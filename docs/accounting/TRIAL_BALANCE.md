# Trial Balance — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Trial Balance
---

# Purpose

The Trial Balance module is the verification and reporting layer that proves the General Ledger is
internally consistent at any point in time. It does not originate financial data — it is a derived,
read-only view computed from posted `journal_lines`, projected through `ledger_entries`, and rolled up
per `accounts`. Its single invariant is the double-entry law: **SUM(debits) = SUM(credits)** across
every account in a company (optionally scoped to a branch, cost center, project, or department) as of a
given date or fiscal period.

In QAYD, the Trial Balance module exists to answer four questions with certainty, on demand, for any
company, branch, or period:

1. **Is the ledger balanced right now?** — a real-time, computed check that total debits equal total
   credits across all posted journal lines, with zero tolerance for variance beyond currency-rounding
   at `NUMERIC(19,4)` precision.
2. **What does the ledger look like at a frozen point in time?** — a durable, immutable snapshot
   (`trial_balance_snapshots`) that can be reviewed, approved, exported, and referenced forever, even
   after subsequent periods post new activity.
3. **What changed between the unadjusted and adjusted view, and why?** — full lineage from an
   Unadjusted Trial Balance (UTB) through adjusting journal entries to an Adjusted Trial Balance (ATB),
   and finally to a Post-Closing Trial Balance (PCTB) after closing entries zero out temporary accounts.
4. **Can a human or an AI agent explain any imbalance or unexpected movement immediately?** — every
   imbalance, anomaly, or material variance surfaces with a machine-generated explanation, a confidence
   score, and a suggested corrective action, without ever mutating posted data.

The Trial Balance module is consumed by three audiences inside QAYD: (a) the Accounting team, who use
it as the mandatory gate before producing financial statements (Balance Sheet, Income Statement, Cash
Flow Statement all derive from an approved Trial Balance); (b) External Auditors, who use the
Post-Closing Trial Balance and its full audit trail as the starting point of a statutory audit; and
(c) AI agents (General Accountant, Auditor, Reporting Agent, Fraud Detection), which continuously
monitor Trial Balance runs for imbalances, anomalies, and forecastable errors, and which propose — but
never autonomously apply — corrections.

Because the Trial Balance has no primary data of its own, this document specifies how it is computed
(the read path from `journal_lines`/`ledger_entries`), how a computed result is frozen into a durable,
approvable, exportable artifact (the write path into `trial_balance_snapshots` and its children), and
how the full lifecycle — Generate → Validate → Review → Approve → Export → Archive — is governed,
audited, and AI-assisted.

# Vision

QAYD's Trial Balance is designed to be the fastest, most explainable, and most trustworthy Trial
Balance in any SME/mid-market accounting platform operating in the Gulf. The long-term vision has four
pillars:

**1. Zero-doubt balancing.** A Trial Balance in QAYD is never "probably balanced." Because
`journal_entries` can only be posted when `SUM(debit_amount) = SUM(credit_amount)` at the entry level
(enforced by a database CHECK-equivalent trigger and a Laravel Service-layer guard before persistence),
an out-of-balance Trial Balance is structurally impossible under normal operation. When one appears
anyway — because of a data-integrity issue, a migration, a manual DB intervention, or corrupted
historical data — the system does not merely flag "not balanced"; it computes the exact variance to the
cent, isolates the smallest set of accounts/periods that explain it, and hands both a human and an AI
agent a ranked list of probable causes with source-document links.

**2. One source of truth, many lenses.** The same computation engine produces the Unadjusted, Adjusted,
Post-Closing, Comparative, Monthly, Quarterly, Yearly, Branch, Department, and Project Trial Balances.
There is exactly one SQL computation path (`fn_compute_trial_balance`, defined in Database Design) that
every report and every API endpoint calls with different parameters — never a parallel, divergent
calculation. This guarantees that a Branch Trial Balance for Branch A, summed with Branch B, always
reconciles to the Company Trial Balance for the same period, barring documented inter-branch
eliminations.

**3. AI as a permanent second pair of eyes, never a silent actor.** Every Trial Balance run is reviewed
by the AI Auditor and Fraud Detection agents before a human even opens it. Findings — imbalances,
anomalies, forecasted errors, unusual variances — are pre-computed and attached to the run as structured
records (`trial_balance_ai_findings`) with confidence and reasoning, so the human reviewer starts from
"here are 3 things to check" instead of a blank spreadsheet. The AI never posts a correcting entry, never
approves a run, and never bypasses the same permission checks a human user would face.

**4. Immutable history, mutable understanding.** Every generated, approved, or archived Trial Balance is
kept forever (soft-deletable only, never hard-deleted) and versioned. As the business restates prior
periods (rare, but real — e.g., a discovered posting error corrected via a reversing entry), the Trial
Balance module never edits history; it produces a new, higher version with full lineage back to the
version it supersedes, so an auditor can always answer "what did the books say on the day we filed the
tax return," independent of what they say today.

The strategic outcome: Trial Balance stops being a monthly fire-drill spreadsheet exercise and becomes a
continuously-live, continuously-verified, continuously-explained state of the ledger that a Finance
Manager can trust without re-deriving it by hand, and that an external auditor can start from without a
week of reconciliation.

# Accounting Theory

This section defines the accounting theory terms this module implements, in QAYD's exact vocabulary, so
that engineering and finance share one unambiguous model.

## Trial Balance

A **Trial Balance** is a list of every account in the Chart of Accounts (`accounts`) together with its
balance, arranged so that debit-normal balances appear in a Debit column and credit-normal balances
appear in a Credit column, as of a specific date or for a specific fiscal period. It is a *control*
report: its sole analytical purpose is to prove that the sum of all debit balances equals the sum of all
credit balances, which is the arithmetic consequence of double-entry bookkeeping (every `journal_lines`
row's debit or credit is matched by an equal and opposite entry elsewhere in the same `journal_entries`
record, or across entries within the same fiscal period that closes to zero). A Trial Balance is **not**
a financial statement — it has no presentation rules for investors or regulators — but it is the
mandatory internal checkpoint that must pass before any financial statement (Balance Sheet, Income
Statement, Statement of Cash Flows, Statement of Changes in Equity) can be generated, because those
statements are themselves derived from the same underlying account balances.

## Debit

A **Debit** (`debit_amount` on `journal_lines`) is the left-side entry of a double-entry bookkeeping
transaction. In QAYD, every `journal_lines` row carries both a `debit_amount` and a `credit_amount`
column (one is always zero, enforced by a CHECK constraint — see Database Design), never a signed single
amount, to keep the ledger unambiguous and to make SUM-based Trial Balance computation trivial and safe.
A debit increases Asset and Expense accounts and decreases Liability, Equity, and Revenue accounts,
per each account's `normal_balance` on `account_types`.

## Credit

A **Credit** (`credit_amount` on `journal_lines`) is the right-side entry of a double-entry transaction.
A credit increases Liability, Equity, and Revenue accounts and decreases Asset and Expense accounts. Like
debit, it is stored as an explicit non-negative `NUMERIC(19,4)` column, never inferred from sign.

## Balance

An account's **Balance** is `SUM(debit_amount) - SUM(credit_amount)` over all posted `journal_lines` for
that account within the relevant date range, expressed in the account's — and ultimately the company's —
base currency. The *sign convention* the Trial Balance report displays depends on the account's
`normal_balance`: for a debit-normal account, a positive result is shown in the Debit column; for a
credit-normal account, a positive result (after multiplying by -1, since debit-normal computation yields
a negative number for a credit-normal account with a normal balance) is shown in the Credit column. The
Trial Balance never nets a debit-normal balance against a credit-normal balance across different
accounts — each account's balance is shown in exactly one column, never both, and never negative within
its column (a negative computed balance instead flips the account into the *opposite* column with a note,
which the Validation Rules and Edge Cases sections both address, since an unexpected negative balance
sign is itself a strong anomaly signal).

## Normal Balance

**Normal Balance** is a fixed, system-level attribute on `account_types` (Asset, Liability, Equity,
Revenue, Expense) per the platform's Double-Entry Accounting Rules:

| Account Type | Normal Balance | Increases with | Decreases with |
|---|---|---|---|
| Asset | Debit | Debit | Credit |
| Expense | Debit | Debit | Credit |
| Liability | Credit | Credit | Debit |
| Equity | Credit | Credit | Debit |
| Revenue | Credit | Credit | Debit |

Every `accounts` row inherits its `normal_balance` from its `account_types.normal_balance` (denormalized
onto `accounts.normal_balance` for query performance — see Database Design). The Trial Balance
computation uses `normal_balance` to decide which column (Debit or Credit) an account's net balance is
placed in, and to detect an "abnormal balance" condition (e.g., a Liability account showing a debit
balance), which the AI Auditor flags as an anomaly worth investigating even when it does not, by itself,
break the debit=credit invariant.

## Opening Balance

The **Opening Balance** of an account for a fiscal period is its Closing Balance from the immediately
preceding fiscal period (or, for the company's very first fiscal period, the balance manually entered
during onboarding via an opening-balance journal entry of `entry_type = 'opening'`). Opening Balances are
never stored as a separate mutable field on `accounts`; they are always the *computed* result of summing
all posted `journal_lines` up to (but not including) the start of the period in question. This keeps
Opening Balance permanently reproducible and consistent with Closing Balance of the prior period by
construction — there is no way for the two to drift apart, because both are computed from the same
`journal_lines` table using the same function with different date bounds.

## Closing Balance

The **Closing Balance** of an account for a fiscal period is Opening Balance + period Debit movements -
period Credit movements (for a debit-normal account; the inverse for credit-normal), i.e. the account's
balance as of the last moment of the period, inclusive of every posted `journal_lines` row dated within
that period. Closing Balance becomes the next period's Opening Balance. For temporary accounts (Revenue,
Expense), the Closing Balance is additionally zeroed out by system-generated **closing entries** at
fiscal year-end, which transfer the net result into Retained Earnings (an Equity account) — this is what
produces the Post-Closing Trial Balance.

## Adjusted Trial Balance

The **Adjusted Trial Balance (ATB)** is the Trial Balance computed *after* posting adjusting journal
entries (`journal_entries.entry_type = 'adjusting'`) for the period — accruals, deferrals, depreciation,
amortization, bad-debt provisions, inventory valuation adjustments, and similar period-end corrections
that are not triggered by an external transaction but are required for the accounts to fairly represent
the period under the applicable accounting framework (IFRS or local GAAP). The ATB is the version that
feeds the Income Statement and Balance Sheet. In QAYD, an ATB run always carries a `parent_snapshot_id`
pointing at the UTB run it was adjusted from, and a `trial_balance_snapshot_adjustments` join records
exactly which adjusting `journal_entries` were posted between the two runs, so the delta between UTB and
ATB is always fully explained, never a silent recomputation.

## Unadjusted Trial Balance

The **Unadjusted Trial Balance (UTB)** is the Trial Balance computed from all *routine* posted journal
entries for the period (sales, purchases, receipts, payments, payroll, inventory movements, bank
transactions, transfers, manual entries of type `standard`) *before* any period-end adjusting entries are
posted. The UTB is the accountant's starting point at period close: it is generated first, reviewed for
completeness (all expected sub-ledger activity posted, no missing accounts, no orphaned entries), and
only then is the list of required adjusting entries determined and posted to produce the ATB.

## Post Closing Trial Balance

The **Post-Closing Trial Balance (PCTB)** is the Trial Balance computed *after* the fiscal year's closing
entries have zeroed all temporary (Revenue and Expense) accounts into Retained Earnings. It therefore
contains only permanent accounts — Assets, Liabilities, and Equity — and by definition its Revenue and
Expense columns are empty. The PCTB becomes the mathematically-guaranteed Opening Balance set for the
following fiscal year, and is the version an external auditor typically requests first, because it
represents the final, closed position of the audited year with no further adjustments pending. A PCTB
can only be generated once `fiscal_years.status = 'closed'` and its closing journal entries
(`entry_type = 'closing'`) have been posted; QAYD represents this as a required, ordered dependency in
the Trial Balance Workflow.

# Business Goals

1. **Guarantee mathematical correctness at all times.** Every Trial Balance run, in every state
   (unadjusted, adjusted, post-closing), must satisfy debit = credit to the cent, or be explicitly and
   visibly flagged as out-of-balance with root-cause analysis attached — never silently rounded or
   hidden.
2. **Reduce period-close time from days to minutes.** Automate generation, validation, and preliminary
   AI review so a Finance Manager's period-close workflow becomes: click Generate, read the AI summary,
   resolve flagged items, click Approve.
