# Micro-Interactions — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: MICRO_INTERACTIONS
---

# Purpose

This document specifies QAYD's micro-interactions: the small, per-control feedback that fires on a single
user gesture — hover, focus, active/press, toggle, copy, inline validation, drag, an AI approve/reject, a
tooltip reveal. Where [ANIMATION_GUIDELINES.md](./ANIMATION_GUIDELINES.md) catalogues the larger composed
animations (panels, loading, list stagger), this document owns the state-feedback layer beneath them: the
120-millisecond acknowledgements that make a control feel *alive and responsive* without ever feeling
*playful*.

It draws every timing from [MOTION_SYSTEM.md](./MOTION_SYSTEM.md) and every color from
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md), and it enforces the interaction-state
accessibility rules of [../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) — the `:focus-visible`
ring, the hover/focus-within parity rule, the 24×24 tap-target floor, and the "never color-only" principle.
It invents no new token.

Micro-interactions are the highest-frequency motion in the product — a data-entry accountant triggers
hundreds of them an hour — so their discipline matters more than any single hero animation. They are held to
the shortest durations in the scale (`micro`/`fast`), they animate only `transform`/`opacity`/border/ring
where possible, and every one of them has a keyboard-equivalent trigger and a reduced-motion fallback,
because a control that only acknowledges a mouse is a control half the target audience cannot confirm they
activated.

# Principles of Restraint

Micro-interactions are where a design system most easily tips from "refined" into "fidgety," so QAYD governs
them with four restraint rules that override any individual designer's impulse to add "a little life."

**1. One acknowledgement per gesture, not a cascade.** A pressed button scales; it does not also glow, ripple,
and shadow-lift. A single, clear response to a single gesture reads as precise; a stack of simultaneous
responses reads as a toy. If a control seems to need three feedback effects to feel finished, the underlying
affordance is unclear and no amount of motion will fix it.

**2. Feedback is proportional to consequence — and inverted for the dangerous ones.** A hover is the
cheapest, quietest feedback; a destructive or financial confirmation is the loudest — but "loud" in QAYD
means *more explicit copy and an extra deliberate step*, never *more animation*. A "Void entry" control does
not get a bigger, bouncier press animation than "Save draft"; it gets a confirmation dialog with precise
wording. Motion never escalates to signal danger; words do.

**3. The keyboard sees everything the mouse sees, at the same time.** Every hover-revealed affordance appears
identically on `:focus-within`; every press feedback fires on `Enter`/`Space` activation, not only on a
pointer press; every tooltip opens on focus, not only on hover. A keyboard-primary accountant — a meaningful
share of QAYD's users — must never be shown less than a mouse user.

**4. Restraint compounds.** Because these interactions fire constantly, a 300ms flourish that would be
charming once becomes exhausting at the thousandth repetition. Micro-interactions live in the `micro` (120ms)
and `fast` (160ms) band precisely so they are *felt but not watched* — an accountant should sense the
interface responding, never wait for it to finish.

# Interaction-State Tokens

The four pointer/keyboard states map to background steps on the ink scale from
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md), plus the shared focus ring and press
transform. These are the only values a state may change; a component does not invent a fifth hover color.

| State | Background | Border / ring | Transform | Duration | Easing |
|---|---|---|---|---|---|
| Rest | `ink-2`/`ink-3` (per surface) | `ink-6` hairline | none | — | — |
| Hover | `ink-4` | unchanged | none | `micro` (120ms) | `easeInOut` |
| Focus-visible | unchanged from hover | `accent` ring, 2px, 2px offset | none | `micro` | `easeInOut` |
| Active / pressed | `ink-5` | unchanged | `scale: 0.98` | `micro` | `easeInOut` |
| Disabled | `ink-3` | `ink-6` | none | — | — (no state motion) |

