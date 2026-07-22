# Input — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / INPUT
---

# Purpose

This document is the atomic, token-level specification of QAYD's text `Input` primitive — the single
`cva`-driven control surface every typed field is built on. It defines the primitive in isolation: its
anatomy, its variants and sizes, prefix/suffix affordances, every field state (default, focus, filled,
invalid, read-only, disabled, loading), the exact tokens each state references, and its prop/API surface.
It is implementation-first and field-agnostic — it says nothing about which fact a user is typing, only
how the surface looks, behaves, and is themed.

It is the design-system half of a two-document pair. The application-integration half —
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) — owns the wider control
catalogue built on top of this surface (`NumberInput`, `DateInput`, `Combobox`, `FileDropzone`,
`TagsInput`, and more) and the finance rules that govern them; the React Hook Form + Zod wiring lives in
[`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md). Read this document for the
primitive surface and the `CurrencyInput` composition it directly parents; read those for the full
control set and the form system. Where the application docs use the pre-canonical draft token names
(`ink-150`, `accent-600`, `danger`), this document uses the **canonical** `ink-1…12` / brass / `negative`
tokens from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md),
which govern (see [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md)).

Two non-negotiables run through the whole surface. First, **a control formats and normalizes for entry;
it never computes a fact** — `CurrencyInput` parses and re-formats what the user types, but the amount
that posts is validated and totaled by the server, never summed on the client. Second, **numerals and
currency codes never mirror** — every numeric and code input keeps its value `dir="ltr"` with Western
Arabic (`latn`) numerals even inside an Arabic RTL form, matching the `AmountCell` rule in
[`../COLOR_SYSTEM.md → The debit/credit rule`](../COLOR_SYSTEM.md).

# Anatomy

An `Input` is one control surface with an optional leading affordance (icon/prefix), the editable field
itself, and an optional trailing affordance (unit tag, clear button, currency tag). In a form it is
wrapped by the `<FormControl>` that owns its `id`/`aria-invalid`/`aria-describedby` — the input itself
stays presentational.

```
┌─────────────────────────────────────────────────────┐
│ [prefix]  editable text / placeholder     [suffix]  │   flex w-full rounded-md border
└─────────────────────────────────────────────────────┘
   ink-9        ink-12 text / ink-8 placeholder   ink-9
```

- **Surface.** `flex w-full rounded-md border bg-ink-1 text-ink-12 placeholder:text-ink-8`. The radius is
  `--radius-md` (6px). The fill is the canvas token (`ink-1`); the border does the structural work.
- **Border.** `border-ink-7` at rest — the input-border band from
  [`../COLOR_SYSTEM.md → Usage bands`](../COLOR_SYSTEM.md). It thickens in weight only through color, never
  a heavier stroke, on focus/invalid.
- **Text.** `text-md` (16px) Inter for the entered value; placeholder is `ink-8` and is never the only
  label. Numeric variants switch to right-aligned tabular figures (see Variants).
- **Prefix / suffix.** A leading icon or a trailing unit/tag sits at `inset-inline-start-3` /
  `inset-inline-end-3`, and the field reserves padding (`ps-9` / `pe-14`) so the text never collides with
  it. Affordances are `ink-9` and `pointer-events-none` unless interactive (a clear button, a calendar
  trigger).
- **Focus ring.** A 2px `accent` ring with a 2px offset, `focus-visible` only — part of the base class,
  never stripped.
- **Height.** `sm` 32px / `default` 36px / `lg` 40px, aligned to the `Button` scale so a field and its
  adjacent button line up on a toolbar without per-control tuning.

# Variants

The `Input` primitive varies along two axes — a content `variant` (text vs. numeric alignment) and an
`invalid` flag — plus the shared `size`. Higher-level control *types* (`NumberInput`, `DateInput`,
`Combobox`, …) are compositions specified in the application doc, not variants of this surface.

| Axis | Value | Effect |
|---|---|---|
| `variant` | `text` | `text-start`, LTR-in-LTR; the default typed field |
| `variant` | `numeric` | `text-end font-mono tabular-nums`, forced `dir="ltr"` + `inputMode="decimal"` — numerals never mirror |
| `invalid` | `false` | `border-ink-7` |
| `invalid` | `true` | `border-negative focus-visible:ring-negative` + `aria-invalid` |
| `size` | `sm \| default \| lg` | `h-8 px-2.5` / `h-9 px-3` / `h-10 px-3.5` |

## `cva` declaration (canonical tokens)

```tsx
// components/ui/input.variants.ts
import { cva, type VariantProps } from 'class-variance-authority';

export const inputVariants = cva(
  'flex w-full rounded-md border bg-ink-1 text-md text-ink-12 placeholder:text-ink-8 ' +
    'transition-colors motion-reduce:transition-none ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 ' +
    'focus-visible:ring-offset-ink-1 ' +
    'disabled:cursor-not-allowed disabled:opacity-50 ' +
    'read-only:bg-ink-3 read-only:text-ink-10',
  {
    variants: {
      variant: {
        text: 'text-start',
        numeric: 'text-end font-mono tabular-nums', // right-aligned tabular figures, LTR
      },
      size: { sm: 'h-8 px-2.5', default: 'h-9 px-3', lg: 'h-10 px-3.5' },
      invalid: {
        true: 'border-negative focus-visible:ring-negative',
        false: 'border-ink-7',
      },
    },
    defaultVariants: { variant: 'text', size: 'default', invalid: false },
  },
);

export type InputVariantProps = VariantProps<typeof inputVariants>;
```

## Implementation

```tsx
// components/ui/input.tsx
import * as React from 'react';
import { cn } from '@/lib/utils';
import { inputVariants, type InputVariantProps } from './input.variants';

export interface InputProps
  extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'size'>,
    InputVariantProps {}

export const Input = React.forwardRef<HTMLInputElement, InputProps>(function Input(
  { className, variant, size, invalid, ...props },
  ref,
) {
  return (
    <input
      ref={ref}
      dir={variant === 'numeric' ? 'ltr' : undefined} // numerals never mirror
      inputMode={variant === 'numeric' ? 'decimal' : undefined}
      aria-invalid={invalid || undefined}
      className={cn(inputVariants({ variant, size, invalid }), className)}
      {...props}
    />
  );
});
```

Note that `variant="numeric"` sets `dir="ltr"` and `inputMode="decimal"` on the element itself, so a
numeric value keeps Western reading order even inside an RTL form and a mobile keyboard opens to the
number pad. The `invalid` prop is almost always driven from a `<FormControl>`'s `fieldState.error`, not
set by hand ([`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md)).

