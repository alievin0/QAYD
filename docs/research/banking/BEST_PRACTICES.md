# BEST_PRACTICES.md — What Works, and the Mechanism by Which It Works

**Practices worth adopting from core banking, each with the reason it works rather than the fact that
it is done.**

Version 1.0 · 2026-07-28 · Research artifact.
Evidence grades: `[DOCS]` `[CODE]` `[COMMUNITY]` `[INFERENCE]` `[UNKNOWN]`

> A practice is only useful if you know *why* it works, because that is what tells you when it stops
> applying. Every entry below states the mechanism. Entries are marked **ADOPT** (QAYD should do this),
> **CONFIRM** (QAYD already does this — recorded so it is defended in future review), or **CONSIDER**
> (genuinely good, genuinely optional). The adopt/reject line is drawn properly in
> `LESSONS_FOR_QAYD.md`; this document is the menu.

---

## Part 1 — Ledger structure

### BP-01 · Make the ledger's write verb the only verb — **CONFIRM**

**Practice.** `UPDATE` and `DELETE` do not exist on the postings table, enforced at multiple layers:
a trigger that raises unconditionally, *and* revocation of the privilege from the runtime role.

**Mechanism.** Every downstream integrity technique — re-derivation, drift detection, hash chaining,
audit reconstruction — assumes the source cannot change. If postings are mutable, re-derivation
reproduces corruption and every check passes on corrupt data. Immutability is not one guarantee among
several; it is the *precondition* for all the others.

TigerBeetle states the accounting benefit precisely: immutable corrections preserve *"the original
error, when it took place, as well as any attempts to correct the record and when they took place"*
`[DOCS]` docs.tigerbeetle.com/coding/recipes/correcting-transfers/.

**QAYD.** Already done on `ledger_entries` via `trg_ledger_entries_append_only`. **Except** the
privilege layer: `audit_logs` revokes `UPDATE, DELETE` from the app role; `ledger_entries` does not.
Recommendation **BR-02**.

---

### BP-02 · Store gross *and* net, never net alone — **CONFIRM**

**Practice.** A balance carries the sum of debits, the sum of credits, *and* the net. Vault's `Balance`
is a `(credit, debit, net)` triple `[CODE]`; TigerBeetle's account carries four running totals
(`debits_pending`, `debits_posted`, `credits_pending`, `credits_posted`) `[DOCS]`.

**Mechanism.** Netting is lossy and the loss is silent. Two compensating errors that cancel leave the
net correct and the gross totals wrong — so gross totals detect a class of corruption a net cannot.
They also answer "how much ever flowed through here" without scanning postings, which is a real
reporting need (turnover, activity volume) that otherwise becomes a table scan.

**QAYD.** `ledger_entries` stores `debit_amount`, `credit_amount`, `base_debit_amount`,
`base_credit_amount` **and** `signed_base_amount`. Correct. The thing to protect: when the S2-09 rollup
is built, it must carry **all three** (gross debit, gross credit, net) and not collapse to the net —
otherwise the property is lost exactly where it would be most useful. Recommendation **BR-07**.

---

### BP-03 · Normalise the sign into the data, not the report — **CONFIRM**

**Practice.** The account's treasury side is carried on the posting and determines the balance's sign,
so no report re-derives it. Vault: `Tside` is on the instruction; `net = (credit − debit) × sign(tside)`
`[CODE]`.

**Mechanism.** Any convention re-derived in N places is wrong in at least one of them, and sign errors
in financial statements are both easy to introduce and hard to spot (the number looks plausible).
Deriving once, at write time, into a stored column makes every consumer trivially correct.

**QAYD.** `signed_base_amount` (`+base_debit − base_credit`) with a `CHECK` asserting the relationship.
This is the same idea and it is arguably better expressed, because the `CHECK` makes the derivation
un-violatable.

---

### BP-04 · Compose multi-leg entries atomically from a minimal primitive — **CONSIDER**

