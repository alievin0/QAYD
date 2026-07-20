# Cards — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / CARDS
---

# Purpose

The card is QAYD's second structural workhorse after the table. Where a table answers "show me every row,"
a card answers "here is one thing, framed." The Dashboard is a grid of cards; the AI Command Center is a
column of cards; a financial statement's summary strip, an approval queue, an insight feed, a settings
page — all cards. This document is the depth specification for that family: it takes the `Card` primitive and
the composed finance cards catalogued in [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) — `KpiTile`,
`ApprovalCard`, `AIProposalPanel` — and expands them into the complete contract for every card in the
application, plus the insight/recommendation card, the summary/stat cards, list-item cards, card grids and
their responsive reflow, the clickable-vs-static distinction, the skeleton/empty/error card states,
elevation and border tokens, and RTL.

Everything here inherits the platform constraints from [`../README.md`](../README.md) without re-deriving
them. Two matter most for cards. First, **AI is visible, never silent** (`../README.md → Overview`, point 2):
every AI-originated card carries its confidence score and its reasoning, and every AI-originated *action*
card renders an explicit approve/reject/delegate affordance — a card never auto-commits a proposal, it
commits a human's decision about one. Second, **design taste is a product requirement**
(`../DESIGN_LANGUAGE.md → Design principles`): a card is a hairline-bordered, tightly-cornered, editorial
surface — not a heavy-shadowed, over-rounded, loudly-tinted consumer tile. A dashboard where every card is a
different color "for visual interest" is a defect, not a style, in QAYD.

The card family is deliberately small. A card is one of: a **stat card** (a number and its context — `KpiTile`
and its statement-summary siblings), an **insight/recommendation card** (an AI observation, read-only or
action-shaped — `AIProposalPanel`), an **approval card** (the human gate — `ApprovalCard`), or a **list-item
card** (a document rendered as a card below `md:` — the card face of a `ResponsiveDataView` row, per
[`../RESPONSIVE_DESIGN.md`](../RESPONSIVE_DESIGN.md)). Everything on a QAYD screen that looks like a card is
one of those four, composed from the same `Card` primitive; there is no fifth ad-hoc card shape.

# Anatomy & Variants

## The `Card` primitive

`Card` (`components/ui/card.tsx`) is the shadcn/ui card re-skinned to QAYD tokens, extended with two props
QAYD relies on everywhere:

