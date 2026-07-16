# Chart of Accounts — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Chart of Accounts
---

# 1 Purpose

The Chart of Accounts (CoA) is the foundational classification structure of the QAYD Accounting Engine. Every financial fact the platform records — a sales invoice, a vendor bill, a bank transfer, a payroll run, a stock adjustment, a tax remittance — ultimately resolves to one or more debit and credit postings against accounts defined in this module. Nothing in Accounting, Sales, Purchasing, Banking, Inventory, Payroll, or Tax can post a journal line without an `account_id` that exists, is active, and belongs to the posting company.

This document specifies the Chart of Accounts as a standalone module: its data model, numbering conventions, hierarchy rules, properties, business rules, validations, workflows, database schema, API surface, permission model, AI responsibilities, import/export behavior, reporting, auditing, notifications, performance and security posture, edge cases, and future roadmap. It is written so that a backend engineer can implement `accounts` and `account_types` in Laravel 12 against PostgreSQL without needing to ask a follow-up question, and so that a frontend or AI engineer can build against the documented `/api/v1/accounting/accounts` surface with no ambiguity about request/response shapes or error semantics.

The CoA in QAYD is deliberately more capable than a spreadsheet-style ledger list. It is:

- **Multi-tenant** — every account belongs to exactly one `company_id`; no company can see or reference another company's accounts.
- **Hierarchical and unlimited-depth** — accounts nest under parent accounts to arbitrary depth, matching how Kuwaiti and broader Gulf accounting practice organizes control accounts, sub-accounts, and analytic accounts.
- **Bilingual by design** — every account carries an Arabic name and an English name as first-class, independently searchable fields, not a translation bolt-on.
- **AI-assisted** — a FastAPI-based classification agent proposes account types, flags duplicates, and recommends accounts for uncoded transactions, but every write to `accounts` happens through the Laravel API under the same permission and validation rules a human user would face.
- **Template-driven** — new companies can seed a full CoA in one action from a country template (Kuwait, Saudi Arabia, UAE) or an industry template (Retail, Trading, Services, Manufacturing, Real Estate, Restaurants), then customize freely.
- **Migration-friendly** — the module accepts CoA imports from Excel/CSV and from the account structures exported by QuickBooks, SAP, Oracle, Odoo, and Zoho, described in detail in section 17.

