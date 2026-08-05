# 02 — Architecture Decisions

**QAYD's permanent decision record summary.** Version 1.0 · 2026-07-28 · Status: **binding summary**

---

## What this document is

`01_ENGINEERING_PRINCIPLES.md` says *how we build and why*. The ADR log in
[`../adr/`](../adr/) records individual decisions as they were taken, one file at a time, immutable
once accepted. This document is the layer between them: **the complete set of load-bearing
architectural decisions, each stated as an assertion, with the alternatives that lost, the price we
paid, and the conditions that would make revisiting it correct.**

It exists because an ADR log answers "what did we decide in July 2026?" and a principles document
answers "what do we believe?", but neither answers the question a new engineer actually asks:
*"Twenty-one things about this system look unusual. Which of them are deliberate, which are load-bearing,
and which are safe to change?"*

Every entry below has been written against a specific, verifiable body of prior art: a full study of
**Odoo 19.0.0 (commit `f3e407c6`, LGPL-3)**, the largest open-source double-entry accounting system in
production, recorded in [`../../research/odoo/ODOO_LEARNING.md`](../../research/odoo/ODOO_LEARNING.md)
(14,150 lines of citations), [`ODOO_TO_QAYD.md`](../../research/odoo/ODOO_TO_QAYD.md), and
[`ODOO_BACKLOG.md`](../../research/odoo/ODOO_BACKLOG.md). **No Odoo code was copied — ideas only.**

### The rule that governs every "Odoo's approach" paragraph

**No decision here is justified by "Odoo does it."** Odoo is evidence, not authority. Twenty years of
production use tells us which *problems* are real and which designs *compound badly*; it does not tell us
what is correct. Each entry therefore states Odoo's approach factually, then argues QAYD's position from
first principles independently of it.

Where Odoo is better, this document says so plainly. It is better in five places recorded below: its
lock-date cursor is a better *enforcement* mechanism than a period-record gate (AD-10); it takes no lock
at all on the posting path where QAYD currently takes one (AD-08); its permission error UX is
categorically better than what RLS gives us (AD-01); its declarative report model is better than
hard-coded statements (AD-16); and `account.lock.exception` is a better answer to late adjustments than
QAYD's current answer, which is nothing (AD-10). Where neither system is right, we designed a third thing
and said so (AD-10, AD-11, AD-17).

### Precedence

```
MANIFEST.md                          vision, laws, decision priority
   └── 01_ENGINEERING_PRINCIPLES.md  how we build, and why
         └── docs/architecture/adr/  the formal, immutable individual records
               └── 02 (this file)    the decisions as a set: alternatives, cost, lifespan
                     └── code        the only thing that actually runs
```

[`../FINAL_TECH_STACK.md`](../FINAL_TECH_STACK.md) governs the stack and wins any conflict. A formal ADR
wins over this document within its own scope. **This document never silently overrides either** — where
it extends or diverges, it says so in the register below.

### How to read a decision

| Field | What it means |
|---|---|
| **Status: Settled** | Decided, argued, and expensive to reverse. Changing it requires a superseding ADR, not a pull request. |
| **Status: Provisional** | Decided in direction, not proven by implementation. The design may still move. |
| **Status: Open** | Genuinely undecided. Listed in "Decisions we have NOT yet made". |
| **Status: Reversed** | Formerly held, now abandoned; kept so the reasoning is not re-litigated. |
| **Confidence: High** | The reasoning is structural, the alternatives were tested against a real system, and being wrong would require the problem itself to change. |
| **Confidence: Medium** | Sound reasoning, but a load-bearing assumption is unvalidated at our scale or the implementation does not exist yet. |
| **Confidence: Low** | We chose a direction because a decision was needed. Expect revision. |
| **Estimated lifespan** | Not a guess at the calendar. It is *the trigger*: the observable event that should make us re-open the decision. A decision with no stated trigger is dogma. |

---

## Index