3. **Provide a single computation path reused by every report.** Monthly, Quarterly, Yearly,
   Comparative, Branch, Department, and Project Trial Balances must all call the same underlying SQL
   function with different filters, eliminating divergent "shadow" calculations across the codebase.
4. **Make every number explainable.** Any accountant, auditor, or AI agent must be able to drill from a
   Trial Balance line down to the exact `journal_lines` rows, `journal_entries`, and source documents
   that produced it, in one click / one API call.
5. **Support statutory and internal audit without extra preparation.** The Post-Closing Trial Balance,
   its approval chain, and its full audit trail must be exportable in one action, in the formats
   (PDF/Excel/CSV) auditors expect, with no manual reformatting.
6. **Enforce period and account governance.** No Trial Balance may include activity from a period that
   is closed for new posting in a way that contradicts the closed state, no inactive or duplicate
   accounts may silently distort totals, and every currency conversion into base currency must be
   traceable to the exchange rate used.
7. **Let AI catch what humans miss, without AI ever acting alone.** Imbalances, anomalies, and
   forecastable errors must be surfaced automatically, with confidence and reasoning, while every
   corrective action always requires human (or explicit policy) approval.
8. **Preserve a permanent, versioned history.** Every generated Trial Balance, once saved, is retained
   forever; regenerating a period after new data changes creates a new version, never an edit of history.
9. **Scale to enterprise data volumes.** A Trial Balance for a company with millions of `journal_lines`
   rows across many fiscal years must generate in seconds via materialized aggregates and indexing, not
   minutes.
10. **Multi-dimensional slicing without re-architecture.** Branch, Department, Project, and Cost Center
    views must be first-class filters on the same computation, not bolted-on separate modules.

# Definitions

| Term | Definition |
|---|---|
| Trial Balance Run / Snapshot | A single execution of the Trial Balance computation, frozen into a `trial_balance_snapshots` row with a specific type, as-of date/period, and status. |
| As-Of Date | The cutoff date (inclusive) up to which posted `journal_lines` are included in the computation. |
| Fiscal Period | A `fiscal_periods` row (e.g., a calendar month) nested inside a `fiscal_years` row; the standard periodicity of a formal Trial Balance run. |
| Unadjusted (UTB) | Trial Balance before adjusting entries for the period. |
| Adjusted (ATB) | Trial Balance after adjusting entries for the period. |
| Post-Closing (PCTB) | Trial Balance after fiscal-year closing entries zero temporary accounts. |
| Snapshot Line | One row of `trial_balance_snapshot_lines`: one account's opening/period/closing debit and credit for a given snapshot. |
| Imbalance | A condition where `SUM(debit) != SUM(credit)` across a snapshot's lines, beyond an allowed rounding tolerance. |
| Variance | The numeric difference between two comparable Trial Balance figures (e.g., this month vs. last month, actual vs. AI-forecast) for the same account. |
| Suspense Account | A designated account (flagged `is_suspense = true` on `accounts`) used to temporarily hold amounts pending correct classification; its presence with a non-zero balance in an approved Trial Balance is itself a validation warning. |
| Control Account | A GL account (e.g., Accounts Receivable, Accounts Payable) that must reconcile to the sum of its sub-ledger (`customers`/`vendors`) balances. |
| Elimination | A manual adjustment applied when consolidating multiple branches/companies to remove inter-branch or inter-company balances before they double-count in a consolidated Trial Balance. |
| Rounding Tolerance | The maximum absolute variance (default `0.0050` in base currency, i.e., half of the smallest unit at 4-decimal storage rounded to 2-decimal presentation) permitted before a Trial Balance is flagged out-of-balance. |
| Snapshot Version | A monotonically increasing integer per (company, branch, fiscal_period, type) identifying successive regenerations of the same logical Trial Balance. |
| Current Snapshot | The snapshot with `is_current = true` for a given (company, branch, fiscal_period, type) — the one considered authoritative "now." |
| AI Finding | A structured record (`trial_balance_ai_findings`) produced by an AI agent describing a detected imbalance, anomaly, pattern, or forecast tied to a specific snapshot and, optionally, a specific account. |
| Approval Chain | The ordered sequence of required human approvals (`trial_balance_approvals`) a snapshot must pass through before it can be exported for external/statutory use or archived as final. |
| Archive | The terminal lifecycle state of a snapshot: locked from further action except read and export, retained permanently for audit. |

# Trial Balance Workflow

The Trial Balance lifecycle is a strict finite-state machine. A snapshot moves forward through six
stages — Generate, Validate, Review, Approve, Export, Archive — and can only move backward via an
explicit new-version regeneration, never via an in-place edit.

```
                 ┌──────────┐
   (trigger) --> │ GENERATE │  (compute from journal_lines/ledger_entries; status = 'generating' -> 'generated')
                 └────┬─────┘
                      │  system validation runs automatically
                      v
                 ┌──────────┐
                 │ VALIDATE │  (status = 'generated' -> 'validated' | 'out_of_balance')
                 └────┬─────┘
                      │  AI Auditor + Fraud Detection attach findings
                      v
                 ┌──────────┐
                 │  REVIEW  │  (status = 'validated' -> 'under_review'; human opens, reads AI findings)
                 └────┬─────┘
                      │  reviewer resolves/dismisses findings, requests changes, or proceeds
                      v
                 ┌──────────┐
                 │ APPROVE  │  (status = 'under_review' -> 'approved'; approval chain per role matrix)
                 └────┬─────┘
                      │
              ┌───────┴────────┐
              v                v
        ┌──────────┐     ┌──────────┐
        │  EXPORT   │     │ ARCHIVE  │  (status = 'approved' -> 'archived'; can export before or after archiving)
        │ (PDF/XLS/ │     │ (locked, │
        │  CSV)     │     │ permanent)│
        └──────────┘     └──────────┘
```

If new `journal_lines` post into an already-approved or archived period (only possible via a reversing
or adjusting entry in a still-open period, since closed periods reject new posting — see Business
Rules), the existing snapshot is **never** mutated. A new snapshot is generated with an incremented
`version`, `parent_snapshot_id` pointing at the prior version, and the prior version's `is_current` flag
flips to `false` while it remains permanently queryable through History.

## Generate

