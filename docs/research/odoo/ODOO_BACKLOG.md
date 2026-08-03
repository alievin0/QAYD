# ODOO_BACKLOG.md

**Every valuable idea discovered, categorized and justified**

Version: 1.0 · Date: 2026-07-28 · Companion to `ODOO_LEARNING.md` and `ODOO_TO_QAYD.md`
Source studied: Odoo 19.0.0, commit `f3e407c6`, LGPL-3. **No code copied — ideas only.**

---

## How this is categorized

| Tier | Meaning |
|---|---|
| **High value** | Adopt. Either a large win, a defect QAYD would otherwise ship, or a decision that gets more expensive every week it is deferred. |
| **Medium value** | Adopt when the dependent subsystem is built. Real value, but no urgency and no compounding cost. |
| **Low value** | Note and revisit. Genuine ideas whose payoff is small at QAYD's stage, or that solve a problem QAYD does not yet have. |
| **Rejected** | Deliberately not adopted, with the reason. **This is the most important section** — most of Odoo's accumulated cost sits in patterns that looked reasonable and compounded badly. |

Each item names its status in QAYD: 🆕 new · 🔨 planned · ✅ already done · ⚠️ fixes a defect.

Effort figures are indicative Fibonacci points for planning conversation, not commitments.

---

# HIGH VALUE

## H1 — Store analytic allocations as rows, not fixed columns and not JSONB ⚠️🔨
**Effort: 21 (but ~0 to *decide* today)** · Blocks: budgeting, cost centres, all dimensional reporting

QAYD's spec currently plans fixed `cost_center_id` / `project_id` / `department_id` columns on
`journal_lines` (TD-14). **Do not build them.** Adopt Odoo's *concept* — dimensions as data,
N-dimensional, percentage-allocated, applicability-governed — but store allocations as **rows** in a
`journal_line_dimensions` child table, rejecting Odoo's JSONB storage as well.

**Why this is the top item:** it is the only recommendation here whose cost rises with delay. Deciding
now costs nothing; deciding after `journal_lines` has millions of rows costs a migration on the largest
table in the system plus roughly 13 points of rework in every subsystem specified against it.

**Why not fixed columns:** a fourth dimension (Fund, Grant, Vessel, Store) becomes a migration and a
deploy — a per-customer schema fork by another name. And "60% Project A / 40% Project B" is
inexpressible without splitting the journal line, which corrupts the line↔source-document relationship.

**Why not Odoo's JSONB:** keys are comma-joined id strings with **no referential integrity**; no CHECK
can express "sums to 100%" (Odoo's rule is context-gated and its own production code disables it for
exchange-difference moves); and **money cannot be aggregated** — `_read_group` raises on any aggregate
but `__count` when grouping by distribution, so the subsystem's primary analytical query is not
expressible against its primary storage.

**The decisive evidence:** Odoo *already* materializes the JSONB into `account.analytic.line` rows and
maintains two-way sync between them with `skip_analytic_sync` context flags in six places. It pays the
row-table cost anyway. The JSONB is an authoring convenience layered on top of the real storage — so
keep the rows and drop the layer.

**Bonus:** with dimensions as orthogonal rows, Odoo's `_merge_distribution` cross-product and its
documented "unsolvable" concurrent-closing-line edge case cannot exist at all. Use a composite FK
`(member_id, dimension_id)` so "member belongs to the declared dimension" is a database guarantee, and a
`DEFERRABLE INITIALLY DEFERRED` constraint trigger for the 100%/amount invariants.

---

## H2 — `account_period_balances` incremental rollup 🆕
**Effort: 8** · Unlocks: fast trial balance, all financial statements

Odoo stores **no aggregate balances anywhere**; every trial balance is a full scan of the largest table,
and `_compute_current_balance` has no date bound at all — opening an account form aggregates its entire
history.

Add `account_period_balances (company_id, account_id, period_id, opening, debit, credit, closing)` with
`CHECK (closing = opening + debit − credit)`, maintained by an **AFTER INSERT trigger on
`ledger_entries`**.

**Why QAYD can do this safely and Odoo cannot:** a cached aggregate is only trustworthy if its source is
append-only. QAYD's `ledger_entries` is; Odoo's ledger is its mutable invoice-line table. The trigger is
therefore **monotonic** — it can only ever increment. Ship `RebuildPeriodBalancesAction` as a drift
detector in CI and on a schedule.

**Payoff:** trial balance becomes a ~2,000-row index scan instead of a full scan of the largest table.
The single largest scalability win available, and a direct dividend of the append-only decision.

---

## H3 — Fix the posting concurrency defect ⚠️
**Effort: 3** · Fixes shipped QAYD code

