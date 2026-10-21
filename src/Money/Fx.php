<?php

declare(strict_types=1);

namespace App\Money;

/** Converting a sum that came in one currency into another, the way the person states it. */
final class Fx
{
    /**
     * How much of $to an amount of $from is: what the person says was credited wins, then their rate
     * ("1 USDT = 386.5 AMD"), then the market rate (null when unknown).
     */
    public static function amount(int $minor, string $from, string $to, ?int $stated, ?float $rate, ?int $market): ?int
    {
        if ($stated !== null && $stated > 0) {
            return $stated;
        }
        if ($rate !== null && $rate > 0) {
            return (int) round($minor / 10 ** Money::digits($from) * $rate * 10 ** Money::digits($to));
        }

        return $market;
    }

    /** "386,5" / "1 386.5" → 386.5; null when empty or not a number */
    public static function parseRate(string $s): ?float
    {
        $s = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim($s));

        return is_numeric($s) && (float) $s > 0 ? (float) $s : null;
    }
}
