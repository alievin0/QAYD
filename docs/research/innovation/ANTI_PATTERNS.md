# ANTI_PATTERNS — AI-finance ideas that sound good and are bad

**Fourteen capabilities that demo well, fund well, and fail in the same direction · `docs/research/innovation/`**

Version 1.0 · 2026-07-28 · **Documentation only** — no application code, schema, migration or test was
modified in producing this.

---

## How to read this file

`04_REJECTED_PATTERNS.md` **R-31…R-34** refuse four *architectural* patterns: an AI writing domain
tables, trusting model output without a confirmation boundary, prompts stored as executable code, and an
LLM where a rule suffices. `docs/research/ai/ANTI_PATTERNS.md` **A-01…A-16** refuses sixteen *engineering*
patterns inside the AI layer.

This document refuses **product ideas**. Every entry below is a capability a reasonable person will
propose, a competitor has announced, or an investor will ask about. None is stupid. Each fails at a
specific, nameable point, and naming the point is the deliverable — because the alternative is refusing
them by taste, and taste does not survive a board meeting.

Each entry carries: what it is · why it is tempting · why it fails, mechanically · the honest, narrow
version where one exists · the transferable form · what to do instead · where it would first appear.

**Evidence grades** are those declared in `README.md`: `[DOCS]` `[PAPER]` `[INDEPENDENT]` `[CODE]`
`[COMMUNITY]` `[INFERENCE]` `[UNKNOWN]`.

---

## The single finding this document exists to state

Read this even if you read nothing else.

> **The two most-requested AI-finance interfaces — speak-to-book and ask-the-database — both fail
> silently, confidently, and in the same direction. Neither returns an error. Both return a plausible
> wrong answer that a competent professional cannot distinguish from a right one without redoing the
> work.**

That is not an aesthetic objection. It is the strongest available empirical argument for the exact
boundary QAYD already built. A system whose failures announce themselves can be trusted with autonomy in
proportion to its accuracy. A system whose failures are indistinguishable from successes cannot be
trusted with autonomy *at any accuracy*, because the residual is undetectable by the only party who
could catch it.

`trg_no_ai_autopost` `[CODE: apps/api/database/migrations/2026_07_28_000004_create_journal_entries_table.php:144-156]`
is therefore not caution. It is the correct engineering response to a measured failure mode.

---

## Index

| ID | Anti-pattern | Category | Where it would first appear |
|---|---|---|---|
| **IA-01** | Voice-driven bookkeeping (speak → journal entry) | Interface | Product roadmap; any "hands-free" demo |
| **IA-02** | Natural-language ERP as the whole product | Interface | I-16 scope creep; S4-10 Copilot |
| **IA-03** | Conversational commitment — removing the form from the write path | Interface | S4-04 approval UI |
| **IA-04** | The autonomous close | Autonomy | I-06 scope creep; S4-01 `AutonomyResolver` |
| **IA-05** | Autonomous audit — the machine that assures itself | Autonomy | I-08/I-15 positioning |
| **IA-06** | The AI CFO — strategic advice with no recorded claim | Autonomy | Any "advisor" or "insights" surface |
| **IA-07** | The multi-agent finance department | Autonomy | `docs/ai/agents/*`, S4-01, S4-09 |
| **IA-08** | AI-proposed tax positions | Autonomy | Tax module; I-25 scope creep |
| **IA-09** | Prediction without a measurement date | Measurement | I-19, I-22, I-26, any risk score |
| **IA-10** | Confidence as a user-facing number | Measurement | S3-08, S4-04 |
| **IA-11** | Manage-by-exception — undisclosed queue truncation | Measurement | Every AI capability's default design |
| **IA-12** | Benchmark scores as evidence of product safety | Measurement | Sales collateral; model selection |
| **IA-13** | The proprietary finance model | Moat | Any "we'll fine-tune on accounting" proposal |
| **IA-14** | Retrofitting the record — "we'll add provenance later" | Moat | Every sprint that defers I-04/I-09/I-31 |

---

# Category A — The interface fantasies

## IA-01 — Voice-driven bookkeeping

*"Just say it and the books update." The flagship entry, and the one with the most evidence against it.*

**What it is.** A microphone as an input path to the ledger. The user speaks a transaction — *"pay
Al-Mulla four hundred and twenty dinars for the September maintenance invoice"* — and the system
transcribes, parses, and produces a posting. In its ambitious form the posting is committed. In its
modest form it is a draft the user confirms.

**Why it is tempting.** Every reason is real:

- It is the single most demo-friendly capability in the category. It photographs well and it films well.
- Data entry genuinely is the worst part of the job, and speech is genuinely faster than typing.
- Speech recognition has been *announced* as solved for years, and English-language WERs support that
  impression.
- The GCC market has a plausible-sounding accessibility story: a shop owner who does not type quickly in
  either script.
- Two large vendors have shipped something in this shape, which makes refusal look like being behind.

**Why it fails.** Four independent mechanisms, in ascending order of how badly each one hurts.

### 1 · Baseline dialect accuracy is not close to sufficient

The Open Universal Arabic ASR Leaderboard (Interspeech 2025) is the relevant independent benchmark
because it separates Modern Standard Arabic from regional dialects instead of averaging them.

| System | Khaliji (Gulf) WER | MSA WER |
|---|---|---|
| **Best model measured** (`nvidia/conformer-ctc-large-ar`) | **48.23%** | — |
| `whisper-large-v3` | **59.92%** | **27.95%** |

`[PAPER: Open Universal Arabic ASR Leaderboard, Interspeech 2025]`

