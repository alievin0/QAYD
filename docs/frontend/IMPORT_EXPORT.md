# Import / Export Center — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: IMPORT_EXPORT
---

# Purpose

Every module QAYD has documented so far already exposes its own scoped, resource-specific bulk-data
surface: `docs/accounting/CHART_OF_ACCOUNTS.md § 17–18` gives the Chart of Accounts a fully-specified
`POST /api/v1/accounting/accounts/import` (partial-success, row-level-validated, mapping-review
pipeline, with a pre-trained per-ERP column-mapping table for QuickBooks, SAP, Oracle, Odoo, and Zoho)
and a `GET /api/v1/accounting/accounts/export` in four formats; `docs/accounting/CUSTOMERS.md` gives
Customers `POST /customers/import` / `GET /customers/export`; `docs/accounting/VENDORS.md` gives
Vendors an async `POST /api/v1/purchasing/vendors/import` with its own job-status poll and a
downloadable CSV template; `docs/accounting/PRODUCTS.md` gives the catalog an async
`POST /api/v1/products/import` returning `{ job_id, status, row_count }`, polled at
`GET /api/v1/products/import/{job_id}`; and `docs/accounting/INVENTORY.md`, `WAREHOUSES.md`, and
`TAX.md` each give their own resource an equivalent `.import`/`.export` pair. Every one of those is a
*contextual* capability — a scoped action reachable from that resource's own List Page Template, behind
that resource's own permission key. None of them is a place an Owner migrating off QuickBooks on day
one, a Finance Manager refreshing a quarterly price list, or an Auditor pulling a company-wide data
snapshot can go to see every bulk operation the company has ever run, resume one that is still queued,
or undo one that just finished. The Import / Export Center is that place. It is the one screen in QAYD
whose entire reason to exist is to give every resource's already-documented `.import`/`.export`
pipeline one shared, general-purpose front door, one shared column-mapping and AI-assistance
experience, one shared progress/error-reporting chrome, and one company-wide job history — the same
"look across what every other screen only ever looks into one slice of" role
`docs/frontend/DOCUMENT_CENTER.md` plays for the polymorphic `attachments` table, applied here to bulk
data movement instead of file storage.

This document specifies `app/(app)/import-export` — the Import wizard (source selection, ERP-migration
picker, AI-assisted column mapping, dry-run validation and preview, commit with live progress, an error
report, and rollback), the Export builder (module, filter, and format selection feeding an async export
job), and the unified cross-module job history both flows are logged into. It introduces **no new
business rule, no new validation rule, and no new import/export mechanism** for any resource that
already has one: the actual parsing, AI column mapping, row-level validation (`FormRequest` rules per
row, exactly as manual creation would run them), transactional batch commit, and audit logging for,
say, a Chart-of-Accounts import are still `CHART_OF_ACCOUNTS.md § 17`'s own responsibility, executed by
calling its own already-documented endpoint — this screen never re-implements that pipeline a second
time under a competing generic endpoint. What this document adds, narrowly and additively, mirroring
exactly the restraint `DOCUMENT_CENTER.md § Purpose` states for its own six additive endpoints, is: the
shared wizard and builder chrome around those already-specified primitives, a thin cross-module
`import_export_jobs` **read-projection** (populated from the same domain events each module's own
pipeline already fires on queue/complete/fail, precisely the way `docs/accounting/GENERAL_LEDGER.md`'s
own `ledger_entries` is "a projection/materialized view for speed" over `journal_lines`, never a second
system of record), a `rollback` action that dispatches to the owning module's own reversal logic rather
than reimplementing it, and the generalization of the ERP-migration pattern `CHART_OF_ACCOUNTS.md §
17.3` pioneers for one resource into a shared Source-step experience every importable resource can use.

