# Motion System — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: MOTION_SYSTEM
---

# Purpose

This document is the motion foundation for QAYD's Next.js 15 frontend. It defines the primitives every
moving pixel is built from — the duration scale, the easing curves, the Framer Motion setup, the
reduced-motion contract, the choreography rules, and the performance envelope — so that
[ANIMATION_GUIDELINES.md](./ANIMATION_GUIDELINES.md) (the catalogue of named animations) and
[MICRO_INTERACTIONS.md](./MICRO_INTERACTIONS.md) (the state-feedback layer) can specialize a single
vocabulary rather than each inventing their own timings. Where those two documents describe *which*
animation plays on *which* surface, this document decides *how fast*, *on what curve*, *toward what axis*,
and *what happens when the user has asked for less motion*.

It is the design-system-level restatement of the `Motion` section of
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md) and the reduced-motion rules of
[../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md). Those documents remain authoritative for the
tokens themselves; this document does not invent a single new duration or curve. It exposes the same six
durations and three curves as a self-contained specification, adds the choreography and performance rules
that were implicit in the named-patterns table, and pins the RTL-aware directional rules that a purely
LTR-trained engineer will otherwise get wrong.

Every rule below resolves to a value in `lib/motion.ts`, a Framer Motion variant, or a CSS declaration in
`app/globals.css` — nothing here is aspirational, and nothing here overrides the fixed stack: Framer Motion
end to end, React 19, TypeScript strict, Tailwind CSS-variable tokens.

# Motion Principles

QAYD is an AI Financial Operating System. Posting a journal entry, approving a bank transfer, and
reconciling a statement are not game mechanics, and the motion system exists to make those actions feel
*certain*, never *delightful*. Five principles govern every decision in this document; the rest of the
document is their mechanical implementation.

**1. Purposeful — motion answers a question, never fills a silence.** Every animation in QAYD resolves one
of exactly three questions: *where did this come from* (a drawer sliding from the inline edge tells you it
belongs to the row you clicked, not the page), *what just changed* (a realtime row flashing `accent-subtle`
tells you which of 400 rows another user just touched), or *is the system still working* (a shimmer or a
confidence meter filling tells you a value is being resolved). If a proposed animation answers none of
those three questions, it is decoration and does not ship. There is no "for visual interest" motion in the
authenticated product.

**2. Calm — double-digit-to-low-triple-digit milliseconds, never a spring.** QAYD's entire duration scale
tops out at 360ms, and the longest curve is a smooth ease, never an overshoot. The product never bounces,
wobbles, confetti-bursts, or spring-oscillates a financial state change. The correct celebration for a
balanced, posted journal entry is a single quiet checkmark, not a fireworks animation — motion is
calibrated to feel like a well-made physical switch (a short, certain click), not a reward.

**3. Reversible — an exit is the mirror of its entrance, and every animated state can be undone.** A panel
that scaled and faded in scales and fades out; a drawer that slid from the inline-end edge slides back to
that same edge. Motion never leaves the interface in a state the user cannot visually retrace, and it never
implies a spatial model the app's information architecture does not actually have (which is why route
transitions cross-fade rather than slide — a slide would imply a left/right page ordering that does not
exist).

**4. Respectful of reduced motion — every animation collapses to an instant, complete state change.** When
`prefers-reduced-motion: reduce` is set, QAYD removes the *animation of* a change, never the *change
itself*. A drawer under reduced motion still opens — instantly. A toast still appears — instantly. A
confidence meter still shows its final fill — instantly. This is the correct reading of the media query
(users who set it still expect the UI to update), and it is a hard requirement, not a best-effort: an
animation with no reduced-motion path is an accessibility defect, not a polish gap.

**5. Consistent — one vocabulary, centralized in one file.** All motion draws from `lib/motion.ts`. No
component hand-writes a `transition` object with an ad-hoc duration, and no interactive component uses a
raw CSS `transition` where a shared token would do. A new curve or a new duration is a change to this
document and to `lib/motion.ts`, reviewed once, not a one-off buried in a single component.

# The Duration Scale

QAYD's motion runs on exactly six durations. There is no seventh; a designer or engineer who feels a
seventh is needed is describing a gap in this table, to be raised here, not a local exception. The scale is
deliberately coarse — six well-separated steps are easier to apply consistently than a fine-grained ramp
where `180ms` and `200ms` are indistinguishable in use but multiply the decisions.

