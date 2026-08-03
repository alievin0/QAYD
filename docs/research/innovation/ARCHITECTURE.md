# ARCHITECTURE — The substrate, in planes

**What an AI Financial Operating System is made of, and where each part runs · `docs/research/innovation/`**

Version 1.0 · 2026-07-28 · **Documentation only** — no application code, schema, migration or test was
modified in producing this. Every `[CODE]` reference was verified by reading the repository; nothing
below proposes a change that has been made.

---

## Contents

1. The design problem, and the answer in one sentence
2. Five planes, and why planes rather than layers
3. Plane 1 — **Assertion**: what exists, and the one verified gap
4. Plane 2 — **Authority**: from a role check to a capability grant
5. Plane 3 — **Attention**: the missing scheduler
6. Plane 4 — **Claim**: predictions as records
7. Plane 5 — **Evidence**: identity, provenance, blast radius
8. Layer allocation — what runs in PostgreSQL, Laravel, FastAPI, Next.js
9. Three sequence flows
10. The invariants this architecture adds
11. What is deliberately not built
12. Open questions this document does not settle

---

## 1 · The design problem, and the answer in one sentence

`docs/research/ai/ARCHITECTURE.md` answers *how to build an AI engine that cannot corrupt the books*. It
answers it well and it is not repeated here: quarantined pipeline, no database driver, code chooses
control flow, the model is a pure function from untrusted tokens to a typed proposal.

This document answers the question one level out:

> **What has to exist around that engine for the twelve capabilities in `OVERVIEW.md` Part 8 to be
> buildable at all — and which parts of it can only be built now?**

The answer in one sentence:

> **Four planes must exist beside the ledger — authority, attention, claim and evidence — each an
> append-only record with a deterministic evaluator, none of them written by a model; and the ledger's
> existing assertion plane is the only one QAYD has built.**

That is the whole architecture. The rest of this document is what each plane contains, where it runs, and
what breaks if it is deferred.

---

## 2 · Five planes, and why planes rather than layers

`OVERVIEW.md` §2.2 maps the operating-system analogy onto QAYD's existing code — MMU, syscall, journal,
scheduler. That mapping is the *thesis*. This is its build-out.

A **layer** is a horizontal slice of the stack: database, domain, API, UI. A **plane** cuts across all of
them. `05_FUTURE_ARCHITECTURE.md` already thinks this way about the data path; the same framing is
correct here, because each of the four new planes has a database component, a domain component and a
surface, and splitting them by layer loses the property that makes them work.

```
                      ┌──────────────────────────────────────────────────────┐
   ASSERTION PLANE    │  ledger_entries · journal_entries · PostingService    │  BUILT ✅
   what is true       │  append-only · immutable when posted · one gate       │
                      └──────────────────────────────────────────────────────┘
                                          ▲   every write passes here
                      ┌───────────────────┴──────────────────────────────────┐
   AUTHORITY PLANE    │  who or what may assert, scoped / delegated /          │  PARTIAL ⚠️
   under what right   │  expiring / revocable — today: an implicit role check  │  (I-27)
                      └──────────────────────────────────────────────────────┘
                                          ▲   gates proposals before they queue
                      ┌───────────────────┴──────────────────────────────────┐
   ATTENTION PLANE    │  reviewer capacity · consequence price · schedule ·    │  MISSING ❌
   who looks, and     │  review debt · engagement telemetry                    │  (I-21)
   at what            └──────────────────────────────────────────────────────┘
                                          ▲   consumes and produces claims
                      ┌───────────────────┴──────────────────────────────────┐
   CLAIM PLANE        │  every forward-looking statement stored with a         │  MISSING ❌
   what we said       │  measurement date, scored when it arrives              │  (I-22/23/26)
   would happen       └──────────────────────────────────────────────────────┘
                                          ▲   attributes everything above
                      ┌───────────────────┴──────────────────────────────────┐
   EVIDENCE PLANE     │  machine identity · inference locus · number           │  PARTIAL ⚠️
   how we know, and   │  provenance · audit chain · blast radius               │  (I-31/32, I-08,
   what we'd redo     └──────────────────────────────────────────────────────┘   I-12, I-30)
```

