# 03 — Design Patterns

**QAYD's reusable pattern catalogue.** Version 1.0 · 2026-07-28 · Status: **binding for new modules**

---

## What this document is

A **pattern language**, not a tutorial. Every financial subsystem QAYD builds after this document — tax,
reconciliation, payments, fixed assets, budgeting, consolidation, payroll — is assembled from these
nineteen patterns. A module that needs a mechanism not described here is either doing something genuinely
novel (write the pattern, then the code) or reinventing something already solved (find it below).

The patterns are drawn from three sources: **the code that already ships** in `apps/api` (the strongest
source — a pattern with a working reference implementation has been argued with a compiler), the
**Phase 1 research** in `docs/research/odoo/`, and the **principles** in `01_ENGINEERING_PRINCIPLES.md`.
This document elaborates `01`; it may add detail, it may not contradict it.

### Precedence

```
MANIFEST.md                             vision, laws, decision priority
   └── 01_ENGINEERING_PRINCIPLES.md     why we build this way
         └── 03_DESIGN_PATTERNS.md      ← you are here (what to reach for, and how)
               └── code                 the only thing that actually runs
```

A pattern here is a *default*, not a law. Deviating is allowed; deviating silently is not. If you do not
apply a pattern the decision tree points you at, say so in the PR description and say why.

---

## The provenance rule

**No pattern is in this catalogue because another system does it.** Odoo was studied for twenty years of
consequences, not for designs to copy — and the most valuable output of that study was the *rejected*
list, because most accumulated cost sits in patterns that looked reasonable and compounded badly.

Every pattern below had to earn its place against a property QAYD actually has. Where QAYD's existing
design was already stronger, the pattern records *keep what you have* rather than manufacturing a change.

| Pattern | The QAYD property that demands it | What it deliberately rejects |
|---|---|---|
| P-01 Posting | One ledger, many future producers (invoices, payroll, FX, AI drafts) | A god posting method; a "fast path" for bulk writes |
| P-02 Ledger Projection | Append-only ledger + GCC audit posture | Reconciliation state stored on the ledger row |
| P-03 Seam | A dated migration (fiscal year → period) and a named second market (storno) | Interfaces added on suspicion; abstractions designed against imagined requirements |
| P-04 Approval | "AI drafts, humans dispose" is the product thesis | Four-eyes enforced only in application code |
| P-05 Validation | An AI agent is a first-class author of drafts | Newline-joined error strings; N round trips |
| P-06 Concurrency | Drafts are edited by humans over minutes | Check-then-update without a guarded `WHERE` |
| P-07 Number Allocation | GCC audit requires gapless numbering | Sequences and cached ranges that gap on rollback |
| P-08 Locking | The posting path is the hottest write path in the system | Locking a calendar row that cannot change during a post |
| P-09 RLS | One database, many tenants, many entry points | Application-layer row filtering; any `sudo()` equivalent |
| P-10 Audit | Someone will be asked to prove this in an audit | An audit table application code can clear |
| P-11 Event | Modules must stay independently replaceable | Cross-module coupling by inheritance or direct table writes |
| P-12 AI Action | An LLM has write access to nothing, by construction | Confidence thresholds used as permissions |
| P-13 Immutability & Correction | A posted fact has been relied upon | Un-posting by state flip; `force_delete` parameters |
| P-14 Money | Fils-exact filings carry legal liability | Float money and epsilon compensation |
| P-15 Read Model | Trial balance over the largest table in the system | Full-history replay as a primary read path |
| P-16 Dimension Rows | A fourth analytic axis must not be a migration | Fixed FK columns *and* JSONB distribution |
| P-17 Explicit Resolution | A silently corrected financial value is unfindable later | Defaulting a missing rate; shifting a locked date |
| P-18 Lifecycle Map | Eight statuses already exist and Actions are multiplying | Transition rules re-derived at six call sites |
| P-19 Declared Config | Several GCC VAT regimes; an AI that must propose reviewable things | Formula strings, `eval`, runtime DDL, security-as-data |

---

## How to read a pattern

Every pattern has the same eleven parts.

| Part | What it answers |
|---|---|
| **Intent** | One sentence. Quotable in a design review. |
| **Use when / Do not use when** | The boundary. A pattern that always applies is not a pattern. |
| **Structure** | The shape, as a diagram. |
| **Participants** | The actual tables, Actions, DTOs, exceptions and events. |
| **Mechanics** | How it works, step by step. |
| **Invariants it guarantees** | And **where** each is enforced, on the enforcement ladder. |
| **Reference implementation** | The file in this repository that demonstrates it — or an honest "none yet". |
| **Worked example** | A second, different use, showing the pattern generalizing. |
| **Failure modes** | What goes wrong when it is applied badly. |
| **Testing strategy** | What a correct implementation must *prove*. |
| **Effort · Confidence** | Fibonacci points to apply; High/Medium/Low with the reason. |

**Enforcement ladder** (from `01_ENGINEERING_PRINCIPLES.md`, repeated because every "Invariants" section
uses it):

```
STRONGEST   database constraint / trigger / GRANT   cannot be violated, by anyone, ever
            CI check (fails the build)              cannot be merged
            architecture test                       cannot be merged, and explains itself
            static analysis                         cannot be merged, mechanically
            Action-layer guard                      good errors; not a guarantee
WEAKEST     documentation                           will be forgotten under deadline
```

An invariant whose strongest enforcement is "Action-layer guard" is a **courtesy**, not a guarantee, and
the pattern says so explicitly wherever that is the case.

---

## Maturity levels

| Level | Meaning |
|---|---|
| **Proven** | Implemented and tested in `apps/api`. Copy the reference implementation. |
| **Partial** | The shape ships; a named piece is missing. The gap is stated in the pattern. |
| **Specified** | Designed in Phase 1 research, not built. Effort figures are estimates. |
| **Decided** | A decision recorded *before* the code exists, to prevent an expensive mistake. |

---

## Pattern index

| # | Pattern | Intent (short) | Use when | Maturity |
|---|---|---|---|---|
| P-01 | **Posting** | One authorized write path into the ledger | A subsystem produces ledger facts | Proven |
| P-02 | **Ledger Projection** | Append-only rows + one signed amount ⇒ balance is a `SUM` | A quantity accumulates and must be auditable | Proven |
| P-03 | **Seam** | An interface isolating a subsystem that will be replaced | You can *name* the second implementation | Proven |
| P-04 | **Approval** | draft → submit → approve → post, four-eyes, SoD | The operation is irreversible or expensive | Partial |
| P-05 | **Validation** | Aggregate every violation into one structured response | Input is authored in bulk or by a machine | Partial |
| P-06 | **Concurrency** | Optimistic `version` guard + precise failure diagnosis | A mutable row is edited over human time | Proven |
| P-07 | **Number Allocation** | Gapless, per-scope, concurrency-safe identifiers | An auditor will infer that nothing is missing | Proven |
| P-08 | **Locking** | Serialize the minimum; prefer a constraint to a lock | Two transactions interleave into an illegal state | Partial ⚠️ |
| P-09 | **RLS** | The tenant boundary is a storage-engine property | The table has `company_id` — i.e. always | Proven |
| P-10 | **Audit** | Append-only, three tiers, hash-chained where it counts | Always | Partial |
| P-11 | **Event** | After-commit domain events + outbox as the only seam | Another module must react to a fact | Partial |
| P-12 | **AI Action** | AI writes only to proposal tables, enforced by GRANTs | Any AI output that would become financial data | Partial |
| P-13 | **Immutability & Correction** | Reverse, never mutate; acyclic and non-over-reversing | The record has been relied upon | Partial |
| P-14 | **Money** | bcmath strings end to end, exact comparison, deterministic pennies | Any monetary quantity | Proven |
| P-15 | **Read Model** | Derived, rebuildable projections with a drift detector | The source query is too slow | Specified |
| P-16 | **Dimension Rows** | Classification as child rows with real FKs | An axis set will grow; allocations split | Decided |
| P-17 | **Explicit Resolution** | Never substitute; return a resolution the caller must accept | A request is *nearly* valid | Specified |
| P-18 | **Lifecycle Map** | Transitions declared once, mirrored by a trigger | >3 states or >1 state-changing Action | Specified |
| P-19 | **Declared Config** | Typed rows compiled through an allowlist, never `eval` | Variants must ship without a release | Specified |

⚠️ P-08 is **Partial** because the shipped code contains a known misapplication of it. That misapplication
is the pattern's teaching example.

---

## Decision tree — "I am building a new financial subsystem"

Walk this top to bottom. It is deliberately not exclusive: most subsystems collect six to ten patterns.

```
START — a new financial subsystem
  │
  ├─ Does it store tenant data?  ─────────────────── yes ─► P-09 RLS         (MANDATORY)
  ├─ Does it store money?        ─────────────────── yes ─► P-14 Money       (MANDATORY)
  ├─ Will anyone ever audit it?  ─────────────────── yes ─► P-10 Audit       (MANDATORY)
  │
  ├─ Does it write to the general ledger?
  │     yes ─► P-01 Posting  ── it is the ONLY way in; do not build a second
  │             ├─ a human or auditor reads the identifier? ──► P-07 Number Allocation
  │             └─ balances are queried?                    ──► P-02 Ledger Projection
  │
  ├─ Is a record final once created?
  │     yes ─► P-13 Immutability & Correction   (correct by reversal, never by UPDATE)
  │     no  ─► P-06 Concurrency                 (version column + guarded UPDATE)
  │
  ├─ More than 3 states, or more than 1 Action that changes state?
  │     yes ─► P-18 Lifecycle Map
  │             └─ must a *different human* authorize a transition? ─► P-04 Approval
  │
  ├─ Does an AI produce any of its data?
  │     yes ─► P-12 AI Action        (proposal tables + GRANTs)
  │             └─ and P-17 Explicit Resolution — an agent must never be silently corrected
  │
  ├─ Does it accept authored, bulk, or machine-generated input?
  │     yes ─► P-05 Validation       (aggregate all violations, one response)
  │
  ├─ Is the natural query too slow over the source of truth?
  │     yes ─► affordable as a VIEW? ── yes ─► use the VIEW (zero writers, zero drift)
  │                                     no  ─► P-15 Read Model (+ rebuilder + drift detector)
  │     no  ─► do NOT pre-aggregate. Speculative denormalisation is a liability.
  │
  ├─ Must another module react to what happens here?
  │     yes ─► P-11 Event            (never a direct write into another module's tables)
  │
  ├─ Does it classify facts along axes?
  │     axes will grow / allocations split ─► P-16 Dimension Rows
  │     closed, exclusive, total           ─► a NOT NULL CHECK-constrained column (not a pattern)
  │
  ├─ Must customers or jurisdictions add variants without a release?
  │     fewer than two concrete cases exist ─► STOP. Build the two cases first.
  │     two or more exist                   ─► P-19 Declared Config
  │
  ├─ Will a dependency's granularity or policy change on a known date?
  │     can you NAME the second implementation? ── yes ─► P-03 Seam
  │                                                no  ─► do not add the seam
  │
  └─ Do two concurrent transactions interleave into an illegal state?
        can a constraint express it?  ── yes ─► use the constraint (cheaper, unforgettable)
                                         no  ─► P-08 Locking (minimum scope, fixed order)
```

**Two shortcuts worth memorising.** If the answer to *"can a constraint say it instead?"* is yes, it is
never a lock and never an Action guard. If the answer to *"can I name the second implementation?"* is no,
it is never a seam.

---

# The patterns

---

## P-01 — Posting Pattern

**Intent** — Every write of a posted financial fact passes through exactly one service, in one
transaction, that enforces every ledger invariant and projects the result; there is no second way in.

**Use when** — a subsystem produces facts that must land in the general ledger: invoices, bills,
payments, payroll, depreciation, FX revaluation, opening balances, reversals, approved AI drafts.

**Do not use when** — the write is not a ledger fact. Drafts, proposals, configuration, read models and
audit rows go through ordinary Actions. Wrapping a non-ledger write in the posting engine buys nothing
and costs the sequence-row lock.

### Structure

```
 caller: HTTP controller │ queue job │ event listener │ console command │ approved AI draft
         (none of them may write journal_entries.status or ledger_entries directly)
                │
                ▼
 ┌─────────────────────────────────────┐
 │  PostJournalEntryAction             │   thin orchestration + after-commit event
 └───────────────┬─────────────────────┘
                 ▼   ONE DB::transaction on pgsql_app (RLS-enforced, NOBYPASSRLS role)
 ┌──────────────────────────────────────────────────────────────────────┐
 │ JournalEntryPostingService                                           │
 │   1  SELECT … FOR UPDATE on the header; re-read status under the lock│
 │   2  re-derive balance FROM THE LINES  (bcmath, zero tolerance,      │
 │        both entry currency AND base currency)                        │
 │   3  resolve the open period      ◄── FiscalCalendarResolver  (seam) │
 │   4  assert every targeted account is active                         │
 │   5  allocate the permanent number ◄── JournalNumberAllocator        │
 │   6  mark posted + project ONE ledger_entries row per line           │
 └───────────────┬──────────────────────────────────────────────────────┘
                 ▼  COMMIT      (any throw ⇒ full rollback ⇒ nothing partial is ever visible)
        event JournalEntryPosted        ── emitted only after the commit returns
```

### Participants

| Kind | Name |
|---|---|
| Tables | `journal_entries`, `journal_lines`, `ledger_entries`, `journal_number_sequences` |
| Action | `PostJournalEntryAction` |
| Service | `JournalEntryPostingService` (the single authorized writer) |
| Seams | `FiscalCalendarResolver` → `ResolvedFiscalPeriod` (P-03) |
| Collaborator | `JournalNumberAllocator` (P-07) |
| Exceptions | `PostingRuleException`, `UnbalancedEntryException`, `ClosedPeriodException` |
| Event | `JournalEntryPosted` (P-11) |

### Mechanics

```
caller          Action            Service                DB
  │  execute()    │                 │                     │
  ├──────────────►│  post()         │                     │
  │               ├────────────────►│  BEGIN              │
  │               │                 ├────────────────────►│
  │               │                 │  FOR UPDATE header  │  ← serializes duplicate post
  │               │                 ├────────────────────►│    and any concurrent draft edit
  │               │                 │  status ∈ postable? │
  │               │                 │  SUM lines (bcmath) │  ← never the cached header totals
  │               │                 │  resolve period     │  ← seam
  │               │                 │  accounts active?   │
  │               │                 │  allocate number    │  ← LAST: shortest lock hold
  │               │                 │  UPDATE header      │
  │               │                 │  INSERT ledger × n  │
  │               │                 │  COMMIT             │
  │               │◄────────────────┤                     │
  │               │  event(...)     │                     │  ← AFTER commit, never inside
  │◄──────────────┤                 │                     │
```

Five decisions in that sequence are load-bearing and must be preserved in any re-implementation:

1. **The header lock comes first.** It makes the whole post serial *per entry*, which is what makes a
   duplicate post an error rather than a double projection, and what stops a draft edit landing between
   the balance check and the projection.
2. **The balance is re-derived from the lines, never read from the header.** Cached header totals are an
   optimisation; trusting them at posting time means trusting whatever last wrote them.
3. **Zero tolerance, in both currencies.** There is no epsilon because there is no float (P-14). A
   tolerance here would not add robustness; it would hide a defect in the line writer.
4. **The calendar is reached through a seam, never queried directly.** The posting engine contains no
   reference to `fiscal_years`; refining the calendar from year to period changes one binding (P-03).
5. **The number is allocated last.** The sequence row is the one genuinely contended object in the
   system (P-07, P-08); allocating it after all validation means a failing post never holds it, and a
   succeeding post holds it for microseconds rather than for the whole validation pass.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| Debits equal credits in both currencies | `assertBalanced()` + `chk_je_balanced`, `chk_je_base_balanced` | DB CHECK |
| Exactly one ledger row per posted line | `uq_ledger_entries_journal_line` UNIQUE | DB constraint |
| A posted entry is never mutated | `trg_journal_lines_no_update_when_posted`, `trg_ledger_entries_append_only` | DB trigger |
| A post is idempotent (re-post projects nothing) | header `FOR UPDATE` + status re-read; UNIQUE as backstop | DB + Action |
| No posting into a non-open period | `FiscalCalendarResolver` (Action-layer today; a lock-cursor trigger when `fiscal_locks` lands) | Action ⚠️ |
| No posting to an inactive account | `assertPostableAccounts()` | Action ⚠️ |
| An AI-generated entry cannot be created posted | `trg_no_ai_autopost` | DB trigger |
| A partial post is never visible | one `DB::transaction` | DB |

⚠️ marks the two invariants whose strongest current enforcement is the Action layer. Both are courtesies
until the corresponding database mechanism lands, and both should be read as open work, not as guarantees.

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/app/Services/Accounting/JournalEntryPostingService.php`
- `/Users/ali/projects/qayd-os/apps/api/app/Actions/Accounting/PostJournalEntryAction.php`
- `/Users/ali/projects/qayd-os/apps/api/tests/Feature/Accounting/PostingEngineTest.php`

### Worked example — unrealized FX revaluation

`RevalueForeignBalancesAction` (statutory under IAS 21; a Kuwait filing requirement) must, at period end,
compare each monetary account's carrying base amount against its foreign balance revalued at the closing
rate and post the delta to unrealized gain/loss.

It does **not** get a posting path. It builds a `JournalEntryData`, creates a draft through
`CreateJournalEntryAction`, and posts through `PostJournalEntryAction`. In exchange it inherits, with no
new code: the balance check, the period gate, gapless numbering, the ledger projection, the audit trail,
the after-commit event, and its own reversibility — the automatic day-one reversal in the next period is
simply another post through the same path.

That is the pattern's whole economic argument. The posting engine cost 8 points once; every subsequent
document type that reuses it costs 2.

### Failure modes

- **A "fast path" for bulk import** that writes `journal_entries` and `ledger_entries` directly. It will
  be justified by a benchmark and it will silently skip every invariant in the table above. Batch inside
  the pattern instead (chunked balanced posts, one transaction per chunk).
- **The service becoming a god method.** Odoo's equivalent performs access control, business validation,
  date mutation, analytic-line creation, reconciliation repair, partner statistics and a marketing hook
  in one function, so numbering cannot be tested independently of invoice validation. Keep new
  responsibilities in collaborators behind seams; the service orchestrates, it does not accumulate.
- **Emitting the event inside the transaction.** Subscribers then react to facts that may roll back.
- **Catching a rule exception and retrying with validation relaxed.** There is no relaxed mode, and
  adding one violates `01`/P2 (no invariant has an off switch).
- **Passing the entry as a mutable model and letting a caller change it mid-post.** The service re-reads
  authoritative state under the lock precisely so this cannot matter — do not undo that.

### Testing strategy

A correct implementation must prove:

1. An unbalanced entry is refused — separately in entry currency and in base currency.
2. A closed/absent period is refused; an inactive account is refused; an empty entry is refused.
3. A second post of a posted entry raises `409` and projects **zero** additional ledger rows.
4. Any failure leaves zero ledger rows and the entry still in its pre-state (rollback proof).
5. `COUNT(ledger_entries) == COUNT(journal_lines)` for the entry, and `SUM(signed_base_amount) = 0`.
6. `JournalEntryPosted` fires exactly once on success and never on failure.
7. N concurrent posts in one scope produce contiguous numbers (P-07 overlap — assert it here too).
8. **An architecture test** asserting no class outside `JournalEntryPostingService` writes
   `ledger_entries`. This is the test that keeps the pattern true as the codebase grows.

**Effort to apply** — **2** per new document type (build the DTO, build the draft, post). The engine
itself was **8**, already spent.

**Confidence** — **High.** Shipped, tested, and exercised by a feature suite. The two Action-layer
invariants are known gaps with named owners, not unknowns.

---

## P-02 — Ledger Projection Pattern

**Intent** — Represent an accumulating quantity as append-only rows carrying one signed amount, so that
any balance at any grain is a single indexed `SUM` and the history can never be rewritten.

**Use when** — the subsystem has a quantity that accumulates over time and must be auditable: general
ledger balances, inventory valuation, budget consumption, tax accrual.

**Do not use when** — the value is a *snapshot of current state* rather than an accumulation (a
customer's address, a configuration flag); or when the history is genuinely uninteresting and mutation
is cheap and safe. Do not reach for it because it sounds rigorous — an append-only table with no
accumulation semantics is just a table you cannot fix.

### Structure

```
 journal_lines                        source of record — mutable while the entry is a draft
      │
      │  posting projects 1:1, inside the posting transaction (P-01 step 6)
      ▼
 ledger_entries                       APPEND-ONLY
      debit_amount, credit_amount
      base_debit_amount, base_credit_amount
      signed_base_amount  =  base_debit_amount − base_credit_amount     ◄── CHECK
      │
      ├── account balance   =  SUM(signed_base_amount) WHERE account_id = ?
      ├── trial balance     =  SUM(signed_base_amount) GROUP BY account_id
      ├── entry proof       =  SUM(signed_base_amount) WHERE journal_entry_id = ?  ⇒ 0
      │
      └── account_period_balances     rollup maintained by AFTER INSERT trigger (P-15)
             MONOTONIC — it can only ever increment, because the source only ever grows
