# Light Mode — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: LIGHT_MODE
---

# Purpose

This document specifies QAYD's **light theme** as a first-class, deliberately authored design — the default
theme every new user sees when their OS is in light mode, and the theme the whole editorial system was
drawn in first. It is the intentional peer of [`./DARK_MODE.md`](./DARK_MODE.md): every state that document
specifies in dark has its counterpart specified here in light, and the two are held symmetrical so that
"light↔dark parity" is a guarantee this doc can point at, not a hope.

Light mode is easy to under-specify precisely because it is the default — a team writes a careful dark-mode
spec to tame the harder theme and lets light "just be the base," which is how a light theme quietly accretes
inconsistencies (three different card backgrounds, borders that do no work, a shadow ramp nobody tuned).
This document refuses that asymmetry: light is authored with the same rigor as dark, from the same rules.

Read alongside:

- [`./THEMING.md`](./THEMING.md) — the pipeline light plugs into (theme = a one-block semantic remap), the
  provider, the parity guarantee, and white-label.
- [`./DARK_MODE.md`](./DARK_MODE.md) — the peer theme; the two docs share structure section-for-section.
- [`./COLOR_SYSTEM.md`](./COLOR_SYSTEM.md) and [`./DESIGN_TOKENS.md`](./DESIGN_TOKENS.md) — the **canonical
  light values** for this module (the light column of the `--qayd-ink-1…12`, accent, and semantic tables,
  plus the shadcn `:root` alias block), mirrored from
  [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md); [`./DESIGN_PRINCIPLES.md`](./DESIGN_PRINCIPLES.md)
  states the rationale (ink-before-color, one brass accent, numbers as the hero). This doc states the *rules*
  those values obey. The surface/ink/accent table below matches `COLOR_SYSTEM.md` → *Light ↔ Dark Remap*
  (light column) row-for-row.
