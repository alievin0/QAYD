# Vendors — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: VENDORS
---

# Purpose

This document specifies the Vendors screen pair: the vendor list (search, filter, and act on every
supplier, contractor, and payee a company transacts with) and the vendor profile (the complete
Accounts-Payable master record for one vendor). It is the frontend counterpart to
`docs/accounting/VENDORS.md` (which owns every table, state machine, business rule, and endpoint this
screen renders) and conforms to the cross-cutting rules in `FRONTEND_ARCHITECTURE.md`,
`DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `RESPONSIVE_DESIGN.md`,
`DARK_MODE.md`, and `ACCESSIBILITY.md`. Where this document is silent, those documents govern; where
this document appears to contradict one of them, that is a defect to raise, not a decision to resolve
unilaterally in code.

Vendors is the screen a Purchasing Employee opens before raising a purchase order, a Finance Manager
opens before releasing a payment run, and an Auditor opens to sample AP controls end to end. It answers
the same three questions `docs/accounting/VENDORS.md`'s own Purpose section poses of the vendor
master — *who are we allowed to pay, on what terms, and does what we owe them reconcile to the ledger* —
and it is the single doorway into every vendor lifecycle action a human is permitted to take: searching
and filtering the roster, reading a vendor's full profile, creating and submitting a new vendor through
its approval workflow, verifying a bank account before it becomes payable-to, blacklisting a vendor that
has failed compliance, and merging two records the system has flagged as the same real-world supplier.
Concretely, this document specifies the screen pair that composes:

- A **Vendors `DataTable`** — the searchable, filterable, sortable roster of every `vendors` row the
  caller's company holds, with category (`vendor_type`), status, risk level, and tag filters, permission-
  gated row actions, and a compact KPI band answering "how many vendors, how much do we owe, how many
  need attention."
- A **duplicate-candidates review surface** — the queue of vendor pairs the Duplicate Detection
  background sweep has flagged at medium confidence (0.70–0.89), the entry point into the irreversible
  merge action.
- A **vendor profile** (`[vendorId]/page.tsx`) — the full AP master record for one vendor, organized into
  an Overview (identity, company/tax/VAT information, payment and credit terms, tags, the AI risk score,
  and the approval-workflow stepper) plus dedicated tabs for **Contacts**, **Addresses**, **Bank
  Accounts** (with the bank-detail change-control/verification flow), **Certificates & Contracts** (with
  AI Contract Analysis extraction review), **AP Aging**, **Statement**, **Transactions**, and **Audit
  Log**.
- A **create/edit surface** (`VendorForm`) — the identity/type/tax/terms form used both for a brand-new
  vendor (a full-page route, because vendor creation runs a synchronous duplicate check that can return a
  blocking `409` needing room to resolve) and for editing an existing vendor's core profile fields (a
  `Sheet` opened from the profile).
- **Lifecycle and governance affordances** — permission-gated actions for submit-for-approval, approve/
  reject at each of the six workflow steps, activate, deactivate, blacklist (with mandatory reason and
  category), unblacklist, archive (with the open-balance/open-PO guard and its Finance-Manager-only
  force override), and unarchive — every one of them a thin rendering of the exact state machine
  `docs/accounting/VENDORS.md → Vendor Lifecycle` defines, never a client-side re-derivation of it.
- **AI surfaces** — the Vendor Risk Score panel and its per-row badge, the duplicate-detection hard-block
  dialog and the medium-confidence review card, Fraud Detection's bank-change/large-invoice alerts, and
  the Contract Analysis extraction proposal a human must confirm before it becomes authoritative.

This screen, like every screen in QAYD, **owns no business logic**. It never computes a risk score,
never decides whether two vendors are duplicates, never evaluates whether a bank account is payable-to,
and never lets an AI agent's confidence substitute for the human sign-off the workflow requires. Every
figure rendered here was computed and validated by Laravel; every mutation this screen triggers is a call
to `/api/v1/purchasing/vendors/...` guarded by the exact permission key the API itself enforces
(`FRONTEND_ARCHITECTURE.md`, Principle 1 and Principle 4).

# Route & Access

## App Router path

Matching `FRONTEND_ARCHITECTURE.md → App Router Structure`'s `purchasing/` segment, extended with the
dynamic and action routes this screen pair needs — the same reasonable extension `BANKING.md` makes to
`banking/accounts/[accountId]/page.tsx` on top of the platform's own shorthand tree, not a departure from
it:

```
app/(app)/purchasing/
├── layout.tsx                          # Sub-nav: Vendors | Purchase Orders | Bills | Vendor Payments (purchasing.read gate)
└── vendors/
    ├── page.tsx                        # ★ THIS DOCUMENT — Vendors list (DataTable + KPI band)
    ├── new/page.tsx                    # ★ THIS DOCUMENT — Full-page create (VendorForm mode="create")
    ├── duplicate-candidates/
    │   └── page.tsx                    # ★ THIS DOCUMENT — Duplicate-candidate review queue
    └── [vendorId]/
        └── page.tsx                    # ★ THIS DOCUMENT — Vendor profile (9 tabs); "Edit" opens
                                         #   VendorForm mode="edit" in a Sheet, never a separate route