Generation is triggered by: (a) a user action (`POST /trial-balance/generate`), (b) a scheduled job
(e.g., nightly generation of the current month's live UTB for dashboarding), or (c) an automatic trigger
at fiscal-period close. Generation performs:

1. Resolve scope: `company_id` (required), `branch_id`, `department_id`, `project_id`, `cost_center_id`
   (all optional filters), `fiscal_period_id` or explicit `as_of_date`, and `type`
   (`unadjusted` | `adjusted` | `post_closing`).
2. Validate preconditions per type (detailed in Business Rules): e.g. `post_closing` requires the
   fiscal year's closing entries to already be posted.
3. Insert a `trial_balance_snapshots` header row with `status = 'generating'`.
4. Execute `fn_compute_trial_balance(...)` (see Database Design) which aggregates `journal_lines`
   filtered by scope and date range into one row per account.
5. Bulk-insert the results into `trial_balance_snapshot_lines`.
6. Compute header totals (`total_debit`, `total_credit`, `variance`) and a content hash
   (`sha256` over the ordered line set) for tamper-evidence.
7. Flip header `status` to `generated` and enqueue the Validate step asynchronously (Laravel queue job
   `ValidateTrialBalanceSnapshot`) so the API call can return immediately with the header while lines are
   still being scored by AI.

Large companies (millions of `journal_lines` rows) generate asynchronously by default: the API returns
`status: "generating"` immediately with a `snapshot_id`, and the client polls or subscribes via Laravel
Reverb to a `trial_balance.generated` event.

## Validate

System (non-AI) validation runs synchronously as part of Generate and re-runs any time a snapshot is
reopened. It executes every rule in the Validation Rules section: debit=credit check, missing-account
check, inactive/duplicate-account check, closed-period consistency check, and currency validation. Each
rule failure inserts a `trial_balance_ai_findings` row with `finding_type` matching the rule (some rule
failures are system-flagged, not AI-flagged — `source = 'system'` vs `source = 'ai'` distinguishes them,
see Database Design) and downgrades `status` to `out_of_balance` (for the debit=credit rule) or leaves
`status = 'generated'` with `has_warnings = true` for lesser rule violations (missing account, inactive
account, etc., which do not break the invariant but do indicate data-quality issues).

## Review

A human with `accounting.trial_balance.read` opens the snapshot. The Review UI surfaces, in priority
order: (1) any `out_of_balance` condition — blocking; (2) AI Auditor and Fraud Detection findings, sorted
by severity and confidence; (3) variance against the prior period/version for material accounts (default
materiality threshold: an account whose absolute period movement exceeds 10% of its opening balance or a
configurable absolute amount, whichever is larger). The reviewer can drill into any line to see the
contributing `journal_lines`, resolve or dismiss each AI finding (recorded with a reason), and either
send the snapshot back for regeneration (if source data needs correcting) or advance it to Approve.
Status transitions to `under_review` on open and stays there until an Approve or explicit "send back"
action.

## Approve

Approval follows the Approval Chain defined per company policy (default: Accountant creates/reviews,
Finance Manager or CFO approves; for `post_closing` runs, Auditor sign-off is also required before
`status` can reach `approved`). Each approval step inserts a `trial_balance_approvals` row
(`approver_id`, `role_at_time`, `action` = `approved`/`rejected`/`requested_changes`, `comment`,
`signed_at`). The header `status` only becomes `approved` once every required step in the configured
chain has an `approved` action; a single `rejected` at any step reverts `status` to `under_review` and
requires the discrepancy to be resolved (either by data correction and regeneration, or by a documented
override with elevated permission — see Business Rules on overrides).

## Export

Once `generated` (drafts can be exported watermarked "DRAFT — NOT APPROVED") or `approved` (final,
unwatermarked), the snapshot can be exported to PDF, Excel (`.xlsx`, with one worksheet per dimension
breakdown if requested), or CSV. Every export call inserts a `trial_balance_exports` row recording who
exported what format when, and stores the generated file via the foundation `attachments` table
(polymorphic `attachable_type = 'trial_balance_snapshots'`) in Cloudflare R2, with a signed, time-limited
download URL returned to the caller.

## Archive

Archiving is a deliberate, permission-gated final step (`accounting.trial_balance.archive`) available
only on `approved` snapshots. Archiving sets `status = 'archived'`, `archived_at`, `archived_by`, and
`is_locked = true`. An archived snapshot can never be edited, re-approved, or deleted (only soft-deleted
by an Owner/Admin for legal-hold/GDPR-type scenarios, and even then the row is retained with
`deleted_at` set — the physical row is never dropped from the table). Archiving does not prevent a
*new version* of the same logical period from being generated later (e.g., a restatement); it only locks
that specific version.

# Business Rules

1. **Structural balance guarantee.** `journal_entries` cannot be posted (`status` transition to
   `posted`) unless `SUM(journal_lines.debit_amount) = SUM(journal_lines.credit_amount)` for that entry,
   enforced by a Postgres trigger (`trg_journal_entry_balance_check`, defined in Database Design) as a
   defense-in-depth backstop to the Laravel Service-layer check. This is why a Trial Balance computed
   purely from posted lines is balanced by construction in the normal case.
2. **Only posted lines count.** Trial Balance computation only includes `journal_lines` whose parent
   `journal_entries.status = 'posted'`. Draft, pending-approval, reversed, and voided entries are
   excluded entirely; a *reversing* entry is itself a posted entry with inverted debit/credit and is
   included normally.
3. **Post-Closing requires closed fiscal year.** A `type = 'post_closing'` snapshot can only be generated
   when `fiscal_years.status = 'closed'` for the target year and its system-generated closing
   `journal_entries` (`entry_type = 'closing'`) exist and are posted. Attempting to generate a PCTB for
   an open fiscal year returns `422` with a specific `errors[]` entry.
4. **Adjusted requires a parent Unadjusted.** A `type = 'adjusted'` snapshot must reference a
   `parent_snapshot_id` pointing to an existing `unadjusted` snapshot for the same scope and period. If
   none exists, the API auto-generates the UTB first (recorded as a system-generated snapshot) before
   producing the ATB, and both are visible in History.
5. **Closed periods are read-only for new postings, not for Trial Balance generation.** A Trial Balance
   *can* be regenerated for a closed period (e.g., to produce a new version after a rare restatement via
   a dated reversing entry that is itself posted into the still-open subsequent period but effectively
   dated back), but no *new* `journal_lines` may be dated inside a period whose `fiscal_periods.status =
   'closed'` or `'locked'`. This distinction — generation is always allowed for history, posting is
   gated by period status — is the load-bearing rule this module enforces around period closing.
6. **Multi-currency lines convert to base currency using the rate stored on the journal line at posting
   time**, never a rate looked up at Trial-Balance-generation time. This guarantees a Trial Balance is
   reproducible byte-for-byte from the same `journal_lines` regardless of when it is regenerated, even if
   the daily FX rate table has since been updated. `trial_balance_snapshot_lines` stores both the
   transaction-currency figures (aggregated per `currency_code` in a JSONB breakdown column) and the
   single base-currency figure used for the balancing check.
7. **Branch/Department/Project Trial Balances are strict subsets, not independent ledgers.** They are
   produced by adding a `WHERE` filter to the exact same computation function; they are not allowed to
   apply different rounding, different account sets, or different normal-balance logic than the Company
   Trial Balance. A Branch Trial Balance is therefore not guaranteed to balance on its own (a branch can
   legitimately have more debits than credits if its offsetting entries post to Head Office or another
   branch); this is documented, expected, and never treated as an "imbalance" — only the ungrouped,
   whole-company Trial Balance is required to balance to zero variance.
8. **Suspense accounts are permitted but always flagged.** Any approved Trial Balance containing a
   non-zero balance in an account where `accounts.is_suspense = true` produces a mandatory, non-dismissable
   AI finding of `finding_type = 'suspense_balance'` with `severity = 'high'`; the Approval Chain step
   for such a snapshot requires an explicit comment from the approver acknowledging the suspense balance.
9. **Every regeneration is a new version, never an overwrite.** `trial_balance_snapshots` rows are
   immutable once `status` leaves `generating`. Regenerating the same logical (company, branch, fiscal
   period, type) combination always inserts a new row with `version = previous_max_version + 1`, links
   `parent_snapshot_id` where semantically applicable (ATB→UTB, PCTB→ATB), and only one row per logical
   combination may have `is_current = true` at a time.
10. **Overrides require elevated, logged, reasoned action.** If an out-of-balance condition cannot be
    resolved by correcting source data (e.g., legacy data migrated from a prior system with a known,
    accepted variance), an Owner or Admin may record a documented **balancing adjustment** — which is
    itself a `journal_entries` row of `entry_type = 'adjusting'` posted to a designated "Historical
    Variance" equity or suspense account, requiring a mandatory `reason` field — never a silent override
    flag on the Trial Balance itself. The Trial Balance module has no "force balance" button; it only
    ever reflects the ledger truthfully.
11. **AI never posts.** AI agents may create `trial_balance_ai_findings` and, for suggested corrections,
    a *draft* `journal_entries` row with `status = 'draft'` and `created_by` set to the AI agent's system
    user, tagged `ai_generated = true` and carrying `ai_confidence`, `ai_reasoning`. That draft still
    requires a human with `accounting.journal.create` and `accounting.journal.post` to review and post
    it — AI cannot advance its own draft's status.
12. **Comparative reports never mix currencies without conversion.** A Comparative Trial Balance across
    periods where the company's base currency changed (rare, but supported for corporate restructuring)
    must show both periods converted into the *current* base currency using the historical rate stored
    per period, with a footnote disclosing the conversion.

# Validation Rules

Validation runs in two layers: **hard rules** (block the snapshot from being marked `validated`; the
snapshot remains usable for diagnosis but cannot proceed to Review/Approve/Archive) and **soft rules**
(attach a warning finding but permit the workflow to continue). Every rule produces a structured
`trial_balance_ai_findings` row even when `source = 'system'` (i.e., not literally AI-authored) so the
Review UI has one unified feed.

| # | Rule | Layer | Trigger Condition | Resulting Status/Finding |
|---|---|---|---|---|
| 1 | Debit must equal Credit | Hard | `ABS(total_debit - total_credit) > rounding_tolerance` | `status = 'out_of_balance'`; finding `severity = 'critical'` |
| 2 | Detect Imbalance (per-dimension) | Hard | Sum of a required dimension breakdown (e.g., per-branch subtotal declared as required to reconcile) doesn't reconcile to header total | `status = 'out_of_balance'`; finding with dimension detail |
| 3 | Missing Accounts | Soft | An account with posted `journal_lines` in the period is absent from `trial_balance_snapshot_lines` (computation/index bug, or account hard-excluded by a stale filter) | `has_warnings = true`; finding `finding_type = 'missing_account'` |
| 4 | Inactive Accounts | Soft | A line references an `accounts.status = 'inactive'` account with a non-zero closing balance | finding `finding_type = 'inactive_account_balance'`, `severity = 'medium'` |
| 5 | Duplicate Accounts | Hard | More than one `trial_balance_snapshot_lines` row for the same `account_id` within one snapshot (aggregation bug or duplicate dimension key collision) | `status = 'out_of_balance'`; blocks Review |
| 6 | Closed Periods | Hard | The snapshot's `fiscal_period_id` has `status IN ('closed','locked')` but at least one contributing `journal_lines.created_at` postdates the period's `closed_at` timestamp (i.e., a line was posted after closing — should be structurally impossible, but checked) | `status = 'out_of_balance'`; finding flags the offending `journal_entries.id` |
| 7 | Currency Validation | Hard | A `journal_lines` row's `currency_code` is not a currency enabled for the company, or its `exchange_rate <= 0`, or its base-currency amount does not equal `transaction_amount * exchange_rate` within tolerance | `status = 'out_of_balance'`; finding names the specific `journal_lines.id` |
| 8 | Rounding Tolerance Exceeded | Soft | `0 < ABS(total_debit - total_credit) <= rounding_tolerance` (i.e., balanced within tolerance but not exactly zero) | `has_warnings = true`; finding records exact variance for transparency even though it does not block |
| 9 | Suspense Account Non-Zero | Soft (escalates at Approve) | Any `is_suspense = true` account has non-zero closing balance | finding `finding_type = 'suspense_balance'`, `severity = 'high'` |
| 10 | Control Account Reconciliation | Soft | AR or AP control account balance does not equal the sum of open `customers`/`vendors` sub-ledger balances | finding `finding_type = 'control_account_mismatch'` with computed sub-ledger sum |
| 11 | Stale Exchange Rate | Soft | A multi-currency line's `exchange_rate` deviates more than a configurable % from the company's rate table for that date (possible manual entry error) | finding `finding_type = 'stale_fx_rate'` |
| 12 | Negative Balance on Normal Side | Soft | A debit-normal account shows a computed negative debit balance (i.e., it is naturally credit-side this period) or vice versa | finding `finding_type = 'abnormal_balance'` |

Each rule's exact SQL predicate is implemented inside `fn_validate_trial_balance_snapshot(snapshot_id)`
(see Database Design), which is invoked synchronously at the end of Generate and can also be invoked
on-demand ("Re-validate") from the Review screen without regenerating the underlying computation.

## Debit must equal Credit

This is the module's foundational check: `total_debit` and `total_credit` on `trial_balance_snapshots`
are computed columns (materialized at generation time, not virtual) from
`SUM(trial_balance_snapshot_lines.closing_debit)` and `SUM(trial_balance_snapshot_lines.closing_credit)`
respectively. The check compares their absolute difference against `rounding_tolerance` (company-level
setting, default `0.0050`, configurable down to `0.0000` for companies that require exact-cent
balancing with no tolerance at all, e.g., regulated financial institutions).

## Detect Imbalance

Beyond the header-level check, "Detect Imbalance" also verifies that every declared dimensional
breakdown reconciles: if a snapshot was generated `WITH BREAKDOWN BY branch_id`, the sum of each
branch's subtotal debit/credit must equal the header's total debit/credit (a company-wide snapshot with
a branch breakdown must fully partition the company's accounts across the branches it names, including
a `branch_id IS NULL` bucket for unassigned entries — no `journal_lines` row is silently dropped from
every bucket).

## Missing Accounts

Detected by a `LEFT JOIN` from "every account with posted activity in the period" to
"`trial_balance_snapshot_lines` for this snapshot," selecting rows that failed to match. This guards
against a computation regression or an overly narrow account-type filter silently excluding real
balances.

## Inactive Accounts

`accounts.status = 'inactive'` marks an account retired from new transaction entry, but its historical
balance must still surface in any Trial Balance covering a period during which it was active or holds a
carried balance. This rule does not block the account from appearing — it flags it so a reviewer
consciously acknowledges "yes, this retired account still has value moving through it" rather than being
silently surprised at period close when someone asks why a supposedly-closed account has a balance.

## Duplicate Accounts

Guarded at the database layer by a `UNIQUE (snapshot_id, account_id, cost_center_id, project_id,
department_id, branch_id)` constraint on `trial_balance_snapshot_lines` (see Database Design) — a
duplicate is a hard constraint violation that the computation function itself cannot produce under
normal operation, so observing this finding in production indicates either a race condition during
concurrent line insertion (guarded further by an advisory lock during Generate) or manual data
tampering, both of which the AI Fraud Detection agent treats as `severity = 'critical'`.

## Closed Periods

The Trial Balance computation window for a formal period-based snapshot (`fiscal_period_id` set) is
always `[fiscal_periods.start_date, fiscal_periods.end_date]` inclusive. This rule cross-checks that no
contributing `journal_lines` carries a `posted_at` timestamp *after* `fiscal_periods.closed_at` — because
the only way that could happen is a period being closed while a transaction was mid-flight, or the
close-period guard being bypassed. It is a backstop, not the primary control (the primary control is
that `POST /journal-entries/{id}/post` itself checks `fiscal_periods.status` before allowing posting).

## Currency Validation

Every `journal_lines` row used in the computation must have: `currency_code` present in
`companies.enabled_currencies` (JSONB array on the company settings), `exchange_rate > 0`, and
`base_currency_amount` equal to `ROUND(transaction_amount * exchange_rate, 4)` within a
`0.0001`-unit tolerance (to absorb legitimate rate-source rounding). A failure here is treated as a
data-integrity defect in the source transaction, not merely a Trial Balance display issue, and the
finding links directly to the offending `journal_lines.id` and its parent `journal_entries.id` for
one-click correction via a reversing entry.

# Database Design

The Trial Balance module owns six tables, all under the `accounting` Postgres schema, plus one
computation function, one validation function, and one materialized view used for performance. It reads
from — but never writes to — `journal_entries`, `journal_lines`, `ledger_entries`, `accounts`,
`account_types`, `fiscal_years`, `fiscal_periods`, `companies`, `branches`, `departments`,
`cost_centers`, `projects`, `customers`, `vendors`, and `attachments`.

## Enums

```sql
CREATE TYPE trial_balance_type AS ENUM ('unadjusted', 'adjusted', 'post_closing');

CREATE TYPE trial_balance_status AS ENUM (
    'generating', 'generated', 'validated', 'out_of_balance',
    'under_review', 'approved', 'archived'
);

CREATE TYPE trial_balance_approval_action AS ENUM ('approved', 'rejected', 'requested_changes');

CREATE TYPE trial_balance_finding_type AS ENUM (
    'imbalance', 'missing_account', 'inactive_account_balance', 'duplicate_account',
    'closed_period_violation', 'currency_error', 'rounding_tolerance', 'suspense_balance',
    'control_account_mismatch', 'stale_fx_rate', 'abnormal_balance', 'anomaly',
    'forecasted_error', 'variance_pattern'
);

CREATE TYPE trial_balance_finding_severity AS ENUM ('low', 'medium', 'high', 'critical');

CREATE TYPE trial_balance_finding_source AS ENUM ('system', 'ai');

CREATE TYPE trial_balance_finding_status AS ENUM ('open', 'acknowledged', 'resolved', 'dismissed');

CREATE TYPE trial_balance_export_format AS ENUM ('pdf', 'xlsx', 'csv');
```

## Tables

### `trial_balance_snapshots`

The header/run record. One row per generated Trial Balance (any type, any scope, any version).

```sql
CREATE TABLE accounting.trial_balance_snapshots (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    department_id       BIGINT NULL REFERENCES departments(id),
    project_id          BIGINT NULL REFERENCES projects(id),
    cost_center_id      BIGINT NULL REFERENCES accounting.cost_centers(id),

    fiscal_year_id      BIGINT NULL REFERENCES accounting.fiscal_years(id),
    fiscal_period_id    BIGINT NULL REFERENCES accounting.fiscal_periods(id),
    as_of_date          DATE NOT NULL,
    period_start_date   DATE NOT NULL,

    type                trial_balance_type NOT NULL,
    status              trial_balance_status NOT NULL DEFAULT 'generating',

    parent_snapshot_id  BIGINT NULL REFERENCES accounting.trial_balance_snapshots(id),
    version             INTEGER NOT NULL DEFAULT 1,
    is_current          BOOLEAN NOT NULL DEFAULT true,

    currency_code       VARCHAR(3) NOT NULL,          -- base currency this snapshot is expressed in
    total_debit         NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_credit        NUMERIC(19,4) NOT NULL DEFAULT 0,
    variance            NUMERIC(19,4) NOT NULL DEFAULT 0,   -- total_debit - total_credit
    rounding_tolerance  NUMERIC(19,4) NOT NULL DEFAULT 0.0050,
    has_warnings        BOOLEAN NOT NULL DEFAULT false,

    account_count       INTEGER NOT NULL DEFAULT 0,
    line_count          INTEGER NOT NULL DEFAULT 0,
    content_hash        VARCHAR(64) NULL,             -- sha256 over ordered line set, tamper-evidence

    generation_mode     VARCHAR(20) NOT NULL DEFAULT 'manual',  -- manual | scheduled | auto_period_close
    generated_by        BIGINT NULL REFERENCES users(id),
    generated_at        TIMESTAMPTZ NULL,

    validated_at        TIMESTAMPTZ NULL,
    reviewed_by         BIGINT NULL REFERENCES users(id),
    reviewed_at         TIMESTAMPTZ NULL,

    approved_at         TIMESTAMPTZ NULL,             -- set only when EVERY required approval step passes
    approved_by         BIGINT NULL REFERENCES users(id),  -- final approver of record

    archived_by         BIGINT NULL REFERENCES users(id),
    archived_at         TIMESTAMPTZ NULL,
    is_locked           BOOLEAN NOT NULL DEFAULT false,

    notes               TEXT NULL,
    tags                JSONB NOT NULL DEFAULT '[]',
    custom_fields       JSONB NOT NULL DEFAULT '{}',

    created_by          BIGINT NULL REFERENCES users(id),
    updated_by          BIGINT NULL REFERENCES users(id),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at           TIMESTAMPTZ NULL,

    CONSTRAINT chk_tbs_variance_matches CHECK (variance = total_debit - total_credit),
    CONSTRAINT chk_tbs_period_or_date CHECK (
        (fiscal_period_id IS NOT NULL) OR (as_of_date IS NOT NULL)
    ),
    CONSTRAINT chk_tbs_post_closing_requires_year CHECK (
        type <> 'post_closing' OR fiscal_year_id IS NOT NULL
    )
);

-- Only one "current" version per logical (company, branch, department, project, cost_center,
-- fiscal_period, type) combination. NULLS in dimension columns are treated as a fixed sentinel via
-- COALESCE in a partial unique index because Postgres UNIQUE treats NULL <> NULL.
CREATE UNIQUE INDEX ux_tbs_current_logical_key ON accounting.trial_balance_snapshots (
    company_id,
    COALESCE(branch_id, 0),
    COALESCE(department_id, 0),
    COALESCE(project_id, 0),
    COALESCE(cost_center_id, 0),
    COALESCE(fiscal_period_id, 0),
    as_of_date,
    type
) WHERE is_current = true AND deleted_at IS NULL;

CREATE INDEX idx_tbs_company_period   ON accounting.trial_balance_snapshots (company_id, fiscal_period_id);
CREATE INDEX idx_tbs_company_status   ON accounting.trial_balance_snapshots (company_id, status);
CREATE INDEX idx_tbs_company_type_date ON accounting.trial_balance_snapshots (company_id, type, as_of_date DESC);
CREATE INDEX idx_tbs_branch           ON accounting.trial_balance_snapshots (branch_id) WHERE branch_id IS NOT NULL;
CREATE INDEX idx_tbs_parent           ON accounting.trial_balance_snapshots (parent_snapshot_id) WHERE parent_snapshot_id IS NOT NULL;
CREATE INDEX idx_tbs_is_current       ON accounting.trial_balance_snapshots (company_id, is_current) WHERE is_current = true;
```

### `trial_balance_snapshot_lines`

One row per account (per requested dimension combination) within a snapshot.

```sql
CREATE TABLE accounting.trial_balance_snapshot_lines (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    snapshot_id         BIGINT NOT NULL REFERENCES accounting.trial_balance_snapshots(id) ON DELETE CASCADE,
    company_id          BIGINT NOT NULL REFERENCES companies(id),

    account_id          BIGINT NOT NULL REFERENCES accounting.accounts(id),
    account_code        VARCHAR(30) NOT NULL,        -- denormalized snapshot-time copy, immutable even if renumbered later
    account_name_en     VARCHAR(255) NOT NULL,
    account_name_ar     VARCHAR(255) NULL,
    account_type_id     BIGINT NOT NULL REFERENCES accounting.account_types(id),
    normal_balance       VARCHAR(6) NOT NULL,         -- 'debit' | 'credit' — denormalized from account_types at snapshot time

    branch_id           BIGINT NULL REFERENCES branches(id),
    department_id       BIGINT NULL REFERENCES departments(id),
    project_id          BIGINT NULL REFERENCES projects(id),
    cost_center_id      BIGINT NULL REFERENCES accounting.cost_centers(id),

    opening_debit       NUMERIC(19,4) NOT NULL DEFAULT 0,
    opening_credit      NUMERIC(19,4) NOT NULL DEFAULT 0,
    period_debit        NUMERIC(19,4) NOT NULL DEFAULT 0,
    period_credit       NUMERIC(19,4) NOT NULL DEFAULT 0,
    closing_debit       NUMERIC(19,4) NOT NULL DEFAULT 0,
    closing_credit      NUMERIC(19,4) NOT NULL DEFAULT 0,

    is_abnormal_balance BOOLEAN NOT NULL DEFAULT false,   -- true if closing side != normal_balance side
    currency_breakdown  JSONB NOT NULL DEFAULT '[]',       -- [{currency_code, debit, credit, avg_rate}]
    source_line_count   INTEGER NOT NULL DEFAULT 0,        -- number of journal_lines rows aggregated into this row

    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_tbsl_debit_xor_credit_closing CHECK (
        (closing_debit = 0) OR (closing_credit = 0)
    ),
    CONSTRAINT chk_tbsl_nonnegative CHECK (
        opening_debit >= 0 AND opening_credit >= 0 AND
        period_debit  >= 0 AND period_credit  >= 0 AND
        closing_debit >= 0 AND closing_credit >= 0
    ),
    CONSTRAINT uq_tbsl_line_key UNIQUE (
        snapshot_id, account_id, branch_id, department_id, project_id, cost_center_id
    )
);

CREATE INDEX idx_tbsl_snapshot        ON accounting.trial_balance_snapshot_lines (snapshot_id);
CREATE INDEX idx_tbsl_account         ON accounting.trial_balance_snapshot_lines (account_id);
CREATE INDEX idx_tbsl_company_account ON accounting.trial_balance_snapshot_lines (company_id, account_id);
CREATE INDEX idx_tbsl_abnormal        ON accounting.trial_balance_snapshot_lines (snapshot_id) WHERE is_abnormal_balance = true;
```

### `trial_balance_approvals`

Ordered approval-chain records per snapshot.

```sql
CREATE TABLE accounting.trial_balance_approvals (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    snapshot_id     BIGINT NOT NULL REFERENCES accounting.trial_balance_snapshots(id) ON DELETE CASCADE,
    company_id      BIGINT NOT NULL REFERENCES companies(id),

    step_order      SMALLINT NOT NULL,               -- 1, 2, 3... per configured approval chain
    required_role   VARCHAR(50) NOT NULL,             -- 'finance_manager' | 'cfo' | 'auditor' ...
    approver_id     BIGINT NULL REFERENCES users(id), -- NULL until acted upon
    action          trial_balance_approval_action NULL,
    comment         TEXT NULL,
    signed_at       TIMESTAMPTZ NULL,

    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_tba_step UNIQUE (snapshot_id, step_order)
);

CREATE INDEX idx_tba_snapshot ON accounting.trial_balance_approvals (snapshot_id);
CREATE INDEX idx_tba_pending  ON accounting.trial_balance_approvals (company_id, required_role) WHERE action IS NULL;
```

### `trial_balance_snapshot_adjustments`

Links an Adjusted or Post-Closing snapshot to the specific adjusting/closing `journal_entries` posted
between it and its parent, so the UTB→ATB→PCTB delta is always fully traceable.

```sql
CREATE TABLE accounting.trial_balance_snapshot_adjustments (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    snapshot_id         BIGINT NOT NULL REFERENCES accounting.trial_balance_snapshots(id) ON DELETE CASCADE,
    journal_entry_id    BIGINT NOT NULL REFERENCES accounting.journal_entries(id),
    adjustment_category VARCHAR(50) NOT NULL,   -- 'accrual' | 'deferral' | 'depreciation' | 'amortization' |
                                                 -- 'bad_debt_provision' | 'inventory_valuation' | 'closing' | 'other'
    impact_debit        NUMERIC(19,4) NOT NULL DEFAULT 0,
    impact_credit       NUMERIC(19,4) NOT NULL DEFAULT 0,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT uq_tbsa_entry UNIQUE (snapshot_id, journal_entry_id)
);

CREATE INDEX idx_tbsa_snapshot ON accounting.trial_balance_snapshot_adjustments (snapshot_id);
CREATE INDEX idx_tbsa_entry    ON accounting.trial_balance_snapshot_adjustments (journal_entry_id);
```

### `trial_balance_ai_findings`

Structured system- and AI-generated findings attached to a snapshot (imbalances, anomalies, patterns,
forecasts, suggested corrections).

```sql
CREATE TABLE accounting.trial_balance_ai_findings (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    snapshot_id        BIGINT NOT NULL REFERENCES accounting.trial_balance_snapshots(id) ON DELETE CASCADE,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    account_id         BIGINT NULL REFERENCES accounting.accounts(id),
    journal_entry_id   BIGINT NULL REFERENCES accounting.journal_entries(id),
    journal_line_id    BIGINT NULL REFERENCES accounting.journal_lines(id),

    finding_type       trial_balance_finding_type NOT NULL,
    severity           trial_balance_finding_severity NOT NULL DEFAULT 'medium',
    source             trial_balance_finding_source NOT NULL DEFAULT 'system',
    ai_agent           VARCHAR(50) NULL,           -- 'auditor' | 'fraud_detection' | 'forecast_agent' | 'reporting_agent'

    title              VARCHAR(255) NOT NULL,
    description        TEXT NOT NULL,
    supporting_data     JSONB NOT NULL DEFAULT '{}',   -- amounts, account lists, computed deltas
    suggested_action    TEXT NULL,
    suggested_journal_entry_id BIGINT NULL REFERENCES accounting.journal_entries(id), -- draft entry, if AI proposed one

    confidence         NUMERIC(5,4) NULL CHECK (confidence >= 0 AND confidence <= 1),
    reasoning          TEXT NULL,

    status             trial_balance_finding_status NOT NULL DEFAULT 'open',
    resolved_by        BIGINT NULL REFERENCES users(id),
    resolved_at        TIMESTAMPTZ NULL,
    resolution_note    TEXT NULL,

    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_tbf_ai_fields CHECK (
        (source = 'system') OR (source = 'ai' AND ai_agent IS NOT NULL AND confidence IS NOT NULL)
    )
);

CREATE INDEX idx_tbf_snapshot        ON accounting.trial_balance_ai_findings (snapshot_id);
CREATE INDEX idx_tbf_open_by_company ON accounting.trial_balance_ai_findings (company_id, status) WHERE status = 'open';
CREATE INDEX idx_tbf_severity        ON accounting.trial_balance_ai_findings (snapshot_id, severity);
CREATE INDEX idx_tbf_account         ON accounting.trial_balance_ai_findings (account_id) WHERE account_id IS NOT NULL;
```

### `trial_balance_exports`

Every export action, for audit and re-download without regenerating the file.

```sql
CREATE TABLE accounting.trial_balance_exports (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    snapshot_id     BIGINT NOT NULL REFERENCES accounting.trial_balance_snapshots(id) ON DELETE CASCADE,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    format          trial_balance_export_format NOT NULL,
    attachment_id   BIGINT NOT NULL REFERENCES attachments(id),
    is_draft_watermarked BOOLEAN NOT NULL DEFAULT false,
    filters_applied  JSONB NOT NULL DEFAULT '{}',   -- e.g. {"breakdown":"branch","include_zero_balances":false}
    exported_by     BIGINT NOT NULL REFERENCES users(id),
    exported_at     TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_tbe_snapshot ON accounting.trial_balance_exports (snapshot_id);
CREATE INDEX idx_tbe_company_date ON accounting.trial_balance_exports (company_id, exported_at DESC);
```

## Computation SQL

`fn_compute_trial_balance` is the single, reusable aggregation function every report and API endpoint
calls. It reads only from `journal_lines` joined to `journal_entries` (for `status = 'posted'`) and
`accounts`/`account_types`, and returns one row per account (optionally further split by the requested
dimension columns).

```sql
CREATE OR REPLACE FUNCTION accounting.fn_compute_trial_balance(
    p_company_id      BIGINT,
    p_branch_id       BIGINT DEFAULT NULL,
    p_department_id   BIGINT DEFAULT NULL,
    p_project_id      BIGINT DEFAULT NULL,
    p_cost_center_id  BIGINT DEFAULT NULL,
    p_period_start    DATE DEFAULT NULL,     -- NULL => from inception
    p_as_of_date      DATE DEFAULT CURRENT_DATE,
    p_split_by_branch     BOOLEAN DEFAULT false,
    p_split_by_department BOOLEAN DEFAULT false,
    p_split_by_project    BOOLEAN DEFAULT false,
    p_split_by_cost_center BOOLEAN DEFAULT false
)
RETURNS TABLE (
    account_id          BIGINT,
    account_code        VARCHAR,
    account_name_en     VARCHAR,
    account_name_ar     VARCHAR,
    account_type_id      BIGINT,
    normal_balance       VARCHAR,
    branch_id            BIGINT,
    department_id        BIGINT,
    project_id           BIGINT,
    cost_center_id        BIGINT,
    opening_debit        NUMERIC,
    opening_credit       NUMERIC,
    period_debit         NUMERIC,
    period_credit        NUMERIC,
    closing_debit        NUMERIC,
    closing_credit       NUMERIC,
    source_line_count    BIGINT
) AS $$
BEGIN
    RETURN QUERY
    WITH scoped_lines AS (
        SELECT
            jl.account_id,
            CASE WHEN p_split_by_branch THEN jl.branch_id ELSE NULL END AS branch_id,
            CASE WHEN p_split_by_department THEN jl.department_id ELSE NULL END AS department_id,
            CASE WHEN p_split_by_project THEN jl.project_id ELSE NULL END AS project_id,
            CASE WHEN p_split_by_cost_center THEN jl.cost_center_id ELSE NULL END AS cost_center_id,
            jl.base_currency_debit,
            jl.base_currency_credit,
            je.posted_at
        FROM accounting.journal_lines jl
        JOIN accounting.journal_entries je ON je.id = jl.journal_entry_id
        WHERE je.company_id = p_company_id
          AND je.status = 'posted'
          AND je.deleted_at IS NULL
          AND (p_branch_id IS NULL OR jl.branch_id = p_branch_id)
          AND (p_department_id IS NULL OR jl.department_id = p_department_id)
          AND (p_project_id IS NULL OR jl.project_id = p_project_id)
          AND (p_cost_center_id IS NULL OR jl.cost_center_id = p_cost_center_id)
          AND je.posted_at::date <= p_as_of_date
    ),
    opening AS (
        SELECT sl.account_id, sl.branch_id, sl.department_id, sl.project_id, sl.cost_center_id,
               SUM(sl.base_currency_debit)  AS opening_debit,
               SUM(sl.base_currency_credit) AS opening_credit
        FROM scoped_lines sl
        WHERE p_period_start IS NOT NULL AND sl.posted_at::date < p_period_start
        GROUP BY 1,2,3,4,5
    ),
    period AS (
        SELECT sl.account_id, sl.branch_id, sl.department_id, sl.project_id, sl.cost_center_id,
               SUM(sl.base_currency_debit)  AS period_debit,
               SUM(sl.base_currency_credit) AS period_credit,
               COUNT(*)                      AS line_count
        FROM scoped_lines sl
        WHERE p_period_start IS NULL OR sl.posted_at::date >= p_period_start
        GROUP BY 1,2,3,4,5
    )
    SELECT
        a.id, a.code, a.name_en, a.name_ar, a.account_type_id, a.normal_balance,
        COALESCE(o.branch_id, p.branch_id),
        COALESCE(o.department_id, p.department_id),
        COALESCE(o.project_id, p.project_id),
        COALESCE(o.cost_center_id, p.cost_center_id),
        COALESCE(o.opening_debit, 0), COALESCE(o.opening_credit, 0),
        COALESCE(p.period_debit, 0),  COALESCE(p.period_credit, 0),
        -- closing = opening + period, then re-netted onto the account's normal side
        GREATEST(
          (COALESCE(o.opening_debit,0)+COALESCE(p.period_debit,0))
          - (COALESCE(o.opening_credit,0)+COALESCE(p.period_credit,0)), 0
        ) AS closing_debit,
        GREATEST(
          (COALESCE(o.opening_credit,0)+COALESCE(p.period_credit,0))
          - (COALESCE(o.opening_debit,0)+COALESCE(p.period_debit,0)), 0
        ) AS closing_credit,
        COALESCE(p.line_count, 0)
    FROM accounting.accounts a
    LEFT JOIN opening o ON o.account_id = a.id
    LEFT JOIN period  p ON p.account_id = a.id
        AND COALESCE(o.branch_id,-1) = COALESCE(p.branch_id,-1)
        AND COALESCE(o.department_id,-1) = COALESCE(p.department_id,-1)
        AND COALESCE(o.project_id,-1) = COALESCE(p.project_id,-1)
        AND COALESCE(o.cost_center_id,-1) = COALESCE(p.cost_center_id,-1)
    WHERE a.company_id = p_company_id
      AND (o.account_id IS NOT NULL OR p.account_id IS NOT NULL)
      AND a.deleted_at IS NULL;
END;
$$ LANGUAGE plpgsql STABLE;
```

The header-level balance check used by the Generate step is simply:

```sql
SELECT
    SUM(closing_debit)  AS total_debit,
    SUM(closing_credit) AS total_credit,
    SUM(closing_debit) - SUM(closing_credit) AS variance
FROM accounting.fn_compute_trial_balance(
    p_company_id => 42, p_as_of_date => '2026-07-31', p_period_start => '2026-07-01'
);
```

`fn_validate_trial_balance_snapshot(p_snapshot_id BIGINT)` runs the twelve Validation Rules against an
already-persisted snapshot's lines and inserts corresponding `trial_balance_ai_findings` rows with
`source = 'system'`; its body is a sequence of `INSERT ... SELECT` statements, one per rule, each
guarded by the rule's predicate (omitted here for brevity of a single illustrative rule):

```sql
CREATE OR REPLACE FUNCTION accounting.fn_validate_trial_balance_snapshot(p_snapshot_id BIGINT)
RETURNS VOID AS $$
DECLARE
    v_company_id BIGINT;
    v_total_debit NUMERIC(19,4);
    v_total_credit NUMERIC(19,4);
    v_tolerance NUMERIC(19,4);
BEGIN
    SELECT company_id, total_debit, total_credit, rounding_tolerance
      INTO v_company_id, v_total_debit, v_total_credit, v_tolerance
      FROM accounting.trial_balance_snapshots WHERE id = p_snapshot_id;

    -- Rule 1: Debit must equal Credit
    IF ABS(v_total_debit - v_total_credit) > v_tolerance THEN
        INSERT INTO accounting.trial_balance_ai_findings
            (snapshot_id, company_id, finding_type, severity, source, title, description, supporting_data)
        VALUES (
            p_snapshot_id, v_company_id, 'imbalance', 'critical', 'system',
            'Trial Balance is out of balance',
            format('Total debit %s does not equal total credit %s (variance %s).',
                   v_total_debit, v_total_credit, v_total_debit - v_total_credit),
            jsonb_build_object('total_debit', v_total_debit, 'total_credit', v_total_credit,
                                'variance', v_total_debit - v_total_credit)
        );
        UPDATE accounting.trial_balance_snapshots SET status = 'out_of_balance' WHERE id = p_snapshot_id;
    ELSE
        UPDATE accounting.trial_balance_snapshots SET status = 'validated', validated_at = now()
        WHERE id = p_snapshot_id;
    END IF;

    -- Rule 5: Duplicate Accounts (defense-in-depth beyond the UNIQUE constraint)
    INSERT INTO accounting.trial_balance_ai_findings
        (snapshot_id, company_id, account_id, finding_type, severity, source, title, description)
    SELECT p_snapshot_id, v_company_id, l.account_id, 'duplicate_account', 'critical', 'system',
           'Duplicate account line detected',
           format('Account %s appears %s times in this snapshot.', l.account_id, COUNT(*))
    FROM accounting.trial_balance_snapshot_lines l
    WHERE l.snapshot_id = p_snapshot_id
    GROUP BY l.account_id
    HAVING COUNT(*) > 1;

    -- Rule 9: Suspense Account Non-Zero
    INSERT INTO accounting.trial_balance_ai_findings
        (snapshot_id, company_id, account_id, finding_type, severity, source, title, description)
    SELECT p_snapshot_id, v_company_id, l.account_id, 'suspense_balance', 'high', 'system',
           'Suspense account carries a non-zero balance',
           format('Suspense account %s has closing balance debit=%s credit=%s.',
                  l.account_code, l.closing_debit, l.closing_credit)
    FROM accounting.trial_balance_snapshot_lines l
    JOIN accounting.accounts a ON a.id = l.account_id
    WHERE l.snapshot_id = p_snapshot_id AND a.is_suspense = true
      AND (l.closing_debit <> 0 OR l.closing_credit <> 0);

    -- ...remaining rules (missing_account, inactive_account_balance, closed_period_violation,
    -- currency_error, rounding_tolerance, control_account_mismatch, stale_fx_rate,
    -- abnormal_balance) follow the identical INSERT ... SELECT ... WHERE <predicate> pattern.
END;
$$ LANGUAGE plpgsql;
```

## Snapshots, History, and Versioning

"Snapshot" is the row itself — a Trial Balance is never recomputed live once persisted; `Export` and
`Review` always read the frozen `trial_balance_snapshot_lines`, not a live re-query of `journal_lines`.
"History" is simply every non-deleted `trial_balance_snapshots` row for a company, browsable and
filterable by type/period/status — nothing is purged. "Versioning" is enforced structurally:

```sql
-- Regenerating an existing logical snapshot: demote the old current version, insert the new one.
BEGIN;
  UPDATE accounting.trial_balance_snapshots
     SET is_current = false, updated_at = now(), updated_by = :user_id
   WHERE id = :previous_snapshot_id;

  INSERT INTO accounting.trial_balance_snapshots (
      company_id, branch_id, department_id, project_id, cost_center_id,
      fiscal_year_id, fiscal_period_id, as_of_date, period_start_date,
      type, status, parent_snapshot_id, version, is_current, currency_code,
      generation_mode, generated_by, created_by
  )
  SELECT
      company_id, branch_id, department_id, project_id, cost_center_id,
      fiscal_year_id, fiscal_period_id, :new_as_of_date, period_start_date,
      type, 'generating', :previous_snapshot_id, version + 1, true, currency_code,
      'manual', :user_id, :user_id
  FROM accounting.trial_balance_snapshots WHERE id = :previous_snapshot_id
  RETURNING id;
COMMIT;
```

A `BEFORE UPDATE` trigger on `trial_balance_snapshots` additionally rejects any attempt to modify
`total_debit`, `total_credit`, `closing_debit`/`closing_credit` aggregates, or any `trial_balance_snapshot_lines`
row once `status` is `approved` or `archived`, raising `EXCEPTION 'trial_balance_snapshot_immutable'` —
the only legitimate paths to change a number are Archive-locking (which touches only lifecycle columns)
or a brand-new version.

## Materialized View for Performance

For companies with very large `journal_lines` volumes, a materialized view pre-aggregates monthly
account movements so `fn_compute_trial_balance` can read from it instead of scanning raw lines for
older, closed periods (open/current-period data is always read live for correctness):

```sql
CREATE MATERIALIZED VIEW accounting.mv_monthly_account_movements AS
SELECT
    je.company_id,
    jl.account_id,
    jl.branch_id, jl.department_id, jl.project_id, jl.cost_center_id,
    date_trunc('month', je.posted_at)::date AS movement_month,
    SUM(jl.base_currency_debit)  AS month_debit,
    SUM(jl.base_currency_credit) AS month_credit,
    COUNT(*) AS line_count
FROM accounting.journal_lines jl
JOIN accounting.journal_entries je ON je.id = jl.journal_entry_id
WHERE je.status = 'posted' AND je.deleted_at IS NULL
GROUP BY 1,2,3,4,5,6,7;

CREATE UNIQUE INDEX ux_mvmam_key ON accounting.mv_monthly_account_movements (
    company_id, account_id, COALESCE(branch_id,0), COALESCE(department_id,0),
    COALESCE(project_id,0), COALESCE(cost_center_id,0), movement_month
);

-- Refreshed concurrently after each fiscal_period close, and nightly for the trailing 3 months
-- (REFRESH MATERIALIZED VIEW CONCURRENTLY requires the unique index above).
```

## Relationships Summary

| Table | Relates To | Nature |
|---|---|---|
| `trial_balance_snapshots` | `companies`, `branches`, `departments`, `projects`, `cost_centers` | scope (FK, nullable except company) |
| `trial_balance_snapshots` | `fiscal_years`, `fiscal_periods` | period context (FK, nullable for ad-hoc as-of-date runs) |
| `trial_balance_snapshots` | `trial_balance_snapshots` (self) | `parent_snapshot_id` — version lineage and UTB→ATB→PCTB chain |
| `trial_balance_snapshot_lines` | `trial_balance_snapshots` | 1 snapshot : many lines, `ON DELETE CASCADE` |
| `trial_balance_snapshot_lines` | `accounts`, `account_types` | denormalized copy at snapshot time + FK for drill-down |
| `trial_balance_approvals` | `trial_balance_snapshots` | 1 snapshot : many ordered approval steps |
| `trial_balance_snapshot_adjustments` | `trial_balance_snapshots`, `journal_entries` | many-to-many join explaining UTB→ATB/PCTB deltas |
| `trial_balance_ai_findings` | `trial_balance_snapshots`, `accounts`, `journal_entries`, `journal_lines` | 1 snapshot : many findings, each optionally pinned to a specific account/entry/line |
| `trial_balance_exports` | `trial_balance_snapshots`, `attachments` | 1 snapshot : many export events, each backed by one stored file |

# AI Responsibilities

AI never writes to the database directly and never bypasses the permissions of the user (or the system
context) it is acting under. Every AI action in this module surfaces as a `trial_balance_ai_findings`
row (for analysis) or a `journal_entries` row with `status = 'draft'` and `ai_generated = true` (for a
proposed correction) — always awaiting a human decision. The relevant agents are the **Auditor**, the
**Fraud Detection** agent, the **Forecast Agent**, and the **Reporting Agent**; the **General Accountant**
agent consumes Trial Balance findings when assisting an accountant conversationally but does not
generate new findings of its own in this module.

| Responsibility | Agent | Inputs | Outputs | Autonomy | Confidence Handling |
|---|---|---|---|---|---|
| Detect Imbalance | Auditor | `trial_balance_snapshots` totals, `trial_balance_snapshot_lines` | `trial_balance_ai_findings` (`imbalance`) | Suggest-only (system rules already hard-block; AI adds root-cause narrative) | N/A — deterministic, confidence fixed at 1.0 |
| Explain Differences | Auditor | Two snapshots (e.g., UTB vs ATB, or this month vs last month) | Narrative finding describing which accounts moved, by how much, and the linked `journal_entries`/`trial_balance_snapshot_adjustments` responsible | Suggest-only | Confidence reflects how completely the delta is attributable to identified entries (1.0 if 100% attributed, lower if a residual remains) |
| Suggest Corrections | Auditor + General Accountant | Open findings (`missing_account`, `currency_error`, `control_account_mismatch`) | A draft `journal_entries` row (`status='draft'`, `ai_generated=true`) with proposed debit/credit lines, plus a `suggested_journal_entry_id` back-reference on the finding | Requires-approval (human must review and post) | Confidence scored on how closely the pattern matches previously-approved corrections of the same finding type at this company |
| Forecast Errors | Forecast Agent | Historical snapshot line trends (via `mv_monthly_account_movements`), seasonality, upcoming period's expected activity | `trial_balance_ai_findings` (`forecasted_error`) raised *before* period close, e.g. "Depreciation adjusting entry for Q3 has not been posted and is forecast to be required based on the last 4 quarters" | Suggest-only | Confidence reflects historical hit-rate of the same forecast pattern for this company |
| Variance Analysis | Reporting Agent | Comparative Trial Balance data across periods/branches | `trial_balance_ai_findings` (`variance_pattern`) with magnitude, direction, and affected accounts, feeding the Comparative and Trends reports | Suggest-only (always attached as read-only annotation on reports) | Confidence reflects statistical significance of the variance vs. the account's historical volatility |
| Pattern Recognition | Fraud Detection | Sequences of postings across periods (e.g., recurring round-number entries, weekend postings, same-user create+approve) | `trial_balance_ai_findings` (`anomaly`) with the specific pattern named | Suggest-only, escalated to Owner/Admin notification if `severity = 'critical'` | Confidence reflects statistical deviation from the company's own historical posting baseline, never a fixed threshold shared across companies |
| Anomaly Detection | Fraud Detection | Individual line-level outliers (amount far outside an account's historical range, unusual account pairing, entry posted just before period lock) | `trial_balance_ai_findings` (`anomaly`) tied to specific `journal_lines.id` | Suggest-only | Confidence combines z-score of amount deviation and rarity of the account pairing |

**Grounding and traceability.** Every AI-generated finding's `supporting_data` JSONB must include enough
raw figures (account IDs, amounts, period boundaries, linked entry IDs) that a human reviewer can verify
the claim without re-running the AI — the finding is a claim with evidence attached, not an opaque
verdict. `reasoning` is a free-text explanation in the same language as the user's session locale
(English or Arabic, per company `locale` setting), generated by the underlying LLM call but always
citing the `supporting_data` fields it used, never inventing figures not present in the data. Every AI
call that produces a finding or a draft entry is itself logged in `ai_conversations`/`ai_messages` with
the exact prompt/response pair, so an auditor can inspect not just *what* the AI concluded but *how*.

**Escalation discipline.** If Fraud Detection raises a `critical` anomaly finding, the module fires a
notification to Owner/Admin/CFO roles *in addition to* the Accountant/Finance Manager who would normally
see it, and the snapshot's Approval Chain automatically inserts an additional required "Fraud Review"
step before `status` can reach `approved` — this is the one place the AI's output changes workflow
structure, but it does so by *adding a mandatory human gate*, never by removing one.

# Reports

All Trial Balance reports share one data contract: they are rendered from `trial_balance_snapshots` +
`trial_balance_snapshot_lines` of an already-generated (and, for external-facing use, approved) run —
never from a live, uncommitted query — so that two people looking at "the July Trial Balance" always see
identical numbers regardless of when they open the report.

| Report | Source `type` | Grouping / Filter | Typical Audience |
|---|---|---|---|
| Trial Balance | `unadjusted` | Company, optional branch/department/project/cost-center filter | Accountant, period-close working paper |
| Adjusted Trial Balance | `adjusted` | Same as above, plus adjustment lineage panel from `trial_balance_snapshot_adjustments` | Finance Manager, feeds financial statements |
| Comparative Trial Balance | any two snapshots of the same `type` and scope, different `as_of_date`/`fiscal_period_id` | Side-by-side columns per period with a computed variance column (amount and %) | Finance Manager, Auditor, Board reporting |
| Monthly Trial Balance | `unadjusted`/`adjusted` scoped to one `fiscal_periods` row where `period_type = 'month'` | Standard period-close cadence | Accountant |
| Quarterly Trial Balance | Rolled up from three monthly snapshots' closing balances (closing of month 3 = quarter closing; no separate computation, just a UI/report aggregation over existing monthly snapshots) | Board/investor reporting cadence | Finance Manager, CFO |
| Yearly Trial Balance | Closing balances of the fiscal year's final month, cross-checked against `post_closing` snapshot once the year is closed | Annual filing, statutory reporting | Auditor, CFO, tax filing |
| Branch Trial Balance | `branch_id` filter applied to `fn_compute_trial_balance` | Per-branch operational review | Branch manager, Finance Manager |
| Department Trial Balance | `department_id` filter | Departmental cost accountability | Department Head, Finance Manager |
| Project Trial Balance | `project_id` filter | Project profitability and cost tracking (construction, services, grants) | Project Manager, Finance Manager |

Every report definition is also registered in the foundation `report_definitions` table
(`key = 'trial_balance'`, `'trial_balance_adjusted'`, `'trial_balance_comparative'`, etc.) so it inherits
scheduling (`report_schedules` — e.g., "email the Monthly Trial Balance to the CFO on the 3rd business
day after month close") and execution history (`report_runs`) from the shared reporting infrastructure,
rather than re-implementing scheduling logic inside this module.

**Display conventions common to all variants:** accounts with a zero closing balance are hidden by
default (toggle `include_zero_balances`); accounts are grouped and ordered by `account_types.display_order`
then `accounts.code`; the report footer always shows Total Debit, Total Credit, and Variance, and renders
the Variance row in a visually distinct (red/flagged) style whenever it is non-zero beyond tolerance —
there is no report mode that hides an imbalance.

# API

All endpoints are under `/api/v1/accounting/trial-balance` and require a Bearer token plus
`X-Company-Id`. Responses use the standard envelope. Every write endpoint is idempotent on retry using a
client-supplied `Idempotency-Key` header, stored against the resulting `snapshot_id` for 24 hours.

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | `/trial-balance/generate` | `accounting.trial_balance.generate` | Generate a new snapshot (UTB/ATB/PCTB) for a scope and period/date |
| POST | `/trial-balance/{id}/refresh` | `accounting.trial_balance.generate` | Regenerate an existing logical snapshot as a new version |
| GET | `/trial-balance/{id}` | `accounting.trial_balance.read` | Fetch a snapshot header + paginated lines |
| GET | `/trial-balance` | `accounting.trial_balance.read` | List/search snapshots by scope, type, status, date range |
| GET | `/trial-balance/{id}/lines` | `accounting.trial_balance.read` | Paginated, filterable, sortable line list |
| GET | `/trial-balance/{id}/findings` | `accounting.trial_balance.read` | List AI/system findings for a snapshot |
| PATCH | `/trial-balance/findings/{findingId}` | `accounting.trial_balance.read` | Acknowledge / resolve / dismiss a finding (with reason) |
| POST | `/trial-balance/{id}/review` | `accounting.trial_balance.read` | Move snapshot into `under_review`, assign reviewer |
| POST | `/trial-balance/{id}/approve` | `accounting.trial_balance.approve` | Record an approval-chain step (approve/reject/request changes) |
| POST | `/trial-balance/{id}/export` | `accounting.trial_balance.export` | Generate PDF/Excel/CSV export, returns signed download URL |
| GET | `/trial-balance/{id}/export/{exportId}` | `accounting.trial_balance.export` | Re-fetch a prior export's signed download URL |
| POST | `/trial-balance/{id}/archive` | `accounting.trial_balance.archive` | Lock snapshot as archived (terminal) |
| GET | `/trial-balance/{id}/history` | `accounting.trial_balance.history.read` | List all versions/lineage for the logical snapshot |
| GET | `/trial-balance/{id}/drill-down` | `accounting.trial_balance.read` | Given an `account_id`, return contributing `journal_lines` |
| GET | `/trial-balance/comparative` | `accounting.trial_balance.read` | Compute a comparative view across 2+ snapshot IDs or periods |

## Generate — balanced result

Request:

```json
POST /api/v1/accounting/trial-balance/generate
X-Company-Id: 42
Idempotency-Key: 8b1a9953-6c4c-4c1e-9a3f-3a3b6a2b1a10

{
  "type": "unadjusted",
  "fiscal_period_id": 218,
  "branch_id": null,
  "breakdown": { "by_branch": true, "by_department": false, "by_project": false, "by_cost_center": false },
  "include_zero_balances": false,
  "generation_mode": "manual"
}
```

Response (`200 OK`):

```json
{
  "success": true,
  "data": {
    "id": 9081,
    "company_id": 42,
    "type": "unadjusted",
    "status": "validated",
    "fiscal_period_id": 218,
    "as_of_date": "2026-07-31",
    "period_start_date": "2026-07-01",
    "version": 1,
    "is_current": true,
    "currency_code": "KWD",
    "total_debit": "1284502.7600",
    "total_credit": "1284502.7600",
    "variance": "0.0000",
    "has_warnings": false,
    "account_count": 214,
    "line_count": 214,
    "content_hash": "9f2c1e6b9a4a8f0c6d7b2e1a3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d",
    "generated_by": 501,
    "generated_at": "2026-08-01T06:03:12Z",
    "validated_at": "2026-08-01T06:03:14Z"
  },
  "message": "Trial balance generated and validated successfully.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1b6a8f2e-77b1-4b9d-9a2b-6f1c8e0a5d3c",
  "timestamp": "2026-08-01T06:03:14Z"
}
```

## Generate — out-of-balance result

Response (`200 OK` — generation itself succeeds; the imbalance is a data finding, not an HTTP error):

```json
{
  "success": true,
  "data": {
    "id": 9082,
    "company_id": 42,
    "type": "unadjusted",
    "status": "out_of_balance",
    "fiscal_period_id": 219,
    "as_of_date": "2026-08-31",
    "period_start_date": "2026-08-01",
    "version": 1,
    "is_current": true,
    "currency_code": "KWD",
    "total_debit": "998410.1200",
    "total_credit": "998385.6700",
    "variance": "24.4500",
    "has_warnings": true,
    "account_count": 217,
    "line_count": 217,
    "findings": [
      {
        "id": 55021,
        "finding_type": "imbalance",
        "severity": "critical",
        "source": "system",
        "title": "Trial Balance is out of balance",
        "description": "Total debit 998410.1200 does not equal total credit 998385.6700 (variance 24.4500).",
        "supporting_data": { "total_debit": "998410.1200", "total_credit": "998385.6700", "variance": "24.4500" }
      },
      {
        "id": 55022,
        "finding_type": "currency_error",
        "severity": "critical",
        "source": "ai",
        "ai_agent": "auditor",
        "title": "Suspicious FX conversion on journal line",
        "description": "Journal line 771204 (AED 1,200.00 at rate 0.0812) computes to base amount 97.44, but the stored base_currency_debit is 121.89 — a 24.45 variance that exactly matches the trial balance imbalance.",
        "supporting_data": { "journal_line_id": 771204, "transaction_amount": "1200.0000", "exchange_rate": "0.081200", "expected_base": "97.4400", "recorded_base": "121.8900" },
        "confidence": 0.98,
        "reasoning": "The recorded base-currency amount does not equal transaction_amount * exchange_rate within tolerance, and the discrepancy value equals the total trial balance variance to the cent, indicating this single line is the root cause.",
        "suggested_action": "Post a correcting entry reducing the debit on line 771204 by 24.4500 KWD, or correct the exchange_rate and re-derive the base amount."
      }
    ]
  },
  "message": "Trial balance generated but is out of balance. See findings for root cause.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "4c9d8e7f-6a5b-4c3d-9e8f-7a6b5c4d3e2f",
  "timestamp": "2026-09-01T06:04:02Z"
}
```

## Approve

```json
POST /api/v1/accounting/trial-balance/9081/approve
X-Company-Id: 42

{ "action": "approved", "comment": "Reviewed against sub-ledger AR/AP reconciliation; no exceptions." }
```

```json
{
  "success": true,
  "data": {
    "snapshot_id": 9081,
    "step_order": 2,
    "required_role": "finance_manager",
    "action": "approved",
    "signed_at": "2026-08-02T09:11:04Z",
    "snapshot_status": "approved",
    "all_steps_complete": true
  },
  "message": "Approval recorded. Trial balance is now fully approved.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d",
  "timestamp": "2026-08-02T09:11:04Z"
}
```

## Export (Excel)

```json
POST /api/v1/accounting/trial-balance/9081/export
X-Company-Id: 42

{ "format": "xlsx", "breakdown": "branch", "include_zero_balances": false }
```

```json
{
  "success": true,
  "data": {
    "export_id": 3391,
    "format": "xlsx",
    "download_url": "https://r2.qayd.app/exports/9081/trial-balance-2026-07-branch.xlsx?sig=...",
    "expires_at": "2026-08-02T10:00:00Z",
    "is_draft_watermarked": false
  },
  "message": "Export generated.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "5e6f7a8b-9c0d-4e1f-8a2b-3c4d5e6f7a8b",
  "timestamp": "2026-08-02T09:15:47Z"
}
```

## Search / History

```json
GET /api/v1/accounting/trial-balance?type=unadjusted&status=approved&fiscal_year_id=11&page=1&per_page=25
```

```json
{
  "success": true,
  "data": [
    { "id": 9081, "type": "unadjusted", "status": "approved", "fiscal_period_id": 218, "as_of_date": "2026-07-31", "version": 1, "is_current": true, "total_debit": "1284502.7600", "total_credit": "1284502.7600" }
  ],
  "message": "OK",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 12, "cursor": null } },
  "request_id": "0f1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b",
  "timestamp": "2026-08-02T09:20:00Z"
}
```

```json
GET /api/v1/accounting/trial-balance/9081/history
```

```json
{
  "success": true,
  "data": {
    "logical_key": { "company_id": 42, "fiscal_period_id": 218, "type": "unadjusted" },
    "versions": [
      { "id": 9010, "version": 1, "is_current": false, "status": "archived", "generated_at": "2026-08-01T05:40:00Z", "superseded_reason": "Restated after correcting FX line 771204" },
      { "id": 9081, "version": 2, "is_current": true, "status": "approved", "generated_at": "2026-08-01T06:03:12Z" }
    ]
  },
  "message": "OK",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "6a7b8c9d-0e1f-4213-8a45-6b7c8d9e0f1a",
  "timestamp": "2026-08-02T09:22:11Z"
}
```

# Permissions

Permission keys follow the platform's `<area>.<entity>.<action>` convention, default-deny. The AI Agent
"role" below is not a human role — it is the fixed system-user context every AI action runs under, and
it is granted only the subset of permissions needed to read data and create findings/drafts, never to
approve, export, or archive.

| Permission Key | Description |
|---|---|
| `accounting.trial_balance.read` | View snapshots, lines, findings, drill-down, history |
| `accounting.trial_balance.generate` | Trigger Generate and Refresh (new version) |
| `accounting.trial_balance.review` | Move a snapshot into Review, assign self as reviewer |
| `accounting.trial_balance.finding.manage` | Acknowledge/resolve/dismiss AI or system findings |
| `accounting.trial_balance.approve` | Record an approval-chain step |
| `accounting.trial_balance.export` | Generate and download PDF/Excel/CSV exports |
| `accounting.trial_balance.archive` | Archive an approved snapshot (terminal lock) |
| `accounting.trial_balance.history.read` | View version lineage and superseded snapshots |
| `accounting.trial_balance.override.post` | Post a documented balancing/historical-variance adjustment (Business Rule 10) |

| Permission | Owner | Admin | Finance Manager | Accountant | Auditor | Employee | AI Agent |
|---|---|---|---|---|---|---|---|
| `accounting.trial_balance.read` | Yes | Yes | Yes | Yes | Yes | No | Yes (read-only, own company only) |
| `accounting.trial_balance.generate` | Yes | Yes | Yes | Yes | No | No | No |
| `accounting.trial_balance.review` | Yes | Yes | Yes | Yes | Yes | No | No |
| `accounting.trial_balance.finding.manage` | Yes | Yes | Yes | Yes | Yes | No | Create only (cannot resolve/dismiss own findings) |
| `accounting.trial_balance.approve` | Yes | Yes | Yes | No | Yes (PCTB step only) | No | No |
| `accounting.trial_balance.export` | Yes | Yes | Yes | Yes | Yes | No | No |
| `accounting.trial_balance.archive` | Yes | Yes | Yes | No | No | No | No |
| `accounting.trial_balance.history.read` | Yes | Yes | Yes | Yes | Yes | No | Yes |
| `accounting.trial_balance.override.post` | Yes | Yes | No | No | No | No | No |

Employees with no accounting role have no visibility into Trial Balance data whatsoever — this module
is Finance/Accounting/Audit-scoped by design; there is no "self-service" employee view. Auditor's
`approve` scope is intentionally restricted to the mandatory sign-off step on Post-Closing runs (Business
Rule/Workflow "Approve"), not the routine monthly Accountant→Finance Manager chain, reflecting a
segregation-of-duties principle: the same person who books the routine entries never has sole authority
to certify the final closed position.

# Notifications

| Event | Trigger | Recipients | Channel |
|---|---|---|---|
| `trial_balance.generated` | Generate completes | Requesting user | In-app (Reverb), push |
| `trial_balance.out_of_balance_detected` | Validate finds an imbalance | Finance Manager, Accountant, Owner | In-app, email, push |
| `trial_balance.critical_finding` | An AI/system finding with `severity = 'critical'` is created | Finance Manager, Owner, Admin (plus CFO if Fraud Detection) | In-app, email, push |
| `trial_balance.review_requested` | Snapshot moved to `under_review` | Assigned reviewer, next approver in chain | In-app, email |
| `trial_balance.approval_step_completed` | Any approval-chain step recorded | Requesting user, next approver in chain | In-app |
| `trial_balance.approved` | All approval steps complete | Requesting user, Finance Manager, Auditor (if PCTB) | In-app, email |
| `trial_balance.rejected` | An approval step is `rejected` | Requesting user, prior approvers | In-app, email |
| `trial_balance.exported` | Export generated | Requesting user | In-app |
| `trial_balance.archived` | Archive action completes | Requesting user, Finance Manager | In-app, email |
| `trial_balance.ai_suggested_correction` | AI creates a draft correcting journal entry | Accountant, Finance Manager | In-app, push |
| `trial_balance.schedule_due` | A `report_schedules` entry for Trial Balance fires | Configured recipient list | Email (with PDF/Excel attachment) |

All notifications are persisted to the foundation `notifications` table (so they appear in-app even if a
push/email channel fails) and delivered in real time over Laravel Reverb to any connected client
subscribed to the company's channel. Email notifications respect each user's locale (`en`/`ar`) and RTL
formatting for Arabic. Notification preferences (which events a given role receives by which channel) are
configurable per company within the bounds of the mandatory rows above (Owner/Admin cannot be fully
muted from `critical_finding` or `out_of_balance_detected`, by design).

# Security

- **Tenant isolation.** Every query against `trial_balance_snapshots` and its children is scoped by
  `company_id` through a Laravel global model scope applied at the Eloquent layer before any controller
  code runs; there is no code path that constructs a Trial Balance query without an authenticated,
  company-bound request context. As defense-in-depth, Postgres Row-Level Security policies are enabled
  on all six tables, keyed to a session-level `app.current_company_id` GUC set at the start of every
  request's database transaction:
  ```sql
  ALTER TABLE accounting.trial_balance_snapshots ENABLE ROW LEVEL SECURITY;
  CREATE POLICY tbs_company_isolation ON accounting.trial_balance_snapshots
    USING (company_id = current_setting('app.current_company_id')::bigint);
  ```
  Equivalent policies apply to `trial_balance_snapshot_lines`, `trial_balance_approvals`,
  `trial_balance_snapshot_adjustments`, `trial_balance_ai_findings`, and `trial_balance_exports`.
- **Authentication.** Every endpoint requires a valid Sanctum/JWT bearer token; tokens carry the
  authenticated user's role and permission set, re-validated server-side on every request (never trusted
  from a client-supplied claim alone).
- **Authorization.** Every controller action calls a Laravel Policy (`TrialBalanceSnapshotPolicy`) that
  checks the specific permission key for the action against the user's role within the active company —
  denied by default; there is no implicit "same company therefore allowed" shortcut.
- **Immutability enforcement.** Postgres triggers reject any `UPDATE` to financially-significant columns
  once a snapshot is `approved` or `archived` (see Database Design), so even a compromised application
  credential with direct DB access cannot silently alter an approved Trial Balance without leaving a
  rejected-statement error in the Postgres logs.
- **Tamper evidence.** `content_hash` (`sha256` over the canonicalized, ordered line set) is computed at
  generation time and re-verified on every read of an `approved`/`archived` snapshot; a mismatch raises a
  `critical` system finding and blocks Export until investigated.
- **Export security.** Exported files are stored in a private Cloudflare R2 bucket, never public; download
  URLs are signed and time-limited (default 15 minutes), and every export is watermarked "DRAFT — NOT
  APPROVED" diagonally across each page if `status != 'approved'`.
- **AI sandboxing.** The FastAPI AI layer has no database credentials at all; it calls the Laravel API
  using a scoped service token whose permission set is the fixed "AI Agent" row in the Permissions table
  above, and every AI-originated request is tagged `X-Actor-Type: ai_agent` so audit logs, rate limiting,
  and the immutability triggers can distinguish AI writes from human writes.
- **Rate limiting.** `POST /trial-balance/generate` is rate-limited per company (default 30/hour) to
  prevent runaway scheduled-job misconfiguration from generating excessive snapshot history; AI-agent
  read calls are rate-limited separately from human user calls.
- **Secrets.** No Trial Balance data ever appears in application logs at more than a snapshot ID and
  status level; account balances and line-level figures are excluded from standard log formatters and
  only appear in the encrypted audit-log payload described below.

# Audit Trail

Every state-changing action in this module writes to the foundation `audit_logs` table with: `actor_id`
(user or AI-agent system user), `actor_type` (`human`/`ai_agent`), `action`, `entity_type` (`trial_balance_snapshots`,
`trial_balance_approvals`, `trial_balance_ai_findings`), `entity_id`, `old_value`/`new_value` (JSONB diff),
`reason` (mandatory for overrides and rejections), `ip_address`, `device`/`user_agent`, and `created_at`.
Specifically logged:

- `generate` — full input parameters (scope, type, period), resulting `snapshot_id`, and computed totals.
- `refresh` — the previous version's id/status and the new version's id, plus the `reason` field
  (mandatory: the UI requires a free-text justification whenever a snapshot is regenerated after having
  already reached `validated` or later).
- `finding.acknowledge` / `finding.resolve` / `finding.dismiss` — `old_value.status` → `new_value.status`
  plus the mandatory `resolution_note`.
- `approve` / `reject` / `request_changes` — the full approval-chain step record.
- `export` — format, filters, and the resulting `attachment_id` (so a data-loss investigation can trace
  exactly which file left the system, to whom, and when).
- `archive` — timestamp and actor; because archiving is terminal, this entry is permanently the last
  mutable-state audit event for that snapshot.
- `override.post` — the balancing/historical-variance journal entry id, mandatory `reason`, and the
  approving Owner/Admin.

Audit log rows for this module are themselves immutable (no `UPDATE`/`DELETE` grants on `audit_logs` for
any application role) and are retained per the company's configured retention policy, with a hard floor
of the statutory audit-record retention period for the company's jurisdiction (7 years for Kuwait, 10
years where GCC VAT record-keeping rules apply, configurable upward, never downward below the legal
floor). Auditor and External Auditor roles have standing read access to the full audit trail for any
snapshot they can already view, without needing a separate elevated permission — audit visibility is a
consequence of `accounting.trial_balance.read`, not a gated add-on.

# Performance

- **Async generation for large scopes.** Any Generate request whose estimated `journal_lines` scan
  exceeds a configurable row-count threshold (default 500,000 rows) is automatically queued
  (`GenerateTrialBalanceSnapshot` job on the `accounting-heavy` Redis queue) rather than computed
  synchronously in the request/response cycle; the API returns `202 Accepted` with `status: "generating"`
  and the client subscribes to the `trial_balance.generated` Reverb event.
- **Materialized monthly aggregates.** `mv_monthly_account_movements` (see Database Design) removes the
  need to re-scan every historical `journal_lines` row for closed periods; only the current, still-open
  period is aggregated live. The view is refreshed concurrently immediately after each `fiscal_periods`
  close and nightly for the trailing 3 months to absorb any late-posted reversing entries within still-open
  windows.
- **Composite and partial indexing.** All hot-path filters (`company_id` + `status`, `company_id` +
  `type` + `as_of_date`, open findings by company) have dedicated composite or partial indexes (see
  Database Design) so common list/dashboard queries never fall back to a sequential scan even at
  multi-million-row scale.
- **Bulk line insertion.** `trial_balance_snapshot_lines` are inserted via a single multi-row `INSERT`
  (or `COPY` for very large account counts) per snapshot generation, never row-by-row from the
  application layer, to keep Generate latency dominated by the aggregation query rather than round-trip
  overhead.
- **Read replicas for reporting.** Comparative, Monthly/Quarterly/Yearly rollups, and export rendering
  read from a Postgres read replica where available, isolating heavy reporting I/O from the primary
  instance that serves transactional journal posting.
- **Pagination everywhere.** `GET /trial-balance/{id}/lines` and `/findings` are always paginated
  (default 25, max 200 per page) with cursor pagination available for very large account counts (10,000+
  accounts is realistic for a large multi-branch group with granular dimension breakdowns).
- **Caching.** The header (`trial_balance_snapshots` row) for an `is_current = true`, `approved` snapshot
  is cached in Redis with a long TTL (invalidated explicitly on `refresh`/`archive`), since approved,
  archived data is immutable by construction and safe to cache indefinitely between invalidation events.
- **Query budget on AI findings generation.** The Auditor/Fraud Detection agents operate on the already-
  materialized `trial_balance_snapshot_lines`, never on raw `journal_lines`, keeping AI-analysis latency
  independent of total historical ledger size.

# Edge Cases

1. **Sub-cent rounding drift across many small multi-currency lines.** Thousands of small foreign-
   currency transactions each rounded to 4 decimal places can accumulate a residual variance of a few
   cents at the company level even though each individual line is internally consistent. This is handled
   by the configurable `rounding_tolerance` (default 0.0050) and, if a company disables tolerance
   entirely, by a dedicated "FX Rounding" adjustment account that periodically absorbs the residual via a
   documented, AI-suggested, human-approved adjusting entry — never by silently changing the tolerance to
   make the number "look" balanced.
2. **Regenerating a period after a discovered posting error in an already-approved snapshot.** The
   original snapshot is never edited; a reversing entry is posted (dated into the current open period,
   never backdated into the closed period) and a new version is generated with `parent_snapshot_id`
   pointing to the superseded one and a mandatory `refresh` reason recorded in the audit trail.
3. **Branch/Department/Project Trial Balance not balancing on its own.** Documented as expected behavior
   (Business Rule 7) rather than an error — the UI explicitly labels dimension-scoped Trial Balances as
   "partial view; company-level balance is the authoritative check" to prevent a branch manager from
   mistakenly treating a legitimate dimensional imbalance as a bookkeeping error.
4. **Deleted (soft-deleted) accounts with historical posted balances.** `accounts.deleted_at` does not
   remove the account from any Trial Balance covering a period during which it held posted activity;
   `fn_compute_trial_balance` filters on `deleted_at IS NULL` only for accounts with *zero* historical
   activity being newly excluded from *future* period computations — an account with any posted
   `journal_lines` ever remains computable for any historical period regardless of its current
   soft-deleted status, using the denormalized `account_code`/`account_name` captured on the snapshot
   line at generation time so a later account rename or deactivation never rewrites history.
5. **Concurrent Generate requests for the same logical snapshot.** Guarded by a Postgres advisory lock
   (`pg_advisory_xact_lock(hashtext(company_id || ':' || fiscal_period_id || ':' || type))`) held for the
   duration of the Generate transaction, so two simultaneous "Generate July UTB" clicks cannot both
   succeed and create two `is_current = true` rows — the second request either waits and then correctly
   creates version 2, or is rejected with `409 Conflict` if a `--fail-fast` idempotency mode is requested.
6. **Entries retroactively posted into a period between UTB and ATB generation.** If new routine
   (non-adjusting) activity posts into the same period after the UTB was generated but before the ATB is
   requested, the ATB generation step detects that its would-be parent UTB is stale (its `content_hash`
   no longer matches a fresh recomputation) and forces a UTB regeneration first, presenting both the old
   and new UTB to the reviewer with the specific new entries highlighted, rather than silently basing the
   ATB on outdated figures.
7. **Company base-currency change.** Handled per Business Rule 12: all historical snapshots retain their
   original `currency_code`; any Comparative report spanning the change point converts using the
   documented historical rate and flags the conversion explicitly rather than silently re-expressing
   history in the new currency.
8. **Suspense or unclassified accounts still open at fiscal year-end.** A `post_closing` generation
   attempt with a non-zero suspense balance does not block generation (Business Rules never block a
   truthful computation) but does block the *Approve* step until either the balance is cleared via a
   proper reclassification entry or an Owner/Admin explicitly acknowledges it in the approval comment —
   an unresolved suspense balance can never quietly ride into a "clean" approved PCTB.
9. **A closed fiscal period is later reopened (rare, permission-gated operation owned by the Fiscal
   Periods module, referenced here).** Any Trial Balance snapshot previously generated against that
   period remains valid history (it reflected the truth at the time), but its `is_current` flag is
   automatically cleared and a system-generated informational finding is attached noting "underlying
   period was reopened after this snapshot; regenerate before relying on this as current."
10. **Zero-activity company/period (e.g., a newly onboarded company with only an opening-balance
    entry).** The Trial Balance still generates normally; `account_count` may be as small as the number
    of accounts touched by the opening-balance entry, and the report correctly shows a balanced Trial
    Balance of just those accounts rather than erroring on "insufficient data."
11. **An account reclassified from one `account_types` classification to another mid-year** (e.g., a
    account incorrectly set up as an Expense later corrected to an Asset). Because `normal_balance` is
    denormalized onto each snapshot line at generation time, historical snapshots continue to display the
    classification that was correct *as of that snapshot's generation*, while new snapshots reflect the
    corrected classification — the module never retroactively reclassifies frozen history.
12. **AI agent unavailable or returns low-confidence/no findings.** The workflow does not block on AI:
    Generate → Validate (system rules) always completes and can proceed to Review even if the AI Auditor/
    Fraud Detection call fails, times out, or is disabled for the company; the Review screen simply shows
    "AI review pending/unavailable" rather than blocking human progress, since AI is a suggest-only,
    additive layer, never a required gate.

# Future Improvements

1. **Continuous (streaming) Trial Balance.** Move from on-demand/scheduled generation of the *current*
   open-period UTB to a continuously-maintained live balance (via incremental materialized view refresh
   triggered on every `journal_lines` insert), so a Finance Manager's dashboard shows an always-current
   "as of right now" Trial Balance without an explicit Generate click, while formal period-close
   snapshots remain the frozen, approvable artifact.
2. **XBRL and regulatory-taxonomy export.** Add an export format that maps `accounts` to the applicable
   jurisdiction's regulatory chart-of-accounts taxonomy (e.g., Kuwait CBK/MOCI reporting formats, IFRS
   taxonomy tags) directly from the Trial Balance, removing a manual remapping step at statutory filing
   time.
