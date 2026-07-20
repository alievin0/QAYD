# Search Bar & Command Palette — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / SEARCH_BAR
---

# Purpose

This document is the component-library contract for QAYD's two search surfaces: the **global command
palette** (`⌘K` / `Ctrl+K`), a transient overlay that jumps to any screen, runs a permitted action,
opens any entity, or asks the AI a question from anywhere in the app; and the **inline search
inputs** — the page-scoped search boxes embedded in a table toolbar, a picker, or the `/search`
results page. It specifies the reusable primitives both are built from — `CommandPalette`,
`GlobalSearchInput`, `ScopedSearchInput`, `SearchResultGroup`, `SearchResultItem`, `RecentSearches`,
`SuggestedSearches`, `SearchAiAnswerCard` — their props, states, keyboard and ARIA contracts, the
debounced query path to the platform's search endpoints, result grouping and highlighting, and the
RBAC filtering that guarantees a user is never shown a screen, action, or record they cannot reach.

Two sibling documents own the *behavioral* specifications this one implements against, and this
document defers to them rather than duplicating them: [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)
`# Command Palette` owns the palette's search-and-action *grammar* (what it searches, how Navigate /
Records / AI & Actions groups are ranked, how it exposes AI actions without committing anything), and
the [`../screens/SEARCH_SCREEN.md`](../screens/SEARCH_SCREEN.md) / `SEARCH.md` screen spec owns the
persistent `/search` results *page* (its route, its aggregate endpoint, its Type Tabs and facets).
This document is the **component catalogue** for the shared building blocks both of those compose —
the same relationship [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) has to every screen it
serves.

