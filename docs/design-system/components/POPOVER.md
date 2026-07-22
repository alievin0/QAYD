# Popover — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / POPOVER
---

# Purpose

This document is the atomic specification for QAYD's **popover** primitive: the click-triggered, interactive
overlay that floats a small panel of *focusable* content next to its trigger — a filter builder, an inline
edit, a date/period picker body, a column-visibility menu, a "why this?" drill-in with a link. It is built on
**`@radix-ui/react-popover`**, re-skinned to QAYD tokens, and it is the workhorse for "a bit of interactive
UI attached to a control, but not a full modal."

The popover exists in the narrow band between three neighbours, and half of this document's job is to keep it
out of their territory:

- Heavier than a **tooltip** ([`./TOOLTIP.md`](./TOOLTIP.md)) — a tooltip is hover-triggered, non-interactive,
  text-only, and holds only what the user can succeed without. A popover is click-triggered, holds focusable
  content, and can carry information the task depends on.
- Lighter than a **modal/dialog** — a popover does **not** block the page with a scrim, does **not** trap
  focus for the whole screen, and dismisses on an outside click. It is for a *contextual, non-critical* task
  the user can walk away from by clicking elsewhere. A decision that must be made, or a destructive
  confirmation, is a `Dialog`, not a popover.
- More structured than a **dropdown menu** — a `DropdownMenu` is a list of *commands* (a roving-tabindex menu
  of actions). A popover is a *panel of content* (inputs, a mini-form, a description with a link). If the
  content is "a menu of actions," it is a `DropdownMenu`; if it is "a little surface with fields," it is a
  popover. Both share the `z-dropdown` tier and the same `surface-2` treatment.

Radix supplies the behavior that makes a popover safe — collision-aware positioning, focus management on open
and return-focus on close, `Esc`/outside-click dismissal, portalling out of clipping containers — and QAYD
does not modify it; it themes it and states the composition rules below.

Related reading: surface/shadow/`z-dropdown` in [`../ELEVATION_SHADOWS.md`](../ELEVATION_SHADOWS.md);
color roles in [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); open/close timing and reduced motion in
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); focus and keyboard rules in
[`../ACCESSIBILITY.md`](../ACCESSIBILITY.md). The `AccountPicker` combobox
([`../../frontend/COMPONENT_LIBRARY.md → AccountPicker`](../../frontend/COMPONENT_LIBRARY.md)) is a popover-hosting
composition and is the canonical worked example. Sibling primitives: [`./TOOLTIP.md`](./TOOLTIP.md),
[`./TOAST.md`](./TOAST.md), [`./BADGE.md`](./BADGE.md).

# Anatomy

| Part | Role | Notes |
|---|---|---|
| Trigger | The control that opens the popover | `<PopoverTrigger asChild>` wrapping a real `<button>` (or a `role="combobox"` button for a picker). Gets `aria-expanded` / `aria-haspopup="dialog"` from Radix. |
| Content panel | The `surface-2` floating panel | `bg-ink-1` (light) / `bg-ink-3` (dark), 1px `ink-6`, `radius-lg` (8px), `shadow-sm`; padding by size. |
| Arrow | Optional 8px pointer to the trigger | `aria-hidden`; commonly omitted for panels aligned to a trigger edge. |
| Header (optional) | A `display-sm` title + optional close `X` | Only for a panel dense enough to warrant a heading; a picker body has none. |
| Body | The focusable content | Inputs, a `Command` list, a mini-form, a description-with-link. This is what distinguishes a popover from a tooltip. |
| Footer (optional) | Apply / Reset actions | For a filter builder or inline edit; a single-select picker closes on select and needs no footer. |

The panel is the same quiet `surface-2` a dropdown and a select menu use — **no scrim, no page dimming**.
Its separation from the page is carried by the `ink-6` hairline border first and the soft `shadow-sm` second;
remove the shadow and the border still reads it as a distinct surface
([`../ELEVATION_SHADOWS.md → Border-vs-Shadow Separation`](../ELEVATION_SHADOWS.md)).

