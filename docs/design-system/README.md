# Design System — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: README
---

# Purpose

This document is the entry point to `docs/design-system/` — the authoritative home of QAYD's
design foundations: the product-design principles that decide *why* the interface looks the way it
does, and the token catalogue that decides *what* every color, size, radius, and duration resolves
to. It exists so that any engineer, designer, or AI coding agent can open one folder, learn the rules
that never change per screen, and then apply them through the screen- and component-level specs that
live in [`../frontend/`](../frontend/).

`docs/design-system/` is deliberately small and deliberately foundational. It does **not** specify any
one screen, any one component's prop surface, or any one route's data flow — those are owned by
`../frontend/` and its screen specs. It specifies the two things every screen and component depend on
and none of them should re-derive: the **principles** and the **tokens**. When a screen spec makes a
visual decision this folder already governs, this folder wins, and the conflict is raised rather than
silently resolved in a single screen's code.

QAYD is an AI Financial Operating System used by owners, CFOs, controllers, accountants, and auditors
to run a company's real books — journal entries correct to the fils, statements a bank or regulator
will read, and an AI layer that drafts and explains but never overrides a human's authority to
approve. The interface is where all of that trust becomes visible, so its foundations are treated with
the same rigor as a database migration or an API contract: named, versioned, reviewed, and enforced.

# What the Design System Is

The design system is the shared, non-negotiable substrate underneath every QAYD surface. Concretely it
is three things, in strict order of authority:

1. **A set of stated principles** — [`DESIGN_PRINCIPLES.md`](./DESIGN_PRINCIPLES.md). Editorial calm
   over decoration; one disciplined accent; numbers as the hero; AI visible, never silent; bilingual
   EN/AR and full RTL as first-class; accessibility as a requirement, not an add-on; restraint that
   rejects the cartoonish, over-rounded, loudly-colorful register of generic SaaS. These decide taste
   arguments before they happen.

2. **A canonical token catalogue** — [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md). The complete
   CSS-variable token set — the twelve-step warm-neutral ink scale, the single brass accent, the four
   financial-semantic colors, the type/spacing/radius/elevation/motion/z-index/breakpoint scales —
   mirrored exactly from [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md), which
   remains the literal source of the values. Nothing else in QAYD hard-codes a hex, rem, or ms value a
   token already covers.

3. **A governance stance** — tokens are the one place an individual engineer's local judgment is
   deliberately constrained, because one off-convention color copied once tends to get copied again.
   The token pipeline, the light/dark remap, and the "reference a token, never a literal" rule are
   enforced in CI (contrast script, Stylelint disallow-list, logical-property lint) as described in
   `../frontend/THEMING.md → Governance`.

# Principles in Brief

The full statement, with rationale and do/don't tables for each, is in
[`DESIGN_PRINCIPLES.md`](./DESIGN_PRINCIPLES.md). In one line each:

| # | Principle | One-line statement |
|---|---|---|
| 1 | Editorial calm over decoration | Type, space, and hierarchy do the work an illustration or a gradient would otherwise do. |
| 2 | One accent, spent deliberately | Exactly one brand color — a restrained brass — rationed to the single primary action and to AI-touched elements. |
| 3 | Numbers are the hero | The interface exists to make a figure trustworthy and fast to read, not to decorate it. |
| 4 | Two audiences, one system | Dense accountant workflows and calm executive views are the same tokens and primitives at different densities, never a second design system. |
| 5 | AI is visible, never silent | Every AI-originated number, draft, or flag carries its confidence and reasoning and is styled distinctly from committed fact. |
| 6 | Bilingual & RTL as first-class | Every screen is designed and reviewed in Arabic, mirrored via logical properties, not flipped at the end. |
| 7 | Accessibility is a requirement | WCAG 2.1 AA is a floor in both themes and both directions; color is never the only signal. |
| 8 | Restraint | No cartoon mascots, emoji, gradient-mesh "AI glow," neumorphism, confetti, or 20px+ "friendly" radii. |
| 9 | Motion explains, never entertains | Every animation answers a question in under ~300ms and collapses to an instant state change under reduced-motion. |
| 10 | Consistency compounds | A new pattern is a withdrawal against trust in every other screen; check the system before inventing. |

# Token Quick Reference

The headline values, for orientation only — the full catalogue, both themes, and the exact
`globals.css` / `tailwind.config.ts` excerpts are in [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md). All values
are mirrored from [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md).

