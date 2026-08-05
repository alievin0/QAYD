# 06 — Lessons for QAYD

**What the research changes, confirms, or contradicts in QAYD's existing plan · `docs/research/ai/`**

Version 1.0 · 2026-07-28

Twenty-two lessons. Each states the external finding, what it means for QAYD specifically, which existing
principle / pattern / innovation / story it touches, and whether it **confirms**, **extends**,
**corrects**, or **contradicts** what is already written.

The distribution matters and is worth stating up front: **most of what QAYD has already decided about its
AI layer is confirmed by the external evidence, sometimes strikingly so.** The boundary in `P15`, the
tiering in `05`, the deterministic-first ordering in `P-12`, the refusal of confidence thresholds in
`R-32` — these are all positions that the published engineering literature independently supports. There
are two genuine contradictions and four material extensions. Those are the ones to read.

---

## Index

| ID | Lesson | Verdict | Touches |
|---|---|---|---|
| **L-01** | Workflows before agents — and QAYD's task list is enumerable | **Confirms** | P15, `docs/ai/**` |
| **L-02** | Multi-agent is measurably wrong for consistency-critical work | **Extends** | A-02, `docs/ai/agents/*` |
| **L-03** | Thirteen agents are thirteen capability scopes, not thirteen loops | **Corrects** | `docs/ai/agents/*`, S4-01 |
| **L-04** | The engine should hold no database driver | **Contradicts** | P-12 vs P15 (G-8) |
| **L-05** | Control-flow integrity is a security property QAYD already has for free | **Extends** | R-31, R-33 |
| **L-06** | Prompt injection is a privilege problem, and QAYD's privilege model is right | **Confirms** | P15, P-12 |
| **L-07** | Sever the exfiltration leg — and it lives in the frontend | **Extends** | S3-07, S4-02 |
| **L-08** | Human approval is not an injection defence | **Extends** | P15, R-32 |
| **L-09** | Approval must be instrumented or it is not a control | **Extends** | P15, S3-09, S4-04 |
| **L-10** | Confidence must be calibrated, not merely recorded | **Extends** | R-32, P-12 |
| **L-11** | The correction corpus is the product's compounding asset | **Confirms** | I-09, S3+A |
| **L-12** | QAYD's ground truth makes the cheapest eval graders the best ones | **Extends** | P16, S3-09 |
| **L-13** | pgvector stays — conditionally, with a trigger | **Answers** | R-02 |
| **L-14** | Naive RAG would be a regression on the hot path | **Confirms** | R-34, ACCOUNTING_MEMORY |
| **L-15** | The judgement temporal filter must be SQL, not prompt | **Extends** | I-05 |
| **L-16** | Context is a budget, and distractors hurt more than length | **Extends** | 05 §E, P-12 |
| **L-17** | Two cache-minimum constants in `05` are stale — test, don't trust | **Corrects** | 05 §E |
| **L-18** | Batch and cache interact, and the default TTL is wrong for batches | **Extends** | 05 §E |
| **L-19** | Structured rationale is cheaper as well as better | **Extends** | P-12, I-12 |
| **L-20** | Escalate on task properties, never on self-reported confidence | **Confirms** | 05 §E, R-32 |
| **L-21** | Bound every loop; give-up is an outcome, not an error | **Extends** | R-30, S4-11 |
| **L-22** | The Challenger should ship dark and be measured before it is shown | **Extends** | I-10, X-04 |

---

## L-01 — Workflows before agents, and QAYD's task list is enumerable

**Finding.** Anthropic's own guidance is to prefer the simplest structure and add agency "only when it
demonstrably improves outcomes"; "for many applications, optimizing single LLM calls with retrieval and
in-context examples is usually enough" (`OVERVIEW.md` §1). Agency is appropriate for "open-ended problems
where it's difficult or impossible to predict the required number of steps."

**For QAYD.** Bookkeeping's task space is a finite, five-century-old list, and QAYD has already
enumerated it across thirteen agent documents and four workflow documents. When the task list is
enumerable, agency buys nothing and costs 4–15× in tokens plus reproducibility, debuggability and the
entire injection threat model.