**Practice.** TigerBeetle's primitive is one debit and one credit; it deliberately does **not** support
multi-leg transfers `[DOCS]`. N-leg entries are composed from linked transfers that *"all succeed or
fail together"*, executed in order with each effect visible to the next `[DOCS]`.

**Mechanism.** A minimal primitive has a small verifiable state space. Atomicity is then a *composition*
property enforced in one place, rather than a property each entry shape must be checked for.

**QAYD.** The opposite convention — the multi-leg journal entry *is* the primitive — and that is
correct for accounting: an accountant thinks in entries, not in pairs. The transferable idea is
narrower and still valuable: **the atomic boundary should be explicit and named**, and QAYD's is the
journal entry. What QAYD lacks is banking's *batch* — an atomic unit spanning multiple entries
(Vault: *"atomically accepted or rejected"* `[CODE]`). Relevant when an importer or a period-close
routine must post many entries all-or-nothing. Not needed today; recorded for when it is.

---

### BP-05 · Make "money you cannot spend" a stored value, not a filter — **CONSIDER (narrowly)**

**Practice.** Column exposes four balance fields — `available`, `pending`, `holding`, `locked` — where
`locked` is money that *is* posted but *"cannot be withdrawn"* `[DOCS]`
docs.column.com/api/bank-account/bank-account-object/.

**Mechanism.** As BA-03 in `ANTI_PATTERNS.md`: a stored value cannot be forgotten; a filter can.

**QAYD.** Do **not** generalise this into a phase dimension (`ANTI_PATTERNS.md` BB-02). Apply it only
if and when a specific feature needs it — unreconciled bank transactions, or purchase commitments —
and then model that feature's own balance explicitly rather than adding a status filter to the general
ledger query.

---

## Part 2 — Time

### BP-06 · Separate the accounting date (a DATE) from the system time (an instant) — **CONFIRM**

**Practice.** Column: `effective_on` is a **date** anchored to 00:00 in a declared timezone; the
lifecycle timestamps (`initiated_at`, `submitted_at`, `settled_at`, `completed_at`) are **instants**
`[DOCS]`.

**Mechanism.** They are different kinds of thing. The accounting date is a calendar fact chosen by a
human and belongs to a period; the system time is a physical fact assigned by the machine and orders
events. Storing the accounting date as a timestamp forces a timezone decision into every comparison
and produces off-by-one-day period assignment at the boundary — the classic month-end bug.

**QAYD.** Already correct: `journal_date` / `entry_date` are `DATE`; `posted_at` is `TIMESTAMPTZ`.
**What is missing is the declared anchor** — Column names Pacific Time explicitly. QAYD should
document, per company, which timezone the accounting date is anchored in, because a Kuwait company
closing at 23:00 AST is doing something the server's UTC clock will otherwise resolve into the next
day. Recommendation **BR-04**.

---

### BP-07 · The system clock on a ledger row is assigned by the system — **CONFIRM**

**Practice.** TigerBeetle rejects any client-supplied timestamp with `timestamp_must_be_zero`; the
cluster assigns all timestamps, and they are *"unique, immutable and totally ordered"* `[DOCS]`
docs.tigerbeetle.com/coding/time/. Vault's `insertion_datetime` is likewise system-assigned `[CODE]`.

**Mechanism.** The insertion clock is the only one an attacker or a buggy client cannot choose. It is
what replay, audit ordering and "as at the time we knew it" queries depend on. The moment a client can
set it, it stops being evidence.

**QAYD.** `posted_at` is set by the server; `created_at` defaults to `now()`. Correct. The rule to hold
in review: **no API surface may ever accept a value for a system timestamp column**, even for
migration — migration gets its own explicitly-flagged path, exactly as TigerBeetle isolates it behind
an `imported` flag with strict monotonicity constraints `[DOCS]`.

---

### BP-08 · Bound backdating and future-dating in the schema — **ADOPT**

**Practice.** Vault constrains `value_datetime` and `booking_datetime` to
`[1970-01-01, now + 90 days]` **in the type**, not in a policy `[CODE]`.

