# LESSONS_FOR_QAYD.md — Where the Line Is

**Core banking treats ledger integrity as a solved, non-negotiable engineering problem in a way
accounting software does not. What should QAYD adopt from that posture, and what is genuinely
unnecessary for an SME accounting product?**

Version 1.0 · 2026-07-28 · Research artifact. Not a specification.

> This is the discriminating document. **QAYD is not a bank.** Importing bank-grade complexity
> wholesale would be a serious mistake, and roughly half of this document exists to say so with
> reasons. Every item is **ADOPT**, **ADAPT**, or **REJECT**, and every REJECT carries the argument
> rather than a shrug.

---

## 0. The finding, before the recommendations

Two sentences that should survive even if nothing else in this research is read:

> **QAYD's ledger core is already built to a standard core banking would recognise. Its proof layer is
> not.**

And a second, which was the surprise of the research:

> **Immutability is not a documented property of the incumbent core banking market.** Exactly one
> vendor studied publicly states its postings are immutable (Increase, a BaaS provider). Mambu
> documents a mutable-until-period-close GL with operator-deletable closures; Temenos publishes an API
> for *"updating, retrieving and deleting journal entries"*. QAYD's append-only ledger is **ahead of
> what most of this market publishes**, not catching up to it.

The consequence for prioritisation is direct. The work is not to make the ledger immutable — it
already is, at the database, in a way that cannot be bypassed. The work is to make the ledger's
integrity **provable and continuously proven**, and to close five specific gaps that are small
individually and consequential together.

---

## 1. The posture worth adopting, stated as five convictions

Not techniques — the *stance* that produces the techniques. This is the transferable part.

### Conviction 1 — Silent wrongness is worse than loud failure

TigerBeetle states it exactly: assertions *"downgrade catastrophic correctness bugs into liveness
bugs"*. A ledger that crashes is recoverable — the transaction rolls back, someone is paged, the data
is intact. A ledger that returns a plausible wrong balance is not recoverable, because nobody knows to
look.

**Everything else in this document follows from this one line.** It is the reason to prefer a
constraint over a check, a raise over a fallback, a `409` over a silent replay, and an alert over a
tolerance threshold.

QAYD already holds this instinct in at least three places: zero-tolerance balance comparison (not an
epsilon), a `LogicException` on a non-numeric money read, and `trg_no_ai_autopost` raising rather than
coercing. The lesson is to make it **explicit and general** rather than incidental.

### Conviction 2 — An invariant not enforced by the database is an aspiration

Banking pushes invariants as far down as they will go: TigerBeetle's balance limits are account flags
checked inside the engine; Vault's chaining rules are enforced by the ledger, not the caller.

QAYD's stated posture is already "PostgreSQL-first integrity" and it is largely honoured. The gap is
specific: **`chk_je_balanced` constrains the cached header totals to each other, not to the sum of the
lines.** The most important accounting invariant in the product is enforced only in PHP. A
`DEFERRABLE INITIALLY DEFERRED` constraint trigger closes it (**BR-03**).

### Conviction 3 — Proof beats prestige, and control totals beat hash chains

The realistic threats to an SME accounting ledger are a projection bug, a partially-applied
transaction, a double-post under retry, and a corrupt rollup. **Every one of them is caught by control
totals and re-derivation. None of them is caught better by a hash chain.**

Yet the hash chain is the item with a name, and S4+A is priced at 21 points against S2-14's 3. The
sequencing risk is real and this research's clearest scheduling recommendation is: **do not let S2-14
wait for S4+A.**

And when the chain does land, it must land **with an external anchor**. A hash chain in a table the
attacker can write to is tamper-evident only against an attacker who does not know it exists. Without
an anchor it is half a feature that reads like a whole one.

### Conviction 4 — Overrides and bypasses are data, never branches

Vault's `advice` and `override_all_restrictions` are *fields on the posting instruction*. "Which
postings skipped the balance check" is a `WHERE` clause.

QAYD has no override today, which is the ideal state and will not last. The rule to hold at the moment
one is requested: a nullable column with a reason and an actor, never a parameter, never a config flag.

### Conviction 5 — Say what you do not know

The most useful sections of this research are the `[UNKNOWN]` ones. Applied inward: the
`TECH_DEBT.md` register, with seventeen items each carrying the reason it was deferred and the story
that resolves it, is the same discipline and is a genuine asset. Preserve it. The temptation at launch
will be to prune it for appearances; that would destroy the most useful document in the repository.

---

## 2. ADOPT — with the reason, in value order

### A1 · Write an audit row inside the posting transaction — **the single highest-priority item**

