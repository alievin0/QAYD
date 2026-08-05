# ANTI_PATTERNS.md — What Fails, and the Mechanism of Failure

**Ledger design mistakes as seen from core banking.**
Each entry states the pattern, **why it is tempting**, the **mechanism** by which it causes harm, the
**observable symptom**, and the **correct alternative**.

Version 1.0 · 2026-07-28 · Research artifact.
Evidence grades: `[DOCS]` `[CODE]` `[COMMUNITY]` `[INFERENCE]` `[UNKNOWN]`

> **Two categories, and the second matters more.**
> **Part A** — things accounting software does that banking would refuse.
> **Part B** — things *banking* does that would be actively harmful in an SME accounting product.
> Part B exists because the failure mode of this research is over-adoption, not under-adoption.

> **Overlap policy.** `knowledge/04_REJECTED_PATTERNS.md` already carries 34 rejections (R-01…R-34).
> Where banking research merely *confirms* an existing rejection, this document says so in one line
> and moves on. Only genuinely new material is developed. **No amendments to existing rejections are
> proposed.**

---

## Part A — Accounting-software patterns that banking refuses

### BA-01 · The mutable balance column as the only record of truth

**Tempting because** it is the obvious schema. One row per account, one `balance` column, `UPDATE` it
on every transaction. Reads are O(1) and the code is four lines.

**Mechanism of failure.** The balance and the transactions are two independent records of the same
fact with no enforced relationship. Any of these silently desynchronises them, permanently:

- a transaction insert that succeeds while the balance update fails (or vice versa) outside a transaction
- a retry that applies the delta twice
- a manual `UPDATE` during a support incident
- a migration that backfills transactions without replaying balances
- any code path that writes a transaction without going through the balance updater

Once they diverge, **there is no way to determine which is correct**, because neither is derivable
from the other. The usual response is to "fix" the balance to match a manual count, which destroys the
last evidence of when the divergence started.

**Symptom.** A balance that disagrees with the sum of its own transaction list, and nobody can say
since when.

**Correct alternative.** Immutable postings as the sole authority; balances maintained as a cache in
the same transaction and **continuously re-derived and compared** (`ARCHITECTURE.md` §7). Every system
in this study does this, including TigerBeetle, whose maintained running totals exist *only* because
an O(1) invariant check is required in the write path — with the postings still immutable and
authoritative `[DOCS]`.

**QAYD status.** Already refused by construction (`ledger_entries` append-only; balance is `SUM()`).
Confirms `knowledge/04_REJECTED_PATTERNS.md`; no change proposed.

---

### BA-02 · Editing a posted entry "because it hasn't been reported yet"

**Tempting because** the correction is a one-character typo, the period is still open, and a reversal
plus re-post produces three entries where the user expected zero.

**Mechanism of failure.** It destroys the only property that makes any of the integrity machinery
work. Specifically:

- **Re-derivation stops proving anything.** If postings can change, rebuilding from them reproduces
  the corrupted state and the drift check passes on corrupt data (`ARCHITECTURE.md` §7).
- **Hash chains become theatre.** The chain is recomputed on edit and verifies fine.
- **The audit trail loses the question it exists to answer** — not "what does it say now" but "what
  did it say then, and who changed it".

TigerBeetle states the positive case exactly: immutable corrections preserve *"the original error,
when it took place, as well as any attempts to correct the record and when they took place"*
`[DOCS]` docs.tigerbeetle.com/coding/recipes/correcting-transfers/.

**Symptom.** An auditor asks what the books said on the 31st and the system can only answer what they
say now.

**Correct alternative.** Reversal-and-repost, always, with the reversal linked to its original. QAYD's
S2-06 and `knowledge/03_DESIGN_PATTERNS.md` P-13 are exactly this.

**QAYD status.** Refused at the database (`trg_ledger_entries_append_only`). Confirmed.

---

### BA-03 · `status = 'pending'` instead of a pending *balance*

**Tempting because** a status column is one column, and "just filter by status" seems obviously
sufficient.

**Mechanism of failure.** The available balance becomes a *predicate* rather than a *value*, which
means every query that computes a balance must remember to apply it — and every query that forgets
returns a plausible, wrong number. There is no compiler, constraint, or test that catches the
omission, because the query is syntactically valid and returns a number of the right shape.

