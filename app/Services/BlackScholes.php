<?php

namespace App\Services;

/**
 * Black-Scholes 權證定價引擎 (含行使比例調整)、歷史波動率計算、
 * 隱含波動率反解(Newton-Raphson，失敗則退回二分法)。
 *
 * 公式與參數定義依研究報告主題一：
 *   Wc = [S*e^(-qT)*N(d1) - K*e^(-rT)*N(d2)] * Cr   (認購)
 *   Wp = [K*e^(-rT)*N(-d2) - S*e^(-qT)*N(-d1)] * Cr (認售)
 *   d1 = [ln(S/K) + (r - q + sigma^2/2)*T] / (sigma*sqrt(T))
 *   d2 = d1 - sigma*sqrt(T)
 */
class BlackScholes
{
    /**
     * 標準常態累積分配函數 N(x)，用誤差函數近似(Abramowitz-Stegun)
     */
    public static function normCdf(float $x): float
    {
        return 0.5 * (1.0 + self::erf($x / sqrt(2.0)));
    }

    /**
     * 標準常態機率密度函數 n(x)
     */
    public static function normPdf(float $x): float
    {
        return (1.0 / sqrt(2.0 * M_PI)) * exp(-0.5 * $x * $x);
    }

    private static function erf(float $x): float
    {
        // Abramowitz and Stegun formula 7.1.26，精度足夠金融計算使用
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);

        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;

        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    private static function d1(float $S, float $K, float $T, float $r, float $q, float $sigma): float
    {
        return (log($S / $K) + ($r - $q + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
    }

    private static function d2(float $d1, float $sigma, float $T): float
    {
        return $d1 - $sigma * sqrt($T);
    }

    /**
     * 權證理論價 (含行使比例)
     *
     * @param string $type 'call'=認購, 'put'=認售
     */
    public static function price(
        string $type,
        float $S,
        float $K,
        float $T,
        float $r,
        float $q,
        float $sigma,
        float $exerciseRatio
    ): float {
        if ($T <= 0 || $sigma <= 0) {
            // 已到期或波動率無效，退化為內含價值
            $intrinsic = $type === 'call' ? max($S - $K, 0) : max($K - $S, 0);
            return $intrinsic * $exerciseRatio;
        }

        $d1 = self::d1($S, $K, $T, $r, $q, $sigma);
        $d2 = self::d2($d1, $sigma, $T);

        if ($type === 'call') {
            $price = $S * exp(-$q * $T) * self::normCdf($d1) - $K * exp(-$r * $T) * self::normCdf($d2);
        } else {
            $price = $K * exp(-$r * $T) * self::normCdf(-$d2) - $S * exp(-$q * $T) * self::normCdf(-$d1);
        }

        return max($price, 0) * $exerciseRatio;
    }

    /**
     * Delta (對標的價格的敏感度，未乘行使比例；乘上行使比例後才是每張權證的實際delta)
     */
    public static function delta(string $type, float $S, float $K, float $T, float $r, float $q, float $sigma, float $exerciseRatio): float
    {
        if ($T <= 0 || $sigma <= 0) {
            return 0.0;
        }
        $d1 = self::d1($S, $K, $T, $r, $q, $sigma);
        $delta = $type === 'call'
            ? exp(-$q * $T) * self::normCdf($d1)
            : exp(-$q * $T) * (self::normCdf($d1) - 1.0);

        return $delta * $exerciseRatio;
    }

    /**
     * Theta (時間價值每日衰減，負值代表每天損失多少價值；已換算成"每一天"而非"每一年")
     */
    public static function theta(string $type, float $S, float $K, float $T, float $r, float $q, float $sigma, float $exerciseRatio): float
    {
        if ($T <= 0 || $sigma <= 0) {
            return 0.0;
        }
        $d1 = self::d1($S, $K, $T, $r, $q, $sigma);
        $d2 = self::d2($d1, $sigma, $T);

        $term1 = -($S * exp(-$q * $T) * self::normPdf($d1) * $sigma) / (2 * sqrt($T));

        if ($type === 'call') {
            $term2 = $q * $S * exp(-$q * $T) * self::normCdf($d1);
            $term3 = -$r * $K * exp(-$r * $T) * self::normCdf($d2);
            $thetaPerYear = $term1 + $term2 + $term3;
        } else {
            $term2 = -$q * $S * exp(-$q * $T) * self::normCdf(-$d1);
            $term3 = $r * $K * exp(-$r * $T) * self::normCdf(-$d2);
            $thetaPerYear = $term1 + $term2 + $term3;
        }

        // 換算成日 theta (以年化交易日數換算)
        return ($thetaPerYear / 240.0) * $exerciseRatio;
    }

    /**
     * 隱含波動率反解: 給定市價，反推 sigma
     * 先用 Newton-Raphson，若不收斂或落在不合理範圍則退回二分法
     */
    public static function impliedVolatility(
        string $type,
        float $marketPrice,
        float $S,
        float $K,
        float $T,
        float $r,
        float $q,
        float $exerciseRatio,
        float $initialGuess = 0.4
    ): ?float {
        if ($marketPrice <= 0 || $T <= 0) {
            return null;
        }

        $targetPrice = $marketPrice / $exerciseRatio; // 先還原成"未乘行使比例"的單位再解

        $sigma = $initialGuess;
        $maxIter = 50;
        $tolerance = 1e-6;

        for ($i = 0; $i < $maxIter; $i++) {
            $price = self::price($type, $S, $K, $T, $r, $q, $sigma, 1.0);
            $diff = $price - $targetPrice;

            if (abs($diff) < $tolerance) {
                return $sigma;
            }

            // vega (未乘行使比例)
            $d1 = self::d1($S, $K, $T, $r, $q, $sigma);
            $vega = $S * exp(-$q * $T) * self::normPdf($d1) * sqrt($T);

            if ($vega < 1e-8) {
                break; // vega太小，牛頓法會發散，改用二分法
            }

            $sigma -= $diff / $vega;

            if ($sigma <= 0.001 || $sigma > 5.0) {
                break; // 跳出合理範圍，改用二分法
            }
        }

        // 二分法備援 (在 0.1% ~ 500% 之間找)
        return self::bisectionIv($type, $targetPrice, $S, $K, $T, $r, $q);
    }

    private static function bisectionIv(string $type, float $targetPrice, float $S, float $K, float $T, float $r, float $q): ?float
    {
        $low = 0.001;
        $high = 5.0;
        $maxIter = 100;
        $tolerance = 1e-6;

        $priceLow = self::price($type, $S, $K, $T, $r, $q, $low, 1.0) - $targetPrice;
        $priceHigh = self::price($type, $S, $K, $T, $r, $q, $high, 1.0) - $targetPrice;

        if ($priceLow * $priceHigh > 0) {
            return null; // 無解(市價超出理論可能範圍，例如市價低於內含價值太多)
        }

        for ($i = 0; $i < $maxIter; $i++) {
            $mid = ($low + $high) / 2;
            $priceMid = self::price($type, $S, $K, $T, $r, $q, $mid, 1.0) - $targetPrice;

            if (abs($priceMid) < $tolerance) {
                return $mid;
            }

            if ($priceLow * $priceMid < 0) {
                $high = $mid;
            } else {
                $low = $mid;
                $priceLow = $priceMid;
            }
        }

        return ($low + $high) / 2;
    }

    /**
     * 由收盤價序列計算年化歷史波動率
     *
     * @param float[] $closes 由舊到新排序的收盤價
     */
    public static function historicalVolatility(array $closes, int $tradingDaysPerYear = 240): ?float
    {
        $n = count($closes);
        if ($n < 2) {
            return null;
        }

        $logReturns = [];
        for ($i = 1; $i < $n; $i++) {
            if ($closes[$i - 1] <= 0 || $closes[$i] <= 0) {
                continue;
            }
            $logReturns[] = log($closes[$i] / $closes[$i - 1]);
        }

        $count = count($logReturns);
        if ($count < 2) {
            return null;
        }

        $mean = array_sum($logReturns) / $count;
        $variance = 0.0;
        foreach ($logReturns as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $variance /= ($count - 1);

        $dailyStd = sqrt($variance);

        return $dailyStd * sqrt($tradingDaysPerYear);
    }
}