| Token | Duration | Seconds (Framer) | Character | Canonical use |
|---|---|---|---|---|
| `motion.instant` | 0ms | `0` | No animation | The reduced-motion value for *every* pattern; the checkbox/toggle visual flip when motion is off |
| `motion.micro` | 120ms | `0.12` | Barely perceptible | Button press scale, checkbox check, icon hover, switch thumb travel |
| `motion.fast` | 160ms | `0.16` | Quick acknowledgement | Tooltip/dropdown/popover appear, table row hover highlight, exit half of a `fadeUp` |
| `motion.base` | 200ms | `0.20` | The workhorse | Tab switch, accordion expand, panel content swap, success checkmark in |
| `motion.moderate` | 280ms | `0.28` | Deliberate entrance | Modal/drawer/sheet enter and exit, popover carrying real content, scrim fade |
| `motion.slow` | 360ms | `0.36` | The longest allowed | Route/page cross-fade, onboarding step transition, the realtime-row flash decay |

Two durations sit outside this table as *loop periods*, not transition durations, because they describe a
repeating ambient state rather than a one-shot A→B transition, and are documented in
[ANIMATION_GUIDELINES.md](./ANIMATION_GUIDELINES.md): the skeleton shimmer sweep (`1.6s`, linear, infinite)
and the AI "thinking" dot pulse (`1.2s`, staggered loop). Neither is a member of the duration scale and
neither is ever used as a transition duration.

```ts
// lib/motion.ts — the duration scale, in seconds for Framer Motion
export const duration = {
  instant: 0,
  micro: 0.12,
  fast: 0.16,
  base: 0.2,
  moderate: 0.28,
  slow: 0.36,
} as const;

export type DurationToken = keyof typeof duration;
```

The same values are mirrored as CSS custom properties for the handful of non-Framer surfaces (a native
`<details>` disclosure, a `scroll-behavior`, a Tailwind `transition-*` utility on a purely presentational
element) that cannot import a JS module:

```css
/* app/globals.css */
:root {
  --motion-instant: 0ms;
  --motion-micro: 120ms;
  --motion-fast: 160ms;
  --motion-base: 200ms;
  --motion-moderate: 280ms;
  --motion-slow: 360ms;
}
```

# The Easing Curves

QAYD uses exactly three cubic-bézier curves, each mapped to a direction of change. Easing is not chosen per
component by feel; it is chosen by *what the motion is doing*, which makes the whole product's motion feel
authored by one hand.

| Token | `cubic-bezier` | Motion role | Why this curve |
|---|---|---|---|
| `easeOut` | `[0.16, 1, 0.3, 1]` | **Entrances** — anything appearing or moving *in* | Fast start, long gentle settle: the element arrives decisively then eases to rest, reading as "placed," not "thrown" |
| `easeInOut` | `[0.65, 0, 0.35, 1]` | **Toggles & size changes** — expand/collapse, a value moving from one state to another, a press | Symmetric acceleration and deceleration: correct for a change with two equally-weighted ends (open↔closed) |
| `easeIn` | `[0.7, 0, 0.84, 0]` | **Exits** — anything leaving or moving *out* | Gentle start, fast finish: the element accelerates away, so a dismissal feels intentional and does not linger |

```ts
// lib/motion.ts — the three curves, as Framer Motion cubic-bezier arrays
export const easeOut = [0.16, 1, 0.3, 1] as const; // entrances
export const easeInOut = [0.65, 0, 0.35, 1] as const; // toggles, expand/collapse
export const easeIn = [0.7, 0, 0.84, 0] as const; // exits
```

The rule an engineer memorizes: **things coming in use `easeOut`; things going out use `easeIn`; things
that toggle in place use `easeInOut`.** A modal enter is `easeOut`; its exit is `easeIn`; an accordion
(which opens and closes symmetrically) is `easeInOut` in both directions. QAYD never uses a spring
(`type: "spring"`), never uses `"anticipate"`, and never uses a curve with an overshoot (a control point
outside the `0–1` range on the value axis) — those read as playful and violate Principle 2.

# Framer Motion Setup

Motion in QAYD is implemented with **Framer Motion end to end**. Interactive components never hand-roll a
CSS `transition` for their state changes, because a scattered set of `transition: all 200ms` declarations is
exactly how duration, easing, and reduced-motion handling drift out of sync across a codebase. Centralizing
in `lib/motion.ts` means a change to the base curve is one edit, and the reduced-motion contract is enforced
in one place rather than re-derived per component.

## The variant library

The reusable variants every component composes from live in `lib/motion.ts` alongside the tokens:

```ts
// lib/motion.ts
import type { Variants } from "framer-motion";

/** The default appear/disappear for content blocks, cards, popover bodies. */
export const fadeUp: Variants = {
  initial: { opacity: 0, y: 8 },
  animate: { opacity: 1, y: 0, transition: { duration: duration.base, ease: easeOut } },
  exit: { opacity: 0, y: 4, transition: { duration: duration.fast, ease: easeIn } },
};

/** Modal/drawer surface: a restrained scale + fade, never a slide-from-offscreen for centered dialogs. */
export const surfaceScale: Variants = {
  initial: { opacity: 0, scale: 0.98 },
  animate: { opacity: 1, scale: 1, transition: { duration: duration.moderate, ease: easeOut } },
  exit: { opacity: 0, scale: 0.98, transition: { duration: duration.fast, ease: easeIn } },
};

/** Scrim/backdrop behind a modal or drawer — fades at the same duration as the surface it dims. */
export const scrim: Variants = {
  initial: { opacity: 0 },
  animate: { opacity: 1, transition: { duration: duration.moderate, ease: easeOut } },
  exit: { opacity: 0, transition: { duration: duration.fast, ease: easeIn } },
};

/** Parent that staggers its children on enter — see Choreography for the 30ms/12-row cap. */
export const listStagger: Variants = {
  animate: { transition: { staggerChildren: 0.03 } },
};
```

## The `getTransition` helper

Every component obtains its `transition` object through a single helper rather than writing one inline, so
the reduced-motion branch cannot be forgotten at a call site:

```ts
// lib/motion.ts
import { useReducedMotion } from "framer-motion";

/** Returns the base transition, or an instant one when the user prefers reduced motion.
 *  Reduced motion removes the animation, never the state change — duration goes to 0,
 *  the target values are unchanged, so the element still ends up where it belongs. */
export function useTransition(base: { duration: number; ease: readonly number[] }) {
  const reduced = useReducedMotion();
  return reduced ? { duration: 0 } : base;
}
```

Because `useReducedMotion()` is a hook, `useTransition` is a hook, and is called at the top of a component,
never inside a render callback. For non-hook contexts (a variant defined at module scope), Framer Motion's
own `MotionConfig reducedMotion="user"` wrapper at the app root is the second line of defense — see below.

## The app-root wrapper

The authenticated layout wraps the tree once in `MotionConfig`, which teaches Framer Motion to honor the
OS-level reduced-motion setting globally, so even a component that forgets `useTransition` degrades safely:

```tsx
// app/(app)/layout.tsx (excerpt)
import { MotionConfig } from "framer-motion";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    // reducedMotion="user" → Framer disables transform/layout animations,
    // keeps opacity, when the OS setting is on. Our own useTransition is the
    // precise, per-component control; this is the safety net beneath it.
    <MotionConfig reducedMotion="user">
      {children}
    </MotionConfig>
  );
}
```

# The Reduced-Motion Contract

Reduced motion is a first-class requirement in QAYD, not a courtesy, and it has a single, precise
definition that this document repeats until it is unmissable: **reduced motion removes the animation of a
state change; it never removes the state change.** A drawer still opens, a toast still appears, a
confidence meter still shows its final value, a checkmark still confirms a post — all instantly, with no
tween. Users who set the preference are telling the OS "animate less," not "show me less."

## Three layers of enforcement

QAYD defends this contract at three levels, so a single missed branch never produces a jarring animation
for a reduced-motion user:

| Layer | Mechanism | Catches |
|---|---|---|
| Component | `useTransition()` from `lib/motion.ts` returns `{ duration: 0 }` when reduced | Every deliberate Framer transition in a component that uses the helper |
| App root | `<MotionConfig reducedMotion="user">` | Transform/layout animations in any component that forgot the helper |
| Global CSS | The `prefers-reduced-motion: reduce` media query below | Everything outside Framer's reach — native `<details>`, `scroll-behavior`, any Tailwind `transition-*` or `animate-*` utility |

```css
/* app/globals.css — the framework-independent safety net */
@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

The `0.01ms` (rather than a literal `0`) is the well-known idiom that reliably fires `transitionend` /
`animationend` events so any JS listener waiting on them still resolves — a hard `0s` can silently skip
those events in some engines, stranding a component that sequences work off an animation completing.

## The per-component pattern

Every animated component reads reduced motion and branches its variants, disabling the *transform* portion
(the `y`, the `scale`, the `x`) while keeping the state visible:

```tsx
// A representative component — see ANIMATION_GUIDELINES.md for the full catalogue
import { motion, AnimatePresence, useReducedMotion } from "framer-motion";
import { duration, easeOut } from "@/lib/motion";

