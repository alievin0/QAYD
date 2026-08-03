# LESSONS FOR QAYD — What transfers, what does not, and why

Version 1.0 · 2026-07-28 · Part of `docs/research/payments/`
The only two files in this set that make claims about QAYD are this one and
`IMPLEMENTATION_RECOMMENDATIONS.md`. Read both before starting S2-13 or any Sprint 03 story.

Claims about QAYD's current state are graded `[CODE]` (read from `apps/api`), `[DOCS, spec]` (read from a
QAYD document) or `[DOCS, sprint]` (read from a sprint plan).

---

## 0 · The short version

**Three things to act on, in this order:**

1. **`R-01` — The idempotency layer specified in `docs/api/API_ARCHITECTURE.md` has a dual-write hole.**
   It stores the key in Redis, after the transaction, only on success, under a 10-second lock. Each of
   those three choices independently permits a double post. This needs an ADR, and it is S2-13's actual
   content. §3.1.
2. **`R-02` — Build the outbox with S2-13, not after it.** `AD-14` says the gap *"must be closed before
   the second consumer exists."* S2-13 creates the second consumer. §3.2.
3. **`R-06` — Two small, latent schema gaps found while cross-checking.** A multi-currency entry can
   balance in base but not per-currency; and `journal_lines.reconciled` / `reconciled_at` are `R-15`-shaped
   columns that no code writes and that the immutability trigger makes unwritable when they would matter.
   §3.3, §3.4.

**And one finding that changes how Sprint 03 should be framed:** there is no bank-data API for Kuwait, at
all, from anyone. CSV statement import is not the MVP compromise — it is the product. §4.

---

## 1 · Where QAYD is already ahead

This section exists because a research document that only ever recommends changes is a sales pitch. On five
of the eight defining splits in `OVERVIEW.md` §2, QAYD is already on the side the corpus converged on, and
in two cases QAYD's enforcement is **stronger** than what the payment companies publish.

### 1.1 Exactly one writer, enforced at a chokepoint

Adyen's published architecture makes the strongest claim in the corpus: *"The only way to add new records
to the accounting system is by means of templates,"* which are formally verified to net to zero, so
*"if at any time, we sum up all the records in the accounting system, the result will always be 0."*
`[DOCS]`

QAYD reaches the same invariant by a different route. `AD-04` makes `JournalEntryPostingService` the one
code path authorised to write a posted line, and it **re-derives the balance from the lines themselves —
never the cached header totals — with zero tolerance, in both the entry currency and the base currency**
`[CODE]`. Adyen proves the invariant over the generator ahead of time; QAYD asserts it at the gate every
time.

Both are valid. QAYD's is cheaper to build and cheaper to reason about; Adyen's scales to hundreds of
event types written by teams who never see the ledger. **The point at which QAYD should reconsider is when
the number of distinct `JournalDraft` constructors exceeds what one reviewer can hold in their head** —
`ClearBankTransactionAction` is the second (S3-03), and every subsequent module adds one. That is `I-07`
(the Posting Firewall), and this study raises confidence in it.

### 1.2 Gross and net, both stored, with the derived column constrained

`ledger_entries` carries `debit_amount`, `credit_amount`, `base_debit_amount`, `base_credit_amount` and
`signed_base_amount`, with `CHECK (signed_base_amount = base_debit_amount - base_credit_amount)` and a
`CHECK` forbidding a two-sided line `[CODE]`.

This is `PB-01` done properly, and the constraint is the part most designs miss. TigerBeetle keeps four
gross counters and derives the net in the application; Modern Treasury exposes `credits`, `debits` and a
derived `amount`. QAYD stores the derived value — which is faster to read — **and binds it to its sources
with a database constraint**, so the classic "derived column drifted from its inputs" bug is structurally
impossible. No system in the corpus documents doing both.

### 1.3 Append-only enforced by a trigger, not a convention