```

`vendors/page.tsx` is the screen this document specifies in the most depth — the `DataTable`, the KPI
band, and the duplicate-candidates banner. `NAVIGATION_SYSTEM.md`'s Purchasing sub-nav points its
"Vendors" item directly at `/purchasing/vendors`, and the Purchasing module's own `layout.tsx` renders
the Vendors / Purchase Orders / Bills / Vendor Payments sub-nav tabs as real `<Link>`s (Server Component,
not client tab state), exactly the pattern `banking/layout.tsx` already establishes for its own module.

A note on this document's permission-key naming, stated once here so it need not be repeated at every
table below: `docs/accounting/VENDORS.md → Permissions` is this module's own dedicated, complete
permission surface (24 distinct keys, each mapped to a role) and is the canonical source this document
follows verbatim (`purchasing.vendor.read`, `purchasing.vendor.create`, `purchasing.vendor.blacklist`,
and so on — vendors are, by that document's own explanation, "conceptually part of the Purchasing/AP
domain even though [the Accounting module] owns the underlying tables"). `NAVIGATION_SYSTEM.md`'s
sidebar citation for the Vendors entry is a shorthand reference to this same permission family, not a
second, competing key — a reader who reaches this screen from either document arrives at the identical
gate.

## Permission gate

| Gate | Permission | Effect if absent |
|---|---|---|
| Screen visible at all | `purchasing.vendor.read` | Sidebar's Purchasing → Vendors item (and this route) does not render; a direct hit to `/purchasing/vendors` renders the shell-level `error.tsx` with "You don't have access to this," never a silent redirect. |
| "New vendor" | `purchasing.vendor.create` | Button omitted. |
| Row action: Edit / core profile fields | `purchasing.vendor.update` | Action omitted from the row's `DropdownMenu`; the profile's own "Edit" button is likewise omitted, not disabled. |
| Row action: Delete (hard delete) | `purchasing.vendor.delete` | Action omitted. Even when present, the API only ever honors it for a `prospect`-status vendor with zero linked documents — the button itself does not attempt to hide that rule (see **Edge Cases**). |
| Submit for approval | `purchasing.vendor.submit` | Action omitted. |
| Approve — Commercial step | `purchasing.vendor.approve` | That step's Approve/Reject pair on the `VendorApprovalStepper` renders disabled with a permission tooltip rather than omitted, because the step is already addressed to a named approver (see `ACCESSIBILITY.md`'s "a card already addressed to someone is disabled, not hidden" rule, reused from `ApprovalCard`). |
| Approve — Compliance step | `purchasing.vendor.approve.compliance` | Same disabled-with-tooltip treatment, scoped to that step only. |
| Approve — Financial step | `purchasing.vendor.approve.finance` | Same treatment. |
| Activate / Deactivate | `purchasing.vendor.activate` / `purchasing.vendor.deactivate` | Action omitted. |
| Blacklist | `purchasing.vendor.blacklist` | Action omitted. |
| Unblacklist | `purchasing.vendor.unblacklist` | Action omitted — deliberately narrower than blacklist itself (Owner only by default, see **Roles**). |
| Archive / Archive (force) | `purchasing.vendor.archive` / `purchasing.vendor.archive.force` | Ordinary archive omitted without the base permission; the force-override checkbox inside the archive dialog is additionally hidden (not just disabled) when the caller lacks `.force`, so a blocked archive attempt shows only "this vendor has an open balance" with no override affordance to a user who could never use it. |
| Unarchive | `purchasing.vendor.unarchive` | Action omitted. |
| Merge | `purchasing.vendor.merge` | The duplicate-candidates queue itself is gated on this permission (see **Route & Access**, screen visible); a caller without it never sees "potential duplicate" banners or the merge dialog anywhere on either screen. |
| Bank account verify / reject | `purchasing.vendor.bank.verify` | Verify/Reject buttons on a `pending_verification` bank account row are omitted; the row still displays with its status pill so a Purchasing Employee can see verification is pending without being able to act on it. |
| Certificates manage | `purchasing.vendor.certificates.manage` | "Add certificate" omitted; existing certificates remain read-only. |
| Contracts manage | `purchasing.vendor.contracts.manage` | "Add contract" omitted; the AI Contract Analysis extraction review action is likewise omitted. |
| Reclassify (`vendor_type`) | `purchasing.vendor.reclassify` | The `vendor_type` field renders read-only inside `VendorForm mode="edit"` rather than as an editable `Select`. |
| Risk review | `purchasing.vendor.risk.review` | The Risk Score panel's reasoning breakdown collapses to the score and level only, omitting the itemized `risk_score_reasoning` disclosure and the "flag for review" action. |
| Import | `purchasing.vendor.import` | "Import" header action omitted. |
| Export | `purchasing.vendor.export` | "Export" header action omitted. |
| Audit log tab | `purchasing.vendor.audit.read` | The "Audit Log" tab itself is omitted from the profile's tab list, not shown disabled — its mere existence is not informative the way a disabled button can be. |

## Roles

Reproduced from `docs/accounting/VENDORS.md → Permissions`'s role table and translated into what a role
concretely sees on **this** screen pair, not the API's abstract grant:

| Role | What renders |
|---|---|
| Owner | Full screen pair: every row action, every workflow-step approval, blacklist and the uniquely-Owner unblacklist, archive with force, merge, bank verification, reclassify, import/export, audit log. |
| CEO / CFO | Same practical surface as Owner on this screen (both inherit the platform's Admin-tier grants per the backend table's "Admin" column); CFO is additionally the one most likely to be the assigned approver on the Financial Approval step. |
| Finance Manager | Full profile actions except unblacklist and reclassify's default grant; is the default approver for the Bank Account Verification and Financial Approval steps, and holds `archive.force`. |
| Purchasing Manager | Create, update (own- or team-created), submit, the Commercial Approval step, activate/deactivate, certificates and contracts management; no blacklist, no merge, no bank verification, no archive. |
| Purchasing Employee | Create and update their own vendors, submit for approval, manage certificates; read-only everywhere else — no lifecycle transitions, no approval-step actions. |
| Accountant | Read + export only; no create, no update, no lifecycle action. |
| Senior Accountant | Same as Accountant plus visibility into the Risk Score panel's full reasoning (`purchasing.vendor.risk.review`, view only — no action). |
| Auditor | Fully read-only across both screens, including the Audit Log tab (`purchasing.vendor.audit.read`); every mutating control is omitted. |
| Read Only / External Auditor | Same floor as Auditor; External Auditor additionally sees the Audit Log tab, matching the backend table's explicit carve-out. |
| AI service account | Never renders this screen as a "user." Its scoped read access feeds the AI panels described in **AI Integration**, and it is structurally incapable of holding `purchasing.vendor.blacklist`, `.unblacklist`, `.merge`, `.bank.verify`, or `.archive.force` regardless of any role grant — the platform-wide rule that sensitive AP operations are never AI-only, reused verbatim from `docs/accounting/VENDORS.md → Permissions`. |

# Layout & Regions

## Vendors list — `vendors/page.tsx`

Laptop (`lg`, 1024px) and up — per `RESPONSIVE_DESIGN.md`'s five-tier breakpoint system — inside the
persistent app shell (`Sidebar`/`Topbar`, out of this document's scope per `NAVIGATION_SYSTEM.md`):

```
┌───────────────────────────────────────────────────────────────────────────────────────┐
│  Vendors   Purchase Orders   Bills   Vendor Payments                    [sub-nav tabs] │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  Vendors                                    [Import] [Export] [+ New vendor]           │
│  312 vendors · 8 pending approval                                                       │
├───────────────────────────────────────────────────────────────────────────────────────┤
│ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐               │
│ │ Active vendors │ │ Pending        │ │ Payables       │ │ High / critical│  KPI band   │
│ │ 284            │ │ approval       │ │ outstanding    │ │ risk           │  (KpiTile×4)│
│ │                │ │ 8              │ │ KD 214,880.500 │ │ 6              │             │
│ └───────────────┘ └───────────────┘ └───────────────┘ └───────────────┘               │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  ┌ AI · General Accountant ──────────────────────────────────────────────────────────┐ │
│  │ ● 3 potential duplicate vendors detected — review before more POs are raised      │ │
│  │   against them.                              [Dismiss]  [Review duplicates →]     │ │
│  └───────────────────────────────────────────────────────────────────────────────────┘ │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  [Search…]  [Category ▾]  [Status ▾]  [Risk ▾]  [Tags ▾]              [Density ▾]      │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐  │
│  │   Code        Vendor              Type      Status    Risk   Balance due  ⋯     │  │
│  │ ─────────────────────────────────────────────────────────────────────────────── │  │
│  │   V-0042-003190 Al-Salam Trading   Company   ●Active   Low    2,450.500  ⋯      │  │
│  │ ○ V-0042-004821 Gulf Logistics Co  Company   ●Prospect —      0.000     ⋯      │  │
│  │   V-0042-002207 Al-Rashid Fahad    Individual ●Active   Medium  680.250  ⋯      │  │
│  │   …                                                              [1 2 3 … 13]   │  │
│  └─────────────────────────────────────────────────────────────────────────────────┘  │
└───────────────────────────────────────────────────────────────────────────────────────┘
```

Region inventory:

| Region | Contents | Streaming boundary |
|---|---|---|
| Sub-nav | `Tabs`: Vendors (active) · Purchase Orders · Bills · Vendor Payments, real `<Link>`s per `purchasing/layout.tsx` (Server Component) | Not streamed — part of the layout shell |
| Page header | `<h1>Vendors</h1>`, a one-line count/pending summary, and three permission-gated actions (`Import`, `Export`, `New vendor`) | Renders immediately with the page shell |
| KPI band | Four `KpiTile`s: Active Vendors, Pending Approval, Payables Outstanding, High/Critical Risk | Own `<Suspense>` boundary — `GET /purchasing/vendors/stats` |
| Duplicate-candidates banner | A dismissible `AIProposalPanel`-style card, omitted entirely when the queue is empty or the caller lacks `purchasing.vendor.merge` | Own `<Suspense>` boundary — `GET /purchasing/vendors/duplicate-candidates?limit=1` (count-only probe; the full queue lives on its own route) |
| Filter bar | Search input, Category (`vendor_type`) select, Status select, Risk level select, Tags multi-select, density toggle | Part of `VendorsTable`'s own client boundary — not independently streamed |
| Vendors table | `<VendorsTable/>` (a preset `DataTable`) with search, filters, density toggle, row actions | Own `<Suspense>` boundary — `GET /purchasing/vendors` |

Each region streams independently behind its own `<Suspense>` boundary exactly per
`FRONTEND_ARCHITECTURE.md → Streaming with Suspense`: a slow duplicate-candidate count probe never
delays the KPI band or the table from painting, and a failure in one region renders that region's own
`ErrorState` without taking down the page (see **States**).

## Vendor profile — `[vendorId]/page.tsx`

```
┌───────────────────────────────────────────────────────────────────────────────────────┐
│  ← Vendors                                                                              │
│  V-0042-003190 · Al-Salam Trading Co. W.L.L.  ●Active  Risk: Low       [Edit] [⋯ Actions]│
│  شركة السلام التجارية ذ.م.م · Company · KWD · Net 30                                     │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  Overview  Contacts  Addresses  Bank Accounts  Certificates & Contracts                 │
│  AP Aging  Statement  Transactions  Audit Log                       [sub-nav tabs, scroll]│
├───────────────────────────────────────────────────────────────────────────────────────┤
│  ┌ Identity ──────────────┐ ┌ Tax & VAT ─────────────┐ ┌ Terms ─────────────────────┐  │
│  │ Legal name              │ │ Tax reg. no. TX-998877  │ │ Payment terms   Net 30      │  │
│  │ Commercial reg. 123456   │ │ VAT registered  No      │ │ Credit limit   15,000.000  │  │
│  │ Legal structure  W.L.L.  │ │ WHT applicable  —        │ │ Credit hold     No          │  │
│  └──────────────────────────┘ └──────────────────────────┘ └────────────────────────────┘  │
│  ┌ AI · Vendor Risk Score ────────────────────────────────────────────────────────────┐  │
│  │  12 / 100 · Low                                              [Why? ▾]              │  │
│  │  ▸ Financial stability — low utilization, no disputes         contribution 2       │  │
│  │  ▸ Compliance — all certificates valid                        contribution 0       │  │
│  │  ▸ Concentration — 1.8% of total company spend                contribution 4       │  │
│  └───────────────────────────────────────────────────────────────────────────────────┘  │
│  ┌ Approval workflow ─────────────────────────────────────────────────────────────────┐  │
│  │ ✓ Data completeness  ✓ Duplicate check  ✓ Compliance  ✓ Bank verify  ✓ Commercial  ✓ Financial │
│  │ Approved 2026-03-11 by F. Al-Mutairi (Finance Manager)                             │  │
│  └───────────────────────────────────────────────────────────────────────────────────┘  │
│  Tags: preferred · office-supplies                              Custom fields: —        │
└───────────────────────────────────────────────────────────────────────────────────────┘
```

Region inventory (Overview tab shown; other tabs replace the content region below the sub-nav, each
independently streamed):

| Region | Contents | Streaming boundary |
|---|---|---|
| Breadcrumb / back link | `← Vendors`, per `NAVIGATION_SYSTEM.md → Breadcrumbs & Page Headers` | Part of the page shell |
| Profile header | Vendor code, bilingual legal name, `StatusPill` (domain `vendor`), `VendorRiskBadge`, `Edit` and an `⋯ Actions` `DropdownMenu` (Submit / Activate / Deactivate / Blacklist / Unblacklist / Archive / Unarchive / Merge, each permission-filtered) | Renders with the initial server fetch (`GET /purchasing/vendors/{id}`) |
| Tab sub-nav | 9 tabs, horizontally scrollable at narrower widths, each a real `<Link>` with a `?tab=` query param (deep-linkable, per `NAVIGATION_SYSTEM.md → Routing & Deep Links`) | Not streamed — part of the profile shell |
| Overview: Identity / Company / Tax & VAT / Terms cards | Read-only display of core `vendors` row fields | Renders from the same initial fetch as the header — no extra round trip |
| Overview: Risk Score panel | `VendorRiskPanel` | Own `<Suspense>` boundary — `GET /purchasing/vendors/{id}` already includes `risk_score`/`risk_level`/`risk_score_reasoning`; no separate call, but rendered in its own error boundary since a malformed reasoning payload should not blank the rest of Overview |
| Overview: Approval workflow stepper | `VendorApprovalStepper` | Own `<Suspense>` boundary — `GET /purchasing/vendors/{id}` includes `approval_workflow` (steps array); omitted entirely once `status` has passed `prospect` and no step is `rejected` awaiting resubmission |
| Contacts tab | `VendorContactsList` | Own `<Suspense>` boundary — data arrives embedded in the profile fetch's `contacts[]`; the tab itself does not issue a new request on first load, only on a child mutation |
| Addresses tab | `VendorAddressesList` | Same as Contacts — embedded in the profile fetch |
| Bank Accounts tab | `VendorBankAccountsList` | Same — embedded, with its own verify/reject actions per row |
| Certificates & Contracts tab | `VendorCertificatesList` + `VendorContractsList` | Same — embedded |
| AP Aging tab | `VendorAgingTable` | Own `<Suspense>` boundary — `GET /purchasing/vendors/{id}/open-items` |
| Statement tab | `VendorStatementTable` | Own `<Suspense>` boundary — `GET /purchasing/bills?filter[vendor_id]=`, `GET /purchasing/vendor-payments?filter[vendor_id]=`, merged client-side into one chronological statement, never re-aggregated for a financial total (the AP Aging tab's totals remain the authoritative balance) |
| Transactions tab | `VendorTransactionsTable` | Own `<Suspense>` boundary — `GET /purchasing/vendors/{id}/purchase-orders`, plus links out to Bills/Vendor Payments filtered the same way |
| Audit Log tab | `VendorAuditLogTable` | Own `<Suspense>` boundary — `GET /purchasing/vendors/{id}/audit-log`, cursor-paginated, virtualized above ~200 rows |

Exactly as `BANKING.md` establishes for its own multi-region screen, every region here streams
independently: a slow Risk Score recomputation never delays the Identity card from painting, and a
failure loading the Audit Log tab never affects the Overview tab a user most likely opened first.

# Components Used

A note on token naming before the table: this document names tokens the way the actual primitive and
finance-component implementations in `COMPONENT_LIBRARY.md` reference them (`ink-950`…`ink-0`,
`accent-700/600/500/100`, `success`/`warning`/`danger`/`info`) rather than `DESIGN_LANGUAGE.md`'s newer,
differently-numbered `ink-1`…`ink-12`/`accent`/`accent-subtle` block — describing this screen's
components with the newer names would describe code that does not match what they actually render.
Reconciling the platform's two token vocabularies is `COMPONENT_LIBRARY.md` and `DESIGN_LANGUAGE.md`'s
own job, exactly as `BANKING.md` already notes for the identical reason.

| Component | Source | Role on this screen pair |
|---|---|---|
| `Tabs` | Primitive | Purchasing sub-nav (list); the 9-tab profile sub-nav (profile), both real `<Link>`s |
| `KpiTile` | Finance | Active Vendors, Pending Approval, Payables Outstanding, High/Critical Risk |
| `DataTable` | Shared | Underlies `VendorsTable` |
| `AmountCell` | Finance | `balance_due`, `credit_limit_amount`, every AP Aging/Statement/Transactions figure |
| `CurrencyTag` | Finance | Multi-currency vendors' bank-account and terms display |
| `StatusPill` | Finance, domain `vendor` (**new**, owned by this document) | Vendor lifecycle status everywhere it appears |
| `ConfidenceBadge` | AI | Contract Analysis per-field confidence; Fraud Detection alert confidence |
| `AIProposalPanel` | AI | Duplicate-candidates banner (list); Contract Analysis extraction proposal (profile) |
| `ApprovalCard` | Shared, `kind="vendor_approval"` (**new discriminant**, owned by this document) | Each pending workflow step assigned to the caller, and any AI-originated vendor recommendation |
| `Sheet` | Primitive | `VendorForm mode="edit"`; row-detail quick view from the list |
| `Dialog` / `AlertDialog` | Primitive | Blacklist/archive reason capture, bank-account verify/reject, merge confirmation, duplicate hard-block |
| `DropdownMenu` | Primitive | Row actions (list) and the profile header's `⋯ Actions` menu, permission-filtered per `DataTable`'s own contract |
| `Skeleton` | Primitive | Per-region loading placeholders |
| `EmptyState` / `ErrorState` | Shared | Zero-vendor onboarding, filtered-empty table, per-region fetch failure |
| `VendorsTable` | **New** — `components/purchasing/vendors-table.tsx` | The list region; a thin preset wrapper over `DataTable`, mirroring `BankTransactionsTable`'s pattern exactly |
| `VendorForm` | **New** — `components/purchasing/vendor-form.tsx` | Create (`new/page.tsx`) and edit (profile `Sheet`) |
| `VendorRiskBadge` / `VendorRiskPanel` | **New** — `components/purchasing/vendor-risk.tsx` | Compact table-cell badge and the Overview tab's full breakdown |
| `VendorApprovalStepper` | **New** — `components/purchasing/vendor-approval-stepper.tsx` | Overview tab's 6-step workflow visualization and inline step approval |
| `VendorContactsList` / `VendorAddressesList` / `VendorBankAccountsList` | **New** — `components/purchasing/vendor-*-list.tsx` | Child-collection managers, each with its own add/edit `Dialog` |
| `BankAccountVerifyDialog` | **New** — `components/purchasing/bank-account-verify-dialog.tsx` | The bank-detail verification sub-workflow |
| `VendorCertificatesList` / `VendorContractsList` | **New** — `components/purchasing/vendor-*-list.tsx` | Certificates & Contracts tab, the latter surfacing the AI extraction proposal |
| `VendorLifecycleActionDialog` | **New** — `components/purchasing/vendor-lifecycle-action-dialog.tsx` | Shared reason(+category) capture for deactivate/blacklist/unblacklist/archive/unarchive |
| `DuplicateCandidateCard` | **New** — `components/purchasing/duplicate-candidate-card.tsx` | One row on the duplicate-candidates queue; an `AIProposalPanel` composition |
| `VendorMergeDialog` | **New** — `components/purchasing/vendor-merge-dialog.tsx` | Survivor/merged selection and the irreversible-merge confirmation |
| `VendorAgingTable` / `VendorStatementTable` / `VendorTransactionsTable` / `VendorAuditLogTable` | **New** — `components/purchasing/vendor-*-table.tsx` | Read-through `DataTable` presets, one per profile tab |

## `VendorsTable`

A thin preset over `DataTable`, following `BankTransactionsTable`'s established shape exactly — this
screen never hand-rolls sorting, pagination, or filtering.

```tsx
// components/purchasing/vendors-table.tsx
'use client';