Two platform rules bind every search surface. First, **results are RBAC-filtered end to end** — a
Navigate result for a screen the role cannot open, an action the role lacks the permission for, or a
record type the role cannot read is never rendered, because each result group's own fan-out call is
independently permission-checked server-side and the palette additionally filters nav/action sources
against `usePermission()` before display ([`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)
`# Permission-Aware Nav`). Second, **AI answers are proposals, never commits** — the palette's "Ask AI"
and the `/search` AI answer card render a response with its confidence and reasoning and offer a
follow-up or a "send for approval," but a keystroke in a search box never posts, approves, or changes
financial data ([`../README.md`](../README.md) `# Overview`, constraint 2).

This document assumes [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md), the `SEARCH.md` screen
spec, [`INPUTS.md`](./INPUTS.md) (the `SearchInput` primitive), and the platform's
`API_FILTERING_SORTING.md` (the full-text `q` grammar) and `API_PAGINATION.md` (cursor pagination for
"see all") are open alongside it.

# Anatomy & Variants

QAYD's search surfaces are built over shadcn/ui's `Command` primitive (the `cmdk`-backed component in
`components/ui/command.tsx`, itself over Radix), which supplies the combobox/listbox roles, roving
focus, and type-ahead for free. There are three composed variants:

| Variant | Component | Presentation | Owns |
|---|---|---|---|
| Global command palette | `CommandPalette` | A `surface-glass` `CommandDialog` portalled to the document root at `z-command` | Navigate + Records + AI & Actions, `⌘K`, recent/suggested, keyboard jump |
| Page results search | `GlobalSearchInput` | A large, auto-focused input at the top of `/search` | The persistent, deep-linkable results page's own query box (a larger sibling of the palette's `CommandInput`) |
| Scoped in-page search | `ScopedSearchInput` | A small toolbar `SearchInput` ([`INPUTS.md`](./INPUTS.md)) | A single table/picker's `q` narrowing — not global search |

The palette and `/search` deliberately use **two different call shapes over the same backend search
machinery** (`tsvector` / `pg_trgm` / `pgvector`): the palette fans out to per-module
`GET /api/v1/{module}/search` endpoints capped at ~5 rows per type for a fast preview; the results
page uses the single aggregate `GET /api/v1/search` so one request populates every group, with
`GET /api/v1/search/{type}` behind each "See all" for the cursor-paginated single-type view
([`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md) and the `SEARCH.md` endpoint table). A
`ScopedSearchInput` is neither — it feeds its own table's `q` param, not global search.

## The command palette

```tsx
// components/shared/command-palette.tsx (shape — behavioral grammar lives in NAVIGATION_SYSTEM.md)
'use client';
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import {
  CommandDialog, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem, CommandSeparator,
} from '@/components/ui/command';
import { useShellStore } from '@/stores/shell-store';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useNavForPermissions } from '@/hooks/use-nav-for-permissions';
import { apiClient } from '@/lib/api-client';
import { useTranslations } from 'next-intl';
import { SearchResultItem } from '@/components/search/search-result-item';
import { RecentRoutes } from '@/components/search/recent-routes';
import { Sparkles } from 'lucide-react';

export function CommandPalette() {
  const t = useTranslations('search');
  const router = useRouter();
  const open = useShellStore((s) => s.commandPaletteOpen);
  const setOpen = useShellStore((s) => s.setCommandPaletteOpen);
  const [query, setQuery] = useShellStore((s) => [s.paletteQuery, s.setPaletteQuery]);
  const debounced = useDebouncedValue(query, 200);

  // ⌘K / Ctrl+K toggles from anywhere.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setOpen(!open); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, setOpen]);

  // Navigate source: the same permission-filtered nav tree the Sidebar renders — no network.
  const navMatches = useNavForPermissions(debounced);

  // Records source: fan-out to per-module search endpoints, ≤5 each, only when the user has typed enough.
  const { data: records, isFetching } = useQuery({
    queryKey: ['palette', 'records', debounced],
    queryFn: () => apiClient.get('/api/v1/search', {
      params: { q: debounced, per_type: 5 },   // aggregate; each group independently permission-checked server-side
    }),
    enabled: debounced.trim().length >= 2,      // full-text minimum, per API_FILTERING_SORTING.md
    staleTime: 30_000,
  });

  return (
    <CommandDialog open={open} onOpenChange={setOpen} label={t('paletteLabel')}>
      <CommandInput value={query} onValueChange={setQuery} placeholder={t('palettePlaceholder')} />
      <CommandList aria-busy={isFetching}>
        {debounced.trim().length === 0 && <RecentRoutes onSelect={(href) => { router.push(href); setOpen(false); }} />}

        {navMatches.length > 0 && (
          <CommandGroup heading={t('groupNavigate')}>
            {navMatches.map((n) => (
              <CommandItem key={n.href} value={`nav:${n.label}`} onSelect={() => { router.push(n.href); setOpen(false); }}>
                <n.icon className="me-2 h-4 w-4 text-ink-500" aria-hidden />
                <Highlight text={n.label} query={debounced} />
              </CommandItem>
            ))}
          </CommandGroup>
        )}

        {records?.groups?.length ? (
          <>
            <CommandSeparator />
            {records.groups.map((g) => (
              <CommandGroup key={g.type} heading={t(`type.${g.type}`)}>
                {g.items.map((item) => (
                  <SearchResultItem key={item.id} item={item} query={debounced}
                    onSelect={(href) => { router.push(href); setOpen(false); }} />
                ))}
              </CommandGroup>
            ))}
          </>
        ) : null}

        <CommandSeparator />
        <CommandGroup heading={t('groupAiActions')}>
          {debounced.trim().length >= 2 && (
            <CommandItem value="ai:ask" onSelect={() => router.push(`/search?q=${encodeURIComponent(debounced)}&ask=1`)}>
              <Sparkles className="me-2 h-4 w-4 text-accent-600" aria-hidden />
              {t('askAiAbout', { q: debounced })}
            </CommandItem>
          )}
        </CommandGroup>

        <CommandEmpty>{t('noResults')}</CommandEmpty>
      </CommandList>
    </CommandDialog>
  );
}
```

The Navigate source is synchronous and permission-filtered (it reuses `filterNavByPermissions`, the
one implementation the Sidebar also uses, per [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)), so
"you typed the name of a page" is always the fastest, most-certain answer and sits above the
network-dependent Records group; the AI action sits last, never first, so the palette never nudges a
user toward an AI answer before a deterministic one exists.

## The result item and highlighting

```tsx
// components/search/search-result-item.tsx (shape)
import { CommandItem } from '@/components/ui/command';
import { AmountCell } from '@/components/accounting/amount-cell';
import { StatusPill } from '@/components/shared/status-pill';
import { useLocale } from 'next-intl';

export function SearchResultItem({ item, query, onSelect }: SearchResultItemProps) {
  const locale = useLocale();
  const title = locale === 'ar' ? item.title_ar : item.title_en;
  return (
    <CommandItem value={`${item.type}:${item.id}`} onSelect={() => onSelect(item.href)}>
      <item.icon className="me-2 h-4 w-4 text-ink-500 shrink-0" aria-hidden />
      <div className="min-w-0 flex-1">
        <Highlight className="truncate" text={title} query={query} />
        {item.subtitle && <p className="truncate text-caption text-ink-500">{item.subtitle}</p>}
      </div>
      {item.amount && <AmountCell amount={item.amount.value} currencyCode={item.amount.currencyCode} />}
      {item.status && <StatusPill domain={item.type} status={item.status} size="sm" />}
    </CommandItem>
  );
}
```

`Highlight` wraps the matched substring(s) in a `<mark>` styled `bg-accent-100 text-accent-700` — the
one place `accent` is used as a match indicator, deliberately quiet, never a bright highlighter — and
is bidi-safe (it never splits inside an Arabic ligature). A record result links to the *canonical URL
the owning screen's own filter UI would produce* — a customer result goes to
`/sales/invoices?filter[customer_id]=…`, not a bespoke detail view — matching
[`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)'s deep-link rule.

## The inline inputs

Both inline inputs are thin `SearchInput`s ([`INPUTS.md`](./INPUTS.md)); the difference is scale and
scope, not mechanism. `GlobalSearchInput` is the large, auto-focused box that owns `/search`'s `?q=`;
`ScopedSearchInput` narrows one table or picker and makes its scope visible so a user never mistakes a
list search for a global one.

```tsx
// components/search/global-search-input.tsx
'use client';
import { Search } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { useTranslations } from 'next-intl';

export function GlobalSearchInput({ value, onValueChange, autoFocus = true }: GlobalSearchInputProps) {
  const t = useTranslations('search');
  return (
    <div className="relative">
      <Search className="absolute inset-inline-start-3 top-1/2 -translate-y-1/2 h-5 w-5 text-ink-500" aria-hidden />
      <Input size="lg" className="ps-11 text-title" value={value} autoFocus={autoFocus}
             onChange={(e) => onValueChange(e.target.value)}
             placeholder={t('resultsPlaceholder')} aria-label={t('resultsPlaceholder')} />
    </div>
  );
}
```

```tsx
// components/search/scoped-search-input.tsx (shape)
export function ScopedSearchInput({ value, onValueChange, scopeLabel, placeholder }: ScopedSearchInputProps) {
  const t = useTranslations('search');
  return (
    <div className="relative max-w-xs">
      <Search className="absolute inset-inline-start-2.5 top-2.5 h-4 w-4 text-ink-500" aria-hidden />
      <Input className="ps-8 pe-16" value={value} onChange={(e) => onValueChange(e.target.value)}
             placeholder={placeholder ?? t('scopedPlaceholder')} aria-label={placeholder ?? t('scopedPlaceholder')} />
      {scopeLabel && (
        <span className="pointer-events-none absolute inset-inline-end-2 top-1/2 -translate-y-1/2 text-caption text-ink-500">
          {t('inScope', { scope: scopeLabel })}
        </span>
      )}
    </div>
  );
}
```

## The AI answer card

On `/search`, the "Ask AI" path renders a `SearchAiAnswerCard` — the search-surface counterpart of
`AIProposalPanel` ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)). It shows the answer, its
confidence and reasoning, inline citations to the source records, and a follow-up composer, and it
commits nothing on its own.