`trg_ledger_entries_append_only` raises `restrict_violation` on any `UPDATE` or `DELETE`, independent of
the application layer, and `fn_block_update_when_posted` does the same for lines of a terminal-state entry
`[CODE]`. `AD-07` and `P-13` make correction-by-reversal the only path.

Every system in the corpus reaches the same conclusion (`PB-04`), but they enforce it in application code
or in an API layer. QAYD enforces it in the storage engine, where an application bug — or a raw SQL
statement under a privileged role — cannot get around it. That is a genuinely stronger position.

### 1.4 Idempotent projection as a uniqueness constraint

`uq_ledger_entries_journal_line UNIQUE (journal_line_id)` is documented in the migration as *"the DB
backstop that a line can be projected at most once (idempotent posting)"* `[CODE]`.

This is `PB-07` — TigerBeetle's client-supplied-immutable-identity idea — applied at the projection
boundary. **Posting is already idempotent at the database level regardless of what any middleware does.**
This matters enormously for §3.1: even if the request-level idempotency layer fails, a duplicate *post of
the same entry* cannot double-project. What it does not protect against is a duplicate *creation and
posting of a second entry*, which is the case the request-level layer exists for.

### 1.5 Locks scoped away from hot accounts

The posting engine row-locks the journal entry header `FOR UPDATE` and never locks an account row `[CODE]`.
Balances are `SUM(signed_base_amount)` over an append-only projection, so posting inserts and never updates
an account.

`PA-14` is the most consistently named scaling failure in the corpus — TigerBeetle rejected general-purpose
SQL over it; Modern Treasury prescribes keeping hot accounts asynchronous. QAYD does not have the problem,
because `AD-08`'s "smallest resource that requires serialization" rule put the lock in the right place.
**The risk arrives with a cached balance table**, which `AD-20` already gates.

### 1.6 The webhook philosophy is already right

`AD-14`: *"Realtime push (Reverb) is a notification to refresh authoritative state, never a second write
path."* S2-13 specifies a *"compact projection an open ledger screen consumes to re-fetch"* `[DOCS, sprint]`.

That is `PB-15` — the position Stripe is migrating toward with thin events, having shipped fat snapshot
payloads first and discovered the versioning cost. QAYD gets to start where Stripe is arriving.

---

## 2 · What transfers, and where it lands

### 2.1 Two-phase money — Sprint 03 already has the shape; name it

`bank_transactions` runs `draft → … → cleared → reconciled`, with a DB `CHECK` that `cleared`/`reconciled`
implies a non-null `journal_entry_id`, and `ClearBankTransactionAction` posting through the Accounting
Service inside one transaction under a row lock `[DOCS, sprint]`.

That is structurally the pending/posted split of `PB-02`, with the posting proof enforced by the database.
Three refinements from the corpus, none of which change the design:

- **The third outcome must be explicit.** All five systems distinguish *resolved successfully* from
  *resolved unsuccessfully* — post vs void, posted vs archived, booked vs refused. `BANKING_SERVICE.md`'s
  state machine includes failure states; make sure a transaction that will *never* clear has a terminal
  state rather than sitting in `pending_clearance` forever. Otherwise phantom obligations accumulate.
- **Consider a reservation timeout.** TigerBeetle gives a pending transfer an optional timeout in seconds
  after which the reservation self-releases `[DOCS]`. QAYD's equivalent — a stale-draft or
  stale-pending-clearance report — is cheaper than a mechanism and achieves most of it.
- **The ledger correctly has no pending state.** QAYD posts facts. A promise lives in `bank_transactions`;
  only a cleared fact reaches `ledger_entries`. This is right, and it is why `I-19` (the Provisional
  Ledger) is framed as a *separate* store rather than a status column.

### 2.2 The available-balance formula, before it is needed

S3-01 gives `bank_accounts` a running balance. The moment an uncleared transaction can affect a displayed
figure, `PB-03` applies:

```
available = posted_inbound − pending_outbound
```

