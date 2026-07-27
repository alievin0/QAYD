# QAYD — Project Status

The single dashboard for the whole project. Anyone opening this repository can read
this file and know exactly where QAYD stands and what the next step is, without
reading dozens of documents.

---

| Field | Value |
|---|---|
| **Status** | **Sprint 1 (Foundations) delivered** — DoD verified, CI green on `sprint-01-foundations`; pending review + merge to `main` |
| **Documentation** | Frozen (`architecture-freeze-v1`); one build decision since: [ADR-0010](docs/architecture/adr/0010-auth-service-authoritative-for-identity-schema.md) |
| **Phase** | Build — Sprint 1 complete (monorepo + CI, PostgreSQL RLS multi-tenancy, auth/RBAC, create-company, app shell + EN/AR RTL) |
| **Current Sprint** | Sprint 1 done → **Sprint 2 (Accounting core)** next |
| **Current Version** | v0.1.0 |
| **Next Milestone** | Sprint 2 — Accounting core: Chart of Accounts, journal entries, posting engine, General Ledger, Trial Balance |
| **Architecture Owner** | Ali S — Founder |
| **Governing Stack** | [docs/architecture/FINAL_TECH_STACK.md](docs/architecture/FINAL_TECH_STACK.md) (Option A · Locked) |
| **Last Updated** | 2026-07-27 |

---

## The stack (locked)

Next.js 15 / React 19 / TypeScript · Tailwind + shadcn/ui · **Laravel 12 (backend / domain layer)** ·
FastAPI (AI engine) · PostgreSQL (single DB + RLS) · Laravel Sanctum + RS256 JWT · Redis (cache + queue) ·
Supabase Storage · Reverb realtime · Docker. See [FINAL_TECH_STACK.md](docs/architecture/FINAL_TECH_STACK.md) — it wins any conflict.

## Where to start

1. **What & why** — [docs/execution/MASTER_PRD.md](docs/execution/MASTER_PRD.md) (the source of truth) and the 15 chapters in [docs/execution/prd/](docs/execution/prd/).
2. **How we build** — [docs/execution/FEATURE_ROADMAP.md](docs/execution/FEATURE_ROADMAP.md), [MVP_SCOPE.md](docs/execution/MVP_SCOPE.md), the sprint plans [SPRINT_01–04](docs/execution/), and the decisions in [docs/architecture/adr/](docs/architecture/adr/).
3. **Sprint 1** — see [docs/execution/SPRINT_01.md](docs/execution/SPRINT_01.md).

## Working rule (from here on)

- **No code without a spec.**
- **No new spec unless development reveals a real need.**

This avoids both traps: endless documentation, and undirected coding.

---

*The blueprint phase is complete. Success from here is not measured in files — it is measured by
shipping a first working version in the browser and building on it, sprint after sprint.*
