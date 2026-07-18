# Global Search — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: SEARCH
---

# Purpose

Global Search is QAYD's single answer to "where is it" and "what does the system know about it" — the
one capability that cuts across all ten primary-nav modules (`NAVIGATION_SYSTEM.md`'s module map:
Accounting, Banking, Sales, Purchasing, Inventory, Payroll, Tax, Reports, AI) instead of living inside
any one of them. It ships as two coordinated surfaces sharing one backend contract and one ranking
model: the **⌘K Command Palette**, a transient overlay reachable from any screen, and **`/search`**,
a dedicated, permission-transparent, directly linkable results page that is one of the five primary
destinations in the mobile bottom tab bar (`Dashboard, Accounting, Banking, Search, More` —
`LAYOUT_SYSTEM.md`'s mobile wireframe and `RESPONSIVE_DESIGN.md`'s Pattern 3 both name it explicitly).
A user reaches for the palette to jump somewhere fast without leaving the page they are on; a user
reaches for `/search` to actually *browse* what the system knows about "Diyar," "VAT," or "the July
bank reconciliation" — a heavier, more deliberate act that deserves its own scrollable page, its own
URL, and its own AI-synthesized answer, not a seven-row overlay.

This document is the binding specification for both surfaces together and is deliberately downstream
of, and consistent with, four sibling documents rather than a re-statement of them. `NAVIGATION_SYSTEM.md`
already establishes the Command Palette's shell mechanics, its keybinding, its three-group anatomy
(Navigate, Records, AI & Actions), its ranking tie-break rule, and its "AI actions are handoffs, never
executions" posture — this document does not re-derive any of that; it treats the palette's existing
behavior as fixed and extends it with exactly one addition (`# Interactions & Flows`, the "View all
results" handoff row) and reconciles it with `/search`'s own, richer grouping and ranking contract,
which the two surfaces now share. `LAYOUT_SYSTEM.md` owns the palette's shell dimensions (`Dialog`
portalled at `z-(--z-command-palette)`, `surface-glass` elevation) and the mobile bottom-tab-bar
contract this document's `/search` route fulfills. `COMPONENT_LIBRARY.md` owns every primitive and
finance component this document composes — `Command`, `Tabs`, `AmountCell`, `ConfidenceBadge`,
`AiCardShell`, `DataTable` — and this document introduces no new design token, no new shadcn primitive,
and no new permission-key grammar. `ACCESSIBILITY.md` owns the palette's exact ARIA pattern (`role="dialog"`
+ `role="combobox"` + `role="listbox"`/`role="option"`, per its `# Keyboard Navigation → Command Palette`
section) — this document reuses that pattern verbatim and extends it to `/search`'s own results region.

Two small, pre-existing cross-document inconsistencies are worth naming rather than silently picking a
side, the same way `DASHBOARD.md` reconciled its own stale cross-references. First, `ACCESSIBILITY.md`'s
own palette excerpt labels its groups "Navigate / Actions / Ask AI / Recent" where `NAVIGATION_SYSTEM.md`'s
fuller, code-backed specification labels them "Navigate / Records / AI & Actions" and adds an unlabeled
"Recent" slot only for an *empty* query. This document treats `NAVIGATION_SYSTEM.md`'s three-plus-one
anatomy as authoritative for *what the groups are and when they appear*, and `ACCESSIBILITY.md` as
authoritative for *the ARIA roles wrapping them* — the two are describing the same component from two
different angles, not two different components. Second, `RESPONSIVE_DESIGN.md`'s `TopAppBar` reflow
states that below `lg:` "the search input collapses to an icon that expands into a full-screen search
overlay on tap," which sits alongside `LAYOUT_SYSTEM.md`'s separate bottom-tab "Search" destination.
This document resolves the apparent overlap explicitly in `# Responsive Behavior`: the collapsed
top-bar icon opens the same transient ⌘K-equivalent overlay every breakpoint has, while the bottom
tab's "Search" destination navigates to the persistent `/search` page — one is a quick jump-to, the
other is a place you can stay and browse, and a phone legitimately offers both.

