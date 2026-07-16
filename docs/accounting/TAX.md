# Tax Management — QAYD Accounting Engine
Version: 1.0
Status: Design Specification
Module: Accounting
Submodule: Tax Management
---

# Purpose

The Tax Management module is the single authoritative subsystem within the QAYD Accounting Engine
responsible for determining, calculating, recording, reporting, and filing every tax obligation a
tenant company incurs across every jurisdiction in which it operates. It exists so that no other
module — Sales, Purchasing, Payroll, Inventory, or Banking — needs to embed jurisdiction-specific
tax logic of its own. Instead, every domain module calls into Tax Management through a single,
versioned calculation contract (`POST /api/v1/tax/calculate`) at the moment a taxable event occurs
(an invoice is priced, a bill is entered, a payroll run is calculated, a customs declaration is
filed), and Tax Management returns a deterministic, auditable, effective-dated tax result that the
calling module embeds in its own document and that Accounting later posts as balanced journal
entries against the correct output/input tax control accounts.

Concretely, Tax Management is responsible for:

- Maintaining the tree of jurisdictions (country → state/province → city/municipality → custom
  region) that a company is registered to collect and remit tax in.
- Maintaining tax codes, tax categories, tax rates (with effective and expiration dates), and the
  rules that select which rate applies to which combination of product, customer, vendor,
  jurisdiction, and transaction type.
- Executing the actual tax calculation for every transaction type in the platform: sales,
  purchases, payroll, inventory movements with tax implications, imports, exports, services,
  digital goods, subscriptions, refunds, credit notes, debit notes, and manual adjustments.
- Supporting compound taxes, tax-inclusive and tax-exclusive pricing, reverse charge, partial and
  full exemptions, zero-rating, and out-of-scope treatment.
- Producing the full set of statutory tax reports (VAT return, GST return, sales/use tax return,
  withholding tax return, corporate tax return, tax summary, tax liability, tax audit trail,
  compliance dashboard) in the format each supported jurisdiction's tax authority expects, and
  packaging them for electronic submission where government e-filing APIs exist.
- Posting tax accounting entries (output tax payable, input tax receivable, withholding tax
  payable, use tax payable, tax expense) into the General Ledger through the shared journal-entry
  contract, never by writing to `journal_entries` directly from outside the Accounting core.
- Giving AI agents (Tax Advisor, Compliance Agent, Auditor, Fraud Detection, Forecast Agent) a
  well-defined, permission-scoped surface to classify, validate, monitor, optimize, forecast, and
  explain tax positions — while keeping the irreversible step, tax filing/submission, gated behind
  mandatory human approval.

Tax Management does not itself decide legal tax positions in ambiguous cases; it encodes the
positions a company's finance/tax team has configured, applies them consistently and
deterministically to every transaction, and surfaces exceptions (missing tax codes, rate gaps,
jurisdiction mismatches, anomalous liabilities) for a human to resolve. It is a calculation and
compliance engine, not a legal opinion engine.

# Vision

QAYD's ambition is that a finance team operating across Kuwait, Saudi Arabia, the UAE, and beyond
never touches a tax rate table by hand more than once — at onboarding — and never files a return by
manually transcribing numbers from a spreadsheet into a government portal. The vision for Tax
Management, executed in full, looks like this:

1. **Configure once, apply everywhere.** A company's tax registrations, rates, and rules are set up
   a single time per jurisdiction. Every downstream document — a sales invoice raised by a sales
   employee in Kuwait, a purchase bill entered by a purchasing clerk sourcing from Germany, a
   payroll run processed for expatriate staff — automatically resolves the correct tax treatment
   without any user needing to know the underlying regulation.
2. **Zero manual reconciliation.** Because every taxable transaction posts through the same
   calculation engine and the same journal-entry contract, the VAT/GST control accounts in the
   General Ledger always agree, to the fil (or cent), with the sum of `tax_transactions` for the
   period. Reconciliation between "what the books say" and "what the return says" is a solved
   problem, not a monthly fire drill.
3. **AI does the tedious 90%, a human owns the final 10%.** Tax classification of new products,
   anomaly detection on unusual tax positions, drafting of the return, and plain-language
   explanation of *why* a given rate applied are automated and continuously monitored by AI agents.
   The actual submission to a tax authority — the one action that cannot be undone — always waits
   for a named human with `tax.submit` permission to review and approve.
4. **Jurisdiction expansion is a configuration change, not a code change.** Adding a new country,
   state, or emirate to a company's tax footprint means inserting rows into `tax_jurisdictions`,
   `tax_codes`, and `tax_rates` — never shipping new application code. The engine's rule model
   (jurisdiction × tax category × product/service type × customer/vendor tax status × effective
   date) is expressive enough to encode GCC VAT, KSA ZATCA e-invoicing requirements, EU-style
   reverse charge, US-style destination-based sales/use tax, and payroll withholding tables without
   forking the calculation logic.
5. **The system that never guesses.** Every calculated tax line carries full lineage: which
   `tax_rate` row, which `tax_rule` matched, what the effective date window was, and what
   confidence (for AI-assisted classification) produced it. When a tax authority audits a company
   three years later, QAYD can reproduce, to the transaction, exactly why a given tax amount was
   charged on a given date under the rules in force at that time.

# Tax Philosophy

Four principles govern every design decision in this module:

**1. Tax is derived, never authored twice.** A tax amount is always a deterministic function of
(taxable base amount, applicable tax rate as of the transaction date, rounding rule, compounding
order). It is never entered as a free-text number by a user creating an invoice. Users choose a
`tax_code` (or the system infers one via `tax_rules`); the engine computes the rate and the amount.
This guarantees that two invoices with the same taxable base, tax code, and date always produce the
same tax amount — a property tax authorities and auditors depend on.

**2. Effective-dating is not optional.** Tax rates change — a VAT rate moves from 5% to 15%, a
withholding rate is revised by ministerial decree, a municipal surcharge is introduced. Every
`tax_rates` row has `effective_from` and `effective_to` (nullable, meaning "still in force").
The calculation engine always resolves the rate that was in force on the transaction's tax point
date (invoice date for output tax, bill/receipt date for input tax, payment date for
cash-basis regimes), never "the current rate." Historical transactions are never recalculated when
a rate changes; only new transactions dated on/after the new `effective_from` pick up the new rate.
This is enforced structurally (a UNIQUE constraint plus a date-range exclusion constraint on
`tax_rates`), not by application discipline alone.

**3. Reverse charge and partial exemption are first-class, not bolt-ons.** Many of QAYD's target
markets (GCC VAT, EU-style B2B services, import VAT self-assessment) require the *buyer*, not the
seller, to self-account for output and input tax on the same transaction (reverse charge), and many
financial-services or mixed-supply companies can only recover a *percentage* of input tax
(partial exemption via an apportionment/recovery ratio). Both are modeled directly in
`tax_rules` (`is_reverse_charge` flag, `recovery_rate` on exemption certificates) and in
`tax_transactions` (`reverse_charge_output_line_id` / `reverse_charge_input_line_id` pairing, and
`recoverable_amount` vs `non_recoverable_amount` columns) rather than being simulated with manual
journal entries after the fact.

**4. Tax submission is irreversible; the system treats it that way.** Filing a return with a
government authority — or even marking an internal return as "Filed" — cannot be undone by editing
a database row. The workflow therefore has a hard state gate: `tax_returns.status` can only move
from `draft` → `under_review` → `ready_to_file` → `filed` → `accepted`/`rejected`/`amended`, and the
transition into `filed` requires (a) a human user holding `tax.submit`, (b) an explicit approval
record with reason/notes, and (c) for jurisdictions with e-filing, a successful government API
acknowledgment receipt stored verbatim. No AI agent, no automation, no scheduled job may perform
this transition. This mirrors the platform-wide rule that bank transfers, payroll release, and tax
submission always require human approval (see `Permissions`).

# Supported Tax Systems

Tax Management ships native support for eleven tax system archetypes. Each is modeled as a
`tax_categories.system_type` enum value, and each has its own calculation semantics, its own set of
applicable `tax_rules` filters, and its own statutory report template. A company can be registered
for several of these simultaneously (e.g., a Kuwaiti holding company with a Saudi subsidiary is
simultaneously in the KSA VAT system, the KSA withholding tax system, and the Kuwait corporate
income tax system).

### VAT (Value Added Tax)

Multi-stage consumption tax charged at each step of the supply chain, with input tax on purchases
creditable against output tax on sales; the net (output − input) is remitted to (or reclaimed from)
the tax authority each filing period. QAYD's primary GCC deployment target — Kuwait (pending
national VAT legislation, currently 0% headline but the framework is provisioned), Saudi Arabia
(15% standard rate under ZATCA), UAE (5% standard rate under FTA), Bahrain (10%), Oman (5%) — is
built on this system type. VAT is *destination-based* for most B2C goods but the calculation engine
also supports origin-based fallback for jurisdictions that require it. Every VAT-registered company
holds a `tax_registrations` row per country with its VAT/TRN number, filing frequency (monthly/
quarterly), and cash- vs accrual-basis flag.

### GST (Goods and Services Tax)

Functionally similar to VAT (multi-stage, input-credit mechanism) but modeled separately because
several jurisdictions QAYD intends to support (India, Australia, Singapore, Malaysia) call it GST
and impose distinct compliance mechanics: dual-rate CGST/SGST/IGST splits (India), a single
consolidated GST return (Australia/Singapore), and different small-business registration
thresholds. `tax_categories.system_type = 'gst'` carries a `gst_component` sub-classification
(`cgst`, `sgst`, `igst`, `utgst`, `unified`) so a single tax line can, where required, be split into
multiple posted tax_transactions rows referencing the same source document.

### Sales Tax

A single-stage, seller-collects tax charged only on the final sale to the end consumer (no input
credit mechanism), used natively for US state/local sales tax. The engine resolves origin- vs
destination-based sourcing per `tax_jurisdictions.sourcing_rule`, supports jurisdiction stacking
(state + county + city + special district, each its own `tax_rates` row summed via a `tax_groups`
compound definition), and tracks economic nexus thresholds per state so the AI Compliance Agent can
flag when a company crosses a registration threshold in a new state before it becomes a liability
exposure.

### Use Tax

