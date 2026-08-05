# Competitive Research — the cross-category view

**Domain:** `docs/research/competitive/`
**Question:** *Who does QAYD actually compete with for a budget line, and on what basis does anyone win?*
**Status:** Phase 3 of the QAYD engineering research programme. **Documentation only** — no application
code, schema, migration, seed or test was created or modified in producing these files.
**Version:** 1.0 · **Written:** 2026-07-28 · **Shelf life:** short (see §Maintenance)

---

## ⚠️ Read this first — what is NOT here

Most of QAYD's competitive surface is already researched, in depth, elsewhere in this repository. This
folder does **not** repeat it, and reading it as a standalone competitive picture would be a mistake.

| If you want… | Read | Depth |
|---|---|---|
| **Architecture** comparison vs Odoo, ERPNext, SAP, NetSuite, D365, Akaunting, Dolibarr | [`../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md`](../../architecture/knowledge/06_COMPETITIVE_ANALYSIS.md) | 1,237 lines, 16 subsystems, the scorecard (QAYD **2.4** mean vs SAP 4.7, ERPNext 3.4) |
| **Experience** comparison vs QuickBooks, Xero, Zoho Books, FreshBooks, Wave, FreeAgent, KashFlow | [`../accounting/`](../accounting/) | 6 files; the core loop, onboarding, the Kuwait reality, the accountant channel |
| Odoo, in exhaustive source detail | [`../odoo/ODOO_LEARNING.md`](../odoo/) | 14,150 lines |
| Tryton, OFBiz, Sage Intacct, Acumatica, Oracle Fusion, Infor, Epicor | [`../erp/`](../erp/) | 7 files |
| Payment rails, PSPs, KNET, GCC settlement reality | [`../payments/`](../payments/) | 4 files |
| Core banking ledgers (Mambu, Temenos, Thought Machine, TigerBeetle, Increase) | [`../banking/`](../banking/) | 6 files |
| OLAP / warehouse / columnar (and why QAYD needs none of it) | [`../analytics/`](../analytics/) | 3,151 lines |
| Agent engineering, context, evals, prompt-injection | [`../ai/`](../ai/) | 4 files |
| SOC 2 / ISO 27001 / PCI / GCC data protection | [`../security/`](../security/) | 3,140 lines |

**This folder is the layer none of those cover: the commercial one.** Positioning, business models,
pricing, packaging, go-to-market, distribution and moats — across *categories*, not just across ERPs.

---

## Why a cross-category folder exists at all

The Phase 2 analysis concluded that **ERPNext, not SAP, is the competitor QAYD actually meets in a GCC
deal** (`06_COMPETITIVE_ANALYSIS.md` §4.5). That conclusion is correct and this folder does not
overturn it. But it is a conclusion about *systems of record*, and it silently assumes the buyer is
choosing a system of record.

Increasingly they are not. Four distinct product categories now converge on the same finance budget:

```
                    ┌──────────────────────────────────────────────────┐
                    │            The SME finance budget                │
                    └──────────────────────────────────────────────────┘
                         ▲            ▲             ▲            ▲
             ┌───────────┘            │             │            └───────────┐
             │                        │             │                        │
   ┌─────────────────┐   ┌────────────────────┐  ┌──────────────────┐  ┌──────────────┐
   │  ERP / system   │   │ Accounting SaaS    │  │ Spend platforms  │  │ AI agents /  │
   │  of record      │   │ (QBO, Xero, Zoho)  │  │ (Ramp, Brex)     │  │ AI bookkeep. │
   │  Odoo, ERPNext, │   │                    │  │                  │  │ Digits,      │
   │  SAP, NetSuite  │   │ *sells* the ledger │  │ *gives away* the │  │ Basis,       │
   │                 │   │                    │  │ software, earns  │  │ Truewind,    │
   │ Phase 2 + erp/  │   │ accounting/        │  │ on the rail      │  │ Pennylane    │
   └─────────────────┘   └────────────────────┘  └──────────────────┘  └──────────────┘
        studied              studied                  ── HERE ──          ── HERE ──
```

Two of those four columns had never been examined. The spend-platform column matters because it
demonstrates a business model in which **the accounting software is free and the money is made
elsewhere** — an existential question for anyone planning to charge for accounting software. The AI-agent
column matters because it is where the capital is going in 2026, because several of those companies are
explicitly building the "proposal-then-approval" primitive that `06_COMPETITIVE_ANALYSIS.md` §4.4
identified as QAYD's largest white space, and because **one of them, Pennylane, has validated the
accountant-channel strategy that the accounting research recommends for QAYD** — at a $4.25B valuation
`[COMMUNITY, Jan 2026]`.

