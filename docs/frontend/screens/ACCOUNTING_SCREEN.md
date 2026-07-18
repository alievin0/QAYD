# Accounting Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: ACCOUNTING_SCREEN
---

# Purpose

This document is the concrete, structured screen specification for the Accounting module's landing route,
`app/(app)/accounting/page.tsx` — the page an Owner, CEO, CFO, Finance Manager, Senior Accountant,
Accountant, or Auditor actually lands on the instant they click **Accounting** in the Sidebar. It is written
against the platform's `SCREEN DOC STRUCTURE` template so it can be read, reviewed, and diffed
section-by-section alongside every other screen document in this series — Dashboard, Accounting, General
Ledger, Journal Entries, Trial Balance, Balance Sheet, Profit & Loss, Cash Flow, Bank Reconciliation, Bank
Reconciliation, and the AI Command Center — using the identical fourteen headings. It is the concrete,
implementation-ready companion to `docs/frontend/ACCOUNTING.md`, which specifies this exact same route in
fuller prose, argues the information-architecture case for why Chart of Accounts and the module's own hub
are one screen rather than two, and reconciles that route against three earlier, superseded sketches
(`NAVIGATION_SYSTEM.md`'s `NAV_TREE`, an early `/accounting/accounts` sub-item route, and a top-level
`/financial-statements` path). This document does not re-argue any of that; the two must never disagree.
Where this document is silent on a fact, `docs/frontend/ACCOUNTING.md` governs first, then
`docs/accounting/CHART_OF_ACCOUNTS.md`, `FRONTEND_ARCHITECTURE.md`, `DESIGN_LANGUAGE.md`,
`COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `LAYOUT_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`,
and `ACCESSIBILITY.md`, in that order. Where this document appears to contradict one of them on a fact — a
route, a permission key, an endpoint path, a component name, a design token — that is a defect in one of the
two documents to raise and resolve in review, never a decision an engineer resolves unilaterally in code.

Every fact restated below is drawn from one of the documents named above and cited at first use. What this
document adds beyond `docs/frontend/ACCOUNTING.md` is the layer an engineer actually opens beside their
editor while building this route: the literal `page.tsx` / `layout.tsx` / `loading.tsx` source, the full
prop and Zod-schema contracts for the screen's own new composed components, worked API request/response
JSON an engineer can paste directly into a test fixture, a region-by-state matrix instead of prose, concrete
pixel/token values in place of a reference to "the shared token set," and the handful of implementation
decisions (optimistic-vs-pessimistic mutation strategy, exact skeleton geometry, exact keyboard bindings)
that `docs/frontend/ACCOUNTING.md` names as decisions but does not spell out to the last detail. It
introduces no new route, no new permission key, and no new design token that those documents have not
already defined.

Concretely, this screen composes seven pieces of surface, each owned elsewhere in the platform's data and
business-logic layers and only rendered, filtered, and routed here — never computed:

- **A persistent five-tab sub-nav** — Chart of Accounts (this route, active) · Journal Entries · General
  Ledger · Trial Balance · Financial Statements — rendered once by the shared `accounting/layout.tsx` and
  reused, unchanged, by every sibling Accounting screen.
- **A permission-gated Page Header** — the title "Accounting," a split-button primary action ("New Account"
  with "Apply Template" / "Enter Opening Balances" behind its caret), and a secondary Import/Export menu.
- **A four-tile Status Strip** — Total Accounts, Pending AI Suggestions, Pending Approvals, and Current
  Fiscal Period, the last of which carries its own AI-computed close-readiness score.
- **The Chart of Accounts region itself** — a virtualized, expandable `treegrid` over up to 5,000 accounts
  and 8 levels of depth, toggleable to a flat, sortable table better suited to bulk selection.
- **An AI & Approvals Rail** — the condensed Pending AI queue (duplicate/merge/missing-account suggestions)
  beside the cross-module Approvals queue for anything touching Accounting.
- **A row of four Module Nav Cards** — Journal Entries, General Ledger, Trial Balance, Financial Statements
  — each a real navigation carrying one live, cheaply-fetched stat.
- **A companion, non-owned platform AI Rail** — the `360px` shell-level panel that may dock inline at `xl`+,
  distinct from and never a duplicate of this page's own AI & Approvals Rail region.

This screen owns no business logic. It never computes a balance, never decides whether a duplicate-account
suggestion is confident enough to auto-apply, and never lets a confidence score substitute for a human's
click on a Reclassify or Merge dialog. Every figure rendered here was computed and persisted by Laravel;
every mutation this screen triggers calls `/api/v1/accounting/accounts...` (or a named sibling endpoint)
guarded by the exact permission key the API itself enforces — the frontend's own checks are a courtesy that
spares a user a confusing `403`, never the actual authority (`docs/frontend/ACCOUNTING.md`'s three inherited
constraints: the frontend computes nothing; AI is visible, labeled, and never silent; RBAC is enforced by
the API and the UI only reflects it).

# Route & Access

## App Router path

```text
app/(app)/accounting/
├── layout.tsx                       # Sub-nav tab bar; resolves the parent `accounting.read` gate once
├── page.tsx                         # ★ THIS DOCUMENT — the hub / Chart of Accounts screen
├── loading.tsx                      # Hub-shaped skeleton — see # States
├── journal-entries/…                # docs/frontend/JOURNAL_ENTRIES.md
├── ledger/…                         # docs/frontend/GENERAL_LEDGER.md
├── trial-balance/…                  # docs/frontend/TRIAL_BALANCE.md
├── financial-statements/
│   ├── balance-sheet/…              # docs/frontend/BALANCE_SHEET.md
│   ├── profit-and-loss/…            # docs/frontend/PROFIT_AND_LOSS.md
│   └── cash-flow/…                  # docs/frontend/CASH_FLOW.md
└── fiscal-periods/…                 # referenced by the Period Status tile; not specified in full here
```

Two redirects — reproduced verbatim from `docs/frontend/ACCOUNTING.md → Route tree`, never re-derived —
retire two earlier route sketches still visible elsewhere in this documentation tree:

```ts
// next.config.ts (excerpt)
async redirects() {
  return [
    { source: "/accounting/accounts", destination: "/accounting", permanent: true },
    { source: "/financial-statements/:statement*", destination: "/accounting/financial-statements/:statement*", permanent: true },
  ];
},
```

`NAVIGATION_SYSTEM.md`'s own `NAV_TREE` code sample and its module-map table both still list this screen's
`href` as `/accounting/accounts` — that is the pre-redirect sketch, not a second, live route; the redirect
above is what makes clicking that stale `href` land correctly on `/accounting`. An engineer wiring the
Sidebar should update `NAV_TREE`'s literal string to `/accounting` when next touching that file, but until
then the redirect keeps both the old and the current link working identically, which is why this is
recorded here as a reconciliation note rather than a blocking defect.

## Permission gate

| Control | Permission | Behavior if absent |
|---|---|---|
| Screen visible at all | `accounting.read` | Sidebar entry, tab bar, and route all absent; a direct hit renders the shell's `403` boundary, never a silent redirect. |
| Chart of Accounts tree/table, account detail sheet | `accounting.accounts.read` | The region renders `<ForbiddenState>` in place of the tree; the rest of the hub (Status Strip's Total Accounts tile excepted) still attempts to render for whatever the role's other keys allow. |
| "New Account," Quick Create sheet, "Apply Template" | `accounting.accounts.create` | Omitted from the Page Header — not disabled — a role-structural gap, not a plan-tier upsell. |
| Edit fields in the Account Detail Sheet | `accounting.accounts.update` | Sheet renders read-only; no Save action. |
| "Reclassify" row action | `accounting.accounts.reclassify` | Menu item omitted — a sensitive, historically-loaded action is never shown disabled with no explanation. |
| "Merge accounts" (row overflow or bulk bar) | `accounting.accounts.merge` | Menu item and bulk-bar action omitted. |
| "Delete" (row overflow, zero-history accounts only) | `accounting.accounts.delete` | Omitted; "Deactivate" (gated by `.update`) remains available. |
| "Enter opening balances" | `accounting.accounts.set_opening_balance` | Omitted from the Page Header's split-button caret. |
| "Import" | `accounting.accounts.import` | Omitted from the Page Header's secondary menu. |
| "Export" | `accounting.accounts.export` | Omitted from the same menu. |
| "Manage account types" (Type filter overflow) | `accounting.accounts.manage_types` | Link omitted. |
| Pending AI queue (view) | `accounting.accounts.review_ai_suggestions` | The rail's "Pending AI" half collapses entirely; the Approvals half still renders alone if `ai.approve`/`reports.read` are held. |
| Accept / reject a Pending AI suggestion | `accounting.accounts.update` (or the specific action's own key — `.reclassify` / `.merge`) | "Do it" / "Send for approval" buttons on that card are disabled with a permission tooltip; "Dismiss" remains available to anyone who can view the queue. |
| Approve / Reject on an `ApprovalCard` | `ai.approve` | Card renders read-only — amount, requester, confidence, no action row. |
| "Close this period" / "Reopen" (Period Status tile) | `accounting.period.lock` / `accounting.period.reopen` | Tile stays read-only; action renders disabled with a `Tooltip` naming the missing key — the one deliberate exception on this screen to "omit, don't disable," because a period's closeable state is not itself sensitive information, only the ability to act on it is. |
| Read-only note on a control-account row (why direct posting is blocked) | `accounting.journal.post_to_control_account` | Note text degrades to a plain explanation with no override link. |

## Roles

| Role | What renders on this screen |
|---|---|
| Owner, CEO / Admin | Everything — full tree with every row action, both split-button captions, Import/Export, the Period Status tile's Close/Reopen actions, every Pending AI action, every `ApprovalCard` action. |
| CFO, Finance Manager | Everything Owner/Admin sees; collapses to the backend's own "Owner"/"Admin" tier for `accounting.accounts.*` per `docs/accounting/CHART_OF_ACCOUNTS.md → 15.1`. |
| Senior Accountant, Accountant | Full read, create, update, import/export, set opening balances; Reclassify requires a secondary approval step (routes through the Approvals rail rather than committing directly); Merge and Delete are unavailable — collapses to the backend table's "Accountant" tier. |
| Auditor, External Auditor | Fully read-only across the tree, the Account Detail Sheet, and both rail halves; every mutating control is omitted, every figure, confidence badge, and reasoning disclosure still renders in full; Export remains available. |
| Read Only | The floor of this screen's surface — identical to Auditor for this module. |
| Sales / Purchasing / Inventory / Payroll self-service roles | Never see this screen at all — the Accounting Sidebar section itself is gated by the parent `accounting.read`, so the nav item, the tab bar, and this route do not exist for them (`docs/frontend/NAVIGATION_SYSTEM.md`'s existence-sensitive posture). |
| AI service account | Never renders this screen as a user; its scoped, read-plus-suggestion-producing access feeds the Pending AI queue in **AI Integration** below and is structurally incapable of holding `.create`, `.update`, `.reclassify`, `.merge`, or `.delete` regardless of any company automation policy (`docs/accounting/CHART_OF_ACCOUNTS.md → 15.1`'s legend). |

Keyboard entry: `G` then `A` opens this screen from anywhere, per `ACCESSIBILITY.md`'s "Go to" mnemonic
registry. `docs/frontend/ACCOUNTING.md → Route & Access` already flags that `docs/frontend/
JOURNAL_ENTRIES.md` documents the identical `G A` binding for its own route — a conflict this document
does not re-resolve; the binding stated here is authoritative for the Accounting module root, and the
registry itself is where the two are reconciled in code review.

# Layout & Regions

This screen instantiates `LAYOUT_SYSTEM.md`'s **Dashboard Template** — KPI Strip / main region / insights
rail / secondary bento row — scoped to one module, exactly as `docs/frontend/ACCOUNTING.md → Layout &
Regions` specifies. The desktop wireframe below is reproduced from that document for a single source of
truth on the pixel arrangement; the mobile and tablet wireframes are this document's own addition, since
`docs/frontend/ACCOUNTING.md` describes the responsive collapse in prose (`# Responsive Behavior`) without
drawing it.

