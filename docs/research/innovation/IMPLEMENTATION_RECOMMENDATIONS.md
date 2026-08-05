# IMPLEMENTATION_RECOMMENDATIONS — Eighteen items, sequenced

**IR-01…IR-18, bound to real stories and proposed for intake · `docs/research/innovation/`**

Version 1.0 · 2026-07-28 · **Documentation only** — no application code, schema, migration or test was
modified in producing this.

---

## How to read this

Nothing here is a parallel roadmap. `08_MASTER_BACKLOG.md` owns sequencing, and its intake rule is
unchanged:

> *No idea enters QAYD's plan without a tier, a value, a dependency list, and a named sprint — or an
> explicit rejection with a reason.*

Most items below are **acceptance criteria and design constraints on stories that already exist**. Where
an item is genuinely new, it is marked **NEW — for triage** and carries everything the intake rule
requires.

**Effort** is Fibonacci. **Value** uses the master backlog's scale (Critical / High / Medium / Low).
**Confidence** is High / Medium / Low with a reason. Every item states its scalability, performance,
maintainability and complexity profile, its business impact, and its evidence grade.

**⏳ marks a closing window** — an item whose cost is small now and *infinite* later, because the data it
captures cannot be backfilled.

---

## The one-page answer

If only six things from this document happen, these six. Four of them are ⏳.

| # | Item | Effort | Why it cannot wait |
|---|---|---|---|
| 1 | **IR-05** — "ships its recording layer in the same story" in the definition of done | **0** | It is the rule that stops the other three ⏳ items from losing every sprint argument |
| 2 | **IR-02** ⏳ — machine identity stamped on every machine-originated artefact | 3 | Every entry posted before it exists is **permanently unattributable**. Blast radius (I-30) becomes unbuildable |
| 3 | **IR-01** — substrate/application tag on the intake rule | 1 | Without it the roadmap is a list, and the list is won on distribution QAYD does not have |
| 4 | **IR-03** ⏳ — prediction claim store with a mandatory measurement date | 8 | Shared by three capabilities; the decay curve cannot be reconstructed from anything |
| 5 | **IR-08** — one proposal queue, not one per capability | 3 now | 3 points before the second capability; ~21 plus a UI rewrite after the sixth |
| 6 | **IR-17** — correct the stale 66.0% anchor in `07` §5.2, in place | 1 | It is the number people quote, and it is falsifiable by anyone opening a web page |

**16 points of build, two rules, one doc fix.** Everything else can slip a sprint. These cannot, because
each either destroys an asset by waiting or costs an order of magnitude more later.

**One item outside this list gates everything:** `08_MASTER_BACKLOG.md` **IM-01**. See IR-00.

---

## Index

| ID | Recommendation | Story | Effort | Value | Pri | ⏳ |
|---|---|---|---|---|---|---|
| **IR-00** | Reframe IM-01's value: it gates the entire substrate | IM-01 | 0 | Critical | P0 | |
| **IR-01** | Substrate/application tag on the intake rule | `08` §0 | 1 | High | P0 | |
| **IR-02** | Machine identity registry, stamped and surviving to the posted entry | S3-08, S4-02 | 3 | Critical | P0 | ⏳ |
| **IR-03** | Prediction claim store, `measure_on` NOT NULL | new, pre-I-19 | 8 | High | P0 | ⏳ |
| **IR-04** | Engagement telemetry from the first proposal surface | S4-04 | 8 | High | P1 | ⏳ |
| **IR-05** | "Ships its recording layer in the same story" in the DoD | `08` §0 | 0 | Critical | P0 | |
| **IR-06** | Start the audit hash chain into the dormant columns | S2-xx / IM-02 follow-on | 5 | High | P1 | ⏳ |
| **IR-07** | Consequence estimator — deterministic, published, versioned | S4-01 | 5 | High | P1 | |
| **IR-08** | One proposal queue, not one per capability | S3-09, S4-01 | 3 | High | P0 | |
| **IR-09** | Review debt: append-only, ages, blocks close above threshold | S3-06, S4-04 | 5 | High | P2 | |
| **IR-10** | Capability grants replace the implicit role check | S4-01 | 13 | High | P2 | |
| **IR-11** | No confidence value rendered to a reviewer | S4-04 | 1 | High | P1 | |
| **IR-12** | Proposals reviewed on a constraint-displaying pre-filled form | S4-04 | 3 | High | P1 | |
| **IR-13** | Explicit refusal outcome + published coverage on NL surfaces | I-16, S4-10 | 3 | High | P1 | |
| **IR-14** | Voice: read path and closed vocabulary only — **decision** | roadmap | 1 / 8 | Medium | P2 | |
| **IR-15** | Blast-radius walker + scheduled rehearsal | new, post-S4 | 13 | High | P3 | |
| **IR-16** | Obligation record first, cashflow bands second | I-19 / I-22 | 21 + 5 | Medium | P3 | |
| **IR-17** | Correct `07` §5.2's benchmark anchor + annual sweep | doc fix | 1 | Medium | P1 | |
| **IR-18** | Three-conversation buyer test before building I-08's bundle | pre-I-08 | 2 | High | P2 | |

