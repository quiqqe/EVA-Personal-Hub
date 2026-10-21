<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Budget;
use App\Money\Entity\Goal;
use App\Money\Dates;
use App\Money\Ledger;
use App\Money\Money;
use App\Money\Rates;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends BaseController
{
    #[Route('/money', name: 'money_home')]
    public function home(): Response
    {
        return $this->redirectToRoute('money', ['scope' => 'me']);
    }

    /** Market rate for the forms: GET /money/rate?from=USDT&to=AMD&date=2026-10-21 */
    #[Route('/money/rate', name: 'money_rate', priority: 10)]
    public function rate(Request $r, Rates $rates): Response
    {
        $from = (string) $r->query->get('from');
        $to = (string) $r->query->get('to');
        $date = Dates::parse((string) $r->query->get('date'), '2000-01-01', 'today');
        $known = Money::choices();

        return $this->json(['rate' => isset($known[$from], $known[$to]) ? $rates->rate($from, $to, $date) : null]);
    }

    #[Route('/money/{scope}', name: 'money', requirements: ['scope' => 'me|[0-9a-f-]{36}'])]
    public function index(Request $r, Scope $scope, Ledger $ledger, Rates $rates, EntityManagerInterface $em): Response
    {
        $in = self::displayCurrency($r, $scope);
        // a group's money: its own accounts plus personal ones shared with it — balance and flows over the same set
        $accounts = $scope->group ? $ledger->commonAccounts($scope->group) : $ledger->accounts($scope);
        $balances = $ledger->balances($accounts);
        $byCurrency = [];
        foreach ($accounts as $a) {
            $byCurrency[$a->currency] = ($byCurrency[$a->currency] ?? 0) + $balances[(string) $a->id];
        }
        [$total, $missing] = $rates->total($byCurrency, $in);

        $from = new \DateTimeImmutable('first day of this month midnight');
        $to = $from->modify('+1 month');
        // the same definitions as Reports: personal = my accounts; group = common money (+ separately: entered in it)
        $flows = $ledger->totals($scope, $scope->group ? 'common' : null, $from, $to, $in);
        $transfers = $ledger->transfers($accounts, $from, $to, $in);

        return $this->render('money/dashboard.html.twig', [
            'scope' => $scope,
            'in' => $in,
            'accounts' => $accounts,
            'balances' => $balances,
            'total' => $total,
            'missing' => $missing,
            'byCurrency' => $byCurrency,
            'income' => $flows['income'],
            'expense' => $flows['expense'],
            'flowMissing' => $flows['missing'],
            'transfers' => $transfers,
            'entered' => $scope->group ? $ledger->totals($scope, 'entered', $from, $to, $in) : null,
            'budgets' => self::budgets($scope, $em, $ledger),
            'goals' => $this->goals($scope, $em, $ledger),
            'debts' => $scope->group ? $ledger->debts($scope->group) : [],
            'recent' => $ledger->operations($scope)->orderBy('o.date', \SortDirection::Descending)->addOrderBy('o.id', \SortDirection::Descending)
                ->setMaxResults(10)->getQuery()->getResult(),
        ]);
    }

    /** @return list<array{budget: Budget, spent: int}> this month's spending in each budget's own currency */
    public static function budgets(Scope $scope, EntityManagerInterface $em, Ledger $ledger): array
    {
        $from = new \DateTimeImmutable('first day of this month midnight');
        $spent = [];
        $out = [];
        foreach ($em->getRepository(Budget::class)->findBy($scope->group ? ['group' => $scope->group] : ['user' => $scope->me, 'group' => null]) as $b) {
            $spent[$b->currency] ??= $ledger->byCategory($scope, 'expense', $from, $from->modify('+1 month'), $b->currency);
            $sum = 0;
            foreach ($spent[$b->currency]['rows'] as $row) {
                $sum += $row['category'] === $b->category ? $row['amount'] : 0;
            }
            $out[] = ['budget' => $b, 'spent' => $sum, 'missing' => $spent[$b->currency]['missing']];
        }

        return $out;
    }

    /** "Show totals in…": ?in=USDT, remembered per space for the session. */
    public static function displayCurrency(Request $r, Scope $scope): string
    {
        $key = 'money_in_'.$scope->key();
        $in = (string) $r->query->get('in');
        if (isset(Money::choices()[$in])) {
            $r->getSession()->set($key, $in);

            return $in;
        }

        return $r->getSession()->get($key, $scope->currency());
    }

    /** @return list<array{goal: Goal, saved: int}> */
    public static function goals(Scope $scope, EntityManagerInterface $em, Ledger $ledger): array
    {
        $goals = $em->getRepository(Goal::class)->findBy($scope->group ? ['group' => $scope->group] : ['user' => $scope->me, 'group' => null]);
        $balances = $ledger->balances(array_map(fn (Goal $g) => $g->account, $goals));

        return array_map(fn (Goal $g) => ['goal' => $g, 'saved' => $balances[(string) $g->account->id]], $goals);
    }
}