## Desktop (`xl`+, ≥1280px)

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│ Chart of Accounts │ Journal Entries │ General Ledger │ Trial Balance │ Financial St. │  accounting/layout.tsx
├──────────────────────────────────────────────────────────────────────────────────────┤
│  Accounting                                          [Import ▾] [+ New Account ▾]     │  Page Header
├──────────────────────────────────────────────────────────────────────────────────────┤
│ [312 accounts] [4 pending AI suggestions] [7 pending approvals] [Jul 2026 · Open · 62%]│  Status Strip
├────────────────────────────────────────────────────────┬─────────────────────────────┤
│ [Search accounts…]  [Nature ▾] [Status ▾]  [▤ Tree|Flat]│  AI & Approvals              │
├────────────────────────────────────────────────────────┤  ── Pending AI (4) ────────── │
│ ▸ 1000 · Assets           …(virtualized, cursor-loaded   │  ▸ Merge 1101.002 → .001 92% │
│   subtrees on expand)…                                   │  ── Approvals (7) ─────────── │
│                                                          │  ▸ JE-2026-000512 · 850.000  │
│                                                          │  [ View all in Approvals → ] │
├────────────────────────────────────────────────────────┴─────────────────────────────┤
│  Journal Entries        │  General Ledger        │  Trial Balance     │ Fin. Statements│  Module Nav Cards
└──────────────────────────────────────────────────────────────────────────────────────┘
```

## Tablet (`md`–`lg`, 768–1279px)

Status Strip becomes a two-tiles-per-row grid at `md`, a full row at `lg`; the Chart of Accounts region and
the AI & Approvals Rail remain stacked, full width, tree above rail, until the `lg` 8/4 split activates;
Module Nav Cards form a `2×2` grid.

## Mobile (`base`–`sm`, <768px)

```
┌────────────────────────────────────┐
│ ‹ Chart of Accounts ▾ (tab strip,  │  scrollable, no wrap
│   scroll for Journal Entries…)     │
├────────────────────────────────────┤
│  Accounting            [+ New ▾]   │  Page Header — Import/Export folds
│                                     │  into the "⋯" overflow beside "+ New"
├────────────────────────────────────┤
│ [312 accounts] ›  (snap carousel,  │  one KpiTile per screen-width,
│  swipe for the other three tiles)  │  horizontal-scroll snap
├────────────────────────────────────┤
│ [Search accounts…]        [▤ ▾]    │
│ ▸ 1000 · Assets                    │  Chart of Accounts,
│   ▸ 1100 · Current Assets          │  full width, single column;
│     …in Current Assets ▸ Bank      │  indentation capped at 3 levels,
│       Accounts ▸ 1101.001  12,400  │  a breadcrumb prefix replaces
├────────────────────────────────────┤  further indentation past level 3
│  AI & Approvals            ›       │  stacks below the tree, full width
├────────────────────────────────────┤
│  Journal Entries            ›      │  Module Nav Cards stack
│  General Ledger              ›     │  one per row, full width
│  Trial Balance                ›    │
│  Financial Statements          ›   │
└────────────────────────────────────┘
```

## Region table with implementation-level grid classes

| Region | Container classes | Content | Notes |
|---|---|---|---|
| Sub-nav Tab Bar | rendered by `layout.tsx`, `sticky top-(--topbar-h) z-(--z-sticky)` | Five tabs, this route active | Not owned by `page.tsx`; every tab's own live-count badge reads the same endpoints the Module Nav Cards row reads, so the two never disagree |
| Page Header | `col-span-12`, `border-b border-ink-4 px-6 py-5` | Title, split-button primary, secondary menu | No record-count badge — Status Strip owns the count, since this is a hub, not a paginated list |
| Status Strip | `col-span-12 grid grid-cols-4 gap-4` at `lg`+; `flex overflow-x-auto snap-x snap-mandatory` below `md` | Four `KpiTile`s | See `# Responsive Behavior` for the carousel mechanics |
| Chart of Accounts region | `col-span-12 lg:col-span-8` | `ChartOfAccountsTree` \| flat `DataTable` toggle | Never mirrors under RTL except indentation direction |
| AI & Approvals Rail | `col-span-12 lg:col-span-4` | Up to 5 `AIProposalPanel` + up to 5 `ApprovalCard` | Scrolls independently (`max-h-[70vh] overflow-y-auto`) of the tree beside it |
| Module Nav Cards | `col-span-12 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4` | `ModuleNavCard` × 4 | Every card a real `<Link>`, never a modal target |

```tsx
// app/(app)/accounting/page.tsx
import { Suspense } from "react";
import { PageHeader } from "@/components/layout/page-header";
import { AccountingStatusStrip } from "@/components/accounting/accounting-status-strip";
import { ChartOfAccountsRegion } from "@/components/accounting/chart-of-accounts-region";
import { AccountingAiApprovalsRail } from "@/components/accounting/accounting-ai-approvals-rail";
import { ModuleNavCardsRow } from "@/components/accounting/module-nav-cards-row";
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";
import { NewAccountSplitButton } from "@/components/accounting/new-account-split-button";
import { AccountingSecondaryActionsMenu } from "@/components/accounting/accounting-secondary-actions-menu";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // tenant-scoped — never statically cached

export default function AccountingHubPage() {
  return (
    <div className="space-y-6">
      <PageHeader
        breadcrumb={[{ label: "Accounting", href: "/accounting" }]}
        title="Accounting"
        actions={
          <div className="flex items-center gap-2">
            <AccountingSecondaryActionsMenu />
            <NewAccountSplitButton />
          </div>
        }
      />
      <Suspense fallback={<WidgetSkeleton variant="kpi-strip" />}>
        <AccountingStatusStrip />
      </Suspense>
      <div className="grid grid-cols-12 gap-6">
        <Suspense fallback={<WidgetSkeleton variant="tree" className="col-span-12 lg:col-span-8" />}>
          <ChartOfAccountsRegion className="col-span-12 lg:col-span-8" />
        </Suspense>
        <Suspense fallback={<WidgetSkeleton variant="rail" className="col-span-12 lg:col-span-4" />}>
          <AccountingAiApprovalsRail className="col-span-12 lg:col-span-4" />
        </Suspense>
      </div>
      <Suspense fallback={<WidgetSkeleton variant="nav-cards" />}>
        <ModuleNavCardsRow />
      </Suspense>
    </div>
  );
}
```

```tsx
// app/(app)/accounting/loading.tsx
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";

export default function AccountingHubLoading() {
  return (
    <div className="space-y-6">
      <div className="border-b border-ink-4 px-6 py-5">
        <div className="h-4 w-24 animate-pulse rounded bg-ink-3" />
        <div className="mt-2 h-7 w-40 animate-pulse rounded bg-ink-3" />
      </div>
      <WidgetSkeleton variant="kpi-strip" />
      <div className="grid grid-cols-12 gap-6">
        <WidgetSkeleton variant="tree" className="col-span-12 lg:col-span-8" />
        <WidgetSkeleton variant="rail" className="col-span-12 lg:col-span-4" />
      </div>
      <WidgetSkeleton variant="nav-cards" />
    </div>
  );
}
```

