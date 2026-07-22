# Typography — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: TYPOGRAPHY
---

# Purpose

This document is the binding specification for type in QAYD across the Next.js frontend. It is the
typography chapter of the QAYD Design System, expanded from the `Typography` section of
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md) — the single upstream source of truth for
the family choices, the type scale, and the tabular-numeral rules — into the full, implementation-ready
detail a frontend engineer or an AI coding agent needs to set every heading, body paragraph, table cell,
and financial figure without a follow-up question. Where this document and DESIGN_LANGUAGE.md state a size,
line-height, weight, or family, they must agree exactly; if they drift, DESIGN_LANGUAGE.md wins and this
document is corrected.

QAYD is an AI Financial Operating System, and its type system is designed around one non-negotiable fact:
**numbers are the hero.** A column of amounts that does not align vertically is an immediate "this wasn't
built by people who use spreadsheets" signal, so tabular figures, right-alignment, and a dedicated
`<Amount>` primitive are load-bearing, not decorative. Around that core the system pairs a grotesque
**display** face (General Sans) with a clean **text** face (Inter), adds a single Arabic family
(IBM Plex Sans Arabic) that serves both display and text roles in Arabic locale, and reserves a monospace
(JetBrains Mono) for account codes and IDs. Mixing more than this in one product is how "editorial" becomes
"inconsistent," so the family count is fixed at four and the scale is a closed set of named steps.

This document covers: the four type families and their roles; the complete type scale as a table (size,
line-height, tracking, weight, family, usage); the split between display, body, and data/tabular-nums; the
`<Amount>` primitive and the financial-figure formatting rules; the Arabic type treatment (taller line
height, zero tracking, Latin numerals for figures); responsive type; truncation and wrapping; and the usage
rules that keep the system coherent. Every value is stated for both Latin and Arabic where they differ.

Related reading: [COLOR_SYSTEM.md](./COLOR_SYSTEM.md) (the ink colors these type roles are drawn in, and
the debit/credit color rule this document restates), [SPACING_SYSTEM.md](./SPACING_SYSTEM.md) (row heights
and cell padding that pair with the table type sizes), and
[../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) (minimum text sizes and the reading contract).

# Typographic Principles

**1. Numbers are the hero, not the chrome around them.** The interface exists to make a number trustworthy
and fast to read. Tabular figures, right-alignment, generous row height, and hairline dividers do more for
a Trial Balance than any card or icon. When in doubt, remove a decorative element before removing
whitespace around a number.

