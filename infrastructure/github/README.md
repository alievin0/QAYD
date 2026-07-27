# infrastructure/github — CI/CD home (reconciliation)

`docs/architecture/FINAL_TECH_STACK.md` lists `infrastructure/github` as the CI/CD home:

> `infrastructure/ … github` — CI/CD workflows — the per-app gate matrix
> (Pint/PHPStan/Pest · ESLint/tsc/Vitest · ruff/mypy/pytest)

GitHub Actions, however, **only executes workflows that physically live under
`.github/workflows/`** at the repository root — a workflow placed here would never run. To honour both
the spec's intent and the platform's hard requirement, the reconciliation is:

- **Canonical, runnable CI:** [`../../.github/workflows/ci.yml`](../../.github/workflows/ci.yml)
- **This directory:** the documented CI home per FINAL_TECH_STACK — the map, plus any shared CI
  snippets. It carries no runnable workflow.

If a future tool wants "the CI definition" from the path the stack doc advertises, it lands here and is
pointed one hop to `.github/workflows/ci.yml`.

## The gate matrix (S1-02)

CI is a **fan-out of three parallel per-codebase jobs**, each running its full set of **blocking**
gates, then a light aggregate `summary` job (a single required status for branch protection). Every
command below is the one that passes locally today; the CI mirrors it exactly.

| Job | Gate | Command (working dir) | Blocking |
| --- | --- | --- | --- |
| `backend` | migrate | `php artisan migrate --force` (`apps/api`) | Yes |
| `backend` | seed | `php artisan db:seed --force` (`apps/api`) | Yes |
| `backend` | format | `./vendor/bin/pint --test` | Yes |
| `backend` | static analysis | `./vendor/bin/phpstan analyse --memory-limit=1G` (level max) | Yes |
| `backend` | unit + feature + arch + rls | `./vendor/bin/pest` | Yes |
| `backend` | coverage floor | `./vendor/bin/pest --coverage --min=85` | No (advisory, S1-02) |
| `frontend` | packages build | `pnpm -r --filter './packages/*' run build` | Yes |
| `frontend` | lint | `pnpm --filter web run lint` | Yes |
| `frontend` | typecheck | `pnpm --filter web run typecheck` | Yes |
| `frontend` | unit + integration | `pnpm --filter web run test` | Yes |
| `frontend` | i18n parity | `pnpm --filter web run i18n:check` | Yes |
| `frontend` | build | `pnpm --filter web run build` | Yes |
| `ai` | lint | `uv run ruff check .` (`apps/ai`) | Yes |
| `ai` | typecheck | `uv run mypy src` (`apps/ai`) | Yes |
| `ai` | unit + contract | `uv run pytest` (`apps/ai`) | Yes |
| `summary` | aggregate | fails unless all three jobs are `success` | Yes |

## Service containers (backend job)

The backend job attaches **Postgres 16** and **Redis 7** as service containers with health checks:

- `postgres:16-alpine` — `POSTGRES_DB=qayd`, `POSTGRES_USER=qayd`, `POSTGRES_PASSWORD=qayd`,
  port `5432`, health-gated on `pg_isready`.
- `redis:7-alpine` — port `6379`, health-gated on `redis-cli ping`.

Migrations run as the owner role `qayd` (a superuser in the CI image, required to `CREATE ROLE`);
migration `2026_07_27_000008_create_app_database_role` then creates the **non-superuser,
`NOBYPASSRLS`** role `qayd_app` that the app (the `pgsql_app` connection) uses so Row-Level Security is
enforced rather than bypassed. `.env` is copied from `apps/api/.env.example`, which ships an **empty**
`DB_PASSWORD`; the job-level `DB_*` env (Laravel's immutable dotenv lets real env win) supplies the
password that both the migrate/seed steps and the pest `rls,isolation` group connect with.

## Triggers

`pull_request` to `main` / `sprint-01-foundations`, `push` to the same protected branches, and manual
`workflow_dispatch`. A concurrency group cancels superseded in-flight runs per ref.

## Local equivalent

The root `Makefile` mirrors these gates for developers: `make install`, `make up`, `make migrate`,
`make seed`, `make fresh`, and `make test` (runs all three codebases' gate suites).

## Not yet wired (deferred with their specs, per TESTING_STRATEGY.md)

The OpenAPI-drift contract stage, the shared AI-proposal schema contract, Playwright E2E, k6 load
smoke, and dependency/secret scans are named in `docs/testing/TESTING_STRATEGY.md` but activate once
their fixtures/specs exist. Frontend/AI coverage floors likewise activate when the suites and coverage
providers land; the backend coverage-floor step is present now and non-fatal.
