# 09 — The QAYD Engineering Playbook

Version: 1.0 · Date: 2026-07-28 · Status: **Mandatory reading**
Audience: every engineer who writes code in this repository, on day one and thereafter.

---

## How to use this document

Read it start to finish once — it takes about an hour. After that it is a reference: §13 is the
pre-PR checklist, §14 is the mistake catalogue you will actually come back to.

This document tells you **how to work**. Its siblings in `docs/architecture/knowledge/` tell you
what the rules are and why they were chosen. They are cited throughout by their numeric prefix,
which is the stable part of each filename:

| # | Document | What it holds |
|---|---|---|
| **01** | `01_ENGINEERING_PRINCIPLES.md` | The 21 numbered principles (P1–P21) and the mechanism enforcing each. The constitution. |
| **02** | `02_…` (architectural decisions) | Why each structural choice was made, and what was traded away. |
| **03** | `03_…` (patterns) | The reusable shapes: Action, DTO, seam, projection, guard. |
| **04** | `04_…` (rejected patterns) | What we deliberately do **not** do, and the failure each avoids. Read before proposing anything clever. |
| **05** | `05_…` (future architecture) | Where the system is going. Read before designing anything you expect to last. |
| **06** | `06_…` (competitive analysis) | What comparable systems do, and where their designs failed. |
| **07** | `07_…` (innovation) | The bets that differentiate QAYD. |

When this playbook and 01 disagree, **01 wins** — it is the constitution, this is the field manual.
When 01 and the code disagree, follow MANIFEST Law 1: find out whether the doc is stale or the code
drifted, and fix the one that is wrong. Never silently bend code to an outdated document.

**A note on honesty.** This document distinguishes three states, and marks them explicitly:

- **Enforced** — a machine fails your build if you break it.
- **Convention** — everyone follows it, review catches violations, nothing automated does.
- **Aspirational** — the intended end state; not true today.

Do not read a convention as a guarantee. Where something is aspirational, it says so.

---

# 1. Start here

## 1.1 What QAYD is

QAYD is an **AI Financial Operating System** for companies in Kuwait and the GCC: a double-entry
accounting core with an AI workforce on top of it. Real companies will keep their statutory books
here. The numbers this system produces get filed with tax authorities and shown to auditors.

That single fact sets the engineering bar. In most software a bug is an annoyance you fix forward.
Here a bug can be a **wrong number in a filed return**, and "we fixed it in the next release" is not
a remedy, because the wrong number was already relied upon.

## 1.2 What QAYD is not

From `MANIFEST.md`, and worth taking literally:

> It is **not** a set of pages. It is **not** CRUD. It is **not** a traditional ERP.

Concretely:

- **Not CRUD.** There is no generic "update the journal entry" operation. There is *create a draft*,
  *edit a draft*, *submit for approval*, *post*, *reverse*. Each is a named Action with its own rules.
  A posted entry has no update path at all.
- **Not a page factory.** Success is not "the screen renders". Success is "the user can do something
  today they could not do yesterday, the system is stable, and the architecture is intact."
- **Not an AI that does your accounting.** The AI drafts; a human disposes; the database enforces
  that ordering. An AI with a write path to the ledger is the single failure mode this architecture
  exists to prevent.
- **Not built ahead of itself.** MANIFEST Law 2 — *do not build the future*. You implement the
  current story's scope and nothing beyond it, however certain the future need feels.

## 1.3 The architecture, in one paragraph

A **Next.js 15 / React 19** web app (`apps/web`) holds no database credential and talks only to the
versioned `/api/v1` of a **Laravel 12 / PHP 8.4** backend (`apps/api`), which is *the* domain layer
and the only thing that writes business data. A **FastAPI** service (`apps/ai`) hosts the AI
orchestrator, is reachable only from Laravel, and holds no tenant database credential — it proposes,
it never writes. Everything persists in one **PostgreSQL** database shared by all tenants, isolated
by **Row-Level Security** keyed on `company_id`, with the application connecting as a
`NOSUPERUSER NOBYPASSRLS` role so the isolation is a property of the storage engine rather than of
programmer diligence. Redis carries cache and queues; Reverb pushes one-way realtime refresh
signals; everything runs in Docker.

```
┌──────────────┐   /api/v1 (typed SDK)   ┌────────────────────────────────┐
│  apps/web    │ ──────────────────────► │  apps/api  (Laravel 12)        │
│  Next.js 15  │ ◄────────────────────── │  THE domain layer              │
│  no DB creds │      JSON envelope      │  Controllers → Actions →       │
└──────────────┘                         │  Services → Models             │
                                         └───────┬──────────────┬─────────┘
                                                 │              │ HTTP/queue
                                 pgsql_app role  │              │ (mTLS + token)
                            (NOSUPERUSER,        │              ▼
                             NOBYPASSRLS)        │      ┌────────────────┐
                                                 ▼      │  apps/ai       │
                                     ┌──────────────────┐  FastAPI       │
                                     │   PostgreSQL     │  proposes only │
                                     │   RLS on         │  NO DB creds   │
                                     │   company_id     └────────────────┘
                                     │   ledger append- │
                                     │   only, triggers │
                                     └──────────────────┘
```

Dependency direction is one-way and non-negotiable: `web → packages/* → /api/v1`; `api → ai`;
`packages/*` never depend on `apps/*`; `ai` never reaches Postgres.

## 1.4 The five things to internalise before you write any code

**1. The database enforces the rules, not your code.**
Every invariant that matters is a `CHECK`, a foreign key, a `UNIQUE`, an `EXCLUDE`, or a trigger —
*in addition to* the application check that produces a friendly error. Application code is a fast
path to a good message; PostgreSQL is the thing that is actually true. Read `chk_je_balanced` in
`apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php`: the cached header
totals **cannot** be unequal, whatever any PHP bug does. (01 → P1.)

**2. Money is a string.**
`NUMERIC(19,4)` in Postgres, `numeric-string` in PHPStan, `bcadd`/`bcsub`/`bcmul`/`bccomp` at scale
4 in PHP. Never a float, never `+`, never `==`. A float ledger accumulates drift that shows up as a
trial balance that is off by fils and cannot be explained. (01 → P4.)

**3. Posted data is immutable and there is exactly one way in.**
`JournalEntryPostingService` is the only code path authorised to write a posted line, and
`ledger_entries` rejects `UPDATE` and `DELETE` at the trigger level — even for the schema owner. A
correction is a *new reversing entry*, never an edit. (01 → P5, P6, P7.)

**4. Tenancy is a property of the database.**
`company_id NOT NULL`, RLS `ENABLE` + `FORCE`, a restrictive company-boundary policy, and a runtime
role that cannot bypass it. If your query somehow escapes the Eloquent scope, RLS still returns zero
rows. With no tenant context set, you see **nothing** — fail-closed, never fail-open. (01 → P3.)

**5. Nothing is silently corrected.**
No auto-balancing plug line. No defaulting a missing exchange rate to 1.0. No nudging a posting date
forward because the period is locked. Every one of those is a real bug pattern in shipped accounting
software, and every one of them silently changes a financial fact. QAYD raises a typed exception with
a catalog code and lets the caller decide. (01 → P14.)

## 1.5 Your first week

Work through this in order. It is designed so each step proves the previous one.

**Day 1 — Read and run.**
- [ ] `MANIFEST.md` (vision, the six laws), `PROJECT_STATUS.md` (where we actually are),
      `docs/architecture/FINAL_TECH_STACK.md` (the locked stack — it wins any conflict).
- [ ] `01_ENGINEERING_PRINCIPLES.md` — at minimum the index and P1–P10.
- [ ] `make install && make up && make migrate && make seed` from the repo root.
- [ ] `make test` — all three gate suites green before you touch anything. If they are not green on
      a clean checkout, that is your first bug, and it belongs to everyone.

**Day 2 — Read the ledger end to end.** In this order, they tell one story:
- [ ] `apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php` — the header,
      its CHECKs, the `trg_no_ai_autopost` trigger, the RLS block.
- [ ] `…_000005_create_journal_lines_table.php` — `chk_jl_one_sided`, the posted-immutability trigger.
- [ ] `…_000007_create_ledger_entries_table.php` — the append-only projection.
- [ ] `apps/api/app/Services/Accounting/JournalEntryPostingService.php` — the five ordered invariants.
      Read its class docblock twice; it is the best single explanation of how this system thinks.
- [ ] `apps/api/tests/Feature/Accounting/PostingEngineTest.php` — what "proven" looks like here.

**Day 3 — Read the tenancy path.**
- [ ] `app/Http/Middleware/ResolveTenantCompany.php` → `app/Support/TenantContext.php` →
      `app/Models/Concerns/BelongsToCompany.php` → `app/Scopes/CompanyScope.php`.
- [ ] `database/migrations/2026_07_27_000008_create_app_database_role.php` and `…_000009_enable_row_level_security.php`.
- [ ] `tests/Support/TenantHarness.php` and `tests/Feature/Rls/` — especially
      `BelongsToCompanyArchTest.php`, which is what "a convention becomes a mechanism" looks like.

**Day 4 — Read one vertical slice.**
- [ ] `AccountController` → `CreateAccountAction` → `CreateAccountData` → `Account` model. Notice how
      little the controller and the model do.
- [ ] `app/Exceptions/DomainException.php` and one subclass with named factories
      (`Accounting/PostingRuleException.php`). Notice that every failure has a stable code and status.

**Day 5 — Ship something small.**
- [ ] Take the smallest open item you can find. Write the test first. Run
      `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse && ./vendor/bin/pest` before pushing.
- [ ] Open the PR against §13's checklist yourself before asking anyone to look at it.

**Questions worth asking out loud in week one** (nobody will think less of you):
*Why is this a trigger and not a validation rule? What happens if two requests do this at once? What
does an auditor see if this is wrong? Where is this invariant enforced when my code is not running?*

---

# 2. Accounting for engineers

You cannot evaluate whether your code is correct without this. It is smaller than you fear —
double-entry bookkeeping is about six ideas, and they have been stable for five hundred years.

## 2.1 Accounts and the two directions

An **account** is a bucket the business tracks: *Cash at Bank*, *Accounts Receivable*, *Sales
Revenue*, *Rent Expense*. The set of them is the **chart of accounts** (`accounts` table, one tree
per company).

Every account belongs to exactly one **type** (`account_types`, a global catalogue of seven rows seeded
by `AccountTypeSeeder`), and each type has a **normal balance** — the direction in which it naturally
grows:

| Type key | Normal balance | On the balance sheet? | Example |
|---|---|---|---|
| `asset` | debit | yes | Cash, Receivables, Inventory |
| `liability` | credit | yes | Payables, Loans |
| `equity` | credit | yes | Share capital, Retained earnings |
| `revenue` | credit | no | Sales |
| `expense` | debit | no | Rent, Salaries |
| `other_income` | credit | no | FX gain, interest received |
| `other_expense` | debit | no | FX loss, bank charges |

The first five are the classical classes; the last two separate non-operating items so the P&L can
show operating results distinctly. `is_balance_sheet` is what splits the two statements: balance-sheet
accounts carry forward across years, P&L accounts do not.

**Debit and credit are directions, not good and bad.** Debit means "left side", credit means "right
side". That is genuinely all they mean. A debit to Cash increases cash; a debit to Sales Revenue
decreases revenue. Stop trying to map them onto "in" and "out" — the mapping depends on the account
type, and the confusion never ends. Treat them as `+` and `−` on an axis whose orientation is set by
the account's normal balance.

In QAYD a journal line carries `debit` and `credit` as two separate `NUMERIC(19,4)` columns, and
`chk_jl_one_sided` enforces that exactly one of them is greater than zero. There is no signed
"amount" column on a line. (The *projection* has one — see §2.5.)

## 2.2 Why entries balance

Every financial event is recorded as a **journal entry**: a header plus two or more **lines**. The
rule is absolute:

```
SUM(debit) across the lines  ==  SUM(credit) across the lines
```

Not "approximately". Not "within a tolerance". Exactly, in the entry's own currency **and**
independently in the company's base currency.

