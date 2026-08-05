# ANTI-PATTERNS — What fails in payment systems, the mechanism, and how to recognise it

Version 1.0 · 2026-07-28 · Part of `docs/research/payments/`
Companion to `BEST_PRACTICES.md`. Modelled on `04_REJECTED_PATTERNS.md`, but these are *research findings*,
not QAYD rejections — a rejection requires an entry in `04`.

---

## How to read this

Sixteen anti-patterns, `PA-01` … `PA-16`. Each carries:

- **What it is**, and **why it is tempting** — an anti-pattern nobody is tempted by is not worth writing
  down.
- **The mechanism of harm** — *how* it breaks, not that it breaks. Mechanism is what transfers.
- **How to recognise it** — in a schema, a diff, or a symptom. This is the section to read in a review.
- **Severity** — Catastrophic (money is wrong and nobody knows) · High · Medium.
- **QAYD exposure** — whether QAYD can hit this, and where.

A recurring theme: **almost every one of these fails silently.** In a payment system, the loud failures are
the cheap ones.

---

## Symptom → anti-pattern lookup

| Symptom | Look at |
|---|---|
| "The totals tie but a customer says they were charged twice" | PA-01, PA-05 |
| "Revenue looks wrong but the bank balance is right" | PA-02, PA-13 |
| "It worked for months then broke on a specific customer" | PA-09, PA-11 |
| "We're missing a day of events and don't know which" | PA-06, PA-08 |
| "The webhook signature fails intermittently / only in production" | PA-03, PA-04 |
| "Two entries where there should be one" | PA-05, PA-07 |
| "The balance is right but the FX gain is wrong" | PA-12 |
| "Reconciliation matched the wrong invoice" | PA-09, PA-11 |
| "Page 3 skipped some rows" | PA-10 |
| "It's slow, and it's slow on the busiest account" | PA-14 |
| "We can't answer a question we could have answered at write time" | PA-15, PA-16 |

---

## PA-01 — A signed net balance as the only stored quantity

**What it is.** Each account holds one number. A debit decrements it, a credit increments it.

**Why it is tempting.** It is the mental model of a bank statement, it is one column, and every balance
query is a single read. It is correct for the question "how much is there," which is the question you have
on day one.

**Mechanism of harm.** The information destroyed is **turnover**, and it is destroyed at write time and
therefore unrecoverable. An account that received 1,000,000 and paid out 999,000 and one that received
1,000 and paid nothing both hold 1,000, and they are not the same business. Everything downstream that
needs gross — percentage fees, volume tiers, VAT bases, any reconciliation against a counterparty who
reports gross, any "how much flowed through this account this month" — is now unanswerable without
re-deriving from the entry log, which is exactly the O(history) scan the net balance was supposed to
avoid. Every serious system in the corpus stores both `[DOCS]`: TigerBeetle's four unsigned counters,
Modern Treasury's `credits`/`debits`/`amount`, Stripe's `amount`/`fee`/`net`.

A second mechanism, narrower: a single signed column means every account type needs sign-direction logic
at every call site, which is a permanent source of "we got the sign backwards for liabilities" bugs.

**How to recognise it.** An accounts table with `balance NUMERIC` and no gross accumulators. A report that
cannot produce a gross figure without scanning the journal. Sign-flipping helper functions named after
account types.

**Severity — High.** Not catastrophic, because the balances are correct. But it is expensive to reverse
once history exists.

**QAYD exposure — none.** `ledger_entries` keeps all four gross amounts plus the derived signed column,
constrained. `[CODE]` See `PB-01`.

---

## PA-02 — A refund modelled as a mutation of the original charge

**What it is.** `UPDATE charges SET amount = amount - refund` or `SET status = 'refunded'` as the record of
a refund. Generalises to: any correction expressed as an edit to the original fact.

**Why it is tempting.** It keeps one row per real-world purchase, which reads naturally and makes "what is
this charge now?" a single lookup.

**Mechanism of harm.** Four, compounding.

1. **The original fact is destroyed.** Gross revenue and refunds become the same number. Every revenue
   report is now net-of-refunds with no way to separate them.
