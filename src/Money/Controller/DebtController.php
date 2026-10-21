<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Settlement;
use App\Money\Ledger;
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
        $members = [];
        foreach ($scope->members() as $u) {
            $members[(string) $u->id] = $u;
        }
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $p = $r->request;
            if (Uuid::isValid((string) $p->get('delete'))) {
                $s = $em->find(Settlement::class, (string) $p->get('delete'));
                if ($s?->group === $scope->group) {
                    $em->remove($s);
                    $em->flush();
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
            if (!$from || !$to || $from === $to || $amount === null || $amount <= 0) {
                $this->addFlash('error', 'Проверьте, кто кому и сколько');
            } else {
                $st = new Settlement($scope->group, $from, $to, $amount, $currency);
                if ($paidCur !== $currency) {
                    $st->origAmount = $paid;
                    $st->origCurrency = $paidCur;
                }
                $em->persist($st);
                $em->flush();
                $this->addFlash('ok', $from->name.' → '.$to->name.': '.Money::format($amount, $currency)
                    .($st->origCurrency ? ' ('.Money::format($paid, $paidCur).')' : ''));
            }

            return $this->redirectToRoute('money_debts', ['scope' => $scope->key()]);
        }

        return $this->render('money/debts.html.twig', [
            'scope' => $scope,
            'members' => $members,
            'debts' => $ledger->debts($scope->group),
            'settlements' => $em->getRepository(Settlement::class)->findBy(['group' => $scope->group], ['date' => 'DESC', 'id' => 'DESC'], 50),
        ]);
    }
}
