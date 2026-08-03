# ARCHITECTURE — Data models, state machines, and algorithms

Version 1.0 · 2026-07-28 · Part of `docs/research/payments/`
A reference, not a narrative. Go to the section you need.

**No code from any system studied is reproduced.** Data models are described as shapes in order to argue
about them; diagrams are this document's own rendering of documented semantics.

---

## Contents

1. Ledger data models — the three published shapes
2. Two-phase money — the state machine and its balance arithmetic
3. Balance definitions — the exact formulas
4. Idempotency — the key record, its state machine, and recovery points
5. Event delivery — outbox, relay, inbox
6. Webhook delivery — the pipeline and the signature
7. Reconciliation — the three-way match and the clearing account
8. Bank-data ingestion — the cursor sync algorithm
9. API surface mechanics — versioning, pagination, expansion, errors

---

# 1 · Ledger data models — the three published shapes

Three systems publish enough detail to compare directly. They differ in where the invariant lives.

## 1.1 TigerBeetle — invariant in the schema

Two fixed record types, no user-defined tables. `[DOCS]`

```
ACCOUNT                                    TRANSFER
  id                u128 (client-supplied)   id                u128 (client-supplied, immutable)
  debits_pending    u128                     debit_account_id  u128
  debits_posted     u128                     credit_account_id u128
  credits_pending   u128                     amount            u128
  credits_posted    u128                     pending_id        u128  ← non-zero ⇒ resolves that transfer
  user_data_128/64/32                        user_data_128/64/32
  ledger            u32  ← currency/asset    timeout           u32   ← seconds; 0 = never expires
  code              u16  ← asset/liab/…      ledger            u32
  flags             u16                      code              u16
  timestamp         u64  (ns, cluster-set)   flags             u16
                                             timestamp         u64  (ns, cluster-set)
```

Four properties do the work:

- **Four unsigned counters, never a signed net.** The balance is derived by the *application*:
  `debits_posted − credits_posted` for assets and expenses, the reverse for liabilities, equity and
  income. The database never holds an opinion about which direction is "positive."
- **`ledger` partitions transactability.** Only same-ledger accounts transact. One ledger per currency
  means a cross-currency movement is *inexpressible* as a single transfer — it must be a linked chain
  through an FX position pair. The currency invariant is enforced by the type system, not by a check.
- **Account guard flags** — `debits_must_not_exceed_credits`, `credits_must_not_exceed_debits` — are
  monotone comparisons over unsigned integers. No sign handling, no underflow.
- **Transfers are immutable.** Resolution creates a *new* transfer pointing back via `pending_id`.

**Linked chains** express multi-leg atomicity without a transaction manager: `flags.linked` links a
transfer's outcome to the next one; the chain terminates at the first event without the flag; if any member
fails, all fail, with the failing event returning its specific error and the rest returning
`linked_event_failed`. Each chain is visible or invisible **as a unit** to later events in the same batch.
`[DOCS]`

```
[ transfer A  linked ]──┐
[ transfer B  linked ]──┤ one atomic, isolated chain
[ transfer C         ]──┘ (no flag ⇒ chain ends here)
[ transfer D         ]    independent
```

That is how an FX conversion is written: debit currency-A, credit FX position A, debit FX position B,
credit currency-B — four legs, one chain, all-or-nothing.

## 1.2 Modern Treasury — invariant in the API

A hierarchy, with the balancing rule enforced at the transaction boundary. `[DOCS]`

```
Ledger
  └── Ledger Account          (one currency; normal_balance = debit | credit)
        │                      lock_version increments when pending/posted balance changes
        └── Ledger Entry       (direction = debit | credit; amount; belongs to one account)
              ▲
Ledger Transaction ───────────┘  ≥2 entries · Σdebits = Σcredits · single ledger
  status         pending | posted | archived
  effective_at   business time      ← reporting
  posted_at      system time        ← null while pending
  external_id    client identity    ← idempotency handle
  ledgerable_type/_id              ← reconciliation pointer to the originating object
  reversed_by_ledger_transaction_id

Ledger Account Category — recursive grouping; balance = Σ contained accounts
```

The `normal_balance` effect table, which is the whole of double entry in four cells:

| Entry direction | Debit-normal account (assets, expenses) | Credit-normal account (liabilities, equity, income) |
|---|---|---|
| `debit` | increase | decrease |
| `credit` | decrease | increase |

**Ledger Event Handlers** sit above this: a template mapping a business event type to a set of double-entry
rules, introduced because *"engineers needed to add custom double-entry logic to each API call"* `[DOCS]`.
Callers emit business events; the handler produces the entries.

## 1.3 Adyen — invariant proven ahead of the write

