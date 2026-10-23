# EVA Personal

Сборник личных инструментов. Первый инструмент — учёт средств («Деньги»).
ТЗ: `TechnicalTask.md` (ссылки «ТЗ §N»). Документация: `docs/`.

## ⚠️ Обязательное правило: PROGRESS.md

`PROGRESS.md` — единственный источник правды о состоянии работ.

- В начале сессии: прочитать `PROGRESS.md`, продолжить с «Следующий шаг».
- После каждого завершённого шага: отметить `[x]`, дописать «Журнал», обновить «Следующий шаг».
- Решения и отклонения от ТЗ — в «Решения». Не отмечать `[x]` непроверенное (`[~]` с причиной).

## ⚠️ Обязательное правило: документация в каждом коммите

Любое изменение поведения — в том же коммите (не «потом») обновить всё, что его касается:

- `PROGRESS.md` — «Этапы» (новый пункт / `[x]` / `[~]`), «Журнал» (что сделано и как проверено), «Решения», «Следующий шаг»;
- `TechnicalTask.md` — требование в нужном разделе (новая функция → новый пункт или §);
- `docs/user-guide.md` — что видит пользователь (это же и справка `/help` в приложении);
- `docs/development.md` — устройство, маршруты, конфиги, команды, переменные окружения.

Перед коммитом пройти по этому списку. Коммит без обновлённых документов — незаконченная работа.

## Стек

PHP 8.3 (Docker; локально может быть новее — `platform.php 8.3.0`, не использовать синтаксис 8.4+),
Symfony 7.4, Doctrine ORM 3, PostgreSQL 16, Twig, Nginx. Без JS-сборки: `public/app.css`, `public/app.js`.

## Структура

```
src/Entity/        общее для хаба: User, Group, Membership
src/Controller/    вход, профиль, хаб, группы
src/Money/         инструмент «Деньги»: Entity/, Controller/, сервисы (Ledger, Rates, Debts, Csv, Schedule)
src/Twig/          фильтры (money)
templates/money/   шаблоны инструмента
```

Новый инструмент: `src/<Tool>/`, `templates/<tool>/`, маршруты `/<tool>/...`, плитка в `templates/hub.html.twig`,
маппинг Doctrine в `config/packages/doctrine.yaml`.

## Правила

- Деньги — `int` в минимальных единицах валюты (`Money::parse/format`), никаких float в хранении.
- Пространство (`Scope`): `me` или id группы; резолвится из `{scope}` в URL с проверкой членства.
  Любой объект из URL проверять на принадлежность пространству, иначе 404.
- Формы — HTML + `csrf_token('form')`, проверка в контроллере.
- Секреты (APP_SECRET, пароли, токены) — только в `.env*.local` / переменных окружения, никогда в коммитах,
  включая `.env.dev` и `.env.test`. Перед коммитом проверять дифф.
- Новая переменная окружения: плейсхолдер в `.env`, имя в `ALLOWED` (`bin/ci-deploy`), `PASSED`/`REQUIRED` (`bin/deploy`) и в workflow
  (`docs/development.md`, «Деплой»). Значения — только GitHub Secrets / `.env.local` на сервере.
- Время UTC, id — UUID v7. Изменения схемы — только миграции.
- `declare(strict_types=1);`, `final` для не-сущностей. Нетривиальная логика — с unit-тестом в `tests/`.

## Команды

```bash
docker compose -f compose.dev.yaml up -d --build          # dev; прод — compose.prod.yaml
docker compose -f compose.dev.yaml exec app bin/console doctrine:migrations:migrate -n
php bin/phpunit                      # unit-тесты, без БД
```

# Infrastructure, domains and deploy notes are kept outside this repository.