2. **The timeline collapses.** A charge in January refunded in March mutates the January row, so January's
   closed books change retroactively and silently.
3. **It cannot represent the interesting cases.** Stripe's dispute documentation states that when a
   merchant *loses* a dispute, *"no money moves from your perspective"* — because the debit occurred at
   dispute-open `[DOCS]`. A loss is the **absence of a reversal entry**. A mutable-charge model has no way
   to express "the thing that did not happen," so it must invent a status that means it, and the status
   drifts from the money.
4. **It is unreconcilable against every processor by construction.** Stripe models `dispute` and
   `dispute_reversal` as separate balance transactions with their own ids `[DOCS]`; Adyen has
   `Chargeback` / `ChargebackReversed` / `SecondChargeback` / `RefundedReversed` as distinct settlement
   rows `[DOCS]`; Square has `_REVERSED` counterparts for nearly every entry type `[DOCS]`. If the
   processor emits N events and you store one mutable row, there is no join that reconciles them.

**How to recognise it.** A `status` enum on a money row that includes past-tense outcomes (`refunded`,
`disputed`, `reversed`). An `amount` column that is ever written more than once. A refunds feature with
no refunds table.

**Severity — Catastrophic.** Revenue is misstated and the audit trail asserts the wrong history
confidently.

**QAYD exposure — structurally impossible.** `AD-07`, `P-13`, and `trg_ledger_entries_append_only` raising
`restrict_violation` on any UPDATE or DELETE `[CODE]`. This is worth noting as a place QAYD's design is
strictly ahead of most of the field.

---

## PA-03 — Verifying a signature over re-serialised JSON

**What it is.** Parse the request body into an object, then compute the HMAC over the object re-encoded
back to a string.

**Why it is tempting.** Every web framework hands you a parsed body. Getting the raw bytes usually requires
fighting middleware, and the round trip *looks* lossless.

**Mechanism of harm.** JSON round-tripping is not byte-preserving. Key order, whitespace, unicode escaping,
and numeric formatting (`1.0` vs `1`, exponent notation, trailing zeros) all change. The HMAC is over
bytes, so any of these produces a different digest. Stripe states it directly: *"Stripe requires the raw
body… Any manipulation to the raw body of the request causes the verification to fail"* `[DOCS]`. Plaid's
warning is sharper still, because it names the exact trap: the body hash *"is sensitive to the whitespace
in the webhook body and uses a tab-spacing of 2"* `[DOCS]`.

The reason this is dangerous rather than merely annoying is the **shape of the failure**: it usually works
in development (small, flat, ASCII payloads that happen to round-trip identically) and fails in production
on the payloads with unicode merchant names, nested objects, or high-precision decimals — that is, on the
real ones. And the fix under deadline pressure is almost always to disable verification "temporarily."

**How to recognise it.** `json_decode` before the signature check. A verification helper whose parameter is
an array rather than a string. A framework route that has already bound a validated request object.
Intermittent verification failures correlated with payload content rather than with time.

**Severity — High.** Either you reject valid webhooks (data loss) or you disable verification (an
unauthenticated write endpoint).

**QAYD exposure — future, on webhook ingress.** Flag it in the ingress story. See `PB-16`, R-09.

---

## PA-04 — A signature with no timestamp inside it

**What it is.** HMAC over the body alone. The timestamp, if present, travels as an unsigned header or not
at all.

**Why it is tempting.** It is the obvious design, and it authenticates the payload correctly. GitHub and
Shopify both do it `[DOCS]`.

**Mechanism of harm.** A signature that does not cover a timestamp is **valid forever**. Anyone who obtains
one delivery — proxy logs, a leaked log aggregator, a misconfigured intermediary, an SSRF that echoes a
request — can replay it indefinitely, and every replay verifies correctly. Putting the timestamp in an
unsigned header does not help: the attacker edits it.

