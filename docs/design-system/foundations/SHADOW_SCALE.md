# Shadow Scale — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Foundations / SHADOW
---

# Purpose

This document is the atomic shadow and elevation reference for QAYD: the four-step shadow token scale with
its exact CSS values, the fixed surface levels 0–3 plus `surface-glass`, the differing light- and dark-mode
treatment, and the **border-versus-shadow** separation rule that keeps QAYD editorial-flat rather than
Material-heavy. It is the primitive-level "how much lift" reference an author consults to answer "what
shadow does this surface get" without inventing depth per screen.

It **specializes** [../ELEVATION_SHADOWS.md](../ELEVATION_SHADOWS.md), which carries the full narrative,
the z-index tiers, the directional frozen-column shadow, and the reconciliation history; this document
restates the shadow scale and surface levels as a tight foundation reference and does not diverge from
them. The visual source of truth upstream of both is
[../../frontend/DESIGN_LANGUAGE.md](../../frontend/DESIGN_LANGUAGE.md#elevation--surfaces); the catalogue
entry is in [../DESIGN_TOKENS.md](../DESIGN_TOKENS.md#elevation--surfaces). Radius pairs with every
elevated surface — see [./RADIUS_SCALE.md](./RADIUS_SCALE.md); the surface backgrounds are ink-scale steps
from [../DESIGN_TOKENS.md](../DESIGN_TOKENS.md#color--ink-scale).

QAYD builds elevation primarily from **hairline borders, not shadow depth** — an editorial-flat approach
closer to Linear or Stripe's dashboard than a Material stacked-shadow system. Shadows exist, but they are
quiet enough that removing them would not visibly break the hierarchy on their own; the border does most of
the work. Heavy drop shadows and neumorphic bevels are explicitly rejected as generic-SaaS.

# The Scale

Four shadow steps, tuned so the softest is barely-there and the strongest still reads as "a temporary
plane above the page," never as Material depth. All shadows are cast in the **warm-neutral ink hue**
(`rgba(21, 19, 14, …)`) rather than pure black, so they belong to the same paper-and-ink palette as
everything else.

```css
/* app/globals.css — mirrors DESIGN_LANGUAGE.md */
:root {
  --shadow-xs: 0 1px 2px rgba(21, 19, 14, 0.04);
  --shadow-sm: 0 2px 4px rgba(21, 19, 14, 0.06);
  --shadow-md: 0 8px 16px -4px rgba(21, 19, 14, 0.08);
  --shadow-lg: 0 24px 48px -12px rgba(21, 19, 14, 0.16);
}
```

| Token | Value | Assigned to |
|---|---|---|
| `shadow-xs` | `0 1px 2px rgba(21,19,14,.04)` | Static cards / panels (`surface-1`) — a whisper of lift under a bordered card |
| `shadow-sm` | `0 2px 4px rgba(21,19,14,.06)` | Dropdowns, popovers, selects, tooltips (`surface-2`) |
| `shadow-md` | `0 8px 16px -4px rgba(21,19,14,.08)` | Glass surfaces, drawers/sheets in overlay mode (`surface-glass`) |
| `shadow-lg` | `0 24px 48px -12px rgba(21,19,14,.16)` | Modals / dialogs (`surface-3`) — the strongest lift in the system |

The **negative spread** on `shadow-md` / `shadow-lg` (`-4px`, `-12px`) keeps the shadow tucked under the
surface rather than haloing out around it, which is what lets a large-radius blur still read as restrained
rather than as a Material drop shadow.

# Surface Levels

QAYD has a small, fixed set of surface levels. A surface is defined by three properties **together** — its
background token, its border, and its shadow — not by shadow alone. Most static content sits at levels 0–1,
where a border does all the separating; shadow is reserved for genuinely elevated, *temporary* surfaces
(menus, popovers, dialogs, sheets), never for static page content.

| Surface level | Background (light) | Background (dark) | Border | Shadow | Usage |
|---|---|---|---|---|---|
| `surface-0` | `ink-1` | `ink-1` | — | none | App canvas |
| `surface-1` | `ink-2` / `ink-3` | `ink-3` | 1px `ink-6` | `shadow-xs` | Cards, panels, sidebar |
| `surface-2` | `ink-1` | `ink-3` | 1px `ink-6` | `shadow-sm` | Dropdowns, popovers, selects, date pickers |
| `surface-3` | `ink-1` | `ink-3` | 1px `ink-6` | `shadow-lg` | Modals, dialogs (with an `ink-12`/50% scrim beneath) |
| `surface-glass` | `ink-1` @ 72% + `backdrop-blur(24px)` | same treatment | 1px `ink-6` @ 60% | `shadow-md` | ⌘K command palette + AI Command Center **only** |

Two things to note. First, dark-mode elevated surfaces get *lighter* backgrounds than the canvas, not
darker (`ink-3` sits above `ink-1`) — the physical-light model in [Light vs Dark](#light-vs-dark-treatment)
below. Second, `surface-glass` is intentionally rare: frosted/blurred glass reads as premium precisely
because it is unusual, and `backdrop-blur` is not free on the back-office desktops that make up much of
QAYD's audience. It is reserved for the two moments — the global command palette and the AI Command Center —
where a panel floats conceptually above the entire app. (The topbar carries a restrained `backdrop-blur(8px)`;
it is chrome, not a glass surface, and does not use `surface-glass`.)

Each surface pairs with the radius from [./RADIUS_SCALE.md](./RADIUS_SCALE.md): an 8px card with
`shadow-xs`, an 8px menu with `shadow-sm`, a 10px dialog with `shadow-lg` — never a heavier shadow to
"compensate" for the restrained radius.

# Tokens & Naming

Shadow primitives follow the QAYD token chain
([../DESIGN_TOKENS.md](../DESIGN_TOKENS.md#token-tiers--naming-convention)): a `--shadow-{step}` primitive
is declared once in `globals.css` (and re-declared under `.dark`), mapped to a Tailwind `shadow-{step}`
utility, and consumed by name.

| Layer | Pattern | Example | Value |
|---|---|---|---|
| Shadow primitive | `--shadow-{step}` | `--shadow-lg` | literal `box-shadow` |
| Tailwind utility | `shadow-{step}` | `shadow-lg` | `var(--shadow-lg)` |
| Surface (composite) | `surface-{n}` | `surface-2` | background + border + shadow together |

The four steps map one-to-one onto the surface levels (`xs`→1, `sm`→2, `lg`→3, `md`→glass), so an author
chooses a *surface*, not a bespoke shadow. A reduced two-step alias set in an older layout draft
(`--shadow-sm` = `0 1px 2px`, `--shadow-md` = `0 4px 16px`) maps onto this canonical scale as
`--shadow-sm`→`shadow-sm` (menus) and `--shadow-md`→`shadow-lg` (modals/sheets); see the reconciliation note
in [../ELEVATION_SHADOWS.md](../ELEVATION_SHADOWS.md#shadow-token-scale).

# Usage

**Elevation is a fixed vocabulary, not a free dial.** A surface is one of `surface-0`…`surface-3` or
`surface-glass` — never a bespoke background+border+shadow combination invented per screen.

**Match the shadow to the surface level, not to how much attention a surface "wants."** A card that needs
more prominence gets more whitespace or a heavier type treatment, never a modal-strength shadow.

**Static content → `shadow-xs` at most.** Cards, panels, sidebar, form sections, and table containers sit
at `surface-1`; their `ink-6` border is the separator and the `shadow-xs` is only a whisper. Temporary
floating content (menus, dialogs, sheets) earns `shadow-sm`/`shadow-lg` *in addition to* its border.

**Reserve `surface-glass` for the ⌘K palette and AI Command Center.** Do not glass a panel to make it feel
premium; the effect only works because it is rare, and it costs GPU on low-end back-office hardware.

**Verify dark mode by removing the shadow.** If a dark-mode surface vanishes into the canvas without its
shadow, the fix is the `ink` background step or the border, not a deeper shadow — layering must survive
shadow removal.

**Reference the token, never a literal.** Component code writes `shadow-sm` or a `surface-*` composite; it
never ships a raw `box-shadow: 0 4px …` a token already covers.

```tsx
// Static card (surface-1): border does the separating, shadow-xs is a whisper.
<div className="rounded-lg border border-ink-6 bg-ink-2 p-6 shadow-xs dark:bg-ink-3">…</div>

// Dropdown (surface-2): shadow AND border, because it floats for a moment.
<div className="rounded-lg border border-ink-6 bg-popover shadow-sm">…</div>

// Dialog (surface-3): the strongest lift, plus a scrim.
<div className="rounded-xl border border-ink-6 bg-popover shadow-lg">…</div>
```

# Light vs Dark Treatment

Dark mode is a first-class theme with its own calibrated ramp, not an inverted filter (`next-themes` drives
`class="dark"` on `<html>`). Two elevation rules keep dark mode from feeling like a cheaper, different
product:

**1. Elevation gets lighter, not darker.** A raised card (`ink-3` in dark mode) is *lighter* than the
canvas (`ink-1`) it sits on — how physical light and most professional dark UIs actually behave, and the
opposite of a naive "darker = higher" instinct. Layering is carried by the ink background steps first and
shadow second. Backgrounds stay warm-neutral in dark mode (`ink-1` = `#14130F`, not a cool blue-black), so
the same paper-and-ink warmth carries through both themes.

**2. Shadows are lighter and subtler in dark mode.** A light-mode shadow reads as "too heavy" against a
dark background, because there is less ambient contrast for a soft shadow to sit inside. Under `.dark`,
every shadow token is redefined with `rgba(0, 0, 0, …)` at roughly **half** the light-mode alpha:

```css
.dark {
  --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.20);
  --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.24);
  --shadow-md: 0 8px 16px -4px rgba(0, 0, 0, 0.32);
  --shadow-lg: 0 24px 48px -12px rgba(0, 0, 0, 0.40);
}
```

The practical consequence: in dark mode a dropdown or dialog is legible against the canvas primarily
because its `ink-3` surface is a step lighter and its `ink-6` border is visible, with the softened shadow
only a secondary cue. A designer verifying dark mode should be able to remove the shadow entirely and still
see the layering — if a dark surface disappears into the canvas without its shadow, the surface/border
tokens are wrong, not the shadow.

# The Border-vs-Shadow Rule

QAYD's default separator is a **1px hairline border**, and shadow is added only when a surface is genuinely
floating above the page. The decision rule:

- **Static, in-flow content → border only, no shadow (or `shadow-xs` at most).** Cards, panels, sidebar,
  form sections, table containers. Removing their shadow entirely must not break the hierarchy — the
  `ink-6` border already does the separating. This is what keeps QAYD editorial-flat.
- **Temporary, floating content → shadow *and* border.** Dropdowns, popovers, dialogs, sheets are lifted
  off the page for a moment and earn a shadow (`shadow-sm` for menus, `shadow-lg` for modals) in addition
  to their border, plus a scrim for modals/drawers.
- **Never shadow as the only separator.** A surface distinguished from its background by shadow alone (no
  border) is banned — it fails in high-contrast mode, prints as an invisible edge, and reads as Material
  rather than editorial. Border is load-bearing; shadow is reinforcement.
- **Never a heavier shadow to compensate** for a restrained radius or a subtle border. If a surface is not
  reading as separate, the fix is the border token or the background step, not a deeper shadow.

## Border tokens

```css
@theme {
  --border-subtle:  color-mix(in oklab, var(--ink) 10%, transparent); /* ≈ ink-6 — default divider, card edge */
  --border-default: color-mix(in oklab, var(--ink) 16%, transparent); /* ≈ ink-7 — stronger divider, input edge */
}
```

| Surface | Border | Shadow | Radius |
|---|---|---|---|
| Static `Card` on a page | `border-subtle` (≈`ink-6`), 1px | none / `shadow-xs` | `radius-lg` (8px) |
| Interactive `Card` (hover) | `border-subtle` → `border-default` | none | `radius-lg` (8px) |
| Dropdown / popover / select | `border-subtle` | `shadow-sm` | `radius-lg` (8px) |
| Dialog / sheet panel | `border-subtle` (scrim also separates) | `shadow-lg` (dialog) / `shadow-md` (sheet overlay) | `radius-xl` (10px) |
| AI-insight card | `border-subtle` + 2px accent `border-inline-start` | none | `radius-lg` (8px) |
| Danger/destructive confirmation card | `border-subtle` + 2px danger `border-inline-start` | none | `radius-lg` (8px) |

The 2px colored `border-inline-start` on AI-insight and danger cards is the one place a colored border is
standard — it marks provenance ("AI-originated") or severity at a glance, on the reading-start edge in both
LTR and RTL (logical `border-s-2`, never `border-l-2`). It is a border treatment, not a shadow one,
consistent with the border-first strategy. The one place a shadow encodes *direction* rather than elevation
— a frozen table column's trailing-edge scroll hint, which flips under `dir` and stays visible in dark mode
— is detailed in [../ELEVATION_SHADOWS.md](../ELEVATION_SHADOWS.md#directional-shadows-frozen-columns-and-scroll-hints).

# Do / Don't

| Do | Don't |
|---|---|
| Separate static content with a 1px hairline border | Separate a surface with shadow alone (no border) |
| Reserve shadow for temporary floating surfaces (menus, dialogs, sheets) | Put a modal-strength shadow on a static card |
| Make dark-mode elevated surfaces lighter than the canvas | Make higher surfaces darker in dark mode |
| Halve shadow alpha and switch to `rgba(0,0,0,…)` under `.dark` | Reuse light-mode shadow opacity in dark mode |
| Choose a `surface-*` composite (bg + border + shadow) | Invent a bespoke per-screen background+shadow combo |
| Keep `surface-glass` to the ⌘K palette and AI Command Center | Frost every panel "for a premium feel" |
| Fix a weak separation with the border / background token | Deepen the shadow to compensate |
| Cast shadows in the warm ink hue (`rgba(21,19,14,…)`) in light mode | Cast pure-black shadows on the light paper canvas |

# Accessibility Notes

Elevation is the property most likely to fail silently for low-vision and high-contrast users, and the
border-first strategy is what protects them. Cross-references live in
[../ACCESSIBILITY.md](../ACCESSIBILITY.md):

- **Layering never depends on shadow alone.** Because every elevated surface carries a 1px `ink-6` border,
  the separation survives Windows High Contrast mode and forced-colors, where box-shadows are dropped
  entirely. A design that leaned on shadow for separation would present as one flat plane in that mode; a
  bordered one does not.
- **Shadows print and photocopy as nothing.** Finance work is exported to PDF and printed for audit files;
  a soft shadow has no ink on paper, so any hierarchy that relies on it is lost in the export path. The
  border is what carries into print — another reason it is the load-bearing separator.
- **Non-text contrast holds on the border, not the shadow.** WCAG 1.4.11 (3:1 for meaningful UI
  boundaries) is met by the `ink-6`/`ink-7` border tokens against their surfaces in both themes; the shadow
  is exempt reinforcement. Verifying a surface's contrast means checking its border, not its blur.
- **Scrims meet the overlay-contrast contract.** A modal's `ink-12`/50% scrim both separates the dialog and
  dims the page enough that the dialog's own border and lighter surface stay distinguishable; one scrim per
  visible overlay depth, never two stacked, so the underlying page never darkens to unreadable.
- **The dark-mode "remove the shadow" test is an a11y check, not just a visual one.** If a dark surface
  needs its shadow to be seen, a user in a bright room (where soft dark shadows wash out) loses the
  layering — so the surface/border tokens must stand alone, which is exactly what the test enforces.

# End of Document
