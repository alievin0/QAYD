# Search Flow — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Flows / SEARCH_FLOW
---

# Purpose

This document specifies the end-to-end journey of finding-and-acting in the QAYD web client: a user
invokes the global command palette (`⌘K` / `Ctrl+K`), types a query, sees debounced results grouped
into Navigate / Records / an AI answer, keyboard-navigates them, and selects one — to navigate to a
screen or entity, run a permitted action, or get an AI answer — as well as the page-scoped
`ScopedSearchInput` variant that narrows a single table or picker, the persistent `/search` results
page a query hands off to, and the branch points (RBAC-filtered results, empty/loading/error,
no-results → refine-or-ask-AI) where the journey forks.

It is a *flow* document and defers, rather than duplicates: [`../components/SEARCH_BAR.md`](../components/SEARCH_BAR.md)
owns the component contract for `CommandPalette`, `GlobalSearchInput`, `ScopedSearchInput`,
`SearchResultGroup`/`SearchResultItem`, `RecentSearches`/`SuggestedSearches`, and `SearchAiAnswerCard`;
[`../screens/SEARCH_SCREEN.md`](../screens/SEARCH_SCREEN.md) (and `SEARCH.md`) owns the persistent
`/search` page — its route, aggregate endpoint, Type Tabs, and facets;
[`../NAVIGATION_SYSTEM.md`](../NAVIGATION_SYSTEM.md) `# Command Palette` owns the palette's
search-and-action *grammar*; and [`../components/AI_WIDGETS.md`](../components/AI_WIDGETS.md) owns the
AI answer card's confidence/reasoning/citation rendering. This document is authoritative only for how
those surfaces sequence into one find-and-act journey.

Two platform rules bind every step below. First, **results are RBAC-filtered end to end** — a Navigate
result for a screen the role cannot open, an action the role lacks, or a record type the role cannot
read is never rendered; search is *a faster path to the permitted surface, never a wider one*. Second,
**AI answers are proposals, never commits** — the "Ask AI" path renders an answer with its confidence,
reasoning, and citations and may *propose* an action routed through the normal human-gate, but a
keystroke in a search box never posts, approves, or changes financial data.

# Actors & Preconditions

| Actor | Role in this flow |
|---|---|
| The searcher | Any authenticated company member. Invokes the palette or a scoped input, types, navigates, and selects. |
| The frontend | Filters Navigate/action sources through `usePermission()`/`filterNavByPermissions` before display; debounces the Records query; trusts backend ranking within a group; commits nothing on a keystroke. |
| The search backend | `tsvector`/`pg_trgm`/`pgvector` machinery behind `GET /api/v1/search` (aggregate) and `GET /api/v1/search/{type}` (single-type cursor page); each group's fan-out is independently permission-checked server-side. |
| The AI orchestrator | Produces the optional `ai_answer` (its confidence, reasoning, citations) when `include_ai_answer=true` or the user takes the "Ask AI" path; gated by `ai.chat`. |

**Preconditions.**

- The authenticated `(app)` shell is mounted; `CommandPalette` is a singleton mounted once in the
  shell layout, so `⌘K` works on every route without each screen wiring it.
- An active `X-Company-Id`; every fan-out is company-scoped.
- No single permission gates search itself — a maximally narrow role still gets a working palette and
  `/search` (Navigate results, and Ask AI if `ai.chat` is held), never an access wall.

# Entry Points

| Entry point | Surface | Notes |
|---|---|---|
| `⌘K` / `Ctrl+K` from anywhere | `CommandPalette` overlay | Registered once at the shell; `Esc` closes |
| The Topbar trigger pill ("Search or jump to…" + `⌘K` hint) | `CommandPalette` | Flips the same shell store flag the shortcut does — one open path, two entry points |
| A `/search?q=…` deep link or bookmark | `/search` results page | Deep-linkable, refresh-surviving, shareable |
| The palette's "Ask AI about '{q}'" item | `/search?q=…&ask=1` | Hands free text to a surface that can show reasoning, citations, and a follow-up composer |
| `Cmd/Ctrl+F` with a dense table focused | That table's `ScopedSearchInput` | Focuses the in-table filter (not browser find, which cannot see virtualized rows) |
| A `RecentSearches`/`SuggestedSearches` chip | `/search?q=…` | Behaves exactly like typing that text and pressing Enter |

