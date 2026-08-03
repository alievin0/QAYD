# 07 — Implementation Recommendations

**Sequenced against the real sprint plan · `docs/research/ai/`**

Version 1.0 · 2026-07-28

Every recommendation is bound to a story that already exists in `docs/execution/SPRINT_03.md` or
`SPRINT_04.md`, or is named as a new item for triage into `08_MASTER_BACKLOG.md`. Nothing here is a
parallel roadmap — the sprints are planned and the plans are good. Most of this is **acceptance criteria
and design constraints on stories that already exist**, which is the same posture the master backlog
takes.

**Effort** is Fibonacci. **Confidence** is High / Medium / Low with a reason. **Value** uses the master
backlog's scale (Critical / High / Medium / Low).

---

## The one-page answer

If only five things from this research happen, these five:

| # | Item | Effort | Why it cannot wait |
|---|---|---|---|
| 1 | **AIR-02** — decide the no-DB-driver question and write the ADR | **0 to decide** | S3-07 writes the transport in week 1. Deciding after is a rewrite. |
| 2 | **AIR-01** — closed capability enum on `/internal/invoke` | 3 | Five lines of code on day one; a schema migration and a contract break on day ninety. |
| 3 | **AIR-04** — egress allowlist + no rendered model URLs | 3 | Closes the highest-severity documented attack class in this product category. |
| 4 | **AIR-05** — correction capture (what changed, not that it changed) | 3 | The only item here whose signal is **destroyed** rather than delayed by waiting. |
| 5 | **AIR-03** — quarantined extraction, typed record crosses the boundary | 5 | S4-02 is where untrusted text first meets a model. Retrofitting the quarantine means re-designing the pipeline. |

**14 points of build, plus one decision.** Everything else in this document can slip a sprint. These
four cannot, because each either protects a guarantee already claimed or destroys an asset by waiting.

---

## Index

| ID | Recommendation | Story | Effort | Value | Pri |
|---|---|---|---|---|---|
| **AIR-01** | Closed capability enum, not an open invoke | S3-07 | 3 | High | P0 |
| **AIR-02** | Decide: no DB driver in the engine (**ADR**) | S3-07 | 0 / 3 | Critical | P0 |
| **AIR-03** | Quarantined extraction; the record crosses, not the document | S4-02 | 5 | Critical | P0 |
| **AIR-04** | Egress allowlist + no rendered model-authored URLs | S3-07 + frontend | 3 | Critical | P0 |
| **AIR-05** | Correction capture: record *what* changed | S3-09, S4-04 | 3 | High | P0 |
| **AIR-06** | Context budget + deterministic assembly + cache assertion | S3-08 | 5 | High | P1 |
| **AIR-07** | pgvector decision recorded, with the corpus constraint and triggers | R-02 | 2 | High | P1 |
| **AIR-08** | Prompt versioning stamped on every artefact | S3-08 | 3 | High | P1 |
| **AIR-09** | Structured rationale, reasoning-first schema, value-constrained enums | S3-08, S4-02 | 3 | High | P1 |
| **AIR-10** | Per-proposal cost record + cache-ratio alert | S4-11 | 3 | High | P1 |
| **AIR-11** | Eval harness: code graders over existing invariants | S3-09 | 8 | High | P1 |
| **AIR-12** | Loop budgets and a first-class give-up outcome | S4-10, S4-11 | 3 | High | P1 |
| **AIR-13** | Three-tier retrieval with the SQL temporal filter | S4-02 + memory | 8 | High | P1 |
| **AIR-14** | Approval instrumentation: latency telemetry + blind sampling | S4-04 | 8 | High | P2 |
| **AIR-15** | Calibration measurement per capability × version | post-S4 | 5 | High | P2 |
| **AIR-16** | Adversarial fixture corpus in CI | S4-12 | 5 | High | P2 |
| **AIR-17** | Copilot as the only agentic loop, bounded and read-only | S4-10 | 5 | Medium | P2 |
| **AIR-18** | Capability config replaces per-agent runtimes | S4-01, S4-09 | 5 | High | P1 |
| **AIR-19** | Correct the two cache constants in `05` | doc fix | 1 | Medium | P1 |
| **AIR-20** | Challenger ships dark; precision measured before it is surfaced | X-04 | 3 | Medium | P3 |

---

# TIER A — Before or during Sprint 3

These land in stories being written now. Deferring them means rewriting rather than adding.

## AIR-01 — Closed capability enum, not an open invoke

