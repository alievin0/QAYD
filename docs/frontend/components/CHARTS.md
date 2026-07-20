# Charts — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / CHARTS
---

# Purpose

QAYD is a financial product, and a chart in a financial product is not decoration — it is an argument about a
number a CFO will act on. This document is the depth specification for `components/charts/*`: the thin QAYD
wrappers — sparkline, bar, line, area, waterfall, donut/pie, combo, and the AI forecast chart — that the
Dashboard, the AI Command Center, and the financial statements draw their visualizations from. It takes the
`components/charts/` entry named in [`../README.md → Repository & Folder Structure`](../README.md) and the
`TrendSparkline` catalogued in [`../COMPONENT_LIBRARY.md → TrendSparkline`](../COMPONENT_LIBRARY.md) and
expands both into the complete contract every chart in the application obeys.

The governing rule, from which most of this document follows, is stated once in
[`../DESIGN_LANGUAGE.md → Imagery & Illustration stance → Data visualization`](../DESIGN_LANGUAGE.md) and
repeated in [`../README.md`](../README.md): **chart color always comes from the design-token chart palette,
never an ad hoc hex per chart.** A chart draws from the ink scale, the one accent, and the semantic financial
set — and nothing else. There is no categorical rainbow in QAYD. A two-series comparison is `accent` against
`ink`; a signed metric is `positive`/`negative`; a breakdown that genuinely needs more than two or three
series varies *value* (light-to-dark tints of the accent) before it reaches for a second hue, and falls back
to direct data labels over a legend once a chart would otherwise need a fourth or fifth color to stay
legible. This is not a stylistic preference a screen may override; it is the palette contract the wrappers
enforce so no screen *can* introduce a raw hex.

Two platform constraints from `../README.md` shape every chart. **AI is visible, never silent**: an
AI-produced series — a cash forecast, a variance projection — is rendered as visibly distinct from actuals
(the accent, a "Forecast" label, a confidence band), never styled to look like committed history, and it
carries its confidence. And **the frontend computes nothing that will be trusted**: a chart plots the numbers
the API returned; it never derives a total, a margin, or a forecast client-side.

The charts layer is to Recharts what `lib/api` is to `fetch`: the **only** place the underlying
visualization primitive is touched. Screens compose `<BarChart>`, `<LineChart>`, `<ForecastChart>` from
`components/charts/`; they never import Recharts (or any chart primitive) directly, because doing so would
route around the palette, the Intl formatting, the RTL mirroring, the reduced-motion gating, and the
accessibility fallback that these wrappers exist to guarantee.

# Anatomy & Variants

## The wrapper stack

```
<ChartContainer>                 ← QAYD shell: token palette injection, RTL, a11y region, data-table fallback,
  │                                 reduced-motion, empty/loading/error, tooltip/axis/grid styling from tokens
  └── <BarChart|LineChart|AreaChart|WaterfallChart|DonutChart|ComboChart|ForecastChart>
        └── Recharts primitives  ← never imported by a screen; only by these wrappers
```

`ChartContainer` (`components/charts/chart-container.tsx`) is the shared shell every chart wrapper renders
inside. It owns everything that must be identical across chart types: it resolves the token palette for the
active theme, sets the chart's reading direction, injects the token-driven axis/grid/tooltip styling, renders
the mandatory accessible `<figure>`/`<table>` fallback, gates entrance animation on `prefers-reduced-motion`,
and switches between loading/empty/error/loaded states. A chart type wrapper supplies only the marks (bars,
lines, arcs) and the data-to-mark mapping; it never re-implements the shell.

`TrendSparkline` (`../COMPONENT_LIBRARY.md → TrendSparkline`) is the deliberate exception: a 28–40px inline
trend is a handful of SVG path commands, so it is hand-drawn without Recharts and without the full
`ChartContainer` shell, to keep a decoration-adjacent element off the charting library's weight budget. Every
*other* chart goes through `ChartContainer`.

## Chart types

