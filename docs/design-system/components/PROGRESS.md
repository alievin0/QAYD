# Progress — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / PROGRESS
---

# Purpose

`Progress` is QAYD's family of primitives for showing **how far along a bounded quantity or task is** — a
determinate linear bar, a circular ring, an indeterminate state for work of unknown length, and an ordered
`Stepper` for a multi-stage flow. One shared visual language (a quiet track, a single meaningful fill, a
value carried as real text) so a bulk-import bar, an onboarding stepper, and a period-close checklist all
read as the same system.

The primitive draws a hard boundary with two neighbors it superficially resembles, because confusing them is
a correctness problem, not a style one:

| Looks similar | Is actually | Owner |
|---|---|---|
| **`ConfidenceMeter`** — a segmented horizontal fill | An **AI confidence band** encoded by *shape* (High = 4/4 solid, Low = ≤1/4 with a hollow/dashed remaining track). It is not "how complete something is." | [`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md) |
| **`AgingBar`** — a filled horizontal bar | A **proportional breakdown** of AR/AP across fixed buckets (`0-30 … 90+`); every segment is data, none is "remaining." | [`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md) |

`Progress` means **completion of a bounded process**; it always has a `value` and a `max`, and its fill's
empty portion means *not yet done*. If the empty portion is not "not yet done" — if it is another category
of data or an AI band — the correct component is `AgingBar` or `ConfidenceMeter`, and this spec records that
relationship rather than duplicating either. The confidence-meter relationship is deliberate: both share the
segmented-fill DNA, but `ConfidenceMeter` carries its band in *shape* (so grayscale-legible) and lives in the
AI layer, while `Progress` carries a *quantity* and lives here.

# Anatomy

**Linear**

```
 Import journal entries                           842 / 1,000     ◄─ label + value (real text, tabular)
 ┌──────────────────────────────────────────────┐
 │██████████████████████████████░░░░░░░░░░░░░░░░│               ◄─ track (ink-3) + fill (accent/ink-9)
 └──────────────────────────────────────────────┘
   fill grows from the inline-start edge
```

**Circular**

```
     ╭────╮        ring track (ink-3) + progress arc (accent),
    │  84% │       optional centered value (tabular, ink-11);
     ╰────╯        arc sweeps from 12 o'clock, clockwise (LTR)
```

**Stepper**

```
 ([check])━━━━━━━(●)━━━━━━━( )━━━━━━━( )
  Draft     Review    Approve   Post
 complete  current   upcoming  upcoming
   │          │          │
   │          │          └─ node: ink-6 ring, ink-9 label
   │          └─ node: accent ring + fill, aria-current="step"
   └─ node: positive/accent fill + CircleCheck glyph; connector behind it is filled
```

1. **Track** — the full extent, `bg-ink-3`, `radius-full` (linear) or a stroked ring (circular).
2. **Fill** — the completed portion; `accent` for a single focal instance, `ink-9` when many bars coexist
   (see Variants → tone). Grows from the inline-start edge; animated with a Framer Motion width/`pathLength`
   tween.
3. **Value / label** — real tabular text beside or centered in the primitive, never *inside* the fill where
   it would clip.
4. **Stepper nodes & connectors** — ordered node markers joined by connector segments; a completed connector
   takes the fill color, an upcoming one stays `ink-6`.

# Variants

| Variant | Component | Determinate | Use |
|---|---|---|---|
| Linear | `Progress` | yes | Bulk import/export, upload, a bounded batch job, a KPI-vs-target bar |
| Linear indeterminate | `Progress indeterminate` | no | A running job of unknown length (a report generating) |
| Circular | `ProgressRing` | yes | A compact completion figure on a tile (period-close %, storage used) |
| Circular indeterminate | `ProgressRing indeterminate` | no | A spinner-equivalent where a ring reads better than a bar |
| Stepper | `Stepper` | n/a | An ordered multi-stage flow — onboarding, a JE lifecycle, a wizard |

