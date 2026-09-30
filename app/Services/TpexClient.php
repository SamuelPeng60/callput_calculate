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
 *     權證代號 -> 標的代號/名稱 的對照。每天存一份，查詢時合併所有存過的，
 *     這樣查過去的日期時，之後才下市的權證也對得到標的 (權證的標的不會變)
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
     * 上櫃權證基本資料(盡量用 $asOfDate 當時的快照，見 MarketData::warrantTerms)，回傳 [權證代號 => row]
     *
     * @param string[]|null $onlyIds
     */
    public function warrantTerms(?array $onlyIds = null, ?string $asOfDate = null): array
    {
        return MarketData::warrantTerms(self::BASIC_URL, 'tpex_t187ap37', $onlyIds, $asOfDate);
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
            MarketData::putFile($cacheFile, $body);
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

    /**
     * 權證代號 -> [id => 標的代號, name => 標的名稱]
     * 今天的對照每天抓一次，再合併之前每天存下來的 (新的蓋舊的)
     */
    private function underlyingMap(): array
    {
        MarketData::cachedGet('tpex_warrant_daily_quts_' . date('Ymd') . '.json', self::UNDERLYING_URL, 120);

        $files = glob(MarketData::cacheDir() . '/tpex_warrant_daily_quts_*.json');
        sort($files); // 檔名帶 Ymd，排序後由舊到新
        $map = [];
        foreach ($files as $file) {
            $rows = json_decode(file_get_contents($file), true);
            if (!is_array($rows)) {
                throw new RuntimeException('TPEx 權證標的對照格式錯誤 (' . basename($file) . ')');
            }
            foreach ($rows as $r) {
                $map[trim($r['Code'])] = ['id' => trim($r['UnderlyingStockCode']), 'name' => trim($r['UnderlyingStock'])];
            }
        }
        return $map;
    }

    /** "博智群益5A售02" -> put, "宏捷科統一5C購01" -> call (看最後出現的是購還是售) */
    private static function typeFromName(string $name): string
    {
        return (int)mb_strrpos($name, '售') > (int)mb_strrpos($name, '購') ? 'put' : 'call';
    }
}