export function Reveal({ open, children }: { open: boolean; children: React.ReactNode }) {
  const reduced = useReducedMotion();
  return (
    <AnimatePresence initial={false}>
      {open && (
        <motion.div
          initial={reduced ? false : { opacity: 0, height: 0 }}
          animate={reduced ? undefined : { opacity: 1, height: "auto" }}
          exit={reduced ? undefined : { opacity: 0, height: 0 }}
          transition={{ duration: reduced ? 0 : duration.base, ease: easeOut }}
        >
          {children}
        </motion.div>
      )}
    </AnimatePresence>
  );
}
```

Passing `initial={false}` under reduced motion is deliberate: it means the element mounts already in its
final state, so there is no first-frame flash of the "from" values before the `0`-duration transition
resolves.

# Choreography

## Enter and exit are not the same length

Entrances are given room to feel placed; exits are quicker so a dismissal never feels like it is dragging.
The `fadeUp` variant encodes this directly — `motion.base` (200ms) in, `motion.fast` (160ms) out — and
every enter/exit pair in the product follows the same asymmetry: the exit duration is one step down the
scale from the enter, on the `easeIn` curve. A modal enters over `moderate` and exits over `fast`; a
tooltip enters over `fast` and exits over `micro`. The one-step-faster-out rule keeps the interface feeling
responsive to dismissal without the exit reading as an abrupt cut.

## Stagger, and its hard cap

A list or table that animates its rows in on first load staggers them at **30ms per child** so the eye
reads them as arriving in sequence rather than all at once — but the stagger is **capped at 12 rows**.
Beyond the twelfth row, the remaining rows appear together in the twelfth slot's animation. This cap is not
optional polish; it is a correctness rule for a financial product whose tables routinely run to hundreds or
thousands of rows. A 200-row General Ledger staggered uncapped at 30ms would take six seconds to finish
"animating in" — an actively hostile experience for an accountant who wants to read line 180 now.

```tsx
// The capped stagger, implemented on the parent — children use `fadeUp`.
import { motion } from "framer-motion";
import { fadeUp, listStagger } from "@/lib/motion";

const STAGGER_CAP = 12;

export function StaggeredRows({ rows }: { rows: Row[] }) {
  return (
    <motion.tbody variants={listStagger} initial="initial" animate="animate">
      {rows.map((row, i) => (
        <motion.tr
          key={row.id}
          variants={fadeUp}
          // Rows past the cap inherit the cap's delay rather than compounding further.
          custom={Math.min(i, STAGGER_CAP)}
        >
          {/* cells */}
        </motion.tr>
      ))}
    </motion.tbody>
  );
}
```

Tables that virtualize (General Ledger, Journal Entries history, Bank Reconciliation lines — see
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md) `Data Density`) do **not** stagger at all:
rows enter and leave the DOM constantly as the virtual window scrolls, and animating each mount would make
scrolling shimmer distractingly. Virtualized rows appear instantly; the stagger is reserved for a
non-virtualized list's genuine first paint.

## Coordinated pairs animate on one timeline

When two elements belong to one gesture — a modal surface and its scrim, a drawer panel and its backdrop —
they animate on the *same* duration so they read as one object, not two loosely-related ones. The scrim
does not linger after the surface has gone, and it does not fade in first. Both use `motion.moderate` in and
`motion.fast` out. This is why `surfaceScale` and `scrim` in the variant library share their timings.

## Sequencing, not chaining

QAYD sequences at most two beats (a scrim fades as a panel scales in — but simultaneously, not one after the
other). It never builds a multi-step chained animation (element A finishes, then B starts, then C) for
product chrome. A chain reads as choreography-for-its-own-sake and violates Principle 1; if a sequence
genuinely needs two ordered beats (rare — a success checkmark that appears, holds, then fades), it is
expressed as a single variant with `times`/`delay`, kept under the `motion.slow` ceiling end-to-end, and
documented as a named pattern in [ANIMATION_GUIDELINES.md](./ANIMATION_GUIDELINES.md).

# Performance

Motion in a financial product must never make a dense table feel slow. QAYD's performance rules are not
optimizations applied after the fact; they are constraints on what may be animated in the first place.

## Animate only `transform` and `opacity`

The two properties the browser can animate on the compositor thread, without recalculating layout or
repainting, are `transform` (translate, scale, rotate) and `opacity`. **QAYD animates these two, and
effectively only these two.** A drawer slides via `transform: translateX()`, never via `left`/`inset-inline`.
A panel that "grows" scales via `transform: scale()` where the visual permits, and where a genuine
`height: auto` reveal is required (an accordion, a reasoning disclosure), it is accepted as the one
sanctioned layout-animating exception — kept short (`motion.base`), scoped to a single element, and never
run on more than one element at once on the same frame.

| Property | Compositor-friendly | Allowed in QAYD |
|---|---|---|
| `transform` (translate/scale/rotate) | Yes | Yes — the default for all movement |
| `opacity` | Yes | Yes — the default for all appear/disappear |
| `height` / `width` | No (triggers layout) | Only for a single disclosure reveal, `motion.base`, one element at a time |
| `top`/`left`/`inset-*`, `margin`, `padding` | No (triggers layout) | Never for animation — banned in review |
| `box-shadow`, `filter`, `background-color` | No (triggers paint) | Only on `:hover`/`:focus` micro-states, never in a list/table at density |

## No layout thrash on scroll or realtime updates

The realtime row-flash (a row's background easing from `accent-subtle` back to transparent when a Reverb
event updates it) animates `background-color`, an exception justified because it touches exactly one row and
never during a scroll. It must never cause a layout shift: the flash changes color only, never height,
padding, or border-width, so a burst of realtime updates in a table the user is reading never reflows the
rows under their eye. This is the motion-side of the accessibility rule in
[../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) that realtime pushes never yank or reflow what a
user is currently reading.

## `will-change` is applied narrowly and removed

`will-change: transform` is set on an element only for the duration it is actually animating (via a class
toggled on animation start and removed on `onAnimationComplete`), never left on statically — a permanent
`will-change` on many rows promotes every one to its own compositor layer and can cost more memory than the
animation saves.

```tsx
<motion.div
  onAnimationStart={() => el.current?.classList.add("will-change-transform")}
  onAnimationComplete={() => el.current?.classList.remove("will-change-transform")}
