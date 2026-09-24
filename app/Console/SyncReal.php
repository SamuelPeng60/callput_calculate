<?php

namespace App\Console;

use App\Models\PriceSnapshot;
use App\Models\UnderlyingStock;
use App\Models\Warrant;
use App\Models\WarrantQuote;
use App\Services\TpexClient;
use App\Services\TwseClient;
use App\Support\Database;

/**
 * 用免費公開資料把某標的「真實」的權證資料灌進資料庫，然後跑計算:
 *   1. TWSE MI_INDEX + TPEx 收盤行情 -> 當天上市/上櫃權證報價(委買/委賣/收盤)，篩出這個標的的
 *   2. TWSE / TPEx 權證基本資料       -> 履約價/行使比例/到期日
 *   3. FinMind 日線(免費)             -> 標的歷史股價(算 HV)
 *   4. calculate
 *
 * CLI: php console.php sync-real 2330 [2026-09-23]   (不帶日期 = 最近一個交易日)
 * API 查到沒同步過的標的時也會直接呼叫這支。
 */
class SyncReal
{
    public function __construct(
        private TwseClient $twse = new TwseClient(),
        private TpexClient $tpex = new TpexClient(),
    ) {
    }

    public function handle(string $stockId, ?string $tradeDate = null): int
    {
        ini_set('memory_limit', '1G'); // 權證基本資料 JSON 約 40MB

        $tradeDate ??= $this->twse->latestTradeDate();
        $ofStock = fn ($q) => $q['underlying_id'] === $stockId;

        echo "抓取 {$tradeDate} 上市/上櫃權證收盤行情...\n";
        $twseQuotes = array_filter($this->twse->dailyQuotes($tradeDate), $ofStock);
        $tpexQuotes = array_filter($this->tpex->dailyQuotes($tradeDate), $ofStock);
        if (empty($twseQuotes) && empty($tpexQuotes)) {
            echo "{$tradeDate} 找不到標的 {$stockId} 的上市或上櫃權證。\n";
            return 0;
        }

        // 只下載有需要的那個市場的基本資料 (TWSE 那份 40MB)
        $terms = ($twseQuotes ? $this->twse->warrantTerms(array_keys($twseQuotes)) : [])
            + ($tpexQuotes ? $this->tpex->warrantTerms(array_keys($tpexQuotes)) : []);
        $quotes = $twseQuotes + $tpexQuotes;

        $pdo = Database::connection();
        $pdo->beginTransaction();

        $first = reset($quotes);
        UnderlyingStock::upsert($stockId, $first['underlying_name'], $twseQuotes ? 'TSE' : 'OTC');

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

        // FinMind 當天資料可能比交易所晚更新，缺當天收盤就用行情資料附的標的收盤價
        $underlyingClose = current(array_filter(array_column($quotes, 'underlying_close'))) ?: null;
        if (PriceSnapshot::closeOn($stockId, $tradeDate) === null && $underlyingClose !== null) {
            PriceSnapshot::upsert($stockId, $tradeDate, $underlyingClose);
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
