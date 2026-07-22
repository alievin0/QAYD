# Search — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / SEARCH
---

# Purpose

This document is the atomic component spec for QAYD's inline search primitives — the debounced search
input and its grouped, highlighted results: `GlobalSearchInput`, `ScopedSearchInput`, `SearchResultGroup`,
`SearchResultItem`, and `Highlight`. These are the page-scoped search boxes embedded in a table toolbar, a
picker, or the `/search` results page — as distinct from the transient `⌘K` overlay, which the sibling
[`COMMAND_PALETTE.md`](./COMMAND_PALETTE.md) spec owns. This spec fixes their anatomy, the debounced query
path, inline result rendering and match highlighting, the empty/loading/error states, the RBAC filtering
that guarantees a user is never shown a record they cannot reach, and the token/theming/RTL rules.

It is a **specialization** of, and defers to, the app-level component doc at
[`../../frontend/components/SEARCH_BAR.md`](../../frontend/components/SEARCH_BAR.md), which catalogues both
search surfaces together and owns the two backend call shapes, the ranking model, and the RBAC
belt-and-braces; and to the [`../../frontend/SEARCH.md`](../../frontend/SEARCH.md) screen spec, which owns
the persistent `/search` results *page* — its route, its aggregate endpoint, its Type Tabs and facets, its
recent/suggested sourcing. This document does not re-derive that behavior; it restates the reusable
input/results shape and its design rules and cross-links back.

Every value references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the `ink-1…12` scale, the single
brass `accent` (used in search in exactly one place — the match `<mark>`), and the `radius-*` primitives.

# Anatomy

```
 GlobalSearchInput (/search page, size lg, auto-focused)
 ┌────────────────────────────────────────────────────────────┐
 │ /  Search invoices, customers, accounts…                   │
 └────────────────────────────────────────────────────────────┘

 ScopedSearchInput (table toolbar, max-w-xs)
 ┌──────────────────────────────┐
 │ / Filter…          in Ledger │  ← trailing "in {scope}" affordance
 └──────────────────────────────┘

 SearchResultGroup + SearchResultItem
 ┌────────────────────────────────────────────────────────────┐
 │ INVOICES                                        See all →   │  ← group heading + seeAllHref
 │  ▸ INV-2026-0482 · ⟨Al-Fajr⟩ Trading   KWD 1,240.000  Paid  │  ← icon · Highlight · amount · StatusPill
 │  ▸ INV-2026-0455 · ⟨Al-Fajr⟩ Motors    KWD   890.500  Overdue│
 └────────────────────────────────────────────────────────────┘
```

| Part | Element | Rule |
|---|---|---|
| Leading icon | `Search` glyph, `ink-9` | Absolute, inline-start; never mirrors |
| Input | `Input` primitive | `lg` for `/search`, default for scoped; `aria-label` mirrors the placeholder |
| Scope affordance | Trailing "in {scope}" text (scoped only) | Real text so the search's scope is announced, not inferred |
| Group heading | `SearchResultGroup` heading + count + "See all →" | Selects the item icon and `StatusPill` domain |
| Result row | `SearchResultItem` | icon · highlighted title · optional subtitle · amount · status |
| Match | `Highlight` `<mark>` | `accent-subtle` bg + `accent` text; the one accent use in search |

`GlobalSearchInput` and `ScopedSearchInput` are thin wrappers over the same `Input` primitive; the
difference is scale and scope, not mechanism. The scoped input makes its scope *visible* so a user never
mistakes a list search for a global one.

# Variants

| Variant | Component | Presentation | Owns |
|---|---|---|---|
| Page results search | `GlobalSearchInput` | Large, auto-focused box atop `/search` | The persistent, deep-linkable results page's `?q=` box |
| Scoped in-page search | `ScopedSearchInput` | Small toolbar `SearchInput` | A single table/picker's `q` narrowing — not global search |
| Result group | `SearchResultGroup` | Heading + ≤N rows + "See all →" | Grouping, count, single-type drill-in |
| Result item | `SearchResultItem` | One row: icon · title · amount · status | Canonical-URL navigation, highlighting |

A `ScopedSearchInput` feeds its own table's `q` param and never opens the palette or hits the global search
endpoint; a `GlobalSearchInput` owns `/search`'s aggregate query. They deliberately do not share a query
state — mistaking one for the other is exactly what the visible scope affordance prevents.

# Props / API

Full tables in [`../../frontend/components/SEARCH_BAR.md`](../../frontend/components/SEARCH_BAR.md).

