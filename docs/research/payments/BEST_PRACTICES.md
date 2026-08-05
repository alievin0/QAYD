# BEST PRACTICES — What works in payment systems, and the mechanism that makes it work

Version 1.0 · 2026-07-28 · Part of `docs/research/payments/`
Companion to `ANTI_PATTERNS.md` (the same field, seen from the failure side).

---

## How to read this

Eighteen practices, `PB-01` … `PB-18`, grouped into five families. Each carries:

- **The practice**, stated as a rule.
- **The evidence** — who does it, with grading.
- **Why it works** — the mechanism, not the benefit. A benefit is a claim; a mechanism is an argument.
- **Cost** — what you give up. A practice with no stated cost has not been thought about.
- **QAYD status** — `already does this` · `partially` · `gap` · `not applicable`, with a pointer.

A practice earns a place here only if at least two independent systems adopted it, or one system published
a mechanism strong enough to stand alone.

---

# Family A — Ledger

## PB-01 — Store gross debits and gross credits separately; derive the net

**The practice.** An account's persistent state is at minimum a pair of gross accumulators, never a single
signed scalar. The net balance is a derived quantity, computed at read time or stored as a constrained
projection of its sources.

**Evidence.** TigerBeetle stores four unsigned 128-bit integers (`debits_pending`, `debits_posted`,
`credits_pending`, `credits_posted`) and derives balance in the application `[DOCS]`. Modern Treasury
exposes `credits`, `debits` and a derived `amount` on every balance object `[DOCS]`. Stripe carries
`amount`, `fee`, `net` with the documented identity `net = amount − fee` `[DOCS]`. Adyen's settlement
report separates gross debit, gross credit, net debit, net credit, commission, markup, scheme fees and
interchange `[DOCS]`.

**Why it works.** Netting is a lossy compression, and what it loses is turnover. Turnover drives fee
calculation, volume-based pricing, VAT and tax bases, and every reconciliation against a counterparty who
reports gross. The information is unrecoverable after the fact — you cannot derive gross from net. A second
mechanism, specific to unsigned separated counters: the account guard invariants become pure comparisons
with no sign handling and no underflow, and all counters are monotonically increasing under posting, which
makes them cheap to verify and safe to accumulate out of order `[INFERENCE]` from TigerBeetle's documented
model.

**Cost.** More columns, and the derived value must be kept honest — which requires a constraint, not a
convention.

**QAYD status — already does this, well.** `ledger_entries` carries `debit_amount`, `credit_amount`,
`base_debit_amount`, `base_credit_amount` and `signed_base_amount`, with
`CHECK (signed_base_amount = base_debit_amount - base_credit_amount)` binding the derived column to its
sources and `CHECK` forbidding a two-sided line `[CODE]`. This is the corpus's best practice with the
derived-column trap closed by a database constraint. See `AD-03`, `P-02`.

## PB-02 — Two phases: reserved, then final — with an explicit unsuccessful terminal

**The practice.** Money movement has a reserved state and a final state, and *three* outcomes from
reserved: posted, voided, or expired. Not two.

**Evidence.** TigerBeetle: `pending` → `post_pending_transfer` | `void_pending_transfer` | timeout `[DOCS]`.
Modern Treasury: `pending` → `posted` | `archived` `[DOCS]`. Adyen: `authorised` → `booked` | `failed` |
`refused` | `returned` `[DOCS]`. Square: `RESERVE_HOLD` → charge | `RESERVE_RELEASE` `[DOCS]`.

**Why it works.** The gap between promise and settlement is physical — card auth to capture, ACH in
flight, cheque not cleared. A one-state ledger must either book promises as facts (wrong when they fail)
or ignore them (blind to committed obligations). The third outcome is the part usually missed: without an
explicit *this will never complete* transition, failed promises accumulate as permanent phantom balance
that nothing ever cleans up. TigerBeetle's timeout is the strongest form — the reservation self-releases
after a declared interval, so a crashed or abandoned flow cannot leak a hold forever `[DOCS]`.

**Cost.** Every balance question becomes three balance questions, and the caller must know which one it
is asking. Reporting has to choose a state and say so.

