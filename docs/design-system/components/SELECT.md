# Select — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / SELECT
---

# Purpose

This document is the atomic, token-level specification of QAYD's `Select` primitive and its searchable
`Combobox` sibling — the controls a user picks a value from rather than types. It defines the primitives
in isolation: the trigger and menu anatomy, the single / searchable / grouped variants, every state,
keyboard navigation, the exact tokens each surface references, RTL behavior, and the prop/API surface. It
is implementation-first and field-agnostic — it says nothing about which entity a picker resolves, only
how the trigger, popover, and options look, behave, and are themed.

`Select` is built on `@radix-ui/react-select` for **short, fixed enumerations** (a status, an entry type,
a currency short-list); `Combobox` is built on Radix `Popover` + the `cmdk`/`Command` primitive for
**long, searchable lists** driven by a debounced server query. The two are deliberately different call
shapes over the same visual system — a giant native `<select>` over several hundred rows is both slow to
scan and inaccessible, so anything long is a `Combobox`, never a `Select`.

It is the design-system half of a pair with the application-integration catalogue,
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md), which lists `Select`,
`Combobox`, `MultiSelect`, and the rest and governs how each is wired into a field; the RHF + Zod system
is in [`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md). The canonical domain
instance of the `Combobox` pattern — `AccountPicker` over the Chart of Accounts — is specified in
[`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md) and summarized here as a note.
Read this document for the picker primitives; read those for the control catalogue, the form system, and
the finance pickers. Where the application docs use the pre-canonical draft token names (`ink-150`,
`accent-600`, `ink-500`), this document uses the **canonical** `ink-1…12` / brass tokens from
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md), which govern.

# Anatomy

Both primitives share a two-part anatomy: a **trigger** (a button-shaped control showing the current
value) and a **menu** (a popover of options). The `Combobox` adds a search input at the top of the menu.

```
Trigger (closed)                               Menu (open) — z-dropdown, surface-2
┌────────────────────────────────┐            ┌────────────────────────────────┐
│ Selected label            ⌄    │            │ [search input]  (Combobox only) │
└────────────────────────────────┘            ├────────────────────────────────┤
  ink-12 / ink-8 placeholder  ChevronsUpDown  │ Group label            (ink-9)  │
  border-ink-7, rounded-md, h-9               │ [check]  Option one         (ink-12)  │  ← selected: check + accent-subtle
                                              │    Option two                   │  ← hover/active: bg-ink-4
                                              │    Option three (locked)ink-8   │  ← unavailable: visible, dimmed
                                              └────────────────────────────────┘
                                                border-ink-6, shadow-sm, rounded-lg
```

- **Trigger.** Reuses the `outline` button surface: `flex w-full items-center justify-between rounded-md
  border border-ink-7 bg-ink-1 px-3 h-9 text-ink-12`, with a trailing `ChevronsUpDown` glyph at `ink-9`.
  A placeholder value is `ink-8`. The trigger carries `role="combobox"` and `aria-expanded`.
- **Menu surface.** A popover at `surface-2`: `bg-ink-1` (light) / `ink-3` (dark), `border border-ink-6`,
  `shadow-sm`, `rounded-lg` (`--radius-lg`, 8px — the menu is a card-class surface, one step larger than
  the 6px trigger). It sits at `z-dropdown` (20) and matches the trigger width
  (`w-[--radix-popover-trigger-width]`) for a `Combobox`, or Radix's content width for a `Select`.
- **Search input (Combobox).** A borderless `Input`-style row with a leading search glyph at the menu top;
  it drives the debounced query, not client-side filtering (`shouldFilter={false}`).
- **Option row.** `flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-ink-12`; the leading slot is
  a `Check` at `ink-12`/opacity for the selected row; hover/keyboard-active is `bg-ink-4`; a selected row
  may additionally tint `bg-accent-subtle`.
- **Group.** An `ink-9` `text-xs` uppercase group label above a set of options, with an `ink-6` divider
  between groups.
- **Focus ring.** The trigger carries the standard 2px `accent` `focus-visible` ring; inside the open
  menu, Radix roving focus moves an `active` highlight (`bg-ink-4`) rather than a ring.

# Variants

The primitives resolve into three functional variants plus a `multiple` mode. They are compositions/props,
not a `cva` skin axis — the shared visual tokens are constant across all of them.

