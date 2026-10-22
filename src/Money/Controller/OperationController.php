<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Operation;
use App\Money\Ledger;
use App\Money\Dates;
use App\Money\Money;
use App\Money\Rates;
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
        private readonly Rates $rates,
    ) {
    }

    #[Route('', name: 'money_operations')]
    public function index(Request $r, Scope $scope): Response
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $r->query->get('m')) ?: new \DateTimeImmutable('first day of this month midnight');
        // a report's year or custom period opens here as from–to (inclusive) instead of one month
        $range = Dates::parse((string) $r->query->get('from')) && Dates::parse((string) $r->query->get('to'))
            ? [Dates::parse((string) $r->query->get('from')), Dates::parse((string) $r->query->get('to'))] : null;
        $q = trim((string) $r->query->get('q'));
        $qb = $this->ledger->operations($scope)->leftJoin('o.category', 'c')
            ->orderBy('o.date', \SortDirection::Descending)->addOrderBy('o.id', \SortDirection::Descending);
        if ($q !== '') {
            // search spans all time: comment, category, or the sum itself ("25000", "1 250,50")
            $like = '%'.mb_strtolower(addcslashes($q, '%_')).'%';
            $where = 'LOWER(o.note) LIKE :q OR LOWER(c.name) LIKE :q';
            $sum = Money::parse($q, 'RUB');
            if ($sum !== null && $sum > 0) {
                $where .= ' OR o.amount = :sum OR o.origAmount = :sum';
                $qb->setParameter('sum', $sum);
            }
            $qb->andWhere($where)->setParameter('q', $like)->setMaxResults(500);
        } else {
            $qb->andWhere('o.date >= :from AND o.date < :to')
                ->setParameter('from', $range[0] ?? $month, 'date_immutable')->setParameter('to', $range ? $range[1]->modify('+1 day') : $month->modify('+1 month'), 'date_immutable');
        }
        $filters = self::filter($r, $qb);
        // viewing one account: show its card (balance, opening balance, edit) above the list
        $account = null;
        if (Uuid::isValid($filters['account'])) {
            $candidates = $this->ledger->accounts($scope, archived: true);
            if ($scope->group) {
                $candidates = [...$candidates, ...$this->ledger->commonAccounts($scope->group)];
            }
            foreach ($candidates as $a) {
                if ((string) $a->id === $filters['account']) {
                    $account = $a;
                }
            }
        }
        // totals of what's listed, in the space's display currency, each at its own day's rate
        $in = DashboardController::displayCurrency($r, $scope);
        $days = $dayNet = [];
        $sum = ['income' => 0, 'expense' => 0, 'count' => 0, 'missing' => []];
        foreach ($qb->getQuery()->getResult() as $o) {
            $day = $o->date->format('Y-m-d');
            $days[$day][] = $o;
            ++$sum['count'];
            if ($o->type === 'transfer') {
                continue;
            }
            $v = $this->rates->convert($scope->group ? $o->amount : ($o->accountAmount ?? $o->amount), $o->currency, $in, $o->date);
            if ($v === null) {
                $sum['missing'][] = $o->currency;
                continue;
            }
            $sum[$o->type] += $v;
            $dayNet[$day] = ($dayNet[$day] ?? 0) + ($o->type === 'income' ? $v : -$v);
        }
        $sum['missing'] = array_values(array_unique($sum['missing']));

        return $this->render('money/operations.html.twig', [
            'scope' => $scope,
            'days' => $days,
            'dayNet' => $dayNet,
            'sum' => $sum,
            'in' => $in,
            'month' => $month,
            'range' => $range,
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
        // a group's operation is edited inside the group (if I'm still in it), where its splits make sense
        if ($o->group && !$scope->group && $o->group->membershipOf($this->me())) {
            return $this->redirectToRoute('money_operation_edit', ['scope' => $o->group->id, 'id' => $id]);
        }
        if ($id && !$this->ledger->canEdit($scope, $o)) {
            if ($r->isMethod('POST')) {
                throw $this->createAccessDeniedException();
            }

            return $this->render('money/operation_view.html.twig', ['scope' => $scope, 'o' => $o]);
        }
        if (!$id) {
            $o->type = \array_key_exists((string) $r->query->get('type'), Operation::TYPES) ? (string) $r->query->get('type') : 'expense';
            // "save and add another" keeps the day
            $o->date = Dates::parse((string) $r->query->get('date')) ?? $o->date;
            // ?account= from an account's card, otherwise the account used last
            $o->account = null;
            foreach ($this->ledger->sourceAccounts($scope) as $a) {
                if ((string) $a->id === (string) $r->query->get('account')) {
                    $o->account = $a;
                }
            }
            // the form shows its account and the sum's currency follows it: never leave it to the browser's first option
            $o->account ??= $this->lastAccount($scope) ?? ($this->ledger->sourceAccounts($scope)[0] ?? null);
        }
        $errors = [];
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $errors = $this->form->apply($r, $scope, $o, isNew: !$id);
            if (!$errors) {
                $o->createdBy ??= $this->me();
                $this->em->persist($o);
                $this->em->flush();
                $this->addFlash('ok', $id ? 'Сохранено' : 'Добавлено: '.Money::format($o->amount, $o->currency));

                if ($r->request->getBoolean('more')) {
                    return $this->redirectToRoute('money_operation_new', ['scope' => $scope->key(), 'type' => $o->type, 'date' => $o->date->format('Y-m-d'), 'account' => $o->account?->id, 'back' => $r->request->get('back')]);
                }
                // back to where the person came from (an account's card, the dashboard), else the month's list
                $back = AccountController::localPath((string) $r->request->get('back'));

                return $back ? $this->redirect($back) : $this->redirectToRoute('money_operations', ['scope' => $scope->key(), 'm' => $o->date->format('Y-m')]);
            }
        }

        return $this->render('money/operation_form.html.twig', $this->form->context($scope, $o) + [
            'scope' => $scope,
            'o' => $o,
            'isNew' => !$id,
            'errors' => $errors,
            'post' => $r->request->all(),
            // where to return after saving: the page the form was opened from
            'back' => self::backTo($r),
        ]);
    }

    #[Route('/{id}/delete', name: 'money_operation_delete', methods: ['POST'])]
    public function delete(Request $r, Scope $scope, string $id): Response
    {
        $this->checkCsrf($r);
        $o = $this->find($scope, $id);
        if (!$this->ledger->canEdit($scope, $o)) {
            throw $this->createAccessDeniedException();
        }
        $this->em->remove($o);
        $this->em->flush();
        $this->addFlash('ok', 'Операция удалена');

        return $this->redirectToRoute('money_operations', ['scope' => $scope->key(), 'm' => $o->date->format('Y-m')]);
    }

    /** Several operations at once: give them one category, or delete them. Only what I may edit is touched. */
    #[Route('/bulk', name: 'money_operations_bulk', methods: ['POST'])]
    public function bulk(Request $r, Scope $scope): Response
    {
        $this->checkCsrf($r);
        $ops = [];
        foreach (array_slice(array_map('strval', $r->request->all('ids')), 0, 500) as $id) {
            $o = Uuid::isValid($id) ? $this->em->find(Operation::class, $id) : null;
            if ($o && $this->ledger->visible($scope, $o) && $this->ledger->canEdit($scope, $o)) {
                $ops[] = $o;
            }
        }
        $done = 0;
        if ($r->request->has('delete')) {
            foreach ($ops as $o) {
                $this->em->remove($o);
                ++$done;
            }
            $msg = 'Удалено операций: '.$done;
        } else {
            $category = null;
            foreach ($this->ledger->categories($scope) as $c) {
                if ((string) $c->id === (string) $r->request->get('category')) {
                    $category = $c;
                }
            }
            if (!$category) {
                $this->addFlash('error', 'Выберите категорию');

                return $this->redirect(AccountController::localPath((string) $r->request->get('back')) ?? $this->generateUrl('money_operations', ['scope' => $scope->key()]));
            }
            // an expense category fits only expenses; transfers have none
            foreach ($ops as $o) {
                if ($o->type === $category->kind) {
                    $o->category = $category;
                    ++$done;
                }
            }
            $msg = 'Категория «'.$category->name.'» у операций: '.$done.(\count($ops) > $done ? ' (другого типа пропущены: '.(\count($ops) - $done).')' : '');
        }
        $this->em->flush();
        $skipped = \count(array_unique($r->request->all('ids'))) - \count($ops);
        $this->addFlash('ok', $msg.($skipped > 0 ? '. Чужие операции не тронуты: '.$skipped : ''));

        return $this->redirect(AccountController::localPath((string) $r->request->get('back')) ?? $this->generateUrl('money_operations', ['scope' => $scope->key()]));
    }

    /** Everything in the space, or just what the list shows: ?account= &category= &type= &from= &to= (inclusive). */
    #[Route('/export.csv', name: 'money_export')]
    public function export(Request $r, Scope $scope): StreamedResponse
    {
        $qb = $this->ledger->operations($scope)->orderBy('o.date');
        self::filter($r, $qb);
        $from = Dates::parse((string) $r->query->get('from'));
        $to = Dates::parse((string) $r->query->get('to'));
        if ($from && $to) {
            $qb->andWhere('o.date >= :from AND o.date <= :to')->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable');
        }
        $ops = $qb->getQuery()->toIterable();
        $response = new StreamedResponse(function () use ($ops, $scope): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Дата', 'Тип', 'Счёт', 'Сумма', 'Валюта', 'Исходная сумма', 'Исходная валюта', 'Счёт зачисления', 'Сумма зачисления', 'Категория', 'Комментарий', 'Кто платил'], ';', '"', '');
            foreach ($ops as $o) {
                // what hit the account in my space (my part of a shared bill), the whole bill in a group
                $sum = $scope->group ? $o->amount : ($o->accountAmount ?? $o->amount);
                fputcsv($out, array_map(self::safeCell(...), [
                    $o->date->format('d.m.Y'),
                    Operation::TYPES[$o->type],
                    $o->account?->name,
                    str_replace('.', ',', Money::toInput($o->type === 'income' ? $sum : -$sum, $o->currency)),
                    $o->currency,
                    $o->origCurrency ? str_replace('.', ',', Money::toInput($o->type === 'income' ? $o->origAmount : -$o->origAmount, $o->origCurrency)) : '',
                    $o->origCurrency,
                    $o->toAccount?->name,
                    $o->toAccount ? str_replace('.', ',', Money::toInput((int) $o->toAmount, $o->toAccount->currency)) : '',
                    $o->category?->name,
                    $o->note,
                    $o->paidBy?->name,
                ]), ';', '"', '');
                $this->em->detach($o);
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="operations-'.date('Y-m-d').'.csv"');

        return $response;
    }

    /** The list's account / category / type filters, shared by the list and its export. */
    private static function filter(Request $r, \Doctrine\ORM\QueryBuilder $qb): array
    {
        $f = ['account' => (string) $r->query->get('account'), 'category' => (string) $r->query->get('category'), 'type' => (string) $r->query->get('type')];
        if (Uuid::isValid($f['account'])) {
            $qb->andWhere('a.id = :acc OR ta.id = :acc')->setParameter('acc', $f['account']);
        }
        if (Uuid::isValid($f['category'])) {
            $qb->andWhere('o.category = :cat')->setParameter('cat', $f['category']);
        } elseif ($f['category'] === 'none') {
            $qb->andWhere('o.category IS NULL AND o.type != :tr')->setParameter('tr', 'transfer');
        }
        if (\array_key_exists($f['type'], Operation::TYPES)) {
            $qb->andWhere('o.type = :type')->setParameter('type', $f['type']);
        }

        return $f;
    }

    /** The page the form was opened from (never another operation form), to return to after saving. */
    private static function backTo(Request $r): ?string
    {
        $ref = (string) $r->headers->get('referer');
        $fromReferer = $ref ? parse_url($ref, PHP_URL_PATH).(($qs = parse_url($ref, PHP_URL_QUERY)) ? '?'.$qs : '') : '';
        $back = AccountController::localPath((string) ($r->request->get('back') ?? $r->query->get('back') ?? $fromReferer));

        return $back && !preg_match('#/operations/(new|[0-9a-f-]{36})#', $back) ? $back : null;
    }

    /** A cell starting with = + - @ is a formula to Excel: names and notes become plain text, numbers stay numbers. */
    private static function safeCell(mixed $v): string
    {
        $v = (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^-?[\d\s]+(,\d+)?$/', $v) ? "'".$v : $v;
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
            ->orderBy('o.id', \SortDirection::Descending)->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($last?->account) {
            return $last->account;
        }
        // in a group, the first entry goes to the group's own account rather than a personal card
        foreach ($scope->group ? $this->ledger->sourceAccounts($scope) : [] as $a) {
            if ($a->group === $scope->group) {
                return $a;
            }
        }

        return null;
    }
}