```css
/* app/globals.css — the shared focus ring, from ACCESSIBILITY.md */
:root {
  --focus-ring-width: 2px;
  --focus-ring-offset: 2px;
}
:focus-visible {
  outline: var(--focus-ring-width) solid var(--qayd-accent);
  outline-offset: var(--focus-ring-offset);
}
.dark :focus-visible {
  outline-color: var(--qayd-accent); /* the accent's own dark-mode value, ≥3:1 on every surface */
}
```

QAYD uses `:focus-visible`, never `:focus`, so a mouse click does not paint a ring on every button a sighted
mouse user happens to press, while a keyboard tab always shows it. `outline: none` without an immediately
adjacent, equally-visible replacement indicator is forbidden anywhere in the codebase — see
[../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) `Keyboard Navigation`.

# Buttons — Hover, Focus, Press

A button acknowledges three states with three distinct, non-overlapping cues: hover deepens its background
one ink step, focus-visible adds the accent ring, and press scales it to `0.98`. The scale is the only
transform, it runs at `micro` on `easeInOut` (a press is a symmetric down/up toggle), and it is the entire
press feedback — no shadow lift, no color flash on top.

- **Keyboard equivalent:** the press scale fires on `Enter`/`Space` activation via Framer's `whileTap`,
  which Radix/native `<button>` semantics trigger for keyboard activation too, so a keyboard user feels the
  same press acknowledgement as a mouse user.
- **Reduced motion:** the scale is dropped; hover/focus background and ring changes remain (they are state,
  not motion, and are near-instant regardless).

```tsx
import { motion, useReducedMotion } from "framer-motion";
import { duration, easeInOut } from "@/lib/motion";
import { cn } from "@/lib/utils";

export function Pressable({ className, children, ...props }: React.ComponentProps<typeof motion.button>) {
  const reduced = useReducedMotion();
  return (
    <motion.button
      whileTap={reduced ? undefined : { scale: 0.98 }}
      transition={{ duration: reduced ? 0 : duration.micro, ease: easeInOut }}
      className={cn(
        "rounded-md bg-accent px-4 py-2 text-text-sm text-accent-on",
        "transition-colors duration-[120ms] hover:bg-accent-strong",
        "focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
        className,
      )}
      {...props}
    >
      {children}
    </motion.button>
  );
}
```

The primary (`accent`) button pairs its fill with `accent-on` (near-black), never white — the mid-value
brass does not clear 4.5:1 against white label text. Hover moves the fill to `accent-strong`; the press
scale is identical across button variants (primary, secondary, ghost, destructive) so the *press* never
becomes a place where danger is signaled — that is the destructive dialog's job.

# Inputs — Focus Ring & State

A text input, select trigger, or currency field shows rest, hover, focus, error, and disabled as
border-and-ring changes only — never a moving or resizing input, which would shift the caret and the fields
below it.

| State | Border | Ring | Notes |
|---|---|---|---|
| Rest | `ink-7` | none | — |
| Hover | `ink-8` | none | Subtle deepen, `micro` color transition |
| Focus-visible | `accent` | `accent` 2px / 2px offset | The ring is the focus indicator; border also shifts to accent for redundancy |
| Error (`aria-invalid`) | `negative` | `negative` ring on focus | Paired with a `role="alert"` message — never border-color alone |
| Disabled | `ink-6` | none | `cursor-not-allowed`, `aria-disabled`, plus a tooltip if permission-gated |

The `CurrencyInput` ([../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) `Forms Accessibility`)
formats **on blur, not on keystroke** — injecting thousands separators mid-type causes caret-jump bugs that
are far more disruptive for screen-reader and voice-control users (no visual caret to track) than for a mouse
user. So the one input micro-interaction QAYD deliberately *does not* have is a live-reformatting animation:
the value the user types stays exactly as typed until focus leaves.

