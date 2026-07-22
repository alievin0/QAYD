# Sprint 03 — Banking & Reconciliation (First AI Touchpoint) — QAYD Execution
Version: 1.0
Status: Planning
Module: Execution
Submodule: Sprint 03
---

# Purpose / Goal

Sprint 03 gives QAYD its cash side and lights up the first AI touchpoint in the product. It builds the
Banking & Treasury module of [../backend/BANKING_SERVICE.md](../backend/BANKING_SERVICE.md) on top of
the immutable ledger from [SPRINT_02.md](./SPRINT_02.md): bank accounts, the bank-transaction state
machine, CSV statement import with a hard tie-out gate, and the deterministic reconciliation engine and
its workbench. It then adds the platform's first real use of the FastAPI AI engine —
**AI-suggested reconciliation matches**, each carrying a confidence score and accepted only by a human —
wired through the exact "AI proposes, human disposes" boundary the whole product is designed around.

Two invariants from the banking spec are enforced structurally, not by convention, and are the spine of
the sprint: **a bank transaction cannot reach `cleared`/`reconciled` without a posted, balanced journal
entry** (the DB `CHECK` plus the `ClearBankTransactionAction` that posts through the Accounting Service,
never writing `journal_lines` itself), and **the AI's similarity signal can never, alone, cross the
auto-commit threshold** — it is added only after a deterministic rule has already fired, and the AI
principal is barred from every money-moving permission.

The exit is a full month-in-miniature: **import a statement, reconcile it with AI suggestions, and close
the period** — a CSV import that ties out, a mix of deterministic auto-matches and AI-suggested matches a
human accepts in the workbench, a computed variance, and a period closed at zero. This is the sprint
where an accountant first watches the AI help — visibly, labeled, and never silently committing.

# Sprint duration & team

- **Duration:** 2 weeks (10 working days). Sprint 03 of 04.
- **Ceremonies:** planning (day 1), daily stand-up, AI-boundary design review (day 4, the
  propose-only/AI-cap contract), mid-sprint review (day 5), demo + retro (day 10).
- **Team (the AI engineer ramps to full load this sprint):**

| Role | Count | Primary focus this sprint |
|---|---|---|
| Tech Lead / Eng Manager | 1 | Clear-and-post atomicity, AI-cap rule, two-key stub review |
| Backend engineers (Laravel) | 2 | Bank accounts, transactions, statement import, reconciliation engine |
| Frontend engineers (Next.js) | 2 | Statement import UI, reconciliation workbench, accounts/txn lists |
| AI engineer (FastAPI) | 1 | AI-engine skeleton, transport/mTLS, Banking Agent match suggestions |
| Platform / DevOps | 1 (shared) | `integrations`/`ai` queues, mTLS between Laravel and FastAPI |
| QA / SDET | 1 | Reconciliation scoring tests, AI-cap tests, isolation on cash tables |
| Product + Designer | 1 (part-time) | Workbench UX, confidence presentation, bilingual banking copy |

- **Target velocity:** ~48 story points. Committed: 47 pts. The first AI integration carries setup cost
  (transport, mTLS, contract fixtures) that is front-loaded and shared with Sprint 04.

# Objectives

1. **Accounts money can sit in.** `bank_accounts` (with the mandatory `gl_account_id` control-account
   link), the verification gate (`pending_verification → active`), and `bank_account_users`, resolved
   per `institution_type` by a strategy object rather than schema branching.
2. **A transaction ledger that never clears without a journal.** The `bank_transactions` state machine
   `draft → … → cleared → reconciled`, with `ClearBankTransactionAction` posting a balanced journal
   through the Accounting Service atomically and updating the running balance under a row lock.
3. **Statement ingestion that refuses to lie.** `StatementImportService` normalizing CSV into immutable
   `bank_statement_lines` batched under a `statement_import_id`, blocked entirely when
   `opening + Σ lines ≠ closing`.
4. **A deterministic reconciliation engine.** Rule-based scoring (exact reference, amount+date,
   counterparty fuzzy, recurring), auto-commit only above threshold **and** anchored by a deterministic
   rule, `bank_reconciliation_matches` recording exactly which rules fired, variance computation, and a
   `bank.reconcile.close` gate.
5. **The first AI touchpoint, correctly bounded.** A FastAPI AI-engine skeleton reachable only through
   Laravel over mTLS; the Banking Agent producing **propose-only** match suggestions with confidence,
   surfaced in the workbench for a human to accept — with the structural AI-cap enforced in
   `ReconciliationEngine::score()`.
