# Best Practices — What This Category Gets Right, and Why It Works

**Scope:** QuickBooks Online · Xero · Zoho Books · FreshBooks · Wave · FreeAgent · KashFlow
**Companion to:** [`ANTI_PATTERNS.md`](./ANTI_PATTERNS.md) — several practices below have a failure mode
documented there. Read them as a pair.

---

## How to read this file

Admiration is worthless; **mechanism** is the deliverable. Each entry states the practice, the evidence
that it exists, **why it works** — the causal account, which is the part that transfers — and the
obligation it creates for QAYD.

A practice earns a place here only if it satisfies one of:

- It is **convergent** — independently arrived at by several of the seven, which is evidence about the
  problem rather than about any one team's taste.
- It is **singular and superior** — one product does something the others do not, and the reasoning for
  it survives scrutiny.

Practices that are merely *popular* are not included. Practices QAYD should refuse are in
`ANTI_PATTERNS.md` regardless of how well the category executes them.

---

# Part 1 — The core loop

The bank-feed → categorise → reconcile loop is the beating heart of this category. Everything else in
these products is periphery. It is examined here as a set of separable mechanisms, because QAYD must
reproduce the *mechanisms* in a market that does not supply the *feed*
(see [`OVERVIEW.md`](./OVERVIEW.md) §8 and [`ARCHITECTURE.md`](./ARCHITECTURE.md) §1.3).

## 1.1 A durable review queue between "the bank said" and "the books say"

