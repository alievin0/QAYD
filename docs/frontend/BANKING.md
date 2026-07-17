# Banking — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: BANKING
---

# Purpose

This document specifies the Banking screen: the operational home for a company's bank accounts, cash
accounts, wallets, and virtual accounts inside the QAYD web application. It is the frontend counterpart
to `docs/accounting/BANKING.md` (which owns every business rule, table, and endpoint this screen
renders) and conforms to the cross-cutting rules in `FRONTEND_ARCHITECTURE.md`, `DESIGN_LANGUAGE.md`,
`COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and
`ACCESSIBILITY.md`. Where this document is silent, those documents govern; where this document appears
to contradict one of them, that is a defect to raise, not a decision to resolve unilaterally in code.

Banking is the screen a Finance Manager opens first every morning and last every evening: it answers,
at a glance, the same three questions `docs/accounting/BANKING.md`'s own Purpose section poses — *how
much cash do we have, where is it, and does our book balance agree with the bank's* — and it is the
launch point for every cash-side action a human is allowed to take: reading a balance, filtering a
transaction history, importing a statement, and initiating a transfer that a Finance Manager and then a
CEO must both sign off on before a single fils moves. Concretely, this document specifies the screen
that composes:

- A **live cash position band** — the aggregated, per-currency, real-time answer to "how much do we
  have," broken into current, available, and restricted balances, with a liquidity-ratio read-out and a
  13-week forecast trend contributed by the Forecast Agent.
- A **bank account card rail** — one card per `bank_accounts` row the caller can see, showing balance,
  available balance, currency, institution type, and status, each a doorway into that account's own
  register and its reconciliation history.
- A **transactions `DataTable`** — the searchable, filterable, sortable ledger of `bank_transactions`
  rows across every account (or scoped to one, when opened from a card), with row-level status, AI
  match/fraud provenance, and permission-gated row actions (approve, reject, void, reverse, reconcile).
- A **statement import affordance** — the entry point for the four ingestion channels
  `docs/accounting/BANKING.md → Bank Reconciliation` defines (Open Banking, structured file, spreadsheet,
  scanned PDF via Document AI/OCR), surfaced here rather than buried in a settings page, because
  importing a statement is a routine, frequent, first-class action for the roles that use this screen.
- **Transfer and approval affordances** — a permission-gated "New transfer" entry point that always
  routes to the full-page, never-a-modal `/banking/transfers/new` route, plus inline `ApprovalCard`s for
  any transaction or transfer awaiting the caller's own Finance-Manager-or-CEO sign-off, so the two-key
  rule is something a user sees and acts on here, not something they have to go hunting for in a
  separate Approval Center.
- **AI Treasury insight** — a dismissible briefing card synthesizing the Treasury Manager's live
  liquidity read, the Forecast Agent's cash-flow projection, and the CFO Agent's weekly narrative, so a
  cash-flow risk surfaces on the screen where a human can actually do something about it, without this
  screen ever computing that insight itself.
- **Links out**, never re-implementations, to the screens that own the deeper flows: the Bank
  Reconciliation workbench (`/banking/reconciliations/[id]`, its own screen document), the full
  Transfers list and creation form (`/banking/transfers*`), and the AI Command Center's Cash Flow
  Forecast and Treasury Briefing surfaces.

This screen, like every screen in QAYD, **owns no business logic**. It never computes a balance, never
decides whether a reconciliation match is good enough to auto-commit, never evaluates whether a transfer
is under the auto-approval threshold, and never lets an AI agent's confidence score substitute for a
human's approval on money leaving the company. Every number rendered here was computed and validated by
Laravel; every mutation this screen triggers is a call to `/api/v1/banking/...` guarded by the exact
permission key the API itself enforces (`FRONTEND_ARCHITECTURE.md`, Principle 1 and Principle 4).

# Route & Access

## App Router path

Matching `FRONTEND_ARCHITECTURE.md → App Router Structure` verbatim — this document does not introduce
a route the platform's own canonical tree does not already name:

```
app/(app)/banking/
├── layout.tsx                          # Sub-nav: Accounts | Transactions | Transfers (bank.read gate)
├── accounts/
│   ├── page.tsx                        # ★ THIS DOCUMENT — the Banking Home screen (cards + cash position + transactions)
│   └── [accountId]/
│       └── page.tsx                    # Single-account register: full transaction history for one bank_accounts row
├── transactions/
│   └── page.tsx                        # All-accounts transaction ledger — same <BankTransactionsTable/>, different default filters
├── reconciliations/
│   └── [id]/page.tsx                   # Bank Reconciliation workbench — its own screen document, linked from here
└── transfers/
    ├── page.tsx                        # Transfers list
    └── new/page.tsx                    # Sensitive — always full-page, never a quick-create modal (FRONTEND_ARCHITECTURE.md)
```

`accounts/page.tsx` is the screen this document specifies in depth: the account-card rail, the cash
position band, and an embedded, filtered instance of the same `<BankTransactionsTable/>` that
`transactions/page.tsx` renders full-width with a broader default filter (`defaultFilters={{}}` vs.
`accounts/page.tsx`'s recent-activity-only default) — one table implementation, two entry points,
exactly the pattern `COMPONENT_LIBRARY.md`'s `DataTable` already establishes elsewhere in the codebase.
`NAVIGATION_SYSTEM.md`'s module map (`# Primary Navigation`) points the Banking sidebar entry (icon
`Landmark`) directly at `/banking/accounts`, not at a bare `/banking` — there is deliberately no
`banking/page.tsx` file at the module's own root segment, matching the canonical route tree above; a
hand-typed or bookmarked hit on the bare `/banking` segment therefore falls through to the nearest
`not-found` boundary rather than resolving to anything, which is the ordinary, expected Next.js behavior
for a route group with a `layout.tsx` but no `page.tsx` of its own (see **Edge Cases**).

## Permission gate

| Gate | Permission | Effect if absent |
|---|---|---|
| Screen visible at all | `bank.read` | Sidebar's Banking entry (and this route) does not render; a direct hit to `/banking/accounts` renders the shell-level `error.tsx` with "You don't have access to this" per `NAVIGATION_SYSTEM.md → Permission-Aware Nav → Server truth, client courtesy`, never a silent redirect and never the Next.js default error screen. |
| Liquidity ratio tile, Treasury Dashboard link, AI Treasury panel | `treasury.read` | Tile renders with an em dash and a `Lock` icon plus a tooltip naming the missing permission, rather than being omitted — see **Edge Cases**. This is the one deliberate exception to "hide, don't disable" on this screen, because the tile's *existence* (a Finance Manager knows a liquidity ratio exists even before they can see it) is not itself sensitive, unlike a control whose existence would reveal a capability. |
| "Connect bank account" / edit account | `bank.account.create` / `bank.account.update` | Button omitted (not disabled) per the "hide, don't disable" default rule (`NAVIGATION_SYSTEM.md → Permission-Aware Nav`, rule 1) — this is a role-structural gap, not a plan-tier upsell or an unconfigured integration, so it does not qualify for that document's narrow disabled-with-a-reason exception. |
| "Import statement" | `bank.reconcile` | Button omitted. |
| "New transaction" (manual entry) | `bank.transaction.create` | Button omitted. |
| Row actions: Approve | `bank.payment.approve` | Action omitted from the row's `DropdownMenu`, and any `ApprovalCard` for that item renders its Approve/Reject pair disabled with a permission tooltip rather than omitted, because the card itself is already addressed to a specific approver and hiding it entirely would look like a missed notification rather than an access boundary — see `ApprovalCard`'s own `usePermission` gate in **Components Used**, and `ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves` for the required `aria-describedby` treatment. |
| Row actions: Final approve | `bank.payment.approve.final` | Same treatment; additionally, the button is disabled outright (not just permission-gated) when `finance_manager_approved_by === currentUser.id`, mirroring the server's `409 Conflict` two-key rule (`docs/accounting/BANKING.md → Security`). |
| "New transfer" | `bank.transfer` | Button omitted; the module sub-nav's "Transfers" tab still shows (a Treasury user without `bank.transfer` can still view the list, gated separately by `bank.read`), but its own "New transfer" button is likewise omitted there. |
| Void / Reverse | `bank.transaction.void` / `bank.transaction.reverse` | Row action omitted. |
| Close / reopen a reconciliation period | `bank.reconcile.close` / `bank.reconcile.reopen` | Not on this screen — surfaced on the Bank Reconciliation workbench this screen only links to. |

## Roles

