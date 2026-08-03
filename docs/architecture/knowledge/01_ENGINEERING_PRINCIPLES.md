# 01 — Engineering Principles

**QAYD's constitutional document.** Version 1.0 · 2026-07-28 · Status: **binding**

---

## What this document is

QAYD is an AI Financial Operating System. It keeps other people's books. When it is wrong, someone
files an incorrect tax return, closes a year on a false balance, or pays an invoice twice — and finds
out months later, from an auditor.

That single fact drives everything below. A social app that loses a row loses a row. A ledger that
loses a row loses **trust**, and trust in an accounting system is not recoverable by a hotfix; the
customer has already signed something.

This document defines the engineering philosophy that should still govern this codebase in ten years.
It is deliberately short on technology and long on reasoning, because the technology will change and
the reasoning should not. Documents `02`–`09` in this directory elaborate individual areas; **they
may add detail, they may not contradict this file.**

### Precedence

```
MANIFEST.md                          vision, laws, decision priority
   └── 01_ENGINEERING_PRINCIPLES.md  ← you are here (how we build, and why)
         └── docs/architecture/adr/  individual reversible decisions
               └── 02–09 knowledge   subsystem elaboration
                     └── code        the only thing that actually runs
```

On a conflict between this document and the code: **the code is what runs, so the code is the fact** —
but a divergence is a bug in one of them, never something to leave alone. Resolve it the way
`MANIFEST.md` Law 1 requires: decide whether the doc is stale or the code drifted, then write an ADR
if the architecture genuinely changed. Never silently bend code to a stale doc, and never silently
leave a doc describing a system that no longer exists.

### Who this is written for

An engineer joining QAYD who has never seen this codebase, has good general instincts, and is about to
make a change. Every principle below exists because the obvious alternative is cheaper on the day you
write it and more expensive every day after.

---

## How to read a principle

Each principle has the same ten parts. The first six are the constitutional core; the last four exist
so a reader can weigh the principle rather than merely obey it.

| Part | What it answers |
|---|---|
| **Statement** | One sentence. Quotable in a code review with no further context. |
| **Why** | The reasoning from first principles. Not precedent, not taste. |
| **What it forbids** | Concrete, enumerable, testable. A principle you cannot violate is not a principle; it is a mood. |
| **How we enforce it** | A mechanism — a database constraint, a CI check, an architecture test. Never "we agreed to be careful." |
| **Cost we accept** | The honest downside. Every principle costs something; a principle whose cost is unstated is a slogan. |
| **When it may be revisited** | The specific conditions that would make changing it correct. Principles are not permanent by decree; they are permanent because the conditions that would justify changing them are rare. |
| *Alternatives considered* | What else we could have done, and the specific reason it loses. |
| *Engineering risks* | How holding this principle can itself hurt us. |
| *At scale* | How the principle behaves at 100× today's data and team size. |
| *Effort · Confidence* | Fibonacci cost to enforce (build the mechanism, not follow the rule), and how sure we are, with the reason for the uncertainty. |

**On enforcement.** The single strongest idea in this document is that a rule without a mechanism is
not a rule. Ranked by strength:

```
STRONGEST   database constraint / trigger / grant   cannot be violated, by anyone, ever
            ─────────────────────────────────────
            CI check (fails the build)              cannot be merged
            architecture test                       cannot be merged, and explains itself
            static analysis (phpstan/psalm level)   cannot be merged, mechanically
            code review convention                  can be forgotten under deadline
WEAKEST     documentation                           will be forgotten under deadline
```

When you propose enforcing a new rule, name where it sits on this ladder. If your answer is
"documentation", you have not enforced anything.

---

## The ten commandments

The whole philosophy, in the time it takes to drink a coffee. Each commandment is implemented by the
principles named beside it; the principles are where the reasoning, the cost, and the escape conditions
live.

| # | Commandment | The failure it prevents | Principles | Strongest enforcement today |
|---|---|---|---|---|
| **I** | **The database has the last word.** If it must always be true, the database says so. | An invariant that holds only on the code paths that remembered to call the checker. | P1 · P2 · P3 | CHECK · trigger · RLS `FORCE` |
| **II** | **Money is exact.** `NUMERIC(19,4)`, moved as strings, computed with bcmath. Never a float. | Fils-level drift that no one notices until a reconciliation is off by 0.0001 and cannot be explained. | P4 | Column type + PHPStan `numeric-string` |
| **III** | **History is written once.** The ledger only grows; a posted entry never changes. | A book whose past silently differs from the one the auditor saw last quarter. | P5 · P6 | `trg_ledger_entries_append_only` |
| **IV** | **There is one door into the ledger.** Every posted line comes through `JournalEntryPostingService`. | A second write path that duplicates the invariants *incompletely*, in a way that surfaces months later. | P7 | Single service + architecture test |
| **V** | **All or nothing.** One transaction per financial operation; effects are published only after commit. | Half-posted entries: a number allocated, a projection missing, an email already sent. | P8 · P9 | One `DB::transaction` + concurrency suite |
| **VI** | **Logic lives in Actions.** Controllers route, models map rows, DTOs carry data, Actions decide. | Business rules reachable only over HTTP — untestable, unreusable, and duplicated the day a job needs them. | P10 · P11 · P12 | Architecture tests |
| **VII** | **Nothing is silent.** No silent coercion, no silent fallback, no swallowed error. Every failure is typed and coded. | A wrong number that looks like a right number: a date quietly shifted, a missing rate quietly treated as 1.0. | P13 · P14 | `DomainException` catalog + envelope test |
| **VIII** | **AI drafts; a human disposes.** The AI engine proposes, and cannot write accounting tables — by grant, not by good behaviour. | An autonomous agent posting to someone's general ledger. There is no acceptable version of this. | P15 | No DB driver in the AI service (to be replaced by an explicit grant-less role) |
| **IX** | **Everything is replaceable and rebuildable.** Seams at volatility boundaries; every projection ships a rebuilder. | A subsystem that cannot be swapped, and a cache nobody can prove is still correct. | P16 · P17 · P19 | Interface bindings + drift check |
| **X** | **No ambient privilege and no private channels.** There is no `sudo()`; modules speak only through domain events; every effect is explainable afterwards. | Security that is a convention, coupling that is invisible at the call site, and an effect nobody can attribute. | P21 · P22 · P23 | Absence of a bypass + `$listen` map + `audit_logs` |

> **If you remember one sentence:** *an invariant that lives only in application code is a wish.*

---

## Where does this logic belong?

The most common question a new engineer has, and the one most often answered wrongly under deadline.
Follow the tree. It has no "it depends" leaf on purpose.

```
                    I have a piece of logic to write.
                                  │
                                  ▼
        ┌──────────────────────────────────────────────────┐
        │ Must it be true of EVERY row, forever, no matter  │
        │ what code wrote it — including a migration, a     │
        │ psql session, or a bug?                           │
        └──────────────────────────────────────────────────┘
             │ yes                                  │ no
             ▼                                      ▼
   ┌────────────────────┐        ┌──────────────────────────────────────┐
   │ DATABASE           │        │ Is it about the SHAPE of a request   │
   │ CHECK / FK /       │        │ (types, required fields, ranges) —   │
   │ EXCLUDE / trigger  │        │ answerable without querying state?   │
   │ (P1, P2)           │        └──────────────────────────────────────┘
   │ + a friendly       │             │ yes                    │ no
   │ pre-check upstream │             ▼                        ▼
   └────────────────────┘   ┌──────────────────┐   ┌────────────────────────────┐
                            │ FORM REQUEST     │   │ Does it decide whether an  │
                            │ + DTO            │   │ OPERATION is legal, or     │
                            │ (P11, P12)       │   │ change the state of the    │
                            └──────────────────┘   │ world?                     │
                                                   └────────────────────────────┘
                                                        │ yes              │ no
                                                        ▼                  ▼
                                     ┌───────────────────────────┐  ┌──────────────────┐
                                     │ Does it have an actor and │  │ Is it a pure     │
                                     │ a permission?             │  │ calculation on   │
                                     └───────────────────────────┘  │ given inputs?    │
                                        │ yes           │ no        └──────────────────┘
                                        ▼               ▼                │ yes
                              ┌──────────────┐  ┌──────────────┐         ▼
                              │ ACTION       │  │ SERVICE      │  ┌──────────────┐
                              │ (P10)        │  │ (P7, P10)    │  │ VALUE OBJECT │
                              │ use case,    │  │ machinery,   │  │ or pure fn   │
                              │ authorized,  │  │ no actor,    │  │ (P16)        │
                              │ transactional│  │ every caller │  └──────────────┘
                              └──────────────┘  └──────────────┘
                                        │
                                        │  …and if another MODULE needs to know it happened:
                                        └──▶ emit a DOMAIN EVENT after commit (P23).
                                             Never write the other module's tables.

  Nothing in this tree ever lands in: a controller (P11), an Eloquent model (P10),
  a Blade/React view, a migration, or an observer.
```

**The two boundaries people get wrong.** *FormRequest vs Action* — a FormRequest that queries three
tables to decide legality is an Action wearing a validation costume, and it is unreachable from the
queue. *Action vs Service* — if it has an actor and a permission it is an Action; if it is machinery
that must hold for every caller it is a Service.

---

## Which layer enforces this invariant?

Every invariant should be enforced at the **lowest** layer that can express it, and *may* be checked
higher up for a better error message. "Both" is the correct answer surprisingly often — the database
guarantees it, the application explains it. A row whose lowest enforcement is "code review" is a
standing risk, and this table names them honestly.

Legend: **■** primary enforcement (cannot be bypassed) · □ secondary (better errors, earlier feedback)
· ○ planned or partial — a known gap · — not applicable.

| Invariant | DB constraint / trigger | DB grant / RLS | Service or Action | CI / arch test | Review only |
|---|---|---|---|---|---|
| Entry debits = credits | **■** `chk_je_balanced` | — | □ re-derived in `PostingService` | □ | — |
| Line is one-sided, money non-negative | **■** `chk_jl_one_sided` | — | □ DTO + Action | — | — |
| Tenant isolation | — | **■** RLS `FORCE` + `NOBYPASSRLS` | □ `CompanyScope` fails closed | □ catalog introspection (P3) | — |
| Ledger never mutates | **■** `trg_ledger_entries_append_only` | ○ *grants still allow UPDATE/DELETE — see P7 gap* | □ no code path exists | □ trigger test | — |
| Posted entry never mutates | **■** posted-state trigger | — | □ lifecycle Action | □ | — |
| Legal status transitions | ○ *planned trigger (P18)* | — | ■ transition map | □ | — |
| Only one writer to the ledger | — | ○ *planned dedicated role* | ■ `PostingService` | □ arch test | — |
| Money never floats | **■** `NUMERIC(19,4)` | — | □ bcmath in Actions | ■ PHPStan `numeric-string` | — |
| Gapless numbering | **■** unique index + row lock | — | ■ `JournalNumberAllocator` | □ concurrency + gap test | — |
| One operation, one transaction | — | — | ■ Action boundary | □ arch test | — |
| AI never posts | — | ○ *planned grant-less role* | ■ proposal tables only | □ | ○ **today, partly** |
| Errors are typed and coded | — | — | ■ `DomainException` | □ envelope test | — |
| Cross-module writes forbidden | — | ○ *possible: per-module role* | ■ events only | □ arch test | ○ **today** |
| No ambient privilege | — | ■ no bypass role | — | □ | ○ *one live exception — see P22* |
| DTO immutability | — | — | ■ `final readonly` | ■ arch test | — |

Rows carrying ○ are **known enforcement gaps**, restated with owners in *"The enforcement gap
register"* at the end of this document. A gap is not a failure; an *unrecorded* gap is.

---

## Index

Principle numbers are permanent identifiers. New principles are **appended**, never inserted, and
numbers are never reused or renumbered — a code review comment that says "P7" must still mean the same
thing in five years, and an ADR that cites P13 must not silently come to mean something else. The
grouping below is editorial; the numbering is append-only, for the same reason the ledger is.

| # | Principle | Group | Primary mechanism |
|---|---|---|---|
| P1 | PostgreSQL owns integrity | Integrity | CHECK / FK / EXCLUDE / trigger |
| P2 | No invariant has an off switch | Integrity | absence of bypass parameters; grep-based arch test |
| P3 | Multi-tenancy is enforced by the database | Integrity | RLS FORCE + `NOBYPASSRLS` role + catalog CI check |
| P4 | Money is never a float | Integrity | `NUMERIC(19,4)` + `numeric-string` types + static analysis |
| P5 | The ledger is append-only | Ledger | UPDATE/DELETE-rejecting trigger |
| P6 | A posted journal is immutable | Ledger | posted-state triggers + lifecycle transition table |
| P7 | There is exactly one way into the ledger | Ledger | single service + arch test (+ grants, planned) |
| P8 | Every financial operation is transactional | Ledger | one `DB::transaction`; events after commit |
| P9 | Serialize the minimum, and prove it | Ledger | scoped row locks + concurrency test suite |
| P10 | Business logic lives in Actions; models are thin | Structure | arch test on model class surface |
| P11 | Domain logic never lives in controllers | Structure | arch test on controller dependencies |
| P12 | DTOs are immutable and typed | Structure | `final readonly` + arch test + PHPStan |
| P13 | Errors are typed, coded, and aggregated | Structure | `DomainException` base + catalog + envelope test |
| P14 | Nothing is silently corrected | Structure | no coercion paths; resolution DTOs returned to the caller |
| P15 | AI drafts, humans dispose, the database enforces it | AI | separate DB role without INSERT on ledger tables |
| P16 | Every subsystem is independently testable | Evolution | constructor injection + no global state in domain code |
| P17 | Every subsystem is replaceable | Evolution | named seam interfaces at volatility boundaries |
| P18 | Lifecycle rules are data, not scattered code | Evolution | one transition map + mirroring DB trigger |
| P19 | Derived data is rebuildable from its source | Evolution | every projection ships a rebuilder + drift check |
| P20 | Documentation is executable or it is decoration | Evolution | doc-linked tests; docs live beside code |
| P21 | Every financial operation is explainable afterwards | Accountability | append-only audit + correlation id + attempt log |
| P22 | No ambient privilege — there is no `sudo()` | Accountability | no bypass role; `PlatformOperation` with a written reason |
| P23 | Cross-module communication is by domain event only | Accountability | after-commit events + outbox + arch test |

---

## P1 — PostgreSQL owns integrity

**Statement.** If a statement about the data must always be true, it is expressed as a database
constraint; the application layer may check it earlier and more kindly, but never *instead*.

**Why.** An invariant enforced in application code is enforced only on the code paths that remember to
call it. In a system with a web API, a queue worker, a scheduled command, a console tool, a data-fix
script, an AI service, a BI connector, and a future mobile backend, "every code path" is not a set
anyone can enumerate — and it grows. An invariant enforced in the database is enforced on every path
that exists now, every path added later, every path written by someone who has never read this
document, and every path that is a human typing SQL at 2am during an incident.

There is a deeper reason. Application-layer validation is a *claim about the future*: "no code will
ever write a bad row." Database-layer validation is a *fact about the present*: "no bad row exists."
The second is checkable. You can prove a `CHECK` constraint holds by the fact the table accepts rows;
you cannot prove application validation holds without auditing every writer forever. For financial
data the difference is the difference between an assertion and an audit opinion.

The corollary that surprises people: **constraints are documentation that cannot rot.** Five years
from now the comment explaining why debits must equal credits may be wrong, deleted, or in a file
nobody opens. `chk_je_balanced` will still be there, still true, still discoverable by `\d+
journal_entries`.

**What it forbids.**

- Nullable `company_id`, or any nullable column whose NULL would silently escape a filter.
- Foreign-key-like references stored as strings, integers-without-FKs, or keys inside JSON. If it
  points at a row, it is a `REFERENCES`.
- Enumerations enforced only by a PHP enum with a `VARCHAR` column behind it. The column gets a
  Postgres `ENUM` type or a `CHECK`.
- "We validate that in the service" as the *complete* answer to "what stops a bad row?"
- Storing a value that must equal a function of other columns without a `CHECK` tying them together
  (e.g. `signed_base_amount` must equal `base_debit_amount - base_credit_amount`, and does:
  `chk_le_signed`).
- Mutually exclusive classifications modelled as optional multi-valued tags. An exclusive
  classification is a `NOT NULL` CHECK-constrained column, because only that makes the "exactly one
  bucket" property structural.
- Deleting a parent row that would orphan financial history. Posted history uses `RESTRICT`, never
  `ON DELETE CASCADE`.

**How we enforce it.** A decision procedure, applied when you add any column or rule:

```
                     Must this always be true of the stored data?
                                      │
                        ┌─────────────┴──────────────┐
                       no                           yes
                        │                            │
              it is a preference,          Can it be expressed over ONE row?
              a default, or UX                       │
              → application layer          ┌─────────┴──────────┐
                                          yes                  no
                                           │                    │
                                    CHECK constraint    Over rows in one table?
                                                                │
                                                    ┌───────────┴────────────┐
                                                   yes                       no
                                                    │                         │
                                        UNIQUE / EXCLUDE / partial      Across tables?
                                        index / statement trigger              │
                                                                    ┌──────────┴─────────┐
                                                                FK expresses it      it does not
                                                                    │                     │
                                                              FOREIGN KEY      DEFERRABLE constraint
                                                              (composite FK      trigger, checked at
                                                               where it can       COMMIT
                                                               carry meaning)
```

Mechanisms in force today: `CHECK` constraints on every journal and ledger row
(`chk_je_balanced`, `chk_je_ai_confidence`, `chk_le_one_sided`, `chk_le_signed`); `UNIQUE` on the
projection key (`uq_ledger_entries_journal_line`); triggers rejecting mutation of posted lines and
rejecting AI auto-post (`trg_no_ai_autopost`); GiST `EXCLUDE` for non-overlapping date ranges. CI runs
migrations against a real PostgreSQL 16 container — never SQLite — for exactly this reason: a test
suite that runs on a database with different constraint semantics tests a different system.

**Cost we accept.** Constraints are the least ergonomic place to change a rule. A relaxation is a
migration, a deploy, and a backfill decision; you cannot toggle it in a config file at 3am. Error
messages from the database are worse than error messages from application code, so we pay for the same
rule twice — once in the DB for truth, once in the app for a decent message. And a `DEFERRABLE`
constraint trigger is materially harder to debug than an `if` statement.

**When it may be revisited.** For a specific rule, when *all* of these hold: the rule is genuinely
policy rather than integrity (it varies by tenant, by plan, or by configuration); no report, no
reconciliation, and no external filing depends on it being universally true; and expressing it in the
database would require per-tenant DDL — which P1 forbids outright, because per-tenant schema divergence
makes migrations unportable and schema review impossible. Policy that varies by tenant is **data
consumed by a fixed constraint**, never generated DDL.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Service-layer validation only | Correct only for writers that call it; unprovable; degrades to zero under time pressure. |
| ORM-level validation (model events, observers) | Bypassed by bulk operations, raw SQL, and any non-ORM writer — including our own queue tooling. |
| Validate on read ("repair on load") | Bad rows already exist; every consumer must now know the repair rules; aggregates are already wrong. |
| Periodic reconciliation job | Detects, does not prevent. Useful *in addition* (see P19), useless *instead*. |

*Engineering risks.* Constraint proliferation can make migrations slow on large tables (a new `CHECK`
validates the whole table); mitigate with `NOT VALID` + `VALIDATE CONSTRAINT`. Over-eager triggers can
become a hidden performance cliff on bulk writes; every trigger we add gets a bulk-insert benchmark.

*At scale.* Improves with size. Constraints are evaluated per-row by the same engine that writes the
row; the alternative — application checks — gets *worse* with concurrency, because two application
processes can each read a valid state, decide independently, and write a jointly invalid one.

*Effort to enforce: 3 (per rule, ongoing) · Confidence: **High**.* This is the least contestable
principle in the document; the only real debate is where the boundary between integrity and policy
falls, and the decision tree above resolves it.

---

## P2 — No invariant has an off switch

**Statement.** An invariant a caller can disable is not an invariant; QAYD ships no bypass flag, no
privilege-escalation helper, and no "skip validation" parameter.

**Why.** This is P1's necessary companion, and it is the principle most often violated by accident.
The pattern always arrives the same way: an invariant blocks a legitimate edge case, and the cheapest
fix is a parameter — `skipValidation: true`, `force: true`, `withoutEvents()`, `asAdmin()`. It works.
It ships. And now the invariant's real definition is "true unless someone passed a flag", which is not
a definition anyone can reason about.

The failure is compounding rather than immediate. Each bypass is individually justified. Their *union*
is the actual security and integrity model of the system, and nobody has ever read that union. Worse,
bypasses propagate: a helper that takes `force` gets called by another helper that takes `force`, and
the flag becomes ambient. The end state is a codebase where you cannot answer "can this ever be
violated?" without reading every call site — which is the same epistemic position as having no
invariant at all, but with the false confidence of having one.

The escalation variant is the dangerous one: a single call that suspends *all* authorization for the
rest of an operation. It converts every security boundary in the system into a convention, and because
it is short and convenient it is used far beyond the case that justified it.

**What it forbids.**

- Any parameter named or meaning `force`, `skip*`, `bypass*`, `unsafe*`, `without*Check`, `noValidate`.
- Any method that disables authorization for its caller's remaining work. There is no `->sudo()` in
  QAYD, and `is_platform_admin` exists as a GUC that is *deliberately not wired to any RLS bypass*.
- Context/config keys that a request can set to change which invariants run.
- Test-only bypasses that are reachable in production code paths. Tests construct valid states or use
  a distinct migration-owner connection; they never ask production code to relax.
- "Temporary" flags with a TODO. There is no mechanism by which they become non-temporary.

**How we enforce it.**

- **Grant-level, where it matters most.** The runtime database role is `NOBYPASSRLS` and lacks
  privileges the application must never exercise. A bypass flag in PHP cannot grant a privilege the
  connection does not have (see P3, P15).
- **Architecture test.** A test scans `app/` for method parameters and named arguments matching the
  forbidden vocabulary and fails the build with the principle reference. It is a crude check; it is
  also the check that would have caught every instance of this pattern we have seen.
- **Code review rule with a named alternative.** The reviewer's line is: *"If you think you need a
  bypass, you need one of three things — a new permission, a new policy clause, or an explicit
  `PlatformOperation` with a written reason."* A rule with a stated alternative gets followed; a rule
  that only says "no" gets worked around.