**Story: S3-07** (AI-engine skeleton + transport).
**What.** `/internal/invoke` accepts a discriminated union over a closed capability enum. An unrecognised
task is a `400`. There is no default handler and no free-text prompt field on the wire.

**Why now.** S3-07 builds this endpoint in week 1. Making the payload closed is five lines then; opening
it later is trivial, closing it later is a contract break across two languages plus every caller.

**Acceptance criteria to add to S3-07.**
- The capability enum is generated from a single source consumed by both PHP and Python; adding a
  capability on one side without the other fails the build.
- A request with an unknown `task` returns `400` and no model call is made (asserted).
- No request field accepts free-form model instructions.
- Contract fixtures cover **responses** as well as requests.

**Tradeoffs.** Each new capability is a two-sided deployment. Intended — it forces the eval suite
(AIR-11) to exist before the capability ships.
**Risk.** A "debug" or "playground" capability is added and becomes load-bearing. Mitigate by asserting
in CI that the production enum contains no capability whose name matches a deny list.
**Effort: 3 · Value: High · Priority: P0 · Confidence: High.**
→ `BEST_PRACTICES.md` **B-02**, `ARCHITECTURE.md` §5.1.

---

## AIR-02 — Decide the DB-driver question, and write the ADR

**Story: S3-07.** ⚠️ **This is a decision, not code — and it is the highest-value item in this document
relative to its cost, in the same way `IM-05` is in the master backlog.**

**The contradiction.** `P15` treats the absence of a database driver in `apps/ai` as real enforcement
worth a one-line CI check (Gap G-8, effort 1). `P-12` specifies a `qayd_ai` role with `SELECT` on read
models and `INSERT` on proposals, calling the GRANT matrix "THIS is the guarantee." Both cannot be
primary. Today `apps/ai` depends only on `fastapi` and `uvicorn` `[CODE]` (`apps/ai/pyproject.toml`), so
the status quo is Position B by accident.

**Recommendation: no driver.** Retrieval becomes a Laravel-mediated read API (`POST /internal/context`).
Embedding vectors are *returned* by the engine and written by a Laravel Action. Full argument in
`ARCHITECTURE.md` §3.2; summary in `LESSONS_FOR_QAYD.md` **L-04**.

**Why now.** S3-07 is front-loaded to week 1 and its risk register already names "the AI engine gains a
path to write tenant data directly (DB credential…)" as Low-likelihood / Critical-impact `[CODE]`
(`docs/execution/SPRINT_03.md:257`). Deciding this after the transport exists means the first connection
string is added under delivery pressure.

**Governance.** This refines `P-12`, which sits in a frozen knowledge base. **MANIFEST Law 1 requires an
ADR** — a knowledge-base research document cannot overturn a frozen pattern. The ADR should record: the
decision, `P-12`'s GRANT matrix retained as the documented fallback, and the measurement (Q2 in
`ARCHITECTURE.md` §14) that would trigger adopting it.

**Acceptance criteria.**
- ADR merged before S3-07's transport is implemented.
- CI check (Gap G-8): `apps/ai` declares no database driver. One line.
- If — and only if — the fallback is later adopted, `P-12`'s catalog-driven GRANT test becomes mandatory
  in the same change.

**Effort: 0 to decide; ~3 to implement the read endpoint · Value: Critical · Priority: P0 ·
Confidence: High** on the analysis; **the decision is the Architecture Owner's.**

---

## AIR-04 — Egress allowlist and no rendered model-authored URLs

**Story: S3-07** (backend) **+ a frontend constraint** applying to S3-09, S4-04, S4-09, S4-10.

**What.** Two halves in two codebases.

1. **Backend.** The AI engine has no outbound network route except an allowlisted model-provider
   endpoint. Enforced at the network layer (egress policy or proxy), not in application configuration.
2. **Frontend.** The UI never renders a model-authored URL as a link, never fetches one, and never
   renders a model-authored image reference. **Citations are internal ids** which the trusted layer
   resolves to signed URLs — which S4-02 already specifies for documents; this makes an id the *only*
   permitted citation shape anywhere.

**Why now.** QAYD unavoidably holds legs 1 and 2 of the lethal trifecta. Leg 3 is severable at near-zero
cost, and EchoLeak (CVE-2025-32711, CVSS 9.3) demonstrates that the channel that actually gets used is a
**renderer that fetches a URL the model authored**, through an allowlisted host, after the injection
classifier has been bypassed (`OVERVIEW.md` §6.3).

