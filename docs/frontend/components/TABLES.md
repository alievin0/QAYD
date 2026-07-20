# Data Tables — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / TABLES
---

# Purpose

The table is the single most important surface in QAYD. A General Ledger is a table; a Trial Balance is a
table; the Journal Entries list, the invoice register, the bank-transaction feed, the account tree, the
audit log — all tables. If the table system is right, the product feels like software built by people who
use spreadsheets for a living; if it is wrong, no amount of polish elsewhere recovers the impression. This
document is the depth specification for that system: it takes the `DataTable` entry catalogued in
[`../COMPONENT_LIBRARY.md → DataTable`](../COMPONENT_LIBRARY.md) and expands it into the complete contract
every list, ledger, and report canvas in the application is built from — the primitive `Table` underneath,
the composed `<DataTable>` and `<ResponsiveDataView>` above it, column definitions and cell renderers,
sorting and cursor pagination, pinning/resize/visibility, density, selection and bulk actions, expandable
rows, sticky structure, per-row and per-cell loading and error, keyboard navigation, virtualization,
RTL column mirroring, the empty/loading/error handoff, and deep-linkable state.

Everything here inherits the platform constraints stated once in [`../README.md`](../README.md) and does not
re-derive them: the frontend is a pure presentation layer (`../README.md → Overview`), so a table **never**
sorts, filters, or paginates client-side against a truncated in-memory page — every one of those operations
is a new server request built from the query grammar in [`../../api/API_PAGINATION.md`](../../api/API_PAGINATION.md)
and [`../../api/API_FILTERING_SORTING.md`](../../api/API_FILTERING_SORTING.md). A table may format, measure,
virtualize, focus, and animate; it may never compute a total that will be trusted, decide whether an entry
balances, or infer a permission. Those are server answers a table surfaces. The visual rules a table obeys —
tabular numerals, hairline `ink-150` borders instead of zebra fill, density through type and row height, the
minus/parentheses negative-number split, the single `accent-600` AI-provenance dot — are set in
[`../DESIGN_LANGUAGE.md → Data Density`](../DESIGN_LANGUAGE.md) and this document only shows how the
component realizes them.

Two audiences share this one system. The compact, keyboard-first ledger an accountant reconciles four
thousand lines in, and the comfortable, drill-down-on-demand list an owner glances at, are the *same*
`DataTable` at two density settings — never two implementations. That is the whole reason the system is
worth specifying once, in depth, here.

# Anatomy & Variants

## The two layers

QAYD's table stack has a primitive layer and a composed layer, and the boundary between them is strict.