It gets worse with partial settlement. When a KWD 500 authorisation settles at KWD 437.250, a status
column forces the caller to compute the residual, and every caller must compute it the same way. Vault
makes "how much is still pending" a *balance coordinate*, so there is nothing to compute
(`ARCHITECTURE.md` §2.2). TigerBeetle makes it two dedicated fields (`debits_pending`,
`credits_pending`) whose invariant check *includes* the pending amount `[DOCS]`.

**Symptom.** Two screens in the same product showing different balances for the same account, both
"correct" according to their own query.

**Correct alternative.** If pending states are needed, model them as a **dimension of the balance**,
not a flag on the row.

**QAYD status.** Does not apply today — QAYD has no pending concept, and `LESSONS_FOR_QAYD.md`
concludes it should not acquire a general one. Recorded so that *if* an unreconciled-bank-transaction
or commitment/encumbrance feature is ever built, it is built as a coordinate rather than a status.

---

### BA-04 · One timestamp for an event that genuinely has three

**Tempting because** `created_at` obviously suffices, and adding a second date field triggers a
support conversation about which one users should fill in.

**Mechanism of failure.** The system permanently loses the ability to answer *as-at* questions. With
one clock you cannot distinguish:

- an entry dated 31 July, entered 31 July
- an entry dated 31 July, entered 12 August as a backdated correction

and therefore cannot reproduce the trial balance as it stood on 31 July *as at 31 July* — which is
the exact document an auditor reconciles against. It is not recoverable later: the information was
never captured.

Vault carries all three (`value_datetime`, `booking_datetime`, `insertion_datetime`) and additionally
makes queries *declare which clock they mean* via `DateTimeView` `[CODE]`.

**Symptom.** "The July trial balance changed" — with no way to show what changed or when.

**Correct alternative.** At minimum the value/insertion pair, with the insertion clock system-assigned,
monotonic, and never user-editable. `ARCHITECTURE.md` §6.

**QAYD status.** Already has two clocks with the right types. Missing the constraints between them
(recommendation **BR-04**).

---

### BA-05 · Unbounded backdating

**Tempting because** a limit feels arbitrary and someone will always have a legitimate exception.

**Mechanism of failure.** Every backdated posting invalidates an unbounded amount of downstream
derived state — period balances, snapshots, statements, tax filings, anything already sent to a third
party. Without a bound, the recomputation cost is unbounded, so in practice **it is not done**: the
posting lands and the derived state is quietly stale. The system now has two disagreeing answers and
no rule for which is right.

Vault bounds `value_datetime` and `booking_datetime` to `[1970-01-01, now + 90 days]` **in the type
system**, not in a policy `[CODE]`.

**Symptom.** A closed period's numbers move.

