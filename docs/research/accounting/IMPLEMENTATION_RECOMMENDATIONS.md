# Implementation Recommendations

**Derived from:** [`LESSONS_FOR_QAYD.md`](./LESSONS_FOR_QAYD.md) · [`BEST_PRACTICES.md`](./BEST_PRACTICES.md) ·
[`ANTI_PATTERNS.md`](./ANTI_PATTERNS.md) · [`ARCHITECTURE.md`](./ARCHITECTURE.md) · [`OVERVIEW.md`](./OVERVIEW.md)
**Status:** recommendations only. No application code, schema, migration or test was created or
modified in producing this document.

---

## How to read this file

Every recommendation carries the same attribute block, so items can be compared and triaged without
reading the prose:

- **Why** — the problem it solves.
- **Benefits** · **Tradeoffs** · **Risks**.
- **Scalability · Performance · Maintainability · Complexity** — the four engineering axes.
- **Effort** — Fibonacci story points (1, 2, 3, 5, 8, 13, 21). Backend-weighted; UI noted separately
  where material.
- **Business impact** — High / Medium / Low, with the mechanism, not an adjective.
- **Confidence** — High / Medium / Low, in the *recommendation*, with the reason.
- **Evidence** — the grade and pointer.

**Sequencing principle.** Ordered by the dependency graph and by the strategic argument in
`LESSONS_FOR_QAYD.md` §7, not by ease. Wave 0 items are cheap decisions that become expensive if
deferred — they are first because *delay is their only real cost*.

**Alignment note.** Several items adjust `docs/execution/MVP_SCOPE.md` and the Sprint 2–4 plans. Those
adjustments are argued, not assumed, and are collected in §9 so the roadmap owner can accept or reject
them as a set.

---

# Wave 0 — Decisions that get expensive if deferred

Four items. Total effort **10 points**. Every one of them costs a fraction of a sprint now and a
migration later.

## R-01 · Make currency precision a per-currency property, not a global constant

**Why.** KWD has three decimal places `[DOCS]`. Intuit's own community response to a Kuwaiti user is
that QuickBooks Online rounds to two and offers no three-decimal display `[COMMUNITY]`. QAYD's base
amounts are already exact decimals rather than floats — the remaining decision is whether the *exponent*
is per-currency data or an assumption. Today there is one currency, so the change is free. After a
second currency, an API contract and an integrator exist, it is not.

**Benefits.** Correctness in fils; a provable, demonstrable advantage over at least one market leader;
removes a whole class of future rounding defects across tax, FX, reconciliation and reporting.
**Tradeoffs.** A small amount of ceremony everywhere money is validated, rounded, formatted or parsed.
**Risks.** Low. The main risk is doing it *partially* — display-only precision while arithmetic still
rounds to two, which is the failure mode `ANTI_PATTERNS.md` §1 describes and is worse than not doing it,
because it looks correct.
**Scalability** — neutral. **Performance** — neutral. **Maintainability** — improves; the rule lives in
one place. **Complexity** — low now, high later.
**Effort — 3.**
**Business impact — High.** It is a named, checkable claim in a sales conversation in Kuwait, and its
absence is a defect a prospect can reproduce in a competitor's product in thirty seconds.
**Confidence — High.** The requirement is objective; the cost is minimal; the alternative is a
migration.
**Evidence** — `[DOCS]` ISO 4217 / KWD; `[COMMUNITY]` Intuit community thread. See `ANTI_PATTERNS.md` §1.

## R-02 · Remove `journal_lines.reconciled`; reconciliation state lives in side tables

**Why.** The boolean is the exact mechanism by which the category's undo-reconcile silently rewrites an
attestation (`ANTI_PATTERNS.md` §3). `06_COMPETITIVE_ANALYSIS.md` §4.1 already named it as the specific
way QAYD's ledger advantage could be lost. It is currently unused; removing it costs a migration and
nothing else.

