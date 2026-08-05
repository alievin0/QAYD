# 04 — Rejected Patterns

**What QAYD will never implement, and the mechanism by which each one causes harm.**
Version 1.0 · 2026-07-28 · Status: **binding**

---

## What this document is

`01_ENGINEERING_PRINCIPLES.md` says what we do. This file says what we do **not** do, and — more
importantly — *why the harm happens*, in enough mechanical detail that an engineer can recognise a
**new** instance of the same mistake in code that looks nothing like the example.

That is the whole discipline of this document. A rejection written as *"Odoo does this and Odoo is
bad"* is worthless: it only protects us from Odoo. A rejection written as *"storing a derived value
on the row that produces it creates a second writer, and a second writer means the row can no longer
be append-only, and once it is not append-only every downstream guarantee that depended on
append-only silently becomes a convention"* protects us from every future design that has that shape,
including ones nobody has invented yet.

**So: every entry below states a mechanism, not a verdict.** If you find yourself reading an entry and
thinking "but our version is different", check the mechanism, not the example. If the mechanism
applies, the rejection applies.

### Where the material comes from

Phase 1 of the Odoo study (`docs/research/odoo/`) produced a 26-item rejected list backed by ~14,000
lines of file-and-line evidence against Odoo 19.0 @ `f3e407c6`. This document takes those 26, restates
each as a transferable mechanism, adds eight more that the research implied but did not name (four of
them AI-specific), and attaches enforcement to every one.

**Odoo is the worked example, not the defendant.** Odoo is a twenty-year-old, commercially successful
ERP that got most of its *conceptual* models right; nearly every rejection below is a case where a
sound concept was given an unsound physical implementation, usually for a defensible reason at the
time. That is exactly what makes it good teaching material: these are not mistakes made by careless
people. They are mistakes made by competent people under delivery pressure, which is the only kind
this document can plausibly protect us from.

**No Odoo code is reproduced here.** Citations locate claims for independent verification.

### Precedence

```
MANIFEST.md                          vision, laws, decision priority
   └── 01_ENGINEERING_PRINCIPLES.md  how we build, and why
         ├── 03_DESIGN_PATTERNS.md   the patterns we reach for   (P-01 … P-19)
         └── 04_REJECTED_PATTERNS.md ← you are here              (R-01 … R-34)
```

`04` may not contradict `01`. Where a rejection here corresponds to a principle there, the principle
is cited (`P1`…`P21`). Where a rejection has a positive replacement, the pattern is cited
(`P-01`…`P-19` in `03_DESIGN_PATTERNS.md`). If you find a case where this file and `01` disagree,
that is a bug in one of them — resolve it under `MANIFEST.md` Law 1, do not pick the one you prefer.

---

## Severity

| Severity | Definition | Response |
|---|---|---|
| **Catastrophic** | Produces wrong numbers in a customer's books, or crosses a tenant boundary, and can do so **silently**. Discovered late, by an auditor, after something has been filed or paid. Not recoverable by a hotfix, because the customer has already signed something. | Block the merge. Revert if shipped. Incident review. |
| **Serious** | Does not corrupt data today, but **removes a guarantee** — so the next ordinary bug in that area becomes Catastrophic instead of contained. Cost compounds with every code path added afterwards. | Block the merge. Fix before the subsystem grows. |
| **Untidy** | Costs clarity, review time, or performance. Nothing becomes wrong; things become slow, confusing, or expensive to change. | Fix it when you next touch that file. Do not block a release on it. |

Two properties matter more than the label:

- **Silence.** A pattern that fails loudly is one bug. A pattern that fails silently is an unknown
  number of bugs, discovered in an unknown order, starting from the oldest. Almost everything ranked
  Catastrophic below is ranked there because of silence, not because of blast radius.
- **Compounding.** A pattern whose cost is fixed can be paid later. A pattern whose cost grows with
  every subsequent code path must be stopped now. Several Serious entries are Serious purely because
  they compound — `R-01`, `R-05`, and `R-28` are cheap to prevent today and unaffordable to reverse
  in a year.

---

## Index — ranked by severity

| # | Pattern | Severity | Primary enforcement | Enforced today? |
|---|---|---|---|---|
| **R-03** | Soft financial validation (tolerances, "close enough") | Catastrophic | `CHECK` + zero-tolerance re-derivation | ✅ |
| **R-04** | Mutable posted entries; un-posting by state flip | Catastrophic | posted-state trigger + lifecycle trigger | ✅ / ⚠️ partial |
| **R-05** | Application-only integrity | Catastrophic | DDL in migrations; constraint-parity review | ⚠️ convention |
| **R-06** | Context-gated invariants (off switches) | Catastrophic | absence of bypass params + arch test | ⚠️ arch test missing |
| **R-07** | Ambient privilege bypass (`sudo()`-style) | Catastrophic | `NOBYPASSRLS` role + grep arch test | ✅ / ⚠️ test missing |
| **R-08** | Nullable `company_id` on a tenant table | Catastrophic | `NOT NULL` + catalog CI check | ⚠️ CI check missing |
| **R-09** | Float money and epsilon compensation | Catastrophic | `NUMERIC(19,4)` + static analysis | ✅ / ⚠️ analysis partial |
| **R-11** | Silent coercion of user data | Catastrophic | no coercion paths; resolution DTOs | ⚠️ convention |
| **R-12** | Auto-balancing suspense lines | Catastrophic | balance `CHECK`; no plug code path | ✅ |
| **R-13** | A second writer into the ledger | Catastrophic | table `GRANT`s + arch test | ⚠️ grants pending |
| **R-14** | Raw SQL writes bypassing the domain layer | Catastrophic | append-only trigger + grants + arch test | ✅ / ⚠️ partial |
| **R-15** | Derived state stored on the ledger row | Catastrophic | schema review; side tables by design | ⚠️ design-time only |
| **R-19** | Security logic stored as evaluated code | Catastrophic | policies are DDL; no `eval` anywhere | ⚠️ arch test missing |
| **R-24** | Amount equality used as identity | Catastrophic | explicit FK on every allocation | ⚠️ design-time only |
| **R-26** | `ON DELETE CASCADE` across financial history | Catastrophic | `RESTRICT` + catalog CI check | ⚠️ CI check missing |
| **R-30** | Silent degradation (warn-and-continue) | Catastrophic | throw-by-default; log-and-continue banned | ⚠️ convention |
| **R-31** | AI writing directly to domain tables | Catastrophic | separate DB role without `INSERT` | ⚠️ role pending |
| **R-32** | Trusting model output without a confirmation boundary | Catastrophic | `entry_source` trigger; proposal tables | ⚠️ partial |
| **R-33** | Prompts / policies / rules stored as executable code | Catastrophic | typed rows compiled by allowlist | ⚠️ convention |
| **R-01** | Fat models / business logic in models | Serious | arch test on model class surface | ⚠️ test missing |
| **R-02** | Business logic in controllers | Serious | arch test on controller dependencies | ⚠️ test missing |
| **R-10** | PHP-side aggregation of money | Serious | review rule + query test | ⚠️ convention |
| **R-16** | JSONB dimensions; FKs as delimited strings | Serious | rows + composite FK by design | ✅ decided |
| **R-17** | Two-way sync between two stores of one truth | Serious | one writer; rebuildable read model | ⚠️ convention |
| **R-18** | Runtime DDL driven by user data | Serious | no `Schema::` outside migrations (arch test) | ⚠️ test missing |
| **R-20** | Magic side effects (observers, computed cascades) | Serious | no model events; arch test | ⚠️ test missing |
| **R-21** | Numbering allocated as an ORM side effect | Serious | explicit Action in `PostingService` | ✅ |
| **R-22** | Presentation rows inside financial tables | Serious | `account_id NOT NULL` + separate table | ✅ |
| **R-25** | Multi-valued optional tags for exclusive classes | Serious | `NOT NULL` + `CHECK (IN …)` column | ⚠️ design-time only |
| **R-28** | Cross-module coupling by inheritance/override | Serious | events-only integration + arch test | ⚠️ test missing |
| **R-34** | An LLM where a deterministic rule suffices | Serious | deterministic-first tiering; review rule | ⚠️ convention |
| **R-23** | Hierarchy derived from string parsing | Untidy | real `parent_id`; review rule | ✅ schema |
| **R-27** | Building a generic workflow engine | Untidy | explicit statuses + `P-18` lifecycle map | ✅ decided |
| **R-29** | Full-history replay as a primary read path | Untidy | `P-15` read model + drift detector | ⚠️ specified |

**Read the last column honestly.** Nineteen of thirty-four rejections are currently held by convention,
design intent, or a test that has not been written. `01` is explicit that a rule without a mechanism is
not a rule — so the ⚠️ rows are, today, aspirations. Each entry names its missing mechanism in
*How the rejection is enforced*; those are the highest-leverage engineering tasks in this document, and
they are collected at the end under **Enforcement debt**.

---

## Code smell → which rejection you are about to violate

Use this table in review. The left column is what you actually see; the right column is what it
becomes.

| What you see or hear | You are about to violate |
|---|---|
| `abs($a - $b) < 0.01`, `->round(2) == `, "within a fils", `EPSILON`, `TOLERANCE` | **R-03** |
| `(float)`, `floatval()`, `+`/`-`/`*` between money variables, `array_sum($amounts)` | **R-09**, **R-10** |
| `round()`, `number_format()` anywhere upstream of a stored amount | **R-09**, **R-03** |
| `->update()`, `->save()`, `->delete()` on anything posted; `status = 'draft'` on a posted row | **R-04** |
| "we'll un-post it, fix it, and re-post it" | **R-04** |
| Validation that exists only in a FormRequest, an Action, or a `booted()` hook | **R-05** |
| A parameter named `$force`, `$skip*`, `$bypass*`, `$validate = true`, `$strict = false` | **R-06** |
| A config key, feature flag, or context array that turns a check off | **R-06** |
| `withoutGlobalScopes()`, `Model::unguarded()`, `unguard()`, a service on the admin DB connection | **R-07** |
| "just this once, as the system user" / "the job runs as root anyway" | **R-07**, **R-31** |
| `->nullable()` on a `company_id`; a tenant table without RLS in its migration | **R-08** |
| `DB::statement`, `DB::update`, `DB::raw` naming `ledger_entries`, `journal_*`, or any posted table | **R-14** |
| A second class that inserts into `ledger_entries`; "the import path is faster if it writes directly" | **R-13** |
| Adjusting a user's date, amount, currency, or account and continuing | **R-11** |
| Inserting a balancing / rounding / plug line so the entry balances | **R-12** |
| `amount_residual`, `reconciled`, `matched`, `status` added to `ledger_entries` | **R-15** |
| A `jsonb` column holding ids; `implode(',', $ids)` as a key; `"14,16" => 60` | **R-16** |
| Two tables kept aligned by an observer, a job, or a `sync*()` method | **R-17** |
| `Schema::`, `ALTER TABLE`, or `CREATE INDEX` outside `database/migrations/` | **R-18** |
| `eval()`, `Blade::render($userString)`, a column holding PHP/SQL, `unserialize()` on stored input | **R-19**, **R-33** |
| A prompt template that concatenates retrieved text into an instruction | **R-33** |
| An Eloquent observer, `booted()`, `saving()`, or accessor that writes another table | **R-20** |
| A number, code, or reference assigned inside a model event | **R-21** |
| `is_section`, `is_header`, `display_type`, `is_note` on `journal_lines` | **R-22** |
| `substr($code, 0, 2)`, `LIKE 'code%'`, or a regex to find a parent account | **R-23** |
| `where('amount', $x)` to decide *which* payment/invoice/line this is | **R-24** |
| A pivot/tag table for something that is exactly one-of-N | **R-25** |
| `->onDelete('cascade')` on a FK into financial history | **R-26** |
| A `workflows` / `steps` / `transitions` table with generic `condition` + `action` columns | **R-27** |
| Module A importing module B's Eloquent model, or extending B's Action to change behaviour | **R-28** |
| Recomputing a balance or cost by replaying all history on the read path | **R-29** |
| `Log::warning(...)` followed by `return`/`continue` after an invariant failed | **R-30** |
| The AI service holding credentials that can write a non-proposal table | **R-31** |
| `auto_apply` above a confidence threshold with no deterministic agreement and no human | **R-32** |
| "the model already checked it" as a reason to skip validation | **R-32** |
| Calling an LLM to compare two numbers, match an exact reference, or apply a fixed rule | **R-34** |

---

## R-01 — Fat models / business logic in models

**What it is** — putting domain behaviour on the persistence class: `$invoice->post()`,
`$entry->calculateTotals()`, `$account->canBeDeleted()`. The model becomes the vocabulary of the
domain, and the code reads like the business: *"post the invoice"*.

**Why it is tempting** — it genuinely is the most readable thing you can write on day one. The data
and the behaviour that needs it are in the same place, so there is no plumbing, no service to
construct, no DTO to define. Every ORM tutorial teaches it. And the alternative looks like
bureaucracy: three files to do what one method did.

**Why QAYD rejects it** — the mechanism is **ambient reachability**. A model is reachable from
everywhere: a controller, a job, a console command, a factory, a seeder, a test, an eager-loaded
relation, another model's accessor. So business logic living on a model is invoked from call sites
its author never saw and cannot enumerate. Three consequences follow mechanically:

1. **Invariants become optional.** `$entry->post()` is available on any hydrated `JournalEntry`,
   including one loaded inside an unrelated transaction with no authorization context. The rule
   "posting requires a balanced entry, an open period, and a permission" is only true if every
   caller happens to enter through the door where it is checked.
2. **The class grows without bound, and the growth hides the rules.** Behaviour accretes onto the
   central noun because that is where it is easiest to add. Odoo's `account.move` reached 7,397 lines,
   and the single line that answers *"is this state transition legal?"* sits at line 5613 inside a
   message-accumulation loop (`ODOO_LEARNING.md:12104`). Nobody hid it. It just ended up there.
3. **Behaviour becomes untestable in isolation.** A test for the posting rules must construct a
   persisted model, which drags in the whole schema, which makes the test slow, which means fewer
   tests, which means the rules are less well specified.

The transferable form: **any construct that is reachable from everywhere must not carry a rule that
must be true always** — because "reachable from everywhere" and "enforced everywhere" are opposites,
not synonyms. This is the same mechanism as `R-20` (model hooks) and `R-28` (inheritance coupling);
they are three faces of one mistake.

**Evidence** — Phase 1, `ODOO_LEARNING.md:12104`: the legality check for the posting transition lives
at line 5613 of a 7,397-line model. `ODOO_LEARNING.md:229`: `account_account.py` is 1,659 lines and
still lacks a `parent_id`. `ODOO_LEARNING.md:11438`: because behaviour is assembled onto model classes
by module load order, *"what does `write()` do?"* has **no static answer** — the MRO is unknowable
without knowing the installed module set. That is the end state of fat models: the central verb of
the system cannot be read.

**What QAYD does instead** — business logic lives in Actions (`01` P10). Models declare table, casts,
relations, and scopes, and nothing else. Posting goes through `PostingService` alone
(`03_DESIGN_PATTERNS.md` **P-01 Posting**); lifecycle legality is one declared map
(**P-18 Lifecycle Map**); validation is one aggregated pass (**P-05 Validation**). An Action has
exactly one entry point, so "every caller" is a set of size one.

**Cost of the rejection** — real and daily. Three files (Action, DTO, test) where one method would
have done. `$entry->post()` is genuinely nicer to read than
`app(PostJournalEntryAction::class)->execute($dto)`. Newcomers from Rails/Laravel backgrounds
experience this as ceremony, and for the first month they are not wrong. We pay it because the cost
is *flat* — three files per operation forever — while the cost of the alternative compounds with
every call site.

**How the rejection is enforced** — **currently a review convention, which is the weakest rung on
`01`'s ladder.** The mechanism that closes it: an architecture test asserting that classes in
`App\Models\*` expose no public methods outside an allowlist (relations, scopes, accessors, casts,
Laravel lifecycle overrides). Until that test exists this rejection is aspirational.
*Enforcement debt: E-1.*

**Exceptions** — (a) query scopes, including complex ones; (b) accessors that format for
presentation and write nothing; (c) relation definitions. A method that reads other tables, writes
anything, or decides whether something is allowed is not one of these. No approval path — the
alternative is always an Action.

**Severity if violated** — **Serious.** Nothing is wrong the day it lands; the guarantee that
"posting is checked" quietly becomes "posting is checked on the paths we remembered".

---

## R-02 — Business logic in controllers

**What it is** — the HTTP layer doing domain work: computing totals, deciding whether a period is
open, writing several tables, orchestrating a multi-step operation inline in the request handler.

**Why it is tempting** — the controller already has everything: the authenticated user, the request
payload, the company context, an open DB connection. Extracting an Action means re-passing all of it
through a DTO. For a genuinely simple endpoint the controller version is shorter and no less clear.

**Why QAYD rejects it** — the mechanism is **transport capture**: logic written in a controller can
only be invoked by the transport it was written for. QAYD has at least six other entry points into the
same domain operations — queue workers, scheduled commands, the AI orchestrator, the import pipeline,
console tooling, and Reverb-driven flows — and every one of them must either duplicate the logic or
fake an HTTP request. Both outcomes are worse than the plumbing we avoided:

- **Duplication diverges.** The second copy is written by someone reading the first copy, and it
  reproduces the code but not the reasoning. When a rule changes, one copy changes.
- **Faking the transport imports the transport's assumptions.** A job that constructs a `Request` to
  reuse a controller inherits the controller's authorization model, its session assumptions, and its
  error envelope — none of which are true in a worker.

There is a second, quieter harm: **a controller cannot be given a precondition.** An Action can
require a `final readonly` DTO whose construction is itself the validation boundary. A controller
receives an array of unknown shape from the network. Logic that lives where the input is untyped
tends to grow defensive checks that duplicate — and eventually contradict — the real rules.

Transferable form: **never put a rule in a layer that only one caller can reach.** The test is not
"is this code short?" but "can the queue worker call it?"

**Evidence** — Odoo has no controller layer in the MVC sense, so Phase 1 offers no direct citation;
this rejection is derived from the same mechanism Phase 1 documents elsewhere. The closest analogue is
`ODOO_LEARNING.md:6811` — the >20% exchange-rate deviation guard is implemented as an *onchange*, i.e.
in the UI layer, so **it never fires on API writes or imports**, which is precisely how bad feed data
arrives. A rule placed in one transport protected exactly that transport, and the attack surface it
was written for was the one it did not cover.

**What QAYD does instead** — controllers do four things: resolve the route, authorize, build a DTO
from validated input, and call one Action. The response is rendered from what the Action returns.
Multi-step operations are composed inside an Action, never across controller lines
(`03_DESIGN_PATTERNS.md` **P-01**, **P-05**).

**Cost of the rejection** — an Action and a DTO for endpoints that genuinely have one line of logic.
For CRUD-shaped resources this is pure overhead and it looks like it. We accept it because the
alternative requires each engineer to correctly predict which endpoints will stay simple, and that
prediction is wrong often enough to matter.

**How the rejection is enforced** — **currently a review convention.** The mechanism: an architecture
test asserting that `App\Http\Controllers\*` may depend only on Actions, DTOs, Requests, Resources,
and authorization helpers — never on `DB`, a model class, or a repository.
*Enforcement debt: E-2.*

**Exceptions** — read-only endpoints that project an already-authorized query into a Resource may
skip the Action. Anything that writes, or that decides, may not. Approval: not required for the
read-only case; there is no approval path for the write case.

**Severity if violated** — **Serious.** The logic is correct on the day it ships and inaccessible to
every non-HTTP caller thereafter.

---

## R-03 — Soft financial validation (tolerances, "close enough" balance checks)

**What it is** — accepting a financial invariant that is *nearly* satisfied: a journal entry whose
debits and credits differ by less than a threshold, a tax repartition that sums to 99.998%, a
reconciliation that matches "within a fils". Almost every accounting system has one, because in
float-based arithmetic it is genuinely necessary.

**Why it is tempting** — it solves a real problem. With floats, `0.1 + 0.2 != 0.3`, so a strict
equality check rejects entries that are arithmetically correct. A tolerance makes the system usable.
It also absorbs the messiness of imported data, third-party feeds, and multi-currency rounding —
things the user cannot fix and does not want to be blocked by.

**Why QAYD rejects it** — three separate mechanisms, each sufficient on its own:

1. **A tolerance is a place for error to hide, sized in advance by someone who did not know what the
   error would be.** Every discrepancy below the threshold is accepted *silently and permanently*.
   The threshold does not distinguish "float noise" from "the importer dropped a line" from "the tax
   rate is misconfigured" — it only distinguishes *small* from *large*, and a systematic bug produces
   many small errors, not one large one.