**Correct alternative.** Period locks (already QAYD's AD-10 / S2-07 design) *plus* an explicit,
DB-enforced bound on how far outside "now" any entry date may sit. The bound is a `CHECK`, and its
violation is a clean 422 rather than a silent recompute obligation.

**QAYD status.** Period locking is designed (S2-07). The absolute bound does not exist
(recommendation **BR-04**).

---

### BA-06 · Multiple write paths into the ledger

**Tempting because** the import job / the year-end close / the opening-balance tool each has "special
needs" and routing them through the normal posting engine looks like unnecessary friction.

**Mechanism of failure.** Every invariant enforced by the posting engine is now enforced *N* times, in
*N* places, and the number of places is not visible from any one of them. When invariant N+1 is added
it is added once. The bypass path is invariably the one used for bulk operations, so when it is wrong
it is wrong at volume.

**Symptom.** A rule that is enforced when a user posts and not when the importer does.

**Correct alternative.** One path, no exceptions, with genuinely special cases expressed as *inputs*
to that path.

**QAYD status.** Already the governing invariant — and the reason `SetOpeningBalanceAction` was
deliberately deferred rather than implemented as a second path (TD-10). This is QAYD getting it
*right* under pressure, which is worth recording.

---

### BA-07 · The unnamed force flag

**Tempting because** production has an emergency and someone needs to post something the rules refuse.

**Mechanism of failure.** The override is a branch in code, so it leaves no record. Six months later
nobody can enumerate which entries bypassed which check. The override then becomes load-bearing —
some integration starts setting it routinely — and the rule it bypasses is now decorative.

Vault's treatment is the corrective: overrides exist (`advice` — *"skip balance checks for this
posting instruction"*; `override_all_restrictions` — *"whether to ignore all restrictions"*) but they
are **fields on the instruction**, persisted with it `[CODE]`. "Which postings skipped the balance
check" is a `WHERE` clause.

**Symptom.** An unanswerable audit question.

**Correct alternative.** Overrides are data on the record, with a reason, an actor, and a query.

**QAYD status.** No force flag exists. Recorded as a rule to hold when one is inevitably requested.

---

### BA-08 · An audit log that does not cover the most important event

**Tempting because** audit logging is built as cross-cutting infrastructure and wired to CRUD; posting
is not CRUD, so it is missed.

**Mechanism of failure.** The audit trail's coverage is inverted relative to consequence. Draft edits
(reversible, low-stakes) are logged; posting (irreversible, the moment money enters the books) is not.
And if the tamper-evidence chain lives in the audit table, **the chain does not cover posting at all**
— the integrity mechanism protects the trivia and not the ledger.

**Symptom.** `TECH_DEBT.md` TD-16, verbatim: *"Posting writes no `journal_entry_history` snapshot and
no `audit_logs` row … a post is currently traceable only through the entry's own
`posted_by`/`posted_at` and its ledger rows."*

**Correct alternative.** The audit write is inside the posting transaction, on the same path, so it
cannot be skipped and cannot be orphaned by a rollback.

**QAYD status.** **Live gap.** Recommendation **BR-01** — the highest-priority item in this research.

---

### BA-09 · Hash chain as the integrity strategy

**Tempting because** it sounds like the serious answer, and "cryptographically verified ledger" is a
good sentence.

**Mechanism of failure.** A hash chain in a table the attacker can write to proves nothing against
that attacker: they recompute the tail. It is tamper-evident only against someone unaware the chain
exists (`ARCHITECTURE.md` §10.1). Meanwhile it is *expensive* — a per-row hash on the write path,
canonical serialisation that must never change, and a verification job that costs a full scan — and
its expense competes for the budget of the two things that would actually have caught the realistic
failures:

- **control totals** (`SUM(signed_base_amount) = 0`, counts match, no number gaps)
- **re-derivation and compare**

Realistic threats to an SME accounting ledger — a projection bug, a partially-applied transaction, a
double-post under retry, a corrupt rollup — are all caught by control totals and none of them are
caught *better* by a hash chain.

**Symptom.** A shipped hash chain, no external anchor, no control totals, and a drift that went
undetected for a quarter.

**Correct alternative.** Build the layers in **value order**, not prestige order: control totals → 
re-derivation → privilege revocation → hash chain → **external anchor**. And a hash chain without an
anchor is half a feature; ship them together (`ARCHITECTURE.md` §10.2, §10.6).

**QAYD status.** The dormant `hash`/`prev_hash` columns (TD-06) are correct — reserving the space
costs nothing. The *sequencing* is the risk. S4+A is priced at 21 points and S2-14 at 3; this research
says S2-14 should not wait for it. Recommendations **BR-05**, **BR-08**, **BR-09**.

---

### BA-10 · Trusting the cached header total instead of the lines

**Tempting because** the header total is already computed and indexed, and re-summing lines on every
read is wasteful.

**Mechanism of failure.** A `CHECK (total_debit = total_credit)` on the header constrains **the cache
to itself**. It says nothing about whether either number equals the sum of the lines. A bug that
writes consistent-but-wrong totals passes every constraint in the schema.

**Symptom.** An entry that satisfies every database constraint and is not balanced.

**Correct alternative.** Enforce the *real* invariant at the database with a `DEFERRABLE INITIALLY
DEFERRED` constraint trigger evaluated at commit, once all lines are present `[DOCS]`
postgresql.org/docs/current/sql-createtrigger.html.

**QAYD status.** The posting service re-derives from lines with zero tolerance and never trusts the
header — which is correct. But the *database* only enforces the weaker constraint. Recommendation
**BR-03**.

---

### BA-11 · Floating-point money, anywhere in the pipeline

**Tempting because** JSON has one number type, JavaScript has one number type, and a chart library
wants floats.

**Mechanism of failure.** Beyond the classic rounding error: floating-point addition is **not
associative**, so a re-derived balance can differ from the maintained one *purely because the rows were
summed in a different order*. That makes the drift detector produce false positives, and a false-positive
alarm is a muted alarm. **One float anywhere destroys the value of the entire integrity layer**, not
just its own precision.

TigerBeetle refuses floats entirely — u128 integers with an asset scale `[DOCS]`.

**Symptom.** A nightly integrity job that alerts inconsistently and gets switched off.

**Correct alternative.** Exact decimal end to end, including the wire format and the client.

**QAYD status.** Refused (`NUMERIC(19,4)` + bcmath strings, with an explicit `LogicException` on a
non-numeric read). Confirms the existing rejection. The live risk is the **frontend**: S2-11 specifies
a client-side `deriveBalance`, and `knowledge` Principle P7 already requires it to use the same exact
semantics. Worth restating only because it is the one place a float can still get in.

---

### BA-12 · Silent date-shifting into an open period

**Tempting because** it is a helpful UX: the user posts to a closed period, the system quietly moves it
to the next open one, nothing fails.

**Mechanism of failure.** The entry is now in a period the user did not choose and does not know about.
The economic event is recorded in the wrong month. Nothing in the audit trail says a shift occurred,
because from the system's perspective nothing went wrong. It compounds silently across many entries.

**Symptom.** Month-end variances that trace to entries the user believes are in the prior month.

**Correct alternative.** Raise, and return a **suggestion the caller must explicitly accept**. This is
already QAYD's stated S2-06 constraint (*"reject silent date-shifting — raise, and return a suggestion
the caller must accept"*, `knowledge/08_MASTER_BACKLOG.md`). Recorded here because banking's version of
the rule is stronger and worth knowing: Vault rejects the *whole batch* atomically and returns a
structured `PostingInstructionRejectionReason` — a machine-actionable code, not prose `[CODE]`.

**QAYD status.** Correctly planned. No change.

---

### BA-13 · Reconstructing state from an event stream

**Tempting because** the events are already being published for the UI, and a consumer that folds them
avoids a round trip.

**Mechanism of failure.** Event delivery is at-least-once and, across partitions, not order-guaranteed.
A consumer that *folds* events into a balance will eventually double-apply or misorder one, and its
number will drift from the ledger's with no mechanism to notice. Worse, the drift is invisible on the
producing side.

**Symptom.** A dashboard whose total disagrees with the report, intermittently, unreproducibly.

**Correct alternative.** **Broadcast a signal, not the money.** The event says "entry 4471 posted"; the
consumer re-reads through the authoritative, RLS-enforced API. This is already
`knowledge/03_DESIGN_PATTERNS.md` P-11 and S2-13's stated design (*"broadcast a signal; fetch content
through RLS-enforced reads"*). Confirmed independently by banking practice, no change.

---

### BA-14 · Sharding a hot account to relieve contention

**Tempting because** it is the standard database answer and it does measurably work.

**Mechanism of failure.** The account's balance becomes a distributed sum. Every read fans out; every
new shard is a new place to forget; a single missed shard makes the books wrong by an amount nobody can
locate, and reconciliation cost grows without bound. It trades a *performance* problem for a
*correctness* problem, which is never a good trade in a ledger.

TigerBeetle states the structural reason: *"horizontal sharding doesn't work well for business
transactions that span multiple accounts"* `[DOCS]` — the hot row is a single row.

**Symptom.** A VAT control account whose balance requires a nine-way union and is occasionally wrong.

**Correct alternative.** Batch the aggregate effect, or derive rather than maintain for the hot
accounts. `ARCHITECTURE.md` §8.2.

**QAYD status.** Not present. Recorded as the wrong answer to a problem QAYD will eventually have
(§8.3 — the S2-09 rollup is where contention will first appear).

---

### BA-15 · Making the balance eventually consistent to remove contention

**Tempting because** it removes the write-path lock completely and the window is "only a few hundred
milliseconds".

**Mechanism of failure.** More insidious than BA-14. It does not merely create a window in which the
balance is wrong — it makes drift **expected**, and an expected drift cannot be alarmed on. The single
most valuable integrity mechanism available (maintained vs re-derived comparison, `ARCHITECTURE.md`
§7) is destroyed as a side effect of a performance optimisation, and the destruction is not visible in
the diff that causes it.

**Symptom.** A drift alert that fires constantly, is tuned to a threshold, and thereafter cannot
distinguish lag from corruption.

**Correct alternative.** Maintain the balance in the **same transaction** as the posting, or do not
maintain it at all. There is no defensible middle.

**QAYD status.** Not present; the S2-09 rollup is specified as an `AFTER INSERT` trigger — same
transaction, correct. Recorded to protect that decision from a future performance review.

---

### BA-16 · Retrying forever with the same idempotency key

**Tempting because** it is what "idempotent" is usually taken to mean, and it is what most
implementations derived from the standard HTTP pattern do.

**Mechanism of failure.** It conflates two different retries:

- *"my request may not have arrived"* → same key is correct
- *"my request arrived and was rejected for a business reason; here is another attempt"* → same key is
  **wrong**, because the second attempt is a different economic event

With one key for both, the second attempt either replays the first failure forever, or — worse — the
implementation treats a failed key as reusable and a genuine duplicate slips through.

TigerBeetle handles this explicitly with `id_already_failed`: *"A previous transfer with the same `id`
failed due to transient errors. Retrying with the same ID will always fail; use a new idempotency ID to
retry."* `[DOCS]` docs.tigerbeetle.com/reference/requests/create_transfers/

**Symptom.** A client stuck retrying a permanently-poisoned key, or a duplicate posting.

**Correct alternative.** Distinguish *transport* retry (same key) from *business* retry (new key), and
say which is which in the error response.

**QAYD status.** S2-13 specifies replay-on-same-key and `409` on fingerprint conflict — both correct —
but does not yet address the failed-key case. Recommendation **BR-06**.

---

### BA-17 · Random UUIDs as ledger primary keys

**Tempting because** UUIDv4 is the default, avoids coordination, and hides row counts.

**Mechanism of failure.** Random keys scatter inserts uniformly across the index keyspace, destroying
locality: every insert dirties a different page, write amplification rises, and the working set stops
fitting in cache. TigerBeetle is explicit: *"Random identifiers are not recommended – they can't take
advantage of all of the LSM optimizations"* and have *"significantly lower throughput than
strictly-increasing ULIDs"* `[DOCS]` docs.tigerbeetle.com/coding/data-modeling/. Its recommended scheme
is 48 bits of millisecond timestamp + 80 bits of randomness. `[UNKNOWN]` — no published quantification.

**Symptom.** Insert throughput that degrades as the table grows, with no query to blame.

**Correct alternative.** Time-ordered identifiers (ULID/UUIDv7 shape) wherever an identifier must be
non-sequential; plain `BIGINT IDENTITY` where it need not be.

**QAYD status.** Correct by accident and by good instinct — `BIGINT GENERATED ALWAYS AS IDENTITY` on
`ledger_entries` and `journal_entries`, monotonic and dense. Recorded so that a future "let's use UUIDs
for the public API" proposal is evaluated on this basis rather than aesthetics.

---

## Part B — Banking patterns that would be *wrong* for QAYD

This half is the point. Every item below is genuinely good engineering in a bank, and adopting it in an
SME accounting product would be a mistake. The reasoning is developed properly in
`LESSONS_FOR_QAYD.md`; these are the sharp warnings.

### BB-01 · Building a purpose-built ledger engine beside PostgreSQL

**What banking does.** Separates the data plane (a specialised ledger store) from the control plane (a
general-purpose database), because the hot path cannot afford a metadata lookup. TigerBeetle is explicit
that strings and metadata belong elsewhere `[DOCS]`.

**Why it is wrong for QAYD.** QAYD's posting volume is *orders of magnitude* below the point where this
trade pays. What the split costs is immediate and large: two stores to keep consistent, a distributed
transaction (or a saga) on the single most correctness-critical operation in the product, two backup and
restore stories, two RLS stories — **and `ledger_entries` losing the ability to join to `accounts`,
which is the basis of every report QAYD sells.** The tenancy model would have to be rebuilt from scratch
in a system that has no concept of it.

**The line.** Adopt banking's *invariants* inside PostgreSQL. Do not adopt its *topology*. The
PostgreSQL-first posture is right and this research strengthens rather than weakens it.

---

### BB-02 · A general pending/authorisation phase model

**What banking does.** Models `PENDING_IN` / `PENDING_OUT` / `COMMITTED` as balance coordinates,
because card authorisations, holds and partial settlements are the daily business of a bank.

**Why it is wrong for QAYD.** An SME accounting ledger has no authorisation concept. The nearest
analogues — an unreconciled bank transaction, a purchase commitment — are **narrow, specific features**
that deserve narrow, specific models. Introducing a general phase dimension means every balance query,
every report, every rollup and every export must now specify a phase, forever, to serve a feature that
does not exist.

**The line.** If and when an unreconciled-bank-balance or commitment feature is built, build it as a
*specific* thing (a status on bank transactions; a separate commitment ledger) and use §2.2's insight
only as a warning against `status='pending'` filtering (BA-03). Do not generalise pre-emptively.

---

### BB-03 · Real-time gross settlement latency budgets

**What banking does.** Engineers to double-digit-millisecond p95 at thousands of TPS, because a card
authorisation has a hard timeout imposed by a scheme.

**Why it is wrong for QAYD.** Nothing in SME accounting has a hard external latency deadline. A journal
post taking 200 ms rather than 20 ms is invisible to every user. Engineering for the harder number costs
architectural options — batching APIs, denormalisation, caching layers, a specialised store — every one
of which is a correctness liability paid for a benefit nobody perceives.

**The line.** Adopt banking's *benchmark honesty* (*"every test request passes through the same public
APIs, internal services, event streams, and database tables as a live, production transaction"*
`[DOCS]`), not its latency target.