import { DataTable } from '@/components/shared/data-table';
import { AmountCell } from '@/components/accounting/amount-cell';
import { StatusPill } from '@/components/shared/status-pill';
import { VendorRiskBadge } from '@/components/purchasing/vendor-risk';
import type { ColumnDef } from '@tanstack/react-table';
import type { Vendor } from '@/types/purchasing';
import { Eye, Pencil, Send, Power, PowerOff, Ban, Archive, GitMerge } from 'lucide-react';
import { useRouter } from 'next/navigation';

interface VendorsTableProps {
  defaultFilters?: Record<string, unknown>;
}

export function VendorsTable({ defaultFilters }: VendorsTableProps) {
  const router = useRouter();

  const columns: ColumnDef<Vendor>[] = [
    { accessorKey: 'vendor_code', header: 'Code', cell: ({ row }) => <span className="font-mono text-xs">{row.original.vendor_code}</span> },
    {
      accessorKey: 'legal_name', header: 'Vendor',
      cell: ({ row }) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ink-950">{row.original.display_name ?? row.original.legal_name}</p>
          {row.original.potential_duplicate_of && (
            <span className="text-caption text-warning">Possible duplicate</span>
          )}
        </div>
      ),
    },
    { accessorKey: 'vendor_type', header: 'Type' },
    { accessorKey: 'status', header: 'Status', cell: ({ row }) => <StatusPill domain="vendor" status={row.original.status} /> },
    {
      accessorKey: 'risk_level', header: 'Risk',
      cell: ({ row }) => row.original.risk_level
        ? <VendorRiskBadge level={row.original.risk_level} score={row.original.risk_score} size="sm" />
        : <span className="text-ink-500">—</span>,
    },
    {
      accessorKey: 'balance_due', header: 'Balance due',
      cell: ({ row }) => <AmountCell amount={row.original.balance_due} currencyCode="KWD" align="end" />,
    },
  ];

  return (
    <DataTable
      columns={columns}
      resource="purchasing/vendors"
      paginationMode="page"
      defaultSort="legal_name"
      searchable
      defaultFilters={defaultFilters}
      onRowClick={(row) => router.push(`/purchasing/vendors/${row.id}`)}
      getRowId={(row) => String(row.id)}
      emptyState={{ title: 'No vendors yet', description: 'Create your first vendor to start raising purchase orders.' }}
      rowActions={(row) => [
        { label: 'View', icon: Eye, onClick: () => router.push(`/purchasing/vendors/${row.id}`) },
        { label: 'Edit', icon: Pencil, permission: 'purchasing.vendor.update', onClick: () => openEditSheet(row.id) },
        { label: 'Submit for approval', icon: Send, permission: 'purchasing.vendor.submit', hidden: row.status !== 'prospect', onClick: () => submitForApproval(row.id) },
        { label: 'Activate', icon: Power, permission: 'purchasing.vendor.activate', hidden: row.status !== 'inactive', onClick: () => activateVendor(row.id) },
        { label: 'Deactivate', icon: PowerOff, permission: 'purchasing.vendor.deactivate', hidden: row.status !== 'active', onClick: () => openLifecycleDialog(row.id, 'deactivate') },
        { label: 'Blacklist', icon: Ban, permission: 'purchasing.vendor.blacklist', hidden: row.status === 'blacklisted' || row.status === 'archived', onClick: () => openLifecycleDialog(row.id, 'blacklist') },
        { label: 'Archive', icon: Archive, permission: 'purchasing.vendor.archive', hidden: row.status === 'archived', onClick: () => openLifecycleDialog(row.id, 'archive') },
        { label: 'Review as duplicate', icon: GitMerge, permission: 'purchasing.vendor.merge', hidden: !row.potential_duplicate_of, onClick: () => router.push('/purchasing/vendors/duplicate-candidates') },
      ]}
    />
  );
}
```

Category, status, risk, and tag filters render as a small `Popover` above the table (the same pattern
`BANKING.md`'s Filters affordance uses), each mapping onto `DataTable`'s `filter[...]` query grammar:
`filter[vendor_type]`, `filter[status]`, `filter[risk_level][in]=high,critical`, `filter[tags][any]=`.
The `potential_duplicate_of` caption on a row is read straight from the list payload — this screen never
re-runs the fuzzy-match algorithm client-side to decide whether to show it.

`StatusPill` gains a `vendor` domain lookup table, owned by this document:

```tsx
// components/shared/status-pill.tsx — additions owned by this document
const VENDOR_STATUS: Record<string, { label: string; tone: Tone }> = {
  prospect:    { label: 'Prospect',    tone: 'neutral' },
  approved:    { label: 'Approved',    tone: 'accent'  },
  active:      { label: 'Active',      tone: 'success' },
  inactive:    { label: 'Inactive',    tone: 'neutral' },
  blacklisted: { label: 'Blacklisted', tone: 'danger'  },
  archived:    { label: 'Archived',    tone: 'neutral' },
};

Object.assign(STATUS_TABLES, { vendor: VENDOR_STATUS });
```

## `VendorRiskBadge` / `VendorRiskPanel`

Renders the AI Vendor Risk Score (`docs/accounting/VENDORS.md → Vendor Risk Management`) as a compact
badge for table cells and a full breakdown panel for the Overview tab. This is deliberately **not**
`ConfidenceBadge` — a risk score is a 0–100 severity rating with four named levels, not a 0–1 confidence
in an AI-authored fact — so it gets its own small component rather than overloading `ConfidenceBadge`'s
semantics.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `level` | `'low' \| 'medium' \| 'high' \| 'critical'` | yes | |
| `score` | `number` | yes | 0–100. |
| `size` | `'sm' \| 'default'` | no | |
| `reasoning` | `{ factor: string; weight: number; contribution: number; evidence_ref?: string }[]` | no | Only rendered by `VendorRiskPanel`; omitted from the compact badge. |

```tsx
// components/purchasing/vendor-risk.tsx
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

const RISK_TONE: Record<string, 'success' | 'neutral' | 'warning' | 'danger'> = {
  low: 'success', medium: 'neutral', high: 'warning', critical: 'danger',
};

export function VendorRiskBadge({ level, score, size = 'default' }: VendorRiskBadgeProps) {
  return (
    <Badge tone={RISK_TONE[level]} className={cn(size === 'sm' && 'px-2 py-0 text-[11px]')}>
      <span className="font-mono tabular-nums" dir="ltr">{score}</span>
      <span className="capitalize">{level}</span>
    </Badge>
  );
}

export function VendorRiskPanel({ level, score, reasoning }: VendorRiskPanelProps) {
  const canReview = usePermission('purchasing.vendor.risk.review');
  return (
    <Card padding="md" className="space-y-3">
      <div className="flex items-center justify-between">
        <div className="flex items-baseline gap-2">
          <span className="font-display text-2xl tabular-nums text-ink-950" dir="ltr">{score}</span>
          <span className="text-ink-500">/ 100</span>
          <VendorRiskBadge level={level} score={score} />
        </div>
        {level === 'critical' && (
          <Badge tone="danger">Flagged for manual review within 48 hours</Badge>
        )}
      </div>
      {canReview && reasoning?.length ? (
        <ul className="space-y-1.5 text-caption">
          {reasoning.map((r, i) => (
            <li key={i} className="flex items-center justify-between border-t border-ink-150 pt-1.5 first:border-0 first:pt-0">
              <span className="text-ink-700">{r.factor}</span>
              <span className="font-mono tabular-nums text-ink-500" dir="ltr">+{r.contribution.toFixed(1)}</span>
            </li>
          ))}
        </ul>
      ) : null}
    </Card>
  );
}
```

`risk_score_reasoning` is rendered exactly as `GET /purchasing/vendors/{id}` returns it — this panel
never recomputes a contribution or re-orders the factor list by its own judgment, matching the platform
rule that "no card shows a bare number the AI computed without showing its work." A `critical` level
additionally surfaces the 48-hour manual-review badge `docs/accounting/VENDORS.md → Risk Response
Matrix` names, purely informational — it triggers no client-side timer or countdown.

## `VendorApprovalStepper`

Visualizes the six-step Vendor Approval Workflow (`docs/accounting/VENDORS.md → Vendor Approval
Workflow`) and hosts inline approval for whichever step is `in_progress` and assigned to the caller.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `steps` | `{ id: number; name: string; status: 'pending' \| 'in_progress' \| 'approved' \| 'rejected' \| 'skipped'; requiredPermission: string; approvedBy?: string; approvedAt?: string; skipReason?: string; rejectionReason?: string }[]` | yes | Mirrors `vendor_approval_steps` verbatim. |
| `vendorId` | `number` | yes | |
| `onApproveStep` | `(stepId: number, note?: string) => Promise<void>` | yes | |
| `onRejectStep` | `(stepId: number, reason: string) => Promise<void>` | yes | Reason mandatory, ≥10 characters per the backend's own validation floor, enforced client-side as a fast-fail UX convenience only. |
| `onDelegateStep` | `(stepId: number, userId: number) => Promise<void>` | no | Only rendered for the step matching the caller's own pending assignment. |

```tsx
// components/purchasing/vendor-approval-stepper.tsx
'use client';

import { Check, Circle, X, MinusCircle } from 'lucide-react';
import { ApprovalCard } from '@/components/shared/approval-card';
import { usePermission } from '@/hooks/use-permission';
import { cn } from '@/lib/utils';

const STEP_ICON = { approved: Check, rejected: X, skipped: MinusCircle, pending: Circle, in_progress: Circle };

