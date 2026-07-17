# Trial Balance — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: TRIAL_BALANCE
---

# Purpose

The Trial Balance screen is where QAYD's frontend renders the one number a Finance Manager, Accountant,
or External Auditor trusts more than any other in the product: proof that the ledger is internally
consistent. It is a **Report Page Template** instance (`docs/frontend/LAYOUT_SYSTEM.md`) — a dense,
print-faithful, read-mostly canvas — layered with the period-close workflow defined in
`docs/accounting/TRIAL_BALANCE.md`: Generate → Validate → Review → Approve → Export → Archive. This
document specifies how that workflow, that data, and that AI layer become pixels, routes, TanStack Query
hooks, and Framer Motion states in the Next.js 15 App Router application, consuming the shared conventions
already fixed by `FRONTEND_ARCHITECTURE.md`, `COMPONENT_LIBRARY.md`, `DESIGN_LANGUAGE.md`,
`NAVIGATION_SYSTEM.md`, `LAYOUT_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, `ACCESSIBILITY.md`,
`THEMING.md`, and `ICONOGRAPHY.md`.

Three properties make this screen different from an ordinary list-and-detail page, and every section below
is organized around them. First, **the screen has no primary data of its own** — every figure it shows is
a `trial_balance_snapshots`/`trial_balance_snapshot_lines` row computed once by
`accounting.fn_compute_trial_balance` and frozen at generation time; the frontend never recomputes a
balance, never sums lines client-side for anything that will be submitted, and never lets a user believe a
number is "live" when it is a snapshot. Second, **the screen has a single, unmissable invariant** — total
debit equals total credit — and the entire visual hierarchy exists to make that invariant impossible to
overlook, whether it holds or breaks. Third, **the screen is where AI review is most load-bearing**: the
Auditor and Fraud Detection agents attach structured findings to every snapshot before a human opens it,
and this screen is the first place in the product where "AI proposes, a human approves" has to work for a
number that regulators, banks, and external auditors will eventually read.

The audience is Accounting, Finance, and Audit only — Owner, Admin/CEO, CFO, Finance Manager, Senior
Accountant, Accountant, and Auditor/External Auditor, per the role table in
`docs/accounting/TRIAL_BALANCE.md → Permissions` and the platform default role list in
`DESIGN_CONTEXT.md`. There is no self-service employee view of this screen; a Sales Employee or Warehouse
Employee never sees the Trial Balance nav item at all, because the Accounting module itself is gated by
`accounting.read` in the Sidebar (`NAVIGATION_SYSTEM.md → Primary Navigation`).

# Route & Access

| Property | Value |
|---|---|
| App Router path | `app/(app)/accounting/trial-balance/page.tsx` |
| Route group / shell | `(app)`, nested under `app/(app)/accounting/layout.tsx` (sub-nav: Chart of Accounts \| Journal Entries \| Ledger \| Trial Balance) |
| Nav entry | Sidebar → Accounting → **Trial Balance**, icon `Scale` (`ICONOGRAPHY.md` Semantic Icon Set), href `/accounting/trial-balance` |
| Breadcrumb | `Accounting / Trial Balance` (module-rooted, per `NAVIGATION_SYSTEM.md → Breadcrumbs`) |
| Rendering mode | `export const dynamic = "force-dynamic"; export const fetchCache = "default-no-store";` — tenant-scoped, never statically cached (`FRONTEND_ARCHITECTURE.md`) |
| View permission | `accounting.trial_balance.read` (parent gate: `accounting.read`) |
| Deep-linkable state | Query string only — `?type=unadjusted\|adjusted\|post_closing`, `&fiscal_period_id=` or `&as_of_date=`, `&branch_id=`, `&department_id=`, `&project_id=`, `&cost_center_id=`, `&compare_with=` (a second snapshot id or period). There is no `[snapshotId]` dynamic segment; a *specific historical version* opened from the History panel is also expressed as `?snapshot_id=` on the same route, never a separate path, so the page's data-fetching and permission logic stays in one place. |

Every mutating control on the page checks a distinct permission from `docs/accounting/TRIAL_BALANCE.md →
Permissions`, and the frontend never invents a looser check than the API enforces:

| Control | Permission | Rendered when absent |
|---|---|---|
| Open the screen at all | `accounting.trial_balance.read` | Route hidden from Sidebar/Command Palette; direct navigation renders the shell's `403` boundary |
| Generate / Refresh (new version) | `accounting.trial_balance.generate` | Button visible, `disabled`, tooltip "Requires `accounting.trial_balance.generate`" |
| Start Review / assign reviewer | `accounting.trial_balance.review` | Omitted from the Findings Bar's action row |
| Resolve / dismiss a finding | `accounting.trial_balance.finding.manage` | Finding card's action buttons disabled, tooltip shown |
| Record an approval step | `accounting.trial_balance.approve` | `ApprovalCard` renders read-only (no Approve/Reject/Delegate) |
| Export (PDF/XLSX/CSV) | `accounting.trial_balance.export` | Export menu item omitted from `DropdownMenu` — see `Accessibility` for why export uses the *omit* rule, not disable |
| Archive | `accounting.trial_balance.archive` | Button omitted; only ever offered on `approved` snapshots regardless |
| History panel | `accounting.trial_balance.history.read` | "History" trigger in the header omitted |
| Post a documented balancing/historical-variance adjustment | `accounting.trial_balance.override.post` | The Owner/Admin-only "Record override adjustment" action inside an unresolved-imbalance finding is omitted for every other role |

Role grants, reproduced from the backend spec's own table and mapped onto the platform's default role
vocabulary (`DESIGN_CONTEXT.md`) so this screen's `Can` checks read naturally against either name set —
Owner/CEO/CFO collapse to the backend table's "Owner"/"Admin" tier, Senior Accountant collapses to
"Accountant", and Read Only/External Auditor collapse to a read-plus-history-only guest of "Auditor":

| Permission | Owner / CEO / CFO | Finance Manager | Senior Accountant / Accountant | Auditor / External Auditor |
|---|---|---|---|---|
| `.read` | Yes | Yes | Yes | Yes |
| `.generate` | Yes | Yes | Yes | No |
| `.review` | Yes | Yes | Yes | Yes |
| `.finding.manage` | Yes | Yes | Yes | Yes |
| `.approve` | Yes | Yes | No | PCTB sign-off step only |
| `.export` | Yes | Yes | Yes | Yes |
| `.archive` | Yes | Yes | No | No |
| `.history.read` | Yes | Yes | Yes | Yes |
| `.override.post` | Yes | No | No | No |

# Layout & Regions

The screen is a **Report Page Template** (`LAYOUT_SYSTEM.md → Page Templates`) rendered inside a
`Container` with `variant="full-bleed"` — reports get the wider 1600px ceiling at `3xl`, never the 1440px
reading cap, because a Trial Balance with a branch/department breakdown genuinely needs the columns. Five
stacked regions, top to bottom, each a real content region rather than a decorative panel:

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Accounting / Trial Balance                                  [Density ▾]    │ Report Header
│ Trial Balance — Jul 2026                     [Approved]  [History] [⋯]     │ (PageHeader)
│ Unadjusted ▾ | Jul 2026 ▾  vs. Jun 2026 ▾ | Branch ▾ Dept ▾ Project ▾ CC ▾  │ (PeriodPicker +
│                                                  [↻ Refresh]  [Export ▾]    │  filter row)
├────────────────────────────────────────────────────────────────────────────┤
│ ✓ Balanced — total debit KD 1,284,502.760 = total credit KD 1,284,502.760  │ Status Banner
├────────────────────────────────────────────────────────────────────────────┤
│ ⚠ 2 findings — suspense balance (high) · stale FX rate (low)         [▾]   │ Findings Bar
├──────┬──────────────────────────────┬────────────┬───────────┬────────────┤ (collapsible)
│ Code │ Account                      │ Opening Dr │ Period Dr │ Closing Dr │
│      │ ▸ Assets                     │            │           │            │ Report Canvas
│ 1000 │   Cash — NBK Current          │  92,400.000│  8,200.000│ 100,600.000│ (grouped by
│ 1120 │   Accounts Receivable        │  41,880.500│  2,140.000│  44,020.500│  account type,
│      │ ▸ Liabilities                │            │           │            │  sticky first
│ 2100 │   Accounts Payable           │  18,220.000│  1,010.000│  19,230.000│  column + header
│  …   │   …                           │     …      │     …     │     …      │  + totals row)
├──────┴──────────────────────────────┴────────────┴───────────┴────────────┤
│ TOTAL                                1,284,502.760      1,284,502.760 ✓    │ (sticky footer)
└────────────────────────────────────────────────────────────────────────────┘
```

