# Calendar — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / CALENDAR
---

# Purpose

This document is the atomic component spec for `Calendar` — QAYD's single date-grid primitive. It renders
a month or week grid of day cells, owns single-date selection and date-range selection, plots read-only
event/deadline indicators on days, and localizes fully to English and Arabic (including a Gregorian-primary,
Hijri-secondary day label and forced Latin numerals). It is the one grid every dated surface in the product
is built from: the `PeriodPicker` / `DateRangeCalendar` "jump to date" popover, a form field's date input,
and — at a larger scale — the Financial Calendar screen's month grid.

It is a **specialization**, never a duplication, of the app documents that compose it. The
[`../../frontend/CALENDAR.md`](../../frontend/CALENDAR.md) screen spec owns the *Financial Calendar* — its
route, its eight event categories, the `CalendarEvent` projection, the aggregate endpoint, the Upcoming
Risks rail, realtime invalidation, and the responsive collapse to an agenda list. This document owns only
the reusable grid primitive that screen (and every date picker) renders through: its anatomy, its selection
model, its keyboard `role="grid"` contract, and its token, theming, and RTL rules. Where the two touch —
the roving-tabindex grid, the `latn`-forced day numerals, the region-driven week start — this spec is the
canonical source and the screen inherits it verbatim.