**1. The `Table` primitive** (`components/ui/table.tsx`) is shadcn/ui's thin wrapper over the native
`<table>`/`<thead>`/`<tbody>`/`<tr>`/`<th>`/`<td>` elements, re-skinned to QAYD tokens. It is markup and
styling only: it knows nothing about data, sorting, pagination, or permissions. It exists so that
`TableHeader`, `TableRow`, `TableHead`, and `TableCell` carry QAYD's border, spacing, and typography tokens
by default, and so that a screen-specific report canvas that legitimately cannot use the composed
`<DataTable>` (the grouped, subtotalled Trial Balance canvas; a Balance Sheet's nested statement rows) can
still be assembled from correctly-styled parts rather than a raw `<table className="...">`. Using the
`Table` primitive directly is the exception, always justified in the consuming screen's spec, and always
still bound by the numeral, border, and RTL rules below.

**2. The composed `<DataTable>`** (`components/shared/data-table.tsx`) is the default. It is a thin
rendering layer over **TanStack Table** (the headless column/row model — sorting state, column visibility,
column sizing, expansion, selection) driven by **TanStack Query** (server state, cache keys, cursor/page
fetching). Ninety percent of the tables in QAYD are a `<DataTable>` with a `columns` array and a `resource`
string; the rest are either a `<ResponsiveDataView>` wrapping it (any list that must also render as cards on
mobile — see [`../RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`](../RESPONSIVE_DESIGN.md)) or the
`Table` primitive assembled by hand for a genuinely non-list report canvas.

```
<ResponsiveDataView>            ← md:+ renders <DataTable>, below md: renders <DataCardList>
  └── <DataTable>               ← TanStack Table × TanStack Query; toolbar, header, body, pagination
        ├── <DataTableToolbar>  ← search box, density toggle, column-visibility menu, active-filter chips
        ├── <Table> (primitive) ← sticky <thead>, virtualized <tbody>, sticky <tfoot> totals
        │     └── cell renderers: AmountCell, StatusPill, AccountCell, FormattedDate, …
        └── <DataTablePagination>← page numbers (page mode) OR "Load more"/infinite (cursor mode)
```

## Variant axes

`DataTable` varies along a small, orthogonal set of axes. Every table in the app is a point in this space;
there is no such thing as a "special" table outside it.

| Axis | Values | Chosen by |
|---|---|---|
| `paginationMode` | `'page'` \| `'cursor'` | The endpoint's documented mode (`API_PAGINATION.md`). Never a UI preference — it must match the server. |
| `density` | `'compact'` \| `'comfortable'` \| `'relaxed'` | Per-table, user-persisted toolbar toggle. Defaults per `DESIGN_LANGUAGE.md → Table density modes`. |
| `selection` | `'none'` \| `'multi'` | Whether the table has bulk actions. |
| `expansion` | `'none'` \| `'row'` | Whether a row discloses detail inline (vs. opening a `Sheet`/route). |
| `virtualization` | `'auto'` \| `'off'` | `'auto'` virtualizes past ~200 rows (`DESIGN_LANGUAGE.md → Virtualization`). |
| responsive strategy | priority-columns+cards \| sticky-first-column scroll | List-like vs. numeric-grid, per `RESPONSIVE_DESIGN.md`. |

These are independent. A Journal Entries list is `page` / `comfortable` / `multi` / `none` / `auto`; a
General Ledger is `cursor` / `compact` / `none` / `none` / `auto` (always virtualized); a Trial Balance
canvas is not a `DataTable` at all but the `Table` primitive with the sticky-first-column pattern and
`groupBy="account_type"`. None of them is a new component.

## Column definitions

Columns are **TanStack Table `ColumnDef`s**, extended platform-wide with QAYD's `ResponsiveColumnDef`
(`components/shared/data-table/types.ts`) so the *same* array drives the desktop table and the mobile card
list without a second, drift-prone definition:

```tsx
// components/shared/data-table/types.ts
import type { ColumnDef } from '@tanstack/react-table';

export type ResponsiveColumnDef<TData, TValue = unknown> = ColumnDef<TData, TValue> & {
  /** 1 = always shown (incl. mobile card); 5 = xl:-only. Filtered by useResponsiveColumns
   *  before the array reaches TanStack Table, so a column its container is too narrow to
   *  show responsibly is never rendered rather than squeezed illegibly. */
  priority: 1 | 2 | 3 | 4 | 5;
  /** When the row collapses to a card below md:, this column is the card's headline. Exactly one. */
  isCardTitle?: boolean;
  /** Optional bespoke renderer for the mobile card body; falls back to `cell`. */
  mobileRender?: (row: TData) => React.ReactNode;
  /** Fixed px width for a numeric or code column that must not reflow. */
  size?: number;
  /** Marks a money/quantity column so the header and cell right-align in LTR and RTL alike. */
  numeric?: boolean;
};
```

Every sortable column's `id`/`accessorKey` must match a `SORTABLE_FIELDS` entry on the backend resource — a
column that renders a sort caret for a field the API cannot sort is a defect, because the click produces a
`422` rather than a re-order. Column ids that carry a bilingual master-data name (`account_name`,
`customer_name`) resolve `name_en`/`name_ar` at render time from `useLocale()` per
`../COMPONENT_LIBRARY.md → Theming & RTL → Bilingual data rendering`, never a server-chosen single field.

## Cell renderers

A cell renderer is a small, pure function of one row that returns a library component — never inline JSX with
hard-coded classes. QAYD's canonical renderers, all catalogued in `../COMPONENT_LIBRARY.md`:

| Renderer | Component | Column kind |
|---|---|---|
| Money | `AmountCell` | Any `NUMERIC(19,4)` money string; `mode="debit"\|"credit"\|"signed"\|"plain"`, right-aligned, `font-mono tabular-nums`, `dir="ltr"` |
| Status | `StatusPill` | A record's `status` enum, via the domain lookup table (`domain="journal_entry"`, `"invoice"`, …) — dot + label, never color alone |
| Date | `FormattedDate` / `FormattedRelativeTime` | `entry_date`, `created_at`; `Intl.DateTimeFormat` via next-intl `useFormatter`, `dir="ltr"` for numerals |
| Account | `AccountCell` | An account reference: monospace `code` + bilingual name, optional `control` tag — the read-only twin of `AccountPicker` |
| Currency | `CurrencyTag` | A per-row ISO code where a table spans currencies |
| Trend | `TrendSparkline` | A `number[]` trend inside a cell (a variance-trend column) |
| Confidence | `ConfidenceBadge` | An AI-sourced row's `confidence`, tooltip-backed by `reasoning` |

`AccountCell` is the one renderer TABLES adds that `COMPONENT_LIBRARY.md` implies but does not fully spell
out, because it recurs in every ledger and statement:

```tsx
// components/accounting/account-cell.tsx
import { useLocale } from 'next-intl';
import { cn } from '@/lib/utils';
import type { AccountRef } from '@/types/accounting';

export function AccountCell({ account, showControlTag = true }: { account: AccountRef; showControlTag?: boolean }) {
  const locale = useLocale();
  const name = locale === 'ar' ? account.name_ar : account.name_en;
  return (
    <span className="inline-flex min-w-0 items-center gap-2">
      <span className="w-14 shrink-0 font-mono text-caption text-ink-500" dir="ltr">{account.code}</span>
      <span className="truncate text-ink-950" title={name}>{name}</span>
      {showControlTag && account.is_control_account && (
        <span className="ms-1 shrink-0 text-[11px] text-ink-500">control</span>
      )}
    </span>
  );
}
```

# Props / API

## `<DataTable>`

The full prop surface, extending the summary table in `../COMPONENT_LIBRARY.md → DataTable` with the props
this depth spec adds (selection, expansion, density, pinning).

| Prop | Type | Required | Description |
|---|---|---|---|
| `columns` | `ResponsiveColumnDef<TData>[]` | yes | Column model. Each sortable column's `id` matches a backend `SORTABLE_FIELDS` entry. |
| `resource` | `string` | yes | API path segment, e.g. `'accounting/journal-entries'`. Builds the query key and request URL. |
| `paginationMode` | `'page' \| 'cursor'` | yes | Must match the endpoint's documented mode. |
| `defaultFilters` | `Record<string, unknown>` | no | Pre-applied `filter[...]`, e.g. `{ status: { in: ['posted'] } }`. Merged with URL/user filters. |
| `defaultSort` | `string` | no | e.g. `'-entry_date'`. Falls back to the platform default `-created_at`. |
| `searchable` | `boolean` | no | Renders the `q` box; valid only if the resource has `SEARCHABLE_FIELDS`. |
| `density` | `'compact' \| 'comfortable' \| 'relaxed'` | no | Initial density; a toolbar toggle then persists the user's choice per table. |
| `selection` | `'none' \| 'multi'` | no, default `'none'` | `'multi'` renders the checkbox column and the bulk-action bar. |
| `bulkActions` | `(rows: TData[]) => BulkAction[]` | conditional | Required when `selection="multi"`. Each action carries its own `permission`. |
| `expansion` | `{ render: (row: TData) => ReactNode; canExpand?: (row: TData) => boolean }` | no | Enables inline row expansion. |
| `columnPinning` | `{ start?: string[]; end?: string[] }` | no | Column ids pinned to the inline-start/end edges (logical, mirrors in RTL). |
| `enableColumnResize` | `boolean` | no, default `false` | User-draggable column widths, persisted per table. |
| `rowActions` | `(row: TData) => RowAction[]` | no | Per-row `DropdownMenu` items; each carries a `permission`. |
| `onRowClick` | `(row: TData) => void` | no | Typically opens a detail `Sheet` or route. Always paired with a keyboard-reachable path. |
| `syncToUrl` | `boolean` | no, default `true` | Reflects sort/filter/page/cursor into the route's search params (deep-linkable). |
| `emptyState` | `{ title; description; action? }` | no | Rendered on a genuinely empty resource; a *filtered* miss renders the lighter "no rows match" variant automatically. |
| `getRowId` | `(row: TData) => string` | yes | Stable key; always the resource `id`, never array index. |

Supporting types:

```tsx
export interface RowAction { label: string; icon?: LucideIcon; permission?: string; destructive?: boolean; onClick: () => void; }
export interface BulkAction { label: string; icon?: LucideIcon; permission?: string; destructive?: boolean; onClick: (ids: string[]) => Promise<void>; }
```

## `<DataTablePagination>`

| Prop | Type | Description |
|---|---|---|
| `mode` | `'page' \| 'cursor'` | Drives which control renders. |
| `meta` | `PaginationMeta` | The envelope's `meta.pagination`. In cursor mode `total` is `null`; `total_hint` may be present. |
| `page` / `onPageChange` | `number` / `(n) => void` | Page-mode only: numbered controls + "Page X of Y". |
| `onCursorChange` | `(cursor: string \| null) => void` | Cursor-mode only. |
| `isFetchingNextPage` | `boolean` | Drives the "Load more" spinner without collapsing existing rows. |

## Column-def example — Journal Entries (page mode, selectable)

Consistent with `../COMPONENT_LIBRARY.md → DataTable → Usage`, expanded to selection, priority, and the
AI-provenance gutter:

```tsx
// app/(app)/accounting/journal-entries/columns.tsx
import type { ResponsiveColumnDef } from '@/components/shared/data-table/types';
import { AmountCell } from '@/components/accounting/amount-cell';
import { StatusPill } from '@/components/shared/status-pill';
import { FormattedDate } from '@/components/shared/formatted-date';
import { AiProvenanceDot } from '@/components/shared/ai-provenance-dot';
import type { JournalEntry } from '@/types/accounting';

export const journalEntryColumns: ResponsiveColumnDef<JournalEntry>[] = [
  {
    id: 'ai',
    header: '',
    priority: 1,
    size: 20,
    enableSorting: false,
    cell: ({ row }) => <AiProvenanceDot source={row.original.source} confidence={row.original.ai_confidence} />,
  },
  { accessorKey: 'journal_number', header: 'Entry #', priority: 1, size: 132,
    cell: ({ row }) => <span className="font-mono text-caption text-ink-700" dir="ltr">{row.original.journal_number}</span> },
  { accessorKey: 'entry_date', header: 'Date', priority: 1, size: 112,
    cell: ({ row }) => <FormattedDate value={row.original.entry_date} /> },
  { accessorKey: 'memo', header: 'Description', priority: 1, isCardTitle: true,
    cell: ({ row }) => <span className="truncate text-ink-950" title={row.original.memo}>{row.original.memo}</span> },
  { accessorKey: 'reference', header: 'Reference', priority: 3,
    cell: ({ row }) => <span className="text-ink-500">{row.original.reference ?? '—'}</span> },
  { accessorKey: 'total_debit', header: 'Amount', priority: 1, numeric: true, size: 148,
    cell: ({ row }) => <AmountCell amount={row.original.total_debit} currencyCode={row.original.currency_code} /> },
  { accessorKey: 'status', header: 'Status', priority: 2, size: 148,
    cell: ({ row }) => <StatusPill domain="journal_entry" status={row.original.status} /> },
];
```

```tsx
// app/(app)/accounting/journal-entries/journal-entries-table.tsx
'use client';
export function JournalEntriesTable({ initialPage }: { initialPage: CursorPage<JournalEntry> }) {
  const router = useRouter();
  const post = usePostJournalEntry();
  return (
    <DataTable
      columns={journalEntryColumns}
      resource="accounting/journal-entries"
      paginationMode="page"
      defaultSort="-entry_date"
      searchable
      density="comfortable"
      selection="multi"
      defaultFilters={{ status: { in: ['draft', 'pending_approval', 'posted'] } }}
      bulkActions={(rows) => [
        { label: 'Export selected', icon: Download, permission: 'reports.export', onClick: (ids) => exportEntries(ids) },
        { label: 'Post selected', icon: Check, permission: 'accounting.journal.post', onClick: (ids) => postMany(ids) },
      ]}
      rowActions={(row) => [
        { label: 'View', icon: Eye, onClick: () => router.push(`/accounting/journal-entries/${row.id}`) },
        { label: 'Post', icon: Check, permission: 'accounting.journal.post', onClick: () => post.mutate(row.id) },
        { label: 'Reverse', icon: Undo2, permission: 'accounting.journal.post', destructive: true, onClick: () => reverse(row.id) },
      ]}
      onRowClick={(row) => router.push(`/accounting/journal-entries/${row.id}`)}
      getRowId={(row) => String(row.id)}
    />
  );
}
```

## Column-def example — Trial Balance (grouped numeric grid, cursor-free)

The Trial Balance canvas is not a paginated list; it is a whole snapshot rendered at once, grouped and
subtotalled, using the sticky-first-column pattern from `../RESPONSIVE_DESIGN.md`. Its column array is a
`ResponsiveColumnDef[]`, but it is fed to `TrialBalanceTable` (which wraps the `Table` primitive plus the
grouping logic) rather than `<DataTable>`, because grouping + a must-balance `<tfoot>` is outside
`<DataTable>`'s list contract:

```tsx
// components/accounting/trial-balance/columns.tsx
export const trialBalanceColumns: ResponsiveColumnDef<TrialBalanceLine>[] = [
  { accessorKey: 'account_code', header: 'Code', priority: 1, size: 88,
    cell: ({ row }) => <span className="font-mono text-caption text-ink-500" dir="ltr">{row.original.account_code}</span> },
  { accessorKey: 'account_name', header: 'Account', priority: 1, isCardTitle: true, size: 260,
    cell: ({ row }) => <AccountCell account={row.original} /> },
  { accessorKey: 'opening_debit', header: 'Opening Dr', priority: 4, numeric: true, cell: MoneyCell },
  { accessorKey: 'opening_credit', header: 'Opening Cr', priority: 4, numeric: true, cell: MoneyCell },
  { accessorKey: 'period_debit', header: 'Period Dr', priority: 2, numeric: true, cell: MoneyCell },
  { accessorKey: 'period_credit', header: 'Period Cr', priority: 2, numeric: true, cell: MoneyCell },
  { accessorKey: 'closing_debit', header: 'Closing Dr', priority: 1, numeric: true, cell: MoneyCell },
  { accessorKey: 'closing_credit', header: 'Closing Cr', priority: 1, numeric: true, cell: MoneyCell },
];

function MoneyCell({ row, column }: CellContext<TrialBalanceLine, unknown>) {
  const raw = row.getValue<string>(column.id);
  // A structurally-inapplicable side (a debit-normal account's credit column) renders an em dash, not 0.000.
  if (raw == null) return <span className="text-ink-500">—</span>;
  return <AmountCell amount={raw} currencyCode={row.original.currency_code} mode="plain" emphasis={row.original.is_group ? 'strong' : 'default'} />;
}
```

The Debit and Credit columns render in plain `ink-950` numerals, distinguished by position and header only —
never by `positive`/`negative` color, per the debit/credit rule in `../DESIGN_LANGUAGE.md → The debit/credit
rule`. Group/subtotal rows use `emphasis="strong"`; the grand-total `<tfoot>` uses the `display-sm`, weight-600
treatment from `../DESIGN_LANGUAGE.md → Running totals`.

# States

A table is a state machine, and each state has a designed rendering — never a raw blank or a spinner where
content of known shape belongs.

| State | Rendering |
|---|---|
| **Initial (SSR first paint)** | The route's Server Component fetched page one; the table renders real rows on first paint with no client spinner, then a matching `useQuery` takes over (`../README.md → RSC vs. Client Components`). |
| **Loading (no prior data)** | Skeleton rows shaped to the column widths — the shimmer sweep from `../DESIGN_LANGUAGE.md → Named patterns`, never a centered spinner. Row count matches the density's typical page (~8–12). |
| **Refetching (prior data present)** | Existing rows stay put (`placeholderData: keepPreviousData`); the table sets `aria-busy="true"` and shows a thin top progress line. Never a flash-to-empty between pages. |
| **Empty (unfiltered)** | The `emptyState` — an `EmptyState` with a single `ink-300` line icon, title, description, and (RBAC-permitting) a primary action ("Create your first journal entry"). |
| **Empty (filtered)** | The lighter automatic variant: "No rows match your filters," plus a "Clear filters" button — distinct from a genuinely empty resource, so the user knows the data exists but is hidden. |
| **Error (whole table)** | `ErrorState` with the request id and a Retry that re-runs the query; the toolbar stays interactive so the user can loosen filters. |
| **Per-row loading** | A single row mutating (posting, matching) shows an inline row-level spinner in its actions cell and `aria-busy` on the `<tr>`; the rest of the table stays live. |
| **Per-row error** | A row whose optimistic mutation was rolled back flashes `negative-subtle` once and shows an inline retry affordance in-row — never a global toast that loses which row failed. |
| **Per-cell loading/error** | A cell backed by a lazily-resolved value (a drill-in count, an FX conversion) shows a cell-scoped skeleton, then the value or an inline `—` with a tooltip on failure — the cell fails, the row does not. |

The empty/loading/error handoff is delegated to the dedicated components — `EmptyState`, `ErrorState`,
`Skeleton` — exactly as `../COMPONENT_LIBRARY.md → DataTable → Implementation` wires them; `DataTable` decides
*which* state it is in and hands off, it does not re-implement any of them inline.

# Behavior & Interaction

## Sorting

Sorting is server-side and single-column by default. Clicking a sortable header cycles
`unsorted → ascending → descending → unsorted`; the active direction sets `aria-sort` and swaps the caret
(`ArrowUpDown` idle → `ArrowUp`/`ArrowDown` active). The header is a real focusable `<button>`, activated by
`Enter`/`Space`, not a bare `<th onClick>`. The chosen sort is serialized as the platform's `sort` param
(`-entry_date` descending) and, when `syncToUrl`, written to the route search params so a sorted view is
linkable and survives refresh. Because a cursor is signed against the exact sort it was minted under,
**changing the sort resets the cursor to `null`** in cursor mode (per `API_PAGINATION.md → Sorting
Interaction`) — the same rule the Audit Log screen calls out.

## Cursor pagination + `useInfiniteQuery`

Page-mode tables render numbered controls and an exact "Page X of Y" from `meta.pagination.total`.
Cursor-mode tables — the unbounded, append-only ledgers (`ledger-entries`, `audit-logs`, the AI Insights
feed) — use `useInfiniteQuery` against the same key factory, never offer a "jump to last page" (cursor
pagination structurally cannot support it), and present `meta.pagination.total_hint` as an explicitly
approximate label ("~48,213 entries"), never as an exact count:

```tsx
// hooks/accounting/use-ledger-entries.ts
import { useInfiniteQuery, keepPreviousData } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { ledgerKeys } from '@/lib/query/keys';
import type { ApiEnvelope, LedgerEntry } from '@/types/api';

export function useLedgerEntries(filters: LedgerFilters, sort = '-posted_at') {
  return useInfiniteQuery({
    queryKey: ledgerKeys.list({ ...filters, sort }),
    queryFn: ({ pageParam }) =>
      apiClient.get<ApiEnvelope<LedgerEntry[]>>('/api/v1/accounting/ledger-entries', {
        params: { sort, cursor: pageParam, per_page: 100, ...flattenFilters(filters) },
      }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.pagination.cursor, // null when exhausted → hasNextPage=false
    placeholderData: keepPreviousData, // "Load more" never flashes loaded rows to empty
  });
}
```

The virtualized body drives `fetchNextPage()` as its scroll window approaches the end of the currently
loaded set, so pagination and virtualization are complementary, not redundant (`DESIGN_LANGUAGE.md →
Virtualization`): the query layer fetches ~100-row cursor pages; the virtualizer renders only the visible
slice of whatever has been fetched. A `<DataTablePagination mode="cursor">` still renders an explicit "Load
more" button as a non-scroll fallback and as the keyboard-reachable way to advance.

## Column pinning, resize, and visibility

- **Pinning.** `columnPinning={{ start: ['ai', 'journal_number'], end: ['actions'] }}` pins columns to the
  **logical** edges via TanStack Table's pinning state translated to `sticky inset-inline-start-0` /
  `inset-inline-end-0` (never `left-0`/`right-0` — see RTL below). The identifier column of every numeric
  grid (account code+name in a Trial Balance/GL) is pinned-start by default; the row-actions column is
  pinned-end.
- **Resize.** `enableColumnResize` exposes a drag handle on each resizable header; widths persist per table
  in the same user-settings store as density. Numeric columns declare a `size` and default to non-resizable,
  because a right-aligned money column reads best at a stable width.
- **Visibility.** The toolbar's column-visibility `DropdownMenu` toggles non-priority-1 columns; the choice
  persists per table. Priority-1 columns (identifier, primary amount, status) cannot be hidden — a user
  cannot accidentally hide the column that makes a row identifiable.

## Density modes

Density is a per-table, user-persisted toolbar toggle, not a global app setting, because "the same day, the
same user" reconciles four thousand lines (wants Compact) and reviews a twelve-vendor list (wants
Comfortable):