Outbound counts at promise; inbound counts at settlement. Modern Treasury, Adyen, TigerBeetle and Wise all
land here `[DOCS]`, and the reason is asymmetric failure consequences, not general caution: counting a
pending inbound that then fails means the money was already spent.

**Record the decision in S3-01 even though S3-01 does not need it**, because the alternative is that
someone chooses the other formula later without knowing there was a choice.

### 2.3 `R-24` gets independent confirmation from a bank's own data

`R-24` (amount equality used as identity) is the most important rejection in the knowledge base for Sprint
03, and its evidence to date is a post-mortem of Odoo's payment allocator. This study supplies stronger
evidence — a bank-data provider documenting that its *own* feed contradicts amount matching:

> *"The pending and posted versions of a transaction may not necessarily share the same details: their
> **name and amount may change**."* `[DOCS, Plaid]`

The canonical example is a restaurant charge without the tip while pending and with it once posted. So
amount matching against a bank feed produces **false negatives** (the amount legitimately changed) as well
as the false positives `R-24` already describes. Two further facts sharpen S3-04 and S3-05:

- **A posted bank transaction is not immutable.** *"A posted transaction cannot necessarily be considered
  immutable"* — refunds and institutional recategorisation mutate posted rows `[DOCS]`.
- **The bank's transaction id is not a stable identity for the economic event.** When a pending
  transaction posts, the pending row is *removed* and a *new* row with a *new* id is added, linked by an
  explicit `pending_transaction_id` — and only when the provider itself matched them `[DOCS]`.

**S3-05's design already satisfies `R-24`'s exception correctly**: amount+date scores 30, which cannot
alone reach the 90 auto-commit threshold, and every committed match records `rules_fired`. What this study
adds is a preference ordering — where a statement line carries an explicit reference, that reference should
dominate the ranking, and where a re-import supplies a revised version of a line already matched, the
system needs a defined behaviour rather than a duplicate. See R-05.

### 2.4 Ordering: put a version on the event now

`AD-14` future risk 3 already names it: *"At-least-once says nothing about order; a consumer that needs
per-aggregate ordering must get a version on the event and reject out-of-order, not assume."*

The corpus is unanimous and explicit — Stripe, Wise, Adyen and Plaid all refuse ordering guarantees in
writing `[DOCS]`. Retries *guarantee* reordering rather than merely permitting it, because an event
retried after 30 seconds arrives after events generated later.

The transferable structure is the pairing: **push for latency, pull for order.** Stripe's push channel is
unordered and its Events API pull channel is explicitly chronological `[DOCS]`. QAYD's equivalent is the
Reverb broadcast (unordered notification) plus the ledger API (authoritative, ordered) — which is already
the design; it just needs the version field so a consumer can detect staleness.

**Cost of adding it in S2-13: effectively zero. Cost of retrofitting across consumers: not zero.**

### 2.5 The clearing account, for when settlement arrives

`ARCHITECTURE.md` §7 develops the three-way match. The part that transfers to QAYD's roadmap rather than to
Sprint 03:

- **Fees must be booked at the transaction moment as a separate expense line**, never netted at deposit,
  or gross revenue is permanently unrecoverable (`PA-13`). Under net settlement the deposit fuses revenue
  and cost-of-revenue into one number, and the processor's itemised report is the *only* source of the
  decomposition.
- **A funds-in-transit control account** whose balance should equal the processor's pending-plus-available
  balance, with the difference being the entire exception queue.
- S3-06's `variance = book_balance − adjusted_bank_balance` is that difference by another name. **The same
  variance machinery covers PSP settlement later** — which is an argument for building S3-06's variance
  computation generically rather than specifically to bank statements.

### 2.6 API surface refinements, for Sprint 04+

`docs/api/API_ARCHITECTURE.md` is already a strong document. Three refinements from `ARCHITECTURE.md` §9:

- **Cursor-paginate append-heavy collections regardless of volume.** The spec offers cursor pagination for
  *"high-volume, time-ordered resources."* The correct trigger is **append-heaviness**, not volume:
  `journal_lines`, `ledger_entries`, `audit_logs` and `bank_statement_lines` are unstable under offset
  pagination even with few rows, because insertion at the head shifts every page (`PA-10`).
- **Publish the additive-change contract.** Stripe's list of backwards-compatible changes is
  simultaneously a definition and a contract on the client — ignore unknown fields, ignore unknown event
  types, never parse or length-bound an id `[DOCS]`. Saying this explicitly is what makes it safe to ship
  additively forever. QAYD's versioning section should state it.
- **Consider the information-leakage rule in the error taxonomy.** Stripe deliberately tells the integrator
  more than the integrator may tell the end user (fraud-related declines must be presented as generic)
  `[DOCS]`. QAYD will have the same need — an AI-flagged anomaly, a failed sanctions check — and the
  distinction between machine-readable diagnosis and human-facing message is easier to build in than to
  retrofit.

---

## 3 · The real gaps

### 3.1 The idempotency layer has a dual-write hole — and it is specified, not accidental

**This is the study's most important finding.**

`docs/api/API_ARCHITECTURE.md` specifies the middleware as: check Redis at
`idempotency:{company_id}:{key}`; acquire a 10-second cache lock or `409`; execute; and — *only*
`if ($response->isSuccessful())` — write the status, body and headers to Redis with a 24-hour TTL
`[DOCS, spec]`.

Three independent defects, each of which alone permits a double post:

**(a) The key is stored outside the transaction, after commit.** This is `PA-05`, and it is the dual-write
problem located in the one component whose entire job is preventing duplicate money movement:

```
BEGIN ── post journal ── COMMIT ──┬────── Redis SETEX ──▶
                                  │
                               ╳ crash / deploy / OOM / network partition to Redis
                                  │
                  fact is durable, key is NOT
                  client (no response) retries → finds no key → POSTS AGAIN
```

The window is small; the consequence is a duplicate financial event. Meanwhile the correct design costs
nothing extra: an `idempotency_keys` table written **in the same transaction** as the post makes the
guarantee unconditional — either both exist or neither does. This is exactly the argument `AD-14` and
`P-11` already make for the outbox, applied to the same class of bug. QAYD has the argument; it has not
yet applied it here.

**(b) Only successful responses are stored.** The dangerous case is precisely a request that *did* the work
and then failed to report it — a 500 raised after `COMMIT`, a dropped connection during serialisation, a
timeout in an after-commit listener. Under the specified design that retry finds no key and executes again.
Stripe stores results *"regardless of whether it succeeds or fails… including 500 errors"* `[DOCS]`. The
ergonomic cost is real (a client must mint a new key to genuinely retry a transient failure) and it is the
correct trade.

**(c) The lock is 10 seconds and the operation is unbounded.** A post that occasionally takes longer than
the lock under load releases the lock mid-flight, and a concurrent retry proceeds. The lock provides its
guarantee when it is not needed and drops it when it is. The reference design solves this with a
`locked_at` column plus expiry-and-reap rather than a fixed TTL `[COMMUNITY]`.

**A fourth issue, smaller: the spec and the sprint plan disagree.** The API document scopes the key
`(company_id, key)` and conflicts on body hash. **S2-13 specifies `(company_id, endpoint, key)**`
`[DOCS, sprint]`. The sprint plan is right — Stripe's `idempotency_error` is defined as reuse *"on a
request that does not match the first request's **API endpoint and parameters**"* `[DOCS]`. Without the
endpoint in the key, one endpoint's response can be replayed as another's.

**Mitigating context, stated honestly:** `uq_ledger_entries_journal_line` means a duplicate *post of the
same entry* cannot double-project (§1.4). The exposure is a duplicate *create-and-post*, and a duplicate
transfer or payment dispatch once those exist — which is precisely the endpoint list the spec's own table
enumerates.