| Wrapper | Use | Default palette treatment |
|---|---|---|
| `TrendSparkline` | Inline micro-trend in a `KpiTile` or table cell | Single `accent` (or `success`/`danger`/`ink`) stroke, no axes |
| `BarChart` | Category comparison (revenue by cost center, spend by vendor) | 1 series = `accent`; 2 series = `accent` vs `ink`; 3+ = accent value-ramp |
| `LineChart` | A metric over time (cash position, headcount) | `accent` line; a second series in `ink` |
| `AreaChart` | A cumulative or volume-over-time (cash runway, AR balance) | `accent` fill at low opacity over the `accent` line |
| `WaterfallChart` | A bridge from one figure to another (opening → closing cash, budget → actual) | `positive` up-steps, `negative` down-steps, `ink` start/end totals |
| `DonutChart` / `PieChart` | A part-to-whole breakdown ≤ 5 slices (expense mix, asset composition) | Accent value-ramp + a final `ink` "Other"; direct labels, not a legend, past 4 |
| `ComboChart` | A bar metric with a line overlay (actuals bars + forecast/target line) | Bars `accent`/`ink`; the line `accent-strong` dashed for a target |
| `ForecastChart` | Actuals + an AI projection with a confidence band | Actuals solid `ink`/`accent`; forecast dashed `accent`; band `accent` at low opacity |

## The chart palette

The palette is a token module — `lib/charts/palette.ts` — not a set of literals in each chart. It exposes
exactly the colors the design language permits a chart to use, resolved from the CSS variables so a token edit
re-skins every chart at once and dark mode is automatic:

```ts
// lib/charts/palette.ts
// The ONLY colors a QAYD chart may use. No chart file references a hex or a numbered Tailwind step directly.
export const chartPalette = {
  // Two-series comparison — the common case.
  primary:   'var(--qayd-accent-600)',   // the series that matters (this period, actual)
  secondary: 'var(--qayd-ink-500)',      // the reference series (last period, budget)

  // Sequential (part-to-whole, ≥3 related categories): tints of the ONE accent, dark→light,
  // never a second hue. Consumed in order; a chart needing a 6th step should switch to
  // direct labels over a legend instead of extending the ramp past legibility.
  sequential: [
    'var(--qayd-accent-700)',
    'var(--qayd-accent-600)',
    'var(--qayd-accent-500)',
    'var(--qayd-accent-100)',
  ],
  other: 'var(--qayd-ink-300)',          // the terminal "Other / remainder" slice or bar

  // Diverging / semantic — a GENUINELY signed metric only (variance, cash in vs out, gain vs loss).
  // Never applied to a raw debit or credit (see the debit/credit rule below).
  positive: 'var(--qayd-success-600)',
  negative: 'var(--qayd-danger-600)',

  // Chart chrome — axes, gridlines, tooltip.
  axis:    'var(--qayd-ink-500)',
  grid:    'var(--qayd-ink-150)',
  tooltipBg:     'var(--qayd-surface-raised)',
  tooltipBorder: 'var(--qayd-ink-150)',
  tooltipText:   'var(--qayd-ink-950)',
} as const;
```

### Palette usage rules

These are the rules `ChartContainer` and each wrapper enforce; a chart that violates one is a review reject,
not a taste debate:

1. **One series → `primary` (`accent`).** A single line, a single bar series, a single sparkline is the
   accent. It is the one thing on screen; it gets the one color.
2. **Two series → `primary` vs `secondary` (accent vs ink).** Actual vs. budget, this period vs. last: the
   series that carries the argument is `accent`; the reference is a neutral `ink`. Two accents competing is a
   hierarchy error, not a palette one.
3. **Three-plus related categories → sequential value-ramp, not new hues.** A cost-center breakdown uses tints
   of the accent (dark→light) in the `sequential` order, because the categories are *the same kind of thing*
   and varying value keeps them one family. A second hue is never introduced to distinguish members of one
   category set.
4. **At the 4th–5th series, switch channel, not color.** Once a chart would need a fourth or fifth
   distinguishable color to stay legible, it switches to **direct data labels over a legend** (label each
   line/slice in place) rather than extending the ramp into indistinguishable tints or reaching for a rainbow.
   Legibility is bought with labels, position, and small multiples — never with a wider palette.
5. **Semantic color only for a genuinely signed metric.** `positive`/`negative` appear on variance, cash
   in/out, gain/loss — numbers where the sign truly means "better/worse." They never color a raw
   `debit`/`credit` (see below).
6. **Semantic is never color-alone.** An up/down or positive/negative encoding always pairs color with a
   second channel — a `+`/`−` sign, an up/down glyph, a label, or bar direction — so the meaning survives
   grayscale and colorblind vision (see Accessibility).
7. **Chrome comes from the ink scale.** Axes are `ink-500`, gridlines `ink-150`, tooltip surface
   `surface-raised` on an `ink-150` border — never a tinted or accented axis.

### The debit/credit rule (restated for charts)

