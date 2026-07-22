# Local Development Setup — QAYD Developer
Version: 1.0
Status: Design Specification
Module: Developer
Submodule: LOCAL_SETUP
---

# Purpose

This document gets a QAYD engineer — or an AI coding agent — from a fresh clone to a running,
seeded, three-service local environment with every quality gate executable on their machine. It
covers the prerequisites, the Docker-based development stack, the per-service setup for the Laravel
backend, the Next.js frontend, and the FastAPI AI engine, the environment files each one reads,
seeding a demo tenant, running the gate commands locally, and the handful of problems that reliably
bite newcomers. It stops at the edge of *how to write* QAYD code (that is
[CODING_GUIDELINES.md](./CODING_GUIDELINES.md)) and *how to submit* it (that is
[CONTRIBUTING.md](./CONTRIBUTING.md)); its single job is a correct, reproducible local rig.

QAYD is one monorepo containing three independently deployable services plus a shared database
package and a Flutter client, per
[../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md):

```text
QAYD/
├── frontend/     Next.js 15 / React 19 / TypeScript — web client        (port 3000)
├── backend/      Laravel 12 / PHP 8.4 — API, the only write path        (port 8000)
├── ai/           FastAPI / Python 3.12 — reasons, never writes to DB     (port 9000)
├── database/     migrations, seeders, factories, ERD, schema snapshots
├── mobile/       Flutter — native client (own toolchain, see mobile/README.md)
└── infrastructure/docker/  the local dev compose stack
```

The three services relate exactly as they do in production: the frontend and mobile clients call the
backend's `/api/v1`; the backend is the only thing that touches PostgreSQL; the AI engine reasons and
proposes but calls back into `/api/v1` like any other client and never opens its own database
connection. Your local rig preserves that topology so a bug you can only reproduce with the real
seams is reproducible before it reaches staging.

# Prerequisites

Install these once. Versions are pinned platform-wide by
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md); a local version that drifts from CI is the
single most common source of "works on my machine".

| Tool | Version | Check | Notes |
|---|---|---|---|
| Docker + Compose v2 | Engine 24+, Compose v2 | `docker --version && docker compose version` | Runs PostgreSQL, Redis, and (optionally) the whole stack. Required. |
| Node.js | 20 LTS | `node -v` | Pinned in `frontend/.nvmrc` / `package.json#engines`. Use `nvm use` in `frontend/`. |
| npm | 10+ | `npm -v` | Ships with Node 20. The frontend is a single npm package with a committed lockfile. |
| PHP | 8.4 | `php -v` | Only needed if you run the backend outside Docker; Laravel Sail bundles it otherwise. |
| Composer | 2.7+ | `composer --version` | PHP dependency manager. |
| Python | 3.12 | `python3 --version` | For the AI engine; use a per-service virtualenv. |
| PostgreSQL client | 15 | `psql --version` | The server runs in Docker; you only need the client (`psql`) locally. |
| Git | 2.40+ | `git --version` | Linear history; `pull --rebase` is the update loop (see CONTRIBUTING). |

You do **not** need a globally-installed PostgreSQL server or Redis — the compose stack provides both.
You do not need any API secret or bearer token to run the frontend: the web session is a Sanctum
cookie established by signing in through the running app, not a client-held credential.

# The Docker Development Stack

The fastest correct path is the compose stack in `infrastructure/docker/`. It brings up the shared
infrastructure (PostgreSQL 15 with row-level security enabled, Redis 7) and, optionally, the three
application services, so a new contributor gets a working system with one command.

```bash
git clone git@github.com:qayd/qayd.git
cd qayd
cp infrastructure/docker/.env.example infrastructure/docker/.env   # stack-level config
docker compose -f infrastructure/docker/compose.dev.yml up -d postgres redis
```

The two lines above are the minimum: they start only PostgreSQL and Redis, which is the recommended
layout for day-to-day work — you run the *service you are editing* on the host (fast reloads, native
debugger) and let Docker own the stateful infrastructure. To bring up everything in containers
instead (useful for a first look or for reproducing a cross-service bug exactly):

```bash
docker compose -f infrastructure/docker/compose.dev.yml up -d
```

| Service in the stack | Container | Host port | Purpose |
|---|---|---|---|
| `postgres` | `qayd-postgres` | 5432 | Primary database, RLS enabled, one database per environment. |
| `redis` | `qayd-redis` | 6379 | Cache, session, queue, and idempotency lock store. |
| `backend` | `qayd-backend` | 8000 | Laravel API + queue worker (optional in-container). |
| `frontend` | `qayd-frontend` | 3000 | Next.js dev server (optional in-container). |
| `ai` | `qayd-ai` | 9000 | FastAPI AI engine (optional in-container). |
| `mailpit` | `qayd-mailpit` | 8025 | Captures outbound mail locally; UI at `http://localhost:8025`. |

