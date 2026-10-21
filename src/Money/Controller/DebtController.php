<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Settlement;
use App\Money\Ledger;
use App\Money\Money;
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
    public function index(Request $r, Scope $scope, Ledger $ledger, EntityManagerInterface $em): Response
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
            $currency = (string) $p->get('currency');
            $amount = isset(Money::choices()[$currency]) ? Money::parse((string) $p->get('amount'), $currency) : null;
            if (!$from || !$to || $from === $to || $amount === null || $amount <= 0) {
                $this->addFlash('error', 'Проверьте, кто кому и сколько');
            } else {
                $em->persist(new Settlement($scope->group, $from, $to, $amount, $currency));
                $em->flush();
                $this->addFlash('ok', $from->name.' → '.$to->name.': '.Money::format($amount, $currency));
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
