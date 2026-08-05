# BEST_PRACTICES — Building the substrate, not the demo

**Sixteen practices for an AI Financial Operating System (IB-01…IB-16) · `docs/research/innovation/`**

Version 1.0 · 2026-07-28 · **Documentation only** — no application code, schema, migration or test was
modified in producing this.

---

## What this file is, and what it is not

`docs/research/ai/BEST_PRACTICES.md` **B-01…B-18** covers how to *engineer* an AI layer: control flow,
context assembly, retrieval tiers, prompt versioning, loop budgets, injection defence. Those are not
repeated. Where a practice below depends on one, it points at it.

This file covers how to *build the category* described in `OVERVIEW.md` — the substrate that mediates
assertion authority, meters review attention, and accumulates transferable trust. The practices are
product-and-architecture level. Several cost nothing when adopted at the right moment and cannot be
adopted at all afterwards; those are marked **⏳ closing window**.

**Every practice carries the full dimension set** required of a recommendation in this programme: why ·
benefits · tradeoffs · risks · scalability · performance · maintainability · complexity · effort
(Fibonacci) · business impact · confidence · evidence.

**Evidence grades** are those declared in `README.md`.

---

## Index

| ID | Practice | Effort | Impact | Window |
|---|---|---|---|---|
| **IB-01** | Write down the refusal criterion, and apply it to every roadmap item | 1 | High | — |
| **IB-02** | Model authority explicitly: scoped, delegated, expiring, revocable | 13 | High | — |
| **IB-03** | Meter reviewer attention from the first AI feature | 8 | Critical | ⏳ |
| **IB-04** | Price proposals in expected consequence, deterministically | 5 | High | — |
| **IB-05** | Make truncation an artefact — name, age and report review debt | 5 | High | — |
| **IB-06** | Store every prediction as a falsifiable claim with a measurement date | 8 | High | ⏳ |
| **IB-07** | Publish the metric that can kill your own feature | 3 | Medium | — |
| **IB-08** | Stamp machine identity and version on every machine-originated artefact | 3 | High | ⏳ |
| **IB-09** | Band every derived number by epistemic status | 5 | Medium | — |
| **IB-10** | Language on the read path, forms on the write path | 2 | High | — |
| **IB-11** | Prefer refusal to a plausible answer, and publish coverage | 3 | High | — |
| **IB-12** | Make blast radius computable | 13 | High | — |
| **IB-13** | Ship the recording layer in the same story as the capability | 0 | Critical | ⏳ |
| **IB-14** | Sell verifiability to whoever pays for the audit | 2 | High | — |
| **IB-15** | Re-derive every threshold that came from a public benchmark | 1 | Medium | — |
| **IB-16** | Frame review telemetry as capacity, never as diligence | 2 | High | — |

---

## IB-01 — Write down the refusal criterion, and apply it to every roadmap item

*The cheapest practice here, and the one that makes the other fifteen possible.*

**Why.** `OVERVIEW.md` §0 identifies the structural failure of a category with no definition: the roadmap
becomes a list, the next feature is whichever one a competitor shipped last week, and there is no
principled way to say no. `07`'s own honesty section records this happening in real time — a competitor
release mid-drafting invalidated a market claim. A refusal criterion converts a taste argument into a
policy argument, and only the second survives a board meeting.

**What it looks like.** `OVERVIEW.md` **C1**, printed and applied at intake:

> *Build capabilities that increase what can be **proved**. Buy, or minimally implement, capabilities
> that only increase what can be **done**.*

Operationally: every backlog item entering `08_MASTER_BACKLOG.md` is tagged **substrate** or
**application**. Applications get built to a "good enough" bar, are never on the first slide, and are
explicitly allowed to be worse than a competitor's. Substrate items get built properly and early.

**Benefits.** Refusals become defensible and repeatable · roadmap arguments resolve in minutes instead of
meetings · engineering effort concentrates on the layer that compounds · sales messaging stops competing
on a checklist QAYD cannot win.

**Tradeoffs.** You will consciously ship a worse anomaly detector, a worse categoriser and a worse
forecast than at least one competitor, and someone will lose a deal over it. That is the intended cost,
and it should be recorded as such when it happens rather than triggering a reversal.

