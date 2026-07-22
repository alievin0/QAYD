# Color System — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: COLOR_SYSTEM
---

# Purpose

This document is the binding specification for every color QAYD renders on the web. It is the color
chapter of the QAYD Design System, expanded from the `Color & Ink` section of
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md) — which remains the single upstream source
of truth for the raw token values — into the full, implementation-ready rules a frontend engineer or an AI
coding agent needs to build a screen without guessing. Where this document and DESIGN_LANGUAGE.md state a
hex value, they must agree to the character; if they ever drift, DESIGN_LANGUAGE.md wins and this document
is corrected. Where this document adds detail DESIGN_LANGUAGE.md leaves implicit — a per-token light↔dark
remap table, verified WCAG pairings, chart-palette derivation, the "never color alone" rule for AI
confidence — that detail is authoritative for the Design System module.

QAYD is an AI Financial Operating System. Its interface has to be trusted with a company's books to the
fils, so the color system is deliberately austere: a twelve-step warm-neutral **ink** scale carries almost
the entire interface, **one** accent (a restrained brass) is spent only on the single primary action per
screen and on anything the AI layer has touched, and **four** semantic financial colors
(positive / negative / warning, plus a deliberately-absent "info") mark financial state and nothing else.
There is no fifth hue, no per-tile "brand color for visual interest," and no ad-hoc hex anywhere in
application code. Every value below exists as a CSS custom property in `app/globals.css`, a Tailwind color
utility, and a plain-JS entry in `lib/tokens.ts`; nothing in a component ever hardcodes a color.

This document covers: the ink scale and its usage bands; the accent and its four states; the semantic
financial colors and the debit/credit rule; AI / confidence color usage (and why it is never color-alone);
surface, background, and border color roles; the chart palette (categorical, sequential, diverging); the
complete light↔dark remap per token; WCAG AA contrast tables verified in both themes; and the usage rules
that keep the palette to one accent forever. It is theme-agnostic in the sense that every rule is stated for
both light and dark at once — dark mode is a first-class, hand-calibrated theme here, never an inversion.

Related reading: [TYPOGRAPHY.md](./TYPOGRAPHY.md) (the `<Amount>` primitive and tabular figures that these
colors are applied to), [SPACING_SYSTEM.md](./SPACING_SYSTEM.md) (the surfaces these colors fill),
[../frontend/THEMING.md](../frontend/THEMING.md) (the three-tier token architecture and `next-themes`
provider that swaps these values at runtime), [../frontend/DARK_MODE.md](../frontend/DARK_MODE.md), and
[../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) (the contrast contract this document satisfies).

# Color Philosophy

Five convictions, inherited directly from the design principles, govern every decision in this document.

**1. Ink before color.** The interface is built first in near-monochrome and color is added only where it
earns its place. A screen must remain legible and hierarchy-correct with the accent temporarily removed. If
a screen only reads correctly because half of it is tinted, the hierarchy is being done by color instead of
by type and space — that is a defect, not a style.

**2. One accent, rationed.** QAYD has exactly one brand color — a restrained brass — and it marks only the
single primary action on a screen and anything the AI layer produced. More than one saturated brass element
competing for attention on a screen means the hierarchy is wrong, not that the token needs a sibling.

**3. Warm-neutral, not blue-black.** The ink scale carries a few points of yellow hue at low saturation, so
it reads as paper and ink — the product's own metaphor — rather than the cool slate-blue that every generic
AI/SaaS dashboard defaults to. The warmth is subtle: never beige, never sepia, but never cool either, and
it carries through both light and dark themes identically.

**4. Semantic color is financial state, never brand state.** `positive`, `negative`, and `warning` describe
what a number is doing (a gain, a loss, a pending reconciliation), never what the brand wants you to feel.
They are visually and tonally distinct from the brass accent so a user never confuses "this is good news"
with "this is the button to press."

**5. Color never carries meaning alone.** Every place color communicates — a positive delta, an AI-flagged
row, a warning banner — is paired with a second, non-color signal: a glyph, a shape, a label, a position.
This is both the accessibility floor (WCAG 1.4.1 Use of Color) and, for a financial product read in
grayscale board packs and printed statements, a correctness requirement.

