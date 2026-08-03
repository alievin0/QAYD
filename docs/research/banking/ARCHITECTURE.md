# ARCHITECTURE.md — Core Banking Ledger Architecture

**How core banking systems actually model, store, and prove a ledger.**
Data models · algorithms · state machines · failure modes.

Version 1.0 · 2026-07-28 · Research artifact, not a specification.
Evidence grades: `[DOCS]` `[CODE]` `[COMMUNITY]` `[INFERENCE]` `[UNKNOWN]`

> **No code from any studied system is reproduced here.** Type and field *names* appear only where
> naming a concept is the shortest honest way to ground a claim. Every diagram is drawn for this
> document.

---

## Table of contents

1. [The reference model: what a bank ledger actually is](#1-the-reference-model)
2. [Thought Machine Vault — the posting/balance model in depth](#2-thought-machine-vault)
3. [TigerBeetle — the debit/credit database](#3-tigerbeetle)
4. [The openly-documented API ledgers](#4-the-openly-documented-api-ledgers)
5. [The opaque cores: Temenos, Finastra, FIS, Fiserv, Mambu](#5-the-opaque-cores)
6. [Tri-temporal dating: value, booking, insertion](#6-tri-temporal-dating)
7. [Balance derivation vs stored balances](#7-balance-derivation-vs-stored-balances)
8. [The hot-account problem](#8-the-hot-account-problem)
9. [Idempotency and exactly-once under partition](#9-idempotency-and-exactly-once)
10. [Integrity proofs: hash chains, Merkle trees, anchoring, control totals](#10-integrity-proofs)
11. [PostgreSQL mechanisms for banking-grade invariants](#11-postgresql-mechanisms)
12. [Where QAYD stands today, mapped onto this model](#12-where-qayd-stands-today)

---

<a name="1-the-reference-model"></a>
## 1. The reference model: what a bank ledger actually is

Strip the vendors away and every core banking ledger is the same five-part object. Understanding the
parts separately is what makes the vendor differences legible.

```
                     ┌───────────────────────────────────────────────┐
                     │  1. INSTRUCTION  (what someone asked for)     │
                     │     - client-supplied id  → idempotency       │
                     │     - a batch is the atomic unit              │
                     └───────────────────┬───────────────────────────┘
                                         │  validated, never mutated
                                         ▼
                     ┌───────────────────────────────────────────────┐
                     │  2. POSTINGS     (the immutable movements)    │
                     │     - N legs, each (account, dir, amount)     │
                     │     - Σ debits = Σ credits, exactly            │
                     │     - APPEND ONLY. no update verb exists      │
                     └───────────────────┬───────────────────────────┘
                                         │  deterministic fold
                                         ▼
                     ┌───────────────────────────────────────────────┐
                     │  3. BALANCES     (the derived state)          │
                     │     - keyed by a COORDINATE, not just account │
                     │     - maintained, but always re-derivable     │
                     └───────────────────┬───────────────────────────┘
                                         │
                     ┌───────────────────┴───────────────────────────┐
                     │  4. RULES        (what may be posted at all)  │
                     │     - evaluated BEFORE acceptance             │
                     │     - product config, not application code    │
                     └───────────────────┬───────────────────────────┘
                                         │
                     ┌───────────────────┴───────────────────────────┐
                     │  5. PROOF        (evidence it wasn't altered) │
                     │     - hash chain / digest / control totals    │
                     │     - re-derivation as a continuous assertion │
                     └───────────────────────────────────────────────┘
```

**The single most important structural claim in this document:** in core banking, parts 2 and 3 are
*different objects with different mutability rules*. Postings are immutable facts. Balances are a
cache of a fold over those facts. Accounting software routinely collapses them — storing a mutable
`balance` column that is the only record of the truth — and that collapse is the origin of an entire
class of unfixable bugs. QAYD already refuses that collapse (`ledger_entries` is append-only,
`signed_base_amount` makes the fold a single `SUM()`); this document is largely about the *other four*
parts, where QAYD has more to gain.

### 1.1 Why the stakes changed the engineering

| | Accounting software | Core banking |
|---|---|---|
| A lost posting means | a wrong report, fixed next month | a regulatory breach and a customer whose money vanished |
| Who audits it | an external auditor, annually, on samples | a regulator, continuously, with the power to halt operations |
| Correction mechanism | often an in-place edit before close | *always* a new compensating posting; never an edit |
| Balance authority | frequently a stored column | almost always derived-and-verified |
| Proof obligation | "the trial balance balances" | "here is a cryptographic/control-total proof for a stated period" |
| Latency budget | seconds (batch overnight) | double-digit milliseconds, 24×7 |

The rightmost column bought a set of techniques. Sections 6–10 are those techniques. Section 12 and
`LESSONS_FOR_QAYD.md` decide which of them an SME accounting product should pay for.

---

<a name="2-thought-machine-vault"></a>
## 2. Thought Machine Vault — the posting/balance model in depth

**Evidence position.** Thought Machine's reference documentation sits behind a client login. However,
the **Contracts SDK** — the Python package Thought Machine distributes so clients can write and unit-test
Smart Contracts offline — is publicly mirrored, and it contains the *authoritative type system and
posting algebra* of the Vault ledger, including docstrings written by Thought Machine. Everything in
this section marked `[CODE]` is recovered from that SDK
(`contracts_sdk/` — `utils/symbols.py`, `utils/posting_logic.py`,
`versions/version_400/common/types/{enums,postings,balances,hook_arguments}.py`; public mirror at
`github.com/ingjohnaragon-crypto/spec-driven-project`, also mirrored by other users). Performance
figures are `[DOCS]` from Thought Machine's own whitepaper.

This is the **most valuable single body of evidence in the whole research programme**, because it is a
complete, real, production core-banking ledger model rather than a marketing description of one.

### 2.1 The balance coordinate — the central idea

A Vault balance is not keyed by account. It is keyed by a **four-part coordinate** `[CODE]`:

```
BalanceCoordinate = ( account_address , asset , denomination , phase )

   account_address : str   e.g. "DEFAULT", "ACCRUED_INTEREST_PAYABLE", "PENALTIES"
   asset           : str   default "COMMERCIAL_BANK_MONEY"
   denomination    : str   e.g. "KWD", "USD"
   phase           : enum  COMMITTED | PENDING_IN | PENDING_OUT
```

and the value at that coordinate is **not a number** — it is a triple `[CODE]`:

```
Balance = ( credit : Decimal , debit : Decimal , net : Decimal )

   net = (credit - debit) * TSIDE_SIGN[tside]
   where TSIDE_SIGN = { LIABILITY: +1 , ASSET: -1 }
```

Four things follow, and each is a design lesson in its own right.

**(a) Gross totals are preserved, not just the net.** A Vault balance always carries the *sum of all
credits* and the *sum of all debits* independently. You can therefore answer "how much has ever flowed
out of this address" without scanning postings. Netting is lossy; Vault refuses to be lossy at the
balance layer. `[CODE]`

**(b) The sign convention is a property of the account, not the report.** `Tside` (ASSET / LIABILITY)
is carried on the *posting instruction itself* — the SDK exposes `tside` on the instruction as "the
treasury side of the target account. Determines the Account Balance net sign." `[CODE]` A contract
never writes `if account_is_asset then …`; it reads `balance.net` and the sign is already correct.
This is the difference between a normal-balance convention that is *encoded in data* versus one that
is *re-derived in every report* — and re-deriving it in every report is exactly how sign bugs get
into financial statements.

**(c) One account holds many balances.** `account_address` is a free-form string, so a single product
account carries a whole family of sub-balances — principal, accrued interest, fees, penalties, each a
separate address — under one account identity. This is a **chart of accounts nested inside an
account**. It is why a mortgage in Vault is one account and not eleven. `[CODE]`

**(d) `phase` is a first-class dimension of the balance, not a status on the transaction.** This is
the piece accounting systems consistently miss, and it gets its own subsection.

### 2.2 Phase: pending is a *balance*, not a *flag*

```
   Phase.COMMITTED     — settled. this is the accounting balance.
   Phase.PENDING_OUT   — authorised outbound, not yet settled (a hold on funds)
   Phase.PENDING_IN    — authorised inbound, not yet settled (an expected credit)
```
`[CODE]` — SDK docstring: *"The availability of a given Balance."*

The consequence is that "available balance" is not a computed report — it is arithmetic over
coordinates that already exist:

```
   available( account , KWD )  =  net@(DEFAULT, KWD, COMMITTED)
                                - |net@(DEFAULT, KWD, PENDING_OUT)|
```

and the *accounting* balance — the one that appears in a general ledger — is the `COMMITTED`
coordinate alone. **The two numbers never contend, because they are different rows.** A system that
models pending as `transactions.status = 'pending'` must filter on status in every single balance
query, and every query that forgets to is a silent bug.

### 2.3 The ClientTransaction state machine

Vault chains posting instructions into a **ClientTransaction** — the lifecycle of one economic event.
The SDK enforces the chaining rules explicitly `[CODE]`:

```
PRIMARY (may start a chain, creates PENDING_* balances):
    InboundAuthorisation | OutboundAuthorisation

SECONDARY (may only continue an existing chain):
    AuthorisationAdjustment | Settlement | Release

NON-CHAINABLE (single-shot, straight to COMMITTED):
    InboundHardSettlement | OutboundHardSettlement | Transfer

CUSTOM:
    CustomInstruction  (a chain of these may only follow a CustomInstruction)
```

The state machine, with the SDK's own enforced invariants:

```
                       ┌─────────────────────────────────────────┐
                       │            (no chain yet)               │
                       └───┬──────────────────┬──────────────────┘
              Authorisation│                  │HardSettlement / Transfer
                           ▼                  ▼
            ┌──────────────────────┐    ┌───────────────────┐
            │      AUTHORISED      │    │    COMMITTED      │  (terminal,
            │  PENDING_OUT/IN ≠ 0  │    │  single instr.    │   non-chainable)
            └───┬────────┬─────────┘    └───────────────────┘
                │        │
   Authorisation│        │Settlement (final=false)
     Adjustment │        │   → partial settle, chain stays open
     (± amount) │        ▼
                │   ┌──────────────────────────┐
                └──►│  PARTIALLY SETTLED       │
                    │  some PENDING → COMMITTED│
                    └───┬──────────────────┬───┘
        Settlement(final=true)          Release
                        ▼                  ▼
                 ┌────────────┐     ┌────────────┐
                 │ COMPLETED  │     │  RELEASED  │
                 └────────────┘     └────────────┘
                        ▲                  ▲
                        └────── terminal ──┘
```

Enforced invariants, quoting the SDK's own exception messages `[CODE]`:

| Invariant | Enforcement |
|---|---|
| A chain may not **start** with a secondary instruction | `"A ClientTransaction cannot start with {type}"` |
| A chain may not be **extended** once completed or released | `"Client Transaction (id …) has already been finalised"` |
| A settlement/release may only follow an **authorisation** | rejected unless the first instruction was an In/OutboundAuthorisation |
| **No backdating within a chain** | `"ClientTransaction does not support backdating"` — each instruction's datetime must be ≥ the previous one's |
| `final=true` is meaningful **only** on a Settlement | `"Final flag can only be used with Settlement Posting Instructions"` |
| Every posting in a chain targets **one account** | rejected if `account_id` differs from the transaction's account |

Read that table as a specification of *what a correction model must forbid*. QAYD's reversal design
(`P-13`, S2-06) is the same shape of problem: a terminal state must be terminal, and the rule must be
enforced where it cannot be bypassed. Vault enforces it in the ledger engine; QAYD's equivalent must
be a DB constraint, not an Action-layer check.

**Partial settlement is the sharpest idea here.** A `Settlement` with `final=false` moves *some* of the
pending amount to committed and leaves the chain open. That is a genuinely hard modelling problem
(a KWD 500 hotel authorisation settling at KWD 437.250) and Vault solves it by making "how much is
still pending" a *balance*, not a subtraction the caller has to get right.

### 2.4 The batch is the atomic unit

`[CODE]` — SDK docstrings:

- `batch_id` — *"The id of the batch of posting instructions that get **atomically inserted into the ledger**."*
- `batch_details` — *"batch-level metadata attached to the list of posting instructions that get **atomically accepted or rejected**."*
- `client_batch_id` — *"An id which allows related posting instructions (for example, interest accrual payments) to be associated with each other."*

So the transactional boundary is the **batch**, and a batch spans multiple accounts and multiple
client transactions. This is what makes a transfer between two customer accounts a single ledger fact
rather than two hopefully-consistent ones.

`client_batch_id` is the caller's handle; `batch_id` is Vault's. That two-id split is the same pattern
as an HTTP `Idempotency-Key` versus a server-assigned resource id, and it is the correct shape
(§9).

### 2.5 Rules run before acceptance, and they are product configuration

Vault evaluates a Smart Contract **hook** on the *proposed* postings before they are committed:

```
 Postings API request
        │
        ▼
  ┌─────────────────────────────────────────────────────────────┐
  │ pre_posting_hook(effective_datetime, client_transactions)   │
  │   sees: the PROPOSED ClientTransactions + fetched balances  │
  │   returns: Rejection  ─────────────────►  batch REJECTED    │
  │            (nothing)  ─────────────────►  continue          │
  └───────────────────────────┬─────────────────────────────────┘
                              ▼
                    ┌───────────────────────┐
                    │ COMMIT batch (atomic) │
                    └───────────┬───────────┘
                                ▼
  ┌─────────────────────────────────────────────────────────────┐
  │ post_posting_hook(...)                                      │
  │   sees: the COMMITTED ClientTransactions                    │
  │   returns: further posting directives (fees, interest, …)   │
  └─────────────────────────────────────────────────────────────┘
```
`[CODE]` — the SDK's `PrePostingHookArguments` / `PostPostingHookArguments` differ *only* in the
docstring phrase "**proposed** posting instructions to be committed" vs "posting instructions that have
**just been committed**". The argument shapes are otherwise identical, which is itself elegant: the same
contract logic can reason about a hypothetical and an actual with one data type.

The structured rejection vocabulary is enumerated `[CODE]`:

```
RESTRICTION_PREVENT_DEBITS   RESTRICTION_LIMIT_DEBITS   RESTRICTION_REVIEW_DEBITS
RESTRICTION_PREVENT_CREDITS  RESTRICTION_LIMIT_CREDITS  RESTRICTION_REVIEW_CREDITS
INSUFFICIENT_FUNDS   WRONG_DENOMINATION   AGAINST_TERMS_AND_CONDITIONS
ACCOUNT_STATUS_INVALID   CLIENT_CUSTOM_REASON
```

Two design points worth stealing conceptually:

1. **Rejections are a closed enumeration with an explicit escape hatch** (`CLIENT_CUSTOM_REASON`).
   Machine-actionable by default, extensible without a schema change.
2. **`REVIEW_*` is a first-class outcome** alongside prevent and limit. The ledger natively supports
   "this posting is allowed but must be looked at" — which is precisely the state an AI-drafted
   journal entry occupies.

Two more escape hatches, both instructive `[CODE]`:

- `advice` — *"indicates that the Smart Contract should skip balance checks for this posting
  instruction"*. The real-world case: a card scheme has already authorised offline and the bank is
  obliged to honour it. **The override exists, is named, and is carried on the instruction** — so it is
  auditable. Compare with the accounting-software habit of a hidden `force=true` code path.
- `override_all_restrictions` — *"whether to ignore all restrictions"*. Same principle: the bypass is
  data on the record, not a branch in the code.

### 2.6 Parameters: three levels of product configuration

`ParameterLevel = GLOBAL | TEMPLATE | INSTANCE`, with
`ParameterUpdatePermission = FIXED | OPS_EDITABLE | USER_EDITABLE | USER_EDITABLE_WITH_OPS_PERMISSION`
`[CODE]`.

That is a complete answer to "who may change this number, and at what scope" expressed as data.
The accounting-software equivalent question — *who may change the VAT rate, the fiscal calendar, the
rounding account?* — is usually answered by scattering permission checks through controllers.

### 2.7 Published performance envelope

`[DOCS]` — thoughtmachine.net/whitepapers/performance-proven-the-right-way, certified results at
**30M accounts / 1B+ transactions**:

| Workload | Throughput | Latency (p95) |
|---|---|---|
| High-priority transactions | 4,769 TPS | < 500 ms |
| Low-priority transactions | 6,768 TPS | < 200 ms |
| Account opening | 2,000 TPS | < 1,500 ms |
| Live balance enquiry | 15,035 TPS | < 200 ms |
| End-of-day processing | up to 20,676 TPS | 90 min total |

Lab study at 70M accounts: 8,000 TPS high-priority under 151 ms; 8,000 TPS balance enquiry under 42 ms
(p95). `[DOCS]`

The methodological claim matters more than the numbers: *"every test request passes through the same
public APIs, internal services, event streams, and database tables as a live, production
transaction"* `[DOCS]` — an explicit repudiation of benchmarking through a hardwired fast path. That
is a testing principle QAYD can adopt at zero cost and should.

**Note the shape of the numbers:** balance enquiry is ~3× the throughput of a posting and ~2.5× faster.
Reads dominate and are optimised separately. And end-of-day still exists — even in a real-time
cloud-native core, there is a batch consolidation window.

`[UNKNOWN]`: the underlying storage engine, partitioning scheme, replication topology, and whether
balances are materialised per coordinate or folded on read. Thought Machine does not publish it and
this document will not guess.

---

<a name="3-tigerbeetle"></a>
## 3. TigerBeetle — the debit/credit database

**Evidence position.** TigerBeetle is fully open source with an unusually rich published design
rationale (`docs.tigerbeetle.com`, `docs/ARCHITECTURE.md`, `docs/TIGER_STYLE.md`, the engineering
blog, plus an independent Jepsen analysis). It is not on the vendor list this research was
commissioned against, and it is included anyway because it is the only place where the *reasoning*
behind a production debit/credit engine is written down in full.

**What it is:** a purpose-built financial transactions database. Two entity types, eight request
types, no SQL, no schema. It is a *data-plane* system — the hot path of transaction processing — and
it says so explicitly, expecting a general-purpose database beside it for everything else `[DOCS]`.

### 3.1 The entire schema, and why it is fixed-size

```
   Account  (128 bytes)                      Transfer  (128 bytes)
   ┌────────────────────────────┐            ┌────────────────────────────┐
   │ id                u128 16B │            │ id                u128 16B │ ← idempotency key
   │ debits_pending    u128 16B │            │ debit_account_id  u128 16B │
   │ debits_posted     u128 16B │            │ credit_account_id u128 16B │
   │ credits_pending   u128 16B │            │ amount            u128 16B │
   │ credits_posted    u128 16B │            │ pending_id        u128 16B │ ← two-phase link
   │ user_data_128     u128 16B │            │ user_data_128     u128 16B │
   │ user_data_64       u64  8B │            │ user_data_64       u64  8B │ ← "the real-world when"
   │ user_data_32       u32  4B │            │ user_data_32       u32  4B │
   │ reserved                4B │            │ timeout            u32  4B │
   │ ledger             u32  4B │ ← currency │ ledger             u32  4B │
   │ code               u16  2B │ ← category │ code               u16  2B │
   │ flags              u16  2B │ ← invariant│ flags              u16  2B │
   │ timestamp          u64  8B │ ← server   │ timestamp          u64  8B │ ← server
   └────────────────────────────┘            └────────────────────────────┘

   AccountBalance and AccountFilter are ALSO exactly 128 bytes.
   The entire wire protocol is 128-byte fixed records.
```
`[DOCS]` docs.tigerbeetle.com/reference/{account,transfer,account-balance,account-filter}

The published rationale for the fixed size: *"1 debit/credit is 128 bytes, or 2 CPU cache lines of
information"* `[COMMUNITY]` tigerbeetle.com/blog/2024-07-23-rediscovering-transaction-processing-…,
which is what allows *"up to 8000 debit/credits in a 1 MiB query"*. And the fixed schema *"avoids the
need to add columns, tables, and complex relations … and avoids complex schema migrations"* `[DOCS]`
docs.tigerbeetle.com/concepts/debit-credit/.

**Immutability is total and published verbatim:** transfers *"cannot be modified after creation"* and
*"cannot be deleted after creation"*; accounts cannot be deleted at all `[DOCS]`. Corrections are new
offsetting transfers, and the published justification is the one an accountant would give — it
preserves *"the original error, when it took place, as well as any attempts to correct the record and
when they took place"* `[DOCS]` docs.tigerbeetle.com/coding/recipes/correcting-transfers/.

### 3.2 Balance invariants enforced by the database, not the application

Two `Account.flags` bits carry the whole normal-balance rule set `[DOCS]`:

| Flag | Rejects any transfer that would make | Accounting meaning |
|---|---|---|
| `debits_must_not_exceed_credits` | `debits_pending + debits_posted + amount > credits_posted` | a liability/equity/income account cannot go debit-negative |
| `credits_must_not_exceed_debits` | `credits_pending + credits_posted + amount > debits_posted` | an asset/expense account cannot go credit-negative |

They are **mutually exclusive** and setting both returns `flags_are_mutually_exclusive` `[DOCS]`.
Note that the check includes the *pending* amounts — a reservation counts against the limit before it
settles, which is the only correct behaviour and is easy to get wrong when pending is a status flag.

The generalised statement worth carrying: **the invariant lives on the account, in the engine, and
cannot be bypassed by any caller.** That is the same posture as QAYD's `trg_no_ai_autopost` and
append-only triggers, applied to a different invariant.

Also `closed` — an account that *"will reject further transfers, except for voiding two-phase
transfers"* `[DOCS]`. Elegantly, closure is applied *via a pending transfer*, so it is reversible by
voiding, and reopening *"does not revert the net balance"* `[DOCS]`
docs.tigerbeetle.com/coding/recipes/close-account/. Compare with the accounting habit of a boolean
`is_active` column with no record of who set it or when.

### 3.3 Two-phase transfers — pending is four fields, not a status

```
                       create transfer, flags.pending, amount A, timeout T
                                          │
                                          ▼
              debits_pending += A ; credits_pending += A        (posted UNCHANGED)
                                          │
        ┌─────────────────────────┬───────┴────────────────┬─────────────────────┐
        ▼                         ▼                        ▼                     ▼
  post, amount = A         post, amount = X < A      post, AMOUNT_MAX        void  |  timeout
  pending -= A             pending -= A              pending -= A            pending -= A
  posted  += A             posted  += X              posted  += A            posted unchanged
                           (A − X returns to
                            the original accounts)
```

Published guarantees `[DOCS]` docs.tigerbeetle.com/coding/two-phase-transfers/:

- A pending transfer *"reserves its `amount` in the debit/credit accounts' `debits_pending` /
  `credits_pending` fields … Pending transfers leave the `debits_posted`/`credits_posted`
  unmodified."*
- **Resolution can never break an invariant.** *"The second step in a two-phase transfer will never
  cause the accounts' configured balance invariants … to be broken, whether the second step is a post
  or void."* Achieved by validating *pessimistically at reserve time*: a pending transfer that could
  violate a constraint at settlement is rejected up front. This is a genuinely subtle and important
  design choice — it moves the failure to the moment the customer is still on the phone.
- **The pending transfer is never mutated.** Resolution *"does not involve modifying the pending
  transfer. Instead you create a new transfer."*
- `AMOUNT_MAX` (2^128 − 1) as a sentinel meaning "post whatever was pending" — so a retrying client
  need not first look up the amount. A small idea with real value for idempotent retries.
- `timeout` is *"an interval in seconds, rather than an absolute timestamp, because it is also
  managed by the primary"* `[DOCS]` docs.tigerbeetle.com/coding/time/ — an explicit clock-skew
  mitigation. A client that sends an absolute expiry sends its own possibly-wrong clock.

State-machine errors are distinct and specific: `pending_transfer_already_posted`,
`pending_transfer_already_voided`, `pending_transfer_expired` `[DOCS]`.

### 3.4 Linked chains — atomicity across events

`flags.linked` chains events so *"these chains of events will all succeed or fail together"*, executed
in order with each event's effect *"visible to the next"*, and *"each chain is either visible or
invisible as a unit to subsequent transfers"* `[DOCS]` docs.tigerbeetle.com/coding/linked-events/.

The chain terminates at the first event *without* the flag; a request whose last event has `linked`
set is rejected as `linked_event_chain_open` — an open-ended chain is a schema error, not a runtime
surprise. Every non-first failure in a chain reports `linked_event_failed` while the actual culprit
reports its own specific code `[DOCS]`. That is a well-designed error surface: one root cause, N
clearly-marked collateral failures.

**Why it matters conceptually:** TigerBeetle deliberately does *not* support multi-debit/multi-credit
transfers — *"the database only supports simple transfers with a single debit and a single credit"*
`[DOCS]` docs.tigerbeetle.com/coding/recipes/multi-debit-credit-transfers/. An N-leg journal entry is
composed from linked 1:1 transfers, optionally through a control account. So the *primitive* is
minimal and the *composition* is atomic — the opposite of the accounting convention where the
multi-leg entry is the primitive.

### 3.5 Idempotency — the sharpest published treatment anywhere

`id` is the idempotency key, client-generated `[DOCS]`
docs.tigerbeetle.com/coding/reliable-transaction-submission/. Three details are unusually well thought
through and all three are directly transferable:

**(a) `exists` results are field-discriminating.** A retry does not merely return "already exists" —
it returns *which field differs* `[DOCS]` docs.tigerbeetle.com/reference/requests/create_transfers/:

```
   exists                              exists_with_different_flags
   exists_with_different_amount        exists_with_different_debit_account_id
   exists_with_different_ledger        exists_with_different_code
   exists_with_different_user_data_128 / _64 / _32
```

This is the request-fingerprint conflict check of §9.3, but *itemised*. A client whose retry
accidentally mutated a field is told exactly which one. QAYD's planned `409
idempotency_key_conflict` is the right behaviour; adding *which field diverged* to the error body is
a cheap and material improvement to debuggability, particularly for AI-generated requests.

**(b) `id_already_failed` — idempotency keys are poisoned by transient failure.** *"A previous
transfer with the same `id` failed due to transient errors. Retrying with the same ID will always
fail; use a new idempotency ID to retry."* `[DOCS]` Transient errors explicitly include
`exceeds_credits` and `debit_account_not_found`.

This is the single most counter-intuitive published idea in the whole corpus and it is *correct*.
"Retry forever with the same key" is wrong: if the first attempt failed for a business reason, the
retry is a *new* business attempt and must carry a new key, or the system cannot distinguish "the
same request again" from "a fresh request that happens to reuse an id". Most idempotency
implementations, including most written from the Stripe pattern, get this wrong.

**(c) The end-to-end argument for client-generated ids.** The published pattern: the client generates
the id *"as far upstream as possible"*, **persists it locally before submission**, and reuses it on
every retry `[DOCS]`. Rationale: it *"handles potential restarts of the app or browser while the
request is in flight"*. A server-generated id cannot survive a lost response — the client does not
know what to ask about.

**(d) IDs must be time-ordered, not random.** *"Random identifiers are not recommended — they can't
take advantage of all of the LSM optimizations"* and have *"significantly lower throughput than
strictly-increasing ULIDs"* `[DOCS]` docs.tigerbeetle.com/coding/data-modeling/. The recommended
scheme is 48 bits of millisecond timestamp + 80 bits of randomness. The mechanism: sequential runs
cluster inserts at the hot end of the tree; UUIDv4 scatters them uniformly and destroys locality.
`[UNKNOWN]` — no published quantification of the penalty.

### 3.6 How correctness is actually achieved

This is the part with no equivalent in accounting software, and it is the most transferable *culture*
in this research even where the techniques themselves are not.

**Deterministic simulation — the VOPR.** A simulator that runs *"many clusters of TigerBeetle
replicas and clients … all in a single process"* with every non-deterministic component stubbed,
injecting network faults (drops, reordering, partitions), storage faults (corrupted reads and writes)
and clock manipulation `[CODE]` docs/internals/vopr.md. Published operating scale: *"1000x speed"*,
running *"24/7 on 1024 cores"* `[DOCS]` docs.tigerbeetle.com/concepts/safety/. ⚠️ The marketing site
states 700x for the same thing — **the two published figures disagree** and this document reports
both rather than choosing.

The property that makes it useful is reproducibility: *"Because our simulator is deterministic based
on a seed number and the Git commit, we can perfectly reproduce any bugs."* A failure is a
`(seed, commit)` pair that replays exactly on a laptop.

**Static memory allocation.** *"All memory must be statically allocated at startup. No memory may be
dynamically allocated (or freed and reallocated) after initialization."* `[CODE]` docs/TIGER_STYLE.md.
The published reasons `[COMMUNITY]` tigerbeetle.com/blog/2022-10-12-a-database-without-dynamic-memory/
are more interesting than the rule: it makes **overload handling trivial** (there is no buffer to
grow when a misbehaving client floods you), makes resource use predictable to the operator, prevents
resource deadlock, improves cache behaviour, and — the best argument — *"if we then get the static
allocation calculation wrong, this can be surfaced sooner through fuzzing, rather than having no
limits and eventual resource exhaustion (and cascading failure!) in production."*

**Assertions everywhere, in release builds.** *"a minimum of two assertions per function"*; assert the
positive space *and* the negative space; for every property find *"at least two different code paths
where an assertion can be added"* (e.g. before writing to disk *and* after reading back) `[CODE]`
TIGER_STYLE.md. The stated purpose is exact: assertions *"downgrade catastrophic correctness bugs into
liveness bugs"*. **A system that crashes is recoverable; a system that silently computes the wrong
balance is not.** That sentence is the entire thesis of banking-grade engineering in nine words, and
it applies verbatim to an accounting ledger.

**An explicit storage fault model.** *"TigerBeetle assumes that its disk _will_ fail."* All data is
*"immutable, checksummed, and hash-chained"*; block pointers carry an out-of-band checksum alongside
the address so misdirected I/O is detectable and repairable from peers; the superblock hash-chains to
its predecessor `[DOCS]`/`[CODE]`. Writes use `O_DIRECT`, and *"no file system is necessary"* — it
manages its own page cache to minimise the layers it must trust `[DOCS]`
docs.tigerbeetle.com/concepts/safety/. Protocol-Aware Recovery means *"a record would need to get
corrupted on all replicas in a cluster to get lost, and even in that case the system would safely
**halt**"* — halting rather than serving corrupt data is the deliberate choice.

**Independent verification.** Jepsen tested it and concluded *"TigerBeetle appeared to meet its
promise of Strong Serializability"* `[COMMUNITY]` jepsen.io/analyses/tigerbeetle-0.16.11, finding 2
safety issues and 7 crash bugs, all fixed. Notably Jepsen also found an *availability* weakness: the
ring replication topology made single-node failures spike latency by *"three to five orders of
magnitude"*. Worth recording because it is the honest counterweight to the safety story.

### 3.7 The hot-account argument, quantified

TigerBeetle publishes the clearest statement of §8's problem that exists anywhere `[COMMUNITY]`
tigerbeetle.com/blog/2024-07-23-rediscovering-transaction-processing-…:

> For each financial transaction, there would be 10–20 SQL queries back and forth across the network,
> **while holding row locks** … if a debit/credit required a minimum of 2 network RTTs while holding
> locks, and with an RTT of 0.5 ms, then other transactions would not be able to obtain the held locks
> for **at least 1 ms**.

That is a hard ceiling of roughly **1,000 transactions per second on any single hot row**, derived
from network latency alone and completely independent of hardware. Contention shape named precisely:
*"a large set of rows interacts with a small set of rows"* — and *"horizontal sharding doesn't work
well for business transactions that span multiple accounts"* `[DOCS]`
docs.tigerbeetle.com/concepts/oltp/, because the hot row is a *single row*.

The published answer is the inversion in §8.2: move the logic into the database so no lock crosses
the network, then batch. Documented maximum **8,189 events per request** `[DOCS]`
docs.tigerbeetle.com/coding/requests/ (⚠️ other published pages say 8,190 or "approximately 8,000" —
reported, not reconciled). *"The cost of replication is paid only once per batch, which means that
TigerBeetle runs almost as fast as an in-memory hash map"* `[DOCS]`, and batches shrink automatically
under light load to trade throughput for latency.

Per-transfer work at the state machine is *"two hash-map lookups, a balance check, and two balance
updates"* `[CODE]` docs/ARCHITECTURE.md — which is why balances are *maintained*, not folded: an
O(n) `SUM()` per transfer is incompatible with checking `debits_must_not_exceed_credits` in the write
path. `[INFERENCE]` — TigerBeetle does not state this rationale explicitly; it follows from the
invariant flags and the batch design.

**Architectural cost, stated plainly by TigerBeetle:** *"uses a single core by design and uses a
single leader node to process events. Adding more nodes can therefore increase reliability, but not
throughput."* `[DOCS]` It does not scale out for writes, and does not pretend to.

### 3.8 What it refuses to do — and why the refusals are the lesson

| Refusal | Published rationale |
|---|---|
| No SQL, 8 request types only | purpose-building for the OLTP schema *"ensures accounting logic is enforced correctly while massively increasing performance"* `[DOCS]` |
| **No strings** | *"initiating a transfer should not require fetching metadata from the general purpose database. If it does, that database will become the bottleneck."* `[DOCS]` |
| **No floats** — u128 integers only | *"calculations should be performed on the integers to avoid loss of precision"*; fractional currency handled by asset scale `[DOCS]` |
| No updates, no deletes | corrections preserve *"the original error, when it took place, as well as any attempts to correct the record"* `[DOCS]` |
| No multi-debit/multi-credit | *"designed for maximum performance. In order to keep it lean…"* — composed from linked transfers instead `[DOCS]` |
| One currency per ledger; **no built-in FX** | cross-`ledger` transfers are *"not permitted"*; FX is a published four-account, two-linked-transfer *recipe*, not a feature `[DOCS]` |
| No permissions, no auth | *"assumes a trusted environment"*; access control is the application's job `[DOCS]` |
| No reporting / OLAP | positioned strictly in the *"data plane, or hot path"*, with reporting systems above it `[DOCS]` |

**The pattern across every row is the same:** each refusal converts a hard general problem into an
easy specific one, and pushes the general problem to a system better suited to it. That is a design
*discipline*, and it is the most portable thing in this section — far more portable than any of the
mechanisms. Every one of these refusals would be *wrong* for QAYD (an accounting product must have
strings, reporting, multi-leg entries, FX and permissions). The transferable part is the habit of
asking, for each capability, *whether this subsystem is the right place for it* — and being willing
to say no.

### 3.9 Value dating: the one place TigerBeetle is weak

TigerBeetle's only published guidance on bitemporality is to stash *"a second timestamp for 'when' the
transaction originated in the real world"* in `user_data_64` `[DOCS]`
docs.tigerbeetle.com/coding/data-modeling/. The published `user_data` semantics are
"who/what" (`_128`), "when in the real world" (`_64`), "where" (`_32`).

But `user_data_64` is an **indexed, semantically opaque u64**. The `timestamp_min`/`timestamp_max`
range filters apply to the *cluster* timestamp, not to `user_data_64`, which supports equality
matching only `[DOCS]` docs.tigerbeetle.com/reference/account-filter/. **There is therefore no
efficient "balance as at value date D" query.** `[UNKNOWN]` — TigerBeetle does not publish the terms
"value date" or "settlement date" at all, and provides no effective-dating, no deferred posting, and
no as-of-date queries.

This is a genuinely important finding and it cuts against the grain of the section: the
most-rigorously-engineered ledger in the corpus is the *weakest* on the temporal model that Vault
treats as first-class (§6). It confirms that value dating is an *accounting/banking-domain*
requirement rather than a *ledger-infrastructure* one — and therefore that QAYD must solve it itself
rather than expecting to inherit it from any infrastructure choice.

---

<a name="4-the-openly-documented-api-ledgers"></a>
## 4. The openly-documented API ledgers

**Evidence position.** The most surprising result of this research: **two Banking-as-a-Service
providers publish more usable ledger engineering than all five incumbent core vendors combined.**
Column (a nationally-chartered US bank with a public developer API) and Increase document balance
decomposition, hold/clear state machines, settlement windows, idempotency conflict behaviour and
webhook delivery guarantees at a level of precision Temenos, Finastra, FIS and Fiserv do not approach
publicly.

`[INFERENCE]` — the correlation is with *who the customer is*. Column and Increase sell to engineers
who integrate unaided and must therefore publish. The incumbents sell to banks who receive a partner
login and an implementation team, and therefore need not.

### 4.1 Increase — the only vendor that publicly states its postings are immutable

`[DOCS]` increase.com/documentation — Transactions are described as *"the **immutable** additions and
removals of money"*.

The model is a two-object separation that maps cleanly onto Vault's phase concept without
generalising it:

```
   PENDING TRANSACTION                    TRANSACTION
   (authorised, not settled)              (immutable, settled)
   ──────────────────────────────         ──────────────────────────────
   affects "available balance"            affects the ledger balance
   amount is NEVER updated                append-only
   when settlement differs        ────►   a NEW Transaction is created
```

**The single sharpest published rule in the corpus:** a Pending Transaction's `amount` is **not**
updated when the settled amount differs. The pending record is a historical fact about what was
authorised; the settlement is a separate fact. `[DOCS]` increase.com/documentation/api/pending-transactions.
Compare with the near-universal accounting-software habit of updating the estimate in place and losing
the estimate.

Corrections are a **named compensating-entry taxonomy** rather than an edit: transaction sources carry
`_intention` / `_return` / `_rejection` suffixes, so "we meant to", "it came back", and "it was refused"
are three distinct, queryable facts `[DOCS]`.

The temporal answer is different from Vault's and worth understanding as a genuine alternative:
**Increase publishes no value-date concept at all** — only `created_at` — and instead offers
**point-in-time balance queries (`at_time`)** `[DOCS]`. Rather than model *when value applies*, it lets
you re-ask *what the balance was*. That is a coherent design for a payments API. §4.4 explains why it
does not generalise.

**Idempotency**, which is the most complete published treatment of the HTTP pattern found anywhere
`[DOCS]` increase.com/documentation/idempotency-keys:

| | Increase's behaviour |
|---|---|
| Mechanism | `Idempotency-Key` header, ≤ 200 chars |
| Successful replay | `200` **plus an `Idempotent-Replayed: true` response header** |
| Conflicting replay | `409`, `type: idempotency_key_already_used_error`, **carrying the `resource_id` of the original object** |
| Window | `[UNKNOWN]` — not published |

Both of those response details are worth copying and neither costs anything. The `Idempotent-Replayed`
header lets a client *distinguish* "I created this" from "this already existed" without parsing the
body — which matters enormously for a client deciding whether to fire a downstream side effect.
Returning the **original resource id** on a conflict turns a dead-end error into a recoverable one: the
caller can go look at what it actually did.

**Versioning**: Increase is **unversioned**, with a published compatibility contract enumerating what
counts as a backwards-compatible change `[DOCS]` increase.com/documentation/backwards-compatibility.
The obligations it pushes onto clients are explicit — never parse IDs, tolerate unknown enum values.
That is the modern pattern and it is more honest than a version number that nobody increments.

### 4.2 Column — balance decomposition done properly

Column's bank account object exposes **four distinct balance fields**, not one `[DOCS]`
docs.column.com/api/bank-account/bank-account-object/:

```
   ┌──────────────────────────────────────────────────────────────┐
   │  available   — spendable right now                           │
   │  pending     — authorised/in-flight, not yet settled         │
   │  holding     — settled but subject to a hold window          │
   │  locked      — "posted on the account but cannot be          │
   │                 withdrawn"  (e.g. overdraft collateral)      │
   └──────────────────────────────────────────────────────────────┘
```

This is §2.2's insight arriving independently from a completely different direction: **the number a
customer can spend and the number the ledger says are different numbers with different names, and both
are stored.** Note in particular that `locked` is money that *is* posted — it is a ledger balance with
a restriction, not a pending item. Collapsing these four into one field and computing the others in the
application is the origin of BA-03 (`ANTI_PATTERNS.md`).

**Dates:** Column separates a **date** from **instants** `[DOCS]`. `effective_on` is a calendar date
anchored to 00:00 **Pacific Time**; `initiated_at` / `submitted_at` / `settled_at` / `completed_at` are
true timestamps. The lesson generalises past banking: *the accounting date is a DATE in a declared
timezone, and the system times are instants.* QAYD already has exactly this shape (`journal_date` DATE,
`posted_at` TIMESTAMPTZ) — Column's contribution is the reminder that **the timezone anchor of the
DATE must be an explicit, documented product decision**, not whatever the server happens to be set to.

**Idempotency:** `Idempotency-Key` header, ≤ 255 chars restricted to ASCII 32–126, **case-sensitive**,
**30-day** retention `[DOCS]`. The character-set restriction is a small thing done right: an
idempotency key is a database key, and constraining it at the edge prevents an entire class of
encoding surprises.

**Webhooks — the most operationally honest documentation in the study** `[DOCS]`
docs.column.com/working-with-the-api/events-and-webhooks:

- delivery is **at-least-once** (same event id may arrive repeatedly), stated explicitly
- ordering is **explicitly not guaranteed** — Column is the only vendor that says so out loud
- **25 retries within 3 days**, first at 1 minute, exponential backoff
- success = a 2XX **within 10 seconds**
- HMAC-SHA256, computed over the **raw unmodified payload** — *"use our raw webhook payloads without
  any modification to calculate signatures"*
- explicit consumer instruction: *"guard against duplicated event receipts by making your event
  processing idempotent"*
- recovery is a **polling API** (`/api/events`), not a replay mechanism

That last point is the design lesson and it is easy to miss: **the webhook is an optimisation; the
list-events API is the correctness backstop.** Increase says the same thing in different words — after
roughly 72 hours and 8 attempts it stops trying, and *"polling is the way to recover from extended
outages"*, with a 30-day event retention `[DOCS]`. Any consumer built on webhooks alone is built on a
best-effort channel.

**Reporting:** two report types — `bank_account_transaction` (one row per transaction, covering *all
four* balance buckets) and `bank_account_summary` (opening/closing balances and period totals), in CSV,
JSON and Parquet, generated **automatically daily after midnight in a configured reporting timezone**,
with a **93-day** maximum lookback `[DOCS]` docs.column.com/guides/reporting.

Two observations worth carrying:

1. `bank_account_transaction` is a **ledger-entry export across all balance buckets** — it is the
   reconciliation primitive even though Column never uses the phrase "general ledger".
2. **Real-time posting does not abolish the end-of-day boundary; it relocates it.** Column posts in
   real time and still cuts a daily report at midnight in a declared timezone. The daily boundary moved
   from the posting engine to the reporting layer, where it belongs.

⚠️ **A correction worth recording.** Search-engine summaries and secondary writing widely assert that
Column runs "a real-time double-entry ledger". That phrase could **not** be verified on any fetched
Column page; the ledger marketing page makes no claim about double-entry, immutability, or sub-account
hierarchies `[UNKNOWN]`. This document does not repeat it. The *verified* material — four balance
fields, the ACH state machine, `locked` semantics — is more useful than the slogan anyway.

### 4.3 The idempotency comparison — a 120× spread on the same problem

Assembled from vendor documentation `[DOCS]`:

| Vendor | Mechanism | Format constraint | Window | Successful replay | Conflicting replay |
|---|---|---|---|---|---|
| **Increase** | `Idempotency-Key` header | ≤ 200 chars | `[UNKNOWN]` | 200 + `Idempotent-Replayed: true` | **409** + `resource_id` of original |
| **Column** | `Idempotency-Key` header | ≤ 255, ASCII 32–126, case-sensitive | **30 days** | cached response replayed | `[UNKNOWN]` |
| **Mambu** | `Idempotency-Key` header | **UUID v4 required** | **6 hours** | *"duplicate request will not be processed, and the response will be re-sent"* | in-flight `102` → migrating to `409`; different-body `[UNKNOWN]` |
| **Treasury Prime** | `X-Idempotency-Key` header | any string, UUID advised | **7 days** | processed once regardless of repeats | `[UNKNOWN]` |
| **Unit** | **`idempotencyKey` in the request body** (JSON:API attribute) | `[UNKNOWN]` | `[UNKNOWN]` | `[UNKNOWN]` | `[UNKNOWN]` |
| Temenos · Finastra · FIS · Finxact | **`[UNKNOWN]` — no published idempotency mechanism found for any of the four** | | | | |

Four findings stated plainly:

1. **Retention windows span 6 hours to 30 days — a 120× spread** across vendors solving an identical
   problem. There is no industry convention. Any client library assuming a common window is wrong
   somewhere. **QAYD must therefore *choose and document* a window rather than inherit one.**
2. **Only Increase documents conflict behaviour.** Every other vendor leaves the most dangerous case —
   same key, different body — undefined. QAYD's S2-13 already specifies `409
   idempotency_key_conflict`, which puts it ahead of four of the five.
3. **No incumbent core vendor publishes an idempotency mechanism at all.** For systems that move
   money, this is the most striking gap in the whole review.
4. **Mambu publishes the one rule nobody else does:** validation failures are *not* cached, so a
   retried `400` re-validates rather than replaying a stale rejection `[DOCS]`. This is correct and is
   the mirror image of TigerBeetle's `id_already_failed` (§3.5b) — between them they define the two
   ends of the "which outcomes does an idempotency key memoise?" question, which most implementations
   never ask.

### 4.4 Value date: the dividing line between a bank core and a payments API

The clearest cross-cutting split in the research.

| System | Published date model |
|---|---|
| **Temenos** | **Four distinct dates** — `bookingDate` (*"run date on which the entry was generated"*), `valueDate` (*"given value for interest purposes"*), **Exposure Date** (*"the actual date on which the clear funds are available"*), `processingDate` `[DOCS]` developer.temenos.com |
| **Vault** | **Three** — `value_datetime`, `booking_datetime`, `insertion_datetime`, plus an explicit `DateTimeView` selector on queries `[CODE]` |
| **Column** | **One date + four instants** — `effective_on` (date, Pacific-anchored) vs `initiated_at`/`submitted_at`/`settled_at`/`completed_at` `[DOCS]` |
| **Increase** | **One** — `created_at`. No value-date concept; temporal questions answered by `at_time` balance lookup `[DOCS]` |
| **Mambu** | **One** — GL journal entry v1 takes a single `date`. v2 schema `[UNKNOWN]` |
| **TigerBeetle** | **One** cluster timestamp; real-world time is an opaque `user_data_64` that range filters do not cover (§3.9) `[DOCS]` |

**The conclusion, and it is load-bearing for QAYD:**

Temenos and Vault model value date explicitly **because they run a bank's own book** and must compute
interest on back-valued positions, support back-dated adjustment and re-accrual, and distinguish "when
funds clear" from "when we booked it". Four dates is not legacy cruft — it is the minimum needed to do
interest correctly.

The US payments APIs mostly **collapse value date into a pending/posted state machine plus
scheme-specific effective dates**. That works because they are shaped by US rails and do not expose
back-valuation to clients.

Neither is wrong; they answer different questions. But **a system that will ever need to back-value a
posting cannot retrofit the dimension onto a pending/posted state machine** — the information was
never captured. QAYD's position is favourable: it already carries the accounting date and the system
date separately, which is the pair that matters (§6.3). The recommendation is to *constrain and
document* what it has, not to add a third clock.

### 4.5 Event delivery — the universal position

| System | Delivery | Ordering | Retry | Recovery |
|---|---|---|---|---|
| Increase | at-least-once (explicit) | unstated | 8 attempts, first immediate, last ~72 h, then **stops** | List Events, 30-day retention |
| Column | at-least-once (explicit) | **explicitly NOT ordered** | 25 retries / 3 days, 10 s success window | `/api/events` |
| Mambu Streaming | at-least-once (explicit) | ordered **within** a partition; **no global ordering** | consumer commits a cursor and resumes | limited retention; idle subscriptions lose old events |

**Every vendor that documents delivery documents at-least-once. Not one claims exactly-once.**
Consumer-side idempotency is universal and universally the consumer's problem. This independently
confirms `knowledge/03_DESIGN_PATTERNS.md` P-11 and requires no change to it.

`[DOCS]` — Mambu deliberately does **not** disclose its streaming broker, exposing only the cursor
semantics. That is a defensible API-design choice worth noting: the *contract* is published, the
*implementation* is not, and the contract is the part consumers can depend on.

---

### 4.6 Modern Treasury, Formance, Square and Uber — four more independent arrivals

Four systems that published enough to be useful, all reaching the same structural conclusions from
different starting points. **Convergence across independent designs is the strongest available
evidence that a design is right**, and this is the most convergent finding in the research.

**Modern Treasury Ledgers** `[DOCS]`/`[COMMUNITY]` docs.moderntreasury.com + the MT Journal.

- Caches **four counters** per ledger account: `pending_debits`, `pending_credits`, `posted_debits`,
  `posted_credits` — the same four as TigerBeetle, arrived at independently.
- **The precise definition of available balance**, which almost everyone gets wrong: *"posted incoming
  entries minus the sum of the pending **and** posted outgoing amounts"*. Pending **outflows** reduce
  availability; pending **inflows** do not increase it. The asymmetry *is* the point — it is
  conservatism, and it is what stops a customer spending money that has not arrived. TigerBeetle
  enforces the identical asymmetry structurally, since a `pending` debit counts against
  `debits_must_not_exceed_credits` before it settles (§3.2).
- `effective_at` is the **value date**; `posted_at` and `created_at` are the other two clocks.
- **Balance locks and value dating are deliberately decoupled**: balance filters *"consider every
  Ledger Entry … **regardless of their `effective_at` values**"* `[DOCS]`. Overdraft protection runs on
  the system-current balance; reporting runs on the value-dated balance. **Conflating the two is a bug
  generator**, and separating them explicitly is a design decision worth copying.
- `external_id` enforces a *domain* uniqueness constraint — only one pending or posted ledger
  transaction may carry a given one — which is a different tool from the `Idempotency-Key` header
  (§9.2) and correctly modelled as such.

**Formance Ledger** (open source) `[CODE]` github.com/formancehq/ledger — the most complete published
answer to value dating found anywhere:

```
   moves:  insertion_date  (when the ledger learned)
           effective_date  (when it economically counts)

   materialises BOTH:
     post_commit_volumes            ← ordered by insertion
     post_commit_effective_volumes  ← ordered by effective date
```

Two materialised balance series, one per clock. That is the correct answer for a system that must
answer both *"what did we believe on date X"* and *"what was economically true on date X"* — and it
demonstrates that the two questions genuinely require two structures, not one structure and a filter.
Formance also ships a `HASH_LOGS` feature — an optional hash chain over the log.

**Square "Books"** `[DOCS]` — the clearest published statement of the invariant:

> *"The accounting equation states that all transactions must balance to 0, so each cent lost is
> matched with a cent gained"* — **enforced at the database level**.

Architecture: an **immutable journal plus a mutable materialised `balance`**. Same shape as §7.

**Uber LedgerStore** `[COMMUNITY]` — the most sophisticated published integrity design, and the source
of two ideas QAYD can use directly:

- **Sealing.** A time range of the ledger is frozen; after sealing, nothing in it may change.
- **Independent recomputation before sealing** — a four-checksum scheme (C1–C4) computed across
  *separate read paths and regions*, so a single corrupted path cannot certify itself. Plus **signed
  manifests**, such that *"only LedgerStore is able to write valid manifests"*.
- **The seal is also the archival boundary.** The same line that freezes data for integrity is the
  line at which it tiers to cold storage. **Integrity and cost optimisation become one mechanism
  instead of two.** That is the best structural idea in this section and it maps directly onto period
  close (§10.7, BR-09).

**The convergence table.** Independently designed, same conclusions:

| | Immutable journal | Materialised balance | 4 pending/posted counters | Separate value clock |
|---|---|---|---|---|
| Thought Machine Vault | ✅ | ✅ (per coordinate) | ✅ (phase) | ✅ (3 clocks) |
| TigerBeetle | ✅ | ✅ | ✅ | ⚠️ opaque `user_data_64` only |
| Modern Treasury | ✅ | ✅ | ✅ | ✅ `effective_at` |
| Formance | ✅ | ✅ (×2 series) | — | ✅ `effective_date` |
| Square Books | ✅ | ✅ | — | `[UNKNOWN]` |
| Increase | ✅ | ✅ | ✅ (pending/posted objects) | ❌ `created_at` only |
| **QAYD today** | ✅ | S2-09 | n/a — no pending concept | ✅ `journal_date` |

`[UNKNOWN]` — **Monzo, Shopify, PayPal, Netflix, Coinbase, Revolut, Starling and Wise publish no
ledger-architecture material** that could be verified. Monzo's current blog index and Shopify's
engineering search were checked directly and contain no such post. Secondhand claims about any of
these should be treated with suspicion.

---

<a name="5-the-opaque-cores"></a>
## 5. The opaque cores: Temenos, Finastra, FIS, Fiserv, Mambu

This section is deliberately short. Four of these five vendors publish almost nothing at engineering
depth, and this document will not manufacture an architecture from marketing copy.

**The headline finding, which contradicts received wisdom and deserves to be stated first:**

> **The widely-repeated claim that core banking ledgers are immutable append-only stores is not
> supported by published vendor documentation.**
>
> Of every system in this study, exactly **one** vendor publicly states its postings are immutable —
> **Increase** (§4.1). **Mambu** documents an explicitly *mutable-until-period-close* general ledger,
> with operator-deletable closures and `PATCH`-able GL accounts `[DOCS]`. **Temenos** publishes an API
> described as managing accounting information *"such as updating, retrieving and **deleting** journal
> entries"* `[DOCS]` developer.temenos.com/transact-apis. What that surface actually permits is
> `[UNKNOWN]` — but it is not the language of an append-only store.
>
> Immutability is an *aspiration and a fintech-blog trope* more than a documented incumbent property.

This matters for QAYD in a specific and encouraging way: **QAYD's append-only ledger is not
table stakes it is catching up on. It is ahead of what most of the incumbent core banking market
publishes.** The gap to close is the *proof* layer (§10, §12), not the immutability layer.

### 5.1 Mambu — the composable-banking model

The most openly documented of the incumbent group `[DOCS]` docs.mambu.com.

**Accounting model.** A general ledger with **accounting closures** as period locks: backdating is
legal until a period is closed, and *closures are deletable by an operator*. GL accounts are
`PATCH`-able. Journal entry v1 takes a **single `date`** — no booking/value split published; the v2
schema is `[UNKNOWN]`.

**Accrual handling.** Mambu publishes a specific and slightly startling rule: accrual entries for a
day are stamped at `00:00:00` **of the following day** attributed to the previous day `[DOCS]`
docs.mambu.com/docs/cash-vs-accruals-accounting/. `[INFERENCE]` — this is a workaround for having only
one date field: with no separate value date, the *timestamp* is bent to carry the accounting-period
meaning. It is a precise illustration of the cost of collapsing the clocks (§6.2, `ANTI_PATTERNS.md`
BA-04): the semantics do not disappear, they get smuggled into a field not designed to hold them.

**Product-as-data.** Mambu's "product factory" is a **flat rule table**: `(product, event,
accounting-method) → GL account`, configured through the UI over a **vendor-defined, fixed event set**
(disbursement, interest accrued, fees applied, …) `[DOCS]` docs.mambu.com/docs/linking-products-to-accounting/.
Bounded and fully auditable; cannot express an accounting event Mambu did not anticipate.

**API surface.** Media-type versioning (`Accept: application/vnd.mambu.v2+json`, required).
**Offset** pagination (default 50, max 1000) with **opt-in** total counts via `paginationDetails` —
Mambu is the only vendor in the study that admits total-count is expensive and makes the caller ask
for it. That is good, honest API design and QAYD should copy the posture on any large list endpoint.

**Streaming API.** At-least-once, ordered within a partition, no global ordering, cursor-committed by
the consumer, with a limited retention window after which an idle subscription loses events `[DOCS]`.
The broker is deliberately undisclosed.

`[UNKNOWN]` for Mambu: rate limits, data residency, tenancy isolation model, journal entry v2 schema,
value/booking semantics, FX revaluation, entry immutability, webhook retry counts and signature
scheme, streaming retention length.

### 5.2 Temenos — four dates, a class hierarchy, and a paywall

Temenos Transact (formerly T24) is the largest incumbent core and almost entirely gated behind a
partner login. What *is* public sits on `developer.temenos.com` as event schemas and article-level
material.

**The genuinely valuable public finding is the four-date model** (§4.4) — `bookingDate`, `valueDate`,
Exposure Date, `processingDate`, each with a published definition `[DOCS]`
developer.temenos.com/article/accounting-events-lifecycle-guide. Exposure Date in particular —
*"the actual date on which the clear funds are available"* — is a **third** distinct concept beyond
value and booking, and it exists because provisional credit is real: funds can be booked, valued, and
still not usable. An accounting system with a bank-reconciliation feature eventually meets the same
distinction.

Also public: journal entries are typed (`journalEntryType: STMT | CATEG | SPEC`) and carry an
`accountingCompany` — i.e. **multi-book accounting is a first-class dimension on the entry**, not a
filter applied afterwards `[DOCS]` developer.temenos.com/events/financial-accounting/....

**Product model — Arrangement Architecture (AA).** A class hierarchy: Product Line → Product Group →
Product → **Property Class** → Property, with **child-overrides-parent** inheritance, and an ACCOUNTING
property mandatory on every financial product line `[COMMUNITY]` (mirrored user guides). Note the
contrast: Mambu uses a *rule table*, Temenos a *type hierarchy with inheritance*, and Finxact an
*embedded DSL* (§5.4). Three genuinely different answers to the same problem, with a legible
trade-off — the rule table is bounded and auditable but cannot express novelty; the DSL is unbounded
but drags code review, testing and deployment into product configuration.

**COB (close of business)** exists as a batch phase structure. Its phase names, the
`ACCT.ENT.TODAY`/`ACCT.ENT.FWD` mechanics, the full COMPANY multi-book model, and the multivalue data
model are all `[UNKNOWN]` from public sources.

⚠️ **Provenance warning.** Detailed T24 material (COB batch structure, AA property classes) circulates
on document-sharing sites as unauthorised copies of Temenos manuals, often several releases out of
date. Useful for gist; **not citable as vendor publication**, and not relied on for any claim in this
document.

### 5.3 Finastra — not gated, simply not crawlable

FusionFabric.cloud has a developer portal. It is a client-rendered single-page application whose
product pages return 403 to automated fetching. **This was verified as "not statically retrievable",
not as "behind a paywall"** — a distinction worth preserving, because it means a human with a browser
may well find real material.

**Everything about Finastra's ledger model is `[UNKNOWN]`.** No claim is made here. If this matters
later, it needs a manual browser session, not another crawl.

### 5.4 FIS and Fiserv — the deepest opacity, with one exception

**FIS** (Modern Banking Platform, Code Connect): the API catalogue is an SPA; public browsability
undetermined. The frequently-quoted "1,000+ APIs" figure is unverified marketing. **Everything on the
core banking side is `[UNKNOWN]`.**

**Fiserv** — DNA and Premier are entirely uncovered `[UNKNOWN]`.

**Finxact** (Fiserv's cloud core) is the exception and the one genuinely interesting datum: its
business rules are expressed in a **TypeScript DSL** `[DOCS]` finxact.com/solution/core-as-a-service/
— configuration as *code* rather than as *data*. Placed against Mambu's rule table and Temenos's class
hierarchy, this completes a three-point spectrum that is directly relevant to any future QAYD decision
about how customers configure accounting behaviour (`knowledge/07_QAYD_INNOVATION.md` territory,
not near-term). Value/effective-date handling, memo vs hard posting, idempotency, pagination and
webhook semantics are all `[UNKNOWN]` for Finxact.

### 5.5 What the opacity itself tells us

Three things, all useful:

1. **You cannot copy your way to a ledger.** The systems that solved this best publish least. Any
   design QAYD adopts has to be reasoned to from first principles, which is exactly what the knowledge
   base already assumes.
2. **The published/unpublished split tracks the customer, not the quality.** Vendors selling to
   engineers publish; vendors selling to banks with implementation teams do not. As QAYD's own API
   becomes a product surface, the same pressure will apply — and Increase's and Column's docs are the
   standard to aim at.
3. **The absence of published immutability across the incumbent market is a competitive fact, not just
   a research gap.** "Our ledger is append-only, enforced at the database, and continuously verified"
   is a claim most of this market cannot make in public. That belongs in
   `knowledge/06_COMPETITIVE_ANALYSIS.md` as much as here.

---

<a name="6-tri-temporal-dating"></a>
## 6. Tri-temporal dating: value, booking, insertion

This is the single most under-modelled concept in accounting software, and core banking gets it right
because it is legally forced to.

### 6.1 The three clocks

Vault carries **all three** on every posting instruction `[CODE]`:

| Field | SDK definition (paraphrased from the docstring) | Who sets it | Mutable? |
|---|---|---|---|
| `value_datetime` | *"the time at which the posting instruction will **affect balances**. Only use for backdated and future-dated instructions."* Bounded to `[1970-01-01, now + 90 days]` | the caller | no |
| `booking_datetime` | *"the time at which the posting instruction will be **booked**. Only use for back-booked or future-booked instructions."* Same bounds | the caller | no |
| `insertion_datetime` | *"when the posting instruction was **inserted into the posting ledger**"* (or, for migrated data, when it entered the source system) | Vault | no |

And a first-class selector for *which clock a query means* — `DateTimeView = VALUE_DATETIME |
BOOKING_DATETIME` — used when fetching balances `[CODE]`. A balance-as-of query is meaningless without
saying which clock you meant, so Vault makes you say it.

```
   real world:   ─────●───────────────────────●─────────────────●──────►
                  value_datetime          booking_datetime   insertion_datetime
                  "when it counts"        "when the book      "when we learned"
                                           says it happened"

   backdated posting        value < booking = insertion      (a correction)
   future-dated instruction value > insertion                (a standing order)
   migration                insertion is the SOURCE system's clock, not Vault's
   normal real-time         value = booking = insertion      (the boring case)
```

### 6.2 Why three and not one

- **`insertion` is the only monotonic clock.** It is the one you replay by, the one an audit trail is
  ordered by, and the only one an attacker cannot choose. Any "as at the time we knew it" question
  uses this clock.
- **`value` is the economic truth.** Interest accrues on value date. An overdraft is breached on value
  date. A dispute is adjudicated on value date.
- **`booking` is the presentational truth** — what the statement says. It can differ from value date
  for entirely legitimate operational reasons.

Collapsing them loses the ability to answer *"what did the books say on the 31st, as at the 31st?"* —
which is exactly the question an auditor asks and exactly the question a system with one timestamp
cannot answer after a backdated correction lands.

`[INFERENCE]` — the ±90-day bound on `value_datetime`/`booking_datetime` is almost certainly a
containment measure: unbounded backdating means an arbitrary amount of already-computed downstream
state (interest, statements, regulatory reports) must be recomputed. Vault chose a bounded window
rather than an unbounded one. That is a *policy* encoded as a *constraint*, and it is a pattern worth
copying: put the limit in the schema, not in a code review.

### 6.3 The accounting-software analogue

The mapping onto standard accounting vocabulary:

| Banking | Accounting | QAYD today |
|---|---|---|
| `value_datetime` | effective / accounting date — the date the entry belongs to | `journal_entries.journal_date` (DATE), `ledger_entries.entry_date` (DATE) |
| `booking_datetime` | document date / posting date as shown | partially `journal_entries.posting_date` |
| `insertion_datetime` | the immutable system time of record | `journal_entries.posted_at`, `ledger_entries.posted_at` |

QAYD therefore already has **two of the three clocks**, correctly separated, with the right types
(DATE for the accounting date, TIMESTAMPTZ for the system time). What it does not yet have is
(a) a constraint relating them, (b) a bound on backdating/future-dating, and (c) an explicit statement
of which clock each report means. Those are cheap and are recommended in
`IMPLEMENTATION_RECOMMENDATIONS.md`.

Whether QAYD needs the *third* clock is a genuine design question, answered in `LESSONS_FOR_QAYD.md`.
Short version: **not yet, and possibly never** — the value/insertion pair covers SME accounting, and a
third clock is only earned when the booking date can legitimately diverge from both.

---

### 6.4 The standards actually say so — value date is not a vendor invention

The two-date model is not something core banking vendors invented. It is written into the payment
message standards, which is why every serious ledger ends up carrying it.

**ISO 20022** (`camt.053` bank-to-customer statement) carries both `BookgDt` (**booking date**) and
`ValDt` (**value date**) as distinct elements on a statement entry `[DOCS]` — sourced here from
implementation guidelines published by SIX, Clearstream (CBPR+) and Bank of America; the ISO data
dictionary itself is `[UNKNOWN]` (not fetched).

**SWIFT MT940** statement line `:61:` carries the **value date in subfield 1** and the **entry date in
subfield 2** `[DOCS]`. The format has carried both since long before any system in this study existed.

**The generalised picture** across everything studied:

| System | Record time | Book time | Value time |
|---|---|---|---|
| ISO 20022 `camt.053` | — | `BookgDt` | `ValDt` |
| SWIFT MT940 `:61:` | — | subfield 2 entry date | subfield 1 value date |
| Thought Machine Vault | `insertion_datetime` | `booking_datetime` | `value_datetime` |
| Modern Treasury | `created_at` | `posted_at` | `effective_at` |
| Formance | `insertion_date` | — | `effective_date` |
| Stripe | `created` | — | `available_on` |
| Uber LedgerStore | write time | seal window | event/business time |
| **QAYD** | `posted_at` | — | `journal_date` |

QAYD sits exactly where a two-clock system should: **record time and value time, with no book time.**
That is the right pair, and §6.3's conclusion stands — the third clock is not earned.

**Settlement and provisional credit.** The reason banking needs an *exposure* date beyond value and
booking (Temenos, §5.2) is that money can be booked, valued, and still not usable, because the
underlying settlement can be reversed within a window. `[UNKNOWN]` — the exact Nacha ACH return
windows could not be verified (nacha.org refuses automated fetch and the Operating Rules are
paywalled), so **no specific figure is stated here**. The *structural* point does not depend on the
figure: a batch/deferred net settlement system carries a reversal window, and a real-time gross
settlement system does not — which is the entire reason RTGS exists.

**For QAYD this is a bank-reconciliation concern, not a ledger concern.** An SME's ledger records what
the business did; the provisional-credit problem belongs to whatever feature reconciles against a bank
statement, and should be modelled there (`LESSONS_FOR_QAYD.md` R2, D2).

`[UNKNOWN]` — Kuwait and GCC specifics (KNET, Kuwait Clearing, CBK settlement regulation, AFAQ/GCC
RTGS) could not be verified; every primary source refused automated retrieval. This is a real gap for
a Kuwait-first product and is flagged as such rather than filled.

---

<a name="7-balance-derivation-vs-stored-balances"></a>
## 7. Balance derivation vs stored balances

The false dichotomy is "store balances (fast, corruptible)" versus "derive balances (slow,
trustworthy)". Every serious ledger does **both**, and the discipline is in the relationship between
them.

```
   ┌──────────────────────────────────────────────────────────────────┐
   │  IMMUTABLE POSTINGS  (the only authority)                        │
   │  append-only · no update verb · one write path                   │
   └───────────────┬──────────────────────────────────┬───────────────┘
                   │ synchronous, same transaction    │ periodic, offline
                   ▼                                  ▼
   ┌───────────────────────────────┐    ┌──────────────────────────────┐
   │ MAINTAINED BALANCE            │    │ RE-DERIVED BALANCE           │
   │ what reads actually hit       │    │ full fold over postings      │
   │ O(1)                          │    │ O(n), run on a schedule      │
   └───────────────┬───────────────┘    └──────────────┬───────────────┘
                   │                                   │
                   └──────────► COMPARE ◄──────────────┘
                                   │
                       equal ──────┴────── unequal
                         │                    │
                    (silence)          ALERT: the maintained
                                       balance is corrupt, and
                                       the postings are right
```

**The rule that makes this safe:** the maintained balance is a *cache with a proof obligation*, and the
proof runs continuously. If the two disagree, the postings win — always, without discussion — because
the postings are the only append-only object in the picture.

Three properties are required for the comparison to be meaningful:

1. **The source must be append-only.** If postings can be edited, re-derivation reproduces the same
   corruption and the check proves nothing.
2. **The fold must be deterministic and exact.** Any floating-point arithmetic anywhere makes
   re-derivation order-dependent, and a drift alert becomes noise that gets muted. Integer or
   fixed-precision decimal only.
3. **The maintained balance must be updated in the *same transaction* as the posting.** An
   asynchronously-updated balance is a different, weaker design in which drift is expected rather
   than alarming, and an expected-drift alert is an ignored alert.

QAYD satisfies (1) via the `ledger_entries` append-only trigger, (2) via `NUMERIC(19,4)` + bcmath
strings, and (3) is the design intent of the S2-09 `account_period_balances` rollup trigger. The
comparison job is **S2-14**. So QAYD's design is already the correct shape; §12 and the recommendations
concern hardening it rather than changing it.

**Vault's variant** is stronger in one respect: it preserves gross debits and credits alongside the net
(§2.1), so the maintained balance carries more information than the fold's result and a wider class of
corruption is detectable — a compensating pair of errors that leaves the net correct still shows up in
the gross totals. QAYD's `ledger_entries` already stores `debit_amount`/`credit_amount` *and*
`base_debit_amount`/`base_credit_amount` *and* `signed_base_amount`, so it has the raw material; what
it lacks is a rollup that carries all three.

---

<a name="8-the-hot-account-problem"></a>
## 8. The hot-account problem

The defining scaling problem of a real ledger, and the one that most cleanly separates ledger design
from general database design.

### 8.1 Statement of the problem

In any real ledger, postings are **not** uniformly distributed across accounts. A small number of
accounts are touched by a large fraction of all postings:

- in a bank: the settlement account, the fee income account, the interest expense account
- in an SME accounting system: **the bank account, the VAT control account, the accounts-receivable
  control account, the retained-earnings account**

If the balance of an account is a mutable row, every posting that touches it must serialise on that
row. The account becomes a global lock. Throughput for the *entire system* converges on
`1 / (lock hold time)` for that one row, regardless of how many cores, shards, or replicas exist.

```
   1,000 concurrent postings, 999 different accounts, all crediting VAT control:

        ┌──── posting 1 ───┐
        ┌──── posting 2 ───┤          ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓
        ┌──── posting 3 ───┼────────► ▓ VAT control row  ▓ ◄── serialised
                 …         │          ▓ (one row lock)   ▓
        ┌──── posting N ───┘          ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓

   effective throughput = 1 / lock_hold_time, independent of N
```

### 8.2 The three answers, and why two of them are wrong

**Wrong answer 1 — shard the account.** Split the hot account into N sub-accounts and route randomly.
This works mechanically and destroys the ledger: the account's balance is now a distributed sum,
every read must fan out, and the moment one shard is missed the books are wrong by a number nobody can
find. Reconciliation cost grows without bound.

**Wrong answer 2 — make the balance eventually consistent.** Write postings synchronously, update
balances asynchronously. This removes the contention and removes the invariant with it: there is now a
window in which the balance is knowingly wrong, and "knowingly wrong" is not a state a ledger may
occupy. Worse, it makes drift *expected*, which destroys the drift alarm (§7).

**Right answer — do not update the balance per posting.** Two forms:

- **Batching.** Accumulate many postings and apply their aggregate effect to the hot balance once. One
  lock acquisition amortised over thousands of movements. This is the answer purpose-built ledger
  engines take, and it is why they expose a batch-oriented API rather than a
  one-transfer-per-request one. Contention becomes `batches/sec × lock_hold`, not `postings/sec ×
  lock_hold`.
- **Derive instead of maintain, for the hot ones.** If the balance is a `SUM()` over an append-only
  table, concurrent postings do not contend at all — they are independent `INSERT`s. The cost moves
  to read time, and is bounded by an index and a rollup at a coarser grain (per period, not per
  posting).

### 8.3 Why this matters for QAYD specifically

QAYD's posting path currently takes a `FOR UPDATE` row lock on the **journal entry header** — which is
correct and *not* a hot spot, because each entry is locked by itself. Ledger writes are pure `INSERT`s
into an append-only table. **QAYD therefore does not have the hot-account problem today, and the
architecture is the reason.** `[INFERENCE]` from the posting service source.

The risk is introduced by the *rollup*: an `AFTER INSERT` trigger on `ledger_entries` that maintains
`account_period_balances` converts every posting touching the VAT control account in a given period
into an `UPDATE` of one shared row — and reintroduces exactly the contention the append-only design
avoided.

```
   BEFORE rollup:   INSERT ledger_entries ×N   →  no contention
   AFTER  rollup:   INSERT ledger_entries ×N
                    + UPDATE account_period_balances(VAT, 2026-07) ×N  →  serialised
```

This is a real and predictable consequence of a design decision already in the backlog (S2-09,
Backlog H2). It is not a reason to abandon the rollup — the rollup is the right call for read
performance — but it *is* a reason to (a) know the grain that will contend, (b) measure it before it
hurts, and (c) know the escape route (insert-deltas-and-fold rather than update-in-place). This is
carried into `IMPLEMENTATION_RECOMMENDATIONS.md` and cross-references `knowledge/05_FUTURE_ARCHITECTURE.md`'s
scale tiers.

---

<a name="9-idempotency-and-exactly-once"></a>
## 9. Idempotency and exactly-once under partition

### 9.1 The impossibility, stated precisely

Exactly-once *delivery* is impossible across an unreliable network. What is achievable is
**at-least-once delivery + idempotent processing = exactly-once effect**. Every correct financial API
implements the second half; the first half is free.

The scenario that forces it:

```
   client                      server                     ledger
     │   POST /post  ─────────────►│                         │
     │                             │  ── validate ──────────►│
     │                             │  ── COMMIT ────────────►│  ✅ money moved
     │        ✗ network dies ◄─────│                         │
     │                             │                         │
     │  client sees a timeout. it CANNOT distinguish:        │
     │    (a) request never arrived     → must retry         │
     │    (b) committed, response lost  → must NOT retry     │
```

Without a server-side idempotency record, the client must choose between losing money and duplicating
it. There is no third option and no amount of client cleverness creates one.

### 9.2 The two-identifier pattern

Every well-designed financial API separates two things that are frequently conflated:

| | Idempotency key | Business transaction id |
|---|---|---|
| Chosen by | the client, per *request attempt-set* | the client or the server, per *economic event* |
| Lifetime | hours to days (a TTL) | forever |
| Scope | one endpoint, one tenant | the whole ledger |
| Answers | "have I already processed this exact request?" | "which real-world event is this?" |
| Vault's version | `client_batch_id` | `client_transaction_id` |
| HTTP convention | `Idempotency-Key` header | a field in the body |

Conflating them produces one of two failures: either the business id must be regenerated on retry
(breaking idempotency), or the idempotency key must live forever (an unbounded table).

### 9.3 The full correct algorithm

```
  ON REQUEST (key K, body B, tenant T, endpoint E):

  1.  fingerprint F := hash(canonical(B))
  2.  INSERT INTO idempotency_keys (T, E, K, F, state='in_progress')
        ON CONFLICT (T,E,K) DO NOTHING
  3.  IF inserted:
        run the real work INSIDE the same transaction
        record (status, response_body) on the idempotency row
        COMMIT              → return the response
  4.  IF conflict:
        SELECT the existing row
        IF row.F ≠ F            → 409  idempotency_key_conflict     ◄── critical
        IF row.state='in_progress' → 409 request_in_flight (client retries later)
        ELSE                    → replay row.response_body, same status code
```

Three details that are usually got wrong:

1. **The fingerprint check is not optional.** Same key + *different* body must be a hard error, not a
   silent replay of the first response. Without it, a client bug that reuses a key silently discards
   the second, different, transaction. QAYD's S2-13 acceptance criteria already specify
   `409 idempotency_key_conflict` for this case — which is correct and should be preserved exactly.
2. **The idempotency row must be written in the same transaction as the effect.** If it is written
   before, a crash leaves a key claiming work that never happened. If after, a crash leaves work with
   no key and the retry duplicates it.
3. **`in_progress` needs a resolution path.** A crashed request leaves a stuck key. Either a TTL after
   which `in_progress` is reclaimable, or an explicit sweep. Silence here becomes a support ticket.

### 9.4 Idempotency of *events*, not just requests

The same reasoning applies on the outbound side. A transactional outbox delivers **at least once**;
therefore every consumer must be idempotent, and the outbox row id is the natural dedupe key. This is
already `knowledge/03_DESIGN_PATTERNS.md` P-11 and needs no restatement — the banking-specific addition
is that **the event must carry a signal, not the money**. A consumer that reconstructs a balance from
a stream of events will eventually be wrong; a consumer that receives "entry 4471 posted" and re-reads
through the authoritative API will not.

---

<a name="10-integrity-proofs"></a>
## 10. Integrity proofs: hash chains, Merkle trees, anchoring, control totals

QAYD has dormant `audit_logs.hash` / `audit_logs.prev_hash` columns (TD-06) and an S2-14 job that will
verify "the hash chain" once S4+A lands. This section is the design work behind that.

### 10.1 What a hash chain does and does not prove

```
   row n-1                       row n                        row n+1
   ┌───────────────┐             ┌───────────────┐            ┌───────────────┐
   │ payload_{n-1} │             │ payload_n     │            │ payload_{n+1} │
   │ prev_hash ────┼──┐          │ prev_hash ────┼──┐         │ prev_hash ────┼──┐
   │ hash = H(...) │  │          │ hash = H(...) │  │         │ hash = H(...) │  │
   └───────┬───────┘  │          └───────┬───────┘  │         └───────────────┘  │
           └──────────┼──────────────────┘          └────────────────────────────┘
                      │
        hash_n = H( prev_hash_n  ||  canonical(payload_n) )
```

**Proves:** that no row was altered, inserted, or deleted *in the middle* of the chain without
recomputing every subsequent hash.

**Does not prove:** anything at all against an attacker who can write to the table, because they can
simply recompute the whole tail. A hash chain in a database the attacker controls is **tamper-evident
only against an attacker who does not know the chain exists**. This is the single most important and
most frequently ignored fact about hash chains in financial systems, and any design that stops here
has bought less than it thinks.

### 10.2 What actually closes the hole: external anchoring

The chain becomes meaningful the moment a digest of it is published somewhere the attacker cannot
retroactively edit:

```
   ledger (mutable by a sufficiently privileged attacker)
   ────────────────────────────────────────────────────────────────►
     …───●───●───●───●───●───●───●───●───●───●───●───●───●───●───
             │                   │                   │
         head@T1             head@T2             head@T3
             │                   │                   │
             ▼                   ▼                   ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  ANCHOR STORE — append-only, separate trust domain           │
   │  (signed digest + row count + control totals + timestamp)    │
   └──────────────────────────────────────────────────────────────┘

   Guarantee: history BEFORE the last anchor cannot be rewritten
              without detection, even by a DB superuser.
```

The anchor need not be exotic. In descending order of strength and cost: a third-party timestamping
service; an append-only object store in a different cloud account with a different credential; a
signed digest emailed to the company's auditor monthly; a digest printed on the period-close report.
**Any of these is categorically stronger than no anchor**, because all of them move the evidence
outside the blast radius of a database compromise.

The economical version for an SME product: **anchor at period close.** The close is already a
meaningful, low-frequency, human-attested event. Publishing a signed digest of the ledger head at
close costs almost nothing and yields "the books for July cannot be altered without detection" — a
sentence with real commercial value to an accountant.

### 10.3 Inclusion and consistency proofs (the Merkle upgrade)

A chain answers "was anything altered?" only by rehashing everything. A Merkle tree answers two
sharper questions in logarithmic work:

```
                              ROOT (the digest you anchor)
                            ┌────────┴────────┐
                        H(AB)                H(CD)
                     ┌────┴────┐          ┌────┴────┐
                   H(A)      H(B)       H(C)      H(D)
                    │          │          │          │
                  entry      entry      entry      entry

  INCLUSION PROOF  — "entry C is in the tree with root R"
                     evidence: H(D), H(AB)          → O(log n) hashes
                     use: give a customer/auditor proof their entry is in the book

  CONSISTENCY PROOF — "the tree with root R2 is an APPEND-ONLY EXTENSION of R1"
                     use: prove the July close was not retroactively altered when
                          the August close was published
```

Consistency proofs are the property that actually matters for a ledger, and they are the reason
Certificate Transparency (the canonical design of a publicly verifiable append-only log) is the right
model to reason from rather than blockchain.

`[INFERENCE]` — for QAYD's scale, a **per-company, per-period Merkle root anchored at close** is the
right point on the cost curve: it gives consistency proofs across periods and inclusion proofs for
individual entries, with one root to store per company per period, and it degrades gracefully to a
plain chain if the tree is never needed.

### 10.4 Control totals — the pre-cryptographic technique that still works

Long before hashing, banking proved batch integrity with **control totals**: alongside a batch of
records, transmit the record count and the sum of a numeric field, and refuse the batch if the
recomputed values differ.

This survives because it catches a class of error that hashing does not usefully distinguish:
*truncation and partial application*. A hash mismatch says "something is wrong". A control-total
mismatch says "you are 3 records and KWD 1,240.500 short" — which is a debuggable statement.

The modern ledger equivalents, all cheap:

| Assertion | What it catches |
|---|---|
| `SUM(signed_base_amount) = 0` per company | any unbalanced posting that reached the ledger |
| `SUM(signed_base_amount) = 0` per journal entry | a partially-projected entry |
| `COUNT(ledger_entries) = COUNT(posted journal_lines)` | a dropped or duplicated projection |
| `SUM(debit) = SUM(credit)` per period | classic trial-balance proof |
| `MAX(journal_number)` sequence has no gaps per company/year | a deleted or lost entry |
| maintained rollup = re-derived fold | a corrupt cache |

**The trial balance is a control total.** It has always been an integrity proof that accountants
happened to also use as a report. Framing it that way — and running it as an assertion on a schedule
rather than only when a human opens a screen — is one of the highest-value, lowest-cost ideas in this
entire research programme.

### 10.5 Balance assertions — the plain-text-accounting idea

Plain-text accounting tools let the author write, inline in the ledger, "on this date this account's
balance is exactly X", and the tool **fails** if the computed balance differs.

The idea generalises beautifully: a balance assertion is a **checkpoint that converts a silent
divergence into a loud failure at a known point in time**. Applied to QAYD, a period-close snapshot
that records each account's closing balance and is thereafter immutable is exactly this — and it makes
the nightly integrity job dramatically more useful, because a drift can be **bisected** to the period
in which it was introduced instead of merely detected.

### 10.6 The layered defence, assembled

```
  L0  SCHEMA           CHECK constraints, NOT NULL, exact NUMERIC types
                       → a malformed posting cannot be represented
  L1  PRIVILEGE        REVOKE UPDATE, DELETE from the app role
                       → the mutating verb does not exist for the application
  L2  TRIGGER          BEFORE UPDATE OR DELETE → RAISE, unconditionally
                       → even an owner/superuser connection is refused
  L3  CONTROL TOTALS   Σ = 0, counts match, no number gaps  (continuous)
                       → corruption is detected within one job cycle
  L4  RE-DERIVATION    rebuild the projection, compare byte-for-byte  (nightly)
                       → a corrupt cache is detected and the source wins
  L5  HASH CHAIN       tamper-evidence within the database
                       → casual alteration is visible
  L6  EXTERNAL ANCHOR  signed digest outside the blast radius  (per period)
                       → alteration by a privileged attacker is detectable
```

QAYD today has **L0, L2, and (on `audit_logs` only) L1**. L3 and L4 are S2-14. L5 is dormant (TD-06,
S4+A). L6 does not exist. That ordering is the backbone of `IMPLEMENTATION_RECOMMENDATIONS.md`, and
the ordering matters: **L3 and L4 are worth more than L5 and cost less**, which is the opposite of the
order they are usually built in.

---

### 10.7 The integrity ladder, with sources

Six rungs in increasing strength. The value of stating it as a ladder is that **most systems skip
straight to rung 3 and stop**, which is the least useful place to stop.

| # | Mechanism | What it catches | What it misses | Evidence |
|---|---|---|---|---|
| 1 | **Trial-balance invariant** — Σ = 0 | any single-sided write, in O(n) | anything balanced-but-wrong | Square: *"all transactions must balance to 0, so each cent lost is matched with a cent gained"*, enforced at the database `[DOCS]`. TigerBeetle checks it globally as `Σ debits_posted == Σ credits_posted` `[CODE]`. Modern Treasury: *"Any imbalance indicates funds were created or destroyed erroneously"* `[COMMUNITY]` |
| 2 | **Balance assertion / external checkpoint** | converts *"is all history right?"* into *"is the delta right?"* | requires an **external truth** (e.g. a bank statement) | plain-text accounting (Beancount) `[COMMUNITY]` |
| 3 | **Local hash chain** | tamper-evidence against partial edits | **defeated by an attacker with full write access** — they recompute the tail; O(n) proofs | Formance ships `HASH_LOGS` `[CODE]`; Microsoft documents the recompute attack explicitly `[DOCS]` |
| 4 | **Merkle tree + periodic digest** | O(log n) **inclusion** proofs and — critically — **consistency** proofs that prove append-only-ness | still inside your trust domain until rung 5 | RFC 6962 (Certificate Transparency) `[DOCS]`; Azure SQL Ledger `[DOCS]` |
| 5 | **External anchoring** of the digest to storage the application cannot rewrite | rewriting of history **before the last anchor**, even by a privileged attacker | anything after the last anchor | Azure SQL Ledger, which anchors digests to WORM blob storage with a **locked immutability policy**, or to a confidential ledger `[DOCS]` |
| 6 | **Independent recomputation across separate paths before sealing** | a single corrupted read path certifying itself | — | Uber LedgerStore's four checksums (C1–C4) computed across separate read paths and regions, plus **signed manifests** so *"only LedgerStore is able to write valid manifests"* `[COMMUNITY]` |

**Rung 5 is the one that closes the loop and the one most systems skip.** Azure SQL Ledger is the
closest analogue to what an SME accounting product could realistically ship: ledger tables with history
tables, database digests, and **automatic digest storage in external immutable storage** — a design
directly transferable in shape, if not in implementation, to PostgreSQL plus an object store in a
separate cloud account.

Note the contrast with **immudb**, which pushes verification to the **client** — *"the integrity of the
history will be protected by the clients, without the need to trust the database"*, and *"you can add
new versions of existing records, but never change or delete records"* `[DOCS]`. Two different answers
to *who do you trust*: Azure externalises the **anchor** and verifies server-side; immudb externalises
the **verification** itself. `[UNKNOWN]` — immudb's specific tree construction and proof APIs were not
verified.

**Uber's best structural idea, restated because it is directly usable:** the seal boundary and the
archival boundary are the same line. **Integrity and cost optimisation become one mechanism instead of
two.** For QAYD, that line is **period close** — which is already a low-frequency, human-attested,
irreversible event. Anchor there, and the same boundary later becomes the partitioning/archival
boundary for a ledger that has grown large.

### 10.8 Control totals — what is real and what is folklore

The **invariant** is rigorously sourced (rung 1 above, three independent systems). Two adjacent claims
frequently repeated in engineering writing are **not**:

- **Hash totals / batch control totals** as a named IT-audit technique — the concept is real and
  standard in audit literature, but `[UNKNOWN]`: no primary citation (ISACA/AICPA/COBIT) was obtained,
  and none is fabricated here.
- **"Proof and control" departments in banks** — `[UNKNOWN]`, and very likely **folklore** as commonly
  retold. Marked as such rather than repeated.

What survives, and it is enough: **the trial balance is a global O(n) invariant that catches any
single-sided write and is cheap to run continuously.** It does *not* catch a balanced-but-wrong pair —
which is exactly the gap that balance assertions (rung 2) and external anchoring (rung 5) fill. That
three-rung argument is the whole design rationale for QAYD's integrity work.

### 10.9 Compliance — what the law actually says

Only verified statute and standards text is stated here.

**18 U.S.C. § 1519** (enacted by Sarbanes-Oxley §802) `[DOCS]` law.cornell.edu/uscode/text/18/1519:

> *"Whoever knowingly alters, destroys, mutilates, conceals, covers up, falsifies, or **makes a false
> entry in any record**, document, or tangible object with the intent to impede, obstruct, or influence
> [a federal investigation or matter] … shall be fined under this title, **imprisoned not more than 20
> years**, or both."*

Note **"makes a false entry"** — the statute reaches *ledger writes*, not merely deletions. `[INFERENCE]`
— a system permitting silent in-place edits of posted entries is not merely poorly hygienic; it
manufactures the capability the statute criminalises. That is an argument for immutability that has
nothing to do with engineering aesthetics, and it is worth having in the product's own compliance
material.

**PCAOB AS 2201** `[DOCS]` pcaobus.org — the ITGC dependency, stated in the standard itself:

- **¶.47**: *"an automated control would generally be expected to be lower risk **if relevant
  information technology general controls are effective**"*.
- Note following ¶.47: for off-the-shelf software, an auditor's testing *"might focus on the
  **application controls built into the pre-packaged software**"*.
- **¶.36**: the auditor *"should understand how IT affects the company's flow of transactions"*.

`[INFERENCE]`, and commercially significant: an accounting product whose controls are demonstrably
effective **lowers its customers' audit risk**, and the note following ¶.47 says an auditor may focus
testing on exactly those built-in application controls. Append-only enforcement, a complete audit
trail on posting, and a documented continuous integrity check are therefore not only engineering
assets — they are **auditable application controls**, and a differentiator most of this market cannot
claim in public (§5.5).

**Retention.** `[DOCS]` — **Kuwait requires corporate accounting records to be retained for a minimum
of 10 years**, with statutory books kept in Arabic (sales journal, inventory, general ledger,
expenditure analysis, share records) and financial statements filed with the Ministry of Commerce and
Industry within three months of year end. This is the binding requirement for QAYD's first market, and
it has two direct design consequences: **the ledger must remain queryable for a decade** (partitioning
and archival, not deletion — §10.7's seal boundary), and **Arabic is a statutory requirement for the
books themselves**, not a localisation nicety.

`[UNKNOWN]` — US IRS retention guidance, 17 CFR 210.2-06, and SEC Rule 17a-4(f) including its 2022
audit-trail alternative to WORM: all refused automated retrieval and none is paraphrased here.

---

<a name="11-postgresql-mechanisms"></a>
## 11. PostgreSQL mechanisms for banking-grade invariants

Banking's guarantees are mostly achievable in PostgreSQL. This is the mapping, because
"PostgreSQL-first integrity" is already QAYD's stated posture and the question is only which
mechanism implements which guarantee.

| Guarantee | PostgreSQL mechanism | QAYD status |
|---|---|---|
| A posting can never be edited | `BEFORE UPDATE OR DELETE` trigger that always raises | ✅ on `ledger_entries` and `audit_logs` |
| The application literally cannot edit it | `REVOKE UPDATE, DELETE ON … FROM <app_role>` | ✅ `audit_logs` · ❌ **not** `ledger_entries` |
| A malformed amount cannot exist | `NUMERIC(19,4)` + `CHECK` | ✅ |
| **Σ(lines) = 0 per entry, enforced by the DB** | `CREATE CONSTRAINT TRIGGER … DEFERRABLE INITIALLY DEFERRED`, fired at commit `[DOCS]` postgresql.org/docs/current/sql-createtrigger.html | ❌ app-layer only |
| Tenant isolation that a bug cannot bypass | RLS `ENABLE` + `FORCE` + `RESTRICTIVE` policy | ✅ uniformly |
| Gapless numbering under concurrency | a sequence table row-locked inside the posting transaction | ✅ `JournalNumberAllocator` |
| Exactly-once effect for a retried request | unique index on `(tenant, endpoint, key)` + `ON CONFLICT DO NOTHING` | S2-13 |
| Event delivery without dual-write | transactional outbox + `FOR NO KEY UPDATE SKIP LOCKED` | P-11, S2-13 |
| Cheap scans of an append-only table | BRIN index on the monotonic timestamp/id | ❌ not used |
| Bounded retention cost | monthly range partitioning | ❌ deferred (TD-06) |
| Tamper-evidence | `BEFORE INSERT` trigger computing `H(prev_hash ‖ canonical(row))` | dormant columns exist |

Two entries deserve emphasis.

**The deferred constraint trigger is the missing enforcement.** PostgreSQL has no `CREATE ASSERTION`,
but a constraint trigger declared `DEFERRABLE INITIALLY DEFERRED` fires **at commit**, after all lines
of an entry are inserted, and can raise if `SUM(debit) ≠ SUM(credit)` for the entry `[DOCS]`. This
converts QAYD's most important accounting invariant from *"the service checks it"* to *"the database
will not accept a transaction that violates it"* — the same class of guarantee as the append-only
trigger. Today `chk_je_balanced` only constrains the **cached header totals** to equal each other; it
does not constrain them to equal the sum of the lines, so a bug that writes consistent-but-wrong
totals passes.

**The privilege asymmetry is a real, small, fixable gap.** `audit_logs` revokes `UPDATE` and `DELETE`
from the runtime role *and* has the trigger. `ledger_entries` has the trigger but keeps the grants —
and additionally defines `FOR UPDATE` and `FOR DELETE` RLS policies for verbs the trigger will always
refuse. The trigger means there is no actual vulnerability. But the ledger is the more important table
of the two, and defending it *less* than the audit log is an inconsistency that should not survive
review.

---

<a name="12-where-qayd-stands-today"></a>
## 12. Where QAYD stands today, mapped onto this model

Assessed against §1's five-part reference model. Sources: the S2 migrations, `JournalEntryPostingService`,
`TECH_DEBT.md`, `docs/execution/SPRINT_02.md`.

### 12.1 Part-by-part

| Part | QAYD today | Grade |
|---|---|---|
| **1. Instruction** | Draft journal entry → `PostJournalEntryAction`. No client idempotency key yet (S2-13 specifies `Idempotency-Key` keyed `(company_id, endpoint, key)` with `409` on fingerprint conflict). Atomic unit = one journal entry; there is no cross-entry batch. | **Good, incomplete** |
| **2. Postings** | `journal_lines` (posted, immutable via lifecycle) → `ledger_entries`, append-only by trigger, 1:1 by `uq_ledger_entries_journal_line`. Exact `NUMERIC(19,4)` + bcmath, zero tolerance in **both** currencies. Written by exactly one code path. | **Strong** |
| **3. Balances** | Derived: `SUM(signed_base_amount)`. Gross debit/credit/base columns all retained. Rollup (S2-09) not yet built. | **Strong** |
| **4. Rules** | Enforced inside the posting transaction: postable status, zero-tolerance balance, open fiscal period, active accounts. `trg_no_ai_autopost` forces AI entries to be created as drafts. Rules are **code**, not product configuration. | **Good** |
| **5. Proof** | Append-only triggers (L2), CHECKs (L0). No control totals, no re-derivation job, no hash chain, no anchor. Posting writes **no audit row at all** (TD-16). | **Weakest** |

### 12.2 The five specific gaps banking research identifies

Ordered by value-per-unit-effort, not by severity. Full treatment in
`IMPLEMENTATION_RECOMMENDATIONS.md`.

1. **A post leaves no audit trail.** TD-16 records that the posting path calls neither `AuditLogger`
   nor writes a history snapshot. A posting is currently traceable only via `posted_by`/`posted_at` on
   the entry itself. In a bank this would be a finding on day one — the *most consequential*
   state transition in the system is the one least evidenced. And because `audit_logs` is where the
   hash chain is planned to live, an unwritten audit row means the chain will not cover posting at all.
2. **`ledger_entries` is defended less than `audit_logs`.** Missing `REVOKE UPDATE, DELETE`; carries
   RLS policies for verbs that can never succeed. §11.
3. **The balance invariant is not DB-enforced.** `chk_je_balanced` constrains cached totals to each
   other, not to the lines. A deferred constraint trigger closes it. §11.
4. **No control totals and no re-derivation.** S2-14 exists in the backlog with exactly the right
   scope. Banking research says it is worth **more** than the hash chain and should not wait for it.
   §10.4, §10.6.
5. **No temporal constraints.** Nothing relates `journal_date` to `posted_at`; nothing bounds
   backdating or future-dating. Vault bounds both at ±90 days in the *type*. §6.2.

### 12.3 What QAYD already does that core banking would recognise

Stated deliberately, because a research document that only produces work items is misleading:

- **One write path into the ledger, with no bypass.** This is the property that everything else in
  this document depends on, and QAYD has it by construction.
- **Zero-tolerance balance checking in both entry and base currency**, re-derived from the lines and
  never trusting a cached header total.
- **Exact decimal arithmetic end to end** — `NUMERIC(19,4)` in the schema, bcmath strings in PHP, with
  an explicit `LogicException` if a non-numeric value is ever read back.
- **Append-only enforced at the database**, not by convention.
- **A normalised signed amount** so a balance is a single `SUM()` — the same idea as Vault's `net`.
- **An AI-safety invariant enforced by a trigger** (`trg_no_ai_autopost`) rather than by a policy
  document. Vault's equivalent posture — bypasses exist but are *named, carried on the record, and
  auditable* — is the same instinct.

The honest summary: **QAYD's ledger core is already built to a standard core banking would find
familiar. Its proof layer is not.** That is the finding this research exists to deliver, and it is a
much better position to be in than the reverse.
