# Tax — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: TAX
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Tax module: the screen set a Tax Manager,
Finance Manager, Accountant, CFO, or Auditor opens to answer four questions — *what do we currently owe
(or can reclaim), what is due next and when, is every transaction correctly taxed, and is a return ready
to file* — and to act on the one truly irreversible step in the entire module, filing a return with a
government authority, without ever letting that step happen without a named human decision. It is the
frontend counterpart to `docs/accounting/TAX.md`, which owns every table, calculation rule, endpoint, and
permission this screen renders, and it conforms to every cross-cutting convention `FRONTEND_ARCHITECTURE.md`,
`DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `LAYOUT_SYSTEM.md`,
`RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and `ACCESSIBILITY.md` establish. Where this document is silent,
those documents govern; where this document appears to contradict one of them, that is a defect to raise,
not a decision to resolve unilaterally in code — exactly the discipline `BANKING.md` and `JOURNAL_ENTRIES.md`
already hold themselves to for their own modules.

Tax is, by construction, a compliance and aggregation layer sitting *above* the transactional modules that
actually generate tax — Sales, Purchasing, Payroll, Inventory, and Banking each call
`POST /api/v1/tax/calculate` server-side, from their own screens, at the moment a taxable document is
priced or committed (`docs/accounting/TAX.md → Tax Calculation Engine`). This screen set never renders a
tax-calculation step embedded inside an invoice or a bill — that calculation is invisible plumbing owned by
`INVOICES.md`/`PURCHASING.md`'s own screens. What this module owns instead is everything *after* a
`tax_transactions` row already exists: the aggregate liability view, the full output/input ledger, the
statutory return's preparation → review → human-approved filing lifecycle, the configuration surface for
codes/rates/rules a Tax Manager maintains maybe once a quarter, and the AI Tax Advisor/Compliance layer that
watches all of it continuously. Concretely, this document specifies the four routes that compose the Tax
module and the screen that composes:

- A **Tax Liability KPI band** — the aggregated, per-registration answer to "what do we currently owe or
  can reclaim," broken into Net Tax Payable, Total Output Tax, Total Recoverable Input Tax, and a
  Compliance Risk Score, each sourced from `GET /tax/reports/liability` and the Risk Detection AI
  capability, never recomputed client-side from raw `tax_transactions` rows.
- A **Filing Deadline rail** — one card per active `tax_registrations` row showing its next `tax_returns`
  due date, an escalating urgency treatment matching the 30/14/7/1-day reminder cadence
  `docs/accounting/TAX.md → Notifications` already defines, and the status of whatever return is currently
  in flight for that registration.
- An **AI Tax Advisor & Compliance panel** — a dismissible briefing synthesizing the Compliance Agent's
  registration/certificate/filing-calendar monitoring, the Risk Detection capability's per-jurisdiction Tax
  Risk Score, and the Forecast Agent's next-period liability projection, so a compliance exposure surfaces
  on the screen where a human can act on it, without this screen ever computing that exposure itself.
- A **Returns `DataTable`** — the searchable, filterable, sortable list of every `tax_returns` row the
  caller can see, each a doorway into the full **Prepare → Review → File** workflow, always on its own
  route, never a modal, because filing carries legal and financial consequence.
- A **Tax Transactions ledger** — the read-only, permission-gated view over `tax_transactions`, clearly
  separating **Output** (tax collected, owed to the authority) from **Input** (tax paid, recoverable —
  subject to the partial-exemption recovery ratio), with full calculation lineage available on demand via
  the fully-autonomous Tax Explanation AI capability.
- A **Tax Codes & Rates configuration surface** — the settings-style home for `tax_codes`, `tax_rates`
  (effective-dated, exclusion-constrained), `tax_rules`, `tax_groups`, `tax_jurisdictions`,
  `tax_registrations`, `exemption_certificates`, and `recovery_ratios` — a screen a Tax Manager touches
  rarely, but where a mistake (an overlapping rate window, a misconfigured reverse-charge rule) has
  outsized downstream consequence, so its interactions are deliberately more deliberate and confirmation-
  heavy than a high-frequency data-entry screen like Journal Entries.
