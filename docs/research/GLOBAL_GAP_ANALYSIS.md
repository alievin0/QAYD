# Global Gap Analysis

**Every platform studied in every Phase-3 folder, scored against a published rubric; every gap classified
as *real* or *bad idea*; every gap weighted by whether QAYD can actually exploit it; and the two or three
places QAYD should genuinely dominate.**

Version 1.0 · 2026-07-28 · Phase 3 synthesis · **Documentation only** — no application code, schema,
migration, seed or test was created or modified in producing this file.

Companion: [`WORLD_CLASS_FEATURES.md`](./WORLD_CLASS_FEATURES.md) (the feature catalogue and the
build/skip verdicts). Source folders:
[`erp/`](./erp/) · [`accounting/`](./accounting/) · [`payments/`](./payments/) · [`analytics/`](./analytics/) ·
[`ai/`](./ai/) · [`banking/`](./banking/) · [`security/`](./security/) · [`competitive/`](./competitive/) ·
[`odoo/`](./odoo/) · [`innovation/`](./innovation/) — 25,774 lines, plus
[`../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md`](../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md).

---

## 0. Why this document exists, and the one thing it is for

Phase 2 produced a capability scorecard. The seven Phase-3 folders produced depth. Neither answers the
question a founder actually has to answer:

> **Of everything nobody has built, which parts are unbuilt because they are hard, and which parts are
> unbuilt because they are a bad idea — and of the first set, which can a two-person company with no
> customers and a Kuwaiti beachhead actually take?**

**Distinguishing the two kinds of gap is the entire value of this document.** Most apparent market gaps
are the second kind. A gap register that does not separate them is a list of things to waste two years on.

### 0.1 What is deliberately not here

- **Feature-by-feature build verdicts** — that is `WORLD_CLASS_FEATURES.md`.
- **Re-scoring the 16 Phase-2 subsystems** — that scorecard stands, and §2.4 carries it forward unchanged.
- **Anything already argued in a folder.** Where a conclusion is imported it is cited, not restated.

---

# 1. THE RUBRIC

**A scorecard without a published rubric is an opinion.** Three rubrics are used, and all three are
defined before any score appears.

## 1.1 Rubric A — Capability score (1–5)

Deliberately **the same scale as Phase 2** (`06_COMPETITIVE_ANALYSIS.md` §3) so the two are comparable.

| Score | Meaning | The test |
|:--:|---|---|
| **1** | Absent, or present in a form that actively harms | Does not exist, or exists and produces wrong answers |
| **2** | Present but structurally weak | Exists; a competent user hits its limit in normal use |
| **3** | Adequate — works in production | A real business runs on it and does not complain |
| **4** | Strong — a real advantage | A buyer would switch *for* this |
| **5** | Best-in-class, hard to replicate | A competitor would need to change architecture to match it |

**Two columns are given for QAYD, and the gap between them is the honest picture:**

- **QAYD-now** — what the code supports today, at four of fourteen Sprint-2 stories closed, posting engine
  under review, no reconciliation, no statements, no tax, most of the frontend and the AI engine unbuilt.
- **QAYD-designed** — the ceiling the architecture permits **if the plan is executed**. It is a
  hypothesis, not an achievement, and every use of it in this document says so.

**Scoring is per-dimension and jurisdiction-aware.** A product scores against *what a Kuwaiti SME buyer
gets*, not against its best edition. Xero is therefore scored on **GLOBAL**, the edition the API enumerates
for Kuwait `[DOCS, verified 2026-07]`, not on its AU or UK edition.

## 1.2 Rubric B — Gap classification

Every gap gets exactly one letter. **This is the rubric that does the work.**

| Type | Name | Definition | What to do |
|:--:|---|---|---|
| **R** | **Real** | Unbuilt because it is genuinely hard, unprofitable at the incumbent's scale, or structurally blocked by a decision they cannot reverse | **Candidate.** Then apply Rubric C |
| **B** | **Bad idea** | Unbuilt because it does not work, the market rejected it, or building it destroys something more valuable | **Refuse, and record the mechanism of harm** |
| **T** | **Temporary** | Unbuilt *yet*. Somebody's next release closes it | **Do not build a strategy on it.** Build it only if it is cheap and serves something durable |
| **M** | **Mirage** | Looks like a gap because it was not looked at hard enough. `[UNKNOWN]`-backed | **Close the `[UNKNOWN]` before spending anything** |

**The discipline that makes this rubric honest:** a gap is **R** only if the *mechanism* preventing the
incumbent from closing it can be named. "They haven't done it" is not a mechanism. "Their money model has
no variable precision and every downstream computation assumes two decimals" is.

## 1.3 Rubric C — Exploitability weight (0–12)

Applied only to **R** gaps. Four factors, 0–3 each, summed.

| Factor | 0 | 1 | 2 | 3 |
|---|---|---|---|---|
| **Buildable** — by this team, on this architecture | Needs a team QAYD will not have | A year | A quarter | Weeks, or already present |
| **Beachhead** — does it serve a Kuwaiti SME or their accountant | Irrelevant to Kuwait | Nice for some | Wanted by most | **Blocking for most** |
| **Durability** — does the advantage compound or decay | Copyable in a sprint | 12–24 months | Multi-year | Compounds with usage |
| **Demonstrable** — can a buyer see it in one meeting | Invisible for years | Third year | Needs a trial | **Visible in five minutes** |

| Total | Band | Meaning |
|:--:|---|---|
| **10–12** | **Dominate** | Build it first and build it better than anyone |
| **7–9** | **Exploit** | Real advantage; build it in the first year |
| **4–6** | **Hold** | Worth having, not worth sequencing around |
| **0–3** | **Note** | A genuine gap QAYD cannot use. Record it and move on |

**Why "Demonstrable" is a scored factor and not a marketing afterthought.** `../accounting/` §7.3 and
`../security/` §7 both reach the same conclusion independently: QAYD's strongest architectural properties
are invisible to a buyer in the first meeting and pay off in the third year. A company with no customers
cannot finance a three-year payoff. **A gap that is real, durable and undemonstrable is a gap for a
company with runway.**

## 1.4 Evidence grading