2. **Tolerated error accumulates and then migrates.** Sub-threshold residue does not stay where it
   was created: it lands in a suspense or rounding account, it is carried forward at period close,
   and it eventually appears in a filed return as a number nobody can explain. The single discrepancy
   was below the threshold; the year's worth was not.
3. **A tolerance destroys the ability to reason about the system.** "Debits equal credits" is a
   theorem you can build on — the trial balance ties, the balance sheet balances, a rebuild can be
   checked against the source. "Debits equal credits to within ε" is not a theorem; it is a
   measurement, and every downstream check must now carry its own compatible ε. That is how a
   codebase ends up with `float_round`, `is_zero`, `compare_amounts`, and a documented warning that
   they are not equivalent.

**QAYD does not need the problem solved, because it does not have the problem.** With
`NUMERIC(19,4)` and bcmath strings there is no representation error to tolerate (see `R-09`). A
non-zero difference is therefore never noise — it is always information. Accepting it discards the
only signal we get.

Transferable form: **a threshold that silently absorbs a discrepancy converts a detectable defect into
an undetectable one.** If you cannot state what a sub-threshold difference *means*, you must not
accept it.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R6/R13 and `ODOO_LEARNING.md:6062`: `float_round` adds
`epsilon = 2**(log2(|normalized|) − 50)` to compensate for IEEE-754 tie mis-detection — an entire
correction layer that exists *only* because the engine floats. `ODOO_LEARNING.md:6852` records that
`compare_amounts` rounds **before** subtracting while `is_zero` rounds **after**, and the docstrings
warn these are not equivalent — i.e. the tolerance itself has variants that disagree.
`ODOO_LEARNING.md:6081`: tax repartition validation uses `float_compare(..., precision_digits=2)` on a
`digits=(16,12)` field, permitting roughly **0.005 of slack** on a ±100% invariant. And
`ODOO_LEARNING.md:6546`: the invariant `amount_currency ≈ balance × rate` is asserted **nowhere** —
not as a `CHECK`, not in application code — so foreign-currency sub-ledgers can drift with nothing
watching at all.

**What QAYD does instead** — zero tolerance, in **both** the entry currency and the base currency,
re-derived from the lines rather than trusted from cached header totals, and additionally expressed as
an unconditional `chk_je_balanced` database `CHECK`. Exact comparison on bcmath strings
(`03_DESIGN_PATTERNS.md` **P-14 Money**). Where a residual genuinely exists — an opening-balance
import from a prior system — it is surfaced in the DTO, named, and requires an explicit acknowledged
suspense line (see `R-12`); it is never absorbed.

**Cost of the rejection** — QAYD rejects entries that other systems would accept, and some of those
rejections will be user-visible friction on messy imports. Deterministic penny distribution must be
implemented carefully on bcmath rather than papered over by ε (`P-14`). Multi-currency rounding
requires real thought at design time instead of a tolerance at runtime.

**How the rejection is enforced** — **database `CHECK` (strongest rung)**: `chk_je_balanced` is
unconditional and has no context escape, so even a raw-SQL writer is rejected. Application-side,
`PostingService` re-derives balance from lines and raises `UnbalancedEntryException` carrying the
exact difference as a bcmath string. **Missing:** a static-analysis rule banning `abs(... ) < literal`
comparisons on money-typed values. *Enforcement debt: E-3.*

**Exceptions** — none for balance. For *presentation* rounding (a report showing thousands), rounding
is applied at render time to a value that was never stored rounded; that is formatting, not
validation, and is not an exception to this rule. No approval path exists for a balance tolerance.

**Severity if violated** — **Catastrophic.** Silent, accumulating, and discovered by an auditor.

---

## R-04 — Mutable posted entries; un-posting by state flip

**What it is** — allowing a posted journal entry to be edited, or providing a path that returns it to
`draft` so it can be corrected and re-posted. Usually implemented as a "cancel" or "reset to draft"
button, sometimes routed through an intermediate state so it looks controlled.

**Why it is tempting** — it is what users ask for, constantly, and the request is reasonable. Someone
posted to the wrong account, noticed within a minute, and wants to fix it. A reversal plus a
correcting entry means three documents where the user sees one mistake, and it makes the audit trail
*noisier*, which feels like the opposite of what an audit trail is for. Un-posting also seems safely
reversible: nothing was deleted, only a status changed.

**Why QAYD rejects it** — the mechanism is that **a posted entry is not a record of intent, it is a
record of a claim already relied upon.** The moment an entry is posted it may have been read by a
trial balance, included in a VAT return, shown to a bank, used to compute a period balance, hashed
into an audit chain, or referenced by a reconciliation. Editing it does not correct the past; it
**rewrites what the past was**, while every artifact derived from the old version continues to exist.
Concretely:

1. **Derived state silently detaches.** Period rollups, hash chains, reconciliation residuals, and
   filed reports were computed from the old values. Nothing tells them to change. The system is now
   internally inconsistent and reports no error, because from each component's local view nothing
   happened.
2. **The state flip is worse than the edit.** `posted → draft` is a single-column write that
   *un-asserts* a claim. Any invariant expressed as "posted rows are immutable" is trivially defeated
   by first making the row not-posted. That is not a loophole in the guarantee; it **is** the
   guarantee, inverted — which is why the transition itself, not just the edit, must be structurally
   impossible.
3. **Mutability is load-bearing in the wrong direction.** Once posted rows can change, they cannot be
   append-only; once not append-only, the incremental period rollup (`H2`) becomes untrustworthy, the
   hash chain can go stale, and partitioning by `(company, period)` becomes unsafe. One decision
   removes three unrelated capabilities.

Transferable form: **anything that has been relied upon may be superseded but never revised.** The
test is not "can we change this safely?" but "has anything already read it?" — and in an accounting
system the answer is always yes, because reading is what an accounting system is for.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R26 and `ODOO_TO_QAYD.md` §1.2. `ODOO_LEARNING.md:905`:
posted-immutability in Odoo is a Python `if` with a hard-coded bypass (`skip_readonly_check`) — no
trigger, no rule, no grant. `ODOO_LEARNING.md:3343` is the decisive finding: posted lines **are**
mutated on the reconciliation path — `full_reconcile_id` nulled by a FK, `matching_number` rewritten
by raw SQL, `amount_residual`/`reconciled` rewritten by the ORM — and Odoo's immutability guarantee
covers only the legally-meaningful fields, with reconciliation state *explicitly exempt*.
`ODOO_LEARNING.md:3718` documents the end state: un-matching a bank statement line deletes and
recreates the journal items of a **posted** move under `force_delete=True, skip_readonly_check=True`.
`ODOO_LEARNING.md:907`: a posted, fully-reconciled invoice can silently become unreconciled as a side
effect of an unrelated field update. And `ODOO_LEARNING.md:1622`: deleting a header cascades its
ledger lines away (`ondelete="cascade"`), so there is no append-only guarantee at all.

**What QAYD does instead** — correction is by **reversal**: a new, first-class posted document created
through the same `PostingService`, linked by `reversal_of_entry_id`, carrying a `NOT NULL` reason. The
original stays posted forever with its number and hash intact
(`03_DESIGN_PATTERNS.md` **P-13 Immutability & Correction**). There is **no un-post path** — not a
button, not an Action, not a console command. `ledger_entries` is append-only by trigger
(**P-02 Ledger Projection**), and the lifecycle map (**P-18**) declares `posted` as reachable only to
terminal states.

**Cost of the rejection** — genuinely high, and mostly borne by users. A typo costs three documents.
Month-end tidy-ups that other systems absorb become visible reversal traffic. Training and support
material must explain why "just fix it" is not offered, and some prospects will read this as the
product being rigid. We also give up the ability to quietly clean up our *own* data-migration
mistakes, which will hurt at least once.

**How the rejection is enforced** — **database triggers (strongest rung)**: `ledger_entries` rejects
`UPDATE`/`DELETE` even for the owning role; posted-state triggers reject mutation of posted
`journal_entries`/`journal_lines`; and — per `01` P18 — the lifecycle trigger must reject
`posted → anything-non-terminal`, which forbids the state-flip path structurally rather than by
policy. **Missing:** the lifecycle transition trigger is specified but not yet shipped; until it is,
un-posting is prevented by the absence of code that does it, which is not a mechanism.
*Enforcement debt: E-4.*

**Exceptions** — none. Not for admins, not for support, not for data migration, not "before the
period closes", not "if nobody has read it yet". If posted data is wrong, it is reversed. The only
legitimate discussion is whether something should have been posted at all — which is what the
approval boundary before posting is for (**P-04 Approval**).

**Severity if violated** — **Catastrophic.** It is the single decision that, in Odoo, forced the
ledger to be mutable and produced the raw-SQL writes, the staleness bug, and the inability to be
append-only.

---

## R-05 — Application-only integrity (invariants enforced solely in code)

**What it is** — expressing a business rule that must always be true as a check in the application:
a validator, an Action guard, a model hook, a service method. The database holds the data; the code
holds the meaning.

**Why it is tempting** — application checks are strictly nicer in every respect except the one that
matters. They produce good error messages with field names and suggestions. They are easy to test.
They are easy to change. They can be conditional on user, plan, or context. A database `CHECK` gives
you a constraint-violation error with a constraint name and nothing else. Given that the application
is the only thing writing the database, checking there looks like checking everywhere.

**Why QAYD rejects it** — the mechanism is that **"the application" is not a single thing, and the
set of writers is not closed.** An invariant enforced in code is enforced on the paths that call the
code, and that set is: whatever exists today, minus the paths whose authors forgot, plus every path
added later by someone who never read this document, plus every path that is not the application at
all — a `psql` session during an incident, a data-fix script, a logical-replication subscriber, an
ETL job, a future service in another language, a restore-and-patch. There is no moment at which that
set is knowable, and it only grows.

Three amplifiers make this worse than it first appears:

1. **Payload-shape conditionality.** Application validators typically fire only when the relevant
   field is present in the write. So an invariant can be skipped by *omitting the field* — not by
   bypassing anything, just by writing a different subset of columns.
2. **After-the-fact enforcement.** Many application checks run after the `INSERT` and rely on
   rollback. That is fine until something between the insert and the check observes the row, or the
   check itself is skipped by (1).
3. **Drift is silent by construction.** If the rule is only in code, there is nothing that can detect
   data violating it. You do not learn that the invariant was broken; you learn that a report is
   wrong, months later, and then have to work backwards to discover which invariant was never true.

Transferable form: **an invariant is only as strong as the weakest writer that can reach the data.**
Ask not "does our code check this?" but "what happens when something that is not our code writes this
row?" If the answer is "the row is accepted", the invariant does not exist.

Note the corollary, which `01` P1 states directly: the application layer *may* check earlier and more
kindly — that is good UX and we want it — but never **instead**.