TD-16 records that posting calls neither `AuditLogger` nor writes a history snapshot. A post is
currently traceable only through `posted_by`/`posted_at` and its own ledger rows.

**Why it matters more than it looks.** Coverage is inverted relative to consequence: draft edits are
reversible and low-stakes, posting is irreversible and is the moment money enters the books. And
because the hash chain is planned to live in `audit_logs`, **an unwritten audit row means the chain
will not cover posting at all** — the integrity mechanism would protect the trivia and not the ledger.

In a bank this would be a day-one finding. → **BR-01**

### A2 · Revoke `UPDATE`/`DELETE` on `ledger_entries`

`audit_logs` revokes the privileges *and* has a trigger. `ledger_entries` has only the trigger, and
additionally defines RLS policies for verbs that can never succeed. There is no live vulnerability —
the trigger always fires — but **the ledger is the more important table and is currently defended
less than the audit log**, which is an inconsistency that should not survive review.

Banking's posture is that the mutating verb *does not exist* for the application, at every layer.
→ **BR-02**

### A3 · Enforce Σ(debits) = Σ(credits) per entry at the database

The service re-derives from lines with zero tolerance in both currencies — correct, and better than
most systems. But the *database* enforces only that two cached numbers equal each other. A bug writing
consistent-but-wrong totals passes every constraint in the schema. → **BR-03**

### A4 · Control totals and re-derivation, ahead of the hash chain

S2-14 already specifies the right scope. The lesson is one of **sequencing and framing**: run the
trial balance as an *assertion on a schedule* rather than a report a human opens. It has always been a
control total; accountants merely also used it as a report. → **BR-05**

### A5 · Complete the idempotency design with the three refinements nobody documents

S2-13's shape is right and already ahead of four of the five vendors studied. The additions are cheap
and each closes a real hole:

