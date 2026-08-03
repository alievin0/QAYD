# IMPLEMENTATION_RECOMMENDATIONS.md — Sequenced, Costed, Confidence-Rated

**BR-01 … BR-12.** What to do, in what order, at what cost, and why that order.

Version 1.0 · 2026-07-28 · Research artifact. **Recommendations, not authorisation.**
Effort in **Fibonacci story points**, calibrated against `docs/execution/SPRINT_02.md` (S2-13 = 3,
S2-14 = 3, S2-05 posting engine = 8, S4+A hash chain = 21).

> **Scope discipline.** Nothing here creates a new subsystem. Every item is a constraint, a column, a
> revocation, a job, or a test against machinery that already exists. Two items (BR-09, BR-12) touch
> planned-but-unbuilt work and are explicitly sequenced behind it. **No item requires an ADR**, and none
> contradicts a frozen decision — the one place this research disagrees with the current plan is a
> *sequencing* opinion (BR-05 before S4+A), not an architectural one.

---

## The sequencing argument, in one paragraph

QAYD's ledger is **already immutable, exact, single-write-path and RLS-isolated** — properties most of
the incumbent core banking market does not publish (`OVERVIEW.md` §1). What is missing is **proof**.
The cheapest, highest-value proof mechanisms are control totals and re-derivation (S2-14, 3 points),
not the hash chain (S4+A, 21 points). Meanwhile the most consequential state transition in the
product — posting — currently writes **no audit row at all** (TD-16), which means the planned hash
chain would not cover it even after S4+A lands. **Fix the coverage first, then the totals, then the
chain, then the anchor.** That ordering is the entire recommendation set.

---

## Priority tiers at a glance

| Tier | Items | Total effort | Rationale |
|---|---|---|---|
| **P0 — before the ledger carries real customer data** | BR-01, BR-02, BR-03, BR-04 | **11** | Each closes a gap that is cheap now and expensive after data exists |
| **P1 — Sprint 2 completion** | BR-05, BR-06, BR-07 | **11** | Makes S2-14 and S2-13 banking-grade rather than merely present |
| **P2 — hardening** | BR-08, BR-10, BR-11 | **13** | Buys the most confidence per point once the basics are in |
| **P3 — after the chain and the outbox exist** | BR-09, BR-12 | **13** | Genuinely dependent on prior work |

**Total: 48 points.** For comparison, S4+A alone is 21.

---

## P0 — Before the ledger carries real customer data

### BR-01 · Write an audit row inside the posting transaction

| | |
|---|---|
| **Effort** | **3** |
| **Confidence** | **Very high** |
| **Depends on** | nothing (`AuditLogger` exists from S1-16) |
| **Resolves** | `TECH_DEBT.md` **TD-16** (partially — the `journal_entry_history` snapshot is separate) |
| **Sources** | `ANTI_PATTERNS.md` BA-08 · `LESSONS_FOR_QAYD.md` A1 |

**What.** Call `AuditLogger::record` from inside the posting transaction in
`JournalEntryPostingService::post`, with `category = 'data_mutation'`, `action =
'journal_entry.posted'`, `entity_type = 'journal_entries'`, the entry id, the actor, the request id,
and a `new_values` payload carrying the journal number, both currency totals, the entry date, the
fiscal year and the projected line count.

**Why it is first.** Three compounding reasons:

1. **Audit coverage is currently inverted relative to consequence.** Draft edits are reversible and
   low-stakes; posting is irreversible and is the moment money enters the books. TD-12 already notes
   draft mutations are unlogged; TD-16 notes posting is too. Posting is the one that matters.
2. **The hash chain is planned to live in `audit_logs`.** An unwritten audit row means **the chain
   would not cover posting** even after S4+A. The 21-point integrity feature would protect the trivia
   and not the ledger. This is the strongest single argument in this document.
3. It is 3 points against machinery that already exists.

**Benefits.** Complete traceability of the ledger's only irreversible transition; makes the future
chain meaningful; satisfies the auditable-application-control framing of `ARCHITECTURE.md` §10.9.

**Tradeoffs / risks.** One extra insert per post (negligible). The real risk is the *opposite* of
adding it: an `audit_logs` write that fails must roll the posting back — which is correct and is
exactly why it belongs **inside** the transaction, not after it.

