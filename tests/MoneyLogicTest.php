<?php

declare(strict_types=1);

namespace App\Tests;

use App\Money\Csv;
use App\Money\Debts;
use App\Money\Money;
use App\Money\Schedule;
use PHPUnit\Framework\TestCase;

final class MoneyLogicTest extends TestCase
{
    public function testParse(): void
    {
        self::assertSame(123456, Money::parse('1 234,56', 'RUB'));
        self::assertSame(123456, Money::parse('1.234,56', 'RUB'));
        self::assertSame(123456, Money::parse('1,234.56', 'RUB'));
        self::assertSame(-50000, Money::parse('-500', 'RUB'));
        self::assertSame(34000, Money::parse('250+120-30', 'RUB'));
        self::assertSame(1001, Money::parse('10.005', 'RUB'));
        self::assertSame(500, Money::parse('500', 'JPY'));
        self::assertSame(10, Money::parse('0,1', 'RUB'));
        self::assertNull(Money::parse('abc', 'RUB'));
        self::assertNull(Money::parse('', 'RUB'));
        self::assertNull(Money::parse('1++2', 'RUB'));
    }

    public function testCrypto(): void
    {
        self::assertSame(50000, Money::parse('0,0005', 'BTC'));
        self::assertSame(1250500000, Money::parse('1 250,5', 'USDT'));
        self::assertSame("1\u{00A0}250,5\u{00A0}USDT", Money::format(1250500000, 'USDT'));
        self::assertSame("0,0005\u{00A0}BTC", Money::format(50000, 'BTC'));
        self::assertSame("1\u{00A0}543,15\u{00A0}USDT", Money::format(1543150095, 'USDT'));
        self::assertSame('0.0005', Money::toInput(50000, 'BTC'));
        self::assertArrayHasKey('USDT', Money::choices());
    }

    public function testInputRoundTrip(): void
    {
        self::assertSame('1234.5', Money::toInput(123450, 'RUB'));
        self::assertSame('-0.05', Money::toInput(-5, 'RUB'));
        self::assertSame('100', Money::toInput(10000, 'RUB'));
        self::assertSame(-5, Money::parse(Money::toInput(-5, 'RUB'), 'RUB'));
    }

    public function testSplitEqually(): void
    {
        self::assertSame([334, 333, 333], Debts::splitEqually(1000, 3));
        self::assertSame([500, 500], Debts::splitEqually(1000, 2));
    }

    public function testSplitModes(): void
    {
        self::assertSame(['a' => 334, 'b' => 333, 'c' => 333], Debts::split(1000, 'equal', ['a' => 1, 'b' => 1, 'c' => 1]));
        self::assertSame(['a' => 700, 'c' => 300], Debts::split(1000, 'amounts', ['a' => 700, 'b' => 0, 'c' => 300]));
        self::assertSame(['a' => 500, 'b' => 250, 'c' => 250], Debts::split(1000, 'shares', ['a' => 2, 'b' => 1, 'c' => 1]));
        self::assertSame(['a' => 334, 'b' => 333, 'c' => 333], Debts::split(1000, 'percent', ['a' => 33.34, 'b' => 33.33, 'c' => 33.33]));
        self::assertSame(1001, array_sum(Debts::split(1001, 'shares', ['a' => 1.5, 'b' => 2.5, 'c' => 3])));
        foreach ([['amounts', ['a' => 600, 'b' => 300]], ['percent', ['a' => 60, 'b' => 30]], ['equal', ['a' => 0]]] as [$mode, $values]) {
            try {
                Debts::split(1000, $mode, $values);
                self::fail("$mode should be rejected");
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testSimplifyBalancesOut(): void
    {
        $net = ['a' => 3000, 'b' => -1000, 'c' => -2500, 'd' => 500];
        $payments = Debts::simplify($net);
        foreach ($payments as $p) {
            $net[$p['from']] += $p['amount'];
            $net[$p['to']] -= $p['amount'];
        }
        self::assertSame(['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0], $net);
        self::assertLessThanOrEqual(3, \count($payments));
    }

    public function testScheduleKeepsMonthEnd(): void
    {
        $start = new \DateTimeImmutable('2026-01-31');
        self::assertSame('2026-02-28', Schedule::occurrence($start, 'month', 1)->format('Y-m-d'));
        self::assertSame('2026-03-31', Schedule::occurrence($start, 'month', 2)->format('Y-m-d'));
        self::assertSame('2027-01-31', Schedule::occurrence($start, 'month', 12)->format('Y-m-d'));
        self::assertSame('2025-02-28', Schedule::occurrence(new \DateTimeImmutable('2024-02-29'), 'year', 1)->format('Y-m-d'));
        self::assertSame('2026-02-14', Schedule::occurrence($start, 'week', 2)->format('Y-m-d'));
    }

    public function testCsvBankStatement(): void
    {
        $csv = mb_convert_encoding("\"Дата операции\";\"Статус\";\"Сумма операции\";\"Категория\";\"Описание\"\n"
            ."\"20.10.2026 13:05:01\";\"OK\";\"-1250,50\";\"Супермаркеты\";\"Пятёрочка; у дома\"\n\n", 'Windows-1251', 'UTF-8');
        $rows = Csv::rows($csv);
        self::assertCount(2, $rows);
        self::assertSame('Пятёрочка; у дома', $rows[1][4]);
        self::assertSame(['date' => 0, 'amount' => 2, 'note' => 4, 'category' => 3], Csv::guess($rows[0]));
        self::assertSame('2026-10-20', Csv::date($rows[1][0])?->format('Y-m-d'));
        self::assertNull(Csv::date('вчера'));
    }
}