- return the **original entry id** on a `409`, and name the diverging fields (Increase, TigerBeetle)
- **do not memoise validation failures** — a retried `400` must re-validate (Mambu)
- **a business failure terminates that key** — the retry is a new economic attempt and needs a new key
  (TigerBeetle's `id_already_failed`)

The last is the counter-intuitive one, and most implementations derived from the standard HTTP pattern
get it wrong. → **BR-06**

### A6 · Bound the entry date, and declare the accounting timezone

Vault bounds backdating and future-dating **in the type** at ±90 days. QAYD's period locks (S2-07)
handle the closed-period case but not an out-of-range date inside an open period, and do not exist
yet. A `CHECK` is cheap and independent.

Separately: Column anchors its accounting date to a **named timezone**. QAYD's `journal_date` has no
declared anchor, and a Kuwait company closing at 23:00 AST is doing something a UTC server will resolve
into the next day. → **BR-04**

### A7 · Seeded property tests over the posting engine

The highest-leverage testing idea in the research and roughly a week of work. Generate thousands of
random-but-valid entry shapes from a recorded seed, post them, assert the invariant set. A failure is a
`(seed)` pair replayable on a laptop.

This is the small, portable core of TigerBeetle's VOPR — not the simulator, just the determinism.
→ **BR-11**

### A8 · Generalise assertions along the posting path

QAYD already does this once (the non-numeric money `LogicException`). Generalise: assert the projected
row count equals the line count; assert the signed amount reconstructs from gross; assert the allocated
number was unallocated. Each is one line and each converts a silent corruption into a rollback.
→ **BR-10**

### A9 · Anchor a signed digest at period close — when the chain lands

Not before the chain, and not after it. The economical form: the close is already a low-frequency,
human-attested event; publishing a signed digest of the ledger head then costs almost nothing and
yields *"the books for July cannot be altered without detection"*.

That sentence has commercial value to an accountant **and** is a claim most of the incumbent core
banking market cannot make in public. → **BR-09**

---

## 3. ADAPT — right idea, wrong dose

### D1 · Balance coordinates → keep the *gross* insight, drop the *phase* dimension

Vault keys balances by `(address, asset, denomination, phase)` and stores `(credit, debit, net)`.

**Adapt:** QAYD already stores gross and net on every ledger row. The thing to protect is that the
S2-09 rollup carries **all three**, not just the net — otherwise the corruption-detection property is
lost exactly where it is most useful (**BR-07**).

**Do not adapt:** the phase dimension. See R2.

### D2 · Tri-temporal dating → two clocks, constrained, not three

Vault carries three clocks; Temenos four. QAYD has two (`journal_date` DATE, `posted_at` TIMESTAMPTZ)
and they are the two that matter — the accounting date and the immutable system time.

**The third clock is not earned yet.** A booking date is only meaningful when it can legitimately
diverge from *both* value and insertion — which happens in banking because a statement cycle is a real
external artefact with its own timing. QAYD has no such artefact.

**Adapt** the constraints instead: bound the range (**BR-04**), never let a client set the system
clock, and make every report state which clock it means. Revisit only if bank-statement reconciliation
introduces a genuine third date.

### D3 · Pre-posting validation → a dry-run endpoint

Vault's `pre_posting_hook` sees the *proposed* postings with the same data shape as the post-commit
view.

**Adapt** the cheap half: an endpoint that runs full validation and reports what *would* happen without
posting. For an AI that drafts entries, that is the difference between iterating safely and iterating
in production. It composes naturally with S2+A's aggregated `violations[]`.

**Do not adapt** the execution environment (see R4).

### D4 · Structured rejections → codes plus a review outcome

Vault's rejection enumeration is closed, machine-readable, and includes `REVIEW_DEBITS`/`REVIEW_CREDITS`
— *"allowed but must be looked at"* as a first-class outcome, plus `CLIENT_CUSTOM_REASON` as an
extensibility escape hatch.

**Adapt** all three properties into S2+A's `violations[]`: stable codes from a closed set, the field
each concerns, and a **review-not-reject severity**. The review outcome is precisely the state an
AI-drafted entry occupies, and it is instructive that a bank core needed the same third option.

### D5 · Two-phase pessimism → make "approved" mean something

TigerBeetle validates pessimistically at reservation so that resolution *cannot* fail.

**Adapt** to approval workflow: if an entry is approved today and posted tomorrow, everything that
could make posting fail should be checked at approval, with the post re-checking only what can
genuinely have changed. Otherwise "approved" is a label, not a guarantee, and the failure lands when
the approver has left.

### D6 · Batching → know where contention will first appear, do not pre-optimise

TigerBeetle's answer to the hot-account problem is batching, with a published ceiling of ~1,000 tx/s on
any single hot row under network-held locks.

**QAYD does not have this problem today** — ledger writes are independent `INSERT`s and the header lock
is per-entry. **The S2-09 rollup introduces it**, converting every posting touching the VAT control
account in a period into an `UPDATE` of one shared row.

**Adapt** to a measurement obligation, not a design change: build the rollup (it is right for reads),
know the grain that will contend, measure before it hurts, and know the escape route
(insert-deltas-and-fold rather than update-in-place). Cross-reference `knowledge/05_FUTURE_ARCHITECTURE.md`.

### D7 · Event history → a listable, retained record, not just a broadcast

Every vendor recovers from missed events via a **polling API**, not a replay: Column's `/api/events`,
Increase's List Events with 30-day retention. The webhook is the optimisation; the queryable history is
the correctness backstop.

**Adapt:** S2-13 broadcasts over Reverb. Add a retained, listable event history so a consumer that was
down can reconcile — which also makes the outbox useful for debugging rather than only for delivery.
→ **BR-12**

---

## 4. REJECT — and why

These are all good engineering. Adopting them would be a mistake, and the reasons are specific.

### R1 · A purpose-built ledger engine beside PostgreSQL

**What it buys:** the hot path stops paying for a metadata lookup; contention collapses.

**What it costs QAYD:** two stores to keep consistent, a distributed transaction or saga on the single
most correctness-critical operation in the product, two backup-and-restore stories, two RLS stories,
two tenancy models — and **`ledger_entries` losing the ability to join `accounts`, which is the basis
of every report QAYD sells.**

**The decisive argument:** QAYD's posting volume is orders of magnitude below the crossover point, and
the specialised store has no concept of tenancy, which is QAYD's hardest-won property. TigerBeetle is
explicit that it *"assumes a trusted environment and does not provide permission systems"* — QAYD would
have to rebuild RLS in application code, which is the exact inversion of its architecture.

**Adopt the invariants inside PostgreSQL. Do not adopt the topology.** This research strengthens the
PostgreSQL-first posture rather than weakening it.

### R2 · A general pending/authorisation phase model

**What it buys:** available-vs-ledger balance without a status filter; partial settlement for free.

**What it costs QAYD:** every balance query, report, rollup, export and API response must specify a
phase, forever.

**The decisive argument:** an SME accounting ledger has **no authorisation concept**. The nearest
analogues — an unreconciled bank transaction, a purchase commitment — are narrow, specific features
that deserve narrow, specific models. Building a general dimension to serve a feature that does not
exist is the definition of building the future, which MANIFEST Law 2 forbids.

**Keep only the warning:** if such a feature is ever built, build it as a value, not as
`status = 'pending'` filtered in every query.

### R3 · Bank latency budgets

Nothing in SME accounting has an externally-imposed deadline. A post taking 200 ms rather than 20 ms is
invisible. Engineering for the harder number costs architectural options — batching APIs,
denormalisation, caching layers — each a correctness liability paid for an imperceptible benefit.

**Adopt the benchmark honesty instead** (*"every test request passes through the same public APIs …
as a live, production transaction"*), which is free and improves every future measurement.

### R4 · Programmable product contracts

Vault's Smart Contracts are genuinely impressive: hooks, a three-level parameter hierarchy, a
conversion hook for versioning, a simulation harness.

**The cost is the surface**: a sandboxed execution environment, a versioning and migration story, a
per-tenant security boundary around executing customer code, and a support burden when a customer's
contract is wrong. QAYD's products are invoices and bills, not 40-year mortgages.

**Take two ideas for days rather than years:** validation on the proposed posting with the same data
shape as the committed view (D3), and a closed machine-readable rejection enumeration (D4). The
execution environment is `knowledge/07_QAYD_INNOVATION.md` territory, not Sprint 2.

### R5 · Cluster-assigned nanosecond timestamps

`TIMESTAMPTZ` plus a monotonic `BIGINT IDENTITY` on a single primary already gives a total order. QAYD
is not a distributed cluster.

**Take the principle** — the system clock is assigned by the system and never accepted from a client —
which QAYD already honours and should hold in review.

### R6 · Deterministic whole-system simulation

Requires the entire system to be deterministic: no ambient clock, no ambient randomness, no real I/O.
Laravel forecloses it.

**Take the reproducible-seed property test** (A7), which is the valuable 5% at 1% of the cost.

### R7 · Static allocation and a literal no-technical-debt doctrine

Static allocation is meaningless in PHP. And *"we do it right the first time"*, applied literally to a
pre-launch product, is a velocity catastrophe — QAYD's `TECH_DEBT.md` is a sign of health, not of
sloppiness.

**Take two free rules:** assertions in production (A8), and bound every loop and batch — an unbounded
`whereIn` or an unpaginated export is the same class of failure the doctrine exists to prevent.

### R8 · A nightly end-of-day batch that *produces* state

Banking runs one because interest accrual, fee application and regulatory extracts genuinely must
happen daily across millions of accounts. QAYD has none of those. A nightly window is a place for
failures to hide — a job that failed at 02:00 and was noticed at 09:00, with the day's work built on
wrong numbers.

**Note the inversion, which is the whole lesson:** banking batches to *produce* state; QAYD should
batch only to *verify* it. The one nightly job QAYD should run is S2-14.

Two footnotes worth carrying: even Vault, a real-time cloud-native core, still consolidates end-of-day
(90 minutes, ~20,676 TPS). And Column, which posts in real time, still cuts a daily report *"after
midnight in your configured reporting time zone"*. **Real-time posting does not abolish the daily
boundary; it relocates it from the posting engine to the reporting layer** — which is exactly where
QAYD should keep it.

### R9 · Multi-book accounting as a first-class dimension on every entry

Temenos carries an `accountingCompany` on the journal entry and types entries `STMT | CATEG | SPEC`.

**Reject for now.** QAYD's tenancy is `company_id` with RLS, which already partitions the books.
Multi-book *within* a tenant (statutory vs management vs tax basis) is a real accounting requirement
and a real future feature — but it is a **product decision with an ADR attached**, not something to
absorb from a competitor's schema. Recorded so that when it arrives, Temenos's shape is known:
the dimension belongs **on the entry**, not as a filter applied afterwards.

---

## 5. The line, stated once

QAYD should adopt core banking's **posture** and almost none of its **machinery**.

**The posture:** invariants at the lowest enforceable layer; loud failure over silent wrongness;
overrides as data; proof as a continuous assertion rather than an annual event; and honesty about what
is not known.

**The machinery** — specialised stores, phase dimensions, programmable contracts, distributed clocks,
whole-system simulation, batch windows — exists to solve problems QAYD does not have, at costs QAYD
cannot justify, and would degrade the properties QAYD has already earned (a single joinable database,
RLS tenancy, one write path, no dual-write).

**The five things that actually matter**, in order:

1. **Audit the posting** (BR-01) — the most consequential transition is currently the least evidenced
2. **Revoke the verbs on `ledger_entries`** (BR-02) — defend the ledger at least as well as the log
3. **Enforce the balance invariant at the database** (BR-03) — the cache constraint proves nothing
4. **Control totals and re-derivation** (BR-05) — worth more than the hash chain, at 1/7th the cost
5. **Bound the dates and declare the timezone** (BR-04) — a policy that belongs in a `CHECK`

Everything after those is improvement. Those five are the difference between a ledger that *is*
correct and a ledger that can be *shown* to be correct — and in this domain, the second is the product.
