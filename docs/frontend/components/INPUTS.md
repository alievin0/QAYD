# Inputs — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / INPUTS
---

# Purpose

This document is the catalogue of QAYD's input primitives — the atomic controls a user types, picks,
toggles, or drops a file into. Where [`FORMS.md`](./FORMS.md) owns the *system* that assembles fields
into a submittable form (React Hook Form + Zod, the `<Form>` wrapper set, submission and error
mapping), this document owns the *controls themselves*: text, textarea, number, `CurrencyInput`,
percentage, date and date-range, select/combobox, multi-select, checkbox, radio group, switch,
segmented control, file upload/dropzone, tags input, and the inline search input. Each is a
re-skinned shadcn/ui + Radix primitive (or a thin QAYD composition of them) that keeps its upstream
accessibility contract and adds only QAYD's tokens, a small set of finance-specific props, and the
bilingual/RTL/numeral rules the platform mandates everywhere.

These primitives are consumed two ways: inside a `<FormControl>` in a QAYD form (the common case,
where RHF owns their value and the `<Form>` wrapper owns their ARIA), or standalone as controlled
components (a toolbar's density toggle, a filter bar's date-range picker). Every primitive below is a
**controlled** component with a `value`/`onValueChange` (or `checked`/`onCheckedChange`) pair and
takes no ARIA-error props of its own when it sits in a `<FormControl>` — the wrapper forwards `id`,
`aria-invalid`, and `aria-describedby` onto it (see [`FORMS.md`](./FORMS.md) `# Anatomy & Variants`).

Two non-negotiables run through the whole catalogue. First, **a control formats and normalizes for
display and entry; it never computes a fact.** `CurrencyInput` parses and re-formats what the user
types, but the amount that posts is validated and totaled by Laravel, never summed on the client for
submission (see [`../README.md`](../README.md) `# Overview` and
[`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Finance Components`). Second, **numerals and
currency codes never mirror.** Every numeric, currency, and code input keeps its value `dir="ltr"`
with Western Arabic (`latn`) numerals even inside an Arabic RTL form, matching the `AmountCell` and
`CurrencyTag` rule in [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Theming & RTL`.

This document assumes [`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) (tokens, motion),
[`FORMS.md`](./FORMS.md) (how a control is wired into a field), and [`SEARCH_BAR.md`](./SEARCH_BAR.md)
(the command palette and page-scoped search, which the plain `SearchInput` here is the small sibling
of) are open alongside it.

# Anatomy & Variants

Every input shares one anatomy: an optional leading affordance (icon/prefix), the control surface, an
optional trailing affordance (unit, clear button, chevron), and — when in a form — the label,
description, and message supplied by the `<Form>` wrapper around it. The shared control surface is a
6px-radius (`radius-md`) box, `ink-150` border, `ink-0`/surface fill, `ink-950` text, `ink-500`
placeholder, with the standard 2px `accent-600` focus-visible ring.

The catalogue's variants are the control *types* themselves. They group into five families:

| Family | Primitives | Built on |
|---|---|---|
| Text entry | `Input` (text/email/url/tel/password), `Textarea`, `SearchInput` | native `input`/`textarea` |
| Numeric entry | `NumberInput`, `CurrencyInput`, `PercentInput` | native `input[inputmode=decimal]` + a normalizer |
| Date entry | `DateInput`, `DateRangePicker` | Radix `Popover` + a calendar |
| Choice | `Select`, `Combobox`, `MultiSelect`, `RadioGroup`, `SegmentedControl` | Radix `Select`/`Command`/`RadioGroup`/`ToggleGroup` |
| Boolean & bulk | `Checkbox`, `Switch`, `FileDropzone`, `TagsInput` | Radix `Checkbox`/`Switch` + composed |

Sizes are uniform across the catalogue via a shared `size` axis (`sm` = 32px / `default` = 36px /
`lg` = 40px control height) matching the `Button` scale in
[`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md), so a control and its adjacent button line up on
a toolbar without per-control tuning.

## Text — `Input` and `Textarea`

```tsx
// components/ui/input.tsx (excerpt — QAYD adds `variant` and `invalid`)
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const inputVariants = cva(
  'flex w-full rounded-md border bg-surface text-body text-ink-950 placeholder:text-ink-500 ' +
  'transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 ' +
  'focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ' +
  'read-only:bg-ink-100 read-only:text-ink-700',
  {
    variants: {
      variant: {
        text: 'text-start',
        numeric: 'text-end font-mono tabular-nums',   // right-aligned tabular figures, LTR
      },
      size: { sm: 'h-8 px-2.5', default: 'h-9 px-3', lg: 'h-10 px-3.5' },
      invalid: { true: 'border-danger focus-visible:ring-danger', false: 'border-ink-150' },
    },
    defaultVariants: { variant: 'text', size: 'default', invalid: false },
  },
);

export interface InputProps
  extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'size'>,
    VariantProps<typeof inputVariants> {}

export const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ className, variant, size, invalid, ...props }, ref) => (
    <input
      ref={ref}
      dir={variant === 'numeric' ? 'ltr' : undefined}   // numerals never mirror
      inputMode={variant === 'numeric' ? 'decimal' : undefined}
      aria-invalid={invalid || undefined}
      className={cn(inputVariants({ variant, size, invalid }), className)}
      {...props}
    />
  ),
);
Input.displayName = 'Input';
```

`Textarea` is the same surface with `min-h` and vertical resize; it inherits `invalid`/`size` and is
used for memos, rejection reasons, notes. A bilingual text field renders two inputs (`name_en`,
`name_ar`), the Arabic one carrying explicit `dir="rtl" lang="ar"` so it shapes and cursors correctly
regardless of UI direction (see [`FORMS.md`](./FORMS.md) `# Theming, Dark Mode & RTL`).

