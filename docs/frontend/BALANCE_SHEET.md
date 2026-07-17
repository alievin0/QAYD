# Balance Sheet — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: BALANCE_SHEET
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Balance Sheet screen — the Statement of
Financial Position rendering of the Accounting Engine's Financial Statements module
(`docs/accounting/FINANCIAL_STATEMENTS.md`). The screen renders, for any company, branch, or
consolidated group, at any as-of date, the full Assets / Liabilities / Equity hierarchy the backend
derives from posted `journal_lines`, together with a mandatory balance-identity check
(`TOTAL ASSETS = TOTAL LIABILITIES + TOTAL EQUITY`), optional comparative and common-size columns, and
an AI Insights rail carrying ratio analysis, variance commentary, risk flags, and a CFO-narrative
executive summary — every AI-authored figure visibly confidence-scored, sourced, and gated behind human
approval before it can leave the company as an export or a share link.

Like every screen in this documentation set, this one contains no financial logic of its own. It never
computes a balance, decides whether a statement is correct, or determines whether Assets equal
Liabilities plus Equity — the Report Generation Engine on the Laravel API does that, and this screen's
only job is to request a statement, render exactly what comes back, and offer the human affordances
(comparison, scope, currency, drill-down, export, share, schedule, review, approve) that
`FINANCIAL_STATEMENTS.md` already defines as the module's capabilities. Where this document performs a
client-side computation at all — the common-size percentage column, most visibly — it is a pure
display transform over numbers the server already returned, exactly the same category of "convenience,
never authority" computation `JournalEntryForm`'s live balance check performs elsewhere in the product
(`COMPONENT_LIBRARY.md → JournalEntryForm`); the server-computed `/export?report_type=common_size`
artifact remains the audited version of that same view.

Three properties make this screen distinct from a typical CRUD list or a transactional form, and they
shape every section below:

1. **A statement is a hierarchical, read-mostly document, not a paginated resource collection.** The
   grid on this screen is a `<table>` with real nesting (Total Current Assets rolls up Cash, Receivables,
   Inventories, …) rendered whole in one response, not a `DataTable` page of rows — closer in kind to
   the sticky-first-column financial grid `RESPONSIVE_DESIGN.md` documents for Trial Balance than to the
   generic `DataTable` used for Journal Entries or Invoices.
2. **Every number is drillable, by design.** `FINANCIAL_STATEMENTS.md`'s Financial Reporting
   Philosophy states plainly that aggregation never destroys lineage — a line like "Trade and Other
   Receivables: KWD 142,500.000" must lead, in at most three clicks, to the accounts that compose it,
   the journal lines that posted to those accounts, and the source document (an invoice, a receipt)
   that created each entry. This screen is the entry point into that lineage, not a dead end.
3. **The AI layer is a permanent, visible collaborator here, never a decision-maker.** A Balance Sheet
   is exactly the kind of artifact a bank, an auditor, or a board reads — so every AI-authored ratio,
   variance sentence, or narrative paragraph this screen shows is watermarked, confidence-scored, and
   — for anything that will leave the company — blocked from export or share until a CFO or Finance
   Manager has reviewed and approved it.

This screen is gated by the `accounting.financial-statements.*` permission family — a deliberate,
Accounting-scoped namespace distinct from the generic `reports.*` grants used for ad hoc,
user-configurable report definitions, because a company's Balance Sheet is core accounting output, not
a configurable report a user assembles from scratch. Every fact this document states about the
underlying computation, the six-stage generation pipeline (resolve scope → template → period → FX →
query ledger → map to lines), the validation rules (VR-01 through VR-10), and the seven
`financial_statement_*` tables is inherited verbatim from `FINANCIAL_STATEMENTS.md`; nothing here
redefines them.

# Route & Access

## Route tree

```text
app/(app)/accounting/financial-statements/balance-sheet/
├── page.tsx              # Server Component — first-paint fetch, hydrates the client grid
├── loading.tsx           # Statement-shaped skeleton (see # States)
└── error.tsx             # Route-level error boundary (generation failure, not the same as "unbalanced")
```

