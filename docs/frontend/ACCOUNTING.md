# Accounting — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: ACCOUNTING
---

# Purpose

This document specifies the Next.js 15 frontend for `/accounting` — the Accounting module's hub screen, the first surface every role holding `accounting.read` reaches when it opens the Accounting section of the Sidebar, and the screen every sibling Accounting document's breadcrumb root points back to (`docs/frontend/JOURNAL_ENTRIES.md`, `GENERAL_LEDGER.md`, `TRIAL_BALANCE.md`, `BALANCE_SHEET.md` all begin their trail at `Accounting / …`). It is the frontend rendering of the module boundary `docs/accounting/CHART_OF_ACCOUNTS.md` defines as "the foundational classification structure of the QAYD Accounting Engine" — the account tree every other Accounting screen is ultimately a view over — combined with the module-scoped landing-page conventions `docs/frontend/DASHBOARD.md` established for the company-wide home screen, applied here to one module instead of the whole company.

This screen is deliberately two things at once, and reconciling that is the first job of this document. **It is the Chart of Accounts screen.** Unlike Journal Entries, General Ledger, Trial Balance, and the three Financial Statements, Chart of Accounts has no separate, dedicated frontend screen specification anywhere in this documentation set — because Chart of Accounts is definitionally the module's own home: every account picker in Journal Entries, every account scope in General Ledger, and every row on a Trial Balance is a view keyed off the exact tree this screen renders. Giving it a second, standalone document elsewhere in the set would either duplicate this one or leave the two silently drifting apart; instead, the account tree/table from `GET /api/v1/accounting/accounts` **is** this hub's main content region, and this document is the authoritative, complete frontend contract for browsing, searching, and lightly maintaining it — not a teaser that defers detail to a page that does not exist. **It is also the module's own landing dashboard** — current fiscal-period status, the AI layer's pending Chart-of-Accounts suggestions, the cross-module approvals queue that touches Accounting, and a fast quick-create/nav-out row into the four sibling screens — in the same register `DASHBOARD.md` established for the company-wide KPI/AI-rail/quick-actions shape, scoped down to one module.

Three routing facts this document commits to, stated once so no other document has to re-derive them. First, `docs/frontend/NAVIGATION_SYSTEM.md`'s module map and its `NAV_TREE` code sample both point the Accounting Sidebar item at `/accounting/accounts` — the same href it gives the Chart of Accounts sub-item — following the identical pattern every other module in that tree uses (Banking's root and its "Bank Accounts" sub-item are both `/banking/accounts`; Sales's root and "Quotations" are both `/sales/quotations`). This screen's actual route is `/accounting` (`app/(app)/accounting/page.tsx`); `/accounting/accounts` is retained as a permanent redirect to `/accounting`, exactly mirroring the `/dashboard` → `/command-center` alias `docs/frontend/AI_COMMAND_CENTER.md` establishes for the same reason (an earlier route sketch surviving in one document after this one gave the screen its own dedicated hub identity). Second, `docs/frontend/BALANCE_SHEET.md` — the most specific, most recently authored word on where Financial Statements actually live — nests them at `accounting/financial-statements/<statement-type>`, gated by an `accounting.financial-statements.*` permission family distinct from generic `reports.*` grants; this document follows that route and that permission family for its Financial Statements nav card, treating `NAVIGATION_SYSTEM.md`'s earlier top-level `/financial-statements` + `accounting.report.read` sketch as superseded, with `/financial-statements` kept as a redirect alias for the same reason as above. Third, this screen renders inside the shared `app/(app)/accounting/layout.tsx` `docs/frontend/TRIAL_BALANCE.md` already documents as wrapping every Accounting screen with a persistent sub-nav (Chart of Accounts | Journal Entries | Ledger | Trial Balance) — this document is that tab bar's fifth tab (Financial Statements) and, because "Chart of Accounts" is the tree this hub itself renders, the tab bar's **first** tab targets this exact route rather than a sixth, separate page.

Three constraints inherited from `docs/frontend/FRONTEND_ARCHITECTURE.md` and `docs/frontend/README.md` bind every pixel below and are not re-argued here. **The frontend computes nothing.** Every account, balance, fiscal-period status, and AI suggestion figure on this screen is a value Laravel already validated and persisted (`docs/accounting/CHART_OF_ACCOUNTS.md`, `docs/accounting/GENERAL_LEDGER.md → Fiscal Years & Periods`); this screen's own logic is limited to fetching, caching, filtering, searching, and routing a click to the right destination. **AI is visible, labeled, and never silent.** Every AI-sourced element on this screen — a duplicate-account flag, a missing-account recommendation, a reclassification suggestion, an approval item originated by an agent — carries its confidence and reasoning exactly as `docs/frontend/DESIGN_LANGUAGE.md` and `COMPONENT_LIBRARY.md` require, and never auto-commits; a human's click is what turns a proposal into a fact. **RBAC is enforced by the API; the UI only reflects it.** A control gated by a permission this role lacks is omitted from the DOM, never shown disabled-and-unexplained, per the platform-wide rule restated in `# Accessibility` below.

The audience is Accounting, Finance, and Audit roles only: Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, and Auditor/External Auditor (`docs/foundation/PERMISSION_SYSTEM.md → Roles`, the same vocabulary `docs/frontend/TRIAL_BALANCE.md` and `docs/frontend/JOURNAL_ENTRIES.md` already map their own permission tables onto). No Sales, Purchasing, Inventory, or Payroll self-service role ever sees this screen — the Accounting Sidebar section itself is gated by the parent `accounting.read` permission, and a role that lacks it never sees the nav item, the tab bar, or a rendered `403` shell; the route simply does not exist for them, matching `docs/frontend/NAVIGATION_SYSTEM.md → Permission-Aware Nav`'s existence-sensitive posture.

# Route & Access

## Route tree

```text
app/(app)/accounting/
├── layout.tsx                       # Shared sub-nav tab bar (Chart of Accounts | Journal Entries |
│                                     #   General Ledger | Trial Balance | Financial Statements);
│                                     #   resolves the parent `accounting.read` gate once for every child
├── page.tsx                         # THIS DOCUMENT — the hub, and the Chart of Accounts screen
├── loading.tsx                      # Hub-shaped skeleton (see # States)
├── journal-entries/…                # docs/frontend/JOURNAL_ENTRIES.md
├── ledger/…                         # docs/frontend/GENERAL_LEDGER.md
├── trial-balance/…                  # docs/frontend/TRIAL_BALANCE.md
├── financial-statements/
│   ├── balance-sheet/…              # docs/frontend/BALANCE_SHEET.md
│   ├── profit-and-loss/…            # docs/frontend/PROFIT_AND_LOSS.md
│   └── cash-flow/…                  # docs/frontend/CASH_FLOW.md
└── fiscal-periods/…                 # referenced by the Period Status tile below; not specified in
                                      #   full here — same convention as this doc referencing GL/JE/TB/FS
```

```ts
// next.config.ts (excerpt) — alias redirects for two earlier route sketches
async redirects() {
  return [
    { source: "/accounting/accounts", destination: "/accounting", permanent: true },
    { source: "/financial-statements/:statement*", destination: "/accounting/financial-statements/:statement*", permanent: true },
  ];
},
```

## Access gate

| | |
|---|---|
| Route | `app/(app)/accounting/page.tsx` (Server Component) + `app/(app)/accounting/loading.tsx` |
| Layout | `app/(app)/accounting/layout.tsx` — renders the five-tab sub-nav and resolves `GET /api/v1/auth/me`'s `permissions` array once for every child route, per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s layout-nesting convention |
| Nav item | **Accounting** — `BookOpenCheck` icon, position 2 of 10 in the primary Sidebar (`docs/frontend/NAVIGATION_SYSTEM.md → Primary Navigation`) |
| Gating permission | `accounting.read` — the module-parent gate every sub-screen inherits; a role without it never sees the Sidebar item, the tab bar, or this route at all |
| This screen's own data permission | `accounting.accounts.read` — gates the tree/table region specifically, layered under the parent `accounting.read` gate exactly as `docs/frontend/GENERAL_LEDGER.md`'s `accounting.ledger.read` layers under the same parent |
| Breadcrumb | `Accounting` only — the IA root for every Accounting sub-screen; a single, non-linked crumb here, matching `docs/frontend/DASHBOARD.md`'s treatment of its own root |
| Keyboard entry | `G` then `A` opens this screen from anywhere, per the platform's "Go to" mnemonic registry (`docs/frontend/ACCESSIBILITY.md → Global keyboard shortcuts`: `G D` Dashboard, `G A` Accounting, `G J` Journal Entries, `G L` Ledger, `G T` Trial Balance). Note for reconciliation: `docs/frontend/JOURNAL_ENTRIES.md → Keyboard entry points` also documents `G` then `A` for its own route, which conflicts with the mnemonic table above (Journal Entries' own mnemonic-consistent binding would be `G J`); the shortcut in this document is authoritative for the Accounting module root, and `ACCESSIBILITY.md`'s registry is where the two should be reconciled in code review. |
| Company/branch scope | Fixed to the active `X-Company-Id`; accounts are not branch-partitioned data (`accounts.branch_id` is a visibility/default-use filter, not a tenancy boundary — `docs/accounting/CHART_OF_ACCOUNTS.md § 7.6`), so this screen has no page-level branch filter the way `docs/frontend/DASHBOARD.md` does; a branch-scoped account is simply annotated inline (see `# Components Used`). |