**This requires an ADR** (MANIFEST Law 1), because it contradicts a written specification. → **R-01**.

### 3.2 The outbox is declared, unbuilt, and S2-13 creates the second consumer

`AD-14` is unusually direct about this: the gap *"must be closed before the second consumer exists — at
one consumer the failure is recoverable by replay, at several it is a silent divergence between modules."*
`P-11` costs it at 5 points and rates confidence **Medium** on the outbox versus **High** on after-commit
emission.

S2-13 adds the Reverb broadcast as a consumer of `accounting.journal.posted`. S3-03 adds
`bank.transaction.cleared`. S3-07 adds the `events-ai` queued relay. **Three consumers land in the next two
sprints**, and the trigger `AD-14` set has fired.

The payments corpus adds nothing new to the argument — it is unanimous that the outbox buys atomicity and
that exactly-once does not exist `[DOCS]` — but it does supply the missing consumer-side half: the
**idempotent consumer** with the dedup insert inside the effect's transaction (`PB-13`). QAYD's knowledge
base specifies the producer side thoroughly and does not yet specify the consumer side. → **R-02, R-03**.

### 3.3 A multi-currency entry can balance in base but not per-currency

Verified in code and schema:

- `journal_lines.currency_code CHAR(3) NOT NULL`, with **no CHECK, trigger, or FK tying it to
  `journal_entries.currency_code`** `[CODE]`.
- `JournalEntryPostingService::assertBalanced()` sums `debit`/`credit` and `base_debit`/`base_credit`
  across *all* lines and requires exact equality in both — **without partitioning by each line's
  `currency_code`** `[CODE]`.

So an entry with lines in USD and EUR that balances in base currency passes. Modern Treasury names this
failure exactly and enforces per-currency balancing to prevent *"transactions that appear balanced overall
while actually losing money in specific currencies"* `[DOCS]`; TigerBeetle makes it inexpressible by
partitioning transactability on the `ledger` field `[DOCS]`.

**Severity assessment, honestly:** this is **latent, not live.** The design assumption is one currency per
entry (the header carries `currency_code`, and the exception is constructed with it), and no caller
constructs a mixed-currency entry today. But nothing in the schema or the engine *enforces* the assumption,
and the moment FX revaluation or a multi-currency invoice lands, the assertion is silently insufficient.

**The cheap fix is a CHECK, not an engine change:** constrain `journal_lines.currency_code` to equal its
parent's, which makes mixed-currency entries impossible and leaves the current assertion exactly correct.
If genuine multi-currency entries are ever wanted, the constraint is the thing you deliberately drop, and
at that moment the per-currency assertion becomes mandatory rather than optional. → **R-06**.

### 3.4 `journal_lines.reconciled` — a live column with no code path

`journal_lines` carries `reconciled BOOLEAN NOT NULL DEFAULT false` and `reconciled_at TIMESTAMPTZ NULL`
`[CODE]`. They are cast on the model. **No application code, no test, and no factory writes either
column** — verified by grep across `apps/api/app`, `apps/api/tests` and `apps/api/database/factories`
`[CODE]`.

Three reasons this should be removed rather than left:

1. **It is the exact shape `R-15` rejects.** `04_REJECTED_PATTERNS.md`'s symptom lookup lists
   `reconciled` added to a ledger row as `R-15` by name, and `R-15` is described as *"the single most
   consequential entry in this document."*
2. **It is structurally unwritable when it would matter.** `fn_block_update_when_posted` rejects any
   `UPDATE` on a line whose parent entry is `posted`, `reversed`, `voided` or `archived` `[CODE]`.
   Reconciliation state is only meaningful for *posted* lines. So the column can only be set while the
   entry is a draft — which is meaningless.
3. **Sprint 03 builds the correct alternative.** `bank_reconciliation_matches` is a proper side table
   recording `rules_fired`, `final_score` and `match_method` `[DOCS, sprint]`, which is what the corpus
   does too (matches are rows, not flags — Stripe, Adyen and Square all model reconciliation membership as
   a join key on a separate report, never as a mutable flag on the money row).