**Benefits.** Preserves the append-only guarantee at the layer above the ledger; forces the better
design (matching links, compensating inserts, rebuildable matching groups).
**Tradeoffs.** Reconciliation queries join rather than filter a column.
**Risks.** Low now; the risk is entirely in *not* doing it before the banking module is written, at
which point the column will be load-bearing.
**Scalability** — neutral to positive; side tables index independently of the ledger's hot path.
**Performance** — a join instead of a predicate; immaterial at SME volumes, and mitigated by indexing.
**Maintainability** — significantly better. **Complexity** — low.
**Effort — 2.**
**Business impact — Medium**, entirely through credibility: the audit story is the reason a
sceptical accountant trusts an AI-posted ledger, and a mutable reconciliation flag undermines it.
**Confidence — High.** Two independent prior analyses reached the same conclusion.
**Evidence** — `[CODE]` the column exists; `06_COMPETITIVE_ANALYSIS.md` §4.1; `ARCHITECTURE.md` §1.2.

## R-03 · Decide exchange-rate provenance now, build multi-currency later

**Why.** Multi-currency is cleanly deferrable — the category's own packaging proves it is a separable
layer (`ARCHITECTURE.md` §1.6), and QAYD's ledger rows already carry base amounts as the authority.
What is *not* deferrable is **where the rate lives, what it is a rate for, and how its source is
recorded**, because retrofitting rate provenance onto historical rows is the expensive part.

**Benefits.** Keeps the MVP single-currency without foreclosing the correct multi-currency design.
**Tradeoffs.** A design artefact with no shipped feature behind it.
**Risks.** The decision drifts and is made implicitly by whoever writes the first FX code.
**Scalability / Performance** — n/a. **Maintainability** — high value. **Complexity** — low.
**Effort — 2** (an ADR, not code).
**Business impact — Medium.** Kuwaiti trading SMEs routinely hold USD and AED; the first paying cohort
will ask.
**Confidence — High.**
**Evidence** — `[DOCS]` multi-currency gated to higher tiers across the category; `ARCHITECTURE.md` §1.6.

## R-04 · Adopt a strict AI claims standard, in writing

**Why.** The category's audience is accountants, and accountants are unforgiving about overclaiming.
Zoho's own documentation is admirably explicit about which AI features require emailing support to
enable `[DOCS]` — and the most valuable one, bank statement categorisation, is among them. QAYD's
marketing must hold the same line, and specifically must never describe statement ingestion as a "bank
feed" (`LESSONS_FOR_QAYD.md` §9.3).

**Benefits.** Protects the channel strategy, which depends entirely on professional credibility.
**Tradeoffs.** Less impressive copy.
**Risks.** None material.
**Effort — 1.**
**Business impact — High**, asymmetrically: it prevents a category of damage rather than creating value.
**Confidence — High.**
**Evidence** — `[DOCS]` Zoho AI features page GA/Early-Access split; `BEST_PRACTICES.md` §3.3.

---

# Wave 1 — The wedge

This is the strategic core. Everything here serves the single defensible claim in
`LESSONS_FOR_QAYD.md` §7.2: *a Kuwaiti bank statement, exactly as the bank gives it to you, becomes
posted, correctly-coded, cited entries.* Total effort **60 points**.

## R-05 · Model the bank **statement** as the primary object, with a balance-closure invariant

**Why.** The category models the *line* as primary because a feed emits lines. QAYD's input is a
statement — opening balance, closing balance, period. Modelling it as primary yields a control that
feed-first products structurally cannot have: **`opening + Σ lines = closing`, enforced at import.**
That single check rejects a truncated CSV or a missing PDF page before a single entry is posted.

**Design shape.** `bank_statements` (company, bank account, period start/end, opening balance, closing
balance, source document reference, import state) owning `bank_statement_lines` (value date, narrative,
amount, direction, running balance where provided, raw payload). Statement lines are **durable
evidence**, retained for the life of the company, never truncated — they are the citation target for
every reconciliation proposal (`ARCHITECTURE.md` §1.1).

