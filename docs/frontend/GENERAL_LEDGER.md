# General Ledger — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: GENERAL_LEDGER
---

# Purpose

The General Ledger screen is the frontend rendering of the Accounting Engine's ledger of record: the
account-activity / account-statement surface where a Finance Manager, Accountant, Senior Accountant,
Auditor, CFO, or Owner opens one account (or a small, deliberately scoped family of accounts), picks a
date range or fiscal period, and reads every posted line that moved that account's balance — opening
balance, each debit or credit in chronological order, the running balance after each line, and the closing
balance for the range — with a one-click path from any line down to the journal entry, and from there to
the source document, that produced it. It is the UI half of the module specified in
`docs/accounting/GENERAL_LEDGER.md`: that document owns the meaning and computation of every number this
screen shows (Ledger Architecture, Business Rules, the `mv_account_balances` / `mv_trial_balance` /
`mv_account_running_balance` caches); this document owns how a human sees it, filters it, scrolls through
it at scale, and gets from a number to its proof.

The backend document names two closely related, but distinct, reports under its `# Reports` section:
**General Ledger** ("every posted line for a chosen account… with running balance") and **Account
Statement** ("same as General Ledger but framed for a single control-account sub-ledger entity — a
specific customer's AR activity, or a specific vendor's AP activity — typically emailed as a PDF"). This
screen renders both from one table and one route, not two: when the selected account is an ordinary leaf
account, the screen is the General Ledger; the moment the selected account is a control account
(`accounts.is_control_account = true`) and the user additionally scopes by a `customer_id` or `vendor_id`
in the secondary filters, the identical `LedgerTable` becomes, functionally, that customer's or vendor's
Account Statement — same columns, same drill-down, same export menu, with an added "Email statement" action
that only appears in that scoped state (`# Interactions & Flows`). Building one screen that specializes by
filter state, rather than two screens that would otherwise duplicate every rule in this document, is a
deliberate choice: the backend itself defines Account Statement as a filtered view of the same
`ledger_entries` data, not a separate table, and the frontend mirrors that exactly.

This screen is deliberately **not** a place where financial facts are created or changed. Posting,
reversing, and voiding all live on the Journal Entries screen (`/accounting/journal-entries`); the General
Ledger screen renders exactly what the ledger says happened and nothing else. Where a number here looks
wrong, the fix is a correcting entry made elsewhere, not an edit made here — the screen has no affordance
that could even attempt the latter. This restraint is what makes the screen safe to hand to an Auditor or
an External Auditor role with the same view every Accountant sees: read access to this screen carries zero
write risk by construction, not merely by omission of a button.

Three properties drive every decision in this document, mirrored directly from the backend module's own
founding properties:

1. **The frontend computes nothing that matters.** The opening balance, the closing balance, and the
   per-line running balance are all server-computed values — `mv_account_balances` for the header strip,
   `mv_account_running_balance` for the per-row `running_balance` — that the screen displays verbatim
   through `AmountCell`. The one client-side arithmetic this screen ever performs is a visually
   distinguished "so far" indicator summing the currently-loaded page's debits/credits while more pages are
   still streaming in; it is never presented as, or allowed to be confused with, the server-confirmed
   closing balance (`# States`, `# Edge Cases`).
2. **Scale is the default assumption, not the exception.** A company with a few years of history can carry
   hundreds of thousands of posted lines against its busiest accounts (Cash, Trade Receivables, Trade
   Payables). This screen is cursor-paginated and virtualized from the first line of its specification, per
   `docs/api/API_PAGINATION.md`'s own worked example for exactly this table, not as a follow-up performance
   pass once someone complains (`# Performance`).
3. **Every number drills down.** Per the backend document's Business Goal 4 — "every number on every
   financial statement must drill down, in the UI, to the exact journal entry and source document that
   produced it, in at most three clicks" — this screen's primary interaction is not reading, it is reading
   *and then proving*. A GL line that cannot be clicked through to its journal entry, and from there to its
   source document, is a defect in this screen, not an acceptable simplification.

This document assumes the reader has `docs/frontend/FRONTEND_ARCHITECTURE.md` (App Router structure, data
layer, cache tuning, realtime), `docs/frontend/COMPONENT_LIBRARY.md` (every named component below),
`docs/frontend/RESPONSIVE_DESIGN.md` (the finance-table responsive ladder and virtualization rules),
`docs/frontend/ACCESSIBILITY.md` (table semantics — this screen is one of ACCESSIBILITY.md's own named
examples), `docs/frontend/DARK_MODE.md` (semantic color tokens), `docs/frontend/NAVIGATION_SYSTEM.md` (the
sidebar/nav tree), `docs/api/API_PAGINATION.md` and `docs/api/API_FILTERING_SORTING.md` (cursor and filter
mechanics), and `docs/accounting/GENERAL_LEDGER.md` (the backend module) open alongside it. Nothing in this
document introduces a new table, a new backend business rule, or a new API response shape that those
documents do not already establish or directly extend.

# Route & Access

| Field | Value |
|---|---|
| App Router path | `app/(app)/accounting/ledger/page.tsx` → `/accounting/ledger` |
| Route group / shell | `(app)`, nested under `app/(app)/accounting/layout.tsx` (sub-nav: Chart of Accounts \| Journal Entries \| Ledger \| Trial Balance) |
| Drill-down target (not owned by this screen) | `/accounting/journal-entries/[journalEntryId]` |
| Rendering mode | `export const dynamic = "force-dynamic"; export const fetchCache = "default-no-store";` — tenant-scoped, never statically cached |
| Primary permission | `accounting.ledger.read` |
| Summary/report data permission | `accounting.report.read` |
| Export permission | `accounting.report.export` |
| Read model | `ledger_entries` (posted-line projection) via `GET /accounting/ledger-entries`, plus `mv_account_balances`/`mv_account_running_balance` via `GET /accounting/reports/general-ledger` — never `journal_lines` directly (see `docs/accounting/GENERAL_LEDGER.md → Ledger Architecture`) |
| Realtime channel | `private-company.{company_id}.ledger.{account_id}` (company-wide `private-company.{company_id}.ledger` when no single account is scoped) |
| Deep-linkable state | Query string only: `?account_id=`, or `?account_id=` repeated/comma-joined when a summary account resolves to several leaf ids, `&fiscal_period_id=` or `&date_from=&date_to=` or `&range=`, `&customer_id=`, `&vendor_id=`, `&cost_center_id=`, `&project_id=`, `&department_id=`, `&branch_id=`, `&currency_code=`, `&amount_min=&amount_max=`, `&sort=`. No `[accountId]` dynamic segment — an account is state on the one route, exactly like Trial Balance's own scope parameters, so switching accounts is a client-side filter change, never a navigation. |

**Route naming.** The screen's product name and sidebar label are "General Ledger"
(`nav.accounting.generalLedger`), but the URL segment is the shorter `ledger`. This is not this document's
invention: `FRONTEND_ARCHITECTURE.md`'s own App Router tree already commits to it —
`ledger/page.tsx  # GET /api/v1/accounting/ledger-entries (cursor-paginated, virtualized)` — and
`NAVIGATION_SYSTEM.md`'s nav-tree entry matches it exactly — `{ id: 'accounting.ledger', labelKey:
'nav.accounting.generalLedger', href: '/accounting/ledger', permission: 'accounting.read' }`. No route named
`/accounting/general-ledger` exists anywhere else in the platform's committed App Router tree or nav tree;
using the shorter, already-wired segment here keeps this document from introducing a second, conflicting
path for the same screen. This mirrors a pattern the backend module itself uses throughout: the document is
titled "General Ledger" while the thing a table, an index, or — here — a URL actually points at is the
shorter, workhorse name (`ledger_entries`, not `general_ledger_entries`).

**Permission.** `accounting.ledger.read` is this screen's own granular permission, following the exact
naming pattern every sibling Accounting sub-screen already has in `NAVIGATION_SYSTEM.md`'s sub-navigation
table: `accounting.accounts.read` for Chart of Accounts, `accounting.journal.read` for Journal Entries,
`accounting.trial_balance.read` for Trial Balance. It refines — and, on this screen, supersedes for gating
purposes — the coarser `accounting.read` the nav tree currently lists against this specific item. This is a
strictly additive refinement, never a loosening: under the backend's default role matrix
(`docs/accounting/GENERAL_LEDGER.md → Permissions`), every role that holds `accounting.read` at all holds
it identically across every sub-screen, so a role granted `accounting.ledger.read` sees the sidebar entry,
the route resolves, and the page shell renders with no behavioral change versus the coarser gate today; the
refinement exists so a future, more granular role (e.g., a "Ledger Viewer" scoped to statements but not
exports) is expressible without a code change, exactly as `accounting.trial_balance.read` already is for
its own screen.

| Permission | Owner | Admin | Finance Manager | Accountant | Auditor | Employee | AI Agent |
|---|---|---|---|---|---|---|---|
| `accounting.ledger.read` (page shell + line data) | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ (scoped, read-only) |
| `accounting.report.read` (balance-strip summary) | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ (scoped) |
| `accounting.report.export` (PDF/XLSX/CSV, email statement) | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |

This table reuses the backend document's own `accounting.read` / `accounting.report.read` /
`accounting.report.export` role grants verbatim (`docs/accounting/GENERAL_LEDGER.md → Permissions`) —
`accounting.ledger.read` is granted to the identical set as `accounting.read` because it is that
permission's own screen-scoped refinement. No permission on this screen is ever a write permission: there
is no row on this table that says "post," "reverse," or "void," because this screen cannot do any of those
things. Drilling into a journal entry from a GL line hands the user off to the Journal Entries screen,
where that screen's own `accounting.journal.post`/`.reverse` permissions apply independently; this screen
never inherits or shortcuts them, and an Auditor who can open every GL line's journal entry still cannot
post or reverse anything from there.

# Layout & Regions

```
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│ Accounting / General Ledger                                       [Explain] [Export ▾]  │  Breadcrumb + actions
├─────────────────────────────────────────────────────────────────────────────────────────┤
│ [ Account: Trade Receivables (1131) ▾ ]  [ Period: This fiscal period ▾ ]  [Filters (2)] │  Filter bar
├─────────────────────────────────────────────────────────────────────────────────────────┤
│  Opening balance      Period debit        Period credit       Closing balance           │
│  9,820.000 KWD        15,400.000 KWD      11,250.000 KWD       13,970.000 KWD            │  Balance strip
├───────────────────────────────────────────────────────────────────┬─────────────────────┤
│ Date ▾    Entry #        Description              Debit   Credit │ Balance │  ┌────────┤
│ ──────────────────────────────────────────────────────────────── │ ─────── │  │ AI panel│
│ Jul 01    JE-2026-000390 Invoice INV-2026-3301…    1,200.000   —  │ 11,020.0│  │(collap- │
│ Jul 08 ⚑  JE-2026-000418 Reclass — Accrued Exp…       —     650  │ 10,370.0│  │  sible  │
│ Jul 15    JE-2026-000271 Receipt RCPT-2026-1187…      —     800  │  9,570.0│  │ below   │
│ …(virtualized rows, cursor-paginated — 200+ rows scroll smoothly)…│         │  │  3xl:)  │
├───────────────────────────────────────────────────────────────────┴─────────┴───────────┤
│ Closing balance (sticky footer)                                    13,970.000 KWD       │
└─────────────────────────────────────────────────────────────────────────────────────────┘
```

**Breadcrumb + page actions.** `Accounting / General Ledger`, collapsing to a back-chevron + title below
`lg:` per `NAVIGATION_SYSTEM.md`'s Top App Bar adaptation. Two actions sit at the trailing edge: **Explain**
(opens the AI panel's ledger-activity explanation scoped to the current account/period; gated on
`accounting.ledger.read`, since it is a read-only explanation of data the viewer can already see) and
**Export** (a `DropdownMenu` of PDF/XLSX/CSV, plus a scoped-only "Email statement" item, gated by
`accounting.report.export`).

**Filter bar.** Three controls: `AccountPicker` (required — the canvas renders its own prompt empty state
until an account is chosen), `PeriodPicker` (defaults to `{ mode: 'relative', range: 'this_fiscal_period' }`),
and a `Filters` trigger carrying a count badge for the secondary filters (customer/vendor, cost center,
project, department, branch, currency, amount range) that open in a `Sheet` rather than crowding the bar —
mirroring the Trial Balance/Journal Entries filter-bar convention exactly. Below `md:`, the whole bar
collapses to one "Filters" button, per `RESPONSIVE_DESIGN.md`'s five-rule finance-table ladder.

**Balance strip.** Four compact stat cells — Opening balance, Period debit, Period credit, Closing balance —
sourced from the single bounded summary call (`# Data & State`), never from summing visible rows. These are
a screen-specific composition (`LedgerBalanceStrip`, `components/accounting/ledger-balance-strip.tsx`), not
full `KpiTile`s: an opening/closing balance is a deterministic ledger fact with a server-verified
computation, not an AI-estimated figure, so `KpiTile`'s `confidence`/`sparklineData` props are simply
irrelevant here rather than omitted for effect — attaching a confidence score to a number that carries no
uncertainty would itself be misleading, and the platform's dashboard widgets reserve `ConfidenceBadge` for
exactly that reason.

