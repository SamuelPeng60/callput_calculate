<?php

namespace App\Console;

use App\Services\MarketData;
use Throwable;

/**
 * 在背景跑 SyncReal，讓網頁查詢不用等下載 (TWSE 條件檔 40MB，慢的時候要好幾分鐘；
 * PHP 內建伺服器一次只能處理一個請求，同步跑會卡住整個網站)。
 *
 * 狀態檔 storage/jobs/{股票}_{Ymd}.json：
 *   {state: running|refreshing|done|failed, trade_date, started_at, finished_at, count, message}
 *
 * 今天的權證條件檔 (40MB) 還沒下載時分兩段，讓網頁不用等下載：
 *   1. 先用之前的條件快照算完 -> state=refreshing (結果已寫入，網頁可以先顯示)
 *   2. 同一個子程序接著下載最新條件、重算 -> state=done
 * 網頁端用 spawn() 啟動、status() 查進度；子程序跑 `php console.php sync-job 2330 2026-09-29`。
 */
class SyncJob
{
    private const STALE_RUNNING_SECONDS = 900; // running 超過 15 分鐘視為卡死，可重跑
    private const RETRY_AFTER_SECONDS = 600;   // 完成/失敗 10 分鐘後，資料還是舊的才再試

    /** 第一段已用舊條件算完，正在下載最新條件重算 (超過 15 分鐘視為卡死) */
    public static function isRefreshingTerms(?array $status): bool
    {
        return ($status['state'] ?? null) === 'refreshing'
            && time() - ($status['finished_at'] ?? 0) <= self::STALE_RUNNING_SECONDS;
    }

    public static function status(string $stockId, string $tradeDate): ?array
    {
        $file = self::statusFile($stockId, $tradeDate);
        return is_file($file) ? json_decode(file_get_contents($file), true) : null;
    }

    /** 沒跑過、跑太久卡死、或完成/失敗一陣子了 -> 可以(再)啟動 */
    public static function shouldStart(?array $status): bool
    {
        if ($status === null) {
            return true;
        }
        if ($status['state'] === 'running') {
            return time() - $status['started_at'] > self::STALE_RUNNING_SECONDS;
        }
        return time() - ($status['finished_at'] ?? 0) > self::RETRY_AFTER_SECONDS;
    }

    /** 啟動背景同步，立即返回 */
    public static function spawn(string $stockId, string $tradeDate): void
    {
        self::writeStatus($stockId, $tradeDate, ['state' => 'running', 'started_at' => time()]);

        // 子程序要跟目前的 PHP 用同一組擴充 (本機是用 -d 參數載入，沒有 php.ini)
        $args = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir')];
        foreach (['pdo_sqlite', 'curl', 'mbstring', 'openssl'] as $ext) {
            array_push($args, '-d', "extension={$ext}");
        }
        array_push($args, dirname(__DIR__, 2) . '/console.php', 'sync-job', $stockId, $tradeDate);

        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $log = escapeshellarg(self::jobDir() . "/{$stockId}_" . str_replace('-', '', $tradeDate) . '.log');

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start \"\" /B {$cmd} > {$log} 2>&1", 'r'));
        } else {
            exec("nohup {$cmd} > {$log} 2>&1 &");
        }
    }

    /** CLI: 子程序實際執行同步並寫回狀態 */
    public function handle(string $stockId, string $tradeDate): int
    {
        $startedAt = self::status($stockId, $tradeDate)['started_at'] ?? time();
        self::writeStatus($stockId, $tradeDate, ['state' => 'running', 'started_at' => $startedAt]);

        ob_start();
        try {
            MarketData::$staleTerms = [];
            $count = (new SyncReal())->handle($stockId, $tradeDate, allowStaleTerms: true);
            $refresh = MarketData::$staleTerms !== [];
            $output = ob_get_clean();
            echo $output;
            $lines = explode("\n", trim($output));
            self::writeStatus($stockId, $tradeDate, [
                'state' => $refresh ? 'refreshing' : 'done', 'started_at' => $startedAt, 'finished_at' => time(),
                'count' => $count, 'message' => end($lines),
            ]);
            if (!$refresh) {
                return $count;
            }

            // 第二段：下載最新條件後重算 (網頁已經先顯示用舊條件算的結果)
            ob_start();
            $count = (new SyncReal())->handle($stockId, $tradeDate);
            $output = ob_get_clean();
            echo $output;
            $lines = explode("\n", trim($output));
            self::writeStatus($stockId, $tradeDate, [
                'state' => 'done', 'started_at' => $startedAt, 'finished_at' => time(),
                'count' => $count, 'message' => end($lines),
            ]);
            return $count;
        } catch (Throwable $e) {
            echo ob_get_clean(), $e, "\n";
            self::writeStatus($stockId, $tradeDate, [
                'state' => 'failed', 'started_at' => $startedAt, 'finished_at' => time(),
                'count' => 0, 'message' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private static function writeStatus(string $stockId, string $tradeDate, array $status): void
    {
        $file = self::statusFile($stockId, $tradeDate);
        $tmp = $file . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode(['trade_date' => $tradeDate] + $status, JSON_UNESCAPED_UNICODE));
        rename($tmp, $file);
    }

    private static function statusFile(string $stockId, string $tradeDate): string
    {
        return self::jobDir() . "/{$stockId}_" . str_replace('-', '', $tradeDate) . '.json';
    }

    private static function jobDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/jobs';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }
}
