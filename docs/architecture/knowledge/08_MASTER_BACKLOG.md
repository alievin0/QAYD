# 08 — Master Backlog

**Everything learned, triaged into a single scheduling spine**

Version 1.0 · 2026-07-28 · Part of `docs/architecture/knowledge/`
Inputs: Phase 1 Odoo research (`docs/research/odoo/`), knowledge-base documents 01–07 and 09, the real
sprint plans (`docs/execution/SPRINT_02..04.md`), and `TECH_DEBT.md`.

---

## What this document is

The other eight documents answer *"how should QAYD be engineered?"* This one answers **"in what order, and
what does it depend on?"**

It is deliberately **not a parallel roadmap**. Sprints 2, 3 and 4 are already planned in detail, and those
plans are good. Most of what the research produced does not need new stories — it needs to be *slotted into
stories that already exist*, as acceptance criteria and design constraints. Where that is the case, this
document names the story, so nothing is re-litigated at implementation time.

**How to use it**

- Before starting any story, read its row here. It names the constraints the research says apply.
- Before proposing new work, add it here and triage it. An idea with no tier and no dependency analysis is
  a wish, not a backlog item.
- When a trigger metric fires (see `05_FUTURE_ARCHITECTURE.md`), promote that tier's items.

**Every item carries:** Value · Complexity · Priority · Dependencies · Recommended Sprint.

| Field | Scale |
|---|---|
| **Value** | Critical (correctness / compliance / security) · High · Medium · Low |
| **Complexity** | Fibonacci points |
| **Priority** | P0 (do now) · P1 (this sprint) · P2 (next) · P3 (when triggered) |
| **Confidence** | High / Medium / Low — how sure we are this is the right thing to do |

---

## 0 · The intake rule

> **No idea enters QAYD's plan without a tier, a value, a dependency list, and a named sprint — or an
> explicit rejection with a reason.**

The failure this prevents is the one every long-lived ERP suffers: a decade of half-features nobody can
justify and nobody dares delete. Odoo's `secure_sequence_number` — a live column, a dead code path, written
only by tests — is what that looks like at year fifteen.

---

# TIER 1 — IMMEDIATE

**Definition:** correctness, security, or decisions whose cost rises with every day of delay. Roughly
**22 points total** — not a sprint's worth — but they gate or contaminate everything after them.

## IM-01 — Close the AI-cannot-post gap ⚠️ *verified defect*

| | |
|---|---|
| **Value** | **Critical** — the single load-bearing AI safety guarantee |
| **Complexity** | 2 |
| **Priority** | **P0** |
| **Dependencies** | none |
| **Sprint** | Immediate — **before S3-08 ships any AI suggestion path** |
| **Confidence** | High (verified in the migration) |

`trg_no_ai_autopost` is `BEFORE **INSERT**` only (`2026_07_28_000004:154`). It blocks *creating* an
`ai_generated` entry in a non-draft status. It does **not** block `UPDATE`-ing an existing AI draft to
`posted` — only application code stands in the way. That violates the principle that the database owns
integrity, on the one rule the entire AI architecture rests upon.

**Fix:** a `BEFORE UPDATE` trigger requiring `approved_by IS NOT NULL` for any transition of an
`ai_generated` row into a posted state. The highest value-to-cost item in this document.

## IM-02 — Remove the platform-admin bypass from `audit_logs` ⚠️ *verified defect*

| | |
|---|---|
| **Value** | **Critical** — tamper-evidence a privileged session can forge is not tamper-evidence |
| **Complexity** | 3 |
| **Priority** | **P0** |
| **Dependencies** | requires deciding the legitimate platform-admin read path |
| **Sprint** | Immediate |
| **Confidence** | High (verified at `2026_07_27_000010:173-186`) |

`audit_logs` carries `OR app_is_platform_admin()` inside its **RESTRICTIVE** boundary, in both `USING` and
`WITH CHECK`. A platform-admin session can therefore read across tenants **and author audit rows attributed
to any tenant** — on the one table whose purpose is proving nobody did that. Every other tenant table is
correctly unwired, so this is an inconsistency rather than a design.

**Fix:** drop the bypass from `WITH CHECK` unconditionally — nobody should ever *write* another tenant's
audit row. For reads, replace the ambient bypass with the `PlatformOperation` pattern
(`03_DESIGN_PATTERNS.md`): a distinct role, a narrow policy, a written reason, and its own audit record.

*This also corrects a Phase 1 claim that `is_platform_admin` was unwired. It was not.*

## IM-03 — Make `ledger_entries` append-only by grant, not only by trigger

| | |
|---|---|
| **Value** | High — defence in depth on the ledger's core guarantee |
| **Complexity** | 1 |
| **Priority** | **P0** |
| **Dependencies** | none |
| **Sprint** | Immediate |
| **Confidence** | High (verified at `2026_07_28_000007:122,128`) |

The table still carries `ledger_entries_tenant_update` / `_tenant_delete` policies and full CRUD grants;
append-only rests solely on `trg_ledger_entries_append_only`. A single trigger is a single point of failure
for the property the whole architecture depends on. `audit_logs` already does this correctly — copy it.

**Fix:** `REVOKE UPDATE, DELETE` from the runtime role; drop the vestigial policies; keep the trigger.

## IM-04 — Fix the posting concurrency defect (TD-13)