**Benefits.** Catches incomplete imports at the door; produces the reconciliation attestation almost for
free; gives every AI matching decision a permanent, dereferenceable citation, which is what makes
`07_QAYD_INNOVATION.md` I-12 real for bank-derived entries rather than aspirational.
**Tradeoffs.** Rejecting imports that do not close will occasionally frustrate a user whose bank omits
a balance — needs a documented, audited override.
**Risks.** Some statement sources genuinely lack an opening or closing balance; the model must degrade
to "unverified import" rather than refuse outright.
**Scalability** — good; statements partition naturally by account and period.
**Performance** — good; the closure check is a single aggregate at import.
**Maintainability** — high; the invariant is declarative.
**Complexity** — medium.
**Effort — 8.**
**Business impact — High.** It is the foundation of the wedge and a genuine differentiator that can be
demonstrated in one screen.
**Confidence — High.** The reasoning is structural and does not depend on any competitor's behaviour.
**Evidence** — `[INFERENCE]` from the category's two reconciliation concepts, `ARCHITECTURE.md` §1.3;
`[DOCS]` Zoho's format support and its reconciliation-after-clearing guidance.

## R-06 · Per-bank statement adapters for the major Kuwaiti banks — zero manual editing

**Why.** This is the unglamorous work that constitutes the moat. UAE users report hand-editing bank CSV
headers to match expected field names before import succeeds `[COMMUNITY]`. No product in this study
publishes GCC bank coverage; Zoho's KB article titled *"Find if my bank supports automatic feeds"*
contains no list and directs the user to email support `[DOCS]`. **"Upload your NBK statement exactly as
the bank gives it to you"** is a claim nobody else can make.

**Design shape.** An adapter interface behind a stable port: input (file, declared bank, account) →
output (normalised statement + lines + confidence + unparsed residue). Standard formats
(CSV/OFX/QIF/MT940/camt.053) as generic adapters; **per-bank PDF and CSV adapters** for the top Kuwaiti
banks as specific ones; AI extraction as the fallback for the unrecognised. Keeping this behind a port
means the eventual switch to a licensed open-banking AISP — if and when CBK finalises its framework
`[DOCS]` — is one adapter, not a rewrite (`ARCHITECTURE.md` §2.2).

**Benefits.** Directly removes the category's most-cited GCC friction; compounding, because every bank
added widens the moat; and the adapter port future-proofs the eventual feed transition.
**Tradeoffs.** Grinding, low-status, no demo value, and it must be maintained as banks change formats.
**Risks.** **The principal risk in this plan.** Bank statement formats change without notice; a
regression silently corrupts an import. Mitigation: golden-file tests per bank per format version, plus
the R-05 closure invariant as the runtime backstop — a format change that breaks parsing will almost
always break closure, which fails loudly instead of silently.
**Scalability** — good; adapters are independent. **Performance** — parsing is offline and per-file.
**Maintainability** — the weak point; needs a fixture regime from day one.
**Complexity** — medium per adapter, low in aggregate.
**Effort — 13** (approximately 3–5 per bank after the first two; the first two carry the framework).
**Business impact — High.** This is the wedge.
**Confidence — Medium-High.** High that the gap exists; medium on effort, because Kuwaiti bank
statement format variability was not sampled in this study `[UNKNOWN]`. **Validate by collecting real
statements from the top five banks before committing the estimate.**
**Evidence** — `[DOCS]` Zoho's feed providers (Yodlee/Plaid US-CA/Token UK-EU, no GCC aggregator) and
its non-existent bank list; `[COMMUNITY]` UAE CSV header editing; `[DOCS]` CBK draft framework.

## R-07 · AI categorisation and matching that produces approvable proposals

