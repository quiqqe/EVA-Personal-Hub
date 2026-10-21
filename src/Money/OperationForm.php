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
    public function apply(Request $r, Scope $s, Operation|Recurring $t): array
    {
        $p = $r->request;
        $errors = [];
        $t->type = \array_key_exists((string) $p->get('type'), Operation::TYPES) ? (string) $p->get('type') : 'expense';

        $split = $t instanceof Operation && $s->group && $t->type === 'expense' && $p->getBoolean('split');
        // paid_by: a member, or "many" with paid[member] amounts
        $manyPayers = $split && $p->get('paid_by') === 'many';
        $payer = $split && !$manyPayers ? ($this->pick($s->members(), (string) $p->get('paid_by')) ?? $s->me) : $s->me;
        $iPay = !$split || ($manyPayers
            ? (Money::parse((string) ($p->all('paid')[(string) $s->me->id] ?? ''), 'RUB') ?? 0) > 0
            : $payer === $s->me);

        // only others paid: we don't know their accounts, only the currency
        if ($split && !$iPay) {
            $t->account = null;
            $currency = (string) $p->get('currency');
            if (!isset(Money::choices()[$currency])) {
                $errors[] = 'Выберите валюту';
                $currency = $s->currency();
            }
        } else {
            $account = $this->pick($this->ledger->sourceAccounts($s), (string) $p->get('account'))
                // keep an archived or someone else's account the operation already has
                ?? ((string) $t->account?->id === (string) $p->get('account') ? $t->account : null);
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
            $t->amount = $this->rates->convert($amount, $entered, $currency) ?? 0;
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

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $p->get('date'));
        $date ? $t->date = $date : $errors[] = 'Укажите дату';
        $t->group = $s->group;

        $t->splits->clear();
        $t->paidBy = null;
        $t->splitMode = null;
        $t->accountAmount = null;
        if ($split && $t->amount > 0) {
            array_push($errors, ...$this->applySplit($p, $s, $t, $manyPayers ? null : $payer));
        }

        return $errors;
    }

    /**
     * Owed shares (equal / amounts / percent / shares) and who paid (one person or several).
     *
     * @return list<string> errors
     */
    private function applySplit(InputBag $p, Scope $s, Operation $t, ?User $payer): array
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
        foreach ($s->members() as $u) {
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
            foreach ($s->members() as $u) {
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
        $mine = $paid[(string) $s->me->id] ?? 0;
        $t->accountAmount = $t->account && $mine !== $t->amount ? $mine : null;
        foreach ($s->members() as $u) {
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

    /** Data the form template needs. */
    public function context(Scope $s): array
    {
        $members = [];
        foreach ($s->members() as $u) {
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