/>
```

## Respect the hardware

QAYD's audience includes back-office desktops that are not gaming rigs. `backdrop-filter: blur()` (the
`surface-glass` treatment) is reserved for exactly two never-in-a-table surfaces — the Command Palette and
the AI Command Center overlay — and is never animated (the blur is static; only the panel's opacity/scale
tweens). Animating a backdrop-blur is one of the most expensive things a browser can do and is banned
outright.

# RTL-Aware Directional Motion

QAYD ships English (LTR) and Arabic (RTL) from the same components, and **motion respects the inline
direction, not a hardcoded left/right.** A detail drawer slides in from the inline-end edge: that is the
right edge in English and the left edge in Arabic. A "next" transition moves toward the inline-end; in
Arabic that is leftward. Getting this wrong — sliding an Arabic drawer in from the physical right — makes
the Arabic product feel like a mechanical mirror of the English one, exactly the "flipped afterthought" the
design language forbids.

## Directional motion is authored in logical terms

The rule mirrors the layout rule in [../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md)
(`Spacing & Grid → RTL mirroring`): physical `x` offsets are never hardcoded in a variant that has a
direction. Instead, the sign of the offset is derived from the active direction at render time:

```tsx
// lib/motion.ts — a direction-aware slide for drawers/sheets/toasts
import { useMemo } from "react";

/** Returns +1 for LTR, -1 for RTL, so an "inline-end" offset is expressed once and mirrors automatically. */
export function useInlineDirection(): 1 | -1 {
  // Reads the resolved document direction; in QAYD `dir` is set on <html> by locale.
  const dir = typeof document !== "undefined" ? document.documentElement.dir : "ltr";
  return dir === "rtl" ? -1 : 1;
}