**Three properties every plane shares**, and they are the design rules:

1. **Append-only.** A plane records decisions, not state. `P5` for the ledger; the same discipline
   everywhere else, because a mutable authority record or a mutable review-debt flag has no evidentiary
   value.
2. **Deterministically evaluated.** Every plane has an evaluator — grant check, consequence estimator,
   claim scorer, provenance walker — and **not one of them may be a model**. `R-34` is the general rule;
   here it is load-bearing, because a model in an evaluator makes the plane's output unattributable and
   therefore worthless for the purpose the plane exists to serve.
3. **Written only from the trusted zone.** The AI engine may *cause* a plane row to exist by producing a
   proposal, never *write* one. `P15`.

---

## 3 · Plane 1 — Assertion: what exists, and the one verified gap

This plane is built, and it is the reason the thesis is credible rather than aspirational. Verified by
reading the repository:

| Mechanism | Where | Effect |
|---|---|---|
| Single posting path | `apps/api/app/Services/Accounting/JournalEntryPostingService.php` (252 lines) `[CODE]` | One gate; `P7` |
| `ai_generated BOOLEAN NOT NULL DEFAULT false` | `…2026_07_28_000004_create_journal_entries_table.php:58` `[CODE]` | Machine origin is a column, not a convention |
| `fn_no_ai_autopost` / `trg_no_ai_autopost` | same file, `:143-157` `[CODE]` | `IF NEW.ai_generated AND NEW.status <> 'draft' THEN RAISE` — the MMU |
| `signed_base_amount NUMERIC(19,4) NOT NULL` + `chk_le_signed` | `…000007_create_ledger_entries_table.php:49,58` `[CODE]` | Fast, checked aggregation without float |
| `ENABLE` + `FORCE ROW LEVEL SECURITY` | every tenant table `[CODE]` | Authority cannot leak across tenants |
| `hash` / `prev_hash CHAR(64) NULL` | `…2026_07_27_000010_create_audit_logs_table.php:84-85` `[CODE]` | Dormant columns reserved for the per-company chain |

**The one verified gap is already in the backlog and this document does not re-discover it.**
`08_MASTER_BACKLOG.md` **IM-01** records that `trg_no_ai_autopost` is `BEFORE INSERT` only, so it blocks
*creating* a non-draft AI entry but not *updating* an existing AI draft into a posted state — application
code alone stands in the way. It is scored Critical / complexity 2 / P0 and is the highest
value-to-cost item in that document.

**What this architecture adds to IM-01:** under the thesis, that trigger is not a safety feature, it is
**the constitutional basis of the category**. Every capability in `OVERVIEW.md` Part 8 assumes it holds.
So the fix is not merely a P0 defect — it is the precondition for the other four planes being worth
building at all, and it should be framed that way when it is sequenced.

**The dormant hash columns are the second-most-important thing in this plane.** They are the anchor point
for I-08, and I-08 is the only capability `07` grades as durable for three or more years. Columns that
exist and are unused cost nothing; a chain that starts in 2029 is worth what a 2029 chain is worth. See
`IMPLEMENTATION_RECOMMENDATIONS.md` **IR-06**.

---

## 4 · Plane 2 — Authority: from a role check to a capability grant

### 4.1 The current model, stated honestly

Authority today is **binary and implicit**: a role either may post or may not, and the machine's authority
is expressed as a single boolean column plus a trigger that refuses it. That is a good boundary and a poor
model. It cannot express *"this capability may propose bank matches under KWD 500 on accounts in the bank
class until 3 June, and its grant was issued by the controller"* — which is the sentence every autonomy
conversation eventually needs.

