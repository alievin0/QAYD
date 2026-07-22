# Dark Mode — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: DARK_MODE
---

# Purpose

This document specializes [`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md) for the Design System
module. The frontend doc specifies dark mode as it appears in the Next.js web client, screen by screen;
this document specifies the **derivation rules and remap contract** that make QAYD's dark theme a
deliberately authored peer of its light theme rather than an inverted filter — the rules a designer applies
to author a new dark value, and the rules a reviewer applies to reject a bad one.

Dark mode is used at the hours accounting actually happens — a controller closing the month at 23:40, a
founder checking cash from bed before a 7am flight. It is a first-class rendering of the same editorial,
near-monochrome system, and every rule that makes the light theme read like a private-bank annual report
rather than a generic dashboard must survive unweakened when the surface goes dark.

Read alongside:

- [`./THEMING.md`](./THEMING.md) — the pipeline this doc plugs into (theme = a one-block semantic remap),
  the provider, and the parity guarantee.
- [`./LIGHT_MODE.md`](./LIGHT_MODE.md) — the peer theme; every state below has a specified light counterpart
  there, and the two docs are deliberately symmetrical.
- [`./COLOR_SYSTEM.md`](./COLOR_SYSTEM.md) and [`./DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) — the **canonical
  dark values** for this module (the dark column of the `--qayd-ink-1…12`, accent, and semantic tables, plus
  the shadcn `.dark` alias block), mirrored from
  [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md); this doc states the *rules* those
  values obey, not a second copy of the table. The remap table below matches `COLOR_SYSTEM.md` → *Light ↔
  Dark Remap* row-for-row.
- [`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md) — the fuller elevation model, chart hook, logo
  swap, and testing matrix; cross-referenced rather than duplicated.
- [`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) — WCAG 2.2 AA, re-verified independently in
  dark.

Binding constraints, inherited and non-negotiable in every section:

- **AA is the floor in dark, not only in light.** A pairing that passes on white and fails on near-black is
  a defect, not a supported trade-off.
- **Finance semantics keep their meaning.** `positive` / `negative` / `warning` read as the same *state* in
  dark as in light even though their exact channel values differ.
- **AI provenance stays visually distinct from financial outcome**, in both themes — a high confidence score
  is not a "success" and never borrows the positive channel.
- **RTL and dark are orthogonal.** All four combinations are built and tested.
- **No naive inversion.** No `filter: invert()`, no global "darken" plugin, no runtime color-mix. Every dark
  value is authored and reviewed like any other design decision.

# Dark Palette Derivation Rules

Dark is not "light, flipped." It is authored against a different problem: legibility and calm elevation on a
near-black canvas. Six rules govern how every dark value in [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md)
was derived, and how any new one must be.

**Rule 1 — The dark ramp is its own calibrated scale, not an inversion of the light ramp.** Dark mode keeps
the *relative* contrast relationships of the light ramp (a border is still a border-strength step below its
text) but re-authors the absolute values so borders, disabled states, and text each hold the same role. The
warm-neutral hue is preserved through both themes — `ink-1` dark is `#14130F`, a warm near-black, **never** a
cool blue-black — because the warmth *is* the brand's paper-and-ink metaphor and losing it in dark mode
reads as "a different, cheaper product."

**Rule 2 — Elevation gets lighter, not darker.** In dark mode a raised surface steps *up* the ramp toward
the viewer (`ink-1` canvas < `ink-3` card < higher for popovers/modals), matching how physical light and
every considered dark UI (Linear, Vercel) actually behave — the inverse of the naive "shadows get darker"
port. See Surfaces & Elevation.

**Rule 3 — The canvas is near-black, never pure black.** The darkest *surface* role is a warm near-black,
not `#000000`. Pure black against a bright accent or white text causes visible halation on OLED panels and
reads as "cheap dark mode"; true black is reserved (if used at all) for the deepest inset well, a small
fraction of any screen's area, and is validated on a physical OLED device.

