# 04 — Agent Anti-Patterns

**Sixteen ways to build an AI layer wrong, and the mechanism by which each fails · `docs/research/ai/`**

Version 1.0 · 2026-07-28

`04_REJECTED_PATTERNS.md` already refuses four AI-specific patterns — **R-31** (AI writing domain
tables), **R-32** (trusting model output without a confirmation boundary), **R-33** (prompts and rules
stored as executable code), **R-34** (an LLM where a rule suffices). Those are architectural refusals
about the *boundary*. This document is about the *engine* — the ways an AI layer that respects the
boundary can still be built badly.

Format follows the house style: what it is · why it is tempting · why it fails, mechanically · the
transferable form · what to do instead. Each names the QAYD story or document where it would first
appear, so it can be caught in review rather than in production.

---

## Index

| ID | Anti-pattern | First appears at |
|---|---|---|
| **A-01** | The unbounded loop | S3-07, S4-10 |
| **A-02** | Multi-agent for its own sake | `docs/ai/agents/*`, S4-01 |
| **A-03** | RAG as a reflex | S4-02, memory design |
| **A-04** | Trusting the model's confidence | S3-08, S4-01 |
| **A-05** | Unversioned prompts | S3-08 |
| **A-06** | Evals as an afterthought | S3-09 |
| **A-07** | The agent as an org chart | `docs/ai/agents/*` |
| **A-08** | Human-in-the-loop as a rubber stamp | S3-09, S4-04 |
| **A-09** | Context stuffing ("it has a million tokens") | S4-02, S4-10 |
| **A-10** | Prose rationale | S3-08, S4-04 |
| **A-11** | The classifier as a boundary | any injection discussion |
| **A-12** | Chat as the product | S4-10 |
| **A-13** | Benchmark-driven development | model selection |
| **A-14** | Framework-first | S3-07 |
| **A-15** | Silent degradation of the AI path | S4-11 |
| **A-16** | The eval set that never changes | post-launch |

---

## A-01 — The unbounded loop

**What it is.** An agent loop with no step counter, no token ceiling, no deadline, and no first-class
give-up outcome. It runs until it produces something, or until a timeout somewhere in the stack kills it
and the caller sees a 504.

**Why it is tempting.** A limit is an arbitrary number, and the first time a task legitimately needs
twelve steps and the limit is ten, the limit looks like the bug. It is also more work: a bounded loop
needs a *partial result* type, which means the caller needs to handle it, which means the API contract
grows.

**Why it fails.** Three independent mechanisms:

1. **Cost is unbounded per request.** Agents use ~4× the tokens of chat and multi-agent ~15×
   (`OVERVIEW.md` §2.2). Without a ceiling, one pathological input consumes an arbitrary fraction of a
   monthly budget. S4-11's `AiCostGovernor` cannot govern what it cannot bound; a per-request budget is
   the unit it needs.
2. **The failure mode is *persistence*, not error.** Answer.AI's finding was that the agent would "spend
   days pursuing impossible solutions rather than recognizing fundamental blockers" (`OVERVIEW.md`
   §11.1). Anthropic's own list — "Scouring the web endlessly for nonexistent sources", "continuing when
   they already had sufficient results" — is the same shape. **A model does not know it is stuck.**
3. **A timeout is not a give-up.** When the loop dies to infrastructure, the partial work is lost, the
   reason is unrecorded, and the rate of not-completing is invisible.

**Transferable form.** *Anything a model drives must be bounded by something a model does not control,
and exhausting the bound must be an outcome rather than an error.*

**Instead.** `BEST_PRACTICES.md` **B-08**. Step count, cumulative token spend, wall clock. Exhaustion
returns "could not complete" with partial state attached, recorded and surfaced. Rising give-up rate on a
task is the earliest signal its input distribution has shifted.

---

## A-02 — Multi-agent for its own sake

