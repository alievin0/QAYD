# Colors — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Foundations / COLORS
---

# Purpose

This document is the **atomic color-token reference** for QAYD — the flat, exhaustive list of every color
CSS variable the frontend renders, each with its exact light value, its exact dark value, and the single
role it plays. It is a foundation-layer document: where [../COLOR_SYSTEM.md](../COLOR_SYSTEM.md) explains
*why* the palette is shaped the way it is (the philosophy, the debit/credit rule, the "never color alone"
contract, the chart-palette derivation), this document is the specialization you reach for when you already
know the rules and just need the value of a token, its dark counterpart, and where it is allowed to appear.

QAYD is an AI Financial Operating System, trusted with a company's books to the fils, so its palette is
deliberately austere: a **twelve-step warm-neutral ink scale** carries almost the entire interface, **one**
brass **accent** (with four states) is spent only on the single primary action per screen and on anything
the AI layer has touched, and **three** semantic financial hues (`positive` / `negative` / `warning`, each
with a subtle wash) mark financial state and nothing else. There is deliberately **no fourth semantic hue**
and **no "info" color** — a fifth hue anywhere is a defect, not a gap. Every value below exists as a
`--qayd-*` CSS custom property in `app/globals.css`, as a Tailwind color utility, and as a plain-JS entry
in `lib/tokens.ts`; nothing in a component ever hardcodes a hex.

This document covers: the complete ink scale (`ink-1`..`ink-12`); the accent family and its four states;
the three semantic financial hues and their washes; the surface and border color roles; the chart palette
(categorical, sequential, diverging); and the naming grammar, usage rules, and accessibility floor that
govern them. Every token is stated for both light and dark at once — dark mode here is a first-class,
hand-calibrated theme, never a luminance inversion.

Related reading: [../COLOR_SYSTEM.md](../COLOR_SYSTEM.md) (the full color rulebook this document indexes),
[../DESIGN_TOKENS.md](../DESIGN_TOKENS.md) (the aggregate token catalogue),
[./TYPOGRAPHY_SCALE.md](./TYPOGRAPHY_SCALE.md) (the type these colors are drawn in), and
[./SPACING_SCALE.md](./SPACING_SCALE.md) (the surfaces these colors fill).

# The Scale

Every color token QAYD renders, in one place. Light and dark are both explicit values — dark is authored,
not derived.

## Ink scale (warm-neutral graphite, 12 steps)

The near-monochrome foundation follows the Radix-Colors usage convention: 1–2 backgrounds, 3–5 component
fills, 6–8 borders/disabled, 9–10 icons/secondary text, 11–12 high-emphasis text.

| Token | Hex (light) | Hex (dark) | Role |
|---|---|---|---|
| `ink-1`  | `#FAFAF9` | `#14130F` | App canvas background (the scrollable page region) |
| `ink-2`  | `#F4F3F1` | `#1B1914` | Sidebar / subtle recessed background |
| `ink-3`  | `#EBE9E6` | `#24211A` | Card / component / panel background |
| `ink-4`  | `#E0DEDA` | `#2D2921` | Hover fill of an interactive surface |
| `ink-5`  | `#D3D0CA` | `#383327` | Active / selected fill |
| `ink-6`  | `#C2BEB6` | `#46402F` | Hairline border, default divider |
| `ink-7`  | `#A9A49A` | `#58503A` | Input border, stronger structural divider |
| `ink-8`  | `#878174` | `#786F55` | Disabled text/icon, placeholder |
| `ink-9`  | `#645F53` | `#A39878` | Secondary text, default icon |
| `ink-10` | `#4A4639` | `#C2B99C` | Primary (non-accent) icon, strong secondary text |
| `ink-11` | `#2B2820` | `#E4DEC9` | Headings, high-emphasis body text |
| `ink-12` | `#15130E` | `#F8F6EF` | Display text, hero numeral, near-black / near-white |

## Accent (one brass, four states)

QAYD's single brand color. Used **only** for the one primary action per screen and for AI-touched elements.
Any third use is a defect.

| Token | Hex (light) | Hex (dark) | Role |
|---|---|---|---|
| `accent-subtle` | `#EADFBF` | `#3A2E14` | Selected-row tint, AI-proposal card background, AI notice background |
| `accent`        | `#9C7A34` | `#D9B96C` | Primary button fill, focus ring, active tab, links |
| `accent-strong` | `#7A5D22` | `#E8CE8E` | Hover / pressed state of the above |
| `accent-on`     | `#15130E` | `#14130F` | Text / icon drawn on top of an `accent` fill — **near-black, never white** |

