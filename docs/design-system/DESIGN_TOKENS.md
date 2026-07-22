# Design Tokens — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: DESIGN_TOKENS
---

# Purpose

This document is the canonical catalogue of QAYD's design tokens: every color, type step, spacing value,
radius, shadow, motion duration/easing, z-index band, and breakpoint the frontend renders, with its exact
value in both light and dark themes. It mirrors the token tables and the `:root`/`.dark` implementation in
[`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md) — that document remains the literal
source of the values; this one restates them, unchanged, as the design-system's authoritative catalogue
and documents the naming convention, the light/dark remap mechanism, and the pipeline into
`tailwind.config.ts` and the shadcn/ui theme.

The binding rule this catalogue exists to serve is one sentence: **a component references a token, never a
literal.** No screen, component, or chart ships a hex, rgb, rem, or px value that a token already covers.
Dark mode and the narrow per-company accent white-label are each a one-line re-point of a semantic
variable, not a re-theme of every component — that is the entire reason the indirection is worth it.

For depth beyond this catalogue, see the specialized frontend documents: type roles and the `<Amount>`
numeral contract in [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md); the runtime token
pipeline, provider wiring, and governance in [`../frontend/THEMING.md`](../frontend/THEMING.md); the
dark-palette derivation in [`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md); iconography in
[`../frontend/ICONOGRAPHY.md`](../frontend/ICONOGRAPHY.md); and the layout application in
[`../frontend/LAYOUT_SYSTEM.md`](../frontend/LAYOUT_SYSTEM.md).

