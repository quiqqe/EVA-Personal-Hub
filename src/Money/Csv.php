<?php

declare(strict_types=1);

namespace App\Money;

/** Bank statement CSV: whatever encoding and delimiter the bank chose. */
final class Csv
{
    /** @return list<list<string>> */
    public static function rows(string $content): array
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $firstLine = strtok($content, "\n") ?: '';
        $delim = ';';
        foreach ([',', "\t"] as $d) {
            if (substr_count($firstLine, $d) > substr_count($firstLine, $delim)) {
                $delim = $d;
            }
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, null, $delim, '"', '')) !== false) {
            if ($r !== [null] && implode('', $r) !== '') {
                $rows[] = array_map(fn ($c) => trim((string) $c), $r);
            }
        }
        fclose($h);

        return $rows;
    }

    /**
     * Column indexes guessed from header names.
     *
     * @param list<string> $header
     *
     * amount — in the account's currency (T-Bank: «Сумма платежа»); orig_* — the purchase's own currency
     * (T-Bank: «Сумма операции» / «Валюта операции»), set for purchases abroad.
     *
     * @return array{date: ?int, amount: ?int, note: ?int, category: ?int, orig_amount: ?int, orig_currency: ?int}
     */
    public static function guess(array $header): array
    {
        $find = function (array $patterns) use ($header): ?int {
            foreach ($patterns as $p) {
                foreach ($header as $i => $h) {
                    if (preg_match($p, mb_strtolower($h))) {
                        return $i;
                    }
                }
            }

            return null;
        };

        return [
            'date' => $find(['/^дата операции/u', '/дата/u', '/date/']),
            'amount' => $find(['/^сумма платежа$/u', '/^сумма операции$/u', '/сумма/u', '/amount|sum/']),
            'note' => $find(['/описание/u', '/назначение/u', '/description|memo|payee/', '/комментарий/u']),
            'category' => $find(['/^категория/u', '/category/']),
            'orig_amount' => \in_array(true, array_map(fn ($h) => mb_strtolower($h) === 'сумма платежа', $header), true) ? $find(['/^сумма операции$/u']) : null,
            'orig_currency' => $find(['/^валюта операции$/u', '/original currency/']),
        ];
    }

    public static function date(string $s): ?\DateTimeImmutable
    {
        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d', 'd/m/Y', 'd.m.y'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!'.$f, $s);
            if ($d !== false && \DateTimeImmutable::getLastErrors() === false) {
                return $d->setTime(0, 0);
            }
        }

        return null;
    }
}
