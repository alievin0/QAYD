# OVERVIEW — The payments landscape and its defining architectural splits

Version 1.0 · 2026-07-28 · Part of `docs/research/payments/`
Read `README.md` first for scope and evidence grading.

---

## 1 · What is actually public, and what is not

The first useful finding is about the corpus itself. "Payment companies publish good engineering
material" is only true of a minority of them, and the minority is not the one you would predict from
brand size.

| Company | Ledger / accounting internals | API + operational docs | Verdict |
|---|---|---|---|
| **TigerBeetle** | **Exceptional** — full data model, two-phase semantics, stated rationale `[DOCS]` | n/a (a database) | The most precisely specified model in the corpus |
| **Modern Treasury** | **Exceptional** — object model, exact balance formulas, a six-part scaling essay series `[DOCS]` | Very good | Ledger as a product, so the ledger *is* the documentation |
| **Adyen** | **Strong, single source** — one architecture piece describing template-only writes and formal verification `[DOCS]` | Excellent (settlement reports) | One very good article; internals otherwise closed |
| **Stripe** | **Partial** — an internal system called Ledger is described at a high level `[DOCS]`; the public `balance_transactions` model is *not* double entry | **Best-in-class** | Best API design writing; ledger internals mostly closed |
| **Square** | Good *by schema* — `Payout`/`PayoutEntry` with 25 entry types is a genuine ledger model `[DOCS]` | Good | Publishes the model without writing about it |
| **Checkout.com** | Adequate — reports plus explicit reconciliation identities `[DOCS]` | Good | No engineering writing found |
| **Mollie** | Adequate — a settlement object with `open`/`next` states `[DOCS]` | Good | No engineering writing found |
| **Plaid** | n/a (not a ledger) | **Very good** — the sync/cursor model is well argued `[DOCS]` | Best bank-data documentation |
| **Airwallex** | Thin | Adequate | Settlement timing only |
| **Mercury** | None — flat transaction feed `[DOCS]` | Good, and unusually honest | Best small-fintech docs of the three |
| **Brex** | None — flat settled-transaction feed `[DOCS]` | Adequate, candid about limits | Documents what it *cannot* do, which is rare |
| **Ramp** | None — flat feed plus ERP field mapping `[DOCS]` | Good | Defers double entry to the downstream ERP |
| **Wise** | **`[UNKNOWN]`** — no first-party engineering writing found | Good API reference | Notable negative result |
| **PayPal** | **`[UNKNOWN]`** — blog exists, no ledger content verified | Adequate | Notable negative result |

Three consequences follow.

**First, the best material comes from companies whose product *is* the ledger.** Modern Treasury and
TigerBeetle sell a ledger, so describing it precisely is a commercial act. Stripe and Adyen sell payment
acceptance, so the ledger is a cost centre they describe only when it helps recruiting. This is worth
knowing before you go looking: the depth is where the product boundary is, not where the volume is.

**Second, "no double-entry model" is the norm among the newer fintechs.** Mercury, Brex and Ramp — the
three modern spend/banking platforms — all expose a **flat, signed transaction feed** with no journal
entry, no chart of accounts, and no balanced-pair primitive. Ramp goes furthest by modelling *ERP field
mapping* (GL accounts, cost centres, tax codes) but explicitly defers the actual double entry to NetSuite
or QuickBooks. `[DOCS]` This is directly relevant to QAYD's positioning: the category QAYD is entering has
largely decided that double entry is somebody else's problem.

**Third, the same conclusions recur independently.** TigerBeetle, Modern Treasury, Adyen and Square arrived
at pending/posted separation, correction-by-reversal, per-currency balancing and client-supplied identity
without coordinating. When four systems built by different people for different purposes converge on the
same decision, that decision is load-bearing rather than stylistic.

---

## 2 · The eight defining splits

Every system in the corpus faces the same set of forks. Where they agree, the agreement is strong
evidence. Where they diverge, the divergence usually traces to one variable — **whether the system is the
authority on the money, or an observer of it.**

### Split 1 — Signed net balance vs separated debits and credits

**The fork.** Does an account store one signed number, or does it store gross debits and gross credits
separately and derive the net?

**Where they land.** Unanimously on separation.