Adyen's published architecture inverts the usual arrangement. `[DOCS]`

```
                     ┌──────────────────────┐
   business event ──▶│  ACCOUNTING TEMPLATE │──▶ journal records
                     └──────────────────────┘
                              ▲
                     formally verified:
                     "prove that every combination of amounts
                      will result in a net sum of 0"
                     re-verified on every template change
```

> *"The only way to add new records to the accounting system is by means of templates."*

The consequence they claim: *"if at any time, we sum up all the records in the accounting system, the
result will always be 0."* The invariant is established by proof over the *generator*, not by assertion at
each *write*.

Two structural choices are also documented and worth noting for what they teach about contention
(see `ANTI_PATTERNS.md` PA-14):

- **Round-robin sharding across accounting clusters.** Domain-knowledge routing (by merchant or payment
  method) was explicitly rejected, because aggregate reporting has to query every shard regardless.
- **The accounting system cannot block authorisation.** The payment layer saves locally and a separate
  process picks it up, so accounting downtime degrades reporting rather than acceptance.

Adyen's ledger vocabulary at the reporting boundary draws an explicit line: `[DOCS]`

| | Transfers | Transactions |
|---|---|---|
| Money state | pending; no real movement | booked; real movement |
| Identity | `Transfer Id` | `Transaction Id` |
| Registers | Received (PC) · Reserved (PC) · Balance (PC), each debited/credited with **opposite signs across counterparty balance accounts** |

## 1.4 Comparison

| | TigerBeetle | Modern Treasury | Adyen | **QAYD today** |
|---|---|---|---|---|
| Invariant lives in | the schema | the API boundary | a verified template | **the single posting service + DB CHECKs** |
| Balance storage | 4 unsigned counters | credits/debits + derived amount | registers | 4 gross + 1 derived, `CHECK`-bound |
| Mutation | impossible (immutable rows) | terminal states immutable | append-only | `trg_ledger_entries_append_only` |
| Currency | `ledger` partition | per-currency balancing rule | per-currency balances | entry + base assertion `[gap: per-line currency]` |
| Concurrency | no row locks | optimistic `lock_version` | sharded | row lock on the entry, not the accounts |
| Identity | client-supplied `id` | `external_id` | `Psp Reference` | `uq_ledger_entries_journal_line` |

`[CODE]` for the QAYD column. QAYD's arrangement is closest to Adyen's chokepoint model with TigerBeetle's
constraint discipline, which is a good place to be.

---

# 2 · Two-phase money — state machine and balance arithmetic

## 2.1 The state machine

```
                    ┌──────────────────────────────────────────┐
                    │                                          │
      create        ▼          post                            │
   ──────────▶ ┌─────────┐ ───────────▶ ┌────────┐             │
               │ PENDING │              │ POSTED │  terminal   │
               └─────────┘ ◀───────┐    └────────┘  immutable  │
                    │  void        │                           │
                    ├──────────────┴──▶ ┌──────────┐           │
                    │                   │ ARCHIVED │ terminal  │
                    │  timeout expires  └──────────┘           │
                    └──────────────────────────────────────────┘
                       (reservation self-releases)
```

Vocabulary across systems: `[DOCS]` for every cell.

| | Reserved | Final | Cancelled | Auto-release |
|---|---|---|---|---|
| TigerBeetle | `pending` transfer | `post_pending_transfer` | `void_pending_transfer` | `timeout` (seconds) |
| Modern Treasury | `pending` | `posted` | `archived` | — |
| Adyen | `authorised` / reserved | `booked` | `failed`, `refused`, `returned` | auth expiry (~28 days, card) |
| Square | `RESERVE_HOLD` | charge in payout | `RESERVE_RELEASE` | — |

**The third outcome is the load-bearing one.** Without an explicit "this will never complete," failed
promises accumulate as permanent phantom balance.

## 2.2 The balance arithmetic, exactly

TigerBeetle documents the counter movements for a 123-unit two-phase transfer A→B, initial values
w, x, y, z: `[DOCS]`

| Step | A.debits_pending | A.credits_posted | B.debits_pending | B.credits_posted |
|---|---|---|---|---|
| before | w | x | y | z |
| after **pending** | **w + 123** | x | **y + 123** | z |
| after **post** (full) | w | **x + 123** | y | **z + 123** |
| after **void** | w | x | y | z |

Three semantics fall out of this table:

- **Post moves; it does not add.** The pending counter decreases by exactly what the posted counter gains.
- **Partial posting is supported** — post less than reserved and *"the remainder returns to original
  accounts."* Posting more than the pending amount is an error. This is exactly the card
  authorise-then-capture-for-less case.