```tsx
// The focus/error state is border+ring only — no transform, no resize.
<input
  aria-invalid={hasError}
  aria-describedby={cn(hintId, hasError && errorId)}
  className={cn(
    "w-full rounded-md border bg-ink-1 px-3 py-2 text-text-md transition-colors duration-[120ms]",
    "border-ink-7 hover:border-ink-8",
    "focus-visible:border-accent focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
    hasError && "border-negative focus-visible:outline-negative",
    "disabled:cursor-not-allowed disabled:border-ink-6 disabled:bg-ink-3",
  )}
/>
```

# Toggles & Switches

A switch animates its thumb from the off position to the on position with a `transform: translateX()` (which
mirrors under RTL — the thumb travels toward the inline-end) and a background color change from `ink-6` to
`accent`, both at `micro` on `easeInOut` (a toggle is a symmetric two-state change). A checkbox animates its
checkmark drawing in at `micro`. Neither is color-only: a switch exposes its state through `role="switch"` +
`aria-checked`, and a checkbox through the native checked state — a screen reader announces "on"/"off",
never inferring it from the thumb's position.

- **Keyboard equivalent:** `Space` toggles; the thumb travel and color change fire identically to a click.
- **Reduced motion:** the thumb jumps to its position and the color changes instantly — the two-state change
  is fully conveyed, just not tweened.

```tsx
import { motion, useReducedMotion } from "framer-motion";
import { duration, easeInOut, useInlineDirection } from "@/lib/motion";

export function Switch({ checked, onChange, label }: SwitchProps) {
  const reduced = useReducedMotion();
  const sign = useInlineDirection();
  return (
    <button
      role="switch" aria-checked={checked} aria-label={label} onClick={() => onChange(!checked)}
      className={cn(
        "relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-[120ms]",
        checked ? "bg-accent" : "bg-ink-6",
        "focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent",
      )}
    >
      <motion.span
        className="size-4 rounded-full bg-ink-1"
        animate={{ x: checked ? sign * 16 : sign * 2 }}
        transition={{ duration: reduced ? 0 : duration.micro, ease: easeInOut }}
      />
    </button>
  );
}
```

# Copy-to-Clipboard Confirmation

Copying a value (an account code, a journal reference `JE-2026-00184`, an IBAN) confirms with a brief,
in-place icon swap: the copy glyph cross-fades to a checkmark at `micro`, holds ~1.2s, then cross-fades back
— no toast for so small an action, because a toast for every copy would be noise. The confirmation is also
announced to assistive technology via a polite live region, because the visual icon swap is invisible to a
screen-reader user.

- **Keyboard equivalent:** the copy control is a real `<button>`, activated by `Enter`/`Space`; the icon
  swap and the live-region announcement fire the same way.
- **Reduced motion:** the icon swaps instantly (no cross-fade), still holds, still reverts; the announcement
  is unchanged.

```tsx
import { motion, AnimatePresence, useReducedMotion } from "framer-motion";
import { duration, easeOut } from "@/lib/motion";
import { Copy, Check } from "lucide-react";

export function CopyButton({ value, label }: { value: string; label: string }) {
  const [copied, setCopied] = React.useState(false);
  const reduced = useReducedMotion();
  async function onCopy() {
    await navigator.clipboard.writeText(value);
    setCopied(true);
    setTimeout(() => setCopied(false), 1200);
  }
  return (
    <>
      <button onClick={onCopy} aria-label={label}
        className="inline-flex min-h-[24px] min-w-[24px] items-center justify-center rounded-md text-ink-9 hover:bg-ink-4">
        <AnimatePresence mode="wait" initial={false}>
          <motion.span key={copied ? "done" : "copy"}
            initial={reduced ? false : { opacity: 0, scale: 0.9 }}
            animate={reduced ? undefined : { opacity: 1, scale: 1 }}
            exit={reduced ? undefined : { opacity: 0 }}
            transition={{ duration: reduced ? 0 : duration.micro, ease: easeOut }}>
            {copied ? <Check className="size-4 text-positive" /> : <Copy className="size-4" />}
          </motion.span>
        </AnimatePresence>
      </button>
      {/* keyboard/screen-reader equivalent of the visual swap */}
      <span role="status" aria-live="polite" className="sr-only">{copied ? "Copied" : ""}</span>
    </>
  );
}
```

