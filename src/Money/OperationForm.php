<?php

declare(strict_types=1);

namespace App\Money;

use App\Money\Entity\Operation;
use App\Money\Entity\Recurring;
use App\Money\Entity\Split;
use App\Entity\User;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

/** Reads the operation form (also used for recurring templates) into the entity. */
final class OperationForm
{
    public function __construct(private readonly Ledger $ledger, private readonly Rates $rates)
    {
    }

    /** @return list<string> errors; the entity is only valid to save when empty */
    public function apply(Request $r, Scope $s, Operation|Recurring $t, bool $isNew = true): array
    {
        $p = $r->request;
        $errors = [];
        $t->type = \array_key_exists((string) $p->get('type'), Operation::TYPES) ? (string) $p->get('type') : 'expense';

        $split = $t instanceof Operation && $s->group && $t->type === 'expense' && $p->getBoolean('split');
        // paid_by: a member, or "many" with paid[member] amounts
        $manyPayers = $split && $p->get('paid_by') === 'many';
        $people = $t instanceof Operation ? self::people($s, $t) : $s->members();
        $payer = $split && !$manyPayers ? ($this->pick($people, (string) $p->get('paid_by')) ?? $s->me) : $s->me;

        $postedAccount = (string) $p->get('account');
        $account = $this->pick($this->ledger->sourceAccounts($s), $postedAccount)
            // keep an archived or someone else's account the operation already has
            ?? ((string) $t->account?->id === $postedAccount ? $t->account : null);
        // the card is debited for its owner (me when it's mine; the payer's own card when someone corrects their bill)
        $owner = $account?->user ?? $s->me;
        $ownerPays = !$split || ($account !== null && ($manyPayers
            ? (Money::parse((string) ($p->all('paid')[(string) $owner->id] ?? ''), 'RUB') ?? 0) > 0
            : $payer === $owner));

        // only others paid from cards we can't see: no account, just the currency
        if ($split && !$ownerPays) {
            $t->account = null;
            $currency = (string) $p->get('currency');
            if (!isset(Money::choices()[$currency])) {
                $errors[] = 'Выберите валюту';
                $currency = $s->currency();
            }
        } else {
            if (!$account) {
                $errors[] = 'Выберите счёт';

                return $errors;
            }
            $t->account = $account;
            $currency = $account->currency;
        }
        if ($t instanceof Operation) {
            $t->currency = $currency;
        }

        // a sum in another currency than the account's: salary in USDT onto an AMD card, a card payment in EUR
        $entered = (string) $p->get('amount_currency');
        $foreign = $t->type !== 'transfer' && $t->account !== null
            && $entered !== '' && $entered !== $currency && isset(Money::choices()[$entered]);
        $amount = Money::parse((string) $p->get('amount'), $foreign ? $entered : $currency);
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Введите сумму больше нуля';
        }
        $t->amount = (int) $amount;
        $t->origAmount = $t->origCurrency = null;
        if ($foreign && $amount > 0 && $t instanceof Recurring) {
            // a template has no rate yet: each payment is converted at its own day's market rate
            $t->origAmount = $amount;
            $t->origCurrency = $entered;
            $estimate = $this->rates->convert($amount, $entered, $currency);
            if (!$estimate) {
                $errors[] = "Сейчас нет курса $entered → $currency — попробуйте позже или задайте сумму в валюте счёта";
            }
            $t->amount = (int) $estimate;
        } elseif ($foreign && $amount > 0) {
            $on = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $p->get('date')) ?: new \DateTimeImmutable('today');
            $credited = Fx::amount(
                $amount, $entered, $currency,
                Money::parse((string) $p->get('account_amount'), $currency),
                Fx::parseRate((string) $p->get('fx_rate')),
                $this->rates->convert($amount, $entered, $currency, $on),
            );
            if ($credited === null || $credited <= 0) {
                $errors[] = "Нет курса $entered → $currency: укажите курс или сумму по счёту";
            } else {
                $t->origAmount = $amount;
                $t->origCurrency = $entered;
                $t->amount = $credited;
            }
        }

        if ($t->type === 'transfer') {
            $to = $this->pick($this->ledger->reachableAccounts($s->me), (string) $p->get('to_account'))
                ?? ((string) $t->toAccount?->id === (string) $p->get('to_account') ? $t->toAccount : null);
            if (!$to || $to === $t->account) {
                $errors[] = 'Выберите другой счёт для зачисления';
            } else {
                $t->toAccount = $to;
                if ($to->currency === $currency) {
                    $t->toAmount = $t->amount;
                } else {
                    $toAmount = Money::parse((string) $p->get('to_amount'), $to->currency);
                    // an exchange office quotes a rate: "1 USDT = 395 AMD"
                    $rate = (float) str_replace([',', ' '], ['.', ''], (string) $p->get('rate'));
                    if ($toAmount === null && $rate > 0 && $t->amount > 0) {
                        $toAmount = (int) round($t->amount / 10 ** Money::digits($currency) * $rate * 10 ** Money::digits($to->currency));
                    }
                    if ($toAmount === null || $toAmount <= 0) {
                        $errors[] = 'Введите, сколько получено в '.$to->currency.', или курс';
                    }
                    $t->toAmount = (int) $toAmount;
                }
            }
            $t->category = null;
        } else {
            $t->toAccount = null;
            $t->toAmount = null;
            $cid = (string) $p->get('category');
            $t->category = $this->pick($this->ledger->categories($s, $t->type), $cid)
                ?? ($t->category?->kind === $t->type && (string) $t->category->id === $cid ? $t->category : null);
        }
        $t->note = mb_substr(trim((string) $p->get('note')), 0, 255);

        if ($t instanceof Recurring) {
            $t->period = \array_key_exists((string) $p->get('period'), Schedule::PERIODS) ? (string) $p->get('period') : 'month';

            return $errors;
        }

        $date = Dates::parse((string) $p->get('date'), '2000-01-01', '+1 year');
        $date ? $t->date = $date : $errors[] = 'Укажите дату (с 2000 года и не дальше чем через год)';
        // a new operation belongs to the space it's entered in; an existing one stays where it is
        if ($isNew) {
            $t->group = $s->group;
        }

        $t->splits->clear();
        $t->paidBy = null;
        $t->splitMode = null;
        $t->accountAmount = null;
        if ($split && $t->amount > 0) {
            array_push($errors, ...$this->applySplit($p, $s, $t, $manyPayers ? null : $payer, $people));
        }

        return $errors;
    }

    /**
     * Owed shares (equal / amounts / percent / shares) and who paid (one person or several).
     *
     * @return list<string> errors
     */
    private function applySplit(InputBag $p, Scope $s, Operation $t, ?User $payer, array $people): array
    {
        if ($t->account?->group) {
            return ['Расход с общего счёта делить не нужно — выберите свой счёт'];
        }
        $mode = \array_key_exists((string) $p->get('split_mode'), Debts::MODES) ? (string) $p->get('split_mode') : 'equal';
        // shares and payments are typed in the currency of the bill (EUR dinner), debts are kept in the account's
        $total = $t->origAmount ?? $t->amount;
        $cur = $t->origCurrency ?? $t->currency;
        $parts = $p->all('part');
        $checked = array_map('strval', $p->all('participants'));
        $values = [];
        foreach ($people as $u) {
            $id = (string) $u->id;
            $values[$id] = match ($mode) {
                'equal' => \in_array($id, $checked, true) ? 1 : 0,
                'amounts' => Money::parse((string) ($parts[$id] ?? ''), $cur) ?? 0,
                default => (float) str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], (string) ($parts[$id] ?? '')),
            };
        }
        try {
            $owed = Debts::split($total, $mode, $values);
        } catch (\InvalidArgumentException $e) {
            return [$e->getMessage()];
        }

        $paid = [];
        if ($payer) {
            $paid[(string) $payer->id] = $total;
        } else {
            foreach ($people as $u) {
                $v = Money::parse((string) ($p->all('paid')[(string) $u->id] ?? ''), $cur) ?? 0;
                if ($v > 0) {
                    $paid[(string) $u->id] = $v;
                }
            }
            if (array_sum($paid) !== $total) {
                return ['Кто сколько заплатил — в сумме должно быть '.Money::format($total, $cur)];
            }
        }
        if ($t->origAmount !== null) {
            // same proportions in the account's currency, not a unit lost
            $owed = Debts::proportional($t->amount, $owed);
            $paid = Debts::proportional($t->amount, $paid);
        }

        $t->splitMode = $mode;
        $t->paidBy = $payer;
        // what left the card is its owner's part of the payments
        $fromCard = $t->account ? ($paid[(string) ($t->account->user ?? $s->me)->id] ?? 0) : 0;
        $t->accountAmount = $t->account && $fromCard !== $t->amount ? $fromCard : null;
        foreach ($people as $u) {
            $id = (string) $u->id;
            if (($owed[$id] ?? 0) > 0 || ($paid[$id] ?? 0) > 0) {
                $weight = \in_array($mode, ['percent', 'shares'], true) && ($owed[$id] ?? 0) > 0 ? (float) $values[$id] : null;
                $t->splits->add(new Split($t, $u, $owed[$id] ?? 0, $paid[$id] ?? 0, $weight));
            }
        }

        return [];
    }

    /**
     * @template T of object
     *
     * @param list<T> $items
     *
     * @return T|null
     */
    private function pick(array $items, string $id): ?object
    {
        foreach ($items as $i) {
            if ((string) $i->id === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Who can take part in a split: current members plus anyone already in this bill (people who left keep their share).
     *
     * @return list<User>
     */
    public static function people(Scope $s, ?Operation $o = null): array
    {
        $out = [];
        foreach ([...$s->members(), ...($o ? array_map(fn (Split $sp) => $sp->user, $o->splits->toArray()) : []), ...($o?->paidBy ? [$o->paidBy] : [])] as $u) {
            $out[(string) $u->id] = $u;
        }

        return array_values($out);
    }

    /** Data the form template needs. */
    public function context(Scope $s, ?Operation $o = null): array
    {
        $members = [];
        foreach (self::people($s, $o) as $u) {
            $members[(string) $u->id] = $u;
        }

        return [
            'sources' => $this->ledger->sourceAccounts($s),
            'targets' => $this->ledger->reachableAccounts($s->me),
            'categories' => ['expense' => $this->ledger->categories($s, 'expense'), 'income' => $this->ledger->categories($s, 'income')],
            'topCategories' => ['expense' => $this->ledger->topCategories($s, 'expense'), 'income' => $this->ledger->topCategories($s, 'income')],
            'members' => $members,
        ];
    }
}
