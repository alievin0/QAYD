# Empty-State Pattern — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / EMPTY_STATES
---

# Purpose

This document specifies the **empty-state pattern** — the composed, recurring UX arrangement QAYD renders
whenever a region that would normally hold data holds none. It is a *pattern* doc, not a primitive: it does
not re-specify a button, an icon, or a heading, but the way those atoms compose into a calm, explanatory
"there is nothing here (and here is why, and here is the one useful next step)" surface, and the rules that
decide *which* of four empty arrangements a given emptiness gets.

It specializes the application-level component contract in
[`../../frontend/components/EMPTY_STATES.md`](../../frontend/components/EMPTY_STATES.md) — that document owns
the `<EmptyState>` component's props, its React implementation, and its per-screen wiring inside `DataTable`.
This pattern document restates the arrangement in design-system terms: which tokens paint it, which motion
tokens (if any) animate it, how it composes with `Button`, `Card`, and the illustration slot, and how it
holds the line against its two siblings — the loading pattern
([`./LOADING_STATES.md`](./LOADING_STATES.md)) and the error pattern
([`./ERROR_STATES.md`](./ERROR_STATES.md)). Where the three-way distinction between "loading / empty / error"
is a *designed invariant*, this doc is one third of it, and the success pattern
([`./SUCCESS_STATES.md`](./SUCCESS_STATES.md)) is the positive counterpart the four form a set with.

The governing idea is that in a financial product an empty state is not a cosmetic afterthought — it is a
first-impression surface and a navigational one. A brand-new company's first login is *almost entirely*
empty states: no accounts, no journal entries, no bank feed, no statements. Those emptinesses are the
onboarding funnel. Each must do real work — say plainly what is absent, say why, offer the single most
useful next action — while holding QAYD's calm editorial register: at most one line-drawn abstraction at
1.5px stroke in `ink-7`, never an illustrated character reacting to the void, never an emoji, never "Nothing
here yet! Let's fix that." The copy carries the weight; the graphic is a quiet anchor.