## GlobalSearchInput

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `string` | yes | Controlled query; mirrored to `/search`'s `?q=` URL param. |
| `onValueChange` | `(q: string) => void` | yes | Fires per keystroke; the consumer debounces the network call. |
| `autoFocus` | `boolean` | no (default `true`) | `/search` auto-focuses this on mount. |

```tsx
// components/search/global-search-input.tsx
'use client';
import { Search } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/lib/i18n';

export function GlobalSearchInput({ value, onValueChange, autoFocus = true }: {
  value: string; onValueChange: (q: string) => void; autoFocus?: boolean;
}) {
  const { t } = useTranslations('search');
  return (
    <div className="relative">
      <Search className="absolute inset-inline-start-3 top-1/2 -translate-y-1/2 h-5 w-5 text-ink-9" aria-hidden />
      <Input size="lg" className="ps-11 text-[1.25rem]" value={value} autoFocus={autoFocus}
             onChange={(e) => onValueChange(e.target.value)}
             placeholder={t('resultsPlaceholder')} aria-label={t('resultsPlaceholder')} />
    </div>
  );
}
```

## ScopedSearchInput

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `string` | yes | The table/picker's `q`. |
| `onValueChange` | `(q: string) => void` | yes | Debounced by the consuming table/picker (250ms). |
| `placeholder` | `string` | no | i18n key; defaults to the resource-scoped placeholder. |
| `scopeLabel` | `string` | no | When set, renders a leading "in {scope}" affordance. |

```tsx
// components/search/scoped-search-input.tsx
export function ScopedSearchInput({ value, onValueChange, scopeLabel, placeholder }: {
  value: string; onValueChange: (q: string) => void; scopeLabel?: string; placeholder?: string;
}) {
  const { t } = useTranslations('search');
  const ph = placeholder ?? t('scopedPlaceholder');
  return (
    <div className="relative max-w-xs">
      <Search className="absolute inset-inline-start-2.5 top-2.5 h-4 w-4 text-ink-9" aria-hidden />
      <Input className="ps-8 pe-16" value={value} onChange={(e) => onValueChange(e.target.value)}
             placeholder={ph} aria-label={ph} />
      {scopeLabel && (
        <span className="pointer-events-none absolute inset-inline-end-2 top-1/2 -translate-y-1/2 text-xs text-ink-9">
          {t('inScope', { scope: scopeLabel })}
        </span>
      )}
    </div>
  );
}
```

## SearchResultGroup / SearchResultItem

| Prop | Type | Required | Description |
|---|---|---|---|
| `type` | entity type | yes | Selects the heading, the item icon, and the `StatusPill` domain. |
| `items` | `SearchResultItem[]` | yes | ≤5 in the palette; cursor-paginated on the `/search` single-type page. |
| `query` | `string` | yes | Passed to `Highlight` for match emphasis. |
| `onSelect` | `(href: string) => void` | yes | Navigates to the item's canonical URL. |
| `seeAllHref` | `string` | no | "See all →" to the single-type page (results page only). |

```tsx
// components/search/search-result-item.tsx
import { AmountCell } from '@/components/accounting/amount-cell';
import { StatusPill } from '@/components/shared/status-pill';
import { Highlight } from '@/components/search/highlight';
import { useLocale } from '@/lib/i18n';

export function SearchResultItem({ item, query, onSelect }: {
  item: SearchResult; query: string; onSelect: (href: string) => void;
}) {
  const locale = useLocale();
  const title = locale.tag.startsWith('ar') ? item.title_ar : item.title_en;   // bilingual data, not translation
  return (
    <button type="button" onClick={() => onSelect(item.href)}
            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start hover:bg-ink-4">
      <item.icon className="me-1 h-4 w-4 shrink-0 text-ink-9" aria-hidden />
      <div className="min-w-0 flex-1">
        <Highlight className="truncate text-sm text-ink-11" text={title} query={query} />
        {item.subtitle && <p className="truncate text-xs text-ink-9">{item.subtitle}</p>}
      </div>
      {item.amount && <AmountCell amount={item.amount.value} currencyCode={item.amount.currencyCode} />}
      {item.status && <StatusPill domain={item.type} status={item.status} size="sm" />}
    </button>
  );
}
```

`Highlight` wraps the matched substring(s) in a `<mark>` styled `bg-accent-subtle text-accent` — the one
place accent marks a match, deliberately quiet, never a bright highlighter — and is bidi-safe (never splits
an Arabic ligature). A record result links to the *canonical filtered URL the owning screen would produce*
(a customer → `/sales/invoices?filter[customer_id]=…`), not a bespoke detail view.