The `min-h-[24px] min-w-[24px]` is the WCAG 2.2 SC 2.5.8 tap-target floor from
[../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) — the glyph is 16px, the hit box is never
smaller than 24px.

# Inline Validation Feedback

A field that fails validation on blur (or on submit) shows its error as a border shift to `negative` **plus**
a `role="alert"` message that is announced the moment it appears — never a color-only red border a screen
reader will only find if the user happens to tab back. The message text does not fade in slowly; it appears
at `fast` with a short opacity-only transition so it registers immediately. A field that *passes* after
having failed clears its error state at `micro` with no celebratory tick — a valid field is the expectation,
not an achievement.

- **The redundant-cue rule:** the border color is reinforcement; the announced message text carries the
  actual information. A colorblind or screen-reader user gets the full "This entry is not balanced. Debits
  (KD 1,204.500) must equal credits (KD 1,150.000)." string, per the design language's voice rules.
- **Reduced motion:** the message appears instantly; the border changes instantly.

```tsx
import { motion, AnimatePresence, useReducedMotion } from "framer-motion";
import { duration } from "@/lib/motion";

export function FieldError({ id, message }: { id: string; message?: string }) {
  const reduced = useReducedMotion();
  return (
    <AnimatePresence>
      {message && (
        <motion.p id={id} role="alert" className="mt-1 text-text-xs text-negative"
          initial={reduced ? false : { opacity: 0 }}
          animate={reduced ? undefined : { opacity: 1 }}
          exit={reduced ? undefined : { opacity: 0 }}
          transition={{ duration: reduced ? 0 : duration.fast }}>
          {message}
        </motion.p>
      )}
    </AnimatePresence>
  );
}
```

On a multi-field form's failed submit, focus moves to the form-level error summary
([../frontend/ACCESSIBILITY.md](../frontend/ACCESSIBILITY.md) `The error summary pattern`) — a structural
focus move, not a micro-interaction, but it is what a validation failure ultimately triggers, so the two are
designed together: the field-level red border is the reinforcement, the focused summary is the resolution.

# Drag Affordances

QAYD has few drag interactions — reordering dashboard report widgets, resizing a dashboard tile — and every
one ships a **non-drag alternative** per WCAG 2.2 SC 2.5.7 (Dragging Movements): an up/down button pair, a
"Move to…" menu. The drag itself is acknowledged with a restrained lift: the dragged item raises to
`shadow-md` and drops to `opacity: 0.9` while dragging, with a `2px` `accent` insertion indicator showing
where it will land. There is no wobble, no tilt, no scale-up — the item stays its own size and shape so the
layout it will drop into is legible.

- **Keyboard equivalent (the actual requirement):** the reorder is fully operable without dragging at all —
  focus the item's move-up/move-down buttons and activate them, or open its "Move to" menu. The drag is an
  enhancement for pointer users, never the only path.
- **Reduced motion:** the lift shadow/opacity change is dropped; the insertion indicator still shows the
  drop position (it is information, not decoration).

```tsx
import { Reorder, useReducedMotion } from "framer-motion";
import { duration, easeOut } from "@/lib/motion";

export function WidgetItem({ value, children }: { value: Widget; children: React.ReactNode }) {
  const reduced = useReducedMotion();
  return (
    <Reorder.Item value={value}
      whileDrag={reduced ? undefined : { boxShadow: "var(--shadow-md)", opacity: 0.9 }}
      transition={{ duration: reduced ? 0 : duration.fast, ease: easeOut }}>
      {/* keyboard alternative always rendered, not hidden behind hover */}
      <div className="flex items-center gap-1">
        <IconButton aria-label="Move up" onClick={onMoveUp} />
        <IconButton aria-label="Move down" onClick={onMoveDown} />
        {children}
      </div>
    </Reorder.Item>
  );
}
```