The Import / Export Center's single most important structural property, repeated here because it
governs almost every design decision below: **this screen owns no primary financial data of its own.**
An imported customer, vendor, product, account, or draft journal entry is, from the instant it commits,
an ordinary row in its own module's own table, subject to that module's own business rules, permissions,
and lifecycle — Import / Export Center is not a second, parallel way to create a customer that bypasses
`docs/accounting/CUSTOMERS.md`'s own validation, it is the bulk front door to the identical validation.
Symmetrically, an export is never itself a source of truth — it is a point-in-time, read-only rendering
of data that already lives, correctly, in its owning module. This is precisely the same non-negotiable
posture `docs/frontend/REPORTS.md` states for a generated report ("this screen has no primary data of
its own... the frontend never evaluates a calculated-field formula, never sums a column client-side for
anything that will be persisted") and `DOCUMENT_CENTER.md` states for a stored file, transplanted here
to bulk movement of the same governed data every other screen in the platform already owns.

Two audiences use this one screen, at two different densities, extending the platform's stated "two
audiences share one shell" rule the way `DOCUMENT_CENTER.md § Purpose` already extended it to three. An
**Owner, CFO, or Finance Manager migrating a company onto QAYD** from QuickBooks, SAP, Oracle, Odoo,
Zoho, or a pile of spreadsheets — heavy, one-time, high-stakes, ERP-migration-register work, where the
AI Mapping Agent's column-mapping suggestions and the dry-run preview are the whole point, because a
500-account Chart of Accounts or a 5,000-row product catalog is not something a human should map field
by field from scratch (`CHART_OF_ACCOUNTS.md`'s own Business Goal G7: "a CoA of 500 accounts imports,
maps, and validates in under 30 minutes with a human reviewing AI-suggested mappings, not building them
from scratch"). And an **Accountant, Purchasing Employee, Sales Employee, or Inventory Manager doing
routine bulk maintenance** — a quarterly price-list refresh, a monthly stock-take reconciliation, a
periodic customer-list export for an external audit request — light, frequent, low-ceremony Excel/CSV
work, where the same wizard collapses to a two- or three-step flow because there is no ERP source to
reconcile and the AI mapping is a fast confirmation, not a research task. Both are built from the same
components at the same design-token baseline; the difference is which Source-step path they take and
how much of the Mapping step's review surface they actually need to look at, never a second design
system or a second endpoint family.

# Route & Access

## Route tree

```text
app/(app)/import-export/
├── page.tsx                    # Overview — Server Component, first-paint fetch; ?type=&module=&status=&q=
├── loading.tsx                 # Overview skeleton
├── error.tsx                   # Overview-level error boundary
├── import/
│   ├── new/page.tsx            # Import wizard entry; ?module=&source_system=
│   └── [jobId]/page.tsx        # A specific import job: mapping/preview/progress/result/rollback
└── export/
    ├── new/page.tsx            # Export builder; ?module=&format=
    └── [jobId]/page.tsx        # A specific export job: progress/download
```

`[jobId]` follows the platform's dynamic-segment convention — camelCase, named for the entity, never
the generic `[id]` (`docs/frontend/FRONTEND_ARCHITECTURE.md → Conventions → Naming`) — and resolves
against this document's own read-projection, `GET /api/v1/import-export/jobs/{jobId}`, detailed in
`# Data & State`. `import/new` and `export/new` follow the identical `new` + `[id]` pairing
`FRONTEND_ARCHITECTURE.md`'s route tree already uses for Journal Entries and `REPORTS.md` reuses for
its own Builder, rather than inventing a third naming shape for a creation entry point.

`export const dynamic = "force-dynamic"; export const fetchCache = "default-no-store";` on every route
in this tree — tenant-scoped job data is never statically cached, per `FRONTEND_ARCHITECTURE.md → Tenant-
scoped data is never statically cached`, the identical rule `REPORTS.md` and `DOCUMENT_CENTER.md` state
for their own routes.

## Access gate

The gating permission is the new, coarse `import_export.read` — visibility of the Overview, a job's own
history entry, and this document's read-projection endpoints. Per the platform's default-deny posture,
`import_export.read` is granted to every role that already holds at least one resource's own `.import`
or `.export` key (see the Role grants table below), so in practice almost every operational seat can see
*that* bulk operations happened company-wide, even where it cannot see every job's own row-level detail
— the row-level detail of a specific job is additionally gated by that job's own `module`'s `.read`
permission, exactly as `REPORTS.md`'s Library gates catalogue entries per category: a Sales Employee
sees that "Products — Import — completed — 2,400 rows" happened in the history list (aggregate
visibility, useful for "did someone already do this today") but opening it to see the actual imported
SKUs requires `products.read` on top.

| Control | Permission | Rendered when absent |
|---|---|---|
| Open `/import-export` at all | `import_export.read` | Nav entries hidden; direct navigation renders the shared `403` boundary |
| See a job's own row-level detail (mapping, preview, error report) | `import_export.read` + the job's `module`'s own `.read` | Job row still appears in history (aggregate fields only); detail route renders a scoped "you don't have access to this module's data" state, not a `404` |
| Start an import targeting a given module | That module's own `.import` key (e.g. `accounting.accounts.import`, `accounting.customer.import`, `purchasing.vendor.import`, `products.import`, `accounting.journal.import`, `inventory.import`, `warehouse.create`, `tax.config.manage`) | That module is omitted from the Source step's target picker entirely — discoverability is default-deny, the identical rule `REPORTS.md` states for its own category cards |
| Commit a validated import | Same `.import` key as above, re-checked server-side at commit, not just at wizard entry | "Commit" renders disabled with a tooltip if the permission was revoked mid-wizard (`# Edge Cases`) |
| Start an export targeting a given module | That module's own `.export` key | Module omitted from the Export builder's picker |
| Roll back a batch you yourself ran | `import_export.rollback` + the module's own `.import` key | "Roll back" omitted from that job's detail actions |
| Roll back any user's batch | `import_export.rollback.any` + the module's own `.import` key | Only your own batches show a rollback action; another user's batch shows the outcome, no rollback control |
| Download an ERP-migration column template | The target module's own `.import` key | "Download template" omitted from the Source step |

There is deliberately **no** separate platform-wide "run an import" or "run an export" permission
distinct from each resource's own key — the same reasoning `REPORTS.md`'s Export menu item applies
("the flat `reports.export` composes with, rather than replaces, the category-level export key"): an
import or export is only ever meaningful once it is already scoped to a specific resource, and that
resource's own `.import`/`.export` key is the one place its own role grants are authored and maintained
(see e.g. `CHART_OF_ACCOUNTS.md`'s own role table for `accounting.accounts.import`). Introducing a
second, parallel "can this user bulk-write data" gate would immediately drift from the per-resource
table it duplicates — exactly the drift risk `DOCUMENT_CENTER.md § Permission surface` declines to take
for its own seven inherited keys.

## Role grants (condensed, mirroring the six representative tiers used throughout `docs/frontend/`)

| Permission | Owner / CEO / CFO | Finance Manager | Accountant / Sr. Accountant | Sales / Purchasing / Inventory Mgr | Operational Employee tier | Auditor / Read Only |
|---|---|---|---|---|---|---|
| `import_export.read` | Yes | Yes | Yes | Yes (own module) | Yes (own module) | Yes |
| Resource `.import` (per module, own grant) | Yes | Yes | Module-dependent | Own module only | Rare, module-dependent | No |
| Resource `.export` (per module, own grant) | Yes | Yes | Yes | Own module | Own module, view-only rows | Yes (scoped) |
| `import_export.rollback` | Yes | Yes | Own batches only | Own batches only | Own batches only | No |
| `import_export.rollback.any` | Yes | Yes | No | No | No | No |

## Nav placement

`docs/frontend/LAYOUT_SYSTEM.md → List Page Template` already reserves a secondary-actions slot in
every list screen's Page Header for exactly this purpose — its own canonical wireframe shows
`[Import ▾] [Export ▾]` beside "New Journal Entry" — so the **primary** entry point into this screen is
not a twelfth Sidebar item but a pair of buttons every resource's own list screen (Journal Entries,
Customers, Vendors, Products, Chart of Accounts, Inventory, Warehouses) already has chrome for. Clicking
"Import" from the Customers list deep-links to `/import-export/import/new?module=accounting/customers`,
pre-selecting the module and skipping straight to file selection within the Source step; "Export" deep-
links to `/import-export/export/new?module=accounting/customers` with that screen's own current filters
carried across as the export's default scope (`# Interactions & Flows`). This is the identical link-out
convention `DOCUMENT_CENTER.md § Nav placement` uses for its own "Manage in Document Center" bullet.

For browsing cross-module history or starting an import with no particular module already in mind,
`docs/frontend/NAVIGATION_SYSTEM.md`'s fixed ten-module Sidebar map is not extended by an eleventh
entry — instead, **Settings** gains one additional sub-navigation bullet, **Import & Export —
`/import-export` — `import_export.read`**, following the identical precedent `DOCUMENT_CENTER.md` sets
for its own bullet under Reports: grouped with Settings because both are company-configuration-adjacent
utilities a user visits deliberately rather than as part of daily operational flow, not because Settings
owns the underlying data. The Command Palette's Navigate group includes "Import & Export" once
`filterNavByPermissions` resolves it visible, no special-casing beyond `NAVIGATION_SYSTEM.md`'s existing
`NAV_TREE` mechanism. On mobile, it is reached through **More**, the same as every non-bottom-tab
destination (`DOCUMENT_CENTER.md`'s own stated mobile placement, reused verbatim).

# Layout & Regions

This screen composes three of `LAYOUT_SYSTEM.md`'s named page templates rather than inventing a fourth:
the Overview is a **List Page Template** variant; the Import flow is the **Wizard Template**
`docs/frontend/ONBOARDING.md § Layout & Regions` introduces as the platform's first ("`LAYOUT_SYSTEM.md`
→ Page Templates should adopt it verbatim the next time that document is revised... the same way
`DASHBOARD.md` flagged its own Dashboard Template split for later reconciliation") — this document is
the second consumer of that template, exactly the reuse Onboarding's own text anticipates ("a payroll-run
wizard, **an import wizard reused outside Onboarding**"); and the Export builder and each job's detail
page are both a **Form Page Template** variant and a **Detail Page Template** instance respectively,
composed the same way `REPORTS.md` composes three templates in one route family rather than treating
that as license to invent new layout shapes.

## Overview (`/import-export`) — List Page Template

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Import & Export                                    [+ New Import] [+ New Export]│ PageHeader
│ 214 jobs this quarter · 3 running                                          │
├──────────────────────────────────────────────────────────────────────────────┤
│ [ Import History ]  [ Export History ]                                     │ Tabs
├──────────────────────────────────────────────────────────────────────────────┤
│ [Search…]   [Module ▾][Status ▾][Source system ▾][Date range ▾]            │ Filter Bar
├──────────────────────────────────────────────────────────────────────────────┤
│ Module      │ Type    │ Rows  │ Status              │ Started      │ By    │⋯│
│ Products    │ Import  │ 5,400 │ ● Completed (40 err) │ Jul 16, 09:25│ Dana  │⋯│ Data Region
│ Customers   │ Import  │   860 │ ◐ Running (62%)      │ Jul 16, 11:02│ Fahad │⋯│ (DataTable,
│ Accounts    │ Import  │   214 │ ✓ Completed          │ Jul 15, 14:10│ Ali   │⋯│  cursor mode)
│ Vendors     │ Export  │ 1,120 │ ✓ Completed          │ Jul 15, 10:40│ Dana  │⋯│
│ …           │ …       │ …     │ …                    │ …            │ …     │⋯│
├──────────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 214                                        [‹ Prev] [Next ›] │ Pagination
└────────────────────────────────────────────────────────────────────────────┘
```

**Page Header** — title, a live count from the read-projection ("214 jobs this quarter") plus a
distinctly-toned "3 running" count that deep-links to the same list pre-filtered to `status=running`,
the identical "a number always means go look at the source" discipline `DASHBOARD.md` establishes for
its own KPI tiles. Primary actions **New Import** (`import_export.read`, further scoped once a module is
chosen inside the wizard) and **New Export**.

**Tabs** — Import History / Export History, `?type=import|export`, mirroring `REPORTS.md`'s own
Library/My Reports/Scheduled tab convention rather than a single merged table with a Type column doing
the same job less legibly.

**Filter Bar** — search (job reference, actor name), and facets: **Module** (every resource with at
least one `.import`/`.export` key — Chart of Accounts, Customers, Vendors, Products, Journal Entries,
Inventory, Warehouses, Tax Configuration), **Status** (`queued`/`mapping_review`/`validating`/
`ready_to_commit`/`committing`/`completed`/`completed_with_errors`/`failed`/`cancelled`/`rolling_back`/
`rolled_back`), **Source system** (Excel/CSV, QuickBooks, SAP, Oracle, Odoo, Zoho, "another QAYD
company"), and **Date range**. Discoverability is default-deny exactly as `# Route & Access` states — a
module the viewer's role cannot read at all is never offered as a filter option, mirroring `REPORTS.md`'s
"a Sales Employee lacking `reports.payroll.read` never sees a Payroll Summary card, disabled or
otherwise."

**Data Region** — the platform's ordinary `DataTable` (`resource="import-export/jobs"`,
`paginationMode="cursor"`, per `# Data & State`), columns: Module, Type, Rows, `StatusPill`, Started
(relative time, full timestamp on `Tooltip`), started by (user avatar or an "AI-assisted" badge when the
mapping step's suggestions were bulk-accepted, never "AI ran this" — a human always clicked Commit),
row-action overflow (`View`, `Download error report` when applicable, `Roll back` when eligible). This
is the same plain `<table>` pattern every list screen uses, never the ARIA `grid` pattern
`docs/frontend/ACCESSIBILITY.md → Data Tables Accessibility` reserves for the two genuinely spreadsheet-
like surfaces (the Journal Entry line editor and the Bank Reconciliation matching grid) — a job history
is a read/sort/filter/paginate/open surface, squarely the plain-table case.

## Import wizard (`/import-export/import/new`, `/import-export/import/[jobId]`) — Wizard Template

Five steps, reusing `ONBOARDING.md`'s exact chrome (Progress Rail, Step Header, Step Body, Footer Action
Bar) with its AI Guide Dock renamed, in this flow, to the **Mapping Assistant** dock — the same narrowed,
one-message-at-a-time composition Onboarding's own dock is, never the full AI Rail:

```
┌───────────────────────────────────────────────────────────────────────────────┐
│  qayd     ●───●───○───○───○                                    [Save & exit]  │  Progress Rail
│           1   2   3   4   5                                                    │
├─────────────────────────────────────────────────────────────────┬─────────────┤
│  Step 2 of 5 — Map columns                                        │ ● Mapping   │
│  We matched 11 of 14 columns automatically. Review and confirm     │   Assistant │
│  the rest before we validate anything.                              │             │
│                                                                     │ "Column D  │
│  Source column        →  QAYD field            Confidence  Sample  │  ('Acct    │
│  Account                → code                    98%      1000    │  Type')    │
│  Account Name            → name_en                 96%      Cash   │  looks like│
│  Type                    → account_type_id         81%      Bank   │  QuickBooks│
│  Detail Type             → (unmapped) ▾            —       Checking│  's Detail │
│  Balance                 → opening_balance          94%     1,200   │  Type. I   │
│  ┌ Preview — 214 rows detected, 3 need manual mapping ────────────┐│  suggest   │
│  │ 1000  Cash on Hand        Asset   1,200.000                    ││  merging it│
│  │ 2000  Accounts Payable    Liability      –                     ││  into      │
│  │ …                                                                ││  Type."    │
│  └──────────────────────────────────────────────────────────────┘│ [Use this] │
├─────────────────────────────────────────────────────────────────┴─────────────┤
│                                              [ Back ]      [ Validate → ]      │  Footer
└───────────────────────────────────────────────────────────────────────────────┘
```

| Step | Region content |
|---|---|
| **1 — Source** | Module/resource picker (`Combobox`, permission-filtered per `# Route & Access`); within it, a toggle between **Upload a file** (drag-and-drop Excel/CSV, reusing `DOCUMENT_CENTER.md`'s `DocumentUploader` upload mechanics, `# Performance`) and **Migrate from another system** (a card grid of QuickBooks / SAP / Oracle / Odoo / Zoho / "Another QAYD company," each a plain text wordmark per `DESIGN_LANGUAGE.md`'s imagery stance — no third-party logos); a "Download column template" link (`GET .../import/template`, where the target module ships one, per `VENDORS.md`'s own precedent) |
| **2 — Mapping** | `ColumnMappingTable` — every detected source column against a target-field `Select`, an AI-suggested-confidence chip, and a live sample value; the Mapping Assistant dock narrates the single lowest-confidence or most-consequential suggestion at a time, never a wall of text |
| **3 — Validate & Preview** | Dry-run results: "N rows will succeed, M have errors," a scrollable preview of the first ~50 successful rows exactly as they will be created, and an `ImportErrorTable` of every failing row with its field-level reason, downloadable as its own file |
| **4 — Commit & Progress** | The explicit "Commit import" action (only enabled once at least one row validates), then a live `ImportProgressCard` — percentage, rows processed, an animated but calm progress bar (`DESIGN_LANGUAGE.md → Motion`, never a spinner once the shape of the work is known) |
| **5 — Result** | Created / updated / skipped counts, a link to the error report if any rows were skipped, the audit trail entry, and — within the rollback window — a **Roll back this import** action; "Import another file into this module" / "Done" close the wizard |

Unlike Onboarding's strictly-additive completed/skipped/upcoming node states, this wizard's Progress
Rail nodes only ever unlock forward linearly through step 3 (mapping must be confirmed before dry-run
can run, dry-run must complete before commit is offered) but, once a job reaches step 5, clicking back
to steps 1–3 opens a **read-only** replay of that job's own history rather than allowing a second
mutation of an already-committed batch — the identical "a decision, once made, is never quietly
re-opened" posture `JOURNAL_ENTRIES.md`'s locking rules take for a posted entry.

## Export builder (`/import-export/export/new`) — Form Page Template

A single-page form, not a wizard — exporting is one decision (what data, filtered how, in which
format), never a multi-stage review the way committing new data into the ledger is, the identical
distinction `ONBOARDING.md § Layout & Regions` draws between the Wizard Template and the Form Page
Template ("a wizard step is one decision... a Wizard Template is a sequence of otherwise-independent
screens," which an export is not).

```
┌───────────────────────────────────────────┬─────────────┐
│ New Export                 [Cancel][Export]│ What you'll  │
├───────────────────────────────────────────┤ get          │
│  Module          [ Customers            ▾]│ ──────────── │
│  Scope           (•) Current filters (860)  │ 860 rows     │
│                  ( ) Entire module (2,140)  │ 14 columns   │
│  Filters         Status: Active             │ Format: XLSX │
│                  Region: Kuwait, Saudi      │ ~2 MB, ready │
│  Format          [ Excel (.xlsx)         ▾]│ in ~5s       │
│  Include         [x] AI risk-tier column    │              │
└───────────────────────────────────────────┴─────────────┘
```

**Module** carries across from a list-screen link-out (`# Route & Access`) or is chosen fresh; **Scope**
defaults to "current filters" when arriving from a list screen's own Export button (so exporting from an
already-filtered Customers list exports exactly what is on screen, not the whole table by surprise) and
to "entire module" when starting from this screen's own New Export action with no inherited filter
state; **Format** options are queried from the target module's own declared export capabilities (some
modules offer `xlsx/csv/pdf/json`, Tax Configuration offers only a versioned `json` pack per `TAX.md`'s
own export section) rather than a single hard-coded universal set. The right-rail **"What you'll get"**
summary is a live, debounced preview computed from the filters and format only — never a full second
query of the export itself — so a user sees the row/column/size estimate before committing to a job.

**Boundary with Reports' own Schedule tab.** A one-off export of governed operational data (today's
customer list, this quarter's product catalog) is this screen's job; a *recurring*, formatted,
narrated report deliverable belongs to `REPORTS.md`'s own `report_schedules` — the same "owned
functionally by its own screen" boundary `REPORTS.md § Purpose` draws around the four Financial
Statements it still lists but does not itself generate. This screen never grows a recurrence/cron field
of its own; a user who wants a recurring version of an export is pointed, in-product, to "Build this as
a scheduled report instead" when they open the Format step's overflow.

## Job detail (`/import-export/import/[jobId]`, `/import-export/export/[jobId]`) — Detail Page Template

```
┌───────────────────────────────────────────────────┬───────────────┐
│ ‹ Import & Export   Products import — Jul 16       │  Summary       │
│                                [Download errors][⋯]│  ───────────   │
├─ Mapping │ Preview │ Progress │ Result │ Activity ─┤  5,400 rows    │
│                                                     │  xlsx · 12MB   │
│         (active tab's content — mapping table,      │  Started by   │
│          preview/error grid, progress card, or       │  Dana Al-Ajmi │
│          result summary, per the wizard steps        │  ────────────  │
│          above, now rendered read-only for a          │  Rollback:    │
│          completed job)                                │  available    │
│                                                        │  until Jul 19 │
└───────────────────────────────────────────────────┴───────────────┘
```

Reached directly (a notification deep link, a shared link, or the Overview history's row click) rather
than only through the wizard, this route renders the identical underlying job data the wizard itself
showed live — the same "the queue row and the detail page mutate the identical cache entry" guarantee
`APPROVAL_CENTER.md § Opening the Detail route directly` states for its own record, so a job never
appears to have two divergent histories depending on which door a user walked through.

# Components Used

Every visual element on this screen is a primitive from `COMPONENT_LIBRARY.md`, a named finance
component already catalogued there, or a screen-specific composition living in `components/import-
export/*`, per that document's own stated contract. Two components are **promoted**, not newly
invented: `ONBOARDING.md § Components Used` explicitly names its own `OnboardingProgressRail` as "a
deliberate candidate" to move into `components/shared/` "once a second multi-step flow (a payroll-run
wizard, **an import wizard reused outside Onboarding**) needs the identical affordance" — this document
is that second flow, so `OnboardingShell`/`OnboardingProgressRail` are renamed and relocated to
`components/shared/wizard-shell.tsx` / `wizard-progress-rail.tsx`, generalized to an arbitrary
`steps: WizardStepState[]` prop with no Onboarding-specific copy baked in, exactly the "moves out of a
module folder the day a second module needs it" rule `COMPONENT_LIBRARY.md` states.

| Component | Source | Role on this screen |
|---|---|---|
| `PageHeader` | `components/layout/page-header.tsx` | Overview title + New Import/Export; wizard's Progress Rail title slot; builder and detail headers |
| `Tabs` | `components/ui/tabs.tsx` | Overview's Import/Export History switch; job detail's Mapping/Preview/Progress/Result/Activity segmentation |
| `DataTable` | `components/shared/data-table.tsx` | Overview's job history (`resource="import-export/jobs"`, `paginationMode="cursor"`) |
| `Combobox` (built on `Command`+`Popover`) | `components/shared/combobox.tsx` | Source/Export builder's module picker; Export builder's format picker |
| `StatusPill` | `components/shared/status-pill.tsx` | Job `status` everywhere it appears |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Per-column mapping confidence in `ColumnMappingTable` |
| `AiCardShell` / `ReasoningDisclosure` | `components/ai/ai-card-shell.tsx`, `reasoning-disclosure.tsx` | The Mapping Assistant dock's narrated suggestion and its "why" detail |
| `Progress` | `components/ui/progress.tsx` | `ImportProgressCard`'s commit progress bar; the Export builder's generation progress |
| `WizardShell` / `WizardProgressRail` | `components/shared/wizard-shell.tsx`, `wizard-progress-rail.tsx` (**promoted**, formerly `components/onboarding/*`) | The Import wizard's persistent chrome |
| `DocumentUploader` | `components/documents/document-uploader.tsx` (existing, reused verbatim) | Source step's file drop zone — the identical presigned-direct-to-R2 path `DOCUMENT_CENTER.md` already specifies, never a second upload mechanism (`# Performance`) |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `alert-dialog.tsx` | Rollback confirmation, cancel-running-job confirmation, discard-in-progress-wizard confirmation |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Job row overflow actions; detail page's `⋯` menu |
| `Tooltip` | `components/ui/tooltip.tsx` | Permission-denied explanations, "why 81%?" confidence previews, disabled Commit reasons |
| `Skeleton` | `components/ui/skeleton.tsx` | Overview, wizard step, and detail loading placeholders |
| `EmptyState` / `ErrorState` | `components/shared/*` | No jobs yet; empty filtered history; job generation/commit failure |
| `Toast` (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing |
| `Can` / `PermissionGate` | `components/auth/can.tsx`, `components/shared/permission-gate.tsx` | Every control named in `# Route & Access`'s control table |
| `ImportSourceStep` **(new)** | `components/import-export/import-source-step.tsx` | Wizard step 1 — module picker, Upload/Migrate toggle, template download |
| `ErpSourcePicker` **(new)** | `components/import-export/erp-source-picker.tsx` | The QuickBooks/SAP/Oracle/Odoo/Zoho/QAYD-company card grid within step 1 |
| `ColumnMappingTable` **(new)** | `components/import-export/column-mapping-table.tsx` | Wizard step 2 — the centerpiece mapping grid |
| `MappingAssistantDock` **(new)** | `components/import-export/mapping-assistant-dock.tsx` | The wizard's narrow, one-suggestion-at-a-time AI dock |
| `ImportPreviewPanel` / `ImportErrorTable` **(new)** | `components/import-export/import-preview-panel.tsx`, `import-error-table.tsx` | Wizard step 3 — successful-row preview and failing-row report |
| `ImportProgressCard` **(new)** | `components/import-export/import-progress-card.tsx` | Wizard step 4 — live commit progress |
| `ImportResultSummary` **(new)** | `components/import-export/import-result-summary.tsx` | Wizard step 5 — counts, error-report link, rollback affordance |
| `ExportBuilderForm` / `ExportPreviewSummary` **(new)** | `components/import-export/export-builder-form.tsx`, `export-preview-summary.tsx` | The Export builder's form body and "What you'll get" rail |
| `RollbackConfirmDialog` **(new)** | `components/import-export/rollback-confirm-dialog.tsx` | Wraps `AlertDialog` with rollback eligibility copy (`# Edge Cases`) |

**Why `ColumnMappingTable` is a plain table with per-row `Select`s, never drag-and-drop.**
`REPORTS.md § Components Used` builds `SortableFieldList` on `dnd-kit` for its Builder's column
*ordering* and, because reordering is a genuine drag interaction, ships the mandatory keyboard
alternative WCAG 2.2's **2.5.7 Dragging Movements** requires. Column *mapping* has no ordering
component — each source column maps to exactly one target field via a `Select`, and a `Select` is
already fully keyboard-operable with zero additional affordance required, so this screen deliberately
does not import `dnd-kit` at all rather than reaching for a drag interaction the underlying task never
needed. This is a smaller, plainer surface for exactly the reason `DESIGN_LANGUAGE.md → Design Principle
8` states: novelty (a new drag interaction) is a withdrawal against consistency, and it is not being
spent here because the plain form control already solves the problem.

```tsx
// components/import-export/column-mapping-table.tsx
"use client";

import { useTranslations } from "@/lib/i18n";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { ConfidenceBadge } from "@/components/ai/confidence-badge";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import type { DetectedColumn, TargetFieldOption } from "@/types/import-export";

interface ColumnMappingTableProps {
  columns: DetectedColumn[];              // { sourceColumn, sampleValues, suggestedField, confidence }
  targetFields: TargetFieldOption[];      // the target module's own importable field schema
  mapping: Record<string, string | null>; // sourceColumn -> targetField key, or null = unmapped
  onChange: (sourceColumn: string, targetField: string | null) => void;
  requiredFields: string[];               // unmapped-required fields block step 3 (`# Interactions & Flows`)
}

export function ColumnMappingTable({
  columns, targetFields, mapping, onChange, requiredFields,
}: ColumnMappingTableProps) {
  const t = useTranslations("importExport.mapping");

  return (
    <table className="w-full text-sm">
      <thead className="bg-ink-100 text-ink-700">
        <tr>
          <th className="p-2 text-start">{t("sourceColumn")}</th>
          <th className="p-2 text-start w-56">{t("targetField")}</th>
          <th className="p-2 text-center w-24">{t("confidence")}</th>
          <th className="p-2 text-start">{t("sample")}</th>
        </tr>
      </thead>
      <tbody>
        {columns.map((col) => {
          const mapped = mapping[col.sourceColumn];
          const unresolvedRequired = !mapped && requiredFields.includes(col.suggestedField ?? "");
          return (
            <tr key={col.sourceColumn} className="border-t border-ink-150">
              <td className="p-2 font-medium text-ink-950">{col.sourceColumn}</td>
              <td className="p-2">
                <Select value={mapped ?? "__unmapped"} onValueChange={(v) => onChange(col.sourceColumn, v === "__unmapped" ? null : v)}>
                  <SelectTrigger aria-label={t("targetFieldFor", { column: col.sourceColumn })}
                                 className={cn(unresolvedRequired && "border-danger")}>
                    <SelectValue placeholder={t("unmapped")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__unmapped">{t("unmapped")}</SelectItem>
                    {targetFields.map((f) => (
                      <SelectItem key={f.key} value={f.key}>{f.label}{f.required ? " *" : ""}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </td>
              <td className="p-2 text-center">
                {col.confidence != null && <ConfidenceBadge value={col.confidence} size="sm" />}
              </td>
              <td className="p-2 text-ink-500 truncate max-w-xs">
                {col.sampleValues.slice(0, 2).join(", ")}
                {unresolvedRequired && <Badge tone="danger" className="ms-2">{t("requiredField")}</Badge>}
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}
```

# Data & State

## Target resources

The Source and Export-builder module pickers enumerate exactly the resources that already own a
documented `.import`/`.export` pair — this screen never lists a module that has not itself committed to
supporting bulk data movement.

| Module | Import endpoint | Import permission | Export endpoint | Export permission | Export formats |
|---|---|---|---|---|---|
| Chart of Accounts | `POST /api/v1/accounting/accounts/import` | `accounting.accounts.import` | `GET /api/v1/accounting/accounts/export` | `accounting.accounts.export` | `xlsx`, `csv`, `pdf`, `json` |
| Customers | `POST /api/v1/accounting/customers/import` | `accounting.customer.import` | `GET /api/v1/accounting/customers/export` | `accounting.customer.export` | `xlsx`, `csv`, `pdf` |
| Vendors | `POST /api/v1/purchasing/vendors/import` | `purchasing.vendor.import` | `GET /api/v1/purchasing/vendors/export` | `purchasing.vendor.export` | `xlsx`, `csv` |
| Products | `POST /api/v1/products/import` | `products.import` | `GET /api/v1/products/export` | `products.export` | `csv`, `xlsx`, `json` |
| Journal Entries (draft-only bulk-create) | `POST /api/v1/accounting/journal-entries/bulk-import` | `accounting.journal.import` | `GET /api/v1/accounting/journal-entries/export` | `accounting.journal.export` | `csv`, `xlsx`, `pdf` |
| Inventory (opening balances / stock-take) | `POST /api/v1/inventory/import` | `inventory.import` | `GET /api/v1/inventory/export` | `inventory.export` | `csv`, `xlsx` |
| Warehouses | `POST /api/v1/warehouses/import` | `warehouse.create` | `GET /api/v1/warehouses/export` | `warehouse.read` | `csv`, `xlsx` |
| Tax Configuration (country pack) | `POST /api/v1/tax/import` | `tax.config.manage` | `GET /api/v1/tax/export` | `tax.read` | `json` only (versioned pack, `TAX.md`) |

Journal Entries is deliberately narrower than the rest: a bulk import only ever creates rows in
`draft` status (`JOURNAL_ENTRIES.md`'s own `accounting.journal.import` grant is scoped to Finance
Manager/CFO precisely because it is a bulk *drafting* aid, not a bulk-posting mechanism) — every
imported entry still passes through the ordinary post/approval workflow one at a time or via the
Approval Center's own bulk-approve, never auto-posted by virtue of having arrived via this screen.

**ERP migration** (`source_system` = QuickBooks/SAP/Oracle/Odoo/Zoho) is available as a Source-step
path for every module above, but only Chart of Accounts ships the pre-trained, pre-built per-ERP
column-lookup tables `CHART_OF_ACCOUNTS.md § 17.3` documents in full today. Selecting an ERP source for
another module (importing a QuickBooks customer list, say) still runs the identical parse → AI-map →
review pipeline — it simply has no pre-seeded lookup table to lean on, so the AI Mapping Agent's
confidence runs lower on average and more columns land in "needs manual mapping," an honest degradation
rather than a silent claim of coverage this document has not earned.

## New endpoints owned by this document

| Method & Path | Permission | Purpose |
|---|---|---|
| `GET /api/v1/import-export/jobs` | `import_export.read` | Cross-module job history — `?type=import\|export&module=&status=&source_system=&q=&cursor=` |
| `GET /api/v1/import-export/jobs/{jobId}` | `import_export.read` + job's module `.read` | A single job's full detail — mapping, preview, progress, result |
| `POST /api/v1/import-export/jobs/{jobId}/rollback` | `import_export.rollback` (own) / `.rollback.any` + module's own `.import` | Dispatches to the owning module's own reversal logic (`# Edge Cases`) |

These three are the **entire** net-new backend surface this document introduces. `import_export_jobs`
is a read-projection populated by a domain-event listener the instant any module's own `.import`/
`.export` endpoint queues, updates, or completes a job — precisely the same "projection, not a second
system of record" relationship `GENERAL_LEDGER.md`'s own `ledger_entries` has to `journal_lines` — never
a competing table a module's own pipeline must remember to write to twice. `POST .../rollback` is a
thin dispatcher: it resolves `{jobId}` to its owning module and `batch_id` (the same `batch_id`
`CHART_OF_ACCOUNTS.md § 20`'s own `audit_logs.metadata` already stores for every `imported` action) and
calls that module's own rollback handler — the actual "how do I safely reverse 214 newly-created
accounts" logic is owned and implemented once, by the module that owns the rows, never re-derived here.

## Staged import contract

Every module's own `POST .../{resource}/import` accepts a shared `mode` field this document adds as a
narrow, additive, backwards-compatible extension of the shape `PRODUCTS.md`'s own worked example
already shows (`options: { dry_run: false }`) — formalized here as an explicit three-value enum every
importable resource's endpoint honors identically, so the wizard's steps 1–4 are the same four calls
regardless of which module is selected:

| `mode` | Called from | Effect |
|---|---|---|
| `preview` | Step 1 → 2 transition, immediately after upload | Parses the file into a staging area, returns `detected_columns` (with `suggested_field`/`confidence`/`sample_values` per the AI Mapping Agent) and the target schema; writes nothing |
| `dry_run` | Step 3 | Re-runs the identical `FormRequest` validation manual creation would run, against the confirmed mapping, for every staged row; writes nothing; returns per-row pass/fail |
| `commit` | Step 4, on explicit user action only | Transactional batch insert (batches of 500 rows per transaction, `CHART_OF_ACCOUNTS.md`'s own stated figure, reused platform-wide) of every row that passed `dry_run`; invalid rows are skipped and listed, never silently dropped, the identical partial-success model `CHART_OF_ACCOUNTS.md § 17.1`'s pipeline diagram already specifies |

```json
POST /api/v1/products/import
Content-Type: multipart/form-data
file: catalog_2026_q3.xlsx
source_system: null
mode: preview
```
```json
{
  "success": true,
  "data": {
    "job_id": "imp_7f3a1c9e",
    "status": "mapping_review",
    "row_count": 5400,
    "detected_columns": [
      { "source_column": "col_a", "suggested_field": "sku", "confidence": 0.98, "sample_values": ["ELC-000042"] },
      { "source_column": "col_c", "suggested_field": "category_code", "confidence": 0.81, "sample_values": ["Electronics"] }
    ],
    "target_schema": [
      { "key": "sku", "label_en": "SKU", "required": true },
      { "key": "category_code", "label_en": "Category", "required": false }
    ]
  },
  "message": "File parsed. Review the suggested mapping before validating.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3d4e5f6a-7b8c-4d9e-0f1a-2b3c4d5e6f7a",
  "timestamp": "2026-07-16T09:25:00Z"
}
```
```json
PATCH /api/v1/products/import/imp_7f3a1c9e
{ "mapping": { "col_a": "sku", "col_c": "category_code" }, "mode": "dry_run" }
```
```json
{
  "success": true,
  "data": {
    "job_id": "imp_7f3a1c9e",
    "status": "ready_to_commit",
    "row_count": 5400,
    "rows_valid": 5360,
    "rows_invalid": 40,
    "errors_preview_url": "https://cdn.qayd.app/imports/imp_7f3a1c9e/errors-preview.csv"
  },
  "message": "5,360 rows will succeed; 40 rows have errors.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "8e9f0a1b-2c3d-4e5f-6a7b-8c9d0e1f2a3b",
  "timestamp": "2026-07-16T09:26:40Z"
}
```

The `commit` call and its subsequent poll are the identical `job_id`/`status`/`errors_file_url` shape
`PRODUCTS.md`'s own example already documents in full — this document does not alter that contract, only
names the two calls that now precede it.

## Query keys

```ts
// lib/query/keys.ts (extends the platform factory pattern)
export const importExportKeys = {
  all: ["import-export"] as const,
  jobs: (filters: JobFilters) => [...importExportKeys.all, "jobs", filters] as const,
  job: (jobId: string) => [...importExportKeys.all, "job", jobId] as const,
  targetSchema: (module: string) => [...importExportKeys.all, "target-schema", module] as const,
};
```

## Cache tuning by job status

| Data | `staleTime` | Rationale |
|---|---|---|
| `import-export/jobs` (Overview history) | 30 seconds | Frequent enough manual glances ("did the price import finish?") to warrant a short window, per `REPORTS.md`'s own `report_schedules` tuning |
| A job in `queued`/`mapping_review`/`validating`/`committing` | `0`, polled every 2s as a Reverb fallback | Actively changing; correctness over efficiency, identical to `REPORTS.md`'s own `report_runs` tuning |
| A job in `completed`/`completed_with_errors`/`failed`/`cancelled` | `Infinity` (terminal; a retry creates a new job row) | Nothing about a finished job changes on its own, except a subsequent rollback (invalidated explicitly by that mutation) |
| Target module's importable-field schema | 5 minutes | Reference data — changes only when engineering ships a new importable field, the same rationale `REPORTS.md` gives its own `data-sources` cache |

## Realtime

```
private-company.{id}.import-jobs.{job_id}   → import.mapping_ready, import.validated, import.progress, import.completed, import.failed
private-company.{id}.export-jobs.{job_id}   → export.progress, export.completed, export.failed
private-company.{id}.notifications.{user_id} → import.completed, import.failed, import.rolled_back, export.completed, export.failed
```

Subscribed only while a specific job's `status` is non-terminal, per the identical pattern
`REPORTS.md § Realtime` states for its own `report-runs` channel. A user who started a large export and
navigated away never loses the result: the notification fires regardless of screen, and its deep link
resolves straight to `/import-export/export/{jobId}`, which by then reads the already-`completed` row
from cache with no further wait — the same guarantee `REPORTS.md` states for its own run notifications.

## AI agents feeding this screen

| Agent | Surfaces on this screen | Autonomy |
|---|---|---|
| **AI Mapping Agent** (primary; introduced by `CHART_OF_ACCOUNTS.md § 17.1` for Chart-of-Accounts import, generalized here to every importable resource) | Step 2's per-column `suggested_field`/`confidence`; bilingual name-field translation suggestions where a source system has no Arabic field (identical to `CHART_OF_ACCOUNTS.md`'s own QuickBooks/Zoho note) | Suggest-only; confidence below **0.5** (inclusive-low, the exact boundary `CHART_OF_ACCOUNTS.md § Edge Cases` fixes) is always presented as "needs manual mapping," never auto-applied |
| **General Accountant, Sales, Purchasing, Inventory, Tax Agents** (per target module) | Back the AI Mapping Agent's per-module field-classification model — a Customers import leans on the same classification the Sales Agent already applies to manually-entered customers, never a separate model per screen | Suggest-only, identical to their own module's own autonomy level |
| **Fraud Detection** | Auto-flags an anomalous bulk change (e.g., an import that would change a majority of SKUs' prices by more than a configurable threshold) at the Validate & Preview step, alongside the ordinary error report | **Auto-flag** (raised without a user request, per its platform-wide autonomy); never blocks Commit outright — the flag renders as a required acknowledgment checkbox before Commit enables, not a hard stop |
| **Compliance Agent** | Flags an export selection that includes payroll or national-ID-bearing fields, in the Export builder's "What you'll get" rail | Suggest-only; the export itself still proceeds once acknowledged, gated additionally by `reports.payroll.employee.read`-equivalent field-level checks on the target module |

# Interactions & Flows

## Import

```
[Select module + Upload file / choose ERP source]
        │  POST .../import  { mode: "preview" }
        ▼
[Step 2 — Mapping: AI Mapping Agent proposes source→field; user confirms/edits;
 bilingual name suggestions require explicit confirmation, never silent translation]
        │  PATCH .../import/{jobId}  { mapping, mode: "dry_run" }
        ▼
[Step 3 — Validate & Preview: N will succeed / M have errors, downloadable error report]
        │  user clicks "Commit" (disabled until ≥1 row is valid and every required
        │  field is mapped — the same rule the Select's border/badge already visualize)
        ▼
[Step 4 — PATCH .../import/{jobId} { mode: "commit" } → 202, job goes to "committing";
 Reverb + 2s-poll fallback drive ImportProgressCard until a terminal status arrives]
        │
        ▼
[Step 5 — Result: created/updated/skipped counts, error-report link, audit_logs row
 (action=imported, source_system, batch_id), rollback available until the window closes]
```

A user may leave the wizard at any point after step 1 and resume later from the Overview history — the
job row already exists in `mapping_review`/`ready_to_commit` status the moment step 1's upload
completes, so "Save & exit" (identical copy and placement to `ONBOARDING.md`'s own Progress Rail) never
discards work, it only pauses at the current step.

## Export

Selecting **Export** from a module's own list screen carries that screen's current filters into the
builder pre-selected to "Current filters"; starting fresh from `/import-export/export/new` defaults to
"Entire module." Submitting `POST .../export` with the chosen filters/format either streams the file
directly (small exports, held open on the request per `CHART_OF_ACCOUNTS.md § 18`'s own convention) or
returns `202` with a `job_id` for anything estimated over roughly 2,000 rows — the exact threshold
`CHART_OF_ACCOUNTS.md § 18` states for its own CoA export — after which `ImportProgressCard`'s sibling,
the export progress card, takes over until a signed download URL is ready.

## Rollback

Rollback is offered only on a `completed`/`completed_with_errors` import, only within its module's
configured rollback window (default 72 hours, company-configurable, the same "sensible default,
company-configurable" pattern `CHART_OF_ACCOUNTS.md`'s own rate limits use), and only while no row the
batch created has since been referenced by a later transaction. `RollbackConfirmDialog` states plainly
what will happen ("214 accounts created by this import will be deactivated; any account that has since
posted activity will be listed instead of reversed") before the mandatory-reason confirmation the
platform requires of every destructive action, then `POST .../rollback` performs a soft-delete/reversal
of every still-untouched row tagged with the batch's `batch_id`, writing one new `import_rolled_back`
`audit_logs` action referencing the original `imported` action it reverses.

## Cancelling a running job

A job in `queued`/`validating`/`committing` shows a "Cancel" action next to its progress card; canceling
a `committing` job never leaves a half-committed batch — the backend's per-500-row transactional
batching (`# Data & State`) means a cancel takes effect at the next batch boundary, and every batch that
had already committed before the cancel signal arrived remains, listed explicitly in the result summary
as "1,500 of 5,400 rows were committed before this import was cancelled," never silently discarded or
silently completed in full.

# AI Integration

This screen is, after the AI Command Center itself, one of the more AI-dense surfaces in the platform —
every mapping suggestion, every anomaly flag, and every translation suggestion is AI-originated — which
makes the platform's non-negotiable rule bear repeating in concrete, UI-specific terms: **nothing the AI
Mapping Agent proposes here is ever styled to look like a fact the system has already committed.**
`DESIGN_LANGUAGE.md → Design Principle 7` states this platform-wide; this section is where it is made
literal for a screen whose entire second step is AI output.

**Column mapping.** Every row of `ColumnMappingTable` that carries a `suggested_field` renders a
`ConfidenceBadge` next to the `Select`, never inside it — the `Select`'s own value is always the current
*proposed* mapping, editable by the user in the same interaction whether they are accepting or
overriding the suggestion, so there is no separate "accept" click for the common case and no visual
distinction between "AI suggested this and I kept it" and "I chose this myself" once the wizard moves
past step 2 (the confidence badge itself is the only remaining trace, and it is present precisely
because it is informative, not because the platform is tracking provenance for its own sake). A row
whose confidence is at or below the **0.5** boundary (`CHART_OF_ACCOUNTS.md § Edge Cases`'s own
inclusive-low rule, reused verbatim) renders with no pre-filled `Select` value at all — "needs manual
mapping" is the default state, never a low-confidence guess quietly pre-selected and easy to miss.
Bulk-accepting is offered as a single, explicit, reversible action — **"Apply all suggestions above
90%"** — in the Mapping Assistant dock, never a default the wizard applies on the user's behalf; clicking
it fills every `Select` above that threshold in one visible pass, still individually editable afterward,
still requiring the same explicit "Validate" click before anything is checked against real rules.

**Bilingual name translation.** Where a source system has no Arabic field (QuickBooks, Zoho, per
`CHART_OF_ACCOUNTS.md § 17.2`'s own note), the AI Mapping Agent's suggested `name_ar` renders inside an
`AiCardShell` with a visible "AI-translated — review before import" label and its own accept/edit
control, never silently written into the mapping payload — QAYD does not let an AI-machine-translated
legal or customer name enter a book of record without a human's explicit look, the identical restraint
`CHART_OF_ACCOUNTS.md` states for exactly this case.

**Fraud Detection's anomaly flag.** At the Validate & Preview step, alongside the ordinary row-level
error report, a bulk change that crosses a configurable materiality threshold (a price import touching
most of the catalog by a large percentage, a Chart-of-Accounts import reclassifying many accounts'
`nature`) renders a `negative`-token-bordered `AIProposalPanel` card above the preview table: "Fraud
Detection flagged this import — 68% of rows change unit price by more than 40%. Review before
committing." This is an **auto-flag**, raised without a user request, consistent with Fraud Detection's
platform-wide autonomy level everywhere else it appears (`REPORTS.md`'s own Anomaly Detection row uses
the identical autonomy). It does not block Commit outright — QAYD does not let an automated heuristic
be the final word on a legitimate, large, intentional price refresh — but Commit itself requires an
explicit acknowledgment checkbox ("I've reviewed this flag") to enable, the same "suggest-only, never a
silent block" posture the platform-wide AI rule requires, made concrete as a specific interaction rather
than a general principle.

**Compliance Agent's export sensitivity flag.** In the Export builder, selecting a module/field
combination that includes payroll compensation or a national-ID-shaped field renders a compact
`AiCardShell` note in the "What you'll get" rail — "This export includes compensation data. Confirm you
have authorization to export this." — mirroring `EMPLOYEES.md`'s own payroll-PII gating pattern and
`DOCUMENT_CENTER.md`'s retention-badge restraint (informative, not a hard stop; the underlying
`reports.payroll.employee.read`-equivalent field-level permission is still the actual gate, per
`# Route & Access`).

**The one hard line.** No AI output on this screen ever calls `mode: "commit"` itself, under any
confidence, for any resource. `PATCH .../import/{jobId} { mode: "commit" }` is only ever issued from a
click handler bound to the wizard's own "Commit import" button, itself disabled until a human has
reached step 4 having seen the dry-run result — there is no "auto-import when confidence is high enough"
setting anywhere in this screen's design, matching the platform's own stated posture in
`DESIGN_LANGUAGE.md`'s Voice & Microcopy table: the AI always speaks in proposing language here too —
"94% of columns mapped automatically," never "Import complete" before a human has actually clicked
Commit.

# States

| State | Rendering |
|---|---|
| **Loading** (Overview, job detail on first paint) | `Skeleton` rows shape-matched to `DataTable`/wizard-step layout, per `DESIGN_LANGUAGE.md`'s slow, low-contrast shimmer — never a spinner for content whose shape is already known |
| **Empty** (no jobs yet) | `EmptyState`: a single line-drawn document-stack abstraction, "No imports or exports yet. Bring in your Chart of Accounts, customers, or products to get started," primary action "Start an import" |
| **Empty, filtered** (a filter matches nothing) | Lighter `EmptyState` variant, "No jobs match your filters," with a "Clear filters" action — the identical distinction `DOCUMENT_CENTER.md § Layout & Regions` draws between a genuinely empty library and a filtered-to-nothing one |
| **Uploading** | `DocumentUploader`'s own per-file progress row (`# Performance`), disabled Continue until the upload's finalize call resolves |
| **Mapping / AI thinking** | The Mapping Assistant dock's three `accent-subtle` pulsing dots (`DESIGN_LANGUAGE.md`'s named "AI thinking" pattern, reused verbatim) while `mode: "preview"`'s response is in flight; once it resolves, the dock cross-fades into the first narrated suggestion |
| **Validating** | `ImportPreviewPanel` renders a skeleton table while `mode: "dry_run"` is in flight; the step's own status text reads "Checking 5,400 rows against your company's data…" |
| **Ready to commit** | Preview + error report rendered in full; "Commit" enabled only if `rows_valid > 0` and every `requiredFields` entry has a non-null mapping |
| **Committing** | `ImportProgressCard`'s determinate `Progress` bar, percentage and row counter updating from the 2s poll/Reverb fallback; a realtime row flash (`accent-subtle`, 600ms) on the Overview's own history row if it happens to be visible in another tab |
| **Completed** | `ImportResultSummary`'s single fade/scale checkmark (`DESIGN_LANGUAGE.md`'s named success-confirmation pattern), counts, no error-report link |
| **Completed with errors** | Identical layout, `warning`-toned `StatusPill`, error-report link present and visually primary next to the counts, never buried |
| **Failed** | `ErrorState` with the specific reason (`FILE_TOO_LARGE`, `UNSUPPORTED_FORMAT`, rate-limit, `# Edge Cases`), a "Try again" action that returns to step 1 with the same module pre-selected |
| **Cancelled** | Neutral `StatusPill`, the "N of M rows committed before cancellation" summary (`# Interactions & Flows`) |
| **Rolling back** | A dedicated, smaller determinate progress row on the job detail's Result tab, distinct from the original import's own progress bar so the two are never visually conflated |
| **Rolled back** | `StatusPill` "Rolled back," a link to the new `import_rolled_back` audit entry, the original result summary retained below it for historical reference, never deleted from view |
| **Permission-denied module in picker** | Omitted from the `Combobox`'s options entirely, never rendered disabled — discoverability is default-deny per `# Route & Access` |

# Responsive Behavior

**Overview (`base`–`sm`).** The Filter Bar's facets collapse into a single "Filters" button opening a
`Sheet`, identical to `DOCUMENT_CENTER.md`'s own mobile Filter Bar collapse. The job history `DataTable`
follows `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`'s `ResponsiveColumnDef<TData>` pattern,
already reused by `REPORTS.md`'s own `ReportResultTable`: Module, Status, and a combined "Rows · relative
time" line survive as a stacked-card row; Started-by, exact timestamp, and the row-action overflow move
behind a "Details" disclosure rather than forcing horizontal scroll on a screen too narrow to make one
useful.

**Import wizard (`base`–`lg`).** The Mapping Assistant dock collapses exactly the way `ONBOARDING.md`'s
own AI Guide Dock does at identical breakpoints — a `56px` icon rail below `xl`, a bottom `Sheet` below
`lg` — because it is built from the same primitive and inherits the same collapse behavior rather than
reinventing a second one. `ColumnMappingTable` itself switches to a stacked-card-per-column layout below
`sm`: each source column becomes its own small card (source name, the `Select`, the confidence badge,
and the sample value stacked vertically) rather than a horizontally-scrolling table row, the identical
`ResponsiveColumnDef` technique applied to a mapping row instead of a report row. `ImportPreviewPanel`'s
result table degrades the same way `TrialBalanceTable` degrades below its own stated minimum width per
`RESPONSIVE_DESIGN.md`: a read-only, horizontally-scrollable, bordered container rather than a forced
column-drop that would misrepresent which value belongs to which column in a financial preview.

**Import wizard (`xl`+).** Full two-pane layout — Step Body plus the persistent Mapping Assistant dock
— exactly the desktop Wizard Template wireframe in `# Layout & Regions`.

**Export builder.** Below `md`, the form body and the "What you'll get" rail stack vertically (rail
first is deliberately avoided — the rail is a *summary* of choices not yet made below `sm`, so it renders
after the form fields, updating live as they're filled in, never floating above an empty form with
nothing yet to summarize).

**Touch targets.** Every interactive control on this screen — the wizard's Back/Continue pair, the
mapping table's `Select` triggers, the job history's row-action overflow — meets the platform's 44×44px
minimum regardless of density, the same floor `PAYROLL.md`'s own Lifecycle Stepper states for its mobile
controls.

# RTL & Localization

Direction is set once, at the root, from the resolved locale (`applyLang()`/`<html dir>`, per
`docs/frontend/DESIGN_LANGUAGE.md → Design Principle 6` and `LAYOUT_SYSTEM.md → RTL Layout Mirroring`) —
no component on this screen toggles direction itself. Every layout, gap, and padding value across the
wizard's Progress Rail, Step Body, and Mapping Assistant dock uses Tailwind's logical utilities
(`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`) exclusively, the same ESLint-enforced restriction
`COMPONENT_LIBRARY.md → Theming & RTL` applies platform-wide — banned physical `ml-*`/`mr-*`/
`text-left`/`text-right` classes are caught in review, not left to a component author's discretion.

**The wizard's Progress Rail mirrors exactly like the Approval Center's own `Stepper`.** Its
progress-direction arrow and its completed/current/upcoming node sequence flow start-to-end, so in
Arabic the rail visually runs right-to-left with zero conditional code — the identical mechanism
`AI_COMMAND_CENTER.md → RTL`'s own "the Approval Center `Stepper`'s progress direction... mirror with
zero conditional code" statement describes, reused here for the promoted `WizardProgressRail`.

**Directional icons flip; meaning-bearing icons don't**, per `AI_COMMAND_CENTER.md`'s and
`LAYOUT_SYSTEM.md → RTL Layout Mirroring Rule 4`'s identical rule. `ColumnMappingTable`'s implicit
source-column-to-target-field relationship (visually, a left-to-right "maps to" reading order in
English) is realized without a literal arrow glyph in the primary layout — the `Select` itself carries
the meaning — but wherever a "source → target" chevron does appear (the Mapping Assistant dock's inline
recap of the change it is proposing), it is the identical directional glyph that mirrors via
`rtl:rotate-180`, never a static one. A trash/void icon, a checkmark, and `ConfidenceBadge`'s own dot
never mirror, per the same rule.

**Numerals, file sizes, and percentages stay LTR-embedded**, exactly as `DESIGN_LANGUAGE.md`'s
`<Amount>` primitive and `AmountCell`'s own `dir="ltr"` wrapper already establish for every numeral in
the platform — a row count ("5,400 rows"), a progress percentage ("62%"), and a file size ("12MB")
inside this screen's Arabic-locale rendering all render Western digits, never Eastern Arabic-Indic
numerals, matching the platform's stated "Gulf ledgers, invoices, and spreadsheets are actually written"
convention. Proper nouns — QuickBooks, SAP, Oracle, Odoo, Zoho, and any uploaded file's own name — render
LTR-embedded the same way, inside a `dir="ltr"` inline span when they appear mid-Arabic-sentence, so
Unicode's bidi algorithm never reorders a file name's extension or a source system's own capitalization.

**Target field labels are bilingual; underlying keys are not.** `ColumnMappingTable`'s `Select` shows
`f.label` in the active locale (`label_en`/`label_ar` per the target schema, the identical
bilingual-master-data convention every other picker in the platform follows), but the `mapping` payload
sent to the API is always the English field *key* (`sku`, `account_type_id`) regardless of display
locale — the API contract itself is never localized, only its presentation, the same separation
`DESIGN_LANGUAGE.md`'s own Arabic microcopy section draws between correct terminology and correct wire
values.

# Dark Mode

Dark mode is the platform's standard first-class token remap (`class="dark"` / `data-theme="dark"`,
system-aware, user-overridable, per `THEMING.md`/`DARK_MODE.md`), never an inverted filter, and this
screen introduces no exception to it. Three specifics worth naming for a screen this data- and
status-dense:

- **`StatusPill` and `ConfidenceBadge` reuse their existing dark-mode token remaps verbatim** — the
  `warning` tone on a "Completed with errors" pill and the tiered coloring on a confidence badge below
  50% both already have calibrated dark-mode values in `COMPONENT_LIBRARY.md`'s token tables; this
  document introduces no new semantic color and therefore no new dark-mode value to calibrate.
- **The Mapping Assistant dock's `AiCardShell` uses `accent-subtle`/`accent-100`'s dark-mode value**,
  the same lighter-not-more-saturated accent shift `DESIGN_LANGUAGE.md → Dark mode strategy` specifies
  platform-wide, so the dock does not glow unnaturally against the darker canvas.
- **ERP source-system cards remain plain text wordmarks in both themes** — since this screen carries no
  photography or third-party logos (`# Layout & Regions`), there is no brand-color/dark-background
  clash to manage the way a rendered logo asset would create; the cards are ordinary `Card` surfaces
  with `ink`-scale text, unaffected by anything beyond the standard surface-token remap.

Every screenshot-based visual-regression check this screen ships (`FRONTEND_ARCHITECTURE.md`'s stated
four-variant convention: light/LTR, light/RTL, dark/LTR, dark/RTL) covers the wizard's Mapping step
specifically, since it is the single densest simultaneous use of color-as-signal (confidence tiers,
required-field danger borders, error badges) on the screen.

# Accessibility

**Keyboard-complete wizard.** Every step is reachable and completable with no mouse: `Tab`/`Shift+Tab`
moves through the Step Body's controls in document order, `Enter`/`Space` activates Back/Continue, and
the Progress Rail's nodes follow the identical rule `ONBOARDING.md § Layout & Regions` states for its
own rail — clicking a completed node navigates directly to it; clicking an upcoming, not-yet-reachable
node does nothing and shows a tooltip naming what must be finished first, never a silently inert click
target. The route-change focus target on every step transition lands on the new Step Header (the
step's own `display-lg` title), the same pattern `ONBOARDING.md`'s own Step Header region states, so a
screen-reader user always hears "Step 3 of 5 — Validate & Preview" immediately after Continue, not
whatever control happened to retain DOM focus from the previous step.

**No drag-and-drop burden.** As stated in `# Components Used`, `ColumnMappingTable` maps columns via a
plain `Select` per row, never a drag interaction — this sidesteps WCAG 2.2's **2.5.7 Dragging
Movements** criterion entirely rather than requiring the keyboard-alternative pattern
`REPORTS.md § Components Used`'s `SortableFieldList` needs for its own genuinely drag-based column
reordering. Where this document's Wizard Template *does* reuse a sequential, node-based interaction
(the Progress Rail), it is a click/keyboard target list, not a drag surface, so no equivalent concern
applies there either.

**Throttled progress announcements.** `ImportProgressCard`'s live region (`aria-live="polite"`) updates
at most once every 5 seconds or on a whole-10%-boundary crossing, whichever is less frequent — never on
every 2-second poll tick — so a screen-reader user hears "62% complete, 3,348 of 5,400 rows processed"
as a meaningful checkpoint rather than a rapid, unusable stream of numbers, the same throttling
discipline `PAYROLL.md`'s own live-region conventions apply to its Lifecycle Stepper's status updates.

**Color is never the only signal.** A mapping row's required-but-unmapped state renders both a `danger`
border on the `Select` *and* an explicit "Required" `Badge` with text (`# Components Used`'s own code
already shows both), never a red border alone — the identical "the leading `+`/`−` glyph carries the
meaning on its own" principle `COMPONENT_LIBRARY.md → AmountCell` states for a colorblind user reading a
monochrome context. The Validate & Preview step's error report likewise pairs its `danger`-toned row
background with an explicit error-code column, never color alone.

**RBAC-aware disabled controls explain themselves.** A "Commit" button disabled because a permission was
revoked mid-wizard (`# Edge Cases`), a module omitted from the picker, and a rollback control absent for
another user's batch all follow the platform-wide rule restated in `DESIGN_LANGUAGE.md`'s Do & Don't
table: never a bare grayed-out control with no explanation, always either omitted (existence itself is
sensitive) or disabled with a `Tooltip` naming the missing permission in human terms.

**Table semantics.** `ColumnMappingTable`, the Overview's job history, and `ImportErrorTable` are all
plain, semantic `<table>` markup with real `<th scope="col">` headers and per-row `aria-label`s on
interactive controls (the `Select`'s own `aria-label={t("targetFieldFor", …)}` in the worked code
above) — never the ARIA `grid` pattern, reserved platform-wide for the two genuinely spreadsheet-like
surfaces named in `ACCESSIBILITY.md → Data Tables Accessibility`, neither of which this screen is.

# Performance

**Upload path.** The Source step's file drop zone is `DOCUMENT_CENTER.md`'s own `DocumentUploader`,
reused verbatim rather than a second upload mechanism: a presigned direct-to-Cloudflare-R2 `PUT`, never
a raw multipart body round-tripped through Next.js or Laravel, so a 20MB ERP export uploads at the
user's own connection speed with no serverless-function body-size ceiling in the way. The subsequent
`mode: "preview"` call references the already-uploaded object key, the identical three-call
(presign → PUT → finalize) sequence `DOCUMENT_CENTER.md § DocumentUploader` documents in full.

**Large previews and error reports are virtualized.** `ImportPreviewPanel` and `ImportErrorTable` use
`@tanstack/react-virtual` combined with `@tanstack/react-table`, the identical combination
`DESIGN_LANGUAGE.md → Virtualization & interaction` specifies for any table expected to exceed roughly
200 rows — a 5,400-row product import's error table renders only the visible window, never all 40
error rows plus 5,360 success rows in the DOM simultaneously, even though 40 is small; the mechanism is
applied uniformly rather than conditionally re-derived per screen.

**Commit is batched, not row-by-row.** The backend commits in transactional batches of 500 rows per
`CHART_OF_ACCOUNTS.md § 15`'s own stated figure ("kept import of a 5,000-row SAP export under the
30-minute Business Goal G7 target"), reused as the platform-wide default every importable resource's
own `mode: "commit"` handler follows — `ImportProgressCard`'s percentage is computed from
`rows_processed / row_count`, ticking up in ~500-row jumps rather than a smooth-but-fake animation, an
honest reflection of how the work is actually chunked server-side.

**Rate limiting is surfaced, not just enforced.** `CHART_OF_ACCOUNTS.md § Edge Cases`'s own default —
10 import jobs/hour, 20 exports/hour per company — is reused platform-wide as the ceiling every
resource's `.import`/`.export` endpoint enforces; hitting it returns `429` with a `retry_after`, and this
screen renders that as a specific, friendly state ("You've reached this hour's import limit — resets at
14:00") rather than the default toast a generic `429` handler would produce, because a limit hit during
an ERP migration session is common enough (many small test imports while confirming a mapping) to
deserve a state better than an error.

**Target schema and mapping suggestions are cached, not re-fetched per keystroke.** A module's
importable-field schema is cached for 5 minutes (`# Data & State`'s cache-tuning table, identical
rationale to `REPORTS.md`'s own `data-sources` cache); editing a single mapping row's `Select` never
triggers a network call — the whole mapping object is held in local component/form state and sent once,
at the `dry_run` transition, not per-field.

**Export size estimation avoids a second full query.** The Export builder's "What you'll get" rail
computes its row/column/size estimate from the same filter parameters a `COUNT`-only, indexed query
already answers cheaply, never by running the full export query twice (once to preview, once for real)
— the actual export generation, triggered only on submit, is the first time the full result set is
materialized.

# Edge Cases

| Scenario | Behavior |
|---|---|
| Uploaded CSV is not UTF-8 (common from an older QuickBooks Desktop or SAP export) | Detected at `mode: "preview"` parse time; Arabic or special characters that would otherwise mis-render prompt an explicit "This file appears to use a different text encoding — re-export as UTF-8 or confirm to proceed with best-effort conversion" notice, rather than silently importing mojibake into `name_ar` fields |
| A cell contains a formula-injection payload (`=cmd\|…`, a leading `=`/`+`/`-`/`@`) | Neutralized (prefixed with a single quote) on both import parsing and export generation, the exact platform-wide rule `CHART_OF_ACCOUNTS.md § Edge Cases` states for its own CoA file safety, applied to every resource's import/export path, not re-derived per module |
| A row's `parent_code`/hierarchy reference points to a row appearing later in the same file | Resolved by the identical two-pass algorithm `CHART_OF_ACCOUNTS.md § 17.4`'s **IR-1** specifies (pass 1 creates/locates all rows without parents, pass 2 wires every parent reference) — generalized to any importable resource with a self-referential hierarchy (account trees, product categories, cost centers), so file row ordering never matters for any of them |
| Two staged rows in the same batch share the same natural key (SKU, account code, customer number) | Rejected as a batch-level error before any row commits, the identical **IR-2** duplicate-within-batch rule, surfaced in the Validate & Preview step's error report rather than silently keeping the first and dropping the second |
| File exceeds 20MB or 50,000 rows | Rejected at upload with a specific message naming the limit — the platform-wide default `CHART_OF_ACCOUNTS.md § Edge Cases` states; a module may set a stricter cap in its own documented pipeline, and this screen surfaces whichever limit the target module actually enforces, never a value it invents independently |
| The caller's `.import` permission for the target module is revoked between step 1 and step 4 | The job stays in `ready_to_commit`; "Commit" renders disabled with a tooltip naming the now-missing permission; the job remains visible and resumable in the Overview history the moment the permission is restored, rather than being silently abandoned |
| Rollback is requested but some imported rows have since been referenced downstream | Mirrors the exact `409 ACCOUNTS_ALREADY_POSTED` precedent `ONBOARDING.md § Edge Cases` documents for a Chart-of-Accounts template re-application — rollback is not rejected outright; still-untouched rows are reversed, and referenced rows are listed explicitly ("6 of 214 imported accounts already have posted activity and were not reversed — manage them individually from Chart of Accounts") rather than either silently skipping them or blocking the entire rollback |
| Two users import into the same module concurrently, with overlapping natural keys across the two batches | The second batch's overlapping rows fail the same unique-constraint validation a manual create would hit, reported as ordinary per-row errors in that batch's own report — no cross-batch locking is introduced, and no batch is allowed to silently overwrite the other's rows |
| The network drops mid-upload, before `mode: "preview"` ever succeeds | Nothing exists server-side yet (no job row, no staged data); the wizard shows a retry action at step 1 with the module selection preserved locally |
| The network drops after a job reaches `mapping_review`/`ready_to_commit`, before Commit is clicked | The job row and its confirmed mapping already persist server-side; the user resumes from the Overview history exactly where they left off, never re-uploading or re-mapping from scratch |
| A monetary column's number format is ambiguous (`1.234,56` vs `1,234.56`) | The Mapping step's per-column preview shows the value as QAYD would interpret it under the company's configured locale, with an explicit "Does this look right?" confirmation for any column mapped to a currency/amount field — never a silent guess, consistent with the platform's zero-tolerance stance on silently misreading a financial figure |
| A requesting user's role is branch-scoped and exports a module they only partially read | The export respects the identical branch/row-level scoping a manual list-view read already enforces — bulk export is never a bypass of a scoping rule the UI otherwise honors |
| The company hits its hourly import or export rate limit mid-session | Rendered as the dedicated friendly rate-limit state in `# Performance`, with a visible reset time, rather than a bare `429` toast |
| A large export (over the ~2,000-row synchronous threshold) is requested during an already-queued export of the same module | Both proceed as independent jobs — this screen does not de-duplicate or merge concurrent export requests, since two different filter sets against the same module are not necessarily redundant, and a strict de-duplication rule would need to inspect filter equality this document does not specify a use for |

# End of Document
