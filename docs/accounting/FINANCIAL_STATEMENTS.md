# Financial Statements — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Financial Statements
---

# Purpose

The Financial Statements module is the terminal reporting layer of the QAYD Accounting Engine. It converts the immutable stream of posted `journal_lines` — routed through the `accounts` chart of accounts and its `account_types` classification — into the four primary statements required by IFRS and by GAAP-equivalent presentation: the Balance Sheet (Statement of Financial Position), the Income Statement (Statement of Profit or Loss), the Statement of Cash Flows, and the Statement of Changes in Equity, together with the Statement of Comprehensive Income and the accompanying Notes to Financial Statements.

This module owns no primary financial data. Every figure it renders is derived, at query time or via a materialized snapshot, from the General Ledger projection (`ledger_entries`) that itself is built exclusively from posted `journal_lines`. This is a hard architectural invariant carried over from the Accounting Engine's double-entry rules (see `DOUBLE-ENTRY ACCOUNTING RULES`, section 4 of the shared platform context): the Financial Statements module never accepts a manual override of a reported number without that override being expressed as a journal entry, an adjusting/reversing entry, or a formally versioned statement template mapping. There is no "type a number into a cell" mode; if a number is wrong, the fix is an accounting entry, not a report edit.

Concretely, the module is responsible for:

1. **Statement Generation** — producing the Balance Sheet, Income Statement, Cash Flow Statement, Statement of Changes in Equity, and Statement of Comprehensive Income for any company, branch, department, project, or consolidated group, for any fiscal period (monthly, quarterly, yearly, or custom date range), in any supported presentation currency.
2. **Template & Mapping Management** — maintaining a company-configurable mapping from `accounts` (Chart of Accounts, COA) to standardized statement line items (`financial_statement_templates` + `financial_statement_lines`), so that a company using industry-specific account naming still produces a standard IFRS-conformant Balance Sheet and Income Statement.
3. **Consolidation** — rolling up subsidiary financial statements into a consolidated group statement, performing intercompany eliminations and computing minority (non-controlling) interest.
4. **Multi-Currency Translation** — translating subsidiary or branch statements prepared in a functional currency into the group's presentation currency using the correct IFRS/GAAP translation method (current rate method for foreign operations, temporal method where functional currency ≠ local currency), and posting the resulting Cumulative Translation Adjustment (CTA) to Other Comprehensive Income (OCI).
5. **Comparative & Historical Reporting** — always capable of rendering a statement "as it was" for any closed prior period, and rendering side-by-side comparatives (prior year, prior period, budget-vs-actual).
6. **AI-Augmented Analysis** — feeding every generated statement through the AI Reporting Agent for ratio analysis, variance analysis, trend analysis, anomaly detection, and narrative (plain-language) executive summaries, all clearly labeled as AI-generated and carrying a confidence score.
7. **Export & Distribution** — rendering statements to PDF, Excel, CSV, and JSON, sharing them via signed links, and scheduling recurring generation and delivery (e.g., a CFO's monthly board pack).
8. **Auditability** — guaranteeing that every statement is reproducible byte-for-byte from its generation parameters (company, period, currency, template version, as-of posting cutoff) and that every access, export, and share is logged in `audit_logs`.

The module's output is consumed by: the CFO/Finance Manager dashboard (Next.js frontend), the AI Reporting Agent (FastAPI), external auditors (via scoped, time-limited share links), banks and regulators (via signed PDF/Excel exports), and downstream modules such as Tax (which reads Income Statement figures to compute tax provisions) and the Consolidation engine (which reads subsidiary statements to build group statements).

# Vision

QAYD's Financial Statements module exists to make "closing the books" a button, not a project. In a traditional Gulf SME or mid-market company, producing a compliant Balance Sheet and Income Statement is a multi-day manual exercise: an accountant exports a trial balance to Excel, manually maps accounts to statement lines, manually computes prior-period comparatives, and manually writes a one-page summary for the owner. Errors creep in at every hand-off, and the resulting statement is stale within a week.

The vision for this module is a single continuously-current source of financial truth:

- **Always current.** Because statements are derived on demand from posted `journal_lines`, a Balance Sheet generated at 09:00 and again at 09:05 (after someone posts a bank reconciliation) reflects the new posting immediately, with zero manual re-work. "Real-time financial statements" is not a marketing phrase here — it is the direct consequence of the derivation architecture.
- **Configurable but standardized.** Every company gets a default IFRS-conformant statement template (Balance Sheet in order of liquidity, Income Statement by nature or by function) that requires zero setup, while still allowing controllers to remap, reorder, and relabel statement lines to match local regulatory filing formats (e.g., Kuwait Ministry of Commerce filing templates, Saudi ZATCA-aligned formats) without touching the underlying Chart of Accounts.
- **Multi-entity from day one.** A Kuwait holding company with a Saudi subsidiary and a UAE branch should be able to view its own entity's statements, the subsidiary's statements in its own functional currency, and a fully consolidated, eliminated, currency-translated group statement — all from the same button, without a separate consolidation product.
- **AI as a co-pilot, never the author of record.** The AI Reporting Agent reads every generated statement and produces the ratio analysis, variance commentary, and narrative summary a human analyst would have spent a day writing — but it never adjusts a posted number, and every AI-authored sentence is traceable to the specific journal lines and accounts that produced it.
- **Audit-grade by construction.** Because a statement is a deterministic function of (company, period, template version, FX rate set, posting cutoff), any external auditor can be handed a URL that reproduces the exact numbers they are reviewing, with a full drill-down to the originating journal entry.

The long-term vision extends the module toward regulatory XBRL-style tagging (to support electronic filing with regional regulators as those requirements mature), rolling forecast statements (pro-forma Balance Sheet and Income Statement driven by the AI Forecast Agent), and a "close calendar" that turns month-end close into a tracked workflow with statement generation as its final, automatic step.

# Financial Reporting Philosophy

The module encodes a small number of non-negotiable principles that govern every downstream design decision:

**1. Statements are views, not stores.** No table in this module stores a Balance Sheet total or an Income Statement line as a primary fact. `financial_statement_snapshots` (defined in Database Design) exists purely as a performance cache and audit artifact — it is always reproducible by re-running the generation query against `ledger_entries`/`journal_lines` as of the same cutoff. If a snapshot and a live re-generation ever disagree for a *closed* period, that is a data-integrity bug to be investigated, never resolved by trusting the snapshot over the ledger.

**2. The accounting equation is the master invariant.** Assets = Liabilities + Equity, at every level: per company, per branch (where branch-level Balance Sheets are requested), and at the consolidated group level after eliminations. The Report Generation Engine validates this identity on every Balance Sheet it produces and refuses to present a statement that fails to balance — instead surfacing a diagnostic (see Validation Rules) rather than a silently wrong report.

**3. Every number is drillable.** A user looking at "Trade Receivables: KWD 142,500.00" on the Balance Sheet can always drill into the contributing sub-ledger balances (customers), then into the contributing journal entries, then into the source document (invoice, receipt) that created each entry. Aggregation never destroys lineage.

**4. Presentation is a mapping problem, not a data problem.** The Chart of Accounts is a company's operational account tree; the statement line items (Current Assets, Non-Current Liabilities, Revenue, Cost of Sales, etc.) are a separate, standardized taxonomy. The `financial_statement_templates`/`financial_statement_lines` mapping layer translates between the two, so that changing a statement's presentation format never requires touching the Chart of Accounts, and vice versa.

**5. Comparatives and history are first-class, not an afterthought.** Every statement generation call accepts a comparison period (prior year, prior period, budget) and every closed period remains permanently reconstructable. There is no "we only keep the last 12 months of statements" limitation — fiscal history persists for the life of the company record, subject only to the immutable soft-delete rules of the platform.

**6. AI analyzes; humans and policy decide.** The AI Reporting Agent's output (ratios, variance narrative, anomaly flags, executive summary) is always rendered in a visually distinct "AI Insights" panel attached to, but never merged into, the audited statement figures. AI never edits a statement line, never posts a journal entry, and never approves its own output for external distribution — an authorized human role does that (see Permissions).

**7. Multi-currency and multi-entity are the norm, not the edge case.** Given QAYD's Gulf-first, multi-market roadmap, every design decision in this module assumes a company will eventually operate a subsidiary or branch in a second currency and require consolidation. The single-currency, single-entity case is simply the n=1 case of the general model, not a separate code path.

# IFRS Support

QAYD's Financial Statements module is built to IFRS as its baseline reporting framework, reflecting the standard used across Kuwait, Saudi Arabia, the UAE, and the broader GCC (each jurisdiction's local GAAP is either IFRS or an IFRS-aligned local variant, e.g., IFRS as adopted for SMEs).

**Statement structure (IAS 1 — Presentation of Financial Statements).** The module implements the two required primary statement layouts specified by IAS 1:

- Balance Sheet in order of liquidity (current/non-current split), the default for QAYD companies, mapped via `financial_statement_lines.classification` = `current` | `non_current` for assets and liabilities.
- Income Statement by nature of expense (default) or by function of expense (cost of sales), selectable per company via `financial_statement_templates.expense_presentation`.
- Statement of Comprehensive Income, either as a single continuous statement (profit or loss followed immediately by OCI) or as two separate statements, controlled by `financial_statement_templates.oci_presentation_mode` (`single_statement` | `two_statements`).
- Statement of Changes in Equity showing, for each equity component (share capital, share premium, statutory reserve, voluntary reserve, retained earnings, OCI reserve, non-controlling interest), the opening balance, each movement (profit for the period, OCI items, dividends declared, share issuances, transfers to reserves), and the closing balance.
- Statement of Cash Flows using the indirect method by default (reconciling profit for the period to net cash from operating activities), with the direct method available per template configuration (`financial_statement_templates.cash_flow_method`).
- Notes to Financial Statements, generated from `financial_statement_notes` records tied to specific statement lines, satisfying IAS 1's disclosure requirements (accounting policies, significant judgments, disaggregation of line items).

**Revenue (IFRS 15).** Revenue recognition itself is performed upstream in the Sales module (`invoices`, `sales_orders`) at the point control transfers to the customer; this module's responsibility is correct presentation — gross revenue, net of returns/credit notes/discounts, disaggregated by the categories a company's `financial_statement_lines` configuration specifies (e.g., by product category or geography) to satisfy IFRS 15 disclosure requirements.

**Leases (IFRS 16).** Right-of-use assets and lease liabilities, once posted by the Fixed Assets/Lease sub-ledger, appear on the Balance Sheet as distinct line items (`right_of_use_assets` under non-current assets, `lease_liabilities_current`/`lease_liabilities_non_current`), and the related depreciation and interest expense flow through the Income Statement and the Cash Flow Statement's financing/operating split per IFRS 16 §50.

**Financial Instruments (IFRS 9).** Expected Credit Loss (ECL) provisions against trade receivables and other financial assets are posted as journal entries by the AI Fraud Detection/Credit Risk process upstream; this module presents the resulting allowance as a contra-asset reducing gross receivables, with the movement in the allowance disclosed in the Notes.

**Consolidation (IFRS 10, IFRS 3, IAS 21).** Group consolidation follows IFRS 10 (control-based consolidation scope), IFRS 3 (business combinations, where goodwill on acquisition is tracked as a distinct non-current asset), and IAS 21 (the current-rate method for translating a foreign operation's functional-currency statements into the group's presentation currency, with the resulting Cumulative Translation Adjustment recognized in OCI, not profit or loss).

**Fair Value (IFRS 13).** Fair value hierarchy (Level 1/2/3) tagging is supported on relevant Balance Sheet line items (investments, biological assets where applicable) via `financial_statement_lines.fair_value_level`, surfaced in the Notes.

**Segment Reporting (IFRS 8).** Where a company operates distinct operating segments (mapped to `departments` or `branches` acting as reportable segments), the module can generate a segment-disaggregated Income Statement and asset/liability schedule as a Note, driven by the same dimension tagging (`department_id`, `branch_id`, `project_id`) already present on `journal_lines`.

# GAAP Compatibility

While IFRS is the default framework, QAYD is built to remain usable by companies and auditors who report under, or need to reconcile to, US GAAP or another local GAAP variant, without maintaining a second ledger.

**Terminology mapping layer.** `financial_statement_templates.framework` accepts `IFRS` or `GAAP`; when set to `GAAP`, the rendering layer swaps IFRS-specific labels for GAAP-conventional ones at presentation time only (e.g., "Statement of Financial Position" → "Balance Sheet", "Statement of Profit or Loss" → "Income Statement", "Non-Current Assets" → "Long-Term Assets", "Trade and Other Receivables" → "Accounts Receivable"). This is a label dictionary (`financial_statement_terminology`), not a different data model — the underlying `financial_statement_lines` mapping and the posted journal data are identical under either framework.

**Key measurement differences handled explicitly:**

| Area | IFRS treatment (QAYD default) | GAAP-mode adjustment supported |
|---|---|---|
| Inventory costing | FIFO or Weighted Average (LIFO prohibited) | LIFO costing supported per `inventory_valuations.method = 'LIFO'` when `framework = GAAP`, with a LIFO reserve note |
| Development costs | Capitalized once technical/commercial feasibility criteria met (IAS 38) | Immediately expensed as incurred (ASC 730), toggled via `financial_statement_templates.capitalize_development_costs` |
| Extraordinary items | Prohibited as a separate statement line under IFRS | GAAP mode permits a distinct "Extraordinary Items, net of tax" line if `financial_statement_lines.is_extraordinary = true` |
| Balance Sheet order | Order of liquidity (current first) is standard | GAAP mode still presents current-first for a Classified Balance Sheet; no format change needed for US practice |
| Component depreciation | Mandatory component-based depreciation (IAS 16) | Not mandatory under GAAP; Fixed Assets module still applies it uniformly since QAYD's asset register is component-based by design — GAAP-mode companies simply inherit the more granular treatment |
| Reversal of write-downs | Permitted (except goodwill) under IAS 36 | Prohibited under ASC 360 — the module blocks a reversing journal against a previously impaired asset when `framework = GAAP` (see Business Rules) |

