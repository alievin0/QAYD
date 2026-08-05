# Lessons for QAYD

**Synthesis of:** [`OVERVIEW.md`](./OVERVIEW.md) · [`BEST_PRACTICES.md`](./BEST_PRACTICES.md) ·
[`ANTI_PATTERNS.md`](./ANTI_PATTERNS.md) · [`ARCHITECTURE.md`](./ARCHITECTURE.md)
**Builds on, does not repeat:** `docs/architecture/knowledge/06_COMPETITIVE_ANALYSIS.md` §4 (strategic
conclusions) and `07_QAYD_INNOVATION.md` (the twenty invented capabilities).
**Actionable form:** [`IMPLEMENTATION_RECOMMENDATIONS.md`](./IMPLEMENTATION_RECOMMENDATIONS.md).

---

## 1. What this study changes

The ERP study concluded that QAYD's architectural advantages are real but unproven, and that §4.2's
table-stakes list is what converts hypothesis into product. Nothing here contradicts that. Three things
are **new**, and each of them changes a plan rather than merely adding colour.

**First: the category's defining loop does not exist in QAYD's home market.** QuickBooks and Xero are
built around an automated bank feed. In Kuwait there is no licensed open-banking regime — the Central
Bank of Kuwait issued its framework only in **draft**, in June 2025, with no licensed providers
operational `[DOCS]`. No product in this study has a verifiable live bank feed for a Kuwaiti bank
`[UNKNOWN, leaning strongly negative]`. This is simultaneously the biggest constraint on QAYD and the
biggest opening in the market, and §7 turns on it.

**Second: Kuwait has no compliance forcing-function, and will not have one for years.** Kuwait still has
no VAT, and the government has ruled it out for the near term `[COMMUNITY]`; the DMTT that *did* arrive
applies only to multinational groups above €750m consolidated revenue `[DOCS]` — roughly twenty Kuwaiti
firms and a few hundred foreign MNEs. **It creates no SME compliance product whatsoever.** Meanwhile
Saudi's ZATCA has pushed its e-invoicing mandate down to a **SAR 375,000** turnover threshold, with
compliance due between April and June 2026 `[DOCS]` — a genuine forcing-function reaching real
micro-businesses. The strategic consequence is stark: *the GCC market where QAYD is based has the
weakest adoption trigger in the region, and the market with the strongest trigger already has an
entrenched, accredited incumbent.*

**Third: the labour-removing AI capability is unclaimed.** Zoho Books' shipped AI is retrospective and
advisory — insights, anomalies, forecasts, document drafting. **Automatic bank statement categorisation
is documented as Early Access, opt-in by emailing support** `[DOCS]`. The single feature that would
actually remove bookkeeping labour is, in the most GCC-present product in the category, not generally
available. That is the gap QAYD's General Accountant and Treasury loops were designed for, and it is
still open.

---

## 2. What these products actually are, and why it matters

A reframe worth holding, because it changes what "competing" means.

QuickBooks, Xero, Zoho Books and the rest are not primarily *ledgers*. They are **bank-transaction
classification engines with a ledger attached**. The ledger is a by-product of classification; the
classification is the product. That is why the bank feed is the first thing built, the first thing
demoed, and — when Wave decided what to charge for first — **the first thing put behind the paywall**
`[DOCS]`/`[COMMUNITY]`.

Two consequences follow.

**For QAYD's positioning.** Competing "on accounting quality" is competing on the by-product. Nobody in
the SME segment buys a better ledger; they buy less typing. QAYD's ledger correctness is a *licence to
operate and a durable moat against future defects* — it is not the pitch.

**For QAYD's architecture.** It also explains why the category's ledgers are mutable, why
reconciliation state sits on transactions, and why FreshBooks could ship for years before adding a
general ledger at all `[DOCS]`. The ledger was never the point for them. It **is** the point for QAYD,
and that is a real difference — but it is a difference that pays off in year three, not in the first
sales meeting. Plan the messaging accordingly.

---

## 3. Adopt — mechanisms, not features

Detailed in `BEST_PRACTICES.md`; listed here as commitments with the reason compressed.