**Mechanism.** Every backdated posting invalidates an unbounded amount of derived state. With no
bound, the recomputation obligation is unbounded, so in practice it is not honoured and the derived
state is quietly stale. A bound converts an unbounded silent obligation into a clean, immediate 422.

**QAYD.** Period locks (S2-07) handle the *closed-period* case. They do not handle a wildly
out-of-range date within an open period, and they do not exist yet. A `CHECK` on `journal_date` is
cheap and independent of the period machinery. Recommendation **BR-04**.

---

### BP-09 · Make queries declare which clock they mean — **CONSIDER**

**Practice.** Vault's `DateTimeView = VALUE_DATETIME | BOOKING_DATETIME` is a required argument when
fetching balances `[CODE]`.

**Mechanism.** "The balance as of 31 July" is ambiguous the moment more than one clock exists, and the
ambiguity is invisible — both answers are numbers. Forcing the caller to name the clock converts a
silent wrong answer into a compile-time or validation-time decision.

**QAYD.** With two clocks the ambiguity is already present. Every report should state, in its own
definition, whether it is *by entry date* or *as at posting time* — and the two must be distinguishable
in the API. Cheap; mostly a documentation and naming discipline rather than code.

---

## Part 3 — Idempotency and correction

### BP-10 · Two identifiers, not one — **ADOPT**