- **TigerBeetle** stores **four unsigned 128-bit integers** per account: `debits_pending`, `debits_posted`,
  `credits_pending`, `credits_posted`. The application derives the balance as
  `debits_posted − credits_posted` for assets and expenses, or the reverse for liabilities, equity and
  income. `[DOCS]` It never stores a signed net.
- **Modern Treasury** exposes `credits`, `debits` and a derived `amount` on every balance object, with the
  direction of `amount` determined by the account's `normal_balance`. `[DOCS]`
- **Stripe's** public balance transaction carries `amount` (gross), `fee`, and `net`, with the documented
  identity `net = amount − fee`. `[DOCS]` Gross is never discarded.
- **Adyen's** settlement report carries `Gross Debit`, `Gross Credit`, `Net Debit`, `Net Credit`,
  `Commission`, `Markup`, `Scheme Fees` and `Interchange` as separate columns. `[DOCS]`

**Why.** Netting to a single signed scalar is lossy in a way that is invisible until you need the lost
information. Turnover — *how much flowed through* — drives fee calculation, volume pricing, VAT
reporting, and every reconciliation. An account that has received 1,000,000 and paid out 999,000 is a
completely different business from one that received 1,000 and paid out nothing, and both have a balance
of 1,000. `[INFERENCE]` TigerBeetle adds a second reason: unsigned separated counters make the guard
invariants pure comparisons with no sign handling and no underflow, and all four counters are
monotonically increasing, which is cheap to verify. `[DOCS]` for the counters, `[INFERENCE]` for the
consequence.

**Relevance to QAYD.** QAYD already lands on the correct side of this split and did so independently —
`ledger_entries` carries `debit_amount`, `credit_amount`, `base_debit_amount`, `base_credit_amount` **and**
the derived `signed_base_amount`, with a `CHECK` binding the derived column to its sources. `[CODE]`
See `LESSONS_FOR_QAYD.md` §1.2.

### Split 2 — One-phase vs two-phase money

**The fork.** Is a money movement a single event, or does it have a reserved state before it becomes final?

**Where they land.** Unanimously on two phases, under five different vocabularies.

| System | Reserved | Final | Released |
|---|---|---|---|
| TigerBeetle | `pending` transfer (`*_pending` counters) | `post_pending_transfer` | `void_pending_transfer` or timeout |
| Modern Treasury | `pending` ledger transaction | `posted` | `archived` |
| Adyen | `authorised` / `reserved` balance | `booked` / current balance | `failed`, `refused`, `returned` |
| Square | `RESERVE_HOLD`, `OPEN_DISPUTE`, `HOLD_ADJUSTMENT` | `CHARGE` in a payout | `RESERVE_RELEASE`, `RELEASE_ADJUSTMENT` |
| Checkout.com | Available | Payable | — |

`[DOCS]` for every row.

**Why.** The physical world has a gap between "promised" and "settled" — a card authorisation, an ACH in
flight, a cheque not yet cleared. A ledger with only one state must either book the promise as fact
(and be wrong when it fails) or book nothing (and be blind to committed obligations). Two phases let the
system be *correct about uncertainty*, which is the actual requirement.

**The subtle part is the third state.** All five systems distinguish "resolved successfully" from
"resolved unsuccessfully" — post vs void, posted vs archived, booked vs refused. A two-state model that
only has pending and posted has no way to say *this promise will never be kept*, and such promises then
accumulate as permanent phantom balance. TigerBeetle goes further and gives a pending transfer an optional
**timeout in seconds**, after which the reservation self-releases. `[DOCS]`

### Split 3 — The definition of "available balance"

**The fork.** Given pending and posted amounts, which combination is safe to spend?

**Where they land.** Unanimously on the pessimistic asymmetry — and this is the single most transferable
formula in the corpus.

> **Count outbound money the moment it is promised. Count inbound money only when it is final.**

- **Modern Treasury**, exactly: `available_balance` for a credit-normal account is
  `posted.credits − pending.debits`. For a debit-normal account, `posted.debits − pending.credits`.
  Inbound legs count only when posted; outbound legs count as soon as pending. `[DOCS]`
- **Adyen**: available = the lower of the current balance, or (current balance − pending − reserved).
  `[DOCS]`
