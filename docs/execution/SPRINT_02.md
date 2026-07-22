# Sprint 02 — Accounting Core — QAYD Execution
Version: 1.0
Status: Planning
Module: Execution
Submodule: Sprint 02
---

# Purpose / Goal

Sprint 02 builds the double-entry heart of QAYD on top of the isolated, authenticated tenant that
[SPRINT_01.md](./SPRINT_01.md) delivered. This is the sprint where the platform's central promise
becomes code: **every posting balances (debit = credit), posting is transactional and atomic, and a
posted record is immutable — a mistake is corrected by a reversing entry, never by an edit**, exactly
as specified in [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md).

By the end of the sprint a signed-in accountant can build a chart of accounts, draft a journal entry
with balanced lines, post it through the single authorized posting code path into an immutable ledger,
and read it back as account activity, a trial balance that ties out, and — through the UI — a live
COA tree, a journal-entry editor with client-side balance derivation, and a trial-balance screen. The
ledger projection (`ledger_entries`) is rebuildable byte-for-byte from the posted lines, and a nightly
integrity job proves it.

The exit is deliberately end-to-end and unforgiving: **post and view a balanced journal entry end to
end** — created in the browser, posted through the real API and posting engine, projected to the
ledger, and reflected in the trial balance — while an unbalanced entry is refused with a diagnostic and
a posted entry cannot be edited. Sprint 02 owns no source documents (invoices, bills, payroll); those
arrive as events in later modules. It owns the ledger they will all post through.

# Sprint duration & team

- **Duration:** 2 weeks (10 working days). Sprint 02 of 04.
- **Ceremonies:** planning (day 1), daily stand-up, posting-engine design review (day 3, the single
  highest-risk story), mid-sprint review (day 5), demo + retro (day 10).
- **Team (unchanged squad from Sprint 01):**

| Role | Count | Primary focus this sprint |
|---|---|---|
| Tech Lead / Eng Manager | 1 | Posting-engine review, immutability triggers, idempotency |
| Backend engineers (Laravel) | 2 | COA, journal lifecycle, `PostingService`, GL, TB, fiscal periods |
| Frontend engineers (Next.js) | 2 | COA screen, journal-entry editor, trial-balance screen |
| AI engineer (FastAPI) | 1 | AI-engine contract fixtures + `ai_draft` intake shape (prep for S03/S04) |
| Platform / DevOps | 1 (shared) | `maintenance` queue, Reverb, migration CI on the growing schema |
| QA / SDET | 1 | Posting-invariant + immutability + isolation test coverage |
| Product + Designer | 1 (part-time) | Accounting screen acceptance, bilingual account/statement copy |

- **Target velocity:** ~48 story points. Committed: 47 pts. The posting engine (S2-05) is estimated
  generously and reviewed early because a defect there is a wrong number in a real ledger.

# Objectives

1. **A chart of accounts that cannot silently lie.** The 7 system-seeded `account_types` and the
   `accounts` tree, with create / reclassify / deactivate / opening-balance flows that guard the rule
   that a posted account cannot be silently renumbered or retyped
   ([../accounting/CHART_OF_ACCOUNTS.md](../accounting/CHART_OF_ACCOUNTS.md)).
2. **A journal-entry lifecycle with a hard immutability line.** `journal_entries` + `journal_lines`
   across `draft → pending_approval → posted → reversed/voided`, lines mutable **only** while the parent
   is a draft, enforced at the application layer *and* by database triggers.
3. **One posting code path, provably balanced.** The `PostingService` that asserts `debit = credit`
   (re-derived server-side, zero tolerance, in both currencies), resolves and locks the fiscal period,
   assigns the entry number, and projects `ledger_entries` — all inside one transaction — invoked
   identically by a human click today and by cross-module events / AI drafts later.
4. **A general ledger and trial balance derived, never stored.** `ledger_entries` as the 1:1 projection
   of posted lines, account-activity reads, and a real-time trial balance plus a durable, approvable
   snapshot ([../accounting/TRIAL_BALANCE.md](../accounting/TRIAL_BALANCE.md)).
5. **A fiscal calendar that blocks bad dates.** `fiscal_years` + `fiscal_periods` with `open → closed →
   locked` transitions, so no posting enters a non-open period.