# States

| State | Global / results | Scoped |
|---|---|---|
| Idle (empty query) | Recent searches + suggested searches; no network | Placeholder only |
| Typing, below 2 chars | Holds prior results; no aggregate call below the full-text minimum | Filters nothing until 2 chars |
| Searching | `aria-busy`; skeleton result cards on first load; prior results kept via cache to avoid flash | Input keeps focus; table shows its own busy row |
| Results | Grouped result cards with per-group counts and "See all" | The table narrows in place |
| Empty (no matches) | `SearchEmptyState` — per-group and whole-page, never a silent blank list | The table's own "no rows match" empty state |
| RBAC-empty | A role with zero readable record types still gets a working page (no single gating permission) | N/A — scope is the table's own permission |
| One-group error | Group-level `ErrorState`, not a whole-page failure, when only one fan-out call failed | Inline retry on the table |
| AI unavailable (`503`) | The AI answer card shows a distinct "temporarily unavailable" state honoring `Retry-After`, never an infinite spinner | N/A |

Below the 2-character full-text minimum no network fires; the query itself is passed to the backend
untransformed (full-text over `tsvector`/`pg_trgm`) — the client neither transliterates nor case-folds.

# Tokens Used

| Role | Token | Where |
|---|---|---|
| Input surface / border | `ink-1`, `ink-7` | `Input` background and border |
| Leading icon / scope text / subtitle | `ink-9` | `Search` glyph, "in {scope}", secondary line |
| Result title | `ink-11` | `SearchResultItem` primary text |
| Row hover | `ink-4` | Result-row highlight |
| Match highlight | `accent-subtle` bg + `accent` text | `Highlight` `<mark>` — the one accent use in search |
| Amount / status | plain `ink` tabular numerals; `StatusPill` tone | Trailing cells — never accent, never polarity-washed magnitude |
| Focus ring | `accent` (`ring`) | Input focus at non-zero offset |
| Radius | `radius-md` (input, rows), `radius-lg` (result cards) | — |
| Motion | `motion.fast` (result appear), `motion.base` (skeleton) | Reduced-motion-gated |
| Z-index | `z-dropdown` | A picker's floating results list |

`accent` appears in search in exactly two places: the match `<mark>` and the input focus ring — both
legitimate under [`../DESIGN_TOKENS.md → Color — Accent`](../DESIGN_TOKENS.md). A result's status uses its
`StatusPill` tone; amounts render plain, never washed by polarity, per the debit/credit rule.

# Accessibility

| Element | Keyboard | Screen reader |
|---|---|---|
| `GlobalSearchInput` | Auto-focused on `/search` mount; standard text-input keys | Labelled input; the results region below is a labelled landmark, announced on update |
| `ScopedSearchInput` | Reachable via `Tab` and `Cmd/Ctrl+F` when its table is focused | The "in {scope}" affordance is real text, so the scope is announced, not inferred |
| Result group | Rows are reachable in order; "See all" is a real link | Each group heading is read before its rows; counts are real text |
| `SearchResultItem` | A real `<button>`/`<a>`, focusable across its full padded hit area | Icon is `aria-hidden`; the title is the accessible name |
| `Highlight` | — | The `<mark>` carries no separate announcement; the full title reads as one string, never fragmenting the accessible name; bidi-safe |
| Searching | — | `aria-busy="true"` while fetching; a visually-hidden `aria-live="polite"` region announces count changes ("6 results") |
| Empty / RBAC-empty | — | A labelled region with a heading, never a silent blank list |

Meets the **WCAG 2.1 AA** floor in both themes and both directions. The `Cmd/Ctrl+F` binding focuses the
in-table scoped filter (which can see virtualized rows) rather than the browser find. Under
`prefers-reduced-motion` the result-appear and skeleton animations collapse to instant state changes.

# Theming, Dark Mode & RTL

- **Dark mode is a token remap only.** The input surface, the row hover, and the highlight `<mark>` resolve
  to their dark values with no `dark:` raw-color variant; the highlight re-tunes per theme rather than
  brightening linearly, so a matched substring reads correctly against the dark canvas.
- **RTL mirrors via logical properties.** The leading `Search` icon and the trailing scope affordance use
  `ps-*`/`pe-*` and `inset-inline-*`; result-item icons use `me-*`; a row's trailing amount/status sits at
  the inline-end. The `Search` glyph itself never mirrors.