| Mode | Row height | Cell font | Cell vertical padding | Default on |
|---|---|---|---|---|
| Compact | 32px | `text-caption` (13px) | `space-2` (8px) | General Ledger, Journal Entries > 50 lines |
| Comfortable | 40px | `text-body` (15px→14px in table) | `space-3` (12px) | Trial Balance, Banking, most lists (default) |
| Relaxed | 48px | `text-body` | `space-4` (16px) | Customer/Vendor directories, settings lists |

Comfortable is the executive-facing default (calmer scan density); Compact is the accountant's dense working
mode. The virtualizer's fixed row-height estimate is read from the active density so virtualization stays
pixel-correct when density changes.

## Row selection + bulk actions

`selection="multi"` renders a pinned-start checkbox column: a header tri-state select-all (which selects the
**loaded** set and, for a filtered view, offers "Select all N matching" that carries the filter to the bulk
endpoint rather than enumerating ids client-side), and per-row checkboxes. Selecting ≥1 row reveals the bulk
action bar (a `sticky` strip above the table): "3 selected · Clear," then the `bulkActions` whose permission
the caller holds — actions the user lacks permission for are omitted, not shown disabled, matching the
row-action rule. A destructive bulk action routes through an `AlertDialog` confirm. Selection state is
ephemeral (Zustand-free component state) and clears on filter/sort/page change, because a selection made
against one filtered set must never silently apply to a different one.