The self-assessed complement to sales tax: when a company purchases a taxable good or service from
a vendor who did not charge sales tax (typically an out-of-state or foreign vendor), the purchasing
company must self-assess and remit use tax directly. The engine detects this automatically at bill
entry: if a `bills` line item's product/service is taxable in the buyer's jurisdiction and the
vendor's invoice shows no tax or a lower rate, a `tax_transactions` row with `tax_direction =
'use_tax_accrual'` is generated, crediting a Use Tax Payable liability account and debiting the
expense/asset account for the tax amount (since there is no input credit for use tax in most US
states unless the good is resold).

### Withholding Tax

Tax withheld at source by the payer on a payment made to a vendor, contractor, or employee, and
remitted directly to the tax authority on the payee's behalf. QAYD implements withholding at three
points: (a) on vendor bills for services/contracts (relevant heavily in KSA, where withholding tax
applies to payments to non-resident vendors at rates from 5% to 20% depending on service type), (b)
on dividend/interest/royalty payments, and (c) on payroll (see Payroll Tax below, modeled
separately for i18n clarity but sharing the same `tax_categories.system_type = 'withholding'`
calculation path). A withholding transaction reduces the net amount paid to the vendor and creates a
Withholding Tax Payable liability plus a withholding certificate record the vendor can use to claim
credit in their own tax return.

### Corporate Tax

Tax on a company's net taxable profit for a fiscal year, calculated at period-end (or estimated
quarterly where required) rather than transaction-by-transaction. The engine supports this
differently from the transactional tax systems above: it reads the posted General Ledger for the
fiscal year, applies a configurable set of tax adjustments (add-backs for non-deductible expenses,
depreciation timing differences, tax-loss carryforward), and produces a `tax_returns` row of type
`corporate_tax` with computed taxable income and tax liability. KSA's 20% corporate tax rate for
non-GCC-owned entities, Kuwait's 15% tax on foreign corporate bodies' profits (Kuwaiti/GCC-owned
entities are typically exempt), and Zakat calculations for GCC-national-owned entities are all
supported via `tax_rules` scoped to `system_type = 'corporate_tax'`.

### Income Tax

Tax on individual (natural person) income, distinct from corporate tax and modeled for
jurisdictions where QAYD's payroll module needs to compute personal income tax withholding
brackets (progressive tax tables) rather than a flat payroll tax rate. Encoded as
`tax_rates` rows with `bracket_min`/`bracket_max`/`marginal_rate` columns forming a progressive
schedule per jurisdiction, resolved by the payroll tax calculation path per employee based on
year-to-date cumulative taxable earnings.

### Payroll Tax

Employer-side and employee-side taxes tied to payroll runs: social-security-style contributions
(e.g., Kuwait's PIFSS for Kuwaiti nationals, GOSI in Saudi Arabia for Saudi/GCC nationals),
unemployment insurance, and any employer payroll levy. Distinct from income tax withholding because
payroll tax often has employer *and* employee legs (both must be calculated, only one is withheld
from the employee, the employer leg is an additional company expense), and is usually capped at a
salary ceiling per period. Modeled with `tax_rates.employer_rate`, `tax_rates.employee_rate`, and
`tax_rates.wage_ceiling` columns.

### Custom Taxes

Any jurisdiction-specific levy that does not cleanly fit the archetypes above — municipal
tourism/hospitality fees, environmental/plastic-bag levies, stamp duty on specific contract types,
Zakat (modeled as a custom tax category for GCC-national-owned entities where Zakat rather than
corporate tax applies), or a company-specific internal cost-allocation "tax" used for management
reporting. `tax_categories.system_type = 'custom'` with a free-form `custom_type` code lets a tenant
define these without an application release, subject to Owner/Tax Manager approval.

### Excise Tax

A per-unit or ad-valorem tax on specific goods considered harmful or luxury (tobacco, energy
drinks, carbonated drinks, sugar-sweetened beverages), highly relevant to GCC deployments — Saudi
Arabia and the UAE both levy excise tax at 50% (soft drinks) and 100% (tobacco, energy drinks) on
import or local production, collected once at the first point of supply rather than at every stage
like VAT. The engine supports both ad-valorem (`percentage_rate` of a defined excise base, which may
differ from the VAT base — e.g., a statutory minimum retail price basis) and specific/per-unit
(`amount_per_unit` × quantity, using the product's `unit_of_measure`) excise calculation, and flags
excise-liable products so that VAT is correctly calculated on the VAT-inclusive-of-excise base where
the local rule requires tax-on-tax (a compound tax case).

### Digital Services Tax

Tax on revenue from digital services (streaming, app stores, online advertising, digital
marketplaces, SaaS) sold to consumers in a jurisdiction regardless of the seller's physical
presence there — increasingly relevant as QAYD itself, and its customers who sell digital goods
regionally, must charge destination-based VAT/GST on digital services (e.g., UAE and KSA both treat
B2C digital services supplied by a non-resident as taxable in the customer's jurisdiction, often via
a simplified non-resident VAT registration regime). Modeled via `tax_categories.system_type =
'digital_services'` with a `is_b2b_reverse_charge_eligible` flag (in most GCC digital-services
rules, B2B supplies shift to reverse charge on the recipient, while B2C requires the non-resident
supplier to register and charge tax directly).

# Tax Jurisdictions

A jurisdiction is any government body (national, sub-national, or municipal) that has the legal
authority to impose a tax and to which a company may owe a registration and a filing obligation.
Tax Management models jurisdictions as a self-referencing tree so that rate lookups can walk from
the most specific applicable level down to the broadest, and so that "stacking" jurisdictions (as
in US sales tax, where a single transaction can be taxed simultaneously by state, county, city, and
special district) resolve as a set, not a single row.

**Table:** `tax_jurisdictions` (full DDL in Database Design). Levels, in descending order of
specificity in the tree:

| Level | Example | Notes |
|---|---|---|
| Country | Kuwait (KW), Saudi Arabia (SA), UAE (AE), Bahrain (BH), Oman (OM), Qatar (QA), United States (US), United Kingdom (GB) | Top of the tree; `parent_id IS NULL`. Carries the default VAT/GST/sales-tax system_type for the country and the ISO 3166-1 alpha-2 code. |
| State / Province | California (US-CA), Ontario (CA-ON), Eastern Province (SA-04) | `parent_id` = country row. Relevant primarily for federal systems (US, Canada) and for KSA/UAE where some fee schedules vary by province/emirate. |
| City / Municipality | Kuwait City, Riyadh, Dubai, Los Angeles | `parent_id` = state/province row (or directly country row where the country has no state level, e.g., Kuwait's governorates modeled as a city-equivalent level). Municipal levies (tourism tax, business licensing tax) attach here. |
| Custom Region | "GCC Customs Union", "EU", "Free Zone — Dubai Airport Free Zone (DAFZA)", "Free Zone — KAEC" | `parent_id` nullable, `region_type = 'custom'`. Used for supra-national blocs (customs unions, trade agreements affecting import duty) and for special economic/free-zone areas that carry their own tax treatment (e.g., 0% corporate tax in a UAE free zone subject to qualifying-income rules) distinct from the surrounding country/emirate. |

Every `tax_jurisdictions` row carries: `code` (unique per company, e.g., `SA`, `SA-RIY`, `US-CA`,
`US-CA-LA`), `name_en`/`name_ar`, `region_type` enum (`country`, `state`, `province`, `city`,
`municipality`, `custom`), `parent_id`, `iso_country_code` (ISO 3166-1 alpha-2, nullable below
country level), `default_currency_code`, `sourcing_rule` enum (`origin`, `destination`,
`mixed`) governing whether tax is calculated based on the seller's or the buyer's location, `status`
(`active`/`inactive`), plus the standard columns.

**Jurisdiction resolution algorithm.** Given a transaction with a ship-to/service location and a
ship-from/company location, the engine:

1. Resolves the buyer-side jurisdiction chain (country → state → city) and the seller-side chain
   from the two addresses.
2. Reads `tax_registrations` for the company to find which of those jurisdictions the company
   actually holds an active registration in (a company only charges tax in a jurisdiction it is
   registered in, unless a marketplace/deemed-supplier rule forces registration — flagged by the AI
   Compliance Agent, see AI Responsibilities).
3. For each jurisdiction level with `sourcing_rule = 'destination'`, uses the buyer's chain; for
   `origin`, uses the seller's chain; for `mixed`, applies the jurisdiction-specific override stored
   on that jurisdiction row (used for US-style "origin-based" states inside an otherwise
   destination-based country).
4. Passes the resolved jurisdiction set (typically 1 row for VAT/GST countries, up to 4 stacked rows
   for US sales tax) into the `tax_rules` matching step described in Tax Configuration.

**Custom regions** are evaluated as an *additional* filter, not a replacement: a transaction
shipped from a UAE mainland company to a UAE free-zone customer still resolves "UAE" as the country
jurisdiction, with the free-zone custom region contributing an *override rule* (e.g., zero-rated
supply to a Designated Zone) that outranks the mainland VAT rate in `tax_rules` priority ordering.

Multi-jurisdiction companies keep one `tax_registrations` row per (company, jurisdiction, system_
type) combination, each with its own registration number, effective date, filing frequency, and
basis (accrual/cash). This is what lets a single QAYD tenant with a Kuwait head office and a Riyadh
branch file a Kuwait corporate tax return and a separate KSA VAT return from the same set of books,
with Tax Management routing each transaction's tax lines to the correct registration automatically
based on the branch (`branch_id`) that issued the document.

# Tax Configuration

Tax Configuration is the layer a Tax Manager or Finance Manager interacts with directly: it is
where the abstract concepts above (jurisdictions, systems) become concrete rates that the
calculation engine can apply. The configuration model is deliberately layered — codes, categories,
rates, rules, groups — so that rate changes (a VAT rate moving from 5% to 15%) never require
touching the rules that decide *which* rate applies to *which* transaction.

### Tax Codes

A `tax_code` is the short, human-facing identifier a user selects (or the AI Classification agent
assigns) on a line item — e.g., `SA-VAT-STD` (KSA standard VAT), `SA-VAT-ZERO` (KSA zero-rated),
`KW-EXEMPT` (Kuwait, out of scope), `US-CA-SALES-STD`. Each `tax_codes` row belongs to exactly one
`tax_categories` row and exists independently of any specific rate value — the code `SA-VAT-STD` is
stable across the 2018 5%-rate era and the post-2020 15%-rate era; only the linked `tax_rates`
history changes. Tax codes carry: `code`, `name_en`/`name_ar`, `tax_category_id`, `is_default`
(offered as the pre-selected code for new products in that jurisdiction), `status`.

### Tax Categories

A `tax_categories` row groups tax codes by system type and jurisdiction and is where the
`system_type` enum (`vat`, `gst`, `sales_tax`, `use_tax`, `withholding`, `corporate_tax`,
`income_tax`, `payroll_tax`, `custom`, `excise`, `digital_services`) lives. Example rows: "KSA VAT",
"UAE VAT", "US Sales & Use Tax — California", "Kuwait Withholding Tax". A category also carries
`calculation_basis` (`percentage`, `per_unit`, `bracket`) which determines whether its rates use
`rate_percentage`, `amount_per_unit`, or the bracket columns.

### Tax Rates

The actual numeric rate, always effective-dated. See Database Design for full DDL of `tax_rates`.
Key columns: `tax_code_id`, `jurisdiction_id`, `rate_percentage NUMERIC(7,4)` (e.g., 15.0000),
`amount_per_unit NUMERIC(19,4)` (for excise/specific taxes), `bracket_min`/`bracket_max`/
`marginal_rate` (for progressive income tax), `effective_from DATE NOT NULL`, `effective_to DATE
NULL`, `rounding_rule` enum (`round_half_up`, `round_half_down`, `round_half_even`, `truncate`),
`rounding_precision SMALLINT DEFAULT 2` (some jurisdictions round to 3 decimal places on
sub-currency-unit fils, e.g., Kuwait's fils has 3 decimal places officially though QAYD stores
NUMERIC(19,4) uniformly and rounds for display/filing per jurisdiction rule).

### Effective Dates and Expiration Dates

`effective_from` and `effective_to` on `tax_rates` are enforced by a PostgreSQL exclusion
constraint (`EXCLUDE USING gist`) so that two active rates for the same `(tax_code_id,
jurisdiction_id)` can never have overlapping date ranges — the database itself refuses a
configuration error that would otherwise let two different rates both claim to be "the" rate for a
given transaction date. When a Tax Manager schedules a future rate change (e.g., entering KSA's
2020 VAT increase from 5% to 15% ahead of the 1 July 2020 effective date), they insert a new
`tax_rates` row with `effective_from = '2020-07-01'` and simultaneously set the prior row's
`effective_to = '2020-06-30'`; both operations happen inside one transaction via the
`TaxRateService::scheduleRateChange()` method so the exclusion constraint never sees a transient
overlap or gap.

### Tax Rules

A `tax_rules` row is the decision logic that maps a transaction's attributes to a `tax_code`. Rules
are evaluated in `priority` order (lower number = evaluated first = higher priority) and the first
matching rule wins. A rule's match conditions (stored as JSONB in `conditions`, validated against a
fixed schema by the FormRequest layer, never raw user-supplied SQL) can reference: `product_category
_id`, `product_tax_treatment` (`standard`, `zero_rated`, `exempt`, `excise_liable`,
`digital_service`), `customer_tax_status` (`registered`, `unregistered`, `government`,
`diplomatic_exempt`), `vendor_tax_status`, `jurisdiction_id` (or jurisdiction ancestor match),
`transaction_type` (`sale`, `purchase`, `import`, `export`, `payroll`, `intercompany`),
`is_b2b`, `customer_country_code` vs `company_country_code` (to detect cross-border/export),
`is_reverse_charge` (boolean output flag, not an input condition), and `min_amount`/`max_amount`
thresholds. Rules also carry `resulting_tax_code_id` and, for reverse-charge cases, a paired
`resulting_output_tax_code_id`/`resulting_input_tax_code_id` so a single rule can generate the
self-assessed output-and-input pair in one resolution.

### Tax Groups

A `tax_groups` row bundles two or more `tax_codes` that must always be applied together and
computed as a set — e.g., US "CA-Los-Angeles-Combined" bundling California state tax + LA county
tax + LA city tax + a special district add-on, each its own `tax_group_items` row with its own
`sequence` (compounding order) and independently effective-dated rate. A `tax_group_items.
apply_on_running_total` boolean marks whether that member computes off the original base or off the
running total including previously-applied group members (needed for compound taxes, see next).

### Compound Taxes

A compound (or "tax-on-tax") calculation is one where a later tax in the sequence is calculated on
a base that already includes an earlier tax. Canonical GCC example: certain jurisdictions require
VAT to be calculated on a price that already includes excise tax (excise first, then VAT on
excise-inclusive price). The engine implements this via `tax_group_items.sequence` +
`apply_on_running_total = true`: the engine calculates member 1 on the raw taxable base, adds that
amount into a running total, then calculates member 2 either on the raw base (if `false`) or on the
running total (if `true`), and so on, recording each member's contribution as its own
`tax_transactions` row so the audit trail shows the exact compounding order used.

### Inclusive Tax

Tax-inclusive pricing means the price shown/entered already contains the tax; the engine
back-calculates the tax component: `tax_amount = gross_amount − (gross_amount / (1 +
rate_percentage/100))`, rounded per the rate's `rounding_rule`. `price_lists.tax_treatment =
'inclusive'` (or a per-line override on `sales_order_items`/`invoice_items`) selects this path. This
is the default and legally mandated presentation for most B2C GCC VAT retail pricing.

### Exclusive Tax

Tax-exclusive pricing means the entered price is the taxable base and tax is added on top:
`tax_amount = base_amount × (rate_percentage/100)`, `total = base_amount + tax_amount`. Default for
B2B invoicing across most jurisdictions and for US sales tax (which is always presented
exclusive on the receipt).

### Reverse Charge

Reverse charge shifts the obligation to account for tax from the seller to the buyer. When a
`tax_rules` row resolves `is_reverse_charge = true` (typically for B2B cross-border services or
specified imports under GCC VAT law), the seller's invoice is issued at zero tax (a note is printed:
"Reverse charge applies — customer to self-account for VAT under Article …"), and on the buyer's
side the engine generates a *matched pair* of `tax_transactions` rows on the buyer's own books: one
`tax_direction = 'output_self_assessed'` (crediting Output Tax Payable, as if the buyer had sold to
itself) and one `tax_direction = 'input_reverse_charge'` (debiting Input Tax Receivable, subject to
the buyer's normal recovery rate). Both rows reference the same `source_document_type`/
`source_document_id` (the vendor bill) via `reverse_charge_pair_id`, and both post in the same
journal entry so the net GL impact is zero when the buyer has full recovery, and equal to the
non-recoverable portion when the buyer has partial exemption.

### Exemptions

An exemption removes the tax obligation entirely for a specific customer, product, or transaction
type, and is distinct from zero-rating (0% but still "in scope," which preserves input-recovery
rights) and out-of-scope (not part of the tax system at all). Exemptions are modeled two ways:

- **Product/service exemption:** `product_tax_treatment = 'exempt'` on the product master (e.g.,
  bare land, certain financial services, healthcare, education under GCC VAT law) — the `tax_rules`
  engine resolves `resulting_tax_code_id` to a zero-value exempt code, and the AI Compliance Agent
  flags any input tax the company incurs to make an exempt supply as non-recoverable per the
  partial-exemption rules below.
- **Customer/entity exemption:** an `exemption_certificates` row (`customer_id` or `vendor_id`,
  `jurisdiction_id`, `exemption_reason` e.g. `diplomatic`, `government`, `charity`, `reseller`,
  `certificate_number`, `valid_from`/`valid_to`, `attachment_id` pointing to the scanned certificate
  via the foundation `attachments` table) overrides the normally-resolved tax code with an exempt
  code for that specific counterparty. Expired certificates are automatically excluded from
  resolution (`valid_to < transaction_date`), and the AI Compliance Agent proactively notifies the
  Tax Manager 30 days before a certificate's expiry.
- **Partial exemption / input-recovery ratio:** for companies making a mix of taxable and exempt
  supplies (common for banks, insurers, mixed-use real estate), a `recovery_ratios` row
  (`company_id`, `fiscal_year_id`, `recovery_rate NUMERIC(5,2)`, `method` — `standard_turnover_
  based` or `special_direct_attribution`, `approved_by`, `approved_at`) is applied to every input
  tax line that cannot be directly attributed to a taxable supply: `recoverable_amount =
  input_tax_amount × recovery_rate`, `non_recoverable_amount = input_tax_amount ×
  (1 − recovery_rate)`, with the non-recoverable portion posted to a Tax Expense (not Recoverable)
  account rather than to Input Tax Receivable. The recovery ratio itself is provisional during the
  year (based on the prior year's actual ratio or an estimate) and is trued up in an annual
  adjustment return line at year-end — modeled as a `tax_return_lines` row of `line_type =
  'partial_exemption_adjustment'`.

# Tax Calculation Engine

The calculation engine is a single Laravel service, `TaxCalculationService`, exposed internally to
every domain module and externally via `POST /api/v1/tax/calculate`. It is stateless per call: given
a `TaxCalculationRequest` DTO (transaction type, line items with product/service references,
amounts, currency, jurisdiction addresses, counterparty tax status, transaction date), it returns a
`TaxCalculationResult` DTO (per-line tax breakdown, totals, applied rule/rate lineage) and — only
when explicitly asked to `commit` — persists `tax_transactions` rows. Domain modules call it twice
in the normal flow: once in `preview` mode (no persistence) while a user is drafting a document, so
totals update live in the Next.js UI, and once in `commit` mode when the document transitions to a
posted/confirmed state, at which point the persisted `tax_transactions` become the source data for
journal-entry generation and for tax returns.

**Algorithm (per line item):**

1. Resolve the applicable `tax_jurisdictions` set (see Tax Jurisdictions) from the transaction's
   ship-from/ship-to or service-location addresses and the company's active `tax_registrations`.
2. Resolve the taxable base amount: `quantity × unit_price` adjusted for line-level discounts,
   then, if the category is `calculation_basis = 'percentage'` and the price is tax-inclusive,
   back-calculate the exclusive base; if `per_unit`, use `quantity` directly against
   `amount_per_unit`; if `bracket`, use year-to-date cumulative taxable earnings (payroll/income tax
   only).
3. Evaluate `tax_rules` in priority order against the line's product/customer/vendor/jurisdiction/
   transaction-type attributes; the first match determines the `tax_code`(s) — a single line may
   resolve to a `tax_group` (multiple stacked/compound codes) rather than one code.
4. For each resolved `tax_code`, look up the `tax_rates` row whose `(effective_from, effective_to)`
   range contains the transaction's tax-point date, for the resolved jurisdiction. If no rate row is
   found, the engine raises a `TaxRateNotConfiguredException`, which the calling module surfaces as
   a 422 validation error rather than silently charging zero tax — a design choice that prevents the
   single most common real-world tax bug (unconfigured new product silently shipping tax-free).
5. Compute each tax amount per the category's calculation basis, applying compounding order for
   groups, and rounding per `rounding_rule`/`rounding_precision`.
6. Apply reverse-charge, exemption-certificate, and partial-exemption-recovery adjustments as
   described in Tax Configuration.
7. Sum to a line total and an overall document total; return full lineage (which rule, which rate
   row, which jurisdiction, which exemption certificate if any) alongside every amount so the result
   is fully explainable without a second query.

The seven paragraphs below describe how this generic algorithm specializes per transaction type.

### Sales

Triggered from `sales_quotations`, `sales_orders`, and `invoices`. Ship-to jurisdiction normally
drives destination-based VAT/GST/sales tax. Output tax accrues to Output Tax Payable at invoice
posting (accrual basis) or at `receipts` posting (cash basis, per the company's `tax_registrations.
basis`). Each `invoice_items` row carries a computed `tax_code_id`, `tax_rate_snapshot` (the rate
value at calculation time, frozen even if the underlying `tax_rates` row is later superseded — never
recalculated retroactively), and `tax_amount`.

### Purchases

Triggered from `purchase_orders` (informational only — no tax posting until a bill exists) and
`bills`. Input tax accrues to Input Tax Receivable, subject to the recovery-rate adjustment above.
The engine cross-checks the vendor's tax registration status (from `vendors.tax_registration_number`
and `vendors.tax_status`) against the resolved rule: an unregistered domestic vendor charging tax is
a validation warning (most GCC VAT rules only allow registered vendors to charge VAT), surfaced by
the AI Validation responsibilities below.

### Payroll

Triggered from `payroll_runs`/`payroll_items`. Two tax categories apply simultaneously per employee
per period: income tax withholding (bracket-based, cumulative year-to-date) and payroll tax
(employer + employee social-security-style contribution, capped at `wage_ceiling`). The engine is
called once per `payroll_items` row with the employee's YTD taxable earnings as calculation
context, producing both an employee-side withholding line (reduces net pay) and an employer-side
contribution line (additional payroll expense, no impact on net pay).

### Inventory

Inventory module transactions themselves (stock movements between warehouses) are not usually
taxable events, but two are: (a) a `stock_adjustments` row marked `reason = 'own_use'` or
`'gift'`/`'sample'` (business assets put to a non-business or free-of-charge use) triggers a
deemed-supply output tax calculation under GCC VAT rules, computed on the item's cost/fair-market
value; (b) inter-branch stock transfers across a jurisdiction boundary (`stock_transfers` where
source and destination `branch_id` map to different `tax_jurisdictions`) can trigger a deemed
import/export leg depending on the two branches' legal-entity structure (single legal entity =
no tax event; separate legal entities under common ownership = a genuine cross-border supply).

### Imports

Triggered when a `bills` or `goods_receipts` row's vendor is foreign (`vendors.country_code !=
company's country_code`) and the goods physically cross a customs border. The engine calculates: (a)
import VAT on the customs value (usually CIF value + duty), self-assessed by the importer in most
GCC states via the reverse-charge-like "deferred import VAT" mechanism at customs clearance —
matched pair of output/input transactions as with reverse charge — and (b) customs duty itself as a
separate `system_type = 'custom'` tax category (not creditable, posted straight to a landed-cost/
inventory-cost account rather than a tax receivable account, since duty is a cost, not a recoverable
tax).

