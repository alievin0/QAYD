# Animation Guidelines — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: ANIMATION_GUIDELINES
---

# Purpose

This document is the applied catalogue of QAYD's animations: every named, reusable animation the product
ships, each pinned to a duration token, an easing curve, a trigger, a Framer Motion snippet, and its
`prefers-reduced-motion` behavior. It is the specialization of [MOTION_SYSTEM.md](./MOTION_SYSTEM.md) (which
owns the tokens and principles) into a lookup an engineer or an AI coding agent can implement from without a
follow-up question — "what animation does a drawer use" resolves to a row here, not to a designer's memory.

It draws its tokens verbatim from [MOTION_SYSTEM.md](./MOTION_SYSTEM.md) and from the `Motion` section of
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md), and it honors the reduced-motion contract
of [../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md). It invents no new timing. Where a component
document — [../frontend/components/DRAWERS.md](../frontend/components/DRAWERS.md),
[../frontend/components/MODALS.md](../frontend/components/MODALS.md),
[../frontend/components/AI_WIDGETS.md](../frontend/components/AI_WIDGETS.md),
[../frontend/components/LOADING_STATES.md](../frontend/components/LOADING_STATES.md) — describes an
animation, this document is the authoritative statement of its motion values, and the component doc points
here rather than restating a possibly-drifting copy.

[MICRO_INTERACTIONS.md](./MICRO_INTERACTIONS.md) is the sibling to this document: it covers the small
state-feedback animations (hover, focus, press, toggle, copy-confirm, inline validation). This document
covers the larger, composed animations — panels entering, content loading, values filling, lists arriving.

# The Catalogue at a Glance

Every animation in QAYD's product chrome is one of the following. There is no animation in the authenticated
app that is not represented here; a new one is a new row proposed against this document, reviewed once.

| # | Animation | Duration | Easing | Trigger | Reduced motion |
|---|---|---|---|---|---|
| 1 | Drawer / sheet slide | `moderate` in / `fast` out | `easeOut` / `easeIn` | Panel opens over a list or detail | Appears/disappears instantly |
| 2 | Modal / dialog fade + scale | `moderate` in / `fast` out | `easeOut` / `easeIn` | Dialog opens | Appears/disappears instantly |
| 3 | Toast slide + fade | `moderate` in / `fast` out | `easeOut` / `easeIn` | A notification is enqueued | Appears/disappears instantly, no slide |
| 4 | Page / route cross-fade | `slow` | `easeOut` | App Router client navigation | Content swaps instantly |
| 5 | Skeleton shimmer | `1.6s` loop | `linear` | First-load of known-shape content | Static `ink-3` fill, no sweep |
| 6 | Confidence-meter fill | `base` | `easeOut` | An AI value/proposal renders | Meter shows final fill instantly |
| 7 | List / table row stagger | `base` per row, 30ms stagger | `easeOut` | Non-virtualized list first paint | All rows appear at once, instantly |
| 8 | Tab underline slide | `base` | `easeInOut` | Active tab changes | Underline jumps to new tab instantly |
| 9 | Accordion / disclosure reveal | `base` | `easeInOut` | Section expands/collapses | Height changes instantly |
| 10 | Realtime row flash | `600ms` decay | `easeInOut` | A Reverb event updates a row | Row updates with no flash |
| 11 | AI "thinking" dots | `1.2s` staggered loop | custom pulse | AI is streaming/working | Dots shown static, no pulse |
| 12 | Success checkmark | `base` in, hold 900ms, `fast` out | `easeOut` / `easeIn` | A financial action succeeds | Checkmark appears then clears, no scale |

# Enter/Exit Panels

## 1. Drawer / sheet slide

Drawers ([../frontend/components/DRAWERS.md](../frontend/components/DRAWERS.md)) carry a row's detail, an AI
approval, or a mobile navigation surface. They slide in from the **inline-end edge** (right in LTR, left in
Arabic) so the panel visibly belongs to the layout's trailing edge, paired with a scrim dimmer than a
modal's. The slide is authored in logical terms per [MOTION_SYSTEM.md](./MOTION_SYSTEM.md) `RTL-Aware
Directional Motion`, so it mirrors automatically.

- **Duration/easing:** `moderate` (280ms) in with `easeOut`; `fast` (160ms) out with `easeIn`. Scrim fades
  on the same timeline.
- **Trigger:** opening a detail/approval drawer, or the mobile sidebar-as-sheet.
- **Reduced motion:** the panel appears and disappears instantly — no slide, no scrim fade. Focus move,
  scroll lock, and the focus trap are unchanged; only the animation is removed.

```tsx
import { motion, AnimatePresence, useReducedMotion } from "framer-motion";
import { duration, easeOut, easeIn, useInlineDirection } from "@/lib/motion";

