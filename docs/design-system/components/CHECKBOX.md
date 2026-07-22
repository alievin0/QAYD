# Checkbox — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / CHECKBOX
---

# Purpose

This document is the atomic specification for QAYD's **Checkbox** primitive — the single boolean
control a user checks to select, opt in, or mark a row. It is the design-system-level companion to the
application-facing input catalogue in [`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md),
which lists `Checkbox` among the platform's controls and states the one rule that separates it from its
sibling: a checkbox is a **value the form submits later**, whereas a [Switch](./SWITCH.md) is an
**immediate-effect toggle**. That semantic split is normative and is repeated here because it is the most
common place the two controls get misused. INPUTS.md owns how the control is *wired into a field*; this
document owns the *primitive itself* — its anatomy, its exact token bindings, its full state matrix, its
indeterminate contract, its group behavior, and its keyboard and screen-reader semantics — at a level of
detail a frontend engineer or an AI coding agent can build from without guessing.

The checkbox is a re-skinned **Radix `Checkbox`** primitive. QAYD keeps Radix's accessibility contract
(the roving `role="checkbox"`, `aria-checked`, the `data-state` attribute, `Space`-to-toggle) untouched
and adds only its own tokens, a `size` axis aligned to the rest of the control set, and an `invalid`
state. Nothing about the control computes a fact: a row-select checkbox selects a row, but the totals,
balances, and permissions that act on that selection are the server's answers, surfaced by the UI, never
recomputed on the client (see [`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md)
`# Foundations`).

All values referenced below — every hex, radius, duration, and contrast ratio — trace to
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); this document
introduces no literal a token does not already cover.

# Anatomy

A checkbox is three parts: the **box** (the interactive 4px-radius square), the **indicator** (the check
or dash glyph revealed when checked or indeterminate), and — in nearly every real use — an associated
**label** and optional **description**. The box is the only interactive surface the primitive draws; the
label is supplied by the consuming context (a `<FormLabel>` inside a form, or a plain `<label htmlFor>`
in a standalone use).

```
┌───────────────────────────────────────────────┐
│  [[check]]  Reconcile matched lines automatically     │   ← box + indicator + label
│       Applies only to exact-amount matches.     │   ← optional description (ink-9)
└───────────────────────────────────────────────┘
   │      │
   │      └─ label: text-sm ink-11, associated via htmlFor / Radix id
   └─ box: 16–20px square, radius-sm (4px), 1px ink-7 border,
          accent fill + accent-on glyph when checked
```

- **Box**, unchecked: `ink-1` fill, `1px ink-7` border, `radius-sm`.
- **Box**, checked / indeterminate: `accent` fill, no border, `accent-on` glyph inside.
- **Indicator**: a `Check` glyph when checked, a `Minus` glyph when indeterminate — both from Lucide,
  both drawn in `accent-on`, both `aria-hidden` (state is carried by `aria-checked`, not the icon).
- **Label**: `text-sm` in `ink-11`, sits on the inline-end of the box with an `--space-xs` (8px) gap;
  clicking the label toggles the box (native `htmlFor`/Radix association).
- **Description**: optional `text-xs` in `ink-9` under the label, associated via `aria-describedby`.
- **Hit target**: the box is visually 16–20px but the clickable/tappable area is padded to a minimum of
  24×24px (44×44px on coarse pointers) so it clears the touch-target floor without enlarging the mark.