All values reference [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the warm-neutral `ink-1…12` scale, the
single brass `accent`, the `positive`/`negative`/`warning` financial-semantic set, and the `radius-*` /
`motion.*` primitives. `Calendar` ships no hex, rgb, or px a token already covers.

# Anatomy

```
┌───────────────────────────────────────────────┐
│  ‹   July 2026   ›                    [Today]   │  ← header: prev/next, visible-unit label, today
├───────────────────────────────────────────────┤
│  Sun  Mon  Tue  Wed  Thu  Fri  Sat             │  ← weekday header row (region-ordered)
├───────────────────────────────────────────────┤
│  28    29    30    1     2     3     4          │  ← week row (role="row")
│                    •                            │     day cell (role="gridcell"): numeral + dots
│  5     6     7     8     9     10    11         │
│  12    13    14   [15]   16    17    18         │  ← [15] selected · today ring on the current day
│              ••    •           •                │     event indicators (up to 3, then "+N")
│  19    20    21    22    23    24    25         │
│  26    27    28    29    30    31    1          │
├───────────────────────────────────────────────┤
│  ١٤ ذو الحجة (optional Hijri sub-label)          │  ← secondary, never the primary numeral
└───────────────────────────────────────────────┘
```

| Part | Element | Token / behavior |
|---|---|---|
| Header | `‹` / `›` icon buttons + visible-unit label + optional `Today` | `ghost` buttons; label is `display-sm`; icons never mirror, order does |
| Weekday row | Seven abbreviated weekday names | `text-xs` `text-ink-9`, region-ordered week start |
| Week row | `role="row"`, seven cells | one `role="row"` per visible week (5–6 in month, 1 in week mode) |
| Day cell | `role="gridcell"` button: numeral + up-to-3 event dots + "+N" | `numeral-table` figure in a `dir="ltr"` span; `rounded-md` hit area |
| Today ring | Outline wash on the current day | `accent` at reduced opacity, background wash — never a filled block |
| Selection | Filled day / range band | `accent` fill + `accent-on` text (single); `accent-subtle` band (range) |
| Event indicator | ≤3 dots, then `+N more` | category dot per event; `+N` opens the host's overflow, not the primitive's |
| Hijri sub-label | Optional secondary line under the numeral | Arabic-Indic by convention, secondary only |

The primitive renders the grid, selection, and indicators; it does **not** own the day-detail popover, the
event sheet, or the category legend — those belong to the composing surface
([`../../frontend/CALENDAR.md`](../../frontend/CALENDAR.md)).

# Variants

| Variant | Prop | Renders | Primary use |
|---|---|---|---|
| Single-select | `mode="single"` | One selectable day; `selected` is a `Date` | Form date field, "jump to date" |
| Range-select | `mode="range"` | A start→end band; `selected` is `{ from, to }` | `PeriodPicker` custom range, report windows |
| Events (read-only) | `mode="events"` | Day cells with indicator dots, no selection | Financial Calendar month grid |
| Month layout | `layout="month"` | 5–6 week rows | Default |
| Week layout | `layout="week"` | One taller week row | Dense `md:` calendar, day-of-week context |

`mode` (what a click *does*) and `layout` (how many rows render) are orthogonal — an `events` calendar can
be `month` or `week`; a `single` picker is almost always `month`. Selection modes and the events mode are
mutually exclusive on one instance: a grid either selects dates or plots events, never both, so a click's
meaning is never ambiguous.

# Props / API

```tsx
// components/ui/calendar.tsx
'use client';
import { useMemo } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Icon } from '@/components/ui/icon';
import { Button } from '@/components/ui/button';
import { useLocale, useTranslations } from '@/lib/i18n';
import { useRovingCalendarGrid } from '@/hooks/use-roving-calendar-grid';
import { buildMonth, weekdayNames, isSameDay, isWithin } from '@/lib/calendar/grid';
import { cn } from '@/lib/utils';

export type CalendarMode = 'single' | 'range' | 'events';
export type CalendarLayout = 'month' | 'week';
export type DateRange = { from: Date; to: Date | null };
export interface DayEvent { id: string; tone: 'accent' | 'positive' | 'negative' | 'warning' | 'neutral'; label: string }

interface CalendarProps {
  mode?: CalendarMode;                       // default 'single'
  layout?: CalendarLayout;                    // default 'month'
  month: Date;                                // the visible month/week anchor (controlled)
  onMonthChange: (next: Date) => void;
  selected?: Date | DateRange | null;         // shape follows `mode`
  onSelect?: (value: Date | DateRange) => void;
  events?: Record<string, DayEvent[]>;        // keyed 'YYYY-MM-DD'; only read in mode='events'
  isDateDisabled?: (day: Date) => boolean;    // e.g. future dates, closed periods
  weekStartsOn?: 0 | 1 | 6;                   // region-driven; default from locale/region
  showHijri?: boolean;                        // adds the secondary Hijri line
  loading?: boolean;                          // renders skeleton cells at final dimensions
}
```

| Prop | Type | Required | Description |
|---|---|---|---|
| `mode` | `'single' \| 'range' \| 'events'` | no (default `single`) | What a day click does; `events` is read-only. |
| `layout` | `'month' \| 'week'` | no (default `month`) | Row count. |
| `month` | `Date` | yes | Controlled visible-range anchor; the primitive never owns navigation state. |
| `onMonthChange` | `(next: Date) => void` | yes | Fires from `‹`/`›`/`Today` and `PageUp`/`PageDown`. |
| `selected` | `Date \| DateRange \| null` | conditional | Required for selection modes; ignored in `events`. |
| `onSelect` | `(value) => void` | conditional | Fires on a day activation in a selection mode. |
| `events` | `Record<string, DayEvent[]>` | no | Indicator dots per `YYYY-MM-DD`; the primitive plots dots, the host owns the detail. |
| `isDateDisabled` | `(day: Date) => boolean` | no | Non-selectable, non-focusable days (`aria-disabled`). |
| `weekStartsOn` | `0 \| 1 \| 6` | no | Region setting, **not** language — Gulf defaults to Sunday (`0`). |
| `showHijri` | `boolean` | no (default `false`) | Adds a secondary Hijri line via `Intl.DateTimeFormat`. |
| `loading` | `boolean` | no | Skeleton cells at final grid dimensions; never a spinner. |

```tsx
export function Calendar({
  mode = 'single', layout = 'month', month, onMonthChange,
  selected, onSelect, events, isDateDisabled, weekStartsOn, showHijri, loading,
}: CalendarProps) {
  const locale = useLocale();
  const { t } = useTranslations('calendar');
  const weeks = useMemo(() => buildMonth(month, { layout, weekStartsOn: weekStartsOn ?? (locale.region === 'KW' ? 0 : 1) }), [month, layout, weekStartsOn, locale.region]);
  const { active, onKeyDown, cellRef } = useRovingCalendarGrid(weeks.length, onMonthChange, month);

  return (
    <div className="w-72 select-none">
      {/* Header — icons keep orientation under RTL; the ‹/› *order* mirrors, not the glyphs */}
      <div className="mb-2 flex items-center justify-between">
        <Button variant="ghost" size="icon" aria-label={t('prev')} onClick={() => onMonthChange(addMonths(month, -1))}>
          <Icon icon={ChevronLeft} size="sm" className="rtl:rotate-180" aria-hidden />
        </Button>
        <span className="font-display text-[1.25rem] leading-tight text-ink-11">
          {new Intl.DateTimeFormat(locale.tag, { month: 'long', year: 'numeric' }).format(month)}
        </span>
        <Button variant="ghost" size="icon" aria-label={t('next')} onClick={() => onMonthChange(addMonths(month, 1))}>
          <Icon icon={ChevronRight} size="sm" className="rtl:rotate-180" aria-hidden />
        </Button>
      </div>

      <div role="grid" aria-label={t('gridLabel')} aria-rowcount={weeks.length} aria-colcount={7} onKeyDown={onKeyDown}>
        <div role="row" className="grid grid-cols-7">
          {weekdayNames(locale.tag, weekStartsOn ?? 0).map((wd) => (
            <span key={wd} role="columnheader" className="pb-1 text-center text-xs font-medium text-ink-9">{wd}</span>
          ))}
        </div>

        {weeks.map((week, wi) => (
          <div role="row" key={wi} className="grid grid-cols-7">
            {week.map((day, di) => {
              const key = day.iso;
              const disabled = isDateDisabled?.(day.date) ?? false;
              const isToday = isSameDay(day.date, new Date());
              const isSelected = isDaySelected(selected, day.date, mode);
              const inRange = mode === 'range' && isWithin(selected as DateRange, day.date);
              const dayEvents = events?.[key] ?? [];
              return (
                <button
                  key={key}
                  ref={active.week === wi && active.day === di ? cellRef : undefined}
                  role="gridcell"
                  type="button"
                  tabIndex={active.week === wi && active.day === di ? 0 : -1}
                  aria-selected={isSelected || undefined}
                  aria-disabled={disabled || undefined}
                  aria-current={isToday ? 'date' : undefined}
                  aria-label={dayAriaLabel(day.date, locale.tag, dayEvents)}
                  disabled={disabled}
                  onClick={() => !disabled && mode !== 'events' && onSelect?.(nextSelection(selected, day.date, mode))}
                  className={cn(
                    'relative m-0.5 flex h-9 flex-col items-center justify-center rounded-md text-sm text-ink-11',
                    !day.inMonth && 'text-ink-8',
                    inRange && 'bg-accent-subtle',
                    isSelected && 'bg-accent text-accent-on',
                    !isSelected && !disabled && 'hover:bg-ink-4',
                    isToday && !isSelected && 'ring-1 ring-accent/60',
                    disabled && 'cursor-not-allowed text-ink-8',
                    loading && 'animate-pulse bg-ink-3 text-transparent',
                  )}
                >
                  {/* numeral never mirrors and never switches to Eastern-Arabic digits */}
                  <span className="font-mono tabular-nums" dir="ltr">{day.date.getDate()}</span>
                  {showHijri && <span className="text-[10px] leading-none text-ink-9">{hijri(day.date, locale.tag)}</span>}
                  {mode === 'events' && dayEvents.length > 0 && <EventDots events={dayEvents} />}
                </button>
              );
            })}
          </div>
        ))}
      </div>
    </div>
  );
}

function EventDots({ events }: { events: DayEvent[] }) {
  const TONE = {
    accent: 'bg-accent', positive: 'bg-positive', negative: 'bg-negative',
    warning: 'bg-warning', neutral: 'bg-ink-9',
  } as const;
  return (
    <span className="mt-0.5 flex items-center gap-0.5" aria-hidden>
      {events.slice(0, 3).map((e) => <span key={e.id} className={cn('size-1 rounded-full', TONE[e.tone])} />)}
      {events.length > 3 && <span className="text-[9px] leading-none text-ink-9">+{events.length - 3}</span>}
    </span>
  );
}
```

# States

| State | Treatment | Token |
|---|---|---|
| Default day | Plain numeral on canvas | `text-ink-11` on `ink-1` |
| Out-of-month | Dimmed spill-over day | `text-ink-8` |
| Hover (selectable) | Subtle fill under the cursor | `hover:bg-ink-4` |
| Focus (roving) | Visible ring at non-zero offset on the one `tabIndex=0` cell | `ring-accent` |
| Today | Outline wash ring, background never filled | `ring-accent/60` |
| Selected (single) | Filled brass day, near-black text | `bg-accent` + `text-accent-on` |
| Range endpoints / band | Endpoints filled, interior tinted | `bg-accent` ends, `bg-accent-subtle` band |
| Disabled date | Non-focusable, `aria-disabled`, `cursor-not-allowed` | `text-ink-8` (contrast-exempt, reinforced by state) |
| Has events | ≤3 tone dots + `+N` | per-category tone; `+N` is `text-ink-9` |
| Loading | Skeleton cells at final dimensions, no layout shift | `bg-ink-3` pulse |
| Empty (events mode) | Grid renders; days simply carry no dots (host owns the "nothing due" copy) | — |

The primitive never renders an error state of its own — a failed events fetch is the host's concern; the
grid always renders its dates so navigation and selection keep working even when indicators cannot load.

# Tokens Used

| Role | Token | Where |
|---|---|---|
| Canvas / cell text | `ink-1`, `ink-11` | Grid background, day numerals |
| Out-of-month / disabled | `ink-8` | Spill-over and non-selectable days |
| Hover fill | `ink-4` | Selectable-day hover |
| Weekday labels / `+N` / Hijri | `ink-9` | Header row, overflow count, secondary line |
| Selection | `accent`, `accent-on` | Selected day fill + text |
| Range band / today ring | `accent-subtle`, `accent` | Interior tint, `Today` outline wash |
| Event dots | `accent`, `positive`, `negative`, `warning`, `ink-9` | Per-category indicator tone |
| Radius | `radius-md` | Day-cell hit area |
| Motion | `motion.base` / `easeOut` | Month transition, range-band fill (reduced-motion-gated) |
| Elevation | `surface-2` (`shadow-sm`, `ink-6` border) | When mounted in a popover |
| Z-index | `z-dropdown` | Popover-mounted picker |

Per [`../DESIGN_TOKENS.md → The debit/credit rule`](../DESIGN_TOKENS.md), `positive`/`negative` on an
event dot express a genuinely signed status the *host* assigned (e.g. overdue), never a raw amount; the
brass `accent` marks selection and today only, never an event's importance.

# Accessibility

`Calendar` is the platform's `role="grid"` date primitive, sharing the exact roving-tabindex skeleton the
Journal Entry line editor and the Financial Calendar screen use — one implementation, not three.

- **Grid roles.** `role="grid"` (`aria-rowcount`, `aria-colcount={7}`), `role="row"` per week,
  `role="columnheader"` per weekday, `role="gridcell"` per day. Exactly one cell holds `tabIndex={0}`
  (the active day); every other is `-1`, so `Tab` enters and leaves the whole grid once.
- **Keyboard model.** `←`/`→` move by a day, `↑`/`↓` by a week, `Home`/`End` to the first/last day of the
  active week, `PageUp`/`PageDown` by a month (calling `onMonthChange`), `Ctrl/Cmd+Home` to today, `Enter`/
  `Space` activate. Arrow keys read DOM row/column indices, so they mirror correctly under `dir="rtl"`
  with no RTL branch.
- **Accessible names.** Every cell's `aria-label` states the full date and its load —
  `"Tuesday, July 15, 2026 — 2 events"` — generated from the cell's own data, never a bare numeral. A
  disabled day carries `aria-disabled` and stays out of the roving order.
- **Live region.** Changing the visible month announces politely (`aria-live="polite"`,
  `"Now showing July 2026"`); nothing on the grid announces assertively.
- **Not color alone.** Today is a ring *and* `aria-current="date"`; selection is a fill *and*
  `aria-selected`; an event's meaning rides its `aria-label`, never a dot color alone — satisfying the
  color-independence rule of [`../DESIGN_TOKENS.md → Contrast targets`](../DESIGN_TOKENS.md).
- **Reduced motion.** The month transition and range-band fill read `useReducedMotion()` and collapse to
  an instant state change; the date still changes, only its animation is removed.

Target: **WCAG 2.1 AA** in both themes and both directions.

# Theming, Dark Mode & RTL

**Tokens only.** Every color resolves to a Tailwind utility backed by a `--qayd-*` variable
(`bg-accent`, `text-ink-11`, `ring-accent/60`, `bg-accent-subtle`) — no `dark:` raw-color variant, no
literal hex. This is what lets dark mode be a one-layer remap.

**Dark mode is a calibrated remap, not an inversion.** `.dark` re-points every `--qayd-*` name these
utilities read: the canvas warms to `ink-1` dark (`#14130F`), the accent *lightens* to `#D9B96C` (holding
contrast against `accent-on`), and the today ring and range band re-tune per theme rather than brightening
linearly. A raised popover-mounted calendar is *lighter* than the canvas it floats over, per the
elevation-lightens-in-dark rule.

**RTL mirrors by layout, numerals never do.** The whole grid, the weekday order, and the `‹`/`›` control
order mirror under `dir="rtl"` from logical properties alone. Three things never mirror: every **day
numeral** renders inside a `dir="ltr"` span with `numberingSystem: "latn"` (a `15`, never a `١٥`, is the
authoritative day number); the `Calendar`/chevron **glyphs** keep orientation (the chevrons only
`rtl:rotate-180` to point the correct way); and any **amount or code** a host paints into a cell stays
`latn` `dir="ltr"`. The Hijri sub-label is the single deliberate Arabic-Indic exception, and only ever as a
secondary line. Week start is a **region** setting (`weekStartsOn`), independent of interface language — an
English UI in Kuwait is Sunday-first.

# Do / Don't

| Do | Don't |
|---|---|
| Keep `month` controlled and drive navigation from the host | Let the primitive own navigation and diverge from the URL/query state |
| Force `latn` `dir="ltr"` on every day numeral | Render Eastern-Arabic-Indic digits for the primary day number |
| Set `weekStartsOn` from region, defaulting Gulf to Sunday | Derive week start from interface language |
| Use `accent` for selection/today only | Use `accent` to signal an event's importance or urgency |
| Plot event tone from the host's assigned status | Wash a whole day in `negative` for a raw monetary magnitude |
| Ship skeleton cells at final dimensions when `loading` | Show a spinner that collapses the grid and shifts layout |
| Keep selection and events modes on separate instances | Make one grid both select dates and plot events |
| Let the host own the day-detail popover and legend | Bake a category legend or event sheet into the primitive |

# Usage & Composition

**In a `PeriodPicker` "jump to date" popover** — the picker owns navigation state; the primitive renders:

```tsx
// components/ui/period-picker.tsx (excerpt)
<Popover>
  <PopoverTrigger asChild><Button variant="outline" size="sm">{label}</Button></PopoverTrigger>
  <PopoverContent className="p-2">   {/* surface-2: shadow-sm + border-ink-6 + rounded-lg */}
    <Calendar
      mode="range"
      month={visibleMonth}
      onMonthChange={setVisibleMonth}
      selected={range}
      onSelect={(value) => setRange(value as DateRange)}
    />
  </PopoverContent>
</Popover>
```

**As the Financial Calendar screen's month grid** — read-only, events-plotting. This spec supplies the
grid; [`../../frontend/CALENDAR.md`](../../frontend/CALENDAR.md) supplies the `CalendarEvent` projection,
the "+N more" day popover, the detail sheet, and the category legend:

```tsx
<Calendar
  mode="events"
  layout="month"
  month={visibleMonth}
  onMonthChange={pushMonthToUrl}
  events={eventsByDay}            // Record<'YYYY-MM-DD', DayEvent[]> derived from CalendarEvent[]
  showHijri={prefs.calendarSystem === 'hijri'}
/>
```

**As a single-date form field** — inside an `AIDraftField`, an AI-suggested date is loaded into the
picker but never auto-committed; the human confirms it through the ordinary form submission (see
[`../../frontend/components/AI_WIDGETS.md → AIDraftField`](../../frontend/components/AI_WIDGETS.md)):

```tsx
<AIDraftField label={t('invoice.dueDate')} suggestion={dueDateSuggestion} accepted={accepted} {...handlers}>
  <Popover>
    <PopoverTrigger asChild><Button variant="outline">{formatDate(value)}</Button></PopoverTrigger>
    <PopoverContent className="p-2">
      <Calendar mode="single" month={month} onMonthChange={setMonth} selected={value} onSelect={setValue} isDateDisabled={isFuture} />
    </PopoverContent>
  </Popover>
</AIDraftField>
```

The grid stays a controlled, host-driven primitive in every case: one selection model, one keyboard
contract, one set of tokens, three surfaces.

# End of Document