| | |
|---|---|
| **Value** | High — removes a company-wide serialization point from the hottest write path |
| **Complexity** | 3 |
| **Priority** | P0 |
| **Dependencies** | best done alongside S2-07, which rebinds the calendar seam |
| **Sprint** | Immediate, or with S2-07 |
| **Confidence** | High |

`PostingService` locks the **fiscal-year row** `FOR UPDATE`, so every concurrent posting in a company-year
queues behind one row. Serialization is genuinely required only for gapless numbering, which
`JournalNumberAllocator` already provides via `ON CONFLICT DO UPDATE`.

**Fix:** plain read of the calendar; keep the sequence-row lock. **Must ship with concurrency tests**,
including one where a random subset of transactions rolls back after allocation and the surviving numbers
must still be contiguous.

## IM-05 — Decide the analytic-dimension storage model (TD-14) — **a decision, not code**

| | |
|---|---|
| **Value** | **Critical** — the only item whose cost provably rises with delay |
| **Complexity** | **0 to decide**; ~21 to implement later |
| **Priority** | **P0 (decide now)** |
| **Dependencies** | needs a real ADR — it contradicts the frozen spec |
| **Sprint** | Decide immediately; implement in Sprint 5 |
| **Confidence** | High on the analysis; the **decision is the Architecture Owner's** |

The frozen spec plans fixed `cost_center_id` / `project_id` / `department_id` columns. The research
recommends **rows** in `journal_line_dimensions`, rejecting both fixed columns *and* Odoo's JSONB. Full
argument in `AD-11` (`02_ARCHITECTURE_DECISIONS.md`).

**A third option surfaced late, from Microsoft Dynamics Business Central** (whose Base Application is MIT
open source and was read directly): intern each distinct *dimension set* in a **trie** keyed on
`(parent_set_id, dimension_value_id)`, and put a single integer `dimension_set_id` FK on the ledger line.
Properties: exact by construction (no hash collisions), prefix-shared across sets, and — the important
part — **it takes no write lock at all in the common case** of a set that has been seen before.