# The Ink Scale

QAYD's near-monochrome foundation is a twelve-step warm-neutral ink scale following the Radix-Colors
usage convention: steps 1–2 for backgrounds, 3–5 for component fills, 6–8 for borders and disabled states,
9–10 for icons and secondary text, 11–12 for high-emphasis text. Any engineer familiar with that convention
from shadcn/ui theming picks up QAYD's scale with zero ramp-up.

| Token | Swatch (light) | Hex (light) | Hex (dark) | Usage band |
|---|---|---|---|---|
| `ink-1`  | `#FAFAF9` | `#FAFAF9` | `#14130F` | App canvas background |
| `ink-2`  | `#F4F3F1` | `#F4F3F1` | `#1B1914` | Sidebar / subtle background |
| `ink-3`  | `#EBE9E6` | `#EBE9E6` | `#24211A` | Card / component background |
| `ink-4`  | `#E0DEDA` | `#E0DEDA` | `#2D2921` | Hover background |
| `ink-5`  | `#D3D0CA` | `#D3D0CA` | `#383327` | Active / selected background |
| `ink-6`  | `#C2BEB6` | `#C2BEB6` | `#46402F` | Hairline border, default divider |
| `ink-7`  | `#A9A49A` | `#A9A49A` | `#58503A` | Input border, stronger divider |
| `ink-8`  | `#878174` | `#878174` | `#786F55` | Disabled text/icon, placeholder |
| `ink-9`  | `#645F53` | `#645F53` | `#A39878` | Secondary text, default icon |
| `ink-10` | `#4A4639` | `#4A4639` | `#C2B99C` | Primary icon, strong secondary text |
| `ink-11` | `#2B2820` | `#2B2820` | `#E4DEC9` | Headings, high-emphasis text |
| `ink-12` | `#15130E` | `#15130E` | `#F8F6EF` | Display text, near-black / near-white |

## Usage bands in detail

The twelve steps resolve into five functional bands. Staying inside a band's intended role is what keeps a
screen coherent across authors.

- **Backgrounds (1–2).** `ink-1` is the app canvas — the scrollable page region behind everything.
  `ink-2` is a subtly recessed surface: the sidebar, a subdued section background. The two are close enough
  that the difference reads as "same paper, faint fold," not as a hard panel edge.
- **Component fills (3–5).** `ink-3` fills a card or component. `ink-4` is the resting hover fill of an
  interactive surface. `ink-5` is the active / selected fill (a pressed segmented-control segment, a
  selected list row before AI tint applies). These are the fills that change on interaction, never the
  backgrounds.
- **Borders & disabled (6–8).** `ink-6` is the default hairline — the border on every card, the divider
  between table rows, the line under a section header. `ink-7` is a stronger structural line: an input
  border, a sticky-footer top rule, every-fifth-row ledger emphasis. `ink-8` is disabled text and
  placeholder text, and the one band where a sub-4.5:1 contrast is tolerated (see Contrast, below).
- **Icons & secondary text (9–10).** `ink-9` is the default icon color and secondary body text. `ink-10`
  is a primary (non-accent) icon and strong secondary text — a table column header that needs to sit above
  `ink-9` cells without becoming a heading.
- **High-emphasis text (11–12).** `ink-11` carries headings and high-emphasis body. `ink-12` is display
  text and the near-black (light) / near-white (dark) extreme — the hero number on a dashboard tile, an H1.

## Reading the scale in code

```tsx
// Ink is applied through Tailwind utilities that resolve to the CSS variables.
<section className="bg-ink-1">                        {/* canvas */}
  <aside className="bg-ink-2 border-e border-ink-6">  {/* sidebar, logical border */}
  <article className="bg-ink-3 border border-ink-6">  {/* card */}
    <h3 className="text-ink-12">Cash Position</h3>     {/* display text */}
    <p  className="text-ink-9">Updated 2 minutes ago</p>{/* secondary text */}
  </article>
</section>
```