`PostingService` takes `SELECT … FOR UPDATE` on the **fiscal-year row**, serializing every concurrent
posting within a company-year. Odoo takes **no lock at all** on its equivalent path (verified: no
`FOR UPDATE`, no advisory lock, no mutex anywhere in its lock-date evaluation).

Serialization is genuinely required only for **gapless number allocation**, which
`JournalNumberAllocator` already achieves by locking the sequence row via `ON CONFLICT DO UPDATE`.
Replace the calendar lock with a plain read.

**Payoff:** removes a company-wide serialization point from the hottest write path in the system. Fix
when S2-07 rebinds the resolver seam, and ship with concurrency tests proving gaplessness survives —
including a test where a random subset of transactions rolls back after allocation and the surviving
numbers must still be contiguous.

---

## H4 — Fiscal periods as dimension, locks as cursor, status as view 🔨
**Effort: 8 + 13** · Resolves the S2-07 design question

Odoo has **no fiscal-year and no fiscal-period table**; period control is a **lock-date cursor** across
five axes (global, sale, purchase, tax, hard). That cursor is genuinely superior for *enforcement*: O(1),
no rows, no contention, multi-axis, monotone, trivially reopenable, and immune to the "period says open
but the lock date says closed" bug class.

But Odoo has **nowhere to record who closed what, when, against which trial balance, with whose
approval** — closing is three unrelated button presses and a chatter message. For an AI-first ledger
where an agent proposes and a human disposes, that gap is disqualifying.

**Resolution:** build `fiscal_periods`, but **demote it from gate to dimension**. Periods carry
`period_id` for reporting and partition pruning and anchor the close workflow (`period_close_runs` with a
four-eyes CHECK and a partial exclusion constraint making concurrent close runs impossible).
**Enforcement** lives in a `fiscal_locks` cursor with a database trigger. `fiscal_periods.status` becomes
a **VIEW over the cursor** — never an independently writable column. One source of truth, two
representations. Non-overlap via `EXCLUDE USING GIST` — a constraint Odoo cannot express.

---

## H5 — Activate the dormant hash chain, properly 🔨
**Effort: 21** · TD-06 · Regulatory

QAYD's `audit_logs` already has unused `hash`/`prev_hash` columns. Odoo's inalterability chain is the
design to learn from — and to improve on in four specific ways:

1. **Enforce in a `BEFORE INSERT` trigger.** Odoo has **not one `CREATE TRIGGER` in the entire
   repository**; its own test suite clears a seal with an ordinary write.
2. **No bypass tokens.** Odoo has three separate context-keyed escapes.
3. **Add externally-signed periodic anchors.** Odoo's chain is unkeyed with an empty-string genesis, so a
   full-chain rewrite is undetectable.
4. **Persist a canonical payload** rather than re-deriving hashes from live business fields, so
   verification is a pure function of audit rows and a field-set change is not a breaking migration.

Worth copying as-is: **generalise, don't localise** (one boolean in core, zero country conditionals) and
**version the digest in-band** so the algorithm can evolve.

QAYD's chain should be computed over `ledger_entries` and cover **every** amount column — Odoo's
allowlist omits `amount_currency`, `currency_id`, `date_maturity`, and analytic distribution, so those are
silently mutable on a "secured" entry. Cheap for QAYD precisely because the projection is append-only,
which also means the chain can never go stale.

---

## H6 — CI catalog-introspection test for RLS 🆕
**Effort: 5** · Highest leverage-to-cost ratio in the study

A CI check querying `pg_class` / `pg_policy` / `pg_attribute` that **fails the build** if any table with a
`company_id` column lacks `NOT NULL`, `relrowsecurity`, `relforcerowsecurity`, and the named restrictive
policy.

**Why it matters:** Odoo has no company primitive — just a field name and 72 hand-written `ir.rule`
records for 192 models, with nothing checking that a model with `company_id` has a rule. A new model
ships cross-tenant-readable and nothing fails.

QAYD's `BelongsToCompany` convention is far stronger, but a convention only becomes a mechanism once it
is **verified**. This one query converts it. Odoo structurally cannot write this test; QAYD can.

Pair it with a boot-time assertion that the gapless-numbering backstop index exists — QAYD's analogue of
Odoo's `sequence_mixin.init()` self-audit, but **fatal rather than a log warning**.

---

## H7 — Pooler-safe GUC lifecycle ⚠️
**Effort: 8** · Silent-breach risk · **Highest-confidence finding in the study** — reached independently
by two agents studying different subsystems (ORM/permissions and platform services)

RLS GUCs are **per-connection**. Under PgBouncer transaction pooling a connection is handed to another
request mid-session — so a `SET` (rather than `SET LOCAL`) leaks one tenant's context into another
tenant's request.

Requirements: always `SET LOCAL` inside an explicit transaction; wrap the RLS connection so *every* query
runs in a transaction that first issues the `SET LOCAL`s; extend this to queue jobs and console commands,
not just HTTP; and **test it with genuine concurrency against a real pooler**.

