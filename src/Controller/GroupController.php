<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Group;
use App\Entity\Membership;
use App\Money\Ledger;
use App\Money\Entity\Account;
use App\Money\Entity\Recurring;
use App\Money\Money;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class GroupController extends BaseController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Ledger $ledger)
    {
    }

    #[Route('/groups', name: 'groups')]
    public function index(Request $r): Response
    {
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $name = trim((string) $r->request->get('name'));
            $currency = (string) $r->request->get('currency');
            if ($name === '' || mb_strlen($name) > 100 || !isset(Money::choices()[$currency])) {
                $this->addFlash('error', 'Введите название и валюту');

                return $this->redirectToRoute('groups');
            }
            $g = new Group($name, $currency);
            $this->em->persist($g);
            $this->em->persist(new Membership($g, $this->me(), 'owner'));
            $this->em->flush();

            return $this->redirectToRoute('group', ['id' => $g->id]);
        }

        return $this->render('groups/index.html.twig', ['groups' => $this->ledger->groupsOf($this->me())]);
    }

    #[Route('/groups/{id}', name: 'group', requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(Request $r, string $id): Response
    {
        [$g, $mine] = $this->load($id);
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $this->ownerOnly($mine);
            $name = trim((string) $r->request->get('name'));
            if ($name !== '' && mb_strlen($name) <= 100) {
                $g->name = $name;
            }
            if (isset(Money::choices()[(string) $r->request->get('currency')]) && $r->request->get('currency') !== $g->currency) {
                $g->currency = (string) $r->request->get('currency');
                $r->getSession()->remove('money_in_'.$g->id);
            }
            $this->em->flush();
            $this->addFlash('ok', 'Сохранено');

            return $this->redirectToRoute('group', ['id' => $id]);
        }

        return $this->render('groups/show.html.twig', ['group' => $g, 'mine' => $mine]);
    }

    #[Route('/groups/{id}/invite', name: 'group_invite', methods: ['POST'])]
    public function invite(Request $r, string $id): Response
    {
        $this->checkCsrf($r);
        [$g, $mine] = $this->load($id);
        $this->ownerOnly($mine);
        $g->newInviteToken();
        $this->em->flush();
        $this->addFlash('ok', 'Старая ссылка больше не работает');

        return $this->redirectToRoute('group', ['id' => $id]);
    }

    #[Route('/groups/{id}/remove/{member}', name: 'group_remove', methods: ['POST'])]
    public function remove(Request $r, string $id, string $member): Response
    {
        $this->checkCsrf($r);
        [$g, $mine] = $this->load($id);
        $self = (string) $mine->id === $member;
        if (!$self) {
            $this->ownerOnly($mine);
        }
        $m = null;
        foreach ($g->memberships as $x) {
            if ((string) $x->id === $member) {
                $m = $x;
            }
        }
        if (!$m) {
            throw $this->createNotFoundException();
        }
        $back = $this->redirectToRoute('group', ['id' => $id]);
        if (\count($g->memberships) === 1) {
            $this->addFlash('error', 'Вы последний участник. Удалите группу или сначала пригласите кого-нибудь — иначе она останется без хозяина.');

            return $back;
        }
        // nobody walks away from an open debt: it could never be settled afterwards
        $open = [];
        foreach ($this->ledger->debts($g) as $cur => $d) {
            $v = $d['net'][(string) $m->user->id] ?? 0;
            if ($v !== 0) {
                $open[] = ($v > 0 ? 'получит ' : 'отдаст ').Money::format(abs($v), $cur);
            }
        }
        if ($open) {
            $this->addFlash('error', ($self ? 'У вас' : 'У '.$m->user->name).' есть несведённые долги в группе: '.implode(', ', $open).'. Сначала рассчитайтесь в «Долгах».');

            return $back;
        }
        if ($m->isOwner()) {
            // pass ownership on so the group is never left without one
            foreach ($g->memberships as $other) {
                if ($other !== $m) {
                    $other->role = 'owner';
                    break;
                }
            }
        }
        $g->memberships->removeElement($m);
        $this->em->remove($m);
        // whoever leaves stops sharing their accounts with the group, and their money stops flowing in or out of it
        $accounts = $this->em->getRepository(Account::class)->findBy(['user' => $m->user]);
        foreach ($accounts as $a) {
            $a->sharedWith->removeElement($g);
        }
        foreach ($this->em->createQuery('SELECT r FROM '.Recurring::class.' r LEFT JOIN r.account a LEFT JOIN r.toAccount ta
            WHERE (r.group = :g AND a.user = :u) OR (r.user = :u AND ta.group = :g)')->setParameter('g', $g)->setParameter('u', $m->user)->getResult() as $rec) {
            $rec->active = false;
        }
        // someone removed by the owner can't come back with the old link
        if (!$self) {
            $g->newInviteToken();
        }
        $this->em->flush();
        $this->addFlash('ok', $self ? 'Вы вышли из группы «'.$g->name.'»' : $m->user->name.' больше не в группе. Ссылка-приглашение обновлена.');

        return $self ? $this->redirectToRoute('groups') : $back;
    }

    #[Route('/groups/{id}/owner/{member}', name: 'group_owner', methods: ['POST'])]
    public function makeOwner(Request $r, string $id, string $member): Response
    {
        $this->checkCsrf($r);
        [$g, $mine] = $this->load($id);
        $this->ownerOnly($mine);
        foreach ($g->memberships as $x) {
            if ((string) $x->id === $member && $x !== $mine) {
                $x->role = 'owner';
                $mine->role = 'member';
                $this->em->flush();
                $this->addFlash('ok', $x->user->name.' теперь владелец группы');
            }
        }

        return $this->redirectToRoute('group', ['id' => $id]);
    }

    #[Route('/groups/{id}/delete', name: 'group_delete', methods: ['POST'])]
    public function delete(Request $r, string $id): Response
    {
        $this->checkCsrf($r);
        [$g, $mine] = $this->load($id);
        $this->ownerOnly($mine);
        $ops = $this->ledger->operations(new Scope($this->me(), $g))->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();
        if ($ops > 0) {
            $this->addFlash('error', 'В группе есть операции — удалить нельзя. Можно выйти из группы.');

            return $this->redirectToRoute('group', ['id' => $id]);
        }
        $this->em->remove($g);
        $this->em->flush();

        return $this->redirectToRoute('groups');
    }

    #[Route('/join/{token}', name: 'join')]
    public function join(Request $r, string $token): Response
    {
        $g = $this->em->getRepository(Group::class)->findOneBy(['inviteToken' => $token]);
        if (!$g) {
            return $this->render('groups/join.html.twig', ['group' => null]);
        }
        if ($g->membershipOf($this->me())) {
            return $this->redirectToRoute('group', ['id' => $g->id]);
        }
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $this->em->persist(new Membership($g, $this->me()));
            $this->em->flush();

            return $this->redirectToRoute('money', ['scope' => $g->id]);
        }

        return $this->render('groups/join.html.twig', ['group' => $g]);
    }

    /** @return array{Group, Membership} */
    private function load(string $id): array
    {
        $g = Uuid::isValid($id) ? $this->em->find(Group::class, $id) : null;
        $m = $g?->membershipOf($this->me());
        if (!$m) {
            throw $this->createNotFoundException();
        }

        return [$g, $m];
    }

    private function ownerOnly(Membership $m): void
    {
        if (!$m->isOwner()) {
            throw $this->createAccessDeniedException();
        }
    }
}
