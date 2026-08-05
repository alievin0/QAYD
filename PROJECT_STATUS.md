# QAYD — Project Status

The single dashboard for the whole project. Anyone opening this repository can read
this file and know exactly where QAYD stands and what the next step is, without
reading dozens of documents.

---

| Field | Value |
|---|---|
| **Status** | **✅ SPRINT 2 (Accounting core) COMPLETE** — **all 14 stories closed** (tags `s2-01-complete` … `s2-14-complete`, contiguous). Base `v0.1.0`; not yet merged or re-tagged. |
| **Documentation** | Frozen (`architecture-freeze-v1`); two build decisions since: [ADR-0010](docs/architecture/adr/0010-auth-service-authoritative-for-identity-schema.md) and [ADR-0011](docs/architecture/adr/0011-direct-refresh-broadcasts-pending-outbox.md) (amends ADR-0006 — refresh-only broadcasts may skip the transactional outbox until it exists; TD-30 carries the migration). The Phase 1–3 research corpus (`docs/research/`, `docs/architecture/knowledge/`) is **frozen reference material** — it governs nothing and never overrides an ADR. |
| **Phase** | Build — Sprint 2 (Accounting core): S2-01 (`accounts` schema) + S2-02 (COA API) + S2-03 (journal schema + immutability triggers) + S2-04 (journal draft lifecycle: models/DTOs/actions, optimistic concurrency) + S2-05 (posting engine: `PostingService`, fiscal-calendar seam, `ledger_entries` projection, permanent numbering) + S2-06 (reverse & void: mirror entry + two-way linkage, one-reversal DB guard, segregation of duties) + S2-07 (fiscal periods: month-level calendar, close/lock/reopen, period-backed posting gate) + S2-08 (general ledger reads: account activity with a running balance, cursor-paginated) + S2-09 (trial balance: live compute + immutable snapshot, first tenant-scoped queue worker) + S2-10 (chart-of-accounts screen) + S2-11 (journal-entry editor) + S2-12 (trial-balance screen: period selector, computed table, snapshot generation) + S2-13 (idempotency + the posted-entry Reverb broadcast: `private-company.{uuid}` refresh notifications, company-scoped channel authorization) + S2-14 (nightly ledger integrity job: scratch-space rebuild from posted journals, drift detection, first scheduled task) done — **the accounting core runs end to end** |
| **Current Sprint** | **Sprint 2 (Accounting core) — COMPLETE** (**14 of 14 stories closed · 69 of 69 story points**). Branch `sprint-02-accounting-core`, not yet merged to `main`. |
| **Current Version** | v0.1.0 |
| **Next Milestone** | **Sprint 3 — awaiting the architecture owner's authorization.** `SPRINT_03.md` deliberately not yet read. Three decisions are worth taking before its code, all identified during Sprint 2: **TD-30 now has a deadline** — ADR-0011 forbids a durable consumer from subscribing to a direct broadcast, so the transactional-outbox story belongs *before* the AI ingestion path (S3-07); the **AI database boundary** still needs an ADR and was flagged as needing settling before S3-07 writes transport code; and **AD-11 dimensions-as-rows** contradicts the frozen TD-14 with no ADR yet — free to decide now, a migration on the largest table later. Also outstanding from Sprint 2: ledger-backed guard (TD-11, unblocked), `SetOpeningBalanceAction` (TD-10, unblocked), fiscal-year lifecycle + year↔period status coupling (TD-20/TD-21 — a company reaching FY2027 has no supported path), the INSERT-into-posted / header-immutability ADRs. **Operational:** deployment needs a worker on the new `maintenance` queue, or the nightly integrity check queues jobs nobody runs — which looks exactly like a passing check. |
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