## Numeric — `NumberInput`, `CurrencyInput`, `PercentInput`

All three share a normalizer that (a) forces `latn` digits on the *displayed* value, (b) strips
grouping separators before validation, and (c) keeps the canonical machine value a plain decimal
string the schema's regex and the API expect. The displayed and canonical values are deliberately
distinct — the user sees `1,204.500`, the form holds `"1204.500"`.

```tsx
// components/ui/currency-input.tsx
'use client';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { CurrencyTag } from '@/components/accounting/currency-tag';
import { cn } from '@/lib/utils';

const DISPLAY_DECIMALS: Record<string, number> = { KWD: 3, BHD: 3, OMR: 3, JPY: 0, KRW: 0 }; // else 2

interface CurrencyInputProps {
  currencyCode: string;                 // ISO 4217; drives decimals and the trailing tag
  value: string;                        // canonical decimal string, e.g. "1204.500"
  onValueChange: (canonical: string) => void;
  allowNegative?: boolean;              // accounting-style negatives; default false
  readOnly?: boolean;
  invalid?: boolean;
  size?: 'sm' | 'default' | 'lg';
}

/** Strips grouping, converts Eastern-Arabic digits to latn, keeps at most `decimals` places. */
function normalize(raw: string, decimals: number, allowNegative: boolean): string {
  const latn = raw.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660));
  const cleaned = latn.replace(/[^\d.\-]/g, '');
  const negative = allowNegative && cleaned.trim().startsWith('-');
  const [int = '', frac = ''] = cleaned.replace(/-/g, '').split('.');
  const body = frac ? `${int}.${frac.slice(0, decimals)}` : int;
  return (negative ? '-' : '') + body;
}

function group(canonical: string, decimals: number): string {
  if (canonical === '' || canonical === '-') return canonical;
  const n = Number(canonical);
  if (Number.isNaN(n)) return canonical;
  return new Intl.NumberFormat('en', {
    minimumFractionDigits: 0, maximumFractionDigits: decimals, numberingSystem: 'latn',
  }).format(n);
}

export function CurrencyInput({
  currencyCode, value, onValueChange, allowNegative = false, readOnly, invalid, size,
}: CurrencyInputProps) {
  const decimals = DISPLAY_DECIMALS[currencyCode] ?? 2;
  const [focused, setFocused] = useState(false);
  // While focused, show the raw canonical value (easier to edit); on blur, show grouped.
  const shown = focused ? value : group(value, decimals);

  return (
    <div className="relative">
      <Input
        variant="numeric"
        size={size}
        readOnly={readOnly}
        invalid={invalid}
        value={shown}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        onChange={(e) => onValueChange(normalize(e.target.value, decimals, allowNegative))}
        className="pe-14"                // room for the trailing currency tag
        aria-label={undefined}           // label comes from <FormLabel> in a form
      />
      <span className="pointer-events-none absolute inset-inline-end-2 top-1/2 -translate-y-1/2">
        <CurrencyTag code={currencyCode} emphasis="muted" />
      </span>
    </div>
  );
}
```