## Permission surface on this screen

Every key below is defined once, authoritatively, in `docs/accounting/CHART_OF_ACCOUNTS.md → 15 Permissions`; this table maps each key to its concrete effect on this specific screen, exactly as `docs/frontend/JOURNAL_ENTRIES.md → Permission surface on this screen` does for its own module.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `accounting.accounts.read` | The route's data region (the tree itself); every account row; the account detail sheet | Route renders the shared `<ForbiddenState>` in place of the tree; nav item and tab hidden |
| `accounting.accounts.create` | "New Account" primary action, the Quick Create sheet, "Apply Template" | Buttons removed from the DOM, not disabled |
| `accounting.accounts.update` | Edit affordance inside the account detail sheet (name, description, tags, dimensions, status) | Detail sheet renders read-only fields with no Save action |
| `accounting.accounts.reclassify` | "Reclassify" action on an account with posted history | Menu item omitted — a sensitive, historically-loaded action is never shown disabled with no explanation |
| `accounting.accounts.merge` | "Merge accounts" flow, reachable from a row's overflow menu or the bulk-selection bar | Menu item omitted |
| `accounting.accounts.delete` | "Delete" on an account with zero postings and zero children | Menu item omitted; the Deactivate path (gated by `.update` instead) remains available |
| `accounting.accounts.set_opening_balance` | "Enter opening balances" quick action (visible mainly during onboarding, before the books-open date passes) | Action omitted |
| `accounting.accounts.import` | "Import" entry point in the Page Header's secondary actions menu | Button omitted |
| `accounting.accounts.export` | "Export" entry point in the same menu | Button omitted |
| `accounting.accounts.manage_types` | "Manage account types" link inside the Nature/Type filter's overflow | Link omitted |
| `accounting.accounts.review_ai_suggestions` | The AI & Approvals rail's "Pending AI" queue (view) | Queue section of the rail collapses entirely; Approvals half renders alone if `ai.approve`/`reports.read` are still held |
| `accounting.journal.post_to_control_account` | Read-only reference only — an informational note shown on a control-account row's detail sheet explaining why direct posting is normally blocked (`docs/accounting/CHART_OF_ACCOUNTS.md → BR-5`) | Note text changes to a plain explanation with no override link |
| `accounting.period.lock` / `accounting.period.reopen` | The Period Status tile's "Close this period" / "Reopen" quick actions | Tile still renders the status read-only; actions omitted |
| `ai.approve` | Approve / Reject on any `ApprovalCard` in the rail | Card renders read-only (amount, requester, confidence — no action row), exactly as `docs/frontend/JOURNAL_ENTRIES.md`'s `ApprovalCard` behaves without `accounting.journal.approve` |

Role grants are exactly the table in `docs/accounting/CHART_OF_ACCOUNTS.md → 15.1 Role Matrix`, mapped onto the platform's canonical role vocabulary (`docs/foundation/PERMISSION_SYSTEM.md → Roles`) the same way `docs/frontend/TRIAL_BALANCE.md → Route & Access` maps its own backend table — Owner/Admin collapse to the backend table's "Owner"/"Admin" tier, Finance Manager and Senior Accountant collapse to "Accountant" with the reclassify/merge exceptions the backend table already carries, and Read Only/External Auditor collapse to "Auditor." This document does not restate the matrix a second time, to avoid the two drifting apart; `usePermission()`/`Can` is the only source either query.

# Layout & Regions

This screen instantiates `docs/frontend/LAYOUT_SYSTEM.md`'s **Dashboard Template** — the same KPI-strip / main-region / insights-rail / secondary-widgets shape `docs/frontend/DASHBOARD.md` and `docs/frontend/AI_COMMAND_CENTER.md` both already use — scoped to one module instead of the whole company, with the account tree standing in for the chart region since a hierarchical ledger structure, not a trend line, is this module's own "what does the business look like" answer.

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│ Chart of Accounts │ Journal Entries │ General Ledger │ Trial Balance │ Financial St. │  ← accounting/layout.tsx
├──────────────────────────────────────────────────────────────────────────────────────┤    tab bar (persistent)
│  Accounting                                          [Import ▾] [+ New Account ▾]     │  ← Page Header
├──────────────────────────────────────────────────────────────────────────────────────┤
│ [312 accounts] [4 pending AI suggestions] [7 pending approvals] [Jul 2026 · Open · 62%]│  ← Status Strip
├────────────────────────────────────────────────────────┬─────────────────────────────┤
│ [Search accounts…]  [Nature ▾] [Status ▾]  [▤ Tree|Flat]│  AI & Approvals              │
├────────────────────────────────────────────────────────┤  ── Pending AI (4) ────────── │
│ ▸ 1000 · Assets                                         │  ▸ Merge 1101.002 → .001 92% │
│   ▸ 1100 · Current Assets                               │  ▸ Missing: Freight-In exp.  │
│     ▾ 1101 · Bank Accounts                              │  ── Approvals (7) ─────────── │
│         1101.001 · Bank – Boubyan Current    12,400.000 │  ▸ JE-2026-000512 · 850.000  │
│         1101.002 · Bank – Boubyan Savings ⚑   3,110.500 │  ▸ Reclassify 1180 → 1210    │
│       1120 · Accounts Receivable – Trade  ◆  44,020.500 │  [ View all in Approvals → ] │
│   ▸ 1200 · Fixed Assets                                 │                              │
│ ▸ 2000 · Liabilities   ▸ 3000 · Equity                  │                              │
│ ▸ 4000 · Revenue       ▸ 5000 · Expenses                │                              │
│ …(virtualized, cursor-loaded subtrees on expand)…        │                              │
├────────────────────────────────────────────────────────┴─────────────────────────────┤
│  Journal Entries        │  General Ledger        │  Trial Balance     │ Fin. Statements│  ← Module Nav Cards
│  12 drafts · 3 pending  │  Last posted 2h ago     │  Jul · Approved    │ B/S gen. Jul 1 │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

