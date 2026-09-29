<?php

/**
 * 極簡 API 前端控制器 (不依賴框架，之後要換 Laravel router 也很好搬)
 *
 * GET /api/stocks
 *   -> 上市+上櫃股票/ETF 代號名稱清單 [{id, name, market}] (搜尋框自動完成用)
 *
 * GET /api/warrants?stock_id=2330[&trade_date=2026-09-18]
 *   -> 回傳該標的股全部權證 + 合理價 + 標籤
 *   不帶 trade_date 時回傳本機最新的結果；若本機還沒有最近交易日的資料(沒查過或資料舊了)，
 *   會在背景跑 SyncJob (TWSE/TPEx + FinMind)，回應帶 updating=該交易日
 *   (本機完全沒資料時回 HTTP 202、warrants 為空)；前端輪詢下面的 sync-status，完成後再查一次
 *
 * GET /api/sync-status?stock_id=2330&trade_date=2026-09-29
 *   -> 背景同步狀態 {state: running|done|failed|none, count, message}
 *
 * 本機測試用內建伺服器啟動:
 *   php -S 127.0.0.1:8000 -t public
 */

require __DIR__ . '/../bootstrap.php';

use App\Console\SyncJob;
use App\Models\WarrantDailyMetric;
use App\Services\StockList;
use App\Services\TwseClient;

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

// 上市+上櫃股票/ETF 清單 (搜尋框自動完成用)
if ($path === '/api/stocks') {
    try {
        echo json_encode(StockList::all(), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        jsonError(502, '取得股票清單失敗：' . $e->getMessage());
    }
    exit;
}

// 背景同步進度 (前端輪詢用)
if ($path === '/api/sync-status') {
    $stockId = strtoupper(trim($_GET['stock_id'] ?? ''));
    $date = $_GET['trade_date'] ?? '';
    if (!preg_match('/^[0-9A-Z]{4,6}$/', $stockId) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonError(400, '需要 stock_id 與 trade_date (Y-m-d)');
    }
    $job = SyncJob::status($stockId, $date);
    echo json_encode([
        'stock_id' => $stockId,
        'trade_date' => $date,
        'state' => $job['state'] ?? 'none',
        'count' => $job['count'] ?? null,
        'message' => $job['message'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
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

    $tradeDate = $_GET['trade_date'] ?? null;
    $notice = null;

    $updating = null; // 背景正在更新的交易日

    // 不指定日期 -> 要最近交易日的結果；本機沒有(沒查過、或資料停在之前的交易日)就在背景抓，
    // 先回本機既有的資料 + updating，前端輪詢 /api/sync-status，完成後再刷新
    if ($tradeDate === null) {
        $localDate = WarrantDailyMetric::latestTradeDate($stockId);
        try {
            $marketDate = (new TwseClient())->latestTradeDate();
        } catch (Throwable $e) {
            $marketDate = null; // 連不上證交所 -> 先用本機既有的資料
            $marketError = $e->getMessage();
        }

        $job = null;
        if ($marketDate !== null && ($localDate === null || $localDate < $marketDate)) {
            $job = SyncJob::status($stockId, $marketDate);
            if (SyncJob::shouldStart($job)) {
                SyncJob::spawn($stockId, $marketDate);
                $job = SyncJob::status($stockId, $marketDate);
            }
            if ($job['state'] === 'running') {
                $updating = $marketDate;
            }
        }

        $tradeDate = $localDate;
        if (!$tradeDate) {
            if ($updating) {
                http_response_code(202);
                echo json_encode([
                    'stock_id' => $stockId, 'trade_date' => null, 'count' => 0,
                    'updating' => $updating, 'notice' => null, 'warrants' => [],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $reason = $job['message'] ?? $marketError ?? '';
            jsonError(404, "{$stockId} 目前沒有可分析的權證" . ($reason ? " ({$reason})" : ''));
        }
        if ($marketDate === null) {
            $notice = "無法取得證交所最新交易日，顯示本機已有的 {$tradeDate} 資料";
        } elseif (!$updating && $tradeDate < $marketDate) {
            $reason = ($job['state'] ?? null) === 'failed' ? "抓取失敗：{$job['message']}" : '沒有可分析的權證';
            $notice = "{$marketDate} {$reason}，顯示的是 {$tradeDate} 的資料";
        }
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
        'notice' => $notice,
        'updating' => $updating, // 非 null = 背景正在更新這個交易日，前端輪詢 /api/sync-status
        'warrants' => $warrants,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

jsonError(404, 'Not found');