The consumer-side consequence is the part usually missed. Without a signed timestamp there is no bounded
replay window, so **the only defence is a dedup store, and it can never expire entries.** With a signed
timestamp and a tolerance (Stripe and Plaid both use 5 minutes `[DOCS]`), the dedup store only needs to
retain ids for the tolerance window, because anything older is rejected by the signature check.
`[INFERENCE]` — this dependency between the two mechanisms is not stated in any source I found and is
worth carrying explicitly.

Two adjacent traps in the same family: a tolerance of `0` disables the recency check entirely (Stripe warns
about this specifically `[DOCS]`), and accepting *any* matching signature scheme in a multi-scheme header
converts the rotation affordance into a downgrade vector — hence Stripe's instruction to *"ignore all
schemes that aren't v1"* `[DOCS]`.

**How to recognise it.** A signed string that is exactly the request body. A dedup table with an unbounded
retention policy and no explanation of why. No clock-skew handling anywhere in the verification path.

**Severity — High.**

**QAYD exposure — future, outbound and inbound.** Adopt the Standard Webhooks shape
(`msg_id.timestamp.payload`, multiple space-delimited signatures for rotation) `[DOCS]`. See R-09.

---

## PA-05 — Storing the idempotency record outside the transaction that does the work

**What it is.** Execute the request, commit the business transaction, then write the idempotency key and
response to a cache or a separate store.

**Why it is tempting.** It is what middleware naturally does — the middleware sees the response, so it
records the response. Redis is fast and the key is transient data, so a cache seems the natural home. The
crash window is genuinely small.

**Mechanism of harm.** This is the **dual-write problem, located in the one component whose entire purpose
is to prevent duplicate money movement.** If the process dies between `COMMIT` and the write to the key
store — deploy, OOM kill, container eviction, network partition to the cache — the business fact is
durable and the key is not. The client, having received no response, retries. The retry finds no key.
It posts again.

The window is small; the consequence is a double post. That is the worst available trade, and it is
strictly worse than the alternative because the correct design costs nothing extra: put the key row in the
same database and the same transaction, and the guarantee becomes unconditional — either both exist or
neither does. This is the same argument as the transactional outbox `[DOCS]`, applied to the same class of
bug.

Two aggravating variants:

- **Recording only successful responses.** The dangerous case is precisely a request that did the work and
  then failed to report it — a 500 raised after the journal posted, a dropped connection during
  serialisation. If only successes are stored, that retry finds nothing and duplicates. Stripe explicitly
  stores results *"regardless of whether it succeeds or fails… including 500 errors"* `[DOCS]`.
- **A lock with a shorter TTL than the operation.** A 10-second lock guarding a post that occasionally
  takes 12 seconds under load means the lock expires mid-flight and a concurrent retry proceeds. The lock
  now provides its guarantee exactly when it is not needed and drops it exactly when it is.

**How to recognise it.** An idempotency middleware that writes to a cache after `$next($request)`. A key
store that is a different technology from the business database. `if (successful)` guarding the store. A
lock TTL expressed in seconds with no relationship to the operation's p99.

**Severity — Catastrophic.** Money moves twice and the system has no record that it was asked once.

**QAYD exposure — YES, and it is currently the specified design.** `docs/api/API_ARCHITECTURE.md`
specifies Redis storage keyed `idempotency:{company_id}:{key}` with a 24-hour TTL, written after the
response, guarded by a 10-second lock, and stored only `if ($response->isSuccessful())`. All three variants
are present. This is the study's most important finding. See `LESSONS_FOR_QAYD.md` §3.1 and R-01.

---

## PA-06 — Assuming webhook ordering

**What it is.** A consumer that processes events as a sequence — treating arrival order as causal order.

**Why it is tempting.** Events *are* generated in an order, they usually arrive in it, and nothing in
development ever contradicts it. Ordering assumptions are also implicit: a handler that does
`if (status === 'paid') markPaid()` has assumed ordering without anyone writing the word "order."

**Mechanism of harm.** No major provider guarantees it, and all of them say so. Stripe: *"Stripe doesn't
guarantee the delivery of events in the order that they're generated"* — with a worked example of a
subscription creation emitting four events in unpredictable order `[DOCS]`. Wise: *"If your application
processes events in the order they are received, it may end up with an inaccurate view of the current
state of a resource"* `[DOCS]`. Plaid: *"You should design your application to handle duplicate and
out-of-order webhooks"* `[DOCS]`.