**Why.** Without this, R-05 and R-06 amount to a good importer. **The labour-removing capability is
unclaimed in this category** — Zoho's AI Bank Statement Categorization is documented as Early Access,
opt-in by emailing support `[DOCS]`. Its shipped AI is retrospective and advisory. This is the gap the
whole product thesis targets.

**Design shape.** Deterministic first, model second — the ordering discipline `06_COMPETITIVE_ANALYSIS.md`
§4.4 draws from SAP's experience. Exact matching (amount + date + reference) → rule predicates → fuzzy
matching → model proposal. Every proposal carries confidence, rationale in the user's language, and a
citation to the statement line. Auto-post only under the confidence-and-amount threshold the MVP
already specifies; everything else is a one-click approval, with **bulk approve** as a first-class
action.
**Benefits.** The core value proposition; directly serves the MVP's Treasury reconciliation loop.
**Tradeoffs.** Cost per document; latency; and a permanent evaluation burden.
**Risks.** Over-confident proposals that get bulk-approved without scrutiny — which is the category's
silent-rules failure (`ANTI_PATTERNS.md` §6) wearing an AI costume. Mitigation: proposals are recorded
as decisions with rationale; a periodic "here is what automation did this month, grouped by pattern"
review surface; and confidence calibration monitored as a first-class metric, not a launch check.
**Scalability** — good; per-line and parallelisable. **Performance** — batch, not interactive.
**Maintainability** — needs a labelled evaluation set from the first pilot tenant onward.
**Complexity** — high.
**Effort — 21.**
**Business impact — High.** This is what the customer is actually buying.
**Confidence — Medium.** High that the gap is open; medium on QAYD's execution, because match quality on
Arabic vendor names and Kuwaiti bank narratives is untested `[UNKNOWN]`.
**Evidence** — `[DOCS]` Zoho AI features page; `[DOCS]` MVP AI contract; `06_COMPETITIVE_ANALYSIS.md` §4.4.

## R-08 · Reconciliation as an event, with a period attestation

**Why.** Clearing every line proves each was *given a meaning*; only the balance check proves the set was
**complete**. Zoho's own guidance runs reconciliation after the queue is clear `[DOCS]`. Under QAYD's
append-only ledger the attestation must be an **event that can be superseded but never dissolved**
(`ANTI_PATTERNS.md` §3).

**Benefits.** Table stakes met, in a form stronger than the category's; and it makes period close
meaningful.
**Tradeoffs.** Users accustomed to a silent "undo" will need the supersession model explained.
**Risks.** Low.
**Scalability / Performance** — good. **Maintainability** — high. **Complexity** — low-medium, given R-05.
**Effort — 5.**
**Business impact — High.** No accountant adopts a system that cannot reconcile.
**Confidence — High.**
**Evidence** — `[DOCS]` Zoho reconciliation guidance; `06_COMPETITIVE_ANALYSIS.md` §4.2.

## R-09 · Bulk work surfaces, keyboard-first

**Why.** The row-by-row path serves the owner with four transactions; the **bookkeeper with four
hundred** decides adoption, and is the distribution channel (§R-13). The category ships bulk-coding and
multi-select matching as first-class surfaces `[DOCS]`.

**Benefits.** Throughput, which is what practitioners recommend on.
**Tradeoffs.** Bulk actions need a batch-level undo, which under an append-only ledger means
compensating inserts rather than deletes — more design work than it appears.
**Risks.** Bulk mistakes are bulk-sized. Mitigate with preview-then-apply, matching R-11's pattern.
**Scalability** — needs pagination and set-based operations rather than per-row round trips.
**Performance** — the main UI performance requirement in the product.
**Maintainability** — medium. **Complexity** — medium.
**Effort — 8** (frontend-weighted).
**Business impact — High**, through the channel.
**Confidence — High.**
**Evidence** — `[DOCS]` category bulk surfaces; `BEST_PRACTICES.md` §1.3.

## R-10 · Rule predicates as data — proposed by the machine, approved by the human