## Prefix / suffix composition

The primitive carries no built-in slot markup; a prefix/suffix is composed by wrapping the `Input` in a
`relative` container and absolutely positioning the affordance with a logical inset, while reserving field
padding so text never overlaps it:

```tsx
// A search field: leading icon + trailing clear button.
<div className="relative">
  <Search className="pointer-events-none absolute inset-inline-start-3 top-1/2 -translate-y-1/2 h-4 w-4 text-ink-9" aria-hidden />
  <Input className="ps-9 pe-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('search.placeholder')} />
  {q && (
    <button type="button" aria-label={t('common.clear')}
            className="absolute inset-inline-end-2 top-1/2 -translate-y-1/2 text-ink-9 hover:text-ink-12">
      <X className="h-4 w-4" aria-hidden />
    </button>
  )}
</div>
```

## CurrencyInput note

`CurrencyInput` is the primitive's most important composition and the reason the `numeric` variant
exists. It is an `Input variant="numeric"` plus a normalizer and a trailing `CurrencyTag`:

- **KWD is the default, three decimals (fils).** Display precision is per ISO 4217 —
  KWD/BHD/OMR = 3 decimals, JPY/KRW = 0, else 2. Display precision only; the server owns the authoritative
  `NUMERIC(19,4)` storage precision.