| Variant | Built on | When |
|---|---|---|
| `Select` (single, fixed) | Radix `Select` | Short in-memory enumeration (status, entry type, a currency short-list) |
| `Combobox` (searchable) | Radix `Popover` + `Command`, debounced query | Long/searchable list from a server resource |
| Grouped | either | Options carry a `group`; rendered under `ink-9` group labels with `ink-6` dividers |
| `MultiSelect` | `Combobox` + a chip strip | Multiple values; `Backspace`-to-remove; a filter facet set |

Shared with `Input`, the trigger takes the `size` axis (`sm` 32px / `default` 36px / `lg` 40px) and an
`invalid` flag (`border-negative` + `aria-invalid`) so a picker lines up with adjacent fields and shows a
resolver/`422` error identically to a text field.

## Select — single, fixed enumeration

```tsx
// components/ui/select.tsx (QAYD-skinned Radix Select — trigger + content)
import * as RSelect from '@radix-ui/react-select';
import { Check, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';

export function Select({ value, onValueChange, options, placeholder, invalid, size = 'default', disabled }: SelectProps) {
  return (
    <RSelect.Root value={value} onValueChange={onValueChange} disabled={disabled}>
      <RSelect.Trigger
        aria-invalid={invalid || undefined}
        className={cn(
          'flex w-full items-center justify-between rounded-md border bg-ink-1 px-3 text-sm text-ink-12',
          'data-[placeholder]:text-ink-8 transition-colors',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
          'disabled:cursor-not-allowed disabled:opacity-50',
          size === 'sm' ? 'h-8' : size === 'lg' ? 'h-10' : 'h-9',
          invalid ? 'border-negative focus-visible:ring-negative' : 'border-ink-7',
        )}
      >
        <RSelect.Value placeholder={placeholder} />
        <RSelect.Icon><ChevronDown className="h-4 w-4 text-ink-9" aria-hidden /></RSelect.Icon>
      </RSelect.Trigger>
      <RSelect.Portal>
        <RSelect.Content
          position="popper"
          className="z-20 overflow-hidden rounded-lg border border-ink-6 bg-ink-1 shadow-sm"
        >
          <RSelect.Viewport className="p-1">
            {options.map((o) => (
              <RSelect.Item
                key={o.value}
                value={o.value}
                disabled={o.disabled}
                className={cn(
                  'flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-ink-12 outline-none',
                  'data-[highlighted]:bg-ink-4 data-[state=checked]:bg-accent-subtle',
                  'data-[disabled]:pointer-events-none data-[disabled]:text-ink-8',
                )}
              >
                <RSelect.ItemIndicator><Check className="h-4 w-4" aria-hidden /></RSelect.ItemIndicator>
                <RSelect.ItemText>{o.label}</RSelect.ItemText>
              </RSelect.Item>
            ))}
          </RSelect.Viewport>
        </RSelect.Content>
      </RSelect.Portal>
    </RSelect.Root>
  );
}
```

## Combobox — searchable, server-driven

```tsx
// components/ui/combobox.tsx (Popover + Command; debounced server query)
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Command, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem } from '@/components/ui/command';
import { ChevronsUpDown, Check } from 'lucide-react';
import { cn } from '@/lib/utils';

export function Combobox<T extends { id: string | number; label: string; group?: string }>(
  { value, onChange, useOptions, placeholder, invalid, disabled }: ComboboxProps<T>,
) {
  const [open, setOpen] = React.useState(false);
  const [search, setSearch] = React.useState('');
  const { options, isFetching } = useOptions(search, open); // debounced query hook lives in the consumer
  const selected = options.find((o) => o.id === value);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button" role="combobox" aria-expanded={open} aria-invalid={invalid || undefined} disabled={disabled}
          className={cn(
            'flex h-9 w-full items-center justify-between rounded-md border bg-ink-1 px-3 text-sm',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
            'disabled:cursor-not-allowed disabled:opacity-50',
            invalid ? 'border-negative' : 'border-ink-7',
          )}
        >
          <span className={cn('truncate', selected ? 'text-ink-12' : 'text-ink-8')}>
            {selected ? selected.label : placeholder}
          </span>
          <ChevronsUpDown className="h-4 w-4 shrink-0 text-ink-9" aria-hidden />
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-[--radix-popover-trigger-width] rounded-lg border-ink-6 p-0 shadow-sm">
        <Command shouldFilter={false}>
          <CommandInput value={search} onValueChange={setSearch} placeholder={placeholder} />
          <CommandList>
            {isFetching && <div className="p-3 text-sm text-ink-9" role="status">Searching…</div>}
            <CommandEmpty>{/* search-miss vs. empty-source — see States */}</CommandEmpty>
            <CommandGroup>
              {options.map((o) => (
                <CommandItem
                  key={o.id} value={String(o.id)}
                  onSelect={() => { onChange(o.id, o); setOpen(false); }}
                  className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-ink-12 data-[selected=true]:bg-ink-4"
                >
                  <Check className={cn('h-4 w-4', o.id === value ? 'opacity-100' : 'opacity-0')} aria-hidden />
                  <span className="truncate">{o.label}</span>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
```