- **Amounts, codes, and dates inside results never mirror** — a result's `AmountCell` stays `dir="ltr"`
  `latn`, a currency code stays Latin, even inside an Arabic result row.
- **Result titles are bilingual data, not translations.** `SearchResultItem` renders `title_en`/`title_ar`
  by `useLocale()`; the aggregate endpoint returns both regardless of `Accept-Language`, so an Arabic-UI
  user can type an English account name and still match. Arabic chrome copy (placeholders, "Searching…",
  "No results for '{q}'", "See all") is authored to the professional-Gulf register, never machine-translated.

# Do / Don't

| Do | Don't |
|---|---|
| Debounce the network call and fire nothing below 2 chars | Query per keystroke or on an empty input |
| Make the scoped input's scope visible with real "in {scope}" text | Let a list search look identical to a global one |
| Link a result to its canonical filtered URL | Invent a bespoke detail route for a search result |
| Keep `accent` to the `<mark>` and the focus ring | Use accent as row decoration or a status color |
| Render amounts in plain `ink` tabular numerals | Wash a result's amount by polarity or reuse accent for magnitude |
| Keep `Highlight` bidi-safe and one accessible string | Split a title into fragments or break an Arabic ligature |
| Degrade one failed fan-out to a group `ErrorState` | Whole-page-fail when only one group's call failed |
| Render `title_en`/`title_ar` from bilingual data | Machine-translate a result title in the client |

# Usage & Composition

**On the `/search` results page** — `GlobalSearchInput` over the aggregate endpoint, groups below; the
screen shell is owned by [`../../frontend/SEARCH.md`](../../frontend/SEARCH.md), this spec supplies the
primitives:

```tsx
// components/search/search-results.tsx (design-system shape)
'use client';
import { useQuery } from '@tanstack/react-query';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { GlobalSearchInput } from '@/components/search/global-search-input';
import { SearchResultGroup } from '@/components/search/search-result-group';
import { SearchAiAnswerCard } from '@/components/search/search-ai-answer-card';
import { SearchEmptyState } from '@/components/search/search-empty-state';
import { apiClient } from '@/lib/api-client';

export function SearchResults({ q, setQ, ask }: { q: string; setQ: (v: string) => void; ask: boolean }) {
  const debounced = useDebouncedValue(q, 200);
  const { data, isPending } = useQuery({
    queryKey: ['search', debounced],
    queryFn: () => apiClient.get('/api/v1/search', { params: { q: debounced, per_type: 5 } }),
    enabled: debounced.trim().length >= 2,
  });
  return (
    <div className="space-y-4">
      <GlobalSearchInput value={q} onValueChange={setQ} />
      {ask && data?.aiAnswer && <SearchAiAnswerCard answer={data.aiAnswer} />}
      {data?.groups?.map((g) => (
        <SearchResultGroup key={g.type} type={g.type} items={g.items} query={debounced}
                           seeAllHref={`/search/${g.type}?q=${encodeURIComponent(debounced)}`}
                           onSelect={(href) => router.push(href)} />
      ))}
      {!isPending && data?.groups?.length === 0 && <SearchEmptyState q={debounced} />}
    </div>
  );
}
```

**As a scoped table filter** — the scope stays visible so the search is unmistakably list-local:

```tsx
<ScopedSearchInput value={q} onValueChange={setQ} scopeLabel={t('nav.generalLedger')} />
```

**RBAC-filtered end to end.** Results are filtered at two layers, belt-and-braces: server-side, each
fan-out call independently permission-checks (a group the user cannot read simply does not return, and a
crafted request still `403`s); client-side, nav and action sources are pre-filtered. The net contract — **a
user can never navigate to, act on, or open via search anything they could not already reach through the
UI**. **AI answers stay proposals**: the `/search` `SearchAiAnswerCard` composes the same `ConfidenceBadge`
+ `ReasoningPanel` primitives from [`AI_WIDGET.md`](./AI_WIDGET.md), shows the answer with confidence,
reasoning, and citations, and commits nothing — routing any proposed action through the normal human-gate.
The transient `⌘K` overlay that shares this machinery is specified in
[`COMMAND_PALETTE.md`](./COMMAND_PALETTE.md).

For the full ranking model, debounce/query-shape tests, and the two-call-shape architecture, see
[`../../frontend/components/SEARCH_BAR.md`](../../frontend/components/SEARCH_BAR.md) and
[`../../frontend/SEARCH.md`](../../frontend/SEARCH.md).

# End of Document
