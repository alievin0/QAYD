# Typography Scale — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Foundations / TYPOGRAPHY_SCALE
---

# Purpose

This document is the **atomic type-scale reference** for QAYD — the flat, exhaustive table of every named
size step (its rem/px value, line height, weight, letter spacing, family, and use) plus the four font stacks
that render them. It is a foundation-layer document: where [../TYPOGRAPHY.md](../TYPOGRAPHY.md) explains
*why* the system is shaped the way it is (numbers as the hero, the two-Latin-face split, the `<Amount>`
primitive, the Arabic typesetting rationale), this document is the specialization you reach for when you
already know the rules and just need the exact value of a step and the token that names it.

QAYD is an AI Financial Operating System designed around one non-negotiable fact: **numbers are the hero.**
A column of amounts that does not align vertically is an immediate "this wasn't built by people who use
spreadsheets" signal, so tabular figures, right-alignment, and a dedicated `<Amount>` primitive are
load-bearing. Around that core the system pairs a grotesque **display** face (General Sans) with a clean
**text** face (Inter), adds one Arabic family (IBM Plex Sans Arabic) that serves both display and text roles
in Arabic locale, and reserves a **monospace** (JetBrains Mono) for account codes and IDs. The family count
is fixed at four and the scale is a **closed set** of named steps — a one-off `text-[17px]` is a defect, not
a convenience.

This document covers: the four type families and their stacks; the complete type scale as a table (token,
size, line height, letter spacing, weight, family, usage); the three clusters (display, numeral, text); the
semantic heading map; the Arabic line-height and tracking overrides; the responsive/fluid tokens; and the
usage rules that keep the scale closed. Every value is stated for both Latin and Arabic where they differ.

Related reading: [../TYPOGRAPHY.md](../TYPOGRAPHY.md) (the full typography rulebook this document indexes),
[../DESIGN_TOKENS.md](../DESIGN_TOKENS.md) (the aggregate token catalogue),
[./COLORS.md](./COLORS.md) (the ink colors these type roles are drawn in), and
[./SPACING_SCALE.md](./SPACING_SCALE.md) (the row heights and cell padding these sizes pair with).

# The Scale

All sizes are defined in `rem` against a **16px root** and exposed as Tailwind utilities plus a matching CSS
variable for use outside Tailwind (Framer Motion `style` props, canvas chart labels). This is the complete,
closed set — twelve named steps in three clusters.

| Token | Size (rem / px) | Line height | Letter spacing | Weight | Family | Usage |
|---|---|---|---|---|---|---|
| `display-2xl` | `clamp(2.75rem, 2rem + 3vw, 4rem)` | 1.05 | -0.02em | 600 | Display | Marketing / empty-state hero only |
| `display-xl` | 2.5rem / 40px | 1.10 | -0.015em | 600 | Display | Page H1 ("General Ledger") |
| `display-lg` | 2rem / 32px | 1.15 | -0.01em | 600 | Display | Section H2, modal titles |
| `display-md` | 1.5rem / 24px | 1.20 | -0.005em | 600 | Display | Card headers, panel titles |
| `display-sm` | 1.25rem / 20px | 1.25 | 0 | 600 | Display | Sub-panel headers, stat-tile labels, sticky-footer totals |
| `numeral-hero` | `clamp(2rem, 1.5rem + 2vw, 3rem)` | 1.10 | -0.01em | 600 | Display, tabular | One hero KPI number per dashboard tile |
| `text-lg` | 1.125rem / 18px | 1.5 | 0 | 400 | Text | Lead paragraph, empty-state body |
| `text-md` | 1rem / 16px | 1.5 | 0 | 400 | Text | Default body / UI, form inputs |
| `text-sm` | 0.875rem / 14px | 1.43 | 0 | 400 | Text | Table cells, secondary UI, buttons |
| `text-xs` | 0.75rem / 12px | 1.4 | 0.01em | 500 | Text | Captions, uppercase table column headers |
| `numeral-table` | 0.875rem / 14px | 1.43 | 0 | 400–600, tabular | Text | All financial table cells, right-aligned |
| `code-sm` | 0.8125rem / 13px | 1.4 | 0 | 400 | JetBrains Mono | Account codes, IDs, request IDs |

## Reading the scale — three clusters

