# 03 — Best Practices for QAYD's AI Layer

**Practices worth adopting, each argued and costed · `docs/research/ai/`**

Version 1.0 · 2026-07-28

Evidence for every claim is in `OVERVIEW.md`; this document does not repeat it, it cites the section.
Anti-patterns — the practices worth refusing — are in `ANTI_PATTERNS.md`.

**Every practice below carries the full assessment block.** Effort is Fibonacci. Confidence is
High/Medium/Low with a stated reason. Where a practice conflicts with something already written in
`docs/ai/**` or the knowledge base, the conflict is named, not glossed.

---

## Index

| ID | Practice | Effort | Priority | Confidence |
|---|---|---|---|---|
| **B-01** | Make code, not the model, choose the control flow | 3 | P0 | High |
| **B-02** | Give every AI task a closed, named contract — no general agent endpoint | 5 | P0 | High |
| **B-03** | Assemble context deterministically, to a declared budget | 5 | P0 | High |
| **B-04** | Retrieve in three tiers: exact key → applicability predicate → embedding | 8 | P1 | High |
| **B-05** | Put reasoning before decision in every output schema | 1 | P0 | Medium-High |
| **B-06** | Constrain the value space, not just the type | 3 | P0 | High |
| **B-07** | Version prompts as code and stamp the version on every proposal | 3 | P0 | High |
| **B-08** | Bound every loop with a counter, a token budget, and a reported give-up | 3 | P1 | High |
| **B-09** | Use sub-agents as function calls for context isolation only | 5 | P2 | Medium |
| **B-10** | Grade with code first, humans second, a model judge last and only for prose | 8 | P1 | High |
| **B-11** | Measure calibration, not just accuracy | 5 | P1 | High |
| **B-12** | Make approval real: engagement, latency telemetry, blind sampling | 8 | P1 | Medium-High |
| **B-13** | Denominate autonomy in a reversibility budget consumed at action time | 8 | P2 | Medium |
| **B-14** | Sever the exfiltration leg: no egress, no rendered model URLs | 3 | P0 | High |
| **B-15** | Quarantine untrusted document text in its own context and its own call | 5 | P0 | High |
| **B-16** | Attribute cost per proposal and alert on cache-hit ratio | 3 | P1 | High |
| **B-17** | Build the golden set from corrections, split by tenant, appended continuously | 8 | P1 | High |
| **B-18** | Treat tool and schema changes as cache-invalidating deployments | 2 | P2 | High |

---

## B-01 — Make code, not the model, choose the control flow

**Practice.** In every AI task on the posting path, the sequence of operations, the choice of which data
to read, and the tenant scope are determined by trusted code before the model is invoked. The model
receives an assembled input and returns a value. It does not select a tool, does not compose a query, and
does not decide what happens next.

**Why.** This is the property CaMeL constructs artificially and QAYD gets for free (`OVERVIEW.md` §6.4).
"Extract this document", "score these candidates", "draft an entry for this bill" are code paths. The
moment the model chooses, untrusted content in its context can influence that choice, and the entire
injection threat model changes from *the model may produce a wrong value* (survivable — a human reviews
it) to *the model may take a different action* (not survivable — nobody reviews a read).

**Benefits.** Reproducible: same input, same code path, so a defect can be reproduced and a fix proven —
which `R-34` §1 identifies as the property probabilistic systems destroy. Debuggable: a trace is a
call stack, not a transcript. Cheap: no planning tokens, no tool-selection round trips. Testable with
ordinary integration tests rather than statistical ones.

**Tradeoffs.** Every new task shape requires code, so the system cannot "figure out" a task it was not
built for. That is a genuine capability ceiling and it should be stated plainly rather than pretended
away. The counter is that the tasks are known — this is bookkeeping, not open-ended research.

**Risks.** The pressure to add a general-purpose endpoint "just for internal testing" is the same
pressure `P15` identifies around the demo where the AI posts. Mitigate the same way: make the closed set
structural (an enum of task names validated at the transport boundary) rather than conventional.

**Scalability / performance.** Strictly better. No planning latency, no tool round trips.
**Maintainability.** Better — the control flow is readable in one file.
**Complexity.** Lower than the alternative.
**Effort: 3** (it is mostly a decision, enforced by the transport contract).
**Business impact.** This is the practice that makes the "structurally incapable of corrupting the books"
claim defensible to an auditor rather than aspirational.
**Confidence: High** — supported by CaMeL's formal result, by Anthropic's own "workflows before agents"
guidance, and by the fact that QAYD's tasks are enumerable.