**Risks.** The tag becomes ceremonial and everything gets labelled substrate (mitigate: the tag requires
a one-sentence justification of *what it lets you prove*) · a genuinely strategic application gets
under-built because of a label (mitigate: the criterion is a default, overridable in writing).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| n/a — process | n/a | Trivial; one field and a rule in the intake gate | Trivial |

**Effort: 1 · Business impact: High · Confidence: High · Evidence:** `[INFERENCE]` from
`OVERVIEW.md` §0/§4 and `07` §4's own moat analysis.
→ `LESSONS_FOR_QAYD.md` **IL-01**, `IMPLEMENTATION_RECOMMENDATIONS.md` **IR-01**.

---

## IB-02 — Model authority explicitly: scoped, delegated, expiring, revocable

*The syscall interface, generalised beyond role checks.*

**Why.** `OVERVIEW.md` §3.1 identifies the gap precisely: QAYD arbitrates the authority to assert a
financial fact very well, but that authority is currently **binary and implicit** — you are a role that
may post, or you are not. An operating system models authority as an explicit object: scoped to a
resource class, delegated by a named grantor, bounded in time, revocable, and **recorded as a delegation
rather than a configuration**.

The forcing case is the machine. When a capability proposes an entry, "the AI did it" is not an authority
statement. *"Capability `bank_match`, grant #4471, issued by the controller on 3 March, scoped to
accounts in the bank class, expiring 3 June, consuming reversibility budget"* is.

**What it looks like.** A `capability_grant` record (`OVERVIEW.md` I-27) that every machine-originated
proposal references, and that the posting path can evaluate deterministically. Grants are data, not code
(`P18`, `R-33`). Revocation is an event, not a delete. Expiry is enforced at evaluation, not by a
sweeper.

**Benefits.** Answers "who authorised this?" for machine actions, which is currently unanswerable ·
replaces the agent-org-chart framing (IA-07) with something enforceable · makes autonomy expansion a
governance decision the customer makes rather than a vendor default · pairs naturally with I-17's
reversibility budget, which becomes the grant's spend limit.

**Tradeoffs.** A real permission system is more work than a role check, and the first version will feel
like over-engineering for two capabilities. The payoff arrives at capability five and at the first
enterprise security review.

**Risks.** Grant sprawl — dozens of near-identical grants nobody can reason about (mitigate: grants are
templated per capability, with per-tenant parameters only) · a grant becomes a de-facto standing
authorisation because it is never allowed to expire (mitigate: expiry is mandatory and renewal is an
explicit act that appears in the audit log) · complexity leaks into the posting hot path (mitigate:
evaluation is a pure function over already-loaded rows).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Grants are per-tenant, small, cacheable | Evaluation is O(1) over a loaded grant; no added query on the posting path if joined at proposal time | Good — data-driven, testable in isolation (`P16`) | **Medium-High** — this is a real authorisation subsystem |

**Effort: 13 · Business impact: High · Confidence: Medium** — the shape is borrowed from
capability-security systems where it is well proven, but its fit to an accounting product is reasoning
rather than observation. **Evidence:** `[CODE]` for the current binary model; `[INFERENCE]` for the
design.
→ `ARCHITECTURE.md` §4, `LESSONS_FOR_QAYD.md` **IL-04**.

---

## IB-03 — Meter reviewer attention from the first AI feature ⏳

*The scarcest resource in the system is the one nobody meters.*

**Why.** `OVERVIEW.md` §3.2 makes the argument and IA-11 makes the refusal. The compressed version: human
review is the only thing standing between a model and the general ledger; it is small, fixed in the short
run, and invisible on any invoice — so every AI feature is shipped as though it were free. Ship six
features, get six unbounded queues, and **the safety property the architecture rests on degrades as a
function of product success**, invisibly.

The evidence that this is a real behavioural effect rather than a worry: a 2025 systematic review of 35
peer-reviewed studies across healthcare, finance, national security and public administration found
**agreement with incorrect AI recommendations is the most consistent behavioural outcome** in human–AI
decision-support pairings `[INDEPENDENT]`; practitioner analysis in 2026 states the design paradox
plainly — **the cleaner and faster the interface, the more likely the human approves without thinking**
`[COMMUNITY]`.