**Rule 4 — The accent gets lighter, not more saturated.** Brass stays brass (`#9C7A34` → `#D9B96C`) —
lifted in lightness so it clears AA against both `accent-on` and the dark canvas, **never** pushed brighter
or more chromatic for "pop," which would glow unnaturally and break the on-accent text pairing. The white-
label `deriveAccentRamp()` (see [`./THEMING.md`](./THEMING.md)) applies the same lightness-lift discipline
to a tenant's accent, so a custom brand behaves in dark exactly as the platform brass does.

**Rule 5 — Semantic colors are re-tuned per theme, not lightened by formula.** `positive` / `negative` /
`warning` each get a purpose-picked dark value that clears AA against its own dark subtle-background — a
brightened light-mode red would read harsh and alarming against near-black; the dark red is chosen to read
*urgent but not shouting*.

**Rule 6 — Shadows are demoted to a secondary cue.** A soft shadow has almost no luminance delta to sit
inside against near-black, so shadows are halved in opacity (and re-based on `rgba(0,0,0,…)`) and never the
*only* signal of depth — a 1px border is.

# Per-Token Light → Dark Remap

The remap below is the semantic tier re-pointing (mechanics in [`./THEMING.md`](./THEMING.md) → *Theme =
Remap*). Values are the canonical `../frontend/DESIGN_LANGUAGE.md` set, shown here as the applied mapping;
that document remains the single source if a value is ever edited.

| Semantic role | Light primitive | Dark primitive | Derivation rule applied |
|---|---|---|---|
| `--color-bg-canvas` | `ink-1` `#FAFAF9` | `ink-1` `#14130F` | R1 warm near-black, R3 not pure black |
| `--color-bg-sidebar` | `ink-2` `#F4F3F1` | `ink-2` `#1B1914` | R1 |
| `--color-bg-surface` (card) | `ink-2/3` | `ink-3` `#24211A` | R2 — card is *lighter* than canvas |
| `--color-bg-hover` | `ink-4` `#E0DEDA` | `ink-4` `#2D2921` | R2 |
| `--color-bg-selected` | `ink-5` `#D3D0CA` | `ink-5` `#383327` | R2 |
| `--color-border-subtle` | `ink-6` `#C2BEB6` | `ink-6` `#46402F` | R6 — primary depth cue in dark |
| `--color-border-strong` | `ink-7` `#A9A49A` | `ink-7` `#58503A` | R6 |
| `--color-fg-muted` (disabled) | `ink-8` `#878174` | `ink-8` `#786F55` | R1 relative-contrast preserved |
| `--color-fg-secondary` | `ink-9` `#645F53` | `ink-9` `#A39878` | R1 |
| `--color-fg-primary` | `ink-11/12` `#2B2820`/`#15130E` | `ink-11/12` `#E4DEC9`/`#F8F6EF` | R1 — near-white, never pure white |
| `--color-accent` | `#9C7A34` | `#D9B96C` | R4 lighter, same hue |
| `--color-accent-subtle` | `#EADFBF` | `#3A2E14` | R4 |
| `--color-accent-on` | `ink-12` near-black | near-black | held: engraved-brass read, AA both themes |
| `--color-positive` | `#17794A` | `#4ADE94` | R5 re-tuned |
| `--color-negative` | `#B4232E` | `#F26B74` | R5 urgent-not-shouting |
| `--color-warning` | `#B45309` | `#F5A855` | R5, kept distinct from brass |
| shadow family | `rgba(21,19,14,α)` | `rgba(0,0,0,½α)` | R6 halved, re-based |

There is deliberately **no `info` hue** in either theme: a neutral notice uses the ink scale alone
(`fg-primary` on `bg-surface` with a `border-subtle` edge), and only an *AI-sourced* notice may use
`accent-subtle`. This keeps QAYD to one accent and prevents a second brand color creeping in "for info" —
identical rule in [`./LIGHT_MODE.md`](./LIGHT_MODE.md).

# Contrast Re-Verification in Dark

Every text/background pairing is checked against WCAG 2.2 AA **independently in dark** — never assumed to
pass because its light counterpart did. The CI `scripts/check-contrast.ts` (see [`./THEMING.md`](./THEMING.md)
→ Governance) runs both themes on every PR touching `globals.css`.