The `Combobox` runs `shouldFilter={false}` because filtering is the server's job — the debounced `search`
drives a query in the consumer's `useOptions` hook, not client-side matching of an already-loaded list.
The debounce (250ms) lives in that hook, so a fast typist does not spawn a request per keystroke.

## AccountPicker note

`AccountPicker` is the canonical domain `Combobox` — a searchable picker over the company's Chart of
Accounts (`accounts`), used anywhere a journal line, budget row, or report filter references a GL account.
It is this primitive plus finance-specific props, fully specified in
[`../../frontend/COMPONENT_LIBRARY.md → AccountPicker`](../../frontend/COMPONENT_LIBRARY.md). The
load-bearing specializations, at the primitive level:

- **Option shape is code + name.** Each row renders a monospaced `account.code` (JetBrains Mono `ink-9`)
  in a fixed-width leading column, then the locale-picked `name_en`/`name_ar`, then an optional trailing
  `control` marker — so an accountant scans by code and by name at once.
- **`postableOnly` filters the query, not the render.** It sends `filter[allow_posting]=true` +
  `filter[status]=active` so header/summary accounts never appear as postable options, matching the
  Posting Engine's own `is_postable` rule — the picker reflects a server truth, it does not invent one.
- **Bilingual data, client-picked language.** The API returns both `name_en` and `name_ar`; the picker
  renders the one matching `useLocale()`, so a user in an Arabic UI can still search an English account
  name in a mixed-language box.