---

### BB-04 · Programmable product contracts as the extension model

**What banking does.** Vault's Smart Contracts are Python programs defining a financial product, with
`pre_posting_hook` able to reject a batch and `post_posting_hook` able to emit further postings, plus a
three-level parameter hierarchy `[CODE]`. It is genuinely impressive.

**Why it is wrong for QAYD — at this stage.** It is an enormous surface: a sandboxed execution
environment, a versioning and migration story (Vault has a dedicated `conversion_hook` for exactly
this), a simulation harness, a per-tenant security boundary around executing customer code, and a
support burden when a customer's contract is wrong. QAYD's products are invoices and bills, not
mortgages with 40-year parameter drift.

**The line.** Steal two *ideas* cheaply — (i) validation runs on the *proposed* posting before
acceptance, with the same data shape as the post-commit view; (ii) rejections are a **closed
machine-readable enumeration** with a `CLIENT_CUSTOM_REASON` escape hatch. Both are days of work. The
execution environment is years and is `knowledge/07_QAYD_INNOVATION.md` territory, not Sprint 2.

---

### BB-05 · Nanosecond, cluster-assigned, globally-unique timestamps

**What banking does.** TigerBeetle assigns every timestamp from a fault-tolerant cluster clock; they are
*"unique, immutable and totally ordered"*, clients must submit zero, and `timestamp_must_be_zero` is
returned otherwise `[DOCS]`.