`[DOCS]` vendor's own surface — pricing page, API, documentation, idea board, press release, regulator
directory · `[CODE]` read from source · `[COMMUNITY]` trade press, forums, comparison sites, practitioner
reports · `[INFERENCE]` a conclusion drawn here with the reasoning shown · `[UNKNOWN]` could not verify.

**Three disciplines carried from `competitive/README.md`:** every market claim is dated; a competitor
capability claim is `[DOCS]` only from the vendor's own surface; and funding is not traction.

---

# 2. THE SCORECARD

## 2.1 The dimensions

Sixteen dimensions spanning all seven research folders plus the commercial layer. They are **not** the
Phase-2 subsystems — Phase 2 scored engineering subsystems, this scores **what decides a deal in Kuwait**.

## 2.2 The systems

| Column | What it is | Why it is here |
|---|---|---|
| **QAYD-now / QAYD-des** | Today / the designed ceiling | The subject |
| **Xero** | **GLOBAL edition** — the one Kuwait gets `[DOCS]` | Category co-leader; the deep profile is new evidence |
| **QBO** | **Global edition** — the one a Gulf buyer gets `[DOCS]` | Category co-leader; no `/kw/` storefront (404) |
| **Zoho** | Zoho Books, GCC editions | The most GCC-present global product |
| **Wafeq** | UAE, 2019 | The only one with a **KWD-priced Kuwait page** |
| **Qoyod/Daftra** | Saudi 2016 / Arabic-market | The regional cloud cohort |
| **Tally** | TallyPrime | **The actual incumbent in a Kuwait SME deal** |
| **ERPNext†** | Partner/reseller-led | The Phase-2 conclusion for GCC ERP deals |
| **Digits** | AI-native GL | The direct architectural challenger |
| **Pennylane** | Firm-first, France | The validated firm-workspace shape |

† ERPNext scored as delivered by a Kuwaiti reseller, not as upstream source.

## 2.3 The scores

| # | Dimension | QAYD-now | QAYD-des | Xero | QBO | Zoho | Wafeq | Qoyod/Daftra | Tally | ERPNext | Digits | Pennylane |
|---|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| 1 | Double-entry integrity & immutability | **4** | **5** | 3 | 3 | 3 | 3 | 3 | 3 | 3 | ? | ? |
| 2 | Tenant isolation & security posture | **5** | **5** | 4 | 4 | 4 | ? | ? | 2 | 4 | ? | ? |
| 3 | Money precision (3-dp KWD/BHD/OMR) | **4** | **5** | **1** | **1** | 3 | ? | ? | 3 | 3 | ? | 1 |
| 4 | Multi-currency depth | **2** | 4 | 3‡ | 3 | 4 | 3 | 3 | 3 | 4 | ? | 3 |
| 5 | Bank data ingestion — **in Kuwait** | **1** | **5** | **1** | **1** | **1** | **1** | **1** | 2 | 2 | **1** | **1** |
| 6 | Reconciliation | **1** | 4 | 4 | 4 | 3 | 3 | 3 | 3 | 3 | ? | 4 |
| 7 | Financial reporting & statements | **1** | 4 | 4 | 4 | 4 | 3 | 3 | 3 | 3 | ? | 4 |
| 8 | Period close & fiscal control | **1** | 4 | 3 | 3 | 3 | 3 | 3 | 3 | 4 | ? | 4 |
| 9 | Tax & compliance — **GCC** | **1** | 3 | **1** | **1** | **5** | 4 | 4 | 3 | 3 | **1** | **1** |
| 10 | Dimensional / analytic accounting | **1** | 4 | 2 | 2 | 3 | 2 | 3 | 3 | 3 | ? | 3 |
| 11 | AI — labour removal | **1** | 4 | 3 | 3 | 2 | ? | ? | 1 | 1 | 4 | 4 |
| 12 | **AI — provenance & auditability** | **2** | **5** | **1** | **1** | **1** | **1** | **1** | **1** | **1** | ? | **1** |
| 13 | Arabic / RTL / localisation | **2** | **5** | **1** | 2 | 3 | 4 | 4 | 3 | 3 | **1** | **1** |
| 14 | Accountant-firm channel product | **1** | 4 | 4 | 4 | 2 | 2 | 2 | 2 | 2 | 3 | **5** |
| 15 | Ecosystem & integrations | **1** | 2 | **5** | **5** | 4 | 2 | 2 | 3 | 4 | 2 | 3 |
| 16 | Breadth (inventory · payroll · CRM · mfg) | **1** | 2 | 2 | 3 | 4 | 3 | **5** | 4 | **5** | 1 | 3 |
| | **Mean (all 16)** | **1.9** | **4.1** | **2.8** | **2.9** | **3.1** | *2.8* | *3.0* | **2.7** | **3.1** | *?* | *2.8* |
| | **Mean (Kuwait-relevant: 1–14, excl. 15–16)** | **1.9** | **4.4** | **2.4** | **2.5** | **3.0** | *2.7* | *2.9* | **2.6** | **2.9** | *?* | *2.7* |

`?` = insufficient evidence to score. Italic means the mean is computed over the scoreable subset and is
therefore not strictly comparable. ‡ Xero multi-currency scores 3 on *capability* and is **locked to the
top tier on the GLOBAL edition** `[DOCS]`, which is scored in dimension 4's justification below.

## 2.4 The honest reading of this table

**Four things, and three of them are unfavourable to QAYD.**

