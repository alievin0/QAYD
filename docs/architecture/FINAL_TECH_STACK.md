# QAYD — Final Tech Stack

Version: 1.0
Status: Locked
Date: 2026-07

---

This document is the single, authoritative reference for QAYD's technical stack and repository shape. The
architecture recorded here (referred to across the docs as **Option A**) is **decided and final**. It is
not an MVP shortcut, a proposal, or one option among several — it is the committed destination that every
Architecture Decision Record, sprint plan, and specification document is expected to agree with.

The shape of the platform is deliberate. QAYD is a double-entry accounting core with an AI workforce on
top of it, and within two years it will carry Inventory, Payroll, a full Tax engine, Banking/Treasury, and
a multi-agent AI layer. That surface area needs a **strong, explicit domain layer** — which is why the
business logic lives in a Laravel modular monolith, not in database edge functions. Supabase is used, but
only for the managed data services it is genuinely good at (Postgres, Storage, Realtime, optionally Auth);
it is **not** the backend.

## The Stack

| Layer | Technology | Role / why |
|---|---|---|
| **Frontend framework** | **Next.js 15 · React 19 · TypeScript** | The web application (`apps/web`): a data-dense, bilingual (EN/AR, full RTL) editorial dashboard. Server Components stream real data on first paint; it holds **no privileged database credential** and consumes the Laravel API through the typed TypeScript SDK. |
| **UI / design system** | **Tailwind CSS + shadcn/ui** (Radix primitives, owned in-repo), bound to the QAYD **brass** design tokens | One token source (colors, grotesque type scale, spacing, radii, motion) drives light/dark × LTR/RTL. Accessibility comes from Radix; the editorial look comes from full styling control. Lives in `packages/ui`. |
| **Backend / domain layer** | **Laravel 12 (PHP 8.4)** | **THE domain layer and single source of truth.** Owns the General Ledger, the Posting Engine, double-entry invariants, Bank Reconciliation, the Tax Engine, Automation, Audit Logs, and the **AI workflow orchestration handoff**. Exposes the versioned, OpenAPI-documented `/api/v1`. Lives in `apps/api`. |
| **AI engine** | **FastAPI (Python)** | A **separate AI engine service** (`apps/ai`) housing the AI Orchestrator and agents (OCR/extract, drafting, reconciliation suggestions, retrieval). Reachable **only through Laravel** over HTTP/queue (mTLS + internal token); holds no tenant DB credential. It proposes; it never writes to the ledger. |
| **Database** | **PostgreSQL** (single database) | The system of record. Money is `NUMERIC(19,4)`, never float; semi-structured payloads use `JSONB`; AI memory uses `pgvector`. One database for all tenants, isolated by RLS. |
| **Multi-tenancy** | **Postgres Row-Level Security** keyed on `company_id` | Shared-database, shared-schema, discriminator column, defended in depth by RLS. Laravel is the primary query path and pins the active company per request (`SET LOCAL app.current_company_id`); RLS is the storage-engine floor that returns **zero rows** when the tenant context is unset. |
| **Auth** | **Laravel Sanctum** (cookie sessions) **+ RS256 JWT** for mobile/partner clients | Sanctum stateful cookies serve the Next.js web app; RS256 JWT + rotating refresh serve bearer clients (mobile, partners). Supabase Auth is available as an option but is not the primary identity provider. |
| **Cache** | **Redis** | Application cache, resolved-permission cache, rate-limit counters. |
| **Queue** | **Redis** | Laravel queues/jobs: the transactional-outbox dispatcher, report generation, AI relay, and background work. |
| **Storage** | **Supabase Storage** | Documents, attachments, and generated exports (PDF/Excel), served via signed URLs through the Laravel File Service. |
| **Realtime** | **Laravel Reverb** (primary); **Supabase Realtime** (option) | One-way, company-scoped backend → client pushes (`private-company.{id}`) that tell the client to refresh authoritative state — never a second write path. Reverb is the primary implementation; Supabase Realtime is an available alternative. |
| **Deployment** | **Docker** | Every service (web, api, ai) and its dependencies (Postgres, Redis) is containerized for local dev, CI, and production. |
| **Managed data services** | **Supabase** — managed **PostgreSQL + Storage + Realtime** (optionally **Auth**) | Data services **only**. Supabase is **not** the backend and holds no business logic. See "Supabase's Role" below. |

