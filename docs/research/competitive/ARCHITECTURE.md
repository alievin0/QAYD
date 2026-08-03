# Architecture — what a business model forces in the schema

**Commercial decisions are architectural decisions with a delay. This document names which ones, and
which of them stop being reversible at the first customer.**
Version 1.0 · 2026-07-28 · Companion to [`LESSONS_FOR_QAYD.md`](./LESSONS_FOR_QAYD.md)

---

## 0. The claim this document exists to make

The sibling folders study architecture and derive engineering conclusions. This one runs the arrow the
other way: **a business model is a set of constraints on the data model, and choosing the model without
choosing the constraints means discovering them later, in a migration, on a live tenant.**

Most of them are cheap now and expensive later. Two are cheap now and *impossible* later, and those are
the point of the document:

| Decision | Cost if decided now | Cost if decided after the first customer |
|---|---|---|
| **Is the firm above the company in the tenancy hierarchy?** (§4) | A column and a policy | Rewriting the RLS boundary — QAYD's hardest-won property |
| **Is the ledger the substrate or is it a mirror of someone else's?** (§2) | Already decided by having built one | A second write path, which the architecture refuses |
| Per-tenant cost attribution (§7) | An outbox consumer | Backfill is impossible; the events were not emitted |
| The reviewed-state shape (§9) | Already a schema property | Retrofit into live proposals |
| The obligation spine (§10) | A table nobody writes to | A re-architecture under a regulatory deadline |
| A meter (§5) | A counter column | Reconstructing history to bill it |

Evidence grading per `README.md`. Claims about QAYD's current schema are `[CODE]` where the sibling
research read them from source, and are attributed to the folder that read them.

### Contents