| Dimension | Headline value(s) |
|---|---|
| Ink scale | 12-step warm-neutral graphite, `ink-1` `#FAFAF9` → `ink-12` `#15130E` (light); `#14130F` → `#F8F6EF` (dark) |
| Accent | One brass — `accent` `#9C7A34` (light) / `#D9B96C` (dark); `accent-on` is near-black, never white |
| Financial semantics | `positive` `#17794A`, `negative` `#B4232E`, `warning` `#B45309` — never brand, never on raw debit/credit; no "info" hue |
| Display type | General Sans (500/600/700) — titles and the one hero number |
| Text / UI type | Inter (400–700) — body, tables, buttons; true `tnum` tabular figures |
| Arabic type | IBM Plex Sans Arabic (400–700) — display and text, one family |
| Numeric / code | JetBrains Mono (400/500) — account codes, IDs |
| Spacing | Tailwind's 4px scale, aliased `--space-2xs`(4) … `--space-3xl`(64) |
| Radius | `4 / 6 / 8 / 10 px` (`sm/md/lg/xl`); only avatars/dots/pills fully round |
| Elevation | Hairline `ink-6`/`ink-7` borders first; four quiet shadow steps (`xs/sm/md/lg`) |
| Motion | `micro` 120ms → `slow` 360ms; `easeOut` `[0.16,1,0.3,1]`; reduced-motion collapses to instant |
| Breakpoints | Tailwind default + custom `3xl` 1920px for General Ledger / Trial Balance |

# The Token Pipeline

QAYD tokens flow in one direction only, from a documented value to a rendered pixel. No stage skips the
one before it, and application code only ever touches the last two stages.

```
DESIGN_LANGUAGE.md value    →   CSS variable          →   tailwind.config.ts        →   shadcn/ui theme          →   pixel
(the documented hex/rem/ms)     (app/globals.css)         (theme.extend mapping)        (semantic aliases +          (utility class or
                                :root + .dark             ink/accent/semantic +          cva component variants)      inline token ref)
                                                          borderRadius/fontFamily
```

- **Stage 1 — documented value.** Every token's canonical value lives in
  [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md) and is catalogued, unchanged, in
  [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md). The ink scale (`#FAFAF9 … #15130E`), the brass accent
  (`#9C7A34`), the financial-semantic set, and every scale (type/space/radius/shadow/motion/z) are
  defined once, here.

- **Stage 2 — CSS variable.** `app/globals.css` declares the raw scale as `--qayd-*` custom properties
  under `:root` (light) and `.dark` (dark), plus the shadcn/ui semantic aliases (`--background`,
  `--primary`, `--border`, `--ring`, …) as unitless HSL triplets consumed via `hsl(var(--x))`. Dark
  mode is a separate, calibrated ramp — not a CSS `invert()`.

- **Stage 3 — Tailwind config.** `tailwind.config.ts`'s `theme.extend` maps every CSS variable to a
  utility: `ink.1…ink.12`, `accent{.subtle,.DEFAULT,.strong,.on}`, `positive/negative/warning`, the
  shadcn aliases, `borderRadius.{sm,md,lg,xl}`, the four `fontFamily` stacks, and the custom `3xl`
  breakpoint. No color is hard-coded here; every entry is a `var(--…)` reference.

- **Stage 4 — shadcn theme + components.** shadcn/ui primitives in `components/ui/` consume the
  semantic aliases; `cva` variant matrices map a variant name to token-referencing Tailwind classes,
  never to a raw color. Framer Motion and canvas/SVG chart code — which cannot read CSS custom
  properties inside a `transition` object or a `fillStyle` computation — import the same values as
  plain JS from `lib/tokens.ts`, kept in lock-step with Stage 2.