Retries guarantee reordering rather than merely permitting it: an event that fails once and is retried
after 30 seconds will arrive after events generated later. So the anti-pattern is not "rare edge case
under network weirdness" — it is "the normal consequence of the retry mechanism working correctly."

The resulting corruption is durable and quiet: a `refunded` processed before the `charge` that created the
row either errors (loud, fine) or creates a phantom, and a `pending` arriving after a `succeeded` moves a
completed object backwards.

**How to recognise it.** A handler that transitions state without comparing a version or timestamp. Any
`switch (event.type)` with no guard on the current state. Integration tests that emit events in the happy
order and only that order.

**Severity — High.**

**QAYD exposure — flagged but not yet closed.** `AD-14` future risk 3 names it exactly. The fix is a
monotonic per-aggregate version on the event, and the cost of adding it in S2-13 is approximately zero
while the cost of retrofitting it across consumers is not. See `PB-14`, R-02.

---

## PA-07 — Synchronous webhook processing

**What it is.** Doing the work inside the HTTP request from the provider, returning 2xx when the work
completes.

**Why it is tempting.** Simplest possible handler, no queue, errors surface as a non-2xx which the provider
helpfully retries. It looks like the provider's retry mechanism is doing your job for you.

**Mechanism of harm.** A bimodal failure that ends in silence.

```
handler slows (a slow query, a downstream call, a traffic spike)
   → deliveries exceed the provider timeout   (Shopify 5s, Adyen/Plaid 10s)
   → provider retries
   → retries add load, handler slows further
   → provider protects its own pipeline: endpoint DISABLED (Stripe) /
     subscription DELETED (Shopify) / retries STOPPED (Plaid, at >90% rejection over 24h)
   → you are now silently receiving nothing
```

`[DOCS]` for every timeout and every protective action.

The silence is the harm. A handler that throws produces an alert; a disabled endpoint produces an absence,
and absences are not monitored unless someone decided to monitor them. By the time it is noticed, the
retention window has closed: Stripe keeps events retrievable for 30 days and resendable for 15 `[DOCS]`;
Plaid retries for 24 hours and then the event is gone permanently `[DOCS]`.

**How to recognise it.** Business logic between signature verification and the response. No queue in the
webhook path. No alert on "expected event class not seen in N hours." No dead-letter store.

**Severity — High.**

**QAYD exposure — the outbound design is already correct** (Stage 8 dispatches asynchronously). The
ingress side does not exist yet; build it as verify → persist → ack → process. See `PB-17`, R-09.

---

## PA-08 — A dedup store that cannot safely expire

**What it is.** Deduplicating deliveries on a message id, with a TTL, behind a signature scheme that has no
bounded validity.

**Why it is tempting.** Both halves are individually correct practice. Dedup on id is right; TTLs on
unbounded-growth tables are right. The interaction is the problem.

**Mechanism of harm.** The dedup store's TTL is a *replay window*. Once an id ages out, a replay of that
message verifies (the signature is still valid, because nothing bounds it) and deduplicates against
nothing. The system reprocesses it. If the handler is idempotent at the business level this is survivable;
if the handler's idempotency *was* the dedup store, it is a duplicate effect.

Standard Webhooks suggests a ~5-minute dedup window `[DOCS]` — which is safe **only because** its signed
timestamp tolerance independently rejects anything older. Copying the 5-minute number without copying the
timestamp check produces a system with a five-minute replay protection window and an infinite replay
capability. `[INFERENCE]`

**How to recognise it.** A `processed_webhooks` table with a TTL, plus a verification function that does
not look at a timestamp. A comment saying "we keep these for a week" with no stated reason for the week.

**Severity — Medium**, rising to High where the handler is not otherwise idempotent.

**QAYD exposure — future.** Bind the two decisions together in one story so neither can be changed alone.
See R-09.

