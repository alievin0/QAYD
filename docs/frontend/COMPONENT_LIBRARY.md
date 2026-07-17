# Component Library — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: COMPONENT_LIBRARY
---

# Purpose

QAYD's frontend is a single Next.js 15 application (App Router, React 19, TypeScript strict) that renders
every screen described in the platform's screen specifications — Dashboard, Accounting, General Ledger,
Journal Entries, Trial Balance, Balance Sheet, P&L, Cash Flow, Banking, Bank Reconciliation, and the AI
Command Center — from one shared component library. This document is the binding contract for that
library: every visual element on every screen is either a primitive from this catalogue, a finance
component built on top of those primitives, or a documented composition of both. No screen may hand-roll a
table, a money cell, an approval affordance, or a confidence indicator that duplicates something already
specified here — that duplication is exactly how a 40-screen ERP frontend rots into forty slightly
different tables within a year.

The library is built as a thin, disciplined extension of **shadcn/ui** over **Radix UI** primitives, styled
with **Tailwind CSS**, animated with **Framer Motion**, iconed with **Lucide**, and composed against
**TanStack Query** for all server state. It contains two layers:

1. **Primitives** — the shadcn/ui base set (`Button`, `Input`, `Select`, `Dialog`, `Sheet`, `Tabs`, `Toast`,
   `Tooltip`, `DropdownMenu`, `Badge`, `Card`, and their siblings), copied into `components/ui/` per the
   shadcn convention (not installed as an opaque npm dependency — the source lives in the repository and is
   owned by QAYD), then re-skinned with QAYD's design tokens and variant sets.
2. **Finance components** — QAYD-specific, domain-aware components (`DataTable`, `AmountCell`,
   `AccountPicker`, `JournalEntryForm`, `ApprovalCard`, `ConfidenceBadge`, `AIProposalPanel`, `KpiTile`,
   `TrendSparkline`, `StatusPill`, `PeriodPicker`, `CurrencyTag`, `AgingBar`) that compose primitives with
   real QAYD API shapes, permission keys, and AI-proposal semantics. These live in `components/accounting/`,
   `components/ai/`, and `components/shared/`.

