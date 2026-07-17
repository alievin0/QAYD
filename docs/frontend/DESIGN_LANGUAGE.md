# Design Language — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: DESIGN_LANGUAGE
---

# Purpose

This document is the single source of truth for how QAYD looks, reads, moves, and speaks across the
Next.js 15 frontend. It governs every screen built under `Module: Frontend` — the Dashboard, Accounting,
General Ledger, Journal Entries, Trial Balance, Balance Sheet, P&L, Cash Flow, Banking, Bank
Reconciliation, and AI Command Center specs — and every component in the shared library they draw from.
Where a screen spec or component spec is silent on a visual decision, this document decides it. Where a
screen spec appears to contradict this document, this document wins, and the conflict should be raised
rather than silently resolved by whichever engineer touches the code last.

QAYD is an AI Financial Operating System used by owners, CFOs, controllers, accountants, and auditors to
run the real financial life of a company: journal entries that must be right to the fils, statements that
regulators and banks will read, and an AI layer that drafts and explains but never overrides a human's
authority to approve. The interface is the only place all of that trust becomes visible. A dashboard that
looks like a consumer mobile game undermines the product's credibility in the first three seconds, before
a single number has been read. A dashboard that looks like an undifferentiated admin panel — generic
Inter-on-white, default shadcn blue, rounded pill buttons — fails to differentiate QAYD from the ten other
"AI accounting" pitches a CFO has already ignored this year. The design language defined here exists to
close both gaps at once: restrained enough to be trusted with a company's books, distinctive enough to be
remembered.

This is a **design specification**, not a mood board. Every rule below resolves to a token, a Tailwind
class, a `next/font` declaration, or a Framer Motion variant that a frontend engineer or an AI coding
agent can implement without asking a follow-up question. Section 13, Design Tokens, is the literal
`:root` block, `tailwind.config.ts` excerpt, and `tokens.ts` export that the rest of this document
describes in prose. Nothing here overrides the fixed technical stack — Next.js 15, React 19, TypeScript
strict, Tailwind CSS, shadcn/ui on Radix primitives, Framer Motion, Lucide — it styles that stack.

This document supersedes the color, radius, and typography guidance in the earlier
`docs/foundation/DESIGN_SYSTEM.md` draft wherever the two disagree. That draft predates the founder's
explicit taste direction (car/fashion-brand editorial restraint, near-monochrome ink, a single disciplined
accent, tight corner radii) and still reflects a generic rounded-SaaS starting point — 24px card radii,
an unnamed "emerald green" primary, no distinction between a display face and a text face. Anywhere the
two documents conflict, this one governs for frontend implementation; `DESIGN_SYSTEM.md` should eventually
be reconciled to match it.

# Design Principles

**1. Ink before color.** The interface is built first in near-monochrome — twelve steps of one warm-neutral
ink scale — and color is added only where it earns its place: one accent, four semantic financial states,
nothing else. A screen should be legible and hierarchy-correct with the accent temporarily removed. If a
screen only reads correctly because half of it is tinted, the hierarchy is being done by color instead of
by type and space, and that is a defect, not a style choice.

