<?php

declare(strict_types=1);

namespace App\Money;

use App\Money\Entity\Operation;
use App\Money\Entity\Recurring;
use App\Money\Entity\Split;
use Symfony\Component\HttpFoundation\Request;

/** Reads the operation form (also used for recurring templates) into the entity. */
final class OperationForm
{
    public function __construct(private readonly Ledger $ledger)
    {
    }

    /** @return list<string> errors; the entity is only valid to save when empty */
    public function apply(Request $r, Scope $s, Operation|Recurring $t): array
    {
        $p = $r->request;
        $errors = [];
        $t->type = \array_key_exists((string) $p->get('type'), Operation::TYPES) ? (string) $p->get('type') : 'expense';

        $split = $t instanceof Operation && $s->group && $t->type === 'expense' && $p->getBoolean('split');
        $payer = $s->me;
        if ($split) {
            $payer = $this->pick($s->members(), (string) $p->get('paid_by')) ?? $s->me;
        }

        // someone else paid: we don't know their account, only the currency
        if ($split && $payer !== $s->me) {
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

        $amount = Money::parse((string) $p->get('amount'), $currency);
        if ($amount === null || $amount <= 0) {
            $errors[] = 'Введите сумму больше нуля';
        }
        $t->amount = (int) $amount;

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
        if ($split) {
            $ids = array_map('strval', $p->all('participants'));
            $people = array_values(array_filter($s->members(), fn ($u) => \in_array((string) $u->id, $ids, true)));
            if (!$people) {
                $errors[] = 'Отметьте, между кем делить';
            } elseif ($t->account?->group) {
                $errors[] = 'Расход с общего счёта делить не нужно — выберите свой счёт';
            } else {
                $t->paidBy = $payer;
                foreach (Debts::splitEqually($t->amount, \count($people)) as $i => $share) {
                    $t->splits->add(new Split($t, $people[$i], $share));
                }
            }
        }

        return $errors;
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
            'members' => $members,
        ];
    }
}