**Why it is wrong for QAYD.** PostgreSQL's `TIMESTAMPTZ` plus a monotonic `BIGINT IDENTITY` already
gives a total order within a single primary. QAYD is not a distributed cluster and does not need
distributed-clock machinery.

**The line.** Take the *principle* — **the system clock on a ledger row is assigned by the system and
is never client-supplied** — and enforce it with a `DEFAULT now()` plus the absence of any code path
that sets it. QAYD already does this. Do not take the mechanism.

---

### BB-06 · Deterministic simulation testing of the whole system

**What banking does.** TigerBeetle's VOPR runs whole clusters deterministically at *"1000x speed"* on
*"1024 cores"*, injecting network, storage and clock faults, with every failure reproducible from a
`(seed, commit)` pair `[DOCS]`/`[CODE]`.

**Why it is wrong for QAYD as a goal.** It requires the entire system to be deterministic — no ambient
clock, no ambient randomness, no real I/O — which is a total architectural commitment. QAYD is a Laravel
application on PostgreSQL; the framework alone forecloses it.

**The line.** The *transferable* piece is small, cheap, and genuinely valuable: **seeded, reproducible
property tests over the posting engine.** Generate thousands of random-but-valid entry shapes from a
recorded seed, post them, and assert the invariants (Σ = 0 per company, ledger count = posted line
count, rebuild = maintained, no number gaps). A failure is a seed, replayable. That is a week of work
and it is the highest-leverage testing idea in this research.