```
              [ Filters ▾ ]  ← trigger, aria-expanded=true
        ┌───────────────────────────────┐
        │ Status        [ Any      ▾ ]   │   ← surface-2 panel, focusable body
        │ Amount ≥      [ 0.000     ]    │
        │ ───────────────────────────    │
        │            [ Reset ] [ Apply ] │   ← optional footer
        └───────────────▲───────────────┘   ← optional arrow
```

# Variants

The popover's variants are structural (there is no tone axis — a popover is a neutral content surface, never
a status surface):

| Axis | Values | Notes |
|---|---|---|
| Size | `sm` (`w-56`, 12px pad) · `md` (`w-72`, 16px pad, default) · `auto` (`w-[--radix-popover-trigger-width]`) | `auto` matches the trigger width — the standard for pickers/comboboxes so the panel lines up under its field. |
| Align | `start` (default) · `center` · `end` | Logical alignment to the trigger; resolved per `dir`. |
| Side | `bottom` (default) · `top` · `left` · `right` | Requested side; flipped by collision detection. |
| Arrow | present · absent | Absent when the panel is edge-aligned to the trigger. |
| Modality | `modal={false}` (default) · `modal` | Default is **non-modal**: the page behind stays scrollable and outside clicks dismiss. `modal` (rare) blocks background interaction for a focus-sensitive inline task without a full dialog; it still shows no scrim. |

The three real shapes a popover takes in QAYD:

1. **Picker body** (`auto` width, no header/footer) — hosts a `Command` list, e.g. `AccountPicker`,
   `PeriodPicker`'s calendar; closes on select.
2. **Mini-form** (`md`, footer with Apply/Reset) — a filter builder or an inline field edit; commits on Apply,
   discards on outside-click/Esc.
3. **Drill-in** (`md`, optional header) — a description-with-link or a small read-mostly detail that,
   *unlike a tooltip*, contains a focusable link the user can tab to and follow.

# Props / API

QAYD wraps Radix's popover parts in `components/ui/popover.tsx`, keeping Radix names.

## `<Popover>` (and parts)

| Part / prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `<Popover open / defaultOpen / onOpenChange modal>` | Radix root | — | uncontrolled, `modal={false}` | Controlled when the opener needs to react to open state (a combobox syncing its query). |
| `<PopoverTrigger asChild>` | — | yes | — | Wraps the real trigger button; `asChild` keeps it a `<button>`. |
| `<PopoverContent side sideOffset align alignOffset collisionPadding onOpenAutoFocus onCloseAutoFocus>` | — | — | `side="bottom"`, `sideOffset=6`, `align="start"`, `collisionPadding=8` | The panel. Focus callbacks let a picker send initial focus to its search input. |
| `<PopoverAnchor>` | — | no | — | Positions the panel against an element other than the trigger (rare; e.g. anchor to a table cell while triggered by its edit icon). |
| `<PopoverClose asChild>` | — | no | — | A dismiss control inside the panel (the header `X`, or a footer "Cancel"). |

## QAYD conventions layered on top

| Convention | Value | Rationale |
|---|---|---|
| Width for pickers | `w-[--radix-popover-trigger-width]` | Panel matches the field it drops from. |
| Open/close motion | `motion.fast` (160ms) for a bare panel; `motion.moderate` (280ms) when it carries laid-out content | Calm-motion; heavier content gets a slightly longer settle. |
| Dismiss | outside-click + `Esc` + `PopoverClose` | Non-modal contextual dismissal; a popover is never "trapping." |
| Nesting | a popover may host a `Select`/`DropdownMenu`/`Tooltip`; it may **not** open a `Dialog` from inside itself | See Behavior → Nesting. |

