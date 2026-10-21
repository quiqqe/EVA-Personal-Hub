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

        // a group also sees members' personal accounts shared with it (only their owners can edit them)
        $shared = $scope->group ? array_values(array_filter($this->ledger->commonAccounts($scope->group), fn (Account $a) => $a->user !== null)) : [];

        return $this->render('money/accounts.html.twig', [
            'scope' => $scope,
            'accounts' => $accounts,
            'shared' => $shared,
            'balances' => $this->ledger->balances([...$accounts, ...$shared]),
        ]);
    }

    #[Route('/new', name: 'money_account_new')]
    #[Route('/{id}', name: 'money_account_edit', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function form(Request $r, Scope $scope, ?string $id = null): Response
    {
        $a = $id ? $this->find($scope, $id) : (new Account())->setScope($scope);
        if (!$id) {
            // presets from quick-start buttons: ?type=cash&currency=USDT&name=…
            $a->type = \array_key_exists((string) $r->query->get('type'), Account::TYPES) ? (string) $r->query->get('type') : 'card';
            $a->currency = isset(Money::choices()[(string) $r->query->get('currency')]) ? (string) $r->query->get('currency') : $scope->currency();
            $a->name = mb_substr((string) $r->query->get('name'), 0, 100);
        }
        $return = self::localPath((string) ($r->request->get('return') ?? $r->query->get('return')));
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
                // a personal account can be shown to any of my groups (its balance and operations join their common money)
                if (!$scope->group) {
                    $chosen = array_map('strval', $p->all('share'));
                    foreach ($this->ledger->groupsOf($scope->me) as $g) {
                        \in_array((string) $g->id, $chosen, true) ? ($a->sharedWith->contains($g) || $a->sharedWith->add($g)) : $a->sharedWith->removeElement($g);
                    }
                }
                $this->em->persist($a);
                $this->em->flush();

                // back to where the account was needed (an operation form, a goal…)
                return $return ? $this->redirect($return) : $this->redirectToRoute('money_accounts', ['scope' => $scope->key()]);
            }
        }

        return $this->render('money/account_form.html.twig', [
            'return' => $return,
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

    /** Show a personal account to a group in one tap (from a hint in the operation form). */
    #[Route('/{id}/share/{group}', name: 'money_account_share', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}', 'group' => '[0-9a-f-]{36}'])]
    public function share(Request $r, Scope $scope, string $id, string $group): Response
    {
        $this->checkCsrf($r);
        $a = $this->find($scope, $id);
        foreach ($this->ledger->groupsOf($scope->me) as $g) {
            if ((string) $g->id === $group && $a->user === $scope->me && !$a->sharedWith->contains($g)) {
                $a->sharedWith->add($g);
                $this->em->flush();
            }
        }

        return $this->json(['ok' => true]);
    }

    /** Only paths on this site: never redirect elsewhere. */
    public static function localPath(string $path): ?string
    {
        return preg_match('#^/(?![/\\\\])#', $path) ? $path : null;
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