---

## PA-09 — Matching by amount and date

**What it is.** Deciding *which* record something refers to by comparing values: a bank line to a
transaction, a pending charge to its posted version, a payment to an invoice.

**Why it is tempting.** It works in testing and in most of production, and when the explicit link was never
recorded it is the only signal left.

**Mechanism of harm.** QAYD already rejects this as `R-24` — the mechanism (identical amounts are
*systematic*, not random; the wrong attribution is silent and plausible; the real link was usually
discarded) is argued there and is not repeated. What this study adds is **independent confirmation from a
bank-data provider's own documentation**, which is stronger evidence than an ERP post-mortem:

> *"The pending and posted versions of a transaction may not necessarily share the same details: their
> **name and amount may change**."* `[DOCS, Plaid]`

The canonical example Plaid gives is a restaurant charge that lacks the tip while pending and includes it
once posted. So amount matching against a bank feed produces **false negatives** (the amount legitimately
changed) as well as false positives (two identical amounts). Plaid's response is to supply an explicit
`pending_transaction_id` link and to steer integrators away from doing their own matching entirely
`[DOCS]`.

Two further findings sharpen the picture for Sprint 03:

- **A posted bank transaction is not immutable.** Plaid: *"a posted transaction cannot necessarily be
  considered immutable"* — refunds and institutional recategorisation mutate posted rows `[DOCS]`.
- **The transaction id is not a stable identity for the economic event.** When a pending transaction
  posts, Plaid **removes** the pending row and **adds** a new one with a **new id** `[DOCS]`. The same
  real-world purchase occupies two different keys over its life; the durable identity is the id chained
  through `pending_transaction_id`.

**How to recognise it.** `where('amount', $x)->where('date', $y)->first()`. A matching function with an
accreting list of tie-breakers. A reconciliation that is "usually right."

**Severity — Catastrophic** (per `R-24`).

**QAYD exposure — bounded correctly by design, and worth re-reading before S3-05.** `R-24`'s exception is
precise: amount as a *feature* in a ranked, confirmed proposal is fine; amount as the deciding factor in
an automatic unconfirmed link is not. S3-05's engine matches that exception exactly — amount+date scores 30
and cannot alone reach the 90 auto-commit threshold. What Plaid adds is that even the *ranking* should
prefer an explicit external reference where the feed supplies one. See R-06.

---

## PA-10 — Offset pagination over an append-heavy collection

**What it is.** `LIMIT n OFFSET m` (or `?page=`) over a list that is being written to while a client
paginates.

**Why it is tempting.** It supports "jump to page 4," it returns a total count, and it is what every ORM
gives you by default.

**Mechanism of harm.** Offsets are positional, and positions shift under concurrent insertion. On a
descending-by-recency list — which every ledger and transaction list is — a row inserted at the head
between page 1 and page 2 pushes every subsequent row down one, so the last row of page 1 reappears as the
first row of page 2, or a row is skipped entirely. On an append-heavy financial table this is not a rare
race; it is the steady state.

Every serious payment API uses ID cursors instead. Stripe's `starting_after` / `ending_before` take an
**object ID**, not an offset, so a new object at the head does not shift subsequent pages `[DOCS]`. Mercury,
Brex and Ramp all paginate by cursor `[DOCS]`. The documented cost is exactly the one thing offsets are
good at: no `total_count`, and no jumping to page 4.

**How to recognise it.** `?page=` on a transaction, ledger, or event collection. A reconciliation import
that walks pages. Duplicate rows in a paginated export with no duplicate in the source.

**Severity — Medium** for a UI list; **High** for anything that ingests the pages into a ledger.

**QAYD exposure — partially specified.** `docs/api/API_ARCHITECTURE.md` defaults to offset pagination with
cursor available for *"high-volume, time-ordered resources."* That framing is right but the trigger is
volume; **the trigger should be append-heaviness**. `journal_lines`, `ledger_entries`, `audit_logs` and
`bank_statement_lines` should be cursor-paginated regardless of how few rows a given tenant has. See R-09.

---

## PA-11 — Retrying one page of a paginated sync

