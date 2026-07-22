# Spacing System — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: SPACING_SYSTEM
---

# Purpose

This document is the binding specification for spacing, density, and layout rhythm in QAYD across the
Next.js frontend. It is the spacing chapter of the QAYD Design System, expanded from the `Spacing & Grid`
and `Data Density` sections of [../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md) — the single
upstream source of truth for the scale values, the breakpoints, and the density modes — into the full,
implementation-ready detail a frontend engineer or an AI coding agent needs to space a card, a form, a
dense ledger table, or a full page without a follow-up question. Where this document and DESIGN_LANGUAGE.md
state a value, they must agree exactly; if they drift, DESIGN_LANGUAGE.md wins and this document is
corrected.

QAYD is an AI Financial Operating System whose screens live or die on how well they hold **density with
air.** Finance work is inherently dense — a General Ledger can be thousands of lines — but density is not
clutter. QAYD achieves density through smaller, disciplined type and tighter row heights, **never** through
removing the whitespace that separates one idea from the next. A dense table on a spacious page reads as
expert; a dense table on a cramped page reads as broken. This document encodes that balance as a concrete
scale, three density modes, and a set of logical-property rules that make every measurement mirror correctly
between English (LTR) and Arabic (RTL) with zero conditional code.

This document covers: the base unit and the full spacing scale as a table; the semantic spacing aliases and
when to use each; logical spacing (`ms-` / `me-` / `ps-` / `pe-`) for RTL; the three table density modes
(Compact / Comfortable / Relaxed) and the per-table user preference behind them; the distinction between
component-internal and layout spacing; the grid, breakpoints, and app-shell metrics the spacing sits inside;
and the usage rules that keep rhythm consistent across authors. Every rule is stated for both directions.

Related reading: [TYPOGRAPHY.md](./TYPOGRAPHY.md) (the type sizes and row heights these density modes pair
with), [COLOR_SYSTEM.md](./COLOR_SYSTEM.md) (the border and surface colors that structure the spaced
regions), [../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md) and
[../frontend/RESPONSIVE_DESIGN.md](../frontend/RESPONSIVE_DESIGN.md) (the page templates this scale fills),
and [../frontend/THEMING.md](../frontend/THEMING.md) (the density preference plumbing).

# Spacing Principles

**1. Density with air.** Density comes from type size and row height, never from deleting the whitespace
that separates ideas. Tighten the row, not the margin around the section.

**2. One 4px grid.** Every space in QAYD is a multiple of 4px on Tailwind's default scale. There is no
parallel spacing system, no `gap-[13px]`, no magic number. One grid is one fewer thing to remember and it is
already what shadcn/ui assumes.

**3. Semantics for decisions, raw scale for measurements.** A handful of spacings recur as *decisions* —
"card internal padding," "gap between panels" — and get a named semantic alias so a change is made once. The
rest are plain scale steps used directly.

**4. Logical, never physical.** All directional spacing uses logical properties (`ms-`, `me-`, `ps-`,
`pe-`, `border-s`, `border-e`, `text-start`, `text-end`) so a component written once mirrors automatically
under `dir="rtl"`. Physical `ml-` / `mr-` / `pl-` / `pr-` is banned outside a short, reviewed allow-list.

**5. Density is a user's choice, per table.** An accountant reconciling 4,000 bank lines and an owner
glancing at a 12-row vendor list have different, equally valid density needs on the same day — so density is
a per-table, user-persisted preference, not one global app setting.

# The Spacing Scale

QAYD adopts Tailwind's default 4px spacing scale unmodified (`p-1` = 4px through `p-32` = 128px) rather than
inventing a parallel one. The base unit is **4px**; every spacing token is a multiple of it. Semantic
aliases are layered on top for the values that recur as decisions.

## Semantic aliases

