# OVERVIEW.md — The Core Banking Landscape

**Who these systems are, what they actually publish, and the seven ideas core banking has that
accounting software does not.**

Version 1.0 · 2026-07-28 · Research artifact.
Evidence grades: `[DOCS]` `[CODE]` `[COMMUNITY]` `[INFERENCE]` `[UNKNOWN]`

---

## 1. The single most important finding

Stated first because it inverts the assumption this research began with.

> **The widely-repeated claim that core banking ledgers are immutable, append-only stores is not
> supported by published vendor documentation.**

Of every system studied, exactly **one** vendor publicly states that its postings are immutable —
**Increase**, a Banking-as-a-Service provider, not a core banking vendor `[DOCS]`. Meanwhile:

- **Mambu** documents an explicitly *mutable-until-period-close* general ledger, in which backdating
  is legal until a period is closed, closures are **deletable by an operator**, and GL accounts are
  `PATCH`-able `[DOCS]` docs.mambu.com.
- **Temenos** publishes an API described as managing accounting information *"such as updating,
  retrieving and **deleting** journal entries"* `[DOCS]` developer.temenos.com. What that surface
  actually permits is `[UNKNOWN]` — but it is not the vocabulary of an append-only store.
- **Finastra, FIS and Fiserv** publish essentially nothing on the question `[UNKNOWN]`.

