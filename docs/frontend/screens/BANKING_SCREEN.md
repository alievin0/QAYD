# Banking Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: BANKING_SCREEN
---

# Purpose

This document is the structural screen specification for the Banking module's landing route,
`app/(app)/banking/accounts/page.tsx` — the page a Finance Manager, Treasury user, Senior Accountant,
Accountant, Auditor, or CEO actually lands on the instant they click "Banking" in the sidebar, since
`NAVIGATION_SYSTEM.md`'s module map points that entry (icon `Landmark`) directly at `/banking/accounts`
rather than at a bare `/banking` segment, which resolves to nothing (see **Edge Cases**). It is written
against the platform's `SCREEN DOC STRUCTURE` template so that it can be read, reviewed, and diffed
section-by-section alongside every other screen document in this series — Dashboard, Accounting, General
Ledger, Journal Entries, Trial Balance, Balance Sheet, Profit & Loss, Cash Flow, Bank Reconciliation, and
the AI Command Center — using the identical fourteen headings. It is the concrete, structured companion to
`docs/frontend/BANKING.md`, which specifies the same route in fuller prose; the two must never disagree.
Where this document is silent, `docs/frontend/BANKING.md`, `FRONTEND_ARCHITECTURE.md`,
`DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `RESPONSIVE_DESIGN.md`,
`DARK_MODE.md`, and `ACCESSIBILITY.md` govern, in that order; where this document appears to contradict
one of them on a fact — a route, a permission key, a component name, an endpoint — that is a defect in
one of the two documents to raise and resolve, never a decision an engineer resolves unilaterally in code.

Every screen in Banking answers a piece of the same question `docs/accounting/BANKING.md`'s own Purpose
section poses: *how much cash do we have, where does it sit, and does our book balance agree with the
bank's own record*. This screen — the module's landing surface — is where that question gets answered
first, at a glance, before a user drills into any single account, any single transaction, or the
reconciliation workbench that proves the numbers here are actually correct. Concretely, this screen
composes seven pieces of surface, each owned elsewhere in the platform's data and business-logic layers
and only rendered, never computed, here:

- **A live cash position band** — current, available, and restricted balances aggregated across every
  visible `bank_accounts` row, converted to the company's base currency, plus a liquidity-ratio read-out
  gated on `treasury.read`.
- **A bank-account card rail** — one card per `bank_accounts` row, each showing balance, available
  balance, currency, institution type, and status, and each a doorway into that account's own register.
- **A transactions table** — the searchable, sortable, filterable ledger of `bank_transactions` rows,
  carrying AI match provenance and permission-gated row actions.
- **A statement import entry point** — the on-screen doorway into the four ingestion channels
  (Open Banking, structured file, spreadsheet, scanned PDF) `docs/accounting/BANKING.md → Bank
  Reconciliation` defines, deliberately first-class here rather than tucked into a settings page.
- **Transfer and approval affordances** — a permission-gated "New transfer" entry point that always routes
  to a full page, plus inline approval cards for anything awaiting the caller's own sign-off under the
  Finance-Manager-then-CEO two-key chain.
- **An AI Treasury insight** — a dismissible briefing synthesizing the Treasury Manager's liquidity read,
  the Forecast Agent's cash-flow projection, and the CFO Agent's weekly narrative.
- **A link out, never a re-implementation, to Bank Reconciliation** — the workbench where the numbers this
  screen displays are actually proven correct against the bank's own statement.

This screen owns no business logic. It never computes a balance, never decides whether a reconciliation
match is good enough to auto-commit, never evaluates whether a transfer is under an auto-approval
threshold, and never lets an AI agent's confidence score substitute for a human's sign-off on money leaving
the company. Every figure rendered here was computed and validated by Laravel; every mutation this screen
triggers calls `/api/v1/banking/...` guarded by the exact permission key the API itself enforces
(`FRONTEND_ARCHITECTURE.md`, Principles 1 and 4).

# Route & Access

## App Router path

```
app/(app)/banking/
├── layout.tsx                            # Sub-nav: Accounts | Transactions | Transfers — bank.read gate
├── accounts/
│   ├── page.tsx                          # ★ THIS DOCUMENT — the Banking landing screen
│   └── [accountId]/page.tsx              # Single-account register — its own screen document
├── transactions/page.tsx                 # All-accounts ledger — same <BankTransactionsTable/>, wider default filter
├── reconciliation/
│   └── [bankAccountId]/page.tsx          # Bank Reconciliation workbench — see note below
└── transfers/
    ├── page.tsx                          # Transfers list
    └── new/page.tsx                      # Always a full page — never a quick-create modal
