<?php

namespace App\Services;

use RuntimeException;

/**
 * TWSE / TPEx 公開資料共用的小工具：HTTP、快取、數字/民國日期解析、權證基本資料檔。
 */
class MarketData
{
    public static function get(string $url, int $timeout = 30): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 warrant-analyzer',
            // Windows 的 PHP 預設沒有 CA bundle，改用系統憑證庫
            CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Request failed: {$error} ({$url})");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("HTTP {$httpCode}: {$url}");
        }
        return $body;
    }

    public static function cacheDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /** 抓一次存成快取檔，之後直接讀檔 (檔名自己帶日期決定多久更新一次) */
    public static function cachedGet(string $cacheName, string $url, int $timeout = 30): string
    {
        $cacheFile = self::cacheDir() . '/' . $cacheName;
        if (!is_file($cacheFile)) {
            file_put_contents($cacheFile, self::get($url, $timeout));
        }
        return file_get_contents($cacheFile);
    }

    /**
     * 權證基本資料彙總表 (TWSE t187ap37_L / TPEx mopsfin_t187ap37_O 同格式)，
     * 只有「最新一天」的資料，每天快取一次。回傳 [權證代號 => row]
     *
     * @param string[]|null $onlyIds 只保留這些代號(省記憶體)
     * @return array<string, array{strike_price:float, exercise_ratio:float, maturity_date:string,
     *   listed_type:string, fulfillment_method:string}>
     */
    public static function warrantTerms(string $url, string $cachePrefix, ?array $onlyIds = null): array
    {
        $cacheName = $cachePrefix . '_' . date('Ymd') . '.json';
        if (!is_file(self::cacheDir() . '/' . $cacheName)) {
            echo "下載權證基本資料 {$cachePrefix} (每天只抓一次)...\n";
        }
        $rows = json_decode(self::cachedGet($cacheName, $url, 180), true);
        if (!is_array($rows)) {
            @unlink(self::cacheDir() . '/' . $cacheName);
            throw new RuntimeException("權證基本資料格式錯誤 ({$cachePrefix})");
        }

        $keep = $onlyIds === null ? null : array_flip($onlyIds);
        $result = [];
        foreach ($rows as $r) {
            $id = trim($r['權證代號'] ?? '');
            if ($id === '' || ($keep !== null && !isset($keep[$id]))) {
                continue;
            }
            $result[$id] = [
                'strike_price' => (float)$r['最新履約價格(元)/履約指數'],
                // 每仟單位權證可換的股數 -> 每單位行使比例
                'exercise_ratio' => (float)$r['最新標的履約配發數量(每仟單位權證)'] / 1000,
                'maturity_date' => self::rocDate($r['履約截止日']),
                'listed_type' => $r['類別'] ?? '',
                'fulfillment_method' => (string)($r['結算方式(詳附註編號說明)'] ?? ''),
            ];
        }
        unset($rows);

        return $result;
    }

    /** "1151016" -> "2026-10-16" */
    public static function rocDate(string $roc): string
    {
        $roc = trim($roc);
        $y = (int)substr($roc, 0, -4) + 1911;
        return sprintf('%04d-%s-%s', $y, substr($roc, -4, 2), substr($roc, -2));
    }

    /** "1,234.50" -> 1234.5, "--" / "" -> null */
    public static function num(?string $s): ?float
    {
        $s = str_replace(',', '', trim(strip_tags((string)$s)));
        return is_numeric($s) ? (float)$s : null;
    }
}
