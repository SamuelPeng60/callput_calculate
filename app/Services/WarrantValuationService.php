<?php

namespace App\Services;

/**
 * 核心評價邏輯 (對應研究報告主題一的實務做法):
 *
 * 1. 用「委買價」反解每檔權證的隱含波動率 BIV (投資人只能用委買價賣出，
 *    所以 BIV 才是真正代表持有人能拿到多少價值的指標)
 * 2. 把同標的、同認購/認售、條件相近(價內外程度、剩餘天數)的權證分成一組
 *    (peer group)，取該組 BIV 的中位數作為「合理 BIV」
 *    -> 這正是統一權證部落格〈隱含波動率到底用來幹嘛的?〉的方法:
 *       「同標的...剩餘天數、價外程度以及行使比例相仿」的權證互比隱波
 * 3. 用合理 BIV 代回 Black-Scholes 反算出「合理(委買)價」
 * 4. 實際委買價 vs 合理價的偏離幅度超過門檻 -> 標示 偏貴 / 便宜，否則 合理
 *
 * 這個 class 是純運算(不碰資料庫)，方便單元測試與之後抽換資料來源。
 */
class WarrantValuationService
{
    public function __construct(
        private float $riskFreeRate = 0.015,
        private float $dividendYield = 0.0,
        private float $labelThresholdPct = 0.15,
        private int $tradingDaysPerYear = 240,
        private int $minPeerGroupSize = 3
    ) {
    }

    /**
     * @param array<int, array{
     *   warrant_id: string,
     *   type: string,               // call | put
     *   strike_price: float,
     *   exercise_ratio: float,
     *   maturity_date: string,      // Y-m-d
     *   bid_price: float,
     *   ask_price: float,
     *   close_price: ?float,
     *   volume: ?int
     * }> $warrants  同一檔標的、同一天的全部權證
     * @param array{underlying_close: float, trade_date: string, hv: ?float} $context
     *
     * @return array<int, array<string, mixed>> 每檔權證的完整計算結果
     */
    public function evaluate(array $warrants, array $context): array
    {
        $S = $context['underlying_close'];
        $tradeDate = new \DateTimeImmutable($context['trade_date']);
        $hv = $context['hv'];

        // 第一步：算每檔的 T、BIV/SIV、Delta/Theta/槓桿/價差比
        $enriched = [];
        foreach ($warrants as $w) {
            $maturity = new \DateTimeImmutable($w['maturity_date']);
            $daysToMaturity = max($tradeDate->diff($maturity)->days, 0);
            $T = $daysToMaturity / 365.0; // 日曆天年化(BS用日曆天更貼近市場慣例)

            $row = $w;
            $row['days_to_maturity'] = $daysToMaturity;
            $row['hv'] = $hv;

            if ($T <= 0) {
                $row['biv'] = null;
                $row['siv'] = null;
                $row['close_iv'] = null;
                $row['delta'] = null;
                $row['theta'] = null;
                $row['theoretical_price'] = null;
                $enriched[] = $row;
                continue;
            }

            $row['biv'] = $this->safeIv($w['type'], $w['bid_price'] ?? null, $S, $w['strike_price'], $T, $w['exercise_ratio']);
            $row['siv'] = $this->safeIv($w['type'], $w['ask_price'] ?? null, $S, $w['strike_price'], $T, $w['exercise_ratio']);
            $row['close_iv'] = $this->safeIv($w['type'], $w['close_price'] ?? null, $S, $w['strike_price'], $T, $w['exercise_ratio']);

            $sigmaForGreeks = $row['biv'] ?? $row['close_iv'] ?? $hv ?? 0.4;
            $row['delta'] = BlackScholes::delta($w['type'], $S, $w['strike_price'], $T, $this->riskFreeRate, $this->dividendYield, $sigmaForGreeks, $w['exercise_ratio']);
            $row['theta'] = BlackScholes::theta($w['type'], $S, $w['strike_price'], $T, $this->riskFreeRate, $this->dividendYield, $sigmaForGreeks, $w['exercise_ratio']);

            $row['theoretical_price'] = $hv !== null
                ? BlackScholes::price($w['type'], $S, $w['strike_price'], $T, $this->riskFreeRate, $this->dividendYield, $hv, $w['exercise_ratio'])
                : null;

            // 實質槓桿 = |Delta| * S / 權證價格 (用委買價當持有成本較保守)
            $refPrice = $w['bid_price'] ?? $w['close_price'] ?? null;
            $row['effective_leverage'] = ($refPrice && $refPrice > 0)
                ? abs($row['delta']) * $S / $refPrice
                : null;

            // 買賣價差比 = (賣-買) / 賣
            $row['spread_ratio'] = (isset($w['ask_price'], $w['bid_price']) && $w['ask_price'] > 0)
                ? ($w['ask_price'] - $w['bid_price']) / $w['ask_price']
                : null;

            $row['peer_group_key'] = $this->peerGroupKey($w['type'], $S, $w['strike_price'], $daysToMaturity);

            $enriched[] = $row;
        }

        // 第二步：依 peer_group_key 分組，算組內 BIV 中位數
        $groups = [];
        foreach ($enriched as $row) {
            if ($row['biv'] === null) {
                continue;
            }
            $groups[$row['peer_group_key']][] = $row['biv'];
        }
        $groupMedians = [];
        foreach ($groups as $key => $bivs) {
            $groupMedians[$key] = [
                'median' => $this->median($bivs),
                'count' => count($bivs),
            ];
        }

        // 全標的(不分組)中位數，當作 fallback (peer group 樣本太少時用)
        $allBivs = array_values(array_filter(array_column($enriched, 'biv'), fn($v) => $v !== null));
        $overallMedianBiv = $this->median($allBivs);

        // 第三步：算合理BIV、合理價、偏離幅度、標籤
        $results = [];
        foreach ($enriched as $row) {
            $group = $groupMedians[$row['peer_group_key']] ?? null;

            $fairBiv = null;
            $fairBivSource = null;
            if ($group !== null && $group['count'] >= $this->minPeerGroupSize) {
                $fairBiv = $group['median'];
                $fairBivSource = 'peer_group';
            } elseif ($overallMedianBiv !== null) {
                $fairBiv = $overallMedianBiv;
                $fairBivSource = 'overall_median';
            } elseif ($hv !== null) {
                $fairBiv = $hv;
                $fairBivSource = 'historical_volatility';
            }

            $row['peer_median_biv'] = $group['median'] ?? null;
            $row['peer_group_size'] = $group['count'] ?? 0;
            $row['fair_biv'] = $fairBiv;
            $row['fair_biv_source'] = $fairBivSource;

            if ($fairBiv !== null && $row['days_to_maturity'] > 0) {
                $T = $row['days_to_maturity'] / 365.0;
                $row['fair_price'] = round(
                    BlackScholes::price($row['type'], $S, $row['strike_price'], $T, $this->riskFreeRate, $this->dividendYield, $fairBiv, $row['exercise_ratio']),
                    4
                );
            } else {
                $row['fair_price'] = null;
            }

            $bidPrice = $row['bid_price'] ?? null;
            if ($row['fair_price'] !== null && $row['fair_price'] > 0 && $bidPrice !== null) {
                $row['deviation_pct'] = round(($bidPrice - $row['fair_price']) / $row['fair_price'], 4);
                $row['label'] = $this->labelFromDeviation($row['deviation_pct']);
            } else {
                $row['deviation_pct'] = null;
                $row['label'] = 'unknown';
            }

            $results[] = $row;
        }

        return $results;
    }