### Exports

Exports of goods and B2B cross-border services are typically zero-rated (0% but in-scope, full input
recovery preserved), not exempt. The engine's `tax_rules` match on `customer_country_code !=
company_country_code` (and, for goods, evidence of physical export — the AI Compliance Agent
requires a linked shipping/customs document before it will mark the return line as substantiated
zero-rating rather than a compliance risk). Export documentation (bill of lading, customs export
declaration) is attached via the polymorphic `attachments` table against the `invoices` row and
referenced by the corresponding `tax_transactions.supporting_document_id`.

### Services

Place-of-supply rules for services differ materially from goods: general B2B services use the
customer's location (reverse charge if cross-border and B2B); B2C digital/electronic services use
the consumer's location regardless of supplier location (see Digital Goods below); "specific"
services (real-estate-related, restaurant/catering, admission to events) use the location where the
service is physically performed regardless of B2B/B2C. `tax_rules.service_place_of_supply_type`
enum (`general`, `digital`, `real_estate`, `event_admission`, `transport`) selects which address on
the transaction (buyer's registered address, buyer's usage location, or the service performance
location) the jurisdiction-resolution step in Tax Calculation step 1 actually uses.

### Digital Goods

Digital goods/services sold B2C are taxed at the customer's location per the `digital_services` tax
category described in Supported Tax Systems, using the address/payment-method/IP evidence trio
(`digital_service_location_evidence` JSONB column on the invoice: billing address, card BIN
country, and IP-geolocation country — at least two of three must agree per most VAT-on-digital-
services regimes) to determine the customer's jurisdiction with sufficient evidence to survive
audit. B2B digital sales reverse-charge to the customer as normal.

### Subscriptions

Recurring billing (`subscriptions` — owned by the Sales module's recurring-billing sub-feature,
referenced here) re-runs the full calculation on every billing cycle rather than freezing the tax
code/rate at subscription creation, because a multi-year subscription must reflect a mid-term rate
change (e.g., the KSA 2020 VAT increase applied to the very next invoice generated after 1 July
2020, even for subscriptions signed under the old rate). Each generated `invoices` row is
independently tax-calculated as a normal sales invoice.

### Refunds

A refund reverses a previously posted sale. The engine does not recompute tax from scratch; it
looks up the original `tax_transactions` row(s) via `original_transaction_id` and generates
mirror-image negative amounts using the *original* rate (even if the current rate has since
changed) — refunding at today's rate a sale taxed at yesterday's rate would create an unreconciled
variance in the tax account. `credit_notes` (the transaction type that carries a refund) always
carry a `reason_code` (`return`, `pricing_error`, `damaged_goods`, `cancellation`) required for the
tax audit report.

### Credit Notes

Structurally identical to Refunds above for tax purposes — a `credit_notes` row is the sales-side
document type, always referencing the `invoices` row it corrects, and always reversing tax at the
original invoice's rate/lineage, never the current rate.

### Debit Notes

The purchase-side mirror of a credit note — used when a company needs to increase (or a vendor
issues to record) an adjustment to a previously received `bills`. Tax treatment mirrors the
original bill's rate lineage in the same way as credit notes mirror invoices.

### Adjustments

A manual `tax_transactions` row of `transaction_type = 'manual_adjustment'`, used only for
corrections that cannot be expressed as a reversal of a specific source document (e.g., a prior-
period partial-exemption true-up, a voluntary disclosure of an under-declared amount discovered in
a self-review). Manual adjustments require `tax.adjust` permission (Tax Manager and above), a
mandatory `reason` and `supporting_document_id`, and are always visible as a distinct line item on
the Tax Audit Report — the engine never lets a manual adjustment blend invisibly into
system-generated lines.

# Multi Country Support

QAYD's Tax Management module is architected so that a single company (and a single group of
related companies under `company_users`/branch structures) can operate simultaneously across
multiple countries without per-country application forks:

- **Per-country registration, not per-company.** `tax_registrations` is scoped to `(company_id,
  jurisdiction_id, system_type)`, so one `companies` row can hold a Kuwait corporate-tax
  registration, a Saudi VAT registration for a KSA branch, and a UAE VAT registration for a Dubai
  branch simultaneously. Each registration has its own filing calendar, currency, and basis.
- **Country-specific rate/rule packs, shipped as data, not code.** Onboarding a new country is a
  data-loading exercise: insert `tax_jurisdictions` rows for the country and its sub-levels, insert
  `tax_categories`/`tax_codes`/`tax_rates` reflecting that country's statutory rates as of go-live,
  and insert `tax_rules` reflecting that country's place-of-supply and reverse-charge logic. QAYD
  ships pre-built, versioned "country packs" (JSON fixtures consumed by an Artisan command
  `php artisan tax:install-country-pack {code}`) for Kuwait, Saudi Arabia, UAE, Bahrain, Oman, and
  Qatar at launch, with the same fixture format usable by any implementation partner to add a new
  country without touching PHP code.
- **Country-aware document numbering and language.** Tax invoices in KSA must carry a
  ZATCA-compliant QR code and specific mandatory fields (seller VAT number, buyer VAT number for
  B2B, timestamp, hash of the previous invoice for the cryptographic chain — see Compliance);
  invoices in Kuwait need no such QR requirement today. The `invoices`/`bills` rendering layer reads
  a `tax_jurisdictions.compliance_profile` JSONB descriptor to know which mandatory fields, QR
  formats, and numbering sequences a given country requires, rather than hardcoding per-country
  branches in the PDF generator.
- **Consolidated and per-entity reporting.** A holding company can request a consolidated Tax
  Summary report across all its `company_users` branches/subsidiaries in one currency (converted at
  period-end or period-average rate per its consolidation policy) for management visibility, while
  every statutory filing (VAT return, corporate tax return) is always generated and filed per legal
  entity per jurisdiction — QAYD never merges two legal entities' filings.
- **Cross-border intercompany transactions.** A sale from the Kuwait entity to the KSA branch (where
  they are the same legal entity, a branch structure) is an internal transfer, not a supply — no
  tax event, handled purely via the accounting core's intercompany elimination; a sale from a
  Kuwait *subsidiary* to a KSA *subsidiary* (two separate legal entities) is a genuine cross-border
  export/import and is taxed as such. This distinction is driven by `companies.legal_structure`
  (`branch` vs `subsidiary`) relative to the parent.

# Multi Currency Support

Every `tax_transactions` row stores both the transaction-currency tax amount and the base-currency
tax amount, following the platform-wide multi-currency convention (`currency_code`,
`exchange_rate`, base-currency amount columns) — this module does not introduce a parallel currency
model.

- **Tax is calculated in transaction currency, converted for filing/GL in base currency.** A
  Kuwaiti company (base currency KWD) issuing an invoice in USD to an export customer calculates VAT
  in USD (0.000, being zero-rated, in this example) but for a domestic sale in USD would calculate
  VAT in USD then convert to KWD using the exchange rate in force on the tax point date
  (`exchange_rates` snapshot referenced by `exchange_rate_id`, never a "current" rate looked up
  later) for posting to the KWD-denominated General Ledger.
- **Statutory returns are filed in the jurisdiction's mandated currency.** KSA VAT returns must be
  filed in SAR, UAE returns in AED, Kuwait corporate tax filings in KWD, regardless of what currency
  the underlying invoices were raised in. `tax_returns.filing_currency_code` is fixed per
  jurisdiction (from `tax_jurisdictions.default_currency_code`) and every `tax_return_lines` amount
  is the base-currency-converted figure re-expressed in the filing currency if the company's base
  currency differs from the jurisdiction's currency (relevant for a KWD-base-currency company filing
  a SAR-denominated KSA VAT return: two conversions — transaction currency → KWD base at transaction
  date, then KWD → SAR at period-end/filing-date rate per ZATCA's prescribed method).
- **Realized/unrealized FX on tax accounts.** Because Output/Input Tax Payable/Receivable balances
  sit in the GL like any other monetary liability/asset, period-end FX revaluation (part of the
  Accounting core's standard revaluation routine) can produce a realized or unrealized FX gain/loss
  on the tax control accounts themselves when the transaction currency differs from base currency —
  this is booked to the standard FX gain/loss account, never blended into the tax expense/payable
  accounts, keeping the tax audit trail's amounts pure tax amounts.
- **Rounding boundary consistency.** Tax rate percentages themselves are currency-agnostic
  (`rate_percentage NUMERIC(7,4)`), but rounding precision can differ by currency (Kuwait's fils
  historically uses 3 decimal digits in some statutory contexts vs. the platform's uniform
  NUMERIC(19,4) storage) — `tax_jurisdictions.display_rounding_precision` governs only the rendered/
  filed figure, never the stored figure, so no precision is lost in the ledger even when a filing
  format demands fewer decimals.

# Accounting Integration

Tax Management never posts a debit or credit to the General Ledger directly. It emits domain events
(`tax.calculated`, `tax.committed`, `tax.return.filed`) that the Accounting core's `JournalPosting
Service` consumes to generate balanced `journal_entries`/`journal_lines`, following the
platform-wide rule that all modules communicate with Accounting through the shared journal-entry
contract, never direct cross-module writes.

### Chart of Accounts

Every company's Chart of Accounts must include, at minimum, the following tax-relevant accounts,
seeded automatically by the Tax Management onboarding wizard against the company's `accounts` tree
(and mapped per jurisdiction where a company has more than one registration, via
`tax_categories.output_tax_account_id` / `input_tax_account_id` / `withholding_tax_account_id`
/ `use_tax_account_id` / `tax_expense_account_id` foreign keys):

| Account | Type | Normal Balance | Purpose |
|---|---|---|---|
| Output Tax Payable — {Jurisdiction} | Liability | Credit | Tax collected on sales, owed to the tax authority |
| Input Tax Receivable — {Jurisdiction} | Asset | Debit | Recoverable tax paid on purchases |
| Input Tax Receivable — Non-Recoverable | Expense | Debit | The non-recoverable portion under partial exemption |
| Use Tax Payable | Liability | Credit | Self-assessed use tax on untaxed purchases |
| Withholding Tax Payable | Liability | Credit | Tax withheld from vendors/employees, owed to authority |
| Withholding Tax Receivable (Asset) | Asset | Debit | Tax withheld by customers on the company's own revenue, creditable against corporate tax |
| Corporate Tax Payable | Liability | Credit | Accrued corporate income tax/Zakat liability |
| Corporate Tax Expense | Expense | Debit | P&L charge for the period's tax provision |
| Customs Duty (Landed Cost) | Asset (Inventory) or Expense | Debit | Non-recoverable import duty, capitalized into inventory cost |
| Excise Tax Payable | Liability | Credit | Excise collected, owed to the authority |
| Tax Suspense/Clearing | Asset/Liability (temp) | Either | Holds amounts pending jurisdiction/rate resolution during exception handling |

### Journal Entries

Every committed `tax_transactions` row (or, more precisely, every source document commit — an
invoice, bill, payroll run — batches its tax lines into a single journal entry alongside its
non-tax lines) generates journal lines dimensioned exactly like any other posting: optional
`cost_center_id`, `project_id`, `department_id`, `branch_id` inherited from the source document.
Example — a KSA sales invoice for SAR 10,000 taxable revenue at 15% standard VAT:

```
Journal Entry JE-2026-04521 (source: invoices INV-2026-08831, branch_id = KSA-Riyadh)
  Dr  Accounts Receivable — Customer                       11,500.0000 SAR
      Cr  Sales Revenue                                                10,000.0000 SAR
      Cr  Output Tax Payable — Saudi Arabia VAT                         1,500.0000 SAR
```

And the corresponding purchase-side bill for SAR 4,000 + 15% recoverable input VAT:

```
Journal Entry JE-2026-04522 (source: bills BILL-2026-04410, branch_id = KSA-Riyadh)
  Dr  Office Supplies Expense                                4,000.0000 SAR
  Dr  Input Tax Receivable — Saudi Arabia VAT                  600.0000 SAR
      Cr  Accounts Payable — Vendor                                     4,600.0000 SAR
```

A reverse-charge import example (import of services, SAR 20,000, 15% VAT self-assessed, full
recovery):

```
Journal Entry JE-2026-04530 (source: bills BILL-2026-04512, reverse charge)
  Dr  Consulting Expense                                    20,000.0000 SAR
  Dr  Input Tax Receivable — Saudi Arabia VAT                3,000.0000 SAR
      Cr  Output Tax Payable — Saudi Arabia VAT (self-assessed)         3,000.0000 SAR
      Cr  Accounts Payable — Vendor                                    20,000.0000 SAR