**Acceptance criteria.**
- A test asserts an outbound HTTP request from the engine to a non-allowlisted host fails.
- A frontend test asserts a model-authored `http(s)://…` string in a rationale renders as inert text.
- A frontend test asserts a model-authored markdown image reference does not produce a network request.
- Any addition to the egress allowlist requires a security review, recorded.

**Tradeoffs.** Rules out any future feature where the AI fetches an external resource. Those become
trusted-side integrations invoked by code — which `B-01` requires anyway.
**Effort: 3 · Value: Critical · Priority: P0 · Confidence: High.**
→ `BEST_PRACTICES.md` **B-14**, `LESSONS_FOR_QAYD.md` **L-07**.

---

## AIR-05 — Correction capture: record *what* changed

**Stories: S3-09** (accept/reject workbench) **and S4-04** (decision review UI).

**What.** Rejection records a reason code plus free text. **Edit-and-accept records the diff** — which
field, from what value, to what value, with the proposal id and the human's id. Together with
`posting_attempts` (`P-10`) and reversal reasons (`P-13`), this is the Correction Corpus.

**Why now, and why this is the only irreversible item here.** Every other recommendation in this
document can be added later at some cost. This one cannot: an edit that is not recorded at the moment it
is made is gone. `S3+A` already states the principle — "nearly free designed in now; expensive to
backfill" — and for the edit path it is not expensive, it is **impossible**.

Edit-and-accept is the richest signal in the entire system: it is a negative, plus the correct answer,
plus the *location* of the error. Nothing else QAYD collects localises a failure.

**Acceptance criteria to add.**
- S3-09: rejecting a suggestion records a structured reason, not only a dismissal.
- S4-04: edit-and-accept persists a field-level diff against the original proposal.
- Rejected and superseded proposals are **retained**, never deleted (`P-12` already requires this — make
  it a test).
- A query returns, for any capability and date range, the set of (proposal, human correction) pairs.