**Evidence** — Phase 1's central integrity finding, `ODOO_LEARNING.md:11452`: across the studied
checkout, **125 Python-only `@api.constrains` versus 64 real DDL constraints — 66% application-only;
in `addons/account`, 82%; in `addons/analytic` and `addons/stock_account`, 100%.** The same section
documents all three amplifiers with citations: constraints fire only when a declared field intersects
the written field set (`models.py:1253-1269`, and the decorator's own docstring admits it); they run
*after* the `INSERT` (`models.py:4949` vs `4886`), so enforcement is rollback rather than rejection;
and they run sudoed, so the constraint's view of the data differs from the writer's. Consequences
observed elsewhere in the same study: `account_partial_reconcile` has **zero** SQL constraints, no row
locking anywhere in the reconciliation path, and **zero** concurrency tests among 95 reconciliation
tests (`ODOO_LEARNING.md:3193`); over-reconciliation is prevented only by Python re-reading a stored
column. `ODOO_LEARNING.md:290`: there is no unique index and no DB constraint on account codes at all.

**What QAYD does instead** — `01` P1: if a statement about the data must always be true, it is a
`CHECK`, `FK`, `UNIQUE`, `EXCLUDE`, or trigger, written in a reviewed migration. Multi-row and
cross-table invariants use `DEFERRABLE INITIALLY DEFERRED` constraint triggers rather than
application loops. The application still validates first, aggregating every violation into one
structured response (`03_DESIGN_PATTERNS.md` **P-05 Validation**) — the database is the floor, not
the user interface.

**Cost of the rejection** — significant and ongoing. Every invariant change becomes a migration, with
a lock consideration on a large table. Error messages from constraint violations must be mapped back
to friendly, field-level messages by hand. Some invariants are genuinely awkward in SQL and will cost
a day of thought instead of ten minutes of PHP. And a `CHECK` cannot be relaxed for one customer
without a schema change — which is a feature, but it will feel like a constraint on the business at
least once a quarter.

**How the rejection is enforced** — **partly structural, mostly convention.** The strong part: DDL
lives only in reviewed migrations, and the constraints that exist are absolute. The weak part: nothing
mechanically detects an invariant that was implemented *only* in an Action. The mechanism that would
close it is a review rule with teeth — every PR introducing a business rule must state where the DDL
is or why none is possible — plus, for the tenancy subset, the catalog-introspection CI check (see
`R-08`). *Enforcement debt: E-5.*

**Exceptions** — rules that are genuinely not invariants: authorization decisions (who may do this),
workflow policy (which approver is required), UX guidance (warnings, suggestions), and anything
tenant-configurable. These belong in code and configuration precisely because they are *not* always
true. The test: if a row violating the rule would be corrupt regardless of who wrote it or why, it is
an invariant and belongs in the database. Approval to leave an invariant application-only: architecture
owner, recorded as an ADR with the specific reason SQL cannot express it.

**Severity if violated** — **Catastrophic.** The failure mode is silent data that violates a rule
everybody believes is enforced.

---

## R-06 — Context-gated invariants (rules a caller can switch off)

**What it is** — a check that consults ambient state before deciding whether to run:
`if ($context['skip_validation']) return;`, a `$force = false` parameter, a config flag, a
request-scoped "we're in import mode" marker. The rule still exists, documented and tested; it simply
does not always execute.

**Why it is tempting** — it is the pragmatic answer to a real conflict. A legitimate internal
operation — a migration, an exchange-difference adjustment, a cascade from an unrelated field — needs
to write something the general rule forbids. Rewriting the rule to admit the special case is hard and
risky; adding a flag takes one line and is obviously scoped to the caller who passes it. It even looks
*more* careful than removing the check, because the check is still there for everyone else.

**Why QAYD rejects it** — the mechanism is that **a switchable invariant is not an invariant; it is a
default.** Three things follow, in order, and they follow every time:

1. **The flag escapes its original caller.** It is added for one justified case. The next engineer
   finds it while debugging, sees that it is an existing, tested, sanctioned mechanism, and uses it
   for a case that is *nearly* as justified. Nothing in the code distinguishes the two. Flags do not
   stay where they were put, because their entire purpose is to be reachable from a caller.
2. **The safe path becomes the unusual path.** Once several callers pass the flag, the guarantee
   inverts: instead of "this rule holds unless explicitly excepted", it becomes "this rule holds on
   the paths that did not need to be fast/quiet/convenient". Nobody decides this; it is the
   accumulated result of individually reasonable decisions.
3. **The exception is invisible afterwards.** A row written under a bypass is indistinguishable from
   a row written under the rule. So the data cannot be audited for the exception, the blast radius of
   a bad bypass cannot be measured, and the invariant cannot even be *re-established* later, because
   you cannot find the rows that violate it.

Transferable form: **any parameter, key, or flag whose meaning is "do less checking" will eventually
be passed by a caller who did not understand what checking they were skipping.** Recognise it by
intent, not spelling: `force`, `skip`, `bypass`, `unsafe`, `raw`, `internal`, `quick`, `strict=false`,
`validate=false`, and a "system mode" that behaves differently are all the same construct.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R11. The named set in Odoo is
`check_move_validity`, `skip_readonly_check`, `force_delete`, and `bypass_lock_check`
(`ODOO_LEARNING.md:1372`), and each disables something load-bearing: balance validation
(`:703`, `:716-717` — the balance context manager returns before the query runs if the context key is
set), posted-row immutability (`:797`), deletion of posted entries including the "restrictive audit
trail" protection (`:822`, `:7180` — the trail is therefore *advisory*), and fiscal-period lock
enforcement (`:2227` — `_check_fiscal_lock_dates` early-returns on `bypass_lock_check`). Points 1 and
2 above are observable in the study rather than hypothesised: the bank-statement undo path passes
**two** bypasses together (`force_delete=True, skip_readonly_check=True`) to delete and recreate
journal items on a posted move (`:3718`, `:3826` — *"Fatal for QAYD"*), and the analytic "distribution
must sum to 100%" rule is not only opt-in but **explicitly disabled by production code** for
exchange-difference moves (`:10481` item 5, disabled at `account_move_line.py:3130`). Point 3 is
visible too: the only trace of a forced deletion is a `_logger.info` line in a rotating text file
(`:7181`, `:7277`).

**What QAYD does instead** — `01` P2: no invariant has an off switch. The invariant lives in the
database where no context can reach it (`R-05`), and legitimate special cases are modelled as
**first-class, audited, time-boxed data** rather than as an absent check. The worked example is
`lock_exceptions` (`ODOO_BACKLOG.md` M1): a relaxation is a row with a `NOT NULL` reason, a `NOT NULL`
end time bounded by a maximum-duration `CHECK`, a recorded actor, a `CHECK` forbidding it for hard
locks, and a tag on every entry written during the window so "what moved under this exception?" is an
index scan. The rule is never off; the *policy* was explicitly, accountably widened for a bounded
period. See `03_DESIGN_PATTERNS.md` **P-08 Locking** and **P-04 Approval**.

**Cost of the rejection** — real. Genuine internal operations must be modelled properly instead of
flagged past, which is more design work up front. Data migrations cannot quietly step around
constraints and must instead be expressed as legitimate domain operations or run as an explicit,
recorded maintenance event. And there will be a moment in an incident where a bypass would have
resolved something in five minutes and instead takes an hour.

**How the rejection is enforced** — **structurally strong where the invariant is in the database**
(a context key cannot reach a trigger), **weak where it is not.** The missing mechanism is the
grep-based architecture test named in `01` P2: fail the build on parameters and array keys matching
the bypass vocabulary in domain namespaces. Until it exists, this is a review convention.
*Enforcement debt: E-6.*

**Exceptions** — none for invariants. Feature flags for *product* behaviour (is this UI enabled, is
this integration on) are not this pattern and are unaffected. The distinguishing question: does the
flag change what data is allowed to exist? If yes, it is forbidden regardless of naming.

**Severity if violated** — **Catastrophic.** Every guarantee downstream of the gated invariant
becomes conditional on a call site nobody is tracking.

---

## R-07 — Ambient privilege bypass (`sudo()`-style)

**What it is** — a mechanism that elevates the current execution context so that permission and
tenancy checks stop applying: an escalate-this-query call, a "run as system" wrapper, an unguarded
model, a service that quietly uses an admin database connection.

**Why it is tempting** — it is the shortest path through a real problem. A background job has no
user. A cross-tenant platform report legitimately needs to read several companies. A computed field
needs a row the acting user cannot see in order to produce a number they *are* allowed to see. Writing
a narrower permission for each of these is slow; elevating for a line is instant, and the intent is
usually genuinely benign.

**Why QAYD rejects it** — the mechanism is **transitive, unscoped, unlogged authority**. Three
properties combine into something far worse than any one of them:

1. **It is ambient, so it is not visible at the point of harm.** The elevation happens at one
   expression; the dangerous read or write may happen several calls later, in a function whose author
   had no idea it could ever run elevated. Reviewing that function tells you nothing about whether it
   is safe.
2. **It propagates.** Anything derived from an elevated handle is typically also elevated. The blast
   radius is therefore not the line that elevated but the entire object graph reachable from it —
   which is not something a reviewer can compute.
3. **It leaves no record.** No actor, no reason, no scope, no target tenant. So after an incident
   there is no way to answer "what did this touch?" — the single most important question, and the one
   the mechanism structurally cannot answer.

The deepest harm is architectural rather than operational: **once an escape hatch exists, every
security boundary in the system degrades from a mechanism to a convention.** You can no longer reason
"this data is isolated because the database isolates it"; you can only reason "this data is isolated
provided no code path on the way here elevated" — which is a whole-codebase property, re-verified on
every change, i.e. not a property at all.

Transferable form: **authority must be requested narrowly at the point of use, never granted broadly
at the point of convenience.** Any construct that makes checks stop applying for an *extent of
execution* rather than for a *named operation on a named resource* is this pattern.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R1 and `ODOO_TO_QAYD.md` §7.2. Scale:
**552 call sites in the studied checkout, 181 in `addons/account`** (`ODOO_LEARNING.md:186`).
Property 1 and 2 in practice: the chart-of-accounts read path elevates on *every* code read, search,
and uniqueness check, deliberately bypassing the multi-company record rule (`:493`), and because the
company-scoped code lives in a JSONB blob, **a mis-scoped elevated read returns another company's
account number** (`:511`). Property 3, and a genuine privilege-widening path: `_reconciled_by_number`
groups elevated and the result is handed to an unreconcile action, so a user can unreconcile lines
they cannot read (`:3406`). On the money path: inventory valuation creates and posts a journal entry
elevated (`:8703`, `:8728`), so *"a user who can validate a picking can cause a posted journal entry
they could not create directly"*. Even authorization itself is computed elevated — the fiscal lock-date
resolution runs elevated because a user may not read a parent company (`:2357`), which the study flags
as *"exactly the pattern to avoid"* under RLS. And Odoo's own tacit admission: security-adjacent
models set `_allow_sudo_commands = False` (`:7299`), conceding that an elevated nested write is a
privilege-escalation primitive.

**What QAYD does instead** — `01` P3: tenancy is a storage-engine property. The runtime role is
`NOSUPERUSER NOBYPASSRLS`, so there is no elevation available to application code even in principle;
`is_platform_admin` exists as a GUC and is **deliberately not wired to any bypass**. Genuine
cross-tenant work is a `PlatformOperation` action object — a second connection as a distinct role,
narrow per-table policy clauses, and an audit row written **in the same transaction**, so if the audit
write fails the operation fails. See `03_DESIGN_PATTERNS.md` **P-09 RLS** and **P-10 Audit**. The
standing rule, verbatim from Phase 1: *"There is no `->sudo()`. If you think you need one, you need a
new permission, a new policy clause, or a `PlatformOperation` with a written reason."*

**Cost of the rejection** — every legitimate cross-boundary need becomes a modelled operation instead
of one line. Support tooling is harder to write. Background jobs must carry an explicit tenant context
rather than running "as the system", which means the job payload and the GUC lifecycle both become
things we have to get right (and `H7` warns that getting the GUC lifecycle wrong under a transaction
pooler is itself a silent-breach risk). Some diagnostics that would be trivial with elevation require
building a purpose-made, audited read path.

**How the rejection is enforced** — **database GRANT (strongest rung)**: `NOBYPASSRLS` means RLS
cannot be turned off by anything the application can express, and `FORCE ROW LEVEL SECURITY` means it
applies to the table owner too. **Missing:** an architecture test banning `withoutGlobalScopes()`,
`Model::unguarded()`, `unguard()`, and any use of a privileged connection name outside the
`PlatformOperation` namespace. *Enforcement debt: E-7.*

**Exceptions** — `PlatformOperation` is the only cross-tenant path, and each new one is approved by
the architecture owner with a written reason, a named target scope, and an audit row. There is no
exception that grants ambient elevation to ordinary code.

**Severity if violated** — **Catastrophic.** This is the tenancy-breach pattern, and it is silent.

---

## R-08 — Nullable `company_id` on a tenant table

**What it is** — allowing the tenant discriminator to be `NULL`, usually so that "shared" or
"system-wide" rows can live in the same table as tenant rows: a global chart template, a system
account, a record created before the company was known.

**Why it is tempting** — it models something real. Some rows genuinely are shared, and a nullable
column expresses "belongs to everyone / belongs to nobody" without a second table. It is also often
not a decision at all: the column is derived or computed, nobody marks it required, and it is
nullable by omission.

**Why QAYD rejects it** — the mechanism is specific and counter-intuitive, and it is the reason this
entry is Catastrophic rather than Untidy: **`NULL` does not fail an isolation predicate — it
disappears from it.** A boundary policy of the form `company_id = current_company` (or `IN (…)`)
evaluates to `NULL`, not `FALSE`, for a `NULL` row. In SQL's three-valued logic the row is therefore
excluded from *every* tenant's view — which sounds safe, and is exactly the trap:

1. **The row leaks *out* of the boundary rather than into it.** It becomes invisible to normal,
   RLS-scoped queries and visible only to whatever runs without the policy — reports, migrations,
   admin tooling, a replica. So the row that is least protected is the one nobody can see to notice.
2. **The bug is undetectable by the obvious test.** "Can tenant A see tenant B's data?" returns *no*,
   for the nullable row, for both tenants. The test passes. The isolation is broken anyway.
3. **It makes every downstream guarantee conditional.** Unique constraints scoped
   `(company_id, code)` stop being unique when `company_id` is `NULL` (distinct `NULL`s do not
   conflict). Aggregates silently omit the row. Foreign keys to it cross the boundary invisibly.

Transferable form: **a discriminator that can be absent is not a discriminator.** Any column whose
job is to answer "which tenant/scope/partition is this?" must be `NOT NULL`, because a predicate over
it is only meaningful when it is total.

There is a second, structural point worth stating: shared reference data and tenant data are
different *kinds* of thing, and putting them in one table with a nullable key is an attempt to avoid
that distinction rather than model it.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R14. `ODOO_LEARNING.md:629`: on `account.move`,
`company_id` is computed, stored, and carries **no `required=True`** — so the column is nullable on
the central accounting table. `ODOO_LEARNING.md:186` names it among the three structural weaknesses of
Odoo's tenancy model. The compounding effect is visible in the same study: isolation is a single
application-layer `ir.rule` (`:507`) with a pervasive bypass, and there is nothing that checks a model
with a company field actually has a rule — `ODOO_BACKLOG.md` H6 records **72 hand-written rules for
192 models**, so *a new model ships cross-tenant-readable and nothing fails*.

**What QAYD does instead** — `01` P3: every tenant table carries `company_id BIGINT NOT NULL`,
`ENABLE ROW LEVEL SECURITY`, `FORCE ROW LEVEL SECURITY`, and a named **RESTRICTIVE** boundary policy
keyed on `app.current_company_id`, with a `NOSUPERUSER NOBYPASSRLS` runtime role
(`03_DESIGN_PATTERNS.md` **P-09 RLS**). Genuinely shared reference data lives in its own table with
its own policy, and is *copied* into a company on adoption rather than pointed at across the boundary.

**Cost of the rejection** — shared data needs a second table and a copy-on-adopt step, which
duplicates rows that a nullable column would have shared, and means an upstream template fix does not
propagate automatically to companies that already adopted it. Bootstrapping is fiddlier: rows cannot
be created before their company exists, so onboarding order matters.

**How the rejection is enforced** — **`NOT NULL` in the migration**, plus — and this is the
high-leverage part — the **catalog-introspection CI check** (`ODOO_BACKLOG.md` H6): a query over
`pg_class` / `pg_policy` / `pg_attribute` that **fails the build** if any table with a `company_id`
column lacks `NOT NULL`, `relrowsecurity`, `relforcerowsecurity`, and the named restrictive policy.
Phase 1 rates this the highest leverage-to-cost item in the entire study, at 5 points, precisely
because it converts a convention into a mechanism and Odoo structurally cannot write it.
**It is not yet built.** *Enforcement debt: E-8.*

**Exceptions** — tables that are genuinely not tenant-scoped (platform configuration, currency
definitions, country/tax jurisdiction reference data) do not have a `company_id` at all — which is
different from having a nullable one. A table with the column must have it `NOT NULL`. No approval
path.

**Severity if violated** — **Catastrophic**, and specifically the kind that passes its tests.

---

## R-09 — Float money and epsilon compensation

**What it is** — storing or computing monetary amounts as binary floating point (`float`, `double`,
PHP's native `float`, JS `number`), then adding correction machinery — epsilon nudges, rounding
helpers, comparison utilities — to make the results behave.

**Why it is tempting** — floats are the default numeric type in nearly every language, they are fast,
arithmetic on them is ordinary syntax (`$a + $b`), and for small numbers of small operations the
error is invisible. The correction machinery, once written, genuinely works most of the time. And the
alternative — money as strings, arithmetic through function calls — is verbose and feels archaic.

**Why QAYD rejects it** — the mechanism is that **binary floating point cannot represent most decimal
fractions, so the error is not a bug to be fixed but a property of the representation**. Two
consequences, and then a third that is the real reason:

1. **Error accumulates with operation count**, so it grows with data volume and with the length of the
   computation. A tax calculation with four rounding levels, or an opening-balance import accumulating
   a running total across 5,000 lines, is exactly where it bites — and those are the operations whose
   correctness matters most.
2. **Equality becomes undecidable.** `0.1 + 0.2 == 0.3` is false. So every comparison needs a
   tolerance, which is `R-03`, which is Catastrophic. **Float money does not merely risk error; it
   forces the adoption of another rejected pattern to remain usable.** That is the strongest argument
   here: the two rejections are not independent, and accepting the first makes the second unavoidable.
3. **The compensation layer becomes load-bearing and then becomes wrong.** Once you have a rounding
   helper with an epsilon, you have a component whose behaviour is subtle, whose variants disagree,
   and which every money path in the system depends on. Its bugs are systematic and invisible.

Transferable form: **do not adopt a representation whose error must then be managed.** If a value has
exact decimal semantics — money, tax rates, percentages, quantities that are counted — represent it
exactly. Recognise the pattern by its compensation layer: if a codebase needs an epsilon, the
representation is wrong.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R13. `ODOO_LEARNING.md:6062` is the definitive citation:
Odoo's `float_round` normalises by the rounding factor, inverts factors below 1 to reduce error, and
**adds `epsilon = 2**(log2(|normalized|) − 50)` to compensate for IEEE-754 tie mis-detection such as
`2.675 == 2.6749999999999998`** — the study calls this *"the single strongest argument for QAYD's
bcmath-strings mandate"*. Consequence 3 is documented at `:6852`: `compare_amounts` rounds **before**
subtracting, `is_zero` rounds **after**, and the docstrings warn they are not equivalent. Consequence
1 appears concretely at `ODOO_TO_QAYD.md` §2.5: the opening-balance import accumulates its running
total in a Python float across thousands of lines. There is even a performance tail —
`math.log2` and `2**` per call inside per-line loops (`:6111`, `:6911`). And the pattern spreads
beyond money: analytic distribution percentages are made comparable by *rounding on write* so that
float equality can be used at all (`:10272`).

**What QAYD does instead** — `01` P4: money is `NUMERIC(19,4)` in PostgreSQL and **bcmath strings**
in PHP, end to end — DTO fields typed as `string`, arithmetic through bcmath, exact comparison, and
deterministic penny distribution implemented explicitly with the allocation trace as a first-class
output (`03_DESIGN_PATTERNS.md` **P-14 Money**). No epsilon exists anywhere, because there is nothing
to compensate for.

**Cost of the rejection** — verbosity, permanently. `bcadd($a, $b, 4)` instead of `$a + $b`, on every
line. Every boundary — JSON, JS, CSV, third-party APIs — needs an explicit conversion, and JavaScript
in particular has no exact type, so the frontend must treat money as an opaque string and never do
arithmetic on it. Sorting and aggregation must happen in SQL rather than PHP (which is `R-10`, and is
a benefit disguised as a cost). New engineers will write `+` at least once.

**How the rejection is enforced** — **`NUMERIC(19,4)` column types (database, strongest rung)** for
storage, plus `final readonly` DTOs typing money as `string`. **Partially missing:** static analysis
that flags arithmetic operators and `(float)` casts applied to money-typed values, and a check that no
migration introduces a `float`/`double precision`/`real` column on a financial table. The type system
carries most of this today; the analyzer rule would close the rest. *Enforcement debt: E-9.*

**Exceptions** — non-monetary statistics that are inherently approximate and never posted: a
confidence score, a similarity ratio, a percentile in a dashboard, an ML feature. These may be
floats because nothing is derived from them that lands in a ledger. The moment a value can influence
a posted amount, it is money-grade. Rates and percentages that participate in money arithmetic are
money-grade (Phase 1's recommendation for tax repartition factors is **integer ppm**, not float).

**Severity if violated** — **Catastrophic.** Silent, accumulating, and it drags `R-03` in behind it.

---

## R-10 — PHP-side aggregation of money

**What it is** — fetching rows into the application and summing them there: `array_sum()`,
`$collection->sum('amount')`, a `foreach` accumulating a total, or converting currency per record in
a loop before adding up.

**Why it is tempting** — it is expressive and easy. Collections have a `sum()`. The rows were often
already loaded for another reason, so summing them looks free. Complex conditional aggregation
("include this line only if…") is genuinely easier to express in PHP than in SQL, especially when the
condition involves data the query did not join.

**Why QAYD rejects it** — two mechanisms, one about scale and one about correctness:

1. **It materialises an unbounded row set to produce one number.** The cost of a total becomes
   proportional to the history being totalled, in memory and in transfer, and it degrades exactly when
   the customer is most valuable. A trial balance built this way works beautifully in development with
   200 entries and is unusable at 2 million. Worse, the failure is a slow slide, not a break, so it is
   normally discovered in production.
2. **It re-introduces the arithmetic risk the storage type had eliminated.** `NUMERIC` arithmetic in
   PostgreSQL is exact. The moment values leave the database they are subject to whatever the host
   language does — and if anything in the path is a float (a cast, a JSON round-trip, a
   `->sum()` on a collection of loosely-typed values), the drift is back. Aggregating in SQL keeps
   the arithmetic in the only place that is exact by construction.

There is a third, subtler harm: **application aggregation hides the query the system actually needs.**
As long as totals are computed in PHP, no one designs the index that would make them cheap, and the
schema never learns what it is being asked. The rollup table that would make the trial balance a
2,000-row index scan is never built, because the problem it solves is spread across a hundred loops.

Transferable form: **compute where the data is, not where the code is convenient.** If a result is a
scalar over many rows, the many rows must not cross a process boundary.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R24. `ODOO_LEARNING.md:8701`: both `stock_quant` and
`account.analytic.account` intercept `value:sum` / `balance:sum` and **sum in Python**, because the
field has no SQL expression — so *"a grouped inventory-value report materializes every record"*.
`ODOO_LEARNING.md:10481` item 14: `_compute_debit_credit_balance` runs two grouped reads per plan,
**converts currency per record in Python**, then sums in Python. The structural cause is documented at
`:10481` item 4 — you *cannot* `SUM` money grouped by analytic distribution, because the aggregate
raises for anything but a count, so the subsystem's primary analytical query is not expressible against
its primary storage and Python summation is the only remaining option. That is the general shape of
this failure: PHP-side aggregation is usually a symptom of a storage choice (`R-16`) that made SQL
aggregation impossible.

**What QAYD does instead** — aggregate in SQL. `ledger_entries.signed_base_amount` makes any balance a
single `SUM()`, and hot aggregates are maintained as an incremental rollup with a rebuilder and a
drift detector (`03_DESIGN_PATTERNS.md` **P-15 Read Model**; `ODOO_BACKLOG.md` H2). Conditional
aggregation uses `FILTER (WHERE …)` rather than a PHP branch. Where a total genuinely must be
composed from several queries, the composition is over already-aggregated scalars, never over rows.

**Cost of the rejection** — some genuinely complex aggregations are harder to write and harder to
read in SQL, and they are less portable if the database ever changes (it will not; `ADR-0004`). Unit
tests for aggregation logic need a database rather than an array fixture, which makes them slower.

**How the rejection is enforced** — **review convention**, supported by performance tests that assert
row counts and query plans for the main reporting paths. A mechanical rule is hard here because
`->sum()` on a small, already-loaded collection is legitimate. The practical test used in review: *is
the number of rows summed bounded by something other than the customer's history?* If not, it belongs
in SQL. *Enforcement debt: E-10.*

**Exceptions** — summing a bounded, already-materialised set: the lines of one journal entry being
validated (bounded by the document), a page of results being subtotalled for display. Both are fine.
Anything scoped by date range, account, or period is not.

**Severity if violated** — **Serious.** Not wrong on the day it ships; a scaling cliff and a drift
vector afterwards.

---

## R-11 — Silent coercion of user data

**What it is** — accepting an *almost* valid request by quietly changing it: moving a posting date
forward into an open period, snapping an amount to the nearest fils, substituting a default exchange
rate when none exists, picking the nearest matching account. The operation succeeds and returns
success; the data stored is not the data submitted.

**Why it is tempting** — it is excellent UX, and often it is genuinely the *right answer*. The user
wanted to post a supplier invoice into a period that just closed; moving it to the next open date is
what an accountant would have done anyway. Rejecting instead means an error the user cannot act on
without domain knowledge. Coercion converts a dead end into a completed task, and every usability
instinct favours it.

**Why QAYD rejects it** — the mechanism is that **coercion destroys the distinction between what was
requested and what happened, at exactly the moment that distinction becomes evidence.** Four
consequences:

1. **The user's assertion is overwritten without their knowledge.** A posting date is not a
   preference; it is a claim about *when an economic event occurred*, and it determines which return
   the transaction lands in. Changing it silently changes a legal statement made by the customer.
2. **The audit trail records the coerced value as if it were intended.** There is no artifact saying
   "the user asked for X and the system stored Y". So the discrepancy cannot be found later, cannot be
   explained to an auditor, and cannot be corrected in bulk when the coercion rule turns out to have
   been wrong.
3. **Coercion rules acquire dependencies nobody sanctioned.** Odoo's coerced posting date is derived
   from the *numbering* granularity — so the numbering policy determines the accounting date. Nobody
   designed that; it fell out of two coercion rules meeting. Silent adjustments compose silently.
4. **It is disqualifying when the drafter is a machine.** An AI agent submitting an entry cannot learn
   from a silent correction, cannot flag it for review, and will confidently repeat it. A rejection
   with a structured reason is a training signal; a silent fix is data poisoning.

Transferable form: **never substitute; always resolve.** When a request is nearly valid, the system
must return *what it would take to make it valid* and require an explicit acceptance. The difference
between "we fixed it for you" and "here is the fix, confirm it" is the entire audit trail.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R8 and R7. Dates: `ODOO_LEARNING.md:1074` and `:1367` —
`_post` **rewrites the user's accounting date with no confirmation, no return value, and no
distinguishable audit signal**; the target is the last violated lock date plus one day, snapped
forward to the end of the containing month or year *according to the deduced sequence-numbering
granularity* (`:1109`), which the study calls *"exactly backwards"*. Consequence 2 is sharpened by the
fact that a **preview helper already exists** (`_get_lock_date_message`) and the posting path simply
does not consult it (`:1111`) — the information needed for an explicit resolution was available and
discarded. Rates: `ODOO_LEARNING.md:5738` and `:6825` — a missing rate resolves to the earliest known
rate and then to **`1.0`**, so a currency with no rates *converts at par, silently*, which Phase 1
rates the highest-severity defect anywhere in Odoo's currency handling. Related silent substitutions
in the same study: an account is created as a side effect of a getter (`:3046`), and an import
silently mutates chart-of-accounts configuration by setting accounts reconcilable (`:3393`).

**What QAYD does instead** — `01` P14. The posting path raises rather than adjusts, and returns a
resolution object — `PostingDateResolution`, `RATE_MISSING_FOR_DATE` — that the caller must
explicitly accept before a second, unambiguous attempt (`03_DESIGN_PATTERNS.md` **P-17 Explicit
Resolution**). Every rejected attempt is recorded in `posting_attempts` with the violation code and
both the requested and resolved values, which is simultaneously a compliance artifact and the highest
quality AI-training signal available (`ODOO_BACKLOG.md` M16). Suggestions are welcome; substitutions
are not.

**Cost of the rejection** — an extra round trip on a common, benign workflow, and more UI work: every
resolution needs a human-readable explanation and an accept control. Bulk imports become two-phase for
any row needing adjustment. Users accustomed to systems that "just handle it" will experience QAYD as
pedantic, and in the AP-entry workflow specifically they will be right that the friction is real.

**How the rejection is enforced** — **convention plus absence**: there are no coercion code paths, and
`RATE_MISSING_FOR_DATE` has no fallback branch. That is weaker than it sounds — nothing prevents the
next author from adding one. The mechanism that would close it: a test suite asserting, for each
resolution-bearing operation, that a nearly-valid request is *rejected* and that the returned DTO
carries the suggestion; plus a review rule that any default value applied to submitted financial data
requires an ADR. *Enforcement debt: E-11.*

**Exceptions** — normalisation that does not change meaning: trimming whitespace, canonicalising a
currency code's case, parsing a date string into a date. The test: could a reasonable user disagree
with the transformation, or could it change a reported number? If yes, it is coercion. Presentation
rounding at render time is not coercion (`R-03`).

**Severity if violated** — **Catastrophic.** The stored data is wrong, it looks intentional, and the
evidence that it was not is gone.

---

## R-12 — Auto-balancing suspense lines

**What it is** — when an entry does not balance, inserting a plug line to a suspense, rounding, or
unallocated-earnings account so that it does. The user is never blocked; the imbalance lands
somewhere.

**Why it is tempting** — it is the humane answer to a genuinely hard workflow. During an
opening-balance import or a long manual entry, the document is unbalanced for most of its life;
blocking every intermediate state is intolerable, and blocking the *final* save when the trial balance
from the old system does not tie is worse — the user often cannot fix it, because the discrepancy came
from a system they no longer control. An auto-plug keeps the entry always-valid and makes repeated
partial imports idempotent. Odoo's version even lands the plug in a semantically defensible account
rather than a junk drawer.

**Why QAYD rejects it** — the mechanism is that **an imbalance is information, and a plug is the act
of deleting it.** Specifically:

1. **The one signal that something is wrong is consumed to make the symptom disappear.** Debits not
   equalling credits is the highest-quality error signal in double-entry accounting — it is what the
   method exists to produce. Absorbing it means the system has detected a defect and chosen to store
   the defect instead of reporting it.
2. **The plug is indistinguishable from a real posting afterwards.** Once written, the balancing line
   is an ordinary line in an ordinary account. Nothing marks it as "this is the amount we could not
   explain", so it cannot be aged, reviewed, or reconciled back to a cause.
3. **Magnitude is unbounded and unchecked.** A plug absorbs a one-fils rounding difference and a
   40,000 KWD missing account with equal composure. Odoo's implementation has *no check that the plug
   is small and no warning if it is enormous* — which, as Phase 1 notes, almost always means a botched
   import. The mechanism scales the harm with the size of the mistake.
4. **It converts a blocking error into a silent liability.** The import "succeeded". The balance sheet
   balances. Months later someone asks what is in unallocated earnings.

Transferable form: **never make a violated invariant true by adding data.** Making the check pass and
making the data correct are different operations, and any code that does the former to avoid the
latter is this pattern — regardless of how principled the account it writes to is.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R5 and `ODOO_TO_QAYD.md` §1.3, §2.5.
`ODOO_LEARNING.md:600`: when an entry does not balance, Odoo *may inject an automatic balancing line
rather than reject it*; the mechanism is at `:740` and `:2940`, emitting paired debit/credit balancing
commands from a running `open_balance`. `ODOO_LEARNING.md:3001` states the intent plainly — the plug
*"absorbs any imbalance, so the move is balanced by construction at all times"* and the user is never
blocked. Consequence 3 is verified at `:3030`: **no validation that the opening balance is meaningful
— no check that the plug is small, no warning if the balancing line is enormous.** The recommended
QAYD response is recorded at `:3055`: an unbalanced opening balance is *a data-quality signal, not
something to silently absorb*.

**What QAYD does instead** — zero-tolerance balance enforcement in both currencies, backed by an
unconditional `chk_je_balanced` `CHECK`, and an `UnbalancedEntryException` carrying the exact
difference as a bcmath string (`R-03`, `03_DESIGN_PATTERNS.md` **P-01 Posting**). For the legitimate
opening-balance case the residual is **surfaced in the DTO before posting** and the user must
explicitly acknowledge an opening suspense line, which is then a deliberate, attributed, reviewable
posting rather than an automatic one — the same accept-a-resolution shape as `R-11`
(**P-17**). Imports are modelled as `opening_balance_imports` + lines with their own residual
`CHECK`, then posted through `PostingService` in balanced chunks; more than one opening batch is
allowed, which Odoo's single-reference design cannot express.

**Cost of the rejection** — onboarding friction, at the worst possible moment: the customer's first
serious interaction with QAYD is importing a trial balance that may not tie, and we will refuse to
proceed until they decide what to do about it. Competitors will import it in one click. We need
genuinely good tooling — a clear residual display, per-account diffs, an AI-proposed mapping with the
unmapped accounts surfaced — to make that refusal feel like diligence rather than incapacity.

**How the rejection is enforced** — **database `CHECK` (strongest rung)** plus the absence of any
plug-generating code path. A regression test asserts that an unbalanced submission raises and that
`journal_lines` count equals exactly what the caller submitted — i.e. the system never adds a line the
caller did not send. That test is the real guard and it exists by design intent; it must ship with
`PostingService`'s suite. *Enforcement debt: E-12 (assert-no-added-lines test).*

**Exceptions** — the acknowledged opening-balance suspense line, which is not an exception to this
rule at all: it is user-initiated, named, sized, displayed before posting, and attributable. There is
no automatic variant, and no approval path to create one.

**Severity if violated** — **Catastrophic.** It launders a data-quality defect into a posted balance.

---

## R-13 — A second writer into the ledger (duplicated posting logic)

**What it is** — any code path that creates ledger facts without going through the single posting
service: a bulk importer that inserts rows directly "because it is faster", a module that builds and
posts its own journal entry inline, a migration that seeds balances, a fixture factory used in
production code.

**Why it is tempting** — the posting service is the most conservative, most validated, most expensive
path in the system, and sometimes you do not appear to need all of it. A 50,000-row import through a
service that validates, locks a sequence, and writes an audit row per entry is slow, and the direct
insert is a hundred times faster and obviously correct for this one shape of data. Subsystem authors
also legitimately own their domain — the inventory module knows what its valuation entry should look
like — so having it build the entry itself feels like good encapsulation.

**Why QAYD rejects it** — the mechanism is that **every guarantee attached to posting is a property of
the path, not of the data.** Balance validation, period locks, gapless numbering, the hash chain, the
audit row, the period rollup trigger, the append-only guarantee, the AI-source check — all of these
hold because there is exactly one place they are applied. A second writer does not weaken them by 50%;
it makes each one **conditional on which writer was used**, which means none of them can be relied on
without knowing the provenance of every row. And provenance is exactly what a second writer does not
record.

Three specific consequences:

1. **Numbering breaks quietly.** A gapless sequence is only gapless if all allocation goes through the
   allocator. One direct insert produces either a gap or a duplicate, and both are discovered by an
   auditor rather than by the system.
2. **Derived state detaches.** Incremental rollups and hash chains maintained by triggers on the
   canonical path silently stop describing reality — and because they are *aggregates*, the divergence
   is invisible until someone rebuilds.
3. **The second implementation ages differently.** When a posting rule changes, the primary path is
   updated and the copy is not, because nobody remembers it exists. The copy is usually the one used
   for bulk data, so the divergence applies to the largest row counts.

Transferable form: **if a guarantee is enforced at a chokepoint, a second entrance deletes the
guarantee** — not for the rows that use it, for *all* rows, because you can no longer tell which is
which.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R4 and `ODOO_TO_QAYD.md` §1.2. Odoo's inventory module
constructs an `account.move` and posts it inline, elevated (`ODOO_LEARNING.md:8415`, `:8703`), and the
study's verdict is explicit: *"Odoo's inline `sudo().create() + _post()` is exactly the pattern QAYD's
charter forbids"* (`:8754`). The consequences are observable rather than theoretical:
`ODOO_LEARNING.md:8729` records that **no lock-date enforcement is visible in that path** — the
posting method performs the check, but this writer creates and posts around it, and a stock user can
influence the accounting date. Meanwhile the reconciliation subsystem writes ledger rows by raw SQL
(`:1555`, `:3234` — see `R-14`), giving the same table at least three writers with different
guarantees. `ODOO_LEARNING.md:716` names the general mechanism precisely: balance is a context manager,
so *"anything writing `account_move_line` outside the wrapper is unchecked — and Odoo itself does
exactly that."*

**What QAYD does instead** — `01` P7: there is exactly one way into the ledger. Every subsystem —
invoicing, inventory valuation, payroll, depreciation, FX revaluation, opening balances, reversals —
builds a `JournalEntryDraft` DTO and hands it to `PostingService`, which is the only code that writes
`journal_lines` and the only producer of `ledger_entries`
(`03_DESIGN_PATTERNS.md` **P-01 Posting**, **P-02 Ledger Projection**). Bulk performance is solved by
batching *inside* the service — precommit-style chunking, one write per balanced chunk — not by
routing around it. Notably, Phase 1 found Odoo independently confirming this principle where it did
follow it: depreciation is posted as ordinary journal entries through the normal path
(`ODOO_LEARNING.md:10393` region), described as *"independent confirmation of the single-PostingService
principle"*.

**Cost of the rejection** — the posting service becomes a bottleneck for feature work: every subsystem
that needs a new kind of entry needs the service to support it, so one component is on the critical
path of many teams. Bulk import performance must be engineered deliberately rather than obtained by
shortcut. And the service will accumulate optionality — entry types, sources, strategies — that a
distributed set of writers would have kept local.

**How the rejection is enforced** — **should be table `GRANT`s (strongest rung)**: the application
role has no `INSERT` on `ledger_entries`, which is produced only by trigger from `journal_lines`, and
ideally a dedicated role owns the write. Today this is held by an architecture test on the dependency
graph — nothing outside `App\Domain\Accounting\Posting` may reference the ledger/journal tables — and
that test is not yet written. **Missing:** both the grants and the arch test.
*Enforcement debt: E-13.*

**Exceptions** — database migrations that create structure (not facts). Test factories, which must be
in the test namespace and unreachable from production code. A one-off historical data load is **not**
an exception: it is an opening-balance import, and it goes through the service.

**Severity if violated** — **Catastrophic.** Every ledger guarantee becomes conditional on provenance
that was never recorded.

---

## R-14 — Raw SQL writes bypassing the domain layer

**What it is** — issuing `UPDATE` / `INSERT` / `DELETE` against domain tables directly:
`DB::statement(...)`, `DB::update(...)`, a hand-written bulk `UPDATE … FROM (VALUES …)`, a
`DB::raw()` fragment that mutates. Usually for performance, usually on a path that touches many rows.

**Why it is tempting** — it is often the only reasonable way to do a bulk operation. Updating 100,000
rows through an ORM is genuinely absurd; one statement does it in milliseconds. The SQL is short,
explicit, reviewable, and does exactly what it says — arguably *more* transparent than the ORM
equivalent. And when the operation is "set this derived column on these rows", it seems to carry no
domain meaning at all.

**Why QAYD rejects it** — the mechanism is that **raw SQL is a writer that bypasses every guarantee
that is not in the database itself.** This is the sharpest possible test of `R-05`: whatever
application-layer validation, audit, event emission, or invariant checking exists, raw SQL skips all
of it, silently and by design. The consequences compound:

1. **Application-held invariants are simply not applied.** No balance check, no lifecycle rule, no
   permission resolution, no `posting_attempts` record. The row changes and nothing observes it.
2. **Derived and cached state goes stale with no signal.** Anything maintained by the application —
   a computed column, an in-memory cache, an event-sourced projection — is now describing a version of
   the data that no longer exists. The system does not fail; it disagrees with itself.
3. **The mutation is invisible to audit.** `audit_logs` is written by the Action layer. A raw write
   produces no audit row, so the change is unattributable forever.
4. **It teaches the codebase that the domain layer is optional.** Once a raw write exists on a
   financial table and is accepted in review, it is precedent, and the second one is easier.

Transferable form: **a write that skips the layer where the rules live is a rule violation waiting for
a value that violates the rules.** Note that the harm does not depend on the statement being *wrong* —
Odoo's raw updates are correct SQL. The harm is that correctness is now the author's responsibility
rather than the system's.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R4. `ODOO_LEARNING.md:1555`: full-reconcile creation issues
a raw `UPDATE account_move_line SET full_reconcile_id = …` and a second raw update for partials,
then **manually invalidates the ORM cache** — the manual invalidation is the admission that
consequence 2 is real. The same citation records that `unlink()` carries a comment explaining that
nulling the FK leaves `matching_number` stale *because nobody recomputes it*, so it must be fixed up
by hand — a **documented bug class arising directly from this pattern** (`:1624`).
`ODOO_LEARNING.md:3234` shows the same path bulk-writing `full_reconcile_id` onto **posted** lines.
Numbering does it too (`sequence_mixin` issuing `UPDATE account_move SET name = …`, `:716`), and the
opening-balance computation reads by raw SQL, *"bypassing the ORM and any record rules — a read-side
authorisation gap by construction"* (`:3028`). The operational hazard is documented as well:
`flush_model()` before raw SQL is mandatory and easy to get wrong — *"a structural hazard of mixing
ORM and raw SQL"* (`:3418`).

**What QAYD does instead** — writes go through Actions; ledger writes go through `PostingService`
(`R-13`). Bulk operations are expressed as batched writes *inside* the domain layer, or — where the
operation is genuinely a set-level data transformation — as a reviewed migration with an explicit
maintenance record. Where a derived column would have motivated a raw update, QAYD instead derives it:
matching state lives in side tables maintained by trigger, not as a column on the ledger rewritten
after the fact (`R-15`, `03_DESIGN_PATTERNS.md` **P-15 Read Model**).

**Cost of the rejection** — some bulk operations are markedly slower and require real engineering
(chunking, queue jobs, progress reporting) instead of one statement. Backfills become migrations with
review overhead rather than a console one-liner. During an incident, the fastest possible remediation
is off the table.

**How the rejection is enforced** — **database trigger for the ledger (strongest rung)**:
`ledger_entries` rejects `UPDATE`/`DELETE` regardless of who issues them, so raw SQL against the most
important table fails outright — this is a genuine mechanism, not a convention, and it is the reason
QAYD can make claims Odoo cannot. Beyond the ledger it is weaker: posted-state triggers cover posted
rows, but ordinary domain tables are protected only by review. **Missing:** an architecture test
banning `DB::statement|update|insert|delete` and mutating `DB::raw` outside `database/migrations`,
and table `GRANT`s narrowing write access by role. *Enforcement debt: E-14.*

**Exceptions** — (a) migrations, which are reviewed DDL/DML with a recorded history; (b) **read-only**
raw SQL, including complex analytical queries — reading is not this pattern and is often the right
tool; (c) a documented incident remediation, executed by the architecture owner, recorded in an
append-only maintenance log **in the same transaction** where possible. Approval for (c): architecture
owner, written before execution, never retrospectively.

**Severity if violated** — **Catastrophic.** Silent, unattributable mutation of financial data.

---

## R-15 — Derived / reconciliation state stored on the ledger row

**What it is** — putting a value on the ledger line that is a *function of other rows*:
`amount_residual`, `reconciled`, `matching_number`, `full_reconcile_id`, a `status`, a "cleared"
flag. The row then carries both what happened and what has since happened to it.

**Why it is tempting** — it is dramatically faster and simpler to query. "Show me open items" becomes
`WHERE reconciled = false` with an index, instead of a join and an aggregate against a matching table.
The value is conceptually *about* that line, so storing it there reads naturally. Every ORM makes it
trivial. And the alternative — deriving it — looks like it will be too slow.

**Why QAYD rejects it** — the mechanism is precise and it is the single most consequential entry in
this document: **storing derived state on a row makes that row mutable, and mutability is not a
property you can confine to one column.** Follow the chain:

1. The residual changes whenever a *different* row (a match, a payment) is created. So something must
   write the ledger row after posting.
2. Therefore the ledger table cannot be append-only.
3. Therefore the append-only trigger cannot exist — which means the immutability guarantee for
   *every* column is gone, not just the derived one. Nothing in SQL lets you say "this row is
   immutable except for these three columns" as cheaply as "this row is immutable".
4. Therefore the hash chain can go stale, the incremental period rollup is no longer monotonic and
   thus no longer trustworthy, and partitioning by `(company, period)` becomes unsafe.
5. And because updating the derived column through the ORM is too slow at scale, the implementation
   reaches for raw SQL (`R-14`), which then requires manual cache invalidation, which produces exactly
   the staleness bug the pattern was supposed to make fast.

That is not a slippery slope; it is a dependency chain, and Phase 1 traced every link of it in a
production system. **One column choice removes four unrelated capabilities.**

Transferable form: **a value derived from many rows belongs in a table keyed to those rows, not on
one of them.** The test: if creating some *other* record would change this field, the field is not
attributable to this record.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R3, which states the conclusion directly:
`amount_residual`, `reconciled`, and `full_reconcile_id` on the ledger line *"is the single decision
that forces Odoo's general ledger to be mutable — and thus its raw-SQL writes, its documented
staleness bug, and its inability to be append-only or partitioned."* The chain is verifiable link by
link: the fields are on the line (`ODOO_LEARNING.md:872`); posted lines are consequently mutated on
the reconciliation path and reconciliation state is *explicitly exempt* from Odoo's immutability
guarantee (`:3343`); the updates are raw SQL requiring hand cache-invalidation (`:1555`, `:3234`); and
the documented consequence is a stale `matching_number` bug (`:1624`). Link 4 is stated at
`ODOO_TO_QAYD.md` §1.2 — partitioning is *"impossible in Odoo because its ledger doubles as the
mutable invoice-line table"*. Meanwhile Odoo's *conceptual* model here is excellent and QAYD adopts it:
residuals derived from partial-reconcile rows, full-reconcile as a pure grouping label with no
amounts, cross-currency partials carrying three amounts (`ODOO_BACKLOG.md` H8). **The idea is right;
the placement is what is rejected.**

**What QAYD does instead** — `ledger_entries` carries only what was posted, plus `signed_base_amount`.
Matching state lives in side tables keyed to `ledger_entry_id`: `reconciliation_partials`,
`reconciliation_groups`, and a `ledger_entry_residuals` read model maintained by trigger and
rebuildable from the partials (`03_DESIGN_PATTERNS.md` **P-02 Ledger Projection**, **P-15 Read
Model**). Un-reconciling is an `INSERT` of a compensating link — never a `DELETE`, never a mutation —
so the full history of who un-allocated what survives (`ODOO_TO_QAYD.md` §3.2). Over-reconciliation is
a deferred constraint trigger asserting `SUM(matched) ≤ original`, not a Python re-read.

**Cost of the rejection** — open-item queries need a join to a residual table rather than a column
scan, and that table needs its own index strategy and its own rebuilder plus drift detector. The
trigger maintaining residuals is real machinery with real concurrency considerations (ordered
`FOR UPDATE` acquisition to avoid deadlock). We are choosing a moderate, permanent query cost to buy an
absolute immutability guarantee.

**How the rejection is enforced** — **design-time only today.** The schema simply does not have these
columns, and the append-only trigger on `ledger_entries` means adding one would be actively
counterproductive — any code trying to maintain it would fail. That is a decent structural
disincentive but not a check. **Missing:** a schema-shape test asserting the exact column set of
`ledger_entries`, failing if a derived column is added. *Enforcement debt: E-15.*

**Exceptions** — none on `ledger_entries`. Derived columns on *mutable* operational tables (a
denormalised counter on a draft document) are ordinary caching and are not this pattern, provided the
table is not append-only and the value is rebuildable.

**Severity if violated** — **Catastrophic.** It is the root decision from which four other rejected
patterns follow as consequences.

---

## R-16 — JSONB accounting dimensions; foreign keys as delimited strings

**What it is** — storing structured, referential data inside a JSON column: analytic allocations as
`{"14,16": 60}`, dimension assignments as a JSON map, a set of related ids as a comma-joined string
key. The column holds relationships the database does not know are relationships.

**Why it is tempting** — it is enormously flexible. A new dimension is a new key, not a migration. The
whole allocation for a line is one column, so it is read and written atomically, it round-trips to the
API unchanged, and it avoids a child table with its own lifecycle. For an authoring UI that edits a
distribution as a single object, the storage shape matches the interaction shape exactly.

**Why QAYD rejects it** — the mechanism is that **JSON is opaque to the relational engine, so every
guarantee and every operation the engine would have provided must be re-implemented in application
code — badly.** Four specific losses, each independently disqualifying:

1. **No referential integrity.** An id inside a JSON key is a string, not a foreign key. Delete the
   referenced record and the JSON keeps pointing at it; nothing catches it. The codebase then fills
   with existence checks at every read site, which is the application re-implementing a foreign key
   without the guarantee.
2. **No expressible invariants.** "This distribution sums to 100%" cannot be a `CHECK` over a JSON
   map. So the rule migrates to application code, which makes it optional (`R-05`), and in practice
   context-gated (`R-06`).
3. **Aggregation is lost — and this is the fatal one.** You cannot `SUM(amount) GROUP BY dimension`
   when the dimension is a JSON key: the grouping requires rewriting the query into a text-and-regex
   subquery, and money aggregates over it are not expressible at all. **The subsystem's primary
   analytical question cannot be asked of its primary storage.**
4. **Indexing fights the storage.** Making it queryable requires a GIN index over a regex-split of a
   JSON path cast to text — which works, and is an admission that the shape is wrong.

Then the tell: **the system ends up materialising the JSON into rows anyway**, and maintaining
two-way sync between them (`R-17`). At that point the row table is doing the real work and the JSON is
an authoring convenience layered on top — so keep the rows and drop the layer.

Transferable form: **JSON is a transport format, never a storage format for anything the ledger
depends on.** If a value inside a document needs to be joined, aggregated, constrained, or referenced,
it is a column in a row.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R10 and H1, and `ODOO_TO_QAYD.md` §7.4. Loss 1:
keys are comma-joined id strings with no referential integrity, *"hence `.exists()` guards scattered
through the codebase, and a deleted analytic account leaving danglers no constraint catches"*
(`ODOO_LEARNING.md:10481` item 2). Loss 2: the 100% rule is opt-in **and explicitly disabled by
production code** for exchange-difference moves (item 5). Loss 3, verbatim: *"You cannot SUM money
grouped by distribution… the primary analytical query of the subsystem is not expressible against its
primary storage"* (item 4). Loss 4: *"the index is a regex over a JSON path cast to text — correct, and
an admission that the storage shape fights the query shape"* (item 3). The decisive evidence is
`ODOO_LEARNING.md:8228`: Odoo **already materialises the JSONB into `account.analytic.line` rows and
maintains two-way sync**, with recursion suppressed by skip-flags in six places, and changing a
distribution unlinks and recreates every analytic line. It pays the row cost anyway.

**What QAYD does instead** — allocations are **rows** in a `journal_line_dimensions` child table, with
a composite foreign key `(member_id, dimension_id)` so "this member belongs to the declared dimension"
is a database guarantee, and a `DEFERRABLE INITIALLY DEFERRED` constraint trigger for the 100% and
amount invariants (`03_DESIGN_PATTERNS.md` **P-16 Dimension Rows**). Adding a dimension is one
`INSERT`, not a migration and not runtime DDL (`R-18`). `SUM(amount) GROUP BY member` is a plain
indexed aggregate. JSON is accepted at the **API boundary** for ergonomics and in the AI's
`proposed_payload` — normalised to rows before anything reaches `journal_lines`.

**Cost of the rejection** — writing a distribution is several rows instead of one column update, so
the write path needs a small amount of diff logic, and reading a line's full allocation needs a join.
The API must translate between the JSON shape clients want and the row shape we store. This is
Phase 1's most time-sensitive recommendation: **free to decide today, roughly 13 points of rework plus
a migration on the largest table in the system if deferred.**

**How the rejection is enforced** — **decided and specified; schema not yet built.** Enforcement will
be structural (the columns will not exist) plus the composite FK and constraint trigger. **Missing:**
a review rule that any new `jsonb` column on a financial table requires an ADR stating why it is not
rows, and Phase 1's suggested regression guard — a golden fixture asserting **no runtime DDL** on
dimension creation. *Enforcement debt: E-16.*

**Exceptions** — JSON is correct for genuinely schemaless payloads that nothing joins on: an audit
diff (self-describing, so it survives schema evolution), an AI proposal payload awaiting validation, a
webhook body, an external API response kept for provenance. The test: does anything ever need to
`JOIN`, `GROUP BY`, or `FOREIGN KEY` a value inside it? If yes, rows.

**Severity if violated** — **Serious**, escalating to Catastrophic once reporting depends on it, since
by then the fix is a migration of the largest table plus a rewrite of every query against it.

---

## R-17 — Two-way sync between two stores of the same truth

**What it is** — keeping two representations of one fact aligned by writing both: a JSON column and a
materialised child table, a denormalised total and its source rows, a cache and its origin — where
editing *either* side updates the other, usually with a recursion-suppression flag to stop the
updates ping-ponging.

**Why it is tempting** — each representation is genuinely better for something. The JSON is better for
authoring and API round-trips; the rows are better for querying and aggregation. Rather than choose
and pay the conversion cost, you keep both and synchronise. It also arrives incrementally and
defensibly: the second representation is added later, for a real need, and syncing is the smallest
change that makes it work.

**Why QAYD rejects it** — the mechanism is that **two writable representations of one fact are two
sources of truth, and there is no way to decide which is right when they differ.** Not "unlikely to
differ" — *undecidable when they do*, which is a different and worse property. Specifically:

1. **The invariant "these agree" is unenforceable.** It spans two shapes, so no `CHECK` can express
   it; the only enforcement is that both writers are correct, forever, on every path.
2. **The recursion guard is the confession.** The moment you need a flag to stop A's write triggering
   B's write triggering A's write, you have built a control loop whose correctness depends on a flag
   being set in every place that matters. Each such flag is an instance of `R-06`, and they multiply.
3. **Write amplification and destructive rewrites.** Keeping the sides aligned typically means
   discarding and recreating one side wholesale on every edit — which destroys identity, breaks
   anything referencing the recreated rows, and turns a small edit into a large write.
4. **Reconciliation is impossible after the fact.** When they diverge, there is no third artifact to
   adjudicate. Recovery is a human deciding which store looks more plausible.

Transferable form: **one writable source; everything else derived and rebuildable.** A second
representation is legitimate only when it is *strictly downstream* — never written directly, always
reproducible from the source by a deterministic rebuild, and continuously checked against it.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R12. `ODOO_LEARNING.md:8228`: the JSONB
`analytic_distribution` is expanded into child rows on post, and **editing a child row writes the
JSONB back**, with recursion suppressed by `skip_analytic_sync` context flags **in six places**;
changing a distribution *"unlinks and recreates every analytic line"* — consequences 2 and 3 in one
citation. `ODOO_LEARNING.md:10481` item 6 states the diagnosis exactly: *"Two sources of truth kept
aligned by convention."* The same study shows the healthy alternative failing for the same reason
elsewhere: `analytic_profitability` is **implemented twice**, in Python and in SQL, *"with paired
comments begging maintainers to keep them aligned"* (item 13) — duplication of logic rather than data,
identical mechanism.

**What QAYD does instead** — pick the storage that supports the invariants and the queries — rows
(`R-16`) — and treat every other shape as a projection. Read models are derived, carry a rebuilder,
and ship a drift detector run in CI and on a schedule (`03_DESIGN_PATTERNS.md` **P-15 Read Model**;
`01` P19). The API's JSON shape is produced on read and consumed on write by a translator; it is never
stored as a second truth. Where a rollup is maintained incrementally (`H2` period balances), it is
safe **only because** its source is append-only, making the trigger monotonic — and it still ships
`RebuildPeriodBalancesAction` as a drift detector.

**Cost of the rejection** — a conversion layer on every boundary, and rebuilders plus drift checks for
every projection, which is real code that produces no visible feature. Occasionally a projection is
expensive to rebuild and we still must be able to.

**How the rejection is enforced** — **convention plus the design rule that every projection has a
rebuilder** (`01` P19). **Missing:** a CI drift check per projection (each read model asserts
rebuild-equals-stored on a sample), and a review rule that any `sync*()` method between two tables
requires an ADR. *Enforcement debt: E-17.*

**Exceptions** — none for two *writable* stores. Derived caches with a single writer, a rebuilder, and
a drift detector are not this pattern — that is `P-15`.

**Severity if violated** — **Serious.** Divergence is silent and, once it happens, unadjudicable.

---

## R-18 — Runtime DDL driven by user data

**What it is** — user actions causing schema changes: creating a "custom dimension" adds a column,
renaming it renames the column, adding a custom field runs `ALTER TABLE` and `CREATE INDEX` against
production.

**Why it is tempting** — it gives customers real extensibility with real database performance. A
user-defined dimension stored as a proper column is indexable, joinable, and fast — everything the
JSON alternative (`R-16`) is not. It also makes the ORM's job easy: the field exists, so all the
normal machinery works on it.

**Why QAYD rejects it** — the mechanism is that **the schema stops being a shared, reviewable,
versioned artifact and becomes a per-tenant runtime accident.** Consequences, in the order they hurt:

1. **No two tenants have the same schema**, so no migration is portable. Every subsequent migration
   must be written defensively against an unknown column set, and "does this migration work?" can only
   be answered per customer, in production.
2. **A schema review cannot enumerate the columns.** Neither can a code reader, a type generator, an
   ORM model, or a security audit. The most basic question about a system — what does this table
   contain? — has no answer outside a live database.
3. **DDL takes heavy locks.** `ALTER TABLE` on a large table blocks; issuing it in response to a user
   clicking Save means a user action can stall the system, and can deadlock against ordinary
   transactions. Zero-downtime deploys become impossible to reason about because deploys are no longer
   the only source of DDL.
4. **Naming leaks internals and encodes ordering.** Columns end up named after the primary key of the
   record that created them, so schema shape is a function of insertion order — and hierarchy gets
   encoded in field-name suffixes, which is `R-23` in another costume.

Transferable form: **structure changes are code; data changes are data.** Anything a user can do at
runtime must alter rows, never the shape of the table. If a feature seems to require user-driven DDL,
the model is wrong: the varying thing is data and belongs in rows.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R9, which calls it *"disqualifying on its own for a
migration-driven system"*. `ODOO_LEARNING.md:10218` and `:10232`: creating or **renaming** an analytic
plan iterates every inheriting model and executes `ALTER TABLE` + `CREATE INDEX` against production —
*"a user creating an analytic plan issues `ALTER TABLE` and `CREATE INDEX` against production."*
Consequence 4 verbatim: columns are named `x_plan{id}_id`, so *"schema shape becomes a function of
insertion order, so no two tenants have the same schema and no migration is portable"*
(`:10063`); sub-plan hierarchy is expressed by a related path assembled by **string multiplication**
and field names suffixed by depth, with the plan id later recovered by regex out of the field name
(`:10232`, `:10234`). Consequence 3 has independent support in the same study:
`ODOO_LEARNING.md:7306` documents a real deadlock where an in-transaction write blocked on an
`ALTER TABLE`, with the general lesson that *"logging that participates in the main transaction can
deadlock the main transaction"*.

**What QAYD does instead** — dimensions, custom classifications, and user-defined attributes are
**rows** with real foreign keys (`03_DESIGN_PATTERNS.md` **P-16 Dimension Rows**). Adding a dimension
is one `INSERT`; adding a member is one `INSERT`. All DDL lives in reviewed Laravel migrations,
version-controlled and identical across tenants. Configuration that must vary per tenant without a
release is expressed as typed rows compiled through an allowlist (**P-19 Declared Config**), never as
generated schema and never as evaluated code (`R-19`).

**Cost of the rejection** — genuinely user-defined *structures* (as opposed to values) require a
release. A customer who wants a new kind of attribute with first-class query performance gets rows
with a composite index, which is slightly slower than a dedicated column would have been. We give up
"infinitely extensible without a deploy" as a marketing claim.

**How the rejection is enforced** — **convention today.** The mechanism: an architecture test banning
`Schema::`, `ALTER TABLE`, `CREATE INDEX`, and `DROP` outside `database/migrations/`, plus Phase 1's
suggested golden fixture asserting that creating a dimension performs no DDL. Additionally the runtime
database role should lack DDL privileges entirely, which turns this from a test into a grant — the
strongest available form. *Enforcement debt: E-18.*

**Exceptions** — migrations. Partition creation for time-partitioned tables, executed by a scheduled
maintenance job with a fixed, code-defined naming scheme, is not user-driven and is permitted.
Approval for anything else: architecture owner, via ADR.

**Severity if violated** — **Serious**, and effectively irreversible: once tenants have divergent
schemas, converging them is a bespoke migration per customer.

---

## R-19 — Security logic stored as evaluated code

**What it is** — persisting a rule as a string that is later executed or interpreted to make a
security decision: a text column holding an expression, a stored predicate run through an evaluator, a
policy assembled by string interpolation and executed as SQL.

**Why it is tempting** — it makes the authorization model configurable without a release, which is a
genuine enterprise requirement. Each tenant, role, or deployment can have rules the product team never
anticipated, and support can fix an access problem by editing a row. The expression language is
usually restricted and reviewed, which makes it feel bounded.

**Why QAYD rejects it** — the mechanism is that **it collapses the boundary between data and code
precisely at the point where the system decides who may see what.** Four consequences:

1. **Write access to the rule table becomes write access to the security model.** Anyone — or
   anything — that can write that row controls the predicate applied to every read of the protected
   resource. A row is a much softer target than a deploy: no code review, no CI, often no audit.
2. **The rule cannot be reviewed by the tools that review code.** It is invisible to static analysis,
   diffing, tests, and the type system. It changes without a commit.
3. **The evaluator is an ongoing injection surface.** "Restricted" evaluators are restricted until
   someone finds the composition that is not, and the failure mode is arbitrary evaluation on the
   request path.
4. **Two evaluators must agree forever.** These languages are typically compiled *both* to SQL (for
   filtering reads) and to an in-memory predicate (for checking writes). Every operator, for every
   type, must be implemented twice and behave identically — a permanent correctness tax landing
   squarely on the security path.

Transferable form: **never store something that will be executed.** Store *parameters* that a fixed,
reviewed, tested piece of code consumes. The distinction is not stylistic: parameters have a domain
you can constrain, and code does not.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R15. `ODOO_LEARNING.md:11606`: `ir.rule.domain_force` is a
**TEXT column holding Python**, evaluated on the request path, and *"the only DB-level constraint in
the entire security model"* is a check that a rule grants at least one mode. So consequence 1 is
literal: anyone who can write `ir.rule` controls the security predicate of every model. Consequence 4
is documented at `ODOO_LEARNING.md:11450`: the domain language compiles to SQL **and** to a Python
predicate, *"every operator, for every field type, must be implemented twice and agree… a permanent
correctness tax (and it lands squarely on the security path)"* — with the practical result
(`ODOO_LEARNING.md:11628`, `ODOO_BACKLOG.md` R2) that **writes and deletes are filtered by an in-memory
predicate over hydrated rows** while reads are filtered in SQL, and creates are checked *after* the
`INSERT`.

**What QAYD does instead** — authorization is **DDL**: RESTRICTIVE company-boundary policies and
PERMISSIVE scope policies written in reviewed migrations, enforced by PostgreSQL against a
`NOBYPASSRLS` role (`03_DESIGN_PATTERNS.md` **P-09 RLS**). Application-side scopes are typed PHP.
Where per-tenant variation is genuinely required, it is expressed as **data consumed by a fixed
policy** — a permission row, a scope enum, a grant — never as a stored expression and never as
generated DDL. The related good idea from Phase 1 — predicates as portable data (`H12`) — is adopted
in the **only** safe form: a closed, `CHECK`-constrained JSONB selector compiled by an **allowlist**
to bound parameters, with no `eval` and no string interpolation, reused across report expressions,
matching rules, and dimension rules (**P-19 Declared Config**).

**Cost of the rejection** — a genuinely novel access rule requires a migration and a release rather
than a support-team row edit. Per-tenant authorization flexibility is bounded by what the fixed policy
vocabulary can express, and extending that vocabulary is engineering work.

**How the rejection is enforced** — **structural**: policies are DDL and there is no evaluator to
abuse. **Missing:** an architecture test banning `eval`, `create_function`, dynamic `Blade::render` of
stored strings, `unserialize` of stored input, and any SQL assembled from a database-sourced string;
plus a schema rule that no column may store an expression. *Enforcement debt: E-19.*

**Exceptions** — the allowlist-compiled selector described above, which is not evaluated code: it is a
constrained data structure interpreted by a fixed compiler that can only emit bound parameters against
an allowlisted column set. Any change to that compiler is a security review. No other exception.

**Severity if violated** — **Catastrophic.** It is remote code execution reachable from a table.

---

## R-20 — Magic side effects (ORM hooks, observers, implicit computed cascades)

**What it is** — behaviour triggered implicitly by persistence rather than called explicitly: Eloquent
observers, `booted()` model events, `saving`/`saved` hooks, accessors that write, computed fields that
cascade recomputation into other records.

**Why it is tempting** — it guarantees the behaviour happens. If audit logging is in a `saved()` hook,
nobody can forget it. If a total recomputes on save, it is never stale. It also keeps calling code
clean: `$model->save()` and everything downstream just works. For cross-cutting concerns this is
genuinely the DRY-est expression available.

**Why QAYD rejects it** — the mechanism is **invisible control flow**: the code that runs is not
mentioned at the place it runs from. Consequences:

1. **The operation's true extent is unknowable from the call site.** `$model->save()` may write one
   row or fifty, dispatch jobs, call an API, and recurse. Reviewing the calling code tells you
   nothing, and neither does reading the model — you must know every observer registered anywhere.
2. **Ordering is emergent and fragile.** With several hooks and cascades, execution order depends on
   registration order, dependency declarations, and framework internals. Nothing declares it, so
   nothing protects it, and it changes when an unrelated module is added.
3. **Dependencies are hand-declared and silently wrong.** A computed value that reads a field it did
   not declare is simply never recomputed. There is no error — just a value that is quietly stale
   forever.
4. **Tests and bulk paths diverge from production.** Bulk operations, raw queries, and mass updates
   skip hooks entirely, so the "guaranteed" behaviour is guaranteed only on the ORM path — the same
   shape as `R-13`, arrived at from the other direction.

Transferable form: **behaviour that matters must be named at the point it happens.** Implicit
triggering trades one forgettable explicit call for an unbounded set of invisible ones — and in a
financial system, "what exactly happened when I saved this?" must have a readable answer.

**Evidence** — Phase 1's ORM analysis, `ODOO_LEARNING.md:11430`. Consequence 1, verbatim: *"Attribute
access performs I/O and runs business logic… reading a property is therefore never side-effect-free."*
Consequence 3 is documented in detail (`:11430` item 5): dependency names are hand-written, so *"a
compute that reads an undeclared field is **never re-triggered**"*; dependency inversion **skips**
certain filtered relations; and any direct SQL write bypasses invalidation entirely — with exposure
quantified at **133 stored computed fields in `addons/account` alone**. Consequence 2 appears as a
fixpoint loop with a bounded iteration count that, when exhausted, *logs a warning and leaves data
unflushed* (`:11430` item 2 — which is also `R-30`). And the compounding case: numbering allocated as
a computed-field side effect (`R-21`).

**What QAYD does instead** — orchestration is explicit inside Actions. Audit rows, events, and
projections are written by named steps in a named sequence, not by hooks. Cross-module reactions use
**after-commit domain events** with a transactional outbox (`03_DESIGN_PATTERNS.md` **P-11 Event**),
which are explicit, ordered, testable, and observable. Where a guarantee must hold regardless of the
caller — the ledger projection, the audit chain, the append-only rule — it is a **database trigger**,
not an application hook: same "cannot be forgotten" property, but enforced on every writer including
raw SQL, and visible in the schema rather than scattered across service providers.

**Cost of the rejection** — more code per operation, and the explicit call *can* be forgotten by a new
Action. We accept it because the trigger-or-explicit split puts the truly non-negotiable things in the
database (where they are stronger than hooks) and the rest in code (where they are readable).

**How the rejection is enforced** — **convention.** The mechanism: an architecture test asserting no
Eloquent observers are registered and no model defines `booted()` event handlers or write-performing
accessors — which pairs naturally with `R-01`'s model-surface test. *Enforcement debt: E-20.*

**Exceptions** — framework-level concerns with no domain meaning: timestamps, soft-delete scoping,
UUID/key generation for non-financial entities, and cast registration. Database triggers are not an
exception to this rule; they are the sanctioned alternative, and each one is reviewed as schema.

**Severity if violated** — **Serious.** Correct on the day it ships; unpredictable as the system grows.

---

## R-21 — Numbering allocated as an ORM side effect

**What it is** — the document number appearing as a consequence of some other write: a computed field
that fires when `status` changes to posted, an observer that stamps a reference on save. Nothing in
the posting code mentions numbering; the number is simply there afterwards.

**Why it is tempting** — it cannot be forgotten. Any path that posts gets a number, including paths
the author of the numbering never saw. It also keeps the posting method focused on posting.

**Why QAYD rejects it** — a specialisation of `R-20`, singled out because numbering carries **legal**
weight in GCC audit and because the failure modes are unusually nasty:

1. **Allocation order becomes an artifact of the ORM's flush strategy** rather than a decision. Which
   transaction gets which number depends on when dirty state is written — so the concurrency behaviour
   of a legally significant sequence is determined by framework internals.
2. **Callers cannot rely on the number existing** immediately after the operation that logically
   produced it; they must force a flush first. That is a leaky, easily-forgotten contract on the value
   an invoice is identified by.
3. **It cannot be tested in isolation.** Testing gapless allocation under concurrency requires
   constructing full documents and driving them through posting, so the property that matters most
   gets the weakest test coverage.
4. **Gaplessness cannot be reasoned about.** When allocation is implicit, the mechanism achieving
   uniqueness is buried, and the trade-off it makes — Odoo's provokes a b-tree lock and consequently
   **skips numbers** — is invisible to anyone reading the posting path.

Transferable form: **a value with legal or external meaning must be produced by a named step, not
observed as a consequence.** If you cannot point to the line that allocates it, you cannot state its
guarantees.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R25. `ODOO_LEARNING.md:1049`: numbering *"does not happen in
that method at all"* — it is triggered as an ORM side effect of the `state` write, and cross-transaction
uniqueness comes from **deliberately provoking a PostgreSQL unique-index b-tree lock**, a mechanism
that *"by construction skips numbers"*; gaplessness is *"not guaranteed; it is merely detected and
discouraged"*. Consequence 2 verbatim (`:212`): *"`write()` must `flush_recordset()` before it can rely
on the name existing."* One thing worth copying appears here too: Odoo's `init()` self-audit queries
`pg_index` at boot and warns that a missing unique index *"will cause duplicated sequences under heavy
load"* (`:1354`) — Phase 1 calls this exemplary and recommends QAYD's analogue be **fatal**, not a
warning (`:1532`).

**What QAYD does instead** — `AllocateJournalNumberAction` is an explicit, named step inside
`PostJournalEntryAction`, taking and returning a `SequenceAllocation` DTO, with its own test suite that
never constructs an invoice (`03_DESIGN_PATTERNS.md` **P-07 Number Allocation**). Allocation is gapless
per `(company, fiscal_year, entry_type)` via an atomic upsert-increment that locks only the sequence
row — which is also the *only* thing that needs serialising on the posting path (`H3`, **P-06
Concurrency**). Concurrency tests are a first-class deliverable, including the case where a random
subset of transactions rolls back after allocation and the surviving numbers must still be contiguous.
A boot-time assertion that the backstop unique index exists is **fatal**.

**Cost of the rejection** — a new posting-adjacent path must remember to call the allocator, and
gaplessness costs a serialisation point on the sequence row, which caps concurrent posting throughput
per scope. Odoo's approach is faster and, for jurisdictions that tolerate gaps, entirely reasonable.

**How the rejection is enforced** — **structural**: allocation exists only as an Action invoked by
`PostingService`, and there is no hook that could assign a number. Backed by the boot-time index
assertion and `VerifyNumberSequenceAction` (no gaps, no duplicates per scope) run in CI and on a
schedule — a check that should never find anything. ✅ Held.

**Exceptions** — non-financial, non-legal identifiers (a support ticket reference, a slug) may be
generated wherever is convenient. Anything an auditor could infer completeness from may not.

**Severity if violated** — **Serious**, escalating to Catastrophic in a jurisdiction that requires
gapless numbering, where a gap is a finding.

---

## R-22 — Presentation rows inside financial tables

**What it is** — storing display-only artifacts as rows in the ledger or journal-line table: section
headers, subtotal captions, notes, page breaks — discriminated by a `display_type`-style column and
excluded from every financial query by convention.

**Why it is tempting** — an invoice is a *document*, and its lines and its headings interleave in a
user-defined order. Keeping them in one table preserves ordering trivially, makes the editor a single
list, and avoids a merge-and-sort on render. It is by far the simplest way to model a WYSIWYG document
editor over financial lines.

**Why QAYD rejects it** — the mechanism is that **a row that is not a financial fact forces every
financial constraint on that table to become conditional**, and a conditional constraint is a
constraint with a hole in it:

1. **`NOT NULL` becomes impossible on the columns that matter.** A section header has no account, so
   `account_id` must be nullable — which means the database can no longer guarantee that a *real*
   ledger line has an account. The presentation row has permanently weakened the schema for every
   real row.
2. **Every `CHECK` acquires an escape clause.** "Debit or credit must be zero" becomes "…unless this
   is a presentation row", so a bug that produces the wrong discriminator produces a row that passes
   every check while satisfying none of them.
3. **Every query must remember the exclusion.** Aggregates, balance computations, exports, and
   reconciliation must all filter the discriminator. Forgetting it is a silent wrong number, and there
   are hundreds of query sites over a system's life.
4. **The mixing spreads.** Once the table holds non-financial rows, it becomes the natural home for
   the next non-financial thing.

Transferable form: **a table whose rows are guaranteed to satisfy an invariant must contain only rows
of that kind.** Discriminating within a table trades one join for an unbounded number of conditional
invariants — and the invariants are the reason the table exists.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R18 and `ODOO_TO_QAYD.md` §1.1. `ODOO_LEARNING.md:214` states
it exactly: `display_type IN ('line_section','line_subsection','line_note')` *"forces `account_id` to
be nullable and makes every accounting CHECK conditional"*. `ODOO_LEARNING.md:645` lists the four
`CHECK` constraints on the line table and **all four are conditional on `display_type`** — including
`account_id IS NOT NULL`. The consequence is drawn at `:903`: *"Invoice cosmetics have permanently
weakened the ledger schema."* Consequence 3 has a citation too (`:675`): the posting method itself must
filter section rows out to decide whether the entry has any real lines. Phase 1's counter-position
(`:977`, `:1030`) is that keeping cosmetics out is precisely what lets QAYD assert *unconditionally* —
a raw-SQL insert of a line with `account_id IS NULL` fails, **with no escape**.

**What QAYD does instead** — `journal_lines` contains only financial lines, with `account_id NOT NULL`
and unconditional one-sided/sign checks. Document cosmetics live in `document_presentation_lines`, RLS
scoped, ordered independently, joined only at render time. The commercial document (invoice/bill) is
its own table linked to the journal entry, so product lines, discounts, and layout never touch the
ledger (`03_DESIGN_PATTERNS.md` **P-02 Ledger Projection**).

**Cost of the rejection** — rendering an invoice requires merging two ordered sets, and the editor must
write to two tables in one transaction, keeping a shared ordering key consistent. That is genuine
complexity in the document UI, paid so that the ledger schema can be absolute.

**How the rejection is enforced** — **`account_id NOT NULL` plus unconditional `CHECK`s (strongest
rung)**: a presentation row literally cannot be inserted into `journal_lines`. ✅ Held by the schema.

**Exceptions** — none. A line without an account is not a journal line.

**Severity if violated** — **Serious**, because it does not corrupt anything by itself — it removes the
guarantees that would have caught the corruption.

---

## R-23 — Hierarchy derived from string parsing

**What it is** — inferring structure from the characters of an identifier: the parent of account
`400100` is `4001` is `40` is `4`; grouping by `LEFT(code, 2)`; matching children with
`LIKE 'code%'`; recovering an id from a field name by regex.

**Why it is tempting** — accounting charts really are designed this way. Codes are hierarchical by
convention, so the information is genuinely present in the string, and deriving it requires no schema,
no maintenance, and no risk of the tree disagreeing with the codes. Reparenting is free: change the
code, the hierarchy follows.

**Why QAYD rejects it** — the mechanism is that **an encoding convention is not a data structure**:

1. **It is not joinable.** A derived parent has no row and no key, so it cannot be a foreign key
   target, cannot carry attributes, and cannot be `JOIN`ed. Grouping a report by hierarchy becomes
   string manipulation in the query instead of a join, which is slower, unindexable in the general
   case, and unreviewable.
2. **The convention is unenforceable.** Nothing stops a customer creating a code that does not fit —
   a different digit width, an alphanumeric segment, a legacy code from a migration. When they do,
   the hierarchy silently mis-groups rather than failing, and a report is quietly wrong.
3. **Renaming becomes restructuring.** Editing a code moves the account in the tree as a side effect,
   with no confirmation and no audit of the structural change.
4. **The parsing rule metastasises.** The slicing logic appears in queries, exports, and UI, each
   with its own assumption about segment widths.

Transferable form: **if you are reading structure out of a string, the structure should have been a
column.** Identifiers should identify; relationships should be modelled.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R19. `ODOO_LEARNING.md:229`: `account_account.py` is 1,659
lines with **no `parent_id` anywhere**. `ODOO_LEARNING.md:285` and `:319`: the account root is computed
as `SUBSTRING(placeholder_code, 1, 2)`, and the whole `account.root` model is a non-stored pseudo-model
whose search accepts only two exact domain shapes and raises for anything else — so consequence 1 is
literal, the root is **not joinable** (`:456`). Consequence 4 appears in the dimension subsystem too,
where a plan id is recovered from a field name by regex (`:10234`).

**What QAYD does instead** — `accounts` has a real `parent_id` with a same-company composite FK
guarantee, a materialised path with a GiST index for subtree queries, and `UNIQUE (company_id, code)`
made simple by RLS. Grouping a report by hierarchy is a join. The code remains a human-facing
identifier with no structural meaning.

**Cost of the rejection** — the tree must be maintained: reparenting is an explicit operation, and
importing a legacy chart requires inferring parentage once, at import, with the ambiguous cases
surfaced to a human (a good AI-assist task). We also lose "renaming reorganises", which some
accountants expect.

**How the rejection is enforced** — **the schema has a real `parent_id`**, so the correct path is the
easy one. Remaining exposure is a developer writing `LIKE 'code%'` in a query; that is a review catch.
✅ Structurally held; review-level for query sites.

**Exceptions** — parsing an external system's codes **at import time**, to propose parentage that is
then stored as rows. Displaying a code with visual segmentation is presentation, not structure.

**Severity if violated** — **Untidy** — the harm is wrong groupings in derived, rebuildable output, and
it is visible and fixable. **Escalates to Serious** the moment a filed statement is grouped this way,
because then a mis-parse is a misstatement.

---

## R-24 — Amount equality used as identity

**What it is** — deciding *which* record something refers to by comparing amounts: matching a payment
to an invoice because the totals are equal, attributing a state change to whichever candidate has the
same value, deduplicating by amount and date.

**Why it is tempting** — it works in testing and in most of production, because amounts are quite
distinctive. It also solves a real problem cheaply: when the explicit link was never recorded, amount
matching is the only signal left, and it is right most of the time.

**Why QAYD rejects it** — the mechanism is that **an amount is an attribute, not an identifier, and
attributes are not unique**. The failure has a specific and cruel shape:

1. **It fails exactly where money is most concentrated.** Identical amounts are not random noise —
   they are *systematic*: recurring subscriptions, standard fees, round-number transfers, split
   payments, the same invoice paid twice. The pattern breaks most often for the customer's most
   routine, highest-volume transactions.
2. **The wrong attribution is silent and plausible.** Both candidates are real; one is marked paid and
   the other is not. Nothing looks wrong — the totals still tie. It surfaces weeks later as a customer
   dispute, and by then the audit trail records a confident, incorrect link.
3. **It hides that the real information was discarded.** The link is almost always *knowable* at the
   moment the operation happens — the user chose an invoice, the file carried a reference. Amount
   matching is what you do after throwing that away, usually because it was computed for the UI and
   never persisted.
4. **The heuristic accretes tie-breakers.** Date, then partner, then reference-similarity — each
   narrowing the failure without eliminating it, and each adding an unreviewable rule.

Transferable form: **identity comes from an explicitly recorded relationship, never from equality of a
value.** If you are searching for a record by amount, ask why the link was not stored — that is the
actual bug.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R22 and `ODOO_TO_QAYD.md` §3.5. `ODOO_LEARNING.md:4203`: the
group-payment accumulator dedupes *"by amount equality against an invoice total, which misfires
silently when two invoices in the group have identical totals"* — and, being transaction-scoped, also
fails when the covering partials are created across two transactions. Consequence 3 is the notable
one: Odoo **computes allocation intent for the UI and then discards it**, so *"which installment did
this payment target?"* is a question the system cannot answer at all (`ODOO_TO_QAYD.md` §3.5). Phase 1's
prescription (`ODOO_LEARNING.md:4240`): record `payment_id` on every allocation, at which point
coverage is *"a query, not a per-transaction accumulator"*, removing the class entirely.

**What QAYD does instead** — every allocation records explicit identity: `payment_id`, and allocation
**intent** (`target_kind`, `target_id`) captured at the moment the human or rule chose it. Coverage
becomes `SUM(allocations WHERE payment_id = X)`. Payment state is a **VIEW** over residuals — zero
writers, zero drift (`03_DESIGN_PATTERNS.md` **P-15 Read Model**). Where matching genuinely must be
*inferred* — bank reconciliation against an external feed — amount similarity is one **feature among
several** feeding a ranked proposal that a human or a deterministic rule confirms (`R-32`); it is never
a silent identity decision.

**Cost of the rejection** — the UI must capture intent even when the user does not care, and the
schema carries columns that are frequently the "obvious" value. Some flows need an extra decision from
the user that a heuristic would have made invisibly.

**How the rejection is enforced** — **design-time**: the allocation tables carry `NOT NULL` explicit
references, so there is nowhere to put an inferred link. **Missing:** a review rule flagging any
`where('amount', …)` used to select a domain record rather than to filter a report.
*Enforcement debt: E-21.*

**Exceptions** — amount as a *filter* in search and reporting is fine. Amount as a *feature* in a
ranked, confirmed matching proposal is fine. Amount as the deciding factor in an automatic,
unconfirmed link is not.

**Severity if violated** — **Catastrophic.** Money is attributed to the wrong obligation, silently,
and the audit trail asserts it confidently.

---

## R-25 — Multi-valued optional tags for an exclusive classification

**What it is** — modelling a one-of-N classification as a many-to-many tag relationship: cash-flow
category as account tags, an account "type" as a label set, a reporting bucket as an optional
attribute that may be absent or repeated.

**Why it is tempting** — tags are the most flexible modelling tool available and cost nothing to add.
A customer wanting a new category needs no migration. The same machinery serves several unrelated
classifications, and there is no awkward "unclassified" default to choose at creation time.

**Why QAYD rejects it** — the mechanism is that **a tag relation cannot express exclusivity or
totality, so any statement that depends on the classification being a partition becomes unprovable**:

1. **Optional means rows silently disappear.** An untagged account is in no bucket, so it vanishes
   from the statement. Nothing errors; a number is simply smaller than it should be, and the omission
   is invisible precisely because the omitted thing is not there to look at.
2. **Multi-valued means rows are silently double-counted.** A double-tagged account contributes to two
   buckets. Both are individually plausible.
3. **The reconciling identity stops being provable.** A cash-flow statement's whole validity rests on
   `operating + investing + financing + net_change = 0`. That identity is a *theorem* if the partition
   is total and disjoint, and merely a *hope* otherwise — and the statement still renders when it
   fails, it just does not tie.
4. **The invariant cannot be pushed into the database.** A tag table cannot express "exactly one of
   these per account" without a partial unique index plus a trigger for totality — at which point you
   have reimplemented a `NOT NULL CHECK` column, with more moving parts.

Transferable form: **model exclusivity as a column with a `CHECK`, not as a relation.** Ask whether an
entity may legitimately have zero or two of these. If the answer is no, the tag relation is a
constraint you have chosen not to enforce.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R23 and `ODOO_TO_QAYD.md` §4.4. `ODOO_LEARNING.md:5551`
verifies both failure modes and — decisively — finds the bug in Odoo's **own test suite**:
`test_account_account.py:810` *creates an account with both `operating` and `investing` tags; nothing
forbids it, and nothing requires any tag at all.* The consequence is stated exactly: *"Untagged
accounts silently vanish from the statement; double-tagged accounts are silently double counted. A
cash flow statement that omits or duplicates an account still renders — it just doesn't tie out."*
Phase 1's replacement (`:5610`, `:5616`) notes the identity *"can only fail if the partition is
incomplete — which the schema forbids."*

**What QAYD does instead** — `accounts.cash_flow_bucket text NOT NULL CHECK (IN ('cash','operating',
'investing','financing'))` — a total, disjoint partition, so the reconciling identity is structurally
guaranteed and the statement **cannot** fail to tie. Because reclassification silently reshapes an
already-published statement, bucket changes are event-sourced (old, new, actor, reason) and blocked for
periods covered by an approved close snapshot. Genuinely multi-valued, genuinely optional metadata
(reporting labels, search tags) remains a tag relation — that is what tags are for.

**Cost of the rejection** — a `NOT NULL` column needs a value at creation, so account creation must
default sensibly and the chart importer must classify every account, surfacing the ones it cannot (a
good AI-assist task). Adding a bucket value is a migration.

**How the rejection is enforced** — **`NOT NULL` + `CHECK` (strongest rung) once built.** Today this is
a design decision; the column does not yet exist. **Missing:** the column, and a review rule that any
new pivot table must state why the relation is not one-of-N. *Enforcement debt: E-22.*

**Exceptions** — classifications that are genuinely multi-valued and genuinely optional. The test:
would a report over this classification need to assume totality or disjointness? If yes, it is a
column.

**Severity if violated** — **Serious.** A financial statement that renders and does not tie is worse
than one that fails.

---

## R-26 — `ON DELETE CASCADE` across financial history

**What it is** — declaring cascade delete from a financial parent to its children: deleting a journal
entry removes its lines, deleting a document removes its ledger rows, deleting a partner removes its
transactions.

**Why it is tempting** — it is the correct default for genuinely owned composition, and it keeps the
database clean without application code. Removing a draft document really should remove its lines, and
`RESTRICT` produces annoying constraint errors that the application must then handle.

**Why QAYD rejects it** — the mechanism is that **cascade delete converts a single authorised action
into an unbounded, unlogged, irreversible deletion of records that were never named**:

1. **The blast radius is not visible at the call site.** `$entry->delete()` may remove one row or ten
   thousand across several tables. The person authorising the delete sees one object.
2. **Cascaded deletes bypass the domain layer entirely.** They are executed by the database engine, so
   no Action runs, no audit row is written, no event fires, no permission is checked on the children.
   The deletion of financial history is the single event most needing an audit trail, and it is
   precisely the one that produces none.
3. **It defeats append-only by the back door.** A trigger rejecting `DELETE` on `ledger_entries`
   protects direct deletes; a cascade from a mutable parent reaches the same rows through a path the
   guarantee did not anticipate. The immutability is only as strong as the weakest inbound FK.
4. **Application-level guards do not compensate**, because they run on the ORM path only and are
   typically honour-system (`R-06`).

Transferable form: **deletion of a record that others depend on must be refused, not propagated.**
`RESTRICT` turns a silent cascade into a loud question, and the loud question is the feature.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R21. `ODOO_LEARNING.md:1622`: the line table declares
`ondelete="cascade"` on its parent, so *"deleting a move cascades its lines away… There is no
append-only guarantee and no immutable event log."* Consequence 4 verbatim (`:906`): cascade *"with
only application-level guards that honour `force_delete`"* — and `:7180` confirms the restrictive
audit-trail protection itself honours that flag, making the trail *advisory* (`:1391`). The only trace
of a forced deletion is a `_logger.info` in a rotating text file (`:7181`). Phase 1's own schema
proposal closes it explicitly with a trigger *"which closes the `ON DELETE CASCADE` hole"* (`:1018`).
Worth noting Odoo's own better instinct elsewhere: routing deletions to reversal/cancellation as a
system-wide policy is described as satisfying the audit requirement without breaking workflows
(`:7269`).

**What QAYD does instead** — `RESTRICT` on every FK into financial history. Posted entries cannot be
deleted at all — correction is reversal (`R-04`, `03_DESIGN_PATTERNS.md` **P-13**). Deleting a *draft*
document is an explicit Action that removes its lines in a named, audited step. Dimension members are
soft-deleted, never hard-deleted, because allocation FKs are permanent — Phase 1 notes Odoo shipped a
migration to fix rows created with `SET NULL`, *"a real historical data-loss bug worth learning from"*
(`ODOO_LEARNING.md:10087`).

**Cost of the rejection** — cleanup code must be written explicitly, and deleting test or demo data is
more work. Users occasionally hit a `RESTRICT` error where another product would have quietly removed
everything, and the message must explain what depends on the record.

**How the rejection is enforced** — **partly database, partly missing.** The append-only trigger on
`ledger_entries` and the posted-state delete triggers hold the core. **Missing:** extending the
catalog-introspection CI check (`R-08`) to fail the build on any `ON DELETE CASCADE` into a financial
table. That is a small addition to a check that must be built anyway. *Enforcement debt: E-23.*

**Exceptions** — cascade is fine between tables that are jointly meaningless and jointly non-financial:
a draft form's scratch rows, a session's temporary state, an import staging table. Never from anything
that has been posted, and never onto anything an auditor could ask about.

**Severity if violated** — **Catastrophic.** Unlogged, unattributable, irreversible loss of financial
history.

---

## R-27 — Building a generic workflow engine

**What it is** — a configurable state machine as a product feature: `workflows`, `states`,
`transitions`, `conditions`, and `actions` tables, with an engine interpreting them, so business
processes can be modelled without code.

**Why it is tempting** — it is one of the most seductive abstractions in enterprise software, because
the observation behind it is *true*: approval flows, document lifecycles, and close checklists really
do share a shape. Building it once looks like it eliminates a category of future work, and it promises
customer-configurable processes without releases.

**Why QAYD rejects it** — the mechanism is that **the engine relocates domain logic into
configuration, where it becomes less visible, less testable, and less type-safe, without becoming
simpler**:

1. **The domain's most important rules become rows.** "Which transitions are legal?" is now data,
   invisible to the type system, absent from diffs and tests, and changeable without review — a close
   relative of `R-19`.
2. **Conditions and actions inevitably become code-in-data.** Real workflows need "if the amount
   exceeds X and the approver is not the author". That is either an expression language (`R-19`) or a
   registry of hook names, which is indirection with none of the benefits.
3. **The abstraction is built against imagined requirements.** The generic engine must be designed
   before the second real workflow exists, so its joints are in the wrong places, and every subsequent
   workflow is bent to fit or escapes into special cases.
4. **Debugging loses the stack.** "Why did this document move to approved?" is answered by tracing
   interpreted configuration rather than by reading a function.

The strongest evidence is empirical rather than theoretical: **a major ERP built one, ran it for
years, and deleted it**, replacing it with explicit state fields and explicit methods. That is a
lesson available to us for free.

Transferable form: **do not build a framework for a domain you have only one example of.** Two concrete
implementations first; extract only what they actually share. (`01` P17's rule — build a seam when you
can *name* the second implementation — is the disciplined version of this.)

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R16 and `ODOO_TO_QAYD.md` §7.3: the declarative
`workflow.workitem` engine was **removed** and replaced with explicit state fields, button methods, and
server actions; `ODOO_LEARNING.md:181` records the summary — *"We built a generic workflow engine and
it was a mistake"* — as one of the most valuable lessons in the codebase. The counter-lesson is equally
important and appears in the same study: having deleted the engine, Odoo did not replace it with a
*declared* lifecycle either, and its transition rules ended up scattered across at least six call
sites with no single place answering "what transitions are legal?" (`ODOO_BACKLOG.md` H11,
`ODOO_LEARNING.md:12104`). **Both extremes fail.**

**What QAYD does instead** — explicit `journal_entry_status` values, explicit lifecycle Actions, and —
avoiding Odoo's second mistake — **one declared transition map** as the single source of truth,
mirrored by a PostgreSQL trigger that rejects any illegal transition, including
`posted → anything-non-terminal` (`03_DESIGN_PATTERNS.md` **P-18 Lifecycle Map**; `01` P18). Approval
flows are a specific, typed pattern with four-eyes and separation-of-duties enforced by `CHECK`
(**P-04 Approval**), not a configurable graph. Phase 1 rates this urgent out of proportion to its size:
3 points today, growing linearly with every Action written against implicit rules.

**Cost of the rejection** — customers cannot define their own approval graphs, which will lose or delay
some enterprise deals; each new lifecycle is code and a release. We are betting that a small number of
well-modelled, rigorously enforced flows beats an infinitely configurable one nobody can verify.

**How the rejection is enforced** — **decided; the mechanism is the alternative.** Once `P-18`'s
transition map and its mirroring trigger ship, illegal transitions are impossible at the database
level, which removes the motivation for an engine. **Missing:** the map and trigger are specified, not
built. *Enforcement debt: E-24.*

**Exceptions** — a *checklist* whose items are data (close tasks, onboarding steps) is not a workflow
engine, provided completing an item performs no configured side effect. Approval **thresholds** as
configuration are fine; approval **transitions** as configuration are not.

**Severity if violated** — **Untidy** in immediate data terms — nothing becomes wrong — but expensive:
it obscures domain state and is very hard to remove once customers configure against it.

---

## R-28 — Cross-module coupling by inheritance or override instead of events

**What it is** — one module changing another's behaviour by extending it: subclassing its Action,
overriding its methods, adding fields to its models, or writing directly to its tables — rather than
reacting to a published fact.

**Why it is tempting** — it is the most direct way to make two subsystems cooperate, and it works
immediately. The inventory module needs a journal entry when stock moves; overriding the stock-move
method to post one is a few lines and is guaranteed to run at the right moment, in the same
transaction, with the data already in hand. Events feel indirect, eventually-consistent, and harder to
debug.

**Why QAYD rejects it** — the mechanism is that **inheritance-based extension makes the behaviour of a
component depend on the set of other components installed**, which destroys three properties at once:

1. **Nothing can be understood locally.** "What does this method do?" has no static answer once other
   modules can inject behaviour into it. Reading the code is insufficient; you must know the
   deployment.
2. **Nothing can be tested in isolation.** A module's tests pass alone and fail in combination, or
   worse, pass in both while the combined behaviour is wrong — because the composition is what
   changed, and nothing tests compositions.
3. **Nothing can be replaced.** Once module B overrides A's internals, A's internals are B's public
   API. A cannot be refactored, and the "modular" system becomes a monolith with extra indirection.

The compounding property is what makes this Serious rather than Untidy: **each override makes the next
one more likely**, because the pattern is established and because the coupling already exists, so the
marginal cost looks like zero. After enough of them the system has no seams left, and there is no
single moment where it went wrong.

Transferable form: **modules integrate through published facts, never through each other's internals.**
The test: if module B were deleted, would module A still be correct and complete? If A's behaviour
depends on B being installed, they are one module wearing two names.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` R17 and `ODOO_TO_QAYD.md` §7.5. `ODOO_LEARNING.md:11438`
demonstrates consequence 1 at its limit: a model's runtime class is assembled from module load order,
so *"what does `write()` do?"* has **no static answer**, and `super()` chains resolve differently per
installed-module set. `ODOO_LEARNING.md:186` names the absence of a domain-event bus as *"precisely why
Odoo modules are so tightly coupled"* after twenty years, and `ODOO_TO_QAYD.md` §7.5 records the direct
consequence: cross-module coupling happens through method overrides and model inheritance. The concrete
harm shows up as `R-13`: the inventory module posts its own journal entries inline and elevated,
bypassing lock-date enforcement (`ODOO_LEARNING.md:8729`). Notably, the same study praises Odoo where
it did the right thing — extension by **empty stub hook points**, *"five hook points, zero
monkeypatching"* (`:10393` region) — which is the disciplined version of the same need.

**What QAYD does instead** — after-commit domain events are the **only** cross-module integration path;
no module ever writes another module's tables (`01` P16/P17; `03_DESIGN_PATTERNS.md` **P-11 Event**).
Events go through a **transactional outbox**, so an event cannot be lost if the broker is down and
cannot fire if the transaction rolls back. Where a subsystem must produce ledger facts it builds a DTO
and calls `PostingService` (`R-13`, **P-01**). Where a genuine variation point exists, it is a named
**seam** interface (**P-03 Seam**) — but only when the second implementation can be named.

**Cost of the rejection** — real and immediate: eventual consistency between modules, an outbox to
operate, and debugging that spans a queue rather than a stack trace. Some interactions that would be
one synchronous call become a published event plus a handler plus a test for each. For a small team
this is the most expensive rejection in this document on a day-to-day basis.

**How the rejection is enforced** — **convention.** The mechanism: an architecture test asserting that
no namespace under `App\Domain\{Module}` references another module's models, tables, or Actions —
only its published event and DTO classes. Modern PHP architecture-testing tooling makes this a short
rule, and it is the highest-value untested rule in this document because it decays quietly.
*Enforcement debt: E-25.*

**Exceptions** — a **shared kernel** of primitives that belongs to no module (money handling, DTO base
types, the exception catalog, the RLS trait) may be depended on by all. Explicitly named seam
interfaces may be implemented by other modules — that is what they are for. Reading another module's
data through its published read API is fine; reading its tables is not.

**Severity if violated** — **Serious**, and it compounds faster than anything else here.

---

## R-29 — Full-history replay as a primary read path

**What it is** — computing a current value by re-processing its entire history every time it is
needed: recalculating an account balance by scanning all entries, deriving inventory cost by replaying
every movement since inception, rebuilding a projection on read.

**Why it is tempting** — it is *correct by construction*, which is a powerful property. There is no
cache to invalidate, no stored aggregate to drift, no rollup to rebuild. The code is short and
obviously right, and for the first few years of a customer's data it is fast enough. It is also the
honest response to having been burned by a stale cache.

**Why QAYD rejects it as a **primary** read path** — the mechanism is that **cost grows with the age of
the account, so the system degrades fastest for the customers who have used it longest** — i.e. the
most valuable ones, and in the least visible way:

1. **No single event marks the failure.** Each day is imperceptibly slower than the last. There is no
   regression to bisect; there is a slope.
2. **The workarounds are worse than the problem.** Batching to avoid running out of memory, or
   date-bounding a query that was supposed to be unbounded, are patches that hide the shape of the
   failure while leaving it in place.
3. **It concentrates on the hottest queries.** Balances are the most-requested value in an accounting
   system — every dashboard, every report, every posting-time validation — so the O(history) path is
   the one taken most often.
4. **Unbounded reads have unbounded blast radius.** A single "open the account page" that aggregates
   an account's entire history is a resource risk, not just a slow page.

Transferable form: **a value that is read constantly and changes rarely should be maintained, not
recomputed** — provided the maintenance is trustworthy, which requires an append-only source
(`R-15`). Replay remains the *verifier*, never the *reader*.

**Evidence** — Phase 1, `ODOO_BACKLOG.md` M15 and `ODOO_TO_QAYD.md` §4.2. Odoo stores **no aggregate
balances anywhere**; every trial balance is a full scan of the largest table, and
`_compute_current_balance` *has no date bound at all* — opening an account form aggregates its entire
history (`ODOO_LEARNING.md:374`, `:1588`). The sharpest evidence is a reversal: Odoo 19 **removed** its
stored valuation layer in favour of full-history replay, then had to batch at 50,000 rows to avoid
`MemoryError` — Phase 1 calls it *"the clearest cautionary tale in the repository: full-history replay
as a primary read path does not scale"* (`ODOO_BACKLOG.md` M15). That is consequence 2, in production,
at a company that knows what it is doing.

**What QAYD does instead** — maintained projections with a rebuilder and a drift detector
(`03_DESIGN_PATTERNS.md` **P-15 Read Model**; `01` P19). Concretely: `account_period_balances` with
`CHECK (closing = opening + debit − credit)`, maintained by an `AFTER INSERT` trigger on
`ledger_entries`, plus `RebuildPeriodBalancesAction` run in CI and on a schedule. The crucial point is
*why QAYD may do this and Odoo may not*: the rollup is trustworthy **only because its source is
append-only**, which makes the trigger monotonic — it can only ever increment. This is a direct
dividend of `R-04` and `R-15`, and Phase 1 rates it the single largest scalability win available
(trial balance becomes a ~2,000-row index scan instead of a full scan of the largest table).

**Cost of the rejection** — a projection, a trigger, a rebuilder, and a drift check per aggregate —
real code with no user-visible feature. And a maintained aggregate *can* be wrong in a way a replay
cannot, which is precisely why the drift detector is not optional.

**How the rejection is enforced** — **specified, not built.** The mechanism once built: the rollup
table plus its `CHECK`, the trigger, and a scheduled drift check that fails loudly (never
`Log::warning` — see `R-30`). **Missing:** all of it, plus a performance test asserting the trial
balance plan does not scan `ledger_entries`. *Enforcement debt: E-26.*

**Exceptions** — replay is the **correct** implementation for verification, rebuilds, and one-off
analysis, and every projection must ship one. Replay is also fine on paths bounded by something other
than history (all lines of one entry, one month of one account). It is banned only as the primary
answer to a routine question.

**Severity if violated** — **Untidy** at the point of writing — nothing is wrong, and it is often the
right first implementation. It becomes a Serious operational problem on a timeline set by customer
growth, which is why it is recorded here rather than left to be discovered.

---

## R-30 — Silent degradation (warn-and-continue after a failed invariant)

**What it is** — detecting that something is wrong and proceeding anyway with a log line: catching an
exception and continuing the loop, logging a warning when a recomputation does not converge, falling
back to a slower or weaker path when a check fails, returning a default when the real value could not
be obtained.

**Why it is tempting** — it maximises availability, which is usually the right instinct. Failing a
whole 10,000-row import because one row is odd is bad service. A warning preserves the information for
someone who cares while letting the work finish. It also feels defensive and mature — the alternative
looks like a system that falls over at the first surprise.

**Why QAYD rejects it in the financial core** — the mechanism is that **a logged warning is not a
signal, it is a deferral to nobody**:

1. **Nobody reads it.** Warnings land in a rotating text file or a log aggregator alongside thousands
   of benign lines. The one that mattered is discovered by grepping backwards after an auditor asks a
   question — if the retention window has not already closed.
2. **The system continues with data it has itself declared invalid.** Everything computed afterwards
   is built on a foundation the system knows is unsound, and each subsequent step is individually
   reasonable. This is how a single bad row becomes a wrong return.
3. **Availability is the wrong objective for a ledger.** For a social feed, degraded-but-up beats
   down. For a system of record, a wrong number that looks right is strictly worse than an error — the
   error costs an hour; the wrong number costs an amended filing and the customer's trust, and
   `MANIFEST.md` ranks **system health above user experience** precisely for this case.
4. **Silent fallbacks conceal the condition that triggered them.** A fallback path that works "well
   enough" removes the pressure to fix the cause, so the underlying defect persists indefinitely.

Transferable form: **if the code has enough information to write a warning, it has enough information
to throw.** The question is never "should we continue?" but "who is guaranteed to see this, and when?"
If the answer is not "the caller, now", it is not handled.

**Evidence** — Phase 1's ORM analysis, `ODOO_LEARNING.md:11430` item 2, titled *"Silent degradation is
systemic"*, listing four failure modes that log and continue: fixpoint exhaustion warns *"Too many
iterations for flushing fields!"* and **data stays unflushed**; a `required` field with no `NOT NULL`
column produces a schema warning and continues; a prefetch permission error silently falls back to
per-record fetching, turning one query into up to a thousand; and cache/DB divergence logs *"Invalid
cache"* and is only checked in debug mode. Related instances elsewhere in the study: a forced deletion
of posted entries leaves only a `_logger.info` line in a rotating file (`:7181`, `:7277`); the
inventory valuation path silently absorbs negative-stock drift (`:10393` region); and — the purest
example — a missing exchange rate silently resolves to `1.0` with *"no error, no log, no flag"*
(`ODOO_BACKLOG.md` H10).

**What QAYD does instead** — typed, coded `DomainException`s with machine-readable payloads (`01`
P13). Bulk operations aggregate **all** violations into one structured `ValidationReport` and reject
the batch or the row explicitly, with `violations[]` of `{code, field, message, actual, expected}`
(`03_DESIGN_PATTERNS.md` **P-05 Validation**) — which serves both a human fixing an import and an AI
agent fixing everything in one round trip. Rejected postings are recorded in `posting_attempts`, so a
failure is a **row**, not a log line: queryable, retained, and reportable
(`ODOO_BACKLOG.md` M16). Drift detectors fail the build or the scheduled job; they do not warn.
Degradation that *is* acceptable is explicit and typed — e.g. `BANKREC_AI_ENGINE_UNAVAILABLE` (503),
degrading to deterministic rules only, which is a declared, visible, tested behaviour rather than a
silent fallback.

**Cost of the rejection** — imports fail more often, and each failure needs a good enough error report
that the user can act on it, which is real UI work. Operationally we accept more visible incidents in
exchange for fewer invisible ones — a trade that looks bad on a status page and good in an audit.

**How the rejection is enforced** — **convention.** The mechanism: a lint/review rule that
`Log::warning`/`error` immediately followed by `return`, `continue`, or a default value in domain
namespaces is a build failure; plus a rule that `catch` blocks in domain code must rethrow, convert to
a typed `DomainException`, or record a `posting_attempts`-style row. *Enforcement debt: E-27.*

**Exceptions** — genuinely optional, non-financial side channels: a notification that could not be
delivered, a cache warm-up, a telemetry write. The test: if this step had never run, would any number
be different or any guarantee weaker? If yes, it must throw.

**Severity if violated** — **Catastrophic**, because it is the mechanism by which every other
rejection in this document fails *quietly* instead of loudly.

---

## R-31 — Letting an AI agent write directly to domain tables

**What it is** — giving the model, or the service hosting it, the ability to insert or update
production data: an agent that posts journal entries, a tool call that writes `journal_lines`, an
extraction pipeline that creates invoices, an "autonomous bookkeeper" that reconciles without a human.

**Why it is tempting** — it is the entire product promise, and the intermediate step feels like
friction that competitors will not have. The model is often *right*, frequently more consistent than a
tired human, and the proposal-plus-approval architecture looks like it doubles the work for a benefit
that shrinks as models improve. There is also a plausible-sounding safety argument: validate the
model's output with the same rules a human's output passes, and it is no more dangerous than a user.

**Why QAYD rejects it** — that safety argument is exactly where the reasoning fails, and the mechanism
is worth stating precisely:

1. **Model output is a fundamentally different threat shape from user input.** A user makes
   uncorrelated mistakes at human volume. A model makes **correlated** mistakes at machine volume: one
   misread rule produces the same wrong classification across ten thousand transactions in a minute,
   all internally consistent, all passing validation. Validation catches *invalid* data; it does not
   catch data that is valid and wrong, which is the model's characteristic failure.
2. **Instructions and data are not separable in an LLM's input.** Anything the agent reads — an
   invoice PDF, a bank statement narrative, a vendor email, a supplier's line-item description — is
   attacker-controllable text arriving on the same channel as its instructions. If the agent holds
   write credentials, then *the ability to send a document to the customer is the ability to write
   their ledger*. That is not a prompt-hardening problem; it is a privilege problem, and the only
   robust fix is to not hold the privilege.
3. **Accountability disappears.** Financial records must attribute every entry to a responsible party.
   "The model did it" is not an answer an auditor accepts, and there is no meaningful sense in which a
   model can hold professional responsibility for a filing.
4. **Non-determinism defeats reproduction.** The same input can produce a different write. Debugging,
   regression testing, and root-cause analysis all assume reproducibility.

Transferable form: **capability must be bounded by architecture, not by behaviour.** Any safety
property that depends on the model behaving well is not a safety property. Ask: *if this component
were fully adversarial, what could it change?* If the answer includes financial data, the boundary is
in the wrong place.

**Evidence** — this is QAYD's own architectural position rather than an Odoo finding; Phase 1 assumed
it as a premise throughout — *"FastAPI AI engine that never writes the DB"* (`ODOO_LEARNING.md:201`) —
and designed every AI touchpoint around it: `coa_suggestions` with `ApproveCoaSuggestionAction` as
*"the only path into `accounts`"* (`:535`); `match_proposals` written by an Action that **never**
touches `reconciliation_links` (`:3974`); `dimension_suggestions.proposed_payload` as the one place
unvalidated JSON is allowed (`:8307`); consolidation and intercompany suggestions writing only to
`consolidation_proposals` (`:10966`). Odoo offers a useful *negative* analogue for consequence 2: the
inventory path posts journal entries elevated, so *"a user who can validate a picking can cause a
posted journal entry they could not create directly"* (`:8728`) — a non-AI instance of exactly the
privilege-escalation shape, which is why `R-07` and this entry share a mechanism.

**What QAYD does instead** — `01` P15, enforced by a **separate database role with no `INSERT` on any
domain table**. The AI service may write only to proposal tables — `coa_suggestions`,
`match_proposals`, `dimension_suggestions`, `consolidation_proposals` — each carrying `confidence`,
`explanation`, `source`, and `outcome`. A Laravel Action is the only promoter of a proposal into real
data, and the ledger is reached only via `PostingService`
(`03_DESIGN_PATTERNS.md` **P-12 AI Action**, **P-01 Posting**). AI-drafted entries carry
`entry_source = 'ai_draft'` with a `CHECK` requiring a confidence, and a trigger forbidding an AI
draft from reaching `posted` without human approval.

**Cost of the rejection** — a human is in the loop for volume work, which caps automation and is the
main thing a bolder competitor could beat us on in a demo. Proposal tables, promotion Actions, review
queues, and their UI are substantial engineering that produces no output the AI could not have written
directly.

**How the rejection is enforced** — **should be a database `GRANT` (strongest rung)**: the AI role
holds `INSERT` on proposal tables only, so a compromised or misbehaving agent *cannot* write a ledger
row, regardless of what it is persuaded to attempt. **Missing:** the dedicated role and its grants are
specified but not provisioned; today the boundary is the absence of code, which is not a mechanism.
This is the single highest-priority enforcement gap in this document. *Enforcement debt: E-28.*

**Exceptions** — none for financial data. AI may write freely to its own artifacts: proposals, chat
transcripts, embeddings, extraction caches, evaluation logs. The test is not *how confident the model
is* but *whether a human or a deterministic rule stands between the output and the ledger*.

**Severity if violated** — **Catastrophic**, and uniquely so: it is the only entry here whose failure
mode includes an external party deliberately steering the system through content it supplies.

---

## R-32 — Trusting model output without a confirmation boundary

**What it is** — the softer version of `R-31`: the AI still writes only proposals, but a proposal
becomes real without a human or a deterministic rule agreeing. Auto-apply above a confidence
threshold, an agent whose own second call reviews its first, or a promotion step that validates the
*shape* of the output and treats shape-validity as correctness.

**Why it is tempting** — reviewing thousands of high-confidence, obviously-correct proposals is
demoralising and expensive, and it is the main thing customers want automated. A calibrated model
above 0.97 really is more accurate than a bored human on transaction 400. Auto-accepting the easy
cases so humans see only the hard ones looks like the *right* division of labour — and, done properly,
it is.

**Why QAYD rejects the unguarded form** — the mechanism is that **a confidence score is a statement
about the model's internal state, not about the world**, and three properties follow:

1. **Confidence is not calibrated across distribution shift.** It is high on inputs resembling
   training data and *stays high* on inputs that do not — a new bank format, a new vendor, a new
   customer's chart. Confidence degrades most gently exactly where accuracy degrades most sharply.
2. **A threshold converts a rare error into a systematic one.** Above the line, nothing is reviewed —
   so any error mode that reliably scores high is applied to *every* instance of it, forever, with no
   sampling that would reveal it.
3. **Self-review is not independent.** A model checking its own output shares its priors and its
   misreadings; a second call agreeing is weak evidence, and an agreeing *second model* is only
   slightly stronger. Independence is what makes a check a check.
4. **Shape validation is not correctness.** A proposal that balances, references real accounts, and
   parses perfectly can still book a capital purchase to repairs expense. Every automated check passes;
   the number is wrong.

Transferable form: **an automated action needs an independent source of agreement, not a stronger
opinion from the same source.** "Independent" means: a deterministic rule computed from data the model
did not author, or a human. A second model call is neither.

**Evidence** — QAYD's own position, and Phase 1 designed the disciplined version rather than banning
automation outright. `ODOO_LEARNING.md:3869` specifies three strict tiers: deterministic rules first;
AI proposals with confidence and rationale second, *"never to the ledger"*; human confirmation third.
Crucially, `:3870` permits auto-accept **only when both** `confidence >= auto_accept_threshold` (per
company, default `0.97`) **and** a deterministic rule agrees — and it still records `source = 'ai'`
with the confidence persisted on the link, so every automated decision remains identifiable and
auditable after the fact. The rationale for deterministic-first is stated plainly
(`ODOO_BACKLOG.md` M5): *"it keeps the AI honest — it only sees what rules could not settle."*
Supporting machinery: `mp_one_pending` (one pending proposal per transaction), `BANKREC_PROPOSAL_STALE`
when residuals move after a proposal was made, and rejection outcomes retained as a training signal
(`:3951`, `:4015`, `:3974`). The negative analogue from Odoo is `R-11`: silent correction is
disqualifying *"when an AI is the drafter"* precisely because the machine cannot learn from an
invisible fix (`ODOO_LEARNING.md:1419`).

**What QAYD does instead** — the three-tier boundary above, with automation permitted only at the
intersection of high confidence and independent deterministic agreement, and every automated decision
persisted with `source`, `confidence`, and the rule that agreed
(`03_DESIGN_PATTERNS.md` **P-12 AI Action**, **P-04 Approval**). Rejections are stored, not discarded —
`posting_attempts` and proposal `outcome` are the compliance record *and* the training set. Thresholds
are per company, adjustable, and auditable; a customer may set them to 1.0 and review everything.

**Cost of the rejection** — throughput. Some proposals a competitor auto-applies will sit in a queue,
and building the review UI well is more work than not needing one. The threshold-plus-agreement rule
also means the deterministic tier must be good, which is real engineering that "just trust the model"
avoids entirely.

**How the rejection is enforced** — **partial.** Structural pieces exist in the design: `entry_source`
with a `CHECK` requiring confidence, a trigger forbidding an AI draft from posting without approval,
and a unique index limiting pending proposals. **Missing:** the auto-accept path must be implemented
such that the deterministic-agreement condition is not bypassable by configuration alone — i.e. the
promoter Action requires a non-null agreeing-rule reference, enforced by `CHECK`, not by an `if`.
*Enforcement debt: E-29.*

**Exceptions** — advisory output that writes nothing: explanations, summaries, search ranking,
anomaly *flags* that open a review rather than change data. Auto-accept for financial writes is
permitted **only** under the dual condition above, with the threshold recorded per company and
changes to it audited. Approval to widen it: architecture owner plus the accounting-domain owner.

**Severity if violated** — **Catastrophic.** Correlated, confident, systematic misclassification is the
characteristic AI failure, and a threshold guarantees it is never sampled.

---

## R-33 — Prompts, policies, or rules stored as executable code

**What it is** — the AI-era instance of `R-19`: storing model-facing instructions or business rules as
strings that are later interpreted with authority — a prompt template that interpolates retrieved
document text into its instruction section, a per-tenant "rule" column whose contents steer tool
selection, a stored expression the agent's output is fed into and evaluated.

**Why it is tempting** — prompts genuinely need to be configurable: per tenant, per jurisdiction, per
document type, tuned without a release. Storing them as data is the obvious way, and it works. Feeding
retrieved context into the prompt is not merely tempting, it is *the entire technique* — RAG is
built on it.

**Why QAYD rejects it** — the mechanism is that **in an LLM, the instruction channel and the data
channel are the same channel**, so any stored or retrieved string that reaches the model with
instruction-level authority is executable code with respect to the agent's behaviour:

1. **Interpolating untrusted content into instructions is injection.** A vendor's invoice
   description, a bank narrative, or an email body can carry text that reads as direction. There is no
   escaping function that reliably neutralises it — unlike SQL, there is no grammar to parameterise
   against. Mitigations reduce likelihood; they do not create a boundary.
2. **A stored prompt is an unreviewed change to system behaviour.** It bypasses code review, CI,
   tests, and the type system, and it changes without a commit — precisely `R-19`'s consequence 1,
   moved one layer up.
3. **Anything evaluating model output inherits its authority.** If output is compiled, `eval`ed, or
   turned into SQL by string assembly, then influencing the model is executing code. The
   injection→execution chain closes.
4. **Behaviour becomes unversionable.** "Why did the agent do that in March?" is unanswerable if the
   prompt in force was a mutable row.

Transferable form: **untrusted text may be an input to a decision; it may never be an instruction, and
model output may never be evaluated.** Retrieved content must be clearly framed as data the model
*reasons about*, never as direction it *follows*, and the model's output must land in a typed,
validated structure — never in something that is executed.

**Evidence** — this is QAYD's own position; Phase 1 supplies the structural precedent rather than the
AI case. `ODOO_LEARNING.md:11606` documents a security predicate stored as **executable text in a
column**, evaluated on the request path with essentially no constraint — establishing consequences 1–2
in a non-AI setting and showing where they lead. Phase 1's positive design is the direct answer, and
it generalises cleanly to prompts: predicates as **portable data** — *"an LLM can emit a predicate; a
human can read and approve it; the backend compiles it to SQL through an allowlist"*, compared
explicitly against *"the alternative, where an AI emits SQL or calls mutating methods directly — which
is unreviewable and unsafe by construction"* (`ODOO_BACKLOG.md` H12). The mandated implementation is a
closed, `CHECK`-constrained JSONB selector compiled through an allowlist to bound parameters,
**never `eval`, never string interpolation**, with one reviewed compiler securing three subsystems.
The same shape governs the AI's own payloads: JSON is permitted in `proposed_payload` precisely
because it is *"unvalidated proposals"* normalised to rows before anything reaches `journal_lines`
(`ODOO_LEARNING.md:8307`).

**What QAYD does instead** — prompts are **versioned application assets** — in the repository, code
reviewed, diffable, released — with tenant variation expressed as typed, validated *parameters*
(company name, jurisdiction, chart summary, tone), never as free-form instruction text supplied by a
tenant. Retrieved content is passed in a clearly delimited data region, framed as untrusted, and the
model is never given authority to act on directives found inside it. Model output is parsed into typed
DTOs and validated against the same domain rules as human input (`R-32`), and structured decisions the
model emits are `CHECK`-constrained selectors compiled by an allowlist
(`03_DESIGN_PATTERNS.md` **P-19 Declared Config**, **P-12 AI Action**). Nothing the model produces is
evaluated as code — ever.

**Cost of the rejection** — prompt iteration requires a release rather than a config edit, which slows
the tightest experimentation loop in AI work. Tenant-specific instruction tuning is limited to a
parameter vocabulary we design, and extending it is engineering. Some genuinely useful "just tell the
model about this customer's quirk" capability is off the table until it is modelled properly.

**How the rejection is enforced** — **convention today.** The mechanisms: the `eval`/dynamic-execution
architecture test from `R-19`; a rule that prompt templates live only in versioned repository assets
and that no prompt asset may interpolate a database-sourced string into an instruction region; and a
schema rule that no column stores an expression. Prompt assets should carry a version id recorded on
every proposal, so behaviour is reconstructable after the fact. *Enforcement debt: E-30.*

**Exceptions** — user-authored content passed as **data** (the document being analysed, the question
being asked) is not this pattern — that is the normal, intended use, and the boundary is that it never
carries instruction authority. Per-tenant *parameters* from a typed vocabulary are fine. There is no
exception permitting evaluation of model output.

**Severity if violated** — **Catastrophic.** It is the injection→execution path, reachable by anyone
who can send the customer a document.

---

## R-34 — Using an LLM where a deterministic rule suffices

**What it is** — reaching for a model on a task that has an exact answer: checking whether an entry
balances, matching a bank line whose reference exactly equals an invoice number, applying a fixed VAT
rate, converting currency at a stored rate, deciding whether a period is open.

**Why it is tempting** — the model is already integrated, it handles all the messy variants without
enumerating them, and it is faster to prototype than an explicit rule. It also degrades gracefully at
the edges where a rule would simply fail. And there is a real institutional pull: AI usage looks like
progress, and "our AI does it" is easier to market than "we wrote a correct rule".

**Why QAYD rejects it** — the mechanism is that **using a probabilistic tool for a deterministic
problem imports every property of the probabilistic tool and gains nothing**:

1. **Reproducibility is lost.** The same input may produce a different answer, so a defect cannot be
   reliably reproduced, a fix cannot be proven, and a regression test is statistical rather than
   binary.
2. **Verification becomes impossible.** A rule can be proven correct by inspection or exhaustive test.
   A model can only be *measured* on a sample — so the strongest available claim about a computation
   with an exact answer becomes "97% accurate on our eval set", which is not a claim you can make to
   an auditor about arithmetic.
3. **Every input becomes an injection surface.** A deterministic comparison cannot be talked out of
   its answer; a model can (`R-33`). Using a model where a rule would do *creates* an attack surface
   that need not have existed.
4. **Cost, latency, and availability move onto a critical path** that had none of those dependencies —
   and an outage in a third-party service now blocks a computation that is a subtraction.
5. **The deterministic tier atrophies.** If the model handles everything, the rules are never written;
   when the model is wrong there is no independent check to catch it, which is exactly the agreement
   `R-32` depends on.

Transferable form: **use AI for judgement under ambiguity, never for computation with a known
answer.** The test: is there an input for which a competent engineer could write the exact expected
output? If yes, write the rule. AI's proper domain is what remains — ambiguity, unstructured input,
ranking, and proposals a human confirms.

**Evidence** — Phase 1's architecture is explicitly tiered to prevent this. The bank-matching design
mandates *"three tiers, in strict order: (1) **deterministic rules** — exact reference/amount match,
no AI; (2) AI proposals…; (3) human confirmation"* (`ODOO_TO_QAYD.md` §3.4), with the reason stated as
a design goal rather than a preference: *"Deterministic-first also keeps the AI honest: it only sees
what rules could not settle"* — consequence 5, anticipated. Where Phase 1 recommends AI, it is
consistently for genuinely ambiguous work: proposing a chart mapping for an opening-balance import and
surfacing the unmapped accounts (`ODOO_BACKLOG.md` M9), proposing account mappings for a newly
onboarded subsidiary *"the most tedious task in the subsystem and the one AI is genuinely best at"*
(`ODOO_LEARNING.md:10966`), ranking reconciliation candidates from features like trigram similarity
and historical affinity (`:3869`). Note that even there, the *features* are computed deterministically
and the model only ranks.

**What QAYD does instead** — deterministic rules for everything with an exact answer, in Actions, with
exhaustive tests. AI is applied to ambiguity — extraction from unstructured documents, chart mapping,
candidate ranking, anomaly surfacing, explanation — and always inside the proposal boundary
(`03_DESIGN_PATTERNS.md` **P-12 AI Action**). Where both apply, deterministic runs first and the model
sees only the residue.

**Cost of the rejection** — more rules to write and maintain, and rules fail on inputs they do not
cover where a model would have produced something plausible. Some features ship later because the
deterministic tier has to exist first.

**How the rejection is enforced** — **review convention.** The practical test used in review: *can you
write the expected output for an arbitrary input?* If yes, an AI call in that path is rejected. A
supporting mechanism worth building: AI call sites are registered with a stated ambiguity
justification, so the set is enumerable and reviewable rather than growing quietly.
*Enforcement debt: E-31.*

**Exceptions** — a model may *assist* a deterministic path by proposing which rule to apply, or by
handling the residue after rules run. It may not *be* the rule. Advisory, non-financial UI text is
unaffected.

**Severity if violated** — **Serious** in a financial path — non-determinism, unverifiability, and an
unnecessary injection surface on a computation that had an exact answer. **Untidy** where the output is
purely advisory and nothing derives from it.

---

## Enforcement debt

`01` is explicit that a rule without a mechanism is not a rule. This document currently contains more
rules than mechanisms. The gaps are listed here so they can be scheduled rather than rediscovered.

| ID | Missing mechanism | Covers | Rung it reaches | Est. |
|---|---|---|---|---|
| **E-28** | AI database role with `INSERT` only on proposal tables | R-31, R-32 | GRANT (strongest) | 3 |
| **E-8** | Catalog-introspection CI check (`NOT NULL`, FORCE RLS, named policy) | R-08, R-26 | CI (fails build) | 5 |
| **E-13** | Table `GRANT`s + arch test: one writer into the ledger | R-13, R-14 | GRANT + arch test | 5 |
| **E-4** | Lifecycle transition map + mirroring trigger | R-04, R-27 | trigger (strongest) | 3 |
| **E-25** | Arch test: no cross-module references outside events/DTOs | R-28 | arch test | 3 |
| **E-6** | Grep arch test for bypass-parameter vocabulary | R-06 | CI | 2 |
| **E-7** | Arch test: no `withoutGlobalScopes`/`unguarded`/privileged connection | R-07 | arch test | 2 |
| **E-19/E-30** | Arch test: no `eval`/dynamic execution; no stored expressions; prompts are repo assets | R-19, R-33 | arch test | 3 |
| **E-1/E-2/E-20** | Arch tests: model surface, controller dependencies, no observers | R-01, R-02, R-20 | arch test | 5 |
| **E-14** | Arch test: no mutating raw SQL outside migrations | R-14 | arch test | 2 |
| **E-18** | No `Schema::` outside migrations; revoke DDL from runtime role | R-18 | GRANT + arch test | 3 |
| **E-27** | Lint rule: no warn-and-continue in domain namespaces | R-30 | CI | 3 |
| **E-9** | Static analysis: money arithmetic and `float` columns | R-09 | static analysis | 3 |
| **E-3** | Static analysis: no epsilon comparison on money | R-03 | static analysis | 2 |
| **E-15** | Schema-shape test on `ledger_entries` column set | R-15 | test | 1 |
| **E-12** | Assert-no-added-lines test in `PostingService` suite | R-12 | test | 1 |
| **E-17** | Per-projection drift check in CI | R-17, R-29 | CI | 3 |
| **E-29** | `CHECK`-enforced agreeing-rule reference on auto-accepted proposals | R-32 | CHECK | 2 |
| **E-5/E-10/E-11/E-16/E-21/E-22/E-23/E-24/E-26/E-31** | Review rules, ADR requirements, and specified-but-unbuilt schema | various | review / design | — |

**E-28 is first.** It is the only gap whose failure mode includes an external party steering the
system, and it is three points of work.

---

## How to propose an exception

A rule with no amendment process gets ignored rather than debated — quietly, by whoever is under
deadline, without the reasoning ever being examined. So this document is amendable, on the record.

### What this is not

It is not a route to a one-off bypass. "We need to violate R-06 just for this import" is not an
exception request; it is the thing R-06 describes. An exception changes the **rule**, for everyone,
permanently, in writing — or it is refused. If you want it only for your case, you want a flag, and
flags are `R-06`.

### The bar

A rejection is overturned by showing that the **mechanism of harm does not apply**, or that it applies
and is outweighed by a cost we did not previously understand. Note what is *not* on the list of
acceptable arguments:

- "It is faster to write." Known, and priced into every *Cost of the rejection* section.
- "Other systems do it." Every entry here is drawn from a system that does it.
- "We will be careful." `01`'s ladder ranks that below documentation.
- "The deadline." `MANIFEST.md` ranks development speed **fifth** of five.
- "Our AI is good enough now." Capability is not a boundary (`R-31`).

### The evidence required

1. **State the mechanism, in our words.** Quote the *Why QAYD rejects it* section and say precisely
   which step of the causal chain does not hold here, and why. If you cannot restate the mechanism,
   you are not yet ready to argue against it.
2. **Show the cost is real and measured.** Not "this will be slow" — a benchmark, a query plan, a
   profile, a support ticket count, a lost deal with a named requirement. The *Cost of the rejection*
   section is our prior; you are updating it with data.
3. **Show the alternative was actually built or seriously scoped.** The replacement pattern named in
   *What QAYD does instead* must have been attempted or costed. Most exception requests dissolve here.
4. **Bound the blast radius.** Which tables, which code paths, which tenants. An unbounded exception
   is a rejected exception.
5. **Name the detection.** If the harm occurs anyway, what surfaces it, how fast, and to whom? An
   exception with no detector is a silent failure with paperwork.
6. **State the reversal plan.** What it costs to undo in twelve months, once data exists in the new
   shape. If the answer is "a migration of the largest table", say so — that is usually the decisive
   number (`R-16`).

### Who decides

| Severity | Decider | Also required |
|---|---|---|
| **Untidy** | Any two engineers in review | A note in the PR; no ADR needed |
| **Serious** | Architecture owner | **ADR** recording the six points above |
| **Catastrophic** | Architecture owner **and** accounting-domain owner, jointly | **ADR**, plus a written detection-and-reversal plan, plus a scheduled review date |

For the AI-boundary rejections (`R-31`, `R-32`, `R-33`), add a required security review, because their
failure modes involve an adversary rather than only a mistake.

Silence is refusal. An unanswered request is not an approved one.

### What must be documented

An approved exception produces, in the same change:

1. **An ADR** in `docs/architecture/adr/` — the six evidence points, the decision, the decider, the
   date, and the scheduled review.
2. **An edit to this file** — the entry gains an explicit *Exceptions* clause, or the entry is
   rewritten. **This file must never contradict the code**, per `MANIFEST.md` Law 1: if the code has
   an exception this document does not name, one of them is a bug.
3. **A test proving the boundary of the exception** — that it applies where approved and fails where
   not. An exception without a test is an exception that will spread (`R-06`, consequence 1).
4. **An entry in `TECH_DEBT.md`** if the exception is temporary, with the condition that would end it.

### Reviewing an existing rejection

Rejections should also be re-examined when the world changes, not only when someone is blocked. A
rejection is worth revisiting when:

- The **mechanism** stops applying — e.g. PostgreSQL gains a way to express an invariant we could
  previously only enforce in code.
- The **cost** we accepted turns out to be much larger than estimated, with data.
- The **evidence** is superseded — a Phase 1 finding was wrong, or was fixed upstream in a way that
  changes the analysis.

Re-examination is normal engineering. Quietly violating a rejection is not — the difference is whether
the reasoning is written down where the next person can find it.

---

*Companion documents: `01_ENGINEERING_PRINCIPLES.md` (what we do), `02_ARCHITECTURE_DECISIONS.md`
(what we chose), `03_DESIGN_PATTERNS.md` (how we build it). Evidence:
`docs/research/odoo/ODOO_BACKLOG.md`, `ODOO_TO_QAYD.md`, `ODOO_LEARNING.md`. No Odoo source is
reproduced in this document; citations locate claims for verification against commit `f3e407c6`.*