```tsx
// components/ui/popover.tsx — QAYD-themed Radix popover
'use client';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { cn } from '@/lib/utils';

export const Popover = PopoverPrimitive.Root;
export const PopoverTrigger = PopoverPrimitive.Trigger;
export const PopoverAnchor = PopoverPrimitive.Anchor;
export const PopoverClose = PopoverPrimitive.Close;

export function PopoverContent({
  className, align = 'start', sideOffset = 6, ...props
}: React.ComponentProps<typeof PopoverPrimitive.Content>) {
  return (
    <PopoverPrimitive.Portal>       {/* #portal-root — escapes sticky headers / overflow:hidden */}
      <PopoverPrimitive.Content
        align={align}
        sideOffset={sideOffset}
        collisionPadding={8}
        className={cn(
          'z-[20] w-72 rounded-lg border border-ink-6 bg-ink-1 dark:bg-ink-3 p-4 text-ink-12 shadow-sm',
          'outline-none focus-visible:ring-2 focus-visible:ring-accent',
          // enter/exit via Radix data-state; reduced-motion gated in globals.css
          'data-[state=open]:animate-in data-[state=closed]:animate-out',
          'data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0',
          'data-[side=bottom]:slide-in-from-top-1 data-[side=top]:slide-in-from-bottom-1',
          className,
        )}
        {...props}
      />
    </PopoverPrimitive.Portal>
  );
}
```

# States