```tsx
// components/search/search-ai-answer-card.tsx (shape)
import { Card } from '@/components/ui/card';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { AgentAvatar } from '@/components/ai/agent-avatar';

export function SearchAiAnswerCard({ answer }: { answer: AiSearchAnswer }) {
  return (
    <Card padding="md" className="space-y-3 bg-accent-100/40">   {/* accent-subtle = "this came from the AI layer" */}
      <div className="flex items-start gap-3">
        <AgentAvatar agentCode={answer.agentCode} />
        <div className="min-w-0 flex-1">
          <p className="text-body text-ink-950">{answer.text}</p>
          <ConfidenceBadge confidence={normalizeConfidence(answer.confidenceScore, 'percentage')}
                           reasoning={answer.reasoning} size="sm" />
        </div>
      </div>
      {answer.citations.length > 0 && (
        <ul className="flex flex-wrap gap-1.5 text-caption">
          {answer.citations.map((c) => (
            <li key={c.href}><a href={c.href} className="text-ink-500 hover:text-accent-600 underline-offset-2 hover:underline">{c.label}</a></li>
          ))}
        </ul>
      )}
      {/* Follow-up composer + optional "Send for approval" (routes through the normal human-gate) */}
    </Card>
  );
}
```

# Props / API

## `CommandPalette`