**Assessment:** the trie is genuinely elegant and better than rows *for read performance* when a line
carries one value per dimension. But it **cannot express percentage allocation** ("60% Project A / 40%
Project B"), which QAYD requires. The recommendation therefore stands at **rows** — with the trie recorded
as the optimisation to adopt if allocation turns out to be rare in practice, since a set-interning table
can be layered over a row model later without changing the ledger.

**Refinement (Phase 3 ERP research) — the row's *contents* should change, and this is time-sensitive
in the same way the storage choice is.** Three systems with no shared lineage — **Sage Intacct**
(`SPLIT.AMOUNT` under a named `ALLOCATIONID`), **Tryton** (`analytic_account.line.debit/credit` under a
`type='distribution'` account) and **Odoo's own materialised rows** — independently converge on two
things the current recommendation does differently:

1. **The allocation policy is a named, reusable object**, not percentages inlined per line.
2. **What lands on the stored row is resolved money, not a percentage.**

Adopt both. Amounts make `journal_line_dimensions` independently aggregable — a dimensional report
becomes `SUM(amount)` with no per-report re-derivation — and confine rounding to **one tested place**
instead of every consumer. Percentages remain on the reusable allocation policy, where they are authored.
**Change this before any allocation data exists**; afterwards it is a data migration.

**Also confirmed:** Apache OFBiz supplies the mature failure mode of the fixed-column approach that AD-11
previously had to argue for in the abstract — ten frozen nullable dimension columns, and percentage splits
simply not expressible. And **Sage Intacct's storage is unremarkable** (named fields on `GLENTRY`); its
strong reputation comes entirely from *ergonomics* — hierarchies, cross-dimension autofill, reusable
allocations. **QAYD's dimensional gap is therefore ergonomic, not structural**, which makes AI-inferred
dimension suggestion a genuine differentiator against the category leader rather than a nice-to-have.

**Governance:** the architecture is frozen at `architecture-freeze-v1`, and MANIFEST Law 1 requires
*new ADR → update the doc → continue*. **A knowledge-base document cannot overturn a frozen spec.** This
needs an ADR before TD-14 is implemented.

## IM-06 — Lifecycle transition map

| | |
|---|---|
| **Value** | High — prevents rule-scatter before it starts |
| **Complexity** | 3 |
| **Priority** | P1 |
| **Dependencies** | none |
| **Sprint** | Immediate — **before S2-06 adds reversal states** |
| **Confidence** | High |

`journal_entry_status` has 8 values, and exactly two constants express any of the rules
(`POSTABLE_STATUSES`, `EDITABLE_STATUSES`). Odoo reached six scattered call sites from this exact starting
point. One `JournalEntryLifecycle::TRANSITIONS` map plus a mirroring database trigger. Costs 3 points today
and grows linearly with every Action written against implicit rules.

## IM-07 — CI catalog-introspection RLS test

| | |
|---|---|
| **Value** | High — converts a convention into a mechanism |
| **Complexity** | 5 |
| **Priority** | P1 |
| **Dependencies** | none |
| **Sprint** | Immediate |
| **Confidence** | High |

Query `pg_class` / `pg_policy` / `pg_attribute`; fail the build if any table with a `company_id` column
lacks `NOT NULL`, `relrowsecurity`, `relforcerowsecurity`, and the named restrictive policy. Every table
added between now and Tier 2 is a table this protects.

## IM-08 — Tenant-context helper for non-HTTP entry points

| | |
|---|---|
| **Value** | High — preventive; the failure mode is a silent cross-tenant leak |
| **Complexity** | 5 |
| **Priority** | P1 |
| **Dependencies** | **must land before the first queue job** |
| **Sprint** | Immediate (S3-07 introduces the first async work) |
| **Confidence** | High |

*Verified: nothing leaks today.* The HTTP middleware correctly uses transaction-local
`set_config(..., true)` (`ResolveTenantCompany.php:85-90`), and there are no queue jobs yet. The risk is
that the first job written has no correct pattern to copy, and the naive implementation — a session-scoped
`SET` on a long-lived worker connection — fails **open**, with plausible-looking data.

**Fix:** a `RunsInTenantContext` helper (open transaction → `set_config(..., true)` → run), plus a test
asserting two sequential jobs for different companies cannot see each other's rows.

## IM-12 — Decide whether a firm tenancy sits above `company_id` ⚠️ *the only truly irreversible decision found*

| | |
|---|---|
| **Value** | **Critical** — it gates the only distribution channel that makes QAYD's chosen sale possible |
| **Complexity** | **0 to decide.** Impossible to retrofit later |
| **Priority** | **P0 (decide before the first customer exists)** |
| **Dependencies** | none — and that is the point |
| **Sprint** | Immediate |
| **Confidence** | High |

Phase 3's competitive analysis identified this as **the sole recommendation in the entire research
programme that becomes *impossible* rather than merely expensive once real customer data exists.** Every
other item on this list — dimensions, idempotency, composite FKs, the hash chain — is a migration. This
one is not, because retrofitting a tenancy level *above* `company_id` means rewriting the RLS boundary
that every table, policy and test in the system is built on, while live.

**The reasoning:** QAYD has chosen the hardest sale in the category — **replacing the general ledger** —
in the one GCC market with **no compliance forcing function before 2028**. That combination means the
accountant/bookkeeper channel is not a growth *option*; it is the **only mechanism that makes the sale
possible at all**. A practice managing 40 client companies needs cross-client staff, permissions,
templates and billing — which requires a firm-level tenancy above the company boundary.

**Decide now**, even if the firm layer is not built for a year: the schema must permit it, or the channel
closes permanently.

**Related strategic conclusions from the same analysis, recorded so they are not lost:**
- Of **55 apparent market gaps, only 21 are real**; 22 are gaps *because the idea is bad*, 5 are
  temporary, and 7 are mirages. Distinguishing these is the analysis's main value.
- **Only five gaps are worth organising a company around**, and each is defensible precisely because it
  is downstream of a *disadvantage* of QAYD's market: no bank feeds forces statement-primary modelling
  with a tie-out control that feed-first products structurally cannot have; a three-decimal home currency
  forces variable precision that Xero and QuickBooks demonstrably lack.
- The `WORLD_CLASS_FEATURES.md` catalogue holds **227 features — of which 67 are "deliberately skip."**
  That column is the most valuable one in it.
- ⚠️ **The headline provenance claim is currently falsifiable.** "The agent cannot post" is true on
  `INSERT` and **false on `UPDATE`** (see **IM-01**). **Fix that 2-point defect before any positioning,
  marketing or sales claim rests on it.**

---

## IM-11 — Idempotency has a dual-write hole ⚠️ *verified against the written spec (Phase 3)*

| | |
|---|---|
| **Value** | **Critical** — it is the one component whose entire job is preventing double-posted money |
| **Complexity** | **8** (not the 3 previously assumed) |
| **Priority** | **P0** |
| **Dependencies** | needs an ADR — it contradicts `docs/api/API_ARCHITECTURE.md` |
| **Sprint** | S2-13 |
| **Confidence** | High |

The specified design stores the idempotency key **in Redis, after the business transaction commits, only
on success**, under a 10-second lock. Three failure modes follow:

1. **Crash between commit and key-write** → the journal entry is durable, the key is absent, and the
   client's retry **posts it again**.
2. **Storing only successes** → a 500 raised *after* posting causes the retry to re-execute.
3. It is a **dual write** — precisely the bug the transactional outbox pattern exists to eliminate,
   sitting in the component least able to tolerate it.

**Fix:** write the idempotency key **in the same transaction as the fact**, and store the *outcome*
regardless of success or failure — Stripe stores it "regardless of whether it succeeds or fails,
including 500 errors." This is a database-table pattern, not a Redis-lock pattern. **S2-13 is an 8-point
story, not 3**, and it needs an ADR because it contradicts a written spec.

**Two secondary findings, both verified in code:**

- **`journal_lines.currency_code` has no constraint tying it to its parent entry**, and
  `assertBalanced()` does not partition by currency. `WritesJournalDraft` always writes the header
  currency to every line (`:78`), so it is not reachable through the Action path — but the guarantee is
  application-only, the same class of gap as **IM-09**. A multi-currency entry that balanced in base but
  not per-currency would pass. **Cheap fix: a CHECK constraint, not an engine change.**
- **`journal_lines.reconciled` / `reconciled_at` are written by nothing** — no code, no test, no factory;
  only model casts exist. Worse, `fn_block_update_when_posted` makes them **unwritable once the entry is
  posted**, which is exactly when reconciliation would need them. **These columns are unusable as
  designed** and should be dropped, with reconciliation state living in the side tables already
  recommended (H8). This is Odoo's `secure_sequence_number` dead-column failure — reproduced at year zero
  rather than year fifteen.

**And a strategic reframing for Sprint 3, which changes what S3-04 *is*:** no account-aggregation
provider covers Kuwait; the CBK publishes no account-information-service licence or API standard; KNET
publishes no public API. **CSV statement import is therefore not an MVP compromise on the way to Open
Banking — there is no Open Banking rail to connect to.** S3-04 *is* the product, and the accumulating
library of per-bank column mappings is a genuine moat rather than throwaway glue. Fund it accordingly.

---

## IM-10 — Settle the AI engine's database boundary ⚠️ *contradiction between existing documents*

| | |
|---|---|
| **Value** | **Critical** — it determines whether control-flow integrity is enforceable at all |
| **Complexity** | **0 to decide**; the decision is architectural, not code |
| **Priority** | **P0 (decide now)** |
| **Dependencies** | must be settled **before S3-07 writes transport code** |
| **Sprint** | Immediate |
| **Confidence** | High on the recommendation |

Two Phase 2 documents currently disagree, and Sprint 3 is about to implement one of them:

- **`01_ENGINEERING_PRINCIPLES.md` P15** (and gap **G-8**) — the AI engine has **no database driver at all**.
- **`03_DESIGN_PATTERNS.md` P-12** — the AI engine holds a **`qayd_ai` database role** with `INSERT`
  limited to proposal tables.

Both are defensible; they cannot both be built. **Recommendation: no database driver in `apps/ai`.**
Retrieval becomes a Laravel-mediated read API, and proposals are submitted over that same API. Reasons:
it is the cheapest auditable enforcement in the system (a capability the AI process does not possess
cannot be misused, and requires no policy review to prove); it keeps every tenant-scoped read inside the
RLS path that is already tested; and it preserves **control-flow integrity** — code, not model output,
decides what happens next.

**The underlying architectural finding is stronger than the boundary question, and should be recorded as
its own principle:** QAYD should **not build an "agent"** in the autonomous-loop sense. It should build a
**quarantined, capability-scoped, deterministic proposal pipeline** in which the model is a *pure
function* — untrusted tokens in, typed proposal out — and **code alone chooses the control flow**. Google
DeepMind's CaMeL work pays a measured ~7-percentage-point utility cost to manufacture exactly that
property artificially; QAYD gets it **free**, because bookkeeping's task list is enumerable rather than
open-ended. This is the single most important architectural statement in the AI research.

Two consequences to fold into Sprint 3/4 planning:
- The **13 "agents"** described in `docs/ai/` should be **13 capability configurations on one runtime**,
  not 13 independent loops. Anthropic measured multi-agent architectures at roughly **15× the tokens**
  and explicitly named dependency-dense, shared-context work — which is precisely what accounting is — as
  the poor-fit case.
- **R-02 is answered:** **pgvector stays in the primary database**, conditional on never embedding raw
  document text, with four named revisit triggers (see `docs/research/ai/`).

---

## IM-09 — Composite tenant-scoped foreign keys ⚠️ *verified gap (Phase 3)*

| | |
|---|---|
| **Value** | High — closes a cross-tenant integrity hole the database currently cannot catch |
| **Complexity** | 5 |
| **Priority** | P1 |
| **Dependencies** | none |
| **Sprint** | Immediate |
| **Confidence** | High — PostgreSQL behaviour is documented; QAYD's schema verified |

**PostgreSQL referential-integrity checks always bypass row-level security** — this is documented
behaviour, and it is described in the PostgreSQL documentation as a covert channel. Consequently an RLS
policy does **not** constrain what a foreign key may point at.

Verified against QAYD's schema: `ledger_entries` carries FKs to `journal_entries(id)`,
`journal_lines(id)`, `accounts(id)` and `fiscal_years(id)` — **none of them composite with
`company_id`** (`2026_07_28_000007:35-39`). The RLS `WITH CHECK` constrains only
`ledger_entries.company_id` itself. So at the database level, nothing prevents a row belonging to company
A from referencing company B's journal line, account, or fiscal year.

Additionally, `uq_ledger_entries_journal_line UNIQUE (journal_line_id)` omits `company_id`, which makes it
a **cross-tenant existence oracle**: an insert referencing another tenant's `journal_line_id` fails with a
unique violation, revealing that the line exists and is already projected.

**Not currently reachable through the application** — `PostingService` resolves every id inside tenant
context. But that is exactly the "application-only integrity" this project's own principles reject
(`04_REJECTED_PATTERNS.md`), and no conventional tenancy test detects it, because the boundary holds for
every query and fails only at the constraint layer.

**Fix:** add `UNIQUE (id, company_id)` to the parent tables, then make the child references composite —
`FOREIGN KEY (journal_line_id, company_id) REFERENCES journal_lines (id, company_id)`. "The referenced row
belongs to the same tenant" then becomes a database guarantee rather than a convention. This is the same
technique recommended for dimension members in `AD-11`. Extend the **IM-07** CI introspection test to fail
the build on any tenant-table FK that is not tenant-composite.

*(`uq_account_types_key UNIQUE (key)` is correctly non-composite — `account_types` is a global catalogue,
not tenant data.)*

---

# TIER 2 — SPRINT 2 REMAINDER (S2-06 … S2-14)

These stories exist and are well-specified. The research adds **constraints**, not scope.

| Story | Constraints to apply | Source |
|---|---|---|
| **S2-06** Reverse & void | Reversal is a posted mirror **through `PostingService`** — never a special path. Add `reversal_reason NOT NULL`, `reversed_by_user_id`, `reversal_kind ∈ {full,partial,storno}`. DB-enforce `CHECK (reversal_of_entry_id <> id)`, a cycle-rejecting trigger, and a partial unique index so an entry is **fully** reversed at most once. **Reject silent date-shifting** — raise, and return a suggestion the caller must accept. Build the `ReversalStrategy` seam now; implement storno only when a market needs it. | Backlog M3, P-13 |
| **S2-07** Fiscal years & periods | **Periods as dimension, locks as cursor, status as a VIEW over the cursor.** Enforcement in a lock trigger, never a writable status column. Add `lock_exceptions` (time-boxed, `reason NOT NULL`, hard locks never excepted, two-pass evaluation). Fold in **IM-04**. | AD-10, Backlog H4/M1 |
| **S2-08** General ledger reads | Running balance via window function; never a stored mutable balance. All reads through the append-only projection. | P-15 |
| **S2-09** Trial balance + snapshot | **`account_period_balances` rollup** maintained by an AFTER INSERT trigger on `ledger_entries` — monotonic, and therefore trustworthy, *only because* the source is append-only. Ship `RebuildPeriodBalancesAction` as a drift detector in CI and on a schedule. Snapshots immutable once approved. ⚠️ **Correction (Phase 3 analytics research):** the per-row `CHECK (closing = opening + debit − credit)` is **not sufficient**. It validates each row in isolation, so a faulty cross-period rebuild can leave *every row individually valid* while breaking the opening→closing chain **between** periods — producing a silently wrong balance sheet that no constraint catches. Add a chain assertion: each period's `opening` must equal the prior period's `closing` per `(company_id, account_id)`, enforced as a deferred constraint trigger or asserted by the nightly integrity job (S2-14). ~3 points. | Backlog H2 |
| **S2-10..12** COA / editor / TB screens | The frontend computes nothing authoritative; any client-side balance indicator must use the same exact arithmetic semantics as the server, never float. | Principle P7 |
| **S2-13** Idempotency + posted-event broadcast | **Transactional outbox** — the event row is written inside the posting transaction and drained after commit with `FOR NO KEY UPDATE SKIP LOCKED`. Broadcast a *signal*; fetch content through RLS-enforced reads. Channel authorization server-side — never rely on unguessable channel names. | Backlog M14, P-11 |
| **S2-14** Nightly ledger integrity job | Verify rollup drift (recompute vs stored), gapless numbering, `SUM(signed_base_amount) = 0` per company, and — once S4+A lands — the hash chain. | Backlog L1, H5 |

**Also fold into Sprint 2 where cheap:**

| ID | Item | Value | Cx | Pri | Sprint |
|---|---|---|---|---|---|
| S2+A | Aggregate **all** validation failures into one structured `violations[]` response | High (AI round-trips) | 3 | P1 | S2-06 |
| S2+B | `SetOpeningBalanceAction` (TD-10) — a normal entry through the normal posting path, `date = opening_date − 1`, explicit acknowledged residual, **no auto-plug** | Medium | 5 | P2 | S2-07 |
| S2+C | Ledger-backed `PostedActivityGuard` (TD-11) — swap the no-ledger stub now that the ledger exists | Medium | 2 | P1 | S2-08 |

---

# TIER 3 — SPRINT 3 (banking, reconciliation, AI skeleton)

The existing S3 plan already matches the research's recommended shape: deterministic first, AI proposing
second, human confirming third. Constraints:

| Story | Constraint | Source |
|---|---|---|
| **S3-04** CSV import + tie-out | **Suspense-account invariant** — import to suspense immediately, so the bank balance is correct *before* matching and the suspense balance *is* the unmatched backlog. | Backlog M5 |
| **S3-05** Deterministic reconciliation engine | **Residuals derived from link rows, never stored on the ledger row** — this is what keeps `ledger_entries` immutable. Partial/full decomposition; three-amount partials for cross-currency. **Unreconcile by INSERT of a compensating link, never DELETE.** Deferred constraint trigger for `SUM(matched) ≤ original`; ordered `FOR UPDATE`; **concurrency tests as a first-class deliverable** (Odoo has zero among its 95 reconciliation tests). | Backlog H8/M6 |
| **S3-06** Variance + period close | `period_close_runs` with a four-eyes CHECK and a partial exclusion constraint preventing concurrent runs; capture an immutable trial-balance snapshot + hash at close. | AD-10 |
| **S3-07** AI engine skeleton | Enforce the boundary **by database GRANT**: the AI role may `INSERT` only into proposal tables, never into domain tables. Application code is not the enforcement layer. | P-12 |
| **S3-08/09** AI suggestions + accept/reject | Every proposal carries confidence, model version, and rationale. Promotion happens through a normal Action recording `applied_from_suggestion_id`, so accuracy is measurable against human corrections. | P-12 |

| ID | New item | Value | Cx | Pri | Dependencies |
|---|---|---|---|---|---|
| S3+A | **Correction Corpus (I-09)** — treat reversals and rejected proposals as labelled training data from day one. Nearly free designed in now; expensive to backfill. | High | 3 | P1 | S3-09 |
| S3+B | Reconciliation rebuildable read model + `RebuildReconciliationGroupsAction` | Medium | 3 | P2 | S3-05 |

---

# TIER 4 — SPRINT 4 (AI gateway, statements, copilot)

| Story | Constraint | Source |
|---|---|---|
| **S4-01** Proposal gateway + AutonomyResolver | The canonical **AI Action Pattern** implementation. Autonomy must be per-tenant, per-capability, and **reversible** — governed by a reversibility budget (I-17) rather than a binary switch. | P-12, I-17 |
| **S4-03** `ai_draft` intake | **Depends on IM-01 being closed. Do not ship without it.** | IM-01 |
| **S4-05..07** Statements | **Build the statements hard-coded first; extract an engine only after two exist.** Building the declarative engine first is exactly how Odoo ended up with a regex formula grammar. Balance sheet: current-year earnings derived, no closing entry. Cash flow: `cash_flow_bucket NOT NULL` as a total, disjoint partition, so the reconciliation identity is structural rather than checked. | Backlog M2/M10/M11 |
| **S4-08** Statement screens + export | **Number Provenance (I-12)** — every figure dereferenceable to its constituent rows. Cheap if designed in at first render; near-impossible to retrofit. | I-12 |
| **S4-11** Cost/rate governance | Model tiering + prompt caching + batching. Per `05_FUTURE_ARCHITECTURE.md`, disciplined versus naive AI spend is roughly **$14 vs $45 per customer per month** — the difference between 80% and 45% gross margin. | 05 |

| ID | New item | Value | Cx | Pri | Dependencies |
|---|---|---|---|---|---|
| S4+A | **Hash-chain activation** (TD-06) — ⚠️ **downgraded on Phase 3 evidence; see the note below** | Medium | 21 | **P3** | IM-02, IM-03, **BR-01** |
| S4+B | Field-level access control (cost prices, salaries, bank details) — Resource-layer attribute plus Postgres column `REVOKE` | Medium | 8 | P2 | — |
| S4+C | RLS diagnostics (not-found vs not-visible), rate-limited and audited | Medium | 8 | P2 | — |

### ⚠️ Integrity work, resequenced on Phase 3 banking evidence

Two findings from the core-banking research change the order of the integrity work, and both cut against
what earlier phases assumed.

**1. QAYD is already ahead of most of this market on immutability — so the gap is *proof*, not
immutability.** Of the core-banking vendors studied, **exactly one (Increase) publicly states its ledger
is immutable**. Mambu documents a GL that is **mutable until period close**, with operator-deletable
closures; Temenos publishes an API for *"updating, retrieving and deleting journal entries."* The
widely-repeated belief that core banking ledgers are uniformly append-only is **not supported by published
vendor documentation**. QAYD's append-only `ledger_entries` is therefore stronger than most of the
category, and the remaining work is demonstrating that it is intact — not making it intact.

**2. Cheap proof beats expensive proof, for the failures that actually happen.** Control totals plus
re-derivation (the **S2-14** nightly job, ~3 points) catch every *realistic* failure mode — projection
bugs, partial posts, double-posts, corrupted rollups — and catch them **better** than a hash chain does.
A hash chain defends against a different and much rarer threat: a malicious actor with direct database
access. It is worth building eventually; it is **not** worth building before the cheap proof exists.
Hence S4+A drops to **P3**.

**3. The blocking prerequisite nobody had noticed — `BR-01`.**

| | |
|---|---|
| **Value** | **Critical** — without it, the entire audit and integrity chain protects the wrong thing |
| **Complexity** | 3 |
| **Priority** | **P0** |
| **Dependencies** | IM-02 (close the audit write-hatch first) |
| **Sprint** | Immediate |
| **Confidence** | High |

**Posting currently writes no `audit_logs` row at all (TD-16).** So a hash chain over `audit_logs` today
would cryptographically protect *audit trivia* while leaving the posting path — the thing that actually
matters — entirely unattested. Make `PostingService` write its audit record inside the posting
transaction. 3 points, and it unblocks the value of everything downstream.

**Resulting order:** `IM-02` (close the write-hatch) → `BR-01` (posting writes audit rows) →
`S2-14` (control totals + re-derivation) → *later, and only then* → `S4+A` (hash chain).

The security research independently reached the same first step by a different route: chaining a table
that still permits cross-tenant `WITH CHECK` writes yields **a cryptographic guarantee of the integrity of
a forgery**, which is strictly worse than no chain at all.

---

# TIER 5 — SPRINT 5 (proposed; not yet planned)

The first sprint with no existing plan. Recommended theme: **dimensional and tax foundations**, because
both gate everything commercial that follows.

| ID | Item | Value | Cx | Pri | Dependencies | Conf |
|---|---|---|---|---|---|---|
| S5-A | **Analytic dimensions as rows** (IM-05 implementation) — `dimensions`, `dimension_members`, `journal_line_dimensions` with composite FK + deferred constraint trigger; applicability and distribution rules | Critical | 21 | P0 | IM-05 ADR | High |
| S5-B | **Tax engine core** — repartition lines as data; ±100% invariant as a deferred constraint trigger; factors as integer ppm, not floats | High | 34 | P1 | S5-A | Medium |
| S5-C | **Persist tax→GL and tax→VAT-box links at post time** — makes the VAT return a `GROUP BY` instead of a reconstruction with an admitted "approximate" branch | High (see note) | 8 | P1 | S5-B | High |
| S5-D | **Exchange-rate subsystem** — GiST exclusion on validity windows; `rate_type`; `source` provenance; `rate_unit` for weak currencies; **never default a missing rate**; immutable once referenced by a posted entry | High | 13 | P1 | — | High |
| S5-E | Deterministic penny distribution with an auditable allocation trace | High | 8 | P1 | S5-B | High |
| S5-F | Cost centres + budgets on the dimension foundation | Medium | 21 | P2 | S5-A | Medium |

**⚠️ Convention warning for S5-D:** Odoo's rate convention is the **inverse** of QAYD's
(`base = amount × rate`). Any formula lifted from that research must be flipped before use.

**⚠️ Market correction — this changes the tax priority (verified during Phase 2 research):**
**Kuwait has no VAT and no e-invoicing mandate, and none is currently scheduled.** The only live
Kuwaiti surface is the Domestic Minimum Top-up Tax under Decree-Law 157/2024, which applies solely to
multinational groups above the €750M threshold — not to QAYD's SME target.

Consequences, and they matter:
- The tax engine (S5-B/C) is **not** a domestic-Kuwait compliance blocker. It was rated "Critical
  (compliance)" on an unexamined assumption, and that rating was wrong.
- It **is** required for **GCC expansion** — Saudi Arabia, the UAE, Bahrain and Oman all levy VAT, and
  Saudi ZATCA e-invoicing is a hard technical mandate.
- Therefore: **tax is an expansion gate, not a launch gate.** If the first customers are Kuwaiti SMEs,
  S5-B/S5-C can move behind dimensions (S5-A) and reporting. If the first design partner is Saudi or
  Emirati, they become P0 immediately.
- **This is a go-to-market question, not an engineering one.** Sequence it once the first target market
  is decided. R-03 (GCC mandate research) should be answered before Sprint 5 is planned.

**Worth evaluating for S5-B (from Business Central):** *posting groups* — documents carry only
*classifications* (business group × product group), and a setup table maps each combination to accounts.
Documents then never reference an account number, so changing the chart of accounts becomes a data change
and a GCC- or auditor-mandated chart becomes a configuration swap. Cost is cardinality (|business| ×
|product|). This is a genuinely better idea than hard-coding account references into document logic.

---

# TIER 6 — FUTURE (planned, not yet scheduled)

| ID | Item | Value | Cx | Pri | Dependencies |
|---|---|---|---|---|---|
| F-01 | Unrealized FX revaluation (IAS 21 / Kuwait statutory) — **no open-source reference implementation exists**; Odoo Community lacks it entirely | High | 13 | P2 | S5-D |
| F-02 | Fixed assets + depreciation (Enterprise-only in Odoo — nothing to study) | High | 34 | P2 | S5-A |
| F-03 | Declarative report engine — extracted *after* statements exist, with typed operand rows rather than formula strings, and cycle detection by recursive CTE | High | 13 | P3 | S4-05..07 |
| F-04 | Multi-entity consolidation — snapshot-and-freeze; the only legitimate cross-tenant read, under a dedicated audited role | High | 34 | P3 | F-03 |
| F-05 | `ledger_entries` partitioning by company/period | Medium | 13 | P3 | trigger: >1M rows for any single tenant-year |
| F-06 | Inventory valuation (AVCO/FIFO) persisting `unit_cost_after` — **not** full-history replay, the mistake Odoo 19 made | Medium | 34 | P3 | S5-A |
| F-07 | **Regulatory output as three-layer configuration** (from Dynamics ER): abstract model → per-release mapping → per-country format, each independently versioned with merge/rebase. For ZATCA / UAE FTA / Oman / future Kuwait e-invoicing, this is the difference between a regulatory change being a config release and a code release. **Do not hard-code XML per country.** | High | 21 | P2 | F-03 |

---

# TIER 7 — RESEARCH (must be answered before it can be estimated)

| ID | Question | Why it matters | Effort |
|---|---|---|---|
| R-01 | Is cross-tenant aggregate learning (**I-14 Federated Benchmarks**) genuinely safe under our RLS model, or does it leak through small-tenant cells? | Determines whether a whole product class is possible | 8 |
| R-02 | pgvector in the primary database vs a separate vector store | Shapes the AI cost curve and the ops surface | 5 |
| R-03 | What GCC e-invoicing mandates will require, and when | Regulatory deadlines are not negotiable; gates F-07 | 8 |
| R-04 | Liability model for AI-originated postings — who is responsible when a confidently-wrong number is filed | Gates every autonomy increase | 5 |
| R-05 | Per-tenant restore ("can you restore one customer's books to Tuesday?") on a shared database | AD-02's unrehearsed assumption | 8 |

---

# TIER 8 — EXPERIMENTAL (prototype before committing)

From `07_QAYD_INNOVATION.md`. These are hypotheses, not commitments.

| ID | Idea | Value | Cx | What the prototype must prove |
|---|---|---|---|---|
| X-01 | **I-08 Offline-verifiable audit receipts** | High | 13 | Can an auditor verify without our servers? Build the verifier first. |
| X-02 | **I-06 Close as a continuously-maintained diff** | High | 21 | Does "always closed" survive a real month-end? |
| X-03 | **I-12 Number provenance** | High | 8 | Cheapest of the set — design into S4-08 rather than prototype separately |
| X-04 | **I-10 The Challenger** (adversarial review agent) | Medium | 13 | Does it find real errors, or generate noise? Measure precision before shipping. |
| X-05 | **I-01 Bitemporal ledger** | High | 34 | Knowledge-time reporting is powerful and expensive. Prototype the query shape first. |
| X-06 | **I-03 Ledger branch** (digital twin on real history) | Medium | 21 | Depends on X-05 |

---

# TIER 9 — LONG-TERM (five-year horizon)

| ID | Item | Why it is long-term |
|---|---|---|
| L-01 | **I-15 Provable books as collateral** — a verified-books rail for lenders | Needs an ecosystem, not just a feature; depends on X-01 |
| L-02 | **I-13 Counterparty graph** | Value compounds only with scale |
| L-03 | **I-20 Regime twin** — simulate a compliance change before it lands | Depends on F-03, F-07, R-03 |
| L-04 | Multi-region / data residency | Triggered by a customer contract, not by engineering |
| L-05 | **I-02 Policy replay** / **I-07 Posting firewall** | Powerful, but only once policies are numerous enough to be worth backtesting |

---

# TIER 10 — REJECTED

26 architectural patterns, plus AI-specific ones, are rejected with reasons in
**`04_REJECTED_PATTERNS.md`**. Not repeated here. The headline refusals:

`sudo()`-style ambient privilege · application-only integrity · mutable posted entries · float money ·
auto-balancing suspense plugs · silent date coercion · JSONB accounting dimensions · runtime DDL from user
data · context-gated invariants · generic workflow engines · cross-module coupling by inheritance · a
second writer into the ledger · AI writing domain tables directly.

**One worth restating, because it will be proposed again:** a generic workflow engine. Odoo built one, then
deleted it. The temptation recurs every time a third approval flow appears. The answer is explicit
statuses, explicit Actions, and DB-enforced transitions — see IM-06.

---

# Dependency graph

```
IM-01 AI-post trigger ─────────────► S4-03 ai_draft intake
IM-02 audit bypass ────────────────┐
IM-03 ledger grants ───────────────┴► S4+A hash chain ──► S2-14 integrity job
IM-04 posting concurrency ─────────► S2-07 (do together)
IM-05 dimension DECISION ──────────► S5-A ──┬─► S5-B tax ──► S5-C VAT links
                                            ├─► S5-F cost centres / budgets
                                            ├─► F-02 fixed assets
                                            └─► F-06 inventory valuation
IM-06 lifecycle map ───────────────► S2-06 reverse & void
IM-07 CI RLS test ─────────────────► every future table
IM-08 tenant ctx helper ───────────► S3-07 AI engine (first async work)

S2-09 period balances ─────► S4-05..07 statements ──► F-03 report engine ──┬─► F-04 consolidation
                                                                           └─► F-07 regulatory ER
S2-13 outbox ──────────────► I-11 streaming anomaly detection
S3-05 reconciliation core ─► S3-08 AI matching ──► I-17 autonomous reconciliation
S3+A correction corpus ────► I-09 ──► model improvement loop
X-05 bitemporal ───────────► X-06 ledger branch ──► I-19 provisional ledger
X-01 audit receipts ───────► L-01 books as collateral
```

**Critical path to a sellable product:** IM-01/03 → S2-07 → S2-09 → S3-05 → S4-01 → S4-05..07.
Everything else is enhancement.

---

# Effort summary

| Tier | Items | Points | Notes |
|---|---|---|---|
| 1 Immediate | 8 | ~22 | One is a zero-cost decision |
| 2 Sprint 2 remainder | 9 stories + 3 | planned + 10 | Constraints, not new scope |
| 3 Sprint 3 | 12 stories + 2 | planned + 6 | |
| 4 Sprint 4 | 12 stories + 3 | planned + 37 | Hash chain dominates |
| 5 Sprint 5 (proposed) | 6 | ~105 | Dimensions + tax foundations |
| 6 Future | 7 | ~162 | |
| 7 Research | 5 | ~34 | Answers, not implementations |
| 8 Experimental | 6 | ~110 | Prototype before committing |
| 9 Long-term | 5 | — | Not honestly estimable yet |

**If only five things happen next:**

1. **IM-05** — decide dimension storage (0 points; needs an ADR)
2. **IM-01** — close the AI-post gap (2 points)
3. **IM-03** — ledger grants (1 point)
4. **IM-06** — lifecycle map (3 points)
5. **IM-07** — CI RLS test (5 points)

**11 points of build, plus one decision.** Everything else here can wait; these cannot, because each either
protects a guarantee already claimed or grows more expensive every week.

---

# Maintenance

This document is **live**. Update it when a story completes (mark it), a trigger metric fires (promote the
tier), an experiment concludes (promote it or reject it with a reason), or a new idea arrives (triage it,
or reject it in `04_REJECTED_PATTERNS.md`).

A backlog nobody prunes becomes a graveyard nobody reads.

# End of Document