### 4.2 The grant object

`OVERVIEW.md` **I-27** as an architecture:

```
   capability_grants                     (append-only; revocation is an event, not a delete)
   ─────────────────────────────────────────────────────────────────────────────
   id · company_id · capability          which capability, from the closed enum
   granted_by (user) · granted_at        a person, always — never a system default
   scope_predicate                       DATA, evaluated deterministically:
                                           account class · amount ceiling · period
                                           · counterparty set · document type
   autonomy_mode                          propose_only | propose_and_prefill | act_reversible
   reversibility_budget                   I-17's budget, denominated per grant
   expires_at                             MANDATORY. Renewal is an explicit act.
   revoked_at · revoked_by · reason       an event; the row is never deleted
```

**Four design rules, each with a reason:**

1. **Scope is data, never code** (`P18`, `R-33`). A grant is a row a customer can read and an auditor can
   read. A grant expressed as a predicate in PHP is a grant nobody outside engineering can inspect.
2. **Expiry is mandatory.** A grant with no expiry is a permission, and permissions accumulate. The
   renewal event is the governance artefact — it is the moment a human re-affirms that a machine may act,
   and it belongs in the audit log.
3. **Evaluation happens at proposal time and is stamped on the proposal**, not re-evaluated at posting
   time. Otherwise a revocation retroactively invalidates work already reviewed, which is confusing and
   wrong: the question is *"was this authorised when it was proposed?"*
4. **A grant is not a role.** Roles say who a person is. Grants say what a capability may do, on whose
   authority, for how long. Conflating them is how the agent-org-chart anti-pattern (`ANTI_PATTERNS.md`
   **IA-07**) gets in through the back door.

### 4.3 What this replaces

| Instead of | Use |
|---|---|
| "The AI agent has permission to…" | A grant, with a grantor and an expiry |
| A confidence threshold authorising an action (`R-32`) | A grant's `autonomy_mode` plus I-17's reversibility budget |
| Thirteen agents with thirteen job titles | Thirteen capability scopes on one runtime (`docs/research/ai/` **L-03**) |
| A settings toggle labelled "enable automation" | A grant issued by a named person, visible in the audit log |

---

## 5 · Plane 3 — Attention: the missing scheduler

This is the plane nobody in the market has built (`OVERVIEW.md` §2.2 observation 2, §3.2, I-21), and its
absence is the clearest structural gap the operating-system analogy exposes.

### 5.1 Position in the stack

The scheduler sits **between the proposal store and the review UI**, and it is the only consumer of
proposals. That is the architecturally significant claim: capabilities do not own queues. There is one
queue, and the scheduler owns it.

```
   capability A ─┐
   capability B ─┼──► PROPOSALS (one store, one shape, per P-12)
   capability C ─┘         │
                           ▼
                  ┌────────────────────────────────────────────┐
                  │ CONSEQUENCE ESTIMATOR                      │  deterministic, published,
                  │ amount · account sensitivity · period      │  customer-editable, versioned
                  │ proximity · counterparty novelty ·         │  — NO MODEL (R-34)
                  │ feeds-a-filing · reversibility · precedent │
                  └───────────────┬────────────────────────────┘
                                  │ score + estimated minutes, STORED on the proposal
                                  ▼
                  ┌────────────────────────────────────────────┐
                  │ SCHEDULER      capacity from telemetry,    │
                  │                not from self-report        │
                  └───────┬────────────────────────┬───────────┘
                  above   │                        │  below the line
                  the line▼                        ▼
             ┌──────────────────┐      ┌───────────────────────────────┐
             │ REVIEW QUEUE     │      │ REVIEW DEBT                   │
             │ ordered by       │      │ append-only record of         │
             │ consequence/min  │      │ DECISIONS NOT TO SHOW         │
             └────────┬─────────┘      │ ages · reported · blocks close│
                      │                └──────────────┬────────────────┘
                      ▼                               │
             ┌──────────────────────────┐             │
             │ ENGAGEMENT TELEMETRY     │             │
             │ dwell · modify-rate ·    │◄────────────┘  blind sampling draws from
             │ blind-sample divergence  │                BOTH sides of the line
             └───────────┬──────────────┘
                         │ recalibrates capacity; feeds I-04 as its own assurance band
                         ▼
```

