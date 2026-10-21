<?php

declare(strict_types=1);

namespace App\Money;

final class Debts
{
    public const MODES = ['equal' => 'Поровну', 'amounts' => 'Суммами', 'percent' => 'В %', 'shares' => 'Долями'];

    /** @return list<int> n shares summing to $amount; leftover minor units go to the first shares */
    public static function splitEqually(int $amount, int $n): array
    {
        return array_values(self::proportional($amount, array_fill(0, $n, 1)));
    }

    /**
     * Who owes how much of $total, the ways people split a bill:
     * equal (value ignored), amounts (minor units, must add up), percent (must make 100), shares (2 : 1 : 1).
     *
     * @param array<string, int|float> $values person => their value; people with 0 / missing take no part
     *
     * @return array<string, int> person => owed, summing to $total
     *
     * @throws \InvalidArgumentException with a message for the person filling the form
     */
    public static function split(int $total, string $mode, array $values): array
    {
        $values = array_filter($values, fn ($v) => $v > 0);
        if (!$values) {
            throw new \InvalidArgumentException('Отметьте, между кем делить');
        }
        switch ($mode) {
            case 'amounts':
                if (array_sum($values) !== $total) {
                    throw new \InvalidArgumentException('Сумма долей должна совпадать с суммой расхода');
                }

                return array_map('intval', $values);
            case 'percent':
                if (abs(array_sum($values) - 100) > 0.001) {
                    throw new \InvalidArgumentException('Проценты должны давать в сумме 100');
                }

                return self::proportional($total, $values);
            case 'shares':
                return self::proportional($total, $values);
            default:
                return self::proportional($total, array_map(fn () => 1, $values));
        }
    }

    /**
     * Splits $total by weights without losing a minor unit: floors, then hands the rest out by largest remainder
     * (ties go to whoever comes first).
     *
     * @template K of array-key
     *
     * @param array<K, int|float> $weights
     *
     * @return array<K, int>
     */
    public static function proportional(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $out = $rest = [];
        foreach ($weights as $k => $w) {
            $exact = $total * $w / $sum;
            $out[$k] = (int) floor($exact);
            $rest[$k] = $exact - $out[$k];
        }
        $left = $total - array_sum($out);
        // stable: arsort keeps insertion order for equal remainders
        arsort($rest);
        foreach (array_keys($rest) as $k) {
            if ($left-- <= 0) {
                break;
            }
            ++$out[$k];
        }

        return $out;
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