**What it is.** A sync loop that fetches pages until exhausted, and on a failed page retries **that page**
and continues.

**Why it is tempting.** It is what every retry wrapper does, and it is correct for independent requests.

**Mechanism of harm.** A cursor-based change feed is a **snapshot across the whole loop**, not a sequence
of independent reads. If the underlying data mutates mid-pagination, resuming from the failed page silently
mixes two snapshots — some rows from before the mutation, some after, and some in neither.

Plaid documents this explicitly, and the wording is unusually direct because the naive fix is wrong:
`TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION` requires that *"the entire pagination request loop must be
restarted beginning with the cursor for the first page of the update, rather than retrying only the single
request that failed"* `[DOCS]`.

The design implication is the transferable part: **the cursor for page 1 must be retained until the whole
loop commits**, and the loop's result must be applied atomically or not at all. That is the same discipline
as S3-04's tie-out gate — a partial import is worse than no import.

**How to recognise it.** A generic retry decorator wrapped around a paginated fetch. A sync that persists
the cursor after each page rather than after the loop. Missing rows that appear on a later full resync.

**Severity — High.** Silent gaps in ingested financial data.

**QAYD exposure — becomes real when any feed-based import lands.** S3-04 is single-file CSV and immune.
Note the pattern for the deferred Open Banking channel. See R-05.

---

## PA-12 — Balancing across currencies

**What it is.** Asserting that an entry balances by summing all lines' debits and credits, without
partitioning by currency.

**Why it is tempting.** It is one `SUM`, it is what "balanced" means colloquially, and it is correct for
every single-currency entry, which is most of them.

**Mechanism of harm.** Modern Treasury names the failure precisely: it permits *"transactions that appear
balanced overall while actually losing money in specific currencies"* `[DOCS]`. An entry of +100 USD and
−100 EUR sums to zero. The invariant reports success and the money is gone. This is not a rounding
concern; it is a whole-amount loss that the check is structurally blind to.

The corpus's answer is to make it inexpressible rather than merely checked. TigerBeetle's `ledger` field
partitions which accounts may transact at all, so a cross-currency transfer cannot be written as one
transfer and must be a linked chain through an FX position pair `[DOCS]`. Modern Treasury enforces
per-currency balancing as a rule `[DOCS]`.

**How to recognise it.** A balance assertion that reads `SUM(debit) == SUM(credit)` with no `GROUP BY
currency`. A journal entry that permits mixed `currency_code` across lines with only a base-currency
check. An FX conversion posted as a single line pair.

**Severity — Catastrophic** where multi-currency entries are reachable; **latent** where they are not.

**QAYD exposure — real but currently unreachable.** `assertBalanced` requires exact equality in *both* the
entry currency and the base currency, which catches the single-currency and base-currency cases. It sums
`debit`/`credit` across all lines regardless of each line's `currency_code`, so a genuine three-currency
entry balancing in base but not per-currency would pass `[CODE]`. No caller constructs such an entry today.
Close it before FX lands. See `LESSONS_FOR_QAYD.md` §3.3, R-06.

---

## PA-13 — Booking from bank deposits alone under net settlement

**What it is.** Recognising revenue from what arrives in the bank account.

**Why it is tempting.** The bank statement is the most trustworthy document available, and the deposit is
unambiguous.

**Mechanism of harm.** Under net settlement — which is what Stripe, Adyen and Square all do by default
`[DOCS]` — a deposit is *gross minus fees*, aggregated across hundreds of transactions, spanning a
cut-off boundary, offset by refunds and disputes, and possibly reduced by reserves. Revenue and
cost-of-revenue arrive **fused into a single number**, and the only place they are separable is the
processor's itemised report.

So a business booking from deposits alone has *permanently* lost the ability to state gross revenue or fee
expense. Not "must compute it later" — the information never entered the system. The processor's
reconciliation report is therefore not a convenience feature; it is the sole source of the decomposition,
which is why every processor in the corpus publishes one with per-transaction `gross` / `fee` / `net`
columns `[DOCS]`.