6. **The accounting UI a person actually uses.** COA tree screen, journal-entry editor with client-side
   balance derivation that flags an imbalance the same way the backend will, and a trial-balance screen,
   under the "frontend computes nothing authoritative" rule of
   [../frontend/screens/ACCOUNTING_SCREEN.md](../frontend/screens/ACCOUNTING_SCREEN.md).

# Backlog

Story points on the Fibonacci scale; all money is `NUMERIC(19,4)` serialized as strings; account and
statement names are bilingual (`name_en` / `name_ar`). Every story ends in a demoable, tested increment.

## Epic A — Chart of accounts

The chart of accounts is the vocabulary every later posting speaks in, so it is built first. The rigor
here is the guard that a *posted* account cannot be silently renumbered or retyped — reclassifying an
account that already carries posted lines is refused — because a chart that can be edited under the
ledger is a chart that can make history lie. Opening balances are not a special case: they post through
the same engine as everything else, so the ledger has one and only one way in.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S2-01 | account_types + accounts schema & actions | Accounting | Seed the 7 system `account_types` (asset/liability/equity/revenue/expense/other income/other expense, `is_balance_sheet`, `normal_balance`); migrate the `accounts` tree (`parent_id`, `normal_balance`, `status`, `is_control_account`, `control_account_of`); `CreateAccountAction`, `UpdateAccountAction`, `ReclassifyAccountAction`, `DeactivateAccountAction`, `SetOpeningBalanceAction`. | Account types are tenant-immutable; a leaf account can be created and deactivated; reclassifying an account that already has posted lines is refused (`422`); opening balances post through the same engine; RLS-scoped; feature-tested. | 8 | S1-05, S1-16 |
| S2-02 | COA API + tree read | Accounting | `GET/POST /accounting/accounts`, `PATCH`, `.../reclassify`, `.../deactivate`, `.../opening-balance`; the tree/flat read with bilingual names and control-account designation. | Endpoints enforce `accounting.coa.manage` / `accounting.journal.read`; the tree returns up to N levels with per-account type + normal balance; a cross-tenant id returns 404 at route-model binding; contract-checked. | 3 | S2-01 |

## Epic B — Journal entries & the posting engine

This is the heart of the platform and the heart of the sprint. The posting engine is the single
authorized writer of posted lines, and its invariants — `debit = credit` re-derived server-side with
zero tolerance, one atomic transaction, immutability enforced at both the application and database
layers — are the ones a defect in which is a wrong number in a real ledger, not a UI annoyance. There is
exactly one posting code path, and every later caller (cross-module events, scheduled jobs, AI drafts)
goes through it. The immutability rule is deliberately enforced twice: in the Actions and by database
triggers, so an application bug cannot breach it.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S2-03 | Journal schema + immutability triggers | Accounting | Migrate `journal_entries` (status/origin/type enums, `reverses_entry_id`/`reversed_by_entry_id`, `created_by_agent`, `ai_confidence`, `version`) and `journal_lines` (debit/credit one-side + non-negative CHECKs, dimensions); the `trg_journal_lines_no_update_when_posted` and `trg_no_ai_autopost` triggers; the `chk_journal_entries_balanced` CHECK. | The one-side and non-negative CHECKs reject bad lines; a raw `UPDATE` to a line whose parent is `posted` is rejected by the trigger; inserting an `origin='ai_draft'` row directly as `posted` is rejected; `ledger_entries` has no update path. | 5 | S2-01 |
| S2-04 | Draft lifecycle actions | Accounting | `CreateJournalEntryAction`, `UpdateJournalDraftAction` (optimistic `version`), `SubmitForApprovalAction`, with the `JournalEntryData` DTO and FormRequest validation; lines editable only while `draft`/`rejected`. | Create returns a `draft` (never `posted`) with server-assigned line numbers; editing a stale `version` returns `409 version_conflict`; a `PATCH` to a non-draft returns `409`; an `ai_draft`-origin entry can be created but not posted by an AI-scoped caller. | 5 | S2-03 |
| S2-05 | PostingService (the posting engine) | Accounting | The single authorized writer of posted lines: `assertBalanced` (re-derived `Money` equality, both currencies, zero tolerance), `lockOpenForDate` (period `FOR UPDATE`), postable-account check, `markPosted` (status/number/totals), `projectLines` (one `ledger_entries` row per line, `signed_base_amount`) — all in one `DB::transaction`. `PostJournalEntryAction` wraps it and emits `accounting.journal.posted` after commit. | An unbalanced draft throws `UnbalancedEntryException` (`422 balance_mismatch`) before any projection row is written (no partial post); posting into a closed period throws `422 closed_period`; a line on an inactive account throws `422`; exactly one `ledger_entries` row per posted line with correct sign; unit + feature tested. | 8 | S2-04 |
| S2-06 | Reverse & void | Accounting | `ReverseJournalEntryAction` (mirror entry, swapped debit/credit, sets `reverses_entry_id`/`reversed_by_entry_id`) and `VoidJournalEntryAction` (unposted only); both refuse to mutate a posted record. | Reversing a posted entry produces a balanced mirror linked both ways and leaves the original intact; an edit/void of a terminal entry returns `409 immutable_record` naming `reverse` as the remedy; segregation-of-duties: the entry creator is not its sole approver. | 5 | S2-05 |