- **TigerBeetle**'s balancing transfers enforce
  `debits_pending + debits_posted ≤ credits_posted` — pending debits count against you, pending credits do
  not count for you. `[DOCS]`
- **Wise** keeps `amount` and `reservedAmount` as separate fields on every balance. `[DOCS]`

**Why.** Getting the asymmetry backwards is how you build an overdraft. If pending inbound counts toward
spendable balance and the inbound fails, the money was already spent. The rule is not conservatism for its
own sake — it is that the two directions have different failure consequences, so they get different rules.

### Split 4 — Correction by mutation vs correction by reversal

**The fork.** When a posted fact turns out to be wrong, do you edit it or write an opposing entry?

**Where they land.** Unanimously on reversal, and several enforce it structurally.

- **TigerBeetle**: *"All Transfers Are Immutable."* Resolving a pending transfer does not mutate it —
  it **creates a new transfer** carrying `pending_id` pointing at the original. `[DOCS]`
- **Modern Treasury**: *"In order to undo the effect of a `posted` Ledger Transaction, you will need to
  write a second reversing Ledger Transaction,"* linked via `reversed_by_ledger_transaction_id`.
  `metadata` is the only mutable surface on a terminal transaction. `[DOCS]`
- **Square** encodes it in the type vocabulary: nearly every one of its 25 payout entry types has an
  explicit `_REVERSED` counterpart. `[DOCS]`
- **Adyen** likewise: `ChargebackReversed`, `RefundedReversed`, `PaidOutReversed`, `SettledReversed`,
  `MerchantPayinReversed`. `[DOCS]`
- **Stripe** models a dispute and its reversal as **two separate balance transactions**, both distinct
  from the original charge, under distinct reporting categories `dispute` and `dispute_reversal`.
  `[DOCS]`

**Why.** The generalisable statement — and the sharpest single sentence in the study — is:

> **Money movement is append-only. A refund is not a smaller charge; a won dispute is not an
> un-chargeback.**

Stripe's dispute documentation only makes sense under this model. When a merchant *loses* a dispute,
Stripe documents that *"no money moves from your perspective"* — because the debit already happened when
the dispute opened. The loss is the **absence of a reversal entry**, not a new debit. `[DOCS]` A system
that modelled a dispute as a mutable field on the charge could not express that.

**Relevance to QAYD.** This is `AD-07` and `P-13`, already binding, already enforced by the
`trg_ledger_entries_append_only` trigger and the journal immutability triggers. `[CODE]` QAYD is not
behind here; it is in the majority.

### Split 5 — Webhook as truth vs webhook as notification

**The fork.** Does an event delivery carry the state, or only the news that state changed?

**Where they land.** Split, and *moving*. This is the one genuinely live disagreement in the corpus.

Martin Fowler's taxonomy names the two poles: **Event Notification** (an id and a link back) and
**Event-Carried State Transfer** (the full data). `[DOCS]` Most payment providers shipped the second and
are adding the first.

Stripe's v2 API introduces **thin events** carrying only `id`, `type`, `created`, `livemode`, `reason`,
`context`, and `related_object { id, type, url }`. Their stated rationale is two-part and both parts are
worth taking seriously `[DOCS]`:

1. **Freshness.** Snapshot events are described as *"a point-in-time view"* suitable for integrations that
   *"can tolerate working with eventually-consistent data."* Stripe is conceding that the fat payload is
   stale by construction — because delivery is unordered and retried for up to three days.
2. **Versioning.** Thin events are **unversioned**. A snapshot event is pinned to an API version on both
   the destination and the client, so upgrading is a two-sided coordinated change. *"This eliminates the
   need to update webhook handlers when upgrading API versions."*

The second argument is the underrated one. **A fat payload is a published schema, and every published
schema is a permanent compatibility obligation.** A thin notification has almost no schema to break.

The honest counter-argument is documented too: fetch-on-notify turns one delivery into N+1 calls, couples
your processing to the provider's API uptime, and during a provider incident your retry storm becomes a
read storm against the same degraded system. `[DOCS]` Several providers therefore offer both per event
type, which is probably the correct answer.

### Split 6 — Idempotency at the edge vs identity in the schema