The Chart of Accounts is not itself a transactional ledger. It stores no balances beyond a company-set opening balance per account; every other balance is derived by summing posted `journal_lines` against the account (see the platform's double-entry rules). This document does not redefine journal posting — it defines the accounts that journal lines point to, and the rules that govern how those accounts are created, classified, numbered, nested, secured, and retired.

# 2 Vision

The long-term vision for the Chart of Accounts module is to make account structuring in QAYD faster, more correct, and less expensive than the equivalent task in any competing ERP, while remaining fully compliant with Gulf statutory and Zakat/tax reporting requirements and IFRS as adopted in Kuwait.

Three pillars drive that vision:

**1. Zero-friction setup.** A new company should never start from a blank CoA. Selecting a country template (Kuwait) and an industry template (e.g., Trading) during company onboarding must produce a complete, IFRS-aligned, bilingual Chart of Accounts — typically 150 to 400 accounts spanning all seven top-level classifications — in under five seconds, with sensible account codes already assigned and control accounts already wired to the sub-ledgers (customers, vendors, inventory, payroll, tax) that depend on them. The owner should be able to go from "just signed up" to "posting my first invoice" without manually creating a single account.

**2. AI as a permanent co-pilot, never a silent actor.** The AI layer (FastAPI) continuously watches account usage patterns across a company's transactions and proposes: reclassifying a misclassified account, merging two accounts that are functional duplicates, creating a missing account it predicts the company will need based on its industry and transaction history, and flagging an account whose behavior (e.g., an "Expense" account receiving only credit-heavy postings) looks anomalous. Every one of these is a suggestion with a confidence score and a natural-language rationale; none of them mutate `accounts` without a human accepting the suggestion through the normal Laravel-validated API path. This is a hard architectural boundary, not a convenience.

**3. Compliance and auditability as defaults, not add-ons.** Posted accounts are protected from silent renumbering or retype once they carry ledger activity. Every field change is versioned in `audit_logs` with old/new values, actor, timestamp, IP, and device. Kuwaiti Zakat/tax mapping and IFRS classification live on the account record itself (`tax_category`), so that VAT/Zakat reporting and financial-statement generation never require a side lookup table maintained by hand.

The measure of success for this module is that a Kuwaiti trading company with a bookkeeper and no accountant on staff can run a compliant CoA unattended for a full fiscal year, while a CFO at a 200-employee group company can drill into cost-center-level sub-accounts across five branches without the account tree becoming unmanageable.

# 3 Business Goals

| # | Goal | Success Metric |
|---|------|-----------------|
| G1 | Eliminate manual CoA setup for new companies | ≥95% of new companies use a template (country or industry) rather than building from scratch; median time-to-first-posted-journal-entry under 10 minutes from signup |
| G2 | Support unlimited organizational complexity without performance loss | Account tree of 5,000 accounts and 8 levels of depth returns a filtered list in <300ms (p95) |
| G3 | Guarantee statutory and IFRS compliance out of the box | 100% of Kuwait-template accounts carry a valid `tax_category` and IFRS classification; zero unclassified control accounts at company go-live |
| G4 | Make bilingual operation a non-negotiable default | 100% of system-seeded accounts have both `name_en` and `name_ar` populated; UI never falls back to showing a blank name in either language |
| G5 | Prevent accidental data corruption of posted financial history | 0% of accounts with posted `journal_lines` can be hard-deleted or have their `normal_balance`/`account_type_id` changed without an explicit reclassification workflow (see section 10) |
| G6 | Reduce duplicate/near-duplicate account proliferation | AI duplicate-detection flags ≥90% of near-duplicate account creations (Levenshtein + semantic similarity ≥0.85) before they are saved |
| G7 | Make migration from incumbent systems (QuickBooks, SAP, Odoo, Zoho, Oracle) a same-day task | A CoA of 500 accounts imports, maps, and validates in under 30 minutes with a human reviewing AI-suggested mappings, not building them from scratch |
| G8 | Keep every account auditable end-to-end | 100% of create/update/deactivate/reclassify operations on `accounts` produce a corresponding `audit_logs` row queryable by account, by actor, and by date range |
| G9 | Support multi-branch, multi-cost-center, multi-department analytics without duplicating the CoA per branch | A single `accounts` tree serves all branches of a company; dimensional reporting (branch, cost center, department, project) is achieved via `journal_lines` dimensions, not via branch-specific duplicate accounts |
| G10 | Keep the account API fast enough to back autocomplete UX | `GET /api/v1/accounting/accounts?search=` returns ranked, bilingual-matched results in <150ms (p95) for typeahead in invoice/bill entry screens |

# 4 Accounting Concepts

This section grounds the module in the accounting theory it implements, translated into QAYD's concrete data model.

**Double-entry foundation.** Every account has a *normal balance* — the side (debit or credit) on which increases to that account are recorded. Assets and Expenses are debit-normal: a debit increases them, a credit decreases them. Liabilities, Equity, and Revenue are credit-normal: a credit increases them, a debit decreases them. "Other Income" and "Other Expenses" (see section 6) inherit the normal balance of Revenue and Expense respectively, since they are non-operating variants of the same underlying nature, not a new accounting nature.

**Account nature vs. account type vs. account category.** QAYD distinguishes three levels of classification that are often conflated in simpler systems:

- *Nature* (`account_types.nature`): the seven immutable, system-defined top-level buckets — Asset, Liability, Equity, Revenue, Expense, Other Income, Other Expense. These map 1:1 to the balance sheet / income statement placement of the account and can never be edited by a tenant.
- *Type* (`account_types` row, e.g., "Current Asset", "Fixed Asset", "Bank", "Accounts Receivable", "Cost of Goods Sold"): a finer classification within a nature that determines statement grouping (e.g., which sub-total on the Balance Sheet an account rolls into) and which sub-ledger, if any, the account is a control account for.
- *Category / group* (the parent-child position of the account within the tree, section 7): the company-specific organizational grouping a tenant builds on top of the system type, e.g., "Bank Accounts – Al Ahli Bank" under "Current Assets" under "Assets."

**Control accounts and sub-ledgers.** Certain account types are marked `is_control_account = true` and are never posted to directly by a human; they receive postings only as the summarized result of sub-ledger activity. Accounts Receivable (AR) is the control account for the `customers` sub-ledger; Accounts Payable (AP) is the control account for the `vendors` sub-ledger; Inventory is the control account for `inventory_items`/`stock_movements`; a Payroll Payable account is the control account for `payroll_items`. The sum of open sub-ledger balances (unpaid invoices, unpaid bills, stock valuation, unpaid payslips) must reconcile to the control account's GL balance at all times; a mismatch is a P1 data-integrity alert (section 21).

**Posting-eligible vs. header/group accounts.** An account can be a *posting account* (journal lines may reference it directly) or a *header/group account* that exists purely to organize the tree (journal lines may never reference it). This is captured by `accounts.allow_posting`. Group accounts typically correspond to top-level and second-level nodes (e.g., "Current Assets") that exist to roll up their children on financial statements; leaf accounts are usually posting accounts, though a leaf account can also be a group account with zero children if the tenant marks it non-postable for organizational reasons.

**Opening balances and the accounting equation.** Each account's `opening_balance` is recorded as of the company's chosen opening date (typically the first day of the first fiscal year on QAYD, or a mid-year cutover date during migration). Opening balances must self-balance: `SUM(opening_balance where normal_balance = debit and nature in Asset,Expense) = SUM(opening_balance where normal_balance = credit and nature in Liability,Equity,Revenue)` after accounting for sign conventions, enforced by the Business Rules in section 10 via a mandatory opening-balance journal entry rather than a free-floating field (see section 10, BR-6).

**Multi-currency accounts.** An account can be currency-restricted (e.g., a USD bank account) or free-currency (most standard accounts, which record transactions in any transaction currency, converted to the company's base currency for the GL). This is controlled by `accounts.currency_code` (nullable = free-currency) as detailed in section 9.

**Statutory/Tax classification.** In Kuwait, most entities are not currently subject to corporate income tax (except foreign-owned entities subject to Kuwait's corporate income tax and Zakat obligations for KSE-listed shareholding companies), but VAT is anticipated across the GCC under the unified VAT framework, and many QAYD tenants operate cross-border in Saudi Arabia (which has VAT and Zakat) and the UAE (VAT and corporate tax as of 2023). The `tax_category` field on each account (section 9) captures how balances in that account should be treated for VAT input/output classification, Zakat base calculation, and corporate tax adjustments, so the Tax module can consume the CoA without a parallel mapping table.

# 5 Definitions

| Term | Definition |
|------|------------|
| **Account** | A single node in the Chart of Accounts tree; the atomic unit journal lines post to (if `allow_posting = true`) or that groups other accounts (if used as a parent). Row in `accounts`. |
| **Account Type** | A system- or tenant-extended classification (e.g., "Bank", "Accounts Receivable", "Cost of Goods Sold") that fixes an account's `nature` and `normal_balance` and drives its financial-statement placement. Row in `account_types`. |
| **Nature** | One of the seven immutable top-level classifications: Asset, Liability, Equity, Revenue, Expense, Other Income, Other Expense. |
| **Normal Balance** | The side (`debit` or `credit`) on which an increase to the account is recorded, determined by the account's nature. |
| **Control Account** | An account that summarizes a sub-ledger (AR, AP, Inventory, Payroll Payable, etc.) and is never posted to directly by a manual journal entry; only sub-ledger transactions post to it. |
| **Posting Account** | An account with `allow_posting = true`; journal lines may reference it. |
| **Group / Header Account** | An account with `allow_posting = false`, used purely to organize the hierarchy and roll up children on statements. |
| **Parent Account** | The account directly above another account in the hierarchy, referenced by `accounts.parent_id`. |
| **Child Account** | Any account whose `parent_id` points to a given account. |
| **Root Account** | An account with `parent_id IS NULL`; one of the seven top-level natures or a tenant-defined top-level grouping beneath them. |
| **Account Code** | The unique-per-company identifier string (e.g., `1101.001`) assigned to an account, used for sorting, display, and integration with external systems. |
| **Account Group / Subgroup** | A named organizational band the tenant can apply on top of the parent/child tree for reporting/display convenience without affecting posting logic (`accounts.group_name`). |
| **Chart Template** | A predefined, versioned set of `account_types` + `accounts` rows (country or industry) that can be cloned into a new company at onboarding or on demand. |
| **Opening Balance** | The account's balance as of the company's books-open date, recorded via a system-generated opening journal entry, never as a raw field edit after go-live. |
| **Cost Center** | A dimension (`cost_centers` table) that can be attached to a journal line for that account to enable departmental/unit-level P&L slicing, independent of the account tree itself. |
| **Tax Category** | The VAT/Zakat/corporate-tax treatment tag on an account (e.g., `vat_standard_output`, `vat_exempt`, `zakat_base_asset`, `non_taxable`) consumed by the Tax module. |
| **AI Category / AI Suggestion** | A machine-generated classification or recommendation attached to an account or a proposed account, always carrying a confidence score and reasoning, never auto-applied to a posted account without human/policy approval. |
| **Soft Delete** | Marking `accounts.deleted_at` rather than removing the row; the account disappears from active UI/API lists but its history remains intact for audit and reporting on periods that included it. |
| **Reclassification** | The controlled workflow (section 10, section 12) of moving an account to a different type, parent, or nature after it has posted activity, executed via a compensating journal rather than a silent field edit. |
| **Merge** | The workflow of combining two accounts into one, redirecting all historical `journal_lines` from the losing account to the surviving account and soft-deleting the loser. |

# 6 Chart of Accounts Architecture

QAYD's Chart of Accounts is organized into exactly seven top-level natures, fixed at the platform level and never editable by a tenant (they are seeded system rows in `account_types` with `is_system = true`, `company_id = NULL`). Every account, at every depth, ultimately rolls up to exactly one of these seven.

## 6.1 Assets

Resources controlled by the company from which future economic benefit is expected. Debit-normal.

Standard second-level groups seeded by every template:
- **Current Assets**: Cash & Bank, Accounts Receivable, Inventory, Prepaid Expenses, Employee Advances, VAT Input/Recoverable, Short-Term Investments.
- **Non-Current (Fixed) Assets**: Property, Plant & Equipment (land, buildings, machinery, vehicles, furniture, computer equipment), Intangible Assets (software licenses, goodwill), Accumulated Depreciation (contra-asset, credit-normal by exception — see section 10 BR-9), Long-Term Investments, Capital Work in Progress.

Control accounts within Assets: **Accounts Receivable – Trade** (control for `customers`), **Inventory** (control for `inventory_items`), **VAT Input Recoverable** (control for `tax_transactions` of type input).

## 6.2 Liabilities

Present obligations from past events, settlement of which is expected to result in an outflow of resources. Credit-normal.

- **Current Liabilities**: Accounts Payable, Accrued Expenses, VAT Output/Payable, Short-Term Loans, Payroll Payable (net salaries, GOSI/social-security payable, end-of-service provision current portion), Customer Advances/Unearned Revenue, Dividends Payable.
- **Non-Current Liabilities**: Long-Term Loans, End-of-Service Indemnity Provision (long-term portion), Lease Liabilities (IFRS 16), Deferred Tax Liabilities.

Control accounts within Liabilities: **Accounts Payable – Trade** (control for `vendors`), **Payroll Payable** (control for `payroll_items`), **VAT Output Payable** (control for `tax_transactions` of type output).

## 6.3 Equity

The residual interest of owners after deducting liabilities from assets. Credit-normal.

- Share Capital / Owner's Capital, Share Premium, Statutory Reserve (mandatory under Kuwait Commercial Companies Law for shareholding companies — 10% of net profit until reserve equals 50% of capital), Voluntary Reserve, Retained Earnings / Accumulated Losses, Current Year Net Profit (system-computed roll-forward, not manually posted), Owner Drawings (contra-equity, debit-normal by exception).

## 6.4 Revenue

Income arising from the ordinary operating activities of the company. Credit-normal.

- Sales Revenue (by product category if the tenant wants revenue-line analytics), Service Revenue, Sales Returns & Allowances (contra-revenue, debit-normal by exception), Sales Discounts (contra-revenue).

Control accounts within Revenue: none by default (Revenue posts from `invoices`/`invoice_items` directly, not from a sub-ledger requiring reconciliation), though a tenant may elect deferred-revenue recognition rules that route through a Liability control account first (Unearned Revenue) before recognition.

## 6.5 Expenses

Decreases in economic benefit arising from the ordinary operating activities. Debit-normal.

- **Cost of Goods Sold / Cost of Sales**: Direct Materials, Direct Labor, Freight-In, Inventory Shrinkage/Write-off.
- **Operating Expenses**: Salaries & Wages, Rent, Utilities, Marketing & Advertising, Professional Fees, Depreciation Expense, Repairs & Maintenance, Office Supplies, Insurance, Bank Charges, IT & Software Subscriptions, Travel.

## 6.6 Other Income

Income not arising from the company's principal operating activities. Credit-normal (inherits Revenue's normal balance).

- Gain on Disposal of Fixed Assets, Foreign Exchange Gain, Interest Income, Rental Income (for a non-real-estate company), Miscellaneous Income.

## 6.7 Other Expenses

Expenses not arising from principal operating activities. Debit-normal (inherits Expense's normal balance).

- Loss on Disposal of Fixed Assets, Foreign Exchange Loss, Interest Expense / Finance Charges, Donations, Fines & Penalties (typically non-deductible for tax purposes, tagged accordingly in `tax_category`).

## 6.8 Why seven natures and not five

Many textbook treatments use five elements (Asset, Liability, Equity, Revenue, Expense). QAYD splits out **Other Income** and **Other Expense** as distinct natures — while preserving their inherited normal balance from Revenue/Expense respectively — because Gulf financial-statement presentation (and IFRS's "profit or loss" structure) conventionally separates operating results from non-operating/other items above the net-profit line, and because the AI classification agent (section 16) gets materially better precision when "Interest Income" is never confusable with "Sales Revenue" at the nature level, not just the type level. This split is a presentation and classification convenience layered on top of the underlying debit/credit mechanics — it does not change double-entry math, since `SUM(debits) = SUM(credits)` is enforced at the journal-entry level regardless of how many natures the CoA exposes.

# 7 Hierarchy

## 7.1 Unlimited nesting

The `accounts` table is a self-referencing tree via `parent_id BIGINT NULL REFERENCES accounts(id)`. There is no hardcoded depth limit in the schema; the platform enforces a soft operational ceiling of **12 levels** (configurable per company via `companies.settings->>'max_account_depth'`, default 12) to keep UI tree rendering and recursive CTE performance predictable. In practice, templates seed 3–4 levels (Nature → Group → Sub-group → Posting Account) and tenants rarely exceed 6, but large group companies with cost-center-per-branch sub-accounting (e.g., "Cash – Branch 3 – Till 2") can legitimately reach 7–8.

Depth is not stored as a column that must be kept in sync manually; it is computed on read via a recursive CTE (section 13.4) and cached in `accounts.depth` (denormalized, recalculated by a database trigger on insert/update of `parent_id`) purely as a read-performance optimization — the trigger is the single source of truth for keeping it correct, so application code never writes to `depth` directly.

## 7.2 Parent Accounts

A parent account is any account referenced by at least one other account's `parent_id`. Parent accounts:

- May be posting accounts or group accounts; QAYD does not force a parent to be non-postable, because some tenants want a summary account (e.g., "Bank Accounts") that also occasionally receives a direct posting for an unclassified bank fee before it's split into named bank sub-accounts. This is discouraged in the UI (a warning is shown) but not blocked, since forcing it would break valid migration scenarios from flatter legacy charts.
- Cannot be deleted (soft or hard) while they have any non-deleted child account. The API returns `409 Conflict` (error code `ACCOUNT_HAS_CHILDREN`) on such an attempt.
- Cannot change `nature`/`account_type_id` in a way that would make them inconsistent with their children's nature (see Business Rule BR-3, section 10) without first migrating the children.

## 7.3 Child Accounts

A child account's `nature` must always match its ultimate root ancestor's nature — an account under "Assets" cannot itself be typed as "Liability" no matter how deep it sits. `account_type_id` can be more specific than the parent (e.g., parent is generic "Current Assets" group type, child is specifically "Bank" type) but must resolve to the same `nature`. This is validated at write time (section 11, VR-4).

Children inherit, unless explicitly overridden: `currency_code` (nullable inheritance — if a child doesn't set its own, it is free-currency, it does NOT auto-inherit the parent's currency restriction), `cost_center_id` default, and `branch_id` visibility scope. Explicit overrides always win; inheritance here is a UI convenience (pre-filling the "create child account" form with the parent's values), not a runtime lookup chain, to keep balance calculation simple and avoid the class of bugs where changing a parent silently reclassifies deep descendants.

## 7.4 Account Groups

An **Account Group** is the second-level organizational band directly under a nature (e.g., "Current Assets," "Non-Current Liabilities," "Operating Expenses"). Groups are ordinary rows in `accounts` with `allow_posting = false` and `parent_id` pointing at the nature root. They exist to give financial statements their standard sub-totals (Total Current Assets, Total Current Liabilities, etc.) and are what `report_definitions` for the Balance Sheet and Income Statement key off via `accounts.statement_section`.

## 7.5 Subgroups

A **Subgroup** is any further organizational layer between a Group and the posting accounts, used for finer statement presentation or purely for tenant-side navigation convenience (e.g., "Cash & Bank" as a subgroup under "Current Assets," containing "Cash on Hand" and five named bank accounts). Subgroups are not a distinct schema concept — they are simply additional depth in the same `parent_id` chain — but the UI labels a node a "subgroup" when it sits strictly between the seeded Group level and leaf posting accounts, and `accounts.group_name` can carry a free-text label independent of the tree position for cross-cutting groupings (e.g., tagging accounts across different branches of the tree as "IFRS 16 Related" for a custom report without moving them).

## 7.6 Tree Integrity Rules

- No cycles: `parent_id` may never (directly or transitively) point back to the account itself. Enforced by a `BEFORE INSERT OR UPDATE` trigger walking the ancestor chain (bounded by `max_account_depth + 1` iterations to guarantee termination even under corruption).
- Cross-company references are impossible: `parent_id` must reference an account with the same `company_id`; enforced by application-layer validation plus a `CHECK`-equivalent trigger, since PostgreSQL cannot express a same-table cross-column FK constraint declaratively for this case.
- Cross-branch nesting is allowed (a branch-scoped account can sit under a company-wide group account) since `branch_id` on `accounts` is a visibility/reporting filter, not a tree-partitioning key.

# 8 Numbering System

## 8.1 Account Codes

Every account has a unique-per-company `code` (VARCHAR(20)), displayed alongside its bilingual name everywhere in the UI ("1101.001 · Cash on Hand · النقد في الصندوق"). Codes are strings, not integers, to support segment separators (`.`), leading zeros, and alphanumeric segments some industry templates require (e.g., project-coded accounts like `5000-PRJ-014`).

QAYD's default numbering convention follows the classic Gulf/IFRS-aligned first-digit-by-nature scheme:

| First digit | Nature |
|---|---|
| 1 | Asset |
| 2 | Liability |
| 3 | Equity |
| 4 | Revenue |
| 5 | Expense |
| 8 | Other Income |
| 9 | Other Expense |

Within a nature, the second digit denotes the Group (e.g., `11xx` Current Assets, `12xx` Fixed Assets; `51xx` COGS, `52xx` Operating Expenses). Subgroups and posting accounts extend with further digits and an optional dot-suffix sequence for tenant-added sub-accounts under a system-seeded account, e.g., system account `1101` "Bank Accounts" (group, non-postable) → tenant-created `1101.001` "Bank Accounts – NBK Current," `1101.002` "Bank Accounts – Boubyan Savings."

## 8.2 Automatic Generation

When a tenant creates a new account without specifying a code, the backend generates one deterministically:

1. Resolve the intended parent (explicit `parent_id`, or inferred from `account_type_id`'s default group).
2. If the parent's code has no existing dot-suffixed children, allocate `<parent_code>.001`.
3. If it has children, allocate `<parent_code>.<max_existing_suffix + 1>`, zero-padded to 3 digits (rolling to 4 digits transparently past 999 children — extremely rare, but not an artificial ceiling).
4. If the parent itself has no code (a same-transaction concurrent creation edge case), fall back to the next unused top-level code in the nature's reserved digit range, skipping any code already taken (including soft-deleted accounts' codes, which remain reserved and are never recycled — see section 10, BR-8).

Generation runs inside the same DB transaction as the insert, using `SELECT ... FOR UPDATE` on the parent row to serialize concurrent child-creation and guarantee no two siblings ever race to the same code (see section 11, VR-2, and section 24 edge cases on concurrent creation).

## 8.3 Custom Codes

A tenant (with `accounting.accounts.create` permission) may specify any code explicitly instead of accepting the generated one, subject to:

- Uniqueness within `company_id` (partial unique index, excluding soft-deleted rows — see section 13).
- Format validation: 1–20 characters, alphanumeric plus `.`, `-`, `_`; must not start or end with a separator.
- A first-digit-vs-nature consistency warning (not a hard block) if the tenant's custom code's leading digit doesn't match the convention in 8.1 — this is a warning because some migrated charts (particularly from QuickBooks or from bespoke legacy systems) use entirely different numbering philosophies, and QAYD must not force a wholesale renumbering just to import a working chart.

## 8.4 Country Templates

Country templates are versioned, system-owned snapshots of `account_types` + `accounts` (with `company_id = NULL`, `is_template = true`) that get *cloned* — not referenced — into a new company's own `accounts` rows on selection. Cloning, not referencing, is deliberate: it lets the tenant freely edit their copy without ever risking mutation of the shared template, and it lets QAYD ship template-v2 updates without silently altering accounts a tenant already customized.

Shipped country templates at GA:

| Template code | Coverage |
|---|---|
| `KW_STANDARD` | Kuwait — IFRS-aligned, includes Zakat/KFAS/NLST reserve accounts for KSE-listed entities, VAT-ready placeholder accounts (dormant `tax_category` until Kuwait VAT go-live) |
| `SA_STANDARD` | Saudi Arabia — IFRS-aligned, active VAT accounts (15%), Zakat computation accounts for Zakat-payable entities, WHT (withholding tax) payable accounts |
| `AE_STANDARD` | UAE — IFRS-aligned, active VAT accounts (5%), UAE Corporate Tax (9% above threshold) provision accounts |

## 8.5 Industry Templates

Industry templates layer on top of (compose with) a country template, adding/renaming Group and Subgroup accounts specific to the sector without touching the country's statutory accounts:

| Template code | Adds |
|---|---|
| `RETAIL` | Point-of-sale cash-drawer accounts, layaway/deposit liability, shrinkage expense, loyalty-points liability |
| `TRADING` | Import duty clearing account, letters-of-credit liability, freight-in/out split, FX translation reserve |
| `SERVICES` | Work-in-progress (unbilled services) asset, retainer/advance liability, subcontractor payable |
| `MANUFACTURING` | Raw materials, WIP inventory, finished goods (as three distinct Inventory sub-accounts rather than one), factory overhead absorption account |
| `REAL_ESTATE` | Tenant security deposits liability, rental income by property (subgroup per property), property management fee expense |
| `RESTAURANTS` | Food cost vs. beverage cost COGS split, delivery-platform commission expense, tips payable liability |

A company applies exactly one country template and zero-or-one industry template at creation time; additional industry accounts can be merged in later via the Import flow (section 17) using the same template as a source.

# 9 Account Properties

Every account row exposes the following properties. Columns marked **required** cannot be null on a non-deleted, active row; columns marked **system** are never directly writable by a tenant user through the standard update endpoint (only through the specific workflow that owns them).

| Property | Column | Type | Required | Notes |
|---|---|---|---|---|
| Name (default display) | `name` (generated) | — | — | Computed as `name_en` or `name_ar` depending on the requesting user's active language; not a stored column |
| Arabic Name | `name_ar` | VARCHAR(255) | Yes | Full Arabic name; RTL rendered; searchable via `pg_trgm` + Arabic-aware `unaccent`-style normalization |
| English Name | `name_en` | VARCHAR(255) | Yes | Full English name |
| Description | `description` | TEXT | No | Free-text explanation of the account's intended use; shown as a tooltip and in account-picker search results |
| Type | `account_type_id` | BIGINT FK | Yes | Points to `account_types`; fixes `nature` and default `normal_balance` |
| Currency | `currency_code` | CHAR(3) | No | ISO 4217; NULL = free-currency (transacts in any currency, converts to base); non-NULL restricts the account to that currency only (typical for foreign-currency bank accounts) |
| Opening Balance | `opening_balance` | NUMERIC(19,4) | No, default 0 | Base-currency amount as of company's books-open date; written only via the opening-balance workflow (section 10 BR-6), read-only afterward through the standard update endpoint |
| Normal Balance | `normal_balance` | ENUM('debit','credit') | Yes (system-derived) | Derived from `account_types.nature` at creation; stored denormalized for query speed; only mutable via the Reclassification workflow (section 12.4) |
| Status | `status` | ENUM('active','inactive','archived') | Yes, default 'active' | `inactive` = hidden from new-transaction pickers but still reportable; `archived` = soft-deleted equivalent for display purposes prior to actual `deleted_at` set |
| Parent | `parent_id` | BIGINT FK (self) | No (NULL = root) | See section 7 |
| Cost Center | `default_cost_center_id` | BIGINT FK `cost_centers` | No | Pre-fills the cost center dimension when this account is selected on a new journal line; does not restrict which cost centers can be used |
| Branch | `branch_id` | BIGINT FK `branches` | No | NULL = company-wide account visible to all branches; non-NULL scopes visibility/default use to one branch |
| Department | `default_department_id` | BIGINT FK `departments` | No | Same pre-fill semantics as Cost Center |
| Tax Category | `tax_category` | VARCHAR(50) | No | Enum-like string consumed by the Tax module, e.g. `vat_standard_output`, `vat_standard_input`, `vat_exempt`, `vat_zero_rated`, `vat_out_of_scope`, `zakat_base_asset`, `zakat_excluded`, `non_deductible_expense`, `withholding_applicable`, `none` |
| AI Category | `ai_suggested_type_id` **(system)** | BIGINT FK `account_types` | No | Last AI-suggested reclassification, kept alongside the human-confirmed `account_type_id` for audit/learning; never authoritative on its own |
| Tags | `tags` | JSONB | No, default `[]` | Free-form tenant labels, e.g. `["ifrs16","related-party"]`; GIN-indexed |
| Attachments | — (polymorphic) | — | No | Via foundation `attachments` table (`attachable_type = 'account'`, `attachable_id = accounts.id`) — supporting documents such as a bank account's IBAN certificate or an asset account's ownership deed |
| Created By | `created_by` **(system)** | BIGINT FK `users` | Auto | Set once at insert, never updated |
| Updated By | `updated_by` **(system)** | BIGINT FK `users` | Auto | Set on every update |
| Audit History | — (derived) | — | — | Queryable via `audit_logs` filtered to `entity_type = 'account', entity_id = accounts.id`; not a column on `accounts` itself |

Additional non-listed but standard columns present per the platform's universal tenant-table conventions: `id`, `company_id`, `code`, `depth` (denormalized, trigger-maintained), `path` (denormalized materialized ancestor path, e.g. `1.11.1101`, used for fast subtree queries — see section 13.5), `allow_posting`, `is_control_account`, `is_system` (true for template-cloned/system-critical accounts a tenant cannot delete, only deactivate), `group_name`, `sort_order`, `created_at`, `updated_at`, `deleted_at`.

**Field-level notes:**

- `name_en`/`name_ar` are independently required — QAYD does not allow "Arabic name same as English" shortcuts, because Arabic-first users (the majority of Kuwait SME bookkeepers) must never see an English placeholder inside an Arabic-language UI. The one exception is proper nouns with no natural translation (e.g., a specific bank's brand name), where the same Latin string may legitimately appear in both fields; validation does not forbid identical values, it only forbids either field being empty.
- `opening_balance` is directional-signed in the account's natural terms (a positive number means "a balance in the normal-balance direction"); a debit-normal account with a positive `opening_balance` has a debit balance, a credit-normal account with a positive `opening_balance` has a credit balance. Negative values are permitted (e.g., a bank account overdrawn at opening) and represent a balance on the abnormal side.
- `status = 'inactive'` is the standard way to retire an account from future use while preserving it fully in historical reports; `deleted_at` (soft delete) is reserved for accounts created in error with zero posted activity (section 10 BR-7).

# 10 Business Rules

| ID | Rule |
|---|---|
| BR-1 | An account's `nature` is immutable after creation. Changing nature (e.g., Asset → Expense) is never permitted, even via reclassification — it requires closing the old account (deactivate) and creating a new one, because nature changes the fundamental meaning of every historical posting. |
| BR-2 | `account_type_id` may be changed (reclassification, section 12.4) only if the new type shares the same `nature` as the current type. Cross-nature type changes are rejected with `422 INVALID_RECLASSIFICATION`. |
| BR-3 | A parent account and all its children must share the same `nature`. Attempting to create a child whose type's nature differs from the parent's root nature is rejected at write time. |
| BR-4 | Only accounts with `allow_posting = true` may be referenced by `journal_lines.account_id`. Attempting to post to a group account is rejected by the Journal Entries module (cross-module rule, enforced at the FK/validation boundary Accounting exposes to itself) with `422 ACCOUNT_NOT_POSTABLE`. |
| BR-5 | Control accounts (`is_control_account = true`) may only receive postings that originate from their owning sub-ledger's domain events (e.g., `invoice.posted` → AR, `bill.posted` → AP, `stock_movement.posted` → Inventory, `payslip.posted` → Payroll Payable). A manual journal entry targeting a control account is blocked by default (`422 CONTROL_ACCOUNT_DIRECT_POST_FORBIDDEN`) unless the acting user holds the elevated permission `accounting.journal.post_to_control_account`, reserved for period-end reconciling entries and requiring a mandatory `reason` note captured in `journal_entries.memo`. |
| BR-6 | Opening balances are never set by editing `accounts.opening_balance` directly after the company's books are open. They are set exactly once, per account, via a system-generated "Opening Balance" journal entry dated on the company's books-open date, balanced against a system "Opening Balance Equity" clearing account, which must itself net to zero once all opening balances are entered. The `accounts.opening_balance` column is a read-optimized cache of that entry's net effect on the account, recalculated by trigger whenever the opening journal entry's lines change (only permitted before the fiscal year is locked). |
| BR-7 | An account may be hard-deleted (irreversibly, and only as a soft-delete setting `deleted_at`, per platform-wide no-hard-delete policy) only if it has zero `journal_lines` referencing it (posted or draft) and zero child accounts. Any account with posted history can only be deactivated (`status = 'inactive'`), never deleted. |
| BR-8 | A deleted account's `code` is permanently reserved within the company and is never reused by automatic generation, to prevent historical reports that filtered by code from ever silently picking up an unrelated later account. |
| BR-9 | Contra accounts (Accumulated Depreciation under Assets, Sales Returns & Allowances and Sales Discounts under Revenue, Owner Drawings under Equity) carry a `normal_balance` opposite to their nature's default, flagged via `account_types.is_contra = true`; this is the sole exception to "normal balance is derived purely from nature." |
| BR-10 | Every posted `journal_line` must reference an account belonging to the same `company_id` as the `journal_entry` header; cross-company account references are structurally impossible because `journal_lines.account_id` is validated against the entry's `company_id` at insert time, not just at the account-existence level. |
| BR-11 | Merging account A (loser) into account B (survivor) requires A and B to share the same `nature` and the same `currency_code` restriction (both free-currency, or both restricted to the identical ISO code); the merge workflow (section 12.5) is blocked otherwise with `422 MERGE_INCOMPATIBLE_ACCOUNTS`. |
| BR-12 | A branch-scoped account (`branch_id IS NOT NULL`) cannot be selected as the `account_id` on a journal line whose header `branch_id` differs, unless the acting user's role has cross-branch posting rights; this keeps branch P&L clean by default while still allowing head-office consolidating entries. |
| BR-13 | The seven `nature` values and their default `normal_balance` are hardcoded platform constants, never rows a tenant can add to, edit, or delete; `account_types.nature` has a CHECK constraint restricting it to the seven allowed values. |
| BR-14 | Every company must have at least one active, posting-enabled account of type "Retained Earnings" (Equity) and one of type "Current Year Net Profit" (Equity, system-computed) before the first fiscal year can be closed; the Fiscal Year close workflow validates this precondition. |
| BR-15 | AI-authored account creation or reclassification suggestions (section 16) never bypass BR-1 through BR-14; the AI service calls the exact same Laravel validated endpoints a human uses, receiving the exact same errors on a rule violation. |

# 11 Validation Rules

Validation happens in a Laravel `FormRequest` (`StoreAccountRequest`, `UpdateAccountRequest`) before the Service layer is invoked, per the platform's Controller → FormRequest → Service → Repository → Model pipeline. All violations return `422` with `errors[]` populated per the standard envelope (section 5 of the shared platform conventions).

| ID | Field(s) | Rule | Error code |
|---|---|---|---|
| VR-1 | `name_en`, `name_ar` | Required, string, 1–255 chars, not pure whitespace | `NAME_REQUIRED` |
| VR-2 | `code` | If provided: unique within `company_id` among non-deleted accounts (case-insensitive); 1–20 chars; pattern `^[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?$`. If omitted: auto-generated (section 8.2) | `CODE_DUPLICATE` / `CODE_INVALID_FORMAT` |
| VR-3 | `account_type_id` | Required; must exist; must belong to either the system (`company_id IS NULL`) or the requesting company; must not be `deleted_at IS NOT NULL` | `ACCOUNT_TYPE_INVALID` |
| VR-4 | `parent_id` | If provided: must exist, must belong to the same `company_id`, must not be a descendant of the account itself (no cycles), and its resolved `nature` must equal the new account's `nature` (BR-3) | `PARENT_NATURE_MISMATCH` / `PARENT_CYCLE_DETECTED` |
| VR-5 | `currency_code` | If provided: must be a valid ISO 4217 code present in the platform's currency reference table | `CURRENCY_INVALID` |
| VR-6 | `opening_balance` | Numeric, up to 4 decimal places, within ±10^15; only settable through the opening-balance workflow endpoint, rejected with `403 FIELD_LOCKED` if sent to the general update endpoint after books-open date has passed | `OPENING_BALANCE_LOCKED` |
| VR-7 | `status` | One of `active`, `inactive`, `archived`; transition to `archived`/soft-delete blocked if the account has children or postings (BR-7) | `INVALID_STATUS_TRANSITION` |
| VR-8 | `default_cost_center_id`, `default_department_id`, `branch_id` | If provided: must exist, must belong to the same `company_id`, must not be soft-deleted | `DIMENSION_INVALID` |
| VR-9 | `tax_category` | Must be one of the platform's fixed enum values (see section 9); free text is rejected to keep the Tax module's mapping deterministic | `TAX_CATEGORY_INVALID` |
| VR-10 | `tags` | JSON array of strings, max 20 tags, each 1–40 chars, no duplicate tags (case-insensitive) | `TAGS_INVALID` |
| VR-11 | `allow_posting` | Cannot be set to `false` if the account already has any `journal_lines` (would silently freeze future postings against a legitimately used account without the explicit deactivate workflow) — must go through `status = inactive` instead | `CANNOT_DISABLE_POSTING_WITH_HISTORY` |
| VR-12 | `is_control_account` | Read-only on the standard endpoint; settable only by system template seeding or an internal migration script, never via tenant-facing API | `FIELD_READONLY` |
| VR-13 | Depth | Creating a child would exceed `companies.settings.max_account_depth` (default 12) | `MAX_DEPTH_EXCEEDED` |
| VR-14 | Cross-field: `account_type_id` change (update only) | New type's `nature` must equal current `normal_balance`-derived nature (BR-2); if the account has any posted journal lines, requires `accounting.accounts.reclassify` permission, not just `accounting.accounts.update` | `RECLASSIFICATION_FORBIDDEN` |
| VR-15 | Bulk import row-level | Each imported row independently validated against VR-1 through VR-13; a failing row is skipped and reported in the import result, it does not abort the entire batch (partial success model, section 17) | `IMPORT_ROW_INVALID` |

# 12 User Workflows

## 12.1 Company Onboarding — Template Selection

```
[New company created] 
      │
      ▼
[Owner selects Country Template] ── required, one of KW_STANDARD / SA_STANDARD / AE_STANDARD
      │
      ▼
[Owner optionally selects Industry Template] ── RETAIL / TRADING / SERVICES / MANUFACTURING / REAL_ESTATE / RESTAURANTS / none
      │
      ▼
[System clones template account_types + accounts into company_id] ── transactional, all-or-nothing
      │
      ▼
[System seeds mandatory control accounts + Retained Earnings + Current Year Net Profit] ── BR-14 precondition satisfied
      │
      ▼
[Owner reviews generated tree, optionally renames/reorders] ── non-destructive; codes/structure preserved
      │
      ▼
[Owner enters opening balances] ── section 12.2
      │
      ▼
[Company ready to post first transaction]
```

## 12.2 Opening Balance Entry

```
[Owner/Accountant opens "Opening Balances" screen]
      │
      ▼
[System lists all posting-enabled accounts with a blank/zero opening balance input]
      │
      ▼
[User enters balances per account] ── signed in natural terms per BR's opening-balance semantics
      │
      ▼
[System validates running balance] ── SUM(debit-side entries) - SUM(credit-side entries) must reconcile
      to zero against a system "Opening Balance Equity" clearing line the system inserts automatically
      │
      ├─ if unbalanced → block save, show variance amount, do not partially commit
      │
      ▼
[User confirms] → [System creates one journal_entries row, status=posted, dated books-open date,
                    with one journal_line per account + one balancing line on Opening Balance Equity]
      │
      ▼
[accounts.opening_balance cache columns updated by trigger]
```

## 12.3 Manual Account Creation

```
[User clicks "New Account"] 
      │
      ▼
[User selects parent (optional) or a nature+group] 
      │
      ▼
[Form pre-fills: account_type_id options filtered to selected nature; suggested next code shown]
      │
      ▼
[AI duplicate-detection runs on name_en+name_ar as user types] ── section 16.2 — inline warning,
      never blocking
      │
      ▼
[User fills name_ar, name_en, description, currency, tax_category, dimensions, tags]
      │
      ▼
[Submit] → [FormRequest validation, section 11] 
      │
      ├─ fail → 422 with field errors
      │
      ▼
[Service layer: code generation if omitted, depth/path computed, normal_balance derived from type]
      │
      ▼
[Repository: INSERT inside transaction, parent row locked FOR UPDATE during code allocation]
      │
      ▼
[audit_logs row written: action=created, new values snapshot]
      │
      ▼
[201 Created, account returned]
```

## 12.4 Reclassification (changing an account's type after it has history)

```
[User selects account with posted journal_lines, requests type change]
      │
      ▼
[System checks permission accounting.accounts.reclassify] ── distinct from .update, since this
      changes the meaning of historical financial statements
      │
      ▼
[System validates same-nature constraint (BR-2)] ── cross-nature rejected outright, no override
      │
      ▼
[System shows impact preview]: "This will move X posted entries totaling Y (base currency) from
      [old type] to [new type] on all historical reports going forward. Prior-period statements
      already generated as PDF/exported snapshots are NOT retroactively altered."
      │
      ▼
[User confirms with mandatory reason text]
      │
      ▼
[System updates accounts.account_type_id, recalculates normal_balance if the new type's default
      differs, writes audit_logs with full before/after + reason + actor]
      │
      ▼
[Domain event accounting.account.reclassified emitted] ── consumed by Reports module to invalidate
      any cached financial-statement materializations touching this account
```

## 12.5 Merge Duplicate Accounts

```
[User or AI suggestion identifies two accounts as duplicates]
      │
      ▼
[User opens "Merge Accounts" — selects survivor and loser]
      │
      ▼
[System validates BR-11: same nature, same currency restriction]
      │
      ├─ fail → 422 MERGE_INCOMPATIBLE_ACCOUNTS
      │
      ▼
[System shows preview: N journal_lines will be reassigned, opening balances will be summed,
      loser's children (if any) will be reparented to survivor]
      │
      ▼
[User confirms, mandatory reason]
      │
      ▼
[Transaction: UPDATE journal_lines SET account_id = survivor WHERE account_id = loser;
             UPDATE accounts SET parent_id = survivor WHERE parent_id = loser;
             UPDATE accounts SET opening_balance = survivor.opening_balance + loser.opening_balance,
                                  deleted_at = now(), status = 'archived' WHERE id = loser]
      │
      ▼
[audit_logs: one row for the merge action referencing both account IDs, full line-count/amount summary]
      │
      ▼
[Domain event accounting.account.merged emitted]
```

## 12.6 Deactivation vs. Deletion Decision Workflow

```
[User attempts to remove an account]
      │
      ▼
[System checks: any journal_lines referencing it (posted or draft)?]
      │
      ├─ yes → [Deletion blocked] → offer "Set to Inactive" instead (status='inactive';
      │         account hidden from pickers, remains on historical reports)
      │
      └─ no  → [System checks: any child accounts?]
                   │
                   ├─ yes → [Deletion blocked] → "Reassign or remove children first"
                   │
                   └─ no  → [Soft delete permitted] → deleted_at = now(), code permanently reserved (BR-8)
```

# 13 Database Schema

## 13.1 Enums and Types

```sql
-- Account nature: the seven immutable top-level classifications
CREATE TYPE account_nature AS ENUM (
    'asset', 'liability', 'equity', 'revenue', 'expense', 'other_income', 'other_expense'
);

-- Normal balance side
CREATE TYPE balance_side AS ENUM ('debit', 'credit');

-- Lifecycle status of an account
CREATE TYPE account_status AS ENUM ('active', 'inactive', 'archived');

-- Tax treatment tag consumed by the Tax module
CREATE TYPE tax_category_enum AS ENUM (
    'none',
    'vat_standard_output', 'vat_standard_input',
    'vat_zero_rated', 'vat_exempt', 'vat_out_of_scope',
    'zakat_base_asset', 'zakat_excluded',
    'non_deductible_expense', 'withholding_applicable'
);
```

## 13.2 `account_types`

```sql
CREATE TABLE account_types (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NULL REFERENCES companies(id),  -- NULL = system-defined, shared
    nature              account_nature NOT NULL,
    code                VARCHAR(50) NOT NULL,                  -- e.g. 'bank', 'accounts_receivable'
    name_en             VARCHAR(150) NOT NULL,
    name_ar             VARCHAR(150) NOT NULL,
    description         TEXT NULL,
    default_normal_balance balance_side NOT NULL,
    is_contra           BOOLEAN NOT NULL DEFAULT FALSE,        -- BR-9: normal balance opposite of nature default
    is_control_account  BOOLEAN NOT NULL DEFAULT FALSE,        -- true = drives a sub-ledger (AR, AP, Inventory, Payroll Payable)
    control_of          VARCHAR(50) NULL,                      -- 'customers' | 'vendors' | 'inventory_items' | 'payroll_items' | 'tax_transactions' | NULL
    statement_section    VARCHAR(100) NULL,                     -- e.g. 'current_assets', 'operating_expenses' — drives report_definitions grouping
    is_system           BOOLEAN NOT NULL DEFAULT FALSE,        -- seeded/template row, cannot be edited or deleted by tenant
    is_template         BOOLEAN NOT NULL DEFAULT FALSE,        -- true for the master template rows (company_id IS NULL, cloned on onboarding)
    sort_order          INTEGER NOT NULL DEFAULT 0,
    created_by          BIGINT NULL REFERENCES users(id),
    updated_by          BIGINT NULL REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,

    CONSTRAINT chk_account_types_contra_balance CHECK (
        (is_contra = FALSE) OR (default_normal_balance IS NOT NULL)
    )
);

-- A type's code must be unique within its owning scope (system-wide when company_id IS NULL, else per company)
CREATE UNIQUE INDEX uq_account_types_company_code
    ON account_types (COALESCE(company_id, 0), code)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_account_types_company_id ON account_types (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_account_types_nature ON account_types (nature);
CREATE INDEX idx_account_types_control_of ON account_types (control_of) WHERE control_of IS NOT NULL;
```

## 13.3 `accounts`

```sql
CREATE TABLE accounts (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id              BIGINT NOT NULL REFERENCES companies(id),
    branch_id               BIGINT NULL REFERENCES branches(id),

    code                    VARCHAR(20) NOT NULL,
    name_en                 VARCHAR(255) NOT NULL,
    name_ar                 VARCHAR(255) NOT NULL,
    description             TEXT NULL,

    account_type_id         BIGINT NOT NULL REFERENCES account_types(id),
    nature                  account_nature NOT NULL,           -- denormalized from account_types for fast filtering; kept in sync by trigger
    normal_balance          balance_side NOT NULL,              -- denormalized; derived at write time, mutable only via reclassification workflow

    parent_id               BIGINT NULL REFERENCES accounts(id),
    depth                   INTEGER NOT NULL DEFAULT 0,         -- trigger-maintained, 0 = root
    path                    VARCHAR(1000) NOT NULL DEFAULT '',  -- trigger-maintained materialized ancestor path of ids, e.g. '1.11.1101'
    sort_order               INTEGER NOT NULL DEFAULT 0,
    group_name              VARCHAR(150) NULL,                 -- free-text cross-cutting grouping label, independent of tree position

    allow_posting           BOOLEAN NOT NULL DEFAULT TRUE,
    is_control_account      BOOLEAN NOT NULL DEFAULT FALSE,     -- denormalized from account_types.is_control_account at creation
    is_system                BOOLEAN NOT NULL DEFAULT FALSE,     -- cloned from a template; tenant may deactivate, never hard-delete

    currency_code            CHAR(3) NULL,                       -- NULL = free-currency
    opening_balance          NUMERIC(19,4) NOT NULL DEFAULT 0,   -- cache column, see BR-6; signed in natural (normal-balance) terms

    status                   account_status NOT NULL DEFAULT 'active',

    default_cost_center_id   BIGINT NULL REFERENCES cost_centers(id),
    default_department_id    BIGINT NULL REFERENCES departments(id),

    tax_category              tax_category_enum NOT NULL DEFAULT 'none',
    ai_suggested_type_id       BIGINT NULL REFERENCES account_types(id),
    ai_suggestion_confidence   NUMERIC(4,3) NULL,                 -- 0.000–1.000
    ai_suggestion_reasoning    TEXT NULL,
    ai_suggested_at             TIMESTAMPTZ NULL,

    tags                       JSONB NOT NULL DEFAULT '[]'::jsonb,
    custom_fields               JSONB NOT NULL DEFAULT '{}'::jsonb,

    created_by                 BIGINT NULL REFERENCES users(id),
    updated_by                 BIGINT NULL REFERENCES users(id),
    created_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                 TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                 TIMESTAMPTZ NULL,

    CONSTRAINT chk_accounts_no_self_parent CHECK (parent_id IS NULL OR parent_id <> id),
    CONSTRAINT chk_accounts_depth_nonneg CHECK (depth >= 0),
    CONSTRAINT chk_accounts_currency_format CHECK (currency_code IS NULL OR currency_code ~ '^[A-Z]{3}$'),
    CONSTRAINT chk_accounts_ai_confidence CHECK (
        ai_suggestion_confidence IS NULL OR (ai_suggestion_confidence >= 0 AND ai_suggestion_confidence <= 1)
    )
);

-- Code must be unique per company among non-deleted accounts (BR-8 keeps deleted codes reserved
-- by simply NOT excluding deleted rows from a secondary reservation index — see uq below)
CREATE UNIQUE INDEX uq_accounts_company_code_active
    ON accounts (company_id, code)
    WHERE deleted_at IS NULL;

-- Reservation index: prevents a NEW account (active) from reusing a code that ANY account
-- (including soft-deleted) in the company ever held — enforces BR-8 at the DB layer.
CREATE UNIQUE INDEX uq_accounts_company_code_reserved
    ON accounts (company_id, code);

CREATE INDEX idx_accounts_company_id ON accounts (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_parent_id ON accounts (parent_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_account_type_id ON accounts (account_type_id);
CREATE INDEX idx_accounts_nature ON accounts (company_id, nature) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_status ON accounts (company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_branch_id ON accounts (branch_id) WHERE branch_id IS NOT NULL;
CREATE INDEX idx_accounts_path ON accounts USING BTREE (path) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_is_control_account ON accounts (company_id) WHERE is_control_account = TRUE AND deleted_at IS NULL;
CREATE INDEX idx_accounts_tags_gin ON accounts USING GIN (tags);
CREATE INDEX idx_accounts_custom_fields_gin ON accounts USING GIN (custom_fields);

-- Bilingual fuzzy search support
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX idx_accounts_name_en_trgm ON accounts USING GIN (name_en gin_trgm_ops) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_name_ar_trgm ON accounts USING GIN (name_ar gin_trgm_ops) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_code_trgm ON accounts USING GIN (code gin_trgm_ops) WHERE deleted_at IS NULL;
```

## 13.4 Trigger — depth/path maintenance and cycle prevention

```sql
CREATE OR REPLACE FUNCTION fn_accounts_maintain_tree()
RETURNS TRIGGER AS $$
DECLARE
    parent_depth   INTEGER;
    parent_path    VARCHAR(1000);
    parent_company BIGINT;
    parent_nature  account_nature;
    ancestor_id    BIGINT;
    hops           INTEGER := 0;
BEGIN
    IF NEW.parent_id IS NULL THEN
        NEW.depth := 0;
        NEW.path  := NEW.id::text;
    ELSE
        SELECT depth, path, company_id, nature
          INTO parent_depth, parent_path, parent_company, parent_nature
          FROM accounts WHERE id = NEW.parent_id FOR UPDATE;

        IF parent_company IS DISTINCT FROM NEW.company_id THEN
            RAISE EXCEPTION 'PARENT_COMPANY_MISMATCH: parent belongs to a different company';
        END IF;

        IF parent_nature IS DISTINCT FROM NEW.nature THEN
            RAISE EXCEPTION 'PARENT_NATURE_MISMATCH: child nature must match parent nature';
        END IF;

        -- cycle detection: walk ancestors, bounded to avoid infinite loop on corruption
        ancestor_id := NEW.parent_id;
        WHILE ancestor_id IS NOT NULL AND hops < 50 LOOP
            IF ancestor_id = NEW.id THEN
                RAISE EXCEPTION 'PARENT_CYCLE_DETECTED: account cannot be its own ancestor';
            END IF;
            SELECT parent_id INTO ancestor_id FROM accounts WHERE id = ancestor_id;
            hops := hops + 1;
        END LOOP;

        NEW.depth := parent_depth + 1;
        NEW.path  := parent_path || '.' || NEW.id::text;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_accounts_maintain_tree
    BEFORE INSERT OR UPDATE OF parent_id ON accounts
    FOR EACH ROW EXECUTE FUNCTION fn_accounts_maintain_tree();
```

## 13.5 Support view — recursive subtree helper

```sql
CREATE OR REPLACE VIEW v_account_subtree AS
WITH RECURSIVE tree AS (
    SELECT a.id, a.id AS root_id, a.parent_id, a.company_id, 0 AS relative_depth
    FROM accounts a
    WHERE a.deleted_at IS NULL
    UNION ALL
    SELECT c.id, t.root_id, c.parent_id, c.company_id, t.relative_depth + 1
    FROM accounts c
    JOIN tree t ON c.parent_id = t.id
    WHERE c.deleted_at IS NULL
)
SELECT * FROM tree;
```

## 13.6 Support table — `chart_templates` (versioned template metadata)

```sql
CREATE TABLE chart_templates (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code            VARCHAR(50) NOT NULL UNIQUE,       -- e.g. 'KW_STANDARD', 'RETAIL'
    template_type   VARCHAR(20) NOT NULL,              -- 'country' | 'industry'
    name_en         VARCHAR(150) NOT NULL,
    name_ar         VARCHAR(150) NOT NULL,
    version         VARCHAR(20) NOT NULL,              -- semver, e.g. '2.3.0'
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT chk_chart_templates_type CHECK (template_type IN ('country', 'industry'))
);

CREATE TABLE chart_template_accounts (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chart_template_id   BIGINT NOT NULL REFERENCES chart_templates(id),
    parent_template_account_id BIGINT NULL REFERENCES chart_template_accounts(id),
    code                VARCHAR(20) NOT NULL,
    name_en             VARCHAR(255) NOT NULL,
    name_ar             VARCHAR(255) NOT NULL,
    account_type_code    VARCHAR(50) NOT NULL,          -- resolved against account_types.code at clone time
    allow_posting        BOOLEAN NOT NULL DEFAULT TRUE,
    tax_category          tax_category_enum NOT NULL DEFAULT 'none',
    sort_order            INTEGER NOT NULL DEFAULT 0,

    UNIQUE (chart_template_id, code)
);

CREATE INDEX idx_chart_template_accounts_template ON chart_template_accounts (chart_template_id);
```

## 13.7 Relationships summary

| From | To | Cardinality | Notes |
|---|---|---|---|
| `accounts.company_id` | `companies.id` | N:1 | Every account belongs to exactly one company |
| `accounts.parent_id` | `accounts.id` | N:1 (self) | Unlimited depth tree, nullable root |
| `accounts.account_type_id` | `account_types.id` | N:1 | Fixes nature/default normal balance |
| `accounts.branch_id` | `branches.id` | N:1 (optional) | Visibility scoping |
| `accounts.default_cost_center_id` | `cost_centers.id` | N:1 (optional) | Dimension default |
| `accounts.default_department_id` | `departments.id` | N:1 (optional) | Dimension default |
| `account_types.company_id` | `companies.id` | N:1 (optional; NULL = system) | Tenant-extended types |
| `journal_lines.account_id` | `accounts.id` | N:1 | Owned by Journal Entries module, referenced here as a consumer relationship |
| `chart_template_accounts.chart_template_id` | `chart_templates.id` | N:1 | Template source rows cloned at onboarding |

## 13.8 Data integrity summary

- All FKs use `ON DELETE RESTRICT` implicitly (default) except self-referencing `parent_id`, which uses no cascading delete at all — deletion of a parent with children is blocked at the application layer (BR: parent cannot be deleted with children) well before any FK constraint would even be tested, since the platform never hard-deletes financial rows.
- `company_id` NOT NULL + indexed on both tables per platform-wide multi-tenancy convention.
- Every write path funnels through the Laravel Service layer, which is solely responsible for keeping `nature`/`normal_balance` denormalized copies in sync with `account_types`; a periodic consistency job (`accounts:verify-denormalization`) runs nightly and raises a P2 alert (section 21) if any drift is found.

# 14 API Design

All endpoints live under `/api/v1/accounting/accounts` (plus a sibling `/api/v1/accounting/account-types`), require a Sanctum/JWT bearer token, are scoped to the active company via the `X-Company-Id` header, and return the standard response envelope (`success`, `data`, `message`, `errors`, `meta`, `request_id`, `timestamp`).

## 14.1 Endpoint Table

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/accounting/accounts` | `accounting.accounts.read` | List accounts, filterable, paginated, tree- or flat-shape |
| GET | `/api/v1/accounting/accounts/{id}` | `accounting.accounts.read` | Fetch one account with computed balance |
| GET | `/api/v1/accounting/accounts/{id}/children` | `accounting.accounts.read` | Direct children only |
| GET | `/api/v1/accounting/accounts/{id}/subtree` | `accounting.accounts.read` | Full recursive subtree |
| GET | `/api/v1/accounting/accounts/{id}/balance` | `accounting.accounts.read` | Derived balance as of a date/period, optionally by dimension |
| GET | `/api/v1/accounting/accounts/{id}/ledger` | `accounting.accounts.read` | Posted journal_lines against this account, paginated |
| GET | `/api/v1/accounting/accounts/{id}/audit-history` | `accounting.accounts.read` | Full audit_logs trail for this account |
| POST | `/api/v1/accounting/accounts` | `accounting.accounts.create` | Create a new account |
| POST | `/api/v1/accounting/accounts/bulk` | `accounting.accounts.create` | Create multiple accounts in one transaction |
| PATCH | `/api/v1/accounting/accounts/{id}` | `accounting.accounts.update` | Update non-locked fields |
| POST | `/api/v1/accounting/accounts/{id}/reclassify` | `accounting.accounts.reclassify` | Change account_type_id with impact preview + reason |
| POST | `/api/v1/accounting/accounts/{id}/deactivate` | `accounting.accounts.update` | Set status = inactive |
| POST | `/api/v1/accounting/accounts/{id}/reactivate` | `accounting.accounts.update` | Set status = active |
| DELETE | `/api/v1/accounting/accounts/{id}` | `accounting.accounts.delete` | Soft-delete (only if zero postings and zero children) |
| POST | `/api/v1/accounting/accounts/merge` | `accounting.accounts.merge` | Merge loser into survivor |
| POST | `/api/v1/accounting/accounts/opening-balances` | `accounting.accounts.set_opening_balance` | Bulk opening-balance entry, creates the opening journal entry |
| GET | `/api/v1/accounting/accounts/templates` | `accounting.accounts.read` | List available country/industry templates |
| POST | `/api/v1/accounting/accounts/apply-template` | `accounting.accounts.create` | Clone a template into the active company |
| POST | `/api/v1/accounting/accounts/import` | `accounting.accounts.import` | Import from Excel/CSV/ERP export, section 17 |
| GET | `/api/v1/accounting/accounts/export` | `accounting.accounts.export` | Export current CoA, section 18 |
| GET | `/api/v1/accounting/account-types` | `accounting.accounts.read` | List account types (system + company-extended) |
| POST | `/api/v1/accounting/account-types` | `accounting.accounts.manage_types` | Create a company-specific account type |
| GET | `/api/v1/accounting/accounts/ai-suggestions` | `accounting.accounts.read` | Pending AI suggestions (duplicates, reclassifications, missing accounts) |
| POST | `/api/v1/accounting/accounts/ai-suggestions/{id}/accept` | `accounting.accounts.update` | Apply an AI suggestion through the normal validated path |
| POST | `/api/v1/accounting/accounts/ai-suggestions/{id}/reject` | `accounting.accounts.update` | Dismiss an AI suggestion, feeds the learning loop (section 16.7) |

## 14.2 Request/Response Examples

**Create account — request**

```json
POST /api/v1/accounting/accounts
X-Company-Id: 4821
{
  "parent_id": 1042,
  "account_type_id": 118,
  "name_en": "Bank Accounts – NBK Current",
  "name_ar": "حسابات بنكية – البنك الوطني الجاري",
  "currency_code": "KWD",
  "allow_posting": true,
  "tax_category": "none",
  "default_cost_center_id": null,
  "tags": ["operational-bank"]
}
```

**Create account — response (201)**

```json
{
  "success": true,
  "data": {
    "id": 55231,
    "company_id": 4821,
    "code": "1101.003",
    "name_en": "Bank Accounts – NBK Current",
    "name_ar": "حسابات بنكية – البنك الوطني الجاري",
    "description": null,
    "account_type_id": 118,
    "account_type": { "code": "bank", "name_en": "Bank", "name_ar": "بنك" },
    "nature": "asset",
    "normal_balance": "debit",
    "parent_id": 1042,
    "depth": 3,
    "path": "1.11.1101.55231",
    "allow_posting": true,
    "is_control_account": false,
    "is_system": false,
    "currency_code": "KWD",
    "opening_balance": "0.0000",
    "status": "active",
    "tax_category": "none",
    "tags": ["operational-bank"],
    "created_by": 902,
    "created_at": "2026-07-16T09:12:41Z",
    "updated_at": "2026-07-16T09:12:41Z"
  },
  "message": "Account created successfully",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "8f2c1e40-2a11-4d3a-9c77-1b6e4d8a9f01",
  "timestamp": "2026-07-16T09:12:41Z"
}
```

**Create account — validation error (422)**

```json
{
  "success": false,
  "data": null,
  "message": "The given data was invalid.",
  "errors": [
    { "field": "parent_id", "code": "PARENT_NATURE_MISMATCH",
      "message": "Child account nature (asset) must match parent account nature (liability)." },
    { "field": "name_ar", "code": "NAME_REQUIRED",
      "message": "The Arabic name field is required." }
  ],
  "meta": { "pagination": null },
  "request_id": "3a71c9d2-88fd-4e2b-9d4a-6c112a55e6f4",
  "timestamp": "2026-07-16T09:13:02Z"
}
```

**List accounts (tree shape) — request**

```
GET /api/v1/accounting/accounts?shape=tree&status=active&nature=asset&search=bank
X-Company-Id: 4821
```

**List accounts — response (200)**

```json
{
  "success": true,
  "data": [
    {
      "id": 1101, "code": "1101", "name_en": "Bank Accounts", "name_ar": "حسابات بنكية",
      "allow_posting": false, "nature": "asset", "children": [
        { "id": 55229, "code": "1101.001", "name_en": "Bank Accounts – Boubyan Current",
          "name_ar": "حسابات بنكية – بنك بوبيان الجاري", "allow_posting": true, "children": [] },
        { "id": 55231, "code": "1101.003", "name_en": "Bank Accounts – NBK Current",
          "name_ar": "حسابات بنكية – البنك الوطني الجاري", "allow_posting": true, "children": [] }
      ]
    }
  ],
  "message": "OK",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "1c9e2b30-77af-4a10-8e2b-fa9c31d0e711",
  "timestamp": "2026-07-16T09:14:10Z"
}
```

**Reclassify account — request/response**

```json
POST /api/v1/accounting/accounts/55231/reclassify
{
  "new_account_type_id": 121,
  "reason": "This account was originally typed as a generic Current Asset; it is a Bank account and should follow bank reconciliation workflows."
}
```

```json
{
  "success": true,
  "data": {
    "id": 55231,
    "account_type_id": 121,
    "previous_account_type_id": 118,
    "normal_balance": "debit",
    "affected_journal_lines": 342,
    "affected_amount_base_currency": "184230.5000"
  },
  "message": "Account reclassified successfully",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9b5e21aa-4f88-49c1-8b39-2d114cf0a220",
  "timestamp": "2026-07-16T09:16:44Z"
}
```

**Merge accounts — request/response**

```json
POST /api/v1/accounting/accounts/merge
{ "survivor_id": 55231, "loser_id": 55229, "reason": "Both point to the same closed Boubyan account; NBK current account is the active successor." }
```

```json
{
  "success": true,
  "data": { "survivor_id": 55231, "loser_id": 55229, "journal_lines_reassigned": 118,
             "children_reparented": 0, "combined_opening_balance": "12500.0000" },
  "message": "Accounts merged successfully",
  "errors": [], "meta": { "pagination": null },
  "request_id": "6cf3a012-1d6e-4b90-9a71-3e0f2b8d44a3",
  "timestamp": "2026-07-16T09:18:03Z"
}
```

**Error responses — reference table**

| HTTP | Error code | Meaning |
|---|---|---|
| 400 | `MALFORMED_REQUEST` | Body is not valid JSON or missing required top-level keys |
| 401 | `UNAUTHENTICATED` | Missing/expired bearer token |
| 403 | `PERMISSION_DENIED` | Authenticated but lacks the endpoint's required permission key |
| 404 | `ACCOUNT_NOT_FOUND` | `{id}` does not exist or does not belong to the active company |
| 409 | `ACCOUNT_HAS_CHILDREN` | Deletion attempted on a parent with active children |
| 409 | `CODE_ALREADY_RESERVED` | Concurrent creation raced for the same generated code (client should retry) |
| 422 | (various, section 11) | Field-level validation failure |
| 429 | `RATE_LIMITED` | Import/export endpoints throttled per company |
| 500 | `INTERNAL_ERROR` | Unexpected server fault; `request_id` should be included in support tickets |

# 15 Permissions

Permission keys follow the platform convention `<area>.<entity>.<action>`. All are default-deny; a role must be explicitly granted each key.

| Permission key | Description |
|---|---|
| `accounting.accounts.read` | View the CoA, individual accounts, balances, ledgers |
| `accounting.accounts.create` | Create new accounts manually or via template/import |
| `accounting.accounts.update` | Edit non-locked fields (name, description, tags, dimensions, status) |
| `accounting.accounts.reclassify` | Change `account_type_id` on an account with posted history |
| `accounting.accounts.merge` | Merge two accounts |
| `accounting.accounts.delete` | Soft-delete an account with zero history and zero children |
| `accounting.accounts.set_opening_balance` | Enter/adjust opening balances (pre fiscal-year-lock only) |
| `accounting.accounts.import` | Run a CoA import job |
| `accounting.accounts.export` | Export the CoA |
| `accounting.accounts.manage_types` | Create/edit company-specific `account_types` |
| `accounting.journal.post_to_control_account` | Override BR-5 for a manual reconciling entry on a control account |
| `accounting.accounts.review_ai_suggestions` | View AI-suggested duplicates/reclassifications/missing accounts |

## 15.1 Role Matrix

| Permission key | Owner | Admin | Accountant | Auditor | Employee | AI Agent |
|---|---|---|---|---|---|---|
| `accounting.accounts.read` | ✅ | ✅ | ✅ | ✅ | ➖ (own dept only, via scoping) | ✅ (read-only) |
| `accounting.accounts.create` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ (suggest-only) |
| `accounting.accounts.update` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ (suggest-only) |
| `accounting.accounts.reclassify` | ✅ | ✅ | ➖ (requires secondary approval) | ❌ | ❌ | ❌ (suggest-only) |
| `accounting.accounts.merge` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ (suggest-only) |
| `accounting.accounts.delete` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.accounts.set_opening_balance` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `accounting.accounts.import` | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `accounting.accounts.export` | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| `accounting.accounts.manage_types` | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| `accounting.journal.post_to_control_account` | ✅ | ➖ (approval-gated) | ➖ (approval-gated) | ❌ | ❌ | ❌ |
| `accounting.accounts.review_ai_suggestions` | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ (produces, does not approve) |

Legend: ✅ granted by default · ❌ never granted · ➖ conditionally granted / requires an approval step even when the role technically holds the key. The **AI Agent** row is not a human role — it is the service-account identity the FastAPI layer authenticates as when calling the Laravel API; it is deliberately capped at read + suggestion-producing endpoints and can never reach `create`, `update`, `reclassify`, `merge`, or `delete`, per the platform-wide rule that AI never writes to the database directly and never bypasses authorization.

# 16 AI Responsibilities

The FastAPI AI layer runs a set of specialized responsibilities against the Chart of Accounts, primarily surfaced through the **General Accountant** and **Auditor** agents, with **Fraud Detection** and **Compliance Agent** contributing risk-flagging signals. Every responsibility below produces a row in an `ai_suggestions`-shaped payload (transported via `ai_conversations`/`ai_messages` or a dedicated suggestions feed) carrying `confidence` (0–1), `reasoning` (natural language), `supporting_documents` (references to the transactions/accounts that triggered the suggestion), and an explicit `autonomy_level`.

| Responsibility | Agent | Inputs | Outputs | Autonomy | Confidence handling |
|---|---|---|---|---|---|
| Automatic Classification | General Accountant | New account's `name_en`/`name_ar`/`description`, company industry, historical account-naming patterns across similar companies (anonymized, cross-tenant model features only — never cross-tenant data access) | Suggested `account_type_id` + `tax_category` | Suggest-only | <0.6 shown as a low-confidence hint in a collapsed panel; 0.6–0.85 shown as a pre-filled but editable default; >0.85 shown as a one-click "Apply" suggestion, still requires the click |
| Duplicate Detection | General Accountant | Levenshtein distance + embedding cosine similarity between the new/edited account's bilingual names and existing accounts of the same nature within the company | Duplicate warning with the matched account(s) and similarity score | Suggest-only, inline at creation time, non-blocking | Threshold ≥0.85 similarity triggers an inline warning; ≥0.95 additionally suggests "Merge instead?" |
| Recommendations | General Accountant, CFO | Transaction history showing repeated postings to a generic/"Miscellaneous" account, industry template gaps vs. actual usage | "Create account X" or "Split account Y into X and Z" recommendations | Suggest-only, surfaced in a weekly digest + `GET .../ai-suggestions` | Confidence derived from frequency/materiality of the pattern (e.g., 40+ postings over KWD 500 to a Miscellaneous Expense account in 90 days = high confidence) |
| Risk Detection | Fraud Detection, Auditor | Postings to an account type inconsistent with its nature (e.g., large debits to a Revenue account), unusually large manual entries to control accounts, dormant-then-suddenly-active accounts | Risk flag with severity + explanation, queued for Auditor review | Suggest-only, never auto-blocks a transaction (blocking is a Journal Entries module concern, not this module's) | Flags always shown regardless of confidence for anything above a materiality threshold (default company-configurable, e.g. 1% of monthly revenue) |
| Account Suggestions | General Accountant | Onboarding conversation ("what does your company sell/buy"), detected but uncreated common accounts for the selected industry template | Batch of "accounts you may be missing" at onboarding and periodically thereafter | Suggest-only, batchable one-click-accept-all | Template-gap-derived; confidence is template-coverage-percentage based |
| Mapping | Document AI, OCR Agent | Uploaded bank statements, vendor invoices, and imported ERP charts (section 17) | Proposed account for each unmapped line item / imported row | Suggest-only within Import workflow; the import review screen is the approval gate | Row-level confidence; rows below 0.5 are flagged "needs manual mapping" rather than guessed |
| Learning | General Accountant (feedback loop) | Every `accept`/`reject` action on an AI suggestion via `POST .../ai-suggestions/{id}/accept|reject` | Updated per-company weighting of classification features; no cross-tenant leakage of accepted/rejected labels — only aggregated, anonymized feature statistics feed the shared base model | N/A — this is a background training signal, not a user-facing action | Rejected suggestions with a user-entered reason are given the highest training weight, since they carry explicit human correction signal |

**Hard boundaries (restated for this module):** the AI layer never calls the database directly; every suggestion acceptance is executed by calling the exact same `POST/PATCH/DELETE` endpoints in section 14 under the acting human's own permissions (or, when a policy explicitly allows fully-automated low-risk actions such as tagging, under the capped AI Agent service identity in section 15.1) — it never escalates its own privilege. AI suggestions are always visible with their reasoning and are never silently applied; `accounting.accounts.reclassify` and `.merge` in particular always require a human `reason` field even when the human is simply clicking "Accept" on an AI proposal, which pre-fills the AI's reasoning text into that field for the human to edit or confirm.

# 17 Import

CoA import is a **partial-success, row-level-validated, mapping-review** pipeline: `POST /api/v1/accounting/accounts/import` accepts a file (or a prior export from another system) plus a `source_system` hint, parses it into a staging table, runs AI-assisted column/value mapping, presents a review screen, then commits only the rows the user confirms.

## 17.1 Pipeline

```
[Upload file] → [Detect format: xlsx/csv/QBO export/SAP export/Oracle export/Odoo export/Zoho export]
      │
      ▼
[Parse into staging rows] → [AI Mapping Agent proposes: target field per source column,
      target account_type_id per source type/category string, target tax_category per source tax code]
      │
      ▼
[Review screen: user confirms/edits mappings; rows below AI confidence 0.5 flagged for manual mapping]
      │
      ▼
[Dry-run validation: every VR-1..VR-13 rule applied per row, WITHOUT writing]
      │
      ▼
[Result summary: N rows will succeed, M rows have errors listed per row/field]
      │
      ▼
[User confirms commit] → [Transactional batch insert of valid rows; invalid rows skipped, listed in
      the final import report with reasons, never silently dropped]
      │
      ▼
[audit_logs: one row per imported account, action=imported, source_system tagged]
```

## 17.2 Excel / CSV

Expected columns (header names are matched case-insensitively and fuzzy-matched by the AI Mapping Agent, so exact naming is not required): `code`, `name_en`, `name_ar` (at least one of `name_en`/`name_ar` required per row, the other AI-translated as a suggestion the user must confirm before commit — QAYD never silently machine-translates a legal account name into a book of record without review), `parent_code`, `type` (free text, mapped to `account_type_id`), `currency`, `opening_balance`, `tax_category`.

## 17.3 ERP Migration — Per-System Mapping Strategy

| Source system | Source concept | Sample column map | Notes |
|---|---|---|---|
| **QuickBooks** (Online/Desktop export) | Chart of Accounts export (`.csv`/`.iif`) | `Account`→`code`+`name_en`, `Type`→`account_type_id` (QB's ~15 built-in types map many-to-one onto QAYD's finer `account_types`, e.g., QB "Bank" and "Other Current Asset" both land under Asset but different types), `Detail Type`→refines the mapping, `Balance`→`opening_balance` | QuickBooks has no native Arabic-name field; `name_ar` is AI-suggested (translation) and requires human confirmation per row |
| **SAP** (S/4HANA / ECC, GL account master export) | `SKA1`/`SKB1` tables export | `Saknr` (GL account number)→`code`, `Txt50`/`Txt20`→`name_en`, `Ktoks` (account group)→`account_type_id` via a lookup table shipped with the import module, `Bilkt` (balance sheet indicator)→distinguishes Balance Sheet (Asset/Liability/Equity) vs. P&L (Revenue/Expense) accounts | SAP's account groups are company-configurable text codes; the AI Mapping Agent is pre-trained on the ~40 most common German/Gulf-market SAP account-group naming conventions |
| **Oracle** (Fusion/EBS Chart of Accounts) | Oracle's segmented COA (Natural Account segment) | The Natural Account segment value→`code`, segment description→`name_en`, Oracle account "type" (Asset/Liability/Owners Equity/Revenue/Expense) → direct `nature` mapping (Oracle already uses a 5-nature model; QAYD's Other Income/Other Expense are inferred from a secondary "non-operating" flag if present, else defaulted to Revenue/Expense) | Oracle's segmented structure often encodes cost center/department INTO the account code itself; the import wizard offers a "split segments into dimensions" mode so cost-center segments become `default_cost_center_id` rather than polluting `accounts.code` |
| **Odoo** | `account.account` model export | `code`→`code`, `name`→`name_en` (+ `name_ar` if the Odoo instance has Arabic localization active, in which case it maps directly, no AI translation needed), `account_type` (Odoo's own type key, e.g. `asset_current`, `liability_payable`)→direct lookup table to QAYD `account_types.code` since Odoo's type taxonomy is close enough to QAYD's to be a near 1:1 static map | Odoo's `reconcile` boolean maps to `is_control_account` heuristically (true + linked to `res.partner` reconciliation typically means AR/AP) |
| **Zoho Books** | Chart of Accounts export (`.csv`) | `Account Name`→`name_en`, `Account Code`→`code`, `Account Type` (Zoho's ~20 types)→lookup table, `Currency`→`currency_code` | Zoho supports Arabic UI but its CoA export is English-only in practice; same AI-translation-with-confirmation path as QuickBooks |

## 17.4 Validation During Import

Every staged row passes through the identical `FormRequest` validation rules as manual creation (section 11), plus two import-specific rules: **IR-1** parent resolution — `parent_code` in the file must resolve to either another row in the same import batch (resolved in two passes: create/locate all rows first without parents, then wire `parent_id` in a second pass) or an existing account in the company; **IR-2** duplicate-within-batch — two staged rows cannot share the same `code` within one import, reported as a batch-level error before any row is committed.

# 18 Export

`GET /api/v1/accounting/accounts/export?format=xlsx|csv|pdf|json` streams the current (non-deleted) Chart of Accounts, respecting the requesting user's `accounting.accounts.read`/`.export` permissions and any branch-scoping on their role.

| Format | Contents | Notes |
|---|---|---|
| **Excel (.xlsx)** | One row per account: code, name_en, name_ar, type, nature, normal_balance, currency, opening_balance, status, parent_code, tax_category, tags — plus a summary sheet with per-nature counts and total accounts | Column headers rendered bilingually (EN above, AR below) in the same cell using a line break, matching the platform's bilingual master-data convention |
| **CSV** | Same columns as Excel, UTF-8 with BOM (for correct Arabic rendering in Excel on Windows) | Round-trips directly into the Import pipeline (section 17.2) for company-to-company cloning or backup/restore |
| **PDF** | Formatted, indented tree view (RTL-mirrored automatically when the requesting user's locale is Arabic), grouped by nature with subtotals, company letterhead | Intended for statutory/audit submission, not for round-trip import |
| **API (JSON)** | Same shape as `GET /api/v1/accounting/accounts?shape=tree` but unpaginated, streamed for large trees (`Transfer-Encoding: chunked`) | Used by the mobile app and by the Import wizard's "clone from another QAYD company you own" feature |

Exports are logged in `audit_logs` (action=exported, format, row_count, actor) since a full CoA export is a data-exfiltration-sensitive operation, and large exports (>2,000 accounts) are generated asynchronously via a queued job with a webhook (`accounting.account_export.completed`) rather than held open on the HTTP request.

# 19 Reports

The Chart of Accounts module does not generate financial statements itself (that is a `report_definitions`/`report_runs` responsibility consuming posted `journal_lines`), but it is the structural backbone every accounting report depends on, and it ships three reports of its own:

1. **Chart of Accounts Listing** — the full tree (or a filtered subtree) with code, bilingual name, type, status, and current balance as of a chosen date; exportable in all section 18 formats; the default landing report when opening the Accounting → Chart of Accounts screen.
2. **Account Usage Report** — for each posting account, count of journal lines, total debit/credit volume, and last-posted date over a chosen period; used to identify dormant accounts (candidates for deactivation) and over-used generic accounts (candidates for the AI's "split this account" recommendation, section 16).
3. **Reclassification & Merge History Report** — every reclassify/merge event in the period with before/after account, actor, reason, and affected line/amount counts; primarily an audit and external-auditor deliverable, sourced directly from `audit_logs` filtered to `entity_type = 'account'` and `action IN ('reclassified','merged')`.

All three respect the platform's dimensional filters (branch, cost center, department, project) since `journal_lines` — not `accounts` — carry those dimensions; the CoA report layer joins through to `journal_lines` only for the Usage report, and is a pure `accounts`-table query for the Listing report.

# 20 Audit Logs

Every mutating action on `accounts` and `account_types` writes exactly one row to the foundation `audit_logs` table, following the platform-wide audit contract: who, when, old value, new value, reason, IP, device.

| Column | Content for this module |
|---|---|
| `entity_type` | `'account'` or `'account_type'` |
| `entity_id` | the affected row's `id` |
| `company_id` | the acting company, always populated, always matches the row's own `company_id` |
| `action` | `created`, `updated`, `reclassified`, `merged`, `deactivated`, `reactivated`, `deleted`, `imported`, `exported`, `opening_balance_set` |
| `actor_type` | `'user'` or `'ai_agent'` — AI-originated *suggestions* are never logged as the acting entity for a write, since AI never writes directly (section 16); when a human accepts an AI suggestion, `actor_type = 'user'` and a separate `ai_suggestion_id` reference is stored in `metadata` |
| `old_values` | JSONB snapshot of changed fields before the action (full row snapshot for `created`; diff-only for `updated`) |
| `new_values` | JSONB snapshot of changed fields after the action |
| `reason` | free text, mandatory for `reclassified`, `merged`, `deleted`, `opening_balance_set` (adjustments post-lock); optional elsewhere |
| `ip_address`, `device` | captured from the request context at the middleware layer, not re-derived per module |
| `metadata` | action-specific extras, e.g. for `merged`: `{ "survivor_id": ..., "loser_id": ..., "journal_lines_reassigned": ... }`; for `imported`: `{ "source_system": "sap", "batch_id": ..., "rows_committed": ..., "rows_skipped": ... }` |

Audit rows are immutable and themselves never deleted, updated, or included in the tenant's own soft-delete cascade — they persist even after the account itself is soft-deleted, so a fully retired account still has a complete, queryable history. `GET /api/v1/accounting/accounts/{id}/audit-history` is available even for `deleted_at IS NOT NULL` accounts to a user holding `accounting.accounts.read` plus the Auditor role, since audit access must survive the record it audits.

Retention: audit rows for financial master data (accounts, account_types) are retained for a minimum of **10 years** to satisfy Kuwait Ministry of Commerce and Industry statutory bookkeeping retention norms and to comfortably exceed typical 5-year GCC tax-authority look-back periods; they are never purged by any automated retention job, only archived to cold storage (still queryable, just slower) after 3 years of inactivity on the parent account.

# 21 Notifications

| Event | Trigger | Recipients | Channel | Severity |
|---|---|---|---|---|
| `accounting.account.created` | New account created (manual, import, or AI-accepted) | Company Admins subscribed to "Accounting changes" | In-app + digest email | Info |
| `accounting.account.reclassified` | Reclassification workflow completed | Owner, Admin, the acting Accountant's supervisor if configured | In-app, immediate | Warning (touches historical statements) |
| `accounting.account.merged` | Merge workflow completed | Owner, Admin | In-app, immediate | Warning |
| `accounting.account.control_balance_mismatch` | Nightly reconciliation job finds control account GL balance ≠ sum of open sub-ledger balances | Owner, Admin, Auditor | In-app + email, immediate | **P1 Critical** |
| `accounting.account.dormant_flagged` | Account with >180 days zero postings identified by AI Recommendations | Accountant | In-app, weekly digest | Info |
| `accounting.account.ai_duplicate_suggested` | AI duplicate-detection fires above threshold | The user currently creating/editing the account | In-app, inline (not a push notification) | Info |
| `accounting.account.risk_flag_raised` | Fraud Detection/Auditor agent flags anomalous posting pattern against an account | Owner, Auditor | In-app + email, immediate | **P1 Critical** |
| `accounting.account_export.completed` | Async large export job finishes | The requesting user | In-app + email with download link (signed URL, time-limited) | Info |
| `accounting.account.opening_balance_unbalanced` | Opening balance entry attempted but does not net to zero | The entering user | In-app, immediate, blocking | Warning |
| `accounting.denormalization_drift_detected` | Nightly consistency job (section 13.8) finds nature/normal_balance drift | Platform engineering on-call (not tenant-facing) | PagerDuty-equivalent internal alert | **P0 Internal** |

All tenant-facing notifications respect the recipient's language preference (bilingual templates, EN/AR) and are also emitted as domain events on Laravel Reverb for any subscribed real-time dashboard widget (e.g., a live "recent CoA changes" panel on the Family/Admin — analogously here, the Accounting — dashboard).

# 22 Performance

The Chart of Accounts is read far more often than it is written (typeahead account pickers on every invoice, bill, and journal line dwarf the rate of account creation), so the performance strategy optimizes aggressively for read paths while keeping writes correct and infrequent enough that stronger consistency (row locks during code generation, trigger-computed depth/path) is an acceptable cost.

- **Denormalized `nature`/`normal_balance`/`path`/`depth`** on `accounts` avoid joining to `account_types` or running a recursive CTE for the overwhelming majority of list/filter/search queries (section 13.3).
- **Trigram GIN indexes** on `name_en`, `name_ar`, and `code` back the typeahead account picker (`GET .../accounts?search=`), targeting the <150ms p95 goal in Business Goal G10; `pg_trgm` similarity search comfortably outperforms `ILIKE '%term%'` at the 5,000+ account scale targeted in G2.
- **Partial indexes** (`WHERE deleted_at IS NULL`) keep hot indexes small since soft-deleted rows accumulate over a company's lifetime but are excluded from nearly every operational query.
- **Materialized ancestor path** (`accounts.path`) turns "give me this account and all its descendants" into a single indexed prefix-range scan (`path LIKE '1.11.1101%'`) instead of a recursive CTE on every request; the recursive CTE (`v_account_subtree`, section 13.5) is reserved for administrative/bulk operations (merge cascades, template cloning) where correctness under concurrent modification matters more than raw speed.
- **Balance computation caching**: `GET .../accounts/{id}/balance` for a closed prior period is served from the `ledger_entries` materialized projection (owned by the General Ledger, not duplicated here) rather than summing raw `journal_lines` on every request; only the current open period is computed live.
- **Redis caching** of the full active-account tree per company (`accounts:tree:{company_id}`, TTL 5 minutes, invalidated immediately on any write via a cache-tag bust) backs the account picker dropdown so that opening an invoice-entry screen doesn't hit PostgreSQL on every keystroke.
- **Bulk import performance**: staged rows are validated and inserted in batches of 500 inside a single transaction per batch (not per row) to keep import of a 5,000-row SAP export under the 30-minute Business Goal G7 target; the parent-locking step in code generation (section 8.2) is skipped during bulk import in favor of a single pre-pass that allocates all codes in memory before any INSERT, since the whole import runs under one serializable transaction per company and cannot race against itself.
- **Pagination defaults** (25/page, cursor-capable) apply to the flat list endpoint; the tree-shape endpoint intentionally does not paginate mid-tree (it would produce broken subtrees) and instead caps at a configurable max-nodes-per-response with a "load more children" affordance for extremely large single branches.

# 23 Security

- **Tenant isolation** is enforced at three layers: (1) every query is scoped by `company_id` derived from the authenticated request's `X-Company-Id` + the user's verified `company_users` membership, never from a client-suppliable ID alone; (2) PostgreSQL Row-Level Security policies on `accounts` and `account_types` provide a defense-in-depth backstop even if an application-layer bug omitted the `company_id` filter; (3) the self-referencing `parent_id` trigger (section 13.4) independently re-validates same-company parentage on every insert/update, so a cross-tenant parent reference cannot slip through even via a direct, non-standard write path.

```sql
ALTER TABLE accounts ENABLE ROW LEVEL SECURITY;
CREATE POLICY accounts_tenant_isolation ON accounts
    USING (company_id = current_setting('app.current_company_id')::bigint);
```

- **Least-privilege AI service account**: the FastAPI layer authenticates to Laravel using a dedicated `ai_agent` API credential scoped to exactly the permission keys in section 15.1's AI Agent column — read and suggestion-producing endpoints only. This credential is rotated on the platform's standard secret-rotation schedule and is rejected outright by any write endpoint at the middleware layer (`accounting.accounts.create/update/reclassify/merge/delete` all explicitly exclude the `ai_agent` token type in their permission gate, not merely by omission).
- **Field-level write protection**: `opening_balance`, `is_control_account`, `is_system`, `created_by` are enforced read-only on the general update endpoint (VR-6, VR-12) at the FormRequest layer, so even a user holding `accounting.accounts.update` cannot smuggle a change to these fields through a generic PATCH body — the FormRequest strips/rejects them before the Service layer is ever invoked.
- **Audit non-repudiation**: every write captures `ip_address` and `device` fingerprint from the authenticated session, and `audit_logs` rows are append-only (no `UPDATE`/`DELETE` grants on that table for the application's database role — only `INSERT` and `SELECT`).
- **Injection safety**: all dynamic filter/search parameters (nature, status, search term, tag) are bound via parameterized queries through Laravel's Eloquent/query builder; the trigram search path never concatenates user input into raw SQL.
- **Import file safety**: uploaded CoA import files are scanned for malformed/oversized content (max 20MB, max 50,000 rows) before parsing; formula-injection payloads in Excel cells (`=cmd|...`, leading `=`/`+`/`-`/`@`) are neutralized (prefixed with a single quote) both on import parsing and on export generation, since a CoA export is frequently re-opened in Excel by a human.
- **Rate limiting**: import/export/bulk-create endpoints are limited per company (default 10 import jobs/hour, 20 exports/hour) to prevent both abuse and accidental runaway automation from a misconfigured integration.
- **PII/sensitivity note**: `accounts` rows themselves are not typically PII, but `attachments` linked to an account (e.g., a bank IBAN certificate, a fixed-asset ownership deed with a personal name) are, and inherit the foundation `attachments` table's signed-URL, time-limited access model rather than any public path.

# 24 Edge Cases

| # | Scenario | Behavior |
|---|---|---|
| 1 | Two users simultaneously create a child account under the same parent without specifying a code | The parent row's `FOR UPDATE` lock (section 8.2) serializes the two INSERTs; the second transaction waits, then re-reads the max suffix and allocates the next available code — no collision, no lost update |
| 2 | An account is reclassified, then the acting user tries to undo it by reclassifying back to the original type | Permitted as a fresh reclassification (new audit row, new "affected lines" computation) — QAYD does not offer a magic "undo" that erases the first reclassification's audit trail; the trail shows both changes plainly |
| 3 | A tenant tries to delete the system-seeded "Retained Earnings" account | Blocked — `is_system = true` accounts required by BR-14 cannot be soft-deleted, only deactivated is also blocked for this specific type via an additional service-layer guard, since a fiscal year close would otherwise have nowhere to roll net profit into |
| 4 | A company applies an industry template a second time after already customizing accounts from the first application | Template accounts are matched by `chart_template_accounts.code` against the tenant's existing `accounts.code`; already-present codes are skipped (never overwritten), only genuinely new template accounts are added — a diff-and-append, not a reset |
| 5 | An import file contains a `parent_code` that refers to a row later in the same file (forward reference) | Handled by the two-pass import algorithm (section 17.4, IR-1): pass 1 creates/locates all rows without parents, pass 2 wires every `parent_id`, so file ordering never matters |
| 6 | A merge is requested where the loser account has posted lines in a fiscal period that is already locked/closed | Permitted — merging reassigns `journal_lines.account_id` (an account pointer), it does not alter the posted amounts, dates, or the closed period's derived totals-by-nature, since both accounts share the same nature; the merge is still logged and visible in the Reclassification & Merge History report for that historical period |
| 7 | A bank account's `currency_code` is set to `USD`, but a transaction is later posted to it in `KWD` | Rejected by the Journal Entries module's cross-module validation against `accounts.currency_code`, not by this module directly — this document notes the contract: a currency-restricted account only accepts transaction-currency amounts in that exact currency |
| 8 | The AI Mapping Agent proposes an account_type for an imported row with confidence exactly at the 0.5 boundary | Treated as below-threshold ("needs manual mapping") — the boundary is inclusive-low (0.5 itself requires manual mapping) to bias toward human review at ties |
| 9 | A tenant's custom account code collides with a soft-deleted account's reserved code from years ago that the tenant has long forgotten about | The reservation index (`uq_accounts_company_code_reserved`, section 13.3) rejects the reuse with `CODE_DUPLICATE`; the error message explicitly states the code was previously used by a now-deleted account and offers the next available suffix as a suggestion |
| 10 | Depth would exceed `max_account_depth` because of an unusually deep imported ERP chart (e.g., an Oracle export with 14 segment levels flattened naively) | Import wizard's "split segments into dimensions" mode (section 17.3) is recommended and, if declined, the import proceeds but rows past the depth ceiling are rejected per-row with `MAX_DEPTH_EXCEEDED`, listed in the import report rather than silently truncated |
| 11 | An account is deactivated (`status = inactive`) while it still has a `default_cost_center_id` and users try to post using it via a saved "quick entry" template | The Journal Entries module rejects posting to any `status != 'active'` account regardless of `allow_posting`, surfacing `422 ACCOUNT_INACTIVE`; the saved quick-entry template itself is flagged for the user to update, since this module does not own quick-entry templates |
| 12 | Two companies under the same corporate group both apply `KW_STANDARD` and later want consolidated reporting | Out of scope for the Chart of Accounts module itself — consolidation is a Reports-module concern that maps each company's `accounts.code` to a shared consolidation chart via a mapping table it owns; this module guarantees only that each company's own CoA is internally correct and exportable, which is the necessary precondition |
| 13 | A journal entry accidentally posts to a group (`allow_posting = false`) account through a bulk-import journal feature elsewhere in the platform, bypassing this module's own UI | BR-4 is enforced at the database/service boundary this module exposes (the Journal Entries service calls `AccountRepository::assertPostable($accountId, $companyId)` before any insert), not merely in this module's own UI, so no caller anywhere in the platform can bypass it |
| 14 | A user with `accounting.accounts.reclassify` but not `accounting.journal.post_to_control_account` tries to reclassify a control account into a non-control type | Rejected — changing whether an account behaves as a control account is a structural change gated by `accounting.accounts.manage_types`-level oversight (requires Admin/Owner in practice, section 15.1), since it would detach the account from its sub-ledger reconciliation guarantees; `reclassify` alone only permits same-control-status type changes |
| 15 | Arabic name contains mixed-direction text (Arabic + an embedded English brand/bank name, e.g. "بنك HSBC الفرع الرئيسي") | Fully supported — `name_ar` has no script restriction, and the frontend renders it with Unicode bidi isolation (`dir="auto"` plus BDI wrapping around the Latin substring) so the English fragment doesn't visually reverse within the RTL sentence |

# 25 Future Features

- **Consolidated multi-company chart view** for corporate groups: a read-only rollup screen that maps each subsidiary's `accounts.code` to a shared group-level chart, without merging the underlying per-company `accounts` tables, feeding group consolidation reports while preserving each entity's statutory independence.
- **AI-driven chart health score**: a single 0–100 score per company (surfaced on the Owner dashboard) combining dormant-account ratio, duplicate-risk count, unclassified-account count, and control-account reconciliation status, with one-click remediation flows into the existing reclassify/merge/deactivate workflows.
- **Version-controlled account tree snapshots**: point-in-time, diffable snapshots of the entire CoA (beyond the row-level audit log) so an Auditor can compare "the chart as it stood on 31 Dec 2026" against today in a single visual diff, rather than reconstructing it from individual `audit_logs` rows.
- **Natural-language account creation**: extending the AI layer so a user can type "add a bank account for our new Al Rai branch's Warba account" in the Pip-style assistant and receive a fully-formed, correctly-parented, correctly-typed draft account ready for one-click confirmation, rather than only reacting to a form already being filled.
- **Automated Zakat/VAT-readiness certification**: a scheduled job that certifies, ahead of a filing deadline, that 100% of accounts touched by that period's transactions carry a valid, non-`none` `tax_category` where required by the company's jurisdiction, blocking tax-return generation (in the Tax module) until the CoA is clean, rather than discovering gaps at filing time.
- **Cross-tenant anonymized benchmarking**: opt-in comparison of a company's account structure and usage ratios (e.g., COGS as % of revenue accounts, dormant-account ratio) against an anonymized peer cohort in the same industry template, surfaced as an insight card, never exposing any other tenant's actual data.
- **Account-level approval workflows configurable per company**: today reclassify/merge/delete follow the fixed role matrix in section 15.1; a future release would let an Owner configure custom N-step approval chains per action type (e.g., "any reclassification of an account with >KWD 50,000 in lifetime postings requires both the CFO and an external Auditor to approve") without a code change.
- **Real-time collaborative chart editing**: multiple accountants editing the tree simultaneously with Laravel Reverb-powered live cursors/locks on the account-tree UI, extending the existing code-generation row-locking (section 8.2, edge case 1) into a fully visible "someone else is editing this node" experience rather than a silent database-level serialization.

# End of Document