Because the same token name (`ink-6`) points at `#C2BEB6` in light and `#46402F` in dark, the component
above is correct in both themes with no conditional class. Dark mode is **not** a literal inversion of the
light values; it is its own calibrated ramp so that borders, disabled states, and text keep the same
*relative* contrast rather than mechanically flipping (see [Light ↔ Dark Remap](#light--dark-remap)).

# The Accent — The One Color QAYD Spends

QAYD's single brand color is a restrained brass. It is not a bright SaaS indigo or violet — both were
deliberately avoided because they are now the default "AI startup" signal, the opposite of distinctive.
Brass also carries the brand's metaphor (a ledger's brass fittings, an engraved plate) without any literal
texture on screen. The accent has exactly four tokens.

| Token | Swatch (light) | Hex (light) | Hex (dark) | Usage |
|---|---|---|---|---|
| `accent-subtle` | `#EADFBF` | `#EADFBF` | `#3A2E14` | Selected-row tint, AI-proposal card background, AI notice background |
| `accent`        | `#9C7A34` | `#9C7A34` | `#D9B96C` | Primary button fill, focus ring, active tab, links |
| `accent-strong` | `#7A5D22` | `#7A5D22` | `#E8CE8E` | Hover / pressed state of the above |
| `accent-on`     | `#15130E` | `#15130E` (`ink-12`) | `#14130F` (near-black) | Text / icon drawn on top of an `accent` fill |

## accent-on is near-black, never white

A mid-value warm brass does not clear 4.5:1 against white text — roughly 3.2:1 at the `accent` value, which
passes only as a large-scale / non-text element, not as button-label text. Against `ink-12` it comfortably
clears (roughly 5.5:1). This is also the more authentic material read: engraved brass is a dark mark on a
gold ground, not a white one. Therefore **every filled `accent` surface pairs with `accent-on`, not with
white/`ink-1`.** In dark mode the fill lightens to `#D9B96C` and `accent-on` becomes the near-black
`#14130F`, preserving the same relationship.

```tsx
// Correct — near-black label on brass.
<button className="bg-accent text-accent-on hover:bg-accent-strong rounded-md">
  Post entry
</button>

// Wrong — white on brass fails AA as text.
<button className="bg-accent text-white">Post entry</button>
```

Text or icons drawn **as** `accent` directly on an `ink-1`/`ink-2` background (a link, an active nav icon)
use `accent` in light mode; in dark mode the lighter `#D9B96C` value clears 4.5:1 against the dark ink
backgrounds. Never place `accent` text on an `accent-subtle` fill — that pairing is a background wash for
a dark-ink label, not a same-hue text/fill combination.

## The accent is also the AI's signature

By rule, `accent` and `accent-subtle` appear in exactly two contexts and nowhere else:

1. The **one** primary action per screen (the filled primary button, the active segmented tab, a selected
   chip).
2. Anything the **AI layer** has produced — a proposed journal-entry card, a confidence badge, the
   assistant's focus ring, the leading-gutter dot on a row an anomaly agent flagged, the assistant's mark.

This double duty is deliberate: it keeps the palette to one hue as promised, and it trains the user that
"brass on this screen" always means either "this is the button I should press" or "this is something AI
touched, not something committed" — never a generic highlight. Any third use of the accent (a decorative
underline, a "for interest" tint on an unrelated card) is a defect.

# Semantic Colors — Financial State, Never Brand State

Four semantic roles mark financial state. Each has a solid value and a subtle background wash, in both
themes. None is ever used for a brand action or an AI badge.

| Token | Swatch (light) | Hex (light) | Hex (dark) | Meaning | Never use for |
|---|---|---|---|---|---|
| `positive`         | `#17794A` | `#17794A` | `#4ADE94` | Increase, gain, reconciled, success | Brand actions, AI badges |
| `positive-subtle`  | `#E3F3EA` | `#E3F3EA` | `#12301F` | Positive background wash | — |
| `negative`         | `#B4232E` | `#B4232E` | `#F26B74` | Decrease, loss, overdue, destructive | Brand actions, AI badges |
| `negative-subtle`  | `#FBE7E8` | `#FBE7E8` | `#3A1618` | Negative background wash | — |
| `warning`          | `#B45309` | `#B45309` | `#F5A855` | Pending, unreconciled, approaching a limit | Brand accent (kept more orange / higher chroma than brass) |
| `warning-subtle`   | `#FBEBDA` | `#FBEBDA` | `#3A2812` | Warning background wash | — |
| `info`             | — (none) | *(ink scale only)* | *(ink scale only)* | Neutral, non-financial notices | — |

