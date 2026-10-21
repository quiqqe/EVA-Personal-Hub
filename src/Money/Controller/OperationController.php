<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Operation;
use App\Money\Ledger;
use App\Money\Money;
use App\Money\OperationForm;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/money/{scope}/operations', requirements: ['scope' => 'me|[0-9a-f-]{36}', 'id' => '[0-9a-f-]{36}'])]
final class OperationController extends BaseController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Ledger $ledger,
        private readonly OperationForm $form,
    ) {
    }

    #[Route('', name: 'money_operations')]
    public function index(Request $r, Scope $scope): Response
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $r->query->get('m')) ?: new \DateTimeImmutable('first day of this month midnight');
        $q = trim((string) $r->query->get('q'));
        $qb = $this->ledger->operations($scope)->orderBy('o.date', 'DESC')->addOrderBy('o.id', 'DESC');
        if ($q !== '') {
            // search spans all time, so cap it
            $qb->andWhere('LOWER(o.note) LIKE :q')->setParameter('q', '%'.mb_strtolower(addcslashes($q, '%_')).'%')->setMaxResults(300);
        } else {
            $qb->andWhere('o.date >= :from AND o.date < :to')
                ->setParameter('from', $month, 'date_immutable')->setParameter('to', $month->modify('+1 month'), 'date_immutable');
        }
        $filters = ['account' => (string) $r->query->get('account'), 'category' => (string) $r->query->get('category'), 'type' => (string) $r->query->get('type')];
        // viewing one account: show its card (balance, opening balance, edit) above the list
        $account = null;
        if (Uuid::isValid($filters['account'])) {
            $qb->andWhere('a.id = :acc OR ta.id = :acc')->setParameter('acc', $filters['account']);
            foreach ($this->ledger->accounts($scope, archived: true) as $a) {
                if ((string) $a->id === $filters['account']) {
                    $account = $a;
                }
            }
        }
        if (Uuid::isValid($filters['category'])) {
            $qb->andWhere('o.category = :cat')->setParameter('cat', $filters['category']);
        }
        if (\array_key_exists($filters['type'], Operation::TYPES)) {
            $qb->andWhere('o.type = :type')->setParameter('type', $filters['type']);
        }
        $days = [];
        foreach ($qb->getQuery()->getResult() as $o) {
            $days[$o->date->format('Y-m-d')][] = $o;
        }

        return $this->render('money/operations.html.twig', [
            'scope' => $scope,
            'days' => $days,
            'month' => $month,
            'q' => $q,
            'filters' => $filters,
            'accounts' => $this->ledger->accounts($scope, archived: true),
            'account' => $account,
            'balance' => $account ? $this->ledger->balances([$account])[(string) $account->id] : null,
            'categories' => $this->ledger->categories($scope, archived: true),
        ]);
    }

    #[Route('/new', name: 'money_operation_new')]
    #[Route('/{id}', name: 'money_operation_edit')]
    public function form(Request $r, Scope $scope, ?string $id = null): Response
    {
        $o = $id ? $this->find($scope, $id) : new Operation();
        // a group's split expense is edited inside the group, where its splits make sense
        if ($o->group && !$scope->group) {
            return $this->redirectToRoute('money_operation_edit', ['scope' => $o->group->id, 'id' => $id]);
        }
        if (!$id) {
            $o->type = \array_key_exists((string) $r->query->get('type'), Operation::TYPES) ? (string) $r->query->get('type') : 'expense';
            // ?account= from an account's card, otherwise the account used last
            $o->account = null;
            foreach ($this->ledger->sourceAccounts($scope) as $a) {
                if ((string) $a->id === (string) $r->query->get('account')) {
                    $o->account = $a;
                }
            }
            $o->account ??= $this->lastAccount($scope);
        }
        $errors = [];
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $errors = $this->form->apply($r, $scope, $o);
            if (!$errors) {
                $o->createdBy ??= $this->me();
                $this->em->persist($o);
                $this->em->flush();
                $this->addFlash('ok', $id ? 'Сохранено' : 'Добавлено: '.Money::format($o->amount, $o->currency));

                return $r->request->getBoolean('more')
                    ? $this->redirectToRoute('money_operation_new', ['scope' => $scope->key(), 'type' => $o->type])
                    : $this->redirectToRoute('money_operations', ['scope' => $scope->key(), 'm' => $o->date->format('Y-m')]);
            }
        }

        return $this->render('money/operation_form.html.twig', $this->form->context($scope) + [
            'scope' => $scope,
            'o' => $o,
            'isNew' => !$id,
            'errors' => $errors,
            'post' => $r->request->all(),
        ]);
    }

    #[Route('/{id}/delete', name: 'money_operation_delete', methods: ['POST'])]
    public function delete(Request $r, Scope $scope, string $id): Response
    {
        $this->checkCsrf($r);
        $o = $this->find($scope, $id);
        $this->em->remove($o);
        $this->em->flush();
        $this->addFlash('ok', 'Операция удалена');

        return $this->redirectToRoute('money_operations', ['scope' => $scope->key(), 'm' => $o->date->format('Y-m')]);
    }

    #[Route('/export.csv', name: 'money_export')]
    public function export(Scope $scope): StreamedResponse
    {
        $ops = $this->ledger->operations($scope)->orderBy('o.date')->getQuery()->toIterable();
        $response = new StreamedResponse(function () use ($ops): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Дата', 'Тип', 'Счёт', 'Сумма', 'Валюта', 'Счёт зачисления', 'Сумма зачисления', 'Категория', 'Комментарий', 'Кто платил'], ';', '"', '');
            foreach ($ops as $o) {
                fputcsv($out, [
                    $o->date->format('d.m.Y'),
                    Operation::TYPES[$o->type],
                    $o->account?->name,
                    str_replace('.', ',', Money::toInput($o->type === 'income' ? $o->amount : -$o->amount, $o->currency)),
                    $o->currency,
                    $o->toAccount?->name,
                    $o->toAccount ? str_replace('.', ',', Money::toInput((int) $o->toAmount, $o->toAccount->currency)) : '',
                    $o->category?->name,
                    $o->note,
                    $o->paidBy?->name,
                ], ';', '"', '');
                $this->em->detach($o);
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="operations-'.date('Y-m-d').'.csv"');

        return $response;
    }

    private function find(Scope $scope, string $id): Operation
    {
        $o = Uuid::isValid($id) ? $this->em->find(Operation::class, $id) : null;
        if (!$o || !$this->ledger->visible($scope, $o)) {
            throw $this->createNotFoundException();
        }

        return $o;
    }

    /** Quick entry: start from the account used last. */
    private function lastAccount(Scope $scope): ?\App\Money\Entity\Account
    {
        $last = $this->ledger->operations($scope)->andWhere('o.createdBy = :me AND a.archived = false')->setParameter('me', $scope->me)
            ->orderBy('o.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $last?->account;
    }
}