### 5.2 Three architectural commitments

1. **One queue, not one per capability.** If capabilities own queues, the sum is never computed and
   `OVERVIEW.md` §3.2's failure — safety degrading as a function of product success — is unobservable by
   construction. This is the single most important structural decision in the plane, and it is
   *cheap now, expensive later*: merging six shipped queues is a migration plus a UI rewrite.
2. **Review debt is append-only and is a record of decisions, not a flag.** A boolean `was_reviewed` is
   worthless as evidence. A row saying *"this proposal was excluded on 14 March by capacity policy v3,
   consequence score 41, estimated 6 minutes"* is defensible.
3. **Blind sampling draws from below the line as well as above.** Otherwise the estimator's own errors
   are invisible — you only ever measure the items the estimator already thought were important. This is
   the mitigation for the plane's Critical risk.

### 5.3 Relationship to the AI engine

None, deliberately. The scheduler runs entirely in the trusted zone (Laravel + PostgreSQL), reads
proposals, and never calls the model. That is what makes its output usable as evidence, and it means the
plane can be built and tested with no AI dependency beyond the existence of proposals.

---

## 6 · Plane 4 — Claim: predictions as records

### 6.1 One store, three consumers

`OVERVIEW.md` I-22 (banded cashflow), I-23 (advice record) and I-26 (solvency tripwire) each need the same
thing and must not build it three times.

```
   claims                                  (append-only; one row per emitted prediction)
   ────────────────────────────────────────────────────────────────────────────
   id · company_id · capability · claim_kind        forecast | advice | tripwire | exposure
   subject_ref                                      what it is about (account, counterparty, entity)
   band                                             committed | expected | speculative   (IB-09)
   value · range_low · range_high · currency        a RANGE for speculative; never a bare point
   basis                                            the inputs, referenced — not prose
   machine_identity_id ──────────────────────────►  FK into the evidence plane (§7)
   grant_id ─────────────────────────────────────►  FK into the authority plane (§4)
   made_at · measure_on
   outcome · measured_value · scored_at             correct | wrong | superseded | unmeasurable
```

**Why `superseded` is a distinct outcome and not a failure.** A forecast about a contract that was
cancelled was not wrong; the world changed. Collapsing the two makes the hit rate meaningless and makes
the decay curve — the only genuinely differentiated artefact this plane produces — untrustworthy.

### 6.2 The scorer

A scheduled job over a `measure_on` index. Deterministic. It compares the stored claim against the
realised value and writes the outcome once; outcomes are immutable. The decay curve is a rollup over
scored claims, grouped by capability, band and horizon.

**The architectural point that justifies the whole plane:** the decay curve is only cheap if claims were
stored at emission. There is no migration that reconstructs what the system predicted in March. This is
the lowest-cost closing window in the folder and the one most likely to be deferred, which is exactly why
`BEST_PRACTICES.md` **IB-13** exists.

### 6.3 What must not be in this plane

- **No model in the scorer.** `R-34`.
- **No claim without a `measure_on`.** A prediction that cannot be scored is not a claim; it is copy, and
  it should be refused at the schema level with a `NOT NULL`.
- **No single total across bands rendered by default** (`IB-09`). If an export requires one, it carries
  the range.

---

## 7 · Plane 5 — Evidence: identity, provenance, blast radius

### 7.1 Machine identity (I-31) — the smallest table with the largest consequence

```
   machine_identities                     (immutable; a new version is a new row)
   ──────────────────────────────────────────────────────────────────────────
   id · provider · model_id · model_version
   prompt_version · policy_version · capability
   effective_from · effective_to
```

