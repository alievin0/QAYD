# AI Agent Engineering — Research Index

**Phase 3 of the QAYD engineering research program · `docs/research/ai/`**

Version 1.0 · 2026-07-28 · Documentation only — no application code, schema, migration or test was
modified in producing this.

---

## What this is

Phase 1 studied Odoo. Phase 2 produced the nine knowledge-base documents in
`docs/architecture/knowledge/`. Both deliberately stopped at the edge of the AI layer: they settled the
**boundary** (`P15`, `P-12`, `R-31…R-34`) and enumerated **twenty product inventions** (`I-01…I-20`),
but left open the question of how the thing on the other side of that boundary is actually engineered.

This phase answers that question. It studies how production AI-agent systems are built — Anthropic,
OpenAI, Cursor, GitHub Copilot, Devin, Windsurf, Perplexity, Notion AI, Google Gemini, Microsoft
Copilot — and converts what is *demonstrated* (not announced) into an architecture for QAYD's FastAPI
AI engine.

**The central question it answers:**

> What is the correct architecture for an AI layer that must be *useful enough to be the product* while
> being *structurally incapable of corrupting the books*?

**The short answer**, stated here so nobody has to read 4,000 lines to find it:

> **QAYD should not build an agent. It should build a quarantined, capability-scoped, deterministic
> proposal pipeline in which the language model is a pure function — untrusted tokens in, typed
> proposal out — and it should build exactly one bounded agentic loop (the Copilot) over a read-only
> tool surface.** Everything the market calls "agentic" that QAYD actually needs is achieved by
> *code* choosing what the model sees and *code* choosing what happens next. The model never selects a
> tool against tenant data, never composes a query, and never holds a credential that can write.

---

## The seven documents

| # | File | What it answers | Lines |
|---|---|---|---|
| 1 | **`README.md`** *(this file)* | How to read this, what it does and does not cover, headline findings | — |
| 2 | **`OVERVIEW.md`** | What the field actually knows: agent architecture, context, memory, retrieval, safety, autonomy, evaluation, cost — evidence-graded, with a summary table of every load-bearing number | 1,136 |
| 3 | **`BEST_PRACTICES.md`** | Eighteen practices worth adopting (B-01…B-18), each with tradeoffs, risk, effort and confidence | 682 |
| 4 | **`ANTI_PATTERNS.md`** | Sixteen named agent anti-patterns (A-01…A-16), why each is tempting, and the mechanism by which it fails | 607 |
| 5 | **`ARCHITECTURE.md`** | The proposed QAYD AI engine in depth — trust zones, layer allocation, pipeline stages, four sequence flows, the injection defence stack, what runs where | 1,049 |
| 6 | **`LESSONS_FOR_QAYD.md`** | Twenty-two lessons (L-01…L-22) mapped onto QAYD's existing principles, patterns, innovations and stories, each marked confirms / extends / corrects / contradicts | 518 |
| 7 | **`IMPLEMENTATION_RECOMMENDATIONS.md`** | Twenty items (AIR-01…AIR-20) sequenced against the real stories S3-07/08/09 and S4-01/02/03/04/09/10/11/12, with effort and confidence | 695 |

Read in that order once. After that, `ARCHITECTURE.md` and `IMPLEMENTATION_RECOMMENDATIONS.md` are the
two that get re-opened.

---

## Evidence grades

Every substantive claim in these documents carries one:

| Grade | Meaning |
|---|---|
| **`[DOCS]`** | Vendor documentation or engineering blog, URL cited. Describes shipped behaviour. |
| **`[PAPER]`** | Peer-reviewed or arXiv preprint, cited. |
| **`[INDEPENDENT]`** | Third-party evaluation not authored by the vendor whose product is measured. |
| **`[CODE]`** | Verified by reading QAYD's own repository. File and line given. |
| **`[COMMUNITY]`** | Practitioner writing, credible but unrefereed. |
| **`[INFERENCE]`** | Our reasoning from the above. Labelled so it can be argued with. |
| **`[UNKNOWN]`** | We could not verify it. Stated rather than guessed. |

**A standing warning about this field.** It is saturated with announcement-as-fact. Vendor benchmarks
are systematically self-favouring in both directions — Timescale's pgvector numbers and Qdrant's
counter-numbers are both real measurements of carefully chosen conditions. Every number below has been
traced to a primary source or marked `[UNKNOWN]`. Where a widely-repeated claim has no primary source,
that is said explicitly.

