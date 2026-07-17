# Bank Reconciliation — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: BANK_RECONCILIATION
---

# Purpose

The Bank Reconciliation screen is the workbench where a human closes the loop the Banking Agent opens the moment a statement lands: it is the one place in QAYD where a Finance Manager, Senior Accountant, or Accountant looks a bank's version of events in the eye against QAYD's own ledger and drives one number — `variance` — to exactly zero before a period is signed closed. Everything else in the Banking module (`docs/frontend/BANKING.md`'s cash position band, account rail, and transaction ledger) is about knowing where the company's cash is right now; this screen is about proving that knowledge is correct. It is the frontend counterpart to `docs/accounting/BANKING.md → Bank Reconciliation` (the tables, matching rules, and approval workflow this screen renders and calls), and it is the primary human-facing surface for the process specified end-to-end in `docs/ai/workflows/BANK_RECONCILIATION.md` and the agent responsible for most of its automation, `docs/ai/agents/BANKING_AGENT.md`. Where this document is silent, `FRONTEND_ARCHITECTURE.md`, `DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `LAYOUT_SYSTEM.md`, `NAVIGATION_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and `ACCESSIBILITY.md` govern; where it appears to contradict one of them, that is a defect to raise, exactly as `DESIGN_LANGUAGE.md`'s own Purpose section states the rule for every screen in this series.

Three properties set this screen apart from the rest of Banking and organize every section below. First, **most of the work is already done by the time a human opens it.** Per the workflow's own SLA (`docs/ai/workflows/BANK_RECONCILIATION.md → SLAs & Timing`, p95 under 30 seconds for accounts under 10,000 unmatched lines), the Banking Agent has ingested the statement, scored every candidate pair, auto-committed the ones clearing a deterministic threshold, and auto-posted the handful of known-pattern fees and interest lines — routinely 90%+ of a statement's lines by a company's second full month. This screen's job is not "match everything"; it is to show what the agent already matched with enough provenance that a human trusts it at a glance, and to concentrate that human's attention on the genuine exceptions. Second, **the screen has a single, unmissable invariant**, structurally identical in spirit to the one `docs/frontend/TRIAL_BALANCE.md` organizes itself around: `variance` — the gap between the book balance and the bank's own adjusted closing balance — must reach exactly zero before a Finance Manager can close the period, and the entire visual hierarchy exists to keep that number in view while a human works through dozens or hundreds of lines underneath it. Third, **every reversible action stays reversible until the period is actually closed.** An auto-committed match is not, in this screen's vocabulary, a "done" fact the way a posted journal entry is — it stays undoable with one click for as long as `bank_reconciliations.status` is anything other than `closed`. This is a deliberate, screen-specific refinement of `DESIGN_LANGUAGE.md`'s general AI-provenance rule ("the dot disappears once a human approves or the system posts"): here, the system's own auto-commit is not the boundary that retires provenance, because a closed accounting period — not a matched line — is the only genuinely final state this domain has (see `# AI Integration`).

The audience is the same Banking/Accounting/Treasury/Audit set `docs/frontend/BANKING.md → Route & Access` already establishes, narrowed by the one permission this screen actually gates on: `bank.reconcile`, which is stricter than the module's baseline `bank.read`. A Sales Employee or Warehouse Employee never sees this screen because they never see Banking at all; a Read Only user sees Banking's accounts and transactions but not this screen, because `bank.reconcile` is not part of that role's grant (`docs/accounting/BANKING.md → Permissions`, role table); an Auditor or External Auditor does open this screen, but strictly to review — every mutating control described below is omitted for that role, per the exact "Yes (read-only view)" grant their row already carries in the same table. Nothing on this screen computes a match score, decides an auto-commit threshold, or evaluates whether a variance is "close enough" to ignore — every one of those is a Laravel-validated fact this screen only renders and lets a permitted human act on, per the platform-wide rule (`FRONTEND_ARCHITECTURE.md`, Principle 1) that the frontend never contains business or financial logic.

# Route & Access

## App Router path

```
app/(app)/banking/
├── layout.tsx                        # Sub-nav: Accounts | Transactions | Transfers (bank.read gate) — unchanged by this document
├── accounts/…                        # docs/frontend/BANKING.md
├── transactions/…                    # docs/frontend/BANKING.md
├── transfers/…                       # docs/frontend/BANKING.md
└── reconciliation/
    └── [bankAccountId]/
        └── page.tsx                  # ★ THIS DOCUMENT — the Bank Reconciliation workbench
```

This screen is deliberately **not** a fourth tab in `banking/layout.tsx`'s sub-nav. It is opened from a `BankAccountCard`'s row action ("Reconcile") on the Accounts screen and from a single account's own register page — "opened from an account, never a bare index," matching the rule `docs/frontend/NAVIGATION_SYSTEM.md → Sub-navigation per module` already states for this exact screen, even though the literal path token that document and `docs/frontend/BANKING.md`'s own route tree sketch (`/banking/reconciliations/[id]`, keyed by a specific `bank_reconciliations` row id) predates this screen having its own specification. That sketch should be read as resolved to the route above, the same kind of supersession the platform already exercises elsewhere (`DESIGN_LANGUAGE.md` explicitly supersedes `docs/foundation/DESIGN_SYSTEM.md`'s earlier draft wherever the two disagree). Keying the route by `bankAccountId` rather than a specific reconciliation-period id is a deliberate choice, not an oversight: a Finance Manager thinks in terms of "reconcile NBK Operating," not "open reconciliation #4103," and the screen's own job — per Purpose — is to resolve *the account's current open period* the instant it loads, exactly the way `docs/frontend/TRIAL_BALANCE.md` resolves "the current snapshot for this scope" rather than requiring a snapshot id in its own path.

A specific `bank_reconciliations` row — a closed prior period opened from History, or a not-yet-current period named explicitly — is addressed with query parameters on this same route, never a second dynamic segment, mirroring `TRIAL_BALANCE.md`'s identical `?snapshot_id=` precedent:

| Query parameter | Effect |
|---|---|
| *(none)* | Resolves and renders the account's current `bank_reconciliations` row — `status IN ('in_progress','discrepancy','balanced','reopened')`, most recent `period_end` |
| `?reconciliation_id=4103` | Opens that specific row directly — rendered fully read-only whenever its `status = 'closed'` |
| `?period_start=&period_end=` | Resolves a specific historical period by date range, for the rare case a caller has the range but not yet the id (e.g. a deep link from a `bank.reconciliation.discrepancy_detected` notification) |
| `?tab=match\|exceptions\|history` | Selects the page-local tab (`# Layout & Regions`) — deep-linkable so an escalation notification can land a Finance Manager directly on the Exceptions tab rather than the default Match tab |

| Property | Value |
|---|---|
| Route group / shell | `(app)`, nested under `app/(app)/banking/layout.tsx` |
| Nav entry | None of its own — reached via `BankAccountCard`'s "Reconcile" action and the account register's action menu, both gated `bank.reconcile` |
| Breadcrumb | `Banking / Reconciliation / {bank account name}` |
| Rendering mode | `export const dynamic = "force-dynamic"` — tenant- and account-scoped, never statically cached |
| Container | `variant="full-bleed"`, the same 1600px `3xl` ceiling `TRIAL_BALANCE.md` uses, for the same reason: a two-pane match board with a center tray genuinely needs the width |

## Permission gate

Every mutating control on the page checks a permission from `docs/accounting/BANKING.md → Permissions`, and none is looser than the endpoint it calls:

| Control | Permission | Rendered when absent |
|---|---|---|
| Open the screen at all | `bank.reconcile` | The "Reconcile" entry point is omitted from the account card and register; a direct hit to the URL renders the shell's `403` boundary. This is deliberately stricter than `bank.read` — it matches the workbench's own primary GET endpoint (`docs/accounting/BANKING.md → API`: `GET /banking/bank-accounts/{id}/reconciliation/unmatched` is gated `bank.reconcile`, not `bank.read`) |
| Accept a suggested match / undo a committed one | `bank.reconcile` | Row's Accept/Undo control omitted, not disabled — the same permission as viewing, so in practice this only differentiates the Auditor role's read-only grant from everyone else's |
| Manual match / split | `bank.reconcile` | Selection checkboxes still render (so a read-only reviewer can inspect candidates), but the Match Tray's commit action is omitted |
| Mark a line "no matching transaction exists" / Ignore | `bank.reconcile` | Row action omitted |
| Import statement (scoped to this account) | `bank.reconcile` | Button omitted — identical gate to `docs/frontend/BANKING.md`'s own "Import statement" entry point |
| Propose / accept a reconciliation adjustment | `bank.reconcile` | "Propose adjustment" omitted from the Difference Bar's overflow menu |
| Approve the resulting adjustment journal entry | `accounting.journal.approve` (Senior Accountant tier), plus a Finance-Manager-held step specifically for a suspense entry | `ApprovalCard` renders read-only. This is the standard Journal Entries approval chain, not a Banking-specific key — `docs/accounting/BANKING.md → Bank Reconciliation, Adjustments` is explicit that an adjustment "routes through the same Approval Workflow as any other financial correction" |
| Close the period | `bank.reconcile.close` | Close button visible, `disabled`, tooltip "Requires `bank.reconcile.close`" |
| Reopen a closed period | `bank.reconcile.reopen` | Reopen action omitted from the closed-period banner |
| History panel | `bank.read` | Trigger omitted — narrower than the rest of the screen, since a period's mere existence and status is ordinary account-level read information, not reconciliation action |

## Roles

Reproduced from `docs/accounting/BANKING.md → Permissions`'s role assignment table and translated onto this screen's concrete controls:

| Role | What renders |
|---|---|
| Owner, CEO, CFO | Full screen: both panes interactive, Propose adjustment, Close, and Reopen all available |
| Finance Manager | Full screen; the only role besides Owner/CEO/CFO that can Close or Reopen a period |
| Treasury | Full matching workbench (accept, undo, manual match, split, ignore, propose); cannot Close or Reopen — Treasury-initiated transfers can appear as ordinary match candidates like anyone else's |
| Senior Accountant | Full matching workbench; cannot Close or Reopen; approves Tier-1 adjustment journal entries in the standard chain |
| Accountant | Matching workbench only, per the backend role table's "Yes (matching only)" note — can accept, manually match, split, and ignore, but a backfill transaction or adjustment they originate reaches `draft` and needs a Senior Accountant or above to move it forward, exactly as `docs/frontend/BANKING.md → Roles` already states for this role across the module generally |
| Auditor / External Auditor | Opens the workbench read-only: both panes, the Difference Bar, and every AI reasoning disclosure render in full, but Accept/Undo/manual-match/Split/Ignore/Propose/Close/Reopen are all omitted |
| Read Only | Never reaches this screen — `bank.reconcile` is not part of this role's grant, so the entry point itself is absent |
| AI service account | Never renders this screen as a "user"; its principal type structurally cannot hold `bank.reconcile.close` or `bank.reconcile.reopen` regardless of any grant, and every action it can influence here surfaces as a proposal a human accepts, per `docs/accounting/BANKING.md`'s AI-Agent role row |

# Layout & Regions

This screen does not fit any of `docs/frontend/LAYOUT_SYSTEM.md`'s five Page Templates verbatim — it is closest to the **Detail Page Template** (a single entity, its lifecycle status, and a primary action), but that template's fixed 8/12 Main Column plus 4/12 Summary Rail cannot hold a genuine two-pane comparison at a usable width. Per `LAYOUT_SYSTEM.md`'s own extension rule ("if a new screen doesn't fit, the template is extended — a new named slot — not bypassed"), this document defines the **Matching Workbench** variant: the Detail Page Template's header, status chrome, and tab mechanics are reused unchanged, and its Main Column is replaced, for the Match tab only, with a three-part horizontal region — Statement Lines pane, Match Tray, Transaction Candidates pane — that together consume the full-bleed width. The Summary Rail is not used on the Match tab (the two panes need the width more than a persistent side rail earns its keep here); the information a Summary Rail would normally carry — AI reasoning, approval-chain progress — surfaces inline in the Match Tray and in the Exceptions tab instead.

Desktop (`lg`+) layout, inside the persistent app shell (`Sidebar`/`Topbar`, out of scope per `NAVIGATION_SYSTEM.md`):

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│ Banking / Reconciliation                                            [Density ▾]    │ Page Header
│ NBK Operating · KWD                          [Discrepancy]  [History] [⋯]          │ (PageHeader,
│ Period Jul 16–31, 2026                              [Import statement] [Close]     │  StatusPill)
├────────────────────────────────────────────────────────────────────────────────────┤
│ Difference — must reach 0.000 to close                                             │ Difference Bar
│ Bank (adjusted) 211,540.000    Book 211,582.750    Variance   42.750   ▾ breakdown  │ (sticky,
│                                                                                       │  full-width,
│                                                                                       │  non-dismissable)
├────────────────────────────────────────────────────────────────────────────────────┤
│  Match         Exceptions (3)         History                                      │ Page-local Tabs
├───────────────────────────────────┬──────────────┬─────────────────────────────────┤
│ Statement lines (52)   [Search…]  │  Match Tray  │  System transactions   [Search…]│
│ ──────────────────────────────────│ ──────────── │ ────────────────────────────────│ Match Board
│ ☐ Jul 16  KNET TRANSFER   +615.000│ 1 staged     │ ☐ Jul 16  Receipt #5521 +615.000 │ (two synced
│ ● Jul 16  FT2607201234  −4,250.000│  each side   │ ● Jul 16  BILL-00891  −4,250.000 │  panes + a
│    ✓ auto-matched · 100%          │  [Match]     │    ✓ auto-matched · 100%  [Undo] │  center tray)
│ ○ Jul 17  MONTHLY A/C FEE   −3.500│  [Split]     │  (no candidate — Propose fee)    │
│ …                                  │  [Clear]     │ …                                │
├───────────────────────────────────┴──────────────┴─────────────────────────────────┤
│ 49 of 52 matched · 3 in Exceptions                          [Show hidden (<60%) ▾]  │ Footer summary
└────────────────────────────────────────────────────────────────────────────────────┘
```

Region inventory, Match tab:

| Region | Contents | Streaming boundary |
|---|---|---|
| Page Header | Breadcrumb, account name + `CurrencyTag`, period range, `StatusPill` for `bank_reconciliations.status`, Import/Close actions, History trigger, overflow menu (Reopen, Bank Statement Explanation) | Renders immediately with the page shell |
| Difference Bar | The must-reach-zero indicator — full detail in `# Interactions & Flows` and `# States` | Own `<Suspense>` boundary — `GET /banking/bank-accounts/{id}/reconciliation/unmatched`'s summary fields resolve this without a second request |
| Page-local Tabs | `Tabs`: Match (default) · Exceptions (badge-counted) · History — client-side state synced to `?tab=`, distinct from `banking/layout.tsx`'s module-level sub-nav `Tabs` one level up | Not streamed — part of the page shell once the account resolves |
| Statement Lines pane | One row per unmatched-or-recently-matched `bank_statement_lines` record for the resolved period, newest first, with its own search/filter | Own `<Suspense>` boundary |
| Match Tray | The center column: currently staged selection from both panes, running staged-amount totals, and the Accept/Match/Split/Ignore/Clear actions | Client state only — no independent fetch |
| Transaction Candidates pane | One row per unmatched-or-recently-matched `bank_transactions` candidate, synchronized 1:1 in vertical position with its statement-line counterpart wherever a confident pairing exists, so a human's eye can read a matched pair as one visual row split across the gap | Own `<Suspense>` boundary |
| Footer summary | Live counts (`matched_line_count` / `unmatched_line_count` from `bank_reconciliations`), "Show hidden (below 60%)" toggle | Client state only |

Region inventory, Exceptions tab (a single full-width column, since a card here needs room for AI reasoning, not two synchronized lists):

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│  Match         Exceptions (3)         History                                      │
├────────────────────────────────────────────────────────────────────────────────────┤
│ ┌ Line #771247 · KNET TRANSFER — CUST 4471 · +615.000 · Jul 16 ──────────────────┐ │
│ │ ● AI · Banking Agent — 68% confidence                       [Why this score?]  │ │
│ │ No open transaction, but Receipt #5521 (customer KNET receipt) plausibly        │ │
│ │ explains this line — same amount, same date, no bank_transaction linked yet.    │ │
│ │                                     [Dismiss]  [Match manually ▾]  [Accept]     │ │
│ └──────────────────────────────────────────────────────────────────────────────────┘ │
│ ┌ Line #771290 · POS SETTLEMENT NET · −212.400 · Jul 22 ──────────────────────────┐ │
│ │ No AI candidate — score below 60%, shown because "Show hidden" is on            │ │
│ │                              [Ignore line…]  [Propose adjustment]  [Match ▾]    │ │
│ └──────────────────────────────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────────────────────────┘
```

Each exception card is an `ExceptionCard` (`# Components Used`) — a screen-specific composition of `AIProposalPanel` and `ConfidenceBadge` for the AI-scored path, or a bare `Card` with the same three action affordances when no AI candidate exists at all (score under the "hidden" floor and no document-first candidate). The **History** tab renders as a full-width list, one row per `bank_reconciliations` version for this account (version, period, status, `closed_at`, `matched_line_count`); it does not reuse the `Sheet` treatment `TRIAL_BALANCE.md`'s History panel uses, because here History is a first-class tab a user reaches by clicking, not a drawer opened on top of a live canvas — a closed period is a destination in its own right, not an overlay on the current one.

# Components Used

Every visual element is a primitive from `COMPONENT_LIBRARY.md`, a named finance component already catalogued there, or a screen-specific composition living in `components/banking/reconciliation/*`. Nothing here is hand-rolled outside that library.

| Component | Source | Role on this screen |
|---|---|---|
| `PageHeader` | `components/layout/page-header.tsx` | Breadcrumb, title, `StatusPill`, action slot |
| `StatusPill` | `components/shared/status-pill.tsx` | Reconciliation status, statement-line status, per-match method chip — new domain tables owned by this document, below |
| `CurrencyTag` | `components/shared/currency-tag.tsx` | Account currency beside the title |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every money value in both panes, the Match Tray, and the Difference Bar — `signed="none"` throughout, per `DESIGN_LANGUAGE.md`'s debit/credit rule: a statement line's direction is a fact (`credit`/`debit`), never a red/green judgment |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Every AI-scored match candidate and exception card |
| `AIProposalPanel` | `components/ai/ai-proposal-panel.tsx` | The Exceptions tab's AI-scored cards; the Propose Adjustment dialog's AI-drafted path |
| `ApprovalCard` | `components/shared/approval-card.tsx`, `kind="journal_entry"` | Any adjustment/suspense entry awaiting the standard approval chain, surfaced inline in the Difference Bar's breakdown once drafted |
| `Tabs` | Primitive (`components/ui/tabs.tsx`) | Match / Exceptions / History, page-local |
| `Sheet` | Primitive (Radix side-dialog) | Row-click quick view of a single statement line or transaction's full detail; mobile Filter sheet |
| `Dialog` / `AlertDialog` | Primitive | Split-match editor, Propose Adjustment form, Ignore-with-reason, Close-with-residual-acceptance, Reopen-with-reason, duplicate-suspected override |
| `DropdownMenu` | Primitive | Difference Bar overflow (Propose adjustment, Bank Statement Explanation), per-row "⋯" actions |
| `Tooltip` | Primitive | Permission-denied explanations, "Why this score?" reasoning previews |
| `Skeleton` | Primitive | Per-region loading placeholders |
| `EmptyState` / `ErrorState` | `components/shared/empty-state.tsx`, `error-state.tsx` | See `# States` |
| `Can` | `components/auth/can.tsx` | Every permission-gated affordance in `# Route & Access` |
| `useApiToast` | `hooks/use-api-toast.ts` | Every mutation's success/error surface |
| `StatementImportDropzone` | `components/banking/statement-import-dropzone.tsx` (owned by `docs/frontend/BANKING.md`) | Reused verbatim, scoped to this `bankAccountId`, for the "Import statement" action |
| `AccountPicker` | `components/accounting/account-picker.tsx` | GL account field inside the Propose Adjustment dialog's manual path |

## New — `ReconciliationDifferenceBar`

The must-reach-zero indicator is this screen's equivalent of `TRIAL_BALANCE.md`'s Status Banner, and is deliberately built the same way: a full-width, sticky, non-dismissable band directly under the header, never a toast and never a card the user can collapse away, because per `DESIGN_LANGUAGE.md` Principle 3 ("numbers are the hero") this is the one region on the page that behaves like a fixed system state rather than ordinary content.

```tsx
// components/banking/reconciliation/reconciliation-difference-bar.tsx
'use client';

import { Amount } from '@/components/ui/amount';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleTrigger, CollapsibleContent } from '@/components/ui/collapsible';
import { ChevronDown } from 'lucide-react';
import type { BankReconciliation } from '@/types/banking';

interface ReconciliationDifferenceBarProps {
  reconciliation: BankReconciliation; // bank_opening/closing_balance, book_balance_at_period_end,
                                       // outstanding_deposits/withdrawals, adjusted_bank_balance, variance, status
  currencyCode: string;
}

const TONE: Record<BankReconciliation['status'], 'success' | 'danger' | 'accent' | 'warning' | 'neutral'> = {
  balanced: 'accent',      // system computed zero; not yet human-closed — mirrors the cleared/reconciled tone split
  closed: 'success',       // human-approved, permanent
  discrepancy: 'danger',
  in_progress: 'neutral',
  reopened: 'warning',
};

export function ReconciliationDifferenceBar({ reconciliation: r, currencyCode }: ReconciliationDifferenceBarProps) {
  const isZero = Number(r.variance) === 0;
  return (
    <div className={cn('sticky top-16 z-sticky border-b p-4', toneBg(TONE[r.status]))} role="status" aria-live={isZero ? 'polite' : 'assertive'}>
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <p className="text-text-sm text-ink-9">
          {isZero ? 'Balanced — difference is 0.000' : 'Difference — must reach 0.000 to close'}
        </p>
        <Amount value={r.variance} currency={currencyCode} className="numeral-hero" />
      </div>
      <Collapsible>
        <CollapsibleTrigger className="mt-1 flex items-center gap-1 text-caption text-ink-500">
          Breakdown <ChevronDown className="h-3.5 w-3.5" />
        </CollapsibleTrigger>
        <CollapsibleContent className="mt-2 grid grid-cols-2 gap-2 text-text-sm sm:grid-cols-5">
          <Field label="Bank closing" value={r.bank_closing_balance} currency={currencyCode} />
          <Field label="Outstanding deposits" value={r.outstanding_deposits} currency={currencyCode} />
          <Field label="Outstanding withdrawals" value={r.outstanding_withdrawals} currency={currencyCode} />
          <Field label="Adjusted bank" value={r.adjusted_bank_balance} currency={currencyCode} />
          <Field label="Book balance" value={r.book_balance_at_period_end} currency={currencyCode} />
        </CollapsibleContent>
      </Collapsible>
    </div>
  );
}
```

`role="status" aria-live="polite"` while balanced, escalating to `aria-live="assertive"` the instant `variance` moves away from zero (a match is undone, a new unmatched line lands mid-review) — the same severity-proportional live-region rule `TRIAL_BALANCE.md → Accessibility` already states for its own Status Banner, reused verbatim here since the invariant is structurally the same shape.

## New — `ReconciliationMatchBoard`, `StatementLinePane`, `TransactionCandidatePane`, `MatchTray`

The two panes share one `ColumnDef`-free row renderer (there is no `DataTable` underneath — a 1:1/1:many/many:1 pairing UI is not a sortable list of independent records, it is a staging surface, so this screen intentionally does not force `TanStack Table` onto it). Both panes and the tray read from one staging store:

```tsx
// components/banking/reconciliation/use-match-staging.ts
import { create } from 'zustand';

interface MatchStagingState {
  stagedLineIds: number[];
  stagedTransactionIds: number[];
  toggleLine: (id: number) => void;
  toggleTransaction: (id: number) => void;
  clear: () => void;
}

export const useMatchStaging = create<MatchStagingState>((set) => ({
  stagedLineIds: [],
  stagedTransactionIds: [],
  toggleLine: (id) => set((s) => ({
    stagedLineIds: s.stagedLineIds.includes(id) ? s.stagedLineIds.filter((x) => x !== id) : [...s.stagedLineIds, id],
  })),
  toggleTransaction: (id) => set((s) => ({
    stagedTransactionIds: s.stagedTransactionIds.includes(id) ? s.stagedTransactionIds.filter((x) => x !== id) : [...s.stagedTransactionIds, id],
  })),
  clear: () => set({ stagedLineIds: [], stagedTransactionIds: [] }),
}));
```

`useMatchStaging` is deliberately Zustand, not TanStack Query state — it is pure client UI state (what the user has currently selected across two lists) and holds nothing that came from `/api/v1/`, per `FRONTEND_ARCHITECTURE.md` Principle 8. The `MatchTray` reads both arrays, computes the staged-amount totals for each side entirely client-side for *display* only (never submitted as a value the server trusts — the server recomputes and validates the match itself), and disables "Match" unless at least one id is staged on each side; "Split" additionally requires the two sides' summed amounts to actually differ, since a split only makes sense when one side needs dividing across more than one counterpart.

Row anatomy (`StatementLinePane`, one row):

```tsx
// components/banking/reconciliation/statement-line-row.tsx
'use client';

export function StatementLineRow({ line, isStaged, onToggle }: StatementLineRowProps) {
  const canAct = usePermission('bank.reconcile');
  return (
    <div
      role="row"
      aria-selected={isStaged}
      tabIndex={0}
      className={cn('flex items-center gap-3 border-b border-ink-6 px-3 py-2', isStaged && 'bg-accent-subtle')}
      onKeyDown={(e) => { if (canAct && (e.key === ' ' || e.key === 'Enter')) onToggle(line.id); }}
    >
      {canAct && <Checkbox checked={isStaged} onCheckedChange={() => onToggle(line.id)} aria-label={`Stage ${line.raw_description}`} />}
      {line.status === 'matched' && <span className="size-1.5 rounded-full bg-accent" aria-hidden />}
      <div className="min-w-0 flex-1">
        <p className="truncate text-text-sm text-ink-12">{line.raw_description}</p>
        <p className="text-caption text-ink-500">
          <FormattedDate value={line.value_date} /> · {line.counterparty_name ?? '—'}
        </p>
      </div>
      <AmountCell amount={line.amount} currencyCode={line.currency_code} mode={line.direction} />
      {line.status === 'matched' && line.match_confidence != null && (
        <ConfidenceBadge confidence={normalizeConfidence(line.match_confidence, 'percentage')} size="sm" showLabel={false} />
      )}
    </div>
  );
}
```

The `role="row"`/`aria-selected`/roving-`tabIndex` treatment (not a plain `<div>` list, not an ARIA `grid` cell — a `listbox`-style multi-select row) is deliberate and detailed further in `# Accessibility`: this is a genuinely interactive selection surface, distinct from a read-only display table like `TRIAL_BALANCE.md`'s canvas, and distinct too from `DataTable`'s row-click-opens-detail pattern, because a click here toggles staging rather than navigating anywhere.

# Data & State

## Endpoints consumed

All under `/api/v1/banking/`, Bearer + `X-Company-Id`, standard envelope, per `docs/accounting/BANKING.md → API`:

| Method | Path | Permission | Used for |
|---|---|---|---|
| GET | `/banking/bank-accounts/{id}` | `bank.read` | Page Header — account name, currency, `gl_account_id` |
| GET | `/banking/bank-accounts/{id}/reconciliations` | `bank.read` | Resolves the current period on load; populates the History tab |
| GET | `/banking/bank-accounts/{id}/reconciliation/unmatched` | `bank.reconcile` | The Match tab's primary payload — unmatched statement lines, unmatched transactions, and every AI candidate/score pair between them |
| POST | `/banking/statement-imports` | `bank.reconcile` | "Import statement," scoped to this `bankAccountId` |
| GET | `/banking/statement-imports/{id}` | `bank.reconcile` | Import progress, parsed line count, OCR confidence summary (PDF path) |
| POST | `/banking/reconciliation-matches` | `bank.reconcile` | Accept, manual match, and split all resolve to this one endpoint with a different `match_method` |
| DELETE | `/banking/reconciliation-matches/{id}` | `bank.reconcile` | Undo — only while the period is not `closed` |
| PATCH | `/banking/bank-statement-lines/{id}` | `bank.reconcile` | "Ignore this line," `{ status: 'ignored', ignore_reason }` |
| POST | `/banking/bank-transactions` | `bank.reconcile` | The Propose Adjustment dialog's manual path — creates the `fee`/`interest_earned`/`adjustment` draft transaction directly |
| POST | `/banking/reconciliations/{id}/close` | `bank.reconcile.close` | Close action |
| POST | `/banking/reconciliations/{id}/reopen` | `bank.reconcile.reopen` | Reopen action from the closed-period banner |
| GET | `/ai/decisions?subject_type=bank_reconciliations&subject_id=` | `bank.reconcile` | AI candidates, reconciliation-adjustment proposals, and duplicate flags for the resolved period — the source for every `ConfidenceBadge`/`AIProposalPanel` on this screen |
| POST | `/accounting/journal-entries/{id}/approve` | `accounting.journal.approve` | The inline `ApprovalCard` for a drafted adjustment/suspense entry |

## Query keys

```ts
// lib/api/query-keys.ts (extends the factory pattern from FRONTEND_ARCHITECTURE.md)
export const bankReconciliationKeys = {
  all: ["banking", "reconciliation"] as const,
  current: (bankAccountId: number) => [...bankReconciliationKeys.all, "current", bankAccountId] as const,
  detail: (reconciliationId: number) => [...bankReconciliationKeys.all, "detail", reconciliationId] as const,
  unmatched: (bankAccountId: number, filters: UnmatchedFilters) =>
    [...bankReconciliationKeys.all, "unmatched", bankAccountId, filters] as const,
  history: (bankAccountId: number) => [...bankReconciliationKeys.all, "history", bankAccountId] as const,
  aiDecisions: (reconciliationId: number) => [...bankReconciliationKeys.detail(reconciliationId), "ai-decisions"] as const,
};
```

Switching accounts (a different `bankAccountId` in the URL) is a full route change, not a filter change, so there is no risk of one account's staged selection or cache leaking into another's — `useMatchStaging` is cleared on unmount and the query keys are already scoped per account.

## Cache tuning

| Data class | `staleTime` | Rationale |
|---|---|---|
| Account header (`GET .../bank-accounts/{id}`) | 30 seconds | Rarely changes mid-session; consistent with `docs/frontend/BANKING.md`'s own "live/derived figures" tier for balances |
| Unmatched workbench payload | `0`, plus realtime invalidation | Correctness matters more than avoiding a refetch — a colleague's accepted match or a fresh `bank.synced` sweep must be reflected the moment it happens, not on a timer |
| `closed` reconciliation detail/History rows | `Infinity` | Immutable once closed, identical reasoning to `TRIAL_BALANCE.md`'s `approved`/`archived` tiers |
| AI decisions feed | 10 seconds, `refetchOnWindowFocus: true` | Matches `FRONTEND_ARCHITECTURE.md`'s stated tier for AI feeds generally |

## Realtime

One private channel, per the platform's `private-company.{id}.<feature>[.{sub_id}]` convention:

| Channel | Events | Effect |
|---|---|---|
| `private-company.{id}.banking.reconciliation.{bank_account_id}` | `bank.statement.imported`, `bank.synced`, `bank.reconciled`, `bank.reconciliation.closed`, `bank.fraud_flag.raised`, `bank.duplicate_suspected` | Invalidates `unmatched`/`detail`/`aiDecisions` for the open reconciliation; `bank.synced`'s payload (`imported_count`/`auto_matched_count`/`unmatched_count`) additionally drives a toast ("47 lines synced — 44 auto-matched") rather than a silent refetch, since a matching sweep completing while the screen is open is exactly the kind of change a user benefits from being told about |
| `private-company.{id}.notifications.{user_id}` | `bank.reconciliation.discrepancy_detected` (Confidence & Escalation Rules' T+2 proactive notice), `journal.posted` for this reconciliation's adjustment | Topbar bell; a notification whose `reconciliation_id` matches the currently open screen additionally triggers a targeted `invalidateQueries` rather than waiting for the next poll |

`bank.fraud_flag.raised` and `bank.duplicate_suspected` are patched into the cache directly (`setQueryData`, not `invalidateQueries`) the instant they arrive, because both need to render as an immediate blocking banner/dialog (`# States`, `# Edge Cases`) rather than waiting on a full refetch round trip — the one deliberate exception `FRONTEND_ARCHITECTURE.md → Realtime` reserves for "high-frequency, low-risk" events extends here to "high-stakes, must-not-be-missed" events on the same reasoning: even a patched value is still corrected by the next ordinary invalidation, so a missed or out-of-order frame can never leave the UI permanently wrong.

## AI agents feeding this screen

Per `docs/accounting/BANKING.md → AI Responsibilities` and `docs/ai/workflows/BANK_RECONCILIATION.md → Participating Agents`: **Banking Agent** (primary orchestrator — ingestion, the matching sweep, auto-commit, known-pattern fee/interest auto-create, duplicate flags, document-first backfill proposals, reconciliation-adjustment proposals); **Treasury Manager** (contributes an AI-similarity sub-signal into the Banking Agent's score, capped, never alone crossing the auto-commit threshold — never rendered as a separate actor on this screen, only cited inside a match's `reasoning_factors`); **Fraud Detection** (the cross-transaction screen that can open a blocking hold banner against a line surfaced while matching); **General Accountant** (drafts every adjustment/suspense journal entry this screen only links to, never edits inline — see `docs/ai/agents/ACCOUNTANT_AGENT.md`); **Document AI / OCR Agent** (the PDF statement-import path's per-field confidence). None of the five writes to the database directly; every output this screen renders is an `ai_decisions` row or a `journal_entries` draft, written through the Laravel API's own validation exactly as a human's request would be.

## Mutations — optimistic vs. pessimistic

Per `FRONTEND_ARCHITECTURE.md` Principle 10: a mutation that changes the ledger's authoritative state is pessimistic, regardless of how reversible it later turns out to be. Every commit on this screen — Accept, Undo, manual match, Split, Ignore, Close, Reopen — moves a `bank_transactions` row into or out of `reconciled`, or a `bank_reconciliations` row's `status`, so none of them uses `onMutate`:

```ts
// hooks/banking/use-reconciliation-matches.ts
export function useCommitMatch(bankAccountId: number) {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey('commit-match');
  return useMutation({
    mutationFn: (body: { statement_line_ids: number[]; bank_transaction_ids: number[]; match_method: 'manual' | 'split' }) =>
      api.post('/banking/reconciliation-matches', body, idempotencyKey),
    // No onMutate — committing a match is a ledger-state change; the UI shows "matched" only after the server's 2xx.
    onSuccess: () => {
      useMatchStaging.getState().clear();
      toast.success(t('banking.reconciliation.matched'));
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: bankReconciliationKeys.unmatched(bankAccountId, {}) });
      qc.invalidateQueries({ queryKey: bankReconciliationKeys.current(bankAccountId) }); // variance changed
    },
  });
}

export function useUndoMatch(bankAccountId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (matchId: number) => api.delete(`/banking/reconciliation-matches/${matchId}`),
    onError: (err) => {
      if (isApiError(err) && err.status === 409) {
        toast.error(t('banking.reconciliation.alreadyClosed')); // period was closed between page-load and click
      }
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: bankReconciliationKeys.unmatched(bankAccountId, {}) });
      qc.invalidateQueries({ queryKey: bankReconciliationKeys.current(bankAccountId) });
    },
  });
}
```

The single exception is the client-only "Show hidden (below 60%)" toggle and the Match Tray's staged-selection state — both are pure UI preference with no server round trip at all, so the optimistic/pessimistic distinction does not apply to them; they simply are not mutations.

## Form schemas

```ts
// lib/schemas/bank-reconciliation.ts
export const commitMatchSchema = z.object({
  statement_line_ids: z.array(z.number().int().positive()).min(1),
  bank_transaction_ids: z.array(z.number().int().positive()).min(1),
  match_method: z.enum(["manual", "split"]),
});

export const ignoreLineSchema = z.object({
  ignore_reason: z.string().min(1, "validation.bankReconciliation.reasonRequired"),
});

export const proposeAdjustmentSchema = z.object({
  transaction_type: z.enum(["fee", "interest_earned", "profit_distribution", "adjustment"]),
  amount: z.string().regex(/^\d+(\.\d{1,4})?$/),
  account_code: z.string().min(1),
  adjustment_reason: z.string().min(1, "validation.bankReconciliation.reasonRequired"),
});

export const closeReconciliationSchema = z.object({
  // Required only when status is 'discrepancy' at the moment of closing — the "explicitly accepted" path
  // docs/accounting/BANKING.md → API describes for POST /reconciliations/{id}/close.
  acknowledged_residual_reason: z.string().min(1).optional(),
});

export const reopenReconciliationSchema = z.object({
  reopen_reason: z.string().min(1, "validation.bankReconciliation.reasonRequired"),
});
```

# Interactions & Flows

The flows below are the UI expression of the six-stage orchestration `docs/ai/workflows/BANK_RECONCILIATION.md → Orchestration` already defines; this screen participates in Stages 3 ("Exception Queue"), 4 ("Adjustment Proposals"), and 6 ("Close"), and observes Stages 1, 2, and 5 as they complete around it. The worked figures below reuse that document's own running example — Al‑Rawda Trading & Logistics W.L.L. (`company_id` 4821), account **NBK Operating** (KWD, `bank_account_id` 12) — for exact cross-document consistency rather than inventing a parallel set of numbers.

**Opening the screen, routine case.** A Senior Accountant clicks "Reconcile" on the NBK Operating card. The route resolves the account's current period (2026‑07‑16 → 2026‑07‑31) and fetches the unmatched workbench payload. Of 52 statement lines, 49 already show a solid accent dot and a quiet `match_method` caption ("Auto‑matched") because the Banking Agent's sweep already committed them before the page ever loaded; three sit in the Statement Lines pane with no counterpart yet highlighted, and the Exceptions tab badge reads "3." The Difference Bar shows `0.000` in `accent` tone (balanced, not yet closed) if Stage 5 already ran clean, or the live, still-computing figure if the sweep is mid-flight (`# States`).

**Reviewing an auto-matched pair.** Clicking either half of an already-matched pair (line #771201, the exact-reference match against `bank_transactions #90344`, the Gulf Office Supplies vendor payment) opens a `Sheet` showing both sides stacked, the `ConfidenceBadge` at 100%, and its full `reasoning_factors` breakdown (exact reference 45, amount+date 30, fuzzy counterparty 15, AI similarity contribution on top) — the same shape `docs/ai/agents/BANKING_AGENT.md → Outputs` defines for a `bank_match_candidate` decision. The Sheet's only action is **Undo**, disabled with a tooltip once the period is `closed`.

**Accepting a suggested, below-threshold match.** Line #771247 (KWD 615.000, "KNET TRANSFER — CUST 4471") has no `bank_transactions` candidate but a plausible `receipts #5521` backfill at 68% confidence — below the 90-point auto-commit bar, so it sits in Exceptions rather than Match. The card's "Accept" button calls `POST /banking/reconciliation-matches` with `match_method: 'ai_suggested_accepted'` and the proposed backfill payload; on success, `bank_transactions` gains a new row (`ai_generated: true`, `source_document_type: 'receipts'`), the statement line moves to `matched`, and the card animates out of the Exceptions list (the row-entrance/exit motion from `DESIGN_LANGUAGE.md → Motion`, never a bounce).

**Manual match.** A user checks one row in the Statement Lines pane and one in the Transaction Candidates pane; the Match Tray's "Match" button enables the instant both sides have at least one staged id, and its own mini-preview shows both amounts side by side so a mismatch is visible before committing, not after a `422`. Submitting calls the same `POST /banking/reconciliation-matches` with `match_method: 'manual'`.

**Split.** Staging one statement line and two transactions whose amounts sum to it (or the reverse) changes the Match Tray's primary action label from "Match" to "Split match" and opens a small allocation editor confirming which staged transaction covers which portion before submitting `match_method: 'split'` — this is the frontend expression of `docs/accounting/BANKING.md → Bank Reconciliation`'s explicit 1:many/many:1 modeling.

**Ignore.** "Ignore this line" (Statement Lines pane row action) opens an `AlertDialog` requiring a typed reason (`ignoreLineSchema`) before calling `PATCH /banking/bank-statement-lines/{id}` with `status: 'ignored'` — reserved for a line a reviewer has determined needs no transaction at all (an informational bank memo, a line already covered elsewhere), distinct from "no matching transaction exists," which creates a backfill rather than simply retiring the line.

**Propose an adjustment.** From the Difference Bar's overflow menu (available once every line in both panes is either matched or ignored, and a residual remains) or from an Exception card lacking any match candidate, "Propose adjustment" opens a `Dialog`. If the Banking Agent already produced a `bank_reconciliation_adjustment_proposal` decision (the KWD 3.500 recurring‑fee case from the workflow's own worked example, or the KWD 42.750 unexplained‑variance case), the dialog renders that proposal through `AIProposalPanel` pre-filled and read-mostly; otherwise it renders the plain `proposeAdjustmentSchema` form with `AccountPicker` for the target GL account. Either path creates a `draft`, `entry_type='ai_generated'`-or-manual journal entry that this screen only links to — it never posts inline (`# AI Integration`).

**Closing the period.** The Close button in the Page Header is enabled once `status = 'balanced'`. Clicking it opens a confirming `AlertDialog` ("Close this reconciliation period? The underlying lines lock from further re-matching."); on a `discrepancy` status, the same button instead opens the "explicitly accepted" variant of that dialog requiring `acknowledged_residual_reason` — the frontend expression of the API's own "Close a `balanced` (or explicitly accepted) period" contract. On success, both panes re-render fully read-only and a persistent "Closed" banner replaces the Import/Close actions with a single, permission-gated Reopen entry point.

**Reopening.** Available only on a `closed` period, gated `bank.reconcile.reopen` specifically (distinct from the `bank.reconcile.close` key that got it there), and always requires a typed reason, mirroring how a closed `fiscal_periods` row is reopened in Accounting. Reopening flips the status to `reopened`, unlocks both panes, and the Difference Bar switches to its `warning` tone until the next matching pass or human action changes it again.

# AI Integration

This screen renders AI provenance in three distinct shapes, deliberately not one uniform treatment, because the three underlying actions have different stakes and different reversibility — collapsing them into a single "AI card" pattern would either over-warn on a routine auto-match or under-warn on a suspense adjustment.

**1. Match candidates — the accent dot, `ConfidenceBadge`, and Accept/Undo.** This is the lightest-weight treatment on the platform, matching `DESIGN_LANGUAGE.md → AI provenance in a table row` almost exactly: a committed match carries a small `accent` dot in its row's leading position and a quiet `match_method` caption, with the full `ConfidenceBadge` and its `reasoning_factors` breakdown available on click rather than rendered permanently in the row (which would compete with the amount for attention at density). This screen's one specific refinement of that general rule — stated in `# Purpose` and worth restating precisely here — is *when* the dot disappears: not on commit (the general rule's default), but on **period close**. A statement line auto-matched at 9am and undone at 2pm the same day is not a defect this screen needs to explain away; it is the entire reason the undo control exists. `ConfidenceBadge` itself is never rendered as a bare number — every instance on this screen carries its `reasoning` prop, so "Why this score?" is always one hover or focus away, per `COMPONENT_LIBRARY.md`'s "no card shows a bare number the AI computed without showing its work" rule. Below‑60 candidates are hidden by default (`docs/accounting/BANKING.md → AI Responsibilities`: "a barely‑there guess is not surfaced as if it were a real candidate") behind the Match tab's own "Show hidden" toggle — a client‑side filter over already‑fetched data, not a second request, so toggling it is instant.

**2. Document-first backfills and reconciliation adjustments — the full `AIProposalPanel`.** Creating a new financial record — a backfilled `bank_transactions` row from a `receipts`/`vendor_payments` document, or a fee/interest/FX/suspense adjustment — is never auto-committed regardless of confidence, per `docs/ai/agents/BANKING_AGENT.md → Autonomy Level`. Both render through the full `AIProposalPanel` (agent avatar, `recommendedAction`, `ConfidenceBadge`, expandable alternatives, `reasoning`) with `autonomyLevel="suggest_only"` — which means, concretely, that the "Do it" one-click execute button this component supports for other AI Command Center surfaces **never renders on this screen**, because `autonomyLevel` for every decision type Bank Reconciliation produces is `suggest_only` or `requires_approval`, never `auto`, per the same document's own autonomy table. Only "Accept" (which, for a match, calls the reconciliation-matches endpoint directly — a lighter action than the Approval Center's chain) and "Dismiss" ever appear here; "Send for approval" is used specifically for the adjustment path, where accepting the proposal creates a `draft` journal entry that must still clear the standard Journal Entries approval chain (`ApprovalCard`, `kind="journal_entry"`) before it posts — reusing `docs/frontend/TRIAL_BALANCE.md`'s own precedent verbatim: "the Trial Balance screen never lets a user post it inline; it only ever navigates to the Journal Entries detail page." The honestly low confidence on a suspense proposal (41.00 in the workflow's own worked example, Part B) is never hidden or rounded up — a low number here is the AI's own guardrail against false precision doing its job, not a defect to visually soften.

**3. Fraud holds and duplicate flags — blocking, not dismissible-by-accident.** A `bank.fraud_flag.raised` event against a line surfaced while matching renders as a persistent banner across the top of both panes (not a toast, not a corner badge) naming the `risk_score` and reason list; per `docs/accounting/BANKING.md → AI Responsibilities`, a `hold_recommended` flag "cannot be dismissed silently — the approver must explicitly acknowledge the flag with a typed reason." A `bank.duplicate_suspected` signal blocks the specific action that triggered it (typically "create backfill" or "Import statement" landing a near-identical batch) with an `AlertDialog` naming the suspected duplicate row; at score ≥ 95 the override additionally requires a typed reason logged to `audit_logs`, per the Confidence & Escalation Rules' explicit "not just a confirmation click" language. Neither of these two surfaces uses `ConfidenceBadge`'s calm 0–1 banding — both use their own `risk_score`/`duplicate_likelihood` scales exactly as `docs/ai/workflows/BANK_RECONCILIATION.md → Confidence & Escalation Rules` keeps them distinct from the match-scoring scale, and neither is styled as an "insight to consider later"; both are styled as a control demanding a decision now.

**Reading a period's own AI narrative.** The overflow menu's "Bank Statement Explanation" action calls the read-only synthesis capability `docs/accounting/BANKING.md → AI Responsibilities` names explicitly ("this statement shows 42 lines; 39 auto-matched; the 3 exceptions are…") and renders it as plain prose in a `Sheet`, with every cited line/transaction id a live link into the corresponding row — never an unlinked claim, matching the platform-wide rule that an AI narrative always points at the specific rows it rests on.

**Unavailable AI.** If `company_ai_agent_settings.is_enabled = false` for the Banking Agent, the workbench does not fail or hide — it degrades to a fully manual matching surface: every row renders with no dot and no `ConfidenceBadge`, the Exceptions tab shows every unmatched line rather than only the ones the agent flagged, and a single calm `StatusPill` ("AI matching disabled") sits beside the Page Header's status pill, matching `docs/ai/workflows/BANK_RECONCILIATION.md → Trigger & Preconditions`'s own stated degradation ("Stage 2 degrades to a fully manual workbench — zero auto-commit — until a Finance Manager re-enables it"). Every human-driven action remains fully available; AI is additive on this screen exactly as it is everywhere else in QAYD.

# States

New `StatusPill` domain tables this document owns (mirroring the pattern `docs/frontend/BANKING.md` established for `bank_account`/`bank_transaction`/`bank_transfer`):

```tsx
// components/shared/status-pill.tsx — additions owned by this document
const BANK_RECONCILIATION_STATUS: Record<string, { label: string; tone: Tone }> = {
  in_progress:  { label: 'In progress',  tone: 'neutral' },
  discrepancy:  { label: 'Discrepancy',  tone: 'danger'  },
  balanced:     { label: 'Balanced',     tone: 'accent'  }, // system-computed, not yet human-closed
  closed:       { label: 'Closed',       tone: 'success' }, // human-approved, permanent
  reopened:     { label: 'Reopened',     tone: 'warning' },
};

const BANK_STATEMENT_LINE_STATUS: Record<string, { label: string; tone: Tone }> = {
  unmatched: { label: 'Unmatched', tone: 'neutral' },
  matched:   { label: 'Matched',   tone: 'success' },
  ignored:   { label: 'Ignored',   tone: 'neutral' },
};

const MATCH_METHOD_LABEL: Record<string, string> = {
  auto_rule: 'Auto-matched',
  ai_suggested_accepted: 'AI-suggested, accepted',
  manual: 'Matched manually',
  split: 'Split match',
};

Object.assign(STATUS_TABLES, {
  bank_reconciliation: BANK_RECONCILIATION_STATUS,
  bank_statement_line: BANK_STATEMENT_LINE_STATUS,
});
```

`balanced` deliberately takes `accent`, not `success` — the same "cleared vs. reconciled" distinction `docs/frontend/BANKING.md` draws for `bank_transactions.status`, applied one level up: a variance of exactly zero is a fact the matching engine computed, not yet a fact a Finance Manager has ratified by closing the period, and only the latter earns the platform's success tone.

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint) | Initial navigation, no cached data | `Skeleton` shaped like the header, Difference Bar, and both panes — shimmer sweep per `DESIGN_LANGUAGE.md → Motion`, never a spinner |
| Import in progress | A statement upload/sync is running (Stage 1) | Header's status area shows "Importing…" with the parsed-line count ticking up if the import endpoint streams progress; panes show their prior state, not a blanking skeleton, so a user can keep working the existing exception queue while a new batch lands |
| Matching sweep running | Stage 2 mid-flight (rare given the sub-30s SLA, but real for a >10,000-line account) | A slim indeterminate bar under the Difference Bar, "Matching…"; no control is disabled, since this is a background computation, not a blocking one |
| Balanced, open | `status = 'balanced'` | Difference Bar `accent` tone, "Balanced — difference is 0.000"; Close enabled |
| Discrepancy | `status = 'discrepancy'` | Difference Bar `danger` tone, exact variance shown; Close disabled unless the caller explicitly accepts the residual (`# Interactions & Flows`) |
| Closed | `status = 'closed'` | Both panes fully read-only (every checkbox and row action omitted, not merely disabled); a persistent, non-dismissable banner names who closed it and when; Difference Bar `success` tone |
| Reopened | `status = 'reopened'` | Difference Bar `warning` tone with a banner naming `reopened_by`/`reopen_reason`; both panes unlock |
| Fully matched, nothing in Exceptions | `unmatched_line_count = 0` on the Match tab | A calm, small checkmark confirmation ("49 of 49 matched") per `DESIGN_LANGUAGE.md`'s "quiet certainty, not fireworks" motion rule — never a celebratory animation, exactly the same restraint `TRIAL_BALANCE.md` applies to a balanced ledger |
| Empty — no statement ever imported | Brand-new account, zero `bank_statement_lines` rows | `EmptyState`: "No statement imported yet for this account," primary action "Import statement" gated on `bank.reconcile`; a lesser copy variant for a caller who lacks it |
| Filtered empty | A pane's search/filter legitimately matches nothing | Lighter `EmptyState` variant, "No lines match your search," never conflated with the no-statement-at-all case |
| Fraud hold | `bank.fraud_flag.raised` against a visible line | Persistent `danger`-toned banner across both panes, typed-reason acknowledgment required before the flagged line's match/accept controls re-enable |
| Duplicate suspected | `bank.duplicate_suspected` | Blocking `AlertDialog` on the specific action that triggered it; every other control on the page remains usable |
| Error | `403`/`404`/`5xx`/network failure | `ErrorState` with retry; a `403` mid-session (permission revoked while the tab was open) collapses the affected control to its disabled-with-tooltip form rather than crashing the page |

# Responsive Behavior

The two-pane Match Board is the one region on this screen with no counterpart in `docs/frontend/RESPONSIVE_DESIGN.md → Finance Tables On Small Screens`'s five-rule ladder, which was written for single-entity row collections (Trial Balance, General Ledger) — a genuine pairing UI needs its own sixth pattern, documented here as this screen's specific extension of that ladder rather than a deviation from it.

**Mobile (`base`–`sm`).** The Page Header collapses to a back-chevron + account name; the Difference Bar stays pinned exactly as `TRIAL_BALANCE.md`'s Status Banner does ("a report's totals/status must never leave the viewport"), collapsing its breakdown behind a tap rather than showing it inline. The Match tab abandons the side-by-side board entirely and becomes a **single reflowed list**: every statement line and every unmatched transaction interleave into one scrollable column, each rendered as a card carrying its own `role="checkbox"`-style tap target, direction glyph, amount, and (if committed) its accent dot and quiet match-method caption. Staging a line and a transaction from this single list populates the same `useMatchStaging` store as desktop; the Match Tray becomes a bottom `Sheet` that slides up the moment the first id is staged, showing both staged items and the Match/Split/Clear actions without navigating away from the list — the mobile equivalent of `RESPONSIVE_DESIGN.md → Pattern 4`'s "modals become sheets" rule, applied to a tray rather than a dialog. The Exceptions tab already reads as a single column on desktop, so it reflows with no structural change beyond standard card-width and touch-target adjustments.

**Tablet (`md`).** The two-pane board survives, but the Match Tray narrows to icon-only buttons with tooltips rather than labeled buttons, and each pane's own search collapses behind a single toggle, freeing width for the panes themselves — the same "filters collapse into overflow" rule `RESPONSIVE_DESIGN.md → Rule 5` states generally.

**Laptop and up (`lg`+).** The full three-column board renders as specified in `# Layout & Regions`, with both panes independently virtualized once either exceeds roughly 200 rows (`# Performance`).

**Touch targets.** Every row's checkbox and every icon-only row action meets the platform's 44×44px minimum regardless of the pane's own row height, via the same `::before` hit-slop expansion `docs/frontend/LAYOUT_SYSTEM.md → Density Modes` already defines for dense finance tables generally.

# RTL & Localization

Every string ships as an EN/AR pair through `next-intl`, and this screen inherits `docs/frontend/LAYOUT_SYSTEM.md → RTL Layout Mirroring` and `THEMING.md → RTL as a theming dimension` without a single per-component RTL branch, plus a few module-specific applications:

- **The two panes mirror normally.** Unlike Trial Balance's Debit/Credit columns — which stay physically fixed because Debit-before-Credit is a universal, external printed-accounting convention — there is no equivalent external convention ordering "statement lines" before "system transactions." The Statement Lines pane sits at the logical start and the Transaction Candidates pane at the logical end in both directions, so under `dir="rtl"` the Statement Lines pane renders on the right and Transaction Candidates on the left, following the platform's default logical-properties rule rather than the rare, explicitly-justified exception Trial Balance carves out for itself.
- **Amounts and dates stay LTR-embedded** inside every row, the Difference Bar, and every AI reasoning string, via the shared `Bidi`/`LtrInline` wrapper (`unicode-bidi: isolate` + `dir="ltr"`) — a KWD figure or a `bank_reference` value never reorders inside a right-to-left description string.
- **Numeric alignment is physically fixed** (`text-right` in both directions, never `text-end`) on every `AmountCell`, matching the platform-wide rule that a bilingual finance team scans magnitude by decimal alignment.
- **AI reasoning renders in the viewer's session locale**, as translated prose the frontend never re-translates client-side, with the same precise Arabic accounting vocabulary `DESIGN_LANGUAGE.md → Voice & Microcopy` catalogs — تسوية بنكية (bank reconciliation), مطابقة (matching), فرق (variance/difference), تصحيح (adjustment).
- **Directional chrome mirrors; content chrome does not.** The row-detail `Sheet` and Match Tray's mobile bottom sheet slide from the logical edges appropriate to their axis; the `Landmark` module icon, the `Sparkles`/AI mark, and every status/severity icon never mirror, per `ICONOGRAPHY.md`'s directional-icon table. A connecting indicator drawn between a staged statement line and its staged transaction counterpart (a thin line or chevron implying "these pair with each other"), if rendered at all, is treated as directional chrome and mirrors via `rtl-flip`, since it visually points from one side to the other.
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching every other financial figure on the platform.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`/`THEMING.md`; nothing on this screen references a raw hex value.

- **Surfaces.** The page canvas sits on `--color-bg-canvas`; both panes and the Match Tray sit on `--color-bg-surface`; the row-detail `Sheet` and every `Dialog` sit on `--color-bg-surface-raised`. The Difference Bar keeps its `ink-6` hairline border in dark mode even though its light-mode equivalent can lean on shadow alone, for the same "a borderless dark card disappears against the near-black canvas" reason `TRIAL_BALANCE.md → Dark Mode` already states.
- **Difference Bar tones.** `balanced` → `accent` (never a large fill — the color is carried by the figure and the pill, not a background wash); `discrepancy` → `danger`, the one place on this screen a saturated treatment is intentional; `closed` → `success`; `reopened` → `warning`. These are the exact same tokens `docs/frontend/BANKING.md` and `TRIAL_BALANCE.md` already use for their own status treatments — no new hue is introduced.
- **Statement-line direction is never a status color.** A `credit` or `debit` direction renders in plain `ink-12`, exactly like Trial Balance's Debit/Credit columns; only the Difference Bar's variance, the reconciliation `StatusPill`, and fraud/duplicate severity ever carry a semantic tone.
- **AI provenance** — the row-leading dot, `ConfidenceBadge`, and `AIProposalPanel`'s left border — use the single platform accent in both themes, never a second AI-specific hue, per `DESIGN_LANGUAGE.md`'s "the accent is also the AI's signature" rule.
- **Contrast.** Every pairing is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text, borders, focus rings) in both themes; the vertical divider between the two panes and the Match Tray is treated as load-bearing, checked at the 3:1 non-text floor rather than exempted as a plain decorative rule.

# Accessibility

Baseline is WCAG 2.2 AA, per `ACCESSIBILITY.md`, with the platform's stricter internal bar applied to Close and Reopen exactly as it applies to Trial Balance's Approve and Archive.

- **The matching panes are a genuinely interactive selection surface, not a display table.** `TRIAL_BALANCE.md → Accessibility` is explicit that arrow-key grid navigation is reserved for "genuinely editable surfaces like the Journal Entry line editor," contrasted with its own read-only canvas. The Statement Lines and Transaction Candidates panes are exactly that reserved case: each pane is a `role="listbox"`-equivalent list of `role="row"` items with `aria-selected` reflecting staged state, roving `tabIndex` (one row per pane is ever `tabIndex={0}` at a time), arrow-up/down moving focus within a pane, `Tab`/`Shift+Tab` moving between the two panes and the Match Tray in visual order, and `Space` or `Enter` toggling the focused row's staged state — never opening a detail view, which is instead reached via a distinct row action (a small "⋯" or a double-click/`Shift+Enter`) so a single accidental double-press cannot both stage and navigate away.
- **Committing a match is never mouse-only.** With both panes reduced to their staged sets, `Cmd/Ctrl+Enter` from anywhere on the page commits the Match Tray's primary action (Match or Split, whichever it currently reads), mirroring the same shortcut `TRIAL_BALANCE.md` binds to its own Generate/Approve confirmation dialogs.
- **The Difference Bar is a live region proportional to its severity.** `role="status" aria-live="polite"` while `variance` sits at zero or the change is a routine improvement; escalates to `role="alert"` (assertive) the moment a value moves *away* from zero — undoing a match, a fraud hold landing, a new unmatched line arriving via realtime — because that is precisely the blocking-error case the platform's live-region tiers reserve assertive announcements for, per `TRIAL_BALANCE.md`'s identical rule for its own must-balance banner.
- **AI decisions arriving via realtime never yank focus or splice silently.** A `bank.synced` sweep completing while a Senior Accountant is mid-review of the Exceptions tab appends/removes cards with a polite live-region announcement ("3 lines auto-matched — Exceptions now 2") rather than reordering the list out from under a focused row, per the platform-wide realtime-accessibility rule `TRIAL_BALANCE.md → Accessibility` already states for its own Findings Bar.
- **Every disabled control explains itself**, and the distinction between a permission-denied disable and a business-rule disable is preserved exactly as `TRIAL_BALANCE.md` states it: the Close button disabled for lack of `bank.reconcile.close` reads "Requires `bank.reconcile.close`"; the same button disabled because `status = 'discrepancy'` without an accepted residual reads "This period has an unresolved difference — resolve it or explicitly accept the residual before closing." The two are never collapsed into a generic "You can't do this."
- **Debit/credit direction is never color-only.** Every `AmountCell` in both panes carries the platform's standard triple redundancy — a directional cue, tabular figures, and a `VisuallyHidden` text equivalent ("Credit of KWD 615.000") — so a colorblind reviewer verifies a line's direction exactly as confidently as anyone else.
- **Focus management on overlays.** The row-detail `Sheet`'s `onOpenAutoFocus` moves focus to its heading; closing it (Escape, backdrop click, or clicking Undo inside it) returns focus to the row that triggered it, never to the top of the page. The Propose Adjustment `Dialog` and every `AlertDialog` (Ignore, Close-with-residual, Reopen, duplicate override) trap focus and return it to their trigger on close, per the platform's standard overlay contract.
- **Export/omit discipline.** Row actions gated on `bank.reconcile` are omitted from a row's action menu for a caller who lacks it (existence itself would leak nothing sensitive, but the action genuinely does not exist for that role), while Close/Reopen — visible, structural, worth advertising exists — render disabled-with-tooltip instead, matching `ICONOGRAPHY.md`'s stated distinction between the two treatments.

# Performance

- **The unmatched workbench payload is the one request this screen cannot proceed without**, so it is prefetched server-side in `page.tsx` and hydrated into the client cache exactly as `TRIAL_BALANCE.md → Data & State`'s SSR hydration pattern does for its own current-snapshot lookup — the first client-side `useQuery` for the same key resolves instantly rather than re-issuing a request the server already made.
- **Both panes virtualize past roughly 200 rows** via `@tanstack/react-virtual`, using the active density mode's row height as the size estimate exactly as `docs/frontend/DESIGN_LANGUAGE.md → Virtualization & interaction` specifies platform-wide; a first-ever import on an account with years of un-reconciled history can land thousands of statement lines in one batch, and this screen must not choose between "scroll smoothly" and "show every line."
- **Matching-sweep completion is observed, never polled for by this screen.** Per `docs/ai/workflows/BANK_RECONCILIATION.md → SLAs & Timing`'s own p95 of under 30 seconds, the frontend subscribes to the realtime channel rather than polling `GET .../reconciliation/unmatched` on an interval — a poll would either lag the sweep's actual completion or waste requests against an endpoint the backend's own queue-backed matching job is already about to invalidate via a push event.
- **Client-side staging computation is deliberately cheap.** `useMatchStaging`'s totals are a plain array sum over already-fetched rows, recomputed on every toggle — there is no debounce needed because the operation is O(n) over, at most, a handful of staged ids, never the full unmatched set.
- **Deferred overlays.** The Propose Adjustment dialog, the History tab's list, and the row-detail Sheet's full `reasoning_factors` breakdown are dynamically imported (`next/dynamic`), consistent with `TRIAL_BALANCE.md`'s identical treatment of its own Generate dialog and History Sheet — none is needed for first paint.
- **Realtime invalidation is targeted, not global.** A `bank.synced` event invalidates only `unmatched(bankAccountId, …)` and `current(bankAccountId)`, never the whole `bankReconciliationKeys.all` tree, so a sweep on one account never forces a refetch of another account's already-loaded, unrelated reconciliation the user might have open in a second tab.

# Edge Cases

1. **Two users commit conflicting matches on the same statement line at once.** The commit is wrapped server-side in a row-level lock; the losing writer's `POST` returns `409 ALREADY_MATCHED` per `docs/accounting/BANKING.md → Edge Cases`, and the frontend responds by refetching the workbench and showing a toast ("This line was just matched by someone else") rather than a raw error — the exact same pattern `useUndoMatch`'s `onError` handler already applies for the closed-period race.
2. **Undo attempted after the period closed between page-load and click.** The `DELETE` returns `409`; the toast names the reason and the row visually re-locks without a full page reload.
3. **A late-arriving transaction dated inside an already-closed period.** Per `docs/accounting/BANKING.md → Edge Cases`, it can still be created but cannot be matched into the closed `bank_reconciliations` row — it appears as an unmatched line in the *next* open period's workbench, tagged `crosses_closed_period: true`, rendered with a small badge and a tooltip explaining why a transaction dated in July is sitting in August's exception queue.
4. **A POS settlement batch nets several terminal transactions against one statement line with only an estimated fee.** The workbench shows the many:1 split pre-staged by the Banking Agent as a suggestion rather than requiring the user to hunt for and manually select every contributing transaction; any variance between the estimate and the provider's later itemized statement surfaces as a small `adjustment` in a future cycle, per the same source document's stated handling.
5. **A suspense adjustment is proposed (Part B of the workflow's worked example).** Because it escalates to the Finance Manager regardless of amount, the `ApprovalCard` for it renders with a visibly different, non-collapsible urgency treatment even before that role opens the screen — this is the one adjustment type this screen never lets a Senior Accountant alone wave through, structurally, not just by convention.
6. **AI disabled mid-review.** A Finance Manager toggles the Banking Agent off in Settings while a Senior Accountant has the workbench open; the next realtime event (or the next 10-second AI-feed refetch) removes every dot and `ConfidenceBadge` in place, without discarding whatever the Senior Accountant had already staged in `useMatchStaging` — client-only state that setting change has no bearing on.
7. **Company switch mid-match.** Per the platform's company-switch contract, switching the active company clears the entire query cache; if a match is staged but not yet committed, the switcher's own confirmation dialog names that in-progress staging explicitly before proceeding, exactly as `TRIAL_BALANCE.md`'s equivalent Edge Case does for an open Generate dialog.
8. **Session permission revoked mid-session.** A role change strips `bank.reconcile` while the workbench is open; the next mutation attempt's `403` collapses every action to its disabled/omitted form and surfaces a toast explaining why, rather than a broken or endlessly-spinning control — the frontend never assumes its own last-known permission snapshot is still valid, per `FRONTEND_ARCHITECTURE.md` Principle 4.
9. **Multi-currency account, matched pair settles at a different rate than booked.** The matched row shows a small secondary caption with the realized FX delta the Banking Agent annotated; the delta itself is never posted from this screen — only the General Accountant's resulting draft entry, linked exactly as any other adjustment, carries the actual journal lines.
10. **A reopened period's re-entry.** Opening a reconciliation that was just reopened (rather than one freshly `in_progress`) shows the persistent `reopened_by`/`reopen_reason` banner from `# States` even after the first new match is committed — it clears only when the period reaches `closed` again, never merely on the first subsequent action, so a reviewer always knows they are looking at a period with a documented prior reopening.
11. **A zero-line statement import** (a bank period with genuinely no activity — a dormant account's monthly no-op statement). The tie-out check passes trivially (`opening_balance = closing_balance`, zero lines), both panes render their empty state, and the reconciliation closes in one step with `matched_line_count: 0` — never treated as an error or a stalled import.
12. **Direct URL access to a `?reconciliation_id=` a caller's company does not own.** Returns `404`, never `403`, per `FRONTEND_ARCHITECTURE.md`'s cross-tenant enumeration-discipline rule — the not-found page never implies "this exists but you can't see it."

# End of Document
