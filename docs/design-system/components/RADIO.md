# Radio Group — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / RADIO
---

# Purpose

This document is the atomic specification for QAYD's **Radio Group** primitive — the control for a
**single, mutually-exclusive choice among a small set of visible options** (a report basis: cash vs.
accrual; an amount sign convention; a rounding rule). It is the design-system companion to the
application-facing input catalogue in
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md), which lists `RadioGroup`
among the platform's controls and names its role precisely: "a small, mutually-exclusive choice that
benefits from all options being visible." INPUTS.md owns how the control is *wired into a field*; this
document owns the *primitive itself* — its anatomy, its exact token bindings, its full state matrix, its
orientation options, and above all its **roving-focus keyboard model**, which is the single most
important thing that distinguishes it from a set of [checkboxes](./CHECKBOX.md).

The radio group is a re-skinned **Radix `RadioGroup`**. QAYD keeps Radix's accessibility contract (the
`role="radiogroup"` container, `role="radio"` items, `aria-checked`, and the roving `tabindex` that makes
the whole group one tab stop) untouched, and adds only its tokens, a `size` and `orientation` axis, and
an `invalid` state. Choosing between a radio group, a [`Select`](../../frontend/components/INPUTS.md),
and a [`SegmentedControl`](../../frontend/components/INPUTS.md) is a real decision the platform makes
consistently: a **radio group** when 2–5 options benefit from being fully visible with room for a
per-option description; a **`Select`** when the list is longer or space is tight; a **`SegmentedControl`**
when it is a compact, toolbar-scale toggle with no descriptions. All three are single-select — this one is
the "show me all my choices" form of it.

Every value below traces to [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); the primitive introduces no literal a token does not already
cover.

# Anatomy

A radio group is a **group container** wrapping two or more **radio items**, each item a **dot control**
(a circle with an inner filled disc when selected) plus a **label** and an optional **description**.

```
┌────────────────────────────────────────────────────┐
│  Reporting basis                        ← group label (legend) │
│                                                              │
│   ( )  Cash basis                       ← unselected item      │
│        Recognize on payment.            ← description (ink-9)   │
│                                                              │
│   (●)  Accrual basis                    ← selected item        │
│        Recognize when earned/incurred.                        │
└────────────────────────────────────────────────────┘
    │     │
    │     └─ label: text-sm ink-11 (+ optional ink-9 description)
    └─ dot: circle, radius-full, 1px ink-7; accent ring + accent inner disc when selected
```

- **Group container**: a Radix `RadioGroup.Root` rendered as (or inside) a `<fieldset>` with a
  `<legend>` carrying the group label; vertical stack by default, `--space-sm`/`--space-md` between
  items.
- **Dot**, unselected: `ink-1` fill, `1px ink-7` border, `radius-full`.
- **Dot**, selected: `1px accent` border with an inner filled disc in `accent` (the disc is what the
  Radix `Indicator` reveals). The selected dot is the classic ring-plus-center, not a filled solid — a
  filled solid reads as a checkbox.
- **Label**: `text-sm` in `ink-11`, inline-end of the dot with an `--space-xs` gap; clicking the label
  or its description selects the item.
- **Description**: optional `text-xs` in `ink-9`, associated via `aria-describedby` on the item — the
  main reason to choose a radio group over a compact `SegmentedControl`.
- **Hit target**: the whole label row is clickable and ≥24px tall (≥44px on coarse pointers), even
  though the dot itself is 16–20px.

# Variants

A single choice has no decorative variants; the axes are **size**, **orientation**, and **state**.