`accent-on` is near-black because a mid-value warm brass clears only ~3.2:1 against white (large-text only)
but ~5.5:1 against `ink-12`. In light mode `accent-on` resolves to `ink-12` (`#15130E`); in dark mode the
fill lightens to `#D9B96C` and `accent-on` becomes the near-black `#14130F`, preserving the relationship.

## Semantic — financial state only (three hues + washes)

Each solid hue marks a financial state; each has a subtle background wash. None is ever used for a brand
action or an AI badge, and none ever colors a raw debit or credit amount.

| Token | Hex (light) | Hex (dark) | Role | Never use for |
|---|---|---|---|---|
| `positive`        | `#17794A` | `#4ADE94` | Increase, gain, reconciled, success | Brand actions, AI badges |
| `positive-subtle` | `#E3F3EA` | `#12301F` | Positive background wash | — |
| `negative`        | `#B4232E` | `#F26B74` | Decrease, loss, overdue, destructive | Brand actions, AI badges |
| `negative-subtle` | `#FBE7E8` | `#3A1618` | Negative background wash | — |
| `warning`         | `#B45309` | `#F5A855` | Pending, unreconciled, nearing a limit | Brand accent (kept more orange than brass) |
| `warning-subtle`  | `#FBEBDA` | `#3A2812` | Warning background wash | — |
| `info`            | *(none)*  | *(none)*  | Neutral non-financial notice → ink scale only | Any dedicated blue hue |

There is deliberately **no `info` token.** A plain informational banner is `ink-11` text on an `ink-3`
background with an `ink-6` border. The single exception is an *AI-sourced* notice, which may use
`accent-subtle` because "this came from the AI layer" is exactly the signal the accent is reserved to carry.

## Surface & border color roles

Elevation in QAYD comes from hairline borders far more than from shadow depth; this table gives only the
*color* of each surface (shadow tokens live in [../DESIGN_TOKENS.md](../DESIGN_TOKENS.md)).

| Surface | Background (light) | Background (dark) | Border |
|---|---|---|---|
| App canvas | `ink-1` | `ink-1` | — |
| Card / panel / sidebar | `ink-2` or `ink-3` | `ink-3` | 1px `ink-6` |
| Dropdown / popover | `ink-1` | `ink-3` | 1px `ink-6` |
| Modal / dialog | `ink-1` | `ink-3` | 1px `ink-6` (over `ink-12` @ 50% scrim) |
| Glass overlay (⌘K / AI Command Center only) | `ink-1` @ 72% + blur | `ink-1` @ 72% + blur | 1px `ink-6` @ 60% |
| Hover fill | `ink-4` | `ink-4` | inherit |
| Active / selected fill | `ink-5` | `ink-5` | inherit (AI-selected rows tint `accent-subtle`) |

**Elevation lightens in dark mode:** a raised card (`ink-3` dark, `#24211A`) is lighter than the canvas
(`ink-1` dark, `#14130F`) it sits on. Borders (`ink-6` default, `ink-7` stronger) are the primary structural
signal in both themes.

## Chart palette

Data visualization draws from the ink scale + accent + semantic set only — never a categorical rainbow. The
governing move is **vary value before you reach for a second hue.** Categorical: 1 series = `accent`;
2 = `accent` + `ink-7`; 3 = `accent`, `accent` @ 60% value, `ink-7`; 4–6 = accent-tint ramp with direct
labels; 7+ = direct data labels over a single-hue set.

**Sequential** (one metric, low → high) — anchored on the accent; intermediate steps are the only
sanctioned chart-derived values, and they live in `lib/tokens.ts` under a `chart` namespace:

| Step | Light | Dark |
|---|---|---|
| Lowest | `#EADFBF` (`accent-subtle`) | `#3A2E14` |
| Low-mid | `#D9C289` | `#6B531F` |
| Mid | `#C1A45C` | `#9C7A34` |
| High-mid | `#9C7A34` (`accent`) | `#C79E4E` |
| Highest | `#7A5D22` (`accent-strong`) | `#D9B96C` |

**Diverging** (signed net metric, negative ↔ neutral ↔ positive) — the one place two hues are correct:

| Pole | Light | Dark |
|---|---|---|
| Negative extreme | `#B4232E` (`negative`) | `#F26B74` |
| Negative wash | `#FBE7E8` (`negative-subtle`) | `#3A1618` |
| Neutral midpoint | `#C2BEB6` (`ink-6`) | `#46402F` |
| Positive wash | `#E3F3EA` (`positive-subtle`) | `#12301F` |
| Positive extreme | `#17794A` (`positive`) | `#4ADE94` |

# Tokens & Naming

Every color traces a fixed chain; nothing in application code references a raw value directly.

```
Raw scale (--qayd-*)   →   shadcn semantic alias (hsl var)   →   Tailwind utility   →   pixel
  ink-1…12, accent,         --background / --primary /            bg-ink-2,             rendered
  positive/negative...      --border / --ring / --destructive     text-accent, border-ink-6
```

| Layer | Pattern | Example | Allowed value |
|---|---|---|---|
| Raw scale | `--qayd-{category}-{step}` | `--qayd-ink-2`, `--qayd-accent`, `--qayd-positive` | Literal hex (light under `:root`, dark under `.dark`) |
| shadcn semantic alias | `--{role}` (unitless HSL triplet) | `--background`, `--primary`, `--ring`, `--border` | HSL triplet, consumed as `hsl(var(--x))` |
| Tailwind utility | mapped in `theme.extend` | `bg-ink-2`, `text-accent`, `border-ink-6` | `var(--…)` reference only |

The `--qayd-*` prefix namespaces the scale so it never collides with a shadcn or third-party variable. The
shadcn aliases are stored as **unitless HSL triplets** (`42 50% 38%`) so Tailwind opacity modifiers
(`bg-primary/10`, `text-ink-9/60`) work via `hsl(var(--x) / <alpha-value>)`.

## Token definitions (`app/globals.css`)

Values are declared once; nothing else in the codebase repeats a hex.

```css
/* app/globals.css */
:root {
  --qayd-ink-1: #FAFAF9;  --qayd-ink-2: #F4F3F1;  --qayd-ink-3: #EBE9E6;
  --qayd-ink-4: #E0DEDA;  --qayd-ink-5: #D3D0CA;  --qayd-ink-6: #C2BEB6;
  --qayd-ink-7: #A9A49A;  --qayd-ink-8: #878174;  --qayd-ink-9: #645F53;
  --qayd-ink-10: #4A4639; --qayd-ink-11: #2B2820; --qayd-ink-12: #15130E;

  --qayd-accent-subtle: #EADFBF;
  --qayd-accent: #9C7A34;
  --qayd-accent-strong: #7A5D22;
  --qayd-accent-on: var(--qayd-ink-12);

  --qayd-positive: #17794A;  --qayd-positive-subtle: #E3F3EA;
  --qayd-negative: #B4232E;  --qayd-negative-subtle: #FBE7E8;
  --qayd-warning:  #B45309;  --qayd-warning-subtle:  #FBEBDA;
}

.dark {
  --qayd-ink-1: #14130F;  --qayd-ink-2: #1B1914;  --qayd-ink-3: #24211A;
  --qayd-ink-4: #2D2921;  --qayd-ink-5: #383327;  --qayd-ink-6: #46402F;
  --qayd-ink-7: #58503A;  --qayd-ink-8: #786F55;  --qayd-ink-9: #A39878;
  --qayd-ink-10: #C2B99C; --qayd-ink-11: #E4DEC9; --qayd-ink-12: #F8F6EF;

  --qayd-accent-subtle: #3A2E14;
  --qayd-accent: #D9B96C;
  --qayd-accent-strong: #E8CE8E;
  --qayd-accent-on: #14130F;

  --qayd-positive: #4ADE94;  --qayd-positive-subtle: #12301F;
  --qayd-negative: #F26B74;  --qayd-negative-subtle: #3A1618;
  --qayd-warning:  #F5A855;  --qayd-warning-subtle:  #3A2812;
}
```

## The shadcn `--accent` trap

shadcn's own `--accent` / `--accent-foreground` are a **neutral** hover/highlight tint (menu-item hover, tab
hover), *not* the brand color. Pointing shadcn's `--accent` at `--qayd-accent` turns every hovered menu row
gold.