**Practice.** Vault separates `client_batch_id` (the caller's handle on a request) from
`client_transaction_id` (the identity of the economic event) `[CODE]`. HTTP APIs separate the
`Idempotency-Key` header from the business id in the body.

**Mechanism.** They have different lifetimes and different scopes. The idempotency key answers *"have I
already processed this exact request?"* and can expire; the business id answers *"which real-world
event is this?"* and cannot. Conflating them forces one of two failures: regenerating the business id
on retry (breaking idempotency) or keeping idempotency keys forever (an unbounded table).

**QAYD.** S2-13 specifies `Idempotency-Key` keyed `(company_id, endpoint, key)` — correct shape.
Recommendation **BR-06** fills in the details below.

---

### BP-11 · Reject a conflicting replay, and say what conflicted — **ADOPT**

**Practice.** Increase returns `409` with `type: idempotency_key_already_used_error` **and the
`resource_id` of the original object** `[DOCS]` increase.com/documentation/idempotency-keys.
TigerBeetle goes further and returns *which field* differs — `exists_with_different_amount`,
`exists_with_different_debit_account_id`, `exists_with_different_flags`, and so on `[DOCS]`.

**Mechanism.** Same key + different body means the client has a bug. Silently replaying the first
response discards the second, different, transaction — a lost posting with no error anywhere. Rejecting
is mandatory; returning *what* conflicted turns a dead end into a recoverable situation, because the
caller can inspect what it actually did.

**QAYD.** `409 idempotency_key_conflict` is already specified — ahead of four of the five vendors
studied, none of whom document this case at all. The additions worth making are cheap: include the
original entry id, and name the diverging fields. This matters disproportionately for AI-drafted
requests, where the caller is a model that benefits from a structured, actionable error
(`knowledge` S2+A already argues for aggregated `violations[]` — same instinct).

---

### BP-12 · Distinguish a transport retry from a business retry — **ADOPT**

**Practice.** TigerBeetle's `id_already_failed`: *"A previous transfer with the same `id` failed due to
transient errors. Retrying with the same ID will always fail; use a new idempotency ID to retry."*
`[DOCS]`. Mambu's complement: **validation failures are not cached**, so a retried `400` re-validates
`[DOCS]`.

**Mechanism.** Two different retries wear the same clothes. *"My request may not have arrived"* must
reuse the key. *"My request arrived and was rejected; here is another attempt"* is a **new economic
attempt** and must not. An implementation that memoises business failures replays a stale rejection
forever; one that treats failed keys as freely reusable lets a genuine duplicate through.

Between them, these two vendors define the whole question — *which outcomes does an idempotency key
memoise?* — and almost no implementation asks it.

**QAYD.** The rule to encode: **memoise successes; do not memoise validation failures; mark a key
consumed by a business failure as terminal for that key.** Recommendation **BR-06**.

---

### BP-13 · Correct with a named compensating entry, never an edit — **CONFIRM**

**Practice.** Increase uses a suffix taxonomy — `_intention` / `_return` / `_rejection` — so "we meant
to", "it came back" and "it was refused" are three distinct queryable facts rather than three states of
one mutable row `[DOCS]`. Vault's `Release` and partial `Settlement` are new instructions in a chain;
the original is never touched `[CODE]`. TigerBeetle: resolution *"does not involve modifying the
pending transfer. Instead you create a new transfer."* `[DOCS]`

**Mechanism.** BP-01's precondition, applied to the correction path — which is where immutability is
most often quietly abandoned, because the correction feels like an edit to the user.

**QAYD.** S2-06 / P-13 are exactly this. The transferable refinement is the **taxonomy**: QAYD's
`reversal_kind ∈ {full, partial, storno}` is already planned; adding *why* (`reversal_reason NOT NULL`,
already in the backlog constraints) completes it.

---

### BP-14 · Validate pessimistically at reservation, so resolution cannot fail — **CONSIDER**

**Practice.** TigerBeetle guarantees that *"the second step in a two-phase transfer will never cause
the accounts' configured balance invariants … to be broken, whether the second step is a post or
void"* — achieved by rejecting at reserve time anything that *could* violate a constraint at
settlement `[DOCS]`.

**Mechanism.** Move the failure to the moment the human is still present. A failure at reservation is a
conversation; a failure at settlement is an incident, because the counterparty has already been told
yes.

**QAYD.** No two-phase concept, so no direct application. The principle generalises to **approval
workflows**: if an entry is approved today and posted tomorrow, everything that could make posting fail
(closed period, inactive account, imbalance) should be checked *at approval*, with the post re-checking
only what can genuinely have changed. Otherwise "approved" means nothing.

---

## Part 4 — Rules and overrides

### BP-15 · Evaluate rules on the *proposed* posting, before acceptance — **CONFIRM**

**Practice.** Vault's `pre_posting_hook` sees the **proposed** client transactions and may reject the
whole batch; `post_posting_hook` sees the **committed** ones. The two argument types are otherwise
identical `[CODE]`.

**Mechanism.** Rejecting before acceptance means there is no partial state to unwind and no
compensating entry to explain to a user. And the *identical data shape* for hypothetical and actual is
quietly excellent design: one piece of logic can reason about both.

**QAYD.** All invariants are enforced inside the posting transaction before any ledger row is written,
and a failure rolls the whole thing back. Correct. The idea worth borrowing is the **dry-run**: an
endpoint that runs the full validation and reports what *would* happen, without posting. For an AI that
drafts entries, that is the difference between iterating safely and iterating in production.

---

### BP-16 · Rejections are a closed, machine-readable enumeration — **ADOPT**

**Practice.** Vault's `PostingInstructionRejectionReason` — `RESTRICTION_PREVENT_DEBITS`,
`RESTRICTION_LIMIT_CREDITS`, `RESTRICTION_REVIEW_DEBITS`, `INSUFFICIENT_FUNDS`, `WRONG_DENOMINATION`,
`ACCOUNT_STATUS_INVALID`, `AGAINST_TERMS_AND_CONDITIONS`, `CLIENT_CUSTOM_REASON` `[CODE]`.

**Mechanism.** A closed enumeration is machine-actionable: a caller can branch on it, a UI can
translate it, a metric can count it. Prose cannot. The `CLIENT_CUSTOM_REASON` member is the part most
designs omit — it makes the enumeration extensible without a schema change, so the closed set does not
become a reason to reach for free text.

**Note `REVIEW_*` specifically:** *"allowed, but must be looked at"* is a first-class ledger outcome
alongside prevent and limit. That is exactly the state an AI-drafted entry occupies, and it is
instructive that a bank core needed the same third option.

**QAYD.** Aligns with S2+A (aggregate all failures into one structured `violations[]`). The banking
refinement: each violation carries a **stable code** from a closed set, plus the field it concerns —
and the set includes a review-not-reject outcome.

---

### BP-17 · Overrides are fields on the record, not branches in code — **ADOPT**

**Practice.** Vault's `advice` (*"skip balance checks for this posting instruction"*) and
`override_all_restrictions` (*"whether to ignore all restrictions"*) are **persisted fields on the
posting instruction** `[CODE]`.

**Mechanism.** An override implemented as a code branch leaves no trace, so "which entries bypassed
which check" becomes unanswerable — and the override then quietly becomes load-bearing for some
integration, at which point the rule it bypasses is decorative. As data, it is a `WHERE` clause and a
dashboard.

**QAYD.** No override exists today, which is the ideal state. The rule to hold: **when one is requested
— and one will be — it is a nullable column with a reason and an actor, never a parameter.**

---

### BP-18 · Configuration has an explicit permission level — **CONSIDER**

**Practice.** Vault: `ParameterLevel = GLOBAL | TEMPLATE | INSTANCE` crossed with
`ParameterUpdatePermission = FIXED | OPS_EDITABLE | USER_EDITABLE | USER_EDITABLE_WITH_OPS_PERMISSION`
`[CODE]`.

**Mechanism.** "Who may change this number, at what scope" is answered as **data**, once, rather than
by permission checks scattered through controllers where they drift apart.

**QAYD.** The same question applies to the fiscal calendar, VAT rates, the rounding account and the
base currency. Not near-term, but the right shape when settings become a real subsystem.

---

## Part 5 — Proof and operations

### BP-19 · Run the trial balance as an assertion, not a report — **ADOPT**

**Practice.** Banking's control totals: transmit a record count and a field sum alongside a batch, and
refuse the batch if the recomputation differs.

**Mechanism.** A hash mismatch says *"something is wrong"*. A control-total mismatch says *"you are 3
records and KWD 1,240.500 short"* — a debuggable statement. Control totals catch truncation and partial
application, which is the realistic failure mode of a projection.

**The reframing that matters:** the trial balance has always *been* a control total. It is an integrity
proof that accountants also happened to use as a report. Running it on a schedule, as an assertion that
alerts, is the highest-value low-cost idea in this research.

**QAYD.** The assertion set is already implied by the schema: `SUM(signed_base_amount) = 0` per company
and per entry; `COUNT(ledger_entries) = COUNT(posted journal_lines)`; no gaps in `journal_number` per
company-year; maintained rollup = re-derived fold. That is S2-14. Recommendation **BR-05**.

---

### BP-20 · Re-derive and compare, continuously — **ADOPT**

**Practice.** Rebuild the derived projection from the immutable source and compare byte-for-byte.

**Mechanism.** It is the only check that validates the *cache* rather than the source. It works **only
because** the source is append-only (BP-01) and the arithmetic is exact (BP-22) — and it stops working
the instant either is compromised, which is why those two are prerequisites rather than companions.

**QAYD.** S2-14 is specified as exactly this (*"rebuilds `ledger_entries` from posted `journal_lines`
… and asserts byte-identical balances and statements"*, and *"a deliberate seeded drift is detected and
alerts"*). The banking-derived point is one of **sequencing**: this is worth more than the hash chain
and costs 3 points against 21. It should not wait for S4+A. Recommendation **BR-05**.

---

### BP-21 · Anchor the digest outside the database — **ADOPT (with the chain)**

**Practice.** Publish a signed digest of the ledger head to an append-only store in a different trust
domain, periodically.

**Mechanism.** A hash chain inside a database the attacker can write to proves nothing against that
attacker — they recompute the tail. The chain becomes meaningful only once a digest exists somewhere it
cannot be retroactively edited (`ARCHITECTURE.md` §10.2). **A chain without an anchor is half a
feature that reads like a whole one.**

**QAYD.** The economical version: **anchor at period close.** The close is already a low-frequency,
human-attested event. A signed digest of the ledger head published then costs almost nothing and yields
*"the books for July cannot be altered without detection"* — a sentence with genuine commercial value
to an accountant, and one most of the incumbent core banking market cannot make in public (§5.5).
Recommendation **BR-09**.

---

### BP-22 · Exact arithmetic, end to end, including the client — **CONFIRM**

**Practice.** TigerBeetle uses u128 integers and an asset scale; floats are refused outright `[DOCS]`.

**Mechanism.** Beyond rounding: floating-point addition is **not associative**, so a re-derived balance
can differ from a maintained one purely because rows were summed in a different order. That makes the
drift detector produce false positives, and a false-positive alarm is a muted alarm. **One float
anywhere destroys the value of the whole integrity layer.**

**QAYD.** `NUMERIC(19,4)` + bcmath strings, with a `LogicException` if a non-numeric value is ever read
back. The remaining exposure is the **frontend** `deriveBalance` (S2-11), which Principle P7 already
requires to use identical semantics. Worth restating only because it is the one remaining door.

---

### BP-23 · Assertions in production, on at least two code paths per property — **ADOPT**

**Practice.** TigerBeetle: *"a minimum of two assertions per function"*; assert the positive space and
the negative space; *"for every property you want to enforce, try to find at least two different code
paths where an assertion can be added"* — e.g. before writing to disk and after reading back. Enabled
in release builds `[CODE]` docs/TIGER_STYLE.md.

**Mechanism.** Stated by TigerBeetle in the best sentence in the corpus: assertions *"downgrade
catastrophic correctness bugs into liveness bugs"*. **A system that crashes is recoverable; a system
that silently computes the wrong balance is not.** In a ledger, failing loudly is strictly better than
proceeding.

**QAYD.** Already does this in exactly one place — the `LogicException` on a non-numeric money read in
`JournalEntryPostingService`. The practice is to **generalise that instinct** along the posting path:
assert the projected row count equals the line count; assert the signed amount reconstructs from
gross; assert the allocated journal number is unallocated. Recommendation **BR-10**.

---

### BP-24 · Seeded, reproducible property tests over the posting engine — **ADOPT**

**Practice.** TigerBeetle's VOPR is deterministic on `(seed, commit)`, so *"we can perfectly reproduce
any bugs"* `[CODE]`.

**Mechanism.** Example-based tests check the cases you thought of. Property tests check the invariant
across cases you did not. Determinism is what makes a property-test failure *actionable* rather than a
flaky ticket.

**QAYD.** The full VOPR is out of reach (`ANTI_PATTERNS.md` BB-06), but the transferable core is a
week of work: generate thousands of random-but-valid entry shapes from a recorded seed, post them, and
assert BP-19's invariant set. A failure is a seed, replayable on a laptop. This is the
highest-leverage testing idea in the research. Recommendation **BR-11**.

---

### BP-25 · Benchmark through the real path — **ADOPT (free)**

**Practice.** Thought Machine: *"every test request passes through the same public APIs, internal
services, event streams, and database tables as a live, production transaction"* — explicitly
contrasted with legacy systems using *"low-level tests and hardwired paths"* `[DOCS]`.

**Mechanism.** A benchmark through a fast path measures the fast path. The number is real and the
inference from it is false, and the falsity surfaces only in production.

**QAYD.** Costs nothing to adopt as a stated rule now, before any performance work exists to
compromise. Note the published shape of Vault's numbers as a planning input: **balance enquiry runs
~3× the throughput of a posting** — reads dominate, and read and write paths deserve separate budgets.

---

## Part 6 — API and events

### BP-26 · The event carries a signal; the API carries the money — **CONFIRM**

**Practice.** Universal across every vendor that documents delivery: **at-least-once, no exactly-once
claimed by anyone.** Column states ordering is **explicitly not guaranteed**; Increase stops retrying
after ~72 hours and says *"polling is the way to recover from extended outages"* `[DOCS]`.

**Mechanism.** A consumer that *folds* an at-least-once, unordered stream into a balance will
eventually double-apply or misorder, with no mechanism to notice. A consumer that receives "entry 4471
posted" and re-reads through the authoritative API cannot drift.

**QAYD.** Already P-11 and S2-13's stated design. Confirmed independently; no change.

---

### BP-27 · The polling API is the correctness backstop; the webhook is the optimisation — **ADOPT**

**Practice.** Column recovers via `/api/events`; Increase via List Events with **30-day retention**;
Mambu via a consumer-committed cursor with a retention window `[DOCS]`.

**Mechanism.** Delivery is best-effort by construction. Without a *queryable history* of events, a
consumer that was down for a day has no way to catch up and no way to know what it missed.

**QAYD.** S2-13 broadcasts over Reverb. The banking-derived addition is a **listable, retained event
history** so a consumer that missed a broadcast can reconcile — which is also what makes the outbox
useful for debugging rather than only for delivery. Recommendation **BR-12**.

---

### BP-28 · Make expensive things opt-in and say why — **CONSIDER**

**Practice.** Mambu's list endpoints return no total count unless the caller requests
`paginationDetails` — the only vendor in the study that admits total-count is expensive and makes the
caller ask `[DOCS]`.

**Mechanism.** `COUNT(*)` over a filtered, RLS-scoped, growing table is unbounded work performed on
every page of every list, usually to render a number nobody reads. Making it opt-in converts a default
cost into a deliberate one.

**QAYD.** Directly applicable to ledger and journal list endpoints, which are exactly the tables that
grow without limit.

---

### BP-29 · Version by contract, not by number — **CONSIDER**

**Practice.** Increase is **unversioned**, with a published compatibility contract enumerating what
counts as backwards-compatible, and explicit client obligations: never parse IDs, tolerate unknown enum
values `[DOCS]` increase.com/documentation/backwards-compatibility. Mambu takes the opposite approach
with a required media type (`Accept: application/vnd.mambu.v2+json`).

**Mechanism.** A version number is a promise that is rarely kept and expensive when it is — every
version is a codebase. A published compatibility contract is a cheaper, more honest promise, *provided*
the client obligations are stated and enforced by the SDK.

**QAYD.** Worth an explicit decision rather than a default. The consideration that tilts it: QAYD's
`violations[]` codes and enum-valued fields (`journal_entry_type`, rejection reasons) are exactly the
places where "tolerate unknown enum values" must be a documented client obligation, because new members
will be added.

---

## Practice → QAYD mapping, at a glance

| Practice | Status | Recommendation |
|---|---|---|
| BP-01 append-only, privilege + trigger | partial — privilege gap | **BR-02** |
| BP-02 gross and net | done; protect in the rollup | **BR-07** |
| BP-06/07 date vs instant, system-assigned clock | done; anchor undeclared | **BR-04** |
| BP-08 bounded backdating | **missing** | **BR-04** |
| BP-10/11/12 idempotency, three refinements | specified, not built | **BR-06** |
| BP-16 closed rejection enumeration | aligns with S2+A | **BR-06** |
| BP-17 overrides as data | none exist — hold the line | — |
| BP-19 control totals | **missing** | **BR-05** |
| BP-20 re-derive and compare | S2-14, should not wait for S4+A | **BR-05** |
| BP-21 external anchor | **missing** | **BR-09** |
| BP-23 assertions on the posting path | one instance; generalise | **BR-10** |
| BP-24 seeded property tests | **missing; highest leverage** | **BR-11** |
| BP-27 listable event history | **missing** | **BR-12** |
| — audit row on posting (TD-16) | **missing; highest priority** | **BR-01** |
| — DB-enforced Σ(lines) = 0 | **missing** | **BR-03** |
