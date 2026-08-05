# LESSONS_FOR_QAYD — What this phase changes, confirms, corrects and contradicts

**Sixteen lessons (IL-01…IL-16) mapped onto QAYD's existing principles, patterns, inventions and
backlog · `docs/research/innovation/`**

Version 1.0 · 2026-07-28 · **Documentation only** — no application code, schema, migration or test was
modified in producing this.

---

## How to read this file

Every lesson carries a **verdict** against prior work:

| Verdict | Meaning |
|---|---|
| **confirms** | The prior document was right; this phase adds evidence or sharpens the reason |
| **extends** | The prior document was right and did not go far enough; here is the next step |
| **corrects** | A specific claim in a prior document is wrong or stale, and should be edited **in place, with the date** |
| **contradicts** | A widely-held belief — inside or outside this project — is wrong, and the refusal must be argued rather than asserted |

Nothing here restates `07_QAYD_INNOVATION.md`, `OVERVIEW.md`, or `docs/research/ai/`. Where a topic is
settled elsewhere, the lesson points and moves on.

---

## Index

| ID | Lesson | Verdict | Touches |
|---|---|---|---|
| **IL-01** | The category has a definition, and a definition produces a refusal criterion | extends | `07` §4, `08` intake rule |
| **IL-02** | `trg_no_ai_autopost` is constitutional, not a safety feature — which changes IM-01's framing | confirms | `P15`, **IM-01** |
| **IL-03** | Voice accounting is refused with measurement now, not taste | new | roadmap concepts |
| **IL-04** | I-16 was right for an accuracy reason, not only a design reason | confirms | **I-16** |
| **IL-05** | Authority must become an object, not a role check | extends | `P22`, `P15`, **I-27** |
| **IL-06** | Attention is the scarcest resource and nothing schedules it | extends | `07` §5.4, **I-21** |
| **IL-07** | "Manage by exception" is `R-32` relocated to the product layer | extends | `R-32` |
| **IL-08** | The 66.0% anchor in `07` §5.2 is stale and must be edited in place | **corrects** | `07` §5.2 |
| **IL-09** | Confidence must never reach the reviewer's screen | extends | `R-32`, `A-04` |
| **IL-10** | Three closing windows are open right now, and all three are cheap | extends | **I-04**, **I-09** |
| **IL-11** | I-19's most valuable part is the measurement, and it needs a claim store | extends | **I-19**, `07` §5.1 |
| **IL-12** | I-08's buyer is probably not the audit firm | extends | `07` §5.5 #1, **I-15** |
| **IL-13** | Multi-agent finance is refused twice; the product refusal is the one that gets tested | confirms | `A-02`, `A-07`, `L-03` |
| **IL-14** | The moat is the record. Every capability that is not the record is a clock | confirms | `07` §4 |
| **IL-15** | The product has already incurred an obligation to compute blast radius | extends | `07` §5.2 |
| **IL-16** | "The form is the enemy" is wrong, and this is the hill | **contradicts** | market consensus |

---

## IL-01 — The category has a definition, and a definition produces a refusal criterion

**Verdict: extends `07_QAYD_INNOVATION.md` §4 and `08_MASTER_BACKLOG.md`'s intake rule.**

`07` is the best artefact this project has produced and it has one structural gap: it invents twenty
capabilities and never says what they add up to. `OVERVIEW.md` closes that gap with a thesis — the system
mediates the *authority to assert a financial fact*, its defining act is *refusal*, and its durable asset
is *the record*.

The operational consequence is not a slogan; it is a **backlog field**. `OVERVIEW.md` **C1** says: build
capabilities that increase what can be *proved*; buy or minimally implement capabilities that only
increase what can be *done*. Applied at intake, that tags every item **substrate** or **application**, and
it makes "we will consciously ship a worse anomaly detector than MindBridge" a decision rather than a
failure.