Every proposal, extraction, prediction claim and advice record carries an FK to it. `docs/research/ai/`
**B-07 / AIR-08** already require prompt versioning stamped on every artefact; the substrate requirement
adds two things:

1. **Identity is a composite** — provider *and* model *and* prompt *and* policy. Recording the model
   alone is roughly 40% of the value, because a prompt change is the most frequent cause of a behaviour
   change.
2. **Identity must survive onto the posted artefact**, not only the proposal. Otherwise the posted record
   — the thing that matters in three years — is attributable only by joining through a proposal table
   that may have been pruned. Assert this in a test.

The window: **every entry posted before this exists is permanently unattributable.** Not
expensive-to-attribute. Unattributable.

### 7.2 Inference locus (I-32)

Where the inference physically happened — provider, region, whether it left the jurisdiction, whether the
tenant's data was in the context. For a GCC product selling to regulated buyers this is a question that
gets asked in procurement, and it is answerable for free if recorded at call time and unanswerable
otherwise. It is one row per invocation, attached to the same identity.

### 7.3 Blast radius (I-30) — the query the whole plane exists to make possible

Given a defect — a bad model version, a wrong rule, a mis-mapped counterparty — enumerate everything that
depends on it:

```
   DEFECT
     │  "machine_identity #114 mis-mapped counterparty X between 3 Mar and 19 Jun"
     ▼
   ┌─────────────────────────────────────────────────────────────────────┐
   │ 1. proposals with machine_identity = 114 in window        (identity) │
   │ 2. journal_entries posted from those proposals            (identity) │
   │ 3. ledger_entries written by those journals               (assertion)│
   │ 4. balances / reports derived from those ledger rows      (I-12)     │
   │ 5. claims whose basis referenced those balances           (claim)    │
   │ 6. filings / covenant certificates exported from those    (evidence) │
   └───────────────────────────┬─────────────────────────────────────────┘
                               ▼
            AMOUNTS, PERIODS, AND AN EXPLICIT COVERAGE STATEMENT
            ("complete for steps 1–4; step 5 covers 78% — 12 claims
             predate the claim store")
```

**The coverage statement is not optional.** An incomplete blast radius presented as complete is more
dangerous than no blast radius, because it produces false comfort at exactly the wrong moment — the same
failure IB-09 guards against for Band 1.

**Steps 1 and 2 are impossible without §7.1. Step 4 is impossible without I-12. Step 5 is impossible
without §6.** This is why the planes are one architecture rather than five features.

---

## 8 · Layer allocation — what runs where

| Plane / component | PostgreSQL | Laravel (trusted) | FastAPI (untrusted) | Next.js |
|---|---|---|---|---|
| Assertion — posting, immutability, RLS | **Owns** — triggers, grants, policies | Orchestrates via `PostingService` | **Never** | Renders |
| Authority — grants, scope evaluation | Stores; FK integrity | **Owns** evaluation | Reads its own scope *as input*, cannot alter it | Issues/revokes UI |
| Attention — estimator, scheduler, debt | Stores; append-only guarantees | **Owns** all three | **No participation** | Review UI + capacity screen |
| Claim — store, scorer, decay curve | Stores; `measure_on` index | **Owns** the scorer | Produces the *content* of a claim as a proposal | Renders bands + curves |
| Evidence — identity, locus, provenance, blast radius | Stores; immutability | **Owns** the walker | **Reports** its own identity per invocation | Renders |

**The single rule that governs the table:** *the AI engine may be the reason a row exists in any plane; it
may never be the writer of one.* That is `P15` restated at the substrate level, and it is why every
evaluator sits in Laravel or in the database rather than in the engine.

