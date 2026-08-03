# IMPLEMENTATION RECOMMENDATIONS — Sequenced, costed, mapped to real stories

Version 1.0 · 2026-07-28 · Part of `docs/research/payments/`
Derived from `LESSONS_FOR_QAYD.md`. Every item names a real story from `docs/execution/SPRINT_02.md` or
`SPRINT_03.md`, or is explicitly deferred with a trigger.

---

## How to read this

Ten recommendations, `R-01` … `R-10`, in dependency order. Each carries:

| Field | Meaning |
|---|---|
| **Story** | The real story it lands in, or *deferred* with a trigger condition |
| **Value** | Critical (correctness / compliance / security) · High · Medium · Low |
| **Effort** | Fibonacci points, using the same scale as `08_MASTER_BACKLOG.md` |
| **Confidence** | High / Medium / Low — how sure we are this is the right thing to do |
| **Blocking?** | Whether a release should be held for it |

**Point totals are stated honestly.** These recommendations add work to two sprints that are already
committed at 47–48 points. Where an item expands a story's estimate, the expansion is named. Sequencing
advice at the end says what to cut if the points do not fit.

**The intake rule applies** (`08_MASTER_BACKLOG.md` §0): nothing here enters the plan without a value, an
effort, a dependency, and a named sprint — or an explicit deferral with a trigger.

---

# Part 1 — Sprint 02: S2-13 and its companions

The three items in this part are the reason to read this document before starting S2-13. As specified,
S2-13 is 3 points; as it should be built, it is 8, plus a 5-point companion.

## R-01 — Rebuild the idempotency layer on the database, not the cache

| | |
|---|---|
| **Story** | **S2-13** — *Idempotency + posted-event broadcast* (Accounting, currently 3 pts) |
| **Value** | **Critical** — the specified design permits a duplicate financial event |
| **Effort** | **5** (S2-13 grows from 3 → 8) |
| **Confidence** | **High** — the mechanism is a crash window, not a judgement call |
| **Blocking?** | **Yes.** This is the story's actual content. |
| **Requires** | **An ADR**, because it contradicts `docs/api/API_ARCHITECTURE.md` (MANIFEST Law 1) |

**Why.** Argued in `LESSONS_FOR_QAYD.md` §3.1 and `ANTI_PATTERNS.md` PA-05. Three defects in the
specified middleware, each independently sufficient to double-post: the key is written to Redis *after*
`COMMIT`; only successful responses are stored; the guard is a 10-second lock over an unbounded operation.

**What to build.**

An `idempotency_keys` table in the tenant database, written **inside the same transaction as the business
fact**:

```
idempotency_keys
  id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY
  company_id     BIGINT NOT NULL REFERENCES companies(id)
  endpoint       VARCHAR NOT NULL          -- part of the match key (Stripe: type + endpoint + params)
  idempotency_key VARCHAR(255) NOT NULL
  request_fingerprint  CHAR(64) NOT NULL   -- SHA-256 over a CANONICALISED body
  recovery_point VARCHAR NOT NULL          -- 'started' … 'finished'  (see below)
  locked_at      TIMESTAMPTZ NULL          -- expiry-and-reap, NOT a fixed short TTL
  response_code  SMALLINT NULL
  response_body  JSONB NULL
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now()

  CONSTRAINT uq_idem UNIQUE (company_id, endpoint, idempotency_key)
  + RLS, as on every tenant table
```

Semantics, following the documented Stripe contract (`ARCHITECTURE.md` §4.1):

| Case | Behaviour |
|---|---|
| New key | Execute; persist `response_code` + `response_body` **in the same transaction** |
| Same key, same endpoint, same fingerprint, `finished` | Replay the stored status and body verbatim; set a replay marker header |
| Same key, **different** fingerprint or endpoint | `409 idempotency_key_conflict` — **never** execute, **never** replay |
| Key row exists, `locked_at` unexpired, not `finished` | `409 idempotency_key_in_use` |
| Key row exists, `locked_at` **expired** | Resume from `recovery_point` (a reaper may also clear it) |
| Original request **failed** | **Store and replay the failure, including 5xx** |

**The three deltas from the current spec, restated so none is lost in implementation:**

1. **Storage moves from Redis to the tenant database, inside the transaction.** Redis may stay as a
   read-through cache in front of it; the database is the authority. This is the whole point.
2. **Failures are stored.** Remove the `if ($response->isSuccessful())` guard. Storing only successes means
   a request that posted and then failed to report gets re-executed.
