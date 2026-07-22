# AI Widget — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / AI_WIDGET
---

# Purpose

This document is the atomic component spec for the small set of primitives every AI-authored surface in
QAYD is assembled from: `ConfidenceBadge` and `ConfidenceMeter` (how certainty is shown),
`ReasoningPanel` (how the "why" is reached), `AgentAvatar` / `AgentAttributionChip` (who produced it),
`AIProposalCard` (one actionable recommendation), and the `ApprovalActionBar` (the approve / reject /
delegate cluster). It fixes their shared visual grammar and the one contract they exist to enforce, in
design-system terms — tokens, shape, state, a11y, theming — so no screen ever invents a second confidence
indicator, a second proposal shape, or a second reading of "the human is still in the loop."

It is a **specialization** of, and defers to, the app-level reference at
[`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md), which owns the full
prop surface, the realtime/streaming wiring, the fifteen-agent roster, the permission matrix, and the
domain payloads (`ai_decisions`, `ai_risk_flags`, `journal_lines.ai_confidence`). This document does not
re-derive that contract; it restates the atomic *design* rules those widgets share and cross-links back
for the exhaustive behavior. Where the two touch, the app doc governs behavior and this doc governs the
token and shape system.

Every color, radius, and motion value references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the warm
`ink-1…12` scale, the single brass `accent` (rationed to AI-touched elements and the one primary action),
and the `positive`/`negative`/`warning` financial set. There is no "AI purple," and no widget here ships a
literal a token already covers.

# The AI-in-UI Contract

Three invariants bind every primitive below; a widget that violates one is a defect regardless of how it
looks. They are the full statement in [`../../frontend/components/AI_WIDGETS.md → The AI-in-UI
Contract`](../../frontend/components/AI_WIDGETS.md), restated here as the design-system rule.

1. **Confidence + reasoning are never separable from an AI value.** No primitive renders a bare AI-computed
   value without an attached confidence indicator and a reachable explanation. A percentage always has a
   path to *why*.
2. **Never auto-commit the proposal.** The UI auto-commits only a *human's decision about* a proposal,
   never the proposal itself. Whether a one-click "Do it" renders at all is decided by the server's
   `can_execute_directly`, never re-computed client-side — when it is `false`, the button is **absent**,
   not disabled.
3. **Confidence is never color alone.** A band is carried by three redundant, non-hue channels — a **fill
   level**, a **shape** (solid vs. hollow/dashed track), and a **text label** — so it survives grayscale,
   dark mode, and color-vision deficiency. QAYD does **not** render confidence as a red/yellow/green
   traffic light.

## The confidence band table

The canonical banding every confidence primitive resolves against. Callers normalize QAYD's two live
scales (`0–100` for `ai_decisions.confidence_score`, `0.0–1.0` for `journal_lines.ai_confidence`) onto a
single **0–1** range first, via `normalizeConfidence(raw, sourceField)`.

| Band | Range (0–1) | Label (EN / AR) | Badge tone | Meter fill | Meter shape | One-click "Do it" |
|---|---|---|---|---|---|---|
| High | `≥ 0.85` | High confidence / ثقة عالية | `accent` | full, `accent` | solid | Eligible if `can_execute_directly` |
| Medium | `0.60 – 0.849` | Medium confidence / ثقة متوسطة | `neutral` | ~⅔, `ink-9` | solid | Eligible if `can_execute_directly` |
| Low | `< 0.60` | Low confidence / ثقة منخفضة | `neutral` | ≤ ⅓, `ink-9` | **hollow / dashed** | **Withheld — never renders** |

Two hard rules fall out: below `0.60` the one-click auto-execute never renders (regardless of server
autonomy), and even a High-band item only offers "Do it" when the server already returned
`can_execute_directly === true` (which itself clears the `0.90` `AUTO_EXECUTE_THRESHOLD`). The UI gate and
the server gate are deliberately redundant, never a substitute for one another.

# Anatomy

An AI-authored card stacks the same envelope every time, so "the AI touched this" reads identically
everywhere:

```
┌─ border-s-4 accent (or warning/negative for severity) ──────────────┐
│  * AI   [◕ 92% High confidence]   ◐ General Accountant              │  ← provenance row
│                                                                      │
│  Recommended: reclassify KWD 1,240.000 to Marketing Expense          │  ← primary content
│  Alternative: leave in Suspense — needs a receipt to confirm         │
│                                                                      │
│  ▸ Why this?  (collapsed disclosure → reasoning + cited sources)     │  ← ReasoningPanel (pull, not push)
│                                                                      │
│                     [ Dismiss ]  [ Send for approval ]  [ Do it ]    │  ← action cluster
└──────────────────────────────────────────────────────────────────────┘
```

| Part | Primitive | Rule |
|---|---|---|
| Provenance row | `Sparkles` badge + `ConfidenceBadge` + `AgentAttributionChip` | Three redundant AI cues: glyph, "AI" text, accent edge |
| Leading edge | `border-s-4` | `accent` for AI-provenance; `warning`/`negative` for *severity*, an independent signal |
| Confidence | `ConfidenceBadge` (+ optional `ConfidenceMeter` glyph) | Band by fill + shape + label, never hue alone |
| Explanation | `ReasoningPanel` | Collapsed by default; sources are real drill-through links |
| Decision | `AIProposalCard` buttons / `ApprovalActionBar` | `Do it` absent below threshold; Reject needs a reason |

The leading accent edge is `border-s-4` — inline-start in both LTR and RTL from one logical rule. Severity
never rides the accent: a critical item is `negative`, so "AI-touched" and "urgent" stay two signals.

# Variants

| Primitive | Variant axis | Values |
|---|---|---|
| `ConfidenceBadge` | `size`, `showLabel`, `showMeter` | `sm` (dense) / `default`; label on/off; leading meter on/off |
| `ConfidenceMeter` | `size` | `xs` (in a badge) / `sm` / `md` (KPI tile) |
| `ReasoningPanel` | `variant` | `popover` (default, feed-safe) / `inline` (chat only) |
| `AgentAvatar` | `size` | `sm` 24px (chat) / `md` 28px (cards) |
| `AIProposalCard` | button set | driven by `can_execute_directly` + the `0.60` floor, never a style toggle |
| `ApprovalActionBar` | affordances | Approve always; Reject always; Delegate only when `onDelegate` provided |

`AIProposalCard` (compact, feed-embeddable) and the app doc's `AIProposalPanel` (expanded, alternatives
inline) are two densities of one contract — a screen picks one, never both for the same decision.

# Props / API

Full prop tables live in [`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md);
the design-system-load-bearing surface:

## ConfidenceBadge

| Prop | Type | Required | Description |
|---|---|---|---|
| `confidence` | `number` | yes | Canonical **0–1**; caller normalizes first, never a raw `0–100`. |
| `size` | `'sm' \| 'default'` | no | `sm` in dense rows/tiles. |
| `showLabel` | `boolean` | no (default `true`) | Band label beside the percentage. |
| `showMeter` | `boolean` | no (default `false`) | Leading `ConfidenceMeter` glyph. |
| `reasoning` | `string` | no | Tooltip on hover **and** keyboard focus. |

```tsx
// components/ai/confidence-badge.tsx (design-system shape)
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { ConfidenceMeter } from '@/components/ai/confidence-meter';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/lib/i18n';

export function normalizeConfidence(raw: number, sourceField: 'fraction' | 'percentage') {
  return sourceField === 'percentage' ? raw / 100 : raw;
}
export function confidenceBand(c: number): { band: 'high' | 'medium' | 'low'; labelKey: string; tone: 'accent' | 'neutral' } {
  if (c >= 0.85) return { band: 'high', labelKey: 'ai.confidence.high', tone: 'accent' };
  if (c >= 0.6)  return { band: 'medium', labelKey: 'ai.confidence.medium', tone: 'neutral' };
  return { band: 'low', labelKey: 'ai.confidence.low', tone: 'neutral' };
}

export function ConfidenceBadge({ confidence, size = 'default', showLabel = true, showMeter = false, reasoning }: {
  confidence: number; size?: 'sm' | 'default'; showLabel?: boolean; showMeter?: boolean; reasoning?: string;
}) {
  const { labelKey, tone } = confidenceBand(confidence);
  const { t } = useTranslations('ai');
  const badge = (
    // tone 'accent' → bg-accent-subtle/text-accent ; tone 'neutral' → bg-ink-3/text-ink-11
    <Badge tone={tone} className={cn('gap-1.5', size === 'sm' && 'px-2 py-0 text-[11px]')}>
      {showMeter && <ConfidenceMeter confidence={confidence} size="xs" aria-hidden />}
      <span className="font-mono tabular-nums" dir="ltr">{Math.round(confidence * 100)}%</span>
      {showLabel && <span>{t(labelKey)}</span>}
    </Badge>
  );
  if (!reasoning) return badge;
  return (
    <Tooltip>
      <TooltipTrigger asChild><button type="button" className="cursor-help">{badge}</button></TooltipTrigger>
      <TooltipContent className="max-w-xs text-start">{reasoning}</TooltipContent>
    </Tooltip>
  );
}
```

