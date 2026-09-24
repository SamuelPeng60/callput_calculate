<?php

namespace App\Models;

use App\Support\Database;

class Warrant
{
    public static function upsert(array $w): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO warrants
                (warrant_id, name, underlying_stock_id, type, issuer, listed_date, maturity_date, strike_price, exercise_ratio, fulfillment_method, status, updated_at)
             VALUES
                (:warrant_id, :name, :underlying_stock_id, :type, :issuer, :listed_date, :maturity_date, :strike_price, :exercise_ratio, :fulfillment_method, :status, CURRENT_TIMESTAMP)
             ON CONFLICT(warrant_id) DO UPDATE SET
                name = excluded.name,
                underlying_stock_id = excluded.underlying_stock_id,
                type = excluded.type,
                issuer = excluded.issuer,
                maturity_date = excluded.maturity_date,
                strike_price = excluded.strike_price,
                exercise_ratio = excluded.exercise_ratio,
                fulfillment_method = excluded.fulfillment_method,
                status = excluded.status,
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'warrant_id' => $w['warrant_id'],
            'name' => $w['name'] ?? null,
            'underlying_stock_id' => $w['underlying_stock_id'],
            'type' => $w['type'],
            'issuer' => $w['issuer'] ?? null,
            'listed_date' => $w['listed_date'] ?? null,
            'maturity_date' => $w['maturity_date'],
            'strike_price' => $w['strike_price'],
            'exercise_ratio' => $w['exercise_ratio'] ?? 1.0,
            'fulfillment_method' => $w['fulfillment_method'] ?? null,
            'status' => $w['status'] ?? 'active',
        ]);
    }

    /** 取得某標的目前所有「未到期」的權證 */
    public static function activeByUnderlying(string $underlyingStockId, string $asOfDate): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM warrants
             WHERE underlying_stock_id = :sid AND maturity_date >= :asof
             ORDER BY maturity_date, strike_price'
        );
        $stmt->execute(['sid' => $underlyingStockId, 'asof' => $asOfDate]);
        return $stmt->fetchAll();
    }

    public static function find(string $warrantId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM warrants WHERE warrant_id = ?');
        $stmt->execute([$warrantId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