**Main grid.** The account-activity table itself: Date, Entry #, Description, Debit, Credit, running
Balance, plus lower-priority columns (Cost Center/Project, Source, secondary Currency) visible from `xl:`
up. This is the region with the most engineering weight in the document and is specified in full in
`# Components Used`, `# Data & State`, and `# Responsive Behavior`.

**AI panel.** A collapsible companion region for the AI layer's ledger-activity explanation and any open
anomaly flags touching the currently visible account/range — an overlay `Sheet` below `3xl:`, a persistent
360px rail at `3xl:` and above, matching `LAYOUT_SYSTEM.md`'s fourth-shell-region "AI Rail" and the same
`3xl:` threshold `RESPONSIVE_DESIGN.md` uses for the AI Command Center's own Ask AI panel (`# AI
Integration`).

**Sticky footer.** The closing balance for the current filtered range stays pinned to the bottom of the
scrollable table region at every tier from `md:` up, so an accountant scrolling through hundreds of lines
never loses sight of the number the lines above are supposed to sum to — the same rule `RESPONSIVE_DESIGN.md`
states for Trial Balance's totals row, applied here to a single closing-balance cell instead of a full
debit/credit total pair.

# Components Used

| Region | Component(s) | Notes |
|---|---|---|
| Breadcrumb + actions | `Breadcrumb` (Topbar region), `Button`, `DropdownMenu`, `Can` | `Can permission="accounting.report.export"` wraps the Export trigger; Explain has no extra permission gate beyond the page's own `accounting.ledger.read` |
| Account filter | `AccountPicker` | `postableOnly={false}` — unlike `JournalLineGrid`'s picker (which only offers leaf accounts one can post to), this screen's `AccountPicker` also lists non-leaf (summary) accounts, because selecting one switches the query to a resolved leaf-id list (`# Interactions & Flows`); `controlAccountFilter` unset |
| Period filter | `PeriodPicker` | `mode="relative"` default, switchable to `"fiscal_period"` or `"date_range"` |
| Secondary filters | `Sheet`, `Select` (customer/vendor, cost center/project/department/branch), `Input` (amount range), `CurrencySelect` | Opened from the "Filters (n)" trigger; merges into the same `filter[...]` object the primary pickers populate |
| Balance strip | `LedgerBalanceStrip` (`components/accounting/ledger-balance-strip.tsx`, screen-specific) | Internally four `AmountCell`s (`emphasis="strong"`) inside plain `Card` cells — not `KpiTile` (`# Layout & Regions`) |
| Main grid (`md:` and up) | `LedgerTable` (`components/accounting/ledger-table.tsx`, screen-specific — the name `FRONTEND_ARCHITECTURE.md`'s own folder-structure comment already reserves for this exact screen) | Wraps `DataTable`'s `ResponsiveColumnDef`/sticky-column pattern (`RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`); date column pinned via `sticky start-0`; every cell composed from `AmountCell`, `StatusPill`, `CurrencyTag` |
| Main grid (below `md:`) | `LedgerEntryCard` (`components/accounting/ledger-entry-card.tsx`) | Header = Entry # + `StatusPill`; body = date/debit/credit/running-balance as a two-column definition list; footer = "View journal entry" |
| Row status | `StatusPill domain="journal_entry"` | A GL line's parent entry is always `posted` or `reversed` here — `draft`/`voided` entries never post, so they never produce a `ledger_entries` row and never appear on this screen (`# Edge Cases`) |
| Multi-currency | `CurrencyTag emphasis="muted"` | Rendered per row only when that line's `currency_code` differs from the account's own primary/base currency |
| Anomaly indicator | `ConfidenceBadge` (`showLabel={false}`, `size="sm"`), `Tooltip` | Rendered only on rows with an open flag from `GET /accounting/ai/flags` (`# AI Integration`) |
| Drill-down | `LedgerDrillDownSheet` (`components/accounting/ledger-drill-down-sheet.tsx`, `Sheet`-based) | Journal entry preview + source-document links (`# Interactions & Flows`) |
| AI panel | `LedgerExplanationCard` (`components/accounting/ledger-explanation-card.tsx`), `ConfidenceBadge`, `Tooltip`, an expandable contributing-entries list | Deliberately **not** the full `AIProposalPanel` — there is nothing to accept, send for approval, or dismiss about a read-only explanation; no Accept/Reject footer exists on this screen |
| Loading | `Skeleton`, shaped to `LedgerTable`'s exact row/column geometry | Never a generic spinner, per the platform's "never blank" rule |
| Empty / error | `EmptyState`, `ErrorState` | Three distinct empty states — see `# States` |
| Export menu | `DropdownMenu`, `useApiToast` | Toast on completion/failure via the standard API-envelope-to-toast mapping |

`LedgerTable`'s prop shape extends the same `ResponsiveColumnDef<TData>` pattern `RESPONSIVE_DESIGN.md`
already defines for Trial Balance, without that screen's `groupBy` concept (a GL line never groups by
account type — there is exactly one account, or one resolved family of leaf accounts, in view at a time):