**Scalability.** One row per post. `audit_logs` partitioning is already a known future need (TD-06).

**Business impact.** High. This is the difference between "we have an audit trail" and "we have an
audit trail of the thing that matters".

**Acceptance.** Posting an entry writes exactly one `audit_logs` row with the entry id and actor; a
forced `audit_logs` failure rolls the whole post back and projects no ledger rows; the row is visible
only within the posting company under RLS.

---

### BR-02 · Revoke `UPDATE`/`DELETE` on `ledger_entries`, and drop the dead RLS policies

| | |
|---|---|
| **Effort** | **1** |
| **Confidence** | **Very high** |
| **Depends on** | nothing |
| **Sources** | `ARCHITECTURE.md` §11 · `BEST_PRACTICES.md` BP-01 · `LESSONS_FOR_QAYD.md` A2 |

**What.** Mirror the `audit_logs` treatment onto `ledger_entries`: `REVOKE UPDATE, DELETE ON
ledger_entries FROM PUBLIC` and from the runtime role, `GRANT INSERT, SELECT` only, and drop
`ledger_entries_tenant_update` and `ledger_entries_tenant_delete` — policies for verbs that can never
succeed.

**Why.** `audit_logs` is defended at **two** layers (privilege + trigger); `ledger_entries` at **one**.
The ledger is the more important table. There is no live vulnerability — `trg_ledger_entries_append_only`
always fires — but defending the ledger *less* than the log is an inconsistency that should not survive
review, and the dead policies actively mislead a reader into thinking an update path exists.

Banking's posture is that the mutating verb **does not exist for the application**, at every layer.

**Tradeoffs.** None material. If a future migration genuinely must rewrite ledger rows, it does so as
the owner with the trigger explicitly disabled — a deliberate, visible, reviewable act, which is the
correct shape for that operation anyway.

**Risk.** A latent code path that performs an `UPDATE` on `ledger_entries` would now fail earlier and
differently. Since the trigger already rejects it, any such path is already broken.

**Acceptance.** The runtime role holds only `INSERT, SELECT`; an attempted `UPDATE` fails on privilege
before reaching the trigger; `pg_policies` shows only the boundary, select and insert policies.

---

### BR-03 · Enforce Σ(debits) = Σ(credits) per entry at the database

| | |
|---|---|
| **Effort** | **5** |
| **Confidence** | **High** |
| **Depends on** | nothing |
| **Sources** | `ANTI_PATTERNS.md` BA-10 · `ARCHITECTURE.md` §11 · `LESSONS_FOR_QAYD.md` A3 |

**What.** A `CREATE CONSTRAINT TRIGGER … DEFERRABLE INITIALLY DEFERRED` on `journal_lines`, firing at
**commit**, that raises unless, for the affected entry, `SUM(debit) = SUM(credit)` **and**
`SUM(base_debit) = SUM(base_credit)` — for entries in a posted state. `[DOCS]`
postgresql.org/docs/current/sql-createtrigger.html.

**Why.** `chk_je_balanced` constrains the **cached header totals to each other**, not to the lines. A
bug that writes consistent-but-wrong totals satisfies every constraint in the schema. The posting
service does re-derive from lines with zero tolerance — correct, and better than most systems — but
that is an *application* guarantee for the product's single most important accounting invariant.

Deferral is what makes this possible at all: lines are inserted one at a time and an immediate check
would fail on the first one. PostgreSQL has no `CREATE ASSERTION`; a deferred constraint trigger is the
supported substitute.

**Tradeoffs.** One aggregate query per entry at commit — measurable but small, and it runs once per
entry rather than once per line. Must be scoped to posted entries so drafts may be transiently
unbalanced.

**Risks.** Getting the scoping wrong would block legitimate draft editing. Mitigated by the existing
`JournalDraftLifecycleTest` and `PostingEngineTest` coverage.

**Maintainability.** Slightly harder to reason about than a plain `CHECK`; warrants a comment block of
the same quality as the existing migrations, which set a high bar.

**Acceptance.** An entry whose header totals are internally consistent but disagree with its lines is
rejected at commit; a multi-line draft can be built one line at a time without tripping the check; the
error is a clean, mapped 422 rather than a raw 500.

---

### BR-04 · Bound the entry date and declare the accounting timezone

