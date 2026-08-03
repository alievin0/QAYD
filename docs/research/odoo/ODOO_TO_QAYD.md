# ODOO_TO_QAYD.md

**Feature-by-feature mapping: what Odoo does → what QAYD should do**

Version: 1.0 · Date: 2026-07-28 · Companion to `ODOO_LEARNING.md` and `ODOO_BACKLOG.md`
Source studied: Odoo 19.0.0, commit `f3e407c6`, LGPL-3. **No code copied — ideas only.**

---

## How to read this

Each entry follows the same five-step chain:

> **Odoo feature** → **Equivalent QAYD feature** → **Reuse ideas** → **New architecture** → **Expected improvements**

"Equivalent QAYD feature" states honestly whether the thing **already exists**, is **planned**, or is
**new**. Where QAYD already has a stronger design, the entry says *keep what you have* rather than
manufacturing a change — a mapping document that only ever recommends changes is a sales pitch, not
engineering.

**Legend:** ✅ already built · 🔨 planned/in backlog · 🆕 new idea from this research · ⚠️ QAYD defect found

---

# Part 1 — Core ledger

## 1.1 Journal entries as a unified document

| | |
|---|---|
| **Odoo feature** | `account.move` + `account.move.line` model *every* financial document — invoice, bill, payment, and manual entry are all the same table with a `move_type` discriminator (`account_move.py:143-159`). One posting path serves all of them. |
| **QAYD equivalent** | ✅ `journal_entries` + `journal_lines` with an `entry_type` enum (17 values) and a single `PostingService`. |
| **Reuse ideas** | The core insight — *one document model, one posting path, discriminated by type* — is already QAYD's design and is validated by Odoo's twenty-year run. Also worth taking: aggregating **all** validation failures into one exception (`account_move.py:5643`) rather than failing on the first, which matters enormously for AI round-trips where an agent should fix everything in one pass. |
| **New architecture** | Keep `journal_entries`/`journal_lines` as-is. Add a `ValidationReport` DTO that accumulates every rule violation and is returned as a single 422 with a `violations[]` array, instead of throwing on the first failure. |
| **Expected improvements** | An AI agent drafting an entry gets a complete fix-list in one round trip instead of N. Reject Odoo's `display_type` overloading (`account_move_line.py:329-348`), which stuffs section headers and notes into the *line* table — presentation rows in a financial table are a permanent source of `WHERE display_type IS NULL` bugs. |

## 1.2 The general ledger

| | |
|---|---|
| **Odoo feature** | **There is no ledger table.** Journal lines *are* the general ledger; every GL read is `account_move_line` filtered on `parent_state = 'posted'`. Balances are computed by scanning. |
| **QAYD equivalent** | ✅ `ledger_entries` — an append-only projection, one row per posted line, carrying `signed_base_amount`, `UNIQUE(journal_line_id)`, and a trigger rejecting UPDATE/DELETE even for the owner role. |
| **Reuse ideas** | Odoo's `parent_state` denormalisation (so GL queries never join to the header) is a good trick — but QAYD does not need it, because `ledger_entries` is posted-by-construction. **Do not add a `status` column to the projection.** |
| **New architecture** | Keep the projection. Explicitly **reject** Odoo's placement of reconciliation state (`amount_residual`, `reconciled`, `full_reconcile_id`) *on the ledger row* — that single decision is what forces Odoo's ledger to be mutable and is the root cause of its raw-SQL writes. QAYD puts matching state in side tables keyed to `ledger_entry_id`. |
| **Expected improvements** | An immutable GL Odoo cannot offer; the ability to partition `ledger_entries` by `(company, period)` — impossible in Odoo because its ledger doubles as the mutable invoice-line table; and a hash chain that is cheap precisely because the table is append-only. |

## 1.3 Balance enforcement

| | |
|---|---|
| **Odoo feature** | Balance is asserted by a Python context manager around write operations (`account_move.py:2765-2804`), **in the company currency only**. A CHECK on cached header totals is defence-in-depth. When an entry does not balance, Odoo can insert an **auto-balancing suspense line** (`account_move.py:3188-3230`). |
| **QAYD equivalent** | ✅ `PostingService.assertBalanced()` — re-derived from the lines with **zero tolerance in both the entry currency and the base currency**, plus an unconditional `chk_je_balanced` DB CHECK. |
| **Reuse ideas** | None needed — QAYD's is strictly stronger. Odoo validating in company currency only is a real defect: foreign-currency sub-ledgers can drift undetected. |
| **New architecture** | Keep zero tolerance. **Reject the auto-balancing plug** as a default: an unbalanced entry is a data-quality signal, not something to silently absorb. Where a plug is legitimately needed (opening balances), make it an explicit, acknowledged suspense line with the residual surfaced in the DTO before posting. |
| **Expected improvements** | Multi-currency correctness Odoo does not have; no silent absorption of user error; and — because QAYD never floats — no need for Odoo's `is_zero()` epsilon machinery to paper over accumulated float drift. |

## 1.4 Entry numbering

