# AI Interaction Patterns — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / AI_INTERACTION
---

# Purpose

This document is the umbrella specification for **AI-in-UI interaction patterns** in QAYD — the reusable,
composed shapes every AI-touched surface is built from, stated once so no screen reinvents them. It is
the pattern-level companion to the atomic component spec [`../components/AI_WIDGET.md`](../components/AI_WIDGET.md)
and the exhaustive app reference [`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md):
those own the primitives (`ConfidenceBadge`, `ReasoningPanel`, `AgentAvatar`, `AIProposalCard`,
`AiTypingIndicator`, `AiUnavailable`, …) and their prop surfaces; this document owns the *interaction
patterns* those primitives compose into — the confidence + reasoning + human-in-the-loop contract as a
reusable posture, AI-draft fields with provenance that never auto-commit, inline proposal cards, the
streaming/typing pattern, agent attribution, the Ask-AI/assistant pattern, the AI-unavailable/degraded
fallback, and the confidence-never-color-alone rule.

QAYD is an AI-native accounting product, so AI is not a feature bolted onto a corner of the UI — it is a
posture the whole interface takes toward machine-produced values. That posture is three invariants, and
this document exists to make them one shape a designer or engineer reaches for by reflex, on every
surface, rather than a rule re-argued per screen:

1. **Confidence + reasoning are never separable from an AI value.** No surface renders a bare
   AI-computed value without an attached confidence indicator and a reachable explanation.
2. **The frontend never auto-commits an AI proposal.** The UI auto-commits only a *human's decision
   about* a proposal, never the proposal itself. Whether a one-click execute renders at all is the
   server's `can_execute_directly`, never re-computed client-side — when `false`, the control is
   **absent**, not disabled.
3. **Confidence is never conveyed by color alone.** A band is carried by three redundant, non-hue
   channels — fill level, shape, and a real text label — so it survives grayscale, dark mode, and
   color-vision deficiency.

Every color, radius, and motion value references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the warm
`ink-1…12` scale, the single brass `accent` (rationed to AI-touched elements and the one primary action),
and the `positive`/`negative`/`warning` financial set. There is **no "AI purple."** The brass accent
marks AI provenance and the one primary action; it never carries status or severity, and
`positive`/`negative` never carry brand or AI-badge meaning.

> **Token-reconciliation note.** The app-level docs this pattern draws on were authored against an earlier
> numeric-step palette (`accent-100`, `accent-600`, `accent-700`, `ink-500`, `ink-700`, `ink-950`,
> `ink-6`, `text-caption`, `text-body`). This document uses the **canonical** names from
> [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md): `accent`/`accent-subtle`/`accent-strong`/
> `accent-on`, `ink-1…ink-12`, `positive`/`negative`/`warning`, and the `text-*` type steps. Read a draft
> name as its canonical equivalent (`accent-100` → `accent-subtle`, `accent-600` → `accent`, `accent-700`
> → `accent-strong`, `ink-500` → `ink-9`, `ink-700` → `ink-10`, `ink-950`/`ink-12` → `ink-12`,
> `text-caption` → `text-xs`, `text-body` → `text-md`).

# When to Use

Reach for these patterns whenever **a value, a suggestion, a draft, or an answer on screen was produced by
a model** — an AI-classified document, an AI-suggested journal-line account, a blended-confidence chat
answer, a surfaced recommendation, a risk flag. Reach for a **plain (non-AI) component** when the value is
deterministic — a government-set tax due date, a computed sum, a status the server assigned by a fixed
rule — and do *not* dress it in AI chrome. The single most common taste failure is applying an accent
edge or a `ConfidenceBadge` to a value the machine did not actually reason about.

| Use an AI interaction pattern | Use a plain component |
|---|---|
| A model produced or scored the value | A deterministic computation or fixed rule produced it |
| A suggestion the human may edit or accept | A field the human always fills themselves |
| An answer with a blended confidence | A record lookup with a single true answer |
| A recommendation the human decides on | A government due date, a posted total, a system status |

A deterministic due date on the Financial Calendar renders as a plain category chip with **no** accent
edge and **no** `ConfidenceBadge`; only a genuinely AI-surfaced chip carries the AI grammar. "AI-touched"
must mean the AI touched it.

# Anatomy

Every AI-authored surface stacks the same envelope, so "the AI touched this" reads identically everywhere
— a card in a feed, a field in a form, a bubble in chat.

```
┌─ border-s-4 accent  (warning/negative for severity — an independent signal) ──────┐
│  * AI   [◕ 92% High confidence]   ◐ General Accountant                             │  ← provenance row
│                                                                                    │
│  <primary content — the recommendation, the drafted value, the answer prose>       │  ← primary content
│                                                                                    │
│  ▸ Why this?  (collapsed ReasoningPanel → reasoning + cited source records)        │  ← evidence, pull not push
│                                                                                    │
│                      [ Dismiss ]  [ Send for approval ]  [ Do it* ]                 │  ← decision (*server-gated)
└────────────────────────────────────────────────────────────────────────────────────┘
```

| Part | Primitive | Rule |
|---|---|---|
| Provenance row | `Sparkles` badge + `ConfidenceBadge` + `AgentAttributionChip` | Three redundant non-color AI cues: glyph, "AI" text, accent edge |
| Leading edge | `border-s-4` | `accent` for AI-provenance; `warning`/`negative` for *severity*, an independent signal |
| Confidence | `ConfidenceBadge` (+ optional `ConfidenceMeter`) | Band by fill + shape + label, never hue alone |
| Explanation | `ReasoningPanel` | Collapsed by default; sources are real drill-through links |
| Attribution | `AgentAvatar` / `AgentAttributionChip` | Domain glyph in a 28px circle, localized agent name; never a photo, mascot, or per-agent color |
| Decision | `AIProposalCard` buttons / `ApprovalActionBar` | `Do it` absent below threshold; Reject/Dismiss need a reason |

The leading accent edge is `border-s-4` — inline-start in both LTR and RTL from one logical rule.
Severity never rides the accent: a critical item is `negative`, so "AI-touched" and "urgent" stay two
signals. The whole envelope is the `AiCardShell` primitive; a designer never hand-rolls a second AI card
shape.

## The confidence band table

Every confidence primitive resolves against one banding. Callers normalize QAYD's two live scales
(`0–100` for `ai_decisions.confidence_score`, `0.0–1.0` for `journal_lines.ai_confidence`) onto a single
**0–1** range first, via `normalizeConfidence(raw, sourceField)`.

| Band | Range (0–1) | Label (EN / AR) | Badge tone | Meter fill | Meter shape | One-click "Do it" |
|---|---|---|---|---|---|---|
| High | `≥ 0.85` | High confidence / ثقة عالية | `accent` | full, `accent` | solid | Eligible if `can_execute_directly` |
| Medium | `0.60 – 0.849` | Medium confidence / ثقة متوسطة | `neutral` | ~⅔, `ink-9` | solid | Eligible if `can_execute_directly` |
| Low | `< 0.60` | Low confidence / ثقة منخفضة | `neutral` | ≤ ⅓, `ink-9` | **hollow / dashed** | **Withheld — never renders** |

Two hard rules fall out: below `0.60` the one-click auto-execute never renders (regardless of server
autonomy), and even a High-band item only offers "Do it" when the server already returned
`can_execute_directly === true` (which itself clears the `0.90` `AUTO_EXECUTE_THRESHOLD`). The UI gate and
the server gate are deliberately redundant.

# Variants

| Pattern | Variant axis | Values |
|---|---|---|
| Confidence display | `ConfidenceBadge` size / meter | `sm`/`default`; label on/off; leading `ConfidenceMeter` glyph on/off |
| Reasoning | `ReasoningPanel` variant | `popover` (feed-safe) / `inline` (chat only) |
| Inline proposal | `AIProposalCard` (compact) / `AIProposalPanel` (expanded) | Two densities of one contract; a screen picks one, never both for the same decision |
| AI-draft field | suggested / accepted | Distinct provenance styling until the human edits or accepts; then plain |
| Attribution | `AgentAvatar` size | `sm` 24px (chat) / `md` 28px (cards) |
| Streaming | `AiTypingIndicator` | Three pulsing dots; static three-dot glyph + live-region text under reduced motion |
| Assistant | `AskAIWidget` container | `sheet` (below 3xl) / `rail` (3xl+) — same transcript, composer, contract as the full `/assistant` |
| Degraded | `AiUnavailable` variant | `panel` / `chat` / `inline` |

`AIProposalCard` and `AIProposalPanel` are two densities of one contract; the assistant's docked and
full-page forms are two renderings of one surface — never a chat-native lookalike of an existing
component.

# Composition

## The confidence + reasoning contract as a reusable posture

Confidence and reasoning travel together, always. The `ConfidenceBadge` normalizes once, resolves the
band, and carries a keyboard-reachable reasoning tooltip; the band is never hue alone.

```tsx
// The reusable confidence posture: normalize once, band by fill+shape+label, reasoning reachable on focus.
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { ConfidenceMeter } from '@/components/ai/confidence-meter';

export function normalizeConfidence(raw: number, sourceField: 'fraction' | 'percentage') {
  return sourceField === 'percentage' ? raw / 100 : raw;
}
export function confidenceBand(c: number) {
  if (c >= 0.85) return { band: 'high', labelKey: 'ai.confidence.high', tone: 'accent' } as const;
  if (c >= 0.6)  return { band: 'medium', labelKey: 'ai.confidence.medium', tone: 'neutral' } as const;
  return { band: 'low', labelKey: 'ai.confidence.low', tone: 'neutral' } as const;
}

export function ConfidenceBadge({ confidence, size = 'default', showLabel = true, showMeter = false, reasoning }: ConfidenceBadgeProps) {
  const { labelKey, tone } = confidenceBand(confidence);
  const badge = (
    <Badge tone={tone} className={size === 'sm' ? 'gap-1.5 px-2 py-0 text-[11px]' : 'gap-1.5'}>
      {showMeter && <ConfidenceMeter confidence={confidence} size="xs" aria-hidden />}
      <span className="font-mono tabular-nums" dir="ltr">{Math.round(confidence * 100)}%</span>{/* real text node */}
      {showLabel && <span>{/* t(labelKey) */}</span>}
    </Badge>
  );
  if (!reasoning) return badge;
  return (
    <Tooltip>
      <TooltipTrigger asChild><button type="button" className="cursor-help">{badge}</button></TooltipTrigger>
      <TooltipContent className="max-w-xs text-start">{reasoning}</TooltipContent>{/* reachable on keyboard focus */}
    </Tooltip>
  );
}
```

The percentage is a **real text node**, never a bare progress-bar width; the reasoning tooltip is
reachable on keyboard focus, never hover-only. Reasoning is **pull, not push** — `ReasoningPanel` is
collapsed by default so twenty insights stay scannable, one keyboard-reachable disclosure away, its cited
sources real drill-through links.

## AI-draft fields — provenance that never auto-commits

An AI-suggested value loaded into a form field shows its provenance and confidence and is styled
distinctly (an accent `Sparkles` affordance + a `ConfidenceBadge`) until the human edits or explicitly
accepts it. The form submits the value the **human** leaves; until then the field is marked "suggested,"
never "set." There is no code path in which an AI-draft field posts anything.

```tsx
// AIDraftField — loading a suggestion is a local-state operation only; the human's value is what submits.
import { Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ConfidenceBadge } from '@/components/ai/confidence-badge';
import { AgentAttributionChip } from '@/components/ai/agent-attribution-chip';
import { cn } from '@/lib/utils';

export function AIDraftField({ label, children, suggestion, accepted, onAcceptSuggestion, onDismissSuggestion }: AIDraftFieldProps) {
  const isDrafted = suggestion != null && !accepted;
  return (
    <div className={cn('rounded-md p-2', isDrafted && 'ring-1 ring-accent/40 bg-accent-subtle/40')}>
      <div className="mb-1 flex items-center justify-between gap-2">
        <label className="text-sm text-ink-11">{label}</label>
        {isDrafted && (
          <span className="inline-flex items-center gap-1.5 text-xs text-accent">
            <Sparkles className="h-3.5 w-3.5" aria-hidden /> Suggested
            <ConfidenceBadge confidence={suggestion.confidence} size="sm" showLabel={false} reasoning={suggestion.reasoning} />
          </span>
        )}
      </div>
      {children}{/* the wrapped input; its value travels through ordinary form submission + its own permission gate */}
      {isDrafted && (
        <div className="mt-1 flex gap-2">
          <Button size="sm" variant="secondary" onClick={onAcceptSuggestion}>Use suggestion</Button>
          <Button size="sm" variant="ghost" onClick={onDismissSuggestion}>Clear</Button>
          <AgentAttributionChip agentCode={suggestion.agentCode} />
        </div>
      )}
    </div>
  );
}
```

This is the same posture the import flow's mapping stage takes toward an AI-translated `name_ar` and the
Document Center takes toward an extracted field: the machine proposes a value into a human-owned control,
labeled as a suggestion, and the *owning* module's governed write is where it becomes a fact.

## Inline proposal cards — the three-button pattern

A fresh recommendation renders the three-button pattern — `Do it` / `Send for approval` / `Dismiss` —
where *which* buttons appear is computed server-side. `Do it` is **structurally absent** (never disabled)
when `can_execute_directly` is false or confidence is below `0.60`; it never renders for anything touching
`bank.transfer`, `payroll.approve`, `tax.submit`, a permission change, or a delete/void.

```tsx
// AIProposalCard — the human-in-the-loop guarantee made structural. offerDoIt can never be true below 0.60.
const AUTO_EXECUTE_THRESHOLD = 0.9; // UI mirror of the company-configured server threshold
const confidence = normalizeConfidence(decision.confidence_score, 'percentage');
const offerDoIt = decision.can_execute_directly && confidence >= AUTO_EXECUTE_THRESHOLD && Boolean(onExecute);

<AiCardShell decision={decision}>
  <p className="text-md text-ink-12">{decision.recommended_action.action}</p>
  <div className="mt-3 flex justify-end gap-2">
    <Button variant="ghost" size="sm" onClick={() => setDismissOpen(true)}>Dismiss</Button>{/* reason required */}
    <Button variant="outline" size="sm" disabled={!canApprove} onClick={onSendForApproval}>Send for approval</Button>
    {offerDoIt && <Button size="sm" loading={busy} disabled={!canApprove} onClick={onExecute}>Do it</Button>}
  </div>
</AiCardShell>
```

`Send for approval` hands the item into the same human-gate every sensitive action passes through — the
proposal is not executed, it is queued for a human (continuing on the
[APPROVAL_PATTERNS](./APPROVAL_PATTERNS.md) surface). `Dismiss` requires a non-empty reason (stored as
`rejected_reason`, fed back to `ai_memory`) with a 5-second Undo.

## The streaming / typing pattern

While a reply generates, the transcript renders `AiTypingIndicator` — three `accent-subtle` dots pulsing
`0.3 → 1 → 0.3` in a 1.2s staggered loop, deliberately calmer than a typical chat typing indicator — then
the streaming text, then, on the terminal frame, the confidence badge, citations, attribution, and any
implied action. The message is **not final** — badge, citations, and action affordances are withheld —
until the terminal frame arrives.

```tsx
// AiTypingIndicator — calm, and never a bare animation with no text fallback.
import { motion, useReducedMotion } from 'framer-motion';

export function AiTypingIndicator({ label = 'Generating…' }: { label?: string }) {
  const reduced = useReducedMotion();
  return (
    <div role="status" aria-live="polite" className="inline-flex items-center gap-1">
      <span className="sr-only">{label}</span>{/* reduced motion → static dots + this live-region text */}
      {[0, 1, 2].map((i) => (
        <motion.span key={i} aria-hidden className="size-1.5 rounded-full bg-accent-subtle"
          animate={reduced ? undefined : { opacity: [0.3, 1, 0.3] }}
          transition={reduced ? undefined : { duration: 1.2, repeat: Infinity, delay: i * 0.15, ease: 'easeInOut' }} />
      ))}
    </div>
  );
}
```

Streaming announces **once at start and once at completion**, never token-by-token (`role="status"` /
`aria-busy` on send, `aria-live="polite"` full text on completion) — token-by-token narration would make
the surface unusable by voice. A pre-first-token routing status ("Checking Treasury Manager…") announces
at most once per specialist.

## Agent attribution

Attribution renders *which* of the fifteen agents produced a payload — its domain icon centered in a 28px
circle filled with a 10%-opacity accent tint, plus the agent's localized name and, when relevant, its
`ConfidenceBadge`. It is **never** a photographic avatar, a per-agent color scheme, or an illustrated
mascot. The attribution row renders only when a fan-out genuinely occurred; a single-domain reply shows no
byline — attribution is informative, not decorative.

## The Ask-AI / assistant pattern

The assistant is one conversational surface with two renderings — the full `/assistant` page and the
docked `AskAIWidget` (a `Sheet` below 3xl, a persistent rail at 3xl+) — sharing one transcript, composer,
and message contract. A reply resolving to an implied action renders the identical three-button
`AIProposalCard`, or embeds the real `ApprovalCard` (re-fetched live), never a second competing pattern.
The user never selects a specialist — routing is the orchestrator's job at request time. `⌘K`'s "Ask AI"
hands free text to the same composer rather than a competing input. Gated by `ai.chat`; the frontend's
one client-checked permission on the action affordances is `ai.approve`.

## The AI-unavailable / degraded fallback

When an AI-only endpoint returns `503`, the surface renders `AiUnavailable` — never a generic `ErrorState`
and never an infinite spinner. It states plainly that AI insights are temporarily unavailable and offers a
retry that backs off on the `Retry-After` header; **the rest of the screen renders normally.** An
AI-draft field degrades to a plain, human-only input with an inline note; a proposal card is replaced by
the panel variant; the whole app is unaffected by one AI endpoint being down.

```tsx
// AiUnavailable — fail fast with Retry-After, never an infinite spinner; non-AI content is untouched.
import { CloudOff } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function AiUnavailable({ variant, retryAfterSeconds, onRetry }: AiUnavailableProps) {
  const waiting = (retryAfterSeconds ?? 0) > 0;
  return (
    <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-ink-6 p-4 text-center">
      <CloudOff className={variant === 'panel' ? 'h-8 w-8 text-ink-7' : 'h-5 w-5 text-ink-7'} aria-hidden />
      <p className="text-xs text-ink-10">AI insights are temporarily unavailable</p>
      <Button variant="outline" size="sm" disabled={waiting} onClick={onRetry}>
        {waiting ? `Retry in ${retryAfterSeconds}s` : 'Retry'}
      </Button>
    </div>
  );
}
```

# States & Behavior

Every AI pattern ships an explicit treatment for each state; none conveys a state by color alone.

| Pattern | Default | Loading | Low-confidence | Degraded (`503`) | Disabled (RBAC) |
|---|---|---|---|---|---|
| `ConfidenceBadge` | Pill + band + optional meter | Renders once value resolves | `neutral` tone, "Low" label, hollow meter | Not rendered; parent shows `AiUnavailable` | N/A (read) |
| `ReasoningPanel` | Collapsed disclosure | Disabled until reasoning loads | Unchanged — low confidence still shows its "why" | Not rendered | N/A |
| `AIDraftField` | Suggested styling + accept/clear | Field spinner | Low-band badge; suggestion shown, never auto-accepted | Degrades to a plain human-only input + inline note | N/A (editing is the human's own permission) |
| `AIProposalCard` | Buttons per `can_execute_directly` | Acting button `loading` | **"Do it" absent**; Send + Dismiss remain | Replaced by `AiUnavailable` | Send/Do-it disabled + tooltip naming the missing permission |
| `AiTypingIndicator` | Three pulsing dots | It *is* the loading state | N/A | Replaced by `AiUnavailable` on failure | N/A |
| `AskAIWidget` | Composer + transcript | `AiTypingIndicator` while streaming | Plain prose, **no badge**, when the model can't answer confidently | `AiUnavailable variant="chat"`, message preserved | Composer hidden without `ai.chat` |
| `AgentAttributionChip` | Domain glyph + name (+ optional badge) | Skeleton circle | Unchanged | Not rendered | N/A |

**Absent ≠ disabled.** A `Do it` withheld by `can_execute_directly: false` (or confidence below `0.60`)
does **not render** — there is no client path to execute. A control disabled for a *permission* reason
renders greyed with a tooltip naming the missing permission — the user should know the action exists but
isn't theirs. The former is a structural guarantee; the latter is an explanation.

**The honest "no answer."** A blended confidence below the answer threshold, or "no tool could answer," is
**not** a low-confidence answer — it is plain prose, an explicit caveat ("I'm not fully confident — want
me to have the Auditor take a closer look?"), and **no confidence badge at all**. Two specialists
disagreeing surfaces the conflict explicitly (an auto-expanded trace with an agent/claim/confidence
comparison), never a silent average.

**Realtime.** A newly generated `ai_decisions`/`ai_risk_flags` row merges into the relevant query cache
via `setQueryData` (an insert), not a blunt full-list invalidation; the arrival flashes `accent-subtle`
and eases back over ~600ms — no toast, no sound, no layout shift — and never yanks focus from a mid-read
screen-reader user, surfacing instead as a polite "3 new — Refresh" banner. On reconnect, realtime-fed AI
query keys are invalidated once as a batch.

**Motion.** The meter fill and reasoning disclosure animate under `motion.moderate`/`easeOut`; every
animation reads `useReducedMotion()` and degrades to an instant state change — reduced motion removes the
*animation* of a change, never the *state change* itself.

# Content & Copy Guidance

- **A percentage is always paired with a word.** "92% High confidence," never a bare "92%" — the band
  label is a real text node beside the number.
- **A suggestion is named as a suggestion.** "Suggested," "AI-translated — review before import," "Use
  suggestion" / "Clear" — never a value presented as already-set.
- **Reasoning leads with the "why," not a wall.** The `ReasoningPanel` header is a plain "Why this?" /
  "Why this recommendation"; the body is the model's own localized prose plus cited records.
- **The honest limit is stated, not hidden.** "I'm not fully confident in this," "Inventory Manager didn't
  respond in time — this answer excludes stock data," "AI insights are temporarily unavailable."
- **Dismiss/Reject demand a reason.** The reason field is mandatory and specific; Confirm stays disabled
  until it is non-empty.
- **The model's own words are never machine-translated by the frontend** — a reply arrives already
  localized per the API's content-negotiation contract; the client localizes only chrome.
- **Bilingual microcopy is authored directly** in the professional Gulf register (composer placeholder
  *اسأل أي شيء عن أعمالك*, low-confidence caveat *لست واثقًا تمامًا من هذه الإجابة*), not translated post
  hoc.

# Accessibility

Target **WCAG 2.2 AA** in both themes and both directions; `axe-core` runs against every widget story, and
the confidence widgets get a manual grayscale pass each release since `axe` cannot verify a band is still
distinguishable without color.

- **Confidence is never color alone.** Three redundant non-hue channels — meter fill level, meter shape
  (solid vs. hollow/dashed), and a real text label — plus the numeric percentage as a real text node. A
  grayscale export, a dark render, and a color-vision-deficient reader all read the same band. QAYD does
  **not** render confidence as a red/yellow/green traffic light.
- **Provenance is never color alone either** — the `Sparkles` glyph **and** an "AI" text label **and** an
  accent border; no single carrier is a color.
- **Every control explains itself before the action.** Approve/Reject/Delegate/Do-it are real `<button>`s
  with `aria-describedby` pointing at the card's reasoning, so "why" is available *before* acting, not
  merely adjacent.
- **RBAC-disabled controls announce their reason** via a tooltip-linked `aria-describedby` naming the
  missing permission, kept textually distinct from a business-state block; a control *withheld* by
  `can_execute_directly: false` is simply absent — nothing to announce.
- **`ReasoningPanel` is a real `aria-expanded` disclosure**; its tooltip is reachable on keyboard focus,
  never hover-only; its sources are a real `<ul>` of tabbable drill-through links.
- **Live regions calibrated.** New AI content announces `aria-live="polite"`, never assertive; streaming
  replies announce once at start and once at completion, never token-by-token.
- **Keyboard + reduced motion.** Every interactive element is a real semantic control with a visible
  `accent` focus ring at non-zero offset; `prefers-reduced-motion` collapses the thinking dots to a static
  three-dot glyph plus a "Generating…" live-region announcement.

# Theming, Dark Mode & RTL

**Tokens only, never raw values.** Every color/radius/shadow resolves to a `--qayd-*`-backed Tailwind
utility; no AI widget contains a `dark:` variant referencing a raw color. One token edit re-skins every AI
surface at once. There is no "AI purple": the brass `accent` marks AI provenance and the one primary
action, `positive`/`negative` never carry AI-badge meaning, and a risk's raw `financial_exposure` renders
in plain `ink` tabular numerals, never washed `negative`.

**Dark mode is a first-class remap, not an inversion** ([`../DESIGN_TOKENS.md → Light / Dark Remap`](../DESIGN_TOKENS.md)).
The accent gets **lighter, not more saturated** (`#9C7A34` → `#D9B96C`) so a High-band hold reads
correctly without over-saturation; the `ConfidenceMeter`'s fill resolves through `lib/tokens.ts`'
JS-readable export because a motion width-tween cannot consume a Tailwind class the way a `div` background
can. Two AI-specific rules hold in both themes: the accent stays reserved for AI provenance and the one
primary action (a critical item is `negative` in both themes, never the accent), and confidence
indicators re-tune per theme rather than linearly brighten.

**RTL is one tree, mirrored by logical properties.** Every widget uses `ms/me`, `ps/pe`, `border-s/e`,
`text-start/end` — the `AiCardShell`'s accent edge is `border-s-4` (inline-start in both directions), the
`ReasoningPanel` chevron flips via `rtl:`. Three things never mirror: **every confidence percentage,
amount, and date** renders inside a `dir="ltr"` `latn` span (a `93%` reads LTR inside Arabic text); the
`Sparkles`, meter, checkmark, and severity-dot **glyphs** keep orientation; and the AI's own prose arrives
already localized from the API — the frontend never machine-translates a model's words.

# Do / Don't

| Do | Don't |
|---|---|
| Render every AI value with a confidence indicator and a reachable reasoning | Show a bare AI number, or a percentage with no path to "why" |
| Normalize with `normalizeConfidence(raw, sourceField)` before rendering | Threshold or format a raw `0–100` field directly |
| Carry the band with fill + shape + a real text label | Render confidence as a red/yellow/green traffic light |
| Withhold "Do it" structurally when `can_execute_directly` is false or confidence < 0.60 | Render "Do it" disabled and leave a re-enable path in the DOM |
| Load an AI suggestion into a field as "suggested," submitting the human's value | Let an AI-draft field post anything on its own |
| Use `accent` for AI provenance and the one primary action | Introduce an "AI purple," or wash a raw magnitude in `negative` |
| Render attribution as a domain glyph + localized name, only on a real fan-out | Use a photo avatar, a per-agent color, a mascot, or a decorative byline |
| Announce streaming once at start and once at completion | Narrate a reply token-by-token to the screen reader |
| Fall back to `AiUnavailable` with `Retry-After`; keep non-AI content working | Show a generic error or an infinite spinner, or block the whole screen |
| Give a deterministic value a plain component | Dress a government due date or a computed sum in AI chrome |
| Keep the model's prose as delivered by the API | Machine-translate AI-authored text in the client |

# End of Document