1. [The general shape: what commercial models constrain](#1-the-general-shape)
2. [The substrate/integration fork](#2-the-substrateintegration-fork)
3. [What the channel forces](#3-what-the-channel-forces)
4. [Firm tenancy — the one that stops being reversible](#4-firm-tenancy)
5. [What pricing forces: metering and plan state](#5-what-pricing-forces)
6. [What "never gate correctness" forces](#6-what-never-gate-correctness-forces)
7. [What a free tier forces: per-tenant cost attribution](#7-what-a-free-tier-forces)
8. [What portability forces: the exit as a feature](#8-what-portability-forces)
9. [What the practitioner review model forces](#9-what-the-practitioner-review-model-forces)
10. [What deferred compliance forces](#10-what-deferred-compliance-forces)
11. [The negative space: what a business model forbids](#11-the-negative-space)
12. [The decision table](#12-the-decision-table)

---

# 1. The general shape

Six business models were catalogued in `OVERVIEW.md` §7. Each one, if adopted, writes itself into the
schema. This table is the compressed version of the whole document.

| Model | The architectural consequence | Reversible after launch? |
|---|---|---|
| **1 · Per-org subscription, feature-tiered** | Plan state per tenant; every gated capability needs an enforcement point; the enforcement points multiply with tiers | Partly — adding tiers is easy, un-gating is easy, *re-gating* is a customer-facing removal |
| **2 · Interchange-funded free** | A money-movement domain: cards, authorisations, settlement, scheme reporting, a two-phase money model in the ledger | No. It is a different product |
| **3 · Suite bundle** | Cross-application identity, shared directory, a licence unit that is not the finance user | No |
| **4 · Sell to the firm** | **A tenancy level above the company**, cross-tenant read scoping, firm-scoped work queues, invitation as a first-class object | **No — this is §4** |
| **5 · Usage-metered** | A counter that is authoritative, a billing period, an idempotent meter increment, and a dispute path | Partly — the counter can be added; the *history* cannot |
| **6 · Implementation-led licence** | Per-tenant configuration depth, and eventually per-tenant code — which `../erp/` L-11 shows turns upgrades into projects | Yes, but the codebase does not un-fork |

**The recommendation from `OVERVIEW.md` §7.2 is model 1 with model 4 as the distribution motion.** That
pairing is the reason this document is mostly about §4 and §5.

---

# 2. The substrate/integration fork

## 2.1 The fork, restated in architectural terms

`OVERVIEW.md` §4.1 draws the commercial fork. Here is what each side *forces*:

```
BET 1 — OWN THE SUBSTRATE                  BET 2 — OWN THE LABOUR
Your ledger is the system of record.       Someone else's ledger is the system of record.

FORCES:                                    FORCES:
· double-entry invariants you enforce      · a sync engine with conflict resolution
· immutability, audit, chain               · a mirror of their object model
· tenancy you own end to end               · rate limits, pagination, eventual consistency
· migration in (their data → yours)        · idempotent writes into a mutable remote store
· statements, close, reporting             · a permanent "our copy vs their copy" question
· the whole accounting surface             · versioning against an API you do not control

COSTS:                                     COSTS:
· a rip-and-replace sale                   · your substrate is a competitor's product
· breadth debt for years                   · they can ship the feature, close the API,
                                             or forbid the use case
```

## 2.2 QAYD is on Bet 1 by construction, and the architecture is what makes it true

`OVERVIEW.md` §4.1 says this commercially. The engineering evidence is stronger than the commercial
statement, and it comes from three sibling folders:

- **One writer.** `AD-04` makes `JournalEntryPostingService` the only code path authorised to write a
  posted line, re-deriving the balance from the lines themselves with zero tolerance in both entry and
  base currency `[CODE, via ../payments/ §1.1]`.
- **Append-only, enforced in the storage engine.** `trg_ledger_entries_append_only` raises on any
  `UPDATE` or `DELETE`, independent of the application layer `[CODE, via ../payments/ §1.3]`.
- **Idempotent projection as a uniqueness constraint** — `uq_ledger_entries_journal_line UNIQUE
  (journal_line_id)` `[CODE, via ../payments/ §1.4]`.

**The commercial significance of those three facts is that Bet 2 is not available to QAYD as a cheap
pivot.** A "let's just integrate with QuickBooks" strategy would require a second write path into the
ledger or a second ledger, and the architecture refuses both. That is a *feature* of the position: the
pivot that `ANTI_PATTERNS.md` §10 warns about is expensive by construction, not merely discouraged.

## 2.3 The hybrid that is not available, and why it matters to say so

There is an apparently attractive middle: own the ledger, but also read the incumbent's data for
customers who have not switched, to sell intelligence before selling replacement.

**It is not a middle. It is Bet 2 with extra steps**, and it forces every Bet-2 cost — a mirror of their
object model, conflict resolution, rate limits, versioning — while adding a second source of truth to a
product whose entire architecture is built on there being exactly one. `../payments/ §4.5` observes that
Mercury, Brex and Ramp all expose a flat signed transaction feed with no double-entry primitive and defer
journal entries to NetSuite, QuickBooks or Xero. **The category QAYD is entering has largely decided that
double entry is somebody else's problem; QAYD's decision to own it is the differentiator, and the hybrid
gives it away while keeping the cost.**

**And it is now contractually hazardous.** `ANTI_PATTERNS.md` §10 records the March 2026 Xero platform
change: per-connection and per-GB-egress fees, plus **a prohibition on using Xero API data to train AI/ML
models** `[DOCS, verified 2026-07]`.

## 2.4 What Digits changes, and what it does not

`OVERVIEW.md` §4.2 is honest that Digits is a greenfield ledger designed for machine authorship, five
years in, funded, shipping, and a 2026 Accounting Today Top New Product `[COMMUNITY]`. **The architectural
question is narrower than the commercial one and it is the one that decides whether QAYD's white space
survives:**

> Does its ledger carry, as *columns*: a proposal distinct from a posting; a confidence; cited source
> rows as dereferenceable keys; a reviewer identity; an approval event; and a tamper-evident chain — with
> the model holding no grant that would let it write the posted table?

**That is `[UNKNOWN]` and cannot be determined from outside a closed product** (`OVERVIEW.md` §10 item 1).
The architectural position that follows is the honest one: QAYD's claim is not "nobody has an autonomous
ledger" — it is **"auditor-grade provenance is a stronger claim than autonomy, and nobody has publicly
demonstrated it."** The second claim is defensible against a competitor whose internals are unknown; the
first is not.

---

# 3. What the channel forces

Adopting model 4 — sell to the firm — is not a go-to-market decision that the schema can ignore. It
forces five things, in increasing order of cost.

## 3.1 Invitation as a first-class object

`BEST_PRACTICES.md` §5.2: adding a client must cost one field. Architecturally that means an **invitation
is an object with a lifecycle** (issued → accepted → active → revoked), not a side effect of creating a
user. The reason is that the accountant issues it before the client exists, which means the invitation
must be able to hold a tenancy relationship that has no user on one end yet.

Getting this wrong produces the flow practitioners complain about — *"you type an email address and that's
it"* on Xero and QuickBooks versus a clunkier alternative `[COMMUNITY, via ../accounting/ §6]`.

## 3.2 Cross-tenant read, scoped, without an ambient bypass

The firm's core work is *across* clients. That requires a session that can read more than one
`company_id` — which is precisely the shape that produces the ambient-privilege bypass
`../security/LESSONS_FOR_QAYD.md` §2.4 warns about: *"every ambient-privilege bypass in every system began
as a silently-empty result someone needed to fix."*

**The constraint: cross-client access must be a *scoped enumeration*, never a bypass.** A firm session
carries the set of `company_id`s the firm is engaged for, and the RLS predicate tests membership of that
set. The RESTRICTIVE company boundary is preserved; what changes is the value it tests against, not
whether it tests. `[INFERENCE — the mechanism follows directly from ../security/'s findings; it has not
been designed or reviewed.]`

**And it must fail loudly.** `../security/` §2.4: a missing tenant context must raise, not return empty.
That applies doubly here, because a firm session with an empty engagement set looks exactly like a bug.

## 3.3 Firm-scoped work queues

A review queue, a coding queue, a close-status board — each must be expressible over *the firm's* work,
not a client's. If the queue is a per-tenant table, the firm-level view is N queries and a merge, which
does not paginate, does not sort correctly and does not scale past a handful of clients.

**This is the concrete cost of the portal architecture** (`ANTI_PATTERNS.md` §15): not that the accountant
cannot log in, but that the firm's actual job cannot be expressed.

## 3.4 Bulk operations that respect the invariants

`../accounting/LESSONS_FOR_QAYD.md` §3 lists a keyboard-first bulk coding surface as an adopt, on the
grounds that *"the bookkeeper with 400 lines decides adoption, not the owner with 4."* Architecturally,
bulk means **one transaction, one posting-service invocation per entry, and an all-or-nothing outcome** —
because a partial application is worse than none (`../payments/ PA-11`).

**The tension to design against:** bulk approve of AI proposals is the fastest path to the failure
`../ai/LESSONS_FOR_QAYD.md` L-09 names — 99% approval, no error, no alert, every downstream metric quietly
poisoned. Bulk must therefore be **instrumented differently from single approval**: recorded as bulk,
sampled, and excluded from the calibration curve unless individually attested. `[INFERENCE]`

## 3.5 The firm as an accountable actor in the audit trail

If a firm user approves a client's entry, the audit row must record **both** identities — the human and
the firm — because the client's auditor will ask on whose authority the entry was made, and "user 4471"
is not an answer that survives a change of accountant.

This is a small extension of `../banking/LESSONS_FOR_QAYD.md` A1 (write an audit row inside the posting
transaction, the single highest-priority item there), and it is nearly free **if the firm concept exists
when that audit row is designed.** It is not free afterwards.

---

# 4. Firm tenancy

**This is the decision that stops being reversible, and it is the reason this document exists.**

## 4.1 The question, stated exactly

> Is `company_id` the top of the tenancy hierarchy, or is there a tenancy level above it?

QAYD today: `company_id` with `FORCE ROW LEVEL SECURITY`, a RESTRICTIVE boundary policy, a runtime role
that is `NOSUPERUSER NOBYPASSRLS`, and `SET LOCAL` GUC context `[CODE, via ../security/ §1]`.
`../security/LESSONS_FOR_QAYD.md` calls the `NOSUPERUSER NOBYPASSRLS` line *"the highest-leverage line in
the codebase."*

## 4.2 Why it is not reversible

Three reasons, and the third is decisive.

1. **RLS is a table feature.** `../analytics/LESSONS_FOR_QAYD.md` L-07 makes the point in another context:
   a result computed outside a tenant session *cannot be re-protected afterwards*. The boundary is
   evaluated where the data is read; changing what the boundary tests means changing every policy on every
   tenant-scoped table.
2. **RESTRICTIVE policies combine with AND** `[DOCS, via ../security/ §1]`. That is what makes them the
   floor nothing can widen — and it also means a firm-level predicate cannot be *added alongside* the
   company predicate to loosen it. It has to be *inside* it.
3. **Composite unique constraints carry `company_id` as a security control.**
   `../security/LESSONS_FOR_QAYD.md` §2.1 establishes that referential-integrity checks always bypass row
   security `[DOCS]`, so a unique constraint omitting `company_id` is a **cross-tenant existence oracle**.
   Every such constraint in the schema encodes the current tenancy shape. **Changing the tenancy shape
   means revisiting every composite unique in the database, under live data, with a security property
   riding on getting each one right.**

That third point is the one that converts "expensive" into "the kind of migration a small team does not
survive." `[INFERENCE — the individual facts are `[DOCS]`/`[CODE]` via ../security/; the aggregate cost
assessment is inferred.]`

## 4.3 What the decision is *not*

**It is not "build the Pennylane workspace now."** The full firm-and-client shared workspace is a large
product surface and it is not an MVP feature.

The decision is narrower and almost free:

> **Does the schema admit a tenancy level above `company_id`, and is the firm↔company engagement modelled
> as an object from the start?**

Concretely, the minimum shape: a `firms` table; a `firm_engagements` table carrying
`(firm_id, company_id, status, effective_from, effective_to)`; the session GUC able to carry a firm
context alongside the company context; and the RLS predicate written so that a firm context resolves to
an enumerated set of `company_id`s. **None of that requires a firm UI, a firm queue, or a firm plan.** It
requires the tables to exist and the boundary to be written once, correctly.

**Note the temporal columns.** `../erp/LESSONS_FOR_QAYD.md` L-09 argues that classification relationships
which reporting slices by must be temporal — "which accounts were in Operating Expenses in FY2024?" is
unanswerable if membership is a mutable FK. **A firm engagement is exactly such a relationship**: "who was
the accountant when this entry was posted?" is asked after the accountant changes, which is exactly when
the FK was mutated. `effective_from`/`effective_to` on the engagement, not a mutable status alone.

## 4.4 The counter-argument, taken seriously

MANIFEST Law 2 says **do not build the future** — implement only the current sprint's scope, nothing
ahead of it, however certain it is to be needed later. A firm tenancy with no firm customers is, on its
face, exactly that.

**The counter-argument is that Law 2 governs *features*, and this is a *constraint*.** The distinction is
whether the thing is cheap to add later. A firm dashboard is cheap to add later; a tenancy boundary is
not, for the three reasons in §4.2. `../erp/LESSONS_FOR_QAYD.md` L-13 draws the same line for a different
subject: *"foundations cannot be retrofitted — an invariant added later must first prove the existing data
satisfies it, and it never does. Features can be added at any time."*

**The honest resolution is a smaller commitment than "build it":** decide it, write the ADR, and build the
minimum that keeps the option open. If the channel strategy is abandoned, two unused tables is a cheap
mistake. If it is pursued and the schema forbids it, the mistake is not cheap. → `IMPLEMENTATION_
RECOMMENDATIONS.md` **C-05**.

---

# 5. What pricing forces

## 5.1 Plan state is an enforcement surface, and tiers multiply it

Every gated capability needs a point where the gate is evaluated. One tier boundary is one enforcement
point; six tiers with overlapping features is a matrix. Zoho's six tiers `[DOCS]` are a commercial choice
with an engineering tail that grows superlinearly with tier count and feature count.

**The architectural argument for `BEST_PRACTICES.md` §2.3's single-plan bias is therefore not only
commercial:** a single plan has zero enforcement points, which is zero places for a gate to be forgotten,
inconsistent, or bypassable.

**And when tiers do arrive, they must gate on scale, not capability**, for an architectural reason as well
as a commercial one: **a scale gate reads a counter, a capability gate branches the code.** Counters do not
fork behaviour; capability flags do, and forked behaviour is where the correctness regressions live.

## 5.2 A meter is a financial record, and must be built like one

If model 5 (usage-metered) is ever adopted, the meter is not a statistic. It is the basis of an invoice,
which means:

- **Idempotent increment.** A retried request must not double-count. This is the same class of problem as
  `../payments/ §3.1`'s idempotency finding, and it has the same correct answer: **write the counter in
  the same transaction as the fact**, not after it, not in Redis.
- **Immutable history.** A disputed bill requires the ability to show what was counted and when. A
  mutable counter cannot answer that.
- **A defined period boundary**, anchored to a declared timezone — `../banking/LESSONS_FOR_QAYD.md` A6
  notes QAYD's `journal_date` has no declared anchor and that a Kuwait company closing at 23:00 AST is
  doing something a UTC server resolves into the next day. A billing period has the same hazard.

**Which is the argument for `BEST_PRACTICES.md` §2.4's verdict of "later":** a correct meter is a small
financial subsystem, and building one before there is anything to meter is building the future.

**What is *not* later: the counter.** A `documents_processed` count per tenant per period is a column and
an outbox consumer. It costs almost nothing and it is the only way the free-tier threshold in
`BEST_PRACTICES.md` §4.3 can ever be set from evidence rather than guessed.

## 5.3 Entity count is a pricing axis and a tenancy axis, and they must agree

`BEST_PRACTICES.md` §2.5 recommends pricing the second entity down. Architecturally that requires knowing
what an entity *is* for billing, and the natural answer — a `company_id` — is also the tenancy unit and
the RLS boundary. **Those must be the same thing or the billing will drift from the isolation.**

The GCC-specific complication, from `../erp/LESSONS_FOR_QAYD.md` L-08: in GCC family-owned structures the
same legal entity is routinely customer, supplier, lessor and shareholder. **A group of eight companies
under one owner is one buying decision and eight tenancy units**, and the pricing must reflect the first
while the isolation reflects the second.

---

# 6. What "never gate correctness" forces

`BEST_PRACTICES.md` §2.2 refuses to tier-gate correctness. The architectural consequence is a **named
set** — a list of capabilities that are, by policy, never behind a plan check:

| Capability | Why it is on the list | Source |
|---|---|---|
| **KWD/BHD/OMR three-decimal precision, end to end** | A defect in the category's volume leader; free for QAYD; and a rounding difference is not a premium feature | `../erp/` L-06 |
| **Audit trail and its export** | It is the evidence of what the system did. Xero routes an audit-trail report to its App Store `[COMMUNITY]` | `ANTI_PATTERNS.md` §9 |
| **Full data export** | §8 below | — |
| **Immutability and reversal correctness** | It is not a feature, it is the ledger working | `../banking/` |
| **Multi-currency, when it ships** | Xero's GLOBAL edition locks it to the top tier `[DOCS]`; a Kuwaiti trading SME holds USD and AED | `ANTI_PATTERNS.md` §7 |
| **Arabic UI and Arabic document output** | Gating a language is gating the market | `../accounting/` §7.2 |

**The architectural discipline this produces is useful independent of pricing:** these capabilities must
have **no plan-check call site at all**, so that a future gating decision requires adding code rather than
flipping a flag. A capability that is gateable by configuration will eventually be gated by configuration.
`[INFERENCE]`

---

# 7. What a free tier forces

## 7.1 Per-tenant cost attribution, which does not exist and cannot be backfilled

`BEST_PRACTICES.md` §4.3's verdict is "adopt the shape, defer the offer", because the threshold cannot be
set without knowing the per-document AI cost. That is not a pricing gap; it is an **instrumentation gap**,
and it is the same class of gap `../analytics/LESSONS_FOR_QAYD.md` L-01 identifies at the database level:
*"an alert that cannot fire is a plan, not a control."*

What is required, and it is small:

- Every model call records tenant, capability, model tier, input tokens, output tokens, cache-read tokens
  and whether it was batched. `../ai/` establishes all of those are available and that
  `cache_read_input_tokens` in particular must already be asserted in tests (L-17).
- The records flow through the outbox to the advisory analytics tier, never into the accounting database
  (`../analytics/` L-04: DuckDB over Parquet, out of process, advisory only).
- The derived number is **cost-to-serve per tenant per month**, which `../analytics/` L-04 names as one of
  the numbers that decides whether QAYD is a good business.

**Why it cannot be backfilled:** the token counts exist only at the moment of the call. A month of
un-instrumented usage is a month with no cost data, permanently.

## 7.2 The free tier's boundary must be enforceable without degrading the product

`BEST_PRACTICES.md` §4.2: a free tier's job is to be outgrown, not to be sufficient. Architecturally that
means the boundary is a **counter check at a single point**, not a set of degraded behaviours scattered
through the product. Degraded behaviour is how a free tier becomes a maintenance burden and a source of
"is this a bug or the free plan?" support load. `[INFERENCE]`

---

# 8. What portability forces

## 8.1 The exit is a feature, and it is the one that makes the entry credible

`BEST_PRACTICES.md` §3.3 argues for a free importer because switching cost protects the incumbent. **The
mirror obligation is that QAYD's own switching cost must be low, and saying so must be true.**

The commercial reason: a small vendor with no track record asking a business to move its general ledger is
asking for a large act of trust. **"You can leave with everything, and here is the export" is the cheapest
available substitute for a track record.** `[INFERENCE]`

## 8.2 What the export must contain to be honest

Not a CSV of transactions. A migration-grade export is:

- The full chart of accounts with hierarchy and any temporal classification membership (`../erp/` L-09).
- Every journal entry and line, with the **entry currency and base currency amounts both**, and the
  three-decimal precision preserved as decimal — never as float. `../analytics/` L-02 makes exactly this
  point about the Parquet archive: map `NUMERIC(19,4)` to `DECIMAL(19,4)`, never `DOUBLE`.
- The reconciliation matches as rows, not as flags — which QAYD already does correctly, since
  `bank_reconciliation_matches` is a side table and `journal_lines.reconciled` is the rejected shape
  (`../payments/ §3.4`).
- The audit trail.
- **The source documents**, because a ledger without its evidence is not a book of record.

## 8.3 The archive already does most of this work

`../analytics/LESSONS_FOR_QAYD.md` L-02 and L-03 specify a Parquet partition export with a manifest
carrying row count, `sum_signed_base_amount`, `ledger_head_id` and `ledger_head_hash`. **That is a
portable, verifiable, self-describing export of a fiscal year** — built for archival, and it is
substantially the customer-facing export as well.

**The commercial observation this document adds:** the manifest's head hash makes a claim no competitor in
this study can make about an export — *"this archived fiscal year is provably the one the books
contained."* `../analytics/` L-03 already identifies that as serving the GCC audit posture. It is also the
single most persuasive answer to "what if we want to leave?"

---

# 9. What the practitioner review model forces

## 9.1 The reviewed state is a schema property, and this is the competitive opening

`ANTI_PATTERNS.md` §20 records the finding: **Xero's JAX auto-reconcile is still Beta, gated above the
entry tier, produces no auditable "reviewed by a human" state, and drops description and reference**
`[COMMUNITY, verified 2026-07]`.

The architectural shape that answers it — and which QAYD's plan already has — is:

| Property | Why it is required | QAYD status |
|---|---|---|
| Proposal is a distinct object from the posting | So the machine can act without acting on the ledger | `[CODE]` — `trg_no_ai_autopost`, via `../security/` |
| Confidence recorded per proposal | So attention can be directed | Required by `P-12` |
| Cited source rows as dereferenceable keys | So the reasoning can be checked, not trusted | `precedents_cited`, via `../ai/` L-19 |
| Reviewer identity and approval event | So the sign-off has evidence | Required by `P15` |
| Structured, machine-readable rationale | So it can be aggregated, diffed and regression-tested | `P-12`, via `../ai/` L-19 |
| The AI holds no grant that permits a post | So the boundary is structural, not behavioural | `[CODE]`, via `../security/` |

**Three cautions carried from `../ai/` and `../security/`, because a reviewed state that is not
instrumented is theatre:**

1. **`trg_no_ai_autopost` is `BEFORE INSERT` only** `[CODE, via ../security/ §3.1]`. An AI-generated row
   inserted as a draft and subsequently `UPDATE`d toward posted meets no trigger. `../security/` calls
   closing this **the single most important item in that corpus** — it is the terminal control on prompt
   injection, and it is the control that will be under most pressure as AI features ship, because it is
   the one that says no to an automation someone wants.
2. **Approval must be instrumented or it is not a control** (`../ai/` L-09) — engagement above
   materiality, time-to-approve telemetry, and a blind-sampled second-review stream. The blind stream is
   the only measurement not conditioned on the reviewer having seen the model's opinion.
3. **Human approval is not an injection defence** (`../ai/` L-08). Review defends against model error;
   the privilege boundary defends against attack. Believing either covers both under-invests in both.

## 9.2 Bulk surfaces and the invariant tension

Restated from §3.4 because it is the place where the channel's ergonomics and the AI's safety collide:
the bookkeeper with 400 lines wants bulk approve; the calibration curve needs individually-considered
judgements. **The resolution is that bulk is recorded as bulk** — a distinguishable state, excluded from
the accuracy estimate — rather than forbidden or silently pooled with individual approvals.

---

# 10. What deferred compliance forces

`BEST_PRACTICES.md` §8.2 recommends the obligation spine now and refuses the urgency. The architectural
content of "the spine":

**An obligation is a first-class object:** type, jurisdiction, period, due date, computed amount, evidence
(a set of ledger references), filing state, and lifecycle. Each regime is then an **adapter** that
computes amounts and produces the filing artefact, rather than a re-architecture.

Two constraints on the spine that come from elsewhere in the research:

- **The computation must be a policy consulted by the posting seam, not a rules engine.**
  `../erp/LESSONS_FOR_QAYD.md` L-12 is precise about this: Oracle Fusion's Subledger Accounting has the
  right idea and the implementation is a trap — *"a rules engine is a programming language you now
  maintain, debug and secure, usually without a debugger."* Keep the seam; scope any engine to tax first;
  never to the double-entry core.
- **Jurisdictional variation is where authors encode their own assumptions.** `../erp/` L-06's OFBiz
  finding — a 0.01 tolerance literal that lets 0.009 KWD post — is exactly the class of defect a spine
  designed by someone in a two-decimal jurisdiction produces. **QAYD's advantage is that its author's
  jurisdiction is the one everyone else gets wrong, and that only pays off if it is tested.**

---

# 11. The negative space

What the chosen business model **forbids**, recorded so the refusals have architectural reasons and not
only commercial ones.

| Forbidden | The architectural reason | Commercial argument |
|---|---|---|
| **User-authored server-side code** | It permanently freezes internal interfaces — the mechanism that stops Dolibarr's core ever tightening an invariant. QAYD's advantage is the freedom to keep tightening | `../accounting/` §5 |
| **A published app platform, for now** | Every published interface is a compatibility obligation; an ecosystem is a commitment never to fix your foundations | `ANTI_PATTERNS.md` §4 |
| **A second ledger store** | Two stores to keep consistent, a distributed transaction on the most correctness-critical operation, and `ledger_entries` losing the ability to join `accounts` — which is the basis of every report QAYD sells | `../banking/` R1, `../payments/` §4.1 |
| **A general pending/authorisation phase on the ledger** | Every balance query, report, rollup, export and API response would specify a phase forever, to serve a feature that does not exist | `../banking/` R2 |
| **In-database analytical engines (`pg_duckdb`)** | An alternative scan path over tenant tables is an RLS surface to audit, inside the database that holds the ledger | `../analytics/` L-09 |
| **Materialised views over tenant-scoped money** | The view is populated in the refresher's GUC context: empty under fail-closed RLS, or cross-tenant under a bypassing role — and RLS is a table feature, so it cannot be re-protected afterwards | `../analytics/` L-07 |
| **Kafka** | A second durability domain reintroduces the dual-write problem the outbox exists to solve, at three to four orders of magnitude below its design point | `../analytics/` L-08 |
| **Per-customer code branches** | Upgrades become projects rather than deploys | `../erp/` L-11, `ANTI_PATTERNS.md` §18 |

**The pattern across all eight:** every one of them is a *reasonable* engineering choice for a company
with a different business model, and every one would degrade a property QAYD has already earned — one
joinable database, RLS tenancy, one write path, no dual-write. `../banking/LESSONS_FOR_QAYD.md` §5 states
the general form: **adopt the posture, not the machinery.**

---

# 12. The decision table

Which commercial decisions are schema decisions, when they must be made, and what happens if they are not.

| # | Decision | Schema impact | Deadline | If missed |
|---|---|---|---|---|
| 1 | **Firm above company in tenancy?** | `firms`, `firm_engagements` (temporal), GUC, RLS predicate, every composite unique | **Before the first customer** | Rewrite the RLS boundary and revisit every composite unique under live data. §4.2 |
| 2 | **Substrate or integration?** | One write path vs a sync engine and a mirror | **Already made** — by having a ledger | A second write path, which the architecture refuses. §2 |
| 3 | **Per-tenant AI cost attribution** | Model-call telemetry → outbox → advisory tier | Before the first free tier or price | The threshold is guessed; the history cannot be backfilled. §7.1 |
| 4 | **Reviewed-state shape** | Proposal / confidence / citations / reviewer / approval / chain | Now — mostly present | Retrofit into live proposals; and `trg_no_ai_autopost` remains `INSERT`-only. §9.1 |
| 5 | **Correctness never gated** | No plan-check call sites on the named set | Now (it is an absence) | A gateable capability eventually gets gated. §6 |
| 6 | **Meter axis, if ever** | An idempotent counter written in the fact's transaction | Counter now; meter later | History cannot be reconstructed to bill it. §5.2 |
| 7 | **Export completeness** | Decimal fidelity, matches-as-rows, documents, manifest with head hash | With the Parquet archive | The exit claim is not true, which makes the entry claim not credible. §8 |
| 8 | **Obligation spine** | `obligations` + adapters; policy at the posting seam | Cheap now, any time before Saudi | A re-architecture under a regulatory deadline. §10 |
| 9 | **Entity = tenancy = billing unit** | One identifier serving three purposes | With pricing | Billing drifts from isolation. §5.3 |
| 10 | **Bulk recorded as bulk** | A distinguishable approval mode | With the bulk surface | The accuracy estimate is silently poisoned. §3.4, §9.2 |

**Two of these ten need an ADR before code, per MANIFEST Law 1: #1 (firm tenancy) and #6 (whether a meter
axis exists at all), because both constrain the schema and both are currently undecided.**

---

*Nothing in this document was derived from reading a competitor's source; every company whose business
model is discussed is closed-source commercial software. The architectural consequences stated here are
derived from QAYD's own schema as read by the sibling research folders, and from the general properties of
the business models — never from an assumption about how a competitor implemented theirs.*

# End of Document