---

## B-02 — Give every AI task a closed, named contract

**Practice.** The FastAPI engine exposes a fixed set of task names — `extract_document`,
`score_match_candidates`, `draft_journal_entry`, `answer_question`, `challenge_entry` — each with its own
typed request DTO, typed response DTO, prompt version, model tier, token budget, and eval suite. There is
no `POST /invoke {prompt: "..."}`.

**Why.** S3-07 already specifies `/internal/invoke` `[CODE]` (`docs/execution/SPRINT_03.md:146`). The
practice is to make that endpoint's payload a **discriminated union over a closed enum**, not an open
envelope. Anthropic's tool guidance says the same thing about tools — "a few thoughtful tools targeting
specific high-impact workflows, which match your evaluation tasks" (`OVERVIEW.md` §10.3) — and the
argument applies with more force to an internal RPC surface, where there is no reason for openness at all.

**Benefits.** Each task gets its own eval set, its own accuracy metric, its own cost line, its own model
tier, and its own rollback. "AI accuracy" stops being a single meaningless number. A task that regresses
is a queryable cohort.

**Tradeoffs.** Adding a task is a deployment on both sides of the contract. That is a feature: it forces
the eval set to exist before the task ships.

**Risks.** Contract drift between Laravel and Python. S3-07 already mandates "contract fixtures shared
both sides" `[CODE]` — that is the correct mitigation and should be extended to the response DTOs.

**Scalability.** Neutral. **Performance.** Neutral. **Maintainability.** Substantially better.
**Complexity.** Slightly higher up front, much lower at task five.
**Effort: 5.**
**Business impact.** Per-capability accuracy is what makes per-capability autonomy (S4-01's
`AutonomyResolver`) meaningful. Without it, autonomy is set on a vibe.
**Confidence: High.**

---

## B-03 — Assemble context deterministically, to a declared budget

**Practice.** Each task declares a `ContextBudget`: a hard ceiling per section (instructions, few-shots,
chart of accounts, judgements, precedents, the document) and a **fixed assembly order**. Assembly is a
pure function of (tenant, task, subject). Every collection is serialised with an explicit `ORDER BY`.
Overflow truncates the *lowest-priority* section and records that it truncated.

**Why.** Two independent reasons converge on the same mechanism.

1. **Quality.** Context is a budget with diminishing returns, and distractor density hurts more than
   length (`OVERVIEW.md` §3.1–3.2). An unbudgeted assembler grows monotonically because nobody ever
   removes anything.
2. **Cost.** The cached prefix must be byte-identical across requests or the cache silently misses, and
   `05_FUTURE_ARCHITECTURE.md` already names a silent caching regression as the highest-frequency cost
   risk in the system. Non-deterministic serialisation of database rows is the most likely cause.

**Benefits.** Cost predictability per task. Cache hit rates become an assertable property rather than a
hope. Truncation becomes an observable event instead of a silent quality loss.

**Tradeoffs.** Some genuinely useful context will be excluded by a budget. The answer is that the budget
is a per-task constant that can be raised deliberately with an eval to justify it — which is the point.

**Risks.** A budget set once and never revisited becomes a quality ceiling nobody remembers. Mitigate by
recording `truncated_sections` on the proposal and alerting when the truncation rate rises.

**Scalability.** Essential — this is what stops per-tenant cost from growing with tenant age.
**Performance.** Better; fewer tokens is lower latency.
**Maintainability.** Much better than an accreted prompt builder.
**Complexity.** Moderate.
**Effort: 5.**
**Business impact.** Directly protects the $14-per-customer figure in `05_FUTURE_ARCHITECTURE.md` §E.
**Confidence: High.**

---

## B-04 — Retrieve in three tiers: exact key → applicability predicate → embedding

**Practice.** Retrieval for any task follows a fixed cascade:

1. **Exact structured lookup.** `ai_categorization_rules` by (company, vendor, pattern). A row or nothing.
2. **Applicability predicate.** Active judgements (I-05) and policies whose conditions match, selected in
   SQL with the temporal filter (`effective_from <= :date AND superseded_by IS NULL`) **in the WHERE
   clause**.
3. **Semantic similarity.** Only for what tiers 1 and 2 could not supply, and only over a small embedded
   corpus (judgement text, vendor description patterns, policy prose).

Hybrid lexical+dense at tier 3, with re-ranking if measurement justifies it.