- **Balance constraints are validated at reserve time**, so a post can never violate an invariant the
  reserve already cleared.

Resolution is exactly-once by construction: a pending transfer cannot be resolved twice, and resolution
creates a new immutable transfer rather than mutating the original.

---

# 3 · Balance definitions — the exact formulas

The three published balances, with Modern Treasury's exact definitions. `[DOCS]`

```
pending_balance    = Σ(all PENDING and POSTED entries)
posted_balance     = Σ(all POSTED entries)
available_balance  = Σ(POSTED entries) + Σ(PENDING OUTBOUND entries)
```

Expanded by account normality — this is the table to copy:

| Balance | credit-normal | debit-normal |
|---|---|---|
| `pending_balance` | `pending.credits − pending.debits` | `pending.debits − pending.credits` |
| `posted_balance` | `posted.credits − posted.debits` | `posted.debits − posted.credits` |
| `available_balance` | **`posted.credits − pending.debits`** | **`posted.debits − pending.credits`** |

Note the asymmetry in the third row: for `available_balance`, the credits component equals
`posted_balance.credits` while the debits component equals `pending_balance.debits`. In one sentence:

> **Outbound counts at promise. Inbound counts at settlement.**

Adyen's five balance types map onto the same idea with a different decomposition: `[DOCS]`

```
balance (current)  = Σ settled transactions
pending            = Σ transactions that will settle in the future   (may be NEGATIVE)
reserved           = authorised-but-unbooked  +  blocked (repayments, collateral)
available          = min( balance , balance − pending − reserved )
pendingAvailable   = min( pending , pending + available )
```

`pending` being permitted to go negative is the detail most designs miss: future settlements can be net
outflows.

**Concurrency guards.** Modern Treasury pairs the balances with conditional writes: a transaction may
declare `pending_balance_amount`, `posted_balance_amount` or `available_balance_amount` with an operator
(`gt`, `gte`, `eq`, `lte`, `lt`), and if the write would move the balance outside the range the request
fails `422`. `[DOCS]` The documented trap is important:

> *"Balance filters consider **every** Ledger Entry on the corresponding Ledger Account, **regardless of
> their `effective_at` values**."*

So a guard is evaluated over the whole account, not an as-of slice — a backdated entry affects guard
evaluation. Bitemporality and conditional writes interact, and the interaction is not intuitive.

**Hot-account rule.** Entries are processed synchronously only when they carry a balance lock, a version
lock, or request resulting balances; otherwise they batch asynchronously under a single lock version, with
a stated SLA of *"all Ledger Entries will be applied… within 60 seconds."* The prescription:
*"ensure that the hot account receives only asynchronous entries."* Lock the user's side, never the
shared settlement side. `[DOCS]`

---

# 4 · Idempotency — the key record and its recovery points

## 4.1 Documented external semantics (Stripe)

`[DOCS]` unless noted.

| Property | v1 API | v2 API |
|---|---|---|
| Methods | `POST` only | `POST` and `DELETE` |
| Replay window | **24 hours** | **30 days** |
| Key omitted | no idempotency | key auto-generated (UUID) |
| Key length | ≤ 255 chars | — |
| Recommended keys | V4 UUID or equivalent entropy; never sensitive data | — |
| Stored on first call | **status code + body, regardless of success or failure — including 500s** | — |
| Replay marker | header `Idempotent-Replayed: true` | — |
| Same key, different body **or endpoint** | `idempotency_error` | — |
| Key currently executing | `409` / `idempotency_key_in_use` | — |
| Validation failure or concurrent conflict | **not saved** — *"we save results only after the execution of an endpoint begins"* | — |

Two of these are counter-intuitive and both are deliberate:

- **Failures are saved and replayed.** The dangerous case is a request that did the work and failed to
  report it. Replaying the failure is honest; re-executing is a double post.
- **The endpoint is part of the match key**, not just the body — otherwise a key-scoping bug lets one
  endpoint's response be replayed as another's.

## 4.2 The internal shape: atomic phases and recovery points

`[COMMUNITY]` — a widely-cited reference implementation informed by Stripe, not documentation of Stripe's
internals. Cite it as a pattern.

Definitions:

- **Foreign state mutation** — a call to an external system outside your ACID boundary. Irreversible.
- **Atomic phase** — a run of purely local database work, committed as one transaction.
- **Recovery point** — a named checkpoint persisted on the idempotency-key row after any completed atomic
  phase *or* foreign state mutation. Recovery points form a directed acyclic graph terminating at
  `finished`.

The rule: **atomic phases are committed *before* initiating any foreign state mutation.**