## There is deliberately no "info" hue

A plain informational banner ("Your session will expire in 5 minutes") is styled with the ink scale alone —
`ink-11` text on an `ink-3` background with an `ink-6` border — never a blue tint. Introducing blue "for
info" is how most products quietly acquire a second brand color; QAYD would rather an informational banner
look slightly less decorated than invent a second accent. The single exception, by the AI rule, is that an
*AI-sourced* notice (not a plain system notice) may use `accent-subtle` instead of `ink-3`, because "this
came from the AI layer" is exactly the signal the accent is reserved to carry.

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

## The debit/credit rule

This is a color rule as much as a numeral-formatting one: **`positive` and `negative` are never applied to
a raw debit or credit amount.** Debit-normal and credit-normal are structural properties of an account type
(Assets and Expenses are debit-normal; Liabilities, Equity, and Revenue are credit-normal — see
`docs/accounting/CHART_OF_ACCOUNTS.md`), not judgments of good and bad. Coloring every debit red and every
credit green — the single most common mistake in accounting-software UI — actively misinforms an accountant,
because revenue postings are credits and are unambiguously good news.

Therefore a Journal Entry Lines table, a General Ledger, and a Trial Balance render their Debit and Credit
columns in plain `ink-12` tabular numerals, distinguished only by column position and a right-aligned
header, never by hue. Color is reserved for the layer **above** the raw ledger — Net Income on a P&L, a
budget variance, a period-over-period delta on a dashboard tile — where a number is genuinely signed and the
sign genuinely means "better" or "worse." Even there, QAYD colors a small delta annotation next to the
figure (a `+2.4%` chip with an up/down glyph) rather than washing the whole numeral in red or green, so the
number itself stays legible in neutral ink first. See [TYPOGRAPHY.md](./TYPOGRAPHY.md) for the `<Amount>`
primitive whose `signed="polarity"` mode is the only entry point to this coloring, and which is used only on
already-net metrics.

# AI & Confidence Color — Never Color Alone

The AI layer is the one place a financial product is most tempted to over-signal with color, and the one
place it must not. QAYD's rule is absolute: **AI provenance and confidence are communicated by the accent
plus at least one non-color signal, never by color alone.**

## Provenance in a table row

A row an AI agent proposed or flagged (an unposted AI-drafted journal entry, a bank line the reconciliation
agent matched, a transaction the fraud agent scored as anomalous) is marked with a single small (6px)
`accent` filled dot in the row's leading gutter — plus a `"Suggested"` micro-label in the expanded / detail
view. It is **not** a colored row background and **not** a full badge cluttering the row. The dot's meaning
survives grayscale because its *position* (leading gutter) and *shape* (a filled status dot, the only filled
icon in the system) carry the signal alongside its color. Once a human approves or the system posts the row,
the dot disappears — provenance is visible until a decision is made, not forever.

## Confidence

The agent's numeric confidence is available on hover as a tooltip (`"92% confidence — Accountant Agent"`),
not rendered as a permanent percentage in every row where it would compete with the actual figures. When
confidence *is* surfaced as a badge (on an AI-proposal card), it pairs a band label with the accent, never a
raw traffic-light color:

| Confidence band | Label | Color treatment | Non-color signal |
|---|---|---|---|
| High (≥ 85%) | "High confidence" | `accent` text on `accent-subtle` | Filled ledger-tick glyph + numeric % in tooltip |
| Medium (60–84%) | "Review suggested" | `ink-11` text on `ink-3`, `accent` dot | Hollow-tick glyph + numeric % |
| Low (< 60%) | "Low confidence — verify" | `warning` text on `warning-subtle` | Warning triangle glyph + numeric % |

