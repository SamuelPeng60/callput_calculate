<?php

namespace App\Services;

use RuntimeException;

/**
 * 台灣證交所(TWSE)免費公開資料 - 上市權證的條件 + 每日收盤報價
 *
 * - MI_INDEX (type=0999 認購 / 0999P 認售):
 *     每日收盤行情，含 收盤價、最後揭示買價/賣價、成交股數、標的代號、標的收盤價
 * - OpenAPI t187ap37_L (上市權證基本資料彙總表):
 *     履約價、行使比例、到期日... 只有「最新一天」的資料，約 40MB，
 *     所以每天只抓一次，快取在 storage/cache/。
 *
 * 上櫃權證見 TpexClient。
 */
class TwseClient
{
    private const MI_INDEX_URL = 'https://www.twse.com.tw/rwd/zh/afterTrading/MI_INDEX';
    private const BASIC_URL = 'https://openapi.twse.com.tw/v1/opendata/t187ap37_L';

    /**
     * 某交易日的全部上市權證收盤報價
     *
     * @return array<string, array{warrant_id:string, name:string, type:string, underlying_id:string,
     *   underlying_name:string, underlying_close:?float, close:?float, bid:?float, ask:?float, volume:?int}>
     */
    public function dailyQuotes(string $tradeDate): array
    {
        $result = [];

        foreach (['0999' => 'call', '0999P' => 'put'] as $apiType => $type) {
            $json = $this->miIndex($tradeDate, $apiType);
            if ($json === null) {
                throw new RuntimeException("TWSE MI_INDEX {$tradeDate}: 查無資料 (非交易日或資料尚未公布?)");
            }

            foreach ($json['tables'] ?? [] as $table) {
                $fields = $table['fields'] ?? [];
                if (!in_array('最後揭示買價', $fields, true)) {
                    continue;
                }
                $col = array_flip($fields);
                foreach ($table['data'] as $row) {
                    $id = trim($row[$col['證券代號']]);
                    $result[$id] = [
                        'warrant_id' => $id,
                        'name' => trim($row[$col['證券名稱']]),
                        'type' => $type,
                        'underlying_id' => trim($row[$col['標的代號']]),
                        'underlying_name' => trim($row[$col['標的名稱']]),
                        'underlying_close' => MarketData::num($row[$col['標的收盤價/指數']]),
                        'close' => MarketData::num($row[$col['收盤價']]),
                        'bid' => MarketData::num($row[$col['最後揭示買價']]),
                        'ask' => MarketData::num($row[$col['最後揭示賣價']]),
                        'volume' => ($v = MarketData::num($row[$col['成交股數']])) === null ? null : (int)$v,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * 從今天往回找，最近一個 TWSE 已公布權證收盤行情的交易日 (Y-m-d)
     */
    public function latestTradeDate(int $maxDaysBack = 10): string
    {
        for ($i = 0; $i <= $maxDaysBack; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            if (in_array(date('N', strtotime($date)), ['6', '7'], true)) {
                continue; // 週末不用問
            }
            if ($this->miIndex($date, '0999') !== null) {
                return $date;
            }
        }
        throw new RuntimeException("最近 {$maxDaysBack} 天都找不到 TWSE 權證收盤行情");
    }

    /**
     * 上市權證基本資料(最新)，回傳 [權證代號 => row]
     *
     * @param string[]|null $onlyIds
     */
    public function warrantTerms(?array $onlyIds = null): array
    {
        return MarketData::warrantTerms(self::BASIC_URL, 'twse_t187ap37', $onlyIds);
    }

    /**
     * MI_INDEX 原始回應；該日沒資料回 null。有資料的日子快取到 storage/cache/
     * (收盤行情公布後就不會再變)。
     */
    private function miIndex(string $tradeDate, string $apiType): ?array
    {
        $date = str_replace('-', '', $tradeDate);
        $cacheFile = MarketData::cacheDir() . "/twse_mi_index_{$date}_{$apiType}.json";
        if (is_file($cacheFile)) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        $body = MarketData::get(self::MI_INDEX_URL . '?' . http_build_query([
            'date' => $date, 'type' => $apiType, 'response' => 'json',
        ]));
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new RuntimeException("TWSE MI_INDEX unexpected response ({$tradeDate})");
        }
        if (($json['stat'] ?? '') !== 'OK') {
            return null;
        }

        file_put_contents($cacheFile, $body);
        return $json;
    }
}