# Flow Overview

```mermaid
flowchart TD
    A[Invoke ⌘K / Topbar pill] --> B[Palette opens\nempty query]
    B --> C[Recent routes + suggested starters\nno network]
    C --> D[User types]
    D --> E{Query length}
    E -->|< 2 chars| F[Navigate matches only\nsynchronous, no network]
    E -->|>= 2 chars| G[Debounced 200ms\nGET /api/v1/search per_type=5]
    G --> H[Grouped results\nNavigate → Records → AI & Actions]
    F --> H
    H --> I{Keyboard-navigate + select}
    I -->|Navigate| J[router.push canonical screen route]
    I -->|Record| K[router.push canonical filtered URL]
    I -->|Run action| L[Permitted action fires]
    I -->|Ask AI| M[/search?q=…&ask=1]
    M --> N[SearchAiAnswerCard\nconfidence + reasoning + citations]
    N -->|Proposes an action| O[Routed through human-gate\nApprovalCard / AIProposalPanel]
    H -->|No matches| P[CommandEmpty\nAsk AI still offered + Navigate matches]
    P --> M
    J --> Z[Journey ends at permitted surface]
    K --> Z
    L --> Z
    N --> Z
```

Step map:

1. Invoke the palette (or focus a scoped input).
2. Idle state — recent + suggested, no network.
3. Type; below the 2-char minimum only Navigate fires.
4. Debounced Records fan-out; results group and rank.
5. Keyboard-navigate across groups.
6. Select — Navigate / Record / Action / Ask AI.
7. Ask AI answer (proposal, never a commit).
8. No-results → refine or Ask-AI handoff. (Scoped in-page variant runs in parallel.)

# Step-by-Step

## Step 1 — Invoke

- **Surface.** `CommandPalette`, a `surface-glass` `CommandDialog` portalled to the document root at
  `z-command`. For the scoped variant, a toolbar `ScopedSearchInput`.
- **User action.** `⌘K`/`Ctrl+K`, the Topbar pill, or `Cmd/Ctrl+F` on a focused table.
- **UI state.** The dialog opens, focus trapped, the `CommandInput` focused. Reopening resets focus to
  the input and shows Recent when the query is empty — the most common `⌘K` use is "take me back."
- **API call.** None on open.

## Step 2 — Idle (empty query)

- **UI state.** The palette shows `RecentRoutes` (client-only last-5 visited routes, per-user, cleared
  on sign-out — only routes the user could reach) plus suggested starter actions. On `/search`, the
  empty-query landing shows `RecentSearches` (last 10 submitted queries) + `SuggestedSearches`
  (role-driven starters).
- **API call.** None — no network fires below the minimum. `SuggestedSearches` sources from `GET
  /api/v1/ai/urgent-actions?per_page=3` (the same Urgent-Actions signal the Dashboard reads), so a role
  that cannot reconcile is never suggested "reconcile a bank account."

## Step 3 — Type