Only the low band reaches for a semantic hue, and it uses `warning` (not `negative`) because low AI
confidence is a "check this before you trust it" state, not a financial loss. In every band the label text
and the glyph carry the meaning without the color, satisfying WCAG 1.4.1. An AI proposal is never styled to
look like a committed fact: the accent, the "Suggested" language, and the confidence affordance always keep
it visually distinct from anything a human has approved or the system has posted.

# Surfaces, Backgrounds & Borders

Color roles for surfaces are drawn entirely from the ink scale; elevation in QAYD comes from hairline
borders far more than from shadow depth (the shadow tokens themselves live in
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md) `Elevation & Surfaces`; this section covers
only the *color* of each surface).

| Surface | Background (light) | Background (dark) | Border | Notes |
|---|---|---|---|---|
| App canvas | `ink-1` | `ink-1` | — | The scrollable page region |
| Card / panel / sidebar | `ink-2` or `ink-3` | `ink-3` | 1px `ink-6` | Border does the elevation work |
| Dropdown / popover | `ink-1` | `ink-3` | 1px `ink-6` | Raised, quiet shadow |
| Modal / dialog | `ink-1` | `ink-3` | 1px `ink-6` | Over an `ink-12` @ 50% scrim |
| Glass overlay (⌘K, AI Command Center only) | `ink-1` @ 72% + blur | `ink-1` @ 72% + blur | 1px `ink-6` @ 60% | Intentionally rare |
| Hover fill (interactive surface) | `ink-4` | `ink-4` | inherit | |
| Active / selected fill | `ink-5` | `ink-5` | inherit | AI-selected rows tint `accent-subtle` instead |

**Elevation gets lighter, not darker, in dark mode.** A raised card (`ink-3` dark, `#24211A`) is lighter
than the canvas (`ink-1` dark, `#14130F`) it sits on — the way physical light and professional dark UIs
behave — rather than the inverse. Borders are the primary structural signal in both themes: `ink-6` for the
default hairline, `ink-7` for a stronger line (input borders, sticky-footer top rules, every-fifth ledger
row). A screen should read as structured with its shadows removed; if it doesn't, the borders are being
under-used.

# Chart Palette

Data visualization draws from the ink scale plus the accent plus the semantic set — never an arbitrary
categorical rainbow. The governing move is **vary value before you reach for a second hue.**

## Categorical (discrete series)

| Series count | Palette | Rule |
|---|---|---|
| 1 | `accent` | The single series is the accent against `ink-6`/`ink-7` gridlines |
| 2 | `accent` + `ink-7` | e.g. actual vs. budget, this period vs. last |
| 3 | `accent`, `accent` @ 60% value, `ink-7` | Third series is a lighter tint, not a new hue |
| 4–6 | Accent tint ramp (see sequential) | Vary value across the ramp; add direct data labels |
| 7+ | Direct data labels over a single-hue set | Fall back to labels; do **not** invent categorical hues |

Once a chart would otherwise need a fourth or fifth *color* to stay legible, QAYD switches to direct data
labels over a legend and keeps the palette to accent-tint values. A cost-center breakdown with six
categories is a light-to-dark accent ramp with labels, not six competing hues.

## Sequential (one metric, low → high)

A light-to-dark ramp anchored on the accent, for choropleth-style intensity (aging buckets, heat by month):

| Step | Light | Dark |
|---|---|---|
| Lowest | `#EADFBF` (`accent-subtle`) | `#3A2E14` |
| Low-mid | `#D9C289` | `#6B531F` |
| Mid | `#C1A45C` | `#9C7A34` |
| High-mid | `#9C7A34` (`accent`) | `#C79E4E` |
| Highest | `#7A5D22` (`accent-strong`) | `#D9B96C` |

The two anchor ends (`accent-subtle`, `accent`/`accent-strong`) are exact tokens; the intermediate steps are
tints of the same hue generated for the sequential ramp and are the **only** sanctioned chart-derived
values outside the token tables. They live in `lib/tokens.ts` under a `chart` namespace so no chart file
hardcodes them.