The label is never omitted. A checkbox with no visible label (a table's row-select column) still carries
an `aria-label` (`"Select row"`) — a label-less, description-less checkbox is a defect.

# Variants

The checkbox has no decorative variants; a boolean is a boolean. Its axes are **size** and **state**, and
one structural composition — the **checkbox group**.

| Axis | Values | Notes |
|---|---|---|
| `size` | `sm` (16px) · `default` (18px) · `lg` (20px) | Aligned to the shared control `size` scale so a checkbox lines up with an adjacent input/button on a toolbar. |
| State | unchecked · checked · indeterminate · disabled · invalid | The full matrix is in [`# States`](#states); `indeterminate` is a third *visual* state, not a third submitted value. |

## Size

```tsx
// components/ui/checkbox.tsx (variant excerpt — canonical QAYD tokens)
import { cva } from 'class-variance-authority';

const checkboxVariants = cva(
  'peer shrink-0 rounded-sm border bg-ink-1 transition-colors ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ' +
    'disabled:cursor-not-allowed disabled:opacity-50 ' +
    'data-[state=checked]:bg-accent data-[state=checked]:border-accent ' +
    'data-[state=indeterminate]:bg-accent data-[state=indeterminate]:border-accent',
  {
    variants: {
      size: { sm: 'size-4', default: 'size-[18px]', lg: 'size-5' },
      invalid: { true: 'border-negative focus-visible:ring-negative', false: 'border-ink-7' },
    },
    defaultVariants: { size: 'default', invalid: false },
  },
);
```

## Checkbox group

A set of related checkboxes (a permission list, a set of export columns, a filter's facet set) is not a
Radix primitive of its own — it is a labelled `<fieldset>` with a `<legend>`, holding one `Checkbox` per
option and, when the set is large, a **parent (select-all) checkbox** that renders `indeterminate` while
the children are partially selected. The parent's `indeterminate` state is derived, never submitted:

```tsx
// components/ui/checkbox-group.tsx (shape)
'use client';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

export function CheckboxGroup({ legend, options, value, onValueChange }: CheckboxGroupProps) {
  const allChecked = options.every((o) => value.includes(o.value));
  const someChecked = options.some((o) => value.includes(o.value));
  const parentState = allChecked ? true : someChecked ? 'indeterminate' : false;

  return (
    <fieldset className="space-y-3">
      <legend className="text-sm font-medium text-ink-11">{legend}</legend>

      <div className="flex items-center gap-2">
        <Checkbox
          checked={parentState}
          onCheckedChange={(c) => onValueChange(c === true ? options.map((o) => o.value) : [])}
          aria-label={`Select all ${legend}`}
          id="grp-all"
        />
        <Label htmlFor="grp-all" className="text-sm text-ink-11">Select all</Label>
      </div>

      <div className="ms-6 space-y-2 border-s border-ink-6 ps-4">
        {options.map((o) => (
          <div key={o.value} className="flex items-center gap-2">
            <Checkbox
              id={`grp-${o.value}`}
              checked={value.includes(o.value)}
              disabled={o.disabled}
              onCheckedChange={(c) =>
                onValueChange(c === true ? [...value, o.value] : value.filter((v) => v !== o.value))
              }
            />
            <Label htmlFor={`grp-${o.value}`} className="text-sm text-ink-11">{o.label}</Label>
          </div>
        ))}
      </div>
    </fieldset>
  );
}
```

The parent–child `indeterminate` relationship is the primary reason `indeterminate` exists in the
primitive at all; it is otherwise rare. A group is a form value like any other — nothing in it takes
effect until the surrounding form submits.

# Props / API

The primitive is a controlled Radix `Checkbox`; QAYD adds `size` and `invalid` and forwards the rest.

| Prop | Type | Default | Description |
|---|---|---|---|
| `checked` | `boolean \| 'indeterminate'` | — | Controlled state. `'indeterminate'` renders the dash glyph and sets `aria-checked="mixed"`; it is a display state, not a submitted value. |
| `onCheckedChange` | `(checked: boolean \| 'indeterminate') => void` | — | Fires the next state. A group derives its own parent/child logic from this callback. |
| `size` | `'sm' \| 'default' \| 'lg'` | `'default'` | 16 / 18 / 20px box, aligned to the shared control scale. |
| `disabled` | `boolean` | `false` | Non-interactive, `opacity-50`, skipped by tab order; pairs with a permission tooltip when disabled by RBAC. |
| `invalid` | `boolean` | `false` | Negative border + `aria-invalid`; usually driven by the `<FormControl>` wrapper, not set by hand. |
| `required` | `boolean` | `false` | For a must-accept checkbox (a terms box); the required rule lives in the Zod schema, the prop only mirrors it for the accessible name. |
| `id` | `string` | Radix-generated | Ties the box to its `<Label htmlFor>`; supplied by `<FormControl>` in a form. |
| `aria-label` | `string` | — | Required **only** when there is no visible label (a row-select column). |
| `name` / `value` | `string` | — | For a native-form fallback; in QAYD forms React Hook Form owns the value and these are usually unset. |

In a form the control takes **no** ARIA-error props of its own — `id`, `aria-invalid`, and
`aria-describedby` are forwarded onto it by the `<FormControl>` wrapper, exactly as
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) `# Anatomy & Variants`
describes for every primitive.

# States

Every checkbox renders the shared field states plus the indeterminate third state. State is expressed by
Radix's `data-state` attribute (`unchecked` / `checked` / `indeterminate`) plus the QAYD `disabled` /
`invalid` flags, never by a color alone — the presence or absence of the glyph is the redundant
non-color signal that satisfies [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) Principle 5.

| State | Box fill | Border | Glyph | Notes |
|---|---|---|---|---|
| Unchecked | `ink-1` | 1px `ink-7` | none | Resting default. |
| Unchecked · hover | `ink-2` | 1px `ink-8` | none | `motion.micro` (120ms) color transition. |
| Checked | `accent` | none (accent) | `Check` in `accent-on` | Glyph draws in on `motion.micro`; instant under reduced motion. |
| Indeterminate | `accent` | none (accent) | `Minus` in `accent-on` | `aria-checked="mixed"`; used by a partially-selected parent. |
| Focus (keyboard) | inherit | inherit | inherit | 2px `ring` (accent) + 2px offset, `focus-visible` only. |
| Disabled | `ink-1` / `accent` @ `opacity-50` | dimmed | dimmed | `cursor-not-allowed`; if RBAC-disabled, a tooltip names the permission. |
| Invalid | `ink-1` | 1px `negative` | none/glyph | `aria-invalid`; a must-accept box left unchecked on submit. Ring turns `negative` on focus. |

The check-in animation is a `motion.micro` (120ms) reveal of the glyph, matching the "checkbox check"
entry in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) `# Motion`. Under `prefers-reduced-motion: reduce`
the glyph appears instantly with no scale or draw — the *state change* is never removed, only its
animation ([`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md)).

# Tokens Used

Every value resolves to a token; the primitive contains no literal hex, rgb, or px a token already
covers. All color tokens carry their own light/dark values from
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) `# Light ↔ Dark Remap`, so the classes below are correct in
both themes with no conditional branch.

| Role | Token / utility | Light | Dark |
|---|---|---|---|
| Box fill, unchecked | `bg-ink-1` | `#FAFAF9` | `#14130F` |
| Box hover fill | `bg-ink-2` | `#F4F3F1` | `#1B1914` |
| Box border, default | `border-ink-7` | `#A9A49A` | `#58503A` |
| Box border, hover | `border-ink-8` | `#878174` | `#786F55` |
| Box fill, checked/indeterminate | `bg-accent` | `#9C7A34` | `#D9B96C` |
| Glyph (check / dash) | `text-accent-on` | `#15130E` | `#14130F` |
| Focus ring | `ring-ring` (= accent) | `#9C7A34` | `#D9B96C` |
| Invalid border + ring | `border-negative` / `ring-negative` | `#B4232E` | `#F26B74` |
| Label text | `text-ink-11` | `#2B2820` | `#E4DEC9` |
| Description text | `text-ink-9` | `#645F53` | `#A39878` |
| Disabled | `opacity-50` on the above | — | — |
| Corner radius | `rounded-sm` (`radius-sm` = 4px) | — | — |
| Check-in motion | `motion.micro` = 120ms | — | — |
| Group gap | `gap-2` (`--space-xs` = 8px) | — | — |

The `accent`/`accent-on` pairing is not incidental: a checked box is a filled `accent` surface, and
every filled `accent` surface pairs with the **near-black** `accent-on` glyph, never white — the
contrast rationale in [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) `# accent-on is near-black, never
white` (≈5.5:1 light, ≈9.6:1 dark). A checked box uses the accent because a selection is a
user-committed choice; this is one of the sanctioned accent uses, not a decorative highlight.

# Accessibility

The checkbox meets the WCAG 2.1 AA floor of [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) and
[`../../frontend/ACCESSIBILITY.md`](../../frontend/ACCESSIBILITY.md).

| Concern | Contract |
|---|---|
| Role | Radix renders `role="checkbox"`; the group is a `<fieldset>` with `<legend>`. |
| State | `aria-checked` is `true` / `false` / `mixed` (indeterminate) — the mixed value is what makes a select-all parent announce correctly. |
| Keyboard | `Tab` moves focus to the box; `Space` toggles it; the box is in the natural tab order (a group is a sequence of tab stops, **not** arrow-key roving — that is the [RadioGroup](./RADIO.md), which is single-select). |
| Label | Every box has an accessible name — a visible `<Label htmlFor>` or, when visually label-less, an `aria-label`. The clickable label area toggles the box. |
| Description | Optional helper text is tied via `aria-describedby`; an invalid message is likewise associated by the `<FormControl>` wrapper. |
| Focus | `focus-visible` ring only (keyboard), 2px `ring` + 2px offset, ≥3:1 against every surface per the non-text-contrast rule. |
| Color independence | Checked vs. unchecked is carried by the **glyph's presence**, not hue alone; invalid is carried by `aria-invalid` + message, not the border color alone. |
| Disabled by permission | A checkbox disabled by RBAC keeps a tooltip naming the required key (e.g. `Requires accounting.close.run`) rather than a bare grey box, per [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) `# WCAG AA Contrast`. |
| Target size | The hit area is ≥24×24px (≥44px coarse pointer) even when the mark is 16px. |

The distinction that matters for assistive tech: a checkbox group is a set of independent boolean tab
stops, so a screen-reader user hears each as "checkbox, checked/unchecked" and toggles with `Space`;
they do **not** cycle a group with arrows. If arrow-cycling single-select is what the interaction needs,
the control is a [radio group](./RADIO.md), not a set of checkboxes.

# Theming, Dark Mode & RTL

- **Tokens only.** Border, fill, glyph, focus ring, and invalid state each resolve to an
  `ink`/`accent`/`negative` variable; the primitive source contains no raw hex and no `dark:` override
  against a raw color. Dark mode is the token remap of [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) — the
  same `bg-accent` produces `#9C7A34` in light and `#D9B96C` in dark, and the `accent-on` glyph stays
  near-black in both, holding its ≥5.5:1 contrast.
- **Dark-mode calibration.** The checked box is a filled accent surface, so it lightens in dark mode
  (`accent` → `#D9B96C`) rather than glowing; the unchecked box border (`ink-7`) stays a hairline in
  both themes.
- **RTL.** The box sits on the inline-**start** of its label; layout uses logical properties
  (`ms-*`/`ps-*`, `gap-2`, `border-s`), so under `[dir="rtl"]` the box moves to the right of the label
  automatically with no separate stylesheet.
- **The glyph never mirrors.** A `Check` and a `Minus` are meaning glyphs, not direction glyphs — they
  are identical in LTR and RTL (only direction-encoding icons like a submit chevron flip, per
  [`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) `# Theming, Dark Mode &
  RTL`).
- **Labels are bilingual data or i18n keys**, never hardcoded strings — an Arabic label sets
  `dir="rtl" lang="ar"` on its own text so it shapes correctly regardless of the surrounding UI
  direction; the box itself is direction-neutral.

# Do / Don't

| Do | Don't |
|---|---|
| Use a checkbox for a value the form submits later | Use a checkbox for an immediate-effect setting — that is a [Switch](./SWITCH.md) |
| Fill a checked box with `accent` and draw the glyph in near-black `accent-on` | Draw a white check on the brass fill (fails AA as a mark) |
| Give every box an accessible name — visible label or `aria-label` | Ship a label-less row-select checkbox with no `aria-label` |
| Use `indeterminate` only for a partially-selected parent in a group | Submit `indeterminate` as if it were a third value |
| Let a group be a set of independent `Space`-toggled tab stops | Wire arrow-key roving onto a checkbox group (that is a radio group) |
| Carry checked-ness with the glyph's presence, not hue alone | Rely on the accent fill as the only signal a box is checked |
| Keep the hit target ≥24px even at the 16px `sm` size | Shrink the clickable area to the drawn 16px box |
| Reference tokens (`bg-accent`, `border-ink-7`) | Hardcode `#9C7A34` or a raw Tailwind palette value |
| Attach a permission tooltip to an RBAC-disabled box | Leave a grayed-out box with no explanation |
| Set the check-in to `motion.micro`, instant under reduced motion | Animate the check with a long or bouncy transition |

# Usage & Composition

- **In a form field.** The primitive drops into a `<FormControl>` so the `<Form>` wrapper owns its ARIA
  and React Hook Form owns its value — the control stays presentational:

  ```tsx
  <FormField control={form.control} name="acceptTerms" render={({ field, fieldState }) => (
    <FormItem className="flex items-center gap-2">
      <FormControl>
        <Checkbox checked={field.value} onCheckedChange={field.onChange}
                  invalid={!!fieldState.error} />
      </FormControl>
      <FormLabel className="!mt-0 text-sm text-ink-11">
        I have reviewed the closing entries.
      </FormLabel>
      <FormMessage />
    </FormItem>
  )} />
  ```

- **As a table's row-select column.** The `DataTable`
  ([`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md)) uses a label-less
  checkbox with an `aria-label` in its leading column, and a header checkbox that renders
  `indeterminate` when some — but not all — visible rows are selected. Selection is a client concern;
  the bulk action it enables (post, reconcile, export) is a server-guarded mutation.

- **As a permission / column-picker group.** A settings screen's permission matrix or an export dialog's
  column list is a `CheckboxGroup` with a select-all parent; the parent's `indeterminate` state is
  derived from the children, never persisted.

- **What it must not become.** A checkbox is never used to fire an effect on change (a setting that saves
  immediately) — that is a [Switch](./SWITCH.md). It is never used for a mutually-exclusive one-of-many
  choice — that is a [RadioGroup](./RADIO.md). And it never gates a destructive action on its own; a
  permanent delete pairs the checkbox with an `AlertDialog` confirmation, because a single stray click
  must not be able to destroy data.

For the field-wiring contract (label, description, error mapping, `<FormControl>` forwarding) see
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) and
[`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md); for the token catalogue see
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); for the
sibling controls this one is deliberately distinguished from, see [`RADIO.md`](./RADIO.md) and
[`SWITCH.md`](./SWITCH.md).

# End of Document