3. **ML-assisted adjusting-entry classification.** Extend the Forecast Agent to not just flag that an
   adjusting entry is likely missing, but propose the specific accrual/deferral amount based on
   contract terms already stored in `procurement_contracts`/subscription billing schedules, raising the
   Suggest Corrections confidence for period-end accruals specifically.
4. **Dual-framework (IFRS/local GAAP) parallel Trial Balances.** Support a second, parallel chart-of-
   accounts mapping so a company can generate an IFRS-basis and a local-GAAP-basis Trial Balance from the
   same underlying `journal_lines`, differing only in classification/adjusting-entry treatment, without
   maintaining two separate ledgers.
5. **Natural-language Trial Balance queries.** Let a Finance Manager ask the General Accountant agent
   "why did our total liabilities jump 12% this month" and have it answer by querying
   `trial_balance_ai_findings`/`trial_balance_snapshot_lines`/`journal_lines` live and citing the exact
   entries, rather than requiring the user to manually open the Comparative report and drill down.
6. **Consolidated multi-company Trial Balance with automated eliminations.** For corporate groups with
   several `companies` rows under common ownership, generate a consolidated Trial Balance that
   automatically identifies and nets designated inter-company accounts (flagged similarly to
   `is_suspense`, e.g. `is_intercompany`), rather than requiring a manual elimination adjustment each
   period.
7. **Immutable external anchoring.** Periodically publish the `content_hash` of each `approved`/
   `archived` snapshot to an external, tamper-proof timestamping service (or a private blockchain
   ledger) so a company can prove to a regulator or auditor, independent of QAYD's own database, that an
   approved Trial Balance has not been altered since a specific point in time.
8. **Configurable materiality-driven auto-summarization in Review.** Instead of a reviewer scanning every
   AI finding, let the Reporting Agent generate a one-paragraph, materiality-ranked executive summary of
   "what actually needs your attention this period," reserving the full finding list for accountants who
   want the detail.
9. **What-if adjusting-entry sandbox.** Allow a Finance Manager to draft a hypothetical adjusting entry
   and preview its effect on the Adjusted Trial Balance and downstream financial statements before
   posting, without creating any real `journal_entries` row until the preview is accepted.
10. **Cross-period anomaly clustering.** Extend Fraud Detection from single-snapshot anomaly scoring to
    clustering anomalies across many periods/companies (where the AI Agent has appropriate scoped
    visibility) to detect systemic issues — e.g., a specific integration or import job that has been
    silently mis-mapping a currency code for several months — earlier than a single-period review would
    catch.

# End of Document