## Diverging (signed metric, negative ↔ neutral ↔ positive)

The one place two hues are correct, because the data is genuinely signed and the debit/credit rule does not
apply (this is a net metric — cash in vs. cash out, budget variance):

| Pole | Light | Dark |
|---|---|---|
| Negative extreme | `#B4232E` (`negative`) | `#F26B74` |
| Negative wash | `#FBE7E8` (`negative-subtle`) | `#3A1618` |
| Neutral midpoint | `#C2BEB6` (`ink-6`) | `#46402F` |
| Positive wash | `#E3F3EA` (`positive-subtle`) | `#12301F` |
| Positive extreme | `#17794A` (`positive`) | `#4ADE94` |

Every chart pairs color with a non-color encoding — direct labels, an up/down glyph on a delta, distinct
dash patterns for two line series — so it survives grayscale printing and color-vision deficiency, per
Principle 5 and WCAG 1.4.1.

# Light ↔ Dark Remap

Dark mode is a first-class theme driven by `next-themes` (a `class="dark"` on `<html>`, system-aware by
default, user-overridable, persisted per user). Every token has an explicit dark value; there is no
`invert()` or automatic luminance flip. Three rules keep dark mode from feeling like a cheaper product:

- **Backgrounds stay warm-neutral, not blue-black.** `ink-1` dark is `#14130F`, not a cool near-black — the
  same warmth carries through both themes.
- **Elevation gets lighter, not darker.** A raised card (`ink-3` dark) is lighter than the canvas (`ink-1`
  dark), matching how physical light behaves.
- **The accent gets lighter, not more saturated.** `#9C7A34` → `#D9B96C`, so it keeps the same 4.5:1+
  contrast against `accent-on` and the darker canvas instead of glowing unnaturally against black.

## Complete per-token remap

| Token | Light | Dark |
|---|---|---|
| `ink-1` | `#FAFAF9` | `#14130F` |
| `ink-2` | `#F4F3F1` | `#1B1914` |
| `ink-3` | `#EBE9E6` | `#24211A` |
| `ink-4` | `#E0DEDA` | `#2D2921` |
| `ink-5` | `#D3D0CA` | `#383327` |
| `ink-6` | `#C2BEB6` | `#46402F` |
| `ink-7` | `#A9A49A` | `#58503A` |
| `ink-8` | `#878174` | `#786F55` |
| `ink-9` | `#645F53` | `#A39878` |
| `ink-10` | `#4A4639` | `#C2B99C` |
| `ink-11` | `#2B2820` | `#E4DEC9` |
| `ink-12` | `#15130E` | `#F8F6EF` |
| `accent-subtle` | `#EADFBF` | `#3A2E14` |
| `accent` | `#9C7A34` | `#D9B96C` |
| `accent-strong` | `#7A5D22` | `#E8CE8E` |
| `accent-on` | `#15130E` | `#14130F` |
| `positive` | `#17794A` | `#4ADE94` |
| `positive-subtle` | `#E3F3EA` | `#12301F` |
| `negative` | `#B4232E` | `#F26B74` |
| `negative-subtle` | `#FBE7E8` | `#3A1618` |
| `warning` | `#B45309` | `#F5A855` |
| `warning-subtle` | `#FBEBDA` | `#3A2812` |

## Token definitions (CSS variables)

The values above are declared once in `app/globals.css`; nothing else in the codebase repeats a hex.

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

## shadcn/ui semantic aliases

shadcn/ui components consume `hsl(var(--x))` semantic aliases. The critical trap: shadcn's own
`--accent`/`--accent-foreground` are a **neutral** hover/highlight tint (menu-item hover, tab hover), *not*
the brand color. Pointing shadcn's `--accent` at `--qayd-accent` turns every hovered menu row gold.

