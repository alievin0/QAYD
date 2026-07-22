# Date Picker — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / DATE_PICKER
---

# Purpose

This document is the atomic specification for QAYD's **Date Picker** family — the single-date `DateInput`
and the `DateRangePicker` — the controls an accountant uses to set a posting date, a document date, a
report window, or a filter's date range. It is the design-system companion to the application-facing input
catalogue in [`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md), which lists both
controls under the "Date entry" family and fixes three platform rules this document builds on: both store
a canonical ISO string (`YYYY-MM-DD`, or a `{from,to}` pair) and display it locale-aware; the
*fiscal-calendar-aware* selector is a separate `PeriodPicker` in
[`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md) that these defer to for
anything bound to `fiscal_periods`; and **displayed dates use Western-Arabic (`latn`) numerals even in the
Arabic locale** — a Gulf finance calendar shows Western digits.

INPUTS.md owns how a date control is *wired into a field*; this document owns the *primitives themselves* —
the trigger, the popover calendar, the typed-entry fallback, the exact token bindings, the full grid
keyboard model, `min`/`max` bounds, the EN/AR locale behavior (including the Hijri-display consideration),
and the RTL calendar mirroring. Both controls are a **Radix `Popover`** over a calendar grid; QAYD keeps
Radix's popover focus management and the calendar's grid semantics, and adds tokens, bounds, locale
formatting, and a keyboard-first typed fallback so an accountant never has to open the calendar to enter a
date they already know.

