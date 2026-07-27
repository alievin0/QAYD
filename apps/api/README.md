# apps/api — QAYD Backend / Domain Layer

This is **THE backend** for QAYD. It is a Laravel 12 (PHP 8.4) application that owns the
`/api/v1` HTTP surface and, going forward, all business/domain logic. **Logic lives here — not
in Supabase, not in Edge Functions, not in the frontend.** Other apps in the monorepo call this
service; they do not re-implement its rules.

Sprint 1 delivered the **identity & tenancy foundation** (the accounting/domain layer arrives in
later sprints):

- **Identity/tenant schema** — users, companies, memberships, and the RBAC catalogue on PostgreSQL.
- **Postgres Row-Level-Security multi-tenancy** — tenant traffic is served as a dedicated
  non-superuser, `NOBYPASSRLS` role (`qayd_app`, the `pgsql_app` connection) so RLS is actually
  enforced; migrations still run as the schema owner.
- **Authentication** — register, email verification, login, RS256 **JWT** for bearer clients and
  a Sanctum **cookie** session for the web SPA, plus refresh-token rotation-on-use with family-wide
  **reuse detection** (`/auth/register`, `/auth/email/verify`, `/auth/login`, `/auth/refresh`,
  `/auth/logout`, `/auth/me`, `/auth/switch-company`).
- **Authorization** — a `PermissionResolver` + `permission:` route gate (deny-by-default RBAC).
- **Onboarding** — create-company (gated on a verified email).
- **Cross-cutting** — the standard JSON response **envelope** and an append-only **audit log**.

## Stack

- **Laravel** 12.x on **PHP** 8.4
- **Database:** PostgreSQL 16 (`pgsql`), local dev at `127.0.0.1:5432`, db/user `qayd`
- **Cache / Redis:** Redis 7 at `127.0.0.1:6379`
- Cache, queue, and session drivers are left at Laravel defaults (`database`).

## Setup

```bash
cp .env.example .env      # then set DB_PASSWORD (owner) for local dev; DB_APP_PASSWORD may stay CHANGE_ME
composer install
php artisan key:generate
php artisan migrate        # creates the qayd_app role + RLS policies
php artisan db:seed        # seeds the RBAC catalogue (permissions + default roles) — REQUIRED for authz
```

> `DB_PASSWORD` must match the `POSTGRES_PASSWORD` your Postgres container uses (compose default:
> `CHANGE_ME`). The `qayd_app` role is created/kept at whatever `DB_APP_PASSWORD` you set, and the app
> connects with the same value, so they always match.

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
