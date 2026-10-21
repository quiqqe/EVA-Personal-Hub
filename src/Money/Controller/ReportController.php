<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Dates;
use App\Money\Ledger;
use App\Money\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends BaseController
{
    public const PERIODS = ['month' => 'Месяц', 'year' => 'Год', 'range' => 'Период'];

    #[Route('/money/{scope}/reports', name: 'money_reports', requirements: ['scope' => 'me|[0-9a-f-]{36}'])]
    public function index(Request $r, Scope $scope, Ledger $ledger): Response
    {
        [$p, $from, $to, $prevFrom] = self::period($r);
        $in = DashboardController::displayCurrency($r, $scope);
        // a group: which money to count (same two definitions as its dashboard)
        $basis = $ledger->basis($scope, (string) $r->query->get('basis'));
        $monthly = $ledger->monthly($scope, 12, $in, $basis);

        return $this->render('money/reports.html.twig', [
            'scope' => $scope,
            'p' => $p,
            'periods' => self::PERIODS,
            'from' => $from,
            // inclusive last day, for display and links
            'last' => $to->modify('-1 day'),
            'prevFrom' => $prevFrom,
            'in' => $in,
            'basis' => $basis,
            'bases' => Ledger::BASES,
            'expense' => $ledger->byCategory($scope, 'expense', $from, $to, $in, $basis),
            'income' => $ledger->byCategory($scope, 'income', $from, $to, $in, $basis),
            // the same length of time just before, to compare with
            'prev' => $ledger->totals($scope, $basis, $prevFrom, $from, $in),
            'monthly' => $monthly['months'],
            'monthlyMissing' => $monthly['missing'],
            'peak' => max(1, ...array_values(array_map(fn ($m) => max($m['income'], $m['expense']), $monthly['months']))),
        ]);
    }

    /** @return array{string, \DateTimeImmutable, \DateTimeImmutable, \DateTimeImmutable} period, from, to (exclusive), previous period's start */
    public static function period(Request $r): array
    {
        $q = $r->query;
        if ($q->get('p') === 'range') {
            $from = Dates::parse((string) $q->get('from'));
            $last = Dates::parse((string) $q->get('to'));
            if ($from && $last) {
                [$from, $last] = $from <= $last ? [$from, $last] : [$last, $from];
                $to = $last->modify('+1 day');

                return ['range', $from, $to, $from->sub($from->diff($to))];
            }
        }
        if ($q->get('p') === 'year') {
            $y = (int) $q->get('y') ?: (int) date('Y');
            $from = (new \DateTimeImmutable('today'))->setDate(max(2000, min(2100, $y)), 1, 1);

            return ['year', $from, $from->modify('+1 year'), $from->modify('-1 year')];
        }
        $from = \DateTimeImmutable::createFromFormat('!Y-m', (string) $q->get('m')) ?: new \DateTimeImmutable('first day of this month midnight');

        return ['month', $from, $from->modify('+1 month'), $from->modify('-1 month')];
    }
}