| Adopt | Because |
|---|---|
| A **durable review queue** separating bank evidence from accounting assertion | Two different truth conditions; makes an unbounded task countable; gives automation a visible place to act |
| **Match-before-create** with residual derived from links | Settles obligations instead of inventing facts; reversible without damage; forced anyway by the append-only ledger |
| A **bulk coding surface**, keyboard-first | The bookkeeper with 400 lines decides adoption, not the owner with 4 |
| **Rules as a small, closed, ordered predicate language** | Explainable, safely bulk-evaluable, conflict-resolvable |
| **Statement-balance attestation** as a step distinct from line clearing | Proves completeness, which clearing cannot |
| **Seeded, editable, GCC-shaped chart of accounts** | Turns authoring into editing; removes the category's biggest onboarding wall |
| **Free assisted migration** — Tally and Excel first | Switching cost, not quality, protects the incumbent |
| **Conversion date + opening balances as modelled objects** | Nobody adopts on day one of a fiscal year |
| **Document capture with its own inbound channel**, generously metered | Capture happens away from the desk, and metering the input to an AI product suppresses the behaviour the product needs |
| **Recurring entries including journals** | The deterministic floor beneath the AI — SAP's rules engine outlived its ML layer (`06_COMPETITIVE_ANALYSIS.md` §4.4) |
| **Accountant console, certification, one-field client invite** | One accountant brings tens of clients |
| **Gulf working week, Arabic, phone-reachable support** | Cannot be copied in a release |

---

## 4. Invert — the same artefact, better authorship

Four places where the category's *shape* is right and its *direction* is wrong. These are cheap,
because the expensive part (the shape) is already validated.

### 4.1 Rules: machine proposes, human approves

The category has humans author predicates and machines execute them. QAYD should have the **machine
notice the pattern and propose the predicate, in the same inspectable form, for one-click human
approval**. Same artefact, inverted authorship. This is `07_QAYD_INNOVATION.md` I-16 delivered inside a
market-validated container — the cheapest possible way to ship a genuinely novel capability, and it is
strictly safer than the category's auto-apply rules because the proposal is recorded with its rationale
(`ANTI_PATTERNS.md` §6).

### 4.2 Compliance: model the obligation, not the report

FreeAgent files directly with the tax authority and maintains a forward-looking tax timeline `[DOCS]`.
Most of the category — including Zoho for UAE corporate tax, where its own documentation says returns
are filed *"on the FTA portal"* `[DOCS]` — produces a report a human takes elsewhere.

The generalisation: **an obligation is a first-class object** — type, jurisdiction, period, due date,
computed amount, evidence, filing state, lifecycle. Build the spine before there is an obligation to
model, so each regime becomes an adapter rather than a re-architecture.

**But sequence it honestly.** There is no Kuwait SME obligation to model today, and there will not be
one before 2028 on current evidence `[COMMUNITY]`. The spine is cheap insurance and correct design; it
is **not** a Kuwait wedge. Where it *is* a wedge — Saudi ZATCA, and the UAE e-invoicing window running
into 2027 — see §7.3.

### 4.3 The bank object: statement primary, lines derived

The category models the **line** as primary because a feed emits lines. QAYD's input is a **statement**,
which carries an opening balance, a closing balance and a period boundary. Modelling the statement as
primary yields a control feed-first products structurally cannot have: **`opening + Σ lines = closing`,
enforced at import**, rejecting a truncated CSV or a missing PDF page before a single entry is posted.

This is the clearest case in the study of a constraint producing a better design
(`ARCHITECTURE.md` §1.3). Treat it as an asset and say so in the product.

### 4.4 Completeness: measure what is missing, not what is touched

The category's progress signal is an empty review queue, which is why bank-feed-only bookkeeping
produces confident, incomplete books (`ANTI_PATTERNS.md` §2). QAYD's signal should be an **honest
completeness measure**: unentered documents in the inbox, missing recurring entries, no depreciation
this period, a supplier who bills monthly and has not this month. This is real work an agent can do
that no rules engine in the category does, and it directly serves the MVP's close-readiness concept.

---

## 5. Refuse