```tsx
// app/(app)/accounting/layout.tsx
"use client";

import { usePathname } from "next/navigation";
import Link from "next/link";
import { cn } from "@/lib/utils";
import { Can } from "@/components/auth/can";

const TABS = [
  { href: "/accounting", labelKey: "nav.accounting.chartOfAccounts", permission: "accounting.accounts.read" },
  { href: "/accounting/journal-entries", labelKey: "nav.accounting.journalEntries", permission: "accounting.journal.read" },
  { href: "/accounting/ledger", labelKey: "nav.accounting.generalLedger", permission: "accounting.read" },
  { href: "/accounting/trial-balance", labelKey: "nav.accounting.trialBalance", permission: "accounting.trial_balance.read" },
  { href: "/accounting/financial-statements/balance-sheet", labelKey: "nav.accounting.financialStatements", permission: "accounting.financial-statements.read" },
] as const;

export default function AccountingLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  return (
    <Can permission="accounting.read" fallback={<ForbiddenShell />}>
      <div>
        <nav
          aria-label="Accounting sections"
          className="sticky top-(--topbar-h) z-(--z-sticky) flex gap-1 overflow-x-auto border-b border-ink-4 bg-surface px-4"
        >
          {TABS.map((tab) => (
            <Can key={tab.href} permission={tab.permission}>
              <Link
                href={tab.href}
                aria-current={pathname === tab.href ? "page" : undefined}
                className={cn(
                  "shrink-0 border-b-2 px-3 py-2.5 text-caption font-medium",
                  pathname === tab.href
                    ? "border-accent text-ink-12"
                    : "border-transparent text-ink-9 hover:text-ink-11",
                )}
              >
                {tab.labelKey}
              </Link>
            </Can>
          ))}
        </nav>
        {children}
      </div>
    </Can>
  );
}
```

`AccountingLayout` resolves the parent `accounting.read` gate exactly once for every child route
(`docs/frontend/ACCOUNTING.md → Access gate`); a role that fails it never reaches `ForbiddenShell`'s
sibling routes at all, since the Sidebar and Command Palette omit the entry entirely — `ForbiddenShell` only
ever renders for a direct URL hit by a session whose permissions changed after the link was bookmarked.

# Components Used

Every visual element on this screen is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue or is one of
the six Accounting-hub-scoped compositions `docs/frontend/ACCOUNTING.md → Components Used` already names
and files under `components/accounting/`. This section does not re-describe what those components are for
— it gives the concrete prop contracts and, for the four compositions that document only names without
showing source, the actual implementation.

| Component | Source | New in this document |
|---|---|---|
| `KpiTile`, `AmountCell`, `CurrencyTag`, `StatusPill`, `Badge`, `ConfidenceBadge`, `AIProposalPanel`, `ApprovalCard`, `AccountPicker`, `PeriodPicker`, `Tabs`, `Sheet`, `Dialog`/`AlertDialog`, `DropdownMenu`, `Command`, `Tooltip`, `Card`/`Button`/`Skeleton`, `EmptyState`/`ErrorState`, `Can`, `WidgetErrorBoundary` | `COMPONENT_LIBRARY.md` (existing) | No — reused verbatim, props unchanged |
| `ChartOfAccountsTree` | `components/accounting/chart-of-accounts-tree.tsx` — full source and props already in `docs/frontend/ACCOUNTING.md → ChartOfAccountsTree` | No — cited, not reproduced |
| `AccountingStatusStrip` | `components/accounting/accounting-status-strip.tsx` | **Yes** — full source below |
| `ModuleNavCard` / `ModuleNavCardsRow` | `components/accounting/module-nav-card.tsx` | **Yes** — full source below |
| `AccountingAiApprovalsRail` | `components/accounting/accounting-ai-approvals-rail.tsx` | **Yes** — full source below |
| `AccountDetailSheet` | `components/accounting/account-detail-sheet.tsx` | **Yes** — prop contract below (body reuses `Tabs`/`AmountCell`/`StatusPill` per `docs/frontend/ACCOUNTING.md → Interactions & Flows`) |
| `QuickCreateAccountSheet` | `components/accounting/quick-create-account-sheet.tsx` | **Yes** — full source below, wraps the `AccountSchema` `docs/frontend/ACCOUNTING.md` already defines |
| `TreeSkeleton` | `components/accounting/tree-skeleton.tsx` | **Yes** — full source below |

## `AccountingStatusStrip`

Composes the four `KpiTile`s named in `docs/frontend/ACCOUNTING.md → Layout & Regions`. The first two tiles
are plain ledger facts and carry no `ConfidenceBadge`; the fourth — Current Fiscal Period — is the one tile
on this strip whose headline figure (the close-readiness percentage) is AI-computed, and it is the only one
of the four with a `confidence` prop populated, per `# AI Integration` below.

```tsx
// components/accounting/accounting-status-strip.tsx
"use client";

import { useQuery } from "@tanstack/react-query";
import { KpiTile } from "@/components/dashboard/kpi-tile";
import { accountingHubKeys } from "@/lib/query/keys";
import { fetchAccountingStatusStrip } from "@/lib/api/accounting";
import { useRouter } from "next/navigation";

export function AccountingStatusStrip() {
  const router = useRouter();
  const { data, isPending } = useQuery({
    queryKey: accountingHubKeys.statusStrip(),
    queryFn: fetchAccountingStatusStrip,
    staleTime: 0, // live/derived figures — corrected via realtime, not polling
  });

  return (
    <div className="grid grid-cols-1 gap-4 overflow-x-auto sm:flex sm:snap-x sm:snap-mandatory lg:grid lg:grid-cols-4 lg:overflow-visible">
      <KpiTile
        label="Total accounts"
        value={data?.totalAccounts ?? "0"}
        format="number"
        loading={isPending}
        onClick={() => router.push("/accounting?shape=flat")}
        className="snap-start"
      />
      <KpiTile
        label="Pending AI suggestions"
        value={data?.pendingAiSuggestions ?? "0"}
        format="number"
        loading={isPending}
        onClick={() => document.getElementById("accounting-ai-rail")?.scrollIntoView({ behavior: "smooth" })}
        className="snap-start"
      />
      <KpiTile
        label="Pending approvals"
        value={data?.pendingApprovals ?? "0"}
        format="number"
        loading={isPending}
        onClick={() => router.push("/approvals?filter[subject_type][in]=journal_entry,account_reclassification,account_merge")}
        className="snap-start"
      />
      <PeriodStatusTile fiscalPeriod={data?.currentFiscalPeriod} loading={isPending} className="snap-start" />
    </div>
  );
}
```

`PeriodStatusTile` is a thin, screen-specific wrapper around `KpiTile` — not a seventh named component,
since it is a single, non-reusable composition — that renders the close-readiness figure with its mandatory
`ConfidenceBadge` and opens `PeriodPicker` in `mode="fiscal_period"` as an expanded popover on click, exactly
as `docs/frontend/ACCOUNTING.md → Layout & Regions` names it: "the Period Status tile's expanded popover."

```tsx
// components/accounting/period-status-tile.tsx
"use client";

import { useState } from "react";
import { Popover, PopoverTrigger, PopoverContent } from "@/components/ui/popover";
import { KpiTile } from "@/components/dashboard/kpi-tile";
import { ConfidenceBadge } from "@/components/ai/confidence-badge";
import { Button } from "@/components/ui/button";
import { Can } from "@/components/auth/can";
import type { FiscalPeriodStatus } from "@/types/accounting";

export function PeriodStatusTile({
  fiscalPeriod, loading, className,
}: { fiscalPeriod?: FiscalPeriodStatus; loading: boolean; className?: string }) {
  const [open, setOpen] = useState(false);
  if (loading || !fiscalPeriod) return <KpiTile label="Current fiscal period" value="—" format="number" loading={loading} className={className} />;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <KpiTile
          label="Current fiscal period"
          value={`${fiscalPeriod.label} · ${fiscalPeriod.status} · ${fiscalPeriod.closeReadiness.score}%`}
          format="number"
          confidence={fiscalPeriod.closeReadiness.confidence}
          className={className}
        />
      </PopoverTrigger>
      <PopoverContent className="w-80 space-y-3" align="end">
        <div className="flex items-center justify-between">
          <p className="font-medium text-ink-12">{fiscalPeriod.label} close readiness</p>
          <ConfidenceBadge confidence={fiscalPeriod.closeReadiness.confidence} size="sm" reasoning={fiscalPeriod.closeReadiness.reasoning} />
        </div>
        <ul className="space-y-1.5 text-caption text-ink-9">
          {fiscalPeriod.closeReadiness.blockingItems.map((item) => (
            <li key={item.type}>
              <a href={item.href} className="hover:text-accent hover:underline">
                {item.count} × {item.type.replace(/_/g, " ")}
              </a>
            </li>
          ))}
        </ul>
        <div className="flex justify-end gap-2">
          <Can permission="accounting.period.lock">
            <Button size="sm" disabled={fiscalPeriod.closeReadiness.score < 100}>Close this period</Button>
          </Can>
          <Can permission="accounting.period.reopen">
            {fiscalPeriod.status === "closed" && <Button size="sm" variant="outline">Reopen</Button>}
          </Can>
        </div>
      </PopoverContent>
    </Popover>
  );
}
```

## `ModuleNavCard` / `ModuleNavCardsRow`

```tsx
// components/accounting/module-nav-card.tsx
import Link from "next/link";
import { Card } from "@/components/ui/card";
import { ArrowUpRight } from "lucide-react";

export function ModuleNavCard({
  title, stat, href,
}: { title: string; stat: string; href: string }) {
  return (
    <Link href={href} className="block">
      <Card interactive padding="md" className="h-full space-y-1">
        <div className="flex items-center justify-between">
          <p className="font-medium text-ink-12">{title}</p>
          <ArrowUpRight className="h-4 w-4 text-ink-8" aria-hidden />
        </div>
        <p className="text-caption text-ink-9">{stat}</p>
      </Card>
    </Link>
  );
}
```