**Display steps** (`display-2xl` → `display-sm`) descend from a marketing hero to a stat-tile label, all in
General Sans weight 600 with progressively **tighter negative tracking as size grows** — the editorial move
that makes a 40px H1 read as composed rather than loose. **Numeral steps** (`numeral-hero`, `numeral-table`)
are the two figure contexts: one hero number per tile in the display face, and every table-cell figure in
the text face with tabular figures on. **Text steps** (`text-lg` → `text-xs`) run body copy down to
captions; `text-xs` alone carries positive `0.01em` tracking and weight 500 because it is used uppercase for
table column headers, where a hair of tracking and extra weight restores legibility lost at 12px caps.

## Semantic heading map

The mapping from semantic heading level to scale token is fixed. Headings use General Sans weight 600 in
`ink-11`/`ink-12` (see [./COLORS.md](./COLORS.md)).

| Element | Token | Color |
|---|---|---|
| Page H1 | `display-xl` | `ink-12` |
| Section H2 / modal title | `display-lg` | `ink-12` |
| Card header / panel title | `display-md` | `ink-12` |
| Sub-panel header / stat-tile label | `display-sm` | `ink-11` |

The display face is never used below `display-sm` (20px) and never sets a paragraph. A "heading" smaller
than 20px is not a heading — it is a `text-xs` uppercase label in the text face.

## Numeral formatting rules

Every money / quantity / percentage / account-code figure is set in **tabular (fixed-width) figures**,
enforced by the `<Amount>` primitive rather than call-site discipline.

| Rule | Applies to | Example |
|---|---|---|
| Tabular figures, right-aligned | Every numeric column | — |
| Minus sign for negative | Raw / editable data — ledger tables, form inputs, exports | `-1,204.500` |
| Parentheses for negative | Rendered statements — Balance Sheet, P&L, Cash Flow | `(1,204.500)` |
| Three decimal places | KWD, BHD, OMR amounts | `KD 1,204.500` |
| Two decimal places | USD, SAR, AED, all other currencies | `$1,204.50` |
| Em dash, not zero, for N/A | A cell where a metric genuinely does not apply | `—` |
| Explicit zero for a real zero balance | A reconciled account, a fully paid invoice | `0.000` |
| Currency as **code**, not symbol | Every amount, always | `KD 1,204.500` |

# Tokens & Naming

## Type families and their stacks

QAYD uses two Latin families, one Arabic family, and one monospace — each doing exactly one job.

| Role | Family | Script | Weights | Source / license |
|---|---|---|---|---|
| Display | **General Sans** | Latin | 500, 600, 700 | Fontshare Free License, self-hosted via `next/font/local` |
| Text / UI | **Inter** | Latin | 400, 500, 600, 700 | SIL OFL 1.1, `next/font/google` |
| Arabic (display + text) | **IBM Plex Sans Arabic** | Arabic | 400, 500, 600, 700 | SIL OFL 1.1, `next/font/google` |
| Numeric / code | **JetBrains Mono** | Latin | 400, 500 | SIL OFL 1.1, `next/font/google` |

Inter — not the display face — carries the text/UI role because its hinting and true `tnum` tabular-figure
feature hold at 12–14px in dense tables where the actual work happens. IBM Plex Sans Arabic serves the whole
Arabic hierarchy (weight and size do the work a style change does in Latin) and is metrically sympathetic to
Inter, keeping mixed EN/AR strings from lurching between scripts.

## Scale as Tailwind + CSS variables

```ts
// tailwind.config.ts (fontSize excerpt) — [size, { lineHeight, letterSpacing, fontWeight }]
fontSize: {
  "display-2xl": ["clamp(2.75rem, 2rem + 3vw, 4rem)", { lineHeight: "1.05", letterSpacing: "-0.02em",  fontWeight: "600" }],
  "display-xl":  ["2.5rem",  { lineHeight: "1.10", letterSpacing: "-0.015em", fontWeight: "600" }],
  "display-lg":  ["2rem",    { lineHeight: "1.15", letterSpacing: "-0.01em",  fontWeight: "600" }],
  "display-md":  ["1.5rem",  { lineHeight: "1.20", letterSpacing: "-0.005em", fontWeight: "600" }],
  "display-sm":  ["1.25rem", { lineHeight: "1.25", letterSpacing: "0",        fontWeight: "600" }],
  "numeral-hero":["clamp(2rem, 1.5rem + 2vw, 3rem)", { lineHeight: "1.10", letterSpacing: "-0.01em", fontWeight: "600" }],
  "text-lg":     ["1.125rem",{ lineHeight: "1.5",  letterSpacing: "0", fontWeight: "400" }],
  "text-md":     ["1rem",    { lineHeight: "1.5",  letterSpacing: "0", fontWeight: "400" }],
  "text-sm":     ["0.875rem",{ lineHeight: "1.43", letterSpacing: "0", fontWeight: "400" }],
  "text-xs":     ["0.75rem", { lineHeight: "1.4",  letterSpacing: "0.01em", fontWeight: "500" }],
  "numeral-table":["0.875rem",{ lineHeight: "1.43", letterSpacing: "0", fontWeight: "400" }],
  "code-sm":     ["0.8125rem",{ lineHeight: "1.4", letterSpacing: "0", fontWeight: "400" }],
},
```