---

### BB-07 · Static memory allocation and the "no technical debt" doctrine

**What banking does.** TigerBeetle allocates all memory at startup and forbids dynamic allocation
thereafter; TIGER_STYLE.md sets a 70-line function limit, forbids recursion, requires all loops bounded,
and states *"Technical debt … We do it right the first time"* `[CODE]`.

**Why it is wrong for QAYD.** Static allocation is meaningless in PHP. And the no-debt doctrine, applied
literally to a pre-launch product, is a velocity catastrophe — QAYD's `TECH_DEBT.md` is a *sign of
health*: seventeen items, each with the reason it was deferred and the story that resolves it.

**The line.** Two ideas port, both free:
- **Assertions in production** on the posting path. The published rationale is the strongest sentence in
  the corpus: assertions *"downgrade catastrophic correctness bugs into liveness bugs"* `[CODE]`. QAYD
  already does exactly this in one place — the `LogicException` on a non-numeric money read. Generalise it.
- **Bound every loop and every batch.** An unbounded `whereIn` or an unpaginated export is the same class
  of failure the rule exists to prevent.

---

### BB-08 · A dedicated end-of-day batch window

**What banking does.** Even Vault, a real-time cloud-native core, still has an end-of-day consolidation —
*"90 minutes"* at up to *"20,676 TPS"* `[DOCS]`.