**What it is.** Decomposing a task into several concurrently-executing model-driven agents that
communicate, when the task neither requires parallel exploration nor tolerates inconsistent sub-results.

**Why it is tempting.** It is the most legible architecture in the field. It maps onto how humans
organise work, it demos beautifully, every framework has first-class support for it, and Anthropic
published a **90.2%** improvement from exactly this pattern (`OVERVIEW.md` §2.1). Refusing it looks like
being behind.

**Why it fails.** The 90.2% and the 15× token multiplier come from the *same* system, and the conditions
that produced the win are stated explicitly: "breadth-first queries that involve pursuing multiple
independent directions simultaneously". The conditions that produce failure are stated just as
explicitly: tasks requiring "all agents to share the same context or involve many dependencies between
agents" (`OVERVIEW.md` §2.3).

The discriminating question is **whether the sub-results must be mutually consistent**. Cognition's
framing is the sharpest: "Actions carry implicit decisions, and conflicting decisions carry bad results"
(`OVERVIEW.md` §2.4). Their worked failure — one subagent building a Mario-style background while another
builds an incompatible sprite — is exactly what happens when parts must fit and the parts were decided
separately.

**Accounting is the canonical must-be-consistent domain.** Lines must balance. Matches must not
double-consume. Postings must respect a period lock. A trial balance must tie. Every one of those is a
cross-sub-result invariant, and a coordination architecture whose members disagree is a machine for
producing entries that individually look fine.

**Transferable form.** *Concurrency between model-driven components is safe exactly when the components'
outputs never have to agree. Pay 15× for breadth; never pay it for coherence.*

**Instead.** One control loop, owned by code (`B-01`). Where context isolation is genuinely needed, use a
**synchronous sub-call that returns a value** (`B-09`) — the isolation benefit without the coordination
cost. If a genuinely breadth-first surface appears later (an audit-research query across five years),
adopt multi-agent *there*, price it at 15×, and nowhere else.

---

## A-03 — RAG as a reflex

**What it is.** Answering every retrieval question with embedding similarity, because that is what an AI
system does. Chunking documents, embedding chunks, top-k, stuff into prompt — applied uniformly,
including to questions with exact answers.

**Why it is tempting.** It is one mechanism that handles every question shape, so it looks like the
general solution. It requires no domain modelling. And it genuinely works well enough on a demo corpus
to seem finished.

**Why it fails.** Four mechanisms, in order of severity for QAYD:

1. **It replaces an exact answer with a probabilistic one.** "Which account does this vendor's packaging
   line post to?" has a row in `ai_categorization_rules` with a hit count of 34 out of 34. Answering it
   by similarity is `R-34` performed in the retrieval layer, and it imports every property `R-34`
   enumerates: lost reproducibility, verification by sampling instead of proof, a new injection surface,
   and a network dependency on a subtraction.
2. **Chart-of-accounts retrieval is the worst case for semantic search.** Chroma's context-rot work found
   that low needle-question similarity degrades steeply with length, and that **distractors compound
   sharply** (`OVERVIEW.md` §3.1). A chart of accounts is a set of dozens of *deliberately similar*
   options. That is a distractor-dense retrieval problem by construction.
3. **Naive chunking loses the context that makes a chunk meaningful**, which is why contextual retrieval
   exists at all and why it moves failure rates from 5.7% to 3.7% before any other change
   (`OVERVIEW.md` §5.1). Teams that skip the indexing work and blame the retriever are debugging the
   wrong layer.
4. **It is often unnecessary.** Below ~200,000 tokens of corpus, the published guidance is to skip
   retrieval and put the corpus in the prompt (`OVERVIEW.md` §5.1). A tenant's chart of accounts, posting
   policies and active judgements fit comfortably — and they belong in the *cached prefix*, at 0.1× input
   price, not in a vector index.

**Transferable form.** *Similarity search is for questions with no key. Every question with a key gets
the key.*

