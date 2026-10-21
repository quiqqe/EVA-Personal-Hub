<?php

declare(strict_types=1);

namespace App\Money;

final class Debts
{
    /** @return list<int> n shares summing to $amount; leftover minor units go to the first shares */
    public static function splitEqually(int $amount, int $n): array
    {
        $base = intdiv($amount, $n);
        $rest = $amount - $base * $n;

        return array_map(fn (int $i) => $base + ($i < $rest ? 1 : 0), range(0, $n - 1));
    }

    /**
     * Turns net balances (positive = is owed, negative = owes) into a short list of payments.
     *
     * @param array<string, int> $net
     *
     * @return list<array{from: string, to: string, amount: int}>
     */
    public static function simplify(array $net): array
    {
        $owe = $get = [];
        foreach ($net as $who => $v) {
            if ($v < 0) {
                $owe[$who] = -$v;
            } elseif ($v > 0) {
                $get[$who] = $v;
            }
        }
        arsort($owe);
        arsort($get);
        $out = [];
        foreach ($owe as $from => $debt) {
            foreach ($get as $to => $credit) {
                if ($debt === 0) {
                    break;
                }
                if ($credit === 0) {
                    continue;
                }
                $pay = min($debt, $credit);
                $out[] = ['from' => (string) $from, 'to' => (string) $to, 'amount' => $pay];
                $debt -= $pay;
                $get[$to] -= $pay;
            }
        }

        return $out;
    }
}
