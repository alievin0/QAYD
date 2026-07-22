# Switch — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / SWITCH
---

# Purpose

This document is the atomic specification for QAYD's **Switch** primitive — the toggle a user flips to
turn a setting **on or off with immediate effect**. It is the design-system companion to the
application-facing input catalogue in
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md), which lists `Switch` and
states the rule this whole document exists to make unambiguous: a switch is an **immediate-effect toggle**
(a setting that saves the moment it is flipped, via a Server Action), whereas a
[Checkbox](./CHECKBOX.md) is a **value the surrounding form submits later**. "If flipping it should take
effect now, it is a `Switch`; if it is a value the form will submit later, it is a `Checkbox`" — that
sentence, quoted from INPUTS.md, is the load-bearing semantic and is repeated here because getting it
wrong is the single most common misuse of either control.

The switch is a re-skinned **Radix `Switch`**. QAYD keeps Radix's accessibility contract
(`role="switch"`, `aria-checked`, `data-state`, `Space`/`Enter` to toggle) untouched and adds only its
tokens, a `size` axis, a **`loading`/pending** state (because an immediate-effect toggle round-trips to
the server and can fail), and a controlled label-placement option. Because a switch takes effect
immediately, it is the one boolean control that must be prepared to **optimistically flip, show a pending
state, and visibly revert if the server rejects the change** — a checkbox never has to, because its value
is not committed until the form is.

Every value below traces to [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); the primitive introduces no literal a token does not already
cover.

# Anatomy

A switch is a **track** (a pill-shaped rail) with a **thumb** (a circular knob that slides between the
inline-start "off" end and the inline-end "on" end), plus an associated **label** and optional
**description**. It reads as a physical rocker: the thumb's position, not only the track's color, tells
you the state.

```
      off                         on
   ┌────────────┐            ┌────────────┐
   │ ○          │            │          ● │
   └────────────┘            └────────────┘
    track: ink-6              track: accent
    thumb: ink-1 (start)      thumb: accent-on (end)

   [══○]  Email me the daily close summary        ← switch + label (inline)
          Sends at 18:00 in the company timezone.  ← optional description (ink-9)
```

- **Track**, off: `ink-6` fill, `radius-full`, height 20–24px by size.
- **Track**, on: `accent` fill, `radius-full`.
- **Thumb**: a `radius-full` disc, `accent-on`-toned, `shadow-xs`; sits at the inline-start when off and
  slides to the inline-end when on. The **thumb's travel is the primary, position-based state signal** —
  it survives grayscale, satisfying [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) Principle 5 without needing
  an on/off glyph.
- **Label**: `text-sm` in `ink-11`. By default it sits on the inline-**start** and the switch on the
  inline-**end** of the row (a settings-list convention: the setting name reads first, the control is at
  the trailing edge); `labelPlacement="end"` puts the switch first for tight inline uses.