```tsx
// components/accounting/module-nav-cards-row.tsx
"use client";

import { useQuery } from "@tanstack/react-query";
import { ModuleNavCard } from "@/components/accounting/module-nav-card";
import { Can } from "@/components/auth/can";
import { accountingHubKeys } from "@/lib/query/keys";
import { fetchModuleNavStats } from "@/lib/api/accounting";

export function ModuleNavCardsRow() {
  const { data } = useQuery({ queryKey: accountingHubKeys.moduleNavStats(), queryFn: fetchModuleNavStats, staleTime: 30_000 });

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
      <Can permission="accounting.journal.read">
        <ModuleNavCard title="Journal Entries" stat={`${data?.journalEntries.drafts ?? 0} drafts · ${data?.journalEntries.pending ?? 0} pending`} href="/accounting/journal-entries" />
      </Can>
      <Can permission="accounting.ledger.read">
        <ModuleNavCard title="General Ledger" stat={data?.ledger.lastPostedRelative ?? "No postings yet"} href="/accounting/ledger" />
      </Can>
      <Can permission="accounting.trial_balance.read">
        <ModuleNavCard title="Trial Balance" stat={`${data?.trialBalance.period ?? "—"} · ${data?.trialBalance.status ?? "Not generated"}`} href="/accounting/trial-balance" />
      </Can>
      <Can permission="accounting.financial-statements.read">
        <ModuleNavCard title="Financial Statements" stat={data?.financialStatements.lastGeneratedRelative ?? "None generated yet"} href="/accounting/financial-statements/balance-sheet" />
      </Can>
    </div>
  );
}
```

Each card omitted by `Can` shrinks the grid rather than leaving a gap, per `docs/frontend/ACCOUNTING.md →
States`'s "never truly empty — a card whose sibling module the viewer cannot read at all is omitted"
rule; the grid's `md:grid-cols-2` still reads correctly at three visible cards because CSS Grid simply wraps
the remaining card, not because this component special-cases the count.

## `AccountingAiApprovalsRail`

```tsx
// components/accounting/accounting-ai-approvals-rail.tsx
"use client";

import { useQuery } from "@tanstack/react-query";
import { AIProposalPanel } from "@/components/ai/ai-proposal-panel";
import { ApprovalCard } from "@/components/shared/approval-card";
import { EmptyState } from "@/components/shared/empty-state";
import { Can } from "@/components/auth/can";
import { accountingKeys } from "@/lib/query/keys";
import { fetchAccountAiSuggestions } from "@/lib/api/accounting";
import { fetchAccountingApprovals } from "@/lib/api/approvals";
import { useAcceptAiSuggestion, useRejectAiSuggestion } from "@/hooks/accounting/use-account-ai-suggestions";
import { useApproveApproval, useRejectApproval } from "@/hooks/approvals/use-approval-mutations";

export function AccountingAiApprovalsRail({ className }: { className?: string }) {
  const suggestions = useQuery({
    queryKey: accountingKeys.aiSuggestions(),
    queryFn: fetchAccountAiSuggestions,
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
  const approvals = useQuery({
    queryKey: ["approvals", "accounting"],
    queryFn: () => fetchAccountingApprovals({ limit: 5 }),
    staleTime: 10_000,
    refetchOnWindowFocus: true,
  });
  const accept = useAcceptAiSuggestion();
  const reject = useRejectAiSuggestion();
  const approve = useApproveApproval();
  const rejectApproval = useRejectApproval();

  const nothingPending = (suggestions.data?.length ?? 0) === 0 && (approvals.data?.length ?? 0) === 0;

  return (
    <div id="accounting-ai-rail" className={className}>
      <Can permission="accounting.accounts.review_ai_suggestions">
        <section aria-labelledby="pending-ai-heading" className="mb-4 max-h-[70vh] space-y-3 overflow-y-auto">
          <h2 id="pending-ai-heading" className="text-caption font-medium text-ink-9">Pending AI ({suggestions.data?.length ?? 0})</h2>
          {suggestions.data?.slice(0, 5).map((s) => (
            <AIProposalPanel
              key={s.id}
              decision={s}
              autonomyLevel={s.autonomyLevel}
              onAccept={() => accept.mutateAsync(s.id)}
              onSendForApproval={() => accept.mutateAsync(s.id, { sendForApproval: true })}
              onDismiss={(reason) => reject.mutateAsync({ id: s.id, reason })}
            />
          ))}
        </section>
      </Can>
      <section aria-labelledby="approvals-heading" className="space-y-3">
        <h2 id="approvals-heading" className="text-caption font-medium text-ink-9">Approvals ({approvals.data?.length ?? 0})</h2>
        {approvals.data?.slice(0, 5).map((a) => (
          <ApprovalCard
            key={a.id}
            kind={a.kind}
            title={a.title}
            amount={a.amount}
            requestedBy={a.requestedBy}
            requestedAt={a.requestedAt}
            confidence={a.confidence}
            detailHref={a.detailHref}
            onApprove={() => approve.mutateAsync(a.id)}
            onReject={(reason) => rejectApproval.mutateAsync({ id: a.id, reason })}
          />
        ))}
        <a href="/approvals" className="block text-caption text-accent hover:underline">View all in Approvals →</a>
      </section>
      {nothingPending && (
        <EmptyState
          title="You're all caught up"
          description="No pending suggestions or approvals right now."
          tone="calm"
        />
      )}
    </div>
  );
}
```

`ApprovalCard`'s `kind` union gains two Accounting-scoped values this screen is the first to need —
`account_reclassification` and `account_merge` — mapped to `accounting.accounts.reclassify` and
`accounting.accounts.merge` respectively in that component's own `PERMISSION_BY_KIND` lookup
(`docs/frontend/ACCOUNTING.md → Components Used`); this file does not redefine that lookup, it consumes it.

## `QuickCreateAccountSheet`

Wraps the `AccountSchema` Zod schema `docs/frontend/ACCOUNTING.md → Interactions & Flows` already defines
(`lib/validation/account.ts` — reused verbatim, not reproduced here) in a React Hook Form + `Sheet` shell,
with the inline duplicate-detection note wired to the same debounced watcher on `name_en`/`name_ar`:

```tsx
// components/accounting/quick-create-account-sheet.tsx
"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetFooter } from "@/components/ui/sheet";
import { Form, FormField, FormItem, FormLabel, FormControl, FormMessage } from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import { Button } from "@/components/ui/button";
import { AccountPicker } from "@/components/accounting/account-picker";
import { useCreateAccount } from "@/hooks/accounting/use-account-mutations";
import { useDuplicateAccountCheck } from "@/hooks/accounting/use-duplicate-account-check";
import { AccountSchema, type AccountInput } from "@/lib/validation/account";
import { useApiToast } from "@/hooks/use-api-toast";

export function QuickCreateAccountSheet({ open, onOpenChange, defaultParentId }: {
  open: boolean; onOpenChange: (v: boolean) => void; defaultParentId: number | null;
}) {
  const form = useForm<AccountInput>({
    resolver: zodResolver(AccountSchema),
    defaultValues: { parent_id: defaultParentId, allow_posting: true, tax_category: "none", tags: [] },
  });
  const nameEn = form.watch("name_en");
  const nameAr = form.watch("name_ar");
  const duplicate = useDuplicateAccountCheck(nameEn, nameAr); // debounced 400ms, non-blocking
  const create = useCreateAccount();
  const toast = useApiToast();

  async function onSubmit(values: AccountInput) {
    try {
      await create.mutateAsync(values);
      toast.success("Account created");
      onOpenChange(false);
      form.reset();
    } catch (e) {
      toast.mapFieldErrors(e, form.setError); // 422 errors[] mapped back onto Zod-shaped fields
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="end" className="w-full sm:max-w-md">
        <SheetHeader><SheetTitle>New Account</SheetTitle></SheetHeader>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4 py-4">
            <FormField control={form.control} name="parent_id" render={({ field }) => (
              <FormItem>
                <FormLabel>Parent account</FormLabel>
                <FormControl><AccountPicker value={field.value} onChange={field.onChange} constrainNature /></FormControl>
                <FormMessage />
              </FormItem>
            )} />
            {duplicate.match && (
              <div className="rounded-md border border-s-2 border-s-accent bg-accent-subtle/40 p-3 text-caption">
                <p className="font-medium text-ink-12">Possible duplicate — {Math.round(duplicate.match.similarity * 100)}% match</p>
                <p className="text-ink-9">{duplicate.match.code} · {duplicate.match.nameEn}</p>
              </div>
            )}
            <FormField control={form.control} name="name_en" render={({ field }) => (
              <FormItem><FormLabel>Name (English)</FormLabel><FormControl><Input {...field} /></FormControl><FormMessage /></FormItem>
            )} />
            <FormField control={form.control} name="name_ar" render={({ field }) => (
              <FormItem><FormLabel>Name (Arabic)</FormLabel><FormControl><Input dir="rtl" {...field} /></FormControl><FormMessage /></FormItem>
            )} />
            <FormField control={form.control} name="description" render={({ field }) => (
              <FormItem><FormLabel>Description (optional)</FormLabel><FormControl><Textarea {...field} /></FormControl><FormMessage /></FormItem>
            )} />
            <FormField control={form.control} name="allow_posting" render={({ field }) => (
              <FormItem className="flex items-center justify-between">
                <FormLabel>Allow direct posting</FormLabel>
                <FormControl><Switch checked={field.value} onCheckedChange={field.onChange} /></FormControl>
              </FormItem>
            )} />
            <SheetFooter>
              <Button type="submit" disabled={create.isPending}>Create account</Button>
            </SheetFooter>
          </form>
        </Form>
      </SheetContent>
    </Sheet>
  );
}
```

## `AccountDetailSheet` (prop contract)

| Prop | Type | Description |
|---|---|---|
| `accountId` | `number \| null` | `null` closes the sheet |
| `onOpenChange` | `(open: boolean) => void` | |
| `defaultTab` | `'overview' \| 'ledger' \| 'history'` | Default `'overview'` |

Body composition (no new primitives): `Tabs` for the three-way split named in `docs/frontend/ACCOUNTING.md →
Interactions & Flows`; `AmountCell` for the balance; a conditional inline caption reading "AI suggested: 6400
· Bad Debt Expense" when `ai_suggested_type_id` is set and differs from the confirmed type; `Tabs.Content`
for Ledger reads `useAccountLedger(id)`; History reads `useAccountAuditHistory(id)`. Edit / Reclassify /
Deactivate / Merge / Delete live in the sheet's own header action row and overflow, each wrapped in `Can`.