```tsx
// components/accounting/ledger-table.tsx
interface LedgerTableProps {
  lines: LedgerEntry[];
  currencyCode: string;               // the scoped account's own primary currency
  baseCurrencyCode: string;           // the company's base currency, for the running-balance column
  sortDirection: "asc" | "desc";      // chronological vs. recent-first — see Data & State → Sort
  onDrillDown: (line: LedgerEntry) => void;
  flaggedLineIds: Set<number>;        // from GET /accounting/ai/flags, for the inline ConfidenceBadge
  isFetchingNextPage: boolean;
}
```

# Data & State

## Endpoints

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/api/v1/accounting/accounts` | `accounting.ledger.read` | `AccountPicker`'s data source; `postableOnly=false` so summary accounts are selectable |
| GET | `/api/v1/accounting/fiscal-periods` | `accounting.ledger.read` | `PeriodPicker`'s data source in `fiscal_period` mode |
| GET | `/api/v1/accounting/reports/general-ledger` | `accounting.report.read` | Bounded summary — opening balance, period debit/credit totals, closing balance, account metadata, currency — seeds `LedgerBalanceStrip`. `meta.pagination: null` (bounded by the number of accounts requested, exactly like the Trial Balance example in `docs/accounting/GENERAL_LEDGER.md → Reports`); never itself paginated |
| GET | `/api/v1/accounting/ledger-entries` | `accounting.ledger.read` | The scrollable line rows. Cursor-paginated only — this is the exact endpoint `docs/api/API_PAGINATION.md` uses as its own worked example for keyset pagination (`filter[account_id]=101&sort=-posted_at&per_page=50`), and the exact endpoint `FRONTEND_ARCHITECTURE.md`'s route-tree comment and `useLedgerEntries` hook already name for this screen |
| GET | `/api/v1/accounting/journal-entries/{id}` | `accounting.ledger.read` (view scope) | Drill-down target: the full entry with its lines, opened from any GL row |
| POST | `/api/v1/accounting/ai/explain-activity` | `accounting.read` | General Accountant Agent's narrative explanation of the current account/period's movement — path and permission exactly as declared in `docs/accounting/GENERAL_LEDGER.md → API` |
| GET | `/api/v1/accounting/ai/flags` | `accounting.read` | Open anomaly/duplicate flags scoped to the visible account/date range, for the inline `ConfidenceBadge` |
| POST | `/api/v1/accounting/reports/general-ledger/export` | `accounting.report.export` | Generates the PDF/XLSX/CSV export (or, when a customer/vendor is scoped, the "Email statement" send) for the current filtered scope — the `{type}` slot of the backend's declared `POST /api/v1/accounting/reports/{type}/export` |

The bounded summary and the cursor-paginated lines are deliberately two separate calls rather than one
nested response, for the same reason `API_PAGINATION.md` insists `data` is always a flat array on a
paginated collection endpoint: nesting a cursor-paginated `lines[]` array one level inside a summary
response's `data.lines` would contradict every cursor-mode example in that document. The summary is bounded
(one row per requested account, `meta.pagination: null`, exactly like the backend document's own Trial
Balance JSON example); the lines are the array `GET /accounting/ledger-entries` already returns under
standard cursor pagination.

**Filters use the platform's bracketed filter grammar**, per `docs/api/API_FILTERING_SORTING.md`:
`filter[account_id]`, `filter[entry_date][between]` (or the named `range` shortcut, e.g.
`range=this_fiscal_period`), `filter[customer_id]`, `filter[vendor_id]`, `filter[cost_center_id]`,
`filter[project_id]`, `filter[department_id]`, `filter[branch_id]`, `filter[currency_code]`, and
`filter[base_debit_amount][between]`/`filter[base_credit_amount][between]` for the amount-range secondary
filter. `range` and an explicit `filter[entry_date][between]` are mutually exclusive on the same request,
per that document's own rule — the `PeriodPicker`'s `relative` mode sends `range`, its `date_range` mode
sends the explicit `between` pair, and the two modes are never combined in one request.

**Server-computed running balance.** Each row returned by `GET /accounting/ledger-entries` includes
`base_debit_amount`, `base_credit_amount`, and `running_balance` (the latter sourced from
`mv_account_running_balance`'s window function — `SUM(signed_base_amount) OVER (PARTITION BY company_id,
account_id ORDER BY entry_date, id)`, per `docs/accounting/GENERAL_LEDGER.md → Performance → Materialized
Views`). The frontend never accumulates this figure itself. This is also *why* this screen's default sort
key is `entry_date, id` rather than `posted_at, id`: the running balance the API returns is only the correct
number for the order the client requested if that order matches the window function's own `ORDER BY`
clause, and that clause is defined over `entry_date, id` — the accounting date, not the technical posting
timestamp. Sorting this screen by `posted_at` instead would still be a valid request, but the returned
`running_balance` values would no longer read as a sensible running total against a `posted_at`-ordered
list, so this screen intentionally does not expose that alternate sort.

## Sort

Default sort is `-entry_date,-id` (recent-first). A visible toggle switches to `entry_date,id`
(chronological, oldest-first — the literal "statement order" a printed or exported Account Statement uses).
Because `(company_id, account_id, entry_date, id)` is a single B-tree index, both directions are served by
one index with equal cost — Postgres B-tree indexes scan forward or backward natively, so this screen's two
sort directions do not require two separate index definitions. Per `API_PAGINATION.md → Sorting
Interaction`, a cursor is cryptographically signed against the exact `sort` string that produced it;
toggling direction always resets `cursor` to `null` and refetches from the first page rather than attempting
to reuse a now-invalid cursor — sending a stale cursor under a new sort returns `422 CURSOR_SORT_MISMATCH`,
which this screen avoids by construction rather than by handling the error.

## Query keys, hooks

```ts
// lib/api/query-keys.ts — extends the exact factory FRONTEND_ARCHITECTURE.md already declares
export const ledgerKeys = {
  all: ["accounting", "ledger-entries"] as const,
  infinite: (filters: LedgerFilters) => [...ledgerKeys.all, "infinite", filters] as const,
  summary: (filters: LedgerSummaryFilters) => [...ledgerKeys.all, "summary", filters] as const,
  explanation: (filters: LedgerSummaryFilters) => [...ledgerKeys.all, "explanation", filters] as const,
  flags: (filters: LedgerSummaryFilters) => [...ledgerKeys.all, "flags", filters] as const,
};
```

```ts
// lib/api/hooks/use-general-ledger.ts
export function useLedgerSummary(filters: LedgerSummaryFilters) {
  return useQuery({
    queryKey: ledgerKeys.summary(filters),
    queryFn: () => api.get<LedgerSummary>("/accounting/reports/general-ledger", { params: filters }),
    enabled: filters.accountIds.length > 0,   // never fires with no account scoped
    staleTime: 0,                              // "Live/derived figures" data class — FRONTEND_ARCHITECTURE.md
  });
}