---

# TIER A — Rules and closing windows

These are the items whose cost rises to infinite, plus the two rules that protect them. Nothing here is
large.

## IR-00 — Reframe IM-01's value: it gates the entire substrate

**Story: IM-01** (already Critical / P0 / complexity 2). **This is a framing change, not a scope change.**

**What.** `08_MASTER_BACKLOG.md` IM-01 records that `trg_no_ai_autopost` is `BEFORE INSERT` only, so it
blocks *creating* a non-draft AI entry but not *updating* an AI draft into a posted state — application
code alone stands in the way. Its value line should name what it gates.

**Why now.** Every capability in `OVERVIEW.md` Part 8 and every plane in `ARCHITECTURE.md` assumes the
posting boundary holds. Under the thesis, that trigger is not a guardrail on a feature; it is the
protected-mode boundary that makes untrusted proposals safe to generate at all. A two-point fix gating a
twelve-capability strategy should be described that way, so that nobody re-prioritises it on the basis of
its size.

**Acceptance criteria.** None new. IM-01's existing fix stands: a `BEFORE UPDATE` trigger requiring
`approved_by IS NOT NULL` for any transition of an `ai_generated` row into a posted state.

**Effort: 0 (wording) · Value: Critical · Priority: P0 · Confidence: High.**
**Evidence:** `[CODE]` per IM-01's own verification. → `LESSONS_FOR_QAYD.md` **IL-02**.

---

## IR-01 — Substrate/application tag on the intake rule

**Story: `08_MASTER_BACKLOG.md` §0.** **NEW — for triage.**

**What.** Every backlog item is tagged **substrate** (increases what can be *proved*) or **application**
(increases what can be *done*), with a one-sentence justification. Applications are built to a "good
enough" bar and are explicitly permitted to be worse than a competitor's.

**Why now.** `OVERVIEW.md` §0 identifies the failure of a category with no definition: the roadmap becomes
a list, and a list is won on distribution QAYD cannot win. The tag is the mechanism that turns
`OVERVIEW.md` **C1** from a paragraph into a decision that gets made.

**Acceptance criteria.** The field exists on intake · the justification is a sentence about *proof*, not
about value · at least one item is tagged application and consciously de-scoped, so the rule is
demonstrated rather than decorative.

**Tradeoffs.** You will lose a deal to a better anomaly detector, and the correct response is to record
it, not to reverse the rule.
**Risks.** Everything gets labelled substrate (mitigate: the justification requirement) · a genuinely
strategic application is under-built (mitigate: the tag is a default, overridable in writing).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| n/a — process | n/a | Trivial | Trivial |

**Effort: 1 · Value: High · Priority: P0 · Business impact: High — it is the only mechanism that makes
"no" repeatable · Confidence: High.** **Evidence:** `[INFERENCE]` from `OVERVIEW.md` §0/§4.

---

## IR-02 — Machine identity registry, stamped and surviving to the posted entry ⏳

**Stories: S3-08** (first AI suggestion path), **S4-02** (extraction).

**What.** An immutable `machine_identities` table keyed on the composite *(provider, model, model
version, prompt version, policy version, capability)*, with a `NOT NULL` FK from every proposal,
extraction, prediction claim and advice record — **and surviving onto the posted journal entry**, not only
the proposal.

**Why now ⏳.** `journal_entries` today carries `ai_generated` as a boolean
`[CODE: …2026_07_28_000004_create_journal_entries_table.php:58]`. That answers *whether* a machine was
involved and nothing else. **Every entry posted before the registry exists is permanently
unattributable** — not expensive to attribute, unattributable — and I-30 (blast radius) is unbuildable
without it. `docs/research/ai/` **B-07 / AIR-08** already require prompt versioning on artefacts; this
extends the requirement to the *posted* record and makes identity composite.

**Acceptance criteria.**
- Identity is written by the **trusted caller** from what it dispatched, never from what the engine
  reports about itself.
- A test asserts that a posted entry originating from an AI proposal retains its identity FK after the
  proposal table is pruned.
- CI asserts all five identity components are populated; a null prompt version fails the build.
- Rows are immutable; a new version is a new row.

**Tradeoffs.** One extra join and one small dimension table. Trivial against the alternative.
**Risks.** Model recorded but prompt and policy omitted — roughly 40% of the value lost, and silently
(mitigate: the CI assertion above) · identity lost at the proposal→posting handoff (mitigate: the test
above).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Slow-growing dimension table | One FK; negligible | High | **Low** |