`08`'s intake rule already requires a tier, a value, a dependency list and a sprint. This adds one field
and one sentence of justification, and it is the cheapest item in this entire phase.

**Action.** Add the substrate/application tag to `08`'s intake rule. → `BEST_PRACTICES.md` **IB-01**,
`IMPLEMENTATION_RECOMMENDATIONS.md` **IR-01**.

---

## IL-02 — `trg_no_ai_autopost` is constitutional, not a safety feature

**Verdict: confirms `P15` and `07`'s closing line — and changes how IM-01 should be framed.**

`07` closes by observing that the word doing the most work in the architecture is `trg_no_ai_autopost`.
That is correct and it understates itself. Under the thesis, the trigger is not a guardrail on a feature;
it is **the protected-mode boundary that makes every capability in Part 8 safe to build**. Untrusted code
became runnable when the MMU made it unable to corrupt anything else; untrusted *proposals* become
runnable for the same reason.

This changes nothing technically and one thing politically. `08_MASTER_BACKLOG.md` **IM-01** records the
verified gap — the trigger is `BEFORE INSERT` only, so it blocks *creating* a non-draft AI entry but not
*updating* an AI draft into a posted state, where only application code stands in the way `[CODE, per
IM-01's own verification]`. IM-01 is already Critical / P0 / complexity 2.

**What this phase adds:** IM-01 is not one P0 among several. It is the precondition for whether the other
four planes in `ARCHITECTURE.md` are worth building at all, because every one of them assumes the boundary
holds. A two-point fix that gates a twelve-capability strategy should be sequenced as such and described
that way to anyone asking why it comes first.

**Action.** Restate IM-01's value line to name what it gates. No scope change.

---

## IL-03 — Voice accounting is refused with measurement now, not taste

**Verdict: new. Nothing in prior work addressed it.**

Voice is on every AI-finance concept list and it was, until this phase, refused on instinct. It is now
refused on evidence, and the evidence is unusually strong:

- **Khaliji (Gulf) Arabic best measured WER: 48.23%**; Whisper-large-v3 at **59.92% Khaliji vs 27.95%
  MSA** `[PAPER: Open Universal Arabic ASR Leaderboard, Interspeech 2025]`.
- **Code-switching is catastrophic and the market code-switches by default: 12.06% WER on English
  segments vs 121.78% on code-switched segments of the same recordings** `[PAPER: Mixat, SIGUL @
  LREC-COLING 2024]`.
- **The failure is silent** — Whisper's response to Gulf code-switching is to *fluently translate into
  MSA*, performing a different task while sounding certain.
- **WER understates numeric risk**: the leaderboard normalises Eastern→Western numerals away before
  scoring, and directly-measured currency-expression accuracy tops out at 95.6–98.5% in *English* with a
  fine-tuned model `[PAPER — verify before external use]`.
- **Both shipped implementations keep a human in the loop**: Zoho Books lands editable text requiring
  confirmation; QuickBooks uses intent-scoped Siri Shortcuts `[DOCS]`. Nobody ships speak → posted entry.

Three things follow for QAYD specifically. First, the refusal is now defensible in a room, which is worth
more than the refusal itself. Second, the *narrow* version — read-path voice, closed-vocabulary commands,
voice memos as attached artefacts — is genuinely worth building and should not be lost in the refusal.
Third, the sentence to keep: **anyone citing a WER as evidence that voice accounting works is citing a
metric that specifically excludes the failure class that matters.**

**Action.** Record voice as **refused for the write path, permitted on the read path** in the concept
mapping (`README.md` §3). → `ANTI_PATTERNS.md` **IA-01**.

---

## IL-04 — I-16 was right for an accuracy reason, not only a design reason

**Verdict: confirms `07`'s I-16 and supplies the argument it was missing.**

I-16 specifies natural-language accounting as **reviewable predicates**: the model emits a structured,
inspectable predicate that a deterministic engine executes, rather than composing a query. It read as a
sound design preference. It is now the only approach in this space with a documented accuracy case:

| Evidence | Finding |
|---|---|
| **BEAVER** — benchmark built from real enterprise warehouses | **GPT-4o: close to 0% end-to-end accuracy** `[PAPER]` |
| **BIRD** — curated academic schemas | Human baseline **92.96%**; top systems **~82%** `[PAPER]` |
| **LogicCat** — complex reasoning queries | **SOTA at most 33.20%** `[PAPER]` |
| Semantic / context layers | **+17 to +70 pp** across five studies `[INFERENCE from mixed grades — three vendor-published, two independent]` |

And the design lesson that matters more than the numbers: **semantic-layer failures are refusals; raw
text-to-SQL failures are confident wrong numbers** — Cube's "silent hallucination" `[DOCS]`.

The gap between BIRD and BEAVER is the whole story. A demo runs on a curated schema. A general ledger with
a customer-extended chart of accounts, analytic dimensions, multi-currency lines, period locks and tenant
partitioning is the other kind.

**Action.** Cite BEAVER in I-16's rationale. Add an explicit *cannot answer* outcome and a published
coverage rate to its acceptance criteria. → `ANTI_PATTERNS.md` **IA-02**, `BEST_PRACTICES.md` **IB-11**.

---

## IL-05 — Authority must become an object, not a role check

**Verdict: extends `P22` (no ambient privilege) and `P15`.**

QAYD arbitrates the authority to assert a financial fact very well and models it very poorly. Today
authority is **binary and implicit**: a role may post or may not; a machine's authority is one boolean
column and a trigger refusing it. That is a good boundary and an unexpressive model — it cannot say *"this
capability may propose bank matches under KWD 500 on bank-class accounts until 3 June, granted by the
controller."*

Every autonomy conversation eventually needs that sentence, and in its absence the vocabulary that fills
the vacuum is the agent org chart (`ANTI_PATTERNS.md` **IA-07**), which is unenforceable.

`OVERVIEW.md` **I-27** is the fix: grants as append-only data with a grantor, a scope predicate, an
autonomy mode, a reversibility budget (I-17 denominated per grant), and a **mandatory** expiry whose
renewal is an explicit, audited act.

**Action.** Design the grant object alongside S4-01's `AutonomyResolver` rather than retrofitting it.
→ `ARCHITECTURE.md` §4, `BEST_PRACTICES.md` **IB-02**.

---

## IL-06 — Attention is the scarcest resource and nothing schedules it

**Verdict: extends `07` §5.4 point 3 — which already states the problem and stops.**

`07` §5.4 says an approval queue of 200 confident-looking items is a click-through agreement, and that
**the divergence between measured safety and actual safety is invisible in the metrics**. That is
correct, it is the sharpest sentence in the honesty section, and nothing was built from it.

The external evidence says the effect is the default rather than an edge case: a 2025 systematic review of
35 peer-reviewed studies across healthcare, finance, national security and public administration found
**agreement with incorrect AI recommendations is the most consistent behavioural outcome** in human–AI
decision-support pairings `[INDEPENDENT]`; 2026 practitioner analysis states the paradox directly — **the
cleaner and faster the interface, the more likely the human approves without thinking** `[COMMUNITY]`.

The architectural consequence is the one that should worry an engineer: **the safety property the entire
design rests on degrades as a function of product success.** More AI capabilities → longer queue →
shallower review → `trg_no_ai_autopost`'s guarantee becomes ceremonial, with every dashboard green. That
is a resource-exhaustion bug, not a UX problem, and an operating system that does not schedule its
scarcest resource is not one.

**Action.** Build the consequence estimator (5 points, independently useful) before the scheduler. Design
the attention plane alongside S4-01. → `ARCHITECTURE.md` §5, `BEST_PRACTICES.md` **IB-03…IB-05**.

---

