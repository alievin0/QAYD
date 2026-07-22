# Loading-State Pattern — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / LOADING_STATES
---

# Purpose

This document specifies the **loading-state pattern** — the composed way QAYD communicates *"we don't know
yet."* It is a pattern doc, not a primitive: it does not re-specify the shimmer keyframe or the spinner
glyph, but the arrangement of skeletons, spinners, streaming SSR (`loading.tsx` + `<Suspense>`), optimistic
UI, per-row/per-cell table loading, and per-button pending states into one coherent loading vocabulary — and
the single decision the primitives cannot make for the author: *when to skeleton, when to spin, and when to
show nothing at all* because the first paint already carries real data.

It specializes the application-level contract in
[`../../frontend/components/LOADING_STATES.md`](../../frontend/components/LOADING_STATES.md) — that document
owns the `Skeleton`/`Spinner`/`LoadingState` component APIs, the TanStack Query wiring, and the shimmer CSS.
This pattern document restates the arrangement in design-system terms: which motion tokens drive the
shimmer, how loading composes with `Card`, `Table`, `Progress`, and `Button`, and how the pattern holds the
line against its siblings — the empty pattern ([`./EMPTY_STATES.md`](./EMPTY_STATES.md)) and the error
pattern ([`./ERROR_STATES.md`](./ERROR_STATES.md)). Keeping "loading," "empty," and "error" visually and
semantically distinct is a designed invariant; this doc is one third of it.

Loading is where QAYD's architecture and its taste meet. Architecturally, every data-bearing route is a
Server Component that performs the first authenticated fetch, so the initial paint already has real data and
shows *no client spinner at all*; a skeleton or spinner is for *subsequent* client transitions — a refetch,
a filter change, a mutation in flight — not for the first render of a well-built page. From taste, loading
motion is held to the platform's calm register: a slow, low-contrast shimmer, never a bouncing spinner as
the primary affordance for content whose shape is already known, and never a full-screen blocking overlay
for a routine fetch. The governing principle is **preserve layout, communicate progress, never block
needlessly.** A loading state that reflows the page when data arrives is worse than none; one that hides an
entire screen because one widget is slow is a failure of granularity. Both are defects.

Token-reconciliation note. The application doc uses draft palette names (`ink-100`, `ink-150`, `ink-500`).
Per [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md), the **canonical** tokens here are
`ink-1 … ink-12`, `accent`, and the motion scale (`motion.instant … motion.slow`). Read the shimmer's draft
`ink-100 → ink-150` sweep as a canonical low-step sweep `ink-3 → ink-4`, and the spinner's `ink-500` as
`ink-9`.

# When to Use

The loading pattern applies while a query is `isPending` (no prior data) or a mutation is in flight — but
its most important rule is knowing when it should *not* appear at all because a Server Component already
delivered the data. The decision, in strict priority order:

| Priority | Situation | Choice |
|---|---|---|
| 1 | A route's **first paint**, data from the Server Component's own `await` | **Nothing** — HTML arrives populated; no client loading moment exists |
| 2 | Content of a **known shape** loading in a **client** context (nav to an uncached key, a filter change) | **Skeleton**, shape-matched so the fill-in causes zero layout shift |
| 3 | A wait with **no known shape or bounded footprint** (a specific button's mutation, a debounced search) | **Spinner**, a last resort for shapeless waits |
| 4 | **Refetching content already on screen** (page turn, re-sort, poll) | **Keep prior data + `aria-busy`** — never blank to skeletons |

Use it when:

- A client-side navigation or filter change hits a not-yet-cached query key.
- A route segment's Server Component is still awaiting its fetch — the `loading.tsx` fallback streams a
  shape-matched skeleton.
- A mutation is running on a specific button, row, or cell.
- The AI assistant is generating a streamed response.

Do **not** use it when:

- The Server Component already resolved the data — a client spinner on first paint is the anti-pattern this
  whole architecture exists to avoid.
- A populated table is merely refetching in the background — keep the prior data visible with `aria-busy`.
- A fetch has settled empty or errored — those are the empty and error patterns, not a spinner-forever.

# Anatomy

The loading vocabulary is **four coordinated primitives plus one motion token**, each with a narrow job.
They are not interchangeable.

| Primitive | What it is | The right choice when |
|---|---|---|
| `Skeleton` | A shape-preserving placeholder block carrying the shimmer, sized to the real content | Content of a **known shape** loading for the first time in a client context |
| `Spinner` | A single `Loader2` Lucide glyph, animated (static under reduced motion) | An **unknown/shapeless** duration — an in-flight button mutation, an indeterminate inline "working" pip |
| `LoadingState` | A region-level composition of `Skeleton`s in the shape of the eventual content | A whole panel/table/widget body loading in a client transition |
| `loading.tsx` | A route-segment `<Suspense>` fallback Next renders instantly while the server awaits | Streaming SSR: the first paint of a route whose server fetch has not resolved |

Plus the **shimmer token**: a slow (1.6s) low-contrast sweep along the inline axis, `ease: linear`,
`repeat: Infinity`, defined once and consumed by every `Skeleton`. It is a *loop period*, not a member of
the transition duration scale (which tops out at `motion.slow` / 360ms) — see
[`../MOTION_SYSTEM.md → The Duration Scale`](../MOTION_SYSTEM.md).

```
Skeleton (a single placeholder block)
  ┌──────────────┐   rounded-md, shimmer sweep ink-3 → ink-4 → ink-3, aria-hidden
  └──────────────┘

LoadingState variant="table" (a region-level composition)
  ┌───────────────────────────────────────────┐
  │ ▁▁▁▁▁▁▁▁   ▁▁▁▁▁   ▁▁▁▁▁▁      ▁▁▁▁   │  ← header-row skeletons
  │ ▁▁▁▁▁▁▁▁▁▁ ▁▁▁▁▁▁▁ ▁▁▁▁▁▁▁▁▁▁  ▁▁▁▁▁ │  ← 8 body-row skeletons, matched to real row height
  │ ▁▁▁▁▁▁▁▁   ▁▁▁▁▁   ▁▁▁▁▁▁      ▁▁▁▁   │     aria-busy on the region, aria-hidden on each block
  └───────────────────────────────────────────┘
```

A `Skeleton`'s footprint must match its target *exactly* — a table-cell skeleton is a `h-4` bar at ~70%
width; a KPI-value skeleton is an `h-8 w-32` block matching the hero numeral's box; an avatar skeleton is
`h-8 w-8 rounded-full`. The binding rule: swapping a `Skeleton` for its real content causes **zero layout
shift**.

# Variants

`LoadingState` selects a skeleton composition by `variant`, so the fallback shape always matches the page
that follows it:

| `variant` | Mirrors | Notes |
|---|---|---|
| `table` | A `DataTable` — header + `rows` body rows at real row height | `columns` aligns cell skeletons to the eventual grid |
| `cards` | A KPI/insight card grid | `rows` = number of placeholder cards |
| `statement` | A financial statement (Balance Sheet, P&L) — section headers + line rows + totals | Matches the statement's own vertical rhythm |
| `form` | A form being hydrated — label + field skeletons | Rare; most forms mount empty, not skeletoned |
| `detail` | A record detail panel — title, meta, definition-list rows | Used behind an `xl` list+detail split |

Two cross-cutting behaviors, driven by TanStack Query state rather than local flags:

| Granularity | Query signal | Rendering |
|---|---|---|
| Whole-table first load | `isPending` (no prior data) | Full skeleton rows; `aria-busy` on the table |
| Background refetch (page/sort/filter with prior data) | `isFetching && !isPending` | Prior rows stay visible via `keepPreviousData`; `aria-busy="true"`; **no** skeleton flash |
| Single row/cell mutation | that row's `useMutation.isPending` | Only the affected row/cell shows an inline spinner or cell skeleton; the rest of the table stays interactive |

The middle case — `keepPreviousData` keeping the old page on screen through a refetch — is the single most
important loading decision in a data-dense product: paging a 4,000-row ledger must not blank the table to
skeletons on every page turn, which reads as a full reload of a page that changed by only 25 rows.

# Composition

The pattern composes from existing atoms and the motion system; it introduces no new primitive.

| Concern | Atom / token source |
|---|---|
| Shimmer fill + sweep | `ink-3 → ink-4 → ink-3`, 1.6s linear loop ([`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md), [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md)) |
| Spinner | `Loader2` Lucide glyph, `animate-spin motion-reduce:animate-none`, `text-ink-9` |
| Table skeleton | `Table` primitive's own eight-row loading composition ([`../components/TABLE.md`](../components/TABLE.md)) |
| Card skeleton | `Card` loading variant ([`../components/CARD.md`](../components/CARD.md)) |
| Button pending | `Button loading` — spinner + disabled ([`../components/BUTTON.md`](../components/BUTTON.md)) |
| Determinate job progress | `Progress` / `Progress indeterminate` ([`../components/PROGRESS.md`](../components/PROGRESS.md)) |
| AI streaming | Three `accent-subtle` "thinking" dots on a 1.2s staggered loop, then streamed text |

## Motion

Only two loop periods drive loading motion, and neither is a member of the transition scale:

- **Skeleton shimmer** — 1.6s, `ease: linear`, `repeat: Infinity`, sweeping along the inline axis. Under
  `prefers-reduced-motion` the sweep is replaced by a *static* `ink-3` fill; the placeholder still
  communicates "loading" through its shape and the region's `aria-busy`, only the sweep is removed.
- **AI "thinking" dots** — three `accent-subtle` dots pulsing on a 1.2s staggered loop, holding the space
  before the first token arrives; static under reduced motion.

The spinner rotates continuously and stops rotating under reduced motion (`motion-reduce:animate-none`). A
determinate job uses `Progress`, whose fill tweens over `motion.base` (200ms). No loading primitive uses a
spring, a bounce, or any transition faster than `motion.micro`. Critically, loading motion animates only
`transform`/`opacity` (and the shimmer's `background-position`), never a layout-triggering property — a
loading state must never reflow the content it stands in for. See
[`../MOTION_SYSTEM.md → Performance`](../MOTION_SYSTEM.md).

## Streaming SSR contract

A data-bearing route composes as: the Server Component `await`s and renders real content; a `loading.tsx`
sibling provides the `<Suspense>` fallback Next streams instantly on navigation; and where one page has both
fast and slow regions, the slow region is wrapped in its own `<Suspense>` so the fast part paints immediately
and the slow part streams in behind its own local skeleton.

```tsx
// app/(app)/dashboard/loading.tsx — streamed instantly while the server awaits.
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
// app/(app)/dashboard/page.tsx — fast KPIs paint; the slow AI feed streams behind its own Suspense.
export default async function DashboardPage() {
  const kpis = await getDashboardKpis();               // fast → part of first paint, no spinner
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

The handoff to client interactivity passes the Server Component's resolved data as `initialData` to a thin
Client Component that mounts a matching `useQuery`, so subsequent refetches, mutations, and realtime updates
are client-driven — but the *first* render never showed a spinner.

# States & Behavior

`LoadingState` is transient by definition; the "states" of the pattern are the distinct loading *situations*
the app must render, each mapped to the right primitive:

| Situation | React / Query signal | Primitive |
|---|---|---|
| Route first paint (server fetch pending) | `<Suspense>` via `loading.tsx` | Route-shaped skeleton (no client spinner; streamed HTML) |
| Client query, first load, no prior data | `useQuery.isPending` | `LoadingState` / embedded skeletons |
| Client query, background refetch | `isFetching && !isPending` | Prior data kept, `aria-busy` only (`keepPreviousData`) |
| Suspense query | `useSuspenseQuery` | Nearest `<Suspense>` fallback *is* the loading UI |
| Mutation in flight (button) | `useMutation.isPending` | `Button loading` (spinner) |
| Mutation in flight (optimistic) | `onMutate` applied optimistic cache | Usually **no** spinner — UI already shows the end state; button briefly disables |
| Per-row / per-cell mutation | that row's mutation `isPending` | Inline cell spinner/skeleton; rest of table stays live |
| Streaming AI output | `role="status"` + `aria-busy` on the response container | Thinking dots, then streamed text |

## `isPending` vs `isFetching` — the distinction that drives everything

- **`isPending`** — there has *never* been data for this query key; there is nothing to show but a
  placeholder. This is the only case that renders a full skeleton.
- **`isFetching`** — a request is in flight *right now*, which is `true` on the first load **and** on every
  background refetch. Gating a skeleton on `isFetching` is the classic bug that blanks a populated table on
  every refetch. The pattern gates the *skeleton* on `isPending` and uses `isFetching` only to set
  `aria-busy` and a subtle reduced-opacity treatment on already-rendered content.

## Optimistic UI

For low-stakes, high-frequency mutations (toggling a setting, marking an insight read, reordering), QAYD
applies the intended end state immediately via React 19 `useOptimistic` / TanStack `onMutate`, so the
loading "state" is *the absence of one* — the UI already shows the result. The button does **not** spin (a
spinner would contradict the applied change); it briefly disables to prevent a double-submit and re-enables
on settle. On failure the optimistic change rolls back and the error surfaces via toast/error state.
Optimistic UI is reserved for reversible, low-stakes actions; a **posting** action — which the user must
watch succeed against the server's authoritative re-check — is a visible pending spinner, never an
optimistic pretend-post.

## AI streaming

The AI response streams token-by-token into a container that is `role="status"` `aria-busy` from the moment
the request fires. Before the first token, the calm three-dot "thinking" indicator holds the space; as
tokens arrive they replace the dots. The live region is throttled so it announces once on completion, not
on each chunk.

# Content & Copy Guidance

Loading has **almost no copy by design** — a `LoadingState` carries no title, no message, and no action;
those belong to the empty and error patterns. A loading placeholder that has explanatory copy has confused
itself with an empty state.

- **The one string is the region's `aria-label`** ("Loading" / "جارٍ التحميل"), an i18n key present in both
  `en.ts` and `ar.ts` and checked by `i18n:check`.
- **A standalone spinner that carries meaning** gets an optional `sr-only` label ("Loading recommendations"
  / "جارٍ تحميل التوصيات") wrapped in `role="status"`.
- **The AI thinking indicator's optional label** ("Thinking…" / "جارٍ التفكير…") is a key in the AI
  namespace, authored to the calm Arabic register, never machine-translated.
- **No numerals in loading UI** — a skeleton never renders a placeholder number that could imply a value, so
  the Western-digit rule does not arise here.

# Accessibility

- **Loading is announced through the region, not the placeholder.** Individual `Skeleton` blocks are
  `aria-hidden` (decorative); the *region* carries `aria-busy="true"` and, where a live announcement is
  wanted, `role="status"` `aria-live="polite"`, so a screen-reader user hears "Loading" once, not a flood of
  empty placeholder announcements.
- **`aria-busy` flips to `false` on settle.** When data arrives, `aria-busy` is removed and, for a
  `DataTable`, the row count is announced via the table's live region — the loading-to-loaded transition is
  perceivable to assistive tech, not a silent swap.
- **Spinners that carry meaning are labeled.** A standalone `Spinner` representing a whole region loading
  gets a `role="status"` with an `sr-only` label; a spinner *inside* an already-labeled control (a
  `Button`'s `loading` state) needs no separate label because the button's `aria-busy` already carries it.
- **No focus theft.** A loading state never moves focus; a skeleton is never focusable; route transitions
  keep focus management to the App Router's own post-navigation handling.
- **Reduced motion is honored everywhere.** The shimmer collapses to a static fill, the button spinner stops
  rotating, and the AI thinking-dots stop pulsing — in every case the *state* (loading) is still conveyed by
  presence and `aria-busy`, only the animation is removed. This is the reduced-motion contract from
  [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md): remove the animation of a change, never the change.
- **Streaming does not flood the screen reader.** The AI response's live region announces on completion, not
  per token.

# Theming, Dark Mode & RTL

## Theming

The shimmer references only the low ink steps (`ink-3 → ink-4`); the spinner inherits `text-ink-9`. No raw
color, no Tailwind palette value. Because the skeleton fill is a neutral-ink token, a white-label accent
override does not touch loading placeholders — correctly, a loading state is not a branded surface.

## Dark mode

No `dark:` variants. The shimmer's `ink-3 → ink-4 → ink-3` gradient remaps under `.dark` to the dark
neutral steps, so the placeholder stays a low-contrast, *lighter* sweep against the dark canvas rather than a
harsh light-on-dark flash — matching the rule that elevation and highlights get lighter, not more saturated,
in dark. The spinner's `ink-9` likewise remaps. A shimmer built from a raw light gradient would glare
against dark; the token indirection is exactly what prevents it.

## RTL

- **Shimmer direction.** The sweep runs along the inline axis; under `dir="rtl"` its travel is mirrored so
  it sweeps in the reading direction rather than against it — handled by a logical-property/`[dir="rtl"]`
  adjustment to the keyframe direction, consistent with [`../MOTION_SYSTEM.md → RTL-Aware Directional
  Motion`](../MOTION_SYSTEM.md).
- **Shape-matched skeletons mirror for free.** Because `LoadingState` compositions use logical spacing
  (`ms-*`/`ps-*`) and mirror the real content's layout, a table/statement skeleton lays out correctly in RTL
  with no conditional code — the same reason the real content does.
- **The spinner never flips.** `Loader2` is rotationally symmetric and meaning-neutral; it is never
  mirrored. A determinate `Progress` fill, by contrast, *does* grow from the inline-start edge (right in
  Arabic) — that RTL detail lives in [`../components/PROGRESS.md`](../components/PROGRESS.md).

# Do / Don't

| Do | Don't |
|---|---|
| Let the Server Component deliver first-paint data with no client spinner | Flash a client loading spinner on a route's first paint |
| Skeleton content of a known shape, matched to zero layout shift | Spin where a shape-matched skeleton belongs |
| Gate the skeleton on `isPending` | Gate it on `isFetching` and blank a populated table on every refetch |
| Keep prior data + `aria-busy` through a background refetch | Blank the table to skeletons on a page turn |
| Reserve the spinner for shapeless, bounded-footprint waits | Use a spinner as the default "a table is loading" affordance |
| Give a slow region its own `<Suspense>` so the fast part paints | Hide the whole screen because one widget is slow |
| Disable-then-re-enable an optimistic button (no spinner) | Spin a button whose change the UI already applied optimistically |
| Bind every spinner to a query/mutation `isPending` that can flip to error | Hand-manage a boolean spinner that can leak into a never-ending spin |
| Collapse the shimmer to a static fill under reduced motion | Leave a bare looping shimmer for reduced-motion users |
| Match each `loading.tsx` shape to its page's primary region | Ship a fallback whose shape jumps when the content fills in |

# End of Document