## Tone — ration the accent

Progress fills are functional, but they still obey the one-accent discipline
([`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md)):

| Tone | Fill | When |
|---|---|---|
| `accent` (default) | `bg-accent` | A **single focal** progress the user is watching (the import running on this screen) |
| `neutral` | `bg-ink-9` | Many bars at once (a utilization list, a per-account completion column) — a wall of brass would break the ration |
| `positive` | `bg-positive` | Only when full completion is a genuine financial-status success (a fully-reconciled account) — paired with a `CircleCheck` glyph, never color alone |

A `warning`/`negative` progress fill is **not** offered: a bar approaching a limit is a limit visualization
(use `AgingBar` or a target marker on the track), not a red progress fill.

## Size

| Size | Linear height | Ring diameter / stroke | Use |
|---|---|---|---|
| `sm` | 4px (`h-1`) | 32px / 3px | Inline in a table cell or tile footer |
| `md` (default) | 8px (`h-2`) | 48px / 4px | Default panel/section |
| `lg` | 12px (`h-3`) | 72px / 6px | A focal hero bar or a dashboard ring |

# Props / API

```tsx
// components/ui/progress.tsx
'use client';
import { motion, useReducedMotion } from 'framer-motion';
import { cn } from '@/lib/utils';

interface ProgressProps {
  value?: number;            // 0..max; omit when indeterminate
  max?: number;              // default 100
  indeterminate?: boolean;
  tone?: 'accent' | 'neutral' | 'positive';
  size?: 'sm' | 'md' | 'lg';
  label?: string;            // visible label + basis of the accessible name
  showValue?: boolean;       // render "842 / 1,000" as tabular text
  valueText?: string;        // explicit aria-valuetext (e.g. localized "84 percent")
}

const HEIGHT = { sm: 'h-1', md: 'h-2', lg: 'h-3' } as const;
const FILL = { accent: 'bg-accent', neutral: 'bg-ink-9', positive: 'bg-positive' } as const;

export function Progress({
  value, max = 100, indeterminate, tone = 'accent', size = 'md', label, showValue, valueText,
}: ProgressProps) {
  const reduced = useReducedMotion();
  const pct = indeterminate ? undefined : Math.min(100, Math.max(0, ((value ?? 0) / max) * 100));
  return (
    <div>
      {(label || showValue) && (
        <div className="mb-1 flex items-center justify-between text-sm">
          {label && <span className="text-ink-11">{label}</span>}
          {showValue && !indeterminate && (
            <span className="font-mono tabular-nums text-ink-9" dir="ltr">
              {value?.toLocaleString()} / {max.toLocaleString()}
            </span>
          )}
        </div>
      )}
      <div
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={indeterminate ? undefined : max}
        aria-valuenow={indeterminate ? undefined : value}
        aria-valuetext={valueText}
        aria-label={label}
        className={cn('w-full overflow-hidden rounded-full bg-ink-3', HEIGHT[size])}
      >
        {indeterminate ? (
          <motion.div
            className={cn('h-full w-1/3 rounded-full', FILL[tone])}
            animate={reduced ? {} : { insetInlineStart: ['-33%', '100%'] }}
            transition={reduced ? {} : { duration: 1.1, ease: 'easeInOut', repeat: Infinity }}
          />
        ) : (
          <motion.div
            className={cn('h-full rounded-full', FILL[tone])}
            style={{ transformOrigin: 'inline-start' }}
            initial={reduced ? false : { scaleX: 0 }}
            animate={{ scaleX: (pct ?? 0) / 100 }}
            transition={reduced ? { duration: 0 } : { duration: 0.28, ease: [0.16, 1, 0.3, 1] }}
          />
        )}
      </div>
    </div>
  );
}
```

`Stepper`:

| Prop | Type | Required | Description |
|---|---|---|---|
| `steps` | `Array<{ id: string; label: string; description?: string }>` | yes | Ordered stages. |
| `current` | `number` | yes | Index of the active step (maps to `aria-current="step"`). |
| `orientation` | `'horizontal' \| 'vertical'` | no (default `horizontal`) | Vertical on narrow widths / long labels. |
| `completedTone` | `'accent' \| 'positive'` | no (default `accent`) | `positive` when each finished stage is a committed success. |

`ProgressRing` shares `value`/`max`/`indeterminate`/`tone`/`valueText` and adds `size` (ring diameter);
it renders an SVG `<circle>` track plus a `pathLength`-tweened arc, `aria-hidden` on the SVG with the
`role="progressbar"` on the wrapper.

# States

| State | Treatment |
|---|---|
| Determinate | Fill width/arc = `value/max`; value shown as tabular text; single entrance tween on first paint |
| Value change | Fill tweens to the new width over `motion.base` (200ms); no tween under reduced motion |
| Indeterminate | A one-third fill sweeps start→end on a loop; under reduced motion it is replaced by a static partial fill plus a "Working…" live-region message |
| Complete (100%) | Fill fills the track; a `positive`-tone bar adds a trailing `CircleCheck` glyph + "Done" text |
| Stepper: complete step | Filled node + `CircleCheck`; the connector *behind* it is filled |
| Stepper: current step | `accent`-ringed node, `aria-current="step"`, label `ink-11` |
| Stepper: upcoming step | `ink-6` ring, `ink-9` label; connector ahead of it stays `ink-6` |
| Error (job failed) | The primitive itself does not render errors — the screen swaps to an error state ([`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md) / ERROR_STATES); a stalled bar never silently reads as "in progress forever" |

