# apps/api — QAYD Backend / Domain Layer

This is **THE backend** for QAYD. It is a Laravel 12 (PHP 8.4) application that owns the
`/api/v1` HTTP surface and, going forward, all business/domain logic. **Logic lives here — not
in Supabase, not in Edge Functions, not in the frontend.** Other apps in the monorepo call this
service; they do not re-implement its rules.

Sprint 1 (story **S1-01**) scope is intentionally a **skeleton**: the app boots, exposes a
health route, connects to Postgres, and passes clean quality gates. No business schema, auth,
RBAC, or domain models yet — those arrive in later stories.

## Stack

- **Laravel** 12.x on **PHP** 8.4
- **Database:** PostgreSQL 16 (`pgsql`), local dev at `127.0.0.1:5432`, db/user `qayd`
- **Cache / Redis:** Redis 7 at `127.0.0.1:6379`
- Cache, queue, and session drivers are left at Laravel defaults (`database`).

## Setup

```bash
cp .env.example .env      # then fill DB_PASSWORD for local dev
composer install
php artisan key:generate
php artisan migrate
```

## Run

```bash
php artisan serve --port 8000
```

Health check (should return `{"status":"ok","service":"qayd-api"}`):

```bash
curl http://127.0.0.1:8000/api/v1/health
```

## Quality gates

All three must be green before merging.

```bash
# Code style (Laravel Pint)
./vendor/bin/pint --test          # report only; drop --test to auto-fix

# Static analysis (Larastan / PHPStan, level: max, analyses app/)
./vendor/bin/phpstan analyse

# Tests (Pest)
./vendor/bin/pest
```

## Layout notes

- `routes/api.php` — API routes, registered in `bootstrap/app.php` via `withRouting(api: ...)`.
  Resolves under the `/api` prefix, so `GET /api/v1/health`.
- `phpstan.neon` — Larastan config, `level: max`, `paths: [app]`.
- `tests/` — Pest feature tests (`tests/Feature`) + unit tests (`tests/Unit`).
