# Radius Scale — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Foundations / RADIUS
---

# Purpose

This document is the atomic corner-radius reference for QAYD: the five-step token scale, the **10px
rectangular ceiling** that is its defining constraint, the per-component radius assignments, the one-step
nesting rule for concentric surfaces, and the RTL logical-corner rules. It is the primitive-level "how
round" reference — a single small table an author consults to answer "what radius does this get" without
re-deriving it per screen.

It **specializes** [../BORDER_RADIUS.md](../BORDER_RADIUS.md), which carries the full narrative rationale,
the per-component table, and the reconciliation history; this document restates the same values as a tight
foundation reference and does not diverge from them. The visual source of truth upstream of both is
[../../frontend/DESIGN_LANGUAGE.md](../../frontend/DESIGN_LANGUAGE.md#corner-radius). The catalogue entry is
in [../DESIGN_TOKENS.md](../DESIGN_TOKENS.md#radius). Radius pairs with elevation on every surface — see
[./SHADOW_SCALE.md](./SHADOW_SCALE.md) — and its nesting rule interacts with the card-composition limits in
[../SPACING_SYSTEM.md](../SPACING_SYSTEM.md).

Radius is a **taste decision** in QAYD, not an incidental one. The founder direction is explicit —
car/fashion-brand editorial restraint — and radius is where a finance UI most easily drifts toward
"consumer app." Large radii read as playful (Notion, Duolingo, mobile-first social); a tight 4–10px range
reads as engineered and precise. A Bentley console does not have rounded-off corners, and neither should a
Trial Balance. The whole scale is built around a hard ceiling well below the "friendly rounded SaaS"
starting point.

# The Scale

QAYD's rectangular surfaces cap at **10px**. Only genuinely circular / pill shapes go fully round. Five
steps, no more:

| Token | Value | CSS variable | Role |
|---|---|---|---|
| `radius-sm` | 4px | `--radius-sm` | Checkboxes, small tags/badges, table-cell inline chips, tooltips |
| `radius-md` | 6px | `--radius-md` | Inputs, buttons, select triggers, segmented-control cells |
| `radius-lg` | 8px | `--radius-lg` | Cards, panels, dropdown/popover menus, modal inner content — **the workhorse** |
| `radius-xl` | 10px | `--radius-xl` | Modal/dialog outer surface and edge-attached sheet only — the ceiling |
| `radius-full` | 9999px | `--radius-full` | Avatars, status dots, pill badges — the *only* fully-round shapes |

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

## The 10px rectangular ceiling

The **10px cap on any rectangular surface** is a deliberate, load-bearing constraint, not a default left
unexamined. An earlier foundation draft specified 20–24px card radii and a 12px sheet; this scale
supersedes both. Anything softer than 10px on a rectangle — `rounded-2xl` (16px), a 12px dialog, a 20px
"friendly" card — is out of the system and is drift to reconcile back, never precedent to extend. The
ceiling is the entire point of the scale: it is what keeps the product reading as engineered rather than
consumer. `radius-full` is not an exception to the ceiling because it applies only to shapes that are
*inherently* circular (an avatar, a status dot, a pill badge), never to a rectangular panel.

# Tokens & Naming

Radius primitives follow the QAYD token chain
([../DESIGN_TOKENS.md](../DESIGN_TOKENS.md#token-tiers--naming-convention)): a `--radius-{step}` primitive
is declared once in `globals.css`, mapped to a Tailwind `rounded-{step}` utility, and consumed by name.

| Layer | Pattern | Example | Value |
|---|---|---|---|
| Radius primitive | `--radius-{step}` | `--radius-lg` | `8px` (literal px) |
| Tailwind utility | `rounded-{step}` | `rounded-lg` | `var(--radius-lg)` |
| Logical one-side utility | `rounded-s-{step}` / `rounded-e-{step}` | `rounded-e-xl` | start/end corners only |

Note the scale intentionally reuses the `sm/md/lg/xl` step names shared across the token system, but its
**values are 4/6/8/10** — not Tailwind's stock `rounded-lg` (8px happens to match) / `rounded-xl` (12px,
which QAYD overrides down to 10px). The mapping above is what makes `rounded-xl` mean 10px in QAYD, not
Tailwind's default 12px. Where an older document or screen named `radius-lg` as 12px, the **value** here
(8px) governs and the step name follows DESIGN_LANGUAGE; see the reconciliation note in
[../BORDER_RADIUS.md](../BORDER_RADIUS.md).

# Usage

Radius is assigned **per component class** so every button in the product shares one corner and every card
shares another — consistency compounds, and a one-off radius is a withdrawal against that trust. The full
assignment table is in [../BORDER_RADIUS.md](../BORDER_RADIUS.md#per-component-radius); the load-bearing
assignments:

| Component | Token | Value |
|---|---|---|
| Button (all variants, all sizes, icon buttons included) | `radius-md` | 6px |
| Input / textarea / select trigger | `radius-md` | 6px |
| Segmented control (outer) | `radius-md` | 6px (active inner cell `radius-sm`, 4px) |
| Checkbox | `radius-sm` | 4px (radio is `radius-full` — it *is* circular) |
| Tag / badge / table-cell chip | `radius-sm` | 4px |
| Card / panel / section surface | `radius-lg` | 8px |
| Dropdown / popover / select menu / ⌘K palette | `radius-lg` | 8px |
| Modal / dialog inner content | `radius-lg` | 8px |
| Modal / dialog outer surface | `radius-xl` | 10px |
| Sheet / drawer panel (free corners only) | `radius-xl` | 10px |
| Toast | `radius-lg` | 8px |
| Avatar / status dot / pill badge | `radius-full` | 9999px |
| Tooltip | `radius-sm` | 4px |

## Buttons are never pills

The single most common way a finance UI drifts toward "consumer app" is a fully-rounded (`radius-full`)
primary button. QAYD buttons are `radius-md` (6px), **always**. `radius-full` is reserved for shapes that
are inherently circular (avatars, status dots) or that carry a count/status as a pill *badge* — never for
an action control.

## The nesting rule

When a rounded surface sits inside another, the **inner radius steps down exactly one token** so the two
curves stay concentric and the inner element does not appear to bulge past its container's corner. The
geometric intuition is `inner ≈ outer − padding`; QAYD's 4/6/8/10 steps are spaced so one step down lands
close to that ideal at the system's typical padding, without asking an author to compute a per-instance
value.

| Outer surface | Outer | Nested element | Inner |
|---|---|---|---|
| Dialog outer (`radius-xl`) | 10px | Dialog inner content panel | `radius-lg` (8px) |
| Card (`radius-lg`) | 8px | Input / button inside it | `radius-md` (6px) |
| Card (`radius-lg`) | 8px | Nested "Suggested by AI" callout | `radius-md` (6px) |
| Segmented control (`radius-md`) | 6px | Active cell | `radius-sm` (4px) |
| Input (`radius-md`) | 6px | Inline chip inside the input | `radius-sm` (4px) |

Nesting deeper than one level is itself a smell (see the card-nesting limit in
[../SPACING_SYSTEM.md](../SPACING_SYSTEM.md)), so in practice this rule governs a single containment step.
When in doubt, step down exactly one token: never match the outer radius on a nested element (it reads as
bulging), and never jump two steps (it reads as a disconnected inner box).

```tsx
// A nested AI callout inside a form-section card — inner steps down 8px → 6px.
<div className="rounded-lg border border-ink-6 bg-ink-2 p-6">        {/* card, 8px */}
  <div className="rounded-md border-s-2 border-s-accent bg-accent-subtle p-4"> {/* callout, 6px */}
    Suggested by the Accountant Agent — 92% confidence.
  </div>
</div>
```

## Clipping full-bleed children

A surface's radius and its border live on the same element so the border follows the curve. A full-bleed
child that would paint to the parent's square corner (a table filling a rounded card) is clipped with
`overflow-hidden` on the rounded parent, so the child inherits the parent's 8px rather than re-declaring a
radius that could drift.

# RTL Logical Corners

Corner radius is one of the few visual properties that is genuinely **symmetric** across reading
direction, and QAYD treats it accordingly.

- **A uniformly rounded surface never changes under `dir="rtl"`.** A card with `rounded-lg` on all four
  corners is identical LTR and RTL; there is nothing to mirror, so the common case needs no logical
  property.
- **A surface rounded on only one side must use logical corner utilities**, so the rounding follows the
  reading-start / reading-end edge instead of a fixed physical side:

  | Physical (banned) | Logical (required) | Meaning |
  |---|---|---|
  | `rounded-l-*` | `rounded-s-*` | Round the start-side corners (top-start + bottom-start) |
  | `rounded-r-*` | `rounded-e-*` | Round the end-side corners (top-end + bottom-end) |

  These are enforced by the same `eslint-plugin-tailwindcss` restricted-list rule that bans physical
  margin/padding/inset utilities across QAYD (see
  [../SPACING_SYSTEM.md](../SPACING_SYSTEM.md#logical-spacing-for-rtl)).

- **Edge-attached panels round only their free corners, logically.** A `Sheet` sliding in from the logical
  start edge rounds its two *end*-side corners (`rounded-e-xl`) and leaves the two edge-flush corners
  square. Because the slide side is itself resolved against `dir`
  (`side={dir === "rtl" ? "right" : "left"}`), `rounded-e-*` keeps the free/rounded corners on the correct
  physical side automatically in both directions.

- **A segmented control's first and last cell** round their outer corners with `rounded-s-*` /
  `rounded-e-*` so the group's rounded ends stay on the reading-start and reading-end edges when the
  control mirrors.

```tsx
// Start-edge Sheet: free (inner) corners rounded via logical utility, edge-flush corners square.
<SheetContent side={dir === "rtl" ? "right" : "left"} className="rounded-e-xl">
  {/* ... */}
</SheetContent>
```

Numeric and Debit/Credit content that is deliberately *not* mirrored (the numeral-alignment exception in
[../SPACING_SYSTEM.md](../SPACING_SYSTEM.md#the-one-place-physical-alignment-is-correct)) has no bearing on
radius: that exception governs alignment and column order, and any rounded chrome around such content still
follows the logical corner rules above.

# Do / Don't

| Do | Don't |
|---|---|
| Keep every rectangular surface ≤ 10px | Use 12px, 16px (`rounded-2xl`), or 20px+ "friendly" cards |
| Use `radius-md` (6px) for buttons and inputs | Make buttons `radius-full` pills |
| Reserve `radius-full` for avatars, status dots, and pill badges | Round a rectangular panel fully |
| Use `radius-lg` (8px) as the default card / menu corner | Invent a per-screen one-off radius |
| Step the inner radius down exactly one token when nesting | Match the outer radius on a nested element (it bulges) |
| Clip a full-bleed child to a rounded parent with `overflow-hidden` | Let a child re-declare a radius that drifts from its container |
| Use logical `rounded-s-*` / `rounded-e-*` for one-sided rounding | Use physical `rounded-l-*` / `rounded-r-*` |
| Round an edge-attached sheet on its free corners only | Round all four corners of an edge-flush panel |

# Accessibility Notes

Radius is a low-risk property for accessibility, but three points connect it to the broader a11y contract
in [../ACCESSIBILITY.md](../ACCESSIBILITY.md):

- **Radius never carries meaning on its own.** No state (selected, error, AI-flagged, disabled) is
  signaled by a corner change; those states use color, a border treatment, an icon, and text. A user who
  cannot perceive a 2px difference in corner rounding loses no information, because rounding is decorative,
  not semantic.
- **A rounded surface still needs a real separator.** Radius makes a 1px border read as a deliberate edge
  rather than a boxy outline, but the *separation* is carried by the `ink-6` border, not the radius — so
  the layering survives Windows High Contrast mode, where custom radii and backgrounds may be overridden
  but the border remains. See the border-versus-shadow rule in [./SHADOW_SCALE.md](./SHADOW_SCALE.md).
- **Focus rings respect the corner.** The accent focus ring is drawn with a non-zero offset around the
  control's own radius, so a 6px button shows a ring that traces its 6px corner rather than a mismatched
  square or full-round halo — keeping the focused element's shape legible for keyboard users.

# End of Document
