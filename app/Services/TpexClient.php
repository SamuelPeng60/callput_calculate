<?php

namespace App\Services;

use RuntimeException;

/**
 * 櫃買中心(TPEx)免費公開資料 - 上櫃權證的條件 + 每日收盤報價
 *
 * TPEx 沒有像 TWSE MI_INDEX 那樣「一次給齊」的權證行情，所以拼三個來源：
 * - 上櫃每日收盤行情 (stk_wn1430, se=AL 全部證券，可指定日期):
 *     收盤、最後買價/賣價、成交股數；但沒有標的代號
 * - OpenAPI tpex_warrant_daily_quts (最新一天):
 *     權證代號 -> 標的代號/名稱 的對照 (標的不會變，所以用最新一天的就好)
 * - OpenAPI mopsfin_t187ap37_O (上櫃權證基本資料彙總表，格式同 TWSE t187ap37_L):
 *     履約價、行使比例、到期日
 *
 * 上櫃權證的標的都是上櫃股票，上市股票(例如 2330)的權證都在 TWSE。
 */
class TpexClient
{
    private const QUOTES_URL = 'https://www.tpex.org.tw/web/stock/aftertrading/otc_quotes_no1430/stk_wn1430_result.php';
    private const UNDERLYING_URL = 'https://www.tpex.org.tw/openapi/v1/tpex_warrant_daily_quts';
    private const BASIC_URL = 'https://www.tpex.org.tw/openapi/v1/mopsfin_t187ap37_O';

    /**
     * 某交易日的全部上櫃權證收盤報價，格式同 TwseClient::dailyQuotes()；該日沒資料回空陣列
     */
    public function dailyQuotes(string $tradeDate): array
    {
        $quotes = $this->allQuotes($tradeDate);
        if ($quotes === null) {
            return [];
        }

        $result = [];
        foreach ($this->underlyingMap() as $id => $u) {
            $q = $quotes[$id] ?? null;
            if ($q === null) {
                continue; // 當天沒掛牌(已下市/尚未上市)
            }
            $result[$id] = [
                'warrant_id' => $id,
                'name' => $q['name'],
                'type' => self::typeFromName($q['name']),
                'underlying_id' => $u['id'],
                'underlying_name' => $u['name'],
                'underlying_close' => $quotes[$u['id']]['close'] ?? null,
                'close' => $q['close'],
                'bid' => $q['bid'],
                'ask' => $q['ask'],
                'volume' => $q['volume'],
            ];
        }

        return $result;
    }

    /**
     * 上櫃權證基本資料(最新)，回傳 [權證代號 => row]
     *
     * @param string[]|null $onlyIds
     */
    public function warrantTerms(?array $onlyIds = null): array
    {
        return MarketData::warrantTerms(self::BASIC_URL, 'tpex_t187ap37', $onlyIds);
    }

    /**
     * 上櫃全部證券某日收盤行情 [代號 => row]；該日沒資料回 null。有資料的日子快取。
     */
    private function allQuotes(string $tradeDate): ?array
    {
        $ts = strtotime($tradeDate);
        $rocDate = sprintf('%d/%s', (int)date('Y', $ts) - 1911, date('m/d', $ts));
        $cacheFile = MarketData::cacheDir() . '/tpex_quotes_' . date('Ymd', $ts) . '.json';

        if (is_file($cacheFile)) {
            $json = json_decode(file_get_contents($cacheFile), true);
        } else {
            $body = MarketData::get(self::QUOTES_URL . '?' . http_build_query([
                'l' => 'zh-tw', 'd' => $rocDate, 'se' => 'AL',
            ]), 60);
            $json = json_decode($body, true);
            if (!is_array($json)) {
                throw new RuntimeException("TPEx 收盤行情格式錯誤 ({$tradeDate})");
            }
            if (empty($json['tables'][0]['data'])) {
                return null; // 非交易日或尚未公布
            }
            file_put_contents($cacheFile, $body);
        }

        $table = $json['tables'][0];
        $col = array_flip(array_map('trim', $table['fields']));
        $result = [];
        foreach ($table['data'] as $row) {
            $bid = MarketData::num($row[$col['最後買價']]);
            $ask = MarketData::num($row[$col['最後賣價']]);
            $volume = MarketData::num($row[$col['成交股數']]);
            $result[trim($row[$col['代號']])] = [
                'name' => trim($row[$col['名稱']]),
                'close' => MarketData::num($row[$col['收盤']]),
                'bid' => $bid ?: null, // 0.00 = 沒有委買
                'ask' => $ask ?: null,
                'volume' => $volume === null ? null : (int)$volume,
            ];
        }
        return $result;
    }

    /** 權證代號 -> [id => 標的代號, name => 標的名稱] (每天快取一次) */
    private function underlyingMap(): array
    {
        $rows = json_decode(MarketData::cachedGet('tpex_warrant_daily_quts_' . date('Ymd') . '.json', self::UNDERLYING_URL, 120), true);
        if (!is_array($rows)) {
            throw new RuntimeException('TPEx 權證標的對照格式錯誤');
        }
        $map = [];
        foreach ($rows as $r) {
            $map[trim($r['Code'])] = ['id' => trim($r['UnderlyingStockCode']), 'name' => trim($r['UnderlyingStock'])];
        }
        return $map;
    }

    /** "博智群益5A售02" -> put, "宏捷科統一5C購01" -> call (看最後出現的是購還是售) */
    private static function typeFromName(string $name): string
    {
        return (int)mb_strrpos($name, '售') > (int)mb_strrpos($name, '購') ? 'put' : 'call';
    }
}