Not theoretical — this is the specific failure mode that produces a silent multi-tenant breach, and it is
invisible in single-connection testing.

---

## H8 — Reconciliation: residuals derived, never stored on the ledger 🔨
**Effort: 45 (core)** · Architectural

Odoo's best idea in reconciliation: `amount_residual` is **derived** from partial-reconcile rows rather
than stored as an independently mutable balance, with full-reconcile as a pure grouping label carrying no
amounts, and cross-currency partials carrying **three** amounts (company, debit-side FX, credit-side FX).

Adopt the partial/full decomposition and the three-amount partial. **But reject where Odoo puts the
state:** `amount_residual`/`reconciled`/`full_reconcile_id` live *on the ledger row*, and that single
decision is what forces Odoo's ledger to be mutable and drives its raw-SQL writes. QAYD keeps matching
state in side tables keyed to `ledger_entry_id`.

Corollary: **unreconcile by INSERT, not DELETE.** Odoo deletes partials and, for bank lines, deletes and
recreates journal items on *posted* moves under `force_delete`/`skip_readonly_check`. QAYD's append-only
trigger forbids this outright — write compensating rows plus a reversing entry instead, and treat matching
groups as a rebuildable read model.

---

## H9 — Persist tax→GL and tax→VAT-box links at post time 🔨
**Effort: part of the ~55-point tax engine** · Compliance

Odoo **reconstructs** the base↔tax relationship at report time in a ~450-line SQL containing an explicit
*"approximate"* fallback branch.

QAYD should persist `journal_line_tax_links` and `journal_line_tax_boxes` **at post time**, when the
relationship is known exactly. The VAT return then becomes a `GROUP BY` on an indexed table. For a filing
that carries legal liability in the GCC, "approximate" is not a word that should appear in the
implementation.

Adopt alongside it: **tax repartition lines** — one tax splitting across multiple accounting lines with
percentages, accounts, and reporting tags, separately for invoices and refunds. This turns reverse charge,
partial deductibility, and withholding into configuration rather than code, which is the difference
between supporting one VAT regime and supporting the GCC's several. Promote the ±100% invariant from an
application constraint to a **deferred constraint trigger**, and store factors as integer ppm rather than
floats.

---

## H10 — Never default a missing exchange rate ⚠️
**Effort: 13 (whole exchange-rate subsystem)** · Correctness

Odoo's rate lookup falls back to the earliest known rate, then to **`1.0`**. A currency with no rates, or
a date before the first rate, **converts at par silently** — no error, no log, no flag. The
highest-severity defect found anywhere in Odoo's currency handling.

QAYD must raise `RATE_MISSING_FOR_DATE` and never extrapolate.

**Critical companion finding:** Odoo's rate convention is the **inverse** of QAYD's (`base = amount ×
rate`). Any Odoo-derived currency formula must be flipped before use — Odoo's public conversion API
returns the field named `inverse_rate` because two inversions cancel, a trap that catches every
integration once.

Also adopt: a **GiST exclusion constraint** on validity windows (structurally impossible overlaps),
`rate_type` (spot/closing/average/customs), `source` provenance, `rate_unit` (1/100/1000) to handle weak
currencies without a schema change, and **immutability once a rate is referenced by a posted entry**.

---

## H11 — Make the lifecycle transition map data, before more Actions encode it ⚠️🆕
**Effort: 3** · Urgent out of proportion to its size

Odoo's `account.move` has 8 statuses and its transition rules are scattered across **at least six
separate call sites** — `write()` guards, `_post` validations, `button_draft`, `button_cancel`, and two
more. There is no single place that answers "what transitions are legal?", so every new code path
re-derives the rules and occasionally gets them wrong.

**QAYD is at exactly the point Odoo was before this metastasized:** `journal_entry_status` has 8 values,
and there is precisely one constant (`POSTABLE_STATUSES`) plus `EDITABLE_STATUSES` expressing any of it.
Every Action added from here will encode transition rules implicitly.

**Do now:** a single `JournalEntryLifecycle::TRANSITIONS` map as the one source of truth, mirrored by a
PostgreSQL trigger that rejects any illegal status transition — including one that rejects
`posted → anything-not-terminal`, which structurally forbids the un-posting path QAYD has already
decided against (see R26).

**Why it is urgent rather than merely valuable:** the cost is 3 points today and grows linearly with
every Action written against the implicit rules. This is the cheapest item in the entire backlog and the
one with the shortest window.

## H12 — Predicates as portable data 🆕
**Effort: 21** · Unlocks the AI story

Odoo's domain language — a storable, reviewable, compilable predicate used uniformly for queries,
security rules, report line definitions, and automation triggers — is the structural idea that makes
"the AI proposes, it never writes" actually implementable.