- [`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) — WCAG 2.2 AA, re-verified independently in
  light.

Binding constraints, identical in weight to dark's:

- **AA is the floor in light too.** A pairing is never assumed to pass on white without measurement.
- **Ink before color.** The interface must read correctly and hierarchy-correct with the accent temporarily
  removed; if half a screen is tinted to read, the hierarchy is being done by color, which is a defect.
- **One accent, spent deliberately.** Brass marks the single primary action and anything the AI layer
  touched — nothing else.
- **RTL and light are orthogonal** — LTR/light and RTL/light are both built and tested.
- **No color-only signals.** A financial sign is always carried by a `+/−` or parentheses (and, in rendered
  statements, by parentheses specifically), never by hue alone.

# Light Palette Derivation & Rationale

QAYD's light palette is a near-monochrome editorial base: a twelve-step **warm-neutral** ink scale, one
brass accent spent narrowly, and three financial-semantic colors that never bleed into brand usage. The
choices, and the rules a new light value must obey:

**Rule 1 — Warm-neutral, not cool blue-black.** The ink scale carries a few points of yellow hue at low
saturation, so the canvas reads as *paper and ink* — the product's own metaphor (قيد, a journal entry) —
where the cool slate-blue most AI/SaaS dashboards default to reads as "generic AI tool." The warmth is
subtle: never enough to look beige or sepia. This same warmth carries into dark (the two themes are one
family, not two moods), which is why [`./DARK_MODE.md`](./DARK_MODE.md) Rule 1 preserves it.

**Rule 2 — Radix-style twelve-step usage convention.** Steps 1–2 back the canvas/sidebar, 3–5 fill
components (card / hover / selected), 6–8 draw borders and disabled states, 9–10 carry icons and secondary
text, 11–12 carry high-emphasis text. An engineer who knows that convention from shadcn/ui picks up QAYD's
scale with zero ramp-up, and every semantic role below maps onto a predictable step.

**Rule 3 — Ink before color.** Color is added only where it earns its place: one accent, three semantic
states, nothing else. There is **no `info` hue** — a plain notice is styled with the ink scale alone
(`ink-11` text on `ink-3` with an `ink-6` border), because introducing blue "for info" is how a product
quietly ends up with two brand colors. (Identical rule in [`./DARK_MODE.md`](./DARK_MODE.md).)

**Rule 4 — Brass accent, `accent-on` is near-black.** The accent is a restrained brass (`#9C7A34`), not the
bright indigo/violet that now reads as the default "AI startup" signal. A mid-value brass does not clear
4.5:1 against *white* text (~3.2:1 — large-scale only), so every filled `accent` surface pairs with a
**near-black** `accent-on` (`ink-12`, ~5.5:1) — which is also the truer material read: engraved brass is a
dark mark on a gold ground, not a white one. Accent drawn *as text/icon* on an `ink-1`/`ink-2` background
(a link, an active nav icon) uses `accent` directly and clears 4.5:1.

**Rule 5 — Semantic colors are financial-state, never brand-state.** `positive` (`#17794A`), `negative`
(`#B4232E`), `warning` (`#B45309`) each get a light value that clears AA against its own subtle wash, kept
visually distinct from brass (warning is more orange, higher chroma, so it never reads as the accent).

**Rule 6 — Elevation is primarily shadow in light.** Because light surfaces cluster near the top of the ramp
(`ink-1`…`ink-3`, little room to go lighter to signal "on top"), elevation is read from a soft, tuned shadow
ramp plus a hairline border — the inverse of dark's lightness-stepping. See Elevation & Borders.

# Surface / Ink / Accent in Light

The applied semantic mapping (mechanics in [`./THEMING.md`](./THEMING.md) → *Theme = Remap*). Values are the
canonical light column of [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md); shown here as
the mapping, with that doc remaining the source if a value is edited. The dark column of each row is in
[`./DARK_MODE.md`](./DARK_MODE.md) → *Per-Token Light → Dark Remap* — the two tables are deliberately the
same rows in the same order, so parity is checkable at a glance.

| Semantic role | Light primitive | Usage | Rule |
|---|---|---|---|
| `--color-bg-canvas` | `ink-1` `#FAFAF9` | App canvas | R1 warm paper-white |
| `--color-bg-sidebar` | `ink-2` `#F4F3F1` | Sidebar, subtle background | R2 |
| `--color-bg-surface` (card) | `ink-2/3` `#F4F3F1`/`#EBE9E6` | Cards, panels | R2, R6 (shadow lifts it, not lightness) |
| `--color-bg-hover` | `ink-4` `#E0DEDA` | Row/control hover | R2 |
| `--color-bg-selected` | `ink-5` `#D3D0CA` | Selected/active fill | R2 |
| `--color-border-subtle` | `ink-6` `#C2BEB6` | Hairline divider, default border | R6 — the workhorse |
| `--color-border-strong` | `ink-7` `#A9A49A` | Input border, stronger divider | R6 load-bearing |
| `--color-fg-muted` (disabled) | `ink-8` `#878174` | Disabled text/icon, placeholder | R2 |
| `--color-fg-secondary` | `ink-9` `#645F53` | Secondary text, default icon | R2 |
| `--color-fg-primary` | `ink-11/12` `#2B2820`/`#15130E` | Headings, high-emphasis / display text | R2 |
| `--color-accent` | `#9C7A34` | Primary action, focus ring, active tab, links, AI-touched | R4 brass |
| `--color-accent-strong` | `#7A5D22` | Hover/pressed of the accent | R4 |
| `--color-accent-subtle` | `#EADFBF` | Selected-row tint, AI-proposal card background | R4 |
| `--color-accent-on` | `ink-12` `#15130E` | Text/icon on an `accent` fill | R4 near-black, not white |
| `--color-positive` | `#17794A` | Increase, gain, reconciled | R5 |
| `--color-positive-subtle` | `#E3F3EA` | Positive background wash | R5 |
| `--color-negative` | `#B4232E` | Decrease, loss, overdue, destructive | R5 |
| `--color-negative-subtle` | `#FBE7E8` | Negative background wash | R5 |
| `--color-warning` | `#B45309` | Pending, unreconciled, nearing a limit | R5 distinct from brass |

**The accent's double duty in light.** By rule, `accent` / `accent-subtle` appear in exactly two contexts:
the single primary action per screen, and anything the AI layer produced (a proposed journal-entry card, a
confidence affordance, the assistant's mark, an anomaly-flagged row's gutter dot). A user learns over time
that "brass on this screen" always means either "the button to press" or "something AI touched, not
committed" — never a generic highlight. A screen with two saturated brass elements competing for attention
has a hierarchy bug, not a token bug.

**The debit/credit rule (a color rule as much as a numeral rule).** `positive`/`negative` are **never**
applied to a raw debit or credit amount — debit-normal and credit-normal are structural properties of an
account type, not judgments of good/bad (revenue postings are credits and are good news). Journal Entry
Lines, General Ledger, and Trial Balance render Debit and Credit columns in plain `ink-12` tabular numerals,
distinguished by column position alone. Color is reserved for the layer above the raw ledger — a genuinely
signed, net metric (Net Income, a variance, a period delta) — and even there QAYD colors a small delta
annotation (`+2.4%` chip, up/down glyph) rather than washing the whole numeral, so the number stays legible
in neutral ink first.

# Contrast & AA in Light

Every pairing is checked against WCAG 2.2 AA **independently in light** — the CI `check-contrast.ts` (see
[`./THEMING.md`](./THEMING.md) → Governance) runs both themes; this is light's half of that table, the
mirror of [`./DARK_MODE.md`](./DARK_MODE.md) → *Contrast Re-Verification*.

| Pairing | Light ratio | AA target | Result |
|---|---|---|---|
| `fg-primary` (`ink-11/12`) on `bg-canvas` (`ink-1/2`) | ~13:1 | 4.5:1 | Pass (generous) |
| `fg-secondary` (`ink-9`) on `bg-canvas` | ~5.1:1 | 4.5:1 | Pass |
| `accent-on` (near-black) on `accent` fill | ~5.5:1 | 4.5:1 | Pass — R4 is what holds this |
| `accent` text on `ink-1`/`ink-2` | ≥4.5:1 | 4.5:1 | Pass |
| white on `negative` fill (destructive button) | ~5.8:1 | 4.5:1 | Pass |
| `positive` on `positive-subtle` | ≥4.5:1 | 4.5:1 | Pass |
| `negative` on `negative-subtle` | ≥4.5:1 | 4.5:1 | Pass |
| `warning` on `warning-subtle` | ≥4.5:1 | 4.5:1 | Pass |
| disabled text (`fg-muted` `ink-8`) on `ink-3` | ~2.9–3.1:1 | 3:1 (disabled exempt) | Accepted — reinforced by cursor + tooltip |
| Focus ring (`accent`) on every surface step | ≥3:1 | 3:1 (1.4.11) | Pass at every step |

Disabled controls are the one place a slightly-lower ratio is accepted, and only because a disabled
control's meaning is reinforced by `cursor-not-allowed`, `aria-disabled`, and a required tooltip. A
permission-gated control that is disabled rather than hidden **must** carry a tooltip naming the permission
(e.g. "Requires `accounting.journal.post`") — QAYD never leaves a user staring at a grayed-out button with
no explanation. This mirrors dark's disabled-text allowance exactly, so neither theme is stricter than the
other on the same control.

# Elevation & Borders in Light

In light mode, **borders do more work than shadows** — an editorial-flat approach closer to Linear/Stripe
than to Material's stacked-shadow depth. Shadows exist but are quiet enough that removing them would not
break the hierarchy on their own; the hairline border does the primary structural work, and shadow adds
only the small "this floats" hint that a light-clustered ramp cannot express through lightness.

| Surface level | Background | Border | Shadow | Usage |
|---|---|---|---|---|
| `surface-0` | `ink-1` | — | none | App canvas |
| `surface-1` | `ink-2`/`ink-3` | 1px `ink-6` | `shadow-xs` | Cards, panels, sidebar |
| `surface-2` | `ink-1` | 1px `ink-6` | `shadow-sm` | Dropdowns, popovers, date pickers |
| `surface-3` | `ink-1` | 1px `ink-6` | `shadow-lg` | Modals/dialogs (with `ink-12`/50% scrim) |
| `surface-glass` | `ink-1` @ 72% + `backdrop-blur(24px)` | 1px `ink-6` @ 60% | `shadow-md` | ⌘K palette + AI Command Center **only** |

```css
/* light shadow ramp — soft, low-opacity, warm-tinted; halved & re-based on black under .dark */
--shadow-xs: 0 1px 2px  rgba(21, 19, 14, 0.04);
--shadow-sm: 0 2px 4px  rgba(21, 19, 14, 0.06);
--shadow-md: 0 8px 16px -4px rgba(21, 19, 14, 0.08);
--shadow-lg: 0 24px 48px -12px rgba(21, 19, 14, 0.16);
```

The shadow tint is warm (`rgba(21,19,14,…)`, ink-12-based) rather than neutral black, so it belongs to the
same paper-and-ink family as the surfaces it sits under. `surface-glass` is intentionally rare in light for
the same reason it is in dark: frosted glass reads premium only when unusual, and `backdrop-blur` is not
free on back-office desktops.

**Corner radius is a ceiling, not a suggestion** — `sm` 4px / `md` 6px / `lg` 8px (the workhorse) / `xl`
10px outer-modal-only / `full` for pills and dots only. A 10px maximum on any rectangular surface (well
under the 20–24px a generic rounded-SaaS starting point uses) is what reads as engineered and precise rather
than consumer-playful — a Bentley console does not have rounded-off corners, and neither should a Trial
Balance. Radius is theme-invariant: it is *not* remapped between light and dark (it is not a color), which is
why [`./THEMING.md`](./THEMING.md) marks the radius scale as structural / non-overridable.

```tsx
// components/ui/card.tsx — light picks shadow as its depth cue; the same class list is dark-correct
<div className="rounded-lg border border-border-subtle bg-surface p-6 shadow-xs dark:shadow-none">
  <h3 className="font-display text-display-sm text-foreground">Cash Position</h3>
  <p className="font-display numeral-hero tabular-nums text-foreground">KD 84,210.500</p>
  <p className="mt-1 text-sm text-positive">+2.4% vs last month</p>
</div>
```

# Charts & AI Affordances in Light

## Charts and data viz
- Chart color draws from the ink scale + the accent + the three semantic colors only — never an arbitrary
  categorical rainbow. A two-series comparison (actual vs. budget) uses `accent` against `ink-7`; a signed
  metric (cash in vs. out) uses `positive`/`negative`; a breakdown needing more than three series varies
  **value** (light-to-dark tints of the accent) before reaching for a second hue, then falls back to direct
  data labels over a legend.
- **Financial charts map to semantic tokens**, so a cash-flow waterfall or a budget-vs-actual chart shares
  its color vocabulary with every badge and card on the same screen — the same rule dark applies, so the two
  themes tell the same visual story.
- Charts read resolved CSS custom properties through the `useChartTokens` hook and re-render on theme change
  (Tailwind cannot reach an SVG `fill`); light is simply the mount-time default, not a special case. Tooltip
  `contentStyle` is overridden to `surface`/`border`/`fg` tokens in light exactly as in dark, so neither
  theme shows a library-default tooltip that fights the page.
- Empty-state art is line-only SVG using `currentColor` (inheriting `ink-7`), so it needs **no** per-theme
  asset — the same file is correct in light and dark by color inheritance. Any second tone uses
  `accent-subtle`, never a hardcoded raster color.

## AI-confidence affordances
AI provenance is the accent, kept distinct from financial outcome, in light exactly as in dark. A proposed
or flagged row carries a single 6px `accent` gutter dot (never a colored row background that would fight
selection/hover fills); an AI-proposal card uses an `accent-subtle` background; the assistant is a small
geometric mark in `accent` on `ink-2`, never a face or emoji. A confidence score is available on hover as a
tooltip (`"92% confidence — Accountant Agent"`), not a permanent percentage competing with the financial
figures. A card that also carries a finance status shows both facts side by side — a `border-negative` edge
+ `negative` chip for a held payment, an `accent` confidence affordance inside — mirroring
[`./DARK_MODE.md`](./DARK_MODE.md) → *AI-confidence affordances* token-for-token, only with the light
values.

# Light↔Dark Parity Guarantee

Light is authored so that **every state specified in one theme is specified in the other** — this is the
guarantee that keeps light from being a vaguer, less-tuned base than dark. How it is held:

1. **Same rows, both docs.** [`./DARK_MODE.md`](./DARK_MODE.md) → *Per-Token Light → Dark Remap* and this
   doc → *Surface / Ink / Accent in Light* enumerate the **same semantic roles in the same order**. A role
   present in one table and absent from the other is a parity break a reviewer can spot without a tool.
2. **One remap, not two palettes.** A theme is a single re-point of the semantic tier
   ([`./THEMING.md`](./THEMING.md)); a component is authored once and is correct in both themes by
   construction, so there is no "light version" and "dark version" of a component to drift apart.
3. **Contrast checked in both.** `check-contrast.ts` runs light *and* dark on every PR; the two contrast
   tables above and in the dark doc are the two halves of one guard, not a light table with a dark
   afterthought.
4. **Four-combination visual regression.** Every component story is snapshotted across
   `{ltr,rtl} × {light,dark}`; a new component gets its light *and* dark snapshots the first time it lands,
   with no extra authoring per theme — so a light-only regression and a dark-only regression are caught by
   the same mechanism.
5. **`axe` parametrized by theme.** The accessibility pass runs both `resolvedTheme` values, so a light-only
   a11y regression cannot hide behind a dark-passing run any more than the reverse can.
6. **State-table symmetry.** Every screen doc's `loading / empty / error / RTL / dark` table specifies the
   light *and* dark rendering of each state; light is a named column, never an unstated default.

The parity guarantee is **not** "light and dark look identical" — they deliberately do not (light reads
elevation from shadow, dark from lightness; each has purpose-picked accent and semantic steps). It is that
both are *specified, measured, and tested to the same standard* — neither is the real theme with the other
bolted on.

# Testing

Light is verified as a peer, not assumed as the base:

- **Four-combination visual regression** — `{ltr,rtl} × {light,dark}`; light is one of the four snapshotted
  combinations, blocking a PR on a diff exactly like dark does.
- **`axe-core` in light and dark** — both run in CI; a light-only contrast or landmark regression fails the
  build.
- **Token-drift lint** — `check-no-raw-colors.sh` catches a hardcoded `bg-white`/hex in a light-authored
  component exactly as it catches a dark one.
- **200%-zoom / reflow pass** — light is the theme most reading happens in; the no-horizontal-scroll-below-
  320px reflow check (WCAG 1.4.10) and the 24×24 target-size floor are verified in light as the primary pass
  and re-checked in dark.
- **Print pass** — `@media print` forces this light, ink-only palette (no accent tints, no shadows) so a
  Trial Balance reads on a black-and-white printer; the print stylesheet is effectively "light mode, further
  stripped," and is verified against a rendered statement.

# Edge Cases

- **Light is the no-JS / no-cookie fallback.** A first-ever visitor with JS blocked, or one whose `system`
  preference the server can't resolve, defaults to light via the pure-CSS layer — so light must be correct
  with zero client runtime, which it is (no light state depends on `useTheme`).
- **Forced-colors / high-contrast** — under `forced-colors: active`, QAYD defers to system colors for
  borders/focus/disabled in light exactly as in dark; it does not fight a user's OS high-contrast choice
  with its own warm palette.
- **`prefers-contrast: more`** — a progressive enhancement may strengthen light's `border-subtle` toward
  `border-strong` for users who ask for more separation, layered on top of the AA baseline, never replacing
  it — the mirror of the same dark enhancement.
- **Exported PDFs and print are always this palette** regardless of the user's in-app theme — a dark-mode
  user exporting a statement still gets the light, print-tuned rendering, because the recipient (an auditor,
  a bank) has no theme context and the document is frequently printed.
- **Photographic/OCR content is never recolored** — a bright white receipt photo inside a light card is
  simply correct; the frame is themed, the content is pixel-faithful, identical to the dark rule so behavior
  matches across themes.
- **Warm-white is deliberate, not a miscalibration.** `ink-1` is `#FAFAF9`, a warm off-white, not pure
  `#FFFFFF` — a QA note, because a reviewer expecting a cool pure-white canvas might file the intended warmth
  as a bug; it is the light-side expression of the same warm-neutral family that gives dark its warm
  near-black.

# End of Document