And the regional column — Wafeq, Qoyod, Daftra, Tally, the Kuwaiti resellers — is who QAYD actually loses
deals to. Not SAP. Not Ramp.

---

## The files

| File | Lines | What it contains | Read it when |
|---|---|---|---|
| [`OVERVIEW.md`](./OVERVIEW.md) | 519 | The five-category map; per-player profiles with business model, pricing, GTM and evidence date; the business-model taxonomy; the pricing archetypes; the distribution analysis; the moat register; the `[UNKNOWN]` register | You need the facts about a company or a category |
| [`BEST_PRACTICES.md`](./BEST_PRACTICES.md) | 849 | Commercial mechanisms that demonstrably work, and *why* they work — not admiration. Each with its **precondition**, which is what decides whether QAYD can use it | Designing pricing, packaging, channel or positioning |
| [`ANTI_PATTERNS.md`](./ANTI_PATTERNS.md) | 752 | 23 commercial failure modes with the mechanism of harm and the cost — **six marked ⚠️ LIVE for QAYD today** | Before committing to a business-model decision |
| [`ARCHITECTURE.md`](./ARCHITECTURE.md) | 540 | What each business model *forces* in the architecture — the substrate-vs-integration split, firm tenancy, metering, portability, and the negative space | Designing anything whose shape a commercial decision constrains |
| [`LESSONS_FOR_QAYD.md`](./LESSONS_FOR_QAYD.md) | 467 | Adopt · invert · refuse; the buyer shift; **why Bet 1 requires a channel**; the recommended business model; the honest answer | Planning a quarter |
| [`IMPLEMENTATION_RECOMMENDATIONS.md`](./IMPLEMENTATION_RECOMMENDATIONS.md) | 439 | C-01…C-16, sequenced, each with why · benefits · tradeoffs · risks · scalability · performance · maintainability · complexity · effort (Fibonacci) · business impact · confidence · evidence | Turning this into backlog rows |

Two further deliverables sit **one level up**, because they synthesise across every research phase and
belong to none of them:

| File | What it is |
|---|---|
| [`../GLOBAL_GAP_ANALYSIS.md`](../GLOBAL_GAP_ANALYSIS.md) | Every platform studied in every phase, scored against a published rubric; gaps classified as *real* vs *bad idea*; weighted by whether QAYD can exploit them; the 2–3 places QAYD should dominate |
| [`../WORLD_CLASS_FEATURES.md`](../WORLD_CLASS_FEATURES.md) | Every feature discovered across all research, in 15 categories, each with a **Must-have / Should-have / Nice-to-have / Deliberately skip** verdict, effort and confidence |

---

## Evidence grading

Every non-obvious claim carries a tier. In this folder the tier distribution is worse than in the source
studies, and that is a property of the subject rather than of the effort.

| Tier | Meaning | How much to trust it |
|---|---|---|
| `[DOCS]` | Vendor's own published page, official press release, regulator publication, or SEC filing. URL cited. | High **for what the party asserts**. A pricing page is authoritative about price and about nothing else. A funding press release is authoritative about the round and about nothing else. |
| `[CODE]` | Read from source. **Almost entirely absent here** — every company in this folder is closed. | Highest, where available |
| `[COMMUNITY]` | Trade press, analyst notes, comparison sites, forums, competitor blogs. | Directional. Good for *that something happened*, poor for *how well it works*. **Comparison-site pricing is frequently stale or wrong.** |
| `[INFERENCE]` | A conclusion drawn here. The reasoning is always shown so it can be attacked. | Only as good as the argument |
| `[UNKNOWN]` | Could not verify. Left empty deliberately. | — |

### Three disciplines specific to this folder

**1 · Every market claim is dated.** Pricing, funding, ownership and AI-feature availability in this
category change monthly. A claim without a date is a defect. Where a fact was verified on 2026-07-28, it
says so; where it describes an event, the event's own date is given.

**2 · Competitor blogs are not evidence about competitors.** Several of the most convenient sources in
this space are one vendor's comparison page about another (`puzzle.io/blog/puzzle-vs-digits` is the
literal example). Those are marked `[COMMUNITY]` even when they read like documentation, and they are
never used for a claim that the rival is worse at something.