---

## Relationship to prior work — read this before adding anything

These documents **cross-reference and never restate**. If a topic is already settled elsewhere, the
correct move is a pointer, not a paragraph.

| Already settled — do not re-litigate | Where |
|---|---|
| The AI may never write a financial table; the boundary is a database GRANT | `01_ENGINEERING_PRINCIPLES.md` **P15** |
| The canonical proposal → human → Action mechanism, its invariants and tests | `03_DESIGN_PATTERNS.md` **P-12** |
| Immutability and correction-by-reversal | `03_DESIGN_PATTERNS.md` **P-13** |
| Why an AI must not write domain tables; why confidence thresholds fail; why stored prompts are code; why an LLM must not do arithmetic | `04_REJECTED_PATTERNS.md` **R-31 · R-32 · R-33 · R-34** |
| Token economics, prompt-caching arithmetic, the $14-vs-$45 figure, batching, tiering | `05_FUTURE_ARCHITECTURE.md` **§E** |
| Twenty product inventions built on the AI layer | `07_QAYD_INNOVATION.md` **I-01…I-20** |
| Sprint sequencing and per-story constraints | `08_MASTER_BACKLOG.md` |
| The AI product specification — 13 agent roles, memory tables, workflows | `docs/ai/**` (~31,700 lines) |

**What this phase adds that none of those contain:** the internal architecture of the engine itself —
its trust zones, its context assembly discipline, its retrieval and memory tiers, its injection-defence
layering, its evaluation harness, its prompt-versioning mechanism, and a concrete answer to whether
"13 agents" means 13 loops (it does not).

---

## Headline findings

Ten things this research changes or settles. Each is argued in full in the documents named.

**1 · Multi-agent is the wrong shape for accounting, and there is now evidence rather than opinion.**
Anthropic measured multi-agent research systems at **~15× the tokens of chat** and explicitly named the
poor-fit conditions: tasks where "all agents share the same context" or that "involve many dependencies
between agents" `[DOCS]`. Double-entry accounting is the canonical dependency-dense, shared-context,
consistency-critical task. Cognition, building a coding agent, reached the same conclusion from the
other direction. → `ANTI_PATTERNS.md` **A-02**, `OVERVIEW.md` §2.

**2 · The "13 agents" in `docs/ai/` should be 13 task specifications on one runtime, not 13 loops.**
The decomposition QAYD needs is **capability scoping** (which tools, which data, which autonomy) — not
process proliferation. Nothing in the product spec requires concurrent agents; everything in it
requires scoped permissions. → `LESSONS_FOR_QAYD.md` **L-03**, `ARCHITECTURE.md` §4.

**3 · The engine should hold no database driver, and retrieval should be a Laravel-mediated read API.**
There is an unresolved contradiction in the prior work: `P15` treats "no DB driver in `apps/ai`" as real
enforcement worth a CI check (Gap G-8), while `P-12` specifies a `qayd_ai` database role. Both cannot be
the primary. We recommend **no driver**, because it is the cheapest auditable enforcement in the entire
system and because it forces the retrieval *query* to be authored in the trusted zone — which is
precisely the property that defeats prompt injection by design. → `ARCHITECTURE.md` §3.4,
`IMPLEMENTATION_RECOMMENDATIONS.md` **AIR-02**.

**4 · R-02 answered: pgvector in the primary database — conditionally, with a named trigger to revisit.**
Not because pgvector wins on raw vector performance (at nine-figure scale it does not), but because
QAYD's retrieval must be tenant-isolated and RLS is the isolation mechanism the architecture rests on. A
second store means a second implementation of tenant isolation with a different failure mode. The
condition is that the *embedded corpus is kept small by design* — judgements, precedents and patterns,
not raw document text. Trigger metrics given. → `OVERVIEW.md` §5.4,
`IMPLEMENTATION_RECOMMENDATIONS.md` **AIR-07**.

**5 · Naive RAG would be a regression, not a feature, on QAYD's hottest path.**
"Which account does this vendor map to?" has an exact answer in a structured table
(`ai_categorization_rules`, already specified). Answering it with a similarity search replaces a correct
lookup with a probabilistic one — `R-34` in vector form. Embeddings are QAYD's *third* retrieval choice,
after exact structured lookup and applicability predicates. → `ANTI_PATTERNS.md` **A-03**.