**2. One accent, spent deliberately.** QAYD has exactly one brand color — a restrained brass — and it is
rationed. It marks the single primary action on a screen, the focus ring, the active tab, and anything the
AI layer has touched (a proposal, a confidence badge, the assistant's presence). If a screen has more than
one saturated brass element competing for attention, the hierarchy is wrong, not the token.

**3. Numbers are the hero, not the chrome around them.** QAYD is a financial product; the interface exists
to make a number trustworthy and fast to read, not to decorate it. Tabular figures, right-alignment,
generous row height, and hairline dividers do more for a Trial Balance than any card, gradient, or icon
ever will. When in doubt, remove a decorative element before removing whitespace around a number.

**4. Density with air.** Finance work is inherently dense — a General Ledger can be thousands of lines —
but density is not the same as clutter. QAYD achieves density through smaller, disciplined type and
tighter row heights, never through removing the whitespace that separates one idea from the next. A dense
table on a spacious page reads as expert; a dense table on a cramped page reads as broken.

**5. Motion explains, never entertains.** Every animation in QAYD answers a question — where did this panel
come from, what changed, is the system still working — in under 300ms. QAYD never bounces, confetti-bursts,
or spring-wobbles a financial state change. Posting a journal entry is not a game mechanic; the correct
celebration for a balanced ledger is quiet certainty, not a fireworks animation.

**6. Arabic is a first language, not a mirrored afterthought.** Every screen is designed and reviewed in
Arabic before it is considered done, not flipped with `dir="rtl"` at the end and eyeballed. Arabic
typography, numeral handling, and icon mirroring follow their own correct rules (detailed in Typography
and Spacing & Grid below) rather than a blind logical mirror of the English layout.

**7. The AI stays humble in the UI, exactly as it stays humble in the API.** Nothing the AI layer produces
is ever styled to look like a fact the system has already committed. Proposed journal entries, forecasts,
and anomaly flags are visually distinct — same accent, same "Suggested" language, same confidence
affordance — from anything a human has approved or the system has posted. A screen should never let a user
mistake an AI draft for ground truth by accident of styling.

**8. Consistency compounds; novelty costs.** A new pattern is a withdrawal against the user's trust in
every other screen. Before introducing a new card shape, a new shadow, a new motion curve, or a new shade
of gray, the component library and this document are checked first. Genuine gaps get proposed as additions
to this document, not one-off exceptions buried in a single screen's code.

# Brand Expression & Tone

QAYD's name is the Arabic word **قيد** (*qayd*) — the accounting term for a journal entry, the single
atomic fact from which every ledger, statement, and report is built. The brand leans into that literalism
rather than away from it: this is software about entries, ink, and the permanent record, and it should
look like it takes that seriously. The visual references are not other accounting software; they are the
objects a serious ledger has always been made of — ruled paper, brass fittings, a fountain pen, a bound
book of record — translated into screen-native type, space, and restraint rather than into skeuomorphic
texture. QAYD should never literally render a leather texture or a pen nib icon; it should behave the way
those objects felt: precise, weighted, unhurried, built to last longer than a trend cycle.

The brand archetype is **the calm expert in the room**, not the hustling founder pitching a demo. Think of
the register of a well-run private bank's annual report, a Swiss watchmaker's product page, or an
automotive marque's configurator — declarative sentences, real numbers, no exclamation marks, no growth-
hacker urgency ("Don't miss out!", "🎉 New feature!"). QAYD is confident enough that it never needs to
perform excitement to be taken seriously. This tone must be identical in English and Arabic; a screen that
sounds premium in English and merely serviceable-translated in Arabic has failed half its audience, and
for a Kuwait-first, Gulf-primary product, Arabic is not the secondary half.

Concretely, this rules out: cartoon mascots or illustrated characters (including for the AI assistant, which
is represented by a mark, never a face); emoji anywhere in product chrome, error states, or system-generated
copy; gradient-mesh or "AI-glow" purple-blue backgrounds; drop-shadow-heavy neumorphic cards; confetti,
particle bursts, or bounce/spring easing on financial actions; illustrated empty-state characters; loud,
saturated, multi-hue dashboards where every KPI tile is a different color "for visual interest." It commits
to: typographic hierarchy doing the work illustration would otherwise do; one accent color used as
punctuation, not paint; motion measured in double-digit-to-low-triple-digit milliseconds; and an Arabic
voice edited by someone fluent in professional Gulf business Arabic, not machine-translated from English
copy after the fact.

# Typography

QAYD uses two Latin type families and one Arabic type family, each doing one job. Mixing more than this in
a single product is how "editorial" becomes "inconsistent."

## Family roles

| Role | Family | Script | Weights used | Source / license |
|---|---|---|---|---|
| Display | **General Sans** | Latin | 500, 600, 700 | Fontshare, free (Fontshare Free License), self-hosted via `next/font/local` |
| Text / UI | **Inter** | Latin | 400, 500, 600, 700 | SIL OFL 1.1, self-hosted via `next/font/google` |
| Arabic (display + text, one family) | **IBM Plex Sans Arabic** | Arabic | 400, 500, 600, 700 | SIL OFL 1.1, self-hosted via `next/font/google` |
| Numeric / code (account codes, IDs, raw JSON in dev views) | **JetBrains Mono** | Latin | 400, 500 | SIL OFL 1.1, self-hosted via `next/font/google` |

**Display (General Sans)** is reserved for page titles, section headings, modal titles, card headers, and
the single "hero number" on a dashboard tile (e.g., the cash position figure at the top of the Banking
overview). It is confident and slightly geometric without being a "startup logotype" face — it reads as
engineered, not playful. It is never used below 16px and never used for a full paragraph of body copy.

**Text (Inter)** carries everything else: body copy, form labels and inputs, buttons, navigation, table
cells, tooltips, toasts. Inter was chosen over General Sans for this role for one concrete reason: Inter's
hinting and its true tabular-figure OpenType feature (`tnum`) hold up at 12–14px in dense financial tables
in a way most display grotesques do not. A General Sans-only interface would look striking in a screenshot
of a dashboard hero and blur at the row-height of a 40-line Trial Balance.

**Arabic (IBM Plex Sans Arabic)** is used for *both* display and text roles in Arabic locale — QAYD does
not attempt a separate "Arabic display face." Arabic display cuts with strong stroke-width contrast lose
legibility fast at UI sizes and rarely have the weight range professional software needs; IBM Plex Sans
Arabic instead gives QAYD one dependable grotesque across the whole hierarchy, with **weight and size**
doing the work that a style change does in Latin. It was drawn to be metrically sympathetic to IBM Plex
Sans, which keeps mixed EN/AR strings (a currency code, a partner's Latin-script name inside an Arabic
sentence) from visually lurching between scripts.

## Type scale

All sizes are defined in `rem` against a 16px root and exposed as Tailwind utilities plus a matching CSS
variable for use outside Tailwind (Framer Motion `style` props, canvas-based chart labels).

| Token | Size | Line height | Letter spacing | Weight | Family | Usage |
|---|---|---|---|---|---|---|
| `display-2xl` | `clamp(2.75rem, 2rem + 3vw, 4rem)` | 1.05 | -0.02em | 600 | Display | Marketing/empty-state hero only |
| `display-xl` | 2.5rem / 40px | 1.10 | -0.015em | 600 | Display | Page H1 ("General Ledger") |
| `display-lg` | 2rem / 32px | 1.15 | -0.01em | 600 | Display | Section H2, modal titles |
| `display-md` | 1.5rem / 24px | 1.20 | -0.005em | 600 | Display | Card headers, panel titles |
| `display-sm` | 1.25rem / 20px | 1.25 | 0 | 600 | Display | Sub-panel headers, stat tile labels |
| `numeral-hero` | `clamp(2rem, 1.5rem + 2vw, 3rem)` | 1.10 | -0.01em | 600 | Display, tabular | One hero KPI number per dashboard tile |
| `text-lg` | 1.125rem / 18px | 1.5 | 0 | 400 | Text | Lead paragraph, empty-state body |
| `text-md` | 1rem / 16px | 1.5 | 0 | 400 | Text | Default body/UI, form inputs |
| `text-sm` | 0.875rem / 14px | 1.43 | 0 | 400 | Text | Table cells, secondary UI, buttons |
| `text-xs` | 0.75rem / 12px | 1.4 | 0.01em | 500 | Text | Captions, table column headers (uppercase) |
| `numeral-table` | 0.875rem / 14px | 1.43 | 0 | 400–600, tabular | Text, tabular | All financial table cells, right-aligned |
| `code-sm` | 0.8125rem / 13px | 1.4 | 0 | 400 | JetBrains Mono | Account codes, IDs, request IDs |

Arabic uses the **same named steps and the same rem sizes** as Latin — `display-xl` is 2.5rem in both
languages — so a bilingual layout never has to renegotiate its grid per language. Two things change for
Arabic, deliberately:

- **Line height is taller** at every step (+0.15 to +0.2 over the Latin value in the table above) to give
  Arabic's connecting strokes and diacritics room; a paragraph set at Latin line-height clips ascenders and
  feels cramped in Arabic even at an identical font size.
- **Letter spacing is always `0` / `normal`.** Negative tracking, used on Latin display sizes above for a
  tightened editorial feel, is never applied to Arabic — it breaks letter-to-letter connections and is
  simply incorrect typesetting, not a style choice.

## Tabular numerals and financial figures

Every number in QAYD that represents money, a quantity, a percentage, or an account code is set in
**tabular (fixed-width) figures**, full stop — a column of amounts where the digits don't align vertically
is an immediate "this wasn't built by people who use spreadsheets" signal. This is enforced with a
dedicated `<Amount>` primitive rather than left to call-site discipline:

```tsx
// components/ui/amount.tsx
import { cn } from "@/lib/utils";

interface AmountProps {
  value: number;
  currency?: string;      // ISO 4217, e.g. "KWD" — omit for plain quantities
  minorUnitDigits?: number; // KWD/BHD/OMR = 3, most others = 2
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
      dir="ltr" // numerals never mirror, even inside an RTL row — see Spacing & Grid
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

Two rules embedded in that component matter beyond the code itself. First, **`dir="ltr"` on every numeral
span** — Kuwait and the wider Gulf write financial amounts with Western (Latin) digits even in fully
Arabic sentences, and without an explicit direction override, the Unicode bidi algorithm can reorder
grouped digits or a trailing currency code inside an RTL paragraph. Second, **`minorUnitDigits` defaults to
three for KWD** (and BHD/OMR), not two — Kuwait's dinar subdivides into 1,000 fils, and a P&L that rounds
`KD 1,204.500` to `KD 1,204.50` is not a rounding error a Kuwaiti finance team will let pass review.

`signed="polarity"` (red/green) is intentionally opt-in, not the default, because of an accounting rule that
is easy to get wrong in software and cheap to get right in a design system: **a debit is not "bad" and a
credit is not "good."** A debit increases an asset or expense account and decreases a liability, equity, or
revenue account; a credit does the reverse. Coloring every debit red and every credit green — the single
most common mistake in accounting-software UI — actively misinforms an accountant, because revenue postings
are credits and are unambiguously good news. `signed="polarity"` is used *only* on genuinely signed,
already-net metrics — Net Income, a budget variance, a period-over-period delta — never on a raw
`journal_lines.debit_amount` or `credit_amount` column. Full color usage rules are in Color & Ink below.

## Font loading

```ts
// lib/fonts.ts
import localFont from "next/font/local";
import { Inter, IBM_Plex_Sans_Arabic, JetBrains_Mono } from "next/font/google";

export const fontDisplay = localFont({
  src: [
    { path: "../assets/fonts/GeneralSans-Medium.woff2", weight: "500", style: "normal" },
    { path: "../assets/fonts/GeneralSans-Semibold.woff2", weight: "600", style: "normal" },
    { path: "../assets/fonts/GeneralSans-Bold.woff2", weight: "700", style: "normal" },
  ],
  variable: "--font-display",
  display: "swap",
});

export const fontText = Inter({
  subsets: ["latin"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-text",
  display: "swap",
});

export const fontArabic = IBM_Plex_Sans_Arabic({
  subsets: ["arabic"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-arabic",
  display: "swap",
});

export const fontMono = JetBrains_Mono({
  subsets: ["latin"],
  weight: ["400", "500"],
  variable: "--font-mono",
  display: "swap",
});
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
// tailwind.config.ts (excerpt)
fontFamily: {
  display: ["var(--font-display)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
  text: ["var(--font-text)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
  arabic: ["var(--font-arabic)", "ui-sans-serif", "system-ui"],
  mono: ["var(--font-mono)", "ui-monospace", "SFMono-Regular", "monospace"],
},
```

Because `fontArabic` is listed as the second fallback in both `display` and `text` stacks, an Arabic
character inside an otherwise-English UI string (a customer's Arabic trade name in a Latin-locale invoice
list, for instance) still renders in IBM Plex Sans Arabic rather than falling through to the browser's
system Arabic font, keeping mixed-script strings visually coherent without any per-string logic.

# Color & Ink

QAYD's palette is near-monochrome by design: a twelve-step warm-neutral ink scale, one accent spent
narrowly, and four semantic financial colors that never bleed into brand usage. "Warm-neutral" is a
deliberate choice over the cooler blue-black most AI/SaaS dashboards default to (Linear, Vercel, and a
hundred imitators) — a faint warmth reads as paper and ink, the product's own metaphor, where a cool
slate-blue reads as "generic AI tool." The warmth is subtle: a few points of yellow hue at low saturation,
never enough to look beige or sepia.

## Ink scale

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
| `ink-12` | `#15130E` | `#F8F6EF` | Display text, near-black/near-white |

The scale follows a Radix-Colors-style twelve-step usage convention — steps 1–2 for backgrounds, 3–5 for
component fills, 6–8 for borders and disabled states, 9–10 for icons and secondary text, 11–12 for
high-emphasis text — so any engineer already familiar with that convention from shadcn/ui's own theming
model can pick up QAYD's scale with zero ramp-up. Dark mode is **not** a literal inversion of the light
values; it is its own calibrated ramp (see Dark Mode strategy below) so that borders, disabled states, and
text all keep the same *relative* contrast rather than mechanically flipping.

## Accent — the one color QAYD spends

| Token | Hex (light) | Hex (dark) | Usage |
|---|---|---|---|
| `accent-subtle` | `#EADFBF` | `#3A2E14` | Selected-row tint, AI-proposal card background |
| `accent` | `#9C7A34` | `#D9B96C` | Primary button fill, focus ring, active tab, links |
| `accent-strong` | `#7A5D22` | `#E8CE8E` | Hover/pressed state of the above |
| `accent-on` | `ink-12` (`#15130E`) | `ink-1` (`#14130F`)-equivalent near-black | Text/icon drawn on top of `accent` |

The accent is a restrained brass, not a bright SaaS indigo or violet — both of those hues were deliberately
avoided because they are now the default "AI startup" signal, the opposite of distinctive. Brass also
carries the brand's own metaphor (a ledger's brass fittings, an engraved plate) without requiring any
literal texture on screen.

**`accent-on` is near-black, never white.** A mid-value warm brass does not clear 4.5:1 contrast against
white text (roughly 3.2:1 at the `accent` value above — passes only as a large-scale/non-text element, not
as button label text), but comfortably clears it against `ink-12` (roughly 5.5:1). This is also the more
authentic material read: engraved brass is a dark mark on a gold ground, not a white one. Every filled
`accent` surface — the primary `<Button variant="default">`, the active segmented-control tab, a selected
chip — pairs with `accent-on`, not with `ink-1`/white. Text or icons drawn as `accent` directly *on* an
`ink-1`/`ink-2` background (a link, an active nav icon) use `accent` in light mode and the lighter
`accent`/dark-mode value, which itself clears 4.5:1 against the dark ink backgrounds.

**The accent is also the AI's signature.** By rule, `accent` and `accent-subtle` are used in exactly two
contexts: the one primary action per screen, and anything the AI layer has produced — a proposed journal
entry card, a confidence badge, the assistant's focus ring, the subtle highlight on a row an anomaly-
detection agent has flagged. This is a deliberate double duty: it keeps the palette to one hue as promised,
and it means a user can learn, over time, that "brass on this screen" always means either "this is the
button I should press" or "this is something AI touched, not something committed" — never anything else.

## Semantic colors — financial state, never brand state

| Token | Hex (light) | Hex (dark) | Meaning | Never use for |
|---|---|---|---|---|
| `positive` | `#17794A` | `#4ADE94` | Increase, gain, reconciled, success | Brand actions, AI badges |
| `positive-subtle` | `#E3F3EA` | `#12301F` | Positive background wash | — |
| `negative` | `#B4232E` | `#F26B74` | Decrease, loss, overdue, destructive | Brand actions, AI badges |
| `negative-subtle` | `#FBE7E8` | `#3A1618` | Negative background wash | — |
| `warning` | `#B45309` | `#F5A855` | Pending, unreconciled, approaching a limit | Brand accent (kept visually distinct from brass — more orange, higher chroma) |
| `warning-subtle` | `#FBEBDA` | `#3A2812` | Warning background wash | — |
| `info` | *(none — see below)* | | Neutral, non-financial notices | — |

QAYD deliberately has **no fifth hue for "info."** A plain informational banner ("Your session will expire
in 5 minutes") is styled with the ink scale alone — `ink-11` text on `ink-3` background with an `ink-6`
border — not a blue tint. Introducing blue "for info" is how most products quietly end up with two brand
colors; QAYD would rather an informational banner look slightly less decorated than invent a second accent.
The one exception, by the AI rule above, is that an *AI-sourced* notice (not a plain system notice) may use
`accent-subtle` instead of `ink-3`, because "this came from the AI layer" is exactly the signal `accent` is
reserved to carry.

### The debit/credit rule

Restated from Typography because it is a color rule as much as a numeral-formatting rule: **`positive` and
`negative` are never applied to a raw debit or credit amount.** Debit-normal and credit-normal are
structural properties of an account type (Assets/Expenses are debit-normal; Liabilities/Equity/Revenue are
credit-normal — see `docs/accounting/CHART_OF_ACCOUNTS.md`), not judgments of good and bad, and a Journal
Entry Lines table, a General Ledger, and a Trial Balance all render their Debit and Credit columns in
plain `ink-12` tabular numerals, distinguished only by column position and a right-aligned column header,
never by hue. Color is reserved for the layer above the raw ledger — Net Income on a P&L, a budget
variance, a period-over-period change on a dashboard tile — where a number is genuinely signed and the sign
genuinely means "better" or "worse." Even there, QAYD colors a small delta annotation next to the figure
(a `+2.4%` chip, an up/down glyph) rather than washing the entire numeral in red or green, so the number
itself always stays legible in the neutral ink first.

## Contrast targets

| Pairing | Minimum ratio | Typical actual |
|---|---|---|
| Body text (`ink-11`/`ink-12`) on `ink-1`/`ink-2` | 4.5:1 (AA normal text) | ~13:1 |
| Secondary text (`ink-9`) on `ink-1`/`ink-2` | 4.5:1 | ~5.1:1 |
| `accent-on` on `accent` fill | 4.5:1 | ~5.5:1 |
| `destructive-foreground` (white) on `negative` fill | 4.5:1 | ~5.8:1 |
| Disabled text (`ink-8`) on `ink-3` | 3:1 (non-text / disabled exempt, kept legible anyway) | ~2.9–3.1:1 |
| Focus ring (`accent`) on any surface | 3:1 (non-text, WCAG 1.4.11) | ≥3:1 at every surface step |

QAYD targets **WCAG 2.1 AA** as a floor across both themes and both languages; disabled controls are the
one place a slightly lower ratio is accepted, and only because a disabled control's information ("this
isn't available to you right now") is reinforced by a tooltip and a `cursor-not-allowed`/`aria-disabled`
state, not by color contrast alone. Any permission-gated control that is disabled rather than hidden must
carry a tooltip naming the required permission (e.g. "Requires `accounting.journal.post`") — QAYD never
leaves a user staring at a grayed-out button with no explanation.

## Dark mode strategy

Dark mode is a first-class theme, not an inverted filter. `next-themes` drives a `class="dark"` on `<html>`
(system-aware by default, user-overridable, persisted per user), and every token in the tables above has an
explicit dark-mode value rather than a CSS `invert()` or automatic luminance flip. Three rules keep dark
mode from feeling like a different, cheaper product:

- **Backgrounds stay warm-neutral, not blue-black.** `ink-1` in dark mode is `#14130F`, not a cool
  near-black — the same warmth carries through both themes.
- **Elevation gets lighter, not darker,** in dark mode: a raised card (`ink-3` dark) is lighter than the
  canvas (`ink-1` dark) it sits on, which is how physical light and most professional dark UIs actually
  behave, rather than the inverse.
- **The accent gets lighter, not more saturated,** in dark mode (`#9C7A34` → `#D9B96C`) so it keeps the same
  4.5:1+ contrast against `accent-on` and against the darker canvas, instead of glowing unnaturally against
  a black background the way a raw hue-preserved accent would.

# Spacing & Grid

## Spacing scale

QAYD adopts Tailwind's default 4px spacing scale unmodified (`p-1` = 4px through `p-32` = 128px) rather
than inventing a parallel one — one fewer thing to remember, and it is already what shadcn/ui's components
assume. Semantic aliases are layered on top for the handful of values that recur as *decisions*, not just
measurements:

| Semantic token | Value | Tailwind | Usage |
|---|---|---|---|
| `--space-2xs` | 4px | `1` | Icon-to-label gap, chip internal padding |
| `--space-xs` | 8px | `2` | Tight stack gap, table cell vertical padding (compact) |
| `--space-sm` | 12px | `3` | Form field internal padding, table cell vertical padding (comfortable) |
| `--space-md` | 16px | `4` | Default stack gap, card internal padding (small card) |
| `--space-lg` | 24px | `6` | Card internal padding (default), section gap within a panel |
| `--space-xl` | 32px | `8` | Gap between panels/cards on a page |
| `--space-2xl` | 48px | `12` | Page-level section gap |
| `--space-3xl` | 64px | `16` | Page top padding under the topbar on wide screens |

## Grid & breakpoints

QAYD uses Tailwind's default breakpoints (`sm` 640, `md` 768, `lg` 1024, `xl` 1280, `2xl` 1536) plus one
addition for the ultra-wide monitors common on accountants' desks:

| Breakpoint | Min width | Layout behavior |
|---|---|---|
| (base) | 0 | Single column, bottom tab bar, tables become stacked cards or horizontally scroll within a bordered container |
| `sm` | 640px | Two-column forms allowed; tables scroll horizontally with a sticky first column |
| `md` | 768px | Sidebar becomes a collapsible rail; 12-column grid active for dashboard tiles |
| `lg` | 1024px | Sidebar is persistent (264px expanded / 72px icon-only collapsed); full table layouts, no horizontal scroll for standard reports |
| `xl` | 1280px | Two-panel layouts (list + detail) become side-by-side instead of stacked/drawer |
| `2xl` | 1536px | Content max-width caps at 1440px and centers; dense ledger tables get extra breathing margin instead of stretching edge-to-edge |
| `3xl` (custom, 1920px) | 1920px | Content max-width caps at 1600px; used on the General Ledger and Trial Balance screens specifically, which benefit from the extra columns an ultra-wide monitor offers |

The app shell is constant across breakpoints ≥`lg`: a 64px topbar (company switcher, global search, AI
status, notifications, user menu) and a persistent sidebar, with the routed page content in a scrollable
region that owns its own horizontal scroll for wide tables rather than letting the whole page scroll
sideways.

## RTL mirroring

QAYD mirrors layout direction using **CSS logical properties exclusively** — never physical
left/right values — so a component written once behaves correctly in both directions with zero
conditional code:

```tsx
// Correct — logical, mirrors automatically with dir="rtl"
<div className="ms-4 me-2 ps-6 border-s">

// Wrong — physical, will not mirror, banned in review
<div className="ml-4 mr-2 pl-6 border-l">
```

Tailwind's logical spacing/border utilities (`ms-*`, `me-*`, `ps-*`, `pe-*`, `border-s`, `border-e`,
`text-start`, `text-end`) are enforced by an ESLint rule (`eslint-plugin-tailwindcss` custom restriction)
that flags any `ml-`, `mr-`, `pl-`, `pr-`, `border-l-`, `border-r-`, `text-left`, `text-right` class outside
a short, reviewed allow-list (the numeral-alignment cases in Data Density below, which are intentionally
**not** mirrored). Icons that encode direction — a chevron indicating "next," an arrow on a "back" button —
are mirrored via a `[dir="rtl"] &` CSS transform (`scaleX(-1)`) at the icon-wrapper level; icons that do
not encode direction (a checkmark, a trash icon, the QAYD mark itself, any Lucide icon depicting a static
object) are never mirrored.

# Elevation & Surfaces

QAYD builds elevation primarily from **hairline borders**, not shadow depth — an editorial-flat approach
closer to Linear or Stripe's dashboard than to a Material-style stacked-shadow system. Shadows exist, but
they are quiet enough that removing them would not visibly break the hierarchy on their own; the border
does most of the work.

| Surface level | Background | Border | Shadow | Usage |
|---|---|---|---|---|
| `surface-0` | `ink-1` | — | none | App canvas |
| `surface-1` | `ink-2` or `ink-3` | 1px `ink-6` | `shadow-xs` | Cards, panels, sidebar |
| `surface-2` | `ink-1` (light) / `ink-3` (dark) | 1px `ink-6` | `shadow-sm` | Dropdowns, popovers, date pickers |
| `surface-3` | `ink-1` (light) / `ink-3` (dark) | 1px `ink-6` | `shadow-lg` | Modals, dialogs (with `ink-12`/50% scrim beneath) |
| `surface-glass` | `ink-1` @ 72% + `backdrop-blur(24px)` | 1px `ink-6` @ 60% | `shadow-md` | Command palette (⌘K) and the AI Command Center overlay **only** |

`surface-glass` is intentionally rare. Frosted/blurred glass reads as premium exactly because it is
unusual; a UI that glasses every panel loses the effect and gains a performance cost (backdrop-blur is not
free on lower-end hardware, and QAYD's primary audience includes back-office desktops that are not gaming
rigs). It is reserved for the two moments — the global command palette and the AI Command Center — where a
panel floats conceptually above the entire app rather than belonging to the current page.

## Shadow tokens

```css
--shadow-xs: 0 1px 2px rgba(21, 19, 14, 0.04);
--shadow-sm: 0 2px 4px rgba(21, 19, 14, 0.06);
--shadow-md: 0 8px 16px -4px rgba(21, 19, 14, 0.08);
--shadow-lg: 0 24px 48px -12px rgba(21, 19, 14, 0.16);
```

In dark mode the same shadows are used at half opacity (shadows read as "too heavy" against a dark
background at light-mode opacity, since there is less ambient contrast for a soft shadow to sit inside) —
`--shadow-xs` through `--shadow-lg` are redefined under `.dark` with `rgba(0, 0, 0, ...)` values at roughly
50% of the light-mode alpha.

## Corner radius

| Token | Value | Usage |
|---|---|---|
| `radius-sm` | 4px | Checkboxes, small tags/badges, table-cell inline chips |
| `radius-md` | 6px | Inputs, buttons, select triggers |
| `radius-lg` | 8px | Cards, panels, dropdown menus, modals' inner content — the workhorse radius |
| `radius-xl` | 10px | Modal/dialog outer surface only, marginally softer than a card |
| `radius-full` | 9999px | Avatars, status dots, pill badges — the *only* fully-rounded shapes in the system |

A maximum radius of 10px on any rectangular surface is a deliberate ceiling, well under the 20–24px the
earlier foundation draft specified. Large radii read as consumer/playful (Notion, Duolingo, mobile-first
social apps); a tight 4–10px range reads as engineered and precise, consistent with the car/fashion-brand
reference point — a Bentley's console does not have rounded-off corners, and neither should a Trial
Balance.

## Card anatomy

```tsx
// components/ui/card.tsx usage pattern
<div className="rounded-lg border border-ink-6 bg-ink-2 p-6 shadow-xs dark:bg-ink-3">
  <div className="flex items-center justify-between mb-4">
    <h3 className="font-display text-display-sm text-ink-12">Cash Position</h3>
    <Button variant="ghost" size="icon"><MoreHorizontal className="size-4" /></Button>
  </div>
  <p className="font-display numeral-hero tabular-nums text-ink-12">KD 84,210.500</p>
  <p className="text-text-sm text-positive mt-1">+2.4% vs last month</p>
</div>
```

## Z-index scale

| Token | Value | Layer |
|---|---|---|
| `z-base` | 0 | Default document flow |
| `z-sticky` | 10 | Sticky table headers/footers, sticky page sub-nav |
| `z-dropdown` | 20 | Select menus, dropdown menus, popovers |
| `z-overlay` | 30 | Modal/drawer scrim |
| `z-modal` | 40 | Modal/drawer content |
| `z-toast` | 50 | Toast notifications |
| `z-command` | 60 | Command palette (⌘K), AI Command Center overlay |
| `z-tooltip` | 70 | Tooltips (always above everything, including modals) |

# Motion

Motion in QAYD is implemented with **Framer Motion** end to end — no ad-hoc CSS `transition` on
interactive components, so that duration, easing, and reduced-motion handling stay centralized in one
`lib/motion.ts` rather than scattered across component files.

## Duration & easing tokens

| Token | Duration | Used for |
|---|---|---|
| `motion.instant` | 0ms | State flips under `prefers-reduced-motion`; checkbox/toggle visual flip when motion is off |
| `motion.micro` | 120ms | Button press scale, checkbox check, icon hover |
| `motion.fast` | 160ms | Tooltip/dropdown appear, table row hover highlight |
| `motion.base` | 200ms | Tab switch, accordion expand, panel content swap |
| `motion.moderate` | 280ms | Modal/drawer enter/exit, popover with content |
| `motion.slow` | 360ms | Route/page transition, onboarding step transition |

```ts
// lib/motion.ts
export const easeOut = [0.16, 1, 0.3, 1] as const;      // entrances
export const easeInOut = [0.65, 0, 0.35, 1] as const;   // toggles, expand/collapse
export const easeIn = [0.7, 0, 0.84, 0] as const;       // exits

export const duration = {
  instant: 0,
  micro: 0.12,
  fast: 0.16,
  base: 0.2,
  moderate: 0.28,
  slow: 0.36,
} as const;

export const fadeUp = {
  initial: { opacity: 0, y: 8 },
  animate: { opacity: 1, y: 0, transition: { duration: duration.base, ease: easeOut } },
  exit: { opacity: 0, y: 4, transition: { duration: duration.fast, ease: easeIn } },
};

export const listStagger = {
  animate: { transition: { staggerChildren: 0.03 } }, // 30ms per row, capped — see below
};
```

## Reduced motion

Every animated component reads `useReducedMotion()` from Framer Motion, and `lib/motion.ts` exports a
`getTransition()` helper that any component calls instead of hand-writing a `transition` object:

```ts
import { useReducedMotion } from "framer-motion";

export function getTransition(base: { duration: number; ease: readonly number[] }) {
  const reduced = useReducedMotion();
  return reduced ? { duration: 0 } : base;
}
```

This is backed by the standard CSS media query as a second, framework-independent safety net for anything
outside Framer Motion's reach (native `<details>` transitions, scroll-behavior):

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

Reduced motion never removes a *state change* — a panel still opens, a toast still appears — it only
removes the *animation* of that change, which is the correct interpretation of the media query (users who
set it still expect the UI to update, just without motion).

## Named patterns

| Pattern | Spec |
|---|---|
| Button press | `scale: 0.98`, `motion.micro`, `easeInOut` |
| List/table row entrance | `fadeUp`, staggered at 30ms per row, **capped at 12 rows** (beyond 12, the remaining rows appear together in the 13th slot's animation — a 200-row ledger must never take four seconds to finish "animating in") |
| Skeleton loading | A slow (1.6s), low-contrast (`ink-3` → `ink-4` → `ink-3`) shimmer sweep, `ease: linear`, `repeat: Infinity` — never a spinner as the primary loading state for content that has a known shape |
| AI "thinking" indicator | Three `accent-subtle` dots, opacity pulsing 0.3→1→0.3 in a 1.2s staggered loop — deliberately calmer and slower than a typical chat-app typing indicator |
| Toast enter/exit | Slide + fade from the block-end edge, `motion.moderate`, `easeOut` in / `easeIn` out |
| Modal/drawer enter | Scale 0.98→1 + fade, `motion.moderate`, `easeOut`; scrim fades in at the same duration |
| Route transition | Cross-fade only (`motion.slow`), no slide/push — a slide implies spatial navigation the app's IA does not have |
| Realtime row update (Reverb event arrives) | The affected row's background flashes to `accent-subtle` then eases back to transparent over 600ms, `easeInOut` — no toast, no sound, no layout shift if avoidable |
| Success confirmation (e.g. entry posted) | A single checkmark icon fades/scales in over `motion.base`, holds 900ms, fades out — no confetti, no bounce, no sound by default |

That last row is a direct expression of Design Principle 5: the correct feedback for a balanced, posted
journal entry is a quiet, brief checkmark, not a celebratory animation. Motion in QAYD is calibrated to
feel like a well-made physical switch — a short, certain click — never like a game reward.

# Data Density

Every screen listed in the screen-doc series — General Ledger, Journal Entries, Trial Balance, Balance
Sheet, P&L, Cash Flow, Banking, Bank Reconciliation — lives or dies on how well its tables hold up at real
Kuwaiti-SME-to-holding-company scale: hundreds to tens of thousands of rows. QAYD's answer is density
through typography and row height, not through removing structure.

## Table density modes

| Mode | Row height | Cell font | Cell vertical padding | Default on |
|---|---|---|---|---|
| Compact | 32px | `text-xs` (12px) | `--space-xs` (8px) | General Ledger, Journal Entries (>50 lines) |
| Comfortable | 40px | `text-sm` (14px) | `--space-sm` (12px) | Trial Balance, Banking, most list views (default) |
| Relaxed | 48px | `text-sm` (14px) | `--space-md` (16px) | Customer/Vendor directories, settings lists |

Density is a **per-table, user-persisted preference** (a density toggle in the table's own toolbar, stored
in the user's settings, not a single global app setting) because an accountant reconciling 4,000 bank lines
and an owner glancing at a 12-row vendor list have different, equally valid density needs on the same day.

## Row treatment

QAYD does not use fill-based zebra striping — alternating row background colors read as busy at density
and fight with the AI-highlight and selection states, which also use background fills. Instead, every row
gets a single 1px `ink-6` bottom border, and on tables over ~40 rows, every fifth row gets a marginally
stronger `ink-7` border — a deliberate callback to ruled ledger paper's grouped rule lines, and a genuine
scanning aid at density (it lets an eye count "five rows down" without a running counter column).

## Numeral formatting rules

| Rule | Applies to | Example |
|---|---|---|
| Tabular figures, right-aligned | Every numeric column | — |
| Minus sign for negative | Raw/editable data — ledger tables, form inputs, CSV-style exports | `-1,204.500` |
| Parentheses for negative | Rendered financial statements — Balance Sheet, P&L, Cash Flow, printed/exported reports | `(1,204.500)` |
| Three decimal places | KWD, BHD, OMR amounts | `KD 1,204.500` |
| Two decimal places | USD, SAR, AED, and all other supported currencies | `$1,204.50` |
| Em dash, not zero, for "no value"/not-applicable | A cell where a metric genuinely does not apply (e.g. a Credit column on a debit-only template row) | `—` |
| Explicit zero for an actual zero balance | A reconciled account, a fully paid invoice | `0.000` |
| Currency as **code**, not symbol | Every amount, always | `KD 1,204.500`, never a symbol glyph — KWD has no universal, well-supported symbol |

The minus-sign/parentheses split is deliberate rather than a missed inconsistency: raw, editable, or
export-adjacent views favor the unambiguous minus sign because a user may be scanning for negatives quickly
or piping the value elsewhere; formally rendered financial statements follow the accounting-typesetting
convention Kuwaiti and international auditors already read fluently, where parentheses — not color — are
the primary negative-number signal, so a printed Balance Sheet reads correctly even in grayscale.

## Running totals and sticky structure

Any table with a meaningful running total (a Trial Balance's Debit/Credit totals, a bank register's running
balance, a Journal Entries list's page subtotal) pins that total to a sticky footer: `ink-3` background,
1px `ink-7` top border, values set in `display-sm` weight 600 rather than the row-level `numeral-table`
style, so the total is visibly heavier than any single line without introducing a new color. Column headers
are similarly sticky (`z-sticky`) on scroll, in `text-xs` uppercase with `ink-9` color and 0.01em tracking.

## AI provenance in a table row

A row that an AI agent proposed or flagged (an unposted AI-drafted journal entry, a bank line the
reconciliation agent matched, a transaction the fraud-detection agent scored as anomalous) is marked with a
single small (6px) `accent` dot in the row's leading gutter — not a colored row background, not a full
badge cluttering the row — plus a `"Suggested"` micro-label only in the expanded/detail view. The
underlying confidence score (see `docs/ai/agents/*` for how each agent computes it) is available on hover
as a tooltip (`"92% confidence — Accountant Agent"`) rather than rendered as a permanent percentage in every
row, which would compete with the actual financial figures for attention. Once a human approves or the
system posts the row, the dot disappears — provenance is visible until a decision is made, not forever.

## Virtualization & interaction

Tables expected to exceed roughly 200 rows (General Ledger, Journal Entries history, Bank Reconciliation
statement lines) are virtualized with `@tanstack/react-virtual`, using the active density mode's fixed row
height for the virtualizer's size estimate, combined with `@tanstack/react-table` for column state, sorting,
and column visibility, and `@tanstack/react-query`-backed cursor pagination underneath the virtualized
window (the API is cursor-paginated per the platform's API conventions; virtualization and pagination are
complementary, not redundant — the query layer fetches pages of ~100 rows as the virtualizer's window
approaches the end of the currently-loaded set). Every dense table supports arrow-key row navigation, `Enter`
to open the focused row's detail, and a table-scoped `Cmd/Ctrl+F` that focuses an in-table filter input
rather than the browser's native find (which cannot see virtualized, off-screen rows).

# Imagery & Illustration stance

QAYD carries **no photography and no illustration as decoration.** No stock photos of people in blazers
shaking hands, no isometric 3D illustrations of coins and charts, no gradient-mesh blobs behind a hero
headline, no cartoon mascot for the AI assistant. This is a direct extension of Design Principle 1: if the
interface needs a picture to feel finished, the typography and spacing have not done their job yet.

**Iconography** is Lucide, exclusively — 1.5px stroke weight, 16px in dense table/toolbar contexts, 20px as
the general default, 24px only in empty states or as a lone focal icon in a card. Icons are line-only; QAYD
does not use Lucide's filled variants anywhere except a single filled dot for status indicators (online,
unread, AI-flagged) at 6–8px. Icons never carry more than one color — an icon is `ink-9`/`ink-10` by
default, `accent` only when it represents the single primary action on its surface (e.g. the Post button's
icon) or an AI-touched element, and a semantic color only when it *is* the status (a red triangle for an
overdue invoice's row icon), never decoratively.

**The AI assistant has a mark, not a face.** Wherever the assistant needs a visual identity — an avatar in
a chat panel, a badge on the AI Command Center nav item — QAYD uses a small geometric glyph (a checkmark
inside a circle, echoing a ledger tick-mark) rendered in `accent` on `ink-2`, never an illustrated character,
never an emoji, never a "friendly robot" icon. The assistant is a capability of the product, not a
character the product is roleplaying as.

**Empty states** use, at most, a single line-drawn abstraction at 1.5px stroke in `ink-7` (with the one
focal element, if any, in `accent`) — an outline suggesting a stack of documents, a ruled page, an empty
tray — never a full-color illustrated scene, never a character reacting to the emptiness. The accompanying
copy (see Voice & Microcopy) carries most of the communicative weight; the graphic is a quiet visual anchor,
not the message.

**Data visualization** — charts on the Dashboard, Cash Flow, and Trends views — draws from the ink scale
plus the accent plus the semantic set only, never an arbitrary categorical rainbow palette. A two-series
comparison (actual vs. budget, this period vs. last) uses `accent` against `ink-7`; a signed metric (cash
in vs. cash out) uses `positive`/`negative`; where a chart genuinely needs more than two or three series
(a cost-center breakdown with six categories), QAYD varies **value** — light-to-dark tints of the accent —
before it reaches for a second hue, and falls back to direct data labels over a legend once a chart would
otherwise need a fourth or fifth color to stay legible.

**Photography, if QAYD ever uses it** (marketing surfaces outside the app shell, not the product itself) is
restricted to genuine, specific, unstaged material — a real Kuwaiti SME's storefront, a real ledger page —
never generic stock imagery of unrelated professionals in unrelated offices. Inside the authenticated
product, there is no photography at all.

# Voice & Microcopy (EN/AR)

## Tone rules

Product copy in QAYD is written the way a competent senior accountant talks to a client they respect: plain
sentences, correct terminology, no exclamation marks, no rhetorical questions, no emoji — anywhere,
including success toasts, empty states, and the AI chat. Buttons are labeled with a verb and an object
("Post entry," not "Submit"; "Reconcile statement," not "Go"). Errors state what happened, why, and what to
do next, in that order, and never blame the user ("This entry is not balanced" rather than "You made a
mistake"). The AI layer always speaks in proposing language — *suggest, propose, recommend, flag* — never
assertive/completed language for anything a human has not yet approved; "I've suggested a match for this
transaction" is correct, "I've matched this transaction" is not, even if the AI is highly confident, because
the UI must never imply an action has been taken when it is still pending human approval.

| Context | Don't | Do |
|---|---|---|
| Primary action | "Submit" / "Go" | "Post entry" / "Reconcile statement" |
| Destructive confirm | "Are you sure?? 😬" | "Void this entry? This cannot be undone. A reversing entry will be created automatically." |
| Success toast | "Woohoo! 🎉 Entry posted!" | "Entry posted." |
| Validation error | "Oops, something's wrong with your entry" | "This entry is not balanced. Debits (KD 1,204.500) must equal credits (KD 1,150.000)." |
| Empty state | "Nothing here yet! Let's fix that 🚀" | "No transactions yet. Connect a bank account or create your first journal entry." |
| AI proposal | "I matched this for you!" | "Suggested match — 92% confidence. Review and confirm." |
| Permission-gated action | *(button silently missing)* | Visible, disabled, tooltip: "Requires `accounting.journal.post`." |

## Arabic microcopy

Arabic copy is written directly by a fluent professional-register writer, not machine-translated from the
English strings above and then adjusted — the two are produced as parallel originals held to the same tone
brief. The register is **professional Modern Standard Arabic used in Gulf business contexts**: correct and
precise, but not ornately literary Classical Arabic, and not colloquial Kuwaiti/Gulf dialect (dialect is
reserved for informal marketing or a support chat, never for system chrome, buttons, or financial
statements). Numerals stay Western (Latin) digits in Arabic copy, per the numeral rules in Typography and
Data Density — QAYD never switches to Eastern Arabic-Indic digits (٠١٢٣...) for financial figures, matching
how Gulf ledgers, invoices, and spreadsheets are actually written. Correct accounting terminology is used
consistently rather than approximated: مدين (debit), دائن (credit), ترحيل (posting), قيد (entry/voucher),
ميزان المراجعة (trial balance), دفتر الأستاذ (general ledger), القوائم المالية (financial statements).

| Context | English | Arabic |
|---|---|---|
| Primary action | Post entry | ترحيل القيد |
| Primary action | Save draft | حفظ كمسودة |
| Approval action | Approve | اعتماد |
| Approval action | Reject | رفض |
| Validation error | This entry is not balanced. Debits must equal credits. | هذا القيد غير متوازن. يجب أن يتساوى إجمالي المدين مع إجمالي الدائن. |
| Success toast | Entry posted. | تم ترحيل القيد. |
| AI proposal | Suggested by the Accountant Agent — 92% confidence. | مقترح من وكيل المحاسب — بثقة 92%. |
| Empty state | No transactions yet. Connect a bank account or create your first journal entry. | لا توجد معاملات حتى الآن. اربط حسابًا بنكيًا أو أنشئ أول قيد محاسبي. |
| Permission tooltip | Requires `accounting.journal.post`. | يتطلب صلاحية `accounting.journal.post`. |

Every Arabic string above is reviewed for register the same way its English counterpart is: would a CFO
reading this in a board pack find it precise and calm, or would they find it stiff, over-translated, or too
casual. A string that fails that test in either language is rewritten, not shipped.

# Do & Don't

| Do | Don't |
|---|---|
| Use one accent (brass) for the single primary action and AI-touched elements | Use a different bright color per card/tile "for visual interest" |
| Build hierarchy with type weight, size, and space | Build hierarchy by making things colorful |
| Use hairline `ink-6`/`ink-7` borders for structure | Use heavy drop shadows or neumorphic bevels |
| Keep corner radius ≤10px on rectangular surfaces | Use 20px+ "friendly" rounded cards |
| Use Lucide line icons, one color, restrained | Use filled/colorful icon sets, illustrated icon packs |
| Represent the AI assistant with a geometric mark | Give the AI assistant a cartoon face, mascot, or avatar photo |
| Write plain, precise, calm copy in both languages | Use exclamation marks, emoji, or hype language ("🎉", "Awesome!") |
| Color a signed, net metric (Net Income, variance) when it is genuinely good/bad news | Color a raw debit or credit red/green |
| Animate to explain a change, in under ~300ms | Animate to delight — bounces, confetti, spring-wobble on financial actions |
| Show a disabled control with a tooltip explaining the missing permission | Hide a control silently so the user can't tell it exists |
| Treat Arabic as a first-class, separately-authored voice | Machine-translate English copy and ship it as Arabic |
| Use currency codes ("KD 1,204.500") | Invent or misuse a currency symbol glyph |
| Reserve `accent`/`accent-subtle` for AI provenance and primary actions only | Use the accent color as a generic "highlight" anywhere convenient |
| Let a screen read correctly with the accent temporarily removed | Depend on color alone to carry meaning or hierarchy |

# Design Tokens (CSS variables)

The tables throughout this document are the source of truth; this section is their literal implementation.
`app/globals.css` defines the raw scale and the shadcn/ui semantic aliases; `tailwind.config.ts` exposes
both to utility classes; `lib/tokens.ts` exposes the subset Framer Motion and canvas-based chart code need
as plain JS/TS values (CSS custom properties cannot be read directly inside a `transition` object or a
canvas `fillStyle` computation without an extra `getComputedStyle` round-trip, so motion and chart code
import from `tokens.ts` instead).

```css
/* app/globals.css */
:root {
  /* Ink — warm-neutral graphite scale */
  --qayd-ink-1: #FAFAF9;
  --qayd-ink-2: #F4F3F1;
  --qayd-ink-3: #EBE9E6;
  --qayd-ink-4: #E0DEDA;
  --qayd-ink-5: #D3D0CA;
  --qayd-ink-6: #C2BEB6;
  --qayd-ink-7: #A9A49A;
  --qayd-ink-8: #878174;
  --qayd-ink-9: #645F53;
  --qayd-ink-10: #4A4639;
  --qayd-ink-11: #2B2820;
  --qayd-ink-12: #15130E;

  /* Accent — one disciplined brass, never used for status */
  --qayd-accent-subtle: #EADFBF;
  --qayd-accent: #9C7A34;
  --qayd-accent-strong: #7A5D22;
  --qayd-accent-on: var(--qayd-ink-12);

  /* Semantic — financial polarity only, never brand */
  --qayd-positive: #17794A;
  --qayd-positive-subtle: #E3F3EA;
  --qayd-negative: #B4232E;
  --qayd-negative-subtle: #FBE7E8;
  --qayd-warning: #B45309;
  --qayd-warning-subtle: #FBEBDA;

  /* Radius */
  --radius-sm: 4px;
  --radius-md: 6px;
  --radius-lg: 8px;
  --radius-xl: 10px;

  /* shadcn/ui semantic aliases — consumed as hsl(var(--x)) */
  --background: 48 20% 98%;
  --foreground: 40 30% 7%;
  --card: 45 15% 95%;
  --card-foreground: 40 30% 7%;
  --popover: 48 20% 99%;
  --popover-foreground: 40 30% 7%;
  --primary: 42 50% 38%;              /* = --qayd-accent */
  --primary-foreground: 40 30% 7%;    /* = --qayd-accent-on (near-black, not white) */
  --secondary: 45 15% 92%;
  --secondary-foreground: 40 30% 12%;
  --muted: 45 15% 93%;
  --muted-foreground: 42 10% 40%;     /* = --qayd-ink-9 */
  /* NOTE: shadcn's own --accent/--accent-foreground are a NEUTRAL hover/highlight
     tint (menu item hover, tab hover) — they are NOT the brand color. Do not point
     shadcn's --accent at --qayd-accent, or every hovered menu row turns gold. */
  --accent: 45 15% 92%;
  --accent-foreground: 40 30% 10%;
  --destructive: 355 65% 40%;         /* = --qayd-negative */
  --destructive-foreground: 0 0% 100%;
  --border: 42 14% 78%;               /* = --qayd-ink-6 */
  --input: 42 14% 72%;                /* = --qayd-ink-7 */
  --ring: 42 55% 40%;                 /* = --qayd-accent, focus ring */
  --radius: 0.5rem;                   /* shadcn base unit; component radius above overrides per-component */
}

.dark {
  --qayd-ink-1: #14130F;
  --qayd-ink-2: #1B1914;
  --qayd-ink-3: #24211A;
  --qayd-ink-4: #2D2921;
  --qayd-ink-5: #383327;
  --qayd-ink-6: #46402F;
  --qayd-ink-7: #58503A;
  --qayd-ink-8: #786F55;
  --qayd-ink-9: #A39878;
  --qayd-ink-10: #C2B99C;
  --qayd-ink-11: #E4DEC9;
  --qayd-ink-12: #F8F6EF;

  --qayd-accent-subtle: #3A2E14;
  --qayd-accent: #D9B96C;
  --qayd-accent-strong: #E8CE8E;
  --qayd-accent-on: #14130F;

  --qayd-positive: #4ADE94;
  --qayd-positive-subtle: #12301F;
  --qayd-negative: #F26B74;
  --qayd-negative-subtle: #3A1618;
  --qayd-warning: #F5A855;
  --qayd-warning-subtle: #3A2812;

  --background: 40 14% 8%;
  --foreground: 45 30% 96%;
  --card: 38 13% 11%;
  --card-foreground: 45 30% 96%;
  --popover: 40 14% 9%;
  --popover-foreground: 45 30% 96%;
  --primary: 40 60% 68%;
  --primary-foreground: 40 14% 7%;
  --secondary: 38 12% 15%;
  --secondary-foreground: 45 20% 90%;
  --muted: 38 12% 15%;
  --muted-foreground: 38 20% 65%;
  --accent: 38 12% 16%;
  --accent-foreground: 45 20% 92%;
  --destructive: 355 75% 68%;
  --destructive-foreground: 40 14% 8%;
  --border: 40 16% 22%;
  --input: 40 16% 26%;
  --ring: 40 60% 68%;
}
```

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
          subtle: "var(--qayd-accent-subtle)",
          DEFAULT: "var(--qayd-accent)",
          strong: "var(--qayd-accent-strong)",
          on: "var(--qayd-accent-on)",
        },
        positive: { DEFAULT: "var(--qayd-positive)", subtle: "var(--qayd-positive-subtle)" },
        negative: { DEFAULT: "var(--qayd-negative)", subtle: "var(--qayd-negative-subtle)" },
        warning: { DEFAULT: "var(--qayd-warning)", subtle: "var(--qayd-warning-subtle)" },
        // shadcn semantic aliases
        background: "hsl(var(--background))",
        foreground: "hsl(var(--foreground))",
        primary: { DEFAULT: "hsl(var(--primary))", foreground: "hsl(var(--primary-foreground))" },
        secondary: { DEFAULT: "hsl(var(--secondary))", foreground: "hsl(var(--secondary-foreground))" },
        muted: { DEFAULT: "hsl(var(--muted))", foreground: "hsl(var(--muted-foreground))" },
        destructive: { DEFAULT: "hsl(var(--destructive))", foreground: "hsl(var(--destructive-foreground))" },
        border: "hsl(var(--border))",
        input: "hsl(var(--input))",
        ring: "hsl(var(--ring))",
      },
      borderRadius: {
        sm: "var(--radius-sm)", md: "var(--radius-md)",
        lg: "var(--radius-lg)", xl: "var(--radius-xl)",
      },
      fontFamily: {
        display: ["var(--font-display)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
        text: ["var(--font-text)", "var(--font-arabic)", "ui-sans-serif", "system-ui"],
        arabic: ["var(--font-arabic)", "ui-sans-serif", "system-ui"],
        mono: ["var(--font-mono)", "ui-monospace", "SFMono-Regular", "monospace"],
      },
      screens: { "3xl": "1920px" },
    },
  },
} satisfies Config;
```

```ts
// lib/tokens.ts — plain JS/TS values for Framer Motion and canvas/SVG chart code
export const tokens = {
  ink: {
    1: "#FAFAF9", 2: "#F4F3F1", 3: "#EBE9E6", 4: "#E0DEDA", 5: "#D3D0CA", 6: "#C2BEB6",
    7: "#A9A49A", 8: "#878174", 9: "#645F53", 10: "#4A4639", 11: "#2B2820", 12: "#15130E",
  },
  inkDark: {
    1: "#14130F", 2: "#1B1914", 3: "#24211A", 4: "#2D2921", 5: "#383327", 6: "#46402F",
    7: "#58503A", 8: "#786F55", 9: "#A39878", 10: "#C2B99C", 11: "#E4DEC9", 12: "#F8F6EF",
  },
  accent: { subtle: "#EADFBF", default: "#9C7A34", strong: "#7A5D22", on: "#15130E" },
  accentDark: { subtle: "#3A2E14", default: "#D9B96C", strong: "#E8CE8E", on: "#14130F" },
  semantic: {
    positive: "#17794A", negative: "#B4232E", warning: "#B45309",
  },
  motion: {
    duration: { instant: 0, micro: 0.12, fast: 0.16, base: 0.2, moderate: 0.28, slow: 0.36 },
    easeOut: [0.16, 1, 0.3, 1],
    easeInOut: [0.65, 0, 0.35, 1],
    easeIn: [0.7, 0, 0.84, 0],
  },
} as const;
```

# End of Document
