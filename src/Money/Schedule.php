<?php

declare(strict_types=1);

namespace App\Money;

final class Schedule
{
    public const PERIODS = ['day' => 'Каждый день', 'week' => 'Каждую неделю', 'month' => 'Каждый месяц', 'year' => 'Каждый год'];

    /** The n-th date (0 = start). Day 31 in a short month falls on its last day instead of spilling over. */
    public static function occurrence(\DateTimeImmutable $start, string $period, int $n): \DateTimeImmutable
    {
        if ($period === 'day' || $period === 'week') {
            return $start->modify('+'.($period === 'week' ? 7 * $n : $n).' days');
        }
        $months = (int) $start->format('Y') * 12 + (int) $start->format('n') - 1 + ($period === 'year' ? 12 * $n : $n);
        $first = $start->setDate(intdiv($months, 12), $months % 12 + 1, 1);

        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $start->format('j'), (int) $first->format('t')));
    }
}