6. **The banking UI.** Statement import (upload, column mapping, tie-out feedback), the reconciliation
   workbench (unmatched lists, AI suggestions with confidence, accept/reject, manual match), and the
   accounts/transactions lists.

# Backlog

Story points on the Fibonacci scale; every amount is `NUMERIC(19,4)`, every FX row stores `base_amount`;
encrypted fields (`iban`, `account_number`, `swift_bic`) never appear in responses or logs. Every story
ends in a demoable, tested increment.

## Epic A — Bank accounts

A bank account is the aggregate root for a place money can sit, and its two non-negotiables are set here:
a mandatory `gl_account_id` linking it to its Chart-of-Accounts control account (so cash always has a
ledger home), and a verification gate that keeps a brand-new account from being a payment destination
until its IBAN is proven — the structural defense against the "add attacker-controlled account, pay it"
fraud pattern. Institution-specific behavior (IBAN/SWIFT rules, Islamic profit-distribution vs interest)
is resolved by a strategy object per `institution_type`, never by branching the schema.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S3-01 | Bank accounts + verification + strategies | Banking | Migrate `bank_accounts` (running balances, `gl_account_id` FK, IBAN/SWIFT/currency CHECKs), `bank_account_users`; `OnboardBankAccountAction` (creates `pending_verification`, wires `gl_account_id`), `VerifyBankAccountAction`; the per-`institution_type` strategy objects (commercial/Islamic/wallet/cash/petty-cash). | An account is created `pending_verification` and cannot be a payment destination until verified; `gl_account_id` links to a real COA control account; IBAN mod-97 and currency regex CHECKs hold; currency is immutable after the first transaction; RLS-scoped; feature-tested. | 8 | S2-01 |

## Epic B — Bank transactions & clearing

The single most important guard in the Banking module is enforced here: a bank transaction cannot reach
`cleared` or `reconciled` without a posted, balanced journal entry behind it. The DB `CHECK` is the
backstop; `ClearBankTransactionAction` is the guarantee — it posts through the Accounting Service's
posting engine (never writing `journal_lines` itself), stores the returned `journal_entry_id`, and
updates the running balance under a row lock, all in one transaction, so there is never a window where a
bank balance moved without a balanced journal behind it. Banking owns the cash leg; Accounting owns the
ledger.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S3-02 | Transaction state machine + create/submit | Banking | Migrate `bank_transactions` (`amount>0`, `base_amount>0`, the `cleared/reconciled ⇒ journal_entry_id NOT NULL` CHECK, `reversed_transaction_id`) and `bank_transaction_status_history`; `CreateBankTransactionAction` (draft), `SubmitBankTransactionAction`; financial fields immutable once past `draft`. | An illegal transition throws `409 invalid_state_transition`; the DB CHECK blocks a `cleared` row with no `journal_entry_id`; editing a non-draft's financial fields is refused; the status history appends; feature-tested. | 5 | S3-01 |
| S3-03 | Clear-and-post (atomic) | Banking | `ClearBankTransactionAction`: inside one `DB::transaction`, lock the row, build a `JournalDraft`, post it through the Accounting Service's posting engine, store the returned `journal_entry_id`, set `cleared`, and apply the running balance via `BalanceService` under `SELECT … FOR UPDATE`; emit `bank.transaction.cleared` after commit. | Clearing posts exactly one balanced journal and stores its id; an unbalanced/failed post rolls the whole thing back with the row unchanged (no balance moved without a journal); concurrent clears of one row serialize under the lock and produce one balance delta; idempotent on `bank_txn:{id}:cleared`. | 8 | S3-02, S2-05 |

## Epic C — Statement import

Imported bank statement lines are immutable facts — the bank's reality, never rewritten to force
agreement. The discipline of this epic is the tie-out gate: before a single line is committed,
`opening_balance + Σ lines = closing_balance` must hold, and a mismatch rejects the *entire* import with
zero lines written. A mis-parsed or partial statement never silently pollutes the ledger; a bad import
is voided and re-run, not edited. Sprint 03 ships the CSV channel (with per-bank saved column mappings);
the structured-file, OCR, and Open Banking channels are deferred.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S3-04 | CSV statement import + tie-out gate | Banking | `StatementImportService::import` for CSV (per-bank saved column mapping) into immutable `bank_statement_lines` batched by `statement_import_id`; the hard arithmetic tie-out `opening + Σ lines = closing` run before a single line is committed; `POST /banking/statement-imports`, `GET .../{id}`. | A tied-out CSV imports all lines as immutable facts; a statement whose totals don't tie raises `422 statement_tie_out_failed` and writes **no** lines; imported lines are never edited (a bad import is voided and re-run); `bank.reconcile` gated; feature-tested. | 5 | S3-01 |

