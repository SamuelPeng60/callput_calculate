<?php

/**
 * 極簡 API 前端控制器 (不依賴框架，之後要換 Laravel router 也很好搬)
 *
 * GET /api/warrants?stock_id=2330[&trade_date=2026-09-18]
 *   -> 回傳該標的股全部權證 + 合理價 + 標籤
 *   不帶 trade_date 時，自動取該標的最新一筆有計算結果的交易日；
 *   完全沒資料的標的會當場跑 SyncReal (TWSE/TPEx + FinMind) 再回傳
 *
 * 本機測試用內建伺服器啟動:
 *   php -S 127.0.0.1:8000 -t public
 */

require __DIR__ . '/../bootstrap.php';

use App\Console\SyncReal;
use App\Models\WarrantDailyMetric;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// 根目錄 (或找不到對應靜態檔的路徑) -> 直接輸出前端頁面
// (PHP 內建伺服器沒有指定 router 時，請求不到實體檔案就會 fallback 進這支 index.php，
//  所以這裡順便當作 SPA 的入口點)
if ($path === '/' || $path === '/index.html' || $path === '/app.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/app.html');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // 開發階段先全開，前端串接後可收斂

function jsonError(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/api/warrants' || $path === '/api/warrants/') {
    $stockId = $_GET['stock_id'] ?? null;
    if (!$stockId) {
        jsonError(400, '請帶入 stock_id 參數，例如 ?stock_id=2330');
    }

    $stockId = strtoupper(trim($stockId));
    if (!preg_match('/^[0-9A-Z]{4,6}$/', $stockId)) {
        jsonError(400, '股票代號格式不正確');
    }

    $tradeDate = $_GET['trade_date'] ?? WarrantDailyMetric::latestTradeDate($stockId);

    // 沒同步過的標的 -> 當場抓最近交易日的真實資料並計算 (第一次查約 10~30 秒)
    if (!$tradeDate && !isset($_GET['trade_date'])) {
        set_time_limit(300);
        ob_start(); // SyncReal 是 CLI 指令，會 echo 進度，這裡不要混進 JSON
        try {
            (new SyncReal())->handle($stockId);
            $log = ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            jsonError(502, "即時抓取 {$stockId} 資料失敗：" . $e->getMessage());
        }
        $tradeDate = WarrantDailyMetric::latestTradeDate($stockId);
        if (!$tradeDate) {
            $lines = explode("\n", trim($log));
            jsonError(404, "{$stockId} 目前沒有可分析的權證 (" . end($lines) . ')');
        }
    }

    if (!$tradeDate) {
        jsonError(404, "找不到 {$stockId} 在 {$_GET['trade_date']} 的計算結果。");
    }

    $rows = WarrantDailyMetric::listForStock($stockId, $tradeDate);

    $warrants = array_map(function ($r) {
        return [
            'warrant_id' => $r['warrant_id'],
            'name' => $r['name'],
            'type' => $r['type'],                 // call | put
            'type_label' => $r['type'] === 'call' ? '認購' : '認售',
            'issuer' => $r['issuer'],
            'strike_price' => (float)$r['strike_price'],
            'exercise_ratio' => (float)$r['exercise_ratio'],
            'maturity_date' => $r['maturity_date'],
            'days_to_maturity' => (int)$r['days_to_maturity'],
            'underlying_close' => (float)$r['underlying_close'],
            'warrant_close' => $r['warrant_close'] !== null ? (float)$r['warrant_close'] : null,
            'bid_price' => $r['bid_price'] !== null ? (float)$r['bid_price'] : null,
            'ask_price' => $r['ask_price'] !== null ? (float)$r['ask_price'] : null,
            'volume' => $r['volume'] !== null ? (int)$r['volume'] : null,
            'biv_pct' => $r['biv'] !== null ? round($r['biv'] * 100, 2) : null,
            'fair_biv_pct' => $r['fair_biv'] !== null ? round($r['fair_biv'] * 100, 2) : null,
            'fair_price' => $r['fair_price'] !== null ? (float)$r['fair_price'] : null,
            'deviation_pct' => $r['deviation_pct'] !== null ? round($r['deviation_pct'] * 100, 2) : null,
            'label' => $r['label'],               // cheap | fair | expensive | unknown
            'label_zh' => match ($r['label']) {
                'cheap' => '便宜',
                'expensive' => '偏貴',
                'fair' => '合理',
                default => '資料不足',
            },
            'delta' => $r['delta'] !== null ? round((float)$r['delta'], 4) : null,
            'theta' => $r['theta'] !== null ? round((float)$r['theta'], 4) : null,
            'effective_leverage' => $r['effective_leverage'] !== null ? round((float)$r['effective_leverage'], 2) : null,
            'spread_ratio_pct' => $r['spread_ratio'] !== null ? round((float)$r['spread_ratio'] * 100, 2) : null,
        ];
    }, $rows);

    echo json_encode([
        'stock_id' => $stockId,
        'trade_date' => $tradeDate,
        'count' => count($warrants),
        'warrants' => $warrants,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

jsonError(404, 'Not found');