Financial statements are nested under the Accounting module's own route segment
(`accounting/financial-statements/<statement-type>`) rather than living at the application's top level,
because a statement is Accounting Engine output consumed inside the same workflow as Journal Entries,
the General Ledger, and Trial Balance — a Finance Manager moving from "post the accrual" to "check the
Balance Sheet" never leaves the Accounting section of the sidebar. `FRONTEND_ARCHITECTURE.md`'s own
route-tree sketch shows a top-level `financial-statements/[statementType]` segment as a placeholder for
this module before its screens were individually specified; per that same document's stated precedence
rule ("where a screen document appears to conflict with this one, this document is authoritative for
cross-cutting frontend concerns and the screen document is authoritative for that screen's specific
layout and interactions"), the nested `accounting/financial-statements/balance-sheet` path above is this
screen's actual, authoritative route. Sibling routes `accounting/financial-statements/profit-and-loss`
and `accounting/financial-statements/cash-flow` share this exact page shape, this exact permission
family, and most of this screen's components; they are documented in their own screen specs but are not
a separate architecture.

`page.tsx` is a thin Server Component. It parses and validates the incoming `searchParams` against
`balanceSheetSearchParamsSchema` (below), prefetches the primary statement query into a `QueryClient`,
and hands a `dehydrate()`d cache to a client `<BalanceSheetScreen>`, exactly mirroring the
Server-Component-prefetch → `HydrationBoundary` → Client-Component-`useQuery` handoff pattern
`FRONTEND_ARCHITECTURE.md → Data Layer` establishes for every statement-shaped and list-shaped route in
the product:

```tsx
// app/(app)/accounting/financial-statements/balance-sheet/page.tsx
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { balanceSheetKeys } from "@/lib/api/query-keys";
import { parseBalanceSheetParams } from "@/lib/schemas/balance-sheet";
import { BalanceSheetScreen } from "@/components/accounting/balance-sheet-screen";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store";

export default async function BalanceSheetPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | undefined>>;
}) {
  const params = parseBalanceSheetParams(await searchParams);
  const queryClient = getQueryClient();

  await queryClient.prefetchQuery({
    queryKey: balanceSheetKeys.view(params),
    queryFn: () =>
      apiServer.get("/accounting/financial-statements/balance-sheet", { params }),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <BalanceSheetScreen initialParams={params} />
    </HydrationBoundary>
  );
}
```

Every toolbar control (as-of date, comparison, scope, currency, common-size, framework) reads from and
writes back to these same URL search params via `router.replace(..., { scroll: false })`, never a
Zustand store — a Balance Sheet view is exactly the kind of state `FRONTEND_ARCHITECTURE.md → State
Management` says belongs in the URL: it must survive a refresh and must be shareable as a link
("here's the Q2 Balance Sheet vs. prior year, consolidated, in KWD") without the recipient needing to
re-select five toolbar controls by hand.

## Access gate

The route sits inside `(app)`, behind `middleware.ts`'s session check and the resolved `X-Company-Id`
context. A user without `accounting.financial-statements.read` never sees "Balance Sheet" in the
Accounting sidebar's Financial Statements group, never sees it in the Command Palette's fuzzy-search
index (RBAC filters the index at the data layer, before the list is built — `NAVIGATION_SYSTEM.md →
Command Palette`: the same permission-filtered `NAV_TREE` the Sidebar renders is what the palette's
Navigate group fuzzy-matches against), and a direct hit on the URL renders the shared `<ForbiddenState>`
rather than a generic 404, since the route and the underlying `fiscal_periods` scope genuinely exist —
the viewer simply is not permitted to see them.

## Permission surface on this screen

Every permission key below is authoritative on the backend (`FINANCIAL_STATEMENTS.md → Permissions`,
adapted to this screen's `accounting.*` namespace); this table is the screen's map of key → concrete UI
effect, not a re-derivation of the grant itself.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `accounting.financial-statements.read` | The route itself; the statement grid; comparative columns; AI ratio pack | Route renders `<ForbiddenState>`; nav item and Command Palette entry hidden |
| `accounting.financial-statements.generate` | "Regenerate" action (force a fresh live pull, bypassing the 60-second server cache); consolidated/branch scope switch | Button removed from the toolbar; the screen still renders the last-cached or last-snapshotted view |
| `accounting.financial-statements.review` | Moving a `draft`/`under_review` snapshot forward toward `final` | "Send for review" action omitted; snapshot stays in `draft` |
| `accounting.financial-statements.approve` | "Mark Final," approving a pending consolidation elimination set, approving the AI CFO narrative before it can be exported/shared | Approve affordances on `<BalanceIdentityBanner>`, `<StatementAIInsightsRail>`, and the snapshot status menu are omitted, not disabled |
| `accounting.financial-statements.export` | The Export menu (PDF/Excel/CSV/JSON) | Menu item removed from the toolbar overflow |
| `accounting.financial-statements.share` | The Share dialog (external time-limited link) | Menu item removed |
| `accounting.financial-statements.schedule` | The Schedule dialog (recurring board-pack distribution) | Menu item removed |
| `accounting.financial-statements.consolidation.manage` | Viewing/approving the intercompany elimination set behind a Consolidated Group scope | Consolidated scope option still selectable for viewing (read permission covers the preview), but the "Approve eliminations" action is omitted |
| `accounting.financial-statements.notes.write` | The "Add disclosure note" action on a line flagged by the materiality check | Action omitted; the materiality gap still surfaces as a read-only AI flag |

Default role grants (Owner, Admin, Finance Manager, CFO, Auditor (internal), External Auditor, AI
Agent) mirror `FINANCIAL_STATEMENTS.md → Permissions → Role matrix` exactly, renamespaced from
`reports.financial_statements.*` to `accounting.financial-statements.*`; `usePermission()`
(`FRONTEND_ARCHITECTURE.md → Authentication & Session`) is the only place either the backend or this
screen resolves a grant — this document does not restate the matrix a second time to avoid the two
copies drifting apart. The AI Agent identity holds `read` and nothing else: it can enrich a snapshot
with insights, and it can never call `.generate`, `.approve`, `.export`, `.share`, or
`.consolidation.manage` under any circumstance, matching the platform-wide rule that AI never bypasses
authorization.

## Keyboard entry points

`NAVIGATION_SYSTEM.md → Keyboard Navigation` documents a `G`-then-letter chord scheme (`G` `A` for
Accounting) as **reserved for a future release, not yet bound to a live handler** — this document does
not claim a shortcut that does not exist yet. Today, this screen is reachable through two live paths:
the Sidebar (Accounting → Financial Statements → Balance Sheet) and the Command Palette (`Cmd/Ctrl+K`),
which fuzzy-matches "balance sheet" against the same permission-filtered `NAV_TREE` the Sidebar renders
and ranks an exact/prefix label match in its Navigate group above any Records or AI & Actions hit
(`NAVIGATION_SYSTEM.md → Command Palette`). `Cmd/Ctrl+P` (overriding the browser's print dialog) opens
the Export menu pre-focused on PDF, matching the mental model of "print this statement" a Finance
Manager already has; `Esc` from any open drill-down `Sheet` returns focus to the row that opened it,
per `ACCESSIBILITY.md → Focus Management`.

# Layout & Regions

The screen composes the platform's Report Page Template (`LAYOUT_SYSTEM.md → Page Templates`) — the
same template Trial Balance uses — with one addition specific to statements that carry a persistent AI
analysis surface: a right-hand AI Insights rail that, at `xl:` (1280px) and above, docks inline in the
page grid rather than living only in an overlay `Sheet`, because ratio/variance/narrative content is
core, always-relevant content on this screen, not an auxiliary assistant a user opens on demand. The
`Container` wrapping the whole screen uses `variant="full-bleed"` (`LAYOUT_SYSTEM.md → Containers &
Cards`), the same 1600px `3xl` ceiling Trial Balance uses, because a consolidated statement with
comparative and common-size columns genuinely needs the width.

## Desktop (`xl:` and above)

```text
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│ Accounting / Financial Statements / Balance Sheet                                        │
│ Balance Sheet                                              [Final ✓]  [History ▾]  [⋯]    │
├──────────────────────────────────────────────────────────────────────────────────────────┤
│ [As of: Jul 2026 ▾] [vs Prior Year ▾] [Company ▾] [KWD ▾] [☐ Common-size] [↻ Regenerate]  │
│                                                          [Export ▾] [Share] [Schedule]     │
├────────────────────────────────────────────────────────────────────┬─────────────────────┤
│ ✓ Assets = Liabilities + Equity                    (sticky, full)  │  AI Insights (360px) │
├────────────────────────────────────────────────────────────────────┤  Tabs: Ratios │      │
│ Line (sticky start)              Jul 2026    Jul 2025    Δ%   %TA  │  Variance │ Risks │  │
│ ▸ ASSETS                                                            │  Narrative           │
│   ▾ Current Assets                                                 ├─────────────────────┤
│      Cash and Cash Equivalents   284,650.120 251,900.000 13.0% 31% │  Current ratio  1.35 │
│      Trade & Other Receivables   142,500.000 119,300.000 19.5% 16% │  Quick ratio    0.92 │
│      Inventories                  85,161.380  76,400.000 11.5%  9% │  Debt/Equity    0.61 │
│    Total Current Assets          512,311.500 447,600.000 14.5% 56% │  ──────────────────  │
│   ▸ Non-Current Assets           406,129.250 383,600.000  5.9% 44% │  92% ● Variance:     │
│ TOTAL ASSETS                     918,440.750 831,200.000 10.5%100% │  "Receivables grew   │
│ ▸ LIABILITIES                                                       │   19.5% against      │
│   ▸ Current Liabilities                                            │   13% revenue growth" │
│   ▸ Non-Current Liabilities                                        │  [Show reasoning]     │
│ TOTAL LIABILITIES                354,220.300 322,400.000  9.9% 39% │  ──────────────────  │
│ ▸ EQUITY                                                            │  CFO Narrative        │
│ TOTAL EQUITY                     564,220.450 508,800.000 10.9% 61% │  [Unreviewed AI Draft]│
│ TOTAL LIABILITIES AND EQUITY     918,440.750 831,200.000 10.5%100% │  Approve · Reject     │
└────────────────────────────────────────────────────────────────────┴─────────────────────┘
```

## Mobile / Tablet (below `xl:`)

```text
┌───────────────────────────────┐
│ ‹ Balance Sheet          [⋯]  │
│ [As of: Jul 2026 ▾]           │
│ Tabs:  Statement | AI Insights │
├───────────────────────────────┤
│ ✓ Balanced          (sticky)  │
├───────────────────────────────┤
│ Line (sticky start) → scroll →│
│ ▸ ASSETS                       │
│   ▾ Current Assets             │
│      Cash …            284,650│
│      Receivables …      142,500│
├───────────────────────────────┤
│ [Filters] [Columns] [⋯ Export]│
└───────────────────────────────┘
```

## Regions

| Region | Content |
|---|---|
| Page Header | Breadcrumb, title, snapshot `<StatusPill>` (`draft`/`under_review`/`final`/`superseded`), "History" (prior snapshot versions), overflow menu |
| Toolbar | As-of date (`PeriodPicker`), comparison type, scope (Company/Branch/Consolidated Group), presentation currency, common-size toggle, Regenerate, Export, Share, Schedule |
| Balance Identity Band | Full-width, sticky below the toolbar — the pass/fail Assets = Liabilities + Equity check, never scrolled out of view while reviewing the grid beneath it |
| Statement Grid | The sticky-first-column hierarchical `<table>` — the statement itself |
| AI Insights Rail | Docked inline at `xl:`+; a full-screen `Sheet` (mobile/tablet) or a `Tabs`-selected pane (below `xl:`, sharing the page instead of a modal) below that — Ratios, Variance, Risks, Narrative |
| Drill-down Sheet | Slides from the block-end/inline-end edge when a line or an account is expanded past the statement-line level; never navigates away from the statement |

# Components Used

Every component below is either a primitive/finance component already cataloged in
`COMPONENT_LIBRARY.md` and reused unmodified, or a screen-owned finance component this document
introduces because no existing primitive fits a hierarchical, read-mostly financial statement — the
same category of decision `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` makes when it builds
Trial Balance's sticky-column grid directly from a semantic `<table>` rather than forcing it through
the paginated, sortable `DataTable`.

| Component | Source | Role on this screen |
|---|---|---|
| `PeriodPicker` (`mode="fiscal_period"`) | `components/accounting/period-picker.tsx` | As-of-date selection, grouped Open / Locked-Closed |
| `StatusPill` (`domain="financial_statement_snapshot"`) | `components/shared/status-pill.tsx`, extended with this domain's lookup table | `draft` (neutral) · `under_review` (warning) · `final` (success) · `superseded` (neutral) |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every monetary figure, every variance amount, `emphasis="strong"` on subtotal/total rows |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Presentation-currency indicator next to the currency selector and inside export dialogs |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Ratio interpretation, variance narrative, CFO narrative — every AI-authored sentence |
| `AIProposalPanel` / `ApprovalCard` | `components/ai/ai-proposal-panel.tsx`, `components/shared/approval-card.tsx` | CFO narrative and liquidity-alert approve/reject inside the AI Insights rail |
| `Tabs` | `components/ui/tabs.tsx` | AI Insights rail's Ratios/Variance/Risks/Narrative sub-navigation; Statement/AI Insights switch below `xl:` |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Export format menu, snapshot History menu, row-action overflow |
| `Dialog` | `components/ui/dialog.tsx` | Share link dialog, Schedule dialog, "Reason required" regeneration dialog |
| `Sheet` | `components/ui/sheet.tsx` | Balance-variance diagnostic drill-in; account-level and GL drill-down; mobile Filters/Columns/AI Insights |
| `Switch` | `components/ui/switch.tsx` | Common-size toggle; "Show low-confidence AI analysis" toggle |
| `Skeleton` | `components/ui/skeleton.tsx` | Statement-shaped loading state, AI Insights "Generating…" placeholder |
| `EmptyState` / `ErrorState` | `components/shared/empty-state.tsx`, `components/shared/error-state.tsx` | Zero-transaction period; generation/validation failure |
| `Tooltip` | `components/ui/tooltip.tsx` | "Why 92%?" on every `ConfidenceBadge`; disabled-control permission explanations |
| `BalanceIdentityBanner` *(new)* | `components/accounting/balance-identity-banner.tsx` | The Assets = Liabilities + Equity pass/fail band |
| `StatementGrid` / `StatementLineRow` *(new)* | `components/accounting/statement-grid.tsx` | The sticky-first-column, expandable statement tree |
| `ComparisonSelect` *(new)* | `components/accounting/comparison-select.tsx` | None / Prior Period / Prior Year / Budget, built on `Select` |
| `ScopeSelect` *(new)* | `components/accounting/scope-select.tsx` | Company / Branch: {name} / Consolidated Group, built on `Select` |
| `StatementAIInsightsRail` *(new)* | `components/accounting/statement-ai-insights-rail.tsx` | Composes `ConfidenceBadge` + `ApprovalCard` + `Tabs` into the Ratios/Variance/Risks/Narrative panel |

`BalanceIdentityBanner` and `StatementGrid` are the two components this screen owns outright rather than
composing from an existing pattern, because neither a plain status `Badge` nor the generic `DataTable`
models "a single, always-visible pass/fail identity check spanning the whole page" or "a fixed
hierarchy of subtotal/total rows with client-side expand/collapse and no server-side pagination." Both
are built entirely from existing primitives (`Badge`, `Button`, native `<table>`) per
`COMPONENT_LIBRARY.md`'s composition-over-modification rule — neither touches a shadcn primitive's
internal Radix wiring.

```tsx
// components/accounting/balance-identity-banner.tsx
import { CheckCircle2, AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { AmountCell } from '@/components/accounting/amount-cell';
import { cn } from '@/lib/utils';

interface BalanceIdentityBannerProps {
  isBalanced: boolean;
  balanceVariance: string;      // NUMERIC(19,4) string, "0.0000" when balanced
  currencyCode: string;
  scopeLabel: string;           // e.g. "Branch view — Head Office balances excluded"
  onViewDiagnostic: () => void; // opens the variance-diagnostic Sheet
}

export function BalanceIdentityBanner({
  isBalanced, balanceVariance, currencyCode, scopeLabel, onViewDiagnostic,
}: BalanceIdentityBannerProps) {
  return (
    <div
      role={isBalanced ? 'status' : 'alert'}
      className={cn(
        'sticky top-[var(--toolbar-h)] z-sticky flex items-center justify-between gap-3',
        'border-b px-4 py-2.5 text-sm',
        isBalanced ? 'border-ink-150 bg-ink-0 text-ink-950' : 'border-danger/30 bg-danger/5 text-danger',
      )}
    >
      <span className="flex items-center gap-2">
        {isBalanced
          ? <CheckCircle2 className="h-4 w-4 text-ink-500" aria-hidden />
          : <AlertTriangle className="h-4 w-4 text-danger" aria-hidden />}
        {isBalanced
          ? <>Assets = Liabilities + Equity{scopeLabel && <span className="text-ink-500"> · {scopeLabel}</span>}</>
          : <>Assets do not equal Liabilities + Equity by{' '}
              <AmountCell amount={balanceVariance} currencyCode={currencyCode} emphasis="strong" showCurrency /></>}
      </span>
      {!isBalanced && (
        <Button variant="outline" size="sm" onClick={onViewDiagnostic}>View diagnostic</Button>
      )}
    </div>
  );
}
```

Note the deliberate restraint in the passing state: per `DESIGN_LANGUAGE.md`'s Design Principle 5
("posting a journal entry is not a game mechanic; the correct celebration ... is quiet certainty, not a
fireworks animation"), a balanced statement renders in plain `ink-950` on `ink-0` with a small neutral
checkmark — never a green success band — while a genuine failure renders prominently in the `danger`
tone, because that asymmetry (calm when correct, loud when wrong) is exactly right for a screen a bank
or auditor may also be looking at.

## StatementGrid

The statement itself: a real semantic `<table>` — never a styled `<div>` grid, per `ACCESSIBILITY.md →
Data Tables Accessibility`'s "plain accessible table, chosen deliberately" pattern — rendering the
company's active `financial_statement_templates`/`financial_statement_lines` hierarchy for
`statement_type = 'balance_sheet'` as a nested, expandable tree rather than a flat row list. Each
response line the screen receives from `GET /accounting/financial-statements/balance-sheet` carries
both its snapshot values (`current_value`, `comparison_value`, `variance_amount`, `variance_percent`,
per `financial_statement_snapshot_lines`) and its template-defined shape (`parent_line_id`,
`classification`, `is_subtotal`, `is_bold`, `display_order`, per `financial_statement_lines`) —
`FINANCIAL_STATEMENTS.md`'s own worked JSON example trims the response to four illustrative lines, but
this screen's contract denormalizes the template's tree shape onto every line in the real response so
`StatementGrid` can build its nesting client-side without a second round trip to fetch the template
separately.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `lines` | `BalanceSheetLine[]` | yes | Flat, `display_order`-sorted array; each carries `parent_line_id` for tree assembly. |
| `showComparison` | `boolean` | no | Renders the comparison-period value and Δ% columns. |
| `showCommonSize` | `boolean` | no | Renders the %TA (percent of Total Assets) column, computed client-side per `# Interactions & Flows`. |
| `currencyCode` | `string` | yes | Presentation currency for every `AmountCell`. |
| `expandedLineIds` | `Set<number>` | yes | Controlled expand/collapse state, persisted in the URL (`?expanded=…`) so a shared link opens to the same depth the sender left it at. |
| `onToggleExpand` | `(lineId: number) => void` | yes | |
| `onLineClick` | `(line: BalanceSheetLine) => void` | yes | Opens the Drill-down `Sheet` for an `account_mapped` leaf; toggles expand/collapse for a `subtotal`/`total` branch instead of drilling. |
| `density` | `'comfortable' \| 'compact'` | no, default `'comfortable'` | Per `LAYOUT_SYSTEM.md → Density Modes`; Balance Sheet defaults to Comfortable (lower row count, higher per-row scrutiny) unlike Journal Entries/Ledger's Compact default. |

**Implementation**

```tsx
// components/accounting/statement-grid.tsx
'use client';

import { useMemo } from 'react';
import { ChevronRight } from 'lucide-react';
import { AmountCell } from '@/components/accounting/amount-cell';
import { cn } from '@/lib/utils';
import { useLocale } from 'next-intl';
import type { BalanceSheetLine } from '@/types/accounting';

interface StatementNode extends BalanceSheetLine {
  children: StatementNode[];
  depth: number;
}

function buildTree(lines: BalanceSheetLine[]): StatementNode[] {
  const byId = new Map<number, StatementNode>(
    lines.map((l) => [l.line_id, { ...l, children: [], depth: 0 }]),
  );
  const roots: StatementNode[] = [];
  for (const node of byId.values()) {
    if (node.parent_line_id && byId.has(node.parent_line_id)) {
      const parent = byId.get(node.parent_line_id)!;
      node.depth = parent.depth + 1;
      parent.children.push(node);
    } else {
      roots.push(node);
    }
  }
  const sortRec = (nodes: StatementNode[]) => {
    nodes.sort((a, b) => a.display_order - b.display_order);
    nodes.forEach((n) => sortRec(n.children));
  };
  sortRec(roots);
  return roots;
}

export function StatementGrid({
  lines, showComparison, showCommonSize, currencyCode, expandedLineIds, onToggleExpand, onLineClick, density = 'comfortable',
}: StatementGridProps) {
  const locale = useLocale();
  const tree = useMemo(() => buildTree(lines), [lines]);
  const totalAssets = useMemo(() => lines.find((l) => l.line_code === 'TOTAL_ASSETS'), [lines]);

  function renderRow(node: StatementNode): React.ReactNode {
    const expandable = node.children.length > 0;
    const isExpanded = expandedLineIds.has(node.line_id);
    const commonSizePct = showCommonSize && totalAssets
      ? ((Number(node.current_value) / Number(totalAssets.current_value)) * 100).toFixed(1)
      : null;

    return (
      <>
        <tr
          key={node.line_id}
          className={cn(
            'border-t border-ink-150',
            (node.is_subtotal || node.is_bold) && 'font-semibold',
            node.line_code.startsWith('TOTAL_') && 'bg-ink-100/60',
          )}
        >
          <th
            scope="row"
            className={cn(
              'sticky start-0 z-10 bg-ink-0 py-2 text-start font-normal',
              density === 'compact' ? 'py-1.5' : 'py-2.5',
            )}
            style={{ paddingInlineStart: `${node.depth * 20 + 12}px` }}
          >
            <button
              type="button"
              onClick={() => (expandable ? onToggleExpand(node.line_id) : onLineClick(node))}
              className="inline-flex items-center gap-1.5 text-start hover:text-accent-600"
              aria-expanded={expandable ? isExpanded : undefined}
              aria-controls={expandable ? `line-group-${node.line_id}` : undefined}
            >
              {expandable && (
                <ChevronRight
                  className={cn('h-3.5 w-3.5 shrink-0 transition-transform rtl:rotate-180', isExpanded && 'rotate-90 rtl:rotate-90')}
                  aria-hidden
                />
              )}
              <span>{locale === 'ar' && node.label_ar ? node.label_ar : node.label_en}</span>
            </button>
          </th>
          <td className="text-end">
            <AmountCell amount={node.current_value} currencyCode={currencyCode}
                        emphasis={node.is_subtotal || node.is_bold ? 'strong' : 'default'} />
          </td>
          {showComparison && (
            <>
              <td className="text-end"><AmountCell amount={node.comparison_value ?? '0.0000'} currencyCode={currencyCode} /></td>
              <td className="text-end" dir="ltr">
                {node.variance_percent != null ? `${node.variance_percent > 0 ? '+' : ''}${node.variance_percent}%` : '—'}
              </td>
            </>
          )}
          {showCommonSize && <td className="text-end" dir="ltr">{commonSizePct ? `${commonSizePct}%` : '—'}</td>}
        </tr>
        {expandable && isExpanded && (
          <tbody id={`line-group-${node.line_id}`} className="contents">
            {node.children.map(renderRow)}
          </tbody>
        )}
      </>
    );
  }

  return (
    <table className="w-full text-sm">
      <caption className="sr-only">Balance Sheet — statement of financial position</caption>
      <thead className="sticky top-0 z-20 bg-ink-100/80">
        <tr>
          <th scope="col" className="sticky start-0 z-30 bg-ink-100/80 text-start">Line</th>
          <th scope="col" className="text-end">Current</th>
          {showComparison && (<><th scope="col" className="text-end">Comparison</th><th scope="col" className="text-end">Δ%</th></>)}
          {showCommonSize && <th scope="col" className="text-end">% of Assets</th>}
        </tr>
      </thead>
      <tbody>{tree.map(renderRow)}</tbody>
    </table>
  );
}
```

Two properties of this implementation matter beyond the code itself. First, expand/collapse state is a
row-local `aria-expanded`/`aria-controls` pair rather than a separate "expand all" mode with no
per-branch memory, matching `ACCESSIBILITY.md → Data Tables Accessibility → Expandable rows`'s pattern
verbatim (an expandable Journal Entry row uses the identical mechanism to reveal its lines). Second,
`buildTree` runs once per response, memoized on the `lines` reference — it never re-sorts or re-nests on
every render, and it never mutates the array the query cache owns, keeping `StatementGrid` a pure
rendering layer over server-shaped data per `COMPONENT_LIBRARY.md`'s "money, dates, and IDs are never
client-computed" rule (the one exception, common-size percentage, is a display-only ratio recomputed
from two already-rendered values, not a value fed back into any mutation).

## ComparisonSelect and ScopeSelect

Two thin `Select`-based controls, each a closed enumeration rather than a searchable `Combobox`, because
neither list is long enough or dynamic enough to need `AccountPicker`-style search:

```tsx
// components/accounting/comparison-select.tsx
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';

type ComparisonType = 'none' | 'prior_period' | 'prior_year' | 'budget';

export function ComparisonSelect({ value, onChange, budgetAvailable }: {
  value: ComparisonType; onChange: (v: ComparisonType) => void; budgetAvailable: boolean;
}) {
  return (
    <Select value={value} onValueChange={(v) => onChange(v as ComparisonType)}>
      <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
      <SelectContent>
        <SelectItem value="none">No comparison</SelectItem>
        <SelectItem value="prior_period">vs. Prior Period</SelectItem>
        <SelectItem value="prior_year">vs. Prior Year</SelectItem>
        <SelectItem value="budget" disabled={!budgetAvailable}>vs. Budget</SelectItem>
      </SelectContent>
    </Select>
  );
}
```

`ScopeSelect` follows the identical shape (`'company' | 'branch' | 'consolidated_group'`, with a nested
branch picker appearing only when `'branch'` is chosen), and both write their value into the same URL
search params `page.tsx` parses, never into component-local state — a page reload or a shared link must
reproduce the exact scope/comparison the sender had open, per `# Route & Access`'s stated URL-as-state
rule. The Consolidated Group option is visible to any user holding `.read` (a preview is always
viewable), but per `# AI Integration` and `# Edge Cases`, selecting it while the current period's
intercompany eliminations are unapproved renders the whole grid inside a watermarked preview treatment
rather than silently pretending the group statement is final.

## StatementAIInsightsRail

Composes four existing finance/AI components — `Tabs`, `ConfidenceBadge`, `AIProposalPanel`,
`ApprovalCard` — into the persistent Ratios / Variance / Risks / Narrative panel described in
`# Layout & Regions`. It owns no data-fetching of its own: it receives the already-resolved
`GET /accounting/financial-statements/{snapshot}/insights` payload as a prop and renders it, exactly as
`COMPONENT_LIBRARY.md`'s authoring convention requires ("a finance component never calls
`fetch`/`axios` inline").

```tsx
// components/accounting/statement-ai-insights-rail.tsx
'use client';

import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { AIProposalPanel } from '@/components/ai/ai-proposal-panel';
import { ApprovalCard } from '@/components/shared/approval-card';
import { Switch } from '@/components/ui/switch';
import { usePermission } from '@/hooks/use-permission';
import type { StatementInsights } from '@/types/ai';

export function StatementAIInsightsRail({ insights, snapshotId, onApproveNarrative, onRejectNarrative }: {
  insights: StatementInsights | null; snapshotId: number;
  onApproveNarrative: (note?: string) => Promise<void>; onRejectNarrative: (reason: string) => Promise<void>;
}) {
  const canApprove = usePermission('accounting.financial-statements.approve');
  const [showLowConfidence, setShowLowConfidence] = React.useState(false);

  if (!insights) return <AIInsightsGeneratingPlaceholder />; // see # States

  const visibleRisks = insights.risks.filter((r) => showLowConfidence || r.confidence >= 0.6);

  return (
    <aside className="flex h-full flex-col gap-4 border-s border-ink-150 p-4" aria-label="AI Insights">
      <div className="flex items-center justify-between">
        <Switch checked={showLowConfidence} onCheckedChange={setShowLowConfidence}
                label="Show low-confidence AI analysis" />
      </div>
      <Tabs defaultValue="ratios">
        <TabsList>
          <TabsTrigger value="ratios">Ratios</TabsTrigger>
          <TabsTrigger value="variance">Variance</TabsTrigger>
          <TabsTrigger value="risks">Risks{visibleRisks.length > 0 && ` (${visibleRisks.length})`}</TabsTrigger>
          <TabsTrigger value="narrative">Narrative</TabsTrigger>
        </TabsList>

        <TabsContent value="ratios" className="space-y-2">
          {insights.ratios.map((r) => (
            <div key={r.code} className="flex items-center justify-between text-sm">
              <span className="text-ink-500">{r.label}</span>
              {r.value != null
                ? <span className="font-mono tabular-nums" dir="ltr">{r.value.toFixed(2)}</span>
                : <span className="text-ink-300" title={r.unavailableReason}>—</span>}
            </div>
          ))}
        </TabsContent>

        <TabsContent value="variance" className="space-y-3">
          {insights.variance.map((v) => (
            <AIProposalPanel key={v.id} decision={v} autonomyLevel="suggest_only"
                              onAccept={async () => {}} onSendForApproval={async () => {}}
                              onDismiss={(reason) => dismissInsightMutation.mutateAsync({ id: v.id, reason })} />
          ))}
        </TabsContent>

        <TabsContent value="risks" className="space-y-3">
          {visibleRisks.map((risk) => (
            <div key={risk.id} className="rounded-md border-s-2 border-s-accent-600 bg-ink-100/50 p-3">
              <div className="flex items-center gap-2">
                <ConfidenceBadge confidence={risk.confidence} size="sm" reasoning={risk.reasoning} />
              </div>
              <p className="mt-1 text-sm text-ink-950">{risk.title}</p>
            </div>
          ))}
        </TabsContent>

        <TabsContent value="narrative">
          {insights.narrative ? (
            <div className="space-y-3">
              <div className="rounded-md border-s-2 border-s-accent-600 bg-ink-100/50 p-3 text-sm">
                {insights.narrative.approvedAt
                  ? <p className="mb-2 text-ink-500">AI-generated — reviewed by {insights.narrative.approvedBy} on {insights.narrative.approvedAt}</p>
                  : <p className="mb-2 font-medium text-warning">Unreviewed AI Draft</p>}
                <p>{insights.narrative.text}</p>
                <ConfidenceBadge confidence={insights.narrative.confidence} reasoning={insights.narrative.reasoning} />
              </div>
              {!insights.narrative.approvedAt && canApprove && (
                <ApprovalCard
                  kind="ai_recommendation"
                  title="CFO Narrative — Executive Summary"
                  requestedAt={insights.narrative.generatedAt}
                  confidence={insights.narrative.confidence}
                  detailHref={`/accounting/financial-statements/balance-sheet?snapshot_id=${snapshotId}`}
                  onApprove={onApproveNarrative}
                  onReject={onRejectNarrative}
                />
              )}
            </div>
          ) : <p className="text-sm text-ink-500">No narrative generated for this snapshot yet.</p>}
        </TabsContent>
      </Tabs>
    </aside>
  );
}
```

Every risk card and the narrative block reuse the exact `border-s-2 border-s-accent-600` "ai-insight"
card treatment `LAYOUT_SYSTEM.md → Card variants` defines platform-wide, so a user who has learned that
visual grammar on the Dashboard or in Journal Entries recognizes it identically here — the rail
introduces no new visual language of its own, only new content inside the existing one.

# Data & State

## Endpoints consumed

This screen's primary resource is a statement-type-scoped read path this document fixes at
`/api/v1/accounting/financial-statements/balance-sheet`, resolving under the hood to the same
Report Generation Engine, the same `financial_statement_snapshots` row shape, and the same
`statement_type = 'balance_sheet'` value `FINANCIAL_STATEMENTS.md → API` defines generically for every
statement type — the path fixes the statement type so the screen never has to pass it as a body param
for its own primary read, while every snapshot-level *action* (review, export, share, schedule,
insights) is statement-type-agnostic once a snapshot id is resolved and therefore reuses the generic
`/financial-statements/{snapshot}/...` action endpoints verbatim, renamespaced under
`/accounting/financial-statements/` for this module's own route grouping. Bearer + `X-Company-Id` +
standard envelope on every call, per the platform's fixed API conventions.

| Method | Path | Permission | Used for |
|---|---|---|---|
| GET | `/accounting/financial-statements/balance-sheet` | `.read` | Resolve the current/matching statement for the active as-of-date/scope/currency/comparison on page load and on every toolbar change |
| GET | `/accounting/financial-statements/preview` | `.read` | Non-persisted `mode="preview"` pull, used for the Consolidated scope's unapproved-eliminations view (BR-06) |
| GET | `/accounting/financial-statements/{snapshot}` | `.read` | A specific historical snapshot opened from History (`?snapshot_id=`) |
| GET | `/accounting/financial-statements` | `.read` | History panel's version list for the resolved logical key |
| GET | `/accounting/financial-statements/{snapshot}/insights` | `.read` | Ratios / Variance / Risks / Narrative for `StatementAIInsightsRail` |
| GET | `/accounting/financial-statements/{snapshot}/drill-down` | `.read` | Drill-down `Sheet`'s contributing accounts and journal lines for a clicked leaf line |
| POST | `/accounting/financial-statements/generate` | `.generate` | Regenerate (force a fresh live pull, bypassing the 60-second Redis cache); first-time generation for a period with no snapshot yet |
| POST | `/accounting/financial-statements/{snapshot}/review` | `.review` / `.approve` | "Send for review," "Mark Final," "Reject," "Request changes" — one endpoint, an `action` field in the body selects the transition, mirroring the single-endpoint pattern `TRIAL_BALANCE.md`'s own `/approve` action uses for its comparable state machine |
| POST | `/accounting/financial-statements/{snapshot}/export` | `.export` | Export dialog submit (PDF/Excel/CSV/JSON), returns a `download_url` |
| GET | `/accounting/financial-statements/{snapshot}/export/{format}` | `.export` | Direct-stream re-download of a prior export without regenerating the file |
| POST | `/accounting/financial-statements/{snapshot}/share` | `.share` | Share dialog submit |
| DELETE | `/accounting/financial-statements/shares/{share}` | `.share` | Revoke an existing share link |
| POST | `/accounting/financial-statements/schedules` | `.schedule` | Schedule dialog submit, `statement_types` pre-filled to `["balance_sheet"]` |
| GET | `/accounting/financial-statements/notes` | `.read` | Disclosure notes attached to lines on this statement, for a materiality-gap prompt |
| POST | `/accounting/financial-statements/notes` | `.notes.write` | "Add disclosure note" action |

## Query keys

```ts
// lib/api/query-keys.ts (extends the factory pattern from FRONTEND_ARCHITECTURE.md)
export const balanceSheetKeys = {
  all: ["accounting", "financial-statements", "balance-sheet"] as const,
  view: (params: BalanceSheetParams) => [...balanceSheetKeys.all, "view", params] as const,
  snapshot: (snapshotId: number) => [...balanceSheetKeys.all, "snapshot", snapshotId] as const,
  insights: (snapshotId: number) => [...balanceSheetKeys.snapshot(snapshotId), "insights"] as const,
  drillDown: (snapshotId: number, lineId: number) =>
    [...balanceSheetKeys.snapshot(snapshotId), "drill-down", lineId] as const,
  history: (logicalKey: BalanceSheetLogicalKey) => [...balanceSheetKeys.all, "history", logicalKey] as const,
  notes: (fiscalPeriodId: number) => [...balanceSheetKeys.all, "notes", fiscalPeriodId] as const,
};
```

`BalanceSheetParams` is `{ as_of_date, scope_type, branch_id | consolidated_company_ids,
presentation_currency, comparison: { type }, framework }` — the same logical key the backend's 60-second
Redis cache keys on (`FINANCIAL_STATEMENTS.md → Report Generation Engine → Real-Time`). Every toolbar
change re-resolves `view(params)` directly rather than first checking a separate "current" lookup the
way Trial Balance's per-scope snapshot resolution does, because a Balance Sheet's primary read path is
already scope-and-date-addressed by construction — there is no ambiguous "which snapshot is current for
this scope" step to resolve first.

## Cache tuning by snapshot status

Cache lifetime is a function of the snapshot's own `status` and `generation_mode`, not a single default,
mirroring `FRONTEND_ARCHITECTURE.md → Cache tuning by data class`:

| `status` / mode | `staleTime` | Rationale |
|---|---|---|
| `mode: 'live'` (current open fiscal period) | 60 seconds | Matches the backend's own Redis result-cache TTL exactly (`FINANCIAL_STATEMENTS.md → Real-Time`) — refetching more eagerly than the server itself would recompute is pure waste; refetching less eagerly risks showing a figure that is stale relative to a posting that already happened |
| `draft` / `under_review` (closed-period, not yet final) | 30 seconds | Reviewers may be resolving findings or adjusting the elimination set concurrently |
| `final` | 5 minutes | Structurally immutable per VR-10's trigger; only lifecycle metadata (history links, exports, shares) can still change |
| `superseded` / `archived` | `Infinity`, invalidated only by an explicit `superseded` realtime event | Immutable, historical, permanent |
| Consolidated `preview` (unapproved eliminations) | 0, no caching | A preview is explicitly disclaimed as provisional; caching it risks a reviewer approving eliminations against a stale preview figure |

## Realtime

Two Laravel Reverb channels, per the platform-wide `private-company.{id}.<feature>[.{sub_id}]`
convention (`FRONTEND_ARCHITECTURE.md → Realtime`):

| Channel | Events | Effect |
|---|---|---|
| `private-company.{id}.financial-statements.{snapshot_id}` | `financial_statement.generated`, `financial_statement.consolidation.eliminations_pending_review` | Subscribed only while a specific snapshot's generation/consolidation job is in flight; on `generated` the client invalidates `snapshot`/`insights`/`drill-down` for that id and the Balance Identity Band animates from its skeleton to the resolved state |
| `private-company.{id}.notifications.{user_id}` | `financial_statement.unbalanced`, `financial_statement.snapshot.final`, `financial_statement.ai.risk_flag_high_confidence`, `financial_statement.note.pending_approval`, `financial_statement.share.created`, `financial_statement.share.accessed`, `financial_statement.share.expiring_soon`, `financial_statement.schedule.run_completed`, `financial_statement.schedule.run_failed`, `financial_statement.year_end.reserve_transfer_drafted`, `financial_statement.regeneration.reason_required` | Topbar bell + toast per `FINANCIAL_STATEMENTS.md → Notifications`; a toast whose event matches the currently-open snapshot id additionally triggers a targeted `invalidateQueries` rather than waiting for the next `staleTime` window |

## AI agents feeding this screen

Per `docs/accounting/FINANCIAL_STATEMENTS.md → AI Responsibilities`: **Reporting Agent** (Financial
Analysis, Ratio Analysis, Variance Analysis, Executive Summary/CFO Narrative, Narrative Report
Generation), **Forecast Agent** (Trend Analysis, Forecasting), and **Fraud Detection Agent +
Compliance Agent** (Risk Detection). None of the three writes to the database directly; each produces
structured output the Laravel backend validates, stores as an `ai_conversations`/`ai_messages` pair
linked to the snapshot, and denormalizes onto the `GET .../insights` response this screen renders.

## Mutations — optimistic vs. pessimistic

Per Principle 10 (`FRONTEND_ARCHITECTURE.md`): reversible, non-financial actions are optimistic;
anything that changes the statement's authoritative lifecycle state is pessimistic.

```ts
// hooks/accounting/use-balance-sheet.ts
export function useToggleCommonSize() {
  // Purely a URL search-param write — no network call, no cache entry at all. Listed here only to
  // make explicit that this "mutation" never touches the server: the toggle is a display transform
  // over already-fetched values (# Interactions & Flows), never a request.
  const router = useRouter();
  const searchParams = useSearchParams();
  return (next: boolean) => {
    const params = new URLSearchParams(searchParams);
    params.set('common_size', String(next));
    router.replace(`?${params.toString()}`, { scroll: false });
  };
}

export function useRegenerateBalanceSheet() {
  // No onMutate — regenerating a statement is a financial-workflow action; the UI only shows the new
  // figures after the server's 2xx, per Principle 10's "pessimistic where it moves money"-adjacent rule
  // (a statement doesn't move money, but a Finance Manager treats a regenerated number with the same
  // weight, so the same caution applies).
  return useMutation({
    mutationFn: (params: BalanceSheetParams & { reason?: string }) =>
      api.post('/accounting/financial-statements/generate',
        { statement_type: 'balance_sheet', mode: 'live', ...params },
        crypto.randomUUID()),
  });
}

export function useApproveStatementStep(snapshotId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { action: 'start_review' | 'approve' | 'reject' | 'request_changes'; comment?: string }) =>
      api.post(`/accounting/financial-statements/${snapshotId}/review`, body, crypto.randomUUID()),
    onSettled: () => qc.invalidateQueries({ queryKey: balanceSheetKeys.snapshot(snapshotId) }),
  });
}

export function useApproveNarrative(snapshotId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (note?: string) =>
      api.post(`/accounting/financial-statements/${snapshotId}/insights/narrative/approve`, { note }, crypto.randomUUID()),
    onSettled: () => qc.invalidateQueries({ queryKey: balanceSheetKeys.insights(snapshotId) }),
  });
}
```

## Form schemas

```ts
// lib/schemas/balance-sheet.ts
export const balanceSheetSearchParamsSchema = z
  .object({
    as_of_date: z.string().date(),
    scope_type: z.enum(['company', 'branch', 'consolidated_group']).default('company'),
    branch_id: z.coerce.number().int().positive().optional(),
    consolidated_company_ids: z.array(z.coerce.number().int().positive()).optional(),
    presentation_currency: z.string().length(3).default('KWD'),
    comparison_type: z.enum(['none', 'prior_period', 'prior_year', 'budget']).default('none'),
    common_size: z.coerce.boolean().default(false),
    framework: z.enum(['IFRS', 'GAAP']).optional(), // omitted = company's configured default
    snapshot_id: z.coerce.number().int().positive().optional(), // History: viewing a specific version
    expanded: z.array(z.coerce.number()).optional(),            // persisted StatementGrid expand state
  })
  .refine((v) => v.scope_type !== 'branch' || v.branch_id != null, {
    message: 'validation.balanceSheet.branchRequired', path: ['branch_id'],
  })
  .refine((v) => v.scope_type !== 'consolidated_group' || (v.consolidated_company_ids?.length ?? 0) > 0, {
    message: 'validation.balanceSheet.subsidiariesRequired', path: ['consolidated_company_ids'],
  });

export const regenerateBalanceSheetSchema = z.object({
  reason: z.string().min(1, 'validation.balanceSheet.regenerationReasonRequired').optional(),
  // Business Rule reference (FINANCIAL_STATEMENTS.md BR-04/VR-10): a reason is required only when the
  // target snapshot is 'final' for a closed period; the resolver injects the requirement conditionally
  // rather than always requiring it, since a same-day live regenerate needs no justification.
});

export const shareStatementSchema = z.object({
  permission: z.enum(['view_only', 'view_and_export']).default('view_only'),
  recipient_email: z.string().email().optional(),
  recipient_label: z.string().min(1, 'validation.balanceSheet.recipientLabelRequired'),
  expires_in_days: z.number().int().min(1).max(90).default(14),
  include_ai_insights: z.boolean().default(false),
});

export const addDisclosureNoteSchema = z.object({
  note_number: z.string().min(1),
  title_en: z.string().min(1),
  title_ar: z.string().optional(),
  content_text: z.string().min(1, 'validation.balanceSheet.noteContentRequired'),
  contains_related_party_pii: z.boolean().default(false),
});
```

## SSR hydration

```tsx
// app/(app)/accounting/financial-statements/balance-sheet/page.tsx (full hydration, extends # Route & Access)
export default async function BalanceSheetPage({ searchParams }: { searchParams: Promise<Record<string, string>> }) {
  const params = parseBalanceSheetParams(await searchParams);
  const queryClient = getQueryClient();

  const view = await apiServer.get("/accounting/financial-statements/balance-sheet", { params });
  const snapshotId = view.data.snapshot_id;

  await Promise.all([
    queryClient.prefetchQuery({ queryKey: balanceSheetKeys.view(params), queryFn: () => Promise.resolve(view) }),
    snapshotId
      ? queryClient.prefetchQuery({
          queryKey: balanceSheetKeys.insights(snapshotId),
          queryFn: () => apiServer.get(`/accounting/financial-statements/${snapshotId}/insights`),
        })
      : Promise.resolve(),
  ]);

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <BalanceSheetScreen initialParams={params} initialSnapshotId={snapshotId} />
    </HydrationBoundary>
  );
}
```

AI Insights are prefetched alongside the primary statement rather than left to a client-only fetch,
because — per `FINANCIAL_STATEMENTS.md → Performance → Asynchronous AI enrichment` — insights populate
"a few seconds to a couple of minutes" after generation for a *freshly generated* snapshot, but for the
overwhelming majority of views (an already-`final`, already-enriched historical snapshot, per the same
document's Performance section on snapshot-first serving) the insights are already computed and this
prefetch resolves instantly from the same request the Server Component already needed to make.

# Interactions & Flows

**First-time / empty scope.** No matching snapshot exists yet for the resolved (as-of-date, scope,
currency, framework) combination. The canvas renders `EmptyState` ("No Balance Sheet generated as of
July 31, 2026 yet") with a primary "Generate" action gated on `.generate`. Clicking it calls
`POST /financial-statements/generate` with `mode: 'live'`; for a same-day current-period generation the
response resolves synchronously (backed by the 60-second Redis cache, per `# Data & State`), and the
grid fades in directly. For a first-time historical-period generation (`mode: 'historical'`, no
snapshot cached yet) or a large consolidated run, the response instead returns `202 Accepted` with
`status: 'generating'` — the Balance Identity Band shows an indeterminate "Generating…" skeleton and the
screen subscribes to the snapshot's Reverb channel rather than blocking the UI thread on a synchronous
wait, per `# States`.

**Changing the as-of date.** `PeriodPicker` writes `as_of_date` into the URL; the primary query
re-resolves immediately. Selecting a date inside a `fiscal_periods` row with `status = 'closed'` almost
always resolves instantly from an existing `final` snapshot (snapshot-first serving,
`FINANCIAL_STATEMENTS.md → Performance`); selecting a date inside the open current period always
re-runs live generation (never served from a stale snapshot), and selecting a date the fiscal calendar
has not yet reached at all is rejected client-side before any request fires, with the date picker itself
graying out future dates by default (`PeriodPicker`'s `allowFuturePeriods` prop stays `false` on this
screen, unlike a budget-entry context that might need it).

**Comparison.** Selecting "vs. Prior Year" (`ComparisonSelect`) adds `comparison_type=prior_year` to the
params and the response's `lines[]` array gains populated `comparison_value`/`variance_amount`/
`variance_percent` fields per line (`financial_statement_snapshot_lines`'s own columns — no separate
comparative endpoint is needed the way Trial Balance's `/comparative` endpoint merges two independently
fetched snapshots, because a Balance Sheet's comparison column is already a first-class part of its
primary generation request, per `FINANCIAL_STATEMENTS.md → Report Generation Engine → Comparative`).
`StatementGrid` renders the Δ% column highlighted (`Badge` `tone="warning"`) whenever a movement exceeds
the company's configured materiality threshold. "vs. Budget" is disabled with a tooltip
("No budget dataset linked to this fiscal year") when no `report_definitions`-linked budget exists for
the active fiscal year, rather than silently returning an all-null comparison column.

**Common-size toggle.** Flipping `☐ Common-size` on sets `common_size=true` in the URL; `StatementGrid`
computes `line.current_value / TOTAL_ASSETS.current_value * 100` for every line purely as a display
column, per `# Purpose`'s "convenience, never authority" framing — this never issues a request, and it
is recomputed identically for the comparison column when both toggles are active simultaneously. Any
export or share that must carry an audited common-size view instead calls
`POST .../export?report_type=common_size`, which `FINANCIAL_STATEMENTS.md → Reports → Common Size`
describes as a pure server-side transformation of `financial_statement_snapshot_lines` with no
additional ledger query — "effectively free to compute," and therefore never a reason to prefer the
client's live toggle over the audited export for anything leaving the company.

**Scope switch — Company / Branch / Consolidated Group.** Switching to a specific Branch re-resolves the
primary query with `scope_type=branch&branch_id=…`; per `FINANCIAL_STATEMENTS.md → Report Generation
Engine → Branch`, the response may legitimately fail the balance identity, and the Balance Identity Band
renders its dedicated "Partial view" treatment rather than the danger treatment (`# States`). Switching
to Consolidated Group calls `GET /accounting/financial-statements/preview` first (per BR-06, a
consolidated run needs an approved elimination set before it can be treated as anything but provisional)
— if the current period's eliminations are already approved, the toolbar's "View live" action re-issues
the same request against `/generate` with `mode: 'consolidated'` to get the official, snapshot-backed
figure instead of the preview.

**Currency switch.** Changing the presentation currency re-resolves the primary query with the new
`presentation_currency`. Per VR-08, a missing `exchange_rates` row for the required rate/date/currency
pair returns `422 exchange_rate_missing`; the toolbar's currency `Select` reverts to the prior value and
an inline banner names the exact currency pair and date a Finance Manager needs to enter a manual policy
rate for (`rate_source = 'manual_policy'`) before the switch can succeed — the screen never silently
falls back to a rate of 1.0.

**Drill-down.** Clicking a leaf line (an `account_mapped` `financial_statement_lines` row, never a
`subtotal`/`total`, which instead toggles expand/collapse per `StatementGrid`'s own click handling)
opens the Drill-down `Sheet` (`GET .../{snapshot}/drill-down?line_id=…`), listing the specific `accounts`
rows the line maps to and each account's contributing balance. Selecting one of those accounts drills
one level deeper into its posted `journal_lines` for the period, and "Open journal entry" from there
navigates to `/accounting/journal-entries/{journalEntryId}`, closing the Sheet on navigate — the full
"statement line → account → journal line → source document" path `FINANCIAL_STATEMENTS.md`'s Financial
Reporting Philosophy #3 requires in at most three clicks.

**Review and approve.** "Send for review" (`.review`) calls the `/review` action endpoint with
`{ action: 'start_review' }`, moving the snapshot to `under_review` and surfacing any materiality-gap
notes prompt (BR-10) alongside the AI Insights rail's Risks tab. "Mark Final" (`.approve`) calls the same
endpoint with `{ action: 'approve', comment }`; per BR-10, the action is rejected with a 422 naming the
specific uncovered material line if a required disclosure note is still missing, and the UI surfaces
that exact line via a "Jump to line" link rather than a generic validation message. A snapshot behind a
Consolidated scope additionally requires "Approve eliminations" (`.consolidation.manage`) before "Mark
Final" is offered at all, per BR-06.

**Regenerating a closed or statutory-filed period.** Attempting `.generate` against a `final` snapshot
for a closed period opens a mandatory-reason `Dialog` (`regenerateBalanceSheetSchema`) rather than
silently mutating anything — VR-10's database trigger makes a direct mutation of a `final` row's
financial columns impossible regardless, so the UI's reason requirement exists to capture the *why*
before the request is even sent, matching the backend's `regeneration_reason` field. The result is a new,
`regeneration_sequence`-incremented snapshot; the prior one remains fully browsable, unmutated, from
History, linked via `superseded_by_id`.

**Export.** The `DropdownMenu` offers PDF / Excel / CSV / JSON. A `draft`/`under_review` snapshot's
export carries a visible "DRAFT — NOT APPROVED" note in the menu item itself, before the click, matching
Trial Balance's identical watermark-disclosure pattern. Every export embeds the snapshot's `result_hash`
as both a footer watermark and embedded document metadata (`FINANCIAL_STATEMENTS.md → Security → Export
integrity`), so a forwarded PDF can always be checked against the system of record.

**Share.** The Share `Dialog` (`shareStatementSchema`) defaults `permission` to `view_only`; selecting
`view_and_export` is a distinct, separately logged choice per `FINANCIAL_STATEMENTS.md → Security →
Share-link security`. `include_ai_insights` defaults to `false` — an external auditor's link shows the
audited figures only unless the creating user explicitly opts the AI Insights rail into the shared view.

**Schedule.** Opens the recurring board-pack `Dialog` pre-filled with `statement_types: ["balance_sheet"]`
and the screen's current scope/currency; submitting calls `POST /financial-statements/schedules`
per the standalone (non-snapshot-scoped) schedules resource `FINANCIAL_STATEMENTS.md → API` defines.

**History.** The History `Sheet` lists every version of the current logical snapshot key
(company/scope/statement_type/period/currency/template_version), each row showing version, status,
generated-at, and — where present — `regeneration_reason`. Opening a non-current version sets
`?snapshot_id=` and renders the whole canvas in a persistent, undismissable "Viewing version 1 of 2 —
superseded" banner with every mutating action disabled, identical in treatment to Trial Balance's own
History pattern.

# AI Integration

The AI Insights rail is this screen's entire AI surface; there is no separate "Ask AI" entry point
specific to the Balance Sheet beyond the platform-wide Ask AI chat. Every tab maps directly onto one row
of `FINANCIAL_STATEMENTS.md → AI Responsibilities`, and the mapping is deliberately narrow — the rail
never invents an AI capability the backend spec does not already define for this module.

**Ratios tab — Reporting Agent, auto-computed, no confidence score.** Per the AI Responsibilities table,
ratio *values* are deterministic math and carry no confidence score of their own; only their narrative
*interpretation* does. This screen renders only the ratio families computable directly from Balance
Sheet lines without an Income Statement — Current Ratio, Quick Ratio, Cash Ratio, Debt-to-Equity,
Debt-to-Assets (`FINANCIAL_STATEMENTS.md → Reports → Financial Ratios` — Liquidity and Solvency
categories). Profitability and Efficiency ratios (ROA, ROE, margins, turnover) require the same-period
Income Statement; when that snapshot exists, the insights payload includes them alongside a
cross-reference link, and when it does not, the row renders `—` with a tooltip ("Generate the Income
Statement for this period to unlock this ratio") rather than a silently wrong or zeroed figure — the
same `null` + `reason` discipline the backend applies to a company's first fiscal period (no averaging
base) is reused here for a missing cross-statement input.

**Variance tab — Reporting Agent, `suggest_only`.** Surfaces the five to ten largest or most unusual
line movements against the active comparison period as `AIProposalPanel` cards, each carrying its own
confidence (higher when a single clear driver account explains the movement, lower when it is diffuse
across many small accounts). The wireframe's worked example — "Receivables grew 19.5% against 13%
revenue growth," 92% confidence — is exactly this responsibility: the underlying `source_references`
point at the specific `financial_statement_snapshot_lines.id` and `journal_lines.id` values that
produced the read, reachable via "Show reasoning."

**Risks tab — Fraud Detection Agent + Compliance Agent, `suggest_only`, escalating.** Ranked risk flags
(e.g., "receivables growing faster than revenue," "related-party concentration above threshold," a
negative-equity flag). Per `FINANCIAL_STATEMENTS.md → Edge Cases`, a negative Total Equity figure is
**always** flagged as a high-confidence risk item regardless of the company's configured anomaly
sensitivity threshold — this screen never lets a company's own sensitivity setting suppress that
specific flag. Any flag at confidence ≥ 0.75 additionally fires
`financial_statement.ai.risk_flag_high_confidence` to the CFO and internal Auditor (`# Data & State →
Realtime`), so the flag reaches the right people even if no one currently has this tab open.

**Narrative tab — Reporting Agent, `suggest_only`, human-approval-gated before external use.** A
150–300 word plain-language executive summary, rendered `Unreviewed AI Draft` until a CFO or Finance
Manager approves it via the embedded `ApprovalCard`; once approved, the attribution line switches to
"AI-generated — reviewed by {name} on {date}," matching the exact attribution convention
`FINANCIAL_STATEMENTS.md → AI Responsibilities → Executive Summary` specifies. Approval here is what
`# Purpose`'s "gated behind human approval before it can leave the company" claim actually cashes out
to in the UI: the Export dialog's `include_ai_insights`/narrative option and the Share dialog's
equivalent flag are both disabled — omitted from the payload the export/share request sends, not merely
grayed — for any narrative that has not cleared this approval, so an unreviewed AI draft can never
accidentally ship inside a board pack or an auditor's share link.

**Forecast cross-link.** Trend Analysis and Forecasting (Forecast Agent) are not a fifth tab on this
screen; per `FINANCIAL_STATEMENTS.md → AI Responsibilities`, forecasting output is explicitly labeled
"Forecast — Not an Audited Statement" and requires a minimum of three comparable historical periods
before returning any claim. Where at least one forecast exists for the open period, the Narrative tab
surfaces a single "View next-period forecast" link into the dedicated `app/(app)/ai/forecast` panel
(`FRONTEND_ARCHITECTURE.md → App Router Structure`) rather than duplicating a second confidence-banded
projection UI inside this already-dense rail.

**Unavailable AI.** If the Reporting Agent, Forecast Agent, or Fraud/Compliance jobs fail, time out, or
are disabled for the company, `StatementAIInsightsRail` renders a single, calm `StatusPill` ("AI review
pending/unavailable") in place of the affected tab's content rather than an error state, and every
human-driven action on the statement itself (Review, Approve, Export, Share, Schedule) remains fully
available — AI is additive here exactly as it is everywhere else in the product; the UI never blocks a
Finance Manager's period close on a model call.

**What this screen's AI never does.** It never explains an Assets ≠ Liabilities + Equity failure — that
diagnostic capability belongs to Trial Balance's Auditor agent (`TRIAL_BALANCE.md → AI Integration`),
because per `FINANCIAL_STATEMENTS.md`'s own Financial Reporting Philosophy #2, a Balance Sheet imbalance
is always a downstream symptom of an unposted or unbalanced journal entry, never a defect in the
statement layer itself. The Balance Identity Band's "View diagnostic" action (`# States`) surfaces the
variance and its contributing accounts directly, and for a genuine imbalance additionally offers "Check
Trial Balance for this period" — a deep link into the screen that actually owns imbalance root-cause
analysis — rather than this screen inventing a second, redundant AI capability for the same underlying
ledger problem.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | `Skeleton` shaped exactly like the Page Header + Balance Identity Band + Statement Grid (shimmer sweep per `DESIGN_LANGUAGE.md → Motion`), never a generic spinner |
| Generating | `status: "generating"` (first historical generation, or a large consolidated run) | Balance Identity Band shows an indeterminate "Generating your Balance Sheet…" bar; grid region shows the skeleton; Reverb-subscribed |
| Balanced | `is_balanced: true` | Band: plain `ink-950` on `ink-0`, small neutral checkmark, "Assets = Liabilities + Equity" — never a green success fill, per `# Components Used → BalanceIdentityBanner` |
| Out of balance | `is_balanced: false` | Band: `danger` tone, exact `balance_variance` amount, "View diagnostic" opens the contributing-accounts breakdown and, where the root cause is plausibly a ledger-level imbalance, a "Check Trial Balance for this period" link (`# AI Integration`); Review/Approve/Export-as-final are disabled, Export-as-draft and Regenerate remain available |
| Partial view (branch-scoped) | A Branch scope is active and the branch-level subtotal does not independently balance | Band: `info` tone, "Branch view — Head Office balances excluded," never the `danger` treatment (`FINANCIAL_STATEMENTS.md → Report Generation Engine → Branch`) — this is the single most important state to render distinctly, since conflating it with a real imbalance would train reviewers to distrust a correct, by-design branch view |
| Consolidated preview, eliminations unapproved | `is_preview: true`, `eliminations_approved: false` | A persistent, undismissable "Preview — Eliminations Not Yet Approved" watermark band above the grid; Export/Share/Schedule/Mark Final are omitted (not disabled) until `.consolidation.manage` approves the elimination set (BR-06) |
| Has disclosure gap | A material line lacks a `financial_statement_notes` row (BR-10) | Band stays whatever its balance state is; a secondary `warning` `Badge` ("1 disclosure required") sits beside the snapshot `StatusPill`; "Mark Final" is disabled with a tooltip naming the specific line, not a generic "incomplete" message |
| Has AI risk flags | One or more Risks-tab entries at any confidence | No change to the Band; the AI Insights rail's Risks tab badge shows a count, and a ≥ 0.75-confidence flag additionally fires the CFO/Auditor notification (`# Data & State → Realtime`) |
| Empty (zero-transaction period) | Generation succeeds against a period with no posted journal entries (BR-01) | A fully-zeroed, fully-structured statement renders normally — every line and total present at 0.0000 — with a `meta`-sourced banner "No posted transactions for this period," never an error and never a blank canvas |
| Empty (no snapshot for scope, `.generate` absent) | No matching statement exists and the viewer cannot generate one | `EmptyState`: "Ask your Finance Manager to generate this period's Balance Sheet" — the CTA-bearing variant is reserved for holders of `.generate` |
| First fiscal period (no comparative available) | Company has fewer than one prior period | Comparison columns and any two-point-average ratio render `—` with a `reason: "no_prior_period_balance"`-sourced tooltip, never a misleading zero (`FINANCIAL_STATEMENTS.md → Edge Cases`) |
| Stale/superseded | Viewing a non-current version via History, or the underlying fiscal period was reopened after generation | Persistent read-only banner naming the reason; every mutation disabled |
| Negative equity | `TOTAL_EQUITY.current_value < 0` | Rendered without alteration or a warning color on the figure itself (it is a valid, important solvency signal, not a data error); the Risks tab always carries a high-confidence flag regardless of sensitivity threshold |
| Error | `403`/`404`/`5xx`/network failure on any request | `ErrorState` with a retry action; a `403` mid-session (permission revoked while the tab was open) collapses the affected control to its disabled-with-tooltip form rather than crashing the page |

# Responsive Behavior

`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` names Balance Sheet explicitly alongside Trial
Balance and General Ledger as a grid that resolves through rule 3 — sticky-first-column horizontal
scroll — because it is "a genuinely tabular numeric grid that does not decompose into a card at all."
This screen follows that mechanism verbatim at `md:` and above, and deliberately does **not** copy Trial
Balance's per-row mobile card transformation (rule 2) below `md:`, for a reason specific to this
screen's data shape: a Trial Balance row's only structural relationship to its neighbors is "same
account-type group," so flattening each row into its own card loses nothing. A Balance Sheet line's
relationship to its neighbors is a real parent/child rollup (`Total Current Assets` is the sum of `Cash`,
`Receivables`, `Inventories`, …) — stacking every line into an equal-weight card would visually flatten
that hierarchy exactly where it matters most for a "glance at the totals, drill in only where something
looks off" reading pattern.

**Mobile (`base`–`sm`).** The Page Header collapses to a back-chevron + title with the snapshot
`StatusPill`; the as-of-date/comparison/scope/currency controls stack into a horizontally-scrollable
chip row; a `Tabs` switch (`Statement | AI Insights`) replaces the desktop's side-by-side rail, sharing
the page rather than opening a modal. `StatementGrid` keeps its full nested-outline structure at every
tier — subtotal/total rows collapse their children exactly as at desktop — but drops to a single
"Current" money column; the comparison Δ% and common-size %TA columns are reachable via a "Show
comparison" toggle that opens a full-width `Sheet` presenting that expanded column set rather than
squeezing three extra numeric columns into a 390px row, matching the same "additive columns open a
dedicated Sheet below `xl:`" rule Trial Balance's comparison/breakdown columns already establish. The
Balance Identity Band and a collapsed disclosure-gap badge stay pinned above the grid; they never scroll
away, matching the platform rule that a report's headline status must never leave the viewport while a
user reviews the data it summarizes.

**Tablet and up (`md:`+).** `StatementGrid` renders as a real `<table>` with the Line column pinned via
`sticky start-0` (the logical start) and the money columns scrolling horizontally inside their own
`overflow-x-auto` region; a trailing-edge scroll-hint gradient (dismissed once per device) marks that
more columns exist off-screen when comparison and common-size are both active. The AI Insights rail
docks inline in the page grid only from `xl:` (1280px); between `md:` and `xl:` it remains behind the
`Tabs` switch used at mobile, since 360px of dedicated rail width genuinely does not fit alongside a
usable statement grid below that breakpoint.

**Touch and row targets.** Every `StatementGrid` row-toggle button and drill-down trigger meets the
platform's 44×44px touch-target minimum regardless of the 32/40/48px density-driven row height
(`LAYOUT_SYSTEM.md → Density Modes`); this screen defaults to Comfortable density (48px rows) rather
than Journal Entries/Ledger's Compact default, because a Balance Sheet's row count is an order of
magnitude smaller than a ledger's and its per-row scrutiny is correspondingly higher.

**Virtualization is not applied here.** A company's Balance Sheet template rarely exceeds a few dozen
rendered lines even with every branch/department breakdown expanded — nowhere near the roughly-200-row
threshold `RESPONSIVE_DESIGN.md → Virtualization at scale` sets for `@tanstack/react-virtual` adoption.
Mounting a virtualizer here would add complexity with no corresponding performance benefit; the account
grouping that could grow unbounded (thousands of GL accounts) lives one level down, inside the
Drill-down `Sheet`, which does virtualize its own account/journal-line list once it crosses that
threshold.

# RTL & Localization

Every string on this screen ships as an EN/AR pair through `next-intl`, and the screen inherits
`THEMING.md → RTL as a theming dimension` and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a
single per-component RTL branch, plus the module-specific applications below.

- **Bilingual statement lines.** Every line renders `label_ar` when the active locale is `ar` and falls
  back to `label_en` only if the Arabic label is genuinely absent — never the reverse. `StatementGrid`'s
  implementation (`# Components Used`) reads `locale === 'ar' && node.label_ar ? node.label_ar :
  node.label_en` for exactly this reason, matching the identical fallback direction Trial Balance and
  General Ledger use for `account_name_ar`.
- **Numeric alignment is physically fixed, never mirrored** (`LAYOUT_SYSTEM.md → RTL Layout Mirroring →
  Rule 2, Exception A`): every money and percentage column is `text-end` (which, unlike a hard
  `text-right`, still correctly flips with `dir` — the exception here is that the numerals themselves,
  not the column's alignment side, stay LTR-shaped via `dir="ltr"` on the `AmountCell` and Δ%/%TA spans),
  so a bilingual Finance team scanning magnitude by decimal alignment sees amounts land on the same edge
  regardless of session language.
- **The Assets → Liabilities → Equity reading order is content, not layout, and therefore already
  correct under RTL by construction.** Unlike Trial Balance's physically-fixed Debit-before-Credit
  column order (an accounting convention independent of interface language), a Balance Sheet's
  Assets/Liabilities/Equity ordering is vertical, top-to-bottom document structure, not a left-to-right
  column pair — it needs no special-casing because RTL mirroring is an inline-axis (start/end) concern,
  never a block-axis (top/bottom) one.
- **Account codes, snapshot dates, currency codes, and confidence percentages stay LTR-embedded** inside
  Arabic sentences (the Balance Identity Band's variance sentence, the AI variance/risk narrative text)
  via the shared `Bidi`/`LtrInline` wrapper (`unicode-bidi: isolate` + `dir="ltr"`), so a KWD figure or a
  `line_code` never reorders inside a right-to-left paragraph.
- **AI-authored text is generated in the viewer's session locale, not machine-translated client-side.**
  Per `FINANCIAL_STATEMENTS.md`, ratio interpretation, variance narrative, and the CFO Executive Summary
  are produced in English or Arabic matching the company's `locale` setting; the frontend renders that
  prose as-is, and Arabic financial terminology is used precisely: الميزانية العمومية (Balance Sheet),
  الأصول (Assets), الخصوم (Liabilities), حقوق الملكية (Equity), الأصول المتداولة (Current Assets).
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching Gulf
  financial-document convention and the platform-wide rule that QAYD never switches a financial figure
  to Eastern Arabic-Indic digits — identical to `AmountCell`'s own `formatAmount` implementation
  (`COMPONENT_LIBRARY.md`).
- **Directional chrome mirrors; content chrome does not.** The `ChevronRight` expand/collapse glyph
  inside `StatementGrid` carries `rtl:rotate-180` for its collapsed state (pointing toward reading-end in
  both directions) while its *expanded* rotation (`rotate-90`) is direction-neutral since it now points
  block-down, not inline; the Drill-down and History `Sheet`s slide from the logical end edge (left in
  RTL); the `FileBarChart2` statement icon, the `Sparkles` AI glyph, and every status/severity icon do
  not mirror, per `ICONOGRAPHY.md`'s directional-icon table.
- **The `sticky start-0` pinned Line column is the single highest-risk line in this screen's
  implementation**, per `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`'s own warning: a
  physical `left-0` here would pin the wrong edge under `ar` and is treated as a review-blocking defect,
  not a stylistic nit.
- **Charts never mirror.** The Ratios tab has no chart, but any future trend visualization added to this
  rail (e.g., a multi-period equity roll-forward sparkline) keeps a fixed left-to-right time axis
  regardless of `dir`, rendered inside a `dir="ltr"` wrapper, consistent with the platform-wide rule that
  time-series visuals read earliest-to-latest independent of prose direction.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`/`THEMING.md`. Nothing on
this screen references a raw hex value or a Tailwind palette utility; every surface, border, and status
color resolves through the semantic tokens those documents define.

- **Surfaces and elevation.** The canvas sits on `--color-bg-canvas`; the Statement Grid card and its
  sticky header/first-column band sit on `--color-bg-surface`; the AI Insights rail, when docked inline
  at `xl:`+, sits on the same `surface-base` as the grid (it is a page region, not an elevated overlay) —
  only the Drill-down/History/mobile-Filters `Sheet`s use `surface-raised`/`surface-overlay`. Per
  `DARK_MODE.md → Surfaces & Elevation In Dark`, dark-mode elevation is communicated by the surface
  getting *lighter* toward the viewer, not by shadow — the sticky Line column and the sticky header row
  both carry their paired `--color-border-subtle` hairline in dark mode even where the light-mode
  equivalent relies on the column's own background contrast alone, because a borderless dark sticky
  column disappears against the near-black canvas the instant it overlaps a scrolled row.
- **Balance Identity Band tones.** Balanced → `--color-success` is deliberately *not* used for the
  passing state at all — per `DESIGN_LANGUAGE.md`'s Design Principle 5 (quiet certainty, not a
  celebration), the band stays neutral `ink-950`-on-`surface-base` with the color carried only by the
  small checkmark icon. Out of balance → `--color-danger`, the one state on this screen where a
  prominent, saturated treatment is intentional. Partial view (branch-scoped) → `--color-info`.
  Consolidated-preview watermark → a distinct neutral/warning-adjacent striped treatment, never confused
  with either the balanced or out-of-balance tone, since "not yet approved" is an orthogonal concern to
  "does it balance." Disclosure-gap badge → `--color-warning`.
- **Figures never take a status color.** Every money cell in `StatementGrid` renders in
  `--color-fg-primary` regardless of theme, including a negative-equity `TOTAL_EQUITY` figure — per
  `# States`, a negative total is a signal surfaced through the AI Risks tab and, if the company's
  design allows it, a parenthesized-negative numeral convention, never a red number; only the Balance
  Identity Band, severity pills, and the Comparative view's signed variance delta ever carry
  `success`/`warning`/`danger`.
- **AI provenance.** The Risks tab's cards, the Variance tab's `AIProposalPanel`s, and the Narrative
  block all use the single platform accent (`--color-accent`/`--color-accent-subtle-bg`) for their
  `border-s-2` marker and the `Sparkles`/`ConfidenceBadge` treatment — per `DARK_MODE.md → The
  accent/success collision, resolved deliberately`, QAYD's one accent and its "success" finance
  semantic are perceptually distinguishable even in the same hue family precisely so an AI-marked card's
  accent border is never misread as "this is a good financial outcome."
- **Contrast.** Every pairing above is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text,
  borders, focus rings) in both themes per `DARK_MODE.md → Color & Contrast In Dark`; the sticky Line
  column's border is treated as load-bearing, not decorative, and checked at the 3:1 non-text floor
  rather than exempted as a plain divider.
- **Print/export independence.** Exported PDFs always render in QAYD's fixed light/print palette
  regardless of the viewer's active theme (`DARK_MODE.md → Exported PDFs always render light`) — a
  Balance Sheet is routinely forwarded to a bank or an external auditor who never opens the dark-mode
  app at all.

# Accessibility

Baseline is WCAG 2.2 AA, with the platform's stricter internal bar on the sensitive-adjacent Approve,
Share, and Regenerate-final-period flows, per `ACCESSIBILITY.md`, which this screen implements without
deviation.

- **Real table semantics, chosen deliberately over the ARIA `grid` pattern.** The Statement Grid is a
  genuine `<table>` with a `<caption>` ("Balance Sheet — statement of financial position"),
  `scope="col"` headers, and `scope="row"` on each line's identifier cell. Per `ACCESSIBILITY.md → Data
  Tables Accessibility → Two patterns, chosen deliberately per table, never by default`, this screen
  uses the **plain accessible table** pattern, not the heavier ARIA `grid` pattern reserved for
  genuinely spreadsheet-like, cell-by-cell-editable surfaces (the Journal Entry line editor, Bank
  Reconciliation's matching grid) — a Balance Sheet is read, expanded/collapsed, and drilled into, never
  edited in place, so applying `grid` here would add keyboard-handling complexity that makes the table
  *worse* for assistive technology without adding any real capability.
- **Expand/collapse reuses the platform's exact expandable-row pattern.** Each subtotal/total row's
  toggle carries `aria-expanded` and `aria-controls` pointing at the revealed child row-group's `id`,
  identical to the mechanism `ACCESSIBILITY.md → Data Tables Accessibility → Expandable rows and bulk
  selection` specifies for an expandable Journal Entry row — no bespoke tree-widget ARIA pattern
  (`role="treegrid"` and its associated `aria-level`/`aria-setsize` machinery) is introduced, because the
  simpler expandable-row pattern already communicates the hierarchy correctly to a screen reader without
  the added complexity a full tree-grid role would require the rest of the table to also adopt.
- **The Balance Identity Band is a live region proportional to its severity.** `role="status"
  aria-live="polite"` when the state resolves to Balanced (a confirmation, not an interruption); the
  Out-of-balance and the missing-disclosure-gap states escalate to `role="alert"` (assertive) only for
  Out-of-balance specifically, matching `ACCESSIBILITY.md → Live regions`'s tier table (assertive is
  reserved for "blocking errors that stop the current task," which an unbalanced statement genuinely is,
  while a disclosure gap is a warning badge, not an alert). The Consolidated-preview watermark announces
  once, politely, on mount — it is a standing disclaimer, not a recurring interruption.
- **AI content arriving asynchronously never yanks focus or silently mutates what a user is reading.**
  When insights finish generating a few seconds after a fresh snapshot is created, `StatementAIInsightsRail`
  swaps its "Generating…" placeholder for the resolved tabs with a polite live-region announcement
  ("AI analysis ready") rather than auto-focusing the rail or auto-expanding a tab the user has not
  selected, per the platform-wide realtime-accessibility rule `ACCESSIBILITY.md → Live regions` states
  explicitly for Reverb-pushed content.
- **Every disabled control explains itself, and the two disabled *reasons* are never conflated.** A
  "Mark Final" button disabled for lack of `.approve` carries `aria-describedby` naming the exact
  permission ("Requires accounting.financial-statements.approve"); a "Mark Final" button disabled
  because of the *business-rule* state (a missing disclosure note, or unapproved eliminations) carries a
  distinctly worded `aria-describedby` naming the specific line or the elimination-approval requirement
  — per `ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves`, collapsing these two
  into a generic "You can't do this right now" is treated as a P1 defect, not a minor copy issue.
- **Export is omitted, not disabled, from the menu for the same reason Trial Balance's is.** A
  screen-reader user encountering a disabled export item with no visible content behind it cannot tell
  whether the feature exists at all versus is merely unavailable right now; the item is filtered out of
  the `DropdownMenu`'s own data source (`actions.filter(hasPermission)`) rather than rendered disabled,
  matching `NAVIGATION_SYSTEM.md → Command Palette`'s identical filtering rule. Every other
  permission-gated control on this screen (Regenerate, Review, Approve, Share, Schedule) uses the
  disabled-with-tooltip pattern instead, because in those cases the *existence* of the action is not
  itself sensitive information the way "this company can export its financials externally at all" can
  be treated as, for a Read Only or scoped External Auditor role.
- **Focus management on overlays.** The Drill-down Sheet's `onOpenAutoFocus` moves focus to its heading,
  not its close button; closing it (by Escape, backdrop click, or navigating to a linked journal entry)
  returns focus to the triggering line's toggle button. The History Sheet's version rows are
  keyboard-navigable and `Enter` opens the selected version exactly as a click would.
- **Keyboard.** Tab order follows the visual/DOM order (toolbar → Balance Identity Band → Statement Grid
  → AI Insights rail); arrow-key cell navigation is not applicable, since the grid is a read-only display
  table rather than an editable ARIA `grid`, per the same distinction drawn above. `Cmd/Ctrl+Enter`
  confirms the Regenerate-reason dialog and the Approve/Reject confirmation dialogs. Every
  `ConfidenceBadge`'s "why 92%?" tooltip is reachable by keyboard focus, not only by hover.
- **Screen-reader amount reading.** Every `AmountCell` carries the platform's triple redundancy — a
  leading glyph where relevant, a directional/sign cue, and a `VisuallyHidden` text equivalent — so a
  negative-equity total or a negative variance is never conveyed by color alone, matching
  `ACCESSIBILITY.md → Color & Contrast → Debit/credit and positive/negative are never color-only`.

# Performance

- **Snapshot-first serving for closed periods.** Per `FINANCIAL_STATEMENTS.md → Report Generation
  Engine → Historical`, once a closed period's Balance Sheet has been generated once, every subsequent
  request for the exact same (company, scope, as_of_date, currency, template_version) combination is a
  single indexed lookup against `financial_statement_snapshots`/`financial_statement_snapshot_lines` —
  no ledger aggregation runs at all. This is the dominant case in practice: the large majority of
  Balance Sheet views on this screen are for already-closed, already-`final` periods (board packs,
  comparatives, audits), and the frontend benefits from this simply by not special-casing the request —
  the same `GET .../balance-sheet` call resolves instantly whether it lands on a cached snapshot or
  triggers live computation.
- **60-second Redis cache for the current open period.** Live-mode generation is bounded by the
  backend's own 60-second result cache keyed on the full request fingerprint
  (`FINANCIAL_STATEMENTS.md → Report Generation Engine → Real-Time`); this screen's own TanStack Query
  `staleTime` for `mode: 'live'` is tuned to the identical 60 seconds (`# Data & State → Cache tuning`)
  specifically so the client never refetches more eagerly than the server could possibly return a
  different answer, and never so lazily that a real posting goes unreflected for materially longer than
  the server's own cache window.
- **Consolidation is always asynchronous, never a blocking request.** Because a consolidated run
  generates N subsidiary statements, translates each, and runs the elimination pipeline, it is always
  dispatched as a queued Laravel job even for a `preview` (`FINANCIAL_STATEMENTS.md → Performance →
  Consolidation cost isolation`); the frontend never holds a loading spinner open for a multi-subsidiary
  computation — it receives a job reference immediately and subscribes to the snapshot's Reverb channel,
  letting the user keep the Statement Grid interactive (e.g., reviewing the prior period's figures) while
  the new consolidated run completes in the background.
- **AI enrichment never blocks the primary render.** A snapshot is fully viewable, drillable, and
  exportable the instant it is generated; the AI Insights rail populates a few seconds to a couple of
  minutes later depending on job queue depth (`FINANCIAL_STATEMENTS.md → Performance → Asynchronous AI
  enrichment`). The rail's "Generating…" placeholder is a `Skeleton`, never a loading state that disables
  the rest of the page.
- **Common-size and Horizontal Analysis are free.** Because both are pure transformations of already-
  fetched `financial_statement_snapshot_lines` values, toggling either never issues a network request —
  the only network cost on this screen beyond the primary statement fetch is the Drill-down Sheet
  (on demand), the AI Insights fetch (prefetched alongside the primary statement, `# Data & State`), and
  the History Sheet (deferred, opened on demand).
- **Deferred, code-split overlays.** The Share dialog, the Schedule dialog, the History `Sheet`, and the
  Export `DropdownMenu`'s content are dynamically imported (`next/dynamic`) rather than bundled into the
  initial route chunk, per `FRONTEND_ARCHITECTURE.md → Performance → Code splitting` — none of the four
  is needed for first paint.
- **Debounced, cancellable toolbar changes.** Rapidly cycling the as-of date, comparison type, or scope
  selector debounces 200ms and cancels any in-flight `view(params)` request via
  `queryClient.cancelQueries` before issuing the next one, matching Trial Balance's identical comparison-
  fetch debouncing — a Finance Manager clicking through several prior periods in a row never stacks
  redundant, out-of-order network responses that could otherwise resolve and repaint after a later click.
- **SSR-seeded first paint.** Per `# Data & State`'s hydration pattern, the primary statement and its AI
  Insights for the resolved default scope are fetched once, server-side, and hydrated into the client
  cache — the client's first `useQuery` call for the same keys resolves instantly rather than re-issuing
  a request the server already made.
- **Index-backed lookups.** The specific composite/partial indexes `FINANCIAL_STATEMENTS.md → Database
  Design → Indexes` defines (`idx_fs_snapshots_balance_sheet_current`, scoped to
  `statement_type = 'balance_sheet' AND status = 'final'`) exist specifically to make this screen's most
  common query — "the current final Balance Sheet for company X as of date Y" — a single index lookup
  rather than a sequential scan across every statement type and status a company has ever generated.

# Edge Cases

1. **First fiscal period of a new company.** No prior-period comparative exists; the comparison columns
   and any two-point-average ratio return `null` with an explicit `reason` code rather than a misleading
   zero (`# States`, `FINANCIAL_STATEMENTS.md → Edge Cases`). `ComparisonSelect`'s "vs. Prior Year"
   option remains selectable but resolves to the same null-with-reason display rather than being
   disabled outright, since a first-year company may still legitimately want to confirm no comparative
   exists yet.
2. **Branch-scoped view that does not independently balance.** Rendered as the distinct "Partial view"
   `info`-tone state (`# States`), with copy explicit that only the company-level (unscoped) Balance
   Sheet is the authoritative balance check — getting this wrong (rendering it identically to a real
   imbalance) would train reviewers to distrust a correct, by-design branch view.
3. **Consolidated scope with unapproved eliminations.** The screen never presents a consolidated figure
   as authoritative until BR-06's approval gate clears; the Preview watermark is persistent and
   undismissable, and Export/Share/Schedule/Mark Final are omitted entirely rather than disabled, since
   their *existence* on an unapproved preview would itself be misleading.
4. **Regenerating a statutory-filed year-end statement.** Blocked from direct mutation by VR-10's
   database trigger regardless of what the UI does; the Regenerate action's mandatory-reason dialog is
   this screen's only path forward, and the result is always a new, explicitly reasoned superseding
   snapshot with the original remaining fully browsable from History — mirroring how a real restatement
   is handled under IAS 8.
5. **Exchange rate gap on a currency switch.** VR-08 blocks the switch rather than defaulting to a rate
   of 1.0; the inline error names the exact currency pair and date a Finance Manager needs to resolve
   via a manual policy rate in Settings before retrying, and the toolbar's currency selector visibly
   reverts to the last-successful value rather than appearing to have silently applied the new one.
6. **Negative equity.** Rendered without alteration — it is a valid, important solvency signal, not a
   rendering error — and always carries a high-confidence AI risk flag regardless of the company's
   configured anomaly sensitivity threshold (`# AI Integration`, `# States`).
7. **Zero-transaction period.** Per BR-01, the screen renders a fully-structured, all-zero statement
   rather than an error or an empty canvas — every line and total present at 0.0000 — important for a
   newly onboarded company or a dormant branch/subsidiary.
8. **Subsidiary acquired or disposed of mid-year, viewed in a Consolidated scope.** The consolidated
   figure includes the subsidiary only from its acquisition date (or up to its disposal date), never a
   full-year pro-rata estimate; any resulting goodwill, bargain-purchase gain, or disposal gain/loss
   (including CTA recycling) appears as a distinct, clearly labeled line rather than being absorbed
   silently into Retained Earnings.
9. **Mid-year base-currency change.** An extremely rare, formally-recorded event; any comparative or
   trend view spanning the change date carries an explicit footnote (sourced from the mandatory
   disclosure Note the backend requires) disclosing the change and the historical conversion basis used
   — the screen never silently re-expresses history in the new currency.
10. **Two users viewing the same live statement concurrently.** The 60-second Redis cache serves both
    requesters the same result rather than double-running the aggregation query; if a relevant posting
    occurs inside that window, the backend's targeted cache-bust on `journal_entries` posting events
    (not solely TTL expiry) means the second requester is not guaranteed to see a stale-but-not-yet-
    expired figure the way a pure-TTL cache would risk.
11. **A closed fiscal period is later reopened.** Any snapshot generated against that period keeps
    rendering as valid history when opened directly via History, but loses `is_current` status and — per
    the same pattern Trial Balance applies — the screen surfaces an informational note that the
    underlying period was reopened and the statement should be regenerated before being relied on as
    current.
12. **Session permission change mid-view.** If a role change revokes `.approve` while an `ApprovalCard`
    is open in the Narrative tab or the snapshot status menu, the next mutation attempt's `403` collapses
    that control to its read-only rendering with a toast explaining why, rather than a broken or stuck
    loading state — the frontend never assumes its own last-known permission snapshot is still valid.
13. **Company switch mid-Regenerate.** Switching the active company discards all cached Balance Sheet
    state per the platform's company-switch contract (`FRONTEND_ARCHITECTURE.md → Company switching`);
    if the mandatory-reason Regenerate dialog is open with an unsaved reason typed in, the switcher's own
    confirmation dialog names exactly that in-progress action before proceeding.
14. **A material line lacks a required disclosure note at the moment "Mark Final" is attempted.** The
    422 response names the specific line; the UI's "Jump to line" link scrolls the Statement Grid to and
    briefly highlights that exact row rather than leaving the reviewer to hunt for which of perhaps
    thirty lines triggered the block.
15. **An external share link's recipient opens it after the underlying snapshot has been superseded.**
    Per `financial_statement_shares.snapshot_id` always pointing at one immutable snapshot rather than a
    "live" statement, the shared view continues rendering the exact figures that existed at share-creation
    time, with no indication to the external recipient that a newer version now exists internally —
    consistent with the platform's rule that a share link never silently changes underneath its recipient.

# End of Document
