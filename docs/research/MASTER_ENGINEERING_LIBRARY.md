# QAYD Master Engineering Library

**The permanent engineering encyclopedia. Consult this before implementing anything.**

Version 1.0 · 2026-07-28
Covers Phases 1–3 of the Architecture Intelligence Initiative — roughly **45,000 lines** of research
across 22 documents and 10 domain folders.

---

## 0 · The rule

> **Before any story is implemented, the owning engineer reads the relevant entries here.**
>
> The purpose of this corpus is that QAYD decides from **first principles and prior evidence**, not by
> re-researching a problem another company solved a decade ago — and not by assuming.

If a question is not answered here, that is itself useful information: the question is genuinely open,
and belongs in the open-questions register rather than being answered by intuition.

---

## 1 · Where everything lives

### Layer 1 — Standing guidance (read first, changes rarely)

`docs/architecture/knowledge/` — **19,857 lines**, the engineering knowledge base.

| Doc | Use it when |
|---|---|
| `01_ENGINEERING_PRINCIPLES.md` | *Why does QAYD do things this way?* 23 principles, each with its enforcement mechanism and a register of where enforcement is missing |
| `02_ARCHITECTURE_DECISIONS.md` | *Why X over Y, and when do we revisit?* AD-01…AD-21, plus 10 deliberately open decisions |
| `03_DESIGN_PATTERNS.md` | *How do I build a new financial subsystem?* P-01…P-19 with reference implementations |
| `04_REJECTED_PATTERNS.md` | *Is this forbidden, and why?* R-01…R-34 with the mechanism of harm and a symptom→rejection lookup |
| `05_FUTURE_ARCHITECTURE.md` | *Will this survive 10,000 customers?* Five scale tiers with trigger metrics |
| `06_COMPETITIVE_ANALYSIS.md` | *How do we compare?* Eight systems scored per subsystem |
| `07_QAYD_INNOVATION.md` | *What should we invent?* I-01…I-20, market-checked |
| `08_MASTER_BACKLOG.md` | *What next, and what depends on it?* Everything triaged onto the real sprint plan |
| `09_ENGINEERING_PLAYBOOK.md` | *I'm new here.* Mandatory onboarding |

### Layer 2 — Domain research (read when you touch that domain)

`docs/research/` — **~25,800 lines** across ten folders, each with the same seven files
(`README` · `OVERVIEW` · `BEST_PRACTICES` · `ANTI_PATTERNS` · `ARCHITECTURE` · `LESSONS_FOR_QAYD` ·
`IMPLEMENTATION_RECOMMENDATIONS`).

| Folder | Read before building | Headline conclusion |
|---|---|---|
| `erp/` | Any accounting subsystem | Sage Intacct's reputation is **ergonomic, not structural** — QAYD's dimensional gap is UX, not schema |
| `accounting/` | Anything customer-facing | The bank-feed loop is **unavailable in Kuwait**; real competitors are Wafeq/Qoyod/Daftra, not QuickBooks |
| `payments/` | Idempotency, reconciliation, webhooks | The specified idempotency design has a **dual-write hole**; S2-13 is 8 points, not 3 |
| `banking/` | Ledger integrity, audit | Most core-banking ledgers are **not** immutable — QAYD is ahead; the gap is *proof*, not immutability |
| `analytics/` | Reporting, aggregates | Choose **Parquet** for cold partitions and internal analytics is free; QAYD's ledger is structurally an Iceberg table, and ahead of one |
| `ai/` | The entire AI engine | **Do not build an agent.** Build a deterministic proposal pipeline where code chooses control flow |
| `security/` | Anything touching tenancy or audit | Close the `audit_logs` write-hatch **before** the hash chain — chaining a forgeable table is worse than no chain |
| `innovation/` | Product strategy | Extends I-01…I-20; contains the AI-finance ideas that sound good and are bad |
| `competitive/` | Positioning, pricing, GTM | Cross-category view — accounting SaaS, spend platforms, AI bookkeeping startups |
| `architecture/` | Any frontend work | Keyboard-first UX is the highest-leverage area and the one SME accounting products neglect |

Plus, at top level: `QAYD_UNFAIR_ADVANTAGES.md` · `GLOBAL_GAP_ANALYSIS.md` · `WORLD_CLASS_FEATURES.md` ·
this file.

### Layer 3 — Primary source study

`docs/research/odoo/` — **15,261 lines**. Odoo 19.0 at commit `f3e407c6`, LGPL-3, studied exhaustively.
Grep it for specifics; do not read it end to end.

---

## 2 · The durable conclusions

If the rest of this corpus were lost, these are the findings worth keeping. Each is evidence-backed, and
each changed a decision.

### On the ledger