- **ZATCA/e-invoicing filing status**, surfaced read-only wherever a `tax_returns` row carries a
  `government_reference_number`/`government_ack_payload`, and wherever a `tax_jurisdictions` row's
  `compliance_profile` names mandatory e-invoicing fields — this screen never generates a ZATCA QR code or
  invoice hash chain itself (that is `INVOICES.md`'s per-document concern); it surfaces the
  *return-level* filing acknowledgment a government e-filing channel sends back.

This screen, like every screen in QAYD, **owns no business or financial logic**. It never resolves a
jurisdiction, never picks a tax rate, never decides whether a rate change's effective-date window overlaps
an existing one, and never lets an AI agent's confidence score substitute for a human's decision to file a
return with a government authority. Every number rendered here was computed and validated by the Laravel
`TaxCalculationService`; every mutation this screen triggers is a call to `/api/v1/tax/...` guarded by the
exact permission key the API itself enforces (`FRONTEND_ARCHITECTURE.md`, Principle 1 and Principle 4). The
module's single most consequential rule — `docs/accounting/TAX.md → Tax Philosophy`'s fourth principle,
"tax submission is irreversible; the system treats it that way" — is enforced at three layers simultaneously
and this document's job is to make the third of those three layers, the UI, hold up its end: "the UI layer
(the 'File Return' button in the Next.js Tax Manager dashboard is hidden entirely for users without the
permission, and shows a second confirmation modal summarizing the amount and period before submission)" is
`docs/accounting/TAX.md → Permissions`'s own words, and this document is where that sentence becomes an
actual component tree.

# Route & Access

## App Router path

`FRONTEND_ARCHITECTURE.md → App Router Structure`'s own canonical route tree stubs the Tax module
minimally — deliberately so, the same way its Banking stub omitted `accounts/[accountId]/page.tsx` and its
Payroll stub omitted a bare employees list — naming only the two segments load-bearing enough to call out
ahead of this document:

```
├── tax/
│   ├── codes/page.tsx
│   └── returns/[returnId]/page.tsx
```

This document reproduces both of those two segments verbatim and extends the tree with the remaining
leaves the four bullet points in **Purpose** require, exactly the way `BANKING.md` added
`accounts/[accountId]/page.tsx` and `JOURNAL_ENTRIES.md` added its own `new/` and `[journalEntryId]/`
detail beyond their own stubs' literal text:

```
app/(app)/tax/
├── layout.tsx                          # Sub-nav: Overview | Transactions | Returns | Codes & Rates (tax.read gate)
├── returns/
│   ├── page.tsx                        # ★ THIS DOCUMENT'S PRIMARY SCREEN — Tax Overview: KPI band +
│   │                                    #   Filing Deadline rail + AI Tax Advisor panel + Returns table.
│   │                                    #   This is the Sidebar's actual root destination for the module.
│   └── [returnId]/
│       └── page.tsx                    # Prepare → Review → File workflow for one tax_returns row
├── transactions/
│   └── page.tsx                        # tax_transactions ledger — Output vs Input, filterable
└── codes/
    └── page.tsx                        # Tabbed config: Codes | Rates | Rules | Groups | Jurisdictions |
                                         #   Registrations | Exemption Certificates | Recovery Ratios
```

`NAVIGATION_SYSTEM.md → The module map` sets Tax's sidebar root route as `/tax/returns`, not a bare `/tax`
— exactly the pattern `BANKING.md`'s Purpose section already explains for its own module ("the Banking
sidebar entry points directly at `/banking/accounts`, not at a bare `/banking`... there is deliberately no
`banking/page.tsx` file at the module's own root segment"). This document resolves the apparent tension
between "Tax needs a dashboard" (this document's own brief) and "the sidebar points at the returns list"
the same way `BANKING.md` resolved an identical tension for its own module: `returns/page.tsx` **is** the
dashboard. It is not a bare list — it opens with the Tax Liability KPI band, the Filing Deadline rail, and
the AI Tax Advisor & Compliance panel, with the Returns `DataTable` composed underneath, precisely mirroring
how `BANKING.md`'s `accounts/page.tsx` is simultaneously "the Banking Home screen" (cash position, AI
Treasury panel) **and** the account list. One route, one screen, both jobs — never a second, competing
`tax/page.tsx` a user could land on and see a different, thinner view of the same module.

`transactions/page.tsx` and `returns/page.tsx` (the list half of the hybrid screen above) are both additions
this document makes beyond the architecture stub's two literal leaves, exactly as `BANKING.md` added
`accounts/[accountId]/page.tsx` without that being treated as a contradiction of the canonical tree — the
canonical stub is illustrative of the segments important enough to call out ahead of time, not an
exhaustive enumeration of every leaf every module will ever need.

## Permission gate

Every permission key below is defined once, authoritatively, in `docs/accounting/TAX.md → Permissions`;
this table is this screen's map of key → concrete UI effect, not a redefinition.

| Gate | Permission | Effect if absent |
|---|---|---|
| Screen visible at all | `tax.read` | Sidebar's Tax entry (and every route under `/tax`) does not render; a direct hit renders the shell-level `error.tsx` "You don't have access to this," per `NAVIGATION_SYSTEM.md → Permission-Aware Nav → Server truth, client courtesy`, never a silent redirect. |
| Compliance Risk Score tile, AI Tax Advisor panel, forecast figures | `reports.read` | Tile/panel omitted from the region layout entirely (not a locked placeholder) — this mirrors `BANKING.md`'s AI Treasury panel gate exactly, since `NAVIGATION_SYSTEM.md`'s AI module note states every AI Command Center panel endpoint itself declares `reports.read`. |
| Invoke calculation preview (used only by the Codes & Rates screen's "Test this rule" affordance) | `tax.calculate` | Test-rule button omitted. |
| "New jurisdiction" / edit a jurisdiction | `tax.jurisdiction.manage` | Button/row-edit action omitted, per the "hide, don't disable" default rule (`NAVIGATION_SYSTEM.md → Permission-Aware Nav`) — a role-structural gap, not a plan-tier upsell. |
| "New registration" / edit a registration | `tax.registration.manage` | Omitted. |
| "New tax code/category" / edit; approve an AI classification proposal | `tax.config.manage` | Omitted; the AI Proposals queue still renders read-only for a `tax.read`-only viewer, with Approve/Reject replaced by a permission tooltip. |
| "New rate" / "Schedule rate change" | `tax.rate.manage` | Omitted. |
| "New rule" / reorder rule priority | `tax.rule.manage` | Omitted; the Rules tab's drag-handle is not rendered at all for a viewer without this permission (a disabled drag handle is worse UX than none). |
| "New exemption certificate" / revoke | `tax.exemption.manage` | Omitted. |
| "Set/approve recovery ratio" | `tax.recovery.manage` | Omitted. |
| Reverse a posted tax transaction; create a manual adjustment | `tax.adjust` | Row action and the Transactions screen's "New adjustment" button omitted. |
| View draft/filed returns, transactions, KPI band | `tax.filing.read` (returns), `tax.read` (transactions/KPIs) | Returns table and Return detail render the shell-level `403` boundary rather than a `404` — the route exists, the return might exist, the viewer simply may not see it (`FRONTEND_ARCHITECTURE.md → 403 vs. 404`). |
| Generate a draft return; move `draft → under_review` | `tax.filing.prepare` | "Prepare return" action (on the Filing Deadline rail and the Returns table) omitted; the Return detail page's "Submit for review" button omitted. |
| Approve a return (`under_review → ready_to_file`) | `tax.filing.approve` | The `ApprovalCard`'s Approve/Reject pair renders read-only with a permission tooltip rather than omitted — the card is already addressed to a specific approver, and hiding it entirely would look like a missed notification rather than an access boundary, exactly the reasoning `BANKING.md` gives for its own `bank.payment.approve` gate. |
| **File the return with the government (`ready_to_file → filed`)** | **`tax.submit`** | **The "File Return" button is not rendered at all — not disabled, not tooltipped, absent from the DOM — for any caller lacking this permission, matching `docs/accounting/TAX.md → Permissions`'s own explicit UI-layer requirement verbatim.** |
| Record a government acknowledgment reference manually | `tax.submit` | Same gate as filing itself — recording an ack is part of the same irreversible-submission surface, never split into a lesser permission. |
| View the full Tax Audit Report / raw calculation lineage | `tax.audit.read` | "View reasoning"/"Tax Audit Report" links omitted; a Tax Explanation `Sheet` trigger is still shown to `tax.read` holders (Tax Explanation is a distinct, narrower read documented in AI Integration) but the full lineage export is not. |
| Export any tax report (VAT/GST/Sales Tax/Withholding/Corporate Tax/Summary/Liability/Audit/Compliance) to PDF/XLSX/XML | `reports.export` | Export menu item omitted per report. |

`NAVIGATION_SYSTEM.md → Sub-navigation per module`'s own Tax entry names the filing permission
`tax.file_return`; this document uses `tax.submit` throughout instead, because that is the exact key
`docs/accounting/TAX.md`'s own Permissions table and its `POST /tax/returns/{id}/file` endpoint row both
define, and — per this document's own Purpose section — the accounting module specification is the
authoritative source for every permission key a frontend screen renders. Both names refer to the same,
single, structurally-AI-proof gate; this document simply uses the name its ground-truth data model actually
carries.

## Roles

Reproduced from `docs/accounting/TAX.md → Permissions`'s own role-grant table and translated into what a
role concretely sees on **this** screen, not the API's permission grant in the abstract — the same
translation discipline `BANKING.md`'s own Roles subsection applies:

| Role | What renders |
|---|---|
| Owner, Admin | Full screen: KPI band, Filing Deadline rail, AI panel, Returns table with every row action, full Codes & Rates config, File Return. |
| CEO, CFO | Per `docs/accounting/TAX.md`'s closing Permissions note, these inherit Finance Manager's grants plus board-level visibility into the Tax Summary and Tax Liability reports; they see the same screen a Finance Manager does. |
| Finance Manager | Full screen; can prepare, submit-for-review, and approve a return, and holds `tax.submit` — files returns exactly as Owner/Admin do, subject to whatever company-level delegation policy governs who actually presses File on a given registration. |
| Tax Manager | Full screen; the primary day-to-day operator of this module — owns configuration (`tax.config.manage`, `tax.rate.manage`, `tax.rule.manage`, `tax.jurisdiction.manage`, `tax.registration.manage`, `tax.exemption.manage`, `tax.recovery.manage`), prepares and files returns (`tax.filing.prepare`, `tax.submit`), and adjusts (`tax.adjust`). |
| Accountant | Read-only on filing (`tax.filing.read`, scoped to their own branch per the accounting doc's parenthetical), full read on configuration and transactions (`tax.read`); no prepare/approve/submit/config-write/adjust actions render. |
| Auditor | Sees the Transactions ledger, the Tax Audit Report, and `tax.filing.read` on returns; every mutating control is omitted. Distinct from Accountant in that Auditor additionally holds `tax.audit.read` (full calculation lineage) while Accountant does not. |
| External Auditor | Same read surface as Auditor (`tax.read`, `tax.filing.read`, `tax.audit.read`), time-boxed via `company_users.access_expires_at` per `docs/accounting/TAX.md`'s own note — see **Edge Cases** for what happens when that window closes mid-session. Never any write control, structurally identical in this respect to `BANKING.md`'s own External Auditor treatment. |
| AI service account | Never renders this screen as a "user." Its scoped, system-to-system `tax.calculate` grant feeds the invisible per-transaction calculation Sales/Purchasing/Payroll call into; its outputs surface *through* the AI Tax Advisor panel and the AI Proposals queue described in **AI Integration**, and it is structurally incapable of holding `tax.submit`, `tax.filing.approve`, `tax.adjust`, or any `*.manage` key, regardless of any role grant — enforced at the state-machine layer per `docs/accounting/TAX.md → Permissions`, which rejects a `file` transition outright for any request "bearing only a service/AI-layer token," independent of what permission that token nominally carries. |

## Keyboard entry point

`ACCESSIBILITY.md → Global keyboard shortcuts` reserves `G` chords for Dashboard, Accounting/Journal
Entries, General Ledger, Banking, Reports, and AI Command Center; it does not yet reserve one for Tax,
Sales, Purchasing, Inventory, or Payroll. This document does not unilaterally mint a new global chord —
doing so would be amending a cross-cutting accessibility contract this document does not own. Tax is
reached, like those other unreserved modules, through the Sidebar (`Percent` icon, per
`NAVIGATION_SYSTEM.md`'s module map) and through the Command Palette's "Navigate" group, both of which
index every route in this document's tree by its localized label.

# Layout & Regions

All four sub-surfaces compose `LAYOUT_SYSTEM.md`'s existing page templates — no new template shape is
introduced, per that document's "a screen never invents a sixth layout shape" rule. **Overview**
(`returns/page.tsx`) is a **Dashboard Template** instance with a **List Page Template**'s Data Region
grafted underneath it — the identical hybrid `BANKING.md`'s `accounts/page.tsx` already establishes for
its own module. **Transactions** is a plain **List Page Template**. **Codes & Rates** is a tabbed **List
Page Template**, one instance per tab. **Return Detail** is a **Report Page Template** (the return's lines
are computed, read-only, box-by-box content, exactly like a Trial Balance) with a **Detail Page
Template**'s lifecycle-action header and Summary Rail layered on top of it — a hybrid this document names
explicitly because neither template alone describes it: a return is simultaneously a formal, print-faithful
statutory document (Report Page Template) and a stateful record moving through an approval chain (Detail
Page Template).

## Overview — Dashboard + List Page Template hybrid

Laptop (`lg`, 1024px) and up, per `LAYOUT_SYSTEM.md → Breakpoints`, layout of `app/(app)/tax/returns/page.tsx`
inside the persistent app shell:

```
┌───────────────────────────────────────────────────────────────────────────────────────┐
│  Overview   Transactions   Returns   Codes & Rates                     [sub-nav tabs]  │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  Tax                                                        [Prepare return ▾]         │
│  4 active registrations · KWD, SAR, AED                                                │
├───────────────────────────────────────────────────────────────────────────────────────┤
│ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐               │
│ │ Net payable    │ │ Output tax     │ │ Input tax      │ │ Compliance     │  KPI band   │
│ │ SAR 106,800.00 │ │ SAR 184,250.00 │ │ recoverable    │ │ risk           │  (KpiTile×4)│
│ │ ▲ +4.1% vs Jun │ │ ~~~~~~~~~~~~~ │ │ SAR 61,200.00  │ │ 18 / 100  ●low │             │
│ └───────────────┘ └───────────────┘ └───────────────┘ └───────────────┘               │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  Upcoming filing deadlines                                          [View all ▾]      │
│  ◄ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐        ►        │
│    │ KSA · VAT      │ │ UAE · VAT      │ │ Kuwait · Corp. │ │ Bahrain · VAT  │  rail    │
│    │ Due in 5 days  │ │ Due in 19 days │ │ Due in 74 days │ │ Filed ✓        │          │
│    │ ● ready_to_file│ │ ● draft        │ │ ● not started  │ │ Jun period     │          │
│    └──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘                │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  ┌ AI · Tax Advisor & Compliance ──────────────────────────────────────────────────┐   │
│  │ ● KSA VAT return due in 5 days, still ready_to_file — file before Jul 21.        │  │
│  │   Certificate CERT-4471 (Al-Rashid Trading) expires in 12 days.                  │  │
│  │                                        [Dismiss]  [View full compliance report]  │  │
│  └───────────────────────────────────────────────────────────────────────────────────┘  │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  Returns                                          [Search…] [Status ▾] [Registration ▾]│
│  ┌─────────────────────────────────────────────────────────────────────────────────┐  │
│  │ Return         Registration      Period      Due       Status         Net payable│ │
│  │ ─────────────────────────────────────────────────────────────────────────────── │  │
│  │ VAT · Jul 2026 KSA VAT           Jul 1–31    Aug 15    ● not started          –  │  │
│  │ VAT · Jun 2026 KSA VAT           Jun 1–30    Jul 21    ● ready_to_file  106,800.00│ │
│  │ VAT · Jun 2026 UAE VAT           Jun 1–30    Jul 28    ● draft           41,200.00│ │
│  │ VAT · May 2026 Bahrain VAT       May 1–31    Jun 28    ● accepted        8,940.00│ │
│  │ …                                                                    [1 2 3 …]   │  │
│  └─────────────────────────────────────────────────────────────────────────────────┘  │
└───────────────────────────────────────────────────────────────────────────────────────┘
```

Region inventory:

| Region | Contents | Streaming boundary |
|---|---|---|
| Sub-nav | `Tabs`: Overview (active) · Transactions · Returns · Codes & Rates, each a real `<Link>`, per `tax/layout.tsx` (Server Component) — "Returns" as a sub-nav tab and `returns/page.tsx` as the Overview route are not a contradiction: the tab exists for a user who wants the unfiltered, full-width table without the KPI band above it, exactly `transactions/page.tsx`'s relationship to the embedded table on this same page | Not streamed — part of the layout shell |
| Page header | `<h1>Tax</h1>`, a one-line registration/currency summary, and the permission-gated "Prepare return" split-button (default action opens the nearest un-started period for the caller's primary registration; the dropdown lists every registration with no in-flight return for its current period) | Renders immediately with the page shell |
| KPI band | Four `KpiTile`s: Net Payable, Output Tax, Recoverable Input Tax, Compliance Risk Score | Own `<Suspense>` boundary — `GET /tax/reports/liability` (aggregated across every visible registration) and the Risk Detection capability's score endpoint independently |
| Filing Deadline rail | One `FilingDeadlineCard` per active `tax_registrations` row with a `due_date` in the future or a return not yet `filed`/`accepted` — horizontally scrollable at `lg`+, `snap-x` | Own `<Suspense>` boundary — `GET /tax/registrations` joined client-side against `GET /tax/returns?filter[status][not_in]=filed,accepted&sort=due_date` |
| AI Tax Advisor & Compliance panel | A dismissible `AiCardShell`-based briefing (Compliance Agent + Risk Detection + Forecast Agent), gated `reports.read` | Own `<Suspense>` boundary; independently failable without blanking the rest of the page |
| Returns table | `<TaxReturnsTable/>` (a preset `DataTable`) with search, Status/Registration filters, row actions | Own `<Suspense>` boundary — `GET /tax/returns` |

Each region streams independently behind its own `<Suspense>` boundary, exactly per
`FRONTEND_ARCHITECTURE.md → Streaming with Suspense`: a slow Forecast Agent computation for the AI panel
never delays the KPI band or the Returns table from painting, and a failure in one region renders that
region's own `ErrorState` without taking down the page (see **States**). The KPI band and Filing Deadline
rail are prefetched most aggressively, because together they answer the screen's single most
time-critical question — "what do we owe and what's due next" — while the AI panel, which synthesizes
rather than merely aggregates, is allowed to resolve a beat later without the page feeling slow.

## Transactions — List Page Template

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Tax Transactions                                                          │
│ 8,204 transactions this fiscal year                          [Export ▾]  │
├──────────────────────────────────────────────────────────────────────────┤
│ [Search…] [Direction ▾] [Registration ▾] [Period ▾] [Tax code ▾]  [⚙]   │
├──────────────────────────────────────────────────────────────────────────┤
│ Date      │ Source doc     │ Direction │ Tax code    │ Base    │ Tax    │⋯│
│ Jul 16    │ INV-2026-08831 │ Output    │ SA-VAT-STD  │10,000.00│1,500.00│⋯│
│ Jul 16    │ BILL-2026-04410│ Input     │ SA-VAT-STD  │ 4,000.00│ 600.00 │⋯│
│ Jul 15    │ BILL-2026-04512│ Input (RC)│ SA-VAT-STD  │20,000.00│3,000.00│⋯│
│ …         │ …              │ …         │ …           │ …       │ …      │⋯│
├──────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 8,204                                   [‹ Prev] [Next ›] │
└──────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — title, live row count (`meta.pagination.total`), Export menu (`reports.export`).
- **Filter Bar** — search (`q`, over `source_document_type`/`source_document_id`'s resolved document
  number and `tax_code`), and four named filters: **Direction** (`tax_direction` enum, grouped visually
  into *Output* — `output`, `output_self_assessed` — and *Input* — `input`, `input_reverse_charge`,
  `use_tax_accrual`, `withholding_payable`, `withholding_receivable` — per the direction taxonomy
  `docs/accounting/TAX.md`'s `tax_direction` enum defines), **Registration** (a `tax_registrations` picker,
  since one company may hold several), **Period** (`PeriodPicker`, `mode="fiscal_period"` default),
  **Tax code** (a searchable `tax_codes` picker). A density toggle and column-visibility menu close the bar.
- **Data Region** — `<TaxTransactionsTable/>`, a preset `DataTable` (`resource="tax/transactions"`,
  `paginationMode="cursor"` — `tax_transactions` is an unbounded, append-only fact table, matching
  `FRONTEND_ARCHITECTURE.md → Pagination`'s treatment of `ledger-entries`/`audit-logs`, not the
  bounded-list `page` mode most of this module's other tables use).
- **Pagination Footer** — cursor "Load more" / virtualized scroll, per **Performance**.

This table renders through the **Plain table pattern**, never the ARIA `grid` pattern —
`ACCESSIBILITY.md → Data Tables Accessibility` names exactly two surfaces in the whole platform that
warrant the heavier grid ("the Journal Entry line editor, and the Bank Reconciliation matching grid"), and
`tax_transactions` rows are immutable, derived, posted facts with nothing to arrow-key between, exactly the
same reasoning `JOURNAL_ENTRIES.md`'s own read-only `JournalLinesTable` gives for the identical choice.

## Return Detail — Report Page Template + Detail Page Template hybrid

```
┌───────────────────────────────────────────────────┬───────────────────┐
│ ‹ Returns   KSA VAT — Jun 2026     ● ready_to_file │  Summary           │
│                        [Submit for review ▾] [⋯]   │  ────────────────  │
├─ Lines │ Transactions │ Filing & ZATCA │ History ──┤  Net payable       │
│  Box  Label                    Taxable    Tax       │  SAR 106,800.00    │
│  1a   Std-rated domestic sales 1,120,000.00 168,000.00│  Due Jul 21 (5d) │
│  1b   Std-rated — reverse chg.    45,000.00   6,750.00│  Prepared by      │
│  3    Zero-rated domestic sales  212,000.00       0.00│  N. Al-Fahad      │
│  9    Std-rated domestic purch.  408,000.00  61,200.00│  Reviewed by —    │
│  12   Total input VAT recoverable        —    67,950.00│  ai_draft: true  │
│  …                                                   │  [View reasoning] │
├───────────────────────────────────────────────────────┤  ───────────────│
│  ⚠ GL variance: 0.00 — reconciled against ledger_entries│  ZATCA status   │
└───────────────────────────────────────────────────┴───────────────────┘
```

- **Page Header** — back-to-list link, `"{registration.legal_name} — {return_type} — {period_start}"`,
  `StatusPill` (`domain="tax_return"`), the single lifecycle action valid for the caller's permission and
  the return's current `status` (Submit for review / Approve-Reject via inline `ApprovalCard` / **File
  Return**, never more than one primary action at once), and an overflow menu (Amend, once `filed` or
  `accepted`; Export).
- **Tab/Segment Nav** — `Tabs`: **Lines** (default, the statutory box-by-box breakdown from
  `tax_return_lines`), **Transactions** (the exact `tax_transactions` rows this return's `tax_return_line_id`
  back-reference locks in once the return moves past `draft`), **Filing & ZATCA** (government reference
  number, acknowledgment payload, e-invoicing compliance profile), **History** (the full
  `draft → under_review → ready_to_file → filed → accepted/rejected/amended` timeline merged with
  `audit_logs`).
- **Main Column (8/12)** — the active tab's content, rendered as a plain, print-faithful `<table>` (Lines),
  a filtered instance of `<TaxTransactionsTable/>` (Transactions), the `ZatcaFilingStatusCard` (Filing &
  ZATCA), or the merged history timeline (History).
- **Summary Rail (4/12)** — net payable (`AmountCell`, `emphasis="strong"`), due date with a
  countdown chip, `prepared_by`/`reviewed_by`/`approved_by`/`filed_by` — and, only when
  `ai_draft_generated = true`, the AI provenance block (`ConfidenceBadge`, "View reasoning").
- **Findings Bar** — a collapsible strip above the Lines table surfacing the GL-variance reconciliation
  check (`docs/accounting/TAX.md → General Ledger`'s "raises an alert if they ever diverge by more than a
  rounding tolerance") and any open Tax Validation warnings on the period's transactions, exactly
  `LAYOUT_SYSTEM.md`'s Report Page Template names for Trial Balance.

## Codes & Rates — tabbed List Page Template

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Tax Codes & Rates                                                          │
│ Codes │ Rates │ Rules │ Groups │ Jurisdictions │ Registrations │ Certs │ Ratios │
├──────────────────────────────────────────────────────────────────────────┤
│ [Search…]                                                    [+ New code]│
├──────────────────────────────────────────────────────────────────────────┤
│ Code          │ Category            │ Reverse chg. │ Zero-rated │ Status │
│ SA-VAT-STD     │ KSA VAT              │      –       │     –      │Active │
│ SA-VAT-ZERO     │ KSA VAT              │      –       │     ✓      │Active │
│ KW-EXEMPT       │ Kuwait Withholding   │      –       │     –      │Active │
│ …                                                                   [1 2] │
└──────────────────────────────────────────────────────────────────────────┘
```

Eight tabs, each a thin, independently-fetched `DataTable` instance over its own resource
(`tax/codes`, `tax/rates`, `tax/rules`, `tax/groups`, `tax/jurisdictions`, `tax/registrations`,
`tax/exemption-certificates`, `tax/recovery-ratios`), each gated on `tax.read` to view and its own
`*.manage` key to create/edit (**Route & Access**). The **Rates** tab is the one given the deepest
treatment in **Interactions & Flows**, because its effective-dating/exclusion-constraint model imposes UX
constraints none of the other seven tabs share. Every tab's create/edit surface is a `Sheet` (a
configuration row is a small, single-entity form — never the full-page treatment `FRONTEND_ARCHITECTURE.md`
reserves for sensitive, money-moving creation flows like a bank transfer or a journal entry), except
**Rates**, whose "Schedule rate change" action is deliberately a `Dialog` with a confirmation step, not a
quick inline `Sheet` edit, given that an incorrectly-dated rate change is one of the highest-blast-radius
mistakes available on this entire screen.

# Components Used

Every component below is drawn from `COMPONENT_LIBRARY.md` as-is, or is one of the screen-specific composed
components this document introduces for this exact surface. No new primitive or generic finance component
is introduced here — a component gap discovered while building this screen is proposed as an addition to
`COMPONENT_LIBRARY.md`, never hand-rolled locally, per that document's own authoring conventions.

| Component | Source | Role on this screen |
|---|---|---|
| `Tabs` | Primitive (`components/ui/tabs.tsx`) | Overview/Transactions/Returns/Codes & Rates sub-nav; the Return Detail's Lines/Transactions/Filing & ZATCA/History tabs; the Codes & Rates screen's eight config tabs |
| `KpiTile` | Finance (`components/dashboard/kpi-tile.tsx`) | Net Payable, Output Tax, Recoverable Input Tax, Compliance Risk Score |
| `TrendSparkline` | Finance (`components/dashboard/trend-sparkline.tsx`) | Net Payable tile's trailing-12-period trend; the Forecast tile inside the AI panel |
| `AmountCell` | Finance (`components/accounting/amount-cell.tsx`) | Every taxable-base and tax-amount figure, list and detail |
| `CurrencyTag` | Finance (`components/accounting/currency-tag.tsx`) | Registration currency on Filing Deadline cards; `filing_currency_code` on the Return Detail Summary Rail |
| `StatusPill` | Finance (`components/shared/status-pill.tsx`), new `tax_return` and `tax_transaction` domain tables (below) | Return lifecycle status everywhere; transaction posted/reversed/adjusted status |
| `PeriodPicker` | Finance (`components/accounting/period-picker.tsx`) | Transactions screen's Period filter (`mode="fiscal_period"`) |
| `DataTable` | Shared (`components/shared/data-table.tsx`) | Returns table (`paginationMode="page"`), Transactions table (`paginationMode="cursor"`), every Codes & Rates tab |
| `ConfidenceBadge` | AI (`components/ai/confidence-badge.tsx`) | Compliance Risk Score tile; AI-drafted return provenance; Tax Classification proposal cards |
| `AiCardShell` / `ReasoningDisclosure` | AI (`components/ai/ai-card-shell.tsx`) | The AI Tax Advisor & Compliance panel's mandatory visual envelope, per `FRONTEND_ARCHITECTURE.md → AI Integration Layer` — every AI-authored card on this screen renders through this shell, never a bespoke one |
| `ApprovalCard` | Shared (`components/shared/approval-card.tsx`), `kind="tax_return"` | The Return Detail's inline Approve/Reject banner while `status = 'under_review'` |
| `PermissionGate` / `Can` | Shared (`components/auth/can.tsx`) | Wraps every mutating affordance named in **Route & Access** |
| `DropdownMenu` | Primitive | Returns table row actions; Return Detail overflow menu; Codes & Rates row actions |
| `Sheet` | Primitive | Codes & Rates create/edit forms (all tabs except Rates' schedule-change); the Tax Explanation reasoning drill-in |
| `Dialog` / `AlertDialog` | Primitive | Schedule-rate-change confirmation; File Return's mandatory second confirmation; Amend confirmation; Reject-reason capture |
| `Skeleton` | Primitive | Per-region loading placeholders (see **States**) |
| `EmptyState` / `ErrorState` | Shared | Zero-registrations onboarding; filtered-empty tables; per-region fetch failure |
| `Badge` | Primitive | Output/Input direction tag; reverse-charge/zero-rated/exempt qualifier chips on tax codes |

Four screen-specific components are new in this document.

## `FilingDeadlineCard`

```tsx
// components/tax/filing-deadline-card.tsx
'use client';

import { Card } from '@/components/ui/card';
import { StatusPill } from '@/components/shared/status-pill';
import { CurrencyTag } from '@/components/accounting/currency-tag';
import { AlertTriangle, Clock, CheckCircle2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { TaxRegistration, TaxReturn } from '@/types/tax';

interface FilingDeadlineCardProps {
  registration: TaxRegistration;
  currentReturn: TaxReturn | null; // null = no return generated yet for the open period
  onOpen: (registrationId: number, returnId: number | null) => void;
}

function urgencyTone(daysRemaining: number, status: TaxReturn['status'] | null): 'neutral' | 'warning' | 'danger' | 'success' {
  if (status === 'filed' || status === 'accepted') return 'success';
  if (daysRemaining <= 1) return 'danger';   // matches the 1-day escalation tier
  if (daysRemaining <= 7) return 'danger';   // matches the 7-day tier
  if (daysRemaining <= 14) return 'warning'; // matches the 14-day tier
  if (daysRemaining <= 30) return 'warning'; // matches the 30-day tier
  return 'neutral';
}

export function FilingDeadlineCard({ registration, currentReturn, onOpen }: FilingDeadlineCardProps) {
  const daysRemaining = currentReturn
    ? Math.ceil((new Date(currentReturn.due_date).getTime() - Date.now()) / 86_400_000)
    : null;
  const tone = daysRemaining != null ? urgencyTone(daysRemaining, currentReturn!.status) : 'neutral';
  const Icon = tone === 'success' ? CheckCircle2 : tone === 'danger' ? AlertTriangle : Clock;

  return (
    <Card
      padding="md"
      interactive
      onClick={() => onOpen(registration.id, currentReturn?.id ?? null)}
      className="min-w-[200px] snap-start space-y-2"
    >
      <div className="flex items-center justify-between">
        <span className="truncate text-sm font-medium text-ink-950">
          {registration.jurisdiction.name_en} · {registration.system_type.toUpperCase()}
        </span>
        <CurrencyTag code={registration.jurisdiction.default_currency_code} emphasis="muted" />
      </div>
      <div
        className={cn(
          'flex items-center gap-1.5 text-caption',
          { neutral: 'text-ink-500', warning: 'text-warning', danger: 'text-danger', success: 'text-positive' }[tone],
        )}
      >
        <Icon className="h-3.5 w-3.5" aria-hidden />
        {currentReturn
          ? tone === 'success'
            ? `Filed — ${currentReturn.period_start.slice(0, 7)}`
            : `Due in ${daysRemaining} day${daysRemaining === 1 ? '' : 's'}`
          : 'Not started'}
      </div>
      {currentReturn && <StatusPill domain="tax_return" status={currentReturn.status} size="sm" />}
    </Card>
  );
}
```

`urgencyTone`'s four day-thresholds (30/14/7/1) are not an independent design choice — they mirror
`docs/accounting/TAX.md → Notifications`'s own "Filing calendar reminders: 30/14/7/1 days before a
`tax_returns.due_date`, escalating in urgency" cadence exactly, so the card's color escalation and the
notification a Tax Manager separately receives always agree with each other. A registration with no
`currentReturn` at all (the next period hasn't been prepared yet) renders "Not started" in neutral tone —
deliberately not alarming this early — and clicking it calls `POST /tax/returns` to generate the draft
before navigating, rather than 404ing on a `returnId` that doesn't exist yet.

## `ZatcaFilingStatusCard`

```tsx
// components/tax/zatca-filing-status-card.tsx
import { Card } from '@/components/ui/card';
import { StatusPill } from '@/components/shared/status-pill';
import { Badge } from '@/components/ui/badge';
import type { TaxReturn, TaxJurisdiction } from '@/types/tax';

interface ZatcaFilingStatusCardProps {
  taxReturn: TaxReturn;
  jurisdiction: TaxJurisdiction; // carries compliance_profile
}

export function ZatcaFilingStatusCard({ taxReturn, jurisdiction }: ZatcaFilingStatusCardProps) {
  const profile = jurisdiction.compliance_profile as { e_invoicing?: boolean; portal_name?: string };
  const ack = taxReturn.government_ack_payload as
    | { acknowledged_at: string; portal: string; status_code: string }
    | null;

  return (
    <Card padding="md" className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-caption font-medium text-ink-500">E-filing status</p>
        {profile.e_invoicing && <Badge variant="outline">e-invoicing jurisdiction</Badge>}
      </div>

      {taxReturn.government_reference_number ? (
        <div className="space-y-1">
          <p className="text-body font-mono" dir="ltr">{taxReturn.government_reference_number}</p>
          {ack && (
            <>
              <p className="text-caption text-ink-500">
                {ack.portal} · acknowledged <span dir="ltr">{new Date(ack.acknowledged_at).toLocaleString()}</span>
              </p>
              <StatusPill
                domain="tax_return"
                status={ack.status_code === 'ACCEPTED' ? 'accepted' : taxReturn.status}
                size="sm"
              />
            </>
          )}
        </div>
      ) : (
        <p className="text-caption text-ink-500">
          Not yet filed. A government reference number and acknowledgment will appear here once this
          return is filed via the primary action above.
        </p>
      )}
    </Card>
  );
}
```

This card is deliberately read-only end to end — it renders `government_reference_number` and
`government_ack_payload` exactly as `docs/accounting/TAX.md`'s `tax_returns` table stores them and never
offers a way to hand-edit either field; the only way either value is ever populated is through
`POST /tax/returns/{id}/file` (automatic, when `submit_to_government_api: true` and the jurisdiction's
e-filing channel responds inline) or `POST /tax/returns/{id}/record-ack` (manual entry of a reference
number a Tax Manager obtained outside QAYD, for jurisdictions without a live e-filing API) — both gated
`tax.submit`, both described fully in **Interactions & Flows**. Per-invoice ZATCA QR codes and the invoice
hash chain are `INVOICES.md`'s own concern; this card exists one level up, at the return, and never
duplicates that per-document detail.

## `AiTaxAdvisorPanel`

```tsx
// components/tax/ai-tax-advisor-panel.tsx
'use client';

import { AiCardShell } from '@/components/ai/ai-card-shell';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { TrendSparkline } from '@/components/dashboard/trend-sparkline';
import { useAiTaxBriefing } from '@/lib/api/hooks/use-tax';
import { Button } from '@/components/ui/button';
import { useRouter } from 'next/navigation';

export function AiTaxAdvisorPanel() {
  const { data: briefing, isPending } = useAiTaxBriefing();
  const router = useRouter();

  if (isPending || !briefing) return null; // own Skeleton is the Suspense fallback, not an internal one

  return (
    <AiCardShell decision={briefing}>
      <ul className="space-y-1.5 text-body">
        {briefing.findings.map((f) => (
          <li key={f.id} className="flex items-start gap-2">
            <span className="mt-1.5 h-1 w-1 rounded-full bg-ink-500" aria-hidden />
            {f.text}
          </li>
        ))}
      </ul>
      {briefing.forecast && (
        <div className="mt-3 flex items-center gap-2">
          <TrendSparkline data={briefing.forecast.trend} ariaLabel="Projected tax liability, next 4 periods" />
          <ConfidenceBadge confidence={briefing.forecast.confidence_by_horizon[0]} size="sm" />
        </div>
      )}
      <div className="mt-3 flex gap-2">
        <Button variant="outline" size="sm" onClick={() => router.push('/reports?filter[report_type]=tax_compliance')}>
          View full compliance report
        </Button>
      </div>
    </AiCardShell>
  );
}
```

`briefing.findings` is a merged, server-computed feed spanning three of the nine AI Responsibilities
`docs/accounting/TAX.md` defines — Compliance Monitoring, Risk Detection, and Tax Forecasting — never
three separate panels competing for the same space; the merge itself happens server-side (the same
`ai_decisions` table every AI-authored surface in the product reads, per **AI Integration**), so this
component's only job is to render what it is given, exactly `FRONTEND_ARCHITECTURE.md → AI Integration
Layer`'s "the frontend's entire job for this layer is to render that shape faithfully" rule. "View full
compliance report" resolves to the platform's shared `report_definitions` mechanism
(`NAVIGATION_SYSTEM.md`'s Reports module, `Report Detail — /reports/[definitionId]`) filtered to the Tax
Compliance Report definition, rather than a bespoke Tax endpoint — `docs/accounting/TAX.md → Reports` lists
the Compliance Report alongside every other statutory report generated through that same shared mechanism,
and this screen does not duplicate Reports' own rendering of it.

## `StatusPill` domain additions

`COMPONENT_LIBRARY.md → StatusPill`'s `domain` union already reserves `'tax_return'` as a documented
value; this document is where that domain's lookup table is actually authored, following the identical
shape `BANKING.md` used to add its own `bank_account`/`bank_transaction`/`bank_transfer` tables:

```tsx
// components/shared/status-pill.tsx — additions owned by this document
const TAX_RETURN_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:          { label: 'Draft',            tone: 'neutral' },
  under_review:   { label: 'Under review',     tone: 'warning' },
  ready_to_file:  { label: 'Ready to file',    tone: 'accent'  },
  filed:          { label: 'Filed',            tone: 'accent'  },
  accepted:       { label: 'Accepted',         tone: 'success' },
  rejected:       { label: 'Rejected',          tone: 'danger'  },
  amended:        { label: 'Amended',           tone: 'neutral' },
};

const TAX_TRANSACTION_STATUS: Record<string, { label: string; tone: Tone }> = {
  posted:   { label: 'Posted',   tone: 'success' },
  reversed: { label: 'Reversed', tone: 'neutral' },
  adjusted: { label: 'Adjusted', tone: 'warning' },
};

Object.assign(STATUS_TABLES, {
  tax_return: TAX_RETURN_STATUS,
  tax_transaction: TAX_TRANSACTION_STATUS,
});
```

`ready_to_file` and `filed` deliberately share the `accent` tone even though they are materially different
states — the same reasoning `BANKING.md` gives for why its own `cleared` and `reconciled` states differ:
`accent` is the platform's "the system/process moved this forward, a human hasn't yet seen the *final*
external confirmation" tone, and both `ready_to_file` (approved internally, awaiting the File action) and
`filed` (submitted, awaiting the government's acceptance) are exactly that. `accepted` — the government's
own confirmation — is the only state that earns `success`, mirroring `reconciled`'s identical role in
Banking. `posted` on a tax transaction earns `success` directly, matching `journal_entry`'s own `posted`
tone precisely, because a posted tax transaction is a routine, correct, final fact, not merely
"in progress." `adjusted` is deliberately `warning`, not `danger` or `neutral` — a manual adjustment
(`tax.adjust`, always carrying a mandatory `reason` and `supporting_document_id` per
`docs/accounting/TAX.md → Adjustments`) is not wrong, but it is a departure from the system's own
deterministic calculation and is worth a second glance, exactly the same weight `warning` carries
elsewhere in the platform for "pending/needs attention," never for "this is bad."

One direction-related design decision is worth naming explicitly because it is easy to get wrong: the
Transactions table's **Output**/**Input** distinction is rendered as a plain `Badge variant="outline"`, not
a `StatusPill`, and never colored `positive`/`negative`. `DESIGN_LANGUAGE.md → The debit/credit rule`
states plainly that debit-normal and credit-normal "are structural properties of an account type... not
judgments of good and bad," and Output vs. Input tax is the exact same kind of structural, directional fact
— Output is not "bad" for being a liability the company owes, and Input is not "good" for being an asset
the company can recover. Both render in neutral `ink` tones, distinguished only by the label and, on the
Transactions filter, by grouping — precisely the same discipline the Journal Entries List applies to its
own Debit/Credit columns.

# Data & State

## Endpoints

Every figure and every row on this screen resolves to an endpoint `docs/accounting/TAX.md → API` already
defines; this screen introduces no endpoint of its own.

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Tax liability (KPI band) | `GET /api/v1/tax/reports/liability` | `tax.filing.read` | Called once per visible registration (or once with an aggregate flag) to feed Net Payable, Output Tax, Recoverable Input Tax |
| Registrations (Filing Deadline rail) | `GET /api/v1/tax/registrations` | `tax.read` | Feeds the rail's card set |
| Returns, filtered to open/near-due (Filing Deadline rail) | `GET /api/v1/tax/returns?filter[status][not_in]=filed,accepted&sort=due_date` | `tax.filing.read` | Joined client-side against the registration list by `tax_registration_id` |
| Returns (Overview table + Returns tab) | `GET /api/v1/tax/returns` | `tax.filing.read` | Supports `filter[status]`, `filter[tax_registration_id]`, `filter[return_type]`, `filter[period_start][gte/lte]` |
| Generate a draft return | `POST /api/v1/tax/returns` | `tax.filing.prepare` | "Prepare return" flow |
| Return detail (Lines, Summary Rail) | `GET /api/v1/tax/returns/{id}` | `tax.filing.read` | Returns the header plus every `tax_return_lines` row |
| Return's locked transactions (Transactions tab) | `GET /api/v1/tax/transactions?filter[tax_return_line_id][in]=...` | `tax.read` | Only populated once the return has moved past `draft` and locked its covering transactions |
| Submit for review | `POST /api/v1/tax/returns/{id}/submit-for-review` | `tax.filing.prepare` | `draft → under_review` |
| Approve | `POST /api/v1/tax/returns/{id}/approve` | `tax.filing.approve` | `under_review → ready_to_file` |
| **File** | `POST /api/v1/tax/returns/{id}/file` | **`tax.submit`** | `ready_to_file → filed`; submits to the government e-filing channel where available |
| Record government acknowledgment | `POST /api/v1/tax/returns/{id}/record-ack` | `tax.submit` | Manual entry path for jurisdictions without a live e-filing API, or a retry after a timed-out inline submission |
| Tax transactions ledger | `GET /api/v1/tax/transactions` | `tax.read` | Feeds the Transactions screen; supports `filter[tax_direction]`, `filter[jurisdiction_id]`, `filter[tax_code_id]`, cursor-paginated |
| Reverse a posted transaction | `POST /api/v1/tax/transactions/{id}/reverse` | `tax.adjust` | Row action, Transactions screen |
| Create a manual adjustment | `POST /api/v1/tax/adjustments` | `tax.adjust` | "New adjustment," Transactions screen |
| Preview a calculation (rule-testing only) | `POST /api/v1/tax/calculate` (`mode: "preview"`) | `tax.calculate` | Codes & Rates → Rules tab's "Test this rule" affordance only — never used to price a live document from this screen |
| Codes / Categories / Rates / Rules / Groups / Jurisdictions / Registrations / Exemption Certificates / Recovery Ratios (list + create/update) | `GET`/`POST`/`PUT /api/v1/tax/{codes,categories,rates,rules,groups,jurisdictions,registrations,exemption-certificates,recovery-ratios}` | `tax.read` to view, the matching `tax.*.manage`/`tax.exemption.manage`/`tax.recovery.manage` to write | Codes & Rates screen's eight tabs |
| Schedule a rate change | `POST /api/v1/tax/rates/schedule-change` | `tax.rate.manage` | Atomically closes the current rate and opens the new one — see **Interactions & Flows** |
| Reorder rule priority | `PUT /api/v1/tax/rules/{id}/reorder` | `tax.rule.manage` | Drag-and-drop in the Rules tab |
| AI classification proposals | `GET /api/v1/tax/ai/proposals` | `tax.read` | Feeds the Codes tab's "AI-suggested" queue |
| Approve / reject an AI proposal | `POST /api/v1/tax/ai/proposals/{id}/approve\|reject` | `tax.config.manage` | Codes tab |
| Bulk-export current configuration | `GET /api/v1/tax/export` | `tax.read` | Codes & Rates screen's "Export configuration" action |
| Import a country pack | `POST /api/v1/tax/import` | `tax.config.manage` | Onboarding/expansion flow, Jurisdictions tab |
| Reconciliation re-run (preview only) | `POST /api/v1/tax/bulk/recalculate` | `tax.adjust` | A Tax Manager utility surfaced from the Transactions screen's overflow menu, never auto-commits |
| Statutory reports (VAT/GST/Sales Tax/Withholding/Corporate Tax/Summary/Audit) | `GET /api/v1/tax/reports/{report}` | `reports.export` + `tax.filing.read`/`tax.audit.read` | Export menu, Overview and Return Detail |

## Query keys and cache tuning

```ts
// lib/api/query-keys.ts (tax-scoped factories, additive to the platform's shared factories)
export const taxKeys = {
  all: ["tax"] as const,
  liability: (filters?: LiabilityFilters) => [...taxKeys.all, "liability", filters ?? {}] as const,
  registrations: () => [...taxKeys.all, "registrations"] as const,
  returns: (filters?: TaxReturnFilters) => [...taxKeys.all, "returns", filters ?? {}] as const,
  return: (id: number) => [...taxKeys.all, "returns", id] as const,
  transactions: (filters: TaxTransactionFilters) => [...taxKeys.all, "transactions", filters] as const,
  codes: (filters?: TaxCodeFilters) => [...taxKeys.all, "codes", filters ?? {}] as const,
  rates: (filters?: TaxRateFilters) => [...taxKeys.all, "rates", filters ?? {}] as const,
  rules: () => [...taxKeys.all, "rules"] as const,
  jurisdictions: () => [...taxKeys.all, "jurisdictions"] as const,
  exemptionCertificates: (filters?: CertificateFilters) => [...taxKeys.all, "exemption-certificates", filters ?? {}] as const,
  recoveryRatios: (fiscalYearId?: number) => [...taxKeys.all, "recovery-ratios", fiscalYearId ?? null] as const,
};

export const aiTaxKeys = {
  briefing: () => ["ai", "decisions", "tax-briefing"] as const,
  proposals: () => ["tax", "ai-proposals"] as const,
};
```

Every top-level key is implicitly company-scoped because `QueryClient` itself is re-created on company
switch rather than shared across companies (`FRONTEND_ARCHITECTURE.md → Query key architecture`), exactly
the same defense-in-depth reasoning `BANKING.md` cites for its own key factory.

Cache tuning follows `FRONTEND_ARCHITECTURE.md → Cache tuning by data class` exactly — this screen invents
no new data class or bespoke `staleTime`. Notably, `tax-codes` is one of the four examples that document's
own table already names under "Rarely-changing reference/master data":

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Rarely-changing reference/master data | `codes`, `rates`, `rules`, `jurisdictions`, `registrations`, `exemptionCertificates`, `recoveryRatios` | 5 minutes | Configuration a Tax Manager touches occasionally, not time-critical to reflect instantly — the exact tier `FRONTEND_ARCHITECTURE.md`'s own table already places `tax-codes` in |
| Transactional lists | `returns`, `transactions` | 30 seconds | Frequent enough writes (a period-end batch of transactions, a return moving through its lifecycle) that a stale list is a real annoyance, not worth sub-minute polling |
| Live/derived figures | `liability` | `0` (always stale) | Correctness over avoiding a refetch; kept fresh primarily via Realtime invalidation, matching `DASHBOARD.md`'s own KPI tiles |
| AI feeds | `aiTaxKeys.briefing`, `aiTaxKeys.proposals` | `10_000`, `refetchOnWindowFocus: true` | Matches the platform's AI-feed row verbatim — a returning Tax Manager should see what the Compliance Agent produced overnight |

## Server-first paint, then Suspense per region

`app/(app)/tax/returns/page.tsx` is a Server Component that prefetches the registration roster (the query
every other region's first paint benefits from having warm) and streams every other region behind its own
`<Suspense>` boundary, mirroring the exact pattern `BANKING.md`'s own `accounts/page.tsx` uses:

```tsx
// app/(app)/tax/returns/page.tsx
import { Suspense } from "react";
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { taxKeys } from "@/lib/api/query-keys";
import { Can } from "@/components/auth/can";
import { WidgetErrorBoundary } from "@/components/shared/widget-error-boundary";
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";
import { TaxLiabilityKpiBand } from "@/components/tax/tax-liability-kpi-band";
import { FilingDeadlineRail } from "@/components/tax/filing-deadline-rail";
import { AiTaxAdvisorPanel } from "@/components/tax/ai-tax-advisor-panel";
import { TaxReturnsTable } from "@/components/tax/tax-returns-table";
import { TaxPageHeader } from "@/components/tax/tax-page-header";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // tenant-scoped — never statically cached

export default async function TaxOverviewPage() {
  const queryClient = getQueryClient();
  await queryClient.prefetchQuery({
    queryKey: taxKeys.registrations(),
    queryFn: () => apiServer.get("/tax/registrations"),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <div className="space-y-6">
        <TaxPageHeader />
        <WidgetErrorBoundary widgetId="tax_liability_kpi_band">
          <Suspense fallback={<WidgetSkeleton variant="kpi-strip" />}>
            <TaxLiabilityKpiBand />
          </Suspense>
        </WidgetErrorBoundary>
        <WidgetErrorBoundary widgetId="filing_deadline_rail">
          <Suspense fallback={<WidgetSkeleton variant="rail" />}>
            <FilingDeadlineRail />
          </Suspense>
        </WidgetErrorBoundary>
        <Can permission="reports.read">
          <WidgetErrorBoundary widgetId="ai_tax_advisor_panel">
            <Suspense fallback={<WidgetSkeleton variant="ai-card" />}>
              <AiTaxAdvisorPanel />
            </Suspense>
          </WidgetErrorBoundary>
        </Can>
        <WidgetErrorBoundary widgetId="tax_returns_table">
          <Suspense fallback={<WidgetSkeleton variant="table" />}>
            <TaxReturnsTable />
          </Suspense>
        </WidgetErrorBoundary>
      </div>
    </HydrationBoundary>
  );
}
```

Each region's `<Suspense>` boundary is paired with its own `WidgetErrorBoundary`, per
`FRONTEND_ARCHITECTURE.md`'s three-granularity error model — a Forecast Agent timeout on the AI panel never
delays the KPI band or blanks the Returns table beside it (see **States**).

## Realtime

Tax subscribes to one company-scoped, module-wide private channel, following the exact
`private-company.{company_id}.<feature>[.{sub_id}]` convention `FRONTEND_ARCHITECTURE.md → Realtime`
establishes and `BANKING.md` already extends for its own module, rather than minting a per-registration or
per-return channel that would multiply subscriptions for a multi-jurisdiction company:

| Channel | Events carried | Effect |
|---|---|---|
| `private-company.{id}.tax` | `tax.calculated`, `tax.committed`, `tax.return.status_changed`, `tax.return.filed`, `tax.rate.scheduled`, `tax.validation_warning_raised`, `tax.certificate_expiring`, `tax.anomaly_flagged` — the domain events `docs/accounting/TAX.md → Accounting Integration` and `→ Notifications` name | Drives cache invalidation/patching, see below |
| `private-company.{id}.ai-jobs` | Any `ai_decisions` row authored by the Tax Advisor, Compliance Agent, Risk Detection, or Forecast Agent capabilities finishing a run | Refreshes the AI Tax Advisor panel and the Compliance Risk Score tile the moment a relevant agent run completes |
| `private-company.{id}.notifications.{user_id}` | Filing calendar reminders, certificate-expiry notices, anomaly/risk digests (`docs/accounting/TAX.md → Notifications`) | Feeds the Topbar's notification bell only — this screen's own regions do not duplicate this stream, matching `BANKING.md`'s and `DASHBOARD.md`'s identical rule |

Event handling follows `FRONTEND_ARCHITECTURE.md → Invalidate, or patch — chosen per event, not by
default`:

- `tax.return.status_changed` and `tax.return.filed` invalidate `taxKeys.returns()` and the specific
  `taxKeys.return(id)` entry as a full `invalidateQueries` — a return's lifecycle status is never patched
  optimistically from a realtime push, because filing and approval are exactly the class of mutation
  Principle 10 reserves for a pessimistic, server-confirmed update.
- `tax.committed` (a new batch of `tax_transactions` posted from Sales/Purchasing/Payroll/Banking/Inventory)
  invalidates `taxKeys.liability()` and `taxKeys.transactions()` — a fresh batch of transactions can move
  Net Payable, so the KPI band is never left showing a stale figure after a busy invoicing day.
- `tax.rate.scheduled` invalidates `taxKeys.rates()` only — the Codes & Rates screen's Rates tab, open in
  another tab or by another user, reflects a newly scheduled change without a manual refresh.
- `tax.validation_warning_raised` and `tax.certificate_expiring` patch the AI Tax Advisor panel's
  `findings` array in place (`setQueryData`, not a full refetch), mirroring `BANKING.md`'s identical rule
  for its own high-frequency, low-risk AI-feed updates.
- On WebSocket reconnect after any extended drop, every one of this screen's realtime-fed query keys
  (`taxKeys.*`, `aiTaxKeys.*`) is invalidated once as a single batch — a missed `tax.certificate_expiring`
  event during an outage must surface the moment connectivity returns, never wait for a manual refresh,
  exactly as `BANKING.md` and `FRONTEND_ARCHITECTURE.md`'s Edge Cases table both specify.

## AI agents feeding this screen

| Agent / capability | Contribution to this screen |
|---|---|
| Tax Advisor — Tax Classification | Populates the Codes tab's AI-suggested `tax_code_id`/`product_tax_treatment` proposal queue; auto-applies (with an audit-logged, reversible entry) at ≥0.90 confidence for a category with a 100%-consistent historical pattern, per `docs/accounting/TAX.md → AI Responsibilities` |
| Compliance Agent — Tax Validation | Feeds the Findings Bar on the Return Detail's Lines tab (hard blocks surface on the *calling* module's own screen at commit time; this screen only shows the aggregated, already-resolved warning count for the period) |
| Compliance Agent — Compliance Monitoring | Registration-threshold, certificate-expiry, and filing-calendar findings inside the AI Tax Advisor panel and the Filing Deadline rail's urgency treatment |
| Tax Advisor — Tax Optimization | A recommendation card inside the AI Tax Advisor panel (e.g., a cash-vs-accrual basis election suggestion) with its estimated financial-impact range, always suggest-only |
| Fraud Detection / Auditor — Anomaly Detection | A weekly, non-interruptive digest (never an immediate interruptive alert) linked from the AI Tax Advisor panel's "View full compliance report" |
| Compliance Agent / Auditor — Risk Detection | Computes the Compliance Risk Score `KpiTile`'s 0–100 figure and its decomposed factors, shown in the tile's tooltip |
| Forecast Agent — Tax Forecasting | Supplies the `TrendSparkline` inside the AI Tax Advisor panel and the confidence-by-horizon figure next to it |
| Tax Advisor — Tax Explanation | Powers the "why was this taxed at X%" trigger available on any Transactions row, fully autonomous and read-only (see **AI Integration**) |
| Compliance Agent — Regulation Change Alerts | Surfaces a pre-drafted, unsaved rate-change proposal inside the Rates tab when a monitored government bulletin implies one — never inserts a `tax_rates` row itself |

# Interactions & Flows

**Opening the screen.** The page shell (sub-nav, header) paints immediately; the KPI band and Filing
Deadline rail resolve first, since the prefetched registration roster and the liability report are both
cheap relative to the AI panel's synthesis step, which streams in independently a beat later — no region
blocks another, per **Data & State**.

**Preparing a return.** Clicking "Prepare return" (`tax.filing.prepare`) — from the page header's split
button, a Filing Deadline card with no `currentReturn`, or the Returns table's row action — calls
`POST /tax/returns` with the target registration and period, and the page navigates straight to
`/tax/returns/{newId}` once the draft exists. Unlike the Journal Entry Composer, there is **no manual line-
entry form here at all**: `docs/accounting/TAX.md → Tax Philosophy`'s first principle — "tax is derived,
never authored twice" — means a return's `tax_return_lines` are computed server-side from the period's
already-posted `tax_transactions` at generation time, box by box, exactly the shape the VAT Report example
in `docs/accounting/TAX.md → Reports` shows. The Return Detail page a user lands on is therefore closer in
spirit to `LAYOUT_SYSTEM.md`'s **Report Page Template** (a Trial Balance is also generated, not typed) than
to a Composer — there is no `JournalLineGrid`-style editable grid anywhere in this module, and this is a
deliberate, structural difference from Journal Entries worth stating plainly: a Tax Manager reviews and
acts on a return, they do not compose one line by line.

**Reviewing a return.** The Lines tab renders every `tax_return_lines` row (`box_number`, bilingual
`line_label_en`/`line_label_ar`, `taxable_amount`, `tax_amount`) as a plain, print-faithful table with a
totals row for `net_tax_payable`, and the Findings Bar above it surfaces the GL-variance reconciliation
result and any open Tax Validation warnings on the period's transactions — both read-only findings a Tax
Manager consults before acting, never inline-editable fields. The Transactions tab, once the return has
moved past `draft`, shows exactly the `tax_transactions` rows this return's generation locked in via their
`tax_return_line_id` back-reference — the same read-only `<TaxTransactionsTable/>` the standalone
Transactions screen uses, scoped by `defaultFilters`.

**Submit for review → Approve.** "Submit for review" (`tax.filing.prepare`) calls
`POST /tax/returns/{id}/submit-for-review`, moving `draft → under_review`; the page's primary action then
becomes an inline `ApprovalCard` (`kind="tax_return"`) for any caller holding `tax.filing.approve`, exactly
the same component and interaction the Approval Center itself uses for a `pending_approval` journal entry,
so a Finance Manager or Tax Manager reviewing a return sees the identical Approve/Reject/Request-Changes
affordance they already know from every other approval surface in the product. Approving calls
`POST /tax/returns/{id}/approve`, moving `under_review → ready_to_file`.

**Filing — the one irreversible step.** Once `ready_to_file`, the page header's primary action becomes
**File Return**, rendered only for a caller holding `tax.submit` — structurally absent, not disabled, for
everyone else, per **Route & Access**. Clicking it opens an `AlertDialog` that is the literal UI
implementation of `docs/accounting/TAX.md → Permissions`'s own sentence: a second confirmation summarizing
the exact net payable amount, the period, and the registration, with a required `approver_notes` field and
a `submit_to_government_api` toggle (defaulting on for jurisdictions whose `compliance_profile` advertises
a live e-filing channel, off otherwise):

```tsx
// components/tax/file-return-dialog.tsx ("use client")
export function FileReturnDialog({ taxReturn, open, onOpenChange }: FileReturnDialogProps) {
  const { mutate: fileReturn, isPending } = useFileTaxReturn(taxReturn.id);
  const [notes, setNotes] = useState('');
  const [submitToGovernment, setSubmitToGovernment] = useState(taxReturn.jurisdiction.compliance_profile.e_invoicing ?? false);

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogTitle>File {taxReturn.return_type.toUpperCase()} return — {taxReturn.period_start.slice(0, 7)}?</AlertDialogTitle>
        <AlertDialogDescription>
          You are about to file a net payable of{' '}
          <AmountCell amount={taxReturn.net_tax_payable} currencyCode={taxReturn.filing_currency_code} emphasis="strong" />
          {' '}with {taxReturn.jurisdiction.name_en}. This cannot be undone from within QAYD.
        </AlertDialogDescription>
        <Textarea
          required
          placeholder="Reviewed against GL trial balance for the period; variance within tolerance."
          value={notes}
          onChange={(e) => setNotes(e.target.value)}
        />
        <Checkbox checked={submitToGovernment} onCheckedChange={setSubmitToGovernment}>
          Submit electronically via {taxReturn.jurisdiction.compliance_profile.portal_name ?? 'the government portal'}
        </Checkbox>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction
            disabled={!notes || isPending}
            onClick={() => fileReturn({ approved_by_confirmation: true, approver_notes: notes, submit_to_government_api: submitToGovernment })}
          >
            File return
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
```

`useFileTaxReturn` carries no `onMutate` — no optimistic state at all — per `FRONTEND_ARCHITECTURE.md`
Principle 10's rule that a money-moving, irreversible mutation only ever shows "filed" after the server has
actually said so. On success, the response's `government_reference_number` and `government_ack_payload`
populate the `ZatcaFilingStatusCard` immediately from the mutation's own return value (`setQueryData`, not
a refetch — the response already carries everything the card needs).

**Recording a government acknowledgment manually.** When `submit_to_government_api` was left off, or an
inline submission timed out waiting on the government portal, the Filing & ZATCA tab's
`ZatcaFilingStatusCard` shows a "Record acknowledgment" action (still gated `tax.submit` — this is the
same irreversible-adjacent surface, never a lesser permission) opening a small form for
`government_reference_number` and the raw ack payload, calling `POST /tax/returns/{id}/record-ack`.

**Amending a filed or accepted return.** The overflow menu's "Amend" action (visible only once
`status ∈ {filed, accepted, rejected}`) opens a confirmation naming that amending creates a brand-new
`tax_returns` row referencing this one, per `docs/accounting/TAX.md → History and Versioning` — QAYD never
edits a filed return in place. Confirming calls `POST /tax/returns` with an `amends_return_id` payload
field and navigates to the new draft, which starts its own full Prepare → Review → File lifecycle.

**Transactions screen — reversing and adjusting.** A posted transaction's row action "Reverse"
(`tax.adjust`) opens an `AlertDialog` requiring a reason before calling
`POST /tax/transactions/{id}/reverse`; "New adjustment" opens a `Sheet` form (registration, tax code,
direction, amount, mandatory `reason` and a required attachment via the polymorphic `attachments` table)
calling `POST /tax/adjustments` — both mirroring `docs/accounting/TAX.md → Adjustments`'s requirement that
"the engine never lets a manual adjustment blend invisibly into system-generated lines": every row this
flow creates renders with the `adjusted`/`warning`-tone `StatusPill` and its `reason_code` visible in an
info tooltip, never indistinguishable from a system-computed row.

**Scheduling a rate change — the Rates tab's own careful UX.** Because `tax_rates` carries a PostgreSQL
`EXCLUDE USING gist` constraint that physically rejects two overlapping effective-date windows for the same
`(tax_code_id, jurisdiction_id)` pair (`docs/accounting/TAX.md → Tax Rates`), the Rates tab does not offer a
generic "edit rate" action on an active row at all — it offers only **"Schedule rate change,"** which opens
a `Dialog` (never a quick inline `Sheet`, per **Layout & Regions**) pre-filled with the current rate and a
date picker whose minimum selectable date is constrained client-side to the day after the current row's
`effective_from` (a friendly, non-authoritative echo of the same rule the database itself enforces):

```tsx
// components/tax/schedule-rate-change-dialog.tsx ("use client")
export function ScheduleRateChangeDialog({ currentRate, open, onOpenChange }: ScheduleRateChangeDialogProps) {
  const { mutate: scheduleChange, isPending, error } = useScheduleRateChange();
  const form = useForm<ScheduleRateChangeValues>({
    resolver: zodResolver(scheduleRateChangeSchema),
    defaultValues: { new_rate_percentage: currentRate.rate_percentage, close_current_rate: true },
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogTitle>Schedule a rate change — {currentRate.tax_code.code}</DialogTitle>
        <DialogDescription>
          Current rate {currentRate.rate_percentage}% has been in force since{' '}
          <span dir="ltr">{currentRate.effective_from}</span>. The new rate takes effect on the date you
          choose below; QAYD closes the current rate the day before, atomically, in one transaction.
        </DialogDescription>
        <Form {...form}>
          <FormField name="new_rate_percentage" render={({ field }) => <Input {...field} type="text" inputMode="decimal" />} />
          <FormField
            name="effective_from"
            render={({ field }) => (
              <DatePicker {...field} minDate={addDays(new Date(currentRate.effective_from), 1)} />
            )}
          />
          <FormField name="legal_reference" render={({ field }) => <Input {...field} placeholder="Royal Decree No. …" />} />
        </Form>
        {error?.code === 'RATE_WINDOW_OVERLAP' && (
          <Alert variant="danger">
            This effective date overlaps an existing rate window for this code and jurisdiction. Choose a
            later date or close the conflicting rate first.
          </Alert>
        )}
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button disabled={isPending} onClick={form.handleSubmit((v) => scheduleChange({ ...v, tax_code_id: currentRate.tax_code_id, jurisdiction_id: currentRate.jurisdiction_id }))}>
            Schedule change
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
```

The client-side `minDate` is a UX courtesy exactly per `FRONTEND_ARCHITECTURE.md` Principle 1 — it never
substitutes for the server's own `EXCLUDE USING gist` check, which is why `RATE_WINDOW_OVERLAP` is still
handled explicitly as a distinct, named error rather than assumed impossible. `useScheduleRateChange` calls
`POST /tax/rates/schedule-change`, is pessimistic (no `onMutate`), and on success invalidates
`taxKeys.rates()` — both the closed row and the new `scheduled` row return in one response, per
`docs/accounting/TAX.md`'s own example, and the tab's table re-renders both changes in the same paint.

**Rules tab — reordering and testing.** Rules render as a `priority`-ordered, drag-reorderable list
(`tax.rule.manage`), each row summarizing its `conditions` in plain language rather than raw JSON. A
"Test this rule" action (`tax.calculate`) opens a `Sheet` accepting a small sample transaction and calling
`POST /tax/calculate` in `preview` mode, showing which rule actually matched and why — a debugging aid for
the Tax Manager, never a way to price a real document from this screen.

**Jurisdictions, Registrations, Exemption Certificates, Recovery Ratios.** These four tabs follow the
platform's ordinary settings-table pattern (`DataTable` + a `Sheet` create/edit form, gated per **Route &
Access**) without a bespoke interaction of their own. Two callouts: an Exemption Certificate row nearing
`valid_to` renders a `warning`-tone expiry chip fed by the same Compliance Monitoring capability that
raises the 30/7-day notification (`docs/accounting/TAX.md → Notifications`), so the Compliance Agent's
alert and this table's own visual state always agree; a Recovery Ratio's "Approve" action
(`tax.recovery.manage`) is the one write on this tab that is pessimistic rather than optimistic, since a
recovery ratio directly changes how much input tax is recoverable across every subsequent transaction.

**Approving or rejecting an AI classification proposal.** The Codes tab's AI-suggested queue renders each
pending `ai_decisions` row through the shared `AiCardShell`, with `Approve`/`Reject` calling
`POST /tax/ai/proposals/{id}/approve|reject` (`tax.config.manage`) — see **AI Integration** for the full
confidence-banded behavior.

**Clicking a Filing Deadline card or a certificate-expiry notification.** Both navigate straight to the
relevant `/tax/returns/{id}` or `/tax/codes?tab=exemption-certificates&highlight={certId}` — every
AI-surfaced or deadline-surfaced target on this screen is a real, resolvable route, never a dead end,
matching `NAVIGATION_SYSTEM.md`'s "Every AI-surfaced target is a real, resolvable route" rule.

# AI Integration

Tax is, module-for-module, one of the most AI-dense screens in QAYD — nine distinct AI responsibilities
`docs/accounting/TAX.md → AI Responsibilities` defines all surface somewhere in this document — and it is
also the one module where the platform's "AI proposes, a human approves" principle carries the highest
stakes, since its terminal action is a legal filing with a government authority. Every AI-authored element
on this screen renders through the exact same contract `FRONTEND_ARCHITECTURE.md → AI Integration Layer`
defines for the rest of the product: `confidence_score`, `reasoning`, `sources`, and, where actionable,
`recommended_action`/`alternatives`, wrapped in the mandatory `AiCardShell` (colored left border, `AI`
badge, `ConfidenceBadge`, a collapsed-by-default `ReasoningDisclosure`) — this screen never invents its own
visual treatment for "this came from the AI layer."

## Confidence banding — Tax Classification, the one case with a real autonomy split

Of the nine responsibilities, **Tax Classification** is the only one with more than one autonomy tier, and
the Codes tab's AI-suggested queue renders all three explicitly rather than collapsing them into one
generic "pending" state:

| Confidence | UI treatment |
|---|---|
| < 0.70 | Rendered in the queue as **blocked** — a `danger`-toned "Needs review" chip, no Approve action at all until a Tax Manager manually assigns the code; this mirrors the platform's `can_execute_directly` field being server-computed and simply absent here, not a client-side threshold check. |
| 0.70 – 0.89 | Rendered as a normal `AiCardShell` proposal with **Approve** and **Reject** — the classic "pre-filled suggestion the user must confirm" state; Approve calls `POST /tax/ai/proposals/{id}/approve`. |
| ≥ 0.90 (and a 100%-consistent historical pattern for the category) | Auto-applied server-side already by the time it reaches this screen — it never appears in the pending queue at all. It instead appears once, the next morning, inside a distinct **"AI-classified items"** digest card (a lighter, dismissible list, not individual `AiCardShell`s) for spot-check, exactly `docs/accounting/TAX.md`'s own daily-digest language. Every auto-applied classification remains fully reversible (a Tax Manager can re-open the product's tax-code field and change it) and is written to `audit_logs` like any other change — the UI never implies "this cannot be undone" for a classification the way it does for a filing. |

This is the one place in the entire Tax module where `can_execute_directly`-style autonomy is real —
everywhere else (adjust, rate-change, submit, approve), the server-computed autonomy is always `false` for
the AI actor, by construction, and the corresponding button structurally does not render for an AI-authored
proposal at all. `FRONTEND_ARCHITECTURE.md`'s own words apply verbatim: "the frontend never independently
evaluates... from raw `ai_automation_rules` data" — the 0.70/0.90 thresholds shown in the table above are
documented here for engineers' understanding of *why* a given card looks the way it does, never
re-implemented as a client-side branch on the raw `confidence_score`.

## Tax Validation and Compliance Monitoring — deterministic, never probabilistic where it blocks

Tax Validation's hard blocks (a missing `tax_rates` row, an unregistered vendor charging tax) are not this
screen's concern to render as a gate — they fire as a `422` on the *calling* module's own commit action
(an invoice being posted in `INVOICES.md`, a bill in `PURCHASING.md`), per
`docs/accounting/TAX.md → Tax Calculation Engine` step 4's explicit design choice to fail loudly rather
than silently zero-rate. What this screen shows instead is the aggregated, already-resolved consequence:
the Findings Bar on a Return's Lines tab counts any warnings still open for transactions inside that
period, and the AI Tax Advisor panel's Compliance Monitoring findings (registration-threshold proximity,
certificate expiry, filing-calendar status) carry no confidence score at all when they are pure
date/threshold arithmetic — `docs/accounting/TAX.md` is explicit that these are deterministic, not
probabilistic, and the UI does not manufacture a fake confidence number for a fact that is simply true or
false on a given date. Only the unstructured-source variant — a Regulation Change Alert inferred from a
government bulletin ingested via WebFetch/RSS — carries a real confidence score, and its card is labeled
plainly ("unconfirmed — verify before acting") whenever that source-reliability confidence is low, per
`docs/accounting/TAX.md`'s own instruction.

## Tax Forecasting — confidence that decays with horizon, shown honestly

The AI Tax Advisor panel's forecast sparkline pairs with a `confidence_by_horizon` array rather than one
number, and the panel renders the *nearest* period's confidence next to the chart while the tooltip on
hover shows the full decay ("Next period: 92% confidence; Period +3: 61% confidence") — exactly
`docs/accounting/TAX.md`'s own worked example. This screen never averages the array into a single
misleadingly-confident headline figure.

## Tax Explanation — the one fully autonomous, read-only capability

Any row in the Transactions table (standalone screen or the Return Detail's Transactions tab) exposes a
"Why was this taxed this way?" row action, available to any `tax.read` holder, opening a `Sheet` that
calls a read-only explanation endpoint and renders the row's own `calculation_lineage` JSONB as plain
language — which jurisdiction resolved, which `tax_rules` row matched and at what priority, which
`tax_rates` row and its effective window, and the rounding rule applied. Per
`docs/accounting/TAX.md → AI Responsibilities`, this capability's `confidence_score` is always exactly
`1.0000` for a posted transaction, because it is "a direct trace of the deterministic calculation lineage
stored with the transaction, not a probabilistic inference" — the `ConfidenceBadge` inside this specific
Sheet is rendered at its maximum, fully-filled state every time, never a live-computed number, and this is
the one AI-attributed surface on the whole screen with no Approve/Reject pair at all, because there is
nothing to approve: it explains a fact that already happened.

## Where AI structurally cannot reach — the three-layer proof

`docs/accounting/TAX.md → Permissions` describes `tax.submit` as enforced "at three layers
simultaneously": the Laravel policy layer, "the state-machine layer (... rejects the request unless it
originates from a human session — API requests bearing only a service/AI-layer token are rejected outright
regardless of any permission grant)," and the UI layer this document specifies. The consequence for this
screen is concrete and testable: there is no code path — no confidence threshold, no company policy toggle,
no `can_execute_directly` flag — under which the **File Return** button, the **Approve** action on a
return, or the **Reverse/Adjust** actions on a transaction render for anything other than a signed-in human
session holding the matching permission. Regulation Change Alerts make this legible rather than
mysterious: even when the Compliance Agent's proposed rate change is well-sourced and high-confidence, it
renders strictly as a **pre-drafted, unsaved** card inside the Rates tab — pre-filling the "Schedule rate
change" `Dialog`'s fields on click, never calling `POST /tax/rates/schedule-change` on the AI's own
authority — exactly `docs/accounting/TAX.md`'s own sentence: "the agent never inserts or modifies a
`tax_rates` row itself... a human commits it through `TaxRateService::scheduleRateChange()`."

# States

Every region defined in **Layout & Regions** carries its own independent loading, empty, and error state,
per `FRONTEND_ARCHITECTURE.md`'s three-granularity error model — no region's failure or slow resolution
ever blanks a sibling region.

| Region | Loading / skeleton | Empty | Error |
|---|---|---|---|
| KPI band | Four `KpiTile`s with `loading` prop set (`Skeleton` in place of value/delta), per `COMPONENT_LIBRARY.md → KpiTile` | N/A — a company with at least one active registration always has a computable (possibly zero) liability figure | `WidgetErrorBoundary` renders a compact inline "Couldn't load tax liability, retry" card in place of the four tiles, never a blank strip |
| Filing Deadline rail | `WidgetSkeleton variant="rail"` — four placeholder cards matching the real card's dimensions | A brand-new company with zero `tax_registrations` renders a dedicated **"No tax registrations yet"** `EmptyState` with a "Register a jurisdiction" CTA into the Codes & Rates → Registrations tab — distinct from a filtered-to-zero result, per `FRONTEND_ARCHITECTURE.md`'s Edge Cases rule that a not-yet-configured empty state must never look identical to a genuine zero-results search | Inline retry card, isolated to the rail |
| AI Tax Advisor panel | `WidgetSkeleton variant="ai-card"` | Renders nothing at all rather than an empty-state card — an AI panel with genuinely no findings is not an error or a gap, it is good news, and `AiTaxAdvisorPanel`'s own early `return null` (see **Components Used**) means the region simply does not occupy space that day | Isolated retry card; never silently disappears on error (distinct from the "nothing to say" empty case — an error always announces itself) |
| Returns table | `DataTable`'s built-in 8-skeleton-row placeholder | `EmptyState`: "No returns yet — prepare your first return from the Filing Deadline card above" for a genuinely empty company; a lighter "No returns match your filters" variant for a filtered-to-zero search, per `COMPONENT_LIBRARY.md → DataTable`'s documented distinction |
| Transactions table | `DataTable`'s skeleton rows | `EmptyState`: "No tax transactions yet — transactions appear here once Sales, Purchasing, Payroll, or Banking documents are posted" (a genuinely new company) vs. the filtered variant | `ErrorState` per `COMPONENT_LIBRARY.md` |
| Return Detail | Route-level `loading.tsx` skeleton matching the Report+Detail hybrid's exact shape (never a generic spinner, per `FRONTEND_ARCHITECTURE.md`'s "never blank" rule) | N/A — a return, once it exists, always has at least a header and (once past `draft`) at least one line | Route-level `error.tsx`; a `returnId` that does not resolve in the caller's company renders `not-found.tsx`, indistinguishable from a `returnId` that never existed, per **Route & Access**'s 403-vs-404 discipline |
| Codes & Rates tabs | Per-tab `DataTable` skeleton | Per-tab `EmptyState`, e.g. "No exemption certificates on file" | Per-tab `ErrorState`, isolated to that tab — a Rates-tab fetch failure never blanks the Codes tab beside it |

A **mutation**-in-flight state is distinct from a **query**-loading state throughout this document: File
Return, Approve, Submit-for-review, Schedule rate change, and Reverse/Adjust all render their triggering
button in a `loading` state (spinner replacing the label, button disabled) for the duration of the request
— never an optimistic "success" state ahead of the server's actual `2xx`, per **Interactions & Flows** and
`FRONTEND_ARCHITECTURE.md` Principle 10.

# Responsive Behavior

This screen follows `LAYOUT_SYSTEM.md → Breakpoints`' five-tier system (`base`/`sm`/`md`/`lg`/`xl`, plus the
`3xl` ultra-wide tier) exactly — no bespoke breakpoint is introduced.

| Breakpoint | KPI band | Filing Deadline rail | AI panel | Returns / Transactions table | Return Detail |
|---|---|---|---|---|---|
| `base`–`sm` (phone) | Single column, one `KpiTile` per row | Vertically stacked cards, not horizontally scrollable (a horizontal `snap-x` rail is a poor fit under 375px width) | Full width, collapsed reasoning by default | `DataTable` renders as a stacked card list, per `RESPONSIVE_DESIGN.md → Pattern 1 — Data tables become cards`: each row becomes a card showing Return #/Registration/Status/Net Payable, with a "View" tap target ≥44px | Summary Rail moves below the Main Column; Tab nav becomes a horizontally-scrollable segmented control |
| `md` (tablet) | Two columns, two rows | Horizontally scrollable rail begins (`snap-x`) | Full width | Table still renders as cards at `md` per the same pattern's tablet-portrait guidance, switching to a real table at `lg` | Same stacked order as mobile |
| `lg` (small laptop) | Four columns, one row | Horizontally scrollable rail, 3–4 cards visible | Full width, inline with the KPI band's width | Real `<table>` markup begins | Summary Rail becomes a true 4/12 side rail (`Detail Page Template`'s standard split) |
| `xl`+ (laptop/desktop) | Four columns | Full rail visible without scrolling on most companies' registration counts | Full width, uncollapsed by default | Full table, all columns | Full two-column layout; **reading container** caps at 1440px (`LAYOUT_SYSTEM.md → Container widths`) since a Return Detail is a reading/review surface, not a full-bleed dashboard |
| `3xl` (ultra-wide desk monitor) | Four columns, extra whitespace rather than a fifth tile — `LAYOUT_SYSTEM.md` reserves stretched width for full-bleed templates only | Unchanged | Unchanged | The Overview screen (a Dashboard-Template hybrid) is a **full-bleed** container, capping at 1600px per `LAYOUT_SYSTEM.md`, so the Transactions/Returns tables get real room on a Finance desk's 32"+ monitor without ever stretching the KPI tiles themselves edge-to-edge |

The Codes & Rates screen's eight tabs collapse into a horizontally-scrollable `Tabs` strip below `md`,
matching `LAYOUT_SYSTEM.md`'s general tab-overflow guidance rather than a bespoke dropdown — a Tax Manager
configuring rates from a tablet still reaches every tab by a swipe, not a menu. Touch targets throughout
(row-action icon buttons, the Filing Deadline rail's cards, the File Return button) respect
`ACCESSIBILITY.md → Target size`'s 24×24 CSS px floor for icon-only controls and the platform's own 44px
internal bar for primary/touch-surface actions — the File Return button in particular is never rendered
below 44px tall on any breakpoint, given what a mis-tap there would risk.

# RTL & Localization

Every string on this screen is a `next-intl` key under a `tax.*` namespace, resolved through the same
`useTranslations`/`applyI18n`-equivalent mechanism every other screen in the product uses — this document
introduces no ad hoc hardcoded English string. Bilingual data fields already exist at the source: every
`tax_jurisdictions`, `tax_categories`, `tax_codes`, `tax_rules`, `tax_groups`, and, critically,
`tax_return_lines` row carries both `name_en`/`name_ar` (or `line_label_en`/`line_label_ar`) columns per
`docs/accounting/TAX.md → Database Design`, so the VAT Report's box labels render in the caller's active
locale directly from the API response — this screen never machine-translates a statutory line label
client-side.

`dir="rtl"` applies at the page-container level for the `ar` locale, mirroring every layout region via
Tailwind's logical properties (`ms-`/`me-`/`ps-`/`pe-`), exactly `FRONTEND_ARCHITECTURE.md` Principle 6 and
`LAYOUT_SYSTEM.md → RTL Layout Mirroring` require: the Filing Deadline rail's scroll direction flips, the
Return Detail's Summary Rail moves to the visually-leading (now right) edge, and the sub-nav `Tabs` read
right-to-left in the same DOM order used for LTR (per `ACCESSIBILITY.md → Tab order`'s "DOM order never
manually re-ordered per locale" invariant).

Three categories of value are deliberately pinned `dir="ltr"` inside an otherwise-RTL page, matching
`FRONTEND_ARCHITECTURE.md → Forms & Validation → RTL forms and bidirectional input` and
`DESIGN_LANGUAGE.md`'s numeral-formatting rules exactly:

- **Every monetary amount and every tax rate percentage** (`AmountCell`, the Rates tab's `rate_percentage`
  column, the Schedule Rate Change dialog's numeric input) — a `15.0000%` or `SAR 106,800.00` reads
  left-to-right regardless of interface language, wrapped in the platform's standard bidi-isolation span
  when it appears mid-sentence inside Arabic prose (the AI Tax Advisor panel's findings text, the Tax
  Explanation sheet's reasoning).
- **The government reference number** on the `ZatcaFilingStatusCard` — a `ZATCA-VAT-2026-06-SA-000481223`
  string is a structured code, not prose, and is pinned `dir="ltr"` for the identical reason an IBAN or an
  account code is elsewhere in the platform.
- **Box numbers** (`1a`, `1b`, `3`, `9`, `12` …) on the Return Lines table — these are the government form's
  own numbering and must never visually reorder relative to the label they accompany.

Currency formatting follows `AmountCell`'s existing minor-unit table (`formatAmount`) without modification:
KWD/BHD/OMR at 3 decimals, most others at 2 — a multi-currency company viewing a KSA return (SAR) beside a
Kuwait corporate-tax filing (KWD) sees each figure at its own currency's correct precision, never one
table-wide decimal format, per `FRONTEND_ARCHITECTURE.md → Edge Cases`'s mixed-currency rule.

# Dark Mode

Every token this screen uses resolves through `DESIGN_LANGUAGE.md`'s CSS variables (`--qayd-ink-*`,
`--qayd-accent*`, `--qayd-positive/negative/warning*`) with an explicit dark-mode value for each — this
document introduces no new token and no component that reads a raw hex value. Three module-specific
callouts:

- **The Compliance Risk Score tile's gauge** uses the `warning`/`negative` semantic tokens exactly
  as defined for both themes — a risk score in the "elevated" band is never a raw, un-themed red that
  clashes with the calibrated dark-mode ramp `DESIGN_LANGUAGE.md → Dark mode strategy` describes ("the
  accent gets lighter, not more saturated... backgrounds stay warm-neutral, not blue-black").
- **The AI Tax Advisor panel's left border and `AI` badge** use `accent-subtle`/`accent` in both themes,
  never a bespoke "AI blue" — per `DESIGN_LANGUAGE.md`'s explicit rule that the accent is reserved for
  exactly two things (the one primary action per screen, and anything AI-authored), and per `DARK_MODE.md`'s
  own "AI provenance color — reserved, never financial" rule, this screen's AI treatment is never confused
  with the Compliance Risk Score's semantic-financial coloring even though both can appear inside the same
  panel.
- **`StatusPill`'s tone-to-color mapping is unaffected by theme** — `TAX_RETURN_STATUS`/`TAX_TRANSACTION_STATUS`
  (**Components Used**) reference semantic tone names (`neutral`/`accent`/`success`/`warning`/`danger`),
  never raw hex, so the identical lookup table this document defines renders correctly in both themes
  without a second, dark-specific table — exactly the same non-duplication `BANKING.md`'s own status tables
  rely on.

Printing a filed return (the Return Detail's Export action, or a browser print of the Lines tab) always
renders in the light, print-optimized stylesheet regardless of the viewer's active theme, per
`FRONTEND_ARCHITECTURE.md → Edge Cases`'s "printing a financial statement" rule — a VAT return handed to a
government auditor never carries a dark background or the AI badge's accent border, since neither has any
meaning on paper.

# Accessibility

## Data tables — plain pattern only, no third grid surface

`ACCESSIBILITY.md → Data Tables Accessibility` names exactly two surfaces in the entire platform that
warrant the heavier ARIA `grid` pattern: "the Journal Entry line editor, and the Bank Reconciliation
matching grid." Every table this document specifies — the Returns table, the Transactions table (both
standalone and embedded in a Return's Transactions tab), the Return Lines table, and every Codes & Rates
tab — uses the **Plain table pattern** instead: real `<table>`/`<thead>`/`<tbody>`/`<th scope="col">`
semantics, `aria-sort` on sortable headers, and `Tab`/`Shift+Tab` moving between rows' interactive elements
in DOM order, never arrow-key cell navigation. This is not a lesser choice; it is the *correct* one, per
the exact reasoning `JOURNAL_ENTRIES.md`'s own read-only `JournalLinesTable` gives for an identical case:
every row here is either an immutable, posted fact (`tax_transactions`) or a computed, statutory figure
(`tax_return_lines`) — there is nothing to arrow-key between, and introducing a third grid surface into a
platform that has deliberately limited itself to two would be a regression against that document's own
stated design intent, not an enhancement.

## Keyboard

Tab order follows DOM order/reading order in both languages, per `ACCESSIBILITY.md → Tab order`'s
invariant — this screen never uses CSS `order` or grid placement to visually reorder a field or a row
action without moving it in the DOM. The global shortcut table (`Cmd/Ctrl+K` Command Palette, `/` focus
search, `?` shortcuts help, `Esc` close top-most overlay) applies unmodified; per **Route & Access**, Tax
has no dedicated `G`-chord reserved yet in that shared table, so this document does not introduce one
unilaterally. Inside the File Return `AlertDialog` specifically, `Cmd/Ctrl+Enter` confirms the dialog's
primary action per the same global convention used everywhere else — but because that primary action is
the module's single most consequential mutation, the button it confirms remains `disabled` until the
required `approver_notes` field is non-empty, so the keyboard shortcut can never fire the mutation with an
empty confirmation reason.

## RBAC-aware disabled controls explain themselves

Per `ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves`, any control on this screen
that is disabled rather than hidden — the `ApprovalCard`'s Approve/Reject pair for a `tax.filing.read`-only
viewer, a Rules-tab drag handle rendered inert for a viewer who can see but not reorder — carries an
`aria-describedby` pointing at a tooltip naming the exact missing permission ("Requires
`tax.filing.approve`"), never a bare grayed-out control with no explanation. Everything else this document
gates (File Return, Schedule rate change, New registration, and so on) is *hidden*, not disabled, per the
platform's default "hide, don't disable" rule (`NAVIGATION_SYSTEM.md → Permission-Aware Nav`) — the
`ApprovalCard` exception exists for the identical reason `BANKING.md` gives its own approval cards the same
treatment: the card is already addressed to a specific approver, and making it vanish entirely would read
as a missed notification rather than a legible access boundary.

## Screen readers and amounts

Every `AmountCell` on this screen — net payable, output/input tax, a return's line amounts — carries an
unambiguous accessible name pairing the figure with its currency and its semantic role ("Net tax payable,
one hundred six thousand eight hundred point zero zero Saudi Riyal"), per
`ACCESSIBILITY.md → Screen Readers → Amounts must be read unambiguously` — a screen reader user never hears
a bare "106800" with no currency or contextual label. The AI Tax Advisor panel's findings list uses
`aria-live="polite"`, never `"assertive"`, matching `FRONTEND_ARCHITECTURE.md → Edge Cases`'s rule for every
AI feed in the product: a compliance finding surfacing while a Tax Manager is mid-read of the Return Lines
table must not interrupt them. The `ZatcaFilingStatusCard`'s government-acknowledgment update, arriving via
the realtime channel, is announced the same way — informative, never interruptive.

## Target size

Every icon-only row action across every table on this screen (View, Reverse, Adjust, Approve/Reject,
per-tab row edit) is built on the shared `IconButton` primitive, whose invisible hit-area padding enforces
`ACCESSIBILITY.md → Target size`'s 24×24 CSS px floor regardless of how small the visual Lucide glyph is
drawn for density — no table in this document is permitted to hand-roll a smaller tap target.

# Performance

## Rendering tier

Overview (`returns/page.tsx`) is treated like `DASHBOARD.md`'s own home screen for prefetch/streaming
priority: the registration roster prefetches server-side, every other region streams behind its own
`<Suspense>` boundary (**Data & State**). Return Detail is treated like a Report Page Template screen
(`TRIAL_BALANCE.md`'s own precedent) for RSC payload discipline — it fetches the fields the Lines tab
actually renders and lets the Transactions and History tabs' deeper data load as separate, lazily-triggered
queries only once that tab is actually opened, never smuggling all four tabs' full payloads across the RSC
wire on first paint, per `FRONTEND_ARCHITECTURE.md → RSC payload discipline`.

## Virtualization and pagination mode

`tax_transactions` is, by construction, an unbounded, ever-growing fact table — every taxable event in the
company's history eventually lands a row here. The standalone Transactions screen and the Return Detail's
embedded Transactions tab therefore both use `paginationMode="cursor"` (never `page`), matching
`FRONTEND_ARCHITECTURE.md → Pagination`'s treatment of `ledger-entries`/`audit-logs`/`stock-movements`, and
render through `@tanstack/react-virtual` once a company's history grows past a few hundred rows, per
`FRONTEND_ARCHITECTURE.md → Virtualization for large tables`. `tax_returns`, by contrast, is a small,
bounded, one-per-registration-per-period table for any real company (a handful to a few dozen active
registrations × monthly/quarterly periods) and correctly uses `paginationMode="page"` with
`placeholderData: keepPreviousData` instead — this document does not apply cursor pagination or
virtualization to a table that will never need it, matching `FRONTEND_ARCHITECTURE.md`'s own
per-resource-class discipline rather than a blanket policy.

## Code splitting and bundle budget

The VAT/GST/statutory-report PDF/XML export path (which reuses the same `InvoicePdfViewer`-class renderer
`FRONTEND_ARCHITECTURE.md → Performance` already names) is `next/dynamic`-split with `ssr: false`, since it
depends on browser Canvas/PDF rendering never useful server-side and is only reachable from the Export
menu's click — a Tax Manager who never exports a return never downloads that chunk. The Rules tab's
drag-and-drop reordering library is similarly split, since only the Codes & Rates route needs it. Lucide
icons are imported by name throughout (`ArrowUpRight`, `AlertTriangle`, `Clock`, `CheckCircle2`, and so on),
never as a namespace import, per the platform-wide tree-shaking convention.

## Caching recap

The four-tier cache-tuning table in **Data & State** is this screen's complete caching story — the browser
HTTP cache's `ETag` support additionally applies to the genuinely reference-only calls (`GET
/tax/jurisdictions`, `GET /tax/codes` when unfiltered) per `FRONTEND_ARCHITECTURE.md → Caching layers,
stacked deliberately`, letting a `304` skip re-serialization on a Tax Manager's second visit to the Codes
tab within the same short window. Nothing tenant-scoped on this screen is ever pushed to a CDN edge cache,
per that same document's absolute rule.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| Brand-new company, zero `tax_registrations` | The Filing Deadline rail and the KPI band both render their dedicated "not yet configured" `EmptyState` with a setup CTA into Codes & Rates → Registrations, never the generic zero-results empty state (**States**). |
| A rate-change window overlaps an existing one | The server's `EXCLUDE USING gist` constraint rejects the write; the client surfaces the named `RATE_WINDOW_OVERLAP` error inline in the Schedule Rate Change dialog (**Interactions & Flows**), never a generic "something went wrong" toast, per `FRONTEND_ARCHITECTURE.md`'s rule that a `422`'s field-level detail belongs next to the control that caused it. |
| A return's underlying transactions change after the return is generated (a late-posted invoice for a period already `under_review`) | The return's totals were locked in at generation time via `tax_return_line_id` back-references; a late-posted transaction for that period does **not** retroactively appear inside an existing return's Lines tab. The AI Tax Advisor panel instead surfaces a distinct finding ("A transaction posted after this return was generated — consider re-running reconciliation before filing"), and the Findings Bar's GL-variance check is the mechanism that would actually catch a resulting mismatch before filing — this mirrors `FRONTEND_ARCHITECTURE.md → Edge Cases`'s "AI proposal's target edited between proposal and approval" pattern: the UI surfaces the divergence rather than silently either including or ignoring it. |
| GL variance is non-zero at filing time | The Findings Bar's reconciliation check (**Layout & Regions**) renders a blocking, non-dismissible `warning` banner above the Lines tab once variance exceeds the 0.01 base-currency tolerance `docs/accounting/TAX.md → General Ledger` defines; the File Return button remains rendered (the permission gate is unaffected) but the confirmation `AlertDialog` repeats the variance figure explicitly, so a Tax Manager cannot file without having seen it stated twice. |
| The government e-filing portal times out or is unreachable during an inline `file` submission | The mutation surfaces the timeout as a retryable error (per `FRONTEND_ARCHITECTURE.md → Retry semantics`, a network-level failure is retryable, a `5xx` from the government-proxying endpoint is not auto-retried); the return's own `status` may already be `filed` server-side even if the acknowledgment payload didn't arrive client-side in time, so the UI's next fetch of the return (on retry or on next visit) is the source of truth, never a locally-assumed "it must have failed." The "Record acknowledgment" manual path (**Interactions & Flows**) exists specifically for this case. |
| Multi-currency return (company's base currency differs from the jurisdiction's filing currency) | The Summary Rail and the Lines table both render in `filing_currency_code` (per `docs/accounting/TAX.md → Multi Currency Support`'s two-conversion rule), with a `CurrencyTag` making the filing currency explicit next to every figure — never silently assumed to match the viewer's own company base currency. |
| External Auditor's time-boxed access expires mid-review | The next request after `company_users.access_expires_at` passes returns `403`; this screen's route-level `error.tsx` renders the standard forbidden state rather than a special "your access expired" copy that would otherwise need to be duplicated across every module — consistent with `FRONTEND_ARCHITECTURE.md`'s preference for one global classification over per-screen special-casing. |
| WebSocket disconnected during an active filing session | Per `FRONTEND_ARCHITECTURE.md → Edge Cases`, a missed `tax.return.filed`/`tax.certificate_expiring` event during a drop is unrecoverable by definition over a non-replaying transport; on reconnect, every `taxKeys.*`/`aiTaxKeys.*` entry is invalidated once as a batch, so a Tax Manager who filed a return just before a connection drop sees the correct, current status the moment connectivity returns rather than a stale `ready_to_file` pill. |
| Bulk-exporting the Tax Audit Report for a large period | Never awaited synchronously; per `FRONTEND_ARCHITECTURE.md → Edge Cases`'s bulk-export pattern, the export triggers a background `report_runs` job and the UI polls (or subscribes via `private-company.{id}.report-runs.{id}`) for completion, downloading the resulting signed URL only once done — a multi-thousand-row audit export can never appear to freeze the Return Detail or Transactions screen. |
| An amended return referencing an original that itself later gets amended again | Each amendment is its own full, independently-auditable `tax_returns` row (**Interactions & Flows**); the History tab on any return in the chain shows a "View original" / "View amendment" cross-link so a Tax Manager can trace the full chain without QAYD ever collapsing it into a single mutable record. |
| A company operates in a jurisdiction with no live e-filing API at all | `compliance_profile.e_invoicing` (or an equivalent absence flag) resolves to `false`; the File Return dialog's "Submit electronically" checkbox defaults off and is disabled with an explanatory caption rather than hidden, since the *filing* action itself is still fully available — only the automatic government-portal leg is unavailable for that specific jurisdiction. |

# End of Document
