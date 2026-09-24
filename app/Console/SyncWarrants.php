<?php

namespace App\Console;

use App\Models\UnderlyingStock;
use App\Models\Warrant;
use App\Services\FinMindClient;

/**
 * 同步某標的股票的「全部權證清單」進資料庫。
 *
 * !! 這個指令需要對外連線到 api.finmindtrade.com，在目前沙盒環境無法測試，
 * !! 請搬到你自己有網路的伺服器上執行:
 * !!   php console.php sync-warrants 2330
 */
class SyncWarrants
{
    public function __construct(private FinMindClient $finmind = new FinMindClient())
    {
    }

    public function handle(string $underlyingStockId): int
    {
        echo "抓取 {$underlyingStockId} 的權證清單中...\n";

        $rows = $this->finmind->getWarrantsByUnderlying($underlyingStockId);

        if (empty($rows)) {
            echo "沒有抓到任何權證資料，請確認股票代碼是否正確。\n";
            return 0;
        }

        UnderlyingStock::upsert($underlyingStockId);

        $count = 0;
        foreach ($rows as $row) {
            // FinMind TaiwanStockInfoWithWarrantSummary 欄位對應
            $warrantId = $row['stock_id'] ?? null;
            if (!$warrantId) {
                continue;
            }

            $type = $this->inferType($row);

            Warrant::upsert([
                'warrant_id' => $warrantId,
                'name' => $row['stock_name'] ?? null,
                'underlying_stock_id' => $underlyingStockId,
                'type' => $type,
                'issuer' => $row['issuer'] ?? null,
                'listed_date' => $row['date'] ?? null,
                'maturity_date' => $row['end_date'] ?? ($row['fulfillment_end_date'] ?? null),
                'strike_price' => (float)($row['fulfillment_price'] ?? 0),
                'exercise_ratio' => (float)($row['exercise_ratio'] ?? 1.0),
                'fulfillment_method' => $row['fulfillment_method'] ?? null,
                'status' => 'active',
            ]);
            $count++;
        }

        echo "完成，共寫入 {$count} 檔權證。\n";
        return $count;
    }

    private function inferType(array $row): string
    {
        $raw = strtolower((string)($row['type'] ?? ''));
        if (str_contains($raw, 'put') || str_contains($raw, '售')) {
            return 'put';
        }
        return 'call';
    }
}
