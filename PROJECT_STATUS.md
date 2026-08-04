# QAYD — Project Status

The single dashboard for the whole project. Anyone opening this repository can read
this file and know exactly where QAYD stands and what the next step is, without
reading dozens of documents.

---

| Field | Value |
|---|---|
| **Status** | **Sprint 2 (Accounting core) IN PROGRESS** — **S2-01 → S2-10 COMPLETE & CLOSED** (tags `s2-01-complete` … `s2-10-complete`). Base `v0.1.0`. |
| **Documentation** | Frozen (`architecture-freeze-v1`); one build decision since: [ADR-0010](docs/architecture/adr/0010-auth-service-authoritative-for-identity-schema.md). The Phase 1–3 research corpus (`docs/research/`, `docs/architecture/knowledge/`) is **frozen reference material** — it governs nothing and never overrides an ADR. |
| **Phase** | Build — Sprint 2 (Accounting core): S2-01 (`accounts` schema) + S2-02 (COA API) + S2-03 (journal schema + immutability triggers) + S2-04 (journal draft lifecycle: models/DTOs/actions, optimistic concurrency) + S2-05 (posting engine: `PostingService`, fiscal-calendar seam, `ledger_entries` projection, permanent numbering) + S2-06 (reverse & void: mirror entry + two-way linkage, one-reversal DB guard, segregation of duties) + S2-07 (fiscal periods: month-level calendar, close/lock/reopen, period-backed posting gate) + S2-08 (general ledger reads: account activity with a running balance, cursor-paginated) done |
| **Current Sprint** | **Sprint 2 (Accounting core)** — S2-01 → S2-10 complete (**10 of 14 stories closed · 52 of 69 story points**); next: S2-11 (Journal-entry editor) |
| **Current Version** | v0.1.0 |
| **Next Milestone** | S2-11 — Journal-entry editor (line grid, client-side `deriveBalance` mirroring the backend check, draft save, post with `Idempotency-Key`); + deferred: `accounts.allow_posting` (TD-15 — S2-11 needs it), ledger-backed guard (TD-11, unblocked), `SetOpeningBalanceAction` (TD-10, unblocked), fiscal-year lifecycle + year↔period status coupling (TD-20/TD-21), the INSERT-into-posted / header-immutability ADRs |
| **Architecture Owner** | Ali S — Founder |
| **Governing Stack** | [docs/architecture/FINAL_TECH_STACK.md](docs/architecture/FINAL_TECH_STACK.md) (Option A · Locked) |
| **Last Updated** | 2026-08-04 |

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