- **`latn` numerals in AR.** The normalizer converts Eastern-Arabic digits (`٠–٩`) to Western `latn`
  before validation, so a user pasting `١٬٢٠٤٫٥` and a user typing `1,204.5` both resolve to the canonical
  `"1204.500"`. The displayed and canonical values are deliberately distinct — the field shows grouped
  `1,204.500` on blur, the raw `1204.500` on focus for frictionless editing, and the form holds the plain
  decimal string.
- **Trailing tag.** The `CurrencyTag` sits at `inset-inline-end-2` (`pe-14` reserved), rendered `dir="ltr"`
  so `KWD` never mirrors. Accounting negatives display a leading minus in the editable field (a
  *rendered statement* uses parentheses via `AmountCell`, not the input).

```tsx
// components/ui/currency-input.tsx (surface shape — normalizer detail in the application doc)
export function CurrencyInput({ currencyCode, value, onValueChange, invalid, size, readOnly }: CurrencyInputProps) {
  const decimals = DISPLAY_DECIMALS[currencyCode] ?? 2; // KWD/BHD/OMR = 3
  const [focused, setFocused] = useState(false);
  const shown = focused ? value : group(value, decimals);
  return (
    <div className="relative">
      <Input
        variant="numeric" size={size} readOnly={readOnly} invalid={invalid} value={shown}
        onFocus={() => setFocused(true)} onBlur={() => setFocused(false)}
        onChange={(e) => onValueChange(normalize(e.target.value, decimals))}
        className="pe-14"
      />
      <span className="pointer-events-none absolute inset-inline-end-2 top-1/2 -translate-y-1/2">
        <CurrencyTag code={currencyCode} emphasis="muted" />
      </span>
    </div>
  );
}
```

The full normalizer, the accounting-negative rule, and the zero-vs-empty distinction are specified in
[`../../frontend/components/INPUTS.md → Numeric`](../../frontend/components/INPUTS.md).

# Props / API

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `variant` | `'text' \| 'numeric'` | no | `'text'` | `numeric` right-aligns tabular figures and forces `dir="ltr"` + `inputMode="decimal"`. |
| `size` | `'sm' \| 'default' \| 'lg'` | no | `'default'` | 32 / 36 / 40px height, aligned to the `Button` scale. |
| `invalid` | `boolean` | no | `false` | `negative` border + `aria-invalid`; usually driven by the `<FormControl>` wrapper, not set by hand. |
| `disabled` | `boolean` | no | `false` | Non-interactive, dimmed, skipped by tab order. Use for *state* blocking, not RBAC. |
| `readOnly` | `boolean` | no | `false` | Reachable, copyable, dimmed `ink-3` fill — for server-computed values (a `journal_number`, a posted line). |
| `value` / `onChange` | `string` / handler | — | — | The primitive is used controlled; higher-level controls expose `onValueChange` returning the canonical (normalized) value. |
| `dir` | `'ltr' \| 'rtl'` | no | inherited | Follows the *content's* language for data fields (`dir="rtl" lang="ar"` on an Arabic name input), not the page. |
| `className` | `string` | no | — | Layout only (`ps-9`, `pe-14`); never a token-color override. |
| …native props | `React.InputHTMLAttributes` (minus `size`) | — | — | `placeholder`, `autoComplete`, `inputMode`, `aria-*` pass through. |

# States

The `Input` renders one of seven field states; the `<FormControl>` wrapper makes them uniform so no two
forms drift.

| State | Trigger | Rendering |
|---|---|---|
| **Default** | Resting, empty | `border-ink-7`, `text-ink-12`, `ink-8` placeholder. |
| **Focus** | Keyboard/pointer focus | `focus-visible:ring-2 ring-accent ring-offset-2` — the ring is keyboard-visible; the border color is unchanged. |
| **Filled** | A value present | Same as default with a value — a filled field is not "done," only non-empty; no special styling. |
| **Invalid** | `invalid` / a resolver or server `422` | `border-negative`, `focus-visible:ring-negative`, `aria-invalid="true"`, and the `<FormMessage>` visible below. |
| **Read-only** | `readOnly` | `bg-ink-3`, `text-ink-10`, still selectable and announced — for server-computed values; **not** `disabled` (which would leave the tab order). |
| **Disabled** | `disabled` from business/form state | `opacity-50`, `cursor-not-allowed`, removed from tab order; reason already visible adjacent. |
| **Loading** | async-populated field | The field is replaced by a `Skeleton` at the control's height while an edit record loads or an AI-drafted value streams in. |

