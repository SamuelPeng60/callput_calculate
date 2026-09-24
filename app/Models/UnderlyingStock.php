<?php

namespace App\Models;

use App\Support\Database;

class UnderlyingStock
{
    public static function upsert(string $stockId, ?string $name = null, ?string $market = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO underlying_stocks (stock_id, name, market, updated_at)
             VALUES (:stock_id, :name, :market, CURRENT_TIMESTAMP)
             ON CONFLICT(stock_id) DO UPDATE SET
                name = COALESCE(excluded.name, underlying_stocks.name),
                market = COALESCE(excluded.market, underlying_stocks.market),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['stock_id' => $stockId, 'name' => $name, 'market' => $market]);
    }

    public static function find(string $stockId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM underlying_stocks WHERE stock_id = ?');
        $stmt->execute([$stockId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