```

### General Ledger

Because Output/Input Tax accounts are ordinary GL accounts, they roll up into the standard Trial
Balance and are subject to the same period-close, revaluation, and reconciliation routines as any
other account. The `ledger_entries` projection (materialized view over posted `journal_lines`)
means the Tax Liability report (see Reports) can be generated either from the tax-domain
`tax_transactions` table (fast, tax-specific) or cross-checked against `ledger_entries` filtered to
the tax GL accounts (slow, authoritative) — the reconciliation between the two is itself a
scheduled integrity check (see Performance) that raises an alert if they ever diverge by more than
a rounding tolerance (0.01 in the base currency), since divergence would indicate a bypass of the
calculation engine somewhere in the codebase.

### Financial Statements

Output Tax Payable and Input Tax Receivable (net of the non-recoverable portion, which sits in
Expense) appear on the Balance Sheet as, respectively, a current liability and a current asset
(often netted into a single "VAT Payable/Receivable, net" line for external presentation, while the
underlying ledger keeps them gross for audit clarity). Corporate Tax Expense and the non-recoverable
input-tax expense line appear on the Income Statement above or as part of "Other Operating
Expenses" depending on the company's chosen presentation policy (`report_definitions` template
choice). Withholding Tax Receivable, where a company's customers withhold tax on its revenue,
appears as a current asset until offset against the Corporate Tax Payable balance at year-end
settlement.

### Sales / Purchasing / Inventory / Payroll / Banking

- **Sales:** every `invoices`/`credit_notes` commit event carries its `tax_transactions` lines into
  the same journal entry as the revenue recognition lines — never a separate, later "tax catch-up"
  entry.
- **Purchasing:** every `bills`/`debit_notes` commit event does the same on the input-tax side; the
  three-way match (purchase order → goods receipt → bill) that Purchasing performs before allowing a
  bill to post also validates that the tax code on the bill matches the tax code implied by the
  original purchase order's product/vendor combination, flagging a mismatch as a validation warning
  rather than silently accepting a vendor's possibly-incorrect tax charge.
- **Inventory:** deemed-supply and cross-border-transfer tax events (see Tax Calculation Engine →
  Inventory) post through the same `stock_adjustments`/`stock_transfers` commit events, and customs
  duty on imported inventory is capitalized as landed cost via a normal `stock_movements` cost-layer
  entry rather than posted to a tax GL account, since duty (unlike VAT) becomes part of the asset's
  cost basis.
- **Payroll:** each `payroll_runs` commit event posts employee income-tax-withholding as a reduction
  of the wage expense/increase of a withholding-payable liability, and employer-side payroll tax
  contributions as an additional payroll expense line plus a corresponding payable — both are part
  of the same payroll journal entry Payroll already generates, tagged with the tax lines' own
  `tax_transactions` references for reporting.
- **Banking:** tax *payments* themselves (a bank transfer remitting the net VAT liability to the tax
  authority, or a corporate-tax installment payment) are ordinary `bank_transactions`/`transfers`
  rows that debit the relevant tax payable account and credit the bank account, initiated from the
  Tax Liability report's "Record Payment" action but requiring the same `bank.transfer` human
  approval as any other outbound transfer — Tax Management can prepare the payment instruction, it
  can never execute the transfer itself.

# AI Responsibilities

Nine AI capabilities are scoped to Tax Management. Every one of them operates under the platform
rule that AI proposes, a human (or an explicit no-approval-needed policy the company itself has
configured) disposes for anything sensitive, and every AI output — without exception — carries a
`confidence_score NUMERIC(5,4)` (0.0000–1.0000), a `reasoning` text explanation, and an array of
`supporting_document_ids`/`source_transaction_ids`. The primary agents involved are the **Tax
Advisor**, **Compliance Agent**, **Auditor**, **Fraud Detection**, and **Forecast Agent**; the
**General Accountant** and **CFO** agents consume Tax Management's outputs but do not own tax logic
themselves.

| Responsibility | Agent | Inputs | Outputs | Autonomy | Confidence Handling |
|---|---|---|---|---|---|
| Tax Classification | Tax Advisor | New/edited product or service master record (name, category, description) | Suggested `tax_code_id` + `product_tax_treatment` | Suggest-only below 0.90 confidence; auto-apply at ≥0.90 confidence for products in a category with an existing 100%-consistent historical pattern, always reversible | Confidence < 0.70 blocks auto-apply entirely and requires Tax Manager review; 0.70–0.89 shows a pre-filled suggestion the user must confirm; ≥0.90 auto-applies but logs an audit entry and is visible in a daily "AI-classified items" digest for spot-check |
| Tax Validation | Compliance Agent | Every commit-mode calculation result before journal posting | Pass/Fail + list of validation warnings (unregistered vendor charging tax, tax code inconsistent with product's historical treatment, missing exemption certificate for a claimed exemption) | Auto-runs on every transaction; blocks posting only for hard rule violations (e.g., missing a `tax_rates` row — see Tax Calculation Engine step 4); soft warnings surface to the user but do not block | N/A (deterministic rule engine, not probabilistic) for hard blocks; soft warnings carry a confidence-weighted priority score for triage ordering |
| Compliance Monitoring | Compliance Agent | Registration thresholds (economic nexus, digital-services thresholds), certificate expiries, filing calendar, rate-change bulletins ingested from government sources | Proactive alerts: "You are approaching the Texas economic nexus threshold," "Exemption certificate CERT-4471 expires in 30 days," "KSA VAT return for March 2026 is due in 5 days and is still in draft" | Suggest-only; always a notification, never an automated registration or filing action | Threshold/date-based alerts carry no confidence score (they are deterministic); regulation-change alerts from unstructured sources (see Regulation Change Alerts) carry a confidence score reflecting source reliability |
| Tax Optimization | Tax Advisor / CFO | Historical transaction mix, recovery-ratio trend, entity structure, available elections (e.g., cash- vs accrual-basis VAT registration, voluntary registration timing) | Recommendations: "Switching to cash-basis VAT filing would improve cash flow by an estimated 45,000 KWD/quarter based on your average 60-day receivable cycle," "Your recovery ratio has been below the safe-harbor de-minimis threshold for 2 consecutive quarters — a full input-tax claim may be available" | Suggest-only, always; optimization recommendations require the Tax Manager/Finance Manager to action any actual election change, which itself is a `tax_registrations` update flow gated behind Owner/Admin approval | Every recommendation shows the estimated financial impact range and the confidence in the underlying assumption set |
| Anomaly Detection | Fraud Detection / Auditor | Statistical baseline of tax-to-revenue ratio, tax-to-purchase ratio, and rate distribution per product category, per period, per branch | Flags: an invoice with an unusually low/zero tax rate for a product category that is 99% taxed at standard rate elsewhere; a sudden spike in exempt-flagged transactions from one sales employee; a credit note issued at a materially different rate than its original invoice (should never happen structurally, so this is also a data-integrity signal) | Suggest-only; creates a review task assigned to the Tax Manager/Auditor, never auto-corrects the underlying transaction | Anomalies below a z-score/confidence threshold are batched into a weekly digest rather than triggering an immediate interruptive alert, to avoid alert fatigue |
| Risk Detection | Compliance Agent / Auditor | Registration coverage vs. actual transaction jurisdictions, unresolved validation warnings aging, partial-exemption ratio volatility, cross-border transaction volume without corresponding customs documentation | A per-jurisdiction Tax Risk Score (0–100) surfaced on the Tax Manager dashboard, decomposed into named risk factors | Suggest-only; the score and its factors are informational, driving human prioritization, never an automated filing or registration change | Each contributing risk factor shows its own weight and evidence so the score is auditable, not a black box |
| Tax Forecasting | Forecast Agent | Trailing 12 months of `tax_transactions`, seasonality, pipeline data from Sales (open quotations/orders), planned payroll headcount changes | Projected tax liability for the next 1–4 filing periods, with a confidence band (low/expected/high) | Suggest-only; feeds cash-flow planning (visible to Treasury Manager/CFO agents) but never books a provisional liability into the GL automatically — provisions are always a human-reviewed period-end journal entry | Forecast confidence decays with horizon length, shown explicitly (e.g., "Next period: 92% confidence; Period +3: 61% confidence") |
| Tax Explanation | Tax Advisor | Any posted tax line, on user request ("why was this taxed at 15% and not 5%?") | Plain-language explanation walking through the exact `tax_rules` match, `tax_rates` row, and jurisdiction resolution that produced the amount, in the user's language (English/Arabic) | Fully autonomous — this is read-only explanation of already-deterministic, already-posted data, carries no risk of incorrect action | Confidence is always 1.0000 for explanations of committed transactions, since the explanation is a direct trace of the deterministic calculation lineage stored with the transaction, not a probabilistic inference |
| Regulation Change Alerts | Compliance Agent | Government gazette/tax-authority bulletin ingestion (KSA ZATCA circulars, Kuwait Ministry of Finance announcements, GCC VAT framework amendments), monitored via scheduled WebFetch/RSS ingestion jobs | Structured alert: jurisdiction, summary of the change, effective date, which of the company's `tax_codes`/`tax_rates`/`tax_rules` are likely affected, and a pre-drafted (unsaved) rate-change proposal for the Tax Manager to review and commit via the normal effective-dated rate-change flow | Suggest-only, always; the agent never inserts or modifies a `tax_rates` row itself — it prepares the proposed change and a human commits it through `TaxRateService::scheduleRateChange()` | Confidence reflects source authority (a ZATCA circular = high confidence; a news article referencing an unconfirmed draft law = lower confidence, explicitly labeled "unconfirmed — verify before acting") |

Across all nine responsibilities, the AI Layer (FastAPI/Python) never writes to `tax_codes`,
`tax_rates`, `tax_rules`, or `tax_transactions` directly. Every AI action is submitted as a proposal
object to the Laravel API (`POST /api/v1/tax/ai/proposals`), which runs the exact same FormRequest
validation and permission checks as a human-submitted change, and which a human (or, for the
narrow, explicitly-configured auto-apply case in Tax Classification above, a policy the company
itself turned on) must approve before it takes effect.

# Database Design

All tables below follow the platform-wide standard columns convention (`id`, `company_id`,
`branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) even where not
re-listed in a `CREATE TABLE` for brevity; the DDL shows them explicitly on the first table and
abbreviates as `-- standard columns --` thereafter to keep the specification readable, but every
migration file for every table below includes the full set. Money columns are `NUMERIC(19,4)`,
exchange rates `NUMERIC(18,6)`, quantities `NUMERIC(18,4)`, per the platform convention.

### Enums

```sql
CREATE TYPE tax_system_type AS ENUM (
    'vat', 'gst', 'sales_tax', 'use_tax', 'withholding', 'corporate_tax',
    'income_tax', 'payroll_tax', 'custom', 'excise', 'digital_services'
);

CREATE TYPE tax_jurisdiction_level AS ENUM (
    'country', 'state', 'province', 'city', 'municipality', 'custom'
);

CREATE TYPE tax_sourcing_rule AS ENUM ('origin', 'destination', 'mixed');

CREATE TYPE tax_calculation_basis AS ENUM ('percentage', 'per_unit', 'bracket');

CREATE TYPE tax_rounding_rule AS ENUM (
    'round_half_up', 'round_half_down', 'round_half_even', 'truncate'
);

CREATE TYPE tax_direction AS ENUM (
    'output', 'input', 'output_self_assessed', 'input_reverse_charge',
    'use_tax_accrual', 'withholding_payable', 'withholding_receivable'
);

CREATE TYPE tax_transaction_source_type AS ENUM (
    'invoice', 'credit_note', 'bill', 'debit_note', 'receipt', 'payroll_item',
    'stock_adjustment', 'stock_transfer', 'manual_adjustment'
);

CREATE TYPE tax_return_status AS ENUM (
    'draft', 'under_review', 'ready_to_file', 'filed', 'accepted', 'rejected', 'amended'
);

CREATE TYPE tax_return_type AS ENUM (
    'vat', 'gst', 'sales_tax', 'withholding', 'corporate_tax', 'excise', 'custom'
);

CREATE TYPE tax_product_treatment AS ENUM (
    'standard', 'zero_rated', 'exempt', 'out_of_scope', 'excise_liable', 'digital_service'
);

CREATE TYPE tax_counterparty_status AS ENUM (
    'registered', 'unregistered', 'government', 'diplomatic_exempt', 'foreign'
);
```

### `tax_jurisdictions`

```sql
CREATE TABLE tax_jurisdictions (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                  BIGINT NOT NULL REFERENCES companies(id),
    branch_id                   BIGINT NULL REFERENCES branches(id),
    parent_id                   BIGINT NULL REFERENCES tax_jurisdictions(id),
    code                        VARCHAR(32) NOT NULL,
    name_en                     VARCHAR(150) NOT NULL,
    name_ar                     VARCHAR(150) NOT NULL,
    region_type                 tax_jurisdiction_level NOT NULL,
    iso_country_code            VARCHAR(2) NULL,
    default_currency_code       VARCHAR(3) NULL,
    sourcing_rule               tax_sourcing_rule NOT NULL DEFAULT 'destination',
    compliance_profile          JSONB NOT NULL DEFAULT '{}',
    status                      VARCHAR(16) NOT NULL DEFAULT 'active'
                                 CHECK (status IN ('active','inactive')),
    tags                        JSONB NOT NULL DEFAULT '[]',
    custom_fields               JSONB NOT NULL DEFAULT '{}',
    created_by                  BIGINT NULL REFERENCES users(id),
    updated_by                  BIGINT NULL REFERENCES users(id),
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                  TIMESTAMPTZ NULL,
    CONSTRAINT uq_tax_jurisdictions_company_code UNIQUE (company_id, code)
);
CREATE INDEX idx_tax_jurisdictions_company ON tax_jurisdictions(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_tax_jurisdictions_parent  ON tax_jurisdictions(parent_id);
CREATE INDEX idx_tax_jurisdictions_iso     ON tax_jurisdictions(iso_country_code);
```

### `tax_registrations`

```sql
CREATE TABLE tax_registrations (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    jurisdiction_id        BIGINT NOT NULL REFERENCES tax_jurisdictions(id),
    system_type           tax_system_type NOT NULL,
    registration_number   VARCHAR(64) NOT NULL,
    legal_name            VARCHAR(200) NOT NULL,
    filing_frequency      VARCHAR(16) NOT NULL DEFAULT 'monthly'
                           CHECK (filing_frequency IN ('monthly','quarterly','annually')),
    basis                 VARCHAR(16) NOT NULL DEFAULT 'accrual'
                           CHECK (basis IN ('accrual','cash')),
    effective_from        DATE NOT NULL,
    effective_to          DATE NULL,
    status                VARCHAR(16) NOT NULL DEFAULT 'active'
                           CHECK (status IN ('active','suspended','deregistered')),
    created_by             BIGINT NULL REFERENCES users(id),
    updated_by             BIGINT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at             TIMESTAMPTZ NULL,
    CONSTRAINT uq_tax_registrations UNIQUE (company_id, jurisdiction_id, system_type, registration_number)
);
CREATE INDEX idx_tax_registrations_company ON tax_registrations(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_tax_registrations_jur     ON tax_registrations(jurisdiction_id);
```

### `tax_categories`

```sql
CREATE TABLE tax_categories (
    id                       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id               BIGINT NOT NULL REFERENCES companies(id),
    branch_id                BIGINT NULL REFERENCES branches(id),
    jurisdiction_id          BIGINT NOT NULL REFERENCES tax_jurisdictions(id),
    system_type              tax_system_type NOT NULL,
    calculation_basis        tax_calculation_basis NOT NULL DEFAULT 'percentage',
    name_en                  VARCHAR(150) NOT NULL,
    name_ar                  VARCHAR(150) NOT NULL,
    output_tax_account_id      BIGINT NULL REFERENCES accounts(id),
    input_tax_account_id       BIGINT NULL REFERENCES accounts(id),
    non_recoverable_account_id BIGINT NULL REFERENCES accounts(id),
    withholding_tax_account_id BIGINT NULL REFERENCES accounts(id),
    use_tax_account_id         BIGINT NULL REFERENCES accounts(id),
    tax_expense_account_id     BIGINT NULL REFERENCES accounts(id),
    status                    VARCHAR(16) NOT NULL DEFAULT 'active'
                              CHECK (status IN ('active','inactive')),
    tags                      JSONB NOT NULL DEFAULT '[]',
    custom_fields             JSONB NOT NULL DEFAULT '{}',
    created_by                BIGINT NULL REFERENCES users(id),
    updated_by                BIGINT NULL REFERENCES users(id),
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                TIMESTAMPTZ NULL
);
CREATE INDEX idx_tax_categories_company ON tax_categories(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_tax_categories_jur     ON tax_categories(jurisdiction_id);
CREATE INDEX idx_tax_categories_system  ON tax_categories(system_type);
```