Numeric compositions add a **focused-editing** state (grouping separators drop to the raw canonical value
on focus, restored on blur) and a **locked-value** state (a stored value no longer in an option set renders
read-only and visibly flagged rather than showing a blank field that looks like data loss) — both detailed
in the application doc.

# Tokens Used

| Concern | Token(s) | Notes |
|---|---|---|
| Surface fill | `ink-1` | `bg-ink-1`; canvas token |
| Read-only fill | `ink-3` | `read-only:bg-ink-3` |
| Text | `ink-12` | Entered value |
| Read-only text | `ink-10` | Strong secondary |
| Placeholder | `ink-8` | Placeholder/disabled band; never the only label |
| Rest border | `ink-7` | Input-border band |
| Invalid border/ring | `negative` (`#B4232E` / `#F26B74`) | `border-negative` + `focus-visible:ring-negative` |
| Prefix/suffix icon | `ink-9` | Default icon band |
| Focus ring | `accent` + `ink-1` offset | `focus-visible:ring-2` |
| Radius | `radius-md` (6px) | `rounded-md` |
| Numeric figures | JetBrains Mono / `tabular-nums` | `font-mono tabular-nums`, `dir="ltr"` |
| Transition | `motion.fast` 160ms | `transition-colors`, `motion-reduce:transition-none` |

`invalid` on `border-negative` clears AA as a non-text state indicator in both themes, and it is never the
only signal — the `<FormMessage>` text carries the error too, per
[`../COLOR_SYSTEM.md → Color never carries meaning alone`](../COLOR_SYSTEM.md).

# Accessibility

- **Label, never placeholder-only.** The programmatic label comes from `<FormLabel>` → `htmlFor` → the
  generated field `id`; the placeholder vanishes on input and is invisible to some AT, so it is never the
  sole label.
- **Invalid is wired, not just colored.** `aria-invalid="true"` plus the `<FormMessage>` (`role="alert"`)
  linked through `aria-describedby` — the `negative` border is redundant with real text.
- **Read-only stays reachable.** A `readOnly` field is focusable, copyable, and announced; a `disabled`
  field is not. A posted entry's amounts are `readOnly`, not `disabled`, so a screen-reader user can still
  read them.
- **Numeric reading order is preserved.** `dir="ltr"` on a numeric input does not disturb Arabic reading
  order for surrounding prose; the `+`/`−`/`%` and the currency tag are real text/inline elements
  announced in place, not icons.
- **Focus-visible only.** The ring appears on keyboard focus, not a mouse click, via `focus-visible`.
- **Autofill safety.** Finance-sensitive fields set `autoComplete="off"` so a browser does not paste a
  saved card or address value into an amount or account field; genuine contact fields use the correct
  `autoComplete` token.
- **Contrast, both themes.** `ink-12` on `ink-1` ≈ 17.8:1 (light) / 16.9:1 (dark); `ink-8` placeholder
  clears the placeholder threshold; the `accent` ring and `negative` border both meet the ≥3:1 non-text
  target in light and dark ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).

# Theming, Dark Mode & RTL

**Theming.** Every border, fill, text, placeholder, focus ring, and invalid color resolves to an
`ink`/`accent`/`negative` CSS variable — no input file contains a raw hex or a `dark:` variant against a
raw color ([`../DESIGN_TOKENS.md → Implementation`](../DESIGN_TOKENS.md)).

**Dark mode.** A token remap only: `ink-7` border, `accent` ring, and `negative` invalid border all
resolve to their `.dark` values and keep their AA contrast — the invalid border stays legible against the
dark canvas without a `dark:` override. Elevation is not used on an input (border-first), so there is no
shadow to re-tune.

