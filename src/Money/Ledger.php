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

    /**
     * Income and expenses on exactly these accounts (so they add up with the accounts' balances), in $in.
     *
     * @param list<Account> $accounts
     *
     * @return array{income: int, expense: int}
     */
    public function flows(array $accounts, \DateTimeImmutable $from, \DateTimeImmutable $to, string $in): array
    {
        $out = ['income' => 0, 'expense' => 0];
        if (!$accounts) {
            return $out;
        }
        $rows = $this->em->createQuery('SELECT o.date d, o.type t, o.currency cur, SUM(COALESCE(o.accountAmount, o.amount)) s FROM '.Operation::class.' o
            WHERE o.account IN (:ids) AND o.type IN (\'income\', \'expense\') AND o.date >= :from AND o.date < :to
            GROUP BY o.date, o.type, o.currency')
            ->setParameter('ids', array_map(fn (Account $a) => (string) $a->id, $accounts))
            ->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')->getArrayResult();
        $this->prefetchRates($rows, $in);
        foreach ($rows as $r) {
            $out[$r['t']] += $this->rates->convert((int) $r['s'], $r['cur'], $in, $r['d']) ?? 0;
        }

        return $out;
    }

    /**
     * What was entered inside the group (any account, split bills included): how much the group spent together.
     *
     * @return array{income: int, expense: int}
     */
    public function enteredInGroup(Group $g, \DateTimeImmutable $from, \DateTimeImmutable $to, string $in): array
    {
        $out = ['income' => 0, 'expense' => 0];
        $rows = $this->em->createQuery('SELECT o.date d, o.type t, o.currency cur, SUM(o.amount) s FROM '.Operation::class.' o
            WHERE o.group = :g AND o.type IN (\'income\', \'expense\') AND o.date >= :from AND o.date < :to
            GROUP BY o.date, o.type, o.currency')
            ->setParameter('g', $g)->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')->getArrayResult();
        $this->prefetchRates($rows, $in);
        foreach ($rows as $r) {
            $out[$r['t']] += $this->rates->convert((int) $r['s'], $r['cur'], $in, $r['d']) ?? 0;
        }

        return $out;
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

    public function visible(Scope $s, Operation $o): bool
    {
        return (bool) $this->operations($s)->select('COUNT(o.id)')->andWhere('o.id = :id')->setParameter('id', $o->id)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Expense or income per category for a period, converted into $in (the space's currency by default).
     *
     * @return array{rows: list<array{category: ?Category, amount: int}>, total: int, missing: list<string>}
     */
    public function byCategory(Scope $s, string $type, \DateTimeImmutable $from, \DateTimeImmutable $to, ?string $in = null): array
    {
        $in ??= $s->currency();
        $rows = $this->operations($s)->select('IDENTITY(o.category) cid, o.date d, o.currency cur, SUM('.$this->amountField($s).') s')
            ->andWhere('o.type = :t AND o.date >= :from AND o.date < :to')
            ->setParameter('t', $type)->setParameter('from', $from, 'date_immutable')->setParameter('to', $to, 'date_immutable')
            ->groupBy('o.category, o.date, o.currency')->getQuery()->getArrayResult();
        $this->prefetchRates($rows, $in);
        $sums = $missing = [];
        foreach ($rows as $r) {
            // each operation at the rate of its own day
            $v = $this->rates->convert((int) $r['s'], $r['cur'], $in, $r['d']);
            $v === null ? $missing[] = $r['cur'] : $sums[$r['cid'] ?? ''] = ($sums[$r['cid'] ?? ''] ?? 0) + $v;
        }
        arsort($sums);
        $out = [];
        foreach ($sums as $cid => $v) {
            $out[] = ['category' => $cid === '' ? null : $this->em->find(Category::class, $cid), 'amount' => $v];
        }

        return ['rows' => $out, 'total' => array_sum($sums), 'missing' => array_values(array_unique($missing))];
    }

    /** @return array<string, array{income: int, expense: int}> 'Y-m' => totals in $in, oldest first */
    public function monthly(Scope $s, int $months, string $in): array
    {
        $from = (new \DateTimeImmutable('first day of this month midnight'))->modify('-'.($months - 1).' months');
        $out = [];
        for ($i = 0; $i < $months; ++$i) {
            $out[$from->modify("+$i months")->format('Y-m')] = ['income' => 0, 'expense' => 0];
        }
        $rows = $this->operations($s)->select('o.date d, o.type t, o.currency cur, SUM('.$this->amountField($s).') s')
            ->andWhere("o.type IN ('income', 'expense') AND o.date >= :from")->setParameter('from', $from, 'date_immutable')
            ->groupBy('o.date, o.type, o.currency')->getQuery()->getArrayResult();
        $this->prefetchRates($rows, $in);
        foreach ($rows as $r) {
            $out[$r['d']->format('Y-m')][$r['t']] += $this->rates->convert((int) $r['s'], $r['cur'], $in, $r['d']) ?? 0;
        }

        return $out;
    }

    /** In my personal space a shared bill counts with what left my account; the group counts the whole bill. */
    private function amountField(Scope $s): string
    {
        return $s->group ? 'o.amount' : 'COALESCE(o.accountAmount, o.amount)';
    }

    /** @param list<array{d: \DateTimeImmutable, cur: string}> $rows */
    private function prefetchRates(array $rows, string $in): void
    {
        $this->rates->prefetch(array_column(array_filter($rows, fn ($r) => $r['cur'] !== $in), 'd'));
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

    /** Called whenever a space is opened: default categories on first visit, then due recurring operations. */
    public function open(Scope $s): void
    {
        if (!$this->categories($s, archived: true)) {
            foreach (Category::defaults($s) as $c) {
                $this->em->persist($c);
            }
            $this->em->flush();
        }
        $this->runRecurring($s);
    }

    /** Creates operations for every due date of the space's recurring templates, up to today. */
    public function runRecurring(Scope $s): void
    {
        $qb = $this->em->createQueryBuilder()->select('r')->from(Recurring::class, 'r')
            ->where('r.active = true AND r.nextDate <= :today')->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable');
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
                        $o->origAmount = $r->origAmount;
                        $o->origCurrency = $r->origCurrency;
                        $o->amount = $this->rates->convert((int) $r->origAmount, $r->origCurrency, $o->currency, $r->nextDate) ?? $r->amount;
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