The architectural fact every section below is built on: QAYD's search is a **federated fan-out over
each module's own existing search logic**, not a unified external index. `TECH_STACK.md` lists
Meilisearch/ElasticSearch under `# Search` as a named **Phase 2** item, and `NAVIGATION_SYSTEM.md`'s own
Command Palette section states this outright — "Records search is a fan-out, not a unified index... a
consolidated search layer (Meilisearch) is a Phase 2 item." Concretely, that means every result this
screen shows is produced by the same PostgreSQL machinery its owning module already ships: `tsvector`/GIN
full-text plus `pg_trgm` fuzzy matching for exact-ish text (`GENERAL_LEDGER.md`, `JOURNAL_ENTRIES.md`,
`DATABASE_INDEXING.md`), and `pgvector`/HNSW cosine-similarity embeddings for semantic fallback where a
module has one (`products.embedding`, per `PRODUCTS.md`'s **Smart Search** agent — "Document AI /
general retrieval layer... fully automated, read-only"). This document's frontend contract is built so
that swapping the backend's internal fan-out for a real unified index later is purely a `# Data & State`
change — the aggregate envelope shape, the grouping model, and every component below stay identical.

Three platform-wide rules bind this screen precisely because Global Search is where a violation of any
one of them would be most damaging and most visible. First, **every result is permission-filtered at
its source, never hidden client-side** — a Sales Employee's search for "payroll" must produce zero
`Payroll` rows from the server itself, not a client-side filter over a response that already contained
them, per `PERMISSION_SYSTEM.md`'s "nobody has unlimited access... never assumed" and the identical rule
`NAVIGATION_SYSTEM.md`'s palette already enforces ("the request is skipped client-side as a courtesy,
and would 403 regardless if it were not"). Second, **AI participates as a retrieval and synthesis layer
that proposes an answer, never as ground truth** — the "Ask AI about this" card on `/search` is exactly
as humble, exactly as confidence-scored, and exactly as citation-bearing as any other AI-authored surface
in the product (`DESIGN_LANGUAGE.md` Principle 7: "the AI stays humble in the UI, exactly as it stays
humble in the API"). Third, **Search never mutates anything** — every quick action reachable from a
result card is the identical, permission-gated, already-governed action the record's own screen exposes;
this screen introduces no parallel approve/post/reconcile path and no new write endpoint of any kind.

# Route & Access

| | |
|---|---|
| Route (results page) | `app/(app)/search/page.tsx` (Server Component shell) + `app/(app)/search/loading.tsx` (route-level skeleton) |
| Route (overlay) | No route of its own — `components/layout/command-palette.tsx`, mounted once in `app/(app)/layout.tsx` and rendered via portal at `z-(--z-command-palette)`, exactly as `NAVIGATION_SYSTEM.md` and `LAYOUT_SYSTEM.md` specify |
| Nav entry points | Desktop/tablet: the Topbar's `CommandPaletteTrigger` pill (`⌘K`); mobile: the bottom tab bar's **Search** destination (position 4 of 5, `Search` icon, per `LAYOUT_SYSTEM.md`'s mobile wireframe and `RESPONSIVE_DESIGN.md` Pattern 3) navigates straight to `/search`, and the collapsed Topbar search icon below `lg:` opens the palette overlay (`RESPONSIVE_DESIGN.md → TopAppBar adaptation`) |
| Gating permission | **None.** Search carries no single visibility permission, matching `DASHBOARD.md`'s "no single gating permission" precedent — every authenticated member of a company can open `/search` or the palette. Each result group's own fan-out call enforces its own permission independently server-side; a role with zero readable entity types still gets a working, non-empty Search (Navigate results, and — if held — Ask AI), never a "you don't have access" wall. |
| Breadcrumb | `Search` alone (non-linked, IA-root sibling to `Dashboard`) when `?q=` is absent; `Search results for "{q}"` once a query is present — the page title updates to match, per the platform's document-title live-region convention (`ACCESSIBILITY.md → Route changes`) |
| Company/branch scope | Fixed to the active `X-Company-Id`. Branch is an optional, URL-mirrored filter (`?branch={id}`), applied only to the entity types that are themselves branch-scoped (Accounts, Products, Bills, Invoices) and silently ignored by company-wide types (Knowledge, Approvals, Navigate) — mirroring `NAVIGATION_SYSTEM.md`'s own branch-switch convention rather than inventing a second one |
| URL contract | `/search?q=<string>&type=<csv>&branch=<id>&<facet params>` — every part of a search is a shareable link, including which tab is active and which facets are applied, per `# Interactions & Flows` |

Because no single permission gates the route, `/search` renders a structurally different set of groups
per role, exactly as `NAVIGATION_SYSTEM.md`'s Command Palette already does for its own Records group:

| Result group | Permission enforced server-side | Typical roles who see it |
|---|---|---|
| Navigate (pages) | Per-item, from the same permission-filtered `NAV_TREE` the Sidebar and palette already use | Whoever holds each target page's own visibility permission |
| Accounts | `accounting.accounts.read` | Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Auditor |
| Journal Entries | `accounting.journal.read` | Same as Accounts, plus anyone with narrower journal-only access |
| Customers | `accounting.customer.read` | Owner, CEO, CFO, Finance Manager, Sales Manager, Sales Employee, Accountant |
| Vendors | `accounting.vendor.read` | Owner, CEO, CFO, Finance Manager, Purchasing Manager, Purchasing Employee, Accountant |
| Products | `products.read` | Owner, CEO, Finance Manager, Inventory Manager, Sales/Purchasing roles |
| Invoices | `sales.read` | Owner, CEO, CFO, Finance Manager, Sales Manager, Sales Employee, Accountant |
| Bills | `purchasing.bill.read` | Owner, CEO, CFO, Finance Manager, Purchasing Manager, Purchasing Employee, Accountant |
| Documents (attachments, RAG) | The owning record's own attachable permission, resolved per row — see `# Data & State` | Whoever can read the record the document is attached to |
| Knowledge (AI insights/recommendations/risks) | `reports.read` | Owner, CEO, CFO, Finance Manager, Senior Accountant |
| Approvals | Assigned approver on the row, or `ai.approve` broadly | Whoever is a named approver on at least one pending item |
| Ask AI answer | `ai.chat` (composing/asking), plus the citation's own permission (a citation the caller cannot read is never rendered — see `# AI Integration`) | Any role holding `ai.chat` |

No control on this screen is ever rendered disabled-and-unexplained: a result group a role cannot read
is omitted from both the palette and `/search`'s Type Tabs entirely — its *existence* is not itself
sensitive (every company has a Payroll module), but showing an empty, permission-denied "Payroll (0)"
tab to a Sales Employee is exactly the kind of noise `COMPONENT_LIBRARY.md`'s row-action precedent
already rejects, applied here to a whole tab rather than a single button.

# Layout & Regions

## The Command Palette (overlay — shell owned by `NAVIGATION_SYSTEM.md`/`LAYOUT_SYSTEM.md`)

This document changes nothing about the palette's existing three-group anatomy. It adds exactly one
element: a persistent final row, rendered whenever at least one Records group has at least one result,
that hands off to the full results page.

```
┌───────────────────────────────────────────────────────────────────────┐
│  🔍  diyar                                                        ⌘K  │
├───────────────────────────────────────────────────────────────────────┤
│  NAVIGATE                                                              │
│    ↗ Sales → Customers                                                │
├───────────────────────────────────────────────────────────────────────┤
│  CUSTOMERS                                                             │
│    Diyar Real Estate — customer · 2 open invoices                     │
├───────────────────────────────────────────────────────────────────────┤
│  INVOICES                                                              │
│    INV-2208 — Diyar Real Estate — KD 44,020.000 · overdue             │
├───────────────────────────────────────────────────────────────────────┤
│  AI & ACTIONS                                                          │
│    ✦ Ask AI about "diyar"                                             │
├───────────────────────────────────────────────────────────────────────┤
│    View all 9 results for "diyar"  →                            [↵]  │
└───────────────────────────────────────────────────────────────────────┘
```

## `/search` results page

`/search` instantiates `LAYOUT_SYSTEM.md`'s **List Page Template** as its base shape (Page Header,
Filter Bar, Data Region) but reinterprets each region for a *multi-entity* result set rather than one
table: the Filter Bar becomes a row of entity **Type Tabs** plus a facets trigger, and the Data Region
becomes a stack of independently-loading, independently-empty **result groups** instead of one
`DataTable`. A dedicated Ask AI card sits alongside the results, not inside any one group, because an
AI-synthesized answer is not itself a member of any single entity type.

```
┌───────────────────────────────────────────────────────────────────────────────┐
│  🔍  diyar                                                        [Filters ▾]  │  ← Page Header /
├─────────────────────────────────────────────────────────────────────────────── ┤     search input
│ [All 9] [Customers 1] [Invoices 2] [Journal Entries 3] [Documents 2] [⋯]        │  ← Type Tabs
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

| Region | Grid position | Content | Notes |
|---|---|---|---|
| Search Input | Full width, sticky below Topbar (`--z-sticky`) | `GlobalSearchInput` — large, auto-focused on landing, pre-filled and pre-selected when `?q=` is present | Same input instance drives both the "All" aggregate and whichever single-type tab is active |
| Type Tabs | Full width, below Search Input | `SearchTypeTabs` — "All" plus one tab per non-empty group, each with a live count badge | Horizontal-scroll below `md`, per `RESPONSIVE_DESIGN.md` Pattern 1's general tab-overflow handling |
| Results column | `col-span-8` at `lg`+, full width below | Stacked `SearchResultGroup`s, "All" tab shows the first `per_type` (default 5) rows per group with a "See all N in {Module} →" link; a single-type tab shows that group's own paginated, "Load more" list | Each group streams and empties independently — see `# States` |
| Ask AI rail | `col-span-4` at `lg`+, appears above results (collapsible) below `lg` | `SearchAiAnswerCard` | Reuses the exact `AiCardShell`/`ConfidenceBadge`/citation pattern from `# AI Integration`; a docked, seeded instance of the same Ask AI surface `AI_COMMAND_CENTER.md` and `FRONTEND_ARCHITECTURE.md` already specify, never a second chat implementation |
| Empty-query state | Replaces Results column + Ask AI rail | `RecentSearches` (client-only, last 10) + `SuggestedSearches` (role-driven, e.g. "Approvals awaiting you") | Never auto-runs a query on the user's behalf — see `# States` |

The Ask AI rail is deliberately *not* itself a member of the Type Tabs row — there is no "AI" tab to
click into, because the answer is a synthesis *over* the visible groups, not a competing result type a
user would filter down to. This mirrors `AI_COMMAND_CENTER.md`'s own Dashboard-vs-Command-Center
distinction applied at a smaller scale: the structured groups are the deterministic, "where do the
records stand" half of this screen, and the Ask AI card is the advisory, "what does this mean" half,
kept visually and structurally distinct exactly as `DESIGN_LANGUAGE.md` Principle 7 requires.

# Components Used

Every visual element on both surfaces is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue,
composed into a small set of new, Search-scoped components living in `components/search/` per
`PROJECT_STRUCTURE.md`'s module-folder convention. Crucially, **`DataTable` is not reused here** — it
assumes one column shape for one resource, and Search's result set is heterogeneous by nature (a
customer row and an invoice row share almost no columns); reusing it would force either a lossy
lowest-common-denominator table or a hand-rolled per-type branch buried inside `DataTable` itself,
exactly the kind of one-off duplication `COMPONENT_LIBRARY.md`'s opening paragraph warns against.
Search instead uses a card-per-result pattern (`SearchResultCard`) with a per-type render body, which
composes the *same* underlying primitives (`AmountCell`, `StatusPill`, `CurrencyTag`) a `DataTable`
column would have used — no financial figure or status label is formatted differently just because it
appears in Search instead of in Invoices' own list.

| Component | Source | Role on this screen |
|---|---|---|
| `Command`, `CommandDialog`, `CommandInput`, `CommandList`, `CommandGroup`, `CommandItem`, `CommandEmpty` | `components/ui/command.tsx` (existing, `cmdk`-backed) | The ⌘K palette in full — unchanged from `NAVIGATION_SYSTEM.md` |
| `Tabs` | `components/ui/tabs.tsx` (existing) | `SearchTypeTabs`'s underline-indicator tab row |
| `AmountCell` | `components/accounting/amount-cell.tsx` (existing) | Every monetary figure inside a result card (invoice/bill totals, journal entry amounts) |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` (existing) | Muted currency annotation on a multi-currency result |
| `StatusPill` | `components/shared/status-pill.tsx` (existing) | Result-card status (posted, overdue, paid, pending), reusing each domain's own status/tone lookup |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` (existing) | The Ask AI card's confidence pill; never used on an individual ranked result (see `# AI Integration`) |
| `AiCardShell` | `components/ai/ai-card-shell.tsx` (existing) | The Ask AI card's mandatory visual envelope — colored start-border, `AI` badge, agent code |
| `ReasoningDisclosure` | `components/ai/reasoning-disclosure.tsx` (existing) | Collapsed-by-default expansion of the Ask AI answer's full retrieval context |
| `Sheet` | `components/ui/sheet.tsx` (existing) | Mobile facets panel; the promoted, full Ask AI conversation drawer below `3xl` |
| `Popover`, `Tooltip`, `DropdownMenu` | `components/ui/*` (existing) | Facet pickers, citation hover previews, per-result quick-action menus |
| `Badge`, `Skeleton`, `Card`, `Button` | `components/ui/*` (existing) | Tab count badges, loading placeholders, result-card shells, quick-action buttons |
| `EmptyState`, `ErrorState` | `components/shared/*` (existing) | Per-group and whole-page empty/error rendering — see `# States` |
| `PermissionGate` / `Can` | `components/shared/permission-gate.tsx`, `components/auth/can.tsx` (existing) | Wraps every quick action a result card exposes |
| `useChat` (Vercel AI SDK) | Reused from `app/(app)/ai/chat/page.tsx`'s existing wiring | Powers `SearchAiAnswerCard`'s streaming answer — no second chat implementation |
| `GlobalSearchInput` | `components/search/global-search-input.tsx` (**new**) | The large, auto-focused input on `/search`; a thin, larger-scale sibling of the palette's own `CommandInput`, sharing the same debounce hook |
| `SearchTypeTabs` | `components/search/search-type-tabs.tsx` (**new**) | Composes `Tabs` with live per-group counts and permission-filtered visibility |
| `SearchFacetsPanel` | `components/search/search-facets-panel.tsx` (**new**) | Per-type facet controls (status, date range, amount range, account), reusing the exact `filter[...]` grammar `API_FILTERING_SORTING.md` defines — a `Popover` at `lg`+, a `Sheet` below it |
| `SearchResultGroup` | `components/search/search-result-group.tsx` (**new**) | One entity type's heading, count, "See all →" link, and its stack of `SearchResultCard`s |
| `SearchResultCard` | `components/search/search-result-card.tsx` (**new**) | A discriminated-union render (`type` prop) producing the correct summary line, `AmountCell`/`StatusPill` pairing, and quick actions per entity type |
| `SearchAiAnswerCard` | `components/search/search-ai-answer-card.tsx` (**new**) | Composes `AiCardShell` + `ConfidenceBadge` + inline citation rendering + the promoted follow-up composer |
| `RecentSearches` | `components/search/recent-searches.tsx` (**new**) | Client-only list of the last 10 submitted queries, capped and per-user, matching the palette's own "five most recently visited routes" precedent |
| `SuggestedSearches` | `components/search/suggested-searches.tsx` (**new**) | Role-driven starter queries/shortcuts (pending approvals, "reconcile a bank account"), sourced from the same Urgent Actions signal `DASHBOARD.md`'s Quick Actions row already reads |
| `SearchEmptyState` | `components/search/search-empty-state.tsx` (**new**) | The whole-page and per-group zero-result renderings — see `# States` |

None of the nine "new" components introduces a design token, an API shape, or a permission key that
does not already exist elsewhere in the platform — each is a documented composition, matching
`COMPONENT_LIBRARY.md`'s stated contract that a screen never hand-rolls a money cell, a status pill, or
a confidence indicator the shared library already owns.

# Data & State

## Two call shapes, one ranking model

The palette and `/search` deliberately use **two different call shapes against the same underlying
per-module search logic**, because they optimize for different things, not because they disagree about
what a good result is:

- **The palette's per-keystroke fan-out** is exactly what `NAVIGATION_SYSTEM.md` already specifies —
  a `useQueries` call issuing one parallel request per permitted source straight to that module's own
  `GET /api/v1/{module}/search` endpoint (`accounting/journal-entries/search`, `accounting/customers/search`,
  `products/search`, `inventory/search`, and the four more this document adds below), capped at 5 rows
  per source, tuned for sub-200ms perceived latency at 2+ typed characters.
- **The `/search` results page** uses one new aggregate endpoint, `GET /api/v1/search`, so that landing
  on the page never triggers eight-to-twelve independent waterfalled requests from the browser itself.
  Laravel resolves this endpoint by calling the identical internal query logic each module's own
  `/search` endpoint already uses — same `tsvector`/`pg_trgm`/`pgvector` machinery, same relevance
  ranking, same permission checks — in parallel, server-side, and returns one grouped envelope. A result
  ranked third in the Customers group is ranked third whether the request came from the palette or from
  `/search`, because both paths ultimately execute the same per-module ranking query; the aggregate
  endpoint is a fan-in of results, never a re-ranking of them.

```
GET /api/v1/search?q=diyar&types=customers,invoices,journal_entries,documents&per_type=5
```

```json
{
  "success": true,
  "data": {
    "query": "diyar",
    "groups": [
      {
        "type": "customers",
        "label": "Customers",
        "permission": "accounting.customer.read",
        "total": 1,
        "items": [
          { "id": 4471, "type": "customer", "display_name": "Diyar Real Estate",
            "name_ar": "شركة ديار العقارية", "subtitle": "2 open invoices · KD 44,020.000 outstanding",
            "href": "/sales/customers/4471", "matched_by": "text" }
        ]
      },
      {
        "type": "invoices",
        "label": "Invoices",
        "permission": "sales.read",
        "total": 2,
        "items": [
          { "id": 2208, "type": "invoice", "reference": "INV-2208", "amount": "44020.0000",
            "currency_code": "KWD", "status": "overdue", "customer_name": "Diyar Real Estate",
            "href": "/sales/invoices/2208", "matched_by": "text" }
        ]
      },
      {
        "type": "documents",
        "label": "Documents",
        "permission": null,
        "total": 1,
        "items": [
          { "id": 90142, "type": "document", "attachable_type": "customer", "attachable_id": 4471,
            "title": "Lease agreement.pdf", "snippet": "…Diyar Real Estate, Unit 14B, term ending…",
            "chunk_id": 553, "relevance": 0.83, "href": "/sales/customers/4471?attachment=90142&highlight=553",
            "matched_by": "semantic" }
        ]
      }
    ],
    "ai_answer": {
      "status": "ready",
      "agent_code": "REPORTING_AGENT",
      "confidence_score": 88,
      "answer_text": "Diyar Real Estate has 2 open invoices totaling KD 44,020.000 [1][2], and last paid INV-2190 on Jul 2 via Receipt #441 [3].",
      "citations": [
        { "ref": 1, "type": "invoice", "id": 2208, "label": "INV-2208", "href": "/sales/invoices/2208" },
        { "ref": 2, "type": "invoice", "id": 2190, "label": "INV-2190", "href": "/sales/invoices/2190" },
        { "ref": 3, "type": "receipt", "id": 441, "label": "Receipt #441", "href": "/sales/receipts/441" }
      ],
      "generated_at": "2026-07-18T09:02:11Z"
    }
  },
  "meta": { "pagination": null },
  "message": "OK",
  "errors": [],
  "request_id": "b7e1...",
  "timestamp": "2026-07-18T09:02:11Z"
}
```

Every `items[]` row's `matched_by` field is `"text"` or `"semantic"` — surfaced in the UI only as the
muted "Matched by meaning" chip described in `# AI Integration`, never as a confidence badge. The
`documents` group's rows carry `attachable_type`/`attachable_id` (the polymorphic pointer the platform's
`attachments` table already uses) plus a `chunk_id` and `relevance` score; `permission: null` on that
group in the envelope signals that permission was already resolved per-row server-side (see below)
rather than once for the whole group, because two documents in the same response can belong to records
two different permissions gate.