```
   request + Idempotency-Key
            │
            ▼
  ┌───────────────────────────────────────────────────────────────┐
  │ TX 1   INSERT idempotency_key (key, params_hash, locked_at,   │
  │                                recovery_point='started')      │
  │        … local writes for phase 1 …                           │
  │ COMMIT ────────────────────────────────────────────────────── │
  └───────────────────────────────────────────────────────────────┘
            │  ← crash here: retry resumes at 'started'. Nothing external happened.
            ▼
     ╔═══════════════════════════════════════╗
     ║  FOREIGN STATE MUTATION               ║   ← cannot be rolled back
     ║  (charge a card, call a bank)         ║
     ╚═══════════════════════════════════════╝
            │  ← crash here: retry resumes at 'started', BUT the external call
            │     already happened. This is why the external system must ALSO
            │     accept an idempotency key derived from ours.
            ▼
  ┌───────────────────────────────────────────────────────────────┐
  │ TX 2   UPDATE idempotency_key SET recovery_point='charged'    │
  │        … local writes for phase 2 …                           │
  │        UPDATE … recovery_point='finished',                    │
  │                 response_code, response_body                  │
  │ COMMIT                                                        │
  └───────────────────────────────────────────────────────────────┘
            │
            ▼
       response  (replayed verbatim on any later use of the key)
```

Supporting mechanics in the same design:

- Composite unique index on `(user_id, idempotency_key)` — keys are namespaced per principal.
- A `locked_at` column prevents concurrent execution, **with lock-expiry logic** so a crashed request can
  be recovered by a background reaper. The lock must outlive the operation's worst case or it stops
  protecting exactly when it matters.
- Side work (email, notifications) is inserted into a staged-jobs table **inside the same transaction** —
  which is the transactional outbox by another name, and prevents jobs orphaned by a rolled-back
  transaction.

## 4.3 The crash-window comparison

This is the argument for `PB-08`, drawn:

```
WRONG — key stored outside the transaction, after commit
  BEGIN ─── post journal ─── COMMIT ─┬─────────── SET key ───▶
                                     │
                                  ╳ crash
                                     │
                     fact is durable, key is not
                     retry finds no key → POSTS AGAIN

RIGHT — key row commits with the fact
  BEGIN ─── INSERT key ─── post journal ─── COMMIT ───▶
                                     │
                                  ╳ crash
                                     │
                     neither exists → retry executes cleanly
                     or both exist  → retry replays
```

There is no third outcome. The guarantee stops being probabilistic.

---

# 5 · Event delivery — outbox, relay, inbox

## 5.1 The full path

```
 ┌─ Action ──────────────────────────────────────────────┐
 │ BEGIN                                                 │
 │    business writes (journal, ledger projection)       │
 │    INSERT INTO outbox (name, aggregate_id, version,   │  ◄─ SAME transaction
 │                        payload, available_at)         │
 │ COMMIT                                                │
 └──────────────────────┬────────────────────────────────┘
        rollback ⇒ no outbox row ⇒ the event CANNOT fire
        commit   ⇒ the row is durable ⇒ it CANNOT be lost
                        ▼
              ┌──────────────────┐
              │  RELAY WORKER    │  SELECT … FOR UPDATE SKIP LOCKED LIMIT n
              │                  │  dispatch → mark delivered
              └──────────────────┘
                        │
        ⚠ the relay's "publish" and "mark published" are THEMSELVES a dual write.
          This is why delivery is AT-LEAST-ONCE and no setting changes that.
                        ▼
              ┌──────────────────┐
              │    CONSUMER      │
              │  BEGIN                                    │
              │    INSERT INTO inbox (subscriber, msg_id) │ ◄─ PRIMARY KEY
              │    … business effect …                    │    duplicate ⇒ PK violation
              │  COMMIT                                   │    ⇒ rollback ⇒ dismissed
              └──────────────────┘
```

`[DOCS]` for the outbox and the idempotent consumer; the ⚠ note is documented as *"the relay may publish
messages multiple times (e.g. after crashing before confirming publication)."*

**The inbox insert must be in the same transaction as the effect.** Before it, a failed effect swallows the
message permanently. After it, a crash between them re-runs the effect. Both are silent.

**Why a primary key rather than a `SELECT` then `INSERT`:** the uniqueness constraint *is* the concurrency
control. Check-then-act loses to two concurrent deliveries in separate transactions; a PK violation does
not.

## 5.2 Relay mechanisms

| | Polling publisher | Transaction-log tailing (CDC) |
|---|---|---|
| How | periodically query unpublished rows | tail the WAL / binlog |
| Benefit | *"works with any SQL database"* | *"guaranteed to be accurate"* |
| Drawback | *"tricky to publish events in order"*; latency-vs-load dial | *"requires database specific solutions"*; *"tricky to avoid duplicate publishing"* |