**The practice.** Imported bank activity lands in a persistent work queue and becomes bookkeeping only
by an explicit act. Every product in the category has this surface: QuickBooks' *For Review* tab,
Xero's *Reconcile* tab, Zoho Books' *Uncategorized Transactions* tab `[DOCS]`
(https://www.zoho.com/us/books/help/banking/matching-transactions.html), and equivalents in the rest.

**Why it works.** Three distinct benefits, and they compound:

1. **It separates evidence from assertion.** The bank's record is external and not the business's to
   edit; the accounting treatment is the business's judgement and is revisable. Keeping them apart
   means a re-categorisation never destroys the evidence it was based on.
2. **It converts an unbounded task into a countable one.** "Do the bookkeeping" is a task with no edge.
   "Clear 42 items" has a beginning, a middle and an end. This is the single largest usability
   contribution the category has made, and it is psychological rather than technical.
3. **It creates the natural insertion point for automation.** Because the queue exists, rules and
   suggestions have somewhere to act, and their action is visible before it becomes permanent.

**Obligation for QAYD.** Build the queue as a **first-class durable object, not a staging table**.
Statement lines persist for the life of the company and remain the citation target for every AI
reconciliation proposal — which is what makes `07_QAYD_INNOVATION.md` I-12 (number provenance)
achievable for bank-derived entries rather than aspirational.

## 1.2 Match-before-create, with the residual derived

**The practice.** The queue offers two actions with different meanings: **match** a line to something
already in the books, or **create** a new transaction. Matching is auto-suggested — Zoho Books matches
on amount, date range, contact, reference and transaction type, tags the line **"Match found"**, and
paginates *Possible Matches* when ambiguous `[DOCS]`. Part-payments, splits, and many-to-many matching
are supported; adjustments (for example a payment-gateway fee split off a gross deposit) can be made
during matching `[DOCS]`.

**Why it works.** Matching *settles* an existing obligation rather than inventing a new fact. It is the
difference between "the invoice is now paid" and "there is some income", and it is what keeps
receivables and payables meaningful. The derived-residual model (see `ARCHITECTURE.md` §1.2) is what
makes it reversible without damage.

**Obligation for QAYD.** Matching is the *default* presented action whenever a plausible candidate
exists; creating a new entry requires rejecting the match. And because QAYD has an AI layer, the
suggestion should be **stated in words with its reason**, not merely highlighted — "this matches the
unpaid bill from Al-Rashed Trading dated 12 March, same amount, vendor name in the narrative" — which is
both better UX and the audit rationale the entry will carry forever.

## 1.3 Bulk coding as a first-class surface, not an advanced feature

**The practice.** The category provides a spreadsheet-like surface for coding many lines at once,
alongside the one-at-a-time surface. Zoho Books ships **Multi-select & Match** for bulk matching
`[DOCS]`; equivalent bulk-coding surfaces exist elsewhere in the category `[DOCS]`.

**Why it works.** The row-by-row path is designed for the owner with four transactions. The person who
determines whether the product is adopted is the **bookkeeper with four hundred**, and for them
per-line round trips are disqualifying. Providing both surfaces serves two populations without
compromising either.

**Obligation for QAYD.** Design the bank surface for the bookkeeper first and the owner second —
keyboard-driven, no modal per line, bulk select, bulk apply, batch undo. This is not a nicety: the
bookkeeper is the distribution channel (§4), and their throughput is what they recommend on.

## 1.4 Rules as a small, closed, ordered predicate language

**The practice.** Bank rules match on a narrow field set (narrative/payee text, amount, direction) with
`all`/`any` semantics and a handful of string operators, and act by setting account, contact, tax and
optional splits, with an explicit run order. Zoho Books exposes the same concept as **Transaction
Rules** `[DOCS]` (referenced in the matching guide; the dedicated rules documentation page 404s, so the
rule criteria, action set and any rule-count cap are `[UNKNOWN]`).

**Why it works.** Analysed in full in `ARCHITECTURE.md` §1.4. In short: explainable to a
non-accountant, safely evaluable in bulk, and conflict-resolvable because the order is total.

**Obligation for QAYD.** Adopt the *shape* and **invert the authorship**. The machine proposes the
predicate from observed behaviour; the human approves it once; the predicate remains inspectable data.
This is `07_QAYD_INNOVATION.md` I-16 delivered in the exact form the market has already validated —
which is the cheapest possible way to ship a genuinely novel capability.

## 1.5 Reconciliation as a period attestation with a balance check

**The practice.** Separate from clearing lines, the category provides a formal reconciliation against a
statement's closing balance, producing a dated attestation and surfacing any discrepancy. Zoho Books'
guidance is explicit that reconciliation runs *after* the queue is clear — *"No uncategorized
transactions should remain for the period you are closing"* `[DOCS]`.

**Why it works.** Clearing every line proves each line was *given a meaning*. Only the balance check
proves the set was **complete**. They are different guarantees and both are needed; a product with only
the first can be fully "done" and still be missing an entire page of transactions.

**Obligation for QAYD — and a genuine opportunity.** Because the GCC supplies statements rather than
feeds, QAYD's input is *natively* the stronger artefact: it has an opening balance, a closing balance
and a hard period boundary. Model the **statement as the primary object** and enforce
`opening + Σ lines = closing` **at import**, rejecting incomplete imports at the door. Feed-first
products cannot perform this check because they have no closing balance to check against. See
`ARCHITECTURE.md` §1.3 — this is one of the places where QAYD's constraint yields the better design.

## 1.6 Broad statement-format support as the honest fallback

**The practice.** Where feeds are unavailable, the category accepts a wide range of statement formats.
Zoho Books imports **CSV, TSV, XLS, OFX, QIF, CAMT.053, CAMT.054 and MT940**, plus PDF for a short list
of US banks only `[DOCS]`
(https://www.zoho.com/us/books/kb/banking/how-to-import-a-bank-statement.html).

**Why it works — and where it stops working.** Format breadth is genuinely valuable: camt.053 and MT940
are what GCC *corporate* banking portals export. But breadth of format is not the same as absence of
friction. UAE users report having to hand-edit ADCB and Emirates NBD CSVs to insert a header row
matching Zoho's expected field names before import succeeds `[COMMUNITY]`.

**Obligation for QAYD.** Support the standard formats, and then go one decisive step further:
**per-bank statement adapters that require zero manual editing** for the specific banks a Kuwaiti SME
uses. "Upload your NBK statement exactly as the bank gives it to you" is a small, unglamorous piece of
engineering with a disproportionate effect, because it converts the category's most-cited GCC friction
into a non-event. And note what it is *not*: it is not a feed, and it should not be marketed as one.

---

# Part 2 — Onboarding and time-to-value

## 2.1 Seed the chart of accounts; never present an empty one

**The practice.** A default chart of accounts ships and is editable; custom charts can be imported
`[DOCS]` (https://www.zoho.com/ae/books/help/accountant/chart-of-accounts.html).

**Why it works.** It removes the category's single largest onboarding wall (`ANTI_PATTERNS.md` §7) by
converting an authoring task into an editing task. People who cannot design a chart of accounts can
readily recognise that "Fuel" is missing from one.

**Obligation for QAYD.** Already specified in `MVP_SCOPE.md` (bilingual default CoA seeded at company
creation). The refinement this study adds is that **a genuinely GCC-shaped chart is a differentiator,
not a checkbox** — Zoho's GCC-specific chart templates could not be verified as a published artefact
and are probably generic `[UNKNOWN]`. A chart that already contains the accounts a Kuwaiti trading
company actually uses is a visible, first-five-minutes signal that the product was built for them.

## 2.2 Free, assisted migration from the incumbent

**The practice.** Zoho Books offers **free migration from QuickBooks or Xero** via a staffed email
address, plus a manual migration tool covering accounts, contacts, products, sales and purchase
transactions and **opening balances** `[DOCS]`
(https://www.zoho.com/us/books/migration.html).

**Why it works.** Switching cost, not product quality, is what protects an incumbent. Paying humans to
dismantle that cost is one of the highest-ROI activities available to a challenger, and it is the
cheapest at low volume — exactly the position a new entrant is in.

**Obligation for QAYD — and the specific opening it reveals.** Zoho's *Tally* migration path is
markedly worse than its QuickBooks/Xero path: no tool, just a documented manual export sequence, which
has produced a cottage industry of paid third-party Tally→Zoho migrators `[DOCS]`/`[COMMUNITY]`
(https://www.zoho.com/books/help/migration/tally-to-zoho-books.html). **Tally is the real incumbent in
a Kuwait SME deal** — the desktop, Indian-expat-bookkeeper standard `[COMMUNITY]` — not QuickBooks and
not Xero. A first-class, one-click Tally importer (and an Excel importer) is therefore worth more in
Kuwait than a QuickBooks importer, and nobody serving the region has built one well.

## 2.3 Model "the day you started" explicitly

**The practice.** The better products treat conversion as a modelled concept — a conversion date plus
conversion balances — rather than as a support exercise. Opening balances are explicitly in scope for
Zoho's migration tooling `[DOCS]`.

**Why it works.** Nobody adopts an accounting system on day one of a fiscal year
(`06_COMPETITIVE_ANALYSIS.md` §4.2). If the product has no concept of "before us", every migrated
business begins with a lie in the ledger.

**Obligation for QAYD.** A conversion date, a balancing set of opening balances, the prior system's
trial balance retained as the source document, and a permanent record of who asserted it — all under
the append-only ledger, which makes the assertion tamper-evident by construction. This is a case where
QAYD's architecture makes a routine feature *better than the category's* at no extra cost.

## 2.4 Document capture with its own inbound channel

**The practice.** Receipts and bills enter through OCR capture independent of the main UI. Zoho calls it
**Autoscan** and meters it — 50/month on Free, 200 on Standard and Professional, 1,000 on Premium and
above, with extra scans sold at roughly $8 per 50 `[DOCS]`.

**Why it works.** Capture happens at the moment of the transaction, away from a desk. Requiring a login
to file a receipt guarantees receipts go unfiled.

**Obligation for QAYD, and a packaging note.** QAYD's MVP already starts from a photographed or
forwarded bill — the correct spine. Two refinements from this evidence: (a) give documents a
**forward-to-QAYD email address**, which is a small piece of engineering with outsized behavioural
effect; (b) **do not meter capture as tightly as Zoho does.** A 200-scan monthly cap is a real
constraint for a document-heavy business and, more importantly, metering the *input* to an AI-first
product suppresses exactly the behaviour the product needs in order to be useful and to learn.

---

# Part 3 — Automation, AI, and compliance

## 3.1 Recurring everything, including journals

**The practice.** Recurring invoices, bills, expenses **and journals** `[DOCS]`.

**Why it works.** A material share of an SME's ledger is predictable — rent, salaries, subscriptions,
depreciation. Recurrence converts predictable work into no work, with no intelligence required.

**Obligation for QAYD.** Ship recurring entries early; they are cheap and they are the **deterministic
floor beneath the AI**. This matters strategically: `06_COMPETITIVE_ANALYSIS.md` §4.4 records SAP's
lesson that the rules engine outlived the ML layer built on top of it. Build the deterministic engine
first and let the model propose only what the rules could not settle.

## 3.2 Server-side extensibility — with a warning

**The practice.** Zoho Books supports **workflow rules** with email, field-update, webhook and
**custom function** actions, where functions are written in **Deluge, Node.js, Java, Python or Go**
`[DOCS]` (https://www.zoho.com/us/books/help/settings/automation/workflow-actions/functions.html). This
is materially more extensibility than Xero or QuickBooks offer.

**Why it is here at all.** The *capability* is real and the demand it serves is real: businesses have
idiosyncratic rules and want them enforced by the system.

**Why QAYD must nonetheless refuse it in this form.** User-authored code in the tenant's execution path
permanently freezes internal interfaces — the same mechanism that, per `06_COMPETITIVE_ANALYSIS.md`
§4.3, is why Dolibarr's core can never tighten an invariant. QAYD's entire advantage is enforcement in
the storage engine, which requires the freedom to keep tightening.

**Obligation for QAYD.** Deliver the *demand* through **declarative predicates plus webhooks**:
inspectable, statically analysable, safe to evaluate in bulk, and free of any compatibility commitment
to a scripting runtime. Same user outcome; no mortgage on the schema.

## 3.3 Metered, honest AI positioning — and the gap it leaves

**The practice, stated strictly.** In Zoho Books, the following are documented as available: **Ask Zia**
(conversational assistant that can create items and transactions and retrieve report data), **CoCreate
Agent** (natural language → sales documents), **report forecasting and anomaly detection**, **Zia
Invoice Agent** (overdue balances, payment-delay patterns, bulk reminders, risk flagging), **Zia
Insights**, email content generation, and a speech assistant `[DOCS]`
(https://www.zoho.com/us/books/help/ai-features/ai-features.html).

The following are **Early Access / opt-in, not generally available**: AI-powered custom fields,
**AI Bank Statement Categorization**, Blueprint generation, and the Zia summary dashboard `[DOCS]`.
Zia Agents / Agent Studio were announced in July 2025 and are ecosystem-wide, weighted toward CRM and
Desk rather than Books `[COMMUNITY]`.

**Why the distinction is the point.** Zoho's shipped Books AI is **retrospective and advisory** — it
describes data and drafts documents. **The one feature that actually removes labour — automatic bank
statement categorisation — is the one still behind an opt-in.** That is not a criticism of Zoho's
honesty; their documentation is admirably clear about which features require emailing support to
enable. It is an observation about where the category actually is.

**Obligation for QAYD.** Two things follow, and they are the most commercially important lines in this
file.

1. **The labour-removing AI capability is unclaimed in the GCC.** Not "poorly executed" — *not
   generally available*. QAYD's General Accountant draft loop and Treasury reconciliation loop target
   precisely this gap.
2. **Hold the same honesty standard.** State plainly what is GA, what is limited, and what is planned.
   In a category where every vendor is announcing agents, a product that ships fewer capabilities and
   describes them accurately is more credible to an accountant than one that does the reverse — and
   accountants are the channel.

## 3.4 Compliance built to the regulator's actual protocol, not to a report

**The practice.** Zoho Books states it is a **ZATCA-approved Phase 2 e-invoicing compliant solution**,
with Phase 1 generation supported since 4 December 2021 and Phase 2 pushing invoices to ZATCA's
**Fatoora** platform; QR codes on B2B and B2C invoices, IRNs for credit and debit notes, Arabic or
bilingual output, and restrictions preventing users from altering or deleting transactions `[DOCS]`
(https://www.zoho.com/sa/books/e-invoicing). It is gated at the **Standard** plan — roughly SAR 60/month
`[DOCS]`. Independent reporting corroborates the accreditation `[COMMUNITY]`.

The comparison point: FreeAgent files directly with the UK tax authority and maintains a forward-looking
tax timeline `[DOCS]`, whereas most of the category — including Zoho for UAE corporate tax — produces a
report the human then files elsewhere `[DOCS]`
(https://www.zoho.com/ae/books/corporate-tax/).

**Why it works.** Integrating with the regulator's actual protocol converts compliance from a task the
customer performs into a property the software has. It also creates the strongest lock-in in the
category: a business mid-way through a mandated filing regime does not switch vendors casually.

**Why this is the most transferable idea in the study.** It generalises past tax entirely. The
principle is: **model the obligation, not the report** — type, jurisdiction, period, due date, computed
amount, evidence, filing state, as a first-class object with a lifecycle.

**Obligation for QAYD, with an important caveat about Kuwait.** Build the obligation model *before*
there is an obligation to model, so each regime is an adapter rather than a re-architecture. But be
honest about the sequencing: **Kuwait has no VAT and no e-invoicing mandate** (`OVERVIEW.md` §8.1), so
there is no compliance moat available in Kuwait for anyone — including the incumbents. In Saudi the
moat exists and Zoho already occupies it at SAR 60/month, which is very hard to undercut. The
conclusion is uncomfortable and worth stating plainly: **compliance is not QAYD's wedge in its home
market**, and where it *is* a wedge (Saudi, and the UAE e-invoicing gap opening through 2027) the
incumbent is already there or arriving. See `LESSONS_FOR_QAYD.md` §4 and §7.

## 3.5 Data residency as a sales instrument

**The practice.** Zoho operates region-specific datacenters, including a **Saudi Arabia (`.sa`) domain
exposed in the Books API** `[DOCS]` (https://www.zoho.com/books/api/v3/introduction/), and regional
partners market PDPL-aligned local hosting as standard `[COMMUNITY]`.

**Why it works.** In the Gulf, data residency is asked about early and by people with authority to
veto. It is a procurement gate, not a technical preference.

**Obligation for QAYD.** Have an answer before it is asked, and know that "we can host in-region" is a
different and weaker claim than "we do". This is a **positioning and architecture decision to record
now**, not a feature to build now — but the cost of discovering it late is a lost enterprise pipeline.

---

# Part 4 — Channel, pricing and support

## 4.1 The accountant as the distribution channel

**The practice.** Zoho runs a Books partner programme for accountants: register, complete a **mandatory
training and certification programme**, become a Certified Partner; benefits include **free Zoho One
access**, a **partner store for managing client subscriptions**, a **partner directory listing**,
priority support and a dedicated partner account manager `[DOCS]`
(https://www.zoho.com/us/books/accountant/). Revenue-share percentages are not published `[UNKNOWN]`.
GCC go-to-market is **reseller-led rather than direct**, with certified partners across the UAE, Saudi,
Qatar, Oman, Bahrain and Kuwait, and Dubai functioning as the regional hub `[COMMUNITY]`.

**Why it works.** This is the single most important go-to-market fact in the category. One accountant
brings tens of clients, and the accountant's recommendation carries authority no marketing spend buys.
The mechanism is compounding: certification creates switching cost *for the accountant*, the client
console makes managing many clients efficient, and the directory listing sends leads back.

**Two exploitable weaknesses in the incumbent's execution.** Zoho has **no equivalent in depth** to a
full advisor-tier ladder and client console; accountants complain directly that adding a client on
Xero or QuickBooks is *"you type an email address and that's it"* while Zoho's flow is clunkier
`[COMMUNITY]`. And the GCC partner network is **thin in Kuwait relative to Dubai and Riyadh**
`[COMMUNITY]`.

**The mechanics worth copying, from the category's most developed programme.** QuickBooks' ProAdvisor
scheme `[DOCS]` (https://quickbooks.intuit.com/accountants/proadvisor/) is instructive for three specific
design choices rather than for its existence:

- **Accountant seats do not consume billable user seats** — two per client subscription, three on the
  top tier `[DOCS]`. Charging a business for its own accountant's access would be a tax on the
  distribution channel. Copy this exactly.
- **Tier status is earned mainly by putting clients on the platform** — 25–75 points per active client
  subscription versus 100–200 per certification, across Silver/Gold/Platinum/Elite bands `[DOCS]`. It is
  a distribution machine dressed as a loyalty programme, and it is honest about what it rewards.
- **Bank rules export and import between company files via Excel** `[DOCS]` — the mechanism firms use to
  templatise client onboarding. A small feature with a large channel effect: it lets one accountant
  encode their coding conventions once and apply them to every client. **QAYD should ship the
  equivalent for its predicate rules**, and it becomes far more powerful when the machine is proposing
  the predicates (§1.4).

**One mechanic to refuse.** When a QuickBooks subscription leaves firm billing, *"all future monthly
subscription charges will be transferred… at the then-current list price"* `[DOCS]` — **the client loses
the discount entirely on leaving the firm.** That is a retention lever aimed at the accountant's client,
and it makes the accountant the bearer of bad news. Reward the channel; do not weaponise it against the
customer.

**Obligation for QAYD.** Build for the accountant from the first release: a multi-client console,
frictionless client invitation (one email field), free accountant seats, bulk work surfaces, exportable
rule sets, and a certification path. **The Kuwaiti bookkeeping and audit community is small,
concentrated, and reachable in person** — which is a structural advantage for a local founder and a
structural disadvantage for a vendor selling through a Dubai reseller. See `LESSONS_FOR_QAYD.md` §6.

## 4.2 Gulf-appropriate support hours

**The practice.** Zoho's Books support runs **Sunday–Friday, 09:00–18:00**, with email, voice and chat
from the Standard plan `[DOCS]` (scraped from the Kuwait, Saudi and UAE pricing pages).

**Why it works.** It respects the Gulf working week. It is a small thing that signals the vendor has
actually thought about the region — and its absence signals the opposite loudly.

**Obligation for QAYD.** Gulf working week, Arabic-speaking, same-timezone, reachable by phone. Treat
it as a moat rather than a cost: it is one of the few advantages that cannot be copied in a release.

## 4.3 A free tier calibrated to graduate, not to satisfy

**The practice.** Zoho Books' Free plan is bounded by **revenue** (AED/SAR 200,000 in the localised
markets; **USD 50,000** in Kuwait, Bahrain, Oman and Qatar), one user plus one accountant, and 1,000
transactions a year `[DOCS]`. Wave's free Starter tier retains manual bookkeeping while automatic bank
import moved to the paid Pro tier in early 2024 `[DOCS]`/`[COMMUNITY]`.

**Why it works.** A revenue-bounded free tier is self-liquidating: the customer graduates by
succeeding, which makes the upgrade feel earned rather than extracted. Wave's choice of *which* feature
to paywall first is the clearest available evidence of where the value sits — it is the automation of
the core loop.

**Obligation for QAYD.** A free or very cheap entry tier bounded by **scale**, never by correctness
(`ANTI_PATTERNS.md` §16). The paid dimension should be **the automation of the loop and the autonomy of
the agents** — the same instinct Wave acted on, applied to a market where the bottleneck is statement
ingestion and matching rather than feed connectivity.

## 4.4 Price the region deliberately

**The practice.** Zoho charges the GCC materially less than the US at every tier above Standard —
roughly $30 vs $50 at Professional and $99 vs $150 at Elite — and prices the UAE and Saudi in local
currency `[DOCS]`.

**Why it works.** It is a deliberate share purchase in a market Zoho intends to hold.

**The tell worth exploiting.** Kuwait, Bahrain, Oman and Qatar are priced **in US dollars**, not local
currency `[DOCS]` (https://www.zoho.com/kw/books/pricing/). For a Kuwaiti SME that means an FX charge on
the card and no KWD invoice for their own books — a small, constant, visible irritation from an
accounting vendor, of all things. It is also a reliable signal that Kuwait is not a managed market for
them.

**Obligation for QAYD.** Price in KWD, invoice in KWD, and — given the 3-decimal issue documented in
`ANTI_PATTERNS.md` §1 — make sure QAYD's own invoice to the customer is arithmetically correct in KWD.
The first document a customer receives from an accounting vendor is a demonstration of the product's
competence.

---

# Part 5 — The practices worth stealing outright

Condensed for reference. Each is a cheap, high-yield practice with an identified mechanism.

| # | Practice | Mechanism that makes it work | Cost to QAYD |
|---|---|---|---|
| 1 | Durable review queue between evidence and assertion | Separates two things with different truth conditions; makes an unbounded task countable | Low — mostly a modelling decision |
| 2 | Match-before-create, residual derived from links | Settles obligations instead of inventing facts; reversible without damage | Low; forced anyway by append-only |
| 3 | Bulk coding surface | Serves the bookkeeper, who is the channel | Medium |
| 4 | Small closed ordered predicate language for rules | Explainable, safely bulk-evaluable, conflict-resolvable | Medium; enables I-16 |
| 5 | Statement-balance attestation as a distinct step | Proves *completeness*, which line-clearing cannot | Low — and stronger in QAYD's input format |
| 6 | Seeded, editable, region-shaped chart of accounts | Turns authoring into editing | Low; already specified |
| 7 | Free assisted migration, **Tally and Excel first** | Dismantles the switching cost that protects incumbents | Medium — highest-leverage GTM engineering |
| 8 | Conversion date + opening balances as modelled objects | Prevents every migrated business starting with a lie | Low; unblocked already |
| 9 | Document capture with its own inbound channel, generously metered | Capture happens away from the desk; feeds the AI | Low |
| 10 | Recurring entries including journals | Deterministic floor beneath the AI | Low |
| 11 | Obligation modelled, not just reported | Converts compliance from a task into a property | Medium; build the spine early |
| 12 | Accountant console + certification + one-field client invite | One accountant brings tens of clients | Medium; the highest-ROI channel work |
| 13 | Gulf working week, Arabic, phone-reachable support | Cannot be copied in a release | Low, ongoing |
| 14 | Region-priced, locally invoiced, in local currency | Removes a constant irritation the incumbent inflicts | Trivial |
| 15 | Strict honesty about GA vs preview AI | Credibility with accountants, who are the channel | Free |
