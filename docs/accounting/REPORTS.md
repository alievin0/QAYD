# Reporting & Analytics — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Reporting & Analytics
---

# Purpose

The Reporting & Analytics module is QAYD's presentation and intelligence layer over every other module in the platform. It owns zero primary business data. Every figure, chart, table, and narrative sentence it produces is derived — at query time, via a materialized view, or via a versioned, reproducible snapshot — from the operational tables owned by Accounting, Sales, Purchasing, Inventory, Payroll, Tax, Banking, and Customers/Vendors. This is the same architectural invariant that governs the Financial Statements submodule (Balance Sheet, Income Statement, Cash Flow, Statement of Changes in Equity), and Reporting & Analytics is its superset: it is the general-purpose reporting, dashboarding, analytics, and AI-narrative engine that sits above the whole ERP, of which the four primary financial statements are simply the highest-privilege, most rigidly standardized special case.

Concretely, this module is responsible for:

1. **Dashboard rendering** — role-scoped, real-time (or near-real-time) visual summaries for Executive, Finance, Sales, Inventory, Payroll, Tax, Operations, and AI-Insights audiences, each pulling from the relevant domain tables and pre-aggregated read models.
2. **Report cataloguing and generation** — a library of pre-built "Standard Reports" (Balance Sheet, Income Statement, Trial Balance, Aging schedules, and 12 others enumerated below) plus an open-ended catalogue of company-defined Custom Reports, all generated on demand or on a schedule.
3. **A no-code Report Builder** — drag-and-drop report composition (fields, filters, grouping, sorting, calculated fields) that lets a Finance Manager or Auditor build and save a new report definition without engineering involvement, and share or schedule it exactly like a Standard Report.
4. **Analytics** — trend analysis, variance analysis, forecasting, period-over-period and budget-vs-actual comparisons, financial ratio computation, peer/industry benchmarking, ABC/XYZ inventory classification, and AI-composed executive insight narratives.
5. **Export and distribution** — rendering any report to PDF, Excel (XLSX), CSV, or JSON; exposing the same data over a versioned REST API; emailing scheduled report runs; and producing print-optimized layouts.
6. **AI augmentation** — every report and dashboard can be enriched by the AI Reporting Agent and its collaborating specialist agents (Forecast Agent, Fraud Detection, CFO Agent, Compliance Agent) with narrative summaries, anomaly flags, trend callouts, and answers to natural-language questions — always labeled as AI-generated, always carrying a confidence score and a citation trail back to the source documents and journal lines.
7. **Governance** — a strict, default-deny permission model per report category and per dashboard, full audit logging of every report generation, export, share, and schedule change, and retention/versioning of every historical snapshot for audit reproducibility.
8. **Performance at scale** — because heavy reports (e.g., a five-year Trial Balance for a 40-branch group) can scan tens of millions of `journal_lines` rows, this module runs expensive report generation asynchronously via a queue (`report_runs`), backed by materialized views, incremental aggregation tables, and a caching layer, so that the interactive dashboard experience stays sub-second while heavy batch reports complete reliably in the background.

Reporting & Analytics is consumed by every human role in the platform (Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, HR Manager, Payroll Officer, Inventory Manager, Warehouse Employee, Sales Manager, Sales Employee, Purchasing Manager, Purchasing Employee, Auditor, External Auditor, Read Only) and by the AI Layer (Reporting Agent, CFO Agent, Forecast Agent, Fraud Detection, Compliance Agent), which reads the same reports a human would read, through the same permission checks, before producing any narrative or recommendation.

# Vision

Most Gulf SME and mid-market ERPs treat reporting as an afterthought bolted onto the accounting core: a handful of hard-coded PDF templates, an "export to Excel" button, and a separate, disconnected BI tool (Power BI, Tableau, or a spreadsheet graveyard) that someone manually refreshes once a month. The result is that decision-makers see stale numbers, auditors receive inconsistent exports, and every "can you also break this down by branch/project/cost-center" request becomes a support ticket.

QAYD's Reporting & Analytics module exists to make every report a live, permissioned, AI-narrated view of the single source of truth, with the following outcomes as the design target:

- **Zero-lag truth.** Because standard reports are derived directly from posted `journal_lines` (via the `ledger_entries` projection) and from the operational tables of Sales, Purchasing, Inventory, Payroll, and Tax, a report opened at 9:00 AM and refreshed at 9:05 AM after a bank reconciliation posts reflects the new data immediately — no nightly batch, no "as of last night" caveat, except for the class of genuinely heavy reports that are intentionally asynchronous for performance reasons and are then explicitly timestamped with their `as_of` cutoff.
- **One report engine, infinite reports.** The same generation pipeline (definition → parameters → query plan → render → export) powers all sixteen Standard Reports, all Custom Reports built through the Report Builder, and every dashboard widget. There is no special-cased "Balance Sheet code path" that diverges from the general reporting engine; the Balance Sheet is simply a `report_definitions` row with `is_system = true` and a locked template.
- **Self-service without engineering.** A Finance Manager should be able to build "Sales by region, this quarter vs last quarter, filtered to invoices over KWD 500, grouped by sales rep, with a calculated gross-margin-percent column" in the Report Builder in under two minutes, save it, schedule it to email the sales team every Monday at 8 AM, and never file an engineering ticket.
- **AI as an analyst, not a black box.** Every dashboard and report carries an "AI Insights" panel — narrative summary, anomaly flags, forecast, recommended actions — that is visually and structurally separate from the audited/derived figures, always shows its confidence score, and always cites the specific report rows, accounts, or documents that produced each claim. AI never silently edits a number; it only annotates, forecasts, and explains.
- **Governed by default.** Every report category, every dashboard, and every export inherits the platform's default-deny RBAC. A Warehouse Employee cannot generate a Payroll Summary by discovering an unguarded API route; a Sales Employee sees Sales Analysis scoped to their own pipeline, not the whole company's, unless explicitly granted broader access.
- **Reproducible for audit.** Any report a human or an External Auditor looks at during an audit can be regenerated byte-for-byte from its `report_runs` parameters (company, period, filters, template version, `as_of` posting cutoff) months or years later, with a full drill-down chain back to the originating journal entries and source documents.
- **Scales from one branch to one hundred.** The same dashboard and report definitions work unmodified whether a company has one branch and ten employees or is a holding group with dozens of subsidiaries, branches, cost centers, and projects — dimensional filtering and grouping are first-class, not a special "enterprise tier" feature.

The long-term vision extends the module toward a semantic layer that lets the AI Reporting Agent answer arbitrary natural-language business questions ("which vendor's payment terms cost us the most in late-payment discounts lost last quarter?") by composing ad hoc queries against the same governed data model that powers the Report Builder, and toward embedding QAYD dashboards inside third-party tools (Slack digests, WhatsApp Business summaries, board-deck exports) without ever duplicating the underlying data outside QAYD's control boundary.

# Reporting Philosophy

Reporting & Analytics is governed by a small set of non-negotiable principles that shape every decision below:

**1. Reports are views, never stores of primary data.** No table in this module is a system of record for a business fact. `report_snapshots` exist purely as a performance cache and an audit artifact of a specific historical run — they are always reproducible by re-executing the same `report_definitions` query with the same parameters against the owning module's tables as of the same `as_of` cutoff. If a snapshot and a fresh re-generation of a *closed* period ever disagree, that is a data-integrity defect to investigate, not a discrepancy to paper over by trusting the snapshot.

**2. Every module owns its own numbers; Reporting only composes them.** The Reporting engine never writes to `journal_lines`, `inventory_items`, `payroll_items`, `invoices`, or any other operational table. It reads them — directly for light, interactive queries, or through a materialized view/aggregation table for heavy ones — and it is the exclusive owner only of its own metadata tables: `report_definitions`, `report_schedules`, `report_runs`, `report_snapshots`, and their supporting tables (`report_shares`, `report_favorites`, `report_comments`).

**3. Heavy is asynchronous; light is instant.** Any report whose query plan is expected to scan a large working set (multi-year Trial Balance, group-wide consolidated Sales Analysis, a Payroll Summary across 500+ employees for 24 months) is executed as a background job tracked in `report_runs` with a `queued → running → completed | failed` lifecycle, and the user is notified when it is ready. Any report scoped to a small, indexed, or materialized working set (today's Cash Position, this month's KPI dashboard tile) renders synchronously, target latency under 800 ms server-side.

**4. Every number is drillable.** A user looking at "Accounts Receivable Aging: 61–90 days, KWD 24,300" can always drill from that aggregate into the contributing customer balances, then into the contributing invoices, then into the original sales order and delivery. Aggregation in a report or dashboard tile never destroys the lineage back to source documents; every generated report row carries enough dimensional keys (`customer_id`, `invoice_id`, `journal_line_id`, etc., as applicable) to support drill-down without a second query language.

**5. Presentation is configuration, not code.** A report's shape — which columns, which grouping, which filters, which calculated fields, which chart type — is data (`report_definitions.config` JSONB), not a hard-coded template requiring a deploy. The sixteen Standard Reports differ from Custom Reports only in that their `report_definitions` rows are seeded, marked `is_system = true`, and their core columns are locked against deletion (though filters, grouping, and additional calculated columns remain user-configurable even on system reports, subject to permission).

**6. Comparatives, history, and versioning are first-class.** Every report generation call accepts an optional comparison period (prior year, prior period, budget, or an arbitrary second date range) and every historical report run is retained as a `report_snapshots` row referencing an immutable rendered payload, subject only to the platform's standard retention and legal-hold policies. There is no "we only keep the last quarter" ceiling on report history.

**7. AI analyzes; it never authors the audited number.** The AI Reporting Agent's narrative, forecast, anomaly flag, or recommendation is always rendered in a clearly delineated "AI Insights" region attached to — never merged into — the underlying report's derived figures. AI output always carries `confidence` (0–1), `reasoning`, and `source_refs` (the specific report rows, account codes, or document IDs that support the claim). AI never edits a report's data payload and never approves its own narrative for external distribution; a human role with the relevant permission does that.

**8. Default deny, scoped by role and by dimension.** A user's ability to run, view, export, schedule, or share a report is governed first by the standard RBAC permission key for that report category, and second — where relevant — by row-level dimensional scoping (a Sales Employee's Sales Analysis is automatically filtered to `sales_rep_id = current_user`, a Branch Manager's dashboards are automatically filtered to `branch_id = their_branch`, unless a broader permission is explicitly granted).

**9. One engine, many surfaces.** The exact same `report_definitions` → `report_runs` → rendered payload pipeline backs the dashboard widgets, the Standard Reports library, the Report Builder's saved reports, the scheduled email digests, and the public API. A dashboard KPI tile is, structurally, a report definition with `output_format = 'widget'` and a small, cached, frequently-refreshed query.

**10. Multi-currency, multi-entity, and multi-dimension are the default case, not an edge case.** Every report in this module is dimension-aware (`company_id`, `branch_id`, `cost_center_id`, `project_id`, `department_id`) and currency-aware (transaction currency + base currency, with an optional presentation currency for consolidated views) from its first line of design, consistent with the platform's Gulf-first, multi-market roadmap.

# Dashboard Architecture

Every dashboard is a named collection of **widgets**. A widget is a `report_definitions` row with `output_format = 'widget'`, a small `config.layout` (grid position, size), a refresh interval, and a rendering hint (`kpi_tile`, `line_chart`, `bar_chart`, `donut_chart`, `table`, `heatmap`, `gauge`, `sparkline`). Dashboards are stored as `report_definitions` rows of `category = 'dashboard'` whose `config.widgets` array references the widget definitions by `id`, in display order. This keeps dashboards inside the same versioned, permissioned, auditable object model as every other report rather than as a separate frontend-only construct.

