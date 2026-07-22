# 06 — Accounting — QAYD PRD
Version 1.0
Status: Source of Truth
Module: PRD
Submodule: ACCOUNTING
---

# Purpose And Altitude

This chapter describes QAYD's accounting platform at PRD altitude: **what each accounting module
does, and how they all connect through one ledger.** It is not a re-specification. The posting
invariant, the schema, the state machines, and the algorithms are owned authoritatively by
[../../accounting/](../../accounting/) (the domain theory) and
[../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md) (the service shape); where
this chapter needs a fact, it cites the document that owns it. What this chapter contributes is the
whole-picture reading: the correct double-entry core is the substrate everything else — including the
entire AI platform of Chapter [05_AI_PLATFORM.md](./05_AI_PLATFORM.md) — stands on, and this is how
its pieces fit.

The accounting core is QAYD's moat and its non-negotiable substrate. Competitors bolt "AI" onto a
ledger they treat as storage; QAYD owns the ledger, keeps it correct by construction, and lets the AI
propose to it but never quietly rewrite it. A platform whose books are not provably correct cannot
make the AI's output defensible to an auditor or a bank — so correctness here is prerequisite to
everything, not one feature among many.

# The Posting Invariant

One promise defines this module and constrains every other module that touches money
([../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md),
[../../accounting/GENERAL_LEDGER.md](../../accounting/GENERAL_LEDGER.md)):

- **Every posting balances.** For every posted entry, the sum of debits equals the sum of credits
  exactly, to the stored precision, in the entry's currency and independently in base currency. Zero
  tolerance, re-derived server-side from the lines at posting time — never trusted from the client or
  a cached header total.
- **Posting is atomic.** Balance assertion, period resolution, entry numbering, and the ledger
  projection all happen inside one database transaction; a balance failure rolls the whole thing
  back. There is no partial post.
- **Posted records are immutable.** No field on a posted, reversed, or voided entry ever changes. A
  mistake is corrected by a **reversing entry**, never by an edit — preserving an unbroken audit
  chain. Lines are freely editable only while the parent is a draft.
- **There is exactly one posting code path.** No caller — human, source module, scheduler, or AI — is
  exempt from it. This is why there is a single, audited place where money enters the books.
- **The AI never posts.** Any AI-originated entry lands as a `draft` awaiting a human who holds the
  posting permission; a database trigger rejects an attempt to post an AI-origin entry directly,
  regardless of caller. This is the mechanical enforcement of "AI proposes, human disposes" from
  Chapter 05, expressed in the ledger itself.

Two database-level guards back these application invariants independently, so an application bug alone
cannot breach them, and money is stored at fixed decimal precision as a value object, never a float —
KWD presents to three decimals (fils) while computing on the stored four. The full invariant set,
triggers, and the `PostingService` that is their home are in
[../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md).

# The Derivation Chain

The accounting domain is a strict, single-direction derivation: primary facts are journal entries and
their lines; everything else is computed or projected from posted lines and is rebuildable. Dropping
and rebuilding the projection from the lines reproduces byte-identical reports — the nightly integrity
job does exactly this.

```
  Account types (7 natures, system-seeded)
        │ classify
        ▼
  Chart of Accounts (bilingual tree)
        │ referenced by
        ▼
  Journal Entries + Lines  ──post()──▶  General Ledger (1:1 projection of posted lines)
   (draft → posted, balanced, atomic)          │ aggregated into
                                    ┌───────────┼──────────────┐
                                    ▼           ▼              ▼
                              Trial Balance   Financial     AI reads
                              (real-time +    Statements    (never writes)
                               snapshots)     (BS/IS/CF/SoCE)
```

Because the ledger projection is scoped by Row-Level Security, there is no code path by which one
company's posted lines can enter another company's trial balance — even a reporting query that forgot
its company filter returns only the active tenant's rows. The chain and its rebuildable property are
specified in [../../accounting/GENERAL_LEDGER.md](../../accounting/GENERAL_LEDGER.md).