3. **`endpoint` joins the key.** The API document scopes `(company_id, key)`; **S2-13 already specifies
   `(company_id, endpoint, key)` and is correct** — follow the sprint plan and amend the API document.

**Acceptance criteria to add to S2-13** (the existing three are correct and stay):

- Killing the process between the business `COMMIT` and the response leaves the key row **present**; the
  retry replays rather than re-executes. *This is the test that proves the whole recommendation.*
- A request that returns `500` after posting replays the `500` on retry and posts no second entry.
- Two concurrent requests with the same key: one executes, the other receives `409
  idempotency_key_in_use`; exactly one entry exists afterwards.
- A key whose `locked_at` has expired is recoverable rather than permanently wedged.
- The same key against a different endpoint returns `409 idempotency_key_conflict`.

**Note on what already protects you.** `uq_ledger_entries_journal_line` means a duplicate *post of the same
entry* cannot double-project `[CODE]`. The residual exposure is a duplicate *create-and-post*, and — once
`PaymentDispatchService` exists — a duplicate outbound payment. That is exactly the endpoint list the API
document's own table enumerates, so the exposure is real and named.

**Also needed:** a pruning job for expired key rows (`maintenance` queue, 1 pt, can follow).

## R-02 — Build the transactional outbox with S2-13, and put a version on the event