| Region | Grid position | Content | Notes |
|---|---|---|---|
| **Sub-nav Tab Bar** | Full width, above `Content`, rendered by `accounting/layout.tsx` | Chart of Accounts (this route, active), Journal Entries, General Ledger, Trial Balance, Financial Statements | Not owned by this document's `page.tsx`, but always visible above it; each tab's own live count badge (drafts, pending approvals) is sourced from the same endpoints the Module Nav Cards row below reads, so the two never disagree |
| **Page Header** | `col-span-12` | Title "Accounting"; primary action "New Account" (`accounting.accounts.create`) with a split-button caret for "Apply Template" / "Enter Opening Balances"; secondary actions menu (Import → `.import`, Export → `.export`) | No record-count badge here (unlike `docs/frontend/JOURNAL_ENTRIES.md`'s list header) — the Status Strip immediately below is where the account count lives, since this is a hub, not a paginated list page |
| **Status Strip** | `col-span-12`, one row | Four `KpiTile`s: Total Accounts, Pending AI Suggestions, Pending Approvals, Current Fiscal Period | Horizontal-scroll snap carousel below `md`, matching `docs/frontend/DASHBOARD.md → KPI Strip`'s own responsive rule |
| **Chart of Accounts region** | `col-span-8` at `lg`+ | The `ChartOfAccountsTree` (or, toggled, a flat `DataTable`) — this hub's primary content | Never mirrors under RTL except for indentation direction; see `# RTL & Localization` |
| **AI & Approvals Rail** | `col-span-4` at `lg`+ | Up to 5 condensed `AIProposalPanel` cards (Pending AI) and up to 5 condensed `ApprovalCard` rows (Approvals), each capped with a "View all" link | The module-scoped counterpart to `docs/frontend/DASHBOARD.md`'s company-wide AI Summary Rail; scrolls independently of the tree beside it |
| **Module Nav Cards** | `col-span-12`, four equal cards | `ModuleNavCard` × 4 — Journal Entries, General Ledger, Trial Balance, Financial Statements, each with a one-line live stat and a full-page link | Never a modal target; every card is a real navigation, per `docs/frontend/DASHBOARD.md`'s "Quick Actions never open an inline modal on the hub" precedent generalized here to "leaving this hub for a sibling screen is always a full navigation" |

At `xl`+ the platform-wide AI Rail (the `360px` companion panel distinct from this page's own AI & Approvals Rail region) may additionally dock inline per `docs/frontend/LAYOUT_SYSTEM.md → App Shell Layout`; when open it shows the same condensed, page-scoped proposal queue any screen would show — a companion to this page, never a duplicate of the region already documented above, which is part of this hub's own content and stays visible whether or not the global rail is toggled.

# Components Used

Every visual element is drawn from `docs/frontend/COMPONENT_LIBRARY.md`'s existing catalogue, composed into a small set of new, Accounting-hub-scoped components living in `components/accounting/` per `docs/frontend/README.md`'s module-folder convention. No primitive is hand-rolled; a gap discovered while building this screen is proposed as a `COMPONENT_LIBRARY.md` addition, never a one-off.

| Component | Source | Role on this screen |
|---|---|---|
| `KpiTile` | `components/dashboard/kpi-tile.tsx` (existing) | Total Accounts, Pending AI Suggestions, Pending Approvals tiles |
| `AmountCell` | `components/accounting/amount-cell.tsx` (existing) | Every balance figure in the tree, the detail sheet, and the Module Nav Cards |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` (existing) | Muted currency annotation on a currency-restricted account row |
| `StatusPill` | `components/shared/status-pill.tsx` (existing) | Account `status` (`active`/`inactive`/`archived`), reused with `domain="account"` |
| `Badge` | `components/ui/badge.tsx` (existing) | "control" badge on a control-account row (mirrors `AccountPicker`'s own `is_control_account` chip), "system" badge on a template-seeded account |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` (existing) | Every AI-sourced element — the tree's inline anomaly indicator, every `AIProposalPanel` card, the Period Status tile's close-readiness figure |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` (existing) | Pending AI queue in the rail — duplicate/merge, reclassification, and missing-account suggestions |
| `ApprovalCard` | `components/shared/approval-card.tsx` (existing) | Approvals queue in the rail — `kind="journal_entry" \| "ai_recommendation"`, plus the two new `kind` values this screen introduces (see below) |
| `AccountPicker` | `components/accounting/account-picker.tsx` (existing) | The Quick Create sheet's Parent field; the Merge dialog's survivor/loser selectors |
| `PeriodPicker` | `components/accounting/period-picker.tsx` (existing) | The Period Status tile's expanded popover, `mode="fiscal_period"` |
| `Tabs` | `components/ui/tabs.tsx` (existing) | The Account Detail Sheet's Overview / Ledger / History segmentation |
| `Sheet` | `components/ui/sheet.tsx` (existing) | Account Detail Sheet, Quick Create Account Sheet, both sliding from the inline-end edge |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` (existing) | Reclassify impact preview, Merge preview, Deactivate/Delete confirmation, AI suggestion dismiss reason |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` (existing) | Row overflow menu (Edit, Reclassify, Deactivate, Merge, Delete); Page Header's Import/Export menu |
| `Command` | `components/ui/command.tsx` (existing) | The tree region's search input, fuzzy-matching by code and bilingual name |
| `Tooltip` | `components/ui/tooltip.tsx` (existing) | Permission-tooltip on any disabled control; `ConfidenceBadge`'s reasoning disclosure |
| `Card`, `Button`, `Skeleton` | `components/ui/*` (existing shadcn primitives) | Region shells, actions, loading placeholders |
| `EmptyState`, `ErrorState` | `components/shared/*` (existing) | Per-region empty/error rendering — see `# States` |
| `Can` | `components/auth/can.tsx` (existing) | Wraps every mutating affordance named in `# Route & Access` |
| `WidgetErrorBoundary` | `components/shared/widget-error-boundary.tsx` (existing) | One per region (Status Strip, Tree, Rail, Nav Cards), isolating a single region's fetch failure from the other three |
| `ChartOfAccountsTree` | `components/accounting/chart-of-accounts-tree.tsx` (**new**) | The tree region's primary rendering — a virtualized, expandable `role="treegrid"` implementation, detailed below |
| `AccountingStatusStrip` | `components/accounting/accounting-status-strip.tsx` (**new**) | Composes the four `KpiTile`s, including the Period Status tile's close-readiness popover |
| `ModuleNavCard` | `components/accounting/module-nav-card.tsx` (**new**) | The four sibling-screen navigation cards, each a thin `Card` with a title, a one-line live stat, and a full-`Link` wrapper |
| `AccountingAiApprovalsRail` | `components/accounting/accounting-ai-approvals-rail.tsx` (**new**) | Composes the Pending AI and Approvals halves of the rail from `AIProposalPanel`/`ApprovalCard` |
| `AccountDetailSheet` | `components/accounting/account-detail-sheet.tsx` (**new**) | The read/edit/lifecycle-action surface opened from a tree row click |
| `QuickCreateAccountSheet` | `components/accounting/quick-create-account-sheet.tsx` (**new**) | The "New Account" primary action's form, Zod-validated |

The six "new" components are pure composition or, for `ChartOfAccountsTree`, one genuinely new interaction pattern — none introduces a design token, an API shape, or a permission key that does not already exist. `ApprovalCard`'s `kind` union gains two Accounting-scoped values this screen is the first to need — `account_reclassification` and `account_merge` — mapped to `accounting.accounts.reclassify` and `accounting.accounts.merge` respectively in that component's `PERMISSION_BY_KIND` lookup (`docs/frontend/COMPONENT_LIBRARY.md → ApprovalCard`); this is a documented extension of an existing component's discriminant, not a parallel implementation.

## `ChartOfAccountsTree`

No existing primitive in `COMPONENT_LIBRARY.md` renders a hierarchical, expandable data set — `DataTable` is deliberately flat, and `AccountPicker`'s own list is a flat, search-ranked combobox result set, not a browsable tree. This screen introduces the one new pattern, implementing the WAI-ARIA `treegrid` role explicitly, because a Chart of Accounts is read-mostly, deeply nested, and — per `docs/accounting/CHART_OF_ACCOUNTS.md → 7.1` — can legitimately reach 5,000 accounts and 8 levels of depth, which rules out a plain nested `<ul>` for both performance and accessibility reasons.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `shape` | `'tree' \| 'flat'` | yes | Toggled by the region's own view switch; `flat` renders the identical data through the existing `DataTable` instead (sortable by code/name/nature, better suited to bulk selection for Merge) |
| `filters` | `{ nature?: AccountNature; status?: AccountStatus; search?: string }` | yes | Mirrors `GET /api/v1/accounting/accounts`'s own query parameters one-to-one |
| `onSelectAccount` | `(accountId: number) => void` | yes | Opens `AccountDetailSheet` |
| `expandedIds` | `Set<number>` | yes | Controlled by the parent so a search match can programmatically expand its ancestor path (see `# Interactions & Flows`) |
| `onToggleExpand` | `(accountId: number) => void` | yes | |

```tsx
// components/accounting/chart-of-accounts-tree.tsx
"use client";

import { useMemo } from "react";
import { useVirtualizer } from "@tanstack/react-virtual";
import { useQuery } from "@tanstack/react-query";
import { accountingKeys } from "@/lib/query/keys";
import { fetchAccountsTree } from "@/lib/api/accounting";
import { AmountCell } from "@/components/accounting/amount-cell";
import { StatusPill } from "@/components/shared/status-pill";
import { Badge } from "@/components/ui/badge";
import { ConfidenceBadge } from "@/components/ai/confidence-badge";
import { ChevronRight } from "lucide-react";
import { cn } from "@/lib/utils";
import type { AccountNode, AccountTreeFilters } from "@/types/accounting";

/** Flattens the currently-expanded subset of the tree into a single virtualizable
 *  array — this is what lets a 5,000-row / 8-level tree stay smooth: the virtualizer
 *  never has to reason about nesting, only about a flat list whose members happen to
 *  carry a `depth` and a `hasChildren` flag. */
function flattenVisible(nodes: AccountNode[], expanded: Set<number>, depth = 0): (AccountNode & { depth: number })[] {
  return nodes.flatMap((node) => {
    const self = { ...node, depth };
    if (!node.children?.length || !expanded.has(node.id)) return [self];
    return [self, ...flattenVisible(node.children, expanded, depth + 1)];
  });
}

export function ChartOfAccountsTree({
  filters, onSelectAccount, expandedIds, onToggleExpand,
}: {
  filters: AccountTreeFilters;
  onSelectAccount: (id: number) => void;
  expandedIds: Set<number>;
  onToggleExpand: (id: number) => void;
}) {
  const { data, isPending } = useQuery({
    queryKey: accountingKeys.tree(filters),
    queryFn: () => fetchAccountsTree(filters),
    staleTime: 30_000,
  });

  const rows = useMemo(() => flattenVisible(data ?? [], expandedIds), [data, expandedIds]);
  const parentRef = useMemo(() => ({ current: null as HTMLDivElement | null }), []);
  const virtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 32, // Compact density row height, per DESIGN_LANGUAGE.md's Density Modes
    overscan: 12,
  });

  if (isPending) return <TreeSkeleton />;

  return (
    <div
      ref={(el) => { parentRef.current = el; }}
      role="treegrid"
      aria-label="Chart of Accounts"
      aria-rowcount={rows.length}
      className="max-h-[70vh] overflow-y-auto rounded-lg border border-ink-6"
    >
      <div style={{ height: virtualizer.getTotalSize(), position: "relative" }}>
        {virtualizer.getVirtualItems().map((virtualRow) => {
          const row = rows[virtualRow.index];
          const hasChildren = Boolean(row.children?.length);
          return (
            <div
              key={row.id}
              role="row"
              aria-level={row.depth + 1}
              aria-expanded={hasChildren ? expandedIds.has(row.id) : undefined}
              aria-rowindex={virtualRow.index + 1}
              className="absolute inset-x-0 flex items-center gap-2 border-t border-ink-6 text-caption hover:bg-ink-3"
              style={{ height: virtualRow.size, transform: `translateY(${virtualRow.start}px)`, paddingInlineStart: `${row.depth * 20 + 8}px` }}
            >
              {hasChildren ? (
                <button
                  type="button"
                  role="gridcell"
                  onClick={() => onToggleExpand(row.id)}
                  aria-label={expandedIds.has(row.id) ? "Collapse" : "Expand"}
                  className="shrink-0"
                >
                  <ChevronRight className={cn("size-3.5 transition-transform rtl:rotate-180", expandedIds.has(row.id) && "rotate-90 rtl:rotate-90")} />
                </button>
              ) : (
                <span className="w-3.5 shrink-0" />
              )}
              {row.hasOpenAiSuggestion && <span className="size-1.5 rounded-full bg-accent" aria-hidden />}
              <button
                type="button"
                role="gridcell"
                onClick={() => onSelectAccount(row.id)}
                className="flex flex-1 items-center gap-2 truncate text-start"
              >
                <span className="font-mono text-code-sm text-ink-9">{row.code}</span>
                <span className="truncate text-ink-11">{row.name}</span>
                {row.isControlAccount && <Badge size="sm">control</Badge>}
                {row.isSystem && <Badge size="sm" variant="outline">system</Badge>}
              </button>
              {!row.allowPosting ? (
                <span className="text-ink-8">—</span>
              ) : (
                <AmountCell amount={row.balance} currencyCode={row.currencyCode ?? "KWD"} className="w-32 shrink-0 text-right" />
              )}
              <StatusPill domain="account" status={row.status} size="sm" />
            </div>
          );
        })}
      </div>
    </div>
  );
}
```

Two decisions here carry real weight, not just illustration. First, the AI-provenance dot (`row.hasOpenAiSuggestion`) is the exact 6px `accent` gutter dot `docs/frontend/DESIGN_LANGUAGE.md → AI provenance in a table row` specifies platform-wide — never a colored row background — and disappears the moment the suggestion is accepted or dismissed, matching that rule's "provenance is visible until a decision is made, not forever." Second, `flattenVisible` is the standard technique for virtualizing a tree: the virtualizer only ever sees a flat array, so expand/collapse never has to re-derive scroll offsets from a nested structure, and the same function is reused, unchanged, if a future screen (e.g., a Warehouse location tree) needs the identical pattern — proposed then as a `COMPONENT_LIBRARY.md` addition rather than copy-pasted a second time, per Design Principle 8.

# Data & State

## Endpoints

Every endpoint below is `docs/accounting/CHART_OF_ACCOUNTS.md → 14 API Design`'s table, annotated with the hook and query key this screen wires it to. All are versioned under `/api/v1/accounting/accounts` (plus the sibling `/account-types`), carry `X-Company-Id`, and return the standard envelope.

| Purpose | Endpoint | Permission | Hook / query key |
|---|---|---|---|
| Tree/flat listing | `GET /accounting/accounts?shape=tree\|flat&status=&nature=&search=` | `.read` | `useAccountsTree(filters)` / `accountingKeys.tree(filters)` |
| One account, with balance | `GET /accounting/accounts/{id}` | `.read` | `useAccount(id)` / `accountingKeys.detail(id)` |
| Direct children (lazy expand) | `GET /accounting/accounts/{id}/children` | `.read` | `useAccountChildren(id)` — only called when a node's `children` array was omitted for size, see `# Performance` |
| Recursive subtree | `GET /accounting/accounts/{id}/subtree` | `.read` | `useAccountSubtree(id)` — Merge preview's "children will be reparented" calculation |
| Derived balance | `GET /accounting/accounts/{id}/balance` | `.read` | `useAccountBalance(id, asOf)` — Account Detail Sheet's Overview tab |
| Posted lines against this account | `GET /accounting/accounts/{id}/ledger` | `.read` | `useAccountLedger(id)` — Account Detail Sheet's Ledger tab, paginated |
| Audit trail | `GET /accounting/accounts/{id}/audit-history` | `.read` | `useAccountAuditHistory(id)` — Account Detail Sheet's History tab |
| Create | `POST /accounting/accounts` | `.create` | `useCreateAccount()` — Quick Create Sheet |
| Bulk create | `POST /accounting/accounts/bulk` | `.create` | `useBulkCreateAccounts()` — Import review's commit step |
| Update | `PATCH /accounting/accounts/{id}` | `.update` | `useUpdateAccount(id)` — Detail Sheet's Edit mode |
| Reclassify | `POST /accounting/accounts/{id}/reclassify` | `.reclassify` | `useReclassifyAccount()` — impact-preview dialog |
| Deactivate / Reactivate | `POST /accounting/accounts/{id}/deactivate` \| `/reactivate` | `.update` | `useSetAccountStatus()` |
| Delete | `DELETE /accounting/accounts/{id}` | `.delete` | `useDeleteAccount()` |
| Merge | `POST /accounting/accounts/merge` | `.merge` | `useMergeAccounts()` |
| Opening balances | `POST /accounting/accounts/opening-balances` | `.set_opening_balance` | `useSetOpeningBalances()` |
| List templates | `GET /accounting/accounts/templates` | `.read` | `useAccountTemplates()` — Apply Template flow |
| Apply template | `POST /accounting/accounts/apply-template` | `.create` | `useApplyAccountTemplate()` |
| Import | `POST /accounting/accounts/import` | `.import` | `useImportAccounts()` |
| Export | `GET /accounting/accounts/export` | `.export` | `useExportAccounts()` — not cached, async job per `# Performance` |
| Account types | `GET /accounting/account-types` | `.read` | `useAccountTypes()` — Quick Create Sheet's Type field, filtered by selected nature |
| Pending AI suggestions | `GET /accounting/accounts/ai-suggestions` | `.review_ai_suggestions` | `useAccountAiSuggestions()` — the rail's "Pending AI" queue |
| Accept a suggestion | `POST /accounting/accounts/ai-suggestions/{id}/accept` | `.update` (or the specific action's own key — `.reclassify`/`.merge`) | `useAcceptAiSuggestion()` |
| Reject a suggestion | `POST /accounting/accounts/ai-suggestions/{id}/reject` | `.update` | `useRejectAiSuggestion()` |

Cross-module endpoints power the Status Strip's remaining tiles and the Module Nav Cards row's live stats — each reads a sibling module's own list endpoint at `per_page=1` purely for its `meta.pagination.total`, never re-deriving a figure that module's own screen already owns:

| Tile / Card | Endpoint | Permission |
|---|---|---|
| Pending Approvals (Status Strip) | `GET /api/v1/approvals?filter[subject_type][in]=journal_entry,account_reclassification,account_merge,account_opening_balance&status=pending` | `ai.approve` to view actionably, `reports.read` read-only |
| Current Fiscal Period (Status Strip) | `GET /api/v1/accounting/fiscal-periods?filter[status][in]=open,closing&sort=-end_date&per_page=1` | `accounting.read` |
| Period close readiness | `GET /api/v1/accounting/fiscal-periods/{id}/close-readiness` | `accounting.read` |
| Journal Entries nav card | `GET /api/v1/accounting/journal-entries?filter[status][in]=draft,pending_approval&per_page=1` | `accounting.journal.read` |
| General Ledger nav card | `GET /api/v1/accounting/ledger-entries?sort=-posted_at&per_page=1` | `accounting.ledger.read` |
| Trial Balance nav card | `GET /api/v1/accounting/trial-balance?is_current=true&per_page=1` | `accounting.trial_balance.read` |
| Financial Statements nav card | `GET /api/v1/accounting/financial-statements/snapshots?statement_type=balance_sheet&per_page=1&sort=-generated_at` | `accounting.financial-statements.read` |

The **close-readiness** endpoint is this document's own addition to the contract `docs/accounting/GENERAL_LEDGER.md → Business Goal 3` states but does not yet wire end to end ("provide a real-time 'period close readiness' score"); it aggregates three server-known facts into one composite the CFO Agent and Auditor already have the raw inputs for — open/draft journal entries dated inside the period, any unresolved `trial_balance_ai_findings` of type `forecasted_error`/`anomaly` for the period, and whether a Trial Balance snapshot has been generated and approved yet — returning `{ score: number (0–100), confidence: number, reasoning: string, blocking_items: { type: string; count: number; href: string }[] }`, rendered exactly like any other AI-sourced figure (`# AI Integration`).

## Query keys

```ts
// lib/query/keys.ts (Accounting-hub-scoped factories)
export const accountingKeys = {
  all: ["accounting"] as const,
  tree: (filters: AccountTreeFilters) => [...accountingKeys.all, "tree", filters] as const,
  detail: (id: number) => [...accountingKeys.all, "account", id] as const,
  children: (id: number) => [...accountingKeys.detail(id), "children"] as const,
  balance: (id: number, asOf?: string) => [...accountingKeys.detail(id), "balance", asOf ?? "current"] as const,
  ledger: (id: number) => [...accountingKeys.detail(id), "ledger"] as const,
  auditHistory: (id: number) => [...accountingKeys.detail(id), "audit-history"] as const,
  aiSuggestions: () => [...accountingKeys.all, "ai-suggestions"] as const,
  accountTypes: () => [...accountingKeys.all, "account-types"] as const,
};

export const accountingHubKeys = {
  statusStrip: () => ["accounting-hub", "status-strip"] as const,
  currentPeriod: () => ["accounting-hub", "current-period"] as const,
  closeReadiness: (fiscalPeriodId: number) => ["accounting-hub", "close-readiness", fiscalPeriodId] as const,
  moduleNavStats: () => ["accounting-hub", "module-nav-stats"] as const,
};
```

## Cache tuning

Following `docs/frontend/DASHBOARD.md → Query keys and cache tuning`'s data-class table exactly, applied to this screen's own resources:

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Structural/reference | `accountingKeys.tree`, `.accountTypes` | `30_000` | The CoA changes far less often than journal entries; a 30s ceiling is generous, corrected immediately by realtime on any `account.*` domain event |
| Live/derived figures | `.balance`, hub's Total Accounts / Pending Approvals tiles | `0` (always stale) | Corrected primarily via realtime invalidation, not polling |
| AI feeds | `.aiSuggestions`, close-readiness | `10_000`, `refetchOnWindowFocus: true` | Matches `docs/frontend/FRONTEND_ARCHITECTURE.md`'s AI-feed row verbatim — a returning user should see what the agents produced while away |
| Sibling-module nav stats | `accountingHubKeys.moduleNavStats` | `30_000` | Cheap `per_page=1` counts; not worth sub-minute polling, corrected on the relevant domain event (`journal.posted`, `trial_balance.generated`, …) |

## Realtime

This screen subscribes to the same private, company-scoped Reverb channel `docs/frontend/JOURNAL_ENTRIES.md → Realtime` already opens for its own screen (`echo.private('company.{id}.accounting')`, which resolves on the wire to `private-company.{id}.accounting` per the platform's channel convention), plus the shared Approvals channel:

```ts
// hooks/accounting/use-accounting-hub-realtime.ts
"use client";
import { useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { echo } from "@/lib/realtime/echo";
import { accountingKeys, accountingHubKeys } from "@/lib/query/keys";

export function useAccountingHubRealtime(companyId: string) {
  const queryClient = useQueryClient();

  useEffect(() => {
    const accounting = echo.private(`company.${companyId}.accounting`);
    const approvals = echo.private(`company.${companyId}.approvals`);

    for (const event of [".account.created", ".account.updated", ".account.reclassified", ".account.merged"]) {
      accounting.listen(event, () =>
        queryClient.invalidateQueries({ queryKey: accountingKeys.all, refetchType: "inactive" }),
      );
    }
    accounting.listen(".account.ai_suggestion_created", () =>
      queryClient.invalidateQueries({ queryKey: accountingKeys.aiSuggestions() }),
    );
    for (const event of [".journal.posted", ".journal.submitted_for_approval", ".journal.approved"]) {
      accounting.listen(event, () =>
        queryClient.invalidateQueries({ queryKey: accountingHubKeys.moduleNavStats(), refetchType: "inactive" }),
      );
    }
    approvals.listen(".approval.created", () =>
      queryClient.invalidateQueries({ queryKey: ["approvals"], refetchType: "inactive" }),
    );

    return () => { echo.leave(`company.${companyId}.accounting`); echo.leave(`company.${companyId}.approvals`); };
  }, [companyId, queryClient]);
}
```

The tree's currently-expanded, on-screen subtree is patched surgically via `setQueryData` on `.account.updated` (so a status flip or a balance change another user just made reflects within the same second, per `docs/frontend/DESIGN_LANGUAGE.md → Motion → Realtime row update`'s "row flashes `accent-subtle` then eases back" rule), while a collapsed or off-screen branch is only marked `invalidateQueries(..., { refetchType: "inactive" })` — refetched the next time it becomes visible, never yanked out from under a scrolling user.

## AI agents feeding this screen

Per `docs/accounting/CHART_OF_ACCOUNTS.md → 16 AI Responsibilities`, four agent behaviors surface here, each through the ordinary create/read API this screen already calls — never a bespoke AI endpoint this document has to special-case:

| Agent | What appears on this screen | Autonomy |
|---|---|---|
| General Accountant | Duplicate-account flags (post-hoc, on existing rows), "create account X" / "split account Y" recommendations, missing-account batch at onboarding | Suggest-only — lands in the Pending AI queue as a `GET .../ai-suggestions` row |
| CFO Agent | Co-produces the "Recommendations" category alongside the General Accountant, and computes half of the Period Status tile's close-readiness composite | Suggest-only |
| Auditor / Fraud Detection | Risk-detection findings — a posting inconsistent with an account's nature, a dormant-then-active account — surface as an inline `⚑` on the affected row with a tooltip, cross-linking to the full finding in `docs/frontend/AI_COMMAND_CENTER.md`'s Detected Risks radar rather than duplicating the card here | Suggest-only, escalation-only, never blocks |
| Document AI / OCR Agent | Per-line `ai_suggested_account_id` mapping — surfaces inside the Import review screen, not on this hub directly (the hub's Import entry point routes to it) | Suggest-only |

# Interactions & Flows

**Opening the screen.** First paint renders the shell (Sidebar, Topbar, tab bar, Page Header) immediately; the Status Strip typically resolves within one Redis round trip; the tree region, AI & Approvals rail, and Module Nav Cards row stream in independently as their own Suspense boundaries settle, each replacing its skeleton in place with no layout shift, exactly as `docs/frontend/DASHBOARD.md → Server-first paint, then Suspense per region` specifies.

**Switching Tree/Flat view.** A segmented control at the region's own header toggles `ChartOfAccountsTree`'s `shape` prop between `tree` (structural browsing, the default) and `flat` (a sortable `DataTable`, better suited to a bulk Merge selection or a code-order scan). Both shapes read the same `filters` object and the same query key family, so switching view never re-triggers a network fetch for data already in cache.

**Searching.** The `Command`-based search input debounces at 250ms and calls the same `search` parameter `AccountPicker` already uses. A match deep in a collapsed branch auto-expands every ancestor on its `path` (the denormalized materialized path `docs/accounting/CHART_OF_ACCOUNTS.md → 13.3` already stores) and scrolls the matched row into view — a search that returns a result but leaves it invisible behind three collapsed parents is treated as a defect, not an acceptable simplification, mirroring `docs/frontend/GENERAL_LEDGER.md`'s own "every number drills down" standard applied to navigation rather than lineage.

**Expanding a node.** Small subtrees (already included in the initial `shape=tree` response) expand instantly from cached data. A node flagged by the API as having a large subtree (a heuristic the backend applies per `docs/accounting/CHART_OF_ACCOUNTS.md → 13.5`'s recursive-CTE cost) omits its `children` array and sets `hasMoreChildren: true`; expanding that node triggers `GET /accounting/accounts/{id}/children` instead, so a single "Bank Accounts" node with 40 branch sub-accounts never forces the whole tree's first paint to wait on it.

**Opening the Account Detail Sheet.** Clicking any row opens `AccountDetailSheet` from the inline-end edge with three tabs — Overview (fields, balance via `AmountCell`, and, only when `ai_suggested_type_id` is set and differs from the confirmed type, an inline "AI suggested: 6400 · Bad Debt Expense" caption exactly matching the pattern `docs/frontend/JOURNAL_ENTRIES.md → AccountPicker` already uses for the identical field), Ledger (a capped, most-recent-first feed from `.../ledger` with a "View in General Ledger" link that deep-links `/accounting/ledger?account=<id>`), and History (the full `audit_logs` trail from `.../audit-history`). Edit, Reclassify, Deactivate/Reactivate, Merge, and Delete all live in this sheet's own action row and overflow menu, each individually permission-gated per `# Route & Access`.

**Quick Create.** "New Account" opens `QuickCreateAccountSheet` rather than navigating to a full page — a deliberate departure from `docs/frontend/DASHBOARD.md`'s "sensitive-adjacent creation flows are always full pages" rule, justified because creating a non-postable-yet, zero-balance account row moves no money and posts no fact (`docs/accounting/CHART_OF_ACCOUNTS.md`'s own workflow in `§ 12.3` is a short form, not a multi-section document). The sheet's schema mirrors the backend's `StoreAccountRequest` validation field-for-field:

```ts
// lib/validation/account.ts
import { z } from "zod";

export const AccountSchema = z.object({
  parent_id: z.number().int().positive().nullable(),
  account_type_id: z.number().int().positive({ message: "accountForm.errors.typeRequired" }),
  name_en: z.string().trim().min(1).max(255, { message: "accountForm.errors.nameRequired" }),
  name_ar: z.string().trim().min(1).max(255, { message: "accountForm.errors.nameArRequired" }),
  description: z.string().max(2000).optional(),
  currency_code: z.string().length(3).nullable().optional(),
  allow_posting: z.boolean().default(true),
  tax_category: z.enum([
    "none", "vat_standard_output", "vat_standard_input", "vat_zero_rated", "vat_exempt",
    "vat_out_of_scope", "zakat_base_asset", "zakat_excluded", "non_deductible_expense", "withholding_applicable",
  ]).default("none"),
  default_cost_center_id: z.number().int().positive().nullable().optional(),
  tags: z.array(z.string().min(1).max(40)).max(20).default([]),
});
export type AccountInput = z.infer<typeof AccountSchema>;
```

As the user types `name_en`/`name_ar`, the sheet fires a debounced, non-blocking call the Duplicate Detection agent answers inline (`docs/accounting/CHART_OF_ACCOUNTS.md → 12.3`, "AI duplicate-detection runs on name_en+name_ar as user types — inline warning, never blocking"); a ≥0.85 similarity match renders a dismissible `accent`-bordered inline note above the Name fields, never a modal that would block continued typing. Submitting maps 1:1 to `POST /accounting/accounts`; a `422` response's field-level `errors[]` is mapped back onto the same Zod-shaped fields via `setError`, so a `PARENT_NATURE_MISMATCH` from the server surfaces on the Parent field exactly where a client-side check would have, had one existed — this form performs no client-side nature-matching logic of its own, per the platform's "the frontend computes nothing that matters" rule; the fast, friendly rejection here is limited to presence/length/format checks that mirror, and never substitute for, the server's own.

**Reclassify.** Selecting "Reclassify" on an account with posted history opens a `Dialog` showing the exact server-computed impact preview text (`docs/accounting/CHART_OF_ACCOUNTS.md → 12.4`: "This will move X posted entries totaling Y … from [old type] to [new type]…"), a new-type `Combobox` constrained to the same `nature`, and a mandatory reason `Textarea` — the submit button stays disabled until the reason is non-empty, matching `ApprovalCard`'s own reject-reason discipline.

**Merge.** Selecting two accounts (via the Flat view's row checkboxes, or directly from a Pending AI duplicate suggestion's "Review" action) opens the Merge dialog with survivor/loser `AccountPicker`s pre-filled from the selection, the server-computed preview ("N journal_lines will be reassigned, opening balances will be summed, loser's children will be reparented"), and the same mandatory-reason discipline as Reclassify.

**Accepting or rejecting a Pending AI item.** Each `AIProposalPanel` card in the rail exposes the platform's exact three-button pattern. "Do it" is present only when `can_execute_directly` is `true` (server-computed, never a client-derived autonomy guess, per `docs/frontend/AI_COMMAND_CENTER.md → AI Integration`) — in practice this is rare on this screen, since account mutations with posted history nearly always require the reason-carrying Reclassify/Merge dialogs above rather than a one-click accept; "Send for approval" opens the identical Reclassify/Merge dialog pre-filled with the AI's own reasoning text in the reason field, editable before confirming (`docs/accounting/CHART_OF_ACCOUNTS.md → 16`'s stated hard boundary: "pre-fills the AI's reasoning text into that field for the human to edit or confirm"); "Dismiss" requires a reason and feeds the Learning responsibility's per-company weighting.

**Acting on an Approval.** Each `ApprovalCard` in the rail's Approvals half behaves exactly as `docs/frontend/AI_COMMAND_CENTER.md → Approval Center` specifies — approving here advances that request's own approval chain; it never posts a journal entry, commits a reclassification, or executes a merge directly from this rail. "View all in Approvals" always leads to the unfiltered `/approvals` queue for a card whose alternatives or full detail this condensed rail intentionally omits.

**Navigating to a sibling screen.** Every `ModuleNavCard` and every tab in the shared bar is a real `<Link>`, never an intercepted route or a modal — consistent with `docs/frontend/JOURNAL_ENTRIES.md → Route & Access`'s "there is deliberately no separate edit route" philosophy applied in the opposite direction here: leaving the hub is always a full, linkable navigation, never a partial in-place swap.

# AI Integration

This hub's AI surface has two distinct halves, and both obey the platform-wide contract in full: confidence and reasoning are never hidden, visual provenance is consistent, and the three-button pattern is exact, never approximated.

- **The tree's own inline provenance.** A 6px `accent` dot in a row's leading gutter (never a colored row background) marks an account with an open AI suggestion; hovering or focusing it surfaces a `Tooltip` reading, e.g., `"92% confidence — General Accountant Agent"` (`docs/frontend/DESIGN_LANGUAGE.md → AI provenance in a table row`), and the dot disappears the instant a human accepts or dismisses the underlying suggestion. A `⚑` on a row instead of a dot marks an open Risk Detection finding — visually distinct because a risk is not the same category of thing as a proposal; it is escalation, not an offer.
- **The Pending AI queue.** Every card renders through `AIProposalPanel` exactly as `docs/frontend/COMPONENT_LIBRARY.md` specifies — `AgentAvatar` naming the originating agent (never an anonymous "AI"), `ConfidenceBadge` with the reasoning available on hover/focus, an expandable alternatives list (per `docs/ai/agents/ACCOUNTANT_AGENT.md`'s stated "no hallucinated accounting entries" rule, every suggestion here cites the specific document, event, or historical pattern that grounded it), and the exact three-button footer. Below 60% confidence, "Do it" is disabled regardless of the item's configured autonomy tier, matching `ConfidenceBadge`'s platform-wide floor.
- **The Approvals queue.** Every row renders through `ApprovalCard`, including the two Accounting-specific `kind` values this screen introduces (`account_reclassification`, `account_merge`); `confidence` is populated only when the underlying request originated from an AI suggestion rather than a human's own action, and `requestedBy` is correspondingly omitted for a pure AI proposal, per that component's own documented contract.
- **The Period Status tile's close-readiness score is AI-computed and says so.** It renders through the same `KpiTile` shape every other Status Strip tile uses, but — because it is a CFO Agent/Auditor composite rather than a deterministic SQL aggregate — it always carries a `ConfidenceBadge`, unlike the Total Accounts or Pending Approvals counts beside it, which are plain ledger facts and carry none. This is the one place on this screen where two adjacent tiles in the same row look almost identical but differ in this one specific way, and that difference is deliberate: a user should never have to guess whether a number came from a query or an agent.
- **Hard boundaries restated for this screen.** The AI service-account identity is capped, at every company, to read and suggestion-producing endpoints only (`docs/accounting/CHART_OF_ACCOUNTS.md → 15.1`'s legend) — it can never reach `create`, `update`, `reclassify`, `merge`, or `delete` directly, regardless of confidence or company automation policy. Every acceptance on this screen — clicking "Do it," confirming a pre-filled Reclassify/Merge dialog, applying a template — executes through the exact same `POST`/`PATCH`/`DELETE` endpoints a human typing the same change would call, under that human's own permissions, never a privileged AI-only code path.

# States

Every region has its own loading, empty, and error presentation — no region blocks another, and none renders a bare spinner as its primary loading state, per `docs/frontend/DESIGN_LANGUAGE.md → Motion → Skeleton loading`.

| Region | Loading | Empty | Error |
|---|---|---|---|
| Status Strip | Four skeleton tiles matching `KpiTile`'s own `loading` prop | N/A for Total Accounts/Pending Approvals (always computable); Period Status shows a distinct "Set up your fiscal year" variant with a setup CTA for a brand-new company with no `fiscal_years` row yet | Per-tile inline "Couldn't load this figure — Retry"; the other three tiles remain interactive |
| Chart of Accounts | `TreeSkeleton` — shimmer rows at the Compact-density row height, matching the loaded tree's exact geometry | Brand-new company with zero accounts: a dedicated "Set up your Chart of Accounts" card offering "Apply a template" and "Import from another system," never the generic "no results" a filtered search would show; a search or filter that matches nothing shows a distinct, narrower "No accounts match your search" message with a "Clear filters" action | Region-level `<ErrorBoundary>` renders an inline "Couldn't load the Chart of Accounts — Retry" card; a failed lazy-expand on one node shows a small inline retry affixed to that node only, without collapsing the rest of the tree |
| AI & Approvals Rail | Skeleton cards matching `AIProposalPanel`/`ApprovalCard`'s final shape | Calm "You're all caught up — no pending suggestions or approvals right now" message, explicitly not styled as an error or a warning, matching `docs/frontend/DASHBOARD.md`'s own AI Summary Rail empty copy | A distinct "AI insights are temporarily unavailable" state (not a generic error) when the AI engine returns `503`, respecting any `Retry-After` header; the Approvals half fails and recovers independently of the Pending AI half, since they hit different endpoints |
| Module Nav Cards | Four skeleton cards | Never truly empty — a card whose sibling module the viewer cannot read at all is omitted (existence-sensitive), not shown empty | Per-card inline retry; a card's own navigation link remains functional even if its live stat fails to load, since the link points at the sibling screen's own first-paint fetch, not at this hub's cached figure |
| Account Detail Sheet | Skeleton matching the Overview tab's field layout | N/A | Inline retry within the sheet; the sheet itself never fails to open, only its content |

A route-level `error.tsx` remains the outermost safety net for a genuinely unexpected failure (the session itself becoming invalid mid-render), but per the three-granularity model this should essentially never fire for an individual region's ordinary `4xx`/`5xx` — those are caught and handled inline, one region at a time, exactly as `docs/frontend/DASHBOARD.md → States` establishes.

# Responsive Behavior

Following `docs/frontend/LAYOUT_SYSTEM.md`'s per-template responsive table for the Dashboard Template, applied to this screen's specific regions:

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | Sub-nav tab bar becomes a horizontal-scroll strip (no wrapping, snap-aligned); Status Strip becomes a horizontal-scroll carousel, one tile per screen-width; Chart of Accounts region renders full-width, single column, with the AI & Approvals Rail and Module Nav Cards stacking below it in that order; a deeply nested tree row's indentation is capped at 3 visual levels with a "…in Current Assets ▸ Bank Accounts" breadcrumb prefix replacing further indentation, so a level-7 posting account never scrolls off the right edge of a 375px viewport |
| `md` (768–1023px) | Status Strip becomes two tiles per row; Chart of Accounts and the Rail remain stacked, full width |
| `lg` (1024–1279px) | Status Strip becomes a full row; the Chart-of-Accounts/Rail pair activates its 8/4 split; Module Nav Cards become a 2×2 grid |
| `xl`+ (≥1280px) | Full bento layout as drawn in `# Layout & Regions`; Module Nav Cards become a single row of four; the platform-wide AI Rail companion panel may dock inline alongside this hub's own AI & Approvals Rail without competing for the same column |

`ChartOfAccountsTree` and `ModuleNavCard` use Tailwind v4 container queries (`@container`), not only viewport breakpoints, so the same tile reads correctly whether it is one-of-four in a full desktop row or full-width on a phone, matching `docs/frontend/DASHBOARD.md`'s own container-query convention for `KpiTile`. The Chart of Accounts region's own horizontal space is never collapsed to a card stack even on mobile, unlike `docs/frontend/LAYOUT_SYSTEM.md`'s List Page Template rule for ordinary resource lists — a tree is read as a structure, not a table of independent rows, so it stays a tree at every width, with row content eliding (never wrapping) rather than reflowing.

# RTL & Localization

Every rule below is inherited from `docs/frontend/DESIGN_LANGUAGE.md` and `docs/frontend/LAYOUT_SYSTEM.md`'s RTL contract, applied concretely to this screen's specific content.

- **Logical properties only.** `ChartOfAccountsTree`'s indentation uses `paddingInlineStart`, never `paddingLeft` — a level-3 account indents from the reading-start edge in both directions, so the tree's shape is identical in Arabic, only mirrored. The Status Strip's tile order, the Module Nav Cards row, and the Rail's card layout all use `ms-*`/`me-*`/`text-start`/`text-end` exclusively.
- **Numerals, account codes, and currency codes never mirror.** Every `AmountCell` balance in the tree, the Status Strip's Total Accounts count, and every account `code` (`1101.001`) renders inside a `dir="ltr"` / `unicode-bidi: isolate` span, per `docs/frontend/DESIGN_LANGUAGE.md → Typography → Tabular numerals`'s `<Amount>` primitive — `1101.001 · Bank Accounts – NBK Current` reads its code left-to-right even inside a fully Arabic row.
- **Bilingual names are the norm, not the exception, on this specific screen.** Unlike most list screens where a record has one name, every account row here natively carries both `name_en` and `name_ar` as first-class fields (`docs/accounting/CHART_OF_ACCOUNTS.md → 9`); the tree renders whichever matches the active locale, and the Quick Create/Edit forms always show both fields simultaneously, in both languages, never hiding the "other" language's field behind a toggle — a Kuwaiti bookkeeper naming a new bank account types both `name_ar` and `name_en` in the same short form, regardless of which language their own UI is currently set to.
- **The tree's expand/collapse chevron is directional; the AI-provenance dot and the account-code monogram are not.** `ChevronRight` mirrors via `rtl:rotate-180` (and rotates a further 90° when expanded, in either direction) per `docs/frontend/LAYOUT_SYSTEM.md → RTL Layout Mirroring → Rule 4`; the accent dot, the `⚑` risk flag, and every `StatusPill` dot are static glyphs that never flip.

| Context | English | Arabic |
|---|---|---|
| Screen title | Accounting | المحاسبة |
| Tab | Chart of Accounts | دليل الحسابات |
| Primary action | New Account | حساب جديد |
| Row action | Reclassify | إعادة تصنيف |
| Row action | Merge accounts | دمج الحسابات |
| Status Strip tile | Pending AI Suggestions | مقترحات الذكاء الاصطناعي المعلّقة |
| Status Strip tile | Pending Approvals | الاعتمادات المعلّقة |
| Period Status | Jul 2026 · Open · 62% ready | يوليو 2026 · مفتوحة · جاهزية 62% |
| Empty state | Set up your Chart of Accounts | إنشاء دليل الحسابات |
| Empty state | You're all caught up — no pending suggestions or approvals right now. | كل شيء تحت السيطرة — لا توجد مقترحات أو اعتمادات معلّقة الآن. |
| AI proposal | Suggested by the General Accountant Agent — 92% confidence. | مقترح من وكيل المحاسب العام — بثقة 92%. |

Arabic copy is authored directly by a fluent professional-register writer, not machine-translated, per `docs/frontend/DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief — the same discipline `docs/frontend/DASHBOARD.md` and `docs/frontend/AI_COMMAND_CENTER.md` apply, extended here to the accounting terminology `docs/accounting/CHART_OF_ACCOUNTS.md` itself already establishes (دليل الحسابات for Chart of Accounts, تصنيف for classification, دمج for merge).

# Dark Mode

This screen introduces no new color, elevation, or radius token — every surface resolves through the same `:root[data-theme="dark"]` remap `docs/frontend/DESIGN_LANGUAGE.md → Dark mode strategy` defines, verified per-component rather than assumed:

- **The tree's row hover and selected states** use `ink-3`/`ink-4` in light mode and their dark-remapped equivalents; elevation gets *lighter*, not darker, in dark mode, matching the platform's "physical light" strategy rather than a naive inversion.
- **The accent-dot AI provenance marker and the `⚑` risk flag** use the same `accent`/`warning` tokens in both themes, remapped for contrast — never a separate dark-only hue, per `docs/frontend/DESIGN_LANGUAGE.md → Color & Ink`'s "the accent is also the AI's signature" rule.
- **`StatusPill`'s active/inactive/archived tones** and the Period Status tile's close-readiness band colors resolve through the same `success`/`warning`/`danger` tokens in both themes.

Every Storybook story for the four new composed components (`ChartOfAccountsTree`, `AccountingStatusStrip`, `ModuleNavCard`, `AccountingAiApprovalsRail`) ships the platform's standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this screen's own Playwright suite captures the same four-way screenshot set at the route level, per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s testing convention.

# Accessibility

This screen targets WCAG 2.1 AA as a floor, identically in both languages and both themes, with the following screen-specific applications of the platform's general rules.

**The tree is a real `treegrid`, not a styled list.** `ChartOfAccountsTree`'s DOM carries `role="treegrid"`, `aria-level` per row, `aria-expanded` on every branch node, and `aria-rowindex`/`aria-rowcount` for the virtualized set — the canonical WAI-ARIA pattern for exactly this shape of data, so a screen-reader user's rotor/table-navigation commands enumerate the structure correctly rather than reading it as an undifferentiated list of 5,000 flat rows.

**Keyboard path reuses the platform's one canonical grid-navigation implementation, extended.** `docs/frontend/JOURNAL_ENTRIES.md → JournalLineGrid` names its `useRovingGrid` utility as the canonical roving-tabindex implementation, explicitly reused verbatim by the Bank Reconciliation matching grid; this screen extends that same utility with tree semantics rather than writing a third bespoke handler, per Design Principle 8. Concretely: `↓`/`↑` move between visible rows, `→` expands a collapsed branch (or moves focus to the first child if already expanded), `←` collapses an expanded branch (or moves focus to the parent if already collapsed), `Enter` opens `AccountDetailSheet` for the focused row, and `Home`/`End` jump to the first/last visible row — matching the WAI-ARIA Authoring Practices treegrid pattern exactly, with no control on this screen requiring a mouse to reach or activate.

**Realtime updates announce politely, never assertively.** A `.account.updated` patch arriving over the Reverb channel, or a new card appearing in the AI & Approvals Rail, uses `aria-live="polite"` exclusively, matching `docs/frontend/DASHBOARD.md`'s and `docs/frontend/AI_COMMAND_CENTER.md`'s identical rule for their own realtime feeds.

**Color is never the only channel.** The AI-provenance dot is always paired with a `Tooltip` naming the confidence and agent (never color alone); the `⚑` risk flag is always paired with an icon and a tooltip, never a bare tinted background; `StatusPill`'s tone is always paired with a text label.

**Permission-gated controls are legible, not mysterious.** A row-overflow action whose permission is absent is omitted entirely (existence-sensitive, per `docs/frontend/COMPONENT_LIBRARY.md`'s row-action precedent); the one documented exception on this screen is the Period Status tile's "Close this period" action, which — because its *existence* (a period can be closed) is not itself sensitive information, only the *ability to act* is — renders disabled with a `Tooltip` reading "Requires `accounting.period.lock`" rather than being hidden, mirroring `docs/frontend/TRIAL_BALANCE.md`'s identical treatment of its own Generate button.

**Focus management on navigation.** Clicking a Module Nav Card or a sub-nav tab navigates to a new route rather than mutating this page in place, so focus lands on the destination's own heading via the platform's standard route-change focus reset; opening `AccountDetailSheet` or `QuickCreateAccountSheet` traps focus within the sheet (Radix `Dialog` primitive underneath `Sheet`) and returns it to the triggering row or button on close, with no bespoke focus-trap logic written for this screen specifically.

# Performance

- **Streamed, not blocking.** Per `# Data & State`, every region is its own `Suspense` boundary; the slowest region (typically the AI & Approvals Rail, if an agent run is still in flight) never delays the Chart of Accounts region's first paint, and a failure in one region never takes down another, per the `react-error-boundary`-per-region pattern `docs/frontend/DASHBOARD.md` and `docs/frontend/AI_COMMAND_CENTER.md` both establish.
- **Virtualized from the first line of this specification, not as a follow-up pass.** `ChartOfAccountsTree`'s `flattenVisible` + `@tanstack/react-virtual` combination targets the same class of scale `docs/accounting/CHART_OF_ACCOUNTS.md → Business Goal G2` states outright ("account tree of 5,000 accounts and 8 levels of depth returns a filtered list in <300ms p95") — the tree never renders more DOM rows than fit the viewport plus a 12-row overscan, regardless of how many nodes are logically expanded.
- **Lazy subtree fetch bounds the worst case.** A node whose subtree the backend judges large omits `children` from the initial payload and is fetched on demand via `.../children`, so a single dense branch (a "Bank Accounts" group with forty branch sub-accounts) never inflates the first-paint response for every other, smaller branch beside it.
- **Search is server-ranked, never a client-side filter over an unbounded set.** `GET /accounting/accounts?search=` is the same endpoint `docs/accounting/CHART_OF_ACCOUNTS.md → Business Goal G10` targets for typeahead latency (<150ms p95) — this screen's search box calls it directly rather than filtering an already-fetched, potentially-truncated tree in the browser.
- **Bundle budget.** This route's shell (excluding the individually `next/dynamic`-split `ChartOfAccountsTree` chunk, which pulls in `@tanstack/react-virtual`) is held to the platform's stated first-load JS budget for this route, enforced in CI against `performance-budgets.json` — a role whose permissions never surface the tree at all (there is none on this screen, since `accounting.accounts.read` is required to reach the route at all, but the pattern is stated here for consistency with sibling documents) would in principle never pay for the virtualizer, matching `docs/frontend/DASHBOARD.md`'s identical rationale for its own chart dependency.
- **Cache-then-realtime, not poll.** Every region's `staleTime` is corrected primarily by the Reverb channel's targeted invalidations (`# Data & State`), not by a refetch interval — a company with a large, mostly-static Chart of Accounts never pays a background-polling cost proportional to tree size.
- **Web Vitals are tracked against a company-size-band baseline**, exactly as `docs/frontend/DASHBOARD.md → Performance` specifies platform-wide — a company with 5,000 accounts and a company with 40 have legitimately different tail latencies on this exact screen, and the monitoring distinguishes a genuine regression from an expected, scale-dependent one.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A company with 5,000+ accounts across 8 levels of depth | Server-side search and lazy subtree fetch are the only supported ways to reach a deep node quickly; the tree never attempts to eagerly load the full structure client-side, per `# Performance` |
| Two users reclassify or edit the same account concurrently | The client re-fetches the account fresh immediately before opening the impact-preview dialog and diffs it against any already-cached data; on divergence, the dialog shows "This account's data changed — Refresh to see the latest" rather than silently confirming a reclassification against a stale nature/type, mirroring `docs/frontend/AI_COMMAND_CENTER.md → Edge Cases`'s identical conflict posture for a changed approval target |
| Delete attempted on an account that turns out to have children or postings by the time the request lands | The server's `409 ACCOUNT_HAS_CHILDREN` (or the analogous history check) is caught and rendered as an inline dialog error offering "Set to Inactive instead," never a raw toast — the client never pre-emptively disables Delete based on a possibly-stale local read of "zero children," since another user's concurrent create could have added one after this screen's last fetch |
| An AI suggestion (e.g., "merge these two accounts") references an account another user has since deactivated or deleted | The suggestion is invalidated server-side and the rail shows "This suggestion is no longer available" in its place rather than surfacing a stale card whose Accept action would fail |
| A brand-new company, first login, zero accounts, zero fiscal years | The Chart of Accounts region renders its dedicated setup empty state (`# States`); the Period Status tile renders its own "Set up your fiscal year" variant; neither the AI & Approvals Rail nor the Module Nav Cards' sibling-screen stats attempt a query that would 404 or 422 against nonexistent data — each is `enabled: false` until the relevant prerequisite exists |
| A role holds `accounting.accounts.read` but none of the sibling modules' read permissions (a narrow custom role) | Each `ModuleNavCard` for a sibling the role cannot read is omitted entirely (existence-sensitive, per `docs/frontend/DASHBOARD.md`'s Quick Actions precedent); the Chart of Accounts region and its own actions remain fully usable regardless |
| Search matches an account nested inside a currently-collapsed ancestor | The tree programmatically expands every ancestor on the match's `path` and scrolls it into view in one action, never leaving a "found but invisible" result |
| A company applies a second (industry) template after already customizing accounts manually | The Apply Template flow is always an additive merge with a review-before-commit screen, never a silent overwrite — a tenant's manual renames or additions are never clobbered by a later template application, per `docs/accounting/CHART_OF_ACCOUNTS.md → 8.4`'s "cloning, not referencing" rationale |
| WebSocket disconnected for an extended period, then reconnects | Every realtime-fed query key on this screen (`accountingKeys.*`, `accountingHubKeys.*`) is invalidated once as a batch on reconnect — a missed reclassification or a Fraud Detection flag raised during the outage surfaces immediately once connectivity returns, never waiting for a manual refresh, matching `docs/frontend/AI_COMMAND_CENTER.md → Edge Cases`'s identical reconnect rule |
| A company switch happens while this hub is open | `queryClient.clear()` and `router.refresh()` fire together per `docs/frontend/NAVIGATION_SYSTEM.md → Company switch`; this screen re-mounts and re-fetches under the new `X-Company-Id` rather than momentarily showing the previous company's account tree |

# End of Document
