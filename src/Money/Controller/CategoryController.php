<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Budget;
use App\Money\Entity\Category;
use App\Money\Entity\Operation;
use App\Money\Entity\Recurring;
use App\Money\Ledger;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/money/{scope}/categories', requirements: ['scope' => 'me|[0-9a-f-]{36}'])]
final class CategoryController extends BaseController
{
    #[Route('', name: 'money_categories')]
    public function index(Request $r, Scope $scope, Ledger $ledger, EntityManagerInterface $em): Response
    {
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $p = $r->request;
            $id = (string) $p->get('id');
            $c = Uuid::isValid($id) ? $em->find(Category::class, $id) : null;
            if ($c && !$scope->owns($c)) {
                throw $this->createNotFoundException();
            }
            if ($c && $p->has('merge')) {
                return $this->merge($scope, $em, $c, (string) $p->get('into'));
            }
            if ($c && $p->has('delete')) {
                if ($this->uses($em, $c)) {
                    $this->addFlash('error', 'Категория уже в операциях — объедините её с другой или отправьте в архив');
                } else {
                    $em->remove($c);
                    $this->addFlash('ok', 'Категория «'.$c->name.'» удалена');
                }
            } elseif ($c && $p->has('archive')) {
                $c->archived = !$c->archived;
            } else {
                $name = mb_substr(trim((string) $p->get('name')), 0, 100);
                if ($name === '') {
                    $this->addFlash('error', 'Введите название');

                    return $this->redirectToRoute('money_categories', ['scope' => $scope->key()]);
                }
                $c ??= (new Category($p->get('kind') === 'income' ? 'income' : 'expense'))->setScope($scope);
                $c->name = $name;
                $c->icon = mb_substr(trim((string) $p->get('icon')), 0, 4);
                $em->persist($c);
            }
            $em->flush();

            return $this->redirectToRoute('money_categories', ['scope' => $scope->key()]);
        }

        $categories = $ledger->categories($scope, archived: true);

        return $this->render('money/categories.html.twig', [
            'scope' => $scope,
            'categories' => $categories,
            'uses' => array_combine(array_map(fn (Category $c) => (string) $c->id, $categories), array_map(fn (Category $c) => $this->uses($em, $c), $categories)),
        ]);
    }

    /** Two categories for the same thing ("Еда" and "Продукты"): everything moves into one, the other disappears. */
    private function merge(Scope $scope, EntityManagerInterface $em, Category $from, string $into): Response
    {
        $to = Uuid::isValid($into) ? $em->find(Category::class, $into) : null;
        if (!$to || $to === $from || !$scope->owns($to) || $to->kind !== $from->kind) {
            $this->addFlash('error', 'Выберите, с какой категорией объединить');
        } else {
            $em->wrapInTransaction(function () use ($em, $from, $to): void {
                foreach ([Operation::class, Recurring::class] as $class) {
                    $em->createQuery('UPDATE '.$class.' x SET x.category = :to WHERE x.category = :from')->setParameter('to', $to)->setParameter('from', $from)->execute();
                }
                // the limit carries over unless the remaining category already has its own
                $budget = $em->getRepository(Budget::class)->findOneBy(['category' => $from]);
                if ($budget && !$em->getRepository(Budget::class)->findOneBy(['category' => $to])) {
                    $budget->category = $to;
                    $em->flush();
                }
                $em->remove($from);
                $em->flush();
            });
            $this->addFlash('ok', '«'.$from->name.'» объединена с «'.$to->name.'»');
        }

        return $this->redirectToRoute('money_categories', ['scope' => $scope->key()]);
    }

    private function uses(EntityManagerInterface $em, Category $c): int
    {
        $n = 0;
        foreach ([Operation::class, Recurring::class] as $class) {
            $n += (int) $em->createQuery('SELECT COUNT(x.id) FROM '.$class.' x WHERE x.category = :c')->setParameter('c', $c)->getSingleScalarResult();
        }

        return $n;
    }
}