## Epic D — Reconciliation engine & workbench

The reconciliation engine proves that the company's transactions and the bank's statement describe the
same reality. It is *deterministic first*: ordered rules accumulate a 0–100 score, and a match
auto-commits only when the composite score clears the company threshold **and** a deterministic rule
contributed at least 30. This is the structural home of the AI cap that Epic E depends on — the AI
similarity signal can only ever add to a match a deterministic rule has already anchored, so AI alone can
never auto-commit. Every committed match records exactly which rules fired, for audit.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S3-05 | Deterministic reconciliation engine | Banking | `ReconciliationEngine`: ordered rule scoring (exact reference 45, amount+date 30, counterparty fuzzy 15, recurring 10), auto-commit only when score ≥ `auto_commit_threshold` (default 90) **and** a deterministic rule contributed ≥ 30; `bank_reconciliation_matches` recording `rules_fired`/`final_score`/`match_method`; candidate rows selected `FOR UPDATE`. | Exact-reference scores 45; amount+date scores 30; a 6-day-late amount match stays below threshold and lands in the workbench; a ≥90 score with a ≥30 deterministic contribution auto-commits and writes a match row with the right `rules_fired`; two workers cannot double-commit the same pair. | 8 | S3-04, S3-03 |
| S3-06 | Variance + period close | Banking | `variance = book_balance − adjusted_bank_balance` computation; the `in_progress → discrepancy\|balanced → closed` lifecycle; `CloseReconciliationAction` (`bank.reconcile.close`) locking the period's lines/transactions; `ReopenReconciliationAction` (reason required). | Exactly-zero variance closes as `balanced`; any non-zero holds `discrepancy` until resolved or an approved adjustment posts; close locks the period (re-matching a closed period returns `409 reconciliation_closed`); reopen requires the permission + audited reason. | 5 | S3-05 |

## Epic E — First AI touchpoint

This epic lights up the FastAPI engine for the first time, and it does so at the safest possible entry
point: reconciliation *suggestions*, which are inherently reversible and human-accepted. The
architecture set here is reused by every later AI feature — the engine reachable only through Laravel
over mTLS, holding no tenant database credential, proposing through a governed endpoint rather than
writing directly. The Banking Agent runs under a scoped service account holding `bank.read` and
suggest-only, structurally barred from every money-moving permission. Get this boundary right once and
Sprint 04's drafting agent inherits it.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S3-07 | AI-engine skeleton + transport | AI | Stand up the FastAPI engine with `/internal/readyz`, `/internal/events`, `/internal/invoke`; the Laravel `AiEngineClient` interface + `HttpAiEngineClient` (internal bearer + mTLS verify-full); the `events-ai` queued relay listener (`afterCommit`). No model writes to any DB — the engine has no tenant DB credential. | Laravel reaches FastAPI over mTLS with the internal token (`hmac.compare_digest`); a domain event relays only after commit; `/internal/readyz` reports `not_ready` when egress is down; the engine holds no tenant DB connection string (verified); contract fixtures shared both sides. | 8 | S1-16 |
| S3-08 | Banking Agent match suggestions (propose-only) | AI | The Banking Agent scoring candidate matches and returning suggestions with confidence, consumed by `ReconciliationEngine` as the capped AI-similarity signal; the AI runs under a scoped service account holding only `bank.read` + suggest-only, never a money-moving permission. | AI similarity is added only after a deterministic rule fired and is capped (AI alone never auto-commits); suggestions carry a confidence the workbench shows; the AI principal type is refused (`403 ai_forbidden`) on `/transfers`, `/approve`, `/approve-final`; suggestions are tenant-scoped to the calling company. | 5 | S3-07, S3-05 |
| S3-09 | Accept/reject AI suggestions (workbench) | Frontend | The workbench surface for AI-suggested matches: each shows the candidate pair, the confidence, and the rules/context; a human Accept commits the match (`ReconciliationEngine::commitManualMatch`, `match_method='ai_suggested_accepted'`), Reject dismisses it. | A suggested match is visibly labeled AI with its confidence; Accept commits and records `ai_suggested_accepted`; Reject dismisses without committing; nothing the AI suggests posts without the human click; light/dark + RTL; four-variant baseline. | 5 | S3-08 |