1. **Append-only is rarer than the industry implies, and it is QAYD's foundation.** Only one
   core-banking vendor studied publicly claims ledger immutability. Odoo, Mambu and Temenos all permit
   mutation. Every downstream advantage — monotonic rollups, a never-stale hash chain, non-mutating
   reconciliation, partitioning, time travel — is a dividend of that one decision.
2. **Never store derived or mutable state on a ledger row.** Odoo put `amount_residual` there; that
   single choice forced its general ledger to be mutable, drove its raw-SQL writes, and produced a
   documented staleness bug. Reconciliation state belongs in side tables.
3. **The gap is proof, not immutability.** Control totals plus re-derivation (~3 points) catch the
   failures that actually occur — projection bugs, partial posts, double-posts, corrupt rollups — better
   than a 21-point hash chain, which defends a rarer threat.
4. **A per-row CHECK is not a chain check.** `closing = opening + debit − credit` validates each row in
   isolation; a faulty cross-period rebuild can leave every row valid while breaking the opening→closing
   chain between periods. Assert the chain explicitly.

### On integrity

5. **PostgreSQL referential-integrity checks bypass RLS.** A foreign key is not tenant-constrained unless
   it is composite with `company_id`. No conventional tenancy test detects this, because the boundary
   holds for every query and fails only at the constraint layer.
6. **An invariant a caller can switch off is not an invariant.** Odoo's context-gated validations
   (`bypass_lock_check`, `skip_readonly_check`, `force_delete`) are the canonical failure. Use deferred
   constraint triggers.
7. **Enforce at the layer that cannot be bypassed.** Application code runs only when your code runs — not
   for a backfill, a queue worker written next year, or a `psql` session during an incident.

### On money

8. **Exact arithmetic end to end, and zero tolerance.** OFBiz hard-codes a `0.01` balance tolerance, so a
   **0.009 KWD imbalance posts successfully** — invisible to two-decimal testing. Odoo validates balance
   in company currency only. Both are avoidable by construction.
9. **Never default a missing exchange rate.** Odoo falls back to the earliest known rate, then to `1.0`,
   converting at par silently. Raise instead.
10. **Check the convention before porting a formula.** Odoo's rate convention is the **inverse** of
    QAYD's `base = amount × rate`.

### On AI

11. **Build a proposal pipeline, not an agent.** The model is a pure function — untrusted tokens in,
    typed proposal out — and code alone chooses control flow. DeepMind's CaMeL pays ~7pp of utility to
    manufacture that property; QAYD gets it free because bookkeeping's task list is enumerable.
12. **Enforce the AI boundary with database grants, not prompt design.** A process without a driver
    cannot write. That is provable; an instruction is not.
13. **Multi-agent is usually complexity theatre here.** Anthropic measured roughly **15× the tokens** and
    explicitly named dependency-dense, shared-context work — precisely what accounting is — as the
    poor-fit case. Prefer N capability configurations on one runtime.
14. **The competitive opening is accountability, not accuracy.** Xero's customers complain its
    auto-reconcile leaves **no auditable human-reviewed state**. On accuracy: the DualEntry 2026
    accounting benchmark was cited at **66.0%** earlier in 2026 and at **83.2%** later in this research
    — ⚠️ **verify before citing either.** Even the higher figure is roughly **one task in six wrong**,
    which in bookkeeping misstates the books rather than degrading a result. Design for the model being
    wrong; accountability is the durable differentiator, not accuracy.
15. **Both halves of a naive AI pipeline fail silently and confidently.** Speech recognition responds to
    Gulf code-switching by translating into MSA — right dialect, wrong task. Text-to-SQL returns valid
    wrong numbers (GPT-4o ≈ **0%** end-to-end on real enterprise warehouses, per BEAVER). The one
    intervention with strong paired evidence — a semantic layer, **+17 to +70pp** — works partly because
    it converts silent wrong answers into explicit refusals.

### On architecture

16. **Introduce a seam only where a subsystem will genuinely be replaced.** `FiscalCalendarResolver` is
    the proven case; it let the posting engine ship before fiscal periods existed.
17. **Do not build a generic workflow engine.** Odoo built one and deleted it. Explicit statuses,
    explicit Actions, DB-enforced transitions.
18. **Cross-module communication is by after-commit domain event only.** Odoo's absence of an event bus
    is why its modules are inseparable after twenty years.
19. **Lock the narrowest thing that needs it.** QAYD locks a fiscal-year row and serializes every posting
    in a company-year; Odoo takes no calendar lock at all. Serialize the sequence, not the calendar.
20. **Build two concrete instances before extracting an engine.** Building Odoo's declarative report
    engine first is how it ended up with a regex formula grammar.

### On strategy

21. **Kuwait is structurally unserved, and durably so.** No open-banking rail, no aggregator, no bank
    feeds. Statement ingestion *is* the product; per-bank adapters are the moat.
22. **The home market has the weakest forcing function in the region** (no VAT before 2028). Adoption
    must be earned on labour saved, not obligation met.