The frontend never contains business or financial logic. Every number a finance component renders was
computed and validated by Laravel; every mutation a finance component triggers is a call to
`/api/v1/...` guarded by the same RBAC permission the API itself enforces. A component may format,
paginate, sort, debounce, and animate — it may never decide whether an entry balances, whether a period is
open, or whether a user is allowed to post. Those are server answers the component surfaces, never
recomputes. Where a component appears to "validate" something (the balanced-lines check in
`JournalEntryForm`, for instance), that validation exists purely as a fast, friendly rejection *before* the
network round-trip — the server re-validates the identical rule unconditionally and is the only party whose
answer is authoritative (see `JOURNAL_ENTRIES.md → Posting Engine`, step 3: the server "never trusts the
cached header totals").

This document assumes the reader has the platform's shared conventions open alongside it:
`docs/api/REST_STANDARDS.md` for the response envelope and header contract, `docs/api/API_PAGINATION.md`
and `docs/api/API_FILTERING_SORTING.md` for the query grammar every `DataTable` speaks, and
`docs/accounting/JOURNAL_ENTRIES.md` and `docs/accounting/CHART_OF_ACCOUNTS.md` for the exact field shapes
`JournalEntryForm` and `AccountPicker` bind to. Nothing in this document introduces a new API shape, a new
permission key, or a new table — it only renders what those documents already define.

# Foundations

## How components extend shadcn/ui

QAYD does not fork shadcn/ui's behavior; it **re-themes and re-constrains** it. Every primitive keeps its
Radix accessibility semantics (focus trapping in `Dialog`, roving tabindex in `Tabs`, `aria-*` wiring in
`Select`) untouched, because that behavior is exactly what makes the library keyboard- and screen-reader-
correct for free. What QAYD changes is:

- **Design tokens.** Every color, radius, shadow, and font-size a shadcn component references resolves to a
  QAYD CSS variable (below), never a raw Tailwind palette value (`bg-emerald-600` is banned in component
  source; `bg-[--qayd-accent-600]` — exposed as the Tailwind utility `bg-accent` via the token mapping in
  `tailwind.config.ts` — is required). This is what lets a single token edit re-skin every button,
  checkbox, and badge in the app at once, and what makes dark mode and RTL exact mirrors rather than
  separate implementations.
- **Variant sets via `cva`.** Every primitive that has visual variants (`Button`, `Badge`, `Input`) declares
  them with `class-variance-authority` (`cva`), the same pattern shadcn/ui ships with. QAYD adds
  finance-specific variants on top of the defaults — `Badge` gains a `tone` axis (`neutral | accent | success
  | warning | danger`) consumed by `StatusPill`; `Button` gains a `destructive-quiet` variant used for
  "Reject" / "Void" actions that must never look as loud as `destructive` (reserved for permanent-deletion
  contexts), matching the "calm, never alarming" tone the platform requires for finance UI.
- **Composition over modification.** QAYD never edits a shadcn primitive's internal Radix wiring to add
  business behavior. A permission check, a confidence score, or an audit-log side effect always lives in a
  wrapper component (`ApprovalCard` wraps `Card` + `Button` + `AlertDialog`; it does not teach `Card` about
  approvals). This keeps `components/ui/*` upgradable from upstream shadcn releases with a predictable diff.

## Design tokens

All tokens are CSS variables defined once in `app/globals.css`, mapped into Tailwind via `tailwind.config.ts`
`extend.colors` / `extend.borderRadius` / `extend.fontFamily`, and re-declared under `:root[data-theme="dark"]`
for dark mode and left otherwise untouched for `[dir="rtl"]` (RTL changes layout direction, never color).

**Color — near-monochrome ink with one disciplined accent.** QAYD's visual system is not a colorful
dashboard; it is closer to a well-set financial statement than to a consumer app. The ink scale carries
almost all of the interface — text, borders, surfaces, icons — and exactly one accent hue (a deep, quiet
emerald, consistent with the platform's brand primary) is reserved for the handful of things that must pull
the eye: primary actions, focus rings, the active tab indicator, links, selected rows, and AI-confidence
affordances. Status semantics (`success`/`warning`/`danger`) exist because a finance product cannot avoid
signaling "overdue" or "failed" — but they are deliberately desaturated, always paired with a label or icon
(never color alone), and never used for large surface fills.

```css
:root {
  /* Ink scale — the workhorse of the whole UI */
  --qayd-ink-950: #0B120F;   /* primary text, light mode */
  --qayd-ink-900: #141F1B;
  --qayd-ink-700: #3A4A44;
  --qayd-ink-500: #6B7A74;   /* secondary text, placeholders */
  --qayd-ink-300: #A9B5B0;   /* disabled text, dividers-on-dark */
  --qayd-ink-150: #DCE3E0;   /* borders */
  --qayd-ink-100: #E4E9E7;   /* subtle fills (table header, hover) */
  --qayd-ink-0:   #FFFFFF;

  /* Surfaces */
  --qayd-surface:      #FFFFFF;
  --qayd-canvas:        #F6F8F7;   /* app background behind cards */
  --qayd-surface-raised:#FFFFFF;   /* dialogs, dropdowns — same as surface + shadow, never a tint */

  /* The one accent */
  --qayd-accent-700: #0B5F4B;
  --qayd-accent-600: #0E7A5F;   /* primary buttons, links, focus ring */
  --qayd-accent-500: #128F6E;   /* hover */
  --qayd-accent-100: #E3F3ED;   /* selected-row tint, accent-soft badge fill */

  /* Status (desaturated, label-paired, never used as a large fill) */
  --qayd-success-600: #227A5B;
  --qayd-warning-600: #9C6B15;
  --qayd-danger-600:  #A23B2E;
  --qayd-info-600:    #3E6B99;

  /* Elevation — soft only, per the platform's "no heavy shadows" rule */
  --qayd-shadow-sm: 0 1px 2px 0 rgb(11 18 15 / 0.04);
  --qayd-shadow-md: 0 4px 10px -2px rgb(11 18 15 / 0.06), 0 2px 4px -2px rgb(11 18 15 / 0.04);
  --qayd-shadow-lg: 0 12px 24px -8px rgb(11 18 15 / 0.10), 0 4px 8px -4px rgb(11 18 15 / 0.05);

  /* Radius — tightened deliberately below the platform draft (8/12/20/24) to keep the
     editorial, restrained register the founder's taste mandates; full-round is reserved
     for pill-shaped status/badge elements only, never for cards or dialogs. */
  --qayd-radius-sm:   6px;   /* inputs, checkboxes, small controls */
  --qayd-radius-md:   8px;   /* buttons, table cells on focus */
  --qayd-radius-lg:  10px;   /* cards, dialogs, sheets */
  --qayd-radius-xl:  12px;   /* panels, popovers */
  --qayd-radius-full: 999px; /* StatusPill, Badge, Avatar */

  /* Typography */
  --qayd-font-display: 'Inter Tight', 'IBM Plex Sans Arabic', ui-sans-serif, system-ui;
  --qayd-font-text:    'Inter', 'IBM Plex Sans Arabic', ui-sans-serif, system-ui;

  --qayd-text-display: 2.75rem;  --qayd-lh-display: 1.1;   --qayd-fw-display: 650;
  --qayd-text-heading: 1.75rem;  --qayd-lh-heading: 1.25;  --qayd-fw-heading: 600;
  --qayd-text-title:   1.25rem;  --qayd-lh-title:   1.4;   --qayd-fw-title:   600;
  --qayd-text-subtitle:1.0625rem;--qayd-lh-subtitle:1.45;  --qayd-fw-subtitle:500;
  --qayd-text-body:    0.9375rem;--qayd-lh-body:    1.55;  --qayd-fw-body:    400;
  --qayd-text-caption: 0.8125rem;--qayd-lh-caption: 1.4;   --qayd-fw-caption: 400;
  --qayd-text-label:   0.75rem;  --qayd-lh-label:   1.3;   --qayd-fw-label:   600;

  /* Spacing — reused verbatim from the platform draft scale; never an off-scale value */
  --qayd-space-1: 4px;  --qayd-space-2: 8px;   --qayd-space-3: 12px; --qayd-space-4: 16px;
  --qayd-space-5: 20px; --qayd-space-6: 24px;  --qayd-space-8: 32px; --qayd-space-10: 40px;
  --qayd-space-12: 48px;--qayd-space-16: 64px;
}

:root[data-theme="dark"] {
  --qayd-ink-950: #F3F6F4;   /* primary text flips to near-white */
  --qayd-ink-900: #E7ECE9;
  --qayd-ink-700: #B7C2BE;
  --qayd-ink-500: #8A9993;
  --qayd-ink-300: #4E5C57;
  --qayd-ink-150: #2A342F;
  --qayd-ink-100: #1B241F;
  --qayd-ink-0:   #0B120F;

  --qayd-surface:        #0F1613;
  --qayd-canvas:         #0A0F0D;
  --qayd-surface-raised: #141F1B;

  --qayd-accent-600: #16A67F;   /* lifted for sufficient contrast on dark surfaces */
  --qayd-accent-500: #1CBE92;
  --qayd-accent-100: #123027;

  --qayd-success-600: #33A87F;
  --qayd-warning-600: #C99A3F;
  --qayd-danger-600:  #D06A57;
  --qayd-info-600:    #6B9BCB;
}
```

`tailwind.config.ts` maps these onto semantic utility names so component code never references a raw hex or
a numbered scale step directly:

```ts
// tailwind.config.ts
import type { Config } from 'tailwindcss';

export default {
  darkMode: ['class', '[data-theme="dark"]'],
  theme: {
    extend: {
      colors: {
        ink: {
          950: 'var(--qayd-ink-950)', 900: 'var(--qayd-ink-900)', 700: 'var(--qayd-ink-700)',
          500: 'var(--qayd-ink-500)', 300: 'var(--qayd-ink-300)', 150: 'var(--qayd-ink-150)',
          100: 'var(--qayd-ink-100)', 0: 'var(--qayd-ink-0)',
        },
        surface: 'var(--qayd-surface)',
        canvas: 'var(--qayd-canvas)',
        accent: {
          700: 'var(--qayd-accent-700)', 600: 'var(--qayd-accent-600)',
          500: 'var(--qayd-accent-500)', 100: 'var(--qayd-accent-100)',
        },
        success: 'var(--qayd-success-600)',
        warning: 'var(--qayd-warning-600)',
        danger: 'var(--qayd-danger-600)',
        info: 'var(--qayd-info-600)',
      },
      borderRadius: {
        sm: 'var(--qayd-radius-sm)', md: 'var(--qayd-radius-md)',
        lg: 'var(--qayd-radius-lg)', xl: 'var(--qayd-radius-xl)', full: 'var(--qayd-radius-full)',
      },
      fontFamily: {
        display: ['var(--qayd-font-display)'],
        sans: ['var(--qayd-font-text)'],
      },
      boxShadow: {
        sm: 'var(--qayd-shadow-sm)', md: 'var(--qayd-shadow-md)', lg: 'var(--qayd-shadow-lg)',
      },
    },
  },
} satisfies Config;
```

## Variants via `cva`

Every primitive with more than one visual treatment declares its variants with `class-variance-authority`,
so variant logic is a typed, autocompletable API rather than a pile of conditional class strings. This is
the exact mechanism shadcn/ui ships with; QAYD's contribution is the specific variant sets and defaults
below.

```ts
// components/ui/button.tsx (excerpt)
import { cva, type VariantProps } from 'class-variance-authority';

export const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ' +
  'transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 ' +
  'focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
  {
    variants: {
      variant: {
        default: 'bg-accent-600 text-white hover:bg-accent-500',
        secondary: 'bg-ink-100 text-ink-950 hover:bg-ink-150',
        outline: 'border border-ink-150 bg-transparent text-ink-950 hover:bg-ink-100',
        ghost: 'bg-transparent text-ink-950 hover:bg-ink-100',
        destructive: 'bg-danger text-white hover:opacity-90',
        'destructive-quiet': 'border border-danger/40 text-danger bg-transparent hover:bg-danger/5',
        link: 'text-accent-600 underline-offset-4 hover:underline p-0 h-auto',
      },
      size: {
        sm: 'h-8 px-3 text-[13px]',
        default: 'h-9 px-4',
        lg: 'h-10 px-5 text-[15px]',
        icon: 'h-9 w-9 p-0',
      },
    },
    defaultVariants: { variant: 'default', size: 'default' },
  },
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean;
  loading?: boolean;
}
```

Finance components consume `buttonVariants` the same way any screen does — `ApprovalCard`'s Reject button is
`<Button variant="destructive-quiet" size="sm">`, never a hand-written `<button className="...">`.

## Component-authoring conventions

- **File location.** Primitives live in `components/ui/<name>.tsx` (one file per primitive, matching shadcn
  convention exactly). Finance components live in `components/accounting/<name>.tsx`,
  `components/ai/<name>.tsx`, or `components/shared/<name>.tsx` depending on domain, per
  `docs/foundation/PROJECT_STRUCTURE.md`'s `components/{ui,dashboard,accounting,banking,inventory,payroll,
  sales,reports,settings,ai,shared}` tree.
- **Server data only through hooks.** A finance component never calls `fetch`/`axios` inline. It accepts
  already-resolved data and callbacks as props (dumb-ish, testable), or it calls a co-located TanStack Query
  hook named `use<Resource>Query` / `use<Resource>Mutation` exported from `hooks/accounting/*`. This keeps
  every component Storybook-renderable without a live backend.
- **Money, dates, and IDs are never client-computed.** A component receives `total_debit` as the string the
  API returned; it formats for display, it does not sum lines itself for anything that will be submitted.
- **Every exported component ships a co-located `.stories.tsx` and, where it holds logic, a `.test.tsx`.**
  This is enforced in CI, not a suggestion — see `# Testing`.

# Primitives

The primitives below are shadcn/ui components as customized for QAYD. Each keeps its full Radix
accessibility contract; only tokens, variants, and a small number of QAYD-specific props are added.

| Primitive | Radix base | QAYD additions | Typical finance use |
|---|---|---|---|
| `Button` | — (native `button`) | `cva` variants above; `loading` prop renders a spinner and disables the button without changing its width | Post, Approve, Save Draft, Reject |
| `Input` | — (native `input`) | `variant="numeric"` sets `font-variant-numeric: tabular-nums`, `inputMode="decimal"`, right-alignment; `invalid` prop wires `aria-invalid` + danger border | Debit/credit line amount entry |
| `Select` | `@radix-ui/react-select` | QAYD wraps it as a controlled combobox-free select for short enumerations (`status`, `currency_code`); long/searchable lists use `AccountPicker`/`Combobox` instead, never a giant `<Select>` | Currency, tax code, entry type |
| `Dialog` | `@radix-ui/react-dialog` | Standard sizes `sm\|md\|lg\|full`; `full` is used for `JournalEntryForm` on mobile widths | Confirm approve/reject, record detail |
| `Sheet` | `@radix-ui/react-dialog` (side variant) | Slides from the inline-end edge (`right` in LTR, `left` in RTL — direction resolved from `dir`, never hardcoded) | Row detail drawer, AI reasoning drill-in |
| `Tabs` | `@radix-ui/react-tabs` | Underline-indicator style (accent-600, 2px), animated position via Framer Motion `layoutId` | Family dashboard's `familyHome/familySchedule/...`-style section switching; journal entry detail (`Lines / History / Attachments`) |
| `Toast` | `sonner` (shadcn's current default) | A single `useApiToast()` wrapper maps the API envelope's `message` + `errors[]` directly onto success/error toasts, so no screen hand-writes a toast string | Any mutation result |
| `Tooltip` | `@radix-ui/react-tooltip` | 300ms open delay, 0ms close delay (per platform calm-motion rule); used for AI reasoning previews and truncated bilingual names | "Why 92%?" on `ConfidenceBadge` |
| `DropdownMenu` | `@radix-ui/react-dropdown-menu` | Row-action menus auto-hide items the caller's permission set doesn't include (`usePermission`), rather than rendering them disabled, when the action's existence itself is sensitive (e.g. `Void`) | `DataTable` row actions |
| `Badge` | — (native `span`) | `tone` variant (`neutral\|accent\|success\|warning\|danger`) via `cva`; base for `StatusPill`, `CurrencyTag` | Status labels, currency tags |
| `Card` | — (native `div`) | `padding="none\|sm\|md\|lg"`, `interactive` prop adds hover elevation + cursor-pointer for clickable cards | `KpiTile`, `ApprovalCard`, `AIProposalPanel` shells |

Example — `Tooltip` and `Badge` composed with QAYD tokens only (no raw Tailwind color utilities):

```tsx
// components/ui/badge.tsx (excerpt)
import { cva, type VariantProps } from 'class-variance-authority';

export const badgeVariants = cva(
  'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[12px] font-medium leading-none',
  {
    variants: {
      tone: {
        neutral: 'bg-ink-100 text-ink-700',
        accent:  'bg-accent-100 text-accent-700',
        success: 'bg-success/10 text-success',
        warning: 'bg-warning/10 text-warning',
        danger:  'bg-danger/10 text-danger',
      },
    },
    defaultVariants: { tone: 'neutral' },
  },
);

export function Badge({ tone, className, ...props }: React.ComponentProps<'span'> & VariantProps<typeof badgeVariants>) {
  return <span className={cn(badgeVariants({ tone }), className)} {...props} />;
}
```

# Finance Components

Every component below is domain-aware: its props are shaped by a real QAYD table or endpoint, not a generic
placeholder. Each entry gives the full prop table, a real TSX implementation (or the load-bearing parts of
one), and a usage example wired to an actual `/api/v1/...` route.

## DataTable

Server-paginated, sortable, filterable table used everywhere QAYD lists a collection — journal entries,
invoices, ledger entries, accounts, customers. It never paginates, sorts, or filters client-side; every one
of those operations is a new server request built from the exact query grammar in
`docs/api/API_PAGINATION.md` and `docs/api/API_FILTERING_SORTING.md`. `DataTable` is a thin rendering layer
over **TanStack Table** (headless column/row model) driven by **TanStack Query** (server state, cache keys).

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `columns` | `ColumnDef<TData, TValue>[]` | yes | TanStack Table column definitions. Each sortable column's `id` must match a `SORTABLE_FIELDS` entry on the backend resource. |
| `resource` | `string` | yes | API path segment, e.g. `'accounting/journal-entries'`. Used to build the query key and the request URL. |
| `paginationMode` | `'page' \| 'cursor'` | yes | Must match the endpoint's documented mode (see `API_PAGINATION.md → Strategy`). `DataTable` renders page numbers + "Page X of Y" for `'page'`, and an infinite "Load more" / virtualized scroll affordance for `'cursor'`. |
| `defaultFilters` | `Record<string, unknown>` | no | Pre-applied `filter[...]` values, e.g. `{ status: { in: ['posted', 'reversed'] } }`. Merged with user-set filters. |
| `defaultSort` | `string` | no | e.g. `'-entry_date'`. Falls back to the platform default `-created_at` if omitted. |
| `searchable` | `boolean` | no | Renders the `q` search box; only valid if the resource has `SEARCHABLE_FIELDS`. |
| `rowActions` | `(row: TData) => RowAction[]` | no | Per-row `DropdownMenu` items. Each `RowAction` carries its own `permission` key; items whose permission the caller lacks are omitted, not disabled (see Accessibility). |
| `onRowClick` | `(row: TData) => void` | no | Typically opens a `Sheet` detail drawer. |
| `emptyState` | `{ title: string; description: string; action?: ReactNode }` | no | Rendered when `data.length === 0` and no filters are active; a *filtered* empty result renders a lighter "no rows match your filters" variant automatically. |
| `getRowId` | `(row: TData) => string` | yes | Stable row key; always the resource's `id`, never array index. |

**Implementation**

```tsx
// components/shared/data-table.tsx
'use client';

import { useState } from 'react';
import {
  useReactTable, getCoreRowModel, flexRender,
  type ColumnDef, type SortingState,
} from '@tanstack/react-table';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/table';
import { Skeleton } from '@/components/ui/skeleton';
import { Input } from '@/components/ui/input';
import { ArrowUpDown, ArrowUp, ArrowDown, SearchIcon } from 'lucide-react';
import { EmptyState } from '@/components/shared/empty-state';
import { ErrorState } from '@/components/shared/error-state';
import type { ApiEnvelope } from '@/types/api';

interface DataTableProps<TData, TValue> {
  columns: ColumnDef<TData, TValue>[];
  resource: string;
  paginationMode: 'page' | 'cursor';
  defaultFilters?: Record<string, unknown>;
  defaultSort?: string;
  searchable?: boolean;
  rowActions?: (row: TData) => RowAction[];
  onRowClick?: (row: TData) => void;
  emptyState?: { title: string; description: string; action?: React.ReactNode };
  getRowId: (row: TData) => string;
}

export function DataTable<TData, TValue>({
  columns, resource, paginationMode, defaultFilters, defaultSort, searchable,
  rowActions, onRowClick, emptyState, getRowId,
}: DataTableProps<TData, TValue>) {
  const [sorting, setSorting] = useState<SortingState>(
    defaultSort ? [{ id: defaultSort.replace('-', ''), desc: defaultSort.startsWith('-') }] : [],
  );
  const [page, setPage] = useState(1);
  const [cursor, setCursor] = useState<string | null>(null);
  const [q, setQ] = useState('');
  const sort = sorting.length ? `${sorting[0].desc ? '-' : ''}${sorting[0].id}` : defaultSort;

  const { data, isPending, isError, isFetching } = useQuery({
    queryKey: [resource, { sort, page, cursor, q, filters: defaultFilters }],
    queryFn: () =>
      apiClient.get<ApiEnvelope<TData[]>>(`/api/v1/${resource}`, {
        params: {
          sort,
          ...(paginationMode === 'page' ? { page, per_page: 25 } : { cursor, per_page: 25 }),
          ...(q ? { q } : {}),
          ...flattenFilters(defaultFilters),
        },
      }),
    placeholderData: keepPreviousData, // avoids layout flash between pages
  });

  const table = useReactTable({
    data: data?.data ?? [],
    columns,
    state: { sorting },
    onSortingChange: setSorting,
    getRowId: (row) => getRowId(row),
    getCoreRowModel: getCoreRowModel(),
    manualSorting: true,
    manualPagination: true,
  });

  if (isError) return <ErrorState resource={resource} />;
  if (!isPending && data?.data.length === 0) return <EmptyState {...emptyState} />;

  return (
    <div className="space-y-3">
      {searchable && (
        <div className="relative max-w-xs">
          <SearchIcon className="absolute inset-inline-start-2.5 top-2.5 h-4 w-4 text-ink-500" />
          <Input
            className="ps-8"
            placeholder="Search…"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            aria-label="Search"
          />
        </div>
      )}
      <Table aria-busy={isFetching}>
        <TableHeader>
          {table.getHeaderGroups().map((hg) => (
            <TableRow key={hg.id}>
              {hg.headers.map((header) => (
                <TableHead
                  key={header.id}
                  className={header.column.getCanSort() ? 'cursor-pointer select-none' : undefined}
                  onClick={header.column.getToggleSortingHandler()}
                  aria-sort={
                    header.column.getIsSorted() === 'asc' ? 'ascending'
                    : header.column.getIsSorted() === 'desc' ? 'descending' : 'none'
                  }
                >
                  <span className="inline-flex items-center gap-1">
                    {flexRender(header.column.columnDef.header, header.getContext())}
                    {header.column.getCanSort() && (
                      header.column.getIsSorted() === 'asc' ? <ArrowUp className="h-3.5 w-3.5" />
                      : header.column.getIsSorted() === 'desc' ? <ArrowDown className="h-3.5 w-3.5" />
                      : <ArrowUpDown className="h-3.5 w-3.5 text-ink-300" />
                    )}
                  </span>
                </TableHead>
              ))}
            </TableRow>
          ))}
        </TableHeader>
        <TableBody>
          {isPending
            ? Array.from({ length: 8 }).map((_, i) => (
                <TableRow key={i}>
                  {columns.map((_, j) => (
                    <TableCell key={j}><Skeleton className="h-4 w-full" /></TableCell>
                  ))}
                </TableRow>
              ))
            : table.getRowModel().rows.map((row) => (
                <TableRow
                  key={row.id}
                  className={onRowClick ? 'cursor-pointer hover:bg-ink-100/60' : undefined}
                  onClick={() => onRowClick?.(row.original)}
                >
                  {row.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</TableCell>
                  ))}
                </TableRow>
              ))}
        </TableBody>
      </Table>
      <DataTablePagination
        mode={paginationMode}
        meta={data?.meta.pagination}
        page={page}
        onPageChange={setPage}
        onCursorChange={setCursor}
      />
    </div>
  );
}
```

**Usage**

```tsx
// app/(app)/accounting/journal-entries/page.tsx
const columns: ColumnDef<JournalEntry>[] = [
  { accessorKey: 'journal_number', header: 'Entry #' },
  { accessorKey: 'entry_date', header: 'Date', cell: ({ row }) => <FormattedDate value={row.original.journal_date} /> },
  { accessorKey: 'reference', header: 'Reference' },
  {
    accessorKey: 'total_debit',
    header: 'Amount',
    cell: ({ row }) => <AmountCell amount={row.original.total_debit} currencyCode="KWD" mode="debit" />,
  },
  { accessorKey: 'status', header: 'Status', cell: ({ row }) => <StatusPill domain="journal_entry" status={row.original.status} /> },
];

<DataTable
  columns={columns}
  resource="accounting/journal-entries"
  paginationMode="page"
  defaultSort="-entry_date"
  searchable
  defaultFilters={{ status: { in: ['draft', 'pending_approval', 'posted'] } }}
  rowActions={(row) => [
    { label: 'View', icon: Eye, onClick: () => router.push(`/accounting/journal-entries/${row.id}`) },
    { label: 'Post', icon: Check, permission: 'accounting.journal.post', onClick: () => postEntry(row.id) },
    { label: 'Reverse', icon: Undo2, permission: 'accounting.journal.post', onClick: () => reverseEntry(row.id) },
  ]}
  getRowId={(row) => String(row.id)}
/>
```

`ledger-entries`-style cursor-only endpoints pass `paginationMode="cursor"`; `DataTable` then omits page
numbers and the `total` count entirely (per `API_PAGINATION.md`, cursor mode's `total` is `null`), rendering
`meta.pagination.total_hint` as an approximate, explicitly-labeled "~48,213 entries" instead — never
presented as an exact count.

## AmountCell

Renders a single monetary value from a QAYD API response. Money always arrives as a `NUMERIC(19,4)` JSON
**string** (`"450.0000"`, per `REST_STANDARDS.md → Date/Time & Money Formatting`) — `AmountCell` is the one
place that string is ever parsed for display, and it never re-serializes a rounded value back toward the
server.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `amount` | `string` | yes | Raw `NUMERIC(19,4)` string from the API, e.g. `"1050.0000"`. |
| `currencyCode` | `string` | yes | ISO 4217, e.g. `"KWD"`. Drives both the currency symbol/code and the *display* decimal precision (see Edge Cases — KWD/BHD/OMR display at 3 decimals, JPY at 0, most others at 2, independent of the API's fixed 4-decimal storage precision). |
| `mode` | `'debit' \| 'credit' \| 'signed' \| 'plain'` | no, default `'plain'` | `'debit'`/`'credit'` render a leading `+`/`−` sign consistent with the column's semantic side and a subdued vs. ink color; `'signed'` reads the sign directly from the numeric value; `'plain'` shows the absolute value with no sign. |
| `align` | `'start' \| 'end'` | no, default `'end'` | Numeric columns right-align in LTR **and** RTL (numerals are never mirrored — see Theming & RTL). |
| `showCurrency` | `boolean` | no, default `false` | Appends the ISO code (`"1,050.000 KWD"`); tables showing one currency per column normally set this `false` and use a column-level `CurrencyTag` instead. |
| `emphasis` | `'default' \| 'strong' \| 'muted'` | no | `'strong'` for grand totals/footer rows; `'muted'` for zero/void amounts. |

**Implementation**

```tsx
// components/accounting/amount-cell.tsx
import { cn } from '@/lib/utils';

const CURRENCY_DISPLAY_DECIMALS: Record<string, number> = {
  KWD: 3, BHD: 3, OMR: 3, // 1000 fils/baisa per major unit
  JPY: 0, KRW: 0,
  // default 2 for AED, SAR, USD, EUR, GBP, etc.
};

export function formatAmount(raw: string, currencyCode: string): string {
  const decimals = CURRENCY_DISPLAY_DECIMALS[currencyCode] ?? 2;
  const value = Number(raw); // raw is a validated server NUMERIC string; safe to parse for display only
  return new Intl.NumberFormat('en', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
    numberingSystem: 'latn', // Western Arabic numerals even under an Arabic locale — see Theming & RTL
  }).format(Math.abs(value));
}

interface AmountCellProps {
  amount: string;
  currencyCode: string;
  mode?: 'debit' | 'credit' | 'signed' | 'plain';
  align?: 'start' | 'end';
  showCurrency?: boolean;
  emphasis?: 'default' | 'strong' | 'muted';
}

export function AmountCell({
  amount, currencyCode, mode = 'plain', align = 'end', showCurrency = false, emphasis = 'default',
}: AmountCellProps) {
  const numeric = Number(amount);
  const isZero = numeric === 0;
  const sign = mode === 'debit' ? '+' : mode === 'credit' ? '−' : mode === 'signed' && numeric < 0 ? '−' : '';
  const formatted = formatAmount(amount, currencyCode);

  return (
    <span
      className={cn(
        'font-mono tabular-nums whitespace-nowrap',
        align === 'end' ? 'text-end' : 'text-start',
        emphasis === 'strong' && 'font-semibold text-ink-950',
        emphasis === 'muted' && 'text-ink-500',
        isZero && 'text-ink-500',
        !isZero && mode === 'credit' && 'text-danger',
      )}
      dir="ltr" // numerals + sign always render LTR-shaped even inside an RTL row
    >
      {sign}
      {formatted}
      {showCurrency && <span className="ms-1 text-ink-500">{currencyCode}</span>}
    </span>
  );
}
```

**Usage**

```tsx
<AmountCell amount={line.debit !== '0.0000' ? line.debit : line.credit}
            currencyCode={entry.currency_code}
            mode={line.debit !== '0.0000' ? 'debit' : 'credit'} />

// Grand total row
<AmountCell amount={entry.total_debit} currencyCode={entry.currency_code} emphasis="strong" showCurrency />
```

Color is always a *secondary* signal here — the leading `+`/`−` glyph carries the meaning on its own, so a
colorblind user reading a monochrome print export loses nothing (see Accessibility).

## AccountPicker

A searchable combobox over the company's Chart of Accounts (`accounts`), used anywhere a journal line,
budget row, or report filter needs to reference a GL account. Built on shadcn's `Command` + `Popover`
(Radix) pattern rather than a native `<select>`, because a mid-size company's account tree commonly holds
several hundred rows and a flat dropdown is both slow to scan and inaccessible at that length.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `number \| null` | yes | Selected `accounts.id`. |
| `onChange` | `(accountId: number, account: Account) => void` | yes | |
| `postableOnly` | `boolean` | no, default `true` | Filters to `allow_posting = true, status = 'active'` — header/summary accounts in the tree are excluded, matching the Posting Engine's own `is_postable` rule (see `JOURNAL_ENTRIES.md → Journal Lines`). |
| `controlAccountFilter` | `'ar' \| 'ap' \| null` | no | Restricts to the Accounts Receivable or Accounts Payable control account(s), used when the picker is bound to a line that also carries `customer_id`/`vendor_id`. |
| `accountTypeFilter` | `AccountTypeCode[]` | no | e.g. `['expense']` for a cost-entry-only context. |
| `disabled` | `boolean` | no | |
| `placeholder` | `string` | no | i18n key, defaults to `t('accountPicker.placeholder')`. |

**Implementation**

```tsx
// components/accounting/account-picker.tsx
'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { apiClient } from '@/lib/api-client';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Command, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem } from '@/components/ui/command';
import { Button } from '@/components/ui/button';
import { ChevronsUpDown, Check } from 'lucide-react';
import { useLocale } from 'next-intl';
import { cn } from '@/lib/utils';
import type { Account } from '@/types/accounting';

interface AccountPickerProps {
  value: number | null;
  onChange: (accountId: number, account: Account) => void;
  postableOnly?: boolean;
  controlAccountFilter?: 'ar' | 'ap' | null;
  accountTypeFilter?: string[];
  disabled?: boolean;
  placeholder?: string;
}

export function AccountPicker({
  value, onChange, postableOnly = true, controlAccountFilter, accountTypeFilter, disabled, placeholder,
}: AccountPickerProps) {
  const locale = useLocale();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebouncedValue(search, 250);

  const { data, isFetching } = useQuery({
    queryKey: ['accounts', 'picker', { debouncedSearch, postableOnly, controlAccountFilter, accountTypeFilter }],
    queryFn: () =>
      apiClient.get<{ data: Account[] }>('/api/v1/accounting/accounts', {
        params: {
          q: debouncedSearch || undefined,
          'filter[allow_posting]': postableOnly ? true : undefined,
          'filter[status]': 'active',
          'filter[is_control_account]': controlAccountFilter ? true : undefined,
          per_page: 50,
          sort: 'code',
        },
      }),
    enabled: open,
  });

  const selected = data?.data.find((a) => a.id === value);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className="w-full justify-between font-normal"
        >
          {selected
            ? <span className="truncate">{selected.code} · {locale === 'ar' ? selected.name_ar : selected.name_en}</span>
            : <span className="text-ink-500">{placeholder ?? 'Select an account…'}</span>}
          <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput placeholder="Search by code or name…" value={search} onValueChange={setSearch} />
          <CommandList>
            {isFetching && <div className="p-4 text-sm text-ink-500">Searching…</div>}
            <CommandEmpty>No account found.</CommandEmpty>
            <CommandGroup>
              {data?.data.map((account) => (
                <CommandItem
                  key={account.id}
                  value={String(account.id)}
                  onSelect={() => { onChange(account.id, account); setOpen(false); }}
                >
                  <Check className={cn('me-2 h-4 w-4', account.id === value ? 'opacity-100' : 'opacity-0')} />
                  <span className="text-ink-500 font-mono text-xs w-14 shrink-0">{account.code}</span>
                  <span className="truncate">{locale === 'ar' ? account.name_ar : account.name_en}</span>
                  {account.is_control_account && (
                    <span className="ms-auto text-[11px] text-ink-500">control</span>
                  )}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
```

**Usage**

```tsx
<AccountPicker
  value={line.account_id}
  onChange={(id, account) => {
    updateLine(index, { account_id: id });
    if (account.is_control_account) setControlContext(index, account.nature === 'asset' ? 'ar' : 'ap');
  }}
  postableOnly
/>
```

## JournalEntryForm

The create/edit surface for a manual journal entry (`docs/accounting/JOURNAL_ENTRIES.md → Manual Entries`).
It reproduces, client-side and as a UX convenience only, the exact invariant the Posting Engine enforces
authoritatively: `SUM(debit) = SUM(credit)`. The client check exists purely to reject an unbalanced entry
before a network round trip; the server re-derives the same sum from the submitted lines and is the only
party whose answer can actually post the entry (see `JOURNAL_ENTRIES.md → Posting Engine`, step 3).

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `mode` | `'create' \| 'edit'` | yes | `'edit'` is only reachable for entries in `draft`/`rejected` status; the route guard redirects otherwise (see `JOURNAL_ENTRIES.md → Locking Rules`). |
| `journalEntryId` | `number` | conditional | Required when `mode="edit"`. |
| `defaultValues` | `Partial<JournalEntryFormValues>` | no | Pre-fill, e.g. from an AI-drafted entry (see `AIProposalPanel`) or a duplicated prior entry. |
| `onSubmitted` | `(entry: JournalEntry) => void` | no | Called after a successful create/update, before any post/submit action. |

**Schema and form**

```tsx
// lib/schemas/journal-entry.ts
import { z } from 'zod';

export const journalLineSchema = z.object({
  account_id: z.number({ required_error: 'accountRequired' }),
  description: z.string().max(500).optional(),
  debit: z.string().regex(/^\d+(\.\d{1,4})?$/).default('0.0000'),
  credit: z.string().regex(/^\d+(\.\d{1,4})?$/).default('0.0000'),
  cost_center_id: z.number().nullable().optional(),
  project_id: z.number().nullable().optional(),
  customer_id: z.number().nullable().optional(),
  vendor_id: z.number().nullable().optional(),
}).refine((line) => {
  const debit = Number(line.debit), credit = Number(line.credit);
  return (debit > 0 && credit === 0) || (credit > 0 && debit === 0);
}, { message: 'exactlyOneOfDebitCredit', path: ['debit'] });

export const journalEntrySchema = z.object({
  journal_date: z.string().date(),
  currency_code: z.string().length(3),
  reference: z.string().max(100).optional(),
  memo: z.string().min(1, 'memoRequiredForManualEntries'),
  cost_center_id: z.number().nullable().optional(),
  lines: z.array(journalLineSchema).min(2, 'minimumTwoLines'),
}).superRefine((entry, ctx) => {
  const totalDebit = entry.lines.reduce((s, l) => s + Number(l.debit), 0);
  const totalCredit = entry.lines.reduce((s, l) => s + Number(l.credit), 0);
  if (Math.round((totalDebit - totalCredit) * 10_000) !== 0) {
    ctx.addIssue({ code: 'custom', message: 'entryDoesNotBalance', path: ['lines'] });
  }
});

export type JournalEntryFormValues = z.infer<typeof journalEntrySchema>;
```

```tsx
// components/accounting/journal-entry-form.tsx
'use client';

import { useForm, useFieldArray } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslations } from 'next-intl';
import { journalEntrySchema, type JournalEntryFormValues } from '@/lib/schemas/journal-entry';
import { useCreateJournalEntry, useUpdateJournalEntry, usePostJournalEntry, useSubmitJournalEntry }
  from '@/hooks/accounting/use-journal-entries';
import { usePermission } from '@/hooks/use-permission';
import { AccountPicker } from '@/components/accounting/account-picker';
import { AmountCell } from '@/components/accounting/amount-cell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2 } from 'lucide-react';
import { useApiToast } from '@/hooks/use-api-toast';

export function JournalEntryForm({ mode, journalEntryId, defaultValues, onSubmitted }: JournalEntryFormProps) {
  const t = useTranslations('journalEntryForm');
  const canPostDirect = usePermission('accounting.journal.post');
  const canCreate = usePermission('accounting.journal.create');
  const toast = useApiToast();

  const form = useForm<JournalEntryFormValues>({
    resolver: zodResolver(journalEntrySchema),
    defaultValues: defaultValues ?? { currency_code: 'KWD', lines: [{}, {}] },
  });
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'lines' });
  const lines = form.watch('lines');

  const totalDebit = lines.reduce((s, l) => s + Number(l.debit || 0), 0);
  const totalCredit = lines.reduce((s, l) => s + Number(l.credit || 0), 0);
  const balanced = Math.abs(totalDebit - totalCredit) < 0.0001;

  const create = useCreateJournalEntry();
  const update = useUpdateJournalEntry(journalEntryId);
  const post = usePostJournalEntry();
  const submitForApproval = useSubmitJournalEntry();

  async function onSave(values: JournalEntryFormValues, action: 'draft' | 'post') {
    const mutation = mode === 'create' ? create : update;
    const entry = await mutation.mutateAsync(values, {
      onError: (err) => toast.fromApiError(err), // maps errors[].field onto form.setError(...)
    });
    onSubmitted?.(entry);
    if (action === 'post') {
      canPostDirect ? await post.mutateAsync(entry.id) : await submitForApproval.mutateAsync(entry.id);
    }
  }

  return (
    <form onSubmit={form.handleSubmit((v) => onSave(v, 'draft'))} className="space-y-6" noValidate>
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <FormField label={t('journalDate')} error={form.formState.errors.journal_date}>
          <Input type="date" {...form.register('journal_date')} />
        </FormField>
        <FormField label={t('currency')} error={form.formState.errors.currency_code}>
          <CurrencySelect {...form.register('currency_code')} />
        </FormField>
        <FormField label={t('reference')} error={form.formState.errors.reference}>
          <Input {...form.register('reference')} />
        </FormField>
      </div>
      <FormField label={t('memo')} error={form.formState.errors.memo} required>
        <Textarea rows={2} {...form.register('memo')} />
      </FormField>

      <div className="rounded-lg border border-ink-150">
        <table className="w-full text-sm">
          <thead className="bg-ink-100 text-ink-700">
            <tr>
              <th className="p-2 text-start">{t('account')}</th>
              <th className="p-2 text-start">{t('description')}</th>
              <th className="p-2 text-end w-32">{t('debit')}</th>
              <th className="p-2 text-end w-32">{t('credit')}</th>
              <th className="p-2 w-10" />
            </tr>
          </thead>
          <tbody>
            {fields.map((field, index) => (
              <tr key={field.id} className="border-t border-ink-150">
                <td className="p-2">
                  <AccountPicker
                    value={form.watch(`lines.${index}.account_id`)}
                    onChange={(id) => form.setValue(`lines.${index}.account_id`, id, { shouldValidate: true })}
                  />
                </td>
                <td className="p-2"><Input {...form.register(`lines.${index}.description`)} /></td>
                <td className="p-2">
                  <Input variant="numeric" {...form.register(`lines.${index}.debit`)}
                    onChange={(e) => { form.setValue(`lines.${index}.debit`, e.target.value);
                                        if (Number(e.target.value) > 0) form.setValue(`lines.${index}.credit`, '0.0000'); }} />
                </td>
                <td className="p-2">
                  <Input variant="numeric" {...form.register(`lines.${index}.credit`)}
                    onChange={(e) => { form.setValue(`lines.${index}.credit`, e.target.value);
                                        if (Number(e.target.value) > 0) form.setValue(`lines.${index}.debit`, '0.0000'); }} />
                </td>
                <td className="p-2">
                  <Button type="button" variant="ghost" size="icon" disabled={fields.length <= 2}
                          onClick={() => remove(index)} aria-label={t('removeLine')}>
                    <Trash2 className="h-4 w-4 text-ink-500" />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t border-ink-150 font-medium">
              <td className="p-2" colSpan={2}>
                <Button type="button" variant="ghost" size="sm" onClick={() => append({})}>
                  <Plus className="h-3.5 w-3.5" /> {t('addLine')}
                </Button>
              </td>
              <td className="p-2 text-end"><AmountCell amount={totalDebit.toFixed(4)} currencyCode={form.watch('currency_code')} emphasis="strong" /></td>
              <td className="p-2 text-end"><AmountCell amount={totalCredit.toFixed(4)} currencyCode={form.watch('currency_code')} emphasis="strong" /></td>
              <td />
            </tr>
          </tfoot>
        </table>
      </div>

      <div className="flex items-center justify-between rounded-md bg-ink-100 px-4 py-2.5 text-sm">
        <span className={balanced ? 'text-success' : 'text-danger'}>
          {balanced ? t('balanced') : t('outOfBalance', { diff: Math.abs(totalDebit - totalCredit).toFixed(4) })}
        </span>
      </div>

      <div className="flex justify-end gap-2">
        <Button type="submit" variant="secondary" disabled={!canCreate || create.isPending}>
          {t('saveDraft')}
        </Button>
        <Button
          type="button"
          disabled={!balanced || (create.isPending || post.isPending)}
          onClick={form.handleSubmit((v) => onSave(v, 'post'))}
        >
          {canPostDirect ? t('post') : t('submitForApproval')}
        </Button>
      </div>
    </form>
  );
}
```

**Usage**

```tsx
// app/(app)/accounting/journal-entries/new/page.tsx
export default function NewJournalEntryPage() {
  const router = useRouter();
  return (
    <JournalEntryForm
      mode="create"
      onSubmitted={(entry) => router.push(`/accounting/journal-entries/${entry.id}`)}
    />
  );
}
```

The `saveDraft`/`post` button pair directly encodes the permission split in `JOURNAL_ENTRIES.md → Approval
Workflow`: a user holding only `accounting.journal.create` can always save a draft, but the second button's
label and behavior switch on `accounting.journal.post` — holders post immediately (subject to the server's
own re-check of the amount threshold), everyone else's click calls `POST .../submit` instead and the button
is labeled accordingly, never silently disabled without explanation.

## ApprovalCard

A single reusable approval affordance shared across journal entries, AI recommendations, bank transfers,
payroll releases, and tax submissions — the "human gate" every sensitive action in QAYD must pass through
per the platform's non-negotiable rule. Rather than one-off approve/reject buttons on five different
screens, every sensitive action renders one `ApprovalCard` with a `kind` discriminant.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `kind` | `'journal_entry' \| 'ai_recommendation' \| 'bank_transfer' \| 'payroll_release' \| 'tax_submission'` | yes | Determines the permission key checked, the icon, and the detail link target. |
| `title` | `string` | yes | e.g. `"JE-2026-07-0482 · Rent accrual"`. |
| `amount` | `{ value: string; currencyCode: string }` | no | Rendered via `AmountCell`. |
| `requestedBy` | `{ name: string; avatarUrl?: string }` | no | Omitted for pure AI-originated proposals; use `AIProposalPanel`'s own attribution instead. |
| `requestedAt` | `string` (ISO 8601) | yes | |
| `approvalLevel` | `{ current: number; total: number }` | no | Renders "Approval 1 of 2" progress when > 1. |
| `confidence` | `number \| null` | no | 0–1. Passed straight to `ConfidenceBadge` when the source is AI-originated. |
| `detailHref` | `string` | yes | Link to the full record. |
| `onApprove` | `(note?: string) => Promise<void>` | yes | |
| `onReject` | `(reason: string) => Promise<void>` | yes | Reason is mandatory — the dialog will not submit an empty reason (platform-wide rule: every rejection/dismissal carries a reason, stored against the decision row). |
| `onDelegate` | `(userId: number) => Promise<void>` | no | Only rendered when the caller is the assigned approver for the current level. |

**Implementation (excerpt — approve/reject affordance)**

```tsx
// components/shared/approval-card.tsx
'use client';

import { useState } from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
         AlertDialogFooter, AlertDialogCancel, AlertDialogAction } from '@/components/ui/alert-dialog';
import { Textarea } from '@/components/ui/textarea';
import { AmountCell } from '@/components/accounting/amount-cell';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { usePermission } from '@/hooks/use-permission';
import { Check, X, UserPlus } from 'lucide-react';

const PERMISSION_BY_KIND: Record<ApprovalKind, string> = {
  journal_entry: 'accounting.journal.approve',
  ai_recommendation: 'ai.approve',
  bank_transfer: 'bank.transfer',
  payroll_release: 'payroll.approve',
  tax_submission: 'tax.submit',
};

export function ApprovalCard(props: ApprovalCardProps) {
  const { kind, title, amount, requestedBy, requestedAt, approvalLevel, confidence, detailHref,
          onApprove, onReject, onDelegate } = props;
  const canDecide = usePermission(PERMISSION_BY_KIND[kind]);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  return (
    <Card padding="md" className="space-y-3">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <a href={detailHref} className="font-medium text-ink-950 hover:text-accent-600 truncate block">{title}</a>
          <p className="text-caption text-ink-500">
            {requestedBy?.name ?? 'AI proposal'} · <FormattedRelativeTime value={requestedAt} />
            {approvalLevel && approvalLevel.total > 1 && ` · Approval ${approvalLevel.current} of ${approvalLevel.total}`}
          </p>
        </div>
        {confidence != null && <ConfidenceBadge confidence={confidence} />}
      </div>

      {amount && <AmountCell amount={amount.value} currencyCode={amount.currencyCode} emphasis="strong" showCurrency />}

      <div className="flex justify-end gap-2">
        {onDelegate && (
          <Button variant="ghost" size="sm" onClick={() => setDelegateOpen(true)}>
            <UserPlus className="h-3.5 w-3.5" /> Delegate
          </Button>
        )}
        <Button
          variant="destructive-quiet" size="sm" disabled={!canDecide}
          onClick={() => setRejectOpen(true)}
        >
          <X className="h-3.5 w-3.5" /> Reject
        </Button>
        <Button
          size="sm" disabled={!canDecide || busy}
          onClick={async () => { setBusy(true); await onApprove(); setBusy(false); }}
        >
          <Check className="h-3.5 w-3.5" /> Approve
        </Button>
      </div>

      <AlertDialog open={rejectOpen} onOpenChange={setRejectOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Reject {title}?</AlertDialogTitle>
          </AlertDialogHeader>
          <Textarea
            placeholder="Reason (required)…"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            aria-label="Rejection reason"
          />
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              disabled={reason.trim().length === 0}
              onClick={async () => { await onReject(reason); setRejectOpen(false); }}
            >
              Confirm reject
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  );
}
```

**Usage**

```tsx
<ApprovalCard
  kind="journal_entry"
  title={`${entry.journal_number} · ${entry.memo}`}
  amount={{ value: entry.total_debit, currencyCode: entry.currency_code }}
  requestedBy={{ name: entry.created_by_name }}
  requestedAt={entry.created_at}
  approvalLevel={{ current: entry.current_approval_level, total: entry.required_approval_levels }}
  detailHref={`/accounting/journal-entries/${entry.id}`}
  onApprove={() => approveMutation.mutateAsync(entry.id)}
  onReject={(reason) => rejectMutation.mutateAsync({ id: entry.id, reason })}
/>
```

## ConfidenceBadge

Renders an AI confidence score as a small, restrained badge — never a traffic-light red/yellow/green
indicator (that reads as alarm-driven and contradicts the platform's calm, non-alarming finance tone), and
never a bare, unexplained number. `ConfidenceBadge` always accepts a `Tooltip` slot for the underlying
`reasoning` text so a user can see *why* a number is what it is, matching the platform rule that "no card
shows a bare number the AI computed without showing its work."

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `confidence` | `number` | yes | Canonical range **0–1**. See Edge Cases — the API is not uniform (`journal_lines.ai_confidence` and `accounts.ai_suggestion_confidence` are `0.0000–1.0000`; `ai_decisions.confidence_score` is `0–100`). Callers must pass through `normalizeConfidence()` below rather than assume the raw field's scale. |
| `size` | `'sm' \| 'default'` | no | |
| `showLabel` | `boolean` | no, default `true` | Renders `"High confidence"` / `"Medium confidence"` / `"Low confidence"` next to the percentage. |
| `reasoning` | `string` | no | Shown in a `Tooltip` on hover/focus. |

**Implementation**

```tsx
// components/ai/confidence-badge.tsx
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/** Normalizes QAYD's two live confidence scales onto a single 0–1 range.
 *  `journal_lines.ai_confidence`, `accounts.ai_suggestion_confidence` → already 0–1.
 *  `ai_decisions.confidence_score` (AI Command Center) → 0–100, needs /100. */
export function normalizeConfidence(raw: number, sourceField: 'fraction' | 'percentage'): number {
  return sourceField === 'percentage' ? raw / 100 : raw;
}

function band(confidence: number): { label: string; tone: 'accent' | 'neutral' } {
  if (confidence >= 0.85) return { label: 'High confidence', tone: 'accent' };
  if (confidence >= 0.6) return { label: 'Medium confidence', tone: 'neutral' };
  return { label: 'Low confidence', tone: 'neutral' };
}

interface ConfidenceBadgeProps {
  confidence: number;
  size?: 'sm' | 'default';
  showLabel?: boolean;
  reasoning?: string;
}

export function ConfidenceBadge({ confidence, size = 'default', showLabel = true, reasoning }: ConfidenceBadgeProps) {
  const { label, tone } = band(confidence);
  const pct = Math.round(confidence * 100);

  const badge = (
    <Badge tone={tone} className={cn(size === 'sm' && 'px-2 py-0 text-[11px]')}>
      <span className="font-mono tabular-nums" dir="ltr">{pct}%</span>
      {showLabel && <span>{label}</span>}
    </Badge>
  );

  if (!reasoning) return badge;

  return (
    <Tooltip>
      <TooltipTrigger asChild><button type="button" className="cursor-help">{badge}</button></TooltipTrigger>
      <TooltipContent className="max-w-xs text-start">{reasoning}</TooltipContent>
    </Tooltip>
  );
}
```

**Usage**

```tsx
<ConfidenceBadge
  confidence={normalizeConfidence(decision.confidence_score, 'percentage')}
  reasoning={decision.reasoning}
/>
```

Below 60% confidence, `AIProposalPanel` (next) additionally disables its one-click "Do it" action regardless
of the item's configured autonomy level — a low-confidence output may still be *shown*, but it can never be
the thing that executes itself.

## AIProposalPanel

The full rendering of one `ai_decisions` row from the AI Command Center's Recommendations feed — the
action-shaped counterpart to a plain insight. Every panel carries a recommended action, at least one
alternative with its tradeoff (per `AI_COMMAND_CENTER.md → AI Recommendations`: "every one ships with at
least one named alternative... QAYD does not want users to develop reflexive trust in a single accept
button"), a confidence score, and exactly three possible interactions.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `decision` | `AIDecision` | yes | `{ id, agentCode, confidenceScore, recommendedAction, alternatives: {action, tradeoff}[], reasoning, sources, autonomyLevel }`. |
| `autonomyLevel` | `'auto' \| 'suggest_only' \| 'requires_approval'` | yes | Determines whether "Do it" renders at all. |
| `onAccept` | `() => Promise<void>` | yes | Only callable when `autonomyLevel === 'auto'` **and** `decision.confidenceScore` clears the company's configured threshold. |
| `onSendForApproval` | `() => Promise<void>` | yes | Opens the target action's `ApprovalCard` flow pre-attached to this decision. |
| `onDismiss` | `(reason: string) => Promise<void>` | yes | Reason required, stored as `rejected_reason` and fed back into `ai_memory`. |

**Implementation**

```tsx
// components/ai/ai-proposal-panel.tsx
'use client';

import { useState } from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { AgentAvatar } from '@/components/ai/agent-avatar';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { ChevronDown } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

const AUTO_EXECUTE_THRESHOLD = 0.9; // mirrors the company-configured server-side threshold; UI-side gate only

export function AIProposalPanel({ decision, autonomyLevel, onAccept, onSendForApproval, onDismiss }: AIProposalPanelProps) {
  const [expanded, setExpanded] = useState(false);
  const [dismissOpen, setDismissOpen] = useState(false);
  const [dismissReason, setDismissReason] = useState('');
  const confidence = normalizeConfidence(decision.confidenceScore, 'percentage');
  const canAutoExecute = autonomyLevel === 'auto' && confidence >= AUTO_EXECUTE_THRESHOLD;

  return (
    <Card padding="md" className="space-y-3">
      <div className="flex items-start gap-3">
        <AgentAvatar agentCode={decision.agentCode} />
        <div className="min-w-0 flex-1">
          <p className="text-body text-ink-950">{decision.recommendedAction}</p>
          <ConfidenceBadge confidence={confidence} size="sm" reasoning={decision.reasoning} />
        </div>
      </div>

      <button
        type="button"
        onClick={() => setExpanded((v) => !v)}
        className="flex items-center gap-1 text-caption text-ink-500 hover:text-ink-950"
        aria-expanded={expanded}
      >
        <motion.span animate={{ rotate: expanded ? 180 : 0 }} transition={{ duration: 0.15 }}>
          <ChevronDown className="h-3.5 w-3.5" />
        </motion.span>
        {expanded ? 'Hide alternatives' : `${decision.alternatives.length} alternative(s)`}
      </button>

      <AnimatePresence initial={false}>
        {expanded && (
          <motion.ul
            initial={{ height: 0, opacity: 0 }} animate={{ height: 'auto', opacity: 1 }} exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.2 }} className="overflow-hidden space-y-2"
          >
            {decision.alternatives.map((alt, i) => (
              <li key={i} className="rounded-md bg-ink-100 p-2.5 text-caption">
                <p className="font-medium text-ink-950">{alt.action}</p>
                <p className="text-ink-500">{alt.tradeoff}</p>
              </li>
            ))}
          </motion.ul>
        )}
      </AnimatePresence>

      <div className="flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={() => setDismissOpen(true)}>Dismiss</Button>
        <Button variant="outline" size="sm" onClick={onSendForApproval}>Send for approval</Button>
        {autonomyLevel === 'auto' && (
          <Button size="sm" disabled={!canAutoExecute} onClick={onAccept}>
            Do it
          </Button>
        )}
      </div>

      <Dialog open={dismissOpen} onOpenChange={setDismissOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Dismiss this recommendation</DialogTitle></DialogHeader>
          <Textarea placeholder="Why isn't this useful? (required)" value={dismissReason}
                     onChange={(e) => setDismissReason(e.target.value)} />
          <DialogFooter>
            <Button
              disabled={dismissReason.trim().length === 0}
              onClick={async () => { await onDismiss(dismissReason); setDismissOpen(false); }}
            >
              Confirm dismiss
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
```

**Usage**

```tsx
<AIProposalPanel
  decision={{
    id: 991702, agentCode: 'GENERAL_ACCOUNTANT', confidenceScore: 96.5,
    recommendedAction: 'Auto-categorize 214 pending bank transactions matching known merchant patterns at ≥95% confidence.',
    alternatives: [{ action: 'Review each transaction manually', tradeoff: 'Slower, but catches the ~1% edge cases the pattern match would miss.' }],
    reasoning: 'These 214 transactions match a merchant + amount pattern seen ≥20 times in the last 90 days with a consistent category assignment.',
    sources: [{ type: 'bank_transactions', count: 214 }],
  }}
  autonomyLevel="auto"
  onAccept={() => acceptMutation.mutateAsync(991702)}
  onSendForApproval={() => openApprovalDrawer(991702)}
  onDismiss={(reason) => dismissMutation.mutateAsync({ id: 991702, reason })}
/>
```

## KpiTile

A single dashboard stat — Cash Position, Revenue MTD, Gross Margin — the atomic unit of the "What
happened?" answer the platform's dashboard philosophy commits to. Every `KpiTile` on a screen backed by AI
computation (as opposed to a raw SQL aggregate) carries its own small `ConfidenceBadge`, because the
platform rule ("no card shows a bare number the AI computed without showing its work") applies exactly as
much to a dashboard tile as to a recommendation card.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `label` | `string` | yes | e.g. `"Cash position"`. |
| `value` | `string` | yes | Pre-formatted or a raw `NUMERIC` string, per `format`. |
| `format` | `'currency' \| 'number' \| 'percent'` | yes | |
| `currencyCode` | `string` | conditional | Required when `format="currency"`. |
| `delta` | `{ value: number; direction: 'up' \| 'down'; period: string }` | no | e.g. `{ value: 13.3, direction: 'up', period: 'vs last month' }`. |
| `sparklineData` | `number[]` | no | Rendered via `TrendSparkline`. |
| `confidence` | `number \| null` | no | |
| `loading` | `boolean` | no | Renders a `Skeleton` in place of `value`/`delta`. |
| `onClick` | `() => void` | no | Drill-in, e.g. into the filtered ledger view behind the number. |

**Implementation**

```tsx
// components/dashboard/kpi-tile.tsx
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { AmountCell, formatAmount } from '@/components/accounting/amount-cell';
import { TrendSparkline } from '@/components/dashboard/trend-sparkline';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { ArrowUpRight, ArrowDownRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export function KpiTile({ label, value, format, currencyCode, delta, sparklineData, confidence, loading, onClick }: KpiTileProps) {
  return (
    <Card
      padding="md"
      interactive={Boolean(onClick)}
      onClick={onClick}
      className="space-y-2"
    >
      <div className="flex items-center justify-between">
        <p className="text-caption font-medium text-ink-500">{label}</p>
        {confidence != null && <ConfidenceBadge confidence={confidence} size="sm" showLabel={false} />}
      </div>

      {loading ? (
        <Skeleton className="h-8 w-32" />
      ) : (
        <p className="text-heading font-display text-ink-950 tabular-nums" dir="ltr">
          {format === 'currency' ? formatAmount(value, currencyCode!) : value}
          {format === 'percent' && '%'}
        </p>
      )}

      <div className="flex items-center justify-between">
        {delta && !loading && (
          <span className={cn('inline-flex items-center gap-0.5 text-caption', delta.direction === 'up' ? 'text-success' : 'text-danger')}>
            {delta.direction === 'up' ? <ArrowUpRight className="h-3.5 w-3.5" /> : <ArrowDownRight className="h-3.5 w-3.5" />}
            <span dir="ltr">{delta.value}%</span> <span className="text-ink-500">{delta.period}</span>
          </span>
        )}
        {sparklineData && <TrendSparkline data={sparklineData} height={28} ariaLabel={`${label} trend`} />}
      </div>
    </Card>
  );
}
```

**Usage**

```tsx
<KpiTile
  label="Cash position"
  value={kpi.cash_position.base_amount}
  format="currency"
  currencyCode="KWD"
  delta={{ value: 13.3, direction: 'up', period: 'vs last month' }}
  sparklineData={kpi.cash_position.trend_30d}
  confidence={normalizeConfidence(kpi.cash_position.confidence_score, 'percentage')}
  onClick={() => router.push('/accounting/cash-flow')}
/>
```

## TrendSparkline

A small inline chart — no axes, no gridlines, no legend — used inside `KpiTile` and `DataTable` cells to
show shape-of-trend at a glance. Deliberately not built on a full charting library: a 28–40px-tall sparkline
is a handful of SVG path commands, and pulling in a heavyweight chart dependency for it would work against
the platform's performance budget for a decoration-adjacent element.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `data` | `number[]` | yes | At least 2 points. |
| `height` | `number` | no, default `32` | Pixels. |
| `color` | `'accent' \| 'success' \| 'danger' \| 'ink'` | no, default `'accent'` | |
| `showTooltip` | `boolean` | no | Hover tooltip showing the nearest point's value; off by default to keep the element purely decorative-informational. |
| `ariaLabel` | `string` | yes | Sparklines are visual-only by nature — this is the screen-reader's entire understanding of the element (see Accessibility). |

**Implementation**

```tsx
// components/dashboard/trend-sparkline.tsx
'use client';

import { useId, useMemo } from 'react';
import { motion } from 'framer-motion';
import { useReducedMotion } from '@/hooks/use-reduced-motion';

const COLOR_VAR: Record<string, string> = {
  accent: 'var(--qayd-accent-600)', success: 'var(--qayd-success-600)',
  danger: 'var(--qayd-danger-600)', ink: 'var(--qayd-ink-700)',
};

export function TrendSparkline({ data, height = 32, color = 'accent', showTooltip = false, ariaLabel }: TrendSparklineProps) {
  const id = useId();
  const reducedMotion = useReducedMotion();
  const width = 96;

  const path = useMemo(() => {
    const min = Math.min(...data), max = Math.max(...data);
    const range = max - min || 1;
    const step = width / (data.length - 1);
    return data
      .map((v, i) => `${i === 0 ? 'M' : 'L'} ${(i * step).toFixed(2)} ${(height - ((v - min) / range) * height).toFixed(2)}`)
      .join(' ');
  }, [data, height]);

  return (
    <svg
      role="img" aria-label={ariaLabel}
      width={width} height={height} viewBox={`0 0 ${width} ${height}`}
      className="overflow-visible"
    >
      <motion.path
        d={path} fill="none" stroke={COLOR_VAR[color]} strokeWidth={1.5}
        strokeLinecap="round" strokeLinejoin="round"
        initial={reducedMotion ? false : { pathLength: 0 }}
        animate={reducedMotion ? undefined : { pathLength: 1 }}
        transition={{ duration: 0.6, ease: 'easeOut' }}
      />
    </svg>
  );
}
```

**Usage**

```tsx
<TrendSparkline data={[820, 795, 860, 910, 880, 940, 1005]} color="accent" ariaLabel="Cash position, last 7 days" />
```

## StatusPill

The canonical status badge for every stateful QAYD record — journal entries, invoices, bills, payroll runs,
tax returns. One component, one lookup table per domain, so "posted" never renders in a different shade on
two different screens. Deliberately **not** color-only: every pill pairs a small leading dot with a text
label, so the same information survives grayscale printing and colorblind vision (`docs/foundation/
DESIGN_SYSTEM.md → Accessibility → Color Blind Support`).

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `status` | `string` | yes | The raw enum value, e.g. `'pending_approval'`. |
| `domain` | `'journal_entry' \| 'invoice' \| 'bill' \| 'payroll_run' \| 'tax_return' \| 'bank_transfer'` | yes | Selects the label/tone lookup table. |
| `size` | `'sm' \| 'default'` | no | |

**Implementation**

```tsx
// components/shared/status-pill.tsx
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger';

const JOURNAL_ENTRY_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:            { label: 'Draft', tone: 'neutral' },
  pending_approval: { label: 'Pending approval', tone: 'warning' },
  approved:         { label: 'Approved', tone: 'accent' },
  rejected:         { label: 'Rejected', tone: 'danger' },
  posted:           { label: 'Posted', tone: 'success' },
  reversed:         { label: 'Reversed', tone: 'neutral' },
  voided:           { label: 'Voided', tone: 'danger' },
  archived:         { label: 'Archived', tone: 'neutral' },
};

const STATUS_TABLES: Record<string, Record<string, { label: string; tone: Tone }>> = {
  journal_entry: JOURNAL_ENTRY_STATUS,
  // invoice, bill, payroll_run, tax_return, bank_transfer tables follow the identical shape,
  // each owned by its module's screen spec and imported here rather than redefined.
};

export function StatusPill({ status, domain, size = 'default' }: StatusPillProps) {
  const entry = STATUS_TABLES[domain]?.[status] ?? { label: status, tone: 'neutral' as Tone };
  return (
    <Badge tone={entry.tone} className={cn(size === 'sm' && 'px-2 py-0 text-[11px]')}>
      <span
        className={cn('h-1.5 w-1.5 rounded-full', {
          neutral: 'bg-ink-500', accent: 'bg-accent-600', success: 'bg-success',
          warning: 'bg-warning', danger: 'bg-danger',
        }[entry.tone])}
        aria-hidden
      />
      {entry.label}
    </Badge>
  );
}
```

**Usage**

```tsx
<StatusPill domain="journal_entry" status={entry.status} />
```

## PeriodPicker

A combined fiscal-period / date-range selector aware of the company's actual `fiscal_years` /
`fiscal_periods` calendar (not a generic date-range widget) and of the platform's named relative-window
shortcuts (`docs/api/API_FILTERING_SORTING.md → Date & Amount Range Filters`).

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `mode` | `'fiscal_period' \| 'date_range' \| 'relative'` | yes | `'fiscal_period'` lets the user pick an exact `fiscal_periods` row (e.g. "July 2026"); `'date_range'` is a free `from`/`to` calendar; `'relative'` is the named-shortcut list. |
| `value` | `PeriodValue` | yes | Discriminated union matching `mode`. |
| `onChange` | `(value: PeriodValue) => void` | yes | |
| `allowFuturePeriods` | `boolean` | no, default `false` | Only relevant to `'fiscal_period'` mode; future periods are shown grayed unless explicitly enabled (e.g. for a budget screen). |

**Implementation (excerpt)**

```tsx
// components/accounting/period-picker.tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem, SelectGroup, SelectLabel }
  from '@/components/ui/select';

const RELATIVE_RANGES = [
  { value: 'today', label: 'Today' },
  { value: 'this_week', label: 'This week' },
  { value: 'this_month', label: 'This month' },
  { value: 'this_fiscal_period', label: 'This fiscal period' },
  { value: 'this_fiscal_year', label: 'This fiscal year' },
  { value: 'last_30_days', label: 'Last 30 days' },
  { value: 'last_quarter', label: 'Last quarter' },
] as const;

export function PeriodPicker({ mode, value, onChange, allowFuturePeriods = false }: PeriodPickerProps) {
  if (mode === 'relative') {
    return (
      <Select value={value.range} onValueChange={(range) => onChange({ mode: 'relative', range })}>
        <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
        <SelectContent>
          {RELATIVE_RANGES.map((r) => <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>)}
        </SelectContent>
      </Select>
    );
  }

  if (mode === 'fiscal_period') {
    const { data } = useQuery({
      queryKey: ['fiscal-periods', { allowFuturePeriods }],
      queryFn: () => apiClient.get<{ data: FiscalPeriod[] }>('/api/v1/accounting/fiscal-periods', {
        params: { sort: '-start_date', 'filter[status]': allowFuturePeriods ? undefined : { not_in: 'future' } },
      }),
    });
    return (
      <Select
        value={String(value.fiscalPeriodId)}
        onValueChange={(id) => onChange({ mode: 'fiscal_period', fiscalPeriodId: Number(id) })}
      >
        <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
        <SelectContent>
          <SelectGroup>
            <SelectLabel>Open</SelectLabel>
            {data?.data.filter((p) => p.status === 'open').map((p) => (
              <SelectItem key={p.id} value={String(p.id)}>{p.name_en}</SelectItem>
            ))}
          </SelectGroup>
          <SelectGroup>
            <SelectLabel>Locked / closed</SelectLabel>
            {data?.data.filter((p) => p.status !== 'open').map((p) => (
              <SelectItem key={p.id} value={String(p.id)} className="text-ink-500">{p.name_en}</SelectItem>
            ))}
          </SelectGroup>
        </SelectContent>
      </Select>
    );
  }

  return <DateRangeCalendar value={value} onChange={onChange} />; // 'date_range' mode
}
```

**Usage**

```tsx
<PeriodPicker mode="relative" value={{ mode: 'relative', range: 'this_fiscal_period' }} onChange={setPeriod} />
```

## CurrencyTag

A compact ISO 4217 currency indicator — text-only by design (no flag emoji or flag icon: the platform bans
emoji-decorated UI outright, and a flag icon implies a country, not a currency, which is actively misleading
for KWD/AED/SAR shown side by side in a Gulf multi-currency context).

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `code` | `string` | yes | ISO 4217, e.g. `'KWD'`. |
| `variant` | `'code' \| 'name'` | no, default `'code'` | `'code'` renders `"KWD"`; `'name'` renders the localized currency name (`"Kuwaiti Dinar"` / `"دينار كويتي"`). |
| `emphasis` | `'default' \| 'muted'` | no | `'muted'` for a secondary/base-currency annotation next to a transaction-currency amount. |

**Implementation**

```tsx
// components/accounting/currency-tag.tsx
import { useLocale, useTranslations } from 'next-intl';
import { cn } from '@/lib/utils';

export function CurrencyTag({ code, variant = 'code', emphasis = 'default' }: CurrencyTagProps) {
  const locale = useLocale();
  const t = useTranslations('currencies');
  const label = variant === 'code' ? code : t(code); // t('KWD') -> "Kuwaiti Dinar" / "دينار كويتي"

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-sm border border-ink-150 px-1.5 py-0.5 text-[11px] font-mono tracking-wide',
        emphasis === 'muted' ? 'text-ink-500 border-ink-150/60' : 'text-ink-700',
      )}
      dir="ltr" // currency codes are always Latin-script tokens, even inside Arabic text
    >
      {label}
    </span>
  );
}
```

**Usage**

```tsx
<AmountCell amount={invoice.total_amount} currencyCode={invoice.currency_code} />
<CurrencyTag code={invoice.currency_code} emphasis="muted" />
```

## AgingBar

An AR/AP aging visualization across the platform's fixed bucket set (`0-30`, `31-60`, `61-90`, `90+`),
matching the derived `aging_bucket` filter enum documented in `docs/api/API_FILTERING_SORTING.md → Date &
Amount Range Filters` — the bucket boundaries are computed server-side against "today," never recomputed
from raw dates on the client, so the bar's segments and the underlying drill-in filter can never disagree.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `buckets` | `{ bucket: '0-30' \| '31-60' \| '61-90' \| '90+'; amount: string; count: number }[]` | yes | Directly from the aging endpoint response. |
| `currencyCode` | `string` | yes | |
| `total` | `string` | yes | Sum, for the percentage-width calculation and the header total. |
| `onBucketClick` | `(bucket: string) => void` | no | Navigates to the matching `filter[aging_bucket][in]=...` list view. |

**Implementation**

```tsx
// components/accounting/aging-bar.tsx
import { AmountCell, formatAmount } from '@/components/accounting/amount-cell';
import { cn } from '@/lib/utils';