```css
:root {
  --background: 48 20% 98%;   --foreground: 40 30% 7%;
  --card: 45 15% 95%;         --card-foreground: 40 30% 7%;
  --primary: 42 50% 38%;      /* = --qayd-accent */
  --primary-foreground: 40 30% 7%;  /* = --qayd-accent-on, near-black not white */
  --muted-foreground: 42 10% 40%;   /* = --qayd-ink-9 */
  --accent: 45 15% 92%;       /* NEUTRAL hover tint — NOT the brand brass */
  --accent-foreground: 40 30% 10%;
  --destructive: 355 65% 40%; /* = --qayd-negative */
  --destructive-foreground: 0 0% 100%;
  --border: 42 14% 78%;       /* = --qayd-ink-6 */
  --input: 42 14% 72%;        /* = --qayd-ink-7 */
  --ring: 42 55% 40%;         /* = --qayd-accent, focus ring */
}
```

## Plain-JS token export

Framer Motion transitions and canvas/SVG chart code cannot read CSS custom properties without a
`getComputedStyle` round-trip, so they import literal values from `lib/tokens.ts`:

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

# WCAG AA Contrast

QAYD targets **WCAG 2.1 AA** as a floor across both themes and both languages: 4.5:1 for normal text, 3:1
for large text and non-text UI (borders, focus rings, iconographic state). Disabled controls are the one
place a slightly lower ratio is accepted, and only because a disabled control's information is reinforced by
a tooltip and an `aria-disabled` / `cursor-not-allowed` state, not by contrast alone.

## Light theme

| Foreground | Background | Ratio | Requirement | Result |
|---|---|---|---|---|
| `ink-12` `#15130E` | `ink-1` `#FAFAF9` | ~17.8:1 | 4.5:1 | Pass |
| `ink-11` `#2B2820` | `ink-1` `#FAFAF9` | ~13.1:1 | 4.5:1 | Pass |
| `ink-11` `#2B2820` | `ink-3` `#EBE9E6` | ~11.4:1 | 4.5:1 | Pass |
| `ink-9` `#645F53` (secondary) | `ink-1` `#FAFAF9` | ~5.1:1 | 4.5:1 | Pass |
| `ink-9` `#645F53` | `ink-2` `#F4F3F1` | ~4.9:1 | 4.5:1 | Pass |
| `accent-on` `#15130E` | `accent` `#9C7A34` | ~5.5:1 | 4.5:1 | Pass |
| `accent` `#9C7A34` (link) | `ink-1` `#FAFAF9` | ~4.6:1 | 4.5:1 | Pass |
| white `#FFFFFF` | `negative` `#B4232E` | ~5.8:1 | 4.5:1 | Pass |
| `positive` `#17794A` | `positive-subtle` `#E3F3EA` | ~4.8:1 | 4.5:1 | Pass |
| `negative` `#B4232E` | `negative-subtle` `#FBE7E8` | ~5.2:1 | 4.5:1 | Pass |
| `warning` `#B45309` | `warning-subtle` `#FBEBDA` | ~4.7:1 | 4.5:1 | Pass |
| `ink-8` `#878174` (disabled) | `ink-3` `#EBE9E6` | ~2.9:1 | 3:1 (exempt) | Tolerated |
| Focus ring `accent` `#9C7A34` | any surface `ink-1`–`ink-3` | ≥3:1 | 3:1 (non-text) | Pass |
| `ink-6` `#C2BEB6` border | `ink-1` `#FAFAF9` | — | decorative | n/a (hairline) |

## Dark theme