## Endpoints

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Aggregate results (full page) | `GET /api/v1/search` | None baseline; each `groups[]` entry independently permission-checked | `q` (min 2 chars, `API_FILTERING_SORTING.md`'s existing full-text minimum), `types` (CSV, omit for all permitted types), `per_type` (default 5, max 25), `branch_id` |
| Single-type page (Type Tab, "See all") | `GET /api/v1/search/{type}` | That type's own read permission | Cursor-paginated per `API_PAGINATION.md`; identical `q`/`branch_id` params plus that type's own `filter[...]`/`sort` grammar from `API_FILTERING_SORTING.md` |
| Accounts | `GET /api/v1/accounting/accounts/search` | `accounting.accounts.read` | `pg_trgm` + `tsvector` over `code`/`name_en`/`name_ar`, reused verbatim from `AccountPicker`'s own query (`COMPONENT_LIBRARY.md`) |
| Journal Entries | `GET /api/v1/accounting/journal-entries/search` | `accounting.journal.read` | `tsvector`/GIN over `reference`/`memo`/line `description`, per `JOURNAL_ENTRIES.md → Full-text search` |
| Customers | `GET /api/v1/accounting/customers/search` | `accounting.customer.read` | `tsvector` over `legal_name`/`display_name`/`name_ar`/`name_en`, per `CUSTOMERS.md`'s Smart Search agent |
| Vendors | `GET /api/v1/accounting/vendors/search` | `accounting.vendor.read` | Same shape as Customers, per `VENDORS.md` |
| Products | `GET /api/v1/products/search` | `products.read` | `ix_products_search` (tsvector) combined with `ix_products_embedding` (HNSW/pgvector) semantic fallback, exactly as `PRODUCTS.md → Smart Search` specifies — semantic scan only triggers when the tsvector pass returns fewer than a configured minimum |
| Invoices | `GET /api/v1/sales/invoices/search` | `sales.read` | `tsvector` over `invoice_number`/customer name, structured filters (`status`, `due_date`, `amount`) via the standard `filter[...]` grammar |
| Bills | `GET /api/v1/purchasing/bills/search` | `purchasing.bill.read` | Same shape, vendor side; includes OCR-extracted `vendor_bill_reference` per `PURCHASES.md` |
| Documents (RAG) | `GET /api/v1/documents/search` | Resolved per row from `attachments.attachable_type`/`attachable_id`'s owning record | Chunk-level `pgvector`/HNSW semantic search over a document-chunk embedding table, the natural extension of the exact pattern `DATABASE_ARCHITECTURE.md` already names as a planned use of its `vector` extension — "future semantic search over `attachments`" — combined with `tsvector` over each chunk's extracted text for literal-term matches |
| Knowledge (AI) | `GET /api/v1/ai/knowledge/search` | `reports.read` | Searches `ai_decisions`/`ai_risk_flags` content (insights, recommendations, risk narratives) the same way `AI_COMMAND_CENTER.md`'s AI Insights feed already stores it — a hit here is a pointer into that existing feed, never a duplicated copy |
| Approvals | `GET /api/v1/approvals?q=...` | Assigned approver, or `ai.approve` | Reuses the Approval Center's own existing list endpoint (`FRONTEND_ARCHITECTURE.md → The Approval Center`) with its `q` param, not a parallel endpoint |
| Navigate (pages) | n/a — client-side | Per-item, from `filterNavByPermissions(NAV_TREE, permissions)` | Identical to the palette's existing Navigate group; `/search`'s "All" tab includes it as a lightweight, uncounted first row when it has matches, never as a numbered Type Tab of its own |
| Ask AI answer | `POST /api/v1/ai/chat` (SSE) | `ai.chat` | The exact endpoint `app/api/ai/chat/route.ts` already proxies for the full "Ask AI" page and the AI Command Center's docked panel; Search seeds it with `{ context: { surface: "search", query, result_ids } }` rather than introducing a second chat endpoint |

`GET /api/v1/search` is additive, not a replacement: every per-module `/search` endpoint above continues
to exist and continues to be what `AccountPicker`, `CustomerPicker`, and the palette's own per-keystroke
fan-out call directly. The aggregate endpoint is Laravel-side composition over those same code paths —
`SearchController::aggregate()` calls each permitted module's existing `SearchService`, not a new,
parallel search implementation — so there is exactly one place a ranking bug or a permission bug can
live per entity type, never two.

## Types

```ts
// types/search.ts
export type SearchResultType =
  | "account" | "journal_entry" | "customer" | "vendor" | "product"
  | "invoice" | "bill" | "document" | "insight" | "approval" | "page";

export interface SearchResultItem {
  id: number | string;
  type: SearchResultType;
  href: string;
  matched_by: "text" | "semantic";
  // per-type fields (display_name, amount, currency_code, status, snippet, chunk_id, relevance, ...)
  // are typed via a discriminated union keyed on `type`; the shared fields above are the only ones
  // SearchResultGroup's own shell code touches directly.
}

export interface SearchResultGroupData {
  type: SearchResultType;
  label: string;
  permission: string | null;
  total: number;
  items: SearchResultItem[];
}

// Additive extension of `AiDecision.sources` (FRONTEND_ARCHITECTURE.md → AI Integration Layer):
// a SearchCitation is a `source` entry that also carries the inline reference number and a
// resolved href, because an Ask AI answer's citations are clicked, not merely disclosed.
export interface SearchCitation {
  ref: number;              // matches an inline [n] marker in answer_text
  type: SearchResultType | "receipt";
  id: number | string;
  label: string;
  href: string;
}

export interface SearchAiAnswer {
  status: "ready" | "low_confidence" | "unavailable";
  agent_code: string | null;
  confidence_score: number | null; // 0–100, null when status !== "ready"
  answer_text: string | null;
  citations: SearchCitation[];
  generated_at: string | null;
}
```

## Query keys and cache tuning

```ts
// lib/query/keys.ts (search-scoped factories, alongside the existing dashboardKeys/aiSummaryKeys)
export const searchKeys = {
  all: ["search"] as const,
  aggregate: (q: string, filters: { types?: string[]; branchId: number | null }) =>
    [...searchKeys.all, "aggregate", q, filters] as const,
  group: (type: SearchResultType, q: string, filters: Record<string, unknown>) =>
    [...searchKeys.all, "group", type, q, filters] as const,
  paletteSource: (sourceId: string, q: string) =>
    [...searchKeys.all, "palette", sourceId, q] as const, // unchanged shape from NAVIGATION_SYSTEM.md
  aiAnswer: (q: string, resultIds: string) =>
    [...searchKeys.all, "ai-answer", q, resultIds] as const,
  recent: () => [...searchKeys.all, "recent"] as const, // client-only, no network
};
```

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Palette fan-out | `searchKeys.paletteSource(*)` | `15_000` | Unchanged from `NAVIGATION_SYSTEM.md` — a quick overlay favors snappy re-open over freshness |
| Aggregate results | `searchKeys.aggregate(*)` | `15_000` | A landed search is a point-in-time snapshot; 15s keeps a back-navigation from re-fetching identical results while still catching a same-minute edit |
| Single-type page | `searchKeys.group(*)` | `15_000`, `placeholderData: keepPreviousData` | Facet changes and "Load more" pages dim-and-hold the previous result set rather than flashing empty, per `FRONTEND_ARCHITECTURE.md`'s stated debounced-input discipline |
| Ask AI answer | `searchKeys.aiAnswer(*)` | `Infinity` (never refetched automatically) | An LLM call is comparatively expensive and a re-generated answer for an unchanged query is rarely worth it; a manual "Regenerate" affordance in `SearchAiAnswerCard` is the only way to force one |
| Recent searches | `searchKeys.recent()` | n/a — Zustand + `localStorage`, no query at all | Client-only, capped at 10, per-user, cleared on sign-out — the same treatment `NAVIGATION_SYSTEM.md` already gives the palette's "five most recently visited routes" |

The Ask AI answer is deliberately **not** fetched on every keystroke or even on every debounce tick — it
fires once, on query *submit* (Enter, or the debounce settling past 500ms of no further typing while the
input still holds focus), because a synthesized answer for "di", "diy", "diya", "diyar" in rapid
succession would be four wasted model calls for one intent, unlike the structured groups, which are
cheap SQL and genuinely benefit from updating on every 2-character-or-more keystroke.

## Realtime

Search itself is a point-in-time query, not a live view, so it holds no persistent Reverb subscription
of its own for its structured groups — a result list does not visibly update while a user is reading it,
matching how a search engine is expected to behave (unlike Dashboard's KPI tiles, which are genuinely
live). The one exception is the Ask AI card, which subscribes to `private-company.{id}.ai-jobs` for its
own "thinking" status exactly as every other Ask AI surface in the product does, and unsubscribes the
moment its answer resolves or the user navigates away from `/search`.

## AI agents feeding this screen

| Agent | Contribution to Search |
|---|---|
| Document AI / general retrieval layer | Owns embedding maintenance for every semantic-search-eligible column (`products.embedding`, and the document-chunk embeddings backing the Documents group) — per `PRODUCTS.md`, this agent's only responsibility here is transparent background housekeeping; it never renders its own card |
| General Accountant | `pg_trgm` fuzzy-match tuning behind Customers/Vendors search, matching the fuzzy vendor-name matching `DATABASE_ARCHITECTURE.md` already attributes to this agent |
| Reporting Agent | Synthesizes the Ask AI answer's prose from the retrieved structured results and document chunks; also computes the ranking signal behind Products' "Bundle/Recommendation"-adjacent relevance boosting where applicable |
| Orchestrator (FastAPI layer) | Selects which one or two specialist agents actually answer a given Ask AI question, exactly as `AI_COMMAND_CENTER.md`'s own Ask AI row describes — "the FastAPI layer selects one or two specialists per question" |
| Approval Assistant | Determines, for any citation or result that resolves to a pending approval, whether a "Do it" affordance could legally render at all — in practice never on this screen, since Search's Ask AI answer never carries a `recommended_action` (see `# AI Integration`) |

# Interactions & Flows

**Opening the palette and typing.** Unchanged from `NAVIGATION_SYSTEM.md`: `⌘K`/`Ctrl+K` toggles it from
anywhere; typing debounces at 200ms; below 2 characters no Records fan-out fires (matching
`API_FILTERING_SORTING.md`'s own server-side minimum, so the client never sends a request the server
would reject); arrow keys move `aria-activedescendant` through the flattened option list; `Enter`
activates the highlighted item; `Escape` closes without navigating. This document's one addition sits
at the bottom of the list: once any Records group has at least one result, a persistent, always-last
row reads **"View all N results for '{q}' →"** (`N` is the sum of every visible group's `total`, not
just what is currently rendered) — selecting it (click, or `Enter` when it is the highlighted item)
closes the palette and calls `router.push('/search?q=' + encodeURIComponent(rawQuery))`, carrying the
literal, un-debounced current input value so nothing typed after the last resolved fan-out is lost.

**Landing on `/search` directly** — via the palette handoff above, a bookmarked/shared link, a typed
URL, or the mobile bottom tab's **Search** destination with no query at all. `GlobalSearchInput` is
server-rendered pre-filled from `searchParams.q` and, when present, pre-selected (not merely
placed-at-cursor) so retyping is a single keystroke away; the **All** tab is active by default unless
`?type=` names a single group. With no `?q=`, the Results column and Ask AI rail are replaced entirely
by `RecentSearches` + `SuggestedSearches` (`# Layout & Regions`) — Search never runs a query the user
did not ask for, including never re-running the last query automatically on a fresh visit.

**Switching a Type Tab** writes `?type=` to the URL (shareable, back-button-safe) and requests that
single type's own paginated endpoint. The first page of that request is not re-fetched from scratch —
`SearchTypeTabs`' click handler seeds `queryClient.setQueryData(searchKeys.group(type, q, {}), aggregateResponse.groups.find(g => g.type === type))` as `initialData` before the query even mounts, because
the aggregate response already contained that group's first `per_type` rows; only a second page
("Load more") or a facet change triggers a genuinely new network request.

**Refining facets** within a single-type tab renders the exact same `filter[...]`/`sort` grammar
`API_FILTERING_SORTING.md` defines for that resource's own list screen — a status or date-range facet
picked here produces literally the same query-string shape `/accounting/journal-entries` would accept.
This is what makes a group's "See all N in {Module} →" link a zero-translation deep link: it is built as
`{module list route}?q={q}&{same filter params already active}`, and the destination list page
understands every parameter natively, with no Search-specific parsing on the receiving end.

**Clicking a result card** navigates to that record's canonical detail route, reusing the exact per-entity
routes `FRONTEND_ARCHITECTURE.md`'s route tree already defines (`/sales/invoices/{id}`,
`/accounting/journal-entries/{id}`, `/purchasing/vendors/{id}`, …) — never an inline drawer duplicating
the record's own detail page, the same "a number always means go look at the source" discipline
`DASHBOARD.md` established for its KPI tiles, applied here to a search result. A **Documents** result
additionally carries `?attachment={id}&highlight={chunk_id}`, so the destination record's Attachments
tab opens pre-scrolled to and visually highlighting the exact cited passage rather than dropping the
user at the top of a long PDF.