- **Empty source vs. search miss are distinct.** A brand-new company with no chart of accounts shows a
  "set up your chart of accounts" empty state with an onboarding link, not the generic "No account found"
  search-miss — see [`# States`](#states).

Every other entity picker (customer, vendor, product) follows the same `Combobox` shape.

# Props / API

## Shared (Select and Combobox)

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `value` | value / `null` | yes | — | Controlled selected value (`Select`: string; `Combobox`: id). |
| `onValueChange` / `onChange` | handler | yes | — | Fires the selected value (and, for `Combobox`, the full option object). |
| `placeholder` | `string` (i18n key) | no | — | Shown `ink-8` when nothing is selected; never the only label. |
| `size` | `'sm' \| 'default' \| 'lg'` | no | `'default'` | Trigger height aligned to the `Button`/`Input` scale. |
| `invalid` | `boolean` | no | `false` | `negative` trigger border + `aria-invalid`; usually driven by `<FormControl>`. |
| `disabled` | `boolean` | no | `false` | Non-interactive trigger, dimmed, out of tab order. |

## Select-specific

| Prop | Type | Description |
|---|---|---|
| `options` | `{ value; label; group?; disabled? }[]` | Short in-memory enumeration; `disabled` options stay visible and dimmed (locked), not removed. |

## Combobox-specific

| Prop | Type | Description |
|---|---|---|
| `useOptions` | `(search: string, open: boolean) => { options; isFetching }` | The debounced server-query hook; enabled only while `open`. Filtering is server-side (`shouldFilter={false}`). |
| `multiple` | `boolean` | Renders `MultiSelect` — a chip strip of selected values, `Backspace` removes the last. |
| `renderOption` | `(o) => ReactNode` | Optional custom row (e.g. `AccountPicker`'s code + name + control marker). |

# States

| State | Trigger / menu | Rendering |
|---|---|---|
| **Default (closed)** | Trigger | `border-ink-7`, value `ink-12` or placeholder `ink-8`, `ChevronsUpDown` `ink-9`. |
| **Focus** | Trigger | `focus-visible:ring-2 ring-accent ring-offset-2`. |
| **Open** | Menu | Popover at `surface-2` (`z-dropdown`), enter via `motion.fast`; the trigger keeps `aria-expanded="true"`. |
| **Option hover / active** | Option | `bg-ink-4` (Radix `data-[highlighted]` / cmdk `data-[selected=true]`). |
| **Selected** | Option | Leading `Check` at full opacity; the selected row may tint `bg-accent-subtle`. |
| **Searching** | Combobox menu | A `text-ink-9` "Searching…" row (`role="status"`) and an `aria-live="polite"` result-count announcement while the query is in flight. |
| **Search miss vs. empty source** | Combobox menu | A *search miss* ("No account found") is distinct from a *genuinely empty source* ("Set up your chart of accounts" + onboarding link) — different copy, so the user learns which situation they are in. |
| **Locked option** | Option | An option that exists but is unavailable here (a locked fiscal period, a control account in a postable-only context) stays visible and `ink-8`, not removed — the user learns *why* it is unavailable. |
| **Stored value no longer in options** | Trigger | A stored value that has left the source (a currency later disabled, a deleted cost center on an old record) renders as a read-only, visibly-flagged item, never a blank trigger that looks like data loss. |
| **Invalid** | Trigger | `border-negative` + `aria-invalid`; `<FormMessage>` below. |
| **Disabled** | Trigger | `opacity-50`, `cursor-not-allowed`, out of tab order. |

# Tokens Used

| Concern | Token(s) | Notes |
|---|---|---|
| Trigger fill | `ink-1` | `bg-ink-1`; reuses the `outline` surface |
| Trigger border | `ink-7` (rest) / `negative` (invalid) | Input-border band |
| Trigger value / placeholder | `ink-12` / `ink-8` | Value / placeholder |
| Chevron icon | `ink-9` | Default icon band |
| Menu surface | `ink-1` (light) / `ink-3` (dark) | `surface-2` |
| Menu border | `ink-6` | Hairline (menus use the lighter divider hairline, not `ink-7`) |
| Menu shadow / radius | `shadow-sm`, `radius-lg` (8px) | Quiet elevation; card-class radius |
| Option text | `ink-12` | High-emphasis |
| Option hover/active | `ink-4` | Hover fill band |
| Selected tint | `accent-subtle` | The one place the accent marks a selected row |
| Group label / divider | `ink-9` / `ink-6` | Secondary text / hairline |
| Account code (AccountPicker) | JetBrains Mono `ink-9` | Monospaced leading column |
| Focus ring | `accent` + `ink-1` offset | `focus-visible:ring-2` |
| z-index | `z-dropdown` (20) | Above sticky, below overlay |
| Menu enter | `motion.fast` 160ms | Reduced-motion → instant |

The selected-row `accent-subtle` is the accent used for selection state, which is a sanctioned use (a
selected chip / active tab) per [`../COLOR_SYSTEM.md → The accent`](../COLOR_SYSTEM.md); it is always
redundant with the leading `Check`, so selection survives grayscale.

# Accessibility

- **Native semantics via Radix.** `Select` uses Radix `Select` (roving focus, type-ahead, `aria-expanded`,
  option roles wired for free); `Combobox` uses the full combobox pattern (`role="combobox"`,
  `aria-expanded`, `aria-activedescendant`) over `cmdk`. Neither is a `<div onClick>`.
- **Keyboard.** Trigger opens on `Enter`/`Space`/`↓`; `↑`/`↓` move the active option; `Enter` selects;
  `Esc` closes and returns focus to the trigger; `Home`/`End` jump; `Select` supports type-ahead, and
  `Combobox` types into its search input. `MultiSelect`'s chips are individually focusable and removable.
- **Search result count is announced.** The debounced `Combobox` announces result-count changes through a
  visually-hidden `aria-live="polite"` region, and the "Searching…" row is `role="status"`, so a
  screen-reader user knows the list changed under them.
- **Label, never placeholder-only.** The programmatic label comes from `<FormLabel>`; the placeholder is
  never the sole label.
- **Locked options explain themselves.** An unavailable option stays in the list, dimmed, rather than
  vanishing — and where the reason is a permission, it carries the same "Requires `<key>`" affordance the
  platform mandates, never a silently missing row.
- **Invalid is wired.** `aria-invalid` on the trigger plus the `<FormMessage>` (`role="alert"`); the
  `negative` border is redundant with the message.
- **Focus-visible only.** The trigger ring appears on keyboard focus; inside the menu, the active
  highlight (`bg-ink-4`) — not a ring — tracks the roving focus, per Radix.
- **Contrast, both themes.** Trigger and option text (`ink-12` on `ink-1`), the `accent` ring, the
  `accent-subtle` selected tint (paired with the `Check`), and the `negative` invalid border all meet AA
  in light and dark ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).

# Theming, Dark Mode & RTL

**Theming.** Trigger, menu, options, chevron, selected tint, and focus ring all resolve to
`ink`/`accent`/`negative` tokens; no picker file carries a raw hex or a `dark:` variant against a raw
color.

**Dark mode.** A token remap only — the menu surface *lightens as it elevates* (`ink-3` dark menu over an
`ink-1` dark canvas, per [`../COLOR_SYSTEM.md → Surfaces`](../COLOR_SYSTEM.md)); the `accent` ring and
`accent-subtle` selected tint keep AA against the dark canvas; the `shadow-sm` is redefined at ~50%
light-mode alpha so it does not read as heavy on dark. No `dark:` overrides in source.

**RTL.**

- **Logical layout throughout.** The trigger's `justify-between` puts the chevron at the inline-end edge
  (right in LTR, left in RTL); the option `Check` uses `gap-2` and inline order; the menu aligns to the
  trigger's inline-start. Physical `left`/`right`/`ml`/`mr` are banned.
- **The chevron is a direction-neutral disclosure glyph** (`ChevronsUpDown` / `ChevronDown`) and does not
  flip; a "next/expand-toward-reading" chevron elsewhere would, via `rtl:rotate-180`.
- **Bilingual option data.** Options render the `useLocale()`-matched name; the search box accepts either
  language (a user in an Arabic UI can search an English account name). Any code/amount inside an option
  (an account code) stays `dir="ltr"` `latn` and never mirrors, per the numerals rule.
- **The menu direction follows the page**, but an individual option's *content* whose language differs
  (an English name in an Arabic list) is rendered in its own direction so it shapes correctly.

# Do / Don't

| Do | Don't |
|---|---|
| Use `Select` for a short fixed enumeration | Put several hundred rows in a native `<select>` — use `Combobox` |
| Use `Combobox` with a debounced server query for long lists | Load an entire account tree client-side and filter in the browser |
| Render the menu at `surface-2` with `ink-6` border + `shadow-sm` | Give the menu a heavy shadow or a `ink-7` border |
| Mark the selected row with `Check` + optional `accent-subtle` | Rely on the `accent-subtle` tint alone (fails grayscale) |
| Keep locked/unavailable options visible and dimmed | Silently drop an option the user can't pick — they can't learn why |
| Distinguish "no results" from "empty source" copy | Show "No account found" to a company with no chart of accounts yet |
| Render a stored-but-missing value as a flagged read-only item | Show a blank trigger that looks like data loss |
| Keep account codes / amounts `dir="ltr"` `latn` in options | Let a code digit-reverse inside an RTL menu |
| Announce Combobox result-count via `aria-live` | Update the list silently under a screen-reader user |
| Let dark mode be the token remap (menu lightens as it elevates) | Add a `dark:bg-*` on the menu surface |

# Usage & Composition

At the primitive level, a picker is composed inside a `<FormControl>` so the wrapper owns its ARIA and RHF
owns its value:

```tsx
// Short fixed enumeration.
<FormField control={form.control} name="currency_code" render={({ field, fieldState }) => (
  <FormItem>
    <FormLabel>{t('currency')}</FormLabel>
    <FormControl>
      <Select value={field.value} onValueChange={field.onChange}
              options={enabledCurrencies} placeholder={t('selectCurrency')} invalid={!!fieldState.error} />
    </FormControl>
    <FormMessage />
  </FormItem>
)} />

// The canonical searchable finance picker.
<FormField control={form.control} name={`lines.${i}.account_id`} render={({ field, fieldState }) => (
  <FormItem>
    <FormControl>
      <AccountPicker value={field.value ?? null} onChange={(id) => field.onChange(id)}
                     postableOnly invalid={!!fieldState.error} />
    </FormControl>
    <FormMessage />
  </FormItem>
)} />
```

**Composition contract.** The `invalid` prop comes from the wrapper; the label and message come from the
wrapper — the picker never carries its own ARIA-error props. `MultiSelect` is `Combobox` with a chip strip
and is used for filter facets; the fiscal-calendar-aware `PeriodPicker` and the general free-range
`DateRangePicker` are separate date compositions, not `Select` variants. The full control catalogue
(including `MultiSelect`, `RadioGroup`, `SegmentedControl`) is in
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md); the RHF/Zod submission system
and `422`-to-field mapping (including nested `lines.<i>.account_id` paths) are in
[`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md); and `AccountPicker` and the
other entity pickers are specified in
[`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md). The text siblings are
[`./INPUT.md`](./INPUT.md) and [`./TEXTAREA.md`](./TEXTAREA.md).

# End of Document
