# Tooltip — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / TOOLTIP
---

# Purpose

This document is the atomic specification for QAYD's **tooltip** primitive: the small, transient text overlay
that appears on hover or focus of a control and supplies a short, *supplementary* label or explanation. It is
built directly on **`@radix-ui/react-tooltip`**, re-skinned to QAYD tokens, and it is one of the most
disciplined surfaces in the system precisely because it is so easy to misuse.

The governing rule is a single sentence: **a tooltip may only ever hold information the user can succeed
without.** A tooltip is not reliably reachable — it does not appear on touch, it is invisible until a pointer
or keyboard lands on its trigger, and it vanishes the moment attention moves. Anything a user *must* read to
complete a task — a required-field rule, a validation error, an irreversible-action warning, a permission the
control requires — belongs in persistent, always-visible copy, not a tooltip. QAYD uses tooltips for exactly
what they are good at: naming an icon-only button, previewing a truncated bilingual account name, and showing
the *reasoning* behind an AI figure the user has already been shown in full.

QAYD's calm-motion tone shapes the tooltip's timing: a 300ms open delay so tooltips do not flicker as the
pointer crosses a toolbar, and a 0ms close delay so they get out of the way instantly
([`../../frontend/COMPONENT_LIBRARY.md → Primitives`](../../frontend/COMPONENT_LIBRARY.md)). Radix supplies the
entire accessibility contract — `aria-describedby` wiring, focus and hover triggering, `Esc`-to-dismiss,
pointer-safe hovering — and QAYD does not modify that behavior; it only themes it and constrains what may go
inside.

Related reading: the surface color and shadow are in [`../ELEVATION_SHADOWS.md`](../ELEVATION_SHADOWS.md)
(`surface-2`, `shadow-sm`, `z-tooltip` = 70); appear/disappear timing and reduced motion are in
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); the color roles are in [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md);
the live-region and keyboard rules are in [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md). Distinguish this
primitive carefully from [`./POPOVER.md`](./POPOVER.md) (interactive, click-triggered, can hold focusable
content) — the boundary is drawn in Do / Don't below. Sibling primitives: [`./TOAST.md`](./TOAST.md),
[`./BADGE.md`](./BADGE.md).

# Anatomy

A tooltip is a single small text bubble tethered to its trigger, lifted onto the `z-tooltip` layer.

| Part | Role | Notes |
|---|---|---|
| Trigger | The element the tooltip describes | Any focusable control (icon button, badge, truncated label). Wrapped in `<TooltipTrigger>`; an icon-only button trigger must itself be a real `<button>`. |
| Content bubble | The `surface-2` overlay carrying the text | `bg-ink-1` (light) / `bg-ink-3` (dark), 1px `ink-6`, `radius-md` (6px), `shadow-sm`, `text-xs`/`text-sm`, `ink-12` on the surface. |
| Arrow | Optional 8px pointer to the trigger | Filled `ink-1`/`ink-3` with the `ink-6` edge; `aria-hidden`. Optional — omit for very small triggers where it crowds. |
| Text | One short line, occasionally two | Max ~`text-xs`/`text-sm`; hard `max-width` (see Props). No headings, no buttons, no links. |

The bubble is a **quiet surface** — the same `surface-2` a dropdown uses, not a high-contrast black
capsule. It carries no tone color of its own: a tooltip is neutral by definition, because it never conveys
status (status is a [`./BADGE.md`](./BADGE.md) or an inline surface). Its single job is to be legible and to
disappear cleanly.

```
        ┌─────────────────────────┐
        │  Why 92%? Matched on     │   ← surface-2 bubble, text-xs, ink-12
        │  invoice # and amount.   │
        └───────────▼─────────────┘   ← 8px arrow to the trigger
              [ i ]  ← trigger
```

# Variants

A tooltip has **no tone variants** — this is deliberate and load-bearing. Unlike `Toast` and `Badge`, a
tooltip is always the same neutral surface, because coloring a tooltip red/green would imply it carries
status the user could miss, and status must never live in a surface that vanishes. The only axes are
structural:

| Axis | Values | Notes |
|---|---|---|
| Placement | `top` (default) · `right` · `bottom` · `left` (physical, Radix `side`) resolved from logical intent | QAYD authors placement as logical intent and lets collision detection flip it; see States → Collision. |
| Arrow | present · absent | Absent for dense toolbars where the arrow crowds adjacent triggers. |
| Size | single-line (default) · two-line (`max-w-xs`) | Never taller than two lines; longer content is a signal the surface should be a `Popover`. |
| Trigger kind | icon-button · truncated-text · badge (e.g. `ConfidenceBadge`) | The three sanctioned uses; a tooltip on plain body text is disallowed. |

There is exactly **one shared `TooltipProvider`** mounted near the app root so delay and skip-delay behavior
are global and consistent (see Behavior → Delay). Individual tooltips never set their own provider.

# Props / API

QAYD wraps Radix's tooltip parts in `components/ui/tooltip.tsx`, keeping Radix's names so the primitive stays
upgradable.

## `<Tooltip>` (and parts)

| Part / prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `<TooltipProvider delayDuration skipDelayDuration>` | — | mounted once | `delayDuration=300`, `skipDelayDuration=0` | App-root provider; governs open delay and the "already-warm" skip window. |
| `<Tooltip open / defaultOpen / onOpenChange>` | Radix root | — | uncontrolled | Rarely controlled; controlled only for a guided-tour highlight. |
| `<TooltipTrigger asChild>` | — | yes | — | Wraps the real trigger element (`asChild` so the trigger stays a `<button>`, never a wrapping `<span>` that breaks keyboard reach). |
| `<TooltipContent side sideOffset align collisionPadding>` | — | — | `side` from placement, `sideOffset=6`, `collisionPadding=8` | The bubble. `side` is the requested placement; Radix flips it on collision. |
| `<TooltipArrow>` | — | no | omitted where it crowds | The 8px pointer. |

## QAYD constraints layered on top

| Constraint | Value | Rationale |
|---|---|---|
| `max-width` | `18rem` (`max-w-xs`) | A tooltip wider than this is holding too much; promote to a `Popover`. |
| Max lines | 2 | Same signal. |
| Open delay | 300ms | Calm-motion; no flicker crossing a toolbar. |
| Close delay | 0ms | Get out of the way instantly. |
| Content | text only | No focusable elements — a tooltip is not hoverable-into; put interactive content in a `Popover`. |

```tsx
// components/ui/tooltip.tsx — QAYD-themed Radix tooltip
'use client';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { cn } from '@/lib/utils';

export const TooltipProvider = TooltipPrimitive.Provider; // mounted once, delayDuration={300}
export const Tooltip = TooltipPrimitive.Root;
export const TooltipTrigger = TooltipPrimitive.Trigger;

export function TooltipContent({
  className, sideOffset = 6, children, withArrow = false, ...props
}: React.ComponentProps<typeof TooltipPrimitive.Content> & { withArrow?: boolean }) {
  return (
    <TooltipPrimitive.Portal>       {/* portals to #portal-root so no sticky header clips it */}
      <TooltipPrimitive.Content
        sideOffset={sideOffset}
        collisionPadding={8}
        className={cn(
          'z-[70] max-w-xs rounded-md border border-ink-6 bg-ink-1 dark:bg-ink-3 px-2.5 py-1.5',
          'text-xs leading-snug text-ink-12 shadow-sm',
          // enter/exit — Radix data-state hooks, gated by reduced-motion in globals.css
          'data-[state=delayed-open]:animate-in data-[state=closed]:animate-out',
          'data-[state=delayed-open]:fade-in-0 data-[state=closed]:fade-out-0',
          'data-[side=top]:slide-in-from-bottom-1 data-[side=bottom]:slide-in-from-top-1',
          className,
        )}
        {...props}
      >
        {children}
        {withArrow && <TooltipPrimitive.Arrow className="fill-ink-1 dark:fill-ink-3" width={11} height={5} />}
      </TooltipPrimitive.Content>
    </TooltipPrimitive.Portal>
  );
}
```

# States

| State | Trigger | Behavior |
|---|---|---|
| Idle | — | No overlay in the DOM; trigger renders normally. |
| Opening (delayed) | Pointer rests on / focus lands on the trigger for `delayDuration` (300ms) | After the delay, the bubble mounts and fades/slides in over `motion.fast` (160ms), `easeOut`. |
| Open (warm) | Delay elapsed | Bubble visible; `aria-describedby` on the trigger points at it. |
| Skip-delay open | Pointer moves to another tooltip trigger within `skipDelayDuration` | Opens with **no** delay — moving along a toolbar shows each tip instantly once the first has opened. |
| Collision-flipped | Requested side has no room in the viewport | Radix flips to the opposite side and, if needed, shifts along the axis to stay on-screen (`collisionPadding=8`). |
| Closing | Pointer leaves / trigger blurs / `Esc` pressed / trigger activated | Bubble fades out over `motion.instant`→short opacity (0ms close delay), then unmounts. |