| Foreground | Background | Ratio | Requirement | Result |
|---|---|---|---|---|
| `ink-12` `#F8F6EF` | `ink-1` `#14130F` | ~16.9:1 | 4.5:1 | Pass |
| `ink-11` `#E4DEC9` | `ink-1` `#14130F` | ~13.0:1 | 4.5:1 | Pass |
| `ink-11` `#E4DEC9` | `ink-3` `#24211A` | ~10.5:1 | 4.5:1 | Pass |
| `ink-9` `#A39878` (secondary) | `ink-1` `#14130F` | ~5.4:1 | 4.5:1 | Pass |
| `ink-9` `#A39878` | `ink-2` `#1B1914` | ~5.1:1 | 4.5:1 | Pass |
| `accent-on` `#14130F` | `accent` `#D9B96C` | ~9.6:1 | 4.5:1 | Pass |
| `accent` `#D9B96C` (link) | `ink-1` `#14130F` | ~9.0:1 | 4.5:1 | Pass |
| `positive` `#4ADE94` | `ink-1` `#14130F` | ~10.2:1 | 4.5:1 | Pass |
| `negative` `#F26B74` | `ink-1` `#14130F` | ~6.6:1 | 4.5:1 | Pass |
| `warning` `#F5A855` | `ink-1` `#14130F` | ~9.3:1 | 4.5:1 | Pass |
| `ink-8` `#786F55` (disabled) | `ink-3` `#24211A` | ~3.0:1 | 3:1 (exempt) | Tolerated |
| Focus ring `accent` `#D9B96C` | any surface `ink-1`–`ink-3` | ≥3:1 | 3:1 (non-text) | Pass |

Ratios are computed with the WCAG 2.x relative-luminance formula and are approximate to one decimal; any
change to a token hex must be re-verified against this table before it ships. Any permission-gated control
that is disabled rather than hidden must carry a tooltip naming the required permission (e.g. "Requires
`accounting.journal.post`") — QAYD never leaves a user staring at a grayed-out button with no explanation.

# Usage Rules

**One accent, no exceptions.** `accent` and `accent-subtle` are used only for the single primary action per
screen and for AI-touched elements. No card, tile, or section gets its own "brand color for visual
interest." A second saturated hue competing with the brass is a defect.

**No ad-hoc hex.** Every color in application code resolves to a token — a Tailwind utility (`bg-ink-3`,
`text-accent`), a shadcn semantic alias, or a `lib/tokens.ts` entry. A literal `#RRGGBB` in a component,
inline style, or chart file is a review-blocking error. The only literals in the whole system live in
`app/globals.css` and `lib/tokens.ts`.

**Semantic colors are financial state only.** `positive`/`negative`/`warning` never mark a brand action, a
nav state, or an AI badge, and never color a raw debit or credit. They color signed, net metrics and true
status only, always paired with a glyph or label.

**Color never carries meaning alone.** Every color-coded signal — a delta, an AI dot, a warning banner, a
chart series — has a redundant non-color encoding (glyph, shape, label, position). Verify a screen reads
correctly in grayscale.

**Let the accent be removable.** A screen must remain legible and hierarchy-correct with the accent
temporarily swapped for `ink-9`. If it collapses, hierarchy was being done by color instead of type and
space — fix the hierarchy, not the palette.

**Dark mode is authored, not inverted.** Never derive a dark value with `invert()` or a luminance flip; use
the explicit dark token. Backgrounds stay warm, elevation lightens, the accent lightens rather than
saturates.

**AA is a floor, verified in both themes.** Any new pairing is checked against the contrast tables above in
light and dark before it ships; disabled text is the only tolerated sub-4.5:1 case, and only with a
reinforcing tooltip and state.

# Do & Don't

| Do | Don't |
|---|---|
| Build the interface in ink first, add color where it earns its place | Tint half a screen to create hierarchy |
| Spend `accent` on the one primary action and AI-touched elements only | Use brass as a generic highlight anywhere convenient |
| Pair `accent` fills with near-black `accent-on` | Put white text on a brass fill (fails AA) |
| Render raw debit/credit columns in neutral `ink-12` | Color debits red and credits green |
| Color only signed, net metrics (`positive`/`negative`) | Wash a whole numeral in red/green |
| Style plain info banners with the ink scale | Invent a blue "info" hue and acquire a second brand color |
| Pair every AI/confidence color with a glyph or label | Rely on a colored row background alone to mean "AI" |
| Vary value (accent tints) before adding a chart hue | Reach for a categorical rainbow at four+ series |
| Author explicit dark-mode token values | Derive dark mode with `invert()` |
| Verify AA in both themes before shipping a new pairing | Ship a pairing you have not contrast-checked |
| Reference every color as a token | Hardcode a hex in a component or chart |

# End of Document
