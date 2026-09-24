<?php

namespace App\Services;

use App\Support\Env;
use RuntimeException;

/**
 * FinMind API 客戶端 - 抓「某標的的全部權證清單」與「標的歷史股價」
 *
 * !! 注意 !!
 * 這個 class 在目前的開發沙盒裡「無法實際連線測試」，因為沙盒的網路政策
 * 擋掉了 api.finmindtrade.com。程式碼是照 FinMind 官方文件的 API 規格寫的
 * (https://finmind.github.io/)，請把整個專案搬到你自己有對外網路的伺服器
 * (例如你的 vmtfn209114)上執行 SyncWarrants 指令時，才會真的打到網路。
 *
 * 文件參考:
 * - dataset=TaiwanStockInfoWithWarrantSummary&data_id={標的代碼}
 *   -> 回傳該標的對應的「全部」權證(含已下市)，欄位含:
 *      stock_id(權證代碼), date(上市日), close, target_stock_id(標的代碼),
 *      target_close, type, fulfillment_method, end_date(最後交易日),
 *      fulfillment_start_date, fulfillment_end_date, exercise_ratio(行使比例),
 *      fulfillment_price(履約價)
 * - dataset=TaiwanStockPrice&data_id={代碼}&start_date=...
 *   -> 該代碼(標的股 或 權證本身)的日 OHLCV，權證代碼也適用同一個 dataset。
 */
class FinMindClient
{
    private const BASE_URL = 'https://api.finmindtrade.com/api/v4/data';

    private ?string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? Env::get('FINMIND_TOKEN') ?: null;
    }

    /**
     * 取得某標的對應的全部權證清單(含已到期)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWarrantsByUnderlying(string $underlyingStockId): array
    {
        $data = $this->request('TaiwanStockInfoWithWarrantSummary', [
            'data_id' => $underlyingStockId,
        ]);

        return $data;
    }

    /**
     * 取得某代碼(股票或權證)的日線資料
     *
     * @return array<int, array{date:string, open:float, max:float, min:float, close:float, Trading_Volume:int}>
     */
    public function getDailyPrice(string $stockId, string $startDate, ?string $endDate = null): array
    {
        $params = [
            'data_id' => $stockId,
            'start_date' => $startDate,
        ];
        if ($endDate) {
            $params['end_date'] = $endDate;
        }

        return $this->request('TaiwanStockPrice', $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function request(string $dataset, array $params): array
    {
        $query = array_merge(['dataset' => $dataset], $params);
        if ($this->token) {
            $query['token'] = $this->token;
        }

        $url = self::BASE_URL . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // Windows 的 PHP 預設沒有 CA bundle，改用系統憑證庫
            CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("FinMind request failed: {$error}");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("FinMind HTTP {$httpCode}: {$body}");
        }

        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['data'])) {
            throw new RuntimeException("FinMind unexpected response: {$body}");
        }
        if (($json['status'] ?? 200) !== 200) {
            throw new RuntimeException('FinMind error: ' . ($json['msg'] ?? 'unknown'));
        }

        return $json['data'];
    }
}