| | |
|---|---|
| **Odoo feature** | A sequence mixin using a b-tree lock trick (`sequence_mixin.py:355-425`) that **skips numbers** under concurrency and on rollback. Gaps are detected and reported after the fact. |
| **QAYD equivalent** | ✅ `JournalNumberAllocator` — atomic upsert-increment on `journal_number_sequences`, gapless per `(company, fiscal_year, entry_type)`. |
| **Reuse ideas** | Study, do not copy. Odoo's approach optimises for throughput and accepts gaps; QAYD's regulatory posture (GCC audit) wants gaplessness. Odoo's *gap-detection report* is worth having regardless, as a verification tool. |
| **New architecture** | Keep the allocator. ⚠️ **Scope the posting lock to the sequence row, not the fiscal-year row** (see 2.2). Add a `VerifyNumberSequenceAction` that asserts no gaps and no duplicates per scope, run in CI and as a scheduled production job. |
| **Expected improvements** | Gapless numbering *and* concurrent posting throughput — currently QAYD has the former at the cost of the latter. |

---

# Part 2 — Fiscal calendar and period control

## 2.1 Fiscal years and periods — the central decision

| | |
|---|---|
| **Odoo feature** | **No fiscal-year table. No fiscal-period table.** Years are computed from `company.fiscalyear_last_month`/`fiscalyear_last_day`; period control is a set of **lock dates** — a moving date cursor across five axes (global, sale, purchase, tax, hard). |
| **QAYD equivalent** | ✅ `fiscal_years` (with a GiST no-overlap exclusion constraint) · 🔨 `fiscal_periods` planned for S2-07 · ✅ a `FiscalCalendarResolver` seam already isolating the posting engine from either choice. |
| **Reuse ideas** | The lock-date cursor is genuinely **superior for enforcement**: O(1), no rows to maintain, no lock contention, multi-axis, monotone, trivially reopenable, and immune to the "period record says open but the lock date says closed" class of bug. But Odoo pays for it: it has **nowhere to record who closed what, when, against which trial balance, with whose approval.** Closing is three unrelated button presses and a chatter message. |
| **New architecture** | **Hybrid — build `fiscal_periods`, but demote it from gate to dimension.** Periods carry `period_id` for reporting, partition pruning, and as the anchor for the close workflow. **Enforcement** lives in a `fiscal_locks` cursor table with a database trigger on `journal_entries`. `fiscal_periods.status` becomes a **VIEW over the cursor**, never an independently writable column. One source of truth, two representations. |
| **Expected improvements** | Odoo's O(1) enforcement *and* the auditable close record Odoo lacks, without the dual-source-of-truth bug both naive designs invite. For an AI-first ledger where an agent proposes and a human disposes, the close record is not optional. |

## 2.2 ⚠️ Posting concurrency — a defect in shipped QAYD code

| | |
|---|---|
| **Odoo feature** | Odoo takes **no lock whatsoever** to evaluate lock dates — verified across `company.py:607-739` and `account_move.py:2806-2823`: no `FOR UPDATE`, no advisory lock, no mutex. |
| **QAYD equivalent** | ⚠️ `PostingService` locks the **fiscal-year row** `FOR UPDATE` via `FiscalYearCalendarResolver`. |
| **Reuse ideas** | Odoo demonstrates the calendar lock is unnecessary. A date-range read needs no serialization; the calendar is effectively immutable during posting. |
| **New architecture** | Serialize only what genuinely needs it: **the gapless-number sequence row**, which the allocator already locks via `ON CONFLICT DO UPDATE`. Replace the calendar `FOR UPDATE` with a plain read, and rely on the lock-date trigger for period enforcement. |
| **Expected improvements** | Removes a company-year-wide serialization point from the hottest write path in the system. Every concurrent posting in a company currently queues behind one row. This should be fixed when S2-07 rebinds the seam, and must ship with concurrency tests proving gaplessness survives. |

## 2.3 Lock exceptions

| | |
|---|---|
| **Odoo feature** | 🆕 `account.lock.exception` (new in 19.0, `account_lock_exception.py`, 306 lines) — a time-boxed, per-user or global, audited, revocable relaxation of a **soft** lock, storing a snapshot of the lock it relaxed and offering a query that surfaces every entry touched during the exception window. |
| **QAYD equivalent** | 🆕 Nothing today. |
| **Reuse ideas** | Adopt the shape almost wholesale — it is the best single pattern found in the fiscal-control area. Also adopt Odoo's **two-pass evaluation**: test the strict cursor first, consult exceptions only on violation (free performance, no behaviour change). |
| **New architecture** | `lock_exceptions` table, hardened beyond Odoo: `reason NOT NULL`, `end_at NOT NULL` with a maximum-duration CHECK, `CHECK (axis <> 'hard')` so a hard lock can never be excepted, RLS-scoped, and every posting made under an exception tagged so the "what moved during the window" query is a simple index scan. |
| **Expected improvements** | Controlled, auditable late adjustments without reopening a period for everyone — the real-world need that otherwise drives teams to disable locking entirely. |

## 2.4 Reversals

