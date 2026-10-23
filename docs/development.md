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
src/Telegram.php       бот: вызовы Bot API, разбор входящих обновлений
src/Entity/TelegramLogin  попытка входа/привязки через бота (код, кто нажал «Старт», подтверждено ли)
src/Command/           app:telegram:webhook — регистрирует webhook и кнопку меню Mini App (запускается из bin/deploy)
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

- Telegram: `TELEGRAM_BOT_TOKEN` и `TELEGRAM_BOT_USERNAME` — только в `.env.local`; без них кнопки не показываются.
  Вход: `/login/telegram` создаёт код (в сессии браузера) → бот по webhook получает `/start <код>`, спрашивает
  подтверждение → `callback_query` от того же Telegram-аккаунта ставит `confirmedAt` → браузер опрашивает
  `/login/telegram/check` и входит. Webhook `/telegram/webhook` принимает только запросы с заголовком
  `X-Telegram-Bot-Api-Secret-Token` = HMAC(токен бота) — отдельного секрета не нужно.
- Mini App: кнопка меню бота открывает `/tg` (тот же сайт). Telegram кладёт подписанные данные в `#tgWebAppData=…`,
  `app.js` отправляет их на `/login/telegram/webapp`, `Telegram::verifyWebApp` проверяет HMAC (ключ — HMAC токена с
  "WebAppData", не старше суток) и входит. Флаг `tgApp` в sessionStorage подгружает `telegram-web-app.js`
  (на весь экран, без закрытия свайпом). В BotFather ничего настраивать не нужно.
- Иконки интерфейса — SVG-спрайт `public/icons.svg` (контурные, Lucide), в шаблонах `{{ icon('wallet') }}`
  (`AppExtension::icon`), цвет — `currentColor`, размер — CSS `.i`. Новая иконка: `<symbol id="…">` в спрайт и поднять
  `?v=` в `AppExtension::icon` и `app.js`. Эмодзи остаются только у категорий (их выбирает пользователь) и в сообщениях бота.
  Иконка типа счёта — `Account::ICONS`.
- `branding/` — логотип и картинки для Telegram: `logo.svg` (= `public/icon.svg`), `logo-mark.svg` (только «E»),
  `bot-avatar.svg/png` 640×640 (аватарка бота, Telegram обрезает кругом), `miniapp-cover.svg/png` 640×360 (BotFather `/newapp`).
  PNG отрисованы из SVG в Chromium со шрифтом Manrope; в образ папка не попадает.
- **Роли.** `ROLE_USER` у всех. `User::$role`: `admin` → `ROLE_ADMIN` (админка, ошибки, журнал безопасности, уведомления
  в Telegram); `super_admin` → `ROLE_SUPER_ADMIN` (то же + раздаёт роли в «Пользователях»; `role_hierarchy`).
  Первого суперадмина — только из консоли: `bin/console app:admin you@mail --super` (`tg:<id>`, `name:<имя>`;
  `--revoke` — снять; без аргументов — список). Свою роль в интерфейсе менять нельзя. Новая роль — после перевхода.
  В группах свои роли `owner` / `member` (`Membership::$role`).
- **Админка** `/admin`: «Обзор» (за сутки: ошибки, неудачные входы, входы, события сервера; состояние; тестовые
  уведомление и ошибка), «Ошибки» (`app_error`: одинаковые — одна строка со счётчиком, деталь со стеком, «решена» —
  откроется сама при повторе, 90 дней), «Безопасность» (журнал всех: фильтр по типу, поиск по IP/email/имени, страницы),
  «Пользователи» (последний вход, неудачные попытки, роли).
- **Мониторинг.** Ошибки приложения (кроме 4xx) — `App\Monitoring\TelegramAlertHandler` в `monolog.yaml` (prod и dev):
  пишет в `app_error` и шлёт через `App\Monitoring\Alerts` — в чат `TELEGRAM_ADMIN_CHAT_ID`, если задан, иначе каждому
  admin/super_admin с привязанным Telegram; одна и та же ошибка — не чаще раза в 10 минут, со ссылкой на неё в админке.
  `bin/console app:server-event "текст" [--ip=…] [--alert]` — событие сервера в журнал безопасности (и в Telegram).
  id чата: добавить бота в группу (или написать ему) и отправить `/chatid`. `bin/console app:alert "текст"`
  (или `-` — из stdin) шлёт сообщение туда же — им пользуются серверные скрипты, чтобы токен бота был только в приложении.
  Логи контейнеров ротируются (`compose.prod.yaml`, `x-logging`: 10 МБ × 5).