**Verdict: confirms.** `P15`'s architecture is already a workflow architecture; this makes the reason
explicit and gives it external support. → `ARCHITECTURE.md` §1.

---

## L-02 — Multi-agent is measurably wrong for consistency-critical work

**Finding.** Anthropic measured multi-agent at ~15× the tokens of chat and named the poor-fit conditions
explicitly: tasks where "all agents share the same context or involve many dependencies between agents."
Cognition, from the opposite direction, concluded that "running multiple agents in collaboration only
results in fragile systems" and identified the mechanism — "Actions carry implicit decisions, and
conflicting decisions carry bad results" (`OVERVIEW.md` §2).

**For QAYD.** The discriminating question is whether sub-results must be mutually consistent. Accounting
is the canonical yes: lines must balance, matches must not double-consume, postings must respect a lock,
a trial balance must tie. Concurrent model-driven components whose outputs must agree is a machine for
producing individually-plausible, collectively-wrong entries.

**Verdict: extends.** Nothing in QAYD's plan calls for concurrent agents; nothing forbids them either.
This closes the gap with evidence rather than preference, and leaves the door open for one future case: a
genuinely breadth-first research surface, priced at 15×, adopted there and nowhere else.

---

## L-03 — Thirteen agents are thirteen capability scopes, not thirteen loops

**Finding.** `ANTI_PATTERNS.md` **A-07**. What the thirteen personas actually differ in is: readable data,
proposable actions, autonomy class, prompt, eval suite and model tier. Not one of those is a control loop.

**For QAYD.** `docs/ai/agents/*` is excellent product vocabulary and should stay. Implementing it as
thirteen runtimes would buy thirteen deployment surfaces, thirteen places to forget the tenant-context
helper (`IM-08`), and — if they ever call each other — every failure in L-02. One runtime with thirteen
`Capability` configurations delivers identical user-facing behaviour.

There is a second benefit that is easy to miss. The persona vocabulary generates a question the
architecture should make unaskable: *"may the CFO Agent approve the Accountant Agent's proposal?"*
`P-12` already forbids the answer. Capability scoping removes the question, because there is no CFO Agent
holding a credential — there is a capability, and capabilities do not approve things.

**Verdict: corrects** — a likely implementation reading of the product spec, not the spec itself.
→ `ARCHITECTURE.md` §4.

---

## L-04 — The engine should hold no database driver

**Finding.** `ARCHITECTURE.md` §3.2 in full.

**For QAYD.** There is a live contradiction between two documents in the frozen knowledge base:

- `P15` treats the absence of a DB driver in `apps/ai` as **real enforcement** and specifies a one-line
  CI grep to preserve it (Gap G-8, effort 1).
- `P-12` specifies a `qayd_ai` PostgreSQL role with `SELECT` on read models and `INSERT` on proposals,
  calling the GRANT matrix "THIS is the guarantee."

Both cannot be primary. If the engine holds a connection, G-8 must be deleted.

**Recommendation: no driver.** Retrieval becomes a Laravel-mediated read API. The reasons, ranked: G-8 is
the cheapest auditable enforcement in the system and cannot be misconfigured; it preserves control-flow
integrity by construction (L-05); it makes retrieval observable and rate-limitable; and the latency cost
is a single-digit-millisecond hop in front of a call that takes hundreds. `P-12`'s GRANT matrix should be
**retained as the documented fallback**, adopted in full if measurement ever demands it, never half-adopted.

**Verdict: contradicts.** This needs an ADR before S3-07 writes transport code, per MANIFEST Law 1.
→ `IMPLEMENTATION_RECOMMENDATIONS.md` **AIR-02**.

---

## L-05 — Control-flow integrity is a security property QAYD already has for free

**Finding.** Google DeepMind's CaMeL defeats prompt injection by extracting control and data flow from
the trusted query so "the untrusted data retrieved by the LLM can never impact the program flow" —
achieving provable security on 77% of AgentDojo tasks against 84% undefended success, i.e. a **7-point
utility cost for a formal guarantee** (`OVERVIEW.md` §6.4).