const BUCKET_STYLE: Record<string, string> = {
  '0-30':  'bg-ink-300',
  '31-60': 'bg-warning/50',
  '61-90': 'bg-warning',
  '90+':   'bg-danger',
};

export function AgingBar({ buckets, currencyCode, total, onBucketClick }: AgingBarProps) {
  const totalValue = Number(total) || 1;

  return (
    <div className="space-y-2">
      <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-ink-100" role="img"
           aria-label={`Aging summary, total ${formatAmount(total, currencyCode)} ${currencyCode}`}>
        {buckets.map((b) => (
          <button
            key={b.bucket}
            type="button"
            style={{ width: `${(Number(b.amount) / totalValue) * 100}%` }}
            className={cn(BUCKET_STYLE[b.bucket], 'h-full transition-opacity hover:opacity-80 focus-visible:opacity-80')}
            onClick={() => onBucketClick?.(b.bucket)}
            title={`${b.bucket} days · ${formatAmount(b.amount, currencyCode)} ${currencyCode} · ${b.count} invoices`}
          />
        ))}
      </div>
      <div className="grid grid-cols-4 gap-2 text-caption">
        {buckets.map((b) => (
          <button key={b.bucket} type="button" className="text-start" onClick={() => onBucketClick?.(b.bucket)}>
            <span className={cn('inline-block h-2 w-2 rounded-full me-1', BUCKET_STYLE[b.bucket])} aria-hidden />
            <span className="text-ink-500">{b.bucket}d</span>
            <AmountCell amount={b.amount} currencyCode={currencyCode} align="start" />
          </button>
        ))}
      </div>
    </div>
  );
}
```

**Usage**

```tsx
<AgingBar
  buckets={agingData.buckets}
  currencyCode="KWD"
  total={agingData.total}
  onBucketClick={(bucket) => router.push(`/sales/invoices?filter[aging_bucket][in]=${bucket}`)}
