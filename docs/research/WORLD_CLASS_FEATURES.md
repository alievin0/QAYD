# World-Class Features — the catalogue

**Every feature discovered across all Phase-3 research, in fifteen categories, each with a
Must-have / Should-have / Nice-to-have / **Deliberately skip** verdict, effort and confidence.**

Version 1.0 · 2026-07-28 · Phase 3 synthesis · **Documentation only** — no application code, schema,
migration, seed or test was created or modified in producing this file.

Companion: [`GLOBAL_GAP_ANALYSIS.md`](./GLOBAL_GAP_ANALYSIS.md) (the rubric, the scorecard, the gap
classification, the three domination positions). This document is the **inventory**; that one is the
**strategy**.

---

## 0. How to read this

### 0.1 The verdict scale

| Verdict | Meaning | The test |
|---|---|---|
| **Must-have** | The product is not credible without it | A prospect asks in the first meeting, or its absence produces a wrong number |
| **Should-have** | Real value, clearly sequenced after the must-haves | A prospect asks in the third meeting |
| **Nice-to-have** | Genuinely good, genuinely optional | Nobody has ever lost a deal over it |
| **Deliberately skip** | **Do not build it. Ever, or not as this company** | Building it destroys something more valuable, or the evidence says it is a trap |

**The "deliberately skip" verdicts are the most valuable column in this document.** A catalogue that
recommends everything it found is a wish list. **Sixty-seven of the 227 entries below are skips — very
nearly one in three** — and each carries the mechanism of harm or the reason the market says no.

### 0.2 Effort and confidence

**Effort** is Fibonacci points on QAYD's existing scale, for the *minimum credible version* — not the
best-in-class version. **Confidence** is High / Medium / Low on the *verdict*, not on the evidence.

### 0.3 Evidence

`[DOCS]` vendor's own surface · `[CODE]` read from source · `[COMMUNITY]` trade press, forums,
practitioners · `[INFERENCE]` reasoning shown · `[UNKNOWN]` unverified. Market claims are dated.

### 0.4 ⚠️ A disclosure about coverage, made before the tables rather than buried after them

**Four of the fifteen requested categories are near-empty in the source research, and this document does
not pad them.** Inventory, Manufacturing, CRM and HR were not studied in depth by any Phase-3 folder — the
ERP research explicitly records of the two manufacturing-led vendors it examined: *"This section is short
because the evidence is absent, and a longer section would be invention."*

**Specifically absent from 25,774 lines of research:** inventory valuation methods, stock ledgers, landed
cost, reorder points, BOM, routing, work orders, MRP, shop floor, capacity planning — and **PIFSS, Kuwaiti
social security, end-of-service indemnity and WPS appear nowhere at all**, despite PIFSS being named in
`competitive/OVERVIEW.md` §5.1 as one of four requirements recurring in every Kuwaiti implementer's
marketing.

**That absence is itself a finding, and §7, §8, §9 and §10 treat it as one.** It happens that all four
categories are also where this document's hardest skip verdicts land — which is convenient, and which
should be treated with suspicion rather than relief. §17.3 states the honest risk.

### 0.5 Contents