**For QAYD.** QAYD's control flow is genuinely fixed. "Extract this document", "score these candidates",
"draft an entry for this bill" are code paths chosen before the model runs. **QAYD does not need CaMeL's
machinery; it needs to not throw away the property CaMeL works hard to manufacture.** The rule:

> No value derived from untrusted content may determine which code runs, which tool is called, which
> tenant's data is read, or which row is written.

**Verdict: extends** `R-31` and `R-33`. Both already refuse the *consequences*; this names the *property*
and gives it a test — the pipeline must branch on no field of parsed model output except a validated
enum. Losing it is a security regression, not a refactor. → `ARCHITECTURE.md` §2 (Z3), §7 (L2).

---

## L-06 — Prompt injection is a privilege problem, and QAYD's privilege model is right

**Finding.** The lethal trifecta (private data + untrusted content + external communication); the
published residual attack-success rate of **11.2%** for Claude for Chrome *after* mitigations; EchoLeak
bypassing Microsoft's dedicated injection classifier; and Willison's assessment that a guardrail stopping
95% of attacks is "very much a failing grade" (`OVERVIEW.md` §6).

**For QAYD.** `R-31` §2 already states the correct conclusion in its strongest form:

> *"If the agent holds write credentials, then the ability to send a document to the customer is the
> ability to write their ledger. That is not a prompt-hardening problem; it is a privilege problem, and
> the only robust fix is to not hold the privilege."*

The external evidence does not improve on that sentence. What it adds is the **number to design against**:
not zero, but roughly one in nine, published by a frontier lab about its own model after mitigation.

**Verdict: confirms**, emphatically. QAYD's position was reached before the strongest supporting evidence
existed.

---

## L-07 — Sever the exfiltration leg, and note that it lives in the frontend

**Finding.** EchoLeak's exfiltration ran through an **allowlisted Teams image proxy** after the classifier
was defeated (`OVERVIEW.md` §6.3). The model did not need a general HTTP client; it needed a *renderer
that would fetch a URL it had authored*.

**For QAYD.** QAYD unavoidably holds legs 1 and 2 of the trifecta — it is a bookkeeping system that reads
supplier documents. Leg 3 is severable at near-zero cost, and it has **two halves in two different
codebases**:

1. **Backend.** The AI engine has no outbound route except an allowlisted model-provider endpoint,
   asserted by a test.
2. **Frontend.** The UI never renders a model-authored URL as a link, never fetches one, and never
   renders a model-authored image reference. Citations are **internal ids** that the trusted layer
   resolves to signed URLs — which S4-02 already specifies for documents; this generalises it to the only
   permitted citation shape.

**Verdict: extends** S3-07 (which mentions egress) and S4-02 (which already uses signed URLs). The
frontend half is the one nobody would think to write down, and it is the half that actually failed at
Microsoft. → `BEST_PRACTICES.md` **B-14**.

---

## L-08 — Human approval is not an injection defence

**Finding.** `OVERVIEW.md` §6.5. An injected proposal is optimised to look correct, so a genuine review
may still pass it. Review defends against *model error*; the privilege boundary defends against *attack*.

**For QAYD.** This distinction is absent from the current documents, and its absence is dangerous in a
specific way: it causes **both** controls to be under-invested. If approval is believed to cover
injection, the egress and quarantine work looks optional; if the privilege boundary is believed to cover
error, the review UX looks optional. They cover different threats and both are load-bearing.

**Verdict: extends** `P15` and `R-32`. Neither is wrong; the security model needs the sentence added.

---

## L-09 — Approval must be instrumented or it is not a control

**Finding.** `BEST_PRACTICES.md` **B-12**: engagement above materiality, time-to-approve telemetry, and a
blind-sampled second-review stream.

**For QAYD.** `P15` names reviewer fatigue as a real risk and calls the review UI "a UX obligation";
`R-32` names rejection sampling as a requirement of disciplined automation. Neither specifies the
instrument, and without one the failure is invisible: no error, no alert, no complaint, 99% approval, and
every downstream metric quietly poisoned (`ANTI_PATTERNS.md` **A-08**).