| Axis | Values | Notes |
|---|---|---|
| `size` | `sm` (16px) · `default` (18px) · `lg` (20px) | Dot diameter, aligned to the shared control scale. |
| `orientation` | `vertical` (default) · `horizontal` | Vertical for options with descriptions; horizontal only for 2–3 short, description-less options that fit one line. |
| State | unselected · selected · disabled (per item or whole group) · invalid | Full matrix in [`# States`](#states). |

## Size and item

```tsx
// components/ui/radio-group.tsx (canonical QAYD tokens)
'use client';
import * as RadioGroupPrimitive from '@radix-ui/react-radio-group';
import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const RadioGroup = RadioGroupPrimitive.Root; // renders role="radiogroup"

const radioItemVariants = cva(
  'aspect-square rounded-full border bg-ink-1 transition-colors ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ' +
    'disabled:cursor-not-allowed disabled:opacity-50 ' +
    'data-[state=checked]:border-accent',
  {
    variants: {
      size: { sm: 'size-4', default: 'size-[18px]', lg: 'size-5' },
      invalid: { true: 'border-negative focus-visible:ring-negative', false: 'border-ink-7' },
    },
    defaultVariants: { size: 'default', invalid: false },
  },
);

export function RadioItem({ value, size, invalid, className, ...props }: RadioItemProps) {
  return (
    <RadioGroupPrimitive.Item value={value} className={cn(radioItemVariants({ size, invalid }), className)} {...props}>
      <RadioGroupPrimitive.Indicator className="flex items-center justify-center">
        <span className="block size-2 rounded-full bg-accent" aria-hidden />
      </RadioGroupPrimitive.Indicator>
    </RadioGroupPrimitive.Item>
  );
}
```

## Orientation

```tsx
// A vertical group with descriptions (the default, preferred shape).
<RadioGroup value={basis} onValueChange={setBasis} aria-label="Reporting basis" className="space-y-3">
  {options.map((o) => (
    <div key={o.value} className="flex items-start gap-2">
      <RadioItem value={o.value} id={`basis-${o.value}`} disabled={o.disabled} className="mt-0.5" />
      <div className="grid gap-0.5">
        <Label htmlFor={`basis-${o.value}`} className="text-sm text-ink-11">{o.label}</Label>
        {o.description && <p className="text-xs text-ink-9">{o.description}</p>}
      </div>
    </div>
  ))}
</RadioGroup>

// A horizontal group — only for 2–3 short, description-less options.
<RadioGroup value={sign} onValueChange={setSign} aria-label="Sign convention"
            orientation="horizontal" className="flex gap-6">
  {/* … RadioItem + Label per option … */}
</RadioGroup>
```

`orientation="horizontal"` is passed to Radix (it swaps the arrow-key axis to Left/Right) *and*
reflected in layout; the two must always agree so the visual order matches the keyboard order.

# Props / API

The group is a controlled Radix `RadioGroup`; QAYD adds `size`, `orientation`, and `invalid`.

## Group props (`RadioGroup`)

| Prop | Type | Default | Description |
|---|---|---|---|
| `value` | `string` | — | Controlled selected value. A radio group has exactly one value (or none, if `defaultValue` is unset). |
| `onValueChange` | `(value: string) => void` | — | Fires the newly-selected value. |
| `orientation` | `'vertical' \| 'horizontal'` | `'vertical'` | Sets the arrow-key axis and must match the layout. |
| `disabled` | `boolean` | `false` | Disables the whole group; individual items also take their own `disabled`. |
| `invalid` | `boolean` | `false` | Applies the negative treatment to items and wires `aria-invalid`; usually from `<FormControl>`. |
| `required` | `boolean` | `false` | A must-choose group; the rule lives in the Zod schema, the prop mirrors it. |
| `name` | `string` | RHF-owned | Native-form fallback; in QAYD forms React Hook Form owns the value. |
| `aria-label` / `aria-labelledby` | `string` | — | The group's accessible name — a `<legend>` (via `aria-labelledby`) or an `aria-label`; a group is never nameless. |

## Item props (`RadioItem`)

| Prop | Type | Default | Description |
|---|---|---|---|
| `value` | `string` | — | The value this item selects; unique within the group. |
| `size` | `'sm' \| 'default' \| 'lg'` | inherits group | Dot diameter. |
| `disabled` | `boolean` | `false` | Disables a single option; it stays visible and is skipped by roving focus, with a permission tooltip when RBAC-gated. |
| `id` | `string` | Radix-generated | Ties the dot to its `<Label htmlFor>`. |

In a form the group takes no ARIA-error props of its own — `aria-invalid` and `aria-describedby` are
forwarded by the `<FormControl>` wrapper, exactly as
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) `# Anatomy & Variants`
describes.

# States

State is expressed by Radix's `data-state` (`checked` / `unchecked`) plus QAYD's `disabled` / `invalid`
flags. Selection is carried by the **inner disc's presence**, not hue alone, satisfying
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) Principle 5.

| State | Dot border | Inner disc | Notes |
|---|---|---|---|
| Unselected | 1px `ink-7` | none | Resting default. |
| Unselected · hover | 1px `ink-8` | none | `motion.micro` (120ms) color transition. |
| Selected | 1px `accent` | `accent` disc | Disc scales/fades in on `motion.micro`; instant under reduced motion. |
| Focus (keyboard) | inherit | inherit | 2px `ring` (accent) + 2px offset, `focus-visible` only; sits on the currently-roving item. |
| Disabled (item) | dimmed | dimmed | `opacity-50`, `cursor-not-allowed`; visible but skipped by arrows; permission tooltip if RBAC-gated. |
| Disabled (group) | dimmed | — | The whole group is non-interactive; the selected value still shows. |
| Invalid | 1px `negative` | — | `aria-invalid` on the group; a required group left unselected on submit. Ring turns `negative` on focus. |