export function DrawerSurface({ open, onClose, children }: DrawerProps) {
  const reduced = useReducedMotion();
  const sign = useInlineDirection(); // +1 LTR, -1 RTL
  return (
    <AnimatePresence>
      {open && (
        <>
          <motion.div
            className="fixed inset-0 z-overlay bg-ink-12/40"
            initial={reduced ? false : { opacity: 0 }}
            animate={reduced ? undefined : { opacity: 1 }}
            exit={reduced ? undefined : { opacity: 0 }}
            transition={{ duration: reduced ? 0 : duration.moderate, ease: easeOut }}
            onClick={onClose}
          />
          <motion.aside
            className="fixed inset-block-0 inline-end-0 z-modal w-[min(28rem,90vw)] border-s border-ink-6 bg-ink-2 shadow-lg dark:bg-ink-3"
            initial={reduced ? false : { x: sign * 32, opacity: 0 }}
            animate={reduced ? undefined : { x: 0, opacity: 1 }}
            exit={{ x: reduced ? 0 : sign * 32, opacity: 0 }}
            transition={{ duration: reduced ? 0 : duration.moderate, ease: easeOut }}
          >
            {children}
          </motion.aside>
        </>
      )}
    </AnimatePresence>
  );
}
```

## 2. Modal / dialog fade + scale

Dialogs ([../frontend/components/MODALS.md](../frontend/components/MODALS.md)) are centered surfaces for a
focused decision. They do **not** slide from an edge — a centered dialog has no edge to belong to — instead
they scale from `0.98` to `1` while fading, with a coordinated scrim. The subtle scale reads as the surface
settling into place, not arriving from off-screen.

- **Duration/easing:** `moderate` (280ms) in with `easeOut`; `fast` (160ms) out with `easeIn`. Scrim on the
  same timeline.
- **Trigger:** any `Dialog`/`AlertDialog` opening.
- **Reduced motion:** scrim and panel appear instantly with no scale or fade; focus move, scroll lock, and
  trap are unchanged.

```tsx
import { motion, AnimatePresence, useReducedMotion } from "framer-motion";
import { surfaceScale, scrim, duration } from "@/lib/motion";

export function ModalSurface({ open, children }: { open: boolean; children: React.ReactNode }) {
  const reduced = useReducedMotion();
  const V = reduced ? { initial: false } : {};
  return (
    <AnimatePresence>
      {open && (
        <>
          <motion.div className="fixed inset-0 z-overlay bg-ink-12/50" variants={scrim} {...V}
            initial="initial" animate="animate" exit="exit" />
          <motion.div
            role="dialog" aria-modal="true"
            className="fixed left-1/2 top-1/2 z-modal -translate-x-1/2 -translate-y-1/2 rounded-xl border border-ink-6 bg-ink-1 p-6 shadow-lg dark:bg-ink-3"
            variants={surfaceScale}
            initial={reduced ? false : "initial"}
            animate={reduced ? undefined : "animate"}
            exit={reduced ? undefined : "exit"}
            transition={{ duration: reduced ? 0 : duration.moderate }}
          >
            {children}
          </motion.div>
        </>
      )}
    </AnimatePresence>
  );
}
```

## 3. Toast slide + fade

Toasts (Sonner) confirm a background completion or a non-blocking result. They slide in from the
**block-end edge** (the bottom — which, per [MOTION_SYSTEM.md](./MOTION_SYSTEM.md), does *not* mirror in
RTL) and fade. Critically, a toast **never moves focus** — it is announced via a live region
([../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md)), and its motion is purely presentational.

- **Duration/easing:** `moderate` in with `easeOut`; `fast` out with `easeIn`.
- **Trigger:** a queued notification ("Draft auto-saved.", "Entry JE-2026-00184 posted.").
- **Reduced motion:** the toast appears and disappears in place with no slide; it still auto-dismisses on
  its timer and is still announced.

```tsx
import { motion } from "framer-motion";
import { duration, easeOut, easeIn, useReducedMotion } from "@/lib/motion";

