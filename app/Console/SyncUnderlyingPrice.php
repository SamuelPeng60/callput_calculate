<?php

namespace App\Console;

use App\Models\PriceSnapshot;
use App\Services\FinMindClient;

/**
 * 同步標的股票歷史收盤價(算 HV 用)。同樣需要外部網路，沙盒內無法測試。
 *   php console.php sync-price 2330 2026-06-01
 */
class SyncUnderlyingPrice
{
    public function __construct(private FinMindClient $finmind = new FinMindClient())
    {
    }

    public function handle(string $stockId, string $startDate): int
    {
        echo "抓取 {$stockId} 自 {$startDate} 起的歷史股價...\n";
        $rows = $this->finmind->getDailyPrice($stockId, $startDate);

        $count = 0;
        foreach ($rows as $row) {
            PriceSnapshot::upsert(
                $stockId,
                $row['date'],
                (float)$row['close'],
                isset($row['open']) ? (float)$row['open'] : null,
                isset($row['max']) ? (float)$row['max'] : null,
                isset($row['min']) ? (float)$row['min'] : null,
                isset($row['Trading_Volume']) ? (int)$row['Trading_Volume'] : null
            );
            $count++;
        }

        echo "完成，共寫入 {$count} 筆日線資料。\n";
        return $count;
    }
}