**The fork.** Is retry-safety a middleware concern or a data-model property?

**Where they land.** Genuinely divided, and the division is instructive.

**Edge (Stripe, Mercury).** An `Idempotency-Key` header, a stored response, a replay. Stripe stores the
resulting **status code and body of the first request regardless of whether it succeeded or failed**, and
replays it — *including 500 errors* — for 24 hours (30 days in the v2 API). A concurrent request on a key
that is currently executing gets `409` with `idempotency_key_in_use`. A key reused with different
parameters — **or against a different endpoint** — is an `idempotency_error`. `[DOCS]`

**Schema (TigerBeetle, Modern Treasury).** The identity is the row. TigerBeetle's `Transfer.id` is a
**client-supplied, cluster-unique, immutable 128-bit integer**; a resent transfer is rejected as a
duplicate by construction, with no middleware, no TTL, and no stored response. `[DOCS]` Modern Treasury's
`external_id` is the same idea. `[DOCS]`

**Why it matters.** The edge approach handles arbitrary operations but introduces its own storage, its own
expiry, and — critically — its own **dual-write problem**, because the key record and the business fact
are two writes that must both happen. The schema approach makes retry-safety a uniqueness constraint,
which is free and unfailable, but only works when the operation's identity is expressible as a row.

**The synthesis, which Brandur Leach's widely-cited design makes explicit, is that both are needed:** the
key row lives *in the same database as the business fact and commits in the same transaction*, and
progress across any external call is recorded as a named **recovery point** on that row. `[COMMUNITY]` —
this is a reference implementation informed by, but not documentation of, Stripe's internals. See
`ARCHITECTURE.md` §4.

**This split is where QAYD's specified design is currently wrong.** See `LESSONS_FOR_QAYD.md` §3.1.

### Split 7 — Hand-written double entry vs templated posting rules

**The fork.** Does application code construct debits and credits at each call site, or does it declare a
business event that a rule engine converts?

**Where they land.** The two systems that have run longest at the largest scale independently concluded
that hand-written double entry at the call site is the bug source.

- **Adyen**: *"The only way to add new records to the accounting system is by means of templates."* The
  templates are **mathematically verified** — *"prove that every combination of amounts will result in a
  net sum of 0"* — and re-verified automatically on every template change. The resulting global invariant
  is that summing every record in the accounting system always yields zero. `[DOCS]`
- **Modern Treasury**: **Ledger Event Handlers** are templates mapping a business event type to a set of
  double-entry rules, introduced explicitly because *"engineers needed to add custom double-entry logic to
  each API call."* `[DOCS]`

**Why.** Double entry is a small language with a hard invariant. Letting every feature team write it means
every feature team can break it, and the break is silent until a trial balance fails. Centralising the
*construction* is a different (and stronger) discipline from centralising the *write*.

**Relevance to QAYD.** QAYD centralises the write — `AD-04`, exactly one writer into the ledger, realised
as `JournalEntryPostingService` with a zero-tolerance balance assertion re-derived from the lines
themselves. `[CODE]` That is Adyen's invariant enforced at the chokepoint rather than proven ahead of
time. QAYD has not centralised *construction*, and does not need to yet — there is one caller. The
question becomes real in Sprint 03 when `ClearBankTransactionAction` becomes the second constructor of a
`JournalDraft`, and again at every subsequent module. See `I-07` (the Posting Firewall) and
`IMPLEMENTATION_RECOMMENDATIONS.md` R-08.

### Split 8 — Currency: one balance or many

**The fork.** Can an account hold more than one currency?

**Where they land.** Unanimously no — but the enforcement mechanism varies, and the strictest one is the
most interesting.

- **TigerBeetle** makes it *structurally impossible*: the `ledger` field *"partitions the sets of accounts
  that can transact,"* and only same-ledger accounts transact directly. One ledger per currency. A
  cross-currency movement cannot be expressed as a single transfer; it must be a linked chain across two
  ledgers through an FX position account pair. `[DOCS]`
- **Modern Treasury** enforces it in the balance rule: debits must equal credits **within each currency**,
  explicitly *"preventing transactions that appear balanced overall while actually losing money in
  specific currencies."* `[DOCS]`