Token-reconciliation note. The application doc this pattern specializes was written against the earlier
numeric palette (`ink-300`, `ink-500`, `ink-950`, `text-danger`). Per
[`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md), the **canonical** tokens used throughout this
document are `ink-1 … ink-12`, `accent`/`accent-subtle`/`accent-strong`/`accent-on`, and
`positive`/`negative`/`warning`. Read a draft name as its canonical equivalent: `ink-300 → ink-7` (glyph),
`ink-500 → ink-9` (description), `ink-950 → ink-11` (title), `text-danger → text-negative`.

# When to Use

Reach for the empty-state pattern when a fetch has **settled** and the result is genuinely empty — not
while it is still loading, and not when it failed. The decision is a three-way branch every data-bearing
region runs, exactly as `DataTable` does (`isPending → skeleton`, `isError → ErrorState`,
`data.length === 0 → EmptyState`):

| Condition | Truth it states | Pattern |
|---|---|---|
| Query is `isPending`, no prior data | "We don't know yet" | Loading ([`./LOADING_STATES.md`](./LOADING_STATES.md)) |
| Query settled, `data.length === 0` | "We know, and there is genuinely nothing" | **Empty (this doc)** |
| Query settled with `isError` | "We tried and failed" | Error ([`./ERROR_STATES.md`](./ERROR_STATES.md)) |
| Mutation completed successfully | "The thing you did worked" | Success ([`./SUCCESS_STATES.md`](./SUCCESS_STATES.md)) |

Use it when:

- A **first-run** dataset has genuinely never held anything — a new company's ledger, an unstarted chart of
  accounts, a reconciliation queue before the first statement import.
- A **filter or search** matched zero rows in an otherwise-populated dataset.
- A viewer's **role cannot populate** the region, or is not the intended actor for it.
- A region is empty because an **expected, self-healing upstream** is briefly unavailable (the AI engine
  returning a documented soft-fail `503`), which is *not* an error.

Do **not** use it when:

- The region is still loading — a "spinning" empty state is a category error; gate on a settled result.
- A call that *should* have returned data failed unexpectedly (`500`, network drop) — that is the error
  pattern with a retry, never an empty state.
- The region has data but one row is mid-mutation — that is per-row loading, not an emptiness.

# Anatomy

An empty state is a **vertically-stacked, centered composition** inside its region, distinguished by
whitespace and centering rather than by a card, a border, or a background wash. Top to bottom:

```
        ┌─ region body ──────────────────────────────────┐
        │                                                 │
        │                 [ glyph ]         ← illustration slot (Lucide 24px, ink-7, aria-hidden)
        │                                                 │
        │           No journal entries yet          ← title (display-sm, ink-11, real <h3>)
        │                                                 │
        │   Post your first entry to start your books.   ← description (text-sm, ink-9, max-w-sm)
        │                                                 │
        │        [ Learn how ]     [ New entry ]      ← secondary (ghost) + primary (default)
        │                                                 │
        └─────────────────────────────────────────────────┘
```

| Slot | Atom | Token | Rule |
|---|---|---|---|
| Illustration | One Lucide line icon (24px) **or** one custom 1.5px line abstraction | `text-ink-7`, `strokeWidth={1.5}`, `aria-hidden` | Optional; never a raster image, multi-color illustration, character, or emoji |
| Title | Real `<h3>` | `display-sm` (20px, General Sans) `text-ink-11` | Required; a plain noun phrase stating what is absent |
| Description | `<p>` | `text-sm` (Inter) `text-ink-9`, `max-w-sm` | Optional but encouraged for first-run; one or two sentences of *why* |
| Primary action | `Button variant="default" size="sm"` | inherits `accent` fill / `accent-on` text | At most one; permission-checked; the single most useful next step |
| Secondary action | `Button variant="ghost" size="sm"` or link | `text-ink-9` / `accent` link | At most one; lower-emphasis second intent |

Nothing in the composition uses a colored background wash, a heavy border, or a shadow — an empty state sits
flat on the region's own surface. That flatness is deliberate: a bordered, shadowed empty box reads as "an
error occurred," which is precisely the confusion the pattern exists to prevent. The card treatment belongs
to the error pattern's *tone glyph*, not to a background.

Sizing follows the region it fills, via a `size` axis:

| `size` | Padding rhythm | Glyph | Title step | Use |
|---|---|---|---|---|
| `inline` | `py-6`, `gap-2` | 20px | `display-sm` | A compact widget/KPI tile with no data |
| `region` (default) | `py-12`, `gap-3` | 24px | `display-sm` | A table body, panel, or dashboard-widget interior |
| `page` | `py-20`, `gap-4` | 24px | `display-lg` | A full route-level empty (a first-run module hub) |

# Variants

The pattern renders one of **four semantically distinct kinds**, selected by the `kind` prop. The kind is
not cosmetic — it changes the copy register, whether a primary action appears, and what that action does.

| `kind` | Meaning | Default glyph (Lucide) | Primary action | Copy register |
|---|---|---|---|---|
| `first_run` | The dataset has genuinely never held anything | `FileText` (or a custom line abstraction) | The setup/create CTA, if the viewer holds the permission | Inviting, forward-looking |
| `filtered` | The dataset is non-empty, but the active search/filter matched zero rows | `SlidersHorizontal` | "Clear filters" (a secondary) — **never** a create CTA | Neutral, corrective |
| `permission_restricted` | Data may exist, but the viewer's role cannot populate it or is not the actor | `Lock` | None; a redirect/explain link at most | Non-accusatory |
| `error_adjacent` | Empty because an *expected, recoverable* upstream is briefly unavailable | `Sparkles` (AI) | A retry or a "meanwhile" link — never a create CTA | Reassuring, temporary |

## The `first_run` vs `filtered` split

This is the single most important distinction and the one most often collapsed. A user who has filtered a
4,000-row ledger down to zero must never be told "No entries yet — create your first entry" (their books are
full; the CTA is nonsense and the copy is alarming). They must be told "No rows match your filters," with a
way to clear them. `DataTable` makes this automatic — a zero-length result *with active filters* renders
`kind="filtered"`, without them renders `kind="first_run"` — but any screen composing the pattern by hand is
responsible for passing the correct `kind` from its own filter state.

## The `error_adjacent` vs true error boundary

The hardest boundary is `error_adjacent` empty versus a genuine error state. The rule: if the absence is an
*expected* consequence of a known, documented-to-fail-soft condition — the AI engine returning `503` with
`Retry-After`, or a bank account with no feed connected yet — it is an empty state,
`kind="error_adjacent"`. If the absence is an *unexpected* failure of a call that should have returned data
— a `500`, a dropped ledger fetch — it is the error pattern with a retry. The two are never the same
surface, because a scary error box in place of "the AI is briefly resting" misinforms the user, and a
reassuring "temporarily unavailable" in place of a real `500` hides a fault. See
[`./ERROR_STATES.md → Mapping the ApiError envelope`](./ERROR_STATES.md).

# Composition

The pattern is assembled entirely from existing atoms — it introduces no new primitive.

| Concern | Atom / token source |
|---|---|
| Glyph | Lucide line icon at 24px, or a custom stroke-only abstraction ([`../ICON_SYSTEM.md`](../ICON_SYSTEM.md)) |
| Title / description type | `display-sm` / `display-lg` / `text-sm` from [`../TYPOGRAPHY.md`](../TYPOGRAPHY.md) |
| Actions | `Button` primitive ([`../components/BUTTON.md`](../components/BUTTON.md)) — real `href`/`onClick`, never `<div onClick>` |
| Surrounding surface | The region's own `Card`/panel ([`../components/CARD.md`](../components/CARD.md)) stays fully rendered; the empty state fills only the body |
| Colors | `ink-7` / `ink-9` / `ink-11` + `accent` (via `Button`) from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) |
| Motion | The region's own `fadeUp` on mount; the empty state itself has **no** dedicated animation |

## Motion

The empty state does not animate on its own. When a filtered fetch resolves to zero rows, the empty state
appears with the region's standard content-swap — a single `fadeUp` (`motion.base` in, `motion.fast` out)
from [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md), collapsing to an instant appearance under
`prefers-reduced-motion`. The glyph never pulses, the illustration never has ambient motion, and there is no
entrance stagger — an empty state is a settled condition, and animating it would imply activity where there
is none. The only moving pixels the pattern permits are the standard `Button` press/hover micro-states on
its actions.

## Reference composition

```tsx
// A first-run empty ledger, with a permission-gated primary action.
// EmptyState is the app-level component; this pattern governs how it is arranged and toned.
import { EmptyState } from '@/components/shared/empty-state';
import { useTranslations } from 'next-intl';

export function LedgerEmpty() {
  const t = useTranslations('generalLedger.empty');
  return (
    <EmptyState
      kind="first_run"
      icon="ruled-page"                              // a custom 1.5px line abstraction, ink-7, aria-hidden
      title={t('title')}                             // "No posted entries yet"
      description={t('body')}                         // "Post your first journal entry to see it appear here."
      primaryAction={{
        label: t('newEntry'),
        href: '/accounting/journal-entries/new',
        permission: 'accounting.journal.create',      // omitted (not disabled) if the viewer lacks it
      }}
    />
  );
}
```

```tsx
// Filtered-to-nothing. DataTable supplies this variant itself from shared copy; shown here
// for a hand-composed table so the correct kind is passed from the screen's own filter state.
<EmptyState
  kind="filtered"
  title={t('common.noRowsMatchFilters')}
  secondaryAction={{ label: t('common.clearFilters'), onClick: clearFilters }}
/>
```

```tsx
// Permission-restricted statement — no CTA, a non-accusatory "ask someone who can" line.
<EmptyState
  kind="permission_restricted"
  title={t('balanceSheet.empty.askManager')}          // "Ask your Finance Manager to generate this period's Balance Sheet"
/>
```

# States & Behavior

The empty state is a *terminal* rendering — it is what a region shows after loading resolves to nothing — so
its "states" are its four kinds plus the permission-driven action fallback, not a loading/hover/error
lifecycle.

| State | Trigger | Rendering |
|---|---|---|
| First-run | Settled empty, no active filters | Inviting copy; permitted create CTA if the viewer holds the key |
| First-run, viewer can't create | As above, but `primaryAction.permission` ungranted | CTA **omitted, not disabled**; copy falls back to a `permission_restricted`-style "ask someone who can" line |
| Filtered-empty | Settled zero rows *with* active search/filter | Corrective copy; "Clear filters" secondary; never a create CTA |
| Permission-restricted | Viewer's role cannot populate/generate | Non-accusatory copy; at most an explain/redirect link |
| Error-adjacent | Empty because an expected, recoverable upstream is briefly down | "Temporarily unavailable" copy; a retry or "meanwhile" link |
| Action hover/focus | Pointer/keyboard on a rendered action | Standard `Button` hover/focus; the text and glyph are non-interactive |

## Behavioral rules

- **No loading state on the empty state itself.** The region shows the loading pattern *until* the query
  settles, and only then, on a genuinely empty result, does the empty state mount. If it flashes for a frame
  before data arrives, the region rendered empty *before* its query resolved — the fix is to gate on
  `isPending`, never on `data ?? []` being momentarily empty.
- **Action policy: at most one primary, at most one secondary.** The primary is always the single most
  useful next step and is always permission-checked; if the viewer cannot perform it, it is *omitted*, not
  shown disabled with a tooltip — an empty state has no dense context to justify an unusable control.
- **`filtered` never offers a create CTA.** The corrective action is to change the filter; offering "New
  entry" to someone whose books are full but filtered is actively confusing.
- **Scope: fill the region, not the page.** The empty state fills exactly the region that would have held
  the data — a table body, a panel interior, a widget's inside — while the surrounding chrome (toolbar,
  header, filters, page-level actions) stays fully rendered and interactive, so the user can still act to
  change the emptiness. Only a genuinely empty *route* uses `size="page"`.

# Content & Copy Guidance

Copy is where an empty state earns its place; the graphic is a quiet anchor, and the words do the work.

- **Title is a plain noun phrase** stating what is absent: "No journal entries yet," "No unmatched lines,"
  "No postable accounts yet." Never a generic "No data" (reads as unfinished) and never an exclamation.
- **Description explains *why* or *what populating it takes*** in one or two calm sentences. First-run
  descriptions are forward-looking ("Post your first entry to start your books"); filtered descriptions are
  corrective ("No entries match your filters"); restricted descriptions are non-accusatory ("Ask your
  Finance Manager to generate this period's Balance Sheet").
- **Per-module copy is authored, not templated** — each module owns its own first-run keys; the shared
  *filtered* string ("No rows match your filters") is owned centrally by `DataTable` so it never diverges.

| Region | `kind` | Title (EN) | Primary action (permission) |
|---|---|---|---|
| Empty ledger | `first_run` | "No posted entries yet" | "New entry" (`accounting.journal.create`) |
| No journal entries | `first_run` | "No journal entries yet" | "New entry" (`accounting.journal.create`) |
| No unmatched bank lines | `first_run` | "No statement imported yet for this account" | "Import statement" (`bank.reconcile`) |
| Reconciliation filtered to zero | `filtered` | "No lines match your search" | — (Clear filters, secondary) |
| Empty Chart of Accounts | `first_run` | "No postable accounts yet" | "Set up chart of accounts" (`accounting.coa.manage`) |
| Balance Sheet, viewer can't generate | `permission_restricted` | "Ask your Finance Manager to generate this period's Balance Sheet" | — |
| AI insights down | `error_adjacent` | "AI insights are temporarily unavailable" | "Retry" (backs off on `Retry-After`) |

- **No blame.** Never "you have no entries"; state the condition, not a fault.
- **Arabic empties are parallel originals**, written to the professional Gulf-business register, never
  machine-translated — an over-translated or too-casual empty state undermines the exact first impression it
  exists to make. Example first-run Arabic: "لا توجد قيود بعد. اربط حسابًا بنكيًا أو أنشئ أول قيد محاسبي."
- **Every string is an i18n key** present in both `en.ts` and `ar.ts`; a key in one and missing in the other
  fails `i18n:check`. Any embedded numeral or permission key stays a Western-digit / Latin `dir="ltr"` token.

# Accessibility

- **Announced as status, not alarm.** The container carries `role="status"` (`aria-live="polite"`), so when
  a filtered fetch resolves to an empty state the change is announced calmly — "No entries match your
  filters" — without the interrupting `role="alert"` reserved for genuine errors. An empty state is **never**
  `role="alert"`; that assertive announcement is the error pattern's, and the polite/assertive split is the
  auditory version of the visual distinction between the two patterns.
- **The graphic is decorative.** The illustration slot is `aria-hidden`; it repeats nothing the title and
  description do not already say in text. There is never an empty state whose meaning lives only in a
  picture.
- **A real heading.** The title is a real `<h3>` in the region's heading hierarchy, so an empty region is a
  navigable landmark in the document outline — a screen-reader user scanning by heading finds "No journal
  entries yet" as a real stop, not an anonymous centered `<div>`.
- **Actions are keyboard-reachable and labeled.** Primary/secondary actions are `Button`s — focusable,
  `Enter`/`Space`-activatable, with real accessible names; the entire button accessibility contract applies
  unchanged.
- **Not a silent blank.** The whole reason the pattern exists is that a data region must never render as an
  empty `<table>` or a blank panel with no announced content.
- **Contrast holds in both themes.** `ink-11` title / `ink-9` description / `ink-7` glyph all clear their AA
  targets against the region surface in light and dark; the glyph, being decorative, is exempt from the
  text floor but is kept legible regardless.

# Theming, Dark Mode & RTL

## Theming

The pattern references only semantic tokens — `text-ink-11`, `text-ink-9`, `text-ink-7` — and its actions
inherit the accent through `Button`. There is no background or border token on the empty state itself
(intentionally: it sits flat), so a white-label accent override touches only the primary action's fill,
exactly as intended. No raw hex or Tailwind palette color appears.

## Dark mode

No `dark:` variants. The ink and accent tokens remap under `.dark` (`../DESIGN_TOKENS.md` light/dark tables):
the title flips to near-white `ink-11`, the description and glyph lighten to keep the same relative
contrast, and the custom line-drawing glyphs — being stroke-only, `currentColor`-driven SVG — recolor
automatically with the ink token rather than needing a separate dark asset. A raster illustration would need
a dark variant; QAYD's stroke-only stance is exactly what avoids that.

## RTL

- **Centered layout is direction-neutral**, so the vertical stack needs no mirroring; title, description,
  and the action row all center identically in LTR and RTL.
- **Action order mirrors logically.** The action row uses logical flow, so "secondary then primary" lands
  with the primary on the inline-end edge in both directions, matching `ButtonGroup`.
- **Object glyphs don't flip; directional glyphs do.** An "empty-tray"/"documents"/"ruled-page" abstraction
  depicts a static object and never mirrors; a directional arrow glyph, were one ever used, would flip via
  the `[dir="rtl"]` transform per [`../ICON_SYSTEM.md`](../ICON_SYSTEM.md).
- **Arabic copy is first-class**, rendered in IBM Plex Sans Arabic with the taller Arabic line-height; a long
  Arabic title is `max-w`-bounded and wraps to two lines rather than truncating.

# Do / Don't

| Do | Don't |
|---|---|
| Render an empty state only on a *settled* empty result | Show an empty state (or a "spinner") before the query resolves |
| Pass the correct `kind` from the screen's own filter state | Show "create your first entry" to someone who filtered a full ledger to zero |
| Offer at most one permission-checked primary action | Dangle a disabled-with-tooltip CTA the viewer can't press |
| Route a documented AI soft-fail `503` to `error_adjacent` | Render a scary `ErrorState` box for "the AI is briefly resting" |
| Keep the surface flat — whitespace and centering only | Wrap the empty state in a bordered, shadowed, tinted box |
| Use `role="status"` (polite) | Use `role="alert"` — that assertive announcement belongs to errors |
| Author per-module copy in both languages as parallel originals | Ship a generic "No data" or a machine-translated Arabic line |
| Keep the glyph `aria-hidden` and let title/description carry meaning | Put the only meaning of the emptiness in a picture |
| Fill the region; leave the toolbar and filters interactive | Take over the whole page for a single empty widget |

# End of Document
