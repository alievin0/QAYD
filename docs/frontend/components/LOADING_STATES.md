# Loading States — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / LOADING_STATES
---

# Purpose

This document specifies how QAYD communicates *"we don't know yet"* — the entire loading vocabulary of the
frontend: the skeleton system, the small and rationed use of spinners, the streaming-SSR contract
(`loading.tsx` + `<Suspense>`), optimistic UI, per-row and per-cell loading inside `DataTable`, per-button
pending states, the shimmer motion token, and the exact rule for *when to skeleton, when to spin, and when
to show nothing at all* because the first paint already carries real data. It is the counterpart to
`./EMPTY_STATES.md` ("we know, and there's nothing") and `./ERROR_STATES.md` ("we tried and failed"); the
three partition the non-happy outcomes of every data-bearing region, and keeping them visually and
semantically distinct is a designed invariant, not an implementation accident.

Loading is where QAYD's architecture and its taste meet. Architecturally, every data-bearing route is a
Server Component that performs the first authenticated fetch, so the initial paint already has real data and
shows *no client spinner at all* (`../README.md → Conventions → RSC vs. Client Components`); a skeleton or
spinner is for *subsequent* client transitions — a refetch, a filter change, a mutation in flight — not for
the first render of a well-built page. From taste, loading motion is held to the platform's calm register
(`../DESIGN_LANGUAGE.md → Motion`): a slow, low-contrast shimmer, never a bouncing spinner as the primary
loading affordance for content whose shape is already known, and never a full-screen blocking overlay for a
routine fetch. A financial product that flickers a spinner on every keystroke reads as fragile; one that
holds a stable, shape-preserving skeleton reads as engineered.

The governing principle throughout is **preserve layout, communicate progress, never block needlessly.** A
loading state that reflows the page when data arrives (skeleton rows a different height than real rows, a
spinner that collapses to nothing) is worse than none; a loading state that hides an entire screen because
one widget is slow is a failure of granularity. Both are treated as defects here.

# Anatomy & Variants

QAYD's loading vocabulary is four coordinated primitives plus one motion token, each with a narrow, stated
job. They are not interchangeable.

| Primitive | What it is | When it is the right choice |
|---|---|---|
| `Skeleton` | A shape-preserving placeholder block with the shimmer token, sized to match the real content it stands in for | Content with a **known shape** that is loading for the first time in a client context (table rows, KPI values, a form being hydrated) |
| `Spinner` | A single `Loader2` Lucide glyph, animated (or static under reduced motion) | Content with an **unknown or shapeless** duration — an in-flight mutation on a specific button, an indeterminate action, a small inline "working" indicator |
| `LoadingState` | A composed region-level placeholder assembled from `Skeleton`s in the shape of the region's eventual content | A whole panel/table/widget body loading in a client transition |
| `loading.tsx` | A route-segment file Next.js renders instantly while the segment's Server Component awaits its data | Streaming SSR: the first paint of a route whose server fetch has not yet resolved |

Plus the **shimmer token**: a slow (1.6s), low-contrast sweep `ink-100 → ink-150 → ink-100`, `ease: linear`,
`repeat: Infinity`, defined once and consumed by every `Skeleton` — "never a spinner as the primary loading
state for content that has a known shape" (`../DESIGN_LANGUAGE.md → Motion → Skeleton loading`).

## `Skeleton`

The atomic placeholder. A `Skeleton` is a `rounded-md` block filled with the shimmer token, given an explicit
width/height (or driven by its container) so it occupies *exactly* the footprint of the content it replaces.
Skeletons are composed, never one-size-fits-all: a table cell skeleton is a `h-4` bar at ~70% width, a KPI
value skeleton is an `h-8 w-32` block matching the hero numeral's box, an avatar skeleton is an `h-8 w-8
rounded-full`. The rule is that swapping a `Skeleton` for its real content must cause **zero layout shift**.

```tsx
// components/ui/skeleton.tsx
import { cn } from '@/lib/utils';