Exactly as in `../DESIGN_LANGUAGE.md → The debit/credit rule`: a chart **never** colors a raw debit red or a
raw credit green. Debit-normal and credit-normal are structural properties of an account type, not judgments
of good and bad — revenue postings are credits and are good news. Semantic `positive`/`negative` on a chart
is reserved for the layer *above* the raw ledger: Net Income on a P&L trend, a budget variance, a
period-over-period delta. A chart of gross debit and credit volumes plots both in neutral accent/ink,
distinguished by position and label, never by hue.

## Implementation

`ChartContainer` is the shell; a type wrapper supplies only the marks. The shell is where the palette, the
`Intl` formatters, the reading direction, the reduced-motion gate, the four states, and the mandatory tabular
fallback all live — so a wrapper is small and cannot route around any of them.

```tsx
// components/charts/chart-container.tsx
'use client';

import { useLocale } from 'next-intl';
import { ResponsiveContainer } from 'recharts';
import { chartPalette } from '@/lib/charts/palette';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { Skeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/shared/empty-state';
import { ErrorState } from '@/components/shared/error-state';
import { ChartDataTable } from '@/components/charts/chart-data-table';
import type { ApiError } from '@/types/api';

export interface ChartContainerProps {
  title: string;                                   // accessible name (translation key)
  state?: 'loading' | 'ready' | 'empty' | 'error';
  error?: ApiError | null;
  height?: number;
  dataTable: { columns: string[]; rows: (string | number)[][] }; // mandatory a11y fallback
  children: React.ReactNode;                       // the Recharts tree supplied by the type wrapper
}

export function ChartContainer({ title, state = 'ready', error, height = 240, dataTable, children }: ChartContainerProps) {
  const locale = useLocale();
  const dir = locale === 'ar' ? 'rtl' : 'ltr';
  const reducedMotion = useReducedMotion();

  if (state === 'loading') return <Skeleton className="w-full rounded-lg" style={{ height }} />;
  if (state === 'error') return <ErrorState error={error ?? undefined} />;
  if (state === 'empty') return <EmptyState title={title} description="No data for this period" />;

  return (
    <figure aria-label={title} className="m-0">
      {/* Recharts reads layout direction from dir; numerals inside stay ltr via the formatters below. */}
      <div dir={dir} style={{ ['--chart-anim' as string]: reducedMotion ? '0ms' : '360ms' }}>
        <ResponsiveContainer width="100%" height={height}>{children as React.ReactElement}</ResponsiveContainer>
      </div>
      {/* Always rendered: the screen-reader- and keyboard-reachable numeric equivalent. */}
      <ChartDataTable caption={title} columns={dataTable.columns} rows={dataTable.rows} />
    </figure>
  );
}
```

A category chart — the palette is assigned by the wrapper from the series count, never passed in by the
caller, which is what makes palette rules 1–4 impossible to violate at a call site:

```tsx
// components/charts/bar-chart.tsx
'use client';

import { BarChart as RBarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts';
import { ChartContainer, type ChartContainerProps } from './chart-container';
import { chartPalette } from '@/lib/charts/palette';
import { useChartFormatters } from '@/hooks/charts/use-chart-formatters';

interface Series { key: string; label: string }

/** 1 series → accent; 2 → accent + ink; 3+ → sequential accent value-ramp. Never a raw color. */
function colorFor(index: number, count: number): string {
  if (count <= 1) return chartPalette.primary;
  if (count === 2) return index === 0 ? chartPalette.primary : chartPalette.secondary;
  return chartPalette.sequential[Math.min(index, chartPalette.sequential.length - 1)];
}

export function BarChart({
  data, series, valueFormat, xFormat, ...shell
}: {
  data: Record<string, number | string>[];
  series: Series[];
  valueFormat: { type: 'currency' | 'number' | 'percent'; currencyCode?: string };
  xFormat?: { type: 'date' | 'category'; pattern?: string };
} & Omit<ChartContainerProps, 'children' | 'dataTable'>) {
  const { formatValue, formatX, tooltipContent } = useChartFormatters(valueFormat, xFormat);
  const dataTable = {
    columns: ['', ...series.map((s) => s.label)],
    rows: data.map((d) => [formatX(d.label), ...series.map((s) => formatValue(Number(d[s.key])))]),
  };
  return (
    <ChartContainer {...shell} dataTable={dataTable}>
      <RBarChart data={data} barCategoryGap="24%">
        <CartesianGrid stroke={chartPalette.grid} vertical={false} />
        <XAxis dataKey="label" tick={{ fill: chartPalette.axis, fontSize: 12 }} tickFormatter={formatX} tickLine={false} />
        <YAxis tick={{ fill: chartPalette.axis, fontSize: 12 }} tickFormatter={formatValue} width={72} tickLine={false} axisLine={false} />
        <Tooltip content={tooltipContent} cursor={{ fill: chartPalette.grid, opacity: 0.4 }} />
        {series.map((s, i) => (
          <Bar key={s.key} dataKey={s.key} name={s.label} fill={colorFor(i, series.length)} radius={[4, 4, 0, 0]}
               isAnimationActive={false /* entrance handled/suppressed via --chart-anim; no per-bar bounce */} />
        ))}
      </RBarChart>
    </ChartContainer>
  );
}
```