```

### Participants

`ledger_entries` (table, RLS-scoped, append-only trigger, `uq_ledger_entries_journal_line`);
`JournalEntryPostingService::projectLines()` (the only writer); `LedgerEntry` (thin model);
`account_period_balances` (P-15, specified); `LedgerImmutableException` (specified).

### Mechanics

1. **One row per posted line, written inside the posting transaction.** The projection is never
   asynchronous. An asynchronous projection means a window in which the ledger disagrees with the
   journal, and that window will eventually contain a report.
2. **`signed_base_amount` is stored, not computed at read time.** `+base_debit` for a debit leg,
   `−base_credit` for a credit leg. It turns every balance query into a plain aggregate over a covering
   index, and it makes "does this entry balance?" a `SUM(...) = 0` test rather than a two-column
   comparison. The `chk_le_signed` CHECK makes the derivation a database fact rather than a convention
   in one PHP method.
3. **No status column on the projection.** The rows are posted by construction. A `status` column would
   invite a partial index, a `WHERE` clause every reader must remember, and eventually a row whose status
   disagrees with its parent. (Systems whose ledger *is* their invoice-line table need such a
   discriminator; QAYD deliberately does not have that problem.)
4. **Append-only is enforced twice** — a `BEFORE UPDATE OR DELETE` trigger that raises, and (specified)
   `REVOKE UPDATE, DELETE … FROM qayd_app`. The trigger explains itself in the error message; the
   revocation means the privilege was never there. Neither is sufficient alone: the trigger can be
   dropped by a migration, the grant can be re-granted by one.
5. **Append-only is what unlocks everything downstream.** Because rows never change: the period rollup
   trigger is monotonic and therefore trustworthy (P-15); the hash chain can never go stale (P-10); and
   the table can be partitioned by `(company_id, period range)`.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| `signed_base_amount = base_debit − base_credit` | `chk_le_signed` | DB CHECK |
| A leg is one-sided and non-negative | `chk_le_one_sided` | DB CHECK |
| A journal line is projected at most once | `uq_ledger_entries_journal_line` | DB UNIQUE |
| No row is ever updated or deleted | `trg_ledger_entries_append_only` (+ REVOKE, specified) | DB trigger |
| `SUM(signed_base_amount)` over an entry is exactly 0 | theorem of P-01 balance + signing; asserted by test | Test |
| A projection row belongs to the posting tenant | RLS restrictive boundary (P-09) | DB policy |

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_28_000007_create_ledger_entries_table.php`
- `JournalEntryPostingService::projectLines()` — the only writer.

### Worked example — inventory valuation layers

Inventory cost is the same shape: `valuation_events(company_id, product_id, moved_at, signed_qty,
signed_value_base, unit_cost_after)`, append-only, projected inside the transaction that posts the stock
move's journal entry. On-hand value becomes `SUM(signed_value_base)`; current unit cost becomes an
indexed lookup of the most recent `unit_cost_after`.

The `unit_cost_after` column is the detail that matters and it is the direct lesson of the research:
Odoo 19 **removed** its valuation-layer table in favour of replaying full history, batched at 50,000 rows
to avoid `MemoryError`. Full-history replay as a *primary read path* does not scale. Carrying the derived
cost forward on each event turns an O(history) replay into an O(1) lookup, at the cost of one more column
on an append-only row that can never disagree with itself.

### Failure modes

- **Adding a mutable column to the projection.** Storing reconciliation residuals (`amount_residual`,
  `reconciled`, `full_reconcile_id`) on the ledger row is the single decision that forces a general
  ledger to become mutable — and from there to raw-SQL writes, staleness bugs, and the loss of both
  partitioning and hashing. Matching state goes in side tables keyed to `ledger_entry_id` (P-13).
- **Adding a `status` column** because "we might need to void one". Voiding is a reversal (P-13).
- **A one-off `UPDATE` for a backfill.** The trigger will stop you. Do not drop it "for the migration";
  write compensating rows instead, or rebuild the projection from its source.
- **Storing a running balance per row.** It is a read model with a different shape (P-15) and it needs a
  rebuilder; putting it in an append-only row makes it unfixable.
- **Projecting asynchronously** to "speed up posting". The speed comes from the index, not the queue.

### Testing strategy

1. `UPDATE` and `DELETE` on a ledger row are rejected — as the application role, and as a role with
   table privileges, proving the trigger and not merely the grant.
2. `SUM(signed_base_amount) = 0` per entry, and per company across all entries.
3. Projection row count equals posted line count, for a 1-line and a 10,000-line entry.
4. A rolled-back post leaves zero rows.
5. `chk_le_signed` rejects a hand-crafted row whose signed amount disagrees with its legs.
6. RLS: company B cannot read company A's ledger rows with a valid session.

**Effort to apply** — **5** for a new projection (table, CHECKs, append-only trigger, RLS block,
projection writer). **1** if it is a direct copy of the ledger shape.

**Confidence** — **High.** Shipped, and the enabling property for four other patterns.

---

## P-03 — Seam Pattern

**Intent** — Place a named interface at a boundary you *know* will move, so the subsystem behind it can
be replaced without editing the subsystem in front of it.

**Use when** — one of exactly three conditions holds:

1. **A scheduled granularity or policy change.** The fiscal calendar resolves to a *year* today and will
   resolve to a *period* in S2-07. Both answer the same question.
2. **A named variant is coming.** Reversal is negation today and must be *storno* (same-side negative
   amounts) in the roughly ten jurisdictions where negation is illegal. The second implementation is
   legally specified even though no current market needs it.
3. **The real data source does not exist yet and the alternative is writing a lie.** `PostedActivityGuard`
   answers "does this account carry posted activity?" — a question the chart-of-accounts guards must ask
   before the ledger exists.

**Do not use when** — you merely suspect you might want to swap something; when there is exactly one
implementation and you cannot name the second; when the interface would be a 1:1 mirror of one class's
public surface (that is a header file, not a seam); or when you are about to build an *engine* before its
concrete cases exist (see P-19 — build two statements, then extract the engine).

### The cost of introducing one too early

This is the part of the pattern that is usually left out, so it is stated plainly.

**The mechanical cost is small and honest:** an interface, a value object, a container binding, a test
double, and one more hop a reader must follow to answer "what actually happens here". Perhaps forty
lines and a small tax on every future reader.

**The epistemic cost is the real one.** A seam freezes the *shape of the question* before you know the
answer. `resolveOpenPeriodForPosting(companyId, date): ResolvedFiscalPeriod` was safe to freeze because
both candidate implementations answer precisely that question with precisely that data — the value object
already carries a nullable `fiscalPeriodId` that today's implementation never fills. A seam introduced
before the question is understood gets rewritten along with its implementations, and in the meantime it
has *taught the codebase a wrong decomposition*, which is far more expensive than the forty lines.

**The readiness test, in two questions:**

```
   Can you NAME the second implementation?        no ─► not a seam. One call site is fine.
        │ yes
   Does it answer the SAME question with the      no ─► not a seam. You have two features
   same inputs and the same return shape?                that happen to be near each other.
        │ yes
   ────► introduce the seam.
```

And the reassurance that makes it safe to say no: **a seam is cheap to add later if the dependency is
reached from one place.** The alternative to a premature seam is not chaos; it is one call site and a
grep. Concentrate the dependency first, abstract it second.

### Structure

```
 consumer  (JournalEntryPostingService)
     │   depends on the INTERFACE only — no reference to fiscal_years anywhere
     ▼
 «interface» FiscalCalendarResolver
     │   returns ResolvedFiscalPeriod   (final readonly value object, superset-shaped)
     │
     ├── FiscalYearCalendarResolver     ── S2-05, today   (fiscalPeriodId = null)
     └── FiscalPeriodCalendarResolver   ── S2-07, named, not yet written
              ▲
              └── bound in AppServiceProvider — the ONE line that changes
```

### Participants

Interface (`FiscalCalendarResolver`, `PostedActivityGuard`); value object (`ResolvedFiscalPeriod`,
`final readonly`); implementations; the container binding in `AppServiceProvider`; the consumer.

### Mechanics

1. **The interface expresses a question, not a table.** `resolveOpenPeriodForPosting` — not
   `findFiscalYear`. The name survives the change of granularity because the question does.
2. **The return value is a superset-shaped value object.** `ResolvedFiscalPeriod` already declares
   `?int $fiscalPeriodId` and the posting engine already threads it into the ledger projection, where the
   column is nullable pending S2-07. When the finer resolver lands, no signature changes, no consumer
   changes, and the column becomes `NOT NULL` in the same migration that backfills it. **Designing the
   value object for both implementations is what makes the seam real** rather than decorative.
3. **The current implementation tells the truth, it does not stub.** `NoLedgerPostedActivityGuard`
   returns `false` for every account *because no account can carry posted lines until the ledger exists*.
   That is a fact about the schema, not a placeholder. A stub returning a convenient value is a lie with
   a rebinding date; an honest implementation is correct today and replaced later.
4. **Exceptions belong to the seam, not the implementation.** `ClosedPeriodException` is thrown by the
   interface's contract, so swapping implementations cannot change the caller's error handling.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| The consumer never references the concrete class or its tables | Architecture test grepping the consumer for `fiscal_years` / concrete class names | Arch test |
| The value object is immutable | `final readonly` + arch test on `app/Domain` | Arch test |
| Every implementation satisfies the same contract | One shared contract test executed against each binding | Test |
| Swapping implementations changes exactly one line | Binding lives only in `AppServiceProvider` | Convention ⚠️ |

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/app/Domain/Accounting/FiscalCalendarResolver.php`
- `/Users/ali/projects/qayd-os/apps/api/app/Domain/Accounting/FiscalYearCalendarResolver.php`
- `/Users/ali/projects/qayd-os/apps/api/app/Domain/Accounting/ResolvedFiscalPeriod.php`
- `/Users/ali/projects/qayd-os/apps/api/app/Domain/Accounting/PostedActivityGuard.php` +
  `NoLedgerPostedActivityGuard.php` — the "honest current answer" variant.

### Worked example — `ReversalStrategy`

```php
interface ReversalStrategy
{
    /** @param  list<JournalLineData>  $original  @return list<JournalLineData> */
    public function reverseLines(array $original): array;
}
// NegationReversalStrategy  — swap debit and credit          (today, all markets)
// StornoReversalStrategy    — same side, negative amounts     (named, ~10 jurisdictions)
```

This passes the readiness test: the second implementation is *named*, it is *legally specified*, and it
answers the same question with the same input and output shape. It costs ~40 lines now and makes the
storno market a configuration change rather than a fork of the reversal Action.

**The counter-example is equally instructive.** Do **not** put a seam in front of the financial-report
evaluator. Two concrete statements (Trial Balance, then P&L) must exist first; extracting the engine from
two working implementations produces a different — and correct — abstraction from designing it against
imagined requirements. The failure mode of the latter is documented in P-19.

### Failure modes

- **A seam with one implementation, forever.** Dead abstraction: every reader pays, nobody benefits.
  If the named second implementation has not arrived after two sprints, ask whether it ever will.
- **A value object that leaks the current implementation's schema.** If `ResolvedFiscalPeriod` had
  exposed `startDate`/`endDate` from `fiscal_years`, the S2-07 rebinding would change the signature and
  the seam would have bought nothing.
- **A stub that lies.** Returning `true`/`[]`/`null` "for now" plants a defect that only appears when the
  real implementation lands and the caller's assumptions turn out to have been built on the lie.
- **Using a seam as a bypass point.** An implementation that skips a check "for imports" reintroduces the
  off-switch that `01`/P2 forbids.
- **Seams at every boundary.** A codebase where every dependency is an interface is a codebase where no
  reader can find out what runs. Three seams exist today; that is roughly the right density.

### Testing strategy

1. One **contract test** run against every implementation (same inputs, same guarantees, same exception
   types) — this is what makes a rebinding safe.
2. The consumer is tested against a **fake** implementation, proving it depends on the interface only.
3. Each implementation is tested directly against real data.
4. An **architecture test** asserting the consumer's imports contain no concrete implementation and its
   SQL contains no seam-owned table name.
5. A rebinding test: swap the binding in a test container, assert consumer behaviour is unchanged except
   for the intended difference.

**Effort to apply** — **2** to introduce (interface + value object + binding + contract test); **1** to
rebind when the second implementation arrives.

**Confidence** — **High.** Proven twice in this codebase, and the S2-07 rebinding is a designed, dated
event rather than a hope.

---

## P-04 — Approval Pattern

**Intent** — Route an irreversible financial change through explicit recorded states — draft → submitted
→ approved → executed — where the approver is provably a different human from the initiator, and the AI
is provably neither.

**Use when** — the operation is irreversible or expensive: posting, period close, opening-balance import,
a write-off above tolerance, a budget release, a reversal, a consolidation snapshot.

**Do not use when** — the operation is cheap and reversible; or when the check is machine-verifiable. **An
approval is not a substitute for a constraint.** Asking a human to approve an unbalanced entry is theatre:
the CHECK rejects it either way, and the ritual teaches people that approving is a formality. Approvals
are for *judgement*, constraints are for *facts*.

### Structure

```
   ┌─────────┐  submit   ┌──────────────────┐  approve  ┌──────────┐  post   ┌────────┐
   │  draft  │──────────►│ pending_approval │──────────►│ approved │────────►│ posted │
   └────┬────┘           └────────┬─────────┘           └────┬─────┘         └───┬────┘
        ▲                  reject │                          │ (return to draft)  │ reverse
        └─────── rejected ◄───────┘                          ▼                    ▼
                                                          draft               reversed

   AI may AUTHOR a draft.  AI may NOT submit, approve, or post.
   ├─ approved_by <> created_by  ................ CHECK           (database)
   ├─ ai_generated ⇒ status = 'draft' on INSERT .. trg_no_ai_autopost (database)
   ├─ approve permission ≠ post permission ....... PermissionResolver (segregation of duties)
   └─ what was approved is SNAPSHOTTED ........... version stamp / content hash
```

### Participants

`journal_entries.status` / `version` / `created_by` / `approved_by` / `approved_at` / `posted_by`;
`SubmitForApprovalAction`; `ApproveJournalEntryAction` (specified); `JournalRuleException::aiCannotSubmit`,
`::versionConflict`, `::notEditable`; `trg_no_ai_autopost`; `period_close_runs` with
`pcr_approver_distinct` and a partial unique index (specified); `SelfApprovalForbiddenException`
(specified, `403`).

### Mechanics

1. **Legality of the transition comes from the lifecycle map (P-18), not from the Action.** The Action
   decides *whether this actor may do it*; the map decides *whether it is a legal edge at all*.
2. **The version guard comes from P-06.** Approving is a state-changing UPDATE and carries the same
   `WHERE version = ?` guard, so approving a stale view of the object is a `409`, not a silent success.
3. **Four-eyes is a database CHECK, not application code.** `CHECK (approved_by <> initiated_by)` holds
   against a data-fix script, a console command, a compromised service account and a future endpoint
   nobody reviewed. The Action-layer check exists to produce a good error message; the CHECK is the
   guarantee.
4. **Segregation of duties is a permission split.** `accounting.journal.approve` and
   `accounting.journal.post` are distinct permissions resolved by `PermissionResolver`
   (role ∪ grant − deny). The DB CHECK is the backstop that survives a permission-configuration mistake.
5. **The approval must capture *what* was approved.** An approval row recording only "approved by X at T"
   is worthless if the object changed afterwards. Two acceptable forms: stamp the `version` that was
   approved and refuse to execute a different one; or capture a content hash of the approved payload
   (period close captures a bcmath trial-balance snapshot plus its hash). Choose one and enforce it in a
   shape CHECK.
6. **The AI boundary is enforced three times** — GRANTs (P-12), the `trg_no_ai_autopost` trigger, and
   `JournalRuleException::aiCannotSubmit`. Only the first is a guarantee.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| Approver is not the initiator | `CHECK (approved_by <> created_by)` (specified for JE; designed for `period_close_runs`) | DB CHECK |
| An approved record names an approver and a timestamp | shape CHECK (`status='approved'` ⇒ both NOT NULL) | DB CHECK |
| An AI-generated record cannot exist past draft | `trg_no_ai_autopost` + `aiCannotSubmit` | DB trigger |
| Approving a stale object fails | version-guarded `UPDATE` (P-06) | DB predicate |
| Only one approval workflow per subject is open | partial unique index on the open statuses | DB index |
| Approve and post are different capabilities | `PermissionResolver` + route gates | Action ⚠️ |

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/app/Actions/Accounting/SubmitForApprovalAction.php` — the submit
  half: AI refusal, editable-status check, version-guarded UPDATE, and precise post-mortem diagnosis.
- `trg_no_ai_autopost` in
  `/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php`.

**Honest gap:** the approve half (`ApproveJournalEntryAction`, the `approved_by <> created_by` CHECK, and
the shape CHECK) is designed, not built. The pattern is **Partial**.

### Worked example — period close

```sql
CREATE TYPE period_close_status AS ENUM
  ('started','checks_passed','awaiting_approval','closed','aborted');

CREATE TABLE period_close_runs (
  ...
  initiated_by_user_id  BIGINT NOT NULL REFERENCES users(id),
  approved_by_user_id   BIGINT NULL     REFERENCES users(id),
  trial_balance_snapshot JSONB NULL,           -- bcmath strings, never floats
  snapshot_hash          CHAR(64) NULL,
  CONSTRAINT pcr_approver_distinct CHECK (approved_by_user_id IS NULL
                                       OR approved_by_user_id <> initiated_by_user_id),
  CONSTRAINT pcr_closed_shape      CHECK (status <> 'closed'
                                       OR (closed_at IS NOT NULL
                                           AND approved_by_user_id IS NOT NULL
                                           AND snapshot_hash IS NOT NULL))
);
CREATE UNIQUE INDEX pcr_one_open_per_period ON period_close_runs (period_id)
  WHERE status IN ('started','checks_passed','awaiting_approval');
```

Three things generalize out of this and belong in any approval workflow: the **four-eyes CHECK**, the
**shape CHECK** that makes a terminal state impossible without its evidence, and the **partial unique
index** that makes two concurrent workflows on one subject a database error rather than a race (note that
this is a constraint, not a lock — P-08).