export function Skeleton({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      aria-hidden                       // decorative; the region's aria-busy carries the "loading" meaning
      className={cn('animate-shimmer rounded-md bg-ink-100', className)}
      {...props}
    />
  );
}
```

```css
/* app/globals.css — the one shimmer definition the whole app shares */
@keyframes qayd-shimmer {
  0%   { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}
.animate-shimmer {
  background-image: linear-gradient(90deg,
    var(--qayd-ink-100) 0%, var(--qayd-ink-150) 50%, var(--qayd-ink-100) 100%);
  background-size: 200% 100%;
  animation: qayd-shimmer 1.6s linear infinite;
}
@media (prefers-reduced-motion: reduce) {
  .animate-shimmer { animation: none; background-image: none; background-color: var(--qayd-ink-100); }
}
```

Under `prefers-reduced-motion`, the shimmer is replaced by a *static* `ink-100` fill — the placeholder is
still there and still communicates "loading" through its shape and the region's `aria-busy`; only the
sweep is removed, per `../DESIGN_LANGUAGE.md → Reduced motion` ("no motion," not "quick motion").

## `Spinner`

The `Loader2` glyph, `animate-spin motion-reduce:animate-none`, used *only* where a skeleton cannot be —
because the thing loading has no known shape or footprint. Its dominant home is a `Button`'s `loading` state
(`./BUTTONS.md`), and secondarily a small inline "working…" indicator inside an already-rendered control.
A spinner is never QAYD's answer for "a table is loading" or "a page is loading" — those have shapes, so
they skeleton.

## `LoadingState`

A region-level composition of `Skeleton`s matching the eventual content's structure, mounted while a client
query is `isPending`. `DataTable` embeds its own (eight skeleton rows matching the loaded row height,
`../COMPONENT_LIBRARY.md → DataTable`); a KPI grid renders a row of `KpiTile loading` placeholders; a
statement renders a statement-shaped skeleton. `LoadingState` carries no title, no message, and no action —
those belong to `EmptyState`/`ErrorState`; a loading placeholder that has explanatory copy has confused
itself with an empty state.

## `loading.tsx` (streaming SSR)

Per route segment, an `app/(app)/**/loading.tsx` file is the React `<Suspense>` fallback Next.js shows the
instant a navigation begins, while that segment's async Server Component awaits its fetch. It renders the
same shape as the page it precedes (a statement-shaped skeleton for a statement route, a table-shaped one
for a list route) so the transition from fallback to content is a fill-in, not a jump.

# Props / API

## `Skeleton`

| Prop | Type | Required | Description |
|---|---|---|---|
| `className` | `string` | no | Sizing/shape utilities (`h-4 w-32`, `rounded-full`, etc.). This is how a skeleton is matched to its target's footprint. |
| …native `<div>` props | — | no | Standard pass-through. |

## `Spinner`

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `size` | `number` | no | `16` | Pixel size of the `Loader2` glyph; 16 inline, 20 standalone. |
| `label` | `string` | no | — | Optional accessible name for a standalone spinner (e.g. "Loading recommendations"); when present, wraps the spinner in a `role="status"` with an `sr-only` label. |
| `className` | `string` | no | — | Color utility (defaults to `text-ink-500`). |

## `LoadingState`

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `variant` | `'table' \| 'cards' \| 'statement' \| 'form' \| 'detail'` | yes | — | Selects the skeleton composition shape. |
| `rows` | `number` | no | `8` | For `'table'`/`'cards'`: how many placeholder rows/cards to render. |
| `columns` | `number` | no | — | For `'table'`: column count, so cell skeletons align to the eventual grid. |
| `aria-label` | `string` | no | a localized "Loading" | The region's accessible loading label; the container also sets `aria-busy`. |

## `DataTable` per-row / per-cell loading

`DataTable` exposes loading at three granularities, driven by TanStack Query state, not local flags:

| Granularity | Query signal | Rendering |
|---|---|---|
| Whole-table first load | `isPending` (no prior data) | Eight (or `rows`) full skeleton rows, `aria-busy` on the table |
| Background refetch (page/sort/filter change with prior data) | `isFetching && !isPending` | Prior rows stay visible at reduced opacity via `placeholderData: keepPreviousData`; `aria-busy="true"` on the table; **no** skeleton flash |
| Single row/cell mutation | that row's `useMutation.isPending` | Only the affected row (or a specific cell, e.g. an inline-editable amount) shows an inline `Spinner`/cell skeleton; the rest of the table stays fully interactive |

The middle case — `keepPreviousData` keeping the old page on screen through a refetch — is the single most
important loading decision in a data-dense product: paging a 4,000-row ledger must not blank the table to
skeletons on every page turn, which reads as a full reload of a page that only changed by 25 rows
(`../COMPONENT_LIBRARY.md → DataTable`).

# States

`LoadingState` is a transient state by definition; the "states" of the loading system are the distinct
loading *situations* the app must render, each mapped to the right primitive:

| Situation | TanStack Query / React signal | Primitive | Notes |
|---|---|---|---|
| Route first paint (server fetch pending) | `<Suspense>` boundary via `loading.tsx` | Route-shaped skeleton | No client spinner; streamed HTML |
| Client query, first load, no prior data | `useQuery.isPending` (v5: `status === 'pending'`) | `LoadingState` / embedded skeletons | Shape-matched |
| Client query, background refetch | `isFetching && !isPending` | Prior data kept, `aria-busy` only | `keepPreviousData`; no skeleton |
| Suspense query | `useSuspenseQuery` (never `isPending` — it suspends) | Nearest `<Suspense>` fallback | The fallback *is* the loading UI |
| Mutation in flight (button) | `useMutation.isPending` | `Button loading` (spinner) | Per `./BUTTONS.md` |
| Mutation in flight (optimistic) | `onMutate` applied optimistic cache | Usually **no** spinner — UI already shows the end state | Button briefly disables to prevent double-submit |
| Per-row / per-cell mutation | that row's mutation `isPending` | Inline cell spinner/skeleton | Rest of table stays live |
| Streaming AI output | `role="status"` + `aria-busy` on the response container | AI "thinking" dots, then streamed text | Calmer/slower than a chat typing indicator (`../DESIGN_LANGUAGE.md → Motion`) |

## `isPending` vs. `isFetching` — the distinction that drives everything

TanStack Query v5's two booleans mean different things and must not be conflated:

- **`isPending`** — *there has never been data for this query key*; there is nothing to show but a
  placeholder. This is the only case that renders a full skeleton.
- **`isFetching`** — *a request is in flight right now*, which is `true` on the first load **and** on every
  background refetch. Using `isFetching` to decide whether to show a skeleton is the classic bug that blanks
  a populated table on every refetch. QAYD gates the *skeleton* on `isPending` and uses `isFetching` only to
  set `aria-busy` and a subtle reduced-opacity/`aria-busy` treatment on already-rendered content.

# Behavior & Interaction

## When to skeleton vs. spinner vs. nothing

This is the decision the primitives cannot make for the author. The rule set, in priority order:

1. **Nothing (no loading UI at all)** when the content is a route's *first paint* and its data comes from
   the Server Component's own `await` — the HTML arrives already populated, so there is no client loading
   moment to fill. This is the default and best case, and it is why README makes the Server-Component
   first-fetch mandatory: a well-built QAYD route shows real data on first paint, not a spinner. The
   `loading.tsx` fallback covers only the gap while the *server* is still awaiting, streamed as HTML, not a
   client spinner.
2. **Skeleton** when content of a *known shape* is loading in a *client* context — a client-side navigation
   whose data is not yet cached, a filter change on a not-yet-cached key, a widget hydrating a `useQuery`
   with no `initialData`. The skeleton mirrors the shape so the fill-in causes no layout shift.
3. **Spinner** when the thing loading has *no known shape or bounded footprint*: a specific button's
   mutation, an indeterminate inline action ("Searching…" in `AccountPicker` while a debounced query runs),
   a small "working" pip. A spinner is a last resort for shapeless waits, never the default.
4. **Keep prior data + `aria-busy`** when *refetching content that is already on screen* — a page turn, a
   re-sort, a poll — so the user keeps reading the current data through the fetch instead of watching it
   blank to skeletons.

The anti-patterns this rules out: a full-page spinner overlay on a routine fetch; a skeleton flash on a
background refetch; a spinner where a shape-matched skeleton belongs; and, most importantly, *any* client
loading spinner on a route's first paint when the Server Component could have delivered the data already.

## Streaming SSR contract

A data-bearing route composes as: the Server Component `await`s its fetch and renders real content; a
`loading.tsx` sibling provides the `<Suspense>` fallback Next streams instantly on navigation; and where a
single page has both instant and slow regions, the slow region is wrapped in its own `<Suspense>` so the
fast part paints immediately and the slow part streams in behind its own local skeleton:

```tsx
// app/(app)/dashboard/loading.tsx — shown instantly while the server awaits
import { LoadingState } from '@/components/shared/loading-state';
export default function DashboardLoading() {
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <LoadingState variant="cards" rows={6} aria-label="Loading dashboard" className="lg:col-span-2" />
      <LoadingState variant="cards" rows={3} />
    </div>
  );
}
```

```tsx
// app/(app)/dashboard/page.tsx — the fast KPIs paint; the slow AI feed streams behind its own Suspense
export default async function DashboardPage() {
  const kpis = await getDashboardKpis();               // fast → part of first paint
  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <KpiGrid initialKpis={kpis} className="lg:col-span-2" />
      <Suspense fallback={<LoadingState variant="cards" rows={3} aria-label="Loading recommendations" />}>
        <RecommendationsFeed />                          {/* awaits the slower AI endpoint */}
      </Suspense>
    </div>
  );
}
```

The handoff to client interactivity follows README's pattern: the Server Component passes its resolved data
as `initialData` to a thin Client Component that mounts a matching `useQuery`, so subsequent refetches,
mutations, and realtime updates are client-driven — but the *first* render never showed a spinner
(`../README.md → Conventions`).

## Optimistic UI

For mutations that should feel instant — toggling a setting, marking an insight read, reordering — QAYD uses
React 19 `useOptimistic` / TanStack `onMutate` to apply the intended end state immediately, so the loading
"state" is *the absence of one*: the UI already shows the result. The button does not spin (a spinner would
contradict the already-applied change); it briefly disables to prevent a double-submit and re-enables on
settle. On failure, the optimistic change rolls back and an error is surfaced via toast/`ErrorState`
(`./ERROR_STATES.md`), matching `../COMPONENT_LIBRARY.md → Edge Cases → Optimistic update rolled back on
409`. Optimistic UI is reserved for low-stakes, high-frequency mutations; a *posting* action — which the
user must watch succeed against the server's authoritative re-check — is a visible pending spinner, not an
optimistic pretend-post.

## AI streaming

The AI assistant's response streams token-by-token into a container that is `role="status"` `aria-busy` from
the moment the request fires. Before the first token, the calm three-dot "thinking" indicator
(`accent-subtle` dots pulsing on a 1.2s loop, `../DESIGN_LANGUAGE.md → Motion → AI "thinking" indicator`)
holds the space; as tokens arrive they replace the dots. The live region is throttled so it does not
announce every token (`../ACCESSIBILITY.md → Live regions`) — it announces once when streaming completes,
not on each chunk.

# Accessibility

- **Loading is announced through the region, not the placeholder.** Individual `Skeleton` blocks are
  `aria-hidden` (decorative); the *region* carries `aria-busy="true"` and, where a live announcement is
  wanted, `role="status"` `aria-live="polite"` so a screen-reader user hears "Loading" once, not a flood of
  empty placeholder announcements (`../ACCESSIBILITY.md → Screen Readers → Loading state is announced`).
- **`aria-busy` flips to `false` on settle.** When data arrives, `aria-busy` is removed and, for a
  `DataTable`, the row count or result is announced via the table's `aria-live` region — the transition
  from loading to loaded is perceivable to assistive tech, not a silent swap.
- **Spinners that carry meaning are labeled.** A standalone `Spinner` that represents a whole region loading
  gets a `role="status"` with an `sr-only` label ("Loading recommendations"); a spinner *inside* an
  already-labeled control (a `Button`'s `loading` state) needs no separate label because the button's
  `aria-busy` already carries it.
- **No focus theft.** A loading state never moves focus. A route transition keeps focus management to the
  App Router's own post-navigation focus handling (`../ACCESSIBILITY.md → Focus Management → Route changes`);
  a skeleton is never focusable.
- **Reduced motion is honored everywhere.** The shimmer collapses to a static fill, the button spinner stops
  rotating, and the AI thinking-dots stop pulsing under `prefers-reduced-motion` — in every case the
  *state* (loading) is still conveyed by presence and `aria-busy`, only the animation is removed.
- **Streaming does not flood the screen reader.** The AI response's live region announces on completion, not
  per token, per the throttling rule in `../ACCESSIBILITY.md → Live regions`.

# Theming, Dark Mode & RTL

## Theming

The shimmer references only `--qayd-ink-100`/`--qayd-ink-150`; the spinner inherits `text-ink-500`. No raw
color, no Tailwind palette value. Because the skeleton fill is a neutral-ink token, a white-label accent
override does not touch loading placeholders (correctly — a loading state is not a branded surface).

## Dark mode

No `dark:` variants. The shimmer's `ink-100 → ink-150 → ink-100` gradient remaps under
`:root[data-theme="dark"]` to the dark neutral steps, so the placeholder stays a low-contrast, *lighter*
sweep against the dark canvas rather than a harsh light-on-dark flash — matching `../DARK_MODE.md`'s rule
that elevation and highlights get lighter, not more saturated, in dark. The spinner's `ink-500` likewise
remaps to its dark value. A shimmer that used a raw light gradient would glare against dark; the token
indirection is exactly what prevents it.

## RTL

- **Shimmer direction.** The sweep runs along the inline axis; under `dir="rtl"` the gradient's travel is
  mirrored so it sweeps in the reading direction rather than against it — a minor but real polish handled by
  a logical-property/`[dir="rtl"]` adjustment to the keyframe direction, consistent with
  `../DESIGN_LANGUAGE.md → RTL mirroring`.
- **Shape-matched skeletons mirror for free.** Because `LoadingState` compositions use logical spacing
  (`ms-*`/`ps-*`) and mirror the real content's layout, a table/statement skeleton lays out correctly in RTL
  with no conditional code — the same reason the real content does.
- **The spinner never flips.** `Loader2` is a rotationally symmetric, meaning-neutral glyph; it is never
  mirrored (`../ICONOGRAPHY.md → RTL-Aware Icons`).

# i18n

- **Loading has almost no copy — by design.** A `LoadingState` carries no title or message (those belong to
  empty/error states), so there is little to translate. The one string is the region's `aria-label`
  ("Loading" / "جارٍ التحميل") and any standalone spinner's `sr-only` label, both i18n keys present in `en.ts`
  and `ar.ts` and checked by `i18n:check`.
- **The AI thinking indicator's optional label** ("Thinking…" / "جارٍ التفكير…") is a key in the AI namespace,
  authored to the calm Arabic register (`../DESIGN_LANGUAGE.md → Arabic microcopy`), never machine-translated.
- **No numerals in loading UI**, so the Western-digit rule does not arise; a skeleton never renders a
  placeholder number that could imply a value.

# Testing

**Storybook.** `skeleton.stories.tsx`, `spinner.stories.tsx`, and `loading-state.stories.tsx` render each
variant (`table`/`cards`/`statement`/`form`/`detail`) and the reduced-motion static-fill state, inspectable
across LTR/RTL × light/dark. `DataTable`'s story short-circuits its query to `isPending` (full skeleton) and
to `isFetching && !isPending` (prior data retained) to prove the two render differently
(`../COMPONENT_LIBRARY.md → Testing`, `parameters.state`).

```tsx
// components/shared/loading-state.stories.tsx (excerpt)
export const Table: StoryObj<typeof LoadingState> = { args: { variant: 'table', rows: 8, columns: 5 } };
export const ReducedMotion: StoryObj<typeof LoadingState> = {
  args: { variant: 'cards', rows: 3 }, parameters: { prefersReducedMotion: true } };