**Why.** The category converged on a small, closed, ordered predicate language, and that convergence is
evidence about the problem (`ARCHITECTURE.md` §1.4). QAYD's contribution is to **invert the authorship**:
the agent notices that the last nine `TAP*` lines were coded identically and proposes the rule; the
human approves once. This is `07_QAYD_INNOVATION.md` I-16 delivered inside a market-validated container.

**Design shape.** CHECK-constrained JSONB selector compiled through an allowlist — never `eval`.
Total ordering. Scope observed rather than assumed: if a predicate begins matching lines whose shape
differs from those it was written against, it raises rather than fires.
**Benefits.** Novel capability at low marginal cost once the predicate language exists; strictly safer
than the category's auto-apply rules because every application is recorded with its rationale.
**Tradeoffs.** A proposal queue is another surface to design.
**Risks.** Predicate drift (the rule was right; the world changed). Mitigated by the observed-scope check.
**Scalability / Performance** — good; predicates are bulk-evaluable by construction.
**Maintainability** — high; predicates are inspectable and statically analysable.
**Complexity** — medium.
**Effort — 8.**
**Business impact — Medium-High.** A demonstrable capability no competitor ships.
**Confidence — Medium-High.**
**Evidence** — `[DOCS]` category rule shapes; `07_QAYD_INNOVATION.md` I-16.

---

# Wave 2 — Adoption

Removes the barriers between a prospect and a working set of books. Total effort **34 points**.

## R-11 · Migration: Tally and Excel first, with conversion date and opening balances

**Why.** **Tally is the real incumbent in a Kuwait SME deal** `[COMMUNITY]` — not QuickBooks, not Xero.
Zoho offers free assisted migration from QuickBooks and Xero `[DOCS]` but its Tally path is a manual
export sequence with no tool, which has produced a paid third-party migration industry
`[DOCS]`/`[COMMUNITY]`. Switching cost — not product quality — is what protects an incumbent.

**Design shape.** Conversion date + a balancing set of opening balances + the prior system's trial
balance retained as the source document + a permanent record of who asserted it. Import is a **two-phase
transaction with a visible plan**: parse, validate, show exactly what would happen in the user's
language, require approval, then apply atomically. Under an append-only ledger a bad import cannot be
deleted — **the preview *is* the undo**, which makes the dry run mandatory rather than merely good
(`ANTI_PATTERNS.md` §9).

**Benefits.** Directly attacks the only thing protecting the incumbent; and it is the highest-leverage
go-to-market engineering available.
**Tradeoffs.** Tally export variability is real and partly manual at the source.
**Risks.** Migration quality determines first impressions absolutely. A bad import is an unrecoverable
first meeting.
**Scalability** — batch. **Performance** — offline. **Maintainability** — medium.
**Complexity** — medium-high, mostly in data variance.
**Effort — 13.**
**Business impact — High.** In a market where every prospect already runs *something*, migration **is**
the go-to-market.
**Confidence — Medium.** High on the strategic case; medium on effort, because Tally export shapes were
not sampled `[UNKNOWN]`.
**Evidence** — `[COMMUNITY]` Tally as Gulf incumbent; `[DOCS]` Zoho's Tally migration guide;
`[DOCS]` Zoho free migration offer.

## R-12 · Arabic-first, verified against a checklist — not "the strings are translated"

**Why.** **Zoho's GCC help documentation is English-only for every GCC edition** `[DOCS]`, and Arabic
invoice PDFs require manually setting an RTL font `[COMMUNITY]`. Arabic UI is not an Arabic product. The
gap is real, and it is unclaimed even by the most GCC-present incumbent.

**The checklist to verify against** (`ANTI_PATTERNS.md` §20): per-currency precision; RTL layout
*mirroring*, not just text direction; numerals held LTR inside RTL text; bilingual documents where a
regulator requires them; **Arabic PDF rendering with correct shaping and ligatures**; Arabic-aware
sorting and search; Arabic legal entity names as first-class data — not a display name bolted onto an
English record.

