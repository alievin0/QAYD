# QAYD

An **AI Financial Operating System** for companies in Kuwait and the GCC — a double-entry accounting
core with an AI workforce on top, built one capability at a time.

> Read **[MANIFEST.md](./MANIFEST.md)** first (vision + philosophy), then
> **[PROJECT_STATUS.md](./PROJECT_STATUS.md)** (current state). The architecture is frozen —
> **[docs/architecture/FINAL_TECH_STACK.md](./docs/architecture/FINAL_TECH_STACK.md)** governs the stack
> and wins any conflict.

## Monorepo layout

A **polyglot monorepo** — TypeScript, PHP, and Python in one repository — with per-app tooling
coordinated at the root (ADR-0001). No single JS orchestrator spans PHP and Python.

```text
apps/
  web    Next.js 15 / React 19 / TS — the web application; consumes /api/v1 via the SDK   (pnpm)
  api    Laravel 12 / PHP 8.4 — the backend and domain layer; owns /api/v1                (composer)
  ai     FastAPI / Python — the AI engine (Orchestrator + agents); called only by Laravel (uv)
packages/
  ui       design-system components (shadcn/ui + Tailwind on the QAYD brass tokens)
  types    shared TypeScript domain types + Zod schemas
  sdk      typed client for Laravel's /api/v1
  config   shared TS / ESLint / Prettier / Tailwind config
  shared   shared TypeScript utilities and constants
infrastructure/
  supabase  managed-data-services config (Postgres, Storage, Realtime)
  postgres  migrations, RLS policies, seeds — the schema QAYD owns
  docker    Dockerfiles + compose for local, CI, production
  github    CI/CD workflows — the per-app gate matrix
```

Dependency direction: `apps/web` → `packages/*` and calls `apps/api` only over `/api/v1`; `apps/ai` is
reached only by `apps/api`; `packages/*` never depend on `apps/*`.

## Prerequisites

- **Node ≥ 22 + pnpm ≥ 11** — web + packages
- **PHP 8.4 + Composer** — api
- **Python ≥ 3.12 + uv** — ai
- **Docker** (Colima works headlessly) — Postgres + Redis

## Getting started

Prerequisites are listed above. The `Makefile` wraps the per-app toolchains — run `make help` to list
every target. First-time setup, from the repo root:

```bash
# 1. Install all dependencies (Composer for api + the pnpm workspace + uv for ai)
make install

# 2. Configure the backend env (gitignored; copied from the committed example)
cp apps/api/.env.example apps/api/.env
( cd apps/api && php artisan key:generate )
#   In apps/api/.env, set DB_PASSWORD to your local Postgres password — it must equal the
#   POSTGRES_PASSWORD the container is created with (compose default: CHANGE_ME). DB_APP_PASSWORD can
#   stay CHANGE_ME: the migration creates the non-superuser `qayd_app` role at that value and the app
#   connects with the same value, so they always match.
# (optional) frontend env — the SDK defaults to http://localhost:8000/api/v1 if you skip this:
cp apps/web/.env.example apps/web/.env

# 3. Start the local data services (Postgres 16 + Redis 7) and wait until healthy
make up

# 4. Create the schema (also creates the `qayd_app` role + Row-Level-Security policies)
make migrate

# 5. Seed the RBAC catalogue (permissions + default roles) — REQUIRED, or authorization has nothing to resolve
make seed
```

Then run the apps, each in its own terminal:

```bash
( cd apps/api && php artisan serve --port 8000 )                     # API  → http://localhost:8000
pnpm --filter web dev                                               # Web  → http://localhost:3000
( cd apps/ai && uv run uvicorn qayd_ai.main:app --reload --port 8001 )  # AI engine (called only by the API)
```

A healthy backend answers `curl http://localhost:8000/api/v1/health` with
`{"status":"ok","service":"qayd-api"}`. Then verify the whole stack by walking the Sprint-1
**Definition of Done** in the browser at http://localhost:3000:
**register → verify email → login → create a company → land on the dashboard.** In local dev
`MAIL_MAILER=log`, so the verification email (with its link) is written to
`apps/api/storage/logs/laravel.log` rather than sent.

> `make fresh` drops, re-migrates, and re-seeds in one step. `make test` runs all three codebases'
> quality gates — the exact commands CI runs. Every port/password comes from each app's gitignored
> `.env` (copied from its `.env.example`); nothing secret is committed.

## Documentation

The full specification lives in [`docs/`](./docs/) and is the blueprint this build implements (frozen at
git tag `architecture-freeze-v1`). Key areas:

- [foundation](docs/foundation/) · [accounting](docs/accounting/) · [banking](docs/banking/) ·
  [inventory](docs/inventory/) · [payroll](docs/payroll/) · [tax](docs/tax/) · [ai](docs/ai/)
- [api](docs/api/) · [database](docs/database/) · [security](docs/security/) · [enterprise](docs/enterprise/)
- [architecture](docs/architecture/) — ADRs + FINAL_TECH_STACK · [execution](docs/execution/) — sprint plans

## License

Proprietary — © QAYD. All rights reserved.