An LLM can emit a predicate; a human can read and approve it; the backend compiles it to SQL through an
allowlist. Compare the alternative, where an AI emits SQL or calls mutating methods directly — which is
unreviewable and unsafe by construction.

QAYD version: a closed, CHECK-constrained JSONB selector compiled by an allowlist to bound parameters —
**never `eval`, never string interpolation**. The same compiled selector then serves report expressions
(M2), reconciliation matching rules (M5), and dimension distribution rules (H1), so one reviewed
compiler secures three subsystems.

---

# MEDIUM VALUE

## M1 — `lock_exceptions`: time-boxed, audited relaxations 🆕
**Effort: 5** · Build with H4

Odoo 19's `account.lock.exception` — time-boxed, per-user or global, audited, revocable relaxation of a
**soft** lock, storing a snapshot of the lock it relaxed plus a query surfacing every entry touched during
the window. The best single pattern in the fiscal-control area.

Harden beyond Odoo: `reason NOT NULL`, `end_at NOT NULL` with a maximum-duration CHECK, and a database
guarantee that a **hard** lock can never be excepted. Adopt Odoo's **two-pass evaluation** too (strict
cursor first, exceptions consulted only on violation) — free performance, no behaviour change.

Solves the real-world need that otherwise drives teams to disable locking entirely.

## M2 — Reports declared as data 🔨
**Effort: 13** · Build *after* two concrete statements

Odoo's `account.report` / `.line` / `.expression` with pluggable engines is the most valuable structural
idea in its reporting. Adopt the model; reject the execution (string formulas parsed by regex, sign
conventions encoded in punctuation, float money, **no cycle detection**).

QAYD version: typed `report_expression_operands` rows instead of formula strings; cycle detection by
recursive CTE at publish; selectors as CHECK-constrained JSONB compiled through an allowlist, never `eval`.

**Sequencing is the recommendation:** build Trial Balance, then P&L, *then* extract the engine. Building
the engine first is exactly how you get Odoo's regex grammar — an abstraction designed against imagined
requirements.

## M3 — Reversal hardening 🔨
**Effort: 8** · S2-06

Reversal as a first-class posted document (never a mutation) is correct and matches QAYD's immutability.
Add what Odoo enforces nowhere: `CHECK (reversal_of_entry_id <> id)`, a cycle-rejecting trigger, and a
partial unique index so an entry can be **fully** reversed at most once. Add `reversal_reason NOT NULL`
(Odoo interpolates the reason into a text `ref`, making it unqueryable).

**Reverse through the existing `PostingService`** — never a special path. Implement negation vs **storno**
(same-side negative amounts, legally required in around ten jurisdictions) behind a `ReversalStrategy`
seam.

**Reject Odoo's silent date shift:** it bumps a locked reversal to `lock_date + 1 day` without telling
anyone. Raise, and return a suggestion the caller must explicitly accept.

## M4 — Two-hop payment model with derived state 🔨
**Effort: ~50 with allocations** · Build after H8

Adopt Odoo's payment → outstanding → bank-clearing two-hop model; it makes "cash committed" vs "cash
cleared" a real distinction.

Reject how Odoo stores the state: a stored computed field with `readonly=False` and **three independent
writers**, plus group-payment coverage tracked by an in-memory accumulator matching payments to partials
**by amount equality** — so two payments of identical amount to the same partner attribute state changes
to the wrong payment.

QAYD: payment state as a **VIEW** over residuals (zero writers, zero drift), and record allocation
**intent** (`target_kind`, `target_id`) so "which installment did this pay?" is a column lookup — a
question Odoo cannot answer, because it computes intent for the UI and then discards it.

## M5 — Bank matching: deterministic rules → AI proposals → human confirm 🆕
**Effort: ~63** · Commercial differentiator

Odoo's `account.reconcile.model` defines matching conditions as data, but **the evaluator is
Enterprise-only** — so no claim is made here about how it scores candidates.

The publicly visible and genuinely valuable idea is the **suspense-account invariant**: import the bank
line to a suspense account immediately, so the bank balance is correct *before* any matching and the
suspense balance is exactly the unmatched backlog.

QAYD's three strict tiers: (1) deterministic exact-reference/amount rules; (2) AI proposals to a
`match_proposals` table with confidence and reasoning, **never to the ledger**; (3) human confirmation
promotes a proposal. Deterministic-first keeps the AI honest — it only sees what rules could not settle.

## M6 — Reconciliation invariants in the database 🆕
**Effort: included in H8** · Correctness

Odoo's `account_partial_reconcile` has **zero SQL constraints**, no row locking anywhere in the
reconciliation path, and **zero concurrency tests** among its 95 reconciliation tests. Over-reconciliation
is prevented only by Python re-reading a stored column.