## IL-07 — "Manage by exception" is `R-32` relocated to the product layer

**Verdict: extends `R-32`.**

`R-32` refuses trusting model output without a confirmation boundary, and it is usually read as being
about *auto-approval*. The market has shipped the same defect in a shape `R-32`'s wording does not
obviously catch: **the model decides which items a human sees**, and the size and composition of what it
hid is never disclosed. Digits surfaces the things the AI is unsure about; Pilot escalates only judgements
it believes could be material `[DOCS]`.

That is queue truncation by the model's own confidence, undisclosed, unmeasured — a confidence threshold
authorising an outcome, where the outcome is *invisibility* rather than *posting*. It is harder to detect
than the version `R-32` names because nothing was auto-approved; the human approved everything they saw.

**Action.** Extend `R-32`'s statement to cover *what the human is shown*, not only what is written.
Truncation is permitted; undisclosed truncation, and truncation priced by the model, are not.
→ `ANTI_PATTERNS.md` **IA-11**.

---

## IL-08 — The 66.0% anchor in `07` §5.2 is stale ⚠️

**Verdict: corrects `07_QAYD_INNOVATION.md` §5.2. Edit in place, with the date.**

`07` §5.2 anchors its risk argument on the DualEntry Labs benchmark's top score of **66.0%**. The same
public leaderboard now shows **Round 2 at 77.3%** and a current top of **83.2%** across 31 models from 8
providers `[DOCS: dualentry.com/accounting-ai-benchmark]`. `OVERVIEW.md` Part 8 already carries this
correction; it belongs in `07` itself, because `07` is the document people quote.

Three consequences, all of which matter:

1. **Anyone quoting 66% externally will be corrected by whoever opens the page.** Quote it as *"66.0% in
   Round 1, 83.2% today"* or not at all.
2. **The benchmark is vendor-run** — DualEntry is an AI-native ERP competing in this market — and it
   carries a published rebuttal (Accounting Today opinion, 14 May 2026) arguing it tests a bare model with
   no surrounding system, no deterministic calculation engine, no review hierarchy, and **scores an honest
   "I don't know, please review" as a failure** `[INDEPENDENT]`.
3. **The sub-score survives the rebuttal and is the interesting number.** Round 1 reported roughly **92%
   on GAAP/IFRS recall against 30–40% on journal-entry creation** `[DOCS]`. **Models know the rules and
   cannot reliably produce the record.** That gap is the precise justification for a substrate where
   machines propose and are refused the ability to assert — a better argument than the headline score ever
   was, and one that does not go stale when models improve.

**Action.** Edit `07` §5.2 in place with today's date; re-anchor the argument on the production gap rather
than the headline. Add the annual threshold sweep (`BEST_PRACTICES.md` **IB-15**).

---

## IL-09 — Confidence must never reach the reviewer's screen

**Verdict: extends `R-32` and `docs/research/ai/` **A-04**.**

`R-32` forbids confidence from *authorising*. `A-04` forbids *trusting* it. Neither addresses *displaying*
it, and displaying it is the version a product manager will propose next week, in good faith, as
transparency.

It fails three ways. It is an uncalibrated self-report unless someone has measured that items marked 87%
are wrong 13% of the time. It **becomes** the review — a number on screen is an invitation to triage by
that number, which is a confidence gate wearing a human costume. And it directly amplifies the automation
bias measured in IL-06's 35-study review.