A second, smaller example: a payment write-off above `requires_approval_above` raises
`PAY_WRITEOFF_REQUIRES_APPROVAL` (`403`) rather than proceeding — the same pattern with a monetary
threshold as the trigger.

### Failure modes

- **Four-eyes in PHP only.** A queue job, a console command, or a service account defeats it, and nothing
  records that it happened.
- **Approving a reference instead of a snapshot.** The object mutates after approval and the approval
  attests to something that no longer exists.
- **An emergency bypass flag.** `01`/P2: an invariant with an off switch is not an invariant. If an
  emergency path is genuinely needed, it is a `PlatformOperation` with a written reason and an audit row
  in the same transaction — never a boolean parameter.
- **Approval inflation.** Making everything approvable trains reviewers to click approve. Approve the
  irreversible; constrain the rest.
- **Letting the AI author the approval record**, or auto-accepting above a confidence threshold. A
  threshold is a policy, not a permission (P-12).
- **Approval without a rejection path.** If the only outcomes are approve and silence, the queue becomes
  a backlog and the pattern degrades into a delay.

### Testing strategy

1. Self-approval is rejected **via raw SQL as the application role**, not merely via the Action.
2. An AI actor is rejected at submit and at post; an AI-generated entry cannot be inserted non-draft.
3. Approving with a stale `version` returns `409` and changes nothing.
4. The shape CHECK rejects `status='closed'` without approver, timestamp and hash.
5. Two concurrent close runs on one period: exactly one succeeds; the loser gets a coded `409`.
6. A user holding `approve` but not `post` can approve and cannot post, and vice versa.
7. The approved snapshot is byte-identical to what executes.

**Effort to apply** — **5** for a full workflow (states, two Actions, four-eyes CHECK, shape CHECK,
partial unique index, tests); **2** if reusing an existing status enum and lifecycle map.

**Confidence** — **High** on the shape (the submit half, the AI trigger, and the version-guard idiom all
ship). **Medium** on the period-close specifics, which are designed but unbuilt.

---

## P-05 — Validation Pattern

**Intent** — Evaluate every rule, collect every violation, and return them together in one structured,
machine-readable response — never abort on the first failure.

**Use when** — input is authored in bulk or by a machine: journal drafts, opening-balance imports, bank
statement imports, AI proposals, any `422` surface an agent will retry against.

**Do not use when** — a rule's failure makes later rules meaningless or unsafe to evaluate (you cannot
check line balance on an entry whose currency is unknown) — fail fast there, and say so in a comment. And
**never aggregate authorization failures**: a permission denial is `403`, and an aggregated response that
reports which other rules would have failed is an information oracle.

### Structure

```
 ValidateJournalDraftAction
     ├─ header rules   ──┐
     ├─ line rules     ──┼──► ViolationCollector  (accumulates; never throws mid-pass)
     └─ account rules  ──┘         │
                                   ▼
                            ValidationReport
                                   │
                    empty? ── yes ─► proceed to the mutating Action
                              no ──► ValidationFailedException(report)  ⇒ HTTP 422
                                     {
                                       "success": false,
                                       "errors": [
                                         { "code": "LINE_NOT_ONE_SIDED",
                                           "field": "lines[3].debit",
                                           "message": "...",
                                           "meta": { "actual": "50.0000", "expected": "0.0000" } },
                                         { "code": "ACCOUNT_INACTIVE", "field": "lines[7].account_id", ... }
                                       ]
                                     }
```

### Participants

`ValidationReport` and `Violation` (`final readonly` DTOs, specified); `ValidationFailedException extends
DomainException` whose `errorsList()` returns the whole array; the existing
`WritesJournalDraft::assertValid()` (today's fail-fast form); `ApiExceptionRenderer` (already renders
`errors[]` as a list — no envelope change is required).

### Mechanics

1. **Two phases: collect, then decide.** Every rule is a pure function from the DTO (plus pre-fetched
   reference data) to zero or more violations. Nothing throws during collection.
2. **Batch the I/O once, before collection.** Fetch every referenced account in a single query keyed by
   id, then let the per-line rules read the map. The naive form of this pattern is an N+1 generator; the
   fix is structural, not incidental.
3. **Field paths are addressable.** `lines[3].account_id`, not `lines`. An agent must be able to patch
   precisely; a human client must be able to highlight the right input.
4. **Codes come from the error catalog**, never invented at the call site. A test iterates every
   `Violation` code produced anywhere and asserts membership in the catalog.
5. **Money in `actual`/`expected` stays a string** (P-14).
6. **The aggregate check never replaces the database constraint.** It exists to produce a complete,
   kind, machine-readable answer *earlier*. The CHECK is still the guarantee (`01`/P1). A useful property
   test: anything the aggregator accepts, the database must also accept.

**Honest current state.** `WritesJournalDraft::assertValid()` throws on the first violation. That is the
pattern's *pre-state*, and the upgrade is small: `DomainException::errorsList()` already returns a
`list<...>`, so the envelope, the renderer and every client contract are already shaped for many
violations. The work is the collector and the Action refactor.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| A `422` body always carries at least one violation | `ValidationFailedException` constructor + test | Test |
| Every emitted code exists in the error catalog | Catalog test enumerating all violation codes | CI |
| No violation message leaks internals | `DomainException` contract + review + envelope test | Test |
| Validation performs no writes | Arch test: the validate Action has no write dependencies | Arch test |
| The aggregator never accepts what the DB rejects | Property test over generated drafts | Test |

### Reference implementation

**Partial.** `/Users/ali/projects/qayd-os/apps/api/app/Exceptions/DomainException.php` (the `errors[]`
list shape) and
`/Users/ali/projects/qayd-os/apps/api/app/Actions/Accounting/Concerns/WritesJournalDraft.php`
(`assertValid()` — the rules exist; the aggregation does not).

### Worked example — opening-balance import

A 5,000-line trial balance imported from a prior system. Under fail-fast, an AI agent mapping the chart
discovers one problem per round trip: 5,000 calls in the worst case, each costing a model invocation and
a database transaction.

Under this pattern, one call returns every unmapped account, every one-sided violation, every inactive
target, **and** the residual that must be acknowledged against a suspense account (P-17). The agent
patches all of them and re-submits once. The pattern is worth three points and removes an entire
interaction class.

Second example: bank import — every duplicate `external_id`, every unparseable date and every unknown
currency returned together, so the operator fixes the file once.

### Failure modes

- **Aggregating authorization.** Turns a permission boundary into an enumeration API.
- **Rules with side effects.** A rule that creates a missing account "helpfully" breaks the collect/decide
  split and makes the pass non-idempotent.
- **N+1 lookups**, one per rule per line.
- **Unstructured output.** Newline-joined strings have the right instinct and the wrong shape: a client
  cannot address them, an agent cannot patch from them, and a translator cannot localise them.
- **Codes invented at the call site**, so two endpoints report the same failure differently.
- **Treating the aggregate as the guarantee** and skipping the CHECK.

### Testing strategy

1. A draft with three distinct, independent violations returns exactly three, with stable codes and
   correct field paths.
2. Ordering is deterministic (header rules, then lines in order) so snapshot tests are stable.
3. No database write occurred during a failed validation.
4. Query count is O(1) in the number of lines, not O(n).
5. Property test: generate random drafts; anything the aggregator accepts is accepted by the database,
   and anything the database rejects produces at least one violation.
6. Authorization failures are **not** aggregated — a `403` returns a single error.

**Effort to apply** — **3** for the first subsystem (collector, DTOs, exception, refactor);
**1** per subsequent subsystem.

**Confidence** — **High.** The mechanism is simple, the envelope already supports it, and the payoff is
directly measurable in agent round trips.

---

## P-06 — Concurrency Pattern

**Intent** — Detect a lost update with a `version` column and a version-guarded `UPDATE`, then *diagnose*
why zero rows changed instead of guessing.

**Use when** — a mutable row is edited by more than one actor over human time: drafts, settings,
templates, proposals, budgets.

**Do not use when** — the row is append-only (there is no update to lose); when last-write-wins is
genuinely correct (a UI preference); or when contention is at machine speed rather than human speed, in
which case the answer is a lock (P-08) or a constraint, not an optimistic retry loop.

### Structure

```
 client GET   ──►  { "id": 42, "version": 7, ... }
 client PATCH ──►  { "version": 7, ... }
        │
        ▼   inside the transaction
 UPDATE journal_entries
    SET status = 'pending_approval', version = 8
  WHERE id = 42
    AND version = 7                       ◄── the guard: this is the enforcement
    AND status IN ('draft','rejected')    ◄── the state precondition, in SQL, not only in PHP
        │
        ├─ affected = 1 ─► success
        │
        └─ affected = 0 ─► RE-READ the row and DIAGNOSE (still inside the transaction):
                             row missing            ─► 409  JOURNAL_NOT_EDITABLE('unknown')
                             status not editable    ─► 409  JOURNAL_NOT_EDITABLE(status)
                             otherwise              ─► 409  VERSION_CONFLICT(expected: 7)
```

### Participants

`version INTEGER NOT NULL DEFAULT 1` + `chk_je_version_min`; the guarded `UPDATE`;
`JournalRuleException::versionConflict()` / `::notEditable()`; `SubmitForApprovalAction`,
`UpdateJournalDraftAction`.

### Mechanics

1. **The pre-check before the transaction is a courtesy.** It produces a fast, kind error in the common
   case. It is *not* the enforcement — between the check and the update, anything can happen.
2. **The guard lives in the `WHERE` clause.** Both the version and the state precondition. Putting the
   state check only in PHP is the classic time-of-check/time-of-use defect: two submits can both read
   `draft` and both proceed.
3. **The version is incremented in the same statement** that performs the change. Never in a trigger *as
   well* — a double increment silently invalidates every client's cached version.
4. **The post-mortem read happens inside the same transaction**, so it observes the committed competitor
   and can tell the three cases apart. This diagnosis step is the actual content of the pattern: a bare
   "conflict" tells a human nothing and sends an AI agent into an unbounded retry. `SubmitForApprovalAction`
   distinguishes *gone*, *wrong state* and *stale version*, and returns a different coded error for each.
5. **Idempotency keys are a different tool.** Optimistic versioning answers "did someone else change
   this?"; an idempotency key answers "is this the same request twice?". Use both where both questions
   exist.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| A concurrent edit cannot be silently lost | `WHERE version = ?` in the UPDATE | DB predicate |
| `version` strictly increases and is ≥ 1 | `chk_je_version_min` + every UPDATE sets `version + 1` | DB CHECK |
| A state-illegal update cannot land | `WHERE status IN (...)` | DB predicate |
| A conflict never partially applies | one `DB::transaction` | DB |
| The caller can tell the three failure causes apart | post-mortem read + three coded exceptions | Action |

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/app/Actions/Accounting/SubmitForApprovalAction.php` — the
  canonical form, including the three-way diagnosis.
- `/Users/ali/projects/qayd-os/apps/api/app/Actions/Accounting/UpdateJournalDraftAction.php`

### Worked example — accepting an AI match proposal

`AcceptMatchProposalAction` must confirm that the world has not moved since the proposal was computed: a
match proposed twenty minutes ago may now over-reconcile because another user allocated a payment.

The same pattern with a hash instead of an integer: the proposal stores a `plan_hash` over the candidate
residuals; acceptance recomputes it under the row locks and refuses on mismatch with a coded `409` that
says *what* changed. A monotonically increasing integer works when there is one row to version; a content
hash works when the "version" is a computed set. Both are the same idea — **compare-and-swap on a token
the client echoes back** — and both must diagnose rather than merely refuse.

### Failure modes

- **Check-then-update without the `WHERE` guard.** The single most common way this pattern is
  implemented wrongly, and it passes every single-threaded test.
- **Incrementing `version` in both a trigger and the Action.** Clients desynchronise immediately.
- **Returning `409` with no diagnosis.** Humans retry blindly; agents loop.
- **Using `version` on an append-only table.** There is nothing to lose; you have added a column and a
  false sense of safety.
- **Auto-retrying server-side on conflict.** That is last-write-wins with extra latency, and it discards
  the other actor's change without telling anyone.
- **Exposing `version` as `updated_at`.** Timestamps collide at low resolution and are not monotonic
  across clock adjustments.

### Testing strategy

1. Two concurrent submits of the same draft: exactly one `200`, exactly one `409`; final `version` is
   `n + 1`, not `n + 2`.
2. The `409` distinguishes stale-version from wrong-status from missing-row, with different codes.
3. Bypassing the PHP pre-check (calling the Action with a hand-built stale version) still fails at the
   `WHERE` guard.
4. A conflicting update leaves no partial change.
5. `chk_je_version_min` rejects `version = 0`.

**Effort to apply** — **2** (column + CHECK + guarded UPDATE + diagnosis + two tests).

**Confidence** — **High.** Shipped and exercised by the draft-lifecycle suite.

---

## P-07 — Number Allocation Pattern

**Intent** — Draw a **gapless**, per-scope, human-meaningful identifier with a single atomic statement
whose lock is held for the minimum possible time.

**Use when** — a regulator, an auditor or a customer will read the identifier and infer from a gap that
something is missing: journal numbers, invoice numbers, credit notes, VAT-return sequence numbers.

**Do not use when** — the identifier is internal (use the identity primary key); or when throughput
matters more than gaplessness and the audience tolerates gaps. In that case use a PostgreSQL `SEQUENCE`
and **say so explicitly in the migration comment** — you cannot have both properties, and pretending
otherwise is how a system ends up with a "gapless" number that silently gaps under load.

### Structure

```
 journal_number_sequences
   PRIMARY/UNIQUE (company_id, fiscal_year_id, entry_type)   ◄── the SCOPE tuple
   prefix, padding, next_number
        │
        ▼
 INSERT INTO journal_number_sequences (company_id, fiscal_year_id, entry_type, prefix, next_number)
 VALUES (?, ?, ?::journal_entry_type, ?, 2)
 ON CONFLICT (company_id, fiscal_year_id, entry_type)
 DO UPDATE SET next_number = journal_number_sequences.next_number + 1, updated_at = now()
 RETURNING prefix, padding, (next_number - 1) AS allocated
        │
        └── the sequence row is now locked for the caller's POSTING transaction
            ⇒ a rollback rolls back the increment  ⇒  no gap is possible
        │
        ▼
   JE-FY2026-000001        {prefix}-{fiscal_year.name}-{n zero-padded}
```

### Participants

`journal_number_sequences` (with `chk_jns_next_number_min`, `chk_jns_padding_range`, `uq_jns`);
`JournalNumberAllocator`; the posting transaction that owns the lock; `uq_je_number UNIQUE (company_id,
journal_number)` as the cross-check; `VerifyNumberSequenceAction` (specified, CI + scheduled).

### Mechanics

One statement does three things that are usually three statements and a race:

1. **Create-if-absent.** The first post in a new scope needs no seeding step, no migration-time backfill,
   and no "does the row exist?" read.
2. **Increment under a row lock.** `ON CONFLICT DO UPDATE` takes a row lock on the conflicting tuple; two
   concurrent posts in the same scope cannot receive the same number.
3. **Return the allocated value** in the same round trip.

**Why gaplessness follows.** The increment happens *inside the caller's transaction*. If the post rolls
back — a failed constraint, a crashed worker, a deliberate abort — the increment rolls back with it, so
the next post receives the same number. This is precisely why the two neighbouring rules exist:

- **Allocate last** (P-01 step 5). The lock is held from allocation to commit; every microsecond of
  validation performed *after* allocation is a microsecond every other post in the scope waits.
- **Scope narrowly.** `(company, fiscal_year, entry_type)` rather than `(company)`. The scope tuple is
  the contention unit; make it as fine as the audience's expectation of contiguity allows.

**Rejected alternatives, and why:**

| Alternative | Faster? | Why rejected |
|---|---|---|
| PostgreSQL `SEQUENCE` | yes, never blocks | Gaps on every rollback, by design. Fails the audit requirement. |
| `SELECT MAX(number) + 1` | no | Lost update under concurrency; needs its own lock anyway. |
| Advisory lock + read + write | comparable | A second lock object to order against (P-08), and no create-if-absent. |
| In-memory cached range | much | An abandoned range is a permanent gap; the failure is invisible until an audit. |
| Unique-index collision retry | yes | Optimises for throughput and *accepts* gaps; structurally incompatible with the requirement. |

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| No two entries share a number in a company | `uq_je_number UNIQUE (company_id, journal_number)` | DB UNIQUE |
| One sequence row per scope | `uq_jns UNIQUE (company_id, fiscal_year_id, entry_type)` | DB UNIQUE |
| Numbers within a scope are contiguous | atomic increment inside the caller's transaction | DB semantics |
| `next_number ≥ 1`, `padding ∈ [1,12]` | `chk_jns_next_number_min`, `chk_jns_padding_range` | DB CHECK |
| No gaps in production | `VerifyNumberSequenceAction` in CI + scheduled | CI ⚠️ |
| The uniqueness backstop index exists | boot-time assertion, **fatal** | Boot check ⚠️ |

⚠️ Both are verification, not prevention — which is the correct division: the mechanism prevents, the
check proves the mechanism is still installed.

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/app/Services/Accounting/JournalNumberAllocator.php`
- `/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_28_000006_create_journal_number_sequences_table.php`

### Worked example — VAT return filing numbers

A GCC VAT filing needs a contiguous per-regime sequence: scope `(company_id, tax_regime_id, tax_period_id)`,
same table shape, same statement, different tuple. The one addition worth making is to move the **format
template onto the sequence row** rather than into a PHP constant map, so a jurisdiction that requires
`KW/VAT/2026/0001` is configuration rather than a release (P-19).

That generalisation is also the honest critique of the current implementation: `JournalNumberAllocator`
holds its prefixes in a PHP `const` array. That is correct for now (entry types are a fixed enum) and
should move to the row the moment a customer-visible format varies.

### Failure modes

- **Allocating before validation.** Holds the scope lock across the entire validation pass and serializes
  every concurrent post in the scope behind the slowest validator.
- **Allocating outside the caller's transaction** (a separate connection, a nested `commit`). Gaps appear
  on rollback and the allocated number leaks into logs as an entry that never existed.
- **Scoping too broadly.** One lock for the whole company turns the allocator into a global mutex.
- **Caching ranges in memory** for batch throughput. Every abandoned range is a permanent gap.
- **Formatting drift.** Prefixes per entry type with no test: a new enum value silently falls back to the
  generic prefix and nobody notices until an auditor asks why `REV` entries are numbered `JE`.
- **Assuming the UNIQUE index makes it gapless.** The index prevents duplicates, not gaps. They are
  different properties with different mechanisms.

### Testing strategy

1. **N parallel posts in one scope** produce exactly `1..N`, with no duplicates and no gaps.
2. **The rollback test the research specifically calls for:** allocate in M concurrent transactions, roll
   back a random subset after allocation, and assert the surviving numbers are still *contiguous*. This
   is the test that distinguishes this pattern from a sequence.
3. Different scopes do not interfere (parallel posts across two entry types both start at 1).
4. A hand-crafted duplicate `journal_number` is rejected by `uq_je_number`.
5. The boot assertion fails hard when the backstop index is absent.
6. Every `journal_entry_type` enum value maps to an intended prefix (table-driven, so a new enum value
   fails the test rather than silently defaulting).

**Effort to apply** — **3** (table + CHECKs + allocator + concurrency and rollback tests). The tests are
more work than the code, and that ratio is correct.

**Confidence** — **High.** Shipped; the mechanism is one statement and its semantics are PostgreSQL's,
not ours.

---

## P-08 — Locking Pattern

**Intent** — Serialize exactly the rows whose concurrent modification would break an invariant, in a
fixed global order, for the shortest possible window — and lock nothing else.