**6 · Prompt caching's minimum-prefix numbers in `05_FUTURE_ARCHITECTURE.md` are now out of date, and the
error is in the safe direction for one model and the unsafe direction for another.** Current published
minimums: Opus 4.8 and Sonnet 5 = **1,024** tokens; Haiku 4.5 = **4,096**; Opus 4.5/4.6 = 4,096; Opus 5 =
512 `[DOCS]`. The document states 4,096 for Opus 4.8. The engineering conclusion is unchanged and
strengthened: **assert on `cache_read_input_tokens` in an integration test rather than trusting a
constant that moves.** → `OVERVIEW.md` §8.2.

**7 · Confidence is only meaningful if it is measured, and QAYD is one of the few systems that will have
the labels to measure it.** `R-32` correctly forbids confidence from authorising anything. The
constructive complement — nobody in this market ships it — is **calibration measurement**: reliability
curves and Brier scores per model version per task, computed from accepted/edited/rejected outcomes.
Cheap once `P-12`'s proposal tables and the Correction Corpus (`S3+A`) exist. →
`BEST_PRACTICES.md` **B-11**.

**8 · "Human approval" is a defence against error, not against attack — and it degrades measurably.**
An injected proposal is optimised to look right, so review does not stop it. Review *does* stop model
error, but only while it is real. Make it measurable: **time-to-approve distributions**, **blind
rejection sampling** of a fixed fraction of high-confidence proposals, and an approval UI that cannot be
completed without looking at the number. → `BEST_PRACTICES.md` **B-12**, `ANTI_PATTERNS.md` **A-08**.

**9 · The exfiltration leg of the lethal trifecta is closable, cheaply, and QAYD should close it in
S3-07.** The AI engine should have **no outbound network except an allowlisted model-provider endpoint**,
and the UI must never render or fetch a model-authored URL. EchoLeak (CVE-2025-32711, CVSS 9.3) worked
by exfiltrating through an allowlisted image proxy after the injection classifier was bypassed `[DOCS]`.
→ `ARCHITECTURE.md` §7, `BEST_PRACTICES.md` **B-14**.

**10 · Evals are a Sprint 3 deliverable, not a Sprint 5 one — because the labels are generated by Sprint
3 and cannot be backfilled.** Every rejected proposal, every edit-then-accept, and every reversal is an
expert-authored label produced in the ordinary course of work. Designed in, it is nearly free; retrofitted,
it does not exist. This is `S3+A` in the backlog and it is under-rated there. →
`IMPLEMENTATION_RECOMMENDATIONS.md` **AIR-05**.

---

## What this research deliberately does not do

- **It does not copy code or imitate any product's architecture.** Principles only. Where a vendor's
  design is described, it is described to extract the *reason* it works or fails.
- **It does not re-invent I-01…I-20.** Several recommendations *serve* those inventions (I-05, I-09,
  I-10, I-12, I-17 especially); none replaces them.
- **It does not relax the posting boundary.** Nothing here proposes autonomy over the ledger. Where
  autonomy is discussed it is bounded, reversible, and outside the ledger.
- **It does not price anything.** `05_FUTURE_ARCHITECTURE.md` §E owns the dollar arithmetic; this phase
  corrects two of its inputs and adds the levers it did not cover.
- **It does not settle product questions.** Whether reviewers will tolerate the approval discipline in
  §B-12 is a design-partner question, not an engineering one, and it is flagged as such.

---

## Maintenance

This document set is **research, not specification**. It becomes specification only when a recommendation
is promoted into `08_MASTER_BACKLOG.md` with a tier, a value, a dependency list and a sprint — the
intake rule, unchanged.

Re-verify before relying on any of it:

| What | Why it moves | How to check |
|---|---|---|
| Model prices and cache minimums | Change without notice, silently | Provider pricing pages; assert in integration tests |
| Attack-success-rate figures | Every model release changes them | Vendor system cards; re-run internal red-team set |
| pgvector / vector-store benchmarks | Vendor-published, adversarially selected | Benchmark on QAYD's own corpus before switching |
| "Shipped vs announced" for any competitor | The gap is months and sometimes permanent | Product documentation, not press releases |

Anything found to be wrong should be corrected **in place, with the date**, not appended to.

# End of Document
