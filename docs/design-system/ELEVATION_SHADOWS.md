# Elevation & Shadows — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: ELEVATION_SHADOWS
---

# Purpose

This document is the canonical elevation system for QAYD: the surface levels, the shadow token scale,
the z-index tiers every floating layer stacks on, the differing light- and dark-mode elevation
treatment, the border-versus-shadow separation strategy, and the usage rules that keep QAYD's depth
restrained rather than Material-heavy.

It mirrors the elevation and shadow rules in
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md#elevation--surfaces) and consolidates
the z-index scale used across the shell and component documents
([../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md#z-index-scale)). Radius pairs with every
elevated surface — see [BORDER_RADIUS.md](BORDER_RADIUS.md); surface layering inside the grid is in
[GRID_SYSTEM.md](GRID_SYSTEM.md#dense-table-layout).

QAYD builds elevation primarily from **hairline borders, not shadow depth** — an editorial-flat
approach closer to Linear or Stripe's dashboard than a Material stacked-shadow system. Shadows exist,
but they are quiet enough that removing them would not visibly break the hierarchy on their own; the
border does most of the work. Heavy drop shadows and neumorphic bevels are explicitly rejected as
generic-SaaS.

# Elevation Model

QAYD has a small, fixed set of surface levels. A surface is defined by three properties together —
its background token, its border, and its shadow — not by shadow alone. Most static content sits at
levels 0–1, where a border does all the separating; shadow is reserved for genuinely elevated,
*temporary* surfaces (menus, popovers, dialogs, sheets), never for static page content.

| Surface level | Background (light) | Background (dark) | Border | Shadow | Usage |
|---|---|---|---|---|---|
| `surface-0` | `ink-1` | `ink-1` | — | none | App canvas |
| `surface-1` | `ink-2` / `ink-3` | `ink-3` | 1px `ink-6` | `shadow-xs` | Cards, panels, sidebar |
| `surface-2` | `ink-1` | `ink-3` | 1px `ink-6` | `shadow-sm` | Dropdowns, popovers, selects, date pickers |
| `surface-3` | `ink-1` | `ink-3` | 1px `ink-6` | `shadow-lg` | Modals, dialogs (with an `ink-12`/50% scrim beneath) |
| `surface-glass` | `ink-1` @ 72% + `backdrop-blur(24px)` | same treatment | 1px `ink-6` @ 60% | `shadow-md` | Command palette (⌘K) and the AI Command Center overlay **only** |

Two things to note in this table. First, dark-mode elevated surfaces get *lighter* backgrounds than
the canvas, not darker (`ink-3` sits above `ink-1`) — the physical-light model, covered in Light vs.
Dark Elevation below. Second, `surface-glass` is intentionally rare: frosted/blurred glass reads as
premium precisely because it is unusual, and `backdrop-blur` is not free on the back-office desktops
that make up much of QAYD's audience. It is reserved for the two moments — the global command palette
and the AI Command Center — where a panel floats conceptually above the entire app rather than
belonging to the current page. (The topbar carries a restrained `backdrop-blur(8px)`; it is chrome,
not a glass surface, and does not use `surface-glass`.)

# Shadow Token Scale

Four shadow steps, tuned so the softest is barely-there and the strongest still reads as
"a temporary plane above the page," never as Material depth. All shadows are cast in the warm-neutral
ink hue (`rgba(21, 19, 14, …)`) rather than pure black, so they belong to the same paper-and-ink
palette as everything else.

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
| `shadow-xs` | `0 1px 2px rgba(21,19,14,.04)` | Static cards/panels (`surface-1`) — a whisper of lift under a bordered card |
| `shadow-sm` | `0 2px 4px rgba(21,19,14,.06)` | Dropdowns, popovers, selects, tooltips (`surface-2`) |
| `shadow-md` | `0 8px 16px -4px rgba(21,19,14,.08)` | Glass surfaces, drawers/sheets in overlay mode (`surface-glass`) |
| `shadow-lg` | `0 24px 48px -12px rgba(21,19,14,.16)` | Modals/dialogs (`surface-3`) — the strongest lift in the system |

The negative spread on `shadow-md`/`shadow-lg` keeps the shadow tucked under the surface rather than
haloing out around it, which is what makes a large-radius blur still read as restrained.

> Reconciliation note: [../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md#surface-tokens)
> published a reduced two-step alias set (`--shadow-sm` `0 1px 2px`, `--shadow-md` `0 4px 16px`, plus
> `--shadow-none`) using `color-mix`. This document adopts
> [../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md)'s four-step scale as canonical
> because the task and the design language treat it as source of truth and it maps cleanly onto the
> four surface levels. Map the LAYOUT alias `--shadow-sm` → `shadow-sm` (menus) and `--shadow-md` →
> `shadow-lg` (modals/sheets) when reconciling older component code; `--shadow-none` is simply the
> absence of a shadow token (`surface-0`).

# Z-Index Tiers

One scale, defined once, referenced everywhere — no component invents its own z-index. The canonical
tokens are the eight steps published in [DESIGN_TOKENS.md](DESIGN_TOKENS.md#z-index) and
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md#z-index-scale); every Radix overlay
renders in `#portal-root`, outside the shell's DOM subtree, so no sticky table header or topbar can
ever visually trap or clip an open menu.

```css
/* app/globals.css — canonical, per DESIGN_TOKENS.md */
@theme {
  --z-base: 0;
  --z-sticky: 10;     /* sticky table headers/footers, frozen columns, sticky filter/sub-nav */
  --z-dropdown: 20;   /* Radix DropdownMenu, Select, Popover, Combobox */
  --z-overlay: 30;    /* modal/drawer scrim */
  --z-modal: 40;      /* modal/drawer content (Dialog, Sheet, AI-rail overlay) */
  --z-toast: 50;      /* Sonner toast stack */
  --z-command: 60;    /* ⌘K command palette, AI Command Center overlay — above any open modal */
  --z-tooltip: 70;    /* tooltips — never occluded by anything, including modals */
}
```

| Token | Value | Layer | Notes |
|---|---|---|---|
| `z-base` | 0 | Default document flow | — |
| `z-sticky` | 10 | Sticky table header/footer, frozen identifier column, sticky filter/sub-nav bar | Lives *inside* content scroll; below every portalled overlay |
| `z-dropdown` | 20 | Dropdown menu, select, popover, combobox | First portalled tier. The app shell's own chrome (sidebar, topbar, sticky form footer) also sits at this tier as non-portalled `sticky`/`fixed` chrome and never overlaps an open menu, because menus portal above their trigger |
| `z-overlay` | 30 | Modal/drawer scrim (backdrop) | The dark scrim beneath any Dialog or Sheet |
| `z-modal` | 40 | Modal/drawer content | Dialog (create/edit forms, confirmations), Sheet (AI-rail overlay mode, mobile nav, detail drill-down) |
| `z-toast` | 50 | Sonner toast stack | Above modals so a confirmation toast is never hidden behind a dialog |
| `z-command` | 60 | ⌘K command palette, AI Command Center overlay | Above any open modal — reachable from every state |
| `z-tooltip` | 70 | Tooltips | Always on top; a tooltip on a control inside a modal must never be clipped |

A dialog opened from within a sheet is the maximum permitted overlay nesting (drawer → modal); both
sit at `z-modal` and the later-mounted dialog wins in DOM order, with its own scrim (`z-overlay`)
covering the sheet beneath. A Dialog may not open a second Sheet or Dialog. See the nested-overlay edge
case in [../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md#edge-cases).

> Reconciliation note: the app shell in
> [../frontend/LAYOUT_SYSTEM.md](../frontend/LAYOUT_SYSTEM.md#z-index-scale) implements a finer variant
> that splits shell chrome onto its own tier (20) and pairs each overlay scrim with its content
> (`drawer-scrim`/`drawer` = 40/41, `modal-scrim`/`modal` = 50/51, tooltip = 80). Those sub-tiers are
> an implementation expansion that preserves the same *relative* order as the canonical eight-step
> scale above (sticky < chrome/dropdown < scrim < content < toast < command < tooltip). The token
> **names and values in the table above** — matching [DESIGN_TOKENS.md](DESIGN_TOKENS.md) — are the
> design-system source of truth; the shell's split values map onto them (its `drawer`/`modal` content
> tiers collapse to `z-modal`, its scrims to `z-overlay`).

# Light vs. Dark Elevation

Dark mode is a first-class theme with its own calibrated ramp, not an inverted filter. `next-themes`
drives `class="dark"` on `<html>`. Two elevation rules keep dark mode from feeling like a cheaper,
different product:

**1. Elevation gets lighter, not darker, in dark mode.** A raised card (`ink-3` in dark mode) is
*lighter* than the canvas (`ink-1`) it sits on — how physical light and most professional dark UIs
actually behave, and the opposite of a naive "darker = higher" instinct. Layering is therefore carried
by the ink background steps first, and shadow second. Backgrounds also stay warm-neutral in dark mode
(`ink-1` = `#14130F`, not a cool blue-black), so the same paper-and-ink warmth carries through both
themes.

**2. Shadows are lighter and subtler in dark mode.** A light-mode shadow reads as "too heavy" against a
dark background, because there is less ambient contrast for a soft shadow to sit inside. Under `.dark`,
every shadow token is redefined with `rgba(0, 0, 0, …)` at roughly **half** the light-mode alpha, so
the shadow reinforces the lighter-surface layering rather than fighting it.

```css
.dark {
  --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.20);
  --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.24);
  --shadow-md: 0 8px 16px -4px rgba(0, 0, 0, 0.32);
  --shadow-lg: 0 24px 48px -12px rgba(0, 0, 0, 0.40);
}
```

The practical consequence: in dark mode a dropdown or dialog is legible against the canvas primarily
because its `ink-3` surface is a step lighter and its `ink-6` border is visible, with the softened
shadow only as a secondary cue. A designer verifying dark mode should be able to remove the shadow
entirely and still see the layering — if a dark surface disappears into the canvas without its shadow,
the surface/border tokens are wrong, not the shadow.

Both themes and both `dir` values are covered as an explicit visual-regression matrix (`{light, dark} ×
{ltr, rtl} × {comfortable, compact}`); the one place these axes historically interact is a frozen
column's directional drop-shadow (below), which must flip under `dir` and stay visible in dark mode.

# Border-vs-Shadow Separation Strategy

QAYD's default separator is a **1px hairline border**, and shadow is added only when a surface is
genuinely floating above the page. The decision rule:

- **Static, in-flow content → border only, no shadow (or `shadow-xs` at most).** Cards, panels,
  sidebar, form sections, table containers. Removing their shadow entirely must not break the
  hierarchy — the `ink-6` border already does the separating. This is what keeps QAYD editorial-flat.
- **Temporary, floating content → shadow *and* border.** Dropdowns, popovers, dialogs, sheets. These
  are lifted off the page for a moment and earn a shadow (`shadow-sm` for menus, `shadow-lg` for
  modals) *in addition to* their border and, for modals/drawers, a scrim.
- **Never shadow as the only separator.** A surface distinguished from its background by shadow alone
  (no border) is banned — it fails in high-contrast mode, prints as an invisible edge, and reads as
  Material rather than editorial. Border is the load-bearing separator; shadow is reinforcement.
- **Never a heavier shadow to compensate for a restrained radius or a subtle border.** If a surface is
  not reading as separate, the fix is the border token or the background step, not a deeper shadow.

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
| Interactive `Card` (hover) | `border-subtle` → `border-default` on hover | none | `radius-lg` (8px) |
| Dropdown / popover / select | `border-subtle` | `shadow-sm` | `radius-lg` (8px) |
| Dialog / sheet panel | `border-subtle` (scrim also separates) | `shadow-lg` (dialog) / `shadow-md` (sheet overlay) | `radius-xl` (10px) |
| AI-insight card | `border-subtle` + 2px accent `border-inline-start` | none | `radius-lg` (8px) |
| Danger/destructive confirmation card | `border-subtle` + 2px `border-inline-start` in the danger token | none | `radius-lg` (8px) |

The 2px colored `border-inline-start` on AI-insight and danger cards is the one place a colored border
is standard — it marks provenance ("AI-originated") or severity at a glance, on the reading-start edge
in both LTR and RTL (logical `border-s-2`, never `border-l-2`). It is a border treatment, not a shadow
one, consistent with the border-first strategy.

## Directional shadows (frozen columns and scroll hints)

The one place a shadow encodes *direction* rather than elevation is a dense table's frozen identifier
column and its horizontal-scroll hint: a soft shadow (or a `to-*` gradient) on the frozen column's
trailing edge signals "there is more content this way." This shadow points from the logical start
toward the logical end, so it must flip under `dir` (achieved with logical positioning /
`bg-gradient-to-l rtl:bg-gradient-to-r`) and must stay visible — not washed out — in dark mode. It is
called out here because it is the single elevation element that is simultaneously direction-sensitive
and theme-sensitive.

# Usage Rules

- **Elevation is a fixed vocabulary, not a free dial.** A surface is one of `surface-0`…`surface-3` or
  `surface-glass` — never a bespoke background+border+shadow combination invented per screen.
- **Match the shadow to the surface level**, not to how much attention a surface "wants." A card that
  needs more prominence gets more whitespace or a heavier type treatment, never a modal-strength shadow.
- **Portalled overlays only above 30.** Anything that must escape a sticky header or an
  `overflow: hidden` scroll container renders through `#portal-root` at `z-dropdown` or higher — never
  raised by inflating a sticky element's z-index into the overlay band.
- **Reserve `surface-glass` for the command palette and AI Command Center.** Do not glass a panel to
  make it feel premium; the effect only works because it is rare, and it costs GPU on low-end back-office
  hardware.
- **One scrim per visible overlay depth.** A dialog opened from a sheet shows the dialog's scrim (50)
  over the sheet; the sheet does not also darken. Never stack two scrims for one interaction.
- **Verify dark mode by removing the shadow.** If a dark-mode surface vanishes into the canvas without
  its shadow, fix the `ink` background step or the border, because layering must survive shadow removal.

# Do & Don't

| Do | Don't |
|---|---|
| Separate static content with a 1px hairline border | Separate a surface with shadow alone (no border) |
| Reserve shadow for temporary floating surfaces (menus, dialogs, sheets) | Put a modal-strength shadow on a static card |
| Make dark-mode elevated surfaces lighter than the canvas | Make higher surfaces darker in dark mode |
| Halve shadow alpha and switch to `rgba(0,0,0,…)` under `.dark` | Reuse light-mode shadow opacity in dark mode |
| Reference the one shared z-index scale | Invent a component-local z-index |
| Render escape-the-container overlays through `#portal-root` at 30+ | Inflate a sticky element's z-index to sit above a menu |
| Keep `surface-glass` to the ⌘K palette and AI Command Center | Frost every panel "for a premium feel" |
| Fix a weak separation with the border/background token | Deepen the shadow to compensate |
| Flip the frozen-column directional shadow under `dir` and keep it visible in dark mode | Hardcode the scroll-hint shadow to the physical left/right |

# End of Document
