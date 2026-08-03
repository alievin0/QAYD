# SME Accounting Products — Research Index

**Domain:** `docs/research/accounting/`
**Subjects:** QuickBooks Online · Xero · Zoho Books · FreshBooks · Wave · FreeAgent · KashFlow
**Status:** Phase 3 of the QAYD engineering research programme. Documentation only — no application
code, schema, migration, or test was created or modified in producing these files.
**Last updated:** 2026-07-28

---

## Why this category, and why it matters more than the ERP category

The ERP study (`docs/architecture/knowledge/06_COMPETITIVE_ANALYSIS.md`) compared QAYD against Odoo,
ERPNext, SAP S/4HANA, NetSuite, Dynamics 365, Akaunting and Dolibarr. That comparison answers *what to
build*: fiscal periods, statements, reconciliation, tax, dimensions.

This study answers a different and harder question: **what it must feel like.**

The seven products here are what QAYD's actual customers — Kuwaiti and GCC SMEs and the bookkeepers
who serve them — use today, or will be shown by a reseller next quarter. Nobody in that segment
evaluates a chart-of-accounts hierarchy or an append-only projection. They evaluate whether a bank
transaction can be turned into a correctly-coded ledger entry in under three seconds, and whether the
month closes without a spreadsheet.

QuickBooks Online and Xero between them *defined* the category's expectations. Everything else in this
document is measured against that definition, including QAYD.

## The one question these files exist to answer

> QAYD cannot beat QuickBooks on features or ecosystem for years. **On what dimension can a new
> entrant actually win in the GCC, and what is the minimum credible product?**

The answer is in [`LESSONS_FOR_QAYD.md`](./LESSONS_FOR_QAYD.md) §7 and
[`IMPLEMENTATION_RECOMMENDATIONS.md`](./IMPLEMENTATION_RECOMMENDATIONS.md) §1. It is uncomfortable, and
it is stated plainly rather than softened.

---

## The files

| File | What it contains | Read it when |
|---|---|---|
| [`OVERVIEW.md`](./OVERVIEW.md) | Per-product profiles; the category's core loop dissected; pricing/packaging; AI shipped-vs-marketed; ecosystems; the accountant channel; the GCC/Kuwait reality section | You need the facts about a specific product or the market |
| [`BEST_PRACTICES.md`](./BEST_PRACTICES.md) | What these products do **well** and why it works — mechanism, not admiration | Designing any QAYD surface a user will touch daily |
| [`ANTI_PATTERNS.md`](./ANTI_PATTERNS.md) | What they do **badly** — UX, workflow, accounting-model, pricing and data anti-patterns — and the cost of each | Before shipping a workflow, a pricing page, or a currency field |
| [`ARCHITECTURE.md`](./ARCHITECTURE.md) | What can legitimately be **inferred** about their internals from published APIs and observable behaviour, clearly labelled; plus the workflow and data-model *shapes* the category implies | Designing QAYD's banking, matching, and rules subsystems |
| [`LESSONS_FOR_QAYD.md`](./LESSONS_FOR_QAYD.md) | The synthesis: what to adopt, what to refuse, what to invert, and the honest strategic answer | Planning a sprint or a quarter |
| [`IMPLEMENTATION_RECOMMENDATIONS.md`](./IMPLEMENTATION_RECOMMENDATIONS.md) | Sequenced, costed recommendations with effort (Fibonacci), confidence, and business impact | Turning this research into backlog items |

---

## Evidence grading — and its limits in this category

Every non-trivial claim carries a tag. The tags mean exactly this:

| Tag | Meaning | Reliability |
|---|---|---|
| `[DOCS]` | Stated in official vendor documentation, a published pricing page, an official API reference, a regulator's publication, or a vendor's own support/KB article. URL cited. | High for *what the vendor asserts*. A pricing page is authoritative about price and about nothing else. |
| `[CODE]` | Read from source. **Almost absent here by construction** — see below. | Highest, where available |
| `[COMMUNITY]` | User forums, Reddit, G2/Capterra/Trustpilot summaries, trade press, analyst commentary, vendor-partner marketing. | Directional. Good for *what breaks*, poor for *how often*. |
| `[INFERENCE]` | A conclusion I drew. The reasoning is always shown so it can be attacked. | Only as good as the argument |
| `[UNKNOWN]` | Could not verify. Deliberately left empty. | — |

### The rule that governs this whole study

**All seven products are closed-source commercial SaaS.** That imposes a hard epistemic boundary which
the ERP study did not have (Odoo, ERPNext, Akaunting and Dolibarr could be read line by line):

- **Verifiable:** user-visible behaviour, published pricing, published API surface, documented
  workflow, regulator filings, and public statements.
- **Not verifiable:** database schema, service topology, storage engine, whether balances are
  materialised, how matching is implemented, what the AI stack actually is.

An internal design inferred from an API surface is a **hypothesis**, and is tagged `[INFERENCE]` with
its reasoning exposed. Where `ARCHITECTURE.md` says "Xero's model implies X", that is a claim about
what the *observable contract* requires — not a claim about their code. Any sentence in these files
that asserts an internal implementation detail without an `[INFERENCE]` tag and a visible argument is a
defect; report it.