| | |
|---|---|
| **Effort** | **2** |
| **Confidence** | **High** (bound) / **Medium** (timezone — a product decision) |
| **Depends on** | nothing |
| **Sources** | `ARCHITECTURE.md` §6 · `ANTI_PATTERNS.md` BA-05 · `BEST_PRACTICES.md` BP-06, BP-08 |

**What.** Two things:

1. A `CHECK` bounding `journal_entries.journal_date` to a sane range relative to company creation and
   a forward horizon — Vault's precedent is `[1970-01-01, now + 90 days]` in the *type* `[CODE]`. A
   fixed lower bound plus a configurable forward horizon is the pragmatic form.
2. **Declare, per company, the timezone the accounting date is anchored in**, and use it consistently
   when deriving a date from an instant. Column anchors `effective_on` to a named timezone (Pacific)
   `[DOCS]`; QAYD has no declared anchor, and a Kuwait company closing at 23:00 AST is doing something
   a UTC server resolves into the next day.

**Why.** Period locks (S2-07) handle the *closed-period* case but do not exist yet, and never handle a
wildly out-of-range date inside an open period. Backdating without a bound is an unbounded, silent
recomputation obligation — so in practice it is not honoured and derived state goes quietly stale
(`ANTI_PATTERNS.md` BA-05). The bound is independent of the period machinery and can land now.

**Tradeoffs.** A bound will eventually block a legitimate case. That is the intended behaviour: it
becomes a visible, deliberate exception rather than a silent recompute obligation. Make the horizon
configurable per company rather than a hardcoded constant.

**Business impact.** Prevents a class of data-entry error (`2062` for `2026`) that is otherwise
discovered at year end.

**Acceptance.** An entry dated far outside the horizon is rejected with a mapped 422; the accounting
timezone is stored on the company and used by every date-derivation path; period-close boundaries agree
with it.

---

## P1 — Sprint 2 completion

### BR-05 · Make S2-14 a control-total suite, and do not let it wait for the hash chain