# The Core Accounting Modules

Each core module below is a link in the derivation chain. This section states what each does; the
cited document owns the depth.

**Chart of Accounts.** The account tree and its seven system-seeded natures — asset, liability,
equity, revenue, expense, other income, other expense — each account bilingual (`name_en` /
`name_ar`), with unlimited nesting, control-account designation for customers and vendors, normal
balances, and opening balances. A posted account can never be silently renumbered or retyped. The
template is provisioned per company and localized for the GCC context.
Spec: [../../accounting/CHART_OF_ACCOUNTS.md](../../accounting/CHART_OF_ACCOUNTS.md).

**Journal Entries.** The header-plus-lines primary fact, moving through the lifecycle `draft →
pending_approval → posted → reversed/voided`, with lines mutable only while the parent is a draft.
Entries arrive three ways: **manual** (a human, with templates and recurring support), **automatic**
(emitted by a source module's event — invoices, bills, payments, inventory receipts, payroll), and
**AI-drafted** (origin `ai_draft`, created as a draft only, never auto-posted). Every route converges
on the one posting path. Spec:
[../../accounting/JOURNAL_ENTRIES.md](../../accounting/JOURNAL_ENTRIES.md).

**General Ledger.** The deterministic, rebuildable projection of every posted line, carrying the
dimensions (cost center, project, department, branch) copied from the line so the same ledger data
slices by business dimension without duplicating accounts. It backs account-activity and
running-balance reads. Spec:
[../../accounting/GENERAL_LEDGER.md](../../accounting/GENERAL_LEDGER.md).

**Trial Balance.** Both the real-time balanced check (debits equal credits across the whole ledger at
any instant) and the durable, approvable, exportable snapshot — unadjusted, adjusted, and
post-closing — that anchors period review. Spec:
[../../accounting/TRIAL_BALANCE.md](../../accounting/TRIAL_BALANCE.md).

**Financial Statements.** The Balance Sheet, Income Statement, Cash Flow Statement, and Statement of
Changes in Equity, assembled as views over posted lines, IFRS-consistent, validating the accounting
equation (Assets = Liabilities + Equity) before presenting — a Balance Sheet that fails the equation
returns a diagnostic, never a silently wrong statement. They render bilingually and support
comparative, consolidated, branch, department, and project views. Spec:
[../../accounting/FINANCIAL_STATEMENTS.md](../../accounting/FINANCIAL_STATEMENTS.md).

**Fiscal Periods & Close.** Fiscal years and periods walk `open → closed → locked` under a
module-lock matrix; no posting ever enters a non-open period, re-checked at posting time under a lock
on the period row. Period close, period lock, and fiscal-year close are all human-approved sensitive
actions — which is what lets the AI keep the books continuously close-ready (Chapter 05's Autonomous
Closing) while the sign-off stays human. Spec:
[../../accounting/GENERAL_LEDGER.md](../../accounting/GENERAL_LEDGER.md) and
[../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md).

# The Operational Modules That Feed The Ledger

The source modules never write a journal line themselves. Each does its own operational work, and when
a business fact occurs it **emits a domain event; the Accounting Service reacts by posting the ledger
effect through the same Actions a human uses**, idempotency-guarded so a redelivery never double-posts.
This one-way coupling is what keeps each source module replaceable without touching the ledger, and it
is the accounting expression of the module-relationship rule in Chapter
[04_ARCHITECTURE.md](./04_ARCHITECTURE.md).

| Module | What it does operationally | Ledger effect it triggers | Deep spec |
|---|---|---|---|
| **Sales (Order-to-Cash)** | Quotations, sales orders, deliveries, invoices, receipts, returns, discounts, pricing, AR aging | `invoice.posted` → recognize revenue + AR + output VAT; `payment.received` → Dr bank, Cr AR | [../../accounting/SALES.md](../../accounting/SALES.md), [../../accounting/CUSTOMERS.md](../../accounting/CUSTOMERS.md) |
| **Purchasing (Procure-to-Pay)** | Purchase requests/orders, receiving, quality inspection, vendor bills, three-way match, vendor payments | `bill.approved` → Dr expense/asset + input VAT, Cr accounts payable | [../../accounting/PURCHASING.md](../../accounting/PURCHASING.md), [../../accounting/VENDORS.md](../../accounting/VENDORS.md) |
| **Banking & Treasury** | Bank accounts, transaction import (statement then feed), reconciliation, transfers/payments, cash management, multi-currency | `payment.received`/disbursement postings; reconciliation matches ledger lines against bank lines | [../../accounting/BANKING.md](../../accounting/BANKING.md), [../../backend/BANKING_SERVICE.md](../../backend/BANKING_SERVICE.md) |
| **Inventory** | Products, warehouses, stock movements, valuation under the company's costing method, adjustments | `goods.received` → inventory / GRNI postings; material adjustments hand off to accounting for journal posting | [../../accounting/INVENTORY.md](../../accounting/INVENTORY.md), [../../accounting/WAREHOUSES.md](../../accounting/WAREHOUSES.md) |
| **Payroll** | Employees, payroll runs, payslips, Kuwait PIFSS contributions, WPS salary file | `payroll.released` → salary, deductions, and WPS liability postings (release is human-gated) | [../../accounting/PAYROLL.md](../../accounting/PAYROLL.md), [../../backend/PAYROLL_SERVICE.md](../../backend/PAYROLL_SERVICE.md) |
| **Tax** | Tax codes/rates, per-transaction input/output VAT tracking, return preparation, withholding | `return.submitted` → tax-liability recognition (submission is human-gated) | [../../accounting/TAX.md](../../accounting/TAX.md), [../../backend/TAX_SERVICE.md](../../backend/TAX_SERVICE.md) |

**Reconciliation** deserves a specific note because it is where Banking and the ledger meet: the
Treasury/Banking side runs deterministic-plus-fuzzy matching of imported bank lines against posted
ledger lines, auto-matching at high confidence and surfacing the rest — the AI capability of Chapter
05's Autonomous Reconciliation runs on exactly this module's data. Spec:
[../../accounting/BANKING.md](../../accounting/BANKING.md).

**Fixed assets and depreciation** are handled as scheduled ledger postings rather than a separate
system of record: a depreciation job posts the periodic charge through the same posting engine and the
same `origin='system'` path as any other automatic entry, re-establishing tenant context before it
touches a model, so asset expense reaches the ledger with the identical invariants as every other
posting ([../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md),
[../../accounting/GENERAL_LEDGER.md](../../accounting/GENERAL_LEDGER.md)).

# Reporting

Reporting reads the accounting outputs; it never writes accounting tables. On top of the standard
statements it provides role-specific dashboards (executive, finance, sales, inventory, payroll, tax,
operations, AI), report categories, aging and management reports, and scheduled distribution with AI
narrative commentary — all bilingual, and all traceable to the posted lines they summarize. Reporting
and Tax both consume the ledger query-only; Tax reads Income Statement figures to compute provisions.
Spec: [../../accounting/REPORTS.md](../../accounting/REPORTS.md),
[../../backend/REPORTING_SERVICE.md](../../backend/REPORTING_SERVICE.md).

# How It All Connects Through The Ledger

The whole accounting platform is one shape: **many source modules, one terminal consumer.** Sales,
Purchasing, Banking, Inventory, Payroll, and Tax each own their operational world and emit events; the
Accounting Service is the single subscriber that turns those events into balanced, immutable postings;
the General Ledger projects them; the Trial Balance and Financial Statements derive from the
projection; and Reporting and the AI platform read the result. The dependency arrows all point *into*
accounting and never out of it.

Three consequences of this shape are worth stating explicitly, because they are what make the platform
trustworthy at scale:

- **Segregation of duties is structural.** Entries above a company-configured threshold, all
  `ai_draft` entries, year-closing entries, and every manual reversal require an approval chain, and
  the maker can never be the sole approver — enforced by the Workflow Engine, not by convention
  ([../../backend/WORKFLOW_ENGINE.md](../../backend/WORKFLOW_ENGINE.md)).
- **The AI is a client, not a peer.** The FastAPI engine proposes entries by calling `/api/v1` with a
  scoped service token like any client; its proposal lands as a draft with its agent id and confidence
  recorded. It can read balances and draft postings; it can never post, approve, reverse, close, or
  lock. A human with the posting permission disposes — the accounting-side face of Chapter 05's core
  contract.
- **GCC/KWD correctness is built in, not localized on.** KWD three-decimal (fils) precision, GCC VAT
  frameworks (live in Saudi Arabia, the UAE, Bahrain, and Oman; pending in Kuwait and Qatar), Kuwait
  PIFSS/WPS payroll, IFRS-consistent statements, and bilingual Arabic/English output with full RTL are
  properties of the core, present from the MVP ledger onward
  ([../../accounting/TAX.md](../../accounting/TAX.md),
  [../../accounting/PAYROLL.md](../../accounting/PAYROLL.md)).

Every number a QAYD user or auditor sees — a trial balance, a Balance Sheet, an AI narrative, a bank
match — traces back through this chain to a posted, balanced, immutable journal line and the source
document behind it. That traceability is the point of the whole design, and it is what lets the AI
platform of Chapter 05 be as ambitious as it is without ever putting the books at risk.

## Related Documents

- [../../accounting/CHART_OF_ACCOUNTS.md](../../accounting/CHART_OF_ACCOUNTS.md), [../../accounting/JOURNAL_ENTRIES.md](../../accounting/JOURNAL_ENTRIES.md), [../../accounting/GENERAL_LEDGER.md](../../accounting/GENERAL_LEDGER.md), [../../accounting/TRIAL_BALANCE.md](../../accounting/TRIAL_BALANCE.md), [../../accounting/FINANCIAL_STATEMENTS.md](../../accounting/FINANCIAL_STATEMENTS.md), [../../accounting/TAX.md](../../accounting/TAX.md) — the double-entry core and tax.
- [../../accounting/SALES.md](../../accounting/SALES.md), [../../accounting/PURCHASING.md](../../accounting/PURCHASING.md), [../../accounting/BANKING.md](../../accounting/BANKING.md), [../../accounting/INVENTORY.md](../../accounting/INVENTORY.md), [../../accounting/PAYROLL.md](../../accounting/PAYROLL.md), [../../accounting/REPORTS.md](../../accounting/REPORTS.md) — the operational modules that feed the ledger.
- [../../backend/ACCOUNTING_SERVICE.md](../../backend/ACCOUNTING_SERVICE.md), [../../backend/BANKING_SERVICE.md](../../backend/BANKING_SERVICE.md), [../../backend/PAYROLL_SERVICE.md](../../backend/PAYROLL_SERVICE.md), [../../backend/TAX_SERVICE.md](../../backend/TAX_SERVICE.md), [../../backend/INVENTORY_SERVICE.md](../../backend/INVENTORY_SERVICE.md), [../../backend/REPORTING_SERVICE.md](../../backend/REPORTING_SERVICE.md), [../../backend/WORKFLOW_ENGINE.md](../../backend/WORKFLOW_ENGINE.md) — the service-shape counterparts.
- [../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md), [../../database/ROW_LEVEL_SECURITY.md](../../database/ROW_LEVEL_SECURITY.md) — the tenant boundary the ledger tables inherit.
- [../../api/REST_STANDARDS.md](../../api/REST_STANDARDS.md), [../../api/API_IDEMPOTENCY.md](../../api/API_IDEMPOTENCY.md) — the envelope and idempotency for money-moving posts.
- [../../ai/AI_FINANCE_OS.md](../../ai/AI_FINANCE_OS.md) — how the AI platform proposes to this ledger without ever posting to it (Chapter 05).

# End of Document