**A second rule that falls out and is easy to miss:** the engine **reports** its identity and locus but
does not **assert** them. A compromised or misconfigured engine could lie about its own model version, so
the identity row is written by the trusted caller from what it dispatched, not by the engine from what it
claims. This costs nothing and closes an otherwise elegant hole.

---

## 9 · Three sequence flows

### 9.1 Proposal → consequence → schedule → review → post

```
   FastAPI  ──proposal (typed DTO, P-12)──►  Laravel
                                              │ 1. resolve grant; stamp grant_id
                                              │    (a proposal outside its grant's scope
                                              │     is REJECTED here, not queued)
                                              │ 2. stamp machine_identity_id from what
                                              │    was dispatched (never from the reply)
                                              │ 3. consequence estimator → score + minutes
                                              │ 4. persist proposal (append-only)
                                              ▼
                                          SCHEDULER
                                     ┌────────┴────────┐
                              above  ▼                 ▼  below
                        review queue                review debt row
                              │                    (append-only decision)
                              ▼
                        human reviews on a form that
                        DISPLAYS THE CONSTRAINTS      ◄── IB-10 / IA-03
                              │
                    ┌─────────┴──────────┐
                    ▼                    ▼
              approve → PostingService   modify → correction captured
              (P7; trg_no_ai_autopost           (what changed, not that
               and IM-01's UPDATE guard          it changed — AIR-05)
               both apply)                             │
                    │                                  ▼
                    ▼                            correction corpus (I-09)
              ledger_entries                     = labels nobody else has
              + engagement telemetry event
```

Note what is *absent*: no confidence number reaches the reviewer (IA-10), no model participates in
ordering (IB-04), and the reviewer's queue position is explicable from stored arithmetic.

### 9.2 Prediction → claim → measurement → decay curve

```
   any predictive capability
        │ emits {value, range, band, basis, horizon}
        ▼
   Laravel writes a CLAIM row  ── NOT NULL measure_on ──► refused if absent
        │
        │  … time passes; the claim is immutable …
        ▼
   scheduled SCORER on measure_on index
        │ compares to realised value
        ▼
   outcome ∈ {correct, wrong, superseded, unmeasurable}   (immutable, written once)
        │
        ▼
   rollup by capability × band × horizon  ──►  DECAY CURVE (published, IB-07)
                                                "within 5% at 14d, 19% at 60d,
                                                 beyond 75d we stop claiming"
```

### 9.3 Defect → blast radius → restatement rehearsal

```
   defect identified (bad model version / wrong rule / mis-mapped counterparty)
        │
        ▼
   PROVENANCE WALKER (Laravel, batch, read-only)
        │ walks identity → proposals → journals → ledger → derived → claims → exports
        ▼
   BLAST RADIUS REPORT: amounts, periods, affected artefacts,
                        AND an explicit coverage statement
        │
        ├──► if material: reversal-based correction (P-13). Never mutation.
        │
        └──► REHEARSAL MODE (I-30): run the same walk against a synthetic defect,
             on a schedule, before it is needed — so the first time this runs is
             not the day it matters
```

The rehearsal is the part that distinguishes a capability from a claim. A blast-radius query that has
never been run is a design, not a control.

---

## 10 · The invariants this architecture adds

Stated in the form the existing knowledge base uses, so they can be promoted or refused as a unit:

| # | Invariant | Enforced by |
|---|---|---|
| **V-1** | No model writes a row in any plane | Database grants (`P15`); CI check that the engine holds no driver |
| **V-2** | Every plane evaluator is deterministic and versioned | Code review; `R-34`; the evaluator's version is stamped on its output |
| **V-3** | Every machine-originated artefact carries a machine identity | `NOT NULL` FK; a test asserting identity survives to the posted entry |
| **V-4** | Every emitted prediction carries a `measure_on` | `NOT NULL` |
| **V-5** | Every proposal carries a grant that was valid at proposal time | FK + evaluation at proposal time, stamped |
| **V-6** | Review debt is append-only and its rows are decisions, not flags | `REVOKE UPDATE, DELETE` on the runtime role — the `IM-03` pattern |
| **V-7** | The scheduler is the only consumer of proposals | Architectural; asserted by there being one queue table |
| **V-8** | A blast-radius result always carries a coverage statement | Return type; a report without coverage does not type-check |