`[DOCS]` for all quoted text.

## 5.3 Ordering

Documented outbox routers make the **aggregate id the message key**, because it is *"important for
maintaining correct order in Kafka partitions"* `[DOCS]`. Kafka's guarantee is per-partition: events
sharing a key land in the same partition, and a consumer reads a partition *"in exactly the same order as
they were written"* `[DOCS]`.

```
   global ordering  ─────  single partition  ─────  single consumer  ─────  one machine's throughput
   per-key ordering ─────  N partitions      ─────  N consumers      ─────  horizontal scale
```

Ordering and parallelism are the same dial. Most "we need global ordering" requirements are mis-stated
per-entity ordering requirements; naming the entity usually dissolves the problem. `[INFERENCE]`

The event envelope this implies, minimally:

```
event_id        (dedup key for the consumer)
name            (declared constant, not a call-site string)
aggregate_type  (routing / partition selection)
aggregate_id    (partition key ⇒ per-aggregate ordering)
version         (monotonic per aggregate ⇒ consumers reject stale)
occurred_at
payload         (COMPACT — see PA-15)
correlation_id  (mandatory once the stack trace becomes a chain)
```

---

# 6 · Webhook delivery — pipeline and signature

## 6.1 Producer side

```
 domain event ──▶ outbox ──▶ relay ──▶ delivery queue ──▶ HTTP POST
                                             │
                                             ├─ 2xx ────────▶ delivered
                                             │
                                             └─ non-2xx / timeout / TLS error
                                                     │
                                                     ▼
                                       exponential backoff + JITTER
                                       Stripe:  up to 3 days (live)
                                       Standard Webhooks: 0s, 5s, 5m, 30m … ≈75h
                                       Plaid:   30s ×4 each, up to 24h
                                                     │
                                             ┌───────┴────────┐
                                             │  exhausted     │
                                             ▼                ▼
                                     endpoint disabled    DEAD LETTER
                                     (Stripe) / deleted   (queryable,
                                     (Shopify) / stopped   alertable)
                                     (Plaid >90%/24h)
```

`[DOCS]` for every schedule and protective action. Jitter matters: without it, a recovered provider
flushes its backlog at you as a synchronised thundering herd.

## 6.2 Consumer side

```
   HTTP POST arrives
        │
        ▼
   ① read RAW BYTES  (never a parsed object — see PA-03)
        │
        ▼
   ② verify signature: HMAC-SHA256 over  id . timestamp . raw_body
        │   · constant-time comparison
        │   · reject schemes other than the current one (downgrade defence)
        │   · reject |now − timestamp| > tolerance   (5 min typical; NEVER 0)
        │
        ▼  invalid ⇒ 4xx, log, stop.  Do NOT queue.
   ③ persist raw event keyed by provider event id
        │
        ▼
   ④ return 2xx   ◄── BEFORE any business logic.
        │              Shopify 5s · Adyen 10s · Plaid 10s
        ▼
   ⑤ worker: dedup on event id → fetch current state from the API → apply
```

Step ② must precede step ④, or the queue has an unauthenticated write path into it.

## 6.3 Signature schemes compared

| Provider | Header | Signed string | Timestamp signed? | Encoding |
|---|---|---|---|---|
| Stripe | `Stripe-Signature: t=…,v1=…` | `timestamp . raw_body` | **yes** | hex |
| Standard Webhooks | `webhook-signature` (+ `webhook-id`, `webhook-timestamp`) | `msg_id . timestamp . raw_body` | **yes** | base64 |
| Plaid | `Plaid-Verification` (JWT, ES256) | body SHA-256 inside the JWT; `iat` checked | **yes** | JWS |
| GitHub | `X-Hub-Signature-256` | raw body only | **no** | hex |
| Shopify | `X-Shopify-Hmac-SHA256` | raw body only | **no** | base64 |

`[DOCS]` throughout.

Standard Webhooks carries **multiple space-delimited signatures**, which is how key rotation becomes a
non-breaking operation, and puts the scheme version *inside* the signature value (`v1,<sig>`) rather than
in a separate header — so a scheme migration is also non-breaking. That is the design to copy.

The dependency worth carrying explicitly: **a bounded dedup store is only safe behind a signed timestamp.**
Standard Webhooks' ~5-minute dedup suggestion works because the tolerance check independently rejects
anything older. Behind a GitHub-style scheme, ids must be retained indefinitely. `[INFERENCE]`

## 6.4 Thin vs snapshot payloads