```tsx
// components/ui/card.tsx (excerpt)
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

export const cardVariants = cva(
  'rounded-lg border bg-surface text-ink-950', // radius-lg (10px), 1px border, no heavy shadow
  {
    variants: {
      padding: { none: 'p-0', sm: 'p-3', md: 'p-4', lg: 'p-6' },
      // Static cards sit on a hairline border with the softest elevation; interactive cards
      // add a hover lift + pointer, per DESIGN_LANGUAGE elevation rules.
      interactive: {
        true: 'border-ink-150 shadow-sm transition-shadow hover:shadow-md cursor-pointer ' +
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 focus-visible:ring-offset-2',
        false: 'border-ink-150 shadow-sm',
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

The primitive is markup and tokens only — it knows nothing about KPIs, approvals, or confidence. A permission
check, a confidence score, or an audit side-effect always lives in a wrapping finance component
(`ApprovalCard` wraps `Card` + `Button` + `AlertDialog`; it never teaches `Card` about approvals), per the
composition-over-modification rule in `../COMPONENT_LIBRARY.md → Foundations`.

## The four card families

| Family | Component(s) | Shape | AI contract |
|---|---|---|---|
| **Stat** | `KpiTile`, `StatCard`, statement-summary tiles | Label · hero number · delta · sparkline · optional target | If the number is AI-computed, carries a `ConfidenceBadge` (small, label-less) |
| **Insight / Recommendation** | `AIProposalPanel` (action-shaped), `InsightCard` (read-only) | Agent mark · statement · confidence · reasoning · alternatives · actions | Always: confidence + reasoning; action-shaped adds approve/reject/delegate |
| **Approval** | `ApprovalCard` | Title · amount · requester/agent · approval-level progress · confidence · approve/reject/delegate | Renders `ConfidenceBadge` when the source is AI; every rejection carries a mandatory reason |
| **List-item** | `DataCardList` row (card face of a `ResponsiveDataView`) | Document number + status headline · 2–4 priority fields · tap-through | Inherits the row's AI-provenance dot from the table column model |

## Card structure

A card is composed of optional, consistently-ordered regions so two cards never disagree on where the title,
the number, or the actions sit:

```
┌─ Card (interactive?) ───────────────────────────────┐
│  Header:  label / title            [meta: badge]     │  ← label = text-caption ink-500; title = font-display
│  Body:    hero value / statement / definition-list   │  ← the one thing the card is about
│  Detail:  delta · sparkline · reasoning (collapsible)│  ← secondary context, never louder than the body
│  Footer:  actions (approve/reject/dismiss/drill)     │  ← right-aligned; primary action rightmost
└──────────────────────────────────────────────────────┘
```

Not every card uses every region — a `KpiTile` has no footer; an `InsightCard` has no hero number — but the
*order* is fixed, which is what makes a wall of cards read as one system.

## Elevation & border tokens

Cards obey the editorial-flat elevation model of `../DESIGN_LANGUAGE.md → Elevation & Surfaces`: a card is a
`bg-surface` panel on a 1px `ink-150` hairline with `shadow-sm` — the border does the structural work, the
shadow is quiet enough that removing it would not break the hierarchy. Interactive cards add `shadow-md` on
hover and the accent focus ring. QAYD never uses heavy drop shadows, neumorphic bevels, or the frosted-glass
`surface-glass` treatment on an ordinary card — glass is reserved for the command palette and the AI Command
Center overlay only. Corner radius is `radius-lg` (10px) for cards, a deliberate ceiling well under the
"friendly" 20–24px the platform rejects; `radius-full` appears only on the pills and dots *inside* a card
(`StatusPill`, the AI dot), never on the card itself.

# Props / API

## `KpiTile`

The atomic dashboard stat, per `../COMPONENT_LIBRARY.md → KpiTile`, restated here as the reference stat card.

| Prop | Type | Required | Description |
|---|---|---|---|
| `label` | `string` | yes | e.g. `"Cash position"` — a translation key, `text-caption` `ink-500`. |
| `value` | `string` | yes | Pre-formatted, or a raw `NUMERIC` string per `format`. |
| `format` | `'currency' \| 'number' \| 'percent'` | yes | Drives formatting; `'currency'` routes the value through `formatAmount`. |
| `currencyCode` | `string` | conditional | Required when `format="currency"`. |
| `delta` | `{ value: number; direction: 'up' \| 'down'; period: string }` | no | The signed period-over-period change; colored `success`/`danger` on the small delta chip only, never the hero number. |
| `sparklineData` | `number[]` | no | Rendered via `TrendSparkline`. |
| `target` | `{ value: string; label?: string }` | no | An optional goal rendered as a thin progress track under the number (budget, forecast). |
| `confidence` | `number \| null` | no | 0–1; renders a small `ConfidenceBadge` when the number is AI-computed. |
| `loading` | `boolean` | no | Renders a `Skeleton` in place of value/delta. |
| `onClick` | `() => void` | no | Drill-in (e.g. into the filtered ledger behind the number); makes the card `interactive`. |

## `StatCard`

The general stat card for statement summary strips (Total Debit, Total Credit, Variance, Account Count above
a Trial Balance; Net Income, Gross Margin above a P&L) — a `KpiTile` without a mandatory delta or sparkline,
used where the number is the whole point and the trend lives elsewhere.

| Prop | Type | Required | Description |
|---|---|---|---|
| `label` | `string` | yes | |
| `value` | `string` | yes | |
| `format` | `'currency' \| 'number' \| 'percent'` | yes | |
| `currencyCode` | `string` | conditional | |
| `emphasis` | `'default' \| 'strong'` | no | `'strong'` for a grand-total tile. |
| `tone` | `'neutral' \| 'accent'` | no | `'accent'` only when the tile is itself the AI's output, never decoratively. |
| `hint` | `string` | no | A one-line caption under the number (a definition, an as-of date). |

`StatCard` is a small composition over the `Card` primitive — it owns no data fetching and computes nothing,
it formats a server value and frames it:

```tsx
// components/dashboard/stat-card.tsx
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { formatAmount } from '@/components/accounting/amount-cell';
import { cn } from '@/lib/utils';