```

`accounts/page.tsx` is the only file this document specifies in depth. There is deliberately no
`banking/page.tsx` at the module's own root segment — see **Edge Cases** for what a bare `/banking` hit
resolves to. Note the reconciliation segment: `docs/frontend/BANKING.md`'s own route sketch names it
`reconciliations/[id]`, keyed by a specific `bank_reconciliations` row; `docs/frontend/
BANK_RECONCILIATION.md`, the screen document that actually owns that route, supersedes that sketch in
favor of `reconciliation/[bankAccountId]`, keyed by the account rather than a specific period, because a
Finance Manager thinks "reconcile NBK Operating," not "open reconciliation #4103" — the specific period,
where one is needed, is addressed with a `?reconciliation_id=` query parameter on that same route instead
of a second dynamic segment. This document links out using the superseding, current path exclusively;
any reference to the older sketch anywhere else in the docs tree should be read as resolved to it.

## Permission gate

| Control | Permission | Behavior if absent |
|---|---|---|
| Screen visible at all | `bank.read` | Sidebar entry and route both absent; a direct hit renders the shell's `403` boundary, never a silent redirect. |
| Liquidity Ratio tile, AI Treasury panel, Treasury Dashboard link | `treasury.read` (panel additionally requires `reports.read`) | The Liquidity tile is the one deliberate exception to "hide, don't disable" on this screen: it renders with an em dash, a lock glyph, and a tooltip naming the missing permission, because the tile's existence is not itself sensitive — see **Edge Cases**. The AI Treasury panel, by contrast, is omitted outright. |
| "Connect account" / edit account | `bank.account.create` / `bank.account.update` | Button omitted, not disabled — a role-structural gap, not a plan-tier upsell. |
| "Import statement" | `bank.reconcile` | Button omitted. |
| "New transaction" (manual entry) | `bank.transaction.create` | Button omitted. |
| Row action: Approve (first key) | `bank.payment.approve` | Omitted from the row menu; any inline approval card for that item renders its Approve/Reject pair disabled with a permission tooltip instead, since the card is already addressed to a specific approver. |
| Row action: Approve (final key) | `bank.payment.approve.final` | Same, and additionally disabled outright — not merely permission-gated — when the caller is the same user who already holds the first key on that item. |
| "New transfer" | `bank.transfer` | Button omitted; the Transfers sub-nav tab itself only needs `bank.read` to view. |
| Void / Reverse | `bank.transaction.void` / `bank.transaction.reverse` | Row action omitted. |
| Reconcile (row action into the workbench) | `bank.reconcile` | Omitted — stricter than this screen's own baseline `bank.read`. |
| Close / reopen a reconciliation period | `bank.reconcile.close` / `bank.reconcile.reopen` | Not surfaced here at all — owned entirely by the reconciliation workbench this screen only links to. |

## Roles

| Role | What renders on this screen |
|---|---|
| Owner, CEO, CFO | Everything: cards, cash position, every row action, "New transfer," the Treasury Dashboard link. Only the CEO's approval is ever the *final* key. |
| Finance Manager | Everything; their own "Approve" click always leaves an item at `pending_approval` awaiting a CEO — they hold the first key only. |
| Treasury | Everything including the Liquidity/Treasury Dashboard; can initiate a transfer but cannot approve one at either key — their own transfers queue for a Finance Manager exactly like anyone else's. |
| Senior Accountant | Read, create/update/submit transactions, match reconciliation candidates; no account lifecycle actions, no approval keys, no transfer creation. |
| Accountant | Read, create/update transactions only; matching but not closing a reconciliation period. |
| Auditor / External Auditor | Fully read-only — every mutating control is omitted, every figure and every reasoning disclosure still renders in full. |
| Read Only | The floor of this screen's surface — same as Auditor, minus even the Liquidity tile's disabled-with-reason distinction where a narrower custom role applies. |
| AI service account | Never renders this screen as a user; its scoped read access feeds the panels in **AI Integration**, and it is structurally incapable of holding `bank.transfer`, `bank.payment.approve`, or `bank.payment.approve.final` regardless of any role grant. |

# Layout & Regions

Reference layout at Laptop (`lg`, 1024px) and up, inside the persistent app shell (`Sidebar`/`Topbar`,
out of this document's scope per `NAVIGATION_SYSTEM.md`); see **Responsive Behavior** for how each region
reflows below this tier.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ Accounts ● Transactions   Transfers                                     [sub-nav tabs] │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Banking                                          [Import statement]  [+ New transfer]  │
│ 6 accounts · 3 currencies                                          [+ Connect account] │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ ┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐                            │
│ │Cash position│ │ Available  │ │ Restricted │ │ Liquidity  │        KPI row (×4 tiles)  │
│ │KD 84,210.500│ │KD 79,004.1 │ │KD 5,206.400│ │ 1.42 ▲     │                            │
│ └────────────┘ └────────────┘ └────────────┘ └────────────┘                            │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ ◄ [NBK Operating] [KFH Payroll] [Burgan O/D] [USD Invest.] [Petty cash] ►   Account rail│
├────────────────────────────────────────────────────────────────────────────────────────┤
│ ┌ AI · Treasury ───────────────────────────────────────────────────────────────────┐   │
│ │ ● USD exposure grew 12% this week; Burgan overdraft utilization crossed 70%.     │   │
│ │                                              [Dismiss]        [View full report] │   │
│ └───────────────────────────────────────────────────────────────────────────────────┘   │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Recent transactions                               [Search…]  [Filters ▾]  [Density ▾]  │
│ ┌──────────────────────────────────────────────────────────────────────────────────┐   │
│ │ Date     Account        Type              Description       Amount      Status   │   │
│ │ Jul 16   NBK Operating  Incoming payment  INV-2026-00417   +5,000.000  Cleared   │   │
│ │ Jul 16   Burgan O/D     Outgoing payment  BILL-2026-00891  −4,250.000  Pending   │   │
│ │ Jul 15   KFH Payroll    Fee               Monthly maint.      −3.500  Cleared   │   │
│ └──────────────────────────────────────────────────────────────────────────────────┘   │
│                                                 [View all transactions →] (/banking/transactions) │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

| Region | Contents | Streaming boundary |
|---|---|---|
| Sub-nav | `Tabs`: Accounts (active) · Transactions · Transfers, real `<Link>`s owned by `banking/layout.tsx` | Server Component — not streamed, part of the layout shell |
| Page header | `<h1>Banking</h1>`, a one-line account/currency summary, and up to three permission-gated actions | Renders with the page shell, immediately |
| Cash Position band | Four `KpiTile`s: Cash Position, Available, Restricted, Liquidity Ratio | Own `<Suspense>` — `GET /banking/cash-position` and `GET /banking/liquidity` independently |
| Account rail | One `BankAccountCard` per visible `bank_accounts` row, horizontally scrollable, `snap-x` | Own `<Suspense>` — `GET /banking/bank-accounts` |
| AI Treasury panel | A dismissible briefing card synthesizing Treasury Manager + Forecast Agent + CFO Agent output; omitted entirely without `reports.read` | Own `<Suspense>`, independently failable |
| Transactions table | `<BankTransactionsTable/>`, a preset `DataTable`, filtered to the trailing 30 days by default | Own `<Suspense>` — `GET /banking/bank-transactions` |
| Footer link | "View all transactions" → `/banking/transactions` (same table, unfiltered) | n/a |

Each region streams independently behind its own `Suspense` boundary per `FRONTEND_ARCHITECTURE.md →
Streaming with Suspense`: a slow AI-synthesis call for the Treasury panel never delays the account rail
or the transactions table from painting, and a failure in one region renders that region's own error
state without taking the rest of the page down with it (see **States**).

# Components Used

| Component | Source | Role on this screen |
|---|---|---|
| `Tabs` | Primitive (`components/ui/tabs.tsx`) | Accounts / Transactions / Transfers sub-nav |
| `KpiTile` | `components/dashboard/kpi-tile.tsx` | Cash Position, Available, Restricted, Liquidity Ratio |
| `TrendSparkline` | `components/dashboard/trend-sparkline.tsx` | 30-day trend in the Cash Position tile; forecast shape in the Liquidity tile's tooltip |
| `BankAccountCard` | `components/banking/bank-account-card.tsx` | One card per account in the rail |
| `CurrencyTag` | `components/shared/currency-tag.tsx` | ISO 4217 chip on cards and multi-currency table cells |
| `StatusPill` | `components/shared/status-pill.tsx`, domains `bank_account`/`bank_transaction`/`bank_transfer` | Account status on cards; transaction/transfer status in the table and on approval cards |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every balance and every transaction amount |
| `BankTransactionsTable` | `components/banking/bank-transactions-table.tsx`, wraps `DataTable` | The transactions region, reused verbatim by `transactions/page.tsx` |
| `AccountPicker` | `components/accounting/account-picker.tsx` | GL account field inside the manual-transaction Sheet |
| `PeriodPicker` | `components/accounting/period-picker.tsx` | Date-range filter above the table |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Per-row AI match dot; Fraud Detection risk score |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` | The AI Treasury briefing |
| `ApprovalCard` | `components/shared/approval-card.tsx`, `kind="bank_transfer"` | Any transaction or transfer awaiting the caller's own sign-off |
| `StatementImportDropzone` | `components/banking/statement-import-dropzone.tsx` | "Import statement" flow |
| `TransferApprovalGate` | `components/banking/transfer-approval-gate.tsx` | Referenced here, fully specified on `/banking/transfers/new` |
| `Sheet` | Primitive (Radix side-dialog) | Row-click quick view; the manual-transaction form |
| `DropdownMenu` | Primitive | Per-row/per-card actions, permission-filtered |
| `Dialog` / `AlertDialog` | Primitive | Reject-reason capture, fraud-hold acknowledgment, transfer-approval confirmation, duplicate-suspected override |
| `Skeleton`, `EmptyState`, `ErrorState` | Primitive / Shared | Per-region loading, empty, and failure presentation (see **States**) |