**2. Two Latin faces, one job each.** General Sans (display) does titles, headings, and the single hero
number per tile; Inter (text) does everything else — body, labels, inputs, buttons, table cells, tooltips.
The split is not stylistic preference; it is functional (see [Why two Latin faces](#why-two-latin-faces)).

**3. Arabic is a first language, not a mirrored afterthought.** Arabic uses the same named scale steps and
the same rem sizes as Latin, so a bilingual layout never renegotiates its grid per language — but its line
height is taller and its tracking is always zero, because those are correct Arabic typesetting, not options.

**4. Latin numerals for money, always.** Financial figures use Western (Latin) digits even inside fully
Arabic sentences, matching how Gulf ledgers, invoices, and spreadsheets are actually written. QAYD never
switches to Eastern Arabic-Indic digits for figures.

**5. A closed scale.** The type scale is a fixed set of named tokens. A new size is a withdrawal against the
consistency of every existing screen; genuine gaps are proposed as additions here, never introduced as a
one-off `text-[17px]` in a component.

# Type Families

QAYD uses two Latin families, one Arabic family, and one monospace — each doing exactly one job.

| Role | Family | Script | Weights | Source / license |
|---|---|---|---|---|
| Display | **General Sans** | Latin | 500, 600, 700 | Fontshare (Fontshare Free License), self-hosted via `next/font/local` |
| Text / UI | **Inter** | Latin | 400, 500, 600, 700 | SIL OFL 1.1, self-hosted via `next/font/google` |
| Arabic (display + text) | **IBM Plex Sans Arabic** | Arabic | 400, 500, 600, 700 | SIL OFL 1.1, self-hosted via `next/font/google` |
| Numeric / code | **JetBrains Mono** | Latin | 400, 500 | SIL OFL 1.1, self-hosted via `next/font/google` |

**Display (General Sans)** is reserved for page titles, section headings, modal titles, card headers, and
the single "hero number" on a dashboard tile (e.g. the cash position figure atop the Banking overview). It
is confident and slightly geometric without being a "startup logotype" face — it reads as engineered, not
playful. It is **never** used below 16px and **never** for a full paragraph of body copy.

**Text (Inter)** carries everything else: body copy, form labels and inputs, buttons, navigation, table
cells, tooltips, toasts.

**Arabic (IBM Plex Sans Arabic)** serves *both* display and text roles in Arabic locale — QAYD does not
attempt a separate "Arabic display face." Arabic display cuts with strong stroke-width contrast lose
legibility fast at UI sizes and rarely carry the weight range professional software needs; IBM Plex Sans
Arabic instead gives one dependable grotesque across the whole hierarchy, with **weight and size** doing the
work that a style change does in Latin. It is metrically sympathetic to IBM Plex Sans, which keeps mixed
EN/AR strings (a currency code, a Latin-script partner name in an Arabic sentence) from visually lurching
between scripts.

**Monospace (JetBrains Mono)** is reserved for account codes, entity IDs, request IDs, and raw JSON in dev
views — anywhere a fixed-width glyph aids scanning or copy-accuracy. It is never used for prose or figures
(figures use the text face's tabular feature, below).

## Why two Latin faces

Inter was chosen over General Sans for the text/UI role for one concrete reason: Inter's hinting and its
true tabular-figure OpenType feature (`tnum`) hold up at 12–14px in dense financial tables in a way most
display grotesques do not. A General Sans-only interface would look striking in a screenshot of a dashboard
hero and blur at the row-height of a 40-line Trial Balance. The display face earns its place at the top of
the hierarchy; the text face earns its place in the dense middle where the actual work happens.

# The Type Scale

All sizes are defined in `rem` against a 16px root and exposed as Tailwind utilities plus a matching CSS
variable for use outside Tailwind (Framer Motion `style` props, canvas-based chart labels).

| Token | Size | Line height | Letter spacing | Weight | Family | Usage |
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
| `text-xs` | 0.75rem / 12px | 1.4 | 0.01em | 500 | Text | Captions, table column headers (uppercase) |
| `numeral-table` | 0.875rem / 14px | 1.43 | 0 | 400–600, tabular | Text, tabular | All financial table cells, right-aligned |
| `code-sm` | 0.8125rem / 13px | 1.4 | 0 | 400 | JetBrains Mono | Account codes, IDs, request IDs |

## Reading the scale

The scale is three clusters. **Display steps** (`display-2xl` → `display-sm`) descend from a marketing hero
to a stat-tile label, all in General Sans weight 600 with progressively tighter negative tracking as size
grows — the tracking tightens the larger display gets, which is the editorial move that makes a 40px H1 read
as composed rather than loose. **Numeral steps** (`numeral-hero`, `numeral-table`) are the two figure
contexts: one hero number per tile in the display face, and every table cell figure in the text face with
tabular figures on. **Text steps** (`text-lg` → `text-xs`) run body copy down to captions; note `text-xs`
alone carries a positive `0.01em` tracking and weight 500 because it is used uppercase for table column
headers, where a hair of tracking and extra weight restores legibility lost at 12px caps.

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

# Headings, Body & Data

## Headings (display face)

Headings use General Sans weight 600 in `ink-11`/`ink-12` (see [COLOR_SYSTEM.md](./COLOR_SYSTEM.md)). The
mapping from semantic heading level to scale token is fixed:

| Element | Token | Color |
|---|---|---|
| Page H1 | `display-xl` | `ink-12` |
| Section H2 / modal title | `display-lg` | `ink-12` |
| Card header / panel title | `display-md` | `ink-12` |
| Sub-panel header / stat-tile label | `display-sm` | `ink-11` |

```tsx
<h1 className="font-display text-display-xl text-ink-12">General Ledger</h1>
<h2 className="font-display text-display-lg text-ink-12">Journal entries</h2>
<h3 className="font-display text-display-md text-ink-12">Cash Position</h3>
```

The display face is never used below `display-sm` (20px) and never sets a paragraph. A "heading" smaller
than 20px is not a heading — it is a `text-xs` uppercase label in the text face.

## Body (text face)

Body copy uses Inter at `text-md`/1.5 for default reading, `text-lg`/1.5 for a lead paragraph or empty-state
body, and `text-sm`/1.43 for secondary UI and dense contexts. Form labels are `text-sm` weight 500; inputs
are `text-md` weight 400; buttons are `text-sm` weight 500. Body color is `ink-11` for primary and `ink-9`
for secondary.

```tsx
<label className="font-text text-sm font-medium text-ink-11">Account</label>
<input className="font-text text-md text-ink-12 placeholder:text-ink-8" />
<p className="font-text text-md text-ink-11">Every posting must balance to the fils.</p>
<p className="font-text text-sm text-ink-9">Updated 2 minutes ago</p>
```

## Data and tabular numerals

Every number in QAYD that represents money, a quantity, a percentage, or an account code is set in
**tabular (fixed-width) figures** — full stop. This is enforced with the `<Amount>` primitive, not left to
call-site discipline. Table figures use `numeral-table` (14px, text face, `tabular-nums`, right-aligned); a
single hero number per tile uses `numeral-hero` (the display face, tabular, at a clamped hero size); account
codes and IDs use `code-sm` (JetBrains Mono). Column headers above numeric columns are `text-xs` uppercase
`ink-9`, right-aligned to match their column.

# The Amount Primitive & Financial Figures

Tabular figures are enforced by a dedicated `<Amount>` component so that no call site can forget them.

```tsx
// components/ui/amount.tsx
import { cn } from "@/lib/utils";

interface AmountProps {
  value: number;
  currency?: string;         // ISO 4217, e.g. "KWD" — omit for plain quantities
  minorUnitDigits?: number;  // KWD/BHD/OMR = 3, most others = 2
  signed?: "polarity" | "parentheses" | "none";
  className?: string;
}

export function Amount({
  value,
  currency,
  minorUnitDigits = currency === "KWD" ? 3 : 2,
  signed = "none",
  className,
}: AmountProps) {
  const abs = Math.abs(value).toLocaleString("en-US", {
    minimumFractionDigits: minorUnitDigits,
    maximumFractionDigits: minorUnitDigits,
  });
  const negative = value < 0;
  const display =
    signed === "parentheses" && negative ? `(${abs})` : negative ? `-${abs}` : abs;

  return (
    <span
      dir="ltr" // numerals never mirror, even inside an RTL row
      className={cn(
        "font-text tabular-nums",
        signed === "polarity" && negative && "text-negative",
        signed === "polarity" && !negative && value > 0 && "text-positive",
        className,
      )}
    >
      {currency && <span className="text-ink-9 mr-1">{currency}</span>}
      {display}
    </span>
  );
}
```

Two rules embedded there matter beyond the code. First, **`dir="ltr"` on every numeral span** — Kuwait and
the wider Gulf write financial amounts with Western digits even in fully Arabic sentences, and without an
explicit direction override the Unicode bidi algorithm can reorder grouped digits or a trailing currency
code inside an RTL paragraph. Second, **`minorUnitDigits` defaults to three for KWD** (and BHD/OMR), not
two — the Kuwaiti dinar subdivides into 1,000 fils, and a P&L that rounds `KD 1,204.500` to `KD 1,204.50` is
not a rounding a Kuwaiti finance team will pass at review.

## The debit/credit rule (restated for type)

`signed="polarity"` (red/green) is opt-in, never the default, because **a debit is not "bad" and a credit
is not "good."** A debit increases an asset or expense and decreases a liability, equity, or revenue; a
credit does the reverse. Coloring every debit red and every credit green — the most common accounting-UI
mistake — misinforms an accountant, since revenue postings are credits and are good news.
`signed="polarity"` is used *only* on genuinely signed, already-net metrics — Net Income, a budget variance,
a period-over-period delta — never on a raw `journal_lines.debit_amount` or `credit_amount` column. Raw
debit/credit columns render in plain `ink-12` tabular numerals, distinguished by column position alone. The
full color rule lives in [COLOR_SYSTEM.md](./COLOR_SYSTEM.md).

## Numeral formatting rules

| Rule | Applies to | Example |
|---|---|---|
| Tabular figures, right-aligned | Every numeric column | — |
| Minus sign for negative | Raw / editable data — ledger tables, form inputs, CSV-style exports | `-1,204.500` |
| Parentheses for negative | Rendered statements — Balance Sheet, P&L, Cash Flow, printed / exported | `(1,204.500)` |
| Three decimal places | KWD, BHD, OMR amounts | `KD 1,204.500` |
| Two decimal places | USD, SAR, AED, all other currencies | `$1,204.50` |
| Em dash, not zero, for "no value" / N/A | A cell where a metric genuinely does not apply | `—` |
| Explicit zero for an actual zero balance | A reconciled account, a fully paid invoice | `0.000` |
| Currency as **code**, not symbol | Every amount, always | `KD 1,204.500`, never a symbol glyph |

The minus-sign / parentheses split is deliberate: raw, editable, or export-adjacent views favor the
unambiguous minus sign because a user may be scanning for negatives or piping the value elsewhere; formally
rendered statements follow the accounting-typesetting convention Kuwaiti and international auditors read
fluently, where parentheses — not color — are the primary negative signal, so a printed Balance Sheet reads
correctly in grayscale.

# Arabic Type Treatment

Arabic uses the **same named steps and the same rem sizes** as Latin — `display-xl` is 2.5rem in both
languages — so a bilingual layout never renegotiates its grid per language. Two things change for Arabic,
deliberately.

**Line height is taller** at every step, +0.15 to +0.20 over the Latin value, to give Arabic's connecting
strokes and diacritics room; a paragraph set at Latin line-height clips ascenders and feels cramped in
Arabic even at an identical font size.

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

**Letter spacing is always `0` / `normal` in Arabic.** The negative tracking applied to Latin display sizes
for a tightened editorial feel is never applied to Arabic — it breaks letter-to-letter connections and is
simply incorrect typesetting, not a style choice. Any `-0.0Xem` tracking token drops to `0` under
`[lang="ar"]`.

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

**Latin numerals inside Arabic.** Figures stay Western digits in Arabic copy, per the `<Amount>` primitive's
`dir="ltr"` and the formatting rules above; QAYD never switches to Eastern Arabic-Indic digits (٠١٢٣…) for
financial figures, matching how Gulf ledgers, invoices, and spreadsheets are actually written. Correct
accounting terminology is set consistently: مدين (debit), دائن (credit), ترحيل (posting), قيد (entry),
ميزان المراجعة (trial balance), دفتر الأستاذ (general ledger), القوائم المالية (financial statements).

**Mixed-script coherence.** Because `var(--font-arabic)` is the second fallback in both the `display` and
`text` font stacks, an Arabic character inside an otherwise-English string (a customer's Arabic trade name
in a Latin-locale invoice list) renders in IBM Plex Sans Arabic rather than the browser's system Arabic
font, keeping mixed strings visually coherent with no per-string logic.

# Font Loading

Fonts are self-hosted and loaded through `next/font` so there is no layout shift and no third-party request
at runtime.

```ts
// lib/fonts.ts
import localFont from "next/font/local";
import { Inter, IBM_Plex_Sans_Arabic, JetBrains_Mono } from "next/font/google";

export const fontDisplay = localFont({
  src: [
    { path: "../assets/fonts/GeneralSans-Medium.woff2",   weight: "500", style: "normal" },
    { path: "../assets/fonts/GeneralSans-Semibold.woff2", weight: "600", style: "normal" },
    { path: "../assets/fonts/GeneralSans-Bold.woff2",     weight: "700", style: "normal" },
  ],
  variable: "--font-display",
  display: "swap",
});

export const fontText   = Inter({ subsets: ["latin"], weight: ["400","500","600","700"], variable: "--font-text", display: "swap" });
export const fontArabic = IBM_Plex_Sans_Arabic({ subsets: ["arabic"], weight: ["400","500","600","700"], variable: "--font-arabic", display: "swap" });
export const fontMono   = JetBrains_Mono({ subsets: ["latin"], weight: ["400","500"], variable: "--font-mono", display: "swap" });
```

```tsx
// app/[locale]/layout.tsx (excerpt)
<html
  lang={locale}
  dir={locale === "ar" ? "rtl" : "ltr"}
  className={cn(fontDisplay.variable, fontText.variable, fontArabic.variable, fontMono.variable)}
>
```

```ts
// tailwind.config.ts (fontFamily excerpt)
fontFamily: {
  display: ["var(--font-display)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
  text:    ["var(--font-text)",    "var(--font-arabic)", "ui-sans-serif", "system-ui"],
  arabic:  ["var(--font-arabic)",  "ui-sans-serif", "system-ui"],
  mono:    ["var(--font-mono)",    "ui-monospace", "SFMono-Regular", "monospace"],
},
```

# Responsive Type

Only the two hero-scale tokens flex with the viewport; everything from `display-xl` down is a fixed rem
size, because a Trial Balance cell that reflowed its font size across breakpoints would break the tabular
grid it depends on. The fluid tokens use `clamp()` so they scale smoothly between a mobile minimum and a
desktop maximum without a media-query step.

| Token | Mobile (min) | Desktop (max) | Mechanism |
|---|---|---|---|
| `display-2xl` | 2.75rem | 4rem | `clamp(2.75rem, 2rem + 3vw, 4rem)` |
| `numeral-hero` | 2rem | 3rem | `clamp(2rem, 1.5rem + 2vw, 3rem)` |
| `display-xl` and smaller | fixed | fixed | Single rem value at all breakpoints |

Body copy never shrinks below `text-md` (16px) for primary reading on any breakpoint — 16px is the mobile
minimum that avoids iOS Safari's input-zoom and keeps AA reading comfort. Dense table cells may drop to
`text-xs` (12px) in Compact density (see [SPACING_SYSTEM.md](./SPACING_SYSTEM.md)) because they are scanned,
not read as prose, and are backed by the `<Amount>` primitive's tabular alignment. The per-user text-size
preference scales the root font size, so every rem-based token scales proportionally with it without any
per-component change.

# Truncation & Wrapping

| Content | Rule |
|---|---|
| Financial figures (`<Amount>`) | **Never truncate.** A clipped amount is a wrong amount. The column widens or the layout scrolls horizontally (see the table-density rules); the number is always shown in full. |
| Account / entity names in a cell | Single-line truncate with an ellipsis and a `title`/tooltip carrying the full value; `truncate` (`overflow: hidden; text-overflow: ellipsis; white-space: nowrap`). |
| Headings | Do not truncate a page H1 or section H2; they wrap. A card header may truncate to one line with a tooltip if the card has a fixed width. |
| Body paragraphs | Wrap normally; never truncate prose. Use `text-pretty` / `text-balance` where a lead paragraph benefits. |
| Long codes / IDs (`code-sm`) | Truncate from the *end* with a leading-visible ellipsis pattern where the prefix is the meaningful part, else full with wrap-anywhere in a detail view. |
| Multi-line clamp | Use `line-clamp-2` / `line-clamp-3` for descriptions in a card, with the full text in the detail view — never for a financial figure. |

Truncation uses logical properties so it mirrors correctly in RTL: an ellipsis on an Arabic name truncates
at the logical end (visually the left edge in RTL), which the browser handles when `dir` is set and no
physical `text-align: left/right` is forced. Never force a physical alignment on a truncated cell; use
`text-start` / `text-end`.

# Usage Rules

**Four families, no more.** Display, text, Arabic, mono — each in its role. Adding a fifth face, or using
the display face for body, or the mono face for figures, is a defect.

**The scale is closed.** Use a named token. A literal `text-[17px]` or an off-scale line-height in a
component is a review-blocking error; a genuine gap is proposed as an addition to this document.

**Every figure goes through `<Amount>`.** No raw `toLocaleString` call site for a money value, no
hand-set `tabular-nums` span. This is what guarantees fixed-width digits, the KWD three-decimal default,
and the `dir="ltr"` bidi safety everywhere at once.

**Never color a raw debit or credit.** `signed="polarity"` is only for already-net metrics. Raw ledger
columns are neutral `ink-12`.

**Arabic gets taller lines and zero tracking.** Never ship Arabic at Latin line-heights, and never apply
negative tracking to Arabic.

**Latin digits for money in every locale.** No Eastern Arabic-Indic numerals for financial figures.

**Display face never below 20px, never as a paragraph.** Below `display-sm`, use the text face.

**Body never below 16px for primary reading.** Dense scanned table cells may reach `text-xs`; prose may not.

# Do & Don't

| Do | Don't |
|---|---|
| Set every money value with `<Amount>` and tabular figures | Hand-roll a `toLocaleString` span and forget `tabular-nums` |
| Use the display face for headings and one hero number | Set a paragraph in the display face, or use it below 20px |
| Default KWD/BHD/OMR to three decimals | Round `KD 1,204.500` to two decimals |
| Render raw debit/credit columns in neutral `ink-12` | Color debits red and credits green |
| Give Arabic taller line height and zero tracking | Flip `dir="rtl"` and keep Latin line-heights and tracking |
| Keep financial figures in Latin digits in Arabic | Switch to Eastern Arabic-Indic numerals for money |
| Pick a named scale token | Drop in a one-off `text-[17px]` |
| Truncate names with a tooltip; never truncate amounts | Clip a financial figure to fit a column |
| Keep body ≥ 16px for primary reading | Shrink prose below 16px to gain density |
| Use currency codes ("KD 1,204.500") | Invent or misuse a currency symbol glyph |

# End of Document
