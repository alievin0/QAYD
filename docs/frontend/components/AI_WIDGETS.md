# AI Widgets — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / AI_WIDGETS
---

# Purpose

This document is the definitive specification for the family of components through which every
AI-originated value, suggestion, draft, flag, and action reaches a human in the QAYD web client. It is
the single source of truth for how the AI layer becomes *visible* in the UI — the confidence pill, the
reasoning drawer, the source-document citation, the proposal card, the approve/reject/delegate bar, the
AI-drafted form field, the agent-attribution chip, the dashboard insight and risk cards, the inline
assistant, the streaming indicator, and the automation-status chip — and for the two fallback states
(low-confidence, AI-unavailable) that keep the interface honest when the AI is uncertain or offline.

Where [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) catalogues these widgets alongside every other
primitive and finance component, this document is their authoritative, exhaustive reference: it fixes
the full prop surface, every visual and behavioral state, the realtime wiring, and the accessibility and
localization obligations of each, so that no two engineers ever independently invent a second confidence
indicator, a second way to render an AI proposal, or a second interpretation of what "the human is still
in the loop" means in pixels. It is a *specialization* of, never a contradiction to, the platform
documents it inherits from: [`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) owns the token system and the
principle that "the AI stays humble in the UI"; [`../ICONOGRAPHY.md`](../ICONOGRAPHY.md) owns the
`Sparkles` provenance treatment, the fifteen-agent icon roster, and the confidence-meter glyph;
[`../ACCESSIBILITY.md`](../ACCESSIBILITY.md), [`../THEMING.md`](../THEMING.md), and the
[`../FRONTEND_ARCHITECTURE.md`](../FRONTEND_ARCHITECTURE.md) AI Integration Layer own the cross-cutting
rules every widget below obeys. The screen documents that compose these widgets —
[`../AI_COMMAND_CENTER.md`](../AI_COMMAND_CENTER.md), [`../AI_CHAT.md`](../AI_CHAT.md),
[`../DASHBOARD.md`](../DASHBOARD.md), and every module screen that surfaces an AI draft or flag — assume
this specification and do not re-derive it.

Every widget here is domain-aware: its props are shaped by a real QAYD table or endpoint
(`ai_decisions`, `ai_approval_requests`, `ai_risk_flags`, `ai_insights`, `journal_lines.ai_confidence`,
`accounts.ai_suggestion_confidence`), not a generic placeholder, and the agent behavior each renders is
read verbatim from the backend agent specifications under [`../../ai/agents/`](../../ai/agents/) — the
frontend renders that contract, it never computes it.

# The AI-in-UI Contract (confidence + reasoning + human-in-the-loop)

Three invariants, restated once here because every widget in this document exists to enforce one or more
of them, and any widget that violates one is a defect regardless of how it looks:

1. **Every AI-originated number, suggestion, draft, or flag renders its confidence and its reasoning.**
   No component in this document ever shows a bare value the AI computed without an attached confidence
   indicator (`ConfidenceBadge`/`ConfidenceMeter`) and an attached, reachable explanation
   (`ReasoningPanel`). A percentage is never rendered without a path to *why*; a drafted amount is never
   rendered without a confidence and a provenance mark.
2. **Every AI action touching money, tax, payroll, or posted financial data renders an explicit
   approve / reject / delegate affordance, and the frontend never auto-commits the proposal.** The UI
   auto-commits only a *human's decision about* a proposal, never the proposal itself. Whether the
   one-click "Do it" affordance renders at all is decided by the server-computed `can_execute_directly`
   field, never by the client re-evaluating autonomy rules — when it is `false`, the button is *absent*,
   not disabled, so a determined user cannot re-enable a path that the mutation endpoint would in any
   case `403`.
3. **AI actions are gated behind `ai.approve` / `ai.automation` permissions**, checked in the UI as a
   matter of experience quality (hide or disable the control) and enforced authoritatively on the
   backend. The client's convenience check goes through `usePermission()`; the server's `403` is the
   final word (see [`../../foundation/PERMISSION_SYSTEM.md`](../../foundation/PERMISSION_SYSTEM.md)).

## Permissions this document's widgets check

| Permission | Governs | Widgets |
|---|---|---|
| `ai.approve` | Acting on any AI-originated pending item — approve, reject, execute a proposal, dismiss a `critical` flag | `ApprovalCard`, `ApprovalActionBar`, `AIProposalCard`, `AIProposalPanel`, `RiskCard` (critical dismiss) |
| `ai.automation` | Viewing and toggling automation rules; the presence of the `AutomationStatusChip`'s manage affordance | `AutomationStatusChip` |
| `ai.chat` | Opening the assistant, sending a message, reading a reply | `AskAIWidget` |
| `reports.read` | Reading AI-authored read-only content (insights, risks, health figures) | `InsightCard`, `RiskCard`, `AIDraftField` (read context) |

The permission a *sensitive proposal* itself resolves to (e.g. `bank.transfer`, `payroll.approve`,
`tax.submit`) is carried on the `ApprovalCard`'s `kind` discriminant and checked in addition to
`ai.approve`; a proposal whose `required_permission` the caller lacks renders its action bar disabled
with an explanation, never silently omitted (see [States](#states)).

## The single confidence scale, normalized once

QAYD's data model carries confidence on two genuinely different scales — `ai_decisions.confidence_score`
and `ai_messages.confidence`-adjacent fields are `0–100`, while `journal_lines.ai_confidence` and
`accounts.ai_suggestion_confidence` are `0.0000–1.0000`. Every widget below renders confidence on the
component library's canonical **0–1** internal range, and every caller passes the raw field through the
shared `normalizeConfidence(raw, sourceField)` helper (defined on `ConfidenceBadge`,
[`../COMPONENT_LIBRARY.md → ConfidenceBadge`](../COMPONENT_LIBRARY.md)). No widget in this document
compares, formats, or thresholds a raw confidence number itself — a missing or wrong `sourceField`
argument is the single most likely AI-widget bug across the frontend, which is exactly why it is
unit-tested rather than left to convention (see [Testing](#testing)).

## The confidence-score band table

This is the canonical banding every confidence widget in this document resolves against. It supersedes
the earlier "below 70 renders amber" note in the [`../FRONTEND_ARCHITECTURE.md`](../FRONTEND_ARCHITECTURE.md)
draft: per the `ConfidenceBadge` rule that confidence is "never a traffic-light red/yellow/green
indicator (that reads as alarm-driven and contradicts the platform's calm, non-alarming finance tone),"
QAYD does **not** color low confidence red or amber. Instead a band is distinguished by three redundant,
non-hue channels — a **fill level** (the meter), a **shape** (solid vs. hollow ring/track), and a **text
label** — so the banding survives grayscale, dark mode, and color-vision deficiency without ever leaning
on hue alone.

| Band | Range (0–1) | Label (EN) | Label (AR) | Badge `tone` | Meter fill | Meter shape | One-click "Do it" |
|---|---|---|---|---|---|---|---|
| High | `≥ 0.85` | High confidence | ثقة عالية | `accent` | full track, `accent` | solid | Eligible (if `can_execute_directly`) |
| Medium | `0.60 – 0.849` | Medium confidence | ثقة متوسطة | `neutral` | ~⅔ track, `ink-9` | solid | Eligible (if `can_execute_directly`) |
| Low | `< 0.60` | Low confidence | ثقة منخفضة | `neutral` | ≤ ⅓ track, `ink-9` | **hollow / dashed track** | **Withheld — never renders** |

Two hard rules fall out of this table. First, **below `0.60` the one-click auto-execute affordance never
renders**, regardless of the item's configured autonomy or the server's `can_execute_directly` — a
low-confidence output may still be *shown and sent for approval*, but it can never be the thing that
executes itself. Second, the separate **auto-execute floor is `0.90`** (`AUTO_EXECUTE_THRESHOLD`, a
UI-side mirror of the company-configured server threshold): even a High-band item only offers "Do it"
when the server has already said `can_execute_directly === true`, which itself requires clearing that
floor. The UI gate and the server gate are deliberately redundant, never a substitute for one another.

# Widget Catalogue

Every widget lives under `components/ai/` unless noted, and every AI-authored surface in the product is
composed from this set — no screen introduces a bespoke confidence indicator, a bespoke proposal shape,
or a bespoke approval affordance.

| Widget | File | Renders | Primary screens | Permission to *act* |
|---|---|---|---|---|
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Normalized 0–1 score as a restrained pill + qualitative band + reasoning tooltip | Everywhere AI content appears | — (read) |
| `ConfidenceMeter` | `components/ai/confidence-meter.tsx` | The confidence glyph — a segmented fill encoding the band by shape + level, Framer-Motion-filled | KPI tiles, proposal headers, draft fields | — (read) |
| `ReasoningPanel` | `components/ai/reasoning-panel.tsx` | Collapsed-by-default rationale + source-document citations with drill-through | Every `AiCardShell` payload | — (read) |
| `AgentAvatar` | `components/ai/agent-avatar.tsx` | The producing agent's domain glyph in a 28px tinted circle | Proposal/chat/insight attribution | — (read) |
| `AgentAttributionChip` | `components/ai/agent-attribution-chip.tsx` | `AgentAvatar` + localized agent name (+ optional confidence) | Insight/risk/message headers | — (read) |
| `AiCardShell` | `components/ai/ai-card-shell.tsx` | The mandatory colored-border + `Sparkles` "AI" badge + confidence + reasoning envelope | Every AI-authored card | — (read) |
| `AIProposalCard` | `components/ai/recommendation-card.tsx` | One actionable `ai_decisions` row with the three-button pattern | Command Center, Chat, module screens | `ai.approve` |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` | The full proposal — recommended action, alternatives-with-tradeoffs, three interactions | Command Center Recommendations | `ai.approve` |
| `ApprovalCard` | `components/shared/approval-card.tsx` | The shared human-gate for any sensitive action, AI- or human-originated | Approval Center, entity detail | per `kind` + `ai.approve` |
| `ApprovalActionBar` | `components/ai/approval-action-bar.tsx` | The reusable Approve / Reject / Delegate control cluster | Inside `ApprovalCard`, inline in chat | per `kind` + `ai.approve` |
| `AIDraftField` | `components/ai/ai-draft-field.tsx` | A form field pre-filled by AI, showing provenance + confidence, never auto-committed | Journal composer, account mapping, tax coding | — (edit is the human's) |
| `InsightCard` | `components/ai/insight-card.tsx` | A read-only atomic observation (Dashboard "Top Recommended Actions" / feed) | Dashboard, AI Insights | — (read) / graduates to `AIProposalCard` |
| `RiskCard` | `components/ai/risk-card.tsx` | One `ai_risk_flags` row (Dashboard "Top Risks"), severity-first | Dashboard, Detected Risks | `ai.approve` (critical dismiss) |
| `AskAIWidget` | `components/ai/ask-ai-widget.tsx` | The inline/docked assistant composer + transcript | Every screen (docked), `/assistant` | `ai.chat` |
| `AiTypingIndicator` | `components/ai/ai-typing-indicator.tsx` | The calm "AI is generating" three-dot pulse for in-progress generation | Chat, streaming proposals | — |
| `AutomationStatusChip` | `components/ai/automation-status-chip.tsx` | The status of the automation rule behind an auto-executed action (`ai.automation`) | Command Center, audit trail | `ai.automation` |
| `AiUnavailable` | `components/ai/ai-unavailable.tsx` | The distinct AI-degraded / offline fallback state | Any AI-only panel on `503` | — |

# Props / API per widget (tables)

Each subsection gives the full prop table, the load-bearing TSX (consistent with
[`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)'s conventions — `Icon` wrapper, `Card`, `Button`
variants, `usePermission`, TanStack Query hooks, tokens as utilities, no raw hex/px), and a usage example
wired to a real `/api/v1/...` route.

## ConfidenceBadge

Renders a normalized confidence score as a small, restrained pill — never a red/yellow/green traffic
light, never a bare unexplained number. It always accepts a `reasoning` slot so a user can see *why* a
number is what it is.

| Prop | Type | Required | Description |
|---|---|---|---|
| `confidence` | `number` | yes | Canonical **0–1**. Callers pass the raw field through `normalizeConfidence()` first — never a raw `0–100` field. |
| `size` | `'sm' \| 'default'` | no | `sm` inside dense contexts (draft fields, table rows, KPI tiles). |
| `showLabel` | `boolean` | no (default `true`) | Renders "High/Medium/Low confidence" beside the percentage. |
| `showMeter` | `boolean` | no (default `false`) | Renders a leading `ConfidenceMeter` glyph before the percentage. |
| `reasoning` | `string` | no | Shown in a `Tooltip` on hover **and** keyboard focus. |

```tsx
// components/ai/confidence-badge.tsx
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { ConfidenceMeter } from '@/components/ai/confidence-meter';
import { cn } from '@/lib/utils';

/** Normalizes QAYD's two live confidence scales onto a single 0–1 range.
 *  `journal_lines.ai_confidence`, `accounts.ai_suggestion_confidence` → already 0–1.
 *  `ai_decisions.confidence_score`, `ai_messages.confidence` (0–100)   → /100. */
export function normalizeConfidence(raw: number, sourceField: 'fraction' | 'percentage'): number {
  return sourceField === 'percentage' ? raw / 100 : raw;
}

export type ConfidenceBand = 'high' | 'medium' | 'low';

export function confidenceBand(confidence: number): {
  band: ConfidenceBand;
  labelKey: string;
  tone: 'accent' | 'neutral';
} {
  if (confidence >= 0.85) return { band: 'high', labelKey: 'ai.confidence.high', tone: 'accent' };
  if (confidence >= 0.6) return { band: 'medium', labelKey: 'ai.confidence.medium', tone: 'neutral' };
  return { band: 'low', labelKey: 'ai.confidence.low', tone: 'neutral' };
}

interface ConfidenceBadgeProps {
  confidence: number;
  size?: 'sm' | 'default';
  showLabel?: boolean;
  showMeter?: boolean;
  reasoning?: string;
}

export function ConfidenceBadge({
  confidence, size = 'default', showLabel = true, showMeter = false, reasoning,
}: ConfidenceBadgeProps) {
  const { band, labelKey, tone } = confidenceBand(confidence);
  const { t } = useTranslations('ai');
  const pct = Math.round(confidence * 100);

  const badge = (
    <Badge tone={tone} className={cn('gap-1.5', size === 'sm' && 'px-2 py-0 text-[11px]')}>
      {showMeter && <ConfidenceMeter confidence={confidence} size="xs" aria-hidden />}
      {/* numerals never mirror, even inside an RTL row */}
      <span className="font-mono tabular-nums" dir="ltr">{pct}%</span>
      {showLabel && <span>{t(labelKey)}</span>}
    </Badge>
  );

  if (!reasoning) return badge;
  return (
    <Tooltip>
      {/* real <button>, reachable and Esc-dismissible — reasoning is on focus, not hover-only */}
      <TooltipTrigger asChild><button type="button" className="cursor-help">{badge}</button></TooltipTrigger>
      <TooltipContent className="max-w-xs text-start">{reasoning}</TooltipContent>
    </Tooltip>
  );
}
```

```tsx
// Usage — an ai_decisions row from the AI Command Center feed
<ConfidenceBadge
  confidence={normalizeConfidence(decision.confidence_score, 'percentage')}
  reasoning={decision.reasoning}
  showMeter
/>
```

## ConfidenceMeter

The confidence glyph from [`../ICONOGRAPHY.md`](../ICONOGRAPHY.md) — a four-segment horizontal track whose
**filled-segment count encodes the band** (High = 4/4 solid, Medium = 2–3/4 solid, Low = ≤1/4 with the
remaining track drawn as a **hollow/dashed outline**), so the band is legible from shape alone before
color is considered. The fill animates in with a Framer Motion width tween, reduced-motion-safe. It is
`aria-hidden` by default (the adjacent `ConfidenceBadge` percentage carries the accessible value); when
used standalone it takes an `ariaLabel`.

| Prop | Type | Required | Description |
|---|---|---|---|
| `confidence` | `number` | yes | Canonical 0–1 (pre-normalized). |
| `size` | `'xs' \| 'sm' \| 'md'` | no (default `sm`) | Track length token: `xs` inline in a badge, `md` on a KPI tile. |
| `ariaLabel` | `string` | no | Present ⇒ the meter is `role="img"` and meaningful; absent ⇒ `aria-hidden`. |

```tsx
// components/ai/confidence-meter.tsx
'use client';
import { motion, useReducedMotion } from 'framer-motion';
import { confidenceBand } from '@/components/ai/confidence-badge';
import { cn } from '@/lib/utils';

const SEGMENTS = 4;
const TRACK = { xs: 'h-1 w-8', sm: 'h-1.5 w-12', md: 'h-2 w-16' } as const;

export function ConfidenceMeter({
  confidence, size = 'sm', ariaLabel,
}: { confidence: number; size?: keyof typeof TRACK; ariaLabel?: string }) {
  const reduced = useReducedMotion();
  const { band, tone } = confidenceBand(confidence);
  const filled = Math.max(1, Math.round(confidence * SEGMENTS)); // ≥1 so "low" is still visible
  const fillClass = tone === 'accent' ? 'bg-accent-600' : 'bg-ink-9';

  return (
    <span
      role={ariaLabel ? 'img' : undefined}
      aria-hidden={ariaLabel ? undefined : true}
      aria-label={ariaLabel}
      className="inline-flex items-center gap-0.5"
    >
      {Array.from({ length: SEGMENTS }).map((_, i) => {
        const isFilled = i < filled;
        return (
          <span
            key={i}
            className={cn(
              'rounded-full',
              TRACK[size].replace(/w-\S+/, 'w-2'),
              // shape encodes the band: unfilled segments are a hollow dashed outline, never a color-only cue
              isFilled ? '' : 'border border-dashed border-ink-6 bg-transparent',
            )}
          >
            {isFilled && (
              <motion.span
                className={cn('block h-full w-full rounded-full', fillClass, band === 'low' && 'opacity-90')}
                initial={reduced ? false : { scaleX: 0 }}
                animate={{ scaleX: 1 }}
                transition={reduced ? { duration: 0 } : { duration: 0.28, ease: [0.16, 1, 0.3, 1] }}
                style={{ transformOrigin: 'inline-start' }}
              />
            )}
          </span>
        );
      })}
    </span>
  );
}
```

```tsx
// Usage — standalone on a KPI tile whose value is an AI computation
<ConfidenceMeter confidence={0.94} size="md" ariaLabel={t('ai.confidenceValue', { pct: 94 })} />
```

## ReasoningPanel

The expandable rationale and citation surface. It is **collapsed by default** — a popover/disclosure,
never inline paragraph text competing with a card's primary content, so a feed of twenty insights stays
scannable. Expanded, it renders the AI's `reasoning` prose followed by the `sources` list; each source is
a **drill-through link** to the originating record (`journal_entries` #, `bank_transactions` #,
`documents` #), resolved through the same route map the rest of the app uses so a citation is a real
navigation, never a dead label.

| Prop | Type | Required | Description |
|---|---|---|---|
| `reasoning` | `string` | yes | The agent's rationale text, already localized by the API. |
| `sources` | `Array<{ type: string; id: number; label: string }>` | no | Cited source documents; each renders as a drill-through link via `sourceHref()`. |
| `defaultOpen` | `boolean` | no (default `false`) | Never `true` in a dense feed; may be `true` on a single focused detail view. |
| `variant` | `'popover' \| 'inline'` | no (default `popover`) | `inline` (an animated disclosure) is used only inside a chat message where space is not contended. |

```tsx
// components/ai/reasoning-panel.tsx
'use client';
import { useState } from 'react';
import Link from 'next/link';
import { motion, AnimatePresence, useReducedMotion } from 'framer-motion';
import { ChevronDown } from 'lucide-react';
import { Icon } from '@/components/ui/icon';
import { sourceHref } from '@/lib/ai/source-href'; // maps { type, id } → the record's route
import { useTranslations } from '@/lib/i18n';

interface ReasoningPanelProps {
  reasoning: string;
  sources?: Array<{ type: string; id: number; label: string }>;
  defaultOpen?: boolean;
  variant?: 'popover' | 'inline';
}

export function ReasoningPanel({ reasoning, sources = [], defaultOpen = false }: ReasoningPanelProps) {
  const [open, setOpen] = useState(defaultOpen);
  const reduced = useReducedMotion();
  const { t } = useTranslations('ai');

  return (
    <div className="mt-2">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex items-center gap-1 text-caption text-ink-500 hover:text-ink-950"
      >
        <motion.span animate={{ rotate: open ? 180 : 0 }} transition={{ duration: reduced ? 0 : 0.15 }}>
          <Icon icon={ChevronDown} size="xs" aria-hidden />
        </motion.span>
        {open ? t('reasoning.hide') : t('reasoning.show')}
      </button>

      <AnimatePresence initial={false}>
        {open && (
          <motion.div
            initial={reduced ? false : { height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={reduced ? { opacity: 0 } : { height: 0, opacity: 0 }}
            transition={{ duration: reduced ? 0 : 0.2 }}
            className="overflow-hidden"
          >
            <p className="mt-2 text-caption leading-relaxed text-ink-700">{reasoning}</p>
            {sources.length > 0 && (
              <ul className="mt-2 space-y-1" aria-label={t('reasoning.sources')}>
                {sources.map((s) => (
                  <li key={`${s.type}-${s.id}`}>
                    <Link
                      href={sourceHref(s)}
                      className="inline-flex items-center gap-1.5 text-caption text-accent-600 hover:underline"
                    >
                      <span className="font-mono tabular-nums text-ink-500" dir="ltr">#{s.id}</span>
                      <span>{s.label}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
```

## AgentAvatar & AgentAttributionChip

`AgentAvatar` renders *which of the fifteen agents* produced a payload — its **domain icon** (reused from
the [`../ICONOGRAPHY.md`](../ICONOGRAPHY.md) agent roster, so an agent's mark always agrees with the module
it works in) centered inside a 28px circle filled with a 10%-opacity `--primary` tint. It is never a
photographic avatar, never a per-agent color scheme, and never an illustrated mascot — the taste bar
forbids all three. `AgentAttributionChip` composes the avatar with the agent's localized name and, when
relevant, its `ConfidenceBadge`.

`AgentAvatar` props:

| Prop | Type | Required | Description |
|---|---|---|---|
| `agentCode` | `AgentCode` | yes | One of the fifteen `agent_code`s (`GENERAL_ACCOUNTANT`, `CFO_AGENT`, `FRAUD_DETECTION`, …). |
| `size` | `'sm' \| 'md'` | no (default `md`) | `sm` = 24px (chat), `md` = 28px (cards). |

`AgentAttributionChip` props:

| Prop | Type | Required | Description |
|---|---|---|---|
| `agentCode` | `AgentCode` | yes | Producing agent. |
| `confidence` | `number \| null` | no | Canonical 0–1; renders a `sm` `ConfidenceBadge` when present. |
| `reasoning` | `string` | no | Passed to the badge's tooltip. |

```tsx
// components/ai/agent-avatar.tsx
import { Icon } from '@/components/ui/icon';
import { AGENT_ICON, type AgentCode } from '@/lib/ai/agent-roster';
import { cn } from '@/lib/utils';

const CIRCLE = { sm: 'size-6', md: 'size-7' } as const;

export function AgentAvatar({ agentCode, size = 'md' }: { agentCode: AgentCode; size?: keyof typeof CIRCLE }) {
  const glyph = AGENT_ICON[agentCode]; // Sparkles-family domain icon per ICONOGRAPHY roster
  return (
    <span
      className={cn('inline-flex shrink-0 items-center justify-center rounded-full bg-accent-100', CIRCLE[size])}
      aria-hidden
    >
      <Icon icon={glyph} size="sm" className="text-accent-700" />
    </span>
  );
}
```

```tsx
// components/ai/agent-attribution-chip.tsx
import { AgentAvatar } from '@/components/ai/agent-avatar';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { AGENT_NAME_KEY, type AgentCode } from '@/lib/ai/agent-roster';
import { useTranslations } from '@/lib/i18n';

export function AgentAttributionChip({
  agentCode, confidence, reasoning,
}: { agentCode: AgentCode; confidence?: number | null; reasoning?: string }) {
  const { t } = useTranslations('ai');
  return (
    <span className="inline-flex items-center gap-2">
      <AgentAvatar agentCode={agentCode} size="sm" />
      <span className="text-caption text-ink-700">{t(AGENT_NAME_KEY[agentCode])}</span>
      {confidence != null && <ConfidenceBadge confidence={confidence} size="sm" showLabel={false} reasoning={reasoning} />}
    </span>
  );
}
```

## AiCardShell

The mandatory visual envelope every AI-authored card renders through, so the "colored border + `Sparkles`
'AI' badge + confidence + reasoning" rule cannot drift screen to screen. Its provenance treatment is three
redundant, non-color cues stacked together (per [`../ICONOGRAPHY.md → Color & State`](../ICONOGRAPHY.md)):
the `Sparkles` glyph, an explicit "AI" label, and a leading accent border — all in the *same* one accent
already used for interactive elements, never a second "AI purple." Severity (a critical risk, a held
approval) is carried by the `negative` semantic token elsewhere on the card, never by the accent, so "the
AI touched this" and "this is urgent" stay two independent signals.

| Prop | Type | Required | Description |
|---|---|---|---|
| `decision` | `AiDecision` | yes | `{ agent_code, confidence_score, reasoning, sources, ... }` — the shape every AI payload shares. |
| `severity` | `'none' \| 'warning' \| 'critical'` | no (default `none`) | Drives the leading-edge border token (`ink-6` → `warning` → `negative`), independent of the accent. |
| `children` | `ReactNode` | yes | The card's own primary content. |

```tsx
// components/ai/ai-card-shell.tsx
import { Sparkles } from 'lucide-react';
import { Icon } from '@/components/ui/icon';
import { Badge } from '@/components/ui/badge';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { AgentAttributionChip } from '@/components/ai/agent-attribution-chip';
import { ReasoningPanel } from '@/components/ai/reasoning-panel';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/lib/i18n';
import type { AiDecision } from '@/types/ai';

const EDGE = { none: 'border-s-accent-600', warning: 'border-s-warning', critical: 'border-s-negative' } as const;

export function AiCardShell({
  decision, severity = 'none', children,
}: { decision: AiDecision; severity?: keyof typeof EDGE; children: React.ReactNode }) {
  const { t } = useTranslations('ai');
  const confidence = normalizeConfidence(decision.confidence_score, 'percentage');
  return (
    <div className={cn('rounded-lg border border-ink-6 border-s-4 bg-ink-2 p-4 dark:bg-ink-3', EDGE[severity])}>
      <div className="mb-2 flex items-center gap-2">
        <Badge tone="accent" className="gap-1">
          <Icon icon={Sparkles} size="xs" aria-hidden /> {t('badge')}
        </Badge>
        <ConfidenceBadge confidence={confidence} size="sm" reasoning={decision.reasoning} />
        <AgentAttributionChip agentCode={decision.agent_code} />
      </div>
      {children}
      <ReasoningPanel reasoning={decision.reasoning} sources={decision.sources} />
    </div>
  );
}
```

## AIProposalCard (RecommendationCard)

One actionable `ai_decisions` row rendered with the **three-button pattern** — `Do it` /
`Send for approval` / `Dismiss`. Which of the three appear is computed server-side via
`can_execute_directly`; the client never re-derives it. This is the component that makes the
human-in-the-loop guarantee structurally true rather than a convention.

| Prop | Type | Required | Description |
|---|---|---|---|
| `decision` | `AiDecisionWithAction` | yes | Adds `recommended_action`, `alternatives`, `can_execute_directly` to `AiDecision`. |
| `onExecute` | `() => Promise<void>` | conditional | Wired only when `can_execute_directly`; the `Do it` handler. Backs `POST /api/v1/ai/decisions/{id}/execute`. |
| `onSendForApproval` | `() => Promise<void>` | yes | Opens/creates the `ApprovalCard` flow for this decision. |
| `onDismiss` | `(reason: string) => Promise<void>` | yes | Reason required; stored as `rejected_reason`, fed back into `ai_memory`. |

```tsx
// components/ai/recommendation-card.tsx ("use client")
'use client';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { AiCardShell } from '@/components/ai/ai-card-shell';
import { AlternativesList } from '@/components/ai/alternatives-list';
import { DismissDialog } from '@/components/ai/dismiss-dialog';
import { usePermission } from '@/hooks/use-permission';
import { normalizeConfidence } from '@/components/ai/confidence-badge';
import { useTranslations } from '@/lib/i18n';
import type { AiDecisionWithAction } from '@/types/ai';

const AUTO_EXECUTE_THRESHOLD = 0.9; // UI-side mirror of the company-configured server threshold

export function AIProposalCard({
  decision, onExecute, onSendForApproval, onDismiss,
}: {
  decision: AiDecisionWithAction;
  onExecute?: () => Promise<void>;
  onSendForApproval: () => Promise<void>;
  onDismiss: (reason: string) => Promise<void>;
}) {
  const { t } = useTranslations('ai');
  const canApprove = usePermission('ai.approve');
  const [dismissOpen, setDismissOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const confidence = normalizeConfidence(decision.confidence_score, 'percentage');

  // Server decides can_execute_directly; the client additionally never offers one-click below 0.60.
  const offerDoIt = decision.can_execute_directly && confidence >= AUTO_EXECUTE_THRESHOLD && Boolean(onExecute);

  return (
    <AiCardShell decision={decision}>
      <p className="text-body text-ink-950">{decision.recommended_action.action}</p>
      <AlternativesList alternatives={decision.alternatives} />
      <div className="mt-3 flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={() => setDismissOpen(true)}>{t('dismiss')}</Button>
        <Button variant="outline" size="sm" disabled={!canApprove} onClick={onSendForApproval}>
          {t('sendForApproval')}
        </Button>
        {offerDoIt && (
          <Button
            size="sm"
            loading={busy}
            disabled={!canApprove}
            onClick={async () => { setBusy(true); await onExecute!(); setBusy(false); }}
          >
            {t('doIt')}
          </Button>
        )}
      </div>
      <DismissDialog open={dismissOpen} onOpenChange={setDismissOpen} onConfirm={onDismiss} requireReason />
    </AiCardShell>
  );
}
```

## AIProposalPanel

The fuller rendering used on the Command Center's Recommendations feed: recommended action, at least one
**named alternative with its tradeoff** (QAYD deliberately never wants users to develop reflexive trust in
a single accept button), confidence, and the same three interactions. See
[`../COMPONENT_LIBRARY.md → AIProposalPanel`](../COMPONENT_LIBRARY.md) for the canonical implementation;
its prop surface:

| Prop | Type | Required | Description |
|---|---|---|---|
| `decision` | `AIDecision` | yes | `{ id, agentCode, confidenceScore, recommendedAction, alternatives: {action, tradeoff}[], reasoning, sources, autonomyLevel }`. |
| `autonomyLevel` | `'auto' \| 'suggest_only' \| 'requires_approval'` | yes | Determines whether "Do it" renders at all. |
| `onAccept` | `() => Promise<void>` | yes | Callable only when `autonomyLevel === 'auto'` **and** confidence clears `AUTO_EXECUTE_THRESHOLD`. |
| `onSendForApproval` | `() => Promise<void>` | yes | Opens the target action's `ApprovalCard` flow pre-attached to this decision. |
| `onDismiss` | `(reason: string) => Promise<void>` | yes | Reason required. |

`AIProposalCard` and `AIProposalPanel` are two densities of one contract — the card is the compact,
feed-embeddable form; the panel is the expanded form with the alternatives list inline and the reasoning
disclosure always present. A screen picks one; it never renders both for the same decision.

## ApprovalCard

The shared human-gate for any sensitive action — AI recommendation, journal entry, bank transfer,
payroll release, tax submission — behind a single `kind` discriminant. See
[`../COMPONENT_LIBRARY.md → ApprovalCard`](../COMPONENT_LIBRARY.md) for the canonical implementation.
Its AI-relevant prop surface:

| Prop | Type | Required | Description |
|---|---|---|---|
| `kind` | `'journal_entry' \| 'ai_recommendation' \| 'bank_transfer' \| 'payroll_release' \| 'tax_submission'` | yes | Determines the permission key checked (`ai.approve` for `ai_recommendation`, `bank.transfer`, `payroll.approve`, `tax.submit`), the icon, and the detail link. |
| `title` | `string` | yes | e.g. `"JE-2026-07-0482 · Rent accrual"`. |
| `amount` | `{ value: string; currencyCode: string }` | no | Rendered via `AmountCell`, in plain ink — never washed in `negative` for a raw magnitude. |
| `requestedBy` | `{ name: string; avatarUrl?: string }` | no | Omitted for pure AI proposals — attribution there is `agent_code`, surfaced via `AgentAttributionChip`, not a human name. |
| `approvalLevel` | `{ current: number; total: number }` | no | Renders "Approval 1 of 2" as real text, never a color-only progress bar. |
| `confidence` | `number \| null` | no | 0–1; populated **only** for AI-originated requests, passed to `ConfidenceBadge`. |
| `onApprove` / `onReject` / `onDelegate` | callbacks | yes / yes / no | `onReject`'s reason is mandatory; `onDelegate` renders only when the caller is the assigned approver for the current level. |

The internal Approve/Reject/Delegate cluster is factored into `ApprovalActionBar` (next) so the identical
control set can be embedded inline in a chat message where a full card would be too heavy.

## ApprovalActionBar

The reusable Approve / Reject / Delegate control cluster. Approve is the one filled `accent` action;
Reject uses `destructive-quiet` (never the loud `destructive`, reserved for permanent deletion); Delegate
is `ghost` and renders only when `onDelegate` is provided. Reject opens a mandatory-reason dialog whose
`Confirm reject` stays disabled until the reason is non-empty (a platform-wide rule: every
rejection/dismissal carries a stored reason).

| Prop | Type | Required | Description |
|---|---|---|---|
| `permissionKey` | `string` | yes | The exact key gating this decision (`ai.approve`, `bank.transfer`, …); drives the disabled state + tooltip. |
| `onApprove` | `(note?: string) => Promise<void>` | yes | Approve handler; optimistically flips state, rolls back on `4xx/5xx`. |
| `onReject` | `(reason: string) => Promise<void>` | yes | Reason mandatory. |
| `onDelegate` | `(userId: number) => Promise<void>` | no | Renders the Delegate control only when provided. |
| `disabledReason` | `string` | no | Overrides the permission tooltip when the block is a business-state block (e.g. "waiting on step 1"), kept textually distinct from a permission block. |

```tsx
// components/ai/approval-action-bar.tsx ("use client")
'use client';
import { useState } from 'react';
import { Check, X, UserPlus } from 'lucide-react';
import { Icon } from '@/components/ui/icon';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { RejectDialog } from '@/components/ai/reject-dialog';
import { DelegatePopover } from '@/components/ai/delegate-popover';
import { usePermission } from '@/hooks/use-permission';
import { useTranslations } from '@/lib/i18n';

export function ApprovalActionBar({
  permissionKey, onApprove, onReject, onDelegate, disabledReason,
}: {
  permissionKey: string;
  onApprove: (note?: string) => Promise<void>;
  onReject: (reason: string) => Promise<void>;
  onDelegate?: (userId: number) => Promise<void>;
  disabledReason?: string;
}) {
  const { t } = useTranslations('ai');
  const canDecide = usePermission(permissionKey);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const blocked = !canDecide || Boolean(disabledReason);
  const blockedTip = disabledReason ?? t('requiresPermission', { permission: permissionKey });

  return (
    <div className="flex items-center justify-end gap-2">
      {onDelegate && (
        <DelegatePopover onDelegate={onDelegate} disabled={blocked}>
          <Button variant="ghost" size="sm"><Icon icon={UserPlus} size="sm" /> {t('delegate')}</Button>
        </DelegatePopover>
      )}
      <Button variant="destructive-quiet" size="sm" disabled={blocked} onClick={() => setRejectOpen(true)}>
        <Icon icon={X} size="sm" /> {t('reject')}
      </Button>
      <Tooltip>
        <TooltipTrigger asChild>
          <span>
            <Button
              size="sm"
              loading={busy}
              disabled={blocked}
              aria-describedby={blocked ? 'approve-blocked-reason' : undefined}
              onClick={async () => { setBusy(true); await onApprove(); setBusy(false); }}
            >
              <Icon icon={Check} size="sm" /> {t('approve')}
            </Button>
          </span>
        </TooltipTrigger>
        {blocked && <TooltipContent id="approve-blocked-reason">{blockedTip}</TooltipContent>}
      </Tooltip>
      <RejectDialog open={rejectOpen} onOpenChange={setRejectOpen} onConfirm={onReject} />
    </div>
  );
}
```

## AIDraftField

A form field pre-filled by an AI agent — a journal line's account suggestion, a tax code proposed for a
bill, an OCR-extracted amount — that shows its **provenance and confidence** and is **never
auto-committed**. The AI-suggested value is loaded into the field but styled distinctly (an accent
`Sparkles` affordance and a `ConfidenceBadge`) until the human either edits it or explicitly accepts it;
the form submits the value the *human* leaves, and until then the field is marked "suggested," never
"set." Backed by `journal_lines.ai_confidence` / `accounts.ai_suggestion_confidence` (the 0–1 scale, so
`sourceField="fraction"`).

| Prop | Type | Required | Description |
|---|---|---|---|
| `label` | `string` | yes | Field label. |
| `children` | `ReactNode` | yes | The wrapped input control (an `AccountPicker`, `Input`, `Select`). |
| `suggestion` | `{ confidence: number; agentCode: AgentCode; reasoning: string } \| null` | yes | Present ⇒ the field is AI-drafted; `null` ⇒ a plain field. |
| `accepted` | `boolean` | yes | Whether the human has confirmed the suggestion; flips the field from "suggested" styling to plain. |
| `onAcceptSuggestion` | `() => void` | yes | Marks the current value human-accepted. |
| `onDismissSuggestion` | `() => void` | yes | Clears the AI value so the human enters their own. |

```tsx
// components/ai/ai-draft-field.tsx
import { Sparkles } from 'lucide-react';
import { Icon } from '@/components/ui/icon';
import { Button } from '@/components/ui/button';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { AgentAttributionChip } from '@/components/ai/agent-attribution-chip';
import { cn } from '@/lib/utils';
import { useTranslations } from '@/lib/i18n';
import type { AgentCode } from '@/lib/ai/agent-roster';

interface AIDraftFieldProps {
  label: string;
  children: React.ReactNode;
  suggestion: { confidence: number; agentCode: AgentCode; reasoning: string } | null;
  accepted: boolean;
  onAcceptSuggestion: () => void;
  onDismissSuggestion: () => void;
}

export function AIDraftField({
  label, children, suggestion, accepted, onAcceptSuggestion, onDismissSuggestion,
}: AIDraftFieldProps) {
  const { t } = useTranslations('ai');
  const isDrafted = suggestion != null && !accepted;

  return (
    <div className="space-y-1.5">
      <div className="flex items-center justify-between">
        <label className="text-caption font-medium text-ink-700">{label}</label>
        {isDrafted && (
          <span className="inline-flex items-center gap-2">
            <Icon icon={Sparkles} size="xs" className="text-accent-600" aria-hidden />
            <span className="text-[11px] text-ink-500">{t('suggested')}</span>
            {/* fraction scale — never a raw 0-100 field here */}
            <ConfidenceBadge confidence={suggestion.confidence} size="sm" showLabel={false} reasoning={suggestion.reasoning} />
          </span>
        )}
      </div>

      {/* the accent dashed ring marks a not-yet-committed AI draft; a plain/accepted field has none */}
      <div className={cn('rounded-md', isDrafted && 'ring-1 ring-dashed ring-accent-600/40')}>{children}</div>

      {isDrafted && (
        <div className="flex items-center justify-between">
          <AgentAttributionChip agentCode={suggestion.agentCode} />
          <div className="flex gap-2">
            <Button variant="ghost" size="sm" onClick={onDismissSuggestion}>{t('clear')}</Button>
            <Button variant="outline" size="sm" onClick={onAcceptSuggestion}>{t('useSuggestion')}</Button>
          </div>
        </div>
      )}
    </div>
  );
}
```

The field never posts anything on its own. Accepting a suggestion mutates only local form state; the
value still travels through the ordinary form submission (and its own `accounting.journal.post` gate) like
any hand-typed value — the AI drafted it, the human committed it.

## InsightCard

A read-only atomic observation from the `ai_insights` feed — the shape behind the Dashboard's **Top
Recommended Actions** strip and the full AI Insights feed. An insight carries no action of its own; if its
`recommended_action` field is populated it **graduates** to rendering through `AIProposalCard` instead
(the split is strictly field-presence-driven, never a styling toggle).

| Prop | Type | Required | Description |
|---|---|---|---|
| `insight` | `AiInsight` | yes | `{ id, agent_code, title, body, confidence_score, reasoning, sources, recommended_action? }`. |
| `onExplainMore` | `() => void` | no | Seeds `AskAIWidget` with this insight's `sources` as context. |
| `onFeedback` | `(useful: boolean) => Promise<void>` | no | `POST /api/v1/ai/insights/{id}/feedback`; "Not useful" optimistically removes the card with a 5s Undo. |

```tsx
// components/ai/insight-card.tsx ("use client")
'use client';
import { Button } from '@/components/ui/button';
import { AiCardShell } from '@/components/ai/ai-card-shell';
import { AIProposalCard } from '@/components/ai/recommendation-card';
import { useTranslations } from '@/lib/i18n';
import type { AiInsight } from '@/types/ai';

export function InsightCard({
  insight, onExplainMore, onFeedback, ...proposal
}: { insight: AiInsight; onExplainMore?: () => void; onFeedback?: (useful: boolean) => Promise<void> } & Record<string, unknown>) {
  const { t } = useTranslations('ai');

  // field-presence-driven graduation: an actionable insight is a proposal, not a read-only card
  if (insight.recommended_action) {
    return <AIProposalCard decision={insight as never} {...(proposal as never)} />;
  }

  return (
    <AiCardShell decision={insight}>
      <p className="text-body font-medium text-ink-950">{insight.title}</p>
      <p className="text-caption text-ink-700">{insight.body}</p>
      <div className="mt-2 flex gap-2">
        {onExplainMore && <Button variant="ghost" size="sm" onClick={onExplainMore}>{t('explainMore')}</Button>}
        {onFeedback && <Button variant="ghost" size="sm" onClick={() => onFeedback(false)}>{t('notUseful')}</Button>}
      </div>
    </AiCardShell>
  );
}
```

## RiskCard

One `ai_risk_flags` row — the shape behind the Dashboard's **Top Risks** and the Detected Risks radar.
Grouped severity-first (critical → high → medium → low) by its container, each card renders through
`AiCardShell` with a `severity` that drives the leading-edge token in the `negative`/`warning` scale
(never the accent — "the AI touched this" and "this is urgent" stay separate signals). Its actions are
**Acknowledge**, **Resolve** (opens a dialog requiring `resolution_note`), and **Dismiss as false
positive** (requires a reason). A `critical`-severity flag's dismiss is enabled only when the caller holds
`ai.approve`, checked client-side before the dialog even opens and re-checked by the mutation's `403`.

| Prop | Type | Required | Description |
|---|---|---|---|
| `risk` | `AiRiskFlag` | yes | `{ id, source_agent_code, severity, title, financial_exposure?, confidence_score, reasoning, sources }`. |
| `onAcknowledge` | `() => Promise<void>` | yes | Marks the flag seen. |
| `onResolve` | `(note: string) => Promise<void>` | yes | Requires a `resolution_note`. |
| `onDismissFalsePositive` | `(reason: string) => Promise<void>` | yes | Requires a reason; `critical` gated on `ai.approve`. |

```tsx
// components/ai/risk-card.tsx ("use client")
'use client';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { AiCardShell } from '@/components/ai/ai-card-shell';
import { Amount } from '@/components/ui/amount';
import { ResolveDialog } from '@/components/ai/resolve-dialog';
import { DismissDialog } from '@/components/ai/dismiss-dialog';
import { usePermission } from '@/hooks/use-permission';
import { useTranslations } from '@/lib/i18n';
import type { AiRiskFlag } from '@/types/ai';

export function RiskCard({
  risk, onAcknowledge, onResolve, onDismissFalsePositive,
}: {
  risk: AiRiskFlag;
  onAcknowledge: () => Promise<void>;
  onResolve: (note: string) => Promise<void>;
  onDismissFalsePositive: (reason: string) => Promise<void>;
}) {
  const { t } = useTranslations('ai');
  const canApprove = usePermission('ai.approve');
  const [resolveOpen, setResolveOpen] = useState(false);
  const [dismissOpen, setDismissOpen] = useState(false);
  const dismissBlocked = risk.severity === 'critical' && !canApprove;

  return (
    <AiCardShell
      decision={{ ...risk, agent_code: risk.source_agent_code }}
      severity={risk.severity === 'critical' ? 'critical' : risk.severity === 'high' ? 'warning' : 'none'}
    >
      <p className="text-body text-ink-950">{risk.title}</p>
      {/* raw magnitude renders in plain ink — severity is already carried by the shell's border/badge */}
      {risk.financial_exposure && <Amount value={Number(risk.financial_exposure)} currency="KWD" />}
      <div className="mt-2 flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onAcknowledge}>{t('acknowledge')}</Button>
        <Button variant="outline" size="sm" onClick={() => setResolveOpen(true)}>{t('resolve')}</Button>
        <Button
          variant="destructive-quiet"
          size="sm"
          disabled={dismissBlocked}
          title={dismissBlocked ? t('requiresPermission', { permission: 'ai.approve' }) : undefined}
          onClick={() => setDismissOpen(true)}
        >
          {t('dismissFalsePositive')}
        </Button>
      </div>
      <ResolveDialog open={resolveOpen} onOpenChange={setResolveOpen} onConfirm={onResolve} />
      <DismissDialog open={dismissOpen} onOpenChange={setDismissOpen} onConfirm={onDismissFalsePositive} requireReason />
    </AiCardShell>
  );
}
```

## AskAIWidget

The inline assistant — the docked rendering of the one conversational surface (`/assistant`,
[`../AI_CHAT.md`](../AI_CHAT.md)). It is the *same* transcript, composer, and message contract as the full
page, rendered in a `Sheet` (below `3xl`) or a persistent rail (`3xl`+), pre-scoped to whatever panel it
was opened from. There is one composer, one send action, one place a reply appears; a reply that resolves
to an implied action renders the identical three-button set as `AIProposalCard`, never a second competing
pattern. Gated by `ai.chat`.

| Prop | Type | Required | Description |
|---|---|---|---|
| `seedContext` | `{ panel: string; sources?: Source[] } \| null` | no | Present when opened from a panel; renders a "Scoped to: {panel}" strip and seeds the first turn. |
| `container` | `'sheet' \| 'rail'` | yes | `sheet` below `3xl`, `rail` at `3xl`+. |
| `conversationId` | `string \| null` | no | Resumes a saved thread; `null` starts a fresh, unsaved conversation. |

```tsx
// components/ai/ask-ai-widget.tsx ("use client")
'use client';
import { useChat } from 'ai/react';               // Vercel AI SDK, SSE streaming — reused verbatim from /assistant
import { PermissionGate } from '@/components/shared/permission-gate';
import { AiMessageBubble } from '@/components/ai/ai-message-bubble';
import { AiTypingIndicator } from '@/components/ai/ai-typing-indicator';
import { AiUnavailable } from '@/components/ai/ai-unavailable';
import { ChatComposer } from '@/components/ai/chat-composer';
import { useTranslations } from '@/lib/i18n';
import type { Source } from '@/types/ai';

export function AskAIWidget({
  seedContext, container, conversationId,
}: { seedContext?: { panel: string; sources?: Source[] } | null; container: 'sheet' | 'rail'; conversationId?: string | null }) {
  const { t } = useTranslations('ai');
  const { messages, input, handleInputChange, handleSubmit, status, error } = useChat({
    api: '/api/ai/chat',                            // SSE proxy forwards the httpOnly bearer server-side
    id: conversationId ?? undefined,
    body: { context: seedContext?.sources ?? null },
  });

  return (
    <PermissionGate permission="ai.chat" fallback={null}>
      <section className={container === 'rail' ? 'flex h-full flex-col' : 'flex flex-col'} aria-label={t('assistant.title')}>
        {seedContext && (
          <p className="border-b border-ink-6 px-4 py-2 text-caption text-ink-500">
            {t('assistant.scopedTo', { panel: seedContext.panel })}
          </p>
        )}
        <div className="flex-1 space-y-3 overflow-y-auto p-4" aria-live="polite">
          {messages.map((m) => <AiMessageBubble key={m.id} message={m} />)}
          {status === 'streaming' && <AiTypingIndicator />}
          {error && <AiUnavailable onRetry={() => handleSubmit()} variant="chat" />}
        </div>
        <ChatComposer value={input} onChange={handleInputChange} onSubmit={handleSubmit} disabled={status !== 'ready'} />
      </section>
    </PermissionGate>
  );
}
```

## AiTypingIndicator

The calm "AI is generating" indicator for any in-progress AI generation — three `accent-subtle` dots
pulsing opacity `0.3 → 1 → 0.3` in a 1.2s staggered loop, deliberately slower and quieter than a typical
chat-app typing indicator. Under `prefers-reduced-motion` it collapses to a static three-dot glyph plus a
visually-hidden "Generating…" live-region announcement (never a bare animation with no text fallback).

| Prop | Type | Required | Description |
|---|---|---|---|
| `label` | `string` | no | Overrides the default "Generating…" accessible label (e.g. "Drafting entry…"). |

```tsx
// components/ai/ai-typing-indicator.tsx
'use client';
import { motion, useReducedMotion } from 'framer-motion';
import { useTranslations } from '@/lib/i18n';

export function AiTypingIndicator({ label }: { label?: string }) {
  const reduced = useReducedMotion();
  const { t } = useTranslations('ai');
  const text = label ?? t('generating');
  return (
    <div role="status" aria-live="polite" className="inline-flex items-center gap-1">
      <span className="sr-only">{text}</span>
      {[0, 1, 2].map((i) => (
        <motion.span
          key={i}
          aria-hidden
          className="size-1.5 rounded-full bg-accent-subtle"
          animate={reduced ? undefined : { opacity: [0.3, 1, 0.3] }}
          transition={reduced ? undefined : { duration: 1.2, repeat: Infinity, delay: i * 0.15, ease: 'easeInOut' }}
        />
      ))}
    </div>
  );
}
```

## AutomationStatusChip

The status of the automation rule behind an auto-executed AI action — the surface that makes an
`ai.automation`-governed rule *visible* wherever its output appears (a Command Center row that executed
overnight, an audit-trail entry). It renders the rule's state (`active` / `paused` / `throttled` /
`disabled`) and, for `ai.automation` holders, a manage affordance into the Automation Center. It never
implies an automation *ran without a human*: an automation that touched money still passed a policy gate;
the chip states which rule and at what threshold.

| Prop | Type | Required | Description |
|---|---|---|---|
| `rule` | `{ id: number; name: string; status: 'active' \| 'paused' \| 'throttled' \| 'disabled'; threshold: number }` | yes | The `ai_automation_rules` row that authorized the action. |
| `onManage` | `() => void` | no | Rendered only for `ai.automation` holders; deep-links into the Automation Center. |

```tsx
// components/ai/automation-status-chip.tsx
import { Zap } from 'lucide-react';
import { Icon } from '@/components/ui/icon';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { usePermission } from '@/hooks/use-permission';
import { useTranslations } from '@/lib/i18n';

const TONE = { active: 'success', paused: 'warning', throttled: 'warning', disabled: 'neutral' } as const;

export function AutomationStatusChip({
  rule, onManage,
}: { rule: { id: number; name: string; status: keyof typeof TONE; threshold: number }; onManage?: () => void }) {
  const { t } = useTranslations('ai');
  const canManage = usePermission('ai.automation');

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          onClick={canManage ? onManage : undefined}
          className="inline-flex items-center"
          aria-label={t('automation.aria', { name: rule.name, status: t(`automation.status.${rule.status}`) })}
        >
          <Badge tone={TONE[rule.status]} className="gap-1">
            <Icon icon={Zap} size="xs" aria-hidden /> {t(`automation.status.${rule.status}`)}
          </Badge>
        </button>
      </TooltipTrigger>
      <TooltipContent>
        {t('automation.tooltip', { name: rule.name, threshold: Math.round(rule.threshold * 100) })}
      </TooltipContent>
    </Tooltip>
  );
}
```

## AiUnavailable

The distinct AI-degraded / offline fallback — rendered when an AI-only endpoint returns `503`. It is
never a generic `ErrorState` and never an infinite spinner, matching the platform's guidance that AI-only
endpoints fail fast with `Retry-After`. It states plainly that AI insights are temporarily unavailable and
offers a retry that backs off on the `Retry-After` header when present; the rest of the screen (any
non-AI content) renders normally.

| Prop | Type | Required | Description |
|---|---|---|---|
| `variant` | `'panel' \| 'chat' \| 'inline'` | yes | Sizing/copy: a whole panel, a chat send failure, or an inline draft-field degrade. |
| `retryAfterSeconds` | `number \| null` | no | From the `Retry-After` header; disables the retry button until elapsed. |
| `onRetry` | `() => void` | yes | Re-runs the failed query/mutation. |

```tsx
// components/ai/ai-unavailable.tsx
import { CloudOff } from 'lucide-react';
import { Icon } from '@/components/ui/icon';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';

export function AiUnavailable({
  variant, retryAfterSeconds, onRetry,
}: { variant: 'panel' | 'chat' | 'inline'; retryAfterSeconds?: number | null; onRetry: () => void }) {
  const { t } = useTranslations('ai');
  const waiting = (retryAfterSeconds ?? 0) > 0;
  return (
    <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-ink-6 p-4 text-center">
      <Icon icon={CloudOff} size={variant === 'panel' ? '2xl' : 'md'} className="text-ink-7" aria-hidden />
      <p className="text-caption text-ink-700">{t('unavailable.title')}</p>
      <Button variant="outline" size="sm" disabled={waiting} onClick={onRetry}>
        {waiting ? t('unavailable.retryIn', { seconds: retryAfterSeconds }) : t('unavailable.retry')}
      </Button>
    </div>
  );
}
```

# States

Every AI widget ships an explicit treatment for each state below as a matter of house style — no widget
falls through to a bare blank region, and none conveys a state by color alone.

| Widget | Default | Loading | Empty | Low-confidence | Degraded (`503`) | Disabled (RBAC) |
|---|---|---|---|---|---|---|
| `ConfidenceBadge` | Pill + band label + optional meter | — (renders once value resolves) | N/A (never renders without a value) | `neutral` tone, "Low confidence" label, hollow meter track | Not rendered; parent shows `AiUnavailable` | N/A (read-only) |
| `ConfidenceMeter` | Segmented fill by band | Static empty track | N/A | ≤ ⅓ filled + hollow/dashed remaining track | Not rendered | N/A |
| `ReasoningPanel` | Collapsed disclosure | Disclosure disabled until reasoning loads | "No rationale recorded" (rare; a valid AI payload always carries one) | Unchanged — low confidence still shows its reasoning | Not rendered | N/A |
| `AgentAvatar` / chip | Domain glyph + name | Skeleton circle | N/A | Unchanged | Not rendered | N/A |
| `AiCardShell` | Accent edge + AI badge + confidence + reasoning | `Skeleton` shaped to the card | Host decides (no payload ⇒ no shell) | Confidence badge shows Low band; card otherwise unchanged | Replaced by `AiUnavailable variant="panel"` | N/A |
| `AIProposalCard` / `Panel` | Three buttons per `can_execute_directly` | Buttons show `loading` on the acting one | N/A | **"Do it" absent** (below 0.60); Send-for-approval + Dismiss remain | `AiUnavailable` in place | Send/Do-it disabled + tooltip when `ai.approve` absent |
| `ApprovalCard` / `ActionBar` | Approve / Reject / Delegate | Acting button `loading`; others locked | "Nothing waiting on you" (queue-level) | Confidence badge Low; approval still requires the human | Queue shows retry, never a stale silent trust | Disabled + tooltip naming the missing permission (`ai.approve`/`bank.transfer`/…) |
| `AIDraftField` | Suggested styling + accept/clear | Field spinner in the input slot | Plain field (no suggestion) | Low-band badge; suggestion still shown, never auto-accepted | Field degrades to a plain, human-only input with an inline `AiUnavailable variant="inline"` note | N/A (editing is the human's own permission) |
| `InsightCard` | Read-only card, or graduates to proposal | Feed skeleton rows | "No new insights since your last visit" | Low-band badge; unchanged otherwise | `AiUnavailable variant="panel"` | Feedback/actions per permission |
| `RiskCard` | Severity-edged card + actions | Severity-grouped skeleton rows | "No open risks" + calm line-drawn check glyph | Low-band badge on the flag | `AiUnavailable` | Critical-dismiss disabled + tooltip when `ai.approve` absent |
| `AskAIWidget` | Composer + transcript | `AiTypingIndicator` while streaming | Contextual suggested-question chips | Answer renders as plain prose, no badge, when the model states it can't answer confidently | `AiUnavailable variant="chat"` on send failure, user's message preserved | Composer hidden when `ai.chat` absent |
| `AutomationStatusChip` | Status badge (+ manage for holders) | Skeleton chip | N/A (only renders when a rule authorized the action) | N/A | N/A | Manage affordance absent for non-`ai.automation` holders |
| `AiTypingIndicator` | Three pulsing dots | — (it *is* the loading state) | N/A | N/A | Replaced by `AiUnavailable` on failure | N/A |

A note on the difference between an *absent* control and a *disabled* one, because the two encode
different facts and this document's widgets never conflate them: a **"Do it" withheld by
`can_execute_directly: false` (or confidence below 0.60) does not render at all** — there is no
client-side path to execute, accessible or otherwise. A **control disabled for a permission reason** (a
Sales Employee viewing an Approval Center row) *does* render, greyed with a tooltip naming the missing
permission — the user should understand the action exists but isn't currently theirs to take. The former
is a structural guarantee; the latter is an explanation.

# Behavior & Interaction

**The three-button decision, end to end.** A proposal offers, at most, `Do it` / `Send for approval` /
`Dismiss`. `Do it` fires the execute mutation (`POST /api/v1/ai/decisions/{id}/execute`) with a
client-generated `Idempotency-Key`, optimistically flips the card to an "executed" state, and rolls back
on any `4xx/5xx`. `Send for approval` creates/opens the `ApprovalCard` flow pre-attached to the decision,
handing the item into the same human-gate every sensitive action passes through — the proposal is not
executed, it is *queued for a human*. `Dismiss` requires a non-empty reason (stored as `rejected_reason`,
fed back into `ai_memory` so the agent learns), and optimistically removes the card with a 5-second Undo
toast before the mutation commits.

**Approve / Reject / Delegate.** Approve is optimistic-and-reversible-adjacent (a wrong approval can still
be caught at the next step or reversed procedurally), so it flips the step in the TanStack Query cache
immediately and reconciles on the server response. The *money-moving execution the approval eventually
authorizes* (a bank transfer's own settlement) is deliberately **not** optimistic — the two are treated
with different optimism on purpose. Reject requires a reason before `Confirm reject` un-disables. Delegate
reassigns the current approval step to another eligible approver and renders only when the caller is the
assigned approver for that level.

**Reasoning is pull, not push.** `ReasoningPanel` is collapsed by default everywhere it appears in a feed,
so twenty insights stay scannable; the reasoning is one keyboard-reachable disclosure away, and its cited
sources are real drill-through links. A card never front-loads a wall of rationale over its primary
content.

**AI draft fields never self-commit.** Loading an AI suggestion into a field is a local-state operation
only; the value travels through the ordinary form submission and its own permission gate exactly like a
hand-typed value. "Use suggestion" marks the value human-accepted (dropping the drafted styling); "Clear"
removes the AI value entirely. There is no code path in which an `AIDraftField` posts anything.

**Assistant hand-offs are context, never a second address bar.** Opening `AskAIWidget` from a panel seeds
the conversation with that panel's `sources`; a `⌘K` "Ask AI" action hands free text to the same widget
rather than a competing input. A user never selects a specialist agent — routing to a specialist is the
orchestrator's job at request time.

# Realtime & Streaming

AI content is inherently push-driven — an agent finishes work overnight or mid-session and the UI must
reflect it without a manual refresh. Every AI widget is fed by the platform's realtime layer (Laravel
Reverb server, Laravel Echo browser client) over the private, company-scoped **`.ai` channel**
(`private-company.{companyId}.ai`), with related work-status on `.ai-jobs` and dashboard patches on
`.dashboard`, subscribed once in the authenticated app shell's `RealtimeProvider` and torn down on
company switch or sign-out (never one connection per widget).

**Streaming proposals.** A newly generated `ai_decisions` or `ai_risk_flags` row arrives as an event on
`.ai`; the Echo listener merges it directly into the relevant TanStack Query cache entry via
`queryClient.setQueryData` (an insert), rather than a blunt full-list invalidation that would disrupt
scroll position. The affected surface animates the arrival calmly — the new row's background flashes to
`accent-subtle` then eases back over 600ms, no toast, no sound, no layout shift — per the platform's
"realtime row update" motion pattern. A Reverb push that lands while a user is mid-read never yanks focus
or splices a row into what a screen reader has already indexed; it surfaces as a polite, dismissible
"3 new — Refresh" banner instead.

```ts
// lib/ai/use-ai-channel.ts — one subscription, cache patches per event
export function useAiChannel(companyId: string) {
  const qc = useQueryClient();
  useEffect(() => {
    const ch = echo.private(`company.${companyId}.ai`);
    ch.listen('.ai.decision.created', (e: { decision: AiDecision }) =>
      qc.setQueryData(aiKeys.insights('latest'), (old: AiInsight[] = []) => [e.decision as AiInsight, ...old]));
    ch.listen('.ai.risk.flagged', (e: { risk: AiRiskFlag }) =>
      qc.setQueryData(aiKeys.risks('all'), (old: AiRiskFlag[] = []) => insertBySeverity(old, e.risk)));
    ch.listen('.approval.updated', () => qc.invalidateQueries({ queryKey: approvalKeys.all }));
    return () => echo.leave(`company.${companyId}.ai`);
  }, [companyId, qc]);
}
```

**Streaming generation (Ask AI).** The assistant streams tokens over SSE through a Next.js route handler
proxy (`app/api/ai/chat/route.ts`) that forwards the visitor's own httpOnly bearer server-side; the
transcript renders `AiTypingIndicator` until the first token, then the streaming text, then — on
completion — any implied action as `AIProposalCard`. The stream sets `role="status"`/`aria-busy` once when
a reply begins and announces the full text once on completion, never token-by-token (which would make the
dock unusable by voice).

**Reconnect correctness.** A WebSocket has no replay, so on reconnect every realtime-fed AI query key
(`aiKeys.*`, `approvalKeys.all`) is invalidated exactly once as a batch — a Fraud Detection hold missed
during an outage must surface immediately, never wait for a manual refresh. A stale Approval Center
silently trusted after a drop would be a correctness bug, not a cosmetic one.

# Accessibility

Every widget inherits its base Radix primitive's accessibility contract; this section lists what each AI
widget adds and must not break. The baseline is WCAG 2.2 AA in both themes and both directions.

**Confidence is never conveyed by color alone.** This is the load-bearing accessibility rule of this
document. A confidence band is signalled through **three redundant, non-hue channels** — the meter's
**fill level** (how many of four segments are solid), the meter's **shape** (solid segments vs. a hollow,
dashed track for the unfilled remainder), and a **real text label** ("High/Medium/Low confidence") — plus
the numeric percentage as a real text node. QAYD deliberately does not render confidence as a
red/yellow/green traffic light. A grayscale export, a dark-mode render, and a user with any of the
color-vision deficiencies that affect roughly 8% of men all read the same band correctly, because hue is
never the carrier. The same rule governs AI *provenance*: an AI-drafted field or card is marked by the
`Sparkles` glyph **and** an explicit "AI"/"Suggested" text label **and** an accent border — three cues, no
single one of them a color.

**Every AI-authored control explains itself.** `ConfidenceBadge`'s percentage and label are both real
text, never a bare progress-bar `width`. Every Approve/Reject/Delegate button is a real `<button>` with
`aria-describedby` pointing at the card's reasoning, so the "why" is available *before* the action, not
only visually adjacent. A `ReasoningPanel` disclosure is a real `aria-expanded` button; its tooltip
content is reachable on keyboard focus, not hover-only.

**RBAC-disabled controls announce their reason.** A control disabled because the caller lacks a permission
carries a `Tooltip`-linked `aria-describedby` naming the missing permission (kept textually distinct from
a business-state block like "waiting on step 1"). A control *withheld* by `can_execute_directly: false` is
simply absent — there is no disabled element to announce, matching the structural guarantee.

**Live regions, calibrated to avoid noise.** New AI rows announce via `aria-live="polite"`, never
`"assertive"`; the streaming reply announces once at start and once at completion, never token-by-token; a
Reverb-driven queue change surfaces as a polite "Refresh" banner rather than re-indexing a list under a
screen reader mid-read.

**Keyboard & reduced motion.** Every interactive AI element is a real semantic `<button>`/`<a>`, focusable
across its full padded hit area with a visible `--ring`/`accent` focus ring at a non-zero offset. Every
Framer Motion animation in this set (the `ConfidenceMeter` fill, the `ReasoningPanel` expand, the
`AiTypingIndicator` pulse, the realtime row flash) reads `useReducedMotion()` and degrades to an *instant
state change* — `prefers-reduced-motion: reduce` is treated as "no motion," never "quick motion," with
zero widget-level exceptions. The typing indicator always ships a static, text-labeled fallback.

Per-widget accessibility obligations:

| Widget | Keyboard | Screen reader | Must not break |
|---|---|---|---|
| `ConfidenceBadge` | Tooltip trigger is a real `<button>`, Esc-dismissible | Percentage + label are real text; reasoning available on focus | Never encode the band by hue alone |
| `ConfidenceMeter` | Not focusable (decorative unless standalone) | `aria-hidden` beside a badge; `role="img"` + `ariaLabel` when standalone | Fill level + shape carry the band, not color |
| `ReasoningPanel` | Real `aria-expanded` disclosure; source links tabbable | Sources are a real `<ul>` of drill-through links | Never hover-only |
| `AIProposalCard` / `Panel` | Three real buttons; alternatives a real `<ul>` | Reasoning linked via `aria-describedby`; "Do it" properly absent, not disabled, below threshold | Absent ≠ disabled distinction |
| `ApprovalCard` / `ActionBar` | Focusable buttons; reject dialog traps focus | "Approval 1 of 2" is text; disabled reason announced | Reject stays disabled until reason non-empty |
| `AIDraftField` | Accept/Clear are real buttons; the wrapped input keeps its own a11y | "Suggested" is a real label; agent + confidence announced | Never auto-commit |
| `AskAIWidget` | Composer + send reachable; transcript scrollable | Streaming `role="status"`, announce-once | Never narrate token-by-token |
| `AutomationStatusChip` | Real `<button>` when manageable | `aria-label` states rule name + status | Status not by color alone |

# Theming, Dark Mode & RTL

**Tokens only, never raw values.** Every color, radius, and shadow these widgets reference resolves to a
QAYD CSS-variable token exposed as a Tailwind utility (`bg-accent-100`, `border-ink-6`, `text-ink-950`,
`bg-ink-2`, `rounded-lg`) — no widget ships a literal hex or px a token already covers, and no widget
contains a `dark:` variant referencing a raw color. This is what lets a single token edit re-skin every AI
surface at once, and what makes dark mode and RTL exact remaps rather than separate implementations.

**Dark mode is a first-class remap, not an inversion.** `data-theme="dark"` (system-aware, user-override,
persisted) redefines every token these widgets use — the ink scale, `accent`/`accent-subtle`,
`positive`/`negative`/`warning` — with its own calibrated value. Two AI-specific rules
([`../DARK_MODE.md`](../DARK_MODE.md)): the accent stays reserved for AI provenance and the one primary
action — a `critical` risk or `held` approval is the `negative` token in both themes, never the accent, so
"AI touched this" and "this is urgent" never collapse into one signal; and confidence indicators are
re-tuned per theme, not linearly brightened, so a high-confidence hold reads correctly in dark mode
without harsh over-saturation. The `ConfidenceMeter`'s SVG-adjacent fill resolves through `lib/tokens.ts`'s
JS-readable token export rather than a CSS variable read at render time, because a fill attribute cannot
consume a Tailwind class the way a `div` background can.

**RTL is one component tree, mirrored by logical properties.** Every AI widget uses logical utilities
exclusively — `ms-*`/`me-*`, `ps-*`/`pe-*`, `border-s`/`border-e`, `text-start`/`text-end`, `inset-inline-*`
— so it mirrors under `dir="rtl"` with zero conditional code (the `AiCardShell`'s leading accent border is
`border-s-4`, inline-start in both directions; the `ReasoningPanel` chevron and any "next" affordance flip
via `mirrorRtl`/`rtl:` variants). Three things never mirror, matching the platform's numeral and icon
rules: **every confidence percentage, monetary figure, and date** renders inside a `dir="ltr"` span (a
`93%` reads left-to-right even inside a fully Arabic sentence, via `numberingSystem: "latn"` forcing — QAYD
never switches to Eastern Arabic-Indic digits for AI figures); **value-direction and symmetric icons**
(`Sparkles`, the confidence meter, a checkmark, a severity dot) never flip; and the `AskAIWidget`'s
slide-in edge is inline-end in LTR, inline-start in RTL, from one logical rule.

# i18n

Every user-facing string in these widgets is a key present in **both** `lib/i18n/en.ts` and
`lib/i18n/ar.ts` under the `ai` namespace, sharing one `Dictionary` type — a key in one and missing in the
other fails `npm run i18n:check` in CI, not silently at runtime. Widgets read strings through
`useTranslations('ai')`; no widget hard-codes an English string.

The **fifteen agent display names** and the **three confidence-band labels** are localized keys, never
rendered from the raw `agent_code` or a client-side band computation:

| Context | Key | English | Arabic |
|---|---|---|---|
| AI provenance badge | `ai.badge` | AI | الذكاء الاصطناعي |
| Confidence band | `ai.confidence.high` | High confidence | ثقة عالية |
| Confidence band | `ai.confidence.medium` | Medium confidence | ثقة متوسطة |
| Confidence band | `ai.confidence.low` | Low confidence | ثقة منخفضة |
| Proposal | `ai.suggested` | Suggested | مُقترح |
| Three-button | `ai.doIt` | Do it | نفّذ |
| Three-button | `ai.sendForApproval` | Send for approval | إرسال للاعتماد |
| Three-button | `ai.dismiss` | Dismiss | تجاهل |
| Approval action | `ai.approve` | Approve | اعتماد |
| Approval action | `ai.reject` | Reject | رفض |
| Approval action | `ai.delegate` | Delegate | تفويض |
| Attribution | `ai.agent.GENERAL_ACCOUNTANT` | General Accountant | وكيل المحاسب |
| Attribution | `ai.agent.CFO_AGENT` | CFO Agent | وكيل المدير المالي |
| Attribution | `ai.agent.FRAUD_DETECTION` | Fraud Detection | كشف الاحتيال |
| Permission tooltip | `ai.requiresPermission` | Requires `{permission}`. | يتطلب صلاحية `{permission}`. |
| Degraded | `ai.unavailable.title` | AI insights are temporarily unavailable. | تعذّر الوصول إلى رؤى الذكاء الاصطناعي مؤقتًا. |

Two i18n rules are specific to AI content. First, **the AI's own words are never machine-translated by the
frontend** — an Arabic briefing, an Arabic risk narrative, an Arabic assistant reply arrives already
localized from the API per its `Accept-Language` content-negotiation contract; the client's localization
responsibility is limited to chrome (labels, band text, tooltips, empty-state copy), never the model's
prose. Second, **numerals in AI content follow the platform rule exactly** — every confidence score,
exposure amount, and threshold renders Western (Latin) digits inside a `dir="ltr"` span regardless of
locale, and Arabic band/label copy is authored directly by a fluent professional-register writer, not
translated post hoc.

# Testing

**Storybook.** Every widget ships a colocated `.stories.tsx` covering its real permutation matrix, and a
global decorator renders each story in all four combinations (LTR/RTL × light/dark) plus its data states
(`loading`/`empty`/`degraded`) via a `parameters.state` mock that short-circuits the TanStack Query hook.
The confidence widgets specifically story all three bands, and the low band's hollow-meter treatment is a
mandatory visual-regression snapshot (a regression that reintroduced a color-only band would surface as a
pixel diff).

```tsx
// components/ai/confidence-badge.stories.tsx (excerpt)
export const High: StoryObj<typeof ConfidenceBadge> = { args: { confidence: 0.94, showMeter: true } };
export const Medium: StoryObj<typeof ConfidenceBadge> = { args: { confidence: 0.72, showMeter: true } };
export const Low: StoryObj<typeof ConfidenceBadge> = { args: { confidence: 0.41, showMeter: true } };
export const LowRTLDark: StoryObj<typeof ConfidenceBadge> = {
  args: { confidence: 0.41, showMeter: true },
  parameters: { direction: 'rtl', theme: 'dark' },
};
```

**Vitest + Testing Library** covers the logic-bearing pieces — the normalizer and the banding above all,
since a wrong `sourceField` is the most likely AI-widget bug in the frontend:

```tsx
// components/ai/confidence-badge.test.ts
import { normalizeConfidence, confidenceBand } from './confidence-badge';

test('normalizes a 0-100 ai_decisions.confidence_score onto 0-1', () => {
  expect(normalizeConfidence(96.5, 'percentage')).toBeCloseTo(0.965);
});
test('passes through an already-fractional journal_lines.ai_confidence unchanged', () => {
  expect(normalizeConfidence(0.965, 'fraction')).toBe(0.965);
});
test('bands at the documented thresholds', () => {
  expect(confidenceBand(0.85).band).toBe('high');
  expect(confidenceBand(0.60).band).toBe('medium');
  expect(confidenceBand(0.59).band).toBe('low');
});

// components/ai/recommendation-card.test.tsx
test('withholds "Do it" entirely below 0.60 confidence even when can_execute_directly is true', () => {
  render(<AIProposalCard decision={{ ...base, confidence_score: 55, can_execute_directly: true }} {...handlers} />);
  expect(screen.queryByRole('button', { name: /do it/i })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: /send for approval/i })).toBeInTheDocument();
});
test('disables the action bar when ai.approve is absent, with a naming tooltip', () => {
  renderWithPermissions([], <ApprovalActionBar permissionKey="ai.approve" {...handlers} />);
  expect(screen.getByRole('button', { name: /approve/i })).toBeDisabled();
  expect(screen.getByText(/requires .*ai\.approve/i)).toBeInTheDocument();
});
```

**Playwright** covers the two flows too state-dependent for a component test: the full
`AIProposalCard → Send for approval → ApprovalCard → Approve` lifecycle across two personas (an Accountant
who can only submit, a Finance Manager who approves), asserting the permission-gated buttons switch
correctly; and the `AskAIWidget` SSE stream, asserting the typing indicator appears, the reply streams,
and an implied action renders as `AIProposalCard`.

**Accessibility CI gate.** `axe-core` runs against every AI-widget story; a new or regressed violation
fails the build. The confidence widgets additionally get a manual grayscale pass before each release,
since `axe` catches missing ARIA but cannot verify that a band is still distinguishable without color.

# Edge Cases

- **Two confidence scales, one component.** `ai_decisions.confidence_score` (0–100) vs.
  `journal_lines.ai_confidence` / `accounts.ai_suggestion_confidence` (0.0000–1.0000) are two genuinely
  different scales on two different tables — not a typo to "fix." No widget accepts a raw field without the
  caller passing it through `normalizeConfidence(raw, sourceField)`; the argument is unit-tested rather
  than trusted to convention.
- **`can_execute_directly` true but confidence below 0.60.** The server may compute `can_execute_directly`
  from autonomy alone; the client applies a second, independent floor and **still** withholds "Do it"
  below 0.60. The two gates are redundant on purpose — neither substitutes for the other, and the mutation
  endpoint `403`s as the final backstop.
- **Permission removed mid-session.** `usePermission()` reads a client-side RBAC snapshot fetched at
  session start and revalidated on each mutation's `403`. A widget never assumes a check made on mount is
  still true 20 minutes later for a sensitive action; the mutation re-checks before firing and the server's
  `403` wins, forcing a permission-cache refetch and an explanatory toast.
- **AI proposal's target record changes between proposal and click.** The approval flow re-fetches the live
  target immediately before rendering Approve and diffs it against the proposal's stored payload; on
  divergence, Approve is replaced with "Review changed data" rather than silently approving stale intent.
- **AI engine unavailable (`503`).** An AI-only panel renders `AiUnavailable` (not a generic `ErrorState`,
  not an infinite spinner), and its TanStack Query `retry` policy backs off using the `Retry-After` header
  when present; the rest of the screen renders normally, and an `AIDraftField` degrades to a plain
  human-only input rather than blocking the whole form.
- **A raw magnitude is not a signed metric.** A risk's `financial_exposure` renders in plain tabular ink,
  never washed in `negative`, because severity is already carried by `AiCardShell`'s border and badge, and
  the debit/credit rule reserves `positive`/`negative` for genuinely signed, net metrics (a variance, a
  delta) — never a raw amount.
- **Low confidence is shown, not hidden.** A Low-band output still renders — with its Low label, hollow
  meter, and reasoning — because suppressing a low-confidence answer would be a silent failure; only the
  *one-click auto-execute* is withheld, never the visibility.
- **The assistant genuinely cannot answer.** When the orchestrator can't answer confidently from any tool,
  the reply states so in plain prose with **no** confidence badge — the failure is "no answer," not "a
  low-confidence answer," and rendering an artificially low badge would misrepresent it.
- **Company switch with AI content open.** `queryClient.clear()` and `router.refresh()` fire together;
  every AI widget re-mounts and re-fetches under the new `X-Company-Id` rather than momentarily showing the
  previous company's proposals or risks.
- **Printing / PDF export.** AI badges, confidence scores, and the assistant have no meaning on paper; an
  AI-dense surface (the Command Center) is not a "print this" target — a user needing a shareable artifact
  is directed to the relevant statement or report export, which carries its own print stylesheet. A
  meaningful AI status that *does* appear on a printed statement (a "Voided" glyph) survives via the
  color+shape+text redundancy every widget here already guarantees.
- **Reduced motion during streaming.** With `prefers-reduced-motion`, the `AiTypingIndicator` is a static
  three-dot glyph plus a "Generating…" live-region announcement, the `ConfidenceMeter` fill appears
  instantly, and the realtime row-arrival flash is suppressed — the *state* still changes, only its
  animation is removed.

# End of Document