**Using a quick action** on a result card (e.g., "Post" on a draft Journal Entry match, "Reconcile" on a
Bank Transaction match) calls the identical permission-gated mutation the record's own screen exposes,
wrapped in the same `PermissionGate`/`Can` guard — Search never renders a bespoke, screen-specific
version of an action, and a sensitive action (`bank.transfer`, `payroll.approve`, `tax.submit`) still
always terminates at that record's own full page or the Approval Center, never inline on a result card.

**Asking the Ask AI card a follow-up question** promotes the condensed rail card into the full docked
Ask AI surface (`Sheet` below `3xl`, persistent rail at `3xl`+, per `AI_COMMAND_CENTER.md`'s own docked
pattern), pre-seeded with the exact retrieval context already computed for the current query — no
re-computation, no re-fetch of results the user has already seen. Any follow-up answer that resolves to
an actionable item renders the platform's standard three-button set (`Do it` / `Send for approval` /
`Dismiss`) exactly as `FRONTEND_ARCHITECTURE.md`'s AI Integration Layer specifies; Search introduces no
variant of that contract.

**Clearing or editing the query** re-debounces and cancels any in-flight aggregate/group request via
TanStack Query's own built-in cancellation (an `AbortController` per query, torn down when the query key
changes) — a fast clear-then-retype never races an earlier, now-irrelevant response back into the UI.
`placeholderData: keepPreviousData` keeps the previous result set visible, dimmed, through the gap.

