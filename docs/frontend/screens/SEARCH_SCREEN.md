# Search Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: SEARCH_SCREEN
---

# Purpose

This document is the concrete, build-ready screen specification for `app/(app)/search/page.tsx` — the
results page half of QAYD's Global Search — and, by extension, the one addition Global Search makes to the
⌘K Command Palette overlay. It is the implementation-level companion to `docs/frontend/SEARCH.md`, in
exactly the relationship this series' other screen documents already establish between a narrative module
document and its concrete screen document: `docs/frontend/screens/ACCOUNTING_SCREEN.md` is to
`docs/frontend/ACCOUNTING.md` what this document is to `SEARCH.md`, and `docs/frontend/screens/
SALES_SCREEN.md` is to `docs/frontend/SALES.md` the same way. Where `SEARCH.md` argues *why* Global Search
takes the shape it does — two coordinated surfaces sharing one backend contract, a federated fan-out over
each module's own existing `tsvector`/`pg_trgm`/`pgvector` search logic rather than a unified index, and the
three platform rules that make Search the single most consequential place a permission leak or a silent AI
overreach could occur (every result permission-filtered at its source, AI as a retrieval-and-synthesis layer
that never becomes ground truth, and Search never mutates anything) — this document specifies exactly how
`/search` is built: the literal `page.tsx`/`loading.tsx` source, full prop contracts and implementations for
the screen's own composed components (`SEARCH.md` names nine of them in a table; this document gives working
source for seven and a full prop contract for the remaining two), the actual query-key factory and hook
bodies behind the two-call-shape design `SEARCH.md` only describes in prose, additional worked
request/response JSON an engineer can paste directly into a test fixture, a region-by-state matrix, the
platform's concrete design tokens as they apply to Search's own surfaces, the full keyboard-binding table,
and the implementation-level races (SSE disconnects, back-button interactions with a Type Tab switch, a
citation clicked mid-navigation) that only surface once the mutation-free, read-heavy wiring below is
actually written. Every fact `SEARCH.md`, `NAVIGATION_SYSTEM.md`, `LAYOUT_SYSTEM.md`, `COMPONENT_LIBRARY.md`,
`ACCESSIBILITY.md`, `RESPONSIVE_DESIGN.md`, `DESIGN_LANGUAGE.md`, `FRONTEND_ARCHITECTURE.md`, `API_PAGINATION.md`,
and `API_FILTERING_SORTING.md` already fixed — routes, permission keys, endpoint paths, component names,
query-key shapes, design tokens — is reused verbatim here, never re-derived or quietly changed. Where this
document adds a wire-level detail those documents left implicit (chief among them, the exact mechanism by
which a per-keystroke aggregate call and a submit-triggered Ask AI answer share one endpoint without
colliding in the query cache — see `# Data & State`), it is called out explicitly as this document's own
concrete resolution, additive to `SEARCH.md`'s design, never a contradiction of it.

Concretely, this screen renders four things, matching `SEARCH.md → Layout & Regions`'s own enumeration:

1. **A sticky `GlobalSearchInput`** — large, auto-focused on desktop landing, pre-filled and pre-selected
   from `?q=` when present, driving both the aggregate "All" view and whichever single-type Type Tab is
   active.
2. **`SearchTypeTabs`** — "All" plus one live-counted tab per non-empty, permission-filtered result group.
3. **A Results column** of independently-loading, independently-empty `SearchResultGroup`s (or, with no
   query at all, `RecentSearches` + `SuggestedSearches` instead).
4. **A docked `SearchAiAnswerCard`** — a Retrieval-Augmented Generation surface synthesizing prose from the
   exact structured results and document chunks already visible beside it, never a second, independent
   answer.