**Use when** — two transactions can interleave to produce a state neither would produce alone, *and* no
constraint can express the invariant: gapless number allocation, read-modify-write of a residual,
hash-chain head assignment.

**Do not use when** — the data is effectively immutable during the operation; when the operation is a
pure read; when a constraint can say it instead (a `DEFERRABLE INITIALLY DEFERRED` constraint trigger, a
`UNIQUE` index, an `EXCLUDE USING GIST`); or when what you actually want is an approval workflow (P-04).

### Structure — the shipped defect, side by side with the fix

```
 WRONG — what ships today                    RIGHT — H3 / S2-07
 ────────────────────────                    ───────────────────
 BEGIN                                       BEGIN
   FOR UPDATE journal_entries (header)         FOR UPDATE journal_entries (header)
   ...balance check...                         ...balance check...
   FOR UPDATE fiscal_years  ◄══════╗           SELECT fiscal_years            (plain read)
        every concurrent post in   ║           check lock cursor / DB trigger (no lock)
        the company-YEAR queues    ║           ...account checks...
        behind this ONE row        ║           FOR UPDATE sequence row  ◄── the only lock
   ...account checks...            ║           INSERT ledger rows
   FOR UPDATE sequence row         ║         COMMIT
   INSERT ledger rows              ║
 COMMIT                            ║         lock footprint: (company, year, entry_type)
                                   ║                          — one per entry type
 lock footprint: company × year ═══╝
```

### Participants

`journal_entries` (header row, `FOR UPDATE`); `journal_number_sequences` (the one genuinely contended
row, P-07); `entry_chain_heads` (P-10, specified); `ledger_entries` (INSERT-only, never locked);
`FiscalYearCalendarResolver` (**the misapplication**); and the declarative alternatives that replace
locks — `DEFERRABLE INITIALLY DEFERRED` constraint triggers, partial unique indexes, `EXCLUDE USING GIST`.

### Mechanics — the diagnosis procedure

Before adding any lock, answer three questions **in writing**:

```
 1. What invariant breaks if two transactions read this row concurrently and both proceed?
        "none"  ─────────────────────────────────────► the lock is decoration with a throughput bill
 2. Can this row actually change during my transaction?
        "no"    ─────────────────────────────────────► you are locking against an event that cannot happen
 3. Can a constraint express the invariant instead?
        "yes"   ─────────────────────────────────────► use the constraint. It is cheaper, declarative,
                                                       and cannot be forgotten by the next Action.
 Otherwise ────────────────────────────────────────► lock, at the narrowest scope, acquired last.
```

### The teaching example — QAYD over-locks the fiscal calendar

`FiscalYearCalendarResolver` takes `SELECT … FOR UPDATE` on the fiscal-year row so that a concurrent year
close cannot race a post. The *intention* is real. The *instrument* is wrong, on all three questions:

1. **Which invariant?** "Do not post into a closed year." But a lock does not enforce that — the status
   check does. The lock only prevents the status changing between the check and the commit.
2. **Can the row change?** A fiscal year does not close spontaneously. Closing is itself a deliberate,
   approved, serialized workflow (P-04) that happens roughly once a year. The posting path pays a lock on
   **every single post** to defend against an event with an annual frequency.
3. **Can a constraint say it?** Yes — and better. A `BEFORE INSERT/UPDATE` trigger on `journal_entries`
   consulting the lock cursor rejects the write at the moment of the write. Enforcement moves to the
   written row, needs no shared object, and cannot be raced at all.

**The cost.** Every concurrent posting in a company-year serializes on one row. It is the hottest write
path in the system, and this is the single highest-impact scalability defect in the current design. Two
independent research paths reached it; the reference system takes **no lock of any kind** on its
equivalent evaluation.

**The lesson to generalise:** a lock taken to protect a check is usually the wrong shape. Move the check
to where the write happens, and the lock disappears.

### Lock ordering and deadlock avoidance

QAYD's **global acquisition order** — every Action acquires in this order, always:

```
  1. journal_entries          (header, FOR UPDATE)
  2. — fiscal calendar —      (NO LOCK: plain read; enforcement is a trigger)
  3. entry_chain_heads        (FOR UPDATE, when hash chaining lands — P-10)
  4. journal_number_sequences (FOR UPDATE via ON CONFLICT DO UPDATE — P-07)
  5. ledger_entries           (INSERT only — never locked, never updated)

  Multi-row acquisition (reconciliation, allocation):
      SELECT … WHERE id = ANY(?) ORDER BY id FOR UPDATE     ← ALWAYS ordered by primary key
```

Two rules make this deadlock-free: a fixed order between *kinds* of row, and a fixed order (ascending
primary key) *within* a kind. A single Action that acquires in caller-supplied order is enough to
reintroduce deadlocks across the whole system, so the ordering is a review checklist item, not a
suggestion.

### Invariants it guarantees

| Invariant | Enforced how | Note |
|---|---|---|
| Gapless numbering | sequence-row lock (P-07) | a genuine lock — no constraint can express it |
| No duplicate post of one entry | header `FOR UPDATE` + status re-read | a genuine lock |
| No over-reconciliation | `DEFERRABLE INITIALLY DEFERRED` constraint trigger asserting `SUM(matched) ≤ original` | **constraint, not lock** |
| No two concurrent period closes | partial unique index on open statuses | **constraint, not lock** |
| No overlapping fiscal years / rate windows | `EXCLUDE USING GIST` | **constraint, not lock** |
| No posting into a closed period | lock-cursor trigger on the write (specified) | **trigger, not lock** |

Four of six are constraints. That ratio is the pattern's main claim.

### Reference implementation

- **Correct:** `JournalEntryPostingService` (header lock, narrow, first) and `JournalNumberAllocator`
  (sequence lock, narrow, last).
- **The known defect:** `/Users/ali/projects/qayd-os/apps/api/app/Domain/Accounting/FiscalYearCalendarResolver.php`
  — `FOR UPDATE` on the fiscal-year row. Scheduled for removal when S2-07 rebinds the seam (P-03), and it
  must ship with the concurrency tests below.

### Worked example — applying a reconciliation

`ApplyReconciliationAction` locks the participating `ledger_entries` with `ORDER BY id FOR UPDATE`, then
re-derives residuals, then inserts the link rows. Note carefully what the lock is *for*: it makes the
read-modify-write of the residual atomic. It does **not** enforce the over-match invariant — that is a
deferred constraint trigger evaluated at `COMMIT`. If the lock were forgotten, throughput would suffer
and a retry would be needed; if the constraint were forgotten, the books would be wrong. Locks protect
sequencing; constraints protect truth.

### Failure modes

- **Locking a parent "to be safe."** The defect above, in general form.
- **Caller-dependent acquisition order.** Deadlocks that reproduce only under load.
- **Holding a lock across an external call** — an HTTP request to the AI engine, an S3 upload, a queue
  dispatch that blocks. Never hold a row lock across a network boundary you do not control.
- **Using a lock where a `UNIQUE`/`EXCLUDE`/deferred trigger exists.** Slower, forgettable, and it only
  protects the paths that remember it.
- **Audit or log writes inside the business transaction against a table that sees DDL.** A documented
  deadlock class: an in-transaction log insert blocked on an `ALTER TABLE`. Keep audit writes in the same
  transaction (correctness) but only on tables that never see concurrent DDL (P-10).
- **`FOR UPDATE` on a query with a join**, locking far more rows than intended.
- **Assuming `SERIALIZABLE` removes the need to think.** It converts races into serialization failures
  the caller must retry, which is a different design, not a free one.

### Testing strategy

A **concurrency suite is a first-class deliverable**, not an afterthought:

1. N parallel posts across the *same* scope and across *different* scopes; assert correctness in both,
   and assert that different scopes actually run in parallel (a timing assertion with a generous bound is
   the regression test for over-locking).
2. `pg_locks` introspection during a post, asserting **which rows** were locked — this is the only test
   that would have caught the fiscal-year lock at review time.
3. A deliberate reverse-order acquisition test proving the global order rule is followed (it should
   deadlock, and the test asserts the codebase does not do it).
4. The rollback-contiguity test from P-07.
5. Over-reconciliation attempted from two concurrent transactions: the deferred constraint rejects at
   `COMMIT` regardless of interleaving.

**Effort to apply** — **3** to remove the calendar lock; **5** including the concurrency suite that must
ship with it. **2** to add a correctly-scoped lock to a new Action.

**Confidence** — **High** that the calendar lock is wrong (reached independently by two research paths;
the compared system takes no lock at all on the equivalent path). **Medium** on the replacement's exact
shape until the `fiscal_locks` cursor and its trigger land.

---

## P-09 — RLS Pattern

**Intent** — Make the tenant boundary a property of the storage engine, identical on every tenant table,
that fails closed and cannot be bypassed by any code path — including ones nobody has written yet.

**Use when** — the table carries `company_id`. That is: always, for tenant data. There is no threshold
and no "internal table" exemption.

**Do not use when** — the table is a **global catalogue** (`account_types`, currencies, country data).
Those are protected differently: `REVOKE INSERT, UPDATE, DELETE … FROM qayd_app`, so the application can
read them and can never write them. Also not for a deliberately cross-tenant read (consolidation) — that
is a `PlatformOperation` on a distinct role with its own narrow policy clauses and an audit row in the
same transaction, **never** a loosened policy.

### Structure

```
 request / job / command
        ▼
 TenantMiddleware  (or the job/command equivalent — every entry point, no exceptions)
        BEGIN;
          SET LOCAL app.current_company_id = '42';     ◄── LOCAL, inside an explicit transaction
          SET LOCAL app.current_user_id    = '7';
          SET LOCAL app.is_platform_admin  = 'false';
          … every query for this unit of work …
        COMMIT;                                        ◄── GUCs die with the transaction

 connection: pgsql_app  ──►  role qayd_app
        LOGIN · NOSUPERUSER · NOBYPASSRLS · NOCREATEDB · NOCREATEROLE · NOINHERIT
        (RLS is silently a NO-OP for a superuser or a BYPASSRLS role — even with FORCE)

 per tenant table, verbatim, every time:
   ALTER TABLE t ENABLE ROW LEVEL SECURITY;
   ALTER TABLE t FORCE  ROW LEVEL SECURITY;              ◄── applies to the table OWNER too
   CREATE POLICY t_company_boundary ON t
       AS RESTRICTIVE FOR ALL                            ◄── AND-ed: nothing can OR past it
       USING      (company_id = app_current_company_id())
       WITH CHECK (company_id = app_current_company_id());
   CREATE POLICY t_tenant_select / _insert / _update / _delete   ◄── permissive, per verb

   app_current_company_id()  ⇒  NULL when the GUC is unset
   company_id = NULL         ⇒  UNKNOWN, not TRUE  ⇒  ZERO ROWS        (fail-closed)
```

### Participants

Migration `2026_07_27_000008` (the `qayd_app` role and its GRANTs); migration `2026_07_27_000009`
(`app_current_company_id()`, `app_current_user_id()`, `app_is_platform_admin()`, and the uniform
five-policy generator); the identical `applyRowLevelSecurity()` block in every S2 table migration;
`App\Support\TenantContext` (the one place the GUC names are written); `App\Models\Concerns\BelongsToCompany`;
`App\Scopes\CompanyScope`; `tests/Feature/Rls/*`.

### Mechanics

1. **Fail-closed is the whole design.** `app_current_company_id()` returns `NULL` when the GUC is unset.
   `company_id = NULL` evaluates to `UNKNOWN`, which is not `TRUE`, so **no context yields no rows** —
   never all rows. A forgotten middleware produces an empty screen and a bug report, not a breach.
2. **This is why `company_id` must be `NOT NULL`.** A NULL `company_id` is invisible to the predicate, so
   such a row leaks *out* of every tenant boundary rather than into one. Nullable tenant keys are the
   quiet version of having no RLS at all.
3. **RESTRICTIVE is the boundary; PERMISSIVE is the scope.** PostgreSQL `AND`s restrictive policies and
   `OR`s permissive ones. The company boundary is restrictive, so no future feature policy can widen it;
   feature access (own / team / all, driven by resolved permissions projected into GUCs) is added as
   permissive policies that can only ever narrow within the boundary. This decomposition is not a guess —
   it is the same algebra mature systems arrive at by hand, expressed declaratively.
4. **`FORCE ROW LEVEL SECURITY` matters as much as `ENABLE`.** Without `FORCE`, the table owner bypasses
   its own policies. Migrations run as the owner (correct — it must build and seed the schema); runtime
   runs as `qayd_app` (correct — it must be constrained).
5. **The GUC lifecycle is the highest-risk operational detail in the system.** GUCs are
   **per-connection**. Under PgBouncer *transaction* pooling a connection is returned to the pool at
   `COMMIT` and handed to another request. A `SET` (session-scoped) therefore leaks tenant A's context
   into tenant B's request. The rules, without exception:
   - always `SET LOCAL`, never `SET`;
   - always inside an **explicit transaction** (a `SET LOCAL` outside a transaction block is a silent
     no-op);
   - on **every** entry point — HTTP, queue worker, scheduled command, console, test harness;
   - one wrapper opens the transaction and sets the GUCs, and it is the only code allowed to set them;
   - **test it with genuine concurrency against a real pooler.** This failure mode is invisible in
     single-connection testing, which is exactly why it survives to production.
6. **Convention becomes mechanism through catalog introspection.** A CI test queries `pg_class`,
   `pg_policy` and `pg_attribute` and **fails the build** if any table with a `company_id` column lacks
   `attnotnull`, `relrowsecurity`, `relforcerowsecurity`, and the named restrictive policy. Five points
   of work, and it is the highest leverage-to-cost item in the entire Phase 1 research: it converts
   "we always paste the block" from a habit into a guarantee, and it covers every table that will ever
   be added, by anyone, forever.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| Every `company_id` table has NOT NULL + FORCE RLS + the restrictive boundary | CI catalog-introspection test (H6, specified) | CI ⚠️ |
| No runtime connection is superuser or `BYPASSRLS` | role migration + boot assertion | DB + boot |
| No tenant context ⇒ zero rows | `app_current_company_id()` NULL semantics | DB policy |
| A tenant can never read or write another tenant's row | restrictive policy `USING` + `WITH CHECK` | DB policy |
| Every model on a `company_id` table uses `BelongsToCompany` | `BelongsToCompanyArchTest` | Arch test |
| No ambient privilege bypass exists | `is_platform_admin` GUC deliberately unwired; arch test greps for bypass parameters | Arch test |
| Global catalogues are unwritable at runtime | `REVOKE` from `qayd_app` | DB GRANT |

⚠️ The catalog test is the one piece not yet built, and it is the piece that makes all the others durable.

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_27_000008_create_app_database_role.php`
- `/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_27_000009_enable_row_level_security.php`
- `/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_28_000003_lock_global_catalogues_for_app_role.php`
- `/Users/ali/projects/qayd-os/apps/api/app/Support/TenantContext.php`
- `/Users/ali/projects/qayd-os/apps/api/app/Models/Concerns/BelongsToCompany.php`
- `/Users/ali/projects/qayd-os/apps/api/tests/Feature/Rls/`

### Worked example — a queue job posting scheduled depreciation

A monthly job posts depreciation for every company. It does **not** inherit a tenant context, and that is
the correct behaviour: a job that inherits nothing sees nothing.

```
foreach (company ids from a platform-scoped read) {
    BEGIN;
      SET LOCAL app.current_company_id = <id>;
      SET LOCAL app.current_user_id    = <system actor id>;
      … build the draft, post it through PostJournalEntryAction …
    COMMIT;
}
```

Two properties fall out for free. A bug in the loop cannot post company A's depreciation into company B —
the `WITH CHECK` rejects the INSERT. And a job that forgets the `SET LOCAL` entirely reads zero
accounts, builds nothing, and fails loudly instead of silently doing the wrong thing.

Second example: **any new tenant table.** Copy `applyRowLevelSecurity()` verbatim. The reason "copy
verbatim" is acceptable engineering here — rather than a smell — is precisely the CI catalog check: a
forgotten paste fails the build, not production.

### Failure modes

- **`SET` instead of `SET LOCAL`.** The single highest-severity failure available: a silent cross-tenant
  breach under a pooler, invisible to every single-connection test.
- **`SET LOCAL` outside a transaction.** PostgreSQL warns and ignores it; the request then runs with
  whatever the previous request left behind.
- **Nullable `company_id`.** The row leaks out of every boundary.
- **Running the application as the owner role** in any environment. Every policy silently no-ops and the
  isolation tests "pass" by seeing all rows — a false green that is worse than no test.
- **A permissive policy that references something other than the company predicate**, quietly widening
  access. Permissive policies may narrow; they may never broaden.
- **An unrated, unaudited "why can't I see this record?" diagnostic.** Distinguishing *does not exist*
  from *exists in company X* is genuinely valuable for support cost — and is an existence oracle by
  construction. Build it (M8), and rate-limit and audit it from day one.
- **Reaching for a bypass.** There is no `->sudo()` here, deliberately. If you think you need one, you
  need a new permission, a new policy clause, or a `PlatformOperation` with a written reason.

### Testing strategy

1. Two companies, identical query, disjoint results — for every verb, not just `SELECT`.
2. A query with **no** tenant context returns zero rows on every tenant table (table-driven over the
   catalog, so new tables are covered automatically).
3. The application role cannot `SET ROLE`, cannot `BYPASSRLS`, and cannot write a global catalogue.
4. `WITH CHECK` rejects an INSERT/UPDATE that would move a row across the boundary.
5. **A real-pooler concurrency test**: interleaved requests for two tenants through PgBouncer in
   transaction mode, asserting no context leakage. This test is the point of the whole GUC discipline.
6. The CI catalog-introspection test (add a table without RLS in a test migration; assert the build
   fails).
7. `BelongsToCompanyArchTest`: every model whose table has `company_id` uses the trait.

**Effort to apply** — **1** per new table (copy the block; the CI check makes that safe); **5** for the
catalog-introspection test; **8** for the pooler-safe GUC lifecycle audit across all entry points.

**Confidence** — **High** on the shape: shipped, uniform across every tenant table, and covered by an
isolation suite. The pooler lifecycle is **High-confidence as a risk** (two independent research paths
reached it) and **unverified as a fix** until it is tested against a real pooler — which must happen
*before* pooling is enabled in production, not after.

---

## P-10 — Audit Pattern

**Intent** — Record what happened in append-only storage, at three deliberately separate tiers, with a
hash chain over the tier that carries legal weight.

**Use when** — always. Every lifecycle transition and every field mutation of a financial object.

**Do not use when** — the record is really *collaboration*. A comment thread must be editable and
deletable, because people need to correct themselves; forcing it into the immutable tier makes the
product hostile. That is tier A, and it is separate on purpose.

### Structure — three tiers, three different guarantees

```
 ┌── TIER A ── entry_comments ────────────────────────────────────────────────┐
 │   purpose: collaboration          mutable · deletable · NEVER hashed        │
 └────────────────────────────────────────────────────────────────────────────┘
 ┌── TIER B ── audit_logs ────────────────────────────────────────────────────┐
 │   purpose: field-level diffs      APPEND-ONLY (REVOKE + BEFORE UPD/DEL trg) │
 │   diffs as SELF-DESCRIBING JSONB — each diff carries its own field name and  │
 │   type, so history stays readable after a schema change. GIN-indexed.        │
 └────────────────────────────────────────────────────────────────────────────┘
 ┌── TIER C ── journal_entry_history ─────────────────────────────────────────┐
 │   purpose: lifecycle snapshots    APPEND-ONLY + HASH CHAINED                │
 │   one row per transition, carrying a canonical payload of the whole object   │
 └────────────────────────────────────────────────────────────────────────────┘

 chaining (tier C only):
   entry_chain_heads(company_id, scope, last_seq, last_hash)  ──FOR UPDATE──┐
   BEFORE INSERT ON journal_entry_history:                                   │
       chain_seq := last_seq + 1                                             │
       prev_hash := last_hash                                                │
       hash      := '$1$' || encode(digest(prev_hash || canonical_payload,   │
                                           'sha256'), 'hex')                 │
       UPDATE the head                                                       │
   ── the application NEVER supplies hash / prev_hash / chain_seq;  ──────────┘
      the trigger OVERWRITES whatever was passed.

   daily: KMS-signed anchor over (company_id, chain_seq, hash) → bounds any rewrite to one day