**What it looks like.** Every capability that creates review work **declares its attention cost in
reviewer-minutes per week** as an acceptance criterion. The sum is visible on one screen. A capability
that cannot state its cost does not ship. Capacity is calibrated from observed behaviour — median dwell
time on items the reviewer later *modified*, which is the only observable proxy for genuine engagement —
not from self-report.

**⏳ Why the window closes.** Engagement telemetry cannot be backfilled. Every week shipped without it is
a week of unrecoverable calibration data, exactly like I-04 and I-09.

**Benefits.** Turns an invisible degradation into a managed number · gives the controller a defensible
answer to "why didn't you catch this?" · produces the metric an audit committee will eventually demand ·
gives QAYD the only honest denominator for its own safety claims.

**Tradeoffs.** It measures the customer's staff, and that is a product and trust decision before it is an
engineering one (see IB-16). It also produces a number that will sometimes say the product is asking too
much — which is the point, and will be uncomfortable.

**Risks.** Perceived as surveillance and routed around (**High** — aggregate by role and team by default;
individual telemetry opt-in and visible to the individual; never expose to a manager as a performance
metric) · the measured rubber-stamp rate is discoverable in litigation (**High and under-appreciated** —
the honest position is that the alternative is not *no evidence*, it is *no knowledge*; take legal advice
before setting the retention policy).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Telemetry is append-only and per-tenant; volume is proportional to review events, i.e. small | Negligible on the request path; aggregation is a background job | Medium — the calibration logic will need tuning | **Medium** — the hard part is political, not technical |

**Effort: 8 · Business impact: Critical · Confidence: High** that the problem is real; **Low** on the
telemetry's political viability without a design partner — test with one controller before building past
the estimator. **Evidence:** `[INDEPENDENT]` `[COMMUNITY]` `[DOCS]`.
→ `OVERVIEW.md` I-21, `ANTI_PATTERNS.md` **IA-11**, `docs/research/ai/` **B-12**.

---

## IB-04 — Price proposals in expected consequence, deterministically

*The scheduler needs a price, and the price may not come from the model.*

**Why.** If attention is scheduled, something must order the queue. The tempting orderer is model
confidence — and that is `R-32` re-implemented at the product layer (IA-10, IA-11). The correct orderer
is **expected consequence**, computed by arithmetic the customer can inspect and argue with.

**What it looks like.** A deterministic scoring function over data QAYD already holds: amount, account
sensitivity, proximity to a period boundary, counterparty novelty, whether the entry feeds a filing or a
covenant, reversibility, and whether an equivalent has been approved before. Published, editable by the
customer, versioned like any other policy (`P18`).

**Benefits.** Defensible ordering the customer chose · generalises I-17's reversibility budget from
*actions the machine takes* to *attention the human spends* · immune to model drift, because no model is
involved · independently useful even before a scheduler exists — the score alone improves any queue.

**Tradeoffs.** Deterministic scoring will be wrong sometimes in ways a model might have caught. Accepted:
a wrong-but-inspectable ordering is recoverable; a wrong-and-opaque one is not.

**Risks.** **Critical** — if the estimator is wrong, truncation hides the item that mattered (mitigate:
blind sampling must include below-the-line items, not only above; the estimator is published and
customer-editable) · scope creep into ML, which must be refused on `R-34` grounds.

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Pure function; scales with proposal volume | Microseconds; computable at proposal time and stored | High — a table of weights and a pure function are the easiest things here to test | **Low** |

**Effort: 5 · Business impact: High · Confidence: High.** **Evidence:** `[INFERENCE]`, borrowing
risk-based sampling from audit practice, where it is well established.
→ `OVERVIEW.md` I-21 mechanism 2; `IMPLEMENTATION_RECOMMENDATIONS.md` **IR-03**.

---

## IB-05 — Make truncation an artefact: name, age and report review debt

*Truncating the queue is correct. Hiding the truncation is the anti-pattern.*

**Why.** Every system that produces more proposals than a human can review truncates. The market's
default is to truncate silently, by model confidence (IA-11). The practice is to truncate deliberately,
by consequence, and to make **what was not reviewed** a first-class object.