**Dismiss policy.** A tooltip closes on pointer-leave, on blur, on `Esc`, and — importantly — when its
trigger is *activated* (clicking the icon button the tooltip labels dismisses the tip so it does not linger
over the result of the click). It also closes on scroll of the nearest scroll container, so it never floats
detached from a trigger that has moved.

**Pointer-safe.** Radix's tooltip does not require the user to hover *into* the bubble; the bubble is not
interactive, so there is no "safe triangle" to traverse. A tooltip that needs to be hovered into is a design
error — that is a `Popover`.

# Tokens Used

| Concern | Token | Value |
|---|---|---|
| Bubble background | `ink-1` (light) / `ink-3` (dark) | `#FAFAF9` / `#24211A` |
| Bubble border | `ink-6` | `#C2BEB6` / `#46402F` |
| Radius | `radius-md` | 6px |
| Shadow | `shadow-sm` | `0 2px 4px rgba(21,19,14,.06)`; half-alpha `rgba(0,0,0,…)` in dark |
| Text | `text-xs` / `text-sm`, `ink-12` | 12–14px, near-black / near-white |
| Arrow fill | `ink-1` / `ink-3` | matches the bubble |
| Stack tier | `z-tooltip` | **70 — above everything, including modals** |
| Open delay / close delay | 300ms / 0ms | calm-motion |
| Enter / exit | `motion.fast`, `easeOut` / `easeIn` | 160ms |

A tooltip renders at `z-tooltip` (70), the **highest** tier in the system, so a tooltip on a control *inside*
a dialog (`z-modal` 40) or a command palette (`z-command` 60) is never clipped by its container
([`../ELEVATION_SHADOWS.md → Z-Index Tiers`](../ELEVATION_SHADOWS.md)). It portals to `#portal-root` so no
`overflow:hidden` scroll container or sticky header can trap it.

# Accessibility

- **Radix owns the wiring, and it is correct by default.** The trigger gets `aria-describedby` pointing at the
  content, so a screen reader announces the control *and* its tooltip together. QAYD does not re-implement
  this and does not break it by wrapping the trigger in a non-focusable element (`asChild` keeps the trigger a
  real `<button>`).
- **`aria-describedby`, not `aria-label`, by design.** A tooltip *describes* — it is supplementary. If an
  icon-only button has **no** visible text, the button itself still needs an accessible **name**
  (`aria-label`) so it is usable when the tooltip never fires (touch, reduced pointer); the tooltip then adds
  a *description* on top. A tooltip is never the only source of a control's name.
- **Keyboard: focus opens, `Esc` closes.** Tabbing to the trigger opens the tooltip after the delay; `Esc`
  dismisses it without moving focus. There is no keyboard trap — the bubble is not focusable.
- **Never the sole home of essential information.** Because a tooltip is unreachable on touch and invisible
  until hover/focus, it may not carry a required-field rule, a validation error, a destructive-action
  warning, or a permission requirement. Those are persistent copy. This is the single most important
  accessibility rule for this primitive and it is enforced in review.
- **Contrast holds in both themes.** `ink-12` on `ink-1`/`ink-3` clears AA in light and dark
  ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)). The bubble is neutral, so there is no
  status-color-alone concern.
- **Motion is optional.** Under reduced motion the bubble simply appears/disappears without the slide (see
  Theming → Reduced motion) — the information is never gated on an animation.

# Theming, Dark Mode & RTL

## Theming

The tooltip references only `bg-ink-1`/`dark:bg-ink-3`, `border-ink-6`, `text-ink-12` — all tokens, no raw
hex, no tone color. The white-label accent never touches a tooltip, because a tooltip carries neither a
primary action nor status.

## Dark mode

One class swap (`bg-ink-1` → `dark:bg-ink-3`) puts the bubble on the dark `surface-2` background, a step
lighter than the dark canvas so it reads as raised; the `ink-6` border and the half-alpha `rgba(0,0,0,…)`
`shadow-sm` do the rest ([`../ELEVATION_SHADOWS.md → Light vs. Dark Elevation`](../ELEVATION_SHADOWS.md)). No
other dark override is needed because every token remaps under `.dark`.