```css
:root {
  --primary: 42 50% 38%;            /* = --qayd-accent */
  --primary-foreground: 40 30% 7%;  /* = --qayd-accent-on, near-black not white */
  --accent: 45 15% 92%;             /* NEUTRAL hover tint — NOT the brand brass */
  --destructive: 355 65% 40%;       /* = --qayd-negative */
  --border: 42 14% 78%;             /* = --qayd-ink-6 */
  --ring: 42 55% 40%;               /* = --qayd-accent, focus ring */
}
```

## Plain-JS export (`lib/tokens.ts`)

Framer Motion and canvas/SVG chart code cannot read CSS custom properties without a `getComputedStyle`
round-trip, so they import literal values, kept in lock-step with `globals.css`.

```ts
// lib/tokens.ts (color subset)
export const tokens = {
  ink:      { 1:"#FAFAF9",2:"#F4F3F1",3:"#EBE9E6",4:"#E0DEDA",5:"#D3D0CA",6:"#C2BEB6",
              7:"#A9A49A",8:"#878174",9:"#645F53",10:"#4A4639",11:"#2B2820",12:"#15130E" },
  inkDark:  { 1:"#14130F",2:"#1B1914",3:"#24211A",4:"#2D2921",5:"#383327",6:"#46402F",
              7:"#58503A",8:"#786F55",9:"#A39878",10:"#C2B99C",11:"#E4DEC9",12:"#F8F6EF" },
  accent:     { subtle:"#EADFBF", default:"#9C7A34", strong:"#7A5D22", on:"#15130E" },
  accentDark: { subtle:"#3A2E14", default:"#D9B96C", strong:"#E8CE8E", on:"#14130F" },
  semantic:   { positive:"#17794A", negative:"#B4232E", warning:"#B45309" },
} as const;
```

# Usage

**Apply through Tailwind utilities that resolve to the variables.** The same token name (`ink-6`) points at
`#C2BEB6` in light and `#46402F` in dark, so a component is correct in both themes with no conditional class.

```tsx
<section className="bg-ink-1">                          {/* canvas */}
  <aside className="bg-ink-2 border-e border-ink-6">    {/* sidebar, logical border */}
  <article className="bg-ink-3 border border-ink-6">    {/* card */}
    <h3 className="text-ink-12">Cash Position</h3>       {/* display text */}
    <p  className="text-ink-9">Updated 2 minutes ago</p> {/* secondary text */}
  </article>
</section>
```

**Pair every `accent` fill with `accent-on`, never white.**

```tsx
// Correct — near-black label on brass.
<button className="bg-accent text-accent-on hover:bg-accent-strong rounded-md">Post entry</button>

// Wrong — white on brass fails AA as text.
<button className="bg-accent text-white">Post entry</button>
```

**Plain vs AI-sourced notices** — the one place `accent-subtle` is allowed as a banner background:

```tsx
// Plain system notice — ink only, no hue.
<div className="bg-ink-3 border border-ink-6 text-ink-11 rounded-lg p-4">
  Your session will expire in 5 minutes.
</div>

// AI-sourced notice — the one allowed accent-subtle background.
<div className="bg-accent-subtle border border-ink-6 text-ink-11 rounded-lg p-4">
  <LedgerTick className="text-accent size-4" aria-hidden />
  The Accountant Agent suggests reclassifying 3 transactions. Review and confirm.
</div>
```

**AI confidence bands** pair a label and glyph with color — never color alone:

| Confidence band | Label | Color treatment | Non-color signal |
|---|---|---|---|
| High (≥ 85%) | "High confidence" | `accent` text on `accent-subtle` | Filled ledger-tick glyph + numeric % in tooltip |
| Medium (60–84%) | "Review suggested" | `ink-11` text on `ink-3`, `accent` dot | Hollow-tick glyph + numeric % |
| Low (< 60%) | "Low confidence — verify" | `warning` text on `warning-subtle` | Warning triangle glyph + numeric % |

**Debit/credit stay neutral.** Raw debit and credit columns render in plain `ink-12` tabular numerals,
distinguished by column position — never `positive`/`negative`. Color is reserved for genuinely signed, net
metrics (Net Income, a variance, a delta), and even there a small delta chip is colored, not the whole
numeral. See [./TYPOGRAPHY_SCALE.md](./TYPOGRAPHY_SCALE.md) for the `<Amount>` primitive.

# Do / Don't