/** A slide that enters from the inline-end edge — right in LTR, left in RTL. */
export function useInlineSlide(distance = 24) {
  const sign = useInlineDirection();
  return useMemo(
    () => ({
      initial: { opacity: 0, x: sign * distance },
      animate: { opacity: 1, x: 0 },
      exit: { opacity: 0, x: sign * distance },
    }),
    [sign, distance],
  );
}
```

## What mirrors and what does not

| Motion | Mirrors in RTL? | Why |
|---|---|---|
| Drawer / sheet slide from the inline edge | Yes | The panel belongs to the inline-end of the layout, which flips with `dir` |
| Toast slide from the block-end edge | No (vertical) | The block axis (top/bottom) does not flip between LTR and RTL; only the inline axis does |
| "Next / previous" directional transitions | Yes | Forward reads toward the inline-end in both scripts |
| A chevron/arrow icon's rotation or flip | Yes (handled at the icon layer) | A directional glyph is mirrored via `scaleX(-1)` under `[dir="rtl"]`, per the iconography rule |
| Scale/opacity of a modal, a fade, a checkmark, a spinner rotation | No | These have no inline direction to mirror; a spinner rotates the same way in both |
| Numeral/amount rendering inside a moving row | No — stays `dir="ltr"` | Amounts never mirror even inside an RTL row (see the `Amount` primitive in the design language) |

The block axis versus inline axis distinction is the one engineers most often miss: a toast that enters
from the bottom enters from the bottom in Arabic too, because "bottom" is not a direction that mirrors. Only
motion along the writing axis (the axis that flips) mirrors.

# Where Motion Is — and Is Not — Allowed

Motion is a scarce resource in QAYD, spent where it answers one of the three questions in Principle 1 and
withheld everywhere else. This table is the allow-list; a surface not represented by a permitted pattern
here gets no motion until one is added.

| Surface | Motion allowed | Pattern (see [ANIMATION_GUIDELINES.md](./ANIMATION_GUIDELINES.md)) |
|---|---|---|
| Drawer / sheet / side panel | Yes | Inline-edge slide + fade, `moderate` in / `fast` out |
| Modal / dialog | Yes | Scale `0.98→1` + fade + coordinated scrim, `moderate` in / `fast` out |
| Toast / notification | Yes | Block-end slide + fade, `moderate` in / `fast` out |
| Route / page change | Yes | Cross-fade only, `slow` — never a slide |
| Skeleton / loading | Yes | `1.6s` linear shimmer sweep; static fill under reduced motion |
| Confidence meter | Yes | Segmented track filling to the band level, `base`, `easeOut` |
| AI "thinking" indicator | Yes | Three `accent-subtle` dots pulsing on a `1.2s` staggered loop |
| List / table row first paint (non-virtualized) | Yes | `fadeUp` staggered 30ms, capped at 12 rows |
| Realtime row update | Yes | `accent-subtle` background flash decaying over 600ms |
| Tab underline, accordion, disclosure | Yes | See MICRO_INTERACTIONS and ANIMATION_GUIDELINES |
| Button press, hover, focus, toggle, copy confirm, inline validation | Yes | See [MICRO_INTERACTIONS.md](./MICRO_INTERACTIONS.md) |
| **A raw debit/credit or ledger amount** | **No** | Financial figures never animate their value or color for effect |
| **A KPI number on load** | **No decorative count-up** | A hero number appears with its container's `fadeUp`; it never odometer-rolls or counts up from zero |
| **Success on a financial action** | **Restrained only** | A single quiet checkmark (`base` in, hold 900ms, fade) — never confetti, particles, bounce, or sound |
| **Charts / data marks** | **On first paint only** | A bar/line may draw in once on mount; it never loops, pulses, or animates on every data tick |
| **Decorative background, hero, or empty-state graphic** | **No** | The product has no ambient/decorative motion anywhere in the authenticated shell |

The prohibitions are as load-bearing as the permissions. A count-up on a cash-position figure would imply
the number is still settling when it is in fact final — actively misleading in an accounting product. A
celebratory animation on a posted entry would frame a legally-consequential action as a game reward. When in
doubt, QAYD removes motion before adding it.

# Testing & Enforcement

- **Reduced motion is a release gate.** Every animated component ships a Storybook story with the
  `prefersReducedMotion` parameter set, asserting the element reaches its final state with no transform
  tween. A component that animates identically with the preference on fails review.
- **The duration/curve lint.** A custom ESLint rule flags any `transition={{ duration: <number-literal> }}`
  or raw `cubic-bezier(...)` outside `lib/motion.ts` — motion values are imported, never inlined.
- **The physical-property lint.** The same Tailwind restriction that bans `ml-`/`pl-`/`left-` for layout
  (see [../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md)) covers animated offsets: a variant
  animating `left`/`right`/`inset-*` for movement is rejected in favor of `transform`.
- **The RTL pass.** Any component with directional motion (drawer, sheet, directional transition) requires
  an `rtl` Storybook/Playwright variant asserting it slides from the mirrored edge, per
  [../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) `RTL Accessibility`.
- **The performance pass.** A Playwright + tracing check on the General Ledger and Trial Balance asserts no
  layout-thrashing property is animated during scroll, and that a realtime row-flash does not shift layout.

# End of Document