/>
```

# Composition Patterns

Finance components are designed to combine into whole screens without any screen-level component
reinventing behavior the library already owns. Three worked compositions:

## Pattern 1 — a list screen (Journal Entries index)

```tsx
// app/(app)/accounting/journal-entries/page.tsx
export default function JournalEntriesPage() {
  const [period, setPeriod] = useState<PeriodValue>({ mode: 'relative', range: 'this_fiscal_period' });
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-heading font-display">Journal entries</h1>
        <div className="flex items-center gap-2">
          <PeriodPicker mode="relative" value={period} onChange={setPeriod} />
          <PermissionGate permission="accounting.journal.create">
            <Button asChild><Link href="/accounting/journal-entries/new">New entry</Link></Button>
          </PermissionGate>
        </div>
      </div>
      <DataTable columns={journalEntryColumns} resource="accounting/journal-entries"
                 paginationMode="page" searchable defaultFilters={rangeToFilter(period)}
                 getRowId={(r) => String(r.id)} />
    </div>
  );
}
```

`DataTable` owns pagination/sort/filter state and network calls; `PeriodPicker` only produces a
`filter[entry_date]` shape that `DataTable` merges in — neither component knows the other exists beyond that
shared filter object, which is what keeps both independently testable and reusable on a screen that has no
`PeriodPicker` at all (e.g. `Accounts`).

## Pattern 2 — a create-then-approve flow (Journal Entry lifecycle end to end)

```tsx
// app/(app)/accounting/journal-entries/[id]/page.tsx
export default function JournalEntryDetailPage({ params }: { params: { id: string } }) {
  const { data: entry } = useJournalEntryQuery(Number(params.id));
  if (!entry) return <PageSkeleton />;

  if (entry.status === 'draft' || entry.status === 'rejected') {
    return <JournalEntryForm mode="edit" journalEntryId={entry.id} defaultValues={entry} />;
  }

  return (
    <div className="space-y-4">
      <JournalEntryHeader entry={entry} />
      {entry.status === 'pending_approval' && (
        <ApprovalCard
          kind="journal_entry"
          title={`${entry.journal_number} · ${entry.memo}`}
          amount={{ value: entry.total_debit, currencyCode: entry.currency_code }}
          requestedBy={{ name: entry.created_by_name }}
          requestedAt={entry.created_at}
          approvalLevel={{ current: entry.current_approval_level, total: entry.required_approval_levels }}
          detailHref={`/accounting/journal-entries/${entry.id}`}
          onApprove={() => approveMutation.mutateAsync(entry.id)}
          onReject={(reason) => rejectMutation.mutateAsync({ id: entry.id, reason })}
        />
      )}
      <JournalLinesTable entry={entry} />
    </div>
  );
}
```

The same page component renders three entirely different states — editable form, pending approval, posted
read view — purely by branching on `entry.status`, never by the client inferring what *should* be editable
from anything other than the server-supplied status enum (per the Locking Rules in `JOURNAL_ENTRIES.md`).

## Pattern 3 — a dashboard grid (AI Command Center home)

```tsx
// app/(app)/dashboard/page.tsx
export default function DashboardPage() {
  const { data: kpis } = useDashboardKpisQuery();
  const { data: recommendations } = useAIRecommendationsQuery();

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <section className="grid grid-cols-2 gap-4 lg:col-span-2 lg:grid-cols-3">
        {kpis?.map((kpi) => <KpiTile key={kpi.key} {...kpi} />)}
      </section>
      <section className="space-y-3">
        <h2 className="text-title font-display">Recommendations</h2>
        {recommendations?.map((d) => (
          <AIProposalPanel key={d.id} decision={d} autonomyLevel={d.autonomyLevel}
                            onAccept={() => accept(d.id)} onSendForApproval={() => sendForApproval(d.id)}
                            onDismiss={(reason) => dismiss(d.id, reason)} />
        ))}
      </section>
    </div>
  );
}
```

Generous whitespace and a restrained 1–3-column grid — never a dense, gapless bento layout — is the
deliberate reading of the founder's editorial-calm mandate onto a dashboard that is, structurally, the
single highest-density screen in the product.

# Accessibility per component

Every finance component inherits its base Radix primitive's accessibility contract; the table below only
lists what each component adds or must not break.

| Component | Keyboard | Screen reader | Notes |
|---|---|---|---|
| `DataTable` | `Tab` moves between header cells, search box, and row actions; sortable headers are focusable buttons (not bare `<th onClick>`) activated by `Enter`/`Space` | Sortable headers expose `aria-sort`; loading state sets `aria-busy="true"` on the table; empty state is a landmark region, not a silent blank table | Row `onClick` is always paired with a keyboard-reachable action (a `DropdownMenu` item or a focusable link in the first cell) — a table row is never the *only* way to open a record |
| `AmountCell` | — (not interactive) | Reads as plain text; sign glyph (`+`/`−`) is real text, not a background image, so it is never dropped by a screen reader | Color is secondary to the sign glyph, satisfying colorblind users without extra markup |
| `AccountPicker` | Full `Combobox` pattern: `↓`/`↑` navigate options, `Enter` selects, `Esc` closes | `aria-expanded`, `aria-activedescendant` wired by Radix `Command`; each option announces code + name | Debounced search announces result count changes via a visually-hidden `aria-live="polite"` region |
| `JournalEntryForm` | Full tab order through header fields, then line grid, then footer buttons; `Add line` is reachable without leaving the grid | Field-level errors are linked via `aria-describedby`; the balance indicator's live state change is announced through `aria-live="polite"` on the balance banner | The **Post**/**Submit** button's disabled state always has a reason available to assistive tech via `aria-describedby` pointing at the balance banner — never a silently-disabled button |
| `ApprovalCard` | Approve/Reject/Delegate are ordinary focusable buttons; rejection dialog traps focus (Radix `AlertDialog`) | Approval-level progress ("Approval 1 of 2") is real text, not conveyed by a progress bar's color alone | Reject action's `Confirm reject` button stays disabled (and announces why) until the reason field is non-empty |
| `ConfidenceBadge` | Tooltip trigger is a real `<button>`, reachable and dismissible via `Esc` | Percentage and label are both real text; the tooltip's `reasoning` is available on focus, not hover-only | |
| `AIProposalPanel` | Expand/collapse alternatives is a real button with `aria-expanded`; `Do it` is properly `disabled` (not merely styled) below the confidence threshold | Alternatives list is a real `<ul>`; expand/collapse announces state change | Framer Motion height animation respects `prefers-reduced-motion` (collapses/expands instantly instead) |
| `KpiTile` | Focusable only when `onClick` is provided; otherwise a static, non-tabbable `<div>` region | Delta direction is announced as text ("up 13.3% vs last month"), not only via arrow icon color | |
| `TrendSparkline` | Not focusable (decorative-informational) | `role="img"` + mandatory `aria-label` carries the entire meaning — never `aria-hidden` on a sparkline that isn't purely decorative next to an already-labeled number | If a `TrendSparkline` sits beside a `KpiTile` delta that already states the trend in text, the sparkline *may* be `aria-hidden="true"` to avoid redundant announcements — this is the one documented exception, and it only applies when the accompanying text is present |
| `StatusPill` | — (not interactive) | Dot is `aria-hidden`; the text label is the accessible name | |
| `PeriodPicker` | Native `Select`/`Command` keyboard behavior per mode | Grouped by "Open" / "Locked / closed" via `SelectLabel`, read by screen readers as group headers | Locked/closed periods remain visible (not removed) so a screen-reader user understands *why* a period is unavailable rather than finding a shorter, unexplained list |
| `CurrencyTag` | — (not interactive) | `dir="ltr"` on the tag does not affect its reading order in Arabic screen readers, which already announce Latin tokens correctly inline | |
| `AgingBar` | Each bucket segment is a real `<button>`, not a `<div>` with a click handler | The bar itself carries `role="img"` with a full-summary `aria-label`; per-bucket buttons additionally expose their own amount and count as visible + accessible text, never color-coded severity alone | |

# Theming & RTL

**Light/dark.** Every token in `# Foundations → Design tokens` is redefined once under
`:root[data-theme="dark"]`; no component file contains a `dark:` Tailwind variant referencing a raw color —
`dark:bg-neutral-900` is banned in the same way a raw hex value is. The `data-theme` attribute is set on
`<html>` by a small `ThemeProvider` that resolves `system | light | dark` from `localStorage` and
`prefers-color-scheme`, matching the platform's "supported from day one, every component in light and dark"
rule.