- **Negative accounting style.** With `allowNegative`, a negative amount displays with a leading
  minus in raw/editable contexts; a *rendered statement* uses parentheses via `AmountCell`, not the
  input (the minus/parentheses split in [`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Data
  Density`). The input is an editable surface, so it uses the unambiguous minus.
- **Thousands separators** appear on blur, disappear on focus for frictionless editing; the canonical
  value never contains them.
- **KWD/BHD/OMR = 3 decimals**, JPY/KRW = 0, else 2 — display precision only; the submitted string
  keeps whatever precision the user entered up to the display cap, and the server owns the authoritative
  `NUMERIC(19,4)` storage precision ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge
  Cases`).

`PercentInput` is `NumberInput` with a trailing `%` affordance and a schema-side range rule
(`0–100`); it stores the raw number (`12.5`) the API expects, not a `0.125` fraction, unless the
resource's own contract says otherwise. `NumberInput` is the plain quantity case (an inventory count,
a line's units) — `latn`, right-aligned, no currency tag.

## Date — `DateInput` and `DateRangePicker`

Both are a Radix `Popover` over a calendar; both store an ISO `YYYY-MM-DD` string (or a `{from,to}`
pair) as the canonical value and display it locale-aware. The `DateRangePicker` composes named
relative shortcuts (Today, This month, This fiscal period) — but the *fiscal-calendar-aware* selector
is `PeriodPicker` in [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md), which this defers to for
anything tied to `fiscal_periods`; `DateRangePicker` is the general free-range control used in filter
bars ([`FILTERS.md`](./FILTERS.md)).

```tsx
// components/ui/date-range-picker.tsx (shape)
'use client';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { CalendarDays } from 'lucide-react';
import { useFormatter } from 'next-intl';

export function DateRangePicker({ value, onChange }: {
  value: { from: string; to: string } | null;
  onChange: (range: { from: string; to: string } | null) => void;
}) {
  const f = useFormatter();
  const label = value
    ? `${f.dateTime(new Date(value.from), { dateStyle: 'medium' })} – ${f.dateTime(new Date(value.to), { dateStyle: 'medium' })}`
    : undefined;
  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" className="justify-start gap-2 font-normal">
          <CalendarDays className="h-4 w-4 text-ink-500" />
          {label ?? <span className="text-ink-500">Select a date range…</span>}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-auto p-0">
        <Calendar mode="range" selected={value ? { from: new Date(value.from), to: new Date(value.to) } : undefined}
                  onSelect={(r) => onChange(r?.from && r?.to
                    ? { from: toISO(r.from), to: toISO(r.to) } : null)} />
      </PopoverContent>
    </Popover>
  );
}
```

The calendar mirrors under RTL (week starts and navigation chevrons flip via logical layout and the
`rtl:rotate-180` chevron rule), but **displayed dates use `latn` numerals** in the Arabic locale, for
the same reason amounts do — a Gulf finance calendar shows Western digits.

`DateInput` is the single-date sibling, with a typed-entry fallback so a keyboard-first accountant can
enter an ISO date without opening the calendar:

```tsx
// components/ui/date-input.tsx (shape — typed fallback + calendar)
'use client';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Input } from '@/components/ui/input';
import { Calendar } from '@/components/ui/calendar';
import { CalendarDays } from 'lucide-react';

export function DateInput({ value, onValueChange, min, max, invalid, readOnly }: DateInputProps) {
  return (
    <div className="relative">
      <Input
        value={value}                                   // ISO YYYY-MM-DD, the canonical form value
        onChange={(e) => onValueChange(e.target.value)} // typed ISO entry
        placeholder="YYYY-MM-DD" inputMode="numeric" dir="ltr"
        invalid={invalid} readOnly={readOnly} className="pe-9"
      />
      <Popover>
        <PopoverTrigger asChild disabled={readOnly}>
          <button type="button" aria-label="Open calendar"
                  className="absolute inset-inline-end-2 top-1/2 -translate-y-1/2 text-ink-500">
            <CalendarDays className="h-4 w-4" aria-hidden />
          </button>
        </PopoverTrigger>
        <PopoverContent align="end" className="w-auto p-0">
          <Calendar mode="single" fromDate={min ? new Date(min) : undefined} toDate={max ? new Date(max) : undefined}
                    selected={value ? new Date(value) : undefined}
                    onSelect={(d) => d && onValueChange(toISO(d))} />
        </PopoverContent>
      </Popover>
    </div>
  );
}
```

## Choice — `Select`, `Combobox`, `MultiSelect`, `RadioGroup`, `SegmentedControl`

- **`Select`** (Radix `Select`) is for short, fixed enumerations (status, entry type, a currency
  short-list) — never for a long, searchable list.
- **`Combobox`** (Radix `Command` + `Popover`, debounced server query) is for long/searchable lists;
  `AccountPicker` in [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) is the canonical instance and
  the pattern every entity picker (customer, vendor, product) follows.
- **`MultiSelect`** is `Combobox` with a chip strip of selected values and a `Backspace`-to-remove
  behavior; used for a filter's facet set ([`FILTERS.md`](./FILTERS.md)).
- **`RadioGroup`** (Radix) is for a small, mutually-exclusive choice that benefits from all options
  being visible (a report basis: cash vs. accrual).
- **`SegmentedControl`** (Radix `ToggleGroup`, single-select) is the compact, toolbar-scale version of
  a radio group — a table's density toggle, a statement's period-comparison mode — with the active
  segment on the `accent`/selected tint and a Framer Motion `layoutId` indicator.

```tsx
// components/ui/segmented-control.tsx (shape)
'use client';
import * as ToggleGroup from '@radix-ui/react-toggle-group';
import { motion } from 'framer-motion';
import { cn } from '@/lib/utils';