**Why.** Tier 1 is `R-34` in the retrieval layer: a question with an exact answer gets an exact lookup.
Tier 2 is the mechanism that makes I-05's stale-judgement risk structurally impossible rather than
prompt-dependent (`OVERVIEW.md` §4.2). Tier 3 is where semantic search actually earns its cost, and the
measured evidence says it should be hybrid and re-ranked when it is used at all (`OVERVIEW.md` §5.1–5.2).

Note that this ordering is also what Anthropic's Agent SDK guidance recommends by default — "start with
agentic search, and only add semantic search if you need faster results" (`OVERVIEW.md` §9.3b) — arrived
at from an entirely different direction.

**Benefits.** The hot path (vendor → account) never touches a vector index, so it is fast, exact,
explicable and free. The embedded corpus stays small, which is the precondition that makes the pgvector
answer to R-02 hold (`OVERVIEW.md` §5.4). Every retrieved item can be cited by primary key, which is what
I-12 Number Provenance needs.

**Tradeoffs.** Three mechanisms instead of one. More code. The counter is that tier 1 is a `SELECT` and
tier 2 is a `SELECT` — the complexity is concentrated in tier 3, which is the smallest.

**Risks.** The temptation to collapse it to "just embed everything and let similarity sort it out."
That is `ANTI_PATTERNS.md` **A-03** and it should be refused on record.

**Scalability.** Excellent — the expensive tier handles the smallest volume.
**Performance.** Tier 1 is sub-millisecond; the alternative is tens of milliseconds plus a model call.
**Maintainability.** Good, provided the cascade lives in one named component.
**Complexity.** Moderate.
**Effort: 8.**
**Business impact.** This is the difference between "AI that guesses your accounts" and "a system that
knows your accounts and asks when it doesn't."
**Confidence: High.**

---

## B-05 — Put reasoning before decision in every output schema

**Practice.** Response schemas are ordered `{ reasoning, evidence, decision, confidence }`. Never
`{ decision, confidence, reasoning }`.

**Why.** Generation is autoregressive; fields emitted earlier condition fields emitted later. A schema
that puts the decision first forces commitment before analysis and turns the reasoning field into
post-hoc rationalisation — which is worse than useless, because it is a *convincing* rationalisation
that a reviewer will read. `OVERVIEW.md` §10.2 gives the evidence, including the disputed but
directionally-credible finding that strict format constraints degrade reasoning partly through output
misordering. Anthropic's own ACI guidance says the same thing from the tool side: "Give the model enough
tokens to 'think' before it writes itself into a corner."

**Benefits.** Better decisions, and a rationale that actually reflects the process — which is what `P-12`
requires the rationale to be for.

**Tradeoffs.** Marginally more output tokens, which are the expensive kind. Real but small, and the
alternative is a rationale that misleads a reviewer, which is a correctness cost.

**Risks.** None identified.
**Scalability / performance / maintainability.** Neutral. **Complexity.** None.
**Effort: 1.** The cheapest item in this document.
**Business impact.** Improves the one artefact the entire human-in-the-loop design depends on.
**Confidence: Medium-High** — the mechanism is sound and the cost is zero; the magnitude of the effect is
disputed in the literature.

---

## B-06 — Constrain the value space, not just the type

**Practice.** Where a field's legal values are a known finite set, express it as an enum of that set in
the schema sent to the model — not as a string validated afterwards. Account codes come from the
tenant's postable accounts. Tax codes from the tenant's configured codes. Document ids from the ids
passed into the request. Dates from a bounded range.

**Why.** This is Anthropic's poka-yoke principle — "Change the arguments so that it is harder to make
mistakes" (`OVERVIEW.md` §10.3) — with a security dividend. A model that can only choose from a set it
did not author cannot hallucinate an account, and **cannot be instructed by an injected document to
reference something outside the set**, because the something-outside-the-set is not representable.

**Benefits.** An entire error class becomes impossible by construction rather than caught by validation.
Fewer round trips (no "invalid account" retry). Smaller output. And it converts a security control from
a check into a shape.

**Tradeoffs.** The enum is per-tenant, so it is part of the prompt and part of the cached prefix —
which is fine and is exactly what makes QAYD's prefix cache-friendly, but it means a chart-of-accounts
change invalidates that tenant's cache. Acceptable: charts change rarely.

**Risks.** Very large charts produce very large enums. Mitigate by scoping the enum to *plausible*
accounts for the document class where that is safely derivable, and by falling back to "none of these"
as an explicit, first-class answer rather than a failure.

