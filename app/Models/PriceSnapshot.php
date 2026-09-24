<?php

namespace App\Models;

use App\Support\Database;

class PriceSnapshot
{
    public static function upsert(string $stockId, string $tradeDate, float $close, ?float $open = null, ?float $high = null, ?float $low = null, ?int $volume = null): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO price_snapshots (stock_id, trade_date, open, high, low, close, volume)
             VALUES (:stock_id, :trade_date, :open, :high, :low, :close, :volume)
             ON CONFLICT(stock_id, trade_date) DO UPDATE SET
                open = excluded.open, high = excluded.high, low = excluded.low,
                close = excluded.close, volume = excluded.volume'
        );
        $stmt->execute([
            'stock_id' => $stockId, 'trade_date' => $tradeDate,
            'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => $volume,
        ]);
    }

    /** 取得某股票最新 N 個交易日收盤價(由舊到新) */
    public static function recentCloses(string $stockId, string $asOfDate, int $lookbackDays): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT trade_date, close FROM price_snapshots
             WHERE stock_id = :sid AND trade_date <= :asof
             ORDER BY trade_date DESC LIMIT :n'
        );
        $stmt->bindValue(':sid', $stockId);
        $stmt->bindValue(':asof', $asOfDate);
        $stmt->bindValue(':n', $lookbackDays + 1, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll());
        return array_column($rows, 'close');
    }

    public static function closeOn(string $stockId, string $tradeDate): ?float
    {
        $stmt = Database::connection()->prepare(
            'SELECT close FROM price_snapshots WHERE stock_id = :sid AND trade_date = :d'
        );
        $stmt->execute(['sid' => $stockId, 'd' => $tradeDate]);
        $row = $stmt->fetch();
        return $row ? (float)$row['close'] : null;
    }
}
