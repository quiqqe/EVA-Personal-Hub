<?php

declare(strict_types=1);

namespace App\Money;

use Symfony\Component\Intl\Currencies;

/** Amounts are ints in the currency's minor units (kopecks, satoshis). Crypto codes sit next to ISO ones. */
final class Money
{
    public const POPULAR = ['RUB', 'USD', 'EUR', 'KZT', 'BYN', 'UAH', 'GEL', 'AMD', 'TRY', 'AED', 'CNY', 'THB', 'GBP', 'UZS', 'KGS', 'RSD'];

    /** Current currencies the Central Bank quotes, so every one of them converts */
    public const OTHER = ['AUD', 'AZN', 'BGN', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EGP', 'HKD', 'HUF', 'IDR', 'INR', 'IRR', 'JPY',
        'KRW', 'MDL', 'NOK', 'NZD', 'PLN', 'QAR', 'RON', 'SEK', 'SGD', 'TJS', 'TMT', 'VND', 'ZAR', 'ETB', 'BDT', 'DZD', 'BHD', 'BOB', 'CUP', 'MMK', 'MNT', 'NGN', 'OMR', 'SAR'];

    /** code => [name, digits stored, CoinGecko id] */
    public const CRYPTO = [
        'USDT' => ['Tether', 6, 'tether'],
        'USDC' => ['USD Coin', 6, 'usd-coin'],
        'BTC' => ['Bitcoin', 8, 'bitcoin'],
        'ETH' => ['Ethereum', 8, 'ethereum'],
        'TON' => ['Toncoin', 6, 'the-open-network'],
        'SOL' => ['Solana', 6, 'solana'],
        'TRX' => ['TRON', 6, 'tron'],
        'BNB' => ['BNB', 8, 'binancecoin'],
        'LTC' => ['Litecoin', 8, 'litecoin'],
        'XRP' => ['XRP', 6, 'ripple'],
        'DOGE' => ['Dogecoin', 6, 'dogecoin'],
    ];

    public static function digits(string $currency): int
    {
        return self::CRYPTO[$currency][1] ?? (Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2);
    }

    public static function isCrypto(string $currency): bool
    {
        return isset(self::CRYPTO[$currency]);
    }

    /**
     * Parses what a person types: "1 234,56", "1.234,56", "-500", "250+120-30".
     * Returns null when it is not a number.
     */
    public static function parse(string $input, string $currency): ?int
    {
        $s = preg_replace('/[\s\x{00A0}\x{202F}₽$€£¥]/u', '', $input) ?? '';
        if ($s === '') {
            return null;
        }
        // "1.234,56" / "1,234.56": the last separator is the decimal one, the other groups thousands
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        if (!preg_match('/^[+-]?\d+(\.\d+)?([+-]\d+(\.\d+)?)*$/', $s)) {
            return null;
        }
        preg_match_all('/([+-]?)(\d+)(?:\.(\d+))?/', $s, $m, PREG_SET_ORDER);
        $d = self::digits($currency);
        $total = 0;
        foreach ($m as $t) {
            $frac = str_pad($t[3] ?? '', $d + 1, '0');
            $minor = (int) $t[2] * 10 ** $d + (int) substr($frac, 0, $d) + ((int) $frac[$d] >= 5 ? 1 : 0);
            $total += $t[1] === '-' ? -$minor : $minor;
        }

        return $total;
    }

    public static function format(int $minor, string $currency): string
    {
        static $fmt, $dec;
        $d = self::digits($currency);
        if (self::isCrypto($currency)) {
            // "0,00125 BTC": no fixed decimals, trailing zeros dropped
            $dec ??= new \NumberFormatter('ru_RU', \NumberFormatter::DECIMAL);
            // precision follows size: 1 543,15 USDT, but 0,00012345 BTC
            $abs = abs($minor) / 10 ** $d;
            $dec->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $abs >= 100 ? min(2, $d) : ($abs >= 1 ? min(4, $d) : $d));

            return $dec->format($minor / 10 ** $d)."\u{00A0}".$currency;
        }
        $fmt ??= new \NumberFormatter('ru_RU', \NumberFormatter::CURRENCY);
        $fmt->setAttribute(\NumberFormatter::FRACTION_DIGITS, $minor % 10 ** $d === 0 ? 0 : $d);

        return $fmt->formatCurrency($minor / 10 ** $d, $currency);
    }

    /** Plain value for an <input>: "1234.5". */
    public static function toInput(int $minor, string $currency): string
    {
        $d = self::digits($currency);
        $abs = abs($minor);
        $s = (string) intdiv($abs, 10 ** $d);
        if ($d > 0 && $abs % 10 ** $d !== 0) {
            $s .= '.'.rtrim(str_pad((string) ($abs % 10 ** $d), $d, '0', STR_PAD_LEFT), '0');
        }

        return ($minor < 0 ? '-' : '').$s;
    }

    /** @return array<string, string> code => "RUB — российский рубль": popular, crypto, then the rest */
    public static function choices(): array
    {
        static $out;
        if ($out) {
            return $out;
        }
        $names = Currencies::getNames('ru');
        foreach (self::POPULAR as $c) {
            $out[$c] = $c.' — '.$names[$c];
        }
        foreach (self::CRYPTO as $c => [$name]) {
            $out[$c] = $c.' — '.$name.' (крипто)';
        }
        foreach (self::OTHER as $c) {
            $out[$c] ??= $c.' — '.$names[$c];
        }

        return $out;
    }
}
