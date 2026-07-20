# Filters — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / FILTERS
---

# Purpose

Every list and table screen in QAYD — Journal Entries, General Ledger, Invoices, Banking
transactions, ledger search, the aging registers — narrows a large collection down to the rows a user
actually needs, and it does so through one shared filtering system. This document is the binding
contract for that system: the filter bar (desktop) and filter drawer (tablet) surfaces, filter
chips/pills with clear-all, the date-range and fiscal-period filters, multi-select facet filters, the
dimension filters QAYD's accounting model runs on (cost center, project, department, branch),
search-within-a-filter, saved filter views, the URL/search-param serialization that makes every
filtered view a shareable, refresh-surviving deep link, the applied-filter summary, and the hand-off
to an empty state when a filter matches nothing.

The filtering system sits directly on top of two things it never redefines: `DataTable`'s
server-driven query model ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# DataTable`) and the
platform's `filter[...]`/`sort`/`q` query grammar (`docs/api/API_FILTERING_SORTING.md`). A QAYD filter
never filters, sorts, or paginates client-side — it produces a `filter[...]` object, hands it to
`DataTable`, and every change is a new server request. This is the same posture the whole frontend
takes ([`../README.md`](../README.md) `# Overview`): the client composes a query; the server computes
the answer. RBAC applies as everywhere else — a facet whose values a role cannot read is not offered,
and a dimension the role has no permission to slice by is absent from the bar, not shown disabled with
empty options.

