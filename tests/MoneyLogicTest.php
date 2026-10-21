<?php

declare(strict_types=1);

namespace App\Tests;

use App\Money\Csv;
use App\Telegram;
use App\Money\Debts;
use App\Money\Fx;
use App\Money\Money;
use App\Money\Rates;
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

    public function testRateSources(): void
    {
        // snapshot: units per one rouble, only our currencies kept, inverted to roubles per unit
        $r = Rates::parseSnapshot('{"date":"2025-06-15","rub":{"usd":0.0125,"amd":4.8,"usdt":0.0125,"xyz":1}}');
        self::assertEqualsWithDelta(80.0, $r['USD'], 1e-9);
        self::assertEqualsWithDelta(0.2083333, $r['AMD'], 1e-6);
        self::assertArrayHasKey('USDT', $r);
        self::assertArrayNotHasKey('XYZ', $r);
        $c = Rates::parseCbr('{"Valute":{"AMD":{"Nominal":100,"Value":19.6424}}}');
        self::assertEqualsWithDelta(0.196424, $c['AMD'], 1e-9);
        self::assertSame(1.0, $c['RUB']);
    }

    public function testTelegramUpdates(): void
    {
        $start = ['message' => ['text' => '/start 0123abcdef', 'chat' => ['id' => 5, 'type' => 'private'], 'from' => ['id' => 42, 'first_name' => 'Рома']]];
        self::assertSame(['kind' => 'start', 'tgId' => 42, 'name' => 'Рома', 'chatId' => 5, 'param' => '0123abcdef'], Telegram::parse($start));

        $plain = $start;
        $plain['message']['text'] = '/start';
        self::assertSame('', Telegram::parse($plain)['param'] ?? null);

        $group = $start;
        $group['message']['chat']['type'] = 'group';
        self::assertNull(Telegram::parse($group), 'only private chats');
        $text = $start;
        $text['message']['text'] = 'привет';
        self::assertNull(Telegram::parse($text));
        $weird = $start;
        $weird['message']['text'] = '/start ../../etc';
        self::assertNull(Telegram::parse($weird), 'code charset is restricted');

        $button = ['callback_query' => ['id' => 'q1', 'data' => 'login:abc', 'from' => ['id' => 42, 'username' => 'roma'], 'message' => ['message_id' => 9, 'chat' => ['id' => 5]]]];
        self::assertSame(['kind' => 'button', 'tgId' => 42, 'name' => 'roma', 'chatId' => 5, 'param' => 'login:abc', 'callbackId' => 'q1', 'messageId' => 9], Telegram::parse($button));
    }

    public function testForeignAmount(): void
    {
        // 1500 USDT at the exchanger's 386.5 → 579 750 AMD
        self::assertSame(57975000, Fx::amount(1500000000, 'USDT', 'AMD', null, 386.5, 999));
        // the credited sum the person typed wins over any rate
        self::assertSame(57000000, Fx::amount(1500000000, 'USDT', 'AMD', 57000000, 386.5, 999));
        // nothing stated: market rate
        self::assertSame(999, Fx::amount(1500000000, 'USDT', 'AMD', null, null, 999));
        self::assertNull(Fx::amount(100, 'XXX', 'AMD', null, null, null));
        self::assertSame(386.5, Fx::parseRate('386,5'));
        self::assertSame(1386.5, Fx::parseRate('1 386.5'));
        self::assertNull(Fx::parseRate(''));
        self::assertNull(Fx::parseRate('abc'));
        // shares entered in EUR, kept in the account's AMD without losing a unit
        self::assertSame(['a' => 1050000, 'b' => 1050001], array_reverse(Debts::proportional(2100001, ['b' => 2500, 'a' => 2500]), true));
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