## ConfidenceMeter

| Prop | Type | Required | Description |
|---|---|---|---|
| `confidence` | `number` | yes | Canonical 0–1 (pre-normalized). |
| `size` | `'xs' \| 'sm' \| 'md'` | no (default `sm`) | Track length. |
| `ariaLabel` | `string` | no | Present ⇒ `role="img"`; absent ⇒ `aria-hidden` beside a badge. |

A four-segment track whose **filled count encodes the band** (High 4/4, Medium 2–3/4, Low ≤1/4 with the
remainder a **hollow dashed outline**). Filled segments are `accent` (High) or `ink-9` (Medium/Low); the
fill animates a width tween under `motion.moderate`, reduced-motion-safe.

## AIProposalCard

| Prop | Type | Required | Description |
|---|---|---|---|
| `decision` | `AiDecisionWithAction` | yes | Adds `recommended_action`, `alternatives`, `can_execute_directly`. |
| `onExecute` | `() => Promise<void>` | conditional | Wired only when `can_execute_directly`; backs `POST /ai/decisions/{id}/execute`. |
| `onSendForApproval` | `() => Promise<void>` | yes | Hands the item into the `ApprovalCard` human-gate. |
| `onDismiss` | `(reason: string) => Promise<void>` | yes | Reason required; stored as `rejected_reason`. |

```tsx
const AUTO_EXECUTE_THRESHOLD = 0.9; // UI mirror of the company-configured server threshold
const offerDoIt = decision.can_execute_directly && confidence >= AUTO_EXECUTE_THRESHOLD && Boolean(onExecute);
// ... below 0.60 confidence, offerDoIt is structurally false and the button never mounts.
```

## ApprovalActionBar

| Prop | Type | Required | Description |
|---|---|---|---|
| `permissionKey` | `string` | yes | Exact gating key (`ai.approve`, `bank.transfer`, …); drives disabled + tooltip. |
| `onApprove` | `(note?: string) => Promise<void>` | yes | Optimistic; rolls back on `4xx/5xx`. |
| `onReject` | `(reason: string) => Promise<void>` | yes | Reason mandatory before Confirm un-disables. |
| `onDelegate` | `(userId: number) => Promise<void>` | no | Renders the Delegate control only when provided. |

Approve is the one filled `accent` action; Reject uses `destructive-quiet` (never the loud `destructive`,
reserved for permanent deletion); Delegate is `ghost`.

# States

Every primitive ships an explicit treatment for each state; none conveys a state by color alone. This
condenses [`../../frontend/components/AI_WIDGETS.md → States`](../../frontend/components/AI_WIDGETS.md).

| Primitive | Default | Loading | Low-confidence | Degraded (`503`) | Disabled (RBAC) |
|---|---|---|---|---|---|
| `ConfidenceBadge` | Pill + band + optional meter | Renders once value resolves | `neutral` tone, "Low" label, hollow meter | Not rendered; parent shows `AiUnavailable` | N/A (read) |
| `ConfidenceMeter` | Segmented fill by band | Static empty track | ≤⅓ filled + hollow/dashed remainder | Not rendered | N/A |
| `ReasoningPanel` | Collapsed disclosure | Disabled until reasoning loads | Unchanged — low confidence still shows its "why" | Not rendered | N/A |
| `AgentAvatar` / chip | Domain glyph + name | Skeleton circle | Unchanged | Not rendered | N/A |
| `AIProposalCard` | Buttons per `can_execute_directly` | Acting button `loading` | **"Do it" absent**; Send + Dismiss remain | Replaced by `AiUnavailable` | Send/Do-it disabled + naming tooltip |
| `ApprovalActionBar` | Approve / Reject / Delegate | Acting button `loading`; others locked | Confidence Low; approval still human-gated | Queue retry, never stale silent trust | Disabled + tooltip naming the missing permission |