- **Description**: optional `text-xs` in `ink-9` under the label, associated via `aria-describedby`.
- **Pending affordance**: while a flip round-trips, a small inline spinner replaces or overlays the thumb
  and the control sets `aria-busy` (see [`# States`](#states)).
- **Hit target**: the whole label row is clickable and ≥24px tall (≥44px on coarse pointers).

# Variants

A switch is on or off; it has no decorative variants. Its axes are **size**, **label placement**, and
**state**.

| Axis | Values | Notes |
|---|---|---|
| `size` | `sm` (track 20×12, thumb 8) · `default` (track 28×16, thumb 12) · `lg` (track 36×20, thumb 16) | Aligned to the shared control scale so a switch lines up with adjacent controls in a settings row. |
| `labelPlacement` | `start` (default, label leads) · `end` (switch leads) | `start` for a settings list; `end` for a compact inline toggle. |
| State | off · on · disabled · loading (pending) · invalid | Full matrix in [`# States`](#states). `loading` is unique to the switch among the boolean controls. |

## Size and thumb travel

```tsx
// components/ui/switch.tsx (canonical QAYD tokens)
'use client';
import * as SwitchPrimitive from '@radix-ui/react-switch';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const trackVariants = cva(
  'peer inline-flex shrink-0 cursor-pointer items-center rounded-full border border-transparent ' +
    'transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ' +
    'disabled:cursor-not-allowed disabled:opacity-50 ' +
    'data-[state=unchecked]:bg-ink-6 data-[state=checked]:bg-accent',
  {
    variants: { size: { sm: 'h-3 w-5', default: 'h-4 w-7', lg: 'h-5 w-9' } },
    defaultVariants: { size: 'default' },
  },
);

const thumbVariants = cva(
  'pointer-events-none block rounded-full bg-accent-on shadow-xs ring-0 transition-transform ' +
    // logical translate: moves toward the inline-end when checked; RTL handled by the parent dir
    'data-[state=unchecked]:translate-x-0',
  {
    variants: {
      size: {
        sm: 'size-2 data-[state=checked]:translate-x-2',
        default: 'size-3 data-[state=checked]:translate-x-3',
        lg: 'size-4 data-[state=checked]:translate-x-4',
      },
    },
    defaultVariants: { size: 'default' },
  },
);

export function Switch({ size, className, ...props }: SwitchProps) {
  return (
    <SwitchPrimitive.Root className={cn(trackVariants({ size }), className)} {...props}>
      <SwitchPrimitive.Thumb className={cn(thumbVariants({ size }))} />
    </SwitchPrimitive.Root>
  );
}
```

The thumb translate is a `translate-x` under the hood; under RTL the parent `dir="rtl"` flips the visual
axis so the "on" thumb still lands on the reading-trailing edge (see
[`# Theming, Dark Mode & RTL`](#theming-dark-mode--rtl)).

# Props / API

The switch is a controlled Radix `Switch`; QAYD adds `size`, `labelPlacement`, and `loading`.

| Prop | Type | Default | Description |
|---|---|---|---|
| `checked` | `boolean` | — | Controlled on/off state. |
| `onCheckedChange` | `(checked: boolean) => void` | — | Fires on flip. For an immediate-effect switch this handler starts the Server Action and (optionally) optimistically updates. |
| `size` | `'sm' \| 'default' \| 'lg'` | `'default'` | Track/thumb dimensions, aligned to the shared control scale. |
| `labelPlacement` | `'start' \| 'end'` | `'start'` | Whether the label leads (settings list) or the switch leads (inline). |
| `loading` | `boolean` | `false` | Pending state: shows the inline spinner, sets `aria-busy`, and blocks re-toggling until the round-trip resolves. |
| `disabled` | `boolean` | `false` | Non-interactive, `opacity-50`, skipped by tab order; permission tooltip when RBAC-gated. |
| `invalid` | `boolean` | `false` | Rare for a switch (a boolean is seldom "invalid"); reserved for a must-be-on gate, drawing the negative focus ring. |
| `id` | `string` | Radix-generated | Ties the switch to its `<Label htmlFor>`. |
| `aria-label` | `string` | — | Required only when there is no visible label. |

Because the switch commits immediately, its `onCheckedChange` is where the platform's optimistic-update
pattern lives — flip the local state, mark `loading`, fire the Server Action, and on failure revert the
state and surface a toast. That orchestration lives in the consuming component, not the primitive; the
primitive only renders `checked` and `loading` as told.

# States

State is Radix's `data-state` (`checked` / `unchecked`) plus QAYD's `loading` / `disabled` / `invalid`.
The **thumb position** is the primary signal in every state, so on/off is legible without color.

| State | Track | Thumb | Notes |
|---|---|---|---|
| Off | `ink-6` | at inline-start | Resting default. |
| Off · hover | `ink-7` | at inline-start | `motion.fast` (160ms) track color transition. |
| On | `accent` | at inline-end | Thumb slides on `motion.base` (200ms) `easeInOut`; instant under reduced motion. |
| On · hover | `accent-strong` | at inline-end | Slightly stronger brass on hover/pressed. |
| Focus (keyboard) | inherit | inherit | 2px `ring` (accent) + 2px offset, `focus-visible` only. |
| Loading (pending) | current | spinner overlay | `aria-busy="true"`; the control is non-toggleable until the Server Action resolves. |
| Failed → reverted | previous | previous | On a rejected Server Action the switch visibly slides back to its prior position and a toast explains why. |
| Disabled | dimmed | dimmed | `opacity-50`, `cursor-not-allowed`; permission tooltip if RBAC-gated. |
| Invalid | `negative` ring | — | Rare; a must-be-on gate left off. |

The on-flip thumb slide is `motion.base` (200ms) with `easeInOut` — a *toggle*, per
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) `# Motion` (the `easeInOut` curve is the one named for
"toggles, expand/collapse"). Under `prefers-reduced-motion: reduce` the thumb jumps to its end position
with no slide, and the pending spinner is replaced by a static "Saving…" text cue
([`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md)). The **loading and revert states are what make the switch
different from every other boolean control** — an immediate-effect toggle owes the user honest feedback
that its change reached the server, and honest reversal when it did not.

# Tokens Used

Every value resolves to a token; no literal a token already covers appears in the primitive. Color tokens
carry their own light/dark values from [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md).

| Role | Token / utility | Light | Dark |
|---|---|---|---|
| Track, off | `bg-ink-6` | `#C2BEB6` | `#46402F` |
| Track, off hover | `bg-ink-7` | `#A9A49A` | `#58503A` |
| Track, on | `bg-accent` | `#9C7A34` | `#D9B96C` |
| Track, on hover/pressed | `bg-accent-strong` | `#7A5D22` | `#E8CE8E` |
| Thumb | `bg-accent-on` | `#15130E` | `#14130F` |
| Thumb elevation | `shadow-xs` | `0 1px 2px rgba(21,19,14,.04)` | reduced-alpha (dark) |
| Focus ring | `ring-ring` (= accent) | `#9C7A34` | `#D9B96C` |
| Invalid ring | `ring-negative` | `#B4232E` | `#F26B74` |
| Label text | `text-ink-11` | `#2B2820` | `#E4DEC9` |
| Description text | `text-ink-9` | `#645F53` | `#A39878` |
| Disabled | `opacity-50` on the above | — | — |
| Track/thumb shape | `rounded-full` (`radius-full`) | — | — |
| Thumb slide motion | `motion.base` = 200ms, `easeInOut` | — | — |
| Track hover motion | `motion.fast` = 160ms | — | — |

The **thumb is `accent-on` (near-black), not white**, and the "on" track is `accent` — the same
filled-accent-surface pairing as a checked checkbox, for the same reason: near-black on brass clears AA
(≈5.5:1 light, ≈9.6:1 dark) while white on brass does not
([`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) `# accent-on is near-black, never white`). The "off" track is
`ink-6` — a neutral hairline-weight fill, deliberately not `negative`, because "off" is a neutral state,
not a bad one. The switch is one of the sanctioned accent uses (a committed setting), not a decorative
highlight.

# Accessibility

The switch meets the WCAG 2.1 AA floor of [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) and
[`../../frontend/ACCESSIBILITY.md`](../../frontend/ACCESSIBILITY.md).

| Concern | Contract |
|---|---|
| Role | Radix renders `role="switch"` — distinct from `role="checkbox"`; a screen reader announces "switch, on/off," which correctly primes the user that flipping takes effect now. |
| State | `aria-checked` is `true` / `false` (a switch has no `mixed` state — there is no indeterminate switch). |
| Keyboard | `Tab` focuses the switch; `Space` **or** `Enter` toggles it (Radix accepts both on a switch). Each switch is its own tab stop. |
| Pending | During a flip's Server Action the control sets `aria-busy="true"`; the resolution (or a revert) is announced via an `aria-live` toast so a non-visual user learns the save succeeded or failed. |
| Label | Every switch has an accessible name — a visible `<Label htmlFor>` or an `aria-label`; the whole label row is a click target. |
| Focus | `focus-visible` ring only, 2px `ring` + 2px offset, ≥3:1 against every surface. |
| Color independence | On/off is carried by the **thumb's position**, not the track hue alone — the state survives grayscale and color-vision deficiency without an on/off glyph. |
| Disabled by permission | An RBAC-disabled switch keeps a tooltip naming the required key, never a bare grey rail, per [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) `# WCAG AA Contrast`. |
| Target size | The hit area is ≥24×24px (≥44px coarse pointer) even at the `sm` track size. |

The `role="switch"` vs. `role="checkbox"` distinction is not cosmetic: it changes what assistive tech
tells the user about *when* their action lands. A switch says "this is live"; a checkbox says "this is a
value you will submit." Matching the role to the actual behavior is a correctness requirement, not a
preference — see the semantic split in [`CHECKBOX.md`](./CHECKBOX.md).

# Theming, Dark Mode & RTL

- **Tokens only.** Track, thumb, focus ring, and invalid state each resolve to an
  `ink`/`accent`/`negative` variable; the source has no raw hex and no `dark:` override against a raw
  color. Dark mode is the token remap of [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) — the "on" track
  `bg-accent` becomes `#D9B96C`, the thumb `accent-on` becomes `#14130F`, holding the same relationship.
- **Dark-mode calibration.** The "off" track (`ink-6`) stays a warm neutral in both themes; the accent
  lightens rather than saturates on flip, so an "on" switch reads as confident, not glowing.
- **RTL — the thumb travel flips.** "Off" is the inline-start end and "on" is the inline-end end, so
  under `[dir="rtl"]` the thumb slides right-to-left: the on-thumb still lands on the *reading-trailing*
  edge. The `translate-x` is applied within a `dir`-aware container so Radix and layout agree on the
  axis; never hardcode a physical left/right translate that would put "on" on the wrong side in Arabic.
- **Label placement is logical.** `labelPlacement="start"` puts the label on the inline-start via
  `flex-row` + logical gaps; under RTL the label is on the right and the switch on the left, matching the
  reading order automatically.
- **No mirrored glyph.** The switch carries no on/off icon by default (position is the signal); if a
  product surface adds one, it is a meaning glyph and does not flip.

# Do / Don't

| Do | Don't |
|---|---|
| Use a switch for an immediate-effect setting that saves on flip | Use a switch for a value the form submits later — that is a [Checkbox](./CHECKBOX.md) |
| Show a `loading` state while the flip round-trips, and revert visibly on failure | Flip the switch and assume the save succeeded silently |
| Carry on/off by the thumb's position | Rely on the track hue as the only on/off signal |
| Pair the "on" `accent` track with the near-black `accent-on` thumb | Put a white thumb on the brass track (fails the AA pairing) |
| Keep the "off" track a neutral `ink-6` | Color the "off" state `negative` as if off were "bad" |
| Give the switch `role="switch"` (Radix default) and an accessible name | Reuse a checkbox styled to look like a switch (wrong role) |
| Flip the thumb travel under RTL so "on" is the reading-trailing edge | Hardcode a physical left/right translate |
| Set the slide to `motion.base`/`easeInOut`, instant under reduced motion | Bounce or over-animate the toggle |
| Attach a permission tooltip to an RBAC-disabled switch | Leave a grey rail with no explanation |
| Reference tokens (`bg-accent`, `bg-ink-6`) | Hardcode `#9C7A34` or a raw Tailwind palette value |

# Usage & Composition

- **As an immediate-effect setting row.** The canonical use is a Settings screen list where each row is a
  setting that saves on flip. The `onCheckedChange` optimistically updates, marks `loading`, fires the
  Server Action, and reverts on failure:

  ```tsx
  'use client';
  function DailySummarySwitch({ initial }: { initial: boolean }) {
    const [on, setOn] = useState(initial);
    const [pending, setPending] = useState(false);

    async function toggle(next: boolean) {
      setOn(next); setPending(true);                 // optimistic
      const res = await saveSetting('daily_summary', next);   // Server Action
      setPending(false);
      if (!res.ok) { setOn(!next); toast.error(res.message); } // visible revert
    }

    return (
      <div className="flex items-center justify-between gap-4 py-3">
        <div className="grid gap-0.5">
          <Label htmlFor="daily-summary" className="text-sm text-ink-11">Daily close summary email</Label>
          <p className="text-xs text-ink-9">Sends at 18:00 in the company timezone.</p>
        </div>
        <Switch id="daily-summary" checked={on} loading={pending} onCheckedChange={toggle} />
      </div>
    );
  }
  ```

- **Not inside a submit-later form.** A switch is deliberately **not** the control for a value the user
  edits and then saves with a form's Save button — that is a [`Checkbox`](./CHECKBOX.md). A settings
  *form* that batches several changes behind an explicit Save uses checkboxes; a settings *list* where
  each row is live uses switches. Mixing the two on one screen confuses when changes land, so a screen
  picks one model.

- **Not for a destructive or high-stakes flip.** Turning off a control that has real consequences
  (disabling two-factor, closing a fiscal period) does not ride on a bare switch flip — it pairs the
  switch with an `AlertDialog` confirmation, and only then commits, because an immediate-effect control
  makes an accidental flip immediately real.

- **Not a filter or a mode toggle.** A binary *view* toggle in a toolbar (density, a two-mode comparison)
  is a [`SegmentedControl`](../../frontend/components/INPUTS.md), not a switch; a switch implies a
  persisted setting, not a transient view state.

For the immediate-effect vs. submit-later split see
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) `# Boolean & bulk` and
[`CHECKBOX.md`](./CHECKBOX.md); for tokens see [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); for the single-select siblings see [`RADIO.md`](./RADIO.md).

# End of Document