PostgreSQL data persists in a named volume across restarts. To reset to a clean database — the
fastest fix for a corrupted local schema — tear the volume down and re-migrate:

```bash
docker compose -f infrastructure/docker/compose.dev.yml down -v   # -v drops the data volume
docker compose -f infrastructure/docker/compose.dev.yml up -d postgres redis
# then re-run backend migrate + seed (below)
```

# Backend Setup (Laravel 12 / PHP 8.4)

The backend is the source of truth and the first service to stand up, because the frontend and AI
engine both need it running. Laravel Sail (the project's Docker wrapper for PHP) is the supported
path; running native PHP works identically if you prefer your own PHP 8.4.

```bash
cd backend
cp .env.example .env                 # local backend config (see the env table below)
composer install                     # PHP dependencies
php artisan key:generate             # app encryption key
```

Point `.env` at the compose stack's PostgreSQL and Redis (the defaults in `.env.example` already do
this for the ports in the table above), then create the schema and seed a demo tenant:

```bash
php artisan migrate --seed           # runs database/migrations then database/seeders
```

`--seed` runs the `DatabaseSeeder`, which provisions the demo tenant described under
[# Seeding a Demo Tenant](#seeding-a-demo-tenant). Serve the API and start a queue worker (heavy work
— OCR, reports, AI fan-out — runs on the queue, so a worker is needed for those flows to complete):

```bash
php artisan serve                    # http://localhost:8000  (or: ./vendor/bin/sail up)
php artisan queue:work               # in a second terminal — processes default/ai/reports queues
```

Verify the API is up and tenant-aware:

```bash
curl -s http://localhost:8000/api/v1/health | jq
# { "success": true, "data": { "status": "ok" }, "request_id": "...", "timestamp": "..." }
```

Backend `.env` keys that matter locally:

| Key | Example | Purpose |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `local` / `true` | Local mode; verbose errors. Never `true` outside local. |
| `APP_KEY` | (from `key:generate`) | Encryption key; unique per checkout. |
| `DB_CONNECTION` | `pgsql` | PostgreSQL only — RLS and `NUMERIC(19,4)` money depend on it. |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `5432` | The compose `postgres` container. |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `qayd` / `qayd` / `qayd` | Matches `compose.dev.yml`. |
| `REDIS_HOST` / `REDIS_PORT` | `127.0.0.1` / `6379` | Cache, queue, session, idempotency locks. |
| `QUEUE_CONNECTION` | `redis` | So `queue:work` picks up jobs; `sync` hides async bugs — avoid it. |
| `SESSION_DOMAIN` | `localhost` | Sanctum cookie session domain for the local web client. |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3000` | Lets the Next.js dev server hold a cookie session. |
| `MAIL_MAILER` / `MAIL_HOST` / `MAIL_PORT` | `smtp` / `127.0.0.1` / `1025` | Routes local mail to Mailpit. |
| `AI_ENGINE_URL` | `http://localhost:9000` | Where the backend fans AI events to and receives proposals from. |

Secrets (payment keys, real bank credentials, government-integration tokens) are **never** placed in a
committed `.env`; local development uses the sandbox/stub values in `.env.example` and the stubbed
integration drivers. A real secret in a working tree is a secret-scan failure waiting to happen.

# Frontend Setup (Next.js 15 / React 19)

The frontend is a pure presentation layer that calls the backend's `/api/v1`; it holds no database
access and no service credential. Point it at either your local backend or the shared staging API.

```bash
cd frontend
nvm use                              # selects Node 20 from .nvmrc
npm install                          # single package, committed package-lock.json
cp .env.local.example .env.local     # public NEXT_PUBLIC_* config
npm run dev                          # next dev --turbo → http://localhost:3000
```

The one variable you must get right is the API base URL. For a fully-local loop, point it at your
backend; to develop UI against real, seeded data without running the backend, point it at staging and
sign in with a staging demo user:

```bash
# .env.local — fully local
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000

# .env.local — UI against shared staging data
NEXT_PUBLIC_API_BASE_URL=https://staging.qayd.app
```

Frontend `.env.local` keys (all browser-readable `NEXT_PUBLIC_*` except where noted):

