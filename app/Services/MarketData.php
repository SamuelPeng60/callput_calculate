<?php

namespace App\Services;

use RuntimeException;

/**
 * TWSE / TPEx 公開資料共用的小工具：HTTP、快取、數字/民國日期解析、權證基本資料檔。
 */
class MarketData
{
    /** 這次執行中「先用了舊快照」的條件檔 prefix => 快照日期 (呼叫端據此決定要不要再下載最新的重算) */
    public static array $staleTerms = [];

    public static function get(string $url, int $timeout = 30): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 warrant-analyzer',
            // Windows 的 PHP 預設沒有 CA bundle，改用系統憑證庫
            CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Request failed: {$error} ({$url})");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("HTTP {$httpCode}: {$url}");
        }
        return $body;
    }

    /** 先寫暫存檔再改名：背景同步可能同時有好幾個程序在讀寫同一個快取檔，避免讀到寫一半的 */
    public static function putFile(string $path, string $data): void
    {
        $tmp = $path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $data);
        rename($tmp, $path);
    }

    public static function cacheDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /** 抓一次存成快取檔，之後直接讀檔 (檔名自己帶日期決定多久更新一次) */
    public static function cachedGet(string $cacheName, string $url, int $timeout = 30): string
    {
        $cacheFile = self::cacheDir() . '/' . $cacheName;
        if (!is_file($cacheFile)) {
            self::putFile($cacheFile, self::get($url, $timeout));
        }
        return file_get_contents($cacheFile);
    }

    /**
     * 權證基本資料彙總表 (TWSE t187ap37_L / TPEx mopsfin_t187ap37_O 同格式)。
     *
     * 來源只有「最新一天」的資料，所以每天下載一次、解析後存成精簡快照
     * ({prefix}_terms_Ymd.json，幾 MB)，累積起來就是每天的條件歷史。
     * 查過去某個交易日時，用「該日當天或之後最近的一份」快照，
     * 盡量貼近當時的履約價/行使比例(除權息會調整)；都沒有才退回今天的。
     *
     * $allowStale = true 時，今天的還沒下載就先用之前最近的一份 (條件只有除權息才會變)，
     * 並記在 self::$staleTerms，讓呼叫端之後再下載最新的重算；完全沒有快照才當場下載。
     *
     * @param string[]|null $onlyIds 只保留這些代號
     * @return array<string, array{strike_price:float, exercise_ratio:float, maturity_date:string,
     *   listed_type:string, fulfillment_method:string}>
     */
    public static function warrantTerms(string $url, string $cachePrefix, ?array $onlyIds = null, ?string $asOfDate = null, bool $allowStale = false): array
    {
        self::convertLegacyTermsCache($cachePrefix);

        $today = date('Ymd');
        $asOf = $asOfDate === null ? $today : date('Ymd', strtotime($asOfDate));
        $snapshotDate = self::termsSnapshotDateOnOrAfter($cachePrefix, $asOf);
        if ($snapshotDate === null && $allowStale && ($prev = self::latestTermsSnapshot($cachePrefix)) !== null) {
            $snapshotDate = $prev;
            self::$staleTerms[$cachePrefix] = $prev;
            echo "先用 {$prev} 的權證條件快照計算 ({$cachePrefix})，最新條件稍後在背景下載。\n";
        }
        $snapshotDate ??= $today;

        $file = self::cacheDir() . "/{$cachePrefix}_terms_{$snapshotDate}.json";
        if (!is_file($file)) {
            self::downloadTerms($url, $cachePrefix, $file);
        }
        if ($snapshotDate > $asOf && $snapshotDate === $today) {
            echo "注意：沒有 {$asOf} 當時的權證條件快照，改用今天的條件(期間若有除權息調整，履約價/行使比例可能不同)。\n";
        }

        $terms = json_decode(file_get_contents($file), true);
        return $onlyIds === null ? $terms : array_intersect_key($terms, array_flip($onlyIds));
    }

    /**
     * 下載條件檔並存成精簡快照。多個背景程序可能同時要同一份 (同時查好幾檔股票)，
     * 用檔案鎖讓同一時間只有一個在下載，其他的等它下載完直接讀。
     */
    private static function downloadTerms(string $url, string $cachePrefix, string $file): void
    {
        $lock = fopen($file . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            if (is_file($file)) {
                return; // 等鎖的時候別的程序已經下載好了
            }
            echo "下載權證基本資料 {$cachePrefix} (每天只抓一次)...\n";
            // TWSE 那份約 40MB，證交所有時只有 ~200KB/s，逾時要給夠
            $rows = json_decode(self::get($url, 600), true);
            if (!is_array($rows)) {
                throw new RuntimeException("權證基本資料格式錯誤 ({$cachePrefix})");
            }
            self::putFile($file, json_encode(self::parseTerms($rows), JSON_UNESCAPED_UNICODE));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 已存在的快照中最新的一份 (Ymd)；沒有回 null */
    private static function latestTermsSnapshot(string $cachePrefix): ?string
    {
        $dates = [];
        foreach (glob(self::cacheDir() . "/{$cachePrefix}_terms_*.json") as $f) {
            if (preg_match('/_terms_(\d{8})\.json$/', $f, $m)) {
                $dates[] = $m[1];
            }
        }
        return $dates ? max($dates) : null;
    }

    /** 已存在的快照中，日期 >= $asOf 的最早一份 (Ymd)；沒有回 null */
    private static function termsSnapshotDateOnOrAfter(string $cachePrefix, string $asOf): ?string
    {
        $dates = [];
        foreach (glob(self::cacheDir() . "/{$cachePrefix}_terms_*.json") as $f) {
            if (preg_match('/_terms_(\d{8})\.json$/', $f, $m) && $m[1] >= $asOf) {
                $dates[] = $m[1];
            }
        }
        return $dates ? min($dates) : null;
    }

    /** 舊版快取存的是 40MB 原始檔 ({prefix}_Ymd.json)，轉成精簡快照後刪掉 */
    private static function convertLegacyTermsCache(string $cachePrefix): void
    {
        foreach (glob(self::cacheDir() . "/{$cachePrefix}_[0-9]*.json") as $f) {
            if (!preg_match('/_(\d{8})\.json$/', $f, $m)) {
                continue;
            }
            $rows = json_decode(file_get_contents($f), true);
            if (is_array($rows)) {
                self::putFile(
                    self::cacheDir() . "/{$cachePrefix}_terms_{$m[1]}.json",
                    json_encode(self::parseTerms($rows), JSON_UNESCAPED_UNICODE)
                );
            }
            unset($rows);
            unlink($f);
        }
    }

    /** 原始彙總表 rows -> [權證代號 => 精簡欄位] */
    private static function parseTerms(array $rows): array
    {
        $result = [];
        foreach ($rows as $r) {
            $id = trim($r['權證代號'] ?? '');
            if ($id === '') {
                continue;
            }
            $result[$id] = [
                'strike_price' => (float)$r['最新履約價格(元)/履約指數'],
                // 每仟單位權證可換的股數 -> 每單位行使比例
                'exercise_ratio' => (float)$r['最新標的履約配發數量(每仟單位權證)'] / 1000,
                'maturity_date' => self::rocDate($r['履約截止日']),
                'listed_type' => $r['類別'] ?? '',
                'fulfillment_method' => (string)($r['結算方式(詳附註編號說明)'] ?? ''),
            ];
        }
        return $result;
    }

    /** "1151016" -> "2026-10-16" */
    public static function rocDate(string $roc): string
    {
        $roc = trim($roc);
        $y = (int)substr($roc, 0, -4) + 1911;
        return sprintf('%04d-%s-%s', $y, substr($roc, -4, 2), substr($roc, -2));
    }

    /** "1,234.50" -> 1234.5, "--" / "" -> null */
    public static function num(?string $s): ?float
    {
        $s = str_replace(',', '', trim(strip_tags((string)$s)));
        return is_numeric($s) ? (float)$s : null;
    }
}