**Empty-state shortcuts.** Selecting a `SuggestedSearches` chip (e.g., "Approvals awaiting you") behaves
exactly like typing that query and pressing Enter — it populates `GlobalSearchInput`, updates the URL,
and runs the same aggregate call, so a suggested shortcut is never a hidden, Search-specific code path a
typed query could not also reach.

# AI Integration

Search carries two distinct AI surfaces, and keeping them visually and structurally separate is the
single most important design decision on this screen — a user must never be able to mistake a ranking
heuristic for an assertion of fact, per `DESIGN_LANGUAGE.md` Principle 7.

**Ranking-time AI (silent, structural).** The `pgvector`/HNSW semantic fallback behind Products,
Customers/Vendors fuzzy matching, and the Documents group's chunk retrieval is exactly the "Document AI
/ general retrieval layer... fully automated, read-only" agent `PRODUCTS.md`'s own Smart Search section
already defines. This layer never renders its own card, its own confidence badge, or its own reasoning
disclosure — a returned row is just a row, and ranking is not itself a claim requiring disclosure any
more than a `tsvector` match is. The one visible trace it leaves is deliberately minimal: a result whose
`matched_by` field is `"semantic"` (it matched by meaning, not by any literal token in the query) carries
a small, muted microcopy chip — **"Matched by meaning"** — not a confidence badge, not an `AiCardShell`
border, because this is an explanation of *why a row appeared*, not a statement the platform is asking
the user to trust or distrust with a score.

**Answer-time AI (visible, advisory).** `SearchAiAnswerCard` is a Retrieval-Augmented Generation surface
in the literal sense: its retrieval context is exactly the same top-ranked structured results and
Documents chunks already returned for the identical query — nothing hidden, nothing the user could not
also see as a plain result card — and its generation step is the same orchestrated `POST /api/v1/ai/chat`
endpoint every other Ask AI surface in the product calls. It always renders through `AiCardShell` (colored
start-border, `AI` badge, `agent_code`), always carries a `ConfidenceBadge` normalized via the existing
`normalizeConfidence(score, "percentage")` helper (`AI_COMMAND_CENTER.md`'s own stated normalization from
the API's 0–100 scale to the component's canonical 0–1 range), and its prose contains numbered inline
citations (`[1]`, `[2]`, …) that map one-to-one to `SearchCitation` entries — clicking or focusing a
citation scrolls to and briefly highlights that exact result card if it is on-screen, or navigates to it
directly if it is not. Every cited monetary figure renders through `AmountCell`, matching the platform
rule `FRONTEND_ARCHITECTURE.md`'s Streaming AI chat section states verbatim — "an amount cited by the
assistant in an Arabic conversation is exactly as unambiguous as one shown in a table."

**Confidence floor and the "no answer" state.** When the orchestrator's confidence in a synthesized
answer falls below the platform's stated threshold, `SearchAiAnswerCard` renders `status: "low_confidence"`
or `"unavailable"` with explicit prose — "I don't have enough posted data to answer that confidently
yet" — and **no confidence badge at all**, identical in wording and posture to `AI_COMMAND_CENTER.md`'s
own documented edge case for an unanswerable Ask AI question. This is a deliberate asymmetry: a badge
showing an artificially low number would still look like a measurement; the honest failure mode here is
"no answer," not "a low-confidence answer," and the UI says so in plain language rather than hedging
with a number.