The AI forecast chart — actuals never blur into projection, and the confidence band is real data in both the
shaded interval and the tabular fallback:

```tsx
// components/charts/forecast-chart.tsx
'use client';

import { ComposedChart, Area, Line, XAxis, YAxis, CartesianGrid, Tooltip, ReferenceLine } from 'recharts';
import { ChartContainer } from './chart-container';
import { chartPalette } from '@/lib/charts/palette';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { useChartFormatters } from '@/hooks/charts/use-chart-formatters';

interface ForecastPoint { x: string; value: number; lower: number; upper: number }

export function ForecastChart({
  actual, forecast, confidence, reasoning, valueFormat, title, ...shell
}: {
  actual: { x: string; value: number }[];
  forecast: ForecastPoint[];
  confidence: number;               // 0–1 (caller pre-normalizes; see below)
  reasoning: string;
  valueFormat: { type: 'currency' | 'number' | 'percent'; currencyCode?: string };
  title: string;
} & { height?: number }) {
  const { formatValue, formatX, tooltipContent } = useChartFormatters(valueFormat, { type: 'date' });
  const boundary = actual.at(-1)?.x;               // the "today" line between committed history and projection
  const merged = [
    ...actual.map((a) => ({ x: a.x, actual: a.value })),
    ...forecast.map((f) => ({ x: f.x, forecast: f.value, band: [f.lower, f.upper] as [number, number] })),
  ];
  const dataTable = {
    columns: ['', 'Actual', 'Forecast', 'Lower', 'Upper'],
    rows: [
      ...actual.map((a) => [formatX(a.x), formatValue(a.value), '—', '—', '—']),
      ...forecast.map((f) => [formatX(f.x), '—', formatValue(f.value), formatValue(f.lower), formatValue(f.upper)]),
    ],
  };
  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <h3 className="font-display text-title">{title}</h3>
        <ConfidenceBadge confidence={confidence} reasoning={reasoning} size="sm" />
      </div>
      <ChartContainer {...shell} title={title} dataTable={dataTable}>
        <ComposedChart data={merged}>
          <CartesianGrid stroke={chartPalette.grid} vertical={false} />
          <XAxis dataKey="x" tick={{ fill: chartPalette.axis, fontSize: 12 }} tickFormatter={formatX} tickLine={false} />
          <YAxis tick={{ fill: chartPalette.axis, fontSize: 12 }} tickFormatter={formatValue} width={72} tickLine={false} axisLine={false} />
          <Tooltip content={tooltipContent} />
          {/* Shaded confidence interval — low opacity accent, always paired with the lower/upper table columns. */}
          <Area dataKey="band" stroke="none" fill={chartPalette.primary} fillOpacity={0.12} isAnimationActive={false} />
          {/* Committed history: solid neutral ink. */}
          <Line dataKey="actual" stroke={chartPalette.secondary} strokeWidth={1.5} dot={false} isAnimationActive={false} />
          {/* AI projection: dashed accent — visibly not a fact. */}
          <Line dataKey="forecast" stroke={chartPalette.primary} strokeWidth={1.5} strokeDasharray="5 4" dot={false} isAnimationActive={false} />
          {boundary && <ReferenceLine x={boundary} stroke={chartPalette.grid} strokeWidth={1} />}
        </ComposedChart>
      </ChartContainer>
    </div>
  );
}
```

**Usage**