All dashboards share:
- **Auto-refresh** on a per-widget interval (default 60s for KPI tiles fed by materialized views, 5s for the AI Dashboard's live anomaly feed, on-demand for heavy charts) implemented via polling today and upgraded to push via Laravel Reverb WebSocket channels (`private-company.{id}.dashboard.{dashboard_id}`) for widgets flagged `realtime: true`.
- **Dimensional filter bar** — company (always fixed to `X-Company-Id`), branch, date range (with quick presets: Today, This Week, This Month, This Quarter, This Year, Last 12 Months, Custom), and, where relevant, cost center/project/department — that cascades into every widget on the dashboard simultaneously.
- **Drill-through** — clicking any KPI tile or chart segment navigates to the underlying Standard Report or Custom Report, pre-filtered to match the clicked dimension/value.
- **Personalization** — a user can hide, reorder, and resize widgets on their own dashboard instance (`user_dashboard_layouts`, a per-user override layer that never mutates the shared `report_definitions` row).
- **AI Insights strip** — a persistent, collapsible panel at the top of every dashboard showing the 1–3 most relevant AI-generated observations for the current filter context (see AI Responsibilities → Executive Summary).

## Executive Dashboard

Audience: Owner, CEO, board members (via share link). Purpose: a single screen answering "how is the business doing, right now, across every function."

| Widget | Source | Refresh |
|---|---|---|
| Revenue (MTD/QTD/YTD) vs. prior period | `invoices`, `credit_notes` (net revenue) | 60s |
| Net Profit Margin | Income Statement derivation | 60s |
| Cash Position (all bank accounts, base currency) | `bank_accounts`, `bank_transactions` | 30s |
| AR vs. AP (net working capital snapshot) | Customer/Vendor Aging aggregates | 5 min |
| Top 5 Customers by Revenue (rolling 12 months) | `invoices` grouped by `customer_id` | 15 min |
| Top 5 Products by Margin | `invoice_items` joined to `products.cost_price` | 15 min |
| Headcount & Payroll Cost (MTD) | `employees`, `payroll_runs` | 1 hour |
| Open Purchase Commitments | `purchase_orders` status `open` | 5 min |
| Inventory Value on Hand | `inventory_valuations` | 15 min |
| Company Health Score (AI-composed 0–100) | AI Reporting Agent composite | On dashboard load |
| AI Insights strip | AI Reporting Agent | On dashboard load |

## Finance Dashboard

Audience: CFO, Finance Manager, Senior Accountant. Purpose: the operational finance cockpit — GL health, statement readiness, cash, AR/AP.

| Widget | Source |
|---|---|
| Trial Balance out-of-balance check (should always read 0.00) | `journal_lines` SUM(debit) − SUM(credit) |
| Unposted (draft) journal entries count & aging | `journal_entries` status `draft` |
| Fiscal period close status (open/closed per period) | `fiscal_periods` |
| Cash & bank balances by account | `bank_accounts` |
| AR Aging summary (bucketed) | Customer Aging aggregate |
| AP Aging summary (bucketed) | Vendor Aging aggregate |
| Bank reconciliation status (reconciled vs. outstanding items) | `bank_reconciliations` |
| Budget vs. Actual (current period, by account group) | `journal_lines` vs. `budgets`/`budget_lines` |
| Expense trend (12-month line) | `journal_lines` filtered to expense account types |

## Sales Dashboard

Audience: Sales Manager, Sales Employee (row-scoped to own pipeline unless `sales.read.all`).

| Widget | Source |
|---|---|
| Pipeline value by stage (funnel) | `leads`, `sales_quotations` |
| Sales Orders this period vs. target | `sales_orders` |
| Revenue by product category | `invoice_items` joined `product_categories` |
| Revenue by sales rep (leaderboard) | `invoices` grouped by `created_by`/assigned rep |
| Win rate & average deal size | `sales_quotations` → `sales_orders` conversion |
| Top open quotations expiring soon | `sales_quotations` status `sent`, `valid_until` window |
| Customer churn/at-risk flag (AI) | AI Fraud/CFO Agent pattern on declining order frequency |

## Inventory Dashboard

Audience: Inventory Manager, Warehouse Employee (row-scoped to assigned warehouse unless `inventory.read.all`).

| Widget | Source |
|---|---|
| Stock on hand by warehouse (value & quantity) | `inventory_items` |
| Low-stock / reorder-point alerts | `inventory_items` vs. `products.reorder_point` |
| Stock movements (in/out) trend | `stock_movements` |
| Pending stock transfers & counts | `stock_transfers`, `stock_counts` |
| Slow-moving / dead stock flag | ABC/XYZ analysis (see Analytics) |
| Inventory valuation by method (FIFO/weighted-average) | `inventory_valuations` |

## Payroll Dashboard

Audience: HR Manager, Payroll Officer, CFO (aggregate only for CFO; PII-restricted below that).

| Widget | Source |
|---|---|
| Current payroll run status | `payroll_runs` |
| Payroll cost trend (12 months, by department) | `payroll_items` grouped by `department_id` |
| Headcount trend | `employees` |
| Overtime & allowance cost breakdown | `salary_components` categorized |
| Statutory contribution totals (social security, GOSI-equivalent) | `payroll_items` tax/contribution components |
| Pending leave/expense claims impacting next run | Payroll module inputs |

## Tax Dashboard

Audience: Finance Manager, Tax preparer role, CFO, Auditor (read-only).

| Widget | Source |
|---|---|
| VAT/tax liability this period (output tax − input tax) | `tax_transactions` |
| Filing calendar & next due date | `tax_returns` |
| Tax return status by period | `tax_returns` |
| Tax code exposure breakdown (by `tax_codes`) | `tax_transactions` grouped |
| Effective tax rate trend | Income Statement derivation |

## Operations Dashboard

Audience: COO-equivalent, Purchasing Manager, general Operations role. Purpose: cross-functional operating metrics that don't belong solely to Finance.

| Widget | Source |
|---|---|
| Purchase Orders open/overdue | `purchase_orders` |
| Goods receipt vs. PO variance | `goods_receipts` vs. `purchase_order_items` |
| Vendor on-time delivery rate | `goods_receipts.received_at` vs. `purchase_orders.expected_date` |
| Delivery performance (Sales) | `deliveries` vs. `sales_order_items.promised_date` |
| Open quality inspections | `quality_inspections` |
| Cross-module SLA breaches (AI-flagged) | Compliance Agent scan |

## AI Dashboard

Audience: Owner, CFO, Auditor. Purpose: a single surface for everything AI has recently done, is confident about, or is uncertain about, across the whole platform — the transparency and control panel for AI Responsibilities (see below).

| Widget | Source |
|---|---|
| Pending AI-drafted actions awaiting human approval | Cross-module approval queue (journal entries, payments, tax filings drafted by AI) |
| Anomalies detected (last 7/30 days), by severity | AI Fraud Detection + Reporting Agent anomaly log |
| Forecast accuracy tracker (forecast vs. actual, rolling) | Forecast Agent historical predictions vs. realized figures |
| AI confidence distribution across recent narrative reports | `report_runs.ai_metadata` |
| Natural-language question log (recent Q&A, anonymized) | AI conversation log scoped to reporting context |
| AI agent activity by type (Reporting, Forecast, Fraud, Compliance) | Aggregated `ai_conversations`/`ai_messages` tagged to Reporting module |

# Report Categories

Every `report_definitions` row carries exactly one `category` from the enum below. Categories are the primary axis for cataloguing, permissioning, and navigation (the Report Builder's "New Report" wizard starts by asking for a category, which pre-selects the eligible source tables and default permission key).

| Category | Description | Primary source tables | Default permission key |
|---|---|---|---|
| `financial` | Statutory and management financial reports: Balance Sheet, Income Statement, Cash Flow, Trial Balance, GL, Profitability | `journal_lines`, `ledger_entries`, `accounts` | `reports.financial.read` |
| `operational` | Cross-functional operating metrics not owned by a single ledger domain: SLA breaches, delivery performance, quality inspections | Multiple modules | `reports.operational.read` |
| `inventory` | Stock valuation, movement, aging, ABC/XYZ classification | `inventory_items`, `stock_movements`, `inventory_valuations` | `reports.inventory.read` |
| `sales` | Pipeline, orders, invoicing, customer analysis | `leads`, `sales_orders`, `invoices`, `customers` | `reports.sales.read` |
| `purchasing` | Requests, RFQs, POs, receipts, vendor analysis | `purchase_orders`, `bills`, `vendors` | `reports.purchasing.read` |
| `payroll` | Compensation, headcount, statutory contributions | `payroll_runs`, `payroll_items`, `employees` | `reports.payroll.read` (PII-gated) |
| `tax` | VAT/tax computation, filings, exposure | `tax_transactions`, `tax_returns`, `tax_codes` | `reports.tax.read` |
| `banking` | Cash, bank transactions, reconciliation, transfers | `bank_accounts`, `bank_transactions`, `bank_reconciliations` | `reports.banking.read` |
| `compliance` | Regulatory adherence, filing deadlines, policy breaches | `tax_returns`, `audit_logs`, Compliance Agent findings | `reports.compliance.read` |
| `audit` | Change history, access logs, approval trails | `audit_logs`, `report_runs`, `journal_entries` history | `reports.audit.read` |
| `executive` | Cross-functional summaries for leadership, board packs | Composite of `financial` + `sales` + `inventory` + `payroll` | `reports.executive.read` |
| `custom` | Company-defined reports built via the Report Builder | Any table exposed in the semantic layer (see Report Builder) | `reports.custom.read` (+ owner override) |

Categories are not mutually exclusive from the module's perspective — a Custom Report can join `sales` and `inventory` source tables — but every report definition is tagged with the single category that determines its default permission gate, its placement in the Standard Reports library navigation, and its default retention/export policy. A user who lacks the category's read permission cannot see the report definition exists (default deny extends to discoverability, not just execution).

# Standard Reports

The sixteen Standard Reports are seeded `report_definitions` rows (`is_system = true`, `code` prefixed `STD_`) present in every company from day one, with a locked core column set and company-configurable filters, grouping, and additional calculated columns. Each is described below with its parameters, derivation, and default output columns.

## Balance Sheet

`code = STD_BALANCE_SHEET`, `category = financial`. Owned functionally by the Financial Statements submodule; exposed here as a Standard Report for scheduling, sharing, export, and AI narrative purposes. Parameters: `as_of_date`, `branch_id` (optional), `presentation_currency` (defaults to company base currency), `comparison` (`prior_year` | `prior_period` | `none`), `template_version`.

Derivation: for every `accounts` row mapped to a Balance Sheet line in `financial_statement_lines`, sum `ledger_entries.balance` as of `as_of_date`, group by the line's classification (current asset, non-current asset, current liability, non-current liability, equity). Validates `Assets = Liabilities + Equity` before returning; a failed check raises `report_runs.status = failed` with `error_code = BALANCE_SHEET_UNBALANCED` rather than returning an incorrect statement.

Default columns: `line_item`, `classification`, `current_period_amount`, `comparison_amount`, `variance_amount`, `variance_pct`.

## Income Statement

`code = STD_INCOME_STATEMENT`, `category = financial`. Parameters: `period_start`, `period_end`, `branch_id`, `project_id`, `cost_center_id` (all optional filters), `presentation` (`by_nature` | `by_function`), `comparison`.

Derivation: sums `journal_lines` posted within the period for accounts of type `revenue` and `expense`, mapped through `financial_statement_lines`, computing Gross Profit (Revenue − Cost of Sales), Operating Profit (Gross Profit − Operating Expenses), and Net Profit (Operating Profit + Other Income − Other Expenses − Tax Provision).

Default columns: `line_item`, `current_period_amount`, `comparison_amount`, `variance_amount`, `variance_pct`, `pct_of_revenue`.

## Cash Flow

`code = STD_CASH_FLOW`, `category = financial`. Parameters: `period_start`, `period_end`, `method` (`indirect` default | `direct`), `branch_id`.

Derivation (indirect method): starts from Net Profit (Income Statement derivation for the same period), adds back non-cash items (depreciation, amortization, provisions — identified via `accounts.is_non_cash_addback = true`), adjusts for changes in working capital (ΔAR, ΔInventory, ΔAP computed from opening vs. closing `ledger_entries.balance`), then adds Investing Activities (fixed asset purchases/disposals) and Financing Activities (loan drawdowns/repayments, dividend payments, share issuance/buyback), reconciling to the actual change in `bank_accounts` cash balances over the period — a hard validation identical in spirit to the Balance Sheet check.

Default columns: `section` (`operating`|`investing`|`financing`), `line_item`, `current_period_amount`, `comparison_amount`.

## Trial Balance

`code = STD_TRIAL_BALANCE`, `category = financial`. Owned functionally by the Trial Balance submodule; parameters: `as_of_date`, `fiscal_period_id`, `branch_id`, `account_type_filter`, `include_zero_balances` (boolean, default false), `posting_status` (`posted_only` default | `include_draft`).

Derivation: one row per `accounts` leaf node with non-zero (or all, if `include_zero_balances`) debit/credit balance as of the cutoff, summed from `journal_lines`. The report footer always shows `SUM(debit_balance) = SUM(credit_balance)`; any non-zero difference is a P0 data-integrity alert (see Edge Cases) surfaced both in the report and to the AI Fraud Detection agent.

Default columns: `account_code`, `account_name`, `account_type`, `opening_debit`, `opening_credit`, `period_debit`, `period_credit`, `closing_debit`, `closing_credit`.

## General Ledger

`code = STD_GENERAL_LEDGER`, `category = financial`. Parameters: `account_id` (single or multiple), `period_start`, `period_end`, `include_reversed` (boolean), `dimension_filters` (cost_center/project/department/branch).

Derivation: every `journal_lines` row for the selected account(s) within the period, in posting order, with a running balance column computed as a window function (`SUM(debit - credit) OVER (PARTITION BY account_id ORDER BY posted_at, id)` added to the opening balance).

Default columns: `posted_at`, `journal_entry_number`, `description`, `debit`, `credit`, `running_balance`, `counter_account`, `reference_document`.

## Inventory Valuation

`code = STD_INVENTORY_VALUATION`, `category = inventory`. Parameters: `as_of_date`, `warehouse_id`, `product_category_id`, `valuation_method` (`fifo` | `weighted_average`, read from `products.valuation_method` per item, not overridable at report time since it must match the posted valuation).

Derivation: reads `inventory_valuations` (the authoritative, event-sourced valuation ledger maintained by the Inventory module as stock moves in and out at cost) joined to `inventory_items` for on-hand quantity, producing quantity-on-hand × unit-cost = extended value per product per warehouse, reconciled to the Inventory control account balance in the GL (a mismatch here is flagged identically to the Trial Balance check).

Default columns: `product_code`, `product_name`, `warehouse_name`, `quantity_on_hand`, `unit_cost`, `extended_value`, `gl_control_account_balance`, `variance`.

## Customer Aging

`code = STD_CUSTOMER_AGING`, `category = sales`. Parameters: `as_of_date`, `customer_id` (optional single-customer drill), `bucket_scheme` (default `0-30 / 31-60 / 61-90 / 91-120 / 120+`), `currency`.

Derivation: for every open (non-fully-paid) `invoices` row, computes `days_overdue = as_of_date - due_date`, buckets the outstanding balance (`invoice_total - allocated_receipts` via `receipt_allocations`) into the configured aging bands, grouped by `customer_id`, reconciled to the Trade Receivables control account balance.

Default columns: `customer_name`, `current`, `31_60`, `61_90`, `91_120`, `over_120`, `total_outstanding`, `credit_limit`, `credit_limit_utilization_pct`.

## Vendor Aging

`code = STD_VENDOR_AGING`, `category = purchasing`. Structurally identical to Customer Aging, mirrored against `bills` and `vendor_payments` instead of `invoices` and `receipts`, reconciled to the Trade Payables control account.

Default columns: `vendor_name`, `current`, `31_60`, `61_90`, `91_120`, `over_120`, `total_outstanding`, `payment_terms`, `next_due_date`.

## Sales Analysis

`code = STD_SALES_ANALYSIS`, `category = sales`. Parameters: `period_start`, `period_end`, `group_by` (any combination of `product`, `product_category`, `customer`, `sales_rep`, `region`, `branch`), `comparison`.

Derivation: aggregates `invoice_items` (net of `credit_notes` and `discounts`) joined to `products`, `customers`, and the assigned rep, computing Gross Revenue, Discounts, Net Revenue, Cost of Goods Sold (from `products.cost_price` or FIFO/weighted-average valuation at time of sale), Gross Margin, and Gross Margin %.

Default columns: `dimension_value` (per selected group-by), `gross_revenue`, `discounts`, `net_revenue`, `cogs`, `gross_margin`, `gross_margin_pct`, `unit_count`, `comparison_variance_pct`.

## Purchase Analysis

`code = STD_PURCHASE_ANALYSIS`, `category = purchasing`. Mirrors Sales Analysis against `bill_items` joined to `vendors`, `products`, and `purchase_orders`, computing Total Spend, PO vs. Bill variance, average unit cost trend, and vendor concentration (% of total spend per vendor, flagged above a configurable concentration-risk threshold, default 30%).

Default columns: `dimension_value`, `total_spend`, `po_count`, `avg_unit_cost`, `price_variance_pct`, `on_time_delivery_pct`, `concentration_pct`.

## Payroll Summary

`code = STD_PAYROLL_SUMMARY`, `category = payroll`, PII-gated (see Permissions). Parameters: `payroll_run_id` or `period_start`/`period_end`, `department_id`, `include_employee_detail` (boolean; false returns department-level aggregates only, for roles without `payroll.employee.read`).

Derivation: sums `payroll_items` per employee (or aggregated per department if detail is suppressed) across `salary_components` categories (basic, allowances, overtime, deductions, statutory contributions, net pay), reconciled to the total payroll journal entry posted to Accounting for the run.

Default columns (detail mode): `employee_name`, `department`, `basic_salary`, `allowances`, `overtime`, `gross_pay`, `deductions`, `statutory_contributions`, `net_pay`. Default columns (aggregate mode): `department`, `headcount`, `total_gross`, `total_net`, `total_statutory`.

## Tax Reports

`code = STD_TAX_SUMMARY`, `category = tax`. Parameters: `tax_return_period_id`, `tax_code_id` (optional filter), `jurisdiction`.

Derivation: sums `tax_transactions` grouped by `tax_codes` into Output Tax (on sales) and Input Tax (on purchases), computing Net Tax Payable/Receivable, reconciled against the corresponding `tax_returns` row once filed, with a variance column highlighting any difference between the live ledger-derived figure and the filed return (which should be zero for a closed period and is a compliance flag if not).

Default columns: `tax_code`, `description`, `taxable_base`, `output_tax`, `input_tax`, `net_tax`, `filed_amount`, `variance`.

## Cash Position

`code = STD_CASH_POSITION`, `category = banking`. Parameters: `as_of_datetime` (supports intraday, not just end-of-day), `currency`, `include_pending_transfers` (boolean).

Derivation: sums `bank_accounts.current_balance` (itself derived from posted `bank_transactions`) across all accounts, converts each to base currency at the `as_of_datetime` exchange rate, and optionally nets pending `transfers` in transit. This is the one Standard Report most likely to be rendered as a live-refresh dashboard widget rather than a generated document.

Default columns: `bank_account_name`, `currency_code`, `balance_local`, `exchange_rate`, `balance_base_currency`, `pending_transfers_in`, `pending_transfers_out`.

## Bank Reconciliation

`code = STD_BANK_RECONCILIATION`, `category = banking`. Parameters: `bank_account_id`, `statement_period_end`, `reconciliation_id` (if reviewing a completed reconciliation).

Derivation: reads a `bank_reconciliations` row and its linked `bank_statement_lines` and `bank_transactions`, presenting matched items, unmatched book items (in QAYD but not yet on the bank statement — outstanding checks/pending transfers), and unmatched bank items (on the statement but not yet booked — bank fees, interest), with the reconciling total: `book_balance + outstanding_book_items − outstanding_bank_items = statement_balance`.

Default columns: `item_type` (`matched`|`outstanding_book`|`unmatched_bank`), `date`, `description`, `amount`, `match_reference`.

## Profitability

`code = STD_PROFITABILITY`, `category = financial`. Parameters: `period_start`, `period_end`, `dimension` (`product` | `customer` | `branch` | `project` | `cost_center`), `allocation_method` for shared overhead (`revenue_share` | `headcount_share` | `direct_only`).

Derivation: for the selected dimension, computes Net Revenue, direct Cost of Sales, directly attributable Operating Expenses (from `journal_lines` tagged with the matching `project_id`/`cost_center_id`/`branch_id`), an allocated share of shared/overhead costs per the selected allocation method, and resulting Net Contribution and Net Contribution %. This is the report most frequently rebuilt as a Custom Report with company-specific allocation rules, and the Standard version intentionally ships with the three most common allocation methods pre-built.

Default columns: `dimension_value`, `net_revenue`, `direct_cogs`, `direct_opex`, `allocated_overhead`, `net_contribution`, `net_contribution_pct`.

## KPIs

`code = STD_KPI_SCORECARD`, `category = executive`. Parameters: `period`, `kpi_set` (`finance` | `sales` | `operations` | `all`), `comparison`.

Derivation: a curated scorecard of the platform's headline KPIs, each independently derived from its owning domain, presented with target, actual, variance, and a red/amber/green status computed against company-configured thresholds (`kpi_targets` table, referenced from `report_definitions.config`). Default KPI set:

| KPI | Formula | Domain |
|---|---|---|
| Revenue Growth % | (Current period revenue − prior period revenue) / prior period revenue | Sales |
| Gross Margin % | Gross Profit / Net Revenue | Financial |
| Net Profit Margin % | Net Profit / Net Revenue | Financial |
| Current Ratio | Current Assets / Current Liabilities | Financial |
| Quick Ratio | (Current Assets − Inventory) / Current Liabilities | Financial |
| DSO (Days Sales Outstanding) | (Accounts Receivable / Net Credit Sales) × Days in Period | Sales/Financial |
| DPO (Days Payable Outstanding) | (Accounts Payable / COGS) × Days in Period | Purchasing/Financial |
| Inventory Turnover | COGS / Average Inventory Value | Inventory |
| Employee Cost Ratio | Total Payroll Cost / Net Revenue | Payroll |
| On-Time Delivery Rate | Deliveries on/before promised date / Total deliveries | Operations |

Default columns: `kpi_name`, `target`, `actual`, `variance`, `status` (`green`|`amber`|`red`), `trend_sparkline_data`.

# Analytics

Analytics is the layer of computed, comparative, and predictive views that sit above the raw Standard Reports. Every analytic below is itself implemented as a `report_definitions` row (`category` matching its subject-matter domain, with an `analytics_type` tag) so that it inherits the same permission, scheduling, export, and AI-narration pipeline as any other report — analytics are not a separate product surface.

## Trend Analysis

Renders any numeric report metric (revenue, expenses, cash balance, inventory value, headcount) as a time series across a configurable number of trailing periods (default 12 months, selectable weekly/monthly/quarterly/yearly granularity). Computed via a windowed aggregation over `journal_lines`/domain tables grouped by `date_trunc(granularity, posted_at)`. Includes a linear and a 3-period moving-average trendline computed server-side (never client-side, so the same trendline appears identically in the dashboard widget, the exported PDF, and the API response). The AI Reporting Agent annotates trend breaks (a period whose value deviates from the moving average by more than 1.5 standard deviations) with a plain-language callout.

## Variance Analysis

Compares any two comparable periods, or a period against its budget, at the line-item level, for any Standard Report or Custom Report that has a numeric measure. Output columns: `line_item`, `period_a_value`, `period_b_value`, `absolute_variance`, `pct_variance`, `favorable_unfavorable` (derived from the account's normal balance — an expense coming in under budget is `favorable`, revenue coming in under budget is `unfavorable`). Variance Analysis against `budgets`/`budget_lines` is the mechanism behind the Finance Dashboard's Budget vs. Actual widget and is available for any account, cost center, project, or department.

## Forecasting

Produces forward-looking projections for revenue, expenses, cash position, and inventory demand. Two modes:
- **Statistical mode** (default, always available, zero AI dependency): a seasonal-naive or linear-regression projection computed directly in PostgreSQL/Laravel from the trailing 12–36 months of the relevant series, suitable for a simple "next 3 months at current trend" view.
- **AI mode** (Forecast Agent): a model-driven projection that additionally incorporates seasonality detection, known one-off events (recorded as `forecast_adjustments`, e.g., a known large contract ending), and cross-series signals (e.g., pipeline value in `sales_quotations` as a leading indicator for next-quarter revenue). AI-mode forecasts always carry a `confidence_interval` (p10/p50/p90) rather than a single point estimate, and are always labeled distinctly from the statistical baseline so a user can see both side by side.

Forecasts never auto-post anything (no journal entry, no budget line); they are read-only projections a Finance Manager or CFO can accept into a budget draft.

## Comparisons

The generic engine behind every "vs. prior period," "vs. prior year," "vs. budget," and "vs. another branch/entity" view in the module. A comparison is a `report_runs` parameter (`comparison_mode` + `comparison_reference`) rather than a separate report type: any Standard or Custom Report can be run with a comparison attached, returning paired columns (`current_value`, `comparison_value`, `variance_absolute`, `variance_pct`) for every measure. Cross-entity comparison (Branch A vs. Branch B, or Subsidiary A vs. Subsidiary B) uses the same mechanism with `comparison_reference = { "branch_id": ... }` instead of a date range.

## Ratios

A standing library of financial ratios computed from Balance Sheet and Income Statement derivations, available as their own report (`STD_RATIO_ANALYSIS`, category `financial`) and as inputs to the KPI Scorecard and Executive Dashboard.

| Ratio | Formula | Category |
|---|---|---|
| Current Ratio | Current Assets / Current Liabilities | Liquidity |
| Quick Ratio | (Current Assets − Inventory) / Current Liabilities | Liquidity |
| Cash Ratio | Cash & Equivalents / Current Liabilities | Liquidity |
| Debt-to-Equity | Total Liabilities / Total Equity | Leverage |
| Interest Coverage | EBIT / Interest Expense | Leverage |
| Gross Margin % | Gross Profit / Net Revenue | Profitability |
| Operating Margin % | Operating Profit / Net Revenue | Profitability |
| Net Margin % | Net Profit / Net Revenue | Profitability |
| Return on Assets (ROA) | Net Profit / Average Total Assets | Profitability |
| Return on Equity (ROE) | Net Profit / Average Total Equity | Profitability |
| Asset Turnover | Net Revenue / Average Total Assets | Efficiency |
| Inventory Turnover | COGS / Average Inventory | Efficiency |
| Receivables Turnover | Net Credit Sales / Average AR | Efficiency |
| Payables Turnover | COGS / Average AP | Efficiency |

Every ratio computation is stamped with the exact Balance Sheet/Income Statement `report_runs.id` it was derived from, so a ratio can always be traced back to the audited statement snapshot that produced it.

## Benchmarking

Compares a company's ratios and KPIs against configurable reference sets: (a) the company's own prior periods (trivially available from Comparisons above), (b) a company-entered manual target/budget, and (c) — where the company opts in — an anonymized, aggregated industry benchmark computed across QAYD's Gulf customer base within the same `industry_code` and revenue band, with strict k-anonymity (a benchmark cohort must have at least 10 contributing companies before any aggregate is surfaced, and no company ever sees another individual company's data). Benchmarking against the industry cohort is opt-in per company (`companies.benchmarking_opt_in`) and, when enabled, contributes only anonymized, aggregated ratios back into the cohort — never raw financial data.

## ABC Analysis

Classifies inventory items (or customers, or products) into A/B/C tiers by cumulative contribution to a chosen value measure (default: annual consumption value = quantity sold × unit cost). Standard thresholds: A = top items contributing 80% of cumulative value, B = next 15%, C = remaining 5%, each threshold configurable per company. Used to prioritize cycle-count frequency (A items counted monthly, B quarterly, C annually — feeding `stock_counts` scheduling), reorder policy strictness, and vendor negotiation focus. Output columns: `item_code`, `item_name`, `annual_value`, `cumulative_pct`, `abc_class`.

## XYZ Analysis

Classifies the same population by demand *variability* (coefficient of variation of period-over-period consumption) rather than value: X = low variability/highly predictable (CV < 0.5), Y = moderate variability (0.5–1.0), Z = high/erratic variability (CV > 1.0). Combined with ABC into a 9-cell ABC-XYZ matrix (e.g., "AX" = high-value, predictable-demand items — ideal candidates for tight safety-stock policy and vendor-managed inventory; "CZ" = low-value, erratic-demand items — candidates for make-to-order or discontinuation review). The AI Inventory Manager agent uses the ABC-XYZ matrix as a direct input to its reorder-point and safety-stock recommendations.

## Executive Insights

The AI-composed synthesis layer that reads across Trend, Variance, Ratio, Benchmarking, and ABC/XYZ analytics for the current dashboard or report context and produces a ranked list of the 3–7 most decision-relevant observations, each tagged with its supporting analytic, its confidence score, and a suggested next action (e.g., "Gross margin fell 3.2 points quarter-over-quarter, driven primarily by a 14% increase in COGS for Product Category 'Electronics' — confidence 0.86 — recommend reviewing the March vendor price increase from Vendor #4021"). Executive Insights never fabricates a number that isn't already present in an underlying report; its role is ranking, correlation, and plain-language explanation of numbers that already exist and are already independently auditable.

# Report Builder

The Report Builder is the no-code composition surface behind every Custom Report. It operates over a governed **semantic layer** — a curated set of exposed tables/views (never raw, ungoverned SQL access) each described by a `report_data_sources` catalogue entry: display name, underlying table/materialized view, available fields with types and formats, available joins to other exposed sources, and the RBAC permission required to include that source in a report. This keeps the Report Builder exactly as safe as the rest of the platform: a Sales Employee building a Custom Report simply never sees `payroll_items` in the field picker, because their role lacks `reports.payroll.read`.

## Drag & Drop

The frontend (Next.js/shadcn) presents three panes: **Fields** (draggable, grouped by data source), **Canvas** (the report layout — a table by default, switchable to any chart type), and **Configuration** (filters, grouping, sorting, calculated fields for whatever is currently selected on the canvas). Dragging a field onto the canvas appends it as a column (table view) or maps it to an axis/series (chart view). Reordering columns is drag-and-drop on the canvas itself. The builder auto-saves a draft `report_definitions` row (`status = draft`) every 3 seconds of inactivity so work is never lost, and the user explicitly "Publishes" to move it to `status = active` and make it visible to others per its sharing configuration.

## Custom Fields

Beyond the semantic layer's native fields, a report can define **custom fields** at the `report_definitions.config.custom_fields` level: renamed/relabeled columns, unit conversions (e.g., displaying a `NUMERIC(18,4)` quantity field rounded to 2 decimals with a unit suffix), and conditional formatting rules (e.g., color a cell red if `days_overdue > 90`). Custom fields are presentation-layer only — they never write back to the source table.

## Filters

Every field in the semantic layer can be used as a filter with an operator appropriate to its type: text (`equals`, `contains`, `starts_with`), numeric/date (`equals`, `greater_than`, `less_than`, `between`), enum/status (`in`, `not_in`), boolean (`is_true`/`is_false`). Filters combine with `AND`/`OR` grouping (nested filter groups, matching the shape of `report_definitions.config.filters` shown in Database Design). Date filters additionally support relative expressions (`last_7_days`, `this_month`, `last_fiscal_quarter`) that re-resolve to concrete dates every time the report runs, which is what makes a saved "Sales this month" report continue to mean "this month" a year from now rather than being frozen to the month it was created.

## Grouping

Any dimension field can be added to the grouping list, in order, producing nested subtotal rows (e.g., group by `region` then `sales_rep`, producing a region subtotal, a rep subtotal within each region, and a grand total). Grouping interacts with calculated fields that use aggregate functions (`SUM`, `AVG`, `COUNT`, `MIN`, `MAX`, `COUNT_DISTINCT`) — a calculated field like `gross_margin_pct` is computed per group level, not just at the leaf row.

## Sorting

Multi-column sort with explicit precedence (sort by `total_outstanding` descending, then by `customer_name` ascending as a tiebreaker), applied after grouping/aggregation, with per-group or global sort scope configurable (sort customers within each region vs. sort all customers globally regardless of region).

## Calculated Fields

A formula language restricted to a safe, whitelisted function set (arithmetic operators; `SUM`/`AVG`/`COUNT`/`MIN`/`MAX`/`COUNT_DISTINCT`; `IF(condition, then, else)`; date functions `DATE_DIFF`, `DATE_TRUNC`; string functions `CONCAT`, `UPPER`, `LOWER`) evaluated server-side and compiled into the generated SQL — never evaluated as arbitrary client-supplied SQL or code. Example calculated field definition:

```json
{
  "id": "gross_margin_pct",
  "label": "Gross Margin %",
  "formula": "IF(SUM(net_revenue) = 0, 0, (SUM(net_revenue) - SUM(cogs)) / SUM(net_revenue) * 100)",
  "format": "percentage_2dp"
}
```

## Templates

A company can save any Custom Report as a **template** (`report_definitions.is_template = true`), stripped of company-specific filter values but retaining structure (fields, grouping, calculated fields), so it can be re-instantiated with new parameters — either by the same company for a recurring need with different filters, or, for templates marked `is_shareable_across_companies = true` by QAYD itself, offered in a template gallery to all companies (e.g., "Vendor Concentration Risk Report," "Quarterly Board Pack"). Company-authored templates are never visible to other companies by default; cross-company sharing requires an explicit QAYD platform-level curation step, not a company self-service action.

## Saved Reports

Any published report definition a user has permission to run can be **saved** to their personal or a shared "My Reports" list (`report_favorites`), pinning it for one-click re-run with its last-used parameters pre-filled. Saved is distinct from Scheduled: a saved report still requires a manual click to run; a scheduled report runs itself.

## Scheduling

Any report can be attached to one or more `report_schedules` rows defining a cron-style recurrence (`0 8 * * MON` = every Monday at 8 AM), a fixed set of parameters to run with, an output format, and a delivery method (email to a list of recipients/roles, or drop into a shared folder accessible via the API). Scheduled runs are executed by a Laravel queue worker that creates a `report_runs` row exactly as if a user had clicked "Run," ensuring scheduled and manual runs are indistinguishable in the audit trail except for `report_runs.triggered_by = 'schedule'` vs. `'user'`.

## Sharing

A report definition or a specific completed `report_runs` snapshot can be shared via: (a) an internal share granting another user or role `view`/`edit` rights on the report definition itself (`report_shares`), or (b) an external share generating a signed, time-limited, optionally password-protected link to a specific rendered snapshot (used for External Auditor access and board packs), which never grants access to the live report definition or the ability to re-run it with different parameters — only to view/download the exact frozen output. Every share creation and every access of a shared link is written to `audit_logs`.

# Export

Every report — Standard, Custom, or a dashboard widget's underlying data — can be exported in the formats below. Export is always a rendering of an already-generated `report_runs` payload; exporting never re-triggers a fresh query with different filters, guaranteeing that the PDF a CFO downloads and the Excel file the AI attaches to an email describe the exact same underlying data.

## PDF

Server-rendered via a headless Chromium print pipeline against an HTML template that mirrors the on-screen report layout, with print-specific CSS (page breaks between groups where configured, repeated header rows on each page, a footer carrying `report name | generated_at | generated_by | page X of Y | company name`). Financial Statements and any report flagged `requires_letterhead = true` render with the company's letterhead (logo, registration number, address) pulled from `companies.letterhead_config`. PDFs are the default format for External Auditor shares and for scheduled board-pack delivery.

## Excel

Rendered as a genuine `.xlsx` workbook (via PhpSpreadsheet on the Laravel side), not a renamed CSV: preserves number formatting (currency, percentage, date), applies the report's conditional formatting rules as native Excel conditional formats, freezes header rows, and — for reports with grouping — uses native Excel outline/grouping (collapsible row groups) rather than flattening the hierarchy. Multi-sheet workbooks are used for reports with a "detail" and "summary" view (e.g., Payroll Summary exports a Summary sheet and, if the exporting user has `payroll.employee.read`, a Detail sheet).

## CSV

A flat, single-sheet export of the report's leaf-level rows (grouping subtotal rows are omitted from CSV since CSV has no native grouping concept; a `group_path` column is added instead so the hierarchy is still reconstructable). UTF-8 with BOM for correct Arabic rendering in Excel on Windows. Used primarily for data interchange into other tools.

## JSON

The canonical machine-readable format, identical in shape to the API's report payload (see API section) — column metadata, row data, subtotals, and the `report_runs` metadata block (parameters, `as_of`, `generated_at`, AI insights if requested). This is the format used internally when a report's output feeds another system component (e.g., the Executive Dashboard consuming a KPI Scorecard report).

## API

Every export is additionally available headlessly via `GET /api/v1/reports/runs/{id}/export?format={pdf|xlsx|csv|json}`, returning either the file directly (with appropriate `Content-Type`/`Content-Disposition`) for smaller exports or, for exports whose rendering takes over ~5 seconds (typically large PDF/Excel exports of heavy reports), a `202 Accepted` with a polling URL, following the same async pattern as report generation itself.

## Email

Scheduled reports and the "Email this report" action attach the rendered export (PDF by default, configurable per schedule) to a templated email sent via the platform's transactional email provider, with the report's AI Insights summary (if enabled for that schedule) inlined as the email body text above the attachment, so a recipient gets the narrative takeaway even before opening the file.

## Print

The web UI's "Print" action uses a dedicated print stylesheet (the same one PDF export uses, so print-preview and PDF export are visually identical) rather than relying on the browser's default print rendering of the interactive dashboard chrome, ensuring filters/navigation/AI-insights-editing-controls are excluded and only the report content and its official header/footer are printed.

# AI Responsibilities

The AI Layer never queries the database directly for reporting purposes. Every AI agent described below calls the same versioned Laravel API a human-driven frontend calls (`GET /api/v1/reports/...`), authenticated as the requesting user's delegated context (never as a privileged service account that bypasses permissions), so an AI agent literally cannot read, narrate, or act on a report the requesting user is not themselves permitted to see. Every AI output is persisted with `confidence` (0.00–1.00), `reasoning` (a structured explanation, not just a final sentence), and `source_refs` (an array of the specific report row IDs, account codes, `journal_line_id`s, or document IDs the claim is grounded in). The primary agent for this module is the **Reporting Agent**; it collaborates with the **CFO Agent**, **Forecast Agent**, **Fraud Detection** agent, and **Compliance Agent**, each contributing its specialty analysis into the same AI Insights surface.

| Responsibility | Primary agent | Autonomy | Confidence handling |
|---|---|---|---|
| Executive Summary | Reporting Agent | Suggest-only | Always shown with score; below 0.6 is visually flagged "low confidence — verify" |
| Narrative Reports | Reporting Agent | Suggest-only | Per-sentence traceability to `source_refs`; no narrative ships without at least one source ref per factual claim |
| Forecasts | Forecast Agent | Suggest-only | p10/p50/p90 confidence interval, never a bare point estimate in AI mode |
| Risk Analysis | CFO Agent + Compliance Agent | Suggest-only | Each flagged risk carries severity (`low`/`medium`/`high`/`critical`) and confidence independently |
| Anomaly Detection | Fraud Detection agent | Auto-flag, requires-approval to dismiss | Statistical threshold (z-score/IQR) plus a model confidence; false-positive dismissal requires human `reports.audit` role sign-off, logged |
| Trend Detection | Reporting Agent | Auto-annotate | Deterministic statistical test (moving-average deviation), confidence derived from sample size and variance |
| Recommendations | CFO Agent / Reporting Agent | Suggest-only, requires-approval to act | Every recommendation names the exact action a human would take and the report data supporting it |
| Question Answering | Reporting Agent | Suggest-only | Answers only from data the asking user is permitted to see; unanswerable/unauthorized questions return an explicit refusal, never a guess |
| Natural Language Reports | Reporting Agent | Suggest-only, human "Publish" required for external distribution | Full underlying report attached/linked alongside the prose so every number is checkable |

## Executive Summary

For any dashboard or report, the Reporting Agent can generate a 3–6 sentence plain-language summary of "what changed and why it matters," always anchored to the specific numbers on screen. Input: the current report's row data plus its Variance/Trend/Ratio analytics for the same period. Output: a summary string plus a `key_figures` array cross-referencing the exact cells it discusses. Autonomy: suggest-only — the summary renders in the AI Insights panel automatically on report generation (no explicit user request needed, since this is low-risk read-only narration) but is never merged into the exportable "official" report body unless a human clicks "Include AI summary" when exporting/sharing externally.

## Narrative Reports

A longer-form variant of Executive Summary aimed at board-pack and monthly management-report contexts: a multi-paragraph narrative covering performance vs. plan, notable variances by department/product/region, cash and liquidity commentary, and forward outlook, structured to mirror how a CFO would write a management discussion section. Every paragraph's factual claims are individually traceable to `source_refs`. Generation is triggered explicitly (not automatic, given the higher compute cost and higher stakes of a longer narrative) via `POST /api/v1/reports/{id}/ai-narrative`, and the resulting narrative is stored as a `report_runs.ai_metadata.narrative` block attached to that specific run, versioned alongside it.

## Forecasts

The Forecast Agent produces the AI-mode forecasts described in Analytics → Forecasting: revenue, expense, cash, and inventory-demand projections incorporating seasonality, known one-off adjustments, and cross-series leading indicators. Autonomy is strictly suggest-only — a forecast never auto-populates a budget or triggers a purchasing/reorder action; it is surfaced for a human (Finance Manager, CFO, Inventory Manager) to review and, if accepted, manually convert into a budget draft or reorder suggestion (which itself then follows its own approval workflow in the owning module). Forecast accuracy is continuously tracked: every forecast, once its target period closes, is automatically compared to the realized actual and the delta is logged to a `forecast_accuracy_log`, visible on the AI Dashboard's "Forecast accuracy tracker" widget — this is how the platform keeps the Forecast Agent honest and lets users calibrate how much to trust it over time.

## Risk Analysis

The CFO Agent (financial risk: liquidity, concentration, covenant proximity) and the Compliance Agent (regulatory risk: filing deadlines, threshold breaches) jointly scan report outputs for risk conditions: customer/vendor concentration above threshold, current ratio trending toward covenant breach, tax filing deadline within N days with an unfiled return, unusually large unbudgeted expense categories. Each identified risk is a structured record (`risk_type`, `severity`, `description`, `confidence`, `source_refs`, `suggested_mitigation`) surfaced on the Executive and AI Dashboards. Autonomy: suggest-only; a critical-severity risk additionally triggers a Notification (see below) to the relevant role, but never an automatic corrective transaction.

## Anomaly Detection

The Fraud Detection agent runs a continuous statistical scan (z-score and interquartile-range outlier detection, plus sequence-pattern checks such as duplicate invoice numbers, round-number-heavy transaction clusters, or postings just under an approval threshold) across newly posted journal lines, invoices, bills, and payroll items, surfacing anomalies to the AI Dashboard and, for high-severity anomalies, to the relevant Finance/Audit role via Notification. Autonomy: **auto-flag** — an anomaly is raised automatically without needing a human to request the scan — but dismissing a flagged anomaly as a false positive requires a human with an `audit`-category permission to record a reason, which is itself audit-logged; the agent never silently retracts its own flag.

## Trend Detection

Embedded directly in Trend Analysis (see Analytics): the Reporting Agent automatically annotates any trend chart with callouts where a period deviates materially (>1.5σ) from its moving average, or where a sustained directional trend (3+ consecutive periods) crosses a materiality threshold. Autonomy: auto-annotate on render — this is treated as a deterministic statistical fact about the data rather than a judgment call, so it does not require an explicit "generate insights" click, unlike the heavier Narrative Reports.

## Recommendations

The CFO Agent and Reporting Agent jointly produce action-oriented recommendations tied to a specific report finding — e.g., "Vendor #4021 now represents 34% of total purchase spend (up from 22% a year ago); consider negotiating volume pricing or qualifying a second supplier to reduce concentration risk" — always phrased as a suggestion with the supporting figures attached, never phrased as an action already taken. Autonomy: suggest-only, and where a recommendation implies a specific transactional action available elsewhere in the platform (e.g., "create a purchase order with Vendor X"), the recommendation includes a deep link into that module's create flow rather than the Reporting module executing it directly — Reporting has no write access to Purchasing, Sales, or any other module's operational tables.

## Question Answering

A natural-language Q&A interface (surfaced in the AI Dashboard and as a chat affordance on any report) lets a user ask questions like "why did gross margin drop in March?" or "which customers haven't paid in over 90 days?" The Reporting Agent translates the question into a bounded query against the semantic layer described in Report Builder (never arbitrary raw SQL), executes it through the same permissioned API a Custom Report would use, and answers in prose with the underlying rows attached. If the question requires data the requesting user's role cannot access (e.g., a Sales Employee asking about payroll cost), the agent returns an explicit "I can't answer that — you don't have permission to view Payroll reports" rather than silently omitting the restricted parts or guessing. If the question cannot be mapped to an available data source at all, the agent says so rather than fabricating a plausible-sounding number.

## Natural Language Reports

The inverse direction of Question Answering: a user can ask for an entirely new ad hoc report in natural language ("show me all overdue invoices for customers in the Farwaniya region, grouped by sales rep"), and the Reporting Agent drafts a `report_definitions` row (fields, filters, grouping) matching the request, which the user can review, adjust in the Report Builder exactly as if they had built it manually, and save. Autonomy: suggest-only — the AI drafts, a human reviews and saves; the draft never auto-publishes or auto-schedules itself. This is the primary on-ramp intended to make the Report Builder feel effortless for non-technical roles.

# Database Design

All tables below carry the platform's standard columns (`id`, `company_id`, `branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) even where not re-listed in the `CREATE TABLE` statement, per the shared platform convention. Money columns are `NUMERIC(19,4)`; all JSON configuration is `JSONB` for indexability.

## Core enums

```sql
CREATE TYPE report_category AS ENUM (
  'financial', 'operational', 'inventory', 'sales', 'purchasing',
  'payroll', 'tax', 'banking', 'compliance', 'audit', 'executive', 'custom', 'dashboard'
);

CREATE TYPE report_output_format AS ENUM ('table', 'chart', 'widget', 'document');

CREATE TYPE report_run_status AS ENUM ('queued', 'running', 'completed', 'failed', 'cancelled');

CREATE TYPE report_run_trigger AS ENUM ('user', 'schedule', 'api', 'ai_agent');

CREATE TYPE report_schedule_frequency AS ENUM ('once', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'cron');

CREATE TYPE report_share_scope AS ENUM ('internal_user', 'internal_role', 'external_link');

CREATE TYPE export_format AS ENUM ('pdf', 'xlsx', 'csv', 'json', 'html');
```

## report_definitions

The catalogue of every report a company can run — system-seeded Standard Reports, dashboards, and company-authored Custom Reports.

```sql
CREATE TABLE report_definitions (
  id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  branch_id           BIGINT NULL REFERENCES branches(id),
  code                VARCHAR(64) NOT NULL,               -- e.g. 'STD_BALANCE_SHEET', or a generated slug for custom reports
  name_en             VARCHAR(160) NOT NULL,
  name_ar             VARCHAR(160) NULL,
  description         TEXT NULL,
  category            report_category NOT NULL,
  output_format       report_output_format NOT NULL DEFAULT 'table',
  is_system           BOOLEAN NOT NULL DEFAULT FALSE,      -- true for the 16 seeded Standard Reports
  is_template         BOOLEAN NOT NULL DEFAULT FALSE,
  is_shareable_across_companies BOOLEAN NOT NULL DEFAULT FALSE, -- platform-curated templates only
  is_locked           BOOLEAN NOT NULL DEFAULT FALSE,      -- core columns of a system report cannot be removed
  status              VARCHAR(16) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','archived')),
  version             INT NOT NULL DEFAULT 1,
  parent_definition_id BIGINT NULL REFERENCES report_definitions(id), -- prior version, for version history
  data_sources        JSONB NOT NULL DEFAULT '[]',         -- which report_data_sources this report reads
  config              JSONB NOT NULL DEFAULT '{}',         -- fields, filters, grouping, sorting, calculated_fields, layout
  default_params      JSONB NOT NULL DEFAULT '{}',
  owner_id            BIGINT NULL REFERENCES users(id),
  required_permission VARCHAR(96) NOT NULL,                -- e.g. 'reports.financial.read'
  created_by          BIGINT NULL REFERENCES users(id),
  updated_by          BIGINT NULL REFERENCES users(id),
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at          TIMESTAMPTZ NULL,
  UNIQUE (company_id, code, version)
);

CREATE INDEX idx_report_definitions_company_category ON report_definitions (company_id, category) WHERE deleted_at IS NULL;
CREATE INDEX idx_report_definitions_owner ON report_definitions (owner_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_report_definitions_config_gin ON report_definitions USING GIN (config);
CREATE INDEX idx_report_definitions_is_system ON report_definitions (company_id, is_system) WHERE deleted_at IS NULL;
```

## report_schedules

```sql
CREATE TABLE report_schedules (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  branch_id         BIGINT NULL REFERENCES branches(id),
  report_definition_id BIGINT NOT NULL REFERENCES report_definitions(id),
  name              VARCHAR(160) NOT NULL,
  frequency         report_schedule_frequency NOT NULL,
  cron_expression   VARCHAR(64) NULL,                      -- required when frequency = 'cron'
  run_at_time       TIME NULL,                              -- local time of day for daily/weekly/monthly
  run_on_day_of_week SMALLINT NULL CHECK (run_on_day_of_week BETWEEN 0 AND 6),
  run_on_day_of_month SMALLINT NULL CHECK (run_on_day_of_month BETWEEN 1 AND 31),
  timezone          VARCHAR(64) NOT NULL DEFAULT 'Asia/Kuwait',
  params            JSONB NOT NULL DEFAULT '{}',            -- parameters to run the report with
  export_format     export_format NOT NULL DEFAULT 'pdf',
  include_ai_summary BOOLEAN NOT NULL DEFAULT FALSE,
  delivery_method   VARCHAR(16) NOT NULL DEFAULT 'email' CHECK (delivery_method IN ('email','shared_folder','webhook')),
  recipients        JSONB NOT NULL DEFAULT '[]',            -- [{type:'user'|'role'|'email', value:...}]
  webhook_url       VARCHAR(500) NULL,
  is_active         BOOLEAN NOT NULL DEFAULT TRUE,
  last_run_at       TIMESTAMPTZ NULL,
  next_run_at       TIMESTAMPTZ NULL,
  created_by        BIGINT NULL REFERENCES users(id),
  updated_by        BIGINT NULL REFERENCES users(id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at        TIMESTAMPTZ NULL
);

CREATE INDEX idx_report_schedules_next_run ON report_schedules (next_run_at) WHERE is_active = TRUE AND deleted_at IS NULL;
CREATE INDEX idx_report_schedules_definition ON report_schedules (report_definition_id) WHERE deleted_at IS NULL;
```

## report_runs

The execution ledger. Every generation of a report — manual, scheduled, API-triggered, or AI-triggered — creates exactly one row here, whether it completes synchronously or is processed asynchronously by a queue worker.

```sql
CREATE TABLE report_runs (
  id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id          BIGINT NOT NULL REFERENCES companies(id),
  branch_id           BIGINT NULL REFERENCES branches(id),
  report_definition_id BIGINT NOT NULL REFERENCES report_definitions(id),
  report_schedule_id  BIGINT NULL REFERENCES report_schedules(id),
  status              report_run_status NOT NULL DEFAULT 'queued',
  triggered_by        report_run_trigger NOT NULL DEFAULT 'user',
  triggered_by_user_id BIGINT NULL REFERENCES users(id),
  triggered_by_agent  VARCHAR(64) NULL,                    -- e.g. 'reporting_agent', 'forecast_agent'
  params              JSONB NOT NULL DEFAULT '{}',          -- fully resolved parameters (relative dates resolved to concrete dates)
  as_of               TIMESTAMPTZ NULL,                     -- posting cutoff the report was generated against
  row_count           INT NULL,
  queued_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  started_at          TIMESTAMPTZ NULL,
  completed_at        TIMESTAMPTZ NULL,
  duration_ms         INT NULL,
  error_code          VARCHAR(64) NULL,
  error_message       TEXT NULL,
  ai_metadata         JSONB NULL,                           -- { narrative, confidence, source_refs, anomalies, forecast }
  snapshot_id         BIGINT NULL REFERENCES report_snapshots(id),
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_report_runs_definition_status ON report_runs (report_definition_id, status);
CREATE INDEX idx_report_runs_company_created ON report_runs (company_id, created_at DESC);
CREATE INDEX idx_report_runs_schedule ON report_runs (report_schedule_id) WHERE report_schedule_id IS NOT NULL;
CREATE INDEX idx_report_runs_status_queued ON report_runs (status, queued_at) WHERE status IN ('queued','running');
```

## report_snapshots

The immutable, versioned storage of a completed run's rendered payload — the artifact that makes a report reproducible byte-for-byte months later regardless of subsequent schema evolution in the source tables' presentation layer.

```sql
CREATE TABLE report_snapshots (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  report_run_id     BIGINT NOT NULL REFERENCES report_runs(id),
  report_definition_version INT NOT NULL,                  -- report_definitions.version at time of run
  payload           JSONB NOT NULL,                        -- { columns:[...], rows:[...], subtotals:[...], footer:{...} }
  payload_hash      VARCHAR(64) NOT NULL,                  -- SHA-256 of the canonicalized payload, for tamper detection
  storage_ref       VARCHAR(500) NULL,                      -- R2 object key for large payloads stored off-row
  file_size_bytes    BIGINT NULL,
  retention_expires_at TIMESTAMPTZ NULL,                    -- NULL = retain indefinitely (default for financial/audit categories)
  legal_hold         BOOLEAN NOT NULL DEFAULT FALSE,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at        TIMESTAMPTZ NULL
);

CREATE INDEX idx_report_snapshots_run ON report_snapshots (report_run_id);
CREATE INDEX idx_report_snapshots_company_created ON report_snapshots (company_id, created_at DESC);
CREATE INDEX idx_report_snapshots_retention ON report_snapshots (retention_expires_at) WHERE retention_expires_at IS NOT NULL AND legal_hold = FALSE;
```

## Supporting tables

```sql
CREATE TABLE report_data_sources (
  id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code             VARCHAR(64) NOT NULL UNIQUE,             -- e.g. 'sales_invoices', 'inventory_stock'
  display_name_en  VARCHAR(160) NOT NULL,
  display_name_ar  VARCHAR(160) NULL,
  base_table_or_view VARCHAR(128) NOT NULL,                 -- e.g. 'invoices', 'mv_sales_by_period'
  category         report_category NOT NULL,
  required_permission VARCHAR(96) NOT NULL,
  fields           JSONB NOT NULL DEFAULT '[]',             -- [{name, type, label_en, label_ar, aggregatable, filterable}]
  joinable_sources JSONB NOT NULL DEFAULT '[]',             -- [{source_code, join_key, join_type}]
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE report_shares (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  report_definition_id BIGINT NULL REFERENCES report_definitions(id),
  report_snapshot_id BIGINT NULL REFERENCES report_snapshots(id),
  scope             report_share_scope NOT NULL,
  shared_with_user_id BIGINT NULL REFERENCES users(id),
  shared_with_role  VARCHAR(64) NULL,
  access_level      VARCHAR(16) NOT NULL DEFAULT 'view' CHECK (access_level IN ('view','edit')),
  share_token       VARCHAR(128) NULL UNIQUE,               -- for external_link scope
  password_hash     VARCHAR(255) NULL,
  expires_at        TIMESTAMPTZ NULL,
  max_views         INT NULL,
  view_count        INT NOT NULL DEFAULT 0,
  created_by        BIGINT NULL REFERENCES users(id),
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  revoked_at        TIMESTAMPTZ NULL
);

CREATE INDEX idx_report_shares_token ON report_shares (share_token) WHERE share_token IS NOT NULL AND revoked_at IS NULL;

CREATE TABLE report_favorites (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  user_id           BIGINT NOT NULL REFERENCES users(id),
  report_definition_id BIGINT NOT NULL REFERENCES report_definitions(id),
  last_used_params  JSONB NOT NULL DEFAULT '{}',
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (user_id, report_definition_id)
);

CREATE TABLE report_comments (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  report_run_id     BIGINT NOT NULL REFERENCES report_runs(id),
  user_id           BIGINT NOT NULL REFERENCES users(id),
  body              TEXT NOT NULL,
  cell_reference    JSONB NULL,                             -- {row_index, column} for a cell-anchored comment
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at        TIMESTAMPTZ NULL
);

CREATE TABLE user_dashboard_layouts (
  id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id        BIGINT NOT NULL REFERENCES companies(id),
  user_id           BIGINT NOT NULL REFERENCES users(id),
  report_definition_id BIGINT NOT NULL REFERENCES report_definitions(id), -- the dashboard
  layout_overrides  JSONB NOT NULL DEFAULT '{}',            -- widget hide/reorder/resize
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (user_id, report_definition_id)
);
```

## Materialized Views

Materialized views back every heavy, frequently-run analytic so that dashboard tiles and Standard Reports over large date ranges never do a live full scan of `journal_lines` on every request. Each materialized view is owned by Reporting & Analytics for refresh scheduling purposes but reads from the owning module's tables — Reporting never becomes the primary source for the figures it caches.

```sql
-- General Ledger monthly aggregation, the base for Trial Balance, Balance Sheet, and Income Statement
CREATE MATERIALIZED VIEW mv_gl_monthly_balances AS
SELECT
  jl.company_id,
  jl.branch_id,
  jl.account_id,
  a.account_type_id,
  date_trunc('month', je.posted_at) AS period_month,
  jl.cost_center_id,
  jl.project_id,
  jl.department_id,
  SUM(jl.debit_amount)  AS total_debit,
  SUM(jl.credit_amount) AS total_credit,
  SUM(jl.debit_amount) - SUM(jl.credit_amount) AS net_movement
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
JOIN accounts a ON a.id = jl.account_id
WHERE jl.deleted_at IS NULL
GROUP BY jl.company_id, jl.branch_id, jl.account_id, a.account_type_id,
         date_trunc('month', je.posted_at), jl.cost_center_id, jl.project_id, jl.department_id;

CREATE UNIQUE INDEX idx_mv_gl_monthly_pk ON mv_gl_monthly_balances
  (company_id, account_id, period_month, COALESCE(branch_id,0), COALESCE(cost_center_id,0), COALESCE(project_id,0), COALESCE(department_id,0));
CREATE INDEX idx_mv_gl_monthly_period ON mv_gl_monthly_balances (company_id, period_month);

-- Sales fact table for Sales Analysis, Customer Aging inputs, and the Sales Dashboard
CREATE MATERIALIZED VIEW mv_sales_daily AS
SELECT
  i.company_id,
  i.branch_id,
  date_trunc('day', i.invoice_date) AS sale_date,
  i.customer_id,
  ii.product_id,
  p.product_category_id,
  i.sales_rep_id,
  SUM(ii.quantity)                        AS units_sold,
  SUM(ii.quantity * ii.unit_price)        AS gross_revenue,
  SUM(ii.discount_amount)                 AS discounts,
  SUM(ii.quantity * ii.unit_price - ii.discount_amount) AS net_revenue,
  SUM(ii.quantity * ii.unit_cost_at_sale)  AS cogs
FROM invoices i
JOIN invoice_items ii ON ii.invoice_id = i.id
JOIN products p ON p.id = ii.product_id
WHERE i.status IN ('posted','paid','partially_paid') AND i.deleted_at IS NULL
GROUP BY i.company_id, i.branch_id, date_trunc('day', i.invoice_date), i.customer_id,
         ii.product_id, p.product_category_id, i.sales_rep_id;

CREATE INDEX idx_mv_sales_daily_date ON mv_sales_daily (company_id, sale_date);
CREATE INDEX idx_mv_sales_daily_customer ON mv_sales_daily (company_id, customer_id);

-- Inventory valuation snapshot for the Inventory Dashboard and ABC/XYZ analysis
CREATE MATERIALIZED VIEW mv_inventory_valuation_current AS
SELECT
  iv.company_id,
  ii.warehouse_id,
  ii.product_id,
  p.product_category_id,
  ii.quantity_on_hand,
  iv.unit_cost,
  ii.quantity_on_hand * iv.unit_cost AS extended_value
FROM inventory_items ii
JOIN products p ON p.id = ii.product_id
JOIN LATERAL (
  SELECT unit_cost FROM inventory_valuations v
  WHERE v.product_id = ii.product_id AND v.warehouse_id = ii.warehouse_id
  ORDER BY v.valued_at DESC LIMIT 1
) iv ON TRUE
WHERE ii.deleted_at IS NULL;

CREATE UNIQUE INDEX idx_mv_inv_val_pk ON mv_inventory_valuation_current (company_id, warehouse_id, product_id);
```

Refresh strategy: `mv_gl_monthly_balances` and `mv_sales_daily` are refreshed **incrementally** every 5 minutes via `REFRESH MATERIALIZED VIEW CONCURRENTLY` restricted to a rolling window (current + prior month re-aggregated, older months left untouched since posted history is immutable) rather than a full refresh, keeping refresh time near-constant regardless of total data volume. `mv_inventory_valuation_current` refreshes every 60 seconds given it reflects a single "current" snapshot rather than a time series. All three are also force-refreshed synchronously immediately after any bulk import or period-close operation.

## Aggregations

Beyond materialized views, three lighter-weight aggregation strategies are used depending on freshness requirements:
- **Redis-cached rollups** for dashboard KPI tiles that tolerate up to 60 seconds of staleness: the Laravel service layer computes the aggregate once, caches it under a key like `kpi:{company_id}:{kpi_code}:{period}`, and serves subsequent requests from Redis until the TTL expires or an explicit cache-bust event fires (see Caching).
- **On-demand aggregation** for anything filtered to a small, indexed working set (a single customer's aging, a single account's GL for the current month) — computed directly against the live tables with no caching layer, since the query is already fast.
- **Pre-computed daily rollup tables** (`report_daily_rollups`, one row per company/dimension/day/metric) populated by a nightly Laravel scheduled job, used as the fast path for multi-year trend charts where even the materialized views would need to scan too many grouped rows for interactive rendering.

```sql
CREATE TABLE report_daily_rollups (
  id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id    BIGINT NOT NULL REFERENCES companies(id),
  branch_id     BIGINT NULL REFERENCES branches(id),
  rollup_date   DATE NOT NULL,
  metric_code   VARCHAR(64) NOT NULL,             -- 'revenue_net', 'expense_total', 'cash_balance', 'headcount', ...
  dimension_type VARCHAR(32) NULL,                 -- 'product_category', 'customer', 'department', NULL = company-wide
  dimension_id  BIGINT NULL,
  value         NUMERIC(19,4) NOT NULL,
  currency_code VARCHAR(3) NOT NULL DEFAULT 'KWD',
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (company_id, branch_id, rollup_date, metric_code, dimension_type, dimension_id)
);

CREATE INDEX idx_rollups_lookup ON report_daily_rollups (company_id, metric_code, rollup_date);
```

## Indexes

Beyond the indexes shown inline above, three cross-cutting indexing rules apply to this module:
1. Every foreign key column on every table in this section has a supporting B-tree index (shown above); PostgreSQL does not create these automatically.
2. Every `JSONB` column that is filtered or searched (`report_definitions.config`, `report_data_sources.fields`) has a `GIN` index to support containment (`@>`) queries used by the Report Builder's field picker and by the permission-scoping logic that checks whether a saved report references a data source the requesting user can no longer access (e.g., after a permission change).
3. Partial indexes (`WHERE deleted_at IS NULL`, `WHERE status IN ('queued','running')`) are used throughout to keep hot-path indexes small, since the overwhelming majority of queries only care about active, non-deleted rows.

## Caching

Three cache layers, from fastest/most-perishable to slowest/most-durable:
- **Redis, request-scoped (TTL 30–300s):** dashboard KPI tiles, ratio computations, and any report whose underlying data changes frequently but whose consumer tolerates near-real-time staleness. Cache keys are namespaced `company_id:report_code:params_hash` and are explicitly busted by domain events (`invoice.posted`, `journal_entry.posted`, `payroll.completed`) that a relevant cache entry depends on, in addition to TTL expiry — so a Cash Position tile updates immediately on a new bank transaction rather than waiting out its TTL.
- **Materialized views (refresh interval 60s–5min):** as described above, for aggregate-heavy queries where per-request computation would be too expensive even cached, but where the aggregation logic itself is reusable across many different reports.
- **report_snapshots (durable, until retention policy or legal hold releases it):** the "cache" of last resort and the audit artifact — once a heavy report has been generated, re-requesting the identical `(report_definition_id, params, as_of)` tuple within a configurable freshness window (default 15 minutes for financial reports, configurable per category) serves the existing snapshot rather than re-running the full query, unless the caller explicitly requests `force_refresh=true`.

## History

Every `report_runs` row is retained indefinitely by default (subject to the Compliance section's retention table) — there is no automatic purge of report execution history, only of the heavier `report_snapshots.payload` bodies once their `retention_expires_at` passes (and even then, the `report_runs` metadata row — parameters, who ran it, when — is retained separately from the bulky rendered payload, so the audit trail of *that a report was run* survives even after the *rendered content* itself has aged out per policy).

## Versioning

`report_definitions.version` increments on every structural change (added/removed field, changed filter default, changed calculated-field formula) to a **published** report; a `parent_definition_id` chain lets the platform show a diff between versions and, critically, lets a historical `report_runs` row record exactly which `report_definition_version` it was generated against, so re-rendering an old snapshot's metadata always shows the report definition *as it existed at that time*, not as it exists today after subsequent edits. Draft edits to an unpublished report do not increment the version (there is nothing to preserve history of yet); only publishing a change to an already-active report creates a new version.

# API

All endpoints are versioned under `/api/v1/reports/`, require a Sanctum/JWT bearer token, are scoped to the active company via the `X-Company-Id` header, and return the standard platform response envelope. Every write endpoint (generate, schedule, share, save-custom-report) additionally checks the endpoint's listed permission before touching any data.

## Endpoint reference

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/reports/definitions` | `reports.{category}.read` | List available report definitions (Standard + Custom), filterable by category |
| GET | `/api/v1/reports/definitions/{id}` | `reports.{category}.read` | Get a single report definition's full config |
| POST | `/api/v1/reports/definitions` | `reports.custom.create` | Create a new Custom Report definition (Report Builder save) |
| PUT | `/api/v1/reports/definitions/{id}` | `reports.custom.update` (own) or `reports.custom.update.any` | Update a Custom Report definition (creates a new version if published) |
| DELETE | `/api/v1/reports/definitions/{id}` | `reports.custom.delete` (own) or `.any` | Soft-delete a Custom Report definition (system reports cannot be deleted) |
| POST | `/api/v1/reports/definitions/{id}/duplicate` | `reports.custom.create` | Clone a report (including Standard Reports) into an editable Custom Report |
| POST | `/api/v1/reports/definitions/{id}/generate` | `reports.{category}.read` | Trigger a report run (sync if light, async `202` if heavy) |
| GET | `/api/v1/reports/runs/{id}` | `reports.{category}.read` | Poll run status; returns the payload once `completed` |
| GET | `/api/v1/reports/runs/{id}/export` | `reports.{category}.export` | Export a completed run — `?format=pdf|xlsx|csv|json` |
| POST | `/api/v1/reports/runs/{id}/ai-narrative` | `reports.{category}.read` + `ai.reports.narrative` | Trigger AI narrative generation for a completed run |
| GET | `/api/v1/reports/schedules` | `reports.schedule.read` | List schedules for the company |
| POST | `/api/v1/reports/schedules` | `reports.schedule.create` | Create a schedule for a report definition |
| PUT | `/api/v1/reports/schedules/{id}` | `reports.schedule.update` | Update a schedule (frequency, params, recipients) |
| DELETE | `/api/v1/reports/schedules/{id}` | `reports.schedule.delete` | Deactivate/delete a schedule |
| POST | `/api/v1/reports/definitions/{id}/share` | `reports.{category}.share` | Create an internal or external share |
| GET | `/api/v1/reports/shared/{token}` | none (token-gated) | Public retrieval of an external-link-shared snapshot |
| DELETE | `/api/v1/reports/shares/{id}` | owner or `reports.{category}.share.any` | Revoke a share |
| GET | `/api/v1/reports/data-sources` | `reports.custom.create` | List semantic-layer data sources available to the requesting user's permissions, for the Report Builder field picker |
| POST | `/api/v1/reports/ask` | `reports.{category}.read` (resolved per question) | Natural-language question answering |
| POST | `/api/v1/reports/draft-from-text` | `reports.custom.create` | Natural-language draft of a new Custom Report definition |
| GET | `/api/v1/reports/favorites` | authenticated | List the current user's saved/favorited reports |
| POST | `/api/v1/reports/favorites` | authenticated | Favorite a report definition |
| GET | `/api/v1/dashboards/{code}` | `reports.{category}.read` | Retrieve a dashboard's widget layout and current widget data |

## Filtering & Pagination

`GET /api/v1/reports/definitions` and any list endpoint support standard query parameters: `category`, `is_system`, `search` (matches `name_en`/`name_ar`), `page`, `per_page` (default 25, max 200), `sort` (e.g. `-updated_at`). Report *run* results (row-level report data) use cursor pagination via `meta.pagination.cursor` for large result sets, since offset pagination degrades badly on the multi-hundred-thousand-row exports this module regularly produces; `page`/`per_page` remains available for small, interactive table views under 1,000 rows.

## Generate Reports — request/response example

```
POST /api/v1/reports/definitions/42/generate
X-Company-Id: 7
Authorization: Bearer <token>
Content-Type: application/json

{
  "params": {
    "period_start": "2026-04-01",
    "period_end": "2026-06-30",
    "branch_id": null,
    "comparison": "prior_period",
    "group_by": ["product_category", "sales_rep"]
  },
  "output_format": "table",
  "include_ai_summary": true
}
```

Synchronous response (light report, `200 OK`):

```json
{
  "success": true,
  "data": {
    "run_id": 918273,
    "status": "completed",
    "as_of": "2026-07-16T09:15:00Z",
    "columns": [
      { "key": "product_category", "label": "Product Category", "type": "string" },
      { "key": "sales_rep", "label": "Sales Rep", "type": "string" },
      { "key": "net_revenue", "label": "Net Revenue", "type": "currency", "currency": "KWD" },
      { "key": "gross_margin_pct", "label": "Gross Margin %", "type": "percentage" },
      { "key": "comparison_variance_pct", "label": "Variance vs Prior Period", "type": "percentage" }
    ],
    "rows": [
      { "product_category": "Electronics", "sales_rep": "Fahad Al-Mutairi", "net_revenue": 48250.5000, "gross_margin_pct": 0.3420, "comparison_variance_pct": 0.0810 },
      { "product_category": "Electronics", "sales_rep": "Dana Al-Ajmi", "net_revenue": 31120.0000, "gross_margin_pct": 0.2985, "comparison_variance_pct": -0.0230 }
    ],
    "subtotals": [
      { "group": "Electronics", "net_revenue": 79370.5000, "gross_margin_pct": 0.3244 }
    ],
    "footer": { "grand_total_net_revenue": 214870.7500, "row_count": 46 },
    "ai_summary": {
      "text": "Net revenue for Q2 2026 rose 8.1% quarter-over-quarter in Electronics, led by Fahad Al-Mutairi's territory, while margin compressed slightly across the category due to a vendor price increase in April.",
      "confidence": 0.81,
      "source_refs": ["report_runs.918273.rows[0..1]", "purchase_analysis.vendor_4021.price_change_2026-04-03"]
    }
  },
  "message": "Report generated successfully",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 200, "total": 46, "cursor": null } },
  "request_id": "6f2f0e2a-9d3f-4b4e-8b0a-7e6a2c9f9d10",
  "timestamp": "2026-07-16T09:15:02Z"
}
```

Asynchronous response (heavy report, `202 Accepted`):

```json
{
  "success": true,
  "data": {
    "run_id": 918274,
    "status": "queued",
    "poll_url": "/api/v1/reports/runs/918274"
  },
  "message": "Report generation queued",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "a1c3e5f7-1234-4abc-9def-0123456789ab",
  "timestamp": "2026-07-16T09:16:00Z"
}
```

## Export — request/response example

```
GET /api/v1/reports/runs/918273/export?format=xlsx
Authorization: Bearer <token>
```

Response: `200 OK`, `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition: attachment; filename="Sales_Analysis_Q2_2026.xlsx"`, binary body. For the `json` format the response instead uses the standard envelope wrapping the same `columns`/`rows`/`subtotals`/`footer` shape shown above.

## Schedule — request/response example

```
POST /api/v1/reports/schedules
X-Company-Id: 7
Authorization: Bearer <token>
Content-Type: application/json

{
  "report_definition_id": 42,
  "name": "Weekly Sales Analysis — Sales Team",
  "frequency": "weekly",
  "run_on_day_of_week": 1,
  "run_at_time": "08:00:00",
  "timezone": "Asia/Kuwait",
  "params": { "period_start": "last_7_days", "group_by": ["product_category", "sales_rep"] },
  "export_format": "pdf",
  "include_ai_summary": true,
  "delivery_method": "email",
  "recipients": [ { "type": "role", "value": "Sales Manager" }, { "type": "email", "value": "ops@qayd.example.com" } ]
}
```

```json
{
  "success": true,
  "data": {
    "id": 305,
    "report_definition_id": 42,
    "name": "Weekly Sales Analysis — Sales Team",
    "frequency": "weekly",
    "next_run_at": "2026-07-20T08:00:00+03:00",
    "is_active": true
  },
  "message": "Schedule created",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9b8a7c6d-5e4f-4a3b-8c1d-2e3f4a5b6c7d",
  "timestamp": "2026-07-16T09:20:00Z"
}
```

## Share — request/response example

```
POST /api/v1/reports/definitions/17/share
X-Company-Id: 7
Authorization: Bearer <token>
Content-Type: application/json

{
  "scope": "external_link",
  "report_snapshot_id": 55210,
  "expires_at": "2026-08-16T00:00:00Z",
  "password": "AuditQ2-2026!",
  "max_views": 20
}
```

```json
{
  "success": true,
  "data": {
    "id": 812,
    "scope": "external_link",
    "share_url": "https://app.qayd.example.com/shared/8f3a2c1e-report",
    "expires_at": "2026-08-16T00:00:00Z",
    "max_views": 20,
    "view_count": 0
  },
  "message": "Share link created",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "2d4e6f80-1a3b-4c5d-9e0f-1a2b3c4d5e6f",
  "timestamp": "2026-07-16T09:25:00Z"
}
```

## Templates & Custom Reports — request/response example

```
POST /api/v1/reports/definitions
X-Company-Id: 7
Authorization: Bearer <token>
Content-Type: application/json

{
  "name_en": "Vendor Concentration Risk",
  "category": "purchasing",
  "output_format": "table",
  "data_sources": ["vendor_bills", "vendors"],
  "config": {
    "fields": ["vendor_name", "total_spend", "bill_count"],
    "calculated_fields": [
      { "id": "concentration_pct", "label": "Concentration %", "formula": "SUM(total_spend) / SUM(total_spend) OVER () * 100", "format": "percentage_1dp" }
    ],
    "filters": { "op": "AND", "conditions": [ { "field": "bill_date", "op": "between", "value": ["relative:this_fiscal_year_start", "relative:today"] } ] },
    "group_by": ["vendor_id"],
    "sort": [ { "field": "total_spend", "direction": "desc" } ]
  }
}
```

```json
{
  "success": true,
  "data": {
    "id": 903,
    "code": "vendor-concentration-risk-903",
    "name_en": "Vendor Concentration Risk",
    "category": "purchasing",
    "status": "draft",
    "version": 1,
    "required_permission": "reports.purchasing.read"
  },
  "message": "Custom report created",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3e5f7091-2b4c-4d6e-8f0a-1b2c3d4e5f6a",
  "timestamp": "2026-07-16T09:30:00Z"
}
```

## Error example — permission denied

```json
{
  "success": false,
  "data": null,
  "message": "You do not have permission to view Payroll reports",
  "errors": [
    { "code": "FORBIDDEN_REPORT_CATEGORY", "field": null, "detail": "Missing permission: reports.payroll.read" }
  ],
  "meta": { "pagination": null },
  "request_id": "4f6081a2-3c5d-4e6f-9a1b-2c3d4e5f6a7b",
  "timestamp": "2026-07-16T09:32:00Z"
}
```

HTTP `403`. Errors follow the platform-standard codes (400/401/403/404/409/422/429/500); `422` is used specifically for invalid report parameters (e.g., `period_end` before `period_start`, or a `group_by` field not present in the report's `data_sources`), with `errors[]` populated per-field.

# Permissions

Permission keys follow the platform convention `<area>.<action>` and `<area>.<entity>.<action>`. The Reporting module's keys are all prefixed `reports.` (plus the `dashboards.` prefix for dashboard-specific visibility, since a dashboard can bundle widgets from several report categories and its own gate is coarser than any single widget's).

| Permission key | Description |
|---|---|
| `reports.financial.read` | View financial-category Standard/Custom Reports (Balance Sheet, Income Statement, Trial Balance, GL, Cash Flow, Profitability, Ratios) |
| `reports.financial.export` | Export financial-category reports to PDF/Excel/CSV/JSON |
| `reports.operational.read` | View operational-category reports |
| `reports.inventory.read` | View inventory-category reports |
| `reports.sales.read` | View sales-category reports (row-scoped to own pipeline for Sales Employee unless `.read.all` is also granted) |
| `reports.sales.read.all` | View sales reports across all reps/branches, not just own |
| `reports.purchasing.read` | View purchasing-category reports |
| `reports.payroll.read` | View payroll-category reports at aggregate/department level |
| `reports.payroll.employee.read` | View individual-employee-level payroll detail (PII-gated, separate from the department-aggregate key above) |
| `reports.tax.read` | View tax-category reports |
| `reports.tax.submit` | Trigger/confirm a tax filing referenced from a Tax Report (actual filing action lives in the Tax module; this key governs the Reporting-side action shortcut) |
| `reports.banking.read` | View banking-category reports |
| `reports.compliance.read` | View compliance-category reports and Compliance Agent findings |
| `reports.audit.read` | View audit-category reports, `audit_logs`-derived reports, and dismiss/annotate AI anomaly flags |
| `reports.executive.read` | View Executive Dashboard and cross-functional executive reports |
| `reports.custom.create` | Build and save new Custom Reports via the Report Builder |
| `reports.custom.update` | Edit a Custom Report the user owns |
| `reports.custom.update.any` | Edit any Custom Report in the company regardless of owner |
| `reports.custom.delete` | Delete a Custom Report the user owns |
| `reports.schedule.create` / `.read` / `.update` / `.delete` | Manage report schedules |
| `reports.{category}.share` | Create internal or external shares for reports in that category |
| `ai.reports.narrative` | Trigger AI narrative/forecast generation on a report (distinct from merely viewing AI Insights that render automatically) |
| `dashboards.executive.read`, `dashboards.finance.read`, `dashboards.sales.read`, `dashboards.inventory.read`, `dashboards.payroll.read`, `dashboards.tax.read`, `dashboards.operations.read`, `dashboards.ai.read` | Per-dashboard visibility, each mapping to the corresponding report category permission plus any dashboard-specific composite widgets |

## Role matrix

| Permission | Owner | CEO | CFO | Finance Mgr | Sr. Accountant | Accountant | Auditor | HR Mgr | Payroll Officer | Inventory Mgr | Warehouse Emp. | Sales Mgr | Sales Emp. | Purchasing Mgr | Purchasing Emp. | Read Only | External Auditor | AI Agent |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| reports.financial.read | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | – | – | – | – | – | – | – | – | view-only | scoped share | delegated |
| reports.financial.export | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | – | – | – | – | – | – | – | – | – | ✗ (view snapshot only) | delegated |
| reports.sales.read | ✓ | ✓ | ✓ | ✓ | – | – | ✓ | – | – | – | – | ✓ | ✓ (own) | – | – | view-only | – | delegated |
| reports.sales.read.all | ✓ | ✓ | ✓ | ✓ | – | – | ✓ | – | – | – | – | ✓ | – | – | – | – | – | delegated |
| reports.inventory.read | ✓ | ✓ | ✓ | ✓ | – | – | ✓ | – | – | ✓ | ✓ (own warehouse) | – | – | ✓ | – | view-only | – | delegated |
| reports.purchasing.read | ✓ | ✓ | ✓ | ✓ | – | – | ✓ | – | – | – | – | – | – | ✓ | ✓ (own) | view-only | – | delegated |
| reports.payroll.read | ✓ | ✓ | ✓ | ✓ | – | – | ✓ (aggregate) | ✓ | ✓ | – | – | – | – | – | – | – | – | delegated |
| reports.payroll.employee.read | ✓ | – | ✓ (approval) | – | – | – | – | ✓ | ✓ | – | – | – | – | – | – | – | – | ✗ (never — always human-restricted) |
| reports.tax.read | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | – | – | – | – | – | – | – | – | view-only | scoped share | delegated |
| reports.banking.read | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | – | – | – | – | – | – | – | – | view-only | scoped share | delegated |
| reports.compliance.read | ✓ | ✓ | ✓ | ✓ | – | – | ✓ | – | – | – | – | – | – | – | – | – | ✓ | delegated |
| reports.audit.read | ✓ | ✓ | ✓ | – | – | – | ✓ | – | – | – | – | – | – | – | – | – | ✓ | delegated (read-only, cannot dismiss anomalies) |
| reports.executive.read | ✓ | ✓ | ✓ | ✓ | – | – | ✓ | – | – | – | – | – | – | – | – | – | – | delegated |
| reports.custom.create | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ (own category scope) | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ (own scope) | ✓ | ✓ (own scope) | – | – | ✓ (drafts only) |
| reports.custom.update.any | ✓ | ✓ | ✓ | ✓ | – | – | – | – | – | – | – | – | – | – | – | – | – | ✗ |
| reports.schedule.create | ✓ | ✓ | ✓ | ✓ | ✓ | – | ✓ | ✓ | ✓ | ✓ | – | ✓ | – | ✓ | – | – | – | ✗ (proposes only; human creates) |
| reports.{category}.share (external_link) | ✓ | ✓ | ✓ | ✓ | – | – | ✓ | – | – | – | – | – | – | – | – | – | ✗ (cannot create outbound shares) | ✗ |

Notes on the matrix: "AI Agent" rows describe what the AI Layer is permitted to *read on the requesting user's behalf* and *propose*, never what it can do unattended — AI never holds an independent grant to `reports.payroll.employee.read` regardless of which human it is momentarily acting for, since payroll PII access additionally requires an explicit per-session elevation that the AI Layer's delegated-context model does not carry. "External Auditor" is intentionally read/export-restricted to scoped share links rather than live report generation, so an auditor's access is always to a specific, dated, reproducible snapshot rather than to the live, ever-changing system — consistent with audit engagement norms. Sensitive Reporting-adjacent actions that exist in *other* modules but are commonly triggered from a report (confirming a tax filing, approving a bank transfer suggested by a Cash Position report, releasing a payroll run reviewed via the Payroll Summary) always require that other module's own approval chain; Reporting's permission keys never substitute for or bypass them.

# Notifications

Reporting & Analytics emits and consumes notifications through the platform's foundation `notifications` table and Laravel Reverb channels, with the following event types specific to this module:

| Event | Trigger | Recipients | Channel(s) |
|---|---|---|---|
| `report.scheduled_run.completed` | A scheduled report finishes generating | Schedule's configured recipients | Email (with attachment) + in-app |
| `report.scheduled_run.failed` | A scheduled report run errors out | Schedule owner + Finance/IT admin role | In-app + email |
| `report.heavy_run.completed` | An async-generated heavy report (`report_runs.status = completed`) the user is waiting on | Triggering user | In-app (toast) + WebSocket push to the polling client |
| `report.share.created` | Someone creates an external share of a report the recipient owns/co-owns | Report owner | In-app |
| `report.share.viewed` | An external share link is accessed (first view, and daily digest thereafter for repeated views) | Share creator | In-app |
| `report.share.expiring_soon` | A share link is within 48 hours of `expires_at` | Share creator | In-app + email |
| `ai.anomaly.detected` | Fraud Detection flags a new anomaly at `high` or `critical` severity | Users with `reports.audit.read` in the relevant company | In-app + email (critical only) + WebSocket |
| `ai.risk.critical` | CFO/Compliance Agent flags a `critical`-severity risk | CFO, Owner, Finance Manager | In-app + email + WebSocket |
| `ai.forecast.accuracy_alert` | A Forecast Agent projection's realized-vs-predicted delta exceeds a configured tolerance (default 15%) for two consecutive periods | CFO, Finance Manager | In-app |
| `report.trial_balance.out_of_balance` | The Trial Balance validation check fails for a closed period (should never happen; treated as P0) | Owner, CFO, Finance Manager, Auditor | In-app + email + escalation to platform on-call (see Edge Cases) |
| `report.definition.published` | A Custom Report is published and shared with a role the recipient belongs to | Affected role members | In-app |
| `report.comment.added` | Someone comments on a report run the recipient is watching (creator, or explicitly subscribed) | Report creator + subscribers | In-app |

Notification delivery respects each user's notification preferences (`users.notification_preferences` in the foundation schema) for channel selection (in-app only vs. in-app+email) except for `report.trial_balance.out_of_balance` and `ai.risk.critical`, which are treated as non-suppressible P0/P1 alerts that always deliver on every configured channel regardless of user preference, given their financial-integrity severity.

# Security

- **Authentication & scoping.** Every request is authenticated via Sanctum/JWT and scoped to the company in `X-Company-Id`; the Reporting service layer never trusts a `company_id` supplied in a request body — it is always re-derived from the authenticated session/header and cross-checked against `company_users` membership before any query executes.
- **Tenant isolation at the query layer, not just the application layer.** Every generated SQL query (whether from a Standard Report, a Custom Report, or the semantic layer behind the Report Builder/AI Q&A) has `company_id = :current_company_id` injected as a mandatory, non-removable predicate by the query-building service, never as an optional filter a report author could omit. This is defense-in-depth alongside PostgreSQL Row-Level Security policies enabled on every tenant table this module reads, so that even a bug in the query-builder's predicate injection would still be caught by the database-level RLS policy.
- **No arbitrary SQL.** The Report Builder's calculated-field formula language and the AI Q&A/natural-language-report agents are restricted to a whitelisted function grammar (see Report Builder → Calculated Fields) compiled into parameterized SQL server-side; there is no code path in this module that accepts or executes a raw, user- or AI-supplied SQL string.
- **PII minimization in payroll and employee data.** Payroll Summary and any Custom Report touching `employees`/`payroll_items` at individual level require the separate `reports.payroll.employee.read` permission (see Permissions); a role holding only `reports.payroll.read` receives department/company aggregates with individual rows suppressed server-side, not merely hidden in the UI — the API itself omits the row-level detail from the response payload for such a caller.
- **Signed, scoped, revocable external shares.** External share links (`report_shares.scope = 'external_link'`) use a cryptographically random `share_token` (never a guessable sequential ID), support an optional password (hashed, never stored/transmitted in plaintext) and `max_views`/`expires_at` limits enforced server-side on every access, and are individually revocable without affecting other shares of the same report.
- **Export watermarking for sensitive categories.** PDF exports of `financial`, `payroll`, and `tax` category reports embed a discreet footer watermark (`Exported by {user} on {timestamp} — Confidential`) to deter uncontrolled redistribution and to make leak provenance traceable.
- **AI Layer isolation.** As stated in AI Responsibilities, every AI agent call into this module's API is authenticated with the requesting user's own delegated permission context — there is no "AI service account" with elevated or company-wide reporting access. An agent proposing a Custom Report draft or answering a natural-language question is exposed to exactly the data the human on whose behalf it is acting could see, enforced by the same RLS and permission-check code path as a direct human request.
- **Rate limiting.** Report generation and export endpoints are rate-limited per user (default 60 generate calls/minute, 20 export calls/minute) and per company (aggregate ceiling to prevent a single tenant's heavy-report load from starving the shared queue workers), returning `429` with a `Retry-After` header when exceeded.
- **Secrets and webhook targets.** Scheduled-report `webhook_url` delivery targets are validated against an allow-list pattern (HTTPS only, no internal/private IP ranges) at creation time to prevent the schedule mechanism from being used for internal network probing (SSRF), and webhook payloads are signed with an HMAC secret unique per company so receivers can verify authenticity.

# Audit Logs

Every mutating and every sensitive read action in this module writes a structured entry to the foundation `audit_logs` table (`actor_id`, `action`, `entity_type`, `entity_id`, `old_value`, `new_value`, `reason`, `ip_address`, `device`, `created_at`), with the following Reporting-specific actions tracked:

| Action | Logged fields of note |
|---|---|
| `report_definition.created` / `.updated` / `.deleted` | Full `config` diff (old vs. new) |
| `report_definition.published` | Version number transition |
| `report_run.generated` | Full resolved `params`, `as_of`, `triggered_by`, duration |
| `report_run.exported` | `format`, exporting user, whether AI summary was included |
| `report_share.created` / `.revoked` | Scope, recipient/token, expiry, password-protected flag (never the password itself) |
| `report_share.accessed` (external link) | Requesting IP, user agent, view count increment — logged even though the requester is unauthenticated, since this is the only audit trail for external access |
| `report_schedule.created` / `.updated` / `.deleted` | Frequency, params, recipients diff |
| `ai.anomaly.dismissed` | Dismissing user, `reason` (mandatory free-text field — cannot be dismissed without one), original anomaly's confidence/severity |
| `ai.narrative.generated` | Requesting user, `confidence`, whether it was subsequently included in an external export |
| `payroll.employee_detail.viewed` | Every individual access to employee-level payroll report data, given its PII sensitivity — this is a *read*-audit exception to the general practice of only logging mutations, made because payroll PII access itself is the sensitive event worth tracking |
| `report_snapshot.accessed_by_external_auditor` | Every view of a snapshot via an `External Auditor`-role session, distinct from a generic share-link view, since External Auditor access carries elevated scrutiny expectations |

Audit log entries for this module are themselves exposed back through the `audit`-category Standard Reports (a report cannot audit-log its own audit-log queries beyond the standard read-access log, avoiding infinite recursion, but every *other* action above is fully queryable). Audit logs are immutable — no `UPDATE` or `DELETE` is ever issued against `audit_logs` rows from this module's code paths; corrections to a mistaken log (extremely rare, e.g., a logging bug) are handled by an additive corrective entry, never an edit to history.

# Performance

- **Async-by-default for heavy reports.** Any `report_definitions` row whose historical execution time (tracked via `report_runs.duration_ms` percentiles) exceeds a 2-second p95 is automatically flagged `execution_mode = async` by the platform, routing all future generations of that report through the `report_runs` queue rather than blocking the requesting HTTP connection, regardless of whether the specific run in question would have been fast — this consistency (a report is either always sync or always async, not sometimes one and sometimes the other depending on current load) keeps client-side integration simple.
- **Queue architecture.** Report generation jobs run on a dedicated Laravel queue connection/worker pool (`reports` queue, separate from the general application queue) so that a burst of heavy Trial Balance regenerations at month-end close cannot starve time-sensitive queues like payment processing or notification delivery. Workers are horizontally scalable; queue depth and worker count are monitored with an alert if `report_runs.status = 'queued'` age exceeds 5 minutes.
- **Query plan budget.** The report-generation service enforces a statement timeout (default 30 seconds for async jobs, 3 seconds for sync jobs) via PostgreSQL's `statement_timeout` session setting, so a pathological Custom Report (e.g., an unindexed multi-way join across years of data) fails fast with a clear `error_code = QUERY_TIMEOUT` rather than degrading the shared database for every other tenant.
- **Materialized-view-first for anything spanning more than the current fiscal period.** As detailed in Database Design, `mv_gl_monthly_balances`, `mv_sales_daily`, and `mv_inventory_valuation_current` exist specifically so multi-period Standard Reports and Analytics never issue a live aggregate query against raw `journal_lines`/`invoice_items`/`inventory_valuations` at the scale of a full fiscal year or more.
- **Result-set streaming for large exports.** Excel/CSV exports of reports exceeding 50,000 rows stream the render (row-by-row workbook writing via PhpSpreadsheet's chunked writer, or line-by-line CSV writing) rather than materializing the entire result set in application memory before writing, keeping worker memory usage bounded regardless of export size.
- **Read replicas for reporting queries.** In multi-branch/high-volume deployments, report-generation queries (never write paths) are routed to a PostgreSQL read replica via Laravel's read/write connection splitting, isolating reporting load from the primary transactional workload; materialized view refreshes run against the primary (since `REFRESH MATERIALIZED VIEW` requires it) but the resulting view is then queried from replicas.
- **Frontend performance budget.** Dashboard initial load targets under 1.5 seconds to first meaningful paint (KPI tiles from Redis-cached rollups render immediately; any widget still loading shows a skeleton state rather than blocking the rest of the dashboard), and the Report Builder's live preview (re-running the in-progress report definition as fields are added) debounces at 500ms and caps preview row count at 100 regardless of the eventual full report size, so building a report never requires waiting on a full-scale query on every drag-and-drop action.

# Compliance

- **Retention.** Financial, tax, audit, and compliance-category `report_snapshots` default to indefinite retention (`retention_expires_at = NULL`), consistent with typical Gulf statutory bookkeeping retention requirements (commonly 5–10 years depending on jurisdiction and record type) and with the platform's broader stance that posted financial history is never purged. Operational and custom-category snapshots default to a 24-month retention window, configurable per company up to indefinite, with any snapshot under `legal_hold = true` exempted from automatic expiry regardless of category.
- **Data residency.** Report payloads and snapshots are stored in the same regional PostgreSQL/R2 infrastructure as the primary operational data they derive from, honoring whatever data-residency commitment the company's contract specifies (relevant for Kuwait/Saudi/UAE public-sector or regulated-industry customers); Reporting never replicates data to a different region for caching or performance purposes without the same residency guarantee applying.
- **Auditor access model.** The External Auditor role's restriction to scoped, snapshot-based share links (see Permissions and Security) is itself a compliance feature: it produces a defensible, dated record of exactly what figures were presented to an external audit engagement, rather than an auditor having live query access to an ever-changing ledger during fieldwork.
- **Statutory report formatting.** Balance Sheet, Income Statement, and Cash Flow exports support jurisdiction-specific presentation templates (see the Financial Statements submodule's `financial_statement_templates`) so that a Kuwait Ministry of Commerce filing format and a bank-covenant-reporting format can both be produced from the identical underlying ledger data without a second reporting system.
- **VAT/tax filing traceability.** Every `STD_TAX_SUMMARY` report run that corresponds to an actually-filed `tax_returns` period is permanently linked (`report_runs.params.tax_return_period_id`) so a tax authority audit can always be answered with "here is the exact report, with this exact snapshot, that produced the filed number."
- **AI output disclosure.** Any AI-generated narrative, forecast, or recommendation included in an externally shared or exported report is visually labeled "AI-generated — verify independently" in the export itself (not only in the interactive UI), satisfying the platform-wide policy that AI content is never presented as unambiguously human-authored analysis to a third party.
- **Right to erasure interaction.** Where a company's data-subject-erasure obligations (e.g., an ex-employee's personal data) intersect with immutable financial snapshots, the module never deletes the underlying `payroll_items`/`employees` records it derives from (that remains the Payroll module's governed process), but does support redacting an individual's name/PII fields specifically within *report payloads* (not the source records) going forward, while historical snapshots already generated and distributed remain as they were, since altering a distributed audit artifact retroactively would itself break auditability — this tension is handled by policy (redaction is forward-looking; already-shared snapshots are handled case-by-case with Legal) rather than by an automatic mechanism.

# Edge Cases

- **Trial Balance fails to balance.** SUM(debit) ≠ SUM(credit) for a supposedly-posted period should be structurally impossible given the double-entry enforcement upstream in Accounting, but the Trial Balance and Balance Sheet reports independently re-validate this identity on every generation rather than trusting that upstream enforcement never has a bug. A failure returns `report_runs.status = failed`, `error_code = TRIAL_BALANCE_UNBALANCED`, fires the non-suppressible `report.trial_balance.out_of_balance` notification to Owner/CFO/Finance Manager/Auditor, and is separately escalated to the AI Fraud Detection agent as a `critical` anomaly for root-cause analysis (most commonly a partially-failed migration, a manual DB intervention, or — rarely — a concurrency bug in journal posting) rather than silently rendering a wrong statement.
- **Report requested for a period with unposted/draft journal entries.** By default, every financial report is computed from **posted** journal lines only (`journal_entries.status = 'posted'`); a report parameter `include_draft = true` (permission-gated to roles that can also see draft entries in Accounting) additionally includes draft lines, clearly labeled with a "includes unposted entries — provisional" banner on the rendered output and excluded entirely from any externally shared/exported snapshot regardless of the parameter, since a provisional statement should never leave the company boundary looking like a final one.
- **Concurrent report generation for the same parameters.** If two users (or a user and a schedule) trigger the identical `(report_definition_id, params, as_of)` tuple within the freshness window described in Caching, the second request is served the first request's in-flight or just-completed `report_runs` row rather than launching a duplicate, avoiding wasted compute and guaranteeing both callers see identical numbers.
- **Currency conversion at report boundaries.** A consolidated multi-entity report spanning subsidiaries with different functional currencies must pick a single, explicit exchange-rate policy per generation (period-average rate for Income Statement-type flows, closing rate for Balance Sheet-type stocks, per IAS 21) — `report_runs.params` always records exactly which rate and rate date were used, so two runs of "the same" consolidated report a week apart, with FX rates having moved, are explainable rather than mysteriously different.
- **Deleted or renamed dimension after a report was generated.** If an `accounts`, `products`, `customers`, `cost_centers`, or similar dimension row referenced by a historical `report_snapshots.payload` is later soft-deleted or renamed, the snapshot's payload already contains the point-in-time label/value baked in at generation time (denormalized into the payload rather than re-joined at view time) — so historical reports remain legible and correct even after the underlying master data changes, while a fresh re-generation of the same report definition against current data would correctly reflect the rename/deletion.
- **Permission revoked after a report was scheduled or shared.** If a user's `reports.{category}.read` permission is revoked after they created a schedule or an internal share, the schedule/share continues to execute (since it was validly created) but the queue worker re-checks the *company's* continued subscription/access to that category and the *recipients'* permissions at delivery time — a revoked recipient stops receiving future scheduled runs, and an attempt by the now-unpermitted original creator to edit or manually re-run the schedule is blocked, surfacing a clear "your access to this report category has changed" message rather than a silent failure.
- **A Custom Report references a data source the author later loses access to.** The report definition remains intact and continues running for other users who still have the requisite permission, but the original author's own future edits/re-runs of it are blocked identically to the case above; `reports.custom.update.any` holders (e.g., CFO) can reassign ownership or adjust the report's data sources to keep it functioning for the team.
- **Extremely large export requested (millions of rows).** Exports are capped at a configurable row ceiling per format (default 500,000 rows for CSV, 250,000 for Excel given its heavier per-row overhead, no hard cap for JSON via the paginated API) — a request exceeding the ceiling returns `422` with `error_code = EXPORT_ROW_LIMIT_EXCEEDED` and a suggestion to narrow the filter/date range or use the paginated API for programmatic bulk extraction instead of a single-file export.
- **AI narrative generated on a report that is later re-run with different results.** An AI narrative is always attached to a specific immutable `report_runs.id`/`report_snapshots.id`, never to the mutable report *definition* — re-running the report produces a new `report_runs` row and requires a fresh (or explicitly skipped) narrative generation, so a stale AI summary can never be silently attached to numbers it didn't actually describe.
- **Natural-language question with an ambiguous or multi-interpretation phrasing.** The Reporting Agent's Question Answering responsibility returns its best interpretation together with an explicit restatement of how it interpreted the question ("Interpreting 'top customers' as top 10 by net revenue over the trailing 12 months — let me know if you meant a different ranking or period") rather than silently picking an interpretation and presenting the answer as unambiguous, and it always exposes an easy way to adjust and re-ask.
- **Benchmarking cohort too small.** If a company's `industry_code`/revenue-band cohort has fewer than the k-anonymity floor (10 contributing companies), the Benchmarking analytic returns a clear "insufficient peer data for this segment" rather than either fabricating a number or silently falling back to a broader, less relevant cohort without saying so.
- **Scheduled report's recipient role has zero members at run time.** The schedule still executes and generates the report (so the artifact and audit trail exist), but delivery is skipped with a logged `report.scheduled_run.no_recipients` warning surfaced to the schedule's creator, rather than either failing the whole run or silently discarding the generated output.
- **Report Builder calculated field divides by zero.** The whitelisted formula grammar's arithmetic functions apply an implicit zero-guard (division by a zero denominator resolves to `NULL`/blank in the rendered cell rather than raising a database error or displaying `Infinity`/`NaN`), and the Report Builder's live preview surfaces a non-blocking warning inline on the field so the author notices and can wrap it in an explicit `IF` guard for controlled behavior (e.g., displaying "N/A" instead of blank) before publishing.

# Future Improvements

- **Semantic layer expansion to a full natural-language query surface.** Extend Question Answering and Natural Language Reports beyond the current governed semantic layer's pre-catalogued data sources toward a broader capability that can propose *new* data-source joins on the fly (still permission-checked and still compiled into parameterized SQL, never raw AI-authored SQL) for genuinely novel cross-module questions the Report Builder's current catalogue doesn't yet anticipate.
- **Embedded distribution.** First-class, officially supported embedding of QAYD dashboards and scheduled digests into Slack, Microsoft Teams, and WhatsApp Business (already a channel elsewhere in the platform's roadmap) so an Executive Dashboard summary or a critical anomaly alert can reach a decision-maker where they already work, without requiring a context switch into the QAYD web app.
- **XBRL-style regulatory tagging.** As Gulf regulators mature toward structured electronic filing requirements (mirroring the trajectory seen in more mature IFRS-XBRL jurisdictions), extend `financial_statement_lines`/`report_definitions` with a standardized taxonomy tagging layer so statutory exports can be generated directly in the required machine-readable filing format, not just human-readable PDF/Excel.
- **Rolling forecast statements.** A pro-forma Balance Sheet and Income Statement, driven end-to-end by the Forecast Agent's projections rather than only forecasting individual line items in isolation, letting a CFO see a fully articulated "if current trends continue" future-period financial statement rather than a collection of separately forecasted metrics that a human must still manually reconcile into a coherent picture.
- **Self-tuning materialized view refresh cadence.** Replace the currently fixed refresh intervals (5 minutes for GL/Sales, 60 seconds for Inventory) with an adaptive scheduler that widens or narrows refresh cadence per company based on observed write volume and observed report-consumption patterns, reducing refresh overhead for low-activity companies while keeping high-activity companies closer to real-time.
- **Anomaly-detection model feedback loop.** Formalize the currently manual "dismiss with reason" audit trail (see Audit Logs) into a structured feedback signal that retrains/recalibrates the Fraud Detection agent's per-company thresholds over time, so a company with naturally lumpy, seasonal transaction patterns stops generating repetitive false-positive anomaly flags that a human has already explained multiple times.
- **Report-level data lineage graph.** A visual, interactive lineage view (beyond the current row-level drill-down) showing, for any report, the full graph of contributing tables, materialized views, and upstream domain events — primarily an internal engineering and audit-support tool, but valuable to expose read-only to Auditor and External Auditor roles as a transparency feature during control-testing engagements.
- **Collaborative real-time report editing.** Extend the Report Builder's current single-editor draft-autosave model to multi-user concurrent editing (operational-transform or CRDT-based) so a Finance team can co-author a complex Custom Report together in one session, analogous to collaborative document editing elsewhere in the productivity-tool landscape.
- **Predictive dashboard personalization.** Use aggregate, privacy-preserving usage-pattern analysis (which widgets a given role habitually checks first, which reports they habitually re-run weekly) to suggest — never auto-apply — dashboard layout and Report Builder template recommendations tailored to how a specific user actually works, always presented as an accept/dismiss suggestion rather than a silent layout change.

# End of Document

