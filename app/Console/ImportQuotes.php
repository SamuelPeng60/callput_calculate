<?php

namespace App\Console;

use App\Models\WarrantQuote;

/**
 * 匯入權證每日報價 (委買/委賣/收盤價)。
 *
 * 因為權證的隱含波動率沒有免費 API(研究報告主題二已確認)，實務上要嘛:
 *   (a) 爬「權證資訊揭露平台」(warrants.sfi.org.tw) 或券商權證網，
 *   (b) 或串接付費即時報價。
 * 這個 class 刻意跟「怎麼拿到報價」解耦：不管你用哪種方式拿到資料，
 * 只要輸出成這個 CSV 格式(warrant_id,bid_price,ask_price,close_price,volume)，
 * 就能匯入資料庫接上後面的計算引擎。
 *
 * CLI: php console.php import-quotes 2026-09-18 /path/to/quotes.csv
 */
class ImportQuotes
{
    public function handle(string $tradeDate, string $csvPath): int
    {
        if (!is_file($csvPath)) {
            echo "找不到檔案: {$csvPath}\n";
            return 0;
        }

        $handle = fopen($csvPath, 'r');
        $header = fgetcsv($handle);
        $header = array_map('trim', $header);

        $count = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $assoc = array_combine($header, $row);
            WarrantQuote::upsert(
                $assoc['warrant_id'],
                $tradeDate,
                isset($assoc['bid_price']) && $assoc['bid_price'] !== '' ? (float)$assoc['bid_price'] : null,
                isset($assoc['ask_price']) && $assoc['ask_price'] !== '' ? (float)$assoc['ask_price'] : null,
                isset($assoc['close_price']) && $assoc['close_price'] !== '' ? (float)$assoc['close_price'] : null,
                isset($assoc['volume']) && $assoc['volume'] !== '' ? (int)$assoc['volume'] : null
            );
            $count++;
        }
        fclose($handle);

        echo "匯入完成，共 {$count} 筆報價 ({$tradeDate})。\n";
        return $count;
    }
}