QAYD: a deferred constraint trigger asserting `SUM(matched) ≤ original`, ordered `FOR UPDATE` acquisition
to avoid deadlock, and a concurrency suite as a first-class deliverable. Declarative, so essentially free.

## M7 — Field-level access control 🆕
**Effort: 8** · Capability gap

Odoo has per-field ACLs with real deny semantics; QAYD has nothing at this granularity and needs it for
cost prices, salaries, and bank details.

Two layers: a `#[RequiresPermission]` attribute enforced in the API Resource layer (never in the model),
**plus** real Postgres column `REVOKE` for the handful where a bug must not leak. Postgres has column
privileges; Odoo does not use them.

## M8 — RLS diagnostics 🆕
**Effort: 8** · Support cost

Odoo's error UX is the best thing about its permission model: it names the blocking rule and suggests
which company to switch to. RLS gives the opposite — zero rows, no explanation.

Build a diagnostic path distinguishing *does not exist* from *exists in company X*, returning a
`RecordNotVisibleException` with the company name when the user has access to it. **Rate-limit and audit
it** — it is an existence oracle by construction.

## M9 — Opening balances as normal entries 🔨
**Effort: 5** · TD-10, now unblocked

Three good Odoo ideas: the opening balance is a **normal entry through the normal posting path**
(inheriting locks, hashing, audit, reversal for free); `date = opening_date − 1 day` (so day-one
transactions and the opening entry never share a date); and **precommit batching** so a 5,000-row import
is one write, not 5,000.

**Reject the auto-balancing plug** — surface the residual and require an explicit acknowledged suspense
account. Allow **more than one** opening batch, which Odoo's single M2O cannot express (subsidiary
onboarding, restatement).

Natural AI task: map a prior system's trial balance to QAYD's chart, surface unmapped accounts and the
residual, human approves.

## M10 — Current-year earnings without a closing entry 🔨
**Effort: part of Balance Sheet, ~8**

Odoo's `equity_unaffected` account, deliberately **excluded from carry-forward** so retained earnings are
not double-counted, is the subtle detail that lets a balance sheet balance mid-year without a closing
ritual. Take it — and note that with QAYD's signed append-only ledger, balancing becomes a structural
theorem rather than a runtime check.

## M11 — Cash-flow bucket as a total, disjoint partition 🆕
**Effort: 13**

Odoo classifies cash flow with **optional, multi-valued account tags** — a partition that is neither total
nor disjoint; its own test suite contains an account in two buckets simultaneously.

QAYD: `accounts.cash_flow_bucket NOT NULL CHECK (IN (...))`, making the reconciliation identity
(`operating + investing + financing + net_change = 0`) structurally guaranteed. Reclassification is
event-sourced and blocked for periods covered by an approved snapshot — because moving an account between
buckets silently reshapes an already-published statement.

## M12 — Aggregate all validation errors into one structured response 🆕
**Effort: 3** · AI ergonomics

Odoo collects every validation failure and raises them together rather than failing on the first — but as
an unstructured newline-joined string. For an AI agent drafting entries, aggregation is the difference
between one round trip and N. Return a `ValidationReport` DTO with `violations[]` of
`{code, field, message, actual, expected}` — Odoo's good instinct with machine-readable structure.

## M13 — Unrealized FX revaluation 🔨
**Effort: part of the 13-point rate subsystem** · Statutory

**Verified absent from Odoo Community** — there is no reference implementation to study. Realized FX on
reconciliation *is* Community; period-end revaluation is not.

`RevalueForeignBalancesAction`: compare carrying base against foreign balance × closing rate for monetary
accounts, post the delta to unrealized gain/loss, schedule an automatic day-1 reversal. A hard requirement
for IAS 21 and Kuwait statutory reporting, and a concrete differentiator.

## M14 — Transactional outbox for domain events 🆕
**Effort: 5**

QAYD already emits `accounting.journal.posted` after commit. Add an outbox so an event cannot be lost if
the broker is down and cannot fire if the transaction rolls back. Keep after-commit domain events as the
**only** cross-module integration path — no module ever writes another module's tables.

## M15 — Persist derived valuation state 🆕
**Effort: part of inventory valuation, ~34**

Odoo 19 **removed** its `stock.valuation.layer` in favour of full-history replay, batched at 50,000 rows
to avoid `MemoryError`. The clearest cautionary tale in the repository: full-history replay as a primary
read path does not scale.

QAYD's equivalent should carry `unit_cost_after` on each valuation event, turning an O(history) replay into
an indexed lookup. The same principle drives H2 and consolidation snapshots.

## M16 — `posting_attempts` as a compliance and AI-training artifact 🆕
**Effort: 3**