23. **Tax is an expansion gate, not a launch gate** — ZATCA and UAE VAT matter; Kuwait does not yet.
24. **Decide dimension storage before any allocation data exists.** Rows, carrying resolved money, with
    percentages on a named reusable allocation policy — three unrelated systems converge on this.

---

## 3 · How to use this before a sprint story

```
                    ┌─────────────────────────────────┐
                    │  About to implement a story?    │
                    └────────────────┬────────────────┘
                                     v
              ┌──────────────────────────────────────────────┐
              │ 1. 08_MASTER_BACKLOG.md — find the story      │
              │    It names the constraints research imposes  │
              └────────────────┬─────────────────────────────┘
                               v
              ┌──────────────────────────────────────────────┐
              │ 2. 04_REJECTED_PATTERNS.md — symptom lookup   │
              │    Am I about to violate a rejection?         │
              └────────────────┬─────────────────────────────┘
                               v
              ┌──────────────────────────────────────────────┐
              │ 3. 03_DESIGN_PATTERNS.md — which patterns?    │
              └────────────────┬─────────────────────────────┘
                               v
              ┌──────────────────────────────────────────────┐
              │ 4. docs/research/<domain>/LESSONS_FOR_QAYD.md │
              │    What did the industry already learn here?  │
              └────────────────┬─────────────────────────────┘
                               v
              ┌──────────────────────────────────────────────┐
              │ 5. 02_ARCHITECTURE_DECISIONS.md               │
              │    Does this contradict a settled decision?   │
              │    If yes → ADR required (MANIFEST Law 1)     │
              └──────────────────────────────────────────────┘
```

---

## 4 · Open defects and decisions carried by this corpus

**Seven defects verified in shipped code. None fixed — documentation only.** Full detail in
`08_MASTER_BACKLOG.md` Tier 1.

| # | Defect | Effort |
|---|---|---|
| 1 | `trg_no_ai_autopost` guards `INSERT` but not `UPDATE` — an AI draft can be updated to posted | 2 |
| 2 | `audit_logs` RLS permits a platform admin to author cross-tenant audit rows | 3 |
| 3 | `ledger_entries` append-only rests on one trigger; grants and vestigial policies remain | 1 |
| 4 | Posting locks the fiscal-year row, serializing every concurrent post in a company-year | 3 |
| 5 | Foreign keys are not tenant-composite; RI checks bypass RLS | 5 |
| 6 | Idempotency design has a dual-write hole; line currency has no DB tie to its parent | 8 |
| 7 | `reconciled` / `reconciled_at` are written by nothing and unwritable once posted | drop |

**Decisions requiring an ADR before implementation** (each contradicts a frozen spec, and MANIFEST Law 1
applies — *new ADR → update the doc → continue*):

- **AD-11** — analytic dimensions as rows, carrying resolved money (contradicts TD-14's fixed columns)
- **The AI database boundary** — `01`'s "no driver" versus `03`'s `qayd_ai` role. Both are defensible;
  they cannot both be built. Must be settled **before Sprint 3 writes transport code.**
- **Idempotency** — storing the key in the same transaction as the fact contradicts the written spec

---

## 5 · What this corpus does not know

Stated so nobody fills these in from memory.

- **Xero's profile is thinner than the others** — a research budget ran out mid-pass. Twelve open
  questions are enumerated in `accounting/OVERVIEW.md` §10.
- **Proprietary internals** — SAP, NetSuite, Dynamics F&O, Temenos, FIS, Fiserv, Infor, Epicor. Claims
  about them are `[DOCS]` or `[UNKNOWN]`, never inferred architecture.
- **Five research questions remain open**: cross-tenant aggregate learning safety; pgvector placement
  *(partially answered — stays in the primary database, conditional on never embedding raw document
  text)*; GCC e-invoicing timelines; the AI liability model; per-tenant restore on a shared database.
- **No production data exists.** Every scaling number is derived from stated assumptions, not measured.

---

## 6 · Keeping it true

A stale reference is worse than none, because people trust it.

- When a story completes → mark it in `08_MASTER_BACKLOG.md`.
- When a decision is made → add it to `02_ARCHITECTURE_DECISIONS.md`; mark superseded entries, never
  delete them.
- When a pattern is refused → add it to `04_REJECTED_PATTERNS.md` with the *mechanism* of harm.
- When a defect above is fixed → strike it here and in the backlog.
- When something marked `[UNKNOWN]` becomes known → fill it in and cite the source.

**Every document in this corpus states plainly where QAYD is weaker than it should be. Keep that
honesty.** The gap registers are the most useful content in the library, and they are useful only while
they are accurate.

---

*No code from any studied system is reproduced anywhere in this corpus. Citations locate claims so they
can be verified; every schema, constraint, Action, DTO and exception proposed is an original QAYD design.*