```tsx
// A dashboard cash-forecast panel — the AI series is normalized, labelled, and never mistaken for actuals.
<ForecastChart
  title={t('cashForecast.title')}
  actual={cash.actual}
  forecast={cash.forecast}
  confidence={normalizeConfidence(cash.confidence_score, 'percentage')}
  reasoning={cash.reasoning}
  valueFormat={{ type: 'currency', currencyCode: 'KWD' }}
/>

// A cost-center breakdown — three series, so a value-ramp of the one accent, assigned by the wrapper.
<BarChart
  title={t('spendByCostCenter.title')}
  data={spend.rows}
  series={[{ key: 'q1', label: 'Q1' }, { key: 'q2', label: 'Q2' }, { key: 'q3', label: 'Q3' }]}
  valueFormat={{ type: 'currency', currencyCode: 'KWD' }}
  height={280}
/>
```

`useChartFormatters` is the one place a chart's axis, tick, tooltip, and data-label numbers are formatted; it
wraps `formatAmount` for money (Latin numerals, `dir="ltr"`, KWD/BHD/OMR at 3 decimals) and `Intl` for
dates/percents, so a chart can no more localize a numeral system by accident than a `DataTable` cell can.

# Props / API

## `ChartContainer`

The shared shell props every chart wrapper spreads or forwards:

| Prop | Type | Required | Description |
|---|---|---|---|
| `data` | `TDatum[]` | yes | The already-computed series data from the API; the chart never derives values from it. |
| `state` | `'loading' \| 'ready' \| 'empty' \| 'error'` | no | Usually derived from the parent query; forces the corresponding shell state. |
| `title` | `string` | yes | The chart's accessible name (translation key); rendered as the `<figure>`'s caption/label. |
| `height` | `number` | no, default `240` | Chart body height in px; width is always 100% of the container. |
| `valueFormat` | `{ type: 'currency' \| 'number' \| 'percent'; currencyCode?: string }` | yes | Drives axis tick, tooltip, and data-label formatting via `Intl` (see i18n). |
| `xFormat` | `{ type: 'date' \| 'category'; pattern?: string }` | no | Category/time axis formatting. |
| `showLegend` | `boolean` | no | Off by default; a ≤2-series chart labels in place instead. |
| `error` | `ApiError \| null` | no | Surfaces the request id and Retry in the error state. |
| `dataTable` | `{ columns: string[]; rows: (string \| number)[][] }` | yes | The mandatory accessible tabular equivalent (see Accessibility) — never optional. |

## `BarChart`

| Prop | Type | Required | Description |
|---|---|---|---|
| `data` | `{ label: string; [seriesKey: string]: number \| string }[]` | yes | One row per category. |
| `series` | `{ key: string; label: string }[]` | yes | 1–3 entries; the wrapper assigns palette colors by rule (accent / accent+ink / value-ramp) — the caller never passes a color. |
| `orientation` | `'vertical' \| 'horizontal'` | no, default `'vertical'` | Horizontal for long category names. |
| `stacked` | `boolean` | no | Stacks a value-ramp series set into one bar (composition over time). |
| `valueFormat` / `xFormat` / `title` / `height` / `dataTable` | — | — | Forwarded to `ChartContainer`. |

## `LineChart` / `AreaChart`

| Prop | Type | Required | Description |
|---|---|---|---|
| `data` | `{ x: string; [seriesKey: string]: number \| string }[]` | yes | One row per time point. |
| `series` | `{ key: string; label: string; emphasis?: 'primary' \| 'reference' }[]` | yes | ≤2 for a legend-free chart; `emphasis` maps to accent vs ink. |
| `area` | `boolean` | no (`AreaChart` sets true) | Fills under the line with the accent at low opacity. |
| `xFormat` | `{ type: 'date'; pattern?: string }` | yes | Time axis. |

## `WaterfallChart`

| Prop | Type | Required | Description |
|---|---|---|---|
| `data` | `{ label: string; value: number; kind: 'total' \| 'increase' \| 'decrease' }[]` | yes | The API supplies each step's signed value and role; the chart does not infer sign. |
| `valueFormat` | — | yes | Bridge steps colored `positive`/`negative`, totals `ink`. |

## `DonutChart` / `PieChart`

| Prop | Type | Required | Description |
|---|---|---|---|
| `data` | `{ label: string; value: number }[]` | yes | ≤5 slices; a caller with more must pre-aggregate a server-side "Other." |
| `centerLabel` | `{ value: string; caption: string }` | no | Donut center (total). |
| `labelStrategy` | `'legend' \| 'direct'` | no, default `'direct'` | `'direct'` labels slices in place past 4 slices, per palette rule 4. |

## `ForecastChart` (AI)

The chart that renders an AI projection alongside actuals — the visual home of the confidence-band contract.