export function Toast({ children }: { children: React.ReactNode }) {
  const reduced = useReducedMotion();
  return (
    <motion.div
      className="z-toast rounded-lg border border-ink-6 bg-ink-1 p-4 shadow-md dark:bg-ink-3"
      initial={reduced ? false : { y: 16, opacity: 0 }}
      animate={reduced ? undefined : { y: 0, opacity: 1 }}
      exit={reduced ? { opacity: 0 } : { y: 8, opacity: 0 }}
      transition={{ duration: reduced ? 0 : duration.moderate, ease: easeOut }}
    >
      {children}
    </motion.div>
  );
}
```

## 4. Page / route cross-fade

An App Router client navigation swaps the page's content with **a cross-fade only** — no slide, no push.
A slide would imply a spatial ordering of pages (this one is "left of" that one) that QAYD's flat
information architecture does not have. The cross-fade is the longest transition in the product at `slow`
(360ms), because a whole page changing is the one moment a slightly longer beat reads as "settling," not
lagging. This pairs with the accessibility route-announcer (focus to the new `<h1>`, live-region title
announcement) from [../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) `Focus Management`.

- **Reduced motion:** content swaps instantly; the focus move and live-region announcement still fire (they
  are not motion, they are the state change).

```tsx
"use client";
import { motion } from "framer-motion";
import { usePathname } from "next/navigation";
import { duration, easeOut, useReducedMotion } from "@/lib/motion";