export function SegmentedControl<T extends string>({ value, onChange, options }: {
  value: T; onChange: (v: T) => void; options: { value: T; label: string }[];
}) {
  return (
    <ToggleGroup.Root type="single" value={value}
      onValueChange={(v) => v && onChange(v as T)}
      className="inline-flex rounded-md border border-ink-150 bg-ink-100 p-0.5">
      {options.map((o) => (
        <ToggleGroup.Item key={o.value} value={o.value}
          className="relative rounded-[5px] px-3 py-1 text-sm text-ink-700 data-[state=on]:text-ink-950">
          {value === o.value && (
            <motion.span layoutId="segment" className="absolute inset-0 rounded-[5px] bg-surface shadow-sm"
              transition={{ duration: 0.2 }} />
          )}
          <span className="relative z-10">{o.label}</span>
        </ToggleGroup.Item>
      ))}
    </ToggleGroup.Root>
  );
}
```

## Boolean & bulk — `Checkbox`, `Switch`, `FileDropzone`, `TagsInput`

- **`Checkbox`** (Radix) — a discrete on/off inside a form or a table's row-select column; pairs with a
  `<FormLabel>` or a visible label, never label-less.
- **`Switch`** (Radix) — an *immediate-effect* toggle (a setting that saves on change via a Server
  Action), semantically distinct from a checkbox (a value submitted with the form). QAYD uses the
  distinction consistently: if flipping it should take effect now, it is a `Switch`; if it is a value
  the form will submit later, it is a `Checkbox`.
- **`FileDropzone`** — document ingest (a bank statement, a bill scan) for the Document Center /
  import flows; drag-drop plus click-to-browse, an accepted-types and max-size contract, per-file
  progress, and a signed-upload target (files go to Cloudflare R2 via a signed URL, never a static
  path — [`../README.md`](../README.md) `# Repository & Folder Structure`, `public/` rule).
- **`TagsInput`** — free-token entry (an entry's labels, a saved-filter's tags); `Enter`/comma commits
  a token, `Backspace` removes the last, each token a removable chip.

```tsx
// components/ui/file-dropzone.tsx (shape — the drop surface + a11y)
'use client';
import { useDropzone } from '@/hooks/use-dropzone';
import { UploadCloud } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useTranslations } from 'next-intl';

export function FileDropzone({ accept, maxSizeMb, onFiles, disabled }: FileDropzoneProps) {
  const t = useTranslations('upload');
  const { getRootProps, getInputProps, isDragActive, rejections } = useDropzone({ accept, maxSizeMb, onFiles, disabled });
  return (
    <div {...getRootProps()}
      className={cn(
        'flex flex-col items-center gap-2 rounded-lg border border-dashed border-ink-150 bg-ink-100/50 p-8 text-center',
        'focus-within:ring-2 focus-within:ring-accent-600 focus-within:ring-offset-2',
        isDragActive && 'border-accent-600 bg-accent-100',
        disabled && 'opacity-50',
      )}>
      <input {...getInputProps()} aria-label={t('chooseFiles')} />
      <UploadCloud className="h-6 w-6 text-ink-500" aria-hidden />
      <p className="text-body text-ink-700">{t('dropHint', { types: accept.join(', '), max: maxSizeMb })}</p>
      {rejections.length > 0 && (
        <p role="alert" className="text-caption text-danger">{t('rejected', { count: rejections.length })}</p>
      )}
    </div>
  );
}
```