## `TreeSkeleton`

```tsx
// components/accounting/tree-skeleton.tsx
import { Skeleton } from "@/components/ui/skeleton";

const ROW_DEPTHS = [0, 1, 2, 1, 2, 2, 0, 1, 0, 1, 2, 2] as const; // matches a typical first-viewport shape

export function TreeSkeleton() {
  return (
    <div className="rounded-lg border border-ink-6" aria-hidden>
      {ROW_DEPTHS.map((depth, i) => (
        <div key={i} className="flex h-8 items-center gap-2 border-t border-ink-6 first:border-t-0" style={{ paddingInlineStart: `${depth * 20 + 8}px` }}>
          <Skeleton className="h-3.5 w-3.5 shrink-0 rounded-sm" />
          <Skeleton className="h-3 w-40" style={{ animationDelay: `${i * 40}ms` }} />
          <Skeleton className="ms-auto h-3 w-16" style={{ animationDelay: `${i * 40}ms` }} />
        </div>
      ))}
    </div>
  );
}
```

The staggered `animationDelay` produces the platform's standard 1.6s shimmer sweep
(`DESIGN_LANGUAGE.md → Motion → Named patterns`) reading as a single wave down the skeleton rather than
twelve rows pulsing in lockstep — the same visual language `docs/frontend/ACCOUNTING.md → States` names for
this exact component without specifying the stagger mechanic.

# Data & State

## Endpoints this screen calls

All under `/api/v1/accounting/accounts` (plus the sibling `/api/v1/accounting/account-types`), Bearer +
`X-Company-Id`, standard response envelope, exactly as `docs/accounting/CHART_OF_ACCOUNTS.md → 14 API
Design` defines and `docs/frontend/ACCOUNTING.md → Endpoints` already maps to hooks. Reproduced here in
condensed form with only the calls this exact page issues on first paint or from a control it renders:

| Purpose | Endpoint | Permission | First-paint or on-demand |
|---|---|---|---|
| Tree/flat listing | `GET /accounting/accounts?shape=tree\|flat&status=&nature=&search=` | `.read` | First paint |
| Status Strip bundle | `GET /accounting/accounts/summary` (Total Accounts, Pending AI count, Pending Approvals count) | `.read` | First paint |
| Current fiscal period + close-readiness | `GET /accounting/fiscal-periods?filter[status][in]=open,closing&sort=-end_date&per_page=1` then `GET /accounting/fiscal-periods/{id}/close-readiness` | `.read` | First paint |
| Sibling-module nav stats | `GET .../journal-entries?…&per_page=1`, `.../ledger-entries?…&per_page=1`, `.../trial-balance?…&per_page=1`, `.../financial-statements/snapshots?…&per_page=1` | each module's own `.read` | First paint (Module Nav Cards) |
| Pending AI suggestions | `GET /accounting/accounts/ai-suggestions` | `.review_ai_suggestions` | First paint (rail) |
| Cross-module approvals | `GET /api/v1/approvals?filter[subject_type][in]=journal_entry,account_reclassification,account_merge,account_opening_balance&status=pending` | `ai.approve` / `reports.read` | First paint (rail) |
| Direct children (lazy expand) | `GET /accounting/accounts/{id}/children` | `.read` | On-demand |
| One account, with balance | `GET /accounting/accounts/{id}` | `.read` | On-demand (Detail Sheet open) |
| Ledger tab | `GET /accounting/accounts/{id}/ledger` | `.read` | On-demand |
| History tab | `GET /accounting/accounts/{id}/audit-history` | `.read` | On-demand |
| Create | `POST /accounting/accounts` | `.create` | Quick Create submit |
| Reclassify | `POST /accounting/accounts/{id}/reclassify` | `.reclassify` | Reclassify dialog submit |
| Merge | `POST /accounting/accounts/merge` | `.merge` | Merge dialog submit |
| Deactivate / Reactivate | `POST /accounting/accounts/{id}/deactivate` \| `/reactivate` | `.update` | Row action |
| Delete | `DELETE /accounting/accounts/{id}` | `.delete` | Row action |
| Accept / Reject AI suggestion | `POST /accounting/accounts/ai-suggestions/{id}/accept` \| `/reject` | `.update` (or the specific action's key) | Rail card action |
| Approve / Reject approval | `POST /api/v1/approvals/{id}/approve` \| `/reject` | `ai.approve` | Rail card action |
| Apply template | `POST /accounting/accounts/apply-template` | `.create` | Apply Template flow |
| Import | `POST /accounting/accounts/import` | `.import` | Import entry point (hands off to its own screen) |
| Export | `GET /accounting/accounts/export` | `.export` | Export menu item, async job |

`docs/frontend/ACCOUNTING.md → Endpoints` documents the full 22-row table including `/subtree`,
`/opening-balances`, `/account-types`, and the templates endpoints this exact page's first paint does not
call directly (they are reached from the Detail Sheet, the Opening Balances quick action, and the Apply
Template flow respectively) — this table is the strict subset `page.tsx` and its immediate children issue.

## Worked request/response examples

Reproduced from `docs/accounting/CHART_OF_ACCOUNTS.md → 14.2` for the two mutations this screen's own Quick
Create and row-action surfaces trigger most often, so an engineer wiring `useCreateAccount` has a real
fixture on hand without switching documents:

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

```json
// 201 Created
{
  "success": true,
  "data": {
    "id": 55231, "company_id": 4821, "code": "1101.003",
    "name_en": "Bank Accounts – NBK Current", "name_ar": "حسابات بنكية – البنك الوطني الجاري",
    "account_type_id": 118, "account_type": { "code": "bank", "name_en": "Bank", "name_ar": "بنك" },
    "nature": "asset", "normal_balance": "debit", "parent_id": 1042, "depth": 3,
    "path": "1.11.1101.55231", "allow_posting": true, "is_control_account": false, "is_system": false,
    "currency_code": "KWD", "opening_balance": "0.0000", "status": "active", "tax_category": "none",
    "tags": ["operational-bank"], "created_by": 902,
    "created_at": "2026-07-16T09:12:41Z", "updated_at": "2026-07-16T09:12:41Z"
  },
  "message": "Account created successfully", "errors": [], "meta": { "pagination": null },
  "request_id": "8f2c1e40-2a11-4d3a-9c77-1b6e4d8a9f01", "timestamp": "2026-07-16T09:12:41Z"
}
```

```json
// 422 Unprocessable Entity — mapped by useApiToast.mapFieldErrors onto the Quick Create form's own fields
{
  "success": false, "data": null, "message": "The given data was invalid.",
  "errors": [
    { "field": "parent_id", "code": "PARENT_NATURE_MISMATCH",
      "message": "Child account nature (asset) must match parent account nature (liability)." },
    { "field": "name_ar", "code": "NAME_REQUIRED", "message": "The Arabic name field is required." }
  ],
  "meta": { "pagination": null }, "request_id": "3a71c9d2-88fd-4e2b-9d4a-6c112a55e6f4",
  "timestamp": "2026-07-16T09:13:02Z"
}
```

```json
POST /api/v1/accounting/accounts/55231/reclassify
{ "new_account_type_id": 121, "reason": "This account was originally typed as a generic Current Asset; it is a Bank account and should follow bank reconciliation workflows." }
```

```json
{
  "success": true,
  "data": { "id": 55231, "account_type_id": 121, "previous_account_type_id": 118, "normal_balance": "debit",
             "affected_journal_lines": 342, "affected_amount_base_currency": "184230.5000" },
  "message": "Account reclassified successfully", "errors": [], "meta": { "pagination": null },
  "request_id": "9b5e21aa-4f88-49c1-8b39-2d114cf0a220", "timestamp": "2026-07-16T09:16:44Z"
}
```

```json
// GET /api/v1/accounting/fiscal-periods/9142/close-readiness
{
  "success": true,
  "data": {
    "score": 62, "confidence": 0.88,
    "reasoning": "3 draft journal entries dated inside this period, 1 unresolved anomaly finding, and no Trial Balance snapshot generated yet for July 2026.",
    "blocking_items": [
      { "type": "draft_journal_entries", "count": 3, "href": "/accounting/journal-entries?filter[status]=draft&filter[period]=2026-07" },
      { "type": "unresolved_anomaly_findings", "count": 1, "href": "/accounting/trial-balance?fiscal_period_id=9142" },
      { "type": "trial_balance_not_generated", "count": 1, "href": "/accounting/trial-balance?fiscal_period_id=9142" }
    ]
  },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "c4a91d7e-2b33-4f0a-9e51-7d8823e0aab1", "timestamp": "2026-07-16T09:20:11Z"
}
```

This close-readiness payload is `docs/frontend/ACCOUNTING.md → Endpoints`'s own addition to the contract
`docs/accounting/GENERAL_LEDGER.md → Business Goal 3` states but had not yet wired end to end; the shape
above (`score`, `confidence`, `reasoning`, `blocking_items[]`) is reproduced verbatim from that document so
`PeriodStatusTile` above has an exact fixture to render against.

## Query keys

```ts
// lib/query/keys.ts (accounting-hub-scoped factories, matching docs/frontend/ACCOUNTING.md exactly)
export const accountingKeys = {
  all: ["accounting"] as const,
  tree: (filters: AccountTreeFilters) => [...accountingKeys.all, "tree", filters] as const,
  detail: (id: number) => [...accountingKeys.all, "account", id] as const,
  ledger: (id: number) => [...accountingKeys.detail(id), "ledger"] as const,
  auditHistory: (id: number) => [...accountingKeys.detail(id), "audit-history"] as const,
  aiSuggestions: () => [...accountingKeys.all, "ai-suggestions"] as const,
};

export const accountingHubKeys = {
  statusStrip: () => ["accounting-hub", "status-strip"] as const,
  currentPeriod: () => ["accounting-hub", "current-period"] as const,
  closeReadiness: (fiscalPeriodId: number) => ["accounting-hub", "close-readiness", fiscalPeriodId] as const,
  moduleNavStats: () => ["accounting-hub", "module-nav-stats"] as const,
};
```

## Cache tuning (unchanged from `docs/frontend/ACCOUNTING.md → Cache tuning`, restated for this file's own hooks)

| Data class | Resources | `staleTime` |
|---|---|---|
| Structural/reference | `accountingKeys.tree` | `30_000` |
| Live/derived figures | `accountingHubKeys.statusStrip` (Total Accounts, Pending Approvals) | `0` |
| AI feeds | `accountingKeys.aiSuggestions`, `accountingHubKeys.closeReadiness` | `10_000`, `refetchOnWindowFocus: true` |
| Sibling-module nav stats | `accountingHubKeys.moduleNavStats` | `30_000` |

## Mutation strategy — optimistic vs. pessimistic

Per `docs/frontend/TRIAL_BALANCE.md`'s platform-wide Principle 10 ("reversible, non-financial actions are
optimistic; anything that changes the ledger's authoritative state is pessimistic"), applied concretely to
this screen's own five mutations:

| Mutation | Strategy | Rationale |
|---|---|---|
| `useCreateAccount` | **Optimistic** — inserts a ghost row (`opacity-60`, `id: "pending-" + uuid`) into the cached tree at the selected parent, replaced by the real row on `2xx` or removed with a toast on error | A new, zero-balance, unposted account moves no money and posts no fact (`docs/accounting/CHART_OF_ACCOUNTS.md § 12.3`) |
| `useUpdateAccount` (name/description/tags/status) | **Optimistic** | Same rationale — cosmetic/organizational fields, not financial state |
| `useReclassifyAccount` | **Pessimistic** — no `onMutate`; the row's type only changes after the server's `2xx` | Changes the classification of potentially thousands of posted `journal_lines`; the UI must never show a reclassification as done before the server has actually recomputed derived reports |
| `useMergeAccounts` | **Pessimistic** | Irreversibly reassigns `journal_lines` and reparents children — the single highest-blast-radius mutation on this screen |
| `useSetAccountStatus` (deactivate/reactivate) | **Optimistic** | Reversible, does not touch posted history |
| `useDeleteAccount` | **Pessimistic** | Only ever legal on a zero-history, zero-child account, but the server is the sole authority on that precondition at the moment of the call (see `# Edge Cases`) |

```ts
// hooks/accounting/use-account-mutations.ts
export function useCreateAccount() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: AccountInput) => api.post("/accounting/accounts", input, { idempotencyKey: crypto.randomUUID() }),
    onMutate: async (input) => {
      await qc.cancelQueries({ queryKey: accountingKeys.all });
      const previous = qc.getQueriesData({ queryKey: accountingKeys.all });
      qc.setQueriesData({ queryKey: accountingKeys.tree({}) }, (old: AccountNode[] | undefined) =>
        insertGhostNode(old, input));
      return { previous };
    },
    onError: (_e, _v, ctx) => ctx?.previous.forEach(([key, data]) => qc.setQueryData(key, data)),
    onSettled: () => qc.invalidateQueries({ queryKey: accountingKeys.all }),
  });
}

export function useReclassifyAccount() {
  // No onMutate — see rationale table above. UI shows the change only after the server's 2xx.
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, newAccountTypeId, reason }: { id: number; newAccountTypeId: number; reason: string }) =>
      api.post(`/accounting/accounts/${id}/reclassify`, { new_account_type_id: newAccountTypeId, reason }),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: accountingKeys.detail(id) });
      qc.invalidateQueries({ queryKey: accountingKeys.tree({}) });
    },
  });
}
```

## Realtime

Identical channel set to `docs/frontend/ACCOUNTING.md → Realtime` — `private-company.{id}.accounting` and
`private-company.{id}.approvals` — subscribed once by a screen-level `useAccountingHubRealtime(companyId)`
hook (full source already given in that document; this file does not re-declare it, only consumes it from
`app/(app)/accounting/layout.tsx` so every child route benefits from the same subscription rather than each
re-subscribing).

# Interactions & Flows

Numbered as a concrete implementation sequence; the narrative form of each flow is
`docs/frontend/ACCOUNTING.md → Interactions & Flows`'s own territory and is not repeated here.

1. **Opening the screen.** `loading.tsx`'s shell renders instantly; `AccountingStatusStrip`, the Chart of
   Accounts region, and `AccountingAiApprovalsRail` each resolve behind their own `Suspense` boundary — a
   slow `ai-suggestions` call never blocks the Status Strip or the tree from painting.
2. **Toggling Tree ↔ Flat.** A segmented control local to the Chart of Accounts region's own header flips
   `shape` in `ChartOfAccountsTree`'s `filters` prop; because both shapes key off `accountingKeys.tree(filters)`
   with only `shape` differing, switching never re-triggers a fetch for data already resident in cache.
3. **Searching.** `Command`'s input debounces 250ms and re-keys `accountingKeys.tree({ ...filters, search })`;
   a match inside a collapsed branch expands every ancestor on its materialized `path` and scrolls the row
   into view in the same tick — never a "found but invisible" result.
4. **Expanding a large subtree.** A node with `hasMoreChildren: true` triggers `GET .../{id}/children` on
   first expand only; the fetched children are merged into the cached tree at that node's position via
   `setQueryData`, so a second collapse/expand of the same node never re-fetches.
5. **Opening the Quick Create sheet.** "New Account" opens `QuickCreateAccountSheet` (not a route change);
   typing `name_en`/`name_ar` fires `useDuplicateAccountCheck` at 400ms debounce; submitting calls
   `useCreateAccount()`, whose optimistic ghost row appears in the tree the instant the request is sent (see
   `# Data & State → Mutation strategy`).
6. **Opening the Account Detail Sheet.** Clicking any row calls `onSelectAccount(id)`, which sets a
   `?account={id}` query param (shareable, back-button-safe) and opens `AccountDetailSheet`; closing the
   sheet (Escape, backdrop, or "×") clears the param and returns focus to the triggering row.
7. **Reclassify / Merge.** Both dialogs re-fetch the target account(s) fresh immediately before opening the
   impact-preview text, diff against any already-cached copy, and block submission on an empty `reason`
   field — `useReclassifyAccount`/`useMergeAccounts` fire only after that confirmation.
8. **Accepting/rejecting a Pending AI card.** `onAccept` calls `POST .../ai-suggestions/{id}/accept`, which
   itself calls the underlying `.reclassify`/`.merge`/`.update` endpoint server-side under the *user's own*
   permissions — never a privileged AI-only path; `onDismiss` requires a reason, matching `AIProposalPanel`'s
   platform-wide contract.
9. **Approving/rejecting an `ApprovalCard`.** Advances that approval's own chain via `/api/v1/approvals/{id}
   /approve`\|`/reject`; never posts a journal entry, commits a reclassification, or executes a merge
   directly — the approval record and the underlying mutation are two different rows.
10. **Clicking the Period Status tile.** Opens `PeriodStatusTile`'s popover in place (no navigation); "Close
    this period" is disabled while `closeReadiness.score < 100`, per the source shown in `# Components Used`.
11. **Navigating a Module Nav Card or a sub-nav tab.** Always a real `<Link>` / route change — never an
    intercepted route or a modal — so leaving this hub always resets focus to the destination's own heading.

# AI Integration

This screen's AI surface has two distinct halves, and both obey the platform-wide contract in full:
confidence and reasoning are never hidden, visual provenance is one consistent 6px `accent` dot (never a
colored row background), and the three-button pattern (`Do it` / `Send for approval` / `Dismiss`) is exact,
never approximated — all as `docs/frontend/ACCOUNTING.md → AI Integration` already establishes. This section
adds the concrete numeric gates an implementer needs.

| Gate | Value | Source | Effect on this screen |
|---|---|---|---|
| Confidence floor for "Do it" | `< 0.6` disables it | `ConfidenceBadge`/`AIProposalPanel`, `COMPONENT_LIBRARY.md` | A duplicate-account suggestion below 60% still renders in the Pending AI queue, but its "Do it" (where present at all) is disabled regardless of `autonomyLevel` |
| Client-side auto-execute mirror | `AUTO_EXECUTE_THRESHOLD = 0.9` **and** `autonomyLevel === 'auto'` | `AIProposalPanel`'s own `canAutoExecute` constant, `COMPONENT_LIBRARY.md` | A UX-layer mirror only — never the authority; the server's own `can_execute_directly` boolean is what actually governs whether "Do it" renders at all on this screen (see next row) |
| Server-authoritative execute gate | `can_execute_directly: boolean` on the decision payload | `docs/frontend/AI_COMMAND_CENTER.md → AI Integration`, reused by `docs/frontend/ACCOUNTING.md → Interactions & Flows` | "Do it" does not render at all when `false` — on this screen this is the common case, since account mutations with posted history nearly always route through the reason-carrying Reclassify/Merge dialogs instead of a one-click accept |
| Confidence scale normalization | `ai_suggestion_confidence` is `0.0000–1.0000`; do **not** divide by 100 | `ConfidenceBadge`'s `normalizeConfidence(raw, 'fraction')`, `COMPONENT_LIBRARY.md` | The Pending AI queue's own suggestions use the `'fraction'` source; only the Period Status tile's close-readiness `confidence` (already `0–1` in the worked JSON above) and any `ai_decisions`-sourced approval card (`0–100`, needs `'percentage'`) differ — mixing the two normalizations on this one screen is the single most common implementation bug to guard against in review |

Two provenance markers on the tree itself, restated with their exact visual spec so a reviewer can check a
screenshot against this table directly: a 6px `accent`-filled circle in a row's leading gutter
(`row.hasOpenAiSuggestion`) means an open suggestion exists and disappears the instant it is accepted or
dismissed; a `⚑` glyph instead marks an open Risk Detection finding from the Auditor/Fraud Detection agents
and is never removed by a dismiss action from this screen — it links out to the full finding in
`docs/frontend/AI_COMMAND_CENTER.md`'s Detected Risks radar, since a risk is escalation, not an offer.

The AI service-account identity backing every suggestion on this screen is capped, at the infrastructure
layer, to read and suggestion-producing endpoints only (`docs/accounting/CHART_OF_ACCOUNTS.md → 15.1`); it
cannot reach `POST /accounting/accounts`, `PATCH .../{id}`, `.../reclassify`, `/merge`, or `DELETE .../{id}`
under any company automation policy, at any confidence score. Every acceptance a human makes on this screen
— "Do it," a confirmed pre-filled Reclassify/Merge dialog, an applied template — calls the exact same
endpoint a human typing the same change by hand would call, authenticated as that human, under that human's
own permissions.

# States

| Region | Loading | Empty | Error |
|---|---|---|---|
| Status Strip | 4 `Skeleton` tiles, `h-8 w-32` | Total Accounts/Pending Approvals have no empty state (always computable, even as `0`); Period Status shows "Set up your fiscal year" with a setup CTA for a company with no `fiscal_years` row | Per-tile `WidgetErrorBoundary` → "Couldn't load this figure — Retry"; the other three tiles stay interactive |
| Chart of Accounts | `TreeSkeleton` (component above) | Zero accounts: "Set up your Chart of Accounts" card with "Apply a template" / "Import from another system"; a filter/search matching nothing shows a distinct, narrower "No accounts match your search" with "Clear filters" | Region-level `WidgetErrorBoundary` → "Couldn't load the Chart of Accounts — Retry"; a failed lazy-expand on one node shows a small inline retry affixed only to that node |
| AI & Approvals Rail | Skeleton cards matching `AIProposalPanel`/`ApprovalCard` geometry | "You're all caught up — no pending suggestions or approvals right now," rendered via `<EmptyState tone="calm">`, never styled as a warning | A distinct "AI insights are temporarily unavailable" state (not a generic error) on a `503` from the AI engine, respecting `Retry-After`; the Approvals half fails and recovers independently since it hits a different endpoint |
| Module Nav Cards | 4 skeleton cards | Never truly empty — a card for a sibling module the viewer cannot read is omitted (`Can`), not shown empty | Per-card inline retry; the card's own link stays functional even if its live stat fails, since the link points at the sibling screen's own fetch, not this hub's cached figure |
| Account Detail Sheet | Skeleton matching the Overview tab's field layout | N/A | Inline retry inside the sheet; the sheet itself never fails to *open*, only its content |
| Quick Create Sheet | N/A (opens synchronously) | N/A | A `422` maps to field errors via `setError`; a `5xx` shows a toast and keeps the sheet open with the user's typed values intact |

A route-level `error.tsx` remains the outermost safety net for a genuinely unexpected failure (the session
itself becoming invalid mid-render); per the platform's three-granularity error model this should essentially
never fire for an individual region's ordinary `4xx`/`5xx` — those are caught and handled inline, one region
at a time, exactly as `docs/frontend/ACCOUNTING.md → States` and `docs/frontend/DASHBOARD.md → States` both
establish for their own regions.

# Responsive Behavior

Breakpoints are `LAYOUT_SYSTEM.md → Breakpoints`'s fixed scale — this screen introduces no breakpoint of its
own:

| Token | Width | This screen's behavior |
|---|---|---|
| `base` | <640px | Sub-nav tab bar becomes a horizontal-scroll strip, no wrap, snap-aligned; Status Strip becomes a `snap-x snap-mandatory` carousel, one `KpiTile` per screen-width; Chart of Accounts renders full-width, single column, with a deeply nested row's indentation capped at 3 visual levels and a `…in Current Assets ▸ Bank Accounts` breadcrumb prefix replacing further indentation; AI & Approvals Rail and Module Nav Cards stack below it, full width, in that order |
| `sm` (640px) | Still single column; the Quick Create Sheet becomes a full-height, full-width sheet rather than a fixed `max-w-md` panel |
| `md` (768px) | Status Strip becomes two `KpiTile`s per row; Chart of Accounts and the Rail remain stacked, full width; Module Nav Cards become a `2×2` grid |
| `lg` (1024px) | Status Strip becomes a full row of four; the Chart-of-Accounts/Rail pair activates its `col-span-8`/`col-span-4` split |
| `xl` (1280px) | Full bento layout exactly as drawn in `# Layout & Regions`; Module Nav Cards become a single row of four; the platform-wide `360px` AI Rail may dock inline alongside this hub's own AI & Approvals Rail without competing for the same column |
| `2xl` (1536px) | Same bento layout, wider outer margins per `LAYOUT_SYSTEM.md → Container widths & gutters` |
| `3xl` (1920px) | Container ceiling reached; extra width becomes margin, not additional columns |

`ChartOfAccountsTree` and `ModuleNavCard` use Tailwind v4 container queries (`@container`), not only
viewport breakpoints, matching `docs/frontend/ACCOUNTING.md`'s and `docs/frontend/DASHBOARD.md`'s identical
convention for `KpiTile` — the same `ModuleNavCard` renders correctly whether it is one-of-four in a
desktop row or full-width on a phone. Touch targets on every row-expand chevron, every Rail card's action
button, and every `KpiTile`'s tap target meet the platform's 44×44px minimum below `md`, implemented once in
the shared `Button`/`IconButton` components rather than per-instance on this screen (`RESPONSIVE_DESIGN.md →
Touch targets`).

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md → RTL mirroring` and `LAYOUT_SYSTEM.md`'s RTL
contract, applied concretely to this screen's own regions — `docs/frontend/ACCOUNTING.md → RTL &
Localization` states the same rules; this section adds the concrete component-level application.

- **Logical properties only.** `ChartOfAccountsTree`'s `paddingInlineStart` (never `paddingLeft`), the
  Status Strip's `ms-*`/`me-*` tile order, the Module Nav Cards grid, and the Page Header's action row all
  flip under `dir="rtl"` with zero screen-specific RTL code.
- **Numerals, account codes, and currency codes never mirror.** Every `AmountCell` in the tree and the
  Status Strip's Total Accounts figure render inside a `dir="ltr"` / `unicode-bidi: isolate` span; Western
  Arabic digits (`numberingSystem: "latn"`) are used even under `ar`, matching the same rule
  `docs/frontend/TRIAL_BALANCE.md → RTL & Localization` states for its own money columns.
- **Bilingual names are the default, not an exception, on this screen.** Every account row natively carries
  both `name_en` and `name_ar`; the tree renders whichever matches the active locale, and
  `QuickCreateAccountSheet` always shows both fields simultaneously — never one behind a language toggle —
  per `docs/frontend/ACCOUNTING.md`'s identical rule.
- **The expand/collapse chevron mirrors; the AI-provenance dot and `⚑` flag do not.** `ChevronRight` uses
  `rtl:rotate-180` (plus a further 90° rotation when expanded, in either direction); the accent dot and the
  risk flag are static glyphs in both directions.
- **Charts never mirror** — not directly applicable to this screen (it has no chart region of its own), but
  the rule is restated here for completeness since the Period Status popover could, in a future revision,
  gain a trend sparkline; if it ever does, that sparkline keeps a fixed left-to-right time axis regardless of
  `dir`, per the platform-wide rule every other screen in this series applies.

| Context | English | Arabic |
|---|---|---|
| Screen title | Accounting | المحاسبة |
| Primary action | New Account | حساب جديد |
| Secondary caret item | Apply Template | تطبيق قالب |
| Secondary caret item | Enter Opening Balances | إدخال الأرصدة الافتتاحية |
| Status Strip tile | Total Accounts | إجمالي الحسابات |
| Status Strip tile | Current Fiscal Period | الفترة المالية الحالية |
| Popover action | Close this period | إغلاق هذه الفترة |
| Duplicate warning | Possible duplicate — {pct}% match | تطابق محتمل — نسبة {pct}٪ |
| Empty state | Set up your Chart of Accounts | إنشاء دليل الحسابات |
| Empty state | You're all caught up | كل شيء تحت السيطرة |

Arabic copy on this screen is authored directly by a fluent professional-register writer, never
machine-translated from the English column above, matching `docs/frontend/DESIGN_LANGUAGE.md → Voice &
Microcopy`'s Gulf-business-register brief and reusing the exact accounting terminology
`docs/accounting/CHART_OF_ACCOUNTS.md` and `docs/frontend/ACCOUNTING.md` already established (دليل الحسابات
for Chart of Accounts, تصنيف for classification, دمج for merge).

# Dark Mode

This screen introduces no new color, elevation, or radius token — every surface resolves through the
platform's `.dark` class remap defined once in `DESIGN_LANGUAGE.md → Design Tokens (CSS variables)`. The
concrete pairs this screen's own components read most often:

| Token | Light | Dark | Used on this screen for |
|---|---|---|---|
| `--qayd-ink-1` / `--qayd-ink-12` | `#FAFAF9` / `#15130E` | `#14130F` / `#F8F6EF` | Canvas background / primary text — note the dark-mode scale is not a naive inversion: `ink-1` is the *darkest* surface in dark mode, `ink-12` the *lightest* text, so a component that reads `bg-ink-1 text-ink-12` needs no per-theme branch at all |
| `--qayd-ink-3` / `--qayd-ink-4` | `#EBE9E6` / `#E0DEDA` | `#24211A` / `#2D2921` | Tree row hover / selected state |
| `--qayd-ink-6` | `#C2BEB6` | `#46402F` | Tree region border, `TreeSkeleton` row dividers |
| `--qayd-accent` | `#9C7A34` | `#D9B96C` | AI-provenance dot, `ConfidenceBadge` fill, the one primary-action color (New Account button) |
| `--qayd-accent-subtle` | `#EADFBF` | `#3A2E14` | Duplicate-detection inline note background, `AIProposalPanel`'s `ai-insight` card border tint |
| `--qayd-positive` / `--qayd-negative` / `--qayd-warning` | `#17794A` / `#B4232E` / `#B45309` | `#4ADE94` / `#F26B74` / `#F5A855` | `StatusPill` active/inactive/archived tones; never used on a debit/credit figure itself, per the platform's debit/credit rule |

Elevation gets *lighter*, not darker, toward the viewer in dark mode (`DESIGN_LANGUAGE.md → Dark mode
strategy`) — the Chart of Accounts region's card sits a step lighter than the page canvas behind it in both
themes, and the accent is deliberately the same hue family used for both "the one primary action" and "AI
touched this," so a user learns that reading once and it holds in both themes (`docs/frontend/ACCOUNTING.md
→ Dark Mode`). Every Storybook story for `AccountingStatusStrip`, `ModuleNavCard`,
`AccountingAiApprovalsRail`, and `QuickCreateAccountSheet` ships the platform's standard four-way parameter
matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this route's own Playwright suite captures the
same four-way screenshot set at the route level, per `FRONTEND_ARCHITECTURE.md`'s testing convention.

# Accessibility

Baseline WCAG 2.1 AA, identically in both languages and both themes, restating `docs/frontend/ACCOUNTING.md
→ Accessibility`'s rules with the concrete implementation checklist an engineer verifies against:

- **The tree is a real `treegrid`.** `role="treegrid"`, `aria-level` per row, `aria-expanded` on every
  branch node, `aria-rowindex`/`aria-rowcount` for the virtualized set — verified with axe-core in CI and
  manually with VoiceOver/NVDA's rotor before this component ships (full source in
  `docs/frontend/ACCOUNTING.md → ChartOfAccountsTree`).
- **Keyboard path.** `↓`/`↑` move between visible rows, `→` expands (or moves to the first child), `←`
  collapses (or moves to the parent), `Enter` opens `AccountDetailSheet`, `Home`/`End` jump to the first/last
  visible row — the same `useRovingGrid` utility `docs/frontend/JOURNAL_ENTRIES.md`'s `JournalLineGrid`
  names as canonical, extended with tree semantics rather than a third bespoke handler.
- **Realtime announces politely.** A `.account.updated` patch or a new Rail card uses `aria-live="polite"`
  exclusively — never assertive, matching `docs/frontend/DASHBOARD.md` and `docs/frontend/AI_COMMAND_
  CENTER.md`'s identical rule for their own feeds.
- **Landmarks are real, not visually implied.** Each of the four content regions (`AccountingStatusStrip`,
  the Chart of Accounts region, `AccountingAiApprovalsRail`, `ModuleNavCardsRow`) is wrapped in a
  `<section aria-labelledby>` with a `VisuallyHidden` `<h2>`, mirroring the pattern
  `docs/frontend/DASHBOARD.md → Accessibility` establishes for its own regions.
- **Color is never the only channel.** The AI-provenance dot always pairs with a `Tooltip` naming confidence
  and agent; the `⚑` risk flag always pairs with an icon and a tooltip; `StatusPill` always pairs a text
  label with its tone.
- **Permission-gated controls are legible, not mysterious.** A row-overflow action whose permission is
  absent is omitted entirely (existence-sensitive); the one documented exception is the Period Status tile's
  "Close this period" action, which renders disabled with `aria-describedby` pointing at a `Tooltip` reading
  "Requires `accounting.period.lock`" — because a period's closeable *state* is not itself sensitive, only
  the ability to act on it is, mirroring `docs/frontend/TRIAL_BALANCE.md`'s identical treatment of its own
  Generate button.
- **Focus management.** Opening `AccountDetailSheet`/`QuickCreateAccountSheet` traps focus within the sheet
  (Radix `Dialog` under `Sheet`) and returns it to the triggering control on close; navigating to a sibling
  route via a Module Nav Card or a sub-nav tab resets focus to the destination's own heading — no bespoke
  focus-trap logic is written for this screen specifically.

# Performance

- **Streamed, not blocking.** Per `# Layout & Regions`'s `page.tsx`, every region is its own `Suspense`
  boundary; the slowest region (typically the AI & Approvals Rail, if an agent run is in flight) never
  delays the Chart of Accounts region's first paint.
- **Virtualized from the first line of this specification.** `ChartOfAccountsTree`'s `flattenVisible` +
  `@tanstack/react-virtual` combination targets `docs/accounting/CHART_OF_ACCOUNTS.md → Business Goal G2`'s
  stated floor outright: a 5,000-account, 8-level tree returns a filtered list in <300ms p95; the tree never
  renders more DOM rows than fit the viewport plus a 12-row overscan.
- **Search is server-ranked.** `GET /accounting/accounts?search=` targets `docs/accounting/
  CHART_OF_ACCOUNTS.md → Business Goal G10`'s <150ms p95 typeahead latency; this screen's search box calls it
  directly rather than filtering an already-fetched, potentially-truncated tree client-side.
- **Bundle budget.** This route's shell — excluding the individually `next/dynamic`-split
  `ChartOfAccountsTree` chunk, which pulls in `@tanstack/react-virtual` — targets ≤180KB gzipped first-load
  JS, enforced in CI against `performance-budgets.json`, matching the discipline `docs/frontend/DASHBOARD.md
  → Performance` applies to its own chart dependency.
- **Deferred overlays.** `QuickCreateAccountSheet`, `AccountDetailSheet`'s content, the Reclassify/Merge
  dialogs, and the Import/Export menu's content are dynamically imported — none is needed for first paint.
- **Cache-then-realtime, not poll.** Every region's `staleTime` (`# Data & State`) is corrected primarily by
  the Reverb channel's targeted invalidations, not a refetch interval — a company with a large, mostly-static
  Chart of Accounts never pays a background-polling cost proportional to tree size.
- **Web Vitals tracked against a company-size-band baseline**, exactly as `docs/frontend/DASHBOARD.md →
  Performance` specifies platform-wide — a 5,000-account company and a 40-account company have legitimately
  different tail latencies on this exact screen, and monitoring distinguishes a genuine regression from an
  expected, scale-dependent one.

# Edge Cases

`docs/frontend/ACCOUNTING.md → Edge Cases` already covers the eleven scenarios specific to the Chart of
Accounts tree itself (deep trees, concurrent edits, delete-on-children races, stale AI suggestions, a
brand-new company, a narrow custom role, search-inside-a-collapsed-branch, template re-application, WebSocket
reconnect, and a mid-session company switch) — this document does not repeat them. The rows below are this
implementation layer's own additions: races and environment conditions that surface specifically once
`page.tsx`, its mutation hooks, and its optimistic-update logic are actually written.

| Edge case | Frontend behavior |
|---|---|
| `useCreateAccount`'s optimistic ghost row is still pending when the user closes `QuickCreateAccountSheet` and immediately reopens it for a second account | The first ghost row remains in the tree, independently tracked by its own `pending-<uuid>` key; opening a second sheet does not cancel or interfere with the first's in-flight request — both resolve or roll back independently |
| The network drops between `useCreateAccount`'s optimistic insert and the server's response | `onError` rolls back to the exact `previous` snapshot captured in `onMutate`; a toast names the failure and offers "Retry" pre-filled with the same form values, rather than silently leaving a ghost row stranded in the tree |
| Two browser tabs both have the Quick Create sheet open for the same parent account, and both submit within the same second | Both `POST`s carry independent `Idempotency-Key`s and are processed as two distinct creates server-side (this is a legitimate double-create, not a duplicate submission); the Duplicate Detection agent picks up the resulting near-identical pair on its next pass and raises the ordinary Pending AI merge suggestion — the frontend does not attempt to detect or block this client-side |
| The active locale is switched (EN → AR) while a debounced tree search request is still in flight | The in-flight request is not cancelled — it completes and is applied — but the search input's own placeholder and the "No accounts match your search" empty-state copy re-render in the new locale immediately, since locale and search-result data are independent query keys; a locale switch never clears `accountingKeys.tree`'s cache, since account codes and structure are locale-invariant |
| A company's Period Status tile shows `score: 100` (close-readiness complete) but the "Close this period" click races a colleague's concurrent new draft journal entry landing in the same period | The `POST .../fiscal-periods/{id}/close` call re-validates server-side regardless of the client's last-fetched score; a `409` response re-opens the popover with the now-current, lower score and its updated `blocking_items[]`, rather than silently succeeding against stale client state |
| `AccountingStatusStrip`'s bundle endpoint (`GET /accounting/accounts/summary`) returns before `Total Accounts` has any value because the company has accounts but zero have been assigned a `nature` yet (a mid-import state) | The tile still renders the raw count rather than withholding it — `Total Accounts` is a count of rows, not a validity judgment; a separate, distinct "N accounts need review" affordance (surfaced from the Pending AI queue's missing-classification suggestions, not this tile) is where an incomplete-import signal belongs |
| A very long `tags` array (the Zod schema's own `max(20)` ceiling) is entered in `QuickCreateAccountSheet` | The 21st tag attempt is rejected client-side with the schema's own message before any network call, and the same ceiling is enforced again server-side per `docs/frontend/ACCOUNTING.md`'s "the frontend performs presence/length/format checks that mirror, never substitute for, the server's own" rule |
| A user's session permission for `accounting.accounts.reclassify` is revoked by an admin in another tab while the Reclassify dialog is open on this screen | The dialog's submit button does not disappear mid-edit (per `FRONTEND_ARCHITECTURE.md`'s Principle 4, hiding a control is a courtesy, not the authority); the server's own `403` on submission is caught and rendered as an inline dialog error naming the missing permission, and the dialog stays open with the typed reason preserved so the user does not lose their work |

# End of Document