export function VendorApprovalStepper({ steps, vendorId, onApproveStep, onRejectStep, onDelegateStep }: VendorApprovalStepperProps) {
  const activeStep = steps.find((s) => s.status === 'in_progress');
  const canActOnActiveStep = usePermission(activeStep?.requiredPermission ?? '');

  return (
    <div className="space-y-4">
      <ol className="flex flex-wrap items-center gap-2">
        {steps.map((step) => {
          const Icon = STEP_ICON[step.status];
          return (
            <li key={step.id} className={cn(
              'flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-caption',
              step.status === 'approved' && 'border-success/30 bg-success/10 text-success',
              step.status === 'rejected' && 'border-danger/30 bg-danger/10 text-danger',
              step.status === 'skipped' && 'border-ink-150 bg-ink-100 text-ink-500',
              step.status === 'in_progress' && 'border-accent-600/40 bg-accent-100 text-accent-700',
              step.status === 'pending' && 'border-ink-150 text-ink-500',
            )}>
              <Icon className="h-3.5 w-3.5" aria-hidden />
              {step.name}
            </li>
          );
        })}
      </ol>
      {activeStep && (
        <ApprovalCard
          kind="vendor_approval"
          title={`${activeStep.name} — required to move this vendor to the next stage`}
          requestedAt={activeStep.startedAt}
          detailHref={`/purchasing/vendors/${vendorId}?tab=overview`}
          onApprove={(note) => onApproveStep(activeStep.id, note)}
          onReject={(reason) => onRejectStep(activeStep.id, reason)}
          onDelegate={onDelegateStep ? (userId) => onDelegateStep(activeStep.id, userId) : undefined}
        />
      )}
      {!canActOnActiveStep && activeStep && (
        <p className="text-caption text-ink-500">
          Waiting on {activeStep.name} — requires <code>{activeStep.requiredPermission}</code>.
        </p>
      )}
    </div>
  );
}
```

`ApprovalCard` gains `vendor_approval` as a new `kind` discriminant, mapping to a per-step permission
resolved dynamically from `activeStep.requiredPermission` rather than a single fixed key — the one place
this screen's usage of `ApprovalCard` differs from every other `kind`, because a vendor's six steps carry
six different permissions rather than one permission per record type. A step whose own `reject` reason
becomes the trigger for re-submission (see `docs/accounting/VENDORS.md → Workflow Semantics`,
"re-submission after rejection restarts only the rejected step and any steps after it") is rendered with
its `rejectionReason` visible in the stepper's own tooltip on that step's badge, so a Purchasing Employee
correcting a rejected vendor sees exactly what to fix without opening a separate audit view.

## `VendorLifecycleActionDialog`

One shared component for every reason-gated lifecycle transition — deactivate, blacklist, unblacklist,
archive, unarchive — rather than five near-identical one-off dialogs, mirroring `ApprovalCard`'s own
reject-reason pattern.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `action` | `'deactivate' \| 'blacklist' \| 'unblacklist' \| 'archive' \| 'unarchive'` | yes | Determines copy, minimum reason length, and whether the category select renders. |
| `vendorId` | `number` | yes | |
| `openBalance` | `string \| null` | no | When `action==='archive'` and this is non-zero, the dialog renders the 409 guard message and, only for a caller holding `purchasing.vendor.archive.force`, an explicit "Override and archive anyway" checkbox requiring its own separate justification text. |
| `openPurchaseOrderCount` | `number` | no | Same guard, for the "cannot archive with in-flight purchasing activity" rule. |
| `onConfirm` | `(payload: { reason: string; category?: BlacklistCategory; force?: boolean }) => Promise<void>` | yes | |

Minimum reason length is enforced client-side as a fast-fail convenience matching the backend's own
floor exactly: 10 characters for deactivate, 20 for blacklist (plus the mandatory `blacklist_category`
select — `fraud` / `sanctions` / `quality` / `contract_breach` / `legal_dispute` / `other`), 10 for
archive/unarchive/unblacklist reasons. The server re-validates the identical minimums unconditionally;
this dialog's own check exists only to reject an obviously-too-short reason before a round trip, per
`FRONTEND_ARCHITECTURE.md` Principle 1.

```tsx
// components/purchasing/vendor-lifecycle-action-dialog.tsx (excerpt — the archive-guard branch)
{action === 'archive' && (openBalance !== '0.0000' || openPurchaseOrderCount > 0) && (
  <div className="rounded-md bg-warning/10 border border-warning/30 p-3 text-caption text-ink-950">
    <p>
      This vendor has {openBalance !== '0.0000' && <>an open balance of <AmountCell amount={openBalance} currencyCode="KWD" showCurrency /></>}
      {openBalance !== '0.0000' && openPurchaseOrderCount > 0 && ' and '}
      {openPurchaseOrderCount > 0 && `${openPurchaseOrderCount} in-flight purchase order(s)`}.
    </p>
    {canForceArchive && (
      <label className="mt-2 flex items-center gap-2">
        <Checkbox checked={forceOverride} onCheckedChange={setForceOverride} />
        Archive anyway (requires a separate justification below)
      </label>
    )}
  </div>
)}
```

## `BankAccountVerifyDialog`

The UI for the Bank Account Verification sub-workflow (`docs/accounting/VENDORS.md → Bank Account
Verification Sub-Workflow`) — the single most security-sensitive interaction on this screen pair,
because bank-detail changes are, in the backend document's own words, "the single highest-value fraud
vector in AP."

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `bankAccountId` | `number` | yes | |
| `mode` | `'verify' \| 'reject'` | yes | |
| `onSubmit` | `(payload: { verificationMethod?: BankVerificationMethod; evidenceAttachmentId?: number; reason?: string }) => Promise<void>` | yes | `verificationMethod` + `evidenceAttachmentId` required for `mode="verify"`; `reason` required for `mode="reject"`. |

`mode="verify"` requires selecting a `verification_method` (`callback_call` / `signed_letter` /
`bank_confirmation_letter` / `micro_deposit`) and attaching evidence before the "Confirm verified" button
enables — there is no path to a verified bank account on this screen without both fields present, mirroring
the backend's own "verification requires a distinct, dedicated action... it cannot be done implicitly by
simply editing the row" rule. A rejected row (`mode="reject"`) is permanently terminal: once submitted,
`VendorBankAccountsList` renders that row read-only with a "Rejected — add a new bank account" caption
rather than an editable "try again" affordance, matching the backend's explicit "a rejected bank-detail
claim is corrected by creating a new row, not by resurrecting a failed one."

## `DuplicateCandidateCard` and the hard-block dialog

Two distinct UI moments correspond to the two distinct confidence bands `docs/accounting/VENDORS.md →
Duplicate Detection` defines, and this screen never conflates them.

**`DuplicateCandidateCard`** (0.70–0.89, suggest-only) is an `AIProposalPanel` composition for the
`duplicate-candidates/page.tsx` queue:

```tsx
<AIProposalPanel
  decision={{
    id: candidate.id,
    agentCode: 'GENERAL_ACCOUNTANT',
    confidenceScore: candidate.match_score * 100,
    recommendedAction: `Merge "${candidate.vendor_a.legal_name}" into "${candidate.vendor_b.legal_name}"`,
    alternatives: [{ action: 'Dismiss — not a duplicate', tradeoff: 'Both vendors remain active and independently trackable.' }],
    reasoning: candidate.matching_signals.map((s) => `${s.signal}: ${s.match} (+${s.contribution})`).join(', '),
    sources: [{ type: 'vendors', id: candidate.vendor_a.id }, { type: 'vendors', id: candidate.vendor_b.id }],
  }}
  autonomyLevel="suggest_only"
  onSendForApproval={() => openMergeDialog(candidate)}
  onDismiss={(reason) => dismissMutation.mutateAsync({ id: candidate.id, reason })}
/>
```

`onSendForApproval` opens `VendorMergeDialog` pre-populated with both vendors rather than immediately
merging — `purchasing.vendor.merge` gates the dialog's own confirm button, so a caller can review a
candidate pair without holding the permission to act on it, and the card's "Do it" affordance is
structurally absent (`autonomyLevel="suggest_only"` never renders one) because merging is not a decision
an `AIProposalPanel` executes on its own regardless of confidence.

**The hard-block dialog** (≥0.90, deterministic) is not an AI proposal at all — it is the UI's handling
of a `409` the create/update endpoint itself returns, per `docs/accounting/VENDORS.md → Business Rule
9`. `VendorForm`'s submit handler catches this specific error code and renders a blocking `AlertDialog`
rather than a form-field error, because the resolution is "go look at the existing vendor," not "fix a
field":

```tsx
// components/purchasing/vendor-form.tsx (excerpt — duplicate-block handling)
async function onSubmit(values: VendorFormValues) {
  try {
    const vendor = await createVendor.mutateAsync(values);
    router.push(`/purchasing/vendors/${vendor.id}`);
  } catch (err) {
    if (err instanceof ApiError && err.code === 'vendor.duplicate_high_confidence') {
      const { matched_vendor_id, matched_vendor_code, match_score, matching_signals } = err.fieldErrors[0];
      setDuplicateBlock({ matched_vendor_id, matched_vendor_code, match_score, matching_signals });
      return;
    }
    toast.fromApiError(err);
  }
}
```

```tsx
<AlertDialog open={!!duplicateBlock} onOpenChange={() => setDuplicateBlock(null)}>
  <AlertDialogContent>
    <AlertDialogHeader>
      <AlertDialogTitle>A vendor matching this profile already exists</AlertDialogTitle>
    </AlertDialogHeader>
    <p className="text-body text-ink-700">
      {duplicateBlock?.matched_vendor_code} matches at {Math.round((duplicateBlock?.match_score ?? 0) * 100)}%
      confidence:
    </p>
    <ul className="text-caption text-ink-500 space-y-1">
      {duplicateBlock?.matching_signals.map((s, i) => (
        <li key={i}>{s.signal} — {s.match}{s.similarity && ` (${Math.round(s.similarity * 100)}% similar)`}</li>
      ))}
    </ul>
    <AlertDialogFooter>
      <AlertDialogCancel>Edit and try again</AlertDialogCancel>
      <AlertDialogAction onClick={() => router.push(`/purchasing/vendors/${duplicateBlock.matched_vendor_id}`)}>
        Go to existing vendor
      </AlertDialogAction>
    </AlertDialogFooter>
  </AlertDialogContent>
</AlertDialog>
```

This is a genuine `409`, not a `422` — `VendorForm`'s Zod schema (below) never attempts to predict or
pre-validate a duplicate client-side, because the fuzzy-matching signal set (trigram similarity, IBAN/tax
ID normalization) has no meaningful client-side equivalent and must be the server's own answer, matching
`FRONTEND_ARCHITECTURE.md` Principle 1 exactly.

## `VendorForm`

The create/edit surface for a vendor's core profile fields, following `JournalEntryForm`'s established
`mode` convention.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `mode` | `'create' \| 'edit'` | yes | `'edit'` renders `vendor_type` read-only unless the caller holds `purchasing.vendor.reclassify` (see **Route & Access**). |
| `vendorId` | `number` | conditional | Required when `mode="edit"`. |
| `defaultValues` | `Partial<VendorFormValues>` | no | Pre-fill, e.g. from a Smart Suggestions autocomplete match on a Purchase Order screen that hands off into "create a new vendor instead." |
| `onSubmitted` | `(vendor: Vendor) => void` | no | Called after a successful create/update. |

```ts
// lib/schemas/vendor.ts
import { z } from 'zod';

export const vendorContactSchema = z.object({
  full_name: z.string().min(1),
  email: z.string().email().optional(),
  phone: z.string().optional(),
  contact_type: z.enum(['primary', 'billing', 'sales', 'technical', 'escalation', 'signatory']),
  is_primary: z.boolean().default(false),
});

export const vendorAddressSchema = z.object({
  address_type: z.enum(['registered', 'billing', 'shipping', 'factory', 'warehouse', 'other']),
  address_line1: z.string().min(1),
  city: z.string().min(1),
  country_code: z.string().length(2),
  is_primary: z.boolean().default(false),
});

export const vendorFormSchema = z.object({
  vendor_type: z.enum(['individual', 'company', 'government', 'international', 'service_provider', 'manufacturer', 'distributor', 'contractor']),
  legal_name: z.string().min(1, 'legalNameRequired'),
  name_ar: z.string().optional(),
  display_name: z.string().optional(),
  national_id: z.string().optional(),
  commercial_registration_number: z.string().optional(),
  tax_registration_number: z.string().optional(),
  vat_registered: z.boolean().default(false),
  vat_registration_number: z.string().optional(),
  default_currency_code: z.string().length(3).default('KWD'),
  default_payment_terms: z.enum(['net_15', 'net_30', 'net_45', 'net_60', 'net_90', 'due_on_receipt', 'cod', 'custom']),
  custom_payment_terms_days: z.number().int().positive().optional(),
  credit_limit_amount: z.string().regex(/^\d+(\.\d{1,4})?$/).optional(),
  tags: z.array(z.string()).default([]),
  primary_contact: vendorContactSchema.optional(),
  registered_address: vendorAddressSchema.optional(),
}).superRefine((v, ctx) => {
  if (v.vendor_type === 'individual' && !v.national_id) {
    ctx.addIssue({ code: 'custom', message: 'nationalIdRequiredForIndividual', path: ['national_id'] });
  }
  if (v.vat_registered && !v.vat_registration_number) {
    ctx.addIssue({ code: 'custom', message: 'vatNumberRequiredWhenRegistered', path: ['vat_registration_number'] });
  }
  if (v.default_payment_terms === 'custom' && !v.custom_payment_terms_days) {
    ctx.addIssue({ code: 'custom', message: 'customDaysRequired', path: ['custom_payment_terms_days'] });
  }
});