The blind stream is the important half and the one nobody builds. It is the only measurement in the
system **not conditioned on the reviewer having seen the model's opinion**, which makes it the only
unbiased accuracy estimate available — and therefore the only valid input to L-10's calibration curve.

**Verdict: extends.** ⚠️ Flagged as carrying a real product risk: it trades approval speed for approval
reliability, and whether users accept that trade is a design-partner question, not an engineering one.

---

## L-10 — Confidence must be calibrated, not merely recorded

**Finding.** `R-32` is right that confidence is "a statement about the model's internal state, not about
the world" and that it "degrades most gently exactly where accuracy degrades most sharply."

**For QAYD.** That second clause is a **testable claim**, and QAYD will have the data to test it. `P-12`
requires a confidence on every proposal; the constructive complement is to compute the reliability
curve — predicted confidence bucket versus observed correctness — per capability, per model version, per
prompt version, over the blind stream.

Three things become possible: reviewer attention can be directed by a number that has been shown to mean
something; a distribution shift shows up as a **calibration break before an accuracy drop**, which is the
earliest available warning; and `B-13`'s reversibility budget gets a defensible trust term.

Nobody in this market publishes calibration. It is the honest version of the accuracy claim every vendor
makes.

**Verdict: extends** `R-32` and `P-12` without weakening either — calibration never authorises anything.
→ `BEST_PRACTICES.md` **B-11**.

---

## L-11 — The correction corpus is the product's compounding asset

**Finding.** `OVERVIEW.md` §9.4. Rejected proposals, edited-then-accepted proposals, blind-sample
disagreements, refused postings and reversals are all expert-authored labels produced at zero marginal
cost in the ordinary course of work.

**For QAYD.** `S3+A` already rates this High / 3 points / P1 and notes it is "nearly free designed in now;
expensive to backfill." Two amendments:

1. **For the edit path it is not expensive to backfill — it is impossible.** If S4-04's
   edit-and-accept does not record *what changed*, the richest signal in the system (a negative, plus the
   correct answer, plus the location of the error) is lost permanently.
2. **The rating is low.** This is the compounding asset. A competitor can copy a feature; they cannot
   copy three years of a customer's corrections. It is also the same asset `I-05` identifies as the
   strongest retention idea in the innovation document.

**Verdict: confirms** `I-09` and `S3+A`, with a raised priority and one added acceptance criterion.

---

## L-12 — QAYD's ground truth makes the cheapest eval graders the best ones

**Finding.** Anthropic's published verification hierarchy: rules-based feedback first, then visual, then
an LLM judge — which they call "generally not a very robust method" (`OVERVIEW.md` §9.3b). LLM-judge
biases (position, verbosity, self-preference) are shared across judge populations, so ensembling does not
remove them.

**For QAYD.** This is the single most favourable finding in the entire research. The best-in-hierarchy
grader — deterministic rules — is exactly what an accounting system already owns: does it balance? does
the account exist and is it postable? is the period open? does the reconciliation over-consume? does it
equal the human's corrected version? **Almost every AI product must reach for a model judge because it
has no oracle. QAYD has an oracle in the database.**

The corollary is a discipline: the model judge is confined to explanation quality, validated against
human labels with precision and recall reported separately, and it never scores financial correctness.

The gap code graders cannot close is exactly the one `R-32` §4 names — "A proposal that balances,
references real accounts, and parses perfectly can still book a capital purchase to repairs expense." That
is what the sampled human stream (L-09) is for, and it cannot be dropped.

**Verdict: extends** `P16` into the AI layer. → `ARCHITECTURE.md` §10.

---

## L-13 — pgvector stays, conditionally, with a named trigger

**Finding and answer.** `OVERVIEW.md` §5.4 in full. Summarised: pgvector is comfortable to roughly 10M
vectors per node, pgvectorscale to roughly 50M, and beyond ~100M purpose-built engines are better; HNSW
index *build* is single-node and memory-bound and is the first wall, not query throughput; pgvector 0.8's
iterative index scans fixed the worst filtered-search failures at small and medium scale. Vendor
benchmarks on both sides are adversarially selected.

