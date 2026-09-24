<?php

namespace App\Console;

use App\Models\PriceSnapshot;
use App\Models\Warrant;
use App\Models\WarrantDailyMetric;
use App\Models\WarrantQuote;
use App\Services\BlackScholes;
use App\Services\WarrantValuationService;
use App\Support\Env;

/**
 * 每日盤後批次計算的主流程:
 *   1. 取標的當天收盤價 + 過去 N 天收盤價算 HV
 *   2. 取該標的所有未到期權證
 *   3. 取這些權證當天的委買/委賣/收盤報價 (warrant_quotes 表)
 *   4. 丟進 WarrantValuationService 算 BIV、合理BIV、合理價、標籤
 *   5. 寫回 warrant_daily_metrics
 *
 * CLI: php console.php calculate 2330 2026-09-18
 */
class CalculateMetrics
{
    public function handle(string $underlyingStockId, string $tradeDate): int
    {
        $underlyingClose = PriceSnapshot::closeOn($underlyingStockId, $tradeDate);
        if ($underlyingClose === null) {
            echo "找不到 {$underlyingStockId} 在 {$tradeDate} 的收盤價，請先執行 sync-price 或確認資料是否齊全。\n";
            return 0;
        }

        $lookback = (int)Env::get('HV_LOOKBACK_DAYS', 60);
        $closes = PriceSnapshot::recentCloses($underlyingStockId, $tradeDate, $lookback);
        $hv = BlackScholes::historicalVolatility($closes, (int)Env::get('TRADING_DAYS_PER_YEAR', 240));

        $warrants = Warrant::activeByUnderlying($underlyingStockId, $tradeDate);
        if (empty($warrants)) {
            echo "{$underlyingStockId} 目前沒有未到期的權證資料。\n";
            return 0;
        }

        $warrantIds = array_column($warrants, 'warrant_id');
        $quotes = WarrantQuote::forWarrantsOnDate($warrantIds, $tradeDate);

        $input = [];
        foreach ($warrants as $w) {
            $q = $quotes[$w['warrant_id']] ?? null;
            if ($q === null) {
                continue; // 這檔今天沒有報價資料，跳過
            }
            $input[] = [
                'warrant_id' => $w['warrant_id'],
                'type' => $w['type'],
                'strike_price' => (float)$w['strike_price'],
                'exercise_ratio' => (float)$w['exercise_ratio'],
                'maturity_date' => $w['maturity_date'],
                'bid_price' => $q['bid_price'] !== null ? (float)$q['bid_price'] : null,
                'ask_price' => $q['ask_price'] !== null ? (float)$q['ask_price'] : null,
                'close_price' => $q['close_price'] !== null ? (float)$q['close_price'] : null,
                'volume' => $q['volume'],
            ];
        }

        if (empty($input)) {
            echo "找不到任何權證在 {$tradeDate} 的報價，請先執行 import-quotes。\n";
            return 0;
        }

        $service = new WarrantValuationService(
            riskFreeRate: (float)Env::get('RISK_FREE_RATE', 0.015),
            labelThresholdPct: (float)Env::get('LABEL_THRESHOLD_PCT', 0.15),
            tradingDaysPerYear: (int)Env::get('TRADING_DAYS_PER_YEAR', 240)
        );

        $results = $service->evaluate($input, [
            'underlying_close' => $underlyingClose,
            'trade_date' => $tradeDate,
            'hv' => $hv,
        ]);

        foreach ($results as $r) {
            WarrantDailyMetric::upsert($r['warrant_id'], $tradeDate, array_merge($r, [
                'underlying_close' => $underlyingClose,
            ]));
        }

        echo "計算完成，共處理 " . count($results) . " 檔權證 ({$underlyingStockId}, {$tradeDate})。\n";
        return count($results);
    }
}