**RTL.** `dir="rtl"` is set on `<html>` by the root layout based on the active locale (`ar` → `rtl`, all
others → `ltr`), never toggled per-component. Every finance component follows three fixed rules:

1. **Logical CSS properties only.** `ms-*`/`me-*` (`margin-inline-start/end`), `ps-*`/`pe-*`, `text-start`/
   `text-end`, and `inset-inline-*` are used exclusively — `ml-*`/`mr-*`/`text-left`/`text-right` are banned
   in component source (enforced by an ESLint rule, `no-restricted-syntax` on those Tailwind classes). This
   is what makes `AccountPicker`'s check-mark, `DataTable`'s search-icon padding, and `Sheet`'s slide edge
   mirror automatically with zero conditional logic.
2. **Numerals and currency codes never mirror.** As established in `AmountCell` and `CurrencyTag`, every
   monetary value, percentage, date, and currency code renders inside a `dir="ltr"` span even when the
   surrounding row is RTL. This matches real Gulf financial-UI convention (a Kuwait bank statement is
   Arabic-RTL prose around Western-numeral figures) and keeps `1,050.000 KWD` reading left-to-right inside a
   right-to-left sentence, never as `KWD 000.050,1` or with reversed digit order. `AmountCell`'s
   `numberingSystem: 'latn'` forcing and the `dir="ltr"` wrapper are the two mechanisms that jointly
   guarantee this; a future locale add (numbers rendered with Eastern Arabic-Indic digits, e.g. ٠١٢٣) would
   be an explicit, separately-flagged product decision, not a side effect of adding a language.