**Scalability.** Fine. **Performance.** Better. **Maintainability.** Good. **Complexity.** Low.
**Effort: 3.**
**Business impact.** Directly reduces the reviewer's burden, which is the economic constraint on the
whole human-approval model.
**Confidence: High.**

---

## B-07 — Version prompts as code, stamp the version on every proposal

**Practice.** Prompts live in the repository as templates. The build compiles each to a content hash.
Every proposal, chat turn and extraction records `prompt_version` alongside the `model_id` /
`model_version` that `P-12` already requires. Prompt changes go through review, CI and the regression
gate. Per-tenant variation is achieved only by interpolating *data* into a fixed template.

**Why.** `R-33` already rejects prompts as mutable stored data. The constructive requirement is
attribution: `P-12`'s own argument for `model_version` — *"a regression becomes unattributable and
un-rollbackable"* — applies identically and independently to prompts. A prompt change is at least as
likely to cause a regression as a model change, and rather more likely to happen on a Friday afternoon.

**Benefits.** "Which proposals came from the prompt we rolled back?" becomes a `WHERE` clause. Prompt
A/B becomes possible without a bespoke mechanism. Auditability: "why did the agent do that in March?"
has an answer.

**Tradeoffs.** Tuning a prompt requires a deployment. That friction is intentional; the alternative is
`R-33`.

**Risks.** Engineers editing prompts without running evals. Mitigate by making the eval a required CI
check on the prompts directory, not a convention.

**Scalability / performance.** Neutral. **Maintainability.** Much better.
**Complexity.** Low. **Effort: 3.**
**Business impact.** Turns AI quality from an anecdote into a tracked metric with a rollback lever.
**Confidence: High.**

---

## B-08 — Bound every loop with a counter, a token budget, and a reported give-up

**Practice.** Wherever an iteration exists — the Copilot's tool loop, a retry on a validation failure, a
multi-page extraction — it carries a maximum step count, a maximum cumulative token spend, and a
wall-clock deadline. Exhausting any of them produces a **first-class "could not complete" outcome** with
the partial state attached, recorded and surfaced. It never silently returns a best guess.

**Why.** Every failure in Anthropic's own published list is a boundedness failure, not a reasoning
failure (`OVERVIEW.md` §2.2). Answer.AI's Devin evaluation found the dominant cost of autonomy was the
agent "spend[ing] days pursuing impossible solutions rather than recognizing fundamental blockers"
(`OVERVIEW.md` §11.1). Anthropic's own mitigation is effort heuristics written into a prompt — a budget
expressed as a suggestion. For a system with a cost governor (S4-11) it should be a counter.

**Benefits.** Cost becomes bounded per request, which is what makes S4-11's per-company budget
enforceable rather than approximate. Give-up becomes data: a rising give-up rate on a task is the
earliest available signal that its input distribution has shifted.

**Tradeoffs.** Some tasks that would have succeeded on step 21 fail at 20. Acceptable, and measurable —
if the give-up-at-limit rate is material, raise the limit deliberately with evidence.

**Risks.** Give-up implemented as an exception that gets swallowed. This is `R-30` (silent degradation)
in a new place; the outcome must be a value, not an error.

**Scalability.** Essential. **Performance.** Bounds tail latency, which is the one that hurts.
**Maintainability.** Good. **Complexity.** Low.
**Effort: 3.**
**Business impact.** Unbounded loops are the mechanism by which one pathological tenant consumes a
month's AI budget in an afternoon.
**Confidence: High.**

---

## B-09 — Use sub-agents as function calls, for context isolation only