| Pairing | Dark ratio | AA target | Result |
|---|---|---|---|
| `fg-primary` (`ink-12`) on `bg-canvas` (`ink-1`) | ~15:1 | 4.5:1 | Pass (generous) |
| `fg-secondary` (`ink-9`) on `bg-surface` (`ink-3`) | ~5.0:1 | 4.5:1 | Pass |
| `accent` (`#D9B96C`) on `accent-on` fill | ~5.6:1 | 4.5:1 | Pass — R4 is what holds this |
| `accent` text on `bg-canvas` | ≥4.5:1 | 4.5:1 | Pass (dark accent tuned for text-on-dark) |
| `positive` on `positive-subtle` | ≥4.5:1 | 4.5:1 | Pass |
| `negative` on `negative-subtle` | ≥4.5:1 | 4.5:1 | Pass |
| `warning` on `warning-subtle` | ≥4.5:1 | 4.5:1 | Pass |
| `border-strong` on `bg-surface` (non-text) | ≥3:1 | 3:1 (1.4.11) | Pass — load-bearing borders only |
| Focus ring (`accent`) on every surface step | ≥3:1 | 3:1 | Pass at every step |

`border-subtle`'s low contrast is intentional and outside AA's scope — it is a decorative divider with a
redundant cue (padding, radius). Only **load-bearing** borders (an input outline, the sole separator between
data) use `border-strong` and are held to 3:1. Disabled text (`fg-muted`) is the one place a slightly lower
ratio is accepted, because a disabled control's meaning is reinforced by `cursor-not-allowed`,
`aria-disabled`, and a permission tooltip — never by contrast alone.

# Surfaces & Elevation in Dark

Light and dark communicate "which layer is on top" by **different primary mechanisms**, and treating them as
the same mechanism inverted is the most common structural dark-mode mistake.

- **Light** clusters every surface near the top of the ramp (little room to go lighter), so elevation is
  read from a soft shadow ramp. (Specified in [`./LIGHT_MODE.md`](./LIGHT_MODE.md).)
- **Dark** reads elevation from *lightness*: the background itself steps lighter as a surface approaches the
  viewer, because shadow barely registers against near-black.

Dark elevation is a strict, monotonic ordering:

```
inset well  <  canvas  <  card  <  popover/dropdown  <  modal
 ink (deepest)  ink-1     ink-3     ink-3 + strong border   ink-3 + shadow-lg(½α)
```

| Surface role | Dark background | Border | Shadow |
|---|---|---|---|
| Canvas | `ink-1` `#14130F` | none | none |
| Card / panel / sidebar | `ink-3` `#24211A` | 1px `border-subtle` (`ink-6`) | none |
| Dropdown / popover | `ink-3` | 1px `border-subtle` | none (lightness does the work) |
| Modal / dialog | `ink-3` | 1px `border-subtle` | `shadow-lg` at ~½ opacity (secondary cue) + `ink-12/50%` scrim |
| `surface-glass` (⌘K palette, AI Command Center only) | `ink-1` @ ~72% + `backdrop-blur(24px)` | 1px `border-subtle` @ ~60% | `shadow-md` (½α) |

Two consequences are binding:

1. **A 1px border is the primary depth cue in dark, not an afterthought.** Every dark card/popover/modal
   ships its border token even where its light counterpart is shadow-only and borderless. Removing a dark
   card's border to "match" its light version makes the card invisible against the canvas — the single most
   common dark regression, and [`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md) includes a specific
   visual-regression check for it.
2. **The dark canvas is never pure black** (Rule 3). A Trial Balance's sticky header band reads as recessed
   relative to its scrolling rows in *both* themes — a barely-there tint under white in light, a deeper step
   under the card in dark — because the *relationship* (sunken < base) is preserved even though the absolute
   values and the mechanism differ.

```tsx
// components/ui/card.tsx — each theme picks its own depth cue from one class list
<div className={cn(
  "rounded-lg border border-border-subtle bg-surface",
  "shadow-xs dark:shadow-none",   // shadow does real work in light; border does it in dark
  className,
)} />
```

# Elevation, Shadows, Glass, Charts & AI Affordances in Dark

## Shadows and glass
Shadows are halved in opacity and re-based on `rgba(0,0,0,…)` under `.dark` (Rule 6) and are never the sole
depth signal. `surface-glass` stays rare — reserved for the command palette and the AI Command Center, the
two panels that float conceptually above the whole app — because frosted glass reads as premium only when
unusual, and `backdrop-blur` is not free on back-office hardware.

## Charts and data viz
- **`filter: invert()` is banned.** Inversion flips *hue* as well as lightness — a `negative` red inflow bar
  would invert toward cyan, a `positive` green toward magenta, destroying the exact semantic the color
  encodes. Every dark chart color is an authored dark token, never a filtered light rendering.
- **Chart palettes are re-picked per theme, not lightened by formula.** Two series comfortably distinct on
  white can drift together once uniformly brightened for dark; the dark categorical set is re-checked for
  pairwise perceptual distance **and** for protanopia/deuteranopia simulation *in the dark palette
  specifically* (CVD confusion lines are lightness-dependent).
- **Financial charts map to semantic tokens, not the categorical palette** — a cash-flow waterfall or a
  budget-vs-actual chart draws from `positive`/`negative`/`warning` so its color vocabulary matches every
  badge and card on the same screen, in both themes.
- **Tailwind cannot reach an SVG `fill`.** Charts read resolved CSS custom properties through a hook keyed on
  `resolvedTheme` and **re-render on theme change** — a chart mounted once and never re-reading its tokens is
  the most common dark chart bug (it keeps painting mount-time colors after a toggle). The `useChartTokens`
  hook and the `contentStyle` tooltip override (defeating the library-default white tooltip box) are
  specified in [`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md) → *Charts & Data Viz In Dark*.

## AI-confidence affordances
AI provenance is visually separate from financial outcome in both themes. A confidence score is a statement
about the AI's certainty, not about whether an outcome was good — a `confidence_score: 94.0` fraud hold and
a `94.0` "looks miscategorized" suggestion are both high-confidence, one alarming, one routine — so
confidence must never borrow the `positive`/`negative`/`warning` channels.