3. **Icons that encode direction flip; icons that encode meaning don't.** A chevron used for "next page" or
   "expand toward reading direction" is mirrored via `rtl:rotate-180` (or the icon's own inherent
   direction-awareness in Lucide where available). A checkmark, a status dot, a currency glyph, or the sign
   on `AmountCell` never flips — flipping a `+`/`−` sign would invert its meaning, not its layout.

**Bilingual data rendering.** Every master-data record carries both `name_en` and `name_ar` per the
platform's bilingual-fields convention; `AccountPicker`, `DataTable` cells, and any other component
rendering a name pick the field matching `useLocale()` at render time — the API always returns both fields
regardless of `Accept-Language` (see `REST_STANDARDS.md → Localization`), so the client, not the server,
owns which language is displayed, letting a user viewing an Arabic UI still search by an English account
name typed in a mixed-language search box.

**i18n mechanics.** QAYD uses **next-intl** for the App Router: `useTranslations(namespace)` for UI strings,
`useLocale()` for the active locale, and `useFormatter()` for any *non-financial* locale-aware formatting
(relative timestamps, ordinary counts) — financial figures always go through `AmountCell`'s own formatter,
never through `useFormatter().number()`, to guarantee the fixed-numeral-system rule above holds regardless
of what a generic `Intl` call would otherwise localize.

