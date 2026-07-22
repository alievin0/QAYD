# Chart Library — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / CHART_LIBRARY
---

# Purpose

This is the atomic design-system specification for QAYD's **Chart Library** — the token-driven chart
primitives (`TrendSparkline`, `BarChart`, `LineChart`, `AreaChart`, `DonutChart`, `WaterfallChart`) that
wrap Recharts, plus the shared `ChartContainer` shell and the `chartPalette` token module. It is the
design-system counterpart to the application-level charting system in
[`../../frontend/components/CHARTS.md`](../../frontend/components/CHARTS.md): that document owns the
*behavioral* contract — the wrapper stack, the `ForecastChart` AI confidence-band, drill-in, realtime
updates, and the platform rule that a chart plots the numbers the API returned and derives nothing
client-side. This document does not restate any of that. It owns the **atomic palette-and-token contract**:
the exact tokens each mark, axis, gridline, and tooltip may use; the "vary value before a second hue" rule
that keeps QAYD to one accent; the mandatory accessible data-table fallback; the empty/loading treatment;
and how a chart mirrors in RTL and remaps in dark mode.

The governing rule, stated once in
[`../DESIGN_TOKENS.md → Tokens for Motion & Charts`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md → Chart Palette`](../COLOR_SYSTEM.md), is the load-bearing sentence of the whole
document: **chart color always comes from the design-token chart palette, never an ad-hoc hex per chart.** A
chart draws from the ink scale, the one accent, and the semantic financial set — and nothing else. There is
no categorical rainbow in QAYD. The charts layer is to Recharts what the API client is to `fetch`: the
**only** place the visualization primitive is touched, so no screen can route around the palette, the Intl
formatting, the RTL mirroring, or the accessibility fallback. Screens compose `<BarChart>` / `<LineChart>`
from `components/charts/`; they never import Recharts directly.

> Token-naming note. Application snippets and the `chartPalette` module use an earlier numeric-step palette
> (`--qayd-accent-600`, `--qayd-ink-500`, `--qayd-ink-150`, `--qayd-success-600`, `--qayd-surface-raised`).
> Per [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md), the **canonical** names used here are
> `accent`/`accent-subtle`/`accent-strong`, `ink-1 … ink-12`, and `positive`/`negative`/`warning`. Read a
> draft name as its canonical equivalent (`accent-600` → `accent`, `ink-500` → `ink-9`, `ink-150` →
> `ink-6`, `success-600` → `positive`, `surface-raised` → `ink-1`/`ink-3`). The palette *mechanism* — a
> single token module every chart resolves from — is unchanged; only the token names reconcile.

# Anatomy

Every chart renders inside one shared shell. The shell owns everything that must be identical across chart
types; a type wrapper supplies only the marks and the data-to-mark mapping and cannot route around the shell.

```
ChartContainer (<figure> · aria-label · dir from locale · reduced-motion gate)
├── ResponsiveContainer (Recharts) — width 100%, token height
│   └── <BarChart | LineChart | AreaChart | DonutChart | WaterfallChart>
│       ├── CartesianGrid ── stroke ink-6 · horizontal only
│       ├── XAxis / YAxis ── tick fill ink-9 · no axis line · Intl-formatted, dir="ltr" numerals
│       ├── Tooltip ──────── bg ink-3 · 1px ink-6 · shadow-md · text ink-12
│       └── marks ────────── fill/stroke from chartPalette ONLY (accent / ink / positive / negative)
└── ChartDataTable (mandatory) — visually-hidden, SR- and keyboard-reachable numeric equivalent
```

`ChartContainer` resolves the token palette for the active theme, sets the reading direction, injects the
token-driven axis/grid/tooltip styling, renders the accessible `<figure>`/`<table>` fallback, gates
entrance animation on `prefers-reduced-motion`, and switches loading/empty/error/loaded. `TrendSparkline`
is the deliberate exception: a 28–40px inline trend is a handful of SVG path commands, hand-drawn without
Recharts and without the full shell, to keep a decoration-adjacent element off the library's weight budget —
but it still draws its single stroke from the palette (`accent` or a semantic token), never a raw hex.

# Variants

| Wrapper | Use | Default palette treatment |
|---|---|---|
| `TrendSparkline` | Inline micro-trend in a `KpiTile` or table cell | Single `accent` (or `positive`/`negative`/`ink`) stroke, no axes |
| `BarChart` | Category comparison (revenue by cost center) | 1 series = `accent`; 2 = `accent` vs `ink-7`; 3+ = accent value-ramp |
| `LineChart` | A metric over time (cash position, headcount) | `accent` line; a second series in `ink-7` |
| `AreaChart` | Cumulative / volume-over-time (cash runway, AR balance) | `accent` fill at low opacity over the `accent` line |
| `DonutChart` | Part-to-whole ≤ 5 slices (expense mix, asset composition) | Accent value-ramp + a final `ink` "Other"; direct labels past 4 |
| `WaterfallChart` | A bridge one figure → another (opening → closing cash) | `positive` up-steps, `negative` down-steps, `ink` start/end totals |

## The chart palette (token module)

The palette is a token module — `lib/charts/palette.ts` — not a set of literals in each chart. It exposes
**exactly** the colors the design language permits a chart to use, resolved from CSS variables so a token
edit re-skins every chart at once and dark mode is automatic. SVG `stroke`/`fill` accept CSS variables
directly, so the same string is correct in both themes; where chart code needs a *computed* JS color (a
gradient stop), it imports resolved values from `lib/tokens.ts`, never a hardcoded hex.

```ts
// lib/charts/palette.ts — the ONLY colors a QAYD chart may use. Canonical token names.
export const chartPalette = {
  // Two-series comparison — the common case.
  primary:   'var(--qayd-accent)',        // the series that matters (this period, actual)
  secondary: 'var(--qayd-ink-7)',         // the reference series (last period, budget)

  // Sequential (part-to-whole, ≥3 related categories): tints of the ONE accent, dark→light,
  // never a second hue. A chart needing a 5th step switches to direct labels, not a longer ramp.
  sequential: [
    'var(--qayd-accent-strong)',          // #7A5D22 light
    'var(--qayd-accent)',                 // #9C7A34
    'var(--qayd-chart-accent-mid)',       // sanctioned ramp tint (COLOR_SYSTEM → Sequential)
    'var(--qayd-accent-subtle)',          // #EADFBF
  ],
  other: 'var(--qayd-ink-6)',             // the terminal "Other / remainder" slice or bar

  // Diverging / semantic — a GENUINELY signed metric only (variance, cash in vs out, gain vs loss).
  // Never applied to a raw debit or credit (see the debit/credit rule below).
  positive: 'var(--qayd-positive)',
  negative: 'var(--qayd-negative)',

  // Chart chrome — axes, gridlines, tooltip. Always ink, never tinted or accented.
  axis:          'var(--qayd-ink-9)',
  grid:          'var(--qayd-ink-6)',
  tooltipBg:     'var(--qayd-ink-3)',
  tooltipBorder: 'var(--qayd-ink-6)',
  tooltipText:   'var(--qayd-ink-12)',
} as const;
```

The two anchor ends of the sequential ramp (`accent-subtle`, `accent`/`accent-strong`) are exact tokens; the
intermediate step is the one sanctioned chart-derived tint, defined in
[`../COLOR_SYSTEM.md → Sequential`](../COLOR_SYSTEM.md) and living under the `chart` namespace in
`lib/tokens.ts` so no chart file hardcodes it.

## Palette assignment (rules the wrapper enforces)

The palette is assigned by the wrapper **from the series count**, never passed in by the caller — which is
what makes the rules below impossible to violate at a call site.

```ts
// 1 series → accent; 2 → accent + ink; 3+ → sequential accent value-ramp. Never a raw color.
function colorFor(index: number, count: number): string {
  if (count <= 1) return chartPalette.primary;
  if (count === 2) return index === 0 ? chartPalette.primary : chartPalette.secondary;
  return chartPalette.sequential[Math.min(index, chartPalette.sequential.length - 1)];
}
```

1. **One series → `accent`.** It is the one thing on screen; it gets the one color.
2. **Two series → `accent` vs `ink-7`.** The series that carries the argument is accent; the reference is
   neutral ink. Two accents competing is a hierarchy error, not a palette one.
3. **Three-plus related categories → sequential value-ramp, not new hues.** The categories are the same kind
   of thing; varying value keeps them one family.
4. **At the 4th–5th series, switch channel, not color** — direct data labels over a legend, position, or
   small multiples, never a wider palette.
5. **Semantic color only for a genuinely signed metric** (variance, cash in/out) — never a raw debit/credit.
6. **Semantic is never color-alone** — always paired with a `+`/`−` sign, an up/down glyph, a label, or bar
   direction.
7. **Chrome comes from the ink scale** — axes `ink-9`, gridlines `ink-6`, tooltip `ink-3` on `ink-6`.

### The debit/credit rule (restated for charts)

Exactly as in [`../COLOR_SYSTEM.md → The debit/credit rule`](../COLOR_SYSTEM.md): a chart **never** colors a
raw debit red or a raw credit green — revenue postings are credits and are good news. Semantic
`positive`/`negative` is reserved for the layer *above* the raw ledger (Net Income on a P&L trend, a budget
variance). A chart of gross debit vs. credit volumes plots both in neutral `accent`/`ink`, distinguished by
position and label, never by hue.

## The `ChartContainer` shell (tokens + a11y + states)

```tsx
// components/charts/chart-container.tsx — the shared shell. Owns palette, dir, a11y fallback, states.
'use client';
import { useLocale } from 'next-intl';
import { ResponsiveContainer } from 'recharts';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { Skeleton } from '@/components/ui/skeleton';
import { EmptyState } from '@/components/shared/empty-state';
import { ErrorState } from '@/components/shared/error-state';
import { ChartDataTable } from '@/components/charts/chart-data-table';

export function ChartContainer({ title, state = 'ready', error, height = 240, dataTable, children }: ChartContainerProps) {
  const dir = useLocale() === 'ar' ? 'rtl' : 'ltr';
  const reducedMotion = useReducedMotion();

  if (state === 'loading') return <Skeleton className="w-full rounded-lg bg-ink-4" style={{ height }} />;
  if (state === 'error')   return <ErrorState error={error ?? undefined} />;
  if (state === 'empty')   return <EmptyState title={title} description="No data for this period" />;

  return (
    <figure aria-label={title} className="m-0">
      {/* Recharts reads layout direction from dir; numerals stay ltr via the formatters. */}
      <div dir={dir} style={{ ['--chart-anim' as string]: reducedMotion ? '0ms' : '360ms' }}>
        <ResponsiveContainer width="100%" height={height}>{children as React.ReactElement}</ResponsiveContainer>
      </div>
      {/* ALWAYS rendered: the screen-reader- and keyboard-reachable numeric equivalent. */}
      <ChartDataTable caption={title} columns={dataTable.columns} rows={dataTable.rows} />
    </figure>
  );
}
```

A category wrapper supplies only the marks; the palette is assigned by `colorFor`, never by the caller:

```tsx
// components/charts/bar-chart.tsx — marks only; every color is a chartPalette entry.
'use client';
import { BarChart as RBarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts';
import { ChartContainer } from './chart-container';
import { chartPalette } from '@/lib/charts/palette';
import { useChartFormatters } from '@/hooks/charts/use-chart-formatters';

export function BarChart({ data, series, valueFormat, xFormat, ...shell }: BarChartProps) {
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
          <Bar key={s.key} dataKey={s.key} name={s.label}
               fill={colorFor(i, series.length)} radius={[4, 4, 0, 0]} isAnimationActive={false} />
        ))}
      </RBarChart>
    </ChartContainer>
  );
}
```

`useChartFormatters` is the one place a chart's axis, tick, tooltip, and data-label numbers are formatted; it
wraps `formatAmount` for money (Latin numerals, `dir="ltr"`, KWD/BHD/OMR at 3 decimals) and `Intl` for
dates/percents — so a chart can no more localize a numeral system by accident than a table cell can.

# Props / API

The full wrapper prop surfaces (`BarChart`, `LineChart`, `WaterfallChart`, `DonutChart`, `ForecastChart`,
`ChartContainer`) are owned by
[`../../frontend/components/CHARTS.md → Props / API`](../../frontend/components/CHARTS.md). This section
fixes the props whose values are **palette or token decisions**.

## `ChartContainer`

| Prop | Type | Required | Token / a11y effect |
|---|---|---|---|
| `title` | `string` | yes | The `<figure>` accessible name (translation key). |
| `state` | `'loading' \| 'ready' \| 'empty' \| 'error'` | no | Selects the token-defined shell state. |
| `height` | `number` | no, default `240` | Body height in px; width is always 100%. |
| `dataTable` | `{ columns: string[]; rows: (string \| number)[][] }` | **yes** | The mandatory accessible tabular equivalent — never optional. |

## Series props (palette-bearing)

| Prop | Applies to | Palette effect |
|---|---|---|
| `series` | `BarChart` / `LineChart` / `AreaChart` | 1–3 entries; the wrapper assigns colors by `colorFor` — the caller **never** passes a color. |
| `emphasis` | `LineChart` series | `'primary'` → `accent`, `'reference'` → `ink-7`. |
| `data[].kind` | `WaterfallChart` | `'increase'` → `positive`, `'decrease'` → `negative`, `'total'` → `ink`. The API supplies the sign; the chart never infers it. |
| `labelStrategy` | `DonutChart` | `'direct'` labels slices in place past 4, per palette rule 4. |
| `valueFormat` | all | `{ type: 'currency' \| 'number' \| 'percent'; currencyCode? }` — drives Latin-numeral, `dir="ltr"` formatting. |

# States

Every chart renders a designed, token-defined state for each phase — never a raw blank canvas or a bare
spinner. The behavioral detail (AI-unavailable backoff, partial-series gaps) is the app doc's.

| State | Token treatment |
|---|---|
| **Loading** | A shape-matched `Skeleton` (`bg-ink-4`) at the chart's height with a slow `motion.slow` shimmer, so the card does not reflow when data arrives. Never a centered spinner. |
| **Ready** | The chart. Marks draw in once over `motion.slow` under `prefers-reduced-motion: no-preference`; under reduce, they appear at final state with **no** entrance animation. |
| **Empty** | A quiet in-frame "No data for this period" via `EmptyState`, the axes still drawn (in `ink-9`/`ink-6`) so the reader understands *what* is empty — never a blank box, never a misleading flat line at zero. |
| **Error** | An in-frame `ErrorState` with the request id in `code-sm ink-9` and a Retry; contained to the one chart, siblings stay live. |
| **Partial** | A series with gaps renders the gaps as breaks (no interpolation across missing points) rather than drawing a confident line through data that does not exist. |
| **Hover / focus** | Tooltip in `ink-3` on `ink-6` border with `shadow-md`, `ink-12` text; the hovered series is emphasized by **lowering sibling opacity**, never by recoloring — so the palette contract holds during interaction. |
| **Realtime** | A bound live figure patches its series and transitions the affected mark to its new position over `motion.base`, or snaps under reduced motion — never a full redraw flash, never a toast. |

The empty/loading/error handoff is delegated to `Skeleton`/`EmptyState`/`ErrorState` inside
`ChartContainer` exactly as [`./TABLE.md`](./TABLE.md) and [`./CARD.md`](./CARD.md) delegate theirs.

# Tokens Used

Every token a chart may paint, drawn only from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md). No chart file references anything outside this table.

| Role | Token | Notes |
|---|---|---|
| Primary series | `accent` | 1-series, and the "matters" series in a 2-series chart |
| Reference series | `ink-7` | The neutral second series (budget, last period) |
| 3+ related categories | `accent-strong` → `accent` → chart-mid tint → `accent-subtle` | Sequential value-ramp, dark→light |
| "Other" / remainder | `ink-6` | Terminal slice/bar |
| Signed positive | `positive` | Genuinely signed metric only |
| Signed negative | `negative` | Genuinely signed metric only |
| Axis ticks/labels | `ink-9` | No axis line drawn |
| Gridlines | `ink-6` | Horizontal only |
| Tooltip surface | `ink-3` + 1px `ink-6` + `shadow-md` + `radius-lg` | |
| Tooltip text | `ink-12` | |
| Area fill | `accent` @ ~12% opacity | Over the `accent` line |
| Forecast/confidence band | `accent` @ ~12% opacity | Dashed `accent` projection line (see app doc `ForecastChart`) |
| "Today" boundary | `ink-6` reference line | Separates actuals from projection |
| Numerals (ticks, labels, tooltip) | `tabular-nums`, `dir="ltr"`, `latn` | Money via `formatAmount` |
| Loading skeleton | `ink-4`, `motion.slow` shimmer | |
| Entrance animation | `motion.slow` (360ms) | Reduced-motion → instant, no draw-in |
| Realtime mark move | `motion.base` (200ms) | Reduced-motion → snap |

# Accessibility

Charts are the hardest surface in the app to make accessible, and QAYD treats the accessible representation
as part of the chart, not an afterthought. Inherits the WCAG 2.1 AA baseline; the non-negotiables
(behavioral detail in
[`../../frontend/components/CHARTS.md → Accessibility`](../../frontend/components/CHARTS.md)):

- **A mandatory tabular fallback.** Every `ChartContainer` renders a visually-hidden but screen-reader- and
  keyboard-reachable `<table>` (the `dataTable` prop) carrying the exact numbers the chart plots — required,
  never optional, because a purely visual chart is invisible to a screen-reader user. A "view as table"
  toggle also exposes it visibly.
- **The chart is a labelled `<figure>`** with an `aria-label` naming what it shows — not a bare `<svg>`.
- **Never color-alone.** Every meaning a chart encodes in color also rides a second channel: a
  positive/negative bar carries its sign and points up/down; a two-series line distinguishes by a
  solid/dashed stroke and a direct end-of-line label, not only by accent-vs-ink; a donut slice is labelled
  in place. A grayscale print or a colorblind reader loses nothing — the same invariant `AmountCell` and
  `StatusPill` guarantee for tables and cards. The "not color alone" check is a **release gate**, not an
  advisory, because charts are the highest color-only-risk surface.
- **Interactive marks are real controls** — a clickable bar/point/slice is keyboard-focusable with an
  accessible name and `Enter` activation; hover-only tooltips are also focus-reachable.
- **Contrast, verified both themes.** The `accent` and `ink-7` series, the `ink-9` axis, and the `ink-6`
  gridlines meet the AA / 1.4.11 non-text targets in light and dark; an `accent` line clears contrast
  against the chart's surface in both ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).
- **Reduced motion** removes all entrance animation — marks appear at final state, tooltips and realtime
  updates snap instantly.

# Theming, Dark Mode & RTL

**Palette from tokens, always.** Every color a chart paints is a `chartPalette` entry resolving to a
`--qayd-*` variable; a chart file contains no hex, no numbered legacy step, and no `dark:` variant against a
raw color. This is the load-bearing rule of the whole document: because color comes only from the palette
module, a chart *cannot* introduce a rogue hue, and re-theming is a single token edit.

**Dark mode is a pure remap.** `class="dark"` on `<html>` re-points every `--qayd-*` variable, so the
identical chart renders correctly dark with no chart-level conditional — the `accent` **lifts rather than
saturates** against the darker canvas, axes and gridlines re-derive from the dark ink scale, and the tooltip
surface follows `ink-3` (which is *lighter* than the `ink-1` canvas in dark mode, per the
elevation-lightens rule). Because SVG `stroke`/`fill` accept CSS variables directly, the same
`var(--qayd-accent)` string is correct in both themes; a *computed* JS color imports from `lib/tokens.ts`,
never a hardcoded hex.

**RTL mirrors the chart's structure while leaving its numbers upright:**

- **Axis and category order mirror.** Under `dir="rtl"` a time axis reads right-to-left (earliest at the
  visual right), category bars order from the right, and the y-axis moves to the visual right edge — the
  chart reads in the same direction as the surrounding Arabic layout. `ChartContainer` sets the Recharts
  layout direction from the resolved `dir`, never a hardcoded left/right.
- **Numerals and currency codes never mirror.** Axis ticks, data labels, and tooltip values render
  `dir="ltr"` with `numberingSystem: 'latn'`, so `84,210.500` and `KWD` read left-to-right inside a
  right-to-left chart — the same rule `AmountCell` enforces.
- **Directional labels flip; meaning glyphs don't.** A "Forecast →" annotation flips its arrow in RTL via
  `rtl:rotate-180`; a `+`/`−` sign or an up/down glyph never flips — flipping it would invert its meaning.

# Do / Don't

| Do | Don't |
|---|---|
| Draw every color from the `chartPalette` token module | Write a hex or a numbered step in a chart file |
| Give a 1-series chart the one `accent` | Split one series into two competing accents |
| Distinguish 2 series as `accent` vs `ink-7` | Reach for a second hue for a reference series |
| Vary value (accent tint ramp) for 3+ related categories | Assign a categorical rainbow at 4+ series |
| Switch to direct labels / small multiples past ~4 series | Extend the ramp into indistinguishable tints |
| Reserve `positive`/`negative` for a genuinely signed metric | Color a raw debit red or a raw credit green |
| Pair every color encoding with a sign, glyph, dash, or label | Rely on color alone to distinguish series or sign |
| Draw axes `ink-9` and gridlines `ink-6` | Tint or accent an axis |
| Always render the mandatory tabular fallback | Ship a chart with no accessible numeric equivalent |
| Emphasize a hovered series by lowering sibling opacity | Recolor a series on hover (breaks the palette) |
| Let dark mode remap the palette tokens automatically | Add a `dark:` variant against a raw color |
| Keep numerals `dir="ltr" latn` inside an RTL chart | Let axis/tooltip numerals reverse in Arabic |

# Usage & Composition

```tsx
// A cost-center breakdown — three series, so a value-ramp of the ONE accent, assigned by the wrapper.
<BarChart
  title={t('spendByCostCenter.title')}
  data={spend.rows}
  series={[{ key: 'q1', label: 'Q1' }, { key: 'q2', label: 'Q2' }, { key: 'q3', label: 'Q3' }]}
  valueFormat={{ type: 'currency', currencyCode: 'KWD' }}
  height={280}