export type VendorFormValues = z.infer<typeof vendorFormSchema>;
```

The `superRefine` block reproduces, client-side and as a UX convenience only, three of the backend's own
conditional-requirement rules (`docs/accounting/VENDORS.md → Vendor Profile`, national ID for
individuals; VAT number when registered; custom days when payment terms are custom) — the server
re-validates the identical rules unconditionally on submit and is the only party whose answer is
authoritative, exactly as `JournalEntryForm`'s balanced-lines check is described in `COMPONENT_LIBRARY.md`.
`vendor_type === 'individual'` additionally hides the Commercial Registration and Legal Structure fields
entirely (not merely marks them optional), and `vendor_type === 'contractor'` renders an inline notice
that a `vendor_contracts` row is mandatory before any Purchase Order can reference this vendor, per
`docs/accounting/VENDORS.md → Vendor Types`'s per-type field table — `VendorForm` reads this same table
to decide which fields to show, never a separate, drifting copy of it.

## Read-through table components

`VendorAgingTable`, `VendorStatementTable`, `VendorTransactionsTable`, and `VendorAuditLogTable` are each
a preset `DataTable` scoped to one vendor, following `VendorsTable`'s identical construction pattern —
own columns, own `resource` string, own `defaultFilters={{ vendor_id }}` — and are not reproduced in full
here to avoid restating `DataTable`'s already-specified contract four more times. Their column sets are:

| Component | Columns | Resource |
|---|---|---|
| `VendorAgingTable` | Document #, Type (Bill / Debit Note), Document date, Due date, Days overdue, Current / 1–30 / 31–60 / 61–90 / 90+ bucket amounts, Total | `purchasing/vendors/{id}/open-items` |
| `VendorStatementTable` | Date, Type (Bill / Payment / Debit Note), Reference, Debit, Credit, Running balance | Merged client-side from `purchasing/bills` + `purchasing/vendor-payments`, both filtered by `vendor_id` |
| `VendorTransactionsTable` | PO #, Date, Status, Expected delivery, Received date, Amount | `purchasing/vendors/{id}/purchase-orders`, with a tab-level link out to the Bills and Vendor Payments screens pre-filtered to this vendor |
| `VendorAuditLogTable` | Timestamp, Actor, Field, Old value, New value, Reason | `purchasing/vendors/{id}/audit-log` |

`VendorStatementTable`'s running balance is computed client-side purely for **display sequencing**
(a chronological running total the eye can follow line by line) and is never treated as the authoritative
balance — the AP Aging tab's total, sourced directly from `vendor_open_items`, is the number a Finance
Manager reconciles against the GL, and the Statement tab says so explicitly in a caption beneath its own
running-balance column.

# Data & State

## Endpoints

Every figure and every row on this screen pair resolves to an endpoint `docs/accounting/VENDORS.md →
API` already defines; this screen pair introduces no endpoint of its own beyond one small, reasonable
read-model convenience (`vendors/stats`, `vendors/{id}/open-items`) needed to paint the KPI band and the
AP Aging tab without the frontend re-aggregating raw rows itself.

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Vendor roster | `GET /api/v1/purchasing/vendors` | `purchasing.vendor.read` | Feeds `VendorsTable`; supports `?filter[vendor_type]=`, `?filter[status]=`, `?filter[risk_level][in]=`, `?filter[tags][any]=`, `?q=` |
| Vendor list stats | `GET /api/v1/purchasing/vendors/stats` | `purchasing.vendor.read` | Feeds the KPI band's four `KpiTile`s in one call — active count, pending-approval count, `SUM(balance_due)`, high/critical risk count, never re-aggregated client-side from the full roster |
| Create vendor | `POST /api/v1/purchasing/vendors` | `purchasing.vendor.create` | Runs the synchronous duplicate check; a `409 vendor.duplicate_high_confidence` is the expected, handled outcome for a high-confidence match |
| Vendor profile | `GET /api/v1/purchasing/vendors/{id}` | `purchasing.vendor.read` | Full profile including embedded children (`contacts[]`, `addresses[]`, `bank_accounts[]`, `certificates[]`, `contracts[]`, `approval_workflow`, `risk_score_reasoning`) |
| Update vendor | `PUT /api/v1/purchasing/vendors/{id}` | `purchasing.vendor.update` | Core profile fields; a `vendor_type` change triggers server-side re-validation and may surface new required-field warnings on the next fetch |
| Delete vendor | `DELETE /api/v1/purchasing/vendors/{id}` | `purchasing.vendor.delete` | Succeeds only for `status='prospect'` with zero linked documents; otherwise `409` |
| Submit for approval | `POST /api/v1/purchasing/vendors/{id}/submit-for-approval` | `purchasing.vendor.submit` | Initiates the six-step workflow |
| Approve a step | `POST /api/v1/purchasing/vendors/{id}/approval-steps/{stepId}/approve` | per-step (see **Route & Access**) | `VendorApprovalStepper`'s Approve action |
| Reject a step | `POST /api/v1/purchasing/vendors/{id}/approval-steps/{stepId}/reject` | per-step | Reason required, ≥10 characters |
| Activate | `POST /api/v1/purchasing/vendors/{id}/activate` | `purchasing.vendor.activate` | `approved`/`inactive` → `active` |
| Deactivate | `POST /api/v1/purchasing/vendors/{id}/deactivate` | `purchasing.vendor.deactivate` | `active` → `inactive`, reason required |
| Blacklist | `POST /api/v1/purchasing/vendors/{id}/blacklist` | `purchasing.vendor.blacklist` | Any state → `blacklisted`, reason (≥20 chars) + category required |
| Unblacklist | `POST /api/v1/purchasing/vendors/{id}/unblacklist` | `purchasing.vendor.unblacklist` | `blacklisted` → `inactive`, reason required |
| Archive | `POST /api/v1/purchasing/vendors/{id}/archive` | `purchasing.vendor.archive` | `409` if open balance/open POs, unless `.force` |
| Unarchive | `POST /api/v1/purchasing/vendors/{id}/unarchive` | `purchasing.vendor.unarchive` | `archived` → `inactive` |
| Merge | `POST /api/v1/purchasing/vendors/merge` | `purchasing.vendor.merge` | `VendorMergeDialog`'s confirm action |
| Duplicate candidates | `GET /api/v1/purchasing/vendors/duplicate-candidates` | `purchasing.vendor.merge` | Feeds the duplicate-candidates queue and the list screen's banner probe |
| Dismiss a candidate | `POST /api/v1/purchasing/vendors/duplicate-candidates/{id}/dismiss` | `purchasing.vendor.merge` | `DuplicateCandidateCard`'s Dismiss action |
| Add / update / remove contact | `POST .../{id}/contacts`, `PUT`/`DELETE .../{id}/contacts/{contactId}` | `purchasing.vendor.update` | `VendorContactsList`'s add/edit dialog |
| Add address | `POST /api/v1/purchasing/vendors/{id}/addresses` | `purchasing.vendor.update` | `VendorAddressesList` |
| Add bank account | `POST /api/v1/purchasing/vendors/{id}/bank-accounts` | `purchasing.vendor.update` | Lands as `pending_verification`, never immediately payable-to |
| Verify / reject bank account | `POST .../bank-accounts/{bankId}/verify`, `POST .../bank-accounts/{bankId}/reject` | `purchasing.vendor.bank.verify` | `BankAccountVerifyDialog` |
| Add certificate | `POST /api/v1/purchasing/vendors/{id}/certificates` | `purchasing.vendor.certificates.manage` | `VendorCertificatesList` |
| Add contract | `POST /api/v1/purchasing/vendors/{id}/contracts` | `purchasing.vendor.contracts.manage` | Triggers the AI Contract Analysis extraction job; `VendorContractsList` polls for the resulting proposal |
| Performance scorecard | `GET /api/v1/purchasing/vendors/{id}/performance` | `purchasing.vendor.read` | Linked from the Transactions tab, not embedded inline on this screen |
| Audit log | `GET /api/v1/purchasing/vendors/{id}/audit-log` | `purchasing.vendor.audit.read` | `VendorAuditLogTable`, cursor-paginated |
| Open items (AP Aging) | `GET /api/v1/purchasing/vendors/{id}/open-items` | `purchasing.vendor.read` | `VendorAgingTable`, sourced from the `vendor_open_items` materialized read-model |
| Purchase orders (read-through) | `GET /api/v1/purchasing/vendors/{id}/purchase-orders` | `purchasing.vendor.read` | `VendorTransactionsTable` |
| Payment profile (read-through) | `GET /api/v1/purchasing/vendors/{id}/payment-profile` | `purchasing.vendor.read` | Not rendered directly on this screen; consumed by the Purchase Order screen's vendor picker |
| Spend by project (read-through) | `GET /api/v1/purchasing/vendors/{id}/spend-by-project` | `purchasing.vendor.read` | Linked from the Transactions tab's "View spend by project" action, its own report view |
| Assets supplied (read-through) | `GET /api/v1/purchasing/vendors/{id}/assets-supplied` | `purchasing.vendor.read` | Linked from the Transactions tab for `manufacturer`/`distributor` vendors only |
| Import template | `GET /api/v1/purchasing/vendors/import/template` | `purchasing.vendor.import` | "Import" header action's first step |
| Import | `POST /api/v1/purchasing/vendors/import` | `purchasing.vendor.import` | Async job |
| Import status | `GET /api/v1/purchasing/vendors/import/{jobId}` | `purchasing.vendor.import` | Polled while `status=processing` |
| Export | `GET /api/v1/purchasing/vendors/export` | `purchasing.vendor.export` | Synchronous under 10,000 rows, async above |
| Bulk operation | `POST /api/v1/purchasing/vendors/bulk` | `purchasing.vendor.update` | Multi-select "Add tag" / "Remove tag" / "Deactivate" / "Set custom field" on `VendorsTable` |
| Bills (statement, filtered) | `GET /api/v1/purchasing/bills?filter[vendor_id]=` | `purchasing.bill.read` | `VendorStatementTable`'s bill half |
| Vendor payments (statement, filtered) | `GET /api/v1/purchasing/vendor-payments?filter[vendor_id]=` | `purchasing.bill.read` | `VendorStatementTable`'s payment half |

## Query keys and cache tuning

```ts
// lib/api/query-keys.ts (purchasing-scoped factories, additive to the platform's shared factories)
export const vendorKeys = {
  all: ["purchasing", "vendors"] as const,
  list: (filters?: VendorFilters) => [...vendorKeys.all, "list", filters ?? {}] as const,
  stats: () => [...vendorKeys.all, "stats"] as const,
  detail: (id: number) => [...vendorKeys.all, "detail", id] as const,
  openItems: (id: number) => [...vendorKeys.all, "open-items", id] as const,
  purchaseOrders: (id: number) => [...vendorKeys.all, "purchase-orders", id] as const,
  auditLog: (id: number) => [...vendorKeys.all, "audit-log", id] as const,
  duplicateCandidates: (filters?: DuplicateCandidateFilters) => [...vendorKeys.all, "duplicate-candidates", filters ?? {}] as const,
};
```

Every top-level key is implicitly company-scoped because `QueryClient` itself is re-created on company
switch rather than shared across companies (`FRONTEND_ARCHITECTURE.md → Query key architecture`) — a
component that forgets to invalidate `vendorKeys.*` on switch still cannot serve a stale company's
vendor roster, because there is no live cache instance left holding it.

Cache tuning follows `FRONTEND_ARCHITECTURE.md → Cache tuning by data class` exactly:

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Rarely-changing reference | none on this screen — even `vendors` master data changes often enough via lifecycle actions and bill/payment posting to not qualify | — | — |
| Transactional lists | `vendorKeys.list`, `vendorKeys.duplicateCandidates` | `30_000` | Frequent enough writes (a Purchasing Employee submitting several vendors in a session) that a stale list is a real annoyance, not worth polling |
| Live/derived figures | `vendorKeys.stats`, `vendorKeys.detail`'s `balance_due`/`risk_score`/`risk_level` fields | `0` (always stale) | Correctness matters more than avoiding a refetch; kept fresh primarily via Realtime invalidation rather than polling — the identical class `BANKING.md`'s Cash Position band uses |
| AI feeds | none distinct here — risk score and duplicate candidates arrive embedded in the `live/derived` resources above rather than a separate `ai/*` feed, because this module surfaces its AI outputs as fields on the vendor record itself, not as a standalone recommendation stream | — | — |

## Realtime

Vendors subscribes to one company-scoped, module-wide private channel, mirroring the platform's
`private-company.{id}.<feature>` convention rather than minting per-vendor channels that would multiply
subscriptions for a company with hundreds of vendors:

| Channel | Events carried | Effect |
|---|---|---|
| `private-company.{id}.vendors` | `vendor.status_changed`, `vendor.risk_score_updated`, `vendor.duplicate_candidate_found`, `vendor.bank_account.verified`, `vendor.fraud_flag_raised`, `vendor.balance_updated`, `vendor.approval_step_completed` | Drives cache invalidation/patching, see below |
| `private-company.{id}.ai-jobs` | Any `ai_decisions`-equivalent output from the Auditor, Fraud Detection, or Document AI/OCR agent finishing a vendor-scoped run | Refreshes the Risk Score panel and the Contract Analysis extraction proposal the moment an agent run affecting the open profile completes |
| `private-company.{id}.notifications.{user_id}` | `vendor.bank_account.verification_requested` (the callback-verification notification to Finance Manager), `vendor.certificate.expiring_soon` | Feeds the Topbar's notification bell only — this screen's own regions do not duplicate this stream, matching `BANKING.md`'s identical rule |

Event handling follows `FRONTEND_ARCHITECTURE.md → Invalidate, or patch — chosen per event, not by
default`:

- `vendor.status_changed` and `vendor.approval_step_completed` invalidate `vendorKeys.detail(id)` and
  `vendorKeys.list()` as a full `invalidateQueries` call — a lifecycle transition is never patched
  optimistically from a realtime push, because the push carries no guarantee it reflects the full
  server-computed side effects (e.g., a blacklist cascading a hold to open purchase orders).
- `vendor.balance_updated` (fired when Purchasing posts a bill, payment, or debit note against this
  vendor) invalidates `vendorKeys.detail(id)`, `vendorKeys.openItems(id)`, and `vendorKeys.stats()` — the
  same three places `balance_due` can appear on screen, kept consistent as one batch rather than three
  independent refetches racing each other.
- `vendor.risk_score_updated` and `vendor.fraud_flag_raised` patch the open profile's `VendorRiskPanel`
  and any visible fraud-alert banner in place (a `setQueryData` patch, not a full refetch), so a
  Finance Manager already reading a vendor's risk breakdown sees a live score update without losing
  scroll position, mirroring `BANKING.md`'s identical "Fraud Detection holds a payment while its card is
  already open" rule.
- `vendor.duplicate_candidate_found` invalidates `vendorKeys.duplicateCandidates()` and the list screen's
  banner probe — a newly-detected pair surfaces the next time either screen is viewed, without requiring
  a manual refresh.
- `vendor.bank_account.verified` invalidates the open profile's `vendorKeys.detail(id)` so the Bank
  Accounts tab's status pill flips from `pending_verification` to `verified` live.
- On WebSocket reconnect after any extended drop, every one of this screen pair's realtime-fed query keys
  (`vendorKeys.*`) is invalidated once, as a single batch — a missed fraud flag or a missed bank
  verification during an outage must surface the moment connectivity returns, never wait for a manual
  refresh, exactly as `BANKING.md` and `AI_COMMAND_CENTER.md` both specify for their own realtime-fed
  panels.

## AI agents feeding this screen pair

| Agent | Contribution to this screen pair |
|---|---|
| Auditor | Computes `risk_score`/`risk_level`/`risk_score_reasoning` (primary owner); validates Supplier Performance aggregates before they are trusted for scoring; signs off the Compliance/Sanctions Screening workflow step alongside Fraud Detection's automated pre-check |
| Fraud Detection | Contributes transactional-anomaly sub-signals into the risk score; raises `vendor_fraud_flags` for BEC-pattern bank-change-then-large-invoice sequences, shared-IBAN/shared-tax-ID near-duplicates, spoofed-domain contact emails, and threshold-shopping invoice clustering — surfaced as an urgent, non-dismissible-without-reason banner at ≥0.80 confidence |
| General Accountant | Runs the deterministic Duplicate Detection scoring (both the synchronous creation-time check and the nightly background sweep); powers Smart Suggestions' vendor-autocomplete and tag pre-fill, consumed by the Purchase Order screen rather than rendered inline here |
| Document AI / OCR Agent | Extracts structured terms (`start_date`, `end_date`, `contract_value`, `payment_milestones[]`, `renewal_notice_days`, penalty clauses) from an uploaded contract PDF/DOCX; assists Duplicate Detection when an OCR-extracted tax ID is used as an additional matching signal |
| Approval Assistant | Contributes Smart Suggestions' supplier-consolidation and default-WHT-rate pre-fill suggestions, both consumed elsewhere (Purchase Order creation, Bill creation) rather than rendered as a standalone panel on this screen pair |

Every one of these outputs renders through the platform's one mandatory contract — a `confidence`, a
`reasoning`, and (where applicable) `evidence_ref`/`sources` — per **AI Integration** below.

# Interactions & Flows

**Opening the list.** The page shell (sub-nav, header, filter bar) paints immediately; the KPI band and
the duplicate-candidates banner probe resolve independently a beat later, and `VendorsTable` streams in
on its own boundary — no region blocks another, per **Data & State**.

**Searching and filtering.** Search, category, status, risk, and tag filters all round-trip through
`DataTable`'s own query-param-driven state — no client-side filtering ever runs against an
already-fetched page, matching `BANKING.md`'s identical rule for its own transactions table. Combining
filters (e.g., `status=active` + `risk_level=high,critical`) is additive, and the applied-filter set is
reflected in the URL query string, making a filtered roster view a shareable link.

**Opening a vendor.** A row click (outside the row's own `DropdownMenu`) navigates to
`/purchasing/vendors/{id}`, a real navigation with its own bookmarkable URL — never a `Sheet` or `Dialog`
over the list, because a vendor profile is substantial content (up to nine tabs, several child
collections) that deserves its own address, exactly as `BANKING.md`'s account register does for the
identical reason.

**Creating a vendor.** "New vendor" (`purchasing.vendor.create`) navigates to the full-page
`/purchasing/vendors/new`, never a modal — vendor creation can return a blocking `409` duplicate match
that needs room to present matching signals and an escape hatch to the existing record (see
**Components Used → the hard-block dialog**), which a cramped quick-create `Dialog` would not
comfortably accommodate. `VendorForm mode="create"` renders the fields relevant to the selected
`vendor_type` (see **Components Used → `VendorForm`**), with the primary contact and registered address
collected inline on the same form rather than as a required second step, since both are prerequisites for
the vendor to progress past `prospect` in the approval workflow. A successful create redirects to the new
vendor's own profile at `status=prospect`, where "Submit for approval" is immediately available.

**Submitting for approval and working the workflow.** From the profile's `⋯ Actions` menu, "Submit for
approval" (`purchasing.vendor.submit`) calls `.../submit-for-approval` and the Overview tab's
`VendorApprovalStepper` begins reflecting live step state. Steps 1 (Data Completeness) and 2 (Duplicate
Check) resolve automatically and require no UI action beyond seeing them tick to `approved`/`skipped`;
steps 3 (Compliance) and 4 (Bank Account Verification) can proceed in parallel, each rendering its own
`ApprovalCard` to whichever caller holds the matching permission; steps 5 (Commercial) and 6 (Financial)
are sequential and only the latter's `ApprovalCard` appears once step 5 has completed. Rejecting any step
(reason required) returns `vendors.status` to `prospect` — never a separate "rejected" status — and the
stepper visually distinguishes the rejected step; correcting the flagged field(s) and re-submitting
restarts only that step and any after it, exactly per **Components Used → `VendorApprovalStepper`**.

**Activating, deactivating, blacklisting, archiving.** Every one of these is a `VendorLifecycleAction
Dialog` invocation from either the list row's `DropdownMenu` or the profile's `⋯ Actions` menu — never a
bare confirm-and-fire click, because every one of them requires a reason the backend persists to
`vendor_status_history`. Blacklisting additionally requires the `blacklist_category` select; a vendor
blacklisted with open unpaid bills triggers a platform notification to Finance Manager and Auditor roles
(handled entirely server-side — this screen shows the resulting `on_hold` flag on the vendor's linked
Purchase Orders the next time that list is viewed, never a special modal of its own). Archiving with a
non-zero open balance or in-flight purchase orders shows the guard message from **Components Used**
and, only for a `purchasing.vendor.archive.force` holder, the override checkbox with its own mandatory
justification text.

**Managing bank accounts.** "Add bank account" opens a `Dialog` collecting `account_holder_name`,
`bank_name`, `bank_country`, `iban`/`account_number`, `swift_bic`, and `currency_code`; the new row lands
at `pending_verification` and cannot be selected as a payment destination anywhere in the product until a
Finance Manager runs `BankAccountVerifyDialog`'s verify flow, which requires both a `verification_method`
selection and an evidence attachment — there is no shortcut path on this screen that skips either field.
A verification-request notification (see **Data & State → Realtime**) reaches Finance Manager roles the
moment the account is added, and the callback-verification itself (the actual phone call or signed-letter
review) happens out of band — this screen only records its outcome, it never dials a number or opens an
email client on the user's behalf.

**Managing certificates and contracts.** "Add certificate" collects `certificate_type`,
`certificate_number`, `issuing_body`, `issue_date`, `expiry_date`, and an attachment; a scheduled backend
job recomputes each certificate's `status` (`valid`/`expiring_soon`/`expired`/`revoked`) independently of
any UI action, and `VendorCertificatesList` simply reflects whatever `status` the last fetch returned.
"Add contract" uploads a PDF/DOCX and immediately queues the AI Contract Analysis extraction job; the
contract's row shows a "Extracting terms…" state (see **States**) until the job completes, at which point
`VendorContractsList` renders the extraction as a pending proposal — every extracted field editable, no
field silently accepted — that a `purchasing.vendor.contracts.manage` holder must explicitly confirm
before it becomes the contract's authoritative `payment_milestones`/`contract_value`/etc.

**Reviewing duplicate candidates and merging.** `/purchasing/vendors/duplicate-candidates`
(`purchasing.vendor.merge`) lists every pending medium-confidence pair as a `DuplicateCandidateCard`.
"Send for approval" on a card opens `VendorMergeDialog`, pre-populated with both vendors and a
recommended `survivor_id` (the vendor with more transactional history, mirroring the backend's own
practical convention, always user-overridable); confirming requires a typed reason and calls `POST
/purchasing/vendors/merge`, which re-points every child collection and every transactional FK reference
onto the survivor inside one transaction, sums `balance_due` and lifetime counters, and archives the
merged vendor with `archived_reason='merged'`. The dialog states, in plain language, that this action is
irreversible via the API before its confirm button is enabled — no "are you sure?" euphemism, the actual
consequence spelled out, per `DESIGN_LANGUAGE.md → Voice & Microcopy`'s "state what happened, why, and
what to do next" rule applied to a pre-action warning instead of a post-action toast.

**Tags and custom fields.** Both are editable inline from the Overview tab via a small `Popover`
(tag multi-select, backed by a `GET .../vendors?q=` autocomplete over existing tags to encourage reuse
rather than near-duplicate tag proliferation) and a `custom_fields` editor whose available fields are
driven entirely by the company's own `custom_field_definitions` — this screen renders whatever fields
that company has configured, never a fixed set.

**Import, export, and bulk operations.** "Import" opens a `Dialog` with a "Download template" link
(`GET .../import/template`) and a file dropzone; submitting shows a `status=processing` state polling
`GET .../import/{jobId}` until completion, then reports the exact per-row outcome breakdown
(`created`/`updated`/`skipped_duplicate`/`failed_validation`) the endpoint returns — no row's outcome is
ever summarized away. "Export" streams synchronously under 10,000 rows or returns a job+download link
above that threshold, respecting the list's current filter set exactly as `DataTable`'s contract
specifies. A multi-row selection on `VendorsTable` enables a "Bulk actions" toolbar
(`add_tag`/`remove_tag`/`deactivate`/`set_custom_field`, capped at 500 rows per call, `purchasing.
vendor.update`), which reports a per-chunk success/failure summary rather than failing the entire batch
on one bad row, matching the backend's own chunked-transaction design.

# AI Integration

Every AI-authored element on this screen pair renders through the platform's one mandatory contract
(`FRONTEND_ARCHITECTURE.md → AI Integration Layer`): a confidence figure, a reasoning narrative, and its
supporting sources, in the shared visual envelope, with no card ever showing a bare number the AI
computed without showing its work. Vendor Management is, by `docs/accounting/VENDORS.md`'s own framing,
one of the two or three highest-value fraud surfaces in the entire product (alongside Banking) — a wrong
AI output here is exactly the class of error that can redirect real money to an attacker's account — so
every rule below inherits that strict posture rather than softening it for a calmer-looking screen.

**Vendor Risk Score.** Rendered via `VendorRiskPanel` on the Overview tab and `VendorRiskBadge`
everywhere the vendor appears in a table row — computed nightly plus on trigger events (bank-account
change, new certificate expiry, a related vendor's blacklist-adjacent status change, a payment dispute, a
PO value spike), never on page load. The panel's autonomy is auto-compute/suggest-only-for-action: the
score itself is written automatically, but any consequence gated on it (a mandatory extra approval step,
a blocking dialog) is enforced by the deterministic workflow engine the score feeds, never by the AI
directly vetoing an action. A `critical` level's reasoning is never collapsed or truncated — the full
`{factor, weight, contribution, evidence_ref}` breakdown is available to any `purchasing.vendor.risk.
review` holder, per **Components Used**.

**Duplicate Detection.** Two distinct UI treatments correspond to the two distinct confidence bands, and
this screen never blurs them into one pattern:

```json
// GET /api/v1/purchasing/vendors/duplicate-candidates — one row
{
  "id": 8842,
  "vendor_a": { "id": 3190, "vendor_code": "V-0042-003190", "legal_name": "Al-Salam Trading Co. W.L.L." },
  "vendor_b": { "id": 5510, "vendor_code": "V-0042-005510", "legal_name": "Al Salam Trading Company" },
  "match_score": 0.83,
  "matching_signals": [
    { "signal": "tax_registration_number", "match": "exact", "contribution": 0.40 },
    { "signal": "legal_name", "match": "fuzzy", "similarity": 0.88, "contribution": 0.132 }
  ],
  "status": "pending_review",
  "detected_at": "2026-07-14T02:00:00Z"
}
```

At **≥0.90** (a genuine `409` at creation time, not an item in this queue at all), the create flow is
hard-blocked deterministically — this is stated as a hard rule in `docs/accounting/VENDORS.md → Business
Rule 9` precisely so it is never treated as an AI judgment call a user can dismiss in the moment; the UI
reflects that by rendering it as an error dialog, not an `AIProposalPanel`. At **0.70–0.89**, the pair
lands in the `duplicate-candidates` queue as a genuinely suggest-only `DuplicateCandidateCard`, and a
`purchasing.vendor.merge` holder decides confirm (send to `VendorMergeDialog`) or dismiss — dismissing
requires a reason, stored back into the platform's `ai_memory` so a repeatedly-dismissed pairing pattern
can inform the matching algorithm's future weighting (a backend concern; this screen only supplies the
reason text).

**Fraud Detection.** Always suggest-only in the sense that it never blocks a transaction by itself — it
raises a flag that requires a `purchasing.vendor.risk.review` or `finance.review` holder to act on before
anything proceeds, the block (where one exists) being a workflow hold the flag triggers, not the AI
vetoing an API call directly. On this screen pair, a fraud flag renders as an urgent banner on the
affected vendor's profile header (visible from every tab) rather than only inside the Bank Accounts tab,
because a BEC-pattern flag (a bank-account change on an `active` vendor followed within 72 hours by an
unusually large bill/payment request) is exactly the kind of signal a user should see the moment they
open the vendor for any reason, not only when they happen to be looking at bank details:

| Confidence | Treatment |
|---|---|
| < 0.50 | Logged, visible only in a review queue; no active notification, no banner on this screen |
| 0.50–0.79 | Standard notification to Finance Manager/CFO/Auditor; a dismissible (not blocking) banner on the vendor profile |
| ≥ 0.80 | Urgent notification; a non-dismissible-without-reason banner, and the flagged bank account's `verification_status` is automatically set to `flagged`, blocking its use for payment until a human clears it via `BankAccountVerifyDialog`'s reject-then-recreate path |

**Supplier Performance.** Auto-computed (the score), suggest-only for any consequence (e.g., a
recommendation to move a vendor from "preferred" to under-review). A sample size below 3 completed
purchase orders is marked `insufficient_data: true` and the Transactions tab's performance link renders
"Not enough history yet" rather than a misleadingly precise score from too few data points — this screen
never backfills a synthetic score to fill the gap.

**Contract Analysis.** Document AI/OCR extracts, General Accountant structures, Auditor flags
compliance-relevant clauses — always suggest-only. The extraction proposal
(`{start_date, end_date, contract_value, currency_code, payment_milestones[], renewal_notice_days,
penalty_clauses[], confidence}`) attaches to the contract row as `ai_extraction_proposal`, and
`VendorContractsList` visually distinguishes any field whose per-field confidence falls below 0.85 (common
for a scanned/OCR'd document rather than a native-text PDF) for extra scrutiny before a
`purchasing.vendor.contracts.manage` holder confirms it — confirming is the action that makes the
extracted terms authoritative for the milestone-billing validation described in `docs/accounting/
VENDORS.md → Procurement Integration`; until confirmed, the contract's structured fields remain blank on
this screen even though the proposal is visible.

**Smart Suggestions.** Purely advisory, no data mutation, and largely consumed by *other* screens (the
Purchase Order vendor picker's autocomplete, a Bill's default-WHT-rate pre-fill) rather than rendered as
a standalone panel here — this document notes it for completeness since `docs/accounting/VENDORS.md`
describes it as part of this module's AI responsibilities, but the Vendors screen pair itself surfaces
only the tag-suggestion variant, as a lightweight inline hint inside the tag `Popover` described in
**Interactions & Flows**.

**The three-button pattern, and why "Do it" essentially never appears here.** `can_execute_directly` is a
server-computed field this screen never re-derives (`FRONTEND_ARCHITECTURE.md → The three-button
pattern`). For nearly everything on this screen pair, that field resolves to `false`:
`purchasing.vendor.blacklist`, `.unblacklist`, `.merge`, `.bank.verify`, and `.archive.force` are
permissions the AI Agent principal type can never structurally hold (`docs/accounting/VENDORS.md →
Permissions`, the AI Agent row), so a Duplicate Detection or Fraud Detection output whose resolution
requires one of those permissions never renders a "Do it" button at all — only "Send for approval" (or,
for a fraud flag, an acknowledgment-gated Approve inherited from the underlying `ApprovalCard`) and
"Dismiss." The Vendor Risk Score's own auto-compute step is the one place a number is written without a
human click, and even there the *number* is all that is auto-written — every consequence the number
triggers (an extra approval step, a blocking dialog on a critical-risk vendor) still requires a human
action this screen renders as an ordinary permission-gated control, never a silent auto-execution.

# States

No region on this screen pair ever falls through to a bare blank void or an unrecoverable crash; every
region ships its own loading, empty, and error presentation, and a failure in one never blocks another.

| Region | Loading | Empty | Error |
|---|---|---|---|
| KPI band (list) | Four `KpiTile`s in their own `loading` prop | Not applicable — a brand-new company still has four tiles, all reading zero | Independent per-tile failure shows that tile's own "Couldn't load — Retry," leaving the other three intact |
| Duplicate-candidates banner (list) | A skeleton chip matching `AI_COMMAND_CENTER.md`'s own AI-panel skeleton shape | Omitted entirely — an empty queue is good news and renders no banner at all, not a "nothing to review" message that would compete with genuinely useful list content | A distinct "AI insights temporarily unavailable" state, never a generic error or an infinite spinner |
| Vendors table | `DataTable`'s own 8-row skeleton | Two distinct empty copies: "No vendors yet — create your first vendor" (genuinely empty roster) vs. a lighter "No vendors match your filters" (filtered-to-zero) | `ErrorState` inline in place of the table body; header actions remain interactive |
| Profile header | Skeleton matching the header's exact shape (code, name, pills, actions) — never a generic spinner | Not applicable | Route-level `error.tsx` at `[vendorId]/` for a profile that fails to load at all (distinct from **Edge Cases**' "vendor in a different company" case, which renders `not-found`) |
| Overview: Risk Score panel | Skeleton card matching `VendorRiskPanel`'s shape | Not applicable — every vendor has a risk score once past `prospect`; a `prospect` vendor shows "Risk scoring begins after the vendor is submitted for approval" instead of an empty panel | Inline "Couldn't load risk score — Retry," independent of the rest of Overview |
| Overview: Approval workflow stepper | Skeleton pill row | Omitted entirely for a vendor whose workflow has never been submitted (`status='prospect'`, no `approval_workflow` rows yet) — the Overview tab instead shows a "Submit for approval" call-to-action card in that slot | Inline retry, independent of the Identity/Tax/Terms cards above it |
| Contacts / Addresses / Bank Accounts / Certificates & Contracts tabs | Skeleton rows matching each list's row shape | "No contacts yet — add one to reach approval" style copy per collection, each naming why the collection matters (e.g., bank accounts: "Add a bank account before this vendor can be paid") | Inline `ErrorState`, scoped to the failed tab only |
| AP Aging tab | `DataTable` skeleton | "No open items — this vendor's balance is fully settled," explicitly calm, not styled as an error | Inline retry |
| Statement tab | Skeleton rows for both merged sources | "No statement activity yet" | If either the bills or the payments call fails, the tab shows a partial statement with an inline note naming which half is missing, rather than blanking the whole tab |
| Transactions tab | `DataTable` skeleton | "No purchase orders yet for this vendor" | Inline retry |
| Audit Log tab | `DataTable` skeleton, virtualized above ~200 rows | "No changes recorded yet" (only possible for a vendor created and never edited) | Inline retry |

A route-level `error.tsx` at `purchasing/vendors/` remains the outermost safety net for a genuinely
unexpected failure (the session itself becoming invalid mid-render); per the three-granularity model this
should essentially never fire for an individual region's ordinary `4xx`/`5xx`, which are caught and
handled inline, one region at a time.

# Responsive Behavior

Vendors follows `RESPONSIVE_DESIGN.md`'s five semantic device tiers exactly — **Mobile** (unprefixed,
`sm:` at 640px), **Tablet** (`md:`, 768px), **Laptop** (`lg:`, 1024px), **Desktop** (`xl:`/`2xl:`,
1280px/1536px), **Ultra Wide** (`3xl:`, 1920px) — never a bespoke breakpoint of its own.

| Tier | Behavior |
|---|---|
| Mobile (<768px) | The KPI band becomes a horizontal-scroll, snap-aligned carousel, one `KpiTile` per screen-width, matching `RESPONSIVE_DESIGN.md → Dashboard Reflow`'s identical treatment. `VendorsTable` switches from a `<table>` to `RESPONSIVE_DESIGN.md → Pattern 1`'s card list — each row becomes a compact card (vendor name, code, status pill, risk badge, balance) with remaining fields reachable via the card's own tap-through to the profile, never crammed into a horizontally-scrolling table on a 375px screen. On the profile, the 9-tab sub-nav collapses into a `Select`-style tab switcher (a single dropdown showing the active tab name with a chevron) rather than a horizontally-scrolling tab strip, per `RESPONSIVE_DESIGN.md → Pattern 2`'s equivalent treatment for dense sub-navs. The persistent Sidebar is replaced by the bottom tab bar; "New vendor" is reachable from the tab bar's center "Create" sheet in addition to this screen's own header. |
| Tablet (768–1023px) | The KPI band becomes two tiles per row; `VendorsTable` gains its second priority column (vendor type, then risk) before fully graduating to a real table at Laptop; the profile's tab sub-nav becomes a horizontally-scrollable strip (no longer a dropdown) with a visible overflow-fade edge. |
| Laptop (1024–1279px) and up | The full layout in **Layout & Regions**' wireframes: four-tile KPI row, real `<table>` roster with every priority column visible, and the profile's full 9-tab strip with no horizontal scroll on standard 1024px+ widths. |
| Ultra Wide (≥1920px) | Content capped at `max-w-[1440px]` and centered, per `DESIGN_LANGUAGE.md → Spacing & Grid`; the AP Aging and Statement tabs, both dense tabular content, benefit from the extra breathing margin the same way `BANKING.md`'s transactions table does, without stretching edge-to-edge. |

**Touch targets and gestures.** Every interactive element — row-action triggers, tab-switcher targets,
Approve/Reject on `VendorApprovalStepper`'s `ApprovalCard`, the bulk-actions toolbar's checkboxes —
maintains the platform's 44×44px minimum hit area with an 8px minimum gap between adjacent controls
(`RESPONSIVE_DESIGN.md → Touch Targets & Gestures`), material here specifically because a mis-tap on
Blacklist or Archive carries real operational consequences. Approving a workflow step from a mobile
session additionally requires the same biometric re-confirmation layered on top of the ordinary
permission and confirmation-dialog checks that `BANKING.md`/`AI_COMMAND_CENTER.md` require for their own
mobile approvals — a Financial Approval step is exactly as sensitive as a bank transfer's second key.

**Virtualization.** Once the Audit Log tab's or the AP Aging tab's underlying result set exceeds roughly
200 rows, rows render through `@tanstack/react-virtual` rather than the plain DOM table, per
`RESPONSIVE_DESIGN.md → Virtualization at scale`, with the estimator reading the active density tier
exactly as `BANKING.md`'s transactions table does.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md` and the platform's RTL contract and applied,
concretely, to this screen pair's specific content — Vendors introduces no direction-handling code of
its own.

- **Logical properties only.** The roster's column order, the KPI band's reading order, the profile
  header's action-button order, the tab sub-nav's reading order, and every card's icon/label pairing use
  `ms-*`/`me-*`/`text-start`/`text-end` exclusively; flipping `dir="rtl"` on `<html>` mirrors the whole
  screen pair with zero screen-specific RTL code.
- **Numerals, tax IDs, IBANs, and dates never mirror.** Every `AmountCell` (balance due, credit limit,
  aging-bucket totals), every `VendorRiskBadge`'s score, and every IBAN/tax-registration-number fragment
  shown in a bank account row or a tooltip renders inside a `dir="ltr"`/`unicode-bidi: isolate` span,
  exactly as `AmountCell` and `BANKING.md`'s own IBAN handling already establish. A vendor's
  `commercial_registration_number` and `vat_registration_number` are treated with the same rule — both
  are long alphanumeric tokens that must read as one unbroken, correctly-ordered string even inside a
  fully Arabic sentence describing the vendor.
- **Numeric alignment is physically fixed, not logical (the platform's documented Exception A).** Every
  balance and every risk score in `VendorsTable`, `VendorAgingTable`, and `VendorStatementTable` uses
  hard-coded `text-right`, not `text-end`, in both directions, so a bilingual Finance team scanning
  magnitude lands on the same edge regardless of interface language.
- **Bilingual legal and display names.** `legal_name`/`name_ar` and `display_name` render per the active
  locale, with the API always returning both fields regardless of `Accept-Language`, per
  `COMPONENT_LIBRARY.md`'s bilingual-data convention — the client, never the server, owns which language
  displays, and the roster's search box matches against whichever field the active locale prefers first
  before falling back to the other.
- **AI-authored reasoning is never machine-translated by the frontend.** The Auditor's risk-factor
  labels, the Fraud Detection flag's stated reason, and the Contract Analysis extraction's field labels
  all arrive already localized from the API per its content-negotiation contract; this screen pair's own
  localization responsibility is limited to chrome — button labels, empty-state copy, dialog titles —
  never the model's own words.

| Context | English | Arabic |
|---|---|---|
| Page title | Vendors | الموردون |
| New vendor | New vendor | مورد جديد |
| Status: Prospect | Prospect | مرشح |
| Status: Blacklisted | Blacklisted | محظور |
| Risk level: High | High | مرتفع |
| Blacklist action | Blacklist | حظر |
| Archive guard | This vendor has an open balance | لدى هذا المورد رصيد مستحق |
| Bank verify | Verify bank account | التحقق من الحساب البنكي |
| Duplicate banner | 3 potential duplicate vendors detected | تم اكتشاف 3 موردين محتمل تكرارهم |
| AI provenance | Suggested by the Auditor Agent — 92% confidence | مقترح من وكيل المراجع — بثقة 92% |
| Empty roster | No vendors yet. Create your first vendor to start raising purchase orders. | لا يوجد موردون حتى الآن. أضف أول مورد لبدء إصدار أوامر الشراء. |

Arabic copy on this screen pair is authored directly by a fluent professional-register writer, not
machine-translated from the English strings above — a blacklist confirmation or a duplicate-detection
banner that sounds precise and calm in English must sound identically precise and calm in Arabic,
matching `DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief.

# Dark Mode

Vendors introduces no new color, elevation, or radius token — every surface on this screen pair resolves
through the same component-level tokens `StatusPill`, `AmountCell`, `KpiTile`, `ApprovalCard`,
`AIProposalPanel`, `Badge`, and `Card` already ship, verified per-component rather than assumed, exactly
as `BANKING.md`'s own Dark Mode section establishes for the identical shared components.

- **Cards get lighter, not darker, in dark mode.** `KpiTile`s, the profile's Identity/Tax/Terms cards,
  and `VendorRiskPanel` all sit a step lighter than the canvas behind them in dark mode, matching the
  platform's "physical light" strategy rather than a naive inversion.
- **`VendorRiskBadge`'s tone mapping is re-tuned per theme, not linearly brightened.** `success`/
  `neutral`/`warning`/`danger` each carry their own calibrated dark-mode value (per `COMPONENT_LIBRARY.md`
  → `Badge`'s `tone` variants), so a `critical`-risk vendor's badge reads as urgent in dark mode without
  over-saturating against the darker canvas.
- **The AI accent is reserved for provenance, never for status, on this screen exactly as on Banking.**
  A blacklisted vendor's `danger`-toned status pill and a high-confidence risk factor's contribution
  figure never borrow the accent color even though both are, loosely, "things that matter" — accent means
  "primary action" or "the AI produced this," and a fraud-hold banner's urgency is carried by `danger`,
  never by collapsing the two signals into one hue, per `DESIGN_LANGUAGE.md → Color & Ink`'s accent-is-
  AI's-signature rule.
- **Fraud-hold and duplicate-block treatments keep their light-mode danger/warning tone in dark mode**,
  recalibrated for contrast but never reassigned to a different semantic — an urgent banner stays visually
  urgent in both themes, distinguished only by its calibrated hex value, never by a structural color
  change that would make the same information read differently depending on theme.
- **Debit/credit-style figures stay in plain ink in dark mode exactly as in light mode.** `AmountCell`'s
  balance-due rendering is never washed in red/green regardless of theme — a vendor's outstanding balance
  is a magnitude, not a verdict, matching `DESIGN_LANGUAGE.md`'s debit/credit rule applied to AP.

Every Storybook story for `VendorsTable`, `VendorRiskPanel`, `VendorApprovalStepper`, and
`DuplicateCandidateCard` ships the platform's standard four-way parameter matrix (`light/LTR`,
`light/RTL`, `dark/LTR`, `dark/RTL`), and this screen pair's own Playwright suite captures the same
four-way screenshot set at the route level, so a token regression on Vendors specifically is caught in
CI rather than in a support ticket.

# Accessibility

Vendors targets the platform's WCAG 2.2 AA floor identically in both languages and both themes, with the
following screen-specific applications of the general rules `ACCESSIBILITY.md` already establishes.

- **Landmark and heading structure.** Each region without a visible heading of its own (the KPI band, the
  duplicate-candidates banner) still carries a real, `VisuallyHidden` `<h2>` via `aria-labelledby`, so a
  screen-reader user's landmark list enumerates the KPI band, the duplicate banner, and the roster as
  three distinct regions on the list screen, and each profile tab as its own labeled region, mirroring
  `ACCESSIBILITY.md`'s own worked example.
- **Live regions, calibrated to avoid noise.** A realtime `vendor.balance_updated` patch and a newly
  landed risk-score update both announce via `aria-live="polite"` — never `"assertive"` — so a fast-moving
  Reverb push never interrupts whatever a screen-reader user is currently reading. A `vendor.fraud_flag_
  raised` landing on a vendor the caller is authorized to act on is the one deliberate exception,
  announcing via the assertive tier (`role="alert"`) because it can block a task already in progress (an
  approver mid-review of that exact vendor's bank accounts), matching `ACCESSIBILITY.md`'s reservation of
  the assertive tier for "blocking errors that stop the current task" and `BANKING.md`'s identical
  precedent for its own fraud holds.
- **RBAC-aware disabled controls explain themselves, and are kept textually distinct per cause.** An
  Approve button on `VendorApprovalStepper` disabled because the caller lacks that step's specific
  permission carries `aria-describedby` naming the missing permission and the role that typically holds
  it (`ACCESSIBILITY.md`'s reused pattern); an Archive button disabled because of an open balance carries
  a different, specific `aria-describedby` text naming the exact blocking condition — never a generic
  "You can't do this right now."
- **Every AI-authored control explains itself before it can be acted on.** `VendorRiskPanel`'s
  reasoning list, `DuplicateCandidateCard`'s matching-signals list, and the fraud-flag banner's stated
  reason are all real text nodes, never a bare progress-bar width or an icon-only signal — a
  screen-reader user gets the identical "why" a sighted user reads visually.
- **The bank-account verification flow is fully operable without a mouse.** `BankAccountVerifyDialog`'s
  `verification_method` selection, its evidence-attachment control (a real `<input type="file">` behind
  any drag-and-drop affordance, never a drag-only interaction), and its Confirm button form a complete
  keyboard path, with focus trapped inside the dialog per the platform's standard Radix behavior and
  returned to the triggering row on close.
- **Data grid semantics on every tab's table.** `VendorsTable`, `VendorAgingTable`, `VendorStatementTable`,
  `VendorTransactionsTable`, and `VendorAuditLogTable` all render through real `<table>`/`role="grid"`
  semantics with `aria-sort` on sortable headers and `aria-busy` while a filter/sort/page change is in
  flight — `DataTable`'s own contract, reused unmodified across all five.
- **Forms.** `VendorForm` and every child-collection add/edit `Dialog` (contacts, addresses, bank
  accounts, certificates, contracts) label every field with a real `<label>` (never a placeholder-as-label,
  per `ACCESSIBILITY.md → Forms Accessibility`), map server `422` field errors back onto the same React
  Hook Form fields by name, and surface a top-of-form error summary when more than one field fails
  validation, exactly matching `JournalEntryForm`'s established accessible-forms pattern.
- **Keyboard path.** Tab order flows header actions → KPI band → duplicate banner (Dismiss/Review) → the
  roster's search/filter/sort/row-actions (list screen); profile header actions → tab switcher → the
  active tab's own content, in each tab's natural reading order → `VendorApprovalStepper`'s
  Approve/Reject/Delegate when a step is assigned to the caller. Every control is reachable and operable
  without a mouse, including the tab switcher's mobile `Select` variant.
- **Focus management.** Opening any `Dialog`/`Sheet` on this screen pair traps focus inside per the
  platform's standard Radix focus-trap behavior; closing returns focus to the control that opened it.
  Navigating from a roster row to its own profile resets focus to the destination page's own `<h1>` via
  the platform's standard route-change focus-reset, so this screen pair introduces no bespoke focus-trap
  logic of its own.

# Performance

- **Streamed, not blocking.** Per **Data & State**, every region on both the list and the profile is its
  own `Suspense` boundary; the slowest widget (typically the Risk Score panel, if an Auditor Agent
  recomputation is still in flight) never delays the Identity card or the roster's first paint, and a
  failure in one region never takes down another.
- **The vendor roster never over-fetches.** `DataTable`'s server-driven pagination/sort/filter model
  means `VendorsTable` never pulls more than one page (25 rows default) over the wire at a time,
  regardless of how many hundreds of vendors a holding company has accumulated; virtualization only
  becomes relevant on the Audit Log/AP Aging tabs once a single filtered result set itself exceeds
  ~200 rows.
- **Profile children arrive embedded, not as five extra round trips.** `GET /purchasing/vendors/{id}`
  returns `contacts[]`, `addresses[]`, `bank_accounts[]`, `certificates[]`, and `contracts[]` inline, so
  opening a vendor profile and switching between its Contacts/Addresses/Bank Accounts/Certificates &
  Contracts tabs costs one network request total on first load, not one per tab — only AP Aging,
  Statement, Transactions, and Audit Log issue their own independent calls, because those four are
  genuinely separate, potentially large datasets that do not belong in the profile's initial payload.
- **The synchronous duplicate check stays fast enough to not degrade vendor creation.** Per
  `docs/accounting/VENDORS.md → Duplicate Detection`, the backend's own synchronous check targets under
  150ms at p95; `VendorForm`'s submit button shows its `loading` state for that window and never
  optimistically navigates before the response — this is one of the few mutations on this screen pair
  where even a routine, non-duplicate create waits for the full round trip, because the possible `409`
  outcome must be handled before any navigation happens.
- **Realtime cost control.** This screen pair subscribes through the shell's single shared
  `RealtimeProvider` connection (mounted once, not per-vendor), so opening a vendor profile never opens a
  second WebSocket connection on top of whatever the rest of the shell already holds.
- **Bundle and Web Vitals.** The route's shell is held to the platform's stated first-load JS budget,
  enforced in CI against `performance-budgets.json`; LCP is measured against the roster's first page of
  rows (the list screen's actual "meaningful content") and the profile header's paint (the profile's
  equivalent), and INP is watched specifically on the lifecycle-action buttons and the bank-account
  verify flow, the two highest-stakes interactive surfaces on this screen pair.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A company switch fires while a region's fetch is still in flight | `queryClient.clear()` runs before `router.refresh()` per `FRONTEND_ARCHITECTURE.md → Company switching`; the in-flight response is discarded on arrival since its query key no longer exists in the cleared cache. |
| A user hand-types or bookmarks `/purchasing/vendors/{id}` for a vendor belonging to a different company | The API's own tenant-scoped `404` renders the shell's `not-found.tsx`, deliberately indistinguishable from "this vendor does not exist at all" per `FRONTEND_ARCHITECTURE.md → Dynamic segments, loading, and error conventions" — this screen never reveals that a vendor ID exists in someone else's company. |
| Creating a vendor returns a ≥0.90 duplicate match | `VendorForm`'s submit handler catches the `409 vendor.duplicate_high_confidence` and renders the hard-block dialog described in **Components Used**; the form's own field values are preserved (not cleared) so the user can either navigate to the existing vendor or edit and retry. |
| A vendor is blacklisted while it has open unpaid bills | The lifecycle dialog's confirm proceeds (blacklisting is never blocked by open balances, unlike archiving); the resulting `on_hold` flag on the vendor's open Purchase Orders is reflected the next time the Purchase Orders screen is viewed, not as a special modal here — this screen only shows the vendor's own `blacklisted` status and the reason/category on its profile. |
| Archiving is attempted on a vendor with a non-zero balance or in-flight POs, and the caller lacks `.force` | The dialog shows the guard message with no override affordance at all (not a disabled checkbox) — a caller who could never use the force option is not shown a control that would only ever read "disabled," per **Route & Access**'s "hidden, not disabled" rule for this specific case. |
| Two Finance Managers attempt to verify the same pending bank account in different tabs | The second submission fails with a conflict response (already `verified` or already `verification_failed`); this screen re-fetches the bank account row fresh rather than trusting stale local state, mirroring `BANKING.md`'s identical two-tab approval race handling. |
| A merge is attempted where one of the selected vendors has been merged/archived since the candidate was detected | The API's own validation rejects the merge with a clear message naming which vendor is no longer mergeable; `VendorMergeDialog` surfaces this inline rather than silently proceeding with stale candidate data. |
| A vendor's `vendor_type` is reclassified (e.g., `individual` → `company`) | The server-triggered re-validation job may return newly-missing-required-field warnings on the next profile fetch (e.g., a `company`-type vendor now needs a `commercial_registration_number`); the Overview tab surfaces these as a dismissible warning banner naming exactly which fields are now required, rather than silently reverting the vendor to a blocked state with no explanation. |
| A `contractor`-type vendor has a Purchase Order attempted against it with no linked, active contract | This is enforced server-side on the Purchase Order screen, not here — but the Vendors profile's Overview tab for a `contractor` vendor with zero `active`-status `vendor_contracts` rows shows a standing notice ("A contract is required before this vendor can receive a purchase order") so the condition is visible before a Purchasing user discovers it as a blocked PO elsewhere. |
| A `government` or `international` vendor's approval workflow is submitted | `VendorApprovalStepper` always renders the Compliance/Sanctions Screening step as mandatory for these two types regardless of any company "fast track" configuration, matching `docs/accounting/VENDORS.md → Business Rule 11` — this screen has no setting anywhere that can make that step optional for these types. |
| A vendor accumulates a negative `balance_due` (a debit note exceeding the open balance) | `has_credit_balance: true` renders as a small informational badge next to the balance in both the roster and the profile header, and the AP Aging tab's totals show the negative bucket explicitly rather than clamping it to zero. |
| A bilingual legal name is extremely long | Truncates with `truncate` and exposes the full string via `title`/`aria-label`, matching `RESPONSIVE_DESIGN.md`'s long-name precedent from `BANKING.md`'s `BankAccountCard`; balance and risk figures never shrink or wrap to accommodate a long name. |
| The Contract Analysis extraction job fails or times out | The contract row shows a distinct "Extraction failed — enter terms manually" state rather than an indefinite "Extracting…" spinner; a `purchasing.vendor.contracts.manage` holder can still fill the structured fields by hand, and the failed extraction attempt is visible (not silently discarded) for later retry. |
| WebSocket disconnected for an extended period, then reconnects | Every realtime-fed query key (`vendorKeys.*`) is invalidated once, as a single batch, on reconnect — a missed fraud flag or a missed bank verification during the outage must surface the moment connectivity returns, never wait for a manual refresh. |
| A user wants a printable or exported copy of a single vendor's full profile | Not supported as a literal "print this page" action, for the same reason `BANKING.md` and `DASHBOARD.md` both decline it for their own multi-region screens — a nine-tab profile with live AI provenance styling is not a defensible single-instant record. A user who needs a shareable artifact uses the roster's own CSV/XLSX Export (which includes `risk_score`/`balance_due`/etc. as plain columns) or the Vendor Aging/Vendor Risk reports in the Reports module, each with its own dedicated export path. |

# End of Document