## `CashPositionBand`

The KPI row is its own Client Component so it can subscribe to the Realtime-driven invalidation described
in **Data & State** independently of the Server Component that composes the rest of the page:

```tsx
// components/banking/cash-position-band.tsx
'use client';

import { useSuspenseQuery } from '@tanstack/react-query';
import { KpiTile } from '@/components/dashboard/kpi-tile';
import { TrendSparkline } from '@/components/dashboard/trend-sparkline';
import { usePermission } from '@/hooks/use-permission';
import { bankingKeys } from '@/lib/api/query-keys';
import { api } from '@/lib/api/client';
import { useT } from '@/hooks/use-t';

export function CashPositionBand() {
  const t = useT();
  const canSeeTreasury = usePermission('treasury.read');

  const { data: position } = useSuspenseQuery({
    queryKey: bankingKeys.cashPosition(),
    queryFn: () => api.get('/banking/cash-position'),
    staleTime: 0,
  });
  const { data: liquidity } = useSuspenseQuery({
    queryKey: bankingKeys.liquidity(),
    queryFn: () => (canSeeTreasury ? api.get('/banking/liquidity') : null),
    staleTime: 0,
    enabled: canSeeTreasury,
  });

  return (
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4" role="region" aria-label={t('banking.cashPosition')}>
      <KpiTile label={t('banking.cashPosition')} value={position.current_balance_base}
        currencyCode={position.base_currency} delta={position.delta_30d}>
        <TrendSparkline points={position.trend_30d} />
      </KpiTile>
      <KpiTile label={t('banking.available')} value={position.available_balance_base}
        currencyCode={position.base_currency} />
      <KpiTile label={t('banking.restricted')} value={position.restricted_balance_base}
        currencyCode={position.base_currency} caption={t('banking.holds', { count: position.hold_count })} />
      <KpiTile label={t('banking.liquidityRatio')} value={liquidity?.ratio ?? null}
        format="ratio" locked={!canSeeTreasury} lockedReason={t('banking.needsTreasuryRead')}
        caption={liquidity ? t('banking.vsFloor', { floor: liquidity.policy_floor }) : undefined}>
        {liquidity && <TrendSparkline points={liquidity.forecast_trend} mirrorRTL={false} />}
      </KpiTile>
    </div>
  );
}
```

`enabled: canSeeTreasury` keeps the query from ever firing for a caller without `treasury.read`, so the
"locked" tile is a purely presentational state, never a component quietly holding a `403` response in
memory. `mirrorRTL={false}` on the forecast sparkline is deliberate — see **RTL & Localization**, Rule 5.

## Transfer approval — the confirming-dialog contract