## Epic C — Fiscal periods

The fiscal calendar is what makes "you cannot post into a closed month" a real constraint rather than a
policy. Its subtlety is timing: the period is resolved *and locked* (`FOR UPDATE`) at posting time, not
merely checked at draft creation, so a period that closes between drafting and posting still refuses the
post. Every period test pins the clock, because a fiscal boundary is exactly where an unpinned
wall-clock assertion produces an intermittent, ledger-affecting failure.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S2-07 | Fiscal years & periods + close/lock | Accounting | Migrate `fiscal_years` (`future→open→closing→closed`) and `fiscal_periods` (`open→closed→locked`, `module_lock` JSONB); `ClosePeriodAction`, `LockPeriodAction`, `ReopenPeriodAction`; period resolution used by the posting engine. | A post dated into a `closed`/`locked` period is refused (`422 closed_period`); closing a period emits `accounting.period.closed`; reopening requires the permission and is audited; period rows are the ones the posting engine locks `FOR UPDATE`. | 5 | S2-05 |

## Epic D — Ledger & trial balance

The general ledger and trial balance are *derived*, never stored as independent numbers that could
drift. `ledger_entries` is a 1:1 projection of posted lines, and every balance, trial balance, and (in
Sprint 04) statement is a `SUM()` over it. The design guarantee — the one the nightly integrity job in
Epic F exists to prove — is that dropping and rebuilding the projection from the lines produces
byte-identical reports. RLS scopes the aggregate, so there is no code path by which one company's posted
lines enter another's trial balance even if a reporting query forgets its `where` clause.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S2-08 | General ledger reads | Accounting | `LedgerQueryService` over `ledger_entries`: account activity with running balance, dimension filters; `GET /accounting/ledger/accounts/{id}/activity`. | Account activity returns posted lines in date order with a correct running balance computed from `signed_base_amount`; RLS scopes the read so Company A's lines never enter Company B's activity even with an unscoped query; paginated per REST standards. | 3 | S2-05 |
| S2-09 | Trial balance compute + snapshot | Accounting | `TrialBalanceService::compute` (real-time balanced check via the ledger aggregate), `GenerateTrialBalanceSnapshotAction` (durable `trial_balance_snapshots` + lines), and `approve`. | The trial balance's total debits equal total credits for any posted set; a snapshot freezes the figures and can be approved (`accounting.trial_balance.approve`); long generations return `202` and finish on the `reports` queue; feature-tested. | 5 | S2-08 |

## Epic E — Accounting frontend

The accounting UI renders, filters, and routes; it never computes an authoritative figure. The one place
the frontend does math — the client-side balance derivation in the journal editor — is a courtesy that
spares the user a round-trip, and it must flag an imbalance *the same way the backend will*, to four
decimals, with the backend remaining the sole authority on whether an entry may post. Every screen is
built bilingual and themable from the start, with all four visual variants baselined.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S2-10 | Chart of accounts screen | Frontend | The `accounting/` route + sub-nav tabs and the COA hub: a virtualized expandable tree/flat table over accounts, the New Account dialog, and the deactivate/reclassify dialogs — all calling the API, none computing. | The tree renders accounts with type + normal balance; New Account creates via the API and appears in the tree; a reclassify blocked by the backend surfaces the `422` inline; renders in light/dark and LTR/RTL. | 5 | S2-02, S1-15 |
| S2-11 | Journal-entry editor | Frontend | The journal-entry screen: a line grid with account pickers, a client-side `deriveBalance` that flags imbalance the same way the backend will, draft save (optimistic), and the post action with `Idempotency-Key`. | The grid shows a live debit/credit difference and disables Post until balanced; saving a draft round-trips; posting a balanced entry shows `Posted` (بعد الترحيل "مُرحّل" in AR); a backend `balance_mismatch` is surfaced, never hidden; four-variant baseline captured. | 8 | S2-05, S2-10 |
| S2-12 | Trial-balance screen | Frontend | The trial-balance route: the computed TB table (bilingual account names, debit/credit columns, totals), a period selector, and a "generate snapshot" action. | The screen renders the balanced TB with tying totals; selecting a period re-fetches; generating a snapshot shows the durable artifact; empty and loading states per the design system; light/dark + RTL. | 3 | S2-09, S2-10 |