The palette is a singleton mounted once in the authenticated app shell; it takes no props (its open
state and query live in the shell Zustand store, per
[`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)). Its behavior is configured by the sources it
composes:

| Source | Mechanism | Permission |
|---|---|---|
| Navigate | `useNavForPermissions(q)` — synchronous, over the permission-filtered nav tree | Each nav item's own key; omitted if absent |
| Records | Debounced `GET /api/v1/search?q=&per_type=5` | Each group server-checked; groups the user cannot read never return |
| AI & Actions | Static action list + "Ask AI about '{q}'" | Actions gated by their permission; "Ask AI" gated by `ai.ask` |
| Recent | Client-only last-5 visited routes (per-user, cleared on sign-out) | Only routes the user could visit (they were there) |

## `GlobalSearchInput`

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `string` | yes | Controlled query; mirrored to `/search`'s `?q=` URL param. |
| `onValueChange` | `(q: string) => void` | yes | Fires per keystroke; the consumer debounces the network call. |
| `autoFocus` | `boolean` | no, default `true` | The `/search` page auto-focuses this on mount. |
| `size` | `'lg'` | fixed | The results-page input is always the large scale. |

## `ScopedSearchInput`

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `string` | yes | The table/picker's `q`. |
| `onValueChange` | `(q: string) => void` | yes | Debounced by the consuming table/picker (250ms). |
| `placeholder` | `string` | no | i18n key; defaults to the resource-scoped placeholder. |
| `scopeLabel` | `string` | no | When set, renders a leading "in {scope}" affordance so the user knows this box searches *this list*, not everything. |

## `SearchResultGroup` / `SearchResultItem`

| Prop | Type | Required | Description |
|---|---|---|---|
| `type` | entity type | yes | Selects the group heading, the item icon, and the `StatusPill` domain. |
| `items` | `SearchResultItem[]` | yes | ≤5 in the palette; cursor-paginated on the `/search` single-type page. |
| `query` | `string` | yes | Passed to `Highlight` for match emphasis. |
| `onSelect` | `(href: string) => void` | yes | Navigates to the item's canonical URL. |
| `seeAllHref` | `string` | no | The "See all →" link to `GET /api/v1/search/{type}`'s page (results page only). |

# States

| State | Palette | Inline / results |
|---|---|---|
| Idle (empty query) | Recent routes (last 5) + suggested starter actions; no network fired | `/search` shows Recent searches + Suggested searches; a scoped box shows its placeholder |
| Typing, below minimum | Navigate matches only (synchronous); no Records call until ≥2 chars | Results page holds prior results; no aggregate call below the 2-char full-text minimum |
| Searching | `CommandList` sets `aria-busy`; a subtle inline "Searching…" row under the Records heading; recent results kept via cache to avoid flash | Same, with skeleton result cards on the results page's first load |
| Results | Grouped Navigate → Records → AI & Actions, each with matches; `Highlight` on matched text | Grouped result cards with per-group counts and "See all" |
| Empty (no matches) | `CommandEmpty` — "No results for '{q}'" plus the still-offered "Ask AI about '{q}'" and any Navigate matches | The results page's zero-result state, per-group and whole-page (`SearchEmptyState`) |
| RBAC-empty | A role with zero readable record types still gets a working palette (Navigate + Ask AI), never an access wall | Same for `/search` — no single gating permission, per the screen spec |
| Error | A per-source failure degrades that group to an inline "Couldn't load {type} results" row; Navigate and Ask AI stay usable | The results page surfaces a group-level `ErrorState`, not a whole-page failure, when only one fan-out call failed |
| AI unavailable (`503`) | The "Ask AI" item renders a distinct "AI is temporarily unavailable" disabled state, not an infinite spinner | The AI answer card shows the same distinct state, per `REST_STANDARDS.md`'s fail-fast contract |

# Validation & Behavior

## Debounced query path

- **Palette Records and the `/search` page debounce at 200ms**, below the minimum they fire no
  network call, and the query is a TanStack Query with a short `staleTime` so re-opening the palette on
  the same query is instant. The Navigate source is synchronous (no network) so a page jump never waits
  on a debounce.
- **Minimum query length is 2 characters**, matching the platform's full-text minimum
  (`API_FILTERING_SORTING.md`); below it, only Navigate (a fuzzy label match) and Recent are shown.
- **Ranking is not re-derived client-side.** Within Records, each source's own backend ranking
  (trigram similarity, full-text rank) is trusted as-is; the palette orders only the *groups* (Navigate
  → Records → AI & Actions), never the rows within a group
  ([`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)). Navigate's own ranking (exact → prefix →
  fuzzy → module order) is the one client-side ranking, because it is over a small, in-memory,
  deterministic set.