```

### Participants

`entry_comments` (specified); `audit_logs` (**shipped** — append-only trigger + `REVOKE`, with dormant
`hash`/`prev_hash` columns); `journal_entry_history` (specified); `entry_chain_heads` (specified);
`AuditLogger::record`; `VerifyEntryChainAction` (specified); `posting_attempts` (specified);
`HashChainBrokenException` (`500` — if it fires, integrity is broken, not merely a request).

### Mechanics

1. **The trigger computes the chain, not the application.** A chain that application code can write is a
   chain an application bug — or a compromised deployment — can rewrite. The `BEFORE INSERT` trigger takes
   `FOR UPDATE` on the chain head, assigns `chain_seq`/`prev_hash`/`hash`, and overwrites any supplied
   values. Note that this lock is essentially free: posting already serializes on number allocation
   (P-07), so the chain head is acquired inside a window that is already serial for that scope. Acquire
   it in the documented global order (P-08).
2. **Persist a canonical payload; do not re-derive the digest from live columns.** Canonicalise (RFC
   8785-style, versioned and frozen with golden vectors) and store the exact bytes hashed. Verification
   then becomes a **pure function of the audit rows**, and adding a business column is not a breaking
   migration. Re-deriving from live fields means every schema change silently invalidates history.
3. **Version the digest in-band** (`$1$<hex>`), so the algorithm can evolve without invalidating what
   came before.
4. **Anchor externally.** An unkeyed chain with a known genesis can be rewritten end to end and verify
   perfectly. A daily KMS-signed anchor over `(company, chain_seq, hash)` bounds the undetectable rewrite
   window to a single day. This is the difference between tamper-*evident* and tamper-*evident to someone
   who did not do the tampering*.
5. **Cover every amount column.** A chain that omits `amount_currency`, the FX rate, the maturity date or
   the dimension allocation leaves those fields silently mutable on a "sealed" record. Hash the whole
   canonical payload, not an allowlist.
6. **Shadow-capture reconciliation — the strongest idea in this pattern.** A PL/pgSQL trigger records
   mutations *independently* of the Action layer. A scheduled pass then reconciles trigger-sourced rows
   against Action-sourced rows: **a trigger row with no Action peer means something wrote outside the
   Action layer.** That is a class of event most systems cannot detect at all, and it is the mechanism
   that keeps `01`/P10 ("business logic lives in Actions") honest at runtime rather than only at review.
7. **Never let the audit write deadlock the business write.** Keep it in the same transaction — a
   committed business row with a rolled-back audit row is a lie, and the reverse is worse — but only on
   tables that never see concurrent DDL. An in-transaction log insert blocking on an `ALTER TABLE` is a
   documented deadlock, and it takes the business transaction with it.
8. **No bypass tokens.** Not one. `01`/P2 applies with full force here: an audit trail with an off switch
   is a decoration.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| Tier B/C rows are never updated or deleted | `REVOKE UPDATE, DELETE` + `BEFORE UPDATE OR DELETE` trigger | DB (both layers) |
| `hash`/`prev_hash`/`chain_seq` are never application-supplied | `BEFORE INSERT` trigger overwrites | DB trigger |
| The chain has no gaps and verifies | `VerifyEntryChainAction` in CI + scheduled | CI ⚠️ |
| A full-chain rewrite is detectable | daily KMS-signed anchors | External ⚠️ |
| Every lifecycle transition produces exactly one tier-C row | test over the transition map (P-18) | Test |
| History survives a schema change | self-describing JSONB diffs (no FK to field metadata) | Schema design |
| Writes outside the Action layer are detected | shadow trigger + reconciliation pass | Scheduled check |
| Audit rows are tenant-scoped | RLS (P-09) | DB policy |

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_27_000010_create_audit_logs_table.php`
  — the append-only idiom in full: privilege revocation **plus** a `BEFORE UPDATE OR DELETE` trigger, RLS,
  and `hash`/`prev_hash` columns present and **deliberately dormant** (TD-06).
- `/Users/ali/projects/qayd-os/apps/api/tests/Feature/Audit/AuditLogTest.php`

**Honest gap:** the chain is not active, `journal_entry_history` is not written by the posting path, and
the shadow trigger does not exist. The table, the trigger idiom and the RLS block ship — which is the
expensive half. The pattern is **Partial**.

### Worked example — `posting_attempts`

An append-only record of *rejected* postings: violation codes, entry source, actor, and AI confidence.
Same append-only idiom, RLS block and tenant scoping; **no chain**, because a rejected attempt carries no
legal weight and does not need tamper evidence.

It pays twice. As a compliance artifact it answers "show me every attempt to post into a closed period"
— a question that is otherwise unanswerable because a failed post normally leaves no trace at all. As an
AI artifact it is the highest-quality training signal available, because it records exactly what a human
or an agent got wrong and precisely why (P-12: rejections are the labelled negative set).

Second example: `entry_comments` — tier A, mutable, RLS-scoped, and explicitly *excluded* from the chain,
so that a product affordance people expect (editing a comment) never becomes an integrity question.

### Failure modes

- **One table for all three tiers.** You then get either immutable comments or an editable legal record.
  There is no third outcome.
- **Computing the hash in PHP.** Moves the guarantee to the layer least able to hold it.
- **A "clear the seal" code path** for tests or data fixes. If the test suite needs one, the test suite is
  wrong.
- **Per-field audit rows for a bulk edit.** One edit producing hundreds of rows makes the trail unusable
  and the write path slow. Snapshot at the lifecycle level (tier C); diff at the field level (tier B) only
  for genuinely field-scoped edits.
- **Auditing on a different connection or transaction.** Either direction of divergence is a lie.
- **Exposing sensitive columns in history** to a viewer who lacks permission on the column itself. Audit
  output needs the same field-level filtering as the record (M7/L4) — rarely implemented anywhere, and
  obvious in hindsight after the first incident.
- **Chaining without anchoring**, then describing the result as tamper-proof.

### Testing strategy

1. **A tamper matrix**: every mutation attempt on tiers B and C — `UPDATE`, `DELETE`, `TRUNCATE`, column
   rewrite — rejected, as the application role and as a privileged-but-not-owner role.
2. A hand-forged `hash` supplied on INSERT is overwritten by the trigger, not honoured.
3. Chain verification over 10,000 rows passes; a single mutated byte in a canonical payload fails
   verification and names the first broken link.
4. **50-way parallel posting produces a single unforked chain** with contiguous `chain_seq` — the test
   that justifies the head lock.
5. A direct SQL mutation of a business table is detected by the shadow reconciliation pass.
6. Canonicalisation golden vectors: frozen fixtures that must hash identically forever.
7. RLS: company B cannot read company A's audit rows.

**Effort to apply** — **21** for the chain (the head-lock concurrency is the genuinely hard part, and the
50-way test is most of the trust); **3** for a new append-only, unchained tier-B/attempt table;
**1** to add the append-only block to a new table.

**Confidence** — **Medium.** The table, the two-layer append-only enforcement and the RLS block ship and
are tested. The chain is designed but unbuilt, and its concurrency semantics — head-lock ordering under
parallel posting — are the part that must be proven rather than reasoned about.

---

## P-11 — Event Pattern

**Intent** — Let modules react to each other's facts through after-commit domain events delivered via a
transactional outbox, and make that the **only** cross-module coupling that exists.

**Use when** — another module must know that something happened: posting → trial-balance invalidation,
Reverb real-time push, webhooks, AR/AP sub-ledger caches, notification, AI training signals.

**Do not use when** — the reaction must be atomic with the fact. An event is not a transaction. If the
consequence must succeed or the fact must not happen, it belongs inside the same Action and the same
transaction. Also not when what you want is an *answer* — publish facts, not questions; a request/response
dependency is a seam (P-03).

### Structure

```
 ┌─ Action ────────────────────────────────────────────────────────────┐
 │  BEGIN                                                              │
 │     … business writes …                                             │
 │     INSERT INTO outbox (event_name, payload, available_at)          │  ◄── SAME transaction
 │  COMMIT                                                             │
 └───────────────────────────┬─────────────────────────────────────────┘
                             │  rollback ⇒ no outbox row ⇒ the event CANNOT fire
                             │  commit   ⇒ the outbox row is durable ⇒ it CANNOT be lost
                             ▼
                  relay worker
                     SELECT … FOR UPDATE SKIP LOCKED  LIMIT n
                     dispatch → mark delivered
                             ▼
                  broker / listeners        (at-least-once ⇒ listeners MUST be idempotent)
                             ▼
        Reverb push · webhooks · cache invalidation · AI training log · notifications
```

**Today:** the event is dispatched in PHP after the transaction returns
(`PostJournalEntryAction::execute()`). That is already correct on the axis that matters most — an event
never precedes its fact — and lossy on the other: if the process dies between `COMMIT` and `event()`, the
event is gone. The outbox closes that gap for five points.

### Participants

`App\Events\Accounting\JournalEntryPosted` (with a `NAME` constant — event names are a catalogue, not
call-site strings); `PostJournalEntryAction`; `outbox` table (specified); the relay worker (specified);
listeners registered in `EventServiceProvider`.

### Mechanics

1. **Nothing is dispatched before commit.** The Action calls the service, the service owns the
   transaction, and `event()` runs only after the service returns. If the post throws, nothing is
   emitted — that behaviour is a test, not a comment.
2. **The payload is a minimal, versioned fact.** `JournalEntryPosted` carries `companyId`,
   `journalEntryId`, `journalNumber`, `entryType`, `baseTotal` and `currencyCode` — enough for a
   subscriber to act without re-reading, and no entity handle a subscriber could mutate. Money stays a
   `numeric-string` (P-14) even in transit.
3. **The listener re-establishes tenant context itself.** A queue worker inherits nothing (P-09); the
   payload's `companyId` is what it uses to open its own transaction and `SET LOCAL`. A listener that
   assumes an ambient context reads zero rows — the correct failure.
4. **At-least-once delivery means idempotent handlers.** The outbox row id is the dedupe key. Any handler
   that cannot be run twice safely is a bug, not a delivery-guarantee requirement.
5. **Events are the only cross-module write path.** No module writes another module's tables — enforced
   by an architecture test on namespace → table access. This is the property that keeps modules
   independently testable and replaceable, and its absence is the single clearest cause of long-term
   coupling in comparable systems, where modules extend each other by inheritance and method overrides
   instead.
6. **Emit on rejection too, where the rejection is information.** `accounting.posting.rejected` is how the
   AI-supervision module learns that an agent is repeatedly attempting to post into a closed period. A
   system that only publishes successes cannot observe its own friction.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| No event is emitted before its transaction commits | Action structure + test (failed post emits nothing) | Test |
| A committed fact cannot lose its event | outbox row written in the same transaction | DB |
| A rolled-back fact cannot emit an event | outbox row rolls back with it | DB |
| Handlers tolerate duplicate delivery | dedupe on outbox id + test | Test |
| No module writes another module's tables | architecture test on namespace → table access | Arch test |
| Event names come from a catalogue | `NAME` constants + test enumerating dispatched names | Test |

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/app/Events/Accounting/JournalEntryPosted.php`
- `/Users/ali/projects/qayd-os/apps/api/app/Actions/Accounting/PostJournalEntryAction.php`

**Honest gap:** the outbox and relay are specified, not built; concrete listeners are not yet wired. The
after-commit discipline ships. **Partial.**

### Worked example — `accounting.period.closed`

One fact, three unrelated reactions, zero coupling:

```
 ClosePeriodAction ── COMMIT ──► outbox ──► accounting.period.closed
                                              ├─► reporting module:   invalidate cached statements
                                              ├─► AI module:          expire open proposals in range
                                              └─► notification module: email the approver
```

None of the three may touch `fiscal_periods`, `period_close_runs` or each other's tables. Each can be
deleted, rewritten or replaced without the close workflow noticing — which is the entire point, and the
property that is impossible to retrofit once modules have started reading each other's schemas.

Second example: `accounting.journal.posted` → the trial-balance read model (P-15) refreshes; the AR
sub-ledger cache updates; a webhook fires to the customer's own system. Same fact, no shared code.

### Failure modes

- **Dispatching inside the transaction.** Subscribers act on facts that may still roll back — and the
  ones that send email or call webhooks cannot be rolled back.
- **A listener that throws and rolls something back.** Listeners run after the fact is durable; a
  throwing listener must fail *itself*, not the fact.
- **Putting an Eloquent model in the payload** and letting a listener mutate it. Payloads are values.
- **Using events for request/response.** If the caller needs an answer, it needs an interface (P-03).
- **Event names as call-site strings.** They drift, and a typo produces silence rather than an error.
- **Forgetting the worker has no tenant context**, then "fixing" it by widening a policy.
- **A "reliable" event bus without an outbox.** Broker downtime silently drops facts, and the loss is
  discovered by a customer noticing a stale figure.

### Testing strategy

1. A post that throws dispatches nothing; a post that commits dispatches exactly once.
2. The outbox row and the business row commit or roll back **together** (kill the transaction between the
   two writes and assert neither survives).
3. The relay is idempotent under duplicate delivery of the same outbox row.
4. A listener with no tenant context reads zero rows rather than another tenant's rows.
5. Architecture test: each module's Actions touch only that module's tables.
6. Every dispatched event name is a declared constant.

**Effort to apply** — **5** for the outbox and relay (once, platform-wide); **1** per new event
(class + constant + test); **2** per listener.

**Confidence** — **High** on after-commit emission (shipped and structurally enforced by where `event()`
sits). **Medium** on the outbox, which is designed and unbuilt.

---

## P-12 — AI Action Pattern

**Intent** — Confine every AI write to proposal tables — enforced by **database GRANTs**, not application
code — and make a normal, human-triggered Action the only way a proposal becomes a fact.

**Use when** — any AI output that would otherwise become financial data: drafted journal entries, bank
match proposals, chart-of-accounts mappings, dimension suggestions, close adjustments, document
extraction.

**Do not use when** — the output is purely advisory and never persisted (a chat answer, an explanation);
or when the operation is machine-verifiable *and* reversible *and* a human adds nothing — do not put a
person in front of a CHECK constraint (P-04).

### Structure

```
 FastAPI AI engine ─── connects as role: qayd_ai ────────────────────────────────┐
    GRANT SELECT              ON read models and reference data                   │
    GRANT INSERT              ON *_proposals  ONLY                                │
    NO INSERT/UPDATE/DELETE   ON journal_entries, journal_lines, ledger_entries,  │
                                 accounts, fiscal_*, audit_logs, ANY financial table
                                                                                  ▼
                                              ┌─────────────────────────────────────────┐
                                              │  match_proposals / dimension_suggestions │
                                              │    confidence  NUMERIC(5,4) CHECK 0..1   │
                                              │    model_id, model_version   NOT NULL    │
                                              │    rationale   JSONB         NOT NULL    │
                                              │    outcome  pending|accepted|rejected|   │
                                              │                              expired     │
                                              │    UNIQUE (subject_id) WHERE pending      │
                                              └───────────────────┬─────────────────────┘
                                                                  │
                                   human reviews the rationale ───┤
                                                                  ▼
                              AcceptMatchProposalAction   ── a NORMAL Action, human actor
                                  ├─► freshness re-check (P-06)
                                  ├─► delegates to ApplyReconciliationAction / PostingService (P-01)
                                  └─► stamps outcome, outcome_by, outcome_at

 the drafting path, already shipped:
    AI-authored journal entry ──► created as 'draft'
    any other status on INSERT  ──► trg_no_ai_autopost RAISES  (database error, not a code check)
```

### Participants

`qayd_ai` role and its GRANTs (specified); `journal_entries.ai_generated` / `ai_confidence` +
`chk_je_ai_confidence` (**shipped**); `trg_no_ai_autopost` (**shipped**);
`JournalRuleException::aiCannotSubmit()` (**shipped**); `JournalEntryData::$aiGenerated` /
`$aiConfidence`; `match_proposals` and its acceptance Actions (specified); `posting_attempts` (P-10).

### Mechanics

**Three enforcement layers, in strength order.** This ordering is the pattern:

```
 1. GRANTs      the AI role has no privilege to write a financial table.
                A prompt injection, a bug, or a fully compromised AI service
                cannot write one.  ◄── THIS is the guarantee.
 2. Triggers    trg_no_ai_autopost: an AI-generated entry can only be created as a draft.
                Defends against the app writing on the AI's behalf.
 3. Actions     JournalRuleException::aiCannotSubmit — a clear 403 with a good message.
                A courtesy, not a guarantee.
```

Every discussion of "how do we stop the AI from…" should end at layer 1. If the answer requires layers 2
or 3 to hold, the boundary is in the wrong place.

**Every proposal carries three things, all `NOT NULL`:**

- **`confidence ∈ [0,1]`**, CHECK-constrained. Note what confidence is *for*: ranking and triage. It is
  **not** a permission — see the failure modes.
- **`model_id` + `model_version`.** Without them, an accuracy regression is unattributable and a bad
  model version is not a queryable cohort. With them, "which proposals came from the model we rolled
  back?" is a `WHERE` clause.
- **`rationale` as machine-readable JSONB** — feature contributions, matched tokens, the rules that
  fired — not prose. A human must be able to review the *reasoning*, not just the conclusion, and prose
  cannot be aggregated, diffed, or regression-tested.

**Proposals are a read model plus a training log.** `outcome` may be `UPDATE`d (that is what makes them
different from ledger rows); the financial side never is. A partial unique index
(`UNIQUE (subject_id) WHERE outcome = 'pending'`) keeps exactly one live proposal per subject, so
acceptance is unambiguous.

**Acceptance re-validates freshness.** A proposal computed twenty minutes ago may now over-reconcile or
target a since-posted entry. Acceptance carries a version or plan hash and fails with a diagnosing `409`
(P-06) rather than applying a stale plan.

**Deterministic rules run first, and the AI only sees the remainder.** This is not merely an efficiency
choice: it keeps the AI honest (it is never credited with matches a rule would have made), it keeps the
evaluation set clean, and it bounds the blast radius of a bad model to the genuinely ambiguous cases.

**Rejections are as valuable as acceptances.** Together with `posting_attempts` (P-10) they are the
labelled negative set — the part of the training signal that is otherwise impossible to collect.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| The AI role cannot write any financial table | PostgreSQL `GRANT`/`REVOKE` on the `qayd_ai` role | **DB GRANT** |
| An AI-generated entry can only be created as a draft | `trg_no_ai_autopost` | DB trigger |
| An AI-generated entry carries a confidence in [0,1] | `chk_je_ai_confidence` | DB CHECK |
| An AI actor cannot submit, approve or post | Action guards (`aiCannotSubmit`) + P-04 | Action ⚠️ |
| An accepted proposal names the accepting human | shape CHECK (`accepted` ⇒ `outcome_by NOT NULL`) | DB CHECK |
| At most one pending proposal per subject | partial unique index | DB index |
| A stale proposal cannot be applied | freshness token re-checked under lock (P-06) | DB predicate |
| Every proposal is attributable to a model version | `NOT NULL` columns | DB |

### Reference implementation

- `trg_no_ai_autopost` and `chk_je_ai_confidence` in
  `/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php`
- `JournalRuleException::aiCannotSubmit()` used by
  `/Users/ali/projects/qayd-os/apps/api/app/Actions/Accounting/SubmitForApprovalAction.php`
- `/Users/ali/projects/qayd-os/apps/api/app/Data/Accounting/JournalEntryData.php` (`aiGenerated`,
  `aiConfidence`)
- `/Users/ali/projects/qayd-os/apps/api/tests/Feature/Accounting/JournalTriggersTest.php`

**Honest gap:** the `qayd_ai` role, the proposal tables and the acceptance Actions are specified, not
built. The *boundary* — the strongest part — ships as a trigger and a CHECK, but not yet as GRANTs.
**Partial.**

### Worked example — bank reconciliation matching

```
 bank_transactions imported to a SUSPENSE account immediately
     ⇒ the bank balance is correct BEFORE any matching, and
       the suspense balance IS the unmatched backlog
          │
   tier 1 │  deterministic rules  (exact reference / exact amount)   — no AI involved
          ▼
   tier 2 │  AI proposals → match_proposals(confidence, rationale, model_version)
          │                  NEVER reconciliation_links, NEVER ledger_entries
          ▼
   tier 3 │  human confirmation → AcceptMatchProposalAction
          │      → ApplyReconciliationAction (the normal path, with its own invariants)