| | |
|---|---|
| **Odoo feature** | Reversal copies the move and negates each line, linking child→parent via `reversed_entry_id`, with `TYPE_REVERSE_MAP` mapping document types. Supports **storno** (same-side negative amounts) for jurisdictions where negation is illegal. `_unlink_or_reverse` codifies the delete-vs-cancel-vs-reverse decision. |
| **QAYD equivalent** | 🔨 S2-06. Columns `reversed_entry_id`/`reversal_entry_id`/`is_reversal` already exist from S2-03. |
| **Reuse ideas** | Reversal as a **first-class posted document, never a mutation of the original**, is correct and matches QAYD's immutability. The delete-vs-cancel-vs-reverse predicate set (hashed? locked? cash-basis? exchange-difference?) is well-chosen. Storno proves the design must generalise beyond negation. |
| **New architecture** | Reverse **through the existing `PostingService`** — never a special path. Add `reversal_reason NOT NULL`, `reversed_by_user_id`, and `reversal_kind ∈ {full, partial, storno}`. Enforce in Postgres what Odoo enforces nowhere: `CHECK (reversal_of_entry_id <> id)`, a cycle-rejecting trigger, and a partial unique index so an entry can be **fully** reversed at most once. Implement negation vs storno behind a `ReversalStrategy` seam, mirroring the `FiscalCalendarResolver` pattern. |
| **Expected improvements** | No reversal cycles, no over-reversal, a queryable reason (Odoo interpolates it into a text `ref`), and no silent date-shifting — Odoo bumps a locked reversal to `lock_date + 1 day` without telling anyone; QAYD should raise and return a suggestion the caller must accept. |

## 2.5 Opening balances