- **The scoped input feeds a `q` param, not global search** — a table's `ScopedSearchInput` sets that
  table's `filter`-adjacent `q` and never opens the palette or hits `GET /api/v1/search`.

## RBAC-filtered results — never show what the user can't reach

This is the system's load-bearing rule and it is enforced at two layers, belt-and-braces:

1. **Server-side, per group.** Every fan-out call (`GET /api/v1/search`, `GET /api/v1/search/{type}`,
   the per-module endpoints) independently permission-checks; a group the user cannot read simply does
   not appear in the response. A crafted request still gets a `403` from Laravel regardless of the UI —
   the client filter is a courtesy, not the security boundary
   ([`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md) `# Permission-Aware Nav`, the closing rule).
2. **Client-side, for nav and actions.** The Navigate source is pre-filtered through
   `filterNavByPermissions` (the same function the Sidebar uses), and every AI/action item carries a
   `permission` key checked via `usePermission()` before render — an action a role lacks is *omitted*,
   not shown disabled, because an action's mere existence in the palette is itself information a role
   should not have ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases → Row action
   visibility`).

The net contract: **a user can never navigate to, act on, or open via search anything they could not
already reach through the UI** — search is a faster path to the permitted surface, never a wider one.

## Keyboard & shortcuts

- **`⌘K` / `Ctrl+K`** toggles the palette from anywhere (registered once at the shell); `Esc` closes
  it; the trigger pill in the Topbar shows the shortcut hint
  ([`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)).
- **Within the palette**, `↓`/`↑` move through the flattened result list across groups (cmdk's single
  roving-focus model — one input, no per-group tabs), `Enter` activates the focused item, and
  `Home`/`End` jump to first/last. Typing filters all groups simultaneously.