Reproduced from `docs/accounting/BANKING.md → Permissions`'s role assignment table and translated into
what a role concretely sees on **this** screen (not the API's permission grant in the abstract):

| Role | What renders |
|---|---|
| Owner, CEO, CFO | Full screen: cards, cash position, transactions with every row action, "New transfer," Treasury Dashboard link. Only CEO's approve button is ever the *final* one (`bank.payment.approve.final` is uniquely theirs per the role table). |
| Finance Manager | Full screen; approves as the *first* key (`bank.payment.approve`) — their "Approve" click always leaves an item at `pending_approval` awaiting a CEO, never fully approved. |
| Treasury | Full screen including Liquidity/Treasury Dashboard (`treasury.read`/`treasury.manage`); can initiate a transfer (`bank.transfer`) but cannot approve one at either key — their own transfers appear in the on-screen approval queue exactly like anyone else's, addressed to a Finance Manager. |
| Senior Accountant | Read + create/update/submit transactions and match reconciliation candidates; no account lifecycle actions (verify/freeze/close), no approval keys, no transfer creation. |
| Accountant | Read + create/update transactions only (cannot submit into the approval chain unassisted per the role table's "create/update only" note — the submit action is present but the resulting state still requires a Senior Accountant or above to move it forward in practice, enforced server-side); reconciliation matching only, not closing. |
| Auditor / External Auditor | Fully read-only: cards, cash position, and the transactions table render with every mutating control omitted, reconciliation views open read-only. |
| Read Only | Same as Auditor, minus even `treasury.manage` visibility distinctions — effectively the floor of this screen's permission surface. |
| AI service account | Never renders this screen as a "user" — its scoped read access feeds the AI panels described in **AI Integration**, and it is structurally incapable of holding `bank.transfer`, `bank.payment.approve`, or `bank.payment.approve.final` regardless of any role grant, per the platform-wide rule that sensitive banking operations are never AI-only. |

# Layout & Regions

Laptop (`lg`, 1024px) and up — per `RESPONSIVE_DESIGN.md`'s five-tier breakpoint system, **Mobile /
Tablet / Laptop / Desktop / Ultra Wide** — layout of `app/(app)/banking/accounts/page.tsx`, inside the
persistent app shell (`Sidebar`/`Topbar`, out of this document's scope per `NAVIGATION_SYSTEM.md`):

```
┌───────────────────────────────────────────────────────────────────────────────────────┐
│  Accounts   Transactions   Transfers                                    [sub-nav tabs] │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  Banking                                            [Import statement] [New transfer] │
│  Cash across 6 accounts · 3 currencies                              [+ Connect account]│
├───────────────────────────────────────────────────────────────────────────────────────┤
│ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐ ┌───────────────┐               │
│ │ Cash position  │ │ Available      │ │ Restricted     │ │ Liquidity      │  KPI band   │
│ │ KD 84,210.500  │ │ KD 79,004.100  │ │ KD 5,206.400   │ │ 1.42  ▲        │  (KpiTile×4)│
│ │ +2.4% ▲ ~~~~   │ │ ~~~~~~~~~~~~~ │ │ 3 holds        │ │ vs floor 1.20  │             │
│ └───────────────┘ └───────────────┘ └───────────────┘ └───────────────┘               │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  ◄  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐        ►         │
│     │ NBK       │ │ KFH       │ │ Burgan    │ │ Investmnt │ │ Petty cash│  Account rail│
│     │ Operating │ │ Payroll   │ │ Overdraft │ │ (USD)     │ │ HQ        │  (scrollable)│
│     │ ●Active   │ │ ●Active   │ │ ●Active   │ │ ●Active   │ │ ●Active   │             │
│     │ KD 61,205 │ │ KD 12,004 │ │ KD −8,400 │ │ $30,769   │ │ KD 400    │             │
│     └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘                 │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  ┌ AI · Treasury Manager ──────────────────────────────────────────────────────────┐  │
│  │ ● Weekly treasury briefing — USD exposure grew 12% this week; Burgan overdraft   │  │
│  │   utilization crossed 70%.                        [Dismiss]  [View full report] │  │
│  └───────────────────────────────────────────────────────────────────────────────────┘  │
├───────────────────────────────────────────────────────────────────────────────────────┤
│  Recent transactions                              [Search…] [Filters ▾] [Density ▾]   │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐  │
│  │ ● Date      Account       Type              Description        Amount   Status │  │
│  │ ─────────────────────────────────────────────────────────────────────────────── │  │
│  │   Jul 16    NBK Operating Incoming payment   INV-2026-00417    +5,000.000 Cleared│  │
│  │ ○ Jul 16    Burgan O/D    Outgoing payment    BILL-2026-00891  −4,250.000 Pending│  │
│  │   Jul 15    KFH Payroll   Fee                Monthly maint.       −3.500 Cleared│  │
│  │ …                                                                    [1 2 3 …]  │  │
│  └─────────────────────────────────────────────────────────────────────────────────┘  │
│                                            [View all transactions →] (/banking/transactions)│
└───────────────────────────────────────────────────────────────────────────────────────┘
```

Region inventory:

| Region | Contents | Streaming boundary |
|---|---|---|
| Sub-nav | `Tabs`: Accounts (active) · Transactions · Transfers, each a real `<Link>`, not client-side tab state, per `banking/layout.tsx` (Server Component) | Not streamed — part of the layout shell |
| Page header | `<h1>Banking</h1>`, a one-line account/currency summary, and three permission-gated actions (`Import statement`, `New transfer`, `Connect account`) | Renders immediately with the page shell |
| Cash Position band | Four `KpiTile`s: Cash Position, Available Balance, Restricted Balance, Liquidity Ratio | Own `<Suspense>` boundary — `GET /banking/cash-position` and `GET /banking/liquidity` independently |
| Account rail | One `BankAccountCard` per `bank_accounts` row — horizontally scrollable at `lg`+, `snap-x` | Own `<Suspense>` boundary — `GET /banking/bank-accounts` |
| AI Treasury panel | A dismissible `AIProposalPanel`-style briefing card (CFO agent, synthesizing Treasury Manager + Forecast Agent) — omitted entirely if the caller's role has no AI-visibility scope | Own `<Suspense>` boundary; independently failable without blanking the rest of the page |
| Transactions table | `<BankTransactionsTable/>` (a preset `DataTable`) with search, filters, density toggle, row actions | Own `<Suspense>` boundary — `GET /banking/bank-transactions` |
| Footer link | "View all transactions" → `/banking/transactions` (same table, unfiltered) | n/a |

Each region streams independently behind its own `<Suspense>` boundary exactly per
`FRONTEND_ARCHITECTURE.md → Streaming with Suspense`: a slow Forecast/CFO-agent computation for the AI
panel never delays the account rail or the transactions table from painting, and a failure in one
region renders that region's own `ErrorState` without taking down the page (see **States**).

# Components Used

| Component | Source | Role on this screen |
|---|---|---|
| `Tabs` | Primitive (`components/ui/tabs.tsx`) | Accounts / Transactions / Transfers sub-nav, rendered by `banking/layout.tsx` |
| `KpiTile` | Finance (`components/dashboard/kpi-tile.tsx`) | Cash Position, Available Balance, Restricted Balance, Liquidity Ratio |
| `TrendSparkline` | Finance (`components/dashboard/trend-sparkline.tsx`) | 30-day trend inside the Cash Position tile; 13-week forecast shape inside the Liquidity tile's tooltip |
| `BankAccountCard` | **New** — `components/banking/bank-account-card.tsx` | One card per `bank_accounts` row in the account rail |
| `CurrencyTag` | Finance (`components/shared/currency-tag.tsx`) | ISO 4217 code chip on each card and in multi-currency table cells |
| `StatusPill` | Finance (`components/shared/status-pill.tsx`), domain `bank_account` / `bank_transaction` / `bank_transfer` | Account status on cards; transaction/transfer status in the table and in `ApprovalCard` titles |
| `AmountCell` | Finance (`components/accounting/amount-cell.tsx`) | Every balance and every transaction amount |
| `BankTransactionsTable` | **New** — `components/banking/bank-transactions-table.tsx`, wraps `DataTable` | The transactions region, reused verbatim by `accounts/page.tsx` and `transactions/page.tsx` |
| `AccountPicker` | Finance (`components/accounting/account-picker.tsx`) | GL account field inside the manual transaction create form (Expense Classification target) |
| `PeriodPicker` | Finance (`components/accounting/period-picker.tsx`) | Date-range filter above the transactions table |
| `ConfidenceBadge` | AI (`components/ai/confidence-badge.tsx`) | AI-suggested-match dot's tooltip; Fraud Detection risk score |
| `AIProposalPanel` | AI (`components/ai/ai-proposal-panel.tsx`) | CFO weekly treasury briefing; Payment Suggestions banner |
| `ApprovalCard` | Shared (`components/shared/approval-card.tsx`), `kind="bank_transfer"` | Any transaction/transfer awaiting the caller's own approval, surfaced inline |
| `StatementImportDropzone` | **New** — `components/banking/statement-import-dropzone.tsx` | The "Import statement" flow's upload surface |
| `TransferApprovalGate` | **New** — `components/banking/transfer-approval-gate.tsx` | The persistent, non-dismissible explainer banner on `/banking/transfers/new` describing the two-key rule (referenced here, fully specified on the Transfers screen document) |
| `Sheet` | Primitive (Radix side-dialog) | Row-click quick view of a transaction or an account's recent activity, slides from the inline-end edge (mirrors under RTL) |
| `DropdownMenu` | Primitive | Per-row and per-card actions, permission-filtered (items omitted, not disabled, for role-structural gaps) |
| `Dialog` / `AlertDialog` | Primitive | Reject-reason capture, fraud-hold acknowledgment, duplicate-suspected override |
| `Skeleton` | Primitive | Per-region loading placeholders (see **States**) |
| `EmptyState` / `ErrorState` | Shared | Zero-accounts onboarding, filtered-empty table, per-region fetch failure |

## `BankAccountCard`

```tsx
// components/banking/bank-account-card.tsx
'use client';

import { Card } from '@/components/ui/card';
import { AmountCell } from '@/components/accounting/amount-cell';
import { StatusPill } from '@/components/shared/status-pill';
import { CurrencyTag } from '@/components/shared/currency-tag';
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem }
  from '@/components/ui/dropdown-menu';
import { usePermission } from '@/hooks/use-permission';
import { Landmark, Wallet, Banknote, TrendingUp, MoreHorizontal } from 'lucide-react';
import type { BankAccount } from '@/types/banking';

const INSTITUTION_ICON: Record<BankAccount['institution_type'], typeof Landmark> = {
  commercial_bank: Landmark, islamic_bank: Landmark, investment_bank: TrendingUp,
  digital_bank: Landmark, payment_provider: Wallet, wallet: Wallet,
  cash: Banknote, petty_cash: Banknote,
};

interface BankAccountCardProps {
  account: BankAccount;
  onOpen: (accountId: number) => void;
}

export function BankAccountCard({ account, onOpen }: BankAccountCardProps) {
  const canFreeze = usePermission('bank.account.freeze');
  const canManageUsers = usePermission('bank.account.manage_users');
  const Icon = INSTITUTION_ICON[account.institution_type];

  return (
    <Card
      padding="md"
      interactive
      onClick={() => onOpen(account.id)}
      className="min-w-[220px] snap-start space-y-3"
    >
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-2 min-w-0">
          <Icon className="h-4 w-4 text-ink-500 shrink-0" aria-hidden />
          <span className="truncate text-sm font-medium text-ink-950" title={account.bank_name}>
            {account.bank_name}
          </span>
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
            <button aria-label={`Actions for ${account.bank_name}`}><MoreHorizontal className="h-4 w-4 text-ink-500" /></button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" onClick={(e) => e.stopPropagation()}>
            <DropdownMenuItem onSelect={() => onOpen(account.id)}>View register</DropdownMenuItem>
            {canManageUsers && <DropdownMenuItem>Manage authorized users</DropdownMenuItem>}
            {canFreeze && account.status === 'active' && (
              <DropdownMenuItem className="text-danger">Freeze account</DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      <div className="flex items-center gap-2">
        <StatusPill domain="bank_account" status={account.status} size="sm" />
        <CurrencyTag code={account.currency_code} />
      </div>

      <div>
        <p className="text-caption text-ink-500">Current balance</p>
        <AmountCell amount={account.current_balance} currencyCode={account.currency_code} emphasis="strong" />
      </div>
      <div>
        <p className="text-caption text-ink-500">Available</p>
        <AmountCell amount={account.available_balance} currencyCode={account.currency_code} />
      </div>
    </Card>
  );
}
```

`onOpen` navigates to `/banking/accounts/${accountId}` on a full click, but the card's own `DropdownMenu`
stops propagation so a menu action never also triggers navigation — a common composition bug this
component's own tests (`bank-account-card.test.tsx`, per `COMPONENT_LIBRARY.md`'s CI-enforced
co-located test rule) assert against directly. `StatusPill` gains a `bank_account` domain lookup table
this document owns (mirroring the existing `journal_entry` table's shape in `COMPONENT_LIBRARY.md`):

```tsx
// components/shared/status-pill.tsx — additions owned by this document
const BANK_ACCOUNT_STATUS: Record<string, { label: string; tone: Tone }> = {
  pending_verification: { label: 'Pending verification', tone: 'warning' },
  active:                { label: 'Active',               tone: 'success' },
  dormant:               { label: 'Dormant',               tone: 'neutral' },
  frozen:                { label: 'Frozen',                tone: 'danger'  },
  closed:                { label: 'Closed',                tone: 'neutral' },
};

const BANK_TRANSACTION_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:              { label: 'Draft',              tone: 'neutral' },
  pending_approval:   { label: 'Pending approval',   tone: 'warning' },
  approved:           { label: 'Approved',           tone: 'accent'  },
  rejected:           { label: 'Rejected',            tone: 'danger'  },
  scheduled:          { label: 'Scheduled',           tone: 'neutral' },
  submitted:          { label: 'Submitted',           tone: 'accent'  },
  pending_clearance:  { label: 'Pending clearance',   tone: 'warning' },
  cleared:            { label: 'Cleared',              tone: 'accent'  },
  reconciled:         { label: 'Reconciled',           tone: 'success' },
  failed:             { label: 'Failed',               tone: 'danger'  },
  voided:             { label: 'Voided',               tone: 'danger'  },
};

const BANK_TRANSFER_STATUS: Record<string, { label: string; tone: Tone }> = {
  draft:               { label: 'Draft',                 tone: 'neutral' },
  pending_approval:    { label: 'Pending approval',      tone: 'warning' },
  approved:            { label: 'Approved',               tone: 'accent'  },
  scheduled:           { label: 'Scheduled',              tone: 'neutral' },
  submitted:           { label: 'Submitted',              tone: 'accent'  },
  completed:           { label: 'Completed',              tone: 'success' },
  rejected:            { label: 'Rejected',                tone: 'danger'  },
  failed:              { label: 'Failed',                  tone: 'danger'  },
  voided:              { label: 'Voided',                  tone: 'danger'  },
};

Object.assign(STATUS_TABLES, {
  bank_account: BANK_ACCOUNT_STATUS,
  bank_transaction: BANK_TRANSACTION_STATUS,
  bank_transfer: BANK_TRANSFER_STATUS,
});
```

`cleared` and `reconciled` are deliberately different tones even though both describe a "good" outcome:
`cleared` (accent) marks a transaction the bank has confirmed but reconciliation has not yet proven
against a statement line, while `reconciled` (success) marks the fully closed loop — matching
`DESIGN_LANGUAGE.md`'s semantic table, which names "reconciled" explicitly under the `positive`/success
meaning column, distinct from "the system merely processed this" (accent, the same hue the AI layer's
provenance dot uses, which is the correct family for "something QAYD's own pipeline did automatically"
rather than "a human- or bank-verified fact").

## `BankTransactionsTable`

A thin, preset wrapper over `DataTable` (`COMPONENT_LIBRARY.md`) rather than a bespoke table — this
screen never hand-rolls sorting, pagination, or filtering, all of which `DataTable` already implements
against `docs/api/API_PAGINATION.md`/`API_FILTERING_SORTING.md`'s query grammar:

```tsx
// components/banking/bank-transactions-table.tsx
'use client';

import { DataTable } from '@/components/shared/data-table';
import { AmountCell } from '@/components/accounting/amount-cell';
import { StatusPill } from '@/components/shared/status-pill';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { FormattedDate } from '@/components/shared/formatted-date';
import type { ColumnDef } from '@tanstack/react-table';
import type { BankTransaction } from '@/types/banking';
import { Eye, Check, X, Undo2, Ban, GitMerge } from 'lucide-react';

interface BankTransactionsTableProps {
  defaultFilters?: Record<string, unknown>;
  onRowClick: (row: BankTransaction) => void;
}

export function BankTransactionsTable({ defaultFilters, onRowClick }: BankTransactionsTableProps) {
  const columns: ColumnDef<BankTransaction>[] = [
    {
      accessorKey: 'value_date', header: 'Date',
      cell: ({ row }) => <FormattedDate value={row.original.value_date} />,
    },
    { accessorKey: 'bank_account.bank_name', header: 'Account' },
    { accessorKey: 'transaction_type', header: 'Type' },
    { accessorKey: 'description', header: 'Description' },
    {
      accessorKey: 'amount', header: 'Amount',
      cell: ({ row }) => (
        <AmountCell
          amount={row.original.amount}
          currencyCode={row.original.currency_code}
          mode={['deposit', 'incoming_payment', 'transfer_in', 'interest_earned'].includes(row.original.transaction_type) ? 'debit' : 'credit'}
        />
      ),
    },
    {
      id: 'ai_match', header: '',
      cell: ({ row }) => row.original.ai_match_confidence != null && (
        <ConfidenceBadge
          size="sm" showLabel={false}
          confidence={normalizeConfidence(row.original.ai_match_confidence, 'percentage')}
          reasoning={row.original.ai_match_reasoning}
        />
      ),
    },
    {
      accessorKey: 'status', header: 'Status',
      cell: ({ row }) => <StatusPill domain="bank_transaction" status={row.original.status} />,
    },
  ];

  return (
    <DataTable
      columns={columns}
      resource="banking/bank-transactions"
      paginationMode="page"
      defaultSort="-value_date"
      defaultFilters={defaultFilters}
      searchable
      onRowClick={onRowClick}
      getRowId={(row) => String(row.id)}
      emptyState={{ title: 'No transactions yet', description: 'Import a statement or record a manual transaction to see activity here.' }}
      rowActions={(row) => [
        { label: 'View', icon: Eye, onClick: () => onRowClick(row) },
        { label: 'Approve', icon: Check, permission: 'bank.payment.approve', onClick: () => approveTransaction(row.id) },
        { label: 'Reject', icon: X, permission: 'bank.payment.approve', onClick: () => openRejectDialog(row.id) },
        { label: 'Reconcile', icon: GitMerge, permission: 'bank.reconcile', onClick: () => router.push(`/banking/reconciliations/${row.reconciliation_id}`) },
        { label: 'Void', icon: Ban, permission: 'bank.transaction.void', onClick: () => voidTransaction(row.id) },
        { label: 'Reverse', icon: Undo2, permission: 'bank.transaction.reverse', onClick: () => reverseTransaction(row.id) },
      ]}
    />
  );
}
```

The small, label-less `ai_match` column renders nothing for a manually entered or fully human-matched
row — it is reserved exclusively for the Banking Agent's own provenance dot (see **AI Integration**),
and its absence is itself information: a row with no dot was never touched by the matching engine at
all, distinct from a row the engine touched and scored below the "worth showing" floor (which simply
never reaches this table with a score attached, per `docs/accounting/BANKING.md → AI Responsibilities`,
"scores <70 are hidden from the default workbench view"). `rowActions` omits, per row, any item whose
`permission` the caller lacks — `DataTable`'s own contract, reused unmodified — so an Auditor's row menu
never contains anything past "View."

# Data & State

## Endpoints

Every figure and every row on this screen resolves to an endpoint `docs/accounting/BANKING.md → API`
already defines; this screen introduces no endpoint of its own.

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| Bank account roster | `GET /api/v1/banking/bank-accounts` | `bank.read` | Feeds the account rail; supports `?filter[status]=`, `?filter[account_type]=`, `?filter[institution_type]=`, `?filter[currency_code]=` |
| Cash position | `GET /api/v1/banking/cash-position` | `bank.read` | Feeds the Cash Position / Available / Restricted `KpiTile`s in one call — served from `bank_account_balances_mv`, never re-aggregated client-side |
| Liquidity | `GET /api/v1/banking/liquidity` | `treasury.read` | Feeds the Liquidity Ratio `KpiTile` and its "vs floor 1.20" caption |
| Cash-flow forecast | `GET /api/v1/banking/cash-flow-forecast` | `bank.read` | Feeds the Liquidity tile's tooltip sparkline and the AI Treasury panel's cited forecast bucket |
| Currency exposure | `GET /api/v1/banking/currency-exposure` | `treasury.read` | Feeds the "View full report" destination on the AI Treasury panel (the Treasury Dashboard, out of this document's scope) |
| Transactions (embedded, recent-only) | `GET /api/v1/banking/bank-transactions` | `bank.read` | `accounts/page.tsx`'s default filter narrows to `?filter[value_date][gte]=<today-30d>`; `transactions/page.tsx` requests the same resource with `defaultFilters={{}}` |
| Single account detail | `GET /api/v1/banking/bank-accounts/{id}`, `GET /api/v1/banking/bank-accounts/{id}/balance` | `bank.read` | The `[accountId]` register this screen's cards link into |
| Reconciliation status per account | `GET /api/v1/banking/bank-accounts/{id}/reconciliations` | `bank.read` | Not rendered inline on this screen — consulted only to compute the account card's optional "reconciliation overdue" badge, sourced from the same call the Bank Reconciliation screen document owns in full |
| Statement import | `POST /api/v1/banking/statement-imports` | `bank.reconcile` | "Import statement" flow |
| Statement import status | `GET /api/v1/banking/statement-imports/{id}` | `bank.reconcile` | Polled while `status = processing` |
| Manual transaction create | `POST /api/v1/banking/bank-transactions` | `bank.transaction.create` | "New transaction" flow |
| Submit / approve / reject / void / reverse | `POST /api/v1/banking/bank-transactions/{id}/submit\|approve\|approve-final\|reject\|void\|reverse` | per-action, see **Route & Access** | Row actions and `ApprovalCard` actions |
| Transfers list | `GET /api/v1/banking/transfers` | `bank.read` | "Transfers" sub-nav tab |
| Create / approve / approve-final a transfer | `POST /api/v1/banking/transfers`, `POST /api/v1/banking/transfers/{id}/approve\|approve-final` | `bank.transfer`, `bank.payment.approve`, `bank.payment.approve.final` | Always on `/banking/transfers/new` or via an inline `ApprovalCard`, never a modal on this screen |
| AI Treasury briefing | `GET /api/v1/ai/decisions?agent_code=TREASURY_AGENT,CFO_AGENT&decision_type=treasury_cash_position_summary,treasury_liquidity_alert&status=proposed,approved&limit=3` | `reports.read` | Feeds the AI Treasury panel; the same `ai_decisions` table every AI-authored surface in the product reads, per `docs/ai/agents/TREASURY_AGENT.md → Outputs` |

## Query keys and cache tuning

```ts
// lib/api/query-keys.ts (banking-scoped factories, additive to the platform's shared factories)
export const bankingKeys = {
  all: ["banking"] as const,
  accounts: (filters?: BankAccountFilters) => [...bankingKeys.all, "accounts", filters ?? {}] as const,
  account: (id: number) => [...bankingKeys.all, "accounts", id] as const,
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

Every top-level key is implicitly company-scoped because `QueryClient` itself is re-created on company
switch rather than shared across companies (`FRONTEND_ARCHITECTURE.md → Query key architecture`) — a
component that forgets to invalidate `bankingKeys.*` on a company switch still cannot serve a stale
company's cash position, because there is no live cache instance left holding it.

Cache tuning follows `FRONTEND_ARCHITECTURE.md → Cache tuning by data class` exactly, applied to this
screen's own resources — nothing here invents a new data class or a bespoke `staleTime`:

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Live/derived figures | `cashPosition`, `liquidity`, `accounts` (balances) | `0` (always stale) | Correctness over avoiding a refetch; kept fresh primarily via Realtime invalidation, not polling — the same class DASHBOARD.md's own Cash Position tile uses |
| Transactional lists | `transactions`, `transfers` | `30_000` | Frequent enough writes (a statement import can land dozens of rows at once) that a stale list is a real annoyance, not worth sub-minute polling |
| Rarely-changing reference | `forecast` (recomputed by the Forecast Agent on its own 4-hour schedule, per `docs/accounting/BANKING.md → Performance`) | `900_000` (15 min), served with its own `generated_at` | No benefit to polling faster than the source recomputes; the tile's tooltip shows the cached `generated_at` explicitly rather than implying a live number |
| AI feeds | `aiTreasuryKeys.briefing` | `10_000`, `refetchOnWindowFocus: true` | Matches the platform's AI-feed row verbatim — a returning Finance Manager should see what the Treasury Manager/CFO Agent produced overnight |

## Server-first paint, then Suspense per region

`app/(app)/banking/accounts/page.tsx` is a Server Component that prefetches the account roster (the one
query every other region's first paint benefits from having warm) and streams every other region behind
its own `<Suspense>` boundary, mirroring the exact pattern `AI_COMMAND_CENTER.md`'s own page component
uses:

```tsx
// app/(app)/banking/accounts/page.tsx
import { Suspense } from "react";
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { bankingKeys } from "@/lib/api/query-keys";
import { Can } from "@/components/auth/can";
import { WidgetErrorBoundary } from "@/components/shared/widget-error-boundary";
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";
import { CashPositionBand } from "@/components/banking/cash-position-band";
import { AccountRail } from "@/components/banking/account-rail";
import { AiTreasuryPanel } from "@/components/banking/ai-treasury-panel";
import { BankTransactionsTable } from "@/components/banking/bank-transactions-table";
import { BankingPageHeader } from "@/components/banking/banking-page-header";

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
          <Suspense fallback={<WidgetSkeleton variant="kpi-strip" />}>
            <CashPositionBand />
          </Suspense>
        </WidgetErrorBoundary>
        <WidgetErrorBoundary widgetId="account_rail">
          <Suspense fallback={<WidgetSkeleton variant="rail" />}>
            <AccountRail />
          </Suspense>
        </WidgetErrorBoundary>
        <Can permission="reports.read">
          <WidgetErrorBoundary widgetId="ai_treasury_panel">
            <Suspense fallback={<WidgetSkeleton variant="ai-card" />}>
              <AiTreasuryPanel />
            </Suspense>
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

Each region's `<Suspense>` boundary is paired with its own `WidgetErrorBoundary` one level inside the
fallback, per `FRONTEND_ARCHITECTURE.md`'s three-granularity error model — a Forecast Agent timeout on
the AI Treasury panel never delays the account rail or blanks the transactions table beside it (see
**States**). The Cash Position band and Account rail are the two regions prefetched most aggressively
because they answer the screen's single most time-critical question ("how much do we have, right now")
— everything else, including the AI panel, is allowed to resolve a beat later without the page feeling
slow.

## Realtime

Banking subscribes to one company-scoped, module-wide private channel, mirroring the existing
`private-company.{id}.approvals` and `private-company.{id}.ai-jobs` naming convention rather than
minting per-account channels that would multiply WebSocket subscriptions for a company with a dozen
accounts:

| Channel | Events carried | Effect |
|---|---|---|
| `private-company.{id}.banking` | `bank.transaction.created`, `bank.transaction.cleared`, `bank.transaction.approved`, `bank.transaction.rejected`, `bank.transfer.completed`, `bank.reconciliation.closed`, `bank.statement.imported`, `bank.fraud_flag.raised`, `bank.liquidity_ratio.breached` (the exact webhook list `docs/accounting/BANKING.md → API` defines) | Drives cache invalidation/patching, see below |
| `private-company.{id}.ai-jobs` | Any `ai_decisions` row authored by `TREASURY_AGENT`, `CFO_AGENT`, `FORECAST_AGENT`, or the Banking Agent finishing a matching sweep | Refreshes the AI Treasury panel and the transactions table's `ai_match` confidence dots the moment an agent run affecting this screen completes |
| `private-company.{id}.notifications.{user_id}` | `bank.open_banking.consent_expiring`, `bank.duplicate_suspected` | Feeds the Topbar's notification bell only — this screen's own regions do not duplicate this stream, matching `DASHBOARD.md`'s identical rule |

Event handling follows `FRONTEND_ARCHITECTURE.md → Invalidate, or patch — chosen per event, not by
default`:

- `bank.transaction.cleared` and `bank.transfer.completed` invalidate `bankingKeys.cashPosition()`,
  `bankingKeys.liquidity()`, and the relevant `bankingKeys.accounts()`/`bankingKeys.transactions()`
  entries as a full `invalidateQueries` call — a balance is never patched optimistically from a
  realtime push, because the push itself carries no guarantee it reflects every concurrent write that
  landed in the same instant.
- `bank.statement.imported` invalidates `bankingKeys.transactions()` and the account rail (a fresh
  import can move `available_balance` if any auto-created fee/interest transaction posted alongside
  it), and additionally invalidates `aiTreasuryKeys.briefing()` since a new import is exactly the kind
  of event that can move the Treasury Manager's next liquidity read.
- `bank.fraud_flag.raised` and `bank.liquidity_ratio.breached` patch the affected `ApprovalCard`/`KpiTile`
  in place (a `setQueryData` patch, not a full refetch) so a held payment's left-border treatment or a
  breached-floor caption updates live without disturbing a user's current scroll position or an
  in-progress read of the surrounding table, mirroring `AI_COMMAND_CENTER.md`'s identical "Fraud
  Detection holds a payment while its card is already open" rule.
- On WebSocket reconnect after any extended drop, every one of this screen's realtime-fed query keys
  (`bankingKeys.*`, `aiTreasuryKeys.briefing()`) is invalidated once, as a single batch — a missed
  `bank.fraud_flag.raised` event during an outage must surface the moment connectivity returns, never
  wait for a manual refresh, exactly as `AI_COMMAND_CENTER.md` and `DASHBOARD.md` both specify for their
  own realtime-fed panels.

## AI agents feeding this screen

| Agent | Contribution to this screen |
|---|---|
| `TREASURY_AGENT` (Treasury Manager) | Computes the Liquidity Ratio `KpiTile`'s figure and its policy-floor comparison; authors the `treasury_cash_position_summary`/`treasury_liquidity_alert` decisions the AI Treasury panel renders; contributes the "vs floor 1.20" caption's underlying formula, fully cited on hover |
| `TREASURY_AGENT` (Banking Agent child, per `docs/ai/agents/BANKING_AGENT.md`) | Populates each transactions-table row's `ai_match_confidence`/`ai_match_reasoning` dot; authors `bank_duplicate_flag` (the duplicate-suspected blocking modal) and document-first backfill proposals (surfaced as an `ApprovalCard`) |
| `FORECAST_AGENT` | Supplies the 13-week cash-flow projection cited inside the Liquidity tile's tooltip and inside the AI Treasury panel's reasoning, per its own confidence band per week — this screen never recomputes the forecast, only renders the Treasury Manager's already-consumed reading of it |
| `CFO_AGENT` | Synthesizes Treasury Manager, Forecast Agent, and Fraud Detection signals into the AI Treasury panel's plain-language weekly briefing headline |
| Fraud Detection | Screens every `outgoing_payment`/`transfer_out`/`wire_transfer_out` before it can leave `pending_approval`; a `hold_recommended = true` flag renders the mandatory, non-dismissible second-look banner described in **AI Integration** |
| Document AI / OCR Agent | Extracts statement lines from a PDF/scanned upload during the Import Statement flow; per-field confidence below 95% surfaces in the import review step this screen's dropzone opens into |
| Expense Classification (Banking Agent sub-capability) | Pre-fills the GL account/dimension fields on the manual transaction create form when a description lacks a clear source document |

# Interactions & Flows

**Opening the screen.** The page shell (sub-nav, header, permission-gated actions) paints immediately;
the Cash Position band and Account rail resolve first, since the prefetched `bank-accounts` query and
the materialized `bank_account_balances_mv` behind `cash-position`/`liquidity` are both cheap, and the
AI Treasury panel and transactions table stream in independently a beat later — no region blocks another,
per **Data & State**.

**Opening an account card.** A full click anywhere on a `BankAccountCard` outside its `DropdownMenu`
navigates to `/banking/accounts/{accountId}`, that account's own register (full transaction history for
one `bank_accounts` row, its own screen document). This is a real navigation, never a `Sheet` or
`Dialog` opened over the Banking Home screen — an account register is substantial enough content (a
filterable ledger of potentially thousands of rows) that it deserves its own URL, bookmarkable and
shareable, exactly as `FRONTEND_ARCHITECTURE.md`'s route-tree already anticipates with its dedicated
`[accountId]/page.tsx` segment.

**Importing a statement.** "Import statement" (`bank.reconcile`) opens a `Dialog` hosting
`StatementImportDropzone`: a bank-account picker, a format selector (`mt940` / `camt053` / `csv` / `xlsx`
/ `pdf`), and a drop target. Selecting `csv`/`xlsx` for a bank the company has not imported from before
prompts a one-time column-mapping step (`import_template`, per `docs/accounting/BANKING.md → Bank
Reconciliation`); every subsequent import from that same `bank_name` skips the step. Submitting calls
`POST /banking/statement-imports` and the dialog immediately shows a `status: processing` state, polling
`GET /banking/statement-imports/{id}` every few seconds (never blocking the rest of the screen — a large
import from a busy payment-provider account can take real time to process per `docs/accounting/
BANKING.md → Performance`'s queued-job design) until it reaches `completed` or `failed`. A `pdf` import
additionally surfaces a review step listing any OCR-extracted field below the 95% confidence threshold
for manual correction before the batch is treated as final, per `docs/accounting/BANKING.md → AI
Responsibilities`'s Document AI/OCR Agent row. On completion, the dialog reports the exact
`{line_count, auto_matched, unmatched}` summary the endpoint returns and offers "Go to Reconciliation"
(`/banking/reconciliations/{id}`) as its primary next step — this screen never renders the reconciliation
workbench itself.

**Creating a manual transaction.** "New transaction" (`bank.transaction.create`) opens a full `Sheet`
(not the Banking Home page in place) with a `React Hook Form` + `Zod` form mirroring
`docs/accounting/BANKING.md`'s `bank_transactions` fields: account, type, amount/currency, value date,
payee, and an `AccountPicker` for the GL classification — pre-filled by the Expense Classification agent
where a confident suggestion exists, always editable, never auto-submitted. Saving creates a `draft` row
(`POST /banking/bank-transactions`); "Save and submit" additionally calls `.../submit` in the same flow,
moving the row into `pending_approval` if the type/amount requires the chain.

**Approving or rejecting an item.** Every `ApprovalCard` on this screen (`kind="bank_transfer"`, covering
both a pending `outgoing_payment`/`wire_transfer_out` `bank_transactions` row and an actual `transfers`
row, since both move cash out under the identical Finance-Manager-→-CEO chain and `ApprovalCard`'s own
`kind` enum carries no separate value for the two) exposes Approve/Reject to whichever role holds
`bank.payment.approve` for a first-key card or `bank.payment.approve.final` for a second-key one.
Approving opens no confirmation dialog for an ordinary journal-style approval elsewhere in the product,
but **every Approve/Reject on a `kind="bank_transfer"` card on this screen requires an explicit
confirming dialog naming the exact amount, account, and payee before it submits** — a deliberate,
Banking-specific tightening of `ApprovalCard`'s generic contract, justified by `FRONTEND_ARCHITECTURE.md`
Principle 10's own instruction that a financial mutation "always renders a confirming dialog before it
fires." Rejecting always requires a typed reason (`ApprovalCard`'s own mandatory-reason contract). If a
`hold_recommended` fraud flag is attached, the Approve button stays present but the mandatory
acknowledgment banner described in **AI Integration** must be explicitly dismissed with a typed reason
before the click is accepted — the dialog and the acknowledgment are two separate, both-required gates,
never collapsed into one.

**Initiating a transfer.** "New transfer" (`bank.transfer`) is a real `<Link href="/banking/transfers/new">`,
never a modal — `docs/accounting/BANKING.md`'s own module rule and `FRONTEND_ARCHITECTURE.md`'s
sensitive-mutation rule agree on this point identically. That destination page (its own document) renders
`TransferApprovalGate`, a persistent, non-dismissible banner stating the two-key rule in plain language
before the form's first field.

**Working the transactions table.** Search, column sort, and the density toggle (`compact`/`comfortable`,
per `DESIGN_LANGUAGE.md → Data Density`) all round-trip through `DataTable`'s own query-param-driven
state — no client-side filtering ever runs against an already-fetched page. The Filters affordance opens
a small `Popover` exposing `transaction_type`, `status`, `bank_account_id`, and a `PeriodPicker`-backed
date range; applying a filter updates the URL's query string on `transactions/page.tsx` (making a
filtered view a shareable link) but stays purely in-memory, TanStack-Query-keyed state on the embedded
instance inside `accounts/page.tsx`, since that instance's whole purpose is a *recent-activity* preview,
not a bookmarkable report.

**Clicking a KPI tile.** Cash Position, Available, and Restricted tiles drill into the transactions table
below, pre-filtered to the relevant slice (Restricted → `status=pending_clearance,frozen_hold`); the
Liquidity Ratio tile, when `treasury.read` is held, opens the Treasury Dashboard rather than filtering
this screen's own table, since liquidity is a company-wide, cross-account concept the transactions table
cannot usefully filter down to.

**Dismissing or expanding the AI Treasury panel.** "Dismiss" calls the same dismiss-with-reason contract
`AIProposalPanel` uses platform-wide (`POST /api/v1/ai/decisions/{id}` transitioning `status` to
`rejected` with a required reason) and hides the card for the current session; "View full report"
navigates to the AI Command Center's Cash Flow Status / Treasury Briefing surface, never expanding a
second copy of that content inline on this screen.

# AI Integration

Every AI-authored element on this screen renders through the platform's one mandatory contract
(`FRONTEND_ARCHITECTURE.md → AI Integration Layer`): a `confidence_score`, a `reasoning`, and `sources`,
wrapped in the shared visual envelope, with no card ever showing a bare number the AI computed without
showing its work. Banking is, by the accounting module's own admission, the domain where this contract
is enforced most strictly of anywhere in the product, because a wrong AI output here is the class of
error that touches real money — every rule below inherits that posture rather than softening it for the
sake of a calmer-looking screen.

**The AI Treasury panel.** Rendered via `AIProposalPanel` in its read-mostly mode (no `recommended_action`
requiring a decision on the routine day), sourced from a `treasury_cash_position_summary` or
`treasury_liquidity_alert` `ai_decisions` row authored by the Treasury Manager and narrated by the CFO
Agent, per `docs/ai/agents/TREASURY_AGENT.md → Outputs`:

```json
{
  "agent_code": "TREASURY_AGENT",
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

The panel's `ConfidenceBadge` and `ReasoningDisclosure` render this exactly, unembellished; the panel
never states a figure the `payload` did not already carry, and every cited row in `sources` is a real,
resolvable link (a `bank_accounts` id opens that account's card; an `ai_decisions` id opens the
Forecast Agent's own cited projection) rather than plain text, matching `NAVIGATION_SYSTEM.md`'s "every
AI-surfaced target is a real, resolvable route" rule.

**Per-row match confidence.** `BankTransactionsTable`'s `ai_match` column renders a small `ConfidenceBadge`
dot (`showLabel={false}`) only for a row the Banking Agent's matching sweep actually scored — a manually
entered transaction or a fully bank-statement-sourced one with no AI involvement shows nothing there, not
a zero. Hovering or focusing the dot discloses the exact `reasoning_factors` that fired
(`exact_reference_match`, `amount_date_exact`, `fuzzy_counterparty`, `recurring_pattern`, `ai_similarity`),
reused verbatim from `docs/ai/agents/BANKING_AGENT.md → Outputs`'s own shape — this screen never
summarizes or paraphrases that reasoning into something shorter.

**Fraud holds — the one non-dismissible, non-calm treatment on this screen.** When Fraud Detection
attaches `hold_recommended: true` to a pending outgoing payment or transfer, its `ApprovalCard` renders
with a distinct `danger`-toned left border no other state on this screen uses (mirroring
`AI_COMMAND_CENTER.md`'s identical "held" treatment for its own Approval Center queue) and a banner
stating the specific flagged reason(s) — `new_payee`, `unusual_amount_for_payee`,
`unusual_time_of_day`, `velocity_spike`, `payee_details_changed_recently`, per `docs/accounting/
BANKING.md → AI Responsibilities`. The banner cannot be dismissed with a plain click: the approver must
type an explicit acknowledgment reason in a required `Textarea` before Approve becomes clickable at all,
and that reason is written to `audit_logs` alongside the eventual approval — this is the one screen-level
control this document adds on top of `ApprovalCard`'s generic contract, because the underlying business
rule (`docs/accounting/BANKING.md`: "a `hold_recommended = true` … forces a mandatory second-look banner
… and cannot be dismissed silently") has no generic equivalent elsewhere in the shared component.

**Duplicate-suspected, blocking.** A duplicate-likelihood signal on a new manual transaction or an
in-progress import surfaces as a blocking `AlertDialog` — "This looks identical to transaction #90490
posted 2 minutes ago — proceed anyway?" — that requires the same explicit typed-reason override pattern
once the score reaches 95, per `docs/ai/agents/BANKING_AGENT.md → Guardrails`. The dialog is not a toast
and does not auto-dismiss; the creating action is genuinely held until the user answers.

**Document-first backfill and payment-run proposals.** A Banking Agent backfill proposal (a statement line
with no candidate transaction, plausibly explained by an unlinked `receipts`/`vendor_payments` row) and a
Treasury Manager payment-run proposal both surface as an `ApprovalCard`/`AIProposalPanel` addressed to the
caller, carrying their own `confidence_score` and at least one named alternative — never a bare
"Accept"/"Reject" pair with no context, per `docs/ai/agents/TREASURY_AGENT.md`'s own worked example
("Pay all 3 bills today" / "Defer Salmiya past 2026-07-22," each with a stated tradeoff).

**The three-button pattern, and why "Do it" essentially never appears here.** `can_execute_directly` is a
server-computed field this screen never re-derives (`FRONTEND_ARCHITECTURE.md → The three-button
pattern`). For nearly everything on this screen, that field resolves to `false`: `bank.transfer`,
`bank.payment.approve`, and `bank.payment.approve.final` are permissions the AI Agent principal type can
never structurally hold (`docs/accounting/BANKING.md → Permissions`, the AI Agent role row), so an AI
proposal whose resolution requires one of those three permissions never renders a "Do it" button at all —
only "Send for approval" and "Dismiss." The rare exception is the Banking Agent's own narrow, deterministic
auto-commit path for a reconciliation match that already cleared the confidence threshold anchored by a
real deterministic rule (`docs/accounting/BANKING.md → Bank Reconciliation`): that action has already
happened by the time this screen renders the row (the transaction shows `reconciled` with its provenance
dot), so there is no pending "Do it" click on this screen for it either — the auto-commit is a fact this
screen displays after the event, never a button a human presses. Concretely, no control on the Banking
screen ever lets a click execute a transfer, a payment approval, a reconciliation-period close, or an
account freeze; every one of those routes through the human-only endpoints `docs/ai/tools/BANKING_TOOLS.md`
documents as absent from every AI agent's tool registry.

# States

No region on this screen ever falls through to a bare blank void or an unrecoverable crash; every region
ships its own loading, empty, and error presentation, and a failure in one never blocks another.

| Region | Loading | Empty | Error |
|---|---|---|---|
| Cash Position band | Four `KpiTile`s in their own `loading` prop (`Skeleton` in place of value/delta) | A brand-new company with zero `bank_accounts` renders a single "Connect your first bank account to see your cash position" card spanning all four tile slots, not four independently empty tiles | Each of `cash-position`/`liquidity` fails independently (they are two calls); a `liquidity` failure alone shows only the Liquidity tile's inline "Couldn't load — Retry," leaving the other three intact |
| Account rail | Skeleton cards at the same `min-w-[220px]` shape as `BankAccountCard` | "No bank accounts yet" with a "Connect account" CTA (permission-gated identically to the header's own button) | Inline retry card in place of the rail; the rest of the page renders normally |
| AI Treasury panel | A skeleton chip plus one skeleton card, matching `AI_COMMAND_CENTER.md`'s own AI-panel skeleton shape | A calm "No treasury insights right now — cash position looks steady" message, explicitly not styled as a warning (an empty AI panel is good news, matching `AI_COMMAND_CENTER.md`'s identical "Nothing waiting on you" precedent for its Approval Center) | A distinct "AI insights are temporarily unavailable" state (not a generic error, not an infinite spinner) when the AI engine returns `503`, respecting any `Retry-After` header — the Cash Position band and Account rail are entirely unaffected, since neither calls the AI engine |
| Transactions table | `DataTable`'s own 8-row skeleton (`COMPONENT_LIBRARY.md`) | Two distinct empty copies per `DataTable`'s own contract: "No transactions yet — import a statement or record one manually" (a genuinely empty account) vs. a lighter "No rows match your filters" (a filtered-to-zero result) — never presented identically | `ErrorState` inline in place of the table body; the header's action buttons remain interactive |
| Import Statement dialog | A determinate-where-possible progress state while `status: processing`; an indeterminate shimmer only for the brief window before the first status poll returns | Not applicable — the dialog's own empty state is "no file selected yet," the dropzone's default idle appearance | `BALANCE_CHECK_FAILED` and `OCR_LOW_CONFIDENCE` render inline in the dialog itself (the former blocks the import outright with a re-upload prompt; the latter is advisory and lets the batch proceed to the field-correction review step), per `docs/accounting/BANKING.md`'s import-integrity rule |

A route-level `error.tsx` at `banking/accounts/` remains the outermost safety net for a genuinely
unexpected failure (the session itself becoming invalid mid-render); per the three-granularity model this
should essentially never fire for an individual region's ordinary `4xx`/`5xx`, which are caught and
handled inline, one region at a time.

# Responsive Behavior

Banking follows `RESPONSIVE_DESIGN.md`'s five semantic device tiers exactly — **Mobile** (unprefixed,
`sm:` at 640px), **Tablet** (`md:`, 768px), **Laptop** (`lg:`, 1024px), **Desktop** (`xl:`/`2xl:`,
1280px/1536px), **Ultra Wide** (`3xl:`, 1920px) — never a bespoke breakpoint of its own.

| Tier | Behavior |
|---|---|
| Mobile (<768px) | The Cash Position band becomes a horizontal-scroll, snap-aligned carousel, one `KpiTile` per screen-width, matching `RESPONSIVE_DESIGN.md → Dashboard Reflow`'s identical treatment for the AI Command Center's own KPI row; the Account rail (already horizontally scrollable at every tier) narrows each card to a slightly taller, single-column-friendly shape; the AI Treasury panel, transactions table, and page-header actions each stack full-width in DOM order. The Transactions table itself switches from a `<table>` to `RESPONSIVE_DESIGN.md → Pattern 1`'s card list — each row becomes a compact card showing date, account, amount, and status, with the AI match dot and remaining fields reachable via the card's own row-click `Sheet`, never crammed into a horizontally-scrolling table on a 375px screen. The persistent Sidebar is replaced by the bottom tab bar; a "New transaction"/"Import statement" quick action is reachable from the tab bar's center "Create" sheet in addition to this screen's own header. |
| Tablet (768–1023px) | The Cash Position band becomes two tiles per row; the transactions table gains its second priority column (per `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`) before fully graduating to a real table at Laptop. |
| Laptop (1024–1279px) and up | The full layout in **Layout & Regions**' wireframe: four-tile KPI row, scrollable account rail, and the transactions table rendered as a real `<table>` with every priority column visible, no horizontal scroll. |
| Ultra Wide (≥1920px) | Unchanged bento shape at generous margins (content capped at `max-w-[1440px]`, per `DESIGN_LANGUAGE.md → Spacing & Grid`); the platform's global `360px` AI Rail companion panel may dock inline alongside this screen's own content without competing with the AI Treasury panel, which remains part of this screen's own region set regardless of whether the ambient Rail is open — the same relationship `NAVIGATION_SYSTEM.md` describes between the Rail and any page-specific AI surface. |

**Touch targets and gestures.** Every interactive element — card menu triggers, row-action buttons,
Approve/Reject — maintains the platform's 44×44px minimum hit area with an 8px minimum gap between
adjacent controls (`RESPONSIVE_DESIGN.md → Touch Targets & Gestures`), material here specifically because
Approve and Reject sit side by side on an `ApprovalCard` and a mis-tap carries real financial
consequences. A swipeable `ApprovalCard` on Mobile/Tablet (swipe-to-approve, direction fully mirrored
under RTL per the platform's gesture catalog) still opens the identical confirming dialog a desktop click
would — a swipe changes the entry point into the confirmation, never the confirmation step itself, and a
fraud-held card's mandatory acknowledgment banner cannot be swiped past under any circumstance. Approving
a sensitive item from a mobile session additionally requires a biometric re-confirmation step layered on
top of, never instead of, the ordinary permission and confirmation-dialog checks, mirroring
`AI_COMMAND_CENTER.md → Responsive Behavior`'s identical mobile-approval rule.

**Virtualization.** Once the transactions table's underlying result set exceeds roughly 200 rows (common
for a busy operating account after a few months), rows render through `@tanstack/react-virtual` rather
than the plain DOM table, per `RESPONSIVE_DESIGN.md → Virtualization at scale` — the estimator's
`estimateSize` reads the active tier (40px dense Laptop-and-up rows vs. 56px Mobile/Tablet card rows) so
the same data renders correctly at every width without a bespoke per-screen virtualization config.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md` and the platform's RTL contract and applied,
concretely, to this screen's specific content — Banking introduces no direction-handling code of its own.

- **Logical properties only.** The account rail's scroll direction, the KPI band's reading order, the
  page header's action-button order, and every card's icon/label pairing use `ms-*`/`me-*`/`text-start`/
  `text-end` exclusively; flipping `dir="rtl"` on `<html>` mirrors the whole screen — sidebar to the
  opposite edge, the rail scrolling start-to-end in the opposite physical direction, the "Import
  statement"/"New transfer" button order reversed — with zero screen-specific RTL code.
- **Numerals, currency codes, IBANs, and dates never mirror.** Every `AmountCell` (account balances,
  transaction amounts, the liquidity ratio's `1.42` figure), every `ConfidenceBadge` percentage, and every
  IBAN fragment shown in a card's tooltip or an account's detail sheet renders inside a `dir="ltr"` /
  `unicode-bidi: isolate` span, exactly as `AmountCell`'s own implementation already forces. This matters
  more on this screen than almost anywhere else in the product: an IBAN is a long alphanumeric string
  (`KW81CBKU0000000000001234560101`) that must read as one unbroken, correctly-ordered token even inside a
  fully Arabic sentence describing the account, and QAYD never renders it with Eastern Arabic-Indic
  digits regardless of locale, matching `AmountCell`'s `numberingSystem: 'latn'` rule.
- **Numeric alignment is physically fixed, not logical (the platform's documented Exception A).** The
  transactions table's Amount column and every balance figure on a `BankAccountCard` use hard-coded
  `text-right`, not `text-end`, in both directions, so a bilingual Finance team scanning magnitude and
  decimal alignment sees figures land on the same edge regardless of interface language.
- **The Liquidity tile's sparkline and the AI Treasury panel's cited forecast trend never mirror (Rule
  5).** Both keep a fixed left-to-right time axis (earliest to latest) in both `dir="ltr"` and `dir="rtl"`
  — only the tile's own position within the KPI row mirrors, never the chart's internal axis, matching
  `DASHBOARD.md`'s identical rule for its Revenue-vs-Expense chart and for the same reason: a mirrored
  trend line is one of the most common and most confusing RTL defects in Arabic-market financial software.
- **Bilingual institution and payee names.** `BankAccountCard`'s `bank_name` and any payee name surfaced
  in a transaction row or an `ApprovalCard` title render `name_en`/`name_ar` per the active locale, with
  the API always returning both fields regardless of `Accept-Language`, per `COMPONENT_LIBRARY.md`'s
  bilingual-data convention — the client, never the server, owns which language displays.
- **AI-authored reasoning is never machine-translated by the frontend.** The Treasury Manager's and CFO
  Agent's Arabic narrative on the AI Treasury panel, and the Banking Agent's Arabic match-reasoning text
  in a `ConfidenceBadge` tooltip, both arrive already localized from the API per its content-negotiation
  contract; this screen's own localization responsibility is limited to chrome — button labels, empty-state
  copy, dialog titles — never the model's own words.

| Context | English | Arabic |
|---|---|---|
| Page title | Banking | الحسابات البنكية |
| Cash position tile | Cash position | الوضع النقدي |
| Liquidity caption | vs floor 1.20 | مقابل الحد الأدنى 1.20 |
| Import statement | Import statement | استيراد كشف الحساب |
| New transfer | New transfer | تحويل جديد |
| Fraud hold banner | Held — new payee, unusual amount for payee | معلّق — مستفيد جديد، مبلغ غير معتاد لهذا المستفيد |
| Approval action | Approve | اعتماد |
| Empty accounts | Connect your first bank account to see your cash position | أضف أول حساب بنكي لعرض وضعك النقدي |
| AI provenance | Suggested by the Treasury Manager — 92% confidence | مقترح من مدير الخزينة — بثقة 92% |

Arabic copy on this screen is authored directly by a fluent professional-register writer, not
machine-translated from the English strings above — a fraud-hold banner or a liquidity caption that
sounds precise and calm in English must sound identically precise and calm in Arabic, matching
`DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief.

# Dark Mode

Banking introduces no new color, elevation, or radius token — every surface on this screen resolves
through the same component-level tokens `BankAccountCard`, `KpiTile`, `StatusPill`, `AmountCell`,
`ApprovalCard`, and `AIProposalPanel` already ship, verified per-component rather than assumed. As
`DASHBOARD.md`'s own Dark Mode section already notes for the identical set of shared components, this
document names tokens the way the actual component implementations do (`ink-1`…`ink-12`,
`accent`/`accent-subtle`, `success`/`warning`/`danger`/`neutral` tones) rather than `DESIGN_LANGUAGE.md`'s
newer, differently-numbered token block — describing this screen's components with the newer names would
describe code that does not match what they actually render; reconciling the platform's two token
vocabularies is `COMPONENT_LIBRARY.md` and `DESIGN_LANGUAGE.md`'s own job, not an individual screen's.

- **Cards and tiles get lighter, not darker, in dark mode.** A `BankAccountCard` and a `KpiTile`'s
  background sits a step lighter than the canvas behind it in dark mode, matching the platform's "physical
  light" strategy rather than a naive inversion — an account card should still read as a card sitting
  above the page, not a hole cut into it.
- **The AI accent is reserved for provenance, never for status.** A held payment's `danger`-toned left
  border and a reconciled transaction's `success` pill never borrow the accent color, even though both
  are, in a loose sense, "things the AI touched" — the accent means "this is the primary action" or "the
  AI produced this," and collapsing it into "this is urgent" would destroy the one signal a user learns to
  read consistently across the whole product, per `DESIGN_LANGUAGE.md → Color & Ink`'s accent-is-AI's-
  signature rule. Concretely on this screen: the `ai_match` confidence dot is always accent-family; the
  fraud-hold border and banner are always `danger`; a company can tell "the AI is very sure" and "this is
  a critical hold" apart as two separately-colored facts on the same card, never one collapsed into the
  other.
- **Confidence indicators are re-tuned per theme, not linearly brightened.** `ConfidenceBadge`'s dark-mode
  value uses its own calibrated dark value rather than a naive brightness flip of the light-mode hex, so a
  94%-confidence fraud-flagged transaction reads as urgent in dark mode without over-saturating.
- **The Liquidity tile's sparkline and the AI Treasury panel's forecast trend** resolve their stroke/fill
  color through the shared JS-readable token export (`lib/tokens.ts`), never a CSS variable read at SVG
  render time, since an SVG `stroke` attribute cannot consume a Tailwind class the way a `div` background
  can — the same mechanism every other chart-bearing screen in the product already uses.
- **Debit and credit stay in plain ink in dark mode exactly as in light mode.** `AmountCell`'s own
  `mode="credit"` treatment (see **Components Used**) is a single, theme-aware token, never a separate
  dark-only red — a transaction's outflow direction reads identically calm in both themes, distinguished
  by the leading glyph, not a wash of alarm color across the whole figure.

Every Storybook story for `BankAccountCard`, `BankTransactionsTable`, and the AI Treasury panel ships the
platform's standard four-way parameter matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this
screen's own Playwright suite captures the same four-way screenshot set at the route level, so a token
regression on Banking specifically is caught in CI rather than in a support ticket.

# Accessibility

Banking targets the platform's WCAG 2.2 AA floor identically in both languages and both themes, with the
following screen-specific applications of the general rules `ACCESSIBILITY.md` already establishes.

- **Landmark and heading structure.** Each region without a visible heading of its own (the Cash Position
  band, the Account rail) still carries a real, `VisuallyHidden` `<h2>` via `aria-labelledby`, so a
  screen-reader user's landmark list enumerates the Cash Position band, the Account rail, the AI Treasury
  panel, and the Transactions table as four distinct regions rather than one undifferentiated page,
  mirroring the exact pattern `ACCESSIBILITY.md`'s own Cash Flow Status example establishes.
- **Live regions, calibrated to avoid noise.** A realtime balance patch (`bank.transaction.cleared`
  moving a `KpiTile`'s figure) and a newly landed `ai_match` confidence dot both announce via
  `aria-live="polite"` — never `"assertive"` — so a fast-moving Reverb push never interrupts whatever a
  screen-reader user is currently reading, per `ACCESSIBILITY.md`'s three-tier live-region model. A fraud
  hold is the one deliberate exception: `bank.fraud_flag.raised` landing on an item the caller is
  authorized to act on announces via the assertive tier (`role="alert"`) precisely because it blocks a
  task already in progress (an approver mid-review of that exact item), matching `ACCESSIBILITY.md`'s own
  reservation of the assertive tier for "blocking errors that stop the current task."
- **RBAC-aware disabled controls explain themselves, and are kept textually distinct from a business-rule
  disablement.** An Approve button disabled because the caller lacks `bank.payment.approve` carries
  `aria-describedby` naming the missing permission and the role that holds it (`ACCESSIBILITY.md`'s
  `transfer-disabled-reason` pattern, reused verbatim); an Approve button disabled because
  `finance_manager_approved_by === currentUser.id` (the two-key same-user rule) carries a different,
  specific `aria-describedby` text — "You already approved this at the first step; a different Finance
  Manager or the CEO must complete the second approval" — never a generic "You can't do this right now"
  that would be technically announced but not actionable, per `ACCESSIBILITY.md → RBAC-aware disabled
  controls must explain themselves`.
- **Every AI-authored control explains itself before it can be acted on.** The `ai_match` confidence dot's
  visual pill is `aria-hidden="true"`; an adjacent real text node (available via the same tooltip/disclosure
  pattern) carries the percentage and reasoning as actual text, never a bare progress-bar width. The fraud
  hold's mandatory acknowledgment `Textarea` is `aria-describedby`-linked to the specific flagged reason(s)
  so the "why" is read before the required override text is ever typed.
- **Data grid semantics on the transactions table.** `BankTransactionsTable` renders through real
  `<table>`/`role="grid"` semantics with `aria-sort` on each sortable header (`DataTable`'s own contract,
  reused unmodified) and `aria-busy` while a filter/sort/page change is in flight — screen-reader users get
  the same sort-state and loading-state information a sighted user reads from the header's arrow glyph and
  a dimmed row set.
- **Keyboard path.** Tab order flows header actions → Cash Position band (each `KpiTile` a single stop
  when it has an `onClick`) → Account rail (each card, then its own `DropdownMenu` trigger) → AI Treasury
  panel (its Dismiss/View-full-report controls) → the transactions table (search, filter trigger, sortable
  headers, then row-by-row actions) — every control reachable and operable without a mouse, including the
  Import Statement dialog's dropzone, which additionally accepts a keyboard-triggered native file picker
  (`<input type="file">`) rather than requiring a literal drag gesture.
- **Focus management.** Opening the Import Statement `Dialog` or the New Transaction `Sheet` traps focus
  inside per the platform's standard Radix dialog/sheet focus-trap behavior (`ACCESSIBILITY.md → Focus
  Management`); closing either returns focus to the control that opened it. Navigating from an account card
  or a table row to its own detail route resets focus to the destination page's own `<h1>` via the
  platform's standard route-change focus-reset, so this screen introduces no bespoke focus-trap logic of
  its own.

# Performance

- **Streamed, not blocking.** Per **Data & State**, every region is its own `Suspense` boundary; the
  slowest widget on the page (typically the AI Treasury panel, if a CFO Agent synthesis is still in
  flight) never delays the Cash Position band's first paint, and a failure in one region never takes down
  another.
- **Cheap by design at the source.** `GET /banking/cash-position` and `GET /banking/liquidity` are served
  from `bank_account_balances_mv`, a materialized aggregate refreshed on every balance-changing
  transaction via a lightweight trigger rather than a full `REFRESH` (`docs/accounting/BANKING.md →
  Performance`) — this is precisely why those two calls can safely carry `staleTime: 0` without becoming
  an expensive full-table aggregation on every page load; the frontend's aggressive freshness policy rides
  on a backend design decision made specifically to make that policy cheap.
- **The transactions table never over-fetches.** `DataTable`'s server-driven pagination/sort/filter model
  means this screen's embedded, recent-only instance and `transactions/page.tsx`'s full-history instance
  never pull more than one page (25 rows default) over the wire at a time, regardless of how many years of
  history an account has accumulated; virtualization (see **Responsive Behavior**) only becomes relevant
  once a single filtered result set itself exceeds ~200 rows.
- **The forecast/liquidity chart is code-split.** The Liquidity tile's sparkline and the AI Treasury
  panel's cited forecast chart load their underlying charting dependency via `next/dynamic({ ssr: false })`
  with a matching skeleton, so a role that never sees the AI Treasury panel (no `reports.read`) or the
  Liquidity tile (no `treasury.read`) never downloads that dependency at all.
- **Statement import never blocks the UI thread on a large file.** Import processing runs as a queued
  Laravel job; the dialog polls a lightweight status endpoint rather than holding a long-lived request
  open, so importing a thousand-line statement never makes the rest of the screen feel frozen.
- **Realtime cost control.** This screen subscribes through the shell's single shared `RealtimeProvider`
  connection (mounted once, not per-region), so opening Banking never opens a second WebSocket connection
  on top of whatever the rest of the shell already holds; on Mobile, the AI Treasury panel's own channel
  binding is additionally gated behind an `IntersectionObserver` so it unsubscribes once scrolled out of
  view, per `RESPONSIVE_DESIGN.md → Realtime subscription scoping`.
- **Bundle and Web Vitals.** The route's shell (excluding the individually `next/dynamic`-split chart) is
  held to the platform's stated first-load JS budget, enforced in CI against `performance-budgets.json`;
  LCP is measured against the Cash Position band's paint (this screen's actual "meaningful content," not
  the shell), and INP is watched specifically on the Approve/Reject buttons and the Import Statement
  dropzone, the two highest-stakes interactive surfaces on this screen, tagged against a company-size-band
  baseline since a holding company with a dozen accounts and years of transactions has legitimately
  different tail latencies than a brand-new company with one empty account.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A company switch fires while a region's fetch is still in flight | `queryClient.clear()` runs before `router.refresh()` per `FRONTEND_ARCHITECTURE.md → Company switching`; the in-flight response is discarded on arrival since its query key no longer exists in the cleared cache, and it can never be written into the new company's `bankingKeys.*` entries. |
| A bare hit to `/banking` (no `page.tsx` at that exact segment, per **Route & Access**) | Resolves to the nearest `not-found` boundary, the ordinary Next.js behavior for a route-group segment that has a `layout.tsx` but no `page.tsx` of its own; every in-product link (sidebar, breadcrumb, any AI-surfaced target) points at `/banking/accounts` directly, so this path is reachable only via a hand-typed or stale-bookmarked URL. |
| A company holds bank accounts in more than one currency | The Cash Position `KpiTile`'s single headline figure is always the sum converted to the company's base currency using each account's stored `exchange_rate`, rendered at the base currency's own decimal precision (3 places for KWD) — it never averages or truncates precision across a mixed set. A `CurrencyTag` in muted emphasis annotates the tile only when more than one currency actually contributes. |
| Fraud Detection raises a hold on a payment whose `ApprovalCard` is already open on screen | The realtime push patches that one card's `status` to `held` in place (not a full list invalidation, to avoid disrupting scroll position or an in-progress read), and the `danger`-toned border/banner appear live without a manual refresh, mirroring `AI_COMMAND_CENTER.md`'s identical rule for its own Approval Center. |
| An approver tries to approve their own initiated payment | The button is disabled with the specific two-key `aria-describedby` explanation from **Accessibility**; the underlying `409 Conflict` (`docs/accounting/BANKING.md → Security`) remains the authoritative backstop if a stale permission/session snapshot let the click through anyway. |
| Two browser tabs; one approves the first key of a transfer while the other is mid-review of the same item | The idle tab's next action against that transfer fails with a conflict response (already advanced past that step) and this screen re-fetches the item fresh rather than trusting stale local state, consistent with `FRONTEND_ARCHITECTURE.md`'s general conflict-handling posture and `AI_COMMAND_CENTER.md`'s identical two-tab Approval Center scenario. |
| A statement import's OCR extraction lands a field below the 95% confidence floor | The import review step highlights exactly that field (never the whole line) for manual correction before the batch finalizes; `status` stays `processing`/advisory rather than `completed` until every below-floor field is resolved, per `docs/accounting/BANKING.md`'s OCR rule. |
| A duplicate-suspected transaction is created anyway, with an explicit override | The override reason is written to `audit_logs` and visible to the Auditor role from that transaction's own detail view; the row itself proceeds into its ordinary lifecycle with no special "duplicate" badge lingering on it afterward — the override is a one-time judgment call, not a persistent flag. |
| A standing order's scheduled execution amount exceeds its originally approved envelope | The materialized execution appears in the transactions table at `pending_approval` (not auto-approved, despite the schedule's own pre-approval) with a caption explaining the envelope was exceeded, per `docs/accounting/BANKING.md`'s standing-order edge case — this screen surfaces it as an ordinary `ApprovalCard`, never a special standing-order-specific UI. |
| An account is frozen mid-session (another user, or an automated compliance action, froze it) while this screen is open | The account rail's realtime patch updates that card's `StatusPill` to `Frozen` in place; any row action on a transaction against that account that requires an outgoing capability (submit, approve, transfer) disappears on the next render, since the server's own `422`/`403` for a frozen account is the authority and the UI simply reflects the account's current `status` field, never a cached assumption of `active`. |
| The Liquidity Ratio breaches its policy floor while a user is looking at this screen | `bank.liquidity_ratio.breached` patches the Liquidity `KpiTile`'s caption and delta color live, and the AI Treasury panel's next render (on its own 10-second/focus-triggered refetch) picks up the corresponding `treasury_liquidity_alert` — the two are two separate, independently-timed signals that can briefly disagree by a few seconds, which is an accepted, disclosed tradeoff rather than a synchronization bug. |
| A role holds `bank.read` but not `treasury.read` (a narrow custom role, not one of the platform's named defaults) | The Liquidity Ratio tile renders the disabled-with-a-reason treatment from **Route & Access** rather than being omitted, since a Finance-adjacent role benefits from knowing the capability exists; the AI Treasury panel, gated on `reports.read` rather than `treasury.read`, may still render independently of that tile's own gate. |
| WebSocket disconnected for an extended period, then reconnects | Every one of this screen's realtime-fed query keys (`bankingKeys.*`, `aiTreasuryKeys.briefing()`) is invalidated once, as a single batch, on reconnect — a missed fraud hold or a missed cleared transaction during the outage must surface the moment connectivity returns, never wait for a manual refresh. |
| A user wants a printable or exported copy of what this screen shows | Not supported as a literal "print this page" action, for the same reason `DASHBOARD.md` and `AI_COMMAND_CENTER.md` both decline it for their own screens: five independently-cached, independently-timestamped regions with live AI provenance styling are not a defensible single-instant record. A user who needs a shareable, printable artifact is pointed at the Cash Position, Bank Reconciliation, or Liquidity Dashboard reports (`docs/accounting/BANKING.md → Reports`), each of which carries its own dedicated export/print path through the platform's Reports module. |
| A very long bilingual bank/branch name overflows a `BankAccountCard`'s fixed width | The name truncates with `truncate` and exposes the full string via `title`/`aria-label`, matching `RESPONSIVE_DESIGN.md`'s long-name precedent; the card's balance figures never shrink or wrap to accommodate a long name, since the numbers are this card's primary content. |

# End of Document
