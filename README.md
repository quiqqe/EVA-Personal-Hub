# EVA Personal

Сборник личных инструментов. Первый инструмент — **«Деньги»**: учёт наличных, карт, вкладов и долгов,
личный и общий (семья, друзья) бюджет, деление трат, бюджеты, цели, регулярные платежи, импорт выписок, отчёты.

- [Руководство пользователя](docs/user-guide.md)
- [Разработка: архитектура и модель данных](docs/development.md)
- ТЗ — [TechnicalTask.md](TechnicalTask.md), состояние работ — [PROGRESS.md](PROGRESS.md)

## Быстрый старт (локально)

```bash
echo "APP_SECRET=$(openssl rand -hex 32)" > .env.dev.local   # секреты только в *.local, не в git
docker compose -f compose.dev.yaml up -d --build
docker compose -f compose.dev.yaml exec app composer install
docker compose -f compose.dev.yaml exec app bin/console doctrine:migrations:migrate -n
open http://127.0.0.1:8080
```

Тесты: `php bin/phpunit`.

Два самостоятельных compose-файла: `compose.dev.yaml` — разработка (код смонтирован, БД на `127.0.0.1:54321`),
`compose.prod.yaml` — прод-образ, секреты и порт берёт из `.env.local` (без `POSTGRES_PASSWORD` не запустится).