```
SNAPSHOT (event-carried state transfer)      THIN (event notification)
{ id, type, created,                          { id, type, created, livemode,
  api_version: "2026-03-25.dahlia",             reason: { idempotency_key, request_id },
  data: { object: { …full resource… } } }       related_object: { id, type, url } }

  · self-contained, no callback                 · always current (consumer fetches)
  · point-in-time truth                         · UNVERSIONED — nothing to break
  · STALE by construction (unordered,           · N+1 calls; couples to provider uptime
    retried for days)                           · retry storm ⇒ read storm
  · a PUBLISHED SCHEMA = permanent
    compatibility obligation
```

`[DOCS]` for both shapes and both sets of trade-offs. Offering both per event type is the observed
compromise.

---

# 7 · Reconciliation — the three-way match and the clearing account

## 7.1 Why the books disagree

Nine independent mechanisms, all documented, enumerated in `OVERVIEW.md` §3. The two that surprise people:
**cut-off boundaries** create a *permanent* disagreement at the boundary rather than a transient one, and
the **reconciliation report is itself T+1** (Stripe computes at 00:00 UTC, available by 12:00 next day
`[DOCS]`).

## 7.2 The closing identity

Published by two processors in the same shape:

```
   starting balance  +  activity  −  payouts  =  ending balance
```

Stripe's `balance.summary` report has exactly those four rows, with `activity` defined as *"the net amount
of all transactions that affected your balance **except for payouts**"* `[DOCS]`. Adyen's equivalent:
Σ `Net Credit` per `Batch Number` = the bank payout `[DOCS]`. Square's: Σ `PayoutEntry.net_amount_money` =
`Payout.amount_money` `[DOCS]`.

## 7.3 The three-way match

```
 ┌─────────────────┐   Leg 1   ┌──────────────────────┐   Leg 2   ┌──────────┐   Leg 3   ┌──────────┐
 │  YOUR ORDERS /  │◄─────────▶│  PROCESSOR ACTIVITY  │◄─────────▶│  PAYOUT  │◄─────────▶│   BANK   │
 │    INVOICES     │           │ (balance txns, each  │           │  BATCH   │           │STATEMENT │
 └─────────────────┘           │  gross / fee / net)  │           └──────────┘           └──────────┘
        join on:                └──────────────────────┘            join on:               join on:
   payment_intent_id                     │                      automatic_payout_id    payout_reference
   / Merchant Reference                  │                      / Batch Number         / trace_id
                                         │                      / payout_id            / Payout.id
                          transactions with NO payout id
                                         │
                                         ▼
                            = FUNDS IN TRANSIT, by definition
```

Leg 2's residual is not an error state — it is the balance. Every processor documents the identifier that
appears on the bank statement precisely so Leg 3 is a join rather than a search `[DOCS]`.

## 7.4 The clearing account

The accounting shape the three-way match implies:

```
   capture              ┌───────────────────────────┐
   ────────────────────▶│  FUNDS IN TRANSIT (asset) │
   Dr Funds in transit  │                           │
   Dr Processor fees    │  balance at any instant   │
     Cr Revenue         │  SHOULD equal the         │
                        │  processor's pending +    │
   payout received      │  available balance        │
   ◀────────────────────│                           │
   Dr Bank              │  the DIFFERENCE is the    │
     Cr Funds in transit│  ENTIRE exception queue   │
                        └───────────────────────────┘
```

Two rules make it work:

1. **Fees are booked at the balance-transaction moment**, as a separate expense line — not netted at
   deposit. Otherwise gross revenue is permanently unrecoverable (`PA-13`).
2. **Every entry into the clearing account carries the processor's transaction id.** Attribution recorded
   at write time; never inferred later (`R-24`, `PA-16`).

`[COMMUNITY]` — the field-level facts are documented; assembling them into this methodology is this
document's synthesis. No processor states it in these words.

## 7.5 QAYD's Sprint 03 analogue

S3-04's tie-out gate is the same discipline applied to statement import:

```
   opening_balance + Σ(statement lines) = closing_balance
        │
        ├─ holds  ⇒ commit ALL lines as immutable facts
        └─ fails  ⇒ 422 statement_tie_out_failed, ZERO lines written
```

`[DOCS, sprint]`. This mirrors the settlement identity, and the all-or-nothing rule matches the
paginated-sync discipline in §8: **a partial import is worse than no import.**

S3-06's `variance = book_balance − adjusted_bank_balance` is the clearing-account difference by another
name. When PSP settlements land, the same variance machinery covers them.

---

# 8 · Bank-data ingestion — the cursor sync algorithm

Plaid's model, because it is the best documented and the design problems are universal. `[DOCS]`

## 8.1 The loop