- **`PlatformOperation` as the single legitimate escape.** Genuine cross-tenant work (support,
  migration, future consolidation) runs as a *distinct database role* with narrow per-table policy
  clauses, and writes actor, reason, target tenant, and affected ids to an append-only log **in the
  same transaction** — if the audit write fails, the operation fails. It is not a flag; it is an object
  you must construct, name, and justify.

**Cost we accept.** Legitimate edge cases become more expensive. Data fixes require a migration or a
`PlatformOperation` rather than a one-line script. Onboarding imports, restatements, and
support-initiated corrections each need a designed path instead of an ad-hoc one. We accept this
because "designing the escape hatch" is precisely the work that makes the escape hatch auditable.

**When it may be revisited.** Never for the general rule. For a specific case, the correct move is
never to add a flag but to make the invariant *more precise* — if `X must always be true` blocks a
legitimate case, then `X` was the wrong statement of the invariant. Rewrite the invariant; do not add
an exception to it.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Bypass flags gated by permission | Now the invariant depends on the permission model, which is itself application-enforced. Turtles. |
| Audited bypass (`force` + log line) | Logs what happened, does not constrain what can happen; and the log is written by the code that is bypassing. |
| Time-boxed feature flag | Every long-lived flag in software history started as time-boxed. No mechanism converts it back. |
| Separate "admin" service with full privileges | Acceptable *only* as `PlatformOperation`: distinct role, narrow grants, mandatory audit. A general-purpose admin service is a bypass with a hostname. |

*Engineering risks.* Real operational pressure. During an incident, the absence of an override can
extend an outage — someone must write a migration where a flag would have taken seconds. Mitigation:
pre-build the operational paths (`PlatformOperation`, documented data-fix migration template) *before*
the incident, not during it.

*At scale.* The value grows superlinearly with team size. With three engineers, everyone knows where
the bypasses are. With thirty, nobody does, and the bypass surface becomes the system's real security
posture.

*Effort to enforce: 5 · Confidence: **High**.* The reasoning is airtight; the risk is purely
operational, and it is mitigable by preparation.

---

## P3 — Multi-tenancy is enforced by the database, never by application diligence

**Statement.** A tenant's data is isolated by PostgreSQL Row-Level Security running under a
non-superuser `NOBYPASSRLS` role; every other tenancy mechanism in the codebase is defence in depth,
never the boundary.

**Why.** Cross-tenant leakage is the one bug class that can end the company. Every other defect is a
correction, an apology, and a patch. Showing tenant A's general ledger to tenant B is a breach: it is
notifiable, it is contractual, and in the GCC market QAYD sells into — where a "tenant" is frequently a
family group or a regulated entity — it is unrecoverable commercially.

Given that asymmetry, the question is not "which mechanism is more elegant" but "which mechanism fails
safe." Application-layer scoping fails *open*: forget the `where` clause and you get everyone's rows.
Database-layer RLS fails *closed*: forget everything and you get zero rows. When one failure mode is
"a bug" and the other is "a breach", you do not choose the mechanism that is nicer to write.

The second argument is coverage. A tenancy check in the ORM protects ORM queries. RLS protects ORM
queries, raw SQL, queue workers, console commands, database console sessions, read replicas, BI tools
connecting with the app role, and endpoints written by someone who did not know the convention. The
set of things that talk to the database always grows; the set of things that remember to scope does
not.

**What it forbids.**

- A tenant table without `company_id NOT NULL`. Nullable defeats the boundary entirely: a NULL is
  invisible to an equality predicate, so a NULL-tenant row leaks *out* of every tenant's boundary
  rather than into one.
- A tenant table with RLS enabled but not `FORCE`d — table owners bypass non-forced RLS.
- Running application queries as the migration owner, a superuser, or any role with `BYPASSRLS`.
- Setting the tenant GUC with `SET` rather than `SET LOCAL`. GUCs are per *connection*; under
  transaction-level connection pooling a connection is handed to a different request mid-session, and a
  session-scoped `SET` leaks one tenant's context into another tenant's query. This is the specific
  mechanism that produces a silent cross-tenant breach, and it is invisible in single-connection
  testing.
- Resolving tenant context outside an explicit transaction, in any execution context — HTTP, queue job,
  scheduled command, or one-off script.
- Treating the Eloquent global scope as the boundary. It is layer two, and it is allowed to be
  imperfect precisely because it is not load-bearing.

**How we enforce it.**

```
   request / job / command
            │
            ▼
   ┌─────────────────────────────────────────────────────────────┐
   │ BEGIN;                                                       │
   │   SET LOCAL app.current_company_id = <id>;   ← per-transaction│
   │   …every statement…                                          │
   │ COMMIT;                     (context dies with the txn)      │
   └─────────────────────────────────────────────────────────────┘
            │
            ▼  connection: role qayd_app  (NOT owner, NOBYPASSRLS)
   ┌─────────────────────────────────────────────────────────────┐
   │ RESTRICTIVE policy: company_id = current_setting(...)::bigint │ ← the boundary (AND-ed, always)
   │ PERMISSIVE policies: own / team / all scope                   │ ← visibility within the tenant
   └─────────────────────────────────────────────────────────────┘
            ▲
            │  layer 2 (defence in depth, fails closed with 1=0)
   CompanyScope global Eloquent scope  ·  BelongsToCompany trait
```

The RESTRICTIVE/PERMISSIVE split is doing real work: RESTRICTIVE policies are AND-ed together and can
never be widened by adding another policy, so the company boundary is not something a future
permission feature can accidentally relax; PERMISSIVE policies are OR-ed, which is the right shape for
"which records inside my company may I see."

Enforcement mechanisms:

1. **Catalog-introspection CI check** — a query over `pg_class`, `pg_policy`, and `pg_attribute` that
   **fails the build** if any table carrying a `company_id` column lacks `NOT NULL`,
   `relrowsecurity`, `relforcerowsecurity`, and the named restrictive policy. This is the
   highest-leverage test in the system: it converts a convention into a mechanism, and it protects
   tables that do not exist yet.
2. **Architecture test** (`tests/Feature/Rls/BelongsToCompanyArchTest.php`) — every model whose table
   has `company_id` must use the `BelongsToCompany` trait.
3. **Isolation tests run as `qayd_app` against real PostgreSQL** in CI, not SQLite, so RLS is actually
   in force during the test.
4. **A pooler-realistic concurrency test.** Isolation must be proven with genuine concurrency against a
   real transaction-pooling configuration, because the `SET` vs `SET LOCAL` failure mode does not
   reproduce on a single connection.

**Cost we accept.** RLS is invisible in query plans until you know to look for it, and a policy
misconfiguration presents as "zero rows, no explanation" — the worst possible debugging experience.
Every query pays a small predicate cost. Cross-tenant features (platform admin, future consolidation)
require deliberate design rather than a query change. And the GUC lifecycle must be correct in *every*
execution context, which is real, ongoing discipline in queue and console code.

**When it may be revisited.** If QAYD ever moves to database-per-tenant — which trades a policy
boundary for a connection boundary and is strictly stronger — RLS becomes redundant. That trade
becomes attractive only for a small number of very large tenants with regulatory data-residency
requirements, and even then the correct shape is *hybrid*: pooled RLS for the long tail, dedicated
databases for the few. Nothing in the current design forecloses that; the tenancy boundary is a
`company_id` column either way.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Application scoping only (global query scope) | Fails open. Protects only ORM reads. Bypassed by raw SQL, jobs, imports, BI. |
| Schema-per-tenant | N schemas × M tables migrations; connection/planner overhead; cross-tenant reporting becomes painful; does not remove the need for a boundary in shared tables. |
| Database-per-tenant | Strongest isolation, but operationally heavy at QAYD's stage and hostile to the analytics and AI features that read across a tenant's own history efficiently. Revisit at enterprise scale. |
| RLS *plus* trusting `BYPASSRLS` for "internal" services | One privileged service is one breach away; the privilege is exactly what we removed. |

*Engineering risks.* The GUC lifecycle is the live risk — every new execution context (a new queue
driver, a websocket server, a batch importer) is a new opportunity to forget it. Mitigation: tenant
context is bound by the connection wrapper, not by callers, so "forgetting" means "not using the
connection", which fails closed.

*At scale.* Neutral-to-positive. Policy predicates are index-friendly (`company_id` leads every
composite index). The scaling risk is connection pooling, which is addressed above and must be
verified **before** pooling is enabled in production, not after.

*Effort to enforce: 8 (mechanisms; the CI catalog check alone is 5) · Confidence: **High** on the
model; **Medium** on operational completeness until the pooler-realistic concurrency test exists.*

---

## P4 — Money is never a float

**Statement.** Monetary values are `NUMERIC(19,4)` in PostgreSQL and decimal strings in PHP, arithmetic
goes through bcmath, and no monetary value is ever loaded into a binary floating-point type at any
point in its lifetime.

**Why.** IEEE-754 binary floating point cannot represent most decimal fractions. `0.1 + 0.2 ≠ 0.3` is
the famous example; the version that matters here is that summing ten thousand invoice lines in a float
accumulates an error that is small, real, and *unbounded above* — it grows with the number of
operations, and financial systems perform an enormous number of operations on the same data.

The consequence is not "a fraction of a fils is wrong." It is that **equality stops working**, and
double-entry accounting is built entirely on equality. Once debits and credits are floats, `debits ==
credits` is no longer a meaningful test, so you need a tolerance. Once you have a tolerance you must
choose it, and any tolerance you choose is simultaneously too loose (it hides a real one-fils error) and
too tight (it rejects a legitimate rounding at scale). Then you need epsilon-compensation logic inside
your rounding helper to fix tie-detection, and you have imported an entire category of complexity to
solve a problem you created by choosing the wrong type.

With exact decimals, `debits == credits` is exact, zero-tolerance is achievable, and the balance check
is a *proof* rather than an estimate. The scale choice — 4 decimal places — accommodates the Kuwaiti
dinar's 3 (fils) plus one guard digit for intermediate values such as unit prices and tax rates, and
precision 19 covers a total larger than any plausible tenant's lifetime turnover.

**What it forbids.**

- `float`, `double`, or `real` as the PHP type of a monetary value, at any layer — DTO, model cast,
  API resource, event payload, or AI request/response.
- `json_decode` of a monetary field without string casting. JSON numbers become PHP floats; money
  crosses JSON boundaries (API, AI service, queue payload, webhook) as a **string**.
- `+`, `-`, `*`, `/` on monetary values. Use `bcadd`, `bcsub`, `bcmul`, `bcdiv` with an explicit scale.
- `round()`, `floor()`, `ceil()` on money. Rounding is a domain decision with a stated policy and a
  recorded allocation, not a language builtin.
- Aggregating money in PHP when SQL can do it. `SUM()` over `NUMERIC` is exact; a PHP loop is where
  drift enters. This is a correctness rule first and a performance rule second.
- Comparing money with `==` on floats, or with any epsilon at all. Compare with `bccomp`.
- A "money" column typed `DOUBLE PRECISION` anywhere, including in reporting tables, analytics
  projections, and scratch tables. Reporting numbers get audited too.

**How we enforce it.**

- **Column types.** Every monetary column is `NUMERIC(19,4) NOT NULL` (defaulting to `0` where the
  domain allows a zero leg). A CI schema check asserts no column whose name matches money-ish patterns
  (`*_amount`, `*_debit`, `*_credit`, `*_balance`, `*_total`, `price`, `rate`…) is a floating type.
- **Static analysis.** PHPStan at max level with the `numeric-string` type annotation on every
  monetary parameter and return, so passing a `float` where a `numeric-string` is expected is a build
  failure rather than a runtime surprise. DTOs annotate `@param numeric-string`.
- **Model casts** map monetary columns to string, never to `decimal:` casts that round-trip through
  float, and never to `float`.
- **Domain check.** The posting engine re-derives balances with `bcadd`/`bccomp` at zero tolerance and
  refuses to write an entry that is off by one fils — which is only a meaningful check because the
  types make exactness attainable.

**The boundary rules** — where money changes representation, and what is guaranteed at each hop:

```
 PostgreSQL          PHP domain          JSON / API           AI service (Python)
 NUMERIC(19,4)  ←→  numeric-string  ←→  JSON string     ←→   Decimal (never float)
      exact            exact              exact                  exact
                                     ┌──────────────────────────────────┐
                                     │ RULE: money crosses every process│
                                     │ boundary as a STRING. A JSON     │
                                     │ number is a float on arrival.    │
                                     └──────────────────────────────────┘
 Frontend (TypeScript): money is `string` in the SDK types. Formatting for display uses
 Intl.NumberFormat on the string; arithmetic in the browser is forbidden — if the UI needs a
 total, the API returns the total.
```

**Cost we accept.** Arithmetic is verbose: `bcadd($a, $b, 4)` where `$a + $b` would read better.
Developers coming from other stacks find it alien and will reach for `+` at least once. bcmath is
slower than native floats — irrelevant at our volumes, where the database does the heavy aggregation
anyway. And explicit scale arguments are a real source of subtle bugs if omitted, so scale is always
passed explicitly and never left to the ini default.

**When it may be revisited.** If PHP gains a native, exact, first-class decimal scalar with operator
support, we migrate the *arithmetic mechanism* (bcmath → native decimal) while keeping the principle
unchanged. The principle itself — exact decimal representation end to end — is not revisitable; it is
downstream of how binary floating point works, and that will not change.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Integer minor units (store fils as `BIGINT`) | Genuinely exact and fast, and a defensible choice. Loses on multi-currency (different currencies have different minor-unit exponents, so the scale becomes a per-row property), on rates and percentages that legitimately need sub-minor precision, and on human-readability of the raw data during an audit. `NUMERIC` gives exactness *and* self-describing values. |
| Float with tolerance | Requires choosing a tolerance; every tolerance is simultaneously too loose and too tight; destroys zero-tolerance balance proofs. |
| Float in memory, decimal at rest | The drift happens in memory. Persisting exactly a value that was computed inexactly persists the error precisely. |
| A money value object wrapping a float | The wrapper is good practice; the float inside it is still a float. |

*Engineering risks.* The realistic failure is a boundary leak — a JSON payload, a third-party SDK, or a
CSV import that turns a string into a float in transit. Mitigation: the money-column CI check plus
`numeric-string` static typing at every public boundary, including the AI service contract.

*At scale.* Strictly better. Exact aggregation in SQL over `NUMERIC` scales with indexes; float
aggregation in application memory does not, and gets *less* accurate as data grows.

*Effort to enforce: 5 · Confidence: **High**.* Nothing about this is contestable; the only open
question is `NUMERIC` vs integer minor units, and the multi-currency argument settles it.

---

## P5 — The ledger is append-only

**Statement.** `ledger_entries` accepts `INSERT` and nothing else: no `UPDATE`, no `DELETE`, ever, by
any role, including the table owner.

**Why.** The general ledger answers one question that nothing else can: *what did the books say on
date D?* Every audit, every restatement, every tax filing, and every dispute reduces to that question.
A mutable ledger cannot answer it, because the current state has overwritten the historical one — you
can only report what the books say *now*, and assert that they used to say something else.

Immutability converts that from an assertion into a fact. If rows are only ever appended, the state at
any past instant is `WHERE created_at <= D` (or `WHERE posted_at <= D`), which is a query rather than a
belief. Nothing has to be trusted.

Three consequences follow, and each is worth more than the constraint costs:

1. **Cached aggregates become trustworthy.** An incremental rollup over a mutable table is a lie
   waiting to happen — any missed invalidation silently corrupts it, and you cannot tell by looking. A
   rollup over an append-only table is *monotonic*: it can only ever be incremented, so a trigger
   maintaining `account_period_balances` cannot drift in the way that matters. This turns a trial
   balance from a full scan of the largest table into a small indexed read — the single largest
   scalability win available to this system, and it is available **only because** of this principle.
2. **A tamper-evidence hash chain becomes cheap.** Chaining hashes over rows that never change is
   trivial and never needs recomputation. Chaining over mutable rows requires re-hashing descendants on
   every edit, which is both expensive and self-defeating.
3. **Partitioning becomes possible.** An append-only table partitioned by `(company_id, period)` has
   no cross-partition updates and no row movement. A mutable ledger cannot be partitioned this way,
   because an update can change the partition key.

The deepest argument is conceptual: **a ledger is a log of events, not a store of state.** State is
derived by folding the log. Systems that treat the ledger as state and correct it in place are storing
the fold and discarding the log — which is exactly backwards, and is why they end up unable to explain
their own numbers.

**What it forbids.**

- Any `UPDATE ledger_entries` or `DELETE FROM ledger_entries`, including in migrations, data fixes,
  seeders, and tests.
- Storing mutable state *on* a ledger row. This is the subtle and important one: reconciliation status,
  residual amounts, matching group ids, and settlement flags all feel natural as ledger columns and are
  forbidden there. Putting mutable matching state on the ledger row is the single decision that forces
  a ledger to become mutable, and it does so gradually and invisibly. Matching state lives in **side
  tables keyed to `ledger_entry_id`**.
- Correcting a ledger row. Corrections are new rows produced by a reversing journal entry (P6).
- "Soft delete" columns (`deleted_at`, `is_active`) on the ledger. A ledger row that can be hidden is a
  ledger row that can be mutated, and every consumer must now remember the filter.
- Rebuilding the ledger by truncate-and-replay in production. The ledger is the source; projections
  derived *from* it are rebuildable (P19), the ledger itself is not.

**How we enforce it.**

- **`trg_ledger_entries_append_only`** — a trigger that raises on `UPDATE` and `DELETE` regardless of
  role. Not a grant (grants can be re-granted by an owner in a migration), not a policy — a trigger, so
  even the owner connection running a migration is refused. Removing that protection would require a
  migration that explicitly drops the trigger, which is a reviewable, greppable, alarming diff.
- **`uq_ledger_entries_journal_line`** — one ledger row per posted journal line, making the projection
  exactly 1:1 and making a duplicate post impossible at the database level rather than merely unlikely.
- **A test that asserts the trigger fires.** The protection is itself tested (`JournalTriggersTest`),
  because an untested guard is a guard that silently stops working during a refactor.

**Cost we accept.** Storage grows monotonically and is never reclaimed by correction — a heavily
corrected book stores every version. Genuine mistakes (a typo in a description on a posted row) cannot
be tidied; they are corrected by reversal, so the ugly history is permanent and visible. Some
operations that would be a single `UPDATE` become an insert plus a compensating insert, which is more
code. And a subsystem that naturally wants mutable per-row state (reconciliation is the canonical case)
must be designed with side tables from day one, which is more design work up front.

**When it may be revisited.** For the ledger, effectively never — it is the property the product's
credibility rests on. Two adjacent questions *are* open and should be treated as engineering work
rather than principle changes: (a) **retention and archival** — old partitions may be moved to cold
storage, which is not mutation; and (b) **legally-mandated erasure** of personal data appearing in a
free-text field on a ledger row. The correct answer to (b) is to keep personal data *off* ledger rows
by design (reference a party id, never a name), so the conflict does not arise; where it has already
arisen, redaction is a documented `PlatformOperation` that preserves the hash chain by redacting into a
side table, never by editing the row. See "Principles in tension", §T5.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Mutable ledger + audit log of changes | Two sources of truth for history, kept in sync by convention. The audit log is written by the code doing the mutation, so a bug in that code corrupts both. |
| Soft delete / versioned rows (`valid_from`/`valid_to`) | Every consumer must remember the temporal filter; forgetting it is silent and produces plausible wrong numbers. Append-only makes the naive query correct. |
| Event sourcing with full replay as the read path | Replaying full history to answer a balance query does not scale; systems that have tried it end up batching the replay to avoid running out of memory. The projection *is* the read path here, and it is already append-only. |
| Append-only enforced by grants only | An owner-role migration can re-grant. A trigger cannot be accidentally lifted. |

*Engineering risks.* Storage growth and the resulting index/vacuum behaviour on very large tenants;
addressed by partitioning, which this principle makes possible. The second risk is design pressure:
new subsystems will *want* to put mutable state on ledger rows, and the pressure is strongest when the
subsystem is late. The mitigation is that the trigger refuses, loudly, at the first attempt.

*At scale.* Strongly positive. Append-only is the precondition for partition pruning, trustworthy
incremental rollups, and a maintainable hash chain — the three things that keep reporting fast as data
grows.

*Effort to enforce: 3 (already built) · Confidence: **High**.*

---

## P6 — A posted journal is immutable

**Statement.** Once a journal entry is posted, neither the entry nor its lines may be modified,
deleted, or returned to draft; the only correction is a new, separately posted reversing entry.

**Why (and how this differs from P5).** P5 is about the *ledger projection* — the derived,
append-only record that balances are computed from. P6 is about the *source document* — the journal
entry a human or agent authored, with its narrative, its attachments, its approval trail, and its
number. Both are needed, and neither implies the other:

```
     JOURNAL ENTRY  (source document, human-authored)          LEDGER ENTRIES (projection)
     ┌───────────────────────────────────────────┐             ┌──────────────────────────┐
     │ draft → submitted → approved → POSTED     │  ──post──▶  │ 1 row per posted line    │
     │  ▲          mutable          │  frozen    │             │ append-only forever      │
     │  └── edits allowed here ─────┘            │             │ (P5)                     │
     └───────────────────────────────────────────┘             └──────────────────────────┘
             P6 governs THIS transition                        P5 governs THIS table
             (mutable → permanently frozen)                    (never mutable at all)
```

A journal entry is *deliberately mutable while it is a draft* — that is the whole point of a draft, and
it is where an AI proposal gets corrected by a human. If P5 alone existed, the entry could still be
edited after posting while the ledger rows stayed fixed, and the source document would then contradict
the projection: the entry would say one thing, the ledger another, and the audit trail would show no
change. If P6 alone existed, the ledger could be recomputed and history would be lost.

Why immutability at the *moment of posting* specifically: posting is the point at which the entry
acquires a permanent, gapless number, enters a fiscal period, becomes part of a filed or filable
balance, and becomes visible to other parties. Everything downstream — statements, tax returns, audit
opinions — is a claim about a specific set of posted entries. If that set can change retroactively,
every downstream artefact silently becomes a claim about a set that no longer exists.

There is an accounting-doctrine argument too, and it is not merely convention: correction-by-reversal
preserves *both* facts — that an error was made, and that it was corrected. Correction-in-place
preserves only the second. The first is the one an auditor is looking for, and the one that
distinguishes an honest mistake from a concealed one.

**What it forbids.**

- `UPDATE`/`DELETE` on `journal_lines` of a posted entry — enforced by trigger, not by service code.
- Any transition out of `posted` other than to a terminal state. There is **no un-post path** in QAYD.
  A "post → draft → edit → repost" cycle is forbidden, and forbidden at the database level, because it
  is the pattern that most convincingly looks like a fix and most reliably destroys the audit trail.
