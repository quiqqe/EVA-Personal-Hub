<?php

declare(strict_types=1);

namespace App\Money;

/** Dates people type: a real calendar day within a sane range (no "2026-02-31", no year 0001). */
final class Dates
{
    public static function parse(string $s, string $min = '2000-01-01', string $max = '+2 years'): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if (!$d || \DateTimeImmutable::getLastErrors() !== false || $d->format('Y-m-d') !== $s) {
            return null;
        }

        return $d >= new \DateTimeImmutable($min) && $d <= new \DateTimeImmutable($max) ? $d : null;
    }
}