## Epic F — Integrity & idempotency

Two guarantees that make the ledger trustworthy under real-world conditions: a post is idempotent (a
retried request or a redelivered event never double-posts), and the projection is continuously proven to
match the lines. Both are cheap to build now and expensive to retrofit — and both are prerequisites for
Sprint 03, where banking will post cash movements through this engine under exactly the retry conditions
idempotency defends against.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S2-13 | Idempotency + posted-event broadcast | Accounting | `Idempotency-Key` handling for money-moving posts (keyed `(company_id, endpoint, key)`), and the `accounting.journal.posted` Reverb broadcast (compact projection) on `private-company.{id}`. | Re-posting with the same key + body returns the original entry (no double post); same key + different body returns `409 idempotency_key_conflict`; posting broadcasts a compact `journal.posted` projection an open ledger screen consumes to re-fetch. | 3 | S2-05 |
| S2-14 | Nightly ledger integrity job | Accounting | The `maintenance`-queue job that rebuilds `ledger_entries` from posted `journal_lines` (`TRUNCATE; INSERT … SELECT` in a scratch space) and asserts byte-identical balances and statements. | The rebuild reproduces identical account balances and trial balance; a deliberate seeded drift is detected and alerts; the job re-establishes tenant context (`SET LOCAL`) per company; exercised in CI as the integrity assertion. | 3 | S2-08 |

**Committed total: 47 points.** Financial statements (Balance Sheet / P&L / Cash Flow) are **not** in
Sprint 02 — they land in [SPRINT_04.md](./SPRINT_04.md) once banking and AI drafting have produced the
posted data that makes them meaningful. Approval-chain *mechanics* (the workflow engine) are stubbed:
Sprint 02 declares which entries require approval and enforces segregation of duties, but the full
maker-checker routing arrives with the AI approval gateway.

# Sequencing & capacity

The sprint has one unavoidable spine: the chart of accounts (Epic A) unblocks the journal schema (S2-03),
which unblocks the lifecycle actions (S2-04), which unblock the posting engine (S2-05), which unblocks
everything downstream — reverse/void, fiscal periods, the ledger, the trial balance, and every frontend
screen. That spine is walked in week 1 so that week 2 can build breadth on a finished engine.

| Week | Backend | Frontend | Platform / QA |
|---|---|---|---|
| Week 1 | S2-01 COA + actions, S2-03 journal schema + triggers, S2-04 draft lifecycle, S2-05 posting engine (start day 2, review day 3) | S2-10 COA screen (against COA API as it lands) | Migration CI on the growing schema, posting-invariant unit tests alongside S2-05 |
| Week 2 | S2-06 reverse/void, S2-07 fiscal periods, S2-08 GL reads, S2-09 trial balance, S2-13 idempotency + broadcast | S2-11 journal editor, S2-12 trial-balance screen | S2-14 nightly integrity job, immutability + isolation coverage, four-variant baselines |

The critical path is **S2-01 → S2-03 → S2-04 → S2-05 → S2-11**: the editor that produces the exit cannot
be finished until the engine it posts through exists. S2-05 is started on day 2 and design-reviewed on
day 3 precisely because it sits on the critical path and carries the most risk. S2-06 (reverse/void) is
the designated slip candidate — valuable but not required for the exit — if the engine overruns.

# Story spotlights