**Dual reporting.** A company preparing IFRS statements for local regulators and a GAAP reconciliation for a US-listed parent can maintain two `financial_statement_templates` records (one per framework) against the *same* Chart of Accounts and the *same* posted journal entries — the difference lives entirely in the mapping and terminology layer, never in a duplicated ledger. Where a genuine measurement difference exists (e.g., LIFO vs. FIFO inventory costing), the module supports a documented reconciling adjustment entry type (`journal_entries.entry_type = 'gaap_reconciliation'`) that is excluded from the IFRS-framework statement generation query and included only in the GAAP-framework one, keeping both statements internally consistent and independently reproducible.

# Financial Statements

This section defines the exact structure, computation, and source mapping for each of the six statement artifacts the module can produce. Every statement is generated by the Report Generation Engine (next section) from the same underlying query primitive: sum `journal_lines.debit`/`journal_lines.credit` (base-currency amounts, or transaction-currency amounts translated at the requested rate) for posted `journal_entries`, grouped by the `accounts` each line targets, then rolled up through `financial_statement_lines` into the standardized presentation hierarchy defined by the company's active `financial_statement_templates` row.

## Balance Sheet

The Balance Sheet (Statement of Financial Position) presents recognized assets, liabilities, and equity as of a single point in time — the last day of the requested period (`as_of_date`).

**Structure (order of liquidity, IFRS default):**

```
ASSETS
  Current Assets
    Cash and Cash Equivalents
    Short-Term Investments
    Trade and Other Receivables (net of ECL allowance)
    Inventories
    Prepaid Expenses
    Other Current Assets
  Total Current Assets

  Non-Current Assets
    Property, Plant and Equipment (net of accumulated depreciation)
    Right-of-Use Assets
    Intangible Assets and Goodwill
    Long-Term Investments
    Deferred Tax Assets
    Other Non-Current Assets
  Total Non-Current Assets

TOTAL ASSETS  =  Total Current Assets + Total Non-Current Assets

LIABILITIES
  Current Liabilities
    Trade and Other Payables
    Short-Term Borrowings
    Current Portion of Long-Term Debt
    Lease Liabilities (Current)
    Tax Payable
    Accrued Expenses
    Other Current Liabilities
  Total Current Liabilities

  Non-Current Liabilities
    Long-Term Borrowings
    Lease Liabilities (Non-Current)
    Deferred Tax Liabilities
    Provisions (End-of-Service Benefits, etc.)
    Other Non-Current Liabilities
  Total Non-Current Liabilities

TOTAL LIABILITIES  =  Total Current Liabilities + Total Non-Current Liabilities

EQUITY
  Share Capital
  Share Premium
  Statutory Reserve
  Voluntary Reserve
  Foreign Currency Translation Reserve (OCI)
  Retained Earnings
  Equity Attributable to Owners of the Parent
  Non-Controlling Interest
TOTAL EQUITY  =  Equity Attributable to Owners + Non-Controlling Interest

TOTAL LIABILITIES AND EQUITY  =  TOTAL LIABILITIES + TOTAL EQUITY
```

**Computation rule.** For each `financial_statement_lines` row of `statement_type = 'balance_sheet'`, the engine sums, over all `accounts` mapped to that line (via `financial_statement_lines.account_id` or `account_type_id` wildcard mapping), the *cumulative* posted balance from the account's normal-balance side from company inception (or from the last closed fiscal year for a "current year movement only" account like Retained Earnings) through `as_of_date`. Retained Earnings specifically equals the prior fiscal year-end Retained Earnings balance plus current-period Profit for the Period (pulled live from the Income Statement query) minus dividends declared in the current period — it is never separately posted as a running balance outside of the year-end closing entry.

**Balance identity.** `TOTAL ASSETS = TOTAL LIABILITIES + TOTAL EQUITY` is validated on every generation (see Validation Rules). If Retained Earnings is computed live (mid-year) rather than from a closing entry, this identity holds by construction because Retained Earnings is defined as the plug that absorbs current-period profit; if it does not hold, the root cause is always an unposted or unbalanced journal entry, and the engine reports the exact variance amount and the accounts contributing to it.

## Income Statement

The Income Statement (Statement of Profit or Loss) presents revenue, expenses, and resulting profit or loss for a *period* (`period_start` to `period_end`), never a point in time.

**Structure (by nature of expense, IFRS default):**

```
Revenue
  Gross Revenue
  Less: Sales Returns and Allowances
  Less: Sales Discounts
Net Revenue

Cost of Sales
Gross Profit  =  Net Revenue − Cost of Sales

Operating Expenses
  Selling and Distribution Expenses
  General and Administrative Expenses
  Depreciation and Amortization
  Expected Credit Loss Expense
Total Operating Expenses

Operating Profit (EBIT)  =  Gross Profit − Total Operating Expenses

Other Income and Expenses
  Finance Income
  Finance Costs
  Foreign Exchange Gain/(Loss)
  Other Non-Operating Income/(Expense)

Profit Before Tax  =  Operating Profit + Other Income and Expenses

Income Tax Expense

Profit for the Period  =  Profit Before Tax − Income Tax Expense

Attributable to:
  Owners of the Parent
  Non-Controlling Interest

Earnings Per Share (Basic / Diluted)
```

**Computation rule.** Every `accounts` row of `account_types.category IN ('revenue','expense')` accumulates only *within-period* movement (sum of `journal_lines` debit/credit dated between `period_start` and `period_end` inclusive) — never a cumulative balance, since revenue and expense accounts are closed to Retained Earnings at each fiscal year-end via a system-generated closing journal entry (`journal_entries.entry_type = 'year_end_close'`). Gross Profit, Operating Profit, Profit Before Tax, and Profit for the Period are all computed subtotal/total rows in `financial_statement_lines` (`is_subtotal = true`) with a formula expression rather than a direct account mapping (see Database Design, `financial_statement_lines.formula`).

**By-function alternative.** When `financial_statement_templates.expense_presentation = 'by_function'`, Operating Expenses are instead disaggregated into Cost of Sales, Distribution Costs, Administrative Expenses, and Other Operating Expenses directly on the face of the statement (rather than disclosed by nature in a Note), per IAS 1 §99–105. QAYD stores the same underlying account-level data either way; only the presentation grouping changes.

## Cash Flow Statement

The Statement of Cash Flows explains the movement in Cash and Cash Equivalents between the opening and closing Balance Sheet dates, split into Operating, Investing, and Financing activities.

**Indirect method structure (default):**

```
Cash Flows from Operating Activities
  Profit for the Period
  Adjustments for:
    Depreciation and Amortization
    Expected Credit Loss Expense
    Finance Costs
    Finance Income
    Foreign Exchange (Gain)/Loss, unrealized
    Gain/(Loss) on Disposal of Fixed Assets
  Operating Profit Before Working Capital Changes
  Changes in Working Capital:
    (Increase)/Decrease in Trade and Other Receivables
    (Increase)/Decrease in Inventories
    (Increase)/Decrease in Prepaid Expenses
    Increase/(Decrease) in Trade and Other Payables
    Increase/(Decrease) in Accrued Expenses
  Cash Generated from Operations
  Interest Paid
  Income Tax Paid
Net Cash from Operating Activities

Cash Flows from Investing Activities
  Purchase of Property, Plant and Equipment
  Proceeds from Disposal of Property, Plant and Equipment
  Purchase of Intangible Assets
  Purchase/(Maturity) of Investments
  Interest Received
Net Cash used in Investing Activities

Cash Flows from Financing Activities
  Proceeds from Borrowings
  Repayment of Borrowings
  Payment of Lease Liabilities
  Proceeds from Share Issuance
  Dividends Paid
Net Cash used in Financing Activities

Net Increase/(Decrease) in Cash and Cash Equivalents
Cash and Cash Equivalents at Beginning of Period
Effect of Exchange Rate Changes on Cash
Cash and Cash Equivalents at End of Period
```

**Computation rule.** The indirect method is derived entirely from two already-computed statements: it starts from the Income Statement's Profit for the Period, adds back the non-cash items already isolated at the account level (accounts flagged `accounts.is_non_cash_adjustment = true`, e.g., depreciation, ECL expense), and then computes each working-capital line as the *change* in the corresponding Balance Sheet balance between `period_start` and `period_end` (current-period closing balance minus prior-period closing balance, sign-adjusted per asset/liability convention). Investing and Financing activity lines are sourced directly from `journal_lines` tagged with a cash-flow-classifying `accounts.cash_flow_category` (`operating` | `investing` | `financing`) on the contra (non-cash) side of a cash-account-touching journal entry — e.g., a fixed-asset purchase entry (Dr. Property Plant & Equipment / Cr. Bank) is classified by the PP&E account's `cash_flow_category = 'investing'`.

**Direct method alternative.** When `financial_statement_templates.cash_flow_method = 'direct'`, Operating Activities instead lists actual cash receipts from customers and cash payments to suppliers/employees, computed by filtering `journal_lines` to entries that both (a) touch a `bank_accounts`-linked cash/bank GL account and (b) have a source-document reference to `invoices`, `receipts`, `bills`, or `payroll_items`. QAYD still reconciles the direct-method statement back to the indirect-method total as a mandatory disclosure Note, per IAS 7 §18–20.

## Statement of Changes in Equity

Presents, for each equity component, a roll-forward from the opening balance to the closing balance across every movement type in the period.

**Structure (columnar roll-forward):**

| Component | Opening Balance | Profit for the Period | OCI Items | Dividends Declared | Share Issuance | Transfer to Reserve | Closing Balance |
|---|---|---|---|---|---|---|---|
| Share Capital | X | — | — | — | X | — | X |
| Share Premium | X | — | — | — | X | — | X |
| Statutory Reserve | X | — | — | — | — | X | X |
| Voluntary Reserve | X | — | — | — | — | X | X |
| FX Translation Reserve | X | — | X | — | — | — | X |
| Retained Earnings | X | X | — | (X) | — | (X) | X |
| **Total — Owners of Parent** | **X** | **X** | **X** | **(X)** | **X** | **—** | **X** |
| Non-Controlling Interest | X | X | X | (X) | — | — | X |
| **TOTAL EQUITY** | **X** | **X** | **X** | **(X)** | **X** | **—** | **X** |

**Computation rule.** Each column is a query filtered to journal entries whose target equity account carries a specific `journal_entries.equity_movement_type` tag (`profit_allocation`, `oci_item`, `dividend`, `share_issuance`, `reserve_transfer`) posted within the period; the Opening and Closing Balance columns are the same cumulative-balance computation used for the Balance Sheet's equity section, evaluated at `period_start − 1 day` and `period_end` respectively. Statutory reserve transfers in Kuwait (typically 10% of net profit until the reserve reaches 50% of share capital, per the Commercial Companies Law) are posted as a system-suggested (AI-drafted, human-approved) year-end journal entry, never auto-posted.

## Comprehensive Income

The Statement of Comprehensive Income presents Profit for the Period plus Other Comprehensive Income (OCI) items — gains and losses recognized outside profit or loss under IFRS.

**Structure:**

```
Profit for the Period                                            X

Other Comprehensive Income
  Items that will be reclassified subsequently to profit or loss:
    Foreign Currency Translation Differences for Foreign Operations   X
    Cash Flow Hedge Reserve Movement                                  X
  Items that will not be reclassified to profit or loss:
    Remeasurement of Defined Benefit Obligations                      X
    Fair Value Gain/(Loss) on Equity Investments at FVOCI              X
  Income Tax relating to OCI Items                                    (X)
Total Other Comprehensive Income, net of tax                     X

Total Comprehensive Income for the Period  =  Profit for the Period + Total OCI     X

Attributable to:
  Owners of the Parent
  Non-Controlling Interest
```

**Presentation mode.** Controlled by `financial_statement_templates.oci_presentation_mode`: `single_statement` renders this immediately below Profit for the Period as one continuous statement; `two_statements` renders the Income Statement ending at Profit for the Period as its own document, with Comprehensive Income as a second, separate statement starting from that same Profit figure. Both modes query identical underlying data; only pagination/rendering differs.

**OCI computation rule.** OCI line items are sourced from `journal_lines` posted to accounts flagged `accounts.oci_component` (non-null: `translation_reserve`, `hedge_reserve`, `remeasurement_reserve`, `fvoci_reserve`), summed for within-period movement exactly like Income Statement lines, but routed to the Comprehensive Income statement and to the corresponding equity reserve on the Balance Sheet rather than to Retained Earnings.

## Notes to Financial Statements

Notes are structured, individually versioned disclosures attached to specific statement lines, satisfying IAS 1's requirement that the primary statements be read together with accompanying notes.

**Standard note set generated automatically for every statement run:**