**Benefits.** A first-five-minutes signal that the product was built for this market; and it reaches the
non-English-speaking Kuwaiti bookkeeper, who is currently served by nobody.
**Tradeoffs.** Every new surface carries an RTL cost forever.
**Risks.** **It degrades silently.** RTL correctness must be enforced at review time or it rots.
**Scalability / Performance** — neutral. **Maintainability** — needs a standing check.
**Complexity** — medium, concentrated in PDF output.
**Effort — 8** (PDF shaping is the bulk of it).
**Business impact — High**, and it is one of the few advantages a local entrant holds structurally.
**Confidence — High** on the gap; **Medium** on effort, since Arabic PDF pipelines are reliably harder
than estimated.
**Evidence** — `[DOCS]` Zoho GCC editions all English; `[COMMUNITY]` Arabic PDF font workaround.

## R-13 · Accountant multi-client console in the first release

**Why.** Professionals distribute this category. Zoho runs a certified partner programme with mandatory
training, a client-subscription console and a partner directory `[DOCS]`, and its GCC motion is
reseller-led with Dubai as the hub `[COMMUNITY]`. Two openings: accountants complain that adding a
client on Xero or QuickBooks is *"you type an email address and that's it"* while Zoho's flow is
clunkier `[COMMUNITY]`; and Zoho's channel is thin in Kuwait relative to Dubai and Riyadh `[COMMUNITY]`.

**Design shape.** One console listing all client companies with work-due indicators; **one-field client
invitation**; cross-client bulk work; a certification path. Two mechanics to copy from the category's
most developed programme `[DOCS]`: **accountant seats must not consume the client's billable users**
(QuickBooks gives two or three free), and **rule sets must be exportable and importable between client
files**, so one accountant encodes their coding conventions once and applies them everywhere — far more
powerful when the machine proposes the predicates (R-10). One mechanic to refuse: QuickBooks reverts a
client to full list price when the subscription leaves firm billing `[DOCS]`, which makes the accountant
the bearer of bad news. Reward the channel; never weaponise it against the customer.

The owner is the user; the accountant is the buyer and the distributor.
**Benefits.** The highest-ROI channel investment available; one accountant brings tens of clients.
**Tradeoffs.** Cross-tenant UI over a strict RLS boundary needs careful design — the console must
aggregate without ever weakening tenant isolation.
**Risks.** **Tenant-isolation regression is the serious one.** A cross-client view is exactly the feature
that tempts a policy bypass. It must be built as many scoped sessions, never as an elevated one.
**Scalability** — fine. **Performance** — needs care on the aggregate view.
**Maintainability** — medium. **Complexity** — medium-high, entirely because of the isolation constraint.
**Effort — 13.**
**Business impact — High.** This is the distribution strategy.
**Confidence — High** on the need; **Medium** on Kuwait channel sizing, which is `[UNKNOWN]`.
**Evidence** — `[DOCS]` Zoho accountant programme; `[COMMUNITY]` accountant onboarding complaints;
`LESSONS_FOR_QAYD.md` §6.

---

# Wave 3 — Durability

Total effort **26 points**.

## R-14 · An honest completeness signal, separate from "queue empty"

**Why.** Bank-feed-only bookkeeping produces confident, incomplete books — practitioner guidance is
explicit that relying solely on the banking feature "can result in significant errors" `[COMMUNITY]`.
The category's progress signal rewards clearing the queue, which is precisely when risk is highest
(`ANTI_PATTERNS.md` §2).

