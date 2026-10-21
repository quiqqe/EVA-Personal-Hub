<?php

declare(strict_types=1);

namespace App\Money;

use App\Entity\Group;
use App\Entity\User;
use App\Money\Entity\Account;
use App\Money\Entity\Category;
use App\Money\Entity\Operation;
use App\Money\Entity\Recurring;
use App\Money\Entity\Settlement;
use App\Money\Entity\Split;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** Queries and calculations over a space's money. */
final class Ledger
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Rates $rates)
    {
    }

    /** @return list<Group> */
    public function groupsOf(User $user): array
    {
        return $this->em->createQuery('SELECT g FROM '.Group::class.' g JOIN g.memberships m WHERE m.user = :u ORDER BY g.id')
            ->setParameter('u', $user)->getResult();
    }

    /** @return list<Account> */
    public function accounts(Scope $s, bool $archived = false): array
    {
        $qb = $this->em->createQueryBuilder()->select('a')->from(Account::class, 'a')->orderBy('a.archived')->addOrderBy('a.id');
        $s->group ? $qb->where('a.group = :o')->setParameter('o', $s->group) : $qb->where('a.user = :o')->setParameter('o', $s->me);
        if (!$archived) {
            $qb->andWhere('a.archived = false');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * A group's common money: its own accounts plus members' personal accounts shared with it.
     *
     * @return list<Account>
     */
    public function commonAccounts(Group $g): array
    {
        return $this->em->createQuery('SELECT a FROM '.Account::class.' a WHERE a.archived = false AND (a.group = :g OR :g MEMBER OF a.sharedWith) ORDER BY a.id')
            ->setParameter('g', $g)->getResult();
    }



    /** @return list<Account> my personal accounts plus those of all my groups (destinations of a transfer) */
    public function reachableAccounts(User $me): array
    {
        return $this->em->createQuery('SELECT a FROM '.Account::class.' a LEFT JOIN a.group g LEFT JOIN g.memberships m
            WHERE a.archived = false AND (a.user = :me OR m.user = :me) ORDER BY a.id')
            ->setParameter('me', $me)->getResult();
    }

    /** @return list<Account> what you can pay from here: the space's accounts, and in a group also your own */
    public function sourceAccounts(Scope $s): array
    {
        return array_values(array_filter(
            $this->reachableAccounts($s->me),
            fn (Account $a) => $a->user === $s->me || ($s->group && $a->group === $s->group),
        ));
    }

    /**
     * Categories this space actually uses most (last half-year), for one-tap picking; defaults when there is no history yet.
     *
     * @return list<Category>
     */
    public function topCategories(Scope $s, string $kind, int $limit = 6): array
    {
        $rows = $this->operations($s)->select('IDENTITY(o.category) cid, COUNT(o.id) n')
            ->andWhere('o.type = :k AND o.category IS NOT NULL AND o.date >= :since')
            ->setParameter('k', $kind)->setParameter('since', new \DateTimeImmutable('-6 months'), 'date_immutable')
            ->groupBy('o.category')->orderBy('n', \SortDirection::Descending)->setMaxResults(30)->getQuery()->getArrayResult();
        $all = [];
        foreach ($this->categories($s, $kind) as $c) {
            $all[(string) $c->id] = $c;
        }
        $top = [];
        foreach ($rows as $r) {
            if (isset($all[$r['cid']])) {
                $top[$r['cid']] = $all[$r['cid']];
            }
        }
        // fill up with the remaining ones in their usual order
        $top += $all;

        return \array_slice(array_values($top), 0, $limit);
    }

    /** @return list<Category> */
    public function categories(Scope $s, ?string $kind = null, bool $archived = false): array
    {
        $qb = $this->em->createQueryBuilder()->select('c')->from(Category::class, 'c')->orderBy('c.kind')->addOrderBy('c.name');
        $s->group ? $qb->where('c.group = :o')->setParameter('o', $s->group) : $qb->where('c.user = :o')->setParameter('o', $s->me);
        if ($kind) {
            $qb->andWhere('c.kind = :k')->setParameter('k', $kind);
        }
        if (!$archived) {
            $qb->andWhere('c.archived = false');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<Account> $accounts
     *
     * @return array<string, int> account id => balance
     */
    public function balances(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $a) {
            $out[(string) $a->id] = $a->initialBalance;
        }
        if (!$out) {
            return [];
        }
        $ids = array_keys($out);
        // a shared bill paid only partly from this account moves accountAmount, not the whole amount
        $rows = $this->em->createQuery('SELECT IDENTITY(o.account) id, o.type, SUM(COALESCE(o.accountAmount, o.amount)) s FROM '.Operation::class.' o
            WHERE o.account IN (:ids) GROUP BY o.account, o.type')->setParameter('ids', $ids)->getArrayResult();
        foreach ($rows as $r) {
            $out[$r['id']] += $r['type'] === 'income' ? (int) $r['s'] : -(int) $r['s'];
        }
        $rows = $this->em->createQuery('SELECT IDENTITY(o.toAccount) id, SUM(o.toAmount) s FROM '.Operation::class.' o
            WHERE o.toAccount IN (:ids) GROUP BY o.toAccount')->setParameter('ids', $ids)->getArrayResult();
        foreach ($rows as $r) {
            $out[$r['id']] += (int) $r['s'];
        }

        return $out;
    }

    /** Operations visible in the space; personal = touching my accounts, group = created in it or touching its common accounts. */
    public function operations(Scope $s): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->select('o')->from(Operation::class, 'o')
            ->leftJoin('o.account', 'a')->leftJoin('o.toAccount', 'ta');

        return $s->group
            // entered in the group, or moving the group's common money (its accounts, personal ones shared with it)
            ? $qb->where('o.group = :g OR a.group = :g OR ta.group = :g OR :g MEMBER OF a.sharedWith OR :g MEMBER OF ta.sharedWith')->setParameter('g', $s->group)
            : $qb->where('a.user = :me OR ta.user = :me')->setParameter('me', $s->me);
    }

    /**
     * Seeing is not changing: an operation on someone else's personal account (visible because they share it,
     * or because they transferred into a common account) is theirs to edit. Split bills of the group are shared
     * records any member may correct; the payer's card stays the payer's.
     */
    public function canEdit(Scope $s, Operation $o): bool
    {
        foreach ([$o->account, $o->toAccount] as $a) {
            if ($a?->user !== null && $a->user !== $s->me) {
                return $o->createdBy === $s->me || ($s->group && $o->group === $s->group && \count($o->splits) > 0);
            }
        }

        return true;
    }

    public function visible(Scope $s, Operation $o): bool
    {
        return (bool) $this->operations($s)->select('COUNT(o.id)')->andWhere('o.id = :id')->setParameter('id', $o->id)
            ->getQuery()->getSingleScalarResult();
    }





    public const BASES = ['common' => 'Общие деньги', 'entered' => 'Внесено в группе'];

    /** A group's numbers default to its common money when it has any, otherwise to what was entered in it. */
    public function defaultBasis(Scope $s): ?string
    {
        return $s->group ? ($this->commonAccounts($s->group) ? 'common' : 'entered') : null;
    }

    public function basis(Scope $s, ?string $asked): ?string
    {
        return $s->group ? (\array_key_exists((string) $asked, self::BASES) ? $asked : $this->defaultBasis($s)) : null;
    }

    /**
     * The one definition of "income and expenses" every screen uses, so they can't disagree:
     * - personal: operations on my accounts, a shared bill counted with what left my account;
     * - group "common": operations on the group's accounts and personal ones shared with it — the same set as its
     *   balance; a debt paid back from one of these accounts to another is moving money, not spending;
     * - group "entered": everything entered inside the group, split bills whole.
     *
     * @return array{QueryBuilder, string} query (alias o, account a) and the amount expression
     */
    private function moneyQuery(Scope $s, ?string $basis): array
    {
        $qb = $this->em->createQueryBuilder()->from(Operation::class, 'o')->leftJoin('o.account', 'a')
            ->where("o.type IN ('income', 'expense')");
        if (!$s->group) {
            return [$qb->andWhere('a.user = :me')->setParameter('me', $s->me), 'COALESCE(o.accountAmount, o.amount)'];
        }
        if ($basis === 'entered') {
            return [$qb->andWhere('o.group = :g')->setParameter('g', $s->group), 'o.amount'];
        }

        return [$qb->andWhere('a.archived = false AND (a.group = :g OR :g MEMBER OF a.sharedWith)')
            // a debt paid back from one common account to another only moves money inside the group
            ->andWhere('NOT EXISTS (SELECT st.id FROM '.Settlement::class.' st JOIN st.payerOperation po JOIN po.account pa JOIN st.receiverOperation ro JOIN ro.account ra
                WHERE (st.payerOperation = o OR st.receiverOperation = o)
                AND (pa.group = :g OR :g MEMBER OF pa.sharedWith) AND (ra.group = :g OR :g MEMBER OF ra.sharedWith))')
            ->setParameter('g', $s->group), 'COALESCE(o.accountAmount, o.amount)'];
    }

    /** @return array{income: int, expense: int, missing: list<string>} for the period, in $in */
    public function totals(Scope $s, ?string $basis, \DateTimeImmutable $from, \DateTimeImmutable $to, string $in): array
    {
        [$qb, $amount] = $this->moneyQuery($s, $basis);
        $rows = $qb->select("o.date d, o.type t, o.currency cur, SUM($amount) s")
            ->andWhere('o.date >= :from AND o.date < :to')->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->groupBy('o.date, o.type, o.currency')->getQuery()->getArrayResult();
        $out = ['income' => 0, 'expense' => 0, 'missing' => []];
        foreach ($this->converted($rows, $in, $out['missing']) as [$r, $v]) {
            $out[$r['t']] += $v;
        }
        $out['missing'] = array_values(array_unique($out['missing']));

        return $out;
    }

    /**
     * Expense or income per category, each operation at the rate of its own day.
     * The same category name from another space (a group's "Кафе" on my card) joins my own "Кафе" row.
     *
     * @return array{rows: list<array{category: ?Category, amount: int, foreign: ?string}>, total: int, missing: list<string>}
     */
    public function byCategory(Scope $s, string $type, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $in = null, ?string $basis = null): array
    {
        $in ??= $s->currency();
        [$qb, $amount] = $this->moneyQuery($s, $basis ?? $this->defaultBasis($s));
        $rows = $qb->select("IDENTITY(o.category) cid, o.date d, o.currency cur, SUM($amount) s")
            ->andWhere('o.type = :t AND o.date >= :from AND o.date < :to')
            ->setParameter('t', $type)->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->groupBy('o.category, o.date, o.currency')->getQuery()->getArrayResult();
        $own = $byName = [];
        foreach ($this->categories($s, $type, archived: true) as $c) {
            $own[(string) $c->id] = $c;
            $byName[mb_strtolower($c->name)] ??= $c;
        }
        $sums = $cats = $missing = [];
        foreach ($this->converted($rows, $in, $missing) as [$r, $v]) {
            $c = $r['cid'] ? ($own[$r['cid']] ?? $this->em->find(Category::class, $r['cid'])) : null;
            if ($c && !isset($own[(string) $c->id])) {
                $c = $byName[mb_strtolower($c->name)] ?? $c;
            }
            $key = $c ? (string) $c->id : '';
            $cats[$key] = $c;
            $sums[$key] = ($sums[$key] ?? 0) + $v;
        }
        arsort($sums);
        $out = [];
        foreach ($sums as $key => $v) {
            $c = $cats[$key];
            // a category that exists only in the other space: say whose it is
            $foreign = $c && !isset($own[$key]) ? ($c->group?->name ?? 'личная') : null;
            $out[] = ['category' => $c, 'amount' => $v, 'foreign' => $foreign];
        }

        return ['rows' => $out, 'total' => array_sum($sums), 'missing' => array_values(array_unique($missing))];
    }

    /** @return array{months: array<string, array{income: int, expense: int}>, missing: list<string>} 'Y-m' => totals, oldest first */
    public function monthly(Scope $s, int $months, string $in, ?string $basis = null): array
    {
        $from = (new \DateTimeImmutable('first day of this month midnight'))->modify('-'.($months - 1).' months');
        $out = [];
        for ($i = 0; $i < $months; ++$i) {
            $out[$from->modify("+$i months")->format('Y-m')] = ['income' => 0, 'expense' => 0];
        }
        [$qb, $amount] = $this->moneyQuery($s, $basis ?? $this->defaultBasis($s));
        $rows = $qb->select("o.date d, o.type t, o.currency cur, SUM($amount) s")
            ->andWhere('o.date >= :from AND o.date < :to')->setParameter('from', $from, 'date_immutable')
            ->setParameter('to', new \DateTimeImmutable('first day of next month midnight'), 'date_immutable')
            ->groupBy('o.date, o.type, o.currency')->getQuery()->getArrayResult();
        $missing = [];
        foreach ($this->converted($rows, $in, $missing) as [$r, $v]) {
            $out[$r['d']->format('Y-m')][$r['t']] += $v;
        }

        return ['months' => $out, 'missing' => array_values(array_unique($missing))];
    }

    /**
     * Money moved in or out of a set of accounts by transfers (top-ups of the family pot, cash withdrawn to a card that isn't shared):
     * not income or expense, but it changes the balance — shown so balance = before + income − expense ± transfers.
     *
     * @param list<Account> $accounts
     *
     * @return array{in: int, out: int}
     */
    public function transfers(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to, string $in): array
    {
        $out = ['in' => 0, 'out' => 0];
        if (!$accounts) {
            return $out;
        }
        $ids = array_map(fn (Account $a) => (string) $a->id, $accounts);
        $missing = [];
        foreach (['in' => 'o.toAccount IN (:ids) AND (o.account IS NULL OR o.account NOT IN (:ids))', 'out' => 'o.account IN (:ids) AND o.toAccount NOT IN (:ids)'] as $dir => $where) {
            $field = $dir === 'in' ? 'o.toAmount' : 'o.amount';
            $cur = $dir === 'in' ? 'ta.currency' : 'o.currency';
            $rows = $this->em->createQuery("SELECT o.date d, $cur cur, SUM($field) s FROM ".Operation::class." o JOIN o.toAccount ta
                WHERE o.type = 'transfer' AND $where AND o.date >= :from AND o.date < :to GROUP BY o.date, $cur")
                ->setParameter('ids', $ids)->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')->getArrayResult();
            foreach ($this->converted($rows, $in, $missing) as [, $v]) {
                $out[$dir] += $v;
            }
        }

        return $out;
    }

    /**
     * Rows of (date d, currency cur, sum s) converted into $in at their day's rate; unconvertible currencies go to $missing.
     *
     * @return iterable<array{array, int}>
     */
    private function converted(array $rows, string $in, array &$missing): iterable
    {
        $this->rates->prefetch(array_column(array_filter($rows, fn ($r) => $r['cur'] !== $in), 'd'));
        foreach ($rows as $r) {
            $v = $this->rates->convert((int) $r['s'], $r['cur'], $in, $r['d']);
            if ($v === null) {
                $missing[] = $r['cur'];
                continue;
            }
            yield [$r, $v];
        }
    }

    /**
     * Who owes whom in a group, per currency: paid − owed share, adjusted by settlements. Positive net = the others owe this member.
     *
     * @return array<string, array{net: array<string, int>, plan: list<array{from: string, to: string, amount: int}>}>
     */
    public function debts(Group $g): array
    {
        $net = [];
        $add = function (string $cur, string $user, int $v) use (&$net): void {
            $net[$cur][$user] = ($net[$cur][$user] ?? 0) + $v;
        };
        $q = fn (string $dql) => $this->em->createQuery($dql)->setParameter('g', $g)->getArrayResult();
        foreach ($q('SELECT IDENTITY(sp.user) u, o.currency c, SUM(sp.paid) - SUM(sp.amount) s FROM '.Split::class.' sp JOIN sp.operation o
            WHERE o.group = :g GROUP BY sp.user, o.currency') as $r) {
            $add($r['c'], $r['u'], (int) $r['s']);
        }
        foreach ($q('SELECT IDENTITY(st.from) f, IDENTITY(st.to) t, st.currency c, SUM(st.amount) s FROM '.Settlement::class.' st
            WHERE st.group = :g GROUP BY st.from, st.to, st.currency') as $r) {
            $add($r['c'], $r['f'], (int) $r['s']);
            $add($r['c'], $r['t'], -(int) $r['s']);
        }
        $out = [];
        foreach ($net as $cur => $n) {
            $out[$cur] = ['net' => $n, 'plan' => Debts::simplify($n)];
        }

        return $out;
    }

    /** Called whenever a space is opened: default categories on first visit, then due recurring operations in all my spaces. */
    public function open(Scope $s): void
    {
        if (!$this->categories($s, archived: true)) {
            foreach (Category::defaults($s) as $c) {
                $this->em->persist($c);
            }
            $this->em->flush();
        }
        // due payments everywhere I take part: a personal salary shows up in the group's common money too
        $this->runRecurring(new Scope($s->me));
        foreach ($this->groupsOf($s->me) as $g) {
            $this->runRecurring(new Scope($s->me, $g));
        }
    }

    /**
     * Everyone who ever took part in the group's money (current members first): people who left keep their debts.
     *
     * @return array<string, User> id => user
     */
    public function participants(Group $g): array
    {
        $out = [];
        foreach ($g->members() as $u) {
            $out[(string) $u->id] = $u;
        }
        $ids = array_merge(
            array_column($this->em->createQuery('SELECT IDENTITY(sp.user) u FROM '.Split::class.' sp JOIN sp.operation o WHERE o.group = :g')->setParameter('g', $g)->getArrayResult(), 'u'),
            array_column($this->em->createQuery('SELECT IDENTITY(st.from) u FROM '.Settlement::class.' st WHERE st.group = :g')->setParameter('g', $g)->getArrayResult(), 'u'),
            array_column($this->em->createQuery('SELECT IDENTITY(st.to) u FROM '.Settlement::class.' st WHERE st.group = :g')->setParameter('g', $g)->getArrayResult(), 'u'),
        );
        foreach (array_unique($ids) as $id) {
            $out[$id] ??= $this->em->find(User::class, $id);
        }

        return array_filter($out);
    }

    /** Creates operations for every due date of the space's recurring templates, up to today. */
    public function runRecurring(Scope $s): void
    {
        $qb = $this->em->createQueryBuilder()->select('r')->from(Recurring::class, 'r')->join('r.account', 'ra')
            // an archived account gets no new money: its templates wait until it's restored
            ->where('r.active = true AND r.nextDate <= :today AND ra.archived = false')->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable');
        $s->group ? $qb->andWhere('r.group = :o')->setParameter('o', $s->group) : $qb->andWhere('r.user = :o')->setParameter('o', $s->me);
        foreach ($qb->getQuery()->getResult() as $r) {
            $this->em->wrapInTransaction(function () use ($r): void {
                // two tabs opening the page at once must not create the same operation twice
                $this->em->refresh($r, LockMode::PESSIMISTIC_WRITE);
                for ($i = 0; $r->active && $r->nextDate <= new \DateTimeImmutable('today') && $i < 1000; ++$i) {
                    $o = new Operation();
                    $o->type = $r->type;
                    $o->date = $r->nextDate;
                    $o->account = $r->account;
                    $o->amount = $r->amount;
                    $o->currency = $r->account->currency;
                    if ($r->origCurrency !== null) {
                        $converted = $this->rates->convert((int) $r->origAmount, $r->origCurrency, $o->currency, $r->nextDate);
                        if (!$converted) {
                            // no rate for that day yet: try again on the next visit rather than book 0
                            break;
                        }
                        $o->origAmount = $r->origAmount;
                        $o->origCurrency = $r->origCurrency;
                        $o->amount = $converted;
                    }
                    $o->toAccount = $r->toAccount;
                    $o->toAmount = $r->toAmount;
                    $o->category = $r->category;
                    $o->note = $r->note;
                    $o->group = $r->group;
                    $o->createdBy = $r->createdBy;
                    $this->em->persist($o);
                    $r->advance();
                }
            });
        }
    }
}