**Absent ≠ disabled.** A "Do it" withheld by `can_execute_directly: false` (or confidence below `0.60`)
does not render — there is no client path to execute. A control disabled for a *permission* reason renders
greyed with a tooltip naming the missing permission — the user should know the action exists but isn't
theirs. The former is a structural guarantee; the latter is an explanation.

# Tokens Used

| Role | Token | Where |
|---|---|---|
| AI provenance | `accent`, `accent-subtle`, `accent-on` | `Sparkles` badge, High-band pill/meter, leading edge, AI-notice wash |
| Severity (independent of accent) | `warning`, `negative` | `AiCardShell` leading edge for `warning`/`critical` |
| Neutral confidence / secondary text | `ink-9`, `ink-11` | Medium/Low meter fill, reasoning prose, agent name |
| Surfaces / hairlines | `ink-2`, `ink-3`, `ink-6` | Card background, dashed reasoning divider, borders |
| Approve / Reject | `accent` (Approve fill), `negative` (`destructive-quiet` Reject) | `ApprovalActionBar` |
| Radius | `radius-lg` (cards), `radius-full` (avatar/dots) | Card shell, `AgentAvatar`, meter segments |
| Motion | `motion.moderate` / `easeOut` (meter fill, disclosure) | Reduced-motion-gated |
| Elevation | `surface-1` (`shadow-xs`, `ink-6`) | Card resting; `surface-glass` reserved for the Command Center only |
| Z-index | `z-tooltip` | Reasoning/permission tooltips above modals |

The brass `accent` marks AI provenance and the one primary action; it **never** carries status or severity,
and `positive`/`negative` **never** carry brand or AI-badge meaning — the separation is enforced per
[`../DESIGN_TOKENS.md → Color — Financial Semantics`](../DESIGN_TOKENS.md). A risk's raw
`financial_exposure` renders in plain `ink` tabular numerals, never washed `negative`.

# Accessibility

The load-bearing rule of this document is **confidence is never conveyed by color alone** — three
redundant, non-hue channels (meter fill level, meter shape, and a real text label) plus the numeric
percentage as a real text node. A grayscale export, a dark render, and a color-vision-deficient reader all
read the same band. The same three-cue rule governs AI *provenance*: the `Sparkles` glyph **and** an "AI"
text label **and** an accent border — no single carrier is a color.

- **Every control explains itself before the action.** `ConfidenceBadge`'s percentage and label are real
  text, not a bare progress-bar width. Approve/Reject/Delegate are real `<button>`s with `aria-describedby`
  pointing at the card's reasoning, so "why" is available before acting, not merely adjacent.
- **RBAC-disabled controls announce their reason.** A permission-disabled control carries a tooltip-linked
  `aria-describedby` naming the missing permission (kept textually distinct from a business-state block). A
  control *withheld* by `can_execute_directly: false` is simply absent — nothing to announce.
- **`ReasoningPanel` is a real `aria-expanded` disclosure**; its tooltip is reachable on keyboard focus,
  never hover-only; its sources are a real `<ul>` of tabbable drill-through links.
- **Live regions calibrated.** New AI content announces `aria-live="polite"`, never assertive; streaming
  replies announce once at start and once at completion, never token-by-token.
- **Keyboard + reduced motion.** Every interactive element is a real semantic control with a visible
  `accent` focus ring at non-zero offset; every animation reads `useReducedMotion()` and degrades to an
  instant state change (never "quick motion").

Target: **WCAG 2.2 AA** in both themes and both directions; `axe-core` runs against every widget story and
the confidence widgets get a manual grayscale pass each release, since `axe` cannot verify a band is still
distinguishable without color.

# Theming, Dark Mode & RTL