**S2-05 — PostingService (the single posting code path).** Everything the module promises lives in one
`DB::transaction`: `assertBalanced` re-derives the debit and credit totals from the lines themselves —
never trusting the client or the cached header totals — and compares them with `Money`/`NUMERIC(19,4)`
equality, not float tolerance; `lockOpenForDate` resolves and locks the fiscal period `FOR UPDATE` and
refuses a non-open one; each line's account is checked postable; then `markPosted` assigns the entry
number and totals and `projectLines` writes exactly one `ledger_entries` row per line with the correct
`signed_base_amount`. A balance failure throws *before* any projection row is written — there is no
partial post — and the whole transaction rolls back. This is the story whose unit test (an unbalanced
draft throws) is the single most important assertion in the codebase.

**S2-03 — Journal schema + immutability triggers.** The database is made an independent enforcer of the
rules the Actions also enforce: `trg_journal_lines_no_update_when_posted` rejects any `UPDATE` to a line
whose parent is terminal, `trg_no_ai_autopost` rejects inserting an `origin='ai_draft'` entry directly as
`posted` (a guard Sprint 04 relies on), the one-side and non-negative CHECKs reject malformed lines, and
`ledger_entries` is designed with no update path at all. The test that matters is the *raw-SQL* one: a
privileged role attempting a direct `UPDATE` on a posted line is still refused.

# Out of scope (deferred)

Explicitly *not* in Sprint 02, each a tracked item rather than a gap:

- **Financial statements (Balance Sheet, P&L, Cash Flow, SoCE)** — deferred to
  [SPRINT_04.md](./SPRINT_04.md), once banking and AI drafting have produced posted data worth
  presenting.
- **The workflow/approval engine mechanics** — Sprint 02 declares which entries require approval and
  enforces segregation of duties (creator is not sole approver), but full maker-checker routing arrives
  with the AI approval gateway in Sprint 04.
- **Multi-currency FX revaluation and consolidation** — lines carry currency and base-currency amounts,
  but period-end revaluation is a Banking/Treasury concern in [SPRINT_03.md](./SPRINT_03.md) and beyond.
- **Cross-module automatic postings** (invoice/bill/payroll → journal) — the posting engine is built to
  receive them, but the source modules that emit the events do not exist yet.
- **Cost centers / projects / departments reporting** — the dimensions are carried on every line now;
  slicing reports by them is post-MVP.

# Definition of Done

The [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md) release checklist,
tightened for the money-critical paths per
[../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) (95% line / 90% branch floor on
posting/tenancy paths):

- **The balance invariant is proven, not assumed.** `PostingService` has an isolated unit test that an
  unbalanced draft throws before any `ledger_entries` row is written, using `Money`/`NUMERIC(19,4)`
  equality, never float tolerance.
- **Immutability is proven at two layers.** A feature test that a `PATCH` to a posted entry returns
  `409`, and an RLS/trigger test that a raw `UPDATE` to a posted line is refused even by a privileged
  role.
- **Derivation is single-direction.** The nightly rebuild reproduces byte-identical balances; no report
  reads a stored total that could drift from the posted lines.
- **Migration + triggers.** Every schema change is a reversible, tested migration; the two guard
  triggers and the balanced CHECK are present and tested.
- **Permissions + isolation.** Every endpoint is default-deny with a tested 403 path; the two-company
  isolation suite passes (A's posted lines never in B's trial balance).
- **Idempotency.** Every money-moving post is idempotency-guarded with a tested replay-is-a-no-op.
- **Frontend.** Each new screen has ≥1 Playwright happy-path spec and ≥1 Vitest spec for its riskiest
  logic (the `deriveBalance` total); all four visual variants baselined; `i18n:check` green.
- **Audit + events.** Posting writes an audit row and dispatches `accounting.journal.posted` only after
  commit; a rolled-back post fires no event.
- **Reviewed + merged** behind all green blocking gates.

# Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A subtle balance bug (rounding, currency, cached header totals) lets an unbalanced entry post. | Medium | Critical | Re-derive totals from the lines at post time (never trust the client or cached header); `Money` equality only; day-3 design review of S2-05; the balanced CHECK + trigger as an independent DB backstop. |
| The immutability rule is enforced in the app but not the DB, so a raw query or future job mutates a posted line. | Medium | Critical | Ship `trg_journal_lines_no_update_when_posted` and the no-update `ledger_entries` design in S2-03; test the trigger with raw SQL under a privileged role. |
| `ledger_entries` drifts from `journal_lines` over time (double projection, partial post, retried event). | Medium | High | One projection code path inside the post transaction; the nightly rebuild-and-compare (S2-14) as a continuous integrity assertion; idempotency on posts. |
| Fiscal-period edge cases (posting on a boundary, timezone, period not yet open) produce wrong-period postings. | Medium | High | Resolve + lock the period `FOR UPDATE` in the engine; pin the clock in all period tests; block posts into non-open periods with a tested `422`. |
| Frontend "balanced" derivation diverges from the backend, letting a user believe an entry will post when it won't (or vice versa). | Medium | Medium | Share the exact debit/credit-difference semantics; a Vitest test that `deriveBalance` matches the backend's `balance_mismatch` difference to four decimals; the backend remains the sole authority. |
| Posting-engine complexity overruns the sprint. | Medium | Medium | S2-05 estimated at 8 and started early; reverse/void (S2-06) can slip to the following sprint without blocking the exit criteria. |

# Demo / exit criteria

The sprint is complete when this can be demonstrated live against the demo environment, with the
money-critical tests green in CI:

1. **Build a chart of accounts.** In the COA screen, create a few accounts (Cash, Rent Expense, Accounts
   Payable) with bilingual names; show the tree and the type/normal-balance classification.
2. **Draft a balanced entry in the browser.** In the journal-entry editor, add lines; watch the live
   debit/credit difference; show that Post stays disabled while unbalanced and enables when balanced.
3. **Post it through the real engine.** Post the balanced entry; show it become `posted` with an assigned
   entry number, and show the `ledger_entries` projection (one row per line) behind it.
4. **Prove the invariants.** Attempt to post an unbalanced entry → `422 balance_mismatch` with both
   totals; attempt to edit the posted entry → `409 immutable_record` pointing at `reverse`; reverse it
   and show the linked mirror entry.
5. **View it as a trial balance.** Open the trial-balance screen; show the TB tying out (debits =
   credits) and generate an approvable snapshot; show account activity with a running balance.
6. **Prove derivation integrity.** Run the nightly rebuild job on demo data and show it reproduces
   identical balances; show a seeded drift being detected.
7. **Prove isolation still holds.** Show that a second company's posted lines never appear in the first
   company's trial balance or ledger activity.

Meeting these criteria satisfies the sprint goal — **post and view a balanced journal entry end to
end** — and gives [SPRINT_03.md](./SPRINT_03.md) a real ledger for banking to post its cash movements
into.

# Success metrics

Signals reviewed at the retro, weighted toward the money-critical paths:

| Metric | Target |
|---|---|
| Committed points delivered | ≥ 42 of 47 (≥ 89%); any carry-over is S2-06 reverse/void, never the engine |
| Posting/tenancy path coverage | ≥ 95% line / ≥ 90% branch per the testing-strategy floor |
| Balance invariant | Unit-proven: an unbalanced draft throws before any projection row; zero float tolerance |
| Immutability | Proven at two layers (feature `409` + raw-SQL trigger rejection under a privileged role) |
| Ledger integrity | Nightly rebuild reproduces byte-identical balances; a seeded drift is detected |
| Idempotency | Replay-is-a-no-op proven; no double-post under retried posts/events |
| Frontend derivation parity | `deriveBalance` matches the backend `balance_mismatch` difference to four decimals |
| Exit demo | Product accepts an end-to-end post + trial balance with no manual DB intervention |

A red mark on the balance invariant, immutability, or integrity metrics is release-blocking regardless of
velocity: these are the paths where a regression is a wrong number in a real ledger.

# Related Documents

- [SPRINT_01.md](./SPRINT_01.md) — the isolated, authenticated tenant this sprint builds on.
- [SPRINT_03.md](./SPRINT_03.md) — Banking + reconciliation, which posts cash movements through this engine.
- [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md) — the double-entry core this sprint implements.
- [../accounting/CHART_OF_ACCOUNTS.md](../accounting/CHART_OF_ACCOUNTS.md), [../accounting/JOURNAL_ENTRIES.md](../accounting/JOURNAL_ENTRIES.md), [../accounting/GENERAL_LEDGER.md](../accounting/GENERAL_LEDGER.md), [../accounting/TRIAL_BALANCE.md](../accounting/TRIAL_BALANCE.md) — the domain depth.
- [../frontend/screens/ACCOUNTING_SCREEN.md](../frontend/screens/ACCOUNTING_SCREEN.md) — the accounting UI contract.
- [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) — the posting-invariant and coverage bar.

# End of Document
