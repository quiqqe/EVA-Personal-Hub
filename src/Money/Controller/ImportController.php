<?php

declare(strict_types=1);

namespace App\Money\Controller;

use App\Controller\BaseController;
use App\Money\Csv;
use App\Money\Entity\Account;
use App\Money\Entity\Operation;
use App\Money\Ledger;
use App\Money\Money;
use App\Money\Rates;
use App\Money\Scope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Bank statement CSV → operations (ТЗ §10). */
#[Route('/money/{scope}/import', requirements: ['scope' => 'me|[0-9a-f-]{36}'])]
final class ImportController extends BaseController
{
    private const MAX_ROWS = 5000;

    /** Bank category (lowercase) → name of a default category */
    private const BANK_CATEGORIES = [
        'супермаркеты' => 'Продукты', 'продукты' => 'Продукты',
        'рестораны' => 'Кафе и рестораны', 'фастфуд' => 'Кафе и рестораны', 'кафе' => 'Кафе и рестораны',
        'такси' => 'Транспорт', 'транспорт' => 'Транспорт', 'местный транспорт' => 'Транспорт', 'топливо' => 'Авто', 'автоуслуги' => 'Авто',
        'аптеки' => 'Здоровье', 'медицина' => 'Здоровье', 'красота' => 'Здоровье',
        'одежда и обувь' => 'Одежда', 'связь' => 'Связь и интернет', 'мобильная связь' => 'Связь и интернет',
        'коммунальные услуги' => 'Жильё и ЖКХ', 'жкх' => 'Жильё и ЖКХ', 'дом и ремонт' => 'Жильё и ЖКХ',
        'кино' => 'Развлечения', 'развлечения' => 'Развлечения', 'животные' => 'Питомцы', 'образование' => 'Образование',
        'авиабилеты' => 'Путешествия', 'отели' => 'Путешествия', 'ж/д билеты' => 'Путешествия', 'цифровые товары' => 'Подписки',
        'проценты' => 'Проценты и кэшбэк', 'кэшбэк' => 'Проценты и кэшбэк', 'зарплата' => 'Зарплата',
    ];

    public function __construct(private readonly EntityManagerInterface $em, private readonly Ledger $ledger, private readonly Rates $rates)
    {
    }

    #[Route('', name: 'money_import')]
    public function upload(Request $r, Scope $scope): Response
    {
        if ($r->isMethod('POST')) {
            $this->checkCsrf($r);
            $file = $r->files->get('file');
            $content = $file?->isValid() ? (string) file_get_contents($file->getPathname()) : '';
            $rows = Csv::rows($content, self::MAX_ROWS + 2);
            if (\count($rows) < 2) {
                $this->addFlash('error', 'Не удалось прочитать файл: нужен CSV с заголовком и хотя бы одной строкой. Excel-файл сохраните как CSV («Файл → Сохранить как → CSV»).');
            } elseif (\count($rows) > self::MAX_ROWS + 1) {
                $this->addFlash('error', 'Слишком большой файл: максимум '.self::MAX_ROWS.' строк — разбейте выписку по периодам');
            } else {
                // the file waits on disk, not in the session
                $name = bin2hex(random_bytes(16));
                @mkdir($this->dir(), 0o700, true);
                file_put_contents($this->dir().'/'.$name, $content);
                $r->getSession()->set('import', ['scope' => $scope->key(), 'account' => (string) $r->request->get('account'), 'file' => $name]);

                return $this->redirectToRoute('money_import_map', ['scope' => $scope->key()]);
            }
        }

        return $this->render('money/import.html.twig', ['scope' => $scope, 'accounts' => $this->ledger->accounts($scope)]);
    }

