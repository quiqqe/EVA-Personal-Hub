<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Settlement;
use App\Money\Ledger;
use App\Money\Entity\Account;
use App\Money\Entity\Operation;
use App\Money\Fx;
use App\Money\Money;
use App\Money\Rates;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Who owes whom inside a group (ТЗ §7). */
#[Route('/money/{scope}/debts', requirements: ['scope' => '[0-9a-f-]{36}'])]
final class DebtController extends BaseController
{
    #[Route('', name: 'money_debts')]
    public function index(Request $r, Scope $scope, Ledger $ledger, EntityManagerInterface $em, Rates $rates): Response
    {
        \assert($scope->group !== null);
        // current members and people who left with a history here: their debts can still be settled
        $members = $ledger->participants($scope->group);
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $p = $r->request;
            if (Uuid::isValid((string) $p->get('delete'))) {
                $s = $em->find(Settlement::class, (string) $p->get('delete'));
                // only the two people it's about can take it back
                if ($s?->group === $scope->group && ($s->from === $scope->me || $s->to === $scope->me)) {
                    // the money movements recorded for it go too
                    foreach ($s->operations() as $o) {
                        $em->remove($o);
                    }
                    $em->remove($s);
                    $em->flush();
                }

                return $this->redirectToRoute('money_debts', ['scope' => $scope->key()]);
            }
            // the other side of an already recorded return puts it on their own account
            if (Uuid::isValid((string) $p->get('attach'))) {
                $s = $em->find(Settlement::class, (string) $p->get('attach'));
                $account = $this->myAccount($ledger, $scope, (string) $p->get('account'));
                if ($s?->group === $scope->group && $account) {
                    $this->attach($s, $account, $rates, $scope, $em);
                    $em->flush();
                    $this->addFlash('ok', 'Записано на «'.$account->name.'»');
                }

                return $this->redirectToRoute('money_debts', ['scope' => $scope->key()]);
            }
            $from = $members[(string) $p->get('from')] ?? null;
            $to = $members[(string) $p->get('to')] ?? null;
            // what was handed over, in any currency; the debt it covers is in the debt's currency
            $currency = (string) $p->get('currency');
            $paidCur = (string) $p->get('paid_currency') ?: $currency;
            $known = Money::choices();
            $paid = isset($known[$paidCur]) ? Money::parse((string) $p->get('paid_amount'), $paidCur) : null;
            $amount = null;
            if (isset($known[$currency]) && $paid !== null && $paid > 0) {
                $amount = $paidCur === $currency ? $paid : Fx::amount(
                    $paid, $paidCur, $currency,
                    Money::parse((string) $p->get('amount'), $currency),
                    Fx::parseRate((string) $p->get('fx_rate')),
                    $rates->convert($paid, $paidCur, $currency),
                );
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $p->get('date'));
            $error = match (true) {
                !$from || !$to => 'Выберите, кто отдал и кому',
                $from === $to => 'Отдавал и получал один и тот же человек — выберите разных',
                $from !== $scope->me && $to !== $scope->me => 'Записать возврат может только тот, кто отдавал или получал деньги',
                $paid === null || $paid <= 0 => 'Введите, сколько отдали',
                $amount === null || $amount <= 0 => 'Нет курса для пересчёта — укажите курс или сколько долга закрыто',
                !$date || \DateTimeImmutable::getLastErrors() !== false || $date > new \DateTimeImmutable('today') => 'Укажите дату возврата (не в будущем)',
                default => null,
            };
            if ($error) {
                $this->addFlash('error', $error);
            } else {
                $st = new Settlement($scope->group, $from, $to, $amount, $currency);
                $st->date = $date;
                if ($paidCur !== $currency) {
                    $st->origAmount = $paid;
                    $st->origCurrency = $paidCur;
                }
                $em->persist($st);
                $account = $this->myAccount($ledger, $scope, (string) $p->get('account'));
                $o = $account ? $this->attach($st, $account, $rates, $scope, $em) : null;
                $em->flush();
                $this->addFlash('ok', $from->name.' → '.$to->name.': '.Money::format($amount, $currency)
                    .($st->origCurrency ? ' ('.Money::format($paid, $paidCur).')' : '')
                    .($o ? ' · '.($o->type === 'income' ? 'доход на' : 'расход с').' «'.$account->name.'»' : ''));
            }

            return $this->redirectToRoute('money_debts', ['scope' => $scope->key()]);
        }

        return $this->render('money/debts.html.twig', [
            'scope' => $scope,
            'members' => $members,
            'current' => array_map(fn ($u) => (string) $u->id, $scope->members()),
            'myAccounts' => array_values(array_filter($ledger->sourceAccounts($scope), fn (Account $a) => $a->user === $scope->me)),
            'debts' => $ledger->debts($scope->group),
            'settlements' => $em->getRepository(Settlement::class)->findBy(['group' => $scope->group], ['date' => 'DESC', 'id' => 'DESC'], 50),
        ]);
    }

    private function myAccount(Ledger $ledger, Scope $scope, string $id): ?Account
    {
        foreach ($ledger->sourceAccounts($scope) as $a) {
            if ($a->user === $scope->me && (string) $a->id === $id) {
                return $a;
            }
        }

        return null;
    }

    /**
     * Getting a debt back is income, paying one back is an expense — on the recorder's own account,
     * in the currency the money actually moved in. Each side records only their own account, once.
     */
    private function attach(Settlement $st, Account $account, Rates $rates, Scope $scope, EntityManagerInterface $em): ?Operation
    {
        $receiving = $st->to === $scope->me;
        if (!$receiving && $st->from !== $scope->me) {
            return null;
        }
        if ($receiving ? $st->receiverOperation : $st->payerOperation) {
            return null;
        }
        $paid = $st->origAmount ?? $st->amount;
        $paidCur = $st->origCurrency ?? $st->currency;
        $o = new Operation();
        $o->type = $receiving ? 'income' : 'expense';
        $o->account = $account;
        $o->currency = $account->currency;
        $o->date = $st->date;
        $o->amount = $paid;
        if ($paidCur !== $account->currency) {
            $o->origAmount = $paid;
            $o->origCurrency = $paidCur;
            $o->amount = $rates->convert($paid, $paidCur, $account->currency, $st->date) ?? $paid;
        }
        $o->note = 'Возврат долга: '.$st->from->name.' → '.$st->to->name;
        $o->createdBy = $scope->me;
        $em->persist($o);
        $receiving ? $st->receiverOperation = $o : $st->payerOperation = $o;

        return $o;
    }
}