```
  cursor := load_persisted_cursor()          -- valid ≥1 year once fully paginated
  page_1_cursor := cursor                    -- ⚠ RETAIN: needed for restart
  batch := []

  loop:
      resp := sync(cursor, count ≤ 500)      -- count is UPDATES, not transactions
      batch += resp.added + resp.modified + resp.removed
      cursor := resp.next_cursor
      if not resp.has_more: break

      -- on TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION:
      --   restart the ENTIRE loop from page_1_cursor.
      --   NEVER retry just the failed page.

  apply(batch) atomically
  persist(cursor)                            -- only after the whole loop commits
```

The pagination loop is a **snapshot**, not a sequence of independent reads. This is `PA-11`.

## 8.2 Pending → posted is delete-and-insert

The single most important modelling fact in this section:

```
   pending txn (id = A)                        posted txn (id = B)
        │                                            ▲
        │  1–5 business days                         │
        └────────────────▶ arrives in `removed`      │
                           arrives in `added` ───────┘
                                    with pending_transaction_id = A
                                    (ONLY if Plaid matched them; else null)
```

Consequences, each documented:

- **The `transaction_id` is not a stable identity for the economic event.** The same purchase occupies two
  keys over its life; the durable identity is the id chained through `pending_transaction_id`.
- **The details change between the two.** *"The pending and posted versions of a transaction may not
  necessarily share the same details: their **name and amount may change**."* Canonical example: a
  restaurant charge without the tip while pending, with it once posted. So amount matching yields false
  negatives as well as false positives — independent confirmation of `R-24`.
- **A pending transaction can vanish without ever posting** (an authorisation hold).
- **A posted transaction is not immutable**: *"a posted transaction cannot necessarily be considered
  immutable"* — refunds and recategorisation arrive via `modified`.
- **`removed` entries are stubs** carrying only `transaction_id` and `account_id`. You cannot re-derive the
  deleted row's contents from the response, so the consumer must hold its own copy.

## 8.3 Balances and freshness

`current` (total), `available` (withdrawable), `limit` (credit limit *or*, on depository accounts,
pre-arranged overdraft — the field means two different things by account type). Cached balances come from
the accounts endpoint; a **live** pull requires the dedicated balance endpoint. There is no general
per-account freshness timestamp — one is returned for exactly one institution. `[DOCS]`

The underlying latency floor is the polling cadence: **1–4 times per day**. The webhook announces when the
aggregator learned something, not when the bank posted it. `[DOCS]`

## 8.4 The irreversible configuration decision

`days_requested` — default 90, range 1–730 — **cannot be changed after transactions are added to the
Item**. More history requires removing the Item and re-linking through the user-facing flow. `[DOCS]`
A one-shot decision made before anyone knows how much history they will want; the instance of `PA-16`
that applies to configuration rather than relationships.

## 8.5 Consent as a scheduled event

