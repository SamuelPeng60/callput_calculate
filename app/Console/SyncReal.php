<?php

namespace App\Console;

use App\Models\PriceSnapshot;
use App\Models\UnderlyingStock;
use App\Models\Warrant;
use App\Models\WarrantQuote;
use App\Services\TwseClient;
use App\Support\Database;

/**
 * 用免費公開資料把某標的「真實」的權證資料灌進資料庫，然後跑計算:
 *   1. TWSE MI_INDEX      -> 當天全部上市權證報價(委買/委賣/收盤)，篩出這個標的的
 *   2. TWSE t187ap37_L    -> 這些權證的履約價/行使比例/到期日
 *   3. FinMind 日線(免費) -> 標的歷史股價(算 HV)
 *   4. calculate
 *
 * CLI: php console.php sync-real 2330 [2026-09-23]   (不帶日期 = 最近一個交易日)
 * API 查到沒同步過的標的時也會直接呼叫這支。
 */
class SyncReal
{
    public function __construct(private TwseClient $twse = new TwseClient())
    {
    }

    public function handle(string $stockId, ?string $tradeDate = null): int
    {
        ini_set('memory_limit', '1G'); // 權證基本資料 JSON 約 40MB

        $tradeDate ??= $this->twse->latestTradeDate();

        echo "抓取 TWSE {$tradeDate} 權證收盤行情...\n";
        $quotes = array_filter(
            $this->twse->dailyQuotes($tradeDate),
            fn ($q) => $q['underlying_id'] === $stockId
        );
        if (empty($quotes)) {
            echo "{$tradeDate} 找不到標的 {$stockId} 的上市權證 (上櫃權證目前尚未支援)。\n";
            return 0;
        }

        $terms = $this->twse->warrantTerms(array_keys($quotes));

        $pdo = Database::connection();
        $pdo->beginTransaction();

        $first = reset($quotes);
        UnderlyingStock::upsert($stockId, $first['underlying_name'], 'TSE');

        $count = 0;
        $skipped = 0;
        foreach ($quotes as $id => $q) {
            $t = $terms[$id] ?? null;
            // 牛熊證(有上下限)不適用一般 BS 模型，基本資料缺漏的也跳過
            if ($t === null || $t['listed_type'] !== '一般型' || $t['strike_price'] <= 0 || $t['exercise_ratio'] <= 0) {
                $skipped++;
                continue;
            }

            Warrant::upsert([
                'warrant_id' => $id,
                'name' => $q['name'],
                'underlying_stock_id' => $stockId,
                'type' => $q['type'],
                'issuer' => self::issuerFromName($q['name'], $q['underlying_name']),
                'maturity_date' => $t['maturity_date'],
                'strike_price' => $t['strike_price'],
                'exercise_ratio' => $t['exercise_ratio'],
                'fulfillment_method' => $t['fulfillment_method'],
                'status' => 'active',
            ]);
            WarrantQuote::upsert($id, $tradeDate, $q['bid'], $q['ask'], $q['close'], $q['volume']);
            $count++;
        }

        $pdo->commit();
        echo "寫入 {$count} 檔權證 + 報價" . ($skipped ? "，跳過 {$skipped} 檔(非一般型或缺基本資料)" : '') . "。\n";

        // HV 需要回看 HV_LOOKBACK_DAYS 個交易日，抓半年前開始的日線就夠
        $start = date('Y-m-d', strtotime($tradeDate . ' -180 days'));
        (new SyncUnderlyingPrice())->handle($stockId, $start);

        // FinMind 當天資料可能比 TWSE 晚更新，缺當天收盤就用 MI_INDEX 附的標的收盤價
        if (PriceSnapshot::closeOn($stockId, $tradeDate) === null && $first['underlying_close'] !== null) {
            PriceSnapshot::upsert($stockId, $tradeDate, $first['underlying_close']);
        }

        return (new CalculateMetrics())->handle($stockId, $tradeDate);
    }

    /**
     * 權證簡稱 = 標的簡稱 + 發行商簡稱 + 序號，例如 "啟碁台新5A售02" -> "台新"
     * 標的簡稱在權證名稱裡常被縮短(台積電 -> 台積)，所以用最長共同前綴去掉。
     */
    private static function issuerFromName(string $warrantName, string $underlyingName): ?string
    {
        $w = mb_str_split($warrantName);
        $u = mb_str_split($underlyingName);
        $i = 0;
        while ($i < count($u) && $i < count($w) && $u[$i] === $w[$i]) {
            $i++;
        }
        if ($i === 0) {
            return null;
        }
        $rest = implode('', array_slice($w, $i));
        return preg_match('/^(\p{Han}+?)(?=\d|[A-Z]|購|售)/u', $rest, $m) ? $m[1] : null;
    }
}