`TagsInput` is the free-token control — an entry's labels, a saved filter's tags — committing on
`Enter`/comma and removing on `Backspace`, each token a removable chip:

```tsx
// components/ui/tags-input.tsx (shape)
'use client';
import { useState } from 'react';
import { X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

export function TagsInput({ tokens, onTokensChange, placeholder }: TagsInputProps) {
  const [draft, setDraft] = useState('');
  function commit() {
    const v = draft.trim();
    if (v && !tokens.includes(v)) onTokensChange([...tokens, v]);
    setDraft('');
  }
  return (
    <div className="flex flex-wrap items-center gap-1 rounded-md border border-ink-150 bg-surface p-1.5
                    focus-within:ring-2 focus-within:ring-accent-600 focus-within:ring-offset-2">
      {tokens.map((tk) => (
        <Badge key={tk} tone="neutral" className="gap-1 ps-2 pe-1">
          {tk}
          <button type="button" onClick={() => onTokensChange(tokens.filter((x) => x !== tk))}
                  aria-label={`Remove ${tk}`} className="rounded-full p-0.5 text-ink-500 hover:text-ink-950">
            <X className="h-3 w-3" aria-hidden />
          </button>
        </Badge>
      ))}
      <input
        value={draft} onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); commit(); }
          if (e.key === 'Backspace' && !draft && tokens.length) onTokensChange(tokens.slice(0, -1));
        }}
        placeholder={tokens.length ? undefined : placeholder}
        className="min-w-[6rem] flex-1 bg-transparent px-1 text-body outline-none placeholder:text-ink-500"
      />
    </div>
  );
}
```

## Search — `SearchInput`

The plain, page-scoped inline search box (a table's `q` filter, a picker's query). It is a thin
`Input` with a leading search icon and a trailing clear button, debounced by the consumer. It is
**not** the global command palette — that is [`SEARCH_BAR.md`](./SEARCH_BAR.md)'s `CommandPalette`,
and the two are deliberately different call shapes over the same backend search machinery.

## A field composed in a form

Every primitive above is consumed inside a `<FormControl>` so the `<Form>` wrapper owns its ARIA and
RHF owns its value — the control itself stays presentational:

```tsx
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

The `invalid` prop is driven from `fieldState.error`; the label, description, and message all come
from the wrapper. This is the shape every field in [`FORMS.md`](./FORMS.md) takes.

# Props / API

## Shared props (every primitive)

| Prop | Type | Default | Description |
|---|---|---|---|
| `value` / `checked` | control-specific | — | Controlled value; the primitive is always controlled. |
| `onValueChange` / `onCheckedChange` | callback | — | Fires the canonical (normalized) value, not the raw DOM event, for numeric/date/tags controls. |
| `size` | `'sm' \| 'default' \| 'lg'` | `'default'` | 32 / 36 / 40px control height, aligned to the `Button` scale. |
| `disabled` | `boolean` | `false` | Non-interactive, dimmed, skipped by tab order. |
| `readOnly` | `boolean` | `false` | (Text/numeric/date) reachable, copyable, dimmed fill — for server-computed values. |
| `invalid` | `boolean` | `false` | Danger border + `aria-invalid`; usually driven by the `<FormControl>` wrapper, not set by hand. |

## Numeric-family props

| Prop | Type | Applies to | Description |
|---|---|---|---|
| `currencyCode` | `string` | `CurrencyInput` | ISO 4217; drives display decimals (KWD/BHD/OMR = 3, JPY/KRW = 0, else 2) and the trailing `CurrencyTag`. |
| `allowNegative` | `boolean` | `CurrencyInput`, `NumberInput` | Accounting-style negatives with a leading minus in the editable field. |
| `min` / `max` / `step` | `number` | `NumberInput`, `PercentInput` | Fail-fast bounds mirrored in the schema; `PercentInput` defaults `0–100`. |

## Date-family props

| Prop | Type | Applies to | Description |
|---|---|---|---|
| `value` | `string \| { from: string; to: string } \| null` | `DateInput` / `DateRangePicker` | Canonical ISO string(s); never a localized display string. |
| `min` / `max` | `string` (ISO) | both | Selectable range bounds (e.g. no future date on a posting date). |
| `presets` | `{ label: string; range: {from,to} }[]` | `DateRangePicker` | Named relative shortcuts; for fiscal-calendar shortcuts use `PeriodPicker` instead. |

## Choice-family props

| Prop | Type | Applies to | Description |
|---|---|---|---|
| `options` | `{ value; label; disabled? }[]` | `Select`, `RadioGroup`, `SegmentedControl` | Short, in-memory enumerations. |
| `resource` / `queryFn` | `string` / fn | `Combobox`, `MultiSelect` | The debounced server search source for long lists (per `AccountPicker`). |
| `multiple` | (implicit) | `MultiSelect` | Renders a chip strip + `Backspace`-to-remove. |

## Bulk props

| Prop | Type | Applies to | Description |
|---|---|---|---|
| `accept` | `string[]` | `FileDropzone` | Allowed MIME/extensions (e.g. `['application/pdf','.csv']`). |
| `maxSizeMb` | `number` | `FileDropzone` | Per-file ceiling; a rejection surfaces an inline `role="alert"`, never a silent drop. |
| `onFiles` | `(files: File[]) => void` | `FileDropzone` | Accepted files; the consumer starts the signed upload. |
| `tokens` / `onTokensChange` | `string[]` / fn | `TagsInput` | Committed tokens; commit on `Enter`/comma, remove on `Backspace`. |

# States

Every primitive renders the seven field states from [`FORMS.md`](./FORMS.md) `# States` identically —
default, focus, filled, disabled, read-only, invalid, loading — plus a few control-specific ones:

