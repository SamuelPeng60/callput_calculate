<?php

namespace App\Models;

use App\Support\Database;

class WarrantQuote
{
    public static function upsert(string $warrantId, string $tradeDate, ?float $bid, ?float $ask, ?float $close, ?int $volume): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO warrant_quotes (warrant_id, trade_date, bid_price, ask_price, close_price, volume)
             VALUES (:wid, :d, :bid, :ask, :close, :vol)
             ON CONFLICT(warrant_id, trade_date) DO UPDATE SET
                bid_price=excluded.bid_price, ask_price=excluded.ask_price,
                close_price=excluded.close_price, volume=excluded.volume'
        );
        $stmt->execute(['wid' => $warrantId, 'd' => $tradeDate, 'bid' => $bid, 'ask' => $ask, 'close' => $close, 'vol' => $volume]);
    }

    /** 取得某天、某一批權證代碼的報價，回傳 [warrant_id => row] */
    public static function forWarrantsOnDate(array $warrantIds, string $tradeDate): array
    {
        if (empty($warrantIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($warrantIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT * FROM warrant_quotes WHERE trade_date = ? AND warrant_id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$tradeDate], $warrantIds));
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['warrant_id']] = $row;
        }
        return $result;
    }
}