| Refuse | Why | Prior work |
|---|---|---|
| **User-authored server-side code** (Zoho ships Deluge/Node/Java/Python/Go custom functions `[DOCS]`) | Permanently freezes internal interfaces — the mechanism that stops Dolibarr's core ever tightening an invariant. QAYD's whole advantage is the freedom to keep tightening. Deliver the demand as declarative predicates + webhooks. | `06_COMPETITIVE_ANALYSIS.md` §4.3 |
| **An app marketplace, for now** | An ecosystem is a commitment never to fix your foundations. Xero's and QuickBooks' ecosystems are genuine moats built over a decade and cannot be bought. Revisit after product-market fit, never before. | §4.3 |
| **The feature-count race** | Zoho ships six tiers, 70+ reports, inventory, warehouses, batch tracking. QAYD will not match that and should not try. | §4.3 |
| **A chat box over the ledger** | Commoditising within eighteen months. QAYD's structural advantage is the *proposal primitive in the ledger*, not the conversation. | §4.3, §4.4 |
| **Pretending statement upload is a bank feed** | The category has trained the market on what a feed is. Claiming one and delivering an upload destroys credibility with the exact audience — accountants — that QAYD needs most. | This study |
| **Gating correctness behind a tier** | Multi-currency, KWD precision, audit trail and export belong in every tier. Gating them reads as contempt in a market with alternatives. | `ANTI_PATTERNS.md` §16 |
| **Metering document capture tightly** | Zoho's 200 scans/month is a real constraint `[DOCS]`. Metering the input to an AI-first product suppresses the behaviour the product needs to be useful and to learn. | `BEST_PRACTICES.md` §2.4 |

---

## 6. The channel — and why it is different here

**The category's most important go-to-market fact is that professionals distribute the product.** Zoho
runs a certified accountant partner programme with mandatory training, a client-subscription console,
a partner directory and free Zoho One access `[DOCS]`; its GCC motion is **reseller-led rather than
direct**, with Dubai as the regional hub `[COMMUNITY]`.

Three observations make this contestable in Kuwait specifically.

1. **Zoho's channel is thin in Kuwait relative to Dubai and Riyadh** `[COMMUNITY]`, and Kuwait is not a
   managed market for them — the tell is that Kuwait, Bahrain, Oman and Qatar are priced **in US
   dollars** while the UAE and Saudi get local-currency pricing `[DOCS]`.
2. **The incumbent's accountant onboarding is a known irritant.** Accountants complain directly that on
   Xero and QuickBooks adding a client is *"you type an email address and that's it"* while Zoho's flow
   is clunkier `[COMMUNITY]`.
3. **Kuwait's bookkeeping and audit community is small, concentrated and reachable in person.** That is
   a structural advantage for a local founder and a structural disadvantage for a vendor selling
   through a Dubai reseller. Precise figures on the number of Kuwaiti SMEs and licensed practitioners
   could not be verified in this pass `[UNKNOWN]` — see `OVERVIEW.md` §10.

**The obligation:** treat the accountant as the primary customer of the *first* release, not the second.
Multi-client console, one-field client invitation, bulk work surfaces, certification path. The owner is
the user; the accountant is the buyer and the distributor.

---

## 7. The answer: where a new entrant can actually win

This is the question the study exists to answer. It is answered without softening.

### 7.1 Where QAYD cannot win, stated first

- **Features.** Zoho Books alone spans six tiers, 70+ reports, inventory, warehouses, batch tracking,
  projects, fixed assets, budgets and multi-language templates `[DOCS]`. This gap will never close.
- **Ecosystem.** Xero's marketplace carries a four-figure count of verified integrations `[COMMUNITY]`.
  Network effects of that kind cannot be bought.
- **Price, at the bottom.** Zoho Books Standard is roughly $15–18/month in Kuwait `[DOCS]`, and its Saudi
  ZATCA compliance is included from that tier at roughly SAR 60/month `[DOCS]`. QuickBooks' **Global
  edition** — the one a Gulf buyer gets — is **$21 / $31 / $46 / $89**, roughly a third of US list
  `[DOCS]`. There is no room underneath, and any competitive claim anchored on US pricing ($38–$275) is
  simply wrong outside the US.
- **Trust and continuity, today.** Every one of these products keeps real books for real companies.
  QAYD keeps none. `06_COMPETITIVE_ANALYSIS.md` §4.5 said it plainly and it remains true: architectural
  superiority that has never survived contact with a live tenant is a hypothesis.
- **Compliance depth in Saudi.** Zoho is a **ZATCA-approved Phase 2 solution** with in-Kingdom data
  residency exposed in its API `[DOCS]`. That is occupied ground.

### 7.2 The dimension where a new entrant *can* win

**Bank data ingestion in a market that has no bank feeds — and the AI that turns the resulting mess into
posted entries.**

The argument, in five steps:

1. **The core loop of the entire category depends on an input that does not exist in Kuwait.** No
   licensed open-banking regime; CBK's framework is draft-stage with no operational licensed providers
   `[DOCS]`.
2. **No incumbent has solved it *for Kuwait* — and the one that solved it for the UAE proves why.**
   QuickBooks **does** offer UAE bank feeds, naming ADCB, Dubai Islamic Bank, Emirates NBD, First Abu
   Dhabi Bank and RAK Bank `[DOCS]` (https://quickbooks.intuit.com/ae/bank-feeds/). It has built no
   Kuwait storefront at all — `quickbooks.intuit.com/kw/` returns **404** `[DOCS, by observation]`.
   Zoho integrates Yodlee, Plaid (US/Canada only) and Token (UK/EU) and **zero GCC-native aggregators**;
   it publishes no supported-bank list for the region, and its KB article titled *"Find if my bank
   supports automatic feeds"* contains no list and tells the user to email support `[DOCS]`. Its only
   confirmed GCC bank partnership is a 2019 UAE deal whose help page now 404s `[DOCS]`. For **Kuwait**,
   Saudi, Bahrain, Oman and Qatar there is no evidence of any live feed from anyone
   `[UNKNOWN, leaning strongly negative]`.

   **The UAE counter-example strengthens the argument rather than weakening it.** It demonstrates that
   incumbents build feeds where the market clears their threshold — and that **Kuwait does not clear it
   for any of them.** That is what makes the gap durable rather than temporary.
3. **The fallback is manual and painful.** UAE users hand-edit bank CSV headers to match expected field
   names before import will work `[COMMUNITY]`. Every month, forever.
4. **They will not fix it.** Not because they are incapable, but because the economics forbid it —
   Intuit stopped accepting new QuickBooks signups from India in July 2022 and ended the product there
   in April 2023 `[DOCS]`. If India did not justify the localisation cost, Kuwait never will. **This is
   structural and permanent, not a roadmap gap.**
5. **QAYD's AI is aimed precisely at the residue.** A PDF statement from NBK, a photographed supplier
   bill, an Arabic vendor name — this is document understanding, which is exactly what the AI engine
   exists to do, and it is worth *more* in a market without feeds than in one with them.

**The claim that follows is narrow, defensible and worth making:** *QAYD turns a Kuwaiti bank statement,
exactly as the bank gives it to you, into posted, correctly-coded, cited entries — and no global product
does.*

Three supporting differentiators reinforce it. None is a strategy alone; together they make the wedge
credible:

- **Correct KWD arithmetic.** KWD has three decimal places `[DOCS]`. Intuit's own community response to
  a Kuwaiti user is that amounts *"default to two places after the period. Changing this is currently
  unavailable"* `[COMMUNITY]` — extra decimals exist on exchange *rates*, not on amounts. **That is a
  hard disqualifier for Kuwait, Bahrain and Oman.** Zoho exposes per-currency decimal configuration
  `[DOCS]` but its allowed values and end-to-end precision through tax, FX and reporting are
  undocumented `[UNKNOWN]`; Xero is `[UNKNOWN]`. Being provably, demonstrably correct in fils is nearly
  free for QAYD and is a reproducible defect in the category's volume leader.
- **Arabic-first rather than Arabic-translated.** Zoho's GCC help documentation is **English-only for
  every GCC edition** `[DOCS]`; Arabic invoice PDFs require manually setting an RTL font `[COMMUNITY]`.
  Arabic UI is not an Arabic product.
- **Migration from what people actually run.** **Tally is the real incumbent in a Kuwait SME deal**
  `[COMMUNITY]` — not QuickBooks, not Xero. Zoho's Tally path is a manual export sequence with no tool,
  which has spawned a paid third-party migration industry `[DOCS]`/`[COMMUNITY]`. A one-click Tally and
  Excel importer is worth more here than a QuickBooks importer.

### 7.2a The existence proof

The wedge argument would be speculative if no regional entrant had ever beaten the incumbents on their
own ground. One has, and recently.

Saudi's ZATCA Phase 2 is the region's hardest compliance bar — signed XML, cryptographic stamps, QR,
invoice hash chaining, real-time clearance through Fatoora `[DOCS]`. **QuickBooks does not clear it
natively and does not appear in ZATCA's Solution Providers Directory**; QuickBooks customers in Saudi
bolt on third-party middleware `[COMMUNITY]`. The solutions actually cited as used or approved are Zoho
Books, SAP, Oracle, **Wafeq**, **Qoyod**, FastFatoora and FatooraOnline `[COMMUNITY]`.

**Two of those seven are Arabic-first regional startups founded within the last decade** — Qoyod (Saudi,
2016) and Wafeq (UAE, 2019) — and they are on the list while the category's global volume leader is not.
Wafeq already runs a **Kuwait page priced in KWD**, bilingual, though notably claiming **no Kuwaiti bank
feeds and no Kuwait-specific compliance** `[DOCS]` (https://www.wafeq.com/en-kw).

Two conclusions follow, and they cut in opposite directions — both matter.

1. **It is demonstrably possible for a regional Arabic-first entrant to beat global incumbents in a GCC
   market.** The incumbents' disadvantage is structural, and regional players have already exploited it.
2. **QAYD's real competitors are Wafeq, Qoyod and Daftra — not QuickBooks and Xero.** They are
   Arabic-first, GCC-native, priced for the region, and already in Kuwait. The wedge in §7.2 must be
   defensible against *them*, and "we support Arabic" is not. **What separates QAYD from that group is
   the AI loop and the ledger architecture underneath it — not localisation.** Their depth on bank
   ingestion and AI is `[UNKNOWN]` and is the single most important competitive question left open by
   this study (`OVERVIEW.md` §10, item 8).

### 7.3 The uncomfortable parts

A wedge that is only stated in its favourable form is marketing, not analysis.

- **Kuwait alone is too small to build a company on, and the obvious expansion market is defended.**
  Saudi is the prize; Saudi is where Zoho is ZATCA-accredited, in-Kingdom hosted, and included from a
  ~SAR 60/month tier. QAYD enters Saudi as the challenger to an entrenched, compliant incumbent — the
  opposite of its Kuwait position.
- **The wedge depends partly on something QAYD does not control.** If CBK finalises open banking and
  aggregators arrive, the moat becomes a two-year head start rather than a structural gap. That is
  still valuable — being the product that already has every Kuwaiti bank's statement format mastered,
  with the tenant history to match against, is a strong position on the day feeds arrive — but it must
  be planned as a head start, not a permanent condition.
- **The work is unglamorous.** Per-bank statement adapters are grinding, low-status engineering with no
  demo value, and they must be maintained as banks change formats. It is precisely the work a global
  vendor will not do, which is why it is defensible — but somebody has to do it.
- **The compliance wedge is unavailable at home.** Kuwait offers no VAT and no e-invoicing before 2028
  on current evidence `[COMMUNITY]`. QAYD's home market is the GCC market with the weakest adoption
  trigger. **Adoption must be earned on labour saved, not on obligation met.** That is a harder sale
  and a longer one.
- **QAYD is behind on table stakes.** Per `PROJECT_STATUS.md`, Sprint 2 has four of fourteen stories
  closed and the posting engine is under review. There is no reconciliation, no statements, no
  invoicing, no tax, and most of the frontend and the AI engine are unbuilt. The wedge described above
  is roughly two modules and a lot of adapter work away.
- **The strongest architectural advantages are invisible to buyers.** The append-only ledger,
  DB-enforced tenancy and exact money will not win a single first meeting. They win the third year, the
  first audit and the first incident. Budget the messaging honestly.

### 7.4 The minimum credible product

The smallest thing that is genuinely defensible in Kuwait — not the smallest thing that runs.

| # | Capability | Why it is in the minimum |
|---|---|---|
| 1 | **Statement ingestion with zero manual editing for the top Kuwaiti banks** — PDF and CSV, with per-bank adapters, and `opening + Σ lines = closing` enforced at import | This *is* the wedge. Without it there is no product story. |
| 2 | **AI categorisation and matching that actually posts** — proposal, confidence, cited statement line, one-click approval, bulk approve | The labour-removing capability nobody in the category ships GA. Without it, item 1 is just an importer. |
| 3 | **Bank reconciliation to a statement closing balance**, as an event, not a flag | Table stakes; and the input format makes QAYD's version stronger. |
| 4 | **Bill capture → drafted entry**, already the MVP spine | The other half of the mechanical burden; the AI's most legible win. |
| 5 | **Trial balance, P&L, balance sheet**, drill-down to entry to source document | An accounting system that cannot produce a trial balance is not one (`06_COMPETITIVE_ANALYSIS.md` §4.2). |
| 6 | **Correct KWD to three decimals**, end to end — validation, storage, arithmetic, rounding, display, PDF | Cheap, provable, and a live defect in a market leader. |
| 7 | **Arabic-first UI, documents and support** — including correct Arabic PDF output | The gap is real and unclaimed even by the most GCC-present incumbent. |
| 8 | **Tally and Excel migration with opening balances**, plus a conversion date | Removes the switching cost protecting the actual incumbent. |
| 9 | **Accountant multi-client console** with one-field client invitation | The channel. Not optional, not phase two. |
| 10 | **Period close, human-approved**, and an immutable audit trail | Already QAYD's design; it is what makes the AI safe to trust. |

**Deliberately excluded from the minimum:** multi-currency (defer, but decide rate provenance now —
`ARCHITECTURE.md` §1.6), inventory, payroll, sales invoicing, VAT/e-invoicing engines, app marketplace,
mobile, and any chat assistant. Each is real; none is what makes the first cohort switch.

**The one-line version:** *the minimum credible product is a Kuwaiti bank statement and a supplier bill
going in, and correctly-coded, cited, approvable, auditable books coming out — in Arabic.*

---

## 8. What this study says about decisions QAYD has already made

| Decision | Verdict | Reason |
|---|---|---|
| Ledger-first, not invoicing-first | **Validated, and it is a real asset** | FreshBooks retrofitted a ledger onto a document store `[DOCS]`; the retrofit direction permanently determines what a product can cleanly do (`ARCHITECTURE.md` §2.4) |
| Append-only `ledger_entries`, trigger-enforced | **Validated** | The category's mutable ledgers are the root of its reconciliation fragility |
| Statement upload rather than live feeds in MVP | **Validated — and it is forced, not a compromise** | There are no Kuwaiti bank feeds to build against `[DOCS]`; and the statement is the stronger artefact |
| Single currency (KWD) in MVP | **Accepted with a caveat** | Structurally safe (`ARCHITECTURE.md` §1.6), but a Kuwaiti trading SME holds USD and AED. Fine for proving the AI loop; not fine for the first paying cohort |
| Bilingual AR/EN with RTL from screen one | **Validated and under-weighted** | Zoho's GCC help documentation is English-only for every GCC edition `[DOCS]`. This is a bigger differentiator than QAYD currently treats it as |
| AI proposes, never posts; proposal as a ledger primitive | **Validated, and it is the durable AI advantage** | The category's AI sits *beside* the ledger. QAYD's sits *inside* it |
| `journal_lines.reconciled` boolean | **Challenged — drop it** | It is the exact mechanism by which the category's undo-reconcile rewrites history (`ANTI_PATTERNS.md` §3) |
| Bank feeds deferred to P2 | **Re-scope rather than defer** | The thing to build is not a feed; it is **statement ingestion with per-bank adapters**, and it belongs in the MVP because it is the wedge |
| Tax tracked in MVP, filing deferred | **Validated for Kuwait, revisit for Saudi** | No Kuwait SME obligation exists; Saudi's does, and entering Saudi without ZATCA is not possible |
| Accountant tooling not in MVP | **Challenged** | The accountant is the distribution channel in this category. A multi-client console belongs in the first release |

---

## 9. The three ways this fails

Recorded so they can be watched rather than discovered.

1. **QAYD builds the category's product instead of the market's.** The pull toward replicating
   QuickBooks' surface is strong, because it is legible and every competitor validates it. It leads to
   a worse QuickBooks in a market that cannot use QuickBooks' core mechanism. The counter-discipline is
   §7.4: build the statement wedge first, and let the rest wait.

2. **Revenue pressure produces an invoicing pivot.** Invoicing sells fastest and demos best, and it is
   how FreshBooks and half the category began. Doing it would trade QAYD's only structural advantage —
   a correct, immutable, AI-native ledger built first — for a quarter of easier meetings.

3. **The wedge is announced before it works.** The category's audience is accountants, and accountants
   are unforgiving about overclaiming. Calling statement ingestion a "bank feed", or describing an
   opt-in AI capability as shipped, would cost exactly the credibility the channel strategy in §6
   depends on. **The honesty standard in `BEST_PRACTICES.md` §3.3 applies to QAYD first.**
