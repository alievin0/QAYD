# Spacing Scale — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Foundations / SPACING_SCALE
---

# Purpose

This document is the **atomic spacing-scale reference** for QAYD — the flat, exhaustive table of every step
on the 4px base (its Tailwind step, px, and rem value), the semantic aliases layered on top, and the
logical-property utilities that make every measurement mirror between English (LTR) and Arabic (RTL). It is a
foundation-layer document: where [../SPACING_SYSTEM.md](../SPACING_SYSTEM.md) explains *why* the system is
shaped the way it is (density with air, the three table density modes, the component-internal vs layout
distinction, the app-shell metrics), this document is the specialization you reach for when you already know
the rules and just need the value of a step and the token that names it.

QAYD is an AI Financial Operating System whose screens live or die on how well they hold **density with
air.** Finance work is inherently dense — a General Ledger can be thousands of lines — but density is never
clutter: QAYD achieves density through smaller, disciplined type and tighter row heights, **never** through
removing the whitespace that separates one idea from the next. Every space in QAYD is a multiple of **4px**
on Tailwind's default scale — there is no parallel spacing system, no `gap-[13px]`, no magic number — and all
directional spacing uses logical properties so a component written once behaves correctly in both directions
with zero conditional code.

This document covers: the 4px base unit; the full raw scale as a table; the semantic aliases and when to use
each; the logical-property mapping for RTL; the component-internal vs layout bands; the three table density
modes; and the usage rules that keep rhythm consistent. Every rule is stated for both directions.

Related reading: [../SPACING_SYSTEM.md](../SPACING_SYSTEM.md) (the full spacing rulebook this document
indexes), [../DESIGN_TOKENS.md](../DESIGN_TOKENS.md) (the aggregate token catalogue),
[./TYPOGRAPHY_SCALE.md](./TYPOGRAPHY_SCALE.md) (the type sizes and row heights these density modes pair
with), and [./COLORS.md](./COLORS.md) (the border and surface colors that structure the spaced regions).

# The Scale

The base unit is **4px**; every spacing token is a multiple of it. QAYD adopts Tailwind's default 4px scale
unmodified (`p-1` = 4px through `p-32` = 128px) rather than inventing a parallel one, and layers semantic
aliases on top for the values that recur as *decisions*.

## Full raw scale

| Tailwind step | px | rem | Common use |
|---|---|---|---|
| `0` | 0px | 0 | Reset |
| `0.5` | 2px | 0.125rem | Hairline nudge, filled status-dot inset |
| `1` | 4px | 0.25rem | `--space-2xs` |
| `1.5` | 6px | 0.375rem | Badge padding |
| `2` | 8px | 0.5rem | `--space-xs` |
| `2.5` | 10px | 0.625rem | Button vertical padding (small) |
| `3` | 12px | 0.75rem | `--space-sm` |
| `4` | 16px | 1rem | `--space-md` |
| `5` | 20px | 1.25rem | Comfortable button block padding |
| `6` | 24px | 1.5rem | `--space-lg` |
| `8` | 32px | 2rem | `--space-xl` |
| `10` | 40px | 2.5rem | Icon-button hit target, control row height |
| `12` | 48px | 3rem | `--space-2xl`, Relaxed row height |
| `16` | 64px | 4rem | `--space-3xl` |
| `20` | 80px | 5rem | Large empty-state vertical padding |
| `24` | 96px | 6rem | Marketing-section rhythm |
| `32` | 128px | 8rem | Max page-section separation |

## Semantic aliases

The eight aliases name the values that recur as decisions, so a rhythm change is made once. They resolve onto
the raw Tailwind steps above.

| Semantic token | px | rem | Tailwind step | Usage |
|---|---|---|---|---|
| `--space-2xs` | 4px | 0.25rem | `1` | Icon-to-label gap, chip internal padding |
| `--space-xs` | 8px | 0.5rem | `2` | Tight stack gap, table cell vertical padding (compact) |
| `--space-sm` | 12px | 0.75rem | `3` | Form field internal padding, cell vertical padding (comfortable) |
| `--space-md` | 16px | 1rem | `4` | Default stack gap, card internal padding (small card) |
| `--space-lg` | 24px | 1.5rem | `6` | Card internal padding (default), section gap within a panel |
| `--space-xl` | 32px | 2rem | `8` | Gap between panels / cards on a page |
| `--space-2xl` | 48px | 3rem | `12` | Page-level section gap |
| `--space-3xl` | 64px | 4rem | `16` | Page top padding under the topbar on wide screens |

## Component-internal vs layout bands

The scale serves two jobs, and keeping them separate is what keeps rhythm consistent. **Component-internal**
spacing is padding and gaps *inside* one component; **layout** spacing is the gaps *between* components on a
page.

| Job | Band | Examples |
|---|---|---|
| Component-internal | `2xs`–`lg` (4–24px) | Card padding `lg` (24px); small card `md` (16px); form field `sm` (12px); chip `2xs` (4px); icon-to-label `2xs` (4px) |
| Layout | `lg`–`3xl` (24–64px) | Section gap within a panel `lg` (24px); gap between panels `xl` (32px); page section gap `2xl` (48px); page top padding `3xl` (64px on wide) |

The two overlap at `--space-lg` (24px) **by design** — it is both the default card's internal padding and
the gap between sections within a panel, which is why a panel of cards reads as one coherent rhythm.

## Table density modes

Every dense screen holds up at real scale through three density modes. Density is through typography and row
height, **not** through removing structure. The cell *horizontal* padding is `--space-sm` (12px) in all
modes; only the vertical padding and font change, so columns stay aligned across a user's density switches.