**Report Header.** Breadcrumb + `PageHeader` title with a `StatusPill` for the snapshot's own status
(`generated`/`validated`/`under_review`/`approved`/`archived`/`out_of_balance`); a segmented `Tabs`-style
`ToggleGroup` for Unadjusted / Adjusted / Post-Closing (disabled tabs for a type that has not yet been
generated for this period, with a tooltip offering to generate it); a `PeriodPicker` for the fiscal period
or explicit as-of date; a comparison-period `Combobox` ("vs. Jun 2026", "vs. no comparison"); a filter row
of four pickers — Branch, Department, Project, Cost Center — each a lightweight `Combobox` over its own
`/api/v1/…` list endpoint; a density toggle (`Comfortable` default per `LAYOUT_SYSTEM.md → Density Modes`,
since Trial Balance is lower-row-count than the Ledger); a **History** trigger (opens
`TrialBalanceHistorySheet`); a Refresh/Generate primary action; and an **Export** `DropdownMenu`.

**Status Banner.** A full-width, unmissable, non-dismissable band directly under the header — the
must-balance indicator. It is not a toast and not a card the user can collapse away; per Design Principle 3
("numbers are the hero"), this is the one region on the page that behaves like a fixed system state rather
than content. Its exact states are specified in full in `# States` below.

**Findings Bar.** A collapsible strip (open by default whenever any finding has `severity IN ('high',
'critical')` or `status = 'open'`; collapsed by default otherwise) listing AI/system findings from
`trial_balance_ai_findings`, each rendered as a compact row with severity icon, title, and a "View" action
that expands into the full `AIProposalPanel` treatment described in `# AI Integration`.