## Font family tokens

```ts
// tailwind.config.ts (fontFamily excerpt)
fontFamily: {
  display: ["var(--font-display)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
  text:    ["var(--font-text)",    "var(--font-arabic)", "ui-sans-serif", "system-ui"],
  arabic:  ["var(--font-arabic)",  "ui-sans-serif", "system-ui"],
  mono:    ["var(--font-mono)",    "ui-monospace", "SFMono-Regular", "monospace"],
},
```

Because `var(--font-arabic)` is the second fallback in both the `display` and `text` stacks, an Arabic
character inside an otherwise-English string (an Arabic trade name in a Latin-locale invoice list) renders in
IBM Plex Sans Arabic rather than the browser's system Arabic font, keeping mixed strings coherent with no
per-string logic. Fonts are self-hosted through `next/font` (`display: "swap"`) so there is no layout shift
and no runtime third-party request.

## Arabic overrides — taller lines, zero tracking

Arabic uses the **same named steps and the same rem sizes** as Latin — `display-xl` is 2.5rem in both — so a
bilingual layout never renegotiates its grid per language. Two things change, deliberately: line height is
taller (+0.15 to +0.20) to give connecting strokes and diacritics room, and letter spacing is always `0`
(negative tracking breaks letter-to-letter connections and is simply incorrect Arabic typesetting).

| Token | Latin line height | Arabic line height |
|---|---|---|
| `display-xl` | 1.10 | 1.30 |
| `display-lg` | 1.15 | 1.35 |
| `display-md` | 1.20 | 1.40 |
| `display-sm` | 1.25 | 1.45 |
| `text-lg` | 1.5 | 1.7 |
| `text-md` | 1.5 | 1.7 |
| `text-sm` | 1.43 | 1.6 |
| `text-xs` | 1.4 | 1.55 |

```css
/* app/globals.css — Arabic overrides applied by the [lang="ar"] scope */
[lang="ar"] { font-family: var(--font-arabic), ui-sans-serif, system-ui; letter-spacing: normal; }
[lang="ar"] .text-display-xl { line-height: 1.30; }
[lang="ar"] .text-display-lg { line-height: 1.35; }
[lang="ar"] .text-display-md { line-height: 1.40; }
[lang="ar"] .text-text-md,
[lang="ar"] .text-text-lg   { line-height: 1.70; }
[lang="ar"] .text-text-sm   { line-height: 1.60; }
```

Financial figures stay **Western (Latin) digits** even in fully Arabic copy, per the `<Amount>` primitive's
`dir="ltr"`; QAYD never switches to Eastern Arabic-Indic digits (٠١٢٣…) for money, matching how Gulf ledgers
are written.

## Responsive / fluid tokens

Only the two hero-scale tokens flex with the viewport; everything from `display-xl` down is a fixed rem size,
because a table cell that reflowed its font size across breakpoints would break the tabular grid it depends
on.

| Token | Mobile (min) | Desktop (max) | Mechanism |
|---|---|---|---|
| `display-2xl` | 2.75rem | 4rem | `clamp(2.75rem, 2rem + 3vw, 4rem)` |
| `numeral-hero` | 2rem | 3rem | `clamp(2rem, 1.5rem + 2vw, 3rem)` |
| `display-xl` and smaller | fixed | fixed | Single rem value at all breakpoints |

Body copy never shrinks below `text-md` (16px) for primary reading on any breakpoint — 16px avoids iOS
Safari input-zoom and keeps AA reading comfort. Dense scanned table cells may reach `text-xs` (12px) in
Compact density (see [./SPACING_SCALE.md](./SPACING_SCALE.md)). The per-user text-size preference scales the
root font size, so every rem-based token scales proportionally with no per-component change.

# Usage

**Headings use the display face; body uses the text face.**