Two systems *are* rigorously immutable and both are outside the vendor list this research was
commissioned against: **Thought Machine Vault** (immutable postings with three separate clocks, §3.1)
and **TigerBeetle** (transfers *"cannot be modified after creation"* and *"cannot be deleted after
creation"*, `[DOCS]`).

**What this means for QAYD.** The append-only `ledger_entries` table, the database-level immutability
trigger, and the reversal-only correction model are not table stakes QAYD is catching up on. **They
are ahead of what most of the incumbent core banking market publishes.** The gap this research
identifies is not immutability — it is **proof**: control totals, re-derivation, privilege revocation
and external anchoring (`ARCHITECTURE.md` §10). That is a much better position to be in than the
reverse, and it belongs in `knowledge/06_COMPETITIVE_ANALYSIS.md` as well as here.

---

## 2. The evidence landscape

### 2.1 What each system actually publishes

| System | What it is | Public engineering material | Depth achieved |
|---|---|---|---|
| **Thought Machine Vault** | Cloud-native core banking; products defined as "Smart Contracts" | Reference docs gated. **But** the Contracts SDK — the package clients use to write and unit-test contracts offline — is publicly mirrored and contains the authoritative type system and posting algebra, with Thought Machine's own docstrings. Plus a published performance whitepaper | **High** `[CODE]` `[DOCS]` |
| **TigerBeetle** | Open-source financial transactions database | Fully open source; extensive published rationale (`docs.tigerbeetle.com`, `ARCHITECTURE.md`, `TIGER_STYLE.md`, engineering blog), plus an independent Jepsen analysis | **High** `[DOCS]` `[CODE]` `[COMMUNITY]` |
| **Increase** | US BaaS / payments API | Excellent public API docs; explicit immutability, idempotency conflict behaviour, backwards-compatibility contract | **High** `[DOCS]` |
| **Column** | Nationally-chartered US bank with a public developer API | Excellent public docs; four-way balance decomposition, ACH state machine, webhook delivery guarantees | **High** `[DOCS]` |
| **Mambu** | Cloud "composable banking" | Real public API reference and accounting documentation; architecture described commercially | **Medium** `[DOCS]` |
| **Fiserv / Finxact** | Cloud core (Finxact) + legacy cores (DNA, Premier) | Finxact publishes some material — notably a TypeScript DSL for business rules. DNA/Premier: nothing | **Low–Medium** |
| **Temenos** | Largest incumbent core (Transact / T24) | Event schemas and articles on `developer.temenos.com`; the substance is behind a partner login | **Low — stated plainly** |
| **Finastra** | Incumbent core (Fusion Essence / Phoenix) | Developer portal exists but is a client-rendered SPA returning 403 to automation. **Verified as not statically retrievable, not as paywalled** | **Very low `[UNKNOWN]`** |
| **FIS** | Incumbent core (Modern Banking Platform) | API catalogue is an SPA; the "1,000+ APIs" figure is unverified marketing | **Very low `[UNKNOWN]`** |
| Unit · Treasury Prime | US BaaS | Partial: idempotency mechanisms documented, ledger model not | **Low** |
| Synctera · Solaris · Railsr | BaaS | Not covered in this pass — a **known gap**, not a finding | `[UNKNOWN]` |

### 2.2 Why the depth varies — and it is not quality

`[INFERENCE]`, but strongly supported: **the published/unpublished split tracks who the customer is,
not how good the system is.**

Column and Increase sell to engineers who must integrate unaided, so they publish balance semantics,
state machines and failure behaviour. Temenos, Finastra and FIS sell to banks that receive a partner
login, an implementation team and a support contract — so there is no commercial reason to publish,
and considerable reason not to.

The practical consequence: **two Banking-as-a-Service providers publish more usable ledger
engineering than all five incumbent core vendors combined.** That is the single most useful piece of
navigation in this research, and it is why `ARCHITECTURE.md` §4 is longer than §5.

A second consequence, forward-looking: as QAYD's own API becomes a product surface, the same pressure
will apply to QAYD — and Increase's and Column's documentation is the standard to aim at.

### 2.3 A note on the honesty of `[UNKNOWN]`

There are a great many `[UNKNOWN]` markers in these documents. They are **content, not gaps in the
work.** Knowing precisely what a competitor does not publish tells you two things: where you cannot be
beaten by copying, and where you must reason from first principles instead. This research explicitly
prefers a three-paragraph accurate section to a thirty-paragraph invented one, and §5 of
`ARCHITECTURE.md` is short on purpose.

Where published figures **contradict each other**, both are reported and neither is chosen — e.g.
TigerBeetle's simulator speed is documented as 1000× on the docs site and 700× on the marketing site;
its batch size appears as 8,189, 8,190 and "approximately 8,000" on different published pages.

---

## 3. The seven ideas core banking has that accounting software does not

This is the substantive answer to "what is this category for". Each is developed in
`ARCHITECTURE.md`; each is judged for QAYD in `LESSONS_FOR_QAYD.md`.

### Idea 1 — The balance is a *coordinate*, not a number

Vault keys a balance by **four** dimensions — `(account_address, asset, denomination, phase)` — and
the value at that coordinate is a **triple** `(credit, debit, net)`, not a scalar `[CODE]`.

Three consequences accounting software forgoes by using one number per account:

- **Gross totals survive.** "How much has ever flowed out of this account" is available without
  scanning postings. Netting is lossy; Vault refuses to be lossy at the balance layer.
- **One account holds many sub-balances.** `account_address` is a free string, so principal, accrued
  interest, fees and penalties live under one account identity — a chart of accounts nested inside an
  account.
- **The sign convention is data, not report logic.** `Tside` (ASSET/LIABILITY) is carried on the
  instruction and determines the net's sign, so no report re-derives it and no report gets it wrong.

Column arrives at the same place from a different direction, exposing **four** balance fields —
`available`, `pending`, `holding`, `locked` — where `locked` is money that *is* posted but cannot be
withdrawn `[DOCS]`.

### Idea 2 — Pending is a balance, not a status

`Phase = COMMITTED | PENDING_IN | PENDING_OUT` is a dimension of the balance `[CODE]`. TigerBeetle
does the same with four dedicated fields (`debits_pending`, `debits_posted`, `credits_pending`,
`credits_posted`) whose invariant checks **include the pending amount** `[DOCS]`.

The difference from `status = 'pending'` is not stylistic. A status column makes the available balance
a *predicate* that every query must remember to apply; a phase makes it a *value* that cannot be
forgotten. This is the mechanism behind two screens in one product showing different balances for the
same account, both "correct" per their own query (`ANTI_PATTERNS.md` BA-03).

### Idea 3 — Three clocks, and queries that declare which one they mean

Vault carries `value_datetime` (when it affects balances), `booking_datetime` (when the book says it
happened) and `insertion_datetime` (when the ledger learned of it), plus a `DateTimeView` selector so
a balance query must state which clock it means `[CODE]`. Temenos carries **four**, adding an Exposure
Date — *"the actual date on which the clear funds are available"* `[DOCS]`.

Collapsing them loses the ability to answer *"what did the books say on the 31st, as at the 31st?"* —
and the loss is unrecoverable, because the information was never captured. Mambu's workaround is
instructive: with a single date field, it stamps a day's accruals at `00:00:00` **of the next day**
`[DOCS]`, smuggling period semantics into a timestamp not designed to carry them.

### Idea 4 — Correctness is engineered, not hoped for

The techniques have no equivalent in accounting software:

- **Deterministic simulation.** TigerBeetle's VOPR runs whole clusters in one process with all
  non-determinism stubbed, injecting network, storage and clock faults — and every failure is
  reproducible from a `(seed, commit)` pair `[CODE]`.
- **Assertions in release builds**, minimum two per function, on at least two code paths per property,
  because assertions *"downgrade catastrophic correctness bugs into liveness bugs"* `[CODE]`. **A
  system that crashes is recoverable; one that silently computes the wrong balance is not.**
- **An explicit storage fault model.** *"TigerBeetle assumes that its disk will fail"* — checksums on
  everything, out-of-band checksums on block pointers to catch misdirected I/O, `O_DIRECT` with no
  filesystem required, and a deliberate choice to **halt rather than serve corrupt data** `[DOCS]`.
- **Benchmark honesty.** Thought Machine's whitepaper states that *"every test request passes through
  the same public APIs, internal services, event streams, and database tables as a live, production
  transaction"* `[DOCS]` — an explicit repudiation of benchmarking through a hardwired fast path.

### Idea 5 — Overrides are data on the record, never branches in code

Vault's bypasses exist and are *named fields on the posting instruction*: `advice` (*"skip balance
checks for this posting instruction"*) and `override_all_restrictions` (*"whether to ignore all
restrictions"*) `[CODE]`. "Which postings skipped the balance check?" is a `WHERE` clause, not an
archaeology project.

The rejection vocabulary is likewise a **closed, machine-readable enumeration** with an explicit
escape hatch (`CLIENT_CUSTOM_REASON`), and it includes `REVIEW_DEBITS` / `REVIEW_CREDITS` — *"allowed
but must be looked at"* as a first-class ledger outcome `[CODE]`. That is precisely the state an
AI-drafted journal entry occupies.

### Idea 6 — The atomic unit is the batch, and it spans accounts

Vault's `batch_id` identifies posting instructions that are *"atomically inserted into the ledger"* and
*"atomically accepted or rejected"* — across multiple accounts and multiple client transactions
`[CODE]`. TigerBeetle achieves the same with linked chains: *"these chains of events will all succeed
or fail together"*, executed in order with each effect visible to the next `[DOCS]`.

Note the inversion against accounting convention: TigerBeetle's *primitive* is a single debit and a
single credit — it deliberately does **not** support multi-leg transfers — and the N-leg entry is
*composed* atomically from linked 1:1 transfers `[DOCS]`.

### Idea 7 — The ledger is *proved*, continuously, not audited annually

This is the idea with the widest gap to accounting software, and the one QAYD stands to gain most
from.

Banking proves ledger integrity with a layered stack: schema constraints → privilege revocation →
triggers → **control totals** → **re-derivation and compare** → hash chains → **external anchoring**.
The two middle layers do most of the work and are the cheapest; hash chains are the most prestigious
and the least useful without an anchor, because a chain in a table the attacker can write to is
tamper-evident only against an attacker unaware it exists (`ARCHITECTURE.md` §10.1).

**The trial balance has always been a control total.** Framing it as an integrity *assertion* run on a
schedule — rather than a report a human opens — is the highest-value, lowest-cost idea in this
research.

---

## 4. What core banking does that QAYD should *not* copy

Placed in the overview deliberately, because the failure mode of research like this is over-adoption.

| Bank pattern | Why it is right there | Why it is wrong for QAYD |
|---|---|---|
| A purpose-built ledger engine beside the general database | The hot path cannot afford a metadata lookup | QAYD's volume is orders of magnitude below the crossover. The cost is a distributed transaction on the single most correctness-critical operation, two backup stories, two RLS stories — and `ledger_entries` losing the ability to join `accounts`, which is the basis of every report QAYD sells |
| A general pending/authorisation phase model | Card auths, holds and partial settlements are daily business | An SME ledger has no authorisation concept. A general phase dimension taxes every balance query, report, rollup and export forever, to serve a feature that does not exist |
| Double-digit-millisecond latency budgets | A card scheme imposes a hard timeout | Nothing in SME accounting has an external deadline. Engineering for it costs architectural options nobody perceives the benefit of |
| Programmable product contracts | A 40-year mortgage genuinely needs versioned, parameterised behaviour | A sandbox, a versioning/migration story, a simulation harness and a per-tenant security boundary around customer code. QAYD's products are invoices and bills |
| Cluster-assigned nanosecond timestamps | Distributed consensus needs a fault-tolerant clock | `TIMESTAMPTZ` + monotonic `BIGINT IDENTITY` on a single primary already gives a total order |
| Static memory allocation; "no technical debt" | Zig, and a system where an OOM is a regulatory event | Meaningless in PHP. And a literal no-debt doctrine pre-launch is a velocity catastrophe — QAYD's `TECH_DEBT.md` is a sign of health |
| A nightly end-of-day batch window | Interest accrual and regulatory extracts genuinely must run daily across millions of accounts | QAYD has none of those. A nightly window is a place for failures to hide. **The one job QAYD should run nightly is the integrity check** — note the inversion: banking batches to *produce* state; QAYD should batch only to *verify* it |

Full reasoning in `ANTI_PATTERNS.md` Part B and `LESSONS_FOR_QAYD.md`.

---

## 5. Where QAYD stands, in one table

Against `ARCHITECTURE.md` §1's five-part reference model.

| Part | QAYD today | Grade |
|---|---|---|
| **Instruction** | Draft → `PostJournalEntryAction`. Idempotency specified but not built (S2-13). Atomic unit is one entry | Good, incomplete |
| **Postings** | `ledger_entries` append-only by trigger, 1:1 by UNIQUE, exact `NUMERIC(19,4)` + bcmath, zero tolerance in both currencies, **one write path** | **Strong** |
| **Balances** | Derived via `SUM(signed_base_amount)`; gross and net both retained. Rollup pending (S2-09) | **Strong** |
| **Rules** | Enforced inside the posting transaction; `trg_no_ai_autopost` forces AI entries to draft. Rules are code, not configuration | Good |
| **Proof** | Triggers and CHECKs only. No control totals, no re-derivation job, no anchor — and **posting writes no audit row at all** (TD-16) | **Weakest** |

**The finding in one sentence:** *QAYD's ledger core is already built to a standard core banking would
recognise; its proof layer is not.*

The five specific gaps, in value-per-effort order — audit coverage of posting (TD-16), privilege
revocation on `ledger_entries`, a DB-enforced balance invariant, control totals + re-derivation
(S2-14), and temporal constraints — are worked through in `IMPLEMENTATION_RECOMMENDATIONS.md` as
**BR-01 … BR-12**.

---

## 6. Reading guide

| If you are… | Read |
|---|---|
| Deciding what to do about S2-14 or the hash chain | `IMPLEMENTATION_RECOMMENDATIONS.md`, then `ARCHITECTURE.md` §10 |
| Designing anything that writes to the ledger | `ARCHITECTURE.md` §§1–3, then `BEST_PRACTICES.md` |
| Reviewing a PR that touches balances or dates | `ANTI_PATTERNS.md` symptom lookup |
| Deciding how much banking rigour to import | `LESSONS_FOR_QAYD.md` — the whole point of it is the line |
| Writing competitive material | §1 of this document, plus `ARCHITECTURE.md` §5.5 |