**Autonomy: read-only, zero write path.** `SearchAiAnswer` never carries a `recommended_action` field —
it is a Q&A synthesis, not a proposal, and this is a structural property of the response shape, not a
convention a component chooses to honor. Where an answer's prose references an existing actionable item
("you have 3 pending approvals"), that sentence's citation links straight to `/approvals`; Search never
renders an inline one-click action for anything a citation points at, regardless of how confident the
answer is. This mirrors `DASHBOARD.md`'s own rule for its AI Summary Rail almost exactly — "Dashboard
never renders a one-click 'approve' for anything on the sensitive-action list, regardless of confidence"
— extended here to the entirety of Search's Ask AI surface rather than only the sensitive-action subset,
because unlike Dashboard's condensed recommendation cards, Search's answer is explicitly framed as an
explanation of what already exists, not a workflow surface at all.

**Every quick action stays governed.** A result card's own quick actions (Post, Approve, Reconcile) are
never AI-authored or AI-gated — they are the record's own permission-checked actions, present or absent
based on `usePermission`, completely independent of anything the Ask AI card says. Search's AI layer
can help a user *find* the right record and *understand* what it says; it never becomes a second,
parallel path to *changing* that record.

# States

Every region on `/search` carries its own loading, empty, and error presentation, matching
`DASHBOARD.md`'s own per-region independence — no single slow group blocks another, and a failure in one
never takes the page down.