# Tokens Used

| Role | Token | Utility |
|---|---|---|
| Track | `ink-3` | `bg-ink-3` |
| Default fill | `accent` | `bg-accent` |
| Multi-instance fill | `ink-9` | `bg-ink-9` |
| Success fill | `positive` | `bg-positive` |
| Label text | `ink-11` | `text-ink-11` |
| Value text | `ink-9` (tabular, `dir="ltr"`) | `text-ink-9 font-mono tabular-nums` |
| Stepper upcoming ring / connector | `ink-6` | `ring-ink-6` / `bg-ink-6` |
| Complete-step glyph | `accent` or `positive` | `text-accent` / `text-positive` |
| Focus ring (interactive stepper node) | `accent` | `ring-accent` |
| Shape | `radius-full` | `rounded-full` |
| Fill / value motion | `motion.base` (200ms), `easeOut` | via `lib/motion.ts` |
| Indeterminate sweep | 1.1s loop, `easeInOut` | via `lib/motion.ts` |

All values resolve to [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md); the fill never hardcodes a hex.

# Accessibility

- **`role="progressbar"` with the value contract.** Determinate bars/rings expose `aria-valuemin`,
  `aria-valuemax`, and `aria-valuenow`; an **indeterminate** primitive **omits `aria-valuenow`** (its absence
  is how assistive tech announces "busy, unknown"). A localized `aria-valuetext` ("84 percent",
  "842 of 1,000 entries") is supplied when the raw number would be ambiguous.
- **Value is real text, not just an ARIA attribute.** When `showValue` is on, the figure is on-screen tabular
  text as well, so a sighted low-vision user reads it without a screen reader.
- **Stepper is an ordered list.** `Stepper` renders an `<ol>`; the active step carries `aria-current="step"`;
  each step's *state* (complete / current / upcoming) is conveyed by its glyph and label text, not by color
  alone (WCAG 1.4.1) — a completed step shows a `CircleCheck`, not merely a filled dot.
