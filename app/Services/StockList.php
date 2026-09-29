<?php

namespace App\Services;

use RuntimeException;

/**
 * 上市 + 上櫃股票/ETF 代號名稱清單 (給搜尋框自動完成用)
 *
 * - TWSE OpenAPI STOCK_DAY_ALL (上市全部證券當日行情)
 * - TPEx OpenAPI tpex_mainboard_daily_close_quotes (上櫃全部證券，含權證，要濾掉)
 *
 * 只留一般股票 (4 碼數字) 與 ETF (00 開頭)；權證、特別股、債券等不需要。
 * 每天快取一份精簡清單；當天抓失敗就用最近一份舊的。
 */
class StockList
{
    private const TWSE_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL';
    private const TPEX_URL = 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_daily_close_quotes';

    /**
     * @return array<int, array{id:string, name:string, market:string}> 依代號排序
     */
    public static function all(): array
    {
        $file = MarketData::cacheDir() . '/stock_list_' . date('Ymd') . '.json';
        if (is_file($file)) {
            return json_decode(file_get_contents($file), true);
        }

        try {
            $list = self::fetch();
        } catch (RuntimeException $e) {
            $old = glob(MarketData::cacheDir() . '/stock_list_*.json');
            if (!$old) {
                throw $e;
            }
            return json_decode(file_get_contents(max($old)), true);
        }

        foreach (glob(MarketData::cacheDir() . '/stock_list_*.json') as $f) {
            unlink($f); // 舊清單不用留
        }
        MarketData::putFile($file, json_encode($list, JSON_UNESCAPED_UNICODE));
        return $list;
    }

    private static function fetch(): array
    {
        $list = [];
        $sources = [
            ['TSE', self::TWSE_URL, 'Code', 'Name'],
            ['OTC', self::TPEX_URL, 'SecuritiesCompanyCode', 'CompanyName'],
        ];
        foreach ($sources as [$market, $url, $codeKey, $nameKey]) {
            $rows = json_decode(MarketData::get($url, 60), true);
            if (!is_array($rows)) {
                throw new RuntimeException("股票清單格式錯誤 ({$market})");
            }
            foreach ($rows as $r) {
                $id = trim($r[$codeKey] ?? '');
                if (preg_match('/^(\d{4}|00\d{2,4}[A-Z]?)$/', $id)) {
                    $list[$id] = ['id' => $id, 'name' => trim($r[$nameKey]), 'market' => $market];
                }
            }
        }
        if (!$list) {
            throw new RuntimeException('股票清單是空的');
        }
        ksort($list, SORT_STRING);
        return array_values($list);
    }
}
