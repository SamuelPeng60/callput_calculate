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

    /**
     * 本機 FinMind 日線的最早/最晚日期；沒有回 null。
     * 只看有開盤價的 (FinMind 來的)，行情資料補的單筆收盤價不算，避免之後漏補中間的日子。
     */
    public static function finMindRange(string $stockId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT MIN(trade_date) AS first, MAX(trade_date) AS last FROM price_snapshots
             WHERE stock_id = :sid AND open IS NOT NULL'
        );
        $stmt->execute(['sid' => $stockId]);
        $row = $stmt->fetch();
        return $row && $row['first'] ? $row : null;
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