- Reversal as a mutation. A reversal is a new entry with its own number, its own date, its own
  approval, and a `reversal_of_entry_id` link, posted through the same engine as everything else.
- Reversal without a reason. `reversal_reason` is `NOT NULL` and is a queryable column, never text
  interpolated into a reference string, because "show me every reversal for reason X in Q3" is a
  question auditors actually ask.
- Reversing the same entry twice in full, or an entry reversing itself, or a reversal cycle.
- Silently changing a posted entry's date because the requested date is in a closed period. See P14.

**How we enforce it.**

| Rule | Mechanism |
|---|---|
| Lines of a posted entry are frozen | trigger on `journal_lines` rejecting UPDATE/DELETE when parent is posted |
| No un-posting | status-transition trigger (P18) rejecting `posted → non-terminal` |
| Reversal is a real posted document | reversal goes through `JournalEntryPostingService`; no special path exists |
| No self-reversal | `CHECK (reversal_of_entry_id <> id)` |
| No double full-reversal | partial unique index on `(reversal_of_entry_id) WHERE reversal_kind = 'full'` |
| No reversal cycles | cycle-rejecting trigger on insert |
| Reason is captured | `reversal_reason NOT NULL`, `reversed_by_user_id NOT NULL` |

**Cost we accept.** The user experience is worse than "edit". A typo in a memo on a posted entry
requires a reversal and a re-post — two documents where the user wanted zero — and users will ask for
an edit button repeatedly. Books accumulate reversal pairs that are visually noisy in a naive listing.
And every correction consumes a journal number, so the numbering sequence reflects activity rather than
net documents.

We accept this because the alternative degrades continuously: an edit that is allowed "just for
memos" becomes an edit allowed for dates, then for accounts, then for amounts, and each step is
individually reasonable. The line has to be somewhere, and the only defensible place is *posted*.

Mitigation is a UX obligation, not a principle exception (see §T2): make the correction flow fast and
comprehensible — one action, pre-filled, clearly labelled "correct this entry" — and present a
corrected entry and its reversal as a single collapsed unit in listings.

**When it may be revisited.** For a narrow class of *non-accounting* metadata that provably cannot
affect any report, filing, or balance — internal tags, attachment links, collaboration comments — the
correct design is not to relax P6 but to move that data **off the entry** into a side table where it
was always mutable. If a request to "edit a posted entry" survives that reframing, it is a request to
change the accounting, and the answer is a reversal.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Edit-with-version-history | Requires every downstream artefact to name a version; nobody does; reports silently mean "latest". |
| Un-post → edit → repost | Transiently returns a numbered, period-assigned, possibly-filed document to draft. Everything derived from it is briefly and invisibly wrong. |
| Allow edits until period close | Moves the line from a per-document event to a calendar event, so the same document is mutable or not depending on the date it is looked at. Harder to reason about, not easier. |
| Immutable entries, mutable line descriptions | The camel's nose. Descriptions appear on statements and in tax documentation. |

*Engineering risks.* Product pressure, not technical risk. The technical risk is subtler: a poorly
designed reversal flow leads users to *avoid* posting (leaving entries in draft to keep them editable),
which defeats the purpose — a book full of drafts is a book that does not balance. Monitor draft age
as a product health metric (P21).

*At scale.* Neutral technically; positive operationally, because support cannot be asked to "just fix
it in the database" — there is no such operation, so the escalation path is a designed correction
instead.

*Effort to enforce: 8 (triggers + reversal hardening) · Confidence: **High** on the principle;
**Medium** on the reversal UX being good enough that users do not route around it.*

---

## P7 — There is exactly one way into the ledger

**Statement.** `JournalEntryPostingService` is the only code in the platform that writes a posted line
to the general ledger; every caller — human action, event listener, scheduled job, importer, approved
AI draft — goes through it, and no alternative path exists.

**Why.** The posting engine is where the system's non-negotiable invariants are enforced *in a specific
order*: lock the entry, re-derive the balance from the lines (never from cached header totals) at zero
tolerance in both currencies, resolve the open fiscal period, verify every target account is postable,
allocate the permanent number, mark posted, project the ledger rows. Each step depends on the previous
one having happened, and several are only correct **because** they happen under the same lock in the
same transaction.

A second write path does not merely duplicate that logic — it duplicates it *incompletely*, and the
part it omits is invisible until the specific condition arises months later. The second path is always
written for a good reason (a bulk import is too slow, a migration needs to backfill, an integration has
a special case) and always omits something (the period check, the account-status check, the ordering).

The single-writer property is also what makes the system *auditable as a whole*. With one writer, the
answer to "under what conditions can a line reach the ledger?" is a single file you can read in one
sitting. With two, the answer is the union of two files plus the question of which one ran, and with
five nobody attempts the question at all.

Finally, it is what makes new features cheap. Reversals, opening balances, recurring entries, FX
revaluation, and closing entries are all *just entries*. Each of them inherits period locking,
numbering, balance validation, audit, and the hash chain for free by going through the one path. A
feature that builds its own posting path inherits nothing and must reimplement everything — which is
how a system ends up with five slightly different definitions of "posted".

**What it forbids.**

- Any `INSERT INTO ledger_entries` outside the posting engine — including in migrations, seeders,
  importers, and tests. Test fixtures create posted state by *posting*, which has the pleasant side
  effect that the posting path is exercised by every test that needs posted data.
- Any code that sets `journal_entries.status = 'posted'` directly.
- A "fast path" for bulk posting that skips validation. Bulk posting means calling the engine in a loop
  inside one transaction, or a batched variant of the engine that runs the *same* checks — never a
  different set of checks.
- Allocating a journal number anywhere but inside the engine's transaction, or as a side effect of
  something else. Numbering is an explicit, named, independently testable step, not an ORM
  computed-field trigger — because a number allocated as a side effect is a number nobody can reason
  about the ordering of.
- Modules writing each other's tables. A module that needs an entry posted raises an event or calls the
  Action; it does not reach into accounting's tables. This is the general form of the same rule (P23).

**How we enforce it.**

```
   human UI    integration    scheduled job    event listener    AI-drafted entry (approved)
       │            │               │                │                   │
       └────────────┴───────┬───────┴────────────────┴───────────────────┘
                            ▼
                  PostJournalEntryAction          ← authorization, actor, orchestration
                            │
                            ▼
              JournalEntryPostingService          ← THE gate. all invariants, one transaction
                            │
              ┌─────────────┴─────────────┐
              ▼                           ▼
      journal_entries (posted)     ledger_entries (append-only, P5)
                            │
                            ▼ AFTER COMMIT
                  accounting.journal.posted        ← the only outward signal (P8)
```

- **The trigger, today.** Be precise about what actually holds: `ledger_entries` inherits
  `SELECT, INSERT, UPDATE, DELETE` for the runtime role from `ALTER DEFAULT PRIVILEGES`, and still
  carries vestigial `ledger_entries_tenant_update` / `_tenant_delete` RLS policies copied from the
  uniform tenant template. The append-only guarantee therefore rests **entirely** on
  `trg_ledger_entries_append_only`. That trigger is strong — it fires for the owner too — but it is a
  single point of enforcement, and the privilege layer currently says the opposite of what the trigger
  does. **Gap G-1:** mirror what `audit_logs` already does — `REVOKE UPDATE, DELETE ON ledger_entries`
  from the app role and drop the two unreachable policies, so the grants, the policies, and the trigger
  all tell the same story. Effort 1. A reader should never have to know which of three mechanisms is
  the real one.
- **A dedicated posting role.** Narrowing further, so that only a posting role may `INSERT` into
  `ledger_entries`, is the hardening step that would make P7 enforceable by the database rather than by
  an architecture test. Recorded as **Gap G-2** so it is not forgotten.
- **Architecture test.** A test asserts that `LedgerEntry` is referenced for writing by exactly one
  class, and that no file outside `app/Services/Accounting/` contains an insert against
  `ledger_entries`.
- **Design convention with teeth.** The engine's docblock states the invariant order explicitly, and
  changes to that ordering require a reviewer who can explain why each step's position is safe.

**Cost we accept.** The posting engine is a bottleneck by design — every accounting feature must
negotiate with it, and it will accumulate parameters and branches as the domain grows. It is the file
most likely to become large, and it must be defended against becoming a god-object: complexity that
belongs to a *specific* entry kind goes into that kind's Action or into a seam (P17), and only genuinely
universal invariants live in the engine. Reviewing changes to it requires understanding the whole
posting model, so it is a bus-factor concentration point and must be the best-documented file in the
codebase.

**When it may be revisited.** If a second *fundamentally different* posting semantic appears — a
distinct legal document class with genuinely different invariants, not just different data — the right
move is to extract the shared invariant core and have two thin engines over it, preserving "exactly one
way *per semantic*". The failure mode to avoid is discovering the second path already exists because
someone needed it on a Friday.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Multiple posting services sharing a validator | The shared part is whatever both authors remembered to share; ordering and locking are exactly what does not get shared. |
| Database trigger as the only enforcement, many writers | Triggers cannot express the full ordered protocol (lock → derive → resolve period → allocate number) and cannot produce good errors. Triggers are the *backstop*, not the gate. |
| Repository pattern with a `save()` on the ledger model | Makes writing the ledger look ordinary. Writing the ledger must never look ordinary. |

*Engineering risks.* Concentration risk (bus factor, file size) and contention (see P9 — the engine
currently holds a lock that is broader than necessary, which is a known open defect). Mitigation:
seams keep the engine small; the concurrency fix narrows the lock.

*At scale.* The single writer is not a throughput ceiling — it is a code path, not a mutex. Throughput
is bounded by what it *locks*, which is P9's subject.

*Effort to enforce: 3 (arch test + grants; the engine exists) · Confidence: **High**.*

---

## P8 — Every financial operation is transactional

**Statement.** A financial operation either happens completely or not at all, inside one database
transaction; the only things allowed to cross that boundary are effects published **after** commit.

**Why.** Partial financial state is worse than no state, and worse than an error. A journal entry that
is marked posted but whose ledger rows are missing produces a trial balance that does not balance — and
because nothing failed loudly, it is discovered by an accountant weeks later, at which point the
question "what else is missing?" has no bounded answer. Recovering from partial writes requires knowing
exactly which step failed, which is precisely the information a crash does not leave behind.

Atomicity gives us a stronger property than "no half-writes": it gives us **a single point of
consistency**. Because the entire post is one transaction, any invariant that must hold across multiple
tables can be checked inside it — including with `DEFERRABLE` constraints evaluated at COMMIT, which is
the only way to enforce "these N rows are jointly valid" without ordering the inserts by hand.

The after-commit rule for side effects is the non-obvious half, and it is where most systems get it
wrong in both directions:

- **Publishing inside the transaction** means a consumer can act on an event for a transaction that
  subsequently rolls back. The email is sent, the webhook is delivered, the downstream ledger is
  updated — for a posting that never happened. These effects are not transactional and cannot be
  rolled back.
- **Doing external work inside the transaction** (an HTTP call to the AI service, an email send, a
  file upload) holds database locks for the duration of a network round trip. A slow third party
  becomes a database availability incident, and a timeout becomes an ambiguous outcome.

So the boundary is: everything that must be consistent goes inside; everything that touches the outside
world goes after; and the handoff between them must not lose messages.

**What it forbids.**

- Multiple transactions in one logical financial operation. Not "post, then in a second transaction
  write the audit row."
- HTTP calls, queue dispatches that execute immediately, email sends, file writes, or any external I/O
  inside a financial transaction.
- Publishing a domain event before commit, or from inside the service doing the writing. Events are
  emitted by the wrapping Action after the transaction returns — which is why
  `accounting.journal.posted` is raised by `PostJournalEntryAction`, not by
  `JournalEntryPostingService`.
- Catching an exception mid-transaction and continuing. If an invariant failed, the operation failed;
  "log and continue" inside a financial transaction is how partial state is created deliberately.
- Nested transactions used to allow a sub-operation to fail independently. Savepoints exist and have
  legitimate uses, but not for making a financial sub-step optional.
- Long-running work inside the boundary: no AI inference, no PDF generation, no report materialization.

**How we enforce it.**

```
  Action (orchestrates, authorizes)
    │
    ├── DB::transaction(function () {              ─┐
    │      lock …                                    │
    │      validate …                                │  ATOMIC REGION
    │      write journal_entries                     │  · no I/O
    │      write ledger_entries                      │  · no events
    │      write audit_logs                          │  · no queue dispatch
    │      (DEFERRABLE constraints fire at COMMIT)  ─┘
    │   });
    │
    │   ═══════════ COMMIT ═══════════  ← the durability line
    │
    └── event(new JournalPosted(...))    ← after commit only
             │
             ▼
        outbox row was written INSIDE the txn ──▶ relay publishes ──▶ consumers
        (so the event cannot be lost if the broker is down,
         and cannot fire if the transaction rolled back)
```

- Every Action that writes financial data wraps exactly one `DB::transaction` on the RLS-enforced
  tenant connection.
- Events use Laravel's after-commit dispatch, and the durable path is a **transactional outbox**: the
  event row is written inside the transaction and relayed afterwards, which is the only construction
  that is both "never fires for a rolled-back transaction" and "never lost because the broker was
  down".
- An architecture test asserts no HTTP client, mailer, or filesystem facade is referenced from inside
  `app/Services/Accounting/`.
- Audit writes for security-relevant operations happen *inside* the transaction deliberately: if the
  audit row cannot be written, the operation must not succeed.

**Cost we accept.** Transactions hold locks, and long transactions hold them longer — so this principle
forces us to keep financial operations short, which is usually good and occasionally awkward (a
5,000-line opening-balance import must be chunked into batches rather than run as one heroic
transaction). The outbox is extra machinery: a table, a relay, and at-least-once delivery semantics
that every consumer must handle idempotently. And "after commit" means there is a real window where the
data is committed but the notification has not arrived — consumers must be designed for eventual, not
immediate, consistency.

**When it may be revisited.** If a workflow genuinely spans services in a way that cannot be one
database transaction — a cross-service settlement, say — the answer is a saga with explicit
compensating actions and a persisted state machine, *not* a relaxation of atomicity within each step.
Each local step stays atomic; the composition becomes explicitly compensable. That is a design change,
not a principle change.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Eventual consistency inside one service | Buys nothing here — it is one database. Pays reconciliation costs for no scaling benefit. |
| Events published inside the transaction | Consumers act on rolled-back work. Unfixable after the fact. |
| Events published after commit with no outbox | Simple and mostly fine, and it is where we are today. Loses the event if the process dies between commit and publish — for accounting, that is a real gap. The outbox is a small, bounded upgrade. |
| Two-phase commit with external systems | Operationally heavy, poorly supported by the systems we integrate with, and turns their outages into our outages. |

*Engineering risks.* Lock-hold time under load (P9). Outbox relay lag if the relay is unhealthy —
requires monitoring (P21) and an alert on outbox age, or events silently stop and nobody notices
because nothing errors.

*At scale.* Fine, provided transactions stay short. The risk is not transaction *count* but transaction
*duration*; keeping external I/O out is what keeps duration bounded.

*Effort to enforce: 5 (outbox: 5; the rest exists) · Confidence: **High**.*

---

## P9 — Serialize the minimum, and prove it

**Statement.** Locks are scoped to the exact resource whose invariant requires serialization, held for
the shortest possible time, acquired in a documented global order, and every concurrency claim is backed
by a test that actually runs concurrently.

**Why.** Concurrency bugs in financial systems are the worst bugs we can ship: they are rare,
non-deterministic, invisible in single-threaded tests, and produce *plausible* wrong numbers rather than
errors. Two simultaneous posts producing the same journal number, or a payment reconciled twice against
the same invoice, will not throw — they will quietly create an inconsistency that surfaces in an audit.

That argues for locking. But over-locking is its own failure, and it is the one we have actually
shipped. A lock that is broader than the invariant it protects converts a correctness mechanism into a
throughput ceiling: every write in the scope queues behind one row. The cost is invisible in
development (where concurrency is one) and appears in production as latency under load — at which point
the "fix" is often to weaken the locking, which reintroduces the correctness problem.

The discipline is therefore two-sided, and each side has a concrete test:

- *Is this lock necessary?* Name the invariant it protects and the interleaving that violates it without
  the lock. If you cannot write that interleaving down, the lock is superstition.
- *Is this lock sufficient?* Write the concurrent test. Not a test that calls the function twice — a
  test that runs it from two connections with real contention.

**Known open violation, recorded honestly.** The posting engine currently acquires `FOR UPDATE` on the
**fiscal-calendar row** while posting. Reading a date range needs no serialization — the calendar is
effectively immutable during a post — so this lock protects nothing that is not already protected, and
it serializes *every concurrent post within a company-year* behind a single row. The invariant that
genuinely requires serialization is gapless number allocation, and the number allocator already achieves
it by locking its own sequence row. **Fix: replace the calendar lock with a plain read; keep the
sequence-row lock; ship with concurrency tests proving gaplessness survives — including a test where a
random subset of transactions rolls back after allocation and the surviving numbers must still be
contiguous.** Effort 3. This is recorded here rather than only in a backlog because the principle
document should be honest about where the code does not yet meet it.

**What it forbids.**

- Locking a parent/aggregate row to protect a child-row invariant.
- Table-level locks in application code. Ever.
- Acquiring multiple locks in an order that varies by code path — that is a deadlock, scheduled.
- Claiming a concurrency property without a concurrent test. "It's in a transaction so it's fine" is
  not an argument; transactions provide isolation, and the default isolation level does not prevent
  every anomaly.
- Application-side check-then-act on a uniqueness or capacity invariant (`SELECT count`, decide,
  `INSERT`). Use a unique index, an exclusion constraint, or a deferred constraint trigger and let the
  database arbitrate.
- Holding a lock across any external call (a restatement of P8).

**How we enforce it.**

| Requirement | Mechanism |
|---|---|
| Locks are narrow | code review against the named-invariant test above |
| Lock order is global | one documented ordering (company → entry → sequence → line), asserted in review |
| Uniqueness is arbitrated by the DB | unique / partial-unique / EXCLUDE constraints, not `SELECT count` |
| Cross-row sums are arbitrated by the DB | `DEFERRABLE` constraint triggers (e.g. `SUM(matched) ≤ original`) |
| Claims are tested | a concurrency test suite as a first-class deliverable per subsystem, run in CI against real PostgreSQL |

The concurrency suite is the load-bearing part. Its tests are: N parallel posts produce N contiguous
numbers; N parallel posts with a random rollback subset still produce contiguous numbers among
survivors; parallel reconciliation of the same entry over-matches zero times; parallel period-close and
post produce either a clean post or a clean rejection, never a post into a closed period.

**Cost we accept.** Concurrency tests are slow, occasionally flaky, and harder to write than unit
tests — they need real connections and real contention, so they cannot run on an in-memory database.
Narrow locking requires thinking about interleavings, which is genuinely difficult and cannot be
delegated to a framework. And some invariants get harder to express: a deferred constraint trigger is
more work than `if (sum > max) throw`.

**When it may be revisited.** If posting throughput ever needs to exceed what a single sequence row can
serialize, the correct move is to *change the invariant* — for example, per-branch or per-journal
numbering scopes, which multiply the number of sequence rows without weakening gaplessness within a
scope. Never to relax gaplessness, which is a regulatory expectation in this market, and never to move
the check into application code.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Optimistic concurrency everywhere (version columns) | Correct and cheap for *document editing* (we use it for drafts) but wrong for allocation: retrying a number allocation under contention produces gaps or livelock. |
| Advisory locks | Invisible to the schema, easy to leak across pooled connections, and not tied to the row they protect. Row locks are self-documenting. |
| `SERIALIZABLE` isolation globally | Elegant in principle; in practice pushes the failure into serialization-failure retries that every caller must handle correctly, and the retry logic becomes the new bug surface. Reserve for specific operations. |
| Single-threaded posting queue | Trades a correctness mechanism for a throughput ceiling and a new availability dependency. |

*Engineering risks.* Under-locking is silent corruption; over-locking is a latency incident. Both are
real, and the only defence is the test suite, which must be maintained even though it is the least
pleasant suite to maintain.

*At scale.* This is *the* scaling axis for the write path. Narrow, well-ordered locks scale to many
concurrent posters per tenant; a single broad lock does not scale past one.

*Effort to enforce: 8 (3 for the known fix, 5 for the suite) · Confidence: **High** on the principle;
**High** on the specific defect diagnosis; **Medium** on there being no other over-broad locks not yet
found — the suite is what would tell us.*

---

## P10 — Business logic lives in Actions; models are thin

**Statement.** Every business operation is a single-purpose Action class with one public method; models
describe data — columns, casts, relations, scopes — and decide nothing.

**Why.** The alternative is the fat model, and it fails for a specific structural reason rather than an
aesthetic one: **a model is organized by data, but business operations are organized by use case, and
the two do not align.** "Post a journal entry" touches the entry, its lines, the ledger, the number
sequence, the fiscal calendar, the audit log, and the account table. Whichever model you put it on, it
is in the wrong place for most of what it touches — and it will be found there only by someone who
already guessed which model won.

Three consequences follow:

1. **Discoverability inverts.** With Actions, `app/Actions/Accounting/` *is* the list of things the
   system can do. A new engineer reads directory listings and learns the domain. With fat models,
   capabilities are distributed across model classes by data affinity, and the only way to enumerate
   them is to read every model.
2. **Dependencies become explicit.** An Action declares what it needs in its constructor. A model
   method reaches for whatever it can see — facades, globals, other models via relations — so its true
   dependency set is unknowable without reading its whole body and everything it calls.
3. **Testing becomes proportionate.** An Action is instantiated with test doubles for its collaborators
   and called. A fat-model method needs a hydrated model, which needs a database, which needs a schema
   and a tenant context — so every business-logic test becomes an integration test, the suite slows
   down, and the slow suite stops being run.

The deeper reason is lifecycle. Models are the longest-lived, most-depended-upon classes in the
codebase; every part of the system touches them. Business rules are the *shortest*-lived — they change
with regulation, pricing, and product. Putting volatile logic inside the most-depended-upon class means
every rule change ripples through the widest blast radius in the system. Actions invert that: the
volatile thing is the leaf.

**What "thin" means precisely.** A model may contain:

| Allowed on a model | Forbidden on a model |
|---|---|
| Table, key, connection, fillable/guarded | Any method that writes another table |
| Casts, accessors/mutators that are pure formatting of the row's own data | Anything that decides *whether* an operation is permitted |
| Relations (`hasMany`, `belongsTo`, …) | Anything that orchestrates more than one write |
| Query scopes (reusable `where` fragments) | Anything that dispatches an event, job, or notification |
| Constants and enum bindings for its own columns | Anything that calls an external service |
| Trait application (`BelongsToCompany`) | Multi-step lifecycle methods (`$entry->post()`) |
| Simple derived predicates over its own loaded attributes (`isPosted()`) | Anything a test cannot exercise without other tables |