| § | Category | Entries | Must | Should | Nice | **Skip** |
|---|---|:--:|:--:|:--:|:--:|:--:|
| 1 | [Accounting](#1-accounting) | 34 | 22 | 9 | 0 | **3** |
| 2 | [Reporting](#2-reporting) | 14 | 5 | 4 | 1 | **4** |
| 3 | [Banking](#3-banking) | 18 | 10 | 5 | 0 | **3** |
| 4 | [Payments](#4-payments) | 15 | 6 | 4 | 1 | **4** |
| 5 | [AI](#5-ai) | 35 | 17 | 6 | 0 | **12** |
| 6 | [ERP core](#6-erp-core) | 15 | 3 | 3 | 2 | **7** |
| 7 | [Inventory](#7-inventory) | 6 | 0 | 1 | 1 | **4** |
| 8 | [Manufacturing](#8-manufacturing) | 5 | 0 | 0 | 0 | **5** |
| 9 | [CRM](#9-crm) | 7 | 2 | 1 | 1 | **3** |
| 10 | [HR & payroll](#10-hr--payroll) | 7 | 0 | 1 | 1 | **5** |
| 11 | [Analytics](#11-analytics) | 14 | 4 | 4 | 0 | **6** |
| 12 | [Automation](#12-automation) | 13 | 6 | 4 | 0 | **3** |
| 13 | [Security](#13-security) | 20 | 14 | 5 | 0 | **1** |
| 14 | [Compliance](#14-compliance) | 15 | 5 | 4 | 2 | **4** |
| 15 | [Forecasting](#15-forecasting) | 9 | 1 | 3 | 2 | **3** |
| | **Total** | **227** | **95** | **54** | **11** | **67** |

**Read the shape of that table before the contents of it.** The four categories with the fewest entries —
Inventory, Manufacturing, CRM, HR — contain **17 of the 67 skips and 2 of the 95 must-haves.** That is the
document's central editorial claim in numeric form: *QAYD may account for anything; it should operate
almost nothing.*

---

# 1. Accounting

## 1.1 The double-entry core

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| ACC-01 | **Exactly one write path into the ledger** | A single service authorised to write a posted line | **QAYD** `[CODE]`; Adyen by a different route — *"the only way to add records is by means of templates"*, formally verified to net to zero `[DOCS]` | The invariant "exactly one writer" is what makes every other invariant verifiable. OFBiz fires posting from commit hooks and **has no call graph at all** `[CODE]` | **Must-have** | Built | High |
| ACC-02 | **Balance re-derived from lines, zero tolerance, both currencies** | Never trust cached header totals | **QAYD** `[CODE]` | OFBiz accepts imbalances < 0.01 — **0.009 KWD posts** `[CODE]` | **Must-have** | Built | High |
| ACC-03 | **Σ(debits)=Σ(credits) enforced at the database** | `DEFERRABLE INITIALLY DEFERRED` constraint trigger | Square "Books" — enforced at DB level `[DOCS]` | QAYD's `chk_je_balanced` currently constrains **two cached numbers to each other, not to the lines.** A bug writing consistent-but-wrong totals passes every constraint | **Must-have** | 3 | High |
| ACC-04 | **Append-only ledger enforced by trigger** | `UPDATE`/`DELETE` raise, independent of the application | **QAYD** `[CODE]` | Every corpus system reaches the same conclusion and enforces it in *application* code. **Immutability is not even a documented property of the incumbent core-banking market** — Temenos publishes an API for deleting journal entries `[DOCS]` | **Must-have** | Built | High |
| ACC-05 | **Revoke `UPDATE`/`DELETE` on `ledger_entries`** | The verb does not exist for the app role | Banking posture generally | `audit_logs` revokes *and* has a trigger; **the ledger has only the trigger — the more important table is defended less than the audit log** | **Must-have** | 1 | High |
| ACC-06 | **Correction by reversal only, with linkage** | The mirror entry, linked | TigerBeetle — preserves *"the original error, when it took place, as well as any attempts to correct it"* `[DOCS]` | The alternative is history rewriting | **Must-have** | Built | High |
| ACC-07 | **Named compensating-entry taxonomy** | `_intention` / `_return` / `_rejection` as distinct facts | **Increase** `[DOCS]` | Three queryable facts instead of one edited row | **Should-have** | 3 | Medium |
| ACC-08 | **Gross and net stored, derived column DB-constrained** | `CHECK (signed = debit − credit)` | **QAYD** `[CODE]` | TigerBeetle keeps gross and derives net in the app; Modern Treasury exposes both. **No system in the corpus does both with a constraint.** Kills the "derived column drifted" bug class structurally | **Must-have** | Built | High |
| ACC-09 | **Idempotent projection as a uniqueness constraint** | `UNIQUE (journal_line_id)` | **QAYD** `[CODE]` | Posting is idempotent **at the database level regardless of middleware** | **Must-have** | Built | High |
| ACC-10 | **Exact decimal money, no floats anywhere** | `NUMERIC(19,4)` + bcmath strings | **QAYD**; TigerBeetle (u128 + asset scale) `[DOCS]` | Odoo, Akaunting and Dolibarr all use binary floats; **Dolibarr has zero DECIMAL columns in 410 tables** `[CODE]`. Float addition is not associative — one cast destroys the entire integrity layer | **Must-have** | Built | High |
| ACC-11 | **Variable currency precision as data (3-dp KWD/BHD/OMR)** | Minor-unit exponent per ISO 4217, applied at validation, storage, arithmetic, rounding, display, PDF and export | **Zoho has the right shape** (per-currency decimals, `price_precision` in the API) `[DOCS]` — depth `[UNKNOWN]` | **QuickBooks cannot** `[COMMUNITY]`; **Xero "assumes two decimal places for most currencies"** `[DOCS]`. This is domination position #3 | **Must-have** | 3 | High |
| ACC-12 | **A named regression test that 0.0005 KWD is rejected** | Three-decimal currency in the standard test matrix | Nobody | `../erp/` L-06: QAYD's money model is right and **asserted rather than proved.** The proof is the product claim | **Must-have** | 2 | High |
| ACC-13 | **Gapless numbering under concurrency** | Sequence row-locked inside the posting transaction | **QAYD** `[CODE]`; Tryton makes it a **distinct type** so the cheap variant cannot be wired by mistake `[CODE]` | Name the expensive variant so its cost is visible at every call site | **Must-have** | Built | High |
| ACC-14 | **Bounded entry date + declared accounting timezone** | A `CHECK` on range; a named timezone anchor | Vault bounds ±90 days **in the type** `[CODE]`; Column anchors to a named timezone `[DOCS]` | **A Kuwait company closing at 23:00 AST is doing something a UTC server resolves into the next day** | **Must-have** | 2 | High |
| ACC-15 | **Two clocks, not three** | `journal_date` (valid) + `posted_at` (system) | Vault has three, Temenos four `[CODE]/[DOCS]` | **The third clock is not earned.** A booking date is meaningful only when it can diverge from *both* — which happens in banking because a statement cycle is a real external artefact. QAYD has none | **Deliberately skip** | 0 | High |
| ACC-16 | **Overrides as data, never as branches** | A nullable column with a reason and an actor | Vault — `advice`, `override_all_restrictions` as *fields on the instruction* `[CODE]` | *"Which postings skipped the balance check"* becomes a `WHERE` clause. QAYD has no override today, which is ideal and will not last | **Should-have** | 2 | High |
| ACC-17 | **Closed machine-readable rejection enum, with a review outcome** | Stable codes, the field each concerns, and `REVIEW_*` as a first-class severity | **Vault** — including `CLIENT_CUSTOM_REASON` `[CODE]` | *"Allowed but must be looked at"* is **precisely the state an AI-drafted entry occupies**, and it is instructive that a bank core needed the same third option | **Should-have** | 3 | High |
| ACC-18 | **A dry-run / pre-posting validation endpoint** | Full validation, reports what *would* happen, posts nothing | Vault's `pre_posting_hook` sees proposed postings in the committed view's shape `[CODE]` | For an AI that drafts entries this is **the difference between iterating safely and iterating in production** | **Should-have** | 5 | High |
| ACC-19 | **Assertions along the posting path, in production** | Project-count = line-count; signed reconstructs from gross | **TigerBeetle** — assertions *"downgrade catastrophic correctness bugs into liveness bugs"* `[CODE]` | *"A system that crashes is recoverable; a system that silently computes the wrong balance is not"* | **Must-have** | 2 | High |
| ACC-20 | **Seeded, reproducible property tests over the posting engine** | Thousands of random-but-valid shapes from a recorded seed | The portable 5% of TigerBeetle's VOPR | A failure is a `(seed)` replayable on a laptop. **The highest-leverage testing idea in the corpus, ~a week** | **Should-have** | 8 | High |
| ACC-21 | **Whole-system deterministic simulation** | No ambient clock, randomness or I/O | TigerBeetle VOPR `[CODE]` | **Laravel forecloses it.** Take ACC-20, the valuable 5% at 1% of the cost | **Deliberately skip** | 0 | High |

## 1.2 Chart of accounts, periods, and the accounting surface

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| ACC-22 | **Seeded, editable, GCC-shaped chart of accounts** | A template containing the accounts a Kuwaiti trading company actually uses | **ERPNext ships 68 verified country templates** `[CODE]` | Removes the category's biggest onboarding wall. *"A visible, first-five-minutes signal that the product was built for them."* **A data problem, not an engineering one — the cheapest item on Phase 2's table-stakes list** | **Must-have** | 3 | High |
| ACC-23 | **`is_postable` on accounts + a ledger-aware activity guard** | A header account cannot receive a posting | All seven Phase-2 systems | QAYD's guard is currently **ledger-blind** `[TD-11]` and there is no `is_postable` `[TD-15]`. Phase 2 scores QAYD's COA **2** | **Must-have** | 3 | High |
| ACC-24 | **Fiscal periods and month-end close** | Period objects, a close event, a posting gate at period granularity | All seven `[DOCS]` | *"How do I close a month?"* is asked in the first meeting. **Fiscal-year gating is not an answer.** Phase 2 scores QAYD **1** | **Must-have** | 8 | High |
| ACC-25 | **Opening balances + a conversion date as modelled objects** | Not a special journal, a first-class concept | All seven `[DOCS]` | **Nobody adopts an accounting system on day one of a fiscal year** | **Must-have** | 5 | High |
| ACC-26 | **Recurring entries, including journals** | Schedule-driven templates | Category-wide `[DOCS]` | The **deterministic floor beneath the AI** — SAP's rules engine outlived its ML layer | **Should-have** | 5 | High |
| ACC-27 | **Account-period balance rollup, maintained in the posting transaction** | `account_period_balances` via an `AFTER INSERT` trigger | **QAYD's design** — Odoo stores no aggregates anywhere; ERPNext materialises only at close; SAP deleted its aggregate tables and had to add Deferred Summarization | An `AFTER INSERT` trigger on an **append-only** source is *monotonic*, which makes the aggregate trustworthy in a way an aggregate over a mutable table never is. **Not available to any system whose ledger can be updated** | **Must-have** | 8 | High |
| ACC-28 | **Cross-period continuity assertion** | `period(N+1).opening = period(N).closing` per `(company, account)` | Nobody in the corpus | The planned per-row CHECK **does not catch the worst bug it will have**: a back-dated posting into period 3 leaves periods 4–12 stale, every row still satisfies its own CHECK, and **the balance sheet is wrong from period 4 with nothing detecting it** | **Must-have** | 3 | High |
| ACC-29 | **Invalidation keyed on `entry_date`, not `now()`** | A posting made today with a March date invalidates March onward | — | Keying on the current period is wrong for exactly the entries most common at period-end | **Must-have** | 2 | High |
| ACC-30 | **Multi-currency with a rate table, revaluation and a missing-rate error path** | Rates as data with provenance; unrealised FX revaluation | NetSuite (consolidated rate types + CTA) and ERPNext score 4–5 `[DOCS]` | **A Kuwaiti trading SME routinely holds USD and AED.** Unrealised revaluation is a hard requirement for IAS 21 and Kuwait statutory reporting; Odoo Community users must buy Enterprise for it. **And `rate_unit` (1/100/1000) matches how CBK actually publishes rates** | **Should-have** | 13 | High |
| ACC-31 | **Per-currency balance enforcement** | A `CHECK` tying `journal_lines.currency_code` to its parent | Modern Treasury enforces per-currency balancing to prevent *"transactions that appear balanced overall while losing money in specific currencies"* `[DOCS]`; TigerBeetle makes it inexpressible | **Latent, not live, in QAYD**: the assertion sums across all lines without partitioning by currency. The cheap fix is a CHECK, not an engine change | **Must-have** | 2 | High |
| ACC-32 | **Dimensional accounting with amounts on the allocation row** | `(line, dimension, member, signed_amount)` + a named reusable allocation rule | **Sage Intacct** (named Transaction Allocations, hierarchical members, inter-dimension autofill) `[DOCS]`; **Tryton** (distribution accounts validated to sum to 1) `[CODE]` | Three unrelated architectures agree: **store money, not a percentage.** With percentages every report re-multiplies and re-rounds, and **60/40 of 0.05 KWD has exactly one right answer that must be decided once by the posting service** | **Should-have** | 13 | High |
| ACC-33 | **Derived dimensional-completeness state + a worklist** | `analytic_state` recomputed from the rows, plus a standing "lines to complete" view | **Tryton** `[CODE]` | Refuses the false choice: blocking posting makes close hostage to data entry; allowing gaps makes reports quietly wrong. **Derived, not asserted, so it cannot drift** | **Should-have** | 5 | High |
| ACC-34 | **Fixed dimension columns on the journal line** | Ten nullable dimension FKs | OFBiz `[CODE]` — as the cautionary example | Frozen at the vendor's guess; adding one is a migration on the largest table shipped to every customer; no percentage split possible; **and the vendor's own domain assumptions sit on every journal line of every installation forever** | **Deliberately skip** | 0 | High |

---

# 2. Reporting

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| RPT-01 | **Trial balance, P&L, balance sheet, with drill-down to entry to source document** | The three statements plus provenance | All except Akaunting `[DOCS]` | **An accounting system that cannot produce a trial balance is not an accounting system.** Phase 2 scores QAYD **1**. Becomes cheap once ACC-27 exists, so the rollup should precede the statements | **Must-have** | 8 | High |
| RPT-02 | **The trial balance run as a scheduled assertion, not a report a human opens** | A control total on a schedule | **QAYD's own reframing** | *"The trial balance has always been a control total; accountants merely also used it as a report."* Named **the highest-value, lowest-cost idea** in the banking research | **Must-have** | 3 | High |
| RPT-03 | **Control totals and re-derivation ahead of the hash chain** | Maintained vs recomputed, with the postings always winning | Square, TigerBeetle, Modern Treasury `[DOCS]` | The realistic threats — a projection bug, a partial transaction, a double-post, a corrupt rollup — are **all caught by control totals and none is caught better by a hash chain.** *"A hash mismatch says something is wrong; a control-total mismatch says you are 3 records and KWD 1,240.500 short"* | **Must-have** | 3 | High |
| RPT-04 | **Balance assertions / checkpoints** | The tool *fails* if the computed balance differs at a stated point | **Beancount** `[COMMUNITY]` | Lets drift be **bisected to the period it was introduced**, not merely detected | **Should-have** | 3 | High |
| RPT-05 | **An audit-trail report in the base product** | Who did what, when, exportable | **Nobody in the base product** — **Xero routes a high-vote audit-trail request to its App Store, answered "Not in pipeline"** `[COMMUNITY, verified 2026-07]` | A general ledger that outsources *"show me what the system did"* has outsourced its own accountability. QAYD has the data; it needs the report | **Must-have** | 5 | High |
| RPT-06 | **Report flexibility (custom groupings, comparatives, drill-through)** | A report builder | NetSuite scores **5** — one live database, no ETL lag `[DOCS]` | Report rigidity is a named anti-pattern: *"the spreadsheet becomes the real reporting layer"* | **Should-have** | 13 | Medium |
| RPT-07 | **Statement ageing / AR ageing** | Ageing buckets on statements | Category-wide — and it is a **1,150-vote Xero request answered "not in pipeline"** `[COMMUNITY]` | On the list of core functions the category leader has publicly declined to build | **Should-have** | 5 | Medium |
| RPT-08 | **Scheduled reports** | Emailed on a cadence | Category-wide; another declined Xero request `[COMMUNITY]` | Low effort, high perceived value, publicly unmet by the leader | **Nice-to-have** | 3 | Medium |
| RPT-09 | **Bilingual AR/EN statement output with correct RTL PDF rendering** | Arabic shaping, ligatures, mirrored layout, LTR numerals inside RTL text | Nobody well — **Zoho requires manually setting an RTL font for Arabic invoice PDFs** `[COMMUNITY]`; QuickBooks documents *"may not format perfectly for RTL"* `[COMMUNITY]` | *"An invoice with broken Arabic is unusable and embarrassing to send"* — and **it is the artefact the customer's customer sees** | **Must-have** | 8 | High |
| RPT-10 | **Cursor pagination on append-heavy collections** | Regardless of volume | Stripe `[DOCS]` | The correct trigger is **append-heaviness, not volume**: `journal_lines`, `ledger_entries`, `audit_logs` and `bank_statement_lines` are unstable under offset pagination even with few rows, because insertion at the head shifts every page | **Should-have** | 3 | High |
| RPT-11 | **Document splitting (segment/profit-centre balanced statements)** | Complete balanced statements at a sub-entity level | **SAP** — no peer `[DOCS]` | Genuinely the state of the art, and genuinely far beyond scope | **Deliberately skip** | 0 | High |
| RPT-12 | **Consolidation and group reporting** | ACDOCU / OneWorld | SAP, NetSuite `[DOCS]` | Genuinely valuable for Gulf family groups and genuinely far beyond current scope. **Record the requirement only so the RLS model is not frozen in a way that makes it impossible later** | **Deliberately skip (now)** | 0 | High |
| RPT-13 | **70+ prebuilt reports** | Breadth | Zoho `[DOCS]` | The feature-count race. QAYD will not match it and should not try | **Deliberately skip** | 0 | High |
| RPT-14 | **Tenant-facing figures served from a replica or an analytics tier** | Reporting off the primary | — | **Never.** *"Tenant-facing statements: PRIMARY → `account_period_balances`. Always."* The replica is exploration only | **Deliberately skip** | 0 | High |

# 3. Banking

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| BNK-01 | **Per-bank statement adapters, zero manual editing (PDF + CSV)** | An adapter per Kuwaiti bank that ingests the file the bank actually emits | **Nobody, for Kuwait** — QuickBooks names five UAE banks and 404s on `/kw/` `[DOCS]` | Domination position #1. UAE users already hand-edit ADCB and Emirates NBD CSV headers before import works `[COMMUNITY]` — every month, forever | **Must-have** | 13 | High |
| BNK-02 | **`opening + Σ lines = closing` enforced at import** | A tie-out gate that rejects a truncated CSV or a missing PDF page before any entry is posted | **Nobody** — and **a feed-first product structurally cannot do it, having no closing balance to check against** | The clearest case in the research of a constraint producing a better design | **Must-have** | 5 | High |
| BNK-03 | **All-or-nothing import** | A partial application is worse than none | The corpus's paginated-sync discipline `[DOCS]` | *More* important with files than feeds: there is no cursor to resume from and no provider-side change log to diff against | **Must-have** | 3 | High |
| BNK-04 | **Saved per-bank column mappings as a first-class asset** | A maintained library for NBK, KFH, Gulf Bank, Boubyan, Burgan | Nobody | In a market with no standard feed this is **accumulated proprietary knowledge a competitor must rebuild customer by customer** | **Must-have** | 5 | High |
| BNK-05 | **Defined re-import behaviour for an overlapping period** | Idempotent, or an explicit revision | Nobody documents it well | The predictable second bug of file ingestion | **Must-have** | 3 | High |
| BNK-06 | **Statement as the primary object, lines derived** | The statement carries opening, closing and a period boundary | **QAYD's forced design** — the category models the *line* as primary because a feed emits lines | Enables BNK-02. *"Treat it as an asset and say so in the product"* | **Must-have** | Design | High |
| BNK-07 | **Bank reconciliation to a statement closing balance, as an event** | Reconciliation as a recorded attestation, not a flag | All seven Phase-2 systems have reconciliation; **QAYD scores 1** | **The single most-used daily workflow in an SME finance department** | **Must-have** | 13 | High |
| BNK-08 | **Matches as rows in a side table, never a flag on the money row** | `bank_reconciliation_matches` with `rules_fired`, `final_score`, `match_method` | Stripe, Adyen, Square all model membership as a join on a separate report `[DOCS]`; **QAYD's Sprint-3 design** | `journal_lines.reconciled` is *"the exact decision that made Odoo's GL mutable"* — a live column, no code path, and **structurally unwritable when it would matter** because the immutability trigger blocks updates on posted entries | **Must-have** | Design | High |
| BNK-09 | **Unreconcile by inserting a compensating row** | Never by delete | **QAYD's append-only trigger forbids the alternative** | Odoo unreconciles by DELETE and, for bank lines, deletes and recreates journal items on *posted* moves under `force_delete` — with **zero SQL constraints on its partial-reconcile table and zero concurrency tests among 95 reconciliation tests** | **Must-have** | 5 | High |
| BNK-10 | **A durable review queue separating bank evidence from accounting assertion** | Two different truth conditions, two objects | Category-wide, done well `[DOCS]` | Makes an unbounded task countable and gives automation a visible place to act | **Should-have** | 5 | High |
| BNK-11 | **Match-before-create, with residual derived from links** | Settle obligations rather than invent facts | Category-wide `[DOCS]` | Reversible without damage — and **forced anyway by the append-only ledger** | **Should-have** | 5 | High |
| BNK-12 | **Reference-dominant match ranking; amount+date never sufficient alone** | Scoring where an explicit reference outranks amount equality | Plaid documents that **its own feed contradicts amount matching**: *"their name and amount may change"* between pending and posted `[DOCS]` | Amount matching produces **false negatives as well as false positives.** Also: *"a posted transaction cannot necessarily be considered immutable"*, and the pending row is *removed* and replaced with a new id | **Must-have** | 8 | High |
| BNK-13 | **Variance = book balance − adjusted bank balance, computed generically** | One variance engine | — | **The same machinery covers PSP settlement later**, which is an argument for building it generically now rather than specifically to bank statements | **Should-have** | 3 | High |
| BNK-14 | **Bank-account verification before payment** | A gate on adding a payee account | QAYD's Sprint-3 design | The structural defence against *"add attacker-controlled account, pay it"* | **Should-have** | 5 | High |
| BNK-15 | **Live bank feeds via open banking** | Aggregator-driven transaction sync | Plaid (North America, UK, Europe — **no MENA coverage at all**) `[DOCS]` | **There is no rail.** CBK licenses *moving* money (EPSP/EMSP/EPSO) and has **no account-information-service licence, no payment-initiation licence, no TPP framework and no API standard** `[DOCS]`. Bahrain (mandatory since 2019) and Saudi (SAMA, published specs, conformance lab) are years ahead | **Deliberately skip (no rail)** | 0 | High |
| BNK-16 | **Available-balance formula** | `available = posted_inbound − pending_outbound` | Modern Treasury, Adyen, TigerBeetle and Wise all land here `[DOCS]` | Asymmetric failure consequences, not caution: counting a pending inbound that fails means the money was already spent. **Record the decision before it is needed, or someone chooses the other formula without knowing there was a choice** | **Should-have** | 2 | High |
| BNK-17 | **A pending/posted phase on the ledger itself** | Two-phase money as a ledger primitive | Every payment system in the corpus `[DOCS]` | **Wrong for QAYD.** A processor's ledger records money it is actually moving, so a promise is a real state of real money. **QAYD's ledger records accounting facts, and an uncleared bank transaction is not one — it is a document in a workflow.** The two-phase shape belongs in `bank_transactions`, upstream of the posting boundary | **Deliberately skip** | 0 | High |
| BNK-18 | **A purpose-built ledger database (TigerBeetle-class)** | A specialised store beside PostgreSQL | TigerBeetle `[DOCS]` | Two stores to keep consistent, a distributed transaction on the most correctness-critical operation, two RLS stories — and **`ledger_entries` loses the join to `accounts`, which is the basis of every report QAYD sells.** TigerBeetle *"assumes a trusted environment and does not provide permission systems"* | **Deliberately skip** | 0 | High |

---

# 4. Payments

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| PAY-01 | **Idempotency key written in the same transaction as the effect** | An `idempotency_keys` table, not Redis after commit | Stripe stores results *"regardless of whether it succeeds or fails… including 500 errors"* `[DOCS]` | **QAYD's specified design has three independent defects**, each of which alone permits a double post: the key is stored outside the transaction after commit; only successes are stored; and a 10-second lock guards an unbounded operation. *"The dual-write problem located in the component whose entire job is preventing duplicate money movement"* | **Must-have** | 5 | High |
| PAY-02 | **Key scoped `(company_id, endpoint, key)` with a request fingerprint** | Endpoint in the key | Stripe — `idempotency_error` is defined as reuse *"on a request that does not match the first request's API endpoint and parameters"* `[DOCS]` | **The API spec and the sprint plan disagree; the sprint plan is right.** Without the endpoint, one endpoint's response can be replayed as another's | **Must-have** | 2 | High |
| PAY-03 | **`409` returning the original resource id and the diverging fields** | A recoverable conflict | **Increase** (`resource_id`) + **TigerBeetle** (`exists_with_different_amount/_flags/_ledger/_code/…`) `[DOCS]` | Turns a dead-end error into a recoverable one — *"particularly for AI-generated requests"* | **Should-have** | 3 | High |
| PAY-04 | **Validation failures are not memoised; a business failure terminates the key** | A retried `400` re-validates; a failed economic attempt needs a new key | **Mambu** (the first) + **TigerBeetle** `id_already_failed` (the second) `[DOCS]` | *"The single most counter-intuitive published idea in the corpus and it is correct."* **Most implementations derived from the standard HTTP pattern get it wrong** | **Must-have** | 2 | High |
| PAY-05 | **Transactional outbox** | Event row written in the fact's transaction; drained with `FOR NO KEY UPDATE SKIP LOCKED` | **QAYD's design** — universally confirmed | `AD-14`: the gap *"must be closed before the second consumer exists."* **Three consumers land in the next two sprints. The trigger has fired** | **Must-have** | 5 | High |
| PAY-06 | **Idempotent consumer with the dedup insert inside the effect's transaction** | The consumer half of exactly-once | The corpus generally `[DOCS]` | QAYD specifies the producer side thoroughly and **does not yet specify the consumer side** | **Must-have** | 3 | High |
| PAY-07 | **A version on every event; consumers reject out-of-order** | Ordering as a consumer responsibility | Stripe, Wise, Adyen and Plaid **all refuse ordering guarantees in writing** `[DOCS]` | Retries *guarantee* reordering rather than merely permitting it. **Cost of adding it now: effectively zero. Cost of retrofitting across consumers: not zero** | **Must-have** | 2 | High |
| PAY-08 | **Push for latency, pull for order** | A broadcast plus a listable, retained event history | Stripe (unordered webhooks + a chronological Events API); Column `/api/events`; Increase (30-day retention) `[DOCS]` | **The webhook is the optimisation; the queryable history is the correctness backstop.** A consumer that was down must be able to reconcile | **Should-have** | 5 | High |
| PAY-09 | **Broadcast a signal, never the money** | Realtime is a notification to refresh authoritative state | **QAYD's `AD-14`** — and the position Stripe is *migrating toward* with thin events, having shipped fat payloads first | **QAYD gets to start where Stripe is arriving** | **Should-have** | Design | High |
| PAY-10 | **Fees booked at the transaction moment as a separate expense line** | Never netted at deposit | The corpus `[DOCS]` | Under net settlement the deposit **fuses revenue and cost-of-revenue into one number**, and the processor's itemised report is the only source of the decomposition. **Gross revenue becomes permanently unrecoverable** | **Should-have** | 3 | High |
| PAY-11 | **A funds-in-transit control account** | Balance should equal the processor's pending-plus-available | The corpus `[DOCS]` | The difference **is** the exception queue | **Nice-to-have** | 3 | Medium |
| PAY-12 | **Regional PSP integration (Tap, UPayments, MyFatoorah)** | Merchant settlement feed | **Tap** is the best documented — webhook `hashstring` validation and an idempotency guide; UPayments publicly commits to next-business-day settlement `[DOCS]` | **A revenue-side integration, not a statement source.** They expose only that merchant's own payment transactions — a *settlement* feed and a three-way match, a different module from bank sync | **Deliberately skip (for MVP)** | 0 | High |
| PAY-13 | **Card issuing / interchange-funded free software** | Corporate cards funding free spend management | Ramp, Brex `[COMMUNITY]` | **Structurally unavailable.** No global issuer onboards Kuwaiti SMEs; **KNET is an eleven-bank consortium with no public API** (`knet.com.kw` returned 403 on every attempt) `[DOCS]/[UNKNOWN]` | **Deliberately skip** | 0 | High |
| PAY-14 | **Fraud and risk underwriting machinery** | Scoring, decisioning, chargeback management | The PSPs `[DOCS]` | **QAYD is not a PSP and will never underwrite card risk.** The two places risk surfaces are already in the plan for the right reasons: account verification and two-key approval | **Deliberately skip** | 0 | High |
| PAY-15 | **PSP-scale sharding and availability splits** | Decoupling authorisation from accounting so accounting downtime cannot block acceptance | **Adyen** `[DOCS]` | QAYD has the **opposite** requirement: a ledger that is unavailable should **refuse to record**, not accept-and-reconcile-later. Adyen can afford it because the money movement is real regardless; **QAYD's ledger *is* the record** | **Deliberately skip** | 0 | High |

# 5. AI

## 5.1 The primitives that are the moat

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| AI-01 | **The proposal as a first-class ledger object** | A row distinct from the posting, carrying confidence, cited source rows, reviewer, approval, inside the chain | **Nobody publicly.** Digits is `[UNKNOWN]` | Domination position #2. `06` §4.4: none of the incumbents has a first-class proposal in the ledger — *"their AI is a layer bolted onto a system of record designed for humans typing"* | **Must-have** | 13 | High |
| AI-02 | **The AI holds no database grant that permits a post** | A privilege boundary, not a prompt | **QAYD** `[CODE]` | *"If the agent holds write credentials, then the ability to send a document to the customer is the ability to write their ledger. That is not a prompt-hardening problem; it is a privilege problem, and the only robust fix is to not hold the privilege"* | **Must-have** | Built | High |
| AI-03 | **`trg_no_ai_autopost` firing on `UPDATE` as well as `INSERT`** | Closing the draft→posted path, plus the flip-to-`ai_generated` and self-approval cases | — | **The trigger is `BEFORE INSERT` only** `[CODE]`. It is *"the terminal control on prompt injection"* — every other control reduces likelihood; this one bounds impact. **Until it is closed the product's headline claim is true on `INSERT` and false on `UPDATE`** | **Must-have** | 2 | High |
| AI-04 | **An auditable "reviewed by a human" state** | Reviewer identity, approval time, and it survives export | **Nobody** — **Xero's JAX auto-reconcile is beta, tier-gated, produces no auditable reviewed state and drops description and reference** `[COMMUNITY, verified 2026-07]` | The buyer is buying the ability to *sign off*, not the automation. Without it the accountant carries the risk personally | **Must-have** | 8 | High |
| AI-05 | **Structured, machine-readable rationale** | Feature contributions, matched tokens, rules fired — JSONB, not prose | **QAYD's `P-12`** | Three benefits, no tradeoff: prose *"cannot be aggregated, diffed, or regression-tested"*; structured output is **shorter, and output tokens are ~5× input**; and every `precedents_cited` entry is a dereferenceable key, so **number provenance arrives as a side effect** | **Must-have** | 5 | High |
| AI-06 | **Instrumented approval + a blind-sampled second-review stream** | Engagement above materiality, time-to-approve telemetry, and re-presenting already-approved items unlabelled | **Nobody** | Without it the failure is invisible: no error, no alert, 99% approval, every downstream metric quietly poisoned. **The blind stream is the only measurement not conditioned on the reviewer having seen the model's opinion** — and therefore the only valid input to calibration | **Must-have** | 8 | Medium |
| AI-07 | **Published calibration curve** | Predicted-confidence bucket vs observed correctness, per capability, model and prompt version | **Nobody in this market publishes calibration** | A distribution shift shows up as a **calibration break before an accuracy drop** — the earliest available warning. *"The honest version of the accuracy claim every vendor makes"* | **Should-have** | 5 | Medium |
| AI-08 | **The correction corpus as a designed asset** | Rejected, edited-then-accepted, blind-disagreement, refused and reversed proposals, with **what changed** recorded | **Nobody** | Expert-authored labels at zero marginal cost. **For the edit path it is not expensive to backfill — it is impossible.** *"A competitor can copy a feature; they cannot copy three years of a customer's corrections"* | **Must-have** | 5 | High |
| AI-09 | **Deterministic rules first; the model sees only the residual** | A precedent row with sufficient support means **the AI is never called** | **QAYD's `P-12`/`05`**; SAP's rule-based engine outlived its ML layer | Simultaneously the cost lever, the accuracy lever and the injection blast-radius lever. And *"the cheapest token, the fastest answer, and the only one that can be explained by pointing at a row"* | **Must-have** | 8 | High |
| AI-10 | **Extraction / reasoning separation** | Extraction emits a typed structure; the proposing step sees only that structure | — | **The highest-leverage AI security control.** One extra model call means attacker prose from a supplier document can never reach the step that decides accounting treatment — *"and unlike a filter it cannot be evaded"* | **Must-have** | 5 | High |
| AI-11 | **The historical-departure check** | Deterministic comparison against how this tenant has coded this vendor before | — | Catches the realistic attack, needs no model, cannot be prompted around, **and is a good product feature with no adversary at all** | **Must-have** | 3 | High |
| AI-12 | **Egress severed on both halves** | No outbound route but the model provider (backend); **never render a model-authored URL or image reference** (frontend) | — | EchoLeak's exfiltration ran through an **allowlisted image proxy** after the classifier was defeated. **The frontend half is the one nobody writes down and the half that actually failed at Microsoft** | **Must-have** | 3 | High |
| AI-13 | **Control-flow integrity: no model output selects a code path** | Branch only on a validated enum | CaMeL manufactures this property at a **7-point utility cost**; **QAYD has it for free** because its control flow is genuinely fixed | *"No value derived from untrusted content may determine which code runs, which tool is called, which tenant's data is read, or which row is written."* Losing it is a security regression, not a refactor | **Must-have** | 2 | High |
| AI-14 | **Temporal judgement filter in SQL, not in the prompt** | `effective_from` / `superseded_by` in the `WHERE` clause | — | *"A superseded rule still influencing the AI is a silent, systematic error affecting hundreds of entries."* **If the filter lives anywhere in the AI engine — even in Python — it is one refactor away from being a suggestion** | **Must-have** | 3 | High |
| AI-15 | **Declared per-section context budget with fixed assembly order** | Priority truncation, byte-identical prefix | — | **Even a single distractor reduces performance and four compound it**, and a chart of accounts is distractor-dense by construction. **Quality and cost point at the same mechanism**, because the cached prefix must be byte-identical or the cache silently misses | **Should-have** | 5 | High |
| AI-16 | **Cache-hit assertion in an integration test** | Assert on `cache_read_input_tokens` | — | The minimum cacheable prefix **moves per model and per release, silently, with no error raised** — a marker on an under-length prefix simply does nothing. **The test is the primary control, not a backstop** | **Must-have** | 2 | High |
| AI-17 | **Batch + 1-hour cache TTL for non-interactive work** | 50% discount, with the longer TTL because a 5-minute entry expires mid-batch | Anthropic's documented recommendation `[DOCS]` | They are **not independent levers.** Using the 5-minute default inside a batch pays 1.25× for a write that is never read. And `max_tokens: 0` pre-warming is **not supported inside a batch** | **Should-have** | 3 | High |
| AI-18 | **Escalation on task properties, never on self-reported confidence** | Ambiguity count, multi-document conditions, monetary thresholds, validation failure | **QAYD's tier ladder** | Published cascade results are measured on **preference-quality benchmarks**; QAYD's tasks have correct answers, so a router preserving 95% of preference-quality may preserve far less accuracy — **concentrated on exactly the unusual inputs where escalation mattered** | **Must-have** | 3 | High |
| AI-19 | **Every loop bounded; give-up as a first-class outcome** | Step count, token spend, wall clock — and `null` as a recorded, counted, distinguishable state | — | Every failure in the published multi-agent failure lists is a **boundedness** failure, not a reasoning failure. *"We could not evaluate this"* and *"we evaluated this and found nothing"* must never render the same | **Should-have** | 3 | High |
| AI-20 | **Deterministic graders as the primary eval** | Does it balance? Does the account exist and is it postable? Is the period open? Does it equal the human's correction? | **QAYD, structurally** | **The single most favourable finding in the AI research:** *"Almost every AI product must reach for a model judge because it has no oracle. QAYD has an oracle in the database."* The model judge is confined to explanation quality and **never scores financial correctness** | **Must-have** | 5 | High |
| AI-21 | **The Challenger, shipped dark and measured first** | Findings, not fixes; precision measured for a month before anyone sees it | — | A noisy Challenger is **negative, not merely useless** — it trains users to dismiss alerts, degrading every other alerting surface including the approval discipline. **Alert fatigue is not recoverable by improving the alert later.** It also runs on the batch path at 50% | **Should-have** | 8 | Medium |
| AI-22 | **pgvector in the same database, embedding only what has no exact key** | Judgements, vendor description patterns, policy prose — **never raw document text** | — | The decisive argument is **not performance**: a separate store means re-implementing tenant isolation in a second system with a different enforcement mechanism, a different failure mode, no catalog test, and its own retention and residency obligations. The embedding constraint is what keeps the corpus in the low thousands per tenant | **Should-have** | 5 | High |
| AI-23 | **Thirteen capability configurations on one runtime** | Personas as capability scopes: readable data, proposable actions, autonomy class, prompt, evals, model tier | — | Thirteen runtimes buys thirteen deployment surfaces and thirteen places to forget the tenant-context helper. **And capability scoping makes an unaskable question unaskable: capabilities do not approve things** | **Must-have** | 5 | High |

## 5.2 AI — the ten skips

| # | Feature | Who does it | Why QAYD must not | Verdict | Conf. |
|---|---|---|---|---|:--:|
| AI-24 | **Autonomous posting at high confidence** | The category's ambition | Confidence is *"a statement about the model's internal state, not about the world"* and **degrades most gently exactly where accuracy degrades most sharply.** *"Autonomous is not merely risky, it is category-destroying"* — and it destroys AI-01, which is the actual moat | **Deliberately skip** | High |
| AI-25 | **A chat box over the ledger** | Xero JAX (Sept 2025, free to subscribers); named QBO agents (2026) `[COMMUNITY]` | Commoditised, **and the window is closing rather than opening.** *"In finance the form is not an input mechanism, it is a constraint display"* — a chat box removes the user's error-detection surface at the moment it matters | **Deliberately skip** | High |
| AI-26 | **Concurrent multi-agent orchestration** | The 2026 fashion | ~15× tokens, and the named poor-fit condition is exactly accounting: sub-results that must be mutually consistent. *"Pay 15× for breadth; never pay it for coherence"* | **Deliberately skip** | High |
| AI-27 | **A general agent endpoint (`POST /invoke {prompt}`)** | — | An open interface makes every capability question a **runtime** question. Never on the posting path | **Deliberately skip** | High |
| AI-28 | **An agent framework (LangChain-class)** | — | **The exact bytes sent to the model are the cache key**, and ceding prompt assembly to a dependency's minor version cedes the largest cost lever; frameworks **default to agency**, the property AI-09 refuses; and *"a trace is not a call stack."* `apps/ai` currently depends on nothing but FastAPI and uvicorn — *"a good position, to be departed from deliberately"* | **Deliberately skip** | High |
| AI-29 | **Fine-tuning on customer data** | — | Un-auditable (*"which training example caused this?"* has no answer), cannot be superseded on a date, cannot be tenant-scoped without one model per tenant. **For a product whose claim is that every number is explicable, parametric memory is the wrong container** | **Deliberately skip** | High |
| AI-30 | **A separate vector store** | Pinecone-class | AI-22's argument. *"The same class of decision as a second writer into the ledger"* | **Deliberately skip** | High |
| AI-31 | **Naive RAG on the hot path** | The default architecture | Semantic search is weakest exactly where chart-of-accounts mapping lives — **distractor-dense category assignment with low needle-question similarity** — and below ~200k tokens the guidance is to skip retrieval entirely. A tenant's chart, policies and judgements belong in the **cached prefix at 0.1× input price** | **Deliberately skip** | High |
| AI-32 | **Model-authored SQL, or model-chosen just-in-time retrieval** | — | *"The agent chooses"* is exactly the control-flow property injection subverts. JIT assembly is performed **by code** | **Deliberately skip** | High |
| AI-33 | **Compaction of conversation history** | Coding-agent practice | A summary of a financial conversation may silently drop a qualification. *"In a coding agent a lost detail produces a compile error. In an accounting agent it produces a plausible wrong number."* **If a task needs compaction, the task was scoped wrong** | **Deliberately skip** | High |
| AI-34 | **MCP internally** | The 2025–26 default | It imports an OAuth-and-transport threat model QAYD does not need, and **none of it addresses prompt injection** — MCP is a transport and capability-description protocol, not a trust boundary for content. QAYD's engine is first-party; mTLS plus an internal bearer is stronger. Revisit only when third parties plug tools in | **Deliberately skip (for now)** | High |
| AI-35 | **A cross-tenant model or index** | — | Using one customer's corrections to *evaluate* is defensible; using them to **train** a model serving another customer is a contractual and possibly regulatory question. Keep evaluation and training strictly separate | **Deliberately skip** | High |

# 6. ERP core

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| ERP-01 | **Party / PartyRole instead of Customer and Vendor tables** | *"There is no `Customer` table and no `Vendor` table. There is `Party`, `PartyRole`, and `RoleType`"* | **OFBiz** `[CODE]` — *"the best public worked example of enterprise data modelling in open source"* | In GCC family-owned structures the same legal entity is routinely customer, supplier, lessor and shareholder | **Must-have** | 5 | High |
| ERP-02 | **Counterparty *capacity* on the journal line, not just identity** | `(partyId, roleTypeId)` recorded together | **OFBiz** `[CODE]` — Tryton and Odoo record only the party | Related-party disclosure and intercompany elimination depend on capacity, and **reconstructing it later from the account used is guesswork.** Note OFBiz weakened its own pattern by making the relation `one-nofk`; QAYD would enforce it with a composite FK | **Should-have** | 3 | Medium |
| ERP-03 | **Temporal classification relationships** | `fromDate`/`thruDate` on the relationships reporting slices by | **OFBiz** puts `fromDate` **inside the primary key** of `GlAccountCategoryMember` `[CODE]`; Tryton does a narrower version on accounts | *"Which accounts were in Operating Expenses in FY2024?"* is unanswerable if membership is a mutable FK — **and it is exactly the question asked after the reorganisation that mutated it.** Scope it narrowly: OFBiz pays this tax on hundreds of entities and it is a genuine cost | **Should-have** | 5 | Medium-High |
| ERP-04 | **A named posting seam: `PostingService` takes an *event*, consults a *policy*** | The seam without the engine | Oracle Fusion's Subledger Accounting has the idea `[DOCS]`; **QAYD's `AD-04`/`AD-14` has the safer shape** | Jurisdictional variation is genuinely rules, not code paths — *"Kuwait, Saudi and a future IFRS-17 client differ in treatment, not in control flow."* **The load-bearing pairing: events decouple, the single writer keeps the invariant verifiable. Neither is safe alone — OFBiz has the first without the second** | **Must-have** | Built | High |
| ERP-05 | **Document-shaped write surface, ledger-shaped read surface** | Public API exposes invoices, bills, payments and contacts for write; journals are read-oriented | **QuickBooks and Xero, independently** `[DOCS]` | Document-shaped writes carry business meaning, so **posting rules stay in one place** | **Should-have** | Design | High |
| ERP-06 | **Customisation as a listable, versioned artefact** | *"What is non-standard about this tenant?"* has an answer | **Acumatica** — extensions packaged into versioned Customization Projects `[DOCS]` | **That single question determines whether an upgrade is a deploy or a project, and most ERPs cannot answer it.** Warning: convention-based binding is invisible to static analysis, and *"silence is the worst failure mode in financial software"* — so the "what extends what" report is part of the mechanism | **Nice-to-have** | 8 | Medium-High |
| ERP-07 | **Design rationale beside the code** | A `design.md` per subsystem explaining intent and vocabulary, not signatures | **Tryton** `[CODE]` | *"That one sentence explains the entire model faster than reading `account.py` does."* When `PostingService` acquires its second and third caller this is worth more than another ADR | **Nice-to-have** | 2 | Medium |
| ERP-08 | **Codebase size discipline as a standing constraint** | Tryton's entire `account` module is **14,309 lines**; core `trytond` is 76,939 | **Tryton** `[CODE]` | *"Tryton's whole general ledger is smaller than our description of Odoo's."* **Feature breadth does not require code bulk — what makes ERP codebases enormous is accumulated compatibility, not capability.** Legibility is a correctness property: an invariant nobody can find is an invariant nobody maintains | **Must-have** | 0 | High |
| ERP-09 | **A metadata-driven schema (add a GL column from a web form)** | Runtime-extensible DocTypes | **ERPNext** — Phase 2 scores it **5** for extensibility `[CODE]` | It **costs foreign keys, CHECK constraints and the ability to reason about the schema** — the exact properties that are QAYD's advantage. **ERPNext ships a `Ledger Health Monitor` to detect the drift this causes** | **Deliberately skip** | 0 | High |
| ERP-10 | **A generic workflow engine** | Configurable state machines | Every ERP appears to have one | **Odoo built one and deleted it**, replacing it with explicit state fields. A free lesson | **Deliberately skip** | 0 | High |
| ERP-11 | **A rules engine for posting** | Versioned rule sets over accounting events | **Oracle Fusion SLA** `[DOCS]` | *"A rules engine is a programming language you now maintain, debug and secure, usually without a debugger"*, and Fusion's SLA is widely regarded as one of the harder things to configure in enterprise software. **Keep the seam (ERP-04); scope any engine to tax; never to the double-entry core** | **Deliberately skip** | 0 | Medium |
| ERP-12 | **User-authored server-side code** | Deluge / Node / Java / Python / Go custom functions | **Zoho** `[DOCS]` | **Permanently freezes internal interfaces** — the mechanism that stops Dolibarr's core ever tightening an invariant. Deliver the demand as declarative predicates plus webhooks | **Deliberately skip** | 0 | High |
| ERP-13 | **A plugin marketplace** | Two-sided app ecosystem | Xero, QuickBooks (four-figure verified integrations) `[COMMUNITY]` | *"An ecosystem is a commitment never to fix your foundations."* And **Xero has now demonstrated the endgame: effective 2 March 2026 it retired revenue share for a per-connection plus per-GB-egress fee, and prohibited using its API data to train AI/ML models** `[DOCS]` | **Deliberately skip** | 0 | High |
| ERP-14 | **Reimplementing the database inside the application** | A cross-dialect entity engine | OFBiz — 12 dialect files `[CODE]` | You inherit **the intersection of twelve databases, not the union**: the engine **cannot emit a CHECK constraint at any point** (a scan of all 3,383 lines of `DatabaseUtil.java` finds zero), money is frozen at `NUMERIC(18,2)` forever, and booleans become three-valued `CHAR(1)` | **Deliberately skip** | 0 | High |
| ERP-15 | **Localisation breadth (50+ countries)** | Per-country compliance packs | SAP (DRC), NetSuite (~180 countries), D365 (~54 localizations) `[DOCS]` | Thousands of person-years of permanently maintained regulatory minutiae. **SAP itself ships "Localization as a Self-Service" because its own coverage is finite.** *Four GCC countries done exactly beats fifty done approximately* | **Deliberately skip** | 0 | High |

---

# 7. Inventory

## 7.1 The honest state of the evidence

**The Phase-3 corpus contains no inventory research.** Four incidental mentions exist and every one is
cited as evidence for something else — OFBiz's `inventoryItemId` and `physicalInventoryId` appear only as
examples of the fixed-dimension-column anti-pattern; an "inventory receipt" appears only as an example of
posting fired from a commit hook; Zoho's inventory module appears only as a pricing-gate fact; Wafeq's
appears only as competitor scope. **There is no coverage of valuation methods, stock ledgers, landed cost,
reorder points or costing anywhere in 25,774 lines.**

So the verdicts below are reasoned from QAYD's position, not from comparative research, and they say so.

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| INV-01 | **Inventory valuation and a perpetual stock ledger** | FIFO/weighted-average costing with a stock ledger that posts to the GL | **`[UNKNOWN]`** — not studied. Zoho, Daftra, ERPNext and Wafeq all sell it `[DOCS]` | A Kuwaiti **trading** SME — the archetype in every example in this research — holds stock. Not having it excludes a large share of the beachhead | **Should-have (later)** | 21 | **Low** |
| INV-02 | **Landed cost allocation** | Freight, duty and clearing apportioned into item cost | `[UNKNOWN]` | Highly relevant to a GCC import business, and it is a genuine accounting problem rather than a warehouse one | **Nice-to-have** | 13 | Low |
| INV-03 | **Warehouses, batch and serial tracking** | Multi-location stock with lot traceability | Zoho ships it `[DOCS]` | Operational depth, not accounting depth | **Deliberately skip** | 0 | Medium |
| INV-04 | **Reorder points and stock alerts** | Replenishment signalling | `[UNKNOWN]` | Operations software, not a financial operating system | **Deliberately skip** | 0 | Medium |
| INV-05 | **POS** | Point of sale | Daftra ships it `[COMMUNITY]` | A different product with different hardware, latency and offline requirements | **Deliberately skip** | 0 | High |
| INV-06 | **Warehouse management (WMS)** | Bin locations, picking, putaway | SAP, NetSuite | *"It is how you build 1.85M lines of PHP with zero CHECK constraints"* | **Deliberately skip** | 0 | High |

**The honest position on INV-01.** It is the only Should-have in this category and its confidence is
**Low**, because the evidence to size it does not exist. **The right move is not to build it and not to
promise it — it is to close the `[UNKNOWN]` in the ten practitioner conversations** (`GLOBAL_GAP_ANALYSIS.md`
M-03): ask what proportion of their clients hold stock, and what they use today. If the answer is "most,
and Tally", INV-01 moves up and this document is wrong to defer it.

---

# 8. Manufacturing — the hard look

## 8.1 The verdict, stated before the table

> **QAYD should never build manufacturing. Not in a later phase, not as an edition, not with a partner's
> code. It is the clearest permanent skip in this document.**

Four arguments, in ascending order of force.

**1 · The research has nothing, and that is itself informative.** The two manufacturing-led vendors
examined — Infor and Epicor — yielded **no verifiable architectural evidence at all**. The ERP research
records: *"This section is short because the evidence is absent, and a longer section would be
invention."* Even the question that would matter most — whether Infor's acquired lines (Lawson, Baan,
SyteLine) share a financial core — **has no public answer**. Manufacturing ERP is sold, not documented.

**2 · It is a different product with a different buyer.** MRP, BOMs, routings, work orders, shop-floor
data collection and capacity planning are *operations* software. The buyer is a plant manager, the
integration surface is machinery, and the failure mode is a stopped line rather than a wrong number.
**Nothing QAYD is good at transfers.**

**3 · It is the exact mechanism by which ERP codebases become unmaintainable.** `06` §4.3 names full ERP
scope — manufacturing, WMS, HR, CRM — as a trap and gives the mechanism: *"It is how you build 1.85M lines
of PHP with zero CHECK constraints."* And `../erp/` L-13 gives the counter-discipline: **feature breadth
does not require code bulk, but only if breadth is chosen deliberately.**

**4 · Kuwait's SME economy is not the market for it.** The archetype throughout this research is a
**trading** company — import, hold, distribute — not a manufacturer. Every worked example in the corpus is
shaped that way.

## 8.2 The table, for completeness

| # | Feature | Who sells it | Verdict | Conf. |
|---|---|---|---|:--:|
| MFG-01 | Bills of materials and routings | Odoo, ERPNext, SAP, Infor, Epicor | **Deliberately skip — permanently** | High |
| MFG-02 | MRP / production planning | SAP, Infor, Epicor | **Deliberately skip — permanently** | High |
| MFG-03 | Work orders and shop-floor control | Epicor Kinetic, ERPNext | **Deliberately skip — permanently** | High |
| MFG-04 | Capacity and production scheduling | SAP, Infor | **Deliberately skip — permanently** | High |
| MFG-05 | Standard costing and variance analysis | SAP | **Deliberately skip**, with one caveat below | Medium |

**The one caveat, recorded so the skip is not lazy.** MFG-05 is the only entry with a genuine accounting
core — cost variances are journal entries. **If QAYD ever meets a manufacturing prospect, the answer is a
journal-entry integration from their existing MRP, not a manufacturing module.** That is ERP-05's
document-shaped write surface doing its job, and it costs nothing to hold open.

---

# 9. CRM — the hard look

## 9.1 The verdict

> **Skip CRM as a product. Build the two pieces of it that are actually accounting.**

The distinction is sharp and worth stating precisely: **a counterparty record is accounting; a sales
pipeline is not.** QAYD needs to know who it owes and who owes it, in what capacity, in which language, at
what ageing. It does not need opportunities, stages, forecasts, activities or lead scoring — and the
moment it has them it is competing with a category it has no advantage in, against products the customer
already owns.

## 9.2 The table

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| CRM-01 | **Counterparty records with roles and capacity** | ERP-01 and ERP-02, from the accounting side | **OFBiz** `[CODE]`; Intacct exposes 13 standard dimensions including Customer, Vendor, Employee `[DOCS]` | This is not CRM, it is the party model. It is a **Must-have** because related-party disclosure depends on it | **Must-have** | (see ERP-01) | High |
| CRM-02 | **Per-contact language driving document output** | The contact's language selects the Arabic or English template | **Zoho** `[DOCS]` | Directly serves bilingual AR/EN output. Small, cheap, and it is the difference between "we have Arabic" and "our customers receive Arabic" | **Must-have** | 2 | High |
| CRM-03 | **AR collections / dunning** | Overdue balances, payment-delay patterns, bulk reminders, risk flagging | **Zoho Zia Invoice Agent** (GA) `[DOCS]`; Tryton ships dunning as a module | **The only collections-AI capability verified as GA anywhere in the research.** It is genuinely accounting — it operates on the AR ledger — and it is a legible AI win | **Should-have** | 8 | Medium |
| CRM-04 | **A client portal** | Customers view and pay invoices | **FreshBooks** — best-in-class time-to-first-invoice `[DOCS]` | Real value, and it is downstream of invoicing, which is not in the minimum credible product | **Nice-to-have** | 8 | Medium |
| CRM-05 | **Sales pipeline, opportunities, stages, forecasting** | CRM proper | Daftra bundles it; Odoo, D365 | A different product for a different buyer. **The customer already has WhatsApp and a spreadsheet, and displacing that is not a fight worth having** `[INFERENCE]` | **Deliberately skip** | 0 | High |
| CRM-06 | **Marketing automation, campaigns, lead scoring** | — | Odoo, D365 | Not adjacent to a financial operating system by any reading | **Deliberately skip** | 0 | High |
| CRM-07 | **Helpdesk / ticketing** | — | Odoo, Zoho | Same | **Deliberately skip** | 0 | High |

**A note on why CRM-03 is a Should-have and not a skip.** It sits on the boundary deliberately. Dunning
operates on the AR ledger, uses payment-history data QAYD already holds, produces a measurable outcome
(days-sales-outstanding), and is **the kind of AI capability that removes labour a Kuwaiti SME actually
performs** — chasing payment. It is the one CRM-adjacent capability that passes the test in
`competitive/BEST_PRACTICES.md` §1.1: it is priced against labour, and the labour is real.

---

# 10. HR & payroll — the hard look

## 10.1 The verdict, and the finding that complicates it

> **Skip HR and payroll as product. But answer the question, because it is asked in every Kuwaiti deal —
> and this is the one skip in the document with a genuine unresolved tension.**

**The tension, stated plainly.** `competitive/OVERVIEW.md` §5.1 records four requirements that recur
across Kuwaiti implementer marketing: no VAT; **KWD to three decimals**; **PIFSS social-security
contribution calculation and Kuwait Labour Law end-of-service indemnity**; and **Kuwaitization quotas and
commercial-licence management**. **Two of those four are payroll and HR.** They appear in every local ERP
pitch.

**And the research contains nothing on any of them.** A grep across the corpus returns **zero** results for
PIFSS, social security, end-of-service, indemnity or WPS. This is not a search failure — it is a genuine
white space in the Phase-3 programme, and it is the largest single gap in the evidence base.

## 10.2 Why the verdict is still skip

**1 · Payroll is a compliance product with a per-jurisdiction maintenance obligation** — exactly the
category `06` §4.3 identifies as a trap for a small team, and exactly what ERP-15 refuses.

**2 · Payroll products get retired.** **IRIS KashFlow Payroll reached end of life on 5 April 2026 — *"no
longer maintained, supported, or accessible"*** `[DOCS]`. An SME's payroll is decade-horizon
infrastructure and QAYD cannot credibly promise that horizon.

**3 · The regional pattern is already partnership.** **Zoho's Saudi payroll is offered by *partners*, not
by the product** `[COMMUNITY]`. The category's most GCC-present vendor reached the same conclusion.

**4 · Getting it wrong is a legal exposure, not a bug.** An indemnity calculation that is wrong on
termination is a labour-court matter.

## 10.3 What the skip obliges QAYD to do instead

**A skip is not silence.** `competitive/OVERVIEW.md` §5.1 says it directly: the presence of these items in
every local pitch *"is exactly why that decision must be made consciously and answered with a partnership
or an export, not with silence."*

The obligation is three things, and all three are cheap:

1. **A payroll journal import** — a defined CSV/API shape that turns any payroll provider's output into a
   drafted journal entry. **This is ERP-05's document-shaped write surface applied to payroll**, and it is
   the honest answer to *"do you do payroll?"*: *"No. We post yours in one step."*
2. **A named partner**, or an explicit "we integrate with what you use".
3. **The employee dimension** — so payroll cost is analysable by department, project and branch without
   QAYD computing a single salary.

## 10.4 The table

| # | Feature | What it is | Who does it best | Verdict | Effort | Conf. |
|---|---|---|---|---|:--:|:--:|
| HR-01 | **Payroll journal import → drafted entry** | The integration, not the payroll | Nobody notably | **Should-have** | 5 | High |
| HR-02 | **Employee as an accounting dimension** | `EMPLOYEEID` as a standard dimension | **Sage Intacct** `[DOCS]` | **Nice-to-have** | 2 | High |
| HR-03 | **PIFSS contribution calculation** | Kuwaiti social security | `[UNKNOWN]` — **absent from the entire corpus**; local integrators market it `[COMMUNITY]` | **Deliberately skip — partner or import** | 0 | Medium |
| HR-04 | **Kuwait Labour Law end-of-service indemnity** | Accrual and settlement | `[UNKNOWN]` — same | **Deliberately skip**, with the caveat in §10.5 | 0 | Medium |
| HR-05 | **Kuwaitization quota and commercial-licence tracking** | Regulatory headcount compliance | `[UNKNOWN]` — local integrators `[COMMUNITY]` | **Deliberately skip** | 0 | Medium |
| HR-06 | **Full payroll processing and WPS filing** | Salary runs, bank files | Regional providers; Zoho via partners `[COMMUNITY]` | **Deliberately skip — permanently** | 0 | High |
| HR-07 | **HRMS (leave, attendance, recruitment, appraisals)** | — | Odoo, Zoho, Daftra | **Deliberately skip — permanently** | 0 | High |

## 10.5 The one caveat on HR-04, recorded honestly

**End-of-service indemnity is an accrual, and an accrual is accounting.** A Kuwaiti company's indemnity
liability belongs on its balance sheet whether or not QAYD runs payroll. **If a later phase revisits any
part of this category, HR-04's *accrual* — not its *calculation* — is the defensible entry point**: a
recurring provision entry with a computed liability, sourced from the payroll provider's numbers.

That distinction is the general principle for all four skipped categories: **QAYD may account for
anything; it should operate almost nothing.**

# 11. Analytics

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| ANL-01 | **`pg_stat_statements` + per-report and per-tenant telemetry** | The measurement substrate | PostgreSQL `[DOCS]` | **The scaling triggers in the architecture plan are unmeasurable today.** *"An alert that cannot fire is a plan, not a control."* And `pg_stat_statements` **normalises by query text**, so one whale's 8-second report is averaged into 9,999 fast ones — the p95 threshold would never fire while a customer times out. **The cheapest item in the entire research programme by an order of magnitude** | **Must-have** | 3 | High |
| ANL-02 | **Parquet as the partition-export format** | Closed period → detach → export → drop | Apache Parquet `[DOCS]`; DuckDB reads it directly | The export **has to happen anyway**; the format is free. Buys 5–15× compression `[INFERENCE — measure before quoting]`, a queryable archive, universality, and a free analytics substrate. **Sort by `(company_id, account_id, entry_date)` — free at export, expensive to fix later — and map `NUMERIC(19,4)` to `DECIMAL(19,4)`, never `DOUBLE`** | **Should-have** | 5 | High |
| ANL-03 | **A manifest with `ledger_head_hash`** | Row count, min/max date, `SUM(signed_base_amount)`, per-file SHA-256, and the chain head | **Iceberg's useful 5%, at effort 3 instead of 21** | Makes the archive **provable, not merely readable**. *"Iceberg lets you prove which version you read; the chain lets you prove the version was not altered."* Write the manifest **last**, after checksum verification, so its presence *is* the success signal | **Should-have** | 3 | High |
| ANL-04 | **DuckDB over Parquet, out of process, advisory only** | Internal product analytics with zero infrastructure | **DuckDB** `[DOCS]` | Cohort retention, feature adoption and **AI cost-to-serve** — the numbers that decide whether QAYD is a good business. Two hard boundaries: **never in-process**, and **no tenant-facing figure is ever computed there** | **Should-have** | 3 (+5) | High |
| ANL-05 | **BRIN on `posted_at`, scoped to maintenance scans** | A few MB against ~9 GB for the btree equivalent | PostgreSQL `[DOCS]` | **BRIN cannot prune by `company_id`** — tenants interleave, so every 128-page range spans the id space — and every tenant-facing query filters `company_id` first. It serves hash-chain verification, drift scans, archival selection and backfills. **Document the scope where the index is created, or it gets cited as "we tried indexing and it didn't help"** | **Must-have** | 1 | High |
| ANL-06 | **Per-tenant AI cost attribution** | Tenant, capability, model tier, input/output/cache-read tokens, batched flag | Nobody notably | **Cannot be backfilled** — token counts exist only at the moment of the call | **Must-have** | 5 | High |
| ANL-07 | **A covering index for the trial balance** | `(company_id, fiscal_year_id, account_id) INCLUDE (signed_base_amount)` — ~22 B/row vs ~250–273 B/row | PostgreSQL index-only scan `[DOCS]` | **The trap nobody documents:** the visibility map is maintained by VACUUM, and an **insert-only table produces no dead tuples**, so autovacuum may never run. **Verify `Heap Fetches: 0` and tune `autovacuum_vacuum_insert_threshold`** — the difference between the index working and appearing not to | **Must-have** | 2 | High |
| ANL-08 | **A retained, listable event history** | Not only a broadcast | Column `/api/events`; Increase (30-day retention) `[DOCS]` | The webhook is the optimisation; **the queryable history is the correctness backstop** — and it makes the outbox useful for debugging, not only for delivery | **Should-have** | 3 | High |
| ANL-09 | **Kafka** | An event backbone | Confluent | QAYD emits **~13 events/s average and ~100/s peak** at Tier 3; **Kafka's design point is 10⁵–10⁶/s.** And an accounting system's event volume is **bounded by human business activity** — an architectural ceiling, not a current measurement. It also reintroduces the dual-write problem the outbox exists to solve | **Deliberately skip** | 0 | High |
| ANL-10 | **A warehouse (Snowflake / BigQuery)** | Self-service SQL over a large dataset | Snowflake `[DOCS]` | The justifying condition is **organisational, not technical** — non-engineers needing self-service SQL, plus a data team to keep it honest. QAYD has neither. Decisively: it is **a second copy of the money, outside RLS, outside the append-only trigger, outside PITR alignment** | **Deliberately skip** | 0 | High |
| ANL-11 | **Druid / Pinot** | Real-time OLAP | Apache | **Druid's ingestion-time rollup discards raw rows** — irreversible aggregation. *"An accounting system may never discard a row to make a report faster."* Keep only Pinot's **budgeted partial cube** idea: storage grows with the combinations you *declare*, not 2ⁿ | **Deliberately skip** | 0 | High |
| ANL-12 | **Materialised views over tenant-scoped money** | Refreshed aggregates | PostgreSQL | **A correctness objection, not a performance one, which is why it stays rejected.** The view is populated in the refresher's GUC context: **empty under fail-closed RLS, or cross-tenant under a bypassing role — and RLS is a table feature, so the result cannot be re-protected afterwards** | **Deliberately skip** | 0 | High |
| ANL-13 | **`pg_duckdb` / in-database analytical engines** | Columnar execution inside PostgreSQL | — | An alternative scan path over tenant tables is an **RLS surface to audit, inside the database that holds the ledger** — plus an unbounded analytical query inside a database backend. The benefit is free out of process (ANL-04) | **Deliberately skip** | 0 | High |
| ANL-14 | **TimescaleDB continuous aggregates** | Incrementally refreshed rollups | Timescale `[DOCS]` | The closest off-the-shelf match to `account_period_balances` and **still a downgrade**: refresh is driven by a background policy (eventual) rather than the posting transaction (exact). **For a trial balance, eventual is not a weaker guarantee — it is a wrong number.** Take the invalidation-range idea (ACC-29); leave the extension | **Deliberately skip** | 0 | High |

---

# 12. Automation

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| AUT-01 | **Rules as a small, closed, ordered predicate language** | Explainable, bulk-evaluable, conflict-resolvable — **never `eval`, never user code** | Category-wide, done well `[DOCS]` | The deterministic floor. A CHECK-constrained JSONB selector compiled through an allowlist, so **an agent's proposal is something a human can read and approve rather than opaque SQL** | **Must-have** | 8 | High |
| AUT-02 | **Machine-proposed rules, human-approved** | The machine notices the pattern and proposes the predicate in the same inspectable form | **Nobody** — the category has humans author and machines execute | Same artefact, inverted authorship. **Strictly safer than the category's auto-apply rules because the proposal is recorded with its rationale** — and it ships genuine novelty inside a container the market has already validated | **Should-have** | 8 | Medium |
| AUT-03 | **Bulk coding, keyboard-first** | 400 lines without 400 round trips | Category-wide `[DOCS]` | **The bookkeeper with 400 lines decides adoption, not the owner with 4.** One transaction, one posting-service call per entry, all-or-nothing | **Must-have** | 8 | High |
| AUT-04 | **Bulk recorded *as* bulk** | A distinguishable approval mode, excluded from the accuracy estimate | Nobody | Bulk approve is the fastest path to 99% approval with every downstream metric quietly poisoned | **Must-have** | 2 | High |
| AUT-05 | **Document capture with its own inbound channel, generously metered** | Email, mobile, watched folder | Category-wide `[DOCS]` | Capture happens away from the desk. **And metering the input to an AI product suppresses the behaviour the product needs** — Zoho's 200 scans/month is a real constraint `[DOCS]` | **Must-have** | 8 | High |
| AUT-06 | **Isolated context window for untrusted fetched content** | The document's prose never shares a window with the decision step | **Claude Code** — *"Web fetch uses a separate context window to avoid injecting potentially malicious prompts"* `[DOCS]` | **The ingested corpus is documents authored by parties with a direct financial interest in the resulting entry. This is not a hypothetical adversary; it is a counterparty** | **Must-have** | 3 | High |
| AUT-07 | **Deny-first rule precedence** | Deny, then ask, then allow — specificity does **not** change the order | **Claude Code** `[DOCS]` | **Specificity-based (CSS-model) precedence is unsafe for permissions**: a narrow allow must never beat a broad deny | **Should-have** | 2 | High |
| AUT-08 | **Poka-yoke schemas: a constrained value space** | An account id from an enum of the tenant's postable accounts, never a free-text name | Anthropic tool guidance — *"change the arguments so that it is harder to make mistakes"* `[DOCS]` | Five lines of code; *"the difference between a system with a knowable input distribution and one without"* | **Must-have** | 2 | High |
| AUT-09 | **A completeness measure — what is *missing*** | Unentered documents, missing recurring entries, no depreciation this period, a monthly supplier who has not billed | **Nobody** | The category's signal is an empty review queue, which measures the vendor's ingestion, not the customer's books. **Real work an agent can do that no rules engine in the category does — and a demo no incumbent can currently give** | **Should-have** | 8 | Medium-High |
| AUT-10 | **A nightly job that *verifies* state** | The integrity job, and nothing else | — | **Banking batches to *produce* state; QAYD should batch only to *verify* it.** A nightly window is a place for failures to hide — a job that failed at 02:00, noticed at 09:00, with the day's work built on wrong numbers | **Should-have** | 3 | High |
| AUT-11 | **Rules that post silently** | Auto-apply without a record | Category-wide `[DOCS]` | *"The rule was right when written — the world changed, not the rule."* Silent posting removes the moment at which that is noticeable | **Deliberately skip** | 0 | High |
| AUT-12 | **A nightly batch that produces state** | End-of-day consolidation | Banking cores | ANL/AUT-10's inverse. Even Vault, a real-time cloud-native core, still consolidates end-of-day — but **real-time posting does not abolish the daily boundary, it relocates it to the reporting layer**, which is where QAYD should keep it | **Deliberately skip** | 0 | High |
| AUT-13 | **Autonomous correction by the Challenger** | Findings that fix themselves | — | **Never — findings, not fixes.** A human investigates and, if warranted, reverses through the normal path | **Deliberately skip** | 0 | High |

---

# 13. Security

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| SEC-01 | **RLS with `ENABLE` + `FORCE` + a named RESTRICTIVE policy** | Tenancy as a database property | **QAYD** `[CODE]` — every other shared-database system in Phase 2 enforces tenancy in application code | The decisive argument is not that RLS is more secure in the ideal case — **it is that the failure modes are opposite**: a forgotten `where` returns everything; a missing policy returns nothing | **Must-have** | Built | High |
| SEC-02 | **A runtime role that is `NOSUPERUSER NOBYPASSRLS`** | The credential cannot escape the boundary | **QAYD** `[CODE]` | *"This one line is what converts SQL injection from total compromise into a tenant-scoped bug. It is the highest-leverage line in the codebase"* | **Must-have** | Built | High |
| SEC-03 | **A catalog-introspection CI test** | Assert from `pg_class`/`pg_policy` that every tenant table has FORCE RLS and a RESTRICTIVE policy | **QAYD's `IM-07`** | Tenancy becomes *provable* rather than reviewed | **Must-have** | 3 | High |
| SEC-04 | **Composite uniques carrying `company_id`** | A modelling convention promoted to a security control | — | **Referential-integrity checks always bypass row security**, documented as a covert channel `[DOCS]`. A unique omitting `company_id` is a **cross-tenant existence oracle**: tenant B inserts, gets a violation, and has learned tenant A holds the value. **No RLS policy prevents it and no conventional tenancy test detects it** | **Must-have** | 3 | High |
| SEC-05 | **Adversarial, generated tenancy tests** | Tests that actively attempt the oracle and the bypass | Nobody | The current suite is **optimistic and hand-written per table.** The tell: *when did this suite last fail?* | **Must-have** | 8 | High |
| SEC-06 | **A missing tenant context must raise, not return empty** | Loud fail-closed | — | *"Every ambient-privilege bypass in every system began as a silently-empty result someone needed to fix."* A silently-empty result presents as a bug, and the fastest fix under pressure is a bypass | **Must-have** | 2 | High |
| SEC-07 | **`SET LOCAL` tested against a real pooler** | PgBouncer transaction mode, `default_pool_size = 1`, alternating tenants, plus the inverse test proving the test can fail | — | The hazard has three properties that jointly defeat discipline: invisible in single-connection testing, silent and correct-looking in failure, and **introduced by an infrastructure change rather than a code change.** *"No amount of documentation is in that person's path"* | **Must-have** | 5 | High |
| SEC-08 | **Write an audit row inside the posting transaction** | Coverage matched to consequence | — | **TD-16: posting calls neither `AuditLogger` nor writes a snapshot.** Draft edits are reversible and low-stakes; posting is irreversible. **And because the chain is planned to live in `audit_logs`, an unwritten audit row means the chain will not cover posting at all — the integrity mechanism would protect the trivia and not the ledger.** *"In a bank this would be a day-one finding"* | **Must-have** | 5 | High |
| SEC-09 | **Close the `audit_logs` platform-admin write hatch** | Remove `OR app_is_platform_admin()` from `WITH CHECK` unconditionally | — | **A read hatch for diagnostics is defensible; a write hatch on the audit table is not under any framing** — it means a platform session can author audit rows attributed to any tenant. **And its position inside a RESTRICTIVE policy makes it maximally bad**: an `OR` inside the mechanism whose entire purpose is to have no holes, which no later policy can close | **Must-have** | 3 | High |
| SEC-10 | **Hash chain over the ledger and the audit log** | Every column, `BEFORE INSERT`, cheap because the projection is append-only | **QAYD's design.** Odoo's chain is unkeyed with an empty-string genesis, Python-only, covers an allowlist omitting `amount_currency`, and has three context-keyed bypasses; Dolibarr's is well-engineered but covers invoice and payment *events*, never the ledger | **Sequencing is the finding: SEC-09 must close first, or the result is *a cryptographic guarantee of the integrity of a lie*.** And it must not delay SEC-08/RPT-03, which are worth more at a seventh of the cost | **Should-have** | 21 | High |
| SEC-11 | **An external anchor at period close** | A signed digest published to WORM storage or a confidential ledger | **Azure SQL Ledger** — automatic digest storage with a locked immutability policy `[DOCS]` | **A chain in a table the attacker can write to is tamper-evident only against an attacker who does not know it exists.** Without an anchor it is half a feature that reads like a whole one. The economical form: the close is already a low-frequency human-attested event | **Should-have** | 8 | High |
| SEC-12 | **The verification job** | The chain checked on a schedule | — | **The verification job is the control; the chain is only the data structure that makes it possible.** *"An unverified chain provides exactly the assurance of no chain, while being described as though it provides more"* | **Must-have** | 3 | High |
| SEC-13 | **Crypto-shredding boundary decided at schema-design time** | Erasable personal data under a per-subject key; erasure destroys the key, not the row | — | Resolves erasure-vs-immutability without weakening either: ciphertext stays, references stay, totals stay, the chain stays. **The one item in the security corpus whose cost of delay is genuinely superlinear — and no regulator has asked yet, which is exactly why it will be forgotten** | **Must-have** | 5 | High |
| SEC-14 | **MFA** | — | Everyone | **Weak today. Cheap to fix, and asked in every customer review** | **Must-have** | 3 | High |
| SEC-15 | **A written incident runbook + a tested restore** | With a stopwatch | — | **The CITRA 24-hour breach clock cannot be met by improvisation** (§14). And a restore that has never been tested is a hope | **Must-have** | 5 | High |
| SEC-16 | **A specific security page** | *"Tenant isolation is enforced by PostgreSQL row-level security under a database role that cannot bypass it"* | — | **Specificity beats a badge for a technical reviewer.** And the discipline: describe what is built, present tense, nothing else — *"diluting genuinely exceptional true claims with ordinary false ones is a bad trade in both directions"* | **Should-have** | 2 | High |
| SEC-17 | **CAIQ / SIG-Lite maintained as an artefact** | A completed questionnaire kept current | — | *"A cheaper intermediate step that works surprisingly well"* — many mid-market reviews are satisfied by a well-written specific document | **Should-have** | 3 | Medium |
| SEC-18 | **`perms_ver` for token revocation** | One mechanism serving permission-cache invalidation *and* logout | **QAYD already carries it** `[CODE]` | Better than the planned Redis `jti` denylist: no second mechanism to keep consistent, and bumping the version kills every outstanding token — **which is also what must happen on permission change, role removal and offboarding** | **Should-have** | 3 | Medium |
| SEC-19 | **Broad field-level encryption** | Encrypt sensitive columns | — | **Defeats no realistic adversary in a single-service architecture — the application holds the key** — while destroying indexing, sorting and aggregation, and directly conflicting with the requirement to aggregate money in the database. Correct scope is narrow: third-party credentials, bank tokens, national identifiers, plus the crypto-shredding set | **Deliberately skip (as a default)** | 0 | High |
| SEC-20 | **Vestigial policies and grants that contradict the trigger** | `ledger_entries_tenant_update` / `_delete` policies while the append-only trigger refuses both | — | **Three mechanisms currently describe two different tables.** Defence in depth requires the layers to *agree*; layers that disagree are not depth, they are a latent regression waiting for someone to drop the trigger during a migration | **Must-have (remove)** | 1 | High |

---

# 14. Compliance

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| CMP-01 | **The obligation as a first-class object** | Type, jurisdiction, period, due date, computed amount, evidence, filing state, lifecycle | **FreeAgent** files directly with the tax authority and keeps a forward-looking timeline `[DOCS]`; most of the category — **including Zoho for UAE corporate tax, whose own docs say returns are filed *"on the FTA portal"*** — produces a report a human carries elsewhere | Each regime becomes an adapter rather than a re-architecture. **Cheap insurance and correct design — and explicitly *not* a Kuwait wedge** | **Should-have** | 8 | High |
| CMP-02 | **Correct three-decimal handling as a compliance property** | Not display-only: validation, storage, arithmetic, rounding, tax base, FX, matching, statements, PDF, export | See ACC-11 | **A hard disqualifier for Kuwait, Bahrain and Oman when absent** | **Must-have** | (ACC-11) | High |
| CMP-03 | **Immutability as a fraud control the owner buys against their own staff** | Posted entries the staff cannot alter | **QAYD** `[CODE]` | **The T1 threat — insider alteration — is the highest-expected-loss threat for an accounting system**, and this is *"the strongest product-security claim QAYD has."* It is simultaneously a control and a feature, which is why it gets built and stays built | **Must-have** | Built | High |
| CMP-04 | **An audit trail that begins where the money does** | SEC-08 | — | *"The audit trail records everything"* is currently false at the most consequential transition | **Must-have** | (SEC-08) | High |
| CMP-05 | **A 24-hour breach-notification capability** | Pre-written runbook, pre-drafted notification, fast blast-radius determination | — | **CITRA requires notification to CITRA *and* to affected individuals within 24 hours** of awareness — materially tighter than GDPR's 72 — with penalties of **1–5 years' imprisonment and KWD 500–20,000** `[DOCS]`. Whether a B2B accounting SaaS without a CITRA licence is in scope is **`[UNKNOWN]` and is a question for Kuwaiti counsel — and it is not an expensive question to ask.** The operational consequence holds either way, and it is **an argument for finishing the audit trail before the first customer independent of any attestation** | **Must-have** | 5 | High |
| CMP-06 | **Data export as a compliance and trust artefact** | Chart of accounts, entries with both currency amounts at full decimal fidelity, matches as rows, audit trail, source documents, manifest with head hash | Nobody notably | *"Can we export our data if we leave?"* is **asked more often than any certification question, and answering it well wins trust disproportionately.** And **a pre-launch startup is a worse continuity risk than Intuit — which stopped new QuickBooks signups in India in July 2022 and ended the product in April 2023** `[DOCS]` | **Should-have** | 8 | High |
| CMP-07 | **A jurisdiction-agnostic privacy capability** | Data inventory, subject-access response, deletion, breach detection, transfer disclosure | — | The architectural requirements are the same small set everywhere; **jurisdictions are configuration.** The one expensive-to-reverse decision is **data residency**, which is a deployment-topology change, not a feature — and whether Saudi PDPL imposes hard localisation in the general case is `[UNKNOWN]` and **must not be assumed either way** | **Should-have** | 8 | Medium |
| CMP-08 | **OWASP ASVS L2 as a definition of done and a pen-test scope** | ~350 tagged requirements, released May 2025 | OWASP `[DOCS]` | *"The most useful single document here"* — a *verification* standard rather than a control catalogue. **L1 is the floor for any app; L3 is life-safety. L2 is the right target** | **Should-have** | 5 | High |
| CMP-09 | **PCI scope avoidance** | Redirect or a processor-hosted iframe. **Never a self-built card form, never "just for the enterprise plan", never temporarily for a demo** | PCI SSC `[DOCS]` | **The scope you want is *none*; scope avoidance is worth more than scope compliance.** And **the scoping problem survives the code being deleted.** The v4.0.1 revision widened the gap: SAQ A merchants are exempt from 6.4.3/11.6.1, **SAQ A-EP and above are not** — so the architectural choice made once at the start is worth more than it was | **Must-have** | 1 | High |
| CMP-10 | **ZATCA Phase 2 (Saudi e-invoicing)** | Signed XML, cryptographic stamps, QR, invoice hash chaining, real-time clearance through Fatoora | **Zoho** — approved Phase 2, in-Kingdom residency, from ~SAR 60/month `[DOCS]`. **QuickBooks does not appear in ZATCA's Solution Providers Directory** `[COMMUNITY]` | **The compliance work and the integrity work are the same work** — invoice hash chaining lands directly on SEC-10. But entering Saudi means challenging an accredited in-Kingdom incumbent | **Nice-to-have (not now)** | 21 | High |
| CMP-11 | **UAE e-invoicing (PINT AE / Peppol via an accredited ASP)** | Mandatory start 1 Jan 2027; VAT-registered SMEs by 31 Mar 2027 `[COMMUNITY]` | **Genuinely open** — Zoho reportedly does not natively generate PINT AE XML or transmit via Peppol, and its own `/ae/books/e-invoicing/` URL **404s** `[DOCS, by absence]` | **The FTA-accredited ASP layer is a partnership surface rather than a competitor** | **Nice-to-have** | 13 | Medium |
| CMP-12 | **Kuwait VAT / e-invoicing** | — | Nobody, because it does not exist | **No VAT law passed; ruled out before 2028** `[COMMUNITY]`. The DMTT (Decree-Law 157 of 2024, effective 1 Jan 2025) applies at **15% to MNE groups above €750m consolidated revenue** `[DOCS]` — roughly twenty Kuwaiti firms — and **creates no SME compliance product whatsoever.** Zoho's Kuwait VAT help path 404s, correctly | **Deliberately skip** | 0 | High |
| CMP-13 | **SOC 2** | A report by a CPA firm, not a certification | AICPA `[DOCS]` | **A Type II opines on operating effectiveness *over a period*; with zero customers there is nothing to observe.** All-in first year $30–60k — more than the entire remaining security backlog costs to close. *"An organisation that buys a SOC 2 before it has users is buying a document about a company that does not yet exist."* **Trigger: a named deal blocked on it, worth more than the audit** | **Deliberately skip (until triggered)** | 0 | High |
| CMP-14 | **ISO/IEC 27001** | Certifies an ISMS — a permanent management ritual — with Annex A's 93 controls in 4 themes as the set you select *from* | ISO `[DOCS]` | **Plausibly the more recognised instrument in GCC procurement**, where SOC 2 is a US CPA product. **`[UNKNOWN]`, and resolvable by asking three prospects — one email settles a $40,000 decision** | **Deliberately skip (until triggered)** | 0 | Medium |
| CMP-15 | **Adjacent ISO standards (27017, 27018, 27701)** | Cloud, PII-in-cloud, privacy extensions | ISO | **None are standalone; all extend a 27001 ISMS.** Buying them before 27001 is impossible and shortly after is *"usually a signal of a compliance function with a budget rather than a customer requirement"* | **Deliberately skip** | 0 | High |

---

# 15. Forecasting

| # | Feature | What it is | Who does it best | Why it matters | Verdict | Effort | Conf. |
|---|---|---|---|---|---|:--:|:--:|
| FCT-01 | **The obligation record** | Contracts, schedules and standing commitments as structured data | Nobody — *"they mostly do not exist in structured form anywhere in an SME"* | **This is the real payoff and the forecast is merely its first consumer.** It is a **data-capture** problem, and extracting obligations from documents is where an LLM *is* the right tool — **proposing structured obligations for human confirmation, never asserting them** | **Must-have (if forecasting is built at all)** | 21 | Low |
| FCT-02 | **Banded cash projection: committed / expected / speculative** | Three bands with different sources, owners and error behaviour | **Nobody** — forecast-vs-actual (MAPE) is standard FP&A practice, but **separating committed from expected from speculative as a structural property of the output is not found in any vendor** `[INFERENCE — absence, weakly evidenced]` | *"Do not build a better forecast. Build a forecast that declares its own epistemic status."* Band 1 is **not a forecast, it is arithmetic**. The honest framing: *"we are the only ones who tell you which part of the number is a fact"* | **Should-have** | 5 | Medium |
| FCT-03 | **Predictions stored as falsifiable claims, scored on a measurement date** | `{horizon, band, amount, range, made_at, measure_on}` | Nobody | **Emitting a prediction unrecorded is incoherent with the product.** The decay curve — *"Band 2 is within 5% at 14 days, 19% at 60 days, and beyond 75 days we stop claiming"* — requires stored historical predictions **a later entrant does not have** | **Should-have** | 8 | Medium |
| FCT-04 | **Band 2 as a deterministic model, not an LLM** | Recurring pattern + per-counterparty payment history | — | *"The customer who pays 38 days late, every time"* is arithmetic. **Never an LLM where a rule suffices** | **Should-have** | 5 | Medium |
| FCT-05 | **A naive seasonal baseline that can disable the model** | Publish both; **if naive wins three consecutive months, switch the model off and say so** | — | The discipline of shipping the metric that can kill your own feature | **Nice-to-have** | 3 | Medium |
| FCT-06 | **A single-number cash forecast** | The category standard | Float, Fathom, Futrli, Agicap, Pulse, Xero Analytics Plus, QuickBooks cash flow planner, Tesorio, HighRadius `[COMMUNITY]` | **Thoroughly commoditised — and only ~40% of organisations achieve high or good forecast accuracy, down 13 points from 53% in 2021** `[COMMUNITY]`, despite the software proliferating. **Do not render a single total by default** | **Deliberately skip** | 0 | Medium |
| FCT-07 | **Report forecasting / budgets / anomaly detection on reports** | Forward projection and outlier flags on standard reports | **Zoho Books** — GA, with cash-flow forecasting and budgets gated from Premium `[DOCS]` | Real, shipped, gated. Worth knowing; not worth racing | **Nice-to-have** | 8 | Medium |
| FCT-08 | **AI narrative cash-flow commentary** | Prose about the numbers | QuickBooks Intuit Assist — GA but **US-only** `[DOCS]` | Not available in the Gulf, and it is AI-25 in another costume | **Deliberately skip** | 0 | High |
| FCT-09 | **Demand planning, MRP, sales and inventory forecasting** | — | Not covered anywhere in the corpus | Operations forecasting. See §7 and §8 | **Deliberately skip** | 0 | High |

**The honest verdict on this whole category.** `../innovation/` rates the business value of banded
forecasting as *"moderate and mostly defensive"* — **cashflow is the most-requested SME finance feature and
its absence loses deals; its presence wins none.** The effort estimate (34 points total) is flagged as the
kind of data-capture work that *"reliably goes worse than planned"*. **Recommendation: skip the whole
category for the MVP, and if it is ever built, build FCT-01 for its own sake and let FCT-02 fall out.**

---

# 16. The catalogue in summary

## 16.1 The 95 must-haves, by effort

The distribution is the useful part, and it is unexpectedly favourable.

| Effort | Count | What sits there |
|---|:--:|---|
| **Already built** | 13 | One write path · append-only trigger · exact money · gross+net constrained · idempotent projection · gapless numbering · RLS + `NOBYPASSRLS` · reversal-only correction · immutability as a fraud control |
| **1–2 points** | 20 | **AI-03 close the trigger on `UPDATE` (2)** · ACC-12 the 3-dp regression test (2) · ACC-31 per-currency CHECK (2) · SEC-20 remove vestigial grants (1) · ANL-05 BRIN (1) · SEC-06 raise on missing context (2) · AUT-04 bulk recorded as bulk (2) · AI-13 control-flow integrity (2) · CMP-09 PCI scope avoidance (1) |
| **3 points** | 21 | ACC-03 DB-enforced balance · ACC-22 GCC chart of accounts · RPT-02 the trial balance as an assertion · RPT-03 control totals · SEC-03 catalog-introspection test · SEC-04 composite uniques · SEC-12 the verification job |
| **5 points** | 19 | ACC-25 opening balances · SEC-08 audit the posting · SEC-13 crypto-shredding boundary · PAY-01 idempotency · AI-05 structured rationale · AI-10 extraction/reasoning separation |
| **8 points** | 12 | ACC-24 fiscal periods · ACC-27 the rollup · RPT-01 the statements · RPT-09 Arabic PDFs · AI-04 reviewed state · AI-06 instrumented approval |
| **13+ points** | 5 | BNK-01 per-bank adapters · BNK-07 reconciliation · AI-01 the proposal object · BNK-12 match ranking · SEC-10 the hash chain |
| **Cross-references** | 5 | Entries whose effort is carried by another line |

**Thirty-three of the ninety-five must-haves cost two points or less, and thirteen are already built.**
The expensive half is concentrated in exactly two places — statement ingestion and the proposal loop —
which are the two domination positions.

## 16.2 The 67 skips, grouped by mechanism of harm

| Mechanism | Count | Examples |
|---|:--:|---|
| **It is a different product for a different buyer** | 20 | All of manufacturing · most of HR · CRM proper · POS · WMS · helpdesk · marketing automation |
| **It destroys the moat** | 9 | Marketplace · user code · metadata schema · fine-tuning · autonomous posting · building on a competitor's API |
| **It is a second source of truth for money** | 8 | Warehouse · Kafka · vector store · materialised views · `pg_duckdb` · a purpose-built ledger DB · Druid |
| **The cost is unbounded and permanent** | 8 | Localisation breadth · consolidation · document splitting · full payroll · adjacent ISO standards |
| **It generalises a feature that does not exist** | 6 | Ledger phase model · third clock · fixed dimension columns · rules engine · workflow engine |
| **It is commoditised or the market moved** | 6 | Chat box · single-number forecast · 70 prebuilt reports · narrative commentary |
| **The precondition is absent in Kuwait** | 5 | Card issuing · bank feeds · Kuwait VAT · interchange-funded free |
| **It measures or protects the wrong thing** | 5 | Broad field encryption · pre-launch attestation · nightly state-producing batch · rules that post silently |

## 16.3 The five entries that would most change the plan

1. **AI-03 — close `trg_no_ai_autopost` on `UPDATE`. Effort 2.** The product's headline claim is currently
   true on `INSERT` and false on `UPDATE`.
2. **ACC-12 — the three-decimal regression test. Effort 2.** Converts an asserted advantage into a
   demonstrable one, and it is the cheapest domination position in the whole analysis.
3. **SEC-09 before SEC-10 — the write hatch before the chain.** Otherwise the chain is *a cryptographic
   guarantee of the integrity of a lie*.
4. **ACC-28 — the cross-period continuity assertion. Effort 3.** It prevents the single worst outcome
   available to this product: **a wrong balance sheet that nothing detects**, in a design that has not
   shipped yet.
5. **HR-01 — the payroll journal import. Effort 5.** It is the honest answer to a question asked in every
   Kuwaiti deal, and it costs a fraction of the module it replaces.

---

# 17. Three honest caveats about this document

## 17.1 The skips are load-bearing and some are unverified

Roughly a fifth of the sixty-seven skips carry `[UNKNOWN]` somewhere in their reasoning. **The four
categories with the hardest skips — inventory, manufacturing, CRM, HR — are also the four with the least
research**, and they hold seventeen of those sixty-seven.
That correlation should be treated as a risk, not as a convenience. §7's INV-01 and §10's HR-04 are the
two most likely to be wrong, and both name the cheap test that would settle them.

## 17.2 Effort estimates in the AI and forecasting categories are optimistic

`../innovation/` flags this explicitly for the obligation-extraction family: *"the kind of data-capture
work that reliably goes worse than planned."* The same caution applies to BNK-01 (per-bank adapters, sized
at 13) and CMP-10 (ZATCA, sized at 21) — both are estimates against `[UNKNOWN]` formats.

## 17.3 A catalogue is not a roadmap

**227 features, 95 of them must-haves, is not a plan — it is a map.** The plan is
`../accounting/LESSONS_FOR_QAYD.md` §7.4's **ten-item minimum credible product**, and the three domination
positions in `GLOBAL_GAP_ANALYSIS.md` §6. Everything in this document that is not in one of those two
places is **inventory, not intention.**

---

*No competitor source code was read for any closed product listed here; where `[CODE]` appears it refers to
open-source systems (OFBiz, Tryton, Odoo, ERPNext, Dolibarr, Akaunting) read by the sibling research, or to
QAYD's own repository. No pricing page, UI, document or marketing asset was reproduced. Every verdict is a
judgement with its reasoning attached, and every one resting on absent evidence says so.*

# End of Document
