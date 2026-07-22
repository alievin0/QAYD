# Border Radius — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: BORDER_RADIUS
---

# Purpose

This document is the canonical corner-radius scale for QAYD: the token values, the radius assigned to
each component class (buttons, cards, inputs, modals, pills, tags), the nesting rule for concentric
rounded surfaces, RTL corner symmetry, and the do/don't discipline that keeps QAYD from looking like a
consumer app.

It mirrors the radius scale defined in
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md#corner-radius), which is the visual
source of truth. Radius interacts with elevation and surface layering — see
[ELEVATION_SHADOWS.md](ELEVATION_SHADOWS.md) — and with the grid's card composition rules — see
[GRID_SYSTEM.md](GRID_SYSTEM.md#dense-table-layout).

Radius is a taste decision in QAYD, not an incidental one. The founder direction is explicit:
car/fashion-brand editorial restraint. Large radii read as consumer and playful (Notion, Duolingo,
mobile-first social apps); a tight range reads as engineered and precise. A Bentley's console does not
have rounded-off corners, and neither should a Trial Balance. The whole scale is built around a hard
ceiling well below the "friendly rounded SaaS" starting point.

# The Radius Scale

QAYD's rectangular surfaces cap at **10px**. Only genuinely circular/pill shapes go fully round. The
scale has five steps:

| Token | Value | CSS variable | Usage |
|---|---|---|---|
| `radius-sm` | 4px | `--radius-sm` | Checkboxes, small tags/badges, table-cell inline chips |
| `radius-md` | 6px | `--radius-md` | Inputs, buttons, select triggers, segmented-control cells |
| `radius-lg` | 8px | `--radius-lg` | Cards, panels, dropdown/popover menus, a modal's inner content — the workhorse radius |
| `radius-xl` | 10px | `--radius-xl` | Modal/dialog outer surface only, marginally softer than a card |
| `radius-full` | 9999px | `--radius-full` | Avatars, status dots, pill badges — the *only* fully-rounded shapes in the system |

```css
/* app/globals.css — mirrors DESIGN_LANGUAGE.md */
:root {
  --radius-sm: 4px;
  --radius-md: 6px;
  --radius-lg: 8px;
  --radius-xl: 10px;
  --radius-full: 9999px;
}
```

```ts
// tailwind.config.ts (excerpt)
borderRadius: {
  sm: "var(--radius-sm)", // 4px
  md: "var(--radius-md)", // 6px
  lg: "var(--radius-lg)", // 8px
  xl: "var(--radius-xl)", // 10px
  // 'full' resolves to Tailwind's 9999px default
},
```

The **10px ceiling on any rectangular surface** is a deliberate, load-bearing constraint, not a
default left unexamined. An earlier foundation draft specified 20–24px card radii; this scale
supersedes it. Anything softer than 10px on a rectangle is out of the system.

> Reconciliation note: [../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md#surface-tokens)
> published an alternate naming (`--radius-xs` 4px, `--radius-sm` 6px, `--radius-md` 8px, `--radius-lg`
> 12px) where `Dialog`/`Sheet` panels used **12px**. This document adopts
> [../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md)'s scale and its **10px ceiling** as
> canonical: the design language declares itself the source of truth for radius, and 12px crosses the
> editorial ceiling that is the entire point of the scale. Where the two naming schemes disagree, the
> **values** in the table above win; the assigned *step name* (`radius-lg` = 8px, not 12px) follows
> DESIGN_LANGUAGE. Screens or examples still using `rounded-2xl` (16px) or a 12px sheet are drift to
> reconcile back to this scale, not precedent to extend.

# Per-Component Radius

Radius is assigned per component class so that every button in the product shares one corner and every
card shares another — consistency compounds, and a one-off radius is a withdrawal against that trust.

| Component | Token | Value | Notes |
|---|---|---|---|
| Button (all variants, all sizes) | `radius-md` | 6px | Icon buttons included; a button is never `radius-full` — pill buttons read as consumer |
| Input / textarea / select trigger | `radius-md` | 6px | Matches buttons so an input+button row shares one corner language |
| Segmented control (outer) | `radius-md` | 6px | Inner active cell uses `radius-sm` (4px), nested one step down |
| Checkbox | `radius-sm` | 4px | Radio uses `radius-full` (it *is* circular) |
| Tag / badge (rectangular) | `radius-sm` | 4px | Status badges, count chips |
| Table-cell inline chip | `radius-sm` | 4px | e.g. an account-type chip inside a ledger cell |
| Card / panel / section surface | `radius-lg` | 8px | The workhorse; KPI tiles, form-section cards, AI-insight cards |
| Dropdown / popover / select menu / combobox list | `radius-lg` | 8px | Floating menus match cards |
| Command palette (⌘K) surface | `radius-lg` | 8px | Glass surface, same corner as a card |
| Modal / dialog inner content | `radius-lg` | 8px | Content region inside the outer dialog surface |
| Modal / dialog outer surface | `radius-xl` | 10px | The one place the ceiling is used; marginally softer than a card so the outermost floating plane reads as "above" |
| Sheet / drawer panel | `radius-xl` | 10px (edge-attached corners only) | A sheet attached to an edge rounds only its two free (inner) corners; the two edge-flush corners are square — see RTL Corner Symmetry |
| Toast | `radius-lg` | 8px | Matches cards |
| Avatar / status dot / pill badge | `radius-full` | 9999px | The only fully-round shapes permitted |
| Tooltip | `radius-sm` | 4px | Small, transient, tight |
| Skeleton placeholder | inherits target | — | A skeleton mirrors the radius of the element it stands in for (a card skeleton is 8px, a pill skeleton is round) |

## Buttons are never pills

The single most common way a finance UI drifts toward "consumer app" is a fully-rounded (`radius-full`)
primary button. QAYD buttons are `radius-md` (6px), always. `radius-full` is reserved for shapes that
are inherently circular (avatars, status dots) or that carry a count/status as a pill *badge* — never
for an action control.

# The Radius-Nesting Rule

When a rounded surface sits inside another rounded surface, the **inner radius is one step smaller than
the outer** so the two curves stay visually concentric and the inner element does not appear to "bulge"
past its container's corner. Nesting deeper than one level is itself a smell (see the card-nesting rule
in [GRID_SYSTEM.md](GRID_SYSTEM.md) and
[../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md#nesting-rule)), so in practice this rule
governs a single containment step.

| Outer surface | Outer radius | Nested element | Inner radius |
|---|---|---|---|
| Dialog outer (`radius-xl`, 10px) | 10px | Dialog inner content panel | `radius-lg` (8px) |
| Card (`radius-lg`, 8px) | 8px | Input / button inside it | `radius-md` (6px) |
| Card (`radius-lg`, 8px) | 8px | Nested "Suggested by AI" callout card | `radius-md` (6px) |
| Segmented control (`radius-md`, 6px) | 6px | Active cell | `radius-sm` (4px) |
| Input (`radius-md`, 6px) | 6px | Inline tag/chip inside the input | `radius-sm` (4px) |

The geometric intuition: for concentric corners, `inner_radius ≈ outer_radius − padding`. QAYD's steps
(4 / 6 / 8 / 10) are spaced so that one step down from any level lands close to that ideal at the
system's typical padding values, without asking authors to compute a per-instance radius. When in doubt,
step down exactly one token; never match the outer radius on a nested element (it will read as bulging),
and never jump two steps (it will read as a disconnected inner box).

```tsx
// A nested AI callout inside a form-section card — inner steps down 8px → 6px
<div className="rounded-lg border border-ink-6 bg-ink-2 p-6"> {/* card, 8px */}
  <div className="rounded-md border-s-2 border-s-accent bg-accent-subtle p-4"> {/* callout, 6px */}
    Suggested by the Accountant Agent — 92% confidence.
  </div>
</div>
```

# RTL Corner Symmetry

Corner radius is one of the few visual properties that is genuinely **symmetric** across reading
direction, and QAYD treats it accordingly.

- **A uniformly rounded surface never changes under `dir="rtl"`.** A card with `rounded-lg` on all four
  corners is identical in LTR and RTL; there is nothing to mirror, so no logical property is needed for
  the common case.
- **A surface rounded on only one side must use logical corner utilities**, so the rounding follows the
  reading-start / reading-end edge instead of a fixed physical side:

  | Physical (banned) | Logical (required) | Meaning |
  |---|---|---|
  | `rounded-l-*` | `rounded-s-*` | Round the start-side corners (top-start + bottom-start) |
  | `rounded-r-*` | `rounded-e-*` | Round the end-side corners (top-end + bottom-end) |

  These are enforced by the same `eslint-plugin-tailwindcss` restricted-list rule that bans physical
  margin/padding/inset utilities across QAYD; see
  [../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md#rtl-layout-mirroring).

- **Edge-attached panels round only their free corners, logically.** A `Sheet` sliding in from the
  logical start edge rounds its two *end*-side corners (`rounded-e-xl`) and leaves the two start-side
  corners square where the panel meets the viewport edge. Because the slide direction itself is
  resolved against `dir` (`side={dir === 'rtl' ? 'right' : 'left'}` for a "start" sheet), using
  `rounded-e-*` keeps the free/rounded corners on the correct physical side automatically in both
  directions.

- **A segmented control's first and last cell** round their outer corners with `rounded-s-*` /
  `rounded-e-*` so the group's rounded ends stay on the reading-start and reading-end edges when the
  control mirrors.

```tsx
// Start-edge Sheet: free (inner) corners rounded via logical utility, edge-flush corners square
<SheetContent side={dir === "rtl" ? "right" : "left"} className="rounded-e-xl">
  {/* ... */}
</SheetContent>
```

Numeric and Debit/Credit content that is deliberately *not* mirrored (see the two grid exceptions in
[GRID_SYSTEM.md](GRID_SYSTEM.md#logical-property-alignment-for-rtl)) has no bearing on radius: those
exceptions govern alignment and column order, and any rounded chrome around them still follows the
logical corner rules above.

# Radius, Elevation, and Borders Together

QAYD builds separation primarily from hairline borders, not shadow depth, and radius is what makes a
1px border read as a deliberate edge rather than a boxy outline. A few combined rules:

- A bordered rectangular surface always carries a radius from the scale — a square-cornered 1px border
  reads as a raw table cell or a debug outline, not a component.
- A surface's radius and its border must live on the same element so the border follows the curve; a
  child that paints to the parent's square corner (e.g. a full-bleed table inside a rounded card) is
  clipped with `overflow-hidden` on the rounded parent so the child's corners inherit the parent's
  8px, rather than the child re-declaring a radius that could drift.
- Elevated floating surfaces (menus, dialogs) pair their radius with the appropriate shadow token from
  [ELEVATION_SHADOWS.md](ELEVATION_SHADOWS.md) — an 8px menu with `shadow-sm`, a 10px dialog with
  `shadow-lg` — never a heavier shadow to "compensate" for the restrained radius.

# Do & Don't

| Do | Don't |
|---|---|
| Keep every rectangular surface ≤ 10px | Use 12px, 16px (`rounded-2xl`), or 20px+ "friendly" cards |
| Use `radius-md` (6px) for buttons and inputs | Make buttons `radius-full` pills |
| Reserve `radius-full` for avatars, status dots, and pill badges | Round a rectangular panel fully |
| Step the inner radius down exactly one token when nesting | Match the outer radius on a nested element (it bulges) |
| Use `radius-lg` (8px) as the default card/menu corner | Invent a per-screen one-off radius |
| Use logical `rounded-s-*` / `rounded-e-*` for one-sided rounding | Use physical `rounded-l-*` / `rounded-r-*` |
| Round an edge-attached sheet on its free corners only | Round all four corners of an edge-flush panel |
| Clip a full-bleed child to a rounded parent with `overflow-hidden` | Let a child re-declare a radius that can drift from its container |

# End of Document
