<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Ledger;
use App\Money\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends BaseController
{
    #[Route('/money/{scope}/reports', name: 'money_reports', requirements: ['scope' => 'me|[0-9a-f-]{36}'])]
    public function index(Request $r, Scope $scope, Ledger $ledger): Response
    {
        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) $r->query->get('m')) ?: new \DateTimeImmutable('first day of this month midnight');
        $next = $month->modify('+1 month');
        $in = DashboardController::displayCurrency($r, $scope);
        $monthly = $ledger->monthly($scope, 12, $in);

        return $this->render('money/reports.html.twig', [
            'scope' => $scope,
            'month' => $month,
            'in' => $in,
            'expense' => $ledger->byCategory($scope, 'expense', $month, $next, $in),
            'income' => $ledger->byCategory($scope, 'income', $month, $next, $in),
            'monthly' => $monthly,
            'peak' => max(1, ...array_values(array_map(fn ($m) => max($m['income'], $m['expense']), $monthly))),
        ]);
    }
}