**V-1, V-3 and V-4 are the three that cannot be added later** without leaving a permanent hole in the
record for every row written before them.

---

## 11 · What is deliberately not built

- **No agent framework, no agent org chart, no inter-agent messaging.** `ANTI_PATTERNS.md` **IA-07**;
  `docs/research/ai/` **A-02 / A-07**.
- **No model-composed SQL, ever** — not even read-only. `ANTI_PATTERNS.md` **IA-02**;
  `docs/research/ai/` **L-04**.
- **No write path from voice or chat.** `ANTI_PATTERNS.md` **IA-01 / IA-03**; `BEST_PRACTICES.md`
  **IB-10**.
- **No confidence-based authorisation, and no confidence displayed to a reviewer.** `R-32`;
  `ANTI_PATTERNS.md` **IA-10**.
- **No second ledger, no event-sourced rebuild as a primary read path.** `R-29`; the planes are
  *beside* the ledger, never a parallel truth.
- **No separate datastore per plane.** All five planes live in the primary PostgreSQL instance, because
  tenant isolation is RLS and a second store is a second implementation of isolation with a different
  failure mode — the same reasoning that settled the pgvector question in `docs/research/ai/` **AIR-07**.
- **No AI in any evaluator.** Stated three times in this document because it is the rule most likely to
  be eroded by a plausible-sounding optimisation.

---

## 12 · Open questions this document does not settle

1. **Where the consequence estimator's weights come from.** Published and customer-editable is decided;
   the *initial* values are a design-partner question and this document does not guess them.
2. **Whether engagement telemetry is politically viable.** `BEST_PRACTICES.md` **IB-16** states the
   framing that makes it possible; whether that framing survives contact with a real controller is
   untested and is the highest-value cheap experiment in the folder.
3. **Grant granularity.** Per capability, per capability × account class, or per capability × counterparty?
   Too fine and grants sprawl; too coarse and they are permissions. Undecided; should be settled by the
   second capability, not the first.
4. **Whether the claim plane should cover human predictions too.** A controller's own forecast is also a
   falsifiable claim, and storing it would make the model-vs-human comparison possible. Attractive,
   potentially inflammatory, out of scope here.
5. **The retention policy for engagement telemetry**, which is a legal question before it is an
   engineering one (`OVERVIEW.md` I-21 risk table).
6. **Whether `OVERVIEW.md` Part 8 is complete.** It develops I-21 and I-22 in full and names I-23…I-32 in
   its derivation table without expanding them. This document builds the architecture those ten imply; it
   does not substitute for their being written. See `README.md` §6.

---

## Cross-references

| Topic | Where it is settled |
|---|---|
| The AI engine's internal architecture, trust zones, injection defence | `docs/research/ai/ARCHITECTURE.md` |
| Why the engine holds no database driver | `docs/research/ai/` **L-04**, **AIR-02** |
| The proposal → human → Action mechanism and its invariants | `03_DESIGN_PATTERNS.md` **P-12** |
| Immutability and correction-by-reversal | `03_DESIGN_PATTERNS.md` **P-13**, `01_ENGINEERING_PRINCIPLES.md` **P6** |
| The four AI-specific architectural refusals | `04_REJECTED_PATTERNS.md` **R-31…R-34** |
| The twenty prior inventions | `07_QAYD_INNOVATION.md` **I-01…I-20** |
| The category thesis and the three scarce resources | `docs/research/innovation/OVERVIEW.md` |
| Analytical / reporting tier and partitioning | `docs/research/analytics/`, `05_FUTURE_ARCHITECTURE.md` |

# End of Document