    #[Route('/map', name: 'money_import_map')]
    public function map(Request $r, Scope $scope): Response
    {
        $data = $r->getSession()->get('import');
        $path = isset($data['file']) && preg_match('/^[0-9a-f]{32}$/', $data['file']) ? $this->dir().'/'.$data['file'] : null;
        if (($data['scope'] ?? null) !== $scope->key() || !$path || !is_file($path)) {
            return $this->redirectToRoute('money_import', ['scope' => $scope->key()]);
        }
        $accounts = $this->ledger->accounts($scope);
        $account = null;
        foreach ($accounts as $a) {
            if ((string) $a->id === (string) $r->request->get('account', $data['account'])) {
                $account = $a;
            }
        }
        $rows = Csv::rows((string) file_get_contents($path), self::MAX_ROWS + 1);
        $cols = Csv::guess($rows[0]);
        if ($r->isMethod('POST')) {
            foreach ($cols as $k => $_) {
                $v = (string) $r->request->get($k);
                $cols[$k] = $v === '' ? null : (int) $v;
            }
        }
        $invert = $r->request->getBoolean('invert');
        $parsed = $account ? $this->parse(\array_slice($rows, 1), $cols, $account, $invert, $scope) : [];
        // which rows to take (all on first look) and which are transfers to another of my accounts
        $mapped = $r->request->getBoolean('mapped');
        $keep = $mapped ? array_flip(array_map('intval', $r->request->all('keep'))) : null;
        $asTransfer = array_flip(array_map('intval', $r->request->all('transfer')));
        $transferTo = null;
        foreach ($this->ledger->sourceAccounts($scope) as $a) {
            if ((string) $a->id === (string) $r->request->get('transfer_account') && $a !== $account) {
                $transferTo = $a;
            }
        }

        if ($r->isMethod('POST') && $r->request->has('confirm') && $account) {
            $this->checkCsrf($r);
            $chosen = [];
            foreach ($parsed as $i => $o) {
                if ($keep !== null && !isset($keep[$i])) {
                    continue;
                }
                if (isset($asTransfer[$i])) {
                    if (!$transferTo || !$this->asTransfer($o, $transferTo)) {
                        $this->addFlash('error', 'Для строк-переводов выберите второй счёт в той же валюте или с известным курсом');

                        return $this->render('money/import_map.html.twig', $this->view($scope, $accounts, $account, $rows, $cols, $invert, $parsed, $keep, $asTransfer, $transferTo));
                    }
                }
                $chosen[] = $o;
            }
            $batch = bin2hex(random_bytes(8));
            [$added, $dupes] = $this->save($chosen, $account, $scope, $batch);
            $r->getSession()->remove('import');
            @unlink($path);
            $this->addFlash('ok', "Импортировано: $added".($dupes ? ", пропущено повторов: $dupes" : ''));
            if ($added) {
                $r->getSession()->set('import_undo', ['scope' => $scope->key(), 'batch' => $batch, 'count' => $added]);
            }

            return $this->redirectToRoute('money_operations', ['scope' => $scope->key(), 'account' => (string) $account->id]);
        }

        return $this->render('money/import_map.html.twig', $this->view($scope, $accounts, $account, $rows, $cols, $invert, $parsed, $keep, $asTransfer, $transferTo));
    }

    /** Changed your mind right after importing: everything from that file goes away at once. */
    #[Route('/undo/{batch}', name: 'money_import_undo', methods: ['POST'], requirements: ['batch' => '[0-9a-f]{16}'])]
    public function undo(Request $r, Scope $scope, string $batch): Response
    {
        $this->checkCsrf($r);
        $ops = $this->em->getRepository(Operation::class)->findBy(['importBatch' => $batch, 'createdBy' => $scope->me]);
        foreach ($ops as $o) {
            if ($this->ledger->visible($scope, $o)) {
                $this->em->remove($o);
            }
        }
        $this->em->flush();
        $r->getSession()->remove('import_undo');
        $this->addFlash('ok', 'Импорт отменён: удалено операций — '.\count($ops));

        return $this->redirectToRoute('money_import', ['scope' => $scope->key()]);
    }

    private function view(Scope $scope, array $accounts, ?Account $account, array $rows, array $cols, bool $invert, array $parsed, ?array $keep, array $asTransfer, ?Account $transferTo): array
    {
        return [
            'scope' => $scope,
            'accounts' => $accounts,
            'account' => $account,
            'others' => array_values(array_filter($this->ledger->sourceAccounts($scope), fn (Account $a) => $a !== $account)),
            'header' => $rows[0],
            'sample' => \array_slice($rows, 1, 5),
            'cols' => $cols,
            'invert' => $invert,
            'parsed' => $parsed,
            'keep' => $keep,
            'asTransfer' => $asTransfer,
            'transferTo' => $transferTo,
            'skipped' => \count($rows) - 1 - \count($parsed),
        ];
    }

    /** A row that is really money moved between my own accounts: the other side is that account, not income or expense. */
    private function asTransfer(Operation $o, Account $other): bool
    {
        $leaving = $o->type === 'expense';
        [$from, $to] = $leaving ? [$o->account, $other] : [$other, $o->account];
        $amount = $o->amount;
        $credited = $from->currency === $to->currency ? $amount : $this->rates->convert($amount, $from->currency, $to->currency, $o->date);
        if (!$credited) {
            return false;
        }
        $o->type = 'transfer';
        $o->account = $from;
        $o->currency = $from->currency;
        $o->toAccount = $to;
        // amounts as the statement saw them: on the imported account's side
        [$o->amount, $o->toAmount] = $leaving ? [$amount, $credited] : [$this->rates->convert($amount, $to->currency, $from->currency, $o->date) ?? $amount, $amount];
        $o->category = null;
        $o->origAmount = $o->origCurrency = null;

        return true;
    }

    private function dir(): string
    {
        return $this->getParameter('kernel.project_dir').'/var/import';
    }