Why this matters is not bookkeeping tradition — it is a **redundancy check**. Every event is recorded
twice, from two directions, and the two recordings must reconcile. If they do, the books have a
structural property: assets always equal liabilities plus equity, at every instant, without anyone
computing it. If they can drift, you lose the only self-check the system has, and errors become
undetectable rather than merely present.

In QAYD this is enforced in three places, deliberately:

1. `JournalEntryPostingService::assertBalanced()` — re-derived from the lines with bcmath, zero
   tolerance, in both currencies, throwing `UnbalancedEntryException` (`422 BALANCE_MISMATCH`).
2. `chk_je_balanced` / `chk_je_base_balanced` — CHECK constraints on the cached header totals.
3. A structural consequence: `ledger_entries.signed_base_amount` is `+base_debit` or `−base_credit`,
   so a balanced entry's projected rows always sum to exactly zero.

Note the discipline in step 1: the service **re-derives the totals from the lines** and never trusts
the cached header columns. Cached aggregates are a convenience for reading; they are never an input
to a correctness decision.

## 2.3 A worked example

A company in Kuwait buys a laptop for KWD 850, paying by bank transfer.

Two things happened: equipment came in, cash went out. Two lines:

| Line | Account | Type | Debit | Credit |
|---|---|---|---|---|
| 1 | Office Equipment | Asset | 850.0000 | 0.0000 |
| 2 | Cash at Bank | Asset | 0.0000 | 850.0000 |
| | **Totals** | | **850.0000** | **850.0000** |

Equipment (an asset, normal balance debit) increased → debit. Cash (also an asset) decreased → credit.
Both are assets; one grew and one shrank; total assets are unchanged, and the entry balances.

Now the same company pays KWD 300 rent:

| Line | Account | Type | Debit | Credit |
|---|---|---|---|---|
| 1 | Rent Expense | Expense | 300.0000 | 0.0000 |
| 2 | Cash at Bank | Asset | 0.0000 | 300.0000 |

An expense grew (debit) and cash shrank (credit). Assets fell by 300; equity fell by 300 through the
expense. The accounting equation still holds.

That is the whole mechanic. Everything else — invoices, payroll, depreciation, tax — is this shape
with more lines and more rules about *which* accounts.

## 2.4 What "posting" means

An entry has a lifecycle. `journal_entry_status` has eight values; the ones that matter to you now:

```
   draft ──────► pending_approval ──────► approved ──────┐
     │  ▲               │                                │
     │  └─── rejected ──┘                                ▼
     └───────────────────────────────────────────────► posted ──► reversed
                                                          │
                                                          └─────► voided
```

- **Draft** — a working document. Editable, deletable, not part of the books. It may be unbalanced
  while you are still typing it. It has a provisional number (`DRAFT-{id}`).
- **Posted** — a committed financial fact. It is in the books. It has a permanent, gapless number
  (`JE-FY2026-000001`). It affects the trial balance and every financial statement. **It can never be
  edited or deleted, by anyone, ever.**

**Posting is the transition** and it is the most important operation in the system. In QAYD it is one
transaction that does exactly this, in this order
(`JournalEntryPostingService::post()`):

```
1. Row-lock the header FOR UPDATE; re-read status; refuse unless draft|approved
       └─ this is also what makes a duplicate post impossible
2. Re-derive SUM(debit) / SUM(credit) from the LINES with bcmath; zero tolerance;
   both entry currency and base currency          → UnbalancedEntryException (422)
3. Resolve + lock the open fiscal period for the date, through the
   FiscalCalendarResolver seam                    → ClosedPeriodException (422)
4. Verify every targeted account is still active  → PostingRuleException (422)
5. Allocate the permanent gapless number, mark posted, and project
   one immutable ledger_entries row per line
   ────────────────────────────────────────────────────────────────
   COMMIT.  Then, and only then, PostJournalEntryAction emits
   accounting.journal.posted.
```

If any step throws, the entire transaction rolls back and **nothing** is visible: no number is
consumed, no ledger row exists, the entry is still a draft. There is no partial post.

## 2.5 Why posted data is immutable

An auditor's job is to verify that what the books say happened, happened. That is only possible if
history cannot be rewritten. If a posted entry could be edited, then every statement you ever
produced becomes provisional, every prior-year comparison becomes unverifiable, and any fraud becomes
invisible — you cannot detect a change to a record that has no fixed prior state.

So: **correction is by addition, never by mutation.** To fix a posted entry you post a *reversing*
entry that negates it and, if needed, a corrected entry. The original stays posted forever, with its
number, its date, and its ledger rows intact. The audit trail is the sequence of entries, and it only
ever grows.

QAYD enforces this at the storage engine, not in review:

- `trg_ledger_entries_append_only` — any `UPDATE` or `DELETE` on `ledger_entries` raises, including
  for the schema-owner role. `PostingEngineTest` proves this against the owner connection.
- `trg_journal_lines_no_update_when_posted` — any write to a line whose parent entry is
  `posted|reversed|voided|archived` raises.
- `uq_ledger_entries_journal_line` — a line can be projected at most once, so double-posting is
  structurally impossible even if the application forgets to check.

`ledger_entries` is a 1:1 **projection** of posted lines — a derived read model, written only by the
posting service, in the same transaction as the post, so it is never stale. `signed_base_amount`
(`+base_debit` for a debit leg, `−base_credit` for a credit leg) means any account balance is a single
`SUM()`.

## 2.6 The trial balance

A **trial balance** lists every account with its total debits and total credits for a date range. Its
purpose is one line at the bottom: **total debits must equal total credits**. If they do not, the
books are broken and every statement derived from them is wrong.

In QAYD it is `SUM(signed_base_amount) GROUP BY account_id` over `ledger_entries` — and because every
posted entry balanced and every leg is signed, the grand total is zero *by construction*, not by
luck. That is the payoff of the append-only signed projection: an invariant other systems check at
runtime is a structural theorem here. (Trial Balance is story S2-11; the projection it reads already
exists.)

Every financial statement is a differently-grouped trial balance:

- **Balance Sheet** — asset, liability, equity accounts, as of a date.
- **Profit & Loss** — revenue and expense accounts, over a period.
- **Cash Flow** — cash movements classified by activity.

## 2.7 Period close

A **fiscal year** is the company's accounting year (`fiscal_years`; an `EXCLUDE USING gist`
constraint makes overlapping years impossible per company). It is divided into **periods**, normally
months.

**Closing** a period means: we have finished recording this month, we have reviewed it, and we now
declare it final. After close, **no new entry may be posted into that period.** Without this, someone
posts a September transaction in November, the September statements you already gave the bank become
wrong, and there is no way to know it happened.

Today (S2-05) the gate is **fiscal-year granular**: `FiscalYearCalendarResolver` refuses to post into
any year whose status is not `open`. Month-level `fiscal_periods` are story S2-07. This is recorded
honestly as **TD-13** in `TECH_DEBT.md` — it is a staging decision, not a weaker promise, because no
month-close mechanism exists yet to be bypassed. The `FiscalCalendarResolver` interface exists
precisely so S2-07 can refine the granularity without touching the posting engine.

## 2.8 The vocabulary, mapped to this codebase

| Accounting term | Means | Lives in |
|---|---|---|
| Chart of accounts | The company's account tree | `accounts`, `account_types` |
| Journal entry | One financial event (header) | `journal_entries` |
| Journal line / leg | One debit or credit within it | `journal_lines` |
| General ledger | The record of all posted activity | `ledger_entries` (append-only projection) |
| Posting | Committing a draft to the books | `JournalEntryPostingService` |
| Reversal | Correcting by negating, never editing | S2-06 |
| Trial balance | Debits vs credits per account | `SUM(signed_base_amount)`, S2-11 |
| Fiscal year / period | The accounting calendar | `fiscal_years`; `fiscal_periods` → S2-07 |
| Base currency | The company's reporting currency | `base_debit` / `base_credit` / `base_total_*` |
| Control account | Summary account backed by a sub-ledger (AR/AP) | `accounts.is_control_account` |

---

# 3. Coding philosophy

## 3.1 Naming

Names in this codebase are long and unambiguous, and that is deliberate. Financial code is read far
more often than it is written, and it is read by people deciding whether it is *correct* — a job that
needs no guessing.

| Thing | Convention | Real example |
|---|---|---|
| Action | `VerbNounAction` | `PostJournalEntryAction`, `ReclassifyAccountAction` |
| Service | `NounService` / `NounAllocator` / `NounResolver` | `JournalEntryPostingService`, `JournalNumberAllocator` |
| DTO | `NounData` | `JournalEntryData`, `CreateAccountData` |
| Seam interface | `NounResolver` / `NounGuard` | `FiscalCalendarResolver`, `PostedActivityGuard` |
| Exception | `RuleViolationException` | `UnbalancedEntryException`, `ClosedPeriodException` |
| Error code | `SCREAMING_SNAKE`, stable, never localised | `BALANCE_MISMATCH`, `JOURNAL_NOT_POSTABLE` |
| DB constraint | `chk_` / `uq_` / `idx_` / `excl_` / `trg_` / `fn_` + table abbrev + rule | `chk_je_balanced`, `excl_fiscal_years_no_overlap` |
| Money variable | Names the currency basis | `$baseDebit`, not `$amount2` |

Two hard rules:

- **Never abbreviate a domain term.** `journalEntry`, not `je`. (Database constraint names are the
  single exception — Postgres has a 63-character identifier limit and `chk_je_balanced` is
  conventional there.)
- **A name that needs a comment to be understood is the wrong name.** Fix the name.

## 3.2 Comments

Comments in QAYD do not explain *what* the code does — the code does that. They record the things
code cannot express.

**Write a comment when:**

- **The rule has a source.** Cite it: `docs/accounting/JOURNAL_ENTRIES.md "# Posting Engine"`, a
  sprint story id (`S2-05`), a tech-debt id (`TD-13`). A reviewer must be able to check the code
  against its specification without asking you.
- **Something is deliberately absent.** The "scope note" convention in every migration — *these
  columns are deferred because their target tables do not exist; building them now would be building
  the future* — is one of the most valuable habits in this repository. It converts "looks incomplete"
  into "was decided".
- **The obvious alternative is wrong.** From `…_000005_create_journal_lines_table.php`: the reference
  DDL returns `NEW` unconditionally, which for a `DELETE` (where `NEW` is NULL) would silently cancel
  the delete — so this implementation returns `OLD`. Without that note, someone "simplifies" it back.
- **An ordering matters.** `PostingService`'s five numbered steps are ordered on purpose; the
  docblock says so.
- **Concurrency is involved.** What is locked, for how long, and what races it prevents.

**Do not write a comment when:** it restates the line below it, it is a changelog (`// added by…` —
that is git), it is commented-out code (delete it), or it is a TODO with no owner (put it in
`TECH_DEBT.md` per MANIFEST Law 5).

**The class docblock is the highest-value comment you will write.** Look at
`JournalEntryPostingService`: it states what the class is, its ordered invariants, what it explicitly
does *not* do (emit the event), and where the spec lives. That block is why a new engineer can
understand the most important class in the system in five minutes.

## 3.3 Function size

There is no line limit. There is a comprehension test:

> **A function should do one thing at one level of abstraction, and you should be able to state its
> postcondition in one sentence.**

`JournalEntryPostingService::post()` is about sixty lines and is correctly sized: it is the ordered
narrative of a post, and each step delegates to a well-named private method
(`assertBalanced`, `assertPostableAccounts`, `projectLines`). Splitting the narrative across four
public methods would hide the ordering, which is the thing most likely to be got wrong.

Signals you have it wrong:

- You need a blank line and a comment to introduce each "section" → those sections are methods.
- The function mixes levels: HTTP concerns next to bcmath next to SQL → wrong layer (see §4.2).
- You cannot test a branch without constructing an elaborate world → the branch wants its own unit.

## 3.4 Abstract, or duplicate?

Premature abstraction is more expensive here than duplication, because a wrong shared abstraction in
financial code gets *reused* into places it does not fit, and the resulting bug is silent.