A date control formats and bounds; it never decides policy. `min`/`max` fail fast on the client, but
whether a period is *open* for posting is the server's answer, returned as a `422` and mapped onto the
field — a client date control cannot authoritatively know a company's period state
([`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) `# Validation & Behavior`).
Every value below traces to [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md).

# Anatomy

Both controls share one anatomy: a **trigger** (an input or button showing the current value or a
placeholder), a **`Popover`** that opens a **calendar surface**, and the calendar's parts — a **header**
(month/year label with prev/next navigation), a **weekday row**, and a **day grid** of buttons. The
single-date control adds a **typed-entry field**; the range control adds a **presets rail** and shows two
months side by side on wide viewports.

```
DateInput (single)                         DateRangePicker (range)
┌─────────────────────────┐ [cal]          ┌────────────────────────────────┐ [cal]
│ 2026-07-01              │                 │ 01 Jul 2026 – 31 Jul 2026      │
└─────────────────────────┘                 └────────────────────────────────┘
   typed ISO + calendar button                trigger button opens popover
                                              ┌──────────┬──────────────────────┐
   Popover (surface-2, ink-6 border)          │ Presets  │  July 2026  Aug 2026 │
   ┌───────────────────────────┐              │ Today    │  S M T W T F S  …    │
   │  ‹   July 2026   ›         │  header      │ This mo. │  · · 1 2 3 4 5  …    │
   │  S  M  T  W  T  F  S       │  weekdays    │ This qtr │  … range highlighted │
   │  ·  ·  1  2  3  4  5       │  day grid    │ FY-to-dt │                      │
   │  6  7  8  9 10 11 12 …     │              └──────────┴──────────────────────┘
   └───────────────────────────┘
```

- **Trigger** (`DateInput`): the shared `Input` surface (`radius-md`, `ink-7` border) holding the ISO
  string with `dir="ltr" inputMode="numeric"`, plus a trailing calendar `button` (a `CalendarDays` glyph,
  `ink-9`) that opens the popover. The typed field and the calendar are two ways into the same value.
- **Trigger** (`DateRangePicker`): an outline `Button` showing the formatted range or a
  `"Select a date range…"` placeholder in `ink-8`.
- **Popover surface**: `surface-2` — `ink-1` (light) / `ink-3` (dark) fill, `1px ink-6` border,
  `shadow-sm`, `radius-lg` — opening on `motion.moderate` (280ms) and closing to instant under reduced
  motion ([`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) `# Elevation & Surfaces`, `# Motion`).
- **Header**: the month/year in `display-sm` `ink-11`; prev/next are icon buttons whose chevrons carry
  `rtl:rotate-180` so navigation direction is correct in Arabic.
- **Weekday row**: `text-xs` `ink-9` uppercase; the week starts on the locale's first day (Sunday in the
  Gulf `ar` locale, configurable).
- **Day grid**: 7-column grid of day buttons; today is ringed, the selected day (or range) is filled, out
  of `min`/`max` days are disabled, days outside the visible month are `ink-8`. Digits render in `latn`.
- **Presets rail** (`DateRangePicker` only): a vertical list of named relative ranges (Today, This month,
  This quarter, FY-to-date); fiscal-period-aware shortcuts belong to `PeriodPicker`, not here.

# Variants

| Control | Selection | Trigger | Extra parts |
|---|---|---|---|
| `DateInput` | single day | typed `Input` + calendar button | typed-entry ISO fallback |
| `DateRangePicker` | `{from, to}` range | outline `Button` | presets rail, dual-month grid on `sm+` |

There are no color/decorative variants — a date control looks the same everywhere; only single vs. range
and the shared `size` (`sm`/`default`/`lg`, aligned to the control scale) differ. A **calendar-only**
inline form (no popover, the grid rendered directly in a panel) is the same calendar surface without the
`Popover` wrapper, used where a persistent calendar is wanted (a report's inline period chooser).

## Single date — typed fallback + calendar

```tsx
// components/ui/date-input.tsx (canonical QAYD tokens — typed field + popover calendar)
'use client';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Input } from '@/components/ui/input';
import { Calendar } from '@/components/ui/calendar';
import { CalendarDays } from 'lucide-react';
import { toISO } from '@/lib/dates';

export function DateInput({ value, onValueChange, min, max, invalid, readOnly, size }: DateInputProps) {
  return (
    <div className="relative">
      <Input
        value={value}                                  // canonical ISO YYYY-MM-DD
        onChange={(e) => onValueChange(e.target.value)} // typed ISO entry, latn, LTR
        placeholder="YYYY-MM-DD" inputMode="numeric" dir="ltr"
        invalid={invalid} readOnly={readOnly} size={size} className="pe-9"
      />
      <Popover>
        <PopoverTrigger asChild disabled={readOnly}>
          <button type="button" aria-label="Open calendar"
                  className="absolute inset-inline-end-2 top-1/2 -translate-y-1/2 text-ink-9 hover:text-ink-11">
            <CalendarDays className="size-4" aria-hidden />
          </button>
        </PopoverTrigger>
        <PopoverContent align="end" className="w-auto p-0">
          <Calendar
            mode="single"
            selected={value ? new Date(value) : undefined}
            fromDate={min ? new Date(min) : undefined}
            toDate={max ? new Date(max) : undefined}
            onSelect={(d) => d && onValueChange(toISO(d))}
          />
        </PopoverContent>
      </Popover>
    </div>
  );
}
```

## Date range — presets + dual month

```tsx
// components/ui/date-range-picker.tsx (shape — formatted trigger + range calendar)
'use client';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { CalendarDays } from 'lucide-react';
import { useFormatter } from 'next-intl';
import { toISO } from '@/lib/dates';

export function DateRangePicker({ value, onChange, min, max, presets }: DateRangePickerProps) {
  const f = useFormatter(); // dateTime formatter forces numberingSystem:'latn' in the ar locale
  const label = value
    ? `${f.dateTime(new Date(value.from), { dateStyle: 'medium' })} – ${f.dateTime(new Date(value.to), { dateStyle: 'medium' })}`
    : undefined;
  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" className="justify-start gap-2 font-normal">
          <CalendarDays className="size-4 text-ink-9" aria-hidden />
          {label ?? <span className="text-ink-8">Select a date range…</span>}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="flex w-auto gap-0 p-0">
        {presets && (
          <ul className="w-40 border-e border-ink-6 p-2 text-sm">
            {presets.map((p) => (
              <li key={p.label}>
                <button type="button" onClick={() => onChange(p.range)}
                        className="w-full rounded-md px-2 py-1.5 text-start text-ink-11 hover:bg-ink-4">
                  {p.label}
                </button>
              </li>
            ))}
          </ul>
        )}
        <Calendar
          mode="range" numberOfMonths={2}
          fromDate={min ? new Date(min) : undefined} toDate={max ? new Date(max) : undefined}
          selected={value ? { from: new Date(value.from), to: new Date(value.to) } : undefined}
          onSelect={(r) => onChange(r?.from && r?.to ? { from: toISO(r.from), to: toISO(r.to) } : null)}
        />
      </PopoverContent>
    </Popover>
  );
}
```

# Props / API

Both controls are controlled; the canonical value is always an ISO string (or `{from,to}` of ISO
strings), never a localized display string.

## Shared

| Prop | Type | Default | Description |
|---|---|---|---|
| `min` / `max` | `string` (ISO) | — | Selectable-range bounds; out-of-range days are disabled in the grid and clamped/rejected on typed entry (e.g. no future posting date). |
| `size` | `'sm' \| 'default' \| 'lg'` | `'default'` | Trigger height, aligned to the shared control scale. |
| `disabled` | `boolean` | `false` | Non-interactive trigger; permission tooltip when RBAC-gated. |
| `readOnly` | `boolean` | `false` | Value is reachable/copyable but the calendar button is disabled — for a server-computed date. |
| `invalid` | `boolean` | `false` | Negative border + `aria-invalid`; usually from the `<FormControl>` wrapper. |
| `locale` | `string` | active app locale | Drives month/weekday names, first day of week, and the display formatter (always `latn` numerals). |

## `DateInput`

| Prop | Type | Description |
|---|---|---|
| `value` | `string \| null` | Canonical ISO `YYYY-MM-DD`. |
| `onValueChange` | `(iso: string) => void` | Fires the canonical ISO string from either the typed field or the calendar. |

## `DateRangePicker`

| Prop | Type | Description |
|---|---|---|
| `value` | `{ from: string; to: string } \| null` | Canonical ISO pair. |
| `onChange` | `(range: {from,to} \| null) => void` | Fires the ISO pair, or `null` when cleared. |
| `presets` | `{ label: string; range: {from,to} }[]` | Named relative shortcuts; for fiscal shortcuts use `PeriodPicker` ([`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md)). |

In a form both controls take no ARIA-error props of their own — `id`, `aria-invalid`, and
`aria-describedby` are forwarded by `<FormControl>`, per
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) `# Anatomy & Variants`.

# States

Trigger states follow the shared field matrix; the calendar grid adds day-cell states. Every colored
signal (today, selected, in-range, disabled) pairs with a non-color cue — position, a ring, or the
`aria-disabled` state — per [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) Principle 5.

| Element / state | Treatment | Notes |
|---|---|---|
| Trigger · default | `ink-1` fill, `ink-7` border, `ink-11` value / `ink-8` placeholder | — |
| Trigger · focus | 2px `ring` (accent) + offset | `focus-visible` only. |
| Trigger · invalid | `negative` border + ring | ISO parse failure or a server policy `422`. |
| Trigger · read-only | `ink-2` fill, calendar button disabled | Server-computed date. |
| Popover · open/close | `motion.moderate` (280ms) enter, instant under reduced motion | `surface-2`, `z-dropdown`. |
| Day · default | `ink-11` text on transparent, hover `ink-4` fill | `latn` digit. |
| Day · today | `1px accent` ring, no fill | Position + ring, not a fill, so it never reads as "selected." |
| Day · selected (single) | `accent` fill, `accent-on` digit | The filled-accent-surface pairing (near-black on brass). |
| Range · endpoints | `accent` fill, `accent-on` digit | `from` and `to` are the solid ends. |
| Range · in-between | `accent-subtle` fill, `ink-11` digit | The soft wash between endpoints; a dark-ink digit on the subtle brass. |
| Day · outside month | `ink-8` text | Visible but de-emphasized. |
| Day · out of `min`/`max` | `ink-8`, `aria-disabled`, not focusable | Cannot be selected; typed entry clamps/rejects. |
| Range · in-progress | `from` filled, hovered day previews the tentative `to` | A hover preview highlights the pending range before the second click. |

# Tokens Used

Every value resolves to a token; no literal a token already covers appears in the primitives. Color
tokens carry their own light/dark values from [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md).

| Role | Token / utility | Light | Dark |
|---|---|---|---|
| Trigger fill / border | `bg-ink-1` / `border-ink-7` | `#FAFAF9` / `#A9A49A` | `#14130F` / `#58503A` |
| Placeholder text | `text-ink-8` | `#878174` | `#786F55` |
| Value / day text | `text-ink-11` | `#2B2820` | `#E4DEC9` |
| Popover surface | `bg-ink-1` (L) / `bg-ink-3` (D), `border-ink-6`, `shadow-sm` | `#FAFAF9` / `#C2BEB6` | `#24211A` / `#46402F` |
| Weekday / muted day | `text-ink-9` / `text-ink-8` | `#645F53` / `#878174` | `#A39878` / `#786F55` |
| Day hover fill | `bg-ink-4` | `#E0DEDA` | `#2D2921` |
| Today ring | `ring-accent` | `#9C7A34` | `#D9B96C` |
| Selected / range endpoint fill | `bg-accent` | `#9C7A34` | `#D9B96C` |
| Selected day digit | `text-accent-on` | `#15130E` | `#14130F` |
| In-range wash | `bg-accent-subtle` | `#EADFBF` | `#3A2E14` |
| Focus ring | `ring-ring` (= accent) | `#9C7A34` | `#D9B96C` |
| Invalid border + ring | `border-negative` / `ring-negative` | `#B4232E` | `#F26B74` |
| Presets divider | `border-ink-6` | `#C2BEB6` | `#46402F` |
| Trigger / cell radius | `rounded-md` / `rounded-md` (`radius-md` = 6px) | — | — |
| Popover radius | `rounded-lg` (`radius-lg` = 8px) | — | — |
| Popover open motion | `motion.moderate` = 280ms | — | — |
| Z-index | `z-dropdown` = 20 | — | — |

The selected day is a filled `accent` surface with a **near-black `accent-on` digit**, never white
([`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) `# accent-on is near-black, never white`); the in-range wash is
`accent-subtle` with a dark `ink-11` digit — the same subtle-background-for-a-dark-label pairing used for
AI notices, here reused for a range's interior. **Today is a ring, not a fill**, so it is never confused
with the selected day even in grayscale.

# Accessibility

Both controls meet the WCAG 2.1 AA floor of [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) and
[`../../frontend/ACCESSIBILITY.md`](../../frontend/ACCESSIBILITY.md).

| Concern | Contract |
|---|---|
| Popover focus | Opening the popover moves focus into the calendar; `Esc` closes and returns focus to the trigger; focus is trapped within the open popover (Radix). |
| Trigger | The `DateInput` trigger announces the typed ISO value; the range trigger announces the formatted range or the "select a date range" placeholder. |
| Grid semantics | The calendar is a labelled grid (`role="grid"`, `gridcell` days) with the month/year as its accessible name; today is `aria-current="date"`, the selected day `aria-selected`. |
| Keyboard — grid | Arrow keys move one day (`↑`/`↓` = ±1 week, `←`/`→` = ±1 day); `Home`/`End` jump to the week's start/end; `PageUp`/`PageDown` change month, `Shift+PageUp/Down` change year; `Enter`/`Space` selects the focused day. |
| Keyboard — typed | `DateInput` accepts a typed ISO date without ever opening the calendar — a keyboard-first accountant enters `2026-07-01` and tabs on. |
| Bounds | Out-of-range days are `aria-disabled` and skipped by arrow navigation; a typed out-of-range date is clamped or rejected with an inline message. |
| Direction icons | Prev/next chevrons are direction-encoding and flip under RTL (`rtl:rotate-180`); the calendar glyph is a meaning icon and does not flip. |
| Numerals | The value stays `dir="ltr"` with `latn` digits so an Arabic screen reader and a sighted user both read Western digits in a stable order — a date is never digit-reversed. |
| Focus ring | `focus-visible` only on the trigger and the roving day, 2px `ring` + offset, ≥3:1 against every surface. |
| Color independence | Today (ring), selected (fill + `aria-selected`), in-range (position), and disabled (`aria-disabled`) each carry a non-color cue. |
| Disabled by permission | An RBAC-disabled trigger keeps a tooltip naming the required key, per [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) `# WCAG AA Contrast`. |

# Theming, Dark Mode & RTL

- **Tokens only.** Trigger, popover surface, day cells, today ring, selection fill, in-range wash, focus
  ring, and invalid state each resolve to an `ink`/`accent`/`negative` variable; no source contains a raw
  hex or a `dark:` override against a raw color. Dark mode is the token remap of
  [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) — the popover *lightens* as it elevates (`ink-3` card over
  the `ink-1` canvas in dark), and the selection accent lightens to `#D9B96C` with a near-black digit.
- **RTL — the calendar mirrors.** The day grid and header lay out with logical properties so the week
  reads right-to-left under `[dir="rtl"]`; the prev/next chevrons flip via `rtl:rotate-180` so "next
  month" is still the natural forward direction. A range highlight sweeps in reading order. The first day
  of the week follows the locale (Sunday for the Gulf `ar` locale).
- **Numerals never mirror.** Displayed day numbers, the year, and the typed value render `dir="ltr"` with
  `latn` numerals in the Arabic locale — a Gulf finance calendar shows `2026` and `01`, never
  Eastern-Arabic or digit-reversed forms ([`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md)
  `# Theming, Dark Mode & RTL`, rule 2).
- **Locale — EN / AR.** Month and weekday *names* localize (`July` / `يوليو`) via the `next-intl`
  formatter; the stored value is always ISO. The formatter is pinned to `numberingSystem: 'latn'` for the
  `ar` locale so names translate but digits do not.
- **Hijri consideration.** QAYD's default calendar is Gregorian with `latn` digits, matching how Gulf
  businesses keep their books and file with authorities. A **Hijri (Umm al-Qura) secondary display** — a
  muted `ink-9` line showing the Hijri date beneath the Gregorian header, or a per-day
  `title`/`aria-description` — is a supported *annotation* for `ar` locale surfaces, never a replacement:
  the canonical stored and submitted value stays Gregorian ISO, because the ledger, periods, and tax
  filings are Gregorian. A control that let a user *select* in Hijri still converts to and stores the
  Gregorian ISO date; the Hijri string is display-only and is out of scope for the primitive's value
  contract (it is an opt-in decoration layered by the consuming screen).

# Do / Don't

| Do | Don't |
|---|---|
| Store and submit a canonical ISO `YYYY-MM-DD` (or `{from,to}`) | Store or submit a localized display string |
| Render day digits and the year in `latn` numerals, even in `ar` | Show Eastern-Arabic or digit-reversed numbers in the calendar |
| Offer a typed ISO fallback so the calendar is optional | Force every date entry through the popover |
| Mark today with a ring and the selected day with a fill | Use a fill for both so today reads as selected |
| Pair the selected `accent` fill with a near-black `accent-on` digit | Put a white digit on the brass selected day |
| Flip prev/next chevrons under RTL, mirror the grid | Leave navigation chevrons unflipped in Arabic |
| Localize month/weekday names but pin `numberingSystem:'latn'` | Let the locale switch digits to Eastern-Arabic |
| Treat Hijri as an opt-in display annotation over a Gregorian ISO value | Store or submit a Hijri string as the canonical value |
| Disable out-of-`min`/`max` days and clamp typed entry | Let a user select a future posting date the client should block |
| Map a server period `422` back onto the field | Assume a client-valid date means the period is open |
| Defer fiscal-period shortcuts to `PeriodPicker` | Hardcode fiscal-period logic into `DateRangePicker` |
| Reference tokens (`bg-accent`, `border-ink-6`) | Hardcode `#9C7A34` or a raw Tailwind palette value |

# Usage & Composition

- **A posting date in a form.** The `DateInput` drops into a `<FormControl>` with a `max` of today (no
  future posting) and a `min` at the open period's start; the `<Form>` wrapper owns its ARIA and React
  Hook Form owns the ISO value. Client bounds fail fast; the server re-checks period openness and returns
  a `422` mapped onto the field if the client's view of the period is stale:

  ```tsx
  <FormField control={form.control} name="posting_date" render={({ field, fieldState }) => (
    <FormItem>
      <FormLabel>Posting date</FormLabel>
      <FormControl>
        <DateInput value={field.value} onValueChange={field.onChange}
                   max={today} min={openPeriodStart} invalid={!!fieldState.error} />
      </FormControl>
      <FormMessage />
    </FormItem>
  )} />
  ```

- **A report / filter window.** The `DateRangePicker` sits in a filter bar
  ([`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) family "Date entry") with
  presets (Today, This month, This quarter, FY-to-date) and a dual-month grid; its `{from,to}` only
  parametrizes a server report request — the report figures are the server's.

- **Fiscal periods are not this control.** Anything tied to `fiscal_periods` (a month-end close window, a
  locked-period selector) uses `PeriodPicker` from
  [`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md), which knows the company's
  calendar; `DateRangePicker` is the general free-range control and deliberately does not encode fiscal
  logic.

- **What it must not become.** A date picker never computes an age, a due-date, a term, or a period
  boundary — those are server-derived values it may *display* (read-only) but never calculate; and it
  never silently coerces an ambiguous or out-of-range paste into a "close enough" date — it clamps to
  `min`/`max` or rejects with an inline message.

For field wiring see [`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) and
[`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md); for the fiscal-aware sibling see
`PeriodPicker` in [`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md); for tokens
see [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md); for the
other input primitives see [`CHECKBOX.md`](./CHECKBOX.md), [`RADIO.md`](./RADIO.md), and
[`SWITCH.md`](./SWITCH.md).

# End of Document