1. **Note 1 — Corporate Information and Basis of Preparation**: legal name, country of incorporation, principal activity (from `companies`), statement of compliance with IFRS, functional and presentation currency, basis of measurement (historical cost unless a revaluation model is configured).
2. **Note 2 — Significant Accounting Policies**: revenue recognition policy, inventory costing method (from `inventory_valuations.method`), depreciation methods and useful lives (from `fixed_assets` configuration), foreign currency policy, financial instrument classification.
3. **Note 3 — Critical Accounting Judgments and Estimates**: ECL methodology and key assumptions, useful-life estimates, provision assumptions (e.g., end-of-service benefits), AI-flagged estimation-sensitive balances (surfaced by the AI Risk Detection responsibility).
4. **Note 4 — Segment Information**: disaggregation by `branches`/`departments` acting as reportable segments, where configured.
5. **Note 5 through N — Line-Item Disaggregation Notes**: one note per material Balance Sheet or Income Statement line requiring disclosure (e.g., "Trade and Other Receivables" note showing gross receivables, ECL allowance movement, and an ageing schedule sourced from `customers`/`invoices` ageing buckets; "Property, Plant and Equipment" note showing a full roll-forward by asset class sourced from the Fixed Assets sub-ledger; "Related Party Transactions" note sourced from `journal_entries` tagged with a related-party counterparty).
6. **Note — Subsequent Events**: any material journal entry posted after `period_end` but before the statement's `generated_at` timestamp, auto-flagged by the engine for controller review and manual note authoring.
7. **Note — Contingencies and Commitments**: manually maintained free-text/structured entries (`financial_statement_notes` with no automatic account mapping) for items not yet recognized in the ledger (e.g., pending litigation, capital commitments).

Each note is stored as a `financial_statement_notes` row (see Database Design) referencing the `financial_statement_lines.id` it discloses, a note number, a title, structured JSON content (tables, roll-forwards) and/or free narrative text, and a version/approval state so that notes go through the same draft → reviewed → approved workflow as the statements they accompany.

# Report Generation Engine

The Report Generation Engine is the Laravel service layer (`App\Services\Accounting\FinancialStatementService`) that turns a generation request (company, statement type, period, currency, scope, template) into a rendered statement. It is invoked synchronously for on-demand generation and asynchronously (queued via Redis) for scheduled or large consolidated runs.

**Core pipeline (every generation, regardless of mode):**

1. **Resolve scope** — company, optional branch/department/project filter, optional subsidiary set (consolidation).
2. **Resolve template** — the active `financial_statement_templates` row for (company, statement_type, framework); fall back to the QAYD system default template if the company has not customized one.
3. **Resolve period** — `period_start`/`period_end` (or `as_of_date` for the Balance Sheet) from the requested `fiscal_periods` reference or explicit custom dates.
4. **Resolve FX** — presentation currency and the `exchange_rates` snapshot to use (period-end spot rate for Balance Sheet items, period-average rate for Income Statement/Cash Flow items, per IAS 21).
5. **Query ledger** — aggregate `journal_lines` (via the `ledger_entries` projection for speed) grouped by account, filtered to `deleted_at IS NULL` and `journal_entries.status = 'posted'` with `journal_entries.entry_date <= cutoff`.
6. **Map to statement lines** — join aggregated account balances into `financial_statement_lines` per the resolved template, computing subtotal/total/formula rows bottom-up.
7. **Validate** — run the Validation Rules (balance identity, sign checks, completeness) against the assembled statement.
8. **Snapshot (if applicable)** — persist a `financial_statement_snapshots` row for closed-period or scheduled runs (immutable audit artifact); skip persistence for pure real-time preview calls.
9. **Enrich with AI** — asynchronously dispatch the AI Reporting Agent job to attach ratio/variance/narrative insights (never blocking the primary statement response).
10. **Return/Render** — JSON payload to the API caller; PDF/Excel/CSV rendering happens in a separate export step (see API).

## Real-Time

Real-time generation (`mode = 'live'`) always re-runs steps 1–7 against the current state of `ledger_entries` with no caching beyond a short-lived (60-second) Redis result cache keyed on `(company_id, statement_type, scope_hash, period_hash, currency, template_version)`. This is the default mode for the current open fiscal period, where balances change intra-day as new entries post. Real-time statements are never persisted as a snapshot (they are reproducible by definition) but their *generation request* and *result hash* are still written to `audit_logs` for traceability of who viewed what, when.

## Historical

Historical generation (`mode = 'historical'`) targets a `fiscal_periods` row with `status = 'closed'`. For closed periods, the engine first checks `financial_statement_snapshots` for a matching (company, statement_type, period, template_version, currency) snapshot; if found, it is returned directly (guaranteeing the closed-period statement never silently drifts even if, hypothetically, a correcting entry were later posted with a back-dated period — which the platform prevents structurally, see Business Rules). If no snapshot exists yet (first request for that period), the engine generates it live once and persists the result as a snapshot before returning it.

## Comparative

Comparative generation attaches one or more comparison columns to the primary statement: prior period, prior year same period, budget (from a `report_definitions`-linked budget dataset), or a named custom period. Each comparison column is generated via the identical pipeline (steps 1–7) against its own period/cutoff, then merged into the primary statement's line array as `{ line_id, current_value, comparison_value, variance_amount, variance_percent }`. Variance is computed as `current_value - comparison_value` for all lines, with `variance_percent = variance_amount / ABS(comparison_value)` guarded against division by zero (returns `null` when the comparison base is zero).

## Consolidated

Consolidated generation aggregates a parent company and its subsidiary set (from `companies.parent_company_id` and ownership percentage) into a single group statement. This mode invokes the full Consolidation pipeline (see the dedicated Consolidation section below) as a pre-step: translate each subsidiary's statement to group presentation currency, sum line-by-line with the parent, eliminate intercompany balances/transactions, then compute and present non-controlling interest. Consolidated statements are always generated as a distinct `financial_statement_snapshots` row tagged `is_consolidated = true`, since a consolidation run is materially more expensive than a single-entity run and its elimination inputs (intercompany reconciliation) are a controlled, reviewed step, not a pure real-time query.

## Branch

Branch-scoped generation filters the underlying `journal_lines` to a specific `branch_id` before aggregation, producing a Balance Sheet/Income Statement for that branch alone. Because `branch_id` is nullable on `journal_lines` (some head-office-only transactions, like share capital movements, are never branch-attributed), branch statements carry an explicit "Unallocated / Head Office" line for any statement line that cannot be attributed, keeping the branch statement internally consistent even though branch-level statements do not need to independently satisfy the Balance Sheet identity (a design choice documented in Edge Cases).

## Department

Department-scoped generation follows the same filter pattern as Branch, using `department_id`. This mode is primarily used for Income Statement generation (departmental P&L, e.g., cost-center performance reporting) rather than full Balance Sheets, since assets/liabilities are rarely departmentally attributed. `financial_statement_templates.department_scope_mode` can restrict department-scoped requests to Income-Statement-only, returning a 422 validation error if a caller requests a department-scoped Balance Sheet against a template configured that way.

## Project

Project-scoped generation filters by `project_id`, most commonly used for project profitability statements (project revenue, project cost of sales, project margin) requested by a Project Manager or CFO reviewing a specific `projects` record. Project-scoped statements support the same comparative and real-time modes as company-level statements, and additionally support a project-to-date (inception-to-date) period type in addition to the standard monthly/quarterly/yearly periods, since a project's relevant reporting horizon is its own lifecycle rather than the fiscal calendar.

# Data Sources

Every figure in every statement is ultimately sourced from one of the following eight upstream data domains. This module reads from them; it never writes back except to its own reporting tables (snapshots, notes, templates).

## General Ledger

The primary source. `ledger_entries` is the posted-lines projection (materialized for query speed, rebuilt or incrementally updated whenever a `journal_entries` row transitions to `posted`, `reversed`, or `voided`). Every statement query ultimately reduces to `SUM(ledger_entries.base_amount) WHERE company_id = ? AND account_id IN (...) AND entry_date BETWEEN ? AND ? AND deleted_at IS NULL`, grouped by `account_id` and optionally by `branch_id`/`department_id`/`project_id`/`cost_center_id`.

## Journal Entries

`journal_entries` + `journal_lines` are the ledger's source of truth; `ledger_entries` is derived from them. The Financial Statements module reads `journal_lines` directly (rather than only the projection) whenever it needs entry-level metadata not carried onto the projection — e.g., `journal_entries.equity_movement_type` for the Statement of Changes in Equity, or `journal_lines.cost_center_id`/`project_id` for dimensional slicing, or drill-down from a statement line to its constituent entries.

## Chart of Accounts

`accounts` + `account_types` define what each ledger balance *is* (asset/liability/equity/revenue/expense, debit-normal or credit-normal) and, via `accounts.cash_flow_category`, `accounts.oci_component`, `accounts.is_non_cash_adjustment`, how it participates in statement construction. The Chart of Accounts is also the anchor of the `financial_statement_lines` mapping (see Database Design) — every statement line either maps to one or more specific `accounts` rows or to an entire `account_types` category as a wildcard.

## Inventory

`inventory_items`, `stock_movements`, and `inventory_valuations` supply the Balance Sheet's Inventories line (current valuation per the company's costing method) and the Income Statement's Cost of Sales (via the perpetual or periodic COGS calculation the Inventory module posts as journal entries on each sale/adjustment). The Financial Statements module never recomputes inventory valuation itself — it trusts the journal entries the Inventory module has already posted, and its only direct read of inventory tables is for the Notes disclosure (inventory ageing, valuation method disclosure, write-down movement).

## Payroll

`payroll_runs`, `payroll_items`, `salary_components`, and `payslips` drive two statement areas: the Income Statement's staff cost lines (salaries, benefits, employer social security contributions, all posted as journal entries by the Payroll module on each payroll run) and the Balance Sheet's Provisions line (End-of-Service Benefits liability, accrued leave liability), whose movement is disclosed in a dedicated Note sourced from the payroll provision sub-ledger.

## Banking

`bank_accounts`, `bank_transactions`, and `bank_reconciliations` feed the Balance Sheet's Cash and Cash Equivalents line directly (reconciled bank balance, not just the GL bank account balance, with any reconciling items disclosed in the Cash and Cash Equivalents Note) and the Cash Flow Statement's cash movement lines and opening/closing cash balances.

## Tax

`tax_codes`, `tax_rates`, `tax_transactions`, and `tax_returns` supply the Income Statement's Income Tax Expense line, the Balance Sheet's Tax Payable/Receivable and Deferred Tax Asset/Liability lines, and the tax reconciliation Note (effective tax rate vs. statutory rate reconciliation) that IAS 12 requires.

## Fixed Assets

The Fixed Assets sub-ledger (asset register, depreciation schedules — tables owned by the Fixed Assets module, referenced here as `fixed_assets`, `fixed_asset_depreciation_schedules`, `fixed_asset_disposals`) supplies the Balance Sheet's Property, Plant and Equipment and Right-of-Use Assets lines, the Income Statement's Depreciation and Amortization expense, the Cash Flow Statement's investing-activity purchase/disposal lines, and the full PP&E roll-forward Note (opening cost, additions, disposals, depreciation charge, closing net book value, by asset class).

# Business Rules