Access is not permanent. `ITEM_LOGIN_REQUIRED` (credentials or OAuth consent), `PENDING_EXPIRATION`
(EU/UK, 7 days' notice), `PENDING_DISCONNECT` (US/CA, ~7 days), `USER_PERMISSION_REVOKED`,
`LOGIN_REPAIRED` (self-healed — the consumer must clear its own error state). `[DOCS]`

**Re-authentication is a recurring lifecycle event with a warning webhook, not an exception path.** Any
design that treats a bank connection as durable is wrong.

**For QAYD this section is forward-looking only.** No aggregator covers Kuwait (`OVERVIEW.md` §4). It is
recorded because the *modelling* lessons — unstable ids, mutable posted rows, atomic loop application —
apply equally to a re-imported CSV statement.

---

# 9 · API surface mechanics

Stripe's API is the reference here. The question worth answering is *why* it is considered best-in-class
beyond aesthetics. Four mechanisms, each of which shifts a cost from the client to the server or fixes it
permanently.

## 9.1 Versioning — a backwards transform chain

```
  core codebase speaks ONLY the newest version
            │
            ▼
   response built at CURRENT version
            │
            ├── version change module (2026-03) ──┐
            ├── version change module (2025-09)   │  applied BACKWARDS, in order,
            ├── version change module (2024-09)   │  until the caller's pinned
            └── …                                 │  version is reached
                                                  ▼
                                        response at caller's version
```

`[DOCS]` — Stripe's own vocabulary is *version change module*, *API resource*, *transformation*, and a
`has_side_effects` annotation for changes that are not response transformations (the module becomes a
no-op marker). *"Version changes are written so that they expect to be automatically applied backwards
from the current API version."*

Why this is the good design: **the cost of compatibility is paid once per breaking change, in one place,
and never enters a feature developer's working set.** The alternative — conditionals scattered through
business logic — makes every change pay a compatibility tax forever.

Accounts pin on first request. The version string is `YYYY-MM-DD.codename`, and the **codename is the
compatibility contract** — same codename means backwards-compatible, new codename means breaking. Upgrades
move API calls, Stripe.js objects, webhook payloads and automated billing operations together, with a
72-hour rollback window during which *"webhooks that were sent with the new object structure and failed
will be retried with the old structure."* `[DOCS]`

**The honest part, which the popular narrative gets wrong.** The strongest official statement is
retrospective — *"To date, we've maintained compatibility with every version of our API since the company's
inception in 2011"* — and the same post says *"we expect to eventually start retiring our older API
versions."* There is **no documented sunset policy**. `[DOCS]` for both quotes; `[UNKNOWN]` for any support
commitment. "No documented sunset" is not "documented forever."

**The additive-change contract.** Stripe enumerates what counts as backwards-compatible: new resources, new
optional parameters, new response properties, changed property order, changed length or format of opaque
strings **including object ids** (*"this includes adding or removing fixed prefixes such as `ch_`"*), and
new event types (*"make sure that your webhook listener gracefully handles unfamiliar event types"*).
`[DOCS]`

This list is simultaneously a definition and **a contract imposed on the client**: ignore unknown fields,
ignore unknown event types, never parse or length-bound an id. Declaring these additive is what makes it
safe to ship them without minting a version change module. The API's flexibility is purchased with client
discipline, stated up front.

## 9.2 Pagination — ID cursors

`starting_after` / `ending_before` take an **object id**, not an offset; mutually exclusive; `limit`
default 10, max 100; default order reverse chronological; the envelope carries `has_more` and no total.
`[DOCS]`

The mechanism: an id cursor is a *position in a total order*, not a *count from the start*. A new object at
the head does not shift subsequent pages, so pages are stable under concurrent insertion. The documented
cost is the one thing offsets are good at — no `total_count`, no jumping to page 4. For append-heavy
financial collections that is the right trade (`PA-10`).

## 9.3 Expansion — the client chooses join depth

`expand` inlines a referenced object; dot notation recurses; **maximum depth four**; request-scoped;
available on create and update as well as list and read. `[DOCS]`

The mechanism: it resolves the tension between small default payloads and N+1 round trips **without the
server guessing**. The server ships the minimal representation; the client declares exactly the graph it
needs, per request. The depth cap bounds server-side fan-out so the feature cannot be weaponised.

Compare the alternatives: fat defaults (slow for everyone, and a permanent schema obligation — `PA-15`) or
bespoke endpoints per view (combinatorial explosion).

## 9.4 Errors — a three-level taxonomy plus repair information

```
   HTTP status   → coarse class      (402 = valid request that failed; 409 = idempotency conflict)
   error.type    → 4 values          card_error · invalid_request_error · api_error · idempotency_error
   error.code    → ~180 values       programmatic branch point
   decline_code  → issuer's reason,  NORMALISED across issuers by Stripe
```

`[DOCS]`. Plus repair information on the error object itself: `param` (*"the parameter related to the
error… you can use this to display a message near the correct form field"*), `message`, `doc_url`,
`request_log_url`, and object back-references.

Three things make this better than a flat code list:

1. **The levels answer different questions.** Status → should I retry? Type → whose fault is it? Code →
   what exactly happened? A client can branch at whichever level it cares about and remain correct when new
   codes are added below it.
2. **`decline_code` is normalised.** Stripe maps heterogeneous issuer codes onto its own set — the
   integrator sees one vocabulary instead of hundreds.
3. **An information-leakage rule is part of the taxonomy.** For `fraudulent`, `lost_card` and
   `stolen_card`, the documentation instructs presenting the same message as `generic_decline`. **The API
   deliberately tells the integrator more than the integrator may tell the end user.** That distinction —
   between the machine-readable diagnosis and the human-facing message — is a design decision most error
   taxonomies never make.

`402` deserves a note: *"the parameters were valid but the request failed"* is a genuinely useful status
that most APIs collapse into 400 or 422, losing the "your request was fine, the world said no"
distinction. `[DOCS]`

## 9.5 Rate limiting that admits contention

429 responses carry a header naming *which* limit was hit — global rate, endpoint rate, global concurrency,
endpoint concurrency, resource-specific — and the guidance is exponential backoff **plus randomness** to
avoid a thundering herd. Notably, per-object lock contention is surfaced to clients as its own condition:
an object *"cannot be accessed right now because another API request or Stripe process is currently
accessing it,"* with the recommendation to serialise mutations on a single object. `[DOCS]`

The mechanism worth copying: **telling the client which limit was hit makes the correct backoff strategy
computable.** Concurrency limits want serialisation; rate limits want delay. An undifferentiated 429 leaves
the client guessing.