| State | Trigger | Behavior |
|---|---|---|
| Closed | — | Panel not in the DOM; trigger `aria-expanded="false"`. |
| Opening | Trigger clicked / activated via `Enter`/`Space` | Panel mounts, fades/slides in over `motion.fast`–`motion.moderate`, `easeOut`. Focus moves per `onOpenAutoFocus` (first focusable, or a picker's search input). |
| Open | Enter complete | `aria-expanded="true"`; focus is inside the panel; the page behind stays scrollable (non-modal). |
| Collision-adjusted | Requested side/align has no room | Radix flips side and/or shifts along the axis (`collisionPadding=8`) so the panel stays on-screen; the arrow, if present, tracks the trigger. |
| Dismissing | Outside click, `Esc`, `PopoverClose`, or a successful commit (select/Apply) | Panel fades/slides out over `motion.fast`, `easeIn`; **focus returns to the trigger** unless the commit intentionally moved it. |
| Closed (returned) | Dismiss complete | Panel unmounts; trigger regains focus and `aria-expanded="false"`. |

**Dismiss policy** is the popover's defining behavior versus a modal: it dismisses on an **outside click**
(non-modal) and on `Esc`, and it does **not** dim or block the page. A surface that must not be dismissed by
clicking away — because a decision or a destructive action is pending — is by definition not a popover; it is
a `Dialog`. Radix's `onOpenAutoFocus`/`onCloseAutoFocus` give a picker precise control: focus its search input
on open, and (for a select-and-close picker) let focus return to the trigger on close.

# Tokens Used

| Concern | Token | Value |
|---|---|---|
| Panel background | `ink-1` (light) / `ink-3` (dark) | `#FAFAF9` / `#24211A` |
| Panel border | `ink-6` | `#C2BEB6` / `#46402F` |
| Radius | `radius-lg` | 8px |
| Shadow | `shadow-sm` | `0 2px 4px rgba(21,19,14,.06)`; half-alpha `rgba(0,0,0,…)` in dark |
| Text | `text-sm`, `ink-12` / `ink-9` | body / secondary |
| Focus ring | `accent` | `#9C7A34` / `#D9B96C`, 2px, ≥3:1 on the panel |
| Footer primary action | `bg-accent text-accent-on` | near-black label on brass |
| Stack tier | `z-dropdown` | 20 (with dropdowns, selects, comboboxes) |
| Padding | `--space-sm` / `--space-md` | 12px (`sm`) / 16px (`md`) |
| Open / close | `motion.fast` / `motion.moderate`, `easeOut` / `easeIn` | 160–280ms |

The popover shares `z-dropdown` (20) with dropdown menus, selects, and comboboxes — the first portalled tier,
above the shell's own sticky chrome, below scrims and modals
([`../ELEVATION_SHADOWS.md → Z-Index Tiers`](../ELEVATION_SHADOWS.md)). A popover triggered *from inside a
dialog* renders above that dialog in DOM/stacking order because it mounts later and portals to `#portal-root`
(see Behavior → Nesting).

# Accessibility

- **Radix owns focus management.** On open, focus moves into the panel (to its first focusable element, or
  wherever `onOpenAutoFocus` directs, e.g. a picker's search input); on close, focus returns to the trigger.
  QAYD does not re-implement this. A popover whose content is not reachable by keyboard is a defect.
- **Non-modal focus, by default.** Unlike a dialog, a non-modal popover does **not** trap focus for the whole
  page — `Tab` can move out of it and, when focus leaves, the popover closes. This is correct: a popover is a
  contextual aid, not a task the user is locked into. Use `modal` only when an inline task genuinely needs the
  background inert, and even then there is no scrim.
- **Trigger semantics.** The trigger carries `aria-haspopup="dialog"` and `aria-expanded`, wired by Radix, so
  assistive tech announces "opens a dialog" / "expanded". A combobox-style trigger uses
  `role="combobox"` + `aria-expanded` + `aria-controls` (the `AccountPicker` pattern).
- **`Esc` and outside-click both close**, and both return focus to the trigger — a keyboard user is never
  stranded inside a dismissed panel.
- **Labeled panel.** A popover with a header uses `aria-labelledby` pointing at the header; a picker labels its
  content via its search input's `aria-label`. The panel is never an unlabeled floating region.
- **Focus ring is visible on the panel and its controls** in both themes (`accent`, ≥3:1). Contrast of body
  text (`ink-12`) and secondary text (`ink-9`) on the panel clears AA in light and dark
  ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).
- **Not for essential-only-on-hover content** — a popover is click/keyboard triggered precisely so it is
  reachable (unlike a tooltip); this is why interactive or task-essential content belongs here, not in a
  tooltip.

# Theming, Dark Mode & RTL

## Theming

The panel references only tokens — `bg-ink-1`/`dark:bg-ink-3`, `border-ink-6`, `text-ink-12`, `ring-accent`,
and `bg-accent`/`text-accent-on` for a footer primary action. No raw hex. The white-label accent affects only
the focus ring and any single primary action inside the footer; the panel surface itself stays neutral ink.

## Dark mode

One background swap (`bg-ink-1` → `dark:bg-ink-3`) lands the panel on the dark `surface-2` — a step lighter
than the dark canvas so it reads as raised even with its shadow removed — and the `ink-6` border plus the
half-alpha dark `shadow-sm` complete the separation ([`../ELEVATION_SHADOWS.md → Light vs. Dark Elevation`](../ELEVATION_SHADOWS.md)).
Every other token remaps under `.dark`; no additional dark override is written.

## RTL

- **Alignment is logical.** `align="start"` resolves to the reading-start edge — left in LTR, right in RTL —
  so a `start`-aligned panel lines up under the correct edge of its trigger in both directions without a
  branch. `alignOffset` uses logical sign.
- **Collision flipping is symmetric.** A panel that would overflow the reading-end edge flips the same way in
  both directions because the requested side/align is logical and Radix handles the physical flip.
- **Content mirrors through its own logical layout.** Inputs, labels, and the footer button row inside the
  panel use logical properties (`ms`/`me`, `text-start`), so the whole panel mirrors for free; numerals,
  amounts, and currency codes inside it render `dir="ltr"` (numerals never mirror).
- **The arrow is symmetric** and needs no mirroring.

## Reduced motion

Under `prefers-reduced-motion: reduce`, the slide is dropped and the panel cross-fades (or appears
instantly); open/close *behavior* is unchanged, only its animation ([`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md)).

# Do / Don't

| Do | Don't |
|---|---|
| Use a popover for a small *interactive* panel attached to a control (filter, inline edit, picker body) | Use a popover for hover-only, non-interactive text — that is a tooltip |
| Let it dismiss on outside click and `Esc`, returning focus to the trigger | Use a popover where the user must decide/confirm before leaving — that is a dialog |
| Keep it non-modal and scrim-less | Add a page-dimming scrim to a popover "to focus attention" |
| Use a `DropdownMenu` when the content is a list of actions | Build an actions menu by hand inside a popover panel |
| Match a picker panel to its trigger width (`--radix-popover-trigger-width`) | Let a picker panel float at an unrelated width, misaligned to its field |
| Portal to `#portal-root` at `z-dropdown` (20) | Render the panel inline where a sticky header or `overflow:hidden` clips it |
| Send initial focus to a picker's search input via `onOpenAutoFocus` | Leave focus on the trigger while the panel's search input sits unfocused |
| Host a `Select`/`Tooltip`/`DropdownMenu` inside a popover when needed | Open a `Dialog` from inside a popover (promote the whole interaction to a dialog instead) |
| Author `align`/`side` as logical intent | Hardcode a physical side that clips or mirrors wrongly in RTL |

# Usage & Composition

**Picker body** (the `AccountPicker` pattern — a popover hosting a `Command` list, `auto` width, closes on
select) is specified in full at
[`../../frontend/COMPONENT_LIBRARY.md → AccountPicker`](../../frontend/COMPONENT_LIBRARY.md). The load-bearing
popover parts:

```tsx
<Popover open={open} onOpenChange={setOpen}>
  <PopoverTrigger asChild>
    <Button variant="outline" role="combobox" aria-expanded={open} className="w-full justify-between">
      {selected ? `${selected.code} · ${label}` : <span className="text-ink-9">{placeholder}</span>}
      <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
    </Button>
  </PopoverTrigger>
  <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
    <Command shouldFilter={false}>
      <CommandInput placeholder={t('searchByCodeOrName')} value={search} onValueChange={setSearch} />
      <CommandList>{/* results — onSelect commits and closes */}</CommandList>
    </Command>
  </PopoverContent>
</Popover>
```

**Filter mini-form** (`md`, footer with Apply/Reset, commits on Apply, discards on outside-click):

```tsx
<Popover>
  <PopoverTrigger asChild>
    <Button variant="outline" size="sm"><Filter className="h-3.5 w-3.5" /> {t('filters')}</Button>
  </PopoverTrigger>
  <PopoverContent align="end">
    <div className="space-y-3">{/* status Select, amount range Input(numeric) */}</div>
    <div className="mt-4 flex justify-end gap-2">
      <PopoverClose asChild><Button variant="ghost" size="sm" onClick={reset}>{t('reset')}</Button></PopoverClose>
      <PopoverClose asChild><Button size="sm" onClick={apply}>{t('apply')}</Button></PopoverClose>
    </div>
  </PopoverContent>
</Popover>
```

**Nesting.** A popover may host a `Select`, `DropdownMenu`, or `Tooltip` inside it — all portal above the panel
and dismiss independently. A popover may **not** open a `Dialog`: the maximum permitted overlay nesting is
`Sheet → Dialog` ([`../ELEVATION_SHADOWS.md → Z-Index Tiers`](../ELEVATION_SHADOWS.md)), and a dialog launched
from a transient dismiss-on-outside-click popover would orphan its own scrim when the popover closes. If a
popover's content grows to warrant a dialog, promote the *whole* interaction to a dialog from the original
trigger.

**The composition boundary, restated.** Non-interactive + hover + succeed-without → [`./TOOLTIP.md`](./TOOLTIP.md).
Interactive + contextual + dismiss-on-outside-click → popover. Must-decide + blocking + scrim → `Dialog`. A
list of actions → `DropdownMenu`. Picking the wrong one of these four is the most common overlay mistake in
the frontend, which is why each spec draws the boundary explicitly.

# End of Document