| | |
|---|---|
| **Odoo feature** | The opening balance is **one ordinary journal entry** referenced from the company, dated one day before the opening date, kept balanced by an auto-maintained plug line against an "unaffected earnings" account. Edits batch into a single move rewrite via a precommit hook. |
| **QAYD equivalent** | 🔨 `SetOpeningBalanceAction` (TD-10) — deferred, now unblocked by S2-05. |
| **Reuse ideas** | Three excellent ideas: (a) the opening balance is a **normal entry through the normal posting path**, inheriting locks, hashing, audit, and reversal for free; (b) `date = opening_date − 1 day`, so day-one transactions and the opening entry never share a date; (c) **precommit batching**, so a 5,000-row import produces one rewrite, not 5,000. |
| **New architecture** | Keep (a), (b), (c). **Reject the auto-balancing plug** — surface the residual and require an explicit acknowledged suspense account. Model the import as `opening_balance_imports` + `opening_balance_lines` with `CHECK (debit = 0 OR credit = 0)` and a residual CHECK, then post through `PostingService` in balanced chunks. Allow **more than one** opening batch (Odoo's single M2O cannot express subsidiary onboarding or restatement). |
| **Expected improvements** | Correctness (no float accumulation across thousands of lines — Odoo accumulates its running total in a Python float), plus a use case Odoo structurally cannot serve. Opening-balance import from a prior system's trial balance is also an ideal AI-drafting task: propose the chart mapping, surface unmapped accounts and the residual, human approves. |

---

# Part 3 — Reconciliation

## 3.1 Residual derivation — the single most valuable idea

| | |
|---|---|
| **Odoo feature** | `account.partial.reconcile` rows pair a debit line with a credit line and an amount; `amount_residual` is **derived** from those partials rather than stored as an independently mutable balance. `account.full.reconcile` is a pure grouping label with no amounts. Cross-currency partials carry **three** amounts (company, debit-side FX, credit-side FX). |
| **QAYD equivalent** | 🔨 Not built. This is the design to adopt. |
| **Reuse ideas** | The partial/full decomposition is correct and should be adopted in shape. The three-amount partial is the right model for matching where neither side is in company currency. Making the FX difference a **real journal entry** (not a fudge column) so gains/losses are themselves auditable ledger rows is also right. |
| **New architecture** | `reconciliation_partials(debit_entry_id, credit_entry_id, amount_base, debit_fx_amount, credit_fx_amount, fx_entry_id, max_date)` + `reconciliation_groups`. Residuals maintained in a `ledger_entry_residuals` side table by trigger — **never on the ledger row**. |
| **Expected improvements** | Keeps `ledger_entries` immutable, which is exactly what Odoo had to give up. Over-reconciliation becomes a trigger-enforced impossibility rather than a Python re-read. |

## 3.2 Unreconciling under immutability

| | |
|---|---|
| **Odoo feature** | Odoo **deletes** partials to unreconcile, and for bank statement lines goes further — deleting and recreating journal items on a *posted* move under `force_delete=True, skip_readonly_check=True`. |
| **QAYD equivalent** | 🔨 Not built — and QAYD's append-only trigger **forbids** Odoo's approach outright. |
| **Reuse ideas** | The requirement, not the mechanism. |
| **New architecture** | **Unreconcile by INSERT, not DELETE**: write a compensating link row plus, where money moved, a reversing journal entry through `PostingService`. Treat matching groups as a rebuildable read model with an explicit `RebuildReconciliationGroupsAction`. |
| **Expected improvements** | Same user-visible outcome, full audit trail, and no mutation of posted history. Odoo has a *documented staleness bug* arising directly from its raw-SQL group updates; QAYD's rebuildable-read-model approach cannot have that class of bug. |

## 3.3 Concurrency in reconciliation

| | |
|---|---|
| **Odoo feature** | `account_partial_reconcile` has **zero SQL constraints**; over-reconciliation is prevented only by Python re-reading a stored column; there is **no row locking anywhere** in the reconciliation path; and there are **zero concurrency tests** among the 95 reconciliation tests. |
| **QAYD equivalent** | 🔨 Not built — an opportunity to be correct from day one. |
| **Reuse ideas** | This is a worked example of what happens when invariants live only in application code. |
| **New architecture** | A deferred constraint trigger asserting `SUM(matched) ≤ original` per entry, ordered `FOR UPDATE` acquisition to prevent deadlock, and a concurrency test suite as a first-class deliverable. |
| **Expected improvements** | Closes a hole Odoo has lived with for years, at essentially zero extra cost because the constraint is declarative. |

## 3.4 Bank matching and the AI boundary

| | |
|---|---|
| **Odoo feature** | `account.reconcile.model` defines matching **conditions** and counterpart-line templates as data. **The evaluator is Enterprise-only** — absent from the public repository. No claim is made here about how Odoo scores or ranks candidates, because that code is not available to read. |
| **QAYD equivalent** | 🔨 Not built. |
| **Reuse ideas** | The condition **schema** (match by amount, partner, label, regex; auto-create counterpart lines) is public and is a sound decomposition. Also valuable: the **suspense-account invariant** — import the bank line to a suspense account immediately so the bank balance is correct *before* any matching, with the suspense balance representing the unmatched backlog. |
| **New architecture** | Three tiers, in strict order: (1) **deterministic rules** — exact reference/amount match, no AI; (2) **AI proposals** written to a `match_proposals` table with confidence and reasoning, never to the ledger; (3) **human confirmation** promotes a proposal into a real reconciliation. The FastAPI engine never writes accounting tables. |
| **Expected improvements** | This is where QAYD's AI-first thesis has the clearest commercial edge, and where Odoo's equivalent is both closed-source and paid. Deterministic-first also keeps the AI honest: it only sees what rules could not settle. |

## 3.5 Payment matching

| | |
|---|---|
| **Odoo feature** | A **two-hop** model: payment → outstanding account → bank clearing, giving `in_payment` a real meaning distinct from `paid`. But state is a stored computed field with `readonly=False` and **three independent writers**, and group-payment coverage is tracked by an in-memory, transaction-scoped accumulator that matches payments to partials **by amount equality**. |
| **QAYD equivalent** | 🔨 Not built. |
| **Reuse ideas** | Adopt the two-hop model — it correctly separates "cash committed" from "cash cleared". Reject everything about how state is stored. |
| **New architecture** | Payment state becomes a **VIEW** over residuals — zero writers, zero drift. Record allocation **intent** (`target_kind`, `target_id`: which installment did this payment target?), which Odoo discards after computing it for the UI. Make write-offs a **policy object** with tolerances and approval thresholds rather than a free-text account picker. |
| **Expected improvements** | Eliminates an entire class of Odoo bug: two payments of identical amount to the same partner currently attribute state changes to the wrong payment. And QAYD can answer "what paid installment 2?" with a column lookup — a question Odoo cannot answer at all. |

---

# Part 4 — Reporting

## 4.1 Reports as data

| | |
|---|---|
| **Odoo feature** | Financial statements are **declared as data**: `account.report` → `account.report.line` → `account.report.expression`, with pluggable evaluation engines (`domain`, `account_codes`, `aggregation`, `tax_tags`, `external`, `custom`). **The evaluator is Enterprise-only.** |
| **QAYD equivalent** | 🔨 S2-11 (Trial Balance) is on the board; statements come later. |
| **Reuse ideas** | The declarative model is the single most valuable structural idea in Odoo's reporting. Reject the execution: string formulas parsed by regex, sign conventions encoded in punctuation (`-sum(...)`, `-Tag`, `D`/`C`), money as float, and **no cycle detection** (the formula check is syntax-only). |
| **New architecture** | `report_definitions` / `report_lines` / `report_expressions` / `report_expression_operands` — **typed rows, not formula strings.** Cycle detection by recursive CTE at publish time. Selectors as CHECK-constrained JSONB compiled through an allowlist to bound parameters, never `eval`. |
| **Expected improvements** | Statements that cannot be defined into a cycle, cannot silently mis-sign, and cannot fail to tie out. **Sequencing matters:** build two concrete statements first (Trial Balance, then P&L), *then* extract the engine — building the engine first is precisely how you end up with Odoo's regex grammar, an abstraction designed against imagined requirements. |

## 4.2 Balances and the trial balance

| | |
|---|---|
| **Odoo feature** | Every balance is a scan. No stored aggregates of any kind, at any granularity. `_compute_current_balance` has no date bound — opening an account form aggregates its entire history. |
| **QAYD equivalent** | ✅ `ledger_entries.signed_base_amount` already makes any balance a single `SUM()`. 🔨 S2-11. |
| **Reuse ideas** | Nothing to reuse; this is the clearest scalability lesson in the study. |
| **New architecture** | `account_period_balances (company_id, account_id, period_id, opening, debit, credit, closing)` with `CHECK (closing = opening + debit − credit)`, maintained by an **AFTER INSERT trigger on `ledger_entries`**. Because the projection is append-only, the rollup is monotonic — it can only ever be incremented — so it is trustworthy in a way an aggregate over a mutable table never is. Ship a `RebuildPeriodBalancesAction` as a drift detector, run in CI and on a schedule. |
| **Expected improvements** | Trial balance becomes a ~2,000-row index scan instead of a full scan of the largest table. This is the biggest single scalability win available, and QAYD can have it *only because* it chose an append-only ledger. |

## 4.3 Current-year earnings

| | |
|---|---|
| **Odoo feature** | A dedicated `equity_unaffected` account per company, auto-created, deliberately **excluded from carry-forward** so retained earnings are not double-counted. The Balance Sheet splits unallocated earnings into prior-year and current-year using date-scoped expressions. |
| **QAYD equivalent** | 🔨 Not built. |
| **Reuse ideas** | The exclusion rule is the subtle correctness detail that solves the classic problem of P&L rolling into equity mid-year without a closing entry. Take it. |
| **New architecture** | An `include_initial_balance` classification on account types, with `equity_unaffected` excluded, plus a computed current-year-earnings line derived from `SUM(signed_base_amount)` over P&L accounts within the fiscal year. |
| **Expected improvements** | A balance sheet that balances at any date, mid-year, without a period-close ritual — and, because QAYD's ledger is signed and append-only, balancing becomes a structural theorem rather than a runtime check. |

## 4.4 Cash flow

| | |
|---|---|
| **Odoo feature** | Cash-flow classification uses **optional, multi-valued account tags** — a partition that is neither total nor disjoint. Odoo's own test suite contains an account in two cash-flow buckets simultaneously. |
| **QAYD equivalent** | 🔨 Not built. |
| **Reuse ideas** | The failure mode, as a warning. |
| **New architecture** | `accounts.cash_flow_bucket NOT NULL CHECK (IN ('cash','operating','investing','financing'))` — a **total, disjoint partition**, so the statement's reconciliation identity (`operating + investing + financing + net_change_in_cash = 0`) is structurally guaranteed. Reclassification is event-sourced and blocked for periods covered by an approved snapshot. |
| **Expected improvements** | A cash-flow statement that **cannot** fail to reconcile, versus one that can and occasionally does. |

---

# Part 5 — Tax and currency

## 5.1 Tax repartition

| | |
|---|---|
| **Odoo feature** | `account.tax.repartition.line` splits one tax across multiple accounting lines with percentages, target accounts, and reporting tags — **separately for invoices and refunds**, with a ±100% invariant. |
| **QAYD equivalent** | 🔨 Tax columns are designed in the spec but not built. |
| **Reuse ideas** | Adopt the shape. It turns reverse charge, partial deductibility, and withholding from code into configuration — which is the difference between supporting one VAT regime and supporting the GCC's several. |
| **New architecture** | Repartition rows with the ±100% invariant promoted from an application constraint to a **deferred Postgres constraint trigger**, and factors stored as **integer ppm** rather than floats. |
| **Expected improvements** | New tax regimes become data entry, not a release. The invariant becomes unbreakable rather than merely checked. |

## 5.2 The tax→VAT-return link

| | |
|---|---|
| **Odoo feature** | Odoo **reconstructs** the base↔tax relationship at report time in a ~450-line SQL that contains an explicit *"approximate"* fallback branch. |
| **QAYD equivalent** | 🔨 Not built. |
| **Reuse ideas** | The requirement; emphatically not the method. |
| **New architecture** | Persist `journal_line_tax_links` and `journal_line_tax_boxes` **at post time**, when the relationship is known exactly. |
| **Expected improvements** | The VAT return becomes a `GROUP BY` on an indexed table instead of a 450-line query that admits it may be wrong. For a filing that carries legal liability, "approximate" is not an acceptable word to appear in the implementation. |

## 5.3 Rounding and penny distribution

| | |
|---|---|
| **Odoo feature** | `_distribute_delta_amount_smoothly` — a deterministic, sorted, reproducible allocation of rounding remainders, applied uniformly at four levels of the tax computation. |
| **QAYD equivalent** | ✅ bcmath strings throughout; no distribution algorithm yet. |
| **Reuse ideas** | The *determinism and reproducibility* requirement, and the practice of keeping unrounded intermediates (`raw_` values) alongside posted amounts — both e-invoicing and dispute resolution need them. |
| **New architecture** | Reimplement on bcmath strings with the allocation trace as a first-class output. QAYD needs no epsilon compensation because it never floats — Odoo's `float_round` adds `2**(log2(x)−50)` to fix IEEE-754 tie mis-detection, an entire category of complexity QAYD simply does not have. |
| **Expected improvements** | Reproducible tax amounts to the fils, an auditable allocation trace, and no float-drift compensation machinery. |

## 5.4 Exchange rates

| | |
|---|---|
| **Odoo feature** | Dated rate rows with no `valid_to`. Lookup falls back to the earliest known rate, then to **`1.0`** — so a missing rate converts at par, silently. One number is exposed through four differently-named fields, and the public conversion API returns the field named `inverse_rate`. |
| **QAYD equivalent** | ✅ `exchange_rate` stored on both `journal_entries` and `journal_lines`; `base = amount × rate`. |
| **Reuse ideas** | Dated rows as a step function is the right primitive. `CHECK (rate > 0)` and per-day uniqueness are the right constraints. The 20%-jump warning is a cheap, effective guard against bad feed data — but promote it from an onchange (which never fires on API writes) to a server-side rule. |
| **New architecture** | ⚠️ **Critical:** Odoo's convention is the **inverse** of QAYD's — any Odoo-derived currency formula must be flipped before use. Store exactly one direction. Add `valid_to` with a **GiST exclusion constraint** so overlapping windows are structurally impossible; add `rate_type` (spot/closing/average/customs), `source` provenance, and `rate_unit` (1/100/1000) to handle weak currencies without a schema change. **Never default a missing rate** — raise `RATE_MISSING_FOR_DATE`. Make rates immutable once referenced by a posted entry. |
| **Expected improvements** | Eliminates the silent-par-conversion defect, which is the highest-severity issue found anywhere in Odoo's currency handling. `rate_unit` also solves the `NUMERIC(18,6)` precision-collapse problem for currencies like VND, and matches how CBK actually publishes rates. |

## 5.5 Unrealized FX revaluation

| | |
|---|---|
| **Odoo feature** | **Does not exist in Community** — verified: no `revaluation`/`unrealized` implementation anywhere in `addons/account`. Realized FX on reconciliation *is* Community. |
| **QAYD equivalent** | 🔨 Not built. |
| **Reuse ideas** | None available — there is no reference implementation to study. |
| **New architecture** | `RevalueForeignBalancesAction`: for each account marked `revaluation_policy = 'monetary'`, compare carrying base against foreign balance × closing rate, post the delta to unrealized gain/loss, and schedule an automatic day-1 reversal in the next period. |
| **Expected improvements** | A hard requirement for IAS 21 and Kuwait statutory reporting that Odoo Community users must buy Enterprise to obtain — a concrete differentiator. |

---

# Part 6 — Audit and inalterability

## 6.1 The hash chain

| | |
|---|---|
| **Odoo feature** | A tamper-evidence SHA-256 chain over posted entries, driven by European inalterability law, with a `$4$<hex>` versioned digest envelope so the algorithm can evolve without invalidating history. |
| **QAYD equivalent** | ✅ `audit_logs` exists, append-only by trigger, with **dormant `hash`/`prev_hash` columns** (TD-06). |
| **Reuse ideas** | Two good ideas: **generalise, don't localise** (the chain lives in core behind one boolean, with zero country conditionals — never fork inalterability per jurisdiction); and **version the digest in-band** so the algorithm can change. |
| **New architecture** | Activate the dormant columns. Fix all four of Odoo's weaknesses: (1) enforce in a `BEFORE INSERT` **trigger** — Odoo has *not one* `CREATE TRIGGER` in the entire repository, and its own test suite clears a seal with an ordinary write; (2) **no bypass tokens** — Odoo has three separate context-keyed escapes; (3) add **daily KMS-signed anchors**, because an unkeyed chain with an empty-string genesis can be rewritten end-to-end undetectably; (4) **persist a canonical payload** rather than re-deriving hashes from live business fields, so verification is a pure function of audit rows. |
| **Expected improvements** | A chain that covers **every** amount column (Odoo's covers neither `amount_currency` nor analytic distribution), cannot be cleared by application code, and is externally anchored. Cheap for QAYD precisely because `ledger_entries` is already append-only. |

## 6.2 Change tracking

| | |
|---|---|
| **Odoo feature** | Chatter tracking via `mail.tracking.value`, keyed to `ir.model.fields` by foreign key — so history becomes unreadable once a field is dropped. It is per-record, user-visible, and admin-deletable: **not an audit log**. |
| **QAYD equivalent** | ✅ `audit_logs`. 🔨 `journal_entry_history` (TD-16) not yet written by the posting path. |
| **Reuse ideas** | One genuinely good idea: **per-field read filtering** on audit output, so a viewer without permission on a sensitive column does not see it in the history. Rarely implemented anywhere. |
| **New architecture** | Three tiers, deliberately separated: (A) `entry_comments` — collaboration, mutable, never hashed; (B) `audit_logs` — field-level diffs as **self-describing JSONB** (each diff carries its own field name and type, so it survives a schema change), GIN-indexed; (C) `journal_entry_history` — full JSONB snapshots per lifecycle transition. Add a PL/pgSQL shadow-capture trigger and reconcile trigger-sourced rows against Action-sourced rows: **a trigger row with no Action peer means something wrote outside the Action layer.** |
| **Expected improvements** | Detects unattributed mutation — a class of event Odoo cannot detect at all. Audit history stays readable after schema evolution. And one entry edit produces **one** snapshot rather than hundreds of per-field rows. |

---

# Part 7 — Platform

## 7.1 Multi-tenancy

| | |
|---|---|
| **Odoo feature** | `ir.model.access` (model×operation ACL) + `ir.rule` (row-level domains). Reads inject SQL predicates; **writes and deletes evaluate Python predicates over hydrated rows**; **creates are checked *after* the INSERT** and rely on rollback. `sudo()` bypasses everything, transitively — 552 call sites here, 181 in `addons/account`. `company_id` is nullable on core accounting tables. |
| **QAYD equivalent** | ✅ Database-enforced RLS, `NOBYPASSRLS` runtime role, `BelongsToCompany`, `CompanyScope` failing closed, `PermissionResolver` (role ∪ grant − deny), `perms_ver` cache-busting. |
| **Reuse ideas** | One structural validation worth more than any borrowed code: **Odoo ANDs global rules and ORs group rules — which maps exactly onto PostgreSQL's RESTRICTIVE (AND-ed) and PERMISSIVE (OR-ed) policy semantics.** QAYD's restrictive-boundary + permissive-scope decomposition is therefore a proven pattern, not a guess. Also worth budgeting for: Odoo's **error UX**, which names the blocking rule and suggests which company to switch to — RLS gives you the opposite (zero rows, no explanation). |
| **New architecture** | Keep RLS strictly. Add PERMISSIVE scope policies (own/team/all) driven by resolved permissions projected into GUCs. Add **field-level** access control, which QAYD lacks and Odoo has — enforced in the API Resource layer plus real Postgres column `REVOKE` for the few columns where a bug must not leak. ⚠️ **Critical operational requirement:** GUCs are per-*connection*; under PgBouncer transaction pooling a connection is handed to another request mid-session. Always `SET LOCAL` inside an explicit transaction, and **test it with real concurrency against a real pooler** — this is the failure mode that produces a silent multi-tenant breach. |
| **Expected improvements** | Enforcement that survives raw SQL, queue jobs, console commands, BI tools, replicas, and forgotten endpoints — none of which Odoo's model protects. Plus the highest-leverage test in the system: a **CI catalog-introspection check** that fails the build if any table with a `company_id` column lacks `NOT NULL`, `FORCE` RLS, and the named restrictive policy. Odoo cannot write that test; QAYD can, and it converts a convention into a mechanism. |

## 7.2 `sudo()` — the pattern to refuse

| | |
|---|---|
| **Odoo feature** | Seven characters mid-expression disable ACLs, record rules, field ACLs, and multi-company validation — and the flag propagates transitively through every derived recordset. Not auditable: no log, no reason, no scope. |
| **QAYD equivalent** | ⚠️ **Correction (verified 2026-07-28, after this document was written):** the original claim that `is_platform_admin` is deliberately unwired to any bypass is **false**. `audit_logs` carries `OR app_is_platform_admin()` inside its **RESTRICTIVE** boundary, in both `USING` and `WITH CHECK` (`2026_07_27_000010_create_audit_logs_table.php:173-186`) — so a platform-admin session can read across tenants *and author audit rows attributed to any tenant*, on the one table whose entire purpose is tamper-evidence. Every other tenant table is correctly unwired. See `docs/architecture/knowledge/01_ENGINEERING_PRINCIPLES.md` gaps G-18/G-23. |
| **Reuse ideas** | Only as a cautionary tale. Note Odoo's own tacit admission: its security models set `_allow_sudo_commands = False`, acknowledging that a sudo'd nested write is a privilege-escalation primitive. |
| **New architecture** | Keep the refusal, and document it as load-bearing. Genuine cross-tenant work uses a `PlatformOperation` **action object** — a second connection as a distinct role, narrow per-table policy clauses, never a global bypass — writing actor, reason, target tenant, and affected ids to an append-only log **in the same transaction**; if the audit write fails, the operation fails. |
| **Expected improvements** | Cross-tenant access becomes rare, explicit, reasoned, and audited instead of ambient and invisible. Proposed `CLAUDE.md` rule: *"There is no `->sudo()`. If you think you need one, you need a new permission, a new policy clause, or a `PlatformOperation` with a written reason."* |

## 7.3 The workflow engine that was deleted

| | |
|---|---|
| **Odoo feature** | Odoo **removed** its declarative `workflow.workitem` engine and replaced it with explicit state fields, button methods, and server actions. |
| **QAYD equivalent** | ✅ Explicit status enums and lifecycle Actions — already the post-deletion design. |
| **Reuse ideas** | The lesson itself. A generic workflow engine is an abstraction that looks inevitable and turns out to obscure rather than clarify domain state. |
| **New architecture** | No change. Do not build a workflow engine. Keep explicit statuses, explicit Actions, and DB-enforced transitions. |
| **Expected improvements** | Avoids re-learning a lesson Odoo paid for over several major versions. |

## 7.4 Analytic dimensions — a decision QAYD has not yet made

| | |
|---|---|
| **Odoo feature** | Odoo's **second** design, after abandoning single analytic accounts plus tags: N-dimensional **plans** with a JSONB `analytic_distribution` field holding `{analytic_account_id: percentage}`, allowing a line to be split across dimensions by percentage. |
| **QAYD equivalent** | 🔨 The spec designs fixed `cost_center_id` / `project_id` / `department_id` FK columns — **not yet built** (TD-14). |
| **Reuse ideas** | Adopt Odoo's **concept** — dimensions as data, N-dimensional, percentage-allocated, applicability-governed. Reject Odoo's **storage**. Odoo arrived at plans-plus-distribution *after* the fixed-column approach proved too rigid; that hard-won second design is available before QAYD makes the same first mistake. |
| **New architecture** | **Store allocations as ROWS in a `journal_line_dimensions` child table — reject *both* QAYD's currently-specified fixed FK columns *and* Odoo's JSONB.** Against fixed columns: a fourth dimension becomes a migration, and "60% Project A / 40% Project B" is inexpressible without splitting the journal line. Against JSONB: keys are comma-joined id strings with **no referential integrity**; no CHECK can express "sums to 100%" (Odoo's rule is context-gated and production code disables it); and **money cannot be aggregated** — Odoo's `_read_group` raises on any aggregate but `__count` when grouping by distribution, so the subsystem's primary analytical query is not expressible against its primary storage. The decisive evidence: **Odoo already materializes the JSONB into `account.analytic.line` rows and keeps two-way sync with context flags** — it pays the row cost anyway, and the JSONB is an authoring convenience layered on top. Use a composite FK `(member_id, dimension_id)` so "member belongs to the declared dimension" is a database guarantee, and a `DEFERRABLE INITIALLY DEFERRED` constraint trigger for the 100%/amount invariants. JSONB is legitimate only as an **API transport format** and in the AI's `dimension_suggestions.proposed_payload`, never as ledger storage. |
| **Expected improvements** | New dimensions become one `INSERT`, not a migration; percentage splits become expressible; `SUM(amount) GROUP BY member` becomes a plain indexed aggregate; dimensions become orthogonal rows so Odoo's `_merge_distribution` cross-product and its documented "unsolvable" concurrent-closing-line edge case simply **cannot exist**. **This is the single most time-sensitive recommendation in this document — it costs nothing today and a migration on the largest table in the system later (≈13 points of rework).** |

## 7.5 Background jobs and events

| | |
|---|---|
| **Odoo feature** | `ir.cron` stores scheduled jobs as **data**, with multi-worker safety via row locking. Odoo has **no real job queue in core** (the popular queue library is third-party), and **no domain-event bus** — cross-module coupling happens through direct method overrides and model inheritance. |
| **QAYD equivalent** | ✅ Laravel queues (Redis) available but lightly used; ✅ `accounting.journal.posted` emitted after commit. |
| **Reuse ideas** | Jobs-as-data with `SKIP LOCKED` is a good pattern. The absence of a domain-event bus is an **anti-pattern to avoid**: it is precisely why Odoo modules are so tightly coupled. |
| **New architecture** | Keep Laravel queues. Keep after-commit domain events as the **only** cross-module integration path — no module ever writes another module's tables. Add a transactional **outbox** so an event cannot be lost if the broker is down, and cannot fire if the transaction rolls back. |
| **Expected improvements** | Modules stay independently testable and replaceable — the property Odoo most conspicuously lacks after twenty years of inheritance-based extension. |

---

# Part 8 — Summary of effort

Indicative Fibonacci totals from the subsystem analyses. These are **research estimates for planning
conversation**, not commitments, and several assume prerequisites listed in `ODOO_BACKLOG.md`.

| Area | Points | Notes |
|---|---|---|
| Core ledger refinements | ~20 | Mostly additive; the projection and posting path already exist. |
| Fiscal control (periods, locks, close, reversals, opening) | ~50 | Includes the concurrency fix and the close workflow Odoo lacks. |
| Reconciliation | ~45–158 | Core alone ≈45 and independently shippable; bank + payment matching layer on top. |
| Reporting (TB → statements → engine) | ~47 | Trial Balance (~8) is independently shippable and is the S2-11 story. |
| Tax + currency | ~89 | The tax engine alone is roughly 60% of it. |
| Audit + inalterability | ~47 | Extension of existing tables, not greenfield. |
| Platform / RLS hardening | ~73 | Dominated by the pooler-safe GUC lifecycle and the negative-path test matrix. |

**The four highest-value, lowest-cost items** — detailed in `ODOO_BACKLOG.md`:

1. **Decide analytic dimensions before building them** (Part 7.4) — costs nothing now, a migration later.
2. **`account_period_balances` rollup** (Part 4.2) — the biggest scalability win, made safe by append-only.
3. **The CI catalog-introspection RLS test** (Part 7.1) — turns QAYD's tenancy convention into a mechanism.
4. **Fix the posting concurrency defect** (Part 2.2) — removes a serialization point from the hottest path.

---

*No Odoo source is reproduced in this document. Citations locate claims for verification; every schema,
constraint, Action, DTO, and exception proposed above is an original design targeting Laravel 12 /
PHP 8.4 / PostgreSQL with RLS and bcmath.*
