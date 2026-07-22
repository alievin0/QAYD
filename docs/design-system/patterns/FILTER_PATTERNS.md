# Filter Patterns — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / FILTERS
---

# Purpose

This document is the design-system contract for QAYD's **filter UX patterns** — the recurring, composed
arrangements by which a list or table screen narrows a large collection to the rows a user needs: the filter
bar (desktop) versus the filter drawer (tablet), filter chips with clear-all, the date/period/amount/facet/
dimension filter kinds, saved views, URL/search-param serialization that makes every filtered view a
deep-link, the applied-filter summary, and the filtered-empty hand-off. A pattern here is a *composition* over
one shared filtering system, not a new primitive.

The app-level authority this document restates at the pattern layer is
[`../../frontend/components/FILTERS.md`](../../frontend/components/FILTERS.md), which owns the full system —
the `useFilters` hook, the `FilterConfig` schema, the serializer, the `DataTable` integration, and the testing
matrix. This document names the reusable *pattern shapes* and their token, motion, and accessibility rules and
cross-links back; where a prop, endpoint, or exact serializer behavior is needed, that document governs. The
controls each filter kind is built from are owned by their own specs — [`../components/SELECT.md`](../components/SELECT.md),
[`../components/DATE_PICKER.md`](../components/DATE_PICKER.md), and the `MultiSelect`/`Combobox`/`SearchInput`
in [`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) — and the free-text `q` that
often sits beside filters is owned by [`SEARCH_PATTERNS.md`](./SEARCH_PATTERNS.md).

Two rules bind every filter pattern below. First, **a QAYD filter never filters, sorts, or paginates
client-side** — it produces a `filter[...]` object, hands it to `DataTable`, and every change is a new server
request. The client composes a query; the server computes the answer. Second, **the URL is the single source
of truth for a filtered view** — filters must survive a refresh and be shareable, so they are URL state, never
component `useState`; a shared deep link opens in exactly the sender's scope. RBAC applies as everywhere: a
facet whose values a role cannot read is not offered, and a dimension the role cannot slice by is absent from
the bar, not shown disabled with empty options.

Every value below references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the `ink-1…12` scale, the single
brass `accent` (reserved for a primary action or AI provenance, never chip decoration), `radius-md`/
`radius-full`, and the `motion.*` durations.

> **Token reconciliation.** [`../../frontend/components/FILTERS.md`](../../frontend/components/FILTERS.md) is a
> pre-canonical app draft that names tokens in the older `ink-100` / `ink-150` / `ink-500` / `ink-700` /
> `accent-600` scheme. This document is canonical and uses the [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md)
> names: the draft's `ink-100` is `ink-2`, its `ink-150` is `ink-6`, its `ink-500` is `ink-9`, its `ink-700`
> is `ink-10`, and its `accent-600` is `accent`. Where the draft's literal class disagrees with a token here,
> the token here governs and the conflict is raised in review.

# When to Use

| Pattern | Use when | Do not use when |
|---|---|---|
| Filter bar | A list screen at `lg`+ has room for a horizontal row of controls under the header | Below `md`, where a bar wraps ungracefully (use the drawer) |
| Filter drawer | Tablet/`md` and below, where a bar would steal a dense table's width | Desktop, where the bar is always visible |
| Filter chips + clear-all | Any active filter set — the always-visible record of current scope | No filters active (the strip hides entirely) |
| Date / period filter | Narrowing by a posting-date window or a fiscal period | An arbitrary text match (that is `q`) |
| Amount-range filter | Narrowing by a signed or absolute amount band | A single exact amount (a facet suits it better) |
| Facet / dimension filter | Slicing by status, entity, or an accounting dimension (cost center, project) | A free-text field |
| Saved view | A user re-runs the same filter set regularly | A one-off exploratory narrowing |
| URL serialization | Always — every filtered view | (never optional) |
| Filtered-empty hand-off | A filter set matches zero rows | A genuinely empty collection (that is the collection-empty state) |

# Anatomy

A filtered list screen is three cooperating regions, top to bottom, each knowing only the shared
`filter[...]` object.

```
┌─ Filter surface ────────────────────────────────────────────────────────┐
│  [Status ▾]  [Date range ▾]  [Cost center ▾]  [Amount…]      [Saved ▾]   │ ← filter bar (lg+)
├─ Applied-filter summary ────────────────────────────────────────────────┤
│  ( Status: Posted x )  ( 01/07 – 31/07 x )  ( Kuwait x )   Clear all     │ ← chips + clear-all
├─ DataTable ─────────────────────────────────────────────────────────────┤
│  … filtered rows, server-paginated …                                     │
└──────────────────────────────────────────────────────────────────────────┘
```

| Region | Element | Token / rule |
|---|---|---|
| Filter surface | `FilterBar` (lg+) or `FilterDrawer` (`Sheet`, md-) | Both render the same `<FilterField>` set from one `filters` config — never two definitions |
| Applied-filter summary | `FilterChips` — one removable chip per value + "Clear all" | Hidden entirely when nothing is active, so a clean list carries no chrome |
| Table | `DataTable` | Owns pagination/sort/network; receives only the composed `filter[...]` |

A filter chip is `ink-2` fill / `ink-10` text with an `ink-9` x. Accent is *not* used as chip decoration —
it stays reserved for primary actions and AI provenance; an active saved-view selector may carry the single
accent mark, but a filter value chip never does.

# Variants

## Filter bar vs. filter drawer

The choice is a responsive-layout decision resolved from breakpoint, not a per-screen author choice; a screen
declares its filter set once and both surfaces render it.

| | Filter bar (`lg`+) | Filter drawer (`md` and below) |
|---|---|---|
| Trigger | Always visible under the page header | A "Filters" button (with an active-count badge) opens a `Sheet` from the inline-end edge |
| Layout | A wrap-tolerant flex row, `gap-2` | A vertical stack of the same controls inside the `Sheet` |
| Apply model | Each control applies on change (live), debounced for text | Controls stage a local draft; one "Apply filters" commits them in a single navigation |
| Chips | In the summary strip below the bar | In the summary strip above the table, same as bar mode |

```tsx
// components/shared/filter-bar.tsx (shape — full system in FILTERS.md)
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

```tsx
// components/shared/filter-drawer.tsx (shape) — stages a draft, commits once
export function FilterDrawer({ resource, filters }: { resource: string; filters: FilterConfig[] }) {
  const { value, applyDraft, activeCount } = useFilters(resource, filters);
  const [draft, setDraft] = useState(value);   // stage locally; URL unchanged until Apply
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

Canceling the `Sheet` discards the draft and leaves the current URL scope untouched.

## Filter chips + clear-all

The chip strip is the always-visible record of current scope. Each active value renders one removable chip;
"Clear all" follows the last chip; the strip is hidden when nothing is active.

```tsx
// components/shared/filter-chips.tsx (shape) — chipLabel is the one place a value becomes prose
export function FilterChips({ filters, value, onClear, onClearAll, activeCount }: FilterChipsProps) {
  if (activeCount === 0) return null;
  return (
    <div className="flex flex-wrap items-center gap-1.5" aria-label={t('appliedFilters')}>
      {filters.filter((f) => value[f.key] != null).map((f) => (
        <Badge key={f.key} tone="neutral" className="gap-1 ps-2 pe-1">
          <span className="truncate max-w-[16rem]">{chipLabel(f, value[f.key])}</span>
          <button type="button" onClick={() => onClear(f.key)}
                  aria-label={t('removeFilter', { name: chipLabel(f, value[f.key]) })}
                  className="rounded-full p-0.5 text-ink-9 hover:bg-ink-6 hover:text-ink-12
                             focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
            <X className="h-3 w-3" aria-hidden />
          </button>
        </Badge>
      ))}
      <Button variant="link" size="sm" onClick={onClearAll}>{t('clearAll')}</Button>
    </div>
  );
}
```

`chipLabel` turns a value into prose per filter kind and per language — a date-range chip reads
`01/07/2026 – 31/07/2026` (`latn`, `dir="ltr"`), an entity chip reads the account's `name_en`/`name_ar`, a
multi-enum chip collapses to "Status: Draft +2" past two values. It never string-concatenates English word
order; the templates are authored per language.

## The filter kinds

Each kind is a thin wrapper over an input primitive that knows how to serialize itself into the `filter[...]`
grammar.

| Kind | Control | `filter[...]` shape | Example |
|---|---|---|---|
| `enum` (single) | `Select` | `filter[status]=posted` | Entry status |
| `enum` (multi / facet) | `MultiSelect` | `filter[status][in]=draft,posted` | Statuses, entry types |
| `entity` (facet) | `Combobox` (server-searched) | `filter[account_id][in]=…` | Account, customer, vendor |
| `dimension` | `Combobox` per dimension | `filter[cost_center_id]=…` | Cost center, project, department, branch |
| `date_range` | `DateRangePicker` | `filter[entry_date][between]=2026-07-01,2026-07-31` | Posting-date window |
| `fiscal_period` | `PeriodPicker` | resolves to a `filter[entry_date][between]` | July 2026 |
| `amount_range` | two `NumberInput`s | `filter[total_debit][gte]=…&[lte]=…` | Amount band |
| `aging_bucket` | `Select`/`MultiSelect` | `filter[aging_bucket][in]=31-60` | AR/AP aging |
| `boolean` | `Checkbox`/`Switch` | `filter[has_attachment]=true` | Flags |
| `text` (within-filter) | `SearchInput` | narrows the facet's own option list; not a top-level `q` | Search-within a long account facet |

## Saved views

A saved view is a named bundle of the current filters (and optionally sort and density), stored
per-user-per-resource via the API (not `localStorage`), so it follows the user across devices.

- **Applying a view rewrites the URL** to that view's filter set, so a saved view is still just a
  deep-linkable URL; sharing the link shares the scope even with a colleague who does not have the view.
- **A modified view is flagged, never silently overwritten** — editing a filter while a view is active marks
  it "modified," offering "Update view" / "Save as new" / "Revert."
- **Views respect RBAC at apply time** — a view referencing a dimension the user has since lost permission
  for drops that clause with an inline notice, rather than firing a request the server would `403`.

## Filtered-empty hand-off

When a filter set matches zero rows, the table renders the **filtered-empty** state, not the
collection-empty state — the two carry different messages and recovery actions, and conflating them misleads
the user about whether the data exists at all.

- **Filtered-empty**: "No rows match your filters." + a primary "Clear filters" action (calls `clearAll`) and,
  where useful, a suggestion to widen the date range. The chip strip stays visible so the user sees exactly
  which clauses produced the miss.
- **Collection-empty**: "No journal entries yet." + a create action — reserved for a genuinely empty
  collection with no filters active.

Both use the shared `EmptyState` component, with the filtered variant selected automatically by `DataTable`
when `activeCount > 0`.

# Composition

## A filtered list screen end to end

Each region knows only the shared `filter[...]` object — never the others directly. `useFilters` owns the
whole system's state: it reads filters from the URL, writes changes back, and exposes
`value`/`set`/`clear`/`clearAll`/`toApiFilters`. Both the bar and the drawer consume it; neither holds filter
state locally, which is what keeps the URL the one source of truth.

```tsx
// app/(app)/accounting/general-ledger/page.tsx (client shell — shape)
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

`ledgerFilters` is the one `FilterConfig[]` both the bar and the drawer render; `DataTable` receives only the
composed object and the active-count-derived empty variant. This is what keeps a screen with no filters and a
screen with a dozen on the same two components.

## URL / search-param serialization

Filters serialize into the platform's own query grammar so a filtered link is the exact URL the API speaks:

```
/accounting/journal-entries?filter[status][in]=draft,posted&filter[entry_date][between]=2026-07-01,2026-07-31&sort=-entry_date
```

```tsx
// hooks/use-filters.ts (URL read/write core — shape)
export function useFilters(resource: string, config: FilterConfig[]) {
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const value = decodeFilters(params, config);            // URL → typed values, defaults only when absent

  function commit(next: Record<string, FilterValue>) {
    const qs = encodeFilters(next, config, params);        // preserves sort/cursor/branch
    router.replace(`${pathname}?${qs}`, { scroll: false }); // replace, not push
  }
  // set / clear / clearAll delegate to commit; toApiFilters maps value → filter[...] object.
}
```

- **Read on mount, URL wins.** `useFilters` decodes the URL as authoritative, falling back to a filter's
  `defaultValue` only when the URL carries no value for that key.
- **Write with `replace`, not `push`.** A filter change is a refinement of the current view, not a
  navigation, so the back button returns to the previous *screen*, not through every filter permutation.
- **Non-filter params are preserved.** `clearAll` and any single `set` leave `sort`, `cursor`/`page`, and
  `?branch=` untouched. The `branch` scope is serialized as `?branch=<id>` (the switcher's own convention),
  not as `filter[branch_id]`, so a branch *filter kind* reuses that same param rather than inventing a second.

## Search-within a long facet

A facet over a large set (all accounts, all vendors) never ships a client-side option list; it uses the
server-searched `Combobox`, and its search box narrows the facet's *own* options, debounced at 250ms,
distinct from the table's top-level `q`. The selected values become `filter[cost_center_id][in]=…` and,
separately, chips in the summary.

# States & Behavior

| State | Filter surface | Table |
|---|---|---|
| No filters active | Bar shows controls at rest; chip strip and "Clear all" hidden; drawer badge absent | Full, default-sorted collection (the resource's own defaults may still apply) |
| Filters active | Chips render one per value; "Clear all" visible; drawer badge shows count | Filtered result set |
| Applying (in flight) | Controls stay interactive; a filter change sets the table's `aria-busy` and keeps prior rows via `keepPreviousData` so the layout does not flash | Previous rows dim slightly until the new page resolves |
| Empty after filter | Chips + "Clear all" remain visible (the user must be able to undo the scope) | The filtered-empty state with a one-click "Clear filters" |
| Invalid combination | The offending control shows an inline hint (an amount `min` above `max`); no request fires until resolved | Prior rows retained |
| Saved view active | The selector shows the view name; editing any filter marks it "modified" until re-saved or reverted | Result set for the view's filters |

Behavioral rules:

- **Enum/entity/date/dimension apply on change** — one discrete action, one request. **Text-within-filter and
  amount-range apply debounced** (250ms). **The tablet drawer stages, then applies** — its controls edit a
  local draft and one "Apply filters" commits the whole draft in a single navigation.
- **A stale filter value in a link** (a deleted cost center, a since-disabled status) renders as a
  visibly-flagged "unknown/removed value" chip the user can clear, never a blank chip or a silent drop.
- **A cursor-paginated resource** resets the cursor to the start on any filter change (a new filter is a new
  result stream), keeping its "scope this view" nudge since cursor mode offers no total.
- **Filter + free-text search compose** — a screen may carry both a top-level `q` and structured filters;
  both go on the request and both appear in the summary (the `q` as its own removable chip), so clearing
  search and clearing a filter are independent, visible actions.

# Content & Copy Guidance

| Surface | Guidance | Example |
|---|---|---|
| Filter control label | The dimension, noun, sentence case | "Cost center" — not "Filter by cost center" |
| Chip template | Authored per language, not string-concatenated | "Status is Posted" / "Date 01/07 – 31/07" |
| Multi-value chip | Collapse past two, full set on hover | "Status: Draft +2" |
| Clear-all | Plain, low-emphasis | "Clear all" |
| Filtered-empty | States the cause and the fix | "No rows match your filters." + [Clear filters] |
| Collection-empty | States the true state and the create path | "No journal entries yet." + [New entry] |
| Stale-value chip | Names it as removed, not a code | "Cost center (removed)" |
| Saved-view modified | Offers the three moves | "Modified" → [Update view] [Save as new] [Revert] |
| Permission-dropped clause | Explains the drop | "Branch filter removed — you no longer have access." |

All filter labels, operators, chip templates, and empty-state copy are i18n keys present in both `en` and `ar`;
a key in one and missing in the other fails `npm run i18n:check`. Chip templates interpolate the value label
("Status is {value}", "Date between {from} and {to}") and are authored per language so Arabic grammar is
correct rather than English word-order translated. Dates and amounts in chips format through the shared `latn`
primitives; a fiscal-period filter renders the period's own bilingual name from `fiscal_periods`, not a
client-derived month label. Arabic copy is the professional-Gulf register.

# Accessibility

| Element | Keyboard | Screen reader |
|---|---|---|
| Filter bar | Each control is individually tab-reachable; the bar is a labelled region (`aria-label="Filters"`) | Controls carry their own labels; the bar announces as a filter region |
| Filter drawer | The "Filters" trigger opens a Radix `Sheet` that traps focus; `Esc` cancels the staged draft without applying | The trigger's active-count is real text ("Filters (3)"), not a color-only badge |
| Filter chips | Each chip's x is a real focusable `<button>` with an accessible name ("Remove filter: Status is Posted") | The chip text is the accessible name; the x names the specific filter it clears |
| Applied-filter change | — | The result-count change is announced via an `aria-live="polite"` region ("48 entries match"), never a silent table swap |
| Empty-after-filter | The "Clear filters" action is the first focusable element in the empty region | A landmark with a heading, not a silent blank table |
| Locked/omitted dimensions | — | An omitted dimension simply is not announced; a *disabled-by-state* facet carries a reason via tooltip/`aria-describedby` |

All of the above sit on the WCAG 2.1 AA floor; focus rings are keyboard-only (`focus-visible`), and chip/clear
affordances are never conveyed by color alone. Under `prefers-reduced-motion`, the drawer's slide, the chip
enter/exit, and any applied-row flash collapse to instant state changes.

# Theming, Dark Mode & RTL

- **Chips and controls are ink-scale with one accent boundary.** A filter chip is `ink-2` fill / `ink-10`
  text with an `ink-9` x; a filter *value* chip never uses `accent` as decoration — accent stays reserved for
  primary actions and AI provenance. A status-derived chip may carry the same desaturated `StatusPill` tone
  the status uses elsewhere, always paired with its label, never color-only. An active saved-view selector may
  carry the single `accent` mark.
- **Dark mode is a token remap only** — chip fills, borders, and the drawer surface resolve to their dark
  values with no `dark:` raw-color variant.
- **RTL mirrors via logical properties.** The bar's `ms-auto` saved-views alignment, the drawer's inline-end
  slide edge, the chip x position (`pe-*`), and the "Clear all" placement all flip automatically under
  `dir="rtl"`; physical sides are banned in filter source.
- **Filter *values* that are numeric or dated never mirror** — an amount-range chip reads
  `KD 1,000.000 – 5,000.000` and a date-range chip reads `01/07/2026 – 31/07/2026` in `latn` numerals,
  `dir="ltr"`, inside an RTL bar. An entity/dimension chip shows the `name_en`/`name_ar` matching
  `useLocale()`.

# Do / Don't

| Do | Don't |
|---|---|
| Declare one `filters` config; render it in both bar and drawer | Maintain two filter definitions for desktop and tablet |
| Keep the URL the single source of truth for scope | Hold filter state in component `useState` |
| Write filter changes with `router.replace` | `push` every filter tweak into history |
| Preserve `sort`/`cursor`/`branch` on `clearAll` | Wipe non-filter params when clearing filters |
| Stage the drawer's draft and commit once on "Apply" | Fire a request per tap inside the tablet drawer |
| Show the filtered-empty state with chips intact | Show the collection-empty "create your first" message on a filtered miss |
| Render a stale value as a flagged, clearable chip | Drop it silently or show a blank chip |
| Keep chips ink-scale; reserve accent for actions/AI | Decorate a filter value chip with the accent color |
| Serialize a branch filter to the shared `?branch=` param | Invent a second `filter[branch_id]` that can contradict the switcher |
| Use the server-searched `Combobox` for a long facet | Ship a giant client-side option list |

# End of Document