**Roughly one word in two is wrong**, from the best independently-measured system, on the exact dialect
QAYD's first market speaks. The MSA figure is the trap: a vendor can quote 27.95% honestly, ship into
Kuwait, and encounter 59.92%. The gap between the number you can cite and the number you will get is
**32 percentage points**, and it is invisible unless the benchmark disaggregates dialect — which most do
not.

### 2 · Code-switching, which is how the market actually speaks, is catastrophic

Gulf business speech mixes Arabic and English constantly, and account names, vendor names and system
terms are frequently the English ones. The Mixat corpus (SIGUL @ LREC-COLING 2024) measured this
directly, on the same recordings:

| Segment type, same audio | Whisper WER |
|---|---|
| English-only segments | **12.06%** |
| Code-switched segments | **121.78%** |

`[PAPER: Mixat, SIGUL @ LREC-COLING 2024]`

That is a **~10× degradation within a single recording**, and a WER above 100% means the transcript
contains more errors than the reference contains words — insertions and substitutions compounding. The
paper's own verdict on the models it tested is that none produced satisfactory transcriptions,
*"rendering them unusable for this task"* `[PAPER: Mixat, SIGUL @ LREC-COLING 2024]`.

A vendor is entitled to argue that a benchmark is not their production distribution. A vendor is not
entitled to argue past a 121.78% WER on the precise linguistic behaviour their target users exhibit by
default.

### 3 · The failure is silent, confident, and the wrong *task*

This is the mechanism that moves IA-01 from "not yet good enough" to "structurally wrong for this
application."

Whisper's documented response to Gulf code-switching is not to garble. It is to **fluently translate the
utterance into Modern Standard Arabic** — recognising the dialect correctly and then performing a
different task from the one requested `[PAPER: Open Universal Arabic ASR Leaderboard / Mixat analyses]`.
For a captioning product that is a quality issue. For **data entry it is a paraphrase engine wearing the
costume of a transcriber**: the system returns confident, grammatical, plausible text that is not what
the user said, with no signal that a substitution occurred.

Compare the failure modes:

| Input path | When it fails | What the user sees |
|---|---|---|
| Typing | Typo | The wrong characters, visible |
| OCR on an invoice | Low confidence, garbled glyphs | Obvious corruption, or a flagged low-confidence field |
| **Dialect ASR** | **Dialect + code-switching** | **Clean, fluent, confident, different** |

The user's error-detection capability is highest for the first, moderate for the second, and **near zero
for the third**, because nothing on screen looks wrong.

### 4 · WER is measuring the wrong thing, and it is doing so in the flattering direction

Even taking WER at face value understates the risk for accounting specifically:

- **The Arabic leaderboard normalises Eastern Arabic numerals (٠١٢٣) to Western (0123) before scoring**
  `[PAPER: Open Universal Arabic ASR Leaderboard]`. Numeral-form confusion — the single most
  consequential error class in bookkeeping — is *defined out of the metric*.
- Where numeric accuracy has been measured directly, **currency-expression accuracy tops out at
  95.6–98.5%, in English, with a fine-tuned model** `[PAPER — re-verify the exact figures and their
  source conditions before any external use]`. At the optimistic end that is **roughly one amount in
  sixty-five wrong**.
- WER is a *word*-level average. An accounting utterance is not word-uniform: the vendor name, the
  amount, the currency and the date carry essentially all the consequence, and the connective tissue
  carries none. A 48% average WER says nothing about whether the 48% landed on "four hundred and twenty"
  or on "for the".

**The synthesis:** *anyone citing a WER as evidence that voice accounting works is citing a metric that
specifically excludes the failure class that matters.* That sentence belongs in the objection-handling
notes.

### What the two shipped implementations actually do

Both vendors who shipped in this space stopped at exactly the line this document draws.

| Product | What it does | What it does **not** do |
|---|---|---|
| **Zoho Books** Speech Assistant | Lands the transcription as **editable text** requiring explicit confirmation `[DOCS]` | Post from speech |
| **QuickBooks** | **Intent-scoped Siri Shortcuts** — a small closed set of pre-defined operations `[DOCS]` | Open-vocabulary dictation into the ledger |

**Nobody has shipped speak → posted journal entry.** `[INFERENCE — absence of a product is weakly
evidenced; re-check before claiming it externally]` Two independent teams with far more resources than
QAYD converged on human confirmation, and QuickBooks converged additionally on *closing the vocabulary*
— which is the correct engineering response to open-vocabulary ASR being unreliable.

### The honest, narrow version — and it is genuinely worth building

Voice is not banned. **Voice is banned from the write path, and welcome on the read path**, which is
`OVERVIEW.md` **C5** applied to a microphone:

| Use | Verdict | Why |
|---|---|---|
| *"What did we spend on fuel last month?"* | **Yes** | A misheard query returns a visibly wrong answer or no answer. The failure is self-announcing. |
| *"Show me the Al-Mulla account"* | **Yes** | Navigation. Worst case is the wrong screen, which the user sees. |
| Voice **memo attached to a draft**, transcribed, stored as an artefact, never parsed into fields | **Yes** | The audio is the record; the transcript is a convenience. Nothing is asserted. |
| Voice → **structured proposal → form → human confirm** | **Only with the constraint display intact** — see IA-03 | Still weak, but the form re-supplies the error-detection surface |
| Voice → posted entry | **Never** | Everything above |

The strongest narrow version is the **closed-vocabulary command**, following QuickBooks: a fixed,
enumerable set of intents over a fixed set of resolvable entities, where an unrecognised utterance is a
refusal rather than a best guess. That is a small, honest feature. It is not "voice accounting", and it
should not be sold as such.

**Transferable form.** *An input channel may only be trusted in proportion to how visibly it fails.
Channels whose errors are fluent belong on the read path.*