**Effort: 3 · Value: High (raise from `S3+A`'s current rating) · Priority: P0 · Confidence: High.**
→ `BEST_PRACTICES.md` **B-17**, `LESSONS_FOR_QAYD.md` **L-11**.

---

## AIR-06 — Context budget, deterministic assembly, cache assertion

**Story: S3-08** (Banking Agent match suggestions) — the first capability that assembles real context.

**What.** A `ContextBudget` per capability: per-section token ceilings, a fixed assembly order,
deterministic serialisation (`ORDER BY` on every collection), priority truncation, and
`truncated_sections[]` recorded on the output. Cache breakpoints planted per `ARCHITECTURE.md` §5.2.

**Why now.** The first capability sets the pattern the next twelve copy. It is also where the cached
prefix is first constructed, and `05_FUTURE_ARCHITECTURE.md` already identifies a silent caching
regression as the highest-frequency cost risk in the system.

**Acceptance criteria.**
- An integration test asserts `cache_read_input_tokens > 0` on the second identical invocation.
- A test asserts two invocations with the same dossier produce byte-identical prefixes.
- Exceeding a section budget truncates the lowest-priority section and records it.
- The prefix clears the model tier's minimum cacheable length **as measured**, not as assumed (AIR-19).

**Tradeoffs.** Some useful context is excluded by a budget. Raise budgets deliberately with eval evidence.
**Effort: 5 · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **B-03**, `LESSONS_FOR_QAYD.md` **L-16**.

---

## AIR-08 — Prompt versioning stamped on every artefact

**Story: S3-08.**

**What.** Prompts are repository artefacts compiled to a content hash. Every proposal, extraction and
chat turn records `prompt_version` alongside the `model_id` / `model_version` that `P-12` already
requires. Per-tenant variation only by interpolating data into a fixed template.

**Why now.** `P-12`'s own argument for `model_version` — "a regression becomes unattributable and
un-rollbackable" — applies identically to prompts, which change far more often. Adding the column later
means every proposal created before it is unattributable forever.

**Acceptance criteria.**
- `prompt_version` is `NOT NULL` on every AI-produced artefact, alongside `model_version`.
- A query returns all proposals produced by a given prompt version.
- CI fails if a file in the prompts directory changes without the eval suite running.

**Effort: 3 · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **B-07**, `ANTI_PATTERNS.md` **A-05**.

---

## AIR-09 — Structured rationale, reasoning-first schema, constrained value spaces

**Stories: S3-08, S4-02.**

**What.** Three schema rules applied to every capability:

1. **Field order is `{ reasoning, evidence, decision, confidence }`.** Never decision-first.
2. **`rationale` is structured** — `rules_fired[]`, `feature_contributions[]`, `precedents_cited[]` (by
   primary key), `spans[]`. Rendered as prose in the UI if desired; **stored as structure**.
3. **Enumerable fields are enums over sets supplied in the request** — account codes from the tenant's
   postable accounts, tax codes from configured codes, document ids from ids passed in.

**Why.** Rule 1 is free and prevents post-hoc rationalisation (`B-05`). Rule 2 is already required by
`P-12` for reviewability, and is additionally **cheaper** because output tokens are ~5× input, and it
makes `I-12` Number Provenance a side effect rather than a project. Rule 3 converts hallucinated accounts
from a validation problem into an impossibility, and is the cheapest injection mitigation available
(`B-06`).

**Acceptance criteria.**
- A property test: a proposal referencing an account outside the supplied enum cannot be constructed.
- `rationale` is JSONB with a schema; a free-text-only rationale fails validation.
- Every `precedents_cited` entry resolves to an existing row.

**Effort: 3 · Value: High · Priority: P1 · Confidence: High** on rules 2 and 3; **Medium-High** on rule 1
(mechanism sound, magnitude disputed in the literature).
→ `LESSONS_FOR_QAYD.md` **L-19**, `ANTI_PATTERNS.md` **A-10**.

---

## AIR-11 — Eval harness: code graders over invariants QAYD already owns

**Story: S3-09**, as an added deliverable.

**What.** A harness that runs a capability against fixtures and grades with **code first**: does it
balance? does the account exist and is it postable? is the period open? is the amount within document
tolerance? does the reconciliation over-consume? does it equal the human's corrected version? Human
sampling second, for what code cannot decide. A model judge third, confined to explanation quality,
validated against human labels with precision and recall reported separately.

Start at **20–50 tasks drawn from real failures**, per the published sizing guidance.

**Why now.** Without it, the S4 model and prompt changes are risk events rather than routine operations,
and they get deferred. And QAYD is in the rare position of having an oracle in the database — the
cheapest, most robust grader family is also the most applicable (`LESSONS_FOR_QAYD.md` **L-12**).

**Acceptance criteria.**
- Two sets: a **frozen regression set** gating CI, and a **rolling recent set** reported separately with
  accuracy on newly-seen subjects broken out.
- Splits are **by tenant**, never by row.
- A prompt or model change that regresses the frozen set fails CI.
- The model judge, where used, reports precision and recall against human labels; it never scores
  financial correctness.

**Tradeoffs.** Code graders can be brittle. Define an equivalence class for "different but equally
correct" (account granularity, timing within a period) rather than demanding exact match everywhere.
**Effort: 8 · Value: High · Priority: P1 · Confidence: High.**
→ `ARCHITECTURE.md` §10, `ANTI_PATTERNS.md` **A-06**, **A-16**.

---

# TIER B — Sprint 4

## AIR-03 — Quarantined extraction; the record crosses, not the document

**Story: S4-02** (document register → extract pipeline).

**What.** Zone 3 text — OCR output, filenames, embedded image text — enters **exactly one** model call,
inside a constant-wrapped content block, in the extraction capability. Its output is a typed record with
no free-text passthrough: every field is a constrained value or a verbatim span carrying
`{text, offset, doc_id}`. **The drafting capability receives the record, never the document.**

**Why now.** S4-02 is where attacker-authored text first meets a model. The quarantine is a property of
the pipeline shape; retrofitting it means re-designing the pipeline.

This is the same mechanism Claude Code uses for fetched web content — "Web fetch uses a separate context
window to avoid injecting potentially malicious prompts" (`OVERVIEW.md` §6.7).

**Acceptance criteria to add to S4-02.**
- A test asserts the drafting capability's rendered prompt contains no substring of the source document
  beyond declared spans.
- Extraction output contains no unconstrained free-text field.
- A fixture document containing an injected instruction produces either a valid typed record or a
  flagged low-confidence field — never a change in what the pipeline does next.

**Tradeoffs.** Some nuance in a line-item description is lost by not showing the drafting model the raw
document. Mitigated by verbatim spans with provenance.
**Effort: 5 · Value: Critical · Priority: P0 within S4 · Confidence: High.**
→ `BEST_PRACTICES.md` **B-15**, `ARCHITECTURE.md` §5.3, §8.1.

---

## AIR-10 — Per-proposal cost record and the cache-ratio alert

**Story: S4-11** (cost/rate governance + degraded mode).

**What.** Every model call writes a record keyed to the artefact it served: model id, prompt version,
capability, `input_tokens`, `cache_read_input_tokens`, `cache_creation_input_tokens`, `output_tokens`,
latency, computed cost. Two derived alerts, both already named in `05_FUTURE_ARCHITECTURE.md`:
`cache_read / total_input` below threshold, and `cache_creation_input_tokens` persistently zero despite a
`cache_control` marker.

**Why.** S4-11 specifies a per-company token/spend budget. Without a per-call cost record it can only
count requests, which is not a budget. And a silent caching regression becomes a step change in
cost-per-proposal detectable within hours instead of a surprise at month-end.

**Acceptance criteria to add to S4-11.**
- `AiCostGovernor` enforces **spend**, computed from recorded tokens, not a request count.
- Per-tenant cost per capability is a query.
- Both cache alerts fire in a test that deliberately breaks the prefix (e.g. injects a timestamp).

**Effort: 3 · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **B-16**.

---

## AIR-12 — Loop budgets and a first-class give-up outcome

**Stories: S4-10** (Copilot) **and S4-11**.

**What.** Every iteration carries a maximum step count, a maximum cumulative token spend and a deadline.
Exhausting any produces `outcome = 'gave_up'` with partial state attached, recorded and surfaced. Every
AI-touched artefact carries an outcome enum — `proposed | no_candidate | unavailable | over_budget |
gave_up` — with counters and an alert on the non-`proposed` rate.

**Why.** Every failure in the published multi-agent failure literature is a boundedness failure, not a
reasoning failure. And "we could not evaluate this" must never render the same as "we evaluated this and
found nothing" — that is `R-30` in the AI layer.

S4-11 already specifies the degraded-mode split correctly (`503` for AI-only, `200` with
`meta.ai_suggestion: null` for AI-optional). This adds that `null` must be **distinguishable, recorded
and counted**.

**Acceptance criteria to add.**
- A Copilot request that exhausts its step budget returns a completed response saying so, not a timeout.
- The non-`proposed` outcome rate is a dashboard metric with an alert threshold.
- A test asserts an AI-optional endpoint returning `null` also records a reason.

**Effort: 3 · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **B-08**, `ANTI_PATTERNS.md` **A-01**, **A-15**.

---

## AIR-13 — Three-tier retrieval with the SQL temporal filter

**Story: S4-02** and the memory subsystem.

**What.** Retrieval follows the fixed cascade: **(1)** exact structured lookup in
`ai_categorization_rules`; **(2)** applicability predicate over active judgements, with
`effective_from <= :date AND superseded_by IS NULL` **in the SQL `WHERE` clause**; **(3)** hybrid
semantic search over a deliberately small embedded corpus, re-ranked if measurement justifies it.

If tier 1 returns a row with sufficient support, **the AI is not called at all.**

**Why.** Tier 1 is `R-34` applied to retrieval — a question with an exact answer gets an exact lookup,
which is faster, free, reproducible and explicable by pointing at a row. Tier 2 makes `I-05`'s stated
stale-judgement risk structurally impossible rather than prompt-dependent. Tier 3 is where semantic
search earns its cost, and the evidence says it should be hybrid when used at all.

**Acceptance criteria.**
- A test inserts a superseded judgement and asserts it never appears in any retrieval result, for every
  capability.
- A test asserts that a high-support precedent short-circuits the model call entirely.
- Tier 3 uses hybrid retrieval (dense + lexical), not dense alone.

**Effort: 8 · Value: High · Priority: P1 · Confidence: High.**
→ `BEST_PRACTICES.md` **B-04**, `LESSONS_FOR_QAYD.md` **L-14**, **L-15**.

---

## AIR-18 — Capability config replaces per-agent runtimes

**Stories: S4-01** (proposal gateway + `AutonomyResolver`) **and S4-09** (AI Command Center).

**What.** The thirteen agents in `docs/ai/agents/*` are implemented as thirteen `Capability`
configurations of one runtime — each carrying its dossier spec, prompt version, output schema, model
tier, context budget, proposal type, autonomy class and eval suite. The product continues to say "the
Accountant Agent"; the deployment does not.

**Why now.** S4-01 builds the `AutonomyResolver`, which resolves per-agent; S4-09 renders "the agent
catalogue merged with `ai_agent_settings`" `[CODE]`. Both are the right shape **if** an agent is a
configuration. If an agent becomes a runtime, S4-01 is resolving against processes and S4-09 is a fleet
console.

**Acceptance criteria to add to S4-01.**
- `AutonomyResolver`'s unit is `(company, capability, operation)`, and its truth table is tested per
  capability (already specified) — with capability defined as a config object, not a service.
- Adding a capability requires no new deployment target.
- No capability can invoke another capability. (This is what makes `P-12`'s "letting one AI approve
  another's proposal" unrepresentable rather than merely forbidden.)

**Effort: 5 · Value: High · Priority: P1 · Confidence: High.**
→ `ARCHITECTURE.md` §4, `ANTI_PATTERNS.md` **A-07**, `LESSONS_FOR_QAYD.md` **L-03**.

---

## AIR-17 — Copilot as the only agentic loop, bounded and read-only

**Story: S4-10.**

**What.** The Copilot is the single place the model selects a tool. Constraints: read-only tool surface;
tools executed **in Zone 0 under the asking user's permissions** (already specified); a closed tool set;
step, token and wall-clock budgets; no Zone 3 content in the loop (it reads structured records only);
citations as internal ids; evaluated with **pass^k**, not pass@1.

**Why.** τ-bench's finding — state-of-the-art agents under 50% pass@1 and **under 25% at pass^8**
(`OVERVIEW.md` §9.2) — means a single-run accuracy number materially overstates what a user experiences
from a tool-using loop. S4-10 is already designated a cut candidate in the sprint plan; that instinct is
correct and this recommendation does not argue with it.

**Acceptance criteria to add to S4-10.**
- pass^k (k ≥ 5) is the reported reliability metric for the Copilot, alongside pass@1.
- A test asserts a user without `payroll.read` cannot surface payroll data through any tool (already an
  acceptance criterion — extend it to every tool added later, table-driven from the tool registry).
- The tool registry is closed; adding a tool is a reviewed change.

**Effort: 5 · Value: Medium · Priority: P2 · Confidence: Medium** — the constraints are right; whether
the Copilot survives the sprint is a scoping question.
→ `ARCHITECTURE.md` §8.3, `ANTI_PATTERNS.md` **A-12**.

---

# TIER C — After Sprint 4, or when triggered

## AIR-14 — Approval instrumentation: latency telemetry and blind sampling

**Story: S4-04**, extended post-launch.

**What.** Three mechanisms: an **engagement act** above a materiality threshold (confirm the amount or
select the account, rather than one always-in-the-same-place button); **time-to-approve telemetry** per
reviewer per capability with an alarm threshold; and a **blind-sampled second-review stream** — a fixed
random fraction, including high-confidence proposals, routed to a second reviewer with confidence and
rationale hidden.

**Why.** `P15` names reviewer fatigue as a real risk and `R-32` names rejection sampling as a requirement
of disciplined automation; neither specifies the instrument. The blind stream is the only measurement in
the system not conditioned on the reviewer having seen the model's opinion, which makes it the only
unbiased accuracy estimate — and therefore the only valid input to AIR-15.

**⚠️ Product risk, flagged.** This trades approval *speed* for approval *reliability*, and speed is a
headline product benefit. The honest framing is that QAYD sells the reliability of the approval, not the
speed of the click — but that is a positioning decision. **Validate with a design partner before tuning
the engagement mechanism aggressively.**

**Acceptance criteria.**
- Time-to-decision is recorded per proposal.
- A configurable sampling rate routes proposals to a blind second review.
- Disagreement rate on the blind stream is a reported metric.
- Batch approval is permitted only within a homogeneous cohort, with an aggregate shown and a ceiling.

**Effort: 8 · Value: High · Priority: P2 · Confidence: Medium-High** on necessity; **Medium** on the
specific thresholds, which need real usage.
→ `BEST_PRACTICES.md` **B-12**, `ANTI_PATTERNS.md` **A-08**.

---

## AIR-15 — Calibration measurement per capability × version

**Post-Sprint 4. Depends on AIR-05 and AIR-14.**

**What.** For each `(capability, model_version, prompt_version)`, compute the reliability curve —
predicted confidence bucket versus observed correctness — and a summary score (Brier or ECE), **over the
blind-sampled stream only**. Alert when calibration degrades even if accuracy has not.

**Why.** `R-32` claims confidence "degrades most gently exactly where accuracy degrades most sharply."
That is testable, and QAYD will have the labels. A calibration break is the **earliest available signal of
distribution shift** — a new bank format, a new vendor, a new chart — which is exactly where the model is
weakest.

**⚠️ Dependency that cannot be skipped.** Calibration computed over a biased positive set (fast
approvals) will look excellent and be false. AIR-14's blind stream is not optional for this.

**Acceptance criteria.**
- Reliability curves published per capability where n per bucket is sufficient; suppressed where it is
  not, rather than shown with a caveat.
- A calibration-drift alert exists and is distinct from an accuracy alert.

**Effort: 5 · Value: High · Priority: P2 · Confidence: High** on the method; **Medium** on when volume
makes it meaningful (`ARCHITECTURE.md` §14, Q5).
→ `BEST_PRACTICES.md` **B-11**, `ANTI_PATTERNS.md` **A-04**.

---

## AIR-16 — Adversarial fixture corpus in CI

**Story: S4-12** (MVP end-to-end), as an added gate.

**What.** A standing corpus of adversarial fixtures run in CI: an invoice with instructions in
white-on-white text; instructions in the OCR of an embedded image; instructions in a filename;
instructions in a bank narrative; a document instructing the model to use a different account; one
instructing it to emit a URL; one instructing it to ignore the chart of accounts; one impersonating a
system message.

**The pass criterion is not "the model resists."** It is that **layers L1–L6 hold regardless of what the
model does** (`ARCHITECTURE.md` §7). Anthropic's own published post-mitigation residual of 11.2% is why
the criterion is written that way.

**Acceptance criteria.**
- Every fixture produces either a valid typed output or a flagged low-confidence field — never a change
  in control flow, never an out-of-enum value, never an outbound request.
- The corpus grows when a new ingestion surface is added.

**Effort: 5 · Value: High · Priority: P2 · Confidence: High.**
→ `ARCHITECTURE.md` §7.2, `ANTI_PATTERNS.md` **A-11**.

---

## AIR-20 — Challenger ships dark; precision measured before it is surfaced

**Backlog item X-04 (I-10).**

**What.** Run the Challenger capability on the batch path (50% off — it is not interactive), generate
`ai_findings`, **show nobody**, measure precision (`confirmed / total`) for a month, and surface it only
if precision clears a threshold set in advance.

**Why.** `X-04`'s own gate is "Does it find real errors, or generate noise? Measure precision before
shipping." A noisy Challenger is not merely useless — it trains users to dismiss alerts, degrading every
other alerting surface including AIR-14's approval discipline. Alert fatigue is not recoverable by
improving the alert later.

Fold in `I-05`'s **drift detection** ("entries that violate an active judgement"), which I-05 explicitly
says is "part of the feature, not a follow-up." Planning them together turns two half-features into one.

**Effort: 3** to instrument the dark run (the capability itself is X-04's 13) **· Value: Medium ·
Priority: P3 · Confidence: Medium.**
→ `ARCHITECTURE.md` §8.4, `LESSONS_FOR_QAYD.md` **L-22**.

---

# Documentation corrections

## AIR-07 — Record the pgvector decision, its condition, and its triggers

**Answers R-02** (Tier 7, effort 5).

**Decision: pgvector in the primary database.** The decisive argument is tenant isolation, not
performance: QAYD's isolation is a database property (`NOT NULL company_id`, `FORCE ROW LEVEL SECURITY`,
a named restrictive policy, `IM-07`'s catalog test), and a separate store means a second implementation
of it with a different failure mode and no catalog test.

**Condition (a design constraint, not a preference):** only embed what has no exact key — judgements,
vendor description patterns, policy prose. **Do not embed raw document text**; the extracted record
supersedes it and is exactly queryable. This keeps the corpus in the low thousands per tenant.

**Sizing sanity check** `[INFERENCE]`, using `05_FUTURE_ARCHITECTURE.md`'s own assumption of 300
documents per customer per month:

| If we embed | Vectors / tenant / year | 10,000 tenants, 3 years |
|---|---|---|
| Raw document text at ~3 chunks/doc | ~11,000 | **~330 M** — exceeds pgvector by an order of magnitude |
| Judgements + precedents + patterns only | ~1,000–3,000 | **~30–90 M** at the top end, and it does not grow with document volume |

The first row is why the condition exists. The second is comfortable for pgvectorscale and marginal for
plain pgvector at the very top of the range — which is what the triggers are for.

**Revisit triggers — promote R-02 to a build item when any fires:**

| Trigger | Threshold |
|---|---|
| Vector row count | > 20 M rows in the embedding table |
| Filtered-search latency | p95 > 150 ms with the tenant predicate applied |
| Index build time | Exceeds the maintenance window on the production instance class |
| Recall under filter | recall@20 < 0.9 on a tenant-scoped golden set |

**Fallback if triggered:** pgvectorscale first (same database, same RLS, same backup, no new isolation
implementation), and only then a dedicated store — at which point tenant isolation in that store becomes
a first-class design problem requiring its own test, not an afterthought.

**Effort: 2** to record the decision · **Value: High · Priority: P1 · Confidence: Medium-High** — the
isolation argument is structural; the volume estimate depends on an assumption that could be wrong.

---

## AIR-19 — Correct the two cache constants in `05_FUTURE_ARCHITECTURE.md`

**What.** `05` §E states the minimum cacheable prefix is 4,096 tokens on **Opus 4.8** and Haiku 4.5.
Currently published: **Haiku 4.5 = 4,096** (unchanged — and it is the one that matters, since Haiku runs
the highest-volume shape), **Opus 4.8 = 1,024**, Sonnet 5 = 1,024, Opus 4.5/4.6 = 4,096, Opus 5 = 512.

**Why it matters.** Not because the conclusion changes — it does not — but because the constant **moves
per model release, silently, with no error raised.** The correct response is the one `05` already
prescribes: construct the prefix deliberately to clear the threshold, and **assert on
`cache_read_input_tokens` in an integration test** (AIR-06). This correction promotes that test from a
backstop to the primary control.

Correct the document **in place, with the date**, per its own maintenance rule.

**Effort: 1 · Value: Medium · Priority: P1 · Confidence: High.**

---

# Sequencing

```
 BEFORE S3-07 WRITES TRANSPORT
   AIR-02  ADR: no DB driver ──────────────┐  (a decision; 0 points)
                                           │
 S3-07  engine skeleton + transport        │
   AIR-01  closed capability enum ─────────┤
   AIR-04  egress allowlist ───────────────┤
                                           │
 S3-08  banking suggestions                │
   AIR-06  context budget + cache assert ──┤◄── AIR-19 (correct the constants)
   AIR-08  prompt versioning ──────────────┤
   AIR-09  structured rationale + enums ───┤
                                           │
 S3-09  accept/reject workbench            │
   AIR-05  correction capture ⚠ IRREVERSIBLE
   AIR-11  eval harness (code graders) ────┤
                                           │
 ─────────────────────────── sprint boundary ───────────────────────────
                                           │
 S4-01  proposal gateway + AutonomyResolver│
   AIR-18  capability config, not runtimes ┤
                                           │
 S4-02  extract pipeline                   │
   AIR-03  quarantined extraction ─────────┤
   AIR-13  three-tier retrieval ───────────┤◄── AIR-07 (pgvector recorded)
                                           │
 S4-04  decision review UI                 │
   AIR-14  approval instrumentation (start)┤
                                           │
 S4-10 / S4-11  copilot + cost governance  │
   AIR-12  loop budgets + give-up ─────────┤
   AIR-10  per-proposal cost record ───────┤
   AIR-17  bounded read-only copilot ──────┤
                                           │
 S4-12  MVP E2E                            │
   AIR-16  adversarial fixtures in CI ─────┘

 POST-LAUNCH (needs data)
   AIR-15  calibration  ◄── requires AIR-05 + AIR-14
   AIR-20  challenger dark run
```

**Effort summary**

| Tier | Items | Points |
|---|---|---|
| A — before/during Sprint 3 | 7 | **~27** (one is a zero-cost decision) |
| B — Sprint 4 | 6 | **~29** |
| C — after / triggered | 4 | **~21** |
| Documentation corrections | 2 | **3** |
| **Total** | **19** | **~80** |

`[INFERENCE]` Roughly 27 points land inside Sprint 3, which already carries a full plan. Most of it is
**acceptance criteria on stories that exist** rather than new stories — AIR-01, AIR-04, AIR-05, AIR-08
and AIR-09 are constraints on how S3-07/08/09 are built, not additional work items. The genuinely
additive pieces in Sprint 3 are AIR-06 (5) and AIR-11 (8). If Sprint 3 is tight, **AIR-11's harness can
slip to early Sprint 4 — but AIR-05 cannot slip at all**, because it is the only item whose value is
destroyed rather than deferred by waiting.

---

# Intake

Per `08_MASTER_BACKLOG.md` §0: *"No idea enters QAYD's plan without a tier, a value, a dependency list,
and a named sprint — or an explicit rejection with a reason."*

Each AIR item above carries all four. Promoting them means adding them to the master backlog's Tier 3 and
Tier 4 constraint tables — not maintaining this document as a parallel plan. **AIR-02 additionally
requires an ADR** before it can be implemented, because it refines a pattern in a frozen architecture
document.

# End of Document
