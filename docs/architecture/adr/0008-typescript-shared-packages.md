# ADR-0008: Share TypeScript types across the web app and SDK; the cross-service contract is Laravel's /api/v1

Status: Accepted

Date: 2026-07

## Context

QAYD is a polyglot monorepo ([ADR-0001](./0001-monorepo-structure.md)): a **Laravel 12 / PHP** backend
([ADR-0003](./0003-supabase-as-backend.md)), a **Next.js / TypeScript** web app
([ADR-0002](./0002-nextjs-for-web.md)), and a **FastAPI / Python** AI engine
([ADR-0007](./0007-ai-orchestrator.md)). Three languages cannot share a single compiled type, so the
cross-service contract cannot be a TypeScript type — it must be a language-neutral, versioned API contract
([../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) ADR-003, ADR-008). At
the same time, the TypeScript side of the repo (the web app and the client SDK) benefits enormously from
sharing types among themselves rather than redefining the shape of an invoice or a journal line in two
places. The question is how to scope TypeScript sharing correctly: rich within the TS surfaces, and bounded
by the API contract at the language boundary.

## Decision

**The cross-service contract between the PHP backend and every client is Laravel's versioned,
OpenAPI-documented `/api/v1`.** Laravel is the single source of truth; the OpenAPI specification is the
authoritative shape of every request and response, and it is enforced by contract tests. The FastAPI AI
engine and any mobile/partner client consume this same API contract — **not** a shared TypeScript type.

Within the TypeScript surfaces, shape is shared through two packages
([ADR-0001](./0001-monorepo-structure.md)):

- **`packages/types`** — hand-authored domain types, enums, and **Zod schemas** for validated boundaries
  (form inputs, view models, client-side parsing). Runtime validation and the static type derive from the
  same Zod schema, so a value that type-checks in the browser has also been validated.
- **`packages/sdk`** — the typed TypeScript client for `/api/v1`, whose types are **generated from / kept in
  sync with the OpenAPI contract**. The SDK is the one place the web app talks to the backend; `apps/web`
  imports `packages/sdk` and `packages/types` and **never redefines a wire shape locally** or hand-rolls a
  fetch call against the API.

So the sharing is scoped: `packages/types` + `packages/sdk` are shared across **`apps/web` and the SDK**;
the PHP↔client and Python↔PHP boundaries are governed by the OpenAPI `/api/v1` contract. When the backend
changes an endpoint, the OpenAPI spec changes, the SDK is regenerated, and every TypeScript consumer that no
longer matches fails `tsc` at build time — while the contract test catches any drift between the spec and
Laravel's actual responses.

## Consequences

Positive:
- The right contract at the right boundary: a language-neutral OpenAPI `/api/v1` across services (PHP, Python, mobile), and compiler-checked TypeScript types **within** the web + SDK surfaces.
- The SDK regenerated from OpenAPI keeps the web client honest against the real API; a breaking backend change surfaces as a TypeScript build failure in the same repo, and as a contract-test failure on the backend.
- Zod in `packages/types` gives one definition for both runtime validation and the static type on the client, removing the classic validator/type gap.
- No cross-language illusion: the docs never pretend a TypeScript type binds the PHP backend or the Python engine, so the actual enforcement mechanism (contract tests on `/api/v1`) is never quietly skipped.

Negative / trade-offs:
- The SDK must be regenerated when the OpenAPI spec changes, and the spec must be kept truthful to Laravel's responses by contract tests — a discipline, not an automatic guarantee.
- TypeScript type-safety stops at the API boundary; the FastAPI engine and mobile clients rely on the runtime `/api/v1` contract and its versioning, not on compiler checks.
- Maintaining `packages/types` and `packages/sdk` as the sole source of TS wire shapes adds up-front ceremony a single loosely-typed app would skip.

## Alternatives considered

- **Make TypeScript the end-to-end contract across all three services:** impossible and misleading here — the backend is PHP and the AI engine is Python, so no shared compiled type can bind them; the cross-service contract must be the language-neutral `/api/v1`.
- **Duplicate types per TypeScript surface:** fastest locally, but guarantees drift between the web app and the SDK — the exact failure a single shared `packages/types`/`packages/sdk` exists to prevent.
- **Hand-write the API client instead of generating the SDK from OpenAPI:** works briefly, but the client rots against the real contract; generating the SDK from the OpenAPI spec keeps it aligned with the backend by construction.

## Related

- [../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md) — the packages and the `/api/v1` contract.
- [ADR-0001](./0001-monorepo-structure.md), [ADR-0002](./0002-nextjs-for-web.md), [ADR-0003](./0003-supabase-as-backend.md), [ADR-0006](./0006-event-driven.md), [ADR-0007](./0007-ai-orchestrator.md)
- Design target: [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — ADR-003, ADR-008, ADR-009; [../../foundation/CODING_STANDARDS.md](../../foundation/CODING_STANDARDS.md)