This screen owns no business logic and computes nothing. Every row it renders was ranked, filtered, and
permission-checked by Laravel (or, for the Documents group, by the `pgvector`/HNSW chunk-retrieval path
`PRODUCTS.md`'s Smart Search agent already establishes as a platform pattern); every quick action a result
card exposes calls the exact mutation endpoint that record's own screen already calls, under the acting
user's own session and permissions — this screen introduces no parallel write path of any kind, which is
precisely what makes it safe for `# AI Integration` below to state, without qualification, that Search's
Ask AI surface can help a user find and understand a record but never becomes a second way to change it.
This document introduces no new route, no new permission key, no new API endpoint, and no new design token
beyond the ones `SEARCH.md` and its own cited sibling documents already fix.

# Route & Access

## App Router path

```text
app/(app)/search/
├── page.tsx          # ★ THIS DOCUMENT — results page, Server Component shell
└── loading.tsx        # Route-level skeleton — see # States

components/layout/
└── command-palette.tsx   # Owned by NAVIGATION_SYSTEM.md/LAYOUT_SYSTEM.md; this document adds exactly
                            # one CommandItem row (the "View all N results" handoff) and changes nothing
                            # else about its existing three-group anatomy
```

There is deliberately no nested segment under `search/` — a Type Tab, a facet, and the empty-query state are
all `searchParams`-driven (`# Data & State`'s URL contract), never their own route, because every one of
them must remain a single, shareable link rather than a navigation the browser's back button would need to
re-derive from a route stack. This is a narrower route tree than every sibling screen document in this
series (`ACCOUNTING_SCREEN.md`'s five nested segments, `SALES_SCREEN.md`'s parallel/intercepting quick-create
routes) precisely because Search has no create flow, no detail route, and no sub-navigation of its own to
host — its "detail view" for any result is always the record's own canonical route elsewhere in the tree.

## Permission gate

Reproduced in condensed form from `SEARCH.md → Route & Access` so this document is self-contained for an
engineer building only this page; `SEARCH.md` remains the owning source if the two ever appear to disagree.

| Control | Permission | Behavior if absent |
|---|---|---|
| `/search` route itself | **None.** No single permission gates Search, matching `DASHBOARD.md`'s identical "no single gating permission" precedent | Never applicable — every authenticated company member reaches `/search` |
| Each `groups[]` entry in the aggregate response | That entity type's own read permission (table below) | The group is omitted from both the Type Tabs row and the Results column — never rendered empty-and-permission-denied |
| Documents group, per row | The owning record's own attachable permission, resolved server-side per row | That specific document row is omitted; the group itself still renders if any other row in it resolves |
| Ask AI card | `ai.chat` | The whole card is omitted (`<Can permission="ai.chat">`); the structured Results column is entirely unaffected |
| A result card's quick action (Post, Approve, Reconcile, …) | That action's own permission on the target record | The action is omitted from the card, never shown disabled — identical to the target record's own screen |

| Result group | Permission | | Result group | Permission |
|---|---|---|---|---|
| Accounts | `accounting.accounts.read` | | Bills | `purchasing.bill.read` |
| Journal Entries | `accounting.journal.read` | | Documents | Resolved per row |
| Customers | `accounting.customer.read` | | Knowledge (AI) | `reports.read` |
| Vendors | `accounting.vendor.read` | | Approvals | Assigned approver, or `ai.approve` |
| Products | `products.read` | | Navigate (pages) | Per-item, from `NAV_TREE` |
| Invoices | `sales.read` | | Ask AI answer | `ai.chat` |

## Roles

Translated from `SEARCH.md`'s per-group permission table into what each role concretely encounters on this
one screen, matching the Roles-table convention `ACCOUNTING_SCREEN.md` and `SALES_SCREEN.md` both use:

| Role | What renders on `/search` |
|---|---|
| Owner, CEO, CFO | Every group the company has data for, the full Ask AI card, every quick action a result card can carry. |
| Finance Manager, Senior Accountant, Accountant | Accounts, Journal Entries, Customers, Vendors, Invoices, Bills, Documents, Ask AI; Knowledge and Approvals groups appear only if the narrower `reports.read`/approver-assignment condition is separately met. |
| Sales Manager, Sales Employee | Customers, Invoices, Products, Documents, Navigate, Ask AI (if `ai.chat` held); Accounts, Journal Entries, Bills, and Payroll-adjacent Knowledge rows never appear — omitted at the source, not filtered client-side. |
| Purchasing Manager, Purchasing Employee | Vendors, Bills, Products, Documents, Navigate, Ask AI; the mirror image of the Sales role above. |
| Inventory Manager, Warehouse Employee | Products, Navigate; Ask AI only if `ai.chat` is separately granted, which most warehouse-scoped roles do not hold. |
| Auditor, External Auditor, Read Only | Every group a broad read role can see, fully read-only — every quick action omitted from every result card, matching that action's own governing screen's identical rule for these roles. |
| A maximally narrow custom role (zero read permission on every searchable entity type) | Still a working, non-empty `/search` — Navigate results (permission-filtered `NAV_TREE` matches) and, if `ai.chat` is held, the Ask AI card. Never a blank page or a "you don't have access" wall; see `# Edge Cases`. |
| AI service account | Never renders this screen as a user. Its scoped, read-plus-suggestion-producing identity feeds the ranking-time AI described in `# AI Integration` and is structurally incapable of holding any of this screen's action permissions, regardless of company automation policy. |

## Keyboard entry

`⌘K`/`Ctrl+K` opens the palette from anywhere, including from `/search` itself — this is Search's own,
always-available, single-keystroke entry point, and this document deliberately does not introduce a `G`-then-
letter mnemonic to duplicate it. `ACCESSIBILITY.md`'s bound keyboard table lists six live module chords
(`G D` Dashboard, `G A` Accounting, `G L` General Ledger, `G B` Banking, `G R` Reports, `G I` AI Command
Center) with no seventh entry for Search, while `NAVIGATION_SYSTEM.md`'s own Global Shortcuts table calls the
entire `G`-then-letter scheme "reserved, not yet bound" — a small, pre-existing inconsistency between the two
documents this document does not attempt to resolve on Search's behalf. Adding a `G S` binding here would
either collide with whichever resolution that inconsistency eventually receives, or simply be redundant: a
mnemonic exists to make a destination reachable without a mouse in roughly the same number of keystrokes as
opening it directly, and `⌘K` already *is* that for Search, one keystroke shorter than any two-letter chord
could be. On `/search` itself, `/` is deliberately **not** bound to refocus `GlobalSearchInput` (unlike
GitHub's or Linear's own convention) because QAYD reserves bare, unmodified letter keys for the `G`-chord
scheme platform-wide, and a stray `/` typed as the first character of a legitimate query (a fraction in a
memo, a file path pasted from elsewhere) must never be intercepted.

# Layout & Regions

The two ASCII wireframes and the region table below are reproduced from `SEARCH.md → Layout & Regions` for a
single source of truth on the pixel arrangement; the literal `page.tsx`/`loading.tsx` source beneath them —
which `SEARCH.md` describes only in prose — is this document's own addition, given in full so an engineer
has the exact file to start from.

## `/search` results page — desktop, `lg`+

```
┌───────────────────────────────────────────────────────────────────────────────┐
│  🔍  diyar                                                        [Filters ▾]  │  ← GlobalSearchInput
├─────────────────────────────────────────────────────────────────────────────── ┤
│ [All 9] [Customers 1] [Invoices 2] [Journal Entries 3] [Documents 2] [⋯]        │  ← SearchTypeTabs
├───────────────────────────────────────────────────┬───────────────────────────┤
│  CUSTOMERS                              See all →  │  ✦ Ask AI about "diyar"   │
│  ▸ Diyar Real Estate — 2 open invoices              │  Diyar Real Estate has    │
├─────────────────────────────────────────────────── │  2 open invoices totaling │
│  INVOICES                                See all →  │  KD 44,020.000 [1][2],    │
│  ▸ INV-2208 — KD 44,020.000 — overdue               │  last paid INV-2190 on    │
│  ▸ INV-2190 — KD 12,500.000 — paid                  │  Jul 2 [3].               │
├─────────────────────────────────────────────────── │  88% confidence           │
│  JOURNAL ENTRIES                         See all →  │  ─────────────────────── │
│  ▸ JE-1091 — AR write-off, Diyar Real Estate        │  [1] INV-2208  [2] INV-  │
│  ▸ JE-1077 — Diyar Real Estate receipt              │  2190  [3] Receipt #441  │
├─────────────────────────────────────────────────── │                           │
│  DOCUMENTS                                See all →  │  [ Ask a follow-up… ]     │
│  ▸ Lease agreement.pdf — "…Diyar Real Estate,        │                           │
│    Unit 14B, term ending…"                          │                           │
└─────────────────────────────────────────────────────┴───────────────────────────┘
```

## Mobile, `base`–`sm`

```
┌───────────────────────────────┐
│ 🔍 diyar                  ✕   │  ← GlobalSearchInput, not auto-focused
├───────────────────────────────┤
│ ⇤ [All 9][Custmrs][Inv.][JE] ⇥│  ← SearchTypeTabs, horizontal-scroll
├───────────────────────────────┤
│ ✦ Ask AI has an answer —      │  ← collapsed 1-line affordance,
│   tap to expand            ▾  │    above results, never below fold
├───────────────────────────────┤
│ CUSTOMERS                See →│
│ ▸ Diyar Real Estate            │
│   2 open invoices               │
├───────────────────────────────┤
│ INVOICES                 See →│
│ ▸ INV-2208  KD 44,020.000      │
│   overdue                       │
├───────────────────────────────┤
│ 🏠  💰  🏦  🔍  ⋯               │  ← bottom tab bar, Search active
└───────────────────────────────┘
```

## Region table with implementation-level container/grid classes

| Region | Container classes | Content | Notes |
|---|---|---|---|
| `GlobalSearchInput` | `sticky top-(--topbar-h) z-(--z-sticky) border-b border-ink-6 bg-surface` | Search icon, text input, clear button | Same `z-(--z-sticky)` token every sticky filter bar in the platform uses (`LAYOUT_SYSTEM.md → Z-index scale`) |
| `SearchTypeTabs` | `sticky top-[calc(var(--topbar-h)+56px)] z-(--z-sticky) border-b border-ink-6 bg-surface overflow-x-auto` | "All" + per-group tabs with count badges | Horizontal-scroll below `md`, per `RESPONSIVE_DESIGN.md` Pattern 3's tab-overflow handling |
| Results column | `col-span-12 lg:col-span-8 space-y-6` | Stacked `SearchResultGroup`s | Independently `<Suspense>`-streamed per group — see `# Performance` |
| Ask AI rail | `col-span-12 lg:col-span-4` (order-first below `lg`, order-last at `lg`+) | `SearchAiAnswerCard` | `lg:sticky lg:top-[calc(var(--topbar-h)+112px)] lg:self-start` so it stays in view while the (typically longer) Results column scrolls beside it |
| Empty-query state | Replaces Results column + Ask AI rail entirely | `RecentSearches` + `SuggestedSearches` | Never both rendered alongside a populated Results column — mutually exclusive with a non-empty `?q=` |

## `app/(app)/search/page.tsx`

```tsx
// app/(app)/search/page.tsx
import { Suspense } from 'react';
import { GlobalSearchInput } from '@/components/search/global-search-input';
import { SearchResultsRegion } from '@/components/search/search-results-region';
import { SearchAiRail } from '@/components/search/search-ai-rail';
import { RecentSearches } from '@/components/search/recent-searches';
import { SuggestedSearches } from '@/components/search/suggested-searches';
import { WidgetSkeleton } from '@/components/dashboard/widget-skeleton';
import { PageHeader } from '@/components/layout/page-header';

export const dynamic = 'force-dynamic';
export const fetchCache = 'default-no-store'; // tenant- and query-scoped — never statically cached

export default async function SearchPage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string; type?: string; branch?: string }>;
}) {
  const { q = '', type = 'all', branch } = await searchParams;
  const branchId = branch ? Number(branch) : null;

  return (
    <div className="space-y-0">
      <PageHeader
        breadcrumb={
          q
            ? [{ label: 'Search', href: '/search' }, { label: `Search results for "${q}"`, href: `/search?q=${encodeURIComponent(q)}` }]
            : [{ label: 'Search', href: '/search' }]
        }
        title={q ? `Search results for "${q}"` : 'Search'}
      />
      <GlobalSearchInputServerSeed initialQuery={q} />
      {q.length >= 2 ? (
        <div className="grid grid-cols-12 gap-6 px-4 py-6 lg:px-6">
          <Suspense fallback={<WidgetSkeleton variant="search-results" className="col-span-12 lg:col-span-8" />}>
            <SearchResultsRegion query={q} activeType={type} branchId={branchId} className="col-span-12 lg:col-span-8" />
          </Suspense>
          <Suspense fallback={<WidgetSkeleton variant="ai-rail" className="col-span-12 lg:col-span-4" />}>
            <SearchAiRail query={q} className="col-span-12 lg:col-span-4 lg:order-last order-first" />
          </Suspense>
        </div>
      ) : (
        <div className="mx-auto max-w-2xl space-y-8 px-4 py-10">
          <RecentSearches />
          <Suspense fallback={<WidgetSkeleton variant="chips" />}>
            <SuggestedSearches />
          </Suspense>
        </div>
      )}
    </div>
  );
}
```

`GlobalSearchInputServerSeed` is a two-line Client Component boundary wrapping `GlobalSearchInput`
(`# Components Used`) so the Server Component page above stays a plain `async function` with no `'use client'`
directive of its own — the same boundary-isolation shape `FRONTEND_ARCHITECTURE.md → Client Component
boundaries` prescribes for every RSC page that needs exactly one interactive child. `SearchResultsRegion`
and `SearchAiRail` are thin Client Component wrappers around the hooks in `# Data & State` — they exist so
each has its own `<Suspense>` boundary and therefore its own independent loading state, matching
`DASHBOARD.md`'s per-region streaming pattern that every hub-shaped screen in this series reuses.

## `app/(app)/search/loading.tsx`

```tsx
// app/(app)/search/loading.tsx
import { WidgetSkeleton } from '@/components/dashboard/widget-skeleton';

export default function SearchLoading() {
  return (
    <div className="space-y-0">
      <div className="border-b border-ink-6 px-4 py-3">
        <div className="h-9 w-full max-w-md animate-pulse rounded-md bg-ink-3" />
      </div>
      <div className="border-b border-ink-6 px-4 py-2">
        <div className="flex gap-2">
          {[0, 1, 2, 3].map((i) => <div key={i} className="h-7 w-20 animate-pulse rounded-full bg-ink-3" />)}
        </div>
      </div>
      <div className="grid grid-cols-12 gap-6 px-4 py-6 lg:px-6">
        <WidgetSkeleton variant="search-results" className="col-span-12 lg:col-span-8" />
        <WidgetSkeleton variant="ai-rail" className="col-span-12 lg:col-span-4" />
      </div>
    </div>
  );
}
```

This skeleton renders identically regardless of whether the eventual landing is a populated results page or
the empty-query Recent/Suggested state — Next.js's `loading.tsx` fires before `searchParams` is even read, so
it cannot yet know which of the two shapes it is standing in for; this is an accepted, intentional imprecision
(a one-beat mismatch between a generic skeleton and the specific empty-query layout) rather than a defect,
because the alternative — reading `searchParams` inside `loading.tsx` itself — is not part of the Next.js App
Router's contract for that file.

# Components Used

Every visual element on this screen is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue or is one of
the nine Search-scoped compositions `SEARCH.md → Components Used` already names and files under
`components/search/`. That document gives the reasoning for why each exists (chiefly: why `DataTable` is
never reused here, since a customer row and an invoice row share almost no columns); this section gives the
concrete prop contracts and, for seven of the nine, the actual implementation an engineer copies into the
repository. `SearchFacetsPanel` and `SearchEmptyState` are given prop contracts only — their bodies are
thin, single-purpose compositions of primitives already shown in full elsewhere in this document set (a
`Popover`/`Sheet` wrapping ordinary form controls, and `EmptyState` with per-region copy respectively) and
reproducing them again here would not teach an implementer anything `COMPONENT_LIBRARY.md`'s own `Popover`/
`Sheet`/`EmptyState` entries do not already show.

| Component | Source | This document |
|---|---|---|
| `Command`, `CommandDialog`, `CommandInput`, `CommandList`, `CommandGroup`, `CommandItem` | `components/ui/command.tsx` | Unchanged — cited, not reproduced |
| `AmountCell`, `CurrencyTag`, `StatusPill`, `ConfidenceBadge`, `AiCardShell`, `ReasoningDisclosure`, `Badge`, `Skeleton`, `Card`, `Button`, `Tabs`, `Sheet`, `Popover`, `Tooltip`, `DropdownMenu`, `EmptyState`, `ErrorState`, `PermissionGate`/`Can` | `COMPONENT_LIBRARY.md` (existing) | Unchanged — reused verbatim, props unchanged |
| `GlobalSearchInput` | `components/search/global-search-input.tsx` | **Full source below** |
| `SearchTypeTabs` | `components/search/search-type-tabs.tsx` | **Full source below** |
| `SearchResultGroup` | `components/search/search-result-group.tsx` | **Full source below** |
| `SearchResultCard` | `components/search/search-result-card.tsx` | **Full source below** |
| `SearchAiAnswerCard` | `components/search/search-ai-answer-card.tsx` | **Full source below** |
| `RecentSearches` | `components/search/recent-searches.tsx` | **Full source below** |
| `SuggestedSearches` | `components/search/suggested-searches.tsx` | **Full source below** |
| `SearchFacetsPanel` | `components/search/search-facets-panel.tsx` | Prop contract only |
| `SearchEmptyState` | `components/search/search-empty-state.tsx` | Prop contract only |

## `GlobalSearchInput`

The large, sticky input driving both the "All" aggregate view and whichever single-type Type Tab is active.
Unlike a structured field, it never forces `dir` on its own text (`SEARCH.md → RTL & Localization`'s "the
search input itself does not force a script-specific `dir`" rule), and it distinguishes a **debounced
keystroke** (drives the cheap, structured-groups-only aggregate call) from a **submit** (Enter, or the
debounce settling past 500ms while the input still holds focus — drives the Ask AI answer and the URL write)
via two separate callback props rather than one, because the two events genuinely trigger different network
behavior (`# Data & State`).

```tsx
// components/search/global-search-input.tsx
'use client';

import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { Search as SearchIcon, X } from 'lucide-react';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useIsTouchDevice } from '@/hooks/use-is-touch-device';
import { useRecentSearchesStore } from './recent-searches';
import { cn } from '@/lib/utils';

interface GlobalSearchInputProps {
  onDebouncedChange: (query: string) => void; // fires at 200ms — drives the structured-groups aggregate call
  onSubmit: (query: string) => void;           // fires on Enter or a 500ms settle — drives the Ask AI call + URL write
  className?: string;
}

export function GlobalSearchInput({ onDebouncedChange, onSubmit, className }: GlobalSearchInputProps) {
  const t = useTranslations('search');
  const router = useRouter();
  const searchParams = useSearchParams();
  const initialQuery = searchParams.get('q') ?? '';
  const [value, setValue] = useState(initialQuery);
  const debounced = useDebouncedValue(value, 200);
  const inputRef = useRef<HTMLInputElement>(null);
  const isTouch = useIsTouchDevice();
  const pushRecent = useRecentSearchesStore((s) => s.push);

  useEffect(() => { onDebouncedChange(debounced); }, [debounced, onDebouncedChange]);

  // Submit-on-settle: 500ms of no further typing while focus is still on the input, distinct
  // from the 200ms structural debounce above and never fired a second time for the same value.
  const lastSubmitted = useRef(initialQuery);
  useEffect(() => {
    if (document.activeElement !== inputRef.current) return;
    if (debounced === lastSubmitted.current || debounced.length < 2) return;
    const settle = setTimeout(() => { lastSubmitted.current = debounced; onSubmit(debounced); }, 300);
    return () => clearTimeout(settle);
  }, [debounced, onSubmit]);

  // Desktop only: auto-focus AND pre-select on landing so retyping is one keystroke away.
  // Never on touch — an auto-opened keyboard would immediately cover the results the user
  // navigated here to see. See # Responsive Behavior.
  useEffect(() => {
    if (isTouch || !inputRef.current) return;
    inputRef.current.focus();
    inputRef.current.select();
  }, [isTouch]);

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    lastSubmitted.current = value;
    onSubmit(value);
    pushRecent(value);
    const params = new URLSearchParams(searchParams.toString());
    if (value) params.set('q', value); else params.delete('q');
    router.push(`/search?${params.toString()}`, { scroll: false });
  }

  return (
    <form
      role="search"
      onSubmit={handleSubmit}
      className={cn('relative flex items-center gap-2 px-4 py-3', className)}
    >
      <SearchIcon className="h-5 w-5 shrink-0 text-ink-8" aria-hidden />
      <input
        ref={inputRef}
        role="combobox"
        aria-expanded={value.length >= 2}
        aria-controls="search-results-region"
        aria-label={t('inputLabel')}
        value={value}
        onChange={(e) => setValue(e.target.value)}
        placeholder={t('placeholder')}
        className="w-full bg-transparent text-body text-ink-12 outline-none placeholder:text-ink-8"
        autoComplete="off"
        spellCheck={false}
      />
      {value && (
        <button
          type="button"
          aria-label={t('clear')}
          onClick={() => { setValue(''); inputRef.current?.focus(); }}
          className="rounded-full p-1 text-ink-8 hover:bg-ink-3 hover:text-ink-11"
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      )}
    </form>
  );
}
```

## `SearchTypeTabs`

```tsx
// components/search/search-type-tabs.tsx
'use client';

import { useTranslations } from 'next-intl';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import type { SearchResultGroupData, SearchResultType } from '@/types/search';

interface SearchTypeTabsProps {
  groups: SearchResultGroupData[]; // already permission-filtered server-side — every entry here is renderable
  navigateCount: number;           // uncounted "Navigate" matches, folded into "All" only, never its own tab
  activeType: SearchResultType | 'all';
  onChange: (type: SearchResultType | 'all') => void;
}

export function SearchTypeTabs({ groups, navigateCount, activeType, onChange }: SearchTypeTabsProps) {
  const t = useTranslations('search.tabs');
  const nonEmpty = groups.filter((g) => g.total > 0);
  const allTotal = navigateCount + nonEmpty.reduce((sum, g) => sum + g.total, 0);
  if (allTotal === 0) return null; // whole-page empty state owns this region instead — see # States

  return (
    <Tabs value={activeType} onValueChange={(v) => onChange(v as SearchResultType | 'all')}>
      <TabsList
        aria-label={t('ariaLabel')}
        className="flex gap-1 overflow-x-auto px-2 py-1.5 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      >
        <TabsTrigger value="all" className="shrink-0">
          {t('all')} <Badge variant="count" className="ms-1.5">{allTotal}</Badge>
        </TabsTrigger>
        {nonEmpty.map((g) => (
          <TabsTrigger key={g.type} value={g.type} className="shrink-0">
            {g.label} <Badge variant="count" className="ms-1.5">{g.total}</Badge>
          </TabsTrigger>
        ))}
      </TabsList>
    </Tabs>
  );
}
```

`nonEmpty` is recomputed from the same aggregate response on every render rather than cached separately, so
a group that empties out between two polls of the same query (rare, since Search holds no live subscription
of its own for structured groups — see `# Data & State → Realtime`) never leaves a stale, zero-count tab
visible; the tab simply is not in the array the next time this component renders.

## `SearchResultGroup`

```tsx
// components/search/search-result-group.tsx
'use client';

import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { SearchResultCard } from './search-result-card';
import type { SearchResultGroupData } from '@/types/search';

interface SearchResultGroupProps {
  group: SearchResultGroupData;
  seeAllHref: string; // built by the caller from the module's own list route + the active q/filter state
}

export function SearchResultGroup({ group, seeAllHref }: SearchResultGroupProps) {
  const t = useTranslations('search');
  if (group.total === 0) return null; // collapses to zero height — never an empty card of its own

  return (
    <section aria-labelledby={`search-group-${group.type}`} className="space-y-2">
      <div className="flex items-center justify-between">
        <h2 id={`search-group-${group.type}`} className="text-caption font-medium uppercase tracking-wide text-ink-9">
          {group.label}
        </h2>
        {group.total > group.items.length && (
          <Link href={seeAllHref} className="text-caption text-accent hover:underline">
            {t('seeAllIn', { count: group.total, module: group.label })} →
          </Link>
        )}
      </div>
      <div className="space-y-1.5">
        {group.items.map((item) => (
          <SearchResultCard key={`${item.type}-${item.id}`} item={item} />
        ))}
      </div>
    </section>
  );
}
```

## `SearchResultCard`

The single most novel composition on this screen: one shell, three small per-type switches (title,
subtitle, trailing figure) rather than one large per-type JSX branch, so a future eleventh entity type
extends three functions instead of duplicating the card's markup a tenth time. No financial figure or
status label is ever formatted differently here than on the record's own list screen, because the same
`AmountCell`/`StatusPill`/`CurrencyTag` primitives a `DataTable` column would use are composed directly —
this is the concrete proof of `SEARCH.md`'s own claim that reusing `DataTable` here would have forced a
lossy lowest-common-denominator table.

```tsx
// components/search/search-result-card.tsx
'use client';

import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { AmountCell } from '@/components/accounting/amount-cell';
import { CurrencyTag } from '@/components/shared/currency-tag';
import { StatusPill } from '@/components/shared/status-pill';
import type { SearchResultItem } from '@/types/search';

/** The one visible trace ranking-time AI leaves on a result card. Never a ConfidenceBadge,
 *  never an AiCardShell border — see # AI Integration. */
function MatchedByMeaningChip() {
  const t = useTranslations('search');
  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-ink-3 px-2 py-0.5 text-[11px] text-ink-9">
      {t('matchedByMeaning')}
    </span>
  );
}

export function SearchResultCard({ item }: { item: SearchResultItem }) {
  return (
    <Link
      href={item.href}
      data-result-href={item.href}
      className="@container flex items-start justify-between gap-3 rounded-lg border border-ink-6 bg-surface p-3 hover:border-ink-8 hover:bg-ink-2"
    >
      <div className="min-w-0 flex-1 space-y-0.5">
        <p className="truncate font-medium text-ink-12" title={displayTitle(item)}>{displayTitle(item)}</p>
        <p className="truncate text-caption text-ink-9">{displaySubtitle(item)}</p>
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1">
        {renderTrailing(item)}
        {item.matched_by === 'semantic' && <MatchedByMeaningChip />}
      </div>
    </Link>
  );
}

function displayTitle(item: SearchResultItem): string {
  switch (item.type) {
    case 'customer': case 'vendor': case 'product': return item.display_name;
    case 'invoice': case 'journal_entry': return item.reference;
    case 'bill': return item.vendor_bill_reference ?? item.reference;
    case 'account': return `${item.code} · ${item.display_name}`;
    case 'document': return item.title;
    case 'insight': case 'approval': return item.title;
    case 'page': return item.breadcrumbLabel;
  }
}

function displaySubtitle(item: SearchResultItem): string {
  switch (item.type) {
    case 'customer': case 'vendor': return item.subtitle ?? ''; // e.g. "2 open invoices · KD 44,020.000 outstanding"
    case 'invoice': case 'bill': return item.customer_name ?? item.vendor_name ?? '';
    case 'document': return item.snippet;   // highlighted chunk preview
    case 'insight': return item.summary;
    case 'approval': return item.requested_by_name;
    default: return '';
  }
}

function renderTrailing(item: SearchResultItem) {
  switch (item.type) {
    case 'invoice': case 'bill':
      return (
        <div className="flex items-center gap-1.5">
          <AmountCell amount={item.amount} currencyCode={item.currency_code} size="sm" />
          <StatusPill status={item.status} domain={item.type} size="sm" />
        </div>
      );
    case 'customer': case 'vendor':
      return item.currency_code && item.currency_code !== 'KWD' ? <CurrencyTag code={item.currency_code} /> : null;
    case 'approval':
      return <StatusPill status="pending" domain="approval" size="sm" />;
    default:
      return null;
  }
}
```

`data-result-href` is not decorative — `SearchAiAnswerCard`'s citation-click handler (below) queries the DOM
for it to scroll an already-visible card into view rather than navigating away, so a citation and its
matching result card share one stable identity even though they are rendered by two different components
reading two different parts of the same aggregate response.

## `SearchAiAnswerCard`

Composes the platform's existing `AiCardShell`/`ConfidenceBadge`/`ReasoningDisclosure` — `SearchAiAnswer`'s
own field names (`agent_code`, `confidence_score`, `citations`) do not exactly match the `AiDecision` shape
those components expect (`reasoning`, `sources`), so this component's one piece of real logic is the small
adapter object that reconciles the two without either shape changing to match the other.

```tsx
// components/search/search-ai-answer-card.tsx
'use client';

import { useState } from 'react';
import dynamic from 'next/dynamic';
import { useTranslations } from 'next-intl';
import { AiCardShell } from '@/components/ai/ai-card-shell';
import { Button } from '@/components/ui/button';
import type { SearchAiAnswer } from '@/types/search';

// Code-split: a role without ai.chat never downloads the Vercel AI SDK's useChat bundle at all.
const SearchAiFollowUpComposer = dynamic(() => import('./search-ai-follow-up-composer'), { ssr: false });

function CitedAnswer({ answer }: { answer: SearchAiAnswer }) {
  const parts = (answer.answer_text ?? '').split(/(\[\d+\])/g);
  return (
    <p className="text-body text-ink-11">
      {parts.map((part, i) => {
        const match = part.match(/^\[(\d+)\]$/);
        const citation = match ? answer.citations.find((c) => c.ref === Number(match[1])) : undefined;
        if (!citation) return <span key={i}>{part}</span>;
        return (
          <a
            key={i}
            href={citation.href}
            dir="ltr"
            className="mx-0.5 rounded bg-accent-subtle px-1 text-caption font-medium text-accent-700 hover:underline"
            onClick={(e) => { e.preventDefault(); focusOrNavigate(citation.href); }}
          >
            [{citation.ref}]
          </a>
        );
      })}
    </p>
  );
}

function focusOrNavigate(href: string) {
  const el = document.querySelector<HTMLElement>(`[data-result-href="${CSS.escape(href)}"]`);
  if (el) {
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.classList.add('ring-2', 'ring-accent');
    setTimeout(() => el.classList.remove('ring-2', 'ring-accent'), 1600);
  } else {
    window.location.assign(href);
  }
}

export function SearchAiAnswerCard({ query, answer, isLoading }: {
  query: string; answer: SearchAiAnswer | undefined; isLoading: boolean;
}) {
  const t = useTranslations('search.ai');
  const [followUpOpen, setFollowUpOpen] = useState(false);

  if (isLoading || !answer) {
    return (
      <div role="status" aria-busy="true" className="flex items-center gap-1.5 rounded-2xl border border-ink-6 bg-surface p-4">
        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-accent-subtle" />
        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-accent-subtle [animation-delay:150ms]" />
        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-accent-subtle [animation-delay:300ms]" />
      </div>
    );
  }

  if (answer.status === 'unavailable') {
    return <div className="rounded-2xl border border-ink-6 bg-surface p-4 text-body text-ink-9">{t('unavailable')}</div>;
  }

  if (answer.status === 'low_confidence' || !answer.answer_text) {
    // Deliberate asymmetry: no ConfidenceBadge at all — a badge showing an artificially low
    // number would still look like a measurement. The honest failure mode is "no answer."
    return <div className="rounded-2xl border border-ink-6 bg-surface p-4"><p className="text-body text-ink-9">{t('lowConfidence')}</p></div>;
  }

  // Adapts SearchAiAnswer onto the AiDecision shape AiCardShell/ReasoningDisclosure expect:
  // confidence_score is already 0–100 like ai_decisions.confidence_score, so 'percentage'
  // normalization applies (never 'fraction' — see # AI Integration); citations become sources.
  const decision = {
    agent_code: answer.agent_code,
    confidence_score: answer.confidence_score ?? 0,
    reasoning: t('reasoningIntro'),
    sources: answer.citations.map((c) => ({ id: String(c.ref), label: c.label, href: c.href })),
  };

  return (
    <AiCardShell decision={decision}>
      <CitedAnswer answer={answer} />
      <p className="mt-2 text-caption text-ink-8">{t('generatedAt', { time: answer.generated_at })}</p>
      <div className="mt-3 flex items-center justify-between">
        <Button variant="ghost" size="sm" onClick={() => setFollowUpOpen(true)}>{t('askFollowUp')}</Button>
      </div>
      {followUpOpen && <SearchAiFollowUpComposer query={query} resultIds={answer.citations.map((c) => String(c.id))} />}
    </AiCardShell>
  );
}
```

```tsx
// components/search/search-ai-follow-up-composer.tsx — dynamically imported, never in the main bundle
'use client';

import { useChat } from 'ai/react';
import { useTranslations } from 'next-intl';
import { Input } from '@/components/ui/input';
import { AmountCell } from '@/components/accounting/amount-cell';

export default function SearchAiFollowUpComposer({ query, resultIds }: { query: string; resultIds: string[] }) {
  const t = useTranslations('search.ai');
  const { messages, input, handleInputChange, handleSubmit, isLoading } = useChat({
    api: '/api/ai/chat',
    body: { context: { surface: 'search', query, result_ids: resultIds } },
  });

  return (
    <form onSubmit={handleSubmit} className="mt-3 space-y-2 border-t border-ink-6 pt-3">
      {messages.map((m) => (
        <p key={m.id} className="text-caption text-ink-11">{m.content}</p>
        // Every cited monetary figure inside a streamed message still renders through AmountCell
        // at the markdown-render layer this excerpt omits for brevity — never raw interpolated text,
        // per FRONTEND_ARCHITECTURE.md's Streaming AI chat rule (imported above for that renderer).
      ))}
      <Input value={input} onChange={handleInputChange} placeholder={t('followUpPlaceholder')} disabled={isLoading} aria-label={t('followUpPlaceholder')} />
    </form>
  );
}
```

## `RecentSearches`

Client-only, `localStorage`-backed, capped at 10, per-user, cleared on sign-out by the same session-teardown
routine that clears every other per-user client-side store — no network call, no query key, no loading state.

```tsx
// components/search/recent-searches.tsx
'use client';

import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import Link from 'next/link';
import { Clock } from 'lucide-react';
import { useTranslations } from 'next-intl';

interface RecentSearchesStore {
  queries: string[];
  push: (q: string) => void;
  clear: () => void;
}

export const useRecentSearchesStore = create<RecentSearchesStore>()(
  persist(
    (set, get) => ({
      queries: [],
      push: (q) => {
        const trimmed = q.trim();
        if (!trimmed) return;
        set({ queries: [trimmed, ...get().queries.filter((existing) => existing !== trimmed)].slice(0, 10) });
      },
      clear: () => set({ queries: [] }),
    }),
    { name: 'qayd-recent-searches' },
  ),
);

export function RecentSearches() {
  const t = useTranslations('search');
  const { queries, clear } = useRecentSearchesStore();
  if (queries.length === 0) return null; // a brand-new account shows SuggestedSearches only — see # States

  return (
    <section aria-labelledby="recent-searches-heading" className="space-y-2">
      <div className="flex items-center justify-between">
        <h2 id="recent-searches-heading" className="text-caption font-medium uppercase tracking-wide text-ink-9">
          {t('recentSearches')}
        </h2>
        <button type="button" onClick={clear} className="text-caption text-ink-8 hover:text-ink-11">{t('clear')}</button>
      </div>
      <ul className="space-y-1">
        {queries.map((q) => (
          <li key={q}>
            <Link href={`/search?q=${encodeURIComponent(q)}`} className="flex items-center gap-2 rounded-md px-2 py-1.5 text-body text-ink-11 hover:bg-ink-3">
              <Clock className="h-3.5 w-3.5 text-ink-8" aria-hidden />
              {q}
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
```

`GlobalSearchInput`'s own `handleSubmit` (above) calls `pushRecent(value)` directly from the same store — a
submitted query is recorded exactly once, at submit time, never from a debounce tick, so a query the user
typed and then deleted before ever pressing Enter never pollutes this list.

## `SuggestedSearches`

Role-driven starter chips, sourced from the same Urgent Actions signal `DASHBOARD.md`'s own Quick Actions row
already reads — a presentational reshaping of an existing feed, never a second judgment of what counts as
urgent.

```tsx
// components/search/suggested-searches.tsx
'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import { Sparkles } from 'lucide-react';
import { useTranslations } from 'next-intl';
import { apiClient } from '@/lib/api-client';
import { searchKeys } from '@/lib/query/keys';
import { Skeleton } from '@/components/ui/skeleton';

export function SuggestedSearches() {
  const t = useTranslations('search');
  const { data, isPending } = useQuery({
    queryKey: searchKeys.suggested(),
    queryFn: () => apiClient.get('/api/v1/ai/urgent-actions', { params: { per_page: 3 } }),
    staleTime: 60_000,
  });

  if (isPending) {
    return <div className="flex gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-8 w-32 rounded-full" />)}</div>;
  }

  const chips = (data?.data ?? []) as Array<{ id: string; label: string }>;
  if (chips.length === 0) return null; // fails silently — a convenience, not core functionality, per # States

  return (
    <section aria-labelledby="suggested-searches-heading" className="space-y-2">
      <h2 id="suggested-searches-heading" className="text-caption font-medium uppercase tracking-wide text-ink-9">
        {t('suggestedForYou')}
      </h2>
      <div className="flex flex-wrap gap-2">
        {chips.map((chip) => (
          <Link
            key={chip.id}
            href={`/search?q=${encodeURIComponent(chip.label)}`}
            className="inline-flex items-center gap-1.5 rounded-full border border-ink-6 px-3 py-1.5 text-caption text-ink-11 hover:border-accent hover:text-accent"
          >
            <Sparkles className="h-3 w-3" aria-hidden />
            {chip.label}
          </Link>
        ))}
      </div>
    </section>
  );
}
```

Selecting a chip behaves exactly like typing that text and pressing Enter (`href` points at `/search?q=…`,
a real navigation) — never a hidden, Search-specific code path a typed query could not also reach, matching
`SEARCH.md → Interactions & Flows`'s explicit rule for this exact control.

## `SearchFacetsPanel` (prop contract)

| Prop | Type | Description |
|---|---|---|
| `activeType` | `SearchResultType` | The single type currently active; facets are only shown once a Type Tab (not "All") is selected |
| `filters` | `Record<string, string>` | The current `filter[...]` query-string values for this type, per `API_FILTERING_SORTING.md`'s grammar |
| `onChange` | `(filters: Record<string, string>) => void` | Writes the new `filter[...]` params to the URL |

Body: a `Popover` at `lg`+, a `Sheet` below it (`RESPONSIVE_DESIGN.md` Pattern 4), containing exactly the
same status/date-range/amount-range/account controls that type's own dedicated list screen already renders
in its Filter Bar — no new control is invented here, since the whole point of reusing the `filter[...]`
grammar is that a facet picked in Search and a filter picked on `/sales/invoices` produce the identical
query-string shape.

## `SearchEmptyState` (prop contract)

| Prop | Type | Description |
|---|---|---|
| `scope` | `'group' \| 'page'` | Whether this is one group's own empty row or the whole-page zero-result state |
| `query` | `string` | Interpolated into the copy ("No {type} match '{q}'" / "No results for '{q}'…") |
| `type` | `string \| undefined` | Required when `scope="group"`, omitted for `scope="page"` |

Body: `COMPONENT_LIBRARY.md`'s `EmptyState` with `tone="calm"` and no illustrated character, per
`DESIGN_LANGUAGE.md`'s imagery stance — every entity type renders through this one variant so a Customers
empty row and a Documents empty row look identical apart from their interpolated copy.

# Data & State

## Endpoints this screen calls

Every endpoint below is owned and fully specified by `SEARCH.md → Endpoints`; this table is the condensed
subset `page.tsx` and its immediate children actually issue on first paint or from a control they render —
this screen introduces zero new endpoints.

| Purpose | Endpoint | Permission | First-paint or on-demand |
|---|---|---|---|
| Structured groups (debounced, every 2+ char) | `GET /api/v1/search?q=&types=&per_type=5&branch_id=` | None baseline; each `groups[]` entry independently checked | First paint, and every 200ms-debounced keystroke |
| Structured groups + AI answer (submit) | `GET /api/v1/search?...&include_ai_answer=true` | Same, plus `ai.chat` for the `ai_answer` sub-object | On submit/settle only — see below |
| Single-type page (Type Tab, "See all") | `GET /api/v1/search/{type}?q=&cursor=&filter[...]=` | That type's own read permission | On Type Tab switch / "Load more" |
| Ask AI follow-up | `POST /api/v1/ai/chat` (SSE) | `ai.chat` | On "Ask a follow-up" |
| Suggested Searches source | `GET /api/v1/ai/urgent-actions?per_page=3` | `ai.chat` (a role without it never renders the section) | First paint, empty-query landing only |
| A result card's quick action | The target record's own mutation endpoint (unchanged) | That action's own permission | On demand |

## The `include_ai_answer` parameter — this document's own wire-level resolution

`SEARCH.md → Data & State` establishes two behaviors that are each individually clear but leave one seam
unresolved at the wire level: the structured groups "genuinely benefit from updating on every 2-character-or-
more keystroke," while the Ask AI answer "fires once, on query submit… because a synthesized answer for
'di', 'diy', 'diya', 'diyar' in rapid succession would be four wasted model calls for one intent." Both
behaviors are described against the *same* endpoint, `GET /api/v1/search`, whose worked JSON example already
shows `ai_answer` embedded inline in the response. This document's concrete reconciliation: `GET /api/v1/
search` accepts an additive, optional `include_ai_answer` boolean parameter (default `false`), and the
frontend sends it as `true` on exactly one class of request — the submit-triggered one — never on a plain
debounce tick. When absent or `false`, Laravel's `SearchController::aggregate()` runs only the cheap SQL fan-
out and returns `"ai_answer": null`; when `true`, it additionally calls the orchestrator synchronously before
responding, which is why `SEARCH.md`'s own worked example (implicitly a submit-triggered call) already shows
a populated `ai_answer` object in the same response shape. This keeps the contract additive rather than
introducing a second endpoint: the response envelope, the `groups[]` shape, and every field `SEARCH.md`
already fixed are identical either way — only whether `ai_answer` is computed differs, controlled by one
request-side flag the frontend, not the user, decides on.

The two request classes are kept from colliding in the TanStack Query cache by extending
`searchKeys.aggregate`'s existing `filters` argument with one additional, optional field — the same additive-
extension pattern `SEARCH.md` itself uses for `SearchCitation` ("a `source` entry that also carries the
inline reference number and a resolved href"):

```ts
// lib/query/keys.ts — additive to SEARCH.md's own searchKeys factory, no existing key shape changes
export const searchKeys = {
  all: ['search'] as const,
  aggregate: (q: string, filters: { types?: string[]; branchId: number | null; withAnswer?: boolean }) =>
    [...searchKeys.all, 'aggregate', q, filters] as const,
  group: (type: string, q: string, filters: Record<string, unknown>) =>
    [...searchKeys.all, 'group', type, q, filters] as const,
  suggested: () => [...searchKeys.all, 'suggested'] as const,
  recent: () => [...searchKeys.all, 'recent'] as const, // client-only, no network — see RecentSearches above
};
```

A debounce-tick request and a submit request for the identical query string therefore occupy two distinct
cache entries (`withAnswer: false` vs `withAnswer: true`), so the submit request is never served a stale,
answer-less response the debounce tick already cached moments earlier, and a later debounce tick for that
same settled query is served the richer, already-fetched `withAnswer: true` entry instead of silently
discarding the answer it does not itself need (`select: (data) => data.groups` narrows it back down for the
groups-only consumer, at zero extra network cost).

## Hooks

```ts
// hooks/search/use-search-aggregate.ts
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { searchKeys } from '@/lib/query/keys';
import type { SearchAggregateResponse } from '@/types/search';

export function useSearchAggregate(query: string, opts: {
  types?: string[]; branchId: number | null; withAnswer: boolean;
}) {
  return useQuery({
    queryKey: searchKeys.aggregate(query, { types: opts.types, branchId: opts.branchId, withAnswer: opts.withAnswer }),
    queryFn: () =>
      apiClient.get<SearchAggregateResponse>('/api/v1/search', {
        params: {
          q: query,
          types: opts.types?.join(','),
          branch_id: opts.branchId,
          per_type: 5,
          include_ai_answer: opts.withAnswer,
        },
      }),
    enabled: query.length >= 2,
    staleTime: 15_000,
    placeholderData: keepPreviousData,
  });
}

// hooks/search/use-search-group.ts — the single-type Tab / "Load more" path
export function useSearchGroup(type: SearchResultType, query: string, filters: Record<string, unknown>, initialData?: SearchResultGroupData) {
  return useInfiniteQuery({
    queryKey: searchKeys.group(type, query, filters),
    queryFn: ({ pageParam }) =>
      apiClient.get(`/api/v1/search/${type}`, { params: { q: query, cursor: pageParam, ...filters } }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.pagination.next_cursor ?? undefined,
    initialData: initialData ? { pages: [{ data: initialData.items, meta: { pagination: { next_cursor: null } } }], pageParams: [null] } : undefined,
    enabled: query.length >= 2,
    staleTime: 15_000,
    placeholderData: keepPreviousData,
  });
}
```

`useSearchGroup`'s `initialData` parameter is exactly the seeding mechanic `SEARCH.md → Interactions & Flows`
names ("`SearchTypeTabs`' click handler seeds `queryClient.setQueryData(...)` as `initialData` before the
query even mounts") — this hook accepts that already-known first page directly as a parameter rather than
re-deriving it, so `SearchTypeTabs`'s own click handler is the one place responsible for reaching into the
aggregate response and passing the right slice down.

## Worked examples beyond `SEARCH.md`'s own

`SEARCH.md → Data & State` already gives the full aggregate response shape for a `q=diyar` query. The four
examples below fill in shapes that document's own single example does not show, using the platform's other
widely-reused running company (**Al-Noor Trading & Contracting W.L.L.**, `company_id: 4821`, Kuwait City HQ
`branch_id: 1` — the same company `NAVIGATION_SYSTEM.md`'s and `ACCOUNTING_SCREEN.md`'s own worked examples
use) so this document's fixtures interoperate with theirs in a shared test/demo dataset.

**Single-type page, cursor-paginated** (`SearchTypeTabs` → "Invoices" tab, "Load more"):

```json
GET /api/v1/search/invoices?q=nbk&cursor=eyJpZCI6NTUxMDF9&per_page=5
X-Company-Id: 4821

{
  "success": true,
  "data": {
    "type": "invoices", "label": "Invoices", "permission": "sales.read", "total": 14,
    "items": [
      { "id": 55118, "type": "invoice", "reference": "INV-2026-04471", "amount": "2840.0000",
        "currency_code": "KWD", "status": "posted", "customer_name": "NBK Capital Facilities",
        "href": "/sales/invoices/55118", "matched_by": "text" }
    ]
  },
  "message": "OK", "errors": [],
  "meta": { "pagination": { "page": null, "per_page": 5, "total": 14, "cursor": "eyJpZCI6NTUxMTh9" } },
  "request_id": "5a12e3f0-9c44-4b21-8e77-1d0a3c6f8b90", "timestamp": "2026-07-18T09:41:02Z"
}
```

**Documents group, two rows resolved under two different owning permissions** — the concrete case `SEARCH.md`
names ("two documents in the same response can belong to records two different permissions gate") but does
not itself show with two rows:

```json
GET /api/v1/search?q=lease&types=documents&per_type=5
X-Company-Id: 4821

{
  "success": true,
  "data": {
    "query": "lease",
    "groups": [
      {
        "type": "documents", "label": "Documents", "permission": null, "total": 2,
        "items": [
          { "id": 90142, "type": "document", "attachable_type": "customer", "attachable_id": 4471,
            "title": "Lease agreement.pdf", "snippet": "…Diyar Real Estate, Unit 14B, term ending…",
            "chunk_id": 553, "relevance": 0.83, "href": "/sales/customers/4471?attachment=90142&highlight=553",
            "matched_by": "semantic" },
          { "id": 90210, "type": "document", "attachable_type": "warehouse", "attachable_id": 12,
            "title": "Al-Ahmadi yard lease renewal.pdf", "snippet": "…lease of the Al-Ahmadi storage yard…",
            "chunk_id": 601, "relevance": 0.77, "href": "/inventory/warehouses/12?attachment=90210&highlight=601",
            "matched_by": "semantic" }
        ]
      }
    ],
    "ai_answer": null
  },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "9d3f21ab-6e40-4c1a-9a2e-0f7c8b4d5e6a", "timestamp": "2026-07-18T09:44:10Z"
}
```

A viewer holding `accounting.customer.read` but not `inventory.warehouses.read` (a Sales Employee, say) would
receive only the first of these two rows — the group's own `total: 1` for that request, not `2` — since each
row's permission is resolved independently server-side before the response is ever assembled; there is no
client-side filtering step here to get wrong.

**Ask AI answer, low confidence** — the honest "no answer" failure mode `# AI Integration` and
`SearchAiAnswerCard` (above) both render with no `ConfidenceBadge` at all:

```json
{
  "success": true,
  "data": {
    "query": "why did margins drop",
    "groups": [ /* … structured groups, unaffected … */ ],
    "ai_answer": {
      "status": "low_confidence", "agent_code": "REPORTING_AGENT", "confidence_score": null,
      "answer_text": null, "citations": [], "generated_at": "2026-07-18T09:47:33Z"
    }
  },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "2c4e6f80-1a3b-4d5e-8f90-6a7b8c9d0e1f", "timestamp": "2026-07-18T09:47:33Z"
}
```

**A maximally narrow custom role** — zero read permission on every searchable entity type, `ai.chat` absent
too (the Edge Case named in both `SEARCH.md` and this document's own `# Route & Access → Roles`):

```json
GET /api/v1/search?q=diyar&per_type=5
X-Company-Id: 4821

{
  "success": true,
  "data": { "query": "diyar", "groups": [], "ai_answer": null },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "7b8c9d0e-2f3a-4b5c-9d6e-1f2a3b4c5d6e", "timestamp": "2026-07-18T09:49:01Z"
}
```

`groups: []` here is not an error and does not render `SearchEmptyState`'s whole-page variant — `# States`
below is explicit that Navigate results (a pure client-side computation over `NAV_TREE`, never part of this
response) and the Ask AI card (once `ai.chat` is held) are what this particular role sees instead; a role
this narrow with `ai.chat` also absent sees Navigate results alone, still never a blank page.

## Mutation strategy

Unlike every sibling screen in this series — `ACCOUNTING_SCREEN.md` documents six mutations with an explicit
optimistic-vs-pessimistic table, `SALES_SCREEN.md` documents five — **this screen performs zero mutations of
its own**, and this document states that explicitly rather than silently omitting the sub-section a reader
might otherwise expect. Every write a user can trigger while on `/search` (accepting an AI recommendation,
posting a journal entry, approving a document) is dispatched through the target record's own existing
mutation hook, imported unchanged from that record's own module (`useAcceptAiSuggestion` from
`hooks/accounting/…`, `useApproveApproval` from `hooks/approvals/…`), never a Search-specific reimplementation
— so there is no new optimistic-update rollback logic, no new `Idempotency-Key` generation, and no new
`onError` handling to specify here beyond what each of those hooks' own owning document already covers. The
one property this screen's own code is responsible for is routing: resolving which existing hook a given
result card's quick action should call, based on that card's `type` discriminant, and rendering nothing at
all when the acting user's permissions do not cover it.

## Realtime

Search holds no persistent Reverb subscription for its structured groups — a point-in-time query is expected
to behave like a search engine, not a live view, matching `SEARCH.md → Realtime`'s explicit statement of this
rule. The one subscription this screen opens is scoped to the Ask AI card alone:

| Channel | Events carried | Effect on this screen |
|---|---|---|
| `private-company.{id}.ai-jobs` | The orchestrator's own "thinking"/"ready" status for the in-flight `ai_answer` computation | Drives `SearchAiAnswerCard`'s `isLoading` prop while `include_ai_answer=true` is in flight; unsubscribed the instant the answer resolves or the user navigates away from `/search` |

This is the identical channel every other Ask AI surface in the product subscribes to (`AI_COMMAND_CENTER.md`,
`DASHBOARD.md`'s own AI Summary Rail) — Search opens no channel of its own that does not already exist
elsewhere in the platform's realtime contract.

# Interactions & Flows

Numbered as a concrete implementation sequence, matching the convention `ACCOUNTING_SCREEN.md → Interactions
& Flows` uses; the narrative form of each flow (why the palette hands off the way it does, why a citation
never re-derives its target) is `SEARCH.md`'s own territory and is not repeated here.

1. **Opening the palette and typing.** Unchanged from `NAVIGATION_SYSTEM.md`. This document's one addition —
   the persistent "View all N results for '{q}' →" row — is wired as one more `CommandItem` appended after
   the existing "AI & Actions" group in `components/layout/command-palette.tsx`:

   ```tsx
   {(navResults.length > 0 || sources.some((_, i) => (recordQueries[i]?.data?.data ?? []).length > 0)) && (
     <CommandItem onSelect={() => { setOpen(false); router.push(`/search?q=${encodeURIComponent(query)}`); }}>
       {t('viewAllResults', { count: totalResultCount, query })} →
     </CommandItem>
   )}
   ```

   `query`, not `debounced`, is pushed — the literal, un-debounced current input value — so a character typed
   after the last resolved fan-out is never lost on handoff.

2. **Landing on `/search` directly.** `page.tsx` reads `searchParams.q` server-side and passes it as
   `GlobalSearchInput`'s `initialQuery`; with `q.length < 2` the Results column and Ask AI rail are replaced
   entirely by `RecentSearches`/`SuggestedSearches` (`# Layout & Regions`), and no request to `GET /api/v1/
   search` fires at all — Search never runs a query the user did not ask for.

3. **Every keystroke past 2 characters** re-keys `useSearchAggregate`'s query with `withAnswer: false`,
   cancelled and replaced by TanStack Query's own built-in `AbortController` per query on the next keystroke
   — a fast clear-then-retype never races an earlier, now-irrelevant response back into the UI, and
   `placeholderData: keepPreviousData` keeps the prior result set visible, dimmed, through the gap.

4. **Settling for 300ms, or pressing Enter,** promotes the request to `withAnswer: true` (`# Data & State`)
   and writes `?q=` to the URL via `router.push(..., { scroll: false })` — the `{ scroll: false }` option is
   deliberate: a submit while the user is mid-scroll through a long Results column must not snap them back
   to the top of the page merely because the URL changed underneath them.

5. **Switching a Type Tab** writes `?type=` to the URL and calls `useSearchGroup` for that one type, seeded
   with `initialData` from the aggregate response's own matching group (`# Data & State`) so the first page
   never re-fetches data already sitting in the just-resolved aggregate payload; only a second page ("Load
   more") or a facet change triggers a genuinely new request.

6. **Opening `SearchFacetsPanel`** and picking a facet renders the exact `filter[...]` query-string shape
   `API_FILTERING_SORTING.md` defines for that resource — the resulting `seeAllHref` a `SearchResultGroup`
   builds (`{module route}?q={q}&{same filter params}`) is a zero-translation deep link precisely because
   both ends speak the identical grammar.

7. **Clicking a result card** is an ordinary `<Link>` navigation to the record's own canonical route — never
   an inline drawer. A Documents result additionally carries `?attachment={id}&highlight={chunk_id}`, landing
   the destination's Attachments tab pre-scrolled to and highlighting the exact cited passage.

8. **Clicking an Ask AI citation** calls `focusOrNavigate` (`# Components Used → SearchAiAnswerCard`): if the
   cited result's card is already on-screen (found via its `data-result-href` attribute), the page scrolls to
   and briefly rings it; otherwise the browser navigates to the citation's `href` directly. Neither path ever
   re-fetches the citation's data — the `href` alone is authoritative.

9. **Opening the follow-up composer** dynamically imports `SearchAiFollowUpComposer` (`next/dynamic`,
   `ssr: false`) pre-seeded with `{ surface: 'search', query, result_ids }` — no re-computation of retrieval
   context already captured for the current query.

10. **Using a result card's quick action** calls the identical, permission-gated mutation hook the record's
    own screen imports — see `# Data & State → Mutation strategy`. A sensitive action (`bank.transfer`,
    `payroll.approve`, `tax.submit`) still always terminates at that record's own full page or the Approval
    Center, never inline on a card, matching the platform-wide "always full-page, never a modal" list.

11. **Selecting a `RecentSearches` or `SuggestedSearches` chip** is a plain `<Link href="/search?q=...">` —
    behaviorally identical to typing the same text and pressing Enter, never a hidden shortcut code path.

# AI Integration

Search's AI surface has the same two structurally distinct halves `SEARCH.md → AI Integration` establishes —
ranking-time (silent) and answer-time (visible) — and this section restates them only as far as the concrete
numeric gates an implementer checks a screenshot or a unit test against, the same convention
`ACCOUNTING_SCREEN.md → AI Integration` uses for its own Pending AI queue.

| Gate | Value | Effect on this screen |
|---|---|---|
| Confidence normalization for `SearchAiAnswer.confidence_score` | `0–100`, use `normalizeConfidence(raw, 'percentage')` | Matches `ai_decisions.confidence_score`'s own scale, **not** the `0.0000–1.0000` scale `journal_lines.ai_confidence`/`accounts.ai_suggestion_confidence` use elsewhere in the platform — passing a Search confidence value through `normalizeConfidence(raw, 'fraction')` by mistake is the single most likely implementation bug to guard against in review, exactly the caution `ACCOUNTING_SCREEN.md` raises for its own two-scale screen |
| `ConfidenceBadge` band thresholds | `≥0.85` high, `≥0.6` medium, `<0.6` low | Applied identically here — `SearchAiAnswerCard` never renders its own qualitative bands |
| `SearchAiAnswer.status !== 'ready'` | `'low_confidence'` or `'unavailable'` | No `ConfidenceBadge` renders at all — a structural, not a threshold-crossing, difference from every low-but-shown score elsewhere in the platform |
| `recommended_action` field on `SearchAiAnswer` | **Never present** — not optional, structurally absent from the type | No "Do it" / "Send for approval" affordance can ever render on this card, at any confidence; where the prose references an actionable item, its citation links to that item's own screen instead |
| Client-side `canAutoExecute` mirror (`AIProposalPanel`'s own constant) | Not applicable to this card | `SearchAiAnswerCard` does not import `AIProposalPanel` at all — the three-button pattern is a property of *recommendations*, and `SearchAiAnswer` is a synthesis, not a recommendation |
| A result card's own quick action | Governed entirely by that record's own `usePermission` check | Never gated or ungated by anything the Ask AI card says — the two are fully independent, per `SEARCH.md`'s "Search's AI layer can help a user find and understand a record; it never becomes a second, parallel path to changing it" |

The AI service-account identity backing the ranking-time layer (embedding maintenance for
`products.embedding` and the document-chunk table, `pg_trgm` tuning behind Customers/Vendors fuzzy matching)
is capped at the infrastructure layer to read-only, suggestion-producing access exactly as `PRODUCTS.md §
15.1`-equivalent scoping already establishes for every other semantic-search-eligible column — it cannot
reach any endpoint this screen's own quick actions call, at any confidence, under any company automation
policy. Every acceptance a human makes from a card this screen renders — clicking through to post a journal
entry, approving a document — calls the exact endpoint a human typing the same change by hand would call,
authenticated as that human, under that human's own session.

# States

Every region carries its own independent loading, empty, and error presentation, matching `DASHBOARD.md`'s
per-region independence rule that every hub-shaped screen in this series reuses.

| Region | Loading | Empty | Error |
|---|---|---|---|
| `GlobalSearchInput`, pre-2-char | n/a | No fan-out fires; the input simply shows no results yet | n/a |
| Aggregate results, 2+ chars in flight | `WidgetSkeleton variant="search-results"` — 2–3 skeleton `SearchResultGroup` shells, fading in as each group's real data resolves | n/a at this stage | Whole-page `ErrorBoundary` only for a genuine `5xx`/network failure on the aggregate call itself; a single group's own permission-driven absence never renders here — see `# Edge Cases` |
| A single `SearchResultGroup` | Skeleton rows matching that group's real card height | `SearchEmptyState scope="group"` — "No {type} match '{q}'" | Small inline "Couldn't load {type} results — Retry," isolated per group |
| Whole-page zero results | n/a | `SearchEmptyState scope="page"` + `SuggestedSearches` | Same whole-page `ErrorBoundary` |
| Documents/RAG group | Two-line skeleton matching a real chunk-preview's height | "No matching documents" — distinct from "no attachments exist," a distinction this group does not attempt to make | Same per-group retry card; a chunk-embedding-service outage degrades this one group only |
| `SearchAiAnswerCard` | Three pulsing dots, `role="status" aria-busy="true"` | An unsubmitted, still-typing query shows nothing (the card is simply absent until submit) | `status: "unavailable"` renders `DASHBOARD.md`'s identical AI-engine-down copy; structured groups beside it are unaffected |
| `RecentSearches` | Renders instantly from `localStorage`, no loading state at all | A brand-new account with no history shows nothing here — `SuggestedSearches` alone occupies the empty-query landing | n/a — no network dependency |
| `SuggestedSearches` | 3 skeleton chips, `h-8 w-32` | Fails silently to an empty row — a convenience, not core functionality, per `# Components Used` | Same silent-empty treatment as its own empty state; no retry card for a non-essential feature |
| Single-type Tab ("Load more") | Trailing `Skeleton` rows appended below the existing list | `SearchEmptyState scope="group"` if the type's own filter/facet combination matches nothing | Inline "Couldn't load more — Retry" at the list's own end, the existing rows above it undisturbed |

A route-level `error.tsx` remains the outermost safety net for a genuinely unexpected failure (the session
itself becoming invalid mid-render); per the platform's three-granularity error model this should essentially
never fire for an individual region's ordinary `4xx`/`5xx` — those are caught and handled inline, one region
at a time, exactly as `FRONTEND_ARCHITECTURE.md → Boundaries at three granularities` and every sibling screen
document in this series establish.

# Responsive Behavior

Breakpoints are `LAYOUT_SYSTEM.md`'s fixed scale (`base` <640px, `sm` 640px, `md` 768px, `lg` 1024px, `xl`
1280px, `2xl` 1536px, `3xl` 1920px) — this screen introduces no breakpoint of its own.

| Token | This screen's behavior |
|---|---|
| `base`–`sm` | `GlobalSearchInput` does **not** auto-focus on landing (see the component's own `isTouch` guard) — auto-focusing would force-open the on-screen keyboard and immediately cover the results the user navigated here to see. `SearchTypeTabs` becomes a horizontal-scroll chip row, no wrap. `SearchFacetsPanel` opens as a bottom `Sheet` (`RESPONSIVE_DESIGN.md` Pattern 4). The Ask AI rail renders as a collapsible, dismissible card stacked *above* the Results column, collapsed by default to a one-line "✦ Ask AI has an answer — tap to expand" affordance so it never pushes structured results below the fold on a 375px screen. |
| `md` (768px) | Two Type Tabs' worth of chips fit before scrolling begins; the Ask AI card is still stacked above results but no longer collapsed by default. |
| `lg` (1024px) | The 8/4 Results-column/Ask-AI-rail split activates (`# Layout & Regions`); `SearchFacetsPanel` moves from a `Sheet` to a `Popover`. |
| `xl`+ (1280px) | Same 8/4 layout, generous margins; the platform-wide `360px` AI Rail companion panel may additionally dock per `LAYOUT_SYSTEM.md`'s App Shell, entirely independent of this page's own Ask AI rail column — the same non-competing relationship `DASHBOARD.md` documents between its own AI Summary Rail and the global AI Rail. |
| `3xl` (1920px) | Container ceiling reached (`--container-max-wide`, 1600px for this full-bleed template); extra width becomes margin, not additional columns. |

`SearchResultCard` and `SearchResultGroup` use Tailwind v4 container queries (`@container`), not only
viewport breakpoints, matching `KpiTile`'s and `ModuleNavCard`'s identical convention elsewhere in this
document series — the same card renders correctly whether it is one-of-five in a full desktop group or one-
of-three inside a narrower mobile stack. Touch targets on every result card, quick-action button, and Type
Tab meet the platform's 44×44px minimum below `md`, implemented once in the shared `Button`/`IconButton`
components rather than per-instance here.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md` and `LAYOUT_SYSTEM.md`'s RTL contract and applied
concretely to this screen's own components — `SEARCH.md → RTL & Localization` states the identical rules;
this section is their component-level application.

- **Logical properties only.** `GlobalSearchInput`'s clear-button placement, `SearchTypeTabs`' tab order,
  `SearchResultCard`'s icon-to-text gap, and the Ask AI rail's column position (`order-first`/`order-last`,
  which itself flips meaning under `dir="rtl"` since CSS `order` is direction-aware) all use `ms-*`/`me-*`
  exclusively — zero screen-specific RTL code.
- **The search input never forces a script-specific `dir`.** `GlobalSearchInput`'s `<input>` carries no
  `dir` attribute of its own, letting the browser's bidi algorithm handle a mixed-script query (an Arabic-
  locale session typing a Latin-script SKU) naturally, per `SEARCH.md`'s identical rule.
- **Numerals, amounts, and citation markers never mirror.** Every `AmountCell` inside `SearchResultCard` and
  every `[n]` citation marker inside `CitedAnswer` renders with `dir="ltr"` — visible directly in this
  document's own `SearchAiAnswerCard` source above (`dir="ltr"` on the citation `<a>`).
- **Bilingual names are the default.** `displayTitle`/`displaySubtitle` in `SearchResultCard` read whichever
  of `name_en`/`name_ar` (or `display_name`, already locale-resolved server-side) matches the active locale —
  never a language toggle a user must operate.

| Context | English | Arabic |
|---|---|---|
| Search input placeholder | Search or jump to… | ابحث أو انتقل إلى… |
| Clear button | Clear search | مسح البحث |
| Type Tab: All | All | الكل |
| "Matched by meaning" chip | Matched by meaning | مطابقة بالمعنى |
| Recent Searches heading | Recent searches | عمليات البحث الأخيرة |
| Suggested for you | Suggested for you | مقترح لك |
| Ask a follow-up | Ask a follow-up | اسأل سؤالاً إضافياً |
| Ask AI, unavailable | AI insights are temporarily unavailable. | ميزات الذكاء الاصطناعي غير متاحة مؤقتاً. |

Arabic copy on this screen is authored directly by a fluent professional-register writer, never machine-
translated from the English column above, matching `SEARCH.md`'s own stated voice discipline and reusing the
exact terminology `SEARCH.md`'s own RTL table already established (مطابقة بالمعنى for "Matched by meaning")
rather than a second, independently-translated variant of the same phrase.

# Dark Mode

This screen introduces no new color, elevation, or radius token — every surface resolves through the
platform's `:root[data-theme="dark"]` remap `DESIGN_LANGUAGE.md`/`COMPONENT_LIBRARY.md` define once. The
concrete pairs this screen's own components read most often, reproduced from `ACCOUNTING_SCREEN.md`'s own
Dark Mode table for direct cross-screen consistency:

| Token | Light | Dark | Used on this screen for |
|---|---|---|---|
| `--qayd-ink-1` / `--qayd-ink-12` | `#FAFAF9` / `#15130E` | `#14130F` / `#F8F6EF` | Canvas / primary text throughout every result card and the Ask AI rail |
| `--qayd-ink-3` / `--qayd-ink-6` | `#EBE9E6` / `#C2BEB6` | `#24211A` / `#46402F` | `SearchResultCard` hover state / border; `TreeSkeleton`-style shimmer rows |
| `--qayd-accent` | `#9C7A34` | `#D9B96C` | Citation markers' background tint, the one primary Search action (none on this screen — Search has no primary-action button, only the input itself) |
| `--qayd-accent-subtle` | `#EADFBF` | `#3A2E14` | Citation `<a>` background (`bg-accent-subtle` in `CitedAnswer`'s source above) |

**`surface-glass` is reserved for exactly two places platform-wide — the Command Palette and the AI Command
Center overlay** (`DESIGN_LANGUAGE.md`'s explicit "only"). The ⌘K palette therefore renders on `surface-glass`
in both themes, unchanged by this document. `/search`'s own result cards, Type Tabs, and Ask AI rail are
ordinary `surface-1` `Card`s with a 1px hairline border — never glass — for the identical reason `SEARCH.md`
states: a full page built from frosted panels loses the effect precisely because glass is meant to be rare,
and costs a real `backdrop-blur` performance hit this document's own `# Performance` section is responsible
for not incurring needlessly. Skeleton shimmer uses the identical `ink-3 → ink-4 → ink-3` sweep at the
platform's standard 1.6s cadence, so this screen's loading rhythm is indistinguishable from any other List
Page Template's.

Every Storybook story for the seven components given full source above ships the platform's standard four-
way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this route's own Playwright suite
captures the identical four-way screenshot set at the route level, per `FRONTEND_ARCHITECTURE.md`'s testing
convention.

# Accessibility

Search targets **WCAG 2.2 AA** as a floor — `SEARCH.md`'s own stated target for this exact surface, adopted
here without amendment (some earlier documents in this series predate 2.2 and state 2.1; where the two
targets differ this document follows `SEARCH.md`'s, since a floor can only ever be raised, never lowered, by
a more specific sibling document).

- **The palette's ARIA contract is entirely unchanged.** `role="dialog"` + `aria-modal="true"` + a
  `role="combobox"` input (`aria-expanded`, `aria-controls`, `aria-activedescendant`) + a `role="listbox"`
  results list (`role="option"` rows) — this document's one addition, the "View all N results" row, is simply
  one more `role="option"` at the end of the flattened list.
- **`GlobalSearchInput` follows the identical `combobox` pattern**, `aria-controls="search-results-region"`
  pointing at the Results column instead of a `CommandList` — a screen-reader user who has learned the
  palette's behavior finds `/search`'s own input behaves identically.
- **Real landmarks, not visual-only structure.** `role="search"` (or the native `<search>` element once
  universally supported) wraps `GlobalSearchInput` and `SearchTypeTabs`; every `SearchResultGroup` is a
  `<section aria-labelledby>` with a visually-present `<h2>` (this screen's headings are visible, not
  visually-hidden, since the group label is also useful sighted content — unlike `DASHBOARD.md`'s Chart
  Region, which hides an otherwise-redundant heading).
- **Live regions, calibrated to avoid noise.** Per-group counts settling in as independent requests resolve
  announce via `aria-live="polite"`, batched once per group, never once per row. `SearchAiAnswerCard`'s
  `role="status" aria-busy="true"` (visible in this document's own component source) announces once when
  generation begins and once on completion — never token-by-token.
- **Keyboard path, start to finish.** `⌘K` opens the palette from anywhere, including from `/search` itself.
  On `/search`: `Tab` moves `GlobalSearchInput` → `SearchTypeTabs` (roving tabindex within the tab list, the
  Radix `Tabs` primitive's own contract) → the Facets trigger → each `SearchResultGroup`'s cards in DOM order
  → that group's "See all" link → the Ask AI card's citation links → its follow-up composer. No control on
  this screen requires a mouse.
- **Color is never the only channel.** The "Matched by meaning" chip pairs text with (optionally) a glyph;
  `ConfidenceBadge`'s qualitative band is always paired with the numeric score and a label; `StatusPill`
  reuses each domain's own existing text-plus-tone pairing.
- **Permission-aware behavior stays legible.** A result group a role cannot read is omitted from Type Tabs
  entirely (existence-sensitive, matching `NAVIGATION_SYSTEM.md`'s identical precedent); a quick action a role
  cannot perform on a visible result card is omitted, never shown disabled-and-unexplained, inheriting
  whichever rule the target record's own document specifies for that exact action.
- **Focus management on navigating away.** Clicking any result card, citation, or "See all" link is a real
  route change; focus lands on the destination page's own heading via the platform's standard route-change
  focus-reset — this screen introduces no bespoke focus-trap logic beyond what the palette's `Dialog` and the
  mobile Facets `Sheet` already provide as Radix primitives.

# Performance

- **The aggregate endpoint exists specifically to avoid a client-side waterfall** landing on `/search` —
  one request, not eight-to-twelve independent per-module calls, matching `SEARCH.md`'s own stated rationale.
- **Streamed, not blocking, per region.** `page.tsx` (`# Layout & Regions`) wraps the Results column and the
  Ask AI rail in independent `<Suspense>` boundaries — a slow Documents/RAG group (chunk-level vector search
  is the single most expensive query shape on this screen) never delays Customers or Invoices from painting.
- **Debounced, cancellable, stable placeholders.** `GlobalSearchInput` debounces at 200ms for the structural
  aggregate call, matching the palette's own tuning, and every `SearchFacetsPanel` control at 300ms, matching
  `FRONTEND_ARCHITECTURE.md`'s platform-wide debounced-input convention; every query uses
  `placeholderData: keepPreviousData`.
- **The Ask AI answer never fires per keystroke** — only on submit/settle, via the `include_ai_answer` flag
  this document introduces (`# Data & State`), avoiding the wasteful (and, for a paid-per-token backend,
  literally costly) pattern of calling an LLM for "d", "di", "diy", "diya" in immediate succession.
- **`SearchAiFollowUpComposer` is code-split** via `next/dynamic({ ssr: false })` (visible in
  `SearchAiAnswerCard`'s own source above) — a role without `ai.chat` never downloads the Vercel AI SDK's
  `useChat` bundle at all.
- **Virtualization threshold matches the platform default.** A single-type Tab's "Load more" list adopts
  `@tanstack/react-virtual` once it exceeds roughly 200 rows, the same threshold `DESIGN_LANGUAGE.md`'s Data
  Density section sets for General Ledger and Journal Entries history.
- **Web Vitals tracked against a realistic baseline.** LCP for `/search` is measured against the first non-
  empty result group's paint (or the empty-query Recent/Suggested state's paint, whichever the landing
  actually is), tagged by company-size band exactly as `DASHBOARD.md` specifies platform-wide.

# Edge Cases

`SEARCH.md → Edge Cases` already covers the platform-level scenarios (cross-tenant enumeration, a mid-session
permission revocation, a query shorter than 2 characters, a semantic-only match, the AI engine down, two
tabs running different searches, a citation surviving a subsequent facet change, a common-word query
returning hundreds of matches, a bulk-mutation race against a captured retrieval context, offline search) —
this document does not repeat them. The rows below are this implementation layer's own additions: races and
environment conditions that surface specifically once `page.tsx`, its hooks, and its component wiring are
actually written.

| Edge case | Frontend behavior |
|---|---|
| The Ask AI SSE stream (`POST /api/v1/ai/chat`, inside `SearchAiFollowUpComposer`) disconnects mid-response | The Vercel AI SDK's `useChat` surfaces its own `error` state; the composer renders the partial message already streamed plus an inline "Connection lost — Retry" affordance, never silently truncating the visible text without explanation, and never re-submitting the original question automatically (mutations/generations are never auto-retried, per `FRONTEND_ARCHITECTURE.md`'s identical rule for financial mutations, applied here to a paid-per-token generation). |
| The user presses the browser Back button immediately after switching a Type Tab | Since the Tab switch wrote `?type=` via `router.push`, Back restores the previous `?type=` (or its absence) from history; `useSearchGroup`'s query key changes back with it, and the previously-cached page for that state (still within its 15s `staleTime`) renders instantly with no network request, rather than Back appearing to "lose" the prior view. |
| A `?q=` value contains characters that are valid in a URL only when percent-encoded (`&`, `#`, emoji) | `GlobalSearchInput`'s `handleSubmit` always writes via `URLSearchParams.set`, which encodes correctly; a manually-typed or hand-edited URL with a raw, unencoded `&` is parsed by Next.js's own `searchParams` before this component ever sees it, so the component itself never needs a defensive decode step. |
| A user opens the Facets `Sheet` on mobile, then rotates the device or resizes past the `lg` breakpoint while it is open | `ResponsiveOverlay`'s (`RESPONSIVE_DESIGN.md` Pattern 4) `useBreakpoint()` re-evaluates on resize; the open `Sheet` is not force-closed or teleported mid-interaction — it remains a `Sheet` until closed, and the *next* time Facets is opened after the resize, it opens as a `Popover` instead. |
| A citation's `href` points at a record that was deleted (soft-deleted, per the platform's never-hard-delete rule) between the Ask AI answer being generated and the user clicking `[n]` | The destination route's own server-side fetch for a soft-deleted record follows that record's own document's stated behavior (typically a `404`-style "no longer available" state, since accounting records are never hard-deleted but can be voided/archived) — Search does not attempt to detect or pre-empt this client-side; the citation still navigates, and the destination screen's own existing handling applies unchanged. |
| The active company is switched (via the Topbar switcher) while `/search` is open with results rendered | Company switching is the platform's one genuinely session-level, server-confirmed operation (`NAVIGATION_SYSTEM.md → Company switch`); it triggers a full reload, and every `searchKeys.*`-cached entry for the previous company is discarded along with it — there is no partial, client-side "re-scope search results to the new company" path to get wrong, because none is attempted. |
| An ad-blocker or strict privacy extension blocks the request to `/api/v1/search` specifically (some block list entries target the literal substring `search`) | This surfaces as an ordinary network-level failure, indistinguishable from any other connectivity failure — the same whole-page `ErrorBoundary` and Retry affordance in `# States` applies; this document does not special-case ad-blocker detection, consistent with the platform generally not fingerprinting the client's extensions. |
| The `withAnswer: true` request and a subsequent `withAnswer: false` debounce tick for the *same* settled query race each other (the user resumes typing a trailing space immediately after Enter) | The two occupy distinct cache keys (`# Data & State`), so neither overwrites the other; the Ask AI rail continues showing the `withAnswer: true` response's answer undisturbed while the structured groups re-render from whichever of the two resolves most recently — a benign race, since both requests return the identical `groups[]` content for an unchanged query string. |
| A screen reader user submits a query via Enter while `GlobalSearchInput`'s `aria-activedescendant`-style focus state (inherited from the combobox pattern) is mid-transition | `handleSubmit`'s `preventDefault` fires on the form's own `onSubmit`, not on a raw keydown listener racing the combobox's internal state machine, so the submission is never lost to a focus-timing edge case the way a hand-rolled `keydown === 'Enter'` handler elsewhere in the tree might be. |

# End of Document