| Region | Loading | Empty | Error |
|---|---|---|---|
| Search Input (typing, pre-2-char) | n/a | Below the 2-character minimum, no fan-out fires at all; the input simply shows no results yet, never an error or a spinner for a query the server would reject anyway | n/a |
| Aggregate results (2+ chars, in flight) | Skeleton `SearchResultGroup` shells for the two or three most commonly non-empty types (Customers, Invoices, Journal Entries), fading in as each group's real data resolves independently | n/a at this stage | A whole-page `ErrorBoundary` only for a genuine `5xx`/network failure on the aggregate call itself; a single group's own `4xx` (a permission edge case, see `# Edge Cases`) never shows here because permission failures are structural — the group is absent, not errored |
| A single `SearchResultGroup` | Skeleton rows matching that group's real card height | "No {type} match '{q}'" with a muted subtext, styled identically across every type via one shared `SearchEmptyState` variant — never an illustrated empty-state character, per `DESIGN_LANGUAGE.md`'s imagery stance | Small inline "Couldn't load {type} results — Retry" card, isolated per group |
| Whole-page zero results | n/a | "No results for '{q}' across your accessible data" plus `SuggestedSearches`, distinguished from a single empty group by covering every visible tab, not just one — never conflated with a permission-driven empty page (a role with zero readable types instead sees `# Edge Cases`' own dedicated state) | Same whole-page `ErrorBoundary` as above |
| Documents/RAG group specifically | Skeleton snippet lines (two lines, matching a real chunk preview's height) | "No matching documents" — distinct from "no attachments exist at all," since the latter is a business-state fact this group does not attempt to distinguish (Search only reports what matched, not what exists) | Same per-group retry card; a chunk-embedding-service outage degrades this one group only |
| Ask AI card | Three pulsing `accent-subtle` dots per `DESIGN_LANGUAGE.md`'s calmer AI-thinking pattern, never a generic spinner | An unsubmitted query (still typing, not yet settled) shows a quiet "Ask AI will answer once you finish typing" placeholder, never a stale answer from a previous query | `status: "unavailable"` (AI engine down/`503`) renders the same "AI insights are temporarily unavailable" treatment `DASHBOARD.md` already defines for its own AI Summary Rail — the structured groups beside it are completely unaffected |
| Recent/Suggested (empty-query landing) | Recent Searches renders instantly from `localStorage`, no network, no loading state; Suggested Searches shows 2–3 skeleton chips while its underlying signal resolves | A brand-new account with no search history yet shows Suggested Searches only, with copy naming that Recent Searches will appear "once you've searched a few times" | Suggested Searches fails silently to an empty row (it is a convenience, not core functionality) rather than showing a retry card for a non-essential feature |

A route-level `error.tsx` remains the outermost safety net for a genuinely unexpected failure (the
session becoming invalid mid-render), but per the platform's three-granularity error model
(`FRONTEND_ARCHITECTURE.md → Error Handling & Boundaries`) this should essentially never fire for an
individual group's ordinary `4xx`/`5xx` — those are caught and handled inline, one region at a time.

# Responsive Behavior

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | The palette's collapsed Topbar icon (`RESPONSIVE_DESIGN.md → TopAppBar adaptation`) opens the same full-screen ⌘K-equivalent overlay every breakpoint shares — a `Sheet`, not a centered `Dialog`, per `LAYOUT_SYSTEM.md`'s Pattern 4 (modals become sheets) applied to the palette's own container. The bottom tab bar's **Search** destination navigates to `/search` directly: Type Tabs become a horizontal-scroll chip row (no wrapping, matching `RESPONSIVE_DESIGN.md`'s general tab-overflow handling), the Facets trigger opens a bottom `Sheet`, and the Ask AI card renders as a collapsible, dismissible card stacked *above* the Results column rather than a side rail — collapsed to a one-line "✦ Ask AI has an answer — tap to expand" affordance by default so it never pushes structured results below the fold on a 375px screen. |
| `md` (768–1023px) | Two Type Tabs' worth of chips fit before scrolling begins; Results column remains full width; Ask AI card is still stacked above results, no longer collapsed by default. |
| `lg` (1024–1279px) | The 8/4 Results-column/Ask-AI-rail split activates; Facets moves from a `Sheet` to a `Popover` anchored to the Facets trigger button. |
| `xl`+ (≥1280px) | Same 8/4 layout, generous margins; the platform-wide `360px` AI Rail companion panel may additionally dock per `LAYOUT_SYSTEM.md`'s App Shell, entirely independent of this page's own Ask AI rail column — a user can have both open simultaneously without them competing for the same space, the same relationship `DASHBOARD.md` already documents between its AI Summary Rail and the global AI Rail. |

`SearchResultCard` and `SearchResultGroup` use Tailwind v4 container queries (`@container`) rather than
only viewport breakpoints, so the identical card renders correctly whether it is one-of-five in a full
desktop group or one-of-three inside a narrower mobile stack — the same pattern `DASHBOARD.md` already
established for `KpiTile`. Touch targets on every result card, quick-action button, and Type Tab
maintain the platform's 44×44px minimum hit area below `md`, implemented once in the shared
`Button`/`IconButton` components rather than per instance on this screen. On mobile specifically, the
`GlobalSearchInput` does not auto-focus on landing the way it does on desktop — auto-focusing an input
on a touch device force-opens the on-screen keyboard and immediately obscures the very results the user
navigated to `/search` to see, so mobile shows the pre-filled input and results together, unfocused,
requiring an explicit tap to edit the query.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md` and `LAYOUT_SYSTEM.md`'s RTL contract and applied
concretely to this screen's specific content; nothing here introduces a new mirroring rule.

- **Logical properties only.** `SearchTypeTabs`' tab order, `SearchResultCard`'s icon-to-text gap, the
  Facets trigger's placement relative to the input, and the Ask AI card's citation footnote row all use
  `ms-*`/`me-*`/`text-start`/`text-end` exclusively — flipping `dir="rtl"` mirrors the whole page (Ask AI
  rail moves to the opposite edge, Type Tabs read start-to-end in the opposite direction) with zero
  screen-specific RTL code, per the ESLint restriction `COMPONENT_LIBRARY.md` already enforces platform-wide.
- **The search input itself does not force a script-specific `dir`.** Unlike a structured field (an
  amount, a date), free-text search input must accept mixed-script queries as naturally in an
  Arabic-locale session as in an English one — a user reading an Arabic-locale `/search` page routinely
  types a Latin-script SKU or an English customer name, and `GlobalSearchInput` lets the browser's own
  bidi algorithm handle the input box's cursor behavior rather than pinning `dir="rtl"`/`dir="ltr"` on it.
- **Numerals, amounts, and citation markers never mirror.** Every `AmountCell` inside a result card and
  every `[n]` inline citation marker in the Ask AI answer renders inside a `dir="ltr"`/
  `unicode-bidi: isolate` span, per `AmountCell`'s existing implementation (`COMPONENT_LIBRARY.md`) — a
  citation numbered `[2]` reads as `[2]`, left-to-right shaped, even embedded inside a fully Arabic
  sentence, and `KD 44,020.000` reads unambiguously regardless of the surrounding paragraph's direction.
- **Cross-lingual semantic matching is a first-class, not a fallback, capability.** An Arabic-typed query
  matching an English-named product or customer via embedding similarity — the exact example
  `PRODUCTS.md`'s own Smart Search section names — is not a degraded result; it renders identically to
  any other match, distinguished only by the same muted "Matched by meaning" chip any semantic hit
  carries, in either language.
- **Bilingual names throughout.** Every result card showing a customer, vendor, product, or account name
  renders `name_en`/`name_ar` per the active locale via `useLocale()`, with the API always returning both
  fields regardless of `Accept-Language`, per `COMPONENT_LIBRARY.md`'s bilingual-data convention — a user
  reading an Arabic `/search` page can still type an English name into the box and land on the
  Arabic-labeled record.

| Context | English | Arabic |
|---|---|---|
| Search input placeholder | Search or jump to… | ابحث أو انتقل إلى… |
| Type Tab: All | All | الكل |
| "Matched by meaning" chip | Matched by meaning | مطابقة بالمعنى |
| Ask AI card, ready | Suggested by the Reporting Agent — 88% confidence. | مقترح من وكيل التقارير — بثقة 88%. |
| Ask AI card, low confidence | I don't have enough posted data to answer that confidently yet. | لا تتوفر بيانات مرحّلة كافية للإجابة بثقة حتى الآن. |
| Whole-page empty state | No results for "{q}" across your accessible data. | لا توجد نتائج لـ "{q}" ضمن البيانات المتاحة لك. |
| "View all" palette row | View all {n} results for "{q}" | عرض كل {n} نتيجة لـ "{q}" |
| "See all in {module}" link | See all {n} in {module} → | عرض الكل ({n}) في {module} ← |

Arabic copy on this screen is authored directly by a fluent professional-register writer, not
machine-translated from the English strings above, matching the platform's stated voice discipline; a
"Matched by meaning" chip or a low-confidence disclosure that sounds precise and calm in English must
sound identically precise and calm in Arabic.

# Dark Mode

Search introduces no new color, elevation, or radius token — every surface resolves through the same
`:root[data-theme="dark"]` remap `COMPONENT_LIBRARY.md` and `DESIGN_LANGUAGE.md` define, applied with one
deliberate elevation distinction this document is responsible for getting right: **`surface-glass`
(frosted/blurred elevation) is reserved for exactly two places platform-wide — the Command Palette and
the AI Command Center overlay** (`DESIGN_LANGUAGE.md → Elevation & Surfaces`, explicit "**only**"). The
⌘K palette therefore renders on `surface-glass` in both themes, unchanged. `/search`'s own result cards,
Type Tabs, and Ask AI rail are **ordinary `surface-1` `Card`s with a 1px hairline border** — not glass —
because a full page built entirely from frosted panels loses the effect `DESIGN_LANGUAGE.md` explains
glass is meant to have precisely because it is rare, and costs a real, avoidable `backdrop-blur`
performance hit on the back-office desktop hardware this product's primary audience actually uses. This
is a distinction worth stating explicitly because a naive implementation, reusing the palette's own
"floating overlay" visual language for the Ask AI rail simply because both are AI-adjacent, would over-apply
glass in exactly the way the design language forbids.

- **Result cards and the Ask AI card** use `bg-surface`/`bg-ink-100` in light mode and their
  dark-remapped equivalents; elevation gets *lighter*, not darker, in dark mode, matching the platform's
  stated "physical light" dark-mode strategy rather than a naive inversion.
- **The "Matched by meaning" chip and `ConfidenceBadge`** read their tone tokens (`ink-500`/`ink-700` for
  the chip, the accent/brass scale for the badge) from the same CSS variables every other screen uses —
  never a Search-specific hard-coded hex, so a token-level theme change (light-to-dark, or a future
  white-label re-skin per `THEMING.md`'s Company Branding section) applies here automatically.
- **Citation footnote markers and inline `[n]` references** keep the same `ink-500`/muted-link treatment
  in both themes, never the accent color on its own — the accent is reserved platform-wide for the single
  primary action and AI-touched provenance borders, not for a footnote number, per `DESIGN_LANGUAGE.md`'s
  "one accent, spent deliberately" principle.
- **Skeleton shimmer** on every loading region uses the identical `ink-3 → ink-4 → ink-3` sweep
  `DESIGN_LANGUAGE.md`'s Motion section defines, at the same 1.6s cadence, so Search's loading state is
  visually indistinguishable in rhythm from Dashboard's or any List Page Template's own loading state.

Every Storybook story for the five new composed components (`SearchTypeTabs`, `SearchResultGroup`,
`SearchResultCard`, `SearchAiAnswerCard`, `SearchFacetsPanel`) ships the platform's standard four-way
parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and Search's own Playwright suite
captures the same four-way screenshot set at the route level, per `FRONTEND_ARCHITECTURE.md`'s testing
convention.

# Accessibility

Search targets WCAG 2.2 AA as a floor, identically in both languages and both themes, per
`ACCESSIBILITY.md`'s platform-wide conformance target, with the following screen-specific applications.

**The Command Palette's ARIA contract is unchanged and fully reused.** `ACCESSIBILITY.md → Keyboard
Navigation → Command Palette` already specifies the exact pattern this screen relies on: a `role="dialog"`
`aria-modal="true"` container, labelled by a visually-hidden title, containing a `role="combobox"` input
(`aria-expanded`, `aria-controls` pointing at the results list, `aria-activedescendant` tracking the
highlighted row) and a `role="listbox"` results list whose rows are `role="option"`, grouped under
labelled headings. This document's one addition — the "View all N results" row — is simply one more
`role="option"` at the end of the flattened list, reachable by the same arrow-key traversal and activated
the same way as every other row; it introduces no new interaction pattern for assistive technology to learn.

**`/search`'s own input follows the identical `combobox` pattern**, `aria-controls` pointing at the Results
column instead of a `CommandList`, so a screen-reader user who has already learned the palette's behavior
finds the full page's search box behaves identically — typing, arrowing through suggestions (Recent/
Suggested when empty), and landing on real results is one consistent mental model across both surfaces,
not two.

**Landmark structure.** `/search`'s regions are real landmarks: `<search>` (or `role="search"` on a
`<div>` where the native element is not yet universally supported by the target browser matrix) wraps the
input and Type Tabs; each `SearchResultGroup` is a `<section aria-labelledby="...">` with a
visually-hidden `<h2>` naming the entity type, mirroring the exact pattern `DASHBOARD.md` already
established for its own Chart Region — "a visually-hidden `<h2>` labels each region for screen-reader
navigation even where the sighted design omits a visible heading."

**Live regions, calibrated to avoid noise.** Per-group result counts settling in as independent requests
resolve announce via `aria-live="polite"`, batched once per group rather than once per row — a
twelve-row Journal Entries group does not announce twelve times. The Ask AI card's streaming answer sets
`role="status"`/`aria-busy="true"` once when generation begins ("answering…") and announces the complete
text once more on completion; it never narrates token-by-token, matching `ACCESSIBILITY.md`'s explicit
rule against exactly that failure mode and `AI_COMMAND_CENTER.md`'s identical posture for its own docked
Ask AI surface.

**Keyboard path, start to finish.** `⌘K` opens the palette from anywhere on the product, including from
`/search` itself. On `/search`: `Tab` moves from `GlobalSearchInput` → Type Tabs (arrow-key roving
tabindex within the tab list, per the Radix `Tabs` primitive's own contract) → Facets trigger → each
`SearchResultGroup`'s cards in DOM order → that group's "See all" link → the Ask AI card's citation
links → its follow-up composer. No control on this screen requires a mouse to reach or activate.

**Color is never the only channel.** The "Matched by meaning" chip pairs a label with (optionally) a
small glyph, never color alone; `ConfidenceBadge`'s qualitative band (amber below 70%) is always paired
with the numeric score and a text label, never a bare color swatch; `StatusPill` on every result card
reuses each domain's own existing text-plus-tone pairing rather than inventing a Search-specific status
treatment.

**Permission-aware behavior stays legible, never mysterious.** A result group a role cannot read is
omitted entirely from Type Tabs (existence-sensitive, matching `NAVIGATION_SYSTEM.md`'s and
`DASHBOARD.md`'s identical precedent for omitting rather than disabling); a quick action on a visible
result card that the role cannot perform is likewise omitted rather than shown disabled-and-unexplained,
because the underlying record's own screen already establishes that a permission-gated *button* (not a
whole group's existence) gets a disabled-with-tooltip treatment — Search inherits whichever rule the
target record's own document specifies for that specific action, never invents a third rule of its own.

**Focus management on navigating away.** Clicking any result card, citation, or "See all" link is a real
route change, not an in-place mutation, so focus lands on the destination page's own heading via the
platform's standard route-change focus-reset (`FRONTEND_ARCHITECTURE.md`/`ACCESSIBILITY.md → Route
changes`) — Search introduces no bespoke focus-trap logic of its own beyond what the palette's `Dialog`
and the mobile Facets `Sheet` already provide as Radix primitives.

# Performance

- **The aggregate endpoint exists specifically to avoid a client-side waterfall.** Landing on `/search`
  issues one request, not eight-to-twelve independent per-module calls — the alternative (the client
  fanning out itself, the way the palette legitimately does for a 200ms overlay) would multiply
  connection overhead and make the slowest single module's response time the page's *effective* loading
  time for every group, not just its own.
- **Streamed, not blocking, per group.** `app/(app)/search/page.tsx` is a Server Component that resolves
  `searchParams.q` and streams the Results column and Ask AI rail behind independent `<Suspense>`
  boundaries, matching `DASHBOARD.md`'s own per-region streaming pattern — a slow Documents/RAG group
  (chunk-level vector search is the single most expensive query shape on this screen) never delays
  Customers or Invoices from painting.
- **Debounced, cancellable, stable placeholders.** `GlobalSearchInput` debounces at 200ms for the
  aggregate call (matching the palette's own tuning) and every facet control at 300ms, matching
  `FRONTEND_ARCHITECTURE.md`'s platform-wide debounced-input convention; every query uses
  `placeholderData: keepPreviousData` so a fast retype dims the previous results rather than flashing an
  empty page between keystrokes.
- **The Ask AI answer never fires per keystroke.** As stated in `# Data & State`, generation triggers only
  on submit/settle — avoiding the obviously wasteful (and, for a paid-per-token backend, literally
  costly) pattern of calling an LLM for "d", "di", "diy", "diya" in immediate succession.
- **Embedding freshness is asynchronous, by design, and that lag is an accepted trade-off, not a bug.**
  `PRODUCTS.md`'s own Smart Search section states this explicitly for its embedding-refresh queue — "a
  just-edited product's semantic search ranking still reflects its previous text" for a short window —
  and Search inherits the identical trade-off for every semantic-search-eligible entity type: an LLM
  embedding call inline on every write would blow up write-path latency for a benefit (near-real-time
  semantic ranking) that is not worth that cost.
- **Code-split the Ask AI card's chat SDK.** The Vercel AI SDK's `useChat` bundle backing
  `SearchAiAnswerCard` is loaded via `next/dynamic({ ssr: false })` with a matching skeleton, so a role
  without `ai.chat` (rare, but possible for a narrow custom role) never downloads it at all.
- **Virtualization threshold matches the platform default.** A single-type tab's "Load more" list adopts
  `@tanstack/react-virtual` once it exceeds roughly 200 rows, the same threshold `DESIGN_LANGUAGE.md`'s
  Data Density section already sets for General Ledger and Journal Entries history — a heavily-matched
  Journal Entries tab on a large company behaves identically to that module's own list screen.
- **Web Vitals tracked against a realistic baseline.** LCP for `/search` is measured against the first
  non-empty result group's paint (or the empty-query Recent/Suggested state's paint, whichever the
  landing actually is), tagged by company-size band exactly as `DASHBOARD.md` specifies for its own route,
  since a holding company's search surface and a brand-new company's are expected to have different tail
  latencies for reasons unrelated to a genuine regression.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A role holds zero read permissions on every searchable entity type (an extremely narrow custom role) | `/search` still renders: Navigate results (permission-filtered `NAV_TREE` matches), and Ask AI if `ai.chat` is held — never a fully blank page or a "you don't have access" wall, matching `AI_COMMAND_CENTER.md`'s identical "grid renders Morning Briefing and Ask AI only" fallback for its own most-restricted-role case. |
| A query matches a record that exists in a different company | Structurally impossible, not merely filtered post-hoc — every per-module `/search` call, and the aggregate endpoint that fans out to them, is `company_id`-scoped at the query level (the same leading-column composite-index discipline `GENERAL_LEDGER.md`'s own full-text search already applies), so a cross-tenant row is never fetched from the database in the first place, let alone returned and hidden. This is the same enumeration discipline `FRONTEND_ARCHITECTURE.md`'s `404`-not-`403` rule protects elsewhere, applied at the search layer instead of a single-resource fetch. |
| A permission is revoked between a result rendering and the user clicking through to it (a role change in another tab, mid-session) | `SearchResultCard` renders synchronously from the already-resolved response with no re-check of its own; the destination route's own server-side permission check is the actual authority, and a stale click lands on that route's own `403`/`404` handling — never a Search-side crash — exactly matching `DASHBOARD.md`'s identical edge case for a Quick Action's create permission. |
| A query shorter than 2 characters | No Records fan-out fires at all (client-side, before any request is sent), consistent with `API_FILTERING_SORTING.md`'s own server-side minimum ("Search query 'q' must be at least 2 characters") — the input simply shows Recent/Suggested still, never an error message for typing "a". |
| A result matches only by semantic/embedding similarity, with no literal token overlap | Renders normally, tagged with the "Matched by meaning" chip (`# AI Integration`) rather than being excluded or ranked below literal matches by default — a semantic-only match is still a real match, just one whose relevance signal differs from a keyword hit. |
| An Arabic-typed query against an English-named record, or vice versa | Resolved via the same cross-lingual embedding similarity `PRODUCTS.md`'s Smart Search section names as a first-class capability, not a fallback; the result renders with full bilingual (`name_en`/`name_ar`) display exactly as any other match would. |
| The AI engine (FastAPI layer) is down or returns `503` for an extended period | Every structured result group is completely unaffected, since none of them call the AI engine for ranking (embeddings are pre-computed and read from PostgreSQL directly) — only `SearchAiAnswerCard` shows its `"unavailable"` state. This is the same direct payoff `DASHBOARD.md` documents for its own AI-engine-down scenario, applied here: a finance team can still find every record with the AI layer fully down. |
| Two browser tabs run different searches simultaneously | No shared-session concern exists — each tab's query, Type Tab selection, and facet state live entirely in that tab's own URL and component state, with no cross-tab synchronization requirement, unlike a company switch (`FRONTEND_ARCHITECTURE.md`'s own cross-tab discussion), which is session-level and genuinely must be. |
| An Ask AI answer's citation points to a result that a subsequent facet change or "Load more" click has scrolled out of the currently-rendered list | The citation still resolves correctly, because it links directly to the record's own canonical route (`href`), never to "the 3rd item in the currently visible list" — a citation survives any amount of subsequent client-side list manipulation. |
| A single result group would return hundreds of matches for a common query (e.g., searching a frequently-used word like "invoice") | The aggregate response caps each group at `per_type` (default 5); the group's own "See all N in {Module} →" link deep-links into that module's own fully-featured, paginated, filterable list screen (`/sales/invoices?q=invoice`) rather than Search attempting to paginate hundreds of heterogeneous cards inline. |
| A bulk import or a wave of mutations lands while an Ask AI answer's retrieval context was already captured | The answer's `generated_at` timestamp is shown in the card's footer with "as of" microcopy, consistent with the platform's general staleness-labeling discipline (`AI_COMMAND_CENTER.md`'s own `stale`/`stale_since` marker convention) — the answer is never silently presented as reflecting data newer than what it actually saw. |
| A very long bilingual customer, vendor, or product name overflows a result card's title line, especially under Arabic where a formal legal name can run long | The title truncates with `truncate` and exposes the full name via the card's own accessible name (`aria-label`/`title`), matching the identical pattern `DASHBOARD.md` specifies for its `BranchSelect` trigger, applied here to a result card instead of a dropdown trigger. |
| A user searches while completely offline or mid-reconnect | The aggregate request fails as an ordinary network error (not an `ApiError`, per `FRONTEND_ARCHITECTURE.md`'s `isRetryable` classification), rendering the whole-page error state with a Retry button; Recent Searches still renders from `localStorage` with no network dependency at all, so a user can at least re-select a prior query the moment connectivity returns. |
| A citation or result would reveal information the user can browse but not act on (e.g., a Journal Entry a Read Only role can see but not post) | The result and any citation to it render fully and legibly — read access is read access — while its quick-actions row simply omits "Post" the same way that entry's own detail screen would, per the platform's default-deny-but-legible posture; Search never grants an action a role would not otherwise have. |

# End of Document
