# ADR-0001: Structure the build as a polyglot monorepo (apps + packages + infrastructure)

Status: Accepted

Date: 2026-07

## Context

QAYD is an AI Financial Operating System built from three runtimes that must ship and evolve together: a
**Laravel 12 / PHP 8.4** backend that owns the domain, a **Next.js 15 / React 19 / TypeScript** web app, and
a **FastAPI / Python** AI engine ([../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md);
[../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) ADR-001). These
surfaces share a versioned API contract, a database schema, and a design system, and a change to any of
them frequently needs a coordinated change in another. The question was how to organize the repositories so
those coordinated changes land as one reviewable, atomic unit rather than as three separately-versioned
releases chasing each other.

The choices were a polyrepo (one repo per surface), a loose multi-package repo, or a first-class monorepo
that accepts more than one language.

## Decision

QAYD is built as a **single polyglot monorepo** — **TypeScript + PHP + Python in one repository** — with
**per-app tooling coordinated at the root**. There is no single JS build orchestrator spanning every
surface; each app keeps its idiomatic toolchain and the root coordinates builds, gates, and CI. The layout
is:

- `apps/web` — the Next.js 15 web application ([ADR-0002](./0002-nextjs-for-web.md)); tooling: **pnpm**.
- `apps/api` — the **Laravel 12 backend and domain layer** ([ADR-0003](./0003-supabase-as-backend.md)),
  which owns all business rules and exposes the versioned `/api/v1`; tooling: **Composer**.
- `apps/ai` — the **FastAPI AI engine** ([ADR-0007](./0007-ai-orchestrator.md)), the Orchestrator + agents,
  reachable only through Laravel; tooling: **uv / pip**.
- `packages/ui` — the shadcn/ui + Tailwind design-system components ([ADR-0009](./0009-tailwind-shadcn-design-system.md)); pnpm.
- `packages/types` — hand-authored shared TypeScript domain types and Zod schemas ([ADR-0008](./0008-typescript-shared-packages.md)); pnpm.
- `packages/sdk` — the typed TypeScript client for `/api/v1`, consumed by the web app ([ADR-0008](./0008-typescript-shared-packages.md)); pnpm.
- `packages/config` — shared TypeScript, ESLint, Prettier, and Tailwind configuration for the TS workspaces.
- `packages/shared` — shared TypeScript utilities and constants used across the web app and packages.
- `infrastructure/supabase` — Supabase project configuration (managed Postgres, Storage, Realtime, optional Auth), the managed data plane only.
- `infrastructure/postgres` — database migrations, RLS policies, and seeds — the schema QAYD owns.
- `infrastructure/docker` — Dockerfiles and compose definitions for local, CI, and production.
- `infrastructure/github` — the CI/CD workflows and the per-app gate matrix.

Boundaries are enforced by review and per-workspace rules: `packages/*` never depend on `apps/*`;
`apps/web` never imports `apps/api` internals — it calls `/api/v1` through `packages/sdk`; and `apps/ai` is
reached only by `apps/api`. The pnpm workspace covers the TypeScript surfaces (`apps/web` + `packages/*`);
Composer manages `apps/api`; uv/pip manages `apps/ai`. This mirrors the module discipline of the Laravel
domain layer.

## Consequences

Positive:
- A change that spans the schema, the API contract, and the web client lands in **one commit** across all three languages — no cross-repo version skew between backend, web, and AI.
- Each app keeps the idiomatic tooling its ecosystem expects (Composer, pnpm, uv/pip), so no runtime is forced through a foreign build tool.
- The `apps/` boundaries are the exact seams along which a surface could later be split into its own deployable if scale demands it; the `infrastructure/` tree keeps schema, containers, and CI versioned with the code they serve.

Negative / trade-offs:
- A polyglot monorepo needs disciplined dependency hygiene and **language-aware CI** — three tool matrices (Pint/PHPStan/Pest, ESLint/`tsc`/Vitest, ruff/mypy/pytest) fanned out per app rather than one unified pipeline.
- There is no single cross-language build cache; caching is per-ecosystem, so the "one tool builds everything" convenience of a JS-only workspace does not apply.
- One repo means one blast radius for a bad shared-config or CI change.

## Alternatives considered

- **Polyrepo (one repo per surface):** strongest isolation, but the shared API contract and schema would each live behind their own release cadence, reintroducing exactly the version-skew and coordination cost the monorepo removes.
- **A pure pnpm/Turborepo JavaScript-only workspace:** rejected because QAYD is deliberately **not** JavaScript end to end — the backend is Laravel/PHP and the AI engine is FastAPI/Python ([../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md)). A JS-only orchestrator cannot build or gate the PHP and Python apps, so a language-agnostic, per-app-tooling monorepo is the correct fit.
- **Single flat app (no packages):** fastest to start, but it would hard-wire web, api, and ai together with no reusable UI, types, or SDK contract, blocking the mobile and AI surfaces the platform requires.

## Related

- [../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md) — the governing stack + monorepo reference.
- [ADR-0002](./0002-nextjs-for-web.md), [ADR-0003](./0003-supabase-as-backend.md), [ADR-0007](./0007-ai-orchestrator.md), [ADR-0008](./0008-typescript-shared-packages.md), [ADR-0009](./0009-tailwind-shadcn-design-system.md)
- Design target: [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — ADR-001 (modular monolith), and [../../foundation/PROJECT_STRUCTURE.md](../../foundation/PROJECT_STRUCTURE.md)
