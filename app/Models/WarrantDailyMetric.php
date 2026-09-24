<?php

namespace App\Models;

use App\Support\Database;

class WarrantDailyMetric
{
    public static function upsert(string $warrantId, string $tradeDate, array $m): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO warrant_daily_metrics
                (warrant_id, trade_date, underlying_close, warrant_close, bid_price, ask_price, volume,
                 days_to_maturity, hv, theoretical_price, biv, siv, close_iv,
                 peer_group_key, peer_median_biv, fair_biv, fair_price, deviation_pct, label,
                 delta, theta, effective_leverage, spread_ratio)
             VALUES
                (:warrant_id, :trade_date, :underlying_close, :warrant_close, :bid_price, :ask_price, :volume,
                 :days_to_maturity, :hv, :theoretical_price, :biv, :siv, :close_iv,
                 :peer_group_key, :peer_median_biv, :fair_biv, :fair_price, :deviation_pct, :label,
                 :delta, :theta, :effective_leverage, :spread_ratio)
             ON CONFLICT(warrant_id, trade_date) DO UPDATE SET
                underlying_close=excluded.underlying_close, warrant_close=excluded.warrant_close,
                bid_price=excluded.bid_price, ask_price=excluded.ask_price, volume=excluded.volume,
                days_to_maturity=excluded.days_to_maturity, hv=excluded.hv, theoretical_price=excluded.theoretical_price,
                biv=excluded.biv, siv=excluded.siv, close_iv=excluded.close_iv,
                peer_group_key=excluded.peer_group_key, peer_median_biv=excluded.peer_median_biv,
                fair_biv=excluded.fair_biv, fair_price=excluded.fair_price, deviation_pct=excluded.deviation_pct,
                label=excluded.label, delta=excluded.delta, theta=excluded.theta,
                effective_leverage=excluded.effective_leverage, spread_ratio=excluded.spread_ratio'
        );
        $stmt->execute([
            'warrant_id' => $warrantId,
            'trade_date' => $tradeDate,
            'underlying_close' => $m['underlying_close'],
            'warrant_close' => $m['close_price'] ?? null,
            'bid_price' => $m['bid_price'] ?? null,
            'ask_price' => $m['ask_price'] ?? null,
            'volume' => $m['volume'] ?? null,
            'days_to_maturity' => $m['days_to_maturity'],
            'hv' => $m['hv'] ?? null,
            'theoretical_price' => $m['theoretical_price'] ?? null,
            'biv' => $m['biv'] ?? null,
            'siv' => $m['siv'] ?? null,
            'close_iv' => $m['close_iv'] ?? null,
            'peer_group_key' => $m['peer_group_key'] ?? null,
            'peer_median_biv' => $m['peer_median_biv'] ?? null,
            'fair_biv' => $m['fair_biv'] ?? null,
            'fair_price' => $m['fair_price'] ?? null,
            'deviation_pct' => $m['deviation_pct'] ?? null,
            'label' => $m['label'] ?? 'unknown',
            'delta' => $m['delta'] ?? null,
            'theta' => $m['theta'] ?? null,
            'effective_leverage' => $m['effective_leverage'] ?? null,
            'spread_ratio' => $m['spread_ratio'] ?? null,
        ]);
    }

    /** 取得某標的、某天的全部權證計算結果 + 權證基本資料，供 API 使用 */
    public static function listForStock(string $underlyingStockId, string $tradeDate): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT w.warrant_id, w.name, w.type, w.issuer, w.strike_price, w.exercise_ratio, w.maturity_date,
                    m.underlying_close, m.warrant_close, m.bid_price, m.ask_price, m.volume,
                    m.days_to_maturity, m.hv, m.biv, m.siv, m.fair_biv, m.fair_price,
                    m.deviation_pct, m.label, m.delta, m.theta, m.effective_leverage, m.spread_ratio,
                    m.trade_date
             FROM warrants w
             JOIN warrant_daily_metrics m ON m.warrant_id = w.warrant_id
             WHERE w.underlying_stock_id = :sid AND m.trade_date = :d
             ORDER BY w.type, w.strike_price'
        );
        $stmt->execute(['sid' => $underlyingStockId, 'd' => $tradeDate]);
        return $stmt->fetchAll();
    }

    /** 取得某標的最新一筆有資料的交易日 */
    public static function latestTradeDate(string $underlyingStockId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT MAX(m.trade_date) as d FROM warrant_daily_metrics m
             JOIN warrants w ON w.warrant_id = m.warrant_id
             WHERE w.underlying_stock_id = :sid'
        );
        $stmt->execute(['sid' => $underlyingStockId]);
        $row = $stmt->fetch();
        return $row['d'] ?? null;
    }
}