- **User action.** Types into the input.
- **UI state.** The Navigate source is synchronous and permission-filtered (`useNavForPermissions`,
  reusing the Sidebar's `filterNavByPermissions`), so "you typed the name of a page" is the fastest,
  most-certain answer and sits above the network-dependent Records group. Below **2 characters** (the
  platform full-text minimum), only Navigate and Recent render — no Records call fires.
- **API call.** None until ≥2 chars.
- **Success/failure.** Proceed to Step 4 once ≥2 chars.

## Step 4 — Debounced Records fan-out

- **UI state.** `CommandList` sets `aria-busy`; a subtle inline "Searching…" row appears under the
  Records heading; prior results are kept via cache to avoid a flash.
- **API call.** `GET /api/v1/search?q={q}&per_type=5` (aggregate; each group independently
  permission-checked server-side), debounced 200ms, a TanStack Query with `staleTime: 30_000` so
  re-opening on the same query is instant. The palette caps ~5 rows per type for a fast preview.
- **Success branch.** Grouped results render: **Navigate → Records → AI & Actions**, each with matched
  text `Highlight`ed (`<mark bg-accent-100 text-accent-700>`, bidi-safe, never splitting an Arabic
  ligature). Ranking is *not* re-derived client-side — each source's backend ranking is trusted as-is;
  the palette orders only the *groups*, never the rows within one (Navigate's own exact → prefix → fuzzy
  → module order is the one client-side ranking, over a small in-memory set).
- **Failure branches.** A one-group fan-out `503` (e.g. customers fails, journal entries succeed)
  degrades *that* group to an inline "Couldn't load customer results" row — Navigate and Ask AI stay
  usable; never a whole-empty or whole-error palette.

## Step 5 — Keyboard-navigate

- **User action.** `↓`/`↑` move through the flattened result list across group boundaries (cmdk's
  single roving-focus model); `Home`/`End` jump to first/last; typing filters all groups
  simultaneously.
- **UI state.** One focused option at a time; the group order (Navigate → Records → AI) is meaningful
  and stable.
- **API call.** None during navigation.

## Step 6 — Select

- **User action.** `Enter` (or click) on the focused item.
- **Branches.**
  - **Navigate result** → `router.push(navItem.href)`, palette closes. Navigate outranks Records for an
    exact page-name match ("inventory" surfaces the Inventory screen above a fuzzy record).
  - **Record result** → `router.push(item.href)` to the *canonical URL the owning screen's own filter UI
    would produce* — a customer result goes to `/sales/invoices?filter[customer_id]=…`, not a bespoke
    detail view; matches the deep-link rule and survives refresh.
  - **Run an action** (AI & Actions group) → the permitted action fires; an action a role lacks is
    *omitted* (not shown disabled), because the action's mere existence is itself information a role
    should not have.
  - **Ask AI** → `router.push('/search?q={q}&ask=1')` — the answer renders on a surface that can show
    reasoning, citations, and a follow-up composer, never inline in the palette.
- **API call.** Navigation only, until the destination's own fetch; or the action's own mutation.
- **Failure branches.** A record the user has since lost access to → navigating there yields the
  server's `403`, caught by the route's `error.tsx` into an "You don't have access to this" `ErrorState`,
  never the Next.js default error screen.

## Step 7 — Ask AI answer (proposal, never a commit)

- **Screen / route.** `/search?q=…&ask=1`. `page.tsx` reads `searchParams.q` server-side; the AI
  answer fires once on submit/settle (not per keystroke).
- **UI state.** `SearchAiAnswerCard` (the search counterpart of `AIProposalPanel`): the answer, its
  `ConfidenceBadge` (normalized via `normalizeConfidence(score, 'percentage')`) + reasoning, inline
  citations to the source records, and a follow-up composer. It commits nothing. Below the confidence
  threshold the answer is still shown but any "do it" affordance is truly `disabled`, exactly as
  `AIProposalPanel` gates a low-confidence output.
- **API call.** Structured groups + AI answer on submit: `GET
  /api/v1/search?…&include_ai_answer=true` (the additive optional parameter, gated by `ai.chat` for the
  `ai_answer` sub-object); a follow-up uses `POST /api/v1/ai/chat` (SSE). The in-flight `ai_answer`
  status is driven by `private-company.{id}.ai-jobs`, unsubscribed the instant it resolves or the user
  leaves `/search`.
- **Success branch.** The answer renders with citations that `focusOrNavigate` to the underlying record.
  If the answer *proposes* an action, it routes through the normal `ApprovalCard`/`AIProposalPanel`
  human-gate — see [`./APPROVAL_FLOW.md`](./APPROVAL_FLOW.md) and
  [`./AI_CHAT_FLOW.md`](./AI_CHAT_FLOW.md).
- **Failure branches.** AI engine `503` → the "Ask AI" item and the answer card render a distinct
  "temporarily unavailable" state honoring `Retry-After`, never an infinite spinner; the structured
  Results column is entirely unaffected. Honest low-confidence "no answer" renders as plain prose with
  no fabricated badge.

## Step 8 — No results → refine or Ask-AI handoff

- **UI state.** Palette: `CommandEmpty` ("No results for '{q}'") *plus* the still-offered "Ask AI about
  '{q}'" and any Navigate matches. `/search`: `SearchEmptyState scope="page"` + `SuggestedSearches` for a
  whole-page zero-result; per-group `SearchEmptyState scope="group"` ("No {type} match '{q}'") for a
  single empty group.
- **Handoff.** The zero-result state never dead-ends — the reachable next actions are always "refine the
  query" or "Ask AI about it," so a user who could not find a record by keyword is one keystroke from an
  AI answer over the same corpus.

## Scoped in-page variant (parallel)

A `ScopedSearchInput` narrows one table/picker via that table's own `q` param (debounced 250ms), never
opening the palette or hitting `GET /api/v1/search`. It renders a leading "in {scope}" affordance so a
user never mistakes a list search for a global one, and takes `Cmd/Ctrl+F` when its table is focused.

# Happy Path

Khalid (Finance Manager) presses `⌘K`. The frosted palette opens showing his last five visited routes. He
types `diyar`. Below two characters nothing fired; at "di" a debounced `GET /api/v1/search?q=di&per_type=5`
runs while Navigate already shows the "Inventory" and "Dashboard" pages. At "diyar" the Records group fills:
a CUSTOMERS group (Diyar Real Estate, with its trading-name subtitle) and an INVOICES group, each row's
"diyar" softly highlighted, plus an "* Ask AI about 'diyar'" item last. He arrows down to the customer and
presses Enter; the palette closes and he lands on `/sales/invoices?filter[customer_id]=…` — the same URL his
own filter UI would build, refresh-survivable. Later he wants a synthesized read, so he reopens `⌘K`, types
the same query, and selects Ask AI; `/search?q=diyar&ask=1` renders a `SearchAiAnswerCard` with a
`High confidence` badge, a two-sentence answer, and clickable citations to the underlying invoices — and
commits nothing.

# Alternate & Error Paths

| Path | Trigger | Behavior |
|---|---|---|
| Below-minimum query | `< 2` chars | Only Navigate + Recent; no Records call. |
| One-group failure | One fan-out `503`s | That group degrades to an inline "Couldn't load {type} results" row; other groups and Ask AI stay usable. |
| AI unavailable | AI engine `503` | The "Ask AI" item / answer card renders a distinct "temporarily unavailable" state honoring `Retry-After`; structured results unaffected. |
| Low-confidence AI answer | Blended confidence below threshold | The answer is still shown, but its "do it" affordance is truly `disabled`; an honest "no answer" renders as plain prose with no badge. |
| No matches | Empty result set | `CommandEmpty`/`SearchEmptyState` + the still-offered Ask AI and Navigate matches — never a dead end. |
| RBAC-empty role | Zero readable record types | A working palette/`/search` (Navigate + Ask AI if held), never an access wall. |
| Lost access before click | Permission changed between response and select | The destination's `403` is caught by its `error.tsx` into an "You don't have access to this" `ErrorState`. |
| Query matching a screen and a record | e.g. "inventory" | Navigate outranks Records for an exact page-name match — the deterministic, network-free answer wins. |
| Rapid open/close | Palette closed mid-fetch | The in-flight query is not cancelled (its result is cached for a re-open); reopening resets focus and shows Recent when empty. |

# Data & State

| Purpose | Endpoint | Method | Permission | Cache behavior |
|---|---|---|---|---|
| Palette Records fan-out | `/api/v1/search?q=&per_type=5` | GET | Each group server-checked | `['palette','records', q]`, debounced 200ms, `staleTime: 30_000`, enabled at `q.trim().length >= 2` |
| `/search` structured groups | `/api/v1/search?q=&types=&per_type=5&branch_id=` | GET | Baseline none; each group checked | `['search', q]`, debounced 200ms, enabled at ≥2 chars |
| Structured + AI answer | `/api/v1/search?…&include_ai_answer=true` | GET | Same + `ai.chat` for `ai_answer` | On submit/settle only |
| Single-type page ("See all") | `/api/v1/search/{type}?q=&cursor=&filter[...]=` | GET | That type's read permission | `useInfiniteQuery`, cursor, `keepPreviousData` |
| Ask AI follow-up | `/api/v1/ai/chat` | POST (SSE) | `ai.chat` | Not cached — `useChat` owns it |
| Suggested searches | `/api/v1/ai/urgent-actions?per_page=3` | GET | `ai.chat` | Empty-query landing only |
| Scoped in-page search | The table's own `q` param | GET | The table's own read | Feeds that table's list query; never global search |

**Mutations & invalidations.** Search itself mutates nothing. Every write a user can trigger *while on*
`/search` (accepting an AI recommendation, sending for approval) is the owning surface's own mutation
with its own optimistic/pessimistic posture and its own invalidations — search never adds a parallel
write path. Navigate/record selection is `router.push` only; the destination owns its fetch.

**Realtime.** The palette subscribes to no channel. `/search` opens exactly one subscription, scoped to
the Ask AI card alone (`private-company.{id}.ai-jobs`, driving `SearchAiAnswerCard`'s `isLoading` while
`include_ai_answer=true` is in flight), unsubscribed the instant the answer resolves or the user
navigates away.

**Deep-linkability.** Every part of a search is a shareable URL (`/search?q=…&type=…&ask=1`); a Type
Tab, a facet, and the empty-query state are all `searchParams`-driven, never their own route — refresh-
and back-button-safe like a filtered list.

# AI Touchpoints

- **The AI answer carries confidence + reasoning + citations**, via `SearchAiAnswerCard` composing
  `ConfidenceBadge` + reasoning + citation links — never a bare answer.
- **`accent` appears in exactly two places** in search: the highlighted match `<mark>` and the "Ask AI"
  item's `Sparkles` icon — both legitimate under "accent marks either the primary action or an
  AI-touched element." A result row's status uses its `StatusPill` tone, never accent as decoration.
- **The AI sits last, never first.** The AI & Actions group ranks below Navigate and Records so the
  palette never nudges a user toward an AI answer before a deterministic one exists.
- **AI answers stay proposals.** The card may *propose* an action (draft an entry, suggest a match)
  routed through the normal `ApprovalCard`/`AIProposalPanel` human-gate; a search keystroke posts
  nothing. Search's AI layer can help a user *find and understand* a record; it never becomes a second,
  parallel path to *changing* it.
- **A result card's own quick action** is governed entirely by that record's own `usePermission` check —
  never gated or ungated by anything the Ask AI card says; the two are fully independent.

# Permissions

| Step | Permission | Enforcement |
|---|---|---|
| Open the palette / `/search` | None | No single gating permission — a narrow role still gets a working surface |
| A Navigate result | The nav item's own key | Pre-filtered via `filterNavByPermissions`; absent items never render |
| A Records group | That entity type's read permission | Server-checked per fan-out; an unreadable group never returns, and a crafted request still `403`s |
| An action item | The action's own permission | Checked via `usePermission()` before render; an ungranted action is *omitted*, not disabled |
| The Ask AI card / answer | `ai.chat` | The whole card is omitted (`<Can permission="ai.chat">`); structured Results are unaffected |
| Following a result | The destination's own permission | The destination's `403`/`error.tsx` is the boundary; the client filter is a courtesy |

The net contract: **a user can never navigate to, act on, or open via search anything they could not
already reach through the UI** — enforced belt-and-braces (server per group, client for nav/actions).

# i18n & RTL

- **Result titles are bilingual data, not translations.** `SearchResultItem` renders `title_en`/
  `title_ar` by `useLocale()`; the aggregate endpoint returns both regardless of `Accept-Language`, so
  an Arabic-UI user can still type an English account name and match it.
- **AI-answer prose is never machine-translated by the frontend** — it arrives already localized; the
  client localizes only chrome (placeholders, group headings, "Searching…", "No results for '{q}'",
  "Ask AI about '{q}'", "See all", type labels), all failing `npm run i18n:check` if present in only one
  dictionary.
- **The palette is `surface-glass`** (one of only two frosted surfaces in the app) and slides/fades in
  place (no directional slide), so it needs no mirroring beyond its logical layout; RTL mirrors via
  logical properties (`ps-*`/`pe-*`, `me-2`, `inset-inline-*`).
- **Amounts, codes, and dates inside results never mirror** — a result's `AmountCell` stays `dir="ltr"`
  `latn`, a currency code stays Latin, even inside an Arabic result row.
- **The query itself is passed to the backend untransformed** (full-text over `tsvector`/`pg_trgm`); a
  mixed-script or Eastern-Arabic-digit query is not transliterated or case-folded client-side — result
  titles render in their stored language, and any numeric portion still shows `latn`.

# Accessibility

- **Dialog + roving focus.** `CommandPalette` is a labelled `role="dialog"`; the `CommandInput` is a
  `combobox` with `aria-expanded`/`aria-controls`; the list is a `listbox` of `option` rows (Radix/cmdk
  wired, preserved when re-skinning). Focus is trapped and **returns to the trigger on close** — the
  load-bearing focus-continuity guarantee of this flow.
- **Cross-group roving.** `↓`/`↑` cross group boundaries seamlessly; each `CommandGroup` heading is a
  group label read before its options; group order (Navigate → Records → AI) is meaningful and stable.
- **Search-in-progress announce policy.** `CommandList` sets `aria-busy="true"` while fetching; a
  visually-hidden `aria-live="polite"` region announces result-count changes ("6 results") — a
  newly-arriving result set is announced, never silently swapped, and never token-by-token.
- **Highlight is purely visual.** The `<mark>` carries no separate announcement; the full title is read
  as one string so highlighting never fragments the accessible name (bidi-safe).
- **Scope is announced, not inferred.** `ScopedSearchInput`'s "in {scope}" affordance is real text, so
  an AT user distinguishes a list-scope search from a global one.
- **Empty / RBAC-empty is a labelled region with a heading**, never a silent blank list; the
  still-offered "Ask AI"/Navigate items are the reachable next actions. A low-confidence AI "do it" is
  truly `disabled`, not merely dimmed. Reduced motion collapses the palette's fade/scale entrance, the
  AI "thinking" dots, and any result stagger to instant state changes.

# Edge Cases

| Edge case | Behavior |
|---|---|
| Abandonment | Closing the palette persists nothing beyond the client-only Recent list; an in-flight query is cached for a re-open, not cancelled. |
| Resume / back button | `/search` state lives entirely in `searchParams`; back/refresh restores the exact query, Type Tab, and `ask` state. |
| Rapid open/close | Reopening resets focus to the input and shows Recent when empty; the cached in-flight result serves instantly if the same query returns. |
| Stale result (lost access) | Following a since-forbidden result yields the destination's `403` → `error.tsx` `ErrorState`, never a leak of whether the resource exists. |
| One-group failure | Degrades that group only; the rest of the palette works. |
| No-permission-to-read-anything role | A working palette/`/search` (Navigate + Ask AI if held), never an access wall. |
| Query matching a screen and a record | Navigate outranks Records on an exact page-name match. |
| Mixed-script / Eastern-Arabic-digit query | Passed to the backend untransformed; titles render in their stored language; numeric portions stay `latn`. |
| Very long result title | `truncate` with a `title` attribute + keyboard-focus tooltip; widths never tuned to English samples (Arabic runs longer). |
| Double-action | Search commits nothing; any action reached *from* a result carries the owning surface's own idempotency/optimism — never a search-local write. |
| AI engine unavailable | "Ask AI" and the answer card show a distinct fail-fast state honoring `Retry-After`; structured search is unaffected. |

# End of Document