# Testing

**Storybook.** Every component in `# Primitives` and `# Finance Components` ships a `.stories.tsx` colocated
in its component folder. Each story file includes, at minimum, the permutation matrix the component
actually varies along:

```tsx
// components/accounting/amount-cell.stories.tsx
import type { Meta, StoryObj } from '@storybook/react';
import { AmountCell } from './amount-cell';

const meta: Meta<typeof AmountCell> = { component: AmountCell, title: 'Accounting/AmountCell' };
export default meta;

export const Debit: StoryObj<typeof AmountCell> = { args: { amount: '1050.0000', currencyCode: 'KWD', mode: 'debit' } };
export const Credit: StoryObj<typeof AmountCell> = { args: { amount: '450.5000', currencyCode: 'KWD', mode: 'credit' } };
export const Zero: StoryObj<typeof AmountCell> = { args: { amount: '0.0000', currencyCode: 'KWD' } };
export const NonKwdTwoDecimals: StoryObj<typeof AmountCell> = { args: { amount: '1050.0000', currencyCode: 'USD', showCurrency: true } };
export const RTL: StoryObj<typeof AmountCell> = { args: { amount: '1050.0000', currencyCode: 'KWD', mode: 'debit' },
  parameters: { direction: 'rtl' } };
export const DarkMode: StoryObj<typeof AmountCell> = { args: { amount: '1050.0000', currencyCode: 'KWD' },
  parameters: { theme: 'dark' } };
```

