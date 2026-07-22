# Search Patterns — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / SEARCH
---

# Purpose

This document is the design-system contract for QAYD's **search UX patterns** — the recurring, composed
arrangements by which a user finds a screen, a record, an action, or an AI answer: the global command palette
(`⌘K` / `Ctrl+K`), scoped in-page search, table search, result grouping and highlighting, recent and
suggested queries, the debounce path, the empty/no-results-to-refine-or-ask-AI hand-off, and RBAC-filtered
results. A pattern here is a *composition* over one shared search machinery, not a new primitive.

Three siblings own the pieces this document composes and it defers to them rather than duplicating them:
[`../components/COMMAND_PALETTE.md`](../components/COMMAND_PALETTE.md) is the atomic spec for the `⌘K` overlay
(anatomy, tokens, the cmdk keyboard contract); [`../../frontend/components/SEARCH_BAR.md`](../../frontend/components/SEARCH_BAR.md)
is the app-level component catalogue (the two call shapes, the debounce path, the RBAC belt-and-braces, the
recent/suggested sourcing); and [`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md)
owns the palette's search-and-action *grammar* (how Navigate / Records / AI & Actions rank). This document
restates the reusable *pattern shapes* across all three and cross-links back; where a prop, endpoint, or
ranking rule is needed, those documents govern.

Two platform rules bind every search surface. First, **results are RBAC-filtered end to end** — a screen the
role cannot open, an action it lacks the permission for, or a record type it cannot read is never rendered,
because each group's own fan-out is independently permission-checked server-side and the palette additionally
filters nav/action sources through `usePermission()` before display. Search is a faster path to the permitted
surface, never a wider one. Second, **AI answers are proposals, never commits** — "Ask AI" renders a response
with its confidence, reasoning, and citations and may *propose* an action routed through the normal human-gate,
but a keystroke in a search box never posts, approves, or changes financial data.

Every value below references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the `ink-1…12` scale, the single
brass `accent` (used in exactly two places in search), `accent-subtle` for the match `<mark>` and AI card,
the `surface-glass` treatment, and the `z-command` band.

> **Token reconciliation.** [`../../frontend/components/SEARCH_BAR.md`](../../frontend/components/SEARCH_BAR.md)
> is a pre-canonical app draft that names tokens in the older `ink-500` / `accent-600` / `accent-100` /
> `accent-700` scheme. This document is canonical and uses the [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md)
> names: the draft's `ink-500` is `ink-9`, its `accent-600` is `accent`, and its match-highlight pair
> `bg-accent-100 text-accent-700` is `bg-accent-subtle text-accent`. The atomic spec at
> [`../components/COMMAND_PALETTE.md`](../components/COMMAND_PALETTE.md) already uses the canonical names;
> where the app draft's literal class disagrees, the token here governs and the conflict is raised in review.

# When to Use

| Pattern | Use when | Do not use when |
|---|---|---|
| Global command palette (`⌘K`) | The user needs to jump anywhere — a screen, a record, an action, or the AI — from anywhere | Narrowing the rows of one table already on screen |
| Scoped in-page search | A picker or a single dataset needs a query box that visibly searches *this* list only | The user might mean "search everything" (that is the palette) |
| Table search (`q`) | A `DataTable` needs a top-level free-text filter over its own rows | A structured facet (that is a filter — see [`FILTER_PATTERNS.md`](./FILTER_PATTERNS.md)) |
| Result grouping | Results span more than one kind (Navigate, Invoices, Customers) | A single homogeneous result list |
| Highlighting | A match substring should be visually located within a longer title | A result whose whole label *is* the query |
| Recent / suggested | The idle state should offer a fast "take me back" or a role-appropriate starter | Mid-query, where live results should show instead |
| Empty → refine-or-ask-AI | A query returns nothing and the user needs a next move | Results exist |
| RBAC-filtered results | Always — every result surface | (never optional) |

# Anatomy

The command palette is the reference composition — a glass overlay with a combobox input over a grouped
listbox, ordered Navigate → Records → AI & Actions.

```
        ┌──────────────────────────────────────────────────────┐  ← surface-glass, z-command
        │  /  Search or jump to…                          Esc  │  ← CommandInput (role=combobox)
        ├──────────────────────────────────────────────────────┤
        │  NAVIGATE                                             │  ← CommandGroup heading
        │   ▸ Invoices                                         │     CommandItem (role=option, roving)
        │  ───────────────────────────────────────────────      │  ← CommandSeparator
        │  INVOICES                                            │
        │   ▸ INV-2026-0482 · Al-Fajr Trading   KWD 1,240.000  │  ← SearchResultItem (icon·title·amount·status)
        │  ───────────────────────────────────────────────      │
        │  AI & ACTIONS                                         │
        │   * Ask AI about "unpaid Al-Fajr invoices"           │  ← accent Sparkles; last group, never first
        ├──────────────────────────────────────────────────────┤
        │  No results for "…"   (still offers Ask AI + Navigate)│  ← CommandEmpty (never a dead end)
        └──────────────────────────────────────────────────────┘
```

| Part | Element | Token / rule |
|---|---|---|
| Overlay | `CommandDialog` portalled to document root | `surface-glass`, `z-command`, focus-trapped, `Esc`-closes |
| Input | `CommandInput` | `role="combobox"`, `aria-expanded`/`aria-controls`, auto-focused |
| Group | `CommandGroup` + heading | Navigate → Records → AI & Actions, stable order |
| Item | `CommandItem` / `SearchResultItem` | `role="option"`; icon + highlighted title (+ amount/status) |
| Match `<mark>` | `Highlight` | `bg-accent-subtle text-accent` — the one place accent marks a match |
| Empty | `CommandEmpty` | Navigate matches and "Ask AI" stay offered |

The Navigate source is synchronous and permission-filtered (no network), so "you typed a page name" is the
fastest, most-certain answer and sits above the network-dependent Records group; the AI action sits last,
never first, so the palette never nudges toward an AI answer before a deterministic one exists.

# Variants

## The global command palette (⌘K)

The palette is a **singleton** — one instance in the authenticated app shell, opened by `⌘K` or the Topbar
trigger pill, propless (its open state and query live in the shell store). It composes three sources.

```tsx
// components/shared/command-palette.tsx (design-system shape; grammar in NAVIGATION_SYSTEM.md)
export function CommandPalette() {
  const open = useShellStore((s) => s.commandPaletteOpen);
  const setOpen = useShellStore((s) => s.setCommandPaletteOpen);
  const [query, setQuery] = useShellStore((s) => [s.paletteQuery, s.setPaletteQuery]);
  const debounced = useDebouncedValue(query, 200);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setOpen(!open); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, setOpen]);

  const navMatches = useNavForPermissions(debounced);                 // synchronous, permission-filtered
  const { data: records, isFetching } = useQuery({
    queryKey: ['palette', 'records', debounced],
    queryFn: () => apiClient.get('/api/v1/search', { params: { q: debounced, per_type: 5 } }),
    enabled: debounced.trim().length >= 2,                            // 2-char full-text minimum
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
                <n.icon className="me-2 h-4 w-4 text-ink-9" aria-hidden />
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
              <Sparkles className="me-2 h-4 w-4 text-accent" aria-hidden />
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

## Scoped in-page search vs. table search

Both are thin `SearchInput`s ([`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md));
the difference is scope, not mechanism. A scoped input makes its scope *visible* so a user never mistakes a
list search for a global one; a table search feeds one `DataTable`'s `q` param.

```tsx
// components/search/scoped-search-input.tsx (shape) — the "in {scope}" affordance is the load-bearing detail
export function ScopedSearchInput({ value, onValueChange, scopeLabel, placeholder }: ScopedSearchInputProps) {
  return (
    <div className="relative max-w-xs">
      <Search className="absolute inset-inline-start-2.5 top-2.5 h-4 w-4 text-ink-9" aria-hidden />
      <Input className="ps-8 pe-16" value={value} onChange={(e) => onValueChange(e.target.value)}
             placeholder={placeholder ?? t('scopedPlaceholder')} aria-label={placeholder ?? t('scopedPlaceholder')} />
      {scopeLabel && (
        <span className="pointer-events-none absolute inset-inline-end-2 top-1/2 -translate-y-1/2 text-xs text-ink-9">
          {t('inScope', { scope: scopeLabel })}
        </span>
      )}
    </div>
  );
}
```

| | Command palette | Scoped in-page search | Table search (`q`) |
|---|---|---|---|
| Scope | The whole app | One picker/dataset | One `DataTable`'s rows |
| Backend | Fan-out per-module + aggregate `GET /search` | The picker's own `resource` query | The table's `q` param |
| Opens | `⌘K` / Topbar pill | Always visible in its toolbar | Always visible in the table toolbar |
| Debounce | 200ms | 250ms | 250ms |
| Never | — | Opens the palette or hits `GET /search` | Hits global search |

## Result grouping and highlighting

Results group by kind, each group with a heading and a per-group count; group *order* is meaningful and stable
(Navigate → Records → AI & Actions), but the rows *within* a group are never re-ranked client-side — each
source's own backend ranking (trigram similarity, full-text rank) is trusted as-is.

```tsx
// Highlight wraps the matched substring in a quiet <mark>, bidi-safe (never splits an Arabic ligature)
export function Highlight({ text, query, className }: HighlightProps) {
  const parts = splitBidiSafe(text, query);   // never splits inside a ligature
  return (
    <span className={className}>
      {parts.map((p, i) => p.match
        ? <mark key={i} className="rounded-sm bg-accent-subtle px-0.5 text-accent">{p.text}</mark>
        : <span key={i}>{p.text}</span>)}
    </span>
  );
}
```

A record result links to the *canonical URL the owning screen's own filter UI would produce* — a customer
result goes to `/sales/invoices?filter[customer_id]=…`, not a bespoke detail view — matching the deep-link
rule and the filter-serialization contract in [`FILTER_PATTERNS.md`](./FILTER_PATTERNS.md).

## Recent and suggested

The idle state (empty query) fires no network and offers two things: **Recent** (the last 5 visited routes in
the palette, the last 10 submitted queries on `/search` — client-only, per-user, cleared on sign-out, only
ever routes the user could reach) and **Suggested** (role-driven starter shortcuts sourced from the same
Urgent-Actions signal the Dashboard reads, so a role that cannot reconcile is never suggested "reconcile a
bank account"). The idle state is the common `⌘K` use — "take me back," not search.

## Empty / no-results → refine or ask AI

A no-match state is never a dead end. `CommandEmpty` shows "No results for '{q}'" *plus* the still-offered
"Ask AI about '{q}'" and any Navigate matches; the `/search` page shows a per-group and whole-page empty state
with a refine hint. An RBAC-limited role with zero readable record types still gets a working palette
(Navigate + Ask AI), never an access wall.

## The AI answer card

On `/search`, the "Ask AI" path renders a `SearchAiAnswerCard` — the search-surface counterpart of
`AIProposalPanel`. It shows the answer, its confidence and reasoning, inline citations to the source records,
and a follow-up composer, and commits nothing on its own.

```tsx
// components/search/search-ai-answer-card.tsx (shape) — accent-subtle = "this came from the AI layer"
export function SearchAiAnswerCard({ answer }: { answer: AiSearchAnswer }) {
  return (
    <Card padding="md" className="space-y-3 bg-accent-subtle/40 border-s-2 border-s-accent">
      <div className="flex items-start gap-3">
        <AgentAvatar agentCode={answer.agentCode} />
        <div className="min-w-0 flex-1">
          <p className="text-md text-ink-12">{answer.text}</p>
          <ConfidenceBadge confidence={normalizeConfidence(answer.confidenceScore, 'percentage')}
                           reasoning={answer.reasoning} size="sm" />
        </div>
      </div>
      {answer.citations.length > 0 && (
        <ul className="flex flex-wrap gap-1.5 text-xs">
          {answer.citations.map((c) => (
            <li key={c.href}><a href={c.href} className="text-ink-9 underline-offset-2 hover:text-accent hover:underline">{c.label}</a></li>
          ))}
        </ul>
      )}
      {/* Follow-up composer + optional "Send for approval" — routes through the normal human-gate */}
    </Card>
  );
}
```

# Composition

## Mounting the palette once in the shell

The palette is a singleton mounted in the authenticated `(app)` layout so `⌘K` works on every route without
each screen wiring it. Its open state and query live in the shell store (chrome-only, never persisted).

```tsx
// app/(app)/layout.tsx (excerpt)
export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <AppShell topbar={<><CommandPaletteTrigger className="me-auto" />{/* … */}</>}>
      {children}
      <CommandPalette />   {/* portalled to the document root; opened by ⌘K or the Topbar trigger */}
    </AppShell>
  );
}
```

The Topbar's `CommandPaletteTrigger` is a pill (`Search or jump to…` + a `⌘K` hint) that flips the same store
flag the keyboard shortcut does — one open path, two entry points.

## The /search results page

The persistent page composes `GlobalSearchInput` + the result groups over the aggregate endpoint, with each
group's "See all" behind the cursor-paginated single-type view. Every part of a search is a shareable URL
(`/search?q=…&type=…&ask=1`), so a `/search` view is deep-linkable and refresh-surviving, exactly like a
filtered list ([`FILTER_PATTERNS.md`](./FILTER_PATTERNS.md)).

```tsx
// components/search/search-results.tsx (shape — screen owns the page shell)
export function SearchResults({ q, setQ, ask }: SearchResultsProps) {
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

## Two call shapes, one machinery

The palette and `/search` use two different call shapes over the same backend search machinery
(`tsvector` / `pg_trgm` / `pgvector`): the palette fans out to per-module endpoints capped at ~5 rows per type
for a fast preview; the results page uses the single aggregate `GET /api/v1/search`, with
`GET /api/v1/search/{type}` behind each "See all." A `ScopedSearchInput` is neither — it feeds its own
table's `q`.

# States & Behavior

| State | Palette | Inline / results |
|---|---|---|
| Idle (empty query) | Recent routes + suggested starters; no network fired | Recent + Suggested searches; a scoped box shows its placeholder |
| Typing, below minimum | Navigate matches only (synchronous); no Records call below 2 chars | Prior results held; no aggregate call below the 2-char minimum |
| Searching | `CommandList` sets `aria-busy`; a subtle inline "Searching…"; prior results kept via cache to avoid flash | Skeleton result cards on first load |
| Results | Grouped Navigate → Records → AI & Actions; `Highlight` on matched text | Grouped cards with per-group counts and "See all" |
| Empty (no matches) | `CommandEmpty` + still-offered "Ask AI" and any Navigate matches | Per-group and whole-page empty state with a refine hint |
| RBAC-empty | A role with zero readable types still gets Navigate + Ask AI, never an access wall | Same — no single gating permission |
| One-group error | The failed source degrades to an inline "Couldn't load {type} results" row; Navigate and Ask AI stay usable | A group-level `ErrorState`, not a whole-page failure |
| AI unavailable (`503`) | The "Ask AI" item renders a distinct "temporarily unavailable" state, honoring `Retry-After`, never an infinite spinner | The answer card shows the same distinct state |

Behavioral rules:

- **Debounce at 200ms** for palette Records and `/search`; below the minimum no network fires; a short
  `staleTime` makes re-opening on the same query instant. Navigate is synchronous — a page jump never waits.
- **Ranking is not re-derived client-side.** The palette orders only the *groups*; within a group each
  backend ranking is trusted. Navigate's own ranking (exact → prefix → fuzzy → module order) is the one
  client-side ranking, over a small, in-memory, deterministic set.
- **RBAC is belt-and-braces.** Server-side, every fan-out independently permission-checks and a crafted
  request still gets a `403`. Client-side, Navigate is pre-filtered through `filterNavByPermissions` and every
  action carries a `permission` key checked before render — an action a role lacks is *omitted*, not disabled,
  because its mere presence is information.
- **The AI item hands off, never answers inline.** Activating "Ask AI" opens `/search?q=…&ask=1`, so the
  answer renders on a surface that can show reasoning, citations, and a follow-up composer. Below the
  confidence threshold the answer is shown but its "do it" affordance is disabled.

# Content & Copy Guidance

| Surface | Guidance | Example |
|---|---|---|
| Palette placeholder | Names both jobs — search and jump | "Search or jump to…" |
| Group heading | The kind, uppercase-styled via `text-xs` | "NAVIGATE" / "INVOICES" / "AI & ACTIONS" |
| Scoped placeholder | Names the scope | "Search invoices…" not "Search…" |
| Scope affordance | Explicit, so list-scope is never mistaken for global | "in Invoices" |
| No-results | States the query and offers the next move | "No results for 'al-fajr'." + "Ask AI about 'al-fajr'" |
| Ask AI item | Quotes the query so the hand-off is unambiguous | "Ask AI about 'unpaid Al-Fajr invoices'" |
| See all | Directs to the deep view | "See all invoices →" |
| One-group error | Names the failed source, not a code | "Couldn't load customer results." |
| AI unavailable | Calm, honors retry | "AI is temporarily unavailable." |

Result titles are **bilingual data, not translations** — `title_en`/`title_ar` render by `useLocale()`, and
the aggregate endpoint returns both regardless of `Accept-Language`, so an Arabic-UI user can type an English
account name and match it. All chrome strings are i18n keys in both dictionaries; a key present in only one
fails `npm run i18n:check`. Arabic copy is the professional-Gulf register. The query itself is passed to the
backend untransformed — the client neither transliterates nor case-folds beyond what the input yields.

# Accessibility

| Element | Keyboard | Screen reader |
|---|---|---|
| `CommandPalette` | `⌘K`/`Ctrl+K` opens, `Esc` closes; `↓`/`↑` roving focus across groups; `Enter` activates; `Home`/`End` first/last | Labelled `role="dialog"`; `CommandInput` is a `combobox` with `aria-expanded`/`aria-controls`; the list is a `listbox` of `option` rows (cmdk-wired) |
| Result groups | Roving focus crosses group boundaries as one flattened list | Each heading is a group label read before its options; counts are real text |
| Search-in-progress | — | `CommandList` sets `aria-busy="true"`; a visually-hidden `aria-live="polite"` region announces count changes ("6 results") |
| `Highlight` | — | The `<mark>` carries no separate announcement; the full title reads as one string; bidi-safe, never splits an Arabic ligature |
| `GlobalSearchInput` | Auto-focused on `/search` mount | Labelled input; the results region is a labelled landmark, announced on update |
| `ScopedSearchInput` | Reachable via `Tab` and `Cmd/Ctrl+F` when its table is focused | The "in {scope}" affordance is real text, so scope is announced, not inferred |
| Empty / RBAC-empty | The still-offered "Ask AI" / Navigate items are the reachable next actions | A labelled region with a heading, never a silent blank list |
| AI answer card | The follow-up composer and any "send for approval" are ordinary focusable controls | Confidence and reasoning are real text; a low-confidence "do it" is truly `disabled`, not dimmed |

All of the above meet the WCAG 2.1 AA floor; the palette's focus trap and roving focus come from Radix/cmdk
and are preserved, never stripped, when re-skinning. Under `prefers-reduced-motion`, the palette's fade/scale
entrance and the AI "thinking" dots collapse to instant state changes.

# Theming, Dark Mode & RTL

- **The palette is `surface-glass`** — one of only two frosted-glass surfaces in the whole app (the other
  being the AI Command Center overlay), because it floats conceptually above the entire app rather than
  belonging to a page. Everything else is ink-scale surface; the scoped inputs are ordinary `SearchInput`s,
  never glass.
- **`accent` appears in exactly two places** in search: the highlighted-match `<mark>`
  (`bg-accent-subtle text-accent`) and the "Ask AI" icon — both legitimate under the rule that accent marks
  the primary action or an AI-touched element. A result row's status uses its `StatusPill` tone, never accent
  as decoration; amounts render in plain `ink` tabular numerals, never washed by polarity.
- **Dark mode is a token remap only** — the glass tint, the highlight, and the row hover resolve to their
  dark values with no `dark:` raw-color variant; the raised dialog is *lighter* than the canvas per the
  elevation-lightens rule; the blur is kept performant.
- **RTL mirrors via logical properties.** The input's leading search icon and trailing shortcut hint use
  `ps-*`/`pe-*`; result-item icons use `me-2`; a row's trailing `AmountCell`/`StatusPill` sits at the
  inline-end. The palette fades/scales in place (no directional slide), so it needs no mirroring beyond its
  logical layout; the `Search` glyph itself never mirrors.
- **Amounts, codes, and dates inside results never mirror** — a result's `AmountCell` stays `dir="ltr"`
  `latn`, a currency code stays Latin, even inside an Arabic result row.

# Do / Don't

| Do | Don't |
|---|---|
| Mount one palette singleton in the app shell | Wire `⌘K` per screen or mount multiple instances |
| Keep Navigate → Records → AI & Actions order stable | Float an AI answer above a deterministic Navigate match |
| Fire no network on idle or below 2 chars | Query per keystroke or on an empty input |
| Trust each source's backend ranking within a group | Re-rank rows client-side |
| Filter Navigate and actions by permission before render | Show a disabled action a role lacks — its presence is information |
| Link a record to its canonical filtered URL | Invent a bespoke detail route for a search result |
| Make a scoped search show its "in {scope}" affordance | Let a list search look identical to global search |
| Keep an empty state offering refine + Ask AI | Render a bare "No results" dead end |
| Keep `accent` to the `<mark>` and the Ask-AI icon | Use accent as row decoration or a status color |
| Hand the AI query off to `/search` for reasoning + citations | Answer inline in the palette with no citation surface |

# End of Document