- **A record result opens on `Enter`**; the AI item opens the `/search` page pre-seeded with the query
  and `ask=1` rather than answering inline in the palette, so the AI answer renders on a surface that
  can show reasoning, citations, and a follow-up composer.
- **The scoped input** takes `Cmd/Ctrl+F` when a dense table is focused, focusing the in-table filter
  rather than the browser find (which cannot see virtualized rows), per
  [`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Data Density → Virtualization & interaction`.

## AI answers stay proposals

The "Ask AI" path renders a `SearchAiAnswerCard` (on `/search`) composing the AI answer with a
`ConfidenceBadge` + reasoning and inline citations to the source records, plus a follow-up composer.
It **never** commits: it may *propose* an action (draft an entry, suggest a match), routed through the
normal `ApprovalCard` / `AIProposalPanel` human-gate ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)),
but a search keystroke posts nothing. Below the platform's confidence threshold the answer is still
shown but its "do it" affordance is disabled, exactly as `AIProposalPanel` gates a low-confidence
output.

## Recent & suggested

- **Recent** is the last 5 visited routes in the palette (client-only, per-user, cleared on sign-out)
  and the last 10 submitted queries on `/search` — capped, per-user, and only ever containing routes
  the user could reach.
- **Suggested** is role-driven starter queries/shortcuts (pending approvals, "reconcile a bank
  account"), sourced from the same Urgent-Actions signal the Dashboard's Quick Actions row reads, so a
  role that cannot reconcile is never suggested "reconcile a bank account."

# Composition Patterns

## Pattern 1 — mounting the palette once in the shell

The palette is a singleton, mounted in the authenticated `(app)` layout so `⌘K` works on every route
without each screen wiring it. Its open state and query live in the shell Zustand store (chrome-only,
never persisted), matching [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)'s store contract.

```tsx
// app/(app)/layout.tsx (excerpt)
import { CommandPalette } from '@/components/shared/command-palette';
import { CommandPaletteTrigger } from '@/components/layout/command-palette-trigger';

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <AppShell topbar={<><CommandPaletteTrigger className="me-auto" />{/* … */}</>}>
      {children}
      <CommandPalette />   {/* portalled to the document root; opened by ⌘K or the Topbar trigger */}
    </AppShell>
  );
}
```

The Topbar's `CommandPaletteTrigger` is a pill button (`Search or jump to…` + a `⌘K` hint) that flips
the same store flag the keyboard shortcut does — one open path, two entry points
([`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md) `# Topbar`).

## Pattern 2 — the `/search` results page

The persistent page composes `GlobalSearchInput` + the results groups over the aggregate endpoint; its
full spec is the `SEARCH.md` / [`../screens/SEARCH_SCREEN.md`](../screens/SEARCH_SCREEN.md) screen doc,
and this document only supplies the shared primitives it draws from.

```tsx
// components/search/search-results.tsx (shape — screen owns the page shell)
'use client';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { GlobalSearchInput } from '@/components/search/global-search-input';
import { SearchResultGroup } from '@/components/search/search-result-group';
import { SearchAiAnswerCard } from '@/components/search/search-ai-answer-card';
import { SearchEmptyState } from '@/components/search/search-empty-state';

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

Every part of a search is a shareable URL (`/search?q=…&type=…&ask=1`), so a `/search` view is
deep-linkable and refresh-surviving, exactly like a filtered list
([`FILTERS.md`](./FILTERS.md), [`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md)).

# Accessibility

| Element | Keyboard | Screen reader | Notes |
|---|---|---|---|
| `CommandPalette` | `⌘K`/`Ctrl+K` opens, `Esc` closes; `↓`/`↑` roving focus; `Enter` activates | The dialog is a labelled `role="dialog"`; `CommandInput` is a `combobox` with `aria-expanded`/`aria-controls`; the list is a `listbox` with `option` rows (Radix/cmdk-wired) | Focus is trapped in the dialog and returns to the trigger on close. |
| Result groups | Roving focus crosses group boundaries seamlessly | Each `CommandGroup` heading is a group label read before its options; result counts are real text | Group order (Navigate → Records → AI) is meaningful and stable. |
| Search-in-progress | — | `CommandList` sets `aria-busy="true"` while fetching; a visually-hidden `aria-live="polite"` region announces result-count changes ("6 results") | A newly-arriving result set is announced, never silently swapped. |
| `Highlight` | — | The `<mark>` carries no separate announcement; the full title is read as one string, so highlighting is purely visual and never fragments the accessible name | Bidi-safe: never splits an Arabic ligature. |
| `GlobalSearchInput` | Auto-focused on `/search` mount; standard text-input keys | Labelled input; the results region below is a labelled landmark, announced on update | — |
| `ScopedSearchInput` | Reachable via `Tab` and `Cmd/Ctrl+F` when its table is focused | The "in {scope}" affordance is real text so the search's scope is announced, not inferred | Distinguishes list-scope from global search for AT users. |
| Empty / RBAC-empty | The still-offered "Ask AI" / Navigate items are the reachable next actions | The empty state is a labelled region with a heading, never a silent blank list | An RBAC-limited role still gets a functional, non-empty palette. |
| AI answer card | The follow-up composer and any "send for approval" are ordinary focusable controls | Confidence and reasoning are real text (per `ConfidenceBadge`); citations are labelled links | A low-confidence "do it" is truly `disabled`, not just dimmed. |

All of the above meet the WCAG 2.1 AA floor of [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md); the
palette's dialog and focus management come from Radix/cmdk and are preserved, never stripped, when
re-skinning ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Foundations`).

# Theming, Dark Mode & RTL

- **The palette is `surface-glass`.** It is one of only two surfaces in the whole app that use the
  frosted-glass treatment (the other being the AI Command Center overlay), because it floats
  conceptually above the entire app rather than belonging to a page
  ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Elevation & Surfaces`). Everything else is
  ink-scale surface.