The single rule that makes this pipeline worth the indirection: **a component references a token, never
a literal.** Dark mode, and the narrow per-company accent white-label, each become a one-line re-point
at Stage 2 rather than a re-theme of every component. See
[`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) for the full catalogue and the exact `globals.css` /
`tailwind.config.ts` excerpts.

# Relationship to `docs/frontend/`

`docs/design-system/` and `docs/frontend/` are complementary, not competing, and the split is by
altitude:

| | `docs/design-system/` (this folder) | `docs/frontend/` |
|---|---|---|
| Answers | *Why* it looks this way; *what* a value resolves to | *How* a screen or component is built from those values |
| Scope | Principles + tokens — the substrate every screen shares | Architecture, layout templates, component APIs, per-screen specs |
| Changes | Rarely; a token change is a Design-Systems-reviewed event | Per feature; a new screen adds a route, a hook, and composed components |
| Authority | Source of truth for tokens and foundations | Applies the tokens; may not contradict them |

This folder is the **source of truth for tokens and foundations**; `docs/frontend/` is where those
foundations are **applied**. To avoid duplication, this folder does not restate the component catalogue,
the layout templates, the theming provider mechanics, or the per-screen specs — it cross-references
them. The canonical *values* still live in `../frontend/DESIGN_LANGUAGE.md` (this folder mirrors and
governs them); the *application* of those values lives across the rest of `../frontend/`.

## A note on source-doc reconciliation

Three documents in `../frontend/` currently express the token layer with genuinely different palettes
and naming schemes:

- [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md) — **canonical.** Warm-neutral ink
  `ink-1…ink-12`, a brass accent (`#9C7A34`), radii `4/6/8/10px`, `--qayd-*` variable naming.
- [`../frontend/THEMING.md`](../frontend/THEMING.md) — an emerald accent (`#157A4D`), a
  `color-ink-0…950` ramp, radii `6/10/14/20px`, a `--color-*` three-tier naming scheme.
- [`../frontend/COMPONENT_LIBRARY.md`](../frontend/COMPONENT_LIBRARY.md) — a teal-green accent
  (`#0E7A5F`), a `--qayd-ink-0…950`/`--qayd-accent-{100,500,600,700}` numeric scale.

For all new work, **`DESIGN_LANGUAGE.md` governs**, and [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) mirrors
it exactly. The emerald/teal palettes, the `0–950`/`--color-*` naming, and the wider `6/10/14/20` radii
in the other two documents (and the `8/12/20/24` radii and "Emerald Green" primary in the superseded
`../foundation/DESIGN_SYSTEM.md`) should be read as pre-canonical drafts to be reconciled *to* this
system, not divergences to preserve. `DESIGN_TOKENS.md → Reconciliation` records the full mapping.

# Index of `docs/design-system/`

Every document in this folder, with a one-line description. Start with the three core documents; the
specialized documents expand one token family or one foundation each and are referenced from
[`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md).

## Core

- [`README.md`](./README.md) — this file: the design-system entry point, the principles-in-brief
  table, the token quick reference, the token pipeline, the relationship to `../frontend/`, and this
  index.
- [`DESIGN_PRINCIPLES.md`](./DESIGN_PRINCIPLES.md) — the stated product-design principles in full:
  editorial calm, one accent, numbers-as-hero, two audiences, AI-visible-never-silent, bilingual/RTL
  first-class, accessibility-as-requirement, restraint, motion discipline, and consistency — each with
  its rationale and a do/don't table.
- [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) — the canonical token catalogue: the full CSS-variable set
  (ink/accent/semantic color, typography, spacing, radius, elevation, motion, z-index, breakpoints)
  mirrored from `DESIGN_LANGUAGE.md`, the naming convention, the light/dark remap mechanism, the flow
  into `tailwind.config.ts` and the shadcn theme, the "reference a token, never a literal" rule, and
  the reconciliation table for the divergent frontend drafts.

## Color & Typography

- [`COLOR_SYSTEM.md`](./COLOR_SYSTEM.md) — the ink scale, brass accent, and financial-semantic palette
  in depth, with contrast pairings and usage-by-step rules.
- [`TYPOGRAPHY.md`](./TYPOGRAPHY.md) — the display/text/Arabic/mono family roles, the full type scale,
  tabular-numeral rules, and the bilingual EN/AR typesetting differences.
- [`DARK_MODE.md`](./DARK_MODE.md) — the dark-palette derivation, elevation-inverts rule, and AA
  re-verification for the second theme.

## Layout, Space & Shape

- [`SPACING_SYSTEM.md`](./SPACING_SYSTEM.md) — the 4px spacing scale and its semantic aliases.
- [`GRID_SYSTEM.md`](./GRID_SYSTEM.md) — the container grid and the app-shell composition.
- [`BREAKPOINTS.md`](./BREAKPOINTS.md) — the responsive breakpoints, including the custom `3xl` for
  ultra-wide ledger screens.
- [`BORDER_RADIUS.md`](./BORDER_RADIUS.md) — the `4/6/8/10px` radius scale and the ≤10px ceiling
  rationale.
- [`ELEVATION_SHADOWS.md`](./ELEVATION_SHADOWS.md) — the border-first elevation model, surface levels,
  and the four quiet shadow tokens.

## Motion & Assets

- [`MOTION_SYSTEM.md`](./MOTION_SYSTEM.md) — the duration/easing tokens and the reduced-motion contract.
- [`ANIMATION_GUIDELINES.md`](./ANIMATION_GUIDELINES.md) — the named motion patterns and the
  "explain, never entertain" rules per interaction.
- [`ICON_SYSTEM.md`](./ICON_SYSTEM.md) — Lucide usage (stroke, sizing, one color) and custom financial
  glyphs.
- [`ILLUSTRATION_SYSTEM.md`](./ILLUSTRATION_SYSTEM.md) — the no-decoration imagery stance and empty-state
  line-drawing rules.
- [`GLASSMORPHISM_GUIDELINES.md`](./GLASSMORPHISM_GUIDELINES.md) — the deliberately-rare `surface-glass`
  usage, reserved for the command palette and AI Command Center.

## Theming & Accessibility

- [`THEMING.md`](./THEMING.md) — the design-system-scoped theming architecture (token tiers, provider
  contract, per-company white-label surface); complements `../frontend/THEMING.md`.
- [`ACCESSIBILITY.md`](./ACCESSIBILITY.md) — the WCAG 2.1 AA baseline as it applies to tokens: contrast
  floors, color-is-never-the-only-signal, and the CI contrast gate.

> These specialized documents were authored alongside the three core files as part of the same design
> system. Where any of them states a token value, it must match [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md)
> (canonical); a divergence is a reconciliation task, not a second source of truth.

# How to Use This Folder

Read in this order, depending on what you are doing:

- **New to QAYD's design language** — read this README, then
  [`DESIGN_PRINCIPLES.md`](./DESIGN_PRINCIPLES.md) end to end (it decides the taste arguments), then skim
  [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) to see how each principle resolves to a concrete value.
- **Building or restyling a component** — open [`DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) for the exact
  token to reference (never a literal), then `../frontend/COMPONENT_LIBRARY.md` for the primitive's API,
  then `../frontend/THEMING.md` for how the token reaches the DOM.
- **Building a screen** — read the relevant `../frontend/` screen spec; it already assumes these
  foundations. Return here only when the spec is silent on a visual decision — this folder decides it.
- **Proposing a token or a new pattern** — [`DESIGN_PRINCIPLES.md → Principle 10`](./DESIGN_PRINCIPLES.md)
  and `../frontend/THEMING.md → Governance` define what a new token must prove before it ships (both-theme
  values, a contrast proof, a Design-Systems review). A raw hex/`rgb()`/px literal in component code is
  blocked outright.

The one rule that spans every path: **reference a token, never a literal.** Dark mode and the per-company
accent white-label are each a one-line re-point at the CSS-variable layer precisely because no component
hard-codes a value a token already covers.

# Cross-References into `docs/frontend/`

The foundations here are applied by these `../frontend/` documents; read the foundation, then the
application:

- [`../frontend/README.md`](../frontend/README.md) — the frontend engineering index: tech stack,
  repository layout, conventions, and the full screen-spec index.
- [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md) — the canonical source of the token
  values this folder mirrors, plus the prose that motivates each (brand tone, typography roles,
  color/ink rules, data density, imagery stance, voice).
- [`../frontend/THEMING.md`](../frontend/THEMING.md) — the runtime theming layer: `next-themes`
  provider wiring, SSR-safe no-flash theme/density/direction, per-company white-label accent, and
  governance/CI enforcement.
- [`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md) — the dark-palette derivation and its AA
  re-verification.
- [`../frontend/COMPONENT_LIBRARY.md`](../frontend/COMPONENT_LIBRARY.md) — the shadcn/ui-based primitive
  and finance-component catalogue that consumes these tokens.
- [`../frontend/LAYOUT_SYSTEM.md`](../frontend/LAYOUT_SYSTEM.md) — the container grid, spacing
  application, and shared page templates.
- [`../frontend/RESPONSIVE_DESIGN.md`](../frontend/RESPONSIVE_DESIGN.md) — breakpoint behavior for dense
  finance workbenches.
- [`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) — the WCAG 2.1 AA baseline and
  keyboard/screen-reader patterns.
- [`../frontend/ICONOGRAPHY.md`](../frontend/ICONOGRAPHY.md) — Lucide usage rules and the small set of
  custom financial glyphs.
- [`../foundation/DESIGN_SYSTEM.md`](../foundation/DESIGN_SYSTEM.md) — the earliest foundation sketch,
  **superseded** by this folder and `DESIGN_LANGUAGE.md` wherever the two disagree.

# End of Document