**RTL.**

- **Logical properties for layout.** Leading/trailing affordances use `inset-inline-start/end` and
  `ps-*`/`pe-*`; a currency input's trailing tag sits at `inset-inline-end-2` so it moves to the left edge
  under RTL automatically. Physical `left`/`right`/`ml`/`mr` are banned in control source.
- **Contents that never mirror.** Numeric values, currency codes, and dates render `dir="ltr"` with
  `latn` numerals even inside an RTL form — a Kuwaiti amount reads `1,204.500 KWD`, never digit-reversed
  ([`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md), debit/credit rule).
- **Direction follows content for text fields.** An Arabic name input sets `dir="rtl" lang="ar"` so the
  caret starts at the right and letters shape and connect correctly, while an English input in the same
  Arabic-UI form keeps `dir="ltr"` — direction follows the content's language, not the page.
- **Direction-encoding icons flip; meaning icons don't.** A `Combobox`/`Select` chevron and a calendar
  next/prev use `rtl:rotate-180`; a search magnifier, a checkmark, and the currency tag never flip.

# Do / Don't

| Do | Don't |
|---|---|
| Reference `bg-ink-1`, `border-ink-7`, `text-ink-12` — tokens only | Write `border-[#A9A49A]` or `border-neutral-300` in input source |
| Drive `invalid` from `<FormControl>`'s `fieldState.error` | Hand-set `aria-invalid` and a red border independently |
| Keep numeric values `dir="ltr"` with `latn` digits in RTL | Let a KWD amount digit-reverse inside an Arabic form |
| Use `readOnly` (`bg-ink-3`) for server-computed values | Use `disabled` for a value the user must still read/copy |
| Pair the `negative` invalid border with `<FormMessage>` text | Rely on the red border alone to signal an error |
| Set `dir="rtl" lang="ar"` on an Arabic content field | Let an Arabic name input inherit the page direction and mis-cursor |
| Reserve `ps-*`/`pe-*` padding for prefix/suffix affordances | Let placeholder text collide with a leading icon |
| Set `autoComplete="off"` on amount/account fields | Let a saved card value autofill an amount field |
| Let dark mode be the token remap | Add a `dark:border-*` variant to an input |

# Usage & Composition

At the primitive level, an input is composed inside a `<FormControl>` so the `<Form>` wrapper owns its
ARIA and RHF owns its value — the control stays presentational:

```tsx
<FormField control={form.control} name="reference" render={({ field, fieldState }) => (
  <FormItem>
    <FormLabel>{t('journalEntries.reference')}</FormLabel>
    <FormControl>
      <Input {...field} invalid={!!fieldState.error} autoComplete="off" />
    </FormControl>
    <FormMessage />
  </FormItem>
)} />

// A bilingual name pair — Arabic input carries explicit direction.
<Input {...field} dir="rtl" lang="ar" />

// The numeric composition, in a form.
<FormField control={form.control} name="amount" render={({ field, fieldState }) => (
  <FormItem>
    <FormLabel>{t('amount')}</FormLabel>
    <FormControl>
      <CurrencyInput currencyCode={form.watch('currency_code')} value={field.value}
                     onValueChange={field.onChange} invalid={!!fieldState.error} />
    </FormControl>
    <FormMessage />
  </FormItem>
)} />
```

**Composition contract.** The `invalid` prop comes from the wrapper; the label, description, and message
all come from the wrapper — the input never carries its own ARIA-error props. Higher-level control types
(`NumberInput`, `PercentInput`, `DateInput`, `Combobox`, `MultiSelect`, `FileDropzone`, `TagsInput`) are
compositions over this surface, each specified in
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md); the RHF/Zod submission
system, `422`-to-field mapping, and the dirty guard are in
[`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md). The multiline sibling is
[`./TEXTAREA.md`](./TEXTAREA.md); the searchable dropdown sibling is [`./SELECT.md`](./SELECT.md).

# End of Document