**A second discipline, specific to the AI sections.** The most common failure mode in competitive
documents is claiming a rival lacks something they shipped last quarter. Every AI claim here is
separated into **shipped and generally available**, **shipped but limited** (region/SKU/preview), and
**announced only**. Where GA status could not be confirmed for a given market, it says so.

---

## Prior work — cross-referenced, never restated

These files assume the earlier research and deliberately do not repeat it.

| Prior document | What it already covers | How this study relates |
|---|---|---|
| `docs/architecture/knowledge/06_COMPETITIVE_ANALYSIS.md` (~1,240 lines) | Odoo, ERPNext, SAP, NetSuite, Dynamics, Akaunting, Dolibarr; subsystem-by-subsystem architecture comparison; the honest scorecard; §4.2 table-stakes list; §4.4 the four white-space capabilities | This study is the **experience** counterpart. Where §4.2 says "bank reconciliation is table stakes", this study specifies *what good feels like*, and — critically — establishes whether the input that makes it possible even exists in Kuwait. |
| `docs/architecture/knowledge/07_QAYD_INNOVATION.md` (20 ideas, I-01…I-20) | The invented capabilities: bitemporal ledger, policy replay, judgement record, autonomous reconciliation under a reversibility budget (I-17), NL accounting as reviewable predicates (I-16), number provenance (I-12) | This study supplies the **market justification or refutation** for several of them. I-17 in particular is re-examined against the reality that GCC bank data arrives as a PDF, not a feed. |
| `docs/research/odoo/ODOO_TO_QAYD.md` and `ODOO_LEARNING.md` | Odoo's reconciliation model, residual derivation, tax repartition, the deleted workflow engine | Odoo's *conceptual* reconciliation model is the reference; QuickBooks' and Xero's are the *experiential* reference. They are different lessons and both are needed. |
| `docs/execution/MVP_SCOPE.md` | QAYD's MVP: bill → ledger → bank → close → report; KWD single currency; bilingual AR/EN; statement **upload**, not live feeds | This study validates that scope decision and sharpens it. The statement-upload choice turns out to be forced by the market, not a compromise — see `OVERVIEW.md` §8. |

**Duplication rule:** if a fact is already in `06_COMPETITIVE_ANALYSIS.md`, this study cites the
section rather than restating it. Odoo, ERPNext, SAP, NetSuite, Dynamics, Akaunting and Dolibarr are
**out of scope here** except where a direct contrast with an SME product is the point being made.

---

## Scope boundaries of this study

**In scope:** the seven named products; the bank-feed → categorise → reconcile loop; onboarding and
time-to-value; automation; shipped AI; reporting; app ecosystems; pricing and packaging; multi-currency;
the accountant/bookkeeper channel; the GCC and Kuwait market reality.

**Out of scope:** ERP-class systems (covered in the prior study); payroll products; tax-filing-only
products; the payments and banking research domains (`docs/research/payments/`, `docs/research/banking/`);
audit-firm practice-management software; anything requiring source access to a closed product.

**Explicitly refused:** copying code, imitating architecture, reproducing UI, or lifting screen
layouts. Every recommendation in these files is a *principle* with QAYD-native reasoning. Where a
competitor's concept is adopted, the concept is named, the reason it works is explained, and the QAYD
implementation is designed independently.

---

## A note on the research conditions

This study was produced under a constrained web-search budget, which was exhausted before the work was
complete. Coverage is therefore **uneven, and deliberately labelled as such**:

| Depth | Subjects |
|---|---|
| **Researched in depth** | QuickBooks Online · Zoho Books · the GCC/Kuwait market section |
| **Under-researched relative to its importance** | **Xero** — the second most important product in the category. `OVERVIEW.md` §2.2 is thin and says so |
| **Deliberately light** | FreshBooks · Wave · FreeAgent · KashFlow — none competes in the GCC |

Questions that should have been answerable and were not — Xero's decimal-place behaviour for 3-decimal
currencies from a primary source, Xero's JAX GA status, the share of subscribers acquired through the
accountant channel, the current number of registered SMEs in Kuwait — are marked `[UNKNOWN]` rather than
filled from memory. All of them are listed in `OVERVIEW.md` §10 so a later pass can close them without
re-deriving the document.

Two further honesty notes. **Intuit's sites block automated fetching**, so some QuickBooks claims rest
on a text proxy or on search-indexed snippets; those are marked `[DOCS-snippet]` and flagged for
re-verification. And **the Kuwait section is the highest-value and the least well-served by public
sources** — where a claim there rests on a single secondary source, it says so.

One conclusion that emerged late and is worth surfacing here: **QAYD's real competitors are probably not
QuickBooks and Xero but Wafeq, Qoyod and Daftra** — Arabic-first GCC startups, two of which already
clear Saudi's ZATCA bar that QuickBooks does not. That argument is in `LESSONS_FOR_QAYD.md` §7.2a, and
researching those three properly is the single most valuable follow-up this study leaves undone.