**1 · QAYD is currently the weakest system in the table.** 1.9 mean against Zoho's 3.1 and ERPNext's 3.1.
This is consistent with Phase 2, which scored QAYD **2.4 against SAP's 4.7 and ERPNext's 3.4** and called
it *"the second-weakest system in this comparison by breadth, ahead only of Akaunting"*
`[06_COMPETITIVE_ANALYSIS.md` §3]`. **Nothing in Phase 3 improves that; two folders make it worse**, because
the AI cohort and the regional cohort are stronger than the Phase-2 set on dimensions Phase 2 did not
score.

**2 · The QAYD-designed column is a hypothesis and must be read as one.** 4.4 on Kuwait-relevant
dimensions is what the architecture *permits*. `../accounting/` §7.1 states the counterweight and it should
be re-read every time this table is shown: *"architectural superiority that has never survived contact with
a live tenant is a hypothesis."*

**3 · The dimensions where the whole table is weak are the interesting ones.** Look at rows 5 and 12:

- **Row 5 — bank ingestion in Kuwait — is 1 for every global and regional player.** Not because they are
  bad at bank feeds; because **there is no rail**. CBK issued only a *draft* open-banking framework in
  June 2025 with no licensed providers `[DOCS]`, and its licence categories cover *moving* money with **no
  account-information-service category at all** `[DOCS, via ../payments/ §4]`. Tally and ERPNext score 2
  only because file import is native to their heritage, not because they solve it.
- **Row 12 — AI provenance — is 1 for everyone including the AI-native cohort.** Xero's JAX auto-reconcile
  is beta, tier-gated, and produces **no auditable reviewed-by-a-human state** `[COMMUNITY, verified
  2026-07]`. Digits is `?` because it is a closed product and its schema is unknowable from outside.

**A row where every column is 1 is either the best opportunity in the table or the clearest evidence that
the thing should not be built.** §3 exists to tell them apart.

**4 · QAYD already wins three rows today.** Dimensions 1, 2 and 3 — integrity, isolation and money
precision — at 4, 5, 4. These are real, `[CODE]`-verified, and **two of them are invisible to a buyer**
(§4.3). The third is not, which is why §6 makes it a domination candidate.

## 2.5 Score justifications — the ones that would otherwise look arbitrary

**Xero = 1 on money precision.** An open Xero idea states the product *"assumes two decimal places for most
currencies"* `[DOCS, verified 2026-07]`. Proper 3-dp KWD/BHD/OMR handling is **`[UNKNOWN]` — undocumented
by Xero** — but the absence of a variable-precision currency model is demonstrated, and for a Kuwaiti
buyer an undemonstrated precision claim is a 1.

**Xero = 1 on Arabic/RTL.** Proven by an *open, unactioned* Product Idea requesting "the ability to have
other language in Xero" `[DOCS, verified 2026-07]`. **Searching Xero's idea board for `kuwait` or `bahrain`
returns zero results** — the market is absent from the conversation, not underserved within it.
**Third-party blogs claiming "Arabic is available in Xero" are false and are a documented trap.**

**Xero = 1 on GCC tax/compliance.** No Middle East edition exists; the API enumerates exactly five —
`AU, NZ, GLOBAL, UK, US` `[DOCS]`. Kuwait gets `GLOBAL`, the residual bucket.

**Xero = 5 on ecosystem, and it is a hostile 5.** Effective **2 March 2026** Xero retired revenue share for
a **per-connection plus per-GB-egress platform fee** (free/$35/$245/$1,445 AUD; $2.40/GB overage) and added
a **prohibition on using Xero API data to train AI/ML models** `[DOCS, verified 2026-07]`. It is the
strongest ecosystem in the table and it has just demonstrated willingness to re-price and restrict it.

**Xero's published ceilings, recorded because they are a qualification tool:** 5,000 invoices/month, 5,000
bank transactions/month, 4,000 inventory items, 10,000 contacts, **500 fixed assets** `[DOCS, verified
2026-07]`.

**QBO = 1 on money precision.** Intuit's own community response to a Kuwaiti user: amounts *"default to two
places after the period. Changing this is currently unavailable"* `[COMMUNITY]`. Extra decimals exist on
exchange *rates*, not amounts.

**QBO = 1 on Kuwait bank ingestion, and the reason strengthens rather than weakens the finding.**
QuickBooks **does** offer UAE feeds naming ADCB, Dubai Islamic Bank, Emirates NBD, First Abu Dhabi Bank and
RAK Bank `[DOCS]` — and `quickbooks.intuit.com/kw/` returns **404** `[DOCS, by observation]`. Incumbents
build feeds where the market clears their threshold. **Kuwait clears nobody's.**

**Zoho = 5 on GCC tax.** ZATCA-approved Phase 2 with in-Kingdom residency exposed in its API, included
from roughly SAR 60/month `[DOCS]`. This is the single most defended position in the table.

**Zoho = 2 on the firm-channel product.** It runs a certified partner programme `[DOCS]`, but accountants
report its client-add flow is materially clunkier than Xero's or QuickBooks' *"you type an email address
and that's it"* `[COMMUNITY]`.

**Pennylane = 5 on the firm channel and 1 on everything GCC.** A reported $4.25B valuation with roughly
4,500–6,000 firms `[COMMUNITY, 2026-01-20]` — a validated shape at scale. It is a French product for the
French market underwritten by France's September 2026 e-invoicing regime `[COMMUNITY]`, with no GCC
presence found.

**Digits is `?` on nine dimensions and that is the honest score.** ≈$98M raised, a self-described
*"Autonomous General Ledger"*, a 2026 Accounting Today Top New Product `[COMMUNITY]`. Whether its ledger
carries confidence, cited source rows, a reviewable predicate, a reviewer and a chain is **`[UNKNOWN]` and
cannot be determined from outside a closed product**. Scoring it from marketing would be inventing
evidence.

**Tally = 2 on tenant isolation.** Desktop-era architecture `[COMMUNITY]`. It is scored because it is
**the actual incumbent in a Kuwait SME deal** `[COMMUNITY]`, not because it is a cloud competitor.

**QAYD-now = 2 on AI provenance despite the AI engine not existing.** The *schema* carries `ai_generated`,
`ai_confidence` and `ai_suggested_account_id` as ledger columns with a trigger refusing AI auto-posting
`[CODE]`. That is more than any competitor demonstrably has and less than a working system — hence 2, not
1 and not 4.

---

# 3. THE GAP REGISTER

Fifty-five gaps. **Classification first, exploitability second, and the B gaps are listed at equal length
to the R gaps because refusing them correctly is worth as much as taking the others.**

## 3.1 Type R — Real gaps (the mechanism is named)

| # | Gap | Why nobody closed it — the mechanism | Weight | Band | Evidence |
|---|---|---|:--:|---|---|
| **R-01** | **Kuwaiti bank statement → posted entry, zero manual editing** | There is no rail to build a feed against `[DOCS]`, and per-bank adapter work is unglamorous, format-fragile and permanently maintained. Incumbents build where the market clears their threshold; **Kuwait clears nobody's — proven by QuickBooks building five UAE banks and 404ing on `/kw/`** | **11** (3·3·2·3) | **Dominate** | `[DOCS]` CBK draft framework; `[DOCS]` QBO `/ae/` vs `/kw/` |
| **R-02** | **Auditor-grade machine-authorship provenance** | Requires proposal, confidence, citation, reviewer and chain as *columns*. Retrofitting into a live multi-tenant GL with tens of thousands of tenants is the obstacle — **their architecture, not their roadmap** | **11** (3·2·3·3) | **Dominate** | `06` §4.4; `[COMMUNITY]` JAX has no reviewed state |
| **R-03** | **Three-decimal money, proved end to end** | The two-decimal assumption is baked into storage and every downstream computation. **OFBiz rejects only imbalances ≥0.01, so 0.009 KWD posts** `[CODE]`; QBO cannot change it `[COMMUNITY]`; Xero has no variable-precision model `[DOCS]` | **11** (3·3·2·3) | **Dominate** | `../erp/` L-06; `[DOCS]` Xero 2-dp idea |
| **R-04** | **`opening + Σ lines = closing` enforced at import** | **A feed-first product structurally cannot do this — it has no closing balance to check against.** The GCC's lack of feeds hands QAYD the stronger artefact | **10** (3·3·2·2) | **Dominate** | `../accounting/` §4.3 |
| **R-05** | **A human-reviewed state that is auditable and exportable** | The category ships automation and treats absence of complaint as review. **JAX drops description and reference — the fields a reconciliation is defended with** | **10** (3·3·2·2) | **Dominate** | `[COMMUNITY]` Xero Product Ideas 2026-07 |
| **R-06** | **Arabic-first, not Arabic-translated** | Zoho's GCC help documentation is **English-only for every GCC edition**; Arabic invoice PDFs need a manually-set RTL font `[DOCS]/[COMMUNITY]`. Xero's Arabic request is open and unactioned `[DOCS]` | **9** (3·3·1·2) | **Exploit** | `../accounting/` §7.2 |
| **R-07** | **One-click Tally → cloud migration** | Zoho's Tally path is a manual export sequence with no tool, which spawned a **paid third-party migration industry** — a market inefficiency nobody has removed | **9** (2·3·2·2) | **Exploit** | `[DOCS]/[COMMUNITY]` |
| **R-08** | **A firm-and-client shared workspace in the GCC** | Pennylane proved the shape at scale in France; nobody has brought it to a GCC market. It is a **schema decision**, so incumbents cannot add it cheaply either | **9** (2·2·3·2) | **Exploit** | `[COMMUNITY]` Pennylane |
| **R-09** | **Completeness measurement (what is *missing* from the books)** | The category's progress signal is an empty review queue, which measures the vendor's ingestion rather than the customer's books. Feed-only bookkeeping produces confident, incomplete books | **9** (2·2·2·3) | **Exploit** | `../accounting/ANTI_PATTERNS.md` §2 |
| **R-10** | **Machine-proposed, human-approved posting rules** | The category has humans author predicates and machines execute them. The inversion needs a proposal primitive, which is R-02's dependency | **8** (2·2·2·2) | **Exploit** | `../accounting/` §4.1 |
| **R-11** | **Signed period-close anchor ("July cannot be altered without detection")** | **Immutability is not a documented property of the incumbent core banking market** — Mambu documents a mutable-until-close GL; Temenos publishes an API for *deleting* journal entries | **8** (3·1·2·2) | **Exploit** | `../banking/` §0, A9 |
| **R-12** | **Per-bank saved column mappings as an accumulated asset** | In a market with no standard feed, a library of working mappings for NBK, KFH, Gulf Bank, Boubyan and Burgan is proprietary knowledge a later entrant must rebuild customer by customer | **8** (3·3·1·1) | **Exploit** | `../payments/` §5 |
| **R-13** | **Calibrated confidence, published** | Nobody in this market publishes a reliability curve. It requires a blind-sampled review stream — **the only measurement not conditioned on the reviewer having seen the model's opinion** | **7** (2·1·2·2) | **Exploit** | `../ai/` L-09, L-10 |
| **R-14** | **The correction corpus as a compounding asset** | Rejected, edited-then-accepted and blind-disagreement proposals are expert labels produced free in the ordinary course of work. **For the edit path it is not expensive to backfill — it is impossible** | **7** (2·1·3·1) | **Exploit** | `../ai/` L-11 |
| **R-15** | **Dimension *ergonomics* (suggest the dimension, don't just store it)** | Intacct is the reference implementation and **autofills from a static configured relationship. QAYD can infer.** Dimensional accounting fails because humans will not enter data consistently, not because schemas cannot hold it | **7** (2·2·2·1) | **Exploit** | `../erp/` L-07 |
| **R-16** | **Counterparty *capacity*, not just identity** | In GCC family-owned structures the same legal entity is routinely customer, supplier, lessor and shareholder. Related-party disclosure and intercompany elimination depend on capacity | **6** (3·2·1·0) | **Hold** | `../erp/` L-08 |
| **R-17** | **Temporal classification relationships** | *"Which accounts were in Operating Expenses in FY2024?"* is unanswerable if membership is a mutable FK — and is exactly the question asked after the reorganisation that mutated it | **6** (3·1·2·0) | **Hold** | `../erp/` L-09 |
| **R-18** | **Provable cold archive (manifest + `ledger_head_hash`)** | Iceberg proves *which* version you read; a chain proves the version *was not altered*. Nobody combines them for an SME ledger | **6** (3·1·2·0) | **Hold** | `../analytics/` L-03 |
| **R-19** | **Phone-reachable, Arabic, Gulf-working-week support** | **Xero has no inbound phone support, as stated policy** `[DOCS]`. Uncopyable in a release; costs headcount, which is why it is defensible | **7** (3·3·1·0) | **Exploit** | `[DOCS, verified 2026-07]` |
| **R-20** | **Fixed-asset capacity for capital-heavy GCC SMEs** | **Xero publishes a 500-fixed-asset ceiling** `[DOCS]`. Not hard to exceed; not a gap anyone is racing to close | **4** (2·1·1·0) | **Hold** | `[DOCS, verified 2026-07]` |
| **R-21** | **An audit-trail *report* in the base product** | **Xero routes a high-vote audit-trail request to its App Store, answered "Not in pipeline"** `[COMMUNITY]`. A GL that outsources "show me what the system did" has outsourced its accountability | **6** (3·2·1·0) | **Hold** | `[COMMUNITY]` Xero Product Ideas |

## 3.2 Type B — Bad ideas (unbuilt for good reason, or built and regretted)

**These are the entries most likely to be mistaken for opportunities.** Each carries the mechanism of harm.

| # | Apparent gap | Why it is a bad idea | Evidence |
|---|---|---|---|
| **B-01** | **Autonomous posting at high confidence** | Confidence is a statement about the model's internal state, not the world, and **it degrades most gently exactly where accuracy degrades most sharply.** *"Autonomous is not merely risky, it is category-destroying"* — and it destroys R-02, which is the actual moat | `../ai/` R-32; `../innovation/` |
| **B-02** | **A chat box over the ledger** | Commoditised within eighteen months, and **the window is closing rather than opening** — Xero's JAX ships free to all subscribers; Intuit shipped named QBO agents. Also: *"in finance, the form is not an input mechanism, it is a constraint display"* — a chat box removes the user's error-detection surface at the moment it matters | `[COMMUNITY]`; `../accounting/ANTI_PATTERNS.md` §22 |
| **B-03** | **Concurrent multi-agent orchestration** | Measured at ~15× tokens, and the poor-fit condition is exactly accounting: sub-results that must be mutually consistent. **Lines must balance, matches must not double-consume, a trial balance must tie.** Concurrent components whose outputs must agree is a machine for individually-plausible, collectively-wrong entries | `../ai/` L-02 |
| **B-04** | **A purpose-built ledger database beside PostgreSQL** | Two stores to keep consistent, a distributed transaction on the most correctness-critical operation in the product, two RLS stories — and **`ledger_entries` loses the ability to join `accounts`, which is the basis of every report QAYD sells.** TigerBeetle is explicit it *"assumes a trusted environment and does not provide permission systems"* | `../banking/` R1; `../payments/` §4.1 |
| **B-05** | **A generic rules engine for posting** | The idea is right and the implementation is a trap: *"a rules engine is a programming language you now maintain, debug and secure, usually without a debugger."* Fusion's SLA is widely regarded as one of the harder things to configure in enterprise software. **Keep the seam; scope any engine to tax; never to the double-entry core** | `../erp/` L-12 |
| **B-06** | **A metadata-driven schema ("add a GL column from a web form")** | An impressive demo that **costs foreign keys, CHECK constraints and the ability to reason about the schema** — the exact properties that are QAYD's entire advantage. ERPNext ships a `Ledger Health Monitor` to detect the drift it causes | `06` §4.3 |
| **B-07** | **A generic workflow engine** | **Odoo built one and deleted it**, replacing it with explicit state fields. A free lesson | `06` §4.3 |
| **B-08** | **An app marketplace** | *"An ecosystem is a commitment never to fix your foundations."* It is also why Dolibarr's core can never tighten an invariant. And Xero has now demonstrated that a platform re-prices its ecosystem when it suits — **per-connection fees plus an AI-training prohibition, March 2026** | `../accounting/` §5; `[DOCS]` |
| **B-09** | **User-authored server-side code** (Zoho ships Deluge/Node/Java/Python/Go) | Permanently freezes internal interfaces — the mechanism that stops Dolibarr's core ever tightening an invariant. Deliver the demand as declarative predicates plus webhooks | `../accounting/` §5 |
| **B-10** | **A general pending/authorisation phase on the ledger** | Every balance query, report, rollup, export and API response would specify a phase **forever**, to serve a feature that does not exist. An SME accounting ledger has no authorisation concept | `../banking/` R2 |
| **B-11** | **Kafka / a second event backbone** | The outbox already gives durability tied to the same transaction as the fact. Kafka's design point is **three to four orders of magnitude** above QAYD's peak, and an accounting system's event volume is **bounded by human business activity** — an architectural ceiling, not a current measurement | `../analytics/` L-08 |
| **B-12** | **An OLAP warehouse / analytical database for tenant data** | The tenant predicate is already maximally selective. A warehouse is **a second copy of the money, outside RLS, outside the append-only trigger, outside PITR alignment.** Druid additionally discards raw rows at ingestion — *"an accounting system may never discard a row to make a report faster"* | `../analytics/` |
| **B-13** | **In-database analytical engines (`pg_duckdb`)** | An alternative scan path over tenant tables is an RLS surface to audit, **inside the database that holds the ledger.** The benefit is free out of process | `../analytics/` L-09 |
| **B-14** | **Materialised views over tenant-scoped money** | The view is populated in the refresher's GUC context: **empty under fail-closed RLS, or cross-tenant under a bypassing role — and RLS is a table feature, so the result cannot be re-protected afterwards.** A correctness objection, not a performance one, which is why it stays rejected | `../analytics/` L-07 |
| **B-15** | **Fine-tuning a model on customer data** | Un-auditable (*"which training example caused this?"* has no answer), cannot be superseded on a date, cannot be tenant-scoped without one model per tenant. **For a product whose core claim is that every number is explicable, parametric memory is structurally the wrong container** | `../ai/` |
| **B-16** | **A separate vector store** | A second tenant-isolation implementation with a different enforcement mechanism, a different failure mode, no catalog introspection test, and its own backup, retention, deletion and residency obligations — *"the same class of decision as a second writer into the ledger"* | `../ai/` L-13 |
| **B-17** | **Naive RAG on the hot path** | Semantic search is weakest exactly where chart-of-accounts mapping lives: **distractor-dense category assignment with low needle-question similarity.** A tenant's chart, policies and judgements fit under 200k tokens and belong in the cached prefix | `../ai/` L-14 |
| **B-18** | **Broad field-level encryption** | Defeats no realistic adversary in a single-service architecture — the application holds the key — while destroying indexing, sorting and aggregation, and **directly conflicting with the requirement to aggregate money in the database** | `../security/` §5.2 |
| **B-19** | **Buying an attestation pre-launch** | A SOC 2 Type II opines on operating effectiveness *over a period*; **with zero customers there is nothing to observe.** All-in first-year cost $30–60k — more than the entire remaining security backlog costs to close. *"An organisation that buys a SOC 2 before it has users is buying a document about a company that does not yet exist"* | `../security/` §5.1 |
| **B-20** | **A nightly batch that *produces* state** | **Banking batches to produce state; QAYD should batch only to verify it.** A nightly window is a place for failures to hide — a job that failed at 02:00, noticed at 09:00, with the day's work built on wrong numbers | `../banking/` R8 |
| **B-21** | **Localisation breadth (50+ countries)** | Thousands of person-years of permanently-maintained regulatory minutiae. **SAP itself ships "Localization as a Self-Service" because its own coverage is finite.** Four GCC countries done exactly beats fifty done approximately | `06` §4.3 |
| **B-22** | **Building the accounting product *on* a competitor's API** | Your substrate is a competitor's product: they can ship the feature, close the API, or **forbid the use case — which Xero did on 2 March 2026** | `competitive/ANTI_PATTERNS.md` §10 |

## 3.3 Type T — Temporary gaps

| # | Gap | Who closes it, and when | What to do |
|---|---|---|---|
| **T-01** | Incumbent AI assistants | **Already closed.** JAX (Sept 2025, free to subscribers); named QBO agents (2026) | Never claim they lack AI. `competitive/ANTI_PATTERNS.md` §12 |
| **T-02** | Auto-categorisation as a shipped feature | Zoho's is **Early Access, opt-in by emailing support** `[DOCS]`. It will go GA | Build the labour-removal loop for its *quality*, not its existence |
| **T-03** | Kuwaiti bank feeds | If CBK finalises open banking and aggregators arrive. **CBK's framework has been draft since June 2025** | **Plan R-01/R-12 as a head start, not a permanent condition.** On the day feeds arrive, being the product that already knows every Kuwaiti bank's format with tenant history to match against is a strong position |
| **T-04** | Kuwait VAT / e-invoicing | Not before 2028 on current evidence `[COMMUNITY]` | Build the obligation spine as design hygiene; **never sequence on it** |
| **T-05** | JAX auto-reconcile leaving beta | Xero's own timeline | R-05's opening is dated. It will narrow — which argues for moving, not for waiting |

## 3.4 Type M — Mirages (`[UNKNOWN]`, close before spending)

| # | Apparent gap | Why it may not be one | How to close it | Cost |
|---|---|---|---|---|
| **M-01** | **Digits' AGL lacks real provenance** | It is a closed product with five years of work behind it. **Marketing is not a schema, and neither is its absence** | Trial account; API docs; a technical talk. **Do not infer from marketing** | Days |
| **M-02** | **Wafeq/Qoyod/Daftra have no AI or bank-ingestion depth** | They are Arabic-first, GCC-native, priced for the region and already in Kuwait. **These, not QuickBooks and Xero, are QAYD's real competitors** | Trial account with a KWD org; fils-level arithmetic test; upload a real NBK statement | Days |
| **M-03** | **Kuwait's practitioner community is small and reachable** | This is the load-bearing premise of the entire channel strategy and it is `[INFERENCE]` | Ten conversations + KNFSMD / PACI / Kuwait Association of Accountants and Auditors | **Two weeks. The highest-value item in the programme** |
| **M-04** | **Tally is the Kuwait incumbent** | Named repeatedly `[COMMUNITY]` and never verified. C-11's whole premise | Ask, in M-03's conversations | Free |
| **M-05** | **No Kuwaiti bank publishes statement data programmatically** | Strongly circumstantial. NBK, Gulf Bank, KFH, Boubyan and Burgan were not individually asked | Direct enquiry | Days |
| **M-06** | **Xero cannot do 3-dp KWD** | Xero has no *variable-precision model* — that is `[DOCS]`. Whether KWD specifically fails is **`[UNKNOWN]`, undocumented by Xero** | Trial org with KWD; enter 255.456 | Hours |
| **M-07** | **SOC 2 is the attestation GCC buyers want** | ISO 27001 is plausibly the more recognised instrument in Gulf procurement; SOC 2 is a US CPA product | Ask three prospects. **One email settles a $40,000 decision** | Free |

---

# 4. WHERE QAYD LOSES

A gap analysis that only finds opportunities is a pitch. **This section is longer than §6 on purpose.**

## 4.1 It loses on breadth, permanently

Zoho Books alone spans six tiers, 70+ reports, inventory, warehouses, batch tracking, projects, fixed
assets, budgets and multi-language templates `[DOCS]`. Daftra sells invoicing, sales, POS, inventory, CRM,
HR and payroll across 50+ sectors on one subscription `[COMMUNITY]`. **This gap never closes**, and
`../erp/` L-13 is right that it is the correct gap to have *today* — with an expiry date attached.

## 4.2 It loses on ecosystem, and the loss just got worse

Xero's marketplace carries a four-figure count of verified integrations `[COMMUNITY]` and now monetises
them directly `[DOCS, 2 March 2026]`. Decade-scale network effects that cannot be bought — and `B-08`
refuses building one on architectural grounds, which means **this is a permanent, deliberate loss.**

## 4.3 It loses on the visibility of its own strengths

The single most uncomfortable finding in this analysis, and it is reached independently by three folders:

- `../accounting/` §7.3: the append-only ledger, DB-enforced tenancy and exact money *"will not win a
  single first meeting. They win the third year, the first audit and the first incident."*
- `../security/` §4: *"QAYD answers the hard security questions better than it answers the easy ones."*
  MFA, an incident runbook, a tested restore and a security page are all cheap, all asked in every
  customer review, and **all missing**.
- `06` §4.5: SAP and NetSuite are enormously capable systems whose difficulty is easy to underestimate.

**A company with no runway cannot finance a three-year payoff**, which is why Rubric C scores
demonstrability and why R-03 (three-decimal money, visible in five minutes) outranks R-11 (signed anchors,
visible at the first audit) despite R-11 being architecturally deeper.

## 4.4 It loses on compliance, at home and in the obvious expansion market

**At home:** Kuwait has no VAT and none before 2028 `[COMMUNITY]`; the DMTT reaches MNE groups above €750m
consolidated revenue `[DOCS]` — roughly twenty Kuwaiti firms — and **creates no SME compliance product
whatsoever.** Every regional peer's position is mandate-underwritten.

**In Saudi:** Zoho is ZATCA Phase 2 approved with in-Kingdom residency from roughly SAR 60/month `[DOCS]`,
and **ZATCA has pushed the threshold down to SAR 375,000 turnover with compliance due April–June 2026**
`[DOCS]`. QAYD enters Saudi as the challenger to an entrenched accredited incumbent — the inverse of its
Kuwait position.

**The reframe that must survive:** *adoption must be earned on labour saved, not on obligation met.* That
is a harder sale and a longer one.

## 4.5 It loses on capital, in the category aimed at its differentiator

Basis: $100M at $1.15B `[DOCS, 2026-02-24]`. Pennylane: ≈$204M at a reported $4.25B `[COMMUNITY]`. Digits:
≈$98M `[COMMUNITY]`. **Funding is not traction** — but it buys engineering years, and QAYD has one team.

## 4.6 It loses on trust, because it has none yet

Every product in these studies keeps real books for real companies. QAYD keeps none. And it is worse than
neutral: **a pre-launch startup is a *worse* continuity risk than Intuit** — which stopped new QuickBooks
signups in India in July 2022 and ended the product in April 2023 `[DOCS]`. The only available answer is
demonstrated data portability, offered before it is asked for.

## 4.7 It loses on things it has not decided yet, which is the recoverable loss

- **`trg_no_ai_autopost` is `BEFORE INSERT` only** `[CODE]`. The central commercial claim is true on
  `INSERT` and false on `UPDATE`. Effort 2.
- **Posting writes no audit row** (TD-16) `[CODE]` — coverage is inverted relative to consequence, and
  because the hash chain is planned to live in `audit_logs`, **an unwritten audit row means the chain will
  not cover posting at all.**
- **The `audit_logs` platform-admin write hatch** sits inside a RESTRICTIVE policy — *"a hole in the
  mechanism whose entire purpose is to have no holes"* — and **must be closed before the hash chain, or
  the result is a cryptographic guarantee of the integrity of a lie.**
- **`journal_lines.reconciled` is a live column with no code path**, structurally unwritable when it would
  matter, and it is *"the exact decision that made Odoo's GL mutable."*
- **The idempotency layer has a specified dual-write hole** — key in Redis, after the transaction, only on
  success, under a 10-second lock; each choice independently permits a double post.
- **The firm-tenancy question is undecided**, and it is the only one on this list that becomes
  *impossible* rather than expensive.

**These are the honest losses, and they are the cheapest to reverse — which is why they are first in the
sequencing.**

---

# 5. THE WEIGHTED PICTURE

R gaps sorted by exploitability weight. **This is the output of the rubric.**

| Band | Gaps | Total |
|---|---|:--:|
| **Dominate (10–12)** | R-01 statement ingestion · R-02 provenance · R-03 three-decimal money · R-04 tie-out at import · R-05 reviewed state | **5** |
| **Exploit (7–9)** | R-06 Arabic-first · R-07 Tally migration · R-08 firm workspace · R-09 completeness · R-10 proposed rules · R-11 signed anchor · R-12 bank mappings · R-13 calibration · R-14 correction corpus · R-15 dimension ergonomics · R-19 phone support | **11** |
| **Hold (4–6)** | R-16 capacity · R-17 temporal classification · R-18 provable archive · R-20 fixed assets · R-21 audit-trail report | **5** |
| **Refuse (B)** | 22 entries | **22** |
| **Do not plan on (T)** | 5 entries | **5** |
| **Verify first (M)** | 7 entries | **7** |

**The ratio is the finding.** Fifty-five gaps examined; **twenty-two are bad ideas, five are temporary and
seven are unverified.** Twenty-one are real — and of those, eleven are worth building, five are worth
noting, and **only five are worth organising a company around.**

**Nine per cent of the apparent opportunity is the opportunity.** A gap register that converted half its
entries into roadmap would be a wish list with a rubric attached.

---

# 6. THE THREE PLACES QAYD SHOULD DOMINATE

Not "be good at" — **dominate**, meaning no competitor can match it without abandoning something they will
not abandon. Five gaps scored 10+; they collapse into three positions, because R-04 is a property of R-01
and R-05 is the visible half of R-02.

## 6.1 Kuwaiti bank statement → posted, cited, approvable entry

**The claim.** *QAYD turns a Kuwaiti bank statement, exactly as the bank gives it to you, into posted,
correctly-coded, cited entries — and no global product does.*

**Why nobody takes it away.**

| Contender | Why not |
|---|---|
| Xero, QuickBooks, Zoho | The economics forbid it. **Intuit abandoned India rather than localise it** `[DOCS]`. Kuwait clears nobody's threshold — proven by QBO building five UAE banks and 404ing on `/kw/` |
| Wafeq, Qoyod, Daftra | **The genuine threat, and it is `[UNKNOWN]`.** They are Arabic-first, GCC-native, and Wafeq already prices Kuwait in KWD. Their bank-ingestion and AI depth is M-02 |
| The AI cohort | No GCC presence found `[UNKNOWN]`, and their thesis is agents over an *existing* ledger |

**What makes it durable rather than temporary.** Two things. The **per-bank mapping library** is
accumulated proprietary knowledge a later entrant rebuilds customer by customer (R-12). And the
**statement is the stronger artefact**: `opening + Σ lines = closing` enforced at import is a control a
feed-first product cannot have, because it has no closing balance to check against (R-04).

**The honest caveat.** T-03. If CBK finalises open banking, this becomes a two-year head start rather than
a structural gap. **Plan it as a head start.**

**Cost: 21 points** (statement ingestion with per-bank adapters, tie-out gate, the mapping library).

## 6.2 Provenance an auditor accepts

**The claim.** *Every number an agent touched is explainable to your auditor, and the agent could not have
written it even if it tried, because it has no grant.*

**Why nobody takes it away.** Their architecture, not their roadmap, is the obstacle. Retrofitting a
proposal primitive into a live multi-tenant GL means changing the ledger schema of a system with tens of
thousands of tenants. **And the shipped attempt by the category leader demonstrates the difficulty
precisely:** JAX auto-reconcile is still beta, tier-gated, produces **no auditable reviewed-by-a-human
state**, and **drops description and reference** `[COMMUNITY, verified 2026-07]`.

**What makes it durable.** Three compounding layers:

1. **The schema property** — copyable only by a greenfield competitor. Digits may have it; `[UNKNOWN]`.
2. **The calibration data** — a published reliability curve requires a blind-sampled review stream nobody
   else runs (R-13).
3. **The correction corpus** — three years of a customer's corrections, **impossible to backfill on the
   edit path** (R-14). *A competitor can copy a feature; they cannot copy three years of corrections.*

**The three preconditions, and one of them is currently unmet:**

- `trg_no_ai_autopost` must fire on `UPDATE`. **It does not.** Effort 2. Until then the claim is falsifiable.
- Posting must write an audit row (TD-16), or the chain will not cover posting.
- The `audit_logs` write hatch must close **before** the chain, or the chain guarantees the integrity of a
  lie.

**Cost: 34 points** across the proposal surface, instrumentation, chain and anchor — of which **the first
2 points make the headline claim true.**

## 6.3 Three-decimal correctness, proved rather than asserted

**The claim.** *KWD, BHD and OMR are correct to the fils, end to end — validation, storage, arithmetic,
rounding, display, PDF and export — and here is the test that proves it.*

**Why it is a domination position despite being the smallest.** It is the only one **visible in five
minutes with a competitor's trial account open**:

- **QuickBooks:** Intuit's own community response — amounts *"default to two places after the period.
  Changing this is currently unavailable"* `[COMMUNITY]`.
- **Xero:** *"assumes two decimal places for most currencies"* `[DOCS]`; no variable-precision model.
  Whether KWD specifically fails is `[UNKNOWN]` (M-06) and **must not be claimed either way**.
- **OFBiz:** rejects only imbalances ≥ 0.01 `[CODE]` — **0.009 KWD posts successfully**, nine times the
  smallest unit, accumulating without bound while the trial balance still balances to tolerance.
- **Zoho** has the correct *shape* (per-currency decimals) `[DOCS]`, but whether three decimals survive
  tax, FX, matching and the statements is `[UNKNOWN]`.

`../erp/` L-06 calls this the most instructive defect in that study: *"a correct decision made inside an
unstated assumption — that money has two decimals — that fails only outside the author's jurisdiction and
is therefore invisible to the vendor's own testing. QAYD's entire market is that outside."*

**The one thing standing between QAYD and this position.** QAYD's money model is already `NUMERIC(19,4)`
with zero-tolerance balance assertion `[CODE]`. **It is asserted, not proved.** The requirement is a
three-decimal currency in the standard test matrix and a named regression test asserting that a KWD journal
off by 0.0005 is rejected. **Cost: 3 points. It is the cheapest domination position available to any
company in this analysis.**

## 6.4 What these three have in common

Each is a place where **a constraint of QAYD's market produces a better design than the incumbent's
freedom did.** No feeds forces statement-primary modelling, which yields a completeness control feeds
cannot have. A greenfield ledger forces proposal-as-a-row, which yields provenance a retrofit cannot.
A three-decimal home currency forces variable precision, which yields correctness a two-decimal assumption
cannot reach.

**That is the strategic shape worth holding: QAYD's advantages are downstream of its disadvantages.**

---

# 7. WHAT THE ANALYSIS DOES NOT SETTLE

| # | Question | Why it is load-bearing | Cost to close |
|---|---|---|---|
| 1 | **Is the Kuwaiti practitioner community reachable?** (M-03) | The channel is not a growth option; `competitive/LESSONS_FOR_QAYD.md` §4 argues it is the mechanism that makes QAYD's chosen sale possible at all | **Two weeks** |
| 2 | **How deep are Wafeq, Qoyod and Daftra on bank ingestion and AI?** (M-02) | They are the real competitors. The §6.1 position must be defensible against *them*, and "we support Arabic" is not | Days |
| 3 | **Does Digits' AGL carry a genuine proposal primitive?** (M-01) | Decides whether §6.2 is open or narrowed | Days |
| 4 | **Will any customer pay a premium for assurance?** | `../innovation/` names this as the cheapest falsification test of the whole thesis and records that **it has not been run** | Twenty conversations |
| 5 | **SOC 2 or ISO 27001 in the GCC?** (M-07) | A $40,000 decision | One email |

**Item 4 is the one that should worry a reader.** Positions §6.2 and §6.3 both assume buyers value
provable correctness. If in twenty sales conversations *"how do I know it's right"* never determines an
outcome, the assurance thesis is wrong and this document's ranking is wrong with it.

---

# 8. THE ANALYSIS IN TEN LINES

1. **Fifty-five gaps examined; twenty-two are bad ideas and only five are worth organising around.**
   Separating them is the document's entire output.
2. **QAYD is the weakest system in the scorecard today** (1.9), consistent with Phase 2's 2.4.
3. **The designed ceiling (4.4 on Kuwait-relevant dimensions) is a hypothesis, not an achievement.**
4. **Two rows are 1 for everybody** — Kuwait bank ingestion and AI provenance. Those are the opportunity.
5. **QAYD's real competitors are Wafeq, Qoyod and Daftra**, and Tally is the incumbent to migrate from.
6. **Kuwait has no forcing function before 2028; adoption must be earned on labour saved.**
7. **Dominate three things:** statement-to-entry, auditor-grade provenance, three-decimal correctness.
8. **The cheapest of the three costs 3 points** and is visible in a five-minute meeting.
9. **The headline provenance claim is currently falsifiable** — the AI trigger is `INSERT`-only, effort 2.
10. **The load-bearing unknown is whether the channel exists**, and it costs two weeks to find out.

---

*No competitor source code was read; every commercial product scored here is closed. No pricing page, UI,
document or marketing asset was reproduced. Scores are judgements against a published rubric, not
measurements, and every one that rests on unverified evidence is marked `?` or `[UNKNOWN]` rather than
guessed.*

# End of Document