| | |
|---|---|
| **Story** | **S2-13**, as a companion story — call it **S2-13b** |
| **Value** | **Critical** — `AD-14`'s named gap, and its trigger has fired |
| **Effort** | **5** (matches `P-11`'s own estimate) |
| **Confidence** | **High** — designed, costed and specified already; only unbuilt |
| **Blocking?** | **Yes for S2-13's broadcast**, which is the second consumer |

**Why.** `AD-14` states the gap *"must be closed before the second consumer exists — at one consumer the
failure is recoverable by replay, at several it is a silent divergence between modules."* Three consumers
arrive in the next two sprints: S2-13's Reverb broadcast, S3-03's `bank.transaction.cleared`, and S3-07's
`events-ai` relay. The trigger has fired.

The payments corpus adds no new argument — it is unanimous that the outbox buys atomicity and that
exactly-once does not exist `[DOCS]` — but it does confirm the shape and supply the envelope.

**What to build.** `P-11` already specifies it; build exactly that. Outbox row written in the same
transaction; relay worker selecting `FOR UPDATE SKIP LOCKED`; at-least-once delivery.

**The one addition from this study — put a monotonic per-aggregate version on every event:**

```
event_id        (the consumer's dedup key)
name            (declared constant — never a call-site string)
aggregate_type  aggregate_id     (partition key ⇒ per-aggregate ordering)
version         (monotonic per aggregate ⇒ consumers reject stale)
occurred_at
payload         (COMPACT — see PA-15)
correlation_id  (mandatory once a stack trace becomes a chain)
```

`AD-14` future risk 3 already calls for this: *"a consumer that needs per-aggregate ordering must get a
version on the event and reject out-of-order, not assume."* Every provider in the corpus refuses ordering
guarantees in writing, and retries *guarantee* reordering rather than merely permitting it
(`BEST_PRACTICES.md` PB-14).

**Cost of adding the version now: effectively zero. Cost of retrofitting it across three consumers: not
zero.**

**Acceptance criteria.**

- The outbox row and the business row commit or roll back **together** (kill the transaction between the
  two writes; assert neither survives) — `P-11`'s test 2.
- The relay is idempotent under duplicate delivery of the same row — `P-11`'s test 3.
- Every dispatched event carries a version monotonic within its aggregate.
- Relay lag is observable and alertable.

## R-03 — Idempotent consumers: dedup insert inside the effect's transaction

| | |
|---|---|
| **Story** | **S2-13b**, with R-02 |
| **Value** | **High** — at-least-once delivery makes this a correctness requirement, not hygiene |
| **Effort** | **2** |
| **Confidence** | **High** |
| **Blocking?** | Yes, alongside R-02 — an outbox without idempotent consumers is half a mechanism |

**Why.** `AD-14`: *"At-least-once delivery makes every handler's idempotency a correctness requirement,
and non-idempotent handlers fail rarely and confusingly."* QAYD's knowledge base specifies the producer
side thoroughly and does not yet specify the consumer side.

**What to build.** An inbox table (or a dedup column on the affected entity), with the insert of
`(subscriber, event_id)` as a **PRIMARY KEY** in the **same transaction** as the business effect. A
duplicate fails on insert, rolls back, and the message is dismissed. `[DOCS]`

**The two mechanisms that make this the right shape, both worth stating in the story:**

1. **The uniqueness constraint *is* the concurrency control.** `SELECT` then `INSERT` is check-then-act and
   loses to two concurrent deliveries in separate transactions; a primary-key violation does not.
2. **The atomicity matters in both directions.** Dedup written before the effect swallows the message
   permanently if the effect fails; written after, a crash between them re-runs the effect. Both silent.

**Retention.** Bound it explicitly, and record *why* the bound is safe. A dedup store can only expire
entries if something else bounds the replay window (`ANTI_PATTERNS.md` PA-08). For internal outbox events
the bound is the outbox retention; for future external webhooks it will be the signed-timestamp tolerance.

---

# Part 2 — Sprint 03: banking and reconciliation

Sprint 03's design is sound. These four items are refinements and two small schema fixes; none changes an
architecture.

## R-04 — Name the two-phase model, and give unclearable transactions a terminal state

| | |
|---|---|
| **Story** | **S3-02** (state machine) and **S3-03** (clear-and-post) |
| **Value** | **Medium** — mostly documentation; one real behaviour |
| **Effort** | **1** |
| **Confidence** | **High** |
| **Blocking?** | No |

**Why.** `bank_transactions` moving `draft → … → cleared → reconciled` with the DB `CHECK` that
`cleared`/`reconciled` implies a non-null `journal_entry_id` is structurally the pending/posted split every
system in the corpus uses (`PB-02`). Naming it makes two later decisions free instead of accidental.

**What to do.**

1. **State the mapping in the S3-02 story text.** Pending = pre-`cleared`; posted = `cleared`; and the
   ledger deliberately has **no** pending state, because QAYD posts facts (`LESSONS_FOR_QAYD.md` §4.3).
   This prevents someone later "improving" `ledger_entries` with a status column, which `R-15` forbids.
2. **The one real behaviour: verify a terminal unsuccessful state exists.** All five systems in the corpus
   distinguish *resolved successfully* from *resolved unsuccessfully*. A transaction that will never clear
   must reach a terminal state, not sit in `pending_clearance` forever, or phantom obligations accumulate.
   `BANKING_SERVICE.md`'s state machine includes failure states — confirm the transitions into them are
   reachable and tested.
3. **Add a stale-pending report** rather than a timeout mechanism. TigerBeetle's self-releasing reservation
   timeout is the strong form; a report achieves most of it for a fraction of the cost.

## R-05 — Define statement-line identity and re-import behaviour

| | |
|---|---|
| **Story** | **S3-04** — *CSV statement import + tie-out gate* |
| **Value** | **High** — prevents a duplicate-or-lost-line class before any data exists |
| **Effort** | **2** |
| **Confidence** | **Medium** — the right *default* is clear; the ideal UX is not |
| **Blocking?** | No, but much cheaper now than after the first customer import |

**Why.** S3-04 makes statement lines immutable facts batched by `statement_import_id`, with a hard tie-out
gate and a bad import voided-and-re-run rather than edited. That is correct and matches the corpus's
all-or-nothing discipline (`PA-11`).

What it does not yet define is **what happens when a user imports a period that overlaps an existing
import** — which will happen, because a bank portal export is a date range the user chooses. Without a
defined behaviour the outcomes are duplicate lines (silent double-counting) or a hard rejection that
blocks a legitimate correction.

The corpus supplies the relevant facts, all from bank-data documentation `[DOCS]`:

- A posted bank transaction **is not immutable** — refunds and institutional recategorisation revise it.
- A bank's transaction id **is not stable across the pending→posted boundary**: the pending row is removed
  and a new row with a new id is added.
- The details **change** between versions: *"their name and amount may change."*

**What to do.**

1. **Record an explicit external identity per line where the file supplies one** — a bank reference,
   transaction id, or cheque number, stored as its own column, `NULL` when the format has none. This is
   `R-24`/`PA-16` applied at ingestion: capture identity at write time rather than inferring it later.
2. **Define overlap behaviour and write it in the story.** The recommended default: an import whose date
   range overlaps a committed import is **rejected with a named error** that offers voiding the earlier
   import. Rejecting is consistent with `AD-18` (nothing is silently corrected) and with S3-04's existing
   posture that a bad import is voided and re-run.
3. **Do not build fuzzy de-duplication between imports.** That is `R-24` by another name.

## R-06 — Two small schema fixes found while cross-checking

| | |
|---|---|
| **Story** | **S3-04 / S3-05** (a migration alongside either) |
| **Value** | **High** — one closes a latent correctness hole, one removes a rejected pattern |
| **Effort** | **2** total |
| **Confidence** | **High** for (a); **High** for (b) |
| **Blocking?** | No |

### (a) Constrain `journal_lines.currency_code` to its parent entry's currency

`journal_lines.currency_code` is `CHAR(3) NOT NULL` with **no CHECK, trigger or FK tying it to
`journal_entries.currency_code`** `[CODE]`, and `assertBalanced()` sums across all lines regardless of each
line's currency `[CODE]`. So a mixed-currency entry that balances in base currency but not per-currency
would pass. Modern Treasury enforces per-currency balancing specifically to prevent *"transactions that
appear balanced overall while actually losing money in specific currencies"* `[DOCS]`.

**Latent, not live** — no caller constructs such an entry, and the design assumption is one currency per
entry. But nothing enforces the assumption.

**The cheap fix is the constraint, not an engine change:** enforce that a line's `currency_code` equals its
parent's. That makes mixed-currency entries impossible and leaves the current assertion exactly correct. If
genuine multi-currency entries are ever wanted, the constraint is what you deliberately drop — and at that
moment the per-currency assertion becomes mandatory rather than optional, which is a much better place to
have the conversation than a silent pass.

### (b) Drop `journal_lines.reconciled` and `reconciled_at`

Both columns exist and are cast on the model. **No application code, no test and no factory writes either
one** — verified by grep across `apps/api/app`, `apps/api/tests`, `apps/api/database/factories` `[CODE]`.

Three reasons to remove rather than leave:

1. **It is exactly the shape `R-15` rejects.** `04_REJECTED_PATTERNS.md`'s symptom lookup names
   `reconciled` on a ledger row as `R-15`, described there as *"the single most consequential entry in this
   document."*
2. **It is unwritable when it would matter.** `fn_block_update_when_posted` rejects any `UPDATE` on a line
   whose parent is `posted`/`reversed`/`voided`/`archived` `[CODE]`. Reconciliation state is only
   meaningful for posted lines, so the column can only be set on a draft — which is meaningless.
3. **S3-05 builds the correct alternative.** `bank_reconciliation_matches` records `rules_fired`,
   `final_score` and `match_method` as its own rows — which is what the whole corpus does (matches are
   rows; never a mutable flag on the money row).

This is precisely the failure `08_MASTER_BACKLOG.md`'s intake rule cites: *"Odoo's `secure_sequence_number`
— a live column, a dead code path, written only by tests."* Here it is at year zero, and it is cheaper to
remove now than it will ever be again.

**Add the schema-shape test.** `04_REJECTED_PATTERNS.md` lists **E-15** — *"Schema-shape test on
`ledger_entries` column set"* — as outstanding enforcement debt, at 1 point. Fold it in here and extend it
to `journal_lines`, so a future `reconciled` column fails CI.

## R-07 — Build S3-06's variance generically

| | |
|---|---|
| **Story** | **S3-06** — *Variance + period close* |
| **Value** | **Medium** — avoids a rewrite when PSP settlement lands |
| **Effort** | **1** (framing, not extra code) |
| **Confidence** | **Medium** |
| **Blocking?** | No |

**Why.** `variance = book_balance − adjusted_bank_balance` is the clearing-account difference by another
name (`ARCHITECTURE.md` §7.4). The same computation covers PSP settlement reconciliation later — the
sources differ, the arithmetic does not. Every processor in the corpus publishes the same closing identity
in the same shape:

```
starting balance + activity − payouts = ending balance
```

**What to do.** Express variance over a *reconciliation subject* (a bank account today, a settlement batch
later) rather than hard-coding `bank_accounts`. This is `P-03` (the Seam Pattern) applied at a boundary
that is currently invisible and will be obvious in a year.

**What not to do.** Do not build the settlement module now. `MANIFEST` Law 2 — building the future is a
rejection, not a virtue. This is one interface parameter, not a subsystem.

## R-08 — Re-read `R-24` before S3-05, and prefer explicit references in ranking

| | |
|---|---|
| **Story** | **S3-05** — *Deterministic reconciliation engine* |
| **Value** | **High** — the rule is already right; this sharpens the ranking |
| **Effort** | **1** |
| **Confidence** | **High** |
| **Blocking?** | No |

**Why.** S3-05's design already satisfies `R-24`'s exception correctly: amount+date scores 30 and cannot
alone reach the 90 auto-commit threshold; exact reference scores 45; every committed match records
`rules_fired`. Nothing needs changing.

What this study adds is **stronger evidence for the rule and one ranking refinement.** `R-24`'s evidence
to date is an Odoo post-mortem; the payments corpus supplies a bank-data provider documenting that its own
feed contradicts amount matching: *"The pending and posted versions of a transaction may not necessarily
share the same details: their name and amount may change"* `[DOCS]`. So amount matching produces **false
negatives** as well as false positives.

**What to do.**

1. Where a statement line carries an explicit external reference (R-05a), that reference should dominate
   the ranking — which the 45-point exact-reference rule already does. Confirm the rule reads the new
   column.
2. Add the Plaid finding to the story's rationale, so the threshold is not "tuned" downward later by
   someone who has not seen the evidence.
3. Keep the signature test from the sprint plan: an AI-only signal, at any confidence, stays below the
   auto-commit line. That is `R-32` and `PA-09` enforced in code.

---

# Part 3 — Deferred, with triggers

## R-09 — Webhook ingress and egress discipline

| | |
|---|---|
| **Story** | *Deferred.* **Trigger:** the first inbound webhook (a PSP), or the first customer-facing outbound webhook |
| **Value** | High when triggered |
| **Effort** | **8** (ingress 5, egress signing 3) |
| **Confidence** | **High** — the corpus is unanimous and the spec is stable |

When triggered, build from `BEST_PRACTICES.md` PB-16 and PB-17, and `ARCHITECTURE.md` §6:

- **Ingress:** raw bytes → verify signature (constant-time, signed timestamp with a tolerance, reject
  non-current schemes) → **persist the raw event** keyed by provider event id → **return 2xx** →
  worker deduplicates, fetches current state from the provider's API, applies. Verification precedes the
  acknowledgement, or the queue has an unauthenticated write path into it.
- **Egress:** adopt the Standard Webhooks header shape — `webhook-id`, `webhook-timestamp`,
  `webhook-signature`, signing `msg_id.timestamp.payload`, with multiple space-delimited signatures so key
  rotation is non-breaking and the scheme version living *inside* the signature value so scheme migration
  is non-breaking too `[DOCS]`.
- **Dead-letter store**, queryable and alertable. Retention windows are short and unforgiving — Stripe
  keeps events retrievable for 30 days; Plaid retries for 24 hours and then the event is gone permanently
  `[DOCS]`.
- **Alert on absence.** The characteristic failure is a disabled endpoint producing silence
  (`PA-07`), and absences are not monitored unless someone decided to monitor them.

## R-10 — API surface refinements

| | |
|---|---|
| **Story** | *Deferred to Sprint 04+*, alongside the public API work |
| **Value** | Medium |
| **Effort** | **3** |
| **Confidence** | **Medium** |

Three amendments to `docs/api/API_ARCHITECTURE.md`, argued in `ARCHITECTURE.md` §9:

1. **Cursor-paginate append-heavy collections regardless of volume.** The spec offers cursor pagination for
   *"high-volume, time-ordered resources."* The correct trigger is **append-heaviness**:
   `journal_lines`, `ledger_entries`, `audit_logs`, `bank_statement_lines` are unstable under offset
   pagination even with few rows (`PA-10`).
2. **Publish the additive-change contract explicitly** — ignore unknown fields, ignore unknown event types,
   never parse or length-bound an id. Stripe's list of backwards-compatible changes is simultaneously a
   definition and a contract on the client, and saying so is what makes shipping additively safe forever
   `[DOCS]`.
3. **Add the information-leakage rule to the error taxonomy.** Stripe deliberately tells the integrator
   more than the integrator may tell the end user `[DOCS]`. QAYD will have the same need — an AI-flagged
   anomaly, a failed sanctions check — and the distinction between machine-readable diagnosis and
   human-facing message is far easier to build in than to retrofit.

**Also fold in the amendment R-01 requires:** the API document's idempotency section must be updated to
match the ADR (database storage, failures stored, endpoint in the key).

---

# Sequencing, and what to cut

## Points added

| Sprint | Item | Points | Net effect |
|---|---|---|---|
| 02 | R-01 (S2-13: 3 → 8) | +5 | |
| 02 | R-02 (S2-13b, outbox) | +5 | |
| 02 | R-03 (idempotent consumers) | +2 | **+12 to Sprint 02** |
| 03 | R-04 name the two-phase model | +1 | |
| 03 | R-05 statement-line identity | +2 | |
| 03 | R-06 two schema fixes (+ E-15) | +2 | |
| 03 | R-07 generic variance | +1 | |
| 03 | R-08 `R-24` re-read | +1 | **+7 to Sprint 03** |
| later | R-09 webhooks · R-10 API | 8 + 3 | deferred with triggers |

**Twelve points is a large addition to a committed sprint,** and pretending otherwise would make this
document useless. Two honest options:

**Option A — absorb R-01 and R-02, defer R-03 by one sprint.** R-03 is only load-bearing once a *second*
listener exists on the same event. S2-13's broadcast is one. Landing R-03 early in Sprint 03, before
`bank.transaction.cleared` and the `events-ai` relay arrive, is defensible. Net: +10 to Sprint 02.

**Option B — split S2-13b (the outbox) into Sprint 03, keep R-01 in Sprint 02.** Defensible only if the
Reverb broadcast is the sole consumer through the end of Sprint 02, which it is. `AD-14`'s trigger is *the
second consumer*, and that arrives with S3-03. Net: +5 to Sprint 02, +5 to Sprint 03.

**Not defensible: shipping S2-13 as specified.** R-01 is not an enhancement to the idempotency story; it is
the difference between an idempotency layer that holds under a crash and one that does not. A 3-point
story that permits a duplicate financial event is not a 3-point story.

## Dependency order

```
R-01 idempotency on the DB ──┬──▶ R-02 outbox + event version ──▶ R-03 idempotent consumers
   (ADR required)            │                                          │
                             │                                          ▼
                             │                              R-09 webhook ingress/egress
                             │                                  (trigger: first PSP)
                             ▼
                     R-04 name two-phase  ──▶ R-05 statement identity ──▶ R-08 R-24 ranking
                             │                                                  │
                             ▼                                                  ▼
                     R-06 schema fixes                                  R-07 generic variance
                     (independent; do early)                                    │
                                                                                ▼
                                                                        settlement module
                                                                        (trigger: first PSP)
```

R-06 has no dependencies and closes two real holes for 2 points. It is the cheapest item here and should
land regardless of what else does.

## The blocking bar

Two items should hold a release; the rest should not.

- **R-01** — a specified idempotency layer that permits a duplicate financial event is release-blocking on
  the same grounds Sprint 03 treats the clear-needs-journal invariant and the AI cap as blocking: it is a
  core platform promise, not a feature.
- **R-02 + R-03**, from the moment a second consumer exists. Before that, `AD-14`'s own reasoning says the
  failure is recoverable by replay.

Everything else is a refinement, and refinements do not hold releases.

---

# What this study did not resolve

Recorded so nobody re-runs the same searches expecting a different answer.

| Question | Status |
|---|---|
| Wise's internal ledger, FX-as-ledger-operation, reconciliation | `[UNKNOWN]` — no first-party engineering writing found |
| PayPal's ledger design | `[UNKNOWN]` — blog exists, no ledger content verified |
| KNET's ownership, settlement timing, and whether any public API exists | `[UNKNOWN]` — `knet.com.kw` returned 403 on every attempt |
| Exact webhook retry backoff intervals for most providers | `[UNKNOWN]` — only total windows are published |
| Whether Stripe's 2017 version-change-module architecture is still current | `[UNKNOWN]` — no newer official write-up |
| Any forward-looking API-version support commitment from Stripe | `[UNKNOWN]` — the only strong statement is retrospective, and the same source anticipates retirement |
| Adyen's settlement delay in days, and how its accounting templates are specified/verified | `[UNKNOWN]` — the proof mechanism is asserted, not described |
| Whether Stripe or Adyen offer gross settlement with separately invoiced fees | `[UNKNOWN]` — Square documents one; the others are silent either way |
| Which file formats specific Kuwaiti banks (NBK, KFH, Gulf Bank, Boubyan, Burgan) export | `[UNKNOWN]` — **the highest-value follow-up, and it is answerable by a phone call rather than research.** It directly determines S3-04's format priorities |

The last row is the one to act on. Everything else in this table is closed knowledge belonging to someone
else; that one is open knowledge nobody has bothered to write down, and QAYD needs it more than anyone.