### `tax_codes`

```sql
CREATE TABLE tax_codes (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    tax_category_id     BIGINT NOT NULL REFERENCES tax_categories(id),
    code                VARCHAR(32) NOT NULL,
    name_en             VARCHAR(150) NOT NULL,
    name_ar             VARCHAR(150) NOT NULL,
    description         TEXT NULL,
    is_default          BOOLEAN NOT NULL DEFAULT false,
    is_reverse_charge    BOOLEAN NOT NULL DEFAULT false,
    is_zero_rated        BOOLEAN NOT NULL DEFAULT false,
    is_exempt            BOOLEAN NOT NULL DEFAULT false,
    status              VARCHAR(16) NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active','inactive')),
    tags                JSONB NOT NULL DEFAULT '[]',
    custom_fields       JSONB NOT NULL DEFAULT '{}',
    created_by           BIGINT NULL REFERENCES users(id),
    updated_by           BIGINT NULL REFERENCES users(id),
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at           TIMESTAMPTZ NULL,
    CONSTRAINT uq_tax_codes_company_code UNIQUE (company_id, code)
);
CREATE INDEX idx_tax_codes_company  ON tax_codes(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_tax_codes_category ON tax_codes(tax_category_id);
```

### `tax_rates` (effective-dated, exclusion-constrained)

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE tax_rates (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id             BIGINT NOT NULL REFERENCES companies(id),
    branch_id              BIGINT NULL REFERENCES branches(id),
    tax_code_id            BIGINT NOT NULL REFERENCES tax_codes(id),
    jurisdiction_id        BIGINT NOT NULL REFERENCES tax_jurisdictions(id),
    rate_percentage        NUMERIC(7,4) NULL CHECK (rate_percentage >= 0 AND rate_percentage <= 100),
    amount_per_unit        NUMERIC(19,4) NULL CHECK (amount_per_unit >= 0),
    bracket_min            NUMERIC(19,4) NULL,
    bracket_max            NUMERIC(19,4) NULL,
    marginal_rate          NUMERIC(7,4) NULL,
    employer_rate          NUMERIC(7,4) NULL,
    employee_rate          NUMERIC(7,4) NULL,
    wage_ceiling           NUMERIC(19,4) NULL,
    rounding_rule          tax_rounding_rule NOT NULL DEFAULT 'round_half_up',
    rounding_precision     SMALLINT NOT NULL DEFAULT 2,
    effective_from         DATE NOT NULL,
    effective_to           DATE NULL,
    legal_reference        VARCHAR(255) NULL,
    status                 VARCHAR(16) NOT NULL DEFAULT 'active'
                            CHECK (status IN ('active','superseded','scheduled')),
    created_by              BIGINT NULL REFERENCES users(id),
    updated_by              BIGINT NULL REFERENCES users(id),
    created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at              TIMESTAMPTZ NULL,
    CONSTRAINT chk_tax_rates_date_order CHECK (effective_to IS NULL OR effective_to >= effective_from),
    CONSTRAINT excl_tax_rates_no_overlap EXCLUDE USING gist (
        tax_code_id WITH =,
        jurisdiction_id WITH =,
        daterange(effective_from, COALESCE(effective_to, 'infinity'::date), '[]') WITH &&
    ) WHERE (deleted_at IS NULL)
);
CREATE INDEX idx_tax_rates_code_jur   ON tax_rates(tax_code_id, jurisdiction_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_tax_rates_effective  ON tax_rates(effective_from, effective_to);
```

The `EXCLUDE USING gist` constraint is the structural guarantee referenced in Tax Philosophy: the
database physically rejects any insert or update that would create two overlapping active date
ranges for the same `(tax_code_id, jurisdiction_id)` pair, making "which rate applies on this date"
always resolve to exactly zero or one row.

### `tax_rules`

```sql
CREATE TABLE tax_rules (
    id                              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                       BIGINT NOT NULL REFERENCES companies(id),
    branch_id                        BIGINT NULL REFERENCES branches(id),
    name_en                          VARCHAR(150) NOT NULL,
    name_ar                          VARCHAR(150) NOT NULL,
    jurisdiction_id                  BIGINT NULL REFERENCES tax_jurisdictions(id),
    transaction_type                 VARCHAR(32) NOT NULL,
    priority                         INTEGER NOT NULL DEFAULT 100,
    conditions                       JSONB NOT NULL DEFAULT '{}',
    is_reverse_charge                 BOOLEAN NOT NULL DEFAULT false,
    resulting_tax_code_id             BIGINT NULL REFERENCES tax_codes(id),
    resulting_output_tax_code_id       BIGINT NULL REFERENCES tax_codes(id),
    resulting_input_tax_code_id        BIGINT NULL REFERENCES tax_codes(id),
    resulting_tax_group_id             BIGINT NULL REFERENCES tax_groups(id),
    service_place_of_supply_type       VARCHAR(32) NULL,
    status                            VARCHAR(16) NOT NULL DEFAULT 'active'
                                      CHECK (status IN ('active','inactive')),
    created_by                        BIGINT NULL REFERENCES users(id),
    updated_by                        BIGINT NULL REFERENCES users(id),
    created_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                        TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                        TIMESTAMPTZ NULL,
    CONSTRAINT chk_tax_rules_result CHECK (
        resulting_tax_code_id IS NOT NULL
        OR resulting_tax_group_id IS NOT NULL
        OR (resulting_output_tax_code_id IS NOT NULL AND resulting_input_tax_code_id IS NOT NULL)
    )
);
CREATE INDEX idx_tax_rules_company_priority ON tax_rules(company_id, priority) WHERE deleted_at IS NULL;
CREATE INDEX idx_tax_rules_conditions_gin   ON tax_rules USING gin (conditions);
```

### `tax_groups` and `tax_group_items`

```sql
CREATE TABLE tax_groups (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    branch_id       BIGINT NULL REFERENCES branches(id),
    code           VARCHAR(32) NOT NULL,
    name_en        VARCHAR(150) NOT NULL,
    name_ar        VARCHAR(150) NOT NULL,
    is_compound     BOOLEAN NOT NULL DEFAULT false,
    status         VARCHAR(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_by      BIGINT NULL REFERENCES users(id),
    updated_by      BIGINT NULL REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at      TIMESTAMPTZ NULL,
    CONSTRAINT uq_tax_groups_company_code UNIQUE (company_id, code)
);

CREATE TABLE tax_group_items (
    id                       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tax_group_id              BIGINT NOT NULL REFERENCES tax_groups(id),
    tax_code_id               BIGINT NOT NULL REFERENCES tax_codes(id),
    sequence                 SMALLINT NOT NULL,
    apply_on_running_total     BOOLEAN NOT NULL DEFAULT false,
    created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_tax_group_items_seq UNIQUE (tax_group_id, sequence)
);
CREATE INDEX idx_tax_group_items_group ON tax_group_items(tax_group_id);
```

### `exemption_certificates`

```sql
CREATE TABLE exemption_certificates (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id           BIGINT NOT NULL REFERENCES companies(id),
    branch_id            BIGINT NULL REFERENCES branches(id),
    customer_id          BIGINT NULL REFERENCES customers(id),
    vendor_id            BIGINT NULL REFERENCES vendors(id),
    jurisdiction_id       BIGINT NOT NULL REFERENCES tax_jurisdictions(id),
    exemption_reason     VARCHAR(32) NOT NULL
                         CHECK (exemption_reason IN ('diplomatic','government','charity','reseller','other')),
    certificate_number   VARCHAR(64) NOT NULL,
    valid_from            DATE NOT NULL,
    valid_to              DATE NULL,
    attachment_id         BIGINT NULL REFERENCES attachments(id),
    status               VARCHAR(16) NOT NULL DEFAULT 'active'
                         CHECK (status IN ('active','expired','revoked')),
    created_by            BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,
    CONSTRAINT chk_exemption_certificates_party CHECK (
        (customer_id IS NOT NULL AND vendor_id IS NULL) OR
        (customer_id IS NULL AND vendor_id IS NOT NULL)
    )
);
CREATE INDEX idx_exemption_certificates_customer ON exemption_certificates(customer_id);
CREATE INDEX idx_exemption_certificates_vendor   ON exemption_certificates(vendor_id);
CREATE INDEX idx_exemption_certificates_validity ON exemption_certificates(valid_from, valid_to);
```

### `recovery_ratios`

```sql
CREATE TABLE recovery_ratios (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id         BIGINT NOT NULL REFERENCES companies(id),
    branch_id          BIGINT NULL REFERENCES branches(id),
    fiscal_year_id     BIGINT NOT NULL REFERENCES fiscal_years(id),
    jurisdiction_id    BIGINT NOT NULL REFERENCES tax_jurisdictions(id),
    recovery_rate      NUMERIC(5,2) NOT NULL CHECK (recovery_rate >= 0 AND recovery_rate <= 100),
    method             VARCHAR(32) NOT NULL DEFAULT 'standard_turnover_based'
                       CHECK (method IN ('standard_turnover_based','special_direct_attribution')),
    is_provisional      BOOLEAN NOT NULL DEFAULT true,
    approved_by         BIGINT NULL REFERENCES users(id),
    approved_at         TIMESTAMPTZ NULL,
    created_by          BIGINT NULL REFERENCES users(id),
    updated_by          BIGINT NULL REFERENCES users(id),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,
    CONSTRAINT uq_recovery_ratios UNIQUE (company_id, fiscal_year_id, jurisdiction_id, is_provisional)
);
```

### `tax_transactions`

The core fact table. Every calculated-and-committed tax amount in the entire platform is a row
here, and every journal entry involving tax references back to this table via
`journal_lines.tax_transaction_id` (an additional nullable FK on `journal_lines`, owned by the
Accounting core, added specifically to support this reverse traceability).

```sql
CREATE TABLE tax_transactions (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                   BIGINT NOT NULL REFERENCES companies(id),
    branch_id                    BIGINT NULL REFERENCES branches(id),
    tax_code_id                  BIGINT NOT NULL REFERENCES tax_codes(id),
    tax_rate_id                  BIGINT NOT NULL REFERENCES tax_rates(id),
    tax_rule_id                  BIGINT NULL REFERENCES tax_rules(id),
    jurisdiction_id              BIGINT NOT NULL REFERENCES tax_jurisdictions(id),
    tax_registration_id          BIGINT NULL REFERENCES tax_registrations(id),
    tax_direction                tax_direction NOT NULL,
    source_document_type         tax_transaction_source_type NOT NULL,
    source_document_id           BIGINT NOT NULL,
    source_document_line_id      BIGINT NULL,
    original_transaction_id      BIGINT NULL REFERENCES tax_transactions(id),
    reverse_charge_pair_id       BIGINT NULL REFERENCES tax_transactions(id),
    exemption_certificate_id     BIGINT NULL REFERENCES exemption_certificates(id),
    tax_point_date               DATE NOT NULL,
    taxable_base_amount          NUMERIC(19,4) NOT NULL,
    rate_snapshot_percentage     NUMERIC(7,4) NULL,
    rate_snapshot_per_unit       NUMERIC(19,4) NULL,
    tax_amount                   NUMERIC(19,4) NOT NULL,
    recoverable_amount           NUMERIC(19,4) NOT NULL DEFAULT 0,
    non_recoverable_amount       NUMERIC(19,4) NOT NULL DEFAULT 0,
    currency_code                VARCHAR(3) NOT NULL,
    exchange_rate                NUMERIC(18,6) NOT NULL DEFAULT 1,
    base_currency_tax_amount     NUMERIC(19,4) NOT NULL,
    is_reverse_charge            BOOLEAN NOT NULL DEFAULT false,
    calculation_lineage          JSONB NOT NULL DEFAULT '{}',
    ai_confidence_score          NUMERIC(5,4) NULL CHECK (ai_confidence_score BETWEEN 0 AND 1),
    ai_reasoning                 TEXT NULL,
    reason_code                  VARCHAR(32) NULL,
    supporting_document_id       BIGINT NULL REFERENCES attachments(id),
    tax_return_line_id           BIGINT NULL REFERENCES tax_return_lines(id),
    status                       VARCHAR(16) NOT NULL DEFAULT 'posted'
                                 CHECK (status IN ('posted','reversed','adjusted')),
    created_by                    BIGINT NULL REFERENCES users(id),
    updated_by                    BIGINT NULL REFERENCES users(id),
    created_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                    TIMESTAMPTZ NULL,
    CONSTRAINT chk_tax_transactions_recovery_sum CHECK (
        recoverable_amount + non_recoverable_amount = tax_amount
        OR tax_direction NOT IN ('input','input_reverse_charge')
    )
);
CREATE INDEX idx_tax_transactions_company_period
    ON tax_transactions(company_id, tax_point_date) WHERE deleted_at IS NULL;
CREATE INDEX idx_tax_transactions_source
    ON tax_transactions(source_document_type, source_document_id);
CREATE INDEX idx_tax_transactions_jurisdiction
    ON tax_transactions(jurisdiction_id, tax_point_date);
CREATE INDEX idx_tax_transactions_return_line
    ON tax_transactions(tax_return_line_id);
CREATE INDEX idx_tax_transactions_reverse_pair
    ON tax_transactions(reverse_charge_pair_id) WHERE reverse_charge_pair_id IS NOT NULL;
```

`tax_transactions` is never hard-deleted and never edited in place once `status = 'posted'`;
corrections happen via a new row referencing `original_transaction_id`, per the platform-wide
posted-records-are-immutable rule. `calculation_lineage` stores the full resolution trace (matched
rule id, evaluated conditions, jurisdiction chain walked, rounding applied) as JSONB so the Tax
Explanation AI responsibility and any future audit can reconstruct exactly how the amount was
derived without re-running the calculation engine against potentially-since-changed configuration.

### `tax_returns`

```sql
CREATE TABLE tax_returns (
    id                        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id                 BIGINT NOT NULL REFERENCES companies(id),
    branch_id                  BIGINT NULL REFERENCES branches(id),
    tax_registration_id        BIGINT NOT NULL REFERENCES tax_registrations(id),
    return_type                tax_return_type NOT NULL,
    period_start                DATE NOT NULL,
    period_end                  DATE NOT NULL,
    filing_currency_code        VARCHAR(3) NOT NULL,
    total_output_tax            NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_input_tax             NUMERIC(19,4) NOT NULL DEFAULT 0,
    total_adjustments           NUMERIC(19,4) NOT NULL DEFAULT 0,
    net_tax_payable             NUMERIC(19,4) NOT NULL DEFAULT 0,
    status                     tax_return_status NOT NULL DEFAULT 'draft',
    prepared_by                 BIGINT NULL REFERENCES users(id),
    reviewed_by                 BIGINT NULL REFERENCES users(id),
    approved_by                 BIGINT NULL REFERENCES users(id),
    approved_at                 TIMESTAMPTZ NULL,
    filed_by                    BIGINT NULL REFERENCES users(id),
    filed_at                    TIMESTAMPTZ NULL,
    government_reference_number VARCHAR(100) NULL,
    government_ack_payload      JSONB NULL,
    due_date                    DATE NOT NULL,
    ai_draft_generated           BOOLEAN NOT NULL DEFAULT false,
    ai_confidence_score          NUMERIC(5,4) NULL CHECK (ai_confidence_score BETWEEN 0 AND 1),
    notes                       TEXT NULL,
    created_by                   BIGINT NULL REFERENCES users(id),
    updated_by                   BIGINT NULL REFERENCES users(id),
    created_at                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at                   TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at                   TIMESTAMPTZ NULL,
    CONSTRAINT uq_tax_returns_period UNIQUE (tax_registration_id, period_start, period_end),
    CONSTRAINT chk_tax_returns_period_order CHECK (period_end >= period_start)
);
CREATE INDEX idx_tax_returns_company_status ON tax_returns(company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX idx_tax_returns_registration   ON tax_returns(tax_registration_id, period_start);
CREATE INDEX idx_tax_returns_due            ON tax_returns(due_date) WHERE status NOT IN ('filed','accepted');
```

### `tax_return_lines`

```sql
CREATE TABLE tax_return_lines (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tax_return_id      BIGINT NOT NULL REFERENCES tax_returns(id),
    line_code          VARCHAR(32) NOT NULL,
    line_label_en      VARCHAR(200) NOT NULL,
    line_label_ar      VARCHAR(200) NOT NULL,
    line_type          VARCHAR(48) NOT NULL,
    box_number         VARCHAR(16) NULL,
    taxable_amount     NUMERIC(19,4) NOT NULL DEFAULT 0,
    tax_amount         NUMERIC(19,4) NOT NULL DEFAULT 0,
    sequence           SMALLINT NOT NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_tax_return_lines UNIQUE (tax_return_id, line_code)
);
CREATE INDEX idx_tax_return_lines_return ON tax_return_lines(tax_return_id);
```

`box_number` maps a line to the specific numbered box on the jurisdiction's official return form
(e.g., ZATCA's VAT return boxes 1a–12, the UAE FTA VAT 201 boxes), so the Reports layer can render
the exact government-form layout directly from this table without a separate mapping step.
`line_type` values include `standard_rated_sales`, `zero_rated_sales`, `exempt_sales`,
`standard_rated_purchases`, `reverse_charge_output`, `reverse_charge_input`,
`partial_exemption_adjustment`, `corrections_from_prior_period`, `withholding_collected`, and
`withholding_credit_claimed`.

### Relationships summary

- `tax_jurisdictions` is self-referencing (tree) and is referenced by `tax_registrations`,
  `tax_categories`, `tax_rates`, `tax_rules`, `exemption_certificates`, `recovery_ratios`, and
  `tax_transactions`.
- `tax_categories` (1) → `tax_codes` (many) → `tax_rates` (many, effective-dated).
- `tax_rules` resolves to one or more `tax_codes` (directly, or via `tax_groups` →
  `tax_group_items` → `tax_codes`).
- `tax_transactions` is the fact table referencing `tax_codes`, `tax_rates`, `tax_rules`,
  `tax_jurisdictions`, `tax_registrations`, `exemption_certificates`, and, once filed,
  `tax_return_lines`.
- `tax_returns` (1) → `tax_return_lines` (many); `tax_return_lines` (1) → `tax_transactions` (many,
  via the transactions' `tax_return_line_id` back-reference, set only when a return moves past
  `draft` and "locks in" which transactions it covers).

### History and Versioning

Every table above carries `deleted_at` (soft delete only) and every mutable configuration table
(`tax_codes`, `tax_rates`, `tax_rules`, `tax_categories`, `tax_jurisdictions`) is additionally
covered by the platform-wide `audit_logs` table (polymorphic `auditable_type`/`auditable_id`,
before/after JSONB snapshots). `tax_rates` uses effective-dating rather than a separate history
table because effective-dating *is* the history — every superseded rate remains queryable by its
own `effective_from`/`effective_to` window rather than being archived out-of-line. `tax_returns`
uses `status = 'amended'` plus a new `tax_returns` row with `notes` referencing the prior return's
id (stored in `custom_fields` on the header if the jurisdiction's amendment process requires linking
to the original filing) rather than a separate "amendments" table, since an amended return is
itself a full, independently-auditable return.

# API

All endpoints are REST/JSON over HTTPS, versioned under `/api/v1/`, authenticated with a Sanctum/
JWT bearer token, scoped to the active company via the `X-Company-Id` header, and return the
standard platform response envelope (`success`, `data`, `message`, `errors`, `meta.pagination`,
`request_id`, `timestamp`). Every write endpoint enforces the permission listed and runs its
FormRequest validation before reaching the Service layer.

### Endpoint Table

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/tax/jurisdictions` | `tax.read` | List jurisdictions, filterable by `region_type`, `parent_id` |
| POST | `/api/v1/tax/jurisdictions` | `tax.jurisdiction.manage` | Create a jurisdiction |
| PUT | `/api/v1/tax/jurisdictions/{id}` | `tax.jurisdiction.manage` | Update a jurisdiction |
| GET | `/api/v1/tax/registrations` | `tax.read` | List the company's tax registrations |
| POST | `/api/v1/tax/registrations` | `tax.registration.manage` | Register the company in a jurisdiction/system |
| GET | `/api/v1/tax/categories` | `tax.read` | List tax categories |
| POST | `/api/v1/tax/categories` | `tax.config.manage` | Create a tax category, including GL account mapping |
| GET | `/api/v1/tax/codes` | `tax.read` | List tax codes, filterable by `tax_category_id`, `status` |
| POST | `/api/v1/tax/codes` | `tax.config.manage` | Create a tax code |
| PUT | `/api/v1/tax/codes/{id}` | `tax.config.manage` | Update a tax code |
| GET | `/api/v1/tax/rates` | `tax.read` | List rates, filterable by `tax_code_id`, `jurisdiction_id`, `as_of_date` |
| POST | `/api/v1/tax/rates` | `tax.rate.manage` | Create a new effective-dated rate |
| POST | `/api/v1/tax/rates/schedule-change` | `tax.rate.manage` | Atomically close the current rate and open a new one on a future effective date |
| GET | `/api/v1/tax/rules` | `tax.read` | List tax rules by priority |
| POST | `/api/v1/tax/rules` | `tax.rule.manage` | Create a tax rule |
| PUT | `/api/v1/tax/rules/{id}/reorder` | `tax.rule.manage` | Change rule priority |
| GET | `/api/v1/tax/groups` | `tax.read` | List tax groups and their members |
| POST | `/api/v1/tax/groups` | `tax.config.manage` | Create a compound/stacked tax group |
| GET | `/api/v1/tax/exemption-certificates` | `tax.read` | List exemption certificates, filterable by `customer_id`, `vendor_id`, `status` |
| POST | `/api/v1/tax/exemption-certificates` | `tax.exemption.manage` | Upload/register an exemption certificate |
| GET | `/api/v1/tax/recovery-ratios` | `tax.read` | List recovery ratios by fiscal year |
| POST | `/api/v1/tax/recovery-ratios` | `tax.recovery.manage` | Set/approve a partial-exemption recovery ratio |
| POST | `/api/v1/tax/calculate` | `tax.calculate` | Calculate tax for a transaction (preview or commit mode) |
| GET | `/api/v1/tax/transactions` | `tax.read` | List posted tax transactions, filterable by period/jurisdiction/direction |
| POST | `/api/v1/tax/transactions/{id}/reverse` | `tax.adjust` | Reverse a posted tax transaction |
| POST | `/api/v1/tax/adjustments` | `tax.adjust` | Create a manual tax adjustment |
| GET | `/api/v1/tax/returns` | `tax.filing.read` | List tax returns by status/period |
| POST | `/api/v1/tax/returns` | `tax.filing.prepare` | Generate a draft return for a period |
| GET | `/api/v1/tax/returns/{id}` | `tax.filing.read` | Retrieve a return with all lines |
| POST | `/api/v1/tax/returns/{id}/submit-for-review` | `tax.filing.prepare` | Move draft → under_review |
| POST | `/api/v1/tax/returns/{id}/approve` | `tax.filing.approve` | Move under_review → ready_to_file (human approval, mandatory) |
| POST | `/api/v1/tax/returns/{id}/file` | `tax.submit` | Move ready_to_file → filed; submits to government API where available (human approval, mandatory, never AI-only) |
| POST | `/api/v1/tax/returns/{id}/record-ack` | `tax.submit` | Attach the government acknowledgment/reference number |
| GET | `/api/v1/tax/reports/vat-report` | `reports.export` + `tax.filing.read` | Generate the VAT report for a period |
| GET | `/api/v1/tax/reports/liability` | `reports.export` + `tax.filing.read` | Generate the Tax Liability report |
| GET | `/api/v1/tax/reports/audit-report` | `reports.export` + `tax.audit.read` | Generate the full Tax Audit Report |
| POST | `/api/v1/tax/validate` | `tax.read` | Run validation checks against a document without persisting |
| POST | `/api/v1/tax/import` | `tax.config.manage` | Bulk-import a jurisdiction's country pack (codes/rates/rules) |
| GET | `/api/v1/tax/export` | `tax.read` | Bulk-export the current tax configuration as a portable JSON pack |
| POST | `/api/v1/tax/bulk/recalculate` | `tax.adjust` | Re-run calculation across a date range in preview mode for reconciliation (never auto-commits) |
| GET | `/api/v1/tax/ai/proposals` | `tax.read` | List pending AI classification/optimization proposals |
| POST | `/api/v1/tax/ai/proposals/{id}/approve` | `tax.config.manage` | Approve and apply an AI proposal |
| POST | `/api/v1/tax/ai/proposals/{id}/reject` | `tax.config.manage` | Reject an AI proposal with a reason |

### Example: `POST /api/v1/tax/calculate` (preview mode)

Request:

```json
{
  "mode": "preview",
  "transaction_type": "sale",
  "transaction_date": "2026-07-16",
  "currency_code": "SAR",
  "ship_from": { "jurisdiction_code": "SA-RIY" },
  "ship_to": { "jurisdiction_code": "SA-RIY" },
  "customer": { "id": 4821, "tax_status": "registered" },
  "lines": [
    {
      "line_ref": "L1",
      "product_id": 9931,
      "product_tax_treatment": "standard",
      "quantity": 4,
      "unit_price": 250.0000,
      "price_treatment": "exclusive"
    },
    {
      "line_ref": "L2",
      "product_id": 9944,
      "product_tax_treatment": "zero_rated",
      "quantity": 1,
      "unit_price": 1200.0000,
      "price_treatment": "exclusive"
    }
  ]
}
```

Response:

```json
{
  "success": true,
  "data": {
    "lines": [
      {
        "line_ref": "L1",
        "taxable_base_amount": "1000.0000",
        "tax_code": "SA-VAT-STD",
        "jurisdiction": "SA",
        "tax_rate_percentage": "15.0000",
        "tax_amount": "150.0000",
        "line_total": "1150.0000",
        "rule_matched": { "tax_rule_id": 12, "priority": 10 }
      },
      {
        "line_ref": "L2",
        "taxable_base_amount": "1200.0000",
        "tax_code": "SA-VAT-ZERO",
        "jurisdiction": "SA",
        "tax_rate_percentage": "0.0000",
        "tax_amount": "0.0000",
        "line_total": "1200.0000",
        "rule_matched": { "tax_rule_id": 47, "priority": 5 }
      }
    ],
    "totals": {
      "taxable_base_amount": "2200.0000",
      "total_tax_amount": "150.0000",
      "grand_total": "2350.0000",
      "currency_code": "SAR"
    }
  },
  "message": "Tax calculated (preview — not persisted)",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "a1c9f2de-77b1-4e21-9b6a-df3ac1f4e001",
  "timestamp": "2026-07-16T09:12:44Z"
}
```

### Example: `POST /api/v1/tax/rates/schedule-change`

Request:

```json
{
  "tax_code_id": 205,
  "jurisdiction_id": 3,
  "new_rate_percentage": "15.0000",
  "effective_from": "2020-07-01",
  "legal_reference": "Royal Decree No. (M/113), 11 May 2020",
  "close_current_rate": true
}
```

Response:

```json
{
  "success": true,
  "data": {
    "closed_rate": {
      "id": 88,
      "rate_percentage": "5.0000",
      "effective_from": "2018-01-01",
      "effective_to": "2020-06-30"
    },
    "new_rate": {
      "id": 214,
      "rate_percentage": "15.0000",
      "effective_from": "2020-07-01",
      "effective_to": null,
      "status": "scheduled"
    }
  },
  "message": "Rate change scheduled; both rows committed atomically",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "5b2e9a10-44cd-4f60-8a3e-1c9d7e5b2f10",
  "timestamp": "2026-07-16T09:20:03Z"
}
```

### Example: `POST /api/v1/tax/returns/{id}/file` (human-approved submission)

Request:

```json
{
  "approved_by_confirmation": true,
  "approver_notes": "Reviewed against GL trial balance for the period; variance within tolerance.",
  "submit_to_government_api": true
}
```

Response:

```json
{
  "success": true,
  "data": {
    "tax_return_id": 9021,
    "status": "filed",
    "filed_by": 118,
    "filed_at": "2026-07-16T10:04:11Z",
    "government_reference_number": "ZATCA-VAT-2026-06-SA-000481223",
    "government_ack_payload": {
      "acknowledged_at": "2026-07-16T10:04:19Z",
      "portal": "ZATCA e-Filing",
      "status_code": "ACCEPTED"
    }
  },
  "message": "Return filed and acknowledged by the government portal",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "e6d4a301-9f22-4b17-9d0e-2a6c7f5e4c33",
  "timestamp": "2026-07-16T10:04:20Z"
}
```

### Example: `GET /api/v1/tax/reports/liability?jurisdiction_id=3&period=2026-06`

Response:

```json
{
  "success": true,
  "data": {
    "jurisdiction": "Saudi Arabia — VAT",
    "period": "2026-06",
    "output_tax": "184250.0000",
    "input_tax_recoverable": "61200.0000",
    "input_tax_non_recoverable": "3400.0000",
    "net_payable": "123050.0000",
    "currency_code": "SAR",
    "as_of": "2026-07-16T09:00:00Z"
  },
  "message": "Tax liability computed from posted tax_transactions",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "77f0e5c2-1b3a-4a9e-8d6f-9c2e0a4f7b18",
  "timestamp": "2026-07-16T09:00:01Z"
}
```

### Example: `POST /api/v1/tax/validate` (validation error response)

Request:

```json
{
  "transaction_type": "purchase",
  "transaction_date": "2026-07-16",
  "vendor": { "id": 663, "country_code": "SA", "tax_registration_number": null },
  "lines": [
    { "product_id": 771, "product_tax_treatment": "standard", "quantity": 1, "unit_price": "5000.0000", "vendor_charged_tax_amount": "750.0000" }
  ]
}
```

Response (HTTP 422):

```json
{
  "success": false,
  "data": null,
  "message": "Validation failed",
  "errors": [
    {
      "code": "UNREGISTERED_VENDOR_CHARGED_TAX",
      "field": "lines[0].vendor_charged_tax_amount",
      "detail": "Vendor 663 has no tax_registration_number on file but charged SAR 750.00 VAT. Only VAT-registered vendors may charge VAT under KSA law. Verify the vendor's registration before posting this bill."
    }
  ],
  "meta": { "pagination": null },
  "request_id": "1f4a6d90-8e2c-4a11-9d3b-6a7f0c5e8d22",
  "timestamp": "2026-07-16T09:31:07Z"
}
```

### Bulk Operations

`POST /api/v1/tax/import` accepts a versioned country-pack JSON payload (`{"pack_version": "1.2.0",
"jurisdiction_code": "SA", "categories": [...], "codes": [...], "rates": [...], "rules": [...]}`)
and processes it inside a single database transaction, validating every foreign reference before
any row is written, so a malformed pack never leaves the tax configuration in a partially-applied
state. `GET /api/v1/tax/export` produces the same format from the current configuration, primarily
for migrating configuration between a sandbox/staging company and production, or for handing a
configuration snapshot to an external auditor. `POST /api/v1/tax/bulk/recalculate` is the
reconciliation tool referenced in Performance: it re-runs the calculation engine over a date range
in `preview` mode only and returns a diff against what is actually posted, never committing changes
itself — any correction it surfaces still goes through the normal `tax.adjust`-gated adjustment
flow.

# Reports

Every report below is generated from `tax_transactions` (fast path) with an optional
cross-check against `ledger_entries` filtered to the mapped GL accounts (authoritative path, used
automatically whenever a report is generated for a period that is about to be filed). All reports
support `format=json|pdf|xlsx|xml` where `xml` is used specifically for government e-filing formats
that require XML (several ZATCA and FTA submission channels).

| Report | Purpose | Key Columns / Sections | Primary Consumer |
|---|---|---|---|
| VAT Report | Statutory VAT return content, box-by-box, ready to file | Standard-rated sales, zero-rated sales, exempt sales, standard-rated purchases, reverse-charge output/input, corrections, net payable | Tax Manager, government portal |
| GST Report | Statutory GST return, with CGST/SGST/IGST split where applicable | Taxable supplies by GST component, input tax credit claimed, net GST payable | Tax Manager, government portal |
| Sales Tax Report | Per-jurisdiction (state/county/city) sales tax collected, mapped to nexus registrations | Gross sales, taxable sales, exempt sales, tax collected, per stacked jurisdiction | Tax Manager (US-model tenants) |
| Withholding Report | Withholding tax withheld from vendors/employees this period, with certificate numbers generated | Vendor/employee, gross payment, withholding rate, withheld amount, certificate number | Tax Manager, vendors/employees (certificate copy) |
| Corporate Tax Report | Taxable income computation from GL: accounting profit, add-backs, deductions, tax-loss carryforward applied, tax payable | Accounting profit, non-deductible add-backs, allowable deductions, taxable income, tax rate, tax payable, Zakat computation where applicable | CFO, Tax Manager, auditors |
| Tax Summary | Cross-jurisdiction, cross-system-type single-page overview for management | Output/input tax by jurisdiction, net position, trend vs. prior period | CFO, Owner |
| Tax Liability | Real-time "what do we currently owe" across all active registrations | Net payable per registration, due date, days remaining, status | Tax Manager, Treasury Manager |
| Tax Audit Report | Full transaction-level trail for a period, exportable for a government audit | Every `tax_transactions` row with full lineage (rule, rate, rounding, exemption), reconciled against GL | Auditor, External Auditor, Tax Manager |
| Compliance Report | Registration coverage, certificate expiries, filing calendar adherence, open validation warnings | Jurisdictions with obligations vs. active registrations, certificates expiring within 30/60/90 days, overdue/at-risk returns | Compliance Agent (auto-generated weekly), Tax Manager |

### VAT Report — example structure (KSA, monthly)

```json
{
  "jurisdiction": "Saudi Arabia",
  "registration_number": "3001234567",
  "period": "2026-06-01 to 2026-06-30",
  "boxes": [
    { "box": "1a", "label": "Standard rated domestic sales", "taxable_amount": "1120000.0000", "tax_amount": "168000.0000" },
    { "box": "1b", "label": "Standard rated domestic sales — reverse charge", "taxable_amount": "45000.0000", "tax_amount": "6750.0000" },
    { "box": "2", "label": "Sales to registered customers in other GCC states", "taxable_amount": "0.0000", "tax_amount": "0.0000" },
    { "box": "3", "label": "Zero-rated domestic sales", "taxable_amount": "212000.0000", "tax_amount": "0.0000" },
    { "box": "4", "label": "Exports", "taxable_amount": "398000.0000", "tax_amount": "0.0000" },
    { "box": "5", "label": "Exempt sales", "taxable_amount": "18000.0000", "tax_amount": "0.0000" },
    { "box": "9", "label": "Standard rated domestic purchases", "taxable_amount": "408000.0000", "tax_amount": "61200.0000" },
    { "box": "10", "label": "Imports subject to VAT paid at customs", "taxable_amount": "0.0000", "tax_amount": "0.0000" },
    { "box": "11", "label": "Imports subject to VAT accounted for through reverse charge", "taxable_amount": "45000.0000", "tax_amount": "6750.0000" },
    { "box": "12", "label": "Total input VAT recoverable", "taxable_amount": null, "tax_amount": "67950.0000" }
  ],
  "net_vat_payable": "106800.0000",
  "generated_at": "2026-07-16T09:00:00Z",
  "reconciled_against_gl": true,
  "gl_variance": "0.0000"
}
```

### Tax Audit Report — row shape

Each row exposes: `tax_transaction_id`, `source_document_type` + `source_document_number` (human
document number, not internal id), `tax_point_date`, `counterparty_name`, `tax_code`,
`jurisdiction`, `taxable_base_amount`, `rate_applied`, `tax_amount`, `tax_direction`,
`is_reverse_charge`, `recoverable_amount`, `non_recoverable_amount`, `exemption_certificate_number`
(if any), `ai_confidence_score` (if AI-classified), `posted_journal_entry_number`, and
`matches_gl` (boolean, from the reconciliation cross-check). Auditors can filter by any column and
export the filtered set, satisfying the "produce a complete list of all VAT transactions for the
period with supporting detail" request that is a standard first step in any GCC VAT audit.

# Permissions

Permission keys follow the platform naming convention `<area>.<action>` and `<area>.<entity>.
<action>`. Every permission below defaults to **deny** for a role unless explicitly granted; the
table lists only the default-role grants QAYD ships, which a company's Owner/Admin can further
customize per company.

| Permission Key | Description | Owner | Admin | Finance Manager | Tax Manager | Accountant | Auditor | AI Agent |
|---|---|---|---|---|---|---|---|---|
| `tax.read` | View tax configuration, transactions, returns | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ (read-only, own proposals) |
| `tax.calculate` | Invoke the calculation engine (preview or via document commit) | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ (system-to-system, no human data entry) |
| `tax.jurisdiction.manage` | Create/edit jurisdictions | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `tax.registration.manage` | Create/edit tax registrations | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `tax.config.manage` | Create/edit tax categories and codes; approve AI classification proposals | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ (proposes, does not approve) |
| `tax.rate.manage` | Create/schedule effective-dated tax rates | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `tax.rule.manage` | Create/reorder tax rules | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `tax.exemption.manage` | Register/revoke exemption certificates | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `tax.recovery.manage` | Set/approve partial-exemption recovery ratios | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `tax.adjust` | Create manual tax adjustments; reverse posted tax transactions | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ (proposes only, via `ai/proposals`) |
| `tax.filing.read` | View draft/filed returns | ✅ | ✅ | ✅ | ✅ | ✅ (own branch) | ✅ | ✅ (read-only) |
| `tax.filing.prepare` | Generate draft returns; move draft → under_review | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ✅ (drafts only, never submits) |
| `tax.filing.approve` | Approve a return (under_review → ready_to_file) | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| `tax.submit` | **File a return with the government (ready_to_file → filed) — sensitive, human-only, never AI-only** | ✅ | ✅ | ✅ (if delegated) | ✅ | ❌ | ❌ | ❌ (structurally forbidden) |
| `tax.audit.read` | View the full Tax Audit Report and raw transaction lineage | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ (read-only) |
| `reports.export` | Export any tax report to PDF/XLSX/XML | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |

Two additional roles referenced by the platform's default role list interact with Tax Management at
a narrower scope not shown as full columns above: **CEO**/**CFO** typically inherit Finance
Manager's tax permissions plus visibility into the Tax Summary and Tax Liability reports for
board-level reporting; **External Auditor** is granted `tax.read`, `tax.filing.read`, and
`tax.audit.read` only, scoped to a time-boxed engagement (`company_users.access_expires_at`), and
never any write permission — an external auditor can see everything but change nothing.

`tax.submit` is the module's single most sensitive permission and is enforced at three layers
simultaneously: the Laravel policy layer (403 if the user lacks the permission), the state-machine
layer (`tax_returns.status` transition guard rejects the request unless it originates from a human
session — API requests bearing only a service/AI-layer token are rejected outright regardless of
any permission grant, per the platform rule that sensitive operations are never AI-only), and the
UI layer (the "File Return" button in the Next.js Tax Manager dashboard is hidden entirely for
users without the permission, and shows a second confirmation modal summarizing the amount and
period before submission).

# Notifications

Tax Management emits notifications through the foundation `notifications` table and, where
configured, Laravel Reverb for real-time in-app delivery. Notification categories:

- **Filing calendar reminders:** 30/14/7/1 days before a `tax_returns.due_date`, escalating in
  urgency, sent to everyone with `tax.filing.prepare` or `tax.submit` on the relevant registration;
  the 1-day reminder is also sent to the Owner/Admin if the return is still in `draft` or
  `under_review`.
- **Rate change alerts:** when a Regulation Change Alert (AI Responsibilities) is generated, and
  separately when a Tax Manager schedules a rate change taking effect within the next 30 days, sent
  to Tax Manager, Finance Manager, and Accountant roles so front-line staff know a rate is about to
  shift.
- **Validation warnings:** real-time, in-app, to the user who triggered the transaction
  (unregistered vendor charging tax, missing exemption certificate, tax code inconsistent with
  historical pattern) — these are actionable notifications with a direct link back to the offending
  document.
- **Certificate expiry:** 30/7 days before an `exemption_certificates.valid_to`, sent to Tax Manager
  and to the Accountant who most recently transacted with that customer/vendor.
- **Anomaly and risk digests:** a scheduled weekly digest (configurable per company) summarizing
  AI-flagged anomalies and the current per-jurisdiction Tax Risk Score, sent to Tax Manager, Finance
  Manager, and CFO.
- **Return status changes:** every state transition (`draft → under_review`, `under_review →
  ready_to_file`, `ready_to_file → filed`, and any `accepted`/`rejected` government response) fires
  a notification to the return's `prepared_by`, `reviewed_by`, and `approved_by` users, plus the
  Owner for the `filed` and `rejected` transitions specifically, since those carry legal/financial
  consequence.
- **AI proposal digest:** a daily digest of auto-applied Tax Classification proposals (confidence
  ≥0.90) for spot-check, and a real-time notification for any proposal requiring manual review
  (confidence 0.70–0.89), sent to users with `tax.config.manage`.
- **Webhook events:** mirroring the platform's domain-event convention, Tax Management fires
  `tax.transaction.posted`, `tax.return.status_changed`, `tax.return.filed`,
  `tax.rate.scheduled`, and `tax.certificate.expiring` webhooks for external system integration
  (e.g., pushing filed-return confirmations into a company's own document management system).

# Security

- **Tenant isolation.** Every query against every table in this module is scoped by `company_id`
  through a global Eloquent scope applied at the model base class, never left to individual
  controller logic to remember; Postgres Row-Level Security policies provide a second,
  database-enforced layer so that even a bug in the application layer cannot leak Company A's tax
  rates or transactions to Company B.
- **Field-level sensitivity.** Tax registration numbers, withholding certificate numbers, and
  exemption certificate numbers are treated as sensitive identifiers: they are never included in
  webhook payloads sent to third-party endpoints unless that endpoint is the specific government
  e-filing integration it belongs to, and access to `tax_registrations.registration_number` in the
  API response is itself gated by `tax.read` (not exposed on any public/unauthenticated endpoint).
- **Government API credentials.** OAuth tokens/API keys for ZATCA, FTA, or any other government
  e-filing integration are stored encrypted at rest (application-level encryption using Laravel's
  encrypted casts, backed by a company-specific key derived from the platform's KMS-managed master
  key) and are never returned in any API response, including to users with `tax.submit` — the
  credential is used server-side only, by the filing job, and rotated on a schedule independent of
  user action.
- **Immutable posted transactions.** `tax_transactions` rows with `status = 'posted'` are protected
  by a database trigger (`prevent_posted_tax_transaction_mutation`) that raises an exception on any
  `UPDATE` to `tax_amount`, `taxable_base_amount`, `tax_code_id`, or `tax_rate_id` once posted,
  independent of application-layer discipline — even a direct, mistaken SQL `UPDATE` run by an
  engineer with database access cannot silently alter a posted tax figure; the only path to change
  it is a new row referencing `original_transaction_id`.
- **Signed filing payloads.** Where a government API requires a cryptographically signed submission
  (ZATCA's e-invoicing chain, which requires each invoice to carry a hash of the previous invoice
  and a digital signature under a ZATCA-issued cryptographic certificate — see Compliance), the
  signing key material is stored in the same encrypted-secrets store as other government
  credentials and signing operations happen inside a dedicated, narrowly-scoped signing service, not
  inline in general application code, to minimize the blast radius of any future vulnerability.
- **Least-privilege AI access.** The AI Layer's service account holds only `tax.read` and
  `tax.calculate` (for preview-mode calculations feeding its recommendations) plus a narrow
  `tax.ai.propose` permission that allows writing to the `tax_ai_proposals` staging table only —
  it structurally cannot hold `tax.submit`, `tax.rate.manage`, or any other mutating permission on
  the authoritative tables, enforced by the permission seeder refusing to ever assign those keys to
  the `ai_agent` role type.
- **Rate limiting and abuse prevention.** `/api/v1/tax/calculate` in preview mode is rate-limited
  per user (600 requests/minute) since it is called live as users type in the UI; `tax:submit` and
  government-facing endpoints are rate-limited far more conservatively (10 requests/minute) and
  additionally require a fresh re-authentication (step-up auth, password or MFA re-prompt) if the
  user's session is older than 30 minutes, given the irreversibility of the action.

# Audit Logs

Every mutation in Tax Management writes to the foundation `audit_logs` table with the standard
shape (`auditable_type`, `auditable_id`, `actor_type` [`user`|`ai_agent`|`system`], `actor_id`,
`action`, `old_values` JSONB, `new_values` JSONB, `reason`, `ip_address`, `device_info`,
`created_at`). In addition to the generic mutation log, Tax Management maintains three
domain-specific audit surfaces:

1. **Rate change history** — every `tax_rates` insert/close pair from `scheduleRateChange()` is
   logged as a single logical audit event (not two disconnected inserts), carrying the
   `legal_reference` field so a reviewer can see not just *that* a rate changed but *why*
   (which decree, circular, or internal policy decision triggered it).
2. **Return lifecycle history** — every state transition on `tax_returns` is logged with the acting
   user, timestamp, and any `approver_notes`/`reason` supplied, forming a complete, non-repudiable
   chain from `draft` to `filed` that satisfies the "who approved this filing and when" question any
   external auditor or tax authority will ask.
3. **AI decision log** — every AI proposal (classification suggestion, optimization recommendation,
   anomaly flag) is logged at creation with its full `reasoning` and `confidence_score`, and again
   at disposition (approved/rejected/auto-applied/expired-unactioned), so the complete AI decision
   trail — not just the final outcome — is reconstructable. This is distinct from, and in addition
   to, the `calculation_lineage` JSONB stored directly on each `tax_transactions` row.

Audit logs in this module are, like `tax_transactions`, never hard-deleted, retained for a minimum
of 10 years by default (configurable upward per jurisdiction — KSA's ZATCA regulations currently
require e-invoice and supporting record retention for at least 6 years, Kuwait's general commercial
record-keeping norms extend to 10; QAYD defaults to the longer of any applicable jurisdiction's
requirement across the company's registrations), and are exportable in bulk for a government audit
via the same `tax.audit.read` permission that gates the Tax Audit Report.

# Performance

- **Materialized reporting path.** `ledger_entries` (the platform's GL projection) and a
  module-owned materialized view, `mv_tax_liability_by_period`, pre-aggregate `tax_transactions` by
  `(company_id, jurisdiction_id, tax_direction, period)` so the Tax Liability report and dashboard
  widgets render in constant time regardless of transaction volume; the materialized view refreshes
  incrementally on every `tax.transaction.posted` event rather than a full nightly rebuild, keeping
  it near-real-time without a heavy batch job.
- **Calculation engine caching.** The resolved `tax_rules` set and the currently-effective
  `tax_rates` for a company's active jurisdictions are cached in Redis (keyed by `company_id`,
  invalidated on any write to `tax_rules`/`tax_rates`/`tax_codes`/`tax_categories`) so the hot path
  — calculating tax on every invoice line as a user types — never round-trips to Postgres for
  configuration lookups; only the final commit-mode calculation writes to the database.
- **Indexing strategy.** Beyond the indexes listed in Database Design, `tax_transactions` is
  partitioned by `RANGE (tax_point_date)` on a yearly boundary for companies exceeding 5 million
  tax transactions in a rolling 12-month window (a threshold configurable per deployment), keeping
  period-scoped queries (which are the overwhelming majority — reports, returns, reconciliation all
  filter by period) fast without needing to scan the full historical table; older partitions can be
  moved to cheaper storage tiers without affecting current-period performance.
- **Reconciliation job cost control.** The GL-vs-`tax_transactions` reconciliation check
  (Accounting Integration → General Ledger) runs incrementally per posted journal entry rather than
  as a full-table nightly sweep; a full-table reconciliation is available on demand (via
  `/api/v1/tax/bulk/recalculate`) but is queued as a background job (Redis queue,
  `low-priority-reconciliation` queue) so it never competes with live transaction posting for
  database resources.
- **Bulk import/export performance.** Country-pack imports (`POST /api/v1/tax/import`) that touch
  more than 500 rows are processed via `COPY`-style bulk insert (Laravel's `insertOrIgnore` in
  chunks of 1,000 wrapped in a single transaction) rather than row-by-row Eloquent creates, keeping
  a full country pack's onboarding (typically 200–2,000 rate/rule rows) under a few seconds even for
  the most complex jurisdictions.
- **Report generation SLAs.** VAT/GST/Sales Tax reports for a single period are targeted at
  sub-second generation from the materialized view; the full Tax Audit Report for a 12-month period
  with 100,000+ transaction rows is targeted at under 10 seconds server-side, with the response
  streamed (chunked JSON/CSV) rather than buffered entirely in memory, and the PDF/XLSX rendering
  paths run as an async job with a webhook/notification on completion for large exports rather than
  blocking the HTTP request.

# Compliance

### IFRS

Tax balances follow IFRS presentation and measurement where relevant: IAS 12 (Income Taxes) governs
current and deferred tax accounting for Corporate Tax — QAYD's Corporate Tax Report computes not
only the current-period tax payable but also the deferred tax impact of temporary differences
(e.g., accelerated tax depreciation vs. straight-line book depreciation), producing a
`deferred_tax_asset`/`deferred_tax_liability` figure posted to its own GL accounts distinct from the
current Corporate Tax Payable. VAT/GST/sales-tax balances are presented as current
assets/liabilities per IAS 1, generally net-presented for external reporting (Financial Statements
→ balance sheet) while remaining gross in the underlying ledger.

### GAAP

For companies reporting under US GAAP rather than IFRS (relevant for any US-incorporated entity or
subsidiary in a company's structure), the same deferred-tax mechanics apply under ASC 740 rather
than IAS 12; QAYD's `tax_categories`/`fiscal_years` configuration carries an `accounting_framework`
flag (`ifrs`|`us_gaap`) that selects which standard's specific disclosure requirements the
Corporate Tax Report's notes section follows — the underlying calculation of the temporary
difference itself is identical, only the disclosure format and a small number of measurement
elections (e.g., uncertain tax position reserve methodology, ASC 740-10 vs. IAS 12's less
prescriptive approach) differ.

### Country Specific Regulations

Each jurisdiction's specific statutory requirements are encoded in `tax_jurisdictions.
compliance_profile` (JSONB) and in the country-pack fixtures referenced in Multi Country Support.
Examples of what this profile captures: mandatory invoice fields (VAT number display requirements,
QR code requirements), filing frequency defaults and thresholds (e.g., a small-business simplified
filing threshold under which quarterly rather than monthly filing is permitted), rounding
conventions, and any mandatory sequential/gapless invoice numbering requirement (several GCC VAT
laws require tax invoice numbers to be sequential without gaps — the platform's invoice numbering
scheme is checked by the Compliance Agent for gaps whenever a document is voided, since voiding
must never remove a number from the sequence, only mark it void).

### Electronic Filing

Where a jurisdiction offers a government e-filing API or a structured file-upload channel, Tax
Management integrates it behind the same `POST /api/v1/tax/returns/{id}/file` endpoint so the human
approval workflow is identical regardless of destination — the difference is entirely in the
adapter behind that endpoint (`ZatcaFilingAdapter`, `FtaFilingAdapter`, a generic
`ManualFilingAdapter` that produces a downloadable XML/PDF package for jurisdictions without an API,
for the human filer to upload manually). Every adapter's response (success, rejection, partial
acceptance) is normalized into the same `government_ack_payload` JSONB shape, and any rejection
routes the return back to `under_review` automatically with the rejection reason attached, rather
than leaving it stuck in a `filed`-but-actually-failed limbo state.

### Government APIs — GCC VAT, Kuwait, and KSA ZATCA E-Invoicing

- **GCC VAT framework.** All GCC states operate under the shared GCC VAT Framework Agreement, which
  QAYD's jurisdiction/category model reflects directly: standard rate, zero-rating for exports and
  specified supplies, exemption for specified financial services/real estate/healthcare/education
  categories (varying by state's domestic implementing law), and reverse charge for cross-border
  B2B. The engine's jurisdiction tree and `tax_rules` conditions are expressive enough to encode each
  state's specific implementing variations (UAE's Designated Zone rules, KSA's specific exemption
  list, Bahrain's phased exemption removals) without needing separate calculation code per state.
- **Kuwait-ready.** Kuwait has not yet implemented a domestic VAT as of this specification, but
  the platform is fully provisioned for it: a `KW` country jurisdiction, a `tax_categories` row of
  `system_type = 'vat'` with `status = 'inactive'` and a placeholder 0% rate, ready to be activated
  (status flipped to active, a real statutory rate inserted with the legislated `effective_from`)
  the day Kuwait's VAT law takes effect, with zero application code changes required — only a
  country-pack update. In the interim, Kuwait transactions correctly flow through the corporate tax
  system (15% on foreign corporate bodies' Kuwait-source profits, administered by Kuwait's Ministry
  of Finance / Tax Department) and, where applicable, Zakat for GCC-national-owned entities.
- **KSA ZATCA e-invoicing.** Saudi Arabia's e-invoicing (Fatoora) mandate has two phases QAYD
  supports natively: **Phase 1 (Generation)** requires every tax invoice to be generated
  electronically in a structured format (XML or PDF/A-3 with embedded XML) containing a QR code
  encoding seller name, VAT number, timestamp, invoice total, and VAT total — QAYD's invoice
  rendering layer generates this QR code automatically for every KSA-jurisdiction invoice, reading
  the mandatory fields directly from the `invoices`/`tax_transactions` rows, no manual entry.
  **Phase 2 (Integration)** requires invoices to be transmitted to ZATCA's platform in real time
  (for simplified/B2C invoices, reporting within 24 hours; for standard/B2B invoices, clearance
  *before* the invoice reaches the customer) via a cryptographically signed XML payload that
  includes a hash chain linking each invoice to the previous one (`previous_invoice_hash`, stored on
  `invoices.zatca_metadata` JSONB) and a digital signature under a ZATCA-issued cryptographic
  stamp/certificate provisioned per company during ZATCA onboarding. QAYD's `ZatcaFilingAdapter`
  handles the clearance/reporting call synchronously at invoice-posting time for Phase 2 companies
  (blocking invoice finalization until ZATCA clears it, per the mandate's requirement that a cleared
  invoice — with ZATCA's cryptographic stamp added — is the only valid version to send the
  customer), and asynchronously (within the 24-hour window) for the simplified/B2C reporting path.
  Any ZATCA rejection (malformed schema, expired certificate, hash-chain break) is surfaced as a
  blocking validation error on the invoice itself, not merely a background warning, since an
  uncleared invoice cannot legally be issued to the customer under Phase 2.

# Edge Cases

- **Mid-transaction rate change.** An invoice is drafted on 30 June 2020 (5% VAT era) but not
  posted/committed until 2 July 2020 (15% VAT era, effective 1 July 2020). The engine calculates tax
  at commit time using the transaction's designated tax point date (which, per most VAT laws, is the
  invoice date the user sets, not the system clock) — if the user back-dates the invoice to 30 June,
  5% applies and a validation warning notes the invoice was committed after its tax point date,
  flagged for the Compliance Agent's late-invoicing risk check; if the invoice date is legitimately
  2 July, 15% applies.
- **Product reclassified after transactions already posted.** A product is reclassified from
  `standard` to `zero_rated` (e.g., a regulatory change adds it to a zero-rated goods list). Past
  `tax_transactions` rows are never retroactively recalculated — they remain correct-as-of-their-
  own-effective-rate. Only new transactions dated on/after the reclassification's effective date use
  the new treatment. If the reclassification is itself retroactive by law (rare, but occurs with
  some ministerial clarifications), the correction is handled as a bulk manual adjustment batch
  with `tax.adjust`, never a silent backdated edit to the historical rows.
- **Customer's exemption certificate expires between order and invoice.** A sales order is created
  while the customer's diplomatic exemption certificate is valid; by the time the order converts to
  an invoice, the certificate has expired. The engine re-evaluates exemption validity at invoice
  commit time (not at order creation time), so the invoice correctly charges tax and the sales
  employee is notified with a clear reason ("Exemption certificate CERT-2201 expired 2026-06-30;
  standard VAT applied") rather than silently changing the price the customer expected.
- **Partial return / partial credit note.** A customer returns 3 of 10 units invoiced. The credit
  note's tax line is calculated as exactly 3/10 of the original invoice's tax amount at the
  original invoice's rate (via `original_transaction_id` lineage), not recalculated fresh — this
  matters when the return happens in a period where the rate has since changed.
- **Negative net tax position (refund due from authority).** When `total_input_tax >
  total_output_tax` for a period, `tax_returns.net_tax_payable` is negative, representing a refund
  claim rather than a payment obligation; the Tax Liability report and dashboard render this
  distinctly (a "Refund Due" badge rather than an "Amount Owed" badge), and the filing workflow
  routes to the jurisdiction's specific refund-claim process (some jurisdictions auto-carry-forward
  a negative position to the next period rather than issuing a cash refund below a threshold — this
  behavior is itself a `tax_jurisdictions.compliance_profile` setting).
- **Multi-currency invoice where the exchange rate used for tax differs from the rate used for
  revenue recognition.** Some jurisdictions mandate a specific exchange-rate source/timing for VAT
  purposes (e.g., the central bank's official rate on the invoice date) that may differ from the
  company's own treasury-policy rate used for revenue recognition. `tax_transactions.exchange_rate`
  is captured independently from the source document's own `exchange_rate`, allowing the two to
  differ when a jurisdiction's compliance profile mandates a specific tax-rate source, with the
  resulting difference posted to a small, clearly-labeled "Tax FX Basis Difference" account rather
  than blended into either the revenue-recognition FX gain/loss or the tax expense.
- **Reverse charge on a partially-exempt buyer.** A partially-exempt buyer (60% recovery ratio)
  imports a service subject to reverse charge. The self-assessed output leg is charged in full (100%
  of the calculated VAT, since output tax is never reduced by the buyer's own recovery position);
  the input leg is split 60% recoverable / 40% non-recoverable per the buyer's ratio — meaning a
  partially-exempt company's reverse-charge imports have a genuine net VAT cost (the non-recoverable
  40%), unlike a fully-taxable company for whom reverse charge nets to zero GL impact.
- **Voided invoice after tax has already been included in a filed return.** An invoice is voided
  after its period's return has already moved to `filed`. The void cannot alter the filed return's
  locked `tax_return_lines`; instead, the void generates a new `tax_transactions` reversal row dated
  in the *current* open period (never backdated into the filed period) and flows into the next
  return as a `corrections_from_prior_period` line — mirroring how most tax authorities require
  prior-period corrections to be handled (via the current period's return, with disclosure, rather
  than by amending a filed return, except where the amendment threshold/materiality rules of that
  jurisdiction specifically permit or require a formal amended filing, in which case a Tax Manager
  triggers the `amended` status flow instead).
- **AI misclassifies a product and it auto-applies before being caught.** Because auto-apply is
  gated at ≥0.90 confidence and every auto-applied classification is both logged and included in the
  daily digest, a misclassification is caught either by digest review or by the Anomaly Detection
  agent (which would flag the resulting unusual tax-rate pattern for that product category). The
  correction path is: fix the product's `tax_code_id`/`product_tax_treatment` going forward (new
  transactions immediately correct), and issue manual adjustments (via `tax.adjust`) for any
  already-posted transactions affected, each carrying `reason_code = 'ai_reclassification_
  correction'` for clean audit-trail visibility into how many corrections originated from AI error
  versus human error versus regulation change.
- **Company deregisters from a jurisdiction mid-year.** When a `tax_registrations.status` moves to
  `deregistered`, the engine stops resolving any new `tax_rules` against that jurisdiction for that
  company as of the deregistration's `effective_to` date, a final return (`return_type` matching the
  registration's `system_type`, period ending on the deregistration date) is auto-drafted by the
  Compliance Agent for the Tax Manager's review, and any transaction dated after deregistration that
  would otherwise have matched that jurisdiction instead raises a `TaxRateNotConfiguredException`
  (per Tax Calculation Engine step 4) prompting a human to explicitly decide the correct treatment
  (out-of-scope, or a new registration is actually still required) rather than silently either
  taxing or not taxing an ambiguous post-deregistration transaction.

# Future Improvements

- **Native OCR-to-tax-code pipeline for inbound vendor bills.** Extend the Document AI/OCR Agent
  (already part of the platform's broader AI responsibilities) to read a scanned vendor invoice,
  extract line items, and pre-populate both the bill and its suggested tax codes in one pass,
  reducing purchase-bill data entry to a single human confirmation step.
- **Real-time nexus/threshold monitoring beyond sales tax.** Extend the Compliance Monitoring
  responsibility, currently focused on US-style economic nexus, to proactively track VAT
  registration thresholds in jurisdictions with a registration-threshold model (several non-GCC
  markets QAYD may expand into), and to simulate "what if" scenarios ("if Q3 sales in this
  jurisdiction grow 20%, we cross the threshold in week 6").
- **Automated recovery-ratio calculation from GL data.** Currently `recovery_ratios` values are
  entered/approved by a Tax Manager (with an AI-suggested value as a starting point); a future
  iteration could have the Forecast Agent compute the provisional ratio automatically from the
  trailing 12 months of taxable-vs-exempt revenue at the start of each fiscal year, presenting it as
  a pre-filled, still-human-approved default rather than a blank field.
- **Direct integration with additional GCC/regional government portals.** Beyond ZATCA and FTA,
  extend the adapter pattern to Oman Tax Authority, Bahrain NBR, and Qatar's forthcoming e-invoicing
  regime as each jurisdiction's API/schema stabilizes, without changing the core filing workflow or
  permission model.
- **Tax scenario simulation sandbox.** A "what-if" mode allowing a Tax Manager to simulate the
  P&L/cash-flow effect of a hypothetical registration in a new jurisdiction, or a hypothetical
  reclassification of a product line, before committing to either — reusing the existing
  `preview`-mode calculation engine but run in bulk against a historical transaction sample rather
  than a single new transaction.
- **Peppol/UBL interoperability for cross-border e-invoicing.** As more jurisdictions adopt Peppol-
  based e-invoicing networks for structured B2B invoice exchange, add a Peppol Access Point adapter
  alongside the direct government-API adapters, letting QAYD-generated invoices flow directly into a
  customer's own e-invoicing/accounts-payable system in a standard UBL format.
- **Continuous transaction control (CTC) readiness for additional markets.** Generalize the ZATCA
  Phase 2 real-time-clearance pattern (hash chain, cryptographic stamp, synchronous government
  clearance before invoice issuance) into a reusable CTC framework, since an increasing number of
  jurisdictions worldwide are moving toward this "clear before you can invoice" model rather than
  periodic after-the-fact filing.
- **Machine-assisted tax code migration for M&A/restructuring.** When a company acquires another
  entity or restructures its legal-entity tree, provide an AI-assisted mapping tool that proposes how
  the acquired entity's existing tax configuration (codes, rates, registrations) should merge into
  or coexist alongside the acquirer's configuration, flagging conflicts (duplicate registrations,
  incompatible rate structures) for a human to resolve rather than requiring a fully manual
  re-configuration from scratch.

# End of Document