Adyen goes furthest and shows what is at stake: where the acquirer supplies the detail, its cost splits
into `Interchange`, `Scheme Fees` and `Markup` as separate columns; where it does not, the blended total
lands in `Commission` and the other three are empty `[DOCS]`. Same money, different analytical resolution
— and the resolution is fixed at ingestion.

**How to recognise it.** A revenue figure derived from bank credits. No fee expense account. A P&L whose
gross revenue moves when a processor changes its pricing.

**Severity — High.** The books are internally consistent and materially misstated.

**QAYD exposure — a future product requirement, not a current defect.** QAYD does not ingest PSP
settlements yet. When it does, the itemised report is the source and the deposit is the *proof*, not the
entry. See `ARCHITECTURE.md` §7 and R-08.

---

## PA-14 — Pessimistic row locks on hot accounts

**What it is.** `SELECT … FOR UPDATE` on the shared account — cash, revenue, a settlement omnibus — on
every posting.

**Why it is tempting.** It is the correct and obvious way to serialise concurrent balance updates, and it
is exactly right for the *user's* account.

**Mechanism of harm.** Business transactions are not uniformly distributed across accounts. Every sale
touches the same revenue account; every receipt touches the same cash account. So the shared account
becomes a global serialisation point, and throughput converges on `1 / lock_hold_time` regardless of how
many workers exist. This is the most consistently named scaling problem in the corpus:

- **TigerBeetle** cites hot-account contention as the primary reason it rejected general-purpose SQL:
  business transactions touch shared accounts, *"the resulting contention can bring the system's
  performance to a crawl,"* and *"business transactions don't shard well"* `[DOCS]`.
- **Modern Treasury** prescribes the practical mitigation: *"it is best practice to ensure that the hot
  account receives only asynchronous entries, so that it can perform at high throughput."* Lock the
  low-throughput side, never the shared one `[DOCS]`.
- **Stripe** surfaces the same discipline to its own clients: an object cannot be accessed *"because
  another API request or Stripe process is currently accessing it,"* with the recommendation to serialise
  mutations on a single object `[DOCS]`.

**How to recognise it.** A lock acquired on an account row during posting. Latency that degrades with
*business* volume rather than with tenant count. p99 concentrated on the busiest accounts.

**Severity — Medium** at QAYD's scale; **High** at the scale where it appears, and it appears suddenly.

**QAYD exposure — none today, and the design already avoids it.** Balances are `SUM(signed_base_amount)`
over an append-only projection, so posting inserts and never updates an account row `[CODE]`. Locks are
scoped to the journal entry being posted, not to the accounts it touches — which is `AD-08`'s "smallest
resource that requires serialization," and is exactly right. **The risk arrives with a cached balance
table.** `AD-20` already gates that (a cached aggregate is permitted only over an append-only source, and
every projection ships a rebuilder); the payments corpus adds: if that cache ever becomes an
`UPDATE balances SET` under a lock, this anti-pattern arrives with it.

---

## PA-15 — A fat event payload treated as a stable contract

**What it is.** Publishing the full aggregate in the event, so consumers do not have to re-fetch.

**Why it is tempting.** It removes a round trip, works offline, and is a point-in-time record.

**Mechanism of harm.** Two, and the second is the expensive one.

*Staleness.* An unordered channel retried for up to three days cannot carry current truth. Stripe's own
framing concedes it: snapshot events are *"a point-in-time view"* for integrations that *"can tolerate
working with eventually-consistent data"* `[DOCS]`.

*Contract lock-in.* **A payload is a published schema, and a published schema is a permanent compatibility
obligation.** Once a consumer reads twelve fields, all twelve are frozen. Stripe's snapshot events are
pinned to an API version on both the destination and the client, so an upgrade is a two-sided coordinated
change; their documented migration is dual-write — run two endpoints, *"every event is sent twice,"* then
cut over `[DOCS]`. Thin events exist largely to escape this: they are unversioned, and *"this eliminates
the need to update webhook handlers when upgrading API versions"* `[DOCS]`.