**Effort: 3 · Value: Critical · Priority: P0 · Business impact: High — it is the precondition for the
capability that answers the buyer's real objection · Confidence: High.**
**Evidence:** `[CODE]` for the before-state; `[INFERENCE]` for the design.
→ `ARCHITECTURE.md` §7.1, `BEST_PRACTICES.md` **IB-08**.

---

## IR-03 — Prediction claim store, `measure_on` NOT NULL ⏳

**NEW — for triage. Must land before the first predictive surface (I-19 / I-22 / I-23 / I-26).**

**What.** One append-only `claims` table: capability, subject, band, value, range, basis,
`machine_identity_id`, `grant_id`, `made_at`, **`measure_on NOT NULL`**, plus an immutable outcome written
once by a scheduled scorer. Outcomes are `correct | wrong | superseded | unmeasurable`.

**Why now ⏳.** `OVERVIEW.md` **C4**: a prediction is an assertion about the future by a system whose
entire pitch is that assertions are recorded. Unrecorded predictions cannot be reconstructed, and the
**decay curve** — *"within 5% at 14 days, 19% at 60 days, beyond 75 days we stop claiming"* — is the only
artefact in this family a competitor starting later cannot copy. Built once, it serves three capabilities;
built three times, it is three schemas that do not agree.

**Acceptance criteria.**
- `measure_on` is `NOT NULL`; a prediction that cannot be scored cannot be stored, and therefore cannot
  be emitted.
- `superseded` is a distinct outcome from `wrong`, because a forecast about a cancelled contract was not
  wrong.
- The scorer is deterministic and contains no model (`R-34`).
- Outcomes are written once and are immutable.
- A rollup produces the per-capability × band × horizon decay curve.