**Practice.** Where a task must digest far more material than belongs in the main context — a
forty-page contract, a year of statements for an audit question — invoke a **synchronous, isolated model
call** that reads the material in its own window and returns a bounded summary (1,000–2,000 tokens, per
Anthropic's published figure). It is called by code, returns a value, and has no peers.

**Why.** This captures the one genuinely valuable property of the multi-agent literature — context
isolation — without any of the coordination fragility that Cognition documents and that Anthropic
concedes for dependency-dense work (`OVERVIEW.md` §2.5–2.6).

**Benefits.** Keeps the main context small, which §3 says is the binding constraint on quality. Bounds
the blast radius of a poisoned document: the injected instruction lands in a window that has no tools and
whose only output is text that will be treated as data.

**Tradeoffs.** Summarisation loses information, and in accounting a lost qualification produces a
plausible wrong number (`OVERVIEW.md` §3.4). Therefore: **the summary must cite, and the citation must be
followable.** A summary that says "the contract specifies quarterly billing" must carry the page and the
span, so the reviewer can check it. Without citation, do not use this pattern on the posting path.

**Risks.** Becoming a general-purpose delegation mechanism, i.e. multi-agent by accident. Guard by
keeping it a *named function with a typed return*, never a spawn.

**Scalability.** Good. **Performance.** Adds a serial model call.
**Maintainability.** Good. **Complexity.** Moderate.
**Effort: 5.**
**Business impact.** Enables document-heavy features (long contracts, multi-page statements) that would
otherwise be out of reach.
**Confidence: Medium** — the technique is well supported; the citation requirement is our addition and
has not been validated against a real corpus.

---

## B-10 — Grade with code first, humans second, a model judge last and only for prose

**Practice.** The eval harness grades in this order:

1. **Code-based graders over existing invariants.** Balanced? Account exists and is postable? Period
   open? Amount matches the source document field? Reconciliation does not over-consume? Matches the
   human's corrected version exactly?
2. **Human review**, on a sampled stream, for anything code cannot decide.
3. **A model judge**, confined to explanation quality, validated against human labels with precision and
   recall reported separately, with order randomised and identity masked.

**Why.** Anthropic's own stated hierarchy (`OVERVIEW.md` §9.3b) places rules-based feedback first and
calls LLM judging "generally not a very robust method." The documented judge biases — position,
verbosity, self-preference — are shared across judge populations, so ensembling does not remove them
(`OVERVIEW.md` §9.3). **And QAYD is in the rare position of having ground truth in the database**, which
is why almost every other AI product reaches for a judge and QAYD does not have to.

**Benefits.** Binary, reproducible, fast, free, and defensible to an auditor. "97% on our eval set" is a
weak claim; "the balance invariant is checked deterministically on every proposal" is a strong one.

**Tradeoffs.** Code graders can be brittle — Anthropic names exactly this anti-pattern, graders "too
rigid… as agents regularly find valid approaches that eval designers didn't anticipate." In accounting
that risk is lower than in open-ended domains because there genuinely is one right posting, but
"different but equally correct" does occur (account granularity, timing within a period) and the grader
must allow a defined equivalence class.

**Risks.** Grading only what is easy to grade. The invariant checks are necessary and not sufficient —
`R-32` §4: "A proposal that balances, references real accounts, and parses perfectly can still book a
capital purchase to repairs expense." Human sampling covers exactly that gap and cannot be dropped.

**Scalability.** Excellent. **Performance.** Fast enough for CI.
**Maintainability.** Good. **Complexity.** Moderate.
**Effort: 8.**
**Business impact.** An eval harness is what makes model upgrades a routine operation instead of a risk
event.
**Confidence: High.**

---

## B-11 — Measure calibration, not just accuracy

**Practice.** For each (task, model version, prompt version), compute and track the **reliability curve**
— predicted confidence bucket versus observed correctness — and a summary score (Brier or ECE), from
proposal outcomes. Publish it internally. Alert when calibration degrades even if accuracy has not.

**Why.** `P-12` requires a confidence on every proposal and `R-32` correctly refuses to let it authorise
anything. But an unmeasured confidence is worse than no confidence: it is displayed to a reviewer, it
influences their attention, and nobody knows whether it means anything. `R-32` §1 states the failure
precisely — confidence "degrades most gently exactly where accuracy degrades most sharply." **That
sentence is a testable claim, and QAYD will have the data to test it.**

**Benefits.** Three things become possible. (1) The reviewer's attention can be directed by a number that
has been shown to mean something. (2) A *distribution shift* — new bank format, new vendor, new chart —
shows up as a calibration break before it shows up as an accuracy drop, which is the earliest available
warning. (3) When the reversibility budget (B-13) needs a trust term, there is a defensible one.

**Tradeoffs.** Requires enough outcomes per bucket to be meaningful, so it is a Tier-2-onward metric for
low-volume tenants. Report it globally and per-tenant only where n justifies it.

**Risks.** Calibration measured on a biased positive set (fast approvals) will look excellent and be
false. This metric is only valid over the blind-sampled stream from B-12 — the two practices are a pair
and neither works alone.

**Scalability.** Improves with volume. **Performance.** Offline computation.
**Maintainability.** Good. **Complexity.** Moderate.
**Effort: 5.**
**Business impact.** No competitor publishes this. It is the honest version of the accuracy claim every
vendor in this market makes, and it is defensible in a procurement conversation.
**Confidence: High** on the method; **Medium** on when the data volume makes it meaningful.

---

## B-12 — Make approval real: engagement, latency telemetry, blind sampling

**Practice.** Three mechanisms, together:

1. **Engagement.** Above a materiality threshold, approval requires an act that cannot be performed
   without reading — confirming the amount, or selecting the account from the shortlist, rather than
   clicking one always-in-the-same-place button. Batch approval is permitted only within a homogeneous
   cohort, with an aggregate shown and a per-batch ceiling.
2. **Latency telemetry.** Record time-from-render-to-decision per proposal. Track the distribution per
   reviewer per task. A median below a couple of seconds on a multi-line entry is an operational alarm.
3. **Blind sampling.** A fixed random fraction — including high-confidence proposals — is routed to a
   second reviewer with confidence and rationale **hidden**. Disagreement on that stream is the only
   unbiased accuracy estimate the system can produce.

**Why.** `P15` names reviewer fatigue as a real risk and calls the review UI "a UX obligation"; `R-32`
names rejection sampling as a requirement of any disciplined automation. Neither specifies the
instrument. This is the instrument. The reason it matters is stated in `OVERVIEW.md` §6.5: human approval
defends against *error* but not against *attack*, and it defends against error only while it is real.

**Benefits.** Converts the single largest unmeasured assumption in the architecture — "a human reviewed
this" — into a measured one. Produces the unbiased label set that B-11 and B-17 both depend on. Provides
a defensible answer to an auditor asking what "approved" means.

**Tradeoffs.** This is the practice most likely to be resisted, and the resistance is legitimate: it
makes approval slower, and speed of approval is a headline product benefit. The honest framing is that
**QAYD is selling the reliability of the approval, not the speed of the click** — but that is a
positioning decision, not an engineering one, and it should be validated with a design partner before
the engagement mechanism is tuned aggressively.

**Risks.** Over-friction drives users to batch-approve everything, which is the failure it was meant to
prevent. Tie engagement to materiality, not to every proposal, and let the thresholds be per-tenant
configuration with an audited floor.

**Scalability.** Sampling rate can fall as volume rises while keeping the same statistical power.
**Performance.** Neutral. **Maintainability.** Good. **Complexity.** Moderate — mostly UI.
**Effort: 8.**
**Business impact.** High, and double-edged. It is the strongest trust claim available and the most
likely source of user friction.
**Confidence: Medium-High** on the necessity; **Medium** on the specific thresholds, which need real
usage to set.

---

## B-13 — Denominate autonomy in a reversibility budget consumed at action time

**Practice.** Implement I-17's reversibility budget with explicit units — monetary, count, window,
trust — consumed when an autonomous action is taken and restored only by an **event proving the action
was fine** (a reconciliation that held, a period that closed without adjustment), never merely by the
passage of time. The `AutonomyResolver` (S4-01) reads the budget; it does not own it.

**Why.** `08_MASTER_BACKLOG.md` already binds I-17 to S4-01 and specifies autonomy must be "per-tenant,
per-capability, and reversible — governed by a reversibility budget rather than a binary switch." The
missing engineering content is the units and the refill rule (`OVERVIEW.md` §7.2). A budget that refills
on a schedule regardless of outcome is a rate limit, not a safety mechanism.

**Benefits.** Autonomy can be increased incrementally with a bounded worst case, and the bound is a
number a CFO can be shown. A degrading model automatically loses autonomy through the trust term without
anyone noticing and intervening.

**Tradeoffs.** Considerably more complex than a boolean. Only worth building once there is a capability
genuinely safe to automate — which, given `P15`, is never the posting path and is plausibly bank matching
of exact-reference items (which is a *rule*, not AI, and therefore may auto-execute anyway).

**Risks.** Budget accounting becoming a second ledger with its own consistency problems. Keep it derived
from existing outcome records rather than separately maintained.

**Scalability.** Fine. **Performance.** Fine. **Maintainability.** Moderate.
**Complexity.** High relative to its immediate value.
**Effort: 8.**
**Business impact.** This is the mechanism by which QAYD could ever offer more automation without
abandoning `P15`. Strategically important, not urgent.
**Confidence: Medium** — the design is sound; whether any capability clears the safety bar in the next
two sprints is genuinely open.

---

## B-14 — Sever the exfiltration leg: no egress, no rendered model URLs

**Practice.** Two controls, both cheap:

1. **The AI engine has no outbound network route except an allowlisted model-provider endpoint.** No
   general HTTP client, no DNS to arbitrary hosts, enforced at the network layer, verified by a test that
   asserts an outbound request to an arbitrary host fails.
2. **The user interface never renders a model-authored URL as a link, never fetches one, and never
   renders a model-authored image reference.** Citations resolve through **internal identifiers** —
   document id, entry id — which the trusted layer turns into a signed URL. S4-02 already specifies
   "sources resolve to the source document via signed URL" `[CODE]`; this practice adds that the *only*
   permitted citation shape is an id.

**Why.** QAYD unavoidably holds legs 1 and 2 of the lethal trifecta (`OVERVIEW.md` §6.2). Leg 3 is
severable. EchoLeak's exfiltration ran through an allowlisted image proxy after the injection classifier
was defeated (`OVERVIEW.md` §6.3) — the model did not need a general HTTP client, it needed a *renderer
that would fetch a URL it had authored*. That is the actual channel and it lives in the frontend, not the
AI service.

**Benefits.** Closes the highest-severity documented attack class in this product category, at
approximately zero ongoing cost. It also makes the "no DB driver" story complete: an engine with neither
a database credential nor an egress route has, quite literally, nowhere to send anything.

**Tradeoffs.** Rules out any future feature where the AI fetches an external resource (a supplier's
website, a currency rate, a company registry). Those should be **trusted-side integrations** invoked by
code — which is what B-01 requires anyway.

**Risks.** The allowlist widening over time. Treat any addition as a security review, and prefer a
dedicated egress proxy over per-service configuration.

**Scalability / performance / maintainability.** Neutral. **Complexity.** Low.
**Effort: 3.**
**Business impact.** This is the control that makes the security section of an enterprise questionnaire
answerable with a mechanism instead of a policy.
**Confidence: High.**

---

## B-15 — Quarantine untrusted document text in its own context and its own call

**Practice.** Text extracted from a supplier document, a bank narrative or an email is:

- passed to the model as a **separate, constant-wrapped content block**, never templated into the system
  prompt (`R-33`);
- processed by a **dedicated extraction call whose only output is a typed record with no free-text
  passthrough** — every field is either a constrained value or a verbatim span with offsets;
- **never re-injected as text** into the downstream drafting call. The drafting call receives the typed
  record, not the document.

**Why.** This is Claude Code's "isolated context window" for fetched content (`OVERVIEW.md` §6.7),
applied to the exact analogue. The extraction call is the only place attacker-authored text is in a
context window; everything downstream reasons over a structured record produced under B-06's value
constraints. An injected instruction has one opportunity to influence one call whose entire output
surface is typed and allowlisted.

**Benefits.** The blast radius of a poisoned invoice is one extraction result, which a human reviews
against the source document. The injection cannot reach the drafting prompt, the Copilot, the memory
store, or another tenant.

**Tradeoffs.** Some quality is lost by not letting the drafting model see the original document text
(nuance in a line-item description, an unusual payment term). Mitigate by allowing verbatim spans with
offsets in the typed record — the text is carried, but as *quoted evidence with provenance*, in a field
the schema defines as data.

**Risks.** The "just pass the whole document through, it works better" pressure. It will work better on
the eval set and it re-opens the channel. Refuse it on record.

**Scalability.** Fine. **Performance.** One extra call on the extraction path, which is already batched.
**Maintainability.** Good — it is a pipeline stage, not a cross-cutting concern.
**Complexity.** Moderate. **Effort: 5.**
**Business impact.** This is the practice that lets QAYD honestly answer "what happens if a supplier
sends a malicious invoice?"
**Confidence: High.**

---

## B-16 — Attribute cost per proposal and alert on cache-hit ratio

**Practice.** Every model call writes a record keyed to the artefact it served, carrying model id, prompt
version, task name, `input_tokens`, `cache_read_input_tokens`, `cache_creation_input_tokens`,
`output_tokens`, latency and computed cost. Two derived alerts: `cache_read / total_input` below
threshold, and `cache_creation_input_tokens` persistently zero despite a `cache_control` marker.

**Why.** `05_FUTURE_ARCHITECTURE.md` already specifies those two alert conditions and names a silent
caching regression as the highest-frequency cost risk. This practice supplies the record they compute
over, and adds attribution: without a per-proposal key, cost is a monthly aggregate and a regression is
invisible until the invoice.

**Benefits.** S4-11's `AiCostGovernor` can enforce a spend budget rather than a request count.
Per-tenant gross margin becomes a query, which is a pricing input. A caching regression becomes a step
change in cost-per-proposal, detectable within hours.

**Tradeoffs.** One extra write per model call. Negligible next to the model call itself.

**Risks.** The cost record becoming a hot write path. It is append-only, tenant-scoped and small; batch
it if measurement says so.

**Scalability.** Fine — it grows with call volume and can be rolled up and pruned.
**Performance.** Negligible. **Maintainability.** Good. **Complexity.** Low.
**Effort: 3.**
**Business impact.** Directly enables S4-11, and turns the $14/customer figure from a projection into a
measurement.
**Confidence: High.**

---

## B-17 — Build the golden set from corrections, split by tenant, appended continuously

**Practice.** The eval corpus is assembled from the Correction Corpus (`S3+A` / I-09): rejected
proposals, edited-then-accepted proposals with the edit as the label, blind-sampled agreements and
disagreements, refused postings from `posting_attempts`, and reversals with their reasons. Splits are by
**tenant**, not by row. New corrections are appended continuously, and accuracy on **newly-seen
subjects** (new vendor, new bank format, new chart) is reported as a separate headline metric.

**Why.** `OVERVIEW.md` §9.4–9.5. Start at 20–50 tasks drawn from real failures, per Anthropic's own
sizing guidance — the effect sizes early on are large enough that a small set is informative.

**Benefits.** The eval improves automatically as the product is used, by the people best qualified to
label it, at zero marginal cost. Edited-then-accepted is the richest signal available anywhere because it
localises the error rather than merely flagging it.

**Tradeoffs.** Requires the edit path to record *what* changed, not just that something did. That is a
small addition to S4-04 and it must be made before the path ships, or the signal is lost permanently.

**Risks.** Tenant leakage (evaluating on a tenant's own precedents measures memorisation) and consent —
using one customer's corrections to evaluate behaviour is defensible; using them to train a model that
serves another customer is a contractual and possibly regulatory question. Keep evaluation and any future
training strictly separate, and note that R-01 in the backlog already flags the cross-tenant question.

**Scalability.** Improves with scale. **Performance.** Offline.
**Maintainability.** Good. **Complexity.** Moderate.
**Effort: 8.**
**Business impact.** This is the compounding asset. A competitor can copy a feature; they cannot copy
three years of a customer's corrections.
**Confidence: High.**

---

## B-18 — Treat tool and schema changes as cache-invalidating deployments

**Practice.** Changes to tool definitions, response schemas or the system-prompt template are batched,
released deliberately, and monitored for a cache-cost spike. The cached prefix order — `tools` →
`system` → `messages` — is fixed, and anything per-request sits after the last breakpoint.

**Why.** `[DOCS]` Changing tool definitions invalidates **all** cache levels; changing the system prompt
invalidates system and messages. With four breakpoints available and a per-tenant prefix, a careless
schema tweak re-writes every active tenant's cache at 1.25–2× input price (`OVERVIEW.md` §8.3).

**Benefits.** Avoids a class of cost spike that looks like nothing and shows up on the invoice.

**Tradeoffs.** Slower iteration on schemas. Small, and it aligns with B-07's release discipline anyway.

**Risks.** None beyond process discipline. Make it visible by having the cost alert from B-16 fire on the
release rather than relying on anyone remembering.

**Scalability / performance / maintainability.** Neutral. **Complexity.** None.
**Effort: 2.**
**Business impact.** Protects the largest single cost lever in the system.
**Confidence: High.**

---

## How these interlock

Several of these are only correct together. The dependencies worth knowing:

```
   B-01 code owns control flow
     ├──► B-02 closed task contracts ──► B-07 prompt versioning ──► B-10 eval harness
     │                                                                    ▲
     ├──► B-15 quarantined extraction ──► B-06 constrained values         │
     │           ▲                                                        │
     └──► B-14 no egress / no rendered URLs                               │
                                                                          │
   B-03 context budget ──► B-16 cost attribution ──► B-18 cache discipline│
                                                                          │
   B-04 three-tier retrieval ─────────────────────────────────────────────┤
                                                                          │
   B-12 real approval ──┬──► B-11 calibration                             │
                        └──► B-17 golden set from corrections ────────────┘
                                    │
                                    └──► B-13 reversibility budget (trust term)
```

Two pairs must not be separated:

- **B-11 and B-12.** Calibration computed over a biased positive set is confidently wrong, which is worse
  than not measuring.
- **B-15 and B-06.** Quarantine without value constraints leaks through the typed record; value
  constraints without quarantine leave the drafting prompt exposed.

# End of Document