**Instead.** Read-path voice; closed-vocabulary commands; voice memos as attached artefacts. If dialect
ASR is ever revisited, the gate is a **measurement on QAYD's own audio**, disaggregated by dialect and
code-switching, scored on **entity-level accuracy (amount, counterparty, date, account)** rather than
WER, with numerals *not* normalised away.

**Where it would first appear.** A product-roadmap line item; a demo request; an accessibility
requirement.

**Confidence.** **High** that the write path is wrong — this is the best-evidenced refusal in the folder.
**Medium** on the read-path carve-out, which is reasoning rather than measurement.

---

## IA-02 — Natural-language ERP as the whole product

*"Delete the 400 forms and the 200 reports; just ask." The second half of the same failure.*

**What it is.** Positioning natural language as the primary interface to the entire system — not as a
query convenience but as the product thesis. In its strong form the model receives a question and
composes a query against tenant data.

**Why it is tempting.** For **retrieval**, natural language is straightforwardly better, and pretending
otherwise is dishonest. Nobody wants to build a report to learn last quarter's freight spend. Training
cost collapses. The demo is spectacular. `OVERVIEW.md` §1.2 concedes all of this.

**Why it fails.** The demo is run on a clean schema. The product runs on a real one, and there is now a
benchmark that measures precisely that difference.

### 1 · The enterprise cliff is measured

| Benchmark | What it measures | Result |
|---|---|---|
| **BIRD** | Text-to-SQL on curated academic schemas | Human baseline **92.96%**; top systems **~82%** `[PAPER]` |
| **BEAVER** | Text-to-SQL built from **real enterprise data warehouses** | **GPT-4o: close to 0% end-to-end accuracy** `[PAPER]` |
| **LogicCat** | Complex multi-step reasoning queries | **SOTA at most 33.20%** `[PAPER]` |

The ~10-point gap on BIRD is the number that gets quoted in decks. **BEAVER is the number that matters**,
because a general ledger with a customer-extended chart of accounts, analytic dimensions, multi-currency
lines, period locks and tenant partitioning is a real enterprise schema, not a curated one. Near-zero
end-to-end accuracy is not a tuning problem.

### 2 · The one intervention with strong evidence is a semantic layer, not a better model

Five independent lines of evidence converge on the same fix — give the model a curated, governed
semantic layer instead of the raw schema:

| Source | Reported improvement |
|---|---|
| Cube `[DOCS — vendor, self-favouring]` | Large gain from semantic-layer mediation |
| dbt Labs `[DOCS — vendor, self-favouring]` | Large gain from metric definitions |
| data.world `[DOCS — vendor, self-favouring]` | Large gain from a knowledge-graph context layer |
| Bayer / JAMIA `[PAPER — independent]` | Independent replication in a clinical setting |
| BIRD "external knowledge" ablation `[PAPER — independent]` | The benchmark's own evidence that context is what moves the score |

**Aggregate range: +17 to +70 percentage points** `[INFERENCE from five sources of mixed grade — three
are vendor-published and systematically self-favouring; two are independent. The *direction* is
well-supported; the *magnitude* should not be quoted as a single number.]`

### 3 · The design lesson is about the shape of the failure, not the size of it

This is the sentence to carry out of the section:

> **Semantic-layer failures are refusals — "I can't answer that." Raw text-to-SQL failures are confident
> wrong numbers.** Cube's name for the second is **"silent hallucination"** `[DOCS]`.

A refusal costs the user thirty seconds and a rephrase. A confident wrong number costs whatever it costs
— and in finance, a number that leaves the system in a board pack, a covenant certificate or a filing
does not come back for correction. It becomes the basis of a decision.

That asymmetry, not the accuracy delta, is why the semantic layer is mandatory rather than an
optimisation.

### 4 · QAYD's version of the semantic layer already exists and is called something else

`07_QAYD_INNOVATION.md` **I-16** — natural-language accounting as **reviewable predicates** — is exactly
this architecture reached by a different route: the model emits a *structured, inspectable predicate*
that a deterministic engine executes, rather than composing SQL. The model's output is reviewable before
execution, and an unmappable question produces a refusal rather than a guess.

**IA-02's contribution is the evidence that I-16 was not a stylistic preference.** It is the only
approach in this space with a documented accuracy case, and its failure mode is the survivable one.

### The honest, narrow version

- **Read path, mediated.** Natural language → typed predicate over a governed semantic model → executed
  by code → answer rendered with **I-12 number provenance** so every figure dereferences to its rows.