| Control | Extra state | Visual |
|---|---|---|
| `CurrencyInput` / `NumberInput` | Focused-editing | Grouping separators drop to the raw canonical value for easy editing; restored on blur. |
| `Combobox` / `MultiSelect` | Searching | A `text-ink-500` "Searching…" row and an `aria-live="polite"` result-count announcement while the debounced query is in flight. |
| `Combobox` | Empty vs. no-results | A *search miss* ("No account found") is distinct from a *genuinely empty source* ("No postable accounts yet — set up your chart of accounts"), per [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases`. |
| `FileDropzone` | Drag-active / rejecting / uploading | Accent border + tint on drag-over; `role="alert"` inline message on a type/size rejection; a per-file progress bar during upload. |
| `Switch` | Pending | The immediate-effect toggle shows a brief inline spinner while its Server Action round-trips, and reverts visibly if the action fails. |
| `Select` / `Combobox` | Locked options | Options that exist but are unavailable (a locked fiscal period, a control account in a postable-only context) stay visible and `ink-500`, not removed — the user learns *why* it is unavailable. |

# Validation & Behavior

- **Fail fast, server decides.** Bounds and shapes (`min`/`max`, the amount regex, ISO date) live in
  the schema and reject early; the backend re-validates every one. A control never enforces a policy
  rule (a period being open, an account being postable in *this* company) it cannot authoritatively
  know — that comes back as a server `422` mapped onto the field
  ([`FORMS.md`](./FORMS.md) `# Validation & Behavior`).
- **Normalization happens before validation.** Numeric inputs convert Eastern-Arabic digits to `latn`,
  strip grouping separators, and clamp decimal places *before* the value reaches the schema, so the
  schema's regex only ever sees a canonical decimal string — a user pasting `١٬٢٠٤٫٥` or `1,204.5000`
  both resolve to the same `"1204.500"`.
- **Debounce is the caller's, not the control's default.** `Combobox`/`MultiSelect`/`SearchInput` fire
  their server query through a shared `useDebouncedValue(250ms)` so a fast typist does not spawn a
  request per keystroke; the debounce lives in the query hook, and the control's `onValueChange` is
  synchronous.
- **One-of exclusivity is a form concern, not a control concern.** The debit/credit "exactly one of"
  rule on a journal line is enforced by the form (clearing the sibling on entry, plus the schema
  `refine`), not baked into `CurrencyInput` — a currency input does not know it has a sibling
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# JournalEntryForm`).
- **File uploads validate type and size client-side to fail fast**, then the server re-checks the real
  bytes (a renamed `.exe` is caught server-side regardless of a permissive `accept`); a client-side
  accept is a courtesy, not a security boundary.
- **AI-populated inputs never auto-commit.** A field pre-filled by an AI suggestion (a proposed
  account in a picker, a drafted memo) renders editable with its `ConfidenceBadge`; the value posts
  only when the human submits ([`FORMS.md`](./FORMS.md) `# Edge Cases`).

# Accessibility

| Control | Keyboard | Screen reader | Notes |
|---|---|---|---|
| `Input` / `Textarea` | Native | Label via `<FormLabel>`; `aria-invalid` + `aria-describedby` from `<FormControl>` | Placeholder is never the only label. |
| `NumberInput` / `CurrencyInput` / `PercentInput` | Native + `inputMode="decimal"` | The value's `+`/`−`/`%` is real text, not an icon; the currency tag is announced inline | `dir="ltr"` does not disturb Arabic reading order for the value. |
| `DateInput` / `DateRangePicker` | `Popover` opens on `Enter`/`Space`; arrow keys move day/month in the calendar; `Esc` closes | The trigger announces the selected date(s); the calendar is a Radix-labelled grid | A typed-entry fallback lets a keyboard user enter an ISO date without the calendar. |
| `Select` | Radix `Select` roving focus, type-ahead | `aria-expanded`, option roles wired by Radix | Locked options stay in the list with a state hint, not removed. |
| `Combobox` / `MultiSelect` | Full combobox: `↓`/`↑`, `Enter`, `Esc` | `aria-expanded`, `aria-activedescendant`; a visually-hidden `aria-live` announces result-count changes | Selected chips in `MultiSelect` are individually focusable and removable. |
| `RadioGroup` | Arrow keys move selection; `Tab` enters/leaves the group | Radix group semantics; each option labelled | — |
| `SegmentedControl` | Arrow keys move; `Enter`/`Space` selects | `role="radiogroup"` semantics via `ToggleGroup` | The Framer Motion indicator respects `prefers-reduced-motion` (instant). |
| `Checkbox` / `Switch` | `Space` toggles | Radix state + label; `Switch` announces on/off, `Checkbox` announces checked/mixed | `Switch`'s pending state is announced (`aria-busy`) during its Server Action. |
| `FileDropzone` | The dropzone is a labelled `<input type=file>`; `Enter`/`Space` opens the browse dialog | `aria-label` on the input; rejections are `role="alert"` | Drag-drop is never the *only* way to add a file — click-to-browse always works. |
| `TagsInput` | `Enter`/comma commits, `Backspace` removes last, arrows move between chips | Each chip is a labelled removable element; the field announces token count | — |

All of the above satisfy the WCAG 2.1 AA floor of [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md); focus
rings are `focus-visible` (keyboard-only), and every disabled control that is disabled by permission
carries a tooltip naming the required key rather than an unexplained grey box
([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Color & Ink → Contrast targets`).

# Theming, Dark Mode & RTL

- **Tokens only.** Every control's border, fill, text, placeholder, focus ring, and invalid state
  resolves to an `ink`/`accent`/`danger` CSS variable; no control file contains a raw hex or a `dark:`
  variant against a raw color ([`../THEMING.md`](../THEMING.md)). Dark mode is a token remap that keeps
  the invalid `danger` border and the `accent-600` focus ring at their AA contrast in both themes.
- **Logical properties for layout.** Leading/trailing affordances use `inset-inline-start/end` and
  `ps-*`/`pe-*`; a currency input's trailing tag sits at `inset-inline-end-2` so it moves to the left
  edge under RTL automatically. Physical `left/right`/`ml/mr` are banned in control source.
- **Contents that never mirror.** Numeric values, currency codes, and dates render `dir="ltr"` with
  `latn` numerals even inside an RTL form — a Kuwaiti amount is `1,204.500 KWD`, a date is `01/07/2026`
  in Western digits, never digit-reversed ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)
  `# Theming & RTL`, rule 2).
- **Caret and shaping for bilingual text.** A text input carrying Arabic content sets `dir="rtl"
  lang="ar"` so the caret starts at the right and letters shape and connect correctly, while an
  English input in the same Arabic-UI form keeps `dir="ltr"` — direction follows the *content's*
  language for data fields, not the page.
- **Direction-encoding icons flip; meaning icons don't.** A `Combobox`/`Select` chevron and a
  calendar's next/prev use `rtl:rotate-180`; a search magnifier, a checkmark, an upload cloud, and the
  currency tag never flip.

# i18n & Formatting

- **Numbers and currency go through the primitives' own `latn` formatter**, never a generic
  `useFormatter().number()` for financial values — the fixed-numeral-system and per-currency-decimals
  rules must hold regardless of locale ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)
  `# Theming & RTL`, i18n mechanics). KWD is the default currency; 3-decimal display is its default.
- **Dates display via `useFormatter().dateTime`** with `numberingSystem: 'latn'` in the Arabic locale;
  the stored value is always ISO `YYYY-MM-DD`.
- **All visible strings are i18n keys.** Placeholders, dropzone hints, "Searching…", "No results",
  clear-button labels, and validation messages are keys present in both `en` and `ar` dictionaries;
  a key in one and missing in the other fails `npm run i18n:check`
  ([`../README.md`](../README.md) `# Getting Started → Quality gates`).
- **Currency and account labels are bilingual data**, not translations — `CurrencyTag`'s `name`
  variant and `AccountPicker`'s option label pick `name_en`/`name_ar` by `useLocale()` at render, so a
  user in an Arabic UI can still search by an English account name in a mixed-language box
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Theming & RTL → Bilingual data rendering`).

# Testing

- **Storybook per primitive.** Every control ships a `.stories.tsx` covering its state matrix
  (default, focus, filled, disabled, read-only, invalid, loading) rendered in all four LTR/RTL ×
  light/dark combinations via the global decorator, exactly as [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)
  `# Testing` describes; `axe-core` runs against each story in CI.
- **Normalizer unit tests (Vitest).** The most bug-prone code is the numeric normalizer — it is
  unit-tested against grouping separators, Eastern-Arabic digits, over-precise decimals, negatives, and
  paste payloads:

  ```tsx
  // components/ui/currency-input.test.ts
  import { normalize } from './currency-input';
  test('converts Eastern-Arabic digits and strips grouping to a canonical latn KWD string', () => {
    expect(normalize('١٬٢٠٤٫٥٠٠', 3, false)).toBe('1204.500');
  });
  test('clamps a 4-decimal paste to the currency display precision', () => {
    expect(normalize('1204.5678', 3, false)).toBe('1204.567');
  });
  test('honors accounting negatives only when allowed', () => {
    expect(normalize('-50', 2, true)).toBe('-50');
    expect(normalize('-50', 2, false)).toBe('50');
  });
  ```

- **Combobox query-shape test.** `Combobox`/`MultiSelect` assert the debounced request carries the
  documented `q`/`filter[...]` grammar (not that *some* rows appeared), mirroring the `AccountPicker`
  precedent.
- **File dropzone test.** Asserts a too-large or wrong-type file surfaces the inline `role="alert"`
  rejection and does not call `onFiles`, and that click-to-browse works without a drag event.
- **Playwright.** A real round-trip for `Combobox` (type → server search → select → the canonical value
  lands in the form) and a `CurrencyInput` in a live form (type a grouped value → the submitted payload
  carries the canonical string), across LTR and RTL.

# Edge Cases

- **A pasted, fully-formatted amount.** `CurrencyInput` normalizes `"KD 1,204.500"` / `"1٬٢٠٤٫٥"` /
  `"1204.5000"` all to the same canonical string; it never rejects a paste that is *recoverable*, only
  strips it to canonical form.
- **KWD 3-decimal vs. API 4-decimal precision.** The input displays 3 decimals for KWD but never
  rounds the *submitted* value below what the user typed up to that cap; the server owns the
  authoritative 4-decimal storage ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases`).
- **A zero vs. an empty amount.** An empty `CurrencyInput` submits `""` (which the schema may treat as
  a required-field miss), a typed `0` submits `"0"` — the two are distinct, and a form that requires a
  value never coerces empty to zero silently.
- **A `Select` whose current value is no longer in the options** (a currency later disabled for the
  company, a deleted cost center on an old record) still renders the stored value as a read-only,
  visibly-flagged item rather than showing a blank trigger that looks like data loss.
- **A `Combobox` over an empty source.** A brand-new company with no chart of accounts shows the
  distinct "set up your chart of accounts" empty state with an onboarding link, not the generic
  search-miss message.
- **A very long bilingual option label.** Combobox/select options and their triggers `truncate` with a
  `title` and a keyboard-focus `Tooltip` carrying the full string — Arabic labels commonly run longer
  than English at the same size, so widths are never tuned to English samples only
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases`).
- **A date input receiving an out-of-range date via paste/typing.** The `min`/`max` bounds clamp or
  reject with an inline message; a future posting date on a period that does not allow it is *shape*-
  valid client-side and rejected server-side as *policy*, mapped back onto the field.
- **A file that uploads then the mutation fails.** The dropzone keeps the accepted file listed with a
  retry affordance rather than clearing it, so a transient upload failure does not lose the user's
  selection.
- **Reduced motion.** The `SegmentedControl` indicator, `Combobox` popover, and dropzone drag-tint
  transitions collapse to instant state changes under `prefers-reduced-motion: reduce`, with no
  shortened-but-present animation ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Motion`).
- **Autofill mis-mapping.** Finance-sensitive fields set `autoComplete="off"` so a browser does not
  paste a saved credit-card or address value into an amount or account field; genuine contact fields
  use the correct `autoComplete` token instead.

# End of Document