An append-only record of *rejected* postings with violation codes, entry source, and AI confidence. Odoo
has no equivalent — a failed post leaves no trace. This is simultaneously a compliance artifact ("show me
every attempt to post into a closed period") and the highest-quality training signal available for the AI
drafter, because it records exactly what a human or agent got wrong and why.

---

# LOW VALUE

## L1 — Gap-detection reporting for sequences
**Effort: 2.** Odoo detects and flags gaps in posted numbering after the fact. QAYD's allocator is gapless
by construction, so this is a verification tool rather than a feature — worth having as a
`VerifyNumberSequenceAction` in CI, but it should never find anything.

## L2 — 20%-rate-jump warning
**Effort: 1.** A cheap guard against fat-fingered or bad-feed rates. Odoo implements it as an *onchange*,
so it never fires on API or import writes — which is precisely how bad feed data arrives. Worth having,
but only as a server-side rule.

## L3 — `btree_not_null` partial indexes on sparse FKs
**Effort: 1.** Odoo indexes sparse foreign keys with a partial index excluding NULLs, keeping the index
small. Good habit; adopt opportunistically rather than as a work item.

## L4 — Per-field read filtering on audit output
**Effort: part of M7.** Genuinely good and rarely implemented — a viewer without permission on a sensitive
column should not see it in the history either. Low urgency until audit output is broadly exposed.

## L5 — `ir.cron`-style jobs-as-data with `SKIP LOCKED`
**Effort: 0 for now.** A sound pattern, but Laravel's scheduler and queues already cover QAYD's needs.
Revisit only if tenant-configurable scheduled jobs become a product requirement.

## L6 — Storno reversal strategy
**Effort: included in M3.** Same-side negative amounts instead of opposite-side entries, legally required
in around ten jurisdictions — none in QAYD's initial market. Build the `ReversalStrategy` **seam** now (it
costs nothing); implement the storno strategy only when a market requires it.

## L7 — Consolidation
**Effort: 34.** Enterprise-only in Odoo, with only three comment-level traces in Community. Genuinely
valuable for Gulf groups (one company per jurisdiction or licence is common), and the security model —
snapshot-and-freeze under a dedicated audited database role, as the *only* cross-tenant read in the system
— is well understood. Far beyond current scope. Recorded now so the RLS model is not frozen in a way that
makes it impossible later.

## L8 — Multi-currency rounding plug line
**Effort: part of tax.** Odoo permits a sub-threshold base-currency discrepancy to be absorbed by a plug
line against a rounding account; QAYD's zero-tolerance check rejects it. Not yet reachable in practice,
since `base = amount × rate` is derived by one code path at one rate — becomes relevant only with true
per-line rates.

## L9 — Self-billing sequence chains per partner
**Effort: 3.** Odoo maintains a separate numbering chain per commercial partner for self-billing
documents. Correct for that use case, but it multiplies chains — and therefore lock targets and gap
surfaces — by the partner count. Only if self-billing becomes a requirement.

---

# REJECTED

*The most valuable section. Each of these looked reasonable and compounded badly.*

## R1 — `sudo()` and every ambient privilege bypass
Seven characters mid-expression disable ACLs, record rules, field ACLs, and multi-company validation — and
the flag propagates **transitively** through every derived recordset. 552 call sites in this checkout, 181
in `addons/account`. No log, no reason, no scope.

**Rejected because** it converts every security boundary into a convention. Odoo's own tacit admission:
its security models set `_allow_sudo_commands = False`, acknowledging that a sudo'd nested write is a
privilege-escalation primitive.

⚠️ **Correction (verified 2026-07-28):** the claim below that `is_platform_admin` is unwired is **false
for `audit_logs`**, which carries `OR app_is_platform_admin()` in its RESTRICTIVE boundary's `USING`
*and* `WITH CHECK` (`2026_07_27_000010:173-186`). A platform-admin session can therefore author audit
rows attributed to any tenant — on the tamper-evidence table itself. Closing this is a High-value item;
tracked as G-18/G-23 in `docs/architecture/knowledge/01_ENGINEERING_PRINCIPLES.md`.

**Instead:** *restore* `is_platform_admin` to being unwired to any bypass, and make cross-tenant work a
`PlatformOperation` action object — a second connection as a distinct role, narrow per-table policy
clauses, and an audit row written **in the same transaction** (if the audit write fails, the operation
fails). Proposed `CLAUDE.md` rule: *"There is no `->sudo()`. If you think you need one, you need a new
permission, a new policy clause, or a `PlatformOperation` with a written reason."*

## R2 — Application-layer row filtering for writes
Odoo filters reads with a SQL predicate but filters **writes and deletes with a Python predicate over
hydrated rows**, and checks **create rules *after* the INSERT**, relying on rollback.

**Rejected because** two evaluators must agree on every operator for every field type forever; the write
check materialises every row; the `UPDATE`/`DELETE` statements carry no predicate; and anything emitting
SQL outside the ORM is unfiltered — *including Odoo's own ORM*, which does exactly that in several places.