| Prop | Type | Required | Description |
|---|---|---|---|
| `actual` | `{ x: string; value: number }[]` | yes | Committed history — solid line. |
| `forecast` | `{ x: string; value: number; lower: number; upper: number }[]` | yes | The AI projection with its confidence interval per point. |
| `confidence` | `number` | yes | 0–1 overall forecast confidence; rendered as a `ConfidenceBadge` in the chart header. |
| `reasoning` | `string` | yes | The model's rationale, reachable from the badge tooltip. |
| `bandLabel` | `string` | no, default "Confidence range" | Accessible label for the shaded interval. |

# States

Every chart renders a designed state for each phase — never a raw blank canvas or a bare spinner.

| State | Rendering |
|---|---|
| **Loading** | A shape-matched skeleton — a faint gridded rectangle at the chart's height with a slow shimmer — so the card does not reflow when data arrives. Never a centered spinner. |
| **Ready** | The chart. Under `prefers-reduced-motion: no-preference`, marks draw in once (`motion.slow`); under reduce, they appear at final state with no entrance animation (see Behavior). |
| **Empty** | A quiet in-frame message ("No data for this period"), the axes still drawn so the reader understands *what* is empty, plus (where relevant) a hint to widen the range — never a blank box, never a misleading flat line at zero. |
| **Error** | An in-frame `ErrorState` with the request id and a Retry that re-runs the parent query; contained to the one chart, siblings stay live. |
| **AI unavailable** | A `ForecastChart` whose AI endpoint returned `503` renders the actuals alone plus a distinct "Forecast temporarily unavailable" note (not a generic error, not an infinite spinner), backing off on `Retry-After` — the committed history is never hidden just because the projection failed. |
| **Partial** | A series with gaps renders the gaps as breaks (no interpolation across missing points) rather than drawing a confident line through data that does not exist. |

The empty/loading/error handoff is delegated to `Skeleton`/`EmptyState`/`ErrorState` inside `ChartContainer`
exactly as the table and card systems delegate theirs — the wrapper decides *which* state and hands off.

# Behavior & Interaction

## Tooltips and hover

Hovering (or keyboard-focusing) a data point shows a tooltip styled entirely from tokens — `surface-raised`
background, `ink-150` border, `shadow-md`, `ink-950` text — listing the point's x label and each series'
formatted value. Values in the tooltip are formatted through the same `Intl` path as the axis (money via
`formatAmount`, `dir="ltr"`, `latn`), never a raw number. The tooltip is calm: a 300ms open, no bounce, no
crosshair animation beyond a thin `ink-150` reference line. On a multi-series chart the hovered series is
subtly emphasized (siblings drop to a lower opacity) rather than recolored, so the palette contract holds
during interaction.

## Drill-in

A chart on a dashboard that stands in for a deeper view is clickable at the series/point level: clicking a
cost-center bar navigates to the ledger filtered to that cost center and period; clicking a forecast month
opens the cash-flow detail for it. The whole chart is never a single opaque click target — the interactive
units are the marks, each a real focusable element with an accessible name, so keyboard and pointer reach the
same drill-in.

## Reduced motion

Chart entrance animation is purely explanatory and entirely optional. Every wrapper reads `useReducedMotion()`
and, when motion is reduced, renders marks at their final state with **no** draw-in — not a shorter animation,
no animation, consistent with the library-wide "reduced motion means no motion, not quick motion" rule
(`../COMPONENT_LIBRARY.md → Edge Cases`). This mirrors `TrendSparkline`, whose `pathLength` draw-in is already
gated the same way. Tooltips and hover emphasis are state changes, not decorative motion, and remain (they
update instantly under reduced motion).

## Realtime updates