The operative test: **if a method needs data from another table to decide something, it does not belong
on the model.** `$entry->isPosted()` is a comparison against a loaded column — fine. `$entry->post()`
needs the calendar, the sequence, and the ledger — an Action.

**What it forbids.**

- Model methods that write to any table other than their own row.
- Model events / observers containing business rules. Observers are permitted only for mechanical
  concerns (stamping `created_by`, applying the tenant scope) — never for decisions, because an
  observer is invisible at the call site and fires on paths the author never considered.
- Actions with more than one public execution method. One Action, one operation. An Action with
  `handle()` and `handleBulk()` is two Actions.
- Actions that accept arrays. Actions accept DTOs (P12), so their contract is typed and stable.
- Static business methods anywhere. Static methods cannot be substituted in a test and hide their
  dependencies by construction.
- Business logic in a `Service` class that is really an Action with a different name. Services exist
  for *shared, stateless domain mechanisms* used by multiple Actions (the posting engine, the number
  allocator); an operation a user can invoke is an Action.

**How we enforce it.**

- **Architecture tests.** Models in `app/Models/` may not reference `DB::`, the event dispatcher,
  queue facades, HTTP clients, or other Actions. Classes in `app/Actions/` expose exactly one public
  method besides the constructor.
- **Directory structure as contract.** `app/Actions/<Context>/<Verb><Noun>Action.php`. The naming is
  enforced by a test, because the directory listing is the domain documentation (P20) and a
  misnamed file makes the listing lie.
- **Constructor injection only.** Actions receive collaborators through the constructor; an Action
  that resolves a dependency from the container mid-method is failing P16 and is rejected in review.

**Cost we accept.** More files. A CRUD operation that would be three lines on a model becomes an Action
class, a DTO, and a test — which feels like ceremony for simple cases, and *is* ceremony for simple
cases. We accept it because the boundary between "simple enough to skip the ceremony" and "not" is
exactly where the fat model starts, and it is not defensible anywhere. Uniformity is worth more than
the saved lines: an engineer who knows one Action knows all of them.

**When it may be revisited.** For genuinely trivial, non-financial CRUD in a peripheral context (user
preferences, UI settings), a thinner pattern is defensible and costs nothing. It is never revisitable
for anything touching money, tenancy, permissions, or audit — which is most of QAYD.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Fat models (Active Record with behaviour) | Organizes by data while operations organize by use case; volatile logic in the most-depended-upon class; forces integration tests for unit concerns. |
| Fat service classes (one `AccountingService`) | Becomes a god object; dependencies are the union of everything any method needs, so every test constructs everything. |
| Command bus with handlers | Functionally equivalent to Actions plus indirection. The indirection buys middleware (retry, logging) we do not currently need. Adopt only if we need bus-level cross-cutting behaviour. |
| Domain model with rich aggregates (DDD) | Genuinely strong for complex invariants — but its natural home is an ORM designed for it, and it duplicates enforcement we deliberately put in the database (P1). We take the parts that pay: value objects (P12), explicit boundaries (P17). |

*Engineering risks.* Action proliferation without organization becomes its own navigability problem;
mitigated by context subdirectories and strict naming. A subtler risk is Actions calling Actions in deep
chains, which reintroduces hidden orchestration — permitted one level deep for genuine composition,
flagged in review beyond that.

*At scale.* Strongly positive for team size: Actions are small, single-purpose, and independently
ownable, so merge conflicts are rare and code ownership is natural.

*Effort to enforce: 3 (arch tests) · Confidence: **High**.*

---

## P11 — Domain logic never lives in controllers

**Statement.** A controller translates HTTP into a call and a result into a response; it contains no
business rule, no authorization decision, no orchestration, and no query beyond retrieving the subject
of the request.

**Why.** The controller is the layer most coupled to a *delivery mechanism* and least coupled to the
domain. Logic placed there is reachable only over HTTP — not from a queue worker, not from a scheduled
command, not from an event listener, and not from the AI service. So the first time the same operation
is needed from a second entry point, the logic is either duplicated (two rules that drift) or extracted
under time pressure (a refactor nobody budgeted).

Controllers are also the hardest layer to test well: exercising them requires routing, middleware,
serialization, and authentication, so a business rule in a controller can only be tested through the
full HTTP stack. That makes the tests slow, makes edge cases expensive to cover, and means the rule is
usually under-tested exactly where it matters.

The clean split gives each layer one reason to change: HTTP shape changes touch controllers and
requests; business rules touch Actions; storage changes touch models and migrations. When those three
reasons live in one class, every change risks the other two.

**The exact responsibility split.**

```
 HTTP request
     │
     ▼
┌─────────────────┐  Route + Middleware
│ authentication, tenant context (SET LOCAL), rate limiting, envelope wrapping
└─────────────────┘
     │
     ▼
┌─────────────────┐  FormRequest
│ • authorize(): may this actor invoke this endpoint at all? (coarse, permission-level)
│ • rules(): SHAPE validation — types, required, formats, ranges, existence of referenced ids
│ • NEVER: business rules, cross-entity consistency, state-machine legality
└─────────────────┘
     │  validated array → DTO
     ▼
┌─────────────────┐  Controller  (thin, and boring on purpose)
│ • build the DTO from the validated request
│ • call ONE Action
│ • map the returned domain object to a Resource
│ • choose the HTTP status
│ • NEVER: if/else on business state, queries, transactions, events, money arithmetic
└─────────────────┘
     │  DTO
     ▼
┌─────────────────┐  Action
│ • fine-grained authorization (may this actor do this to THIS record?)
│ • the business rules and their ordering
│ • ONE transaction boundary (P8)
│ • emit domain events AFTER commit
│ • return a domain object or a result DTO — never an HTTP concept
└─────────────────┘
     │
     ▼
┌─────────────────┐  Service (shared domain mechanism)
│ • stateless, reusable machinery used by several Actions: posting engine, number allocator,
│   permission resolver. Enforces universal invariants. Knows nothing about HTTP or actors
│   beyond what it is passed.
└─────────────────┘
     │
     ▼
┌─────────────────┐  Model
│ • columns, casts, relations, scopes. Decides nothing (P10).
└─────────────────┘
     │
     ▼
┌─────────────────┐  PostgreSQL
│ • the invariants that must be true regardless of any of the above (P1)
└─────────────────┘
```

Two boundaries are commonly blurred and are worth stating sharply:

- **FormRequest vs Action.** The FormRequest answers *"is this a well-formed request from someone
  allowed to use this endpoint?"* — shape and coarse permission. The Action answers *"is this operation
  legal right now given the state of the world?"* — a closed period, a non-postable account, an entry
  already posted. A FormRequest that queries three tables to decide legality has become an Action with
  a validation-shaped API, and it is unreachable from the queue.
- **Action vs Service.** An Action is a *use case* — something a user, agent, or job invokes, with an
  actor and an authorization decision. A Service is *machinery* — invoked by Actions, no actor of its
  own, enforcing invariants that hold for every caller. If two Actions need the same logic and it has
  no actor, it is a Service. If it has an actor and a permission, it is an Action.

**What it forbids.**

- `if` statements on domain state in a controller.
- Any query in a controller beyond resolving the route-bound subject.
- `DB::transaction` in a controller.
- Dispatching events, jobs, or notifications from a controller.
- Business rules in FormRequest `rules()` or `withValidator()` closures.
- Controllers returning models directly. A Resource shapes the response, so field-level access control
  and envelope consistency have exactly one place to live.
- Controllers catching domain exceptions to reshape them. The global handler renders typed exceptions
  (P13); a controller that catches one is bypassing the catalog.

**How we enforce it.**

- **Architecture tests**: classes in `app/Http/Controllers/` may not reference `DB::`, `Model::query`,
  event/queue facades, or bcmath functions; every public controller method must reference at most one
  Action.
- **A line-count ceiling** on controller methods, enforced in CI. Crude, effective: business logic in a
  controller is always longer than the four lines the correct shape takes.
- **Envelope middleware** wraps any bare payload a route returns, so a controller cannot invent its own
  response shape even by accident.