```

Three properties fall out. The bank balance is right from the moment of import, independent of matching
quality. The AI is confined to the genuinely ambiguous remainder. And unmatching is an `INSERT` of a
compensating link plus a reversing entry (P-13), never a `DELETE` — so even an accepted-then-regretted AI
proposal leaves a complete history.

Second example: **opening-balance chart mapping.** The AI proposes a mapping from a prior system's trial
balance, surfaces unmapped accounts and the residual (P-05, P-17), a human approves, and the entry posts
through the normal `PostingService` (P-01). The AI never touches `accounts` or `journal_entries`.

### Failure modes

- **Giving the AI service the application role "temporarily."** The temporary version of this decision is
  the permanent version. The role separation is the pattern; without it, the rest is decoration.
- **Auto-accepting above a confidence threshold.** This is the most tempting failure and the most
  damaging: it re-creates auto-posting with extra steps, and it does so through a *policy* knob rather
  than a permission. If a business genuinely wants it, it must be an explicit, audited, per-rule policy
  object with a named owner, a monetary ceiling, a reversal path, and a rejection sampling rate — never
  a default, never a global threshold, and never the AI's own confidence deciding its own authority.
- **Rationale as prose.** Unaggregatable, unreviewable at scale, and untestable.
- **Omitting `model_version`.** A regression becomes unattributable and un-rollbackable.
- **Letting the AI author the approval record**, or letting one AI approve another's proposal.
- **Deleting rejected proposals.** They are the training set.
- **Accepting a proposal without a freshness check**, applying a plan computed against a world that has
  moved.
- **Exposing the proposal table to the AI for `SELECT` on other tenants** — the AI role is tenant-scoped
  by RLS like everything else (P-09); a "model needs cross-tenant data" argument is a consolidation-class
  decision, not an AI decision.

### Testing strategy

1. **Connect as `qayd_ai` and assert `INSERT`/`UPDATE`/`DELETE` fails on every financial table** — table
   driven from the catalog, so a table added next year is covered without touching the test.
2. An AI-generated entry cannot be created with any status but `draft`, via the Action *and* via raw SQL.
3. An AI actor cannot submit, approve or post.
4. `chk_je_ai_confidence` rejects a missing or out-of-range confidence on an AI-generated entry.
5. Accepting a stale proposal returns a diagnosing `409` and changes nothing.
6. An accepted proposal has a non-null human `outcome_by`; a shape CHECK rejects the alternative.
7. Two pending proposals for one subject are impossible (partial unique index).
8. RLS: the AI role sees exactly one tenant's data per transaction.

**Effort to apply** — **8** for the first subsystem (role + GRANT matrix + one proposal table + acceptance
Action + the GRANT test); **3** per additional proposal type.

**Confidence** — **High** on the boundary itself: the principle is settled, and two of the three
enforcement layers already ship as database objects. **Medium** on the proposal-table specifics, which are
designed against one worked subsystem and will be refined by the second.

---

## P-13 — Immutability & Correction Pattern

**Intent** — Never mutate a fact that has been relied upon; correct it by recording a new, linked,
reversing fact — and make the link graph acyclic and non-over-reversing in the database.

**Use when** — the record has been relied upon: a posted entry, an approved snapshot, a filed return, an
issued invoice, an applied reconciliation.

**Do not use when** — the record is still a draft (edit it, with P-06); or when the thing to be
"corrected" is a derived read model (rebuild it, with P-15 — a read model has no history to protect
because it *is* history, recomputed).

### Structure

```
  JE-FY2026-000123   posted ──────────────────────────────┐  stays posted FOREVER
      ▲                                                    │  number intact, hash intact,
      │ reversed_entry_id                                  │  ledger rows intact
      │                                                    ▼
  REV-FY2026-000004  posted THROUGH PostingService (P-01) ──► new ledger_entries rows
      reversal_reason        NOT NULL   (queryable — not interpolated into a text ref)
      reversed_by_user_id    NOT NULL
      reversal_kind ∈ {full, partial, storno}

  DB-enforced properties:
    CHECK (reversed_entry_id <> id)                          no self-reversal        [SHIPPED]
    trigger: reject cycles (A→B→A, A→B→C→A)                  no reversal loops
    UNIQUE (reversed_entry_id) WHERE reversal_kind = 'full'  at most ONE full reversal
    transition map: posted ↛ draft                           NO un-post path exists  (P-18)
```

### Participants

`journal_entries.reversed_entry_id` / `reversal_entry_id` / `is_reversal` (**shipped**);
`chk_je_no_self_reverse` (**shipped**); `ReverseJournalEntryAction` (S2-06, specified);
`ReversalStrategy` seam (P-03); `PostingDateResolution` (P-17); `ledger_entries` append-only trigger
(P-02); `LedgerImmutableException`, `AlreadyReversedException` (specified).

### Mechanics

1. **The original is never touched.** Not its status beyond `posted → reversed`, not its lines, not its
   ledger rows, not its number, not its hash. The append-only trigger on `ledger_entries` and the
   posted-parent trigger on `journal_lines` make this a database fact rather than an Action-layer
   promise.
2. **The reversal is a first-class posted document**, produced by a `ReversalStrategy` (P-03) and posted
   through the **same** `PostingService` (P-01). It therefore inherits, with no special code: the
   balance check, the period gate, gapless numbering, the ledger projection, the audit trail, the
   after-commit event, and — importantly — **its own reversibility**. A reversal that can itself be
   reversed is the natural consequence of not building a special path.
3. **There is no un-post.** The lifecycle map has no `posted → draft` edge and the status trigger rejects
   it (P-18). The comparable pattern to refuse is walking `posted → draft → cancel`, which transiently
   returns a posted document to draft — during which window every invariant that depends on "posted means
   final" is false.
4. **Date handling raises, never shifts.** If the reversal date falls in a locked period, raise and return
   a `PostingDateResolution(requested, suggested, violations[])` the caller must explicitly accept
   (P-17). Silently moving the date of a financial event changes which period it belongs to, which
   changes a filed figure, and nobody finds out.
5. **Partial correction is a normal linked entry.** `reversal_kind = 'partial'` with the same
   `reversed_entry_id`; only *full* reversal carries the uniqueness constraint. This is what allows a
   sequence of partial corrections without either forbidding them or permitting over-reversal.
6. **The reason is a column, not a string in a reference field.** `reversal_reason NOT NULL` means "show
   me every reversal for reason X in Q3" is a query. Interpolating the reason into free text makes it
   unqueryable exactly when someone needs to query it.
7. **Correction by insertion generalises beyond entries.** Unreconciling writes a compensating link row
   plus, where money moved, a reversing entry — never a `DELETE` of the original link. A superseded
   snapshot is a new version with the old marked superseded — never an overwrite.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| A posted entry's lines are never updated or deleted | `trg_journal_lines_no_update_when_posted` | DB trigger |
| Ledger rows are never updated or deleted | `trg_ledger_entries_append_only` | DB trigger |
| An entry cannot reverse itself | `chk_je_no_self_reverse` | DB CHECK |
| The reversal graph is acyclic | cycle-rejecting trigger (specified) | DB trigger ⚠️ |
| An entry is fully reversed at most once | partial unique index (specified) | DB index ⚠️ |
| `posted → draft` is impossible | transition trigger (P-18, specified) | DB trigger ⚠️ |
| A reversal reason always exists and is queryable | `reversal_reason NOT NULL` (specified) | DB |
| A reversal is posted through the one path | architecture test (P-01) | Arch test |
| Deleting a posted entry's lines is impossible | FK `ON DELETE RESTRICT`, never `CASCADE` | DB FK |

⚠️ marks S2-06 work. The columns and the self-reversal CHECK ship today; the cycle trigger, the partial
unique index and the transition trigger do not.

### Reference implementation

**Partial.** `/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php`
(`reversed_entry_id`, `reversal_entry_id`, `is_reversal`, `chk_je_no_self_reverse`) and
`/Users/ali/projects/qayd-os/apps/api/database/migrations/2026_07_28_000007_create_ledger_entries_table.php`
(the append-only trigger). The reversal Action is S2-06, unbuilt.

### Worked example — unreconciling

The requirement is real: a user matched the wrong invoice to a payment and must undo it. The naive
implementation deletes the partial-reconcile row; the aggressive one deletes and recreates journal items
on a *posted* entry behind a `force_delete` flag. QAYD's append-only trigger forbids both outright, which
is the point — the constraint forced a better design rather than being worked around:

```
  reconciliation_links      APPEND-ONLY
      + link (invoice ↔ payment, amount)                     original match
      + link (invoice ↔ payment, −amount, compensates_id)    the undo
      + reversing journal entry, where money actually moved  (through PostingService)

  reconciliation_groups     REBUILDABLE READ MODEL (P-15)
      RebuildReconciliationGroupsAction recomputes from the links
```

Same user-visible outcome, a complete audit trail, and no mutation of posted history. It also makes an
entire bug class unreachable: a system that maintains matching groups by raw-SQL `UPDATE` against a
mutable ledger has a documented staleness defect; a rebuildable read model over append-only links cannot.

Second example: a **superseded trial-balance snapshot.** Approving a snapshot makes it immutable (a
`BEFORE UPDATE` trigger permitting only the approval columns, and only from `draft`). A restatement
inserts a new version and marks the old superseded. The published figure that someone relied on remains
retrievable forever.

### Failure modes

- **A `force` / `skip_readonly_check` / `allow_unpost` parameter.** `01`/P2: an invariant with an off
  switch is not an invariant. This is the single most common way immutability is lost, and it always
  arrives attached to a legitimate-sounding operational need.
- **Reversing by copy-and-negate outside the posting path.** Skips the period gate, the numbering, the
  projection and the audit — and produces a reversal that cannot itself be reversed.
- **Deleting instead of reversing** because "it was posted by mistake ten minutes ago". The mistake is a
  fact; the correction is another fact.
- **`ON DELETE CASCADE` from entries to lines.** One `DELETE` on a header silently erases a posted
  document. Use `RESTRICT`.
- **Letting the AI post a reversal.** It is the one operation that can silently undo audited history.
  Human approval always (P-04, P-12).
- **Reversing a reversal without cycle detection**, producing an unfollowable graph and, eventually, an
  infinite loop in a report that walks it.
- **Allowing a second full reversal**, so the books show the correction twice.

### Testing strategy

1. `UPDATE` and `DELETE` on a posted entry's lines and on its ledger rows are rejected — as the
   application role and directly in SQL.
2. `chk_je_no_self_reverse` rejects `reversed_entry_id = id`.
3. `A → B → A` and `A → B → C → A` are rejected by the cycle trigger.
4. A second **full** reversal of one entry is rejected by the partial unique index; a second **partial**
   reversal succeeds.
5. A reversal into a locked period **raises** and returns a resolution — assert the date was *not*
   shifted (this is the test that encodes the refusal).
6. A reversal of a reversal is legal, links correctly, and produces balanced ledger rows.
7. `posted → draft` is rejected at the database, not only by the Action.
8. The original entry's `journal_number`, ledger rows and (once chained) hash are byte-identical before
   and after the reversal.

**Effort to apply** — **8** (Action + strategy seam + cycle trigger + partial unique index + reason
columns + the test matrix above).

**Confidence** — **High** on the principle and on the shipped constraints. **Medium** on the cycle-trigger
implementation details until S2-06 lands, since graph triggers are easy to write in a way that is correct
for the common case and quadratic for the deep one.

---

## P-14 — Money Pattern

**Intent** — Represent money as `NUMERIC(19,4)` in PostgreSQL and as a `numeric-string` in PHP; compute
only with bcmath at a declared scale; compare only with `bccomp`; distribute remainders deterministically
with a persisted trace.

**Use when** — any monetary quantity, any rate-derived amount, and any percentage that will be multiplied
by money.

**Do not use when** — the value is genuinely a measurement rather than money (a display ratio, a model
confidence score, a row count). The moment such a value multiplies money, it re-enters this pattern —
which is why tax factors are stored as **integer ppm** and not as floats.

### Structure

```
 PostgreSQL   NUMERIC(19,4)          money           NUMERIC(18,6)   exchange rates
        │                                                   │
        │  PDO returns numeric TEXT — never a PHP float      │
        ▼                                                    ▼
 PHP    numeric-string  ──── bcadd / bcsub / bcmul / bcdiv (scale: 4) ────► numeric-string
        │
        │  comparison:  bccomp($a, $b, 4) === 0        ◄── never ==, never ===, never abs() < ε
        │  base amount: bcmul($amount, $rate, 4)       ◄── one code path, one rate, one scale
        ▼
 PostgreSQL   NUMERIC(19,4)

        ┌──────────────────────────────────────────────────────────────┐
        │  NO IEEE-754 VALUE EXISTS ANYWHERE BETWEEN THESE TWO POINTS. │
        └──────────────────────────────────────────────────────────────┘
```

### Participants

Every money column (`NUMERIC(19,4)`) and rate column (`NUMERIC(18,6)`);
`JournalEntryPostingService::assertBalanced()` and `::money()`; `WritesJournalDraft::insertLines()`;
`@param numeric-string` annotations on every DTO and exception; `chk_*_nonneg` /
`chk_*_rate_positive` CHECKs; a shared rounding helper and a penny-distribution helper (specified).

### Mechanics

1. **The scale argument is never omitted.** `bcadd($a, $b)` without a scale silently truncates to
   `bcscale()`'s default, which is process-global state that any library can change. Every bcmath call in
   this codebase passes `4` (or `6` for rates, or a wider intermediate scale that is then explicitly
   rounded). A grep for a bcmath call without a scale argument is a review failure.
2. **Comparison is `bccomp(...) !== 0`, and the tolerance is zero.** Because no float exists, there is no
   drift; because there is no drift, there is nothing to tolerate. An epsilon here would not be
   robustness — it would be a mechanism for hiding a defect in whatever produced the numbers. Systems
   built on floats need epsilon machinery (one adds `2**(log2(x)−50)` inside its rounding helper to
   correct IEEE-754 tie mis-detection); that entire category of complexity simply does not exist here,
   and importing any formula from such a system means deleting its epsilon first.
3. **Values read back from the database are narrowed, not trusted.** `money()` casts the attribute to a
   string and raises `LogicException` if it is not numeric — a non-numeric value from a `NUMERIC` column
   is a schema or driver invariant break, and failing loudly beats propagating `"0"`.
4. **The DB/PHP rounding-agreement rule.** PostgreSQL `NUMERIC` rounds **half away from zero**; bcmath
   **truncates**. Any value computed in PHP at a wider scale and stored at 4 must be rounded *explicitly
   in PHP*, through one shared helper, before it is written. Never let an implicit cast perform the last
   rounding step: the same computation must produce the same fils regardless of which side performs it,
   and a discrepancy of one fils between the API response and the stored row is both a support ticket and
   a reconciliation failure.
5. **Deterministic penny distribution.** When a total must be split across N parts (tax across
   repartition lines, an allocation across installments, a discount across items):

   ```
   a) share_i     := truncate(total × weight_i, 4)          for each part
   b) remainder   := total − Σ share_i                       (exact, in bcmath)
   c) distribute the remainder ONE unit (0.0001) at a time over the parts
      in a STABLE sort order (line_number, then id)          ← determinism lives here
   d) emit a TRACE: { part, raw, rounded, adjustment }       ← persisted, not logged
   ```

   The stable sort key is what makes the result reproducible across runs, machines and PHP versions. The
   trace is a first-class output because e-invoicing and dispute resolution both need to show where each
   fils went; keeping the unrounded intermediates alongside the posted amounts is not optional.
6. **Aggregate in SQL.** `SUM()` over `NUMERIC` is exact, is one round trip, and uses the index. A PHP
   loop over rows is O(rows) round trips and is, in float systems, historically where drift accumulates.
   The one legitimate exception is the posting balance check, which deliberately re-derives from the
   fetched lines because it must validate *those exact rows* before they are committed.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| No float ever holds money | `numeric-string` annotations + PHPStan + arch test grepping `(float)`/`floatval` near money | Static analysis |
| Every money column is `NUMERIC(19,4)` | CI catalog check over `information_schema.columns` | CI ⚠️ |
| Money is never negative where it must not be | `chk_je_totals_nonneg`, `chk_jl_base_nonneg`, `chk_le_one_sided` | DB CHECK |
| Rates are positive | `chk_je_rate_positive`, `chk_jl_rate_positive` | DB CHECK |
| Totals equal the exact sum of their parts | `assertBalanced()` + `chk_je_balanced` | DB CHECK |
| A distribution sums exactly to its original | property test over the helper | Test |
| DB and PHP agree on the rounded value | shared rounding helper + round-trip test | Test |
| A non-numeric money value is never propagated | `money()` narrowing guard raising `LogicException` | Runtime |

### Reference implementation

- `/Users/ali/projects/qayd-os/apps/api/app/Services/Accounting/JournalEntryPostingService.php` —
  `assertBalanced()` (bcmath at scale 4, zero-tolerance `bccomp`, both currencies) and `money()` (the
  narrowing guard).
- `/Users/ali/projects/qayd-os/apps/api/app/Actions/Accounting/Concerns/WritesJournalDraft.php` —
  `bcmul($line->debit, $data->exchangeRate, 4)` for base amounts; `bccomp(..., '0', 4)` for the
  one-sidedness check.
- `/Users/ali/projects/qayd-os/apps/api/app/Data/Accounting/JournalLineData.php` — `@param numeric-string`
  on every money parameter.

### Worked example — tax with repartition lines

One tax splits across several accounting lines by configured factors (reverse charge, partial
deductibility, withholding), and the split must reproduce to the fils on a filing that carries legal
liability:

```
 factors stored as INTEGER ppm      (e.g. 1_000_000 = 100%, 500_000 = 50%)   — never floats
 share_i  := bcdiv(bcmul($base, (string) $ppm_i, 8), '1000000', 4)           — wide, then rounded
 remainder distributed over repartition rows in `sequence` order             — stable key
 trace persisted alongside the posted amounts                                — auditable to the fils
 CONSTRAINT: Σ factors = ±1_000_000, as a DEFERRABLE constraint trigger      — not an app check