- **Refusal is a first-class, celebrated outcome.** The coverage metric ("we can answer 71% of asked
  questions") is a better product surface than a hidden failure rate. Ship the refusal rate.
- **No model-composed SQL against tenant data. Ever.** This is `docs/research/ai/` **L-04**
  (no database driver in the engine) with an accuracy justification stacked on top of the security one.

**Transferable form.** *When two designs have similar accuracy and different failure shapes, choose the
one that fails loudly. When one has better accuracy* and *a louder failure, the argument is over.*

**Instead.** I-16 as specified. `docs/research/ai/ARCHITECTURE.md` §6 three-tier retrieval. Never a
free-text-to-SQL path.

**Where it would first appear.** As scope creep on I-16; as the S4-10 Copilot growing a query tool; as a
competitor's demo triggering a "why can't we do that" cycle.

**Confidence.** **High.** BEAVER's near-zero is decisive for the raw form, and the semantic-layer
direction is supported by an independent replication as well as vendor claims.

---

## IA-03 — Conversational commitment (removing the form from the write path)

*The "no more forms" pitch, applied to writes.*

**What it is.** Replacing the journal-entry form, the invoice form and the payment form with a
conversation, on the reasoning that forms are the hostile part of finance software.

**Why it is tempting.** Forms *are* the hostile part. Onboarding cost is real, and the count of fields in
a mature ERP is genuinely indefensible.

**Why it fails.** `OVERVIEW.md` §1.2 makes the structural argument and it is not repeated in full. The
one-line version, because it is the load-bearing sentence in this whole category:

> **In finance the form is not an input mechanism. It is a constraint display.**

A journal-entry form renders the chart of accounts, the open periods, the tax codes, the approval state,
the currency, and which dimensions are mandatory for this account. Those are *the rules, displayed at the
moment of commitment*. The user learns them by being shown them, and — the part that matters — **notices
when they are about to do something that does not fit.**

A chat box displays none of it. It accepts any sentence, resolves it silently, and returns something
confident. **The user's last error-detection surface is removed at exactly the moment it is most needed.**

This is `04_REJECTED_PATTERNS.md` **R-30** (silent degradation) relocated to the UI layer, and it stacks
with IA-01 and IA-02: an input channel that fails fluently, feeding a resolution step that fails
silently, into a commitment surface that displays no constraints. Three silent failures in series.

**The honest, narrow version.** *Language proposes; the form disposes.* An utterance or a sentence
produces a **pre-filled form with every constraint still rendered** and every model-supplied value
visibly marked as such. The human's act of commitment happens on a surface that shows the rules. This is
`03_DESIGN_PATTERNS.md` **P-12** with a UI obligation attached, and it costs nothing extra to build
because the form already exists.

**Transferable form.** *Never remove the display of a constraint from the surface where the constraint is
about to be violated.*

**Instead.** Chat and voice for retrieval and navigation; pre-filled forms for commitment; model-supplied
fields visually distinguished from human-supplied ones so review has something to attach to.

**Where it would first appear.** S4-04's approval UI, as a simplification.

**Confidence.** **High** on the mechanism. **Medium** on how much friction the pre-filled form should
retain — a design-partner question, not an engineering one.

---

# Category B — The autonomy fantasies

## IA-04 — The autonomous close

*Books that close themselves.*

**What it is.** The month-end close executed by the system, with humans handling exceptions only.

**Why it is tempting.** Close is the most painful recurring event in the calendar, the labour arithmetic
is compelling, and two credible vendors ship bounded versions — Digits auto-resolves clear-cut close
issues; Ramp auto-posts accruals under customer configuration `[DOCS]`. Digits shipped Agentic Close to
GA on 8 June 2026 `[DOCS]`, so "nobody does this" is no longer available as an argument.

**Why it fails.** `07_QAYD_INNOVATION.md` §5.4 argues this at length and is not repeated. The additions
this document makes:

1. **The sub-score, not the headline score, is the relevant measurement.** The DualEntry Labs benchmark's
   Round 1 reported roughly **92% on GAAP/IFRS recall against 30–40% on journal-entry creation** `[DOCS
   — vendor-run benchmark]`. **Models know the rules and cannot reliably produce the record.** Close is
   almost entirely record production.
2. **The headline figure has moved and must be quoted correctly.** Round 1's 66.0% is stale; the same
   public leaderboard shows Round 2 at **77.3%** and a current top of **83.2%** across 31 models from 8
   providers `[DOCS: dualentry.com/accounting-ai-benchmark]`. Quote it as *"66.0% in Round 1, 83.2%
   today"* or not at all — see IA-12.
3. **"Autonomous" is category-destroying, not merely risky** (`OVERVIEW.md` §1.3). It defines the product
   by the one thing the customer cannot verify, every competitor can claim it, and the claim collapses
   into noise — while the buyer's actual question ("how do I know it's right?") goes unanswered by the
   entire category.

**The honest, narrow version.** **I-06** — the close as a continuously-maintained *diff*. The system
maintains, at all times, the list of what stands between now and a closeable period, with each item
attributed and evidenced. The human closes; the system removes every reason they had to hunt. That
delivers most of the labour saving and claims nothing unverifiable.

**Transferable form.** *Automate the search for what is wrong. Never automate the assertion that nothing
is.*

**Instead.** I-06; I-18's detect-and-wait principle; S4-01's `AutonomyResolver` bounded by I-17's
reversibility budget, which is denominated in *reversibility*, never in confidence (`R-32`).

**Where it would first appear.** I-06 scope creep; a competitive-response cycle after a Digits release.

**Confidence.** **High** on the refusal. **Medium** on where exactly the bounded-autonomy line sits — the
DualEntry trend is genuinely moving and the line should be re-derived annually.

---

## IA-05 — Autonomous audit

*The machine that assures itself.*

**What it is.** AI performing the assurance function: testing controls, sampling, concluding, and
producing something audit-shaped.

**Why it fails.** A structural circularity, and it is fatal rather than difficult:

> **Assurance derives its value from the independence of the assurer.** A system that both produces the
> records and attests to them has produced a *self-certification*, which is worth exactly what any
> self-certification is worth. Improving the model does not help, because the defect is in the
> relationship, not the capability.

Secondary, and sufficient on its own: audit conclusions are professional judgements attached to a person
with a licence, insurance and liability. `07` §5.3 already covers the liability question; the point here
is that the signature is not an artefact the software can produce.

**The honest, narrow version — and it is the strongest idea in the folder.** Do not automate the auditor.
**Make the auditee's records independently verifiable**, so that whoever does the assurance spends their
time on judgement rather than on reconstruction. That is **I-08** (offline-verifiable audit receipts),
graded in `07` §4 as the single most durable moat in the document, on the basis that a competitor
shipping the same hash chain in 2029 starts their chain in 2029.

`OVERVIEW.md` **C7** adds the commercial correction: sell transferable trust to whoever *pays for* the
audit (auditee, lender, acquirer), not to whoever *performs* it — because verification labour is billable
and the firm's incentive runs the other way (`07` §5.5 #1).

**Transferable form.** *You cannot sell independence you do not have. You can sell verifiability to the
party who benefits from someone else's independence being cheap.*

**Instead.** I-08; I-15 reframed toward lenders and acquirers; `OVERVIEW.md` P3's one-conversation test
before building the full bundle.

**Confidence.** **High** on the circularity. **Medium** on the buyer reframing — `OVERVIEW.md` flags it
as an untested prediction and it should be tested with one conversation, not a sprint.

---

## IA-06 — The AI CFO

*Strategic advice, unrecorded and unmeasured.*

**What it is.** A surface that produces recommendations — pricing, hiring, financing, working capital,
whether to take the discount — from the customer's own numbers.

**Why it is tempting.** It is the largest value claim available, it is what an SME owner without a
finance function genuinely needs, and it is the most fundable sentence in the category.

**Why it fails.** Not because the advice is necessarily bad. Because **the product is incoherent with
itself**:

1. **It contradicts the thesis.** `OVERVIEW.md` **C4**: any forward-looking output is an assertion about
   the future, made by a system whose entire pitch is that assertions are recorded and checkable.
   Emitting advice unrecorded, unscored and unattributed is the one thing this product may not do.
2. **It is unfalsifiable by construction.** Advice that is never scored cannot be wrong, which means it
   also cannot be right, which means it cannot compound into an asset. It is the opposite of the record.
3. **Regulatory exposure.** In several GCC jurisdictions specific classes of financial advice are a
   licensed activity `[UNKNOWN — jurisdiction-specific; requires local legal review before any advisory
   surface ships]`. The relevant risk is not building it; it is building it and discovering the boundary
   afterwards.

**The honest, narrow version.** **The advice record** (`OVERVIEW.md` I-23): every recommendation is
stored as a falsifiable claim — what was recommended, on what evidence, by which model version, under
what assumption, **with a date on which it will be scored**. Then publish the hit rate.

That inverts the entire category's posture. Everyone else ships confident advice with no scoreboard.
Publishing a scoreboard requires being willing to look bad, which is precisely why it is defensible: the
capability is copyable in a sprint, and **three years of scored advice is not.**

**Transferable form.** *A recommendation that is never scored is marketing. Store the claim, store the
measurement date, publish the hit rate — or do not emit the recommendation.*

**Instead.** I-23 discipline on every prescriptive surface; descriptive framing (I-22's banded cashflow)
where a claim is not worth storing; local legal review before any advisory surface ships.

**Confidence.** **High** on the incoherence argument. **Low** on the regulatory specifics, which are
explicitly not researched here.

---

## IA-07 — The multi-agent finance department

*An AP agent, an AR agent, a tax agent, and a controller agent that supervises them.*

**What it is.** Modelling the finance function as concurrently-executing model-driven agents that
communicate — the product-level version of what `docs/research/ai/ANTI_PATTERNS.md` **A-02** and **A-07**
refuse at the engineering level.

**Why it is tempting.** It is the most legible architecture in the field, it maps onto how humans
organise work, it demos beautifully, every framework supports it first-class, and Anthropic published a
**90.2%** improvement from exactly this pattern `[DOCS]`.

**Why it fails.** The 90.2% and the **~15× token multiplier** come from the same system, and the
qualifying conditions are stated explicitly: multi-agent suits *breadth-first* tasks with independent
directions, and fails where agents "share the same context" or have "many dependencies between agents"
`[DOCS]`.

**Double-entry accounting is the canonical dependency-dense, shared-context, consistency-critical task.**
Lines must balance. Matches must not double-consume. Postings must respect a period lock. A trial balance
must tie. Every one of those is a cross-sub-result invariant, and an architecture whose members decide
separately is a machine for producing entries that each look fine and do not agree.

The product-level addition to A-02: **the org-chart framing also imports the org chart's failure mode.**
A "controller agent supervising" is a supervision claim the architecture cannot honour, and it makes
accountability diffuse in a domain whose entire value is that accountability is precise.

**The honest, narrow version.** The thirteen roles in `docs/ai/` are **thirteen capability scopes on one
runtime**, not thirteen loops (`docs/research/ai/` **L-03**). The decomposition finance actually needs is
*which tools, which data, which autonomy* — and `OVERVIEW.md` I-27 (capability grants) is the product
expression of that: authority modelled as explicit, scoped, delegated, expiring and revocable, rather
than as an agent with a job title.

**Transferable form.** *Concurrency between model-driven components is safe exactly when their outputs
never have to agree. Pay 15× for breadth; never pay it for coherence.*

**Instead.** One control loop owned by code; capability scoping; `docs/research/ai/` **AIR-18**.

**Confidence.** **High** — this one has measurement on both sides of the tradeoff.

---

## IA-08 — AI-proposed tax positions

*"It found KWD 14,000 of deductions you missed."*

**What it is.** A model examining the books and proposing tax treatments, elections, or optimisations.

**Why it is tempting.** Quantifiable ROI in a currency the buyer already thinks in, and the GCC's
evolving tax landscape (VAT regimes, the corporate-tax transitions across the region) means genuine
uncertainty that a well-informed system could help with.

**Why it fails.** Three ways, and the third is the one people miss:

1. **A tax position is an assertion to a third party with penalties attached.** The consequence is not
   internal.
2. **The knowledge/production gap applies with unusual force.** Models score well on rule *recall* and
   poorly on record *production* (§IA-04). Tax is precisely "recall a rule, then apply it to a specific
   set of facts correctly" — and the second half is where the measured weakness is.
3. **The optimisation framing selects for the wrong errors.** A system rewarded for finding savings is
   a system biased toward aggressive positions, and its errors will be **systematically in the direction
   the authority disputes.** A neutral model that is wrong 10% of the time in random directions is a very
   different risk from an optimising model that is wrong 10% of the time in one direction.

**The honest, narrow version.** The **tax position register** (`OVERVIEW.md` I-25): every position the
company has taken, recorded with its basis, its authority, its evidence, its author and its date — so
that when a rule changes, or an authority queries, the blast radius is computable rather than
archaeological. That is a record product, not an advice product, and it is the thing the customer
actually cannot buy elsewhere.

Detection is also acceptable where framed as **a question rather than a saving**: *"seven transactions
this quarter are coded differently from equivalents last quarter"* is an anomaly, not a recommendation.

**Transferable form.** *Never optimise against a counterparty who can impose penalties, on behalf of a
user who will not check the working.*

**Instead.** I-25; I-20's regime twin for rule-change simulation; anomalies framed as questions.

**Confidence.** **High** on the directional-bias argument, which generalises well beyond tax.

---

# Category C — The measurement fantasies

## IA-09 — Prediction without a measurement date

*Risk scores, health scores, cashflow lines, "insights".*

**What it is.** Emitting any forward-looking number — a runway projection, a customer-risk score, a churn
probability, a fraud likelihood — without storing it as a claim with a date on which it will be scored.

**Why it is tempting.** Storing predictions is extra work with no visible payoff in the sprint that does
it. The number renders fine without it.

**Why it fails.** Three compounding reasons:

1. **The error becomes unattributable.** When a projection is wrong, you cannot tell whether the model
   was bad or the world changed — which means you cannot improve it and cannot defend it.
2. **The window closes.** Predictions not stored at emission time cannot be recovered. This is the same
   structural property as I-04, I-09 and I-31: **every week without it is a week of permanently
   unrecoverable data.** It is the cheapest of the closing windows and the most often deferred.
3. **The market's track record argues the feature is not what is scarce.** Practitioner surveys report
   **only ~40% of organisations achieving high or good forecast accuracy, down 13 points from 53% in
   2021** `[COMMUNITY]` — over a period in which forecasting software proliferated. More forecasting did
   not produce more accuracy. **Measured** forecasting might.

**The honest, narrow version.** `OVERVIEW.md` **C4** as a hard rule: *predictions are stored as
falsifiable claims with a measurement date, or they are not shipped.* One shared claim store serves I-22,
I-23 and I-26 — build it once, at roughly 8 points, and it is the enabling substrate for three
capabilities rather than a tax on one.

The differentiated artefact that falls out is the **decay curve**: *"within 5% at 14 days, within 19% at
60 days, beyond 75 days we stop claiming."* No vendor publishes this, because publishing it requires both
willingness to look bad and possession of stored history — and a competitor starting in 2029 has neither.

**Transferable form.** *A number about the future is an assertion. If the product's thesis is that
assertions are recorded, an unrecorded prediction is a contradiction of the product.*

**Instead.** The shared claim store; per-band decay curves; I-10's principle of shipping the metric that
can kill your own feature.

**Confidence.** **High** — this follows deductively from the thesis and costs little to obey.

---

## IA-10 — Confidence as a user-facing number

*"AI suggested this — 87% confident."*

**What it is.** Surfacing a model's self-reported confidence to the reviewer as a decision aid.

**Why it is tempting.** It looks like transparency, it is trivially available, and it feels like the
responsible thing to do.

**Why it fails.** `R-32` already forbids confidence from *authorising* anything. The product-level failure
is different and worse: **displaying it changes human behaviour in the wrong direction.**

1. **It is a self-report, and it is uncalibrated by default.** 87% is a token-level artefact, not a
   frequency claim, unless someone has measured that items marked 87% are wrong 13% of the time.
2. **It becomes the review.** A number on the screen is an invitation to triage by that number, which
   converts review into threshold-application — a confidence gate wearing a human costume. `R-32` refused
   the automated version; the manual version has the same defect and is harder to detect.
3. **It interacts with automation bias.** A 2025 systematic review of 35 peer-reviewed studies across
   healthcare, finance, national security and public administration found that **agreement with incorrect
   AI recommendations is the most consistent behavioural outcome** in human–AI decision-support pairings
   `[INDEPENDENT]`. A high confidence score is a direct amplifier of the effect the review exists to
   counteract.

**The honest, narrow version.** Two things, both better:

- **Show the evidence, not the score.** *"Matched to invoice INV-2291 because the amount, the date and
  the reference agree"* is reviewable. `87%` is not. This is `docs/research/ai/` **B-05** at the UI layer.
- **Measure calibration internally and use it for routing, never for display** — reliability curves and
  Brier scores per model version per task, computed from accepted/edited/rejected outcomes
  (`docs/research/ai/` **B-11**). QAYD is one of the few systems that will have the labels to do this,
  because corrections are generated in the ordinary course of work.

**Transferable form.** *Do not put a number on the screen unless you have measured what it means. A
displayed confidence is a claim about frequency; if you have not counted, you are guessing at the user.*

**Instead.** Evidence-first proposals; internal calibration; escalate on task properties, never on
self-reported confidence (`docs/research/ai/` **L-20**).

**Confidence.** **High.**

---

## IA-11 — Manage-by-exception

*"The agent handles the bulk; you review only what it flags." The anti-pattern the whole market has
already shipped.*

**What it is.** The system decides which items a human sees, using the model's own judgement, and does
not disclose the size or composition of what it hid.

**Why it is tempting.** It is the only way to make an AI feature feel like a saving rather than a new
queue, it is what every buyer asks for, and it is what every competitor ships — Digits surfaces the
things the AI is unsure about; Pilot escalates only judgements it believes could be material `[DOCS]`.

**Why it fails.** It is **queue truncation by the model's own confidence, undisclosed, with no
measurement of what the truncation cost** — `R-32` re-implemented at the product layer, where nobody is
looking for it.

The compounding failure is the one `07` §5.4 point 3 names: an approval queue of 200 confident-looking
items is not review, it is a click-through agreement, and **the divergence between measured safety and
actual safety is invisible in the metrics.** Approval rates look excellent right up to the moment an
auditor samples. Practitioner analysis states the design paradox directly: **the cleaner and faster the
human-in-the-loop interface, the more likely the human is to approve without thinking** `[COMMUNITY]`.

So the safety property the entire architecture rests on **degrades as a function of product success** —
more AI features, longer queues, shallower review — while every dashboard stays green.

**The honest, narrow version.** Truncate the queue — that part is unavoidable and correct — but:

1. **Price by expected consequence, deterministically**, not by model confidence. Amount, account
   sensitivity, period proximity, counterparty novelty, whether it feeds a filing or covenant,
   reversibility. All arithmetic the customer can inspect and argue with.
2. **Make the truncation visible.** What was not reviewed is a first-class artefact with a name —
   **review debt** — which is reported, ages, and blocks a period close above a threshold.
3. **Measure whether review is real.** Blind re-presentation of a random unlabelled fraction of
   already-approved items; divergence between first and second judgement is the measured rubber-stamp
   rate (`docs/research/ai/` **B-12**).

That is `OVERVIEW.md` **I-21**, and it is the most important idea in that document.

**Transferable form.** *A system may decide what a human does not see, only if it also tells them the
size and shape of what it hid, and only if the deciding function is one the human can inspect.*

**Instead.** I-21's consequence estimator (5 points, independently useful) as the first slice.

**Confidence.** **High** that the problem is real — peer-reviewed, named in practitioner writing, and
already stated in QAYD's own honesty section. **Medium** on the political viability of engagement
telemetry without a design partner.

---

## IA-12 — Benchmark scores as evidence of product safety

*"The model scores 83.2% on the accounting benchmark, so we're fine."*

**What it is.** Treating a public benchmark score as a statement about what the product will do in
production — in either direction, optimistic or pessimistic.

**Why it is tempting.** Benchmarks are the only quantitative thing available, and a number beats an
argument in a room.

**Why it fails.** Three distinct traps, and QAYD has already stepped in two of them:

1. **Vendor-run benchmarks measure what the vendor is good at.** The DualEntry Labs accounting benchmark
   is run by an AI-native ERP competing in this market. It has a published rebuttal (Accounting Today
   opinion, 14 May 2026) arguing that it tests a bare model with no surrounding system, no deterministic
   calculation engine and no review hierarchy — and **scores an honest "I don't know, please review" as a
   failure** `[INDEPENDENT]`. For a product whose entire design is a surrounding system, that is close to
   measuring the inverse of the thing that matters.
2. **Scores move, and stale pessimism is as falsifiable as stale optimism.** `07` §5.2 anchors its risk
   argument on **66.0%**. The same public leaderboard now shows Round 2 at **77.3%** and a top score of
   **83.2%** across 31 models from 8 providers `[DOCS: dualentry.com/accounting-ai-benchmark]`. Anyone
   quoting 66% externally will be corrected by whoever opens the page.
3. **The metric may exclude the failure class that matters.** IA-01's §4 is the cleanest example: the
   Arabic ASR leaderboard normalises numerals away before scoring, so a WER cited as evidence for voice
   accounting is a metric that specifically excludes numeral errors. **Always ask what the metric
   normalises before it scores.**

**The honest, narrow version.** Use benchmarks for **direction and disqualification**, never for
assurance:

- **Disaggregate.** The Round 1 sub-scores — ~**92% rule recall vs 30–40% journal-entry creation**
  `[DOCS]` — are far more informative than the headline, and they survive the rebuttal.
- **Quote with the trend attached**, or not at all: *"66.0% in Round 1, 83.2% today."*
- **Measure on your own distribution.** QAYD's corrections corpus is an eval set nobody else has
  (`docs/research/ai/` **B-17**); an internal number on real tenant tasks outranks any public score.

**Transferable form.** *A benchmark tells you about the benchmark. Before citing one, ask who ran it,
what it normalises away, and how an honest refusal is scored.*

**Instead.** Internal evals from the correction corpus; benchmarks as a disqualifier only; annual
re-derivation of any threshold that was set from a public score.

**Confidence.** **High.**

---

# Category D — The moat fantasies

## IA-13 — The proprietary finance model

*"We'll fine-tune our own accounting model."*

**What it is.** Building or fine-tuning a model as the differentiator, with the software as a wrapper.

**Why it is tempting.** In several adjacent categories it was briefly true, it is a fundable sentence,
and it feels like owning something.

**Why it fails.** `OVERVIEW.md` §1.4 argues it; the operative property is:

> **Model capability is the one asset in this stack that resets.** A new generation obsoletes your
> fine-tune, and your competitor buys the same upgrade on the same day you do.

**The tell**, worth memorising: *if your advantage improves when someone else ships a better model, it is
a real advantage. If it degrades, you were renting.* Five of the six durable advantages in `07`'s moat
analysis are durable because of **accumulated data**, not clever engineering.

**The honest, narrow version.** Small, task-scoped, cheap models for narrow deterministic-adjacent jobs
where latency or cost dominates and the task is closed — and even then, only after the deterministic rule
has been tried and failed (`R-34`). The strategic posture is: **buy intelligence, re-buy it every six
months, and compete on the layer that accumulates.**

**Transferable form.** *Own the things that compound and rent the things that reset.*

**Instead.** Provider-agnostic gateway; the correction corpus (I-09) as the real proprietary asset; the
anchored record (I-08) as the real moat.

**Confidence.** **High**, with the named falsification in `OVERVIEW.md` §6.2 — if one lab is durably and
unmatchably better at accounting for three years, this entry is wrong.

---

## IA-14 — Retrofitting the record

*"Ship the feature now, add provenance in Q3."*

**What it is.** Deferring the recording layer — assurance grades, correction capture, prediction claims,
model identity — because it produces no visible user value in the sprint that builds it.

**Why it is tempting.** It is *always* the rational local decision. Provenance never wins a sprint
prioritisation against a feature, and it never will, because its value is entirely in the future.

**Why it fails.** This is the only anti-pattern in the folder whose damage is **strictly irreversible**:

> **Every entry posted before the registry exists is permanently unattributable.** Not
> expensive-to-attribute. Unattributable. There is no migration that recovers which model version, under
> which prompt, on which evidence, proposed a journal entry that was posted in 2027 by a system that did
> not record it.

Four capabilities share this property and all four are currently deferred: **I-04** (assurance-weighted
balances), **I-09** (the correction corpus), **I-31** (machine identity), and the shared claim store
behind **I-22/I-23/I-26**. Each is cheap when designed in and nonexistent when retrofitted.

And it is precisely the asset class `OVERVIEW.md` §5 identifies as the only durable one. **A competitor
can copy any capability in twelve to twenty-four months; they cannot copy thirty-six months of anchored,
attributed, labelled history.** Deferring the record does not delay the moat — it deletes the portion of
it that would have accrued during the delay.

**The honest, narrow version.** There isn't a narrow version; there is a **sequencing rule**:

> *A capability that generates a permanent record ships its recording layer in the same story that ships
> the capability, or the capability does not ship.*

The cheap slices, in order of value-per-point: prediction-claim storage (~8, shared across three
capabilities), correction capture recording *what* changed rather than *that* it changed (~3), model
identity stamped on every proposal (~3).

**Transferable form.** *Distinguish work that can be done later from work that can only be done now.
Deferring the second is not prioritisation, it is destruction.*

**Instead.** `IMPLEMENTATION_RECOMMENDATIONS.md` Tier A; `docs/research/ai/` **AIR-05** and **AIR-08**.

**Confidence.** **High** — the irreversibility is a logical property, not an empirical claim.

---

## How these compound

The entries are not independent. The dangerous product is the one that stacks them, and each layer
removes a different error-detection surface:

```
   VOICE INPUT                 IA-01   fluent, confident, wrong — and the
   (dialect + code-switching)          WER metric hid the numeral errors
        │
        ▼
   NL RESOLUTION               IA-02   plausible query, wrong join, no refusal
   (model-composed query)              — "silent hallucination"
        │
        ▼
   CHAT COMMITMENT             IA-03   no chart of accounts, no period lock,
   (no constraint display)             no tax code on screen at commit time
        │
        ▼
   CONFIDENCE BADGE            IA-10   uncalibrated self-report, amplifying
   ("92% — looks fine")                a measured automation bias
        │
        ▼
   MANAGE BY EXCEPTION         IA-11   the model decided you would not see
   (undisclosed truncation)            the other 180 items
        │
        ▼
   NO RECORD                   IA-14   and none of it is attributable
                                       afterwards, permanently
        │
        ▼
   ══════════════════════════════════════════════════════════
   A CONFIDENT, PLAUSIBLE, WRONG NUMBER — INDISTINGUISHABLE
   FROM A RIGHT ONE, WITH NOBODY ANSWERABLE FOR IT
   ══════════════════════════════════════════════════════════
```

Every arrow in that diagram is a place where a competitor's roadmap currently has a feature. **Every
arrow is also a place where QAYD's architecture already says no**, and the value of this document is that
the "no" now has measurements attached instead of instincts.

---

## The one-line test that catches most of these

> **When this fails, does the user find out?**

- If failure is visible → the capability can be built, sized to its accuracy.
- If failure is silent → the capability needs a confirmation boundary, and the boundary must display the
  constraints being committed against.
- If failure is silent **and** the output is used by a third party (a filing, a covenant, an auditor, a
  tax authority) → do not build it. Build the record that makes someone else's check cheap instead.

---

## Sources and re-verification

| Claim | Grade | Re-verify because |
|---|---|---|
| Khaliji WER 48.23% / Whisper 59.92% vs MSA 27.95% | `[PAPER]` | New model releases change it; check the leaderboard, not a citation of it |
| Mixat: 12.06% English vs 121.78% code-switched | `[PAPER]` | Stable (published corpus), but re-check for newer multilingual models |
| Currency-expression accuracy 95.6–98.5% | `[PAPER — weakly sourced here]` | **Verify the primary source and its conditions before any external use** |
| Zoho Books / QuickBooks voice behaviour | `[DOCS]` | Product behaviour changes silently; check documentation, not press |
| "Nobody ships speak → posted entry" | `[INFERENCE]` | Absence is hard to prove; re-check before claiming "first" |
| BEAVER ~0%, BIRD 92.96%/~82%, LogicCat 33.20% | `[PAPER]` | Leaderboards move; the *shape* of the enterprise cliff is the durable finding |
| Semantic layer +17 to +70 pp | `[INFERENCE from mixed grades]` | Three of five sources are vendor-published; quote the direction, not the magnitude |
| DualEntry 66.0% → 77.3% → 83.2%; 92% recall vs 30–40% production | `[DOCS — vendor-run]` | Re-check the leaderboard before quoting; carries a published rebuttal |
| Automation bias — 35-study systematic review, 2025 | `[INDEPENDENT]` | Stable |
| ~40% forecast accuracy, down from 53% in 2021 | `[COMMUNITY]` | Practitioner survey; treat as directional |
| COSO 2026 guidance requiring evidence of human review | `[COMMUNITY — secondary]` | **Verify at COSO before external use** |

# End of Document