**Report Canvas.** The table itself: grouped by `account_types.display_order` then `accounts.code`
(matching the backend's own display convention), each group with a collapsible header and a subtotal row,
a sticky identifier column (`account_code` + bilingual name), horizontally-scrolling money columns
(Opening Dr/Cr, Period Dr/Cr, Closing Dr/Cr, plus a Variance column pair when a comparison period is
active), and a sticky totals `<tfoot>` that never scrolls out of view — see `RESPONSIVE_DESIGN.md →
Finance Tables On Small Screens` for the exact sticky-column mechanics this canvas reuses verbatim.

**Drill-down Panel (not shown above — an overlay, not a fifth stacked region).** A `Sheet` sliding from
the logical end edge, opened by clicking any line's closing balance cell:

```
┌─ Drill-down — 1120 · Accounts Receivable ─────────────────── ✕ ┐
│ Closing balance: Dr 44,020.500 · 12 posted lines                │
├───────────────────────────────────────────────────────────────┤
│ Date    Entry #        Description         Debit     Credit    │
│ Jul 03  JE-2026-0410    Invoice INV-2091   2,140.000       –    │
│ Jul 14  JE-2026-0482    AR write-off             –    1,200.000 │
│ …       …               …                       …         …     │
├───────────────────────────────────────────────────────────────┤
│           [View in General Ledger]   [Open journal entry →]     │
└───────────────────────────────────────────────────────────────┘
```

# Components Used

Every visual element on this screen is a primitive from `COMPONENT_LIBRARY.md`, a named finance component
already catalogued there, or a screen-specific composition of both living in
`components/accounting/trial-balance/*`. Nothing here is hand-rolled outside that library.

| Component | Source | Role on this screen |
|---|---|---|
| `PageHeader` | `components/layout/page-header.tsx` | Breadcrumb, title, `StatusPill`, action slot |
| `PeriodPicker` | `components/accounting/period-picker.tsx` (named finance component, `COMPONENT_LIBRARY.md`) | Fiscal period / explicit as-of-date selection, and the comparison-period `Combobox` |
| `ToggleGroup` (shadcn/Radix `RadioGroup`) | `components/ui/toggle-group.tsx` | Unadjusted / Adjusted / Post-Closing segmented control |
| `Combobox` (built on `Command`+`Popover`, same pattern as `AccountPicker`) | `components/shared/combobox.tsx` | Branch / Department / Project / Cost Center filter pickers |
| `StatusPill` | `components/accounting/status-pill.tsx` | Snapshot status, finding severity, line's abnormal-balance flag |
| `TrialBalanceStatusBanner` | `components/accounting/trial-balance/status-banner.tsx` (screen-specific) | The must-balance indicator described under Layout & Regions |
| `TrialBalanceFindingsBar` | `components/accounting/trial-balance/findings-bar.tsx` (screen-specific) | Collapsible findings strip, composes `AIProposalPanel` + `ConfidenceBadge` |
| `TrialBalanceTable` | `components/accounting/trial-balance/trial-balance-table.tsx` (screen-specific, wraps `DataTable`'s sticky-finance-table pattern) | Grouped, totalled, sticky report canvas |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every money cell; `emphasis="strong"` on subtotal/total rows |
| `KpiTile` | `components/accounting/kpi-tile.tsx` (named finance component) | Optional compact summary strip above the canvas on wide screens: Total Debit, Total Credit, Variance, Account Count |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` (named finance component) | Multi-currency breakdown chip on a line with `currency_breakdown` entries beyond the base currency |
| `TrendSparkline` | `components/accounting/trend-sparkline.tsx` (named finance component) | Mini variance trend inside the Comparative view's variance column header |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` (named finance component) | Full expanded view of a `trial_balance_ai_findings` row: title, narrative, `supporting_data`, `suggested_action`, and — when present — a link to the AI-drafted correcting `journal_entries` row |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Renders `confidence` (0–1) on any `source = 'ai'` finding |
| `ApprovalCard` | `components/shared/approval-card.tsx`, `kind="journal_entry"`-equivalent extended with `kind="trial_balance"` | One card per required approval-chain step |
| `Sheet` | `components/ui/sheet.tsx` | Drill-down Panel, History Panel, mobile Filter sheet |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `alert-dialog.tsx` | Generate configuration dialog, Archive confirmation, override-adjustment confirmation |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Export menu, row-level "⋯" actions |
| `Tooltip` | `components/ui/tooltip.tsx` | Permission-denied explanations, "Why 92%?" confidence previews |
| `Skeleton` | `components/ui/skeleton.tsx` | Loading state, shape-matched to the canvas |
| `EmptyState` / `ErrorState` | `components/shared/empty-state.tsx`, `error-state.tsx` | See `# States` |
| `useApiToast` | `hooks/use-api-toast.ts` | Every mutation's success/error surface |
| `Can` | `components/auth/can.tsx` | Every permission-gated affordance in the table above |

`TrialBalanceTable`'s prop shape extends the `ResponsiveColumnDef<TData>` pattern already defined in
`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` with one addition this screen specifically needs
— grouping:

```tsx
// components/accounting/trial-balance/trial-balance-table.tsx
interface TrialBalanceTableProps {
  lines: TrialBalanceLine[];
  groupBy: "account_type" | "none";
  showComparison: boolean;
  density: "comfortable" | "compact";
  onDrillDown: (line: TrialBalanceLine) => void;
  totals: { totalDebit: string; totalCredit: string; variance: string };
  currencyCode: string;
}
```

# Data & State

## Endpoints consumed

All under `/api/v1/accounting/trial-balance`, Bearer + `X-Company-Id`, standard envelope, per
`docs/accounting/TRIAL_BALANCE.md → API`:

| Method | Path | Permission | Used for |
|---|---|---|---|
| GET | `/trial-balance` | `.read` | Resolve the current snapshot for the active `type`/period/scope on page load; power the History list |
| GET | `/trial-balance/{id}` | `.read` | Snapshot header (status, totals, variance, warnings) |
| GET | `/trial-balance/{id}/lines` | `.read` | Paginated, groupable line data feeding `TrialBalanceTable` |
| GET | `/trial-balance/{id}/findings` | `.read` | Findings Bar + AI Integration panel |
| GET | `/trial-balance/{id}/drill-down` | `.read` | Drill-down Panel's contributing `journal_lines` |
| GET | `/trial-balance/comparative` | `.read` | Comparison-period variance columns |
| GET | `/trial-balance/{id}/history` | `.history.read` | History Panel's version lineage |
| POST | `/trial-balance/generate` | `.generate` | Generate dialog submit (first-time or new-type) |
| POST | `/trial-balance/{id}/refresh` | `.generate` | Refresh action on an existing logical snapshot |
| PATCH | `/trial-balance/findings/{findingId}` | `.finding.manage` | Acknowledge / resolve / dismiss a finding |
| POST | `/trial-balance/{id}/review` | `.review` | "Start Review" |
| POST | `/trial-balance/{id}/approve` | `.approve` | Each `ApprovalCard` step |
| POST | `/trial-balance/{id}/export` | `.export` | Export menu |
| GET | `/trial-balance/{id}/export/{exportId}` | `.export` | Re-download a prior export |
| POST | `/trial-balance/{id}/archive` | `.archive` | Archive confirmation |

## Query keys

```ts
// lib/api/query-keys.ts (extends the factory pattern from FRONTEND_ARCHITECTURE.md)
export const trialBalanceKeys = {
  all: ["accounting", "trial-balance"] as const,
  current: (scope: TrialBalanceScope) => [...trialBalanceKeys.all, "current", scope] as const,
  detail: (snapshotId: number) => [...trialBalanceKeys.all, "detail", snapshotId] as const,
  lines: (snapshotId: number, filters: TrialBalanceLineFilters) =>
    [...trialBalanceKeys.detail(snapshotId), "lines", filters] as const,
  findings: (snapshotId: number) => [...trialBalanceKeys.detail(snapshotId), "findings"] as const,
  drillDown: (snapshotId: number, accountId: number) =>
    [...trialBalanceKeys.detail(snapshotId), "drill-down", accountId] as const,
  history: (logicalKey: TrialBalanceLogicalKey) => [...trialBalanceKeys.all, "history", logicalKey] as const,
  comparative: (snapshotIds: number[]) => [...trialBalanceKeys.all, "comparative", snapshotIds] as const,
};
```

`TrialBalanceScope` is `{ type, fiscal_period_id | as_of_date, branch_id, department_id, project_id,
cost_center_id }` — the same logical key the backend uses for `is_current`. Switching any filter
re-resolves `current(scope)` first (a cheap `GET /trial-balance?…&is_current=true&per_page=1` lookup)
before fetching `detail`/`lines`, so a filter change never assumes the previously-loaded snapshot id is
still the right one.

## Cache tuning by snapshot status

Cache lifetime is a function of the snapshot's own `status`, not a single default, mirroring
`FRONTEND_ARCHITECTURE.md → Cache tuning by data class`:

| `status` | `staleTime` | Rationale |
|---|---|---|
| `generating` | `0`, polled every 2s as a fallback if Reverb is unavailable | Actively changing; correctness over efficiency |
| `generated` / `validated` / `under_review` / `out_of_balance` | 30 seconds | Findings can still be resolved/dismissed by another reviewer concurrently |
| `approved` | 5 minutes | Header/lines are structurally immutable per the backend's `trg_journal_entry_balance_check`-adjacent immutability trigger; only lifecycle metadata (archived, exports) can still change |
| `archived` | `Infinity` (cached until an explicit `queryClient.invalidateQueries` on `archived` event) | Immutable, locked, permanent |

## Realtime

Two Laravel Reverb channels, per the platform-wide `private-company.{id}.<feature>[.{sub_id}]` convention
(`FRONTEND_ARCHITECTURE.md → Realtime`):

| Channel | Events | Effect |
|---|---|---|
| `private-company.{id}.trial-balance.{snapshot_id}` | `trial_balance.generated`, `trial_balance.validated` | Subscribed only while a specific snapshot's `status = 'generating'`; on `generated` the client invalidates `detail`/`lines`/`findings` for that id and the Status Banner animates from the skeleton to the resolved state |
| `private-company.{id}.notifications.{user_id}` | `trial_balance.out_of_balance_detected`, `trial_balance.critical_finding`, `trial_balance.review_requested`, `trial_balance.approval_step_completed`, `trial_balance.approved`, `trial_balance.rejected`, `trial_balance.exported`, `trial_balance.archived`, `trial_balance.ai_suggested_correction` | Topbar bell + toast; a toast whose event matches the currently-open snapshot id additionally triggers a targeted `invalidateQueries` rather than waiting for the next `staleTime` window |

## AI agents feeding this screen

Per `docs/accounting/TRIAL_BALANCE.md → AI Responsibilities`: **Auditor** (imbalance detection, variance
explanation, suggested corrections), **Fraud Detection** (pattern/anomaly findings, escalated to a
mandatory Fraud Review approval step when `severity = 'critical'`), **Forecast Agent** (pre-close
`forecasted_error` findings), and **Reporting Agent** (`variance_pattern` annotations on the Comparative
view). None of the four writes to the database directly; each produces a `trial_balance_ai_findings` row
or, for suggested corrections, a `draft` `journal_entries` row that this screen only *links to*, never
edits inline.

## Mutations — optimistic vs. pessimistic

Per Principle 10 (`FRONTEND_ARCHITECTURE.md`): reversible, non-financial actions are optimistic; anything
that changes the ledger's authoritative state is pessimistic.

```ts
// hooks/accounting/use-trial-balance.ts
export function useDismissFinding(snapshotId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ findingId, reason }: { findingId: number; reason: string }) =>
      api.patch(`/accounting/trial-balance/findings/${findingId}`, { status: "dismissed", resolution_note: reason }),
    onMutate: async ({ findingId }) => {
      await qc.cancelQueries({ queryKey: trialBalanceKeys.findings(snapshotId) });
      const previous = qc.getQueryData(trialBalanceKeys.findings(snapshotId));
      qc.setQueryData(trialBalanceKeys.findings(snapshotId), (old: Finding[]) =>
        old.map((f) => (f.id === findingId ? { ...f, status: "dismissed" } : f)));
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(trialBalanceKeys.findings(snapshotId), ctx?.previous),
    onSettled: () => qc.invalidateQueries({ queryKey: trialBalanceKeys.findings(snapshotId) }),
  });
}

export function useApproveTrialBalanceStep(snapshotId: number) {
  // No onMutate — approving a Trial Balance step is a financial-workflow action; the UI only
  // shows "approved" after the server's 2xx, per Principle 10's "pessimistic where it moves money."
  return useMutation({
    mutationFn: (body: { action: "approved" | "rejected" | "requested_changes"; comment: string }) =>
      api.post(`/accounting/trial-balance/${snapshotId}/approve`, body, crypto.randomUUID()),
  });
}
```

## Form schemas

```ts
// lib/schemas/trial-balance.ts
export const generateTrialBalanceSchema = z
  .object({
    type: z.enum(["unadjusted", "adjusted", "post_closing"]),
    fiscal_period_id: z.number().int().positive().nullable(),
    as_of_date: z.string().date().optional(),
    branch_id: z.number().int().positive().nullable().optional(),
    department_id: z.number().int().positive().nullable().optional(),
    project_id: z.number().int().positive().nullable().optional(),
    cost_center_id: z.number().int().positive().nullable().optional(),
    breakdown: z.object({
      by_branch: z.boolean().default(false),
      by_department: z.boolean().default(false),
      by_project: z.boolean().default(false),
      by_cost_center: z.boolean().default(false),
    }),
    include_zero_balances: z.boolean().default(false),
  })
  .refine((v) => v.fiscal_period_id !== null || Boolean(v.as_of_date), {
    message: "validation.trialBalance.periodOrDateRequired",
    path: ["fiscal_period_id"],
  });

export const resolveFindingSchema = z.object({
  status: z.enum(["acknowledged", "resolved", "dismissed"]),
  resolution_note: z.string().min(1, "validation.trialBalance.reasonRequired"),
});

export const approvalStepSchema = z.object({
  action: z.enum(["approved", "rejected", "requested_changes"]),
  comment: z.string().min(1, "validation.trialBalance.approvalCommentRequired"),
  // Business Rule 8: a snapshot containing a non-zero suspense balance requires an explicit
  // acknowledging comment at the approval step — enforced client-side as UX, re-enforced server-side.
});
```

## SSR hydration

```tsx
// app/(app)/accounting/trial-balance/page.tsx
export default async function TrialBalancePage({ searchParams }: { searchParams: Record<string, string> }) {
  const queryClient = getQueryClient();
  const scope = parseTrialBalanceScope(searchParams); // { type, fiscal_period_id|as_of_date, branch_id, ... }

  const current = await apiServer.get("/accounting/trial-balance", {
    params: { ...scope, is_current: true, per_page: 1 },
  });
  const snapshotId = current.data[0]?.id ?? null;

  if (snapshotId) {
    await Promise.all([
      queryClient.prefetchQuery({
        queryKey: trialBalanceKeys.detail(snapshotId),
        queryFn: () => apiServer.get(`/accounting/trial-balance/${snapshotId}`),
      }),
      queryClient.prefetchQuery({
        queryKey: trialBalanceKeys.lines(snapshotId, {}),
        queryFn: () => apiServer.get(`/accounting/trial-balance/${snapshotId}/lines`, { params: { per_page: 100 } }),
      }),
      queryClient.prefetchQuery({
        queryKey: trialBalanceKeys.findings(snapshotId),
        queryFn: () => apiServer.get(`/accounting/trial-balance/${snapshotId}/findings`),
      }),
    ]);
  }

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <TrialBalanceScreen initialScope={scope} initialSnapshotId={snapshotId} />
    </HydrationBoundary>
  );
}
```

# Interactions & Flows

**First-time / empty scope.** No `is_current` snapshot exists for the resolved scope. The canvas renders
`EmptyState` ("No Trial Balance generated for July 2026 yet") with a primary "Generate" action (gated on
`.generate`). Clicking it opens the Generate `Dialog`, pre-filled with the resolved scope; submitting calls
`POST /trial-balance/generate` and, for a large scope, receives `202 Accepted` with `status: "generating"`
— the dialog closes and the Status Banner shows a determinate-feeling "Generating…" skeleton while the
Reverb subscription waits for `trial_balance.generated`.

**Switching type (Unadjusted → Adjusted → Post-Closing).** Each tab is an independent logical snapshot.
If no Adjusted snapshot exists yet for the period, the tab is disabled with a tooltip "Generate the
Unadjusted Trial Balance first" (Business Rule 4 auto-chains this server-side once triggered, but the UI
still explains the dependency rather than silently disabling); attempting to open a `post_closing` tab
before the fiscal year is closed shows the same disabled-with-explanation pattern citing Business Rule 3.

**Filtering by Branch / Department / Project / Cost Center.** Each picker change updates the URL query
string, re-resolves `current(scope)`, and — per Business Rule 7 — if the resulting scoped snapshot does
not balance to zero, the Status Banner switches to its dedicated "Partial view" treatment (`# States`)
rather than the "Out of balance" treatment; these are deliberately different visual states so a Branch
Manager never mistakes an expected dimensional imbalance for a bookkeeping error.

**Comparison period.** Selecting "vs. Jun 2026" calls `GET /trial-balance/comparative?snapshot_ids=9081,9010`
(or the equivalent `fiscal_period_ids` form when comparing logical periods rather than specific versions)
and adds a Variance amount/percent column pair per line, highlighted (`Badge` `tone="warning"`) whenever
the movement exceeds the account's materiality threshold (Review's own default: 10% of opening balance or
a configurable absolute amount, whichever is larger — reused verbatim from the backend's Review step).

**Drill-down.** Clicking any closing-balance cell opens the Drill-down `Sheet` (`GET
/trial-balance/{id}/drill-down?account_id=…`), listing the contributing `journal_lines`. "View in General
Ledger" navigates to `/accounting/ledger?account_id={id}&as_of={as_of_date}`; "Open journal entry" navigates
to `/accounting/journal-entries/{journalEntryId}` — both close the Sheet on navigate rather than stacking a
second overlay on top of a route change.

**Review.** "Start Review" (`.review`) calls `POST /trial-balance/{id}/review`, assigns the acting user as
reviewer, and expands the Findings Bar to its full, non-collapsed state. The reviewer resolves or dismisses
each finding (`resolveFindingSchema`, reason mandatory) or clicks "Send back" (re-opens the Generate dialog
pre-filled, for a source-data correction) before the primary action switches from "Start Review" to
"Send for Approval."

**Approve.** Renders one `ApprovalCard` per configured step (`trial_balance_approvals`), in order; only
the current step's card is interactive, subsequent steps render as a locked, greyed preview naming the
next required role. A snapshot with any non-zero `is_suspense` account balance forces the approval
`Dialog`'s comment field to be non-empty even for an `approved` action, per Business Rule 8 — this is the
one approval step in the whole platform where even a plain "approve" requires typed acknowledgment, not
just a click.

**Export.** The `DropdownMenu` offers PDF / Excel (`.xlsx`) / CSV. A `generated`-but-not-`approved`
snapshot's export carries a visible "DRAFT — NOT APPROVED" watermark note in the menu item itself before
the click, so a user never discovers the watermark only after opening the downloaded file. Every export
inserts a `trial_balance_exports` row; a small "Recent exports" list inside the same menu re-surfaces prior
`download_url`s via `GET /trial-balance/{id}/export/{exportId}` without regenerating the file.

**Archive.** `AlertDialog` confirmation ("Archive this Trial Balance? This locks it permanently — a
restatement will always create a new version, never edit this one.") — a genuinely irreversible, terminal
action per the platform's confirmation-before-irreversible-action rule.

**History.** The History `Sheet` lists every version of the current logical snapshot
(`GET /trial-balance/{id}/history`), each row showing version, status, generated-at, and — where present —
`superseded_reason`. Opening a non-current version sets `?snapshot_id=` and renders the whole canvas in a
persistent, undismissable "Viewing version 1 of 2 — superseded" banner (`ImpersonationBanner`-style
treatment per `NAVIGATION_SYSTEM.md`'s precedent for "this view is not the live one") with every mutating
action disabled — history is read-only by construction, not by a forgotten permission check.

# AI Integration

Findings from `trial_balance_ai_findings` are the AI layer's entire surface on this screen; there is no
separate "AI tab." Every finding — whether `source = 'system'` or `source = 'ai'` — renders through the
same `AIProposalPanel`, but only `source = 'ai'` findings carry `ai_agent`, `confidence`, and `reasoning`,
and only those render the `Sparkles` badge, the `ai-insight` card variant (`border-s-2 border-s-(--accent)`
per `LAYOUT_SYSTEM.md → Card variants`), and a `ConfidenceBadge`. A system-sourced rule violation (e.g.
Rule 5, Duplicate Accounts) renders in the plain `danger`-bordered card variant instead — the visual
distinction is deliberate: a system rule is a deterministic fact, an AI finding is a claim with attached
evidence that a human still evaluates.

**Worked example — the out-of-balance case.** When `POST /trial-balance/generate` returns a snapshot with
`status: "out_of_balance"`, the response's `findings[]` array (per the backend spec's own worked JSON
example) typically contains both a `system` finding stating the raw variance and an `ai` finding from the
Auditor identifying the likely single cause:

```json
{
  "id": 55022,
  "finding_type": "currency_error",
  "severity": "critical",
  "source": "ai",
  "ai_agent": "auditor",
  "title": "Suspicious FX conversion on journal line",
  "description": "Journal line 771204 (AED 1,200.00 at rate 0.0812) computes to base amount 97.44, but the stored base_currency_debit is 121.89 — a 24.45 variance that exactly matches the trial balance imbalance.",
  "confidence": 0.98,
  "reasoning": "The recorded base-currency amount does not equal transaction_amount * exchange_rate within tolerance, and the discrepancy value equals the total trial balance variance to the cent, indicating this single line is the root cause.",
  "suggested_action": "Post a correcting entry reducing the debit on line 771204 by 24.4500 KWD, or correct the exchange_rate and re-derive the base amount."
}
```

The Status Banner's "Out of balance" state surfaces this exact finding **inline**, not merely as a link
into the Findings Bar: "AI Auditor found a likely cause (98% confidence) — Explain" opens the
`AIProposalPanel` directly, which renders `description`, a confidence ring, `reasoning`, the linked
`journal_line_id` as a one-click jump into the Drill-down Sheet, and `suggested_action` as plain text
(never an auto-apply button — see below). This is the single most important behavior on the screen: the
first thing a Finance Manager sees when a Trial Balance breaks is not a bare "$24.45 variance," it is a
named, evidenced, confidence-scored explanation they can verify in one more click.

**Suggested corrections.** When a finding carries `suggested_journal_entry_id`, the `AIProposalPanel`
additionally renders a "Review draft entry" button linking to
`/accounting/journal-entries/{suggested_journal_entry_id}`. That entry is a real `journal_entries` row with
`status = 'draft'` and `ai_generated = true` — the Trial Balance screen never lets a user post it inline;
it only ever navigates to the Journal Entries detail page, where the normal `accounting.journal.post`
permission and the normal Posting Engine re-validation apply. AI never posts, on this screen or any other.

**Escalation.** A `critical`-severity Fraud Detection finding renders with a persistent, non-collapsible
banner state (never auto-dismissed, never demoted by scrolling) and — per the backend's escalation rule —
the Approval Chain gains a mandatory "Fraud Review" step the moment the finding is created; the
`ApprovalCard` list re-fetches to show the new step without the user having to refresh.

**Unavailable AI.** If the Auditor/Fraud Detection call fails, times out, or is disabled for the company
(Edge Case 12), the Findings Bar shows a single, calm `StatusPill` ("AI review pending/unavailable") rather
than an error state, and every human-driven action (Review, Approve, Export) remains fully available — AI
is additive, never a required gate, and the UI never blocks a Finance Manager's period close on a model
call.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | `Skeleton` shaped exactly like the Report Header + Status Banner + Canvas (shimmer sweep per `DESIGN_LANGUAGE.md → Motion`), never a generic spinner |
| Generating | `status: "generating"` | Status Banner shows an indeterminate "Generating your Trial Balance…" bar; canvas region shows the skeleton; Reverb-subscribed, 2s poll fallback |
| Balanced | `ABS(variance) <= rounding_tolerance` | Status Banner: `success` tone, "Balanced — total debit KD X = total credit KD X" |
| Balanced within tolerance | `0 < ABS(variance) <= rounding_tolerance` | Status Banner: `success` tone but with a small `Tooltip`-attached note: "Within the company's 0.005 rounding tolerance" — never hidden, per the backend's transparency requirement |
| Out of balance | `status: "out_of_balance"` | Status Banner: `danger` tone, exact variance amount, inline AI Auditor explanation when available (`# AI Integration`); every action except Generate/Refresh/Export-as-draft is disabled |
| Partial view (dimension-scoped) | A branch/department/project/cost-center filter is active and the scoped subtotal does not balance | Status Banner: `info` tone, "Partial view — company-level balance is the authoritative check," never the `danger` treatment (Business Rule 7 / Edge Case 3) |
| Has warnings | `has_warnings: true`, `status` otherwise healthy | Status Banner stays `success`/`info`; a secondary `warning` `Badge` ("2 warnings") sits beside the status pill, expanding the Findings Bar by default |
| Empty (no snapshot for scope) | No `is_current` row for the resolved logical key | `EmptyState`: "No Trial Balance generated for {period} yet," CTA gated on `.generate`; a lesser copy variant ("Ask your Finance Manager to generate this period's Trial Balance") when the viewer lacks `.generate` |
| Empty (filtered to nothing) | Filters legitimately produce zero accounts (e.g. a brand-new branch with no postings) | Lighter `EmptyState` variant, "No accounts match your filters," never conflated with the no-snapshot-at-all case |
| Stale/superseded | Viewing a non-current version via History, or a period was reopened after generation (Edge Case 9) | Persistent read-only banner naming the reason; every mutation disabled |
| Error | `403`/`404`/`5xx`/network failure on any request | `ErrorState` with a retry action; a `403` mid-session (permission revoked while the tab was open) collapses the affected control to its disabled-with-tooltip form rather than crashing the page |

# Responsive Behavior

This screen follows `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` exactly, which names the
Trial Balance as its own worked example — this section states the outcome, not a new mechanism.

**Mobile (`base`–`sm`).** The Report Header collapses to a back-chevron + title; the type/period/comparison
controls stack into a horizontally-scrollable chip row; the four dimension filters collapse behind a single
"Filters (n)" trigger opening a `Sheet`. The canvas becomes a stack of `TrialBalanceCard`s — one per
account, showing only `priority: 1–2` fields (code, bilingual name, closing debit, closing credit, period
debit/credit) and an abnormal-balance `Badge` where relevant — each ending in a "View full breakdown"
button that opens the same account's full line detail (opening/period/closing, currency breakdown, and the
Drill-down Sheet trigger) in a full-height `Sheet`, so no figure is ever permanently unreachable on a
phone, only reached with one extra tap. The Status Banner and a collapsed Findings Bar stay pinned above
the card list; they never scroll away, matching the platform rule that a report's totals/status must never
leave the viewport while the user reviews the data those totals summarize.

**Tablet and up (`md`+).** The canvas is a real `<table>` with the account column pinned via `sticky
start-0` (the logical start — see `RTL & Localization`) and the money columns scrolling horizontally inside
their own `overflow-x-auto` region; the totals `<tfoot>` is `sticky bottom-0`. Priority-4 columns (Opening
Dr/Cr) hide below `lg` and reappear at `lg`+; Period Dr/Cr (`priority: 2`) show from `md`; Closing Dr/Cr
(`priority: 1`) are always visible. A trailing-edge scroll-hint gradient (dismissed once per device) marks
that more columns exist off-screen.

**Comparison and breakdown columns.** Both are additive column sets that only render from `xl`+ without
crowding the base report; below `xl`, enabling a comparison or a multi-dimension breakdown instead opens a
dedicated `Sheet` presenting that expanded view full-width, rather than squeezing six extra columns into a
390px table.

**Touch and virtualization.** Row-action icons (drill-down, row "⋯" menu) meet the platform's 44×44px
touch minimum regardless of the 32/40/48px density-driven row height (`LAYOUT_SYSTEM.md → Density Modes`);
snapshots with 10,000+ accounts (realistic for a large multi-branch group, per the backend's own Performance
section) virtualize via `@tanstack/react-virtual` past ~200 rows, with the row-height estimate read from the
active density token so density switching never desyncs virtualized scroll position.

# RTL & Localization

Every string on this screen ships as an EN/AR pair through `next-intl`, and the screen inherits
`THEMING.md → RTL as a theming dimension` and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a single
per-component RTL branch, plus two module-specific applications of those rules:

- **Bilingual accounts.** Every line renders `account_name_ar` when the active locale is `ar` and falls
  back to `account_name_en` only if the Arabic name is genuinely absent — never the reverse, and never a
  mixed-language row where the code is Latin (always LTR-isolated, see below) but the name silently stays
  English under an Arabic session.
- **Debit/Credit column order is physically fixed, never mirrored** (`LAYOUT_SYSTEM.md → Rule 2, Exception
  B`), because Debit-before-Credit left-to-right is the universal accounting convention independent of
  interface language and must match printed/exported statements:

  ```tsx
  <TableCell dir="ltr" className="text-right tabular-nums">{formatMoney(line.closingDebit)}</TableCell>
  <TableCell dir="ltr" className="text-right tabular-nums">{formatMoney(line.closingCredit)}</TableCell>
  ```

- **Numeric alignment is physically fixed** (`Exception A`): every money column is `text-right` in both
  directions, never `text-end`, so a bilingual Finance team scanning magnitude by decimal alignment sees
  amounts land on the same edge regardless of session language.
- **Account codes, snapshot dates, and confidence percentages stay LTR-embedded** inside Arabic sentences
  (findings narrative text, the Status Banner's variance sentence) via the shared `Bidi`/`LtrInline`
  wrapper (`unicode-bidi: isolate` + `dir="ltr"`), so a KWD figure or a `journal_line_id` never reorders
  inside a right-to-left paragraph.
- **AI reasoning is generated in the viewer's session locale.** Per the backend spec, `reasoning` text is
  produced in English or Arabic matching the company's `locale` setting — the frontend renders it as plain
  translated prose, not a string it re-translates client-side, and Arabic accounting terminology is used
  precisely: ميزان المراجعة (Trial Balance), مدين (Debit), دائن (Credit), ترحيل (Posting), قيد (Entry).
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching Gulf
  financial-document convention and the platform-wide rule that QAYD never switches a financial figure to
  Eastern Arabic-Indic digits.
- **Directional chrome mirrors; content chrome does not.** The Drill-down and History `Sheet`s slide from
  the logical end edge (left in RTL); breadcrumb chevrons and the sidebar-collapse icon mirror via
  `rtl-flip`; the `Scale` nav icon, the `Sparkles` AI glyph, and every status/severity icon do not, per
  `ICONOGRAPHY.md`'s directional-icon table.
- **Charts never mirror.** The Comparative view's `TrendSparkline` keeps a fixed left-to-right time axis
  regardless of `dir`, rendered inside a `dir="ltr"` wrapper, consistent with the platform-wide rule that
  time-series visuals read earliest-to-latest independent of prose direction.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`/`THEMING.md`. Nothing on
this screen references a raw hex value or a Tailwind palette utility; every surface, border, and status
color resolves through the semantic tokens those documents already define.

- **Surfaces and elevation.** The canvas sits on `--color-bg-canvas`; the Report Canvas card and its sticky
  header/footer sit on `--color-bg-surface`; the Drill-down/History `Sheet`s sit on
  `--color-bg-surface-raised`. In dark mode, elevation is communicated by the surface getting *lighter*
  toward the viewer (canvas → surface → raised), not by shadow — the sticky totals `<tfoot>` and the sticky
  header band both carry their paired `--color-border-subtle` hairline in dark mode even where the
  light-mode equivalent relies on shadow alone, because a borderless dark card disappears against the
  near-black canvas.
- **Status Banner tones.** Balanced → `--color-success` (never a large green fill; the banner itself stays
  `--color-bg-surface` with the success color carried by the checkmark icon and the pill, per the
  platform's "status color, never a large decorative fill" rule). Out of balance → `--color-danger`, the
  one screen-wide state where a prominent, saturated danger treatment is intentional rather than
  restrained, because a broken ledger is exactly the moment restraint would be the wrong signal. Partial
  view → `--color-info`. Has-warnings badge → `--color-warning`.
- **Debit/credit figures never take a status color.** Per the platform's debit/credit rule, every money
  cell in the canvas renders in `--color-fg-primary` regardless of theme; only the Status Banner, severity
  pills, and a genuinely signed metric (the Comparative view's variance delta) ever carry `success`/
  `warning`/`danger`.
- **AI provenance.** Findings and the `AIProposalPanel` use the single platform accent
  (`--color-accent`/`--color-accent-subtle-bg`) for the `ai-insight` card border, the `Sparkles` glyph, and
  the `ConfidenceBadge` fill — QAYD deliberately does not introduce a second, AI-specific hue here, per
  `DESIGN_LANGUAGE.md`'s "the accent is also the AI's signature" rule; a user learns once that accent-brass
  on this screen means either "the one primary action" or "AI touched this," and that reading holds
  identically in light and dark.
- **Contrast.** Every pairing above is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text, borders,
  focus rings) in both themes per `DARK_MODE.md → Color & Contrast In Dark`; the sticky-column border used
  for the frozen identifier column is treated as load-bearing (not merely decorative), so it is checked at
  the 3:1 non-text floor rather than exempted as a plain divider.
- **Print/export independence.** Exported PDFs always render in QAYD's fixed light/print palette regardless
  of the viewer's active theme (`DARK_MODE.md → Exported PDFs always render light`) — a Trial Balance is
  routinely forwarded to an auditor who never opens the dark-mode app at all.

# Accessibility

Baseline is WCAG 2.2 AA, with the platform's stricter internal bar on the sensitive-adjacent Approve and
Archive flows, per `ACCESSIBILITY.md`, which this screen implements without deviation.

- **Real table semantics.** The Report Canvas is a genuine `<table>` with a `<caption>` ("Trial Balance —
  July 2026, Unadjusted"), `scope="col"` headers, and `scope="row"` on the account-code cell —
  `ACCESSIBILITY.md → Screen Readers` names Trial Balance explicitly as one of the tables that must never
  be a styled `<div>` grid, because it is a display table, not an inline-editable spreadsheet.
- **Debit/credit is never color-only.** Every `AmountCell` carries the triple redundancy already specified
  platform-wide: a leading glyph, a directional cue, and a `VisuallyHidden` text equivalent ("Debit of KWD
  44,020.500") — the one property that matters most on a screen whose entire purpose is proving a
  debit/credit equality that a colorblind reviewer must be able to verify exactly as confidently as anyone
  else.
- **The Status Banner is a live region proportional to its severity.** `role="status" aria-live="polite"`
  when the state changes from Generating to Balanced (a confirmation, not an interruption); the Out-of-
  balance transition escalates to `role="alert"` (assertive), because a broken ledger is precisely the
  blocking-error case `ACCESSIBILITY.md`'s live-region tiers reserve assertive announcements for.
- **Findings arriving via Reverb never yank focus or splice silently.** A new AI finding pushed mid-review
  appends to the Findings Bar with a polite live-region announcement ("New finding: suspense balance
  detected") rather than reordering or auto-expanding the list the reviewer is currently reading, per the
  platform-wide realtime-accessibility rule.
- **Every disabled control explains itself.** A Generate/Approve/Archive button disabled for lack of
  permission carries `aria-describedby` pointing at a tooltip naming the exact permission key
  (`"Requires accounting.trial_balance.approve"`), distinct in wording from a button disabled by a
  *business-rule* state (`"This snapshot is out of balance — resolve findings before approving"`) — the two
  are never collapsed into a generic "You can't do this."
- **Focus management on overlays.** The Drill-down Sheet's `onOpenAutoFocus` moves focus to its heading, not
  its close button; closing it (whether by Escape, backdrop click, or navigating to a linked journal entry)
  returns focus to the triggering cell. The History Sheet's version rows are keyboard-navigable and `Enter`
  opens the selected version exactly as a click would.
- **Keyboard.** Standard Tab order follows the visual/DOM order (header controls → Status Banner →
  Findings Bar → canvas → row actions); arrow-key navigation is not applicable here since the canvas is a
  read-only display table, not an ARIA `grid` — that pattern is reserved for genuinely editable surfaces
  like the Journal Entry line editor, per `ACCESSIBILITY.md`'s own distinction. `Cmd/Ctrl+Enter` confirms
  the Generate dialog and the Approve/Reject confirmation dialogs.
- **Export is omitted, not disabled, for one specific reason.** Because Export sits inside a `DropdownMenu`
  whose trigger is otherwise always meaningful, and because a disabled export item with no visible content
  behind it would leave a screen-reader user unable to tell whether the feature exists at all versus is
  merely unavailable right now, the item is filtered out of the menu's own data source
  (`actions.filter(hasPermission)`, per `NAVIGATION_SYSTEM.md → Command Palette`'s identical rule) rather
  than rendered disabled — every other permission-gated control on this screen uses the disabled-with-
  tooltip pattern instead, because in those cases the *existence* of the action is not itself sensitive
  information.

# Performance

- **Async generation, not a blocking request.** Any Generate/Refresh whose estimated scan exceeds the
  backend's configured row threshold returns `202 Accepted` immediately; the frontend never holds a loading
  spinner open for a multi-minute computation — it subscribes to the snapshot's Reverb channel and lets the
  user keep working elsewhere in the app.
- **Virtualization past ~200 lines.** `TrialBalanceTable` mounts `@tanstack/react-virtual` once
  `line_count` crosses roughly 200 (a company with a deep, granular chart of accounts and a full
  branch/department/project breakdown regularly exceeds this), using the active density mode's fixed row
  height as the size estimate.
- **Status-aware caching.** `approved` and `archived` snapshots are effectively immutable and cached
  aggressively (`# Data & State`); this is what lets a Finance Manager reopen last month's approved Trial
  Balance instantly from History without a network round trip, while an in-progress `under_review` snapshot
  still refetches promptly enough to reflect a colleague's concurrent finding-resolution.
- **Deferred, code-split overlays.** The Generate configuration `Dialog`, the History `Sheet`, and the
  Export `DropdownMenu`'s content are dynamically imported (`next/dynamic`) rather than bundled into the
  initial route chunk — none of the three is needed for first paint, and the History Sheet in particular
  can carry a long version list that has no reason to ship before it is opened.
- **Debounced, cancellable comparison fetches.** Switching the comparison-period selector debounces 200ms
  and cancels any in-flight `comparative` request via `queryClient.cancelQueries` before issuing the next
  one, so rapidly cycling through "vs. last month" → "vs. last year" never stacks redundant network calls.
- **SSR-seeded first paint.** Per `# Data & State`'s hydration pattern, the header, first page of lines,
  and findings for the resolved current snapshot are fetched once, server-side, and hydrated into the
  client cache — the client's first `useQuery` for the same keys resolves instantly rather than re-issuing
  a request the server already made.
- **Read-replica reporting.** Comparative and multi-dimension breakdown queries are served from a Postgres
  read replica on the backend where available (`docs/accounting/TRIAL_BALANCE.md → Performance`), which
  the frontend takes advantage of simply by not special-casing those requests — the API's own routing
  handles it transparently.

# Edge Cases

1. **Balanced within tolerance, not exactly zero.** The Status Banner stays `success` but adds a
   `Tooltip`-attached disclosure of the exact variance and the configured `rounding_tolerance` — never
   silently rounds it away, matching the backend's own transparency requirement.
2. **Dimension-scoped view that legitimately does not balance.** Rendered as the distinct "Partial view"
   `info`-tone state (`# States`), with copy explicit that only the ungrouped company-level Trial Balance
   is the authoritative balance check — this is the single most important edge case to get right visually,
   because getting it wrong (rendering it identically to a real imbalance) would train reviewers to ignore
   real imbalances as "probably just a branch thing."
3. **Regenerating after a discovered posting error in an already-approved snapshot.** The old version is
   never edited; the UI presents the new version with a visible `parent_snapshot_id` lineage link and a
   mandatory-reason `refresh` dialog, and the superseded version remains fully browsable, unmutated, from
   History.
4. **Soft-deleted accounts with historical posted activity.** Still appear in any period during which they
   were active, tagged with an `Inactive` `Badge` using the account's snapshot-time denormalized
   code/name — a later rename or deactivation never rewrites what a frozen historical snapshot displays.
5. **Concurrent Generate clicks.** The Generate button disables itself immediately on click (before the
   network round trip resolves) and reuses a single `Idempotency-Key` per logical submission attempt; a
   `409 Conflict` from a genuine race renders a friendly "Already generating — hang on" toast rather than a
   raw error.
6. **Stale UTB detected while generating an ATB.** If new routine activity posted into the period after the
   Unadjusted snapshot but before the Adjusted one is requested, the Generate flow surfaces a
   "the Unadjusted Trial Balance is out of date — regenerate it first?" confirmation naming the specific new
   entries, rather than silently basing the Adjusted run on stale figures.
7. **Company base-currency change spanning a Comparative view.** The affected period pair renders with an
   explicit footnote disclosing the historical conversion rate used, never a silent re-expression of
   history in the new currency.
8. **Unresolved suspense balance at Post-Closing.** Generation itself is never blocked (the module never
   hides a truthful computation), but the Approve step is — the `ApprovalCard`'s comment field is required
   even for a plain "approved" action, and the card visibly names which suspense account and balance is
   outstanding.
9. **A closed fiscal period is later reopened.** Any snapshot generated against that period keeps rendering
   as valid history when opened directly, but loses `is_current` and gains a system-generated informational
   finding ("underlying period was reopened; regenerate before relying on this as current") surfaced at the
   top of its Findings Bar.
10. **Brand-new company, opening-balance-only period.** Renders as a small, honestly-labeled, correctly
    balanced Trial Balance of just the touched accounts — never an error, never a "not enough data" dead
    end.
11. **Mid-year account reclassification.** Historical snapshots continue showing the classification that
    was correct at generation time (denormalized on the snapshot line); only new snapshots reflect a
    correction — the UI never retroactively re-labels frozen history, even when viewed today.
12. **AI unavailable, disabled, or low-confidence.** The workflow never blocks on it (`# AI Integration`);
    the Findings Bar shows a calm, dismissable-only-by-becoming-available "AI review pending" pill.
13. **Session permission change mid-view.** If a role change revokes `.approve` while an `ApprovalCard` is
    open, the next mutation attempt's `403` collapses that card to its read-only rendering with a toast
    explaining why, rather than a broken/stuck loading state — the frontend never assumes its own last
    known permission snapshot is still valid, per Principle 4.
14. **Company switch mid-Generate.** Per the platform's company-switch contract, switching the active
    company discards all cached Trial Balance state; if a Generate dialog is open with unsaved scope
    selections, the switcher's confirmation dialog names exactly that in-progress action before proceeding.
15. **Direct browser printing vs. Export.** The screen does not special-case `window.print()`; users are
    steered toward the Export menu's PDF option instead, which is the only path that produces the
    watermarked-draft-vs-final distinction and a durable, re-downloadable `trial_balance_exports` record.

# End of Document