- **`accent` appears in exactly two places** in search: the highlighted match `<mark>`
  (`bg-accent-100 text-accent-700`) and the "Ask AI" item's icon — both legitimate under the rule that
  accent marks either the primary action or an AI-touched element. A result row's status uses its
  `StatusPill` tone, never accent as decoration.
- **Dark mode is a token remap only** — the glass tint, the highlight, and the row hover resolve to
  their dark values with no `dark:` raw-color variant ([`../THEMING.md`](../THEMING.md),
  [`../DARK_MODE.md`](../DARK_MODE.md)); the glass blur is kept performant, and it is *not* applied to
  the inline scoped inputs (which are ordinary `SearchInput`s).
- **RTL mirrors via logical properties.** The input's leading search icon and trailing shortcut hint
  use `ps-*`/`pe-*`; result-item icons use `me-2`; a result's trailing `AmountCell`/`StatusPill` sits
  at the inline-end. The palette slides/fades in place (no directional slide) so it needs no
  mirroring beyond its logical layout.
- **Amounts, codes, and dates inside results never mirror** — a result's `AmountCell` stays `dir="ltr"`
  `latn`, a currency code stays Latin, even inside an Arabic result row, per the numeral rule
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Theming & RTL`).

# i18n & Formatting

- **Result titles are bilingual data, not translations.** `SearchResultItem` renders `title_en` /
  `title_ar` by `useLocale()`; the aggregate endpoint returns both regardless of `Accept-Language`, so
  an Arabic-UI user can still type an English account name and match it
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Theming & RTL → Bilingual data rendering`).
- **All chrome strings are i18n keys** in both dictionaries — placeholders, group headings, "Searching…",
  "No results for '{q}'", "Ask AI about '{q}'", "See all", type labels — failing `npm run i18n:check`
  if a key is present in only one language ([`../README.md`](../README.md) `# Getting Started`). Arabic
  copy is authored to the professional-Gulf register
  ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Voice & Microcopy`).
- **Amounts and dates inside results** format through `AmountCell` / the shared `latn` date formatter,
  KWD defaulting to 3-decimal display — search never hand-rolls a money or date string.
- **The query itself is passed to the backend untransformed** (full-text over `tsvector`/`pg_trgm`);
  the client neither transliterates nor case-folds beyond what the input yields, leaving normalization
  to the server's own search configuration.

# Testing

- **Component tests (Vitest + Testing Library).** The palette opens on `⌘K` and closes on `Esc`;
  Navigate matches render synchronously with no network; below-minimum queries fire no Records call;
  `Highlight` wraps the matched substring and never splits an Arabic ligature; a result item links to
  the canonical filtered URL, not a bespoke route.
- **RBAC filtering tests.** With a mocked permission set lacking a record type, the palette renders no
  group for it and no action requiring its permission — asserted directly, because this is the rule
  most costly to regress. A mocked `403` from one fan-out call degrades only that group, leaving
  Navigate and Ask AI usable.
- **Debounce / query-shape tests.** The Records query fires the documented `q`/`per_type` params after
  the debounce window, not per keystroke, and re-opening on the same query serves the cache.
- **Playwright (E2E).** Open `⌘K` → type an account name → the aggregate request carries the documented
  grammar → `Enter` on a record navigates to its canonical URL → the URL survives a refresh; and the
  "Ask AI" path opens `/search?q=…&ask=1` and renders the answer card with a confidence badge but no
  committed mutation. Run across LTR and RTL and both themes.
- **Accessibility CI gate.** The palette (idle, searching, results, empty, RBAC-empty), the results
  page search, and the scoped input Storybook stories run through `axe-core`; a keyboard-only pass
  verifies the dialog focus trap, cross-group roving focus, and focus return to the trigger before
  release.

# Edge Cases

- **A record result the user has since lost access to.** Between the search response and the click, a
  permission may have changed; navigating there yields the server's `403`, caught by the route's
  `error.tsx` boundary into an "You don't have access to this" `ErrorState`, never the Next.js default
  error screen ([`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md) `# Permission-Aware Nav`).