Approving or rejecting a `kind="bank_transfer"` card is the one interaction on this screen with no
optimistic update at all, per `FRONTEND_ARCHITECTURE.md → Principle 10` ("a financial mutation… always
renders a confirming dialog before it fires") and its own note that money-moving mutations deliberately
omit `onMutate`:

```tsx
// lib/api/hooks/use-approve-transfer.ts
'use client';

export function useApproveTransfer(transferId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: { finalKey: boolean }) =>
      api.post(
        `/banking/transfers/${transferId}/${payload.finalKey ? 'approve-final' : 'approve'}`,
        {},
        crypto.randomUUID(), // Idempotency-Key — Principle 9, unchanged across retries of this attempt
      ),
    // no onMutate: the UI shows "approved" only after the server's 2xx — Principle 10
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: bankingKeys.transfers() });
      queryClient.invalidateQueries({ queryKey: bankingKeys.cashPosition() });
    },
    onError: (error) => toastFromApiError(error), // surfaces FRAUD_HOLD_ACK_REQUIRED inline, see AI Integration
  });
}
```

The confirming `AlertDialog` this hook is wired to states the exact amount, source/destination account,
and payee before its own "Confirm approval" button becomes clickable — a Banking-specific tightening of
`ApprovalCard`'s generic contract, since a bare "Approve?" is judged insufficient friction for an action
that moves real money (`docs/frontend/BANKING.md → Interactions & Flows` states the identical rule).
Rejecting always requires a typed reason via the same dialog's alternate branch.

# Data & State

## Endpoints

Every figure and every row on this screen resolves to an endpoint `docs/accounting/BANKING.md → API`
already owns; this screen introduces none of its own. The full catalog lives there — this is the subset
this specific route calls.

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Bank account roster | `GET /api/v1/banking/bank-accounts` | `bank.read` | Feeds the account rail; supports `?filter[status]=`, `?filter[account_type]=`, `?filter[institution_type]=`, `?filter[currency_code]=` |
| Cash position | `GET /api/v1/banking/cash-position` | `bank.read` | Feeds Cash Position / Available / Restricted in one call, served from `bank_account_balances_mv` |
| Liquidity | `GET /api/v1/banking/liquidity` | `treasury.read` | Feeds the Liquidity Ratio tile and its policy-floor caption |
| Cash-flow forecast | `GET /api/v1/banking/cash-flow-forecast` | `bank.read` | Feeds the Liquidity tile's tooltip sparkline and the AI Treasury panel's cited bucket |
| Recent transactions | `GET /api/v1/banking/bank-transactions` | `bank.read` | Default filter `?filter[value_date][gte]=<today-30d>`; `transactions/page.tsx` requests the same resource unfiltered |
| Statement import | `POST /api/v1/banking/statement-imports` | `bank.reconcile` | "Import statement" flow, `multipart/form-data` |
| Statement import status | `GET /api/v1/banking/statement-imports/{id}` | `bank.reconcile` | Polled while `status = processing` |
| Manual transaction create | `POST /api/v1/banking/bank-transactions` | `bank.transaction.create` | "New transaction" flow |
| Submit / approve / reject / void / reverse | `POST /api/v1/banking/bank-transactions/{id}/submit\|approve\|approve-final\|reject\|void\|reverse` | per-action, see **Route & Access** | Row actions and approval-card actions |
| Transfers list | `GET /api/v1/banking/transfers` | `bank.read` | Transfers sub-nav tab |
| Approve / final-approve a transfer | `POST /api/v1/banking/transfers/{id}/approve\|approve-final` | `bank.payment.approve` / `bank.payment.approve.final` | Inline approval-card action on this screen; creation always happens on `/banking/transfers/new` |
| AI Treasury briefing | `GET /api/v1/ai/decisions?agent_code=TREASURY_MANAGER,CFO_AGENT&decision_type=treasury_cash_position_summary,treasury_liquidity_alert&status=proposed,approved&limit=3` | `reports.read` | Feeds the AI Treasury panel — the same `ai_decisions` table every AI-authored surface in the product reads |

## Query keys

```ts
// lib/api/query-keys.ts — banking-scoped factories
export const bankingKeys = {
  all: ["banking"] as const,
  accounts: (filters?: BankAccountFilters) => [...bankingKeys.all, "accounts", filters ?? {}] as const,
  cashPosition: () => [...bankingKeys.all, "cash-position"] as const,
  liquidity: () => [...bankingKeys.all, "liquidity"] as const,
  forecast: (horizon: 30 | 60 | 90 = 90) => [...bankingKeys.all, "forecast", horizon] as const,
  transactions: (filters: BankTransactionFilters) => [...bankingKeys.all, "transactions", filters] as const,
  transfers: (filters?: TransferFilters) => [...bankingKeys.all, "transfers", filters ?? {}] as const,
};

export const aiTreasuryKeys = {
  briefing: () => ["ai", "decisions", "treasury-briefing"] as const,
};
```

Every key is implicitly company-scoped because `QueryClient` is re-created on company switch rather than
shared across companies (`FRONTEND_ARCHITECTURE.md → Query key architecture`) — nothing on this screen
needs to remember to invalidate on switch for correctness, because there is no live cache instance left
holding a previous company's balance for it to accidentally serve.

## Cache tuning by data class

Applying `FRONTEND_ARCHITECTURE.md → Cache tuning by data class` to this screen's own resources, without
inventing a bespoke class:

| Data class | Resources on this screen | `staleTime` | Rationale |
|---|---|---|---|
| Live/derived figures | `cashPosition`, `liquidity`, `accounts` balances | `0` | Correctness over avoiding a refetch; kept fresh primarily via Realtime invalidation, not polling |
| Transactional lists | `transactions`, `transfers` | `30_000` | A statement import can land dozens of rows at once; a stale list is a real annoyance |
| Rarely-changing reference | `forecast` | `900_000` (15 min) | The Forecast Agent recomputes on its own 4-hour cadence (`docs/accounting/BANKING.md → Performance`); the tile's tooltip shows the cached `generated_at` rather than implying a live number |
| AI feeds | `aiTreasuryKeys.briefing` | `10_000`, `refetchOnWindowFocus: true` | A returning Finance Manager should see what the Treasury Manager/CFO Agent produced overnight |

## Server-first paint, then Suspense per region

`accounts/page.tsx` is a Server Component that prefetches the account roster — the one query every other
region's first paint benefits from having warm — and streams every other region behind its own
`Suspense`/`WidgetErrorBoundary` pair, mirroring the pattern `AI_COMMAND_CENTER.md`'s own page uses:

```tsx
// app/(app)/banking/accounts/page.tsx
export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // tenant-scoped — never statically cached

export default async function BankingAccountsPage() {
  const queryClient = getQueryClient();
  await queryClient.prefetchQuery({
    queryKey: bankingKeys.accounts(),
    queryFn: () => apiServer.get("/banking/bank-accounts"),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <div className="space-y-6">
        <BankingPageHeader />
        <WidgetErrorBoundary widgetId="cash_position_band">
          <Suspense fallback={<WidgetSkeleton variant="kpi-strip" />}><CashPositionBand /></Suspense>
        </WidgetErrorBoundary>
        <WidgetErrorBoundary widgetId="account_rail">
          <Suspense fallback={<WidgetSkeleton variant="rail" />}><AccountRail /></Suspense>
        </WidgetErrorBoundary>
        <Can permission="reports.read">
          <WidgetErrorBoundary widgetId="ai_treasury_panel">
            <Suspense fallback={<WidgetSkeleton variant="ai-card" />}><AiTreasuryPanel /></Suspense>
          </WidgetErrorBoundary>
        </Can>
        <WidgetErrorBoundary widgetId="transactions_table">
          <Suspense fallback={<WidgetSkeleton variant="table" />}>
            <BankTransactionsTable defaultFilters={{ value_date: { gte: thirtyDaysAgo() } }} />
          </Suspense>
        </WidgetErrorBoundary>
      </div>
    </HydrationBoundary>
  );
}
```

## Realtime

Banking subscribes to one company-scoped, module-wide private channel — never a per-account channel,
which would multiply subscriptions for a company with a dozen accounts:

| Channel | Events carried | Effect |
|---|---|---|
| `private-company.{id}.banking` | `bank.transaction.created`, `bank.transaction.cleared`, `bank.transaction.approved`, `bank.transaction.rejected`, `bank.transfer.completed`, `bank.reconciliation.closed`, `bank.statement.imported`, `bank.fraud_flag.raised`, `bank.liquidity_ratio.breached` | Drives cache invalidation or an in-place patch, chosen per event, never by default |
| `private-company.{id}.ai-jobs` | Any `ai_decisions` row authored by `TREASURY_MANAGER`, `CFO_AGENT`, `FORECAST_AGENT`, or `BANKING_AGENT` finishing a matching sweep | Refreshes the AI Treasury panel and the table's `ai_match` dots the moment a relevant agent run completes |
| `private-company.{id}.notifications.{user_id}` | `bank.open_banking.consent_expiring`, `bank.duplicate_suspected` | Feeds the Topbar's notification bell only — this screen's own regions do not duplicate this stream |

`bank.transaction.cleared` and `bank.transfer.completed` invalidate `cashPosition`/`liquidity`/`accounts`/
`transactions` as a full `invalidateQueries` call — a balance is never optimistically patched from a
realtime push, because the push carries no guarantee it reflects every concurrent write landing in the
same instant. `bank.fraud_flag.raised` and `bank.liquidity_ratio.breached` instead patch the affected
approval card or KPI tile in place (`setQueryData`, not a refetch), so a held payment's border or a
breached-floor caption updates live without disturbing a user's scroll position. On reconnect after any
extended WebSocket drop, every realtime-fed key on this screen is invalidated once, as a single batch, so
a missed fraud hold during an outage surfaces the moment connectivity returns rather than waiting for a
manual refresh.

## AI agents feeding this screen

| Agent | `agent_code` | Contribution |
|---|---|---|
| Treasury Manager | `TREASURY_MANAGER` | Computes the Liquidity Ratio and its policy-floor comparison; authors the `treasury_cash_position_summary`/`treasury_liquidity_alert` decisions the AI Treasury panel renders; prepares vendor-payment-batch proposals |
| Banking Agent (a Treasury Manager specialization, `agent_key: banking_agent`, `parent_agent_key: treasury_manager` — see `docs/ai/agents/BANKING_AGENT.md`) | `BANKING_AGENT` | Populates each table row's `ai_match_confidence`/`ai_match_reasoning`; authors duplicate-suspected flags and document-first backfill proposals |
| Forecast Agent | `FORECAST_AGENT` | Supplies the 13-week cash-flow projection cited in the Liquidity tile's tooltip and the AI Treasury panel's reasoning |
| CFO Agent | `CFO_AGENT` | Synthesizes Treasury Manager, Forecast Agent, and Fraud Detection signals into the panel's plain-language weekly headline |
| Fraud Detection | `FRAUD_DETECTION` | Screens every outgoing payment/transfer before it can leave `pending_approval`; a `hold_recommended: true` flag renders the mandatory second-look banner in **AI Integration** |
| Document AI / OCR Agent | `DOCUMENT_AI` | Extracts statement lines from a PDF/scanned upload during Import Statement; sub-95%-confidence fields surface in the import review step |
| Expense Classification (a Banking Agent sub-capability) | `BANKING_AGENT` | Pre-fills the GL account/dimensions on the manual-transaction form when a description lacks a clear source document |

# Interactions & Flows

**Opening the screen.** The shell (sub-nav, header, permission-gated actions) paints immediately; the
Cash Position band and Account rail resolve first — the prefetched account roster and the materialized
`bank_account_balances_mv` behind `cash-position`/`liquidity` are both cheap — while the AI Treasury panel
and the transactions table stream in independently a beat later. No region blocks another.

**Opening an account card.** A full click anywhere on a `BankAccountCard` outside its own `DropdownMenu`
navigates to `/banking/accounts/{accountId}`, that account's register — a real navigation, never a
`Sheet` or `Dialog` layered over this screen, since a register is substantial, bookmarkable content in
its own right.

**Importing a statement.** "Import statement" (`bank.reconcile`) opens a `Dialog` hosting
`StatementImportDropzone`: an account picker, a format selector (`mt940` / `camt053` / `csv` / `xlsx` /
`pdf`), and a drop target. A first-time `csv`/`xlsx` import from a given bank prompts a one-time
column-mapping step; every subsequent import from that same institution skips it. Submitting calls
`POST /banking/statement-imports`; the dialog immediately shows `status: processing` and polls the status
endpoint every few seconds rather than holding a long request open, never blocking the rest of the screen.
A `pdf` import additionally surfaces a review step listing any OCR-extracted field below the 95%
confidence threshold for manual correction. On completion the dialog reports the exact
`{line_count, auto_matched, unmatched}` summary and offers "Go to Reconciliation" — navigating to
`/banking/reconciliation/{bankAccountId}` — as its primary next step; this screen never renders the
workbench itself.

**Creating a manual transaction.** "New transaction" (`bank.transaction.create`) opens a full `Sheet`
with a React Hook Form + Zod form mirroring `bank_transactions`' own fields: account, type, amount and
currency, value date, payee, and an `AccountPicker` for GL classification, pre-filled by Expense
Classification where a confident suggestion exists and always editable. Saving creates a `draft` row;
"Save and submit" additionally calls `.../submit` in the same round trip, moving the row into
`pending_approval` if the type or amount requires the chain.

**Approving or rejecting an item.** Every `ApprovalCard` with `kind="bank_transfer"` on this screen —
covering both a pending outgoing payment and an actual transfer, since both move cash out under the
identical two-key chain — exposes Approve/Reject to whichever role holds the relevant key. Every
Approve/Reject click here opens the confirming dialog described in **Components Used** naming the exact
amount, account, and payee; rejecting always requires a typed reason. If Fraud Detection has attached
`hold_recommended: true`, the mandatory acknowledgment banner in **AI Integration** must be dismissed with
a typed reason before the confirming dialog's own "Confirm approval" control becomes clickable — two
separate, both-required gates, never collapsed into one.

**Initiating a transfer.** "New transfer" (`bank.transfer`) is a real link to `/banking/transfers/new`,
never a modal — that destination renders `TransferApprovalGate`, a persistent, non-dismissible banner
stating the two-key rule before the form's first field.

**Working the transactions table.** Search, column sort, and the density toggle all round-trip through
`DataTable`'s own query-param-driven state; no client-side filtering ever runs against an already-fetched
page. The Filters affordance opens a `Popover` exposing transaction type, status, account, and a
`PeriodPicker`-backed date range. On `transactions/page.tsx` a filter updates the URL (a shareable,
bookmarkable view); the embedded instance here stays in-memory, TanStack-Query-keyed state, since its own
purpose is a recent-activity preview, not a report.

**Clicking a KPI tile.** Cash Position, Available, and Restricted drill into the table below,
pre-filtered to the relevant slice (Restricted → `status IN (pending_clearance, frozen_hold)`); the
Liquidity Ratio tile, when visible, opens the Treasury Dashboard instead, since liquidity is a
company-wide concept the transactions table cannot usefully filter down to.

**Dismissing or expanding the AI Treasury panel.** "Dismiss" calls the platform's shared
dismiss-with-reason contract (`POST /api/v1/ai/decisions/{id}` transitioning `status` to `rejected`) and
hides the card for the current session; "View full report" navigates to the AI Command Center's Cash
Flow Status / Treasury Briefing surface rather than expanding a second copy of the content inline here.

**Reaching Bank Reconciliation.** Every entry point into the workbench from this screen — a
`BankAccountCard`'s "Reconcile" row action, and a transaction row's own "Reconcile" action — resolves to
`/banking/reconciliation/{bankAccountId}`, never the account's register and never a modal, since
reconciling is substantial enough work to deserve its own route and its own screen document.

# AI Integration

Every AI-authored element on this screen renders through the platform's one mandatory contract
(`FRONTEND_ARCHITECTURE.md → AI Integration Layer`): a `confidence_score`, a `reasoning`, and `sources`,
in the shared visual envelope — no card ever shows a bare number the AI computed without showing its
work. Banking enforces this contract more strictly than almost anywhere else in the product, because a
wrong AI output here touches real money.

**The AI Treasury panel**, sourced from a `treasury_cash_position_summary` or `treasury_liquidity_alert`
`ai_decisions` row:

```json
{
  "agent_code": "TREASURY_MANAGER",
  "decision_type": "treasury_liquidity_alert",
  "confidence_score": 97.0,
  "reasoning": "Liquidity ratio computed as (operating_cash_base + near_cash_base) / next_30_day_committed_outflows_base using bank_accounts as of today and the open bills/payroll/tax rows listed in sources.",
  "payload": { "liquidity_ratio": 1.42, "policy_floor": 1.20, "breached": false },
  "sources": [
    { "type": "bank_accounts", "id": 12, "label": "NBK Operating — available balance" },
    { "type": "ai_decisions", "id": 881410, "label": "Forecast Agent — 13-week cash-flow projection" }
  ],
  "requires_approval": false
}
```

`AIProposalPanel` renders this unembellished — it never states a figure the `payload` did not already
carry, and every `sources` entry is a real, resolvable link rather than plain text.

**Per-row match confidence.** `BankTransactionsTable`'s `ai_match` column renders a small `ConfidenceBadge`
dot only for a row the Banking Agent's matching sweep actually scored; a manually entered or fully
human-matched row shows nothing there, not a zero — the column's absence is itself information. Hovering
or focusing the dot discloses the exact `reasoning_factors` that fired (`exact_reference_match`,
`amount_date_exact`, `fuzzy_counterparty`, `recurring_pattern`, `ai_similarity`), reused verbatim from
`docs/ai/agents/BANKING_AGENT.md → Outputs` — this screen never paraphrases that reasoning into something
shorter.

**Fraud holds — the one non-dismissible, non-calm treatment on this screen.** When Fraud Detection
attaches `hold_recommended: true` to a pending outgoing payment or transfer, its card renders with a
distinct `danger`-toned left border no other state on this screen uses, and a banner stating the specific
flagged reason(s) — `new_payee`, `unusual_amount_for_payee`, `unusual_time_of_day`, `velocity_spike`,
`payee_details_changed_recently`. The banner cannot be dismissed with a plain click: the approver must
type an explicit acknowledgment reason in a required field before Approve becomes clickable at all, and
that reason is written to `audit_logs` alongside the eventual approval.

**Duplicate-suspected, blocking.** A duplicate-likelihood signal on a new manual transaction or an
in-progress import surfaces as a blocking `AlertDialog` ("This looks identical to transaction #90490
posted 2 minutes ago — proceed anyway?") requiring the same typed-reason override once the score reaches
95. The dialog does not auto-dismiss; the creating action is genuinely held until answered.

**Payment-run proposals.** A Treasury Manager payment-batch proposal — which open bills to pay this run,
from which account — surfaces as an `AIProposalPanel` addressed to the caller, carrying its own
`confidence_score` and at least one named alternative (e.g. "Pay all 3 bills today" vs. "Defer Salmiya
past 2026-07-22," each with a stated tradeoff) — never a bare Accept/Reject pair with no context.

**Why "Do it" essentially never appears here.** `can_execute_directly` is a server-computed field this
screen never re-derives. For nearly everything here it resolves to `false`: `bank.transfer`,
`bank.payment.approve`, and `bank.payment.approve.final` are permissions the AI agent principal type can
never structurally hold, so a proposal whose resolution requires one of those three permissions never
renders a "Do it" button — only "Send for approval" and "Dismiss." The one narrow exception is the
Banking Agent's own deterministic reconciliation auto-commit: that action has already happened by the
time this screen renders the row (it shows `reconciled` with its provenance dot already attached), so
there is no pending click for it either — the auto-commit is a fact this screen displays after the event,
never a button a human presses.

# States

No region on this screen ever falls through to a bare blank void or an unrecoverable crash; every region
ships its own loading, empty, and error presentation, and a failure in one never blocks another.

| Region | Loading | Empty | Error |
|---|---|---|---|
| Cash Position band | Four `KpiTile`s in their own `loading` state (`Skeleton` in place of value/delta) | A brand-new company with zero `bank_accounts` renders one "Connect your first bank account to see your cash position" card spanning all four tile slots, not four independently empty tiles | `cash-position` and `liquidity` fail independently — a `liquidity` failure alone shows only that tile's inline "Couldn't load — Retry," leaving the other three intact |
| Account rail | Skeleton cards at the same fixed width as `BankAccountCard` | "No bank accounts yet" with a permission-gated "Connect account" CTA | Inline retry card in place of the rail; the rest of the page renders normally |
| AI Treasury panel | A skeleton chip plus one skeleton card | A calm "No treasury insights right now — cash position looks steady," explicitly not styled as a warning — an empty AI panel is good news | A distinct "AI insights are temporarily unavailable" state (not a generic error, not an infinite spinner) when the AI engine returns `503`; the Cash Position band and Account rail are entirely unaffected, since neither calls the AI engine |
| Transactions table | `DataTable`'s own row-skeleton | Two distinct copies: "No transactions yet — import a statement or record one manually" (genuinely empty) vs. "No rows match your filters" (filtered to zero) — never presented identically | Inline `ErrorState` in place of the table body; the header's own action buttons remain interactive |
| Import Statement dialog | A determinate progress state while `status: processing`; an indeterminate shimmer only before the first status poll returns | Not applicable — the dropzone's idle appearance is "no file selected yet" | A balance-check failure blocks the import outright with a re-upload prompt; an OCR low-confidence result is advisory and lets the batch proceed to the field-correction review step |

A route-level `error.tsx` at `banking/accounts/` remains the outermost safety net for a genuinely
unexpected failure (the session itself becoming invalid mid-render); per the platform's three-granularity
error model this should essentially never fire for an individual region's ordinary `4xx`/`5xx`, which are
caught and handled inline, one region at a time.

# Responsive Behavior

Banking follows `RESPONSIVE_DESIGN.md`'s five semantic device tiers exactly — Mobile (unprefixed), Tablet
(`md:`, 768px), Laptop (`lg:`, 1024px), Desktop (`xl:`/`2xl:`, 1280/1536px), Ultra Wide (`3xl:`, 1920px) —
never a bespoke breakpoint of its own.

| Tier | Behavior |
|---|---|
| Mobile (<768px) | The Cash Position band becomes a horizontal-scroll, snap-aligned carousel, one tile per screen-width; the Account rail narrows each card to a taller, single-column-friendly shape; the AI Treasury panel, transactions table, and header actions stack full-width in DOM order. The table itself switches from a `<table>` to a card list — each row becomes a compact card showing date, account, amount, and status, with the remaining fields reachable via the card's own row-click `Sheet`. The Sidebar is replaced by a bottom tab bar; "New transaction"/"Import statement" are additionally reachable from the tab bar's center "Create" sheet. |
| Tablet (768–1023px) | The Cash Position band becomes two tiles per row; the table gains its second priority column before fully graduating to a real table at Laptop. |
| Laptop (1024–1279px)+ | The full layout in **Layout & Regions**: four-tile KPI row, scrollable account rail, and the table rendered as a real `<table>` with every priority column visible, no horizontal scroll. |
| Ultra Wide (≥1920px) | Unchanged bento shape at generous margins, content capped at `max-w-[1440px]`; the platform's global AI Rail companion panel may dock alongside this screen without competing with the AI Treasury panel, which remains part of this screen's own region set regardless of whether the ambient Rail is open. |

**Touch targets.** Every interactive element — card menu triggers, row-action buttons, Approve/Reject —
maintains the platform's 44×44px minimum hit area with an 8px minimum gap between adjacent controls,
material here specifically because Approve and Reject sit side by side and a mis-tap carries real
financial consequences. A swipeable approval card on Mobile/Tablet (gesture direction fully mirrored under
RTL) still opens the identical confirming dialog a desktop click would — a swipe changes the entry point
into the confirmation, never the confirmation step itself, and a fraud-held card's acknowledgment banner
cannot be swiped past under any circumstance. Approving a sensitive item from a mobile session
additionally requires a biometric re-confirmation step layered on top of, never instead of, the ordinary
permission and confirmation-dialog checks.

**Virtualization.** Once the transactions table's underlying result set exceeds roughly 200 rows, rows
render through `@tanstack/react-virtual` rather than the plain DOM table; the size estimator reads the
active tier (dense rows at Laptop-and-up, taller card rows at Mobile/Tablet) so the same data renders
correctly at every width without a bespoke per-screen virtualization config.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md` and the platform's RTL contract, applied
concretely to this screen's content — Banking introduces no direction-handling code of its own.

- **Logical properties only.** The account rail's scroll direction, the KPI band's reading order, the
  header's action-button order, and every card's icon/label pairing use `ms-*`/`me-*`/`text-start`/
  `text-end` exclusively; flipping `dir="rtl"` mirrors the whole screen with zero screen-specific code.
- **Numerals, currency codes, IBANs, and dates never mirror.** Every `AmountCell`, every `ConfidenceBadge`
  percentage, and any IBAN fragment shown in a tooltip renders inside a `dir="ltr"`/
  `unicode-bidi: isolate` span, exactly as `AmountCell`'s own implementation forces — an IBAN
  (`KW81CBKU0000000000001234560101`) must read as one unbroken, correctly-ordered token even inside a
  fully Arabic sentence, and QAYD never renders it with Eastern Arabic-Indic digits regardless of locale.
- **Numeric alignment is physically fixed (the platform's documented Exception A).** The table's Amount
  column and every balance figure use hard-coded `text-right`, not `text-end`, in both directions, so a
  bilingual Finance team scanning magnitude and decimal alignment sees figures land on the same edge
  regardless of interface language.
- **The Liquidity tile's sparkline and the AI Treasury panel's cited forecast trend never mirror (Rule
  5).** Both keep a fixed left-to-right time axis in both `dir="ltr"` and `dir="rtl"` — only the tile's
  own position within the KPI row mirrors, never the chart's internal axis, since a mirrored trend line
  is one of the most common and most confusing RTL defects in Arabic-market financial software.
- **Bilingual institution and payee names.** `BankAccountCard`'s bank name and any payee name surfaced in
  a transaction row or an approval-card title render `name_en`/`name_ar` per the active locale, with the
  API always returning both fields regardless of `Accept-Language` — the client, never the server, owns
  which language displays.
- **AI-authored reasoning is never machine-translated by the frontend.** The Treasury Manager's and CFO
  Agent's Arabic narrative, and the Banking Agent's Arabic match-reasoning text, both arrive already
  localized from the API's own content-negotiation contract; this screen's own localization
  responsibility is limited to chrome — button labels, empty-state copy, dialog titles — never the
  model's own words.

| Context | English | Arabic |
|---|---|---|
| Page title | Banking | الحسابات البنكية |
| New transfer | New transfer | تحويل جديد |
| Import statement | Import statement | استيراد كشف الحساب |
| Connect account | Connect account | إضافة حساب |
| Fraud hold banner | Held — new payee, unusual amount for payee | معلّق — مستفيد جديد، مبلغ غير معتاد لهذا المستفيد |
| Confirm approval | Confirm approval | تأكيد الاعتماد |
| View all transactions | View all transactions | عرض جميع الحركات |
| AI provenance | Suggested by the Banking Agent — 94% confidence | مقترح من الوكيل البنكي — بثقة 94% |

Arabic copy on this screen is authored directly by a fluent professional-register writer, never
machine-translated from the English column above — a fraud-hold banner or a confirming-dialog sentence
that reads precise and calm in English must read identically precise and calm in Arabic.

# Dark Mode

Banking introduces no new color, elevation, or radius token — every surface here resolves through the
same component-level tokens `BankAccountCard`, `KpiTile`, `StatusPill`, `AmountCell`, `ApprovalCard`, and
`AIProposalPanel` already ship, named the way the actual component implementations do (`ink-1`…`ink-12`,
`accent`/`accent-subtle`, `success`/`warning`/`danger`/`neutral` tones).

- **Cards and tiles get lighter, not darker, in dark mode.** A `BankAccountCard` and a `KpiTile`'s
  background sits a step lighter than the canvas behind it, matching the platform's "physical light"
  strategy rather than a naive inversion — an account card should still read as a card sitting above the
  page, not a hole cut into it.
- **The AI accent is reserved for provenance, never for status.** The `ai_match` confidence dot is always
  accent-family; the fraud-hold border and banner are always `danger`. A company can tell "the AI is very
  sure" and "this is a critical hold" apart as two separately-colored facts on the same card, never one
  collapsed into the other.
- **Confidence indicators are re-tuned per theme, not linearly brightened.** `ConfidenceBadge`'s dark-mode
  value uses its own calibrated dark value rather than a naive brightness flip of the light-mode hex, so a
  high-confidence fraud-flagged transaction reads as urgent in dark mode without over-saturating.
- **Charts resolve color through the shared JS-readable token export** (`lib/tokens.ts`), never a CSS
  variable read at SVG render time, since an SVG `stroke` attribute cannot consume a Tailwind class the
  way a `div` background can.
- **Debit and credit stay in plain ink in dark mode exactly as in light mode.** `AmountCell`'s
  `mode="credit"` treatment is a single, theme-aware token, never a separate dark-only red — a
  transaction's outflow direction reads identically calm in both themes, distinguished by the leading
  glyph, not a wash of alarm color across the whole figure.

Every Storybook story for `BankAccountCard`, `BankTransactionsTable`, and the AI Treasury panel ships the
platform's standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this
screen's own Playwright suite captures the same four-way screenshot set at the route level.

# Accessibility

Banking targets the platform's WCAG 2.2 AA floor identically in both languages and both themes.

- **Landmark and heading structure.** Each region without a visible heading of its own (the Cash Position
  band, the Account rail) still carries a real, visually hidden `<h2>` via `aria-labelledby`, so a
  screen-reader user's landmark list enumerates four distinct regions rather than one undifferentiated
  page.
- **Live regions, calibrated to avoid noise.** A realtime balance patch and a newly landed `ai_match` dot
  both announce via `aria-live="polite"`, never `"assertive"`, so a fast-moving Realtime push never
  interrupts whatever a screen-reader user is currently reading. A fraud hold is the one deliberate
  exception: it announces via the assertive tier (`role="alert"`) because it blocks a task already in
  progress for an approver mid-review of that exact item.
- **RBAC-aware disabled controls explain themselves, and stay textually distinct from a business-rule
  disablement.** An Approve button disabled for lacking a permission carries `aria-describedby` naming
  the missing permission and the role that holds it; an Approve button disabled because the caller
  already holds the first key on that same item carries a different, specific explanation — never a
  generic "You can't do this right now."
- **Every AI-authored control explains itself before it can be acted on.** The `ai_match` dot's visual
  pill is `aria-hidden`; an adjacent real text node carries the percentage and reasoning as actual text,
  never a bare progress-bar width. The fraud hold's mandatory acknowledgment field is
  `aria-describedby`-linked to the specific flagged reason(s).
- **Data grid semantics on the transactions table.** `BankTransactionsTable` renders through real
  `<table>`/`role="grid"` semantics with `aria-sort` on each sortable header and `aria-busy` while a
  filter/sort/page change is in flight.
- **Keyboard path.** Tab order flows header actions → Cash Position band → Account rail (each card, then
  its own menu trigger) → AI Treasury panel → the transactions table (search, filter trigger, sortable
  headers, row-by-row actions) — every control reachable and operable without a mouse, including the
  Import Statement dropzone, which accepts a keyboard-triggered native file picker rather than requiring a
  literal drag gesture.
- **Focus management.** Opening the Import Statement dialog or the New Transaction `Sheet` traps focus
  inside per the platform's standard Radix focus-trap behavior; closing either returns focus to the
  control that opened it. Navigating to an account's own register resets focus to that page's `<h1>` via
  the platform's standard route-change focus-reset.

# Performance

- **Streamed, not blocking.** Every region is its own `Suspense` boundary; the slowest widget on the page
  — typically the AI Treasury panel, if a CFO Agent synthesis is still in flight — never delays the Cash
  Position band's first paint, and a failure in one region never takes down another.
- **Cheap by design at the source.** `GET /banking/cash-position` and `GET /banking/liquidity` are served
  from `bank_account_balances_mv`, a materialized aggregate refreshed on every balance-changing
  transaction via a lightweight trigger rather than a full `REFRESH` — this is why those two calls can
  safely carry `staleTime: 0` without becoming an expensive full-table aggregation on every page load.
- **The transactions table never over-fetches.** `DataTable`'s server-driven pagination/sort/filter model
  means this screen's recent-only instance and `transactions/page.tsx`'s full-history instance never pull
  more than one page (25 rows default) over the wire at a time, regardless of how many years of history an
  account has accumulated; virtualization only becomes relevant once a single filtered result set itself
  exceeds roughly 200 rows.
- **The forecast/liquidity chart is code-split.** The Liquidity tile's sparkline and the AI Treasury
  panel's cited forecast chart load their charting dependency via `next/dynamic({ ssr: false })` with a
  matching skeleton, so a role that never sees the AI Treasury panel or the Liquidity tile never downloads
  that dependency at all.
- **Statement import never blocks the UI thread on a large file.** Import processing runs as a queued
  Laravel job; the dialog polls a lightweight status endpoint rather than holding a long-lived request
  open, so importing a thousand-line statement never makes the rest of the screen feel frozen.
- **Realtime cost control.** This screen subscribes through the shell's single shared Realtime connection
  (mounted once, not per-region), so opening Banking never opens a second WebSocket connection on top of
  whatever the rest of the shell already holds; on Mobile, the AI Treasury panel's own channel binding is
  additionally gated behind an `IntersectionObserver` so it unsubscribes once scrolled out of view.
- **Bundle and Web Vitals.** The route's shell (excluding the individually `next/dynamic`-split chart) is
  held to the platform's stated first-load JS budget, enforced in CI against `performance-budgets.json`;
  LCP is measured against the Cash Position band's paint — this screen's actual meaningful content, not
  the shell — and INP is watched specifically on the Approve/Reject buttons and the Import Statement
  dropzone, the two highest-stakes interactive surfaces on this screen.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A bare hit to `/banking` | Resolves to the nearest `not-found` boundary — the ordinary Next.js behavior for a route-group segment with a `layout.tsx` but no `page.tsx` of its own. Every in-product link (sidebar, breadcrumb, any AI-surfaced target) points at `/banking/accounts` directly, so this path is reachable only via a hand-typed or stale-bookmarked URL. |
| A company switch fires while a region's fetch is still in flight | `queryClient.clear()` runs before `router.refresh()`; the in-flight response is discarded on arrival since its query key no longer exists in the cleared cache, and can never be written into the new company's entries. |
| A company holds bank accounts in more than one currency | The Cash Position tile's single headline figure is always the sum converted to the company's base currency using each account's stored `exchange_rate`, rendered at the base currency's own decimal precision — it never averages or truncates precision across a mixed set. A muted `CurrencyTag` annotates the tile only when more than one currency actually contributes. |
| A role holds `bank.read` but not `treasury.read` | The Liquidity Ratio tile renders the disabled-with-a-reason treatment from **Route & Access** rather than being omitted, since a Finance-adjacent role benefits from knowing the capability exists; the AI Treasury panel, gated on `reports.read` rather than `treasury.read`, may still render independently of that tile's own gate. |
| A user's role is downgraded (a permission revoked) while this screen is already open | The permission snapshot the `SessionProvider` holds is not re-evaluated mid-render; the next mutating attempt fails its `403` regardless, and the next full navigation or company-switch re-fetches `GET /me` and re-renders every gated control against the fresh grant — this screen does not poll its own permission snapshot for a downgrade that has not yet triggered a navigation. |
| Fraud Detection raises a hold on a payment whose approval card is already open on screen | The realtime push patches that one card's status to `held` in place (not a full list invalidation, to avoid disrupting scroll position or an in-progress read), and the `danger`-toned border/banner appear live without a manual refresh. |
| Two browser tabs; one approves the first key of a transfer while the other is mid-review of the same item | The idle tab's next action against that transfer fails with a conflict response (already advanced past that step), and this screen re-fetches the item fresh rather than trusting stale local state. |
| A statement import's period overlaps an already-closed reconciliation period for that account | The import itself still succeeds (statement lines are immutable, ingestion-only data), but any line falling inside the closed period's date range is excluded from automatic matching and flagged for the reconciliation workbench's own reopen flow — this screen surfaces the exclusion count in the import summary, never silently drops the lines. |
| A newly onboarded account has a nonzero opening balance but zero transactions | The Cash Position band reflects the opening balance immediately (it is itself a ledger fact, not a transaction), while the Account rail's card and the transactions table both show the account normally — the table's own empty state ("No transactions yet") is not in conflict with a nonzero balance shown elsewhere on the same screen, and this document does not treat the two as needing to agree. |
| A connectivity drop occurs mid-upload during Import Statement | The dropzone surfaces a distinct "Upload interrupted — retry" state rather than the generic processing shimmer; no partial statement is ever treated as imported, since the endpoint only creates `bank_statement_lines` after receiving and validating the complete file server-side. |
| A cross-currency transfer is approved but not yet settled | The destination account's `available_balance` on its own card does not reflect the converted amount until the counterpart `transfer_in` transaction actually clears — this screen shows the transfer itself at `scheduled`/`submitted` status in the Transfers tab, never an anticipatory balance bump on the Accounts tab that could disagree with the ledger if the transfer later fails. |
| The Liquidity Ratio breaches its policy floor while a user is looking at this screen | `bank.liquidity_ratio.breached` patches the Liquidity tile's caption and delta color live, and the AI Treasury panel's next refresh (its own 10-second/focus-triggered refetch) picks up the corresponding alert — the two are separately timed signals that can briefly disagree by a few seconds, an accepted, disclosed tradeoff rather than a synchronization bug. |
| WebSocket disconnected for an extended period, then reconnects | Every realtime-fed query key on this screen is invalidated once, as a single batch, on reconnect — a missed fraud hold or cleared transaction during the outage surfaces the moment connectivity returns, never waiting for a manual refresh. |
| A user wants a printable or exported copy of what this screen shows | Not supported as a literal "print this page" action — five independently cached, independently timestamped regions with live AI provenance styling are not a defensible single-instant record. A user needing a shareable artifact is pointed at the Cash Position, Bank Reconciliation, or Liquidity Dashboard reports, each with its own dedicated export path through the Reports module. |
| A very long bilingual bank or branch name overflows a card's fixed width | The name truncates with an ellipsis and exposes the full string via a native tooltip/`aria-label`; the card's balance figures never shrink or wrap to accommodate a long name, since the numbers are the card's primary content. |

# End of Document