```

**Vitest + Testing Library** covers the state-selection logic:

```tsx
// components/shared/data-table.loading.test.tsx
test('shows a full skeleton on first load (isPending) but keeps prior rows on refetch (isFetching)', () => {
  const { rerender, queryAllByTestId, getByRole } = renderTable({ status: 'pending' });
  expect(queryAllByTestId('skeleton-row').length).toBeGreaterThan(0);   // first load → skeleton

  rerender(<TableWith status="success" data={PAGE_1} isFetching />);    // background refetch
  expect(queryAllByTestId('skeleton-row').length).toBe(0);              // no skeleton flash
  expect(getByRole('table')).toHaveAttribute('aria-busy', 'true');     // busy signalled instead
});
```

```tsx
// components/ui/skeleton.test.tsx
test('skeleton blocks are aria-hidden so only the region announces loading', () => {
  const { container } = render(<Skeleton className="h-4 w-32" />);
  expect(container.firstChild).toHaveAttribute('aria-hidden', 'true');
});
```

**Playwright** covers the two things a component test cannot: a real route transition asserting `loading.tsx`
streams a shape-matched skeleton before the server data arrives (and that the fill-in causes no layout
shift, via a bounding-box comparison), and a `DataTable` page-turn against a seeded API asserting the prior
page stays visible through the refetch rather than blanking to skeletons.

**Accessibility CI.** `axe-core` runs against every loading story; a focusable skeleton, a missing
`aria-busy` on a loading region, or an un-collapsed shimmer under the reduced-motion parameter fails the
build. `DataTable`'s loading→loaded announcement gets a manual screen-reader pass each release.

# Edge Cases

- **Skeleton flash on a fast fetch.** A query that resolves in <200ms should not flash a skeleton for two
  frames (which reads as a flicker). Regions whose data is usually cached use `initialData` from the Server
  Component (so no skeleton ever shows) or a short `placeholderData`/delay so the skeleton only appears if
  the wait is actually perceptible — a skeleton that blinks in and out is worse than none.
- **`isFetching` misused as `isPending`.** Gating a skeleton on `isFetching` blanks a populated table on
  every refetch; this is the single most common loading bug and is explicitly unit-tested against (see
  Testing). Skeletons gate on `isPending`; `isFetching` only sets `aria-busy`.
- **A `loading.tsx` shaped wrong.** If the route fallback's shape doesn't match the page, the fill-in jumps.
  Each `loading.tsx` renders the *same* `LoadingState variant` as its page's primary region, verified by the
  Playwright no-layout-shift check.
- **Optimistic mutation that also spins.** Showing a spinner on an optimistically-applied action
  contradicts the already-updated UI; optimistic buttons disable-then-re-enable, they do not spin. A spinner
  belongs to a *pessimistic* pending action the user is watching complete (a Post).
- **Never-ending spinner.** A spinner with no timeout that survives a silently-dropped request is a stuck
  UI; every spinner is bound to a mutation/query `isPending` that a failure flips to `isError` (→
  `ErrorState`), or to a `Retry-After`-backed retry for a soft-failing AI endpoint — a spinner is never a
  hand-managed boolean that can leak (`./ERROR_STATES.md`).
- **Streaming AI flooding the live region.** Wiring `aria-live` directly to a token-by-token stream floods
  the screen reader; the response region announces once on completion, not per chunk
  (`../ACCESSIBILITY.md → Live regions`).
- **Reduced-motion static skeleton mistaken for a broken state.** Under `prefers-reduced-motion` the
  skeleton is a *static* `ink-100` block; combined with the region's `aria-busy` and (where present) a
  visually-hidden "Loading" label, it is unambiguously a loading placeholder, not an empty gray box — this
  is why the region's `aria-busy` is load-bearing, not decorative.
- **Virtualized table loading a new window.** As `@tanstack/react-virtual` scrolls toward the end of the
  loaded set, the query layer fetches the next cursor page; the newly-entering rows show inline cell
  skeletons within the virtualized window rather than a whole-table skeleton, so an accountant scrolling a
  100k-row ledger sees only the incoming rows resolve, never a full reload (`../DESIGN_LANGUAGE.md → Data
  Density → Virtualization`).
- **Print / PDF export never captures a loading state.** Server-side PDF export renders from resolved data,
  so a skeleton or spinner can never appear in an exported document (`../../api/REST_STANDARDS.md`); the
  export path awaits data fully before rendering, by contract.

# End of Document