- **Adyen** and **Wise** both expose a balance account holding *multiple* balances — plural objects, one
  scalar and one currency each — never a mixed scalar. `[DOCS]`

**Why.** Modern Treasury names the failure precisely: a global sum check passes on a transaction that is
+100 USD and −100 EUR treated as zero. The money is gone and the invariant held. `[DOCS]` This is a real
bug class that a naive "does it sum to zero" assertion cannot catch.

**Relevance to QAYD.** QAYD's posting engine asserts balance in **both** the entry currency and the base
currency, with zero tolerance, re-derived from the lines. `[CODE]` That catches the single-currency case
and the base-currency case. It does **not** catch a three-currency entry that balances in base but not
per-currency, because `assertBalanced` sums all lines' `debit`/`credit` regardless of each line's
`currency_code`. See `LESSONS_FOR_QAYD.md` §3.3 — this is a real, small, currently-latent gap.

---

## 3 · The reconciliation problem, stated once

Every merchant-facing system in the corpus publishes reports for the same reason: **a merchant's books and
a processor's books disagree at every instant, structurally, for at least nine independent reasons.**

1. **Availability lag.** A charge captured today has `available_on` at T+2 or T+3. `[DOCS, Stripe]`
2. **Payout batching lag.** Availability and payout are separately scheduled; changing the payout schedule
   *"doesn't change how long it takes for your pending balance to become available."* `[DOCS, Stripe]`
3. **Cut-off boundaries.** Square's US batch covers 5PM PST to 5PM PST and explicitly *"creat[es]
   discrepancies between the Dashboard balance and the bank deposit."* `[DOCS]` Two systems on different
   day boundaries disagree at the boundary permanently, not transiently.
4. **Net settlement.** The deposit is gross minus fees, so it never equals booked revenue. `[DOCS]`
5. **Disputes in flight.** Funds are withdrawn at dispute open and possibly returned weeks later as a
   separate entry. `[DOCS, Stripe]`
6. **Reserves.** `connect_reserved_funds`, `risk_reserved_funds`, Adyen's `ReserveAdjustment` — balance
   that exists but is not payable. `[DOCS]`
7. **Report SLA.** Stripe's reports compute at 00:00 UTC and are available by 12:00 the next day. The
   reconciliation data is itself T+1 relative to the events. `[DOCS]`
8. **Failed payouts.** Money leaves the balance, does not arrive, and returns as a separate entry
   (`payout_reversal`, `BankInstructionReturned`). `[DOCS]`
9. **Non-reconcilable instruments.** Stripe states plainly that instant payouts *cannot* be tied to
   constituent transactions: *"Stripe can't identify which transactions are included in each payout."*
   `[DOCS]`

And the discipline that closes it is the same everywhere, published by two processors in the same shape:

> **starting balance + activity − payouts = ending balance**

Stripe's `balance.summary` report has exactly those four rows. `[DOCS]` Adyen's equivalent is that the sum
of `Net Credit` for a `Batch Number` equals the bank payout. `[DOCS]` Square's is that `PayoutEntry`
net amounts sum to `Payout.amount_money`. `[DOCS]`

The structural requirement this implies — a **clearing / funds-in-transit account** whose balance at any
moment equals the processor's pending-plus-available balance, with the difference being the entire
exception queue — is developed in `ARCHITECTURE.md` §7 and mapped to S3-06 in
`IMPLEMENTATION_RECOMMENDATIONS.md`.

---

## 4 · GCC reality — the finding that reshapes Sprint 03

This section is short because the answer is short, and it is the most operationally consequential
paragraph in the study.

**None of the twelve companies gives a Kuwaiti SME programmatic access to its bank account data.**

