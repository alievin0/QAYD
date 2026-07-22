# Card — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / CARD
---

# Purpose

This is the atomic design-system specification for QAYD's **Card** — the `Card` primitive and its variant
atoms (`static`, `clickable`/`interactive`, `KpiTile`, `StatCard`) expressed purely in tokens: which token
paints the surface, border, radius, and elevation; how the interactive variant adds a hover lift and a
focus ring; and how the card's states (loaded, loading, empty, error) map to shared skeleton/empty/error
atoms. It is the design-system counterpart to the application-level card family in
[`../../frontend/components/CARDS.md`](../../frontend/components/CARDS.md): that document owns the
*composed finance cards* — `AIProposalPanel`, `ApprovalCard`, `InsightCard` — their AI confidence/reasoning
contract, the approve/reject/delegate action flow, card grids and responsive reflow, and the "AI is
visible, never silent" rule. This document does not restate any of that. It owns the **atomic surface
contract** every one of those composed cards is built on.

The binding rule from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) governs every line: **a card references a
token, never a literal.** A card is a hairline-bordered, tightly-cornered, editorial surface — not a
heavy-shadowed, over-rounded, loudly-tinted consumer tile. A dashboard where every card is a different color
"for visual interest" is a defect, not a style. The ink scale, accent, and semantic palette this document
draws on are defined in [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); the composed-card signatures
(`KpiTile`, `AIProposalPanel`, `ApprovalCard`) are in
[`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md).

> Token-naming note. Application snippets use an earlier numeric-step palette (`ink-150`, `accent-100`,
> `accent-600`, `bg-surface`, `text-title`). Per [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md),
> the **canonical** names used here are `ink-1 … ink-12`, `accent`/`accent-subtle`/`accent-strong`/
> `accent-on`, and the `display-*`/`text-*`/`numeral-*` type steps. Read a draft name as its canonical
> equivalent (`ink-150` → `ink-6`, `accent-100` → `accent-subtle`, `accent-600` → `accent`, `bg-surface`
> → `ink-3`, `text-title` → `display-md`).

# Anatomy

A card is a token-styled surface with consistently-ordered optional regions, so two cards never disagree on
where the title, number, or actions sit. Not every card uses every region — a `KpiTile` has no footer, an
`InsightCard` has no hero number — but the *order* is fixed, which is what makes a wall of cards read as one
system.

```
Card (surface-1: bg-ink-3 · 1px ink-6 · radius-lg · shadow-xs)
├── Header ── label (text-xs ink-9) · title (display-md ink-11)      [meta: badge]
├── Body ──── hero value / statement / definition list (the one thing the card is about)
│              └── numeral-hero (KpiTile) · tabular-nums · dir="ltr" · ink-12
├── Detail ── delta chip (positive/negative) · sparkline · reasoning (collapsible)
└── Footer ── actions, logical-end aligned · primary action at the end
```

Cards obey the **editorial-flat** elevation model of
[`../DESIGN_TOKENS.md → Elevation & Surfaces`](../DESIGN_TOKENS.md): a card is a `bg-ink-3` panel on a 1px
`ink-6` hairline with `shadow-xs` — the border does the structural work; the shadow is quiet enough that
removing it would not break the hierarchy. Corner radius is `radius-lg` (8px), a deliberate ceiling well
under the "friendly" 20–24px the platform rejects; `radius-full` appears only on the pills and dots
*inside* a card (`StatusPill`, the AI dot), never on the card itself. The frosted `surface-glass` treatment
is reserved for the command palette and AI Command Center overlay only — never an ordinary card.

# Variants

The card family is deliberately small. Every card on a QAYD screen is one of four composed families
(catalogued in the app doc), each built from **two** primitive variant axes this spec owns: `padding` and
`interactive`.

| Primitive axis | Values | Token effect |
|---|---|---|
| `padding` | `none` / `sm` / `md` / `lg` | `p-0` / `--space-sm` (12px) / `--space-md` (16px) / `--space-lg` (24px) |
| `interactive` | `false` / `true` | `true` adds `shadow-md` on hover, `cursor-pointer`, and the 2px `accent` focus ring |

The four composed families and their atomic mapping:

| Family | Component(s) | Surface treatment |
|---|---|---|
| **Stat** | `KpiTile`, `StatCard` | Plain `bg-ink-3`; hero number `numeral-hero`; AI-computed → `ConfidenceBadge` |
| **Insight / Recommendation** | `InsightCard`, `AIProposalPanel` | `accent-subtle` tint — the learned "this came from the AI layer" signal |
| **Approval** | `ApprovalCard` | Plain `bg-ink-3`; AI-source → `ConfidenceBadge`; every reject carries a mandatory reason |
| **List-item** | `DataCardList` row | Plain `bg-ink-3`; inherits the row's AI-provenance dot from the table column model |

## The `Card` primitive (tokens only)

```tsx
// components/ui/card.tsx — shadcn/ui card re-skinned to QAYD tokens. Markup + tokens only.
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

export const cardVariants = cva(
  // radius-lg (8px), 1px hairline, editorial-flat elevation, no heavy shadow.
  'rounded-lg border border-ink-6 bg-ink-3 text-ink-11 shadow-xs',
  {
    variants: {
      padding: { none: 'p-0', sm: 'p-3', md: 'p-4', lg: 'p-6' },
      interactive: {
        // A clickable card lifts on hover and shows the accent focus ring; the whole surface activates.
        true:
          'transition-shadow hover:shadow-md cursor-pointer ' +
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
        false: '',
      },
    },
    defaultVariants: { padding: 'md', interactive: false },
  },
);

export function Card({
  padding, interactive, className, ...props
}: React.ComponentProps<'div'> & VariantProps<typeof cardVariants>) {
  return <div className={cn(cardVariants({ padding, interactive }), className)} {...props} />;
}
```

The primitive is markup and tokens only — it knows nothing about KPIs, approvals, or confidence. A
permission check, a confidence score, or an audit side-effect always lives in a wrapping finance component
(`ApprovalCard` wraps `Card` + `Button` + `AlertDialog`; it never teaches `Card` about approvals), per the
composition-over-modification rule in the app doc.

## `StatCard` — the reference stat atom

The general stat card for statement summary strips (Total Debit, Variance, Net Income). It owns no data
fetching and computes nothing — it formats a server value and frames it with tokens.

```tsx
// components/dashboard/stat-card.tsx
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { formatAmount } from '@/components/accounting/amount-cell';
import { cn } from '@/lib/utils';

export function StatCard({ label, value, format, currencyCode, emphasis = 'default', tone = 'neutral', hint, loading }: StatCardProps) {
  return (
    // tone="accent" ONLY when the tile is itself the AI's output — never decoratively.
    <Card padding="md" className={cn('space-y-1', tone === 'accent' && 'border-accent-subtle bg-accent-subtle/40')}>
      <p className="text-xs font-medium uppercase tracking-wide text-ink-9">{label}</p>
      {loading ? (
        <Skeleton className="h-7 w-28 bg-ink-4" />
      ) : (
        <p
          className={cn('font-display tabular-nums text-ink-12', emphasis === 'strong' ? 'text-[length:var(--display-lg)]' : 'text-[length:var(--display-md)]')}
          dir="ltr"
        >
          {value == null ? '—' : format === 'currency' ? formatAmount(value, currencyCode!) : value}
          {value != null && format === 'percent' && '%'}
        </p>
      )}
      {hint && <p className="text-xs text-ink-9">{hint}</p>}
    </Card>
  );
}
```

Two non-negotiables the primitive alone would not give you: a `null` value renders an em dash, **never**
`0` (absence of data is not a zero balance), and the number is `font-display tabular-nums dir="ltr"` so it
holds Latin numerals in an Arabic layout.

# Props / API

The composed-card prop surfaces (`KpiTile`, `AIProposalPanel`, `ApprovalCard`, `InsightCard`, `StatCard`)
are owned by [`../../frontend/components/CARDS.md → Props / API`](../../frontend/components/CARDS.md) and are
not duplicated here. This section fixes only the props whose values are **token decisions**.

## `Card` primitive

| Prop | Type | Default | Token effect |
|---|---|---|---|
| `padding` | `'none' \| 'sm' \| 'md' \| 'lg'` | `'md'` | `p-0` / 12px / 16px / 24px from the spacing scale |
| `interactive` | `boolean` | `false` | `true` → hover `shadow-md`, `cursor-pointer`, 2px `accent` focus ring, keyboard-activable |

## Token-bearing stat props

| Prop | Applies to | Token effect |
|---|---|---|
| `emphasis` | `StatCard` | `'strong'` → grand-total tile in `display-lg`, else `display-md` |
| `tone` | `StatCard` | `'accent'` → `accent-subtle` tint + border; **only** when the tile is the AI's output |
| `delta` | `KpiTile` | Colors the small delta **chip** `positive`/`negative` — never the hero number |
| `value == null` | any stat | Renders an em dash `—`, never `0` |

# States

Every card family has a designed, token-defined rendering for each state — never a raw blank or a spinner
where content of known shape belongs. The card decides *which* state and delegates to the shared
`Skeleton`/`EmptyState`/`ErrorState` atoms (also shared with [`./TABLE.md`](./TABLE.md)); the behavioral
detail (AI-unavailable backoff, optimistic rollback) is the app doc's.

| State | Token treatment |
|---|---|
| **Loading** | A `Skeleton` (`bg-ink-4`, `motion.fast` shimmer) shaped to the body — a bar where the hero number goes — **inside** the real card border, so the grid never reflows when data arrives. Never a centered spinner. |
| **Loaded** | The full card: `bg-ink-3`, `ink-6` border, `shadow-xs`. |
| **Empty (stat)** | Label + em-dash `—` in place of the number + a muted `ink-9` hint ("No data for this period") — never a misleading `0`. |
| **Empty (feed)** | A single quiet `EmptyState` card ("You're all caught up") — not an invisible gap. |
| **Error (card-scoped)** | In-card `ErrorState`: message, request id in `code-sm ink-9`, Retry — contained to the one card; sibling cards stay live. |
| **AI unavailable** | A distinct "AI insights temporarily unavailable" state (not a generic error, not an infinite spinner). |
| **Busy (action pending)** | Action buttons disable and show the acting button's spinner **without changing width**; the card body stays readable. |
| **AI-tinted (provenance)** | `accent-subtle` background + the AI mark in `accent` — the learned "this came from the AI layer, not the committed record" signal. |
| **Selected / drill-hover** | Interactive card lifts `shadow-xs → shadow-md` at `motion.fast`; a realtime number tick flashes `accent-subtle` → transparent over 600ms, no toast, no layout shift. |

# Tokens Used

Every token this atom paints, drawn only from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md).

| Role | Token | Notes |
|---|---|---|
| Card surface | `ink-3` | The panel fill |
| Card border | 1px `ink-6` | Does the elevation work |
| Card radius | `radius-lg` (8px) | Ceiling well under 20–24px |
| Resting elevation | `shadow-xs` | Quiet; hierarchy holds without it |
| Interactive hover | `shadow-md` | `motion.fast` transition |
| Focus ring | 2px `accent` + 2px offset | Interactive cards only |
| Label | `text-xs` uppercase, `ink-9` | Card header label |
| Title | `display-md`, `ink-11` | Card / panel title |
| Hero number | `numeral-hero` / `display-md`–`display-lg`, `tabular-nums`, `dir="ltr"`, `ink-12` | Stat value |
| Body / secondary text | `text-sm`, `ink-9`–`ink-11` | |
| AI tint surface | `accent-subtle` | Insight/proposal background + `tone="accent"` stat |
| AI mark / active accent | `accent` | The one AI signature color |
| Delta chip up/down | `positive` / `negative` | The **chip** only, never the hero number |
| Skeleton fill | `ink-4` | `motion.fast` shimmer |
| Padding steps | `--space-sm` / `--space-md` / `--space-lg` | 12 / 16 / 24px |
| Grid gap | `--space-lg` (24px) | Restrained, never a gapless bento wall |
| Realtime tick flash | `accent-subtle` → transparent, 600ms | No toast |

# Accessibility

Inherits the WCAG 2.1 AA baseline; the card atom's non-negotiables (behavioral detail in
[`../../frontend/components/CARDS.md → Accessibility`](../../frontend/components/CARDS.md)):

- **Static cards are not tabbable.** A `KpiTile`/`StatCard` without `onClick` is a plain region — no false
  affordance, no hover lift, no pointer. A hover affordance on a thing that does nothing is a broken
  promise.
- **Interactive cards are real controls** with an accessible name (the title/label), keyboard activation
  (`Enter`/`Space`), and the visible 2px `accent` focus ring — never a `<div onClick>`. A card that
  contains its own inner buttons (an approval card) is `interactive={false}` with real inner controls;
  nesting a whole-card `onClick` around inner buttons is banned as un-announceable.
- **Delta direction is text.** "up 13.3% vs last month" is announced as text, not conveyed by arrow color
  alone; the delta's `+`/`−` sign is real text.
- **Contrast, verified both themes.** `ink-11`/`ink-12` on `ink-3` clears ~11:1; `ink-9` secondary on `ink-3`
  stays legible; the `accent` focus ring clears ≥3:1 at every surface step
  ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).
- **Card-scoped errors** render as a labelled region with the request id, so an assistive-tech user knows
  which card failed and how to retry, not a silent gap.
- **Reduced motion** collapses the grid stagger, the drill-in hover lift, and the realtime flash to instant
  state changes — the state still changes; only its animation is removed.

# Theming, Dark Mode & RTL

**Tokens only.** A card file contains no raw hex, no numbered legacy palette step, and no `dark:` variant
against a raw color. Surface is `bg-ink-3` on `border-ink-6` with `shadow-xs`; the AI tint is
`bg-accent-subtle` with the mark in `accent`; a delta chip is `text-positive`/`text-negative`.

**Dark mode is a pure remap** under `class="dark"`: the identical card markup renders correctly dark with no
card-level conditional. Per [`../COLOR_SYSTEM.md → Light ↔ Dark Remap`](../COLOR_SYSTEM.md), elevation gets
**lighter** — a raised `ink-3` card sits above the darker `ink-1` canvas — and the AI `accent-subtle` lifts
rather than saturates, so an AI-tinted card reads as gently distinct against the dark surface instead of
glowing. Shadows re-derive at ~50% alpha.

**RTL uses logical properties exclusively** — `ps-*`/`pe-*`, `text-start`/`text-end`, `ms-*`/`me-*`,
`inset-inline-*` — so a card's header meta, footer action order, and internal spacing mirror automatically
with `dir="rtl"` and zero conditional code:

- The footer's actions mirror as a group (the primary action sits at the logical end — visual left in
  Arabic); an approval card's Approve/Reject/Delegate order mirrors together.
- **Money values and currency codes never mirror.** Every hero number / `AmountCell` renders `dir="ltr"`
  with `numberingSystem: 'latn'`, so `84,210.500 KWD` reads left-to-right inside a right-to-left card.
- A directional glyph (a drill-in chevron) mirrors via `rtl:rotate-180`; a status dot, a checkmark, the AI
  mark, or a delta `+`/`−` sign never flips — flipping a sign would invert its meaning, not its layout.

# Do / Don't

| Do | Don't |
|---|---|
| Frame a card with a 1px `ink-6` hairline + `shadow-xs` | Use a heavy drop shadow or a neumorphic bevel |
| Cap corner radius at `radius-lg` (8px) | Round a card to a "friendly" 20–24px |
| Keep every card `bg-ink-3`; tint only AI cards `accent-subtle` | Give each card its own color "for visual interest" |
| Render `numeral-hero` numbers `dir="ltr" tabular-nums` | Format a hero number with a generic formatter |
| Show an em dash `—` for absent data | Present absence of data as a `0` |
| Color the delta **chip** `positive`/`negative` | Wash the whole hero number red/green |
| Make a static card a plain region (not tabbable) | Put a hover lift on a card that does nothing |
| Make an interactive card a real control with a focus ring | Wrap a `<div onClick>` around inner buttons |
| Reserve `surface-glass` for ⌘K / AI Command Center | Frost an ordinary dashboard card |
| Let dark mode remap tokens automatically | Add a `dark:` variant against a raw color |

# Usage & Composition

A dashboard is a restrained 1-to-3-column grid of cards with generous `--space-lg` gaps — never a dense,
gapless bento wall. Cards are equal-height by row so a short stat tile and a tall one align their tops and
bottoms; below `md:` a stat grid collapses to a single column before it compresses a number past legibility.

```tsx
// The canonical card grid — the primitive carries the surface, the composed cards carry the logic.
<div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
  <section className="grid grid-cols-2 gap-4 lg:col-span-2 lg:grid-cols-3">
    {kpis?.map((kpi) => <KpiTile key={kpi.key} {...kpi} />)}
  </section>
  <section className="space-y-3">
    <h2 className="font-display text-[length:var(--display-md)] text-ink-11">Recommendations</h2>
    {recommendations?.map((d) => (
      <AIProposalPanel key={d.id} decision={d} autonomyLevel={d.autonomyLevel} /* … */ />
    ))}
  </section>
</div>
```

**Composition boundaries.**

- The AI cards (`AIProposalPanel`, `InsightCard`, `ApprovalCard`) wrap the `Card` primitive at
  `interactive={false}` and own their own inner controls — the whole surface does nothing; only the inner
  buttons act. Their AI confidence/reasoning contract and the approve/reject/delegate flow are the app
  doc's.
- The list-item card (a `ResponsiveDataView` row below `md:`) is the mobile face of a
  [`./TABLE.md`](./TABLE.md) row — the same column model drives both, so the definition never drifts.
- The `Skeleton`/`EmptyState`/`ErrorState` atoms are shared with the table system; the card decides which
  state and hands off.

For the full composed-card contract — the AI confidence/reasoning atoms, the action flow, card grids, and
responsive reflow — see [`../../frontend/components/CARDS.md`](../../frontend/components/CARDS.md).

# End of Document