- **Подробности ошибок** — свой Bugsink (совместим с Sentry SDK) на нашем сервере, в общей Docker-сети `eva-monitoring`
  (её создаёт `bin/deploy`, приложение в ней видит Bugsink как `bugsink:8000`): `sentry/sentry-symfony`, только prod,
  `config/packages/sentry.yaml`. `SENTRY_DSN` пустой — выключено. Личные данные не отправляются (`send_default_pii: false`),
  404/405/403 игнорируются. Проверка: `bin/console sentry:test`. Telegram говорит «что-то сломалось», Bugsink — где и почему.
- **Журнал безопасности** — таблица `security_event` (`App\Security\SecurityLog::record`, хранится 30 дней, чистится
  сама на ~1% записей): входы (`RecordSignIns`, кроме автологина «запомнить меня»), неудачные попытки (с email и аккаунтом,
  если он есть), превышение лимитов, выход, регистрация, сброс и смена пароля, привязка/отвязка Telegram.
  Пишется отдельным INSERT, не через flush, и никогда не ломает запрос. Пользователь видит свои события в профиле.
  `bin/console app:security:digest [--hours=24]` — текстовая сводка для ежедневного отчёта сервера.
- Лимиты попыток — `config/packages/rate_limiter.yaml` (`auth`); выход — POST с `csrf_token('logout')`.
- Справка `/help` и «Что нового» `/changelog` рендерят `docs/user-guide.md` и `docs/changelog.md` (league/commonmark);
  оба файла исключены из `*.md` в `.dockerignore`, иначе их не будет в образе.

- Все POST-формы с `csrf_token('form')` (`BaseController::checkCsrf`), вход — с CSRF и лимитом попыток (5/мин).
- Любой id из URL/формы проверяется на принадлежность пространству (`Scope::owns`, `Ledger::visible`, выборки
  счетов/категорий из пространства), иначе 404.
- Группы: управлять участниками, ссылкой и настройками может только владелец.

## Деплой

- `git push` в `main` → GitHub Actions (`.github/workflows/deploy.yml`): тесты, затем SSH на сервер.
  Ключ CI на сервере ограничен: `restrict,command="<проект>/bin/ci-deploy"` в `authorized_keys` — он умеет только
  `bin/ci-deploy`: принять известные `KEY=VALUE` из stdin, `git reset --hard origin/main`, `bin/deploy`.
  Деплой включается переменной репозитория `DEPLOY_ENABLED=true`.
- `bin/deploy`: пишет в `.env.local` настройки, переданные из GitHub Secrets; `APP_SECRET` и `POSTGRES_PASSWORD`
  генерирует при первом запуске и больше не меняет; без обязательных переменных останавливается до сборки
  (сайт остаётся на старой версии); собирает образ, поднимает, применяет миграции, проверяет, что сайт отвечает.
- Секреты GitHub: `SSH_KEY`, `SSH_KNOWN_HOSTS`, `SSH_HOST`, `SSH_USER`, `HTTP_PORT`, `DEFAULT_URI`,
  `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_ADMIN_CHAT_ID` (необязательный — куда слать уведомления), `SENTRY_DSN` (необязательный — Bugsink).

**Новая переменная окружения:** пустой плейсхолдер с комментарием в `.env`; имя — в `ALLOWED` в `bin/ci-deploy`,
в `PASSED` (и `REQUIRED`, если без неё нельзя) в `bin/deploy`, в `env` и список `for v in ...` в workflow;
значение — `gh secret set ИМЯ`.

## Новый инструмент

1. Код в `src/<Tool>/` (Entity, Controller), шаблоны в `templates/<tool>/`, маршруты `/<tool>/...`.
2. Маппинг Doctrine для `src/<Tool>/Entity` в `config/packages/doctrine.yaml`.
3. Плитка в `templates/hub.html.twig`.
4. Общие люди и группы — `App\Entity\User`, `Group`, `Membership`.
5. Обновить ТЗ, PROGRESS.md, эту документацию.