export function PageTransition({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const reduced = useReducedMotion();
  return (
    <motion.div
      key={pathname}
      initial={reduced ? false : { opacity: 0 }}
      animate={reduced ? undefined : { opacity: 1 }}
      transition={{ duration: reduced ? 0 : duration.slow, ease: easeOut }}
    >
      {children}
    </motion.div>
  );
}
```

# Loading & Optimistic Motion

## 5. Skeleton shimmer

The primary loading state for content with a **known shape** (table rows, KPI values, a hydrating form) is a
skeleton with a slow, low-contrast shimmer — never a spinner
([../frontend/components/LOADING_STATES.md](../frontend/components/LOADING_STATES.md)). The sweep is `1.6s`,
`linear`, infinite, running a subtle `ink-3 → ink-4 → ink-3` gradient along the inline axis (so its travel
mirrors under RTL).

- **Trigger:** first client-side load of shaped content. Not for a background refetch of already-shown data
  (that keeps prior rows at reduced opacity with `aria-busy`, no skeleton flash).
- **Reduced motion:** the sweep is removed entirely and replaced with a **static** `ink-3` fill — the
  placeholder still communicates "loading" by presence and `aria-busy`, without any motion.

```css
/* app/globals.css — the single shimmer definition the whole app shares */
@keyframes qayd-shimmer {
  from { background-position: 200% 0; }
  to   { background-position: -200% 0; }
}
.animate-shimmer {
  background-image: linear-gradient(90deg,
    var(--qayd-ink-3) 25%, var(--qayd-ink-4) 37%, var(--qayd-ink-3) 63%);
  background-size: 200% 100%;
  animation: qayd-shimmer 1.6s linear infinite;
}
@media (prefers-reduced-motion: reduce) {
  .animate-shimmer { animation: none; background-image: none; background-color: var(--qayd-ink-3); }
}
```

```tsx
// components/ui/skeleton.tsx
export function Skeleton({ className }: { className?: string }) {
  return <div aria-hidden className={cn("animate-shimmer rounded-md", className)} />;
}
```

Note the shimmer is CSS, not Framer Motion — it is a continuous ambient loop with no state transition, one
of the two documented exceptions (with the AI thinking-dots) to the "Framer Motion end to end" rule, because
a keyframe loop is genuinely simpler and cheaper in raw CSS and has no reduced-motion branching Framer needs
to manage.

## Optimistic and in-flight motion

QAYD prefers **optimistic UI with restrained motion** over spinners wherever a mutation's success is the
overwhelmingly likely outcome. When a user posts a journal entry, the entry appears in the list immediately
(optimistically) via `fadeUp`, and the in-flight state is carried by the primary button's own inline spinner
(width preserved, no layout jump), not by a full-screen or full-panel loader:

- The submitting button shows a `Loader2` spinner (`animate-spin motion-reduce:animate-none`) in place of
  its label's leading icon; its width is fixed so the button never resizes mid-submit.
- The optimistically-added row carries the AI/pending provenance dot until the server confirms; on
  confirmation the dot clears with no additional animation.
- On failure, the optimistic row is removed (reverse `fadeUp` exit) and the error surfaces via the form's
  error summary — see [../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) `Forms Accessibility`.

A spinner is only ever the *primary* loading indicator for content of **unknown shape or duration** — a
shapeless indeterminate action — never for the first render of a page whose layout QAYD already knows.

# AI-Specific Motion

## 6. Confidence-meter fill

The `ConfidenceMeter` ([../frontend/components/AI_WIDGETS.md](../frontend/components/AI_WIDGETS.md)) is a
segmented glyph that fills to encode an AI value's confidence band. It is Framer-Motion-filled: on mount the
track animates from empty to the band's fill level, so the fill reads as the system *measuring* the value.
Per the AI contract, the band is distinguished by three redundant cues — fill level, track style, and a
qualitative label — never by a red/amber/green traffic light.

| Band | Range | Fill target | Track style | Tone |
|---|---|---|---|---|
| High | `≥ 0.85` | full track | solid | `accent` |
| Medium | `0.60 – 0.849` | ~⅔ track | solid | `ink-9` |
| Low | `< 0.60` | ≤ ⅓ track | hollow / dashed | `ink-9` — and the output is withheld from auto-execution |

- **Duration/easing:** `base` (200ms), `easeOut` — the fill settles into its level, it does not spring past
  it.
- **Trigger:** an AI-authored value, proposal header, or draft field mounting.
- **Reduced motion:** the meter renders at its final fill level with no animated fill — the band is still
  fully conveyed by level, style, and label.

```tsx
import { motion, useReducedMotion } from "framer-motion";
import { duration, easeOut } from "@/lib/motion";

export function ConfidenceMeter({ confidence }: { confidence: number }) { // 0–1, pre-normalized
  const reduced = useReducedMotion();
  const target = confidence >= 0.85 ? 1 : confidence >= 0.6 ? 0.66 : 0.33;
  const tone = confidence >= 0.85 ? "bg-accent" : "bg-ink-9";
  return (
    <div aria-hidden className="h-1.5 w-16 overflow-hidden rounded-full bg-ink-4">
      <motion.div
        className={cn("h-full rounded-full", tone)}
        initial={reduced ? false : { scaleX: 0 }}
        animate={{ scaleX: target }}
        style={{ originX: 0 }} // grows from the inline-start; originX flips with dir at the wrapper
        transition={{ duration: reduced ? 0 : duration.base, ease: easeOut }}
      />
    </div>
  );
}
```

The accessible confidence value is carried by an adjacent real text node ("92% confidence"), never by the
meter's width alone — the meter is `aria-hidden`, per [../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md).

## 11. AI "thinking" dots

While the assistant streams or works, QAYD shows three `accent-subtle` dots pulsing their opacity `0.3 → 1
→ 0.3` on a staggered `1.2s` loop — deliberately calmer and slower than a typical chat-app typing indicator,
matching the product's register. The response container carries `role="status"` and `aria-busy="true"`
while working, flipped to `false` on completion, and the stream is announced once at start and once at end,
never token-by-token.

- **Reduced motion:** the three dots are shown static (full opacity), no pulse; `aria-busy` still conveys the
  working state to assistive technology.

```tsx
import { motion, useReducedMotion } from "framer-motion";

export function ThinkingDots() {
  const reduced = useReducedMotion();
  return (
    <span role="status" aria-busy="true" aria-label="Working" className="inline-flex gap-1">
      {[0, 1, 2].map((i) => (
        <motion.span
          key={i}
          className="size-1.5 rounded-full bg-accent-subtle"
          animate={reduced ? undefined : { opacity: [0.3, 1, 0.3] }}
          transition={reduced ? undefined : { duration: 1.2, ease: "easeInOut", repeat: Infinity, delay: i * 0.15 }}
        />
      ))}
    </span>
  );
}
```

# Structural Motion

## 7. List / table row stagger

A non-virtualized list or table animates its rows in with `fadeUp`, staggered at 30ms per row and **capped
at 12 rows** — beyond the cap, remaining rows appear together in the twelfth slot, so a large table never
takes multiple seconds to finish. Virtualized tables (General Ledger, Journal Entries history, Bank
Reconciliation) do not stagger at all; their rows enter and leave the DOM on scroll and appear instantly.
Full rationale and the parent/child implementation are in [MOTION_SYSTEM.md](./MOTION_SYSTEM.md)
`Choreography`.

- **Reduced motion:** all rows appear at once, instantly, with no per-row fade or offset.

## 8. Tab underline slide

When the active tab in a segmented control or tab strip changes, the active-indicator underline slides from
the old tab to the new one via Framer's shared-layout `layoutId`, so it reads as one indicator moving rather
than one disappearing and another appearing.

- **Duration/easing:** `base` (200ms), `easeInOut` (a toggle between two equal states).
- **Reduced motion:** the underline jumps to the new tab with no slide.

```tsx
import { motion, useReducedMotion } from "framer-motion";
import { duration, easeInOut } from "@/lib/motion";

export function Tab({ active, children }: { active: boolean; children: React.ReactNode }) {
  const reduced = useReducedMotion();
  return (
    <button className="relative px-3 py-2 text-text-sm text-ink-11" role="tab" aria-selected={active}>
      {children}
      {active && (
        <motion.span
          layoutId="tab-underline"
          className="absolute inset-x-0 bottom-0 h-0.5 bg-accent"
          transition={reduced ? { duration: 0 } : { duration: duration.base, ease: easeInOut }}
        />
      )}
    </button>
  );
}
```

## 9. Accordion / disclosure reveal

An expanding section (an accordion, an AI reasoning disclosure — collapsed by default so a confident
reviewer clearing a queue is not forced to read every rationale) animates `height: 0 → auto` with a
coordinated opacity fade. This is the one sanctioned layout-animating pattern from
[MOTION_SYSTEM.md](./MOTION_SYSTEM.md) `Performance` — accepted because it touches a single element, runs at
`base`, and never runs on many elements at once.

- **Duration/easing:** `base` (200ms), `easeInOut`.
- **Reduced motion:** the section's height and opacity change instantly.

```tsx
import { motion, AnimatePresence, useReducedMotion } from "framer-motion";
import { duration, easeInOut } from "@/lib/motion";

export function Disclosure({ open, children }: { open: boolean; children: React.ReactNode }) {
  const reduced = useReducedMotion();
  return (
    <AnimatePresence initial={false}>
      {open && (
        <motion.div
          initial={reduced ? false : { height: 0, opacity: 0 }}
          animate={reduced ? undefined : { height: "auto", opacity: 1 }}
          exit={reduced ? undefined : { height: 0, opacity: 0 }}
          transition={{ duration: reduced ? 0 : duration.base, ease: easeInOut }}
          className="overflow-hidden"
        >
          {children}
        </motion.div>
      )}
    </AnimatePresence>
  );
}
```

## 10. Realtime row flash

When a Reverb realtime event updates a row the user may be reading (another user posts an entry, a
reconciliation agent matches a line), the affected row's background flashes to `accent-subtle` and eases
back to transparent over `600ms` (`easeInOut`) — no toast, no sound, and critically **no layout shift**: the
flash changes `background-color` only, never height or padding, so a burst of updates never reflows the rows
under the reader's eye. This is the one background-color animation QAYD permits, justified because it touches
exactly one row and never during a scroll.

- **Reduced motion:** the row updates its content with no flash at all; the new value is simply present.

```tsx
import { motion, useReducedMotion } from "framer-motion";

export function RealtimeRow({ flashKey, children }: { flashKey: number; children: React.ReactNode }) {
  const reduced = useReducedMotion();
  return (
    <motion.tr
      key={flashKey}
      initial={false}
      animate={reduced ? undefined : { backgroundColor: ["var(--qayd-accent-subtle)", "rgba(0,0,0,0)"] }}
      transition={reduced ? undefined : { duration: 0.6, ease: "easeInOut" }}
    >
      {children}
    </motion.tr>
  );
}
```

## 12. Success checkmark

The correct feedback for a successful financial action (an entry posted, a statement reconciled) is a single
quiet checkmark: it fades and scales in over `base`, holds for 900ms, then fades out — **no confetti, no
bounce, no particle burst, no sound by default.** This is the direct expression of the design language's
motion principle that a balanced, posted ledger deserves quiet certainty, not a celebration.

- **Reduced motion:** the checkmark appears (no scale), holds, and clears — the confirmation is still shown.

```tsx
import { motion, AnimatePresence, useReducedMotion } from "framer-motion";
import { duration, easeOut, easeIn } from "@/lib/motion";
import { Check } from "lucide-react";

export function SuccessCheck({ show }: { show: boolean }) {
  const reduced = useReducedMotion();
  return (
    <AnimatePresence>
      {show && (
        <motion.span
          className="inline-flex text-positive"
          initial={reduced ? { opacity: 0 } : { opacity: 0, scale: 0.9 }}
          animate={reduced ? { opacity: 1 } : { opacity: 1, scale: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: reduced ? 0 : duration.base, ease: easeOut }}
        >
          <Check aria-hidden className="size-4" />
        </motion.span>
      )}
    </AnimatePresence>
  );
}
```

# Do & Don't

| Do | Don't |
|---|---|
| Use a slow, low-contrast skeleton shimmer for known-shape content | Use a bouncing spinner as the primary loading state |
| Reserve the spinner for shapeless/unknown-duration in-flight actions | Show a full-panel spinner on a page whose layout you already know |
| Confirm a financial action with a single quiet checkmark | Fire confetti, particles, or a bounce on a posted entry |
| Cross-fade route changes | Slide/push pages (implies a spatial order the IA lacks) |
| Cap the row stagger at 12 and skip it entirely for virtualized tables | Stagger 200 ledger rows at 30ms each (a six-second animation) |
| Fill a confidence meter to a band level once, on mount | Loop or pulse a confidence meter to draw attention |
| Slide drawers from the inline-end edge (mirrors in RTL) | Hardcode a drawer sliding from the physical right |
| Flash a realtime-updated row's background only, over 600ms | Splice new rows in with a layout shift under a reader's eye |
| Give every animation a `prefers-reduced-motion` path to instant | Ship an animation that plays identically with the preference on |
| Let optimistic UI + an inline button spinner carry an in-flight mutation | Block the whole screen behind a modal spinner for a fast, likely-success mutation |
| Keep every product-chrome animation ≤ `motion.slow` (360ms) | Chain multi-step animations or exceed the duration ceiling for effect |
| Animate `transform`/`opacity` (plus one scoped `height` reveal) | Animate `left`/`top`/`margin`/`box-shadow` in a dense table |

# Reduced Motion — Per-Animation Summary

The reduced-motion behavior is not a global on/off; each animation degrades to a specific instant state. This
table is the checklist the release-gate reduced-motion pass ([../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md)
`Testing`) verifies.

| Animation | Reduced-motion behavior | State still conveyed by |
|---|---|---|
| Drawer / sheet | Instant appear/disappear, no slide/scrim fade | Presence, focus move, scroll lock |
| Modal / dialog | Instant appear/disappear, no scale/fade | Presence, focus trap |
| Toast | Instant appear in place, no slide | Presence + live-region announcement |
| Route cross-fade | Instant content swap | Focus to new `<h1>` + live-region title |
| Skeleton shimmer | Static `ink-3` fill, no sweep | Presence + `aria-busy` |
| Confidence meter | Rendered at final fill, no animated fill | Fill level + track style + text label |
| Row stagger | All rows at once, instantly | The rows themselves |
| Tab underline | Jumps to new tab, no slide | `aria-selected` + underline position |
| Accordion / disclosure | Height/opacity change instantly | `aria-expanded` + content presence |
| Realtime row flash | No flash; value simply updates | The updated value + a "N new" banner where applicable |
| AI thinking dots | Static dots, no pulse | `role="status"` + `aria-busy` |
| Success checkmark | Appears (no scale), holds, clears | The checkmark + a live-region confirmation |

# End of Document
