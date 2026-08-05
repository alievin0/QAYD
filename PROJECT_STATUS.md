# QAYD — Project Status

The single dashboard for the whole project. Anyone opening this repository can read
this file and know exactly where QAYD stands and what the next step is, without
reading dozens of documents.

---

| Field | Value |
|---|---|
| **Status** | **Sprint 2 (Accounting core) IN PROGRESS** — **S2-01 → S2-13 COMPLETE & CLOSED** (tags `s2-01-complete` … `s2-13-complete`). Base `v0.1.0`. |
| **Documentation** | Frozen (`architecture-freeze-v1`); two build decisions since: [ADR-0010](docs/architecture/adr/0010-auth-service-authoritative-for-identity-schema.md) and [ADR-0011](docs/architecture/adr/0011-direct-refresh-broadcasts-pending-outbox.md) (amends ADR-0006 — refresh-only broadcasts may skip the transactional outbox until it exists; TD-30 carries the migration). The Phase 1–3 research corpus (`docs/research/`, `docs/architecture/knowledge/`) is **frozen reference material** — it governs nothing and never overrides an ADR. |
| **Phase** | Build — Sprint 2 (Accounting core): S2-01 (`accounts` schema) + S2-02 (COA API) + S2-03 (journal schema + immutability triggers) + S2-04 (journal draft lifecycle: models/DTOs/actions, optimistic concurrency) + S2-05 (posting engine: `PostingService`, fiscal-calendar seam, `ledger_entries` projection, permanent numbering) + S2-06 (reverse & void: mirror entry + two-way linkage, one-reversal DB guard, segregation of duties) + S2-07 (fiscal periods: month-level calendar, close/lock/reopen, period-backed posting gate) + S2-08 (general ledger reads: account activity with a running balance, cursor-paginated) + S2-09 (trial balance: live compute + immutable snapshot, first tenant-scoped queue worker) + S2-10 (chart-of-accounts screen) + S2-11 (journal-entry editor) + S2-12 (trial-balance screen: period selector, computed table, snapshot generation) + S2-13 (idempotency + the posted-entry Reverb broadcast: `private-company.{uuid}` refresh notifications, company-scoped channel authorization) done |
| **Current Sprint** | **Sprint 2 (Accounting core)** — S2-01 → S2-13 complete (**13 of 14 stories closed · 66 of 69 story points**); next: S2-14 (Nightly ledger integrity job) |
| **Current Version** | v0.1.0 |
| **Next Milestone** | S2-14 — the nightly `maintenance`-queue job that rebuilds `ledger_entries` from posted `journal_lines` in a scratch space and asserts identical balances and trial balance, with a seeded drift detected. Every dependency exists (`RunsInTenantContext` for per-company `SET LOCAL`, `LedgerQueryService`, `TrialBalanceService`); three things need deciding first: what "alerts" means (there is no operational alerting anywhere yet), that this would be the project's **first scheduled task** (`routes/console.php` has no `Schedule::` entries), and that the rebuild must stay in a scratch space because `trg_ledger_entries_append_only` refuses UPDATE/DELETE even to the schema owner. **This is the last story in Sprint 2.** + deferred: ledger-backed guard (TD-11, unblocked), `SetOpeningBalanceAction` (TD-10, unblocked), fiscal-year lifecycle + year↔period status coupling (TD-20/TD-21), the INSERT-into-posted / header-immutability ADRs |
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