| Do | Don't |
|---|---|
| Build the interface in ink first, add color where it earns its place | Tint half a screen to create hierarchy |
| Spend `accent` on the one primary action and AI-touched elements only | Use brass as a generic highlight anywhere convenient |
| Pair `accent` fills with near-black `accent-on` | Put white text on a brass fill (fails AA) |
| Render raw debit/credit columns in neutral `ink-12` | Color debits red and credits green |
| Color only signed, net metrics with `positive`/`negative` | Wash a whole numeral in red/green |
| Style plain info banners with the ink scale | Invent a blue "info" hue and acquire a second brand color |
| Pair every AI/confidence color with a glyph or label | Rely on a colored row background alone to mean "AI" |
| Vary value (accent tints) before adding a chart hue | Reach for a categorical rainbow at four+ series |
| Author explicit dark-mode token values | Derive dark mode with `invert()` |
| Reference every color as a `--qayd-*` token or Tailwind utility | Hardcode a hex in a component, inline style, or chart |

# Accessibility Notes

QAYD targets **WCAG 2.1 AA** as a floor across both themes and both languages: 4.5:1 for normal text, 3:1
for large text and non-text UI (borders, focus rings, iconographic state). Disabled controls are the one
tolerated sub-4.5:1 case, and only because a disabled control's information is reinforced by a tooltip and
an `aria-disabled` / `cursor-not-allowed` state.

## Verified pairings — light theme

| Foreground | Background | Ratio | Requirement | Result |
|---|---|---|---|---|
| `ink-12` `#15130E` | `ink-1` `#FAFAF9` | ~17.8:1 | 4.5:1 | Pass |
| `ink-11` `#2B2820` | `ink-1` `#FAFAF9` | ~13.1:1 | 4.5:1 | Pass |
| `ink-9` `#645F53` (secondary) | `ink-1` `#FAFAF9` | ~5.1:1 | 4.5:1 | Pass |
| `accent-on` `#15130E` | `accent` `#9C7A34` | ~5.5:1 | 4.5:1 | Pass |
| `accent` `#9C7A34` (link) | `ink-1` `#FAFAF9` | ~4.6:1 | 4.5:1 | Pass |
| `positive` `#17794A` | `positive-subtle` `#E3F3EA` | ~4.8:1 | 4.5:1 | Pass |
| `negative` `#B4232E` | `negative-subtle` `#FBE7E8` | ~5.2:1 | 4.5:1 | Pass |
| `warning` `#B45309` | `warning-subtle` `#FBEBDA` | ~4.7:1 | 4.5:1 | Pass |
| `ink-8` `#878174` (disabled) | `ink-3` `#EBE9E6` | ~2.9:1 | 3:1 (exempt) | Tolerated |

## Verified pairings — dark theme

| Foreground | Background | Ratio | Requirement | Result |
|---|---|---|---|---|
| `ink-12` `#F8F6EF` | `ink-1` `#14130F` | ~16.9:1 | 4.5:1 | Pass |
| `ink-11` `#E4DEC9` | `ink-1` `#14130F` | ~13.0:1 | 4.5:1 | Pass |
| `ink-9` `#A39878` (secondary) | `ink-1` `#14130F` | ~5.4:1 | 4.5:1 | Pass |
| `accent-on` `#14130F` | `accent` `#D9B96C` | ~9.6:1 | 4.5:1 | Pass |
| `accent` `#D9B96C` (link) | `ink-1` `#14130F` | ~9.0:1 | 4.5:1 | Pass |
| `positive` `#4ADE94` | `ink-1` `#14130F` | ~10.2:1 | 4.5:1 | Pass |
| `negative` `#F26B74` | `ink-1` `#14130F` | ~6.6:1 | 4.5:1 | Pass |
| `warning` `#F5A855` | `ink-1` `#14130F` | ~9.3:1 | 4.5:1 | Pass |
| `ink-8` `#786F55` (disabled) | `ink-3` `#24211A` | ~3.0:1 | 3:1 (exempt) | Tolerated |

Ratios use the WCAG 2.x relative-luminance formula and are approximate to one decimal. **Color never carries
meaning alone** (WCAG 1.4.1): every color-coded signal — a delta, an AI dot, a warning banner, a chart series
— has a redundant glyph, shape, label, or position, so a screen reads correctly in grayscale and in printed
board packs. Any token hex change must be re-verified against these tables before it ships, and any
permission-gated disabled control must carry a tooltip naming the required permission.

# End of Document