**Design shape.** Measure what is *missing*: unentered documents in the inbox, expected recurring
entries not posted, no depreciation this period, a supplier who bills monthly and has not this month,
accounts with no activity that normally have activity. Feed it into close-readiness.
**Benefits.** Real work an agent can do that no rules engine in the category does; it is the most
legible demonstration that QAYD's AI is doing accounting rather than classification.
**Tradeoffs.** False alarms erode trust quickly — the signal must be conservative.
**Risks.** Nagging. Tune for precision over recall initially.
**Scalability / Performance** — batch. **Maintainability** — medium. **Complexity** — medium.
**Effort — 8.**
**Business impact — Medium-High**; a differentiated capability and a genuine safety improvement.
**Confidence — Medium-High.**
**Evidence** — `[COMMUNITY]` bank-feed-only warning; `MVP_SCOPE.md` close-readiness.

## R-15 · The obligation model — built before there is an obligation

**Why.** FreeAgent files directly with the tax authority and maintains a forward-looking tax timeline
`[DOCS]`; most of the category, including Zoho for UAE corporate tax, produces a report the human files
elsewhere `[DOCS]`. The generalisation — **model the obligation, not the report** — is the most
transferable idea in the study.

**Sequenced honestly.** Kuwait has no VAT and no SME e-invoicing mandate, and the government has ruled
VAT out for the near term `[COMMUNITY]`; the DMTT applies only above €750m consolidated revenue
`[DOCS]`. **There is therefore no Kuwait compliance wedge for anyone.** Build the spine — obligation
type, jurisdiction, period, due date, computed amount, evidence, filing state, lifecycle — because it
makes each future regime an adapter. Do **not** build a filing engine yet.

**Benefits.** Later compliance work becomes additive rather than structural; and it is the precondition
for any credible Saudi entry.
**Tradeoffs.** Building a spine with nothing on it.
**Risks.** Over-building for a speculative future regime. Keep it a data model, not a workflow engine.
**Scalability / Performance** — n/a. **Maintainability** — high value. **Complexity** — low-medium.
**Effort — 5.**
**Business impact — Medium** now, **High** at Saudi entry.
**Confidence — Medium-High.**
**Evidence** — `[DOCS]` FreeAgent direct filing; `[DOCS]` Zoho UAE CT "file on the FTA portal";
`[DOCS]` Kuwait DMTT scope; `[COMMUNITY]` Kuwait VAT deferral.

## R-16 · Document capture with its own inbound channel, generously metered

**Why.** Capture happens away from a desk; requiring a login guarantees receipts go unfiled. Zoho meters
Autoscan at 50/200/1,000 per month by tier with overage sold per 50 `[DOCS]` — a real constraint for a
document-heavy business, and a strategically odd one for an AI product, because **metering the input
suppresses the behaviour the product needs in order to be useful and to learn.**

**Benefits.** More documents in means better books and a better learning signal.
**Tradeoffs.** Direct inference cost, and an inbound email channel is an abuse surface.
**Risks.** Cost runaway. Bound it with per-tenant rate limits and anomaly alerts rather than a low hard
cap; the cap is a pricing decision, not a product one.
**Scalability** — good. **Performance** — asynchronous. **Maintainability** — medium.
**Complexity** — low-medium.
**Effort — 5.**
**Business impact — Medium-High.**
**Confidence — High.**
**Evidence** — `[DOCS]` Zoho Autoscan metering; `BEST_PRACTICES.md` §2.4.

## R-17 · Data portability as a stated, tested, demonstrated property

**Why.** Intuit stopped accepting new QuickBooks signups from India in July 2022 and ended the product
there in April 2023 `[DOCS]`; IRIS KashFlow Payroll reached end of life in April 2026 `[DOCS]`. The
market has learned that accounting systems disappear on a vendor's schedule — and a pre-launch startup
is a *worse* continuity risk than Intuit. The answer is a credible export story, offered before the
customer asks.

**Benefits.** Neutralises the strongest objection a new entrant faces; and it is nearly free given an
append-only ledger, where a complete, ordered, verifiable export is a natural read.
**Tradeoffs.** It genuinely lowers switching costs out. That is the point — a product retaining
customers through captivity is not the product being built here.
**Risks.** None material.
**Scalability / Performance** — batch. **Maintainability** — low burden.
**Complexity** — low.
**Effort — 5.**
**Business impact — Medium-High**, concentrated in the sales conversation.
**Confidence — High.**
**Evidence** — `[DOCS]` Intuit India discontinuation; `[DOCS]` KashFlow Payroll EOL.