## Expandable rows

`expansion` enables inline disclosure for tables where a row's detail is small enough not to warrant a route
(an audit-log diff, a journal entry's lines preview). The expand control is a real `<button>` with
`aria-expanded`/`aria-controls`; the expanded panel animates height via Framer Motion, collapsing to an
instant state change under reduced motion. Rows whose detail is large (a full journal entry, a reconciliation
match set) do not expand inline — they set `onRowClick` to open a `Sheet` or navigate, so a single row never
pushes the whole table's layout by hundreds of pixels.

## Sticky structure

Column headers are `sticky top-0` at `z-sticky`; the totals footer, where a table has a meaningful running
total, is `sticky bottom-0`. Both use the `ink-100`/`muted` header fill and the `ink-150` hairline from
`DESIGN_LANGUAGE.md`. In a horizontally-scrolling numeric grid the identifier column is additionally sticky
to the inline-start edge (`sticky inset-inline-start-0 z-10`), so account code+name stays visible while the
Dr/Cr columns scroll — the exact mechanics `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` defines
and this system reuses verbatim.

## Keyboard navigation

Dense tables are keyboard-first. Beyond the header/toolbar tab order, a data grid supports cell-level
navigation for the accountant workflows that live in the keyboard:

- **Arrow keys** move a roving focus between cells (`↑`/`↓` rows, `←`/`→` cells — mirrored in RTL so `→`
  moves toward the visual right which is the logical *start* edge; see RTL).
- **`Enter`** on a focused row opens its detail (the same target as `onRowClick`); **`Space`** toggles its
  selection checkbox when `selection="multi"`.
- **`Home`/`End`** jump to the first/last cell in a row; **`PageUp`/`PageDown`** page the virtualized
  viewport and trigger `fetchNextPage` at the end.
- **Table-scoped `Cmd/Ctrl+F`** focuses the in-table filter input rather than the browser's native find,
  because native find cannot see virtualized, off-screen rows (`DESIGN_LANGUAGE.md → Virtualization &
  interaction`).

Roving tabindex means the table is a single tab stop from the outside; once inside, arrows move focus and a
single `Tab` exits to the pagination controls — the standard grid pattern, not forty tab stops per page.

## Virtualization

Tables expected past ~200 rows (`ledger-entries`, journal history, reconciliation statement lines) are
virtualized with `@tanstack/react-virtual`, the density's fixed row height as the size estimate, combined
with `@tanstack/react-table` for column state and `@tanstack/react-query` cursor pages underneath. Only the
visible window mounts; the sticky header and footer live outside the virtualized region so they never
unmount on scroll. Virtualization is transparent to the column model — the same `columns` array renders a
40-row list and a 40,000-row ledger.

## Deep-linkable state

With `syncToUrl` (default), the table's sort, active filters, page number or cursor, and search term
serialize into the route's search params, so any table view is a shareable URL that reproduces exactly on
load and survives refresh and back/forward. This is the same mechanism the Trial Balance screen uses for its
`?type=&fiscal_period_id=&compare_with=` state (`../TRIAL_BALANCE.md → Deep-linkable state`): query string
only, no per-view dynamic route segment. The Server Component reads `searchParams` and performs the matching
first fetch, so a deep-linked, filtered, sorted table first-paints with the right rows and no client spinner.

# Accessibility

Inherits the WCAG 2.1 AA baseline and keyboard-first model of [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md);
what the table system adds or must never break:

- **Semantic table.** A `DataTable` is a real `<table>` with `<th scope="col">` headers, so screen readers
  announce "column, row" coordinates natively. A CSS grid faked from `<div>`s is never used for tabular data.
- **Sortable headers** are focusable `<button>`s exposing `aria-sort="ascending|descending|none"`; the sort
  change announces via a visually-hidden `aria-live="polite"` region ("Sorted by date, descending").
- **Busy/empty.** Loading sets `aria-busy="true"` on the table; the empty state is a labelled region, never a
  silent blank `<tbody>`.
- **Row activation is never mouse-only.** An `onRowClick` row always also carries a keyboard-reachable action
  — a focusable link in the identifier cell or a `DropdownMenu` — so a row is never the *only* way to open a
  record (`../COMPONENT_LIBRARY.md → Accessibility per component → DataTable`).
- **Selection** checkboxes are real `<input type="checkbox">` with labels naming the row ("Select JE-2026-0482");
  the header select-all announces its tri-state.
- **Row actions** hidden by permission are genuinely absent from the DOM (not `aria-hidden`), so assistive
  tech and sighted users see the same set; actions blocked by *state* rather than permission render disabled
  with an `aria-describedby` reason ("Already posted") — the "who can see this exists" vs. "why can't I do
  this now" distinction from `../COMPONENT_LIBRARY.md → Edge Cases`.
- **Numerals** carry their sign as real `+`/`−` text (from `AmountCell`), so a screen reader and a grayscale
  export both convey polarity without color.
- **Expandable rows** wire `aria-expanded`/`aria-controls`; expansion animation respects
  `prefers-reduced-motion` by collapsing to an instant open/close.
- **Cell-level focus** uses a roving `tabindex` grid pattern with `aria-rowindex`/`aria-colindex` on
  virtualized rows so the announced position stays correct even though off-screen rows are not in the DOM.

# Theming, Dark Mode & RTL

All colour, radius, and spacing come from the CSS-variable tokens in `../COMPONENT_LIBRARY.md → Foundations →
Design tokens`; a table file never contains a raw hex, a numbered Tailwind palette step, or a `dark:` variant
against a raw colour. Structure is built from tokens: row divider `border-ink-150`, header/footer fill
`bg-ink-100` (`bg-muted`), hover `hover:bg-ink-100/60`, selected row `bg-accent-100`, the AI-provenance dot
`bg-accent-600`. Zebra fill is never used — a single `ink-150` bottom border per row, with every fifth row a
marginally stronger `ink-300`/`ink-150` rule on tables past ~40 rows, per `../DESIGN_LANGUAGE.md → Row
treatment`.

**Dark mode** is a token remap only: `data-theme="dark"` on `<html>` re-points every `--qayd-*` variable
(`../COMPONENT_LIBRARY.md → Foundations`), so the identical table markup renders correctly dark with no
table-level conditional. Elevation gets *lighter* in dark mode (a raised header sits above the canvas), and
the AI/selection accent lifts rather than saturates — the table inherits this for free from the tokens.

**RTL** is the highest-risk area of the whole system, and the rule is absolute: **logical properties only.**
Sticky pinning is `sticky inset-inline-start-0` / `inset-inline-end-0`, never `left-0`/`right-0`; padding and
alignment are `ps-*`/`pe-*`/`text-start`/`text-end`; the search icon insets with `inset-inline-start`. A
physical `left-0` on a pinned account column is syntactically valid, visually correct in an English demo, and
silently pins the *wrong* edge the instant the Trial Balance is viewed in Arabic — `../RESPONSIVE_DESIGN.md`
calls this "the single highest-risk line of code this entire specification describes," and this system
enforces it with the same ESLint `no-restricted-syntax` ban. Column order mirrors automatically (the
identifier column sits at the visual right, amounts flow leftward). Arrow-key navigation mirrors too: `→`
moves toward the logical start. The two things that never mirror are **numerals and currency codes** — every
`AmountCell`/`AccountCell` code and money span is `dir="ltr"` with `numberingSystem: 'latn'`, so
`1,050.000 KWD` reads left-to-right inside a right-to-left row, never as reversed digits
(`../COMPONENT_LIBRARY.md → Theming & RTL`, rule 2).

# i18n & Formatting

- **Strings** come from next-intl `useTranslations(namespace)` — column headers, the density labels, "Load
  more," "Select all," empty/error copy — with a key in both `en` and `ar` or CI fails (`../README.md →
  Internationalization`). A header string is never a hard-coded English literal in the column def; the
  `header` is a translation key resolved at render.
- **Money** always renders through `AmountCell` (`font-mono tabular-nums`, `dir="ltr"`,
  `numberingSystem: 'latn'`), never through a generic `useFormatter().number()` — this is what guarantees the
  fixed-numeral-system and KWD/BHD/OMR 3-decimal rules hold regardless of locale
  (`../COMPONENT_LIBRARY.md → i18n mechanics`).
- **Dates** render through `FormattedDate`/`FormattedRelativeTime` over `Intl.DateTimeFormat`, parameterized
  by the active locale, numerals `dir="ltr"`.
- **Bilingual master data** (account/customer/vendor names) resolves `name_en`/`name_ar` at render from
  `useLocale()` — the API returns both regardless of `Accept-Language`, so the client owns display language
  and a user on an Arabic UI can still search by an English account name.
- **Negative numbers** follow the split from `../DESIGN_LANGUAGE.md → Numeral formatting rules`: minus sign in
  raw/editable/ledger tables and exports; parentheses in formally rendered statements — never color as the
  only negative signal.
- **Not-applicable vs. zero**: an em dash (`—`) for a structurally inapplicable cell (a debit-normal
  account's credit column), an explicit `0.000` for a genuine zero balance.

# Testing

Per the platform testing model (`../COMPONENT_LIBRARY.md → Testing`), tables are covered at four levels:

- **Storybook.** `DataTable`, `ResponsiveDataView`, and each cell renderer ship a `.stories.tsx` inspectable
  in all four LTR/RTL × light/dark combinations via the global decorator, plus `parameters.state` mocks that
  short-circuit the query hook to `isPending` / `isError` / empty-`data`, so the loading, error, and both
  empty variants are visually reviewable without a live backend.
- **Vitest + Testing Library.** The logic-bearing pieces: the sort→param serializer (`-entry_date` round
  trips through `syncToUrl` and back), the filter flattener (`{ status: { in: [...] } }` → the documented
  `filter[status][in]=` grammar), the density→row-height map, the "select all matching" carrying the filter
  rather than enumerating ids, and the cursor-reset-on-sort-change invariant.
- **Playwright.** The two flows too state-dependent for a component test: a real server round-trip on
  sort/filter/page-change against a seeded API, **asserting the request URL's query string matches the
  documented grammar** (not merely that some new rows appeared), and the RTL sticky-column test that pins the
  account column to the physical *right* edge in Arabic (`../RESPONSIVE_DESIGN.md`'s named regression). A
  keyboard-only pass (arrow navigation, `Enter` to open, `Space` to select) is part of `DataTable`'s
  pre-release manual gate.
- **Accessibility CI.** `axe-core` runs against every table story; a regressed `aria-sort`, missing
  `scope`, or unlabelled checkbox fails the build.

# Edge Cases

- **Huge unfiltered ledger.** `paginationMode="cursor"` against `ledger-entries` offers no "jump to last
  page" (cursor pagination cannot) and shows a persistent, dismissible hint nudging the user to scope by
  account or date range rather than silently scrolling millions of rows (`../COMPONENT_LIBRARY.md → Edge
  Cases`).
- **Cursor mode has no exact total.** `total` is `null`; the UI shows `total_hint` as "~N," never a precise
  count, and never renders "Page X of Y" in cursor mode.
- **Sort change while paginated.** Changing sort resets the cursor to `null` (page-mode resets to page 1),
  because a cursor is signed against a specific sort and reusing it yields a `422`.
- **Long bilingual names.** Arabic names commonly run longer than English at the same size; every truncating
  name cell applies `truncate` + a `title` attribute + a keyboard-focus tooltip carrying the full string,
  never a fixed `max-width` tuned only on English samples (`../COMPONENT_LIBRARY.md → Edge Cases`).
- **Structurally-inapplicable cell.** A debit-normal account's credit column (or vice versa) renders `—`,
  distinct from a real `0.000` balance — the two must never be conflated.
- **Row permission removed mid-session.** A row action's `usePermission` is a client snapshot; the mutation
  hook re-checks and surfaces the server's `403` as final, refetching the permission cache and toasting that
  access changed, rather than trusting a mount-time check for a sensitive `Post`.
- **Optimistic row mutation rolled back on `409`.** A per-row post/edit that returns `409 version_conflict`
  discards the optimistic cache patch, refetches the authoritative row, flashes it `negative-subtle` once,
  and surfaces an in-row "changed since you loaded it" affordance — never a silent overwrite.
- **Empty resource vs. filtered miss.** An unfiltered empty resource shows the full `emptyState` with its
  create action; a filtered result of zero shows the lighter "no rows match / clear filters" variant — a new
  company staring at "No journal entries yet, create your first" must never be confused with an
  over-filtered view.
- **Reduced motion.** Row-entrance stagger (capped at 12 rows per `DESIGN_LANGUAGE.md`), the realtime-update
  row flash, and expandable-row height all collapse to instant state changes under
  `prefers-reduced-motion: reduce` — "no motion," not "quick motion."
- **Print / PDF export.** The same table data rendered server-side to a grayscale, no-JS PDF reads correctly
  because polarity rides on the `AmountCell` sign glyph and status on the `StatusPill` dot+label, never on
  color alone — a designed invariant, not a coincidence.
- **Realtime row arrival.** A Reverb event for a row in the current view patches the TanStack Query cache via
  `setQueryData` and flashes the affected row `accent-subtle` back to transparent over 600ms — no toast, no
  sound, no layout shift (`../DESIGN_LANGUAGE.md → Named patterns → Realtime row update`).

# End of Document