**QAYD status — the shape exists, unnamed.** `bank_transactions` runs `draft → … → cleared → reconciled`
with a DB `CHECK` that `cleared`/`reconciled` implies a non-null `journal_entry_id` — structurally a
pending/posted split with the posting proof enforced by the database (S3-02, S3-03). The ledger itself has
no pending concept and correctly should not: QAYD posts facts, not promises. See `LESSONS_FOR_QAYD.md`
§2.1.

## PB-03 — Available balance counts outbound at promise and inbound at settlement

**The practice.**

```
available = posted_inbound − pending_outbound        (never posted_inbound − posted_outbound)
```

**Evidence.** Modern Treasury, exactly: for a credit-normal account,
`available_balance = posted.credits − pending.debits` `[DOCS]`. Adyen: available is the lower of the
current balance or (current − pending − reserved) `[DOCS]`. TigerBeetle's balancing transfers enforce
`debits_pending + debits_posted ≤ credits_posted` `[DOCS]`.

**Why it works.** The two directions have asymmetric failure consequences. If a pending inbound fails after
you counted it, the money was already spent and cannot be recovered. If a pending outbound is voided after
you counted it against the balance, you were merely conservative for a while. The rule is not general
caution — it is that only one of the two errors is unrecoverable.

**Cost.** Users see a lower number than they expect and ask why. This is a product problem, not an
engineering one, and every system in the corpus accepted it.

**QAYD status — not applicable yet, becomes relevant at S3-01.** `bank_accounts` carries a running balance
(S3-01). The moment an uncleared transaction can reduce a displayed figure, this formula is the one to
use. Record it now so the decision is not made accidentally.

## PB-04 — Correction is a new opposing entry, never an edit

**The practice.** A posted fact is immutable. Wrongness is expressed by a second, linked, opposing fact.

**Evidence.** TigerBeetle: *"All Transfers Are Immutable"* — even resolving a pending transfer creates a
*new* transfer carrying `pending_id` `[DOCS]`. Modern Treasury: reversal via
`reversed_by_ledger_transaction_id`; only `metadata` stays mutable `[DOCS]`. Square: `_REVERSED`
counterparts for nearly every entry type `[DOCS]`. Adyen: `ChargebackReversed`, `RefundedReversed`,
`PaidOutReversed`, `SettledReversed` `[DOCS]`. Stripe: `dispute` and `dispute_reversal` are distinct
reporting categories over distinct balance transactions `[DOCS]`.

**Why it works.** Two mechanisms, and the second is the one usually missed.

*The audit mechanism*: an edited row destroys the evidence that the error occurred, which is exactly the
evidence an auditor wants.

*The expressiveness mechanism*: reversal lets the model say things mutation cannot. Stripe's documentation
states that when a merchant **loses** a dispute, *"no money moves from your perspective"* `[DOCS]` — the
debit already happened at dispute-open, so a loss is the **absence of a reversal**, not a new debit. That
sentence is only coherent in an append-only model. A mutable-charge model has no way to represent "the
thing that did not happen."

**Cost.** More rows. Every read must aggregate rather than look up. Users must be taught that a mistake
leaves two entries, not zero.

**QAYD status — already does this, enforced at the database.** `AD-07`, `P-13`,
`trg_ledger_entries_append_only` raising `restrict_violation` on any UPDATE or DELETE independent of the
application `[CODE]`. Among the strongest enforcement in the corpus, because it is a trigger rather than a
convention.

## PB-05 — Balance per currency; never sum across currencies

**The practice.** Debits equal credits **within each currency**, and no account holds a mixed-currency
scalar.

**Evidence.** TigerBeetle enforces it structurally — the `ledger` field partitions which accounts may
transact, so a cross-currency transfer is inexpressible and must be a linked chain through an FX position
pair `[DOCS]`. Modern Treasury enforces it in the balance rule, explicitly to prevent *"transactions that
appear balanced overall while actually losing money in specific currencies"* `[DOCS]`. Adyen and Wise
expose plural per-currency balance objects, never a mixed scalar `[DOCS]`.

**Why it works.** Modern Treasury names the failure exactly: an entry of +100 USD and −100 EUR passes a
global sum check. The invariant held and the money is gone. A single-currency check is not a weaker
version of a per-currency check — it is a *different* check that is silent on the case that matters.

**Cost.** FX becomes structurally multi-leg: two legs across two currency scopes plus an FX
position/gain-loss account. There is no one-line conversion entry.