export function StatCard({ label, value, format, currencyCode, emphasis = 'default', tone = 'neutral', hint, loading }: StatCardProps) {
  return (
    <Card padding="md" className={cn('space-y-1', tone === 'accent' && 'border-accent-100 bg-accent-100/40')}>
      <p className="text-caption font-medium text-ink-500">{label}</p>
      {loading ? (
        <Skeleton className="h-7 w-28" />
      ) : (
        <p className={cn('font-display tabular-nums text-ink-950', emphasis === 'strong' ? 'text-heading' : 'text-title')} dir="ltr">
          {value == null ? '—' : format === 'currency' ? formatAmount(value, currencyCode!) : value}
          {value != null && format === 'percent' && '%'}
        </p>
      )}
      {hint && <p className="text-caption text-ink-500">{hint}</p>}
    </Card>
  );
}
```

Note the two non-negotiables the primitive alone would not give you: a `null` value renders an em dash, never
`0` (absence of data is not a zero balance), and the number is `font-display tabular-nums dir="ltr"` so it
holds Latin numerals in an Arabic layout.

## `InsightCard`

A read-only AI observation from the Insights feed — the counterpart to the action-shaped `AIProposalPanel`.
An insight that graduates to carrying a `recommended_action` is rendered through `AIProposalPanel` instead,
per the field-presence split in `../AI_COMMAND_CENTER.md → AI Insights`.

| Prop | Type | Required | Description |
|---|---|---|---|
| `insight` | `AIInsight` | yes | `{ id, agentCode, title, body, confidenceScore, reasoning, sources, createdAt }`. |
| `onExplainMore` | `() => void` | no | Opens Ask AI pre-seeded with the insight's `sources` as context. |
| `onNotUseful` | `() => Promise<void>` | no | Optimistically removes the card with a 5-second Undo toast before committing. |

```tsx
// components/ai/insight-card.tsx
'use client';

import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { AgentAvatar } from '@/components/ai/agent-avatar';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { FormattedRelativeTime } from '@/components/shared/formatted-date';