A chart bound to a live figure (the AI Command Center's cash-flow status) patches its series from the Reverb
event via `queryClient.setQueryData` and transitions the affected mark to its new position over `motion.base`
under normal motion, or snaps instantly under reduced motion — never a full redraw flash, never a toast.

# Accessibility

Charts are the hardest surface in the app to make accessible, and QAYD treats the accessible representation as
part of the chart, not an afterthought. Inherits the WCAG 2.1 AA baseline of
[`../ACCESSIBILITY.md`](../ACCESSIBILITY.md); what charts must guarantee:

- **A mandatory tabular fallback.** Every `ChartContainer` renders a visually-hidden but screen-reader- and
  keyboard-reachable `<table>` (the `dataTable` prop) carrying the exact numbers the chart plots — this is
  required, never optional, because a purely visual chart is invisible to a screen-reader user. A "view as
  table" toggle also exposes it visibly for any user who prefers the numbers.
- **The chart is a labelled `<figure>`** with an `aria-label`/caption naming what it shows ("Cash position,
  last 12 months"); it is not a bare `<svg>`.
- **Never color-alone.** Every meaning a chart encodes in color also rides a second channel: a positive/
  negative bar carries its sign and points up/down; a two-series line distinguishes by a solid/dashed stroke
  and a direct end-of-line label, not only by accent-vs-ink; a donut slice is labelled in place. A grayscale
  print or a colorblind reader loses nothing — the same designed invariant `AmountCell` and `StatusPill`
  guarantee for tables and cards.
- **Interactive marks are real controls.** A clickable bar/point/slice is keyboard-focusable with an
  accessible name and `Enter` activation; hover-only tooltips are also focus-reachable.
- **The AI forecast's confidence and reasoning are text**, in the chart header (`ConfidenceBadge` +
  focus-reachable reasoning tooltip), and the confidence band is announced through the tabular fallback's
  lower/upper columns — never conveyed by the shaded area alone.
- **Reduced motion** removes all entrance animation, per Behavior.
- **Contrast.** Accent and ink series, axis, and gridline colors meet the AA/1.4.11 non-text contrast targets
  in both themes; the palette tokens are chosen so an `accent` line clears contrast against the chart's
  `surface` background in light and dark alike (`../DESIGN_LANGUAGE.md → Contrast targets`).

# Theming, Dark Mode & RTL

**Palette from tokens, always.** Every color a chart paints is a `chartPalette` entry resolving to a
`--qayd-*` CSS variable; a chart file contains no hex, no numbered Tailwind step, and no `dark:` variant
against a raw color. This is the load-bearing rule of the whole document: because color comes only from the
palette module, a chart *cannot* introduce a rogue hue, and re-theming is a single token edit.

**Dark mode** is a pure remap. `data-theme="dark"` on `<html>` re-points every `--qayd-*` variable
(`../COMPONENT_LIBRARY.md → Foundations`), so the identical chart renders correctly dark with no chart-level
conditional — the accent lifts rather than saturates against the darker canvas, axes and gridlines re-derive
from the dark ink scale, and the tooltip surface follows `surface-raised`. Because SVG `stroke`/`fill` accept
CSS variables directly, the same `var(--qayd-accent-600)` string is correct in both themes; where chart code
needs a *computed* JS color (a canvas fill, a gradient stop), it imports the resolved values from
`lib/tokens.ts` per `../DESIGN_LANGUAGE.md → Design Tokens`, never a hard-coded hex.

**RTL** mirrors the chart's *structure* while leaving its *numbers* upright:

- **Axis and category order mirror.** Under `dir="rtl"` a time axis reads right-to-left (earliest at the
  visual right), category bars order from the right, and the y-axis moves to the visual right edge — the chart
  reads in the same direction as the surrounding Arabic layout. `ChartContainer` sets the Recharts layout
  direction from the resolved `dir`, never a hardcoded left/right.
- **Numerals and currency codes never mirror.** Axis ticks, data labels, and tooltip values render `dir="ltr"`
  with `numberingSystem: 'latn'`, so `84,210.500` and `KWD` read left-to-right inside a right-to-left chart —
  the same rule `AmountCell` enforces (`../COMPONENT_LIBRARY.md → Theming & RTL`, rule 2).
- **Directional legend/label placement** follows logical edges; a "Forecast →" annotation flips its arrow in
  RTL, while a `+`/`−` sign or an up/down glyph never flips (flipping it would invert its meaning).

# i18n & Formatting

- **Strings** — chart titles, axis/series labels, legend entries, the "Forecast"/"Actual"/"Confidence range"
  labels, empty/error copy — come from next-intl `useTranslations`, with a key in both `en` and `ar` or CI
  fails.
- **Money** on axes, tooltips, and data labels routes through `formatAmount` (`tabular-nums`, `dir="ltr"`,
  `numberingSystem: 'latn'`, KWD/BHD/OMR at 3 decimals) — never a generic `Intl.NumberFormat` call that would
  localize the numeral system. Large-magnitude axis ticks may use a compact form (`KD 84.2k`) via
  `Intl.NumberFormat`'s `notation: 'compact'` while keeping Latin numerals and the ISO code, never a symbol
  glyph.
- **Percentages and counts** format through `Intl` parameterized by locale with `dir="ltr"` numerals.
- **Dates** on a time axis format through `Intl.DateTimeFormat` via next-intl `useFormatter`, respecting the
  active locale's month/day names while keeping numerals `dir="ltr"`.
- **Signed values** carry a real `+`/`−` sign in labels and tooltips so polarity is text, not color — a
  variance chart's `+2.4%` reads correctly in grayscale.

# Testing

Per `../COMPONENT_LIBRARY.md → Testing`:

- **Storybook.** `BarChart`, `LineChart`, `AreaChart`, `WaterfallChart`, `DonutChart`, `ComboChart`,
  `ForecastChart`, and `TrendSparkline` each ship a `.stories.tsx` covering 1-series / 2-series / value-ramp /
  direct-label permutations and the loading/empty/error/AI-unavailable states, inspectable in all four
  LTR/RTL × light/dark combinations via the global decorator. A dedicated "palette" story renders every
  wrapper at its maximum legitimate series count to make a stray hue visually obvious in review.
- **Vitest.** The logic-bearing pieces: the palette assigner (1→accent, 2→accent+ink, 3+→value-ramp in the
  documented order, 4th+→direct-label mode — asserting no chart is ever handed a raw color); the
  `WaterfallChart` sign→color mapping (`increase`→positive, `decrease`→negative, `total`→ink); the axis/tooltip
  formatter producing Latin numerals and 3-decimal KWD regardless of locale; the `ForecastChart` band mapping
  (lower/upper into the shaded interval and into the tabular fallback columns).
- **Playwright.** A drill-in from a dashboard bar to the correctly-filtered ledger URL; the RTL axis-direction
  regression (time axis reads right-to-left, numerals stay upright) as a visual snapshot; the "view as table"
  toggle exposing numbers that match the plotted series.
- **Accessibility CI.** `axe-core` runs against every chart story; a chart missing its `<figure>` label, a
  color-only encoding with no second channel, or an absent tabular fallback fails the build. Because charts
  are the highest color-only-risk surface, the "not color alone" check is treated as a release gate, not an
  advisory.

# Edge Cases

- **More categories than the palette allows.** A breakdown with six cost centers does not extend the accent
  ramp into six indistinguishable tints and never reaches for a rainbow; it switches to `labelStrategy="direct"`
  (or small multiples), or the caller pre-aggregates a server-side "Other" slice colored `ink-300` — palette
  rule 4 is enforced, not advisory.
- **A single data point.** A "trend" with one point renders that point as a labelled dot, not a
  zero-length line implying a flat trend; a sparkline with `< 2` points renders nothing and its `KpiTile`
  simply omits it.
- **All-zero or all-equal series.** A flat series renders a real flat line at its actual value with the value
  labelled, distinct from the empty state's "no data" — a genuine run of zeros is data, absence of data is
  not, and the two never look alike.
- **Negative values in a part-to-whole chart.** A donut/pie cannot represent negatives; a dataset containing a
  negative is rendered as a diverging bar chart instead (the wrapper refuses to draw a misleading pie),
  because a "share of total" is meaningless when a component is negative.
- **AI forecast with a wide confidence band.** A low-confidence forecast draws a correspondingly wide shaded
  band and a "Low confidence" `ConfidenceBadge`; the band is never suppressed to make the projection look more
  certain than it is, and below the platform threshold the forecast line is dashed and de-emphasized rather
  than presented as a confident continuation of actuals (`../DESIGN_LANGUAGE.md → Principle 7`).
- **Forecast vs. actuals must never blur.** The boundary between committed history and AI projection is always
  marked — a vertical `ink-150` "today" reference line, a solid→dashed stroke change, and distinct labels — so
  a reader can never mistake the projection for recorded fact (`../README.md → Overview`, point 2).
- **Two confidence scales.** A `ForecastChart`'s `confidence` is normalized through
  `normalizeConfidence(raw, sourceField)` before reaching the badge, exactly as every other AI surface, because
  QAYD's confidence fields are not on one scale (`../COMPONENT_LIBRARY.md → Edge Cases`).
- **Debit/credit volumes.** A chart comparing gross debit and credit volumes colors both in neutral accent/ink
  by position and label, never `positive`/`negative` — coloring a credit green is the exact misinformation the
  debit/credit rule exists to prevent.
- **Reduced motion.** No chart draws in; all marks appear at final state, tooltips and realtime updates snap
  instantly.
- **Print / PDF export.** A chart rendered into a grayscale, no-JS report reads correctly because every color
  encoding is paired with sign, stroke style, position, or a direct label — and the tabular fallback ships in
  the export as the definitive numbers, so a reader who cannot resolve the shaded band still has the
  lower/upper figures.

# End of Document