| Semantic token | Value | Tailwind step | Usage |
|---|---|---|---|
| `--space-2xs` | 4px | `1` | Icon-to-label gap, chip internal padding |
| `--space-xs` | 8px | `2` | Tight stack gap, table cell vertical padding (compact) |
| `--space-sm` | 12px | `3` | Form field internal padding, table cell vertical padding (comfortable) |
| `--space-md` | 16px | `4` | Default stack gap, card internal padding (small card) |
| `--space-lg` | 24px | `6` | Card internal padding (default), section gap within a panel |
| `--space-xl` | 32px | `8` | Gap between panels / cards on a page |
| `--space-2xl` | 48px | `12` | Page-level section gap |
| `--space-3xl` | 64px | `16` | Page top padding under the topbar on wide screens |

## Full raw scale

The semantic aliases resolve onto Tailwind's raw steps; the full range is available directly for one-off
measurements that are not a recurring decision.

| Tailwind step | Value | Common use |
|---|---|---|
| `0` | 0px | Reset |
| `0.5` | 2px | Hairline nudge, filled status-dot inset |
| `1` | 4px | `--space-2xs` |
| `1.5` | 6px | Badge padding |
| `2` | 8px | `--space-xs` |
| `2.5` | 10px | Button vertical padding (small) |
| `3` | 12px | `--space-sm` |
| `4` | 16px | `--space-md` |
| `5` | 20px | Comfortable button block padding |
| `6` | 24px | `--space-lg` |
| `8` | 32px | `--space-xl` |
| `10` | 40px | Icon-button hit target, control row height |
| `12` | 48px | `--space-2xl`, Relaxed row height |
| `16` | 64px | `--space-3xl` |
| `20` | 80px | Large empty-state vertical padding |
| `24` | 96px | Marketing-section rhythm |
| `32` | 128px | Max page-section separation |

## Scale as CSS variables

```css
/* app/globals.css — semantic spacing aliases over Tailwind's 4px grid */
:root {
  --space-2xs: 4px;
  --space-xs:  8px;
  --space-sm:  12px;
  --space-md:  16px;
  --space-lg:  24px;
  --space-xl:  32px;
  --space-2xl: 48px;
  --space-3xl: 64px;
}
```

The aliases are for CSS outside Tailwind's reach (a Framer Motion `style` prop, a canvas gutter). Inside
components, use the Tailwind step directly (`p-6` for default card padding, `gap-4` for a default stack) —
the step *is* the token, so `p-6` and `--space-lg` are the same 24px decision expressed in two contexts.

# Component-Internal vs Layout Spacing

The scale serves two distinct jobs, and keeping them mentally separate is what keeps rhythm consistent.
**Component-internal** spacing is padding and gaps *inside* a single component — a card's padding, a form
field's internal padding, a button's block padding, the gap between an icon and its label. **Layout**
spacing is the gaps *between* components on a page — the gap between two cards, the section gap within a
panel, the page-level section rhythm, the top padding under the topbar.

| Job | Typical tokens | Examples |
|---|---|---|
| Component-internal | `2xs`–`lg` (4–24px) | Card padding `lg` (24px); small card `md` (16px); form field `sm` (12px); chip `2xs` (4px); icon-to-label `2xs` (4px) |
| Layout | `lg`–`3xl` (24–64px) | Section gap within a panel `lg` (24px); gap between panels `xl` (32px); page section gap `2xl` (48px); page top padding `3xl` (64px on wide) |

The two overlap at `--space-lg` (24px) by design — it is both the default card's internal padding and the
gap between sections within a panel, which is why a panel of cards reads as one coherent rhythm rather than
two competing grids. Below is the canonical card, whose internals are all component-internal spacing:

```tsx
// Default card: lg (24px) internal padding, md (16px) header-to-body gap.
<div className="rounded-lg border border-ink-6 bg-ink-2 p-6 shadow-xs dark:bg-ink-3">
  <div className="flex items-center justify-between mb-4">   {/* header→body: --space-md */}
    <h3 className="font-display text-display-sm text-ink-12">Cash Position</h3>
    <Button variant="ghost" size="icon"><MoreHorizontal className="size-4" /></Button>
  </div>
  <p className="font-display numeral-hero tabular-nums text-ink-12">KD 84,210.500</p>
  <p className="text-text-sm text-positive mt-1">+2.4% vs last month</p>  {/* delta: --space-2xs */}
</div>
```

And the canonical page region, whose gaps are all layout spacing:

```tsx
// A page: 2xl (48px) between major sections, xl (32px) between cards in a grid.
<main className="px-6 pt-16 pb-12">        {/* pt-16 = --space-3xl under the topbar on wide */}
  <section className="space-y-12">          {/* --space-2xl between page sections */}
    <div className="grid grid-cols-12 gap-8"> {/* --space-xl between dashboard tiles */}
      {/* ...cards... */}
    </div>
  </section>
</main>
```

# Logical Spacing for RTL

QAYD mirrors layout direction using **CSS logical properties exclusively** — never physical left/right
values — so a component written once behaves correctly in both directions with zero conditional code.

```tsx
// Correct — logical, mirrors automatically with dir="rtl"
<div className="ms-4 me-2 ps-6 border-s">

// Wrong — physical, will not mirror, banned in review
<div className="ml-4 mr-2 pl-6 border-l">
```

| Physical (banned) | Logical (required) | Meaning |
|---|---|---|
| `ml-*` | `ms-*` | Margin at the inline start |
| `mr-*` | `me-*` | Margin at the inline end |
| `pl-*` | `ps-*` | Padding at the inline start |
| `pr-*` | `pe-*` | Padding at the inline end |
| `border-l` | `border-s` | Border at the inline start |
| `border-r` | `border-e` | Border at the inline end |
| `text-left` | `text-start` | Align to the inline start |
| `text-right` | `text-end` | Align to the inline end |
| `left-*` / `right-*` | `start-*` / `end-*` | Inset (positioning) |
| `rounded-l-*` / `rounded-r-*` | `rounded-s-*` / `rounded-e-*` | Corner radius on one side |