**Why it is wrong for QAYD.** It exists in banking because interest accrual, fee application and
regulatory extracts genuinely must happen once per day per account across tens of millions of accounts.
QAYD has none of those. A nightly window is a *place for things to hide* — a job that failed at 02:00 and
was noticed at 09:00, with the day's work built on wrong numbers.

**The line.** Keep everything synchronous and inside the posting transaction. The **one** thing QAYD
should run nightly is the integrity job (S2-14) — and note the inversion: banking runs a batch to
*produce* state, QAYD should run one only to *verify* it.

---

## Symptom → anti-pattern lookup

For the reviewer who has a symptom and not a diagnosis.

| Symptom | Suspect |
|---|---|
| Balance disagrees with the sum of its own transactions | BA-01, BA-15 |
| Cannot reproduce what the books said on a past date | BA-02, BA-04 |
| Two screens show different balances for one account | BA-03, BA-13 |
| A closed period's numbers moved | BA-05, BA-12 |
| A rule is enforced on one path and not another | BA-06 |
| "Who overrode this, and when?" is unanswerable | BA-07 |
| The audit trail has no record of a posting | **BA-08 (live in QAYD)** |
| Every DB constraint passes and the entry is still unbalanced | **BA-10 (live in QAYD)** |
| Drift alerts fire inconsistently and got muted | BA-11, BA-15 |
| A duplicate posting appeared after a network error | BA-16 |
| Insert throughput degrades as the table grows | BA-17 |
| Integrity work is expensive and catches nothing | BA-09 |
| Adding a "small" ledger feature touches every balance query | BB-02 |
| A performance fix quietly removed an invariant | BA-15, BB-03 |

---

## Candidate additions to `knowledge/04_REJECTED_PATTERNS.md`

Offered for the amendment process; **not** applied here, and none of them overturns an existing
rejection. In priority order:

| # | Proposed rejection | Basis |
|---|---|---|
| 1 | **A hash chain shipped without an external anchor** — half a feature that reads as a whole one | BA-09 |
| 2 | **An integrity mechanism sequenced before its own control totals** — prestige order over value order | BA-09 |
| 3 | **A cached aggregate constrained only against itself** (`CHECK` on header totals) — the constraint that proves nothing | BA-10 |
| 4 | **Same idempotency key for transport retry and business retry** | BA-16 |
| 5 | **Unbounded entry-date range** — a policy that must be a `CHECK` | BA-05 |
| 6 | **Eventual consistency between postings and maintained balances** — kills the drift alarm as a side effect | BA-15 |
| 7 | **Sharding a hot account** — trades a performance problem for a correctness one | BA-14 |
| 8 | **Pre-emptive generalisation of a banking primitive** (a phase dimension with no pending feature) | BB-02 |