```

The ±100% invariant being a *deferred* constraint trigger rather than an application check is the detail
that matters: repartition rows are inserted as a set, so the invariant is only meaningful at `COMMIT`,
and an application-level check protects only the code paths that remember it.

Second example: allocating one payment across three installments — same helper, same stable ordering,
same trace, so "which fils went to installment 2?" is answerable a year later.

### Failure modes

- **A float entering through `json_decode`** of an AI or webhook payload. Decode money as string
  (`JSON_BIGINT_AS_STRING`-style handling, or a DTO that rejects non-string money outright).
- **`array_sum()`, `round()`, `number_format()` or `==` applied to money.** Each is a silent precision
  loss; `==` on numeric strings compares numerically in PHP and will surprise you at boundaries.
- **Omitting the scale argument** and inheriting whatever `bcscale()` was last set to.
- **Storing a percentage as a float** and multiplying money by it — the float re-enters through the
  factor.
- **Letting the database perform a rounding PHP did not.** The two then disagree by one fils, and the
  disagreement surfaces in a reconciliation months later.
- **An epsilon comparison "for safety".** It hides the defect it was added to survive.
- **A distribution with an unstable sort key** (hash order, `array_keys` of an associative array), so the
  same input produces different pennies on different runs.

### Testing strategy

1. A 10,000-line entry balances **exactly** — no tolerance, both currencies.
2. Values that are exact in decimal and inexact in binary (`0.1 + 0.2`, `1.005`) survive a full
   PHP → DB → PHP round trip unchanged.
3. **Property test on distribution:** for arbitrary totals and arbitrary weight vectors,
   `Σ shares == total` exactly, every share is within one unit of its ideal, and the trace accounts for
   every adjustment.
4. **Determinism test:** the same input distributed twice, in two processes, produces byte-identical
   output.
5. **DB/PHP agreement test:** a value computed at scale 8 and stored at 4 has the same result whether PHP
   or PostgreSQL performs the final rounding.
6. Assigning a `float` to a money DTO parameter fails static analysis (a PHPStan baseline test).
7. CI catalog check: every column named like money is `NUMERIC(19,4)`.

**Effort to apply** — **1** to follow in new code; **3** for the shared rounding + distribution helper
with its trace; **2** for the CI column check.

**Confidence** — **High.** Shipped, load-bearing, and it eliminates rather than mitigates an entire
defect class. The rounding-agreement rule and the distribution helper are the two pieces still to be
written down as code rather than discipline.

---

## P-15 — Read Model Pattern

**Intent** — Derive query-shaped tables from an append-only source, never treat them as truth, and ship
every one with a rebuilder and a drift detector.

**Use when** — the natural query is too expensive over the source: trial balance, period balances,
reconciliation groups, payment settlement state, statement health, dimensional P&L.

**Do not use when** — the source query is already fast. Speculative denormalisation is a permanent
liability for a temporary benchmark. Also not when the derived value would ever need to be **edited** —
if a human must be able to change it, it is not derived; model it as a fact with its own history.

### Structure

```
 ledger_entries                      APPEND-ONLY SOURCE OF TRUTH  (P-02)
        │
        │  AFTER INSERT trigger — MONOTONIC: it can only ever increment
        ▼
 account_period_balances (company_id, account_id, period_id,
                          opening, debit, credit, closing)
        CHECK (closing = opening + debit − credit)      ◄── internal identity, in the DB
        │
        ├── read path:  ~2,000-row index scan   instead of   a full scan of the largest table
        │
        └── RebuildPeriodBalancesAction
                recompute from source → compare → raise PeriodBalanceDriftException (500)
                run in CI AND on a schedule in production
```

**Prefer a VIEW when the query is affordable.** A view has zero writers and therefore zero drift, no
rebuilder, and no drift detector — it cannot be wrong. Two designed examples: `fiscal_periods.status` as a
view over the lock cursor (one source of truth, two representations), and payment settlement state as a
view over residuals (zero writers instead of three). A materialised table is the **fallback** when a view
is too slow, and it buys speed at the explicit price of a rebuilder and a drift check.

### Participants

`ledger_entries` (source); `account_period_balances` (specified); `reconciliation_groups` (specified);
`RebuildPeriodBalancesAction`, `RebuildReconciliationGroupsAction`, `VerifyNumberSequenceAction`
(specified); `PeriodBalanceDriftException` (`500` — a drift means integrity is broken, not that a request
was bad); `statement_health` (a view).

### Mechanics

1. **The incremental trigger is only safe because the source is append-only.** Rows only ever arrive, so
   the trigger only ever increments; it needs no `UPDATE`/`DELETE` handling and can never have to
   reconcile against a mutation. **On a mutable source the identical trigger is a bug generator** — and
   this is precisely the dependency between P-02 and this pattern: an incremental rollup is trustworthy
   *only* as a dividend of the append-only decision. A system whose ledger is its mutable invoice-line
   table cannot have this, which is why it scans instead.
2. **Every read model declares four things** in its migration header: its source, its rebuild Action, its
   drift check, and — always — that it is authoritative for nothing.
3. **Write paths read the source, never the read model.** A posting must never make a decision from
   `account_period_balances`. If a read model ever feeds a mutation, a drift stops being a reporting
   inconvenience and becomes a corruption vector.
4. **The rebuilder is a first-class deliverable, not a script.** It is an Action, it is tested, it is
   idempotent, it is safe to run online, and it runs in CI on a seeded dataset **and** on a schedule in
   production. A rebuilder that exists but is never executed is documentation.
5. **The drift check compares, it does not repair.** On mismatch it raises. Auto-repairing hides the
   cause, and the cause is always more important than the symptom — a drift means either the trigger, the
   source's append-only guarantee, or an assumption about the source is broken.
6. **Full-history replay is not a read path.** Replaying the whole history on every read does not scale;
   the mitigation of batching to avoid memory exhaustion is a symptom, not a fix. Carry the derived value
   forward on each event (P-02's `unit_cost_after`) or materialise the rollup here — but never make
   replay the primary path.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| The read model's internal arithmetic holds | `CHECK (closing = opening + debit − credit)` | DB CHECK |
| The read model equals a recomputation from source | `Rebuild…Action` comparison, CI + scheduled | CI ⚠️ |
| A rollback of a posting leaves no rollup residue | trigger runs inside the posting transaction | DB |
| No write path reads a read model | architecture test on Action dependencies | Arch test |
| The rebuilder is idempotent | test (run twice, assert no change) | Test |
| Read model rows are tenant-scoped | RLS (P-09) | DB policy |

### Reference implementation

**Specified.** `account_period_balances` is designed (8 points) and unbuilt.

The nearest shipped analogue is `ledger_entries` itself, which *is* a read model in the strict sense — it
is derived 1:1 from posted `journal_lines` and is reconstructible from them. That it is simultaneously
the source of truth for balances is not a contradiction: it is derived from the journal and
authoritative for the ledger, which is exactly why its own drift check (`COUNT(ledger) == COUNT(posted
lines)`, `SUM(signed) = 0`) belongs in the P-01 test list.

### Worked example — reconciliation groups

Matching groups (which invoices and payments form one settled cluster) are a classic derived structure.
The tempting implementation maintains them incrementally with raw SQL against the ledger; the documented
consequence is a staleness defect that survived for years.

QAYD's version: `reconciliation_links` is append-only (P-13), and `reconciliation_groups` is a
**rebuildable read model** with `RebuildReconciliationGroupsAction`. Unreconciling inserts a compensating
link and enqueues a rebuild. The staleness bug class is not fixed — it is **unreachable**, because the
groups are never the record of what happened.

Second example: `statement_health` as a **VIEW** —

```sql
CREATE VIEW statement_health AS
SELECT s.id, s.company_id,
       s.opening_balance + COALESCE(SUM(t.amount_txn), 0) AS closing_computed,
       s.closing_balance_declared,
       (s.opening_balance + COALESCE(SUM(t.amount_txn), 0)) = s.closing_balance_declared AS is_complete
  FROM bank_statements s
  LEFT JOIN bank_transactions t ON t.statement_id = s.id
 GROUP BY s.id;
```

Zero writers, zero drift, no rebuilder, no drift detector, always current. Reach for this shape first
and materialise only when a measured query is too slow.

### Failure modes

- **A write path reading the read model.** Converts a reporting drift into a corruption.
- **A rebuilder that exists but is never run.** Schedule it *and* run it in CI; otherwise the first time
  it runs is during an incident, on production data, untested.
- **Auto-repairing on drift.** Hides the cause; the next drift is larger and less explicable.
- **A "temporary" manual `UPDATE`** to fix a discrepancy instead of finding why it exists.
- **A read model over a mutable source with no full-recompute path.** Unfixable by construction.
- **Adding a `DELETE` handler to the trigger "just in case".** If you need one, the source is not
  append-only and this pattern does not apply — stop and fix the source.
- **Pre-aggregating speculatively.** Every read model is a permanent maintenance obligation; earn it with
  a measurement.
- **Rebuilding under a lock that blocks writes.** Rebuild must be online-safe or it will never be run.

### Testing strategy

1. Build 10,000 ledger rows; assert the rollup equals a full recomputation, account by account and period
   by period.
2. A rolled-back posting leaves no rollup residue.
3. The rebuild is idempotent — running it twice changes nothing.
4. Drift injected by direct SQL is **detected** and raises `PeriodBalanceDriftException`.
5. Trial balance computed from the rollup equals trial balance computed from the source, to the fils
   (P-14).
6. The rollup CHECK rejects a hand-crafted inconsistent row.
7. Architecture test: no Action that writes also reads a read-model table.
8. RLS isolation on the read model, like any tenant table.

**Effort to apply** — **8** for the first one (the drift harness is most of the cost); **3** for each
subsequent one once the harness exists; **1** for a view.

**Confidence** — **Medium-High.** The enabling property (an append-only source, making the trigger
monotonic) is proven and shipped, which is the part that is usually wrong elsewhere. The rollup itself,
its trigger and its drift harness are designed and unbuilt.

---

# Additional patterns the research justified

The four below are not in the original catalogue brief. Each is here because Phase 1 produced a specific,
dated reason for QAYD to have it — three of them are decisions whose cost rises with every week of delay.

---

## P-16 — Dimension Rows Pattern

**Intent** — Model an open-ended, percentage-allocated classification as **child rows with real foreign
keys** — never as fixed columns, and never as JSONB.

**Use when** — a fact must be classified along a set of axes that will grow (cost centre, project,
department, fund, grant, vessel, store, campaign) **and** an allocation may split across members of an
axis ("60% Project A, 40% Project B").

**Do not use when** — the classification is closed, exclusive and total. A cash-flow bucket is
`NOT NULL CHECK (bucket IN ('cash','operating','investing','financing'))` — a column, not this pattern,
and the column is what makes the statement's reconciliation identity structurally guaranteed. Also not
when there is exactly one axis that will provably never grow and never splits; then a column is honest.

### Structure

```
 dimensions (id, company_id, code, name)                    the AXIS      — Project, Cost Centre, Fund
      │
 dimension_members (id, dimension_id, code, name)           the VALUE
      UNIQUE (id, dimension_id)          ◄── exists solely to enable the composite FK below
      │
 journal_line_dimensions
      journal_line_id  BIGINT NOT NULL REFERENCES journal_lines(id)
      dimension_id     BIGINT NOT NULL
      member_id        BIGINT NOT NULL
      percent_ppm      INTEGER NOT NULL CHECK (percent_ppm BETWEEN 0 AND 1000000)
      amount_base      NUMERIC(19,4) NOT NULL
      FOREIGN KEY (member_id, dimension_id)
          REFERENCES dimension_members (id, dimension_id)
          ◄── "the member belongs to the declared axis" is a DATABASE guarantee, not a validation

      CONSTRAINT TRIGGER … DEFERRABLE INITIALLY DEFERRED:
          per (journal_line_id, dimension_id):  Σ percent_ppm = 1_000_000
                                                Σ amount_base = the line's base amount
```

### Participants

`dimensions`, `dimension_members`, `journal_line_dimensions`, `ledger_entry_dimensions` (the projection
copy); a deferred constraint trigger; `dimension_suggestions` (the AI proposal table, P-12);
`DimensionAllocationInvalidException` (specified).

### Mechanics

1. **Axes are orthogonal rows, so there is no cross-product.** Adding a fourth axis is one `INSERT` into
   `dimensions`, not a migration on the largest table in the system. Merging two partial allocations is
   set union, not a cross-product with a documented unsolvable edge case.
2. **The composite foreign key is the pattern's sharpest edge.** `(member_id, dimension_id)` referencing
   `(id, dimension_id)` makes "this member belongs to the axis it claims" impossible to violate — no
   trigger, no validation, no application check.
3. **Percentages are integer ppm**, never floats and never `NUMERIC` with implicit rounding (P-14).
4. **The invariant trigger must be `DEFERRABLE INITIALLY DEFERRED`.** An allocation is a *set* of rows;
   a non-deferred check makes a two-row 60/40 split impossible to insert, because the first row alone
   never sums to 100%.
5. **`SUM(amount_base) GROUP BY member_id` is a plain indexed aggregate.** This is the decisive
   comparison: with JSONB storage, money **cannot be aggregated by the distribution key** at all, so the
   subsystem's primary analytical query is not expressible against its primary storage.
6. **JSONB is legitimate as an API transport format and inside an AI proposal payload — never as ledger
   storage.**

**Why not fixed columns** (`cost_center_id`, `project_id`, `department_id`): a fourth dimension becomes a
migration and a deploy, which is a per-customer schema fork by another name; and a percentage split is
inexpressible without splitting the journal line, which corrupts the line ↔ source-document relationship.

**Why not JSONB distribution**: keys become delimited id strings with **no referential integrity** (so a
deleted member leaves danglers no constraint catches); no CHECK can express "sums to 100%"; and money
cannot be aggregated by the key. The decisive evidence is that the system that chose JSONB **materialises
it into rows anyway** and maintains two-way sync with bypass flags in six places — it pays the row cost
regardless, and the JSONB is an authoring convenience layered on top of the real storage.

**Timing.** This costs **zero to decide today** and roughly 13 points of rework plus a migration on
`journal_lines` and `ledger_entries` later. It is the most time-sensitive recommendation in the entire
research, which is why it is a **Decided** pattern with no code yet.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| A member belongs to the axis it is used under | composite FK `(member_id, dimension_id)` | DB FK |
| An allocation sums to exactly 100% per axis | `DEFERRABLE INITIALLY DEFERRED` constraint trigger | DB trigger |
| Allocated amounts sum to the line's base amount | same trigger (bcmath-exact `NUMERIC` comparison) | DB trigger |
| Percentages are integral and in range | `CHECK (percent_ppm BETWEEN 0 AND 1000000)` | DB CHECK |
| No orphan members | FK with `ON DELETE RESTRICT` | DB FK |
| Dimension rows are tenant-scoped | RLS (P-09) | DB policy |

### Reference implementation

**None — and that is the point.** This is a decision recorded before the code exists, so that the first
`cost_center_id` column is never added. The spec's previously-planned fixed FK columns are superseded by
this pattern.

### Worked example — budgeting by dimension

A budget line scoped to `(account, dimension_key)` consumes against `SUM(amount_base)` from
`journal_line_dimensions`. Because dimensions are rows, budget scope is a join rather than a column list,
and adding "Fund" as a budgetable axis requires no schema change in either subsystem.

Second example: a dimensional P&L — `SUM(signed_base_amount) … GROUP BY member_id` over
`ledger_entry_dimensions`, an ordinary indexed aggregate with an ordinary query plan.

### Failure modes

- **Adding the first fixed FK column "just for cost centre."** This is the exact decision the pattern
  exists to prevent, and it is always locally reasonable.
- **A non-deferred constraint**, making multi-row allocations uninsertable.
- **Percentages as `NUMERIC`/float**, reintroducing rounding drift into a classification.
- **Using JSONB "temporarily"** for the authoring API and letting it become storage.
- **Omitting the composite FK** and validating axis membership in PHP — the one check the database can
  make free and total.
- **Allowing a member to be deleted while referenced.**

### Testing strategy

1. The composite FK rejects a member from a different axis.
2. A 60/40 allocation commits; a 60/30 allocation fails **at `COMMIT`**, proving deferral.
3. Allocated amounts that do not sum to the line's base amount are rejected.
4. `SUM … GROUP BY member_id` uses an index (assert the plan).
5. Adding a new dimension requires **no DDL** — assert via a test that inserts one and allocates against
   it.
6. Deleting a referenced member is rejected.

**Effort to apply** — **0 to decide** (do it now); **21** to build the subsystem.

**Confidence** — **High** on the decision; the evidence against both alternatives is empirical rather
than aesthetic. **Medium** on the exact trigger implementation until it is written.

---

## P-17 — Explicit Resolution Pattern

**Intent** — When the system knows a better value than the caller supplied, never substitute it; return a
typed **resolution** the caller must explicitly accept.

**Use when** — a request is *nearly* valid and an obvious correction exists: a posting date in a locked
period, a missing exchange rate with a nearby published rate, an unmapped account with a likely match, an
import whose residual could be plugged.

**Do not use when** — the correction has no financial consequence (normalising a currency code to
uppercase, trimming whitespace); or when there is exactly one legal value and the caller supplied nothing
optional (a default `exchange_rate` of 1 for a base-currency entry is a definition, not a correction).

### Structure

```
 request: post entry dated 2026-01-15        ← period is locked
        ▼
 RAISE  (never adjust)
        ▼
 HTTP 409
 {
   "success": false,
   "errors": [{
      "code": "POSTING_DATE_LOCKED",
      "field": "journal_date",
      "meta": {
        "resolution": {
           "requested":  "2026-01-15",
           "suggested":  "2026-02-01",
           "reason":     "PERIOD_LOCKED",
           "violations": [ … ]
        }
      }
   }]
 }
        ▼
 caller re-submits with accepted_resolution = "2026-02-01"
        ▼
 an explicit, attributed, AUDITED decision by a human
```

### Participants

`PostingDateResolution`, `RateResolution`, `AccountMappingResolution` (`final readonly` DTOs, specified);
`ClosedPeriodException` (**shipped** — it raises rather than shifting); `RateMissingForDateException`
(`RATE_MISSING_FOR_DATE`, specified); `SuggestPostingDateAction` (specified); `audit_logs` (P-10 — the
acceptance is an audited event).

### Mechanics

1. **The resolution is data, not a log line.** It is a typed DTO in the error envelope's `meta`, so a
   client can render it and an agent can echo it back.
2. **Acceptance is a new request, never a server-side retry.** The server never applies its own
   suggestion; the caller does, explicitly, and that acceptance is attributed and audited.
3. **For an AI drafter the suggestion may be pre-applied — and the draft is flagged for human review.**
   The human is always the acceptor. This is the same boundary as P-12 expressed in a different shape.
4. **Why it earns its place.** Silent coercion is the failure mode with the worst blast radius in an
   accounting system, because it produces a **plausible wrong answer** rather than an error. Two concrete
   cases from the research: a rate lookup that falls back to the earliest known rate and then to `1.0`
   converts a foreign-currency transaction **at par**, with no error, no log and no flag — the
   highest-severity defect found anywhere in the compared system's currency handling. And a posting date
   silently bumped to `lock_date + 1 day` changes which period a financial event belongs to, and thus a
   figure that has been filed. Each is individually small and each is effectively unfindable months
   later.
5. **The negative space is the enforcement.** The rule is "no code path substitutes a financial value the
   caller did not supply", and it is checked by looking for what is *absent*: no fallback default on rate
   resolution, no date arithmetic in the posting path, no "or use the first matching account".

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| No silent substitution of a financial value | architecture test grepping for fallback defaults on rate/date/account resolution | Arch test |
| A near-miss raises rather than adjusts | typed exceptions; tests assert the value was *not* changed | Test |
| Every resolution is machine-readable and echoable | DTO in `meta` + envelope test | Test |
| Acceptance is attributed | `audit_logs` row naming the actor and the accepted value | DB (append-only) |
| A stale resolution cannot be accepted | freshness token (P-06) | DB predicate |

### Reference implementation

**Partial — enforced as negative space.** `ClosedPeriodException` raises rather than shifting the date,
and no rate-defaulting code path exists anywhere in `apps/api`. The resolution DTO and the acceptance
round trip are specified.

### Worked example — `RATE_MISSING_FOR_DATE`

A journal dated 2026-03-14 in USD, with no published rate for that date:

```
 RAISE RateMissingForDateException
   meta.resolution = {
     requested_date: "2026-03-14",
     currency: "USD",
     nearest_published: { date: "2026-03-13", rate: "0.3078", source: "CBK", rate_type: "spot" },
     note: "no extrapolation was performed"
   }