This is the precise failure mode `08_MASTER_BACKLOG.md`'s intake rule cites as the thing to avoid:
*"Odoo's `secure_sequence_number` — a live column, a dead code path, written only by tests — is what that
looks like at year fifteen."* Here it is at year zero, and it is cheaper to remove now than ever again.
→ **R-06**.

### 3.5 What is missing entirely, and correctly so

Not gaps — deliberate absences, recorded so they are not mistaken for oversights:

- **No settlement or payout module.** Correct: QAYD has no PSP integration. The design shape is in
  `ARCHITECTURE.md` §7 for when it does.
- **No inbound webhook ingress.** Correct: nothing sends QAYD webhooks yet. `PB-16` and `PB-17` apply when
  something does.
- **No bitemporal query surface.** `ledger_entries` already carries `entry_date` (valid time), `posted_at`
  and `created_at` (system time) `[CODE]` — **the axes exist**. What is missing is as-of querying and
  version history, which is `I-01`. This study raises confidence in `I-01` and lowers its estimated cost,
  because the data model does not need to change.

---

## 4 · What does NOT transfer

A study that recommends everything it read is useless. Five things in the corpus are wrong for QAYD.

### 4.1 A purpose-built ledger database

TigerBeetle's model is the most precise in the corpus and its arguments against general-purpose SQL are
real: hot-account row-lock contention, *"business transactions don't shard well,"* a target of a million
transactions per second `[DOCS]`.

**None of it applies to QAYD.** QAYD has zero production customers and a scale plan (`05_FUTURE_
ARCHITECTURE.md`) that tops out four orders of magnitude below TigerBeetle's design point. More
importantly, `AD-01` and `AD-02` make PostgreSQL the *integrity* layer — RLS multi-tenancy, CHECK
constraints, immutability triggers — and a second datastore would either duplicate those guarantees or
abandon them. TigerBeetle has no RLS, no multi-tenancy model, and a fixed schema that cannot express a
chart of accounts.

**What transfers is the data-model reasoning, not the database:** four gross counters, client-supplied
identity, immutable transfers, currency partitioning. QAYD already has three of the four.

### 4.2 PSP-scale sharding and availability splits

Adyen round-robins across accounting clusters and deliberately decouples payment authorisation from
accounting so accounting downtime cannot block acceptance `[DOCS]`.

QAYD has the opposite requirement. An accounting system whose ledger is unavailable should **refuse to
record**, not accept-and-reconcile-later. `AD-18` (nothing is silently corrected) and `R-30` (silent
degradation) both point the other way. Adyen can afford eventual consistency between acceptance and
accounting because the money movement is real regardless; QAYD's ledger *is* the record.

### 4.3 Two-phase transfers as the primary ledger model

Every payment system in the corpus models money as pending-then-posted at the *ledger* level. QAYD should
not.

The reason is a difference in what the system is the authority on. A payment processor's ledger is the
authoritative record of money it is actually moving, so a promise is a real state of real money. QAYD's
ledger is the record of a business's *accounting facts*, and an uncleared bank transaction is not yet an
accounting fact — it is a document in a workflow. `AD-03` and `AD-04` are right that only posted facts
reach the ledger, and `R-15` is right that reconciliation state does not belong on the ledger row.

**The two-phase shape belongs where Sprint 03 already put it** — in `bank_transactions`, upstream of the
posting boundary. That is the correct translation, and it is worth stating explicitly so nobody "improves"
the ledger with a pending status later. `I-19` (the Provisional Ledger) is the sanctioned way to express
predicted-but-unposted, and it is deliberately a *separate store*.

### 4.4 Fraud and risk machinery