```
      Am I about to share code between two places?
                         │
        ┌────────────────┴────────────────┐
        │                                 │
  Is it the SAME RULE,                 It just looks similar
  from the same spec,                  (same shape, different reason)
  changing for the same reason?                 │
        │                                       ▼
        ▼                              DUPLICATE. Write both.
   Do I have TWO real                  Add a comment saying why they
   call sites TODAY?                   are not shared.
        │
   ┌────┴────┐
   NO        YES
   │          │
   ▼          ▼
 Inline    Extract — as a trait if it is Action-layer
 it.       behaviour (WritesJournalDraft), as a service
           if it is a capability (JournalNumberAllocator),
           as an interface if it is a volatility boundary
           (FiscalCalendarResolver).
```

Worked example from the repo: `CreateJournalEntryAction` and `UpdateJournalDraftAction` share
`WritesJournalDraft` — same validation, same line-writing, same spec, two real call sites. Correct
extraction. Conversely, account-code uniqueness and journal-number uniqueness are both "unique per
company" and are **not** shared: different rules, different lifetimes, different failure codes.

Abstract only at the **third** occurrence unless the rule is a genuine invariant, in which case it
belongs in the database anyway (§5).

## 3.5 Error handling

Every failure a caller can act on is a **typed domain exception**: it extends
`App\Exceptions\DomainException`, carries a **stable catalog code**, an **HTTP status**, a
caller-safe message, an optional `field`, and structured `meta`.

```php
// app/Exceptions/DomainException.php — the contract
abstract public function errorCode(): string;   // 'BALANCE_MISMATCH' — stable, never localised
abstract public function errorStatus(): int;    // 422 content violation | 409 state conflict
```

`ApiExceptionRenderer` turns any subclass into the standard envelope. An **unhandled** exception
becomes `INTERNAL_ERROR` (500) with a fixed message — never a stack trace, class name, SQL, or file
path, in any environment.

**Named factories, not constructor arguments.** Each call site should read as the rule it enforces:

```php
throw PostingRuleException::notPostable($status);      // 409 JOURNAL_NOT_POSTABLE
throw PostingRuleException::emptyEntry();              // 422 CANNOT_POST_EMPTY
throw PostingRuleException::inactiveAccount($id);      // 422 ACCOUNT_INACTIVE
throw ClosedPeriodException::noPeriodForDate($date);   // 422 CLOSED_PERIOD
```

Status selection, applied consistently:

| Status | Meaning | Example |
|---|---|---|
| 422 | The content violates a rule | unbalanced, inactive account, empty entry |
| 409 | The *state* forbids the operation | already posted, stale `version`, not editable |
| 404 | Not visible to this tenant — **also** what a cross-tenant id returns | unknown account id |
| 403 | Authenticated but not permitted | missing `accounting.coa.manage` |

**403 vs 404 is a security decision, not a UX one.** A cross-tenant id returns 404, never 403 — a 403
confirms the resource exists, which is a tenant-enumeration oracle. See `ResolveTenantCompany`, which
returns 404 both for "no such company" and "you are not a member".

**Never:**
- `throw new \Exception('...')` in domain code — it renders as an opaque 500.
- `abort(422, 'message')` in an Action — the message is not a stable code, so no client can branch on it.
- Catch-and-log-and-continue. If you cannot handle it, let it propagate; the transaction must roll back.
- A `$skipValidation` / `$force` parameter. An invariant with an off switch is not an invariant. (01 → P2.)

## 3.6 The Action pattern in practice

An **Action** is one business operation. It is where business logic lives, and it is the only place
it lives. (01 → P10, P11.)

**Anatomy:**

```php
final class PostJournalEntryAction                      // final; one public method
{
    public function __construct(                         // dependencies injected, never resolved
        private readonly JournalEntryPostingService $posting,
    ) {}

    public function execute(JournalEntry $entry, ?int $actorUserId = null): JournalEntry
    {
        $posted = $this->posting->post($entry, $actorUserId);   // the atomic work

        event(JournalEntryPosted::fromEntry($posted));          // AFTER commit, never inside

        return $posted;
    }
}
```

**The rules:**

1. `final`, one public method (`execute`), named `VerbNounAction`.
2. Input is a **DTO** (`app/Data/…`, `final readonly`) or a model — never a `Request`, never an array.
   An Action must be callable from a controller, a queue job, a console command, an event listener,
   and a test with equal ease.
3. **No HTTP.** No `request()`, no `abort()`, no `response()`, no session. An Action that knows it is
   behind HTTP cannot be reused by the queue.
4. **One transaction, on the tenant connection**: `DB::connection(TenantContext::connection())->transaction(...)`.
   Every write inside it, so a failure anywhere rolls the whole operation back. (01 → P8.)
5. **Events after commit.** A subscriber must never react to a fact that may still roll back. Note
   how `PostJournalEntryAction` keeps the event *outside* the service's transaction — the service is
   pure DB work, the Action orchestrates and announces.
6. **Validate, then act.** Application checks mirror the database CHECKs so the caller gets a clean
   422 instead of a raw constraint violation — and the database remains the final backstop. Both. Not
   either.
7. **Return the domain object or a DTO**, never a response.

**Controllers** validate input, call exactly one Action, and shape the envelope. `AccountController`
is the reference: every rule (type exists, parent in the same company, code unique, posted-account
guard) is in the Actions; the controller does presentation and routing only.

> **Status:** the "no logic in models / controllers" rules are **convention** today, caught in
> review. 01 → P10/P11 specify arch tests to mechanise them; only the `BelongsToCompany` arch test
> exists so far. Writing the model-surface and controller-dependency arch tests is genuinely
> valuable, cheap work if you are looking for a first contribution.

---

# 4. Architecture philosophy

## 4.1 The layers

```
┌────────────────────────────────────────────────────────────────────┐
│ HTTP        routes/api.php · Middleware · Controllers · Requests   │
│             KNOWS: HTTP, auth, envelopes.  KNOWS NOTHING of rules. │
├────────────────────────────────────────────────────────────────────┤
│ Actions     app/Actions/{Domain}/                                  │
│             THE business logic. One operation each. Transactional. │
│             Input: DTOs. Output: models/DTOs. Throws DomainException│
├────────────────────────────────────────────────────────────────────┤
│ Services    app/Services/{Domain}/                                 │
│             Multi-step capabilities several Actions share.         │
│             PostingService, JournalNumberAllocator, AuditLogger.   │
├────────────────────────────────────────────────────────────────────┤
│ Domain      app/Domain/{Domain}/                                   │
│             Interfaces (seams), value objects, resolved-state DTOs.│
│             FiscalCalendarResolver, ResolvedFiscalPeriod.          │
├────────────────────────────────────────────────────────────────────┤
│ Models      app/Models/  — THIN. Table, casts, relations,          │
│             BelongsToCompany, constants. NO business logic.        │
├────────────────────────────────────────────────────────────────────┤
│ PostgreSQL  CHECK · FK · UNIQUE · EXCLUDE · trigger · RLS          │
│             The invariants. True whether or not PHP runs.          │
└────────────────────────────────────────────────────────────────────┘
```

Each layer may call downward only. A model never calls an Action. A service never touches HTTP. This
is what makes any layer testable in isolation and replaceable in place.

## 4.2 Where does this logic go?

```
              I have a piece of logic. Where does it belong?
                                 │
    ┌────────────────────────────┴─────────────────────────────┐
    │ Is it an INVARIANT — must be true of the data, always,    │
    │ no matter what code runs?                                │
    └────────────────────────────┬─────────────────────────────┘
                    YES ─────────┴───────── NO
                     │                       │
                     ▼                       ▼
        ┌────────────────────────┐   ┌──────────────────────────────────┐
        │ POSTGRESQL             │   │ Is it about HTTP — parsing,      │
        │ CHECK/FK/UNIQUE/       │   │ auth, status codes, envelopes?   │
        │ EXCLUDE/trigger        │   └──────────────┬───────────────────┘
        │ + an app-layer mirror  │        YES ──────┴────── NO
        │   for a clean 422      │         │                 │
        └────────────────────────┘         ▼                 ▼
                              ┌───────────────────┐  ┌────────────────────────┐
                              │ CONTROLLER /      │  │ Is it a BUSINESS       │
                              │ MIDDLEWARE /      │  │ OPERATION a user or    │
                              │ FORM REQUEST      │  │ agent performs?        │
                              └───────────────────┘  └───────────┬────────────┘
                                                    YES ─────────┴──── NO
                                                     │                  │
                                                     ▼                  ▼
                                              ┌────────────┐   ┌─────────────────────┐
                                              │  ACTION    │   │ Is it a capability  │
                                              │            │   │ several Actions     │
                                              └────────────┘   │ share?              │
                                                               └──────┬──────────────┘
                                                        YES ──────────┴───── NO
                                                         │                    │
                                                         ▼                    ▼
                                                  ┌────────────┐      ┌───────────────┐
                                                  │  SERVICE   │      │ Is it likely  │
                                                  └────────────┘      │ to be         │
                                                                      │ REPLACED?     │
                                                                      └──────┬────────┘
                                                                  YES ───────┴─── NO
                                                                   │              │
                                                                   ▼              ▼
                                                     ┌──────────────────┐  ┌───────────────┐
                                                     │ DOMAIN INTERFACE │  │ Private method│
                                                     │ (a seam) + a     │  │ on the Action │
                                                     │ container binding│  └───────────────┘
                                                     └──────────────────┘
```

**Never**: a model boot hook that enforces a business rule, an observer that writes to another
module's table, or a global helper function that reaches into the container.

## 4.3 The seam principle

A **seam** is a named interface at a boundary you expect to move, with the implementation chosen by a
container binding. It is not speculative abstraction — you introduce it exactly when you *know* a
decision is staged.

The live example: `JournalEntryPostingService` needs to know "is the period covering this date open?"
The answer's granularity is a known, scheduled change (fiscal years now, fiscal months in S2-07). So
the service depends on the interface:

```php
// app/Domain/Accounting/FiscalCalendarResolver.php
public function resolveOpenPeriodForPosting(int $companyId, string $date): ResolvedFiscalPeriod;

// app/Providers/AppServiceProvider.php
$this->app->bind(FiscalCalendarResolver::class, FiscalYearCalendarResolver::class);
```

S2-07 changes one line in the provider. The posting engine, the interface, and `ResolvedFiscalPeriod`
do not change. The same pattern carries `PostedActivityGuard` — bound today to
`NoLedgerPostedActivityGuard` (returns false; no ledger existed when the chart of accounts was built),
to be rebound to a ledger-backed implementation (TD-11, now unblocked).

**Introduce a seam when:** the decision is staged and you can name the successor; there is a genuine
alternative implementation (a jurisdiction, a strategy); or it is the boundary of an external system.
**Do not** introduce one because an interface feels tidier — a one-implementation interface with no
named successor is indirection, not architecture. (01 → P17.)

## 4.4 Module boundaries, and why cross-module writes are forbidden

Modules — Accounting, Identity, Onboarding, and later Inventory, Payroll, Tax, Banking — each own
their tables. **A module never writes another module's tables. Not through Eloquent, not through a
service, not "just this once".**

```
  ✗ FORBIDDEN                          ✓ REQUIRED
  ┌──────────┐   writes    ┌────────┐  ┌──────────┐  event   ┌────────────┐
  │ Inventory│────────────►│ ledger │  │ Inventory│─────────►│ listener   │
  │  module  │  directly   │ tables │  │  module  │ after    │ in Acctg   │
  └──────────┘             └────────┘  └──────────┘ commit   └─────┬──────┘
                                                                   │ calls
                                                                   ▼
                                                          PostJournalEntryAction
                                                          (the one way in)
```

Cross-module integration is **after-commit domain events** and nothing else.
`accounting.journal.posted` is the first; the pattern is `app/Events/Accounting/JournalEntryPosted.php`.

The reason is not tidiness. Systems that let modules write each other's tables become impossible to
change: every schema edit ripples through unknown callers, no module can be tested alone, and the
invariants of the owning module are enforced only where its own code happens to run. It is also the
mechanism by which "there is exactly one way into the ledger" survives contact with a second module —
Inventory posting a valuation entry goes through `PostJournalEntryAction`, gets every check, and gets
them in the right order.