# AI Approve / Reject Affordance Feedback

The approve/reject controls on an AI proposal or Approval Center card are the most consequential
micro-interactions in the product, and their feedback is deliberately the most restrained. They are real
`<button>` elements (never `<div onClick>`), each with a visible text label plus icon (never a bare icon),
and `aria-describedby` pointing at the card's reasoning so the "why" is read before the action is taken.

- **Approve** (`A` keyboard shortcut when a card is focused) presses with the standard `0.98` scale; on
  success the card leaves the queue with a reverse `fadeUp` exit and focus moves to the next card's
  equivalent control — never silently to `<body>`. There is **no green flash, no confetti, no celebratory
  motion** — approving a journal entry or a bank transfer is a legally-consequential act, and its
  confirmation is a quiet checkmark and the card's calm departure, per
  [ANIMATION_GUIDELINES.md](./ANIMATION_GUIDELINES.md) `Success checkmark`.
- **Reject** (`X` shortcut) does **not** fire on a single click for anything consequential: it opens the
  mandatory-reason step. The danger of a rejection is carried by that required reason and the action verb,
  not by a red button pulsing or shaking. Color is never the only signal that this is the destructive path.
- **Confidence** is shown as a filling meter + a percentage text node + a qualitative band label
  ([ANIMATION_GUIDELINES.md](./ANIMATION_GUIDELINES.md) `Confidence-meter fill`), never a traffic-light color;
  a low-confidence proposal's controls are rendered and readable but its auto-execute path is `disabled`
  (not merely styled), so it can be sent for approval but never one-click-committed.

```tsx
// The approve control — keyboard-first, text-labelled, restrained press.
<Pressable
  onClick={onApprove}
  aria-describedby={reasoningId}
  className="bg-accent text-accent-on"
>
  <Check aria-hidden className="me-2 size-4" />
  {t("approval.approve")}
</Pressable>
```

- **Reduced motion:** the press scale and the card's exit are dropped; the approval still completes, the card
  still leaves the queue instantly, focus still advances, and the confirmation is still announced.

# Tooltip Reveal Timing

Tooltips (Radix `Tooltip`) reveal on hover **and on focus** — a keyboard user tabbing to a control sees the
same tooltip a mouse user gets. The reveal has a short open delay so tooltips do not flicker as the pointer
sweeps across a toolbar, and no close delay so they dismiss cleanly. The tooltip body fades and rises 4px at
`fast` on `easeOut`.

| Property | Value | Reason |
|---|---|---|
| Open delay (hover) | ~500ms | Prevents flicker while sweeping across dense toolbars/row actions |
| Open delay (focus) | 0ms | A deliberate keyboard focus should reveal help immediately |
| Close | immediate | On blur/mouse-leave/Esc; no lingering |
| Motion | opacity + 4px rise, `fast`, `easeOut` | Quiet appearance, above everything at `z-tooltip` |

Tooltips carry real, important text in QAYD — a disabled control's permission explanation ("Requires
`accounting.journal.post`"), a confidence attribution ("92% confidence — Accountant Agent"), a
debit/credit abbreviation's full word — so their content is always available to assistive technology through
`aria-describedby`, and a tooltip is never the *only* place load-bearing information lives.

- **Reduced motion:** the tooltip appears instantly with no rise/fade; timing (open delay, immediate close)
  is unchanged.

```tsx
import { Tooltip, TooltipTrigger, TooltipContent } from "@/components/ui/tooltip";

<Tooltip delayDuration={500}>
  <TooltipTrigger asChild>
    <span> {/* wrapper: disabled buttons don't reliably fire hover/focus */}
      <Button disabled={!canPost} aria-describedby={!canPost ? "post-disabled" : undefined}>
        {t("journal.post_entry")}
      </Button>
    </span>
  </TooltipTrigger>
  {!canPost && (
    <TooltipContent id="post-disabled" role="tooltip">
      {t("a11y.permission_denied", { permission: "accounting.journal.post" })}
    </TooltipContent>
  )}
</Tooltip>
```