**Instead.** `B-04`'s three-tier cascade: exact structured lookup → applicability predicate in SQL →
embedding, hybrid and re-ranked, over a deliberately small corpus. Anthropic's own default points the
same way: "start with agentic search, and only add semantic search if you need faster results"
(`OVERVIEW.md` §9.3b).

---

## A-04 — Trusting the model's confidence

**What it is.** Treating the confidence number as a probability of correctness: routing on it, escalating
on it, sorting the review queue by it and assuming the bottom of the queue is safe, or — the version
`R-32` already refuses — auto-applying above a threshold.

**Why it is tempting.** It is right there, it is a number between zero and one, it correlates with
correctness on the eval set, and every product in the market displays one.

**Why it fails.** `R-32` gives the mechanism and it is worth restating in one line: **confidence is a
statement about the model's internal state, not about the world.** It is high on inputs resembling
training data and *stays high* on inputs that do not — so it "degrades most gently exactly where accuracy
degrades most sharply."

This document adds the constructive corollary that `R-32` does not state: **the failure is not that
confidence is meaningless, it is that it is unmeasured.** A confidence that has been calibrated against
outcomes — and shown, per bucket, to correspond to observed correctness — is a legitimate triage signal.
An uncalibrated one displayed next to a proposal is worse than nothing, because it directs a reviewer's
attention using a number nobody has checked.

There is also a second-order failure specific to a learning loop: if approval is faster for
high-confidence proposals *because* the confidence is shown, and approvals raise confidence, the system
learns from its own priors (`OVERVIEW.md` §4.3). Independence is the break.

**Transferable form.** *A self-reported score may rank; it may never authorise, and it may not even rank
until it has been measured against outcomes it did not influence.*

**Instead.** `B-11` (calibration measurement) paired with `B-12` (blind sampling), and escalation
triggered by **task properties** — a validation failure, an unmatched account, an ambiguity count, a
monetary threshold — never by the model's opinion of itself (`OVERVIEW.md` §8.5).

---

## A-05 — Unversioned prompts

**What it is.** Prompts assembled inline in application code, or stored in a configuration table, or
tuned in a vendor playground and pasted into production, with no identifier recorded on the output.

**Why it is tempting.** Prompt tuning is the fastest feedback loop in AI development, and any process
that slows it down feels like bureaucracy imposed on the one thing that is actually working. Storing
prompts as data also solves a real problem — per-tenant and per-jurisdiction variation.

**Why it fails.** `R-33` covers the security half (a stored prompt interpolated with untrusted content
is an injection vector; anything evaluating model output inherits its authority). The operational half is
independent and just as damaging:

- **A regression is unattributable.** `P-12` already argues this for `model_version`: without it, "an
  accuracy regression is unattributable and a bad model version is not a queryable cohort." A prompt
  change is at least as likely to cause a regression as a model change, and vastly more frequent.
- **Behaviour becomes unversionable.** "Why did the agent do that in March?" has no answer if the prompt
  in force was a mutable row — `R-33`'s consequence 4, stated there and worth re-noting because the
  operational cost lands on whoever is debugging, months later.
- **There is no rollback.** A model version can be pinned. An overwritten prompt is gone.

**Transferable form.** *Anything that changes system behaviour is a deployment, and every output must
name the version of every input that shaped it.*

**Instead.** `B-07`. Prompts as repository artefacts, compiled to a content hash, stamped on every
proposal alongside `model_id`/`model_version`, gated by the eval suite in CI. Per-tenant variation by
interpolating **data** into a **fixed template** — which is also what keeps the cached prefix stable
(`B-18`).

---

## A-06 — Evals as an afterthought

**What it is.** Shipping the AI feature, then "adding evals later" once there is time and once there is
production data to build them from.

**Why it is tempting.** The reasoning is superficially sound: you cannot build a good eval set before you
know what the failures look like, and the practitioner literature agrees that error analysis should come
first (`OVERVIEW.md` §9.4). So waiting seems principled rather than lazy.