RLS wins on every operation except read (where it ties), and decisively on raw SQL, queue jobs, console
commands, BI tools, replicas, and forgotten endpoints.

## R3 — Reconciliation state stored on the ledger row
`amount_residual`, `reconciled`, and `full_reconcile_id` on the ledger line is the single decision that
forces Odoo's general ledger to be mutable — and thus its raw-SQL writes, its documented staleness bug,
and its inability to be append-only or partitioned.

**Rejected.** Matching state goes in side tables keyed to `ledger_entry_id`.

## R4 — Raw SQL writes to the ledger
Odoo issues `UPDATE account_move_line SET full_reconcile_id = …` directly, then hand-invalidates the ORM
cache. Its own comments document a bug class arising from exactly this.

**Rejected.** `PostingService` is the only writer; everything else goes through Actions.

## R5 — The auto-balancing suspense line
Odoo silently inserts a plug line when an entry does not balance.

**Rejected.** An unbalanced entry is a data-quality signal, not something to absorb. QAYD's zero-tolerance
check in both currencies is stronger and must not be weakened. Where a plug is legitimately needed
(opening balances), it is explicit, acknowledged, and surfaced in the DTO before posting.

## R6 — Balance validated in company currency only
Odoo's balance context manager checks the company currency alone, so foreign-currency sub-ledgers can
drift undetected. It never asserts `amount_currency ≈ balance × rate` — only sign co-monotonicity.

**Rejected.** QAYD already checks both currencies with zero tolerance. Keep it.

## R7 — Silent fallbacks in rate lookup
Missing rate → earliest known rate → `1.0`, with no error. **Rejected**; see H10.

## R8 — Silent date coercion on post
Odoo silently moves a posting date forward when the requested date is locked — and the coercion target is
derived from the *numbering* granularity, so numbering policy determines the accounting date. A preview
helper exists; the posting path does not use it.

**Rejected.** Raise, and return a `PostingDateResolution` the caller must explicitly accept. Silently
changing the date of a financial event is never acceptable — least of all when an AI is the drafter.

## R9 — Runtime DDL driven by user data
Creating or renaming an analytic plan executes `ALTER TABLE` and `CREATE INDEX` at runtime, per inheriting
model.

**Rejected — disqualifying on its own for a migration-driven system.** Every tenant's schema would differ,
no migration would be portable, zero-downtime deploys would be unreasonable, and a schema review could not
enumerate the columns.

## R10 — Foreign keys as delimited strings inside JSONB
Odoo stores analytic distribution keys as comma-joined id strings (`{"14,16": 60}`), so there is no
referential integrity — hence `.exists()` guards scattered through the codebase, and a deleted analytic
account leaving danglers no constraint catches.

**Rejected.** Directly contradicts QAYD's first principle. See H1.

## R11 — Context-gated invariants
Odoo's "distribution must sum to 100%" runs **only** when a specific context key is present, and
production code explicitly disables it for exchange-difference moves. The same pattern appears as
`bypass_lock_check`, `skip_readonly_check`, `force_delete`, and `check_move_validity`.

**Rejected.** An invariant a caller can switch off is not an invariant. Use deferred constraint triggers.

## R12 — Two-way sync between a JSONB field and a materialized table
Odoo keeps `analytic_distribution` and `account.analytic.line` aligned in both directions, guarded by
`skip_analytic_sync` context flags in six places.

**Rejected.** Two sources of truth kept aligned by convention. Keep the rows; drop the JSONB.

## R13 — Float money and epsilon compensation
Odoo stores monetary values as float and compensates for IEEE-754 tie mis-detection by adding
`2**(log2(x)−50)` inside its rounding helper.

**Rejected.** `NUMERIC(19,4)` with bcmath strings eliminates the entire category — QAYD needs no epsilon
machinery because it has no drift to compensate for.

## R14 — Nullable `company_id` on tenant tables
Odoo's `company_id` on core accounting tables is a stored related field with no `required=True`, so the
column is nullable — and a NULL is invisible to an `IN (…)` isolation predicate, meaning such a row leaks
*out* of the boundary rather than into it.

**Rejected.** `NOT NULL` is a precondition for RLS to mean anything. Enforced by H6.

## R15 — Security logic stored as evaluated code
`ir.rule.domain_force` is a TEXT column run through `safe_eval` on the request path, validated only by an
application constraint. Anyone who can write `ir.rule` controls the security predicate of every model.

**Rejected.** Policies are DDL in reviewed migrations; scopes are typed PHP. If per-tenant rules ever
become necessary, express them as **data consumed by a fixed policy**, never as generated DDL.

## R16 — Building a generic workflow engine
Odoo built one, then **deleted it**, replacing it with explicit state fields and button methods.