# Hover/Focus Parity in Tables

The single most important micro-interaction rule for QAYD's dense tables: a row's action icons (view / edit /
approve / reject) that appear on `:hover` **must appear identically on `:focus-within`**, in the same place,
at the same time. A keyboard user tabbing into a row sees exactly what a mouse user sees on hover. This is
enforced structurally — an action's visibility is never gated on `group-hover:` alone; every such Tailwind
utility is paired with `group-focus-within:` in the same class list.

```tsx
// Row actions revealed on BOTH hover and keyboard focus — never hover alone.
<tr className="group border-b border-ink-6">
  {/* cells… */}
  <td>
    <div className="flex gap-1 opacity-0 transition-opacity duration-[120ms]
                    group-hover:opacity-100 group-focus-within:opacity-100">
      <IconButton aria-label={`View ${row.ref}`} />
      <IconButton aria-label={`Edit ${row.ref}`} />
    </div>
  </td>
</tr>
```

The row-background hover highlight itself is a `micro` (120ms) `ink-4` fill — it must not collide with the
`accent-subtle` AI-provenance tint or the `ink-5` selection fill, which is why QAYD does not additionally
zebra-stripe rows (fill-based striping fights all three state fills at density; see
[../frontend/DESIGN_LANGUAGE.md](../frontend/DESIGN_LANGUAGE.md) `Data Density`).

# Do & Don't

| Do | Don't |
|---|---|
| Give a button one press feedback: `scale: 0.98` at `micro` | Stack scale + glow + shadow-lift + color flash on one press |
| Reveal row actions on hover AND `:focus-within`, same place, same time | Gate row actions on `group-hover:` alone (invisible to keyboard) |
| Show focus with `:focus-visible` + the accent ring | Use `outline: none` without an adjacent visible replacement |
| Confirm a copy with an in-place icon swap + a polite live-region note | Fire a toast for every copied account code |
| Carry validation info in announced text; use color as reinforcement | Signal an error with a red border alone |
| Ship a keyboard/button alternative for every drag | Make reordering possible only by dragging |
| Keep AI approve/reject calm — quiet check, card departs | Flash green / confetti on approving a journal entry or transfer |
| Open tooltips on focus at 0ms and on hover at ~500ms | Show a tooltip only on hover (keyboard users never see it) |
| Format currency inputs on blur | Reformat with separators mid-keystroke (caret jump) |
| Keep every micro-interaction in the `micro`/`fast` band | Let a per-control flourish run 300ms and be *watched*, not felt |
| Give every state change a reduced-motion path to instant | Ship a press/toggle/reveal that animates identically with the preference on |

# Reduced Motion & Keyboard — Per-Interaction Summary

| Interaction | Keyboard trigger | Reduced-motion fallback |
|---|---|---|
| Button press | `Enter` / `Space` (`whileTap` fires on keyboard activation) | No scale; hover/focus color + ring remain |
| Input focus/error | `Tab` to focus; error on blur/submit | Border/ring change instantly; message appears instantly |
| Toggle / switch | `Space` | Thumb jumps, color changes instantly |
| Copy confirm | `Enter` / `Space` on the copy button | Icon swaps instantly; live-region note unchanged |
| Inline validation | Announced on appear regardless of input method | Message + border appear instantly |
| Drag reorder | Move-up/down buttons or "Move to" menu (the real path) | Lift shadow/opacity dropped; insertion indicator stays |
| AI approve / reject | `A` / `X` when a card is focused | Press scale + card exit dropped; action completes, focus advances |
| Tooltip | Reveals on focus at 0ms delay | Appears instantly, no rise/fade; timing unchanged |
| Row-action reveal | `:focus-within` on `Tab` into the row | Opacity change is near-instant regardless; parity preserved |

# End of Document