| # | Decision | Status | Confidence | Revisit trigger |
|---|---|---|---|---|
| [AD-01](#ad-01--tenant-isolation-is-enforced-by-postgresql-not-by-application-diligence) | Tenant isolation is enforced by PostgreSQL, not application diligence | Settled | High | RLS proven to be the query bottleneck |
| [AD-02](#ad-02--one-database-one-schema-rls--not-schema-per-tenant-not-database-per-tenant) | One database, one schema, RLS — not schema- or database-per-tenant | Settled | Medium | Data-residency law, or one tenant >20% of the primary |
| [AD-03](#ad-03--the-general-ledger-is-an-append-only-projection-not-the-journal-lines-themselves) | The GL is an append-only projection, not the journal lines | Settled | High | Storage cost of duplication becomes material |
| [AD-04](#ad-04--there-is-exactly-one-writer-into-the-ledger) | There is exactly one writer into the ledger | Settled | High | Never, while the ledger is the product |
| [AD-05](#ad-05--money-is-numeric194-in-the-database-and-a-decimal-string-in-php) | Money is `NUMERIC(19,4)` in the DB and a decimal string in PHP | Settled | High | A currency needing >4 decimals, or PHP gaining native decimals |
| [AD-06](#ad-06--business-logic-lives-in-actions-models-are-thin-and-there-is-no-repository-layer) | Business logic lives in Actions; no repository layer | Settled | High | A second persistence engine appears |
| [AD-07](#ad-07--a-posted-entry-is-immutable-forever-correction-is-a-reversing-entry) | A posted entry is immutable forever; correction is reversal | Settled | High | Never; this is the product's promise |
| [AD-08](#ad-08--concurrency-is-optimistic-by-default-and-pessimistic-locks-are-scoped-to-the-smallest-resource-that-requires-serialization) | Optimistic concurrency by default; minimal pessimistic locks | Settled (impl. defective) | High | Lock-free numbering becomes available |
| [AD-09](#ad-09--journal-numbers-are-gapless-and-we-pay-for-it-deliberately) | Journal numbers are gapless, and we pay for it | Settled | Medium | Sustained posting rate exceeds one sequence row's throughput |
| [AD-10](#ad-10--fiscal-periods-are-a-dimension-locks-are-the-cursor-and-status-is-a-view) | Periods are a dimension, locks are the cursor, status is a view | Settled (unbuilt) | Medium | S2-07 implementation contradicts the model |
| [AD-11](#ad-11--analytic-allocations-are-rows--not-fixed-columns-and-not-jsonb) | Analytic allocations are rows — not columns, not JSONB | Settled (supersedes spec) | High | Row volume on `journal_line_dimensions` becomes pathological |
| [AD-12](#ad-12--every-failure-is-a-typed-coded-domainexception-and-validation-failures-are-aggregated) | Failures are typed, coded `DomainException`s; violations aggregate | Settled | High | Never; it is the AI contract |
| [AD-13](#ad-13--every-subsystem-we-expect-to-replace-sits-behind-a-named-seam) | Subsystems we expect to replace sit behind named seams | Settled | High | Seam count outgrows its value |
| [AD-14](#ad-14--after-commit-domain-events-are-the-only-cross-module-path) | After-commit domain events are the only cross-module path | Settled (outbox unbuilt) | High | Module extraction to services |
| [AD-15](#ad-15--laravel-owns-the-domain-postgresql-owns-integrity-fastapi-owns-inference--and-the-ai-engine-holds-no-database-credential) | Laravel/Postgres/FastAPI split; the AI engine holds no DB credential | Settled | High | Never for the credential rule |
| [AD-16](#ad-16--financial-statements-are-declarative-data--but-we-build-two-concrete-statements-before-we-build-the-engine) | Reports are data — but two statements ship before the engine | Settled | Medium | The second statement reveals the engine shape |
| [AD-17](#ad-17--the-audit-trail-is-hash-chained-and-externally-anchored) | The audit trail is hash-chained and externally anchored | Provisional | Low (anchoring) | GCC inalterability rule published |
| [AD-18](#ad-18--nothing-is-silently-corrected) | Nothing is silently corrected | Settled | High | Never |
| [AD-19](#ad-19--lifecycle-transitions-are-data-mirrored-by-a-database-trigger) | Lifecycle transitions are data, mirrored by a DB trigger | Settled (unbuilt, urgent) | High | Never; window closes with each new Action |
| [AD-20](#ad-20--a-cached-aggregate-is-permitted-only-over-an-append-only-source-and-every-projection-ships-a-rebuilder) | Cached aggregates only over append-only sources; every projection has a rebuilder | Settled | High | Drift detected in production |
| [AD-21](#ad-21--no-invariant-has-an-off-switch-and-there-is-no-ambient-privilege-bypass) | No invariant has an off switch; no ambient privilege bypass | Settled | High | Never |

---

## Register: where this document extends or diverges from an accepted record

Honesty here is the whole value of the file. Four entries below are **not** simple summaries of an
accepted ADR.

| Entry | Relationship to the accepted record | Action required |
|---|---|---|
| **AD-11** (dimensions as rows) | **Diverges from the frozen specification.** The spec designs fixed `cost_center_id` / `project_id` / `department_id` FK columns on `journal_lines` (tracked as TD-14). This document rejects that design. | A formal ADR (next free number) must be raised **before** TD-14 is implemented. Until then, TD-14 is blocked, not merely unscheduled. |
| **AD-10** (periods demoted from gate to dimension) | **Refines the specification.** The spec treats a period record as the posting gate. This document makes the lock cursor the gate and the period a reporting dimension whose status is a view. | Record in the S2-07 story and raise an ADR with the migration. The `FiscalCalendarResolver` seam already makes this a rebind, not a rewrite. |
| **AD-08** (minimal serialization) | **Names a defect in shipped code.** `PostingService` takes `SELECT … FOR UPDATE` on the fiscal-year row. That contradicts principle P9 and this decision. | Fix at S2-07 with concurrency tests. Logged as a defect, not a design. |
| **AD-14** (outbox) | **ADR-0006 declares a transactional outbox; the shipped code emits after-commit events without one.** The decision is accepted; the mechanism is missing. | Build the outbox before the second consumer exists. Until then, event loss on broker failure is an accepted, *known* exposure — not an unknown one. |

Everything else in this document is consistent with ADR-0001 through ADR-0010 and with
FINAL_TECH_STACK.md. AD-01 and AD-02 elaborate ADR-0005; AD-05 elaborates ADR-0004; AD-14 elaborates
ADR-0006; AD-15 elaborates ADR-0003 and ADR-0007.

---

## AD-01 — Tenant isolation is enforced by PostgreSQL, not by application diligence

**Status:** Settled

**Problem** — QAYD holds many companies' books in one system. A cross-tenant read is not a bug ticket; it
is a regulatory event that ends the company. The question is not *whether* to filter by `company_id` — it
is **which layer is allowed to be the last line of defence.** Every system that filters in the application
is one forgotten `WHERE` clause, one raw query, one queue job, one BI connection, or one new endpoint away
from a breach, and none of those failure modes announce themselves.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Application-layer filtering only** (a global scope / trait on every model) | Correctness becomes a property of *every query ever written, forever*, including queries written by people who have not read this document and by an AI agent. It also protects nothing that does not go through the ORM: raw SQL, `psql`, queue workers, reporting replicas, migrations, and console commands are all unfiltered. It is not a security control; it is a convention with good intentions. |
| **A dedicated data-access layer that is the only thing allowed to query** | Strictly better than a trait, and genuinely workable — but it relocates the same problem. Now correctness depends on nothing ever bypassing the layer, which is again unenforceable, and the layer becomes a bottleneck every feature must extend. It also cannot protect the BI tool or the replica. |
| **RLS as the only control, with no application filtering** | Safe, but throws away the query planner's best information. Without `company_id` in the predicate the planner cannot use the leading column of the composite indexes, and every query pays a scan-then-filter cost. |
| **RLS as the floor, application filtering above it** (chosen) | Costs one extra predicate per query and the discipline of enabling RLS on every new table. |

**Odoo's approach** — Odoo enforces multi-company isolation entirely in the application. `ir.model.access`
gives a model×operation ACL; `ir.rule` holds a row predicate written as a Python *domain* stored in a
`TEXT` column and evaluated through `safe_eval` on the request path. On **read**, that domain is compiled
to SQL and injected into the `WHERE` clause — a good design. On **write and delete** it is evaluated as a
Python predicate over hydrated rows, and on **create** it is checked *after* the `INSERT`, relying on
rollback. The whole system is disabled transitively by `sudo()` (552 call sites in the studied checkout,
181 in `addons/account` alone), and `company_id` on core accounting tables is nullable — a NULL row is
invisible to an `IN (…)` predicate and therefore leaks *out* of the boundary. There are 72 hand-written
`ir.rule` records covering 192 models, with nothing checking that a model carrying `company_id` has a rule.

**QAYD's approach** — Every tenant-owned table carries `company_id BIGINT NOT NULL`. Every such table runs
`ENABLE ROW LEVEL SECURITY` **and** `FORCE ROW LEVEL SECURITY`, with a **RESTRICTIVE** boundary policy
comparing `company_id` against the `app.current_company_id` GUC. The application runtime connects as a role
that is `NOSUPERUSER NOBYPASSRLS`, so the policy applies to the owner too. Laravel pins the company with
`SET LOCAL` inside the request transaction, from a verified `company_users` membership — never from client
input. `BelongsToCompany` / `CompanyScope` still filter in the application for planner performance, and
`CompanyScope` **fails closed**: no resolved company means no query, not an unfiltered one. A CI
catalog-introspection check queries `pg_class` / `pg_policy` / `pg_attribute` and **fails the build** if any
table with a `company_id` column lacks `NOT NULL`, `relrowsecurity`, `relforcerowsecurity`, and the named
restrictive policy.

**Why QAYD differs** — Not because Odoo's model is careless; it is carefully built. It differs because of
one structural asymmetry: **an application-layer control protects only the paths that go through the
application.** Odoo's own ORM emits raw SQL in several places, and those writes are unfiltered by
construction. The set of code paths that will touch this database over ten years — analytics, migrations,
support tooling, an AI service, a future Go worker — is unbounded and unknowable. The set of paths that go
through PostgreSQL is exactly one. Putting the boundary in the only universal chokepoint is the only
version of this decision that survives contact with an unknown future.

The second reason is testability. `ir.rule` correctness is unverifiable: it depends on two evaluators (SQL
and Python) agreeing about every operator for every field type forever. RLS correctness is a single
catalog query. That converts our tenancy *convention* into a *mechanism* — the distinction principle P3
exists to enforce.

**Tradeoffs**

- **Error UX is strictly worse, and Odoo's is strictly better.** A blocked Odoo read tells the user which
  rule failed and suggests which company to switch to. RLS returns zero rows and says nothing. That is a
  real, daily support cost, and it is the price of enforcement living below the layer that knows the
  user's intent. Mitigation is a deliberately-built diagnostic path that distinguishes *does not exist*
  from *exists in a company you can access* — which must be rate-limited and audited, because it is an
  existence oracle by construction.
- Every query pays policy evaluation. Indexes must lead with `company_id` or the policy is a performance
  cliff rather than a predicate.
- The GUC is per-*connection*, which makes connection pooling a correctness concern rather than a
  performance one (see the open questions).
- Genuinely global reference data (`account_types`, the permission catalog) needs a deliberate carve-out,
  and each carve-out is a decision someone can get wrong.

**Future risks**

1. **Pooling.** Under PgBouncer transaction pooling, a connection is handed to another request mid-session.
   A `SET` instead of `SET LOCAL`, or a query issued outside an explicit transaction, leaks one tenant's
   context into another tenant's request. This is invisible in single-connection testing and is the single
   highest-severity latent risk in the system. Two independent research agents reached it from different
   subsystems.
2. **A managed provider changing default roles or extensions** could silently grant `BYPASSRLS`. The boot
   assertion on role attributes must be fatal, not a warning.
3. **Policy-induced plan regressions** at large row counts, where the planner cannot push the policy
   predicate down through a complex join.

**Estimated lifespan** — Indefinite. The trigger for revisiting is *not* a security event (a breach would
prove the decision right); it is a demonstrated, profiled case where RLS evaluation is the dominant cost of
a critical query and no index or policy rewrite fixes it. Even then the answer is likely a materialized
read model, not removing the floor.

**Confidence: High.** The reasoning is structural rather than empirical: it depends only on the claim that
the set of future database clients is larger than the set of clients we control, which is true of every
system that lives long enough. The independent validation is that Odoo's own decomposition — global rules
AND-ed, group rules OR-ed — maps exactly onto PostgreSQL's RESTRICTIVE/PERMISSIVE policy semantics. Our
restrictive-boundary + permissive-scope split is therefore a pattern proven over twenty years, moved one
layer down.

---

## AD-02 — One database, one schema, RLS — not schema-per-tenant, not database-per-tenant

**Status:** Settled

**Problem** — AD-01 settles *how* isolation is enforced. This settles *where tenant data physically lives*,
which is a different and more expensive question: it determines migration cost, backup strategy, blast
radius, per-tenant cost floor, the shape of cross-tenant analytics, and whether data-residency law can be
satisfied at all. It is the load-bearing decision in the scaling story and cannot be changed cheaply once
there are paying customers.

**Alternatives considered**

| Model | Isolation | Migration cost | Ops cost at N tenants | Cross-tenant analytics | Noisy-neighbour | Per-tenant restore |
|---|---|---|---|---|---|---|
| **Database-per-tenant** | Physical, strongest | O(N) — N databases, N failure modes, N versions in flight | O(N); connection pools multiply | ETL project | None | Trivial |
| **Schema-per-tenant** | Strong logical | O(N) migrations; catalog bloat past a few thousand schemas | O(N) but cheaper than DB-per-tenant | Union-all across N schemas, or ETL | Partial (shared buffers/CPU) | Easy |
| **Single schema + RLS** (chosen) | Logical, enforced by the engine | O(1) | O(1) | A plain query | Real, must be managed | Hard — needs `company_id`-scoped export |
| **Hybrid: RLS by default, dedicated DB for the few** | Both | O(1) + O(k) for k dedicated | O(1) + k | Two paths | Solved for the k that matter | Trivial for the k |

Database-per-tenant lost because operational cost scales with customer count in a business whose margin
depends on it not doing that. A thousand tenants means a thousand migrations to run and verify, a thousand
backup schedules, and a deploy that is partially applied for hours — which means every piece of application
code must tolerate two schema versions simultaneously, forever. It also makes the product's own
cross-tenant needs (platform metrics, fraud signal, benchmarking) into a data-engineering project.

Schema-per-tenant lost for a subtler reason: `search_path` juggling defeats the plan cache, so per-query
planning cost rises exactly as tenant count rises, and the catalog itself becomes a scalability limit in the
low thousands of schemas. It buys weaker isolation than database-per-tenant at most of the operational cost.

**Odoo's approach** — Odoo is database-per-tenant in practice: an Odoo "database" is a tenant, and
multi-company inside one database is a secondary feature layered on with `ir.rule`. The consequence visible
in the study is that its multi-company path was never load-bearing enough to be hardened — `company_id` is
nullable on core accounting tables, isolation is a single ORM-level rule, and `sudo()` bypasses it. The
model works for Odoo because its deployment unit is a tenant; it does not generalize to a SaaS whose
economics require one operational surface.

**QAYD's approach** — One PostgreSQL database, one schema, one migration timeline, one backup, one pool.
Tenancy is a `company_id` column plus the RLS boundary of AD-01. Physical separation is available later as
an *escape hatch for named tenants*, not as the default topology: the schema, the RLS policies, and the
application are all identical whether a tenant sits in the shared primary or in a dedicated one, so moving a
tenant is a data migration, not an architecture change. The design constraint we hold today is therefore
narrow but strict: **nothing may make a single-primary assumption that a per-tenant move would break** — no
cross-tenant foreign keys, no global sequences whose values must be unique across tenants, no report that
joins two companies' rows.

**Why QAYD differs** — The deciding argument is not isolation strength; database-per-tenant wins that
outright. It is that **isolation strength and operational scalability trade against each other only if
isolation must be physical**, and RLS makes it not physical but still engine-enforced. Given a correctly
enforced boundary (AD-01), the marginal isolation gained by physical separation defends against exactly one
threat model that RLS does not: a PostgreSQL vulnerability that defeats RLS itself. That is a real risk, but
it is not a risk we can price above the certainty of O(N) operational cost, because O(N) cost is not a risk —
it is a bill that arrives every month and grows.

Two second-order arguments matter as much:

1. **Migration honesty.** With one database, a migration either applied or it did not. With N, the system
   spends most of its life in a mixed state, and every mixed state is a correctness question for a ledger.
2. **The product needs cross-tenant reads it can trust.** Benchmarking ("your DSO vs. your sector"), fraud
   signal, and platform health are all product features here, not just internal metrics. In a shared
   database those are queries under an audited role. In N databases they are an ETL pipeline with its own
   staleness and its own copy of everyone's financial data — which is a *worse* privacy posture, not a
   better one.

**Tradeoffs**

- **Noisy neighbours are real.** One tenant importing five years of history competes for the same buffers
  and I/O as everyone else. This must be managed with statement timeouts, per-tenant rate limits, and
  eventually partitioning of the largest tables by `(company_id, period)` — which the append-only ledger
  (AD-03) makes possible and which Odoo's mutable ledger structurally forbids.
- **Per-tenant restore is genuinely hard.** Point-in-time recovery restores everyone. Restoring one
  company's ledger to yesterday requires a `company_id`-scoped logical export and a documented, *rehearsed*
  procedure. This is unbuilt and is the most under-appreciated cost of the decision.
- **Blast radius is the whole platform.** One corrupt migration, one runaway query, one exhausted
  connection pool affects every customer. Database-per-tenant would have contained it.
- **Data residency is unsolved.** If a Saudi entity must keep data in-Kingdom, the shared primary cannot
  satisfy it; the answer is a second regional deployment, which is why nothing may assume one primary.

**Future risks**

1. **A data-residency requirement arriving before the escape hatch is built.** This is the most likely
   invalidator, and the GCC regulatory direction points at it.
2. **A single tenant large enough to distort the primary** — an enterprise group with tens of millions of
   ledger rows — turning a shared resource into their private one.
3. **Vertical scaling running out** before the largest tables are partitioned. Mitigated by AD-20's rollups,
   which move the dominant read (trial balance) off the largest table.

**Estimated lifespan** — Three to five years of normal growth, or until whichever comes first: a customer
contract requiring in-country data residency, a single tenant exceeding ~20% of primary load, or sustained
write volume beyond what one primary comfortably serves. **None of those reverse the decision** — they
trigger the hybrid (dedicated primaries for named tenants, identical schema), which is why the "no
single-primary assumptions" constraint is enforced from day one.

**Confidence: Medium.** High confidence that this is right for the first several hundred tenants: the
operational-cost argument is arithmetic, not judgement. Medium overall because it rests on one unvalidated
assumption — that a single primary carries the tenant base to the revenue horizon — and we have no
production load data. The honest statement is that we have chosen the model with the cheapest *exit* rather
than the strongest *isolation*, and that is a bet on optionality.

---

## AD-03 — The general ledger is an append-only projection, not the journal lines themselves

**Status:** Settled

**Problem** — Every accounting system must answer "what is the balance of account X at date D?" The
structural question underneath is whether the general ledger is a **view over the transactional tables** or
a **separate, purpose-built store**. The answer determines whether the ledger can be immutable, whether it
can be partitioned, whether balances can be safely cached, and whether tamper-evidence is cheap or
impossible.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Lines are the ledger** (query `journal_lines WHERE status = posted`) | One table, no duplication, no projection to keep honest — genuinely attractive. It loses because the same rows must then serve two irreconcilable masters: the draft-editing workflow needs them mutable, and the ledger needs them immutable. Every subsequent feature that wants to annotate a posted line (reconciliation state, matching groups) writes to the ledger, and the ledger stops being a record of what happened. |
| **A materialized view refreshed on write** | Postgres materialized views cannot be incrementally refreshed; a full refresh on every post is absurd at scale, and a partial one is a hand-written projection with extra steps and no triggers. |
| **Event sourcing — the ledger is the event log, balances are folds** | Philosophically the closest fit, and the append-only projection is a deliberate 80% of it. Full event sourcing lost on cost: it requires either a snapshot strategy or O(history) reads for every balance (see AD-20), and it puts an unfamiliar programming model in the path of every engineer for benefits we get more cheaply from an append-only table plus SQL aggregates. |
| **Append-only projection** (chosen) | Costs one duplicated row per posted line and the obligation that the projection be rebuildable. |

**Odoo's approach** — There is no ledger table. Journal lines *are* the general ledger: every GL read is
`account_move_line` filtered on the denormalized `parent_state = 'posted'`, and every balance is a scan —
there are no stored aggregates at any granularity, and `_compute_current_balance` has no date bound, so
opening an account form aggregates its entire history. Because reconciliation state (`amount_residual`,
`reconciled`, `full_reconcile_id`) lives *on the line*, the ledger must be mutable; the code therefore
issues raw `UPDATE account_move_line SET full_reconcile_id = …` and hand-invalidates the ORM cache, and its
own source comments document a staleness bug arising from exactly that. Deleting a move cascades its lines
away.

**QAYD's approach** — `ledger_entries` is a separate, append-only projection: one row per posted journal
line, carrying `signed_base_amount` so any balance is a single `SUM()`, with `UNIQUE(journal_line_id)` and a
trigger that rejects `UPDATE` and `DELETE` **even for the owning role**. It carries no status column,
because it is posted by construction. Nothing that varies after posting — reconciliation residuals, matching
group membership, revaluation state — is ever stored on a ledger row; all of it lives in side tables keyed
to `ledger_entry_id`.

**Why QAYD differs** — Because immutability is not a property you can add later. Once one column on the
ledger row is mutable, the table needs `UPDATE` grants, and the moment it has `UPDATE` grants every
guarantee that depends on append-only evaporates simultaneously: the tamper-evident hash chain (AD-17), the
safety of cached aggregates (AD-20), and the ability to partition by `(company_id, period)` — because a
partitioned table with mutating rows invites cross-partition row movement on exactly the hottest path.

The single decision that forces a ledger to be mutable is putting *derived, changing state on the ledger
row*. Odoo demonstrates the full downstream cost of that choice with unusual clarity, and it is a cost paid
continuously rather than once. So the rule is stated as an invariant, not a preference: **a ledger row is a
statement about the past, and the past does not change.** Reconciliation, revaluation, and matching are all
statements about the *present*, and belong in tables that are allowed to have one.

The duplication objection deserves a direct answer: yes, a posted line exists twice. That is the price of a
list of facts that no code path can edit. Storage is the cheapest resource we have; the integrity of the
ledger is the most expensive.

**Tradeoffs**

- Storage roughly doubles for posted line data, and the write path does two inserts instead of one.
- The projection can drift if it is ever written by anything but `PostingService` — which is why AD-04 and
  AD-20 (a mandatory rebuilder plus a scheduled drift check) are not optional companions but part of this
  decision.
- "Correcting" anything requires a new row. There is no way to fix a typo, ever. That is the intended
  behaviour, and it will feel wrong to every new engineer for about a month.
- Queries that want draft and posted data together must join two tables.

**Future risks**

1. **Storage cost becoming material** at very large volume — addressed by partitioning and cold-storage
   archival of old partitions, both of which append-only makes straightforward.
2. **A feature request for a mutable ledger column** — most likely reconciliation, most likely justified by
   query convenience. This is the specific pressure that must be refused; the side-table pattern exists to
   absorb it.
3. **Rebuild divergence** if the projection logic and the posting logic ever disagree about signing or
   currency conversion. Mitigated by rebuilding from the *same* code path, not a reimplementation.

**Estimated lifespan** — Permanent for the life of the product. The trigger for revisiting is not
architectural but economic: if storage duplication ever exceeds a material fraction of infrastructure cost
*and* an alternative preserves immutability, revisit. No such alternative is currently known.

**Confidence: High.** This is the decision the rest of the system's advantages are derived from — safe
aggregates, a cheap hash chain, partitionability, and reconciliation without mutation are all dividends of
it. Odoo provides the counterfactual in production: every one of those four is unavailable to it, and the
research traces each unavailability to this single design choice.

---

## AD-04 — There is exactly one writer into the ledger

**Status:** Settled

**Problem** — A double-entry ledger has invariants that cannot be expressed as a single-row constraint:
debits equal credits *across the entry*, in both the entry currency and the base currency; the target period
is open; every account is postable; the number is the next in an unbroken sequence. If more than one code
path can produce posted rows, each path must re-implement every invariant, and correctness becomes the
intersection of what every author remembered.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Many writers, invariants in the database only** | Attractive given P1 — but the cross-row, cross-currency balance check and the period-resolution logic are not expressible as declarative constraints without triggers that would need to re-derive the same logic in PL/pgSQL. We would have two implementations to keep in sync, in two languages. |
| **A base class or trait every writer inherits** | Enforcement by inheritance is enforcement by convention: the next writer that forgets to extend it compiles fine. It also cannot stop raw SQL. |
| **Per-document posting paths** (invoice posting, payment posting, payroll posting) | The most natural design as features arrive, and the one every system drifts into. It loses because the invariants are properties of *the ledger*, not of invoices — so N document types means N chances to be subtly wrong about currency rounding or period resolution, and the bugs surface as an imbalanced trial balance months later. |
| **One `PostingService`, called by every document type** (chosen) | Costs a chokepoint that every feature must fit through, and a service that grows. |

**Odoo's approach** — Odoo also has one conceptual posting path (`AccountMove._post()`), which is the right
instinct, and its unified `account.move` document model — invoice, bill, payment, and manual entry as one
table with a discriminator — is a genuinely good design that our `entry_type` enum mirrors. The execution is
where it comes apart: `_post()` is a ~230-line method performing access control, roughly twenty
heterogeneous validations, silent mutation of the accounting date, analytic-line creation, reconciliation
repair, partner statistics, and a paid-invoice hook. Numbering is not in the method at all — it happens as
an ORM side effect of writing `state='posted'`, so nothing in the posting code mentions it and the code must
force a flush before relying on the number existing. Meanwhile other code paths write ledger rows directly
in raw SQL, so the single path is not actually single.

**QAYD's approach** — `PostingService` is the only code that writes posted state or `ledger_entries`. Its
sequence is fixed: row-lock the entry, re-derive the balance **from the lines** with zero tolerance in both
the entry currency and the base currency, resolve the target period through the `FiscalCalendarResolver`
seam, verify every account is postable, allocate the gapless `journal_number`, mark the entry posted,
project append-only `ledger_entries` rows, and emit `accounting.journal.posted` **after commit**. Every
document type — invoice, payment, opening balance, reversal, revaluation — composes into this path rather
than around it. The rule is backed by table grants rather than convention: the runtime role's write access
to ledger tables is the enforcement, not a code review.

**Why QAYD differs** — Two specific properties, both learned from the failure mode rather than the design.

First, **re-derive, never trust.** The balance is recomputed from the line rows inside the same transaction
that posts them, not read from a cached header total. A header total is a claim; the lines are the fact. A
posting path that validates the claim will eventually post an unbalanced entry whose header happens to say
zero.

Second, **posting is composed, not monolithic.** `_post()` demonstrates the cost of the opposite: there is
no way to test numbering independently of invoice validation, or validation independently of side effects.
Our path is a sequence of Actions behind one service, so each step is independently testable and the service
is an orchestration rather than an implementation.

**Tradeoffs**

- `PostingService` is a chokepoint. Every new document type queues behind changes to it, and it will attract
  conditional logic that belongs in the callers. The discipline is that callers build a valid entry; the
  service posts it.
- A single path means a single failure mode: a regression here breaks everything. It gets the most tests in
  the system and deserves them.
- Genuine special cases (storno reversal, opening balances) must be expressed as strategy seams inside the
  path rather than as parallel paths — more design work than a bespoke path each time.

**Future risks**

1. **Bulk import performance.** One entry at a time through a full validation path is the right default and
   the wrong shape for a 50,000-row migration. The answer is batching *within* the path (validate N,
   allocate N numbers, insert N), never a fast path around it.
2. **Erosion by exception.** The first "just this once" writer is the failure. Table grants make it a
   permission error rather than a judgement call.

**Estimated lifespan** — Permanent while the ledger is the product. There is no plausible trigger for
allowing a second writer; there are plausible triggers for *decomposing* the single writer further, which is
a refinement rather than a reversal.

**Confidence: High.** The counterfactual is directly observable: the system that allowed raw-SQL ledger
writes documents its own resulting bug class in source comments. Our version is additionally enforced by
database grants rather than by discipline, which moves it from a principle to a mechanism.

---

## AD-05 — Money is `NUMERIC(19,4)` in the database and a decimal string in PHP

**Status:** Settled

**Problem** — Money must be exact. The Kuwaiti dinar has three decimal places; VAT arithmetic produces
fourth-place intermediates; tax repartition and payment allocation produce remainders that must be
distributed deterministically and reproducibly. A representation that is even slightly lossy produces a
trial balance that is off by fils — which is not a rounding annoyance in an accounting system, it is an
unbalanced ledger and a failed close.

**Alternatives considered**

| Representation | Exactness | Ergonomics | Why it lost / won |
|---|---|---|---|
| **IEEE-754 float / double** | No — 0.1 is unrepresentable; error accumulates over summation | Excellent | Lost outright. Requires an epsilon everywhere, and the epsilon is a per-comparison judgement call that will eventually be wrong. |
| **Integer minor units** (fils as `BIGINT`) | Yes | Poor across currencies | Genuinely strong, and the right answer for a single-currency system. Lost because the scale factor is currency-dependent (KWD 1000, USD 100, JPY 1) — so every arithmetic operation needs the currency in scope, every cross-currency calculation is a scale conversion, and intermediates below the minor unit (a tax rate applied to a base) have nowhere to live. It trades a representation problem for a units-tracking problem, and units bugs are silent. |
| **`NUMERIC` in the DB, float in PHP** | No | Excellent | The most common real-world compromise, and the most dangerous: the schema *looks* correct while every computed value has passed through a float. |
| **`NUMERIC(19,4)` + bcmath decimal strings** (chosen) | Yes, end to end | Poor — no operators, verbose | Costs ergonomics and CPU; buys exactness with no epsilon anywhere. |

**Odoo's approach** — Odoo's `Monetary` field maps to a `numeric` column, so the *storage* is exact. The
Python value, however, is a `float`, and comparisons in the accounting code are float comparisons
(`line.balance > 0.0`). Correctness therefore depends on `currency.round` / `float_compare` being applied by
convention at every site. The consequence is visible in the rounding helper: `float_round` adds an epsilon of
`2**(log2(|x|) − 50)` specifically to compensate for IEEE-754 tie mis-detection such as
`2.675 == 2.6749999999999998`. Tax repartition factors are stored as `Float(digits=(16,12))` and validated
with `float_compare(..., precision_digits=2)`, which tolerates roughly 0.005 of slack in an invariant that
should be exact.

**QAYD's approach** — Money is `NUMERIC(19,4)` in every column that holds an amount, and a **decimal
string** everywhere in PHP. Arithmetic goes through bcmath with an explicit scale; aggregation happens in
SQL, not in PHP. Comparisons are exact (`bccomp($a, $b, 4) === 0`) — there is no tolerance parameter
anywhere in the system, which is what lets `PostingService` assert balance with **zero** tolerance in both
currencies (AD-04). The convention is enforced rather than agreed: a `numeric-string` type under static
analysis, and a ban on native arithmetic operators applied to money-typed values. Percentages and
allocation factors that must be exact are stored as **integer parts-per-million**, not decimals, so
"sums to 100%" is an integer comparison a database constraint can express.

**Why QAYD differs** — Not because float is unknown to be inexact; everyone knows that. Because of what the
compensation costs. That epsilon exists *solely* because the engine is float-based, and it is an entire
category of complexity — tie-breaking, precision digits, per-currency rounding conventions applied by
convention at hundreds of call sites — that simply does not exist if the values are never floats. We are
not buying accuracy; we are deleting a subsystem.

The choice of *decimal strings* over a PHP object wrapper is deliberate and less obvious. A `Money` value
object is more ergonomic, but it must serialize somewhere, and every boundary — JSON to the frontend, the
database driver, the queue payload, the AI engine's HTTP contract — is a place where an object becomes a
scalar and someone chooses `(float)`. A string crosses every boundary unchanged and is exactly what
`NUMERIC` accepts and returns. Value objects are then a *layer over* the canonical string, not a replacement
for it.

`(19,4)` specifically: 4 decimals gives one digit of headroom below the KWD's three, so fils-level
intermediates survive rounding to the presentation scale; 19 total digits carries about 999 trillion units,
beyond any real ledger.

**Tradeoffs**

- **Ergonomics are genuinely bad.** `bcadd($a, $b, 4)` is not `$a + $b`, the scale argument must be passed
  every time, and forgetting it silently truncates to bcmath's default scale. This is the most likely place
  for a subtle bug in the entire codebase, and static analysis on a `numeric-string` type plus a ban on
  arithmetic operators over money is a mandatory countermeasure, not a nice-to-have.
- bcmath is slower than native arithmetic. Irrelevant at our transaction volumes; aggregation happens in
  SQL, not PHP (see below).
- Every developer must learn a non-obvious rule on day one.
- The rule needs a boundary policy: quantities and percentages are *not* money and must not be forced
  through it, or the discipline becomes noise and gets ignored where it matters.

**Future risks**

1. **A currency or an instrument needing more than 4 decimals** — crypto, or unit prices quoted to six
   places. This is the most likely invalidator, and it is a scale change (`NUMERIC(19,6)`), not a
   representation change: painful but not architectural.
2. **PHP gaining a native decimal scalar**, which would make the string convention obsolete ergonomically
   while leaving the storage decision untouched.
3. **Erosion at the edges**: a report, an export, or a JavaScript chart doing float math on values that were
   exact right up to the boundary. Presentation-layer float is acceptable; anything that flows back into a
   posted amount is not.

**Estimated lifespan** — Permanent for the storage format. The PHP-side representation could change within
a decade if the language gains native decimals. The trigger is exactly that, or a product requirement for
sub-fils precision.

**Confidence: High.** Exactness is a binary property, not a spectrum, and the alternative's cost is directly
observable in a production system: an epsilon computed with `log2` in the hot path of a per-line loop,
existing purely to hide accumulated representation error. There is no version of "mostly exact" that an
accountant accepts.

---

## AD-06 — Business logic lives in Actions, models are thin, and there is no repository layer

**Status:** Settled

**Problem** — Accounting logic is large, long-lived, and organized by *operation* ("post an entry",
"reverse an entry", "revalue foreign balances"), while database tables are organized by *data*. Where
behaviour is placed determines what can be unit-tested, what can be reused from a queue worker or the AI
path, and how large the most-depended-upon classes become over a decade.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Fat models (Active Record with behaviour)** | Organizes by data while operations organize by use case, so the most volatile logic accumulates in the most-depended-upon class. Every test of a rule becomes an integration test, because instantiating the model means touching persistence. Ten years of this produces the god model. |
| **Fat service classes** (one `AccountingService`) | Becomes a god object with a different name. Its constructor dependencies are the union of everything any method needs, so every test builds the world. |
| **Services + repositories** (a repository interface per aggregate) | The strongest alternative, and the one worth arguing properly. It buys persistence-swappability — which is only valuable if persistence will actually be swapped. We have decided the opposite: PostgreSQL is not a detail to be abstracted, it is where the invariants live (P1). A repository that hides `NUMERIC`, `EXCLUDE`, `FOR UPDATE`, `ON CONFLICT`, RLS, and deferred constraint triggers behind a portable interface would hide precisely the features we chose the database for. It costs an interface, an implementation, a test double, and a translation layer per aggregate, in exchange for portability we have committed to never using. |
| **Domain model with rich aggregates (classic DDD)** | Genuinely strong where invariants are complex — which ours are. It loses on duplication: the aggregate would enforce in PHP what the database already enforces in DDL, so every invariant has two homes and they drift. We take the parts that pay (value objects, explicit boundaries) without the aggregate root. |
| **Actions: one class, one operation, one public method** (chosen) | Costs class proliferation and a navigability problem. |

**Odoo's approach** — Odoo is the fat-model design taken to its conclusion, and is the clearest available
evidence of where it ends. `AccountMove._post()` performs access control, ~20 validations, silent date
mutation, analytic-line creation, reconciliation repair, partner-rank statistics, and a marketing hook in
one method; `account_tax.py` is 5,309 lines. Cross-module behaviour is added by MRO override and model
inheritance — a stock module injects COGS lines into the accounting module's posting transaction by
wrapping `_post`, which is invisible at the call site and order-dependent. The result is that no operation
has a seam: numbering cannot be tested without invoice validation, and the set of things that happen when
you post is not enumerable from any single file.

**QAYD's approach** — One Action per operation: a `final` class, constructor-injected dependencies, one
public `execute()`, returning a `final readonly` DTO. Models carry relationships, casts, and scopes — no
business rules, no orchestration, no queries beyond scopes. Controllers translate HTTP and nothing else
(P11). Persistence stays visible: Actions use Eloquent and, where the database feature matters, raw SQL —
because `SELECT … FOR UPDATE`, `ON CONFLICT DO UPDATE`, and a deferred constraint trigger are the design,
not an implementation detail to be encapsulated away.

**Why QAYD differs** — Because the unit we most need to be small and independently verifiable is the
*operation*, not the *entity*. "Reverse a posted entry with a reason, through the posting path, rejecting
cycles and over-reversal" is a testable thing with a name; `JournalEntry::reverse()` is the same code with
its dependencies hidden and its test forced through persistence.

The no-repository decision deserves its own defence, because it is the one most likely to be challenged by
an experienced engineer joining. The argument for repositories is swappability and testability. We reject
swappability as a goal (AD-13 defines the seams we *do* want, at volatility boundaries that actually move —
the fiscal calendar, the reversal strategy — not at the database, which does not). Testability we get more
cheaply: an Action's dependencies are constructor arguments, and the database in tests is a real PostgreSQL
with the real constraints, which is the only way to test code whose correctness *is* those constraints. A
repository test double would pass on invariants the database would reject, which is worse than no test.

**Tradeoffs**

- Hundreds of small classes. Navigability becomes a real cost, mitigated by context subdirectories and
  strict naming, and it is a genuine tax on newcomers.
- Actions calling Actions can reintroduce hidden orchestration. Permitted one level for genuine
  composition; flagged in review beyond that.
- Coupling to Eloquent and to PostgreSQL is explicit and accepted. Changing ORM or engine would be a large
  project. We have decided that is the correct thing to be bad at.
- The rule needs enforcement or it decays: architecture tests assert model class surface and controller
  dependencies, because "keep models thin" is otherwise a mood.

**Future risks**

1. **A second persistence engine** — a time-series store for metrics, a search index — would create the
   first honest case for an abstraction. Confine it to that subsystem; do not retrofit repositories
   everywhere.
2. **Action sprawl without organization** becoming its own god-directory.
3. **Logic leaking back into models** through convenience accessors that quietly compute business values.
   The arch test is the only thing that catches this.

**Estimated lifespan** — Ten years or more; this is a code-organization decision that is refactorable
incrementally rather than a data decision that is not. Trigger: a second persistence engine, or an
architecture test that has to be weakened rather than a violation fixed.

**Confidence: High.** The alternatives are not hypothetical — they are directly observable at scale in a
twenty-year-old codebase, and the specific pathologies (god methods, invisible cross-module wrapping,
untestable subunits) are documented with citations. The repository rejection is Medium-High rather than
High: it is a genuine trade, and a team with different portability requirements would correctly decide the
other way.

---

## AD-07 — A posted entry is immutable forever; correction is a reversing entry

**Status:** Settled

**Problem** — People make mistakes, and they discover them after posting. The system must offer a
correction path. The choice is whether that path *edits history* or *appends to it* — and it is not
primarily a technical choice. An accounting system's core promise is that what it says happened is what
happened. Every edit-in-place mechanism is a promise that the record can be quietly different tomorrow.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Edit a posted entry directly** | Destroys the audit trail, breaks any hash chain, invalidates every report already issued from that data, and makes "what did the books say on 31 March?" unanswerable. Non-starter for a regulated ledger. |
| **Un-post → edit → re-post** | The intuitive UX, and what most systems offer. It loses because the intermediate state is a lie: an entry that was posted, was reported on, possibly appeared on a filed VAT return, is now "draft" as though it never happened. It also forces every downstream projection to handle un-projection, and it makes the number/hash either reusable (corrupting uniqueness) or orphaned (creating gaps). |
| **Soft-delete + repost** | The same problem with a `deleted_at` column: the original is still gone from every query that respects the flag. |
| **Versioned entries — post v2 superseding v1** | Preserves history and is genuinely defensible. It loses on double-entry semantics: a superseded entry's *amounts* must be unwound in the ledger, so a v2 is a reversal plus a new entry with extra machinery. It adds a concept accountants do not use, to express something they already have a word for. |
| **Reversal-only** (chosen) | Costs UX friction and more rows. |

**Odoo's approach** — Odoo does both. It supports reversal as a first-class posted document: the reverse
copies the move, negates each line, links child to parent via `reversed_entry_id`, and supports **storno**
(same-side negative amounts) for jurisdictions where negation is not permitted — a good design, and the
storno support is the correct proof that reversal must generalize beyond sign-flipping. But it *also*
supports `button_cancel`, which walks posted → draft → cancel, transiently returning a posted document to
draft and deleting its analytic lines on the way. Reset-to-draft is blocked only when an inalterability
hash is present, i.e. only for tenants who opted into sealing. Reversal in a locked period silently shifts
the reversal date to `lock_date + 1 day` without telling anyone.

**QAYD's approach** — There is no un-post path and there never will be. A posted entry keeps its status,
its number, and its hash forever. Correction is a new entry created through `PostingService` (AD-04),
carrying `reversal_of_entry_id`, a `reversal_reason NOT NULL`, `reversed_by_user_id`, and a `reversal_kind
∈ {full, partial, storno}`. The database enforces what code cannot be trusted to: `CHECK
(reversal_of_entry_id <> id)`, a cycle-rejecting trigger, and a partial unique index so an entry can be
**fully** reversed at most once. Negation versus storno sits behind a `ReversalStrategy` seam (AD-13) whose
storno implementation is deliberately unbuilt. The lifecycle trigger of AD-19 rejects `posted → anything
non-terminal` at the database level, so the un-post path is structurally unreachable rather than merely
absent from the UI.

**Why QAYD differs** — The difference is not the reversal design, which we adopt in shape. It is the
refusal of the second path. A correction mechanism that exists "for exceptional cases" is a mechanism that
exists, and every invariant downstream of immutability — the hash chain, the safety of cached balances, the
meaning of an issued report — has to be conditional on it not having been used. One correction path is
worth more than the friction it costs, because *all* of its guarantees are unconditional.

The reason for `reversal_reason NOT NULL` as a column rather than free text in a reference field is
narrower and worth stating: an auditor asking "show me every reversal over KWD 10,000 in Q3 and why" should
get a query, not a text search. Reasons interpolated into a description string are not data.

**Tradeoffs**

- **Worse UX than every competitor at the moment of the mistake.** A user who transposed two digits must
  create a reversal and a corrected entry — three documents where they expected an edit. This costs
  demos and support tickets, and mitigation is UI (a one-click "correct this entry" that generates both
  documents atomically and explains what it did), never a back door.
- More rows: a corrected transaction is three entries, not one. Reporting must present the net position
  clearly or users will believe the books are wrong.
- Genuine data-entry noise (a typo in a description on an otherwise correct entry) has no cheap fix. We
  accept describing-fields being corrected via a linked annotation rather than an edit.

**Future risks**

1. **Sustained commercial pressure** during sales cycles against a competitor that offers editing. The
   counter-position is that this *is* the product for an audited business.
2. **A jurisdiction requiring a specific correction form** we have not modelled — mitigated by the
   `ReversalStrategy` seam.
3. **UI workarounds** that create-and-reverse repeatedly, producing technically-immutable but practically
   unreadable books. Watch reversal rate as a product metric.

**Estimated lifespan** — Permanent. This is not an implementation decision; it is the product's promise
about what its records mean. There is no trigger for reversing it that does not amount to becoming a
different product.

**Confidence: High.** Immutability is what makes everything else in this document cheap — the hash chain
becomes two columns rather than a subsystem, cached aggregates become trustworthy, and "what did the books
say on date D" becomes a query rather than a reconstruction.

---

## AD-08 — Concurrency is optimistic by default, and pessimistic locks are scoped to the smallest resource that requires serialization

**Status:** Settled *(the shipped implementation contains a known defect — see below)*

**Problem** — Two kinds of concurrent conflict exist and they need different answers. A human editing a
draft that someone else edited is a *collaboration* problem: rare, resolvable by a person, and the right
outcome is to tell them. Two transactions racing for the next journal number is a *correctness* problem:
common under load, unresolvable by a person, and the right outcome is for one to wait. Using one mechanism
for both is wrong in one direction or the other.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Pessimistic locking everywhere** | Correct but hostile: a user opening a draft would hold a lock through their coffee break, and a lock held across an HTTP request is a lock held across an unbounded time. Deadlocks become a user-facing feature. |
| **Optimistic everywhere** (retry on conflict) | Fine for drafts, wrong for gapless numbering: retrying allocation is exactly how numbers get skipped, because the failed attempt has usually already consumed one. |
| **`SERIALIZABLE` isolation for the whole posting transaction** | Genuinely attractive — Postgres would detect the anomalies for us. It loses because serialization failures surface as retries of the *entire* posting transaction, which is expensive, and because the failure rate under contention on a hot sequence row is worse than a short explicit lock on that one row. Worth revisiting if contention profiles change. |
| **Advisory locks keyed by company** | Simple, but a company-wide mutex is exactly the serialization point we are trying to remove. |
| **Optimistic for drafts, narrowly-scoped pessimistic for allocation** (chosen) | Costs two mechanisms and the obligation to prove the narrow one is sufficient. |

**Odoo's approach** — Odoo has no optimistic version column; concurrent draft edits are last-write-wins.
For numbering it uses a genuinely clever mechanism: it provokes a PostgreSQL unique-index b-tree lock to
serialize allocation across transactions, which guarantees uniqueness and — by construction — **skips
numbers** in three independent ways (the retry loop only increments, the cache abandons ranges on rollback,
and a partial-index predicate can silently void the guarantee). Notably, Odoo takes **no lock whatsoever**
when evaluating its period lock dates: verified across `company.py` and `account_move.py`, there is no
`FOR UPDATE`, no advisory lock, and no mutex on that path.

**QAYD's approach** — Draft entries carry an optimistic `version` column; a stale write is rejected with a
typed conflict error the caller can present. Posting takes a row lock on the entry being posted (it is
being mutated) and a serialization point on the **journal number sequence row** via `ON CONFLICT DO
UPDATE` — which is the only resource where two transactions genuinely cannot both proceed. Everything else
on the posting path — resolving the period, checking the lock cursor, verifying accounts — is a read of
effectively-immutable data and takes no lock at all. Every lock we hold must be justified by a named
invariant and covered by a concurrency test that proves the invariant survives; a lock without such a test
is treated as a defect.

**Why QAYD differs — and where Odoo is currently better than our shipped code** — On this specific point
the study found a defect in QAYD, not in Odoo. `PostingService` resolves the fiscal period through
`FiscalYearCalendarResolver`, which takes `SELECT … FOR UPDATE` on the **fiscal-year row**. That serializes
*every concurrent posting in a company-year* behind one row, on the hottest write path in the system. Odoo
takes no lock there and is right not to: a date-range read against a calendar that does not change during
posting needs no serialization.

The fix is scoped and scheduled: when S2-07 rebinds the `FiscalCalendarResolver` seam, replace the calendar
`FOR UPDATE` with a plain read, rely on the lock-cursor trigger (AD-10) for period enforcement, and keep
serialization only on the sequence row. It must ship with concurrency tests asserting that N parallel posts
yield contiguous numbers, that a random subset rolling back *after* allocation still leaves the survivors
contiguous, and that `pg_locks` shows no row lock on `fiscal_years` or `fiscal_periods` during posting.

That this defect was found by studying another system's *absence* of a lock is itself the argument for the
principle: **the default must be no lock, with each lock justified**, because an unnecessary lock is
invisible in correctness tests and only shows up as a throughput ceiling under production load.

**Tradeoffs**

- Two mechanisms means engineers must know which applies. The rule is stated as: locks serialize
  *allocation*, versions detect *collision*.
- Optimistic concurrency pushes conflict resolution onto the user, which is correct for drafts and would
  be unacceptable anywhere automated.
- The sequence row remains a genuine serialization point per `(company, fiscal year, entry type)` — the
  price of AD-09, quantified there.
- Proving minimal locking requires a concurrency test suite, which is more work than the code it tests.

**Future risks**

1. **The defect persisting past S2-07** and being discovered as a production throughput ceiling instead of
   a code review finding.
2. **A future feature adding a lock without a test**, re-creating the same class of problem quietly.
3. **Deadlock from multi-row locking** in reconciliation, where two transactions may need two ledger rows
   in opposite order. Mitigated by mandating ordered acquisition (by primary key) wherever more than one
   row is locked.

**Estimated lifespan** — The policy is durable. The sequence-row lock specifically is revisited if a
lock-free gapless allocation technique becomes viable, or if AD-09 is revisited.

**Confidence: High** for the policy and **High** for the defect finding — the latter is verified against
both codebases rather than inferred. Confidence that the fix will not regress gaplessness is Medium until
the concurrency tests exist, which is precisely why they are a shipping condition rather than a follow-up.

---

## AD-09 — Journal numbers are gapless, and we pay for it deliberately

**Status:** Settled

**Problem** — Every posted entry needs a permanent, unique, human-meaningful identifier. The real question
is whether the sequence may contain **gaps**. Gaps are the natural consequence of allocating numbers inside
transactions that can roll back; avoiding them requires that allocation and commit be effectively atomic,
which means serialization.

**Alternatives considered**

| Option | Throughput | Gaps | Auditor answer |
|---|---|---|---|
| **Postgres `SEQUENCE`** | Best — lock-free, cached | Yes, by design (rollback consumes) | "The database works that way" |
| **Allocate on commit via a trigger** | Good | Fewer, not none | Same |
| **Skip-tolerant allocation + a gap report** | Best | Yes, detected after the fact | "Here is our gap register" |
| **Serialized allocation on a sequence row** (chosen) | Bounded by one row per scope | None | "There are none" |

**Odoo's approach** — Odoo allocates via a sequence mixin that deliberately provokes a b-tree lock on a
unique index to guarantee cross-transaction uniqueness. It is superb engineering for uniqueness and
structurally incapable of gaplessness: the retry loop only increments, the in-transaction cache abandons
ranges on rollback, and a partial-index predicate can void the guarantee silently. Odoo's response is
honest — it *detects* gaps, flags them on the move (`made_sequence_gap`), and reports them — and it notes
that for jurisdictions mandating gapless numbering this is a compliance gap papered over with a warning.
Allocation itself happens as an ORM side effect of writing `state='posted'`, not as a step in the posting
method.

**QAYD's approach** — `JournalNumberAllocator` performs an atomic upsert-increment against a
`journal_number_sequences` row scoped by `(company_id, fiscal_year, entry_type)`, inside the posting
transaction, as an **explicit named step** in `PostingService` — not an ORM side effect. A unique index on
`(company_id, journal_number)` is the backstop, asserted to exist by a **fatal** boot-time check rather than
a logged warning. A `VerifyNumberSequenceAction` asserts no gaps and no duplicates per scope, runs in CI and
on a schedule in production, and should never find anything.

**Why QAYD differs** — Because of who asks the question. In a GCC audit, "why does entry 4,417 not exist?"
is not answerable with "the database allocates optimistically." Every gap becomes a conversation, and a
conversation the customer's auditor initiates is a cost the customer attributes to us. Gaplessness is not a
technical preference here; it is the difference between an artifact that requires explanation and one that
does not.

The honest counter-argument is that gaplessness buys *no* integrity: a gapless sequence proves nothing
about whether an entry was deleted, because deletion is prevented by AD-07, not by numbering. So we are
paying throughput for an audit-facing property, not a correctness property. That trade is worth making at
our scale and would not be worth making at ten thousand posts per second — which is exactly why the trigger
below is a throughput number.

**What gaplessness actually costs** — One row of serialization per `(company, fiscal year, entry type)` for
the duration of the posting transaction. Concurrent posts within the same scope queue; posts in different
scopes, different companies, or different entry types do not interact. The cost is therefore proportional to
*transaction duration*, which makes short posting transactions a correctness-adjacent requirement: any slow
work inside the posting transaction (an external call, a large projection, an AI round-trip) multiplies the
contention window for every other poster in that scope. That is a design constraint this decision imposes on
every future feature.

**Tradeoffs**

- A hard throughput ceiling per scope, bounded by transaction duration.
- Bulk imports must be batched *within* one transaction (allocate a contiguous block) or they will
  serialize row-by-row.
- Rollback after allocation must not consume a number, which constrains where allocation may sit in the
  posting sequence and is the single most important thing the concurrency tests must prove.
- A per-scope sequence table is one more thing that must exist, be RLS-scoped, and be correct.

**Future risks**

1. **Throughput.** A high-volume tenant (retail POS, payment processor) posting thousands of entries per
   minute into one scope hits the ceiling. Mitigation is finer scoping (per journal), which multiplies the
   number of chains and therefore the audit surface — a real trade, not a free win.
2. **Long transactions** introduced by a well-meaning feature turning a bounded queue into an unbounded one.
3. **Scope definition drift**: the shipped scope is `(company, fiscal_year, entry_type)`; the research
   assumed per-journal-per-period. These are not the same, and which is correct is an open question (see
   "Decisions we have NOT yet made").

**Estimated lifespan** — Three to five years. Trigger: a tenant whose sustained posting rate within one
scope makes allocation the dominant latency, measured rather than assumed. The likely response is finer
scoping, not abandoning gaplessness.

**Confidence: Medium.** High that gaplessness is the right requirement for the GCC market; Medium overall
because the throughput cost is unmeasured at production load, and because the "skip-tolerant plus a gap
register" alternative is a defensible position that a serious competitor holds in production today.

---

## AD-10 — Fiscal periods are a dimension, locks are the cursor, and status is a view

**Status:** Settled *(design; implementation pending S2-07 — refines the specification, see the register)*

**Problem** — Two different things get conflated under "period control". One is **enforcement**: may this
entry be posted with this date, right now? The other is **the close record**: who closed March, when,
against which trial balance, with whose approval, and what was reopened afterwards? Designs that answer one
well tend to answer the other badly, and designs that use a single mechanism for both produce the worst bug
in this area: two sources of truth that disagree about whether a period is open.

**Alternatives considered**

| Option | Enforcement | Close record | Failure mode |
|---|---|---|---|
| **Period records as the gate** (`status` column checked at post time) | Needs a row lookup, and a lock if status can change concurrently | Excellent — the row is the record | Status column and reality drift; "open" period that should not be |
| **Pure lock dates** (a moving cursor per axis) | Excellent: O(1), no rows, no contention, multi-axis, monotone, trivially reopenable | None — there is nowhere to record who closed what | No auditable close; reopening leaves no trace |
| **Both, independently writable** | — | — | The worst option: two truths, guaranteed to disagree |
| **Hybrid: periods as dimension, locks as cursor, status as a view** (chosen) | Cursor's O(1) enforcement | Period rows anchor a real close workflow | Complexity; two concepts to learn |

**Odoo's approach** — Odoo has **no fiscal-year table and no fiscal-period table**. The fiscal year is
computed from two company fields (`fiscalyear_last_month`, `fiscalyear_last_day`), and period control is
entirely a set of **lock dates** — a moving date cursor across five axes (global, sale, purchase, tax, and
a hard lock). Evaluation takes no lock and touches no rows. Odoo 19 adds `account.lock.exception`: a
time-boxed, per-user or global, audited, revocable relaxation of a *soft* lock, storing a snapshot of the
lock it relaxed and offering a query for every entry touched during the exception window. What Odoo has
nowhere to record is the close itself — closing is three unrelated button presses and a chatter message,
with no trial-balance snapshot, no approver, and no run record.

**QAYD's approach** — Build `fiscal_periods`, and **demote it from gate to dimension**:

- **`fiscal_periods`** carries `period_id` for reporting, for partition pruning, and as the anchor for the
  close workflow. Non-overlap is enforced by `EXCLUDE USING GIST` — a constraint a computed-from-two-company-fields
  model cannot express at all.
- **`fiscal_locks`** is the enforcement cursor: one monotone date per axis, evaluated by a database trigger
  on `journal_entries`. O(1), no rows to maintain, no contention.
- **`fiscal_periods.status` is a VIEW over the cursor**, never an independently writable column. One source
  of truth, two representations. The "period says open but the lock date says closed" bug class becomes
  unrepresentable.
- **`period_close_runs`** records who closed what, when, against which trial balance, with a four-eyes
  CHECK and a partial exclusion constraint making concurrent close runs impossible.
- **`lock_exceptions`** adopts Odoo's shape and hardens it: `reason NOT NULL`, `end_at NOT NULL` with a
  maximum-duration CHECK, `CHECK (axis <> 'hard')` so a hard lock can never be excepted, RLS-scoped, and
  every posting made under an exception tagged so "what moved during the window" is an index scan.
  Evaluation is two-pass — strict cursor first, exceptions consulted only on violation — which is free
  performance and no behaviour change.

**Why QAYD differs** — This is the clearest case in the document where **neither system's design is right
and the third option is better than both.**

Odoo's cursor is genuinely superior for enforcement, and saying so plainly matters: it is O(1), it cannot
contend, it is multi-axis, it is monotone, and reopening is a single date update. A period-record gate is
worse at all five. But an AI-first ledger where an agent proposes and a human disposes makes the *close
record* non-optional: if an agent can propose a close, there must be a durable artifact recording that a
human approved it, against what evidence. Odoo has nowhere to put that artifact, and that gap is
disqualifying for us in a way it is not for Odoo.

The naive fix — keep both and write to both — is the failure this design specifically prevents. Making
status a view rather than a column is the whole trick: it is impossible for two truths to disagree when
there is only one.

**Decision tree — where does a period-related rule belong?**

```
Is the rule about whether a WRITE is allowed right now?
├── YES → it belongs in fiscal_locks (the cursor), enforced by trigger.
│         Needs a temporary, audited exception?
│         ├── soft axis → lock_exceptions (time-boxed, reasoned, tagged)
│         └── hard axis → REFUSED. A hard lock is never excepted.
└── NO
    ├── Is it about grouping, reporting, or partitioning facts?
    │     → fiscal_periods as a dimension (period_id on the fact).
    ├── Is it about recording that a human closed something?
    │     → period_close_runs (approver, trial-balance snapshot, timestamp).
    └── Is it about displaying whether a period is open?
          → the status VIEW. Never a stored column. Never a second write.
```

**Tradeoffs**

- Two concepts where competitors have one; the model must be explained to accountants, who think in periods
  rather than cursors.
- A view-derived status cannot carry per-period metadata that is not derivable from the cursor — anything
  genuinely per-period must live on the period row and must not be enforcement.
- Lock exceptions are a deliberate hole in the wall. Time-boxing, mandatory reasons, and tagging make it an
  audited hole rather than a secret one, but it is still a hole, and its usage rate is a metric to watch.
- The close workflow is substantially more machinery than the button Odoo ships.

**Future risks**

1. **Implementation drift at S2-07** — a developer adding a writable `status` column "temporarily" because
   the view is inconvenient to update. The view must be genuinely non-updatable.
2. **Axis proliferation.** Five axes is already a lot; each new one multiplies the evaluation matrix and
   the test surface.
3. **Period-13 / adjustment-period semantics** are undesigned and will be forced by the first year-end
   close.

**Estimated lifespan** — Five years or more for the model; the implementation will be revised at first
real year-end close. Trigger for revisiting: the S2-07 build discovering that a legitimate rule cannot be
expressed as a cursor, or a statutory requirement for per-period enforcement metadata.

**Confidence: Medium.** The reasoning is strong and the failure modes of both naive designs are documented
in a real system, but nothing here is built yet, and designs that are elegant on paper acquire exceptions
on contact with a real close. The `FiscalCalendarResolver` seam (AD-13) is what makes Medium acceptable:
being wrong costs a rebind, not a rewrite.

---

## AD-11 — Analytic allocations are rows — not fixed columns, and not JSONB

**Status:** Settled — **supersedes the specification's fixed-column design (TD-14). A formal ADR must be
raised before TD-14 is implemented.**

**Problem** — Every business wants to slice the P&L by something other than account: cost centre, project,
department, branch, fund, grant, vessel, store. Two properties make this hard. First, the *set* of
dimensions is customer-specific and grows. Second, a single journal line legitimately splits across members
("60% Project A, 40% Project B") without splitting the line itself, because splitting the line corrupts the
line-to-source-document relationship.

**Alternatives considered**

| Option | New dimension costs | Percentage splits | Referential integrity | `SUM() GROUP BY member` | Verdict |
|---|---|---|---|---|---|
| **Fixed FK columns** (`cost_center_id`, `project_id`, `department_id`) | A migration on the largest table + a deploy, per customer request | Impossible without splitting the line | Yes | Yes | Rejected |
| **JSONB `{member: percentage}`** | Free | Yes | **No** — keys are strings | **No** — cannot aggregate money by a JSONB key | Rejected |
| **Rows in a child table** (chosen) | One `INSERT` | Yes | Yes, composite FK | Yes, plain indexed aggregate | Chosen |
| **EAV on the line** | Free | Awkward | No | Poorly | Rejected — all of JSONB's problems plus joins |

**Odoo's approach** — Odoo's analytic model is its **second** design; the first (a single analytic account
plus tags) proved too rigid and was abandoned. The replacement is N-dimensional *plans* with a JSONB
`analytic_distribution` field on the line holding `{analytic_account_id: percentage}`, allowing percentage
splits across dimensions, governed by applicability rules. The concept — dimensions as data, N-dimensional,
percentage-allocated — is right. The storage has four documented consequences: keys are comma-joined id
strings so there is **no referential integrity** (hence `.exists()` guards scattered through the code, and
deleted analytic accounts leaving danglers no constraint catches); no CHECK can express "sums to 100%", so
the rule is a context-gated Python constraint that production code explicitly disables for
exchange-difference moves; **money cannot be aggregated** — grouping by distribution raises on any aggregate
but `__count`, so the subsystem's primary analytical query is not expressible against its primary storage;
and creating or renaming a plan executes `ALTER TABLE` / `CREATE INDEX` **at runtime**, per inheriting
model. Decisively: Odoo already materializes the JSONB into `account.analytic.line` rows and maintains
two-way sync with `skip_analytic_sync` context flags in six places.

**QAYD's approach** — A `journal_line_dimensions` child table: one row per (journal line, dimension,
member, allocation). Dimensions and members are ordinary tables. The composite foreign key
`(member_id, dimension_id)` makes "this member belongs to the declared dimension" a database guarantee
rather than an application check. The 100%-and-amount invariants are enforced by a `DEFERRABLE INITIALLY
DEFERRED` constraint trigger, so a multi-row allocation can be written in any order within a transaction
and still cannot commit unbalanced. JSONB is legitimate as an **API transport format** and inside the AI's
`dimension_suggestions.proposed_payload` — never as ledger storage.

**Why QAYD differs** — From both, and for different reasons.

Against the specification's fixed columns: a fourth dimension becomes a migration on the largest table in
the system plus a deploy — which for a per-customer request is a schema fork by another name — and
percentage splits are inexpressible without corrupting the line-to-document relationship. That is not a
scaling concern; it is a functional gap on day one.

Against JSONB: the deciding evidence is not that JSONB is untyped — it is that **the system that chose
JSONB pays the row-table cost anyway.** Odoo materializes the same data into rows and keeps two-way sync
with context flags. The JSONB is an authoring convenience layered on top of the real storage. Given that,
keeping the rows and dropping the layer is not a bold choice; it is removing the part that provides no
storage benefit and costs referential integrity, an enforceable invariant, and the ability to aggregate.

**Why this is urgent rather than merely correct** — It is the only decision in this document whose cost
rises with delay. Deciding today costs nothing. Deciding after `journal_lines` has millions of rows costs a
migration on the largest table in the system plus roughly thirteen points of rework in every subsystem
specified against the old shape (budgeting, cost centres, dimensional reporting). The decision is free
exactly once.

**Tradeoffs**

- **Row count.** A line split three ways across two dimensions is six rows. On a large ledger,
  `journal_line_dimensions` becomes one of the biggest tables in the database, and it needs its own
  indexing and partitioning strategy.
- Every read that wants dimensions pays a join.
- Writing an allocation is more code than setting a column, and the deferred trigger's failure mode
  (raised at COMMIT, not at INSERT) is unintuitive to debug.
- Enforcing "every line must be allocated" where required is a separate, harder rule than "this column is
  NOT NULL".

**Future risks**

1. **Row volume becoming pathological** for a customer with many mandatory dimensions. Partitioning by
   `(company_id, period)` mirrors the ledger and is the planned answer.
2. **A `journal_lines.cost_center_id` column being added "for convenience"** by someone who has not read
   this — the exact failure this decision prevents, and the reason it needs a formal ADR rather than a
   knowledge-base entry.
3. **Query ergonomics** pushing developers toward denormalized read models, which is fine as long as they
   are rebuildable projections (AD-20) rather than a second source of truth.

**Estimated lifespan** — Ten years. Trigger: row volume on `journal_line_dimensions` becoming the dominant
storage or query cost after partitioning, which would motivate a hybrid (rows canonical, a denormalized
read model for the top-N dimensions) rather than a reversal.

**Confidence: High.** Unusually high for a decision about something unbuilt, because the evidence is not
argumentation — it is a production system that chose the alternative, hit each named limitation, and
independently arrived at the row table anyway while keeping the JSONB layer on top. We get to skip both the
first design and the second.

---

## AD-12 — Every failure is a typed, coded `DomainException`, and validation failures are aggregated

**Status:** Settled

**Problem** — Errors in this system have three audiences with incompatible needs: a human who needs to know
what to fix, a frontend that needs to highlight a field, and an **AI agent that needs to correct a draft
and retry**. A message string serves the first badly and the other two not at all. Worse, an error path
that fails on the *first* problem forces N round trips to fix N problems — which is a UX annoyance for a
human and a cost multiplier for an agent.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Framework exceptions with messages** (`ValidationException`, `RuntimeException`) | The message becomes the API. Clients string-match on it, translation breaks them, and a wording change is a breaking change nobody notices. |
| **HTTP status codes as the contract** | Far too coarse: `422` covers "unbalanced", "period closed", "account not postable", and "stale version", which need four different client behaviours. |
| **A single `DomainException` with a `code` string, no subclasses** | Cheap, and half-right: it fixes machine-readability but loses catchability, so a caller wanting to handle only concurrency conflicts must inspect a string. |
| **Typed hierarchy + stable code + one envelope** (chosen) | Costs a catalog to maintain and the discipline of never inventing an ad-hoc code. |

**Odoo's approach** — Odoo raises `UserError` and `ValidationError` with translated message strings. Its
balance validation does something genuinely good and worth adopting: it accumulates *every* failure across
a batch into a set and raises them together rather than failing on the first. But the aggregate is an
unstructured newline-joined string — no codes, no field paths, no machine-readable shape — so the good
instinct is not usable by a client. Some constraints are enforced only as Python `@api.constrains`, so a
direct SQL write breaks the invariant with no error at all.

**QAYD's approach** — One `DomainException` base with typed subclasses (`UnbalancedEntryException`,
`PeriodClosedException`, `AccountNotPostableException`, `StaleVersionException`, …), each carrying a
**stable machine-readable code** from a central catalog. Every code renders through one envelope:
`{ code, message, field, actual, expected, correlation_id }`. Validation does not fail fast — it
accumulates into a `ValidationReport` DTO returned as a single response with a `violations[]` array, so a
caller learns everything wrong in one round trip. Codes are contract: renaming one is a breaking API change
subject to the same versioning rules as a field.

**Why QAYD differs** — Because of the AI contract. AD-15 makes the AI a *drafter* that submits proposals
through the same front door a human uses. A drafter needs to know precisely what was wrong to produce a
better draft, and "the entry is not balanced" is not a usable signal — `{code: ENTRY_UNBALANCED, field:
lines, actual: "1000.0000", expected: "1000.5000"}` is. Aggregation matters for the same reason: an agent
fixing one violation per round trip against a ten-violation draft costs ten model calls and ten
transactions, and the tenth attempt may reintroduce the first problem.

The subclasses exist for a narrower reason than the codes: a caller that wants to retry on
`StaleVersionException` and surface everything else must be able to express that in a `catch` clause rather
than a string comparison.

**Tradeoffs**

- The catalog is maintenance, and it goes stale unless a test asserts that every thrown exception's code
  exists in it and that no two exceptions share a code.
- Aggregating validation means validators cannot short-circuit, so an entry with a malformed structure runs
  every check anyway. Checks must therefore be independently safe to run against invalid input — a real
  constraint on how validators are written.
- More classes and more ceremony than `throw new \RuntimeException(...)`, which is what a developer under
  time pressure will reach for.

**Future risks**

1. **Code proliferation** without curation, producing 300 codes nobody can reason about; mitigated by
   coding at the level of *what the caller should do*, not what the code detected.
2. **Codes leaking implementation detail** (a code named after a constraint name), which then cannot be
   changed when the constraint is refactored.
3. **Database-raised errors** (a CHECK violation, a `23505`) bypassing the envelope. Every DB constraint
   that a user can trigger needs a mapping to a domain code, or the contract has holes exactly where the
   strongest enforcement lives.

**Estimated lifespan** — Permanent. Trigger: none foreseeable; the shape may grow (adding a
`remediation_hint` for agents) but the contract is stable.

**Confidence: High.** The cost is small and paid once; the alternative's cost is paid by every client
forever. The aggregation half is directly validated: the system that fails fast on a batch is the same one
whose developers built aggregation for exactly this reason, then stopped short of making it structured.

---

## AD-13 — Every subsystem we expect to replace sits behind a named seam

**Status:** Settled

**Problem** — Some parts of this system will be replaced, and we can predict which. The fiscal calendar
will change when real periods arrive. Reversal will need a second strategy when a storno jurisdiction
appears. The tax engine will be replaced per country. The AI provider will change. The question is where to
put interfaces — because an interface everywhere is astronaut architecture that costs indirection for
nothing, and an interface nowhere means each replacement is a rewrite through the call sites.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Interfaces everywhere** (every service behind a contract) | Doubles the file count, makes navigation worse, and buys optionality at boundaries that never move. Most interfaces would have exactly one implementation forever. |
| **No interfaces; refactor when needed** | Defensible with good tests, and cheaper up front. It loses on the specific case where the replacement must be *swappable per tenant or per jurisdiction* (reversal strategy, tax rules) — that is not a refactor, it is a runtime requirement. |
| **Interfaces at the "layer" boundaries** (repository, service, domain) | Puts seams where the architecture diagram has lines rather than where change actually happens. This is exactly the mistake AD-06 rejects for repositories. |
| **Named seams at identified volatility boundaries** (chosen) | Costs judgement: someone must decide what is volatile, and they can be wrong in both directions. |

**Odoo's approach** — Odoo's extension mechanism is model inheritance and method override, which is a seam
in the sense that behaviour can be replaced, but an unnamed and unenumerable one: a stock module changes
what happens when you post an invoice by wrapping `_post` in the MRO, and nothing at the call site
indicates it. Two consequences follow. The set of things that happen during posting cannot be listed from
any file, and behaviour becomes order-dependent on module installation. Odoo does have genuine designed
seams — the report engine's pluggable evaluation engines are one — but they are the exception rather than
the pattern.

**QAYD's approach** — A seam is an explicit PHP interface with a name, a single documented responsibility,
constructor-injected at the point of use and bound in a service provider. `FiscalCalendarResolver` is the
proven precedent: `PostingService` asks it to resolve a date to a postable period and does not know whether
the answer comes from a fiscal-year row today or a lock cursor after S2-07. That is why AD-10 can be
Medium-confidence and still safe — being wrong costs a rebind. The planned seams are named in advance:
`FiscalCalendarResolver` (built), `ReversalStrategy` (negation vs storno), the tax rules provider, the AI
provider, and the exchange-rate source. Cross-module boundaries are *not* seams — they are events (AD-14).

**Why QAYD differs** — Two properties that inheritance-based extension cannot provide.

**Enumerability.** `php artisan` can list bindings; a code search finds every implementation of a named
interface. The question "what could be running here?" has an answer. With MRO override the same question
requires knowing the installed module set and the resolution order.

**Testability of the seam itself.** A named interface can have a contract test that every implementation
must pass, so replacing an implementation is a bounded verification exercise rather than a full regression.
An override has no contract to test against.

The discipline that keeps this from becoming interfaces-everywhere is a written rule: **a seam requires a
named, plausible second implementation.** Not "this might change" — "here is the specific other thing that
will be plugged in, and why." `ReversalStrategy` qualifies because storno is a legal requirement in about
ten jurisdictions. A `JournalEntryRepository` does not qualify, because there is no second database.

**Tradeoffs**

- Indirection: reading the code requires resolving the binding to know what actually runs.
- Judgement risk in both directions — a seam at a boundary that never moves is pure cost; a missing seam at
  one that does is a rewrite.
- Contract tests are real work, and a seam without one is not a seam, just an interface.
- Seams can be used to defer decisions that should be made. `FiscalCalendarResolver` is legitimate deferral;
  a seam created to avoid choosing is procrastination with a type signature.

**Future risks**

1. **Seam proliferation** as the codebase grows and "might change" becomes the standard.
2. **Leaky seams** — an interface whose method signatures encode the current implementation's assumptions,
   so the second implementation cannot actually satisfy it. This is the most common way seams fail, and it
   only shows up when the second implementation is attempted.
3. **The reverse failure**: a subsystem that turns out to need per-tenant variation and has no seam.

**Estimated lifespan** — Permanent as a practice. Individual seams are removed once the volatility they
anticipated resolves — a seam with one implementation and no plausible second after three years should be
inlined, not preserved out of politeness.

**Confidence: High.** Low cost, high option value, and one precedent already carrying real weight:
`FiscalCalendarResolver` is what turns the largest unbuilt design decision in this document (AD-10) from a
risk into a bounded one.

---

## AD-14 — After-commit domain events are the only cross-module path

**Status:** Settled *(the transactional outbox declared by ADR-0006 is not yet built — see the register)*

**Problem** — QAYD spans Accounting, Sales, Purchasing, Banking, Inventory, Payroll, and Tax, plus an AI
layer, and one business action fans out across several: posting an invoice touches the ledger, inventory,
and tax, and should also invalidate report caches, notify subscribers, and feed the AI. The question is how
a module reacts to another module's fact without the two becoming one module.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Direct synchronous calls between modules** | Simplest to trace, and genuinely fine at two modules. It loses at seven: the posting path accumulates the union of everything anyone wants to happen, and every module's tests need every other module. |
| **Modules reading and writing each other's tables** | The fastest thing to write and the hardest to ever undo. Table shape becomes an implicit public API that cannot be changed. |
| **Events emitted inside the transaction** | Tempting — the handler sees the same transaction. It loses badly: a handler failure rolls back the business fact, so a notification bug becomes a posting outage, and any handler side effect outside the database (an email, an HTTP call) fires for a transaction that may still roll back. |
| **A message broker (Kafka/RabbitMQ) now** | Strong semantics, real operational weight, and a second system to run before there is a second consumer. |
| **After-commit events + transactional outbox** (chosen) | Costs traceability and mandatory idempotency. |

**Odoo's approach** — Odoo has **no domain-event bus**. Cross-module integration is method override and
model inheritance, which the research identifies as the root cause of its coupling after twenty years. It
does have one narrow, well-built instance of the right pattern: its bus writes the notification row inside
the transaction and sends the announcement only after commit — a transactional outbox for exactly one
consumer, the browser. The pattern is correct; it was simply never generalized.

**QAYD's approach** — A module publishes a typed domain event (`accounting.journal.posted`) with an
immutable, versioned payload, dispatched **after commit**. Consumers subscribe; the publisher does not know
they exist. No module reads or writes another module's tables, ever. The declared mechanism (ADR-0006) is a
transactional outbox: the event row is written in the same transaction as the fact, so it is emitted **if
and only if** the fact committed, and a relay delivers it at-least-once with consumers deduplicating on id.
Events carry a sequence so a consumer can detect its own gaps. Realtime push (Reverb) is a notification to
refresh authoritative state, never a second write path.

**Why QAYD differs** — The argument is not that events are fashionable; it is that **the alternative's cost
is invisible for two years and then unpayable.** Inheritance-based coupling has no cost at the moment it is
written, which is why it accumulates, and its cost lands as "we cannot test the accounting module without
installing inventory" long after the decision.

The after-commit timing is the part that is easy to get wrong and worth stating precisely: an event is an
announcement about the past. If it fires inside the transaction it is an announcement about a *possible*
past, and every consumer must then be prepared for the fact to un-happen — which no consumer ever is.

The honest gap: ADR-0006 declares the outbox, and the shipped code emits after-commit events without one.
That means an event can be lost if the process dies between commit and dispatch. This is a **known** hole
with a known fix, and it must be closed before the second consumer exists — at one consumer the failure is
recoverable by replay, at several it is a silent divergence between modules.

**Tradeoffs**

- Debugging is harder: a straight stack trace becomes a chain through an outbox. Correlation ids on every
  event are mandatory, not optional.
- At-least-once delivery makes every handler's idempotency a correctness requirement, and non-idempotent
  handlers fail rarely and confusingly.
- Event payloads are a contract; changing one is a versioned change like an API field.
- Eventual consistency between modules becomes visible in the UI (the ledger updated, the dashboard has
  not), and product must be designed for it rather than surprised by it.

**Future risks**

1. **The outbox staying unbuilt** past the point where a second consumer exists — the specific risk this
   entry exists to flag.
2. **Event payloads growing into DTOs of the whole aggregate**, recreating coupling through the payload:
   a consumer that reads twelve fields is coupled to twelve fields.
3. **Ordering assumptions.** At-least-once says nothing about order; a consumer that needs per-aggregate
   ordering must get a version on the event and reject out-of-order, not assume.

**Estimated lifespan** — Permanent as a rule. The transport evolves (in-process → outbox relay → broker) as
volume demands, and each step is invisible to publishers and consumers, which is the point.

**Confidence: High** for the rule — the counterfactual is a twenty-year-old codebase whose coupling is
traceable to its absence. **Medium** for the current implementation, because the declared outbox does not
exist yet and the gap between "decided" and "built" is exactly where this kind of guarantee is lost.

---

## AD-15 — Laravel owns the domain, PostgreSQL owns integrity, FastAPI owns inference — and the AI engine holds no database credential

**Status:** Settled

**Problem** — QAYD is an AI Financial Operating System, so the AI is not a feature bolted onto an
accounting system — it is the product thesis. That makes the defining risk explicit: **an AI with write
access to a ledger can corrupt the books faster than any human can notice.** Meanwhile the ML ecosystem is
Python and the domain layer is PHP, so a language boundary exists whether or not we want one. The
architecture must place that boundary where it also does security work.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **AI logic inside Laravel** | One less service, one less hop. Loses because the ML/agent ecosystem (model SDKs, OCR, embeddings, evaluation tooling) is Python-first, and long-running inference on the PHP request path is the wrong shape for both. |
| **A TypeScript orchestrator package** | Cannot house the Python agent stack, and blurs the service boundary that keeps the engine credential-less. |
| **Ad-hoc model calls per feature** | Fastest initially; makes the safety contract unenforceable, fragments memory and tools, and makes auditing AI behaviour impossible. |
| **AI writes directly for high-confidence, low-risk actions** | The most seductive alternative, because it is where the throughput is. Rejected: it breaks the single-front-door invariant and creates an unaudited write path. Once one class of AI write bypasses the gate, the gate's guarantee is conditional on a confidence threshold that will be tuned by someone optimizing for throughput. |
| **Three services, credential-less AI** (chosen) | Costs a network hop, a contract to keep in sync, and a hard ceiling on autonomy. |

**Odoo's approach** — Not applicable in kind: Odoo has no comparable AI layer, so there is no design to
compare against and none is invented here. What the study does contribute is the *governance* lesson from
its permission model — `sudo()` demonstrates precisely what happens when a bypass exists for good reasons
(552 call sites, no log, no reason, no scope, propagating transitively). An AI write path justified by
confidence would be the same pattern with a probability attached.

**QAYD's approach** — Three tiers with non-negotiable ownership:

- **PostgreSQL owns integrity.** Constraints, triggers, RLS, and grants are the enforcement layer. Nothing
  above it is trusted to be careful (P1, AD-01).
- **Laravel owns the domain.** Every business operation — human, API client, or AI — enters through the
  same versioned `/api/v1` surface with the same validation, the same RBAC, and the same Actions.
- **FastAPI owns inference.** Extraction, drafting, retrieval over per-tenant memory, agent orchestration.
  It is invoked by Laravel over HTTP and via the queue (mTLS plus an internal token) and **holds no tenant
  database credential**. Its outputs return to Laravel and are written only through the governed proposal
  endpoint.

The safety contract: never auto-commit — every AI action is a *proposal*; confidence and reasoning are
attached to every AI-originated number and rendered wherever it appears; and a fixed list of sensitive
operations (money, tax, payroll, posted data) stays irreducibly human-approved and does not shrink as
autonomy grows. What the system auto-commits is a human's **decision about** a proposal, never the proposal
itself.

**Why the AI never writes the database — argued independently** — Three reasons, none of which is "AI is
unreliable."

1. **Blast radius asymmetry.** A human entering a wrong number produces one wrong entry. An agent with a
   systematic misunderstanding produces ten thousand consistent, plausible, wrong entries — and consistency
   is exactly what makes them survive review. Rate-limiting does not fix this; a gate does.
2. **Attribution.** Every financial fact must have a responsible party. "The model decided" is not an
   answer an auditor accepts, and it is not an answer that supports a liability position. A human decision
   about a proposal has an owner; an autonomous write does not.
3. **The credential is the enforcement.** A rule that the AI must not write is a policy. A service that has
   no database credential and a database role that has no `INSERT` on ledger tables is a mechanism. This is
   the same distinction as AD-01 and AD-21, applied to the newest and least predictable client.

**Tradeoffs**

- **A hard ceiling on autonomy**, accepted deliberately as the price of trust. Competitors promising
  full automation will demo better.
- A network hop, a contract, and fixtures to keep in sync between PHP and Python.
- Two languages means two toolchains, two dependency ecosystems, two CI paths, and engineers who must
  read both.
- The Orchestrator is a critical dependency and a security surface — its tool registry is the thing an
  attacker would target.

**Future risks**

1. **Autonomy pressure.** The most likely erosion is not a decision to let AI write; it is a series of
   reclassifications of what counts as "sensitive". The fixed list must be genuinely fixed, and shrinking
   it must require the same ceremony as a superseding ADR.
2. **Approval fatigue** producing rubber-stamping, which is autonomy without the audit trail — arguably
   worse than autonomy, because it looks compliant. Batch approval with sampling is the mitigation to
   design deliberately.
3. **Proposal-queue throughput** becoming the product's bottleneck at scale.

**Estimated lifespan** — The credential rule is permanent. The sensitive-operations list will be revisited
annually as accuracy data accumulates; the trigger for narrowing any part of it is a measured error rate on
a specific operation class over a meaningful volume — never a general sense that the models got better.

**Confidence: High.** The credential-less design costs one hop and removes an entire category of
catastrophic failure. The one thing to watch honestly is that we have no production data on approval
throughput, so the *product* consequences of the ceiling are less certain than the safety argument for it.

---

## AD-16 — Financial statements are declarative data — but we build two concrete statements before we build the engine

**Status:** Settled

**Problem** — A Trial Balance, P&L, Balance Sheet, and Cash Flow are structurally the same object: a tree
of labelled lines, each computing a number from the ledger over a date scope, with sign conventions and
subtotals. Hard-coding each one means every new statement, every localization variant, and every customer
tweak is a code change and a release. Making them data means an engine — and an engine designed before its
requirements are known is the most expensive kind of abstraction.

There are therefore **two** decisions here: what the representation should be, and in what order to build
it.

**Alternatives considered**

| Option | Why it lost / won |
|---|---|
| **Hard-coded statements** (one Action per statement) | Fast for the first two, then every localization and every customer variant is a release. Loses as a destination — but wins as a *starting point*, which is the sequencing decision below. |
| **String formulas parsed at evaluation** | The obvious way to make reports data, and the one to avoid: a grammar nobody designed, parsed by regex, with sign conventions encoded in punctuation, and validation that is syntax-only. |
| **Typed operand rows** (chosen) | Costs more schema and a less compact authoring format; buys FK-enforced references, structural cycle detection, and no parser. |
| **Engine first, statements second** | Rejected — see below. |
| **Two statements first, extract the engine second** (chosen) | Costs one refactor of known scope. |

**Odoo's approach** — Odoo's declarative report model is the single most valuable structural idea found in
its reporting, and it is genuinely better than hard-coding: `account.report` → `account.report.line` →
`account.report.expression`, each expression an `(label, engine, formula, subformula, date_scope)` tuple,
with pluggable engines (`domain`, `account_codes`, `aggregation`, `tax_tags`, `external`, `custom`). Country
packs author statements as data records with zero code per statement. The execution is where the cost sits:
formulas are strings validated by regex; sign conventions are encoded in punctuation (`-sum(...)`, `-Tag`,
`D`/`C`); copying a report rewrites formulas with a regex substitution padded with spaces so lookaround
works; there is **no cycle detection** — `A.balance = B.balance` and `B.balance = A.balance` is accepted at
write time and only misbehaves at evaluation; and the evaluator itself is Enterprise-only, so Community
ships definitions without numbers.

**QAYD's approach** — `report_definitions` / `report_lines` / `report_expressions` /
`report_expression_operands` — **typed rows, not formula strings.** An operand is a row with a kind and a
foreign key, so a reference to another line is FK-enforced rather than a name in a string. Cycle detection
is a recursive CTE at publish time, which makes a cyclic definition unsavable rather than
misbehaving-at-runtime. Account selectors are CHECK-constrained JSONB compiled through an allowlist into
bound parameters — never `eval`, never string interpolation. Sign is a typed column, not punctuation.

**The sequencing decision, stated as strongly as the representation decision:** build **Trial Balance
first, then P&L, then extract the engine from what those two actually needed.** Building the engine first
is precisely how a regex grammar happens — an abstraction designed against imagined requirements acquires
generality nobody asked for and lacks the one thing the third statement turns out to need.

**Why QAYD differs** — On representation, the difference is where validation happens. A formula string is
validated when it runs, against data; a typed operand row is validated when it is written, against the
schema. For a financial statement that difference is the difference between "the balance sheet did not
balance in March" and "this definition cannot be saved." Cycle detection makes the point sharply: it is
essentially free as a recursive CTE over rows and essentially impossible over strings, which is why the
string-based system does not have it.

On sequencing, the difference is epistemic. We do not yet know what the engine must do, and the honest way
to find out is to write the two statements we are certain about and observe what they share. The cost of
being wrong in this direction is one refactor of known scope; the cost of being wrong in the other
direction is a grammar we maintain forever.

**Tradeoffs**

- Two concrete statements will contain duplicated logic that lives with us until the extraction. Accepted
  deliberately; duplication is cheaper than the wrong abstraction.
- Typed operand rows are a worse authoring experience than a one-line formula. Authoring is where the
  string format genuinely wins, and the answer is an authoring API that compiles to rows — the compaction
  belongs at the boundary, not in storage.
- More tables and joins to evaluate one line.
- A wrong extraction point (too early, too generic) is still possible; two statements is a heuristic, not a
  guarantee.

**Future risks**

1. **The extraction never happening**, leaving four hard-coded statements and a permanent per-statement
   release cost. Guard: the extraction is a scheduled story, not an aspiration.
2. **Engine scope creep** into a general query language, at which point it needs its own security review —
   a report definition that can express arbitrary SQL is an RLS-bypass surface.
3. **Statement snapshots.** Once a statement is issued to an auditor it must be reproducible. If a
   definition or an account classification changes afterwards, the reissued statement differs. Immutable
   published snapshots are undesigned and will be forced by the first audited close.

**Estimated lifespan** — Five years or more for the model. The *sequencing* decision expires the moment the
second statement ships, and the extraction is then judged on what those two needed.

**Confidence: Medium.** High that reports-as-data is right — that part is validated by a system where
country packs author statements with zero code. Medium overall because the extraction point is a judgement
call, and because the reporting requirements of GCC statutory formats are not yet fully known, which is
exactly the argument for not designing the engine yet.

---

## AD-17 — The audit trail is hash-chained and externally anchored

**Status:** Provisional *(columns exist and are dormant — TD-06; the anchoring design is unchosen)*

**Problem** — An append-only table proves nothing on its own. Append-only is enforced by a trigger, a
trigger is enforced by the database, and anyone with database access can drop the trigger, rewrite rows,
and recreate it. So the question is not "can the application modify history" — AD-03 and AD-07 answer that
— it is **"can we detect modification by someone who has more privilege than the application?"** For an
accounting system, that is the difference between a record and evidence.

**Alternatives considered**

| Option | Detects app-level tampering | Detects DBA-level tampering | Detects full-history rewrite | Cost |
|---|---|---|---|---|
| **Plain audit log** (append-only table) | Yes | No | No | Free (already built) |
| **Hash chain, unkeyed** | Yes | Yes, for partial edits | **No** — recompute the chain end to end | 2 columns + a trigger |
| **Hash chain + periodic externally-signed anchors** (chosen) | Yes | Yes | Yes, back to the last anchor | Above + a KMS dependency |
| **Write-once storage / external ledger service** | Yes | Yes | Yes | Operational and vendor cost far beyond current scope |

**Odoo's approach** — Odoo implements a SHA-256 tamper-evidence chain over posted entries, driven by
European inalterability law, and two of its design choices are genuinely good: the chain lives in core
behind one boolean with **zero country conditionals** (generalize, do not localize), and the digest is
versioned in-band as `$4$<hex>` so the algorithm can evolve without invalidating history. Four properties
limit it. It is enforced in Python — there is **not one `CREATE TRIGGER` in the entire repository**, and its
own test suite clears a seal with an ordinary write. There are three separate context-keyed bypasses. The
chain is unkeyed with an empty-string genesis, so a full-chain rewrite is undetectable. And the hashed field
set is an allowlist that omits `amount_currency`, `currency_id`, `date_maturity`, and analytic distribution
— so those are silently mutable on a "secured" entry.

**QAYD's approach** — Activate the dormant `hash` / `prev_hash` columns, chained over `ledger_entries`, and
fix all four:

1. **Enforce in a `BEFORE INSERT` trigger**, so the chain is a property of the table rather than of the
   code that writes it.
2. **No bypass tokens of any kind** (AD-21).
3. **Cover every column**, not an allowlist — cheap precisely because the projection is append-only, so the
   chain can never go stale and there is no "which fields are we sealing?" question.
4. **Persist a canonical payload** rather than re-deriving the hash from live business fields, so
   verification is a pure function of the audit rows and a future field-set change is not a breaking
   migration.

Plus **periodic externally-signed anchors** — a KMS-signed digest of the chain head at an interval — which
is the only thing that makes a full-chain rewrite detectable.

Audit output is deliberately three separated tiers: `entry_comments` (collaboration, mutable, never
hashed); `audit_logs` (field-level diffs as self-describing JSONB so history stays readable after a schema
change); `journal_entry_history` (full snapshots per lifecycle transition). A PL/pgSQL shadow-capture
trigger reconciles trigger-sourced rows against Action-sourced rows: **a trigger row with no Action peer
means something wrote outside the Action layer** — a class of event that is otherwise undetectable.

**Why QAYD differs** — The reasoning is about *threat model*, not diligence. A chain enforced in
application code defends against the application; a chain enforced by a trigger defends against anything
that speaks SQL; an anchored chain defends against whoever can drop the trigger. Each layer costs
approximately nothing more than the one below it, and the top layer is the only one that answers the
question a forensic auditor actually asks.

The all-columns decision is a direct dividend of AD-03: hashing an allowlist is a compromise forced by a
mutable ledger, where fields that legitimately change after posting cannot be sealed. Nothing in
`ledger_entries` changes after posting, so there is no reason to choose which parts of the truth to protect.

**Tradeoffs**

- **Hash computation is in the posting transaction**, which lengthens it — and AD-09 makes transaction
  length a contention multiplier. The interaction is real and must be measured.
- Chaining is inherently serial per chain, so chain scope is a throughput decision, not just an audit one.
- Anchoring introduces an **external dependency** (a KMS) in a system that otherwise has none, with its own
  availability, key rotation, and cost questions — all unchosen.
- Verification over a large chain is expensive and must be incremental (verify since the last anchor), or
  it becomes a thing nobody runs.
- A broken chain is a page-someone event with no automated remedy, and the runbook for "the chain is
  broken" does not exist.

**Future risks**

1. **Anchoring never being built**, leaving an unkeyed chain that a privileged actor can rewrite end to end
   — which is the *appearance* of tamper-evidence without the property. This is the risk that keeps the
   status Provisional.
2. **The GCC inalterability requirement being different from what we assume.** We are building against a
   European-shaped requirement because that is the reference implementation available; the actual local
   rule may differ in scope or in retention.
3. **Chain contention** at high posting volume forcing per-period or per-journal chains, which multiplies
   verification surface.

**Estimated lifespan** — The chain design is durable for a decade. Trigger for revisiting: publication of a
specific GCC inalterability rule, or SHA-256 weakening (already mitigated by the versioned in-band digest).

**Confidence: Low for the anchoring design, High for the chain itself.** The chain is well-understood,
cheap, and directly enabled by decisions already made. The anchoring is Low because no KMS is chosen, no
cadence is chosen, no verification runbook exists, and — most honestly — **no customer has yet asked for
it.** We are building it because a ledger without it is not evidence, not because a requirement forced the
shape. That is a legitimate reason to build and an illegitimate reason to claim confidence.

---

## AD-18 — Nothing is silently corrected

**Status:** Settled

**Problem** — When input is nearly-but-not-quite valid, a system can either fix it and proceed or refuse
and explain. Fixing feels helpful and produces better conversion metrics. In a ledger it produces financial
facts nobody authored: an entry dated differently from what the user chose, a balance made to balance by a
line the user did not write, an amount converted at a rate that does not exist. Each of these is defensible
individually and catastrophic as a policy, because the resulting record is *wrong in a way that looks
right.*

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Silent coercion** (fix and proceed) | Produces facts with no author. The user believes they posted X; the books say Y; nothing records the difference. |
| **Coerce, but log it** | Better, and still wrong: a log is not consent, nobody reads it, and the entry is still not what the user authored. |
| **Coerce, but warn in the UI** | Only works for interactive humans — it protects nobody using the API, the import path, or an AI drafter, which is where volume lives. |
| **Refuse, and return a typed resolution the caller must explicitly accept** (chosen) | Costs a round trip and more client code on every path that can hit it. |

**Odoo's approach** — Three specific behaviours, each individually reasonable. Posting into a locked period
**silently moves the accounting date forward** into the first open period — and the coercion target is
derived from the *numbering* granularity, so numbering policy determines the accounting date; a preview
helper exists but the posting path does not use it. An unbalanced entry can be silently completed with an
**auto-balancing suspense line**. A missing exchange rate falls back to the earliest known rate and then to
**`1.0`** — so a currency with no rates, or a date before the first rate, converts **at par, silently**: no
error, no log, no flag. The research identifies that last one as the highest-severity defect found anywhere
in its currency handling.

**QAYD's approach** — Each of the three is refused explicitly:

- **Dates:** a locked-period post raises and returns a `PostingDateResolution` naming the earliest legal
  date. The caller must resubmit with it. The system never picks a date for a financial event.
- **Balance:** zero tolerance in both the entry currency and the base currency, with an unconditional
  `chk_je_balanced` CHECK. An unbalanced entry is a data-quality signal, not something to absorb. Where a
  plug is legitimately needed (opening balances), it is an explicit, acknowledged suspense line with the
  residual surfaced in the DTO before posting.
- **Rates:** a missing rate raises `RATE_MISSING_FOR_DATE`. Never extrapolate, never default, never
  convert at par. Rate rows get a `valid_to` with a GiST exclusion constraint so overlapping windows are
  structurally impossible, and become immutable once referenced by a posted entry.

**Why QAYD differs** — Because of a property of financial errors that does not hold elsewhere: **they are
discovered late, by someone else, and they are expensive in proportion to the delay.** A silent correction
converts an error that would have been caught at entry into one caught at audit. The user cannot detect it
because the system reported success, and the reviewer cannot detect it because the record looks internally
consistent.

The AI dimension makes this sharper rather than merely adding to it. A human notices a date that shifted by
a month; an agent posting a thousand entries does not, and coercion applied at machine speed is systematic
corruption. AD-12's structured violations exist precisely so that refusing is *cheap for the caller* — the
resolution DTO tells them exactly what to accept — which is what makes strictness affordable rather than
merely principled.

**Tradeoffs**

- **More round trips, and a worse first impression.** Competitors that "just handle it" demo better.
- Every path that can hit a resolution needs client code to present and accept it — real work in the web
  app, the API, the importer, and the AI adapter.
- Bulk imports fail more, and fail more often on the last row. Import UX must surface all resolutions at
  once (AD-12) or the strictness becomes unusable at volume.
- There is a genuine boundary judgement: trimming whitespace on a description is a correction nobody wants
  a dialog for. The rule applies to **anything that changes a financial fact** — amount, date, account,
  currency, rate, period — and deliberately not to cosmetics.

**Future risks**

1. **Erosion by convenience.** The first `if (config('accounting.auto_adjust_dates'))` is the failure, and
   it will be proposed by someone with a real customer complaint.
2. **Rate-lookup strictness** becoming an operational burden if the rate feed is unreliable — the correct
   answer is feed monitoring and alerting, not a fallback.
3. **User workarounds** that are worse than the coercion would have been: a user blocked on a date may
   backdate something else instead. Watch for it; solve with UX, not with tolerance.

**Estimated lifespan** — Permanent. No trigger for reversal exists that does not amount to accepting facts
with no author.

**Confidence: High.** All three refusals are validated against a production system where the coercions
exist, and where the currency one in particular is a silent, high-severity correctness defect rather than a
theoretical concern.

---

## AD-19 — Lifecycle transitions are data, mirrored by a database trigger

**Status:** Settled — **decided, not yet built; the window is closing** (3 points of work)

**Problem** — `journal_entry_status` has eight values. The rules about which transitions are legal are
currently expressed as two constants (`POSTABLE_STATUSES`, `EDITABLE_STATUSES`) plus whatever each Action
happens to check. Every Action written from here encodes transition rules implicitly, and the cost of
consolidating grows linearly with the number of Actions written against the implicit rules.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **Guards in each Action** (the current state) | There is no single place that answers "what transitions are legal?", so every new path re-derives the rules and occasionally gets them wrong — and the wrongness is only visible in the one path that has it. |
| **A state-machine library** | Solves the same problem with a dependency, a DSL, and an opinion about persistence. The map is ~30 lines; the library is not worth its integration surface. |
| **A database trigger only** | Enforcement without a readable declaration: correct, but the rules are then only discoverable by reading PL/pgSQL, and the application cannot answer "what can I do next?" without a round trip. |
| **One transition map + a mirroring trigger** (chosen) | Costs keeping two representations in sync, mitigated by generating the trigger's data from the map and testing that they agree. |

**Odoo's approach** — `account.move` has eight statuses, and its transition rules are scattered across at
least six separate call sites: `write()` guards, `_post` validations, `button_draft`, `button_cancel`, and
more. There is no single place that answers what transitions are legal. One consequence is directly
relevant: `button_cancel` walks posted → draft → cancel, transiently returning a posted document to draft
and deleting its analytic lines on the way — a path that exists because no single authority ever said it
should not.

**QAYD's approach** — One `JournalEntryLifecycle::TRANSITIONS` map as the single source of truth for what
may follow what, mirrored by a PostgreSQL trigger that rejects any illegal status transition. The trigger
includes a rule that rejects `posted → anything-not-terminal`, which makes the un-post path of AD-07
structurally unreachable rather than merely absent. The application uses the map to answer "what actions
are available?"; the database uses it to guarantee the answer.

**Why QAYD differs** — Because we are at exactly the point the other system was before this metastasized,
and the difference between three points and a multi-week untangling is entirely a matter of when it is
done. That is unusual: most decisions in this document are about being right, and this one is about being
early.

The two-representation design is deliberate rather than redundant. The map is *declarative and readable* —
a reviewer can see the entire lifecycle in one screen — and the trigger is *enforcing*: it applies to a
migration, a support script, and a bulk update, none of which go through the Action. Keeping them in sync
is a solved problem (generate the trigger's transition table from the map; test that the map and the
catalog agree), and it is far cheaper than either representation alone.

**Tradeoffs**

- Two representations to keep aligned; a drift test is mandatory or the trigger silently becomes the real
  rule while the map becomes documentation.
- A legitimate new transition requires a migration, which is friction — intentionally.
- The trigger's error message is a database error and must be mapped to a domain code (AD-12) or the
  envelope has a hole exactly where the strongest enforcement is.

**Future risks**

1. **Delay.** The cost is 3 points today and grows with every Action written against the implicit rules.
   This is the cheapest item in the backlog and the one with the shortest window.
2. **Status proliferation.** Eight is already many; each addition multiplies the transition matrix.
3. **A transition that needs a condition, not just a source and target** ("posted → reversed only if not
   fully reversed"). The map handles shape, not predicates; conditional rules must stay in the Action, and
   the split must stay clear or the map becomes a half-truth.

**Estimated lifespan** — Permanent as a pattern; the map's contents change with the domain. Trigger for
revisiting: a genuine need for conditional transitions numerous enough to make the map misleading.

**Confidence: High.** Small, reversible, and validated by a directly observable end state: eight statuses
with rules in six places, and a posted-to-draft path that exists because nothing ever forbade it.

---

## AD-20 — A cached aggregate is permitted only over an append-only source, and every projection ships a rebuilder

**Status:** Settled

**Problem** — A trial balance sums every ledger row for an account. Doing that from scratch on the largest
table in the system, for every report, does not scale. Caching aggregates is the obvious fix and the
classic source of silent wrongness: a cached balance that has drifted from its source is worse than no
cache, because it is confidently incorrect and nothing surfaces the divergence.

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **No aggregates; always scan** | Correct by construction and the current behaviour. It loses at scale: the trial balance becomes a full scan of the biggest table, and it is on the critical path of every close. |
| **Cache in Redis with TTL invalidation** | Fast, and wrong in a specific way: financial aggregates must be exact, and a TTL is a promise that they are exact *eventually*, which is not a property a trial balance can have. |
| **Aggregates over the mutable source** | Requires handling increments, decrements, and re-statements, so the maintenance trigger must reason about the previous value. This is where cache-invalidation bugs live. |
| **Incremental rollups over an append-only source + a mandatory rebuilder** (chosen) | Costs a rollup table, a trigger, and a rebuild job. |

**Odoo's approach** — Odoo stores **no aggregate balances anywhere**, at any granularity. Every trial
balance is a scan, and `_compute_current_balance` has no date bound at all, so opening an account form
aggregates the account's entire history. It is a defensible choice given its constraint — a cached aggregate
over a mutable ledger is genuinely dangerous — but the constraint was self-imposed by putting mutable state
on the ledger row. Separately, Odoo 19 **removed** its `stock.valuation.layer` in favour of full-history
replay, batched at 50,000 rows to avoid a `MemoryError`, which is the clearest available demonstration that
full-history replay as a primary read path does not scale.

**QAYD's approach** — `account_period_balances (company_id, account_id, period_id, opening, debit, credit,
closing)` with `CHECK (closing = opening + debit − credit)`, maintained by an **AFTER INSERT trigger on
`ledger_entries`**. Because the source is append-only, the trigger is **monotonic**: it only ever
increments, and there is no previous value to reason about — which removes the entire class of
cache-invalidation bug. `RebuildPeriodBalancesAction` recomputes from source, runs in CI, and runs on a
schedule in production as a drift detector.

This generalizes to a rule: **every derived store in the system ships a rebuilder, and the rebuilder is
tested against the incremental path.** That applies to period balances, to reconciliation matching groups
(rebuildable read model), and to `ledger_entries` itself.

**Why QAYD differs** — Not because caching is clever, but because AD-03 changed what caching *means* here.
Over a mutable source, an aggregate is a claim that must be continuously defended. Over an append-only
source it is a fold of an immutable sequence — the arithmetic cannot become wrong unless the trigger is
wrong, and a rebuilder detects exactly that. This is the clearest dividend of the append-only decision, and
it is the largest single scalability win available to us: the trial balance goes from a full scan of the
largest table to a roughly two-thousand-row index scan.

The rebuilder requirement is the part that must not be treated as optional. A projection you cannot rebuild
is a second source of truth, and the moment it diverges you have no way to determine which one is right.
A projection you *can* rebuild is a cache, and a divergence is a bug with a known remedy.

**Tradeoffs**

- Trigger work on the posting path: every posted line updates a rollup row, which is another row lock and
  another few milliseconds inside a transaction that AD-09 makes contention-sensitive.
- Rollup rows are a hot spot: many concurrent posts to the same account and period contend on one row. If
  this becomes the bottleneck, the answer is delta rows summed on read rather than removing the rollup.
- Rebuild is expensive on a large ledger and must be schedulable, resumable, and safe to run against live
  traffic.
- Period boundaries must be settled for a rollup keyed by `period_id` to exist, which couples this to
  AD-10.

**Future risks**

1. **Rollup row contention** at high posting volume within one account and period.
2. **Drift going undetected** because the scheduled rebuild check is disabled for cost. The check *is* the
   guarantee; disabling it removes the property, not just the alarm.
3. **Rollup proliferation** — one per reporting dimension — until maintenance cost exceeds the savings.
   Each rollup needs an owner and a justification.

**Estimated lifespan** — Permanent as a rule. `account_period_balances` specifically is revisited if
partitioning makes direct aggregation fast enough to make it unnecessary, which would be a welcome
simplification.

**Confidence: High.** The monotonic-trigger argument is structural rather than empirical, and the negative
case is directly observed: a production system that removed its materialized valuation layer in favour of
replay and then had to batch the replay to avoid running out of memory.

---

## AD-21 — No invariant has an off switch, and there is no ambient privilege bypass

**Status:** Settled

**Problem** — Every invariant eventually meets a legitimate need to violate it: a data migration, a support
fix, a bulk import, an exchange-difference entry with unusual properties. The pressure to add a bypass is
therefore continuous and always justified by a real case. The question is whether the escape is *ambient*
(available everywhere, invisible at the call site) or *explicit* (a named, audited, narrow operation).

**Alternatives considered**

| Option | Why it lost |
|---|---|
| **An ambient bypass flag** (`sudo()`, `force: true`, a context key) | Converts every boundary into a convention. Once available, it is used wherever it is convenient, and the call site gives no indication that anything was skipped. |
| **A privileged role the application can assume** | Better — at least it is a role — but if the application can assume it at will, it is an ambient bypass with more steps. |
| **No escape at all** | Honest and unworkable: genuine platform operations exist, and if there is no sanctioned path someone will use `psql` and there will be no record at all. |
| **Explicit, audited, narrowly-scoped operations** (chosen) | Costs friction on genuinely legitimate work. |

**Odoo's approach** — `sudo()` is seven characters mid-expression that disable model ACLs, record rules,
field ACLs, and multi-company validation — and the flag propagates **transitively** through every derived
recordset. There are 552 call sites in the studied checkout, 181 in `addons/account` alone. There is no
log, no reason, and no scope. The same pattern recurs as context keys: `bypass_lock_check`,
`skip_readonly_check`, `force_delete`, `check_move_validity`, `skip_analytic_sync`. The most telling detail
is Odoo's own tacit admission — its security models set `_allow_sudo_commands = False`, acknowledging that
a sudo'd nested write is a privilege-escalation primitive. Relatedly, its "distribution must sum to 100%"
rule runs **only** when a specific context key is present, and its own production code disables it for
exchange-difference moves.

**QAYD's approach** — Three rules, enforced by mechanism rather than agreement:

1. **No bypass parameters.** No `force`, no `skip_validation`, no context key that turns a check off. A
   grep-based architecture test is the enforcement, because this is a pattern that arrives one parameter
   at a time.
2. **`is_platform_admin` is deliberately not wired to any bypass.** The GUC exists; nothing reads it as an
   RLS escape. Keeping it unwired is load-bearing, and this document is the record that it is deliberate
   rather than unfinished.
3. **Cross-tenant work is a `PlatformOperation`** — an action object using a second connection as a
   distinct database role with narrow per-table policy clauses, never a global bypass, writing actor,
   reason, target tenant, and affected ids to an append-only log **in the same transaction**. If the audit
   write fails, the operation fails.

Stated for code review: *there is no `->sudo()`. If you think you need one, you need a new permission, a
new policy clause, or a `PlatformOperation` with a written reason.*

**Why QAYD differs** — The argument is about what an invariant *is*. An invariant a caller can switch off
is not an invariant; it is a default. And the two are indistinguishable from inside the code — the
constraint looks identical whether or not a flag exists somewhere that disables it. Every downstream
guarantee then becomes conditional on the flag not having been used, and nothing in the system records
whether it was.

The transitive-propagation detail is what makes the ambient version qualitatively worse than a local one.
A flag that affects one call is a bounded decision. A flag that propagates through every derived object
means a caller three layers away is running without checks, and neither the caller nor the reviewer can
see it. That is not a bypass; it is an unbounded change of execution mode.

**Tradeoffs**

- **Legitimate operations get harder**, and this is felt most acutely during incidents, which is exactly
  when someone will propose a shortcut.
- `PlatformOperation` is real machinery — a second connection, a distinct role, per-table policy clauses,
  audit — for work that a flag would make trivial.
- Data migrations that must legitimately touch immutable data need a designed path (a migration role with
  explicit, temporary, audited grants), and that path does not exist yet.
- A grep-based arch test is a blunt instrument that will produce false positives and be argued with.

**Future risks**

1. **Incident pressure** producing a temporary bypass that becomes permanent. The mitigation is that adding
   one requires a superseding ADR, which is deliberately slower than an incident.
2. **The migration path staying undesigned** until a migration needs it at 2am, which is the worst moment
   to design it.
3. **Bypass by another name** — a "system" user with permissions so broad it is a bypass with a user id.
   Watch permission grants, not just flag names.

**Estimated lifespan** — Permanent. There is no trigger for reversal. There is a real, scheduled need to
*build* the sanctioned alternatives (`PlatformOperation`, the migration role) so the rule stays workable
rather than merely strict.

**Confidence: High.** The counterfactual is quantified rather than argued: 552 ambient bypass call sites in
one codebase, 181 in the accounting module, none logged, plus five separately-named context flags doing the
same thing for individual invariants — and a self-referential admission in the source that the mechanism is
a privilege-escalation primitive.

---

# Decision trees

Three trees for the decisions engineers actually face while writing code. They encode the entries above;
if a tree and an entry disagree, the entry wins.

### Tree 1 — Where does this rule belong?

```
A new rule must hold. Where does it go?

Can it be expressed as a property of one row?
├── YES → CHECK constraint. Done. (AD-01/P1)
└── NO
    ├── Is it a relationship between rows in one table or across tables?
    │   ├── Expressible as FK / UNIQUE / EXCLUDE?
    │   │     → declarative constraint. Done.
    │   └── Needs multi-row arithmetic (sums to 100%, ≤ original)?
    │         → DEFERRABLE INITIALLY DEFERRED constraint trigger.
    ├── Is it a tenancy boundary?
    │     → RESTRICTIVE RLS policy + NOT NULL company_id + the CI catalog check. (AD-01)
    ├── Is it a state-transition rule (status A may become status B)?
    │     → the lifecycle map AND the mirroring trigger. Both. (AD-19)
    ├── Does it require the request's identity, permissions, or intent?
    │     → an Action. The database cannot know intent. (AD-06)
    └── Does it need to be switchable per tenant or per jurisdiction?
          → a named seam with a contract test — never a boolean flag. (AD-13, AD-21)

At no point is the answer "a flag the caller can pass". (AD-21)
```

### Tree 2 — Something in the books is wrong. What do I do?

```
Is the entry POSTED?
├── NO (draft)
│    └── Edit it. Optimistic `version` will reject a stale write. (AD-08)
└── YES → it is immutable. Forever. (AD-07)
     ├── Are the AMOUNTS wrong?
     │    └── Post a reversing entry through PostingService, with a reason,
     │        then post the correct entry. Two new documents; the original stands.
     ├── Is only DESCRIPTIVE text wrong (memo, reference)?
     │    └── Annotate via entry_comments. Never edit the posted row.
     ├── Is the PERIOD closed as well?
     │    ├── soft axis → a time-boxed, reasoned lock_exception; the posting is
     │    │               tagged so "what moved during the window" is one query. (AD-10)
     │    └── hard axis → refused. Correct in the current open period instead.
     └── Is the LEDGER PROJECTION wrong but the journal right?
          └── That is a projection bug, not an accounting event:
              fix the code, then RebuildPeriodBalances / rebuild the projection. (AD-20)

"Un-post it and fix it" is not on this tree, and never will be.
```

### Tree 3 — Where does this new piece of state live?

```
Does the value change after the entry is posted?
├── NO  → it may live on journal_entries / journal_lines / ledger_entries.
└── YES → it must NOT live on a ledger row. (AD-03)
     ├── Is it derived from other rows (a residual, a balance, a status)?
     │    ├── Is the source append-only?
     │    │    ├── YES → a rollup table with a monotonic trigger + a rebuilder. (AD-20)
     │    │    └── NO  → a VIEW. Do not store it.
     │    └── Is it a payment/reconciliation state?
     │           → a side table keyed to ledger_entry_id, plus a VIEW for the state. (AD-03)
     ├── Is it an N-way allocation across a dimension?
     │    → rows in journal_line_dimensions. Not a column. Not JSONB. (AD-11)
     └── Is it a record of a human decision (close, approval, exception)?
          → its own table with actor, reason, timestamp, and an audit row. (AD-10, AD-21)
```

---

# Cross-cutting summary: what we took, what we refused, what we designed

| Area | Odoo's design | QAYD's design | Relationship |
|---|---|---|---|
| Document model | One table, discriminated by type | Same (`entry_type`) | **Adopted** — validated by twenty years |
| Tenancy enforcement | `ir.rule` in the ORM + `sudo()` | RESTRICTIVE RLS + `NOBYPASSRLS` + CI catalog check | **Refused the mechanism, kept the AND/OR decomposition** |
| Physical tenancy | Database-per-tenant | Single DB + RLS, hybrid escape hatch | **Different problem** — SaaS economics vs. deployment unit |
| General ledger | Lines are the ledger | Append-only projection | **Refused** |
| Money | `numeric` column, float in Python | `NUMERIC(19,4)` + bcmath strings | **Refused** — deletes the epsilon subsystem |
| Numbering | Skip-tolerant + gap report | Gapless + verification job | **Refused, at a stated throughput cost** |
| Period control | Lock-date cursor (no period tables) | Cursor for enforcement + periods as dimension + close runs | **Neither — designed a third** |
| Lock exceptions | `account.lock.exception` | Same shape, hardened | **Adopted, improved** |
| Dimensions | JSONB distribution + materialized rows | Rows only | **Refused the layer, kept the concept** |
| Reversal | First-class posted document + storno | Same, plus DB-enforced cycle/over-reversal rules | **Adopted, improved** |
| Reconciliation residuals | Derived from partials, stored on the ledger row | Derived from partials, stored in side tables | **Adopted the derivation, refused the location** |
| Reports | Declarative model, string formulas, no cycle detection | Declarative model, typed operand rows, CTE cycle detection | **Adopted the model, refused the execution** |
| Tax→VAT link | Reconstructed at report time ("approximate" branch) | Persisted at post time | **Refused** |
| Exchange rates | Falls back to earliest, then to 1.0 | `RATE_MISSING_FOR_DATE`, never extrapolate | **Refused** |
| Hash chain | Python-enforced, allowlisted fields, unkeyed | Trigger-enforced, all columns, externally anchored | **Adopted, hardened** |
| Validation errors | Aggregated into one string | Aggregated into structured violations | **Adopted the instinct, added structure** |
| Cross-module | MRO override / inheritance | After-commit domain events + outbox | **Refused** |
| Workflow engine | Built one, then deleted it | Never build one | **Adopted the lesson, skipped the cost** |
| Privilege escape | `sudo()`, ambient and transitive | `PlatformOperation`, explicit and audited | **Refused** |

---

# Decisions we have NOT yet made

Genuinely open. Listing them honestly is worth more than pretending the set is closed — and each one names
what would force it and by when.

### O1 — Connection pooling mode, and the GUC lifecycle under it
**Open.** RLS GUCs are per-*connection*. Under PgBouncer transaction pooling a connection is handed to
another request mid-session, so a `SET` (rather than `SET LOCAL`) or a query outside an explicit transaction
leaks one tenant's context into another tenant's request. We have not chosen a pooling mode, audited every
path (HTTP, queue workers, console commands, scheduled jobs) for the `SET LOCAL`-inside-a-transaction
guarantee, or tested it with genuine concurrency against a real pooler.
**Forced by:** enabling connection pooling in any environment carrying real tenant data.
**By when:** before pooling is switched on — not after. This is a cross-tenant data-leak check, not a
performance task, and it is invisible in single-connection testing. It is the highest-severity open item in
this document.

### O2 — Data residency and the regional split
**Open.** AD-02 commits to one primary with a per-tenant escape hatch, and forbids single-primary
assumptions so the hatch stays available. What we have not decided: whether a second region is a separate
deployment or a shard of one logical system, and whether identity is global (a `global_users` store with
regional mirrors, as one specification describes) or per-region. ADR-0010 defers this explicitly.
**Forced by:** a customer contract or a regulation requiring in-country data storage — most likely Saudi.
**By when:** before signing the first such contract; the lead time is months, not weeks.

### O3 — Sequence scope for gapless numbering
**Open.** The shipped allocator scopes sequences by `(company_id, fiscal_year, entry_type)`. The research
assumed per-journal-per-period. These give different numbering to the same books and different contention
profiles, and finer scoping trades throughput for more chains to audit.
**Forced by:** the first customer whose statutory numbering expectation contradicts our scope, or the first
measured contention problem.
**By when:** before the first production tenant posts at volume — changing sequence scope after entries
exist means renumbering, which AD-07 forbids.

### O4 — Consolidation and the only sanctioned cross-tenant read
**Open.** Gulf groups commonly run one company per jurisdiction or licence, and will want consolidated
statements. The security model is understood in outline — snapshot-and-freeze under a dedicated audited
database role as the *only* cross-tenant read in the system — but nothing is designed, and the interaction
with AD-01's RESTRICTIVE boundary is the hard part.
**Forced by:** the first group customer.
**By when:** it need not be built early, but the RLS model must not be frozen in a way that makes it
impossible — which is why it is recorded now rather than later.

### O5 — Immutable published report snapshots
**Open.** Once a statement is issued to an auditor it must be reproducible. If an account's classification
or a report definition changes afterwards, the reissued statement differs from the issued one. AD-16 and the
cash-flow bucket design both assume a snapshot concept exists (reclassification blocked for periods covered
by an approved snapshot); it does not.
**Forced by:** the first audited close, or the first customer who reissues a prior-period statement.
**By when:** with or before the Balance Sheet.

### O6 — The predicate language for AI-authored selectors
**Open.** A storable, reviewable, compilable predicate — emitted by an agent, read and approved by a human,
compiled to SQL through an allowlist — is what makes "the AI proposes, it never writes" implementable
beyond journal drafts. One compiled selector would serve report expressions, reconciliation matching rules,
and dimension distribution rules. We have not designed its grammar, its allowlist, or its review UI. The
only settled part is negative: **never `eval`, never string interpolation.**
**Forced by:** the second subsystem that needs AI-authored rules — bank matching.
**By when:** before bank matching is built, or three subsystems will each invent their own.

### O7 — Tax engine ownership: build versus rules-as-data per jurisdiction
**Open.** We have decided the *shape* (repartition rows with a DB-enforced ±100% invariant, integer ppm
factors, tax→GL and tax→VAT-box links persisted at post time). We have not decided whether GCC country
rules are our code, our data, or a third-party pack — which determines who is liable when a rate changes
and a filing is wrong.
**Forced by:** the first non-Kuwait jurisdiction, or a KSA e-invoicing integration.
**By when:** before the second jurisdiction ships.

### O8 — Per-tenant restore procedure
**Open.** AD-02 accepts that per-tenant restore is hard as a consequence of the shared database. The
consequence has not been designed: restoring one company's ledger to a point in time requires a
`company_id`-scoped logical export, a documented procedure, and — critically — a *rehearsal*. Right now the
honest answer to "can you restore one customer's books to Tuesday?" is "not without downtime for everyone."
**Forced by:** the first customer data-loss incident, which is the worst possible moment.
**By when:** before the first enterprise contract with a recovery-objective clause.

### O9 — Period-13 and adjustment-period semantics
**Open.** Whether year-end adjustments post into an extra period, into the last period with a flag, or into
a distinct entry type — and how each interacts with the lock cursor and the close run.
**Forced by:** the first year-end close.
**By when:** with the AD-10 implementation, or it will be retrofitted under audit deadline pressure.

### O10 — Field-level access control mechanism
**Open.** Cost prices, salaries, and bank details need per-field access, which we do not have at any
granularity. The direction is two layers — a permission attribute enforced in the API Resource layer, plus
real Postgres column `REVOKE` for the handful where a bug must not leak — but neither the boundary between
those layers nor the interaction with `perms_ver` cache-busting is settled.
**Forced by:** the payroll module, or the first customer with segregated-duties requirements.
**By when:** with payroll.

---

# Provenance and maintenance

**Prior art.** Every "Odoo's approach" paragraph is a factual statement about **Odoo 19.0.0, commit
`f3e407c6`, LGPL-3**, verified against the source and cited line-by-line in
[`../../research/odoo/ODOO_LEARNING.md`](../../research/odoo/ODOO_LEARNING.md). **No Odoo source is
reproduced anywhere in this document**, and every schema, constraint, Action, DTO, and exception named here
is an original QAYD design targeting Laravel 12 / PHP 8.4 / PostgreSQL with RLS and bcmath. Where the study
found something absent from Odoo Community (unrealized FX revaluation, the report evaluator, the bank
matching evaluator), this document says so rather than inventing a comparison.

**Maintenance rules.**

1. A decision here is amended only by adding to it, never by silently rewriting it. If a decision is
   reversed, set `Status: Reversed`, link the superseding ADR, and keep the original reasoning so it is not
   re-litigated from scratch.
2. Any entry that reaches `Settled` and is load-bearing enough to constrain later work should have a formal
   ADR in [`../adr/`](../adr/). The register at the top of this document lists the four entries that
   currently need one.
3. When an entry's **revisit trigger** fires, that is not a suggestion — it is the moment to re-open the
   decision with evidence rather than intuition.
4. The "Decisions we have NOT yet made" section is a debt register. An item leaves it only by becoming an
   AD, never by being forgotten.

**Next review:** at the close of Sprint 2, when AD-08's defect, AD-10's implementation, AD-11's formal ADR,
and AD-19's transition map are all expected to have landed or to have slipped — and either outcome is
information.

# End of Document