> A note before the tables: two other frontend documents (`THEMING.md`, `COMPONENT_LIBRARY.md`) and the
> superseded `../foundation/DESIGN_SYSTEM.md` express the token layer with different palettes and naming
> schemes (emerald/teal accents, a `0–950`/`--color-*` ink scale, wider radii). This catalogue follows
> `DESIGN_LANGUAGE.md` exactly; the [Reconciliation](#reconciliation) section at the end records the
> divergence and the governing mapping.

# Token Tiers & Naming Convention

Every value a component renders traces through a fixed chain; nothing in application code references a raw
value directly.

```
Raw scale (--qayd-*)   →   shadcn semantic alias (hsl var)   →   Tailwind utility / cva variant   →   pixel
   ink-1…12, accent,        --background/--primary/--border/       bg-ink-2, text-accent, bg-primary    rendered
   positive/negative...     --ring/--destructive...                border-ink-6, rounded-lg
```

| Layer | Pattern | Example | Allowed value |
|---|---|---|---|
| Raw scale | `--qayd-{category}-{step}` | `--qayd-ink-2`, `--qayd-accent`, `--qayd-positive` | Literal hex (light and dark under `:root` / `.dark`) |
| Radius / shadow / motion primitives | `--radius-{step}`, `--shadow-{step}` | `--radius-lg`, `--shadow-md` | Literal px / box-shadow / cubic-bezier |
| shadcn semantic alias | `--{role}` (unitless HSL triplet) | `--background`, `--primary`, `--ring`, `--border` | HSL triplet, consumed as `hsl(var(--x))` |
| Tailwind utility | mapped in `theme.extend` | `bg-ink-2`, `text-accent`, `border-ink-6`, `rounded-lg` | `var(--…)` reference only |

Two things are deliberately true of this scheme. The `--qayd-*` prefix namespaces QAYD's own scale so it
never collides with a third-party or shadcn variable. And the shadcn semantic aliases (`--background`,
`--primary`, …) are stored as **unitless HSL triplets** (`42 50% 38%`) rather than hex, because the
`hsl(var(--x) / <alpha-value>)` form is what lets Tailwind opacity modifiers (`bg-primary/10`,
`text-ink-9/60`) work on every token without extra plumbing.

# Color — Ink Scale

The near-monochrome foundation: twelve steps of one warm-neutral graphite scale. "Warm-neutral" (a few
points of yellow hue at low saturation) is a deliberate choice over the cooler blue-black most AI/SaaS
dashboards default to — a faint warmth reads as paper and ink, the product's own metaphor. The scale
follows a Radix-Colors-style usage convention (1–2 backgrounds, 3–5 component fills, 6–8 borders/disabled,
9–10 icons/secondary text, 11–12 high-emphasis text), so anyone familiar with shadcn's theming model
reads it with zero ramp-up.

| Token | Hex (light) | Hex (dark) | Typical usage |
|---|---|---|---|
| `ink-1` | `#FAFAF9` | `#14130F` | App canvas background |
| `ink-2` | `#F4F3F1` | `#1B1914` | Sidebar / subtle background |
| `ink-3` | `#EBE9E6` | `#24211A` | Card / component background |
| `ink-4` | `#E0DEDA` | `#2D2921` | Hover background |
| `ink-5` | `#D3D0CA` | `#383327` | Active / selected background |
| `ink-6` | `#C2BEB6` | `#46402F` | Hairline border, default divider |
| `ink-7` | `#A9A49A` | `#58503A` | Input border, stronger divider |
| `ink-8` | `#878174` | `#786F55` | Disabled text/icon, placeholder |
| `ink-9` | `#645F53` | `#A39878` | Secondary text, default icon |
| `ink-10` | `#4A4639` | `#C2B99C` | Primary icon, strong secondary text |
| `ink-11` | `#2B2820` | `#E4DEC9` | Headings, high-emphasis text |
| `ink-12` | `#15130E` | `#F8F6EF` | Display text, near-black / near-white |

# Color — Accent

QAYD's one brand color: a restrained brass, rationed to the single primary action per screen and to
AI-touched elements (see [`DESIGN_PRINCIPLES.md`](./DESIGN_PRINCIPLES.md) principles 2 and 5). Brass was
chosen over the default SaaS indigo/violet because that hue is now the generic "AI startup" signal; brass
carries the ledger metaphor (brass fittings, an engraved plate) without any literal texture.

| Token | Hex (light) | Hex (dark) | Usage |
|---|---|---|---|
| `accent-subtle` | `#EADFBF` | `#3A2E14` | Selected-row tint, AI-proposal card background |
| `accent` | `#9C7A34` | `#D9B96C` | Primary button fill, focus ring, active tab, links |
| `accent-strong` | `#7A5D22` | `#E8CE8E` | Hover / pressed state of the above |
| `accent-on` | `#15130E` (`ink-12`) | `#14130F` (near-black) | Text/icon drawn on top of `accent` |

**`accent-on` is near-black, never white.** A mid-value warm brass does not clear 4.5:1 against white
text (~3.2:1 at the `accent` value) but comfortably clears it against `ink-12` (~5.5:1) — and engraved
brass is a dark mark on a gold ground, not a white one. Every filled `accent` surface pairs with
`accent-on`. Brass used *as* text/icon directly on an `ink-1`/`ink-2` background (a link, an active nav
icon) uses the theme's `accent` value, which clears 4.5:1 against those backgrounds. In dark mode the
accent gets **lighter, not more saturated** (`#9C7A34` → `#D9B96C`) so it holds contrast against the dark
canvas instead of glowing.

# Color — Financial Semantics

Financial state, never brand state. These four hues express polarity and status and never bleed into
brand usage; the accent, conversely, never carries status. There is deliberately **no fifth "info" hue** —
a plain informational banner uses the ink scale alone (`ink-11` on `ink-3` with an `ink-6` border), so
QAYD never quietly ends up with two brand colors. The one exception, per the AI rule, is that an
*AI-sourced* notice may use `accent-subtle`.

| Token | Hex (light) | Hex (dark) | Meaning | Never use for |
|---|---|---|---|---|
| `positive` | `#17794A` | `#4ADE94` | Increase, gain, reconciled, success | Brand actions, AI badges |
| `positive-subtle` | `#E3F3EA` | `#12301F` | Positive background wash | — |
| `negative` | `#B4232E` | `#F26B74` | Decrease, loss, overdue, destructive | Brand actions, AI badges |
| `negative-subtle` | `#FBE7E8` | `#3A1618` | Negative background wash | — |
| `warning` | `#B45309` | `#F5A855` | Pending, unreconciled, nearing a limit | Brand accent (kept more orange/higher-chroma than brass) |
| `warning-subtle` | `#FBEBDA` | `#3A2812` | Warning background wash | — |

**The debit/credit rule.** `positive`/`negative` are never applied to a raw debit or credit amount.
Debit-normal and credit-normal are structural properties of an account type, not judgments of good/bad;
Journal Entry, General Ledger, and Trial Balance debit/credit columns render in plain `ink-12` tabular
numerals, distinguished by column position, never by hue. Color is reserved for genuinely signed, net
metrics (Net Income, a variance, a period-over-period delta), and even there QAYD colors a small delta
chip beside the figure rather than washing the whole numeral. Full rationale in
[`DESIGN_PRINCIPLES.md → Principle 3`](./DESIGN_PRINCIPLES.md) and `../frontend/DESIGN_LANGUAGE.md`.

## Contrast targets

| Pairing | Minimum ratio | Typical actual |
|---|---|---|
| Body text (`ink-11`/`ink-12`) on `ink-1`/`ink-2` | 4.5:1 (AA normal) | ~13:1 |
| Secondary text (`ink-9`) on `ink-1`/`ink-2` | 4.5:1 | ~5.1:1 |
| `accent-on` on `accent` fill | 4.5:1 | ~5.5:1 |
| `destructive-foreground` (white) on `negative` fill | 4.5:1 | ~5.8:1 |
| Disabled text (`ink-8`) on `ink-3` | 3:1 (disabled exempt, kept legible) | ~2.9–3.1:1 |
| Focus ring (`accent`) on any surface | 3:1 (WCAG 1.4.11) | ≥3:1 at every surface step |

QAYD targets **WCAG 2.1 AA** as a floor in both themes and both directions; disabled controls are the
only accepted exception, and only because their state is reinforced by a tooltip and
`aria-disabled`/`cursor-not-allowed`, not color alone.

# Typography

Two Latin families and one Arabic family, each doing one job.

| Role | Family | Script | Weights | Notes |
|---|---|---|---|---|
| Display | General Sans | Latin | 500, 600, 700 | Page/section/modal/card titles and the one hero number; never below 16px, never body paragraphs |
| Text / UI | Inter | Latin | 400, 500, 600, 700 | Body, labels, inputs, buttons, nav, table cells; true `tnum` tabular figures hold at 12–14px |
| Arabic (display + text) | IBM Plex Sans Arabic | Arabic | 400, 500, 600, 700 | One family across the whole Arabic hierarchy; weight/size do the work a style change does in Latin |
| Numeric / code | JetBrains Mono | Latin | 400, 500 | Account codes, IDs, request IDs, raw JSON in dev views |

## Type scale

Sizes in `rem` against a 16px root, exposed as Tailwind utilities plus matching CSS variables. Arabic uses
the same named steps and rem sizes with taller line-height (+0.15–0.2) and letter-spacing forced to `0`.

| Token | Size | Line height | Letter spacing | Weight | Family | Usage |
|---|---|---|---|---|---|---|
| `display-2xl` | `clamp(2.75rem, 2rem + 3vw, 4rem)` | 1.05 | -0.02em | 600 | Display | Marketing / empty-state hero only |
| `display-xl` | 2.5rem / 40px | 1.10 | -0.015em | 600 | Display | Page H1 |
| `display-lg` | 2rem / 32px | 1.15 | -0.01em | 600 | Display | Section H2, modal titles |
| `display-md` | 1.5rem / 24px | 1.20 | -0.005em | 600 | Display | Card headers, panel titles |
| `display-sm` | 1.25rem / 20px | 1.25 | 0 | 600 | Display | Sub-panel headers, stat labels, sticky totals |
| `numeral-hero` | `clamp(2rem, 1.5rem + 2vw, 3rem)` | 1.10 | -0.01em | 600 | Display, tabular | One hero KPI per dashboard tile |
| `text-lg` | 1.125rem / 18px | 1.5 | 0 | 400 | Text | Lead paragraph, empty-state body |
| `text-md` | 1rem / 16px | 1.5 | 0 | 400 | Text | Default body/UI, form inputs |
| `text-sm` | 0.875rem / 14px | 1.43 | 0 | 400 | Text | Table cells, secondary UI, buttons |
| `text-xs` | 0.75rem / 12px | 1.4 | 0.01em | 500 | Text | Captions, uppercase column headers |
| `numeral-table` | 0.875rem / 14px | 1.43 | 0 | 400–600, tabular | Text | Financial table cells, right-aligned |
| `code-sm` | 0.8125rem / 13px | 1.4 | 0 | 400 | JetBrains Mono | Account codes, IDs, request IDs |

Every money/quantity/percentage/account-code figure is tabular, enforced by the `<Amount>` primitive,
which also owns the KWD three-decimal (fils) default, currency-as-code, and `dir="ltr"` digit-order
protection. The full `<Amount>` contract is in `../frontend/DESIGN_LANGUAGE.md → Tabular numerals`.

# Spacing

QAYD adopts Tailwind's default 4px scale unmodified; semantic aliases name the handful of values that
recur as *decisions*, not just measurements. The `--space-*` primitives exist so non-Tailwind contexts
(Framer Motion inline styles, chart SVG padding) share the identical steps — not to give Tailwind a
second competing scale (`p-4` already means 16px).

| Semantic token | Value | Tailwind | Usage |
|---|---|---|---|
| `--space-2xs` | 4px | `1` | Icon-to-label gap, chip padding |
| `--space-xs` | 8px | `2` | Tight stack gap, compact cell vertical padding |
| `--space-sm` | 12px | `3` | Form field padding, comfortable cell vertical padding |
| `--space-md` | 16px | `4` | Default stack gap, small-card padding |
| `--space-lg` | 24px | `6` | Default card padding, section gap within a panel |
| `--space-xl` | 32px | `8` | Gap between panels/cards on a page |
| `--space-2xl` | 48px | `12` | Page-level section gap |
| `--space-3xl` | 64px | `16` | Page top padding under the topbar on wide screens |

# Radius

| Token | Value | Usage |
|---|---|---|
| `radius-sm` | 4px | Checkboxes, small tags/badges, inline cell chips |
| `radius-md` | 6px | Inputs, buttons, select triggers |
| `radius-lg` | 8px | Cards, panels, dropdown menus, modal inner content — the workhorse radius |
| `radius-xl` | 10px | Modal/dialog outer surface only |
| `radius-full` | 9999px | Avatars, status dots, pill badges — the only fully-rounded shapes |

A 10px ceiling on any rectangular surface is deliberate: large radii read as consumer/playful; a tight
4–10px range reads as engineered and precise. This supersedes the `8/12/20/24` in the foundation draft
(see [Reconciliation](#reconciliation)).

# Elevation & Surfaces

QAYD builds elevation primarily from **hairline borders**, not shadow depth — an editorial-flat approach
closer to Linear/Stripe than to Material's stacked shadows. Shadows exist but are quiet enough that
removing them would not break the hierarchy on their own.

| Surface | Background | Border | Shadow | Usage |
|---|---|---|---|---|
| `surface-0` | `ink-1` | — | none | App canvas |
| `surface-1` | `ink-2` / `ink-3` | 1px `ink-6` | `shadow-xs` | Cards, panels, sidebar |
| `surface-2` | `ink-1` (light) / `ink-3` (dark) | 1px `ink-6` | `shadow-sm` | Dropdowns, popovers, date pickers |
| `surface-3` | `ink-1` (light) / `ink-3` (dark) | 1px `ink-6` | `shadow-lg` | Modals, dialogs (with `ink-12`/50% scrim) |
| `surface-glass` | `ink-1` @ 72% + `backdrop-blur(24px)` | 1px `ink-6` @ 60% | `shadow-md` | Command palette + AI Command Center **only** |

`surface-glass` is intentionally rare (frosted glass reads as premium because it is unusual, and
`backdrop-blur` is not free on back-office hardware). Dark mode is *lighter as it elevates* — a raised card
(`ink-3` dark) is lighter than the canvas (`ink-1` dark) — which is how physical light and professional
dark UIs actually behave.

## Shadow tokens

```css
--shadow-xs: 0 1px 2px rgba(21, 19, 14, 0.04);
--shadow-sm: 0 2px 4px rgba(21, 19, 14, 0.06);
--shadow-md: 0 8px 16px -4px rgba(21, 19, 14, 0.08);
--shadow-lg: 0 24px 48px -12px rgba(21, 19, 14, 0.16);
```

In dark mode the same shadows are redefined with `rgba(0, 0, 0, …)` at roughly 50% of the light-mode
alpha — a soft shadow reads as "too heavy" against a dark background at light-mode opacity.

# Motion

Motion is implemented with Framer Motion end to end (no ad-hoc CSS transitions on interactive
components), so duration, easing, and reduced-motion handling stay centralized in `lib/motion.ts`.

| Token | Duration | Used for |
|---|---|---|
| `motion.instant` | 0ms | State flips under `prefers-reduced-motion` |
| `motion.micro` | 120ms | Button press scale, checkbox check, icon hover |
| `motion.fast` | 160ms | Tooltip/dropdown appear, row hover highlight |
| `motion.base` | 200ms | Tab switch, accordion expand, panel content swap |
| `motion.moderate` | 280ms | Modal/drawer enter/exit, popover with content |
| `motion.slow` | 360ms | Route/page transition, onboarding step transition |

```ts
// lib/motion.ts (easing curves)
export const easeOut   = [0.16, 1, 0.3, 1] as const;    // entrances
export const easeInOut = [0.65, 0, 0.35, 1] as const;   // toggles, expand/collapse
export const easeIn    = [0.7, 0, 0.84, 0] as const;    // exits
```

Every animated component reads `useReducedMotion()` via a `getTransition()` helper, backed by the standard
`@media (prefers-reduced-motion: reduce)` query as a framework-independent safety net. Reduced motion
removes the *animation* of a change, never the *state change* itself. Row-entrance stagger is 30ms/row
capped at 12 rows; a posted-entry confirmation is a single checkmark that fades in, holds ~900ms, and
fades out — never confetti. Full named-pattern table in `../frontend/DESIGN_LANGUAGE.md → Motion`.

# Z-Index

| Token | Value | Layer |
|---|---|---|
| `z-base` | 0 | Default document flow |
| `z-sticky` | 10 | Sticky table headers/footers, sticky page sub-nav |
| `z-dropdown` | 20 | Select menus, dropdown menus, popovers |
| `z-overlay` | 30 | Modal/drawer scrim |
| `z-modal` | 40 | Modal/drawer content |
| `z-toast` | 50 | Toast notifications |
| `z-command` | 60 | Command palette, AI Command Center overlay |
| `z-tooltip` | 70 | Tooltips (always above everything, including modals) |

# Breakpoints

Tailwind's default breakpoints plus one addition for the ultra-wide monitors common on accountants' desks.

| Breakpoint | Min width | Layout behavior |
|---|---|---|
| (base) | 0 | Single column, bottom tab bar; tables stack or scroll within a bordered container |
| `sm` | 640px | Two-column forms; tables scroll horizontally with a sticky first column |
| `md` | 768px | Sidebar collapsible rail; 12-column dashboard grid active |
| `lg` | 1024px | Persistent sidebar (264px / 72px collapsed); full table layouts |
| `xl` | 1280px | Two-panel (list + detail) side-by-side instead of drawer |
| `2xl` | 1536px | Content max-width caps at 1440px and centers |
| `3xl` (custom) | 1920px | Content caps at 1600px; used on General Ledger / Trial Balance for the extra columns |

# Light / Dark Remap Mechanism

Dark mode is a **first-class, calibrated theme, not an inverted filter.** `next-themes` drives a
`class="dark"` (and `data-theme`) on `<html>`, system-aware and user-overridable, persisted per user and
mirrored to a cookie so the Server Component renders the correct attribute with no flash. Every token in
the tables above has an explicit dark value; none is a CSS `invert()` or luminance flip. Three rules:

1. **Backgrounds stay warm-neutral, not blue-black** — `ink-1` dark is `#14130F`, carrying the same
   warmth through both themes.
2. **Elevation gets lighter, not darker** — a raised card is lighter than the canvas it sits on.
3. **The accent gets lighter, not more saturated** — `#9C7A34` → `#D9B96C`, holding 4.5:1+ against
   `accent-on` and the dark canvas.

Mechanically, the remap is done once at the CSS-variable layer: `:root` declares the light hex values and
`.dark` re-declares the same `--qayd-*` names with the dark hex values. Every downstream Tailwind utility
and component references the variable name, so the same class produces the correct color in either theme
with no per-component branching. The narrow per-company white-label follows the same principle — only the
`accent` family is overridable, written as a small fixed set of `--brand-*` properties by a `BrandProvider`
(full mechanics and guardrails in [`../frontend/THEMING.md → Company Branding`](../frontend/THEMING.md)).

# Implementation — `app/globals.css`

The raw scale plus the shadcn/ui semantic aliases, exactly as in `../frontend/DESIGN_LANGUAGE.md`.

```css
/* app/globals.css */
:root {
  /* Ink — warm-neutral graphite scale */
  --qayd-ink-1:  #FAFAF9; --qayd-ink-2:  #F4F3F1; --qayd-ink-3:  #EBE9E6;
  --qayd-ink-4:  #E0DEDA; --qayd-ink-5:  #D3D0CA; --qayd-ink-6:  #C2BEB6;
  --qayd-ink-7:  #A9A49A; --qayd-ink-8:  #878174; --qayd-ink-9:  #645F53;
  --qayd-ink-10: #4A4639; --qayd-ink-11: #2B2820; --qayd-ink-12: #15130E;

  /* Accent — one disciplined brass, never used for status */
  --qayd-accent-subtle: #EADFBF;
  --qayd-accent:        #9C7A34;
  --qayd-accent-strong: #7A5D22;
  --qayd-accent-on:     var(--qayd-ink-12);

  /* Semantic — financial polarity only, never brand */
  --qayd-positive: #17794A; --qayd-positive-subtle: #E3F3EA;
  --qayd-negative: #B4232E; --qayd-negative-subtle: #FBE7E8;
  --qayd-warning:  #B45309; --qayd-warning-subtle:  #FBEBDA;

  /* Radius */
  --radius-sm: 4px; --radius-md: 6px; --radius-lg: 8px; --radius-xl: 10px;

  /* shadcn/ui semantic aliases — consumed as hsl(var(--x)) */
  --background: 48 20% 98%;
  --foreground: 40 30% 7%;
  --card: 45 15% 95%;            --card-foreground: 40 30% 7%;
  --popover: 48 20% 99%;         --popover-foreground: 40 30% 7%;
  --primary: 42 50% 38%;         /* = --qayd-accent */
  --primary-foreground: 40 30% 7%;   /* = --qayd-accent-on (near-black, not white) */
  --secondary: 45 15% 92%;       --secondary-foreground: 40 30% 12%;
  --muted: 45 15% 93%;           --muted-foreground: 42 10% 40%;   /* = --qayd-ink-9 */
  /* NOTE: shadcn's own --accent is a NEUTRAL hover tint, NOT the brand color.
     Do not point it at --qayd-accent, or every hovered menu row turns gold. */
  --accent: 45 15% 92%;          --accent-foreground: 40 30% 10%;
  --destructive: 355 65% 40%;    --destructive-foreground: 0 0% 100%;  /* = --qayd-negative */
  --border: 42 14% 78%;          /* = --qayd-ink-6 */
  --input:  42 14% 72%;          /* = --qayd-ink-7 */
  --ring:   42 55% 40%;          /* = --qayd-accent, focus ring */
  --radius: 0.5rem;              /* shadcn base; per-component radius above overrides */
}

.dark {
  --qayd-ink-1:  #14130F; --qayd-ink-2:  #1B1914; --qayd-ink-3:  #24211A;
  --qayd-ink-4:  #2D2921; --qayd-ink-5:  #383327; --qayd-ink-6:  #46402F;
  --qayd-ink-7:  #58503A; --qayd-ink-8:  #786F55; --qayd-ink-9:  #A39878;
  --qayd-ink-10: #C2B99C; --qayd-ink-11: #E4DEC9; --qayd-ink-12: #F8F6EF;

  --qayd-accent-subtle: #3A2E14;
  --qayd-accent:        #D9B96C;
  --qayd-accent-strong: #E8CE8E;
  --qayd-accent-on:     #14130F;

  --qayd-positive: #4ADE94; --qayd-positive-subtle: #12301F;
  --qayd-negative: #F26B74; --qayd-negative-subtle: #3A1618;
  --qayd-warning:  #F5A855; --qayd-warning-subtle:  #3A2812;

  --background: 40 14% 8%;       --foreground: 45 30% 96%;
  --card: 38 13% 11%;            --card-foreground: 45 30% 96%;
  --popover: 40 14% 9%;          --popover-foreground: 45 30% 96%;
  --primary: 40 60% 68%;         --primary-foreground: 40 14% 7%;
  --secondary: 38 12% 15%;       --secondary-foreground: 45 20% 90%;
  --muted: 38 12% 15%;           --muted-foreground: 38 20% 65%;
  --accent: 38 12% 16%;          --accent-foreground: 45 20% 92%;
  --destructive: 355 75% 68%;    --destructive-foreground: 40 14% 8%;
  --border: 40 16% 22%;          --input: 40 16% 26%;   --ring: 40 60% 68%;
}
```

# Implementation — `tailwind.config.ts`

Every entry is a `var(--…)` reference; no color is hard-coded. This is the mapping that turns the CSS
variables above into utility classes (`bg-ink-2`, `text-accent`, `border-ink-6`, `rounded-lg`, `bg-primary`).

```ts
// tailwind.config.ts (excerpt)
import type { Config } from "tailwindcss";

export default {
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        ink: {
          1: "var(--qayd-ink-1)", 2: "var(--qayd-ink-2)", 3: "var(--qayd-ink-3)",
          4: "var(--qayd-ink-4)", 5: "var(--qayd-ink-5)", 6: "var(--qayd-ink-6)",
          7: "var(--qayd-ink-7)", 8: "var(--qayd-ink-8)", 9: "var(--qayd-ink-9)",
          10: "var(--qayd-ink-10)", 11: "var(--qayd-ink-11)", 12: "var(--qayd-ink-12)",
        },
        accent: {
          subtle: "var(--qayd-accent-subtle)", DEFAULT: "var(--qayd-accent)",
          strong: "var(--qayd-accent-strong)", on: "var(--qayd-accent-on)",
        },
        positive: { DEFAULT: "var(--qayd-positive)", subtle: "var(--qayd-positive-subtle)" },
        negative: { DEFAULT: "var(--qayd-negative)", subtle: "var(--qayd-negative-subtle)" },
        warning:  { DEFAULT: "var(--qayd-warning)",  subtle: "var(--qayd-warning-subtle)" },
        // shadcn semantic aliases
        background: "hsl(var(--background))",
        foreground: "hsl(var(--foreground))",
        primary:     { DEFAULT: "hsl(var(--primary))",     foreground: "hsl(var(--primary-foreground))" },
        secondary:   { DEFAULT: "hsl(var(--secondary))",   foreground: "hsl(var(--secondary-foreground))" },
        muted:       { DEFAULT: "hsl(var(--muted))",       foreground: "hsl(var(--muted-foreground))" },
        destructive: { DEFAULT: "hsl(var(--destructive))", foreground: "hsl(var(--destructive-foreground))" },
        border: "hsl(var(--border))", input: "hsl(var(--input))", ring: "hsl(var(--ring))",
      },
      borderRadius: {
        sm: "var(--radius-sm)", md: "var(--radius-md)",
        lg: "var(--radius-lg)", xl: "var(--radius-xl)",
      },
      fontFamily: {
        display: ["var(--font-display)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
        text:    ["var(--font-text)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
        arabic:  ["var(--font-arabic)", "ui-sans-serif", "system-ui"],
        mono:    ["var(--font-mono)", "ui-monospace", "SFMono-Regular", "monospace"],
      },
      screens: { "3xl": "1920px" },
    },
  },
} satisfies Config;
```

# Tokens for Motion & Charts — `lib/tokens.ts`

CSS custom properties cannot be read inside a Framer Motion `transition` object or a canvas `fillStyle`
computation without an extra `getComputedStyle` round-trip, so motion and chart code import the same
values as plain JS from `lib/tokens.ts`, kept in lock-step with `globals.css`.

```ts
// lib/tokens.ts — plain values for Framer Motion and canvas/SVG chart code
export const tokens = {
  ink: {
    1: "#FAFAF9", 2: "#F4F3F1", 3: "#EBE9E6", 4: "#E0DEDA", 5: "#D3D0CA", 6: "#C2BEB6",
    7: "#A9A49A", 8: "#878174", 9: "#645F53", 10: "#4A4639", 11: "#2B2820", 12: "#15130E",
  },
  inkDark: {
    1: "#14130F", 2: "#1B1914", 3: "#24211A", 4: "#2D2921", 5: "#383327", 6: "#46402F",
    7: "#58503A", 8: "#786F55", 9: "#A39878", 10: "#C2B99C", 11: "#E4DEC9", 12: "#F8F6EF",
  },
  accent:     { subtle: "#EADFBF", default: "#9C7A34", strong: "#7A5D22", on: "#15130E" },
  accentDark: { subtle: "#3A2E14", default: "#D9B96C", strong: "#E8CE8E", on: "#14130F" },
  semantic:   { positive: "#17794A", negative: "#B4232E", warning: "#B45309" },
  motion: {
    duration: { instant: 0, micro: 0.12, fast: 0.16, base: 0.2, moderate: 0.28, slow: 0.36 },
    easeOut: [0.16, 1, 0.3, 1], easeInOut: [0.65, 0, 0.35, 1], easeIn: [0.7, 0, 0.84, 0],
  },
} as const;
```

Chart color draws from the ink scale + accent + semantic set only, never an arbitrary categorical
rainbow: a two-series comparison uses `accent` against `ink-7`; a signed metric uses `positive`/`negative`;
a many-series chart varies **value** (light-to-dark tints of the accent) before reaching for a second hue,
and falls back to direct data labels once it would otherwise need a fourth or fifth color.

# The Governing Rule — reference a token, never a literal

Component and screen code may reference only Tailwind utilities backed by tokens (`bg-ink-2`,
`text-accent`, `border-ink-6`, `rounded-lg`, `bg-primary`) or a semantic/component CSS variable
(`var(--qayd-accent)`, `var(--brand-accent)`); it may never write a raw hex, `rgb()`, or px value that a
token already covers. This is enforced, not merely requested:

| Enforcement | What it does |
|---|---|
| Stylelint disallow-list | Bans raw hex/`rgb()` in component source; bans referencing a raw `--qayd-{primitive}` from outside `globals.css` |
| Token-name regex | Rejects a stray `--brandColor`/`--Accent` that doesn't match the naming grammar |
| `scripts/check-contrast.ts` (CI) | Fails any PR touching the palette whose `fg`/`bg` pairing drops below AA in either theme |
| ESLint logical-property rule | Blocks physical `ml/mr/pl/pr`, `text-left/right` outside a reviewed allow-list (RTL correctness) |
| Design-Systems review | Required to add a primitive or a semantic token; a new semantic token needs both-theme values + a contrast proof |
| Quarterly drift audit | Greps shipped code for inline `style={{ color }}` literals to promote into a token or remove |

Full governance process is in [`../frontend/THEMING.md → Governance`](../frontend/THEMING.md).

# Reconciliation

Three frontend documents currently express the token layer differently. This catalogue follows
[`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md), which is **canonical**; the others are
pre-canonical drafts to be reconciled *to* this system. The divergences, recorded so no one mistakes a
draft value for a live one:

| Dimension | Canonical — `DESIGN_LANGUAGE.md` (this catalogue) | `THEMING.md` (draft) | `COMPONENT_LIBRARY.md` (draft) | `foundation/DESIGN_SYSTEM.md` (superseded) |
|---|---|---|---|---|
| Accent hue | Brass `#9C7A34` (light) / `#D9B96C` (dark) | Emerald `#157A4D` (`emerald-600`) | Teal-green `--qayd-accent-600 #0E7A5F` | "Emerald Green" (unnamed) |
| Ink scale naming | `ink-1 … ink-12` (`--qayd-ink-*`) | `color-ink-0 … 950` (`--color-*`) | `--qayd-ink-0 … 950` | unnamed neutral gray |
| Ink undertone | Warm-neutral graphite | Cool-green undertone | (per component draft) | White / Dark Gray |
| Radius scale | `4 / 6 / 8 / 10 px` | `6 / 10 / 14 / 20 px` | `sm/md/lg/xl` (values per draft) | `8 / 12 / 20 / 24 px` |
| Token architecture | `--qayd-*` raw + shadcn HSL aliases | 3-tier primitive → semantic → component (`--color-*`) | `--qayd-*` numeric-step scale | prose only |
| Display face | General Sans | Inter Tight | (per component draft) | Inter only |
| Root font size | 16px | 15px (`0.9375rem` base) | — | — |
| Info color | None (ink scale only; AI notices use `accent-subtle`) | `--color-info` blue present | — | Blue "Info" present |

**Resolution.** For all new work, use the canonical column. The emerald/teal accents, the `0–950` and
`--color-*` naming, the wider `6/10/14/20` and `8/12/20/24` radii, the Inter-Tight display face, the 15px
root, and the blue "info" color are **not** to be adopted. The other documents should be reconciled to this
catalogue over time; until they are, where any of them disagrees with a value here, this catalogue (and
`DESIGN_LANGUAGE.md`) governs, and the conflict is raised rather than silently resolved in a screen's code.
Note that `DESIGN_LANGUAGE.md` itself already declares it supersedes the `foundation/DESIGN_SYSTEM.md`
draft on color, radius, and typography.

# End of Document