export function useLedgerEntries(filters: LedgerFilters, sort: "asc" | "desc") {
  return useInfiniteQuery({
    queryKey: ledgerKeys.infinite({ ...filters, sort }),
    queryFn: ({ pageParam }) =>
      api.get<LedgerPage>("/accounting/ledger-entries", {
        params: {
          ...toFilterParams(filters),                                // filter[account_id][in]=…&filter[entry_date]…
          sort: sort === "asc" ? "entry_date,id" : "-entry_date,-id",
          cursor: pageParam,
          per_page: 50,
        },
      }),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.meta.pagination.next_cursor,
    enabled: filters.accountIds.length > 0,
    staleTime: 0,   // ledger-entries is explicitly a "Live/derived figures" resource, kept fresh via Realtime
  });
}

export function useExplainLedgerActivity() {
  return useMutation({
    mutationFn: (filters: LedgerSummaryFilters) =>
      api.post<AiExplanation>("/accounting/ai/explain-activity", filters),
  });
}

export function useLedgerAiFlags(filters: LedgerSummaryFilters) {
  return useQuery({
    queryKey: ledgerKeys.flags(filters),
    queryFn: () => api.get<AiFlag[]>("/accounting/ai/flags", { params: { ...filters, module: "general_ledger" } }),
    enabled: filters.accountIds.length > 0,
    staleTime: 10_000,   // AI feeds data class, per FRONTEND_ARCHITECTURE.md's cache-tuning table
    refetchOnWindowFocus: true,
  });
}
```

`filters.accountIds` (an array, even for a single leaf account) gates every query with `enabled: … .length >
0` specifically so the screen never fires an unscoped, company-wide ledger scan merely because the page
mounted before a user picked an account — see `# States` for the resulting prompt empty state and
`# Performance` for why an unscoped walk is discouraged even for a viewer who technically holds the
permission.

## Realtime

`private-company.{company_id}.ledger.{account_id}` (or the company-wide `private-company.{company_id}.ledger`
when no single account is scoped) follows the platform's fixed `private-company.{id}.<feature>[.{sub_id}]`
channel convention. A `journal.posted` event whose `affected_account_ids[]` includes the currently-scoped
account — or a `journal.reversed` event on an entry that already touched it — does not silently rewrite
rows underneath a scrolling user; `ACCESSIBILITY.md` names this screen explicitly as its own worked example
of the rule: "if another user posts a journal entry to a General Ledger table the Auditor is currently
tabbing through, the correct behavior is a polite, non-disruptive banner — '3 new entries posted —
Refresh' — not a live splice of new rows into the list." The banner walks `prev_cursor` toward newest only
when the user explicitly clicks it, combined with the realtime channel purely for the *notice*, never a
`cursor=null` poll on a timer:

```ts
// lib/api/hooks/use-ledger-realtime.ts
"use client";
import { useEffect, useState } from "react";
import { echo } from "@/lib/realtime/echo";

export function useLedgerRealtime(companyId: string, accountId: number | null) {
  const [hasNewActivity, setHasNewActivity] = useState(false);

  useEffect(() => {
    const channelName = accountId ? `company.${companyId}.ledger.${accountId}` : `company.${companyId}.ledger`;
    const channel = echo.private(channelName);
    const onActivity = (e: { affected_account_ids: number[] }) => {
      if (!accountId || e.affected_account_ids.includes(accountId)) setHasNewActivity(true);
    };
    channel.listen(".journal.posted", onActivity).listen(".journal.reversed", onActivity);
    return () => { echo.leave(channelName); };
  }, [companyId, accountId]);

  return { hasNewActivity, dismiss: () => setHasNewActivity(false) };
}
```

## AI agents feeding this screen

| Agent | Role on this screen | Autonomy |
|---|---|---|
| General Accountant Agent | "Explain this activity" narrative (`# AI Integration`) | Suggest-only, read/explain, no write |
| Fraud Detection Agent + Auditor Agent | Inline anomaly/duplicate flags on individual rows | Suggest-only; flags, never blocks |

The Forecast Agent and Reporting Agent, both named against the General Ledger module in
`docs/accounting/GENERAL_LEDGER.md → AI Responsibilities`, feed the Dashboard and scheduled digests
respectively, not this screen — a screen that shows every ledger line exactly as it happened is not the
natural home for a forward-looking forecast or a periodic summary digest, and this document does not
manufacture a UI slot for them here.

# Interactions & Flows

**No account selected (first load).** The canvas renders the `EmptyState` "Choose an account to see its
activity" and no query fires (`# Data & State`'s `enabled` gate) — this is the screen's true initial state,
distinct from a zero-activity result for an account that *is* selected.

**Selecting a leaf account.** The common case: `filter[account_id]=1131` is sent as a one-element array,
`LedgerBalanceStrip` and `LedgerTable` both fetch, and the URL updates to `?account_id=1131`.