| Key | Example | Purpose |
|---|---|---|
| `NEXT_PUBLIC_API_BASE_URL` | `http://localhost:8000` | Base for every `lib/api/client.ts` call, server- or browser-side. |
| `NEXT_PUBLIC_APP_ENV` | `local` | Drives the staging banner and error sample rate. |
| `NEXT_PUBLIC_DEFAULT_LOCALE` | `en` | Fallback locale before session/browser locale resolves. |
| `NEXT_PUBLIC_DEFAULT_CURRENCY` | `KWD` | Fallback presentation currency before the company's base currency loads. |
| `NEXT_PUBLIC_REVERB_HOST` / `_PORT` / `_SCHEME` / `_APP_KEY` | `localhost` / `8080` / `http` / (key) | Realtime WebSocket (Reverb) connection for dashboard/notification/AI channels. |
| `SESSION_COOKIE_DOMAIN` | `localhost` | Server-only; `middleware.ts` validates the Sanctum cookie domain before trusting it. |

There is deliberately no `API_SECRET_KEY` here: authentication is the Sanctum cookie session
established by signing in through the running `/login` screen — there is nothing to paste in to "log
in as" someone. `lib/api/client.ts` handles CSRF-cookie priming and session-cookie forwarding exactly
as production does, so the fastest way to catch a contract mismatch is to never mock the API locally.

# AI Engine Setup (FastAPI / Python)

The AI engine is a separate typed-Python service. It reasons, drafts, and proposes; it never writes to
PostgreSQL. Locally it needs its own virtualenv and, to do anything useful, a model API key placed in
its dev-vars file (never committed).

```bash
cd ai
python3 -m venv .venv
source .venv/bin/activate             # Windows: .venv\Scripts\activate
pip install -r requirements-dev.txt   # runtime + test/lint tooling
cp .dev.vars.example .dev.vars        # local-only secrets/config — gitignored
uvicorn app.main:app --reload --port 9000
```

AI engine `.dev.vars` keys:

| Key | Example | Purpose |
|---|---|---|
| `QAYD_API_BASE_URL` | `http://localhost:8000` | The engine calls `/api/v1` as the acting user — same contract as any client. |
| `MODEL_API_KEY` | `sk-...` | Model provider key. Local-only; never committed, never logged. |
| `REDIS_URL` | `redis://localhost:6379` | Shared queue for the `events-ai` fan-out from the backend. |
| `AI_PROPOSAL_CALLBACK_PATH` | `/api/v1/ai/proposals` | Where the engine posts a completed proposal for human approval. |
| `LOG_LEVEL` | `info` | Structured logging verbosity; never logs monetary payloads or PII. |

Without a `MODEL_API_KEY` the engine still boots and serves its deterministic tool functions and
health check, so contract and unit tests run offline; only the reasoning paths degrade. This mirrors
the platform rule that AI is an assistive layer around a system that is fully correct without it.

# Environment Files at a Glance

The three services use three different env conventions, which trips people up. Keep them straight:

| Service | File | Committed? | Read by | Notable var |
|---|---|---|---|---|
| Backend | `backend/.env` | No (`.env.example` is) | Laravel config cache | `DB_*`, `REDIS_*`, `AI_ENGINE_URL` |
| Frontend | `frontend/.env.local` | No (`.env.local.example` is) | Next.js build + runtime | `NEXT_PUBLIC_API_BASE_URL` |
| AI engine | `ai/.dev.vars` | No (`.dev.vars.example` is) | FastAPI settings loader | `MODEL_API_KEY`, `QAYD_API_BASE_URL` |
| Docker stack | `infrastructure/docker/.env` | No (`.env.example` is) | `compose.dev.yml` | `POSTGRES_*` |

