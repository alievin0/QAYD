# 02 — Overview: What the Field Actually Knows

**The engineering state of the art in AI agents, graded by evidence · `docs/research/ai/`**

Version 1.0 · 2026-07-28

This document is the evidence base. It reports what production systems demonstrably do, what has been
measured, and where the widely-repeated claim has no primary source. `BEST_PRACTICES.md` and
`ARCHITECTURE.md` build on it; neither repeats it.

**Reading rule.** Nothing here is a recommendation. Recommendations live in the next three documents.
This one is deliberately descriptive, because the most common failure in this field is adopting a
practice without knowing what evidence supports it.

---

## Contents

1. [The vocabulary problem — what "agent" actually denotes](#1)
2. [Agent architecture: single, orchestrated, and multi](#2)
3. [Context management](#3)
4. [Memory](#4)
5. [Knowledge retrieval](#5)
6. [Safety, decision boundaries and prompt injection](#6)
7. [Autonomous execution and what approval must mean](#7)
8. [Cost engineering](#8)
9. [Evaluation](#9)
10. [Prompt architecture, structured output, tool-call reliability](#10)
11. [What the products actually ship](#11)
12. [Summary table of load-bearing numbers](#12)

---

<a id="1"></a>
## 1 · The vocabulary problem — what "agent" actually denotes

The word carries at least four distinct meanings in current usage, and conflating them is the source of
most bad architecture decisions.

Anthropic's own taxonomy is the cleanest published one `[DOCS]`
(https://www.anthropic.com/engineering/building-effective-agents, Dec 2024):

| Term | Definition (verbatim where quoted) | Control flow |
|---|---|---|
| **Augmented LLM** | An LLM plus retrieval, tools and memory. The "basic building block". | Code |
| **Workflow** | "systems where LLMs and tools are orchestrated through predefined code paths" | Code |
| **Agent** | "systems where LLMs dynamically direct their own processes and tool usage" | **Model** |

The distinction that matters is the last column. **In a workflow, code decides what happens next. In an
agent, the model decides.** Everything about cost, latency, debuggability, reproducibility, security
posture and blast radius follows from that one bit.

A fourth meaning is purely commercial: "agent" as a *product persona* — a named role with a job title.
`docs/ai/agents/*` uses this sense (Accountant Agent, Treasury Agent, Fraud Agent, and ten more). That is
a legitimate product vocabulary and a bad engineering vocabulary, because it silently implies thirteen
control loops where the requirement is thirteen permission scopes. `LESSONS_FOR_QAYD.md` **L-03** treats
this in full.

**The published guidance is uniformly conservative about the third sense.** Anthropic:

> "You should consider adding complexity *only* when it demonstrably improves outcomes." `[DOCS]`

and

> "For many applications, optimizing single LLM calls with retrieval and in-context examples is usually
> enough." `[DOCS]`

They also warn specifically about frameworks: they "often create extra layers of abstraction that can
obscure the underlying prompts and responses, making them harder to debug", with the recommendation to
start against the raw API `[DOCS]`.

`[INFERENCE]` The industry's own vendors — who profit from token consumption — publish guidance telling
readers to use fewer tokens and simpler structures. That asymmetry is worth weighting heavily.

### 1.1 The six composable patterns

Named once here; referenced by number throughout `[DOCS]`.

| Pattern | Shape | Fits |
|---|---|---|
| **Prompt chaining** | Sequential steps with programmatic gates between them | Fixed decomposable tasks; latency traded for accuracy |
| **Routing** | Classify, then dispatch to a specialised handler | Distinct input categories that would otherwise degrade each other |
| **Parallelisation** | Sectioning (independent subtasks) or voting (multiple attempts) | Tasks benefiting from focused attention or diverse opinion |
| **Orchestrator-workers** | A central LLM decomposes, workers execute, orchestrator synthesises | Task structure unknowable until the input arrives |
| **Evaluator-optimiser** | Generator + critic in a loop | Clear evaluation criteria and demonstrable improvement from iteration |
| **Autonomous agent** | Model loops against environmental feedback | Open-ended problems, extended operation |

`[INFERENCE]` For QAYD: five of the six are workflows and are appropriate. The sixth is appropriate in
exactly one place (the Copilot) and inappropriate everywhere on the posting path.

---

<a id="2"></a>
## 2 · Agent architecture: single, orchestrated, and multi

This is the most consequential architectural choice, and it is the one where the evidence is best.

### 2.1 The case *for* multi-agent, measured

Anthropic's multi-agent research system `[DOCS]`
(https://www.anthropic.com/engineering/multi-agent-research-system, Jun 2025):

- A multi-agent system with **Claude Opus 4 as lead and Sonnet 4 subagents outperformed single-agent
  Claude Opus 4 by 90.2%** on their internal research evaluation.
- On BrowseComp, **token usage alone explains 80% of the variance** in performance; tool calls and model
  choice account for a further ~15%.
- Parallelisation "cut research time by up to 90% for complex queries".

That is a real, large, published gain. It should not be dismissed.

### 2.2 The cost, measured

From the same source `[DOCS]`:

- "agents typically use about **4× more tokens** than chat interactions"
- "multi-agent systems use about **15× more tokens** than chats"

And the stated economic precondition: multi-agent is viable only for "tasks where the value of the task
is high enough to pay for the increased performance."

Two further statements from the same post are load-bearing for any system that keeps records `[DOCS]`:

> "Agents are stateful and errors compound."

> "Agents make dynamic decisions and are non-deterministic between runs, even with identical prompts.
> This makes debugging harder."

Their own observed failure list is worth reproducing, because it is a catalogue of what unbounded agency
actually does: "Spawning 50 subagents for simple queries"; "Scouring the web endlessly for nonexistent
sources"; subagents that "misinterpreted the task or performed the exact same searches as other agents";
agents "continuing when they already had sufficient results"; agents that "consistently chose
SEO-optimized content farms over authoritative" sources `[DOCS]`.

`[INFERENCE]` Note what those failures have in common: **none is a reasoning failure.** They are all
resource-consumption and coordination failures — the model is not wrong, it is *unbounded*. That is a
strong argument that the primary control on an agent loop is a **budget**, not a better prompt.

Anthropic's own mitigation is instructive: effort-scaling heuristics written **into the orchestrator
prompt** — simple fact-finding gets "1 agent with 3-10 tool calls", direct comparisons "2-4 subagents
with 10-15 calls each", complex research "more than 10 subagents" `[DOCS]`. `[INFERENCE]` That is a
budget expressed as a suggestion. For a financial system it should be a budget expressed as a counter.

### 2.3 The explicit poor-fit conditions

Anthropic names them `[DOCS]`:

- "most coding tasks involve fewer truly parallelizable tasks than research"
- tasks requiring "all agents to share the same context or involve many dependencies between agents"

And the good-fit conditions: "heavy parallelization, information that exceeds single context windows",
"breadth-first queries that involve pursuing multiple independent directions simultaneously".

### 2.4 The counter-argument from a different domain

Cognition, building a coding agent, published the opposing conclusion `[COMMUNITY]`
(https://cognition.com/blog/dont-build-multi-agents, 2025). Two principles:

1. "Share context, and share full agent traces, not just individual messages."
2. "Actions carry implicit decisions, and conflicting decisions carry bad results."

Their headline claim: "running multiple agents in collaboration only results in fragile systems." Their
recommended architecture is a **single-threaded linear agent**, with a dedicated compression model for
long histories — which they concede "is hard to get right."

### 2.5 Reconciling the two

`[INFERENCE]` They do not actually disagree. Anthropic's win comes from *breadth-first search over
independent branches where the branches never have to agree*. Cognition's failure comes from *decomposing
one artefact into parts that must fit together*. The discriminating question is:

> **Do the sub-results have to be mutually consistent?**
>
> If no → parallel subagents are a good trade at 15× tokens.
> If yes → they are a fragility generator, and the tokens buy you disagreement.

Accounting is unambiguously the second case. A journal entry's lines must balance. A reconciliation's
matches must not double-consume a transaction. A period's postings must respect a lock. A trial balance
must tie. Every one of those is a mutual-consistency constraint across sub-results.

`[INFERENCE]` The correct QAYD reading is therefore: **the multi-agent evidence is real and does not
apply to us on the posting path.** It may apply to one future surface — a research-shaped question like
"gather everything relevant to this audit query across five years" — and if so, it should be adopted
there specifically, priced at 15×, and nowhere else.

### 2.6 What the orchestrator pattern is actually for

`[INFERENCE]` The valuable, uncontroversial residue of the multi-agent literature is **context isolation**,
not parallelism. A subagent that reads 200,000 tokens of noisy material and returns a 1,500-token summary
has done something a single agent cannot: it has kept 198,500 tokens out of the main context. Anthropic
describes exactly this — sub-agents returning "condensed summaries (typically 1,000-2,000 tokens)" with
"the detailed search context remain[ing] isolated within sub-agents" `[DOCS]`.

That benefit is available **without** any of the coordination fragility, because the subagent is invoked
synchronously by code, returns a value, and has no peers to disagree with. It is a function call, not an
organisation.

---

<a id="3"></a>
## 3 · Context management

### 3.1 Long context degrades — this is measured, not folklore

Chroma's *Context Rot* technical report `[INDEPENDENT]`
(https://www.trychroma.com/research/context-rot, 2025) evaluated **18 models** across Anthropic, OpenAI,
Google and Alibaba families on deliberately-controlled variants of needle-in-a-haystack.

Headline: **"Models do not use their context uniformly; instead, their performance grows increasingly
unreliable as input length grows."**

The specific findings are more useful than the headline:

| Manipulation | Finding |
|---|---|
| **Needle–question semantic similarity** (0.445–0.829) | Lower-similarity pairs degrade *steeply faster* with length. Exact-match lookups survive long context far better than semantic ones. |
| **Distractors** (0, 1, 4 topically-similar wrong answers) | Even a *single* distractor reduces performance; four compound it. |
| **Haystack structure** (coherent prose vs shuffled sentences) | **Shuffled haystacks outperformed coherent ones across all 18 models** — a genuinely counter-intuitive result. |
| **LongMemEval** (focused ~300 tokens vs full ~113k) | Large gap between the two; Claude Opus 4 showed a 2.89% refusal rate. |
| **Repeated words** | Accuracy highest when the unique item appears early; models under- and over-generate past ~2,500 words. |
| **Model families** | Claude models: lowest hallucination, highest abstention. GPT models: hallucinate most confidently. |

`[INFERENCE]` Three operational consequences for a financial system:

1. **Distractors are the dominant risk, not length.** A chart of accounts contains dozens of accounts
   that are plausibly similar to the right one. That is a distractor-dense retrieval problem by
   construction, and the finding says distractor density hurts more than raw token count.
2. **Prefer exact-match retrieval where an exact key exists.** The similarity finding says semantic
   retrieval is the *fragile* mode under length. Vendor name → account code is an exact key.
3. **The abstention/hallucination split is a model-selection input.** For a system where a wrong number
   is worse than a refusal, the family that abstains is the right default. This is a rare case where a
   published behavioural difference maps directly onto a business requirement.

Related and older: *Lost in the Middle* (Liu et al., TACL) established that models attend better to the
beginning and end of long inputs than the middle `[PAPER]`. `[UNKNOWN]` — we did not re-verify its
figures in this pass; the Chroma report supersedes it in scope.

### 3.2 Context as a budget, not a container

Anthropic's *Effective Context Engineering for AI Agents* `[DOCS]`
(https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents, 2025) frames the
discipline:

> "Context, therefore, must be treated as a finite resource with diminishing marginal returns."

> LLMs have an **"attention budget"** they draw on when parsing large volumes of context.

The stated optimisation target:

> "the smallest possible set of high-signal tokens that maximize the likelihood of some desired outcome."

The mechanical reason is architectural: attention is n² in sequence length, so "every token to attend to
every other token" spreads a fixed representational capacity thinner as the sequence grows.

### 3.3 The named techniques

| Technique | What it is | Anthropic's stated caveat |
|---|---|---|
| **System-prompt altitude** | Between "hardcoding complex, brittle logic" and "vague, high-level guidance". Specific enough to guide, flexible enough to leave heuristics. | Both extremes fail; this is a calibration, not a rule |
| **Tool design** | "self-contained, robust to error, and extremely clear with respect to their intended use" | Failure mode is "bloated tool sets… ambiguous decision points about which tool to use" |
| **Few-shot examples** | "the 'pictures' worth a thousand words" | Do not stuff "a laundry list of edge cases" |
| **Just-in-time retrieval** | Agent holds lightweight identifiers (paths, queries, links) and loads at runtime | Explicitly analogised to human external memory |
| **Compaction** | Summarise history near the limit, reinitialise from the summary | Tune for recall first, then precision |
| **Structured note-taking** | Persist notes outside the window, re-read later | "allows the agent to track progress across complex tasks" |
| **Sub-agent isolation** | Focused agent, clean window, returns 1,000–2,000 tokens | "clear separation of concerns" |

`[INFERENCE]` For QAYD, only three of these are relevant, and one is dangerous:

- **System-prompt altitude** — relevant, and the altitude for accounting is *low* (specific rules,
  because the domain has specific rules).
- **Few-shot examples** — highly relevant, and doubly so because they sit in the cached prefix and
  therefore cost ~0.1× after the first call (§8).
- **Sub-agent isolation as a function call** — relevant for document-heavy work (§2.6).
- **Just-in-time retrieval where the agent chooses what to load** — *dangerous*, because "the agent
  chooses" is exactly the control-flow property that prompt injection subverts (§6). QAYD wants
  just-in-time assembly performed by **code**, not by the model.

### 3.4 Compaction is a correctness hazard in an accounting context

`[INFERENCE]` Compaction summarises. A summary of a financial conversation may silently drop a
qualification ("…but only if the invoice is pre-payment"). In a coding agent, a lost detail produces a
compile error. In an accounting agent, it produces a plausible wrong number. QAYD's tasks are short
enough that compaction should not be needed; if a task needs compaction, that is a signal the task was
scoped wrong.

---

<a id="4"></a>
## 4 · Memory

The field uses "memory" for at least five different things. Distinguishing them is most of the work.

| Kind | Horizon | Typical store | Retrieval |
|---|---|---|---|
| **Working / short-term** | One task | The context window | None — it is the window |
| **Episodic** | Across sessions | Event log | By recency / by key |
| **Semantic** | Durable facts | Structured table or vector index | Exact or similarity |
| **Procedural** | How to do things | Prompts, tools, code | Loaded by task type |
| **Parametric** | Baked into weights | The model | Implicit — fine-tuning |

### 4.1 When to use which — the decision that is usually made wrong

`[INFERENCE]`, informed by the retrieval evidence in §5:

| If the fact… | Store it as | Not as |
|---|---|---|
| Has an exact key and an exact answer | A structured row, looked up by key | An embedding |
| Has an applicability *condition* | A predicate evaluated in SQL | A prompt instruction |
| Is a paraphrase of prose a human wrote | An embedding, plus the structured predicate that gates it | An embedding alone |
| Is a behaviour you want the model to have always | A prompt in version control | A fine-tune |
| Is a *style* you cannot express in a prompt after honest effort | Possibly a fine-tune | A longer prompt |

**Fine-tuning is almost never the right answer for facts.** It is expensive, it is un-auditable ("which
training example caused this?" has no answer), it cannot be superseded on a date, and it cannot be
tenant-scoped without training one model per tenant. For a system whose core product claim is that every
number is explicable, parametric memory is structurally the wrong container.

### 4.2 "Accounting memory" is not retrieval — it is precedent with governance

`[INFERENCE]`, connecting to `07_QAYD_INNOVATION.md` **I-05**.

The generic agent-memory literature is about remembering *facts* and *preferences*. QAYD's requirement is
categorically different: it must remember **why a judgement was made, by whom, on what basis, what it now
governs, and whether it still holds.**

I-05 already specifies this as a first-class `judgements` entity with `effective_from` / `superseded_by`
/ `supersedes`, bound by foreign key rather than by memo text, and explicitly notes the failure mode: *"a
superseded rule still influencing the AI is a silent, systematic error affecting hundreds of entries.
`effective_from`/`superseded_by` must be enforced in the retrieval query, not in a prompt."*

That sentence is the whole engineering content of "AI memory" for QAYD, and it is already written.
`[INFERENCE]` What this research adds is the *mechanism* by which that enforcement is guaranteed rather
than intended:

> The judgement retrieval function must be a **SQL query that cannot return a superseded row**, in the
> trusted zone, with the temporal predicate in the `WHERE` clause and a test that inserts a superseded
> judgement and asserts it never appears in a retrieval result. If the temporal filter is anywhere in the
> AI engine — even in Python — it is one refactor away from being a suggestion.

This is the same argument as `P15`'s "boundary must be structural, not behavioural", applied one layer in.

### 4.3 The learning loop, and its one real danger

`docs/ai/memory/ACCOUNTING_MEMORY.md` specifies confidence accrual from approvals and decay from
overrides, with the explicit rule that "no single correction is allowed to instantly and irreversibly
overwrite an agent's established behavior."

`[INFERENCE]` The danger the spec is right to guard against, stated in its general form: **a learning loop
whose training signal is its own output is a feedback oscillator.** If a high-confidence proposal is
approved quickly *because* it is high-confidence, and that approval raises the confidence, the system
learns from its own priors. The break is independence: confidence must be updated from outcomes that were
*not* conditioned on the confidence being shown. This is the same independence requirement as `R-32`'s
"a second model call is neither", and it is the reason blind rejection sampling (§7.3) is not optional.

---

<a id="5"></a>
## 5 · Knowledge retrieval

### 5.1 Why naive RAG underperforms — measured

Anthropic's *Contextual Retrieval* `[DOCS]`
(https://www.anthropic.com/news/contextual-retrieval, Sept 2024) gives the cleanest published numbers on
the chunking failure and its fix. Baseline is top-20-chunk retrieval failure rate:

| Configuration | Failure rate | Reduction vs baseline |
|---|---|---|
| Standard embeddings (baseline) | **5.7%** | — |
| + Contextual embeddings | **3.7%** | **35%** |
| + Contextual embeddings **and** contextual BM25 | **2.9%** | **49%** |
| + Reranking on top of both | **1.9%** | **67%** |

Other reported findings from the same source:

- Cost to generate contextualised chunks with prompt caching: **$1.02 per million document tokens**.
- Passing **20 chunks** outperformed 5 or 10.
- "Chunk boundaries, size, and overlap significantly affect retrieval outcomes."
- **Below ~200,000 tokens (~500 pages), skip RAG entirely** and put the corpus in the prompt, especially
  with caching.

`[INFERENCE]` Three readings that matter more than the headline:

1. **Naive RAG's failure mode is context loss at chunk boundaries.** The fix — prepending a short
   generated description of what the chunk is and where it sits — is a *pre-processing* fix, not a
   retrieval-algorithm fix. The lesson generalises: most RAG quality problems are indexing problems.
2. **Hybrid beats dense alone, everywhere.** Adding lexical BM25 to dense embeddings improved on dense
   alone in every configuration. For a domain full of exact tokens — invoice numbers, IBANs, account
   codes, vendor legal names — lexical matching is not a fallback, it is the primary signal.
3. **The 200k-token escape hatch is real and under-used.** A tenant's entire chart of accounts, its
   posting policies, and its active judgements will comfortably fit under 200k tokens. For that corpus,
   the correct architecture is *no retrieval at all* — put it in the cached prefix.

### 5.2 Re-ranking

The 5.7% → 1.9% figure above is the strongest published evidence for re-ranking `[DOCS]`. `[INFERENCE]`
The mechanism is that first-stage retrieval optimises for recall cheaply and is therefore imprecise;
a cross-encoder scoring query and candidate *jointly* is far more accurate and far more expensive, so it
is affordable only over a shortlist. That is a classic two-stage IR design and it is not novel — the
novelty is only that people building LLM applications frequently skip the second stage.

### 5.3 Where semantic search *loses*

`[INFERENCE]`, supported by §3.1's similarity finding:

- **Exact identifiers.** "Find the entry with reference `INV-2026-04471`." A `WHERE` clause is exact,
  instant, free, and cannot be talked out of its answer. An embedding search is none of those. This is
  `R-34` restated in the retrieval layer.
- **Numeric and range predicates.** "All invoices over KWD 5,000 in Q2 from vendors in the GCC."
  Embeddings do not encode magnitude reliably.
- **Anything with a foreign key.** If the relationship is modelled, traverse it.
- **Distractor-dense category assignment.** §3.1's finding says this is where semantic retrieval is
  weakest, and chart-of-accounts mapping is exactly that shape.

### 5.4 R-02 — pgvector in the primary database vs a separate vector store

This is an open question in `08_MASTER_BACKLOG.md` (Tier 7, R-02, effort 5). Here is the evidence and the
answer.

**Evidence for pgvector at QAYD's scale** `[DOCS]`/`[COMMUNITY]`:

- pgvector 0.8 added **iterative index scans**, which materially fixed the "my metadata filter ate the
  result set" failure that previously made filtered vector search unreliable at small and medium scale.
- pgvectorscale (Timescale/Tiger Data) adds StreamingDiskANN and statistical binary quantization.
  Reported: **471 QPS at 99% recall on 50M vectors, vs Qdrant at 41 QPS** in the same test.
  ⚠️ **This is a vendor benchmark published by the party selling pgvectorscale. Treat the ordinal claim
  as unproven and the order-of-magnitude feasibility claim as plausible.**
- Practical scale guidance from independent write-ups: pgvector HNSW is production-comfortable to roughly
  **10M vectors per node**; pgvectorscale extends that to roughly **50M** with p95 under 50 ms; **beyond
  ~100M vectors, purpose-built engines are the better tool** `[COMMUNITY]`.
- HNSW index *build* is single-node and memory-bound. One report: an 8 vCPU / 16 GB machine builds a
  1M × 1536-dim index in ~30 minutes, and a 2M index on the same machine will not build at all
  `[COMMUNITY]`. **Build cost, not query cost, is the first wall.**

**Evidence for a dedicated store** `[COMMUNITY]`:

- Filter-aware graph traversal: a highly selective filter over hundreds of millions of vectors does not
  gut recall or force a large over-fetch. pgvector's pre-filtering story remains weaker.
- Horizontal scale and operational separation from the transactional database.

**The decisive argument is neither of those.** `[INFERENCE]`:

> QAYD's retrieval must be **tenant-isolated**, and tenant isolation in QAYD is a *database* property —
> `NOT NULL company_id`, `FORCE ROW LEVEL SECURITY`, a named restrictive policy, and a CI test that
> introspects `pg_class`/`pg_policy` to prove it (`IM-07`). A separate vector store means re-implementing
> that isolation in a second system, with a different enforcement mechanism, a different failure mode,
> and no catalog introspection test. It also creates a second copy of tenant-derived data with its own
> backup, retention, deletion and residency obligations.
>
> That is the same class of decision as "a second writer into the ledger", which `04_REJECTED_PATTERNS.md`
> already refuses. **The consistency and isolation argument dominates the performance argument by an
> order of magnitude at QAYD's scale.**

**Answer: pgvector in the primary database — conditional, with named triggers to revisit.**

The condition is a design constraint, not a hope:

> **Only embed what has no exact key.** Judgements, memory rows, vendor description patterns, policy
> text. **Do not embed raw document text**, because the extracted structured record supersedes it and is
> exactly queryable. This keeps the vector corpus in the low thousands per tenant rather than tens of
> thousands per tenant per year, and it is what makes the scale argument hold.

Revisit triggers (promote R-02 to a build item when any fires):

| Trigger | Threshold |
|---|---|
| Vector row count | > 20 M rows in the embedding table |
| Filtered-search latency | p95 > 150 ms with the tenant predicate applied |
| Index build time | Exceeds the maintenance window on the production instance class |
| Recall under filter | Measured recall@20 < 0.9 on a tenant-scoped golden set |

Full sizing arithmetic and the fallback design are in `IMPLEMENTATION_RECOMMENDATIONS.md` **AIR-07**.

### 5.5 Embeddings and dimensionality

`[INFERENCE]` Two practical notes, both cost-relevant:

- Dimensionality is a storage and index-build cost multiplier. 1,536-dim is a default, not a
  requirement. Matryoshka-style truncatable embeddings allow storing a shorter prefix for the first
  stage and reserving full dimensionality for rerank — worth evaluating before committing the schema,
  because `VECTOR(n)` is a column type and changing it is a migration.
- Re-embedding on model change is a bulk batch job. It is the archetypal Batches-API workload (§8.3),
  and `05_FUTURE_ARCHITECTURE.md` already lists "periodic re-embedding after a chart-of-accounts change"
  as batchable.

---

<a id="6"></a>
## 6 · Safety, decision boundaries and prompt injection

**This section is a security topic, not a quality topic.** QAYD ingests supplier invoices, bank
statement files and (eventually) emails. Every one of those is attacker-authored text arriving on the
same channel as the model's instructions. `R-31` already states the principle; this section supplies the
threat model and the measured evidence.

### 6.1 The taxonomy

| Class | Definition |
|---|---|
| **Direct prompt injection** | The user of the system is the attacker; they type the malicious instruction |
| **Indirect prompt injection** | A third party plants the instruction in content the system later ingests |

QAYD's exposure is overwhelmingly **indirect**: a vendor who can send an invoice can send text into the
model's context. The vendor is not a user of QAYD and has no account.

OWASP Top 10 for LLM Applications 2025 `[DOCS]` (https://genai.owasp.org/llm-top-10/):

| ID | Name |
|---|---|
| LLM01 | Prompt Injection |
| LLM02 | Sensitive Information Disclosure |
| LLM03 | Supply Chain |
| LLM04 | Data and Model Poisoning |
| LLM05 | Improper Output Handling |
| LLM06 | Excessive Agency |
| LLM07 | System Prompt Leakage |
| LLM08 | Vector and Embedding Weaknesses |
| LLM09 | Misinformation |
| LLM10 | Unbounded Consumption |

`[INFERENCE]` QAYD's live exposure ranks: **LLM01** (untrusted documents), **LLM06** (the entire autonomy
question), **LLM05** (model output reaching a renderer or a query), **LLM02** (cross-tenant or
cross-permission leakage through the Copilot), **LLM10** (cost — which S4-11 addresses), **LLM08** (the
memory store is tenant data in a shared index). LLM04 is low today because QAYD fine-tunes nothing —
which is itself an argument for not starting.

### 6.2 The lethal trifecta

Simon Willison's formulation `[COMMUNITY]`
(https://simonwillison.net/2025/Jun/16/the-lethal-trifecta/, Jun 2025). An agent is exploitable for data
theft when it simultaneously has:

1. **Access to private data**
2. **Exposure to untrusted content**
3. **The ability to externally communicate**

His stated mitigation principle:

> "once an LLM agent has ingested untrusted input, it must be constrained so that it is impossible for
> that input to trigger any consequential actions."

And on guardrail vendors claiming to block "95% of attacks":

> "in web application security 95% is very much a failing grade."

`[INFERENCE]` **QAYD holds legs 1 and 2 unavoidably** — it is a bookkeeping system that reads supplier
documents. Leg 3 is therefore the one to sever, and it is severable at near-zero cost: the AI engine
should have no outbound network route except an allowlisted model-provider endpoint, and the *user
interface* must never render or fetch a model-authored URL. That second half is not obvious and is where
real systems have failed (§6.3).

### 6.3 Demonstrated exploits, not theoretical ones

**EchoLeak — CVE-2025-32711, CVSS 9.3, Microsoft 365 Copilot, disclosed by Aim Security, June 2025**
`[DOCS]`. A **zero-click** indirect prompt injection: a single crafted email, no user interaction. When
Copilot retrieved the email into its RAG context, it executed the attacker's instructions and exfiltrated
chat logs, OneDrive files, SharePoint content and Teams messages. It **bypassed Microsoft's XPIA
(Cross-Prompt Injection Attempt) classifier**, bypassed link redaction, and defeated Content Security
Policy **via an allowlisted Teams image proxy**. Microsoft patched it server-side and reported no
in-the-wild exploitation.

`[INFERENCE]` Three lessons, each directly transferable:

1. **A dedicated injection classifier, built by a company with Microsoft's security resources, was
   bypassed.** Classifiers are telemetry. They are not a boundary.
2. **The exfiltration channel was an allowlisted, legitimate, internal URL.** Allowlists that include
   anything capable of carrying a query string are not allowlists.
3. **The user did nothing.** "The human is in the loop" was true and irrelevant, because the human was
   in the loop for the *answer*, not for the retrieval.

**Anthropic's Claude for Chrome red-teaming**, Aug 2025 `[DOCS]`: 123 test cases across 29 attack
scenarios. Attack success rate **23.6% without mitigations**, reduced to **11.2% with them**. On a
four-attack browser-specific challenge set, mitigations took ASR from **35.7% to 0%**. One documented
pre-mitigation success: an email claiming messages must be deleted for security reasons, which Claude
obeyed, deleting the user's mail without confirmation.

`[INFERENCE]` **11.2% residual, published by the vendor, on their own model, after mitigation.** That is
the honest number to design against. Any architecture whose safety depends on the model not being
injected is designed against 0%.

### 6.4 The one defence with a formal argument

Google DeepMind's **CaMeL** — *Defeating Prompt Injections by Design* `[PAPER]` (arXiv:2503.18813, 2025):

> CaMeL "explicitly extracts the control and data flows from the (trusted) query; therefore, the
> untrusted data retrieved by the LLM can never impact the program flow."

Plus capability-based data-flow control to prevent exfiltration, with policies enforced at tool-call
time. Measured on AgentDojo: **provable security on 77% of tasks**, versus **84% task success on an
undefended system** — a 7-point utility cost for a formal guarantee on most of the surface.

`[INFERENCE]` **This is the single most important result in the security literature for QAYD, and QAYD
gets most of it for free** — because QAYD's control flow is genuinely fixed. "Extract this invoice",
"score these candidate matches", "draft this entry" are *code paths*, not model decisions. QAYD does not
need CaMeL's machinery; it needs to not throw away the property CaMeL works hard to manufacture. The
architectural rule that preserves it:

> **No value derived from untrusted content may determine which code runs, which tool is called, which
> tenant's data is read, or which row is written.** Untrusted content may only produce *values* that land
> in a typed, validated, allowlist-constrained structure.

### 6.5 The defences that do not work, and why

| Defence | Status | Evidence |
|---|---|---|
| Input classifiers / injection detectors | **Not a boundary.** Useful as telemetry. | XPIA bypassed in EchoLeak `[DOCS]`; 11.2% residual ASR `[DOCS]` |
| "Ignore any instructions in the document" in the system prompt | **Not a mechanism.** | `P15` already states this; the instruction and data channels are the same channel |
| Delimiting / spotlighting untrusted content | **Reduces likelihood; does not create a boundary.** Worth doing, worth nothing alone. | `R-33`'s stated position; consistent with residual-ASR data |
| A second model reviewing the first | **Not independent.** | `R-32` §3 — shared priors, shared misreadings |
| Human approval | **Defends against error, not against attack.** | `[INFERENCE]` — an injected proposal is optimised to look correct |
| Least privilege on the acting credential | **The boundary.** | `P15`, `P-12` layer 1; CaMeL's capability half `[PAPER]` |
| Removing the egress channel | **The boundary for exfiltration.** | Lethal trifecta leg 3 `[COMMUNITY]` |

### 6.6 MCP's security model — what it does and does not give you

The MCP specification publishes a security-best-practices document `[DOCS]`
(https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices). It names and
requires mitigations for: **confused deputy** (OAuth proxy + static client id + consent cookie),
**token passthrough** ("MCP servers **MUST NOT** accept any tokens that were not explicitly issued for
the MCP server"), **SSRF** during metadata discovery, **session hijacking** ("MCP servers **MUST NOT**
use sessions for authentication"), **local server compromise**, **OAuth authorization URL validation**
(reject `javascript:`, `data:`, `file:`), and **scope minimisation** with progressive least-privilege
elevation.

`[INFERENCE]` What is striking is what the list is *about*: it is an OAuth-and-transport threat model. It
is thorough and it is correct, and **none of it addresses prompt injection**, because MCP is a transport
and capability-description protocol, not a trust boundary for content. A tool exposed over MCP is exactly
as dangerous as the same tool exposed any other way.

For QAYD specifically: MCP is a way to let *third parties* plug tools into an agent. QAYD's AI engine is
first-party and internal. **Adopting MCP internally would import an authorization surface QAYD does not
need**, and its scope-minimisation guidance is worth reading and applying *conceptually* to the internal
Laravel↔FastAPI contract without adopting the protocol. That contract is already specified as internal
bearer + mTLS verify-full in S3-07 `[CODE]` (`docs/execution/SPRINT_03.md:146`), which is stronger.

⚠️ **Currency warning.** The MCP specification is versioned and moving fast. As observed on 2026-07-28
the current revision is **`2026-07-28`**, which differs materially from the widely-cited `2025-06-18`:
MCP is now explicitly "a stateless protocol"; capability discovery moved to a mandatory `server/discover`
request; change notifications became opt-in and explicitly "Best Effort"; and **sampling and logging are
deprecated** ("New implementations should integrate directly with LLM provider APIs"), leaving
`elicitation/create` as the only non-deprecated client primitive `[DOCS]`. Any design document citing MCP
should state the revision it read, because half of what was written about MCP in 2025 is now wrong.

The specification's own scope disclaimer is the honest summary: *"MCP focuses solely on the protocol for
context exchange—it does not dictate how AI applications use LLMs or manage the provided context."*
`[DOCS]` `[INFERENCE]` There is **no protocol-level defence against prompt injection, tool poisoning, or
malicious tool descriptions.** Sandboxing and consent UX are client-side `SHOULD`s, not guarantees.

### 6.7 What a shipped agent product actually does about injection

Claude Code's documented permission and security model is the most detailed published example of
defence-in-depth in a real agent `[DOCS]` (code.claude.com/docs/en/permissions, /security). The
transferable mechanisms:

| Mechanism | Detail | Why it transfers |
|---|---|---|
| **Deny-first rule precedence** | "Rules are evaluated in order: deny, then ask, then allow. The first match in that order determines the outcome, and rule specificity doesn't change the order." | Specificity-based precedence (the CSS model) is unsafe for permissions: a narrow allow must never beat a broad deny |
| **Removing a tool from context entirely** | A bare tool-name deny rule removes the tool so the model never sees it; a scoped rule leaves it visible and blocks matching calls | A capability the model cannot see cannot be argued into being used — and it costs no context |
| **Isolated context window for fetched content** | "Web fetch uses a separate context window to avoid injecting potentially malicious prompts" | **Directly applicable to invoice and statement ingestion** — see `ARCHITECTURE.md` §7.2 |
| **Sandboxing as a distinct layer from permissions** | "Sandbox restrictions prevent Bash commands from reaching resources outside defined boundaries, **even if a prompt injection bypasses Claude's decision-making**" | The stated design assumption is that injection *will* sometimes succeed |
| **Deny rules do not cover indirect access** | File deny rules "don't apply to arbitrary subprocesses that read or write files indirectly" | Application-layer permission checks are porous; OS/DB-layer enforcement is not |
| **Fail-closed and re-prompting** | Command-injection detection re-prompts "even if previously allowlisted" | An allowlist decision is per-invocation, not permanent |

And the residual-risk statement, published by the vendor `[DOCS]`: *"While these protections
significantly reduce risk, no system is completely immune to all attacks."*

---

<a id="7"></a>
## 7 · Autonomous execution and what approval must mean

### 7.1 Where autonomy is safe

`[INFERENCE]` The general test, consistent with `P-04` and `R-32`:

> Autonomy is safe where the action is **(a)** machine-verifiable against a source the model did not
> author, **(b)** reversible within a bounded window, **(c)** bounded in blast radius, and **(d)**
> observable — a wrong outcome is detected without anyone going looking.

Note that "the model is usually right" is not on the list, and cannot be, because the failure mode is
correlated (`R-31` §1: one misread rule produces the same wrong classification across ten thousand
transactions in a minute, all internally consistent, all passing validation).

### 7.2 Reversibility budgets

`07_QAYD_INNOVATION.md` **I-17** already proposes autonomy governed by a reversibility budget rather than
a binary switch, and `08_MASTER_BACKLOG.md` binds it to **S4-01**'s `AutonomyResolver`.

`[INFERENCE]` The engineering content this research adds is the **units**. A reversibility budget is only
meaningful if it is denominated in something measurable and depleted by something automatic:

| Dimension | Unit | Depleted by |
|---|---|---|
| Monetary | Currency, per period, per capability | The absolute value of what an autonomous action moved |
| Count | Actions per period | Each autonomous action |
| Window | Time until irreversible | Period close, statement issue, or filing |
| Trust | Rolling accuracy on the last *n* outcomes | Each rejection or reversal |

And the crucial property: **the budget must be consumed at action time and restored only by an event
that proves the action was fine** — not by the clock. A budget that refills nightly regardless of outcome
is a rate limit wearing a safety costume.

### 7.3 What "human approval" must actually be

This is the weakest link in every human-in-the-loop design, and `P15` already names it: *"a human
clicking approve on 200 proposals is not meaningfully reviewing them."*

`[INFERENCE]` Approval is real only if all four hold:

1. **The information needed to disagree is present at the moment of decision** — the source document,
   the amount, the account, the confidence, and the diff against what the deterministic path would have
   produced. `P-12` requires machine-readable `rationale` for exactly this reason.
2. **The act of approving cannot be completed without engaging with the content.** A single button that
   is in the same place for every proposal is a reflex, not a decision. Where the value is material, the
   confirming act should require re-entering or selecting the salient value.
3. **It is measured.** Time-to-approve is observable. A median approval latency of under a couple of
   seconds on a multi-line entry is direct evidence that review is not happening, and it is an
   operational alarm, not a UX opinion.
4. **It is sampled blind.** A fixed random fraction of proposals — including high-confidence ones — is
   routed to a second reviewer with the confidence and the AI's rationale hidden. Disagreement rate on
   that stream is the only unbiased estimate of true accuracy the system can produce, because every other
   number is conditioned on the reviewer having seen the model's opinion.

Point 4 is the one nobody builds, it is cheap once proposals exist, and it is the only defence against
the automation-bias failure that `R-32` and `P15` both identify but neither instruments.

`[UNKNOWN]` We did not locate a primary quantitative study of automation bias in financial-approval UIs
specifically. The concept is well established in human-factors literature (aviation, clinical decision
support), but no cited figure should be attributed to it here.

---

<a id="8"></a>
## 8 · Cost engineering

`05_FUTURE_ARCHITECTURE.md` **§E** owns the arithmetic — the $14-vs-$45-per-customer figure, the 3.2×
gap, the decomposition into caching (52%) / tiering (42%) / batching (6%), and the projection that AI is
70–85% of infrastructure cost at every tier. **This section does not restate it.** It corrects two
inputs and adds three levers that document does not cover.

### 8.1 The ordering is right and worth restating once

Caching > tiering > batching, in that order of value, *for QAYD's prompt shape specifically*, because
QAYD's prompts are dominated by a large stable per-company prefix. That is an unusual and favourable
shape and `05` is correct to call it an architectural asset.

### 8.2 Correction — the minimum cacheable prefix numbers have moved

`05_FUTURE_ARCHITECTURE.md` states: *"The minimum cacheable prefix is 4,096 tokens on Opus 4.8 and Haiku
4.5 (2,048 on some Sonnet-family models)."*

Currently published `[DOCS]` (https://platform.claude.com/docs/en/docs/build-with-claude/prompt-caching,
observed 2026-07-28):

| Model | Minimum cacheable tokens |
|---|---|
| Claude Opus 5, Fable 5, Mythos 5 | **512** |
| Claude Opus 4.8, Sonnet 5, Sonnet 4.6, Sonnet 4.5, Opus 4.1, Opus 4 | **1,024** |
| Claude Mythos Preview, Opus 4.7 | 2,048 |
| Claude Opus 4.6, Opus 4.5 | 4,096 |
| **Claude Haiku 4.5** | **4,096** |
| Claude Haiku 3.5 | 2,048 |

So Haiku 4.5 is unchanged at 4,096 — which is the one that matters, because Haiku is the extraction tier
and extraction is the highest-volume shape. Opus 4.8 is 1,024, not 4,096.

`[INFERENCE]` The engineering conclusion is *strengthened*, not weakened: **the constant moves per model
and per release, silently, with no error raised.** `05`'s own advice — construct the prefix to clear the
threshold and assert on `cache_read_input_tokens` in an integration test — is the correct response, and
the test is now the primary control rather than a backstop.

### 8.3 Caching mechanics that will cost money if missed

All `[DOCS]`, same source:

| Fact | Consequence |
|---|---|
| Cache **write** = 1.25× base input (5-min TTL), **2×** (1-hour TTL); **read = 0.1×** | The 1-hour TTL breaks even at ≥3 reads |
| Cache prefix order is immutable: **`tools` → `system` → `messages`** | Tool definitions must be stable, or every cache dies |
| Changing **tool definitions invalidates all caches** (tools, system, messages) | Tool schema is a cache-invalidating deployment. Version and batch tool changes. |
| Changing `tool_choice` or adding/removing images invalidates **messages only** | Cheaper, but still a cost event |
| **Maximum 4 explicit cache breakpoints** per request | Budget them deliberately: instructions / few-shots / chart of accounts / judgements |
| 5-minute TTL **refreshed at no cost on each use**; 1-hour likewise | A tenant with continuous activity holds its cache indefinitely for free |
| **20-block lookback** per breakpoint | Put the breakpoint on the last block of the stable prefix, not on changing content |
| Cache is isolated per **workspace** (Claude API) or per **organization** (Bedrock, Vertex) | No cross-tenant cache sharing risk if tenants share a workspace — but also no cross-tenant *benefit*, which is why the per-company prefix is the right unit |
| Usage returns `cache_creation_input_tokens`, `cache_read_input_tokens`, `input_tokens` | `total = read + creation + input`. This is the telemetry to alert on. |

### 8.4 Batching — the exact terms

`[DOCS]` (https://platform.claude.com/docs/en/docs/build-with-claude/batch-processing):

- **50% cost reduction.**
- Batch limit: **100,000 requests or 256 MB**, whichever is hit first.
- Most batches complete **within 1 hour**; results available when all complete **or after 24 hours**;
  **batches expire at 24 hours** and expired requests are **not billed**.
- Results retained **29 days**.
- **Caching and batching combine**, and the documentation explicitly recommends the **1-hour cache TTL**
  for batches with shared context, because a 5-minute entry will likely expire mid-batch.
- `max_tokens: 0` (cache pre-warming) is **not supported inside a batch**.

`[INFERENCE]` The 1-hour-TTL-inside-batch note is the non-obvious one and it directly changes QAYD's
nightly extraction design: a nightly batch over one tenant's documents should write a 1-hour cache entry
for that tenant's prefix and then run the batch, rather than writing 5-minute entries that die.

### 8.5 Model routing and cascades — the evidence, honestly graded

- **RouteLLM** (UC Berkeley, ICLR 2025): reported **>85% cost reduction on MT-Bench while retaining ~95%
  of GPT-4 quality**, routing only ~14% of queries to the strong model `[PAPER]` — *figures taken from
  secondary summaries in this pass; verify against the paper before quoting commercially.*
- **FrugalGPT** (Chen et al., Stanford, 2023): LLM cascades adaptively selecting among model calls;
  reported up to **98% cost reduction** `[PAPER]`, same caveat.

`[INFERENCE]` Two cautions before treating routing as free money:

1. Both results are measured on **general chat benchmarks**, where "quality" is a preference judgement.
   QAYD's tasks have *correct answers*. A router that preserves 95% of preference-quality may preserve
   much less than 95% of *accuracy*, and the failures will not be uniformly distributed — they will
   concentrate on exactly the unusual inputs where escalation was most needed.
2. A cascade that escalates on the *cheap model's own confidence* re-introduces `R-32`. Escalation must
   trigger on something independent: a validation failure, an ambiguity count, a monetary threshold, an
   unmatched-account condition — a property of the *task*, not an opinion of the model.

QAYD's design already has the correct shape (`05`: deterministic rules → Haiku → Sonnet → Opus), and it
already ties escalation to task properties rather than self-reported confidence. That is the right
answer and it should be defended when someone proposes a confidence-based router.

### 8.6 Two levers `05` does not cover

`[INFERENCE]`

**Per-proposal cost attribution.** Every model call should write a cost record keyed to the proposal (or
chat turn) it served, carrying model id, prompt version, `input_tokens`, `cache_read_input_tokens`,
`cache_creation_input_tokens`, `output_tokens`, and computed cost. Three things become possible that are
otherwise guesswork: S4-11's `AiCostGovernor` can enforce a *real* budget rather than a request count;
per-tenant gross margin becomes a query; and a caching regression shows up as a cost-per-proposal step
change rather than as a surprise at month-end. `05` already names the silent caching regression as "the
highest-frequency risk in this document" — this is the instrument that catches it.

**Output tokens are the expensive half.** At published Anthropic ratios, output is priced at ~5× input.
Anything that shortens output is worth ~5× the same saving on input. Concretely: proposals should emit
*references* (account id, document id, line ids) rather than restating content the caller already has,
and the machine-readable `rationale` required by `P-12` should be structured feature contributions rather
than prose — which `P-12` already requires for *reviewability* reasons and which turns out to be a cost
decision as well.

---

<a id="9"></a>
## 9 · Evaluation

### 9.1 Benchmarks are not evidence about your system

**SWE-bench Verified** (OpenAI, Aug 2024) exists because human annotators found a material share of the
original SWE-bench tasks unsolvable or underspecified; Verified is a human-validated 500-problem subset
`[DOCS]`. `[UNKNOWN]` — the source page returned HTTP 403 in this pass, so the exact percentages are not
quoted here. Do not cite a number for it without re-verifying.

`[INFERENCE]` The transferable lesson is not about SWE-bench. It is that **a widely-cited benchmark ran
for a year before anyone checked whether its tasks were answerable**, and every model comparison published
in that year inherited the flaw. A public benchmark tells you about a model. It tells you nothing about
your pipeline, your prompts, your retrieval, your data, or your users.

### 9.2 Single-run accuracy overstates reliability — pass^k

**τ-bench** (Sierra) `[PAPER]` (arXiv:2406.12045) evaluates agents in simulated tool-using conversations
against domain policies, and introduces **pass^k**: the probability an agent succeeds on *all k*
independent attempts at the same task.

Reported: state-of-the-art function-calling agents such as GPT-4o succeeded on **under 50%** of tasks
overall, and **pass^8 dropped below 25%** in the retail domain.

`[INFERENCE]` This is the most under-appreciated number in agent evaluation. A "50% accurate" agent that
is *inconsistently* 50% accurate is a different product from one that is *reliably* 50% accurate: the
first cannot be trusted with any task twice, and its failures cannot be characterised, so they cannot be
routed around. **For any QAYD surface where the model chooses what to do — i.e. the Copilot — pass^k is
the metric, not pass@1.** For the pure-function surfaces (extraction, scoring, drafting) single-run
accuracy is the right metric, because code, not the model, chooses what happens next — which is another
argument for the architecture in `ARCHITECTURE.md`.

### 9.3 LLM-as-judge, and its documented biases

Well-established `[PAPER]`:

- **Position bias** — judges prefer the first-presented option (MT-Bench / Chatbot Arena, NeurIPS 2023).
- **Verbosity bias** — judges prefer longer answers.
- **Self-preference bias** — judges rate their own family's outputs higher (arXiv:2410.21819).
- Systematic surveys quantify these across judge models (arXiv:2410.02736).

Standard mitigations — order randomisation, identity masking, ensembling, reporting inter-judge agreement
— reduce *variance within* the judge population but do not remove biases *shared across* it.

`[INFERENCE]` For QAYD the conclusion is sharp: **an LLM judge may never score financial correctness.**
Whether an entry is right is a deterministic question with a deterministic checker (does it balance? does
it match the human's corrected version? did it use an account that exists?). An LLM judge is appropriate
for exactly one thing: grading the *quality of an explanation* — is the rationale intelligible, does it
cite the right source — and even then it must be validated against human labels with **precision and
recall reported separately**, as the practitioner literature insists `[COMMUNITY]`
(https://hamel.dev/blog/posts/evals/).

### 9.3b Anthropic's own eval taxonomy, and the verification hierarchy

*Demystifying evals for AI agents* `[DOCS]`
(https://www.anthropic.com/engineering/demystifying-evals-for-ai-agents, Jan 2026) gives the cleanest
published structure:

- **Three grader families.** *Code-based* (string match, binary tests, static analysis, outcome
  verification, tool-call verification, transcript analysis) · *model-based* (rubric scoring, NL
  assertions, pairwise, reference-based, multi-judge consensus) · *human* (SME review, spot-check, A/B,
  inter-annotator agreement).
- **Outcome/state vs trajectory.** State is "the final state in the environment at the end of the
  trial"; trajectory is "the complete record of a trial, including outputs, tool calls, reasoning,
  intermediate results." Grade the end state, "since agents may take alternative paths."
- **Sizing.** "20-50 simple tasks drawn from real failures is a great start."
- **Anti-pattern.** Graders that are "too rigid and results in overly brittle tests, as agents regularly
  find valid approaches that eval designers didn't anticipate"; and class-imbalanced eval sets.

The Claude Agent SDK post states the **verification hierarchy** explicitly `[DOCS]`
(claude.com/blog/building-agents-with-the-claude-agent-sdk, Sep 2025): "The best form of feedback is
providing clearly defined rules for an output" — rules-based checks first, then visual feedback, then an
LLM judge, which they describe as "generally not a very robust method."

`[INFERENCE]` **This is the most favourable finding in the entire research for QAYD.** The
best-in-hierarchy verification method — deterministic rules — is precisely what an accounting system
already owns in abundance: does it balance? does the account exist and is it postable? is the period
open? does the reconciliation over-consume? does the trial balance sum to zero? An AI product in almost
any other domain has to reach for a model judge because it has no ground truth. QAYD has ground truth in
the database. **The correct eval architecture is code-based graders over the existing invariants, with a
model judge confined to explanation quality.**

The same post also states a retrieval default worth carrying into §5: "start with agentic search, and
only add semantic search if you need faster results", on the grounds that semantic search is "faster…
but less accurate, more difficult to maintain, and less transparent" `[DOCS]`.

### 9.4 Build evals from production failures — and QAYD's unfair advantage

The practitioner consensus is error-analysis-first: look at real failures, cluster them, build the eval
from the clusters, and only then write judges `[COMMUNITY]` (Hamel Husain, hamel.dev/notes/llm/evals/).

`[INFERENCE]` **QAYD has a labelled-data source that most AI products would pay for and cannot obtain:**

| Signal | What it labels | Where it comes from |
|---|---|---|
| **Rejected proposal** | A negative, with a human's reason | `P-12` `outcome='rejected'` |
| **Edited-then-accepted proposal** | A negative *plus the correct answer plus the location of the error* — the richest signal in the system | S4-04's edit-and-accept path |
| **Accepted proposal** | A positive, weakly (see §9.5) | `P-12` `outcome='accepted'` |
| **Reversal of a posted entry** | A delayed negative, caught after the fact | `P-13` reversal with `reversal_reason NOT NULL` |
| **Refused posting** | Violation codes with the AI's confidence attached | `posting_attempts` (`P-10`) |

Every one of these is produced by a qualified professional in the ordinary course of their work, at zero
marginal cost, permanently, with provenance. `08_MASTER_BACKLOG.md` **S3+A** ("Correction Corpus, I-09")
rates this High/3 points/P1 and notes it is "nearly free designed in now; expensive to backfill." That
rating is, if anything, low.

### 9.5 The three traps in using those labels

`[INFERENCE]`

1. **Acceptance is a biased positive.** A proposal accepted in 1.2 seconds is evidence about the
   reviewer, not the proposal. Weight acceptances by review engagement, or restrict the positive set to
   the blind-sampled stream (§7.3).
2. **Tenant leakage.** Evaluating a tenant's model behaviour on examples drawn from that tenant's own
   precedent store measures memorisation. Golden sets must be split by tenant, and cross-tenant
   evaluation must respect the same isolation rules as everything else (see R-01 in the backlog).
3. **Distribution drift is the point, not the noise.** The eval set assembled in month one describes month
   one. New vendors, new bank formats and new charts of accounts are precisely where confidence stays
   high and accuracy falls (`R-32` §1). The golden set must be *continuously appended* from recent
   corrections, and the metric that matters is accuracy on *newly-seen* subjects, reported separately.

---

<a id="10"></a>
## 10 · Prompt architecture, structured output, tool-call reliability

### 10.1 Prompts are code

`R-33` already rejects prompts stored as mutable data. `[INFERENCE]` The constructive half:

- A prompt is a **versioned artefact in the repository**, compiled to an immutable identifier.
- That identifier is recorded on every proposal, alongside `model_id`/`model_version` which `P-12`
  already requires. Without it, "which prompt produced this?" is unanswerable and a prompt regression is
  not a queryable cohort — exactly the argument `P-12` makes for `model_version`.
- A prompt change is a **deployment** and must pass the regression gate (§9).
- Per-tenant variation is achieved by **data interpolated into a fixed template** (chart of accounts,
  judgements, policies) — never by a per-tenant template.

### 10.2 Structured output — and the ordering rule

The reliability case for constrained decoding / schema-enforced output is straightforward and it is what
makes a proposal a typed object rather than a string to parse.

The complication `[PAPER]`: *Let Me Speak Freely? A Study on the Impact of Format Restrictions on
Performance of Large Language Models* (arXiv:2408.02442) reports that format restrictions degrade
reasoning performance, with stricter constraints producing larger drops, and identifies **output
misordering** under strict JSON mode as a mechanism. ⚠️ **This finding is contested** — practitioners
maintaining constrained-decoding libraries have published rebuttals arguing the effect is largely a
prompting artefact `[COMMUNITY]`. Treat it as *directionally credible, magnitude disputed*.

`[INFERENCE]` The design rule that is robust under either reading, costs nothing, and should simply be
adopted:

> **Put the free-form reasoning field first in the schema, and the decision fields after it.** Because
> generation is autoregressive, fields emitted earlier condition fields emitted later. A schema ordered
> `{decision, confidence, reasoning}` forces the model to commit before it reasons and then rationalise;
> `{reasoning, decision, confidence}` does the reverse. This is free, and it is the mechanism the
> misordering finding actually points at.

A second rule: **constrain the value space, not just the type.** An `account_code` field should be an
enum over the tenant's actual chart of accounts — a set the model did not author — not a free string
validated afterwards. That converts a whole class of hallucination into an impossibility and is the
cheapest injection mitigation available (§6.4).

### 10.3 Tool design, where tools exist at all

Anthropic's *Writing effective tools for agents* `[DOCS]`
(https://www.anthropic.com/engineering/writing-tools-for-agents) — the transferable findings:

- **Consolidate.** One `schedule_event` beats `list_users` + `list_events` + `create_event`, because the
  intermediate outputs never enter context.
- **Namespace** by service and resource (`asana_projects_search`); the choice of prefix vs suffix has
  "non-trivial effects on tool-use evaluations".
- **Cap response size.** Claude Code defaults to a **25,000-token** tool-response limit; truncation
  messages should steer toward "many small and targeted searches instead of a single, broad search".
- **Return meaningful identifiers.** Resolving UUIDs to semantic names "significantly improves Claude's
  precision in retrieval tasks by reducing hallucinations". Prefer `name`, `file_type`, `image_url` over
  `uuid`, `mime_type`, `256px_image_url`.
- **Offer a verbosity enum** (`concise` / `detailed`); the concise form used roughly **one third** of the
  tokens in their example.
- **Error messages should teach.** Show the correct format, not an opaque code.
- Claude-optimised tool definitions outperformed expert-human-written ones on held-out tests.
  ⚠️ `[UNKNOWN]` — the post states the improvement qualitatively; **no percentage is published.** Do not
  cite a number for it.

The concrete verbosity figure: their Slack example returned **206 tokens in `DETAILED` mode vs 72 tokens
in `CONCISE`** `[DOCS]`.

Anthropic frames the whole area as the **Agent–Computer Interface (ACI)**, deliberately parallel to HCI,
and argues it deserves the same investment as a human-facing UI `[DOCS]`. The two rules from that framing
that generalise best:

- **Give the model room to think before it commits.** "Give the model enough tokens to 'think' before it
  writes itself into a corner" — the same mechanism as the schema-ordering rule in §10.2.
- **Poka-yoke the schema.** "Change the arguments so that it is harder to make mistakes." Their worked
  example: requiring absolute file paths eliminated an entire class of relative-path errors.
  `[INFERENCE]` The accounting analogue is exact — require an account *id* selected from an enum of the
  tenant's postable accounts rather than a free-text account *name*, and a whole error class disappears
  by construction rather than by validation.

And the summarising warning: **"More tools don't always lead to better outcomes."** Build "a few
thoughtful tools targeting specific high-impact workflows, which match your evaluation tasks" `[DOCS]`.

`[INFERENCE]` For QAYD, this applies to exactly one surface — the Copilot's read-only tool set (S4-10) —
and the "return meaningful identifiers" finding conflicts with an instinct QAYD engineers will have,
which is to pass database ids. The resolution: pass **both**, with the human-readable name in the field
the model reasons over and the id in a field it copies verbatim into the structured output.

---

<a id="11"></a>
## 11 · What the products actually ship

Strictly separating demonstrated behaviour from marketing.

| Product | What is documented | What is *not* established |
|---|---|---|
| **Anthropic (Claude Code, Agent SDK, MCP)** | Workflow/agent taxonomy, context-engineering practices, multi-agent token multipliers and eval deltas, tool-authoring guidance, permission-gated tool execution, published red-team ASRs `[DOCS]` | — this is the best-documented engineering corpus in the field |
| **OpenAI** | Structured Outputs with schema conformance; Batch API; instruction-hierarchy research; SWE-bench Verified `[DOCS]` | `[UNKNOWN]` internal agent architecture of ChatGPT agent surfaces |
| **Cursor** | Codebase indexing with embeddings + retrieval; multi-model routing; agent mode `[DOCS]` at product-doc level | `[UNKNOWN]` — no detailed published architecture; most circulating descriptions are reverse-engineered `[COMMUNITY]` |
| **GitHub Copilot** | Editor-context assembly; Copilot coding agent operating on a repo in a sandbox `[DOCS]` | `[UNKNOWN]` retrieval internals |
| **Devin (Cognition)** | Published *architectural philosophy* (single-threaded, context compression) `[COMMUNITY]`; product exists | **Independent evaluation contradicts the autonomy claim** — see below |
| **Windsurf** | Product-level docs on codebase awareness | `[UNKNOWN]` no published engineering detail located |
| **Perplexity** | Search-then-synthesise with citations; a workflow, not an agent, on the core path | `[UNKNOWN]` ranking/retrieval internals |
| **Notion AI** | Retrieval over a workspace graph with permission scoping | `[UNKNOWN]` architecture undocumented |
| **Google Gemini** | Long context; Deep Research as a plan-search-synthesise workflow; CaMeL from DeepMind research `[PAPER]` | Research ≠ product: CaMeL is not shipped in Gemini |
| **Microsoft 365 Copilot** | Graph-grounded RAG; XPIA injection classifier; **and a CVSS 9.3 zero-click injection that bypassed it** `[DOCS]` | — the security lesson is the finding |

### 11.1 The two independent results that matter most

**METR randomised controlled trial**, July 2025 `[INDEPENDENT]`
(https://metr.org/blog/2025-07-10-early-2025-ai-experienced-os-dev-study/, arXiv:2507.09089): 16
experienced open-source developers, 246 real tasks on their own mature repositories (22k+ stars, 1M+
lines), primarily Cursor Pro with Claude 3.5/3.7 Sonnet.

> Developers took **19% longer** with AI tools — and afterwards estimated that AI had made them **20%
> faster**.

**Answer.AI's month with Devin**, January 2025 `[INDEPENDENT]`
(https://www.answer.ai/posts/2025-01-08-devin.html): of 20 tasks, **14 failures, 3 successes, 3
inconclusive**. Their most damaging observation was not the success rate but that they **could not predict
which tasks would succeed** — tasks similar to early wins failed "in complex, time-consuming ways", and
the autonomy became a liability because the agent would pursue impossible solutions for days rather than
recognising a blocker.

`[INFERENCE]` Three conclusions QAYD should carry:

1. **Self-reported productivity is not evidence.** The 19%/20% gap is a measurement of how badly humans
   estimate their own AI-assisted throughput. Any QAYD claim about time saved must come from instrumented
   before/after, not from a customer's impression — and this is a *product* discipline as much as an
   engineering one.
2. **Unpredictable failure is worse than frequent failure.** A system that fails 50% of the time in a
   *characterisable* way can be routed around. One that fails unpredictably cannot. This is the same
   property τ-bench's pass^k measures (§9.2) and it argues for narrow, well-characterised tasks over
   broad autonomous ones.
3. **Time spent recognising a blocker is the dominant cost of autonomy.** An autonomous loop must have a
   step budget and a give-up condition, and giving up must be a *reported outcome*, not a silent timeout.

---

<a id="12"></a>
## 12 · Summary table of load-bearing numbers

Every figure this research relies on, with its grade. Re-verify before commercial use.

| # | Figure | Source | Grade |
|---|---|---|---|
| 1 | Agents use ~**4×** the tokens of chat | Anthropic multi-agent post | `[DOCS]` |
| 2 | Multi-agent uses ~**15×** the tokens of chat | Anthropic multi-agent post | `[DOCS]` |
| 3 | Multi-agent beat single-agent Opus 4 by **90.2%** on internal research eval | Anthropic multi-agent post | `[DOCS]` |
| 4 | Token usage explains **80%** of BrowseComp variance | Anthropic multi-agent post | `[DOCS]` |
| 5 | Contextual embeddings: **5.7% → 3.7%** failure (−35%) | Anthropic contextual retrieval | `[DOCS]` |
| 6 | + contextual BM25: **→ 2.9%** (−49%) | same | `[DOCS]` |
| 7 | + reranking: **→ 1.9%** (−67%) | same | `[DOCS]` |
| 8 | Contextualising cost **$1.02 / M document tokens** | same | `[DOCS]` |
| 9 | Skip RAG below ~**200,000 tokens** of corpus | same | `[DOCS]` |
| 10 | Context rot across **18 models**; shuffled haystacks beat coherent ones | Chroma | `[INDEPENDENT]` |
| 11 | CaMeL: **77%** of AgentDojo tasks provably secure vs **84%** undefended | arXiv:2503.18813 | `[PAPER]` |
| 12 | Claude for Chrome ASR **23.6% → 11.2%**; challenge set **35.7% → 0%** | Anthropic | `[DOCS]` |
| 13 | EchoLeak **CVE-2025-32711, CVSS 9.3**, zero-click, bypassed XPIA + CSP | Aim Security / Microsoft | `[DOCS]` |
| 14 | METR RCT: **19% slower**, believed **20% faster** | METR | `[INDEPENDENT]` |
| 15 | Devin: **3 / 20** tasks succeeded over a month | Answer.AI | `[INDEPENDENT]` |
| 16 | τ-bench: GPT-4o **<50%** pass@1, **pass^8 <25%** (retail) | arXiv:2406.12045 | `[PAPER]` |
| 17 | Cache write **1.25×** (5m) / **2×** (1h); read **0.1×**; max **4** breakpoints; **20**-block lookback | Anthropic docs | `[DOCS]` |
| 18 | Min cacheable prefix: Haiku 4.5 = **4,096**; Opus 4.8 / Sonnet 5 = **1,024**; Opus 5 = **512** | Anthropic docs | `[DOCS]` |
| 19 | Batch: **50% off**, **100,000 req / 256 MB**, **24 h** expiry, **29 d** retention, combine with **1-hour** cache TTL | Anthropic docs | `[DOCS]` |
| 20 | RouteLLM **>85%** cost cut at **~95%** GPT-4 quality; FrugalGPT up to **98%** | secondary summaries of the papers | `[PAPER]` ⚠️ verify |
| 21 | pgvector comfortable to ~**10M** vectors/node; pgvectorscale ~**50M**; >**100M** favours purpose-built | vendor + community write-ups | `[COMMUNITY]` ⚠️ vendor-biased |
| 22 | Claude Code default tool-response cap **25,000 tokens**; concise mode ≈ **⅓** the tokens | Anthropic tools post | `[DOCS]` |

# End of Document