**Rejected before starting.** Keep explicit statuses, explicit lifecycle Actions, and DB-enforced
transitions. A lesson available for free.

## R17 — Cross-module coupling by inheritance instead of events
Odoo has no domain-event bus; modules extend each other by overriding methods and inheriting models — the
root cause of its coupling after twenty years.

**Rejected.** After-commit domain events are the only cross-module integration path in QAYD.

## R18 — `display_type` overloading the journal-line table
Odoo stores section headers and notes as rows in `account_move_line`, discriminated by `display_type` —
which is also why `account_id` cannot be `NOT NULL`.

**Rejected.** Presentation rows in a financial table are a permanent source of
`WHERE display_type IS NULL` bugs, and every aggregate must remember to exclude them. Keep cosmetics in a
separate table — which is what lets QAYD make `account_id NOT NULL`.

## R19 — Hierarchy derived from account-code strings
Odoo derives its account-root hierarchy by slicing the code string, producing a non-stored, non-joinable
pseudo-model.

**Rejected.** QAYD has a real `parent_id`. Grouping a report by hierarchy should be a SQL join, not a
string operation.

## R20 — Account codes stored as per-company JSONB
Odoo stores account code in a JSONB keyed by company, with uniqueness enforced in Python only.

**Rejected.** RLS makes `UNIQUE (company_id, code)` both correct and simple.

## R21 — `ON DELETE CASCADE` from entries to lines
Deleting a move cascades its lines away.

**Rejected** for posted entries. Use `RESTRICT`; correction is by reversal, never deletion.

## R22 — Amount equality as identity
Odoo matches payments to reconciliation partials by comparing amounts — so two payments of identical value
to the same partner attribute state changes to the wrong payment.

**Rejected.** Record explicit `payment_id` on every allocation; coverage becomes a query, not a heuristic.

## R23 — Multi-valued optional tags for exclusive classifications
Used for cash-flow buckets, allowing an account in two buckets at once (present in Odoo's own tests).

**Rejected.** An exclusive classification is a `NOT NULL` CHECK-constrained column. See M11.

## R24 — Python-side aggregation of money
Several Odoo balance computations fetch rows and sum them in Python, sometimes converting currency
per-record.

**Rejected.** Aggregate in SQL. Both a performance and a correctness matter — Python-side summation is
where float drift accumulates.

## R25 — Numbering allocated as an ORM side effect
Odoo's number is assigned by a computed-field dependency that fires *after* `state='posted'` is written;
nothing in the posting method mentions numbering, and the code must force a flush before it can rely on
the name existing.

**Rejected.** Allocation is an explicit step in `PostingService` with a named Action, testable
independently of validation.

## R26 — Un-posting by state flip
Odoo's `button_cancel` walks posted → draft → cancel, transiently returning a posted document to draft and
deleting its analytic lines on the way.

**Rejected.** No un-post path exists in QAYD. Correction is a reversing entry; the original stays posted
forever, with its number and hash intact.

---

# Summary

| Tier | Items | Indicative effort |
|---|---|---|
| High value | 12 | ~159 pts (several already scheduled) |
| Medium value | 16 | ~155 pts |
| Low value | 9 | ~45 pts, mostly deferrable |
| Rejected | 26 | — |

**If only five things are actioned — all small, all with a closing window:**

| # | Item | Points | Why now |
|---|---|---|---|
| 1 | **H1** — decide dimension storage | **0 to decide** | Costs nothing today; a migration on the largest table in the system later. |
| 2 | **H11** — lifecycle transition map | **3** | Cost grows with every Action written against the implicit rules. |
| 3 | **H3** — fix the posting concurrency defect | **3** | Removes a company-wide serialization point from the hottest write path. |
| 4 | **H6** — CI RLS introspection test | **5** | Turns QAYD's tenancy convention into a mechanism. |
| 5 | **H2** — period-balances rollup | **8** | The largest scalability win available, and only safe because the ledger is append-only. |

Total: **19 points of build**, plus one decision that costs nothing to make today.

Separately and urgently, **H7** (the `SET LOCAL` / PgBouncer audit) must be verified *before* connection
pooling is enabled, not after. It is filed at 8 points but the *check* is an afternoon; it is listed
under performance in some sources and is in fact a cross-tenant data-leak check. Two independent agents
in this study reached it from different subsystems.

**The one-sentence summary of the whole study:** Odoo's greatest strengths are its *conceptual* models —
one document type, dimensions as data, reports as data, tax repartition as data, residuals derived from
links — and its greatest weaknesses are that almost none of those models are enforced by the database;
QAYD's opportunity is to adopt the concepts and put every invariant in PostgreSQL.

---

*No Odoo source is reproduced. Every design named above is an original QAYD proposal; citations exist in
`ODOO_LEARNING.md` so each claim can be independently verified against the studied commit.*