> **Status:** event-based integration is architecture and convention today. A **transactional
> outbox** (so an event cannot be lost if the broker is down, nor fire if the transaction rolls back)
> is designed but not built — see 05.

---

# 5. Database philosophy

## 5.1 PostgreSQL owns integrity

The rule: **if a statement about the data must always be true, it is a database constraint.** The
application check is an addition for a good error message, never a substitute.

Why, stated plainly: application checks only run when your application runs. They do not run for a
migration backfill, a console command, a queue worker written next year, a psql session during an
incident, a BI tool, or the code path someone adds and forgets to guard. A constraint runs for all of
them, forever, including for the schema owner.

This is also the difference between a rule you *believe* holds and one you *know* holds. You can
prove `chk_je_balanced` by reading one line of DDL. You cannot prove "the totals are always balanced"
by reading a whole application.

## 5.2 The constraint vocabulary

| Tool | Use it when | QAYD example |
|---|---|---|
| `NOT NULL` | The column is meaningless when absent | `company_id BIGINT NOT NULL` — a precondition for RLS meaning anything |
| **FK** | A value must name a real row | `account_id BIGINT NOT NULL REFERENCES accounts(id)` |
| **CHECK** | A rule over *one row's* columns | `chk_jl_one_sided`: exactly one of debit/credit > 0, both ≥ 0 |
| **UNIQUE** | No two rows may share a key | `uq_je_number (company_id, journal_number)`; `uq_ledger_entries_journal_line` — the 1:1 projection backstop |
| **EXCLUDE** | No two rows may *overlap* (ranges, not equality) | `excl_fiscal_years_no_overlap` — GiST over `daterange`, per company. UNIQUE cannot express this |
| **Partial index** | Uniqueness or lookup over a *subset* | `idx_je_not_deleted … WHERE deleted_at IS NULL` |
| **Trigger** | A rule needing another row, or forbidding a whole operation | `trg_ledger_entries_append_only` (rejects UPDATE/DELETE); `trg_journal_lines_no_update_when_posted` (reads the parent's status); `trg_no_ai_autopost` (an AI entry may only be *created* as a draft) |
| **Deferred constraint trigger** | The invariant is only true at end-of-transaction (e.g. children must sum to 100%) | Not yet used; the designated tool for that class — see 05 |

Reach for the **weakest tool that expresses the rule**: CHECK before trigger, UNIQUE before trigger,
declarative before procedural. A CHECK is visible in `\d`, is used by the planner, and cannot have a
bug. A trigger is code, and code can be wrong.

**Never** enforce with a trigger what a CHECK expresses. **Never** enforce in PHP what a CHECK
expresses.

## 5.3 Migration discipline

**Raw SQL, deliberately.** QAYD migrations use `DB::statement` with heredoc SQL, not the Schema
builder. The builder cannot express `EXCLUDE USING gist`, `RESTRICTIVE` policies, `FORCE ROW LEVEL
SECURITY`, `GENERATED ALWAYS AS IDENTITY`, or named CHECK constraints — and half-expressing the
schema is worse than not using the builder at all. Read
`…_000004_create_journal_entries_table.php` for the house style.

Every migration:

- **Has a docblock** naming the story, the spec section, the scope, and the *deferred* columns with
  the reason they are deferred. This is the single most useful thing in a QAYD migration.
- **Names every constraint.** `CONSTRAINT chk_je_balanced CHECK (...)`. An anonymous constraint
  produces an unreadable error and cannot be referenced.
- **Is idempotent where the type system forces it.** Enums are created inside `DO $$ … IF NOT EXISTS`
  so `migrate:fresh` is safe.
- **Has a real `down()`.** `DROP TABLE` takes the policies, indexes, triggers, and constraints with
  it; then drop the now-table-independent functions and types.
- **Applies the RLS block** if the table has `company_id`.
- **Never carries data migrations mixed with DDL.** Separate them; a failed backfill should not leave
  a half-built schema.

**Expand/contract for anything already in production** (§12.3).

## 5.4 The RLS idiom — copy this exactly

Three things make tenancy real, and all three must be present:

1. The runtime role is `NOSUPERUSER NOBYPASSRLS` (`qayd_app`, migration `…_000008`), used by the
   `pgsql_app` connection. RLS does not apply to superusers or to the table owner unless forced.
2. `ENABLE` **and** `FORCE` row level security. `ENABLE` alone leaves the owner exempt; `FORCE` closes
   that.
3. A `RESTRICTIVE` boundary policy **plus** permissive per-verb policies. Restrictive policies are
   AND-ed and cannot be OR-ed past by any future permissive policy; permissive ones are OR-ed and are
   what actually grant access. Together they give "always inside the company, and additionally
   whatever the verb permits".

```php
private function applyRowLevelSecurity(): void
{
    DB::statement('ALTER TABLE my_table ENABLE ROW LEVEL SECURITY');
    DB::statement('ALTER TABLE my_table FORCE ROW LEVEL SECURITY');

    DB::statement(<<<'SQL'
        CREATE POLICY my_table_company_boundary ON my_table
        AS RESTRICTIVE FOR ALL
        USING (company_id = app_current_company_id())
        WITH CHECK (company_id = app_current_company_id())
        SQL);

    // then the four permissive per-verb policies: _tenant_select / _insert / _update / _delete,
    // each keyed on the same app_current_company_id() predicate.
}
```

`app_current_company_id()` (defined once in `…_000009_enable_row_level_security.php`) reads the
`app.current_company_id` GUC and returns **NULL when unset**. `company_id = NULL` evaluates UNKNOWN,
so **no rows** are returned. That is the fail-closed property: no tenant context means you see
nothing, never someone else's data.

## 5.5 Every tenant table looks the same

A table with `company_id` is a tenant table, and every tenant table is identical in these respects:

- [ ] `company_id BIGINT NOT NULL REFERENCES companies(id)` — **NOT NULL is load-bearing.** A NULL
      `company_id` is invisible to an equality predicate, so such a row leaks *out* of the boundary
      rather than being contained by it.
- [ ] `ENABLE` + `FORCE` row level security.
- [ ] The restrictive boundary policy + four permissive per-verb policies, named
      `{table}_company_boundary` / `{table}_tenant_{verb}`.
- [ ] Its Eloquent model uses `BelongsToCompany` — which adds `CompanyScope`, auto-fills
      `company_id`/`created_by`/`updated_by`, and binds the model to the `pgsql_app` connection.
- [ ] Money columns are `NUMERIC(19,4)`. Rates are `NUMERIC(18,6)`. Timestamps are `TIMESTAMPTZ`.
- [ ] Indexes are `(company_id, …)`-prefixed — every tenant query filters on it.

Uniformity is not aesthetics. It means a reviewer can verify a new table in thirty seconds, and it
means the whole set is checkable by one query.

> **Status:** `BelongsToCompanyArchTest` mechanises the model half — the build fails if a model owns
> a `NOT NULL company_id` table without the trait. The **catalog-introspection CI check** for the
> other half (any table with `company_id` must have `NOT NULL`, `relrowsecurity`,
> `relforcerowsecurity`, and the named restrictive policy) is designed but **not built**. It is the
> highest leverage-to-cost item available: one query against `pg_class`/`pg_policy`/`pg_attribute`
> that converts a convention into a mechanism.

---

# 6. AI philosophy

QAYD is an AI Financial Operating System. The defining risk is therefore an AI that writes to the
ledger at machine speed without supervision — it can corrupt books faster than any human can notice,
and the corruption is *plausible*, which makes it worse than random.

The entire AI architecture exists to make that structurally impossible rather than merely discouraged.

## 6.1 The proposal / confirmation boundary

```
   ┌────────────┐    reads context       ┌──────────────┐
   │ apps/ai    │◄──────────────────────┤ apps/api      │
   │ FastAPI    │                        │ Laravel       │
   │ Orchestrator│───── PROPOSAL ───────►│ /api/v1       │
   │            │  {payload, confidence, │ validation,   │
   │ NO DB      │   reasoning, model,    │ RBAC, tenant  │
   │ CREDENTIAL │   prompt_version}      │ context       │
   └────────────┘                        └──────┬────────┘
                                                │ stored as a PROPOSAL
                                                ▼
                                    ┌───────────────────────┐
                                    │  Human reviews        │
                                    │  approve / reject /   │
                                    │  edit / delegate      │
                                    └──────────┬────────────┘
                                               │ the DECISION is committed,
                                               ▼ never the proposal itself
                                    PostJournalEntryAction → the ledger
```

Three separate barriers, each sufficient on its own:

1. **Architectural** — `apps/ai` holds no tenant database credential and no network path to Postgres.
   It could not write if it tried.
2. **Application** — every AI output re-enters through the same `/api/v1` front door as a human
   action: same validation, same RBAC, same tenant context. It can knock only as hard as its
   permission grant allows.
3. **Database** — `trg_no_ai_autopost` raises if a row with `ai_generated = true` is *inserted* with
   any status other than `draft`. Even a compromised application layer cannot create-and-post in one
   step.

The system auto-commits a **human's decision about a proposal**, never a proposal.

## 6.2 Confidence, provenance, and explanation are first-class data

Not logs. Not metadata. Columns.

`journal_entries` already carries `ai_generated BOOLEAN NOT NULL` and `ai_confidence NUMERIC(5,4)`,
with `chk_je_ai_confidence` enforcing that an AI-generated entry **must** carry a confidence in
[0,1]. `journal_lines` carries `ai_confidence` and `ai_suggested_account_id`.

Every AI-originated value must be able to answer, from the database alone:

| Question | Stored as |
|---|---|
| Did a model produce this? | `ai_generated` |
| How sure was it? | `ai_confidence`, CHECK-constrained to [0,1] |
| Why did it say that? | reasoning text (persisted with the proposal) |
| Which model, which prompt version? | model id + prompt version on the proposal |
| Who approved it, and when? | `approved_by` / `approved_at` / `posted_by` / `posted_at` |

If a customer asks "why is this transaction classified as marketing expense?", the answer must be
retrievable a year later without replaying anything.

## 6.3 When NOT to use an LLM

> **A deterministic rule beats a model wherever one exists.** Always. Not usually.

```
        Can this be decided by an exact rule over data I already have?
                                  │
                    YES ──────────┴────────── NO
                     │                         │
                     ▼                         ▼
        WRITE THE RULE. No LLM.     Is the input unstructured (a PDF, an
        Faster, free, deterministic, email, free text) or genuinely ambiguous?
        testable, explainable.                 │
        e.g. exact reference match,  ┌─────────┴─────────┐
        amount + date match,        YES                  NO
        VAT arithmetic, whether      │                    │
        an account is postable.      ▼                    ▼
                            ┌──────────────────┐  ┌───────────────────┐
                            │ LLM PROPOSES.    │  │ It is a rule you  │
                            │ Human disposes.  │  │ have not written  │
                            │ Confidence +     │  │ yet. Write it.    │
                            │ reasoning stored.│  └───────────────────┘
                            └──────────────────┘
```

**Never use a model for:** arithmetic of any kind (money, tax, FX, balances), determining whether an
entry balances, authorization decisions, generating SQL that runs, or anything where a wrong answer is
silently plausible and nobody will check.

**Deterministic-first also keeps the model honest.** In bank reconciliation the intended design is a
strict ordering: exact rules settle what they can, the model only ever sees the residue, and a human
confirms. That ordering both reduces cost and makes the model's error rate measurable — you know
exactly which population it was asked about.

## 6.4 Prompt and version discipline

- Prompts are **files in the repository**, versioned in git, reviewed like code.
- Every proposal records the **model id and prompt version** that produced it. Without that, a
  regression is undiagnosable — you cannot compare against a prompt you no longer have.
- Prompt changes are **behaviour changes**. They go through review and through the eval suite, not
  through a config tweak in production.
- Tenant data never crosses tenants: retrieval is company-scoped, and per-tenant learning happens
  through per-tenant retrieved corrections, never a shared fine-tune.

## 6.5 Evaluating AI output

Two different things, deliberately separated:

| | The boundary around the model | The model's answer quality |
|---|---|---|
| Question | Did the system refuse to let it write? Was a sensitive op forced to require approval regardless of confidence? Was a low-confidence field flagged rather than guessed? | Was the suggested account the right one? |
| Nature | Deterministic | Statistical |
| Where | Backend unit + contract tests | Scheduled eval harness against golden datasets |
| Gating | **Blocking.** Always. | **Non-blocking** — model quality is a tuning surface, not a correctness boundary |

Guard the boundary in CI; track quality on a dashboard. Never confuse the two.

> **Status — read this before assuming.** `apps/ai` today is a health route and its gate suite
> (ruff / mypy strict / pytest). The proposal endpoint, the `match_proposals` table, the
> `AutonomyResolver`, and the eval harness are **designed, not built**. What *is* real today:
> `trg_no_ai_autopost` (a DB-enforced guarantee), the `ai_generated`/`ai_confidence` columns and their
> CHECK, the credential-less service boundary, and ADR-0007. Note also **TD-12 #4**: "AI cannot
> submit" is currently *advisory* at the Action layer (a caller-supplied flag), with the trigger as
> the real backstop — it must become an authorization boundary.

---

# 7. Security philosophy

## 7.1 Tenancy is a property of the database

Restating §5.4 as a security claim, because it is the most important one in the system: a cross-tenant
read is not prevented by remembering to add `where('company_id', …)`. It is prevented by the storage
engine, because the connection's role cannot bypass RLS and the policy predicate resolves to NULL
without a tenant context.

**Defence in depth, all four layers live:**

```
1. ResolveTenantCompany middleware — verifies membership on the owner connection with
   trusted inputs (auth user id + requested company), 404s on any failure, then opens the
   request transaction and SET LOCALs the GUCs.
2. CompanyScope (Eloquent global scope)  — every query gains the company predicate.
3. BelongsToCompany                      — binds the model to the pgsql_app connection.
4. PostgreSQL RLS                        — zero rows regardless of what 1–3 did.
```

The middleware verifies membership on the **owner** connection deliberately: a pre-context read on
the RLS-scoped connection would return zero rows and be indistinguishable from "not a member". The
inputs there are trusted (the authenticated user id and the requested company), and the resulting
tenant context is derived from the verified membership — never from raw client input.

## 7.2 No ambient privilege

There is **no bypass mechanism** in QAYD, and this absence is load-bearing.

`is_platform_admin` exists as a user column and sets a GUC, but is deliberately **not wired to any
cross-tenant read bypass** in any policy (TD-04). That is not an oversight; it is the decision.

The failure mode being avoided is well documented in other systems: a short, convenient escape hatch
that disables access control mid-expression, propagates transitively to everything derived from it,
and is unauditable — no log, no reason, no scope. Once such a thing exists it appears hundreds of
times, and every security boundary becomes a convention.

**The rule: there is no bypass.** If you think you need one, you need one of:

- a new permission (add it to the RBAC catalogue), or
- a new policy clause (a reviewed migration), or
- an explicit `PlatformOperation` — a distinct database role, narrow per-table policy clauses, and an
  audit row written **in the same transaction** so that if the audit write fails, the operation fails.

## 7.3 Secrets

- Never in the repository. Never in a migration, seed, fixture, test, or log line.
- Environment variables only; `.env.example` documents the *keys*, never the values.
- The one exception is deliberate and narrowly scoped: the throwaway CI service-container passwords in
  `.github/workflows/ci.yml`, which must be literal for the container and the migrate step to agree,
  and whose detection is scoped out in `/.gitguardian.yaml` — **not** a global secret suppression.
- Signing keys (`GenerateJwtKeys`) are generated, never committed.
- Error responses never leak internals: `ApiExceptionRenderer` renders an unhandled exception as a
  fixed `INTERNAL_ERROR` message in **every** environment.

## 7.4 The `SET LOCAL` discipline, and why pooling is a correctness hazard

This is the subtlest and most dangerous thing in the codebase. Read it twice.

PostgreSQL GUCs (`app.current_company_id` and friends) are **per-connection**, not per-request. QAYD
uses the transaction-local form:

```php
// ResolveTenantCompany — inside a transaction it opened itself
$tenant->transaction(function () use ($tenant, ...) {
    $tenant->select('SELECT set_config(?, ?, true)', [TenantContext::GUC_COMPANY_ID, (string) $company->id]);
    //                                          ^^^^ true = LOCAL: discarded at commit/rollback
    ...
});
```

`set_config(name, value, true)` is the parameter-safe equivalent of `SET LOCAL`. It **must** be inside
an explicit transaction; outside one it silently behaves as session-scoped.

**The hazard.** Under PgBouncer *transaction* pooling, a physical connection is handed to a different
request between transactions. If the GUC were set with `SET` (session scope) instead of `SET LOCAL`,
one tenant's company id would remain on the connection and be inherited by the next request — a
**silent cross-tenant data breach**, with no error, no log, and no symptom until a customer sees
another company's numbers.

It is invisible in single-connection testing. It only appears under a real pooler with real
concurrency.

**Requirements, non-negotiable:**

- Always `SET LOCAL` (`set_config(..., true)`), always inside an explicit transaction.
- The rule applies to **queue jobs and console commands**, not just HTTP. Any code path that touches
  tenant data must establish the context the same way. A background job is exactly where this gets
  forgotten.
- Before connection pooling is enabled anywhere, this must be **tested against a real pooler under
  genuine concurrency**. Not reasoned about. Tested.

> **Status:** the HTTP path is correct today — `ResolveTenantCompany` opens the transaction and uses
> the local form, and `TenantHarness::runInTenant` mirrors it in tests. A pooler-safe wrapper covering
> queue jobs and console commands, and the concurrency test against a real pooler, are **not built**.
> This is a pre-condition for enabling pooling, not a follow-up.

## 7.5 Threat model

| Threat | Primary control | Backstop |
|---|---|---|
| Cross-tenant read/write | RLS `FORCE` + `NOBYPASSRLS` role | `CompanyScope`, membership check in middleware |
| Tenant enumeration | 404 for both "absent" and "not a member" | No 403 on tenant-scoped resources, ever |
| Ledger tampering | `trg_ledger_entries_append_only` | `uq_ledger_entries_journal_line`; no un-post path |
| Editing posted history | `trg_journal_lines_no_update_when_posted` | `EDITABLE_STATUSES` in the Actions |
| AI writing to the ledger | Credential-less `apps/ai` | `trg_no_ai_autopost`; proposal-only endpoint |
| Privilege escalation | RBAC (`role ∪ grant − deny`), route `permission:` gates | No `sudo`; `is_platform_admin` unwired |
| GUC leakage via pooling | `SET LOCAL` in an explicit transaction | (test against a real pooler — **outstanding**) |
| Raw SQL bypassing RLS | Route tenant queries through `pgsql_app` | **TD-01 — open**: raw `DB::` on the owner connection is unscoped; a static check is planned |
| Credential replay after logout | Refresh-token rotation with family-wide reuse detection | **TD-09 — open**: the 15-min access JWT is not revoked on logout |
| Session fixation / CSRF | Session rotation on login | **TD-07 — open**: CSRF is not wired on the Sanctum cookie flow |

The open items are in `TECH_DEBT.md` with owners and planned resolutions. Read that file — it is the
honest picture, and a reviewer is entitled to assume you have.

## 7.6 If you suspect a tenant data leak

Treat it as a live incident. In this order:

1. **Do not fix it first.** Capture evidence: the exact request, the `request_id` (every response
   carries one — `AssignRequestId`), the timestamp, the companies involved, the SQL if you have it.
2. **Escalate immediately** to the architecture owner. A tenant leak is not a normal bug and is not
   yours to triage alone.
3. **Determine the blast radius** before remediating: query as the owner role for which rows were
   returned, to whom, and when. Do this before any deploy changes the behaviour you are measuring.
4. **Write the failing test first** — an isolation test that reproduces the leak. It goes in
   `tests/Feature/Rls/` and it stays forever.
5. **Fix at the deepest layer that was breached.** If RLS did not stop it, an application fix is not
   the fix — find out why RLS did not apply (wrong connection? raw query on the owner connection?
   missing `FORCE`? missing policy? unset GUC?).
6. **Record it** in `TECH_DEBT.md` if anything remains open, and write the check that would have
   caught it.

---

# 8. Testing philosophy

In most software, tests protect you from regressions. Here they are also the **evidence** that a
financial invariant holds. `PostingEngineTest` is not a safety net around the posting engine; it is
the demonstration that debits equal credits, that a closed period refuses a post, and that posted
history cannot be mutated even by a privileged role. Write tests that would convince an auditor, not
tests that raise a coverage number.

## 8.1 The pyramid

```
                      ▲
                     / \     AI-Eval — model answer quality (non-gating)
                    /---\
                   / Sec \   RLS negative suite, permission matrix, secrets
                  /-------\
                 /  Load   \ k6 — smoke (PR), ramp (nightly), soak (pre-release)
                /-----------\
               /     E2E     \ Playwright — critical journeys only
              /---------------\
             /    Contract      \ OpenAPI + shared AI-proposal schema
            /-------------------\
           /    Integration       \ Pest feature (full stack), migration/RLS tests
          /-----------------------\
         /          Unit            \ Pest / Vitest / pytest — every commit
        /---------------------------\
```

Target composition of the test *count*: ~60% unit, ~26% integration, ~6% contract, ~4% E2E, ~2% load,
~2% dedicated security/isolation. The shape matters more than the numbers: most confidence must come
from fast, isolated tests, and **no upper band is ever accepted as a substitute for a lower one**. An
E2E journey that happens to hit `POST /accounting/journal-entries` does not excuse that endpoint from
a feature test.

## 8.2 What must be tested where

This is the load-bearing table. Get the layer wrong and the test proves less than you think.

| Level | Owns | Must include |
|---|---|---|
| **Database** (integration, real Postgres) | **Invariants** — the things that must be true whatever code runs | Every CHECK rejects its violation. Every trigger fires — including **against the owner connection**, which is the whole point. Every RLS policy: cross-tenant read returns zero rows, cross-tenant write is rejected, and **no tenant context returns zero rows** (fail-closed). Every EXCLUDE/UNIQUE rejects its overlap/duplicate. |
| **Action** (unit + integration) | **Rules** — the business decisions | Every named exception factory has a test asserting its `errorCode()` **and** `errorStatus()`. Every guard: not-editable, stale `version`, inactive account, invalid entry type. The happy path returns the right domain object. |
| **Service** (integration) | **Orchestration and atomicity** | The ordering of checks. That a failure at step N leaves **nothing** written from steps 1..N−1. Idempotency (post twice → 409, still exactly one projection per line). |
| **Feature / HTTP** | **Flows** — the full stack through the real middleware | Auth → tenant → RBAC → validation → Action → DB → envelope. Every route has ≥1 test asserting the envelope. Every FormRequest rule has a 422 test asserting the `code`. Every sensitive endpoint has an explicit 403 test. Cross-tenant id → 404. |
| **Contract** | Shapes crossing a service boundary | Response vs OpenAPI; the AI proposal payload vs the shared schema. |

Coverage floors (from `docs/testing/TESTING_STRATEGY.md`): **95% line / 90% branch on money, posting,
and tenancy paths**; 85/80 overall for the backend. Coverage is a floor, not a goal — a green badge
over code that never exercises a permission boundary is worthless.

> **Status:** the coverage step in `ci.yml` is currently `continue-on-error: true` — **advisory, not
> blocking**, while the suites are still small. The named mandatory scenarios in the table above are
> the real bar today, enforced by review.

## 8.3 Testing against real PostgreSQL — the `TenantHarness`

**RLS is a PostgreSQL feature. It cannot be tested on sqlite, and neither can any trigger, CHECK,
EXCLUDE constraint, or `NUMERIC` behaviour.** A test that "proves" tenant isolation on sqlite proves
nothing.

`tests/Support/TenantHarness.php` gives you two real connections against the real database:

| | Role | RLS | Use it for |
|---|---|---|---|
| `TenantHarness::owner()` | `qayd` (schema owner) | **bypassed** | Migrations, seeding, arranging fixtures that must survive, and asserting what *really* exists in the table |
| `TenantHarness::app()` | `qayd_app` (`NOSUPERUSER NOBYPASSRLS`) | **enforced** | Everything the application does. This is where isolation is actually proven |

The canonical shape:

```php
beforeEach(fn () => TenantHarness::boot());          // registers both connections; migrate:fresh once

$one = TenantHarness::seedCompany('Tenant One');     // company + owner role + admin + membership
$two = TenantHarness::seedCompany('Tenant Two');     // two companies: a leak needs somewhere to leak TO

$visible = TenantHarness::runInTenant($one['company_id'], function (): array {
    // binds the container company id AND SET LOCALs the RLS GUC, mirroring the middleware exactly;
    // the whole closure runs in a transaction that is rolled back afterwards for isolation
    return LedgerEntry::query()->pluck('journal_entry_id')->all();
});

expect($visible)->toContain($mine)->and($visible)->not->toContain($theirs);
```

Two consequences of `runInTenant` rolling back, and both bite people:

1. **Fixtures that must outlive the closure go on the owner connection** (see `peSeedPostedLedgerRow`
   in `PostingEngineTest`). A row created inside `runInTenant` vanishes when it returns.
2. **Assertions about post-effects must run *inside* the same closure** — hence the `pePostHere`
   in-context variant. This is why the idempotency test performs both posts inside one closure: the
   second must see the first.

Tag your tests: `uses()->group('rls', 'isolation')` or `uses()->group('accounting')`.

> **Status:** the harness runs `migrate:fresh` against the real dev/CI `qayd` database and the
> "migrate once" guard is per-process (**TD-05**). Parallel Pest runs will need a per-worker database.

## 8.4 Property-based tests for money

Example-based tests confirm the cases you thought of. Money bugs live in the cases you did not: the
value that rounds at the fourth decimal, the sum whose order changes the result, the negative-zero,
the amount at the `NUMERIC(19,4)` boundary.

The properties worth asserting over generated inputs:

- **Associativity / commutativity of summation** — the order of lines never changes the total.
- **Round-trip** — `string → NUMERIC(19,4) → string` is the identity for any in-range value.
- **Balance is preserved** — for any generated set of balanced legs, the projected
  `SUM(signed_base_amount)` is exactly `0.0000`.
- **Scale is never lost** — no operation produces more than 4 decimal places, or fewer.
- **Range is respected** — a value exceeding `NUMERIC(19,4)` fails *cleanly* (a 422), not as a raw 500.

> **Status: aspirational.** No property-based money tests exist today, and there is no `Money` value
> object — money is a bare `numeric-string` with bcmath at the call sites. **TD-12 #1** records the
> concrete gap: an out-of-range amount currently surfaces as a raw 500 rather than a clean 422.

## 8.5 Concurrency tests are first-class

In a ledger, concurrency bugs are not slowdowns — they are duplicate journal numbers, double-posted
entries, and lost updates. They must be tested, not reasoned about.

What needs a concurrency test:

- **Gapless numbering under parallel posts** — including the case where a random subset of
  transactions rolls back *after* allocating, and the surviving numbers must still be contiguous.
- **Double-post** — two simultaneous posts of the same entry produce exactly one posted entry, one
  number, and one ledger row per line.
- **Optimistic concurrency** — two edits with the same `version`: one succeeds, one gets 409, and the
  loser writes nothing.
- **GUC isolation under a real pooler** (§7.4) — the highest-severity one.

> **Status: aspirational.** `PostingEngineTest` proves idempotency *sequentially* (a second post of a
> posted entry is refused) but there is **no genuine concurrency suite**. Relatedly, the posting path
> currently takes `SELECT … FOR UPDATE` on the **fiscal-year row**, which serialises every concurrent
> post within a company-year. Serialisation is genuinely required only for gapless number allocation,
> which `JournalNumberAllocator` already achieves via `ON CONFLICT DO UPDATE` on the sequence row.
> Narrowing that lock is scheduled with the S2-07 seam rebind and **must ship with the concurrency
> tests**, not before them.

## 8.6 What "done" means

A story is done when **all** of these hold. Not most.

- [ ] The capability works end to end, for a real user, in the running app.
- [ ] Every new invariant is enforced in PostgreSQL and has a test proving the constraint rejects its
      violation — including against the owner connection where a trigger is the mechanism.
- [ ] Every new rule is in an Action, with a typed exception carrying a catalog code, and a test
      asserting both the code and the status.
- [ ] Every new tenant table passes the §5.5 checklist and has a two-company isolation test.
- [ ] `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse && ./vendor/bin/pest` — all green.
      (`make test` for all three codebases.)
- [ ] Anything deferred is in `TECH_DEBT.md` with a severity, a reason, and a planned resolution —
      MANIFEST Law 5. **A deferral you did not write down is a defect you introduced.**
- [ ] Docs that the change makes untrue are updated in the same PR.
- [ ] `PROJECT_STATUS.md` reflects reality if the story closed.

---

# 9. Performance philosophy

## 9.1 Measure first

QAYD's decision priority is **system health → architecture clarity → code quality → user experience →
development speed**. Performance sits inside health and UX — it is not a licence to break the ones
above it. An optimisation that weakens an invariant is not an optimisation; it is a defect with a
benchmark attached.

So: no optimisation without a measurement. `EXPLAIN (ANALYZE, BUFFERS)` the query, or count the
queries the endpoint issues. "This felt slow" is a reason to measure, never a reason to change code.

Correctness is not negotiable for speed. The zero-tolerance balance check re-derives totals from the
lines on every post rather than trusting the cached header. That is deliberate, it costs a query, and
it stays.

## 9.2 The specific traps in this system

**N+1 queries.** The classic shape, and one that will appear the moment reporting lands: loading each
line's account, or each entry's lines, inside a loop. Use `with()` eager loading, or collect ids and
issue one `whereIn`. `JournalEntryPostingService::assertPostableAccounts()` is the pattern to copy —
it collects distinct account ids and issues a single `pluck('status', 'id')` regardless of line count.

**Unbounded scans.** Ledger tables grow forever. Any query over `ledger_entries` or `journal_entries`
must be bounded by `company_id` **and** a date range or a fiscal year, and must ride an index. The
indexes exist for this: `idx_ledger_account_date (company_id, account_id, entry_date)`,
`idx_je_company_date`. A query without a date bound is a query that gets slower every day it is in
production and is fine in every test you write.

**Aggregate-on-read.** Computing a balance by summing an account's entire history on every read is the
single clearest scalability failure in accounting systems: opening an account screen aggregates
years. `signed_base_amount` already reduces a balance to one `SUM()`, which is a large improvement —
but a `SUM()` over the largest table in the system is still O(history).

**Indexes are `(company_id, …)`-prefixed.** Every tenant query filters on `company_id`; an index that
does not lead with it is largely useless.

**Aggregate in SQL, never in PHP.** Fetching rows to sum them in a loop is both slower and less
correct — PHP-side summation is exactly where precision discipline erodes.

## 9.3 When to denormalise

Only when three things hold:

1. You have **measured** the read cost and it is genuinely a problem.
2. The derived value has **exactly one writer**, in the same transaction as its source.
3. The derived value is **rebuildable from its source**, and you ship the rebuilder.

`ledger_entries` itself is the model: it duplicates data from `journal_lines`, has exactly one writer
(`JournalEntryPostingService`), is written in the same transaction as the post, and is a pure function
of the posted lines.

Every projection must ship with a **rebuild action and a drift check** run in CI and on a schedule. A
cached aggregate nobody verifies is a number that is quietly wrong. (01 → P19.)

## 9.4 Append-only makes rollups safe — the key insight

This is the most valuable performance property the architecture buys, and it is worth understanding
precisely.

A cached aggregate is only trustworthy if its source cannot change behind it. If the source table is
mutable, an incremental rollup must handle updates and deletes — which means it can drift, and once it
can drift you must periodically rebuild it, and once you rebuild it you no longer trust it between
rebuilds.

`ledger_entries` is **append-only, enforced by a trigger**. Therefore an `AFTER INSERT` trigger
maintaining a per-period balance table is **monotonic**: it can only ever increment. There is no
update path and no delete path to get wrong.

```
   Mutable ledger                          Append-only ledger (QAYD)
   ─────────────────                       ─────────────────────────
   INSERT → rollup +=                      INSERT → rollup +=
   UPDATE → rollup ±?  ← drift lives here      (no UPDATE path exists)
   DELETE → rollup −?  ← and here              (no DELETE path exists)
   ⇒ aggregate must be rebuilt to trust    ⇒ aggregate is correct by construction
```

The intended consequence: a trial balance becomes a few-thousand-row index scan on a period-balance
table instead of a full scan of the largest table in the system. That option exists **only because**
the ledger was made append-only — a correctness decision paying a performance dividend.

> **Status: not built.** `account_period_balances` and its rebuild/drift-check action are designed
> (see 05); the append-only property that makes them safe is real today.

---

# 10. Documentation philosophy

## 10.1 What must be written down

- **Every decision with a rejected alternative** → an ADR in `docs/architecture/adr/`. Not the
  decision alone — the *alternatives and the trade-off*. A decision without its rejected options is
  unreviewable later, because nobody can tell whether the constraint that drove it still holds.
- **Every deferral** → `TECH_DEBT.md`, with source story, severity, the decision taken, and the
  planned resolution. MANIFEST Law 5. Never in memory, never in a TODO comment.
- **Every invariant and why it exists** → the migration docblock and 01.
- **Every deliberate absence** → the "scope note" in the migration. *These columns are deferred because
  their target tables do not exist; adding them now would be building the future.*
- **Every non-obvious ordering, lock, or concurrency property** → the class docblock.
- **The current state of the project** → `PROJECT_STATUS.md`, updated when a story closes.

## 10.2 What must not

- **What the code does.** The code says that, and it stays true.
- **Anything you can assert instead.** A doc saying "every tenant table has RLS" is a wish; a CI query
  that fails the build is a fact. Prefer the mechanism. (01 → P20.)
- **Aspirational descriptions written in the present tense.** Writing "the system validates X" when it
  does not is worse than silence — it produces a reviewer who does not check, and an engineer who
  assumes.
- **Duplicated content.** Say it once, link to it. This document links to 01 rather than restating the
  principles precisely so they cannot diverge.
- **Speculative future design in a current-state doc.** That belongs in 05.

## 10.3 Where each kind of knowledge lives

| Knowledge | Home | Lifetime |
|---|---|---|
| Vision, the laws | `MANIFEST.md` | Permanent |
| Where the project is right now | `PROJECT_STATUS.md` | Updated every story |
| The stack (wins any conflict) | `docs/architecture/FINAL_TECH_STACK.md` | Frozen at `architecture-freeze-v1` |
| A decision + its rejected alternatives | `docs/architecture/adr/NNNN-*.md` | Immutable once accepted; superseded by a new ADR, never edited |
| Principles and their mechanisms | `01_ENGINEERING_PRINCIPLES.md` | Long-lived |
| How to work | This document | Long-lived |
| What this class does and why *this* way | Class docblock | Lives and dies with the class |
| A known gap | `TECH_DEBT.md` | Removed when resolved, with the resolving commit noted |
| A spec for a subsystem | `docs/{area}/*.md` | Updated when behaviour changes |

**ADRs are immutable.** A frozen doc is not edited. A real architecture change goes **new ADR → update
the governing doc → continue** — never the reverse (MANIFEST Law 1). ADR-0010 is the worked example:
a genuine conflict between two specs, resolved by a new ADR that names which one is authoritative.

## 10.4 Keeping docs true

**A stale doc is worse than no doc**, because it is believed. An absent doc makes someone read the
code; a wrong doc makes them confidently build on a falsehood.

Therefore:

- Doc updates ship **in the same PR** as the behaviour change. Not the next one.
- When code and a doc conflict, **the code is the fact** — but do not silently bend the code to the
  doc or the doc to the code. Ask which one is wrong, then fix that one deliberately.
- Cite specs from code (`docs/accounting/JOURNAL_ENTRIES.md "# Posting Engine"`), so a reviewer can
  check the pair and a drift becomes visible at review time.
- Prefer an executable assertion to a sentence, every time you can have one.

---

# 11. Review philosophy

## 11.1 What a reviewer is actually looking for

Not style — Pint decides style. Not type errors — PHPStan at level max decides those. A human reviewer
of financial code is answering four questions, in this order:

1. **Can this produce a wrong number?** Float arithmetic, a lost decimal, an unguarded rounding, a
   trusted cached aggregate, an aggregation in PHP.
2. **Can this leak or corrupt across tenants?** A raw query on the owner connection, a missing RLS
   block, a model without `BelongsToCompany`, a GUC set outside a transaction, a 403 where a 404
   belongs.
3. **Is the invariant enforced where it will still be true when this code is not running?** In
   PostgreSQL — or only in a PHP `if`?
4. **What happens under concurrency, and what happens on failure?** Is it one transaction? Is the event
   after commit? Does a partial failure leave partial state?

Everything else is secondary. A reviewer who spends their attention on naming and misses an
application-only invariant has reviewed the wrong thing.

## 11.2 The review checklist

**Money**
- [ ] Every monetary value is a `numeric-string`; every operation is `bcadd`/`bcsub`/`bcmul`/`bccomp`
      at scale 4. No `+ - * /`, no `==`, no `(float)`, no `round()`.
- [ ] New money columns are `NUMERIC(19,4)`; rates are `NUMERIC(18,6)`.
- [ ] Totals are re-derived from their source, never read from a cached column for a correctness
      decision.

**Tenancy**
- [ ] New tables: `company_id BIGINT NOT NULL REFERENCES companies(id)`, `ENABLE` + `FORCE` RLS, the
      restrictive boundary + four permissive per-verb policies.
- [ ] New models use `BelongsToCompany`.
- [ ] No raw `DB::table()` / `DB::select()` against a tenant table on the default (owner) connection
      (TD-01).
- [ ] There is a two-company isolation test, and a cross-tenant id returns **404**.

**Invariants**
- [ ] Anything that must always be true is a CHECK / FK / UNIQUE / EXCLUDE / trigger — not only a PHP
      check.
- [ ] The application check mirrors the constraint (for a clean 422) rather than replacing it.
- [ ] No bypass parameter, no `$force`, no `$skipValidation`.

**The ledger**
- [ ] Nothing writes a posted line except `JournalEntryPostingService`.
- [ ] No path edits or deletes posted data; corrections are new entries.
- [ ] Any new projection has exactly one writer, in the same transaction as its source.

**Structure**
- [ ] Business logic is in an Action. The controller validates, calls one Action, shapes the envelope.
      The model is thin.
- [ ] The Action takes a DTO or model, not a `Request`; it contains no HTTP concern.
- [ ] DTOs are `final readonly`.
- [ ] One `DB::transaction` on the tenant connection; events emitted **after** commit.

**Errors**
- [ ] Every failure is a typed `DomainException` with a stable catalog code and the right status
      (422 content / 409 state / 404 invisible / 403 forbidden).
- [ ] No raw exception, no `abort()` in an Action, no internals in any message.

**Tests**
- [ ] Constraints and triggers tested against real Postgres, including against the owner connection.
- [ ] Every exception factory has a test asserting code **and** status.
- [ ] Failure paths assert that **nothing** was written.
- [ ] `pint --test`, `phpstan analyse` (level max), `pest` — all green.

**Process**
- [ ] Nothing built ahead of the story's scope (MANIFEST Law 2). Deferred columns carry a scope note.
- [ ] Every deferral is in `TECH_DEBT.md`.
- [ ] Docs updated in the same PR.

## 11.3 Giving and receiving review

**Giving.**
- Say which category a comment is in: **blocking**, **should fix**, or **nit**. An unlabelled comment
  is read as blocking and wastes a cycle.
- Explain the failure, not the preference: "this sums money in PHP, which drifts and cannot be
  reproduced by the database" beats "use bcmath".
- Approve when it is *correct*, not when it is *how you would have written it*.
- If you cannot tell whether it is correct, say so and ask. "I don't understand why this lock is
  needed" is a valuable review comment; silence is not.

**Receiving.**
- A blocking comment on a financial invariant is not an opinion. Fix it or demonstrate it is wrong
  with evidence — a test, a constraint, a spec line.
- "It works" is not an answer to "what happens under concurrency". Neither is "that can't happen".
- If a comment reveals the code was hard to understand, the fix is usually the code or its docblock,
  not a reply in the thread.

## 11.4 When to block

**Block, without exception**, on:

- Float or native arithmetic on money.
- An invariant enforced only in application code.
- A missing RLS block, a missing `NOT NULL company_id`, or a missing `BelongsToCompany`.
- Any write to posted data, or any second path into the ledger.
- A bypass parameter, or a new ambient-privilege escape.
- A GUC set outside an explicit transaction, or with session scope.
- A new failure with no catalog code, or a raw exception escaping to the client.
- Any change to money, posting, or tenancy with no test.
- A red gate. `main` always builds and passes (MANIFEST Law 4).

**Do not block** on naming preference, file organisation within an established convention, a test you
would have written differently but which proves the property, or scope you wish had been included —
that is a new story, not a review comment.

---

# 12. Release philosophy

## 12.1 The gates

Identical locally and in CI, by design — "green locally" and "green in CI" must mean the same thing:

```
make test-api   →  pint --test        (format; Laravel default preset, no pint.json)
                   phpstan analyse    (larastan, level max, over app/)
                   pest               (unit + feature + arch + rls/isolation)

make test-web   →  packages build · lint · tsc --noEmit · vitest · i18n parity (en/ar)
make test-ai    →  ruff check · mypy --strict · pytest
```

`.github/workflows/ci.yml` fans these out into three parallel jobs plus a `summary` job that
aggregates them into one required status check for branch protection. The backend job runs against a
real **Postgres 16** service container and connects as `qayd_app`, so RLS is genuinely enforced in CI.

**PHPStan is at level max.** Do not lower it, and do not paper over a finding with a baseline entry or
a blanket ignore. A `mixed` you cannot narrow is usually telling you the data has no contract — see
`SqlRow` and `JournalEntryPostingService::money()` for how narrowing at the boundary is done here.

## 12.2 Branch discipline

- Work happens on a sprint branch (`sprint-02-accounting-core`); CI gates `main` and `sprint-**`.
- **Never break `main`** (MANIFEST Law 4). `main` always builds, passes its tests, and runs.
  Unfinished work stays on its branch.
- One story per PR. A PR that lands two stories cannot be reverted cleanly, and in a ledger system
  revert cleanliness is a safety property.
- Every sprint ends with a usable product, however small.
- The repo is a **single linear timeline** — one branch at a time, meaningful commits, so rollback is
  a real option rather than an archaeology project.

## 12.3 Migration safety — expand/contract

A migration and a deploy are not simultaneous. Between them, old code runs against the new schema
(and, during a rollback, new code against the old one). A single destructive migration guarantees a
window where one of those combinations is broken.

So any change to an existing table is **three deploys**:

```
  EXPAND                    MIGRATE                     CONTRACT
  ───────                   ───────                     ────────
  Add the new column        Backfill in batches.        Once no code reads the
  NULLABLE, no NOT NULL.    Dual-write old + new.       old column: drop it, add
  Old code unaffected.      Verify counts match.        NOT NULL / the FK.
       deploy 1                  deploy 2                   deploy 3
```

This is exactly how `ledger_entries.fiscal_period_id` is staged: present but **nullable with no FK**
today, with S2-07 adding the FK, backfilling, and making it `NOT NULL` (TD-13). The same shape was
used for `journal_entries.fiscal_year_id` in S2-03.

Rules:

- Never `DROP COLUMN` in the same deploy that stops writing it.
- Never add `NOT NULL` without a default or a completed backfill.
- Never rename — add, dual-write, migrate, drop.
- Backfill in **batches**, outside the DDL transaction, with progress visible.
- An index on a large table is `CREATE INDEX CONCURRENTLY` (and therefore outside a transaction).
- **Test `down()`.** A migration you cannot reverse is a deploy you cannot roll back.

## 12.4 Rollback

**Code rolls back. Data does not.** A revert un-deploys the binary; it cannot un-post a journal entry,
and it must not — that would be mutating history.

Therefore:

| Situation | Action |
|---|---|
| Bug found before any posting used the path | Revert the code. Roll the migration back if it was expand-only. |
| Bug found after entries were posted | Revert the code. **Correct the data with reversing entries through the normal posting path** — never a manual `UPDATE`. |
| A migration is mid-backfill | Do not revert the schema; the expand step is designed to be safe with old code. Fix forward. |
| RLS or tenancy is implicated | §7.6 — incident, not rollback. |

Anything that touches posted data has a written, reviewed remediation plan **before** it runs, and the
remediation itself goes through `PostJournalEntryAction`.

## 12.5 What makes a change risky here

| Risk | Change | Required |
|---|---|---|
| **Critical** | Anything in the posting path, the ledger schema, RLS policies, the runtime role, or the GUC lifecycle | Architecture-owner review; tests before code; a concurrency argument in the PR description; a data-remediation plan |
| **High** | New tenant table; new money column; new Action that writes; auth/permission changes | Full §11.2 checklist; two-company isolation test; explicit failure-path tests |
| **Medium** | New read endpoint; new projection column; index changes | Envelope test; `EXPLAIN` for any new query over a growing table |
| **Low** | Copy, formatting, docs, additional tests | Gates green |

The correlation to keep in mind: **risk is proportional to how far the effect is from where the change
is.** A posting-path change affects every future financial fact in every tenant. That is why it gets
the most scrutiny per line of any code in the repository.

---

# 13. The QAYD engineer's checklist

One page. Run it against your own PR before asking anyone to look.

```
╔══════════════════════════════════════════════════════════════════════════════╗
║  BEFORE I OPEN THE PR                                                        ║
╠══════════════════════════════════════════════════════════════════════════════╣
║  SCOPE                                                                       ║
║  □ Implements exactly this story — nothing ahead of it (Law 2)               ║
║  □ Deferred things carry a scope note saying WHY they are deferred           ║
║  □ Every "fix later" is in TECH_DEBT.md with severity + planned resolution   ║
║                                                                              ║
║  MONEY                                                                       ║
║  □ NUMERIC(19,4) columns; numeric-string in PHP; bcmath at scale 4           ║
║  □ No float, no + - * /, no ==, no round() on money                          ║
║  □ Totals re-derived from source, never trusted from a cache                 ║
║                                                                              ║
║  TENANCY                                                                     ║
║  □ company_id BIGINT NOT NULL REFERENCES companies(id)                       ║
║  □ ENABLE + FORCE RLS; restrictive boundary + 4 permissive per-verb policies ║
║  □ Model uses BelongsToCompany                                               ║
║  □ No raw DB:: on the owner connection against a tenant table                ║
║  □ Two-company isolation test; cross-tenant id → 404 (never 403)             ║
║  □ Any GUC is SET LOCAL, inside an explicit transaction                      ║
║                                                                              ║
║  INVARIANTS                                                                  ║
║  □ Every "must always be true" is a CHECK / FK / UNIQUE / EXCLUDE / trigger  ║
║  □ App-layer check MIRRORS the constraint (clean 422); does not replace it   ║
║  □ No bypass parameter anywhere ($force / $skip / $unsafe)                   ║
║  □ Nothing writes a posted line except JournalEntryPostingService            ║
║  □ No edit/delete path to posted data; correction is a reversing entry       ║
║                                                                              ║
║  STRUCTURE                                                                   ║
║  □ Logic in an Action; controller validates + calls one Action + envelopes   ║
║  □ Model is thin (table, casts, relations, constants, trait)                 ║
║  □ Action takes a DTO/model, no HTTP; DTOs are final readonly                ║
║  □ One DB::transaction on the tenant connection                              ║
║  □ Domain events emitted AFTER commit                                        ║
║  □ Volatility boundary crossed only through a named seam interface           ║
║                                                                              ║
║  ERRORS                                                                      ║
║  □ Typed DomainException, stable catalog code, correct status                ║
║    422 content · 409 state · 404 invisible · 403 forbidden                   ║
║  □ Named factories; caller-safe messages; no internals leaked                ║
║                                                                              ║
║  TESTS                                                                       ║
║  □ Constraints/triggers proven against REAL Postgres (owner conn. too)       ║
║  □ Every exception factory: code AND status asserted                         ║
║  □ Failure paths assert NOTHING was written                                  ║
║  □ Concurrency considered; if it matters, it is tested                       ║
║  □ pint --test · phpstan (max) · pest — green. make test if cross-cutting.   ║
║                                                                              ║
║  DOCS                                                                        ║
║  □ Class docblock: what, why this way, ordering, locks, spec citation        ║
║  □ Docs this change makes untrue are fixed in THIS PR                        ║
║  □ New decision with a rejected alternative → an ADR                         ║
║  □ PROJECT_STATUS.md updated if the story closed                             ║
╚══════════════════════════════════════════════════════════════════════════════╝
```

---

# 14. Common mistakes

The most-read section. Each entry: the symptom you will see, why it is wrong here specifically, and
the fix.

### 1. Doing money arithmetic in PHP

**Symptom.** `$total = $a + $b;` · `if ($debit == $credit)` · `round($x, 2)` · a `float` type hint on
a money parameter.
**Why wrong.** PHP floats are IEEE-754 binary: `0.1 + 0.2 !== 0.3`. Summed across thousands of lines
the drift becomes a trial balance that is off by fils and cannot be explained to an auditor. It also
diverges from what `NUMERIC(19,4)` stores, so PHP and Postgres disagree about the same number.
**Fix.** `bcadd($a, $b, 4)`, `bcsub`, `bcmul`, and `bccomp($a, $b, 4) === 0` for equality. Type as
`numeric-string`. Narrow values read from the database at the boundary — see
`JournalEntryPostingService::money()`, which throws rather than silently coercing a non-numeric value.

### 2. Putting business logic in the model

**Symptom.** A `boot()` hook that validates, an accessor that computes a business value, a static
`Model::doTheThing()`.
**Why wrong.** Model hooks fire on *every* write from every context — including seeds, backfills, and
tests — so the rule is unavoidable where it does not belong and invisible where it does. It is also
untestable without a database, and impossible to call deliberately from a queue job.
**Fix.** Move it to an Action. Models hold table name, casts, relations, constants, and
`BelongsToCompany`. Compare `JournalEntry` — 8 lines of logic, all of it constants.

### 3. Putting business logic in the controller

**Symptom.** An `if` in the controller deciding whether an operation is allowed; a controller
touching two Actions and reconciling them.
**Why wrong.** A rule in a controller does not exist for the queue, the console, an event listener, or
the AI proposal path. It will be re-implemented — differently — the first time a second caller
appears.
**Fix.** One controller method → one Action. `AccountController` is the reference: every rule is in the
Action, and the controller does resolution, presentation, and the envelope.

### 4. Writing to the ledger outside `PostingService`

**Symptom.** A new module inserting into `ledger_entries` or flipping `journal_entries.status` to
`posted` directly, "because our case is simpler".
**Why wrong.** The posting service is not a helper; it is the ordered enforcement of five invariants
(balance, open period, active accounts, permanent numbering, exact projection). A second path skips
some of them — and it is never obvious which, or that it happened.
**Fix.** Call `PostJournalEntryAction`. If it does not fit your case, the missing capability belongs
*in* the posting path, discussed with the architecture owner. There is exactly one way in.

### 5. A raw query against a tenant table on the owner connection

**Symptom.** `DB::table('journal_entries')->…` or `DB::select('SELECT … FROM accounts …')` in
application code.
**Why wrong.** The default connection is the **schema owner**, which bypasses RLS. Eloquent tenant
models are safe (`BelongsToCompany` binds them to `pgsql_app`), but a raw call on the default
connection is **not tenant-scoped** and will happily return every company's rows. This is **TD-01**,
open today.
**Fix.** Use the model, or explicitly
`DB::connection(TenantContext::connection())->…`. In tests, use `TenantHarness::app()` when you mean
"as the application" and `TenantHarness::owner()` only when you mean "what really exists in the table".

### 6. `SET` instead of `SET LOCAL`, or a GUC outside a transaction

**Symptom.** `DB::statement("SET app.current_company_id = …")`, or `set_config(name, value, false)`,
or the local form outside an explicit transaction.
**Why wrong.** Session-scoped GUCs survive the request. Under PgBouncer transaction pooling the
connection is handed to another request carrying the previous tenant's company id — a silent
cross-tenant breach with no error and no log. Outside a transaction the local form silently degrades
to session scope.
**Fix.** `set_config($name, $value, true)` inside a transaction you opened, exactly as
`ResolveTenantCompany` does. This applies to queue jobs and console commands too — that is where it
gets forgotten.

### 7. Enforcing an invariant only in PHP

**Symptom.** A validation rule with no matching constraint; a comment saying "we always ensure X".
**Why wrong.** PHP only runs when your code runs. It does not run for a backfill, a console command,
a psql session, a future module, or a queue worker someone writes next year. An invariant with a gap
is not an invariant.
**Fix.** Add the constraint (§5.2), then keep the PHP check as a mirror so the caller gets a clean 422
instead of a raw `QueryException`. Both. Not either.

### 8. Adding a bypass flag

**Symptom.** `execute(..., bool $skipValidation = false)`, `$force = true`, a context key that
disables a check "for imports".
**Why wrong.** An invariant a caller can switch off is not an invariant — it is a suggestion with a
default. The flag will be set somewhere for a good reason, and then copied somewhere for a bad one,
and there is no way to find out which call sites are which.
**Fix.** If the strict rule genuinely cannot hold for a real case, the *rule* is wrong: model that
case explicitly (an acknowledged suspense line, an audited exception record with a reason and an
expiry) rather than adding an off switch. (01 → P2; 04 for the rejected shapes.)

### 9. Trusting a cached total

**Symptom.** Reading `total_debit` / `total_credit` off the header to decide whether an entry
balances; summing a stored balance column to build a report.
**Why wrong.** A cached aggregate is a read convenience. Basing a *correctness* decision on it means a
bug that wrote a wrong cache becomes a bug that posts an unbalanced entry.
**Fix.** Re-derive from the source, as `assertBalanced()` does. Note the deliberate corollary in
`CreateJournalEntryAction`: draft header totals stay at zero throughout the draft lifecycle and are
never synced to a possibly-unbalanced line sum — the posting engine sets them from the balanced lines.

### 10. Emitting the event inside the transaction

**Symptom.** `event(...)` inside `DB::transaction(...)`.
**Why wrong.** Subscribers react to a fact that may still roll back: an email is sent, a webhook
fires, another module posts a consequential entry — for something that never happened.
**Fix.** Emit after commit. `PostJournalEntryAction` is the pattern: the service is pure DB work, and
the Action emits `JournalEntryPosted` only once `post()` has returned.

### 11. Throwing a generic exception

**Symptom.** `throw new \Exception('Entry is not balanced')` · `abort(422, '...')` inside an Action.
**Why wrong.** A generic exception renders as `INTERNAL_ERROR` 500 with a fixed message — the caller
learns nothing and the client cannot branch. An `abort()` message is not a stable code, so no client,
SDK, or AI agent can react to it programmatically.
**Fix.** A typed `DomainException` subclass with a named factory, a catalog code, and a status. See
`PostingRuleException`.

### 12. Adding a tenant table without the full pattern

**Symptom.** A new table with `company_id` but no `FORCE`, or missing the restrictive policy, or a
model without `BelongsToCompany`, or — worst — a **nullable** `company_id`.
**Why wrong.** A nullable `company_id` is invisible to an equality predicate, so such a row escapes
the boundary rather than being contained by it. `ENABLE` without `FORCE` leaves the owner exempt. A
missing restrictive policy means a later permissive policy can OR past the boundary.
**Fix.** Work §5.5 as a literal checklist. `BelongsToCompanyArchTest` will fail the build for the
model half; the rest is on you and your reviewer until the catalog-introspection check exists.

### 13. Mutating posted data, or wanting an "un-post"

**Symptom.** An `UPDATE` on a posted entry; a status flip from `posted` back to `draft`; "just fix the
amount, it was a typo".
**Why wrong.** Posted history is what an audit verifies against. If it can change, every statement you
ever produced is provisional and no fraud is detectable. The database will refuse you anyway —
`trg_journal_lines_no_update_when_posted` and `trg_ledger_entries_append_only` fire even for the owner
role.
**Fix.** Post a reversing entry, then a corrected one. The original stays posted forever with its
number and its ledger rows. There is no un-post path and there will not be one.

### 14. Silently correcting the user (or the AI)

**Symptom.** Defaulting a missing exchange rate to 1.0; inserting a plug line to force a balance;
nudging a posting date forward because the period is locked; rounding away a residual.
**Why wrong.** Each of these silently changes a financial fact, and each has produced real defects in
shipped accounting software. A missing rate that converts at par is invisible until a foreign-currency
balance is inexplicable. A silent date shift means the accounting date is decided by a numbering
policy. An auto-plug absorbs exactly the data-quality signal you needed to see.
**Fix.** Raise a typed exception with the residual/context in `meta`. Where a resolution is legitimate,
return it as a DTO the caller must **explicitly accept** — never apply it yourself. (01 → P14.)

### 15. Testing accounting or RLS on the wrong database

**Symptom.** A tenancy or constraint test using the default sqlite `:memory:` connection, or
`RefreshDatabase`, or asserting isolation with a single company.
**Why wrong.** sqlite has no RLS, no `EXCLUDE`, no plpgsql triggers, and different numeric semantics —
so the test passes while proving nothing. A single-company test cannot detect a leak, because there is
nowhere to leak *to*.
**Fix.** `TenantHarness::boot()`, two `seedCompany()` calls, work inside `runInTenant()`, and assert
both directions: mine is visible, theirs is not. Note that `RefreshDatabase` is deliberately commented
out in `tests/Pest.php`.

### 16. Fixture scope confusion in `runInTenant`

**Symptom.** "My test passes in isolation but the row disappears", or an assertion after
`runInTenant()` finds nothing.
**Why wrong.** `runInTenant` wraps the closure in a transaction it rolls back for isolation. Anything
written inside vanishes; anything asserted outside was never committed.
**Fix.** Arrange durable fixtures on `TenantHarness::owner()` (which bypasses RLS and commits), and
assert post-effects **inside** the same closure. `PostingEngineTest` shows both patterns —
`peSeedPostedLedgerRow` for durable fixtures, `pePostHere` for in-context assertions.

### 17. Building the future

**Symptom.** Adding a column, table, interface, or abstraction for a requirement that is certain but
not current. "We will need `cost_center_id` eventually, so I added it."
**Why wrong.** MANIFEST Law 2. An empty column ahead of a source that can fill it is a column nobody
can validate, index correctly, or constrain — and it silently becomes part of the contract. A
one-implementation abstraction with no named successor is indirection, not architecture.
**Fix.** Build the story's scope. Record the deferral as a **scope note** in the migration docblock
(the house style — see any S2-03/S2-05 migration) and, where it is a real gap, in `TECH_DEBT.md`.
Introduce a seam only when you can name its successor, as `FiscalCalendarResolver` names S2-07.

### 18. Not writing the deferral down

**Symptom.** "I'll fix that in the next story." A `// TODO` with no owner. A known gap discussed in a
review thread and nowhere else.
**Why wrong.** MANIFEST Law 5 exists because undocumented debt becomes invisible debt, and invisible
debt in a financial system is an invariant nobody knows is unenforced. `TECH_DEBT.md` is also what
tells the next engineer that a limitation was *decided* rather than *missed* — TD-13's honest
"fiscal-YEAR granular, not fiscal-PERIOD granular" is worth more than a clean-looking file.
**Fix.** One row in `TECH_DEBT.md`: id, source story, severity, the decision taken, the planned
resolution. In the same PR.

---

*This document describes QAYD as it is on 2026-07-28 — Sprint 2, S2-01 through S2-05 landed. Where it
describes something as aspirational or not built, that is the current truth, not modesty. When it
stops being true, fix this file in the same PR that changes the behaviour.*

# End of Document