**What it looks like.** *Review debt*: an append-only record of decisions **not to show**, carrying the
item, its consequence score, the capacity that excluded it, and its age. It is reported as a balance
(*"KWD 41,200 across 88 items, oldest 19 days"*), it ages, and above a threshold it **blocks a period
close** rather than warning — I-18's detect-and-wait principle applied to attention.

**Benefits.** Converts an invisible risk into a managed balance · gives the close a meaningful gate ·
feeds I-04's assurance-weighted balance as its own band · makes the "we reviewed everything" claim either
true or visibly false.

**Tradeoffs.** Customers will not enjoy a number that says their books are less reviewed than they
thought. Some will ask to turn it off, which is the moment to discover whether IB-14's buyer thesis is
real.

**Risks.** Debt becomes a number nobody looks at (mitigate: it blocks close above threshold) · it is
implemented as a mutable flag rather than an append-only record of decisions, destroying its evidentiary
value (mitigate: append-only, same discipline as `P5`).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Append-only, per-tenant, partitionable on the same pattern as `audit_logs` | Aggregation is a periodic rollup, not a live query | Good | **Low-Medium** |

**Effort: 5 · Business impact: High · Confidence: Medium** — the mechanism is sound; whether customers
tolerate the number is untested. **Evidence:** `[INFERENCE]`; adjacent prior art in SOC alert triage and
audit sampling `[COMMUNITY]`.

---

## IB-06 — Store every prediction as a falsifiable claim with a measurement date ⏳

*The one-line rule behind three separate capabilities.*

**Why.** `OVERVIEW.md` **C4**. Any forward-looking output — forecast, risk score, recommendation,
estimated exposure — is an assertion about the future by a system whose entire pitch is that assertions
are recorded and checkable. Emitting it unrecorded is incoherent with the product (IA-09). It is also the
cheapest closing window in the folder: unrecorded predictions cannot be recovered.

**What it looks like.** One shared claim store: `{capability, subject, horizon, band, value, range,
basis, model_identity, made_at, measure_on, outcome}`. Scored automatically when `measure_on` arrives.
Built **once** and consumed by I-22 (cashflow bands), I-23 (advice), I-26 (solvency tripwire) — three
capabilities, one substrate.

**Benefits.** Makes error attributable — model wrong, or world changed? · produces the decay curve, which
is the differentiated artefact competitors cannot backfill · gives calibration measurement real labels ·
turns "insights" from marketing into a measurable product.

**Tradeoffs.** Every prediction surface acquires a schema obligation and a scoring job. Some
low-consequence surfaces will feel over-engineered; the answer is not to skip the store but to emit fewer
predictions.

**Risks.** Scoring is ambiguous for claims whose subject changed underneath them (mitigate: store the
basis, and score "superseded" as a distinct outcome, not a failure) · the hit rate is bad and someone
wants to hide it (mitigate: IB-07 — the decision to publish is made once, in advance).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Append-only, volume proportional to prediction emissions — small | Scoring is a scheduled job over a date index | High — one table, one job, many consumers | **Low-Medium** |

**Effort: 8 (shared across three capabilities) · Business impact: High · Confidence: High.**
**Evidence:** `[INFERENCE]` from `OVERVIEW.md` C4; forecast-vs-actual tracking is standard FP&A practice
`[COMMUNITY]` and must not be claimed as an invention.

---

## IB-07 — Publish the metric that can kill your own feature

*I-10's principle, generalised.*

**Why.** Every claim in this product is of the form "you can check us". A vendor that publishes only
flattering numbers has made the claim and declined the check. It is also, unusually, a *competitive*
practice rather than merely an honest one: publishing a decay curve or a hit rate requires stored history
that a later entrant does not have, so the disclosure is itself a moat expression.

**What it looks like.** Per-band forecast decay curves; advice hit rates; the give-up rate on bounded
loops; the measured rubber-stamp rate from blind sampling; the coverage rate on natural-language queries
(IB-11). Each with a pre-committed rule for what happens when it goes bad — for example: *if the naive
seasonal baseline beats the model for three consecutive months, the model is disabled automatically and
the customer is told.*

**Benefits.** Differentiation that cannot be claimed without the data · forces the honest version of every
feature · converts a support conversation ("is this right?") into a link.