**Tradeoffs.** Every prediction surface acquires a schema obligation. The correct response to a
low-consequence surface finding it heavy is to emit fewer predictions, not to skip the store.
**Risks.** Ambiguous scoring where the subject changed (mitigate: store the basis; `superseded`) · the hit
rate is bad and someone wants it hidden (mitigate: IR-13's publication discipline, decided in advance).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Append-only; volume ∝ emissions, i.e. small | Scoring is a scheduled job on a date index | High — one table, one job, three consumers | **Low-Medium** |

**Effort: 8, shared across three capabilities · Value: High · Priority: P0 · Business impact: High ·
Confidence: High.** **Evidence:** `[INFERENCE]` from C4; forecast-vs-actual tracking is standard FP&A
practice `[COMMUNITY]` and must not be claimed as an invention.
→ `ARCHITECTURE.md` §6.

---

## IR-04 — Engagement telemetry from the first proposal surface ⏳

**Story: S4-04** (approval / review UI).

**What.** Record, per review event: dwell time, whether the item was modified before approval, and a
blind-sampling loop that re-presents a small random **unlabelled** fraction of already-approved items.
Divergence between first and second judgement is the measured rubber-stamp rate.

**Why now ⏳.** Reviewer capacity cannot be inferred retrospectively from approval timestamps. This is
`docs/research/ai/` **B-12 / AIR-14** promoted from an engineering practice to the calibration input for
the attention plane. The problem it measures is documented, not speculative: a 2025 systematic review of
35 peer-reviewed studies found **agreement with incorrect AI recommendations is the most consistent
behavioural outcome** in human–AI decision support `[INDEPENDENT]`, and 2026 practitioner writing states
that **the cleaner and faster the interface, the more likely the human approves without thinking**
`[COMMUNITY]`.

**Acceptance criteria.**
- Blind sampling draws from **below** the attention line as well as above, or the estimator's own errors
  stay invisible.
- Aggregation is by role and team **by default**; individual telemetry is opt-in and visible to the
  individual first, and is never exposed to a manager as a performance metric.
- Capacity is derived from median dwell on items the reviewer later *modified* — the only observable
  proxy for genuine engagement — not from self-report.

**Tradeoffs.** Aggregation costs calibration precision. Accepted: a noisier number that ships beats a
precise one that gets switched off.
**Risks.** Perceived as surveillance and defeated (**High** — mitigate with `BEST_PRACTICES.md` **IB-16**:
frame as *capacity*, never *diligence*) · the measured rubber-stamp rate is discoverable in litigation
(**High and under-appreciated** — the alternative is not *no evidence*, it is *no knowledge*; take legal
advice before setting retention).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Append-only, volume ∝ review events | Negligible on the request path | Medium — calibration needs tuning | **Medium**; the hard part is political |

**Effort: 8 · Value: High · Priority: P1 · Business impact: High · Confidence: High** that the problem is
real; **Low** on political viability without a design partner — **test with one controller before building
past IR-07.** **Evidence:** `[INDEPENDENT]` `[COMMUNITY]`.

---

## IR-05 — "Ships its recording layer in the same story" in the definition of done

**Story: `08_MASTER_BACKLOG.md` §0.** **NEW — for triage. Effort 0.**

**What.** One line in the intake rule:

> *A story that generates a permanent record ships its recording layer in the same story, or it does not
> ship.*

**Why now.** IR-02, IR-03, IR-04 and IR-06 will lose every sprint prioritisation on their own merits,
because their value is entirely in the future and their demo value is zero. That is not a scheduling
problem to be solved by advocacy; it is a definition-of-done problem. This rule is the single
highest-leverage sentence in this document and it costs nothing.

**Acceptance criteria.** The rule is in `08` §0 · waivers are recorded in the backlog with a date and a
person, so the cost is visible later — the same discipline the product sells.

**Effort: 0 · Value: Critical · Priority: P0 · Business impact: Critical · Confidence: High** — the
irreversibility it protects against is a logical property, not an empirical claim.
→ `ANTI_PATTERNS.md` **IA-14**, `BEST_PRACTICES.md` **IB-13**.

---

## IR-06 — Start the audit hash chain into the dormant columns ⏳

**Story: follow-on to IM-02** (the `audit_logs` platform-admin bypass), which must land first.

**What.** Begin populating the `hash` / `prev_hash CHAR(64)` columns that already exist on `audit_logs`
`[CODE: …2026_07_27_000010_create_audit_logs_table.php:84-85]`, reserved for the per-company chain.

**Why now ⏳.** `07` §4 grades anchored history (I-08) as the single most durable moat in the document, on
one basis: **a competitor shipping the same chain in 2029 starts their chain in 2029.** The columns exist
and cost nothing; the chain's value is a pure function of its age. Every month of delay is a month
permanently missing from the strongest asset in the strategy.

**Ordering matters and is not optional.** IM-02 records that `audit_logs` carries a platform-admin bypass
inside its RESTRICTIVE boundary in both `USING` and `WITH CHECK`, so a privileged session can author audit
rows attributed to any tenant. **Chaining forgeable rows produces a chain of forgeable rows.** IM-02 first,
then this.

**Acceptance criteria.** Chain is per-company · the chain-verification job is a scheduled integrity check
that *detects and waits* rather than repairing (I-18) · a break is an alert and a blocked close, not a
log line.

**Tradeoffs.** A hash computation on the audit write path. Small, and the write path is already
asynchronous per `audit_logs`' design intent.
**Risks.** A hash chain is treated as the whole integrity strategy (mitigate: `docs/research/banking/`
**BA-09** already refuses that framing — it is tamper-*evidence*, not tamper-*proofing*) · chain breaks
from legitimate operational events with no runbook (mitigate: write the runbook with the job).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Per-company chains partition naturally | One hash per audit row | Medium | **Medium** |

**Effort: 5 · Value: High · Priority: P1 · Business impact: High · Confidence: High** on the value,
**Medium** on the operational cost of chain breaks. **Evidence:** `[CODE]` for the dormant columns;
`07` §4 for the moat grading.

---

# TIER B — The substrate planes

These are larger and can be sequenced across Sprint 4 and beyond — with one exception (IR-08) that is
cheap now and expensive later.

## IR-08 — One proposal queue, not one per capability

**Stories: S3-09, S4-01.**

**What.** Proposals from every capability land in one store with one shape (`P-12`), and there is exactly
one consumer of that store — the scheduler. Capabilities do not own queues.

**Why now.** This is the cheapest structural decision in the folder and the most expensive to reverse.
Three points before the second capability ships; roughly 21 points plus a UI rewrite plus a data migration
after the sixth. And if capabilities own queues, **their sum is never computed**, which makes
`OVERVIEW.md` §3.2's failure — safety degrading as a function of product success — unobservable by
construction. You cannot schedule a resource whose total demand you never see.

**Acceptance criteria.** One table · a test asserting no capability writes a queue of its own · the review
UI reads only the scheduler's ordering.

**Tradeoffs.** Capability teams lose the ability to tune their own queue UX. Intended.
**Risks.** A capability with genuinely different review ergonomics gets a "temporary" second queue
(mitigate: differences belong in the *rendering*, never in the store).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| One partitionable table beats six | One index instead of six | High | **Low** |

**Effort: 3 · Value: High · Priority: P0 · Business impact: High · Confidence: High.**
→ `ARCHITECTURE.md` §5.2.

---

## IR-07 — Consequence estimator: deterministic, published, versioned

**Story: S4-01** (`AutonomyResolver` — design alongside, not after).

**What.** A pure function scoring each proposal in **expected consequence**: amount, account sensitivity,
period proximity, counterparty novelty, whether the entry feeds a filing or covenant, reversibility,
precedent. Score and estimated review-minutes are stored on the proposal at creation.

**Why now.** Something must order the queue. The tempting orderer is model confidence, which is `R-32`
re-implemented at the product layer (`ANTI_PATTERNS.md` **IA-10 / IA-11**). This is the alternative, it
uses only data QAYD already holds, and **it is independently useful before any scheduler exists** — the
score alone improves any queue, which makes it the correct first slice of the attention plane.

**Acceptance criteria.** No model anywhere in the estimator (`R-34`) · weights are data, versioned like
any policy (`P18`), and customer-editable · the estimator's version is stamped on the score, so a
re-ordering is explicable afterwards.

**Tradeoffs.** Deterministic scoring will sometimes be wrong where a model would have been right.
Accepted: wrong-but-inspectable is recoverable, wrong-and-opaque is not.
**Risks.** **Critical** — a wrong estimator hides the item that mattered (mitigate: blind sampling from
below the line, IR-04) · scope creep into ML (mitigate: refuse on `R-34`; this is the single most likely
place in the architecture for that erosion to start).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Pure function | Microseconds; computed once and stored | High — the easiest thing here to test | **Low** |

**Effort: 5 · Value: High · Priority: P1 · Business impact: High · Confidence: High.**
**Evidence:** `[INFERENCE]`, borrowing risk-based sampling from audit practice.

---

## IR-09 — Review debt: append-only, ages, blocks close above threshold

**Stories: S3-06** (period close), **S4-04**.

**What.** Every decision *not to show* a proposal is an append-only row carrying the item, its consequence
score, the capacity policy version that excluded it, and its age. Reported as a balance (*"KWD 41,200
across 88 items, oldest 19 days"*). Above a threshold it **blocks a period close**.

**Why now.** Truncation is unavoidable and correct; undisclosed truncation is the anti-pattern the whole
market has shipped. Blocking rather than warning is I-18's detect-and-wait principle: a warning nobody
acts on is a warning nobody reads.

**Acceptance criteria.** Append-only — `REVOKE UPDATE, DELETE` on the runtime role, following the IM-03
pattern · debt is a record of *decisions*, never a mutable flag · the close gate is configurable but
cannot be set to infinity · debt feeds I-04 as its own assurance band.

**Tradeoffs.** Customers will not enjoy a number saying their books are less reviewed than they thought.
Some will ask to disable it — which is the cheapest possible test of whether IR-18's buyer thesis is real.
**Risks.** It becomes a number nobody looks at (mitigate: it blocks close) · implemented as a flag,
destroying evidentiary value (mitigate: the grant revocation above).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Append-only, partitions like `audit_logs` | Periodic rollup, not a live query | Good | **Low-Medium** |

**Effort: 5 · Value: High · Priority: P2 · Business impact: High · Confidence: Medium** — mechanism
sound, customer tolerance untested. **Evidence:** `[INFERENCE]`; adjacent prior art in SOC alert triage
and audit sampling `[COMMUNITY]`.

---

## IR-10 — Capability grants replace the implicit role check

**Story: S4-01** — design alongside `AutonomyResolver`. **NEW — for triage.**

**What.** `capability_grants` as append-only data: capability, grantor, scope predicate (account class,
amount ceiling, period, counterparty set, document type), autonomy mode, reversibility budget (I-17
denominated per grant), **mandatory** expiry, and revocation as an event rather than a delete. Every
proposal carries the grant that was valid **at proposal time**.

**Why now.** Authority today is binary and implicit. Every autonomy conversation needs a sentence the
current model cannot express, and in its absence the vocabulary that fills the vacuum is the agent org
chart, which is unenforceable (`ANTI_PATTERNS.md` **IA-07**).

**Acceptance criteria.** Scope is data, never code (`P18`, `R-33`) · expiry is `NOT NULL` and renewal is an
audited act · evaluation happens at proposal time and is stamped, so revocation does not retroactively
invalidate reviewed work · a proposal outside its grant's scope is **rejected at intake, not queued**.

**Tradeoffs.** A real authorisation subsystem is more work than a role check, and version one will feel
over-built for two capabilities. The payoff arrives at capability five and at the first enterprise
security review.
**Risks.** Grant sprawl (mitigate: templated per capability, per-tenant parameters only) · a grant becomes
standing authorisation because it is never allowed to lapse (mitigate: mandatory expiry) · complexity
leaks into the posting hot path (mitigate: evaluate at proposal time over already-loaded rows).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Small, per-tenant, cacheable | O(1) evaluation; no added query on the posting path | Good — data-driven, testable in isolation (`P16`) | **Medium-High** |

**Effort: 13 · Value: High · Priority: P2 · Business impact: High · Confidence: Medium** — the shape is
borrowed from capability-security systems where it is well proven; its fit to an accounting product is
reasoning, not observation. → `ARCHITECTURE.md` §4.

---

# TIER C — Capability-level constraints

Small items, all of them acceptance criteria on stories that already exist. Together they are 10 points
and they decide whether the product's failures are visible.

## IR-11 — No confidence value rendered to a reviewer

**Story: S4-04.**

**What.** One acceptance criterion: no model-reported confidence appears in any reviewer-facing surface.

**Why.** `R-32` forbids confidence from authorising; `docs/research/ai/` **A-04** forbids trusting it.
Displaying it is the version that will be proposed next week in good faith as transparency, and it fails
three ways: it is an uncalibrated self-report; it *becomes* the review, converting judgement into
threshold-application; and it directly amplifies the automation bias measured across 35 studies
`[INDEPENDENT]`.

**Instead.** Show the evidence — *"matched to INV-2291 because amount, date and reference agree"* — which
is reviewable in a way `87%` is not. Measure calibration internally for routing only
(`docs/research/ai/` **B-11 / AIR-15**).

**Effort: 1 · Value: High · Priority: P1 · Business impact: High · Confidence: High.**
| Scalability | Performance | Maintainability | Complexity | — all trivial; this is a subtraction. |

---

## IR-12 — Proposals are reviewed on a constraint-displaying pre-filled form

**Story: S4-04.**

**What.** A machine proposal is presented as a **pre-filled form with every constraint still rendered** —
chart of accounts, open periods, tax codes, approval state, currency, mandatory dimensions — with
model-supplied values visually distinguished from human-supplied ones.

**Why.** In finance the form is not an input mechanism; it is a **constraint display**. It is where the
user notices they are about to do something that does not fit. Replacing it with a summary card or a chat
confirmation removes the last error-detection surface at the moment of commitment
(`ANTI_PATTERNS.md` **IA-03**, `LESSONS_FOR_QAYD.md` **IL-16**).

**Acceptance criteria.** Constraints are rendered, not summarised · model-supplied fields are visually
marked · a modification is captured as *what changed*, not *that it changed*
(`docs/research/ai/` **AIR-05**) — this is the correction corpus's raw material and it is free here.

**Tradeoffs.** More friction than a one-tap approve, which is the point.
**Risks.** The distinction between machine- and human-supplied values is styled so subtly that nobody
notices (mitigate: test it with a real reviewer, not a designer).

**Effort: 3 · Value: High · Priority: P1 · Business impact: High · Confidence: High** on the mechanism,
**Medium** on how much friction to retain — a design-partner question.

---

## IR-13 — Explicit refusal outcome and published coverage on natural-language surfaces

**Stories: I-16, S4-10** (Copilot).

**What.** Every model-mediated query surface has a first-class *cannot answer* outcome, rendered as a
normal non-apologetic result, and a **published coverage rate**.

**Why.** The strongest available evidence in this space is about failure *shape*, not failure rate:
semantic-layer failures are refusals; raw text-to-SQL failures are confident wrong numbers — Cube's
"silent hallucination" `[DOCS]`. The accuracy evidence is equally direct: **BEAVER, built from real
enterprise warehouses, measures GPT-4o at close to 0% end-to-end**, against a BIRD human baseline of
92.96% and top systems around 82% on curated schemas `[PAPER]`; LogicCat puts SOTA at **at most 33.20%**
on complex reasoning queries `[PAPER]`. A general ledger is the BEAVER kind of schema, not the BIRD kind.

**Acceptance criteria.** Refusal is a typed outcome, not an error · the refusal log is retained as the
highest-quality feature-request queue in the product · coverage is a published metric with a target ·
**no model-composed SQL against tenant data, ever** (`docs/research/ai/` **L-04**).

**Tradeoffs.** Early coverage will be low and a demo will hit a refusal. Rehearse the answer.
**Risks.** Refusal as a crutch for a lazy semantic model (mitigate: coverage target) · over-refusal
(mitigate: sample refusals weekly).

**Effort: 3 · Value: High · Priority: P1 · Business impact: High · Confidence: High.**

---

## IR-14 — Voice: read path and closed vocabulary only — **a decision, not code**

**Story: roadmap-level.** **Decision effort 1; the narrow build is 8 if pursued.**

**What.** Record the decision: **voice is permitted on the read path and refused on the write path.** The
only write-adjacent form permitted is a closed-vocabulary command set where an unrecognised utterance is a
refusal, never a best guess.

**Why.** The evidence is the strongest in the folder and is set out in `ANTI_PATTERNS.md` **IA-01**: best
measured Khaliji Arabic WER **48.23%**; Whisper **59.92% Khaliji vs 27.95% MSA** `[PAPER]`; code-switched
segments at **121.78% WER against 12.06%** on English segments of the same recordings `[PAPER]`; a silent
failure mode in which the model fluently translates dialect into MSA rather than transcribing; and a
metric that normalises numerals away before scoring. Both shipped implementations — Zoho Books and
QuickBooks — stopped at human confirmation `[DOCS]`.

**Acceptance criteria if the narrow version is built.** Intents are enumerable and closed · entities
resolve against existing records or the command is refused · scored on **entity-level accuracy** (amount,
counterparty, date, account) on QAYD's own audio, disaggregated by dialect and code-switching, with
numerals **not** normalised — never on WER.

**Tradeoffs.** Loses the most photogenic demo in the category. The counter-position — *"we will not let a
machine commit something you did not see the rules for"* — is better in a finance sale.
**Risks.** The decision is not written down and is re-litigated every quarter (mitigate: write it into the
concept mapping in `README.md` §3 with the evidence attached).

**Effort: 1 to decide · Value: Medium · Priority: P2 · Business impact: Medium (avoided cost is the real
value) · Confidence: High.**

---

# TIER D — Later, larger, or contingent

## IR-15 — Blast-radius walker and scheduled rehearsal

**NEW — for triage. Post-S4. Depends on IR-02 and I-12.**

**What.** Given a defect — bad model version, wrong rule, mis-mapped counterparty — walk identity →
proposals → journals → ledger → derived balances → claims → exports, and produce amounts, periods and an
**explicit coverage statement**. Rehearse it on a schedule against a synthetic defect (I-30).

**Why.** `07` §5.2 lists four mitigations for the confidently-wrong-number scenario; all four *bound*
error and **none responds to one**. The canonical case is `07`'s own: a vendor misclassified for eleven
months, inside every tolerance, approved forty-four times. Only the walk catches that.

**Acceptance criteria.** The result type cannot be constructed without a coverage statement · correction
is by reversal, never mutation (`P-13`) · the rehearsal runs on a schedule, because a query that has never
been run is a design, not a control.

**Tradeoffs.** Substantial engineering whose value is invisible until the day it is enormous — the classic
casualty of prioritisation, which is why IR-01's tag exists.
**Risks.** Partial coverage presented as complete produces false comfort at the worst moment (mitigate:
the coverage statement is structural, not a footnote) · slow on large tenants (mitigate: it is a batch
analysis, and minutes are fine).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Batch; scales with history depth | Minutes, not milliseconds | Medium; couples to provenance schema | **High** |

**Effort: 13 · Value: High · Priority: P3 · Business impact: High — it is the demonstrated answer to the
buyer's actual objection · Confidence: Medium** — clearly right; cost depends on how completely IR-02 and
I-12 were done.

---

## IR-16 — Obligation record first, cashflow bands second

**Stories: I-19 → I-22.**

**What.** Build the **obligation record** (contracts, schedules, standing commitments) as the primary
deliverable; the banded cashflow view is its first consumer, not the goal.

**Why.** `07` §5.1 already concludes that a rigorous provisional ledger may be no better than an AR/AP
ageing projection every competitor ships free, and that the predicted-vs-actual measurement is the most
valuable and cheapest part. The obligation record is the piece with downstream value: I-25 (tax positions),
I-26 (solvency tripwire) and I-30 (blast radius) all need it. The forecast is the demo; the record is the
asset.

**Acceptance criteria.** Band 1 carries a **completeness indicator** — *"derived from 23 recorded
obligations; 4 known documents unparsed"* — because a false certainty is worse than an honest guess ·
Band 2 is deterministic (a per-counterparty payment-lag median is a rule, `R-34`) · Band 3 is a range,
never a point, and **auto-disables** if a naive seasonal baseline beats it for three consecutive months,
telling the customer · no single cross-band total is rendered by default.

**Tradeoffs.** Users will ask for one number. If one is exported, it carries the range.
**Risks.** Obligation extraction is a data-capture problem, not a model problem, and this class of work
reliably goes worse than planned — `07` §5.5 #3 already warns these estimates are optimistic.

**Effort: 21 (obligation record) + 5 (banding); claim storage is IR-03 and is not counted twice · Value:
Medium · Priority: P3 · Business impact: Medium — defensive; its absence loses deals and its presence
wins none · Confidence: High** on the epistemics, **Low** on the extraction estimate.

---

## IR-17 — Correct `07` §5.2's benchmark anchor, in place, and add an annual sweep

**Doc fix.**

**What.** Edit `07_QAYD_INNOVATION.md` §5.2 in place, with today's date: the DualEntry Labs top score of
**66.0%** is Round 1 and stale; the same public leaderboard shows **Round 2 at 77.3%** and a current top of
**83.2%** across 31 models from 8 providers `[DOCS: dualentry.com/accounting-ai-benchmark]`. Re-anchor the
argument on the **production gap** — roughly **92% GAAP/IFRS recall against 30–40% journal-entry
creation** `[DOCS]` — which survives the benchmark's published rebuttal and does not go stale as models
improve. Add a standing annual task to re-derive every threshold traceable to an external benchmark.

**Why now.** `07` is the document people quote. Anyone citing 66% externally will be corrected by whoever
opens the page. And the benchmark is vendor-run, with a published rebuttal arguing it tests a bare model
with no surrounding system and **scores an honest "I don't know, please review" as a failure**
`[INDEPENDENT]` — which for a product whose entire design *is* the surrounding system is close to
measuring the inverse of what matters.

**Acceptance criteria.** Corrected in place with the date, not appended · the sweep's output is a written
decision per threshold: *changed* or *confirmed, with reason*.

**Effort: 1 · Value: Medium · Priority: P1 · Business impact: Medium — credibility · Confidence: High.**

---

## IR-18 — Three conversations before building I-08's bundle

**Pre-I-08. A test, not a build.**

**What.** One conversation each with an **auditee**, a **lender** and an **acquirer** about whether a
verifiable-record bundle changes what they pay or how fast they move — **before** committing to I-08's
full build.

**Why.** `07` §4 grades I-08 as the strongest moat in the document and routes it through the audit firm,
then flags that as its own weakest claim: verification labour is **billable** and the firm's incentive
runs the other way (§5.5 #1). `OVERVIEW.md` records the alternative as prediction **P3** with a named
falsification. Two points of effort against a ~21-point build is the best expected-value trade in this
document.

**Acceptance criteria.** Each conversation asks the same question and the answers are written down ·
the outcome is recorded as confirming or falsifying P3, either way · `07` §5.5 #1 is updated in place with
the result.

**Risks.** Three conversations is a small sample and will not be conclusive (accepted — it is a
disqualifier test, not a validation) · the wrong three people are asked (mitigate: an auditee who has
recently *paid* an audit fee, not one who has recently *passed* an audit).

**Effort: 2 · Value: High · Priority: P2 · Business impact: High · Confidence: Low** — this is explicitly
an untested prediction. **Evidence:** `[INFERENCE]`.

---

## Sequencing view

```
   NOW ──────────────────────────────────────────────────────────────────────►

   IM-01 (gates everything)  ──►  IR-00 reframe
        │
   IR-01 tag ── IR-05 DoD rule ────────────────────────► protect the ⏳ items below
        │
        ├─ IR-02 machine identity ⏳ ──┬──────────────────────► IR-15 blast radius
        │   (S3-08 / S4-02, 3 pts)     │                        (13, post-S4)
        │                              │
        ├─ IR-03 claim store ⏳ ────────┼──► IR-16 obligation record + bands
        │   (8, pre-I-19)              │     (21+5, P3)
        │                              │
        ├─ IR-08 one queue (3) ────────┼──► IR-07 estimator (5) ──► IR-09 debt (5)
        │                              │                              │
        ├─ IR-04 telemetry ⏳ (8) ──────┘                              ▼
        │   (S4-04)                                          IR-10 grants (13)
        │
        ├─ IR-11 (1) · IR-12 (3) · IR-13 (3)  ── S4-04 / S4-10 acceptance criteria
        │
        ├─ IR-06 hash chain ⏳ (5, after IM-02)
        │
        └─ IR-14 voice decision (1) · IR-17 doc fix (1) · IR-18 three conversations (2)

   Tier A total: 25 points + 2 rules.  Tier B: 26.  Tier C: 8.  Tier D: 39 + 3.
```

---

## What is explicitly **not** recommended

Recorded so the absence is a decision rather than an oversight, as the intake rule requires:

| Not recommended | Reason |
|---|---|
| Voice-to-posted-entry, in any form | `ANTI_PATTERNS.md` **IA-01** — measured, not preferred |
| Model-composed SQL against tenant data, even read-only | **IA-02**; `docs/research/ai/` **L-04** |
| Chat as a commitment surface | **IA-03**; `LESSONS_FOR_QAYD.md` **IL-16** |
| An autonomous close, or any "autonomous" positioning | **IA-04**; `07` §5.4 |
| AI performing the assurance function | **IA-05** — the circularity is structural, not a capability gap |
| An advisory / AI-CFO surface without the claim store | **IA-06**; permitted *with* IR-03 |
| AI-proposed tax positions framed as savings | **IA-08** — optimisation selects for the disputed direction |
| Concurrent multi-agent architectures | **IA-07**; `docs/research/ai/` **A-02**, **AIR-18** |
| A fine-tuned proprietary accounting model | **IA-13** — the one asset in the stack that resets |
| A second datastore for any plane | Tenant isolation is RLS; a second store is a second isolation implementation |
| Any model inside any plane evaluator | `R-34`; stated three times in `ARCHITECTURE.md` for a reason |

# End of Document