```tsx
<h1 className="font-display text-display-xl text-ink-12">General Ledger</h1>
<h2 className="font-display text-display-lg text-ink-12">Journal entries</h2>
<h3 className="font-display text-display-md text-ink-12">Cash Position</h3>

<label className="font-text text-sm font-medium text-ink-11">Account</label>
<input className="font-text text-md text-ink-12 placeholder:text-ink-8" />
<p className="font-text text-md text-ink-11">Every posting must balance to the fils.</p>
<p className="font-text text-sm text-ink-9">Updated 2 minutes ago</p>
```

**Every figure goes through `<Amount>`** — no raw `toLocaleString` span, no hand-set `tabular-nums`. This
guarantees fixed-width digits, the KWD three-decimal (fils) default, currency-as-code, and `dir="ltr"` bidi
safety everywhere at once.

```tsx
// components/ui/amount.tsx (contract excerpt)
interface AmountProps {
  value: number;
  currency?: string;         // ISO 4217, e.g. "KWD"
  minorUnitDigits?: number;  // KWD/BHD/OMR = 3, most others = 2
  signed?: "polarity" | "parentheses" | "none";
  className?: string;
}
// The span sets dir="ltr" (numerals never mirror) and font-text tabular-nums;
// signed="polarity" is the ONLY entry point to red/green coloring, used only on net metrics.
```

`signed="polarity"` (red/green) is opt-in and used **only** on genuinely signed, already-net metrics — Net
Income, a budget variance, a period-over-period delta — never on a raw `debit_amount` / `credit_amount`
column, which renders in plain `ink-12`. The full color rule lives in [./COLORS.md](./COLORS.md).

## Truncation & wrapping

| Content | Rule |
|---|---|
| Financial figures (`<Amount>`) | **Never truncate** — a clipped amount is a wrong amount; the column widens or scrolls |
| Account / entity names | Single-line `truncate` with a `title`/tooltip carrying the full value |
| Headings | Do not truncate a page H1 or section H2; they wrap |
| Body paragraphs | Wrap normally; never truncate prose; use `text-pretty`/`text-balance` on leads |
| Long codes / IDs (`code-sm`) | Truncate from the end, or full with wrap-anywhere in a detail view |

Truncation uses logical properties (`text-start`/`text-end`) so it mirrors correctly in RTL — never force a
physical `text-align: left/right` on a truncated cell.

# Do / Don't

| Do | Don't |
|---|---|
| Set every money value with `<Amount>` and tabular figures | Hand-roll a `toLocaleString` span and forget `tabular-nums` |
| Use the display face for headings and one hero number | Set a paragraph in the display face, or use it below 20px |
| Default KWD/BHD/OMR to three decimals | Round `KD 1,204.500` to two decimals |
| Render raw debit/credit columns in neutral `ink-12` | Color debits red and credits green |
| Give Arabic taller line height and zero tracking | Keep Latin line-heights and negative tracking under `dir="rtl"` |
| Keep financial figures in Latin digits in Arabic | Switch to Eastern Arabic-Indic numerals for money |
| Pick a named scale token | Drop in a one-off `text-[17px]` |
| Truncate names with a tooltip; never truncate amounts | Clip a financial figure to fit a column |
| Keep body ≥ 16px for primary reading | Shrink prose below 16px to gain density |
| Use currency codes ("KD 1,204.500") | Invent or misuse a currency symbol glyph |

# Accessibility Notes

**Minimum sizes.** Primary reading body never drops below `text-md` (16px) on any breakpoint — this both
clears AA reading comfort and avoids iOS Safari's zoom-on-focus for inputs. Dense table cells may reach
`text-xs` (12px) only because they are *scanned*, not read as prose, and are backed by the `<Amount>`
primitive's tabular alignment; prose never reaches 12px.

**User text-size preference.** Because every step is rem-based against the 16px root, a per-user text-size
setting scales the root font size and every token scales proportionally — the layout stays intact and the
whole scale remains internally consistent at any user zoom.

**Numerals and bidi.** Every numeral span carries `dir="ltr"` so the Unicode bidi algorithm cannot reorder
grouped digits or a trailing currency code inside an RTL paragraph — a correctness requirement for a
financial product, not only an accessibility nicety. Parentheses (not color) are the primary negative signal
on rendered statements, so a printed Balance Sheet reads correctly in grayscale and for color-vision
deficiency.

**Contrast.** Heading and body colors (`ink-11`/`ink-12` on `ink-1`/`ink-2`/`ink-3`) clear WCAG 2.1 AA in
both themes; the verified pairing tables live in [./COLORS.md](./COLORS.md). Color is never the sole carrier
of a type-level signal — a signed amount pairs its `positive`/`negative` hue with a sign, a glyph, or
parentheses.

# End of Document