**Why it fails.** It confuses two different things — *the eval set* and *the mechanism that captures the
labels*.

The eval set can indeed be built later. **The labels cannot be captured later.** Every rejected proposal,
every edited-then-accepted proposal, and every reversal is an expert-authored label produced once, at the
moment of the decision, and if the schema does not record *what* the human changed, the signal is gone
permanently. `08_MASTER_BACKLOG.md` **S3+A** states this exactly: "nearly free designed in now; expensive
to backfill." It is, if anything, understated — for the edit path it is not expensive to backfill, it is
impossible.

The second failure is that without an eval, a model upgrade is a risk event rather than a routine
operation, so it gets deferred, so the system runs on an old model, so cost and quality both suffer.

Anthropic's own sizing guidance removes the last excuse: "20-50 simple tasks drawn from real failures is
a great start" (`OVERVIEW.md` §9.3b). That is a day of work, not a quarter.

**Transferable form.** *Build the label-capture mechanism before the feature; build the eval set from the
labels afterwards. The order is not negotiable because one is reversible and the other is not.*

**Instead.** `B-17` and `IMPLEMENTATION_RECOMMENDATIONS.md` **AIR-05**. Land the correction-capture schema
in Sprint 3 with S3-09, not in Sprint 5. Grade with code-based checks over invariants QAYD already owns
(`B-10`) — the cheapest eval harness in this market, because the ground truth is in the database.

---

## A-07 — The agent as an org chart

**What it is.** Modelling the AI layer as a set of named personas with job titles — Accountant Agent,
Treasury Agent, Fraud Agent, CFO Agent — and then implementing each as its own runtime with its own loop,
its own prompt, its own tools and its own deployment, because that is what the product documentation
says exists.

**Why it is tempting.** It is genuinely excellent *product* vocabulary. Customers understand "the
Accountant Agent drafted this". It makes the roadmap legible, it maps onto a pricing model, and it gives
each capability an owner. `docs/ai/agents/*` uses it, correctly, for that purpose.

**Why it fails as engineering.** Because the persona metaphor smuggles in an *implementation* claim that
nothing requires. Read what the thirteen agents actually differ in:

| They differ in | Which is really |
|---|---|
| What data they may read | A **permission scope** |
| What actions they may propose | A **capability list** |
| How autonomous they may be | An **autonomy policy** (S4-01's `AutonomyResolver`) |
| What they are good at | A **prompt and an eval suite** |
| Which model tier they use | A **routing decision** |

Not one of those is a control loop. Thirteen personas require thirteen *configurations*; implementing
them as thirteen runtimes buys thirteen deployment surfaces, thirteen sets of drift, thirteen places for
the tenant-context helper to be forgotten (`IM-08`), and — if they ever call each other — every failure
mode in **A-02**.

There is a second, subtler cost. A persona invites *scope creep by anthropomorphism*: a "CFO Agent"
sounds like it should be able to do CFO things, and the question "should the CFO Agent be allowed to
approve the Accountant Agent's work?" is a question the architecture should make unaskable. `P-12`
already forbids the answer ("letting one AI approve another's proposal"), but the vocabulary keeps
generating the question.

**Transferable form.** *A persona is a product noun. A capability scope is an engineering noun. Do not
let the first one determine the process topology.*

**Instead.** One runtime, one control loop, thirteen **task-and-scope configurations** — each with its
own permission set, capability list, autonomy policy, prompt version and eval suite. The product may
continue to say "the Accountant Agent"; the deployment should not. See `LESSONS_FOR_QAYD.md` **L-03** and
`ARCHITECTURE.md` §4.

---

## A-08 — Human-in-the-loop as a rubber stamp

**What it is.** A review step that exists, is recorded, satisfies the architecture diagram, and does not
happen. Two hundred proposals, one button, the same place on the screen every time.

**Why it is tempting.** Nobody designs it deliberately. It is what a good review UI *becomes* under
volume, because the reviewer's incentive is throughput and the interface's incentive is to make approval
fast. Both are behaving correctly.

**Why it fails.** `P15` names it — "a human clicking approve on 200 proposals is not meaningfully
reviewing them" — and identifies the mitigation as a UX obligation. The failure runs deeper than UX:

1. **It invalidates the accountability claim, which is the load-bearing one.** `P15`'s second argument
   for the boundary is that "accountability requires a person" and that an approval column must hold a
   human user id because the legal structure demands it. A rubber stamp holds the user id and none of the
   accountability.
2. **It poisons every derived metric.** Approval rate becomes a measure of reviewer throughput.
   Calibration computed over those approvals is confidently wrong. The correction corpus loses its
   positives. Every number downstream inherits the bias.
3. **It is invisible without instrumentation.** There is no error, no alert, no complaint. The system
   reports 99% approval and appears to be working extremely well.
4. **It provides no defence against a targeted attack anyway** — an injected proposal is optimised to
   look correct, so even a *real* review may pass it (`OVERVIEW.md` §6.5). Review defends against error;
   the privilege boundary defends against attack. Conflating them causes both to be under-invested.

**Transferable form.** *A control that cannot be observed failing is not a control. Instrument the human
step or stop counting it.*

**Instead.** `B-12`: engagement above a materiality threshold, time-to-approve telemetry with an alarm
threshold, and a blind-sampled second-review stream that produces the only unbiased accuracy estimate the
system can generate. And be honest in the security model that approval is not an injection defence.

---

## A-09 — Context stuffing

**What it is.** Putting everything potentially relevant into the window because the window is large:
the full chart of accounts, twelve months of similar transactions, the whole vendor history, the entire
document, three policies, and every prior message.

**Why it is tempting.** Context windows are advertised in the hundreds of thousands of tokens and are
priced per token, so there is no error and no obvious signal. More context also *does* help, up to a
point, and the point is invisible.

**Why it fails.** The measurement is unambiguous: "Models do not use their context uniformly; instead,
their performance grows increasingly unreliable as input length grows" (`OVERVIEW.md` §3.1). The
mechanism is attention spread over n² pairwise relationships — an "attention budget" that depletes.

Two findings make stuffing specifically bad for accounting:

- **Distractors degrade performance even at n=1**, and compound at n=4. Additional plausible-but-wrong
  accounts and additional similar-but-different prior transactions are precisely distractors.
- **Shuffled haystacks outperformed coherent ones across all 18 models tested** — a result that should
  make anyone confident about "just give it more context" pause, because it means the effect is not
  simply about information content.

And there is a cost dimension: stuffed context that varies per request sits *after* the cache breakpoint
and is billed at full input price, every time.

**Transferable form.** *The target is "the smallest possible set of high-signal tokens", and every token
added without evidence is a token spent making the useful ones harder to find.*

**Instead.** `B-03`: a declared per-section context budget, a fixed assembly order, priority-ordered
truncation, and `truncated_sections` recorded so the tradeoff is visible. Then measure — raise a budget
only when an eval shows it helps.

---

## A-10 — Prose rationale

**What it is.** The model's explanation stored as a paragraph of natural language: *"I matched this
transaction to invoice 4471 because the amounts are similar and the vendor name appears in the
narrative."*

**Why it is tempting.** It reads beautifully. It is what a human reviewer says they want. It demos
better than a JSON object. And the model produces it without any additional design.

**Why it fails.** `P-12` already requires machine-readable JSONB and gives the reason — prose is
"unaggregatable, unreviewable at scale, and untestable". Three consequences worth spelling out:

1. **It cannot be regression-tested.** "Did the reasoning change?" is a diff over prose, which is noise.
   Over structured feature contributions it is a comparison.
2. **It cannot be aggregated.** "Which signal is driving our false positives?" is answerable over
   structured rationale and unanswerable over ten thousand paragraphs.
3. **It is persuasive independent of correctness.** A fluent explanation of a wrong match increases the
   chance a tired reviewer approves it. Prose rationale actively works against `B-12`.

A fourth, from this research: prose is **output tokens**, which are the expensive half of the bill
(`OVERVIEW.md` §8.6) — roughly 5× input at published ratios. A structured rationale is cheaper *and*
better, which is an unusually clean tradeoff.

**Transferable form.** *An explanation that cannot be diffed, aggregated or asserted on is decoration.*

**Instead.** Structured rationale — the rules that fired, the feature contributions with weights, the
matched tokens with offsets, the precedent rows cited by primary key. Render it as prose in the UI if
that is what reviewers prefer; **store it as structure**. This is also what makes `I-12` Number
Provenance reachable, since every contribution is already a dereferenceable row.

---

## A-11 — The classifier as a boundary

**What it is.** Deploying a prompt-injection detector (bought or built), measuring it at 95%+ on a test
set, and treating the remaining surface as protected.

**Why it is tempting.** It is the only mitigation that can be added *without changing the architecture*.
It produces a metric. It is what a vendor will sell you. And it does genuinely reduce attack volume.

**Why it fails.** The evidence is about as direct as security evidence gets:

- **EchoLeak (CVE-2025-32711, CVSS 9.3) bypassed Microsoft's XPIA classifier**, in a product built by a
  company with Microsoft's security resources, and exfiltrated data through an *allowlisted* channel
  (`OVERVIEW.md` §6.3).
- **Anthropic's own published post-mitigation attack success rate for Claude for Chrome is 11.2%**, on
  their own model, after classifiers and confirmations (`OVERVIEW.md` §6.3).
- The general form, from Willison: a guardrail claiming to stop 95% of attacks — "in web application
  security 95% is very much a failing grade."

The mechanism is that a classifier is a *probabilistic filter on an adversarial input distribution*. The
attacker iterates; the classifier does not. Its error rate against a determined adversary is not its
error rate on the test set.

**Transferable form.** *A probabilistic filter reduces volume. Only a privilege boundary reduces
consequence. Never let the first be counted as the second in a threat model.*

**Instead.** The layering in `ARCHITECTURE.md` §7: privilege first (GRANTs — `P-12` layer 1), control-flow
integrity second (`B-01`), egress removal third (`B-14`), quarantine fourth (`B-15`), value constraints
fifth (`B-06`), and a classifier **last, as telemetry** — useful for detecting that someone is trying,
never as the thing that stops them.

---

## A-12 — Chat as the product

**What it is.** Making a conversational interface the primary surface, on the theory that a chat box can
express any request and therefore covers every use case.

**Why it is tempting.** It is one UI for every feature, it requires no per-task design, it is what
everyone ships, and it demos as unlimited capability.

**Why it fails.** Four ways, in increasing severity for an accounting product:

1. **It maximises the attack surface.** A chat box over tenant data with any tool access is a
   general-purpose query engine with a natural-language front end. Every permission question becomes a
   runtime question ("should this user see payroll?") rather than a compile-time one. S4-10 already
   handles this correctly — "the agent's context is scoped to the asking user's own permissions" `[CODE]`
   — but the discipline has to hold for every future tool added to that surface.
2. **It maximises the eval surface.** A closed task has a golden set. An open chat box has a
   distribution nobody has characterised, which is precisely where τ-bench's pass^k result bites: agents
   that succeed under 50% of the time and **under 25% consistently across eight trials**
   (`OVERVIEW.md` §9.2).
3. **It sets an expectation the deterministic core cannot meet.** A user who has been invited to ask
   anything will ask for a number, and the honest answer to many number questions is a report, not a
   sentence.
4. **It is the wrong shape for the actual job.** Bookkeeping is a queue of decisions, not a
   conversation. The high-value surface is the review queue where a proposal is confirmed in seconds —
   which is exactly what S4-09's Command Center is.

**Transferable form.** *An open interface makes every capability question a runtime question. Prefer
surfaces whose input distribution you can enumerate.*

**Instead.** Keep the Copilot (S4-10) as a **read-only, permission-scoped, bounded** surface — which the
sprint already specifies — and invest the product weight in the decision queue. Note that S4-10 is
already designated a cut candidate in the sprint plan `[CODE]`; that instinct is correct.

---

## A-13 — Benchmark-driven development

**What it is.** Selecting models, and claiming quality, on the basis of public benchmark scores.

**Why it is tempting.** The numbers are free, comparable, and updated constantly, and the alternative —
building a domain eval — is work.

**Why it fails.** Three mechanisms:

1. **Benchmarks can be wrong for a year before anyone checks.** SWE-bench Verified exists because human
   annotators found a material share of the original tasks unsolvable or underspecified
   (`OVERVIEW.md` §9.1). Every comparison published before that inherited the flaw.
2. **Contamination.** Public benchmarks age into training data. A score improvement on a two-year-old
   benchmark is not evidence of capability improvement on your task.
3. **Single-run scores overstate reliability.** τ-bench's pass^k shows the gap directly: under 50%
   pass@1, under 25% pass^8. A leaderboard reports the first number.

And the most damaging version is not about models at all — it is applying the same reasoning to *your
own* system, reporting "97% accuracy" from a static internal set. `R-34` §2 already refuses this as an
auditor-facing claim about arithmetic; it is equally weak as an engineering claim about judgement,
because the number describes the eval set's distribution and not next month's.

**Transferable form.** *A benchmark measures a model on someone else's distribution. Model selection is
an experiment on your own.*

**Instead.** `B-10` and `B-17`. Use public benchmarks to shortlist candidates; decide with a run against
QAYD's own correction-derived golden set, per task, with the newly-seen-subject metric reported
separately.

---

## A-14 — Framework-first

**What it is.** Beginning with an agent framework and building the architecture inside its abstractions,
before there is a working direct-API implementation to compare against.

**Why it is tempting.** Frameworks provide retries, tracing, tool schemas, memory, streaming and a
hundred integrations. Building those is weeks of undifferentiated work.

**Why it fails.** Anthropic's own guidance is unusually blunt for a vendor: frameworks "often create
extra layers of abstraction that can obscure the underlying prompts and responses, making them harder to
debug", and "don't hesitate to reduce abstraction layers and build with basic components as you move to
production" (`OVERVIEW.md` §1).

The specific costs for QAYD:

- **The exact bytes sent to the model are the cache key.** A framework that assembles messages for you
  owns the cached prefix, and `05_FUTURE_ARCHITECTURE.md` already identifies a silent caching regression
  as the highest-frequency cost risk. Ceding control of prompt assembly to a dependency's minor version
  is ceding control of the largest cost lever.
- **Frameworks default to agency.** Their happy path is a tool-using loop where the model decides —
  exactly the property `B-01` refuses. Building against the grain of a framework is worse than not using
  it.
- **A trace is not a call stack.** Debuggability drops precisely where it matters most.

`apps/ai` currently depends on nothing but `fastapi` and `uvicorn` `[CODE]`
(`apps/ai/pyproject.toml`). That is a good position and it should be departed from deliberately.

**Transferable form.** *Do not adopt an abstraction over the thing you are optimising.*

**Instead.** Direct provider SDK, a thin internal pipeline (`ARCHITECTURE.md` §5), and libraries chosen
for one job each — schema validation, HTTP, observability — rather than one library that owns the
architecture.

---

## A-15 — Silent degradation of the AI path

**What it is.** When the model is unavailable, over budget, rate-limited, or returns something
unparseable, the system quietly proceeds without it: an empty suggestion list, a null confidence, a
default classification, a retry that eventually returns a lower-quality answer with no marking.

**Why it is tempting.** It keeps the product working. The AI is "optional", so degrading gracefully looks
like exactly the right engineering instinct — and for a *suggestion*, it often is.

**Why it fails.** This is `R-30` (silent degradation) in the AI layer, and the AI layer makes it worse in
two specific ways:

1. **The degraded output is indistinguishable from a confident one.** An empty suggestion list looks
   like "no match found", which is a *finding*. A default classification looks like a *decision*. The
   user cannot tell that the system did not try.
2. **It hides the outage from the metrics that would catch it.** Accuracy is computed over proposals that
   exist. If a failure mode produces *no proposal*, accuracy is unaffected and coverage silently drops.

S4-11 already specifies the correct shape — AI-only endpoints return `503`, AI-optional endpoints return
`200` with `meta.ai_suggestion: null` `[CODE]`. That is right, and the discipline to preserve is that
`null` must be a **distinguishable, recorded, counted** state, not an absence. "We could not evaluate
this" and "we evaluated this and found nothing" are different answers and must never render the same.

**Transferable form.** *Absence of a result and a result of "nothing" must be different values, at every
layer, including the UI.*

**Instead.** An explicit outcome enum on every AI-touched artefact — `proposed` / `no_candidate` /
`unavailable` / `over_budget` / `gave_up` — with counters on each and an alert on the non-`proposed`
rate. `B-08`'s give-up outcome is the same mechanism applied to loops.

---

## A-16 — The eval set that never changes

**What it is.** Building a golden set once, wiring it into CI, and treating a stable score as evidence
that the system is stable.

**Why it is tempting.** A frozen set is the only way to compare two versions fairly, which is a real and
correct requirement. Changing it breaks comparability. So it never changes.

**Why it fails.** The eval set describes the distribution it was drawn from, and the distribution moves —
new tenants, new vendors, new bank formats, new charts of accounts, a new fiscal year, a new market. And
the moving parts are exactly where the model is weakest: `R-32` §1 — confidence "is high on inputs
resembling training data and *stays high* on inputs that do not."

So the frozen set measures the system on the inputs it has already mastered, reports a stable high score,
and is structurally blind to the only failures that matter.

**Transferable form.** *A frozen eval set answers "did we break what worked?" It cannot answer "does this
work on what is arriving now?" Both questions need an owner.*

**Instead.** Two sets with two jobs (`B-17`):

- **A frozen regression set** — comparability across versions, gates CI. Changes only by deliberate
  versioned amendment.
- **A rolling recent set** — appended continuously from the last *n* weeks of corrections, reported as a
  separate headline number, with **accuracy on newly-seen subjects** broken out. A gap opening between
  the two is the drift signal, and it is the number to watch.

---

## Cross-cutting: how these compound

Anti-patterns in this domain are rarely independent. Three combinations are worth naming because each
produces a system that looks healthy from every dashboard:

**A-08 + A-04 + A-16 — the confident, stable, wrong system.** Reviewers rubber-stamp, so approval rate is
99%. Confidence is uncalibrated, so it is high. The frozen eval set contains only mastered inputs, so the
score is stable. Every metric is green and nobody has checked a number in months. *The break is `B-12`'s
blind sample: it is the only measurement in this list not conditioned on the others.*

**A-03 + A-09 + A-01 — the expensive, slow, plausible system.** Everything is embedded, retrieval returns
twenty marginally-relevant chunks, they all go into the window, the loop runs until it produces something.
Answers are fluent, cost is 4–15×, accuracy is untested. *The break is `B-03`'s budget: cost becomes
visible per task, and visible cost forces the retrieval question.*

**A-02 + A-07 + A-14 — the architecture that cannot be debugged.** Thirteen personas become thirteen
agents inside a framework whose abstractions hide the prompts. When an entry is wrong, the answer to "why"
is a distributed trace across four model calls with no call stack. *The break is `B-01`: if code owns the
control flow, there is a stack, and the org chart stays in the product copy where it belongs.*

# End of Document
