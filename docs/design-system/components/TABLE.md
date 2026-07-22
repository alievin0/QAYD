# Table — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / TABLE
---

# Purpose

This is the atomic design-system specification for QAYD's **Table** — the `Table` primitive and the
`DataTable` cell-and-structure atoms (`AmountCell`, `StatusPill`, header cell, row, selection checkbox,
sticky header/footer, density) expressed purely in tokens. It is the design-system counterpart to the
application-level system in [`../../frontend/components/TABLES.md`](../../frontend/components/TABLES.md):
that document owns the *behavioral* contract — TanStack Table/Query wiring, server-side sort/filter/cursor
pagination, the two-layer `Table`/`DataTable` architecture, deep-linkable state, virtualization, keyboard
grid navigation, and the platform rule that a table **never** sorts or totals client-side. This document
does not restate any of that. It owns the **atomic visual contract**: which token paints every border,
fill, numeral, dot, and sticky surface; the exact row heights per density; how a cell renderer maps a
data shape to a token treatment; and how the whole thing mirrors in RTL and remaps in dark mode.

The binding rule from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) governs every line below: **a table cell
references a token, never a literal.** No table file ships a hex, an `rgb()`, or a raw px that a token
already covers. Every financial figure is `tabular-nums`, Latin-numeral, `dir="ltr"`, rendered through the
`AmountCell` atom — enforced, not requested. The full ink scale, accent, and semantic palette this document
draws on are defined in [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); the `AmountCell`/`StatusPill` component
signatures are in [`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md).

> Token-naming note. The application docs were drafted against an earlier numeric-step palette
> (`ink-150`, `accent-600`, `bg-surface`, `text-body`). Per [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md),
> the **canonical** names — used throughout this spec — are `ink-1 … ink-12`, `accent`/`accent-subtle`/
> `accent-strong`/`accent-on`, `positive`/`negative`/`warning`, and the `text-*`/`numeral-*` type steps.
> Where an app-doc snippet shows a draft name, read it as its canonical equivalent (`ink-150` → `ink-6`,
> `accent-600` → `accent`, `bg-surface` → `ink-1`/`ink-3`, `text-body` → `text-sm` in-table).

# Anatomy

A QAYD table is assembled from a fixed set of token-styled atoms. The primitive owns markup and tokens
only; data, sorting, and permissions live in the composed `DataTable` (see the app doc).

```
Table (surface-1: ink-1 canvas, 1px ink-6 frame, radius-lg)
├── TableHeader  ── sticky top-0 · z-sticky · bg-ink-2 · text-xs uppercase ink-10 · 1px ink-7 bottom rule
│   └── TableHead (sortable) ── focusable <button> · ArrowUpDown ink-9 idle / accent active · aria-sort
├── TableBody
│   └── TableRow ── 1px ink-6 bottom border · hover:bg-ink-4/60 · selected bg-accent-subtle
│       ├── [gutter] AiProvenanceDot ── 6px filled accent dot · leading edge only
│       ├── [select] Checkbox ── radius-sm · accent when checked
│       └── TableCell ── ps-3/pe-3 · text-sm ink-11 (text) / numeral-table ink-12 (money)
│           ├── AmountCell ──── font-mono tabular-nums · dir="ltr" · text-end
│           ├── StatusPill ──── radius-full · dot + label · never color-alone
│           ├── AccountCell ─── mono code (ink-9) + bilingual name (ink-11)
│           └── FormattedDate ─ Intl · numerals dir="ltr"
└── TableFooter (totals) ── sticky bottom-0 · z-sticky · bg-ink-2 · 1px ink-7 top rule · display-sm 600
```

Elevation is built from **hairline borders, not shadow** ([`../DESIGN_TOKENS.md → Elevation & Surfaces`](../DESIGN_TOKENS.md)):
the `ink-6` row divider and the `ink-7` header/footer rule carry the structure; the table would still read
correctly with its `shadow-xs` removed. **Zebra fill is never used** — a single `ink-6` bottom border per
row, with every fifth row promoted to a marginally stronger `ink-7` rule on tables past ~40 rows, is the
entire row-separation system.

## The cell-renderer contract

A cell renderer is a pure function of one row that returns a library atom — never inline JSX with
hard-coded classes. Each atom below carries its own token contract so two tables never disagree on how a
posted status or a KWD amount looks. Signatures live in the component library; this spec fixes their
**token treatment**.

| Atom | Token treatment | Numeral rule |
|---|---|---|
| `AmountCell` | `font-mono tabular-nums`, `text-end`, `ink-12`; `emphasis="strong"` → 600; `emphasis="muted"`/zero → `ink-9` | `dir="ltr"`, `numberingSystem: 'latn'`, KWD/BHD/OMR 3-decimal |
| `StatusPill` | `radius-full`, dot + label; dot fill by tone (`ink-9`/`accent`/`positive`/`warning`/`negative`) | — |
| `AccountCell` | mono `code-sm` `ink-9` + bilingual name `ink-11`, optional `control` tag `ink-9` | code `dir="ltr"` |
| `FormattedDate` | `text-sm ink-11` | numerals `dir="ltr"` |
| `AiProvenanceDot` | 6px filled `accent` dot in the leading gutter; the only filled status dot in the system | — |

# Variants

The Table atom varies along a small, orthogonal token-affecting set. Behavioral axes (`paginationMode`,
`virtualization`, responsive strategy) are the app doc's; the axes that change **tokens** are:

| Axis | Values | Token effect |
|---|---|---|
| `density` | `compact` / `comfortable` / `relaxed` | Row height + cell font + vertical padding (table below) |
| `selection` | `none` / `multi` | Adds the leading `radius-sm` checkbox column + selected-row `accent-subtle` fill |
| emphasis (per row) | `default` / `strong` | Group/subtotal rows use `display-sm`, weight-600, `ink-12` |
| sticky | header / footer / start-column | `z-sticky` surfaces filled `ink-2` so scrolled content never bleeds through |

## Density

Density is a per-table, user-persisted toggle, not a global setting — the same accountant reconciles four
thousand lines in Compact and reviews a twelve-vendor list in Comfortable. Each mode is a fixed row height,
type step, and vertical-padding token; the virtualizer reads the active row height as its size estimate so
it stays pixel-correct across a density change.

| Mode | Row height | Cell type step | Vertical padding | Default on |
|---|---|---|---|---|
| Compact | 32px | `text-xs` (12px) | `--space-xs` (8px) | General Ledger, Journal Entries > 50 lines |
| Comfortable | 40px | `text-sm` (14px) | `--space-sm` (12px) | Trial Balance, Banking, most lists (default) |
| Relaxed | 48px | `text-sm` (14px) | `--space-md` (16px) | Customer/Vendor directories, settings lists |

## The `Table` primitive (tokens only)

```tsx
// components/ui/table.tsx — shadcn/ui table re-skinned to QAYD tokens. Markup + tokens only.
import { cn } from '@/lib/utils';

export function Table({ className, ...props }: React.ComponentProps<'table'>) {
  return (
    <div className="relative w-full overflow-x-auto rounded-lg border border-ink-6 shadow-xs">
      <table className={cn('w-full caption-bottom border-collapse text-sm', className)} {...props} />
    </div>
  );
}

export function TableHeader({ className, ...props }: React.ComponentProps<'thead'>) {
  // Sticky, recessed header fill; z-sticky so it floats above scrolling rows.
  return <thead className={cn('sticky top-0 z-[10] bg-ink-2 [&_tr]:border-b [&_tr]:border-ink-7', className)} {...props} />;
}

export function TableRow({ className, ...props }: React.ComponentProps<'tr'>) {
  return (
    <tr
      className={cn(
        'border-b border-ink-6 transition-colors',
        'hover:bg-ink-4/60 data-[selected=true]:bg-accent-subtle',
        className,
      )}
      {...props}
    />
  );
}

export function TableHead({ className, ...props }: React.ComponentProps<'th'>) {
  // Column header: uppercase caption, secondary ink, logical alignment. Numeric columns pass text-end.
  return <th className={cn('h-9 px-3 text-start align-middle text-xs font-medium uppercase tracking-wide text-ink-10', className)} {...props} />;
}

export function TableCell({ className, ...props }: React.ComponentProps<'td'>) {
  return <td className={cn('px-3 align-middle text-ink-11', className)} {...props} />;
}

export function TableFooter({ className, ...props }: React.ComponentProps<'tfoot'>) {
  return <tfoot className={cn('sticky bottom-0 z-[10] bg-ink-2 font-semibold text-ink-12 [&_tr]:border-t [&_tr]:border-ink-7', className)} {...props} />;
}
```

The primitive knows nothing about KPIs, permissions, or sorting; a permission check or an audit side-effect
always lives in the composed `DataTable`, per the composition-over-modification rule in the app doc.

# Props / API

The full `DataTable` prop surface (`columns`, `resource`, `paginationMode`, `selection`, `bulkActions`,
`expansion`, `columnPinning`, `syncToUrl`, …) is owned by
[`../../frontend/components/TABLES.md → Props / API`](../../frontend/components/TABLES.md) and is not
duplicated here. This section fixes only the props whose values are **token or numeral decisions**.

## Density and emphasis (token-bearing)

| Prop | Type | Token effect |
|---|---|---|
| `density` | `'compact' \| 'comfortable' \| 'relaxed'` | Selects the row-height/type/padding triple in the table above. |
| `numeric` (column) | `boolean` | Marks a money/quantity column: header and cell `text-end` in LTR **and** RTL; cell renders through `AmountCell`. |
| `emphasis` (row) | `'default' \| 'strong'` | `'strong'` → group/subtotal row in `display-sm` weight-600 `ink-12`. |

## `AmountCell` token props

`AmountCell` is the one atom every money cell routes through — the single place a `NUMERIC(19,4)` string is
parsed for display. Its token-relevant props:

| Prop | Type | Token / numeral effect |
|---|---|---|
| `amount` | `string` | Raw server `NUMERIC(19,4)` string; parsed for display only, never re-serialized. |
| `currencyCode` | `string` | Drives display decimals (KWD/BHD/OMR = 3, JPY/KRW = 0, else 2). |
| `mode` | `'debit' \| 'credit' \| 'signed' \| 'plain'` | Leading `+`/`−` **glyph** carries polarity; color is only a secondary signal. |
| `align` | `'start' \| 'end'` | Default `'end'`; numeric columns right-align in both directions. |
| `emphasis` | `'default' \| 'strong' \| 'muted'` | `strong` → 600 `ink-12`; `muted`/zero → `ink-9`. |

```tsx
// The renderer maps a row to the atom; the atom owns the tokens. No inline classes here.
{ accessorKey: 'total_debit', header: 'Amount', numeric: true, size: 148,
  cell: ({ row }) => <AmountCell amount={row.original.total_debit} currencyCode={row.original.currency_code} /> }
```

**The debit/credit rule (token-level).** A raw debit or credit column is **never** tinted
`positive`/`negative` — debit-normal and credit-normal are structural properties of an account type, not
good/bad ([`../COLOR_SYSTEM.md → The debit/credit rule`](../COLOR_SYSTEM.md)). Journal Entry, General
Ledger, and Trial Balance Dr/Cr columns render in plain `ink-12` tabular numerals, distinguished only by
column position and a right-aligned header. Semantic color is reserved for genuinely signed net metrics
(Net Income, a variance) and even there rides a small delta chip, not the whole numeral.

# States

Every state has a designed, token-defined rendering — never a raw blank or a spinner where content of known
shape belongs. The behavioral handoff (which state, when) is the app doc's; the **token treatment** is here.
The table delegates the whole-surface empty/loading/error to `Skeleton`/`EmptyState`/`ErrorState`
([`./CARD.md`](./CARD.md) shares those atoms) and only decides which state it is in.

| State | Token treatment |
|---|---|
| **Loading (no prior data)** | Skeleton rows shaped to column widths: `ink-3` bars with the `motion.slow` shimmer sweep; row count ≈ the density's page (8–12). Never a centered spinner. |
| **Refetching (data present)** | Rows stay put; a 2px `accent` top progress line, `aria-busy="true"`. Never a flash-to-empty. |
| **Empty (unfiltered)** | `EmptyState`: one `ink-9` line icon, `display-md` title `ink-11`, `text-sm` body `ink-9`, optional primary `bg-accent`/`accent-on` action. |
| **Empty (filtered)** | Lighter variant: `text-sm ink-9` "No rows match your filters" + a `text-accent` "Clear filters" button. Distinct from a genuinely empty resource. |
| **Error (whole table)** | `ErrorState` with request id in `code-sm ink-9` and a Retry (`bg-accent`/`accent-on`); toolbar stays interactive. |
| **Per-row loading** | Inline spinner in the actions cell, `aria-busy` on the `<tr>`; the rest of the table stays live. |
| **Per-row error (rollback)** | The row flashes `negative-subtle` once over `motion.moderate` back to transparent, with an in-row retry — never a global toast that loses which row failed. |
| **Selected row** | `bg-accent-subtle` fill (not `ink-5`) — the accent's second sanctioned duty is "AI/selection", per [`../COLOR_SYSTEM.md → The accent`](../COLOR_SYSTEM.md). |
| **AI-provenance row** | A 6px filled `accent` dot in the leading gutter + a "Suggested" micro-label in detail — never a full colored row background. Disappears once a human decides. |
| **Realtime arrival** | The new row flashes `accent-subtle` → transparent over 600ms; no toast, no layout shift. |

# Tokens Used

Every token this atom paints, drawn only from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md). No other values are permitted in a table file.

| Role | Token | Notes |
|---|---|---|
| Table canvas | `ink-1` | Behind the frame |
| Table frame | 1px `ink-6` + `radius-lg` + `shadow-xs` | Editorial-flat elevation |
| Header / footer fill | `ink-2` | Recessed, sticky at `z-sticky` |
| Header/footer rule | 1px `ink-7` | Stronger structural line |
| Row divider | 1px `ink-6`; every 5th row `ink-7` past ~40 rows | Never zebra fill |
| Row hover | `ink-4` @ 60% | Interactive fill, not a background |
| Row selected | `accent-subtle` | Selection = second accent duty |
| AI-provenance dot | `accent` (6px filled) | Only filled status dot |
| Header label | `text-xs` uppercase, `ink-10` | Sits above `ink-9` cells |
| Body text cell | `text-sm`, `ink-11` | |
| Money cell | `numeral-table` (`tabular-nums`), `ink-12`, `dir="ltr"` | `AmountCell` |
| Account code | `code-sm`, `ink-9`, `dir="ltr"` | Monospace |
| Secondary / muted / zero | `ink-9` | Placeholder-of-value, em-dash |
| Group/subtotal row | `display-sm`, weight-600, `ink-12` | `emphasis="strong"` |
| Status dot tones | `ink-9` / `accent` / `positive` / `warning` / `negative` | Never color-alone |
| Per-row error flash | `negative-subtle` | One flash, `motion.moderate` |
| Focus ring | 2px `accent` + 2px offset | Sortable header buttons, checkboxes |
| Sticky z-index | `z-sticky` (10) | Header, footer, start-pinned column |
| Row-entrance stagger | `motion.fast`, 30ms/row capped at 12 | Reduced-motion → instant |

Density row heights (32/40/48px) and cell paddings (`--space-xs`/`--space-sm`/`--space-md`) come from the
spacing scale in [`../DESIGN_TOKENS.md → Spacing`](../DESIGN_TOKENS.md).

# Accessibility

Inherits the WCAG 2.1 AA baseline of the design system; the table atom's non-negotiables (behavioral detail
in [`../../frontend/components/TABLES.md → Accessibility`](../../frontend/components/TABLES.md)):

- **Real semantic table.** A `<table>` with `<th scope="col">`; a CSS-grid fake from `<div>`s is never used
  for tabular data, so screen readers announce "column, row" natively.
- **Sortable headers are focusable `<button>`s** exposing `aria-sort="ascending|descending|none"`; the sort
  change announces via a visually-hidden `aria-live="polite"` region. The idle/active caret is `ink-9` →
  `accent`, a color change **and** a glyph swap (`ArrowUpDown` → `ArrowUp`/`ArrowDown`) so direction is not
  color-alone.
- **Numeral polarity is text.** `AmountCell` renders a real `+`/`−` glyph, so a grayscale board pack or a
  colorblind reader conveys sign without color — a designed invariant, not a coincidence.
- **Status is dot + label**, never a bare colored dot; the label carries the meaning, the tone is secondary.
- **Contrast, verified both themes.** `ink-11`/`ink-12` body on `ink-1`/`ink-2` clears ~13:1; `ink-9`
  secondary clears ≥4.9:1; the `accent` focus ring clears ≥3:1 at every surface step
  ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).
- **Selection checkboxes** are real `<input type="checkbox">` with labels naming the row ("Select
  JE-2026-0482"); the header select-all announces its tri-state.
- **Reduced motion** collapses the row-entrance stagger, the realtime flash, and expandable-row height to
  instant state changes — "no motion," not "quick motion."

# Theming, Dark Mode & RTL

**Tokens only.** A table file contains no raw hex, no numbered legacy palette step, and no `dark:` variant
against a raw color. Structure is built from the tokens in the table above; a re-theme is a single
`globals.css` edit.

**Dark mode is a pure remap.** `class="dark"` (and `data-theme="dark"`) on `<html>` re-points every
`--qayd-*` variable, so the identical table markup renders correctly dark with no table-level conditional.
Per [`../COLOR_SYSTEM.md → Light ↔ Dark Remap`](../COLOR_SYSTEM.md), elevation gets **lighter** as it
raises — the sticky `ink-2` header sits above the `ink-1` canvas — and the selection/AI `accent-subtle`
lifts rather than saturates, so a selected row reads as gently distinct against the dark surface instead of
glowing. Shadows re-derive at ~50% alpha with `rgba(0,0,0,…)`.

**RTL is the highest-risk area of the whole system, and the rule is absolute: logical properties only.**

- Sticky pinning is `sticky inset-inline-start-0` / `inset-inline-end-0`, **never** `left-0`/`right-0`. A
  physical `left-0` on a pinned account column is visually correct in an English demo and silently pins the
  wrong edge the instant the Trial Balance is viewed in Arabic — the single highest-risk line the app doc
  describes, enforced with an ESLint `no-restricted-syntax` ban.
- Padding and alignment are `ps-*`/`pe-*`/`text-start`/`text-end`; column order mirrors automatically (the
  identifier column sits at the visual right, amounts flow leftward); arrow-key cell navigation mirrors so
  `→` moves toward the logical start.
- **Numerals and currency codes never mirror.** Every `AmountCell`/`AccountCell` code and money span is
  `dir="ltr"` with `numberingSystem: 'latn'`, so `1,050.000 KWD` reads left-to-right inside a right-to-left
  row, never as reversed digits.
- **Meaning glyphs never flip.** A status dot, a checkmark, a `+`/`−` sign never mirror — flipping a sign
  would invert its meaning, not its layout. A directional chevron (a row drill-in) flips via
  `rtl:rotate-180`.

# Do / Don't

| Do | Don't |
|---|---|
| Separate rows with a single `ink-6` hairline | Apply zebra background fill |
| Build elevation from the `ink-6`/`ink-7` borders | Reach for a heavy drop shadow to structure rows |
| Route every money cell through `AmountCell` (`tabular-nums`, `dir="ltr"`, `latn`) | Format a figure with a generic number formatter |
| Render raw Dr/Cr columns in neutral `ink-12` | Color debits red and credits green |
| Fill a selected row `accent-subtle` | Give selection its own new hue |
| Mark AI provenance with a 6px `accent` dot + "Suggested" | Wash the whole AI row in a colored background |
| Pin with `inset-inline-start-0` / `inset-inline-end-0` | Pin with physical `left-0`/`right-0` |
| Pair every color signal (sort caret, status) with a glyph/label | Convey sort direction or status by color alone |
| Read the density's row height into the virtualizer | Hardcode a row-height px in the virtualizer |
| Let dark mode remap tokens automatically | Add a `dark:` variant against a raw color |

# Usage & Composition

A `DataTable` is a `columns` array of `ResponsiveColumnDef`s plus a `resource` — the column defs are where
the cell atoms are wired, and the atoms own the tokens. This is the composition the app doc's Journal
Entries example expands with selection and bulk actions.

```tsx
// columns.tsx — cell renderers map a row to a token-owning atom; no inline classes.
import type { ResponsiveColumnDef } from '@/components/shared/data-table/types';
import { AmountCell } from '@/components/accounting/amount-cell';
import { StatusPill } from '@/components/shared/status-pill';
import { FormattedDate } from '@/components/shared/formatted-date';
import { AiProvenanceDot } from '@/components/shared/ai-provenance-dot';
import type { JournalEntry } from '@/types/accounting';

export const journalEntryColumns: ResponsiveColumnDef<JournalEntry>[] = [
  { id: 'ai', header: '', priority: 1, size: 20, enableSorting: false,
    cell: ({ row }) => <AiProvenanceDot source={row.original.source} confidence={row.original.ai_confidence} /> },
  { accessorKey: 'entry_date', header: 'Date', priority: 1, size: 112,
    cell: ({ row }) => <FormattedDate value={row.original.entry_date} /> },
  { accessorKey: 'memo', header: 'Description', priority: 1, isCardTitle: true,
    cell: ({ row }) => <span className="truncate text-ink-11" title={row.original.memo}>{row.original.memo}</span> },
  { accessorKey: 'total_debit', header: 'Amount', priority: 1, numeric: true, size: 148,
    cell: ({ row }) => <AmountCell amount={row.original.total_debit} currencyCode={row.original.currency_code} /> },
  { accessorKey: 'status', header: 'Status', priority: 2, size: 148,
    cell: ({ row }) => <StatusPill domain="journal_entry" status={row.original.status} /> },
];
```

```tsx
// The composed table — behavior (server sort/filter/pagination) is the app doc's; density carries the tokens.
<DataTable
  columns={journalEntryColumns}
  resource="accounting/journal-entries"
  paginationMode="page"
  density="comfortable"        // 40px rows, text-sm cells, --space-sm padding
  selection="multi"            // adds the radius-sm checkbox column + accent-subtle selected fill
  getRowId={(row) => String(row.id)}
/>
```

**Composition boundaries.**

- A grouped, subtotalled report canvas (Trial Balance, Balance Sheet) is **not** a `DataTable`; it is the
  `Table` primitive assembled by hand with the sticky-first-column pattern, still bound by every token,
  numeral, and RTL rule above. Its Dr/Cr columns stay neutral `ink-12`; group rows use `emphasis="strong"`;
  the grand-total `<tfoot>` uses `display-sm` weight-600.
- A row that must render as a card below `md:` wraps the same `columns` array in a `<ResponsiveDataView>` —
  the mobile card face is the [`./CARD.md`](./CARD.md) list-item card, so the definition never drifts.
- The empty/loading/error atoms (`EmptyState`, `ErrorState`, `Skeleton`) are shared with the card system;
  the table decides *which* state and hands off, it never re-implements them inline.

For the full behavioral contract — TanStack wiring, cursor pagination, virtualization, keyboard grid, and
deep-linkable state — see [`../../frontend/components/TABLES.md`](../../frontend/components/TABLES.md).

# End of Document