    private function safeIv(string $type, ?float $price, float $S, float $K, float $T, float $ratio): ?float
    {
        if ($price === null || $price <= 0) {
            return null;
        }
        return BlackScholes::impliedVolatility($type, $price, $S, $K, $T, $this->riskFreeRate, $this->dividendYield, $ratio);
    }

    /**
     * 分組 key：認購/認售 + 價內外程度區間(每5%一格) + 到期天數區間
     * 對應研究報告「同標的、剩餘天數、價外程度以及行使比例相仿」的比較條件
     */
    private function peerGroupKey(string $type, float $S, float $K, int $daysToMaturity): string
    {
        $moneyness = $S / $K; // >1 表示認購價內 / 認售價外
        $moneynessBucket = round($moneyness / 0.05) * 0.05;

        $maturityBucket = match (true) {
            $daysToMaturity <= 30 => '0-30d',
            $daysToMaturity <= 60 => '31-60d',
            $daysToMaturity <= 90 => '61-90d',
            $daysToMaturity <= 180 => '91-180d',
            default => '180d+',
        };

        return sprintf('%s|money=%.2f|%s', $type, $moneynessBucket, $maturityBucket);
    }

    private function labelFromDeviation(float $deviationPct): string
    {
        if ($deviationPct > $this->labelThresholdPct) {
            return 'expensive'; // 偏貴
        }
        if ($deviationPct < -$this->labelThresholdPct) {
            return 'cheap'; // 便宜
        }
        return 'fair'; // 合理
    }

    /**
     * @param float[] $values
     */
    private function median(array $values): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2.0;
        }
        return $values[$mid];
    }
}