Per [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md), AI-touched elements are marked with
the **brass accent itself** (a 6px `accent` gutter dot on a proposed/flagged row; `accent-subtle` on an
AI-proposal card; the assistant's geometric mark in `accent`) — the accent's deliberate double duty is "the
one primary action" *or* "something the AI touched, never committed." A card that *also* carries a finance
status shows **both** signals side by side rather than collapsing one into the other: a held payment renders
its `border-negative` left edge and status chip in `negative` (dark-tuned `#F26B74` — urgent against `ink-3`
without harsh saturation) while its confidence affordance renders in `accent` — two legible, independently
colored facts matching the two separate API fields (`status`, `confidence_score`). This holds identically in
light and dark; only the token values differ.

# Toggle & Persistence

The theme control is a **three-way segmented control** (System / Light / Dark — `Monitor` / `Sun` / `Moon`
from Lucide, on shadcn/ui `ToggleGroup` = Radix `RadioGroup`, so `role="radiogroup"`/`radio` for free), not
a binary switch — because `system` is QAYD's recommended default, a distinct selectable state, not a
fallback. It lives in two places: the account menu (reachable from every screen) and
`settings/appearance`.

Interaction rules:

- **Instant apply, no Save.** `setTheme` repaints synchronously in the same frame; `persist` follows in the
  background with no spinner. A network delay never makes the toggle feel laggy because the visible effect
  never waited on the network.
- **Fully keyboard-operable**, exposed in the ⌘K command palette as "Switch to dark theme" etc.
- **`system` tracks the OS live** for the session (a `matchMedia` listener), so a sunset auto-switch is
  followed without a reload — and never caches the first resolution.
- **Never auto-downgrades an explicit choice** — picking `dark` and then having the OS go light keeps QAYD
  on dark until the user changes it.

Persistence and no-flash SSR (cookie for the server render, `users.settings.ui_theme` for cross-device,
optimistic write, multi-tab `storage` sync) are specified once in [`./THEMING.md`](./THEMING.md) →
*Theme Provider* and [`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md) → *Persistence & SSR*; the
`disableTransitionOnChange` reduced-motion-safe icon cross-fade is verified in both toggle directions.

# Common Dark-Mode Pitfalls Avoided

Each row is a mistake QAYD's rules and CI structurally prevent, so it cannot ship unnoticed in a
light-mode-only review.

| Pitfall | Why it happens | How QAYD prevents it |
|---|---|---|
| `filter: invert()` chart/logo | "Cheap" one-line dark mode | Banned; authored dark tokens + two-variant logo SVG (below) |
| Pure-black canvas | "Darker = more premium" | Rule 3 — warm near-black canvas; true black reserved for a tiny inset area, OLED-validated |
| Borderless dark card | Copied from the borderless light card | Elevation model makes the border the primary depth cue; visual-regression check |
| Naive-brightened accent that glows | "Pop against black" | Rule 4 — lightness lift only, capped so `accent-on` still clears AA |
| Harsh brightened `negative` red | Formula-lightened from light red | Rule 5 — re-tuned to urgent-not-shouting |
| Chart stuck on mount-time colors | `useEffect` deps `[]` instead of `[resolvedTheme]` | Hook keyed on `resolvedTheme`, re-renders on toggle |
| Blinding white default tooltip | Library default, theme-blind | Every chart overrides `contentStyle` to `surface`/`border`/`fg` tokens |
| Hardcoded `bg-white` in a modal | Invisible in light review | `check-no-raw-colors.sh` fails the build |
| Contrast regression only in dark | A11y check runs one theme | `check-contrast.ts` + `axe` parametrized over both themes |
| Logo vanishes on dark | Single-variant asset | Two pre-authored SVGs swapped by `resolvedTheme`; the emerald/brass mark is pixel-identical across both, never remapped like a UI state |

Photographic/user-uploaded content (an OCR'd invoice, a receipt) is **never** recolored to fit the theme —
the *frame* is themed while the image content stays pixel-faithful (the Document AI pipeline relies on
faithfulness for audit). Exported PDFs and transactional emails always render light regardless of in-app
theme (an auditor forwarded a Trial Balance has no theme context; email clients auto-invert unpredictably) —
both are permanent, documented exceptions to "everything themes."

# Testing

Dark is verified as a peer of light, never as an afterthought:

- **Four-combination visual regression** — every component story renders through the real
  `ThemeProvider` + `dir` wiring and is snapshotted across `{ltr,rtl} × {light,dark}`; a diff on any one of
  the four blocks the PR exactly like a diff on the default combination.
- **`axe-core` parametrized by theme** — runs against both `resolvedTheme` values in CI, so a contrast
  regression introduced only in dark's token values cannot ship silently.
- **Token-drift lint** — `check-no-raw-colors.sh` turns "components never hardcode a color" from a review
  convention into a build-breaking guarantee.
- **Physical OLED pass** on the darkest canvas token specifically (banding/flicker at low brightness a lab
  monitor won't reproduce) — the reason a warm near-black, not true black, is the canvas.
- **`forced-colors: active`** checked separately (defer to system colors) and **`prefers-reduced-motion` ×
  theme-change** verified in both toggle directions.

Every screen doc carries the `loading / empty / error / RTL / dark` state table
([`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md) → *Manual QA state table*); this doc guarantees the
tokens those states resolve to are correct in dark by construction.

# Edge Cases

- **First-ever visit, JS blocked** — the `<meta name="color-scheme">` + critical `@media` CSS fallback give
  an approximately-correct theme; no toggle is available (it needs React) — an accepted, documented limit.
- **Company switch never changes theme** — theme lives on the user; a `switch-company` call must leave
  `resolvedTheme` unchanged (asserted by test).
- **Android/Chrome "Force Dark"** — `color-scheme` + `<meta>` signal "this page handles its own dark mode;
  do not heuristically re-color," preventing double-processing.
- **Third-party OAuth popups aren't themeable** — a light Google/Apple popup over a dark QAYD login is
  expected, not a defect.
- **Live camera/OCR preview is never tinted** — only the surrounding chrome follows the theme.
- **`prefers-contrast: more`** — a progressive enhancement may strengthen the already-AA dark border tokens
  toward `border-strong`, never a replacement for the AA baseline.
- **Realtime update mid-toggle** — a Reverb push landing in the same render as a theme flip is handled by
  ordinary React re-render; both are React state, so there is no race and no "pause updates while switching"
  logic.

# End of Document
