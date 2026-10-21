# Разработка

## Стек и запуск

PHP 8.3, Symfony 7.4, Doctrine ORM 3, PostgreSQL 16, Twig. Без JS-сборки: `public/app.css`, `public/app.js`
(только улучшения — все формы работают без JS). Шрифты Inter и Manrope (OFL) — в `public/fonts`.
Цвета и отступы — CSS-переменные в начале `app.css`; тёмная тема — `prefers-color-scheme` или `html[data-theme]`.

```bash
docker compose -f compose.dev.yaml up -d --build    # код смонтирован, Postgres на 127.0.0.1:54321
docker compose -f compose.dev.yaml exec app bin/console doctrine:migrations:migrate -n
php bin/phpunit                     # unit-тесты расчётов, без БД
```

Схема БД меняется только миграциями: поправить сущность → `bin/console doctrine:migrations:diff` → проверить файл → `migrate`.

## Структура

```
src/Entity/            общее для хаба: User, Group, Membership
src/Controller/        вход/регистрация (email и Telegram), профиль, главная-хаб, группы и приглашения
src/TelegramAuth.php   проверка подписи Telegram Login Widget
src/Repository/        UserRepository: загрузка пользователя по email или «tg:<id>»
src/Money/             инструмент «Деньги»
  Entity/              Account, Category, Operation, Split, Settlement, Budget, Goal, Recurring (+ трейт Scoped)
  Controller/          Dashboard, Account, Operation (+экспорт), Category, Plan, Debt, Import, Report
  Scope.php            пространство: «Личное» или группа
  ScopeResolver.php    {scope} из URL → Scope (проверка членства, 404); при открытии — категории по умолчанию и регулярные
  Ledger.php           запросы: счета, балансы, операции пространства, отчёты, долги, регулярные
  OperationForm.php    разбор формы операции/регулярной в сущность (валидация, доступные счета, деление)
  Money.php            суммы: ввод/вывод, минимальные единицы, список валют
  Rates.php            курсы: сегодня — ЦБ + CoinGecko; прошлые дни — снимки/архив ЦБ, хранятся в money_rate_day
  Debts.php            деление поровну, упрощение долгов
  Schedule.php         даты регулярных операций
  Csv.php              чтение банковских CSV, угадывание колонок, даты
src/Twig/AppExtension  фильтры money, money_input; функции my_groups, currencies
templates/             hub, auth, groups, money/*
tests/MoneyLogicTest   unit-тесты Money, Debts, Schedule, Csv
```

## Модель данных

- **Деньги** — `bigint` в минимальных единицах валюты (копейки). `Money::parse` / `Money::format` / `Money::toInput`.
- **Scoped** (Account, Category, Budget, Goal, Recurring): ровно одно из `user` (личное) или `group`.
- **Operation**: `type` (expense / income / transfer), `amount > 0` в валюте `account`, `currency` (копия валюты счёта
  или своя, если счёта нет), `toAccount` + `toAmount` для перевода, `category`, `group` — если создана в группе,
  `paidBy` + `splits` — для разделённого расхода, `importHash` — защита от повторного импорта.
- **Баланс счёта** = `initialBalance` + доходы − расходы − исходящие переводы + `toAmount` входящих (`Ledger::balances`).
- **Видимость операции** (`Ledger::operations`): в личном — затрагивает мой счёт; в группе — создана в группе
  или затрагивает счёт группы.
- **Долги группы** (`Ledger::debts`): по каждой валюте `net = заплатил (paidBy) − доли (splits) + отдал (Settlement.from) − получил (Settlement.to)`.
- **Пересчёт валют** (`Rates::convert(..., $on)`): в отчётах — по курсу дня операции, курсы дней грузятся пачкой
  (`prefetch`, параллельные HTTP) и сохраняются в `money_rate_day`. Остатки — по сегодняшнему курсу.
- **Регулярные**: `startDate` + `count` → следующая дата через `Schedule::occurrence` (без накопления сдвига 31-го числа).
  Создание — в транзакции с `SELECT … FOR UPDATE` по шаблону, чтобы две вкладки не задвоили операции.

## Безопасность

- Telegram: `TELEGRAM_BOT_TOKEN` и `TELEGRAM_BOT_USERNAME` — только в `.env.local`; без них кнопка не показывается.
  Подпись — HMAC-SHA256 от отсортированных полей с ключом sha256(токен), срок — сутки.

- Все POST-формы с `csrf_token('form')` (`BaseController::checkCsrf`), вход — с CSRF и лимитом попыток (5/мин).
- Любой id из URL/формы проверяется на принадлежность пространству (`Scope::owns`, `Ledger::visible`, выборки
  счетов/категорий из пространства), иначе 404.
- Группы: управлять участниками, ссылкой и настройками может только владелец.

## Новый инструмент

1. Код в `src/<Tool>/` (Entity, Controller), шаблоны в `templates/<tool>/`, маршруты `/<tool>/...`.
2. Маппинг Doctrine для `src/<Tool>/Entity` в `config/packages/doctrine.yaml`.
3. Плитка в `templates/hub.html.twig`.
4. Общие люди и группы — `App\Entity\User`, `Group`, `Membership`.
5. Обновить ТЗ, PROGRESS.md, эту документацию.