## RTL

- **Placement is authored as logical intent, resolved by collision.** QAYD does not hardcode `side="right"`;
  it lets Radix's collision detection choose the physical side, so a tooltip that would open toward the
  reading-end edge behaves symmetrically in Arabic and English. Where a fixed side is genuinely needed, it is
  expressed relative to reading direction and mapped per `dir`, never as a raw physical side.
- **`sideOffset`/`collisionPadding` are symmetric**, so no directional value needs flipping.
- **Text direction follows the content.** A tooltip holding an Arabic string renders RTL; a tooltip holding a
  Latin/numeric token (a currency code, a `92%`, an account code) renders that token `dir="ltr"` inline, the
  same numerals-never-mirror rule the rest of the system follows.
- **The arrow is symmetric** and needs no mirroring.

## Reduced motion

Under `prefers-reduced-motion: reduce`, the fade/slide is removed and the bubble toggles visibility over
`motion.instant`; the open/close *behavior* is unchanged. The reduced-motion query is a framework-independent
safety net alongside the `useReducedMotion()`-gated Framer values ([`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md)).

# Do / Don't

| Do | Don't |
|---|---|
| Use a tooltip to name an icon-only button or preview a truncated bilingual name | Put a required-field rule, validation error, or destructive warning in a tooltip |
| Keep content to one short line, occasionally two | Grow a tooltip into a paragraph with headings or links |
| Give the icon-only trigger its own `aria-label` and let the tooltip *describe* | Rely on the tooltip as the button's only accessible name |
| Keep the bubble neutral `surface-2` | Color a tooltip red/green to imply status |
| Let collision detection choose the physical side | Hardcode `side="right"` and have it clip or mirror wrongly in RTL |
| Promote to a `Popover` the moment content is interactive or > 2 lines | Cram a focusable control or a form into a tooltip |
| Portal to `#portal-root` at `z-tooltip` (70) | Render the bubble inline where a sticky header can clip it |
| Provide persistent copy for anything essential | Assume a touch user will ever see the tooltip |
| Use the shared `TooltipProvider` and its 300/0ms delays | Set a per-tooltip provider or a jittery near-zero open delay |

# Usage & Composition

**Icon-only button** — the canonical case. The button carries its own name; the tooltip adds a description:

```tsx
<Tooltip>
  <TooltipTrigger asChild>
    <Button variant="ghost" size="icon" aria-label={t('reverseEntry')}>
      <Undo2 className="h-4 w-4" aria-hidden />
    </Button>
  </TooltipTrigger>
  <TooltipContent>{t('reverseEntryHint')}</TooltipContent>
</Tooltip>
```

**Truncated bilingual name** — the tooltip reveals the full account name a cell had to clip:

```tsx
<Tooltip>
  <TooltipTrigger asChild>
    <span className="truncate">{locale === 'ar' ? account.name_ar : account.name_en}</span>
  </TooltipTrigger>
  <TooltipContent>{locale === 'ar' ? account.name_ar : account.name_en}</TooltipContent>
</Tooltip>
```

**AI reasoning on a `ConfidenceBadge`** — QAYD's rule that "no card shows a bare number the AI computed
without showing its work" is satisfied by the tooltip. The percentage and band label are *already visible*;
the tooltip supplies the *why*, which the user can succeed without ([`../../frontend/COMPONENT_LIBRARY.md → ConfidenceBadge`](../../frontend/COMPONENT_LIBRARY.md)):

```tsx
<Tooltip>
  <TooltipTrigger asChild>
    <button type="button" className="cursor-help"><ConfidenceBadge confidence={0.92} /></button>
  </TooltipTrigger>
  <TooltipContent className="text-start">{decision.reasoning}</TooltipContent>
</Tooltip>
```

**The composition boundary with `Popover`.** If the overlay must contain a link, a button, a form, or content
the user needs to scroll or copy from at leisure, it is not a tooltip — it is a [`./POPOVER.md`](./POPOVER.md).
The decisive test: *can the user complete their task if this overlay never appears?* If yes, tooltip; if no,
the information must be persistent or interactive, so it is a popover, an inline surface, or plain copy — never
a tooltip.

# End of Document