**Tradeoffs.** Some numbers will be embarrassing, and one of them will be embarrassing during a
fundraise. Pre-committing to publication before the number exists is what makes this survivable.

**Risks.** Selective publication — the practice degrades into publishing only the good curves (mitigate:
the list of published metrics is fixed at capability-approval time, not at release time).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Reporting only | Batch | Medium — each metric needs an owner | **Low** |

**Effort: 3 · Business impact: Medium (High as narrative) · Confidence: High.** **Evidence:**
`[INFERENCE]`; extends `07` I-10.

---

## IB-08 — Stamp machine identity and version on every machine-originated artefact ⏳

*"AI-generated, confidence 0.87" is not provenance; it is a rumour.*

**Why.** `OVERVIEW.md` **C3**. An assertion carries its authority, and for a machine the authority
includes *which model, which prompt version, which policy version, under which grant*. Without it, the
answer to "what else did this bad version touch?" is unanswerable, and IB-12 cannot be built at all.

**⏳ Why the window closes.** Every entry posted before the registry exists is **permanently
unattributable** — the strongest form of IA-14.

**What it looks like.** A model/prompt/policy identity registry, and a foreign key to it on every
proposal, every extraction, every prediction claim and every advice record. Immutable rows; new version =
new row. This is `docs/research/ai/` **B-07 / AIR-08** promoted from an engineering practice to a
product-substrate requirement, with the addition that the identity must survive onto the *posted* artefact
and not only the proposal.

**Benefits.** Enables blast-radius computation (IB-12) · enables calibration per model version · makes a
model rollback a bounded, describable event rather than a hope · answers the auditor's inevitable
question about which machine produced what.

**Tradeoffs.** One more join and one more immutable table. Trivial next to the cost of not having it.

**Risks.** The registry records the model but not the prompt or policy, which is 40% of the value
(mitigate: identity is a composite, and CI asserts all components are populated) · identity is stamped on
the proposal but lost at posting (mitigate: assert in a test that a posted entry originating from a
proposal retains it).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Tiny dimension table; unbounded but slow-growing | One FK; negligible | High | **Low** |

**Effort: 3 · Business impact: High · Confidence: High.** **Evidence:** `[CODE]` — `journal_entries`
carries `ai_generated` as a boolean today
`[apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php:58]`, which is the
before-state this practice replaces.

---

## IB-09 — Band every derived number by epistemic status

*One line implies one epistemic status, and that is a lie about the data.*

**Why.** A cashflow projection that mixes payroll on the 25th, a signed supplier contract and a guess
about November walk-in revenue produces a number whose error is unattributable. Payroll is not a
prediction; it is an obligation not yet recorded. `OVERVIEW.md` I-22 develops this for cash; the practice
generalises to any derived figure — pipeline, exposure, coverage, runway.

**What it looks like.** Three bands with different sources, owners and error behaviour: **committed**
(contracts and schedules; error ≈ 0 absent default; arithmetic, not forecast) · **expected**
(deterministic pattern from counterparty history; error measured and published; a rule, not an LLM —
`R-34`) · **speculative** (an actual model; a range, never a point; never folded into a single displayed
total). Band 1 carries a **completeness indicator** — *"derived from 23 recorded obligations; 4 known
documents unparsed"* — because a false certainty is worse than an honest guess.

**Benefits.** Maps onto how finance people already think (committed vs pipeline) · makes error
attributable · Band 1 is the wedge into the obligation record that I-25, I-26 and I-30 all need.

**Tradeoffs.** Users will ask for the single number anyway. If one must be exported, it carries the
range.

**Risks.** **Critical** — Band 1 presented as complete when coverage is unknown · Band 3 worse than a
naive baseline and shipped anyway (mitigate: publish both; auto-disable per IB-07).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Fine | Band 1 and 2 are queries; Band 3 is a batch model | Medium | **Medium-High**, dominated by obligation capture, which is a data problem not a model problem |