**Selecting a summary (non-leaf) account.** `AccountPicker` resolves the chosen summary account's leaf
descendants from the same Chart-of-Accounts tree data already loaded for the picker (no extra request), and
the screen queries `filter[account_id][in]=<id1>,<id2>,…` instead of a single id — a direct use of the
generic `in` operator `docs/api/API_FILTERING_SORTING.md` already defines for `bigint`-typed filter fields,
requiring no new backend capability. The balance strip's opening/closing figures become the *sum* across
every resolved leaf account for the range (still server-computed, via the same bounded
`reports/general-ledger` call with multiple `account_id`s), and the table's rows interleave activity from
every resolved account in one chronological stream, each row's account named explicitly in a
`priority: 3` column that becomes visible from `lg:` (below that, the account name is folded into the
`LedgerEntryCard`'s body). Because `in` accepts at most 100 values per request (`API_FILTERING_SORTING.md →
Supported Operators`), a summary account resolving to more than 100 leaves shows a non-blocking warning in
the picker ("This account has 214 sub-accounts — narrow your selection to see combined activity") and falls
back to single-account mode until the user picks a narrower summary node or an individual leaf (`# Edge
Cases`).

**Switching period/date range.** `PeriodPicker`'s three modes each update the URL and reset the
`useInfiniteQuery`'s cursor to `null` (a filter narrowing the date window is exactly the kind of "target
fundamentally changed" case `API_PAGINATION.md` recommends resetting for, rather than resuming an old
cursor inside a new window that may not even contain it).

**Secondary filters.** Opening the "Filters (n)" `Sheet` and applying customer/vendor, cost center,
project, department, branch, currency, or amount-range filters merges into the same filter object; applying
a `customer_id` or `vendor_id` filter against a control account is what promotes the screen into its Account
Statement framing (`# Purpose`) — the moment that combination is active, the page action row gains the
"Email statement" item next to Export, and the balance strip's header line changes from the bare account
name to "{Account} — {Customer/Vendor name}."

**Sort toggle.** Switching between "Recent first" and "Chronological (statement order)" always refetches
from the first page (`# Data & State → Sort`); the running-balance column's values are correct for either
direction because they are server-computed per the exact order requested, not accumulated client-side.

**Drill-down to journal entry and source document.** Clicking any row (or its Entry # / amount cells)
opens `LedgerDrillDownSheet`, a compact preview of the full journal entry — every line of that entry, not
just the one line that touched this account, so the user can see the whole balanced picture in context:

```
┌─ JE-2026-000390 · posted Jul 01 ────────────────────────── ✕ ┐
│ Invoice INV-2026-3301 — Al Zahra Trading Co.                  │
├───────────────────────────────────────────────────────────────┤
│ Account                       Debit        Credit             │
│ 1131 · Trade Receivables      1,200.000        —                │
│ 4100 · Sales Revenue               —      1,200.000             │
├───────────────────────────────────────────────────────────────┤
│ Source: Invoice INV-2026-3301 → Sales                          │
│ Attachments: 1 (delivery-note.pdf)                              │
├───────────────────────────────────────────────────────────────┤
│              [View attachments]      [Open journal entry →]     │
└───────────────────────────────────────────────────────────────┘
```

The Sheet resolves `journal_entries.source_type`/`source_id` to a human label and a real route via a small,
fixed mapping (`invoice → /sales/invoices/{id}`, `bill → /purchasing/bills/{id}`, `receipt →
/sales/receipts/{id}`, `vendor_payment → /purchasing/vendor-payments/{id}`, `payroll_run →
/payroll/runs/{id}`, `bank_transaction → /banking/transactions/{id}`, `stock_adjustment →
/inventory/movements/{id}`); a `source_type` outside this fixed set (a manual entry, or a source module not
yet wired into the mapping) renders the source line as plain text with no broken link rather than a `404`.
Attachments come from the same `GET /accounting/journal-entries/{id}` payload Journal Entries' own Detail
page already nests (`docs/frontend/JOURNAL_ENTRIES.md`'s Attachments tab) — "View attachments" deep-links to
`/accounting/journal-entries/{id}?tab=attachments` rather than duplicating a file-preview UI on this screen.
"Open journal entry" navigates to the full Detail page and closes the Sheet on navigate, never stacking a
second overlay on top of a route change, matching the same rule Trial Balance's own drill-down uses.

**Explain this activity.** Clicking **Explain** in the page header (or, contextually, on the balance strip)
calls `useExplainLedgerActivity` with the current scope and renders `LedgerExplanationCard` inline above the
table (`# AI Integration`).

**Export / Email statement.** The `DropdownMenu` offers PDF/XLSX/CSV always; "Email statement" appears only
in the Account-Statement-scoped state described above, opening a small `Dialog` confirming the recipient
(defaulted from the customer's/vendor's own contact record) before calling
`POST /accounting/reports/general-ledger/export` with `delivery: "email"`.

**Realtime "new activity" banner.** Described fully in `# Data & State → Realtime`; clicking "Refresh"
resets the cursor to `null` and refetches the first page rather than attempting an in-place splice.

# AI Integration

The AI layer's entire surface on this screen is two things: an on-demand narrative explanation, and passive
anomaly flags on individual rows. There is no accept/reject decision anywhere on this screen, because there
is nothing here for a human to approve — every figure is already a posted, immutable fact; the AI's job is
only to help a human *understand* it faster.

**Explain this activity.** `LedgerExplanationCard` renders the General Accountant Agent's response from
`POST /accounting/ai/explain-activity`, whose structured shape is fixed by
`docs/accounting/GENERAL_LEDGER.md → AI Responsibilities → Explain Ledger Activity`:
`{ summary, contributing_entries: [{ journal_entry_id, amount, weight }], confidence }`. The card renders
`summary` as plain narrative text, a `ConfidenceBadge` (via `normalizeConfidence`, since the backend's
`confidence` field is already `0–1`), and an expandable list of `contributing_entries`, each a one-click
jump into `LedgerDrillDownSheet` for that entry. When the agent's own clustering does not fully reconcile
the requested delta, it says so explicitly in `summary` ("…the remainder, KWD 42.00, is an unreconciled
residual") rather than forcing a narrative that overstates its own coverage — the card renders that residual
line exactly as returned, with no attempt to round it away or hide it.

**Anomaly and duplicate flags.** `GET /accounting/ai/flags` returns open flags from the Fraud Detection and
Auditor agents scoped to the visible account/date range. Each flagged row renders an icon-only
`ConfidenceBadge` (`showLabel={false}`) with a `Tooltip` carrying the flag's `reasoning` — hovering or
focusing it is the entire interaction; there is no inline Approve/Dismiss action on this screen, because
resolving a finding is the Trial Balance/Journal Entries screens' job (`docs/accounting/GENERAL_LEDGER.md`'s
Fraud Detection agent routes its findings to Owner/Admin/Finance Manager/Auditor simultaneously, not to a
button on every screen that happens to render the flagged row). Clicking the badge instead opens
`LedgerDrillDownSheet` for that row pre-scrolled to the flag's own explanation, so "understand why this was
flagged" and "see the full entry it belongs to" are the same click.

**Unavailable AI.** If the explain or flags call fails, times out, or AI is disabled for the company, the
page header's Explain button disables with a tooltip ("AI explanation unavailable right now") and the
per-row `ConfidenceBadge`s simply do not render — every other capability on the screen (reading, filtering,
sorting, drilling down, exporting) remains fully available. AI here is strictly additive, never a gate on
anything a human needs to do their job.

# States

| State | Trigger | Rendering |
|---|---|---|
| No account selected | Initial load, or account cleared | `EmptyState`: "Choose an account to see its activity"; no query fires |
| Loading (account chosen, first paint) | Query in flight, no cached data | `Skeleton` shaped to `LedgerBalanceStrip` + `LedgerTable`'s row/column geometry, shimmer sweep; never a generic spinner |
| Loaded, has activity | Normal case | Balance strip + table render; sticky closing-balance footer visible |
| Loaded, zero activity in range | Account selected and valid, but no posted lines match the current filters | Lighter `EmptyState`: "No activity for {account} in {period}" — distinct from the no-account-selected state; balance strip still renders (opening = closing, zero movement) since that itself is a real, valid answer |
| Filtered to zero | Secondary filters (e.g. a cost center) legitimately exclude every row | `EmptyState` variant: "No lines match your filters" with a "Clear filters" action, never conflated with genuine zero-activity |
| Fetching next page | Scrolling near the end of the loaded window | Trailing row-shaped `Skeleton` strip below the last loaded row; `LedgerTable`'s `isFetchingNextPage` prop |
| Realtime new activity available | A `journal.posted`/`journal.reversed` event matches the scoped account(s) | Dismissible, non-disruptive banner above the table: "New activity posted — Refresh"; existing rows never mutate in place |
| Partial rollup (summary account, too many leaves) | Selected summary account resolves to more than 100 leaf accounts | Non-blocking warning in `AccountPicker`; falls back to requiring a narrower selection (`# Edge Cases`) |
| Error | `403`/`404`/`5xx`/network failure on any request | `ErrorState` with a retry action; a `403` discovered mid-session (permission revoked while the tab was open) collapses only the affected control, never the whole page |

# Responsive Behavior

This screen follows `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` exactly, which names General
Ledger directly, alongside Trial Balance and Balance Sheet, as one of the "genuinely tabular numeric grids
that do not decompose into a card" — this section states the outcome for this specific screen, not a new
mechanism.

**Mobile (`base`–`sm`).** The header collapses to a back-chevron + title; the account/period controls stack
into a horizontally-scrollable chip row; the secondary filters collapse behind a single "Filters (n)"
trigger opening a `Sheet`. The canvas becomes a stack of `LedgerEntryCard`s — one per line, showing
`priority: 1–2` fields (date, entry #, debit or credit, running balance) plus a `StatusPill` for
reversed entries and a flag `Badge` where relevant — each ending in a "View journal entry" button that
opens `LedgerDrillDownSheet` directly. The balance strip and a collapsed realtime banner stay pinned above
the card list; the closing balance is always the last visible figure on screen without scrolling further,
matching the platform rule that a report's totals must never leave the viewport while the data those totals
summarize is being reviewed.

**Tablet and up (`md:`+).** The canvas is a real `<table>` with the Date column pinned via `sticky start-0`
(the logical start — see `# RTL & Localization`) and the Debit/Credit/Balance columns scrolling horizontally
inside their own `overflow-x-auto` region; the closing-balance `<tfoot>` is `sticky bottom-0`. Cost
Center/Project and Source columns (`priority: 4`) hide below `xl:` and reappear at `xl:`+; Description
(`priority: 2`) shows from `md:`; Date/Entry #/Debit/Credit/Balance (`priority: 1`) are always visible.

**Touch and virtualization.** Row-action targets meet the platform's 44×44px touch minimum regardless of
the 40px (desktop, mouse) vs. 56px (mobile card) density-driven row height. Once the loaded window exceeds
roughly 200 rows — the common case for a busy Cash or Trade Receivables account after even a few months —
`LedgerTable` mounts `@tanstack/react-virtual`, with `estimateSize` reading the active breakpoint tier
rather than a single hardcoded value, so the estimate matches whichever row shape (dense desktop row vs.
mobile card) is actually rendering.

**AI panel.** Overlay `Sheet` below `3xl:`; persistent 360px rail at `3xl:` and above, exactly matching
`RESPONSIVE_DESIGN.md`'s statement that Ultra Wide is "the one tier where QAYD adds a genuinely new region
rather than more columns of the same thing."

# RTL & Localization

Every string on this screen ships as an EN/AR pair, and the screen inherits `THEMING.md`'s RTL-as-a-theming-
dimension and `LAYOUT_SYSTEM.md`'s RTL layout mirroring without a single per-component RTL branch, plus the
module-specific applications below.

- **Bilingual accounts.** Every row and the balance strip's header render `account_name_ar` under an Arabic
  session and fall back to `account_name_en` only if the Arabic name is genuinely absent — never the
  reverse.
- **Debit/Credit column order is physically fixed, never mirrored**, because Debit-before-Credit
  left-to-right is a universal accounting convention independent of interface language, and must match
  printed/exported statements:

  ```tsx
  <TableCell dir="ltr" className="text-end tabular-nums">{formatAmount(line.debitAmount, currencyCode)}</TableCell>
  <TableCell dir="ltr" className="text-end tabular-nums">{formatAmount(line.creditAmount, currencyCode)}</TableCell>
  ```

- **Numeric alignment is physically fixed**: every money and running-balance column stays `text-end` in
  both directions via `AmountCell`'s own `align="end"` default, so a bilingual Finance team scanning
  magnitude by decimal alignment sees amounts land on the same edge regardless of session language.
- **Entry numbers, dates, account codes, and confidence percentages stay LTR-isolated** inside Arabic
  sentences (the AI explanation's narrative, the realtime banner's copy) via a shared bidi-isolation wrapper
  (`dir="ltr"` + `unicode-bidi: isolate`), so a `JE-2026-000390` reference never visually reorders inside a
  right-to-left paragraph.
- **AI explanation text is generated in the viewer's session locale** and rendered as plain translated
  prose, never re-translated client-side; Arabic accounting terminology is used precisely — دفتر الأستاذ
  (General Ledger), مدين (Debit), دائن (Credit), ترحيل (Posting), قيد (Entry).
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching Gulf
  financial-document convention and `AmountCell`'s own platform-wide implementation — QAYD never switches a
  financial figure to Eastern Arabic-Indic digits.
- **Directional chrome mirrors; content chrome does not.** `LedgerDrillDownSheet` and the AI panel's overlay
  `Sheet` slide from the logical end edge (left in RTL); breadcrumb chevrons mirror; the flag/anomaly icons
  and every status glyph do not, per `ICONOGRAPHY.md`'s directional-icon table — an "inflow vs. outflow"
  glyph encodes a financial direction, not a reading direction, and must look identical in both languages.
- **The pinned Date column uses `sticky start-0`, never `sticky left-0`** — `RESPONSIVE_DESIGN.md` calls
  this out as "the single highest-risk line of code" in its entire responsive specification, because
  `left-0` is syntactically valid and visually correct in an English demo, and silently pins the *wrong*
  physical edge the instant this screen is viewed in Arabic.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`. Nothing on this screen
references a raw hex value or a Tailwind palette utility; every surface, border, and status color resolves
through the semantic tokens `DARK_MODE.md → Token Mapping` defines.

- **Surfaces and elevation.** The page canvas sits on `--surface-canvas`; the balance strip's cards, the
  table's own surface, and its sticky header/footer bands sit on `--surface-base`; `LedgerDrillDownSheet` and
  the AI panel sit on `--surface-raised`/`--surface-overlay`. Dark mode communicates elevation by the surface
  getting *lighter* toward the viewer (`sunken < canvas < base < raised < overlay`), not by shadow, so the
  sticky Date column and the sticky closing-balance footer both carry their paired `--border-subtle`/
  `--border-default` hairline in dark mode even where the light-mode equivalent leans on shadow alone — a
  borderless dark surface disappears against the near-black canvas, which `DARK_MODE.md` calls out as the
  single most common dark-mode regression its component library guards against.
- **Debit/credit figures never take a status color.** Every `AmountCell` in the table renders in
  `--text-primary` regardless of theme; only the realtime banner, the anomaly `ConfidenceBadge`, and the
  reversed-entry `StatusPill` ever carry `--status-success`/`--status-warning`/`--status-error`/
  `--status-info` (with their paired `-subtle` background tokens for any filled treatment) — a debit is not
  "bad" and a credit is not "good," and this screen never implies otherwise through color.
- **AI provenance uses the reserved AI accent, never a finance-semantic color.** `LedgerExplanationCard`'s
  border accent, the `Sparkles`-style glyph, and the per-row `ConfidenceBadge` fill all resolve through
  `--ai-accent`/`--ai-accent-subtle` — the same reserved hue `DARK_MODE.md` defines specifically so it is
  never reused for a success/warning/error meaning, keeping "this was AI-touched" and "this account balance
  is healthy/at-risk" visually distinct in both themes.
- **Contrast.** Every token pairing here is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text,
  including the sticky Date column's dividing border) in both themes, per `DARK_MODE.md → Color & Contrast
  In Dark` — the sticky border is treated as load-bearing, not decorative, so it is held to the 3:1 floor
  rather than exempted as a plain divider.
- **Print/export independence.** Exported PDFs and the emailed Account Statement always render in QAYD's
  fixed light/print palette regardless of the viewer's active theme, per `DARK_MODE.md → Exported PDFs
  always render light` — a statement emailed to a customer or forwarded to an auditor is never expected to
  open a dark-mode-aware viewer.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, which this screen implements without deviation and which
names General Ledger explicitly in two places worth calling out directly.

- **Plain accessible `<table>`, never the ARIA `grid` pattern.** `ACCESSIBILITY.md → Data Tables
  Accessibility` lists General Ledger by name among the tables "whose primary interaction is *read, sort,
  filter, paginate, click a row to navigate, click a row action*" — the heavier `grid` pattern is reserved
  exclusively for genuinely spreadsheet-like editing surfaces (the Journal Entry line editor, Bank
  Reconciliation's matching grid), and applying it here would add keyboard-handling complexity this
  screen's read-only interaction never needs.
- **The horizontally-scrolling region is itself keyboard-operable**: `role="region"` + `aria-label` +
  `tabIndex={0}` on the wrapper around `LedgerTable`'s money columns, so a keyboard user can focus the
  region and scroll it with arrow keys per the APG scrollable-region pattern, without that focus stop
  trapping `Tab`.
- **Sortable headers are real `<button>`s with `aria-sort` on the parent `<th>`**, never a clickable `<th>`
  or a `<div>`, for both the Date column's default sort toggle and any future column-level sort this screen
  adds.
- **Loading and streaming are announced, not silently swapped.** `<tbody aria-live="polite"
  aria-busy={isLoading}>` — a screen reader hears "Loading ledger entries…" and, on completion, the new row
  count, rather than experiencing a table that appears frozen while `useInfiniteQuery` fetches; the same
  `aria-busy` toggling covers `isFetchingNextPage` during virtualized scroll-triggered loads.
- **Row-level accessible names are unique, always.** The "View journal entry" action on every row (desktop
  icon button or mobile card footer button) carries `aria-label={t('a11y.openJournalEntry', { number:
  line.entryNumber })}` — "Open journal entry JE-2026-000390" — never a bare "Open," which is what
  `ACCESSIBILITY.md`'s `IconButton` lint rule requires for any icon-only control rendered inside a row
  `.map()`.
- **Realtime pushes never yank focus or silently splice rows.** Per `ACCESSIBILITY.md → Live regions`'s own
  worked example — written about this exact screen — a `journal.posted` event affecting the scoped account
  surfaces as a polite, dismissible banner, never a live insertion into the list an Auditor is currently
  tabbing through.
- **Amounts carry meaning beyond color.** Per `COMPONENT_LIBRARY.md → AmountCell`, "color is always a
  secondary signal — the leading `+`/`−` glyph carries the meaning on its own, so a colorblind user reading
  a monochrome print export loses nothing"; this screen never overrides that default with a color-only
  debit/credit treatment.
- **Every disabled control explains itself.** The Explain button disabled for a lack of AI availability
  carries `aria-describedby` naming the reason ("AI explanation unavailable right now"), worded distinctly
  from a control disabled for lack of permission ("Requires `accounting.report.export`") — the two causes
  are never collapsed into a generic "You can't do this," per `ACCESSIBILITY.md`'s RBAC-aware disabled
  control pattern.
- **Focus management on overlays.** `LedgerDrillDownSheet`'s `onOpenAutoFocus` moves focus to its heading;
  closing it (Escape, backdrop click, or navigating to the full journal entry) returns focus to the row that
  opened it.

# Performance

- **Cursor pagination, not offset, by construction.** `GET /accounting/ledger-entries` is cursor-only —
  there is no `page` parameter to accidentally reach for — so this screen's scroll cost stays O(per_page)
  regardless of how many pages a long session has already fetched, exactly the property
  `docs/api/API_PAGINATION.md → Performance` describes for the same endpoint.
- **Virtualization past ~200 rows.** `LedgerTable` mounts `@tanstack/react-virtual` once the loaded row
  count crosses roughly 200, per `RESPONSIVE_DESIGN.md → Virtualization at scale`, so a busy account's
  multi-thousand-line history never mounts more DOM nodes than are actually visible.
- **`total` is intentionally `null`; `total_hint` is a courtesy, not a control.** Per
  `API_PAGINATION.md → meta.pagination Response Shape`, an unbounded table's cursor response never carries
  an exact `total` (computing it would require the full scan cursor pagination exists to avoid); where the
  API exposes a Redis-cached `total_hint` (5-minute TTL), this screen may display it as an approximate
  count ("~48,000 entries") but never uses it to compute a page count or to drive any pagination math.
- **Scoped-by-default queries.** Every query is gated on at least one selected account
  (`# Data & State`); an unfiltered, company-wide cursor walk across every account's ledger is technically
  reachable by an Owner/Admin holding the permission but is never the screen's own default behavior, and the
  `AccountPicker`'s empty-selection prompt is what prevents it from happening by accident.
- **SSR-seeded first paint.** The header (breadcrumb, title) renders on the server; the balance strip and
  first page of lines for a resolved default scope (if the URL already carries `account_id`/period query
  params, e.g. from a bookmarked link or a Trial Balance drill-through) are prefetched server-side and
  hydrated into the client cache, so the first client-side `useQuery`/`useInfiniteQuery` call for those same
  keys resolves instantly rather than re-issuing a request the server already made.
- **Debounced filter changes.** Amount-range and search-like secondary filter inputs debounce 300ms before
  triggering a new query key, per `FRONTEND_ARCHITECTURE.md`'s platform-wide debounce rule, with
  `placeholderData: keepPreviousData` on the summary query so the balance strip does not flash empty between
  keystrokes.
- **Read-replica reads.** Both the summary and the line-list `GET` calls are ordinary list reads and are
  served from a Postgres read replica per the platform's default read-routing (`API_PAGINATION.md →
  Performance`); this screen takes advantage of that simply by not special-casing the request, and accepts
  the documented sub-100ms replica-lag tradeoff rather than forcing a primary read for a report view.
- **AI calls never block the table.** `useExplainLedgerActivity` and `useLedgerAiFlags` are independent
  queries/mutations that never gate `useLedgerEntries`'s own fetch or render — a slow or failed AI call
  degrades only the Explain button and the anomaly badges, never the core reading experience.

# Edge Cases

1. **Summary account resolving to more than 100 leaf descendants.** `filter[account_id][in]` is capped at
   100 values per `API_FILTERING_SORTING.md → Supported Operators`; `AccountPicker` warns and requires a
   narrower selection rather than silently truncating the id list to 100 and returning an incomplete view
   that looks complete.
2. **Account with zero posted activity in the selected range.** Renders the dedicated zero-activity
   `EmptyState` (`# States`) with the balance strip still shown (opening = closing, KWD 0.000 movement) —
   never an error, and never conflated with "no account selected."
3. **Reversed entry.** Both the original line and its reversing line are fully visible in the table, each
   correctly signed, each independently drillable — a reversal is new activity, not a redaction, and this
   screen never hides or grays out a reversed line's own row.
4. **Mixed-currency account.** A leaf account with no `currency_restriction` can legitimately show lines in
   more than one `currency_code`. Each row's Debit/Credit cells show that line's own transaction-currency
   amount via `AmountCell`, with a muted `CurrencyTag` appearing only when it differs from the account's
   primary currency; the running balance and the balance-strip figures are always base-currency
   (`base_debit_amount`/`base_credit_amount`/`signed_base_amount`), and the Balance column's header carries
   a persistent "(base currency)" note so a reader never assumes the running total is denominated in
   whatever currency the most recent row happened to use.
5. **Company base-currency change spanning the displayed range.** Historical lines keep the base amount and
   exchange rate that were frozen at posting time (Business Rule 6); this screen never re-expresses history
   in a newly adopted base currency, and a range that straddles the change date carries a footnote in the
   export disclosing the change date.
6. **Soft-deactivated account with historical balance.** Still fully browsable here — deactivation blocks
   *new* postings, never historical visibility — with an `Inactive` `Badge` next to the account name in the
   balance-strip header.
7. **Concurrent posting while a user is mid-scroll.** Per `API_PAGINATION.md → Consistency Under Writes`,
   a keyset cursor can never duplicate or skip a row the client has already seen; a new line landing ahead
   of the cursor's current position simply surfaces via the realtime "new activity" banner rather than
   appearing mid-scroll.
8. **Switching account, or switching company, mid-scroll.** Both reset `cursor` to `null` and discard the
   in-flight `useInfiniteQuery` state — a cursor issued while browsing one account (or one company) is never
   replayed against a different one, matching the platform's company-switch cache-discard contract and
   `API_PAGINATION.md`'s own guidance to reset on any context switch.
9. **AI unavailable, disabled, or returns a low-confidence explanation.** Never blocks reading, filtering,
   drilling down, or exporting (`# AI Integration`); the Explain button and the per-row badges are the only
   things that degrade.
10. **Export of a very large filtered range.** Mirrors the platform's async-report pattern
    (`report_runs`): a range whose estimated row count exceeds the export endpoint's synchronous threshold
    returns `202 Accepted` with a job id, and the Export menu's "Recent exports" list re-surfaces the
    finished file via a notification rather than holding a spinner open on the button.
11. **Session permission change mid-view.** If `accounting.report.export` is revoked while the Export menu
    is open, the next attempt's `403` collapses that item to its permission-denied state with a toast
    explaining why, rather than a stuck or silently-failing download — the frontend never assumes its own
    last-known permission snapshot is still valid.
12. **Drill-down to a `source_type` outside the fixed mapping.** Renders the source line as plain,
    non-linked text (`# Interactions & Flows`) rather than a broken link or a thrown error — a manual
    journal entry has no `source_type` at all, which renders identically (the "Source" line is simply
    omitted from the Sheet).

# End of Document