/>

// An inline trend inside a KpiTile — the sparkline exception: hand-drawn SVG, still an accent-token stroke.
<KpiTile label={t('cashPosition')} value={kpi.value} format="currency" currencyCode="KWD"
         sparklineData={kpi.trend /* → TrendSparkline, stroke=accent */} />
```

**Composition boundaries.**

- The `TrendSparkline` is the one primitive drawn outside `ChartContainer` (a handful of SVG path commands),
  but it still draws its single stroke from the palette — `accent` for a neutral trend, `positive`/`negative`
  only for a genuinely signed one — and it is consumed inside a [`./CARD.md`](./CARD.md) `KpiTile` or a
  [`./TABLE.md`](./TABLE.md) trend cell, never standalone.
- The `ForecastChart` (AI projection + confidence band) is a composed AI surface: its confidence/reasoning
  contract, the dashed-accent projection, and the "actuals never blur into projection" rule are the app
  doc's — this spec only fixes that its band is `accent` @ low opacity and its "today" boundary is an `ink-6`
  reference line.
- The empty/loading/error atoms (`Skeleton`, `EmptyState`, `ErrorState`) are shared with the table and card
  systems; `ChartContainer` decides which state and hands off.

For the full behavioral contract — the wrapper stack, `ForecastChart`, drill-in, realtime updates, and the
"frontend computes nothing" rule — see [`../../frontend/components/CHARTS.md`](../../frontend/components/CHARTS.md).

# End of Document