Documented for completeness in the corpus and deliberately excluded from this study. QAYD is not a PSP and
will never underwrite card risk. The two places it surfaces are already in the plan for the right reasons:
the bank-account verification gate in S3-01 (the structural defence against "add attacker-controlled
account, pay it") and the two-key approval chain in `BANKING_SERVICE.md`.

### 4.5 The flat-transaction-feed model of the modern fintechs

Mercury, Brex and Ramp — the closest comparables to QAYD's category — all expose a **flat, signed
transaction feed with no double-entry primitive**. Ramp models GL accounts, cost centres, tax codes and an
explicit `sync_status` state machine for the ERP handoff, but defers the actual journal entries to
NetSuite, QuickBooks or Xero `[DOCS]`.

This is worth stating plainly because it is a *positioning* finding, not just an engineering one: **the
category QAYD is entering has largely decided that double entry is somebody else's problem.** QAYD's
decision to own it is the differentiator, and it is also why none of those three has anything to teach
about ledgers. Their APIs are worth studying for cursor pagination, idempotency headers and webhook
semantics; their data models are not.

---

## 5 · The GCC finding, and what it means for Sprint 03

Developed with evidence in `OVERVIEW.md` §4. Three verified negatives:

1. **No aggregator covers Kuwait.** Plaid operates in North America, the UK and Europe — no MENA coverage
   at all `[DOCS]`. No comparable provider was found with Kuwaiti coverage.
2. **Kuwait has no open banking regime.** The Central Bank of Kuwait licenses e-payment providers (EPSP,
   EMSP, EPSO) and runs a sandbox, but has **no account-information-service licence, no
   payment-initiation licence, no third-party-provider framework, and no API standard** `[DOCS]`. The
   regime licenses *moving* money and is silent on *reading* bank data. Bahrain (mandatory since 2019) and
   Saudi Arabia (SAMA framework with published API specifications and a conformance lab) are years ahead
   `[DOCS]`.
3. **KNET publishes no public API.** No documentation was found, and every global PSP that fronts KNET
   describes integration as requiring a local contract mediated through them. `knet.com.kw` returned HTTP
   403 on every attempt, so the absence is strongly circumstantial rather than positively confirmed
   `[UNKNOWN]`.

**Three consequences for how Sprint 03 is framed:**

**S3-04 is the product, not the stopgap.** The sprint plan lists MT940, CAMT.053, XLSX, PDF-OCR and the
Open Banking feed as deferred. For a Kuwaiti SME, **the Open Banking feed has no rail to connect to**, and
CSV is what a corporate banking portal actually exports. The deferral list should distinguish
*"deferred until we have capacity"* (MT940, CAMT.053, XLSX — real formats real banks export) from
*"deferred until a rail exists"* (Open Banking). Those are different kinds of not-yet, and conflating them
puts effort in the wrong place.

**Per-bank saved column mappings are a moat, not a convenience.** In a market with no standard feed, a
library of working mappings for NBK, KFH, Gulf Bank, Boubyan and Burgan is accumulated proprietary
knowledge that a competitor entering the market must rebuild customer by customer. S3-04 already specifies
saved mappings; treat them as a first-class asset.

**The regional PSPs are a revenue-side integration, not a statement source.** Tap Payments (the best
documented — webhook `hashstring` validation and an idempotency guide), UPayments (publicly commits to
next-business-day settlement) and MyFatoorah expose **only that merchant's own payment transactions**
`[DOCS]`. When QAYD integrates them, it will be ingesting a *settlement* feed — which is
`ARCHITECTURE.md` §7's three-way match, not `ARCHITECTURE.md` §8's bank-data sync. Different problem,
different module.

**A note on file-based ingestion as a design constraint rather than a limitation.** Because the channel is
a re-importable file rather than an incremental feed, S3-04's all-or-nothing tie-out gate is *more*
important, not less: there is no cursor to resume from and no provider-side change log to diff against.
The corpus's paginated-sync discipline (`PA-11` — a partial application is worse than none) applies
directly, and S3-04 already implements it. The one thing to add is a defined behaviour for re-importing a
period that overlaps an existing import. See R-05.