This document assumes [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) (`DataTable`,
`PeriodPicker`, `StatusPill`, the empty-state component), [`INPUTS.md`](./INPUTS.md) (the
`DateRangePicker`, `MultiSelect`, `Combobox`, `SearchInput` primitives a filter is built from),
[`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md) (the deep-linking and `?branch=` conventions this
extends), and the platform's `API_FILTERING_SORTING.md` and `API_PAGINATION.md` are open alongside it.

# Anatomy & Variants

A filtered list screen is three cooperating regions, top to bottom:

1. **The filter surface** — a horizontal **filter bar** at `lg`+ (a row of filter controls under the
   page header) or a **filter drawer** (a `Sheet` opened by a "Filters" button) at tablet/`md` and
   below, where a horizontal bar would either wrap ungracefully or steal the width a dense table needs.
   Both surfaces produce the identical `filter[...]` object; the drawer is a responsive presentation of
   the same controls, never a second filter model.
2. **The applied-filter summary** — a strip of filter chips (one per active filter value) plus a
   "Clear all" affordance and, optionally, a saved-view selector. This is the always-visible record of
   *what is currently narrowing the list*, so a user (or a colleague opening a shared link) can see and
   undo the scope without opening the bar or drawer.
3. **The `DataTable`** — which owns pagination/sort/network and simply receives the composed
   `filter[...]` as its `defaultFilters`/applied filters.

## The filter bar vs. the filter drawer

| | Filter bar (`lg`+) | Filter drawer (`md` and below) |
|---|---|---|
| Trigger | Always visible under the page header | A "Filters" button (with an active-count badge) opens a `Sheet` from the inline-end edge |
| Layout | A wrap-tolerant flex row of filter controls, `gap-2` | A vertical stack of the same controls inside the `Sheet` |
| Apply model | Each control applies on change (live), debounced for text | Controls stage inside the drawer; an "Apply filters" button commits them in one navigation, so a tablet user is not firing a request per tap |
| Chips | Rendered in the summary strip below the bar | Rendered in the summary strip above the table, same as bar mode |
| Shared code | `FilterBar` and `FilterDrawer` both render the same `<FilterField>` set from one `filters` config array — never two definitions | |

The choice between the two is a responsive-layout decision resolved from breakpoint
([`../RESPONSIVE_DESIGN.md`](../RESPONSIVE_DESIGN.md)), not a per-screen author choice; a screen
declares its filter set once and both surfaces render it.

## Filter kinds

The system ships a fixed set of filter *kinds*, each a thin wrapper over an [`INPUTS.md`](./INPUTS.md)
primitive that knows how to serialize itself into the `filter[...]` grammar:

| Kind | Control | `filter[...]` shape | Example |
|---|---|---|---|
| `enum` (single) | `Select` | `filter[status]=posted` | Entry status |
| `enum` (multi / facet) | `MultiSelect` | `filter[status][in]=draft,posted` | Statuses, entry types |
| `entity` (facet) | `Combobox` (server-searched) | `filter[account_id][in]=…` | Account, customer, vendor |
| `dimension` | `Combobox` per dimension | `filter[cost_center_id]=…` | Cost center, project, department, branch |
| `date_range` | `DateRangePicker` | `filter[entry_date][between]=2026-07-01,2026-07-31` | Posting date window |
| `fiscal_period` | `PeriodPicker` | resolves to a `filter[entry_date][between]` for the period | July 2026 |
| `amount_range` | two `NumberInput`s | `filter[total_debit][gte]=…&[lte]=…` | Amount band |
| `aging_bucket` | `Select`/`MultiSelect` | `filter[aging_bucket][in]=31-60` | AR/AP aging |
| `boolean` | `Checkbox`/`Switch` | `filter[has_attachment]=true` | Flags |
| `text` (within-filter) | `SearchInput` | narrows the facet's own option list; not a top-level `q` | Search-within a long account facet |

## The reference filter bar

```tsx
// components/shared/filter-bar.tsx (shape)
'use client';
import { FilterChips } from '@/components/shared/filter-chips';
import { FilterField } from '@/components/shared/filter-field';
import { SavedViewsMenu } from '@/components/shared/saved-views-menu';
import { useFilters } from '@/hooks/use-filters';
import type { FilterConfig } from '@/types/filters';

export function FilterBar({ resource, filters }: { resource: string; filters: FilterConfig[] }) {
  const { value, set, clear, clearAll, activeCount } = useFilters(resource, filters);
  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        {filters.map((f) => (
          <FilterField key={f.key} config={f} value={value[f.key]} onChange={(v) => set(f.key, v)} />
        ))}
        <SavedViewsMenu resource={resource} current={value} className="ms-auto" />
      </div>
      <FilterChips filters={filters} value={value} onClear={clear} onClearAll={clearAll} activeCount={activeCount} />
    </div>
  );
}
```

`useFilters` is the single hook that owns the whole system's state — it reads the current filters from
the URL, writes changes back to the URL, and exposes `value`/`set`/`clear`/`clearAll`. Both the bar
and the drawer consume it; neither holds filter state locally, which is what keeps the URL the one
source of truth (below).

## The applied-filter summary (chips)

The chip strip is the always-visible record of the current scope. Each active filter value renders one
removable chip; a "Clear all" affordance follows the last chip; the whole strip is hidden when nothing
is active so a clean list carries no chrome.

```tsx
// components/shared/filter-chips.tsx (shape)
'use client';
import { X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from 'next-intl';
import { chipLabel } from '@/lib/filters/chip-label';   // "Status is Posted", "Date 01/07 – 31/07"

export function FilterChips({ filters, value, onClear, onClearAll, activeCount }: FilterChipsProps) {
  const t = useTranslations('filters');
  if (activeCount === 0) return null;
  return (
    <div className="flex flex-wrap items-center gap-1.5" aria-label={t('appliedFilters')}>
      {filters.filter((f) => value[f.key] != null).map((f) => (
        <Badge key={f.key} tone="neutral" className="gap-1 ps-2 pe-1">
          <span className="truncate max-w-[16rem]">{chipLabel(f, value[f.key])}</span>
          <button type="button" onClick={() => onClear(f.key)} aria-label={t('removeFilter', { name: chipLabel(f, value[f.key]) })}
                  className="rounded-full p-0.5 text-ink-500 hover:bg-ink-150 hover:text-ink-950
                             focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600">
            <X className="h-3 w-3" aria-hidden />
          </button>
        </Badge>
      ))}
      <Button variant="link" size="sm" onClick={onClearAll}>{t('clearAll')}</Button>
    </div>
  );
}
```

`chipLabel` is the one place a filter value becomes prose, per filter kind and per language — a
date-range chip reads `01/07/2026 – 31/07/2026` (`latn`, `dir="ltr"`), an entity chip reads the
account's `name_en`/`name_ar`, a multi-enum chip collapses to "Status: Draft +2" past two values with
the full set on hover. It never string-concatenates English word order; the templates are authored per
language.

# Props / API

## `useFilters(resource, filters)`

| Return | Type | Description |
|---|---|---|
| `value` | `Record<string, FilterValue>` | The current, decoded filter values, one entry per active filter key. |
| `set` | `(key: string, value: FilterValue \| null) => void` | Sets or clears one filter; writes the URL via `router.replace` (not `push` — a filter change is not a distinct history entry, see `# Validation & Behavior`). |
| `clear` | `(key: string) => void` | Removes one filter (a chip's ✕). |
| `clearAll` | `() => void` | Removes all filters (the "Clear all" affordance); leaves non-filter params (`sort`, `branch`) intact. |
| `activeCount` | `number` | Count of active filters, driving the drawer trigger's badge and the summary. |
| `toApiFilters` | `() => Record<string, unknown>` | The composed `filter[...]` object handed to `DataTable`. |

## `FilterConfig`

| Field | Type | Required | Description |
|---|---|---|---|
| `key` | `string` | yes | The URL param and the API `filter[<key>]` name (e.g. `status`, `cost_center_id`, `entry_date`). |
| `kind` | filter kind (above) | yes | Selects the control and the serializer. |
| `label` | `string` | yes | i18n key; the chip and control label. |
| `options` | `{ value; label }[]` | conditional | For in-memory `enum`/`boolean` kinds. |
| `resource` | `string` | conditional | For `entity`/`dimension` kinds — the server search endpoint (per `AccountPicker`). |
| `permission` | `string` | no | If present, the filter is omitted entirely when the role lacks it (a `branch` dimension for a role with no cross-branch read). |
| `operator` | `'eq' \| 'in' \| 'between' \| 'gte_lte'` | no | The `filter[...]` operator; defaulted from `kind`. |
| `defaultValue` | `FilterValue` | no | A screen default (e.g. Journal Entries defaults `status` to the working set), applied only when the URL carries no value for the key. |

## `<FilterChips>`

| Prop | Type | Description |
|---|---|---|
| `value` | `Record<string, FilterValue>` | Active filters to render as chips. |
| `filters` | `FilterConfig[]` | For resolving each key's label and value-label. |
| `onClear` | `(key: string) => void` | Remove one filter. |
| `onClearAll` | `() => void` | Remove all filters. |
| `activeCount` | `number` | Hides the whole strip when `0`. |

# States

| State | Filter surface | Table |
|---|---|---|
| No filters active | Bar shows controls at rest; chip strip and "Clear all" hidden; drawer badge absent | Full, default-sorted collection (the resource's own default filters may still apply — e.g. Journal Entries' working-status default) |
| Filters active | Chips render one per value; "Clear all" visible; drawer badge shows count | Filtered result set |
| Applying (in flight) | Controls stay interactive; a filter change sets the table's `aria-busy` and keeps the prior rows via `keepPreviousData` so the layout does not flash | Previous rows dim slightly until the new page resolves |
| Empty after filter | Chips + "Clear all" remain visible (the user must be able to undo the scope that produced zero rows) | The *filtered-empty* variant of the empty state — "No rows match your filters" with a one-click "Clear filters" — distinct from the *genuinely-empty* collection state (see `# Edge Cases` and `DataTable`'s `emptyState`) |
| Invalid filter combination | The offending control shows an inline hint (e.g. an amount `min` above `max`); no request fires until it is resolved | Prior rows retained |
| Saved view active | The saved-views selector shows the view name; editing any filter marks the view "modified" until re-saved or reverted | Result set for the view's filters |

# Validation & Behavior

## URL / search-param serialization — the source of truth

Every filter lives in the URL, and the URL is the single source of truth for the filtered view. This
is the same contract [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md) `# Deep Linking` establishes
for the app as a whole ("no nav interaction is allowed to produce a screen configuration a link cannot
reproduce") and that [`../README.md`](../README.md)'s state table encodes ("Route search params —
unless the state must survive a refresh or be linkable"). Filters must survive a refresh and be
shareable, so they are URL state, never component `useState`.

- **Encoding.** Filters serialize into the platform's own query grammar so a filtered link is the
  exact URL the API speaks: `/accounting/journal-entries?filter[status][in]=draft,posted&filter[entry_date][between]=2026-07-01,2026-07-31&sort=-entry_date`.
  A single serializer (`lib/nav/search-params.ts`, referenced in
  [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)) owns encode/decode so the bar, the drawer, a
  Command Palette result link, and a notification's `target` all produce byte-identical URLs.
- **Read on mount, URL wins.** `useFilters` decodes the URL on first render and treats it as
  authoritative, falling back to a filter's `defaultValue` only when the URL carries no value for that
  key — mirroring the branch-scope ordering in [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)
  (URL wins over store on load). A shared deep link therefore opens in exactly the sender's scope,
  never silently overridden by the recipient's last session.
- **Write with `replace`, not `push`.** Changing a filter uses `router.replace` so the back button
  returns the user to the *previous screen*, not through every intermediate filter permutation they
  tapped — a filter is a refinement of the current view, not a navigation.
- **Non-filter params are preserved.** `clearAll` and any single `set` leave `sort`, `cursor`/`page`,
  and `?branch=` untouched; the filter serializer only ever owns the `filter[...]` and its own keys.
- **Branch is a dimension but a shared one.** The `branch` scope is serialized as `?branch=<id>` (the
  convention `NAVIGATION_SYSTEM.md` already owns), not as `filter[branch_id]`, so it stays consistent
  with the company/branch switcher; a `branch` *filter kind* on a screen that also slices by branch
  reuses that same param rather than inventing a second one.

```tsx
// hooks/use-filters.ts (URL read/write core — shape)
'use client';
import { useSearchParams, useRouter, usePathname } from 'next/navigation';
import { decodeFilters, encodeFilters } from '@/lib/nav/search-params';

export function useFilters(resource: string, config: FilterConfig[]) {
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const value = decodeFilters(params, config);            // URL → typed values, applying defaults only when absent

  function commit(next: Record<string, FilterValue>) {
    const qs = encodeFilters(next, config, params);        // preserves sort/cursor/branch
    router.replace(`${pathname}?${qs}`, { scroll: false }); // replace, not push
  }
  // set / clear / clearAll delegate to commit; toApiFilters maps value → filter[...] object.
  // …
}
```

## Applying and debouncing

- **Enum/entity/date/dimension filters apply on change** — selecting a status or a cost center is one
  discrete action and fires one request.
- **Text-within-filter and amount-range apply debounced** (250ms shared debounce) so typing does not
  spawn a request per keystroke, consistent with the `Combobox`/`SearchInput` debounce in
  [`INPUTS.md`](./INPUTS.md).
- **The tablet drawer stages, then applies** — its controls edit a local draft, and one "Apply
  filters" button commits the whole draft to the URL in a single navigation, so a touch user tuning
  four filters fires one request, not four.

## Saved filter views

A saved view is a named bundle of the current filters (and optionally the current sort and density),
stored per-user-per-resource, so a controller who runs "Unreconciled, this fiscal period, Kuwait
branch, over KD 1,000" every morning re-applies it in one click.

- **Storage.** Saved views persist to the user's preferences via the API
  (`PATCH /api/v1/users/me/preferences` bucket, as [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)
  does for chrome prefs) so they follow the user across devices — not `localStorage`.
- **Applying a view rewrites the URL** to that view's filter set, so a saved view is still just a
  deep-linkable URL; sharing the link shares the view's scope even with a colleague who does not have
  the saved view.
- **A modified view is flagged, never silently overwritten** — editing a filter while a view is active
  marks it "modified," offering "Update view" / "Save as new" / "Revert," so a user never loses a saved
  scope by tweaking it once.
- **Views respect RBAC at apply time** — a saved view referencing a dimension the user has since lost
  permission for drops that clause with an inline notice, rather than firing a request the server would
  `403`.

## RBAC and dimension filters

The dimension filters (cost center, project, department, branch) are the accounting model's slicing
axes. Each carries a `permission`; a role without it does not see that dimension in the bar or the
drawer at all — its absence is intentional and matches the platform's "omit, don't disable, when the
control's existence is itself sensitive" rule ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)
`# Edge Cases → Row action visibility`). A dimension the user *may* slice by but which currently has no
values (a company with no projects configured) renders the control with an empty-source hint, not a
broken empty dropdown ([`INPUTS.md`](./INPUTS.md) `# Edge Cases`).

## Empty-after-filter hand-off

When a filter set matches zero rows, the table renders the **filtered-empty** state, not the
collection-empty state — the two carry different messages and different recovery actions, and
conflating them misleads the user about whether the data exists at all:

- **Filtered-empty**: "No rows match your filters." + a primary "Clear filters" action (calls
  `clearAll`) and, where useful, a suggestion to widen the date range. The chip strip stays visible so
  the user sees exactly which clauses produced the miss.
- **Collection-empty**: "No journal entries yet." + a create action — reserved for a genuinely empty
  collection with no filters active, owned by the screen's own `DataTable` `emptyState`.

Both use the shared `EmptyState` component ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)
`# DataTable`, `emptyState` prop; `components/shared/empty-state.tsx`), with the filtered variant
selected automatically by `DataTable` when `activeCount > 0`.

## The tablet filter drawer

Below `md`, the same `filters` config renders inside a `Sheet` that stages a draft and commits it in
one navigation, so a touch user tuning several filters fires one request, not one per tap.

```tsx
// components/shared/filter-drawer.tsx (shape)
'use client';
import { useState } from 'react';
import { Sheet, SheetTrigger, SheetContent, SheetHeader, SheetTitle, SheetFooter } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { FilterField } from '@/components/shared/filter-field';
import { SlidersHorizontal } from 'lucide-react';
import { useFilters } from '@/hooks/use-filters';
import { useTranslations } from 'next-intl';

export function FilterDrawer({ resource, filters }: { resource: string; filters: FilterConfig[] }) {
  const t = useTranslations('filters');
  const { value, applyDraft, activeCount } = useFilters(resource, filters);
  const [draft, setDraft] = useState(value);        // stage locally; URL unchanged until Apply
  const [open, setOpen] = useState(false);

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>
        <Button variant="outline" size="sm" className="gap-2">
          <SlidersHorizontal className="h-4 w-4" aria-hidden /> {t('filters')}
          {activeCount > 0 && <Badge tone="accent" className="px-1.5">{activeCount}</Badge>}
        </Button>
      </SheetTrigger>
      <SheetContent side="inline-end" className="w-[min(90vw,380px)]">
        <SheetHeader><SheetTitle>{t('filters')}</SheetTitle></SheetHeader>
        <div className="flex-1 space-y-4 overflow-y-auto py-4">
          {filters.map((f) => (
            <FilterField key={f.key} config={f} value={draft[f.key]}
                         onChange={(v) => setDraft((d) => ({ ...d, [f.key]: v }))} orientation="stacked" />
          ))}
        </div>
        <SheetFooter className="flex-row justify-between">
          <Button variant="ghost" onClick={() => setDraft(value)}>{t('reset')}</Button>
          <Button onClick={() => { applyDraft(draft); setOpen(false); }}>{t('apply')}</Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  );
}
```

The active-count badge is the tablet equivalent of the desktop chip strip's presence; the drawer's
`side="inline-end"` resolves to `right` in LTR and `left` in RTL from `dir`, never hardcoded (the
`Sheet` rule in [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Primitives`). Canceling the
`Sheet` discards the draft and leaves the current URL scope untouched.

# Composition Patterns

## Pattern 1 — a filtered list screen end to end

A list screen wires the filter surface, the summary, and `DataTable` together, and each piece knows
only the shared `filter[...]` object — never the others directly, exactly as
[`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Composition Patterns → Pattern 1` establishes.

```tsx
// app/(app)/accounting/general-ledger/page.tsx (Client shell — shape)
'use client';
import { FilterBar } from '@/components/shared/filter-bar';
import { FilterDrawer } from '@/components/shared/filter-drawer';
import { DataTable } from '@/components/shared/data-table';
import { useFilters } from '@/hooks/use-filters';
import { useBreakpoint } from '@/hooks/use-breakpoint';
import { ledgerColumns, ledgerFilters } from './config';

export function GeneralLedgerView() {
  const isDesktop = useBreakpoint('lg');
  const { toApiFilters, activeCount } = useFilters('accounting/ledger-entries', ledgerFilters);
  return (
    <div className="space-y-4">
      {isDesktop
        ? <FilterBar resource="accounting/ledger-entries" filters={ledgerFilters} />
        : <FilterDrawer resource="accounting/ledger-entries" filters={ledgerFilters} />}
      <DataTable
        columns={ledgerColumns}
        resource="accounting/ledger-entries"
        paginationMode="cursor"                 // ledger is cursor-only; a filter change resets the cursor
        appliedFilters={toApiFilters()}
        emptyState={{ variant: activeCount > 0 ? 'filtered' : 'collection' }}
        getRowId={(r) => String(r.id)}
      />
    </div>
  );
}
```

`ledgerFilters` is the one `FilterConfig[]` both the bar and the drawer render; `DataTable` receives
only the composed `filter[...]` object and the active-count-derived empty variant. Neither the bar nor
the table knows the other exists beyond that object — which is exactly what keeps a screen with no
filters (`Accounts`) and a screen with a dozen (`General Ledger`) on the same two components.

## Pattern 2 — search-within a long facet

A facet over a large set (all accounts, all vendors) never ships a client-side option list; it uses
the server-searched `Combobox` from [`INPUTS.md`](./INPUTS.md), and its search box narrows the facet's
*own* options, distinct from the table's top-level `q`.

```tsx
// components/shared/filter-field.tsx — the entity/dimension branch (shape)
case 'entity':
  return (
    <MultiSelect
      resource={config.resource!}                 // e.g. 'accounting/accounts'
      value={value ?? []}
      onValueChange={onChange}
      searchPlaceholder={t('searchWithin', { field: t(config.label) })}
      renderOption={(a) => <><span className="font-mono text-xs text-ink-500 w-14">{a.code}</span>{localeName(a)}</>}
    />
  );
```

The within-filter search debounces at 250ms and queries the same endpoint `AccountPicker` uses, so a
"cost center" facet over 400 centers stays fast and accessible; the selected values become
`filter[cost_center_id][in]=…` and, separately, chips in the summary.

# Accessibility

| Element | Keyboard | Screen reader | Notes |
|---|---|---|---|
| Filter bar | Each control is individually tab-reachable; the bar is a labelled region (`aria-label="Filters"`) | Controls carry their own labels; the bar announces as a filter region | The bar is not a `role="toolbar"` unless it also has arrow-key roving — plain tab order is the default. |
| Filter drawer | The "Filters" trigger opens a Radix `Sheet` that traps focus; `Esc` cancels the staged draft without applying | The trigger's active-count is real text ("Filters (3)"), not a color-only badge | "Apply filters" is the drawer's single primary action; canceling discards the draft, not the current URL scope. |
| Filter chips | Each chip's ✕ is a real focusable `<button>` with an accessible name ("Remove filter: Status is Posted") | The chip text is the accessible name; the ✕ names the specific filter it clears | "Clear all" is a labelled button, reachable after the last chip. |
| Applied-filter change | — | The result-count change is announced via an `aria-live="polite"` region ("48 entries match"), so a screen-reader user knows a filter took effect without inspecting the table | Never a silent table swap. |
| Empty-after-filter | The "Clear filters" action is the first focusable element in the empty region | The empty region is a landmark with a heading, not a silent blank table | Matches `DataTable`'s empty-state accessibility row. |
| Locked/omitted dimensions | — | An omitted dimension simply is not announced; a *disabled-by-state* facet (not by permission) carries a reason via tooltip/`aria-describedby` | Preserves the "who can see this exists vs. why can't I use it now" distinction. |

All of the above sit on the WCAG 2.1 AA baseline of [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md);
focus rings are keyboard-only (`focus-visible`), and chip/clear affordances are never conveyed by
color alone.

# Theming, Dark Mode & RTL

- **Chips and controls are ink-scale with one accent.** A filter chip is `ink-100` fill / `ink-700`
  text with an `ink-500` ✕; an *active saved view* selector may carry the single `accent` mark, but a
  filter value chip never uses `accent` as decoration — accent stays reserved for primary actions and
  AI provenance ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Color & Ink`). Status-derived
  chips (a `status:posted` chip) may carry the same desaturated `StatusPill` tone the status uses
  elsewhere, always paired with its label, never color-only.
- **Dark mode is a token remap only** — chip fills, borders, and the drawer surface resolve to their
  dark values with no `dark:` raw-color variant ([`../THEMING.md`](../THEMING.md)).
- **RTL mirrors via logical properties.** The bar's `ms-auto` saved-views alignment, the drawer's
  inline-end slide edge, the chip ✕ position (`pe-*`), and the "Clear all" placement all flip
  automatically under `dir="rtl"`; physical sides are banned in filter source.
- **Filter *values* that are numeric or dated never mirror** — an amount-range chip reads
  `KD 1,000.000 – 5,000.000` and a date-range chip reads `01/07/2026 – 31/07/2026` in `latn` numerals,
  `dir="ltr"`, inside an RTL bar, per the numeral rule.
- **Bilingual value labels.** An entity/dimension chip (an account, a cost center) shows the
  `name_en`/`name_ar` matching `useLocale()`, the same bilingual-data rule the pickers follow.

# i18n & Formatting

- **All filter labels, operators, chip templates, and empty-state copy are i18n keys** present in both
  `en` and `ar`; a key in one and missing in the other fails `npm run i18n:check`
  ([`../README.md`](../README.md) `# Getting Started`). Chip templates interpolate the value label
  ("Status is {value}", "Date between {from} and {to}") and are authored per language, not string-
  concatenated, so Arabic grammar is correct rather than English word-order translated.
- **Dates and amounts in chips and controls format through the same `latn` primitives** as everywhere
  else ([`INPUTS.md`](./INPUTS.md), [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)); KWD defaults
  to 3-decimal display. A filter never hand-rolls a currency or date string.
- **Fiscal-period filters render the period's own bilingual name** (`name_en`/`name_ar` from
  `fiscal_periods`) via `PeriodPicker`, not a client-derived month label, so "July 2026" and its
  Arabic counterpart come from the company's real calendar.
- **Arabic copy is professional-Gulf register**, authored in parallel, not machine-translated
  ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Voice & Microcopy`).

# Testing

- **Serialization unit tests (Vitest).** The encode/decode round-trip is the system's highest-risk
  code and is tested exhaustively: every filter kind encodes to the documented `filter[...]` grammar
  and decodes back to the identical value; `defaultValue` applies only when the key is absent from the
  URL; `clearAll` preserves `sort`/`cursor`/`branch`.

  ```tsx
  // lib/nav/search-params.test.ts
  import { encodeFilters, decodeFilters } from './search-params';
  test('a multi-enum + date-range round-trips to the documented grammar and back', () => {
    const value = { status: ['draft', 'posted'], entry_date: { from: '2026-07-01', to: '2026-07-31' } };
    const qs = encodeFilters(value, journalFilters, new URLSearchParams('sort=-entry_date'));
    expect(qs).toContain('filter[status][in]=draft,posted');
    expect(qs).toContain('filter[entry_date][between]=2026-07-01,2026-07-31');
    expect(qs).toContain('sort=-entry_date');                 // non-filter param preserved
    expect(decodeFilters(new URLSearchParams(qs), journalFilters)).toEqual(value);
  });
  ```

- **Component tests (Vitest + Testing Library).** A chip's ✕ removes exactly its one filter and leaves
  the rest; "Clear all" empties filters but not sort; a permission-gated dimension is absent from the
  rendered bar for a role lacking it; the tablet drawer stages and only commits on "Apply."
- **Playwright (E2E).** The load-bearing integration assertions: applying a filter issues a request
  whose query string matches the documented grammar (not merely that *some* rows changed — the same
  standard `DataTable`'s own Playwright test holds); a shared filtered URL opened in a fresh session
  reproduces the exact scope after a refresh; the empty-after-filter state renders the "Clear filters"
  action and clearing restores the full list.
- **Accessibility CI gate.** Filter-bar, filter-drawer, chips, and empty-after-filter Storybook
  stories run through `axe-core` in all four LTR/RTL × light/dark combinations; a keyboard-only pass
  confirms the drawer's focus trap and the chip/clear tab order before release.

# Edge Cases

- **A filtered link that matches nothing today.** A shared URL scoped to a period now empty (all its
  rows archived) opens on the *filtered-empty* state with its chips intact, so the recipient sees the
  scope and can widen it — never the *collection-empty* "create your first entry" message, which would
  wrongly imply the data never existed.
- **A stale filter value in a link.** A URL referencing a deleted cost center or a since-disabled
  status renders that chip as a visibly-flagged "unknown/removed value" chip the user can clear, rather
  than a blank chip or a silent drop that hides why the result set looks wrong.
- **Permission lost between saving and applying a view.** A saved view that slices by a dimension the
  user no longer may read drops that clause on apply with an inline notice ("Branch filter removed —
  you no longer have access"), and never fires a request the server would `403`.
- **Amount range with `min > max`.** The amount-range control shows an inline validation hint and
  withholds the request until the bounds are coherent; it never sends an impossible `gte`/`lte` pair.
- **A cursor-paginated resource under filtering.** On `ledger-entries`-style cursor endpoints, changing
  a filter resets the cursor to the start (a new filter is a new result stream) and the table keeps its
  "scope this view" nudge, since cursor mode cannot offer a total or a jump-to-last
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases → Huge unfiltered ledger view`).
- **Very long facet lists.** A facet whose option set is large (all accounts, all customers) uses the
  server-searched `Combobox` with search-within, never a giant client-side option list; the "search
  within this filter" box narrows the facet's own options, distinct from the table's top-level `q`.
- **Filter + free-text search together.** A screen may carry both a top-level `q` (searchable rows) and
  structured filters; they compose (both go on the request), and both appear in the summary — the `q`
  as its own removable chip — so clearing search and clearing a filter are independent, visible actions.
- **Branch scope vs. a branch filter.** When a screen offers both the global `?branch=` switcher scope
  and a per-row branch filter, they use the same param and never contradict — the filter kind reads and
  writes `?branch=`, so a user cannot end up scoped to Riyadh globally while filtering to Kuwait rows
  and see an empty, confusing result with no explanation.
- **Reduced motion.** The filter drawer's slide, the chip enter/exit, and any applied-row flash respect
  `prefers-reduced-motion: reduce` and collapse to instant state changes
  ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Motion`).

# End of Document