The two replacements are both better products: **show the evidence, not the score** (*"matched to
INV-2291 because amount, date and reference agree"* is reviewable; `87%` is not), and **measure calibration
internally for routing, never for display** — which QAYD is unusually well placed to do, because
corrections generate labels in the ordinary course of work (`docs/research/ai/` **B-11**).

**Action.** Add "no confidence value is rendered to a reviewer" as an acceptance criterion on S4-04.
→ `ANTI_PATTERNS.md` **IA-10**.

---

## IL-10 — Three closing windows are open right now, and all three are cheap

**Verdict: extends I-04 and I-09's closing-window argument to three more items.**

`07` identifies that I-04 (assurance grades) and I-09 (the correction corpus) have a property most
features do not: **the data cannot be backfilled**, so every week without them is permanently lost. This
phase finds three more with the same property, and they are cheaper:

| Window | Cost now | Cost later | What is lost |
|---|---|---|---|
| **Machine identity** on every machine-originated artefact | **3** | Impossible | Every pre-registry entry is permanently unattributable; blast radius (I-30) cannot be built at all |
| **Prediction claims** with a measurement date | **8**, shared by I-22 / I-23 / I-26 | Impossible | The decay curve — the only artefact in that family a competitor cannot copy |
| **Engagement telemetry** on review | **8** | Impossible | Real reviewer capacity is unknowable; I-21's calibration starts from zero |

The uncomfortable observation: **these are exactly the items that lose every sprint prioritisation**,
because their value is entirely in the future and their demo value is zero. That is not a scheduling
problem to be solved by advocacy; it is a definition-of-done problem to be solved by a rule
(`BEST_PRACTICES.md` **IB-13**): *a story that generates a permanent record ships its recording layer in
the same story, or it does not ship.*

**Action.** Add the rule to `08`'s intake section. Sequence the three windows in Tier A of
`IMPLEMENTATION_RECOMMENDATIONS.md`.

---

## IL-11 — I-19's most valuable part is the measurement, and it needs a store

**Verdict: extends I-19; `07` §5.1 already reached the conclusion and did not follow it through.**

`07` §5.1 says of the provisional ledger that it *may be no better than a simple AR/AP-ageing projection
— which every competitor already ships for free*, and that *the predicted-vs-actual measurement is the
most valuable part and the cheapest; it may be the only part worth building.* That is right, and the
follow-through is a schema decision, not a feature decision.

Two additions from this phase:

1. **Band by epistemic status.** Payroll on the 25th, the rent standing order and a signed supplier
   contract are not predictions — they are obligations not yet recorded. Mixing them with a stochastic
   guess produces a number whose error is unattributable. Three bands, three owners, three error
   behaviours (`BEST_PRACTICES.md` **IB-09**).
2. **Store every claim with a `measure_on`**, in one shared table serving I-22, I-23 and I-26. The market
   context is unflattering to the alternative: practitioner surveys report **only ~40% of organisations
   achieving high or good forecast accuracy, down 13 points from 53% in 2021** `[COMMUNITY]`, over a
   period in which forecasting software proliferated. More forecasting did not produce more accuracy.
   *Measured* forecasting might.

**Action.** Build the claim store once, before the first predictive surface ships.
→ `ARCHITECTURE.md` §6.

---

## IL-12 — I-08's buyer is probably not the audit firm

**Verdict: extends `07` §5.5 #1, which flags this as the claim most likely to be wrong.**

`07` grades I-08 (offline-verifiable audit receipts) as the single most durable moat in the document, and
routes it through the audit firm — then correctly flags that as its own weakest claim, because those two
weeks of verification labour are **billable** and the firm's incentive runs the other way.

The thesis suggests the fix is not a better argument to the firm; it is **a different buyer**. Trust that
is transferable can be sold to whoever *pays for* the audit rather than whoever *performs* it: the
auditee (who wants the fee smaller), the lender (who wants to underwrite faster — this is I-15) and the
acquirer (who wants diligence cheaper). The artefact is identical in all three cases, so the test is
cheap.

`OVERVIEW.md` §5 records this as prediction **P3** with a named falsification: if assurance capability is
bought by audit firms before it is bought by auditees and lenders, `07`'s original routing was right.

**Action.** One conversation each — auditee, lender, acquirer — **before** building the full I-08 bundle.
Two points of effort against a 21-point build. → `BEST_PRACTICES.md` **IB-14**.

---

## IL-13 — Multi-agent finance is refused twice, and the product refusal is the one that gets tested

**Verdict: confirms `docs/research/ai/` **A-02**, **A-07** and **L-03**.**

The engineering refusal is settled: Anthropic measured multi-agent at **~15× the tokens of chat** and
named the poor-fit conditions explicitly — tasks where agents share context or have many
inter-dependencies `[DOCS]` — and double-entry accounting is the canonical instance of both.

The refusal that will actually be pressure-tested is the **product** one, because "an AP agent, an AR
agent, and a controller agent that supervises them" is a slide, not an architecture diagram, and slides
travel further. The product-level addition: the org-chart framing imports the org chart's failure mode —
a "supervising" agent is a supervision claim the architecture cannot honour, and it makes accountability
diffuse in the one domain whose entire value is that accountability is precise.

The honest decomposition is **capability scoping** — which tools, which data, which autonomy — and it now
has a concrete expression in the grant object (IL-05), which is enforceable in a way a job title is not.

**Action.** When the thirteen roles in `docs/ai/` are implemented, implement them as thirteen grants over
one runtime. → `ANTI_PATTERNS.md` **IA-07**, `docs/research/ai/` **AIR-18**.

---

## IL-14 — The moat is the record. Everything else is a clock

**Verdict: confirms `07` §4's moat analysis and sharpens its strategic conclusion.**

`07` §4 grades five of six durable advantages as durable **because of accumulated data**, not clever
engineering. `OVERVIEW.md` §5 turns that into a falsifiable prediction: feature parity arrives in 12–24
months, always; model capability is bought and resets each generation; only the accumulated record
compounds and cannot be backdated.

The strategic content is one sentence: **start the clock now on the things that accumulate, and buy the
things that do not.**

The corollary is uncomfortable and should be said plainly rather than discovered later: **this thesis does
not create a moat against a well-funded AI-native entrant.** Nothing structural does. What it creates is a
*different clock*. A competitor can copy any capability in 12–24 months; they cannot copy 36 months of
anchored, attributed, labelled history, because it does not exist for them.

**The tell**, worth keeping: *if your advantage improves when someone else ships a better model, it is a
real advantage. If it degrades, you were renting.*

**Action.** No new work. This is the sentence that justifies IL-10's three windows when they lose a
prioritisation argument.

---

## IL-15 — The product has already incurred an obligation to compute blast radius

**Verdict: extends `07` §5.2.**

`07` §5.2 lists four mitigations for the confidently-wrong-number scenario. All four *bound* error; **none
responds to one.** A product whose thesis is that history is permanent and derivations are traceable has
made an implicit promise to answer the question that follows any discovered defect: *we were wrong — what
does that touch?*

The canonical case is not the KWD 4,000,000 misposting that fails a sanity check. It is `07`'s own better
example: the vendor misclassified for eleven months, inside every tolerance, approved forty-four times. No
bound catches that. Only a walk from the defect through identity → proposals → journals → ledger →
derived balances → claims → exports does, and every step of that walk depends on a plane that does not
exist yet.

Two design requirements that are easy to get wrong: the result must carry an **explicit coverage
statement** (an incomplete blast radius presented as complete is worse than none), and the capability must
be **rehearsed on a schedule** against a synthetic defect — because a query that has never been run is a
design, not a control.

**Action.** Treat I-30 as the consumer that justifies IL-10's identity window, and say so when identity is
sequenced. → `ARCHITECTURE.md` §7.3.

---

## IL-16 — "The form is the enemy" is wrong, and this is the hill ⚠️

**Verdict: contradicts the market consensus, and the instinct of anyone who has used an ERP.**

The most repeated pitch in this category is *"no more forms — just ask."* It is wrong, and the reason is
structural rather than aesthetic:

> **In finance the form is not an input mechanism. It is a constraint display.**

A journal-entry form renders the chart of accounts, the open periods, the tax codes, the approval state,
the currency, and which dimensions are mandatory for this account. Those are the *rules, displayed at the
moment of commitment*. The user learns them by being shown them and — the part that matters — **notices
when they are about to do something that does not fit.** A chat box displays none of it, accepts any
sentence, resolves it silently, and returns something confident. The last error-detection surface is
removed exactly where it is most needed.

This lesson is a contradiction rather than an extension because it must be argued, repeatedly, against
people who are right about the symptom. Forms *are* the hostile part of finance software. The correct
response is **language proposes, the form disposes**: an utterance produces a pre-filled form with every
constraint still rendered and every model-supplied value visibly marked. That costs nothing extra, because
the form already exists.

It also resolves four separate feature requests with one line — voice, chat, natural-language ERP, and
third-party agent write access — which is why `OVERVIEW.md` **C5** states it as a consequence of the
thesis rather than a UI preference.

**Action.** Write the rule into the design system before the first AI proposal surface is built.
→ `ANTI_PATTERNS.md` **IA-03**, `BEST_PRACTICES.md` **IB-10**.

---

## What this phase did *not* change

Stated so a reader does not go looking for a revision that is not there:

- **The posting boundary.** `P15`, `P7`, `P-12`, `R-31` are untouched and reinforced. Nothing here
  proposes autonomy over the ledger.
- **The AI engine's internal architecture.** `docs/research/ai/ARCHITECTURE.md` stands; the planes sit
  around it, not inside it.
- **I-01…I-20.** None is replaced. Four are extended (I-08, I-16, I-17, I-19), one is re-routed
  commercially (I-15), and one has its rationale corrected (I-19's framing). The rest are confirmed by
  omission.
- **The database's ownership of integrity.** `P1`, `P3`, `P5`, `P6`. Every plane proposed here is an
  append-only table in the primary database for exactly that reason.
- **Cost arithmetic.** `05_FUTURE_ARCHITECTURE.md` §E owns it; nothing here re-prices anything.
- **Sprint sequencing.** `08_MASTER_BACKLOG.md` owns it. Everything in
  `IMPLEMENTATION_RECOMMENDATIONS.md` is proposed *for* intake, not merged into the plan.

---

## The lessons in one table

| Lesson | If it is right | If it is wrong |
|---|---|---|
| IL-01 refusal criterion | Roadmap arguments resolve on policy | You built a checklist and lost on distribution |
| IL-02 constitutional trigger | IM-01 is sequenced first, correctly | A two-point defect gated a strategy and nobody said so |
| IL-03 voice refusal | You avoided the most expensive demo in the category | You conceded a market you could have served |
| IL-04 I-16 accuracy case | NL query ships with refusals and coverage | You shipped confident wrong numbers |
| IL-05 authority object | Autonomy expands as governance, not as a toggle | Agents acquire permissions nobody granted |
| IL-06 attention scheduler | Safety stops degrading with success | Green dashboards until an auditor samples |
| IL-07 truncation disclosure | You caught `R-32` in its second costume | The model chose what nobody saw |
| IL-08 stale anchor | Your risk argument survives contact with a leaderboard | You were corrected publicly on your own number |
| IL-09 no confidence display | Review attaches to evidence | Review became threshold-application |
| IL-10 three windows | The moat accrues from now | Three permanent holes in the record |
| IL-11 claim store | The decay curve exists in 2029 | Another forecast line among ten |
| IL-12 different buyer | I-15's rail opens | 21 points spent on the wrong conversation |
| IL-13 capability scoping | Thirteen grants on one runtime | Thirteen loops and 15× tokens |
| IL-14 record as moat | The clock runs in your favour | You competed on the layer that resets |
| IL-15 blast radius | The buyer's real objection is answered | An archaeology project during a restatement |
| IL-16 the form | Language and safety both ship | You removed the user's last check |

# End of Document