**For QAYD the decisive argument is neither performance nor cost.** QAYD's retrieval must be
tenant-isolated, and tenant isolation here is a *database* property — `NOT NULL company_id`, `FORCE ROW
LEVEL SECURITY`, a named restrictive policy, and a catalog-introspection CI test (`IM-07`). A separate
store means re-implementing that isolation in a second system with a different enforcement mechanism, a
different failure mode, no catalog test, and its own backup, retention, deletion and residency
obligations. **That is the same class of decision as "a second writer into the ledger", which the
architecture already refuses.**

**The condition:** only embed what has no exact key. Judgements, vendor description patterns, policy
prose — **not raw document text**, because the extracted structured record supersedes it and is exactly
queryable. This keeps the corpus in the low thousands per tenant rather than tens of thousands per tenant
per year, and it is what makes the scale argument hold.

**Verdict: answers R-02** (Tier 7, effort 5). → `IMPLEMENTATION_RECOMMENDATIONS.md` **AIR-07** for the
sizing arithmetic and the four revisit triggers.

---

## L-14 — Naive RAG would be a regression on the hot path

**Finding.** `ANTI_PATTERNS.md` **A-03**. Semantic search is weakest exactly where chart-of-accounts
mapping lives: distractor-dense category assignment with low needle-question similarity
(`OVERVIEW.md` §3.1). And below ~200,000 tokens of corpus, the published guidance is to skip retrieval
entirely (`OVERVIEW.md` §5.1).

**For QAYD.** `docs/ai/memory/ACCOUNTING_MEMORY.md` already reaches the right conclusion, describing
`ai_categorization_rules` as "a structured, non-embedding companion table built for the one thing
free-text semantic memory is comparatively slow and imprecise at." The lesson raises it from *companion*
to *first tier*: if a precedent row exists with sufficient support, **the AI is never called at all** —
which is the cheapest token, the fastest answer, and the only one that can be explained by pointing at a
row.

A tenant's chart of accounts, posting policies and active judgements fit comfortably under 200k tokens
and belong in the **cached prefix** at 0.1× input price, not in a vector index.

**Verdict: confirms** `R-34` and `ACCOUNTING_MEMORY.md`, with the tier ordering made explicit.
→ `BEST_PRACTICES.md` **B-04**.

---

## L-15 — The judgement temporal filter must be SQL, not prompt

**Finding.** `I-05` already states the risk exactly: *"a superseded rule still influencing the AI is a
silent, systematic error affecting hundreds of entries. `effective_from`/`superseded_by` must be enforced
in the retrieval query, not in a prompt."*

**For QAYD.** That sentence is correct and this research adds only the *mechanism that guarantees it*:
the judgement retrieval function is a SQL query in Zone 0 that **cannot return a superseded row**, with
the temporal predicate in the `WHERE` clause and a test that inserts a superseded judgement and asserts
it never appears in any retrieval result, for every capability. If the temporal filter lives anywhere in
the AI engine — even in Python — it is one refactor away from being a suggestion.

This is `P15`'s own argument ("the boundary must be structural, not behavioural") applied one layer in.

**Verdict: extends** `I-05` with an enforcement mechanism and a test. Note that I-05's third stated risk
— drift detection, "entries that violate an active judgement" — is what the Challenger produces (L-22),
so the two should be planned together.

---

## L-16 — Context is a budget, and distractors hurt more than length

**Finding.** Context must be treated "as a finite resource with diminishing marginal returns"; models
"do not use their context uniformly"; and — the operationally important part — **even a single distractor
reduces performance, four compound it**, while shuffled haystacks outperformed coherent ones across all
18 models tested (`OVERVIEW.md` §3.1–3.2).

**For QAYD.** A chart of accounts is a set of deliberately similar options: a distractor-dense retrieval
problem by construction. So the instinct "include more similar prior transactions so it has more to go
on" is measurably counterproductive past a small number.