## The Monorepo

QAYD is a **polyglot monorepo** — TypeScript, PHP, and Python living in one repository — with per-app
tooling coordinated at the root (Composer for the Laravel API, `uv`/`pip` for the FastAPI engine,
pnpm for the Next.js web app and the TypeScript packages). It is **not** a pure pnpm/Turborepo
JavaScript-only workspace: no single JS build orchestrator spans PHP and Python. The root coordinates
per-app builds, gates, and CI; each app keeps its idiomatic toolchain.

```
apps/
  web        Next.js 15 / React 19 / TS — the web application; consumes /api/v1 via the SDK
  api        Laravel 12 / PHP 8.4 — the backend and domain layer; owns /api/v1
  ai         FastAPI / Python — the AI engine (Orchestrator + agents); called only by Laravel
packages/
  ui         shadcn/ui + Tailwind design-system components bound to the brass tokens (used by web)
  types      hand-authored shared TypeScript domain types + Zod schemas (web + sdk)
  sdk        typed TypeScript client for Laravel's /api/v1 (from the OpenAPI contract)
  config     shared TS / ESLint / Prettier / Tailwind config for the TypeScript workspaces
  shared     shared TypeScript utilities and constants used across web + packages
infrastructure/
  supabase   Supabase project configuration — managed Postgres, Storage, Realtime, (optional Auth)
  postgres   database migrations, RLS policies, and seeds — the schema QAYD owns
  docker     Dockerfiles and compose definitions for local, CI, and production
  github     CI/CD workflows — the per-app gate matrix (Pint/PHPStan/Pest · ESLint/tsc/Vitest · ruff/mypy/pytest)
docs/        the specification and execution documents (this file governs the stack)
```

Dependency direction: `apps/web` depends on `packages/*` and calls `apps/api` only over `/api/v1` (never
its internals); `apps/ai` is reached only by `apps/api`; `packages/*` never depend on `apps/*`. The
cross-service contract between the PHP backend and its consumers is the **versioned, OpenAPI-documented
`/api/v1`**, which the TypeScript SDK consumes — not a shared compiled type.

## Supabase's Role

Supabase provides **managed data services only**:

- **Managed PostgreSQL** — the system of record ([single database, RLS multi-tenancy](adr/0004-postgresql.md)).
- **Storage** — documents, attachments, and exports via signed URLs.
- **Realtime** — an available option for backend → client pushes (Reverb is the primary implementation).
- **Auth** — available as an option; the primary identity mechanism is Laravel Sanctum + RS256 JWT.

Supabase is explicitly **NOT the backend**. All business logic — the General Ledger, the Posting Engine,
double-entry enforcement, Bank Reconciliation, the Tax Engine, Automation, Audit Logs, and the AI
orchestration handoff — lives in **Laravel 12**. There are no Supabase Edge Functions carrying domain
rules, and clients do not query Postgres directly through PostgREST for business operations; every
business operation passes through Laravel's `/api/v1`.

**Rationale (why this is deliberate).** Accounting is a domain-heavy problem, and QAYD's roadmap only
deepens it: within two years the platform will run Inventory, Payroll, a full Tax engine, Banking/Treasury,
and a multi-agent AI workforce. That is far easier to build, test, and evolve in a strong Laravel domain
layer — with its service classes, policies, queues, events, and a real posting engine — than as a sprawl
of database edge functions. Postgres, the schema, and RLS are portable and vendor-neutral; using Supabase
for the managed data plane buys operational leverage without ceding the domain layer.

## How to Use This Document

**This file wins.** It is the governing reference for QAYD's stack and repository structure. If any Sprint
plan, Architecture Decision Record, or other specification document conflicts with what is written here,
the **other document is wrong and is updated to match this one** — not the reverse. When you find a
contradiction, treat FINAL_TECH_STACK.md as the source of truth and reconcile the other document to it.

The Architecture Decision Records in [`adr/`](adr/) record the reasoning behind each of these choices and
are kept consistent with this file; the [`adr/README.md`](adr/README.md) index links back here as the
governing document.

# End of Document