**3 · Funding is not traction, and valuation is not revenue.** A $1.15B valuation is evidence that
sophisticated investors believe something; it is not evidence that the product works. Every funding
figure in this folder is presented as what it is — a market signal about where capital thinks the value
is — and never as a capability claim.

---

## What was verified, and when

Facts obtained by web search on **2026-07-28** and used as load-bearing evidence:

| Fact | Tier | Date of the fact |
|---|---|---|
| Capital One completed its acquisition of Brex, $5.15B (≈$2.75B cash + 10.6M CoF shares) | `[DOCS]` capitalone.com newsroom + SEC 8-K; `[COMMUNITY]` CNBC | Announced 2026-01-22; **completed 2026-04-07** |
| Basis raised $100M Series B at $1.15B valuation, led by Accel with GV | `[DOCS]` businesswire; `[COMMUNITY]` Bloomberg, CPA Practice Advisor | 2026-02-24 |
| Numeric raised $51M Series B led by IVP; ~$89M total | `[DOCS]` numeric.io + PRNewswire | 2025-11 |
| Pennylane raised ~$204M / €175M at a reported $4.25B valuation, led by TCV + Blackstone Growth | `[COMMUNITY]` tech.eu, SiliconANGLE, EU-Startups | 2026-01-20 |
| Digits: ~$98M total raised; $65M Series C at $565M post (SoftBank-led) | `[COMMUNITY]` Crunchbase, CB Insights, Tracxn | Round 2022-03; totals as of 2026 |
| Digits shipped an "Autonomous General Ledger"; named a 2026 Top New Product by Accounting Today | `[COMMUNITY]` vendor press release via Yahoo Finance | AGL 2025-03; award 2026-02 |
| Truewind: firm-facing "digital staff accountant", human-in-the-loop, ~$17.5M raised, YC | `[COMMUNITY]` Crunchbase, Axios Pro | Series A 2025-01 |
| Ramp free core plan; paid tier ≈$15/user/mo; revenue primarily interchange | `[COMMUNITY]` multiple 2026 review sites — **vendor pricing page not fetched** | Reviewed 2026 |
| Brex free Essentials; Premium ≈$12/user/mo | `[COMMUNITY]` 2026 review sites | Reviewed 2026 |
| Intuit shipped named QBO agents (Accounting / Payments / Payroll), Essentials-and-up, GA and fees expected ≈late spring 2026 | `[COMMUNITY]` trade press | 2026 |
| Xero JAX launched 2025-09; chat free to subscribers, beta→standard terms 2026-06-01; M365 Copilot public preview from 2026-08 | `[COMMUNITY]` + vendor media release | 2025-09 → 2026-08 |
| ≈half of the 30 largest US accounting firms carry PE capital or an alternative practice structure; Thrive Holdings ≈$1B AI accounting roll-up; >$3B deployed to the strategy | `[COMMUNITY]` Forbes, CPA Trendlines, CFO Brew | early–mid 2026 |
| Qoyod ≈SAR 199/month single plan; Daftra reported from ≈USD 125/month; Wafeq four tiers incl. free, KWD pricing on its Kuwait page | `[COMMUNITY]`, plus `[DOCS]` for Wafeq's Kuwait page via `../accounting/OVERVIEW.md` §8.6 | 2026 |

Facts **deliberately not claimed** because they could not be verified in this pass are listed in
`OVERVIEW.md` §9. The most important are: Ramp's and Brex's own current pricing pages; any GCC revenue
or customer count for any player; and whether any AI-bookkeeping vendor's autonomy claims survive
contact with a real month-end.

---

## Headline findings

Six things this folder settles or changes. Each is argued in the document named.

**1 · The most dangerous competitor to a paid accounting product is a free one funded by a payment
rail — and QAYD is structurally immune in Kuwait, which is worth understanding before celebrating.**
Ramp and Brex give away spend management and earn on interchange `[COMMUNITY]`. That model needs card
issuing, which needs a bank partner and a scheme relationship in the market of operation. `../payments/`
established that Ramp and Brex are US-entity products and that no global issuer onboards Kuwaiti SMEs
`[DOCS]`. So the model cannot cross into Kuwait soon — but the *lesson* travels: whoever owns the
payment rail can zero-price the software above it. → `ANTI_PATTERNS.md` §2, `OVERVIEW.md` §3.