The logical utilities are **enforced by an ESLint rule** (`eslint-plugin-tailwindcss` custom restriction)
that flags any `ml-`, `mr-`, `pl-`, `pr-`, `border-l-`, `border-r-`, `text-left`, `text-right` class outside
a short, reviewed allow-list. That allow-list holds exactly the numeral-alignment cases from Data Density
that are intentionally **not** mirrored — a financial figure is always LTR and right-aligned in its column
regardless of page direction (see [The one place physical alignment is correct](#the-one-place-physical-alignment-is-correct)).

Icons that encode direction — a chevron meaning "next," an arrow on a "back" button — are mirrored via a
`[dir="rtl"] &` CSS transform (`scaleX(-1)`) at the icon-wrapper level. Icons that do not encode direction
(a checkmark, a trash icon, the QAYD mark, any Lucide icon of a static object) are never mirrored.

```css
/* Directional icons only — flip at the wrapper, never the whole layout. */
[dir="rtl"] [data-icon-directional] { transform: scaleX(-1); }
```

## The one place physical alignment is correct

A numeric column stays LTR and end-aligned to its column in both page directions, because a column of
amounts must align on the decimal regardless of whether the surrounding UI reads left-to-right or
right-to-left. This is the sole allow-listed exception to the logical-only rule, and it is delivered by the
`<Amount>` primitive's `dir="ltr"` (see [TYPOGRAPHY.md](./TYPOGRAPHY.md)) plus a `text-end` column, so no
component hardcodes a physical `text-right`.

# Table Density Modes

Every dense screen — General Ledger, Journal Entries, Trial Balance, Balance Sheet, P&L, Cash Flow,
Banking, Bank Reconciliation — holds up at real scale (hundreds to tens of thousands of rows) through three
density modes. Density is **through typography and row height, not through removing structure.**

| Mode | Row height | Cell font | Cell vertical padding | Default on |
|---|---|---|---|---|
| Compact | 32px | `text-xs` (12px) | `--space-xs` (8px) | General Ledger, Journal Entries (> 50 lines) |
| Comfortable | 40px | `text-sm` (14px) | `--space-sm` (12px) | Trial Balance, Banking, most list views (default) |
| Relaxed | 48px | `text-sm` (14px) | `--space-md` (16px) | Customer / Vendor directories, settings lists |

Density is a **per-table, user-persisted preference** — a density toggle in the table's own toolbar, stored
in the user's settings, not a single global app setting — because different tasks on the same day need
different densities. The cell horizontal padding is `--space-sm` (12px) in all modes; only the vertical
padding and font change, so columns stay aligned across a user's density switches within a session.

```tsx
// Row height and cell padding are driven by the active density token, not hardcoded.
const density = useTableDensity(tableId); // "compact" | "comfortable" | "relaxed", user-persisted
const rowClass = {
  compact:     "h-8  text-xs py-2",   // 32px, --space-xs
  comfortable: "h-10 text-sm py-3",   // 40px, --space-sm
  relaxed:     "h-12 text-sm py-4",   // 48px, --space-md
}[density];
```

## Row treatment

QAYD does **not** use fill-based zebra striping — alternating row backgrounds read as busy at density and
fight with the AI-highlight and selection states, which also use background fills. Instead every row gets a
single 1px `ink-6` bottom border, and on tables over ~40 rows every fifth row gets a marginally stronger
`ink-7` border — a callback to ruled ledger paper's grouped rule lines and a genuine scanning aid (it lets
an eye count "five rows down" without a running counter). Both borders are colors, not spacing, but they set
the vertical rhythm the density modes are measured against; see [COLOR_SYSTEM.md](./COLOR_SYSTEM.md).

## Sticky structure spacing

A table with a meaningful running total (a Trial Balance's Debit/Credit totals, a bank register's running
balance) pins that total to a sticky footer with the active mode's cell padding, an `ink-3` background, and
a 1px `ink-7` top border; the total values are set in `display-sm` weight 600 rather than the row-level
figure style, so the total reads as heavier without a new color. Column headers are similarly sticky, in
`text-xs` uppercase `ink-9` with the same horizontal cell padding so header and cell align exactly.

## AI provenance gutter

A row an AI agent proposed or flagged carries a single small (6px) `accent` dot in the row's **leading
gutter** — a `--space-md` (16px) inline-start gutter reserved on AI-capable tables so the dot never crowds
the first cell's content. The gutter is logical (`ps-4` on the row, dot positioned at the inline start), so
it sits on the correct side in RTL automatically.

# Grid, Breakpoints & App Shell

The spacing scale lives inside a responsive grid. QAYD uses Tailwind's default breakpoints plus one custom
ultra-wide step for the large monitors common on accountants' desks.

| Breakpoint | Min width | Layout behavior |
|---|---|---|
| (base) | 0 | Single column, bottom tab bar, tables stack as cards or scroll horizontally within a bordered container |
| `sm` | 640px | Two-column forms allowed; tables scroll horizontally with a sticky first column |
| `md` | 768px | Sidebar becomes a collapsible rail; 12-column grid active for dashboard tiles |
| `lg` | 1024px | Sidebar persistent (264px expanded / 72px icon-only); full table layouts, no horizontal scroll for standard reports |
| `xl` | 1280px | Two-panel layouts (list + detail) side-by-side instead of stacked / drawer |
| `2xl` | 1536px | Content max-width caps at 1440px and centers; dense tables get breathing margin instead of stretching edge-to-edge |
| `3xl` (custom) | 1920px | Content max-width caps at 1600px; used on General Ledger and Trial Balance, which benefit from the extra columns |

```ts
// tailwind.config.ts (excerpt) — the one custom breakpoint
screens: { "3xl": "1920px" },
```

## App-shell metrics

The app shell is constant across breakpoints ≥ `lg`: a **64px topbar** (company switcher, global search, AI
status, notifications, user menu) and a **persistent sidebar** (264px expanded / 72px icon-only collapsed),
with the routed page content in a scrollable region that owns its own horizontal scroll for wide tables
rather than letting the whole page scroll sideways.

| Shell element | Metric | Notes |
|---|---|---|
| Topbar height | 64px | Constant ≥ `lg` |
| Sidebar width (expanded) | 264px | `lg`+ |
| Sidebar width (collapsed) | 72px | Icon-only rail |
| Content max-width | 1440px (`2xl`), 1600px (`3xl`) | Centers; dense tables gain margin, not stretch |
| Page top padding under topbar | `--space-3xl` (64px) on wide, `--space-2xl` (48px) mid, `--space-lg` (24px) base | Scales down with viewport |
| Grid columns (dashboard) | 12 | Active from `md`; tile gap `--space-xl` (32px) |

The 12-column dashboard grid uses a `--space-xl` (32px) gutter between tiles; forms use a two-column layout
from `sm` with a `--space-lg` (24px) column gap and `--space-md` (16px) row gap. Page-level section rhythm
is `--space-2xl` (48px). These are the layout-spacing decisions that give the dense tables their air.

# Usage Rules

**One 4px grid.** Every spacing value is a Tailwind step (a 4px multiple). A literal `gap-[13px]` or
`p-[19px]` is a review-blocking error; use the nearest scale step or propose a semantic alias.

**Semantic aliases for recurring decisions.** Card padding, panel gaps, page rhythm, and shell padding use
the named semantic values so a rhythm change is made once, not hunted across components.

**Logical properties only.** Directional spacing, borders, alignment, and insets are logical (`ms-`, `me-`,
`ps-`, `pe-`, `border-s`, `text-start`, `start-`). Physical `ml-`/`mr-`/`pl-`/`pr-`/`text-left`/`text-right`
is banned by ESLint except the allow-listed numeral-alignment case.

**Numeric columns stay LTR and end-aligned in both directions.** This is the sole physical-alignment
exception, delivered through `<Amount>` and a `text-end` column — never a hardcoded `text-right`.

**Density tightens type and row height, never section whitespace.** To make a screen denser, switch density
mode; do not delete the gaps between sections or panels.

**Density is per-table and user-persisted.** Never wire density to a single global setting; store it per
table in the user's preferences with a toolbar toggle.

**Component-internal vs layout spacing stay in their bands.** Internal padding lives in `2xs`–`lg`; gaps
between components live in `lg`–`3xl`. The `lg` overlap is intentional and shared.

**Directional icons flip; static icons never.** Mirror chevrons and back-arrows at the icon wrapper; never
mirror a checkmark, a trash icon, or the QAYD mark.

# Do & Don't

| Do | Don't |
|---|---|
| Use Tailwind's 4px steps for every space | Drop in a `gap-[13px]` magic number |
| Name recurring spacings with semantic aliases | Re-derive card padding by hand in every component |
| Use logical `ms-`/`me-`/`ps-`/`pe-`/`border-s` | Use physical `ml-`/`mr-`/`pl-`/`pr-`/`border-l` |
| Keep numeric columns LTR and end-aligned in RTL too | Let a bidi reflow scramble an amount column |
| Switch density mode to tighten a table | Delete section whitespace to fit more on screen |
| Store density per table in user preferences | Wire density to one global app setting |
| Keep internal padding in the 2xs–lg band | Pad a card with a page-level `3xl` gutter |
| Space page sections with `2xl` (48px) rhythm | Cram panels together with `xs` gaps |
| Mirror only direction-encoding icons | Flip a checkmark or the brand mark in RTL |
| Reserve a logical leading gutter for the AI dot | Crowd the first cell with the AI provenance dot |

# End of Document
