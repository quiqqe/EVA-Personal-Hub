<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Entity\Category;
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
            if ($c && $p->has('archive')) {
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

        return $this->render('money/categories.html.twig', ['scope' => $scope, 'categories' => $ledger->categories($scope, archived: true)]);
    }
}