**Effort: 5 for banding and presentation** (the obligation record itself is ~21 and is I-22's real cost) ·
**Business impact: Medium · Confidence: High** on the epistemics, **Low** on the obligation-extraction
estimate — `07` §5.5 #3 already warns this family of estimates is optimistic. **Evidence:** `[COMMUNITY]`
for the forecast-accuracy decline; `[INFERENCE]` for the banding.

---

## IB-10 — Language on the read path, forms on the write path

*One line that resolves voice, chat and natural-language ERP simultaneously.*

**Why.** `OVERVIEW.md` **C5**. Refusal applies to *assertion*, not to *access*. The evidence in
`ANTI_PATTERNS.md` IA-01 and IA-02 shows both language channels failing silently and confidently; the
form is the surface that re-supplies the constraint display the user needs at the moment of commitment
(IA-03).

**What it looks like.** A **generous** read surface — natural language, voice, third-party agents, MCP-
style integrations — with **no** write path that bypasses the posting gate. Language *proposes*; a
pre-filled form with every constraint still rendered *disposes*. Model-supplied fields are visually
distinguished from human-supplied ones so that review has something to attach to.

**Benefits.** Captures nearly all of the demo value at none of the risk · one rule covers four separate
feature requests · the read surface is where third-party ecosystem value lives, and opening it is free
under this rule.

**Tradeoffs.** Loses the "no more forms" pitch, which is the most quotable line in the category. The
counter-pitch — *"we will never let a machine commit something you did not see the rules for"* — is
better in a finance sale and worse on stage.

**Risks.** Read surface leaks write capability through a "convenience" endpoint (mitigate: the posting
gate is a database-level refusal, not a convention — this is already true `[CODE]`) · a generous read
surface becomes an exfiltration path (mitigate: `docs/research/ai/` **B-14**, egress allowlist and no
rendered model-authored URLs).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Read paths scale independently and can be cached | Read-only; no contention with the posting path | High — the rule is one sentence | **Low** as a rule; the surfaces themselves vary |

**Effort: 2 to adopt as policy · Business impact: High · Confidence: High.** **Evidence:** `[PAPER]`
(ASR and text-to-SQL benchmarks, see `ANTI_PATTERNS.md`), `[DOCS]` (Zoho, QuickBooks both stopped at the
same line).

---

## IB-11 — Prefer refusal to a plausible answer, and publish coverage

*The single most important design property in the whole folder.*

**Why.** The design lesson from the text-to-SQL evidence, stated as a rule: **semantic-layer failures are
refusals; raw failures are confident wrong numbers** — Cube's "silent hallucination" `[DOCS]`. A refusal
costs a rephrase. A confident wrong number leaves the building inside a board pack.

**What it looks like.** Every model-mediated surface has an explicit *cannot answer* outcome that is
rendered as a normal, non-apologetic result. The **coverage rate** ("we can answer 71% of questions
asked") is a published product metric per IB-07, and it goes **up** over time as the semantic model
grows — which makes it a roadmap input rather than an embarrassment.

**Benefits.** Turns the failure mode from undetectable to visible · the refusal log is the highest-quality
feature-request queue in the product, because it is real questions users actually asked · protects the
provenance claim, since a refused question produces no unattributable number.

**Tradeoffs.** Early coverage will be low and a demo will hit a refusal. Rehearse the answer: *"it says it
cannot rather than guessing — here is the coverage curve."*

**Risks.** Refusal used as a crutch for a lazy semantic model (mitigate: coverage is a tracked metric with
a target) · over-refusal on questions the system can genuinely answer (mitigate: sample refusals weekly).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Fine | A refusal is cheaper than an answer | High | **Low** |

**Effort: 3 · Business impact: High · Confidence: High.** **Evidence:** `[PAPER]` (BEAVER, LogicCat,
BIRD), `[DOCS]` (Cube).

---

## IB-12 — Make blast radius computable

*"We were wrong. What does that touch?"*

**Why.** `OVERVIEW.md` **C6**. A product built on the claim that history is permanent and derivations are
traceable has an obligation to answer the question that follows a discovered error. `07` §5.2 lists four
mitigations, all of which *bound* error; **none responds to one**. The canonical case is not the
KWD 4,000,000 misposting that fails a sanity check — it is the vendor misclassified for eleven months,
inside every tolerance, approved forty-four times.

**What it looks like.** Given an identified defect — a wrong rule, a bad model version, a mis-mapped
counterparty — enumerate every posted entry, derived balance, report, filing and downstream claim that
depends on it, with amounts. Depends on IB-08 (identity), I-12 (number provenance) and the audit chain.
`OVERVIEW.md` I-30 adds the rehearsal: run it as a drill before you need it.

**Benefits.** The answer to the buyer's real objection, demonstrated rather than asserted · converts a
restatement from an archaeology project into a query · a genuinely hard capability for a competitor to
retrofit, because it requires the provenance to have existed at write time.

**Tradeoffs.** Substantial engineering, and its value is invisible until the day it is enormous. It is
the classic under-prioritised capability, which is exactly why it needs the IB-01 tag.

**Risks.** Partial coverage produces false comfort — an incomplete blast radius is more dangerous than
none (mitigate: the result carries an explicit coverage statement, in the IB-09 style) · it becomes a
performance problem on large tenants (mitigate: it is a batch analysis, not an interactive query).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| Batch; scales with history depth | Minutes, not milliseconds — and that is fine | Medium; couples to provenance schema | **High** |

**Effort: 13 · Business impact: High · Confidence: Medium** — the capability is clearly right; its
cost is uncertain and depends on how completely IB-08 and I-12 were done. **Evidence:** `[INFERENCE]`
from `OVERVIEW.md` C6 and `07` §5.2.

---

## IB-13 — Ship the recording layer in the same story as the capability ⏳

*Zero effort. Highest consequence. The rule that prevents IA-14.*

**Why.** Provenance never wins a sprint prioritisation against a feature, and never will, because its
value is entirely in the future. So it must not be a prioritisation decision at all — it must be a
**definition of done**.

**What it looks like.** One line in the intake rule of `08_MASTER_BACKLOG.md`:

> *A story that generates a permanent record ships its recording layer in the same story, or it does not
> ship.*

Concretely, that means: a capability emitting predictions ships the claim store row (IB-06); a capability
producing proposals ships the identity stamp (IB-08); a capability accepting corrections records **what**
changed, not that it changed (`docs/research/ai/` **AIR-05**).

**Benefits.** Removes the recurring argument entirely · costs nothing when obeyed · is the single
difference between having and not having the only durable moat in the strategy.

**Tradeoffs.** Individual stories get slightly larger. Roadmap dates move by days; the asset moves by
years.

**Risks.** The rule is written and then waived under deadline pressure (mitigate: waivers are recorded in
the backlog with the date and the person, so the cost is visible later — which is the same discipline the
product sells).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| n/a — process | n/a | Trivial | Trivial |

**Effort: 0 (a rule) · Business impact: Critical · Confidence: High.** **Evidence:** `[INFERENCE]`;
the irreversibility is a logical property, not an empirical claim.

---

## IB-14 — Sell verifiability to whoever pays for the audit

*The commercial correction, and it is cheap to test.*

**Why.** `OVERVIEW.md` **C7** and §3.3. Transferable trust is the only asset whose value compounds with
time and cannot be manufactured retroactively at any level of funding. `07` routes it through the audit
firm and flags — correctly — that this is the claim most likely to be wrong, because verification labour
is **billable** and the firm's incentive runs the other way (§5.5 #1).

**What it looks like.** Position the verifiable-record bundle (I-08 receipts, I-04 assurance grades, I-12
provenance) at the **auditee** (who pays the fee and wants it smaller), the **lender** (who wants to
underwrite faster — I-15) and the **acquirer** (who wants diligence cheaper). Test it with one
conversation each **before** building the full bundle.

**Benefits.** Aligns the pitch with the incentive · opens I-15's lending rail, which is a different and
larger business than accounting software · the artefact is the same in all three cases, so the test is
cheap.

**Tradeoffs.** A longer sales cycle into institutions than into SMEs, and it may require a partner.

**Risks.** The auditee does not perceive audit fees as compressible (mitigate: this is exactly what the
one-conversation test discovers, at near-zero cost) · lender adoption requires a regulatory conversation
QAYD is not resourced for (mitigate: scope the first test to a single lender, not to a rail).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| n/a — positioning | n/a | n/a | **Low** to test, High to fulfil |

**Effort: 2 to test · Business impact: High · Confidence: Low** — explicitly an untested prediction
(`OVERVIEW.md` §5 **P3**). **Evidence:** `[INFERENCE]`.

---

## IB-15 — Re-derive every threshold that came from a public benchmark

*Numbers taken from leaderboards go stale in the unsafe direction too.*

**Why.** IA-12. `07` §5.2 anchored a risk argument on **66.0%**; the same leaderboard now shows Round 2
at **77.3%** and a top score of **83.2%** `[DOCS]`. Any design decision derived from a public number — an
autonomy boundary, an escalation rule, a "not yet" on a capability — inherits that number's staleness.

**What it looks like.** Every threshold traceable to an external benchmark carries a comment naming the
source, the date, and the decision it supports; a standing annual task re-checks them; and the internal
eval built from the correction corpus (`docs/research/ai/` **B-17**) becomes the primary source as soon
as it has volume, because it measures QAYD's own distribution.

**Benefits.** Prevents both stale pessimism (refusing a capability that has become viable) and stale
optimism · cheap.

**Tradeoffs.** Someone has to own the annual sweep.

**Risks.** The sweep happens and the thresholds are not actually revisited (mitigate: the task's output is
a written decision per threshold — *changed* or *confirmed, with reason*).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| n/a | n/a | High | Trivial |

**Effort: 1 · Business impact: Medium · Confidence: High.** **Evidence:** `[DOCS]`, `[INDEPENDENT]`
(the published rebuttal of the benchmark).

---

## IB-16 — Frame review telemetry as capacity, never as diligence

*Whether IB-03 ships at all is decided by this sentence.*

**Why.** IB-03 measures humans. Presented as *diligence*, it is surveillance and the customer's staff will
defeat it — by opening items and leaving them open, by batching approvals, by routing around the tool.
Presented as *capacity*, it is a planning input the controller wants, because it produces the sentence
they need in front of their board: *"the system asked for 11 hours of review; we have 6."*

**What it looks like.** Aggregate by role and team by default. Individual data is opt-in and visible to
the individual first. Never surfaced to a manager as a performance metric. The framing rule stated in the
product copy: **the system's failure to fit within capacity is the system's failure.**

**Benefits.** Determines adoption of the most important capability in the folder · converts a threatening
metric into an advocacy tool for the customer's own budget conversation · makes the honest version of
"our AI created work for you" sayable.

**Tradeoffs.** Aggregation reduces the precision of calibration. Accepted — a slightly noisier number
that ships beats a precise one that gets switched off.

**Risks.** A customer's management demands individual data (mitigate: decide the policy before the first
request, and hold it as a product property, not a setting) · legal discoverability (see IB-03).

| Scalability | Performance | Maintainability | Complexity |
|---|---|---|---|
| n/a | n/a | High | **Low** technically; the risk is entirely in framing |

**Effort: 2 · Business impact: High (gating) · Confidence: Medium** — reasoning, not observation; the
only real test is a design partner. **Evidence:** `[INFERENCE]`, `[COMMUNITY]`.

---

## How these interlock

```
   IB-01 refusal criterion ────────────────► decides what gets built at all
        │
        ├─► SUBSTRATE ──┬─ IB-02 authority ──┬─ IB-08 machine identity ⏳
        │               │                     └─ IB-12 blast radius
        │               │
        │               ├─ IB-03 attention ⏳ ─┬─ IB-04 consequence price
        │               │                      ├─ IB-05 review debt
        │               │                      └─ IB-16 capacity framing (gates IB-03)
        │               │
        │               └─ IB-06 claim store ⏳ ┬─ IB-07 publish the killing metric
        │                                       └─ IB-09 epistemic bands
        │
        └─► APPLICATIONS ─┬─ IB-10 read/write split
                          └─ IB-11 refusal over plausibility

   IB-13 (a rule, effort 0) protects all three ⏳ items from being deferred.
   IB-14 decides who is sold the output of the substrate.
   IB-15 keeps every externally-derived threshold from rotting.
```

The three ⏳ items — **IB-03, IB-06, IB-08** — are the only ones whose cost rises to *infinite* if
deferred, and **IB-13 exists solely to stop that from happening.** If four things from this document are
adopted, adopt those four.

# End of Document