**Cost we accept.** Indirection. Following a request means opening four files instead of one, and for a
trivial endpoint that genuinely is more work to read. Small operations feel over-engineered. New
engineers ask why a three-line endpoint needs a DTO and an Action — and the answer ("so it is reachable
from the queue, testable without HTTP, and identical to every other operation") is not obvious until
they have been on the wrong side of it once.

**When it may be revisited.** Never for the split itself. The *ceremony* may be reduced — a shared base
controller, an invokable-controller convention, or code generation for the boilerplate — and those are
welcome. Reducing ceremony is not the same as moving logic up a layer.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Fat controllers | Logic reachable only over HTTP; slow and awkward to test; duplicated the moment a second entry point exists. |
| Controller → Model directly (skip the Action) | Reintroduces P10's fat model with an extra hop. |
| Single-action invokable controllers containing the logic | Better naming, same defect: still coupled to HTTP, still unreachable from a job. |
| Vertical slice (handler per endpoint, no layers) | Attractive for small services; here it duplicates cross-cutting concerns (tenancy, audit, posting) across slices, and those concerns are the whole product. |

*Engineering risks.* Layer drift — a "convenience" query in a controller that grows. The arch test and
the line ceiling are the defence, and both must actually be in CI rather than aspirational.

*At scale.* Positive: the uniform shape makes endpoints reviewable and generatable, and it is what
allows an AI agent to add an endpoint correctly by pattern.

*Effort to enforce: 3 · Confidence: **High**.*

---

## P12 — DTOs are immutable and typed

**Statement.** Data crossing a layer boundary travels as a `final readonly` class with typed
properties; it is never an associative array, never a mutable object, and never carries hidden context.

**Why.** Three separate problems collapse into one solution here.

*The first is the array.* An `array $data` passed from a controller to an Action to a service is an
undocumented, unvalidatable, untypeable contract. Its shape lives in the reader's head and in whatever
`$data['line_items'] ?? []` defaults accumulated. Static analysis cannot help, the IDE cannot help, and
a renamed key fails at runtime in whichever branch happens to read it. In an accounting system the
branch that fails is frequently the tax one, months later, for one customer.

*The second is mutation in transit.* If an object can be modified after construction, then "what was
the input to this operation?" has no single answer — it depends on where in the call stack you ask.
This matters enormously for a financial system, because the audit record, the validation, and the
posting must all be describing the *same* input. When a DTO is immutable, the object the audit log
serializes is provably the object the posting engine validated. When it is mutable, that is a hope.

*The third is hidden context* — the failure QAYD is deliberately avoiding, and the one worth being
explicit about. Odoo's equivalent of a DTO is a recordset, which is mutable, database-backed, and
carries an ambient `context` dictionary and an `su` (superuser) flag that propagate **transitively**
through every derived recordset. The consequence is not theoretical: Odoo's own code carries a
`depends_context=('uid',)` declaration whose comment explains it exists to avoid *cache pollution
between sudo and non-sudo uses of the same field*. That is a mutable, context-carrying data object
poisoning a cache across a privilege boundary — a class of bug that simply cannot occur when the thing
you pass between layers is an immutable value with no ambient state and no database connection.

There is a fourth reason specific to QAYD's thesis. An AI drafting a journal entry produces a
*proposal* — something that must be reviewable, storable, diffable, and re-playable. A `final readonly`
DTO is exactly that: a value you can serialize, show a human, store in a proposal table, and later
execute unchanged. A mutable object graph with a live database connection is none of those things.

**What it forbids.**

- `array` type hints for domain input on Action, Service, or DTO signatures. (Typed *lists of DTOs* —
  `list<JournalLineData>` — are correct and encouraged; the ban is on unshaped associative arrays.)
- Public setters, `withX()` mutators that modify in place, or public non-readonly properties on a DTO.
- Non-`final` DTOs. A subclass that adds a property makes the type a lie for every consumer that
  declared the parent.
- Eloquent models used as inputs to Actions. A model is a mutable, connected, lazily-loading object;
  passing one hands the callee a live database handle and an unknown load state (P10).
- Money as `float` or `int` on a DTO. It is a `numeric-string`, annotated as such (P4).
- Framework or transport concerns on a DTO — no `Request`, no `Illuminate\*` types, no HTTP status. A
  DTO that mentions HTTP cannot be constructed by a queue job, which is the whole point of having one.
- Hidden context: no ambient "current company", "current user", or feature-flag state smuggled inside a
  DTO. Tenancy comes from the RLS session (P3); the actor is an explicit parameter.

**How we enforce it.**

- **The language.** `final readonly class` is enforced by PHP itself at construction: a write to a
  readonly property is a fatal `Error`, not a convention. This is the rare case where the strongest
  available mechanism is free.
- **PHPStan at level max**, already a blocking CI gate. It rejects untyped properties, and the
  `@param numeric-string` annotations on money (as on `JournalLineData`) let it catch a float or an
  arbitrary string reaching a money field before the code runs.
- **An architecture test** asserting that every class under `app/Data/` is `final`, is `readonly`, has
  no public methods that return `static`/`self` other than named constructors, and references no
  `Illuminate\` or `App\Models\` type. **Gap G-4:** this test does not exist yet; today the convention
  holds because every DTO in `app/Data/` happens to follow it. Effort 2.
- **Named constructors as the only complex path.** `fromRequest()`, `fromEntry()` — validation and
  normalization happen once, in a named, testable place, rather than at each call site.

**Cost we accept.** Boilerplate, and a real amount of it: a class per shape, a named constructor per
source, and a mapping step at each boundary. Changing one field means touching the DTO, the mapper, the
Action signature, and the tests — where an array would have needed one line. Deeply nested structures
become several classes, and "just add a field to the array" — genuinely faster on the day — is
unavailable. We accept this because the cost is paid at write time by someone with the full context in
their head, and the array's cost is paid at debug time by someone without it.

**When it may be revisited.** Not for domain data. Two legitimate carve-outs already exist and should
stay explicit rather than expanding by drift: (a) **transport-edge payloads** — the raw decoded JSON
body before it becomes a DTO, and the array a Resource returns before serialization; (b) **genuinely
open-ended structures** — an audit diff, an AI proposal payload — which are `array<string, mixed>`
inside a *typed wrapper* whose shape is described by a JSON Schema and CHECK-constrained in the
database. The rule is that unshaped data may exist at the edges and must never be the thing an Action
receives.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Associative arrays | No type safety, no IDE support, no static analysis, no documentation; shape drifts silently. The status quo of most PHP codebases and the reason most PHP codebases are hard to change. |
| Mutable DTOs with setters | Loses the "the audited input is the validated input" guarantee, and invites partially-constructed objects to travel between layers. |
| Eloquent models as the transport | Couples every layer to the persistence schema, hands the callee a live connection, and makes load state part of the contract (P10). |
| A DTO library with attribute-based magic hydration | Convenient, but reflection-driven hydration hides the mapping and makes the failure mode a runtime exception in a vendor stack trace. Plain named constructors are more code and always debuggable. |
| Value objects for every field (a `Money` class, an `AccountCode` class) | Genuinely stronger typing, and worth adopting selectively for `Money` later. Rejected as a blanket rule now because it multiplies the boilerplate cost by the field count for a system whose money handling is already guarded by `NUMERIC(19,4)` plus `numeric-string`. Recorded as a deliberate deferral, not a rejection. |

*Engineering risks.* **Boilerplate fatigue** is the real one — under deadline, an engineer adds
`array $extra` beside the DTO and the discipline is gone. The arch test in G-4 is what converts this
from vigilance into a gate. A second risk is **DTO proliferation**: near-identical `CreateFooData` and
`UpdateFooData` classes that drift apart. Tolerate the duplication; merging them into one optional-heavy
class is worse, because "which fields are meaningful in this context?" stops being answerable from the
type.

*At scale.* Neutral-to-positive. Construction cost is negligible next to a database round trip.
Immutability makes DTOs safe to cache, safe to serialize onto a queue, and safe to share across
coroutines or parallel processes without defensive copying — which is exactly what a heavier
import/batch-posting workload will need.

*Effort to enforce: 2 (the arch test; the language does the rest) · Confidence: **High** — the mechanism
is compiler-level and already in use across `app/Data/`.*

---

## P13 — Errors are typed, coded, and aggregated

**Statement.** Every expected failure is a typed `DomainException` carrying a stable catalog code and
an HTTP status, rendered into one envelope shape; and where several rules can fail at once, **all** of
them are reported together.

**Why.** An error message is an API. Financial software fails constantly and legitimately — a period is
closed, an entry does not balance, an account is not postable, a rate is missing for a date. These are
not exceptions in the "something broke" sense; they are ordinary, expected outcomes that the caller
must be able to *program against*.

A free-text message cannot be programmed against. Match on its wording and the next copy edit breaks
you; translate it and you have made the machine-readable path depend on the user's language. A stable
code (`UNBALANCED_ENTRY`, `PERIOD_CLOSED`, `RATE_MISSING_FOR_DATE`) survives rewording, translation,
and refactoring, and lets a frontend decide *what to do* rather than what to display.

The typed part matters separately from the coded part. A typed exception is a domain vocabulary the
compiler and static analyser understand: `ClosedPeriodException` is greppable, catchable at a precise
granularity, and impossible to confuse with an infrastructure failure. It also draws the line QAYD
cares most about — a `DomainException` is *the system working correctly and saying no*, whereas an
untyped `RuntimeException` is *the system broken*. The first is rendered to the user with its message
intact; the second is logged, alerted on, and rendered as an opaque 500. Conflating them is how you get
either stack traces leaking to users or genuine incidents hidden as validation messages.

**Aggregation is the third leg, and it is the one QAYD has not built.** Failing on the first violation
is fine for a human filling in a form, who fixes one thing and resubmits. It is quadratically wasteful
for an agent or an importer: an AI drafting a 40-line entry with three problems needs three full
round-trips — each requiring inference, each costing money and latency — to learn what one response
could have told it. This is not a hypothetical improvement borrowed from elsewhere; it follows directly
from QAYD's own premise that a machine is a first-class caller. Notably, Odoo — whose error handling is
otherwise unstructured — *does* collect every validation failure and raise them together, then discards
the benefit by joining them into a newline-separated string. The instinct is right and the encoding is
wrong: aggregate like Odoo, structure it like an API.

**What it forbids.**

- Throwing bare `Exception`, `RuntimeException`, or `\LogicException` for an expected business outcome.
- `abort(422, 'Some message')` in an Action, or any error whose only identity is its prose.
- Error codes invented at the throw site. A code that is not in the catalog does not exist; adding one
  is a deliberate edit to a reviewed list.
- Catching a `DomainException` to reshape it into a different response (P11) — the renderer owns the
  envelope, so there is exactly one place where the wire format is decided.
- Returning `null`, `false`, or an empty collection to signal a business failure. A silent failure is a
  correctness bug wearing a return value (P14).
- Leaking internals — SQL text, class names, file paths, constraint names — into a caller-visible
  message. A `DomainException` message is written to be read by a customer; everything else is logged.
- Stopping at the first violation when a batch of independent rules could have been evaluated together.

**How we enforce it.**

```
    Action / Service
          │  throws
          ▼
    DomainException (abstract)          ← errorCode() · errorStatus() · errorsList() · headers()
          │
          ├── Accounting\UnbalancedEntryException     UNBALANCED_ENTRY        422
          ├── Accounting\ClosedPeriodException        PERIOD_CLOSED           409
          ├── Accounting\PostingRuleException         …                       422
          ├── Identity\InsufficientPermissionException                        403
          └── …                                       (catalog: docs/api/API_ERROR_HANDLING.md)
          │
          ▼
    ApiExceptionRenderer                ← the ONE place the wire format is decided
          │
          ▼
    { success: false, errors: [ { code, field, message, meta }, … ] }
                                          ▲
                                          └── already a LIST. Aggregation (G-5) fills it
                                              with N entries instead of always exactly one.
```

- **An abstract base class with abstract methods.** `DomainException::errorCode()` and
  `errorStatus()` are abstract, so a subclass *cannot* be written without deciding both. This is
  enforcement by type system rather than by review, and it is why the catalog has stayed coherent.
- **A single renderer.** `ApiExceptionRenderer` is the only code that shapes an error response;
  anything not a `DomainException` renders as a generic coded 500 and is logged with its correlation id
  (P21).
- **An envelope contract test** asserting that every `DomainException` subclass renders to the agreed
  shape and that its code appears in the catalog document. **Gap G-6:** the "code exists in the
  catalog" half should be a test that parses the catalog, so a code cannot be introduced without
  documenting it. Effort 2.
- **Aggregation — Gap G-5, not yet built.** The envelope's `errors[]` is already a list, so the wire
  format needs no change. What is missing is a `ValidationReport` value collected by the validating
  Action — `violations[]` of `{code, field, message, actual, expected}` — thrown once at the end of a
  rule pass rather than at the first failure. `actual`/`expected` matter specifically for the machine
  caller: "debits 1,200.0000, credits 1,150.0000" is directly actionable where "entry is not balanced"
  requires another round trip to discover by how much. Effort 3, and it should land before the AI
  drafting path does, because retrofitting it afterwards means changing every caller's error handling.

**Cost we accept.** A class per failure mode, which feels heavy for a one-off rule and means the
exception directory grows steadily. A catalog to maintain, and the discipline that codes are permanent
once published — a published code is part of the API, so renaming it is a breaking change and
retiring one requires a deprecation window. Aggregation additionally costs: rules must be written to
*collect* rather than *return early*, which is slightly more code and requires care about rules that
genuinely cannot be evaluated after a prior failure (you cannot check whether an account is postable if
the account id does not resolve). The answer there is explicit **rule tiers** — structural rules first,
and if any fail, report them and stop; dependent rules only when the structure holds.

**When it may be revisited.** The typed-and-coded part: never. The *shape* of the envelope may evolve
with the API version, and the aggregation policy may become per-endpoint (an interactive form may
legitimately prefer first-failure for latency) — but that is a parameter of the same design, not a
different one.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Exception messages only | Not machine-readable; breaks on translation and copy edits; forces the frontend to match strings. |
| Result/Either return types instead of exceptions | Genuinely attractive and used well elsewhere. Loses here because it requires every intermediate frame to thread the result through, and PHP has no `?` operator to make that ergonomic — in practice teams end up unwrapping and throwing anyway, with the type as ceremony. |
| Integer error codes | Compact, unreadable, and unmergeable — two branches both claim `4012`. String codes merge cleanly and read in a log. |
| HTTP status alone | Far too coarse: a dozen distinct business failures all render as 422 with no way to distinguish them. |
| One `BusinessRuleException` with a code parameter | Fewer classes, and loses type-level granularity: you cannot catch just the period-closed case, and the code becomes a runtime string the analyser cannot check. |

*Engineering risks.* **Catalog sprawl** — codes multiply until they are as unusable as free text.
Mitigation: codes name the *rule violated*, not the call site, so two endpoints hitting the same rule
return the same code. **Over-classification** — inventing a type for a failure that occurs once. A
shared `JournalRuleException` with a code parameter is the right answer for a family of closely related
rules, and QAYD already uses that shape; the granularity test is "would a caller ever branch on this
separately?"

*At scale.* Positive and increasingly so. Coded errors are aggregatable in monitoring — "PERIOD_CLOSED
is up 40× this hour" is a usable signal, where a spike in 422s is not. Aggregation directly reduces AI
inference cost and latency as automated drafting grows, which is the direction of the product.

*Effort to enforce: 5 (base and renderer exist; aggregation 3, catalog test 2) · Confidence: **High**
on typed-and-coded — it is shipped and in use. **Medium** on the aggregation design: the rule-tier
boundary needs one real subsystem to validate it, and the right tiering will only be obvious after the
posting rules are written against it.*

---

## P14 — Nothing is silently corrected

**Statement.** When input is wrong, ambiguous, or missing, the system refuses and explains; it never
guesses, never substitutes a default for a missing financial fact, and never adjusts the user's data to
make an operation succeed.

**Why.** This is the principle that separates an accounting system from a system that stores numbers.
A silent correction produces the worst possible outcome: an operation that **succeeds**, returns a
plausible number, and is wrong. Nobody investigates a success. The error surfaces at a year-end
reconciliation, in a tax filing, or in an audit — at which point the cost is not a bug fix but a
restatement and a conversation about credibility.

The failure is easy to underrate because each individual instance looks helpful. Three from the
research make the case concretely, and all three are shipped, defensible-looking behaviours in a mature
system:

1. **The missing exchange rate.** A rate lookup that falls back to the earliest known rate, and then to
   `1.0`. A currency with no rates, or a date before the first rate, converts **at par** — silently, no
   error, no log, no flag. A 300-fils expense becomes a 300-dinar expense with a straight face.
2. **The auto-balancing plug.** An entry that does not balance gets a suspense line inserted so it can
   post. The unbalanced entry was a signal that something upstream was wrong; the plug converts a loud,
   cheap, immediate failure into a quiet, expensive, deferred one — and the suspense account becomes a
   junk drawer nobody can later decompose.
3. **The date shift.** Posting into a locked period silently moves the date forward to the first open
   one. The accounting date of a financial event is not a formatting detail — it determines which
   period, which return, and which statement the event lands in. Changing it silently is changing the
   fact being recorded.

Each is individually reasonable ("don't block the user"). Together they describe a system that would
rather be convenient than correct. QAYD's zero-tolerance balance check in both currencies is the same
decision made the other way, and it must not be weakened one convenience at a time.

There is a second-order reason that is specific to this product. When the drafter is an AI, silent
correction is *catastrophic* rather than merely bad: a model that submits a wrong date and receives a
success response has learned nothing, and the human reviewing the result sees a clean posting. Refusal
with a precise reason is the only feedback channel that makes an automated drafter converge instead of
drift.

**What it forbids.**

- Defaulting a missing financial fact. No default exchange rate, no default tax code, no default
  account, no assumed date. Missing means `RATE_MISSING_FOR_DATE`, not `1.0`.
- Rounding to make a comparison pass. Balance is checked at **zero** tolerance in both the entry and
  base currency; there is no epsilon and no "close enough" (P4 is what makes that affordable).
- Inserting a plug, balancing line, or suspense entry to force an operation through. Where a residual
  is legitimate — an opening-balance import from a prior system — it is surfaced in the DTO, requires
  an explicitly acknowledged suspense account, and is visible in the response before anything posts.
- Coercing a date, period, currency, or amount to a "valid" nearby value. Return a resolution the
  caller must accept: `PostingDateResolution { requested, earliest_open, reason }` — a suggestion, not
  an action.
- Truncating or reinterpreting precision. A value with more decimals than the currency allows is a
  rejection, not a silent round.
- `try { … } catch (\Throwable) { /* ignore */ }`, `?: 0`, and `??` used to paper over an absent
  financial value. Empty-catch and null-coalescing on money are the two commonest ways this principle
  dies quietly.
- "Best effort" batch semantics that skip bad rows and report success. A batch reports per-row outcomes
  explicitly, or it fails as a unit (P8).

**How we enforce it.**

- **Absence of the code path.** The strongest enforcement here is structural: there is no
  `$rate ?? 1.0`, no plug-line generator, no date coercer. You cannot bypass a fallback that was never
  written, and adding one is a visible, reviewable diff rather than a flag flipped in config.
- **Database CHECKs as the backstop.** `chk_je_balanced` and `chk_je_base_balanced` are unconditional
  equalities. Even if an application-layer tolerance were introduced by accident, the database refuses
  the row — this is P1 doing exactly the job it exists for.
- **A static-analysis rule** banning empty catch blocks and `??`/`?:` on values annotated
  `numeric-string`, plus a review checklist item for any new `catch`. **Gap G-7:** the money-specific
  null-coalescing rule is not implemented; PHPStan's `max` level catches some of it via type
  narrowing but not the semantic case. Effort 3.
- **Negative-path tests as first-class deliverables.** Every subsystem that resolves an external fact
  (rate, period, account) ships a test proving that *absence raises*, not that presence works. The
  "missing rate" test is more important than the "correct rate" test, and reviewers should treat a PR
  without one as incomplete.

**Cost we accept.** Real user friction, and it will generate support tickets. Users — and importers —
will hit refusals where another system would have quietly proceeded, and some of those refusals will be
for data the user considers unimportant. Bulk imports become harder: one bad row in ten thousand fails
the batch, and building good partial-failure reporting is more work than skipping the row. We accept
this because the alternative is a system that is easy to use and cannot be trusted, and because the
mitigation is a **UX obligation, not a principle exception** (see §T2): make the refusal specific,
actionable, and fixable in one click — offer to create the missing rate, show exactly which rows failed
and why, propose the resolution and let the user accept it.

**When it may be revisited.** Never for financial facts. There is a legitimate and quite different
neighbouring case: **presentation-layer normalization** of user input — trimming whitespace, accepting
`1,234.50` and `١٢٣٤٫٥٠` and normalizing to `1234.5000`, parsing a date in the user's locale. That is
*parsing*, not correcting, and the test that separates them is simple: **if the normalization can
change which number or which date is recorded, it is a correction and it is forbidden; if it can only
fail or produce exactly one unambiguous value, it is parsing and it belongs at the edge.**

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Sensible defaults for missing values | The default is invisible in the result. Nobody audits a number that arrived without complaint. |
| Warn-and-proceed | Warnings on a successful path are not read — by humans or by agents. A warning nobody blocks on is a comment. |
| Auto-correct with a full audit trail | Better than silent, and still wrong: the corrected value is what flows into the statements, and the audit trail is only consulted after someone already suspects a problem. |
| Configurable strictness per tenant | Guarantees the product has two behaviours, that the lax one becomes the default under sales pressure, and that every downstream feature must handle both. |
| Tolerance thresholds on balance (e.g. ±0.01) | Only necessary because of float drift, which P4 eliminates. Adopting a tolerance would import a problem QAYD does not have. |

*Engineering risks.* **Friction-driven erosion** — a large customer with dirty data asks for an
exception, and the exception becomes a flag, and the flag becomes the default. The defence is that
relaxations must be *data-level and auditable* rather than *behaviour-level*: a time-boxed, reasoned,
expiring lock exception is acceptable; a `strict_mode = false` setting is not. Second risk: **refusal
without a path forward** is genuinely bad product. A refusal that does not tell the user precisely what
to do is a failure of this principle, not an expression of it.

*At scale.* Positive. Bad data caught at the boundary does not propagate into aggregates, projections,
and statements, so the cost of a data-quality problem stays linear instead of compounding. The largest
version of this benefit arrives with AI drafting: an agent that receives precise refusals produces
clean entries, where an agent whose mistakes are silently absorbed produces a book that looks fine and
is not.

*Effort to enforce: 3 (the code paths are already absent; the static rule is the work) ·
Confidence: **High** on the principle. **Medium** on holding the line under commercial pressure —
which is why the §T2 UX obligation is written down as a duty rather than left as good intentions.*

---

## P15 — AI drafts, humans dispose, and the database enforces it

**Statement.** The AI engine may propose, classify, explain, and rank; it may never write to an
accounting table, and above all may never post. The boundary is enforced by database privileges and
constraints, not by prompt design or good behaviour.

**Why.** This is the principle the product is named for and the one with the least room for nuance.

Start with the asymmetry. An AI that drafts an entry a human approves is enormously valuable and its
mistakes are cheap — a reviewer rejects a bad draft and nothing happened. An AI that posts is a system
that can silently produce a wrong general ledger, and in accounting a wrong posting is not a wrong
prediction; it is a wrong *record*, immutable by P5 and P6, correctable only by a reversal that is
itself permanently visible. The upside of autonomous posting is saving one click. The downside is the
product's entire credibility. No confidence threshold changes that arithmetic, because the failure is
not "the model is usually right" — it is "when the model is wrong, the customer's books are wrong and
nobody looked."

Second, **accountability requires a person.** Someone signs the accounts. An auditor asking "who
approved this entry?" cannot be answered with a model version, and no regulator in the GCC or elsewhere
recognizes a model as an approver. The approval column must hold a human user id because the legal
structure of the activity requires it.

Third — and this is the part that is easy to get wrong — **the boundary must be structural, not
behavioural.** A rule that lives in a system prompt is not a boundary; prompts can be injected, models
change, and a future engineer adding a "helpful automation" is not violating anything they can see. The
only durable version of "the AI cannot post" is that the AI's database credentials do not permit it,
and the schema rejects the row regardless of who sent it. QAYD already has the second half of that: the
`trg_no_ai_autopost` trigger refuses to create an `ai_generated` entry in any status but `draft`, and
`chk_je_ai_confidence` requires an AI-flagged entry to carry a confidence value. Those constraints
apply to *every* connection — including the one an engineer opens by hand at 2 a.m.

Fourth, the constraint is also what makes the AI *useful*. Because a proposal is a stored, typed,
reviewable object rather than a side effect, it can be diffed, explained, batched, and learned from.
The AI's job becomes producing an excellent draft with its reasoning attached — which is a tractable
problem — rather than being trusted, which is not.

**What it forbids.**

- Any write to `journal_entries`, `journal_lines`, `ledger_entries`, `accounts`, or any accounting
  table by the AI service, directly or through a privileged API.
- An AI-authored posting, at **any** confidence. `ai_confidence = 0.999` is a draft. There is no
  threshold above which review is skipped, and introducing one is a change to this document.
- Auto-approval schedules, "approve everything below KWD X" rules, or bulk-approve-all UI that does not
  show what is being approved. Batch approval is fine; blind batch approval is the same thing as
  auto-posting with extra steps.
- The AI calling an Action that posts. It submits a *proposal*; a human invokes the Action.
- AI-generated SQL executed against the production database, and any predicate that reaches the
  database as a string. Where an agent must express a query — a matching rule, a report line, a
  dimension allocation — it emits a **closed, CHECK-constrained JSON selector** compiled to bound
  parameters through an allowlist. Never `eval`, never string interpolation. The same reviewed compiler
  then serves reconciliation matching, report expressions, and dimension rules, so one audited
  component secures three subsystems.
- Silent AI provenance. Anything an agent touched carries `ai_generated`, `ai_confidence`, and a
  reference to the proposal it came from, permanently — including after a human edits it.
- An AI-emitted "event" that Laravel treats as authoritative. The AI may *consume* domain events; a
  message from the AI is an input that goes through the same Controller → FormRequest → Action path as
  any other input (P23).

**How we enforce it.**

```
   ┌──────────────────────────┐        proposals only        ┌────────────────────────────┐
   │  FastAPI AI engine       │ ───────────────────────────▶ │  Laravel domain layer      │
   │  reads: projections,     │   HTTP / queue, typed DTO    │  Controller → Action        │
   │  read-model views        │                              │                            │
   │  writes: NOTHING in      │ ◀─────────────────────────── │  domain events (read-only  │
   │  the accounting schema   │      after-commit events     │  interest)                 │
   └──────────────────────────┘                              └────────────────────────────┘
              │                                                          │
              ▼ writes only here                                         ▼ human approves
   ┌──────────────────────────┐                              ┌────────────────────────────┐
   │ *_proposals tables       │  ──── human confirmation ───▶│ PostJournalEntryAction     │
   │ (confidence, reasoning,  │                              │ approved_by = a real user  │
   │  proposed_payload JSONB) │                              └────────────────────────────┘
   └──────────────────────────┘                                           │
                                                                          ▼
                                                          JournalEntryPostingService (P7)

   DATABASE REFUSES REGARDLESS OF CALLER:
     trg_no_ai_autopost      ai_generated entry may only be INSERTed as 'draft'
     chk_je_ai_confidence    an ai_generated entry must carry a confidence in [0,1]
```

- **`trg_no_ai_autopost`** — shipped. A `BEFORE INSERT` trigger on `journal_entries` raising if
  `ai_generated AND status <> 'draft'`.
- **`chk_je_ai_confidence` / `chk_jl_ai_confidence`** — shipped. AI provenance cannot be recorded
  without a confidence value, so "was this AI-drafted?" is always answerable.
- **No database driver in the AI service** — today `apps/ai` depends on nothing but `fastapi` and
  `uvicorn`; there is no `psycopg`, no `sqlalchemy`, no connection string. This is real enforcement and
  should be treated as deliberate rather than incidental: a CI check asserting the AI service declares
  no database driver is one line of `grep` and closes the most likely regression (**Gap G-8**, effort 1).
- **Gap G-3 — the UPDATE half is missing.** `trg_no_ai_autopost` fires on `INSERT` only. An
  `ai_generated` draft can be transitioned to `posted` by an `UPDATE`, and today only application code
  stands between that and a posted AI entry. The closing constraint is a companion trigger:
  `BEFORE UPDATE ON journal_entries WHEN (NEW.ai_generated AND NEW.status = 'posted')` raising unless
  `NEW.approved_by IS NOT NULL` — i.e. **an AI-drafted entry cannot reach `posted` without a human
  approver recorded on the row.** The columns (`approved_by`, `approved_at`) already exist. Effort 2,
  and it is the single highest-value constraint in this document relative to its cost.
- **A dedicated `qayd_ai` database role** for when the AI service does need read access: `SELECT` on
  read-model views only, `INSERT` on `*_proposals` only, no privileges of any kind on accounting
  tables. Deferred until the AI service reads the database at all — recorded as **Gap G-9** so it is
  designed before the first connection string is added, not after.

**Cost we accept.** A permanent human step in every automated flow — which caps how much of the work
QAYD can take off a customer's hands, and gives a competitor room to advertise "fully automatic
bookkeeping" that we will not match. Building the proposal layer (tables, review UI, promotion path,
provenance) is genuine additional work over letting the model write. Reviewer fatigue is a real risk:
a human clicking approve on 200 proposals is not meaningfully reviewing them, and the honest answer is
that the review UI must be good enough that a wrong proposal is *visibly* wrong — surfacing the
reasoning, the confidence, the source document, and the diff against what a rule-based path would have
produced.

**When it may be revisited.** The posting boundary: never, while QAYD keeps other people's books. Two
adjacent things may legitimately evolve and should not be confused with relaxing it: (a) **the review
becomes lighter for low-risk classes** — a human still approves, but in a batch view designed for
speed, with the risky items separated out; (b) **the deterministic tier grows** — bank matching by
exact reference and amount is a *rule*, not an AI decision, and rules may auto-execute where they are
provably exact. Moving work from the AI tier into the deterministic tier is the correct way to increase
automation. Note the ordering that follows: deterministic rules run first, and the AI only ever sees
what the rules could not settle — which keeps the AI honest and its measured accuracy meaningful.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| AI posts above a confidence threshold | Confidence is a model's opinion of itself, not a calibrated probability, and it is systematically overconfident exactly where the data is unusual — which is where the errors are. |
| AI posts, human reviews afterwards | Reverses the cost asymmetry: the wrong entry is already in the ledger, already numbered, already hashed, and correction is a permanent reversal. Review after the fact is auditing, not approving. |
| AI posts to a "pending" ledger, promoted in bulk | This is exactly the proposal model with the word "ledger" misapplied. If it is not posted it is not the ledger; calling it one invites a report to read from it. |
| Enforce the boundary in application code only | A boundary enforced only where someone remembered to check is a boundary that a new endpoint, a queue job, or a debugging session removes. |
| Enforce it in the system prompt | Not a mechanism. Prompts are injectable, versionable by accident, and invisible to a schema review. |

*Engineering risks.* **Commercial pressure** is the main one — the demo where the AI posts is
irresistible, and "just for the sandbox" becomes a flag. The mitigation is that the constraint lives in
the schema, so relaxing it is a migration with a reviewable name, not a config change. Second:
**rubber-stamping**, addressed above as a UX obligation. Third: **provenance loss** — a human edits an
AI draft and the entry stops being marked AI-generated, destroying the ability to measure real accuracy.
Provenance must be sticky: `ai_generated` stays true, with the human edit recorded alongside.

*At scale.* This is the principle that scales *best*, and the reason is worth stating. Every proposal —
accepted, edited, or rejected — is labelled training data produced by an expert in the ordinary course
of their work, and the rejections are the most valuable signal in the system. A companion
`posting_attempts` record of *refused* postings, with violation codes and AI confidence, is
simultaneously a compliance artifact ("show me every attempt to post into a closed period") and the
highest-quality corrective signal available (P21). An architecture that posts automatically generates
no such signal, because nothing was ever judged.

*Effort to enforce: 8 (G-3 trigger 2 · G-8 CI check 1 · G-9 role and proposal tables 5) ·
Confidence: **High** — the principle is unambiguous and half the mechanism is already shipped. The
open question is not whether to hold the line but how good the review experience must be for the line
to be economically comfortable.*

---

## P16 — Every subsystem is independently testable

**Statement.** Every subsystem can be tested without booting the rest of the system, and anything that
must be proven against real PostgreSQL behaviour is tested against real PostgreSQL.

**Why.** Testability is not a quality attribute you add; it is a *structural property* that either
falls out of the design or cannot be retrofitted. A subsystem that can only be exercised by starting
the whole application has already told you that its dependencies are ambient rather than declared — and
ambient dependencies are what make a system impossible to reason about, impossible to change safely,
and impossible to replace (P17). Testability is therefore a proxy metric for coupling, and the cheapest
one available: if setting up a test for your Action requires seeding six unrelated tables, the Action
has six dependencies you did not intend to give it.

The second reason is economic and specific to a financial system. QAYD's correctness claims are
concentrated in a handful of places — balance, numbering, period control, tenancy, immutability — and
each must be proven under conditions that are *expensive to reproduce*: concurrency, rollback, a
missing rate, a closed period, a second tenant. Tests of that kind can only be written if the unit
under test can be constructed in isolation with its collaborators controlled. Where that is not
possible, the tests silently degrade into happy-path integration tests, which is how a mature system
ends up with a large suite and no coverage of the cases that actually break it. The most striking
example from the research: a reconciliation subsystem with 95 tests and **zero concurrency tests** —
not because concurrency did not matter, but because the architecture made such a test impractical to
write.

The third reason is the boundary between *isolation* and *fidelity*, which this principle resolves
deliberately rather than by preference. QAYD deliberately pushes its invariants into PostgreSQL (P1,
P3). It follows that a mock database does not test QAYD — it tests a fiction. A test that stubs the
database can prove the Action calls the repository; it cannot prove the CHECK fires, the RLS policy
denies, the trigger raises, or the deferred constraint holds at commit. So the rule has two halves that
must be held together: **isolate collaborators, never isolate the database.** QAYD's CI already reflects
this — the RLS suite runs against a real Postgres 16 container, connecting as the non-superuser
`qayd_app` role, because RLS enforcement is meaningless when tested as the owner.

**What it forbids.**

- Global mutable state in domain code: static properties holding request state, singletons carrying
  the "current" anything, service locators resolved at call time.
- Facades and container lookups inside Actions, Services, and DTOs. Dependencies arrive through the
  constructor, where a reader and a test can see them. (Facades at the HTTP edge are fine.)
- `new` on a collaborator that has behaviour worth substituting. Constructing a value object is fine;
  constructing a resolver, a clock, or an HTTP client inside a method is not — it makes the dependency
  unsubstitutable and therefore untestable.
- Reading `now()`, `random`, `env()`, or the authenticated user directly in domain code. Time is
  injected (Laravel's test-time freezing is acceptable, ambient `now()` in a Service is not); the actor
  is a parameter.
- Mocking the database to test an invariant that the database enforces. A test that stubs a repository
  and asserts "unbalanced entries are rejected" proves nothing about `chk_je_balanced`.
- A subsystem whose only test is an end-to-end HTTP test. That is a smoke test; it is not evidence the
  subsystem is correct, and it fails for the wrong reasons.
- Tests that depend on execution order, on data left by another test, or on a shared seeded database
  that any test may mutate.

**How we enforce it.**

```
   FAST                                                                       FAITHFUL
   ├── Unit (no DB)                    pure logic, DTOs, calculators, resolvers
   ├── Feature (real Postgres)         Actions end-to-end: constraints, triggers, transactions
   ├── RLS / isolation group           real Postgres, connected as qayd_app (NOBYPASSRLS)
   ├── Concurrency group               ≥2 real connections, real locks, real rollback
   └── Contract (cross-service)        Laravel ⇄ FastAPI DTO shapes, without booting both

   Rule of placement:  test an invariant at the layer that ENFORCES it (see the matrix above).
                       Never higher — a higher test passes for reasons unrelated to the invariant.
```

- **Constructor injection everywhere in domain code**, with the container wiring in
  `AppServiceProvider`. This is what makes the seam in P17 a *testing* seam as well as a replacement
  seam: `FiscalCalendarResolver` can be stubbed to report any period state without creating a fiscal
  year.
- **Named Pest groups** — the codebase already uses `uses()->group('rls', 'isolation')` — so
  correctness-critical suites can be run and required independently rather than being diluted into one
  undifferentiated `pest` run.
- **Real Postgres 16 + Redis in CI**, with migrations and seeds run as the owner and the RLS group
  connecting as `qayd_app`. This is the single most important testing decision in the repository and it
  is already made.
- **Three independent CI gate jobs** (backend / frontend / AI), each with its own blocking gates —
  Pint, PHPStan at max, Pest for the backend; ruff, mypy `strict`, pytest for the AI engine. A service
  that cannot be tested without another service starting has failed this principle at the deployment
  level, and the CI shape makes that failure visible immediately.
- **Gap G-10: a concurrency test group does not exist yet.** Gapless numbering under parallel posting,
  and the ordered-lock acquisition that reconciliation will need, are exactly the properties that
  single-connection tests cannot observe. The suite must include the awkward case — a random subset of
  transactions rolling back *after* number allocation, with the surviving numbers still contiguous.
  Effort 5, and it should exist before the posting lock is narrowed (P9), because it is what makes that
  change safe to ship.

**Cost we accept.** Real Postgres in CI is slower than an in-memory SQLite run — minutes rather than
seconds — and it requires database state management (transactions per test, or truncation) that adds
setup complexity. Constructor injection produces constructors with several parameters and a container
that must be configured, which is more ceremony than reaching for a facade. Writing a fake for a seam
is work that a mock library would have done in one line, and the fake must be kept honest as the
interface evolves. We pay all of this because the alternative — a fast suite that tests a fiction — is
worse than no suite, since it produces confidence without evidence.

**When it may be revisited.** The isolation half may be relaxed where a dependency is genuinely trivial
and stable. The *fidelity* half may not: any test whose subject is a database-enforced invariant runs
against real PostgreSQL, permanently. If suite duration becomes a genuine constraint, the answer is
parallelism, per-group selection in pre-merge CI, and pushing slow suites to a merge queue — never
substituting a fake database.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| SQLite in-memory for speed | Different constraint semantics, no RLS, no deferred constraints, no PL/pgSQL triggers. It would test a database QAYD does not run on, and would silently pass exactly the cases we most need to fail. |
| Mock the database entirely | Tests the mock. Cannot observe a CHECK, a policy, a trigger, or a lock — i.e. cannot observe P1, P2, P3, P5, or P6. |
| Only end-to-end tests | Slow, flaky, and diagnostically useless: a failure tells you the system is broken, not which invariant broke. |
| Only unit tests | Fast and reassuring, and blind to the entire layer where QAYD's correctness actually lives. |
| Shared seeded fixture database | Couples every test to one dataset; a change to fix one test breaks five, and order-dependence creeps in. Build the state the test needs, through the real Actions. |

*Engineering risks.* **Seam proliferation** — introducing an interface purely to make something
mockable, which adds indirection without adding replaceability (P17 names the criterion: a seam belongs
at a *volatility* boundary, and testability is a symptom, not the reason). **Over-faking** — a fake
that drifts from the real implementation gives a green suite over a broken system; contract tests
against the real implementation are the defence. And **slow-suite erosion**: when CI takes too long,
teams start skipping groups locally, so the fast/faithful split above must stay usable rather than
becoming one monolithic run.

*At scale.* Positive but requires investment. Suite duration grows with the codebase, and the fix is
parallel execution and group selection rather than lower fidelity. The tests that matter most —
concurrency, tenancy, immutability — are the ones whose value grows fastest, because they cover the
behaviours that only appear under production load and are the most expensive to discover in production.

*Effort to enforce: 5 (structure and CI exist; the concurrency group is the outstanding work) ·
Confidence: **High** — the fast/faithful split is already implemented and proven by the RLS suite.*

---

## P17 — Every subsystem is replaceable

**Statement.** Wherever a design decision is likely to change, the system depends on a **named
interface** rather than on the implementation, so the implementation can be swapped without editing its
callers. Seams are placed at volatility boundaries, deliberately and sparingly.

**Why.** Every long-lived system is wrong about something. The question is not whether a decision will
need reversing but what reversing it will cost, and that cost is determined years earlier by whether
the decision was named or diffused. A decision expressed as an interface is replaced by writing a new
class and changing one binding. The same decision expressed as inline calls scattered through a service
is replaced by an archaeology project.

QAYD has already proved this at exactly the moment it mattered. The posting engine needs to know
whether a date falls in an open period. The *right* answer to how fiscal periods should work was
genuinely unresolved — the research made a strong case that a lock-date cursor is superior for
enforcement while a period table is necessary for the close workflow, and the resolution (periods as a
reporting dimension, locks as the enforcement cursor, status as a view over the cursor) was reached
*after* the posting engine shipped. Because the engine depends on a `FiscalCalendarResolver` interface
rather than on `fiscal_years`, that entire redesign is a new implementation and a changed binding in
`AppServiceProvider`. The posting engine — the most sensitive file in the system, the one whose diff
requires the most careful review — does not change at all. That is what a seam buys, and it is why this
principle is stated as an architectural rule rather than left to taste.

The counter-example is equally instructive. A system whose modules extend one another by inheriting and
overriding each other's methods has no seams at all: behaviour is injected into the middle of another
module's method, invisibly at the call site, order-dependent on load sequence, and impossible to
enumerate. "What happens when an entry is posted?" then has no answer short of grepping every module.
Replacing anything in such a system means understanding everything in it. That is not a hypothetical
end state — it is the observed condition of a mature ERP after twenty years, and it is the specific
future QAYD is buying its way out of, here and in P23.

**The discipline that makes this work is restraint.** An interface with exactly one implementation and
no plausible second one is not a seam; it is indirection with a ceremony budget. Seams belong where
volatility is *identified*, and the identification should be defensible in a sentence.

**Where the seams are, and why.**

| Seam | The volatility it absorbs | Status |
|---|---|---|
| `FiscalCalendarResolver` | Period model unresolved: lock-date cursor vs period table vs hybrid | **Built** — bound to `FiscalYearCalendarResolver`, to be rebound |
| `ReversalStrategy` | Negation vs *storno* (same-side negative amounts), legally required in roughly ten jurisdictions, none of them in the launch market | **Planned** — build the seam now, implement storno never or later |
| `TaxEngine` / rate resolution | GCC VAT regimes differ and change; e-invoicing mandates arrive on legislative timetables | Planned |
| `ExchangeRateProvider` | CBK vs commercial feed vs manual entry; rate conventions differ per source | Planned |
| `NumberAllocator` | Gapless-per-scope today; per-partner or per-branch chains if self-billing arrives | Built (concrete; interface when a second policy is real) |
| `PredicateCompiler` | The one compiler that turns stored selectors into bound SQL for matching rules, report expressions, and dimension allocation | Planned |
| AI engine boundary (HTTP/queue) | Model, vendor, and even the decision to run inference in-process will all change | Built by service separation (P15) |

The `ReversalStrategy` entry deserves a note, because it demonstrates the principle's economics. Storno
is not needed in Kuwait and may never be built. The *seam* costs approximately nothing today — an
interface and a binding written while the reversal code is being written anyway — and buys the option
to enter a jurisdiction later without touching the posting path. Building the seam is cheap; retrofitting
one into shipped reversal logic is not.

**What it forbids.**

- A subsystem depending on another subsystem's concrete class where the interface exists.
- `instanceof` checks or `match` statements on implementation types in domain code — a switch on
  implementation is a seam that was declared and then bypassed.
- Modules reading or writing each other's tables, which is the storage-level version of the same
  violation and is stated in full as P23.
- Extending behaviour by subclassing another module's class and overriding a method. Behaviour is
  composed through interfaces and events, never injected into someone else's call stack.
- A seam that leaks its implementation through its interface — a `FiscalCalendarResolver` returning an
  Eloquent `FiscalYear` model would have defeated the entire exercise. It returns a
  `ResolvedFiscalPeriod` value, which is why the rebinding is free.
- Speculative seams: an interface introduced "in case we need it", with one implementation and no named
  volatility. That is the failure mode this principle is most often used to justify, and it is
  forbidden by the same reasoning that motivates it.

**How we enforce it.**

- **The binding is the mechanism.** Interfaces are bound in `AppServiceProvider`; a consumer that
  type-hints the interface physically cannot reach the implementation. This is enforcement by the type
  system plus the container, not by convention.
- **Return values are DTOs, never models** (P12) — `ResolvedFiscalPeriod` rather than a `FiscalYear`.
  The interface's contract must not mention the implementation's storage.
- **An architecture test** asserting that no class outside a subsystem's own namespace references its
  concrete implementations where an interface exists. **Gap G-11:** not written. Effort 2.
- **A written justification per seam.** The table above is the register: a seam without an entry naming
  its volatility should be deleted, and a subsystem with identified volatility and no seam is a
  scheduled cost.
- **Contract tests** run against every implementation of a seam, so a replacement is proven equivalent
  rather than assumed to be.

**Cost we accept.** Indirection, and the navigational tax it imposes: "go to definition" lands on an
interface and the reader must consult the container to learn what actually runs. Every seam is a design
decision that must be made *before* the volatility resolves, on incomplete information — and some will
be placed wrongly, either in the wrong location or around something that turned out to be stable.
Interfaces also constrain: once several implementations exist, changing the interface means changing
all of them, so a seam introduced too early can ossify a bad abstraction. The mitigation is the
restraint rule above — few seams, each with a stated reason — rather than a lower standard.

**When it may be revisited.** Continuously and in both directions. When volatility resolves permanently
— a jurisdiction exits, a strategy is abandoned — the seam should be **collapsed**, not kept out of
sentiment. Removing an obsolete seam is as much an expression of this principle as adding a needed one,
because the goal is a system whose indirection maps exactly onto its real uncertainty.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Concrete dependencies, refactor when needed | Works until the change is urgent, at which point the edit touches the posting engine — the file with the highest review cost and the least appetite for risk. The S2-07 period redesign is the worked example. |
| Interfaces everywhere, on principle | Doubles the class count, hides the real seams among ceremonial ones, and makes the codebase harder to read for no optionality gained. |
| Inheritance / method overriding for extension | Invisible at the call site, order-dependent, unenumerable, and untestable in isolation. The observed twenty-year outcome is total coupling. |
| Plugin architecture with runtime discovery | Far more machinery than a single-tenant-schema product needs, and it makes the set of behaviours dynamic — precisely what an auditor should not have to reason about. |
| Feature flags in place of seams | Flags multiply code paths inside one implementation instead of separating them; both paths then run in production forever and every future change must consider both. |

*Engineering risks.* **Wrong seam placement** — an interface around something stable while the volatile
thing sits inline. **Leaky interfaces** that expose implementation types and therefore cannot actually
be swapped. **Seam rot** — an interface with one implementation and a forgotten rationale, which is
indirection with no remaining benefit. All three are addressed by requiring a written volatility
justification per seam and by pruning aggressively.

*At scale.* Strongly positive, and it compounds. The larger the system, the higher the cost of
unseparated decisions, and the greater the value of being able to replace a subsystem without a
coordinated rewrite. Seams are also the natural extraction boundaries if any part of the system ever
needs to become a separate service — which is exactly how the AI engine already relates to the domain
layer.

*Effort to enforce: 3 (bindings exist; the arch test and the seam register are the work) ·
Confidence: **High** — this is the one principle in the document with a completed, in-repository proof
that it pays: `FiscalCalendarResolver` was placed before the period design was settled, and it is about
to earn its cost.*

---

## P18 — Lifecycle rules are data, not scattered code

**Statement.** The legal transitions of any stateful entity live in **one** declared map, and that map
is mirrored by a database trigger; no Action, guard, or `if` may encode a transition rule of its own.

**Why.** Status transitions are the rules most likely to be duplicated and the least likely to be
duplicated consistently. Every new operation on an entity needs to know whether it is allowed right
now, and the cheapest way to answer that at the keyboard is a local `if` — `if ($entry->status !==
'draft') throw …`. Each such check is individually correct and locally reviewable. Collectively they
are a transition model nobody has ever seen written down, and it is where "posted → draft" appears once
because it was convenient in a cancel path.

The observed end state is precise and worth stating: a document type with eight statuses whose
transition rules are spread across at least six separate call sites — write guards, posting validation,
a draft button, a cancel button, and more — with no single place that answers "what transitions are
legal?" Every new code path re-derives the rules from context, and occasionally derives them wrongly.
One of the paths walks a *posted* document back to draft transiently in order to cancel it, which is
precisely the mutation that P6 exists to forbid.

**QAYD is at the exact point that system was before this metastasized, and that is the entire argument
for doing it now.** `journal_entry_status` already has eight values, and the codebase expresses the
model with two constants — `POSTABLE_STATUSES` and `EDITABLE_STATUSES` — which cover the two questions
asked so far. Every Action written from here encodes further transition rules implicitly. The cost of
centralizing is roughly three points today and grows linearly with each Action added against the
implicit rules; the cost of reconstructing the model later is bounded by nothing in particular. This is
the cheapest structural item available to the project and the one with the shortest window.

There is a second, non-obvious benefit. A declared transition map is *explainable to a machine and to a
user*. "What can I do with this entry?" becomes a lookup rather than an inference, so the UI can render
exactly the available actions, an API can advertise them, and an AI agent can be told what is legal
instead of guessing and being refused. A transition model that exists only as scattered guards can
answer that question only by attempting the operation.

**What it forbids.**

- A status comparison in an Action that decides legality. Actions ask the map; they do not re-derive it.
- Adding a status value without adding its transitions to the map in the same commit.
- Any path from `posted` to a non-terminal status. `posted → draft` must be structurally impossible,
  not merely absent — this is P6 expressed as a transition rule, and the un-post path is the specific
  thing the trigger exists to make unwritable.
- Transition rules that vary by caller — a "force" parameter, an admin override, a bulk path that skips
  the check. That is P2's forbidden off switch in lifecycle clothing.
- Encoding a transition as a side effect of another operation (a status changed inside an unrelated
  update), which makes the state machine unobservable.

**How we enforce it.**

```
   ONE SOURCE                          MIRRORED BY                    RESULT
   ──────────                          ──────────                     ──────
   JournalEntryLifecycle::TRANSITIONS  trg_je_status_transition       an illegal transition
   [ draft   => [submitted, void],     BEFORE UPDATE ON               is impossible from
     submitted=> [approved, draft],    journal_entries                ANY caller — Action,
     approved => [posted, draft],      rejects OLD.status →           migration, psql,
     posted   => [] ,  ← terminal      NEW.status if the pair         a future developer
     … ]                               is not in the table            in a hurry
        │                                     ▲
        │  generated from / verified against  │
        └─────────────────────────────────────┘
              a test asserts the two agree — one map, two enforcers, no drift
```

- **A single `TRANSITIONS` constant** as the only place a legal transition is written, consulted by
  every lifecycle Action and by the API that advertises available actions.
- **A mirroring `BEFORE UPDATE` trigger** that rejects any status pair absent from the map. The trigger
  is what makes the rule un-bypassable; the constant is what makes it readable and reusable. Neither
  alone is sufficient: a constant alone is a convention, a trigger alone produces an unexplainable
  error.
- **A test asserting the two agree** — the map is the source, the trigger is generated from or verified
  against it, and drift fails CI. Without this test the design has two sources of truth, which is the
  failure P19 and R12 both warn about.
- **Gap G-12:** neither the map nor the trigger exists yet; the two constants are the current state of
  the art. Effort 3, and it is the highest urgency-to-cost ratio in this document.

**Cost we accept.** Indirection for simple checks — asking a map where an `if` would have read more
directly, and a slightly heavier ceremony for adding a status. Transitions that genuinely depend on
*data* rather than only on the current status (a permission, an amount threshold, an approval count)
do not fit a pure pair map, and forcing them into it would be worse than leaving them out. The correct
shape is a map of *structurally* legal transitions plus explicit guards for data-dependent conditions —
the map answers "is this pair ever legal?", the Action answers "is it legal for this actor, on this
entry, right now?" Keeping that boundary crisp is real design work, and blurring it is how the map
becomes a rules engine (which P17's alternatives correctly reject).

**When it may be revisited.** The map may grow, and a second entity with its own lifecycle gets its own
map rather than being forced into a shared abstraction. What may not be revisited is the *singleness*:
one map per entity, one trigger per map, no second place where a transition rule is written.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Scattered `if` guards (status quo) | No single answer to "what is legal"; every new path re-derives the rules; the illegal transition arrives as a one-line convenience. |
| A generic workflow engine | Explicitly rejected. A mature ERP built one and then **deleted it**, replacing it with explicit statuses and explicit methods. It obscures domain state behind configuration, and QAYD is already at the post-deletion design. Do not re-learn this at our own expense. |
| Database enum + trigger only, no application map | Un-bypassable, but the UI and API cannot enumerate legal actions without querying the catalog, and error messages are poor. |
| Application map only, no trigger | Readable, and exactly as strong as everyone's memory. A migration or a raw fix writes straight through it. |
| Event-sourced state (derive status from an event log) | Genuinely rigorous and a much larger commitment; the ledger is already the append-only record that matters, and event-sourcing the entry lifecycle as well would add a second history without a second benefit. |

*Engineering risks.* **Map/trigger drift** if the equivalence test is not written — two sources of truth
is the exact failure this principle is meant to prevent, so shipping the map without the test would be
self-defeating. **Rules-engine creep**: pressure to express data-dependent conditions in the map until
it becomes a small interpreter. Hold the line at structural pairs.

*At scale.* Positive. As entity types multiply (entries, payments, reconciliation groups, period-close
runs, proposals) the map pattern is repeatable and each new lifecycle inherits both the enforcement and
the "what can I do now?" affordance for free.

*Effort to enforce: 3 · Confidence: **High** — the design is simple, the evidence that scattering it is
harmful is unusually direct, and the window for doing it cheaply is now.*

---

## P19 — Derived data is rebuildable from its source

**Statement.** Anything computed from something else — a projection, a cache, a rollup, a read model —
must be reconstructible by a shipped rebuilder, and a scheduled check must prove it has not drifted.
Nothing derived is ever the only copy of a fact.

**Why.** Derived data always drifts. Not sometimes: always, eventually, given a rollback at the wrong
moment, a bug in an increment, a manual fix, or a code path that forgot to update it. The distinction
between a system where that is a nuisance and one where it is a crisis is entirely whether the derived
value can be recomputed on demand and compared against its source.

Two failure modes bracket the design, and QAYD must avoid both.

*Too little derivation* is the scalability failure. A ledger with no stored aggregates at any
granularity makes every trial balance a full scan of the largest table in the system. QAYD's
`ledger_entries.signed_base_amount` already reduces any balance to a `SUM()`, and the natural next step
— `account_period_balances` maintained by an `AFTER INSERT` trigger — turns a trial balance into a
roughly two-thousand-row index scan. The reason QAYD may safely do this and a mutable-ledger system may
not is precise and worth stating: **a cached aggregate is trustworthy only if its source is
append-only.** Because `ledger_entries` never updates or deletes, the rollup is *monotonic* — it can
only ever be incremented, never reconciled backwards. That property is a direct dividend of P5, and it
is the clearest example in the codebase of one principle paying for another.

*Too much reliance on replay* is the opposite failure, and it is equally well evidenced: a mature
system that **removed** its stored valuation layer in favour of replaying full history, then had to
batch the replay at fifty thousand rows to avoid running out of memory. Full-history replay is a
correctness tool and a repair tool; it is not a read path. The lesson is to persist the derived state
*and* keep the ability to rebuild it — not to choose between them.

*The third failure is two sources of truth kept in sync by convention* — a JSONB field and a
materialized table maintained in both directions, with a context flag in half a dozen places to
suppress the sync when it would recurse. That is not derivation; it is duplication with a maintenance
protocol, and it is forbidden here. Derivation has a direction: one source, one derived artifact, one
rebuilder, no write-back.

**What it forbids.**

- A derived table with no rebuilder. If you cannot recompute it, you cannot verify it, and it has
  quietly become a source of truth nobody designated.
- Writing to a projection from anywhere except its single maintainer (a trigger or a designated
  listener). A projection with two writers is a source of truth with a race condition.
- Editing a projection to "fix" a number. Fix the source; rebuild the projection.
- Two-way sync between a derived artifact and its source, in any form.
- Deriving from a *mutable* source without a verification path. Where the source can change, the
  rebuild-and-compare check is not optional — it is the only thing standing between the system and
  silent drift.
- Full-history replay as a production read path.
- Treating `ledger_entries` as rebuildable-in-production. It is derived from `journal_lines`, but it is
  also append-only, hash-chained, and the record the business relies on; truncate-and-replay against it
  is forbidden by P5. Rebuilding it is a disaster-recovery procedure executed against a *copy* and
  compared, never an operation performed on the live table. (See §T4.)

**How we enforce it.**

```
   SOURCE (append-only)            DERIVED                      VERIFICATION
   ────────────────────            ───────                      ────────────
   journal_lines  ──post──▶  ledger_entries         ──▶  hash chain + 1:1 unique index
                                    │                          (rebuild only into a copy)
                                    │ AFTER INSERT trigger
                                    ▼
                             account_period_balances  ──▶  RebuildPeriodBalancesAction
                             CHECK (closing =                 nightly + in CI:
                               opening + debit − credit)      rebuild into a temp table,
                                    │                         diff against live,
                                    │                         ANY difference = alert
                                    ▼
                             report / statement caches   ──▶  invalidated by domain event,
                                                              never written directly
```

- **A `Rebuild*Action` per projection**, shipped in the same story as the projection itself. A
  projection whose rebuilder is deferred to "later" is a projection whose correctness is unverifiable
  from day one.
- **A scheduled drift check** that rebuilds into a temporary table and diffs against the live one. Any
  difference is an alert, not a silent correction — the drift is a symptom of a bug, and repairing it
  without investigating destroys the evidence (P14 applies to the system's own data too).
- **The same check in CI**, on seeded data, so a change that breaks the maintaining trigger fails before
  merge rather than during a month-end close.
- **A structural CHECK where one exists** — `CHECK (closing = opening + debit − credit)` on a period
  balance makes an internally inconsistent rollup row impossible regardless of how it was written.
- **Cache invalidation by domain event, never by direct write** — a `JournalEntryPosted` listener clears
  the affected company/period tags. The cache has exactly one writer and one invalidator.
- **Gap G-13:** no projection currently ships a rebuilder, because `account_period_balances` does not
  exist yet. The rule must be established *with* the first rollup, not retrofitted; it is the cheapest
  possible moment and the only one where the rebuilder is guaranteed to be written against a correct
  projection. Effort 5, bundled with the rollup itself.

**Cost we accept.** Every projection is roughly a third more work: the projection, the maintainer, the
rebuilder, and the drift check. Rebuilders themselves need maintenance and can rot — an unrun rebuilder
is a rebuilder that fails when finally needed, which is why the CI run matters more than the scheduled
one. Drift checks consume real resources at scale, and on a large book the rebuild-and-diff eventually
needs to be incremental or partitioned rather than whole-table. Storage grows: the derived data is
duplicated by definition.

**When it may be revisited.** The rebuilder requirement: never for financial aggregates. It may be
relaxed for genuinely disposable caches whose loss is invisible — an HTTP response cache, a rendered
fragment — where the "rebuild" is simply the next request. The test is whether anyone could ever quote
the derived number to a third party. If they could, it needs a rebuilder and a drift check.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| No derived data; compute everything on read | Correct by construction and unusably slow: every trial balance scans the largest table. This is the observed status quo of a mature ERP and its clearest scalability defect. |
| Derived data with no rebuilder | Works until it drifts, then presents an unfixable discrepancy with no way to tell which side is wrong. |
| Full-history replay on read | Documented to fail at scale — the system that adopted it had to batch replay at 50,000 rows to avoid memory exhaustion. |
| Materialized views | Attractive and viable for some reporting reads, but `REFRESH` is coarse and locks awkwardly, and incremental maintenance is what a monotonic append-only source makes cheap. Reasonable for future read-only analytics; not for the core rollup. |
| Two-way sync between source and derived | Two sources of truth with a protocol. Requires suppression flags at every recursive edge and has no consistent state to converge to. |

*Engineering risks.* **Rebuilder rot** — untested, unrun, and broken when needed; mitigated by running
it in CI. **Drift-check fatigue** — an alert that fires often enough to be ignored, which is worse than
no alert; a drift alert must be treated as a P1 bug, not a chore. **Rebuild cost at scale**, which is a
scheduled engineering problem (partition-scoped rebuilds) rather than a reason to skip the check.

*At scale.* This is the principle that unlocks scale rather than merely surviving it. The rollup turns
the system's most common expensive query into an index scan, and the append-only source means the
maintenance is a monotonic increment rather than a recompute. The rebuild-and-diff is the part that
needs engineering as data grows, and partitioning by `(company, period)` — itself possible only because
the ledger is append-only — is the answer.

*Effort to enforce: 5 (per projection, bundled with the projection) · Confidence: **High** on the
principle; **Medium** on the drift-check cadence, which needs production data volumes to tune and will
almost certainly need to become partition-scoped sooner than expected.*

---

## P20 — Documentation is executable, adjacent, or it is decoration

**Statement.** A claim about how the system behaves is either checked by something that runs, or it
sits next to the code it describes and is changed in the same commit. Documentation that is neither
will be wrong within a quarter, and wrong documentation is worse than none.

**Why.** Documentation decays because nothing forces it not to. Code has tests, types, and a compiler;
prose has good intentions. The half-life of an unenforced statement about a codebase is roughly one
sprint, and the damage compounds: a reader who finds one stale claim distrusts the rest, so the accurate
90% is discarded along with the wrong 10%. At that point the documentation has negative value — it cost
time to write and it costs time to disbelieve.

The response is not "write less" but "make the claim load-bearing." Three mechanisms do this, in
descending strength. **Executable**: the claim is asserted by a test, so it cannot drift without failing
CI — the error-code catalog, the transition map, the RLS invariant. **Adjacent**: the claim lives in the
docblock of the migration or class it describes, so the diff that changes the behaviour physically
contains the sentence that describes it. **Regenerated**: the document is produced from the live system
on a schedule, so it cannot be stale by more than the interval.

QAYD already does the adjacent form unusually well, and it is worth naming as a practice rather than an
accident: migrations carry docblocks that state the invariant, name the specification document, and
explain *why* the mechanism was chosen — the RLS migration explains fail-closed semantics and why the
owner role bypasses; the ledger migration explains that the trigger is defence-in-depth for "the ledger
has no update path at all." A reader arrives at the constraint and the reasoning together. That is the
form of documentation that survives.

There is also an honest example of the *failure* being handled correctly, in the CI workflow: the file
records that `FINAL_TECH_STACK.md` names a different location for CI than the one GitHub Actions
actually executes from, and states which is canonical. The divergence was not silently tolerated and it
was not resolved by editing the doc into a lie — it was written down at the point of confusion. That is
the standard: when doc and code disagree, the disagreement itself becomes documentation until one of
them is fixed.

**What it forbids.**

- A separate document restating what code already says — a class-by-class description, a list of
  endpoints, a schema dump maintained by hand. These are guaranteed to drift and add nothing a reader
  cannot get from the source.
- Behavioural claims with no test. "Posting is idempotent", "the API returns 409 on a closed period",
  "the AI cannot post" are assertions; each must have a test with its name in it, or it is folklore.
- Documentation of intent without documentation of mechanism. "We enforce tenant isolation" is a
  slogan; "RLS `FORCE` plus a `NOBYPASSRLS` role, verified by a catalog introspection test" is a
  statement that can be falsified.
- Editing a document to match code that drifted, without deciding which one was wrong. The precedence
  section at the top of this file governs: determine whether the doc is stale or the architecture
  changed, and write an ADR if the latter.
- Numbers, counts, and schema details copied into prose by hand. Anything countable is regenerated or
  omitted.
- A principle in this document with no enforcement row. Every principle above names its mechanism, and
  where the mechanism does not exist yet it is recorded as a numbered gap — a principle whose
  enforcement column is empty is exactly the decoration this rule is about.

**How we enforce it.**

```
   STRONGEST   executable    the catalog test, the transition-map test, the RLS
                             introspection test, the drift check
               ──────────
               adjacent      migration docblocks, class docblocks, ADRs beside the
                             decision they record — changed in the same diff
               ──────────
               regenerated   schema documentation produced from the live catalog
               ──────────
   WEAKEST     narrative     this file, MANIFEST.md — deliberately about reasoning,
                             which does not decay the way facts do
```

- **Narrative documents describe *why*, never *what***. This file states reasoning and mechanism
  categories; it deliberately avoids column lists and endpoint tables, because those are the parts that
  rot. Where it does name a concrete constraint (`trg_no_ai_autopost`, `chk_je_balanced`), the name is a
  greppable anchor — if the constraint is renamed, the grep fails and the doc reference is findable.
- **Docblocks cite their specification** — the existing `docs/database/ROW_LEVEL_SECURITY.md`,
  `docs/api/API_ERROR_HANDLING.md`, `docs/accounting/GENERAL_LEDGER.md` references — so the link is
  bidirectional and a reader in either place can reach the other.
- **ADRs for reversible decisions**, immutable once accepted; a changed decision is a new ADR that
  supersedes, never an edit. The record of *why we thought that then* is the valuable part.
- **Gap G-14: a documentation-freshness check does not exist.** The cheap, high-value version is a CI
  step that fails when a referenced document path does not exist, and when a named constraint or
  trigger cited in a docblock is absent from the catalog. That converts the most common decay mode —
  a renamed or deleted mechanism leaving a confident description behind — into a build failure.
  Effort 3.
- **Regeneration for schema documentation**, on the same schedule as any other derived artifact. A
  hand-maintained schema document is a projection with no rebuilder (P19).

**Cost we accept.** Writing mechanism-level documentation is slower than writing prose, and doc-linked
tests are real code to maintain. Some genuinely useful narrative — onboarding explanations, worked
examples — is neither executable nor adjacent, and this principle risks discouraging it. It should not:
the rule is that *claims about behaviour* need enforcement, not that explanation is unwelcome. The
correct response to a useful narrative document is to keep it and to be explicit about its
volatility — a dated, scoped document that says what it was true of is far more useful than an undated
one asserting the eternal present.

**When it may be revisited.** Not the principle. The *ladder* may gain a rung: literate examples that
execute as tests, or documentation generated from the schema and the transition map, would both raise
the floor and are welcome.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Comprehensive external documentation | Highest initial value, fastest decay, and the decay is invisible until someone is misled by it. |
| No documentation, "the code is the truth" | The code says what happens and never says why. The reasoning is the part that cannot be recovered, and it is the part that prevents a future engineer from undoing a deliberate decision. |
| Generated API documentation only | Necessary and insufficient: it describes shape, never intent or constraint. |
| Wiki | Ages worse than in-repo documentation because it is not in the diff, so nothing about changing the code prompts changing it. |
| Documentation as a release gate for every change | Excessive ceremony for small changes; the adjacency rule achieves most of it by making the doc part of the same file. |

*Engineering risks.* **Under-documenting reasoning** by over-applying the rule — an engineer who
concludes that unenforceable prose is forbidden stops recording *why*, which is the most valuable and
least recoverable artifact. **Test-as-documentation overreach**: tests written to document rather than
to verify are usually poor at both. And **doc-link brittleness** if G-14 is implemented too strictly —
it should check existence, not content.

*At scale.* Increasingly important. With more engineers and more agents contributing, documentation is
the primary mechanism by which intent survives turnover — and an AI agent reading this repository will
treat a stale document as ground truth far more confidently than a human would. That last point is a
genuine change in the stakes: unenforced documentation is now a source of *automated* error, not merely
of human confusion.

*Effort to enforce: 3 (the freshness check; the practices already exist) · Confidence: **Medium** — the
principle is sound and partly practised, but documentation discipline is the one thing on this list with
no strong mechanical backstop, and it is the most likely to erode quietly. Stated plainly: this is the
weakest-enforced principle in the document.*

---

## P21 — Every financial operation is explainable afterwards

**Statement.** For any figure in the system it must be possible to answer, from stored data alone: what
produced it, who authorized it, when, from which source, under which rule — and if an attempt failed,
that attempt is recorded too.

**Why.** An accounting system's product is not the numbers; it is the *defensibility* of the numbers.
The question that decides whether an audit is routine or existential is "show me how this figure came
to be," and a system that answers it from stored data turns a week of investigation into a query. This
is not a compliance feature bolted on at the end — it is a property that is either designed in or
permanently unavailable, because the information is destroyed at the moment the operation runs.

Three distinct things must be recorded, and they are commonly confused:

*What happened* — the append-only `audit_logs`, already trigger-protected against UPDATE and DELETE. The
key design decision is that a diff is **self-describing JSONB**: each entry carries its own field name
and type, so the history stays readable after a schema change. The contrasting approach — keying change
history to a field-metadata table by foreign key — makes the entire history unreadable the moment a
field is dropped, which is the opposite of what an audit trail is for.

*That it was not tampered with* — the hash chain, whose columns (`hash`, `prev_hash`) already exist and
are dormant. The design worth building improves on the reference implementation in four specific ways:
enforce the chain in a `BEFORE INSERT` **trigger** rather than in application code (a system with no
triggers at all has a test suite that clears a seal with an ordinary write); allow **no bypass tokens**;
add **externally-signed periodic anchors**, because an unkeyed chain with an empty-string genesis can be
rewritten end-to-end undetectably; and persist a **canonical payload** rather than re-deriving the hash
from live business fields, so verification is a pure function of audit rows and adding a field is not a
breaking migration. Cover *every* amount column — a chain that omits the foreign-currency amount leaves
that amount silently mutable on a "sealed" entry.

*What was refused* — and this is the one almost no system records. An append-only `posting_attempts`
table capturing rejected postings with their violation codes, entry source, and AI confidence is
simultaneously a compliance artifact ("show me every attempt to post into a closed period, and who made
it") and the highest-quality corrective signal available for the AI drafter, because it records exactly
what a human or an agent got wrong and why. In most systems a failed post leaves no trace at all; the
information with the most diagnostic value is the information routinely thrown away.

**What it forbids.**

- Mutating or deleting an audit record. Ever, by anyone, including a platform administrator.
- A financial write with no attributable actor. "System" is acceptable only when it names the job, the
  schedule, and the correlation id that triggered it.
- Losing the causal chain across an async boundary. A correlation id propagates through events, queue
  jobs, and outbound calls, so an effect three hops from a request can still be traced back to it.
- Recording only the new value. A diff without the prior value cannot answer "what changed?", which is
  the question actually asked.
- Discarding failures. A rejected posting, a refused approval, a rate lookup that raised — all are
  events worth keeping.
- Storing an explanation only in a free-text log line. Logs are rotated, unqueryable at the granularity
  needed, and outside the transaction; the audit record is a row, written in the same transaction as
  the thing it describes (P8).
- Personal data on ledger rows that would later force redaction into conflict with immutability. Refer
  to a party id, never a name — the conflict is avoided by design rather than resolved under pressure
  (§T5).

**How we enforce it.**

- **`trg_audit_logs_immutable`** and `REVOKE UPDATE, DELETE ON audit_logs` — both shipped. Note that
  `audit_logs` does at the privilege layer what `ledger_entries` does not yet do (Gap G-1); it is the
  model to copy.
- **The audit write is inside the transaction.** If the audit row cannot be written, the operation does
  not happen. An audit trail that is best-effort is not evidence.
- **A shadow-capture trigger reconciled against Action-sourced rows.** This is the subtle and valuable
  mechanism: a PL/pgSQL trigger records changes independently of the application, and a check compares
  trigger-sourced rows against Action-sourced rows. **A trigger row with no Action peer means something
  wrote outside the Action layer** — which is a detector for violations of P7, P10, and P22 that no
  amount of code review provides. **Gap G-15**, effort 8.
- **Gap G-16: the hash chain is dormant.** Columns exist; the trigger, the canonical payload, the
  verification action, and the signed anchors do not. Effort 21. It should be computed over
  `ledger_entries`, which is cheap precisely because that table is append-only — the chain can never go
  stale.
- **Gap G-17: `posting_attempts` does not exist.** Effort 3, and it is worth building before the AI
  drafting path, because the rejections generated during that build-out are the training signal.

**Cost we accept.** Storage that grows faster than the business data — audit volume typically exceeds
the data it describes. Write amplification on every financial operation, inside the transaction, where
it costs latency. A hash chain adds a serialization point on the ledger insert path and makes the
verification job an ongoing operational cost. Querying audit history well requires deliberate indexing
(GIN on the JSONB diff), which is not free either. And the discipline is unforgiving: an audit trail
with gaps is worse than none, because it invites reliance it cannot support.

**When it may be revisited.** The requirement itself: never. The *retention* is negotiable and must be
explicit — audit rows older than the statutory retention period may be moved to cold storage, which is
archival rather than deletion, and the hash chain must be anchored such that an archived segment remains
verifiable. That is engineering work, not a principle change.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Application logs as the audit trail | Rotated, unstructured, outside the transaction, and mutable by anyone with filesystem access. Not evidence. |
| Per-field change rows keyed to a field-metadata table | Becomes unreadable when a field is dropped, and produces hundreds of rows per edit where one snapshot would do. |
| Event sourcing as the primary store | Gives perfect history and imposes a much larger design commitment on every subsystem. The append-only ledger plus an append-only audit log captures most of the benefit at a fraction of the cost. |
| Database-level audit extension (e.g. `pgaudit`) | Records statements, not intent — it cannot say *why* or *on whose authority*, which is the part an auditor asks about. Complementary, not sufficient. |
| Trust the immutable ledger alone | The ledger says what the books contain; it does not say who approved it, what was rejected, or what a figure was before a correction. |

*Engineering risks.* **Audit as a performance excuse** — pressure to move the audit write outside the
transaction for latency, which destroys the guarantee; the correct optimization is a narrower payload,
never a weaker boundary. **Unqueryable audit** — a trail nobody can search is a trail nobody uses.
**Chain rigidity** — a hash chain over live business fields makes every schema change a migration
problem, which is exactly why the canonical-payload design is specified above.

*At scale.* Volume is the challenge and partitioning by `(company, month)` is the answer, with cold
storage beyond the hot window. The value grows with the customer: the enterprise and regulated segments
QAYD is aiming at treat this as a purchase requirement rather than a feature, and the hash chain in
particular is what makes the archive defensible rather than merely large.

*Effort to enforce: 21 (hash chain) + 8 (shadow reconciliation) + 3 (`posting_attempts`) ·
Confidence: **High** on the requirement and the design; **Medium** on the hash-chain effort estimate,
because the canonical-payload format and the anchoring scheme are the kind of decisions that look
settled until the first verification failure.*

---

## P22 — No ambient privilege — there is no `sudo()`

**Statement.** No code path may elevate its own privileges. There is no flag, parameter, context key,
or role that turns off tenant isolation or permission checks for the convenience of the caller.
Cross-tenant work is a named, reasoned, audited operation — never an ambient state.

**Why.** This is the security failure that a mature ERP demonstrates most completely, and the numbers
make the argument better than reasoning does: **552 call sites** of an ambient privilege bypass in one
checkout, 181 of them inside the accounting module alone. Seven characters mid-expression disable
model ACLs, record rules, field ACLs, and multi-company validation at once — and the flag propagates
**transitively** through every recordset derived from an elevated one, so a bypass three frames up
silently governs code that never asked for it. There is no log, no reason, and no scope.

Three consequences follow, and each is fatal on its own.

*Security becomes a convention.* If any code can elevate, then the security model is not "the database
enforces isolation" but "the database enforces isolation except where someone typed seven characters."
Nobody can enumerate those places, so nobody can audit the boundary.

*It is used for convenience, not necessity.* The observed pattern is elevation on the **hot path** — a
field read that must bypass the record rule because the field is stored per-company; a uniqueness check
that must see other companies' rows. Each is locally justified. Collectively they mean the isolation
predicate is bypassed constantly, in ordinary operation, by design.

*It poisons more than it bypasses.* The same system carries an explicit annotation whose comment says
it exists to avoid *cache pollution between sudo and non-sudo uses of the field* — an acknowledgement
that elevated and non-elevated reads share a cache, and that data from one company can therefore be
served to another through it. That is the failure mode: not a policy that was wrong, but a bypass that
made the policy irrelevant somewhere nobody was looking. The same system's own security models set a
flag disabling nested elevated writes — a tacit admission that the primitive is a privilege-escalation
vector.

QAYD's structural answer is already in place: the runtime role `qayd_app` is `NOSUPERUSER
NOBYPASSRLS`, so the *database* refuses to be talked out of the boundary regardless of what the
application believes. There is no application-level construct that can grant more than the connection
has. That is the property this principle protects.

**Honest assessment of where QAYD stands today.** One ambient bypass exists, and it is worth stating
plainly rather than discovering later. The `audit_logs` policies read:

```sql
CREATE POLICY audit_logs_company_boundary ON audit_logs
AS RESTRICTIVE FOR ALL
USING      (company_id = app_current_company_id() OR app_is_platform_admin())
WITH CHECK (company_id = app_current_company_id() OR app_is_platform_admin())
```

`app_is_platform_admin()` reads a session GUC, set by middleware from `users.is_platform_admin`. Three
observations:

1. It is in the **RESTRICTIVE** boundary — the policy whose entire purpose is that no permissive policy
   can OR past it. Here the boundary itself carries the OR, so the strongest tenancy guarantee in the
   system has a session-variable exception on this table.
2. It grants **INSERT**, not only SELECT. A platform-admin session can write an audit row attributed to
   *any* company. On the table whose purpose is to be the tamper-evident record of what everyone did,
   the ability to author entries into another tenant's history is the wrong capability to hold — and it
   directly weakens P21.
3. There is a **legitimate need** underneath it: `audit_logs.company_id` is nullable for platform-level
   events, and those rows must be visible to someone. The need is real; the mechanism is ambient.

**Gap G-18 (effort 3), the recommended resolution:** split the read hatch from the write hatch. Remove
`OR app_is_platform_admin()` from the `WITH CHECK` clauses entirely — nothing should ever insert an
audit row into a tenant it is not acting within. Keep a read hatch, but narrow it to what it is for:
`company_id IS NULL` for platform events, plus an explicit `PlatformOperation` for the rare case of
reading a tenant's history during a support investigation. That case should carry a reason and write
its own audit row. Elsewhere in the system, `app_is_platform_admin()` is defined but referenced by no
policy — a loaded primitive sitting on the table. It should either be deleted or documented as reserved
with a written rule for what may use it, because the next engineer writing a policy will reasonably
assume that a function which exists is a function they are meant to use.

**What it forbids.**

- Any `->sudo()`-equivalent: a method, flag, parameter, or context key that disables scoping or
  permission checks.
- Running application requests as a role that can bypass RLS. Migrations run as the owner; the
  application never does.
- A "system user" with all permissions used to make ordinary operations succeed. Background jobs run
  with an explicit actor and an explicit company context, exactly like a request.
- `withoutGlobalScopes()`, raw queries that skip the tenant connection, or any read that reaches around
  `CompanyScope`.
- Unsetting or reassigning the tenant GUC mid-transaction to reach another tenant's rows.
- Adding an `OR <some ambient flag>` clause to a RESTRICTIVE boundary policy. That is the specific
  shape of this failure, and G-18 is the one live instance.
- A cross-tenant operation whose audit record is written outside the operation's transaction, or not at
  all.

**How we enforce it.**

```
   ORDINARY WORK                          CROSS-TENANT WORK
   ─────────────                          ─────────────────
   connection: qayd_app                   PlatformOperation (an object, not a mode)
   NOSUPERUSER NOBYPASSRLS                  ├── a second connection, distinct role
   GUC: app.current_company_id              ├── narrow per-table policy clauses
        set LOCAL, per transaction          ├── explicit actor + written reason + target
                                            ├── audit row IN THE SAME TRANSACTION
   there is no escape hatch                 │     (audit write fails ⇒ operation fails)
   because the ROLE cannot escape           └── time-boxed and enumerable
```

- **The role is the mechanism.** `NOBYPASSRLS` on the runtime role means no application-layer construct
  *can* grant a bypass. This is the strongest form of enforcement available and it already exists.
- **`PlatformOperation` as an action object** for genuine cross-tenant work — a distinct role, narrow
  per-table policy clauses, an actor, a written reason, and an audit row in the same transaction. If
  the audit write fails, the operation fails. **Gap G-19**, effort 8, needed before the first support
  tool that must read across tenants.
- **A grep-based architecture test** banning `withoutGlobalScopes`, unscoped raw queries against tenant
  tables, and any new function whose name suggests elevation. Cheap and effective (**Gap G-20**,
  effort 2).
- **The catalog introspection test** (P3) extended to assert that **no RESTRICTIVE boundary policy
  contains an OR clause referencing a session GUC other than the tenant id**. That single assertion
  makes G-18's class of regression impossible to reintroduce, and is the highest-leverage line of test
  code available for this principle.
- **The rule, stated for code review and for `CLAUDE.md`:** *there is no `->sudo()`. If you think you
  need one, you need a new permission, a new policy clause, or a `PlatformOperation` with a written
  reason.*

**Cost we accept.** Genuinely harder platform operations. Support engineers cannot simply look at a
customer's data; a cross-tenant read requires a built tool with a reason field. Legitimate
platform-level features — usage analytics, a cross-tenant health dashboard, consolidation for a group of
companies under one owner — each need explicit design rather than an ambient escape. Some of that work
will feel disproportionate for a small internal need. We accept it because the alternative degrades from
"secure by default" to "secure where remembered", and because it is unrecoverable: once a bypass exists,
it spreads to hundreds of call sites and cannot be withdrawn.

**When it may be revisited.** The prohibition on ambient elevation: never. The *set of PlatformOperations*
grows as legitimate needs appear, and each is a reviewed addition with a written justification —
which is the mechanism working, not an exception to it. Note also that group consolidation, when it
comes, is designed as a snapshot-and-freeze under a dedicated audited role and remains the **only**
cross-tenant read in the system; it must not be implemented by relaxing the boundary.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| `sudo()`-style ambient bypass | The demonstrated end state: hundreds of unaudited call sites, transitive propagation, and a cache that leaks across the boundary. |
| A superuser role for the application | Deletes the boundary entirely and makes every application bug a potential cross-tenant breach. |
| An "impersonation" mode | Better than ambient (it has a subject), still a *mode* rather than an operation: everything done while impersonating is elevated, including the parts that did not need to be. |
| Per-request bypass tokens | An off switch with extra steps (P2), and tokens leak into logs, tests, and fixtures. |
| Application-level checks instead of RLS | The specific alternative that produces the failure: filtering reads with SQL but writes with in-memory predicates, checking creates *after* the INSERT, and leaving raw SQL, queue jobs, and console commands entirely unfiltered. |

*Engineering risks.* **Pressure at the moment of an incident** — during an outage someone will want to
read across tenants immediately, and the absence of a hatch will feel like an obstacle. The answer is to
build the `PlatformOperation` tooling *before* it is needed at 3 a.m. **Workaround migration**: if
platform operations are too painful, engineers will do them as one-off migrations run as the owner
role, which bypasses RLS and is audited by nothing — that is the same failure through a different door,
and it argues for making the sanctioned path genuinely convenient.

*At scale.* Increasingly valuable. Cross-tenant breach risk grows with tenant count, engineer count, and
code paths, and this is the only property that does not degrade as those grow — because it is enforced
by the connection's role rather than by anyone's attention. It is also directly saleable: "the
application's database role is physically incapable of reading another tenant's data" is a sentence that
survives a security questionnaire in a way that "we check the company id" does not.

*Effort to enforce: 13 (G-18 policy fix 3 · G-19 `PlatformOperation` 8 · G-20 grep test 2) ·
Confidence: **High** on the principle and on the role-level mechanism, which is shipped and strong.
**Medium** on current compliance — one live ambient bypass exists on `audit_logs`, and it is on
precisely the table where it matters most.*

---

## P23 — Cross-module communication is by domain event only

**Statement.** A module never writes another module's tables and never calls into another module's
internals. It publishes a typed domain event after commit, or it calls a published Action through the
other module's front door. Nothing else crosses a module boundary.

**Why.** The alternative has a twenty-year outcome and it is fully documented. Where one module needs to
react to something another module does, it declares itself an extension of that module's class and
overrides the method — inserting its own behaviour into the middle of a transaction owned by someone
else. In the studied system, an inventory module injects cost-of-goods-sold lines into the accounting
module's posting call by overriding `_post`. A separate automation module goes further and *replaces*
`create` and `write` on registry classes at boot time.

The costs are specific and they compound:

- **Invisible at the call site.** Reading the posting method tells you nothing about what will actually
  run. The behaviour is somewhere else, in a module you may not know is installed.
- **Order-dependent.** What happens depends on module load order — an ordering that is a deployment
  property, not a design decision.
- **Unenumerable.** "What happens when an entry is posted?" has no answer short of grepping every
  installed module for an inheritance declaration. That question should be answerable in one command.
- **Untestable in isolation.** The accounting module cannot be tested without the modules that have
  injected themselves into it, which is P16 failing at the architectural level.
- **Uncontainable failure.** An exception in the injected code aborts the posting transaction of a
  module that never asked to depend on it.

An event boundary fixes all five at once because it inverts the direction of knowledge. The publisher
knows only that something happened; it does not know or care who listens. The subscriber declares its
interest explicitly, in a place that can be listed. And crucially, the subscriber runs **after commit**,
in its own transaction, so a failure in a downstream reaction cannot corrupt or roll back the financial
operation that triggered it (P8).

QAYD already has the template and it is the right shape: `App\Events\Accounting\JournalEntryPosted` is
`final`, `readonly`, carries a stable `NAME` constant (`accounting.journal.posted`), and has a
`fromEntry()` factory that captures the minimal set a subscriber needs *without re-reading the entry* —
which matters, because a subscriber that re-reads has coupled itself to the publisher's schema again
and re-created the problem the event was meant to solve.

**What it forbids.**

- Writing another module's tables. This is the hard line and the one worth restating on its own: no
  `INSERT`, `UPDATE`, or `DELETE` against a table owned by a module other than the one you are in.
- Extending another module by subclassing its classes or overriding its methods, and monkey-patching of
  any kind.
- Reading another module's tables directly for domain logic. A published read model, a query Action, or
  a view is the front door; the physical schema is private.
- Firing an event *inside* the transaction. Events are dispatched after commit, so a subscriber can
  never observe or affect uncommitted state.
- A subscriber that assumes it is the only one, or that runs before another subscriber. Handlers are
  independent, idempotent, and unordered by design.
- Mutable events. An event is a statement of fact about the past; a handler that modifies it is
  changing history for every handler after it.
- An event as a disguised command. `JournalEntryPosted` is a fact; `PostJournalEntry` sent as an event
  is a remote procedure call that has lost its error handling and its caller.
- An event emitted by the AI engine and treated as authoritative. Inbound AI messages are input and go
  through Controller → FormRequest → Action like everything else (P15).

**How we enforce it.**

```
   ACCOUNTING MODULE                                  OTHER MODULES
   ─────────────────                                  ─────────────
   PostJournalEntryAction
        │
        │ DB::transaction { … PostingService … }
        │
        ▼ AFTER COMMIT
   JournalEntryPosted ──▶ outbox row (same tx) ──▶ dispatcher
   final readonly                                       │
   NAME = accounting.journal.posted                     ├──▶ ClearReportCache        (reporting)
   fromEntry(): the minimal payload                     ├──▶ UpdateDimensionRollups  (analytics)
                                                        └──▶ NotifyApprovers         (notifications)

                                                   each handler: own transaction,
                                                   idempotent, independent, failure-isolated

   "What happens when an entry is posted?"  →  php artisan event:list
   Not: grep every module for an inheritance declaration.
```

- **Typed, final, readonly event classes** with a stable `NAME` constant, and a factory carrying a
  self-sufficient payload. Already the established shape.
- **An explicit `$listen` map**, so `php artisan event:list` is the complete and authoritative answer to
  what reacts to what. The enumerability property is the whole point.
- **Dispatch after commit**, so no subscriber observes a transaction that may still roll back.
- **A transactional outbox** — the event row is written in the same transaction as the business change,
  and a dispatcher publishes it afterwards. Without it, an event can be lost when the broker is down or
  fired for a transaction that rolled back, and both failures are silent. **Gap G-21**, effort 5.
- **An architecture test** asserting that no module references another module's Eloquent models or
  tables, with the module boundaries declared explicitly rather than inferred from namespaces.
  **Gap G-22**, effort 3. This is the test that keeps the principle from decaying into an aspiration,
  and it is the direct answer to the failure mode described above — the boundary violation is a
  compile-time-visible reference, so a machine can find it.
- **Idempotent handlers**, since after-commit delivery is at-least-once. A handler that is not
  idempotent is a bug waiting for a retry.

**Cost we accept.** Indirection, and a real loss of traceability in the debugger: a stack trace stops at
the dispatch and resumes somewhere else, so following a flow requires consulting the listener map rather
than stepping through. Eventual consistency becomes visible in the product — a report cache cleared by
a handler is briefly stale, and the UI must account for that. Debugging a failed handler means
correlating across transactions, which is why the correlation id in P21 is not optional. And there is a
genuine ordering cost: when a downstream effect *must* be part of the same atomic operation, an event is
the wrong tool, and the honest answer is that it belongs in the same Action — an event boundary is not a
way to make a synchronous requirement asynchronous.

**When it may be revisited.** The prohibition on cross-module writes: never. Where two "modules" are
discovered to be so tightly coupled that every operation in one requires a synchronous effect in the
other, the correct conclusion is usually that the boundary is drawn in the wrong place — merge them, or
move the shared concept into a third module. Redrawing a boundary is legitimate; punching a hole through
one is not.

*Alternatives considered.*

| Option | Why it loses |
|---|---|
| Direct method calls between modules | Creates a compile-time dependency graph that becomes cyclic, and drags the callee's failures into the caller's transaction. |
| Inheritance / method override (the studied approach) | Invisible, order-dependent, unenumerable, untestable, and failure-coupled. The documented twenty-year cost. |
| Shared database writes | Two modules owning one table means neither owns its invariants, and every schema change is a cross-team negotiation. |
| Events dispatched inside the transaction | Subscribers observe state that may roll back, and a slow subscriber holds a financial transaction open. |
| A full message broker between modules in one process | Operationally heavy for a monolith, and it moves failures out of the process without removing them. The outbox gives the durability without the topology. |
| Direct calls to the other module's public Actions | **Permitted**, and listed here to be explicit: a synchronous, authorized call through a published front door is legitimate. The prohibition is on reaching past the front door, not on using it. |

*Engineering risks.* **Event soup** — so many events and handlers that the flow is impossible to follow.
The defences are naming events as *facts about the past*, keeping the listener map readable, and
resisting the urge to emit an event for every state change rather than for the ones another module could
plausibly care about. **Lost events without the outbox**, which is precisely why G-21 exists. **Hidden
synchronous coupling**, where an "event" handler is actually required to complete before the operation
is meaningful — a design smell that should be resolved by moving the logic into the Action.

*At scale.* This is the principle that determines whether the system can be worked on by several teams
at once, and whether any module can ever be extracted into a separate service. Event boundaries are
already service boundaries; the AI engine relates to the domain layer exactly this way today. It is also
the principle whose absence is hardest to remedy later — coupling accumulated through inheritance cannot
be untangled incrementally, which is why it is enforced from the first module rather than the tenth.

*Effort to enforce: 8 (G-21 outbox 5 · G-22 arch test 3) · Confidence: **High** — the event template is
already correct and in use, the failure mode is exceptionally well evidenced, and the enforcement is a
straightforward architecture test.*

---

# Principles in tension

Principles that never conflict are not principles; they are preferences that happen not to have met yet.
These are the places where two of the rules above genuinely pull against each other. Each is resolved
here **once**, so the resolution is not re-litigated in a pull request under time pressure.

## §T1 — The database enforces it, but the database cannot explain it

**P1** wants every invariant in PostgreSQL. **P13** wants errors a caller can act on. A CHECK
constraint produces `violates check constraint "chk_je_balanced"` — unbypassable and useless to a user
or an agent.

**Resolution: both, in a fixed order.** The application checks first and produces a good error; the
database checks last and is the guarantee. The application check exists for *ergonomics*, never as the
enforcement, and it is never the reason to omit the constraint. When a constraint does fire, that is a
**bug in the application's pre-check**, not a user error — it means an unreachable path was reached, so
it is logged and alerted on, and the user gets a generic coded failure. Constraint names are therefore
part of the diagnostic surface: name them for the rule, not the columns.

## §T2 — Correctness refuses; usability wants to proceed

**P14** (nothing silently corrected) and **P6** (posted entries are immutable) both produce refusals and
friction where a competitor would proceed. A user who typed the wrong date must reverse and re-post; a
user missing an exchange rate is simply stopped.

**Resolution: the friction is real, the mitigation is a UX obligation, and the principle does not bend.**
A refusal that does not tell the user exactly what to do is a failure of P14, not an expression of it.
Every refusal must carry: what is wrong, which value caused it, what the acceptable value would be, and
a one-click path to supply it — create the missing rate inline, propose the corrected date as a
resolution the user accepts, show precisely which import rows failed and why. Where the correction is a
reversal, the flow is one action with the reason captured, not a re-keying exercise. **Product debt
incurred here is tracked as product debt, never resolved by relaxing the rule**, and specifically never
by a per-tenant strictness setting.

## §T3 — One door into the ledger versus subsystem independence

**P7** funnels every posting through one service. **P17** and **P23** want subsystems that are
replaceable and independent. Taken naively, P7 makes `JournalEntryPostingService` the file every
feature must modify — the god-object that P17 exists to prevent.

**Resolution: the engine holds only *universal* invariants; everything specific goes behind a seam or
into its own Action.** The test is a single question — *does this rule hold for every posted entry that
will ever exist?* Balance, period, postable accounts, numbering: yes, so they live in the engine.
Reversal semantics, tax repartition, FX revaluation, opening-balance residual handling: no, so each is
an Action that *calls* the engine, or a strategy the engine resolves through an interface. A parameter
added to the engine for one entry type is the signal that this boundary was crossed, and it is a review
stop.

## §T4 — The ledger is derived, yet it is not rebuildable

**P19** says derived data must be rebuildable. **P5** says the ledger must never be truncated and
replayed. `ledger_entries` is a projection of `journal_lines` — so which is it?

**Resolution: it is a projection that has been promoted to a record.** The rebuild *procedure* exists
and is exercised, but it runs **into a copy** and its output is compared, never swapped in. Three
properties make this coherent: the projection is 1:1 with its source and enforced by a unique index, so
divergence is detectable rather than theoretical; the projection is hash-chained (P21), so replacing it
would break the chain — which is the point; and the source itself is immutable once posted (P6), so a
faithful rebuild must produce identical rows, and if it does not, the correct response is an incident,
not a swap. **Any diff between the live ledger and its rebuild is a P1-severity bug**, and repairing it
by overwriting destroys the evidence.

## §T5 — Immutable history versus the right to erasure

**P5** and **P21** say nothing is ever deleted. Data-protection regimes say a person may require their
data be erased. Both are binding.

**Resolution: the conflict is avoided by design, and where it has already arisen it is resolved without
mutation.** Personal data does not belong on a ledger row: reference a party id, never a name, an email,
or a free-text description containing either. Where personal data appears in a document *attached* to a
transaction, it lives in a separate store with its own retention lifecycle, referenced by id — deleting
it leaves the accounting record intact and the reference dangling by design. Where erasure must reach
data already on an immutable row, it is a documented `PlatformOperation` (P22) that redacts **into a side
table** and leaves the original row and its hash intact, so the chain still verifies and the audit shows
that a redaction occurred, by whom, under what legal basis. Note that statutory bookkeeping retention
frequently *overrides* an erasure request for transaction records; that is a legal determination and
must be recorded as one, not assumed by an engineer.

## §T6 — No ambient privilege versus operability

**P22** removes every escape hatch. **P21** requires that an operator be able to investigate. At 3 a.m.
during an incident, someone will need to see a tenant's data and there will be no way to do it.

**Resolution: build the sanctioned path before it is needed, and make it fast.** `PlatformOperation`
(Gap G-19) must be pleasant enough that nobody reaches for a migration run as the owner role — which is
the same breach through a door nobody is watching. The design targets: available in seconds, requires a
typed reason, time-boxed, writes its audit row in the same transaction, and surfaces in a dashboard the
team actually looks at. **An unpleasant sanctioned path is the leading cause of unsanctioned ones.**

## §T7 — Ceremony versus velocity, including for machines

**P10**, **P11**, **P12**, and **P13** together mean a three-line endpoint touches a controller, a
FormRequest, a DTO, an Action, a Resource, and an exception class. That is a lot of typing for a small
change, and the pressure to shortcut it is constant.

**Resolution: reduce the ceremony, never the structure.** Generators, a shared base, and consistent
naming are all welcome; collapsing layers is not. There is a second-order argument that has become the
stronger one: **the uniformity is what makes the codebase machine-writable.** An agent can add a correct
endpoint by pattern precisely because every endpoint has the same shape, and it can be reviewed quickly
for the same reason. A codebase with six shapes for six endpoints is slower for a human and unusable as
a template.

---

# The enforcement gap register

Every principle above names its mechanism. Where the mechanism does not yet exist, it is recorded here
rather than left implied — because a principle whose enforcement is missing is a slogan, and an
*unrecorded* missing enforcement is a slogan nobody knows about. This register is the honest ledger of
the distance between what this document claims and what the system currently guarantees.

| ID | Gap | Principle | Effort | Priority |
|---|---|---|---|---|
| **G-1** | `REVOKE UPDATE, DELETE ON ledger_entries` from the app role; drop the two unreachable `_tenant_update`/`_tenant_delete` policies, so grants, policies, and the trigger agree | P5 · P7 | **1** | **Now** |
| **G-2** | Dedicated posting role — only it may `INSERT` into `ledger_entries` | P7 | 8 | Later |
| **G-3** | `BEFORE UPDATE` trigger: an `ai_generated` entry may not reach `posted` unless `approved_by IS NOT NULL` | P15 | **2** | **Now** |
| **G-4** | Architecture test: everything in `app/Data/` is `final readonly`, no framework or model types | P12 | 2 | Soon |
| **G-5** | `ValidationReport` — aggregate all rule violations into one coded response with `actual`/`expected` | P13 | 3 | Before AI drafting |
| **G-6** | Test that every thrown error code exists in the published catalog | P13 | 2 | Soon |
| **G-7** | Static rule banning empty `catch` and `??`/`?:` on `numeric-string` values | P14 | 3 | Soon |
| **G-8** | CI check: the AI service declares no database driver | P15 | **1** | **Now** |
| **G-9** | `qayd_ai` role (read-model `SELECT`, `*_proposals` `INSERT`, nothing else) + proposal tables | P15 | 5 | With AI drafting |
| **G-10** | Concurrency test group — gapless numbering under parallel posting, including post-allocation rollback | P16 · P9 | 5 | Before narrowing the posting lock |
| **G-11** | Architecture test: no concrete implementation referenced where a seam interface exists | P17 | 2 | Soon |
| **G-12** | `JournalEntryLifecycle::TRANSITIONS` map + mirroring status-transition trigger + equivalence test | P18 | **3** | **Now** |
| **G-13** | Every projection ships a rebuilder + scheduled and CI drift check | P19 | 5 | With the first rollup |
| **G-14** | Documentation-freshness CI check: referenced doc paths exist; cited constraints exist in the catalog | P20 | 3 | Soon |
| **G-15** | Shadow-capture trigger reconciled against Action-sourced audit rows (detects writes outside the Action layer) | P21 | 8 | Later |
| **G-16** | Activate the hash chain: `BEFORE INSERT` trigger, canonical payload, no bypass, signed periodic anchors | P21 | 21 | Before regulated-segment sales |
| **G-17** | `posting_attempts` — append-only record of *refused* postings with violation codes and AI confidence | P21 | 3 | Before AI drafting |
| **G-18** | Remove `OR app_is_platform_admin()` from `audit_logs` `WITH CHECK`; narrow the read hatch; decide the fate of the unused helper | P22 | **3** | **Now** |
| **G-19** | `PlatformOperation` action object — distinct role, narrow clauses, written reason, same-transaction audit | P22 | 8 | Before the first cross-tenant support tool |
| **G-20** | Grep-based architecture test banning `withoutGlobalScopes` and unscoped raw queries on tenant tables | P22 | 2 | Soon |
| **G-21** | Transactional outbox for domain events | P23 | 5 | Before the second subscriber |
| **G-22** | Architecture test: no module references another module's models or tables | P23 | 3 | Soon |
| **G-23** | Extend the catalog introspection test: no RESTRICTIVE boundary policy may OR on a session GUC other than the tenant id | P3 · P22 | **1** | **Now** |

**Total ≈ 99 points.** Most of it is scheduled work that arrives with the subsystem it protects.

**If only five are done — 10 points, and they close the widest gaps between claim and guarantee:**

| # | Gap | Points | Why now |
|---|---|---|---|
| 1 | **G-3** — AI cannot reach `posted` without a human approver | 2 | The flagship principle is currently enforced on `INSERT` only; the `UPDATE` path is application-only. |
| 2 | **G-18** + **G-23** — remove the ambient bypass from the audit boundary, and make its reintroduction impossible | 4 | The one live violation of P22, on the table where it matters most. |
| 3 | **G-1** — align grants and policies with the append-only trigger | 1 | Three mechanisms currently tell two different stories about the ledger. |
| 4 | **G-12** — the lifecycle transition map and trigger | 3 | The cost grows with every Action written against the implicit rules; the window is now. |
| 5 | **G-8** — CI check that the AI service has no database driver | 1 | Today's strongest AI boundary is an absence; one grep makes it a guarantee. |

---

# Amending this document

Principles are not permanent by decree. They are permanent because the conditions that would justify
changing them are rare — and when those conditions arrive, changing the principle is correct and
changing it *quietly* is not.

**To add a principle.** Append it with the next number. It must state the failure it prevents with
evidence, name its enforcement mechanism and where that sits on the ladder, state its cost honestly,
and give the conditions under which it may be revisited. A proposed principle with no enforcement
mechanism is not ready; either find one or file it as a convention, which is a different and weaker
thing.

**To change a principle.** Write an ADR that states which principle, what changed in the world, what
the new rule is, and what becomes true that was not true before. Amend this file in the same pull
request. The ADR is the permanent record of *why we thought that then*, and it is not edited afterwards
— a later reversal is a new ADR that supersedes it.

**To retire a principle.** Mark it retired in place, with the date and the superseding ADR. Never
delete it and never reuse its number. A reader encountering "P14" in a five-year-old code review comment
must be able to find out what it meant.

**What is not an amendment.** Reducing ceremony, improving an error message, adding a generator,
collapsing an obsolete seam, or tightening an enforcement mechanism are all ordinary engineering and
need no process. The process exists for changes to what the system *guarantees*.

**The test for any proposed relaxation.** Ask: *would this change make a wrong number possible where it
previously was not?* If yes, it is not a relaxation — it is a decision to accept a class of financial
error, and it needs to be made explicitly, in writing, by someone willing to own it.

---

# Version history

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-07-28 | Initial constitution: P1–P23, the ten commandments, the layer matrix, seven resolved tensions, and the enforcement gap register (G-1 … G-23). Synthesized from QAYD's shipped Sprint-1 and S2-01…S2-05 code plus the Phase-1 comparative ERP study; every principle argued on its own merits for this system, not adopted by precedent. |

---

*This document describes what QAYD guarantees and how. Where it claims an enforcement that does not yet
exist, that claim appears in the gap register above rather than in the body — the distinction between
what is guaranteed and what is intended is the one thing a document like this must never blur.*
