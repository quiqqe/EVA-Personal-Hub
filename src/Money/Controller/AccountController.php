<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Account;
use App\Money\Entity\Operation;
use App\Money\Ledger;
use App\Money\Money;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/money/{scope}/accounts', requirements: ['scope' => 'me|[0-9a-f-]{36}'])]
final class AccountController extends BaseController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Ledger $ledger)
    {
    }

    #[Route('', name: 'money_accounts')]
    public function index(Scope $scope): Response
    {
        $accounts = $this->ledger->accounts($scope, archived: true);

        return $this->render('money/accounts.html.twig', [
            'scope' => $scope,
            'accounts' => $accounts,
            'balances' => $this->ledger->balances($accounts),
        ]);
    }

    #[Route('/new', name: 'money_account_new')]
    #[Route('/{id}', name: 'money_account_edit', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function form(Request $r, Scope $scope, ?string $id = null): Response
    {
        $a = $id ? $this->find($scope, $id) : (new Account())->setScope($scope);
        if (!$id) {
            $a->currency = $scope->currency();
            $a->type = (string) $r->query->get('type', 'card');
        }
        $error = null;
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $p = $r->request;
            $a->name = mb_substr(trim((string) $p->get('name')), 0, 100);
            $a->type = \array_key_exists((string) $p->get('type'), Account::TYPES) ? (string) $p->get('type') : 'other';
            // currency is fixed once there are operations: their amounts are in it
            if (!$id || !$this->hasOperations($a)) {
                $a->currency = isset(Money::choices()[(string) $p->get('currency')]) ? (string) $p->get('currency') : $a->currency;
            }
            $initial = Money::parse((string) $p->get('initial') ?: '0', $a->currency);
            $error = match (true) {
                $a->name === '' => 'Введите название',
                $initial === null => 'Начальный остаток — число',
                default => null,
            };
            if (!$error) {
                $a->initialBalance = (int) $initial;
                $this->em->persist($a);
                $this->em->flush();

                return $this->redirectToRoute('money_accounts', ['scope' => $scope->key()]);
            }
        }

        return $this->render('money/account_form.html.twig', [
            'scope' => $scope,
            'account' => $a,
            'isNew' => !$id,
            'locked' => $id && $this->hasOperations($a),
            'error' => $error,
        ]);
    }

    #[Route('/{id}/archive', name: 'money_account_archive', methods: ['POST'])]
    public function archive(Request $r, Scope $scope, string $id): Response
    {
        $this->checkCsrf($r);
        $a = $this->find($scope, $id);
        $a->archived = !$a->archived;
        $this->em->flush();

        return $this->redirectToRoute('money_accounts', ['scope' => $scope->key()]);
    }

    #[Route('/{id}/delete', name: 'money_account_delete', methods: ['POST'])]
    public function delete(Request $r, Scope $scope, string $id): Response
    {
        $this->checkCsrf($r);
        $a = $this->find($scope, $id);
        if ($this->hasOperations($a)) {
            $this->addFlash('error', 'По счёту есть операции — его можно только архивировать');
        } else {
            $this->em->remove($a);
            $this->em->flush();
        }

        return $this->redirectToRoute('money_accounts', ['scope' => $scope->key()]);
    }

    private function find(Scope $scope, string $id): Account
    {
        $a = Uuid::isValid($id) ? $this->em->find(Account::class, $id) : null;
        if (!$a || !$scope->owns($a)) {
            throw $this->createNotFoundException();
        }

        return $a;
    }

    private function hasOperations(Account $a): bool
    {
        return (bool) $this->em->createQuery('SELECT COUNT(o.id) FROM '.Operation::class.' o WHERE o.account = :a OR o.toAccount = :a')
            ->setParameter('a', $a)->getSingleScalarResult();
    }
}