1. **BR-01 — No statement without posted data.** A statement generation request for a period containing zero posted journal entries returns a valid, fully-zeroed statement structure (not an error), clearly labeled "No posted transactions for this period" in the response `meta`.
2. **BR-02 — Closed periods are immutable inputs.** A `fiscal_periods` row transitions to `closed` only when every `journal_entries` row dated within it is `posted` (no `draft` entries remain) and an authorized Finance Manager/CFO has run the period-close action. Once closed, no new `journal_entries` may be dated within that period; corrections use a reversing entry dated in a subsequent open period, referencing the original entry (`journal_entries.reverses_entry_id`).
3. **BR-03 — Retained Earnings is never directly posted mid-year.** Only the system-generated year-end closing entry may post to the Retained Earnings account; any user-drafted journal entry targeting that account is rejected by FormRequest validation with a 422 error directing the user to the correct reserve-transfer or dividend workflow.
4. **BR-04 — Statement templates are versioned, never edited in place.** Modifying a `financial_statement_templates`/`financial_statement_lines` mapping creates a new version row (`version` increments); every `financial_statement_snapshots` row records the exact `template_version` used, so historical statements always re-render with the mapping that was active when they were generated, even if the mapping is later changed.
5. **BR-05 — Currency of record vs. currency of presentation.** Every underlying journal line always has a base-currency amount (the company's functional currency); presentation-currency translation for reporting is a rendering-time operation and is never written back to `journal_lines`.
6. **BR-06 — Consolidation requires an approved elimination set.** A consolidated statement cannot be generated (beyond a `preview` flag) until the current period's intercompany elimination journal entries have been reviewed and approved by a Finance Manager or CFO (see Permissions); an unapproved elimination set blocks the "official" (non-preview) consolidated snapshot.
7. **BR-07 — GAAP-mode reversal restriction.** When `financial_statement_templates.framework = 'GAAP'`, a journal entry attempting to reverse a previously posted impairment loss against a non-financial asset is blocked by the Service layer (ASC 360 prohibits impairment reversal), producing a 409 conflict with a clear message; the same entry is permitted when `framework = 'IFRS'`.
8. **BR-08 — Statutory reserve transfer is AI-suggested, human-approved.** At fiscal year-end close, the AI Reporting Agent drafts the statutory/voluntary reserve transfer entry per the company's jurisdictional rule (configurable percentage and cap, e.g., Kuwait's 10%-of-net-profit-until-50%-of-capital rule) but the entry remains in `draft` status until a CFO or Finance Manager approves and posts it.
9. **BR-09 — Segment and dimension totals must reconcile to the whole.** The sum of all branch-scoped or department-scoped Income Statement runs for a period must equal the company-level (unscoped) Income Statement for that period, net of the explicit "Unallocated" line; the engine self-checks this reconciliation on every scheduled consolidated/comparative run and logs a discrepancy as a data-integrity alert if it fails.
10. **BR-10 — Notes require line coverage before "final" publication.** A statement cannot be marked `status = 'final'` (see API — Schedule/Share) if a material statement line (above the company's configured materiality threshold, `financial_statement_templates.materiality_threshold`) lacks an associated `financial_statement_notes` disclosure; it may still be viewed/exported as `draft`.

# Validation Rules

| Rule | Check | Failure Behavior |
|---|---|---|
| VR-01 | Balance Sheet: `TOTAL ASSETS = TOTAL LIABILITIES + TOTAL EQUITY` (to the base-currency cent) | Statement generation returns HTTP 200 with `data.is_balanced = false` and `data.balance_variance` populated; UI renders a prominent warning banner; export to "final" PDF is blocked until resolved |
| VR-02 | Every `journal_entries` row included in the period's aggregation has `status = 'posted'` | Rows in any other status are excluded from the query by construction (WHERE clause), never silently included |
| VR-03 | Cash Flow Statement: `Cash and Cash Equivalents at End of Period` (computed via the CF roll-forward) equals the Balance Sheet's `Cash and Cash Equivalents` line for the same `as_of_date` | Mismatch raises a 500-level internal consistency alert (not shown as a soft warning) since this indicates a mapping-configuration bug, not a data problem |
| VR-04 | Every `financial_statement_lines` row referenced by a template resolves to at least one `accounts` row (no orphaned mapping) | Template save/activation is rejected with a 422 listing the unmapped line codes |
| VR-05 | Sign convention: asset/expense line aggregates are non-negative unless the account is explicitly flagged `accounts.allow_negative_balance` (e.g., a contra-asset like Accumulated Depreciation, or Sales Returns) | A negative balance on a non-flagged account raises a data-quality flag surfaced to the AI Anomaly Detection responsibility and to the generating user, but does not block generation |
| VR-06 | Comparative periods must not overlap the primary period and must belong to the same company (or, for consolidation, the same group) | Request rejected with 422 `"comparison_period_invalid"` |
| VR-07 | Consolidation: sum of subsidiary ownership percentages used in a single consolidation run does not exceed 100% for any subsidiary (no double-counting across parents) | Consolidation run blocked with 409 `"ownership_conflict"` naming the conflicting `companies.id` |
| VR-08 | Multi-currency: every subsidiary/branch statement being translated has a resolved `exchange_rates` row for the required rate type (spot at period-end, average for the period) — no implicit rate of 1.0 | Missing rate blocks generation with 422 `"exchange_rate_missing"` naming the currency pair and date required |
| VR-09 | Fiscal period continuity: no gap or overlap between consecutive `fiscal_periods` rows for a company | Enforced at `fiscal_periods` creation time (FormRequest), not at statement-generation time, but re-validated defensively before any Yearly statement run |
| VR-10 | Snapshot immutability: an existing `financial_statement_snapshots` row for a closed period can never be UPDATEd, only superseded by a new row with an incremented `regeneration_sequence` and a mandatory `regeneration_reason` | Enforced at the database layer via a `BEFORE UPDATE` trigger that raises an exception on any attempted mutation of snapshot financial data columns |

# Reporting Periods

Every statement generation call resolves against a `fiscal_periods` row (or an explicit custom range) belonging to a `fiscal_years` row. A company's fiscal year does not need to be the calendar year — `fiscal_years.start_month` is configurable (many Kuwait entities use a calendar fiscal year, but the platform supports any start month for holding structures with non-calendar parents).

## Monthly

The default granularity. Each `fiscal_years` row is auto-populated with twelve `fiscal_periods` rows of `period_type = 'monthly'` on fiscal year creation. Monthly statements are the primary artifact of the month-end close process: a CFO's "close the month" action culminates in generating and snapshotting the monthly Balance Sheet, Income Statement, and Cash Flow Statement, each tagged to that `fiscal_periods.id`. Monthly Income Statements are never simply "the year-to-date total for this month" — they are always the discrete within-month movement; year-to-date figures are a separate, explicitly requested comparative/cumulative view.

## Quarterly

`fiscal_periods` rows of `period_type = 'quarterly'` are derived (not independently entered) — each quarter is defined as the aggregation of its three constituent monthly periods (`fiscal_periods.parent_period_id` links a monthly period to its quarter). Quarterly Income Statement/Cash Flow generation simply widens the `period_start`/`period_end` window to the quarter's boundaries; quarterly Balance Sheet generation uses the last day of the quarter as `as_of_date`. Quarterly statements are commonly requested with a "same quarter, prior year" comparative for seasonality-aware businesses.

## Yearly

The annual statement set is the one subjected to the full close, audit, and (where applicable) statutory filing workflow. Year-end generation includes the system-generated closing journal entry (zeroing revenue/expense accounts into Retained Earnings) as a prerequisite, the AI-drafted statutory reserve transfer (BR-08), and the full Notes set including prior-year comparatives for every primary statement (a first-year company generates a single-year statement with no comparative). Yearly statements are the only statement type that can be flagged `is_statutory_filing = true`, which locks the template version and currency used and prevents any further regeneration without a formally logged `regeneration_reason` (VR-10) referencing the specific correction being made.

## Custom

Any arbitrary `period_start`/`period_end` (Income Statement/Cash Flow) or `as_of_date` (Balance Sheet) not aligned to a standard `fiscal_periods` boundary — e.g., a lender's covenant test date, a due-diligence cutoff for an acquisition, or a project's inception-to-date window. Custom-period statements are always generated in `mode = 'live'` (never snapshotted as an official closed-period artifact) unless explicitly requested with `persist = true` and an authorization reason, since they fall outside the standard close calendar and controller sign-off.

# Consolidation

Consolidation produces a single group Balance Sheet, Income Statement, Cash Flow Statement, Statement of Changes in Equity, and Comprehensive Income Statement from a parent company and its subsidiary set, per IFRS 10 (control-based scope) and IFRS 3 (business combinations).

## Holding Companies

A `companies` row with one or more other `companies` rows referencing it via `parent_company_id` is treated as a holding/parent entity for consolidation purposes. `companies.consolidation_method` on each subsidiary determines how it enters the group statement:

- `full_consolidation` — control exists (`companies.ownership_percentage >= 50` or documented control via other means, e.g., majority board rights even at <50% ownership); 100% of the subsidiary's assets, liabilities, revenue, and expenses are combined line-by-line with the parent, with non-controlling interest recognized separately for the minority-owned portion.
- `equity_method` — significant influence but not control (typically 20–50% ownership, `companies.ownership_percentage BETWEEN 20 AND 50`); only a single-line "Investment in Associate" (Balance Sheet) and "Share of Profit of Associate" (Income Statement) are recognized, computed as the parent's ownership percentage times the associate's profit/net assets — the associate's individual line items are never combined into the group statement.
- `cost_method` — no significant influence (`< 20%` ownership or no board representation); the investment is carried at cost (or fair value through OCI/profit-or-loss per IFRS 9 classification) with only dividends received recognized as income.

## Subsidiaries

Each fully-consolidated subsidiary's own statements are generated first, in its own functional currency, using the identical Report Generation Engine pipeline as any standalone company (a subsidiary is a `companies` row like any other — there is no separate "subsidiary statement" code path). The consolidation job then: (1) translates each subsidiary statement to the group's presentation currency (see Multi Currency), (2) combines each translated line item with the parent's corresponding line item by simple addition, (3) applies the elimination set, and (4) splits the combined equity and profit between the parent's share and non-controlling interest.

## Eliminations

Intercompany balances and transactions must be eliminated so the group statement reflects only transactions with external parties. QAYD tracks intercompany transactions via a `counterparty_company_id` tag on `journal_lines` (populated whenever a document — invoice, bill, payment — names another `companies` row within the same group as its customer/vendor). The consolidation job auto-drafts an elimination journal entry set (`journal_entries.entry_type = 'consolidation_elimination'`, posted only into the *consolidation workspace*, never into either subsidiary's own books) covering:

- **Intercompany receivables/payables** — Dr. Intercompany Payable / Cr. Intercompany Receivable, eliminating both sides of the balance so neither appears on the group Balance Sheet.
- **Intercompany revenue/cost of sales** — Dr. Intercompany Revenue / Cr. Intercompany Cost of Sales, eliminating the intercompany sale entirely from the group Income Statement.
- **Unrealized intercompany profit in closing inventory** — where Subsidiary A sold inventory to Subsidiary B at a markup and Subsidiary B has not yet resold it externally, the unrealized profit portion is eliminated: Dr. Cost of Sales (Group) / Cr. Inventory (Group), computed as `intercompany_markup_percent × ending_intercompany_sourced_inventory_balance`.
- **Intercompany dividends** — Dr. Dividend Income (Parent) / Cr. Retained Earnings movement reversal, so a subsidiary's dividend to the parent does not double-count as group income.
- **Intercompany investment vs. subsidiary equity** — Dr. Share Capital (Subsidiary, parent's share) / Cr. Investment in Subsidiary (Parent books), the classic consolidation elimination that also gives rise to any Goodwill (where consideration paid exceeded the fair value of net assets acquired) or a Bargain Purchase Gain (the reverse).

Per BR-06, this elimination set must be reviewed and approved (Finance Manager/CFO) before the consolidated statement can be finalized; a `preview` consolidated run may proceed with an unapproved, system-drafted elimination set clearly watermarked "Preview — Eliminations Not Yet Approved."

## Minority Interest

Non-Controlling Interest (NCI) — the minority interest — is computed for every partially-owned, fully-consolidated subsidiary as `(1 − parent_ownership_percentage) × subsidiary_net_assets` (Balance Sheet NCI) and `(1 − parent_ownership_percentage) × subsidiary_profit_for_period` (Income Statement NCI allocation), evaluated after eliminations since intercompany eliminations affect the subsidiary's post-elimination net assets and profit. NCI is presented as a distinct line within Total Equity (never as a liability, per IFRS 10 §22) and as a distinct allocation line below Profit for the Period and below Total Comprehensive Income. Where a subsidiary's ownership percentage changes mid-year (a partial disposal or additional share purchase not resulting in loss/gain of control), the NCI computation is time-weighted across the sub-periods before and after the ownership change, using the subsidiary's own monthly `fiscal_periods` granularity to avoid a full re-derivation of daily balances.

# Multi Currency

Every company has one base (functional) currency (`companies.base_currency`, ISO 4217). Journal lines are always recorded with both the transaction-currency amount and the base-currency amount (per the platform's standing multi-currency rule); this module additionally supports rendering any statement in a *presentation* currency distinct from the base currency.

## Exchange Rates

`exchange_rates` stores daily (or more frequent, where a company's treasury policy requires it) rate rows for every currency pair the company transacts in or reports in, each tagged with a `rate_type` (`spot`, `average`, `closing`, `historical`) and `rate_source` (e.g., Central Bank of Kuwait, a market data provider, or a manually entered company policy rate). The Report Generation Engine never interpolates a missing rate — VR-08 blocks generation rather than silently defaulting to 1.0 or to the nearest available date, because a silently wrong FX rate is a materially worse failure mode than a blocked report.

## Translation

For a foreign subsidiary being consolidated (functional currency ≠ group presentation currency), IAS 21's current rate method applies:

| Statement item | Rate applied |
|---|---|
| All Balance Sheet assets and liabilities | Closing (period-end spot) rate |
| Share Capital and other equity items existing at acquisition | Historical rate at the date each item arose |
| Income Statement revenue and expense lines | Period-average rate (or the actual transaction-date rate for large one-off items, if the company's policy requires it) |
| Retained Earnings | Not directly translated — it is the accumulated translated profit figures brought forward each period |

The resulting imbalance between the translated Balance Sheet (assets − liabilities − translated equity) and the translated Income Statement's contribution to equity is the Cumulative Translation Adjustment (CTA), recognized in Other Comprehensive Income and accumulated in the Foreign Currency Translation Reserve equity line — never recognized in profit or loss (except on disposal of the foreign operation, per IAS 21 §48, when the cumulative CTA is recycled to profit or loss).

Where a subsidiary's functional currency is deemed to be the same as the parent's (hyperinflationary economy exception aside, not currently modeled), the temporal method applies instead: monetary items at closing rate, non-monetary items at historical rate, with the resulting exchange difference recognized directly in profit or loss (Foreign Exchange Gain/Loss) rather than OCI. `companies.translation_method` (`current_rate` | `temporal`) governs which path the consolidation job takes for that subsidiary.

## Revaluation

Distinct from translation (which applies to consolidating a foreign subsidiary's whole statement), revaluation applies to individual foreign-currency-denominated monetary balances (e.g., a USD-denominated bank account or a foreign-currency trade receivable) held directly within a single company's own books. At each period-end, the Report Generation Engine's underlying ledger process (owned by the Accounting Engine, not duplicated here) revalues these monetary balances to the period-end closing rate and posts an unrealized FX Gain/Loss journal entry directly to profit or loss (not OCI, per IAS 21 §28 — this is a fundamental distinction from the CTA treatment above). The Financial Statements module's role is purely to present the resulting Foreign Exchange Gain/(Loss) line correctly within Other Income and Expenses on the Income Statement, and to disclose the revaluation policy and the period's realized-vs-unrealized FX split in the Notes.

# Database Design

This module owns seven PostgreSQL tables: `financial_statement_templates`, `financial_statement_lines`, `financial_statement_terminology`, `financial_statement_snapshots`, `financial_statement_snapshot_lines`, `financial_statement_notes`, and `financial_statement_shares`. It reads, but does not own, `accounts`, `account_types`, `journal_entries`, `journal_lines`, `ledger_entries`, `fiscal_years`, `fiscal_periods`, `exchange_rates`, `companies`, `branches`, `departments`, `projects`, `cost_centers`, `report_definitions`, `report_schedules`, `report_runs`, and `audit_logs`.

## Tables

```sql
-- ============================================================
-- ENUM TYPES
-- ============================================================

CREATE TYPE fs_statement_type AS ENUM (
  'balance_sheet',
  'income_statement',
  'cash_flow_statement',
  'statement_of_changes_in_equity',
  'statement_of_comprehensive_income'
);

CREATE TYPE fs_framework AS ENUM ('IFRS', 'GAAP');

CREATE TYPE fs_expense_presentation AS ENUM ('by_nature', 'by_function');

CREATE TYPE fs_cash_flow_method AS ENUM ('indirect', 'direct');

CREATE TYPE fs_oci_presentation_mode AS ENUM ('single_statement', 'two_statements');

CREATE TYPE fs_line_classification AS ENUM (
  'current_asset', 'non_current_asset',
  'current_liability', 'non_current_liability',
  'equity',
  'revenue', 'cost_of_sales', 'operating_expense', 'other_income_expense', 'tax_expense',
  'operating_activity', 'investing_activity', 'financing_activity',
  'oci_reclassifiable', 'oci_non_reclassifiable'
);

CREATE TYPE fs_line_kind AS ENUM ('account_mapped', 'subtotal', 'total', 'formula', 'note_reference');

CREATE TYPE fs_generation_mode AS ENUM ('live', 'historical', 'comparative', 'consolidated', 'preview');

CREATE TYPE fs_scope_type AS ENUM ('company', 'branch', 'department', 'project', 'consolidated_group');

CREATE TYPE fs_snapshot_status AS ENUM ('draft', 'under_review', 'final', 'superseded');

CREATE TYPE fs_note_status AS ENUM ('draft', 'reviewed', 'approved');

CREATE TYPE fs_share_permission AS ENUM ('view_only', 'view_and_export');

-- ============================================================
-- financial_statement_templates
-- One row per (company, statement_type, framework) configuration version.
-- Versioned: editing creates a new row rather than mutating an active one (BR-04).
-- ============================================================
CREATE TABLE financial_statement_templates (
    id                        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                BIGINT NOT NULL REFERENCES companies(id),
    branch_id                 BIGINT NULL REFERENCES branches(id),
    statement_type            fs_statement_type NOT NULL,
    framework                 fs_framework NOT NULL DEFAULT 'IFRS',
    name                      VARCHAR(150) NOT NULL,
    version                   INTEGER NOT NULL DEFAULT 1,
    is_active                 BOOLEAN NOT NULL DEFAULT TRUE,
    is_system_default         BOOLEAN NOT NULL DEFAULT FALSE,
    expense_presentation      fs_expense_presentation NOT NULL DEFAULT 'by_nature',
    cash_flow_method          fs_cash_flow_method NOT NULL DEFAULT 'indirect',
    oci_presentation_mode     fs_oci_presentation_mode NOT NULL DEFAULT 'single_statement',
    department_scope_mode     VARCHAR(30) NOT NULL DEFAULT 'income_statement_only'
                              CHECK (department_scope_mode IN ('income_statement_only', 'all_statements')),
    materiality_threshold     NUMERIC(19,4) NOT NULL DEFAULT 0,
    capitalize_development_costs BOOLEAN NOT NULL DEFAULT TRUE,
    supersedes_template_id    BIGINT NULL REFERENCES financial_statement_templates(id),
    created_by                BIGINT NULL REFERENCES users(id),
    updated_by                BIGINT NULL REFERENCES users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                TIMESTAMPTZ NULL,
    CONSTRAINT uq_fs_template_active_version
        UNIQUE (company_id, statement_type, framework, version)
);

CREATE INDEX idx_fs_templates_company ON financial_statement_templates(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_fs_templates_active ON financial_statement_templates(company_id, statement_type, framework)
    WHERE is_active = TRUE AND deleted_at IS NULL;

-- ============================================================
-- financial_statement_lines
-- The presentation hierarchy for a given template: maps accounts/account_types
-- (or a formula over sibling lines) to a labeled, ordered statement row.
-- Self-referencing tree via parent_line_id (Total Current Assets is the parent
-- of Cash and Cash Equivalents, Trade Receivables, Inventories, etc.).
-- ============================================================
CREATE TABLE financial_statement_lines (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    template_id           BIGINT NOT NULL REFERENCES financial_statement_templates(id),
    parent_line_id        BIGINT NULL REFERENCES financial_statement_lines(id),
    line_code             VARCHAR(60) NOT NULL,          -- e.g. 'CA_CASH', 'TOTAL_CURRENT_ASSETS'
    label_en              VARCHAR(200) NOT NULL,
    label_ar              VARCHAR(200) NULL,
    display_order         INTEGER NOT NULL DEFAULT 0,
    classification        fs_line_classification NOT NULL,
    line_kind             fs_line_kind NOT NULL,
    account_id            BIGINT NULL REFERENCES accounts(id),          -- single-account mapping
    account_type_id       BIGINT NULL REFERENCES account_types(id),     -- wildcard category mapping
    formula               TEXT NULL,                      -- e.g. '{TOTAL_CURRENT_ASSETS} - {TOTAL_CURRENT_LIABILITIES}'
    is_subtotal           BOOLEAN NOT NULL DEFAULT FALSE,
    is_bold               BOOLEAN NOT NULL DEFAULT FALSE,
    allow_negative_balance BOOLEAN NOT NULL DEFAULT FALSE,
    is_extraordinary      BOOLEAN NOT NULL DEFAULT FALSE,
    fair_value_level      SMALLINT NULL CHECK (fair_value_level IN (1,2,3)),
    cash_flow_category    VARCHAR(20) NULL CHECK (cash_flow_category IN ('operating','investing','financing')),
    oci_component         VARCHAR(30) NULL CHECK (oci_component IN
                              ('translation_reserve','hedge_reserve','remeasurement_reserve','fvoci_reserve')),
    created_by             BIGINT NULL REFERENCES users(id),
    updated_by             BIGINT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at             TIMESTAMPTZ NULL,
    CONSTRAINT uq_fs_line_code_per_template UNIQUE (template_id, line_code),
    CONSTRAINT chk_fs_line_mapping_exclusive CHECK (
        (line_kind = 'account_mapped' AND (account_id IS NOT NULL OR account_type_id IS NOT NULL) AND formula IS NULL)
        OR (line_kind IN ('subtotal','total','formula') AND formula IS NOT NULL AND account_id IS NULL AND account_type_id IS NULL)
        OR (line_kind = 'note_reference')
    )
);

CREATE INDEX idx_fs_lines_template ON financial_statement_lines(template_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_fs_lines_parent ON financial_statement_lines(parent_line_id);
CREATE INDEX idx_fs_lines_account ON financial_statement_lines(account_id) WHERE account_id IS NOT NULL;
CREATE INDEX idx_fs_lines_account_type ON financial_statement_lines(account_type_id) WHERE account_type_id IS NOT NULL;

-- ============================================================
-- financial_statement_terminology
-- Label-swap dictionary for GAAP-mode rendering (Section: GAAP Compatibility).
-- Applies at render time only; never mutates financial_statement_lines.label_*.
-- ============================================================
CREATE TABLE financial_statement_terminology (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NULL REFERENCES companies(id),   -- NULL = system-wide default row
    framework       fs_framework NOT NULL,
    ifrs_term       VARCHAR(150) NOT NULL,
    replacement_term VARCHAR(150) NOT NULL,
    lang            VARCHAR(5) NOT NULL DEFAULT 'en',
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_fs_terminology UNIQUE (COALESCE(company_id, 0), framework, ifrs_term, lang)
);

-- ============================================================
-- financial_statement_snapshots
-- Immutable, reproducible generation artifacts. One row per
-- (company/scope, statement_type, period, currency, template_version) result.
-- Never UPDATEd for a 'final' row — see trigger below (VR-10).
-- ============================================================
CREATE TABLE financial_statement_snapshots (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id             BIGINT NOT NULL REFERENCES companies(id),
    branch_id              BIGINT NULL REFERENCES branches(id),
    department_id          BIGINT NULL REFERENCES departments(id),
    project_id             BIGINT NULL REFERENCES projects(id),
    statement_type         fs_statement_type NOT NULL,
    scope_type             fs_scope_type NOT NULL DEFAULT 'company',
    template_id            BIGINT NOT NULL REFERENCES financial_statement_templates(id),
    template_version       INTEGER NOT NULL,
    fiscal_year_id         BIGINT NULL REFERENCES fiscal_years(id),
    fiscal_period_id       BIGINT NULL REFERENCES fiscal_periods(id),
    period_start           DATE NULL,
    period_end             DATE NULL,
    as_of_date             DATE NULL,                      -- Balance Sheet point-in-time
    presentation_currency  VARCHAR(3) NOT NULL,             -- ISO 4217
    exchange_rate_set_id   BIGINT NULL REFERENCES exchange_rates(id),
    generation_mode        fs_generation_mode NOT NULL DEFAULT 'live',
    is_consolidated        BOOLEAN NOT NULL DEFAULT FALSE,
    consolidated_company_ids BIGINT[] NULL,                 -- subsidiaries included, if is_consolidated
    status                 fs_snapshot_status NOT NULL DEFAULT 'draft',
    is_statutory_filing    BOOLEAN NOT NULL DEFAULT FALSE,
    is_balanced             BOOLEAN NOT NULL,
    balance_variance        NUMERIC(19,4) NOT NULL DEFAULT 0,
    totals                  JSONB NOT NULL DEFAULT '{}'::jsonb,   -- fast-path headline totals
    generation_params       JSONB NOT NULL DEFAULT '{}'::jsonb,   -- full request payload, for reproducibility
    result_hash             VARCHAR(64) NOT NULL,                 -- sha256 of the rendered line array
    regeneration_sequence   INTEGER NOT NULL DEFAULT 1,
    regeneration_reason     TEXT NULL,
    superseded_by_id        BIGINT NULL REFERENCES financial_statement_snapshots(id),
    generated_by            BIGINT NULL REFERENCES users(id),
    reviewed_by             BIGINT NULL REFERENCES users(id),
    approved_by             BIGINT NULL REFERENCES users(id),
    generated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    created_by              BIGINT NULL REFERENCES users(id),
    updated_by               BIGINT NULL REFERENCES users(id),
    created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at               TIMESTAMPTZ NULL,
    CONSTRAINT chk_fs_snapshot_period_or_asof CHECK (
        (statement_type = 'balance_sheet' AND as_of_date IS NOT NULL)
        OR (statement_type <> 'balance_sheet' AND period_start IS NOT NULL AND period_end IS NOT NULL)
    )
);

CREATE INDEX idx_fs_snapshots_company_period ON financial_statement_snapshots
    (company_id, statement_type, fiscal_period_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_fs_snapshots_lookup ON financial_statement_snapshots
    (company_id, statement_type, presentation_currency, template_version, as_of_date, period_start, period_end)
    WHERE deleted_at IS NULL AND status = 'final';
CREATE INDEX idx_fs_snapshots_consolidated ON financial_statement_snapshots(company_id)
    WHERE is_consolidated = TRUE;
CREATE INDEX idx_fs_snapshots_result_hash ON financial_statement_snapshots(result_hash);

-- Immutability trigger (VR-10): a 'final' snapshot's financial columns can never be updated in place.
CREATE OR REPLACE FUNCTION fn_block_final_snapshot_mutation() RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status = 'final' AND (
        NEW.totals IS DISTINCT FROM OLD.totals OR
        NEW.is_balanced IS DISTINCT FROM OLD.is_balanced OR
        NEW.balance_variance IS DISTINCT FROM OLD.balance_variance OR
        NEW.result_hash IS DISTINCT FROM OLD.result_hash
    ) THEN
        RAISE EXCEPTION 'financial_statement_snapshots: cannot mutate a final snapshot (id=%). Create a superseding row instead.', OLD.id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_block_final_snapshot_mutation
    BEFORE UPDATE ON financial_statement_snapshots
    FOR EACH ROW EXECUTE FUNCTION fn_block_final_snapshot_mutation();

-- ============================================================
-- financial_statement_snapshot_lines
-- The rendered line-by-line values for a snapshot: the actual numbers.
-- One row per financial_statement_lines entry, per snapshot.
-- ============================================================
CREATE TABLE financial_statement_snapshot_lines (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    snapshot_id           BIGINT NOT NULL REFERENCES financial_statement_snapshots(id) ON DELETE CASCADE,
    line_id               BIGINT NOT NULL REFERENCES financial_statement_lines(id),
    line_code             VARCHAR(60) NOT NULL,             -- denormalized for fast rendering without a join
    label_en              VARCHAR(200) NOT NULL,
    label_ar              VARCHAR(200) NULL,
    display_order         INTEGER NOT NULL,
    current_value         NUMERIC(19,4) NOT NULL DEFAULT 0,
    comparison_value       NUMERIC(19,4) NULL,
    variance_amount        NUMERIC(19,4) NULL,
    variance_percent       NUMERIC(9,4) NULL,
    drilldown_account_ids  BIGINT[] NULL,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_fs_snapshot_line UNIQUE (snapshot_id, line_id)
);

CREATE INDEX idx_fs_snapshot_lines_snapshot ON financial_statement_snapshot_lines(snapshot_id);
CREATE INDEX idx_fs_snapshot_lines_line ON financial_statement_snapshot_lines(line_id);

-- ============================================================
-- financial_statement_notes
-- Structured/free-text disclosures attached to a statement line and a period.
-- Independently versioned and approved from the statement they accompany.
-- ============================================================
CREATE TABLE financial_statement_notes (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    line_id             BIGINT NULL REFERENCES financial_statement_lines(id),
    fiscal_period_id    BIGINT NULL REFERENCES fiscal_periods(id),
    note_number         VARCHAR(10) NOT NULL,               -- '1', '2', '5a', etc.
    title_en            VARCHAR(200) NOT NULL,
    title_ar            VARCHAR(200) NULL,
    content_json        JSONB NULL,                         -- structured tables/roll-forwards
    content_text        TEXT NULL,                          -- free narrative
    status               fs_note_status NOT NULL DEFAULT 'draft',
    is_auto_generated    BOOLEAN NOT NULL DEFAULT FALSE,
    reviewed_by          BIGINT NULL REFERENCES users(id),
    approved_by          BIGINT NULL REFERENCES users(id),
    created_by           BIGINT NULL REFERENCES users(id),
    updated_by           BIGINT NULL REFERENCES users(id),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at           TIMESTAMPTZ NULL,
    CONSTRAINT uq_fs_note_number UNIQUE (company_id, fiscal_period_id, note_number)
);

CREATE INDEX idx_fs_notes_company_period ON financial_statement_notes(company_id, fiscal_period_id)
    WHERE deleted_at IS NULL;
CREATE INDEX idx_fs_notes_line ON financial_statement_notes(line_id) WHERE line_id IS NOT NULL;

-- ============================================================
-- financial_statement_shares
-- Time-limited, permission-scoped external share links (auditors, banks).
-- ============================================================
CREATE TABLE financial_statement_shares (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    snapshot_id       BIGINT NOT NULL REFERENCES financial_statement_snapshots(id),
    share_token       VARCHAR(64) NOT NULL,
    permission        fs_share_permission NOT NULL DEFAULT 'view_only',
    recipient_email    VARCHAR(255) NULL,
    recipient_label    VARCHAR(150) NULL,                    -- e.g. 'External Auditor - Deloitte Kuwait'
    expires_at         TIMESTAMPTZ NOT NULL,
    revoked_at         TIMESTAMPTZ NULL,
    last_accessed_at   TIMESTAMPTZ NULL,
    access_count        INTEGER NOT NULL DEFAULT 0,
    created_by          BIGINT NULL REFERENCES users(id),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at           TIMESTAMPTZ NULL,
    CONSTRAINT uq_fs_share_token UNIQUE (share_token)
);

CREATE INDEX idx_fs_shares_snapshot ON financial_statement_shares(snapshot_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_fs_shares_expiry ON financial_statement_shares(expires_at) WHERE revoked_at IS NULL;
```

## Relationships

- `financial_statement_templates` 1—N `financial_statement_lines` (a template owns its full line hierarchy).
- `financial_statement_lines` is a self-referencing tree via `parent_line_id`; leaf nodes are `account_mapped`, branch/root nodes are typically `subtotal`/`total`.
- `financial_statement_lines.account_id` → `accounts.id` (many lines can map to the same account only across *different* templates; within one template, `uq_fs_line_code_per_template` plus application-level validation prevents two lines double-counting the same account).
- `financial_statement_snapshots` N—1 `financial_statement_templates` (denormalizes `template_version` for reproducibility even after the template is later re-versioned).
- `financial_statement_snapshots` 1—N `financial_statement_snapshot_lines` (the actual rendered numbers; cascade-deleted only in the rare case a *draft, non-final* snapshot is purged — final snapshots are never hard-deleted, only soft-deleted per platform convention, and even then the immutability trigger still governs any attempted financial-data update).
- `financial_statement_snapshot_lines.line_id` → `financial_statement_lines.id`, denormalizing `label_en`/`label_ar`/`display_order` at generation time so historical snapshots render correctly even if the live template's labels change later (template versioning handles structural changes; this denormalization protects against label-only edits made without a version bump being silently backdated onto old snapshots).
- `financial_statement_notes.line_id` → `financial_statement_lines.id`, optionally null for notes with no single-line anchor (e.g., Note 1 — Basis of Preparation).
- `financial_statement_shares.snapshot_id` → `financial_statement_snapshots.id`; a share always points at one immutable snapshot, never at a "live" statement, so an auditor's link never silently changes underneath them.
- Upstream (read-only) relationships: `financial_statement_lines.account_id`/`account_type_id` → `accounts`/`account_types`; `financial_statement_snapshots.fiscal_period_id` → `fiscal_periods`; `financial_statement_snapshots.exchange_rate_set_id` → `exchange_rates`; all snapshot/template/line/note rows carry `company_id`/`branch_id` per the platform's standard multi-tenancy columns.

## Indexes

Beyond the indexes declared inline above, the following composite/partial indexes are required for production query performance at scale (a mid-market company can accumulate tens of thousands of snapshot-lines per year across all statement types, periods, and comparative regenerations):

```sql
-- Fast "give me the current final Balance Sheet for company X as of date Y" lookup
CREATE INDEX idx_fs_snapshots_balance_sheet_current ON financial_statement_snapshots
    (company_id, as_of_date DESC) WHERE statement_type = 'balance_sheet' AND status = 'final' AND deleted_at IS NULL;

-- Fast "give me every snapshot needing AI enrichment" queue-scan index
CREATE INDEX idx_fs_snapshots_pending_ai ON financial_statement_snapshots (generated_at)
    WHERE deleted_at IS NULL;

-- Fast statutory-filing history lookup per company
CREATE INDEX idx_fs_snapshots_statutory ON financial_statement_snapshots (company_id, fiscal_year_id)
    WHERE is_statutory_filing = TRUE;

-- Note approval queue
CREATE INDEX idx_fs_notes_pending_review ON financial_statement_notes (company_id, status)
    WHERE status IN ('draft', 'reviewed') AND deleted_at IS NULL;
```

## Snapshots

A snapshot (`financial_statement_snapshots` + its `financial_statement_snapshot_lines`) is the module's unit of durable, audit-grade history. The lifecycle is: `draft` (first generation, or any preview/live regeneration) → `under_review` (a Finance Manager has opened it for sign-off) → `final` (approved; immutable per the trigger above) → `superseded` (a later regeneration, always carrying a `regeneration_reason`, supersedes it via `superseded_by_id` while the original row is retained, never deleted, for audit purposes). Only `final` snapshots are eligible for statutory filing marking, external sharing, or inclusion in a scheduled distribution.

## History

Because `journal_lines`/`journal_entries` are themselves permanently retained (soft-deleted only) and closed `fiscal_periods` never accept new entries, the module's "history" is really two complementary layers: (1) the live-queryable full ledger history, which lets any closed period be regenerated on demand and will always reproduce the same figures, and (2) the `financial_statement_snapshots` archive, which is the fast-path, audit-stamped record of exactly what was *shown* to a user or filed with a regulator at a specific point in time, including the AI insights and notes that accompanied it. Retention for both layers is indefinite by default (`deleted_at` soft-delete only, never a hard purge), consistent with statutory record-keeping requirements (typically 5–10 years minimum across GCC jurisdictions, but QAYD does not impose an upper bound).

# AI Responsibilities

The AI Layer (FastAPI + Python) reads finalized and draft statement data through the Laravel API — it never queries PostgreSQL directly and never writes a statement line, note, or journal entry itself. Every AI job runs as an asynchronous enrichment against an existing `financial_statement_snapshots` row (or a `preview` live generation) and returns structured output that the Laravel backend validates and stores as an `ai_conversations`/`ai_messages` pair linked to the snapshot, plus a denormalized `ai_insights` JSONB blob cached on the snapshot response for fast UI rendering. Every AI output carries `confidence` (0.00–1.00), `reasoning` (a plain-language explanation), and `source_references` (the specific `financial_statement_snapshot_lines.id` or `journal_lines.id` values that produced the conclusion).

| Responsibility | Agent | Inputs | Output | Autonomy | Confidence handling |
|---|---|---|---|---|---|
| Financial Analysis | Reporting Agent | Current snapshot + prior comparatives | Structured health assessment (liquidity, solvency, profitability, efficiency commentary) | Suggest-only | Below 0.6 confidence, output is hidden by default behind a "Show low-confidence AI analysis" toggle |
| Ratio Analysis | Reporting Agent | Current + comparative snapshot line values | Standard ratio set (see Reports — Financial Ratios) with a plain-language read of each | Auto-computed, always shown (ratios are deterministic math, not a judgment call) | N/A — ratios themselves carry no confidence score; only their narrative interpretation does |
| Variance Analysis | Reporting Agent | Current vs. comparison snapshot lines | Line-by-line variance narrative flagging the 5–10 largest/most unusual movements | Suggest-only | Each flagged variance carries its own confidence (higher when a clear driver account is identifiable, lower when the movement is diffuse across many small accounts) |
| Trend Analysis | Forecast Agent | Trailing N periods of snapshots (typically 12 monthly + prior 2 years yearly) | Trend direction/velocity per key line item, seasonality flags | Suggest-only | Requires a minimum of 3 comparable historical periods before returning any trend claim; otherwise returns `insufficient_history` |
| Forecasting | Forecast Agent | Trailing history + open-period actuals + optional budget dataset | Pro-forma next-period Income Statement and Balance Sheet, with a confidence band | Suggest-only, explicitly labeled "Forecast — Not an Audited Statement" | Confidence decays with forecast horizon; a 1-month-ahead forecast typically carries materially higher confidence than a 12-month-ahead one, and the response always includes the decay curve |
| Executive Summary | Reporting Agent | Full snapshot + ratio/variance/trend outputs | 150–300 word plain-language narrative for a non-accountant reader (e.g., a business owner) | Suggest-only | Always shown with a visible "AI-generated — reviewed by [approver] on [date]" attribution once a human has signed off; unsigned-off summaries are watermarked "Unreviewed AI Draft" |
| Risk Detection | Fraud Detection Agent + Compliance Agent | Snapshot lines + underlying journal entries + counterparty data | Ranked list of risk flags (e.g., "receivables growing faster than revenue," "related-party concentration above threshold") | Suggest-only; sensitive risk flags (suspected fraud pattern) escalate to a required human review before being shown outside the Finance Manager/CFO/Auditor roles | High-risk flags (confidence ≥ 0.75) trigger a Notification (see below) in addition to appearing in the AI Insights panel |
| Anomaly Detection | Fraud Detection Agent | Statistical baseline of historical account balances/movements + current period | Flagged accounts/lines whose movement falls outside the learned normal range (z-score or seasonal-adjusted threshold) | Suggest-only | Anomalies below a company-configurable sensitivity threshold are logged but not surfaced in the default view, to avoid alert fatigue |
| Narrative Report Generation | Reporting Agent | Full snapshot + all of the above AI outputs + company profile | Full "AI Analyst Commentary" document (multi-page, suitable for inclusion in a board pack) | Suggest-only; requires explicit CFO/Finance Manager approval before it can be attached to a `final` statement export or a scheduled distribution | The document itself carries an overall confidence score computed as the weighted minimum of its constituent claims' confidence scores, so one weak claim cannot hide behind an otherwise strong report |

**Guardrails applied uniformly:** the AI Layer receives only the active company's data (enforced by the same `X-Company-Id` scoping as every other API call); AI never sees another tenant's figures even when generating cross-period trend analysis. AI outputs are always rendered in a visually distinct panel (a colored border and an "AI" badge in the frontend) and are never merged into the numeric statement grid itself. Every AI job that touches `final` or statutory-filed statement data is itself recorded as an `audit_logs` entry (actor = the AI agent identity, action = `ai.analysis.generated`, with the full input snapshot id and output hash).

# Reports

This section catalogs the report *outputs* the module exposes — distinct from the six statements above, these are analytical/derivative reports built on top of one or more statements.

## Balance Sheet, Income Statement, Cash Flow, Equity Statement

These four are the primary statements defined in full under **Financial Statements** above; as *reports* they are simply the exportable, schedulable, shareable renderings of those statements, available in every mode (real-time, historical, comparative, consolidated, branch, department, project) described under **Report Generation Engine**.

## Comparative

A single report combining any primary statement's current-period column with one or more comparison columns (prior period, prior year, budget). Rendered as a multi-column grid; each comparison column includes variance amount and variance percent per VR-06's validated, non-overlapping period pairing.

## Common Size

Also called a **percentage statement**: every Balance Sheet line is expressed as a percentage of Total Assets; every Income Statement line is expressed as a percentage of Net Revenue. Computed as `line.current_value / base_line.current_value * 100` where `base_line` is the `TOTAL_ASSETS` line (Balance Sheet) or the `NET_REVENUE` line (Income Statement). This report is generated from an existing snapshot with no additional ledger query — it is a pure transformation of `financial_statement_snapshot_lines`, making it effectively free to compute and always available even for archived historical snapshots.

## Vertical Analysis

Synonymous with Common Size for a single period — presents the percentage-of-base breakdown for one statement, one period, emphasizing structural composition (e.g., "Inventories are 34% of Total Assets" as a signal of working-capital intensity).

## Horizontal Analysis

Presents the *period-over-period* percentage and absolute change for every line across a trailing series (e.g., the last 5 fiscal years side by side), computed as `(period_n.value - period_1.value) / ABS(period_1.value) * 100` using `period_1` as the fixed base year, in addition to the period-over-period comparative variance already available in the Comparative report.

## Financial Ratios

Computed live from the most recent applicable snapshot(s); the standard set:

| Ratio | Formula | Category |
|---|---|---|
| Current Ratio | Current Assets ÷ Current Liabilities | Liquidity |
| Quick Ratio | (Current Assets − Inventories − Prepaid Expenses) ÷ Current Liabilities | Liquidity |
| Cash Ratio | Cash and Cash Equivalents ÷ Current Liabilities | Liquidity |
| Debt-to-Equity | Total Liabilities ÷ Total Equity | Solvency |
| Debt-to-Assets | Total Liabilities ÷ Total Assets | Solvency |
| Interest Coverage | Operating Profit ÷ Finance Costs | Solvency |
| Gross Margin | Gross Profit ÷ Net Revenue | Profitability |
| Operating Margin | Operating Profit ÷ Net Revenue | Profitability |
| Net Margin | Profit for the Period ÷ Net Revenue | Profitability |
| Return on Assets (ROA) | Profit for the Period ÷ Average Total Assets | Profitability |
| Return on Equity (ROE) | Profit Attributable to Owners ÷ Average Equity Attributable to Owners | Profitability |
| Asset Turnover | Net Revenue ÷ Average Total Assets | Efficiency |
| Inventory Turnover | Cost of Sales ÷ Average Inventories | Efficiency |
| Receivables Turnover | Net Revenue ÷ Average Trade Receivables | Efficiency |
| Days Sales Outstanding (DSO) | 365 ÷ Receivables Turnover | Efficiency |
| Days Inventory Outstanding (DIO) | 365 ÷ Inventory Turnover | Efficiency |
| Days Payable Outstanding (DPO) | 365 ÷ (Cost of Sales ÷ Average Trade Payables) | Efficiency |
| Cash Conversion Cycle | DSO + DIO − DPO | Efficiency |

"Average" balances use the simple average of the opening and closing Balance Sheet value for the period. Ratios requiring a two-point average (ROA, ROE, turnover ratios) are unavailable for a company's very first reporting period and are returned as `null` with a `reason: "no_prior_period_balance"` rather than a misleading zero.

## KPI Dashboard

A configurable grid of headline metrics (revenue, gross margin, net margin, cash position, current ratio, DSO, and any company-defined custom metric built from a `financial_statement_lines.formula`) rendered as the CFO/Owner landing view, always sourced from the latest `live` or `final` snapshot per metric, each tile carrying a trend sparkline (from Horizontal Analysis data) and a period-over-period delta badge.

# API

All endpoints are versioned under `/api/v1/financial-statements/`, require a Sanctum/JWT bearer token, are scoped to the active company via `X-Company-Id`, and return the standard response envelope defined in the platform API conventions.

## Endpoints

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | `/api/v1/financial-statements/generate` | `reports.financial_statements.generate` | Generate a statement (live, historical, comparative, consolidated, branch/department/project scoped); returns the full line array |
| GET | `/api/v1/financial-statements/preview` | `reports.financial_statements.generate` | Non-persisted preview generation (draft consolidation eliminations allowed) |
| GET | `/api/v1/financial-statements/{snapshot}` | `reports.financial_statements.read` | Fetch a specific existing snapshot by id |
| GET | `/api/v1/financial-statements` | `reports.financial_statements.read` | List/search snapshots (filter by statement_type, period, status, scope) |
| POST | `/api/v1/financial-statements/{snapshot}/review` | `reports.financial_statements.review` | Move a snapshot from `draft`/`under_review` toward `final` |
| POST | `/api/v1/financial-statements/{snapshot}/export` | `reports.financial_statements.export` | Render to PDF, Excel, CSV, or JSON |
| GET | `/api/v1/financial-statements/{snapshot}/export/pdf` | `reports.financial_statements.export` | Direct PDF stream download |
| GET | `/api/v1/financial-statements/{snapshot}/export/excel` | `reports.financial_statements.export` | Direct Excel (.xlsx) stream download |
| GET | `/api/v1/financial-statements/{snapshot}/export/csv` | `reports.financial_statements.export` | Direct CSV stream download |
| GET | `/api/v1/financial-statements/{snapshot}/export/json` | `reports.financial_statements.export` | Raw structured JSON, for integration/BI tools |
| POST | `/api/v1/financial-statements/{snapshot}/share` | `reports.financial_statements.share` | Create a time-limited external share link |
| DELETE | `/api/v1/financial-statements/shares/{share}` | `reports.financial_statements.share` | Revoke a share link |
| POST | `/api/v1/financial-statements/schedules` | `reports.financial_statements.schedule` | Create a recurring generation + distribution schedule (wraps `report_schedules`) |
| GET | `/api/v1/financial-statements/{snapshot}/insights` | `reports.financial_statements.read` | Fetch AI Responsibilities output (ratios, variance, narrative) for a snapshot |
| GET | `/api/v1/financial-statements/templates` | `reports.financial_statements.configure` | List templates for the active company |
| POST | `/api/v1/financial-statements/templates` | `reports.financial_statements.configure` | Create a new template version |
| GET | `/api/v1/financial-statements/notes` | `reports.financial_statements.read` | List notes for a fiscal period |
| POST | `/api/v1/financial-statements/notes` | `reports.financial_statements.notes.write` | Create/update a note (creates a new draft version) |

## Generate — request/response example

```json
POST /api/v1/financial-statements/generate
X-Company-Id: 4821
Authorization: Bearer eyJhbGciOi...

{
  "statement_type": "balance_sheet",
  "scope_type": "company",
  "as_of_date": "2026-06-30",
  "presentation_currency": "KWD",
  "framework": "IFRS",
  "comparison": { "type": "prior_year_same_date" },
  "mode": "historical"
}
```

```json
{
  "success": true,
  "data": {
    "snapshot_id": 91234,
    "statement_type": "balance_sheet",
    "as_of_date": "2026-06-30",
    "presentation_currency": "KWD",
    "is_balanced": true,
    "balance_variance": 0.0000,
    "lines": [
      { "line_code": "CA_CASH", "label_en": "Cash and Cash Equivalents", "current_value": 284650.1200,
        "comparison_value": 251900.0000, "variance_amount": 32750.1200, "variance_percent": 13.00 },
      { "line_code": "CA_RECEIVABLES", "label_en": "Trade and Other Receivables", "current_value": 142500.0000,
        "comparison_value": 119300.0000, "variance_amount": 23200.0000, "variance_percent": 19.45 },
      { "line_code": "TOTAL_CURRENT_ASSETS", "label_en": "Total Current Assets", "current_value": 512310.5000,
        "comparison_value": 447600.0000, "variance_amount": 64710.5000, "variance_percent": 14.46 },
      { "line_code": "TOTAL_ASSETS", "label_en": "TOTAL ASSETS", "current_value": 918440.7500,
        "comparison_value": 831200.0000, "variance_amount": 87240.7500, "variance_percent": 10.50 }
    ]
  },
  "message": "Balance sheet generated successfully",
  "errors": [],
  "meta": { "pagination": null, "template_version": 3, "generation_mode": "historical" },
  "request_id": "b6f2e1a4-8d3c-4e2a-9f11-7c5a2e9d1b44",
  "timestamp": "2026-07-16T09:14:02Z"
}
```

## Preview — consolidated draft

```json
GET /api/v1/financial-statements/preview?statement_type=income_statement&scope_type=consolidated_group&period_start=2026-01-01&period_end=2026-06-30&presentation_currency=KWD
```

```json
{
  "success": true,
  "data": {
    "snapshot_id": null,
    "is_preview": true,
    "eliminations_approved": false,
    "consolidated_company_ids": [4821, 5390, 5512],
    "lines": [
      { "line_code": "NET_REVENUE", "label_en": "Net Revenue", "current_value": 3184220.0000 },
      { "line_code": "GROSS_PROFIT", "label_en": "Gross Profit", "current_value": 1409880.0000 },
      { "line_code": "PROFIT_FOR_PERIOD", "label_en": "Profit for the Period", "current_value": 402115.5000 },
      { "line_code": "NCI_ALLOCATION", "label_en": "Attributable to Non-Controlling Interest", "current_value": 38900.2000 }
    ]
  },
  "message": "Preview generated — eliminations not yet approved; not eligible for final/export",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1a9c7e33-2b41-4a6e-8e0d-9f3c7a5b2e10",
  "timestamp": "2026-07-16T09:20:11Z"
}
```

## Export — PDF request

```json
POST /api/v1/financial-statements/91234/export
{ "format": "pdf", "include_notes": true, "include_ai_insights": true, "watermark": "DRAFT" }
```

```json
{
  "success": true,
  "data": { "export_id": "exp_7f3a1c", "download_url": "https://api.qayd.app/v1/exports/exp_7f3a1c.pdf",
             "expires_at": "2026-07-16T10:20:11Z", "size_bytes": 482119 },
  "message": "Export generated",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "e4d21b0a-9f3c-4b1e-8a2d-6c5e3f9b1a77",
  "timestamp": "2026-07-16T09:21:04Z"
}
```

## Share — create external link

```json
POST /api/v1/financial-statements/91234/share
{ "permission": "view_and_export", "recipient_email": "auditor@deloitte-kw.example",
  "recipient_label": "External Auditor - Deloitte Kuwait", "expires_in_days": 14 }
```

```json
{
  "success": true,
  "data": { "share_id": 331, "share_url": "https://app.qayd.app/shared/fs/9f3e7c2a1b4d6e8f",
             "expires_at": "2026-07-30T09:22:00Z" },
  "message": "Share link created",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "7c2a1b4d-6e8f-4a3c-9d1e-2f5b8a7c3e10",
  "timestamp": "2026-07-16T09:22:00Z"
}
```

## Schedule — recurring board pack

```json
POST /api/v1/financial-statements/schedules
{
  "name": "Monthly Board Pack",
  "statement_types": ["balance_sheet", "income_statement", "cash_flow_statement"],
  "scope_type": "consolidated_group",
  "recurrence": "monthly",
  "run_on_day": 3,
  "recipients": ["cfo@company.example", "owner@company.example"],
  "format": "pdf",
  "include_ai_insights": true
}
```

```json
{
  "success": true,
  "data": { "schedule_id": 58, "report_schedule_id": 4471, "next_run_at": "2026-08-03T06:00:00Z" },
  "message": "Schedule created",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3f9c1e2a-7b4d-4a8e-9c1f-5e2a7b9d3f10",
  "timestamp": "2026-07-16T09:23:40Z"
}
```

# Permissions

Permission keys follow the platform's `<area>.<entity>.<action>` convention, default-deny (a role gets nothing until explicitly granted).

**Permission keys introduced by this module:**

- `reports.financial_statements.read` — view any generated/finalized statement in scope.
- `reports.financial_statements.generate` — trigger a new live/historical/comparative/consolidated generation.
- `reports.financial_statements.review` — move a snapshot through `under_review`.
- `reports.financial_statements.approve` — move a snapshot to `final`, approve consolidation eliminations (BR-06), approve AI-drafted reserve transfers (BR-08), approve AI Narrative Report Generation for external distribution.
- `reports.financial_statements.export` — export to PDF/Excel/CSV/JSON.
- `reports.financial_statements.share` — create/revoke external share links.
- `reports.financial_statements.schedule` — create/modify recurring generation+distribution schedules.
- `reports.financial_statements.configure` — create/activate `financial_statement_templates` and `financial_statement_lines` mappings.
- `reports.financial_statements.notes.write` — author/edit `financial_statement_notes`.
- `reports.financial_statements.consolidation.manage` — run/approve intercompany eliminations and consolidation scope changes.

**Role matrix:**

| Permission | Owner | Admin | Finance Manager | CFO | Auditor (internal) | External Auditor | AI Agent |
|---|---|---|---|---|---|---|---|
| `reports.financial_statements.read` | Yes | Yes | Yes | Yes | Yes | Scoped (shared snapshots only) | Yes (own outputs only) |
| `reports.financial_statements.generate` | Yes | Yes | Yes | Yes | No | No | No (never initiates; only enriches on request) |
| `reports.financial_statements.review` | Yes | No | Yes | Yes | Yes | No | No |
| `reports.financial_statements.approve` | Yes | No | Yes | Yes | No | No | No |
| `reports.financial_statements.export` | Yes | Yes | Yes | Yes | Yes | Scoped (per share permission) | No |
| `reports.financial_statements.share` | Yes | No | Yes | Yes | No | No | No |
| `reports.financial_statements.schedule` | Yes | No | Yes | Yes | No | No | No |
| `reports.financial_statements.configure` | Yes | No | Yes | Yes | No | No | No |
| `reports.financial_statements.notes.write` | Yes | No | Yes | Yes | No | No | No (drafts only, via `is_auto_generated`; cannot set `status = 'approved'`) |
| `reports.financial_statements.consolidation.manage` | Yes | No | Yes (draft only) | Yes (approve) | No | No | No |

External Auditor access is never role-based in the general sense — it is exclusively granted per `financial_statement_shares` row (BR/API — Share), time-limited, and revocable, never a standing account role with open-ended access to the company's financial statements. The AI Agent identity itself is a first-class row in `users` (a system service account) so that every `ai_conversations`/`audit_logs` entry it produces attributes cleanly, but it is bound by the same permission checks as any human — it cannot call `.generate`, `.approve`, `.share`, or `.configure` under any circumstance, consistent with the platform-wide rule that AI never bypasses authorization.

# Notifications

The module fires notifications (delivered via the existing `notifications` foundation table and, where configured, Laravel Reverb WebSocket push to the frontend) on the following domain events:

| Event | Trigger | Recipients |
|---|---|---|
| `financial_statement.generated` | A live/historical statement finishes generating | The requesting user only (toast-level, not persisted) |
| `financial_statement.snapshot.final` | A snapshot is approved to `status = 'final'` | Finance Manager, CFO, Owner |
| `financial_statement.unbalanced` | VR-01 fails (Balance Sheet does not balance) | Finance Manager, CFO — flagged high-priority |
| `financial_statement.consolidation.eliminations_pending_review` | A consolidation run drafts a new elimination set (BR-06) | Finance Manager, CFO |
| `financial_statement.ai.risk_flag_high_confidence` | AI Risk Detection returns a flag with confidence ≥ 0.75 | CFO, Auditor (internal) |
| `financial_statement.note.pending_approval` | A note moves to `reviewed`, awaiting `approved` | Finance Manager, CFO |
| `financial_statement.share.created` | An external share link is created | The creating user (confirmation) + the recipient (email with the share URL, if `recipient_email` set) |
| `financial_statement.share.accessed` | A share link is opened by its recipient | The user who created the share |
| `financial_statement.share.expiring_soon` | A share link is within 24 hours of `expires_at` | The user who created the share |
| `financial_statement.schedule.run_completed` | A `report_schedules`-driven recurring generation completes | All configured recipients, via their chosen channel (in-app, email, both) |
| `financial_statement.schedule.run_failed` | A scheduled generation errors (e.g., missing exchange rate, VR-08) | Finance Manager, CFO — high-priority, includes the specific validation failure |
| `financial_statement.year_end.reserve_transfer_drafted` | AI drafts the statutory/voluntary reserve transfer (BR-08) at year-end close | Finance Manager, CFO |
| `financial_statement.regeneration.reason_required` | A user attempts to regenerate a `final` closed-period snapshot | The requesting user (blocking prompt, not a passive notification) — proceeds only once a `regeneration_reason` is supplied |

# Security

**Tenant isolation.** Every query in the Report Generation Engine is scoped by `company_id` at the repository layer, never left to a controller-level filter; the same Postgres row-level pattern used across the platform applies here — a company can never generate, view, or export another company's statement data, and this is defended in depth with both application-layer scoping and (where the deployment topology supports it) Postgres Row Level Security policies on every table in this module keyed to the session's active `company_id`.

**Share-link security.** `financial_statement_shares.share_token` is a cryptographically random 256-bit token (never a sequential or guessable id), never logged in plaintext outside the `financial_statement_shares` row itself, transmitted only over HTTPS, and every access is checked against `expires_at` and `revoked_at` before the snapshot is rendered. Shares default to `view_only`; `view_and_export` must be explicitly selected and is logged distinctly in `audit_logs`. No share link ever grants write access, drill-down into unrelated periods, or visibility into AI Insights unless `include_ai_insights` was explicitly set at creation.

**PII and sensitive data minimization.** Financial statements are company-level financial data, not personal data, but Notes (e.g., Related Party Transactions, Payroll-derived provision disclosures) can indirectly reference named individuals (directors, key management). The Notes authoring workflow requires an explicit `contains_related_party_pii` flag on any note referencing a named individual, which the export pipeline uses to apply an additional confirmation step before an external share of that note is permitted.

**Export integrity.** Every PDF/Excel export embeds the `result_hash` (from `financial_statement_snapshots.result_hash`) as a footer watermark and as embedded document metadata, so a printed or emailed copy can always be checked against the system of record to detect post-export tampering.

**Secrets and AI boundary.** The AI Layer never receives direct database credentials; it calls back into the Laravel API using a short-lived, narrowly-scoped service token that carries the same company/permission context as the human user who triggered the enrichment (or, for AI-initiated scheduled jobs, the system service account's own permissions, which — per Permissions above — never include generate/approve/share/configure).

**Rate limiting.** `.generate` and `.export` endpoints are rate-limited per user (default 60 requests/minute) and per company (default 300 requests/minute) to prevent a runaway integration or script from placing excessive load on the ledger aggregation queries; limit breaches return HTTP 429 per the platform's standard error codes.

# Audit Trail

Every mutating and every sensitive read action in this module writes an `audit_logs` row capturing actor (`user_id` or the AI service account), action, target (`snapshot_id`/`template_id`/`share_id`/`note_id`), old value, new value, reason (where applicable — e.g., `regeneration_reason`), IP address, and device/user-agent, per the platform's standing audit rule.

**Actions specifically audited by this module (non-exhaustive, illustrative of granularity):**

- `financial_statement.generated` (live/historical/comparative/consolidated) — logs the full generation parameters even though the result itself may not be persisted as a snapshot, so "who looked at what numbers, when" is always answerable even for ephemeral real-time views.
- `financial_statement.snapshot.status_changed` — every `draft → under_review → final → superseded` transition, with old/new status and the acting user.
- `financial_statement.template.version_created` / `.activated` — full diff of the mapping change (old `financial_statement_lines` set vs. new).
- `financial_statement.note.status_changed` — draft/reviewed/approved transitions, with the content diff.
- `financial_statement.share.created` / `.accessed` / `.revoked` — every access to a shared snapshot by an external party, including the accessing IP, distinct from internal user audit entries.
- `financial_statement.export.generated` — format, `include_notes`/`include_ai_insights` flags, and the resulting export's hash.
- `financial_statement.consolidation.eliminations_approved` — the specific elimination journal entry set approved, and by whom.
- `financial_statement.ai.analysis_generated` — every AI Responsibilities job run against `final` or statutory-filed data, per the AI Responsibilities guardrail section above.

Audit log rows for this module are, like every other financial audit record on the platform, never hard-deleted; they are the primary evidence trail for any external audit, regulatory inquiry, or internal dispute over "what did the CFO see and when."

# Performance

**Materialized aggregation.** The `ledger_entries` projection (owned by the core Accounting Engine, consumed here) is the primary performance lever: statement generation never scans raw `journal_lines` for a full fiscal year when a maintained, indexed projection already carries posted-period balances. For companies with unusually high transaction volume (e.g., retail chains posting thousands of daily sales journal entries), `ledger_entries` is additionally pre-aggregated into monthly account-balance rollups (`ledger_entries` carries a `period_bucket` column maintained by the core engine) so that a Yearly statement's account-level sums are a 12-row aggregation per account rather than a scan of the full year's line-level detail.

**Snapshot-first for closed periods.** Per the Historical generation mode, once a closed period's statement has been generated once, every subsequent request for that exact (company, statement_type, period, currency, template_version) combination is a single indexed lookup against `financial_statement_snapshots`/`financial_statement_snapshot_lines` — no ledger aggregation query runs at all. This is the dominant performance case in practice, since the overwhelming majority of statement views are for already-closed periods (board packs, comparatives, audits).

**Redis caching for real-time.** Live-mode generation results for the current open period are cached in Redis with a 60-second TTL keyed on the full request fingerprint (company, scope, period, currency, template version). This bounds worst-case load from a dashboard auto-refreshing every few seconds to one real aggregation query per minute per unique view, regardless of concurrent viewer count.

**Consolidation cost isolation.** Because a consolidation run requires generating N subsidiary statements, translating each, and running the elimination pipeline, it is always dispatched as a queued Laravel job (Redis-backed queue) rather than a synchronous request-response cycle, even for a `preview` — the API returns a job reference immediately and the frontend polls or subscribes via Reverb for completion, preventing a slow consolidation from holding an HTTP worker or timing out a request.

**Asynchronous AI enrichment.** AI Responsibilities jobs never block statement generation or export; a snapshot is fully usable (viewable, exportable, shareable) the instant it is generated, with AI Insights populating the dedicated panel a few seconds to a couple of minutes later depending on job queue depth. The frontend polls `/insights` or subscribes to the corresponding Reverb channel and renders a "Generating AI analysis…" placeholder in the interim.

**Export rendering.** PDF/Excel rendering is CPU-bound and is offloaded to a dedicated queue worker pool distinct from the general application queue, with a per-export timeout (default 30 seconds) after which the job is retried once and then surfaces a `financial_statement.schedule.run_failed`-style error to the requester rather than hanging indefinitely.

**Index maintenance.** All indexes declared under Database Design are designed as partial indexes wherever a `WHERE deleted_at IS NULL` / `WHERE status = 'final'` predicate can shrink the index substantially, since the overwhelming majority of query traffic targets active, final, non-deleted rows and there is no benefit to indexing soft-deleted or superseded historical noise for the hot query paths.

# Edge Cases

- **First fiscal period of a new company.** No prior-period comparative exists; all comparison columns, ROA/ROE/turnover ratios, and Horizontal Analysis return `null` with an explicit `reason` code rather than a misleading zero or a divide-by-zero error.
- **Mid-year change of functional/base currency.** Extremely rare and requires a formal accounting policy change; the module supports it only via a dated `companies.base_currency` change record, generating a mandatory Note disclosing the change, its rationale, and the date, and treats all figures before that date as requiring translation for any single-currency trend/comparative view spanning the change.
- **Branch statement that does not balance on its own.** As noted under Report Generation Engine — Branch, a branch-scoped Balance Sheet is not required to independently satisfy Assets = Liabilities + Equity, since head-office-only equity and certain liability accounts are never branch-attributed; the module labels this clearly ("Branch view — Head Office balances excluded") rather than presenting a false balance-check failure.
- **Subsidiary acquired or disposed of mid-year.** Consolidation includes the subsidiary's results only from the acquisition date (or up to the disposal date), computed from the specific `journal_lines.entry_date` range within the subsidiary's own books, not a full-year pro-rata estimate; goodwill/bargain purchase gain on acquisition and any gain/loss on disposal (including CTA recycling per IAS 21 §48) are posted as distinct, clearly labeled Income Statement or equity lines in the period of the event.
- **Negative equity.** A company whose accumulated losses exceed contributed capital produces a negative Total Equity figure; the module renders this without alteration (it is a valid, important solvency signal) and the AI Risk Detection responsibility always flags negative equity as a high-confidence risk item regardless of the company's configured anomaly sensitivity threshold.
- **Zero-transaction period.** Per BR-01, returns a fully-structured, all-zero statement rather than an error — important for a newly onboarded company or a dormant branch.
- **Regeneration of a filed statutory statement.** If a material error is discovered in a statement already marked `is_statutory_filing = true`, the module never silently updates it (blocked by the immutability trigger); it requires a new, explicitly reasoned superseding snapshot and surfaces a mandatory restatement disclosure Note, mirroring how a real restatement would be handled under IAS 8.
- **Exchange rate gap on a bank holiday or thin-market currency pair.** VR-08 blocks generation rather than guessing; the recommended resolution path (surfaced in the error response) is for the Finance Manager to enter a manual policy rate in `exchange_rates` with `rate_source = 'manual_policy'`, which is itself logged and disclosed.
- **Two users requesting the same live statement concurrently.** The 60-second Redis cache serves the second requester the first requester's result rather than double-running the aggregation query; if the underlying ledger changes between the two requests (a posting occurred in that window), the cache is invalidated by a targeted cache-bust on `journal_entries` posting events, not solely by TTL expiry, to avoid serving a stale-but-not-yet-expired result immediately after a relevant posting.
- **Formula line referencing a not-yet-computed sibling.** The engine resolves `financial_statement_lines.formula` dependencies via topological sort at template-activation time (VR-04-adjacent validation), rejecting any template containing a circular formula reference before it can ever be activated, rather than discovering the cycle at generation time.
- **Company with no configured template.** Every company is seeded with the QAYD system-default `is_system_default = true` template at company creation; a company can never reach a state with zero active templates for a given (statement_type, framework) pair, so "no template configured" is not a runtime state the generation engine needs to handle defensively — it is prevented at company-provisioning time.

# Future Improvements

- **XBRL/inline-XBRL tagging.** As GCC regulators (following the global trend set by IFRS Foundation's XBRL taxonomy) move toward mandated electronic structured filing, extend `financial_statement_lines` with an XBRL element mapping and add an `xbrl` export format alongside PDF/Excel/CSV/JSON.
- **Rolling forecast statements as a first-class snapshot type.** Currently the AI Forecast Agent's output is presented as an insight attached to an actual snapshot; a future iteration would let a forecast itself become a lightweight `financial_statement_snapshots` row (`generation_mode = 'forecast'`) so forecast-vs-actual comparatives become a native Comparative report type rather than a bespoke AI Insights view.
- **Close calendar and workflow orchestration.** Wrap the manual month-end close process (accruals, reconciliations, statutory reserve transfer, statement generation, review, approval) into a tracked `close_tasks` workflow that culminates automatically in the statement generation and snapshot-finalization steps this module already provides, giving the CFO a single progress view of "days to close."
- **What-if / scenario statements.** Allow a Finance Manager to clone a live statement into an editable scenario workspace (e.g., "what if we collect 30 days faster on receivables") that recomputes downstream ratios and cash position without ever touching posted ledger data — a sandboxed, clearly-labeled variant of the Preview generation mode.
- **Materiality-driven auto-disclosure suggestions.** Extend BR-10's materiality check so the AI Reporting Agent proactively drafts a Note (not just flags a gap) for any line crossing the materiality threshold, subject to the same approval gate already in place for AI-authored content.
- **Real-time collaborative review.** Add Reverb-powered live cursors/comments on a `draft`/`under_review` snapshot so a Finance Manager and CFO can review the same statement simultaneously with inline discussion threads, rather than the current asynchronous review-then-approve flow.
- **Deeper segment reporting automation.** Automatically infer IFRS 8 "reportable segments" from `departments`/`branches`/`projects` activity patterns (revenue/asset concentration) rather than requiring a controller to manually flag which dimensions qualify as reportable segments.
- **Benchmark-aware AI analysis.** Enrich the AI Financial Analysis and Ratio Analysis responsibilities with anonymized, aggregated peer-industry benchmarks (opt-in, privacy-preserving) so a company's ratios can be contextualized against comparable Gulf-market businesses, not just its own history.

# End of Document