Every `*.example` file is committed and is the source of truth for *which* keys exist; the real files
are gitignored and hold your machine's values. Adding a new variable means updating the matching
`*.example` in the same commit, with its scope stated — a variable without a documented scope is never
merged (the rule mirrors the frontend README's env-var discipline).

# Seeding a Demo Tenant

The `DatabaseSeeder` provisions one fully-formed demo company so every screen renders against real,
multi-tenant, RLS-scoped data from the first minute. It is deterministic — re-running produces the
same tenant — so tests and screenshots are stable.

```bash
cd backend
php artisan migrate:fresh --seed      # drops, re-migrates, re-seeds a clean demo tenant
```

The seed creates:

| Seeded data | Detail |
|---|---|
| Demo company | `Qayd Demo Trading Co.` — base currency KWD, English + Arabic enabled, one branch. |
| Users + roles | An owner, an accountant, an auditor, and a read-only viewer — one per role in the RBAC model, so you can sign in as each and see how permissions gate the UI. |
| Chart of accounts | A standard localized COA (assets, liabilities, equity, income, expenses) with Arabic and English account names. |
| Transactions | A quarter of posted journal entries, invoices, bills, and bank lines so ledger, trial balance, and reconciliation screens are non-empty. |
| AI proposals | A few pending AI-drafted entries so the approval/reasoning affordances have something to render. |

Local sign-in credentials are printed by the seeder and documented in `database/seeders/README.md`;
the accountant user (`accountant@demo.qayd.app`) is the default for most flows. A second seeded
company exists specifically so tenant-isolation behaviour is observable locally: signing in scoped to
company A and requesting company B's resource returns a 404, never a leaked row — the same property the
CI RLS negative suite asserts.

# Running the Quality Gates Locally

CI runs exactly the commands below, so a green local run predicts a green pipeline (see
[CONTRIBUTING.md](./CONTRIBUTING.md) for the full gate matrix and
[../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) for what each layer owns). Run them
before you push — catching a failure locally costs seconds; catching it in CI costs a round trip.

Frontend:

```bash
cd frontend
npm run lint          # ESLint: typescript-eslint, jsx-a11y, react-hooks
npm run typecheck     # tsc --noEmit (strict)
npm run test          # Vitest + Testing Library
npm run test:e2e      # Playwright — 4 visual variants: light/dark × LTR/RTL
npm run i18n:check    # fails if a key exists in en.ts but not ar.ts, or vice versa
```

Backend:

```bash
cd backend
composer pint -- --test          # Laravel Pint / PSR-12 formatting
composer stan                    # PHPStan (max)
php artisan test --testsuite=Unit      # Pest unit — services, actions, value objects
php artisan test --testsuite=Feature   # Pest feature — full stack, real test PG + RLS
```

AI engine:

```bash
cd ai && source .venv/bin/activate
ruff check .          # lint
black --check .       # formatting
mypy --strict .       # typecheck
pytest tests/unit tests/contract   # deterministic tools + the shared proposal-schema contract
```

The single most valuable local habit is running the backend Feature suite before pushing any change
that touches a query or a permission — it runs against a real PostgreSQL with RLS on and is what
catches a tenant-scoping regression before a reviewer or, worse, staging does.

# Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Frontend loads but every request 401s | Not signed in, or `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN` don't include `localhost:3000`. | Sign in via `/login`; confirm the two backend `.env` keys list the frontend origin. |
| Requests 419 (CSRF) on mutations | The CSRF cookie wasn't primed. | Ensure `lib/api/client.ts` is the only fetch path; a hand-rolled `fetch` skips CSRF priming — route it through the wrapper. |
| `SQLSTATE... permission denied` on a normal query | RLS is on but `app.current_company_id` wasn't set — usually a query outside the tenant-scoped path. | Confirm the model uses `BelongsToCompany`; never `DB::table()` for a tenant read (see CODING_GUIDELINES). |
| Queued jobs never run (reports/AI stuck "processing") | No `queue:work` running, or `QUEUE_CONNECTION` isn't `redis`. | Start a worker; set `QUEUE_CONNECTION=redis`, not `sync`. |
| `i18n:check` fails on a PR that "only changed logic" | A string was added/removed in one dictionary only. | Add or remove the key in both `en.ts` and `ar.ts` in the same commit. |
| AI proposals never appear | Engine not running, no `MODEL_API_KEY`, or `AI_ENGINE_URL` mismatched. | Start `uvicorn`; set the key in `.dev.vars`; confirm backend `AI_ENGINE_URL` and engine `QAYD_API_BASE_URL` point at each other. |
| Node/PHP/Python version errors in CI but not locally | Local runtime drifted from the pinned version. | `nvm use` in `frontend/`; match `php -v` to 8.4 and `python3 --version` to 3.12. |
| Port already in use (3000/8000/9000/5432) | A prior run or a host PostgreSQL is bound. | Stop the other process, or remap the port in the relevant `.env`/compose file. |
| Corrupted local schema after an aborted migration | Partial migration state. | `php artisan migrate:fresh --seed`, or drop the Docker volume with `down -v` and re-migrate. |

# Related Documents

- [CONTRIBUTING.md](./CONTRIBUTING.md) — branch/commit/PR workflow and the required CI gates the
  local commands above mirror.
- [CODING_GUIDELINES.md](./CODING_GUIDELINES.md) — the tenancy discipline, `/api/v1` envelope, and
  per-language conventions your local code must satisfy before the gates pass.
- [RELEASE_PROCESS.md](./RELEASE_PROCESS.md) — how a green `main` becomes a deployed release.
- [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) — the test pyramid and coverage
  targets behind the gate commands.
- [../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md),
  [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md) — the monorepo layout and pinned versions
  this setup depends on.

# End of Document