The engineering response is a **declared per-section context budget with a fixed assembly order and
priority truncation**, which serves a second master: the cached prefix must be byte-identical across
requests or the cache silently misses, and `05_FUTURE_ARCHITECTURE.md` already names a silent caching
regression as the highest-frequency cost risk in the system. **Quality and cost point at the same
mechanism.**

**Verdict: extends** `05` §E and `P-12`. → `BEST_PRACTICES.md` **B-03**, `ARCHITECTURE.md` §5.2.

---

## L-17 — Two cache-minimum constants in `05` are stale — test, don't trust

**Finding.** `05_FUTURE_ARCHITECTURE.md` states: *"The minimum cacheable prefix is 4,096 tokens on Opus
4.8 and Haiku 4.5."* Currently published: **Haiku 4.5 = 4,096** (unchanged, and it is the one that
matters because Haiku runs the highest-volume shape), **Opus 4.8 = 1,024**, Sonnet 5 = 1,024,
Opus 4.5/4.6 = 4,096, Opus 5 = 512 (`OVERVIEW.md` §8.2).

**For QAYD.** The engineering conclusion is *strengthened*, not weakened. The constant moves per model
and per release, **silently, with no error raised** — a `cache_control` marker on an under-length prefix
simply does nothing and `cache_creation_input_tokens` returns 0. `05`'s own advice — construct the prefix
deliberately to clear the threshold, and assert on `cache_read_input_tokens` in an integration test — is
the correct response, and the **test is now the primary control rather than a backstop.**

**Verdict: corrects** two constants in `05` §E; confirms its method. Correct the document in place with
the date, per its own maintenance rule.

---

## L-18 — Batch and cache interact, and the default TTL is wrong for batches

**Finding.** The Batches API is 50% off, capped at 100,000 requests or 256 MB, expires at 24 hours (with
expired requests unbilled), retains results 29 days — and **caching combines with batching**, with the
documentation explicitly recommending the **1-hour** cache TTL for batches with shared context, because a
5-minute entry will likely expire mid-batch (`OVERVIEW.md` §8.4).

**For QAYD.** `05` §E treats batching and caching as independent levers worth ~6% and ~52% of the gap
respectively. They are not independent, and the interaction changes the nightly-extraction design
concretely: a nightly batch over one tenant's documents should write a **1-hour** cache entry for that
tenant's prefix and then run the batch. Using the 5-minute default inside a batch is a way to pay
1.25× for a write that never gets read.

Also worth carrying: `max_tokens: 0` cache pre-warming is **not supported inside a batch**, so warming
must happen outside it.

**Verdict: extends** `05` §E with a mechanism it does not describe.

---

## L-19 — Structured rationale is cheaper as well as better

**Finding.** Output tokens are priced at roughly 5× input at published Anthropic ratios
(`OVERVIEW.md` §8.6).

**For QAYD.** `P-12` already requires machine-readable JSONB rationale — "feature contributions, matched
tokens, the rules that fired — not prose" — on *reviewability* grounds: prose "cannot be aggregated,
diffed, or regression-tested."

The cost dimension is additive and unusually clean: structured rationale is **shorter output**, so it is
cheaper on the expensive half of the bill, *and* better for review, *and* regression-testable. Three
benefits, no tradeoff.

A fourth follows for free: every `precedents_cited` entry is a dereferenceable primary key, which means
`I-12` Number Provenance arrives as a side effect of doing rationale correctly rather than as a separate
project. `08_MASTER_BACKLOG.md` already flags I-12 as "cheap if designed in at first render;
near-impossible to retrofit" — this is one of the places it gets designed in.

**Verdict: extends** `P-12` with a cost argument and an `I-12` linkage.

---

## L-20 — Escalate on task properties, never on self-reported confidence

**Finding.** Published cascade results (RouteLLM ~85% cost reduction at ~95% of GPT-4 quality; FrugalGPT
up to 98%) are measured on **general chat benchmarks where quality is a preference judgement**
(`OVERVIEW.md` §8.5). QAYD's tasks have correct answers, and a router preserving 95% of preference-quality
may preserve much less accuracy — concentrated on exactly the unusual inputs where escalation mattered.