QAYD's knowledge base independently identified the internal form of this: `AD-14` future risk 2 — *"event
payloads growing into DTOs of the whole aggregate, recreating coupling through the payload: a consumer
that reads twelve fields is coupled to twelve fields."*

**How to recognise it.** An event class whose constructor takes a model. A payload that grew a field
because a consumer asked. A version bump on an event that nobody can perform because three consumers read
it.

**Severity — Medium**, compounding over years.

**QAYD exposure — guarded, and S2-13 is the test.** The story specifies a *"compact projection"* for the
`journal.posted` broadcast `[DOCS, sprint]`. Keep it compact: ids, a version, and the minimum a screen
needs to decide whether to re-fetch.

---

## PA-16 — Discarding the intent that was available at write time

**What it is.** Computing something for display and not persisting it — then reconstructing it later by
inference.

**Why it is tempting.** At the moment of writing, the derived value seems obvious and re-derivable. Storage
of "obvious" columns feels redundant.

**Mechanism of harm.** This is the root cause behind PA-09, and `R-24` documents QAYD's canonical instance
(Odoo computing allocation intent for the UI and discarding it, so *"which installment did this payment
target?"* becomes unanswerable). The payments corpus supplies three more instances of the same shape:

- **Plaid's `days_requested`** is set once at Item creation and *"cannot be updated if Transactions has
  already been added to the Item"* — obtaining more history requires removing the Item and re-linking
  through the user-facing flow `[DOCS]`. A one-shot irreversible decision, made before anyone knows how
  much history they will want.
- **Stripe's instant payouts** cannot be reconciled at all because *"Stripe can't identify which
  transactions are included in each payout"* `[DOCS]` — the membership was known at payout time and not
  recorded, so no later report can recover it.
- **Adyen's blended `Commission`** where interchange detail was unavailable at ingestion `[DOCS]`: the
  decomposition is not recoverable afterwards.

The general form: **a fact that is cheap to record at write time and impossible to derive at read time
must be recorded.** The test is not "is this derivable in principle" but "is the input still available
later."

**How to recognise it.** A report that reconstructs a relationship by heuristic. A `NULL`able foreign key
that is "usually obvious." A design discussion that concludes "we can always work that out later."

**Severity — High.** The loss is permanent and is discovered when someone asks the question.

**QAYD exposure — governed by `R-24`.** The transferable addition from this study is to apply the same test
to *configuration* decisions, not only to relationships: any ingestion parameter that cannot be changed
after the fact (a history window, a mapping, a rounding rule) should be recorded with the data it shaped.

---

## Summary table

| ID | Anti-pattern | Severity | QAYD exposure |
|---|---|---|---|
| PA-01 | Signed net balance as the only stored quantity | High | None |
| PA-02 | Refund as a mutation of the charge | Catastrophic | Structurally impossible |
| PA-03 | Signature over re-serialised JSON | High | Future (ingress) |
| PA-04 | Signature with no timestamp inside it | High | Future (both directions) |
| PA-05 | Idempotency record stored outside the transaction | **Catastrophic** | **YES — currently specified** |
| PA-06 | Assuming webhook ordering | High | Flagged (`AD-14`), open |
| PA-07 | Synchronous webhook processing | High | Outbound correct; ingress unbuilt |
| PA-08 | Dedup store that cannot safely expire | Medium–High | Future |
| PA-09 | Matching by amount and date | Catastrophic | Bounded by `R-24`; re-read before S3-05 |
| PA-10 | Offset pagination over append-heavy data | Medium–High | Partially specified |
| PA-11 | Retrying one page of a paginated sync | High | Future (feed imports) |
| PA-12 | Balancing across currencies | Catastrophic (latent) | Real, currently unreachable |
| PA-13 | Booking from bank deposits under net settlement | High | Future (PSP settlement) |
| PA-14 | Pessimistic locks on hot accounts | Medium | None today; arrives with a balance cache |
| PA-15 | Fat event payload as a stable contract | Medium | Guarded; S2-13 is the test |
| PA-16 | Discarding intent available at write time | High | Governed by `R-24` |