export function InsightCard({ insight, onExplainMore, onNotUseful }: InsightCardProps) {
  const confidence = normalizeConfidence(insight.confidenceScore, 'percentage');
  return (
    // accent-tinted: the learned "this came from the AI layer, not the committed record" signal.
    <Card padding="md" className="space-y-2 border-accent-100 bg-accent-100/30">
      <div className="flex items-start gap-3">
        <AgentAvatar agentCode={insight.agentCode} />
        <div className="min-w-0 flex-1">
          <p className="font-medium text-ink-950">{insight.title}</p>
          <p className="text-body text-ink-700">{insight.body}</p>
          <div className="mt-1 flex items-center gap-2">
            <ConfidenceBadge confidence={confidence} size="sm" reasoning={insight.reasoning} />
            <span className="text-caption text-ink-500"><FormattedRelativeTime value={insight.createdAt} /></span>
          </div>
        </div>
      </div>
      <div className="flex justify-end gap-2">
        {onNotUseful && <Button variant="ghost" size="sm" onClick={onNotUseful}>Not useful</Button>}
        {onExplainMore && <Button variant="outline" size="sm" onClick={onExplainMore}>Explain more</Button>}
      </div>
    </Card>
  );
}
```

The insight is read-only — it has no approve/execute affordance, because it is an *observation*, not an
action. The moment an insight carries a `recommended_action` it is no longer an insight; it renders through
`AIProposalPanel` with the three-interaction action contract instead. That field-presence split is the whole
reason `InsightCard` and `AIProposalPanel` are two components rather than one with a mode flag.

## `ProposalCard` / `AIProposalPanel`

The full rendering of one `ai_decisions` row — an action-shaped recommendation — per
`../COMPONENT_LIBRARY.md → AIProposalPanel`. Restated here because it is the canonical AI *action* card and
the tightest expression of the confidence/reasoning contract.

| Prop | Type | Required | Description |
|---|---|---|---|
| `decision` | `AIDecision` | yes | `{ id, agentCode, confidenceScore, recommendedAction, alternatives: {action, tradeoff}[], reasoning, sources, autonomyLevel }`. |
| `autonomyLevel` | `'auto' \| 'suggest_only' \| 'requires_approval'` | yes | Determines whether the one-click "Do it" renders at all. |
| `onAccept` | `() => Promise<void>` | yes | Callable only when `autonomyLevel === 'auto'` **and** confidence clears the company threshold. |
| `onSendForApproval` | `() => Promise<void>` | yes | Opens the target action's `ApprovalCard` flow pre-attached to this decision. |
| `onDismiss` | `(reason: string) => Promise<void>` | yes | Reason mandatory; stored as `rejected_reason`, fed back into `ai_memory`. |

Every proposal carries at least one named alternative with its tradeoff, because "QAYD does not want users to
develop reflexive trust in a single accept button" (`../AI_COMMAND_CENTER.md → AI Recommendations`). Below 60%
confidence the "Do it" action is disabled regardless of configured autonomy — a low-confidence output may be
*shown* but can never be the thing that executes itself (`../COMPONENT_LIBRARY.md → ConfidenceBadge`).

## `ApprovalCard`

The single reusable human-gate card, per `../COMPONENT_LIBRARY.md → ApprovalCard` — one component across
journal entries, AI recommendations, bank transfers, payroll releases, tax submissions, discriminated by
`kind`. Its full prop table lives in the component library; the load-bearing contract for this document is:
`onReject` takes a **mandatory** reason (the dialog will not submit an empty one), `onDelegate` renders only
when the caller is the assigned approver for the current level, and `confidence` feeds a `ConfidenceBadge`
whenever the source is AI-originated.

## The AI card contract (shared)

Every AI-originated card — stat, insight, proposal, or approval — obeys the same three-part contract, which is
the visual realization of `../README.md → Overview` point 2 and `../DESIGN_LANGUAGE.md → Principle 7`:

1. **Confidence is always shown**, via `ConfidenceBadge` — never a bare number the AI computed without its
   score. Callers normalize the raw field through `normalizeConfidence(raw, sourceField)` first, because
   QAYD has two live confidence scales (`ai_decisions.confidence_score` is 0–100; `journal_lines.ai_confidence`
   is 0–1) — a wrong `sourceField` is the single most likely AI-card bug (`../COMPONENT_LIBRARY.md → Edge
   Cases`).
2. **Reasoning is always reachable**, via the badge's tooltip (`reasoning` on focus, not hover-only) or an
   expandable panel — "no card shows a bare number the AI computed without showing its work."
3. **Proposing language, never assertive.** An AI card says "Suggested," "Recommended," "Flagged" — never "I
   matched this," "Done," or anything implying a committed action, until a human has approved
   (`../DESIGN_LANGUAGE.md → Voice & Microcopy`). The card's accent tint (`accent-100` background, the AI mark
   in `accent-600`) is the learned signal "this came from the AI layer, not the committed record."

# States

Every card family has a designed rendering for each state — never a raw blank or a spinner where content of
known shape belongs.

| State | Rendering |
|---|---|
| **Loading** | A `Skeleton` shaped to the card's body — a bar where the hero number goes, a shorter bar for the delta — inside the real card border, so the grid does not reflow when data arrives. Never a centered spinner in a card. |
| **Loaded** | The full card. |
| **Empty (stat)** | A stat with no data yet renders the label, an em-dash `—` in place of the number, and a muted hint ("No data for this period") — never a misleading `0`, which is a real value. |
| **Empty (feed)** | A recommendation/insight column with nothing to show renders a single quiet `EmptyState` card ("You're all caught up — no recommendations right now"), not an invisible gap. |
| **Error (card-scoped)** | A card whose own query failed renders an in-card `ErrorState` (message + request id + Retry) — the failure is contained to the one card; sibling cards in the grid stay live. |
| **AI unavailable** | An AI card whose engine returned `503` renders a distinct "AI insights are temporarily unavailable" state (not a generic `ErrorState`, not an infinite spinner), backing off on the `Retry-After` header, per `../COMPONENT_LIBRARY.md → Edge Cases`. |
| **Busy (action pending)** | An approval/proposal card mid-mutation disables its action buttons and shows the acting button's `loading` spinner without changing its width; the card body stays readable. |
| **Optimistic → rolled back** | An optimistically-dismissed insight that fails to commit reappears with its Undo window; an approval that `409`s refetches and surfaces "this changed since you opened it" rather than a silent overwrite. |

The empty/loading/error handoff is delegated to `Skeleton`, `EmptyState`, and `ErrorState` — the card decides
*which* state it is in and hands off, exactly as the stat and feed compositions in
`../COMPONENT_LIBRARY.md` wire them.

# Behavior & Interaction

## Clickable vs. static cards

A card is either **static** (a stat you read) or **interactive** (a surface you activate) — and the two are
visually and semantically different, never conflated:

- A **static** card is a plain `<div>`, not tabbable, `interactive={false}`: a `KpiTile` with no `onClick`, a
  `StatCard`, an `InsightCard`'s body region. It has no hover lift and no pointer cursor, because a hover
  affordance on a thing that does nothing is a broken promise.
- An **interactive** card is one whose *whole surface* navigates or drills in: a `KpiTile` with `onClick` into
  the filtered ledger behind its number, a list-item card that opens a record. It is a real focusable control
  (`interactive={true}` renders the focus ring, hover `shadow-md`, and `cursor-pointer`; the whole card is
  keyboard-activable with `Enter`/`Space` and has an accessible name). Its `onClick` is always mirrored by a
  keyboard path — a card is never mouse-only.

A card that contains its *own* interactive controls (an `ApprovalCard`'s Approve/Reject buttons, an
`AIProposalPanel`'s "Do it") is **not** itself a clickable card — the whole surface does nothing; only the
inner buttons act. Nesting a whole-card `onClick` around inner buttons produces ambiguous, un-announceable
activation and is banned; those cards are `interactive={false}` with real inner controls.

## Drill-in

An interactive stat card's drill-in navigates to the filtered view *behind* the number — a Cash Position tile
opens `/accounting/cash-flow`, a Revenue MTD tile opens the ledger scoped to the revenue accounts and the
month — so the number and the rows it summarizes are always one click apart. The target is a real route (or a
`Sheet`), deep-linkable per the table system's `syncToUrl`.

## The AI action flow

An `AIProposalPanel`'s three interactions are exhaustive and consistent everywhere:

- **Do it** (only when `autonomyLevel === 'auto'` and confidence ≥ the company threshold) — executes the
  recommended action directly; the card animates to a quiet posted/checkmark confirmation
  (`../DESIGN_LANGUAGE.md → Named patterns → Success confirmation`), never confetti.
- **Send for approval** — opens the target action's `ApprovalCard` flow pre-attached to this decision, moving
  it into the human gate.
- **Dismiss** — requires a mandatory reason (stored as `rejected_reason`, fed to `ai_memory` so the model
  learns), then optimistically removes the card.

The expandable "N alternatives" region discloses each alternative with its tradeoff; expansion animates height
via Framer Motion, collapsing to an instant open/close under reduced motion.

## Motion

Card motion is calm and explanatory, per `../DESIGN_LANGUAGE.md → Motion`: a card grid fades/slides in with
`fadeUp` staggered at 30ms per card and capped so a large grid never takes seconds to settle; a drill-in
card's hover lift is a `shadow-sm → shadow-md` transition at `motion.fast`; an approval decision confirms with
a single checkmark that fades in over `motion.base`, holds ~900ms, and fades out — no bounce, no particle
burst, no sound. A realtime update to a card's number (a Reverb KPI tick) patches the query cache and flashes
the card `accent-subtle` back to transparent over 600ms — no toast, no layout shift.

# Card grids & responsive reflow

Cards live in grids, and the grid is deliberately restrained — a 1-to-3-column layout with generous gaps
(`gap-6`), never a dense, gapless bento wall, which is the platform's reading of the editorial-calm mandate
onto the highest-density screen in the product (`../COMPONENT_LIBRARY.md → Composition Patterns → Pattern 3`).

The reflow follows the tiers in [`../RESPONSIVE_DESIGN.md`](../RESPONSIVE_DESIGN.md):

| Tier | KPI stat row | Recommendation / insight column |
|---|---|---|
| Mobile (< `md:`) | Single column, 2 tiles across only if each stays legible | Single column, priority-sorted, full-width cards |
| Tablet (`md:`) | 2–3 across | Two-column mid section |
| Desktop (`xl:`+) | Up to 4 across (the AI Command Center KPI row's full count) | Two-column, capped at the `max-w-[1440px]` container |

```tsx
// app/(app)/dashboard/page.tsx — the canonical card grid
export default function DashboardPage() {
  const { data: kpis } = useDashboardKpisQuery();
  const { data: recommendations } = useAIRecommendationsQuery();
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <section className="grid grid-cols-2 gap-4 lg:col-span-2 lg:grid-cols-3">
        {kpis?.map((kpi) => <KpiTile key={kpi.key} {...kpi} />)}
      </section>
      <section className="space-y-3">
        <h2 className="font-display text-title">Recommendations</h2>
        {recommendations?.map((d) => (
          <AIProposalPanel key={d.id} decision={d} autonomyLevel={d.autonomyLevel}
            onAccept={() => accept(d.id)} onSendForApproval={() => sendForApproval(d.id)}
            onDismiss={(reason) => dismiss(d.id, reason)} />
        ))}
      </section>
    </div>
  );
}
```

Cards in a grid are equal-height by row (the grid stretches them) so a short stat tile and a tall one align
their tops and bottoms; a card never floats at a different height than its row-mates. Below `md:`, a
stat-tile grid collapses to a single column before it compresses a number past legibility — the same
"never shrink the number to fit" rule the table system applies to columns.

# Accessibility

Inherits the WCAG 2.1 AA baseline of [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md); what cards add:

- **Static cards are not tabbable.** A `KpiTile` without `onClick` is a plain region, not a focus stop — no
  false affordance. Its delta direction is announced as text ("up 13.3% vs last month"), not only via arrow
  color (`../COMPONENT_LIBRARY.md → Accessibility per component → KpiTile`).
- **Interactive cards are real controls** with an accessible name (the card's title/label), keyboard
  activation (`Enter`/`Space`), and the visible focus ring — never a `<div onClick>`.
- **Confidence and reasoning are real text.** The `ConfidenceBadge` percentage and label are text; the
  reasoning tooltip is reachable on focus, not hover-only.
- **Approval progress** ("Approval 1 of 2") is real text, not conveyed by a bar's color alone; the reject
  dialog traps focus (Radix `AlertDialog`) and its confirm button stays disabled — and announces why — until
  the reason field is non-empty.
- **Proposal expand/collapse** is a real `<button>` with `aria-expanded`; the alternatives are a real `<ul>`;
  the "Do it" disabled state below the confidence threshold is a genuine `disabled`, announced, not merely
  styled.
- **Card-scoped errors** render as a labelled region with the request id, so an assistive-tech user
  understands which card failed and how to retry, rather than encountering a silent gap.
- **Grids reflow, order stays sensible.** Reading order matches visual order at every tier — a priority-sorted
  recommendation column reads top-to-bottom in importance order for a screen reader exactly as it appears.

# Theming, Dark Mode & RTL

All color, radius, shadow, and type come from the `../COMPONENT_LIBRARY.md → Foundations` tokens; a card file
never contains a raw hex, a numbered palette step, or a `dark:` variant against a raw color. Card surface is
`bg-surface` on `border-ink-150` with `shadow-sm`; the AI tint is `bg-accent-100` with the mark in
`accent-600`; a delta chip is `text-success`/`text-danger`; a status pill inside a card is the standard
`StatusPill`. The hero number is `font-display tabular-nums`; a money value routes through `formatAmount`/
`AmountCell`, never a generic number formatter.

**Dark mode** is a pure token remap under `data-theme="dark"`: the identical card markup renders correctly
dark with no card-level conditional. Per `../DESIGN_LANGUAGE.md → Dark mode strategy`, elevation gets
*lighter* — a raised card sits above a darker canvas — and the AI accent lifts rather than saturates, so an
AI-tinted card reads as gently distinct against the dark surface instead of glowing.

**RTL** uses logical properties exclusively — `ps-*`/`pe-*`, `text-start`/`text-end`, `ms-*`/`me-*`,
`inset-inline-*` — so a card's header meta, footer action order, and internal spacing mirror automatically
with `dir="rtl"` and zero conditional code. The footer's actions mirror (the primary action sits at the
logical end — visual left in Arabic); an approval card's Approve/Reject/Delegate order mirrors as a group.
The two things that never mirror are the money values and currency codes inside a card — every `AmountCell`/
`CurrencyTag` renders `dir="ltr"` with `numberingSystem: 'latn'`, so `KD 84,210.500` reads left-to-right
inside a right-to-left card (`../COMPONENT_LIBRARY.md → Theming & RTL`, rule 2). A directional glyph (a
drill-in chevron) mirrors via `rtl:rotate-180`; a status dot, a checkmark, the AI mark, or a delta sign
never flips — flipping a `+`/`−` would invert its meaning, not its layout.

# i18n & Formatting

- **Strings** — card labels, titles, action verbs, empty/error copy, the "Suggested"/"Recommended" AI
  language — come from next-intl `useTranslations`, with a key in both `en` and `ar` or CI fails. AI action
  language is authored in proposing register in both languages (`../DESIGN_LANGUAGE.md → Arabic microcopy`),
  never machine-translated.
- **Numbers** in a card follow the same rules as everywhere: money through `AmountCell`/`formatAmount`
  (`tabular-nums`, `dir="ltr"`, `latn`, KWD/BHD/OMR at 3 decimals); percentages and counts through the
  card's own formatter with `dir="ltr"` numerals; a delta's sign is real `+`/`−` text carrying the meaning so
  color is secondary.
- **Dates** (an insight's "2 hours ago," an approval's requested-at) render through
  `FormattedRelativeTime`/`FormattedDate` over `Intl` via next-intl `useFormatter`, numerals `dir="ltr"`.
- **Currency** is always shown as the ISO code (`KD`, `KWD`), never a symbol glyph, and never a flag icon —
  a flag implies a country, misleading for KWD/AED/SAR side by side (`../COMPONENT_LIBRARY.md → CurrencyTag`).
- **A "no value" stat** shows an em dash `—`, distinct from a real `0` — a card must never present absence of
  data as a zero figure.

# Testing

Per `../COMPONENT_LIBRARY.md → Testing`:

- **Storybook.** `KpiTile`, `StatCard`, `InsightCard`, `AIProposalPanel`, and `ApprovalCard` each ship a
  `.stories.tsx` covering the permutation matrix they vary along — loading, empty, error, AI-unavailable, high/
  medium/low confidence, with and without delta/sparkline/target — inspectable in all four LTR/RTL × light/dark
  combinations via the global decorator, with `parameters.state` mocks short-circuiting the query hook.
- **Vitest + Testing Library.** The logic-bearing cards: `AIProposalPanel`'s "Do it" stays disabled below the
  confidence threshold and when `autonomyLevel !== 'auto'`; `ApprovalCard`'s confirm-reject stays disabled
  until the reason is non-empty; the `normalizeConfidence` scale conversions (0–100 vs. 0–1);
  `KpiTile`/`StatCard` render an em dash rather than `0` for a null value; a delta's sign glyph matches its
  direction.
- **Playwright.** The card-driven flows: the full `AIProposalPanel → ApprovalCard` send-for-approval path
  across an Accountant and a Finance Manager persona, asserting the gate's permission-driven button labels; a
  drill-in `KpiTile` navigating to the correctly-filtered ledger URL.
- **Accessibility CI.** `axe-core` runs against every card story; a static card that is wrongly tabbable, a
  missing accessible name on an interactive card, or a disabled action lacking its announced reason fails the
  build.

# Edge Cases

- **AI number with no confidence field.** A stat card whose value is a raw SQL aggregate (not AI-computed)
  carries **no** `ConfidenceBadge` — the badge means "the AI computed this," and putting it on a deterministic
  figure is as wrong as omitting it from an AI one. `confidence` is `null` for non-AI stats.
- **Two confidence scales.** `ai_decisions.confidence_score` (0–100) vs. `journal_lines.ai_confidence` (0–1)
  are genuinely different scales on different tables; every card passes the raw value through
  `normalizeConfidence(raw, sourceField)` — a wrong `sourceField` is the most likely AI-card bug and is
  unit-tested explicitly.
- **Confidence below 60%.** The proposal is still *shown* (with a "Low confidence" band) but its one-click
  "Do it" is disabled regardless of configured autonomy — a low-confidence output can never execute itself.
- **Zero vs. no data.** A card renders an em dash for genuinely absent data and an explicit `0.000` for a real
  zero balance — a reconciled account showing `0.000` and a period with no data showing `—` must never look
  alike.
- **Long AI statements / bilingual overflow.** An insight or recommendation body can be long, and Arabic runs
  longer than English at the same size; the card grows to fit its statement (it is not a fixed-height tile) and
  truncates only a title with a `title` attribute + focus tooltip, never the reasoning that justifies the
  action.
- **Permission removed mid-session.** An `ApprovalCard`/`AIProposalPanel` action re-checks permission at
  mutation time and surfaces the server's `403` as final (refetching the permission cache, toasting that
  access changed), never trusting a mount-time check for a sensitive approve/execute.
- **Optimistic dismiss / approve rollback.** A dismissed insight that fails to commit reappears with its Undo
  window intact; an approval returning `409 version_conflict` refetches the authoritative record and shows
  "this changed since you opened it" rather than silently overwriting.
- **Reduced motion.** The grid stagger, the drill-in hover lift, the proposal expand/collapse, and the
  posted-confirmation checkmark all collapse to instant state changes under `prefers-reduced-motion: reduce` —
  the state still changes; only its animation is removed.
- **Print / PDF.** A card grid exported grayscale reads correctly because a delta's polarity rides on its
  `+`/`−` sign and a status on its `StatusPill` dot+label, never on color alone.

# End of Document
