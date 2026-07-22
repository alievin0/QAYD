# Grid System — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: GRID_SYSTEM
---

# Purpose

This document specifies the layout grid QAYD composes every screen on: the container widths, the
12-column content grid, the gutter and margin scale, how the five shared page templates
(list, detail, workbench/report, form, wizard) consume that grid, how dense financial tables lay out
inside it, how alignment is expressed in logical properties so Arabic RTL mirrors for free, and how
columns reflow down the breakpoint ladder.

It is the design-system-level restatement and consolidation of the grid mechanics defined in
[../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md), narrowed to the grid itself. Where
`LAYOUT_SYSTEM.md` also covers the app shell, scroll/sticky behavior, and template anatomy in full,
this document owns the *grid contract* those templates share. The breakpoint values here are mirrored
verbatim from that document; see [BREAKPOINTS.md](BREAKPOINTS.md) for the full breakpoint system and
device-tier posture, and [../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md) for the
reflow patterns each grid degrades through.

QAYD's taste is explicit: near-monochrome ink, one accent, hairline borders over heavy shadows,
restrained radii, generous whitespace, desktop-first-but-tablet-capable density. The grid is where
that taste survives contact with forty screens of real financial data or collapses into generic-SaaS
clutter. Every rule below exists so that forty screens feel like one product.

# Two Grids, Not One

QAYD composes on two independent grids that never intersect:

1. **The macro shell grid** — a fixed two-track CSS Grid (`sidebar` + `main`) that frames every
   authenticated screen. It never has 12 columns. It is defined once in the app shell and is covered
   in full in [../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md#app-shell-layout).
2. **The content grid** — the 12-column grid this document governs. It lives *inside* the content
   region only. It never contains the sidebar, the topbar, or the AI rail.

The separation matters: a screen author reasons about "how do my cards divide the reading width"
without ever having to account for the sidebar's current width, because the sidebar is a sibling
track in a different grid. The content grid always starts from `1fr` of whatever the shell has left.

# Container Widths & Gutters

Only one component sets outer page margin and max-width: `Container`. No page template, card, or
feature component sets its own `max-width` or `mx-auto` — doing so is a review-blocking violation,
because it is exactly how two screens silently drift out of alignment over time.

## Container tokens

```css
/* app/globals.css */
@theme {
  --container-max: 90rem;        /* 1440px — reading/content container ceiling */
  --container-max-wide: 100rem;  /* 1600px — dashboard/report full-bleed ceiling on 3xl */

  --grid-margin-base: 1rem;      /* 16px, <sm  */
  --grid-margin-sm: 1.5rem;      /* 24px, sm–md */
  --grid-margin-md: 2rem;        /* 32px, md–xl */
  --grid-margin-lg: 2rem;        /* 32px, xl+ (margin stays fixed; container caps instead) */

  --grid-gutter-base: 1rem;      /* 16px, <md  */
  --grid-gutter-md: 1.5rem;      /* 24px, md–xl */
  --grid-gutter-xl: 2rem;        /* 32px, xl+  */
}
```

## Container behavior by breakpoint

| Breakpoint | Outer margin (inline) | Column gutter | Container behavior |
|---|---|---|---|
| base–sm | 16px | 16px | Fluid 100%, no cap |
| md | 32px | 24px | Fluid 100%, no cap |
| lg | 32px | 24px | Fluid 100%, no cap |
| xl | 32px | 32px | Caps at `--container-max` (1440px) for **reading** templates; full-bleed templates stay fluid |
| 2xl | 32px | 32px | Same cap; extra width becomes centered side margin |
| 3xl | 32px | 32px | Reading caps at 1440px; full-bleed caps at `--container-max-wide` (1600px) — never edge-to-edge |

## Reading vs. full-bleed containers

QAYD draws a deliberate, per-template distinction between two container variants — never a global
setting:

- **Reading container** (`variant="reading"`, caps at 1440px) — form pages, detail pages. Caps so
  line lengths and form-field widths stay scannable rather than stretching across a 32" monitor.
- **Full-bleed container** (`variant="full-bleed"`, caps at 1600px on `3xl`) — the dashboard grid and
  the report canvas, where multi-column financial tables genuinely benefit from more room.

```tsx
// components/layout/Container.tsx
import { cn } from "@/lib/utils";

type ContainerProps = React.ComponentProps<"div"> & {
  variant?: "reading" | "full-bleed";
};

export function Container({ variant = "reading", className, ...props }: ContainerProps) {
  return (
    <div
      className={cn(
        "mx-auto w-full px-4 sm:px-6 md:px-8",
        variant === "reading" ? "max-w-(--container-max)" : "max-w-(--container-max-wide)",
        className,
      )}
      {...props}
    />
  );
}
```

> Reconciliation note: [../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md) engages
> the 1440px cap from `2xl:` (`max-w-full 2xl:max-w-[1440px]`), while
> [../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md) engages it from `xl` for reading
> templates. The reading cap of **1440px** and the full-bleed cap of **1600px** are canonical in both
> documents; the exact tier the cap first engages is a per-template detail (reading templates that are
> already ≤1440px wide at `xl` are visually identical either way). New screens should engage the cap no
> later than `2xl`.

# The 12-Column Content Grid

Inside a `Container`, screens lay out with CSS Grid's native `grid-cols-12` — never ad-hoc percentage
widths — with logical gutters that scale by breakpoint:

```tsx
<Container variant="reading">
  <div className="grid grid-cols-12 gap-x-4 md:gap-x-6 xl:gap-x-8">
    <section className="col-span-12 lg:col-span-8">{/* main column */}</section>
    <aside className="col-span-12 lg:col-span-4">{/* summary rail */}</aside>
  </div>
</Container>
```

## Standard column splits

Every screen reuses the same small set of ratios instead of inventing new ones:

| Split | Usage |
|---|---|
| `12 / 12` | List pages, report canvases, dashboard full-width widgets |
| `8 / 4` | Detail pages (main content / summary-and-activity rail); form pages with a live-preview rail |
| `9 / 3` | Form pages with a slim contextual-help rail instead of a preview |
| `4 / 4 / 4` or `3×4` | Dashboard KPI strip (3 or 4 equal stat cards) |
| `6 / 6` | Two-up card comparisons (actual vs. budget panels, side-by-side period cards) |

A split outside this table is a signal the content wants a different template, not a new ratio.
The one exception is the dashboard bento (below), which is content-height-driven rather than
ratio-driven.

## Gutters and margins summary

| Tier | Inline margin (via Container padding) | Column gutter (`gap-x`) |
|---|---|---|
| base | 16px (`px-4`) | 16px (`gap-x-4`) |
| sm | 24px (`px-6`) | 16px |
| md | 32px (`px-8`) | 24px (`md:gap-x-6`) |
| lg | 32px | 24px |
| xl+ | 32px | 32px (`xl:gap-x-8`) |

Gutters are always horizontal (`gap-x`); vertical spacing between stacked grid children uses the
spacing scale (`gap-y-8` = 32px between page regions) rather than the gutter tokens, so column gutters
and section rhythm stay independently tunable. See the spacing scale in
[../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md#spacing-scale--rhythm).

# Dashboard Bento Grid

KPI/chart/insight widgets have unequal, content-driven heights that a strict 12-column equal-height
row would force into awkward cells. Dashboards therefore use CSS Grid **template areas** layered on
the same 12 columns:

```css
/* DashboardGrid — illustrative; in practice expressed as Tailwind arbitrary values */
.dashboard {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  grid-template-rows: auto auto auto;
  gap: var(--space-6); /* 24px */
  grid-template-areas:
    "kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi   kpi"
    "chart chart chart chart chart chart chart chart insights insights insights insights"
    "cash  cash  cash  cash  ai    ai    ai    ai    ai    ai    ai    ai";
}
```

Because widgets carry a fixed DOM source order for accessibility and SSR streaming, narrow-viewport
promotion is done with `order-*` utilities (an Urgent Actions card is `order-first` on mobile,
`xl:order-none` on desktop), never by reordering the markup. The screen-reader tab order therefore
matches the visually-promoted mobile order, not a desktop-only DOM order. Full reflow rules are in
[../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md#dashboard-reflow).

# Page Template Grid Usage

Every screen is an instance of exactly one of five templates. Each maps onto the content grid the same
way every time, so a `PageHeader`, a `Card`, and a 24px section gap behave identically whether the
screen is Journal Entries or the AI Command Center.

## List page — `12 / 12`

```
┌─────────────────────────────────────────────────────────────────┐
│ Journal Entries                                    [+ New Entry] │  ← PageHeader (col-span-12)
│ 1,842 entries                              [Import ▾] [Export ▾] │
├─────────────────────────────────────────────────────────────────┤
│ [Search…]  [Status ▾][Period ▾][Account ▾]     [▤ Density][[gear]] │  ← Filter/Bulk bar (col-span-12, sticky)
├─────────────────────────────────────────────────────────────────┤
│ DataTable — own horizontal scroll for wide columns (col-span-12) │
├─────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 1,842                    [‹ Prev]  [Next ›]      │  ← Pagination footer (col-span-12)
└─────────────────────────────────────────────────────────────────┘
```

Uses `Container variant="full-bleed"`. Every region is `col-span-12`; the density of the table itself
is a per-table preference (see Dense-Table Layout below).

## Detail page — `8 / 4`

```
┌───────────────────────────────────────────────────┬───────────────┐
│ ‹ Journal Entries   JE-1091           [Posted [check]]  │  Summary       │  ← Header spans both, then split
├─────────────────────────────────────────────────  │  Total 45,220  │
│  Main column (col-span-12 lg:col-span-8)          │  Period Jul'26 │
│  journal lines table, then Activity timeline      │  AI 92% conf.  │
│                                                    │  (col-span-4)  │
└───────────────────────────────────────────────────┴───────────────┘
```

Uses `Container variant="reading"`. Main content is `lg:col-span-8`; the summary rail is
`lg:col-span-4`. The activity timeline is a first-class region at the bottom of the **main** column,
never demoted into the rail. Below `lg` the rail stacks under the main column (`col-span-12`).

## Report / workbench canvas — `12 / 12` full-bleed

The Trial Balance, Balance Sheet, P&L, and Cash Flow are dense, print-faithful statements. The canvas
spans all 12 columns of a full-bleed container; the AI rail does not compete for canvas space by
default, and a drill-down opens as an end-edge `Sheet` overlay rather than stealing a grid column.
Reconciliation and other finance "workbenches" are Detail-template instances with a specialized
match-grid as the `8`-column main region.

## Form page — `9 / 3` or `8 / 4`

Form body is `lg:col-span-9` (with a slim help rail) or `lg:col-span-8` (with a live-preview rail
such as the running debit/credit balance indicator). Sections are stacked `Card` groups with a
**40px** (`--space-10`) gap between sections — louder than the 24px between unrelated page regions,
because form sections read top-to-bottom as one continuous task. Below `lg` the rail stacks below and
the sticky footer action bar carries Save/Submit.

## Wizard — `12 / 12`, centered narrow

Multi-step flows (onboarding, guided import, first-bank-connect) render a single centered column
capped tighter than a reading container — a `col-span-12 md:col-start-3 md:col-span-8
xl:col-start-4 xl:col-span-6` band — so one decision is in focus at a time. A persistent step
indicator (progress rail) sits above the band, full width; the step body never exceeds ~65ch of
reading measure. The wizard is the one template that intentionally *under-fills* the grid: whitespace
is the point.

# Dense-Table Layout

Finance tables are where the grid earns its keep. A table always occupies a full grid region
(`col-span-12` on list/report templates, the `8`-column main on detail) and manages its own internal
column layout and horizontal scroll — the 12-column grid never tries to align a table's internal
columns to page columns.

## Density modes

Density changes padding and row height only. **Font size is constant across modes** — shrinking
financial figures to fit more rows is how mis-keyed decimals get missed.

| | Comfortable | Compact |
|---|---|---|
| Row height | 48px | 32px |
| Header row height | 52px | 36px |
| Cell padding-block | 12px | 6px |
| Cell padding-inline | 16px | 12px |
| Font size | 14px | 14px (unchanged) |

Density is a **per-table, user-persisted** preference (a segmented `Comfortable | Compact` control in
the table toolbar), not a global app setting — an accountant reconciling 4,000 bank lines and an owner
glancing at a 12-row vendor list have different, equally valid needs on the same day. Compact is the
default for high-row-count homogeneous screens (Journal Entries, Ledger, Bank Transactions, Stock
Movements); Comfortable is the default for lower-count varied screens (Customers, Vendors, Products,
Invoices).

## Row structure inside the grid region

- **No zebra striping.** Alternating fills read as busy at density and collide with AI-highlight and
  selection background states. Every row gets a single 1px hairline bottom border instead.
- **Ledger rule lines.** On tables over ~40 rows, every fifth row gets a marginally stronger border —
  a callback to ruled ledger paper, and a genuine scanning aid ("five rows down") at density.
- **Sticky structure.** The identifier column freezes at the logical start edge
  (`inset-inline-start: 0`); the header row sticks to the top; a running-totals row sticks to the
  bottom of the scroll container. All three sit at the sticky z-tier — below every portalled overlay.
  See [ELEVATION_SHADOWS.md](ELEVATION_SHADOWS.md#z-index-tiers).
- **Column min-widths.** Numeric columns declare a `min-w` sized from the widest expected value
  (using `Intl.NumberFormat` accounting sign display), never auto-sized from the first row, so a later
  large/negative value never causes a column reflow after initial paint.

Tables over ~200 rows virtualize with `@tanstack/react-virtual`, feeding the active density mode's
fixed row height into the size estimate.

# Logical-Property Alignment for RTL

The grid mirrors for Arabic with **zero screen-specific overrides** because every offset, span edge,
and alignment is expressed in logical terms. Flipping `dir="rtl"` on `<html>` is sufficient.

| Concern | Logical (required) | Physical (banned) |
|---|---|---|
| Column start/end offset | `col-start-*`, `col-end-*` (grid lines flip with `dir`) | fixed `left`/`right` positioning |
| Inline margin/padding | `ms-*`, `me-*`, `ps-*`, `pe-*` | `ml-*`, `mr-*`, `pl-*`, `pr-*` |
| Card accent edge | `border-s-2` | `border-l-2` |
| Text alignment | `text-start`, `text-end` | `text-left`, `text-right` |
| Frozen column pin | `inset-inline-start: 0` (`sticky start-0`) | `left-0` |

The macro grid's `grid-template-columns` is left with no explicit `direction` override, so column 1
is the **start** edge by definition — right in RTL, left in LTR. A custom `eslint-plugin-tailwindcss`
restricted-list rule fails the build on any physical-direction utility outside two documented
exceptions.

## The two grid exceptions that never mirror

1. **Numeric/currency cell alignment is physically right-aligned in both directions** — `text-right`,
   not `text-end`. Accounting scans magnitude and decimal alignment against a fixed right edge; a
   bilingual team switching language mid-review must see amounts land on the same edge every time.
2. **Debit-before-Credit column order is physically fixed left-to-right in both directions.** The
   label column and all others mirror normally; the Debit/Credit pair is wrapped in a `dir="ltr"`
   span so it never swaps, matching printed statements, PDF exports, and auditor expectations that are
   themselves LTR-fixed regardless of company locale.

```tsx
<TableCell className="text-end">{line.accountName}</TableCell>
<TableCell dir="ltr" className="text-right tabular-nums">{formatMoney(line.debit)}</TableCell>
<TableCell dir="ltr" className="text-right tabular-nums">{formatMoney(line.credit)}</TableCell>
```

Charts also never mirror internally: a time-series axis reads earliest-to-latest left-to-right in both
locales. Only the chart's *container position* within the grid mirrors — an `8/4` split still places
the chart in the visually-start column.

# Responsive Column Reflow

Each template degrades along a documented path. The guiding rule: **content reflows and reprioritizes;
it never gets silently clipped or forced into a horizontal scroll the user did not cause.** A wide
table that the user scrolls is fine; a layout-caused horizontal overflow of the shell is always a bug.

| Template | base–sm | md | lg | xl+ |
|---|---|---|---|---|
| List | Rows become stacked cards (priority-1 fields, "View" opens detail) | Table returns, horizontal scroll, priority columns | Full table, all default columns | Full table, generous gutters |
| Detail | Single column; summary rail stacks below main | Same | `8/4` split activates | `8/4`, more rail whitespace |
| Form | Single column; sticky footer actions | Same | `9/3` or `8/4` split activates | Full split |
| Dashboard | KPI strip becomes horizontal snap carousel; widgets 1-up | KPI 2-up | KPI full row, bento activates | Bento, AI rail may dock inline |
| Report | Canvas scrolls horizontally in its own container; findings collapsed | Same, less column hiding | Full canvas; drill-down inline-expand on very wide | Full canvas, generous margins |
| Wizard | Full-width single column | Centered narrow band | Same | Same, capped |

Two mechanics carry the reflow:

- **Column priority hiding.** Every table column declares a `priority` (`1` = always visible,
  higher = hidden below wider tiers). Hidden columns stay reachable via the column-visibility menu and
  the row's expand affordance — never permanently lost.
- **Container queries for widget-level reflow.** A KPI card in a 3-column grid is narrower than the
  same component full-width on a detail page, so widgets use `@container` queries and respond to their
  *own* available width, not the viewport's — the same `KpiCard` looks correct 1-up on mobile, 3-up on
  a laptop, or dropped into the narrow AI rail slot.

The **card transform** (table → cards) applies only below `sm`, and only for List pages. A Report
statement never becomes a stack of cards — its tabular structure is the content, not a display choice;
it gets fonts-stay-fixed horizontal scroll instead.

# Do & Don't

| Do | Don't |
|---|---|
| Compose inside `Container` + `grid-cols-12` | Set `max-width`/`mx-auto` on a card or feature component |
| Reuse the standard splits (12, 8/4, 9/3, 3×4, 6/6) | Invent a new column ratio per screen |
| Cap reading templates at 1440px, full-bleed at 1600px | Stretch a 6-column Trial Balance edge-to-edge on a 1920px monitor |
| Express every offset/alignment in logical properties | Use `left-*`, `ml-*`, `text-left` outside the two exceptions |
| Right-align numerals and fix Debit-before-Credit in both directions | "Correct" numeric cells to `text-end` in a refactor |
| Let a table own its internal column layout and scroll | Try to align table columns to the 12-column page grid |
| Promote mobile order with `order-*` on a fixed DOM order | Reorder markup to change visual priority (breaks tab order) |
| Keep gutters horizontal; use spacing tokens for vertical rhythm | Conflate column gutter with section spacing |

# End of Document
