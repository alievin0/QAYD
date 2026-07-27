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

Filled in as each app lands in Sprint 01. The intended single entry point is `make up`; per-app run
commands are documented in each app's README.

## Documentation

The full specification lives in [`docs/`](./docs/) and is the blueprint this build implements (frozen at
git tag `architecture-freeze-v1`). Key areas:

- [foundation](docs/foundation/) · [accounting](docs/accounting/) · [banking](docs/banking/) ·
  [inventory](docs/inventory/) · [payroll](docs/payroll/) · [tax](docs/tax/) · [ai](docs/ai/)
- [api](docs/api/) · [database](docs/database/) · [security](docs/security/) · [enterprise](docs/enterprise/)
- [architecture](docs/architecture/) — ADRs + FINAL_TECH_STACK · [execution](docs/execution/) — sprint plans

## License

Proprietary — © QAYD. All rights reserved.