## Epic F — Banking frontend

The banking UI makes the cash side usable and the AI visible. The workbench is the centerpiece: unmatched
items on both sides, a clear separation between deterministically auto-matched pairs and AI-*suggested*
pairs, and confidence surfaced on every suggestion so a human always knows what the machine is and is not
sure about. Encrypted fields (IBAN, account numbers) are masked everywhere; no screen ever renders a raw
banking secret.

| ID | Title | Module | Description | Acceptance criteria | Est | Deps |
|---|---|---|---|---|---|---|
| S3-10 | Statement import UI | Frontend | The CSV upload + column-mapping screen with tie-out feedback and per-bank saved mappings. | Uploading a CSV lets the user map columns, saves the mapping per bank, and shows a clear pass/fail tie-out result; a failed tie-out explains the imbalance and writes nothing; loading/empty/error states per the design system. | 3 | S3-04, S2-10 |
| S3-11 | Bank accounts & transactions lists | Frontend | The accounts list (balances, verification status) and the transactions list (state, journal link, filters); encrypted fields masked. | Accounts render with running balance and status; a transaction shows its cleared journal link; IBAN/account numbers are masked; cross-tenant ids 404; bilingual + themable. | 3 | S3-02, S2-10 |
| S3-12 | Reconciliation workbench shell | Frontend | The workbench: unmatched statement lines and unmatched transactions side by side, auto-matched summary, manual-match action, and the variance + close-period controls. | The workbench lists unmatched items on both sides; a manual match commits via the API; the running variance and the close/reopen controls reflect the backend lifecycle; a closed period disables re-matching; four-variant baseline. | 5 | S3-06, S3-09 |

**Committed total: 47 points.** MT940/CAMT.053/XLSX/PDF-OCR import channels, Open Banking feeds, the
full two-key payment/transfer *dispatch*, standing orders, and FX revaluation are **out of scope** for
Sprint 03 — CSV import is the one channel required to hit the exit, and the two-key chain is stubbed at
the state machine (created + segregation-of-duties enforced, dispatch deferred). The AI's role this
sprint is strictly reconciliation *suggestions*; AI-drafted journal entries from documents arrive in
[SPRINT_04.md](./SPRINT_04.md).

# Sequencing & capacity

Two tracks run in parallel and must converge in the workbench. The **deterministic track** (accounts →
transactions → clear-and-post → statement import → reconciliation engine) is the sprint's backbone and is
built so that reconciliation works fully even if the AI track slips. The **AI track** (engine skeleton →
transport/mTLS → Banking Agent suggestions) is front-loaded because its setup cost (mTLS, contract
fixtures) is the sprint's biggest unknown, and it is deliberately additive: it enriches the deterministic
workbench rather than being load-bearing for the exit.

| Week | Backend (deterministic) | AI track | Frontend / QA |
|---|---|---|---|
| Week 1 | S3-01 accounts + verify, S3-02 txn state machine, S3-03 clear-and-post | S3-07 engine skeleton + mTLS transport (start day 1) | S3-11 accounts/txn lists, isolation + clear-atomicity tests |
| Week 2 | S3-04 statement import + tie-out, S3-05 reconciliation engine, S3-06 variance + close | S3-08 Banking Agent suggestions (capped), S3-09 accept/reject UI | S3-10 import UI, S3-12 workbench shell, reconciliation-scoring + AI-cap tests, four-variant baselines |

The critical path is **S3-01 → S3-02 → S3-03 → S3-05 → S3-06 (+ S3-12)**: closing a period requires
reconciliation, which requires import and clearing, which require accounts. The AI stories (S3-08, S3-09)
hang off S3-07 and S3-05; if the transport (S3-07) overruns, the exit degrades gracefully to
deterministic-only reconciliation and the AI touchpoint moves to an early-Sprint-04 carryover.

# Story spotlights