The selected-disc reveal is a `motion.micro` (120ms) transition, consistent with the other small-control
state flips in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) `# Motion`; under `prefers-reduced-motion:
reduce` the disc appears instantly ([`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md)).

# Tokens Used

Every value resolves to a token; no literal hex, rgb, or px the token layer already covers appears in the
primitive. Color tokens carry their own light/dark values from
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md).

| Role | Token / utility | Light | Dark |
|---|---|---|---|
| Dot fill, unselected | `bg-ink-1` | `#FAFAF9` | `#14130F` |
| Dot border, default | `border-ink-7` | `#A9A49A` | `#58503A` |
| Dot border, hover | `border-ink-8` | `#878174` | `#786F55` |
| Dot border, selected | `border-accent` | `#9C7A34` | `#D9B96C` |
| Inner disc | `bg-accent` | `#9C7A34` | `#D9B96C` |
| Focus ring | `ring-ring` (= accent) | `#9C7A34` | `#D9B96C` |
| Invalid border + ring | `border-negative` / `ring-negative` | `#B4232E` | `#F26B74` |
| Label text | `text-ink-11` | `#2B2820` | `#E4DEC9` |
| Description text | `text-ink-9` | `#645F53` | `#A39878` |
| Disabled | `opacity-50` on the above | — | — |
| Dot shape | `rounded-full` (`radius-full`) | — | — |
| Select motion | `motion.micro` = 120ms | — | — |
| Item gap | `gap-2` (`--space-xs` = 8px) | — | — |

Unlike a checkbox, a selected radio uses the accent as a **ring plus inner disc on an `ink-1` field**,
not as a full fill — so the accent-on / contrast-of-a-fill rule does not apply here; the `accent` border
and disc each clear the 3:1 non-text contrast floor against the `ink-1` dot field in both themes
([`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) `# WCAG AA Contrast`). The accent's use here is a sanctioned
one: it marks the user's single committed choice.

# Accessibility

The radio group meets the WCAG 2.1 AA floor of [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) and
[`../../frontend/ACCESSIBILITY.md`](../../frontend/ACCESSIBILITY.md). Its keyboard model is the defining
difference from a checkbox set and is stated in full.

| Concern | Contract |
|---|---|
| Role | Radix renders `role="radiogroup"` on the container and `role="radio"` on each item; wrap in `<fieldset>` + `<legend>` for the group name. |
| State | Each item's `aria-checked` is `true` / `false`; exactly one is `true` at a time. |
| **Roving focus** | The **entire group is one tab stop.** `Tab` enters the group and lands on the selected item (or the first item if none is selected); `Tab` again leaves the group entirely. |
| **Arrow keys select** | `↓`/`→` moves to and **selects** the next item; `↑`/`←` the previous; selection wraps at the ends. In a radio group, moving focus *is* choosing — there is no separate "commit" step, which is why arrows both move and select. |
| Orientation | `↑`/`↓` for a vertical group, `←`/`→` for horizontal; Radix derives the axis from `orientation`, so it must match the layout. |
| Label | The group has a `<legend>`/`aria-label`; every item has a `<Label htmlFor>` and its optional description via `aria-describedby`. |
| Focus ring | `focus-visible` only, 2px `ring` + 2px offset on the roving item, ≥3:1 against every surface. |
| Color independence | Selected vs. unselected is carried by the inner disc's presence, not hue; invalid by `aria-invalid` + message, not border color alone. |
| Disabled item | Stays visible and announced, is skipped by arrow roving, and — when RBAC-gated — carries a tooltip naming the permission rather than a bare grey dot. |

The contrast with [`CHECKBOX.md`](./CHECKBOX.md) is the whole point: a **checkbox set** is N independent
tab stops toggled with `Space`; a **radio group** is one tab stop whose arrows both move and select. Use
a radio group only when the choices are genuinely mutually exclusive — if a user may pick more than one,
it is a checkbox group; if picking one is an immediate side-effecting action, it is likely a
[`SegmentedControl`](../../frontend/components/INPUTS.md) or a set of buttons.

# Theming, Dark Mode & RTL

- **Tokens only.** Border, disc, focus ring, and invalid state each resolve to an
  `ink`/`accent`/`negative` variable; the source has no raw hex and no `dark:` override against a raw
  color. Dark mode is the token remap of [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) — `border-accent`
  and the disc `bg-accent` both become `#D9B96C` in dark, keeping the ring/disc legible against the
  `ink-1` dot field.
- **Dark-mode calibration.** The dot field stays warm (`ink-1` = `#14130F` in dark), the accent lightens
  rather than saturates, and the hairline border keeps the same relative weight in both themes.
- **RTL.** The dot sits on the inline-**start** of its label; layout uses logical properties
  (`ms-*`/`ps-*`, `gap`, `space-*`), so under `[dir="rtl"]` the dot moves to the right of the label with
  no separate stylesheet. In a horizontal group the item order also follows reading direction.
- **Arrow keys follow direction.** Radix maps the horizontal-group arrows to the visual axis; under RTL
  a horizontal group's `→`/`←` still move to the visually-next/previous item, so the keyboard order
  matches what the user sees.
- **The dot never mirrors.** The circle and inner disc are direction-neutral; only direction-encoding
  icons elsewhere flip. Labels are bilingual data or i18n keys, an Arabic label setting `dir="rtl"
  lang="ar"` on its own text.

# Do / Don't

| Do | Don't |
|---|---|
| Use a radio group for a genuinely mutually-exclusive choice | Use radios when a user may select more than one — that is a [checkbox group](./CHECKBOX.md) |
| Keep 2–5 options fully visible with room for descriptions | Cram a long list into radios — use a [`Select`](../../frontend/components/INPUTS.md) |
| Make the whole group one tab stop with arrow-key roving | Wire each radio as its own `Tab` stop |
| Let arrows both move focus and select | Add a separate `Space`/`Enter` "commit" step after arrowing |
| Match `orientation` to the visual layout | Render a horizontal row but leave `orientation="vertical"` |
| Mark selection with the ring + inner disc | Fill the dot solid so it reads like a checkbox |
| Give the group a `<legend>`/`aria-label` and each item a label | Ship a nameless group or label-less items |
| Keep a disabled option visible with a permission tooltip | Remove an unavailable option so the user never learns why |
| Reference tokens (`bg-accent`, `border-ink-7`) | Hardcode `#9C7A34` or a raw Tailwind palette value |
| Set the disc reveal to `motion.micro`, instant under reduced motion | Bounce or spring the selection change |

# Usage & Composition

- **In a form field.** The group drops into a `<FormControl>` so the `<Form>` wrapper owns its ARIA and
  React Hook Form owns its single value:

  ```tsx
  <FormField control={form.control} name="reportingBasis" render={({ field, fieldState }) => (
    <FormItem>
      <FormLabel>Reporting basis</FormLabel>
      <FormControl>
        <RadioGroup value={field.value} onValueChange={field.onChange}
                    invalid={!!fieldState.error} className="space-y-3">
          <div className="flex items-start gap-2">
            <RadioItem value="cash" id="basis-cash" className="mt-0.5" />
            <div className="grid gap-0.5">
              <Label htmlFor="basis-cash" className="text-sm text-ink-11">Cash basis</Label>
              <p className="text-xs text-ink-9">Recognize on payment.</p>
            </div>
          </div>
          <div className="flex items-start gap-2">
            <RadioItem value="accrual" id="basis-accrual" className="mt-0.5" />
            <div className="grid gap-0.5">
              <Label htmlFor="basis-accrual" className="text-sm text-ink-11">Accrual basis</Label>
              <p className="text-xs text-ink-9">Recognize when earned or incurred.</p>
            </div>
          </div>
        </RadioGroup>
      </FormControl>
      <FormMessage />
    </FormItem>
  )} />
  ```

- **As a report/statement option.** A P&L or Trial Balance options panel uses a vertical radio group for
  basis (cash/accrual) or comparison mode, where each option earns a one-line description. The chosen
  value only re-parametrizes a server report request; the report figures themselves are the server's.

- **Radio group vs. its neighbors.** Choose a **radio group** for a small visible set with descriptions;
  a [`Select`](../../frontend/components/INPUTS.md) when the list is long or space is tight; a
  [`SegmentedControl`](../../frontend/components/INPUTS.md) for a compact toolbar toggle with no
  descriptions and a `layoutId` indicator. All three are single-select — the difference is density and
  discoverability, not semantics.

- **What it must not become.** A radio group is never a stand-in for a boolean (a single on/off is a
  [`Checkbox`](./CHECKBOX.md) or, if immediate-effect, a [`Switch`](./SWITCH.md)); a "Yes / No" pair of
  radios where a checkbox would do is a smell. And it never fires a side effect merely by arrowing over
  an option — selection updates the form value; any effect happens on submit.

For field wiring see [`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) and
[`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md); for tokens see
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); for the
sibling controls this one is distinguished from, see [`CHECKBOX.md`](./CHECKBOX.md) and
[`SWITCH.md`](./SWITCH.md).

# End of Document