| Provider | Kuwait merchant onboarding | Evidence |
|---|---|---|
| Stripe | **No.** UAE is the sole GCC entry on the supported-countries list | `[DOCS]` |
| Adyen | KNET page is headed *"no longer supported"*; local entity required | `[DOCS]` |
| Square | **No.** Eight countries, none in MENA | `[DOCS]` |
| Plaid | **No.** North America, UK, Europe — no MENA coverage at all | `[DOCS]` |
| PayPal | **Yes** — "Send, receive, and withdraw" for KW | `[DOCS]` |
| Checkout.com | Supports KNET, but requires a **direct contract with the local provider** | `[DOCS]` |
| Wise | Send *to* Kuwait in KWD; KW account opening `[UNKNOWN]` | `[DOCS]` / `[UNKNOWN]` |
| Mercury / Brex / Ramp | US-entity products | `[DOCS]` / `[UNKNOWN]` |
| Airwallex / Mollie | `[UNKNOWN]` / not supported | `[UNKNOWN]` / `[COMMUNITY]` |

**KNET** is Kuwait's dominant rail: *"All debit cards issued are mandated to be branded with this local
brand… Out of 5 million issued cards, 80% are debit and therefore KNET."* `[DOCS, Adyen]` It is a
consortium of *"the 11 member banks of Kuwait."* `[DOCS, Checkout.com]` **No public KNET API documentation
was found**, and every global PSP that fronts it describes integration as requiring a local contract
mediated through them — strong circumstantial evidence that no self-serve public API exists, though
`knet.com.kw` returned HTTP 403 on every attempt so the absence is not positively confirmed.
`[UNKNOWN]`

**Kuwait has no open banking regime.** The Central Bank of Kuwait licenses e-payment providers (EPSP,
EMSP, EPSO) and runs a regulatory sandbox, but has **no account-information-service licence category, no
payment-initiation licence category, no third-party-provider framework, and no API standard.** `[DOCS]`
The regime licenses *moving* money and does not address *reading* bank data. Compare Bahrain, whose
framework has been mandatory since 2019 and licenses AISPs and PISPs `[DOCS]`, and Saudi Arabia, whose
SAMA framework includes published API specifications and a conformance lab `[DOCS]`.

**Therefore, for a Kuwaiti SME today, bank transaction data reaches accounting software by file.** CSV,
XLS, PDF or MT940 exported from a corporate banking portal. There is no licensed alternative, no
aggregator with coverage, and no national-switch API. `[INFERENCE]`, from three independently verified
negatives.

**This validates Sprint 03's scope rather than constraining it.** S3-04 ships CSV statement import with
per-bank saved column mappings and a hard tie-out gate. That is not an MVP compromise on the way to an
Open Banking feed — **in Kuwait it is the correct and currently the only channel**, and the deferred
`SyncOpenBankingJob` has no rail to connect to in the home market. The regional PSPs that *do* publish
APIs — Tap Payments (the best documented, with webhook `hashstring` validation and an idempotency guide),
UPayments (which commits publicly to next-business-day settlement), MyFatoorah — expose **only that
merchant's own payment transactions**, never the bank account. `[DOCS]` They are a future revenue-side
integration, not a statement source.

---

## 5 · What the landscape says QAYD should carry forward

Condensed; each is argued properly in `BEST_PRACTICES.md` and mapped in `LESSONS_FOR_QAYD.md`.

1. **QAYD is already on the right side of five of the eight splits** — separated debits and credits,
   correction by reversal, append-only projection, one writer, per-currency awareness. That is not luck;
   it is `AD-03`/`AD-04`/`AD-07` doing their job.
2. **The two-phase model is the shape Sprint 03 already has.** `bank_transactions` moving
   `draft → … → cleared → reconciled`, with the `cleared ⇒ journal_entry_id NOT NULL` CHECK, is
   structurally the pending/posted split. Naming it as such makes the available-balance asymmetry
   available for free later.
3. **The idempotency design in `docs/api/API_ARCHITECTURE.md` has a dual-write hole** and needs an ADR.
   This is R-01.
4. **The outbox declared by `AD-14`/`ADR-0006` should land with S2-13, not after it.** The payments corpus
   is unanimous that at-least-once plus idempotent consumers is the only workable delivery model, and
   S2-13 is the story that first creates a consumer.
5. **`R-24` is the most important rejection in the knowledge base for Sprint 03.** Plaid's documentation
   independently proves it: *"The pending and posted versions of a transaction may not necessarily share
   the same details: their name and amount may change."* `[DOCS]` A bank's own data contradicts
   amount-equality matching.
6. **Kuwait's rails are file-based.** Build the CSV channel properly; it is the product, not the stopgap.