    /**
     * @param list<list<string>>                $rows
     * @param array<string, int|null>           $cols
     *
     * @return array<int, Operation> row index => operation, not persisted yet
     */
    private function parse(array $rows, array $cols, Account $account, bool $invert, Scope $scope): array
    {
        if ($cols['date'] === null || ($cols['amount'] === null && $cols['out'] === null && $cols['in'] === null)) {
            return [];
        }
        $byName = $byNote = [];
        foreach ($this->ledger->categories($scope) as $c) {
            $byName[$c->kind][mb_strtolower($c->name)] = $c;
        }
        // categories you gave the same description before, newest wins — only this space's own categories
        $own = array_flip(array_map(fn ($c) => (string) $c->id, $this->ledger->categories($scope)));
        foreach ($this->ledger->operations($scope)->andWhere('o.category IS NOT NULL')->orderBy('o.date')->setMaxResults(5000)->getQuery()->getResult() as $o) {
            if (isset($own[(string) $o->category->id])) {
                $byNote[$o->type][mb_strtolower($o->note)] = $o->category;
            }
        }
        $seen = [];
        $out = [];
        foreach ($rows as $i => $row) {
            $date = Csv::date($row[$cols['date']] ?? '');
            if ($cols['amount'] !== null) {
                $amount = Money::parse($row[$cols['amount']] ?? '', $account->currency);
            } else {
                // separate "debit" / "credit" columns
                $amount = (Money::parse($row[$cols['in']] ?? '', $account->currency) ?? 0) - abs(Money::parse($row[$cols['out']] ?? '', $account->currency) ?? 0);
            }
            if (!$date || !$amount) {
                continue;
            }
            $amount = $invert ? -$amount : $amount;
            $o = new Operation();
            $o->type = $amount < 0 ? 'expense' : 'income';
            $o->date = $date;
            $o->account = $account;
            $o->currency = $account->currency;
            $o->amount = abs($amount);
            $o->note = mb_substr($cols['note'] !== null ? ($row[$cols['note']] ?? '') : '', 0, 255);
            $o->group = $scope->group;
            $this->foreign($o, $row, $cols, $invert);
            if ($o->amount <= 0) {
                continue;
            }
            $bankCategory = $cols['category'] !== null ? mb_strtolower($row[$cols['category']] ?? '') : '';
            $o->category = $byNote[$o->type][mb_strtolower($o->note)]
                ?? $byName[$o->type][$bankCategory]
                ?? $byName[$o->type][mb_strtolower(self::BANK_CATEGORIES[$bankCategory] ?? '')]
                ?? null;
            // identical purchases on the same day stay separate, re-importing the file adds nothing
            $key = implode('|', [$account->id, $date->format('Y-m-d'), $amount, $o->note]);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $o->importHash = hash('sha256', $key.'|'.$seen[$key]);
            $out[$i] = $o;
        }

        return $out;
    }

    /**
     * A purchase in another currency than the account's: keep its own sum; when the statement has only that sum,
     * the account's sum is converted at the rate of the day.
     *
     * @param list<string>            $row
     * @param array<string, int|null> $cols
     */
    private function foreign(Operation $o, array $row, array $cols, bool $invert): void
    {
        $cur = strtoupper(trim($cols['orig_currency'] !== null ? ($row[$cols['orig_currency']] ?? '') : ''));
        $cur = $cur === 'RUR' ? 'RUB' : $cur;   // banks still write the old code
        if ($cur === '' || $cur === $o->currency || !isset(Money::choices()[$cur])) {
            return;
        }
        if ($cols['orig_amount'] !== null) {
            $orig = Money::parse($row[$cols['orig_amount']] ?? '', $cur);
        } else {
            // the only sum column is in the purchase's currency
            $orig = Money::parse($row[$cols['amount']] ?? '', $cur);
            $converted = $orig !== null ? $this->rates->convert(abs($orig), $cur, $o->currency, $o->date) : null;
            if (!$converted) {
                // no rate for that day: better skip the row (it's counted as skipped) than book USD as AMD
                $o->amount = 0;

                return;
            }
            $o->amount = $converted;
        }
        if ($orig) {
            $o->origAmount = abs($orig);
            $o->origCurrency = $cur;
        }
    }

    /**
     * @param list<Operation> $ops
     *
     * @return array{int, int} added, duplicates
     */
    private function save(array $ops, Account $account, Scope $scope, string $batch): array
    {
        $existing = array_flip(array_column($this->em->createQuery('SELECT o.importHash h FROM '.Operation::class.' o WHERE o.account = :a AND o.importHash IS NOT NULL')
            ->setParameter('a', $account)->getArrayResult(), 'h'));
        $added = 0;
        foreach ($ops as $o) {
            if (isset($existing[$o->importHash])) {
                continue;
            }
            $o->createdBy = $scope->me;
            $o->importBatch = $batch;
            $this->em->persist($o);
            ++$added;
        }
        $this->em->flush();

        return [$added, \count($ops) - $added];
    }
}