---

# Wave 4 — Deliberately deferred

Named so they are decisions rather than omissions.

| Item | Defer until | Why |
|---|---|---|
| Multi-currency implementation | First paying cohort with FX exposure | Separable layer; but R-03 decides provenance now |
| VAT / e-invoicing engine | Saudi or UAE entry | No Kuwait obligation exists; ZATCA is a market-entry precondition, not a Kuwait feature |
| Sales invoicing, inventory, payroll | After the wedge is proven | Each is real; none makes the first cohort switch. The invoicing pivot is failure mode §9.2 in `LESSONS_FOR_QAYD.md` |
| App marketplace / public API programme | After product-market fit | An ecosystem is a commitment never to fix your foundations (`06_COMPETITIVE_ANALYSIS.md` §4.3) |
| User-authored server-side functions | Never in this form | Permanently freezes internal interfaces; deliver the demand as declarative predicates + webhooks |
| Conversational assistant | After the proposal queue works | The proposal primitive is the durable advantage; the chat box is commoditising |
| Live open-banking feeds | CBK licences providers | Not available. R-06's adapter port makes this a one-adapter change when it becomes available |

---

# §9 · Changes this study proposes to the existing plan

Collected so the roadmap owner can accept or reject them as a set. Each is argued above.

| # | Current plan | Proposed change | Rationale |
|---|---|---|---|
| 1 | Bank feeds deferred to Phase 2 | **Re-scope, not defer.** The MVP item is *statement ingestion with per-bank adapters* (R-05, R-06) | There are no Kuwaiti feeds to defer *to*; statement ingestion is the wedge |
| 2 | Accountant tooling not in MVP | **Move a multi-client console into the first release** (R-13) | The accountant is the distribution channel in this category |
| 3 | `journal_lines.reconciled` present | **Remove** (R-02) | It is the mechanism by which the category's undo-reconcile rewrites attestations |
| 4 | Currency precision implicit | **Per-currency exponent in data** (R-01) | KWD is 3-decimal; free now, a migration later |
| 5 | Bilingual AR/EN specified | **Verify against the localisation checklist, especially PDF output** (R-12) | "Translated strings" is not an Arabic product; and the gap is unclaimed |
| 6 | Migration unspecified | **Tally and Excel first, with a mandatory dry run** (R-11) | Tally is the real incumbent; append-only makes preview the only undo |
| 7 | Tax tracked, filing deferred | **Add the obligation model, not the filing engine** (R-15) | Cheap spine; makes Saudi entry additive |

---

# §10 · Summary

| Wave | Items | Effort | What it buys |
|---|---|---|---|
| **0 — Cheap now, expensive later** | R-01 … R-04 | **10** | Currency correctness, ledger integrity, FX optionality, credibility |
| **1 — The wedge** | R-05 … R-10 | **60** | The one defensible claim: statement in, cited posted entries out |
| **2 — Adoption** | R-11 … R-13 | **34** | Migration, Arabic-first, the accountant channel |
| **3 — Durability** | R-14 … R-17 | **26** | Honest completeness, obligation spine, capture, portability |
| **Total** | 17 items | **130** | |

**If only three things are done:** R-01 (3 points, permanent correctness advantage), R-06 (13 points,
the moat), R-07 (21 points, the reason anyone buys). Thirty-seven points is the smallest credible
version of the strategy in `LESSONS_FOR_QAYD.md` §7.4.

**The order that matters most:** R-05 precedes R-06 precedes R-07. The statement model makes the
adapters checkable; the adapters make the AI's input clean; the AI is what the customer is paying for.
Doing them in any other order produces a demo instead of a product.