| Mode | Row height | Cell font | Cell vertical padding | Default on |
|---|---|---|---|---|
| Compact | 32px (`h-8`) | `text-xs` (12px) | `--space-xs` (8px, `py-2`) | General Ledger, Journal Entries (> 50 lines) |
| Comfortable | 40px (`h-10`) | `text-sm` (14px) | `--space-sm` (12px, `py-3`) | Trial Balance, Banking, most lists (default) |
| Relaxed | 48px (`h-12`) | `text-sm` (14px) | `--space-md` (16px, `py-4`) | Customer / Vendor directories, settings lists |

Density is a **per-table, user-persisted preference** — a toggle in the table's own toolbar, stored in the
user's settings — not a single global app setting.

# Tokens & Naming

## Scale as CSS variables

The semantic aliases exist for CSS outside Tailwind's reach (a Framer Motion `style` prop, a canvas gutter,
chart SVG padding). Inside components, use the Tailwind step directly — the step *is* the token, so `p-6` and
`--space-lg` are the same 24px decision expressed in two contexts.

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

## Logical properties for RTL

QAYD mirrors layout direction using **CSS logical properties exclusively** — never physical left/right — so a
component written once mirrors automatically under `dir="rtl"`. The logical utilities are **enforced by an
ESLint rule** that flags any physical class outside a short, reviewed allow-list.

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

```tsx
// Correct — logical, mirrors automatically with dir="rtl"
<div className="ms-4 me-2 ps-6 border-s">

// Wrong — physical, will not mirror, banned in review
<div className="ml-4 mr-2 pl-6 border-l">
```

**The one allow-listed exception:** a numeric column stays LTR and end-aligned to its column in both page
directions, because a column of amounts must align on the decimal regardless of page direction. This is
delivered by the `<Amount>` primitive's `dir="ltr"` (see [./TYPOGRAPHY_SCALE.md](./TYPOGRAPHY_SCALE.md)) plus
a `text-end` column — never a hardcoded `text-right`.

Directional icons (a chevron meaning "next," a "back" arrow) flip via a wrapper transform; static icons (a
checkmark, a trash icon, the QAYD mark) are never mirrored.

```css
/* Directional icons only — flip at the wrapper, never the whole layout. */
[dir="rtl"] [data-icon-directional] { transform: scaleX(-1); }
```

# Usage

**The canonical card** — all internals are component-internal spacing (`lg` padding, `md` header-to-body):

```tsx
<div className="rounded-lg border border-ink-6 bg-ink-2 p-6 shadow-xs dark:bg-ink-3">
  <div className="flex items-center justify-between mb-4">   {/* header→body: --space-md */}
    <h3 className="font-display text-display-sm text-ink-12">Cash Position</h3>
    <Button variant="ghost" size="icon"><MoreHorizontal className="size-4" /></Button>
  </div>
  <p className="font-display numeral-hero tabular-nums text-ink-12">KD 84,210.500</p>
  <p className="text-text-sm text-positive mt-1">+2.4% vs last month</p>  {/* delta: --space-2xs */}
</div>
```

**The canonical page region** — all gaps are layout spacing (`2xl` between sections, `xl` between tiles):

```tsx
<main className="px-6 pt-16 pb-12">          {/* pt-16 = --space-3xl under the topbar on wide */}
  <section className="space-y-12">            {/* --space-2xl between page sections */}
    <div className="grid grid-cols-12 gap-8"> {/* --space-xl between dashboard tiles */}
      {/* ...cards... */}
    </div>
  </section>
</main>
```

**Density is driven by the active token, not hardcoded:**

```tsx
const density = useTableDensity(tableId); // "compact" | "comfortable" | "relaxed", user-persisted
const rowClass = {
  compact:     "h-8  text-xs py-2",   // 32px, --space-xs
  comfortable: "h-10 text-sm py-3",   // 40px, --space-sm
  relaxed:     "h-12 text-sm py-4",   // 48px, --space-md
}[density];
```

**The AI provenance gutter** is a logical `--space-md` (16px) inline-start gutter (`ps-4` on the row) so the
6px `accent` dot never crowds the first cell and sits on the correct side in RTL automatically.

# Do / Don't

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

# Accessibility Notes

**Hit targets.** Interactive controls meet a minimum touch target on the scale: an icon-button uses step
`10` (40px) as its hit area, and the Comfortable/Relaxed row heights (40px/48px) keep row-level actions
comfortably tappable. Compact density (32px rows) is a scanning mode for expert desktop use; row-level tap
targets in Compact still expand their hit area to 40px via padding even though the visual row is 32px.

**Density serves accessibility, not against it.** Because density is a per-table, user-persisted choice, a
user who needs more space (motor or low-vision needs) sets Relaxed and keeps it, while a power user scanning
4,000 bank lines sets Compact — the same screen serves both without a global compromise. Density never
removes the whitespace between sections, so the visual grouping that aids comprehension survives every
density mode.

**Logical properties are an accessibility feature.** Because all directional spacing is logical, the Arabic
(RTL) layout is a true mirror rather than a bolted-on afterthought, and screen-reader reading order follows
the visual order in both directions. The sole physical exception — the LTR numeric column — is itself a
correctness measure that keeps amounts from being misread when the bidi algorithm would otherwise reorder
them.

**Reduced-motion and rhythm.** Spacing is static and unaffected by `prefers-reduced-motion`, but the layout
rhythm it establishes is what lets a reduced-motion user orient without entrance animation: clear section
gaps (`2xl`) and consistent card padding (`lg`) carry the structure that motion would otherwise reinforce.

# End of Document