**S3-03 — Clear-and-post (atomic).** The whole "cash never moves without a balanced journal" promise
lives in one `DB::transaction`: lock the transaction row, build a `JournalDraft`, call the Accounting
Service's posting engine (which asserts `debit = credit` and throws upstream on a mismatch), store the
returned `journal_entry_id`, set `cleared`, and apply the running balance under `SELECT … FOR UPDATE` —
then emit `bank.transaction.cleared` after commit. The tests that matter are the failure and concurrency
ones: if the poster throws, the whole thing rolls back with the row unchanged; two concurrent clears of
the same row serialize under the lock and produce exactly one balance delta; a retried clear on the same
idempotency key posts no second journal.

**S3-05 — Deterministic reconciliation engine (and the AI cap).** The engine evaluates rules in order —
exact reference (45), amount+date (30), counterparty fuzzy (15), recurring (10) — and only considers a
match auto-committable when the composite score is ≥ the threshold (default 90) **and** a deterministic
rule contributed ≥ 30. The AI-similarity contribution (up to +10) is added *only after* a deterministic
rule has fired, and `ReconciliationEngine::score()` enforces this ordering structurally. The signature
test asserts that an AI-only signal, at any confidence, stays below the auto-commit line — this is where
"AI proposes, humans authorize" is enforced in code, not documentation.

# Out of scope (deferred)

Explicitly *not* in Sprint 03, each a tracked item rather than a gap:

- **Non-CSV import channels** — MT940, CAMT.053 XML, XLSX, and PDF-OCR statement ingestion, plus the
  Open Banking feed (`SyncOpenBankingJob`), are deferred; CSV with saved column mappings is the one
  channel required for the exit.
- **The two-key payment/transfer *dispatch*** — `ApprovalService`'s Finance-Manager → CEO chain,
  `PaymentDispatchService`, and sending payments to rails are deferred; Sprint 03 builds the transaction
  state machine and enforces segregation of duties, but does not move money outward.
- **Standing orders and recurring-payment materialization** — the recurrence engine
  (`MaterializeStandingOrdersJob`) is post-MVP.
- **FX revaluation and the 13-week cash-flow forecast** — multi-currency `base_amount` is captured per
  row, but period-end revaluation and forecasting are deferred.
- **AI-drafted journal entries from documents** — this sprint's AI role is strictly reconciliation
  *suggestions*; document drafting is the headline of [SPRINT_04.md](./SPRINT_04.md).

# Definition of Done

The [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md) checklist plus the
AI-boundary bar from [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md):

- **The clear-needs-journal invariant is proven at two layers.** A unit test that `ClearBankTransaction
  Action` posts exactly one balanced draft and rolls back entirely if the poster throws, and a DB test
  that the `CHECK` blocks a `cleared` row with no `journal_entry_id`.
- **The tie-out gate is proven.** A feature test that a non-tying import is rejected `422` and writes
  zero lines.
- **The AI cap is proven structurally.** A reconciliation-engine unit test that AI-only never
  auto-commits, and a feature test that the AI principal type gets `403` on `/transfers`, `/approve`,
  and `/approve-final` even when a permission row is present.
- **Human-in-the-loop is real.** No AI-suggested match reaches `reconciled` without a recorded human
  Accept (`match_method='ai_suggested_accepted'`).
- **Concurrency is safe.** Matching and clearing select rows `FOR UPDATE`; a test proves two workers
  cannot double-commit a pair or double-apply a balance delta.
- **Isolation holds on the cash tables.** The two-company suite passes; a cross-tenant bank account/txn
  id returns 404; encrypted fields are redacted from logs and exception context.
- **Frontend.** Each screen has ≥1 Playwright happy-path spec and ≥1 Vitest spec; the AI-suggestion
  presentation labels confidence; all four visual variants baselined; `i18n:check` green.
- **AI transport.** The engine is reachable only through Laravel over mTLS and holds no tenant DB
  credential; the shared proposal/suggestion contract fixture is tested on both sides.
- **Reviewed + merged** behind all green blocking gates.

# Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A clear updates the bank balance but the journal post fails (or vice versa), leaving cash and ledger out of step. | Medium | Critical | Do both inside one `DB::transaction` with the row locked `FOR UPDATE`; roll back atomically; the DB `CHECK` as an independent backstop; unit-test the rollback path. |
| The AI similarity signal is allowed to auto-commit a match on its own, breaking the human-in-the-loop guarantee. | Medium | Critical | Enforce the cap in `ReconciliationEngine::score()` (AI added only after a deterministic rule ≥30 fired); day-4 design review; an explicit unit test that AI-only stays below threshold. |
| The AI engine gains a path to write tenant data directly (DB credential, a bypass endpoint). | Low | Critical | The engine holds no tenant DB connection string; its only write path is a governed Laravel endpoint; verify in S3-07 and assert it in a test; mTLS + internal token on the transport. |
| mTLS / internal-transport setup between Laravel and FastAPI overruns and blocks the AI stories. | High | Medium | Front-load S3-07 in week 1; ship the deterministic engine (S3-05/06) independently so reconciliation works even if AI suggestions slip; fall back to deterministic-only for the exit if needed. |
| CSV parsing brittleness (encodings, separators, bank-specific formats) makes import flaky. | Medium | Medium | Per-bank saved column mappings; the tie-out gate rejects a mis-parse rather than importing garbage; a golden-file fixture set of real-shaped statements. |
| Reconciliation scoring thresholds are miscalibrated (too many false auto-commits or too few). | Medium | Medium | Thresholds are company config with safe defaults (90 / ≥30 deterministic); unit-test the scoring table; auto-commits always record `rules_fired` for auditability. |

# Demo / exit criteria

The sprint is complete when this can be demonstrated live against the demo environment, with the
invariant tests green in CI:

1. **Onboard and verify a bank account.** Create a bank account linked to its GL control account; show it
   starts `pending_verification` and cannot be a payment destination until verified.
2. **Import a statement.** Upload a CSV, map its columns, and show the tie-out passing; then upload a
   deliberately non-tying CSV and show it rejected (`422`) with zero lines written.
3. **Auto-reconcile deterministically.** Show the engine auto-matching lines by exact reference and
   amount+date, each recording which rules fired, and clearing the matched transactions (each posting one
   balanced journal into the Sprint 02 ledger).
4. **Reconcile with AI help.** For the leftover lines, show AI-suggested matches with their confidence in
   the workbench; accept one and reject another; show that the accepted one commits as
   `ai_suggested_accepted` and the rejected one commits nothing.
5. **Prove the AI cannot act alone.** Show an AI-only signal failing to auto-commit, and the AI principal
   being refused (`403 ai_forbidden`) on a transfer/approve route.
6. **Close the period.** Show the computed variance reaching zero and the reconciliation closing as
   `balanced`; show that a closed period refuses re-matching.
7. **Prove isolation.** Show a second company's bank accounts and statement lines never appearing in the
   first company's workbench.

Meeting these criteria satisfies the sprint goal — **import a statement, reconcile with AI suggestions,
and close a period** — and gives [SPRINT_04.md](./SPRINT_04.md) both a working AI transport and posted
cash data for financial statements.

# Success metrics

Signals reviewed at the retro, weighted toward the cash-integrity and AI-boundary invariants:

| Metric | Target |
|---|---|
| Committed points delivered | ≥ 42 of 47 (≥ 89%); any carry-over is an AI story (S3-08/09), never clearing or reconciliation |
| Clear-needs-journal invariant | Proven at two layers (unit rollback + DB `CHECK`); no cleared row without a posted journal |
| Statement tie-out gate | A non-tying import is rejected `422` and writes zero lines |
| AI cap | Unit-proven: an AI-only signal never auto-commits; AI principal `403` on money-moving routes |
| Human-in-the-loop | No AI-suggested match reaches `reconciled` without a recorded human Accept |
| Concurrency | Two workers cannot double-commit a match pair or double-apply a balance delta |
| AI transport isolation | Engine reachable only via Laravel over mTLS; holds no tenant DB credential |
| Exit demo | Product accepts import → AI-assisted reconcile → zero-variance close, unassisted |

A red mark on the clear-needs-journal invariant or the AI cap is release-blocking regardless of velocity:
both are core platform promises, not features.

# Related Documents

- [SPRINT_02.md](./SPRINT_02.md) — the ledger and posting engine banking clears through.
- [SPRINT_04.md](./SPRINT_04.md) — AI drafting + reporting, which reuses this sprint's AI transport.
- [../backend/BANKING_SERVICE.md](../backend/BANKING_SERVICE.md) — the Banking & Treasury service this sprint implements.
- [../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md) — the posting engine clearing depends on.
- [../backend/AI_SERVICE.md](../backend/AI_SERVICE.md), [../ai/agents/BANKING_AGENT.md](../ai/agents/BANKING_AGENT.md), [../ai/workflows/BANK_RECONCILIATION.md](../ai/workflows/BANK_RECONCILIATION.md) — the AI boundary and the Banking Agent.
- [../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md) — the AI-boundary and reconciliation test bar.

# End of Document
