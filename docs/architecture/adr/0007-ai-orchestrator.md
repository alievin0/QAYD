# ADR-0007: Route all AI through a dedicated Orchestrator with a human-in-the-loop safety contract

Status: Accepted

Date: 2026-07

## Context

QAYD is an AI Financial Operating System — an AI workforce that continuously determines what should happen next
in the books and prepares it. The defining risk is an AI that acts as an unaudited writer at machine speed: a
model with a path to the database, or the authority to commit financial facts unsupervised, could corrupt the
ledger faster than any human could catch it. The design specification
([../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) ADR-007;
[../../foundation/SYSTEM_ARCHITECTURE.md](../../foundation/SYSTEM_ARCHITECTURE.md)) fixes the rule: **the AI
never edits the database directly, and every AI action passes through the backend's validation and
permissions.** The AI capability is large — extraction, drafting, reconciliation suggestions, retrieval over
per-tenant memory, multiple agents — so it needs one place that owns AI behavior, tool access, memory, and,
above all, the safety contract. Ad-hoc model calls scattered through the code make that contract impossible to
enforce consistently.

## Decision

All AI capability lives in a single **AI Orchestrator inside the FastAPI AI engine** (`apps/ai`,
[ADR-0001](./0001-monorepo-structure.md)) — a **separate Python service**, not a TypeScript package. The
Orchestrator owns model selection, the agent/tool registry, retrieval over per-tenant memory (pgvector,
[ADR-0004](./0004-postgresql.md)), and event ingestion ([ADR-0006](./0006-event-driven.md)). The engine is
**invoked only by Laravel over HTTP and via the queue** (mTLS + an internal token); it holds **no tenant
database credential** and has no direct path to Postgres. Domain events reach it through a Laravel relay after
commit; its outputs return to Laravel and are written **only** through the backend's governed proposal
endpoint. Its safety contract is non-negotiable:

1. **Never auto-commit.** Every AI action — from routine categorization to a large payment draft — is a
   **proposal** submitted back through Laravel's API surface, validation, and RBAC, the same front door a human
   action faces ([ADR-0003](./0003-supabase-as-backend.md), [ADR-0005](./0005-multitenancy-rls.md)). The AI can
   knock only as hard as its permission grant allows, and it cannot write to the database itself.
2. **Confidence + reasoning always attached.** Every AI-originated number, suggestion, or flag carries its
   confidence score and its reasoning, rendered wherever it appears.
3. **Human-in-the-loop for sensitive classes.** Any action touching money, tax, payroll, or posted data
   renders an explicit approve/reject/delegate affordance; the system auto-commits a human's **decision** about
   a proposal, never the proposal itself. A fixed sensitive-operations list stays irreducibly human-approved
   and does not shrink as autonomy expands.

## Consequences

Positive:
- There is no path by which AI corrupts the ledger unsupervised — the engine has no DB credential, and its only write path is Laravel's governed endpoint, constrained by the identical auth, validation, and permission machinery as any client, plus an approval gate on top.
- Isolating AI in a Python service is the natural home for the ML/agent ecosystem (model SDKs, OCR/extraction, embeddings) and keeps long-running or GPU-shaped work off the Laravel request path.
- Every AI decision is inspectable (confidence + reasoning), reversible, and recorded; learning is per-tenant via retrieved corrections, so one company's data never shapes another's model behavior.
- One Orchestrator means one place to change models, add tools, set budgets, and audit behavior — not scattered SDK calls with drifting guardrails.

Negative / trade-offs:
- A separate service adds a network hop and transport surface (mTLS, internal token, contract fixtures) between Laravel and FastAPI that must be built and kept in sync.
- Even high-confidence routine proposals incur a human approval step for sensitive classes, capping how much throughput full autonomy can reach — accepted deliberately as the price of trust.
- The Orchestrator is a critical dependency and a potential bottleneck; it must be observable and resilient, and its tool registry is a security surface to guard.

## Alternatives considered

- **Ad-hoc model calls per feature:** fastest initially, but the safety contract cannot be enforced uniformly, memory and tools fragment, and auditing AI behavior becomes impossible — unacceptable for financial actions.
- **A TypeScript orchestrator inside the monorepo (e.g. `packages/ai`), or AI logic embedded in Laravel:** rejected. The AI engine is a distinct Python/FastAPI service ([../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md)) so the ML ecosystem lives where it is strongest and heavy AI work stays off the PHP request path; a TypeScript package could not house the Python agent stack and would blur the service boundary that keeps the engine credential-less.
- **Let AI write directly for high-confidence, low-risk actions:** more throughput, but it breaks the "same front door" invariant and creates an unaudited write path; the engine deliberately holds no DB credential and the fixed sensitive list exists precisely to forbid this.

## Related

- [../FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md) — the AI engine (`apps/ai`) role.
- [ADR-0001](./0001-monorepo-structure.md), [ADR-0003](./0003-supabase-as-backend.md), [ADR-0004](./0004-postgresql.md), [ADR-0005](./0005-multitenancy-rls.md), [ADR-0006](./0006-event-driven.md)
- Design target: [../../developer/ARCHITECTURE_DECISIONS.md](../../developer/ARCHITECTURE_DECISIONS.md) — ADR-007; [../../foundation/AI_ARCHITECTURE.md](../../foundation/AI_ARCHITECTURE.md)