- **Reduced motion.** The determinate tween and the indeterminate sweep both respect
  `prefers-reduced-motion`: the tween becomes an instant set, and the sweep is replaced by a static partial
  fill plus a polite "Working…" announcement — never a bare looping animation with no static equivalent
  ([`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) Motion).
- **Non-text contrast.** The fill against the `ink-3` track clears the 3:1 non-text floor in both themes.
- **Live updates are polite.** A completing job announces once (`aria-live="polite"`), not on every frame.

# Theming, Dark Mode & RTL

**Dark mode** re-points the tokens: the `ink-3` track → `#24211A`, `accent` fill → `#D9B96C` — the accent
*lightens* rather than saturates, keeping the fill legible against the dark track with no separate asset.

**RTL — fill direction is the load-bearing detail:**

- The linear fill grows from the **inline-start** edge, so in Arabic it fills **right-to-left**. This is
  achieved with `transform-origin: inline-start` and, for the indeterminate sweep, animating
  `insetInlineStart` — never a hardcoded `left`/`transform-origin: left`, which would fill the wrong way in
  RTL.
- The **circular arc** sweeps clockwise from 12 o'clock in both directions (a clock is not mirrored), but the
  centered value text is bidi-safe and any leading/trailing caption uses logical `ms-*`/`me-*`.
- The **Stepper** order reverses horizontally in RTL — step 1 sits on the inline-start (right) edge — because
  nodes and connectors are laid out with flex + logical spacing; the `CircleCheck` and node dots are
  symmetric and do not mirror, while a directional "next" chevron between steps *does* (`mirrorRtl`), per
  [`../ICON_SYSTEM.md`](../ICON_SYSTEM.md).
- The value/label row uses `justify-between` so the label and figure swap sides correctly under `dir`.

# Do / Don't

| Do | Don't |
|---|---|
| Use `Progress` only for a bounded, completing quantity (value + max) | Use it to show an AI confidence band — that is `ConfidenceMeter` |
| Use `AgingBar` for a proportional breakdown | Fake an aging breakdown with a stacked `Progress` |
| Default to `accent`, switch to `ink-9` when many bars coexist | Paint a list of ten bars all in brass |
| Grow the fill from the inline-start edge via `transform-origin: inline-start` | Hardcode `transform-origin: left` so RTL fills backwards |
| Omit `aria-valuenow` on an indeterminate bar | Report a fake `aria-valuenow` for unknown-length work |
| Render the value as real tabular text and as ARIA | Bury the number inside the fill where it clips |
| Convey stepper state with glyph + label | Rely on a filled-vs-empty dot color alone |
| Replace the indeterminate sweep with a static fill + "Working…" under reduced motion | Leave a bare looping animation for reduced-motion users |

# Usage & Composition

```tsx
// A single focal import job — accent, determinate, with a live value
<Progress
  label={t('import.journalEntries')}
  value={imported}
  max={total}
  showValue
  valueText={t('progress.ofEntries', { done: imported, total })}
/>
```

```tsx
// Journal-entry lifecycle as an ordered stepper (RTL-safe, positive on commit)
<Stepper
  current={jeStepIndex}
  completedTone="positive"
  steps={[
    { id: 'draft',   label: t('je.draft') },
    { id: 'review',  label: t('je.review') },
    { id: 'approve', label: t('je.approve') },
    { id: 'post',    label: t('je.post') },
  ]}
/>
```

```tsx
// A compact completion ring on a KPI tile (period close)
<ProgressRing value={closedTasks} max={totalTasks} size="sm"
  valueText={t('close.percent', { pct: Math.round((closedTasks / totalTasks) * 100) })} />
```

`Progress` composes into `KpiTile`, import/export drawers, onboarding, and period-close checklists. For AI
confidence use `ConfidenceMeter`/`ConfidenceBadge`
([`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md)); for AR/AP breakdowns
use `AgingBar` ([`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md)). Sibling
primitives: [`./TAG.md`](./TAG.md), [`./AVATAR.md`](./AVATAR.md), [`./TIMELINE.md`](./TIMELINE.md).

# End of Document
