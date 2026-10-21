<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Budget;
use App\Money\Entity\Goal;
use App\Money\Entity\Recurring;
use App\Money\Ledger;
use App\Money\Money;
use App\Money\OperationForm;
use App\Money\Schedule;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/** Budgets, goals and recurring operations. */
#[Route('/money/{scope}/plan', requirements: ['scope' => 'me|[0-9a-f-]{36}', 'id' => '[0-9a-f-]{36}'])]
final class PlanController extends BaseController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Ledger $ledger)
    {
    }

    #[Route('', name: 'money_plan')]
    public function index(Scope $scope): Response
    {
        $owner = $scope->group ? ['group' => $scope->group] : ['user' => $scope->me, 'group' => null];

        return $this->render('money/plan.html.twig', [
            'scope' => $scope,
            'budgets' => DashboardController::budgets($scope, $this->em, $this->ledger),
            'goals' => DashboardController::goals($scope, $this->em, $this->ledger),
            'recurring' => $this->em->getRepository(Recurring::class)->findBy($owner, ['nextDate' => 'ASC']),
            'categories' => $this->ledger->categories($scope, 'expense'),
            'accounts' => $this->ledger->accounts($scope),
            'periods' => Schedule::PERIODS,
        ]);
    }

    #[Route('/budget', name: 'money_budget_save', methods: ['POST'])]
    public function budget(Request $r, Scope $scope): Response
    {
        $this->checkCsrf($r);
        $category = $this->own($scope, \App\Money\Entity\Category::class, (string) $r->request->get('category'));
        $currency = (string) $r->request->get('currency');
        $currency = isset(Money::choices()[$currency]) ? $currency : $scope->currency();
        $amount = Money::parse((string) $r->request->get('amount'), $currency);
        $budget = $this->em->getRepository(Budget::class)->findOneBy(['category' => $category]);
        if ($amount === null || $amount <= 0) {
            $budget && $this->em->remove($budget);
        } elseif ($budget) {
            $budget->amount = $amount;
            $budget->currency = $currency;
        } else {
            $this->em->persist((new Budget($category, $amount, $currency))->setScope($scope));
        }
        $this->em->flush();

        return $this->redirectToRoute('money_plan', ['scope' => $scope->key()]);
    }

    #[Route('/goal', name: 'money_goal_save', methods: ['POST'])]
    public function goal(Request $r, Scope $scope): Response
    {
        $this->checkCsrf($r);
        $p = $r->request;
        $account = $this->own($scope, \App\Money\Entity\Account::class, (string) $p->get('account'));
        $name = mb_substr(trim((string) $p->get('name')), 0, 100);
        $target = Money::parse((string) $p->get('target'), $account->currency);
        if ($name === '' || $target === null || $target <= 0) {
            $this->addFlash('error', 'Укажите название и сумму цели');
        } else {
            $g = (new Goal($name, $account, $target))->setScope($scope);
            $g->deadline = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $p->get('deadline')) ?: null;
            $this->em->persist($g);
            $this->em->flush();
        }

        return $this->redirectToRoute('money_plan', ['scope' => $scope->key()]);
    }

    #[Route('/goal/{id}/delete', name: 'money_goal_delete', methods: ['POST'])]
    public function deleteGoal(Request $r, Scope $scope, string $id): Response
    {
        $this->checkCsrf($r);
        $this->em->remove($this->own($scope, Goal::class, $id));
        $this->em->flush();

        return $this->redirectToRoute('money_plan', ['scope' => $scope->key()]);
    }

    #[Route('/recurring/new', name: 'money_recurring_new')]
    #[Route('/recurring/{id}', name: 'money_recurring_edit')]
    public function recurring(Request $r, Scope $scope, OperationForm $form, ?string $id = null): Response
    {
        $t = $id ? $this->own($scope, Recurring::class, $id) : (new Recurring())->setScope($scope);
        $errors = [];
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $oldPeriod = $t->period;
            $errors = $form->apply($r, $scope, $t);
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $r->request->get('date'));
            $start ?: $errors[] = 'Укажите дату первого платежа';
            if (!$errors) {
                // a new start date or period restarts the schedule from that date
                if (!$id || $start != $t->startDate || $t->period !== $oldPeriod) {
                    $t->startDate = $t->nextDate = $start;
                    $t->count = 0;
                }
                $t->createdBy ??= $this->me();
                $this->em->persist($t);
                $this->em->flush();
                $this->ledger->runRecurring($scope);

                return $this->redirectToRoute('money_plan', ['scope' => $scope->key()]);
            }
        }

        return $this->render('money/operation_form.html.twig', $form->context($scope) + [
            'scope' => $scope,
            'o' => $t,
            'recurring' => true,
            'periods' => Schedule::PERIODS,
            'isNew' => !$id,
            'errors' => $errors,
            'post' => $r->request->all(),
        ]);
    }

    #[Route('/recurring/{id}/toggle', name: 'money_recurring_toggle', methods: ['POST'])]
    public function toggle(Request $r, Scope $scope, string $id): Response
    {
        $this->checkCsrf($r);
        $t = $this->own($scope, Recurring::class, $id);
        if ($r->request->has('delete')) {
            $this->em->remove($t);
        } else {
            $t->active = !$t->active;
            // resuming must not back-fill everything missed while paused
            while ($t->active && $t->nextDate < new \DateTimeImmutable('today')) {
                $t->advance();
            }
        }
        $this->em->flush();

        return $this->redirectToRoute('money_plan', ['scope' => $scope->key()]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function own(Scope $scope, string $class, string $id): object
    {
        $e = Uuid::isValid($id) ? $this->em->find($class, $id) : null;
        if (!$e || !$scope->owns($e)) {
            throw $this->createNotFoundException();
        }

        return $e;
    }
}