**QAYD status — partial, with a small latent gap.** `assertBalanced` requires exact equality in both the
entry currency and the base currency, zero tolerance, re-derived from lines `[CODE]`. That catches the
common cases. It sums `debit`/`credit` across all lines **regardless of each line's `currency_code`**, so a
three-currency entry that balances in base but not per-currency would pass. There is no such caller today.
See `LESSONS_FOR_QAYD.md` §3.3 and R-06.

## PB-06 — Bitemporality: separate when it happened from when you learned it

**The practice.** Every entry carries at least two timestamps: business/valid time (when the event
occurred for reporting) and system/record time (when it entered the system).

**Evidence.** Modern Treasury: `effective_at` (*"the time at which the ledger transaction happened for
reporting purposes"*) vs `posted_at` vs `created_at`, with as-of queries via `balances[effective_at]` and a
separate version history queryable by `created_at` `[DOCS]`. Adyen: `Value Date` vs `Booking Date` `[DOCS]`.
Square: `PayoutEntry.effective_at` vs `Payout.created_at` `[DOCS]`. TigerBeetle: an `imported` flag that
preserves an original timestamp against the cluster-assigned one `[DOCS]`.

**Why it works.** Late-arriving and backdated facts are normal, not exceptional — a statement line for the
28th arrives on the 3rd; an accrual is booked in arrears. With one timestamp you must choose between
"the report for March changes after March closed" and "the entry is filed in the wrong month." Two
timestamps let both questions be answered: *what was true in March* and *what did we know in March*.

**Cost.** Two axes to query and to index, and users must be told which one a report uses. Modern Treasury
documents a real trap here: its balance-condition guards *"consider every Ledger Entry… regardless of
their `effective_at` values"* `[DOCS]` — the guard is evaluated over the whole account, not an as-of
slice, so a backdated entry affects guard evaluation.

**QAYD status — the axes exist, the queries do not.** `ledger_entries` carries `entry_date` (valid time),
`posted_at` (system time) and `created_at` `[CODE]`. The data model is already bitemporal; there is no
as-of query and no version history. This is `I-01` (The Bitemporal Ledger), and it is closer to reach than
`I-01` implies.

---

# Family B — Idempotency

## PB-07 — Client-supplied immutable identity is idempotency for free

**The practice.** Where an operation's identity can be a row, let the client generate the identifier, make
the row immutable, and let a uniqueness constraint reject the duplicate.

**Evidence.** TigerBeetle's `Transfer.id` is a client-defined, cluster-unique, non-zero 128-bit integer,
and a resent transfer is rejected as a duplicate by construction `[DOCS]`. Modern Treasury's `external_id`
is the same idea `[DOCS]`. Adyen's `Psp Reference` and Square's `end_to_end_id` play the identity role
on the read side `[DOCS]`.

**Why it works.** Retry-safety becomes a schema property rather than a middleware behaviour. There is no
TTL to expire, no response to store, no cache to fall out of sync with the database, and no window during
which the guarantee is absent. The uniqueness constraint is also the correct concurrency control: two
simultaneous inserts of the same id cannot both succeed, which a check-then-insert cannot promise.

**Cost.** Only works when the operation's identity is expressible as a single row. It does not generalise
to "this request performed five writes and called an external API."

**QAYD status — already does this in one place, and it is the strongest example in the codebase.**
`uq_ledger_entries_journal_line UNIQUE (journal_line_id)` is documented in the migration as *"the DB
backstop that a line can be projected at most once (idempotent posting)"* `[CODE]`. Posting is
idempotent at the database level regardless of what any middleware does. Extend the idea, do not replace
it.

## PB-08 — The idempotency record commits with the fact it protects

**The practice.** The idempotency key row lives in the same database as the business fact and is written
in the same transaction. Never in a separate store written after commit.

**Evidence.** The reference design most often cited describes an idempotency-key table holding the request
parameters, a **recovery point**, and — on completion — the response code and body, with a composite
unique index namespacing keys per user and a `locked_at` column preventing concurrent execution
`[COMMUNITY]` — this is a reference implementation informed by Stripe, not documentation of Stripe's
internals, and should be cited as a pattern. Stripe's *observable* behaviour is consistent with it: a key
currently executing returns `409 idempotency_key_in_use`, and *"we save results only after the execution
of an endpoint begins"* `[DOCS]`.

**Why it works.** Storing the key anywhere else recreates the dual-write problem the outbox exists to
solve, in the one place whose entire job is to prevent duplicate money movement. If the process dies
between `COMMIT` and the write to the key store, the fact is durable and the key is not — so the retry
finds no key and executes again. **The window is small and the consequence is a double post**, which is
the worst trade in the system. Same-transaction storage makes the guarantee unconditional: either both
exist or neither does.

**Cost.** The key table is in the transactional path and grows; it needs a pruning job. A cache in front of
it is fine as an optimisation, provided the database remains the authority.

**QAYD status — GAP, and specified wrongly.** `docs/api/API_ARCHITECTURE.md` specifies Redis storage
written after the response, with a 10-second lock. See `LESSONS_FOR_QAYD.md` §3.1 and R-01. This is the
study's most important finding.

## PB-09 — Store and replay the outcome, including failures

**The practice.** On first execution, persist the status code and response body **regardless of whether
the request succeeded**, and replay that outcome for subsequent uses of the key.

**Evidence.** Stripe, verbatim: *"Stripe's idempotency works by saving the resulting status code and body
of the first request made for any given idempotency key, regardless of whether it succeeds or fails.
Subsequent requests with the same key return the same result, including 500 errors."* `[DOCS]` Replays are
detectable via an `Idempotent-Replayed: true` header `[DOCS]`.

**Why it works.** Storing only successes is the intuitive design and is wrong, because the dangerous case
is precisely a request that *did* the work and then failed to report it. A 500 raised after the journal
posted, or a connection dropped during response serialisation, leaves durable state behind. If only
successes are recorded, the retry finds nothing and posts a second journal. Recording the failure makes
the client's retry return the same failure — which is honest, and which the client can then investigate —
rather than silently duplicating.

**Cost.** A client that retries a transient 500 gets the same 500 forever within the replay window and must
mint a new key to genuinely retry. That is a real ergonomic cost and it is the correct trade: the
alternative trades ergonomics for double-posting.

**QAYD status — GAP.** The specified middleware stores only `if ($response->isSuccessful())` `[CODE, doc]`.
See R-01.

## PB-10 — The key's match includes the endpoint and a fingerprint of the body

**The practice.** A key identifies *(tenant, endpoint, request body)*. Reuse with a different body or a
different endpoint is a client bug and must be an error, not a replay and not a fresh execution.

**Evidence.** Stripe: *"The idempotency layer compares incoming parameters to those of the original
request and errors if they're not the same."* The `idempotency_error` type is defined as reuse *"on a
request that does not match the first request's **API endpoint and parameters**"* `[DOCS]` — endpoint is
part of the match key, not only the body.

**Why it works.** Two distinct client bugs are caught. Same key with a different body means the client is
reusing a key for a different logical operation — replaying would return a result for an action the caller
believes it did not take. Same key on a different endpoint means a key-scoping bug that would otherwise
let a `POST /transfers` result be replayed as the response to `POST /payments`. Both are silent
corruption if unchecked.

**Cost.** A body fingerprint must be computed and stored, and it must be canonicalised carefully (key
order, numeric formatting) or false conflicts appear.

**QAYD status — partial, with an internal inconsistency worth resolving.** `docs/api/API_ARCHITECTURE.md`
scopes the key `idempotency:{company_id}:{key}` and conflicts on body hash only. The S2-13 story is
stricter, specifying `(company_id, endpoint, key)` `[DOCS, sprint]`. **Follow S2-13; the sprint plan is
right and the API doc is one column short.** See R-01.

## PB-11 — Cross an external call only between committed phases

**The practice.** Never hold a database transaction open across a call to an external system. Split the
operation into **atomic phases** — runs of purely local database work — separated by external calls, and
persist a named **recovery point** at each boundary so a resumed request knows where it stopped.

**Evidence.** The canonical description names *foreign state mutation* (a call to an external system
outside your ACID boundary), *atomic phase* (local work committed as one transaction), and *recovery
point* (a checkpoint on the idempotency-key row after any completed phase or external call, forming a
directed acyclic graph terminating at `finished`). The rule: *"atomic phases should be safely committed
before initiating any foreign state mutation."* `[COMMUNITY]`

**Why it works.** An external side effect cannot be rolled back. If it happens inside a transaction that
later aborts, the database forgets and the world does not — you have charged a card with no record of
charging it. Committing before the call means the intent is durable first; recording the result after
means the outcome is durable second; and the recovery point tells a retry which of those two states it is
in. Without recovery points a resumed request can only re-run everything, which re-fires the external
call.

**Cost.** The operation is no longer one transaction, so intermediate states are visible and must be
legal states. This is real modelling work.

**QAYD status — not yet needed, needed soon.** Sprint 03's posting path is entirely local, so one
transaction is correct and `JournalEntryPostingService` is right to be pure DB work `[CODE]`. It becomes
load-bearing the moment an Action calls a PSP, a tax authority, or a bank — the deferred
`PaymentDispatchService` in `docs/backend/BANKING_SERVICE.md` is the first. Record the pattern before that
story is written, not during.

---

# Family C — Events and webhooks

## PB-12 — The transactional outbox, and its honest consequence

**The practice.** Write the event row into an outbox table **in the same transaction** as the business
fact. A separate relay publishes from the outbox. Consumers deduplicate.

**Evidence.** The canonical statement: the outbox means *"messages are guaranteed to be sent if and only
if the database transaction commits"*, with the documented drawback that the relay *"may publish messages
multiple times (e.g. after crashing before confirming publication)"* `[DOCS]`. Two relay mechanisms are
documented: polling publisher (*"works with any SQL database"*, but *"tricky to publish events in order"*)
and transaction-log tailing / CDC (*"guaranteed to be accurate"*, but *"requires database specific
solutions"* and *"tricky to avoid duplicate publishing"*) `[DOCS]`.

**Why it works.** It converts a distributed-transaction problem into a local one. The dual write — change
state, then tell someone — has a crash window in the middle that no amount of retry logic closes, because
the retry logic itself is lost in the crash. Putting the message in the same ACID boundary as the state
removes the window entirely.

**The consequence must be stated plainly, because it is where teams go wrong:** the outbox buys
**atomicity, not exactly-once**. The relay's "publish" and "mark published" are themselves a dual write,
one level down. There is no configuration that changes this. Exactly-once delivery does not exist; what
exists is at-least-once delivery plus an idempotent consumer.

**Cost.** A table, a relay worker, a monitoring surface for relay lag, and mandatory idempotency in every
consumer.

**QAYD status — declared and unbuilt, and this is `AD-14`'s named gap.** Events are dispatched after the
transaction returns in `PostJournalEntryAction`, which is correct on the axis that matters most (an event
never precedes its fact) and lossy on the other. `P-11` costs the outbox at 5 points and says it *"must be
closed before the second consumer exists."* **S2-13 creates the second consumer.** See R-02.

## PB-13 — The idempotent consumer, with the dedup insert inside the effect's transaction

**The practice.** A consumer records processed message ids in its own database, and the insert of
`(subscriber, message_id)` as a **primary key** happens in the same transaction as the business effect.

**Evidence.** The pattern is documented with exactly this atomicity requirement; a duplicate fails on
insert, rolls back, and the message is dismissed `[DOCS]`. Every provider in the corpus pushes dedup onto
the consumer: Stripe recommends logging processed `event.id`s `[DOCS]`; Adyen documents `eventCode` +
`pspReference` as the dedup pair `[DOCS]`; Mercury states plainly *"you may occasionally receive the same
event multiple times, and you should use the event's id field"* `[DOCS]`; Plaid: *"You should design your
application to handle duplicate and out-of-order webhooks"* `[DOCS]`.

**Why it works.** The uniqueness constraint *is* the concurrency control. A `SELECT` then `INSERT` is
check-then-act and loses to two concurrent deliveries of the same message in separate transactions; a
primary-key violation does not. And the atomicity matters in both directions: writing the dedup row before
the effect swallows the message permanently if the effect fails, writing it after re-runs the effect if
the process dies between them. Both failures are silent.

**Cost.** A table per consumer (or a column on the affected entity), and a retention policy.

**A subtlety worth carrying, because no source states it directly:** a dedup table can only safely expire
entries if something *else* bounds the replay window. Standard Webhooks suggests a ~5-minute dedup store
`[DOCS]` — safe only because its signed-timestamp tolerance independently rejects anything older. A dedup
table behind an unbounded-validity signature must retain ids forever. `[INFERENCE]`

**QAYD status — gap, and cheap.** Listeners today are single-consumer and in-process. The moment the relay
exists, this is 2 points. See R-03.

## PB-14 — Refuse ordering guarantees on push; provide an ordered pull channel

**The practice.** State explicitly that push delivery is unordered, and give consumers an ordered,
replayable pull channel for when order matters.

**Evidence.** Every provider that addresses ordering refuses to guarantee it. Stripe: *"Stripe doesn't
guarantee the delivery of events in the order that they're generated,"* with their own example of a
subscription creation emitting four events in unpredictable order `[DOCS]`. Wise devotes a doc page to it:
*"If your application processes events in the order they are received, it may end up with an inaccurate
view of the current state of a resource"* `[DOCS]`. Adyen tells consumers to check the timestamp and
`sequenceNumber` `[DOCS]`. And crucially, **Stripe's pull channel *is* ordered** — paginating the Events
API with `ending_before` *"returns events in chronological order. This lets you process events in their
created order"* `[DOCS]`.

**Why it works.** Per-key ordering and parallelism are the same dial turned in opposite directions. A
globally ordered stream is a single partition, which is a single consumer, which caps throughput at one
machine. Kafka's guarantee is explicitly per-partition, with events sharing a key landing in the same
partition `[DOCS]`; Debezium's outbox router makes the aggregate id the message key precisely because it is
*"important for maintaining correct order in Kafka partitions"* `[DOCS]`. **Push for latency, pull for
order** is the pairing, and it lets each channel be good at one thing.

The design corollary: most "we need global ordering" requirements are mis-stated per-entity ordering
requirements. Ask which entity, and the problem usually dissolves.

**Cost.** Consumers must be written defensively, and the ordered channel is extra surface to build.

**QAYD status — `AD-14` already names this** as future risk 3: *"At-least-once says nothing about order;
a consumer that needs per-aggregate ordering must get a version on the event and reject out-of-order, not
assume."* The payments corpus confirms it unanimously. Put a monotonic per-aggregate version on
`accounting.journal.posted` in S2-13 — it costs nothing now and is unretrofittable later. See R-02.

## PB-15 — Treat a webhook as a notification; fetch the truth

**The practice.** Deliver "something changed, id=X" and let the consumer re-read current state over an
authenticated channel.

**Evidence.** Stripe's v2 thin events carry only `id`, `type`, `created`, `livemode`, `reason`, `context`
and `related_object { id, type, url }`, with `fetchRelatedObject()` as the documented pattern `[DOCS]`.
Their stated rationale is freshness (*snapshot events are "a point-in-time view"* for integrations that
*"can tolerate working with eventually-consistent data"*) and versioning (*thin events are unversioned;
"this eliminates the need to update webhook handlers when upgrading API versions"*) `[DOCS]`. Stripe's
own out-of-order guidance says the same thing from the other side: *"You can also use the API to retrieve
any missing objects"* `[DOCS]`. Svix: thin payloads *"always reflect the latest state even if the webhook
was delayed or delivered out of order"* `[DOCS]`.

**Why it works.** Three independent mechanisms.

*Security.* The body is attacker-reachable surface. If the body carries only an id and you re-fetch, a
successful forgery or replay buys the attacker at most a spurious authenticated read of your own data. It
also keeps sensitive values out of logs, proxies and queue storage.

*Correctness.* An unordered, retried, multi-day-delayed channel cannot carry current truth. A payload
asserting `status=pending` may arrive after the object reached `succeeded`. Re-fetching collapses the
ordering problem into last-writer-wins on *fetch time* rather than delivery time — which is the answer,
not a workaround.

*Versioning.* A fat payload is a published schema and therefore a permanent compatibility obligation. A
thin notification has almost nothing to break.

**Cost.** Documented honestly by the same sources: N+1 network calls, coupling of your processing to the
provider's API availability, and — during a provider incident — your retry storm becoming a read storm
against the same degraded system `[DOCS]`. Offering both shapes per event type is the observed
compromise.

**QAYD status — already the declared design, and it is correct.** `AD-14`: *"Realtime push (Reverb) is a
notification to refresh authoritative state, never a second write path."* S2-13's `journal.posted`
broadcast is specified as *"a compact projection an open ledger screen consumes to re-fetch"* `[DOCS]`.
QAYD reached the right answer independently; the corpus is confirmation, not correction.

## PB-16 — Sign a timestamp inside the payload; compare in constant time; hash the raw bytes

**The practice.** The signed string includes a timestamp (and ideally a message id), not just the body.
Verification uses constant-time comparison, over the **exact bytes received**, before any parsing.

**Evidence.** Stripe signs `timestamp . raw_body` with HMAC-SHA256, defaults to a 5-minute tolerance,
warns that a tolerance of `0` *"disables the recency check entirely"*, instructs implementers to
*"ignore all schemes that aren't v1"* to prevent downgrade, and states that *"any manipulation to the raw
body of the request causes the verification to fail"* `[DOCS]`. Standard Webhooks signs
`msg_id.timestamp.payload`, requires constant-time comparison, and carries multiple space-delimited
signatures to make key rotation a non-breaking operation `[DOCS]`. Plaid's scheme independently arrives at
the same controls: reject any `alg` other than `ES256`, reject webhooks with an `iat` more than 5 minutes
old, and compare the body SHA-256 in constant time — with an explicit warning that the hash *"is sensitive
to the whitespace in the webhook body"* `[DOCS]`. GitHub and Shopify sign the body **only**, with no
timestamp `[DOCS]`.

**Why it works.** Without a signed timestamp, a valid signature is valid **forever**. Anyone who captures
one delivery — from proxy logs, a leaked mirror, an intermediary — can replay it indefinitely and every
replay verifies. Sending the timestamp alongside is not enough; it must be *inside* the signed string, or
the attacker simply re-dates the capture. Constant-time comparison matters because a public webhook
endpoint is by definition an oracle an attacker can call at will, and byte-by-byte early exit leaks the
correct prefix through response timing.

The raw-bytes rule is the most common integration bug in the field: parse JSON, re-serialise, hash the
re-serialisation, fail. Key order and number formatting are not preserved by round-tripping.

**Cost.** Framework middleware that eagerly parses bodies has to be worked around, and clock skew becomes
a real operational concern (Stripe recommends NTP explicitly `[DOCS]`).

**QAYD status — future work.** QAYD's outbound webhook system is specified in
`docs/api/API_ARCHITECTURE.md` but not built. Adopt the Standard Webhooks header shape; the
version-prefix-inside-the-signature-value design makes scheme migration and key rotation expressible
without a breaking change `[DOCS]`. See R-09.

## PB-17 — Verify, persist, acknowledge, then process

**The practice.** In order: verify the signature on raw bytes; persist the raw event durably keyed by
provider event id; return 2xx; hand off to a worker. Exhausted retries land in a queryable dead-letter
store.

**Evidence.** Stripe: *"Your endpoint must quickly return a successful status code (2xx) prior to any
complex logic that could cause a timeout,"* and *"configure your handler to process incoming events with
an asynchronous queue"* `[DOCS]`. Adyen: acknowledge with 202, *"store the webhook message in your
database or a queue so you can process it later,"* and *"make sure that you acknowledge the webhook before
applying any business logic"* — with a 10-second ceiling `[DOCS]`. Shopify's total request timeout is
**5 seconds** `[DOCS]`. Plaid retries on non-200 **or no response within 10 seconds** `[DOCS]`.

**Why it works.** The failure mode this prevents is bimodal and vicious. A slow handler times out →
deliveries are retried → retries make the handler slower → the provider protects its own delivery pipeline
by **disabling the endpoint** (Stripe) or **deleting the subscription** (Shopify) → you now have a
**silent** data gap. Plaid's variant is worse because it is quieter: it stops retrying entirely if your
endpoint has rejected more than 90% of webhooks over the last 24 hours — a circuit breaker that can cut
you off during a bad deploy `[DOCS]`.

The ordering within the practice matters too. Verification must precede the acknowledgement, or you have
built an unauthenticated write endpoint into your queue.

**Cost.** A raw-event table, a worker, and a DLQ with alerting. Non-negotiable given the retention limits:
Stripe keeps events retrievable for **30 days**, resendable for 15 (Dashboard) or 30 (CLI) `[DOCS]`; Plaid
retries for **24 hours** and then the webhook is gone forever `[DOCS]`. The DLQ is the only thing standing
between a bad deploy and permanent data loss.

**QAYD status — future work; the shape is right in the existing spec** (Stage 8 dispatches asynchronously
via a queued job). Add the receive-store-process discipline on the *ingress* side when PSP webhooks land.
See R-09.

---

# Family D — Reconciliation and settlement

## PB-18 — One batch object whose entries sum to exactly one bank line

**The practice.** Model the payout as a first-class object with itemised entries whose net amounts sum to
the batch total, and carry the identifier that appears on the bank statement.

**Evidence.** Every merchant-facing system in the corpus does this, with an explicit reconciliation
identity.

| System | Batch object | Identity | Bank-statement join key |
|---|---|---|---|
| Stripe | payout + payout reconciliation report | starting + activity − payouts = ending | `payout_reference_token`, `trace_id` `[DOCS]` |
| Adyen | Batch Number + settlement details | Σ `Net Credit` per batch = bank payout | `Batch Number`, `MerchantPayout` row `[DOCS]` |
| Square | `Payout` + `PayoutEntry` (25 types) | Σ `net_amount_money` = `Payout.amount_money` | `Payout.id` on the statement `[DOCS]` |
| Checkout.com | Payouts + Financial Actions by Payout ID | Payout Amount = Σ Holding Currency Amount, **rounded down to 2dp** | Payout ID `[DOCS]` |
| Mollie | `Settlement`, with `open` and `next` states | settlement = its payments + refunds + chargebacks | settlement reference `[DOCS]` |

**Why it works.** A bank statement shows one line for a deposit that represents hundreds of transactions,
net of fees, spanning a cut-off boundary. Without a batch object the merchant faces an unsolvable
attribution problem; with one, reconciliation is a **join, not a search** — which is the same principle as
`R-24` seen from the settlement side. The identity assertion is what makes the join verifiable rather than
merely plausible.

Mollie's `open` and `next` settlement objects are the underappreciated refinement: the accruing,
not-yet-paid-out batch is addressable as a first-class object, so a merchant can reconcile **in flight**
rather than only after the money lands `[DOCS]`.

**Cost.** The batch object is real schema, and the identity must be asserted by a test, not assumed.
Stripe's own documentation shows what happens when the model is bypassed: instant payouts *cannot* be
reconciled because *"Stripe can't identify which transactions are included in each payout"* `[DOCS]`.

**QAYD status — Sprint 03 has the reconciliation half, not the settlement half.** S3-04's tie-out gate
(`opening + Σ lines = closing`, rejecting the entire import on mismatch) is exactly this discipline applied
to statement import `[DOCS, sprint]`. The settlement side arrives when QAYD ingests PSP payouts. See
`ARCHITECTURE.md` §7 and R-08.

---

## Summary table

| ID | Practice | QAYD status |
|---|---|---|
| PB-01 | Gross debits and credits stored separately; net derived | ✅ already, with a `CHECK` |
| PB-02 | Two phases, with an explicit unsuccessful terminal | ✅ shape exists (S3-02/03), unnamed |
| PB-03 | Available = posted inbound − pending outbound | ⬜ becomes relevant at S3-01 |
| PB-04 | Correction by reversal, never edit | ✅ trigger-enforced |
| PB-05 | Balance per currency, never a global sum | ⚠️ partial — latent multi-currency gap |
| PB-06 | Bitemporal: valid time and system time | ⚠️ axes exist, queries do not (`I-01`) |
| PB-07 | Client-supplied immutable identity | ✅ `uq_ledger_entries_journal_line` |
| PB-08 | Idempotency record commits with the fact | ❌ **GAP — specified wrongly** (R-01) |
| PB-09 | Store and replay outcomes including failures | ❌ gap (R-01) |
| PB-10 | Match on (tenant, endpoint, body fingerprint) | ⚠️ sprint plan right, API doc short (R-01) |
| PB-11 | Atomic phases and recovery points around external calls | ⬜ not needed yet; needed at dispatch |
| PB-12 | Transactional outbox, at-least-once | ❌ declared, unbuilt (`AD-14`, R-02) |
| PB-13 | Idempotent consumer, dedup inside the effect's transaction | ❌ gap, 2 points (R-03) |
| PB-14 | Refuse push ordering; provide ordered pull | ⚠️ add per-aggregate version in S2-13 (R-02) |
| PB-15 | Webhook as notification, fetch the truth | ✅ already the declared design |
| PB-16 | Signed timestamp, constant-time, raw bytes | ⬜ future (R-09) |
| PB-17 | Verify, persist, acknowledge, process | ⬜ future (R-09) |
| PB-18 | Batch object summing to one bank line | ⚠️ import half shipped (S3-04); settlement half later |
