<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Money\Ledger;
use App\Money\Money;
use App\Telegram;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class AppExtension
{
    public function __construct(private readonly Security $security, private readonly Ledger $ledger, private readonly Telegram $telegram)
    {
    }

    /** round: whole units, for converted totals where kopecks are noise */
    #[AsTwigFilter('money')]
    public static function money(int $minor, string $currency, bool $round = false): string
    {
        if ($round && !Money::isCrypto($currency)) {
            $unit = 10 ** Money::digits($currency);
            $minor = (int) round($minor / $unit) * $unit;
        }

        return Money::format($minor, $currency);
    }

    /** Effective rate of an exchange: "1 USDT = 395,2 AMD" */
    #[AsTwigFunction('fx_rate')]
    public static function fxRate(int $amount, string $from, int $toAmount, string $to): string
    {
        if ($amount === 0) {
            return '';
        }
        $rate = $toAmount / 10 ** Money::digits($to) / ($amount / 10 ** Money::digits($from));
        // show the rate the way people quote it: the bigger number per one unit of the "harder" currency
        [$one, $other, $rate] = $rate >= 1 ? [$from, $to, $rate] : [$to, $from, 1 / $rate];
        $fmt = new \NumberFormatter('ru_RU', \NumberFormatter::DECIMAL);
        $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $rate >= 100 ? 2 : 4);

        return "1 $one = ".$fmt->format($rate)." $other";
    }

    #[AsTwigFilter('money_input')]
    public static function moneyInput(?int $minor, string $currency): string
    {
        return $minor === null ? '' : Money::toInput($minor, $currency);
    }

    /** Groups for the space switcher in the header. */
    #[AsTwigFunction('my_groups')]
    public function myGroups(): array
    {
        $u = $this->security->getUser();

        return $u instanceof User ? $this->ledger->groupsOf($u) : [];
    }

    /** Bot username, or null when the bot is not configured (Telegram buttons are hidden then) */
    #[AsTwigFunction('telegram_bot')]
    public function telegramBot(): ?string
    {
        return $this->telegram->enabled() ? $this->telegram->botUsername : null;
    }

    #[AsTwigFunction('currencies')]
    public static function currencies(): array
    {
        return Money::choices();
    }
}