**2 · The 2026 capital flow says the value is moving to the accounting *firm*, not the SMB.** Basis
($100M at $1.15B, working with ~30% of the US Top 25 firms), Truewind (firm-facing), Pennylane (~4,500
firms, $4.25B), and a >$3B PE roll-up wave buying the firms themselves. The buyer with money and pain
is the practitioner. → `OVERVIEW.md` §4, `LESSONS_FOR_QAYD.md` §3.

**3 · Two opposite bets are being placed on the same thesis, and QAYD must consciously pick one.**
Digits is building a *new autonomous general ledger* (own the substrate). Basis and Truewind are
building *agents that operate the incumbent's ledger* (own the labour, rent the substrate). Both are
funded. They imply different architectures, different moats and different failure modes.
**QAYD has already picked "own the substrate" by building a ledger — this folder argues that it must
therefore also pick the firm as its buyer, or it gets the hardest version of both bets.**
→ `ARCHITECTURE.md` §2, `LESSONS_FOR_QAYD.md` §4.

**4 · Compliance deadlines are the only reliable forcing function in this category, and QAYD's home
market has none.** Pennylane's $4.25B is underwritten by France's September 2026 e-invoicing mandate
`[COMMUNITY]`; Zoho's Saudi position is underwritten by ZATCA `[DOCS, via ../accounting/]`. Kuwait has no
VAT and no e-invoicing on any announced timetable. This is the single most important asymmetry in
QAYD's commercial position and it is *unfavourable*. → `OVERVIEW.md` §6, `ANTI_PATTERNS.md` §13.

**5 · Nobody in any of the five categories has shipped a first-class, auditable machine-authorship
primitive inside the ledger.** Everyone has agents; the agents write into schemas designed for humans
typing. This is the same conclusion `06_COMPETITIVE_ANALYSIS.md` §4.4 reached from the architecture
side, and it survives contact with the 2026 AI cohort — **with one important qualification: Digits'
AGL is the closest thing to a direct challenge, and it is `[UNKNOWN]` how deep it goes.**
→ `OVERVIEW.md` §4.2, `LESSONS_FOR_QAYD.md` §5.

**6 · QAYD's defensible commercial position is narrower than its architecture suggests and wider than
its feature list suggests.** It cannot win on features, ecosystem, price-at-the-bottom, or compliance
depth in Saudi. It can win on *statement-to-posted-entry in a market with no bank feeds, sold through a
small reachable practitioner community, with provenance an auditor accepts.* Everything else is
enhancement. → `LESSONS_FOR_QAYD.md` §7.

---

## Scope

**In scope:** spend-management platforms; AI bookkeeping and AI accounting-agent startups; the AI
capabilities the accounting incumbents shipped in 2025–26; regional GCC vendors; business models,
pricing, packaging, GTM, distribution channels and moats across all five categories.

**Out of scope:** anything already covered by a sibling folder (see the table at the top); payroll-only,
tax-filing-only and practice-management-only products; audit software; treasury and FP&A platforms
except where they define a category boundary; any claim requiring source access to a closed product.

**Explicitly refused:** copying code, imitating architecture, reproducing UI, lifting pricing pages, or
recommending a business practice on the grounds that a competitor does it. Every recommendation here is
a *mechanism* with QAYD-native reasoning attached, and the ones that are rejected are rejected with the
mechanism of harm stated.

---

## Maintenance

**This folder ages faster than any other in the repository.** The architecture research pinned to a
commit is true forever; a pricing claim is true for a quarter.

- **Re-verify every `[DOCS]` price and every AI-availability claim before any decision depends on it.**
  The URLs are in `OVERVIEW.md`.
- **Ownership changes invalidate strategy, not just facts.** Brex is now inside Capital One
  `[DOCS, 2026-04-07]`; that changes what Brex will do next far more than it changes what Brex is.
- **`[UNKNOWN]`s are inventory.** §9 of `OVERVIEW.md` is the register. Close them with primary sources,
  date them, and promote the tier — never fill one from memory.
- **When a recommendation here is accepted it must become an ADR or a backlog row**, per MANIFEST Law 1.
  This folder cannot decide anything. The recommendations most likely to need one are **C-01** (the
  business-model decision) and **C-05** (firm tenancy), because both constrain the schema.

# End of Document