**Tokens only, never raw values.** Every color/radius/shadow resolves to a `--qayd-*`-backed Tailwind
utility; no widget contains a `dark:` variant referencing a raw color. One token edit re-skins every AI
surface at once.

**Dark mode is a first-class remap, not an inversion.** `.dark` re-points the ink scale, the accent, and
the semantics with calibrated values ([`../DESIGN_TOKENS.md → Light / Dark Remap`](../DESIGN_TOKENS.md)).
Two AI-specific rules: the accent stays reserved for AI provenance and the one primary action (a critical
item is `negative` in both themes, never the accent); and confidence indicators re-tune per theme, not
linearly brighten, so a High-band hold reads correctly in dark mode without over-saturation. The
`ConfidenceMeter`'s fill resolves through `lib/tokens.ts`' JS-readable export, because a motion width tween
cannot consume a Tailwind class the way a `div` background can.

**RTL is one tree, mirrored by logical properties.** Every widget uses `ms/me`, `ps/pe`, `border-s/e`,
`text-start/end` — the `AiCardShell`'s accent edge is `border-s-4` (inline-start in both directions), the
`ReasoningPanel` chevron flips via `rtl:`. Three things never mirror: **every confidence percentage,
amount, and date** renders inside a `dir="ltr"` `latn` span (a `93%` reads LTR inside Arabic text); the
`Sparkles`, meter, checkmark, and severity-dot **glyphs** keep orientation; and the AI's own prose arrives
already localized from the API — the frontend never machine-translates a model's words.

# Do / Don't

| Do | Don't |
|---|---|
| Render every AI value with a confidence indicator and a reachable reasoning | Show a bare AI number or a percentage with no path to "why" |
| Normalize with `normalizeConfidence(raw, sourceField)` before rendering | Threshold or format a raw `0–100` field directly |
| Carry the band with fill + shape + text label | Render confidence as a red/yellow/green traffic light |
| Withhold "Do it" structurally when `can_execute_directly` is false or confidence < 0.60 | Render "Do it" disabled and leave a re-enable path in the DOM |
| Use `accent` for AI provenance and the one primary action | Introduce an "AI purple" or wash severity in the accent |
| Show a risk's raw exposure in plain `ink` tabular numerals | Wash a raw magnitude in `negative` |
| Require a reason on every Reject/Dismiss | Let a rejection commit with an empty reason |
| Keep the model's prose as delivered by the API | Machine-translate AI-authored text in the client |

# Usage & Composition

**Compose a proposal from the primitives** — the card shell stacks provenance, confidence, reasoning, and
the decision cluster in one fixed envelope:

```tsx
// One ai_decisions row, feed-embeddable
<AiCardShell decision={decision} severity="none">
  <p className="text-md text-ink-12">{decision.recommended_action.action}</p>
  <ApprovalActionBar
    permissionKey="ai.approve"
    onApprove={approve}
    onReject={reject}      // reason-gated dialog
    onDelegate={delegate}  // renders only when caller is the assigned approver
  />
</AiCardShell>
```

**On a KPI tile whose value is an AI computation** — the meter is standalone and meaningful, so it takes an
`ariaLabel`:

```tsx
<div className="rounded-lg border border-ink-6 bg-ink-2 p-4">
  <span className="font-display text-[2rem] tabular-nums text-ink-12" dir="ltr">94%</span>
  <ConfidenceMeter confidence={0.94} size="md" ariaLabel={t('ai.confidenceValue', { pct: 94 })} />
</div>
```

**Inside a search AI answer** — the same `ConfidenceBadge` + reasoning contract renders on the `/search`
answer card (see [`SEARCH.md`](./SEARCH.md) and
[`COMMAND_PALETTE.md`](./COMMAND_PALETTE.md)); a search keystroke proposes, never commits. **On the
Financial Calendar** — only genuinely AI-surfaced chips carry a `ConfidenceBadge`; a deterministic
government-set due date renders as a plain category chip with no accent edge
([`CALENDAR.md`](./CALENDAR.md), [`../../frontend/CALENDAR.md`](../../frontend/CALENDAR.md)).

For the exhaustive prop surface, realtime/streaming wiring, the full state matrix, the fifteen-agent
roster, and testing, see [`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md).

# End of Document