| | |
|---|---|
| **Effort** | **5** (on top of S2-14's own 3) |
| **Confidence** | **Very high** |
| **Depends on** | S2-08 (per the existing plan), BR-01 |
| **Sources** | `ARCHITECTURE.md` §10.4, §10.7 · `BEST_PRACTICES.md` BP-19, BP-20 · `LESSONS_FOR_QAYD.md` A4 |

**What.** S2-14 as specified is the *re-derivation* half (rebuild `ledger_entries` from posted
`journal_lines`, assert byte-identical balances). Add the **control-total** half as a set of named,
individually-reported assertions:

| Assertion | Catches |
|---|---|
| `SUM(signed_base_amount) = 0` per company | any unbalanced posting that reached the ledger |
| `SUM(signed_base_amount) = 0` per journal entry | a partially-projected entry |
| `COUNT(ledger_entries) = COUNT(posted journal_lines)` per company | a dropped or duplicated projection |
| `SUM(debit) = SUM(credit)` per period | classic trial-balance proof |
| no gaps in `journal_number` per `(company, fiscal_year, type)` | a lost or deleted entry |
| maintained rollup = re-derived fold (once S2-09 lands) | a corrupt cache |
| every posted entry has a corresponding `audit_logs` row (after BR-01) | a bypassed write path |

**Why.** The realistic failure modes of this ledger are a projection bug, a partial post, a double-post
under retry, and a corrupt rollup. **Control totals catch all four. A hash chain catches none of them
better.** Yet S4+A is priced at 21 and S2-14 at 3.

The framing matters as much as the code: **the trial balance has always been a control total** —
accountants merely also used it as a report. Running it as a scheduled assertion that alerts, rather
than a screen a human opens, is the highest-value low-cost idea in this research. Independently
confirmed by Square (*"each cent lost is matched with a cent gained"*, enforced at the database
`[DOCS]`), TigerBeetle (global `Σ debits_posted == Σ credits_posted` `[CODE]`), and Modern Treasury
(*"any imbalance indicates funds were created or destroyed erroneously"* `[COMMUNITY]`).

**Tradeoffs.** Full-scan cost, mitigated by running per company and off-peak. Each assertion must
report **individually** — "integrity check failed" is not actionable; "company 42, entry 8817, ledger
projected 3 rows for 4 lines" is.

**Risks.** An assertion that produces false positives will be muted, and a muted assertion is worse
than none. This is why exact arithmetic (already in place) is a hard prerequisite: floating-point
non-associativity alone would generate noise (`ANTI_PATTERNS.md` BA-11).

**Scalability.** These are the first queries that will need the ledger partitioned by period. Uber's
insight applies: **the seal/close boundary and the archival boundary should be the same line**
(`ARCHITECTURE.md` §10.7).

**Acceptance.** Each assertion is separately named, separately reported and separately alertable; a
deliberately seeded drift of each kind is detected and identifies the affected company and entry; the
job re-establishes tenant context per company; it runs in CI on a seeded dataset.

---

### BR-06 · Finish S2-13's idempotency with the three refinements nobody documents

| | |
|---|---|
| **Effort** | **3** (on top of S2-13's 3) |
| **Confidence** | **High** |
| **Depends on** | S2-13 |
| **Sources** | `ARCHITECTURE.md` §4.3, §9 · `BEST_PRACTICES.md` BP-10…BP-12 · `LESSONS_FOR_QAYD.md` A5 |

**What.** S2-13's shape — `Idempotency-Key` scoped `(company_id, endpoint, key)`, replay on match,
`409 idempotency_key_conflict` on a differing body — is correct and already **ahead of four of the five
vendors studied**, none of whom document the conflict case. Add:

1. **Return the original entry id on a `409`**, and name the diverging fields. Increase returns the
   `resource_id` of the original object `[DOCS]`; TigerBeetle goes further with
   `exists_with_different_amount`, `exists_with_different_flags` and so on `[DOCS]`. This turns a dead
   end into a recoverable situation — especially valuable when the caller is an AI drafting entries.
2. **Do not memoise validation failures.** A retried `400` must re-validate rather than replay a stale
   rejection. This is Mambu's published rule and nobody else states it `[DOCS]`.
3. **A business failure terminates that key.** *"Retrying with the same ID will always fail; use a new
   idempotency ID"* `[DOCS]` — TigerBeetle's `id_already_failed`. A second attempt after a business
   rejection is a **new economic attempt** and must carry a new key, or the system cannot distinguish
   "the same request again" from "a fresh request reusing an id". This is the counter-intuitive one
   and most implementations get it wrong.

Plus the mechanics: a **request fingerprint** over the canonical body; the idempotency row written **in
the same transaction as the effect**; an `in_progress` state with a resolution path (`409
request_in_flight` plus a reclaim TTL); and an explicitly chosen, **documented** retention window —
published windows range from 6 hours (Mambu) to 30 days (Column), a 120× spread with no industry
convention, so QAYD must choose rather than inherit.

**Tradeoffs.** One extra table and one extra write per money-moving request. Fingerprinting requires a
stable canonical serialisation — a real, small design task with a real failure mode if it drifts.

**Business impact.** High and rising: every retry-capable client, every queue, and every AI agent
multiplies the duplicate-post risk this eliminates.

**Acceptance.** Same key + same body replays the original response; same key + different body returns
`409` naming the original entry id and the diverging fields; a retried validation failure re-validates;
a business-rejected key is terminal; a crashed in-flight key is reclaimable after its TTL.

---

### BR-07 · Carry gross **and** net in the S2-09 rollup, and know where it will contend

| | |
|---|---|
| **Effort** | **3** (design constraint on S2-09, not separate work) |
| **Confidence** | **High** |
| **Depends on** | S2-09 |
| **Sources** | `ARCHITECTURE.md` §7, §8.3 · `BEST_PRACTICES.md` BP-02 · `LESSONS_FOR_QAYD.md` D1, D6 |

**What.** Two constraints on `account_period_balances` as it is built:

1. **Store gross debit, gross credit *and* net** — not the net alone. Vault's `Balance` is a
   `(credit, debit, net)` triple; TigerBeetle keeps four counters; Modern Treasury keeps four
   `[CODE]`/`[DOCS]`. Netting is lossy: two compensating errors that cancel leave the net right and the
   gross wrong, so gross totals detect a class of corruption the net cannot — and BR-05's comparison is
   correspondingly stronger.
2. **Record, in the migration's own comment block, where contention will first appear.** The rollup
   converts every posting touching the VAT control account in a period into an `UPDATE` of **one shared
   row** — reintroducing exactly the serialisation the append-only design avoids
   (`ARCHITECTURE.md` §8.3). This is not a reason to abandon the rollup; it is the right call for
   reads. It is a reason to name the grain, measure it, and record the escape route
   (insert-deltas-and-fold rather than update-in-place).

**Why.** Both are free at design time and expensive afterwards — adding gross columns later means
backfilling from a table that is by then large.

**Risks.** Wider rows and a slightly larger trigger. Negligible against the detection gain.

**Acceptance.** The rollup carries all three values with a `CHECK` relating them; BR-05 compares all
three; the contention grain and escape route are documented; a concurrency test posts N entries to one
hot account and records the observed serialisation.

---

## P2 — Hardening

### BR-08 · Activate the hash chain **with** its verification, on the right table

| | |
|---|---|
| **Effort** | **8** (against S4+A's 21 — this is the narrowed version) |
| **Confidence** | **Medium-high** |
| **Depends on** | BR-01, BR-05 |
| **Sources** | `ARCHITECTURE.md` §10.1 · `ANTI_PATTERNS.md` BA-09 |

**What.** A `BEFORE INSERT` trigger on `audit_logs` computing `hash = H(prev_hash ‖ canonical(row))`
per company, filling the dormant `hash`/`prev_hash` columns (TD-06), plus chain verification added to
BR-05's assertion suite.

**Two design decisions this research settles:**

1. **The canonical payload must be persisted or fully reconstructible, and its definition frozen.** If
   the serialisation ever changes, every historical hash becomes unverifiable and the chain is worth
   nothing. This is the single largest implementation risk and is why the backlog's own S4+A note says
   "persisted canonical payload".
2. **`audit_logs` is the right table — but only after BR-01.** Chaining the audit log is correct
   *provided the audit log covers posting*. Without BR-01 the chain protects everything except the
   ledger. Do not consider chaining `ledger_entries` separately: it is append-only, `SUM`-verified and
   re-derivable, so the audit chain plus control totals covers it.

**Why only 8 and not 21.** S4+A bundles partitioning, the shadow-table row-diff capture, the outbox
write path and signed anchors. This recommendation is the chain **and its verification** alone; the
others are independently valuable and independently schedulable.

**Tradeoffs.** A serialisation on the write path of every audited event; a per-company serialisation
point on `audit_logs` inserts (the chain is inherently ordered), which is a genuine contention
consideration at volume.

**Risks.** Shipping the chain **without** BR-09's anchor produces tamper-evidence only against an
attacker unaware it exists — half a feature that reads like a whole one. **Do not ship BR-08 without
committing to BR-09.**

**Acceptance.** Every `audit_logs` row carries a chain-valid hash; a manually tampered row is detected
by the verification pass; the canonical payload definition is documented and covered by a test that
fails if it changes.

---

### BR-10 · Generalise assertions along the posting path

| | |
|---|---|
| **Effort** | **2** |
| **Confidence** | **High** |
| **Depends on** | nothing |
| **Sources** | `BEST_PRACTICES.md` BP-23 · `LESSONS_FOR_QAYD.md` A8 |

**What.** Add explicit invariant assertions inside `JournalEntryPostingService::post`, each raising
(and therefore rolling the transaction back) rather than proceeding:

- the number of projected `ledger_entries` rows equals the number of lines
- each `signed_base_amount` reconstructs exactly from its gross pair
- the allocated `journal_number` was not already present for the company
- the resolved fiscal period covers `journal_date`

**Why.** TigerBeetle's published rationale is the strongest sentence in the corpus: assertions
*"downgrade catastrophic correctness bugs into liveness bugs"* `[CODE]`. **A system that crashes is
recoverable; a system that silently computes the wrong balance is not.** In a ledger, a rollback is
always better than a plausible wrong number.

QAYD already does this in exactly one place — the `LogicException` on a non-numeric money read. This
generalises an existing instinct rather than importing a foreign one.

**Tradeoffs.** A few cheap checks per post. The named risk is over-assertion producing spurious
failures in legitimate edge cases; keep each assertion to a property that is *definitionally* true.

**Acceptance.** Each assertion has a test that deliberately violates it and observes a rollback with no
partial projection.

---

### BR-11 · Seeded property tests over the posting engine

| | |
|---|---|
| **Effort** | **3** |
| **Confidence** | **High** |
| **Depends on** | BR-05 (shares the assertion set) |
| **Sources** | `BEST_PRACTICES.md` BP-24 · `LESSONS_FOR_QAYD.md` A7 · `ANTI_PATTERNS.md` BB-06 |

**What.** A test that, from a **recorded seed**, generates thousands of random-but-valid journal-entry
shapes — varying line counts, account mixes, currencies, dates within the open period, and concurrent
posting order — posts them through the real path, and asserts BR-05's invariant set. A failure reports
the seed.

**Why.** Example-based tests check the cases you thought of; property tests check the invariant across
cases you did not. Determinism is what makes a property-test failure *actionable* rather than a flaky
ticket — this is the small, portable core of TigerBeetle's VOPR (*"deterministic based on a seed number
and the Git commit, we can perfectly reproduce any bugs"* `[CODE]`), without the simulator.

The full VOPR is correctly rejected (`ANTI_PATTERNS.md` BB-06); the seed is 1% of the cost and most of
the value.

**Tradeoffs.** Slower CI — mitigate by running a small iteration count per commit and a large one
nightly. Generator bias is the real limitation: it only explores shapes it knows how to make, so the
generator itself needs review.

**Business impact.** This is the item most likely to find a bug that no other item on this list would
have found.

**Acceptance.** The suite runs from a fixed seed in CI and a random seed nightly; a deliberately
introduced projection bug is caught; a reported seed reproduces the failure locally.

---

## P3 — After the chain and the outbox exist

### BR-09 · Anchor a signed digest at period close

| | |
|---|---|
| **Effort** | **8** |
| **Confidence** | **Medium** (mechanism high; the storage/trust choice is a product decision) |
| **Depends on** | BR-08, S2-07 (fiscal periods) |
| **Sources** | `ARCHITECTURE.md` §10.2, §10.7 · `BEST_PRACTICES.md` BP-21 · `LESSONS_FOR_QAYD.md` A9 |

**What.** At period close, compute a digest over the period's ledger and audit-chain head — count,
control totals, chain head hash, and optionally a Merkle root — sign it, and publish it to an
append-only store in a **different trust domain**: an object store in a separate cloud account with a
locked immutability policy, a third-party timestamping service, or at minimum a signed digest included
on the close report and delivered to the customer's auditor.

**Why.** A hash chain inside a database the attacker can write to proves nothing against that attacker
— they recompute the tail (`ARCHITECTURE.md` §10.1). The anchor is *"the step that actually closes the
loop, and it is the one most systems skip"*; Azure SQL Ledger is the documented precedent, anchoring
digests to WORM storage with a locked immutability policy `[DOCS]`.

**Why period close specifically.** It is already a low-frequency, human-attested, irreversible event —
so the anchor costs almost nothing operationally. And Uber's structural insight applies: **the seal
boundary and the archival boundary should be the same line**, so this same boundary later becomes the
partitioning/archival boundary for a large ledger. Integrity and cost optimisation become one
mechanism `[COMMUNITY]`.

**Business impact — the highest of any item here.** It yields the sentence *"the books for July cannot
be altered without detection"*, which has direct commercial value to an accountant, feeds the
auditable-application-control framing of `ARCHITECTURE.md` §10.9, and is a claim **most of the
incumbent core banking market cannot make in public** (`OVERVIEW.md` §1). This belongs in
`knowledge/06_COMPETITIVE_ANALYSIS.md` as much as in the backlog.

**Tradeoffs / risks.** Key management for the signature is a real new responsibility. The external
store is a new dependency whose failure must not block a close — anchor **after** the close commits,
with a retry, and alert on a missed anchor.

**Acceptance.** Closing a period publishes a signed digest to the external store; the digest is
independently verifiable against a recomputation from the ledger; a tampered historical period fails
verification against its anchor; a failed anchor alerts without blocking the close.

---

### BR-12 · A listable, retained event history beside the broadcast

| | |
|---|---|
| **Effort** | **5** |
| **Confidence** | **Medium-high** |
| **Depends on** | S2-13, P-11 outbox |
| **Sources** | `ARCHITECTURE.md` §4.5 · `BEST_PRACTICES.md` BP-27 · `LESSONS_FOR_QAYD.md` D7 |

**What.** Retain drained outbox rows for a documented window and expose them as a cursor-paginated,
RLS-scoped list endpoint, so a consumer that missed a broadcast can reconcile.

**Why.** Every vendor that documents delivery recovers via a **polling API**, not a replay: Column's
`/api/events`, Increase's List Events with 30-day retention, Mambu's consumer-committed cursor `[DOCS]`.
Increase says it plainly — after ~72 hours and 8 attempts it stops, and *"polling is the way to recover
from extended outages"*. **The webhook is the optimisation; the queryable history is the correctness
backstop.** Every one of them documents at-least-once delivery and **none claims exactly-once**.

Secondary benefit: a retained outbox is the best debugging artefact the system will have for "what
happened at 03:14".

**Tradeoffs.** Storage for the retention window; a `deleted_at`-style sweep. Both bounded and
predictable.

**Risks.** The endpoint must be strictly RLS-scoped and must carry **signals, not payloads**
(`ANTI_PATTERNS.md` BA-13) — an event history that leaks amounts is a data-exposure surface, and one
that lets a consumer fold balances reintroduces drift.

**Acceptance.** Drained outbox rows are retained for the documented window and listable by cursor;
events carry ids and types, not financial content; the endpoint is RLS-scoped; a consumer that
reconnects after a simulated outage reconciles to the same state.

---

## Dependency graph

```
  BR-02 revoke verbs ─────────────────────────────────► (independent, 1 pt)
  BR-04 date bounds ──────────────────────────────────► (independent, 2 pts)
  BR-03 deferred balance trigger ─────────────────────► (independent, 5 pts)

  BR-01 audit on posting ──┬──► BR-05 control totals ──┬──► BR-11 property tests
       (unblocks the       │        (S2-14 + 5)        │
        chain's value)     │                           │
                           └──► BR-08 hash chain ──────┴──► BR-09 external anchor
                                (needs BR-01 to be                (needs S2-07)
                                 worth building)

  S2-09 rollup ──► BR-07 gross+net constraint
  S2-13 ─────────► BR-06 idempotency refinements
  P-11 outbox ───► BR-12 event history
  (independent) ─► BR-10 posting assertions
```

**The critical path is BR-01 → BR-05 → BR-08 → BR-09.** Everything else is parallelisable.

---

## What this research deliberately does **not** recommend

Recorded so the absences are visible and cannot be read as oversights. Full reasoning in
`LESSONS_FOR_QAYD.md` §4.

| Not recommended | Because |
|---|---|
| A specialised ledger engine beside PostgreSQL | Volume is orders of magnitude below the crossover; costs a distributed transaction on the most critical operation, and `ledger_entries` loses the join to `accounts` |
| A pending/`phase` balance dimension | No authorisation concept exists in SME accounting; taxes every query forever to serve a feature that does not exist |
| A third (booking) clock | Not earned — no external artefact makes it diverge from the other two |
| Programmable product contracts | Years of surface; take the dry-run and the rejection enumeration instead (days) |
| Bank latency budgets | No external deadline exists; costs architectural options for an imperceptible benefit |
| Deterministic whole-system simulation | Laravel forecloses it; BR-11 is the portable 5% |
| A nightly batch that *produces* state | Banking batches to produce, QAYD should batch only to verify (BR-05) |
| Multi-book accounting on the entry | A real future product decision needing its own ADR, not something to absorb from a competitor's schema |

---

## Open questions this research could not close

| Question | Status |
|---|---|
| Kuwait/GCC settlement and clearing specifics (KNET, Kuwait Clearing, CBK, AFAQ) | `[UNKNOWN]` — every primary source refused automated retrieval. A **real gap for a Kuwait-first product** |
| Exact Nacha ACH return windows | `[UNKNOWN]` — paywalled. Structural argument in `ARCHITECTURE.md` §6.4 does not depend on the figure |
| Whether the accounting-date timezone should be per-company or per-fiscal-year | Product decision (BR-04) |
| Idempotency retention window for QAYD | Must be chosen and documented — published windows span 6 h to 30 d with no convention (BR-06) |
| Which external store to anchor to | Product/ops decision (BR-09) |
| Temenos, Finastra and FIS ledger internals | `[UNKNOWN]` and likely to remain so. Not a blocker — no recommendation here depends on them |