- **A no-permission-to-read-anything role.** A role with zero readable record types still gets a
  working palette and `/search` — Navigate results and, if held, Ask AI — never an access wall, matching
  the "no single gating permission" precedent of the search screen.
- **A one-group failure.** If the customers fan-out `503`s but journal entries succeed, the palette
  shows the entries group and an inline "Couldn't load customer results" row for the failed one, not a
  whole-empty or whole-error palette.
- **AI engine unavailable (`503`).** The "Ask AI" item and the answer card render a distinct
  "temporarily unavailable" state honoring the endpoint's `Retry-After`, never an infinite spinner
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases → AI engine unavailable`).
- **A query matching a screen name and a record.** Navigate outranks Records for an exact page-name
  match ("inventory" surfaces the Inventory screen above a fuzzy record), because the deterministic,
  network-free answer is always the cheapest and most certain.
- **Mixed-script and Eastern-Arabic-digit queries.** A query mixing Arabic and Latin, or containing
  Eastern-Arabic digits, is passed to the backend's own search config untransformed; result titles
  render in their stored language, and any numeric portion of a result (an amount, a code) still shows
  `latn` per the numeral rule.
- **A very long result title.** Titles `truncate` with a `title` attribute and a keyboard-focus tooltip
  carrying the full string; Arabic titles commonly run longer than English at the same size, so widths
  are never tuned to English samples ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge
  Cases`).
- **Rapid open/close and stale results.** Closing the palette does not cancel an in-flight query (its
  result is cached for a re-open), but reopening resets focus to the input and shows Recent when the
  query is empty — the most common `⌘K` use is "take me back," not search.
- **Reduced motion.** The palette's fade/scale entrance, the AI "thinking" dots, and any result
  stagger collapse to instant state changes under `prefers-reduced-motion: reduce`
  ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Motion`).

# End of Document