**For QAYD.** `05` §E's tier ladder already escalates on task properties — deterministic rules first,
then Haiku, then Sonnet, then Opus, with escalation on ambiguity and multi-document conditions rather
than on the model's opinion of itself. **That is the right answer and it should be defended**, because
"just route on confidence, it's simpler" will be proposed, and it re-introduces `R-32`.

**Verdict: confirms** `05` §E and `R-32`, and supplies the counter-argument to have ready.

---

## L-21 — Bound every loop; give-up is an outcome, not an error

**Finding.** Every failure in Anthropic's own published multi-agent failure list is a boundedness failure
rather than a reasoning failure — "Spawning 50 subagents for simple queries", "continuing when they
already had sufficient results". Answer.AI found the dominant cost of autonomy was the agent pursuing
impossible solutions for days rather than recognising a blocker (`OVERVIEW.md` §2.2, §11.1).

**For QAYD.** Two implications:

1. **The primary control on any iteration is a budget, not a better prompt.** Step count, cumulative
   token spend, wall clock. This is what makes S4-11's `AiCostGovernor` enforceable rather than
   approximate — a governor needs a bounded unit to govern.
2. **Exhaustion must be a first-class outcome, not an exception.** This is `R-30` (silent degradation) in
   a new place. "We could not evaluate this" and "we evaluated this and found nothing" are different
   answers and must never render the same. S4-11 already specifies the right shape for degraded mode —
   `503` for AI-only, `200` with `meta.ai_suggestion: null` for AI-optional — and the discipline to
   preserve is that `null` is a **recorded, counted, distinguishable state**.

**Verdict: extends** `R-30` and S4-11. → `BEST_PRACTICES.md` **B-08**, `ANTI_PATTERNS.md` **A-15**.

---

## L-22 — The Challenger should ship dark and be measured before it is shown

**Finding.** `X-04` in the backlog already sets the right gate: *"Does it find real errors, or generate
noise? Measure precision before shipping."*

**For QAYD.** The architecture makes that measurement nearly free, because a finding is an object with an
outcome, so precision is `confirmed_findings / total_findings` — a query. Therefore: **generate findings
for a month, show nobody, measure, then surface only if precision clears a threshold.**

The reason to insist on this is that a noisy Challenger is not merely useless, it is **negative**: it
trains users to dismiss alerts, which degrades every other alerting surface in the product, including the
ones that matter for `L-09`'s approval discipline. Alert fatigue is not recoverable by improving the
alert later.

Two further notes:

- The Challenger is a natural home for `I-05`'s **drift detection** ("entries that violate an active
  judgement"), which I-05 explicitly says is "part of the feature, not a follow-up." Planning them
  together turns two half-features into one.
- It runs on the **batch path** — it is not interactive, so it takes the 50% discount, which materially
  changes its cost case.

**Verdict: extends** `I-10` / `X-04` with a shipping protocol and a cost note.

---

## What the research did *not* change

Recorded because a research phase that changes everything is usually wrong about something.

| Position | Status |
|---|---|
| The AI may never write a financial table; the boundary is a database privilege (`P15`, `P-12`) | **Unchanged and independently supported.** `R-31` §2's privilege argument is the strongest sentence in QAYD's AI documentation. |
| No confidence threshold authorises anything (`R-32`) | **Unchanged.** Extended with calibration measurement, which never authorises. |
| Prompts and rules are never stored as executable data (`R-33`) | **Unchanged.** Extended with versioning-as-code. |
| Never an LLM where a rule suffices (`R-34`) | **Unchanged.** Extended into the retrieval layer (L-14). |
| Deterministic rules run first; the AI sees only the residual (`P-12`, `05`) | **Unchanged and strongly supported.** It is simultaneously the cost lever, the accuracy lever and the injection blast-radius lever. |
| Caching > tiering > batching for QAYD's prompt shape (`05` §E) | **Unchanged.** Two input constants corrected (L-17); one interaction added (L-18). |
| Immutability and correction-by-reversal (`P-13`) | **Unchanged**, and it is what makes rejected and reversed items usable as labels rather than lost. |

# End of Document