The Storybook instance runs a global decorator toggling `dir` and `data-theme` from `parameters.direction`/
`parameters.theme`, so **every** story in the library is inspectable in all four combinations
(LTR/RTL × light/dark) without four separate story files per component — loading, empty, and error states
are treated the same way for data-bound components (`DataTable`, `KpiTile`) via a `parameters.state` mock
that short-circuits the TanStack Query hook to a fixed `isPending`/`isError`/empty-`data` response.

**Vitest + React Testing Library** covers logic-bearing components — anything with a computed invariant,
not purely presentational ones:

```tsx
// components/accounting/journal-entry-form.test.tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { JournalEntryForm } from './journal-entry-form';

test('Post button stays disabled while debits and credits differ', async () => {
  render(<JournalEntryForm mode="create" />);
  fireEvent.change(screen.getByLabelText(/debit/i, { selector: 'input' }), { target: { value: '100.0000' } });
  expect(screen.getByRole('button', { name: /post|submit for approval/i })).toBeDisabled();
  fireEvent.change(screen.getAllByLabelText(/credit/i, { selector: 'input' })[1], { target: { value: '100.0000' } });
  expect(screen.getByRole('button', { name: /post|submit for approval/i })).toBeEnabled();
});
```

```tsx
// components/ai/confidence-badge.test.ts
import { normalizeConfidence } from './confidence-badge';

test('normalizes a 0-100 ai_decisions.confidence_score onto 0-1', () => {
  expect(normalizeConfidence(96.5, 'percentage')).toBeCloseTo(0.965);
});
test('passes through an already-fractional journal_lines.ai_confidence unchanged', () => {
  expect(normalizeConfidence(0.965, 'fraction')).toBe(0.965);
});
```

Other components with mandatory unit coverage: `AmountCell` (currency-decimals table, zero/negative
formatting, sign-glyph correctness per mode), `AgingBar` (segment width math sums to 100% within rounding),
`PeriodPicker` (relative-range value shape matches the API's enumerated `range` values exactly, catching a
future drift between the two independently maintained lists).

**Playwright** covers the two flows too state-dependent for a component-level test: `DataTable`'s actual
server round-trip on sort/filter/page-change against a seeded test API (asserting the request URL's query
string matches the documented grammar, not just that *some* new rows appeared), and the full
`JournalEntryForm → ApprovalCard` create-to-post lifecycle across two logged-in personas (an Accountant who
can only submit, a Finance Manager who approves), asserting the permission-gated button labels switch
correctly per persona.

**Accessibility CI gate.** `axe-core` runs against every Storybook story in CI (via
`@storybook/test-runner` + `axe-playwright`); a new or regressed violation on any story fails the build,
not just a periodic manual audit. `DataTable`, `JournalEntryForm`, and `ApprovalCard` additionally get a
manual keyboard-only pass before each release, since automated `axe` checks catch missing ARIA but not a
broken tab order.

**Visual regression.** Each Storybook story is captured as a baseline snapshot; a PR that changes a
component's rendered output beyond a small pixel-diff threshold surfaces a side-by-side diff in review — this
is what catches an accidental token change (e.g. someone reintroducing a raw Tailwind color) before it ships
across every screen the component appears on, given how widely `AmountCell` and `StatusPill` are reused.

# Edge Cases

- **KWD/BHD/OMR display precision vs. API storage precision.** The API always serializes money at 4 decimal
  places (`"1050.0000"`, per `REST_STANDARDS.md`) regardless of currency, but Kuwait, Bahrain, and Oman's
  real currencies have 3 fractional digits (1,000 fils/baisa per major unit), and JPY/KRW have zero.
  `AmountCell`'s `CURRENCY_DISPLAY_DECIMALS` map is the single place this is reconciled — a component must
  never assume the API string's own decimal count is the correct display count, and must never round the
  *value* being submitted back to the server (only the *displayed* string is rounded; forms always resubmit
  the full-precision string the user edited or the server returned).
- **`ai_decisions.confidence_score` (0–100) vs. `journal_lines.ai_confidence` / `accounts.
  ai_suggestion_confidence` (0.0000–1.0000).** These are two genuinely different scales on two different
  tables in the platform's own data model — not a typo to "fix" in one component. `ConfidenceBadge` never
  accepts a raw field value without the caller passing it through `normalizeConfidence(raw, sourceField)`
  first; a missing or wrong `sourceField` argument is the single most likely AI-panel bug across the whole
  frontend, which is why it is unit-tested explicitly (see Testing) rather than left to convention alone.
- **Permission removed mid-session.** `usePermission()` reads from a client-side RBAC snapshot fetched at
  session start and revalidated on each mutation's `403` response (which forces a permission-cache refetch
  and a toast explaining access changed) — a component must never assume a permission check made on mount
  is still true 20 minutes later for a sensitive action like `Post`; the mutation hook itself re-checks
  before firing and surfaces the server's `403` as the final word if the cached permission was stale.
- **Optimistic update rolled back on `409 version_conflict`.** `JournalEntryForm`'s edit mode sends `If-Match`
  with the entry's last-known `version`/`etag`; on a `409`, the mutation hook discards the optimistic
  TanStack Query cache update, refetches the authoritative record, and surfaces a non-destructive "this
  entry changed since you opened it — your edits are preserved below, please re-apply them" banner rather
  than silently overwriting the user's in-progress edits or silently discarding them.
- **Empty Chart of Accounts (new company, onboarding not finished).** `AccountPicker` renders a distinct
  empty state ("No postable accounts yet — set up your chart of accounts") with a link into onboarding,
  rather than the generic "No account found" search-miss message, which is reserved for a *search that
  matched nothing* against a non-empty account list.
- **Long bilingual names truncating unpredictably.** Arabic account/customer/vendor names commonly run
  longer than their English counterparts at the same font size. Every truncating cell (`DataTable` name
  columns, `AccountPicker` list rows) applies `truncate` with a `title` attribute carrying the full string
  and a `Tooltip` on keyboard focus (not hover-only) — never a fixed `max-width` tuned only against English
  sample data.
- **Row action visibility vs. disabled state.** `DataTable`'s `rowActions` omits an action entirely when the
  caller lacks its permission for actions whose mere *existence* is sensitive information (e.g. `Void` on an
  invoice a Sales Employee has no path to void) but renders a *visible-but-disabled* action with an
  explanatory tooltip when the action is discoverable-but-currently-blocked by business state rather than
  permission (e.g. `Post` on an already-`posted` entry) — the distinction is "who can see this exists" vs.
  "why can't I do this right now," and conflating them either over-exposes RBAC-sensitive affordances or
  under-explains ordinary state gating.
- **AI engine unavailable (`503` on an AI-only endpoint).** `AIProposalPanel`'s parent query surfaces a
  distinct "AI insights are temporarily unavailable" empty state (not a generic `ErrorState`, and not an
  infinite loading spinner), matching `REST_STANDARDS.md`'s guidance that AI-only endpoints fail fast with
  `Retry-After` rather than hang; a `TanStack Query` `retry` policy backs off using that header when present.
- **Huge unfiltered ledger view.** `DataTable` in `paginationMode="cursor"` against `ledger-entries` never
  offers a "jump to last page" control (cursor pagination structurally cannot support it, per
  `API_PAGINATION.md`) and actively nudges the user toward scoping by account or date range via a persistent,
  dismissible hint banner rather than silently allowing an unscoped, slow scroll through millions of rows.
- **Reduced motion.** Every Framer Motion animation in this library (`AIProposalPanel`'s expand/collapse,
  `TrendSparkline`'s draw-in, `Tabs`' indicator slide) is wrapped behind `useReducedMotion()` and degrades to
  an instant state change, never merely a *shorter* animation — `prefers-reduced-motion: reduce` is treated
  as "no motion," not "quick motion," across the entire library, with zero component-level exceptions.
- **Printing / PDF export of a screen containing these components.** `AmountCell`'s sign-glyph-first design
  and `StatusPill`'s dot-plus-label design were both chosen so that a grayscale, no-JS PDF export (rendered
  server-side for `GET /api/v1/reports/report-runs/{id}/export?format=pdf`, per `REST_STANDARDS.md`) built
  from the same underlying data reads correctly without color at all — this is a designed invariant, not an
  incidental benefit, and is the reason color is never the *only* channel carrying meaning anywhere in this
  library.

# End of Document