```

The caller either accepts the nearest published rate (an explicit accounting decision, audited) or
publishes the missing rate. What the system never does is convert at par and let the entry look correct.

Second example: an opening-balance import whose lines do not net to zero. The residual is **surfaced**
with a suggested suspense account that the importer must explicitly acknowledge — never absorbed by an
auto-inserted plug line. An unbalanced import is a data-quality signal, and absorbing it destroys the
signal precisely when it is most useful.

### Failure modes

- **Putting the suggestion in a log line** instead of the response.
- **Auto-accepting above a confidence threshold** (P-12's failure mode in a different costume).
- **A free-text hint** the client cannot echo back, forcing the human to re-type a value.
- **Applying the resolution server-side "because the caller obviously wants it"** — the whole pattern is
  that nobody may assume this on a financial value.
- **A resolution with no freshness bound**, accepted an hour later against a changed world.

### Testing strategy

1. For every coercion candidate (rate, date, account mapping, residual), a test asserts a **raise** and
   asserts the input value was not modified.
2. The resolution round-trips: the value returned in `meta` is accepted verbatim on re-submission.
3. Accepting a stale resolution is rejected with a diagnosing `409`.
4. The acceptance writes an audit row naming the actor and the accepted value.
5. Architecture test: no `??`/`?:` fallback on a rate, date or account lookup in a financial path.

**Effort to apply** — **3** (DTO + envelope plumbing + the arch test + per-case tests).

**Confidence** — **High.** This is `01`/P14 given a concrete shape, and the refusal half already ships.

---

## P-18 — Lifecycle Transition Map Pattern

**Intent** — Express every legal state transition **once**, as typed data in one file, and mirror it with
a database trigger so an illegal transition is impossible rather than merely un-coded.

**Use when** — an entity has more than three states, or more than one Action that changes its state.

**Do not use when** — two states and one transition. A boolean with a CHECK is honest and cheaper.

### Structure

```
 app/Domain/Accounting/JournalEntryLifecycle.php     ← ONE source of truth, typed, ~80 lines
   TRANSITIONS = [
     draft            => [pending_approval, posted, voided],
     pending_approval => [approved, rejected, draft],
     approved         => [posted, draft],
     rejected         => [draft],
     posted           => [reversed, archived],       ◄── NO path back to draft
     reversed         => [archived],
     voided           => [],                          terminal
     archived         => [],                          terminal
   ]
   assert(from, to)          used by EVERY Action, under the row lock
   reachableFrom(state)      drives GET /journal-entries/{id}/transitions  → UI button state
        │
        │  mirrored by
        ▼
 BEFORE UPDATE trigger on journal_entries
        posted → draft  is a DATABASE ERROR, not a code convention
 +  CREATE UNIQUE INDEX je_number_posted_uq ON journal_entries (company_id, journal_number)
       WHERE status IN ('posted','reversed','archived')     ◄── numbering survives an allocator bug
```

### Participants

`JournalEntryLifecycle` (specified); a backed `JournalEntryStatus` enum mirroring the PostgreSQL enum
(specified); `IllegalTransitionException` (specified); the status-transition trigger migration
(specified); today's `JournalEntry::EDITABLE_STATUSES` and
`JournalEntryPostingService::POSTABLE_STATUSES` (**shipped** — the pattern's pre-state).

### Mechanics

1. **Every Action asserts against the map, under the row lock.** In the posting service, the current
   `in_array($status, self::POSTABLE_STATUSES, true)` becomes
   `JournalEntryLifecycle::assert(JournalEntryStatus::from($status), JournalEntryStatus::POSTED)` — one
   line, and legality stops being duplicated per Action.
2. **The trigger mirrors the map.** PHP gives good errors and API affordances; the trigger gives the
   guarantee, including against data-fix scripts and future endpoints.
3. **The map is not in a model observer.** `01`/P10: models are thin. The map is domain data; the
   assertion happens in Actions.
4. **The map pays for itself three ways beyond correctness:** a `GET .../transitions` endpoint that drives
   button enablement in the Next.js UI (so the frontend never re-derives the rules); a generated state
   diagram for documentation; and a Pest test asserting that every Action's target state is legal from its
   source.
5. **Urgency.** `journal_entry_status` already has **eight** values and the only expression of legality is
   two constants plus rules implicit in each Action. Every Action written from here encodes transition
   rules implicitly, so the cost is 3 points today and grows linearly. This is the cheapest item in the
   research with the shortest window — and the reference system's version of this mistake ended with its
   transition rules scattered across at least six call sites, with no single place answering "what
   transitions are legal?".
6. **It structurally forbids un-posting**, which P-13 has already decided against — the map simply has no
   such edge, and the trigger makes that a database fact.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| A transition not in the map cannot occur | `BEFORE UPDATE` status trigger | DB trigger |
| Every Action's transition is in the map | Pest test over all Actions | Test |
| Terminal states are terminal | map + trigger | DB trigger |
| The PHP map and the trigger agree | a test that reads both and diffs them | Test |
| `posted → draft` is impossible | absent edge + trigger | DB trigger |
| A posted number is unique even if the allocator misbehaves | partial unique index on posted-ish statuses | DB index |

### Reference implementation

**Partial — the pre-state.** `JournalEntry::EDITABLE_STATUSES` and
`JournalEntryPostingService::POSTABLE_STATUSES` are the two constants that express fragments of the map
today. Cited honestly: this is the condition the pattern exists to fix, not an example of it.

### Worked example — `period_close_runs`

`started → checks_passed → awaiting_approval → closed | aborted`, with the same map-plus-trigger shape,
and a shape CHECK making `closed` unreachable without approver, timestamp and snapshot hash (P-04).

Second example: `match_proposals` — `pending → accepted | rejected | expired`, all terminal. Three lines
of map, one trigger, and the "can a rejected proposal be re-accepted?" question is answered by the schema
rather than by whoever writes the next endpoint.

### Failure modes

- **The map in PHP only.** A console command or a data-fix script defeats it.
- **The map in the trigger only.** No UI affordance, no API contract, no test, and a raw SQL error message
  as the user experience.
- **Guards in a model observer.** Violates thin models and hides the rule from the Action that owns it.
- **Duplicating the map per Action** — the exact condition being fixed.
- **A map that drifts from the trigger.** Test that they agree; do not rely on both being edited.
- **Adding a state without adding its edges**, producing an unreachable or inescapable status.

### Testing strategy

1. **Table-driven over the full cross-product** of states: exactly the map's edges succeed; every other
   pair fails.
2. Raw SQL attempting `posted → draft` fails at the database.
3. `GET .../transitions` returns exactly `reachableFrom()`.
4. Every Action's `(from, to)` pair is a legal edge.
5. The PHP map and the trigger definition agree (parse both; assert equal edge sets).
6. The partial unique index rejects a duplicate posted number.

**Effort to apply** — **3**.

**Confidence** — **High.** Small, mechanical, and the failure it prevents is well documented.

---

## P-19 — Declared Configuration Pattern

**Intent** — Express open-ended business configuration — report definitions, matching rules, tax
repartition, automation conditions — as **typed, constrained rows compiled through an allowlist**, never
as strings evaluated at runtime and never as code.

**Use when** — a customer, a jurisdiction, or an AI must add a variant without a release: GCC VAT
regimes, financial-statement lines, reconciliation matching rules, dimension distribution rules.

**Do not use when** — two variants exist and neither is customer-visible (write two classes and a seam,
P-03); or — the important case — **before the concrete cases exist.**

### The sequencing rule

This is the pattern's most important clause and it is stated before the structure deliberately:

```
   Build Trial Balance.          (a concrete statement)
   Build P&L.                    (a second concrete statement)
   THEN extract the engine.      (from two working implementations)

   Building the engine first is precisely how you get a regex formula grammar with sign
   conventions encoded in punctuation, money as float, and no cycle detection — an
   abstraction designed against imagined requirements rather than real ones.
```

### Structure

```
 report_definitions ─► report_lines ─► report_expressions ─► report_expression_operands
                                                              ▲
                                              TYPED ROWS, not formula strings
                                              signs are COLUMNS, not punctuation
                                              money stays NUMERIC, factors are integer ppm
        │
        │  selector: JSONB with a CHECK-constrained shape
        ▼
 SelectorCompiler ── allowlist of fields + operators ──► parameterized SQL with BOUND params
        ✗ never eval        ✗ never string interpolation      ✗ never user-supplied SQL
        │
        └─ at PUBLISH time: recursive CTE cycle detection over the operand graph
```

### Participants

`report_definitions` / `report_lines` / `report_expressions` / `report_expression_operands`;
`reconciliation_rules`; tax repartition rows; `SelectorCompiler` (the single reviewed compiler);
`CyclicReportDefinitionException`, `UnsupportedSelectorException` (specified).

### Mechanics

1. **One reviewed compiler secures several subsystems.** The same CHECK-constrained selector serves
   report expressions, reconciliation matching rules and dimension distribution rules — so the security
   review happens once and the allowlist is one artifact.
2. **This is what makes "the AI proposes, a human approves" implementable.** An LLM can emit a declared
   predicate; a human can *read and approve it*; the backend compiles it through an allowlist to bound
   parameters. The alternative — an AI emitting SQL or invoking mutating methods — is unreviewable and
   unsafe by construction (P-12).
3. **Typed operand rows, not formula strings.** A string formula must be parsed, and a parser accumulates
   a grammar; typed rows are queryable, diffable, joinable and constrainable.
4. **Cycle detection at publish, by recursive CTE.** A syntax check is not a cycle check, and a cyclic
   statement definition is discovered at report time, in front of a customer, otherwise.
5. **Invariants become deferred constraint triggers.** Tax repartition's ±100% rule is a
   `DEFERRABLE INITIALLY DEFERRED` trigger, not an application check — the same reasoning as P-16.
6. **Configuration-as-data stops at the security boundary.** RLS policies are DDL in reviewed migrations
   and permission scopes are typed PHP. Storing a security predicate as evaluated text means anyone who
   can write a configuration row controls the security predicate of every model. If per-tenant rules ever
   become necessary, express them as **data consumed by a fixed policy**, never as generated DDL and never
   as evaluated code.
7. **No runtime DDL, ever.** Creating configuration must never execute `ALTER TABLE` or `CREATE INDEX`.
   That path makes every tenant's schema different, no migration portable, zero-downtime deploys
   unreasonable, and a schema review unable to enumerate the columns. It is disqualifying on its own for a
   migration-driven system.

### Invariants it guarantees

| Invariant | Enforced where | Strength |
|---|---|---|
| No dynamic code evaluation exists | architecture test grepping for `eval`/`create_function`/variable-variable dispatch | Arch test |
| Every selector field and operator is on the allowlist | compiler + golden-file tests | Test |
| A published definition contains no cycles | recursive CTE check at publish | DB query |
| Compiled SQL uses bound parameters only | compiler contract + test asserting no interpolation | Test |
| Repartition factors sum to ±100% | `DEFERRABLE` constraint trigger | DB trigger |
| Configuration never triggers DDL | architecture test on migration-only DDL | Arch test |
| Security predicates are never data | policies exist only in migrations; arch test | Arch test |

### Reference implementation

**None — deliberately.** The sequencing rule forbids building it until Trial Balance and one further
statement exist. Recording the pattern now is what prevents the engine being built first.

### Worked example — GCC VAT repartition

One tax splitting across several accounting lines with per-line factors, target accounts and reporting
tags, defined separately for invoices and refunds. Reverse charge, partial deductibility and withholding
become **data entry rather than a release** — which is the difference between supporting one VAT regime
and supporting the several that the GCC actually has. Factors are integer ppm (P-14); the ±100% rule is a
deferred trigger; the base ↔ tax link is persisted at post time so the VAT return is a `GROUP BY` on an
indexed table rather than a large reconstruction query with an "approximate" fallback branch. For a filing
that carries legal liability, "approximate" is not a word that should appear in an implementation.

Second example: reconciliation matching rules — conditions as data (amount, partner, label, regex),
evaluated deterministically before any AI is consulted (P-12), with regexes precompiled and time-boxed.

### Failure modes

- **Formula strings.** They become a grammar, the grammar becomes a parser, the parser becomes
  unreviewable.
- **`eval` in any form.** Non-negotiable.
- **Building the engine before two concrete cases exist.**
- **Storing security predicates as data.** Anyone who can write configuration then controls
  authorization.
- **Runtime DDL driven by user data.** Disqualifying.
- **Signs encoded in punctuation** (`-sum(...)`, `-Tag`, `D`/`C`), which no constraint can validate.
- **Float money inside the configuration layer**, reintroducing P-14's problem through the side door.
- **An allowlist that grows by request** without review, until it is not an allowlist.

### Testing strategy

1. Golden-file compile tests: selector → exact SQL + exact bound parameter list.
2. A selector using an unlisted field or operator is rejected with a coded error.
3. A cyclic definition is rejected **at publish**, not at evaluation.
4. Compiled queries use indexes (assert the plan on a realistic dataset).
5. Architecture test: no dynamic evaluation anywhere in the codebase.
6. Repartition rows that do not sum to ±100% fail at `COMMIT`.
7. Fuzz the selector input; assert the compiler never emits interpolated text.

**Effort to apply** — **21** for the compiler and the report model, **after** two concrete statements
exist.

**Confidence** — **Medium.** High on the shape and on every rejection listed above; medium on QAYD's
selector grammar specifically, because — by this pattern's own sequencing rule — it must not be designed
before its two concrete consumers exist.

---

# Applying the catalogue

## Pattern interaction map

Patterns are not independent. These dependencies are the ones that bite if ignored.

```
                      P-09 RLS ─────────────────────► every table, no exceptions
                      P-14 Money ───────────────────► every amount, no exceptions
                      P-10 Audit ───────────────────► every mutation, no exceptions

  P-02 Ledger Projection (APPEND-ONLY)
        │  is what MAKES SAFE ──────────────────────► P-15 Read Model (monotonic trigger)
        │                       ──────────────────────► P-10 Audit (chain can never go stale)
        │                       ──────────────────────► P-13 Correction (nothing to mutate)
        ▼
  P-01 Posting ── the single write path
        ├── needs ─► P-07 Number Allocation ─► which is the ONLY genuine lock in P-08
        ├── needs ─► P-03 Seam (calendar) ──► removing P-08's misapplied lock rides on this
        └── emits ─► P-11 Event

  P-18 Lifecycle Map  ── is the substrate for ─► P-04 Approval
  P-04 Approval       ── shares its guard with ─► P-06 Concurrency
  P-12 AI Action      ── depends on ───────────► P-04 (a human accepts) + P-17 (never coerce an agent)
  P-05 Validation     ── is what makes ───────► P-12 economically viable (one round trip, not N)
  P-16 Dimension Rows ── must be decided BEFORE any of the above touches journal_lines again
```

**The one ordering that is not negotiable:** P-02's append-only guarantee is load-bearing for four other
patterns. Anything that would make `ledger_entries` mutable — most temptingly, storing reconciliation
state on the ledger row — silently invalidates P-15's monotonic trigger, P-10's chain, and P-13 entirely.

## Adoption checklist for a new subsystem

Copy this into the design document of any new financial module and answer every line.

```
[ ] P-09  Every table: company_id NOT NULL, FORCE RLS, restrictive boundary, per-verb policies.
[ ] P-14  Every amount: NUMERIC(19,4) / numeric-string. No float anywhere. Scale declared at every call.
[ ] P-10  Append-only audit for every mutation. Is any tier legally load-bearing? → chain it.
[ ] P-18  How many states? >3 or >1 mutating Action → transition map + mirroring trigger.
[ ] P-04  Which operations are irreversible? → approval with a DB four-eyes CHECK and a snapshot.
[ ] P-06  Which rows are edited over human time? → version column + guarded UPDATE + diagnosis.
[ ] P-13  Which records are relied upon once created? → correction by reversal; RESTRICT, never CASCADE.
[ ] P-01  Does it touch the ledger? → through PostJournalEntryAction. No second path. Arch test it.
[ ] P-05  Bulk or machine-authored input? → aggregate every violation into one structured 422.
[ ] P-12  Does an AI produce data here? → proposal table + GRANTs + confidence + model_version + rationale.
[ ] P-17  Any place the system "knows better"? → raise with a resolution. Never substitute.
[ ] P-11  What must other modules learn? → after-commit event via the outbox. Never a cross-module write.
[ ] P-15  Any query too slow over the source? → prefer a VIEW; else rollup + rebuilder + drift detector.
[ ] P-08  List every lock. For each: which invariant? can the row change? can a constraint say it instead?
[ ] P-03  Any seam? Name the second implementation. If you cannot, do not add it.
[ ] P-16  Any classification axis? Rows with a composite FK — never new columns, never JSONB.
[ ] P-19  Any customer- or jurisdiction-variable behaviour? Do two concrete cases exist yet?
```

## Open work register

The patterns above name honest gaps. Collected here so they are trackable rather than buried.

| Pattern | Gap | Effort | Why it matters |
|---|---|---|---|
| P-16 | **Decide dimension storage before anything else touches `journal_lines`** | 0 to decide | Costs nothing today; a migration on the largest table later |
| P-18 | Lifecycle map + mirroring trigger | 3 | Cost grows with every Action written against the implicit rules |
| P-08 | Remove the fiscal-year `FOR UPDATE`; ship with concurrency tests | 3–5 | Removes a company-year serialization point from the hottest write path |
| P-09 | CI catalog-introspection RLS check | 5 | Converts QAYD's tenancy convention into a mechanism |
| P-09 | Pooler-safe `SET LOCAL` audit, verified against a real pooler | 8 | The specific failure mode that produces a silent cross-tenant breach |
| P-15 | `account_period_balances` + rebuilder + drift detector | 8 | The largest single scalability win available |
| P-05 | Violation aggregation (the envelope already supports it) | 3 | One AI round trip instead of N |
| P-12 | `qayd_ai` role + GRANT matrix + first proposal table | 8 | Moves the AI boundary from a trigger to a privilege |
| P-13 | Reversal Action, cycle trigger, partial unique index | 8 | S2-06 |
| P-11 | Transactional outbox + relay | 5 | An event cannot be lost if the broker is down |
| P-10 | Activate the hash chain (trigger, canonical payload, anchors) | 21 | Regulatory; cheap only because the ledger is append-only |
| P-14 | Shared rounding helper + penny distribution with trace | 3 | Makes the DB/PHP agreement rule code rather than discipline |

**If only five things are actioned**, take them in this order: P-16 (decide), P-18, P-08, P-09's CI check,
P-15's rollup. That is nineteen points of build plus one decision that costs nothing to make today — and
P-09's pooler audit must be verified **before** connection pooling is enabled, not after.

---

*This document describes original QAYD designs. Where Phase 1 research informed a pattern, it did so by
supplying evidence about consequences — not source. No third-party code is reproduced; the citations that
let each empirical claim be verified live in `docs/research/odoo/ODOO_LEARNING.md`.*
