# Journal Entries — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: JOURNAL_ENTRIES
---

# Purpose

This document specifies the Next.js 15 frontend for QAYD's Journal Entries screen set: the list, the
composer (create/edit), and the detail view over the `journal_entries` / `journal_lines` tables that
`docs/accounting/JOURNAL_ENTRIES.md` defines as the single transactional core of the Accounting Engine.
Every financial fact QAYD will ever show on a General Ledger, a Trial Balance, or a Financial Statement
passes through this screen's data model in exactly one shape — a balanced entry composed of a header and
two or more lines — whether that entry was typed by an Accountant, generated automatically by the Sales
or Payroll modules, or drafted by the FastAPI AI layer. This screen is therefore one of the highest-risk
surfaces in the entire product from a correctness and accessibility standpoint: a Finance Manager
approving a payroll accrual here is making a decision they are personally and professionally accountable
for, and the UI's only job is to make that decision fast, unambiguous, and fully reversible in the audit
trail if it turns out to be wrong.

Three sub-surfaces are covered by this one document, because they share one route family, one data model,
one permission surface, and — per `docs/frontend/README.md` and `COMPONENT_LIBRARY.md` — one set of
composed components:

1. **List** (`/accounting/journal-entries`) — browse, filter by status/period/source, search, sort, bulk
   act, export, and import. The default landing surface for every role holding `accounting.journal.read`.
2. **Composer** (`/accounting/journal-entries/new`, and the edit mode of the detail route) — the
   `JournalEntryForm` + `JournalLineGrid` multi-line debit/credit editor with a live, client-side balance
   check, account/dimension pickers, and attachments. This is where a human — or, upstream of a human
   review, the AI layer — authors a `draft`.
3. **Detail** (`/accounting/journal-entries/[journalEntryId]`) — the full lifecycle view of one entry:
   its lines, its approval chain, its attachments, its history, and — while it is still a `draft` or
   `rejected` — the same composer, in edit mode, in place.

This screen never computes a financial truth of its own. Every balance check, period-lock check, and
account-validity check the client performs is a fast, friendly, purely cosmetic rejection that exists to
avoid a wasted round trip — the `JournalEntryPostingService` on the Laravel API is the only party whose
answer can ever actually post an entry, exactly as `docs/accounting/JOURNAL_ENTRIES.md → Posting Engine`
describes ("the server never trusts the cached header totals"). Nothing in this document introduces a
validation rule, a permission key, an API shape, or a status value that is not already defined in that
backend specification; this document is strictly the rendering, interaction, and state-management contract
for it, consistent with `FRONTEND_ARCHITECTURE.md`'s Principle 1 ("the frontend never contains business or
financial logic").

Two audiences share this one shell, per the platform's "two audiences, one shell" rule
(`docs/frontend/README.md`): an Accountant or Senior Accountant doing high-volume, keyboard-first data
entry (dense `Compact` table density, the ARIA `grid` line editor, `N` to create, arrow-key navigation
inside a line) and a Finance Manager, CFO, or Auditor reviewing and deciding (calmer `Comfortable` density,
`ApprovalCard`-centric review, no hand-holding but no dense-grid keyboard model either). Both are built
from the exact same components — `DataTable`, `JournalEntryForm`, `ApprovalCard`, `StatusPill`,
`AmountCell` — composed differently; there is no separate "reviewer mode" build.

A third author exists on this screen that neither of the two human audiences above accounts for: the AI
layer. An `ai_generated` entry — drafted by the General Accountant agent from an unmatched bank line, or
by the Document AI/OCR agent from a scanned vendor bill — enters this screen's data exactly the way a
human-typed draft does, through the identical `JournalEntryService::create()` code path
(`docs/accounting/JOURNAL_ENTRIES.md → Automatic Entries → AI Generated Entries`), and is visually
distinguished only by the platform-wide AI-provenance affordances (`# AI Integration`, below) — never by a
parallel screen, a parallel table, or a parallel API. Per `FRONTEND_ARCHITECTURE.md`'s Principle 3, this
screen structurally cannot render a one-click "post it" affordance for an AI-drafted entry: `entry_type =
'ai_generated'` always requires at least one human approval level (`docs/accounting/JOURNAL_ENTRIES.md →
Approval Workflow`), so the client-side path to execute one without a human decision simply does not exist
in the component tree, not merely in the disabled state of a button.

# Route & Access

## Route tree

```text
app/(app)/accounting/journal-entries/
├── page.tsx                     # List — Server Component, first-paint fetch
├── loading.tsx                  # List skeleton (streamed while the Server Component fetches)
├── new/
│   └── page.tsx                 # Composer — create mode
└── [journalEntryId]/
    └── page.tsx                 # Detail — view mode, OR Composer in edit mode (see below)
```

`[journalEntryId]` follows the platform's dynamic-segment convention — camelCase, named for the entity,
never the generic `[id]` (`docs/frontend/FRONTEND_ARCHITECTURE.md → Conventions → Naming`) — and its
API-resource mirror is `/api/v1/accounting/journal-entries/{id}`.

**One route, two rendered modes, chosen by server-known status — not four routes.** There is deliberately
no separate `/[journalEntryId]/edit` route. `[journalEntryId]/page.tsx` is a Server Component that fetches
the entry once and renders one of two Client Components based on `entry.status`, reusing exactly the
branch `COMPONENT_LIBRARY.md → Composition Patterns → Pattern 2` already establishes for this screen:

```tsx
// app/(app)/accounting/journal-entries/[journalEntryId]/page.tsx
import { getJournalEntry } from "@/lib/api/accounting";
import { JournalEntryDetail } from "@/components/accounting/journal-entry-detail";
import { JournalEntryForm } from "@/components/accounting/journal-entry-form";
import { notFound } from "next/navigation";

const EDITABLE_STATUSES = new Set(["draft", "rejected"]);

export default async function JournalEntryPage({
  params,
}: {
  params: Promise<{ journalEntryId: string }>;
}) {
  const { journalEntryId } = await params;
  const entry = await getJournalEntry(journalEntryId).catch(() => null);
  if (!entry) notFound();

  return EDITABLE_STATUSES.has(entry.status) ? (
    <JournalEntryForm mode="edit" journalEntryId={entry.id} defaultValues={entry} />
  ) : (
    <JournalEntryDetail initialEntry={entry} />
  );
}
```

`defaultValues` is `JournalEntryForm`'s own documented prop name (`COMPONENT_LIBRARY.md → JournalEntryForm`
— not a screen-local invention), so a `draft` opened for editing pre-fills through the identical prop path
an AI-drafted or duplicated entry uses. This mirrors `docs/accounting/JOURNAL_ENTRIES.md → Locking Rules`
exactly: `draft` and `rejected` are the only mutable statuses, so those are the only statuses that can ever
resolve to a form. There is structurally no URL that implies a posted entry can be edited, because no such
URL exists — a user who somehow lands on this route for a `posted` entry always sees the read-only
`JournalEntryDetail`, never a form with disabled fields (a disabled form inviting an edit attempt is worse
UX than no form at all).

## Access gate

The route sits inside the `(app)` route group, behind `middleware.ts`'s session-cookie check and the
resolved active-company context (`X-Company-Id`), per `FRONTEND_ARCHITECTURE.md`. A user without
`accounting.journal.read` never sees the "Journal Entries" item in the Accounting sidebar section or in the
Command Palette's fuzzy-search index (RBAC filters at the data layer before the list is constructed, per
`NAVIGATION_SYSTEM.md → Permission-Aware Nav`), and a direct hit on the URL renders the shared `403`
boundary (`# States`) rather than a `404` — the route exists, the record might exist, the viewer simply may
not see it. A cross-company hit (a `journalEntryId` that exists but belongs to a different `company_id`)
renders the shared `not-found.tsx` instead, per `FRONTEND_ARCHITECTURE.md`'s "403 vs. 404" rule: the API
itself returns `404`, never `403`, for a cross-tenant record specifically so a caller can never learn that a
journal entry with that id exists somewhere they cannot see.

## Permission surface on this screen

Every permission key below is defined once, authoritatively, in
`docs/accounting/JOURNAL_ENTRIES.md → Permissions`; this table is this screen's map of key → concrete UI
effect, not a redefinition.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `accounting.journal.read` | The route itself; every list row; every detail view | Route renders the shared `403` boundary; nav item hidden |
| `accounting.journal.create` | "New Journal Entry" button, `/new` route, the `N` shortcut, "Save Draft" in the Composer | Button/route removed from the DOM, not disabled |
| `accounting.journal.update` | Edit affordance on a `draft`/`rejected` entry (own drafts only, unless Finance Manager/CFO) | Detail route renders the read-only `JournalEntryDetail` even for a `draft`, with a banner explaining why |
| `accounting.journal.submit` | "Submit for approval" action when the entry requires approval | Composer's primary button hidden; falls through to `accounting.journal.post` check |
| `accounting.journal.approve` | Approve / Reject on `ApprovalCard`, at the viewer's assigned level; Delegate | `ApprovalCard` renders read-only (amount, requester, confidence — no action row) |
| `accounting.journal.post` | Direct "Post" label/behavior on the Composer's primary button and the Detail page's lifecycle action | Same button falls back to "Submit for approval" label/behavior instead of disappearing |
| `accounting.journal.reverse` | "Reverse" action on a `posted` entry's Detail page and row-action menu | Menu item omitted (a sensitive, semantically loaded action is never shown disabled with no explanation — it is simply absent) |
| `accounting.journal.delete` | "Delete" on a `draft`/`rejected` row/detail | Menu item omitted |
| `accounting.journal.archive` | "Archive" / "Unarchive" toggle | Menu item omitted |
| `accounting.journal.export` | "Export" button in the List's Filter Bar | Button omitted |
| `accounting.journal.import` | "Import" entry point in the List's Page Header | Button omitted |
| `accounting.period.reopen` | Read-only reference only — an informational link ("Ask a CFO/Owner to reopen this period") shown when a post attempt fails with a period-lock error | Link text changes to a plain explanation with no CTA |

Default role grants (Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Auditor, Read Only,
External Auditor) are exactly the table in `docs/accounting/JOURNAL_ENTRIES.md → Permissions` — this
document does not restate role-by-role grants to avoid the two tables drifting out of sync; `usePermission()`
(`FRONTEND_ARCHITECTURE.md → Conventions`) is the only source either query. Auditor and External Auditor
hold `accounting.journal.read` and `accounting.journal.export` only, enforced at the Laravel Policy layer —
this screen's UI never renders an Approve/Post/Reverse control for those roles at all, since `usePermission`
resolves to `false` unconditionally for them regardless of which entry or approval level is in view.

## Keyboard entry points

Consistent with `ACCESSIBILITY.md → Global keyboard shortcuts`: `G` then `A` navigates to this screen from
anywhere in the app; `N`, scoped to this route, opens `/new` (only rendered reachable when
`accounting.journal.create` is held — the shortcut and the button share one permission check, never two).

# Layout & Regions

All three sub-surfaces compose `LAYOUT_SYSTEM.md`'s existing page templates verbatim — no new template is
introduced for Journal Entries, per that document's "a screen never invents a sixth layout shape" rule.

## List — List Page Template

```
┌──────────────────────────────────────────────────────────────────────────┐
│ Journal Entries                                          [+ New Entry]   │
│ 1,842 entries                                    [Import ▾]  [Export ▾]  │
├──────────────────────────────────────────────────────────────────────────┤
│ [Search…]  [Status ▾]  [Period ▾]  [Source ▾]         [▤ Density]  [⚙]  │
├──────────────────────────────────────────────────────────────────────────┤
│ ☐ │ Entry #   │ Date    │ Reference    │ Status         │  Debit│Credit│⋯│
│ ☐ │ JE-000512 │ Jul 16  │ ACC-2026-07… │ ● Draft         │   850 │    – │⋯│
│ ☐ │ JE-000511 │ Jul 15  │ INV-2026-091 │ ● Pending appr. │ 1,050 │    – │⋯│
│ ☐ │ JE-000510 │ Jul 15  │ —            │ ● Posted        │44,020 │44,020│⋯│
│ …│  …         │ …       │ …            │ …               │  …    │  …   │⋯│
├──────────────────────────────────────────────────────────────────────────┤
│ Showing 1–25 of 1,842                                   [‹ Prev] [Next ›] │
└──────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — title, live entry count (`meta.pagination.total`), primary action ("New Journal
  Entry", gated `accounting.journal.create`), secondary actions menu (Import → `accounting.journal.import`,
  Export → `accounting.journal.export`).
- **Filter Bar** — search (`q`, full-text over `memo` + `reference` + line `description`), and the three
  filters this screen's spec calls out explicitly: **Status** (multi-select over the eight lifecycle
  values), **Period** (`PeriodPicker`, `mode="fiscal_period"` by default with a `mode="relative"` toggle
  for "This month" / "Last 30 days"), **Source** (a grouped select over `entry_type` — Manual, Invoice,
  Bill, Payment, Receipt, Inventory, Payroll, Depreciation, Loan, Asset, Tax, Revaluation, Year Closing,
  Opening Balance, AI Generated, Reversal, Adjustment — grouped visually into *Manual*, *Automatic*, and
  *AI-generated* per `docs/accounting/JOURNAL_ENTRIES.md`'s `entry_type` enum). A density toggle and a
  column-visibility menu sit at the Filter Bar's end edge.
- **Bulk Action Bar** replaces the Filter Bar's end side the moment ≥1 row is selected: "3 selected ·
  Approve · Export · Clear" — each bulk action re-checks its own permission and re-validates server-side
  per row (a bulk "Approve" is `N` individual `POST .../approve` calls, reported back per-row, never a
  single all-or-nothing endpoint).
- **Data Region** — `DataTable` (`resource="accounting/journal-entries"`, `paginationMode="page"`).
- **Pagination Footer** — page controls; "Showing 1–25 of 1,842."

The List keeps **two separate Debit and Credit columns** rather than `COMPONENT_LIBRARY.md → Pattern 1`'s
generic single-"Amount" illustration, and this is a deliberate, screen-specific departure worth naming
explicitly: `total_debit` and `total_credit` are only guaranteed equal once an entry is fully, correctly
composed (`docs/accounting/JOURNAL_ENTRIES.md` explicitly allows an out-of-balance `draft` to exist). Two
columns mean a Senior Accountant scanning a page of drafts can spot an unbalanced one — the two numbers
visibly disagree — without opening it; collapsing to one signed "Amount" figure the way a header-level
total is shown elsewhere would hide exactly the signal this list most needs to surface. Both columns render
through `AmountCell` with `mode="plain"` (each column is inherently one-sided; there is no debit/credit
ambiguity within a single column), a muted en dash for the zero side, and `emphasis="strong"` reserved for
the two columns matching on a `posted` row.

## Composer — Form Page Template

```
┌─────────────────────────────────────────────────┬───────────────┐
│ New Journal Entry      [Cancel] [Save Draft] [Post] │ Live balance │
├─────────────────────────────────────────────────┤  Dr   850.000  │
│  Header Info                                      │  Cr   850.000  │
│  Date [__]  Currency [KWD▾]  Reference [__]       │  ✓ Balanced    │
│  Memo [_____________________________________]     ├───────────────┤
├─────────────────────────────────────────────────┤  AI suggests    │
│  Lines                                             │  template:     │
│  ┌─────────────┬──────────┬───────┬───────┬───┐   │  "Monthly rent │
│  │ Account     │Description│ Debit│Credit │ ⋯ │   │  accrual" 92%  │
│  │ 5310 · Rent │Office rent│850.000│    –  │ ⋯ │   │  [Apply] [✕]   │
│  │ 2205 · Accr.│Accrued pay│    – │850.000 │ ⋯ │   │                │
│  └─────────────┴──────────┴───────┴───────┴───┘   │                │
│  [+ Add line]                                       │                │
├─────────────────────────────────────────────────┤                │
│  Attachments                          [Upload]     │                │
├─────────────────────────────────────────────────┴───────────────┤
│ ── sticky footer, appears once header scrolls out of view ──────│
│         [Cancel]        [Save Draft]        [Post]               │
└───────────────────────────────────────────────────────────────────┘
```

- **Page Header** — "New Journal Entry" / "Edit JE-2026-000512"; Cancel (secondary, confirms discard if
  dirty); Save Draft (secondary, `accounting.journal.create`/`.update`); Post or Submit for approval
  (primary — label and behavior resolved from `accounting.journal.post`, see `# Interactions & Flows`).
- **Form Body** — three `Card` sections: Header Info (date, currency, reference, memo, optional header-level
  `cost_center_id`), Lines (`JournalLineGrid`), Attachments.
- **Live Preview Rail** (3–4/12, `xl:`+; becomes an inline panel above the sticky footer below `xl:`) —
  the running `Σdebit − Σcredit` balance indicator, and, when the AI layer has a matching
  `journal_entry_templates` suggestion for this memo/date pattern, an inline "Apply template" card (never
  auto-filled without the explicit click, per the platform's no-silent-autofill-of-financial-values rule).
- **Sticky Footer Action Bar** — duplicates Cancel/Save Draft/Post once the header scrolls out of view;
  the header's own buttons gain `aria-hidden` while the footer bar is the visible instance, so there is
  never a duplicate, ambiguous pair of live focus targets (`LAYOUT_SYSTEM.md → Form Page Template`).

## Detail — Detail Page Template

```
┌───────────────────────────────────────────────────┬───────────────┐
│ ‹ Journal Entries   JE-2026-000512      ● Posted   │  Summary       │
│                                  [Reverse ▾] [⋯]   │  ───────────   │
├─ Lines │ Attachments │ History ──────────────────┤  Total 850.000 │
│  Account          │ Debit   │ Credit  │ Memo       │  KWD           │
│  5310 · Rent Exp.  │  850.000│     –   │ Office rent│  Period       │
│  2205 · Accrued Pay│      –  │ 850.000 │ Accrued    │  Jul 2026      │
│                                                     │  Created by AI │
├─────────────────────────────────────────────────────┤  Confidence 92%│
│  (Attachments tab: receipts, source documents)      │  [View reasoning]│
│  (History tab: full lifecycle + version timeline)    ├───────────────┤
└───────────────────────────────────────────────────┴───────────────┘
```

- **Page Header** — back-to-list link, entry number, `StatusPill`, the single lifecycle action that
  applies right now (Approve/Reject via `ApprovalCard` inline banner when `pending_approval` and the
  viewer is an eligible approver; Post when `approved` or an approval-exempt `draft`; Reverse when
  `posted`), and an overflow menu for Archive/Unarchive/Delete/Export-this-entry.
- **Tab/Segment Nav** — `Tabs`: **Lines** (default), **Attachments**, **History** — exactly the case
  `LAYOUT_SYSTEM.md → Detail Page Template` names ("only when the entity has sub-views") for a journal
  entry, matching the three sub-views `docs/frontend/README.md`'s index already advertises for this screen.
- **Main Column (8/12)** — the active tab's content: the read-only `JournalLinesTable` (Lines), the
  attachments grid keyed to `attachable_type = 'journal_entry'` (Attachments), or the merged
  `journal_entry_history` + `audit_logs` timeline (History).
- **Summary Rail (4/12)** — total amount (`AmountCell`, `emphasis="strong"`), currency (`CurrencyTag`),
  fiscal period, creator, and — only when `ai_generated = true` — the AI provenance block:
  `ConfidenceBadge`, agent name, and a "View reasoning" trigger that opens the AI reasoning `Sheet`
  (`# AI Integration`). When `posted_by` resolves to the platform's system service account rather than a
  human `users` row (the confidence-gated auto-post whitelist path, `# AI Integration`), the "Posted by"
  field renders "Posted automatically under company policy" rather than a blank, a raw id, or a crash.
- **Activity Timeline** — always rendered at the bottom of the Main Column inside the History tab, never
  relegated to a rail footnote, per `LAYOUT_SYSTEM.md → Detail Page Template`.

# Components Used

Every component below is drawn from `COMPONENT_LIBRARY.md` as-is, or is one of the screen-specific composed
components this document introduces for this exact surface. No new primitive or generic finance component
is introduced here — a component gap discovered while building this screen is proposed as an addition to
`COMPONENT_LIBRARY.md`, never hand-rolled locally.

| Component | Source | Used for |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | The List's server-paginated, sortable, filterable entry table (`resource="accounting/journal-entries"`) |
| `StatusPill` | `components/shared/status-pill.tsx` | Every lifecycle-status rendering, list and detail (`domain="journal_entry"`) |
| `AmountCell` | `components/accounting/amount-cell.tsx` | Every debit/credit/total figure, list and detail, `mode="debit" \| "credit" \| "plain"` |
| `CurrencyTag` | `components/accounting/currency-tag.tsx` | Header currency indicator on the Composer and the Summary Rail |
| `PeriodPicker` | `components/accounting/period-picker.tsx` | The List's Period filter (`mode="fiscal_period"`/`"relative"`) |
| `AccountPicker` | `components/accounting/account-picker.tsx` | Every line's account cell inside `JournalLineGrid`; `postableOnly`, `controlAccountFilter` wired per line |
| `JournalEntryForm` | `components/accounting/journal-entry-form.tsx` | The Composer's outer form (header fields, Save Draft/Post, balance banner) |
| `JournalLineGrid` | `components/accounting/journal-line-grid.tsx` (screen-specific — new in this doc, see below) | The multi-line debit/credit editor inside `JournalEntryForm`, implementing the ARIA `grid` pattern |
| `JournalEntryDetail` | `components/accounting/journal-entry-detail.tsx` (screen-specific) | The Detail route's read-only composition: `JournalEntryHeader` + conditional `ApprovalCard` + `Tabs` |
| `JournalEntryHeader` | `components/accounting/journal-entry-header.tsx` (screen-specific) | The Detail page's back-link, entry number, `StatusPill`, and lifecycle action button |
| `JournalLinesTable` | `components/accounting/journal-lines-table.tsx` (screen-specific) | The Lines tab's read-only, posted-line rendering — the plain `<table>` accessibility pattern, never the grid |
| `ApprovalCard` | `components/shared/approval-card.tsx` | Inline approval banner on the Detail page and the List's row/bulk actions (`kind="journal_entry"`) |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | AI-provenance confidence display, Summary Rail and reasoning `Sheet` |
| `Tabs` | `components/ui/tabs.tsx` | Detail page's Lines / Attachments / History segmentation |
| `Sheet` | `components/ui/sheet.tsx` | The AI reasoning drill-in; the mobile row-detail drawer |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` | Reverse confirmation, Reject reason, duplicate-suspected warning, discard-draft confirmation |
| `PermissionGate` | `components/shared/permission-gate.tsx` | Wraps every mutating affordance named in `# Route & Access` |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Row actions in the List; the Detail page's overflow menu |
| `Badge` | `components/ui/badge.tsx` | Source/entry-type chips in the Filter Bar's Source groups |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing, mapped from the API envelope |

## `JournalLineGrid`

`JournalEntryForm`'s own general-purpose reference implementation in `COMPONENT_LIBRARY.md` renders its
lines as a plain HTML `<table>`; on this screen, where the line editor is the single densest, most
frequently used piece of UI in the whole platform, its Lines region is promoted to `JournalLineGrid` — the
canonical ARIA `grid` implementation `ACCESSIBILITY.md → Data Tables Accessibility` names explicitly as one
of exactly two surfaces in QAYD that warrant the heavier grid pattern ("the Journal Entry line editor, and
the Bank Reconciliation matching grid"), because a line editor is genuinely spreadsheet-like: a user
reasonably expects arrow-key cell-to-cell navigation while keying many small values in place, and the plain
`<table>` pattern used by the List and every other display table in this document would make a 40-line
entry take 160+ `Tab` presses to traverse. `JournalLineGrid` is a controlled component driven entirely by
the parent `JournalEntryForm`'s React Hook Form `useFieldArray` state — it holds no server state of its own.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `lines` | `JournalLineFormValues[]` | yes | The current `useFieldArray` field list. |
| `onChangeLine` | `(index: number, patch: Partial<JournalLineFormValues>) => void` | yes | Applied via `form.setValue`, never local component state. |
| `onAddLine` | `() => void` | yes | Appends a blank line; focuses its Account cell. |
| `onRemoveLine` | `(index: number) => void` | yes | Disabled below 2 remaining lines (`fields.length <= 2`), matching the backend's "at least two lines" invariant. |
| `currencyCode` | `string` | yes | Header currency, inherited by every line unless per-line override is enabled by company policy. |
| `readOnlyDimensions` | `boolean` | no | `true` while any approval level has already signed off but the entry is still technically `draft` (the single-user self-approval exception path) — locks dimension fields while leaving amounts editable. |

```tsx
// components/accounting/journal-line-grid.tsx
"use client";

import { useState } from "react";
import { AccountPicker } from "@/components/accounting/account-picker";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Trash2, Plus, ChevronDown } from "lucide-react";
import { LineDimensionsPopover } from "@/components/accounting/line-dimensions-popover";
import { useTranslations } from "next-intl";

function clamp(n: number, min: number, max: number) {
  return Math.min(Math.max(n, min), max);
}

function useRovingGrid(rowCount: number, colCount: number) {
  const [active, setActive] = useState({ row: 0, col: 0 });
  function onKeyDown(e: React.KeyboardEvent) {
    const deltas: Record<string, [number, number]> = {
      ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1],
    };
    const delta = deltas[e.key];
    if (!delta) return;
    e.preventDefault();
    setActive(({ row, col }) => ({
      row: clamp(row + delta[0], 0, rowCount - 1),
      col: clamp(col + delta[1], 0, colCount - 1),
    }));
  }
  return { active, onKeyDown, isActive: (r: number, c: number) => r === active.row && c === active.col };
}

const COLUMNS = ["account", "description", "debit", "credit", "dimensions", "remove"] as const;

export function JournalLineGrid({
  lines, onChangeLine, onAddLine, onRemoveLine, currencyCode, readOnlyDimensions,
}: JournalLineGridProps) {
  const t = useTranslations("journalEntryForm.lineGrid");
  const { active, onKeyDown, isActive } = useRovingGrid(lines.length, COLUMNS.length);

  return (
    <div className="rounded-lg border border-ink-150">
      <div
        role="grid"
        aria-label={t("gridLabel")}
        aria-rowcount={lines.length}
        aria-colcount={COLUMNS.length}
        className="min-w-full"
      >
        <div role="row" className="grid grid-cols-[2fr_2fr_1fr_1fr_2.5rem_2.5rem] bg-ink-100 text-caption text-ink-700">
          {["account", "description", "debit", "credit", "", ""].map((label, c) => (
            <div key={c} role="columnheader" className="p-2 text-start">{label && t(label)}</div>
          ))}
        </div>
        {lines.map((line, r) => (
          <div
            key={line.id}
            role="row"
            aria-rowindex={r + 1}
            className="grid grid-cols-[2fr_2fr_1fr_1fr_2.5rem_2.5rem] border-t border-ink-150 items-center"
          >
            <div role="gridcell" tabIndex={isActive(r, 0) ? 0 : -1} onFocus={() => {}} onKeyDown={onKeyDown} className="p-1.5">
              <AccountPicker
                value={line.account_id}
                onChange={(id, account) => {
                  onChangeLine(r, { account_id: id });
                  if (account.requires_cost_center || account.is_control_account) {
                    onChangeLine(r, { dimensionsExpanded: true }); // auto-expand — see Interactions & Flows
                  }
                }}
                postableOnly
                aria-label={t("a11y.accountForLine", { line: r + 1 })}
              />
            </div>
            <div role="gridcell" tabIndex={isActive(r, 1) ? 0 : -1} onKeyDown={onKeyDown} className="p-1.5">
              <Input
                value={line.description ?? ""}
                onChange={(e) => onChangeLine(r, { description: e.target.value })}
                aria-label={t("a11y.descriptionForLine", { line: r + 1 })}
              />
            </div>
            <div role="gridcell" tabIndex={isActive(r, 2) ? 0 : -1} onKeyDown={onKeyDown} className="p-1.5">
              <Input
                variant="numeric"
                value={line.debit}
                onChange={(e) => onChangeLine(r, { debit: e.target.value, credit: Number(e.target.value) > 0 ? "0.0000" : line.credit })}
                aria-label={t("a11y.debitForLine", { line: r + 1 })}
              />
            </div>
            <div role="gridcell" tabIndex={isActive(r, 3) ? 0 : -1} onKeyDown={onKeyDown} className="p-1.5">
              <Input
                variant="numeric"
                value={line.credit}
                onChange={(e) => onChangeLine(r, { credit: e.target.value, debit: Number(e.target.value) > 0 ? "0.0000" : line.debit })}
                aria-label={t("a11y.creditForLine", { line: r + 1 })}
              />
            </div>
            <div role="gridcell" tabIndex={isActive(r, 4) ? 0 : -1} onKeyDown={onKeyDown} className="p-1.5">
              <LineDimensionsPopover
                line={line}
                readOnly={readOnlyDimensions}
                currencyCode={currencyCode}
                onChange={(patch) => onChangeLine(r, patch)}
                trigger={
                  <Button variant="ghost" size="icon" aria-label={t("a11y.dimensionsForLine", { line: r + 1 })}>
                    <ChevronDown className="size-4" />
                  </Button>
                }
              />
            </div>
            <div role="gridcell" tabIndex={isActive(r, 5) ? 0 : -1} onKeyDown={onKeyDown} className="p-1.5">
              <Button
                type="button" variant="ghost" size="icon" disabled={lines.length <= 2}
                onClick={() => onRemoveLine(r)} aria-label={t("a11y.removeLine", { line: r + 1 })}
              >
                <Trash2 className="size-4 text-ink-500" />
              </Button>
            </div>
          </div>
        ))}
      </div>
      <div className="p-2">
        <Button type="button" variant="ghost" size="sm" onClick={onAddLine}>
          <Plus className="size-3.5" /> {t("addLine")}
        </Button>
      </div>
    </div>
  );
}
```

Two design decisions carry real weight here and are binding, not incidental to the illustration:

1. **Dimensions (`cost_center_id`, `project_id`, `department_id`, `tax_code_id`/`tax_rate_id`,
   `customer_id`/`vendor_id`) live behind a per-line `LineDimensionsPopover`, not as six more visible
   grid columns.** `journal_lines` carries eleven optional foreign keys beyond account/amount
   (`docs/accounting/JOURNAL_ENTRIES.md → Journal Lines`); rendering all of them as always-visible columns
   would violate `DESIGN_LANGUAGE.md`'s "density with air" principle and make a four-line entry scroll
   horizontally for no reason. The popover auto-opens (`dimensionsExpanded: true`) the moment the selected
   account's mapping requires it — `requires_cost_center = true` on the account, or the account is an AR/AP
   control account requiring `customer_id`/`vendor_id` — so the one case where a dimension is mandatory
   never hides behind an extra click, while the common case (a simple two-line manual entry with no
   dimensions) stays visually minimal. A line whose required dimension is still unset shows a small
   `warning`-tone dot on its `LineDimensionsPopover` trigger.
2. **The roving-tabindex grid pattern is implemented once, here, and reused verbatim by the Bank
   Reconciliation matching grid** (per `ACCESSIBILITY.md`'s statement that this screen is the canonical
   implementation) — `useRovingGrid` is exported from this file rather than duplicated.

## `JournalEntryDetail`, `JournalEntryHeader`, `JournalLinesTable`

Three thin, screen-specific compositions cover the read-only branch of the Detail route. `JournalEntryHeader`
renders the back-link, `entry.journal_number`, `StatusPill`, and resolves the *one* lifecycle action that
applies to the entry's current `status` and the viewer's permissions (never more than one primary action at
a time — a `posted` entry shows Reverse, never Reverse-and-also-Archive side by side as two primaries).
`JournalLinesTable` is the Lines tab's content: the plain, accessible `<table>` pattern (never the grid —
these lines are immutable once posted, so there is nothing to arrow-key between), sorted by `line_number`,
with a totals `<tfoot>` and an AI-suggestion caption under any line where
`ai_suggested_account_id IS NOT NULL AND ai_suggested_account_id <> account_id` (a human overrode the AI's
proposal — surfaced for transparency even after posting, per the platform's explainability requirement).
`JournalEntryDetail` composes the two of them plus a conditional `ApprovalCard` (rendered only while
`status = 'pending_approval'`) inside the `Tabs` region `# Layout & Regions` describes, and is the single
component `[journalEntryId]/page.tsx` mounts for every non-editable status.

# Data & State

## Endpoints

Every endpoint below is `docs/accounting/JOURNAL_ENTRIES.md → API`'s table, annotated with which hook and
query key this screen wires it to. All are versioned under `/api/v1/accounting/journal-entries`, carry
`X-Company-Id`, and return the standard envelope.

| Method & Path | Permission | Hook | Query key |
|---|---|---|---|
| `GET /journal-entries` | `.read` | `useJournalEntries(filters)` | `journalEntryKeys.list(filters)` |
| `GET /journal-entries/{id}` | `.read` | `useJournalEntry(id)` | `journalEntryKeys.detail(id)` |
| `POST /journal-entries` | `.create` | `useCreateJournalEntry()` | invalidates `journalEntryKeys.lists()` |
| `PUT /journal-entries/{id}` | `.update` | `useUpdateJournalEntry(id)` | invalidates `detail(id)` + `lists()` |
| `POST /journal-entries/{id}/submit` | `.submit` | `useSubmitJournalEntry()` | optimistic status patch, see `# Interactions & Flows` |
| `POST /journal-entries/{id}/approve` | `.approve` | `useApproveJournalEntry()` | optimistic status patch |
| `POST /journal-entries/{id}/reject` | `.approve` | `useRejectJournalEntry()` | optimistic status patch |
| `POST /journal-entries/{id}/post` | `.post` | `usePostJournalEntry()` | pessimistic — see `## Mutations` |
| `POST /journal-entries/{id}/reverse` | `.reverse` | `useReverseJournalEntry()` | invalidates `detail(id)` + `detail(newReversalId)` + `lists()` |
| `DELETE /journal-entries/{id}` | `.delete` | `useDeleteJournalEntry()` | removes from `lists()` cache; invalidates on settle |
| `POST /journal-entries/{id}/archive` \| `/unarchive` | `.archive` | `useArchiveJournalEntry()` | optimistic status patch |
| `GET /journal-entries/{id}/history` | `.read` | `useJournalEntryHistory(id)` | `journalEntryKeys.history(id)` |
| `POST /journal-entries/{id}/attachments` | `.update` | `useAddJournalEntryAttachment()` | invalidates `detail(id)` |
| `GET /journal-entries/{id}/ai-explanation` | `.read` | `useJournalEntryAiExplanation(id)` | `journalEntryKeys.aiExplanation(id)`, `enabled: entry.ai_generated` |
| `GET /journal-entries/export` | `.export` | `useExportJournalEntries()` | not cached — fires an async `report_runs` job, see `# Performance` |
| `POST /journal-entries/bulk-import` | `.import` | `useBulkImportJournalEntries()` | invalidates `lists()` on settle |

## Query key factory

```ts
// lib/query/keys.ts (excerpt)
export const journalEntryKeys = {
  all: ["accounting", "journal-entries"] as const,
  lists: () => [...journalEntryKeys.all, "list"] as const,
  list: (filters: JournalEntryFilters) => [...journalEntryKeys.lists(), filters] as const,
  details: () => [...journalEntryKeys.all, "detail"] as const,
  detail: (id: number | string) => [...journalEntryKeys.details(), id] as const,
  history: (id: number | string) => [...journalEntryKeys.detail(id), "history"] as const,
  aiExplanation: (id: number | string) => [...journalEntryKeys.detail(id), "ai-explanation"] as const,
};
```

`JournalEntryFilters` mirrors the search/filter query parameters
`docs/accounting/JOURNAL_ENTRIES.md → Search / filtering` documents: `status`, `entry_type`,
`source_module`, `date_from`/`date_to`, `account_id`, `cost_center_id`, `project_id`, `customer_id`,
`vendor_id`, `currency_code`, `min_amount`/`max_amount`, `reference`, `created_by`, `q`, `sort`. The List's
three named filters map onto this parameter set directly: **Status** → `status` (array, `in`), **Period**
→ a resolved `date_from`/`date_to` pair from whichever `fiscal_periods` row `PeriodPicker` selects, **Source**
→ `entry_type` (array) grouped client-side into Manual / Automatic / AI-generated for the filter's own UI,
with `source_module` layered on when a user narrows an "Automatic" group entry further (e.g. to Payroll
only).

## Reference data

`AccountPicker`, `LineDimensionsPopover`, and the Source filter all depend on read-mostly reference
collections that change far less often than journal entries themselves, so each is fetched through its own
short, independent query with a generous `staleTime` (5 minutes, per `FRONTEND_ARCHITECTURE.md → Cache
tuning by data class`'s "rarely-changing reference/master data" tier) rather than being bundled into the
entry payload:

| Reference | Endpoint | Consumed by |
|---|---|---|
| Accounts (Chart of Accounts) | `GET /api/v1/accounting/accounts` | `AccountPicker` (`postableOnly`, `controlAccountFilter`, `accountTypeFilter`) |
| Cost centers | `GET /api/v1/accounting/cost-centers` | `LineDimensionsPopover` |
| Projects | `GET /api/v1/accounting/projects` | `LineDimensionsPopover` |
| Departments | `GET /api/v1/foundation/departments` | `LineDimensionsPopover` |
| Tax codes / rates | `GET /api/v1/accounting/tax-codes` | `LineDimensionsPopover` (only rendered when the line's account is tax-relevant) |
| Customers / Vendors | `GET /api/v1/sales/customers`, `GET /api/v1/purchasing/vendors` | `LineDimensionsPopover`, only rendered when the line's account is a matching control account |
| Fiscal periods | `GET /api/v1/accounting/fiscal-periods` | `PeriodPicker` (both the List filter and the Composer's implicit period resolution banner) |

The List itself follows the "transactional lists" tier from the same cache-tuning table: `staleTime: 30_000`
— frequent enough writes that a stale list is a real annoyance, not worth polling.

## Client state ownership

Consistent with `FRONTEND_ARCHITECTURE.md → State Management`:

| State | Owner |
|---|---|
| The entry list, one entry's detail, its history, its AI explanation | TanStack Query cache, keyed as above |
| The in-progress Composer form values (header fields + `lines[]`) | React Hook Form's internal state, schema-validated by `journalEntrySchema` (`# Interactions & Flows`) |
| List density, column visibility, saved filter presets | Zustand (`useDensityStore`, per-table-keyed, per `LAYOUT_SYSTEM.md → Density Modes`) + `users.settings` sync |
| Selected rows for bulk action | Local component state inside `DataTable`, cleared on navigation |
| "Which line's dimensions popover is open" | Local `useState` inside `JournalLineGrid` — ephemeral UI, never persisted |
| A mid-entry draft's `Idempotency-Key`, keyed to the Composer's `formInstanceId` | `sessionStorage`, per `FRONTEND_ARCHITECTURE.md → Idempotency keys` — never regenerated across retries of the same submission attempt |

## Mutations — optimistic vs. pessimistic

Per `FRONTEND_ARCHITECTURE.md`'s Principle 10 — whose own worked example *is* "posting a journal entry" —
this screen is the platform's canonical illustration of the optimistic/pessimistic split: a reversible,
non-financial action (dismissing a duplicate-suspected warning, archiving a posted entry for visibility
only) updates the cache immediately and rolls back on failure; a mutation that changes the ledger's
authoritative state (submit, approve, reject, post, reverse) waits for the server's `2xx` before the UI
treats it as done, and never renders an "in progress" state as if it had already succeeded.

```ts
// hooks/accounting/use-journal-entries.ts (excerpt)
export function usePostJournalEntry() {
  const qc = useQueryClient();
  const idempotencyKey = useIdempotencyKey("journal-entry-post");
  return useMutation({
    // No onMutate — posting is Principle 10's own named example of a mutation that "waits for
    // the server's 2xx before the UI treats it as done," and always renders a confirming dialog
    // before it fires (see Interactions & Flows).
    mutationFn: (id: number) => api.post(`/accounting/journal-entries/${id}/post`, {}, idempotencyKey),
    onSuccess: (entry: JournalEntry) => {
      qc.setQueryData(journalEntryKeys.detail(entry.id), entry);
      qc.invalidateQueries({ queryKey: journalEntryKeys.lists() });
    },
  });
}

export function useArchiveJournalEntry(id: number) {
  const qc = useQueryClient();
  return useMutation({
    // Archive/unarchive is visibility-only — no financial mutation ever occurs — so it is the
    // reversible, low-stakes half of Principle 10 and updates optimistically.
    mutationFn: (archived: boolean) =>
      api.post(`/accounting/journal-entries/${id}/${archived ? "archive" : "unarchive"}`, {}),
    onMutate: async (archived) => {
      await qc.cancelQueries({ queryKey: journalEntryKeys.detail(id) });
      const previous = qc.getQueryData<JournalEntry>(journalEntryKeys.detail(id));
      qc.setQueryData(journalEntryKeys.detail(id), (old?: JournalEntry) =>
        old ? { ...old, status: archived ? "archived" : old.status } : old);
      return { previous };
    },
    onError: (_e, _v, ctx) => qc.setQueryData(journalEntryKeys.detail(id), ctx?.previous),
    onSettled: () => qc.invalidateQueries({ queryKey: journalEntryKeys.lists() }),
  });
}
```

`useSubmitJournalEntry`, `useApproveJournalEntry`, `useRejectJournalEntry`, and `useReverseJournalEntry`
follow `usePostJournalEntry`'s exact shape — no `onMutate`, a client-generated `Idempotency-Key` per logical
submission attempt (`FRONTEND_ARCHITECTURE.md → Idempotency keys, generated and scoped correctly`), and a
confirming `Dialog`/`AlertDialog` in front of every one of them except Submit (Submit's own confirmation
*is* the balance banner already visible in the Composer — a second modal in front of an already-visible,
already-affirmative "Balanced ✓" state would be friction without a corresponding safety benefit).

## Realtime

The List and the Detail page both subscribe to the company's private accounting channel via Echo, re-firing
the exact domain events `docs/accounting/JOURNAL_ENTRIES.md → Notifications` and `→ Posting Engine` already
name — this hook does not invent a parallel set of event names:

```ts
// hooks/accounting/use-journal-entry-realtime.ts
'use client';
import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { echo } from '@/lib/realtime/echo';
import { journalEntryKeys } from '@/lib/query/keys';

export function useJournalEntryRealtime(companyId: string) {
  const queryClient = useQueryClient();

  useEffect(() => {
    const channel = echo.private(`company.${companyId}.accounting`);

    channel.listen('.journal.posted', (e: JournalPostedEvent) => {
      // Matches the exact payload the Posting Engine dispatches after commit
      // (docs/accounting/JOURNAL_ENTRIES.md → Posting Engine, step 13).
      queryClient.setQueryData(journalEntryKeys.detail(e.journal_entry_id), (old?: JournalEntry) =>
        old ? { ...old, status: 'posted', posted_at: e.posted_at, journal_number: e.journal_number } : old,
      );
      queryClient.invalidateQueries({ queryKey: journalEntryKeys.lists(), refetchType: 'inactive' });
    });

    // '.journal.submitted_for_approval' also covers a non-final approval-level advance — the next
    // level's eligible approvers are routed the same notification per the Approval Workflow's own
    // routing rule, so this hook does not need a second, bespoke "level advanced" event name.
    for (const event of ['.journal.submitted_for_approval', '.journal.rejected', '.journal.reversed',
                          '.journal.ai_draft_ready', '.journal.fraud_risk_flagged']) {
      channel.listen(event, () => queryClient.invalidateQueries({ queryKey: journalEntryKeys.lists(), refetchType: 'inactive' }));
    }

    return () => { echo.leave(`company.${companyId}.accounting`); };
  }, [companyId, queryClient]);
}
```

Two deliberate choices: the currently-open detail record is patched surgically via `setQueryData` (so an
open Detail page reflects another user's action within the same second, with the row briefly flashing an
`accent-subtle` tint per `DESIGN_LANGUAGE.md → Motion → Realtime row update` before easing back), while the
List's own cached pages are only marked `invalidateQueries(..., { refetchType: 'inactive' })` — refetched
the next time they become the active query, never yanked out from under a user mid-scroll, matching the
non-disruptive realtime-list pattern `# Edge Cases` describes. The final `pending_approval → approved`
transition and archive/unarchive are not individually broadcast by name; `approved` is normally followed
immediately, in the same request cycle, by a synchronous post (which *does* broadcast `.journal.posted`),
and both fall back safely on the List's own 30-second `staleTime` in the rarer case where they are not.

## AI agents feeding this screen

Per `docs/accounting/JOURNAL_ENTRIES.md → AI Responsibilities`, six agent behaviors surface here, each
through the ordinary create/read API — never a bespoke AI endpoint this screen has to special-case:

| Agent | What appears on this screen | Autonomy |
|---|---|---|
| General Accountant | New `ai_generated` rows in the List (unmatched bank lines, OCR'd bills, detected recurring patterns) | Suggest-only — lands as `draft` |
| Document AI / OCR Agent | Per-line `ai_suggested_account_id` shown as a subtle "AI suggested: 6400 · Bad Debt Expense" caption under `AccountPicker` when it differs from the line's current `account_id` | Suggest-only |
| Fraud Detection | An additional required approval level and a `warning`-tone note on `ApprovalCard` ("+1 review required — flagged by Fraud Detection") | Escalation-only; never blocks posting outright |
| Duplicate Detection | The inline duplicate-suspected warning at submit time (`# Interactions & Flows`) | Suggest-only; never auto-rejects |
| Suggested Entries | The Composer's "AI suggests template" card, pre-filling lines only on explicit "Apply" | Suggest-only |
| Explanation | The AI reasoning `Sheet` content, generated on demand from `GET .../ai-explanation` | Read-only, no side effects |

# Interactions & Flows

**Creating a manual entry.** From the List, "New Journal Entry" (gated `accounting.journal.create`) opens
`/new`. `JournalEntryForm` mounts with `mode="create"`, `entry_type` defaulting to `manual`, and two blank
lines already present (`journalEntrySchema`'s own `min(2)` invariant, mirrored client-side). The accountant
fills the header (date, currency, reference, memo — memo is required by policy for a manual entry,
enforced client-side by `journalEntrySchema` and re-enforced server-side per
`docs/accounting/JOURNAL_ENTRIES.md → Validation Rules`), then works through `JournalLineGrid` one line at a
time: selecting an account auto-focuses the Debit cell of that same row, typing a Debit value zeroes that
row's Credit field and vice versa (a line is never allowed to visually suggest it could be both at once),
and the Live Preview Rail's `Σdebit − Σcredit` indicator recomputes on every keystroke, flipping from
`danger`-toned "Out of balance by 50.000" to `success`-toned "Balanced ✓" the instant the two sums agree —
this is the fast, friendly, purely cosmetic check `# Purpose` describes; the server re-derives the same sum
independently and is the only party that can actually accept the entry.

**Save Draft vs. the primary action.** Save Draft (`accounting.journal.create`/`.update`) is always
available regardless of balance state — an accountant is allowed to leave for lunch mid-entry with a
deliberately unbalanced placeholder saved. The primary button's label and behavior are resolved from
`accounting.journal.post`, never guessed: a holder's click posts directly (subject to the server's own
re-check of the amount threshold — a Senior Accountant under Tier-1 posts immediately, but the exact same
click for an amount that crosses Tier-2 comes back with the entry now `pending_approval` instead of an
error, and the UI simply reflects whatever status the response actually returned); everyone else's click
calls `POST .../submit` and the button is labeled "Submit for approval" from the start, never silently
disabled without explanation. The primary button is additionally disabled — with an inline reason, not a
bare `disabled` — while the balance banner reads "Out of balance."

**Duplicate-suspected warning.** A `submit`/`post` attempt that the Duplicate Detection agent's
near-real-time scan (`AIJournalDuplicateScan`, queued on every draft creation/submit) has flagged returns
`success: true` with a non-blocking warning carried in the envelope's `meta`:

```json
{
  "success": true,
  "data": { "id": 88231, "status": "draft", "journal_number": "JE-2026-000512" },
  "message": "Journal entry saved as draft.",
  "errors": [],
  "meta": {
    "pagination": null,
    "warnings": [
      {
        "code": "DUPLICATE_SUSPECTED",
        "confidence": 0.81,
        "candidate_ids": [87960],
        "message": "This looks similar to JE-2026-000488, posted 3 days ago for the same amount and accounts."
      }
    ]
  },
  "request_id": "b7c8d9e0-1234-4abc-9def-0123456789ab",
  "timestamp": "2026-07-16T09:12:44Z"
}
```

The Composer renders this as a dismissible, non-blocking inline banner ("This looks similar to JE-2026-000488
— View entry · Not a duplicate"), never a modal that blocks the save the request already succeeded at. "View
entry" opens the candidate in a new tab; "Not a duplicate" dismisses the banner and is retained in
`audit_logs` as the human's override decision, per `docs/accounting/JOURNAL_ENTRIES.md`'s AI Responsibilities
note that a human's override of a suggestion is always logged, never silently discarded.

**Editing a `draft` or `rejected` entry.** Opening `[journalEntryId]` for either status resolves to
`JournalEntryForm` in edit mode (`# Route & Access`). Every save carries the entry's last-read `version`; a
concurrent edit by another user surfaces as a `409` toast — "This entry was modified by someone else — reload
and retry" — never a silent overwrite, per `docs/accounting/JOURNAL_ENTRIES.md → Locking Rules`'s optimistic
concurrency rule.

**Approval.** Opening a `pending_approval` entry the viewer is an eligible approver for renders an inline
`ApprovalCard` (`kind="journal_entry"`) above the read-only `JournalLinesTable`. Only the current level's
card is interactive; a multi-level chain shows every level as a `Stepper`-style progression naming each
level's `approver_role` and, once decided, its `decision_reason` — the current user's own Approve/Reject is
only ever enabled on the level that is actually `pending` and assigned to their role, never on a future
level, mirroring `FRONTEND_ARCHITECTURE.md → The Approval Center`'s identical out-of-order-approval
prevention. Reject requires a typed reason (`ApprovalCard`'s own contract — the confirm button stays
disabled on an empty reason) and returns the entry to `draft` for the original creator to correct and
resubmit. Approving the *final* required level transitions the entry to `approved` and, in the same request
cycle, triggers the Posting Engine synchronously — the UI reflects whatever terminal status the response
actually contains (usually `posted` already) rather than showing an intermediate "approved, about to post"
state the backend does not meaningfully expose. `onDelegate` is only rendered when the viewer is the
currently assigned approver for the open level.

**Posting.** For an approval-exempt `draft` (below Tier-1, or a company-configured automatic type opted into
post-hoc review only), the Detail page's lifecycle action reads "Post" directly. Clicking it opens a
lightweight confirming `Dialog` restating the amount and accounts before firing `usePostJournalEntry()` —
per Principle 10, every financial mutation "always renders a confirming dialog before it fires," even one
that requires no approval level at all.

**Reversing a posted entry.** "Reverse" (`accounting.journal.reverse`) opens a `Dialog` collecting a
reversal date (defaulting to today, constrained to an open period on or after the original `journal_date`)
and a mandatory reason. Submitting calls `POST .../reverse`; the response links both records
(`reversal_entry.reversed_entry_id` ↔ the original's new `reversal_entry_id`), and the Detail page's
Summary Rail immediately shows a "Reversed by JE-2026-000588 →" cross-link once the mutation settles.
Reversal is always whole-entry — there is no partial-line selection UI, matching the backend's explicit
design choice to correct via a fresh, fully balanced entry rather than a harder-to-audit partial patch (see
`# Edge Cases`).

**Archiving.** A visibility-only toggle on any `posted`/`reversed`/`voided` entry, gated
`accounting.journal.archive`, with no confirming dialog (it changes nothing financial and is instantly
reversible via "Unarchive") — the one lifecycle action on this screen that is deliberately *not* wrapped in
a confirmation, because wrapping every action in a modal regardless of stakes trains users to click through
modals without reading them.

**Bulk import.** "Import" opens a `Dialog` accepting a CSV/XLSX file and an `auto_submit` toggle. On submit,
`useBulkImportJournalEntries()` uploads to Cloudflare R2 and calls `POST .../bulk-import`; the response's
`entries_created`/`entries_failed`/`failures[]` shape (exactly `docs/accounting/JOURNAL_ENTRIES.md → API →
Example: bulk import`'s worked response) renders as a results summary — "118 entries created as draft, 2
rows failed" — with each failure's row number and `error` code listed so the user can fix and re-upload only
the failed rows, never the whole file.

**Bulk approve from the List.** Selecting ≥1 row swaps the Filter Bar for the Bulk Action Bar. "Approve"
fires one `POST .../approve` per selected row (never a single all-or-nothing bulk endpoint), reports each
row's individual result, and leaves any row that failed its own server-side re-check (e.g. the viewer is not
an eligible approver for that specific entry's current level) selected and flagged, so a partial bulk success
is legible rather than an opaque "3 of 5 succeeded."

**Applying an AI-suggested template.** When the Composer's header fields (date, memo pattern) match a
`journal_entry_templates` row closely enough, the Live Preview Rail's "AI suggests template" card appears
with the template's name and a confidence percentage. "Apply" pre-fills `lines[]` from the template's
`line_template` (resolving any formula-based amounts against current account balances) — the fields remain
fully editable afterward, and nothing posts or submits as a side effect of applying a template. "✕" dismisses
the suggestion for this session without applying it.

**Attachments.** Uploading a file (a signed receipt, an OCR source image, a board resolution) attaches via
the polymorphic `attachments` table (`attachable_type = 'journal_entry'`). A multi-vendor batch entry where
one attachment supports only a specific line tags that relationship through `custom_fields.line_number`
rather than a bespoke line-level attachments table, exactly as the backend spec describes — the Attachments
tab groups by that tag when present and falls back to "applies to the whole entry" otherwise.

# AI Integration

AI touches this screen at exactly three points, and none of them is a separate "AI tab": **drafting** (an
`ai_generated` entry enters the same list and the same Composer/Detail split every other entry uses),
**suggesting** (inline captions and a template card during composition, never a silent autofill), and
**reviewing** (fraud and duplicate flags surfaced at submit time and on the `ApprovalCard`). Per the
platform's non-negotiable rule, no AI output on this screen ever posts, approves, or reverses anything by
itself; `AIProposalPanel`'s "Do it" affordance structurally never renders on this screen at all, because
`entry_type = 'ai_generated'` always requires at least one human approval level
(`docs/accounting/JOURNAL_ENTRIES.md → Approval Workflow`) — the one narrow exception is described under
"Confidence-gated auto-post whitelist" below, and even that exception is a company-configured, CFO-approved,
capped policy applied by a background job, never a per-instance client-side click.

**General Accountant.** Drafts land in the List exactly like a human-typed draft, distinguished only by the
platform-wide AI-provenance affordances: the row's leftmost `Card`-equivalent border accent (in the List,
a small `Sparkles` glyph beside the entry number) and a filterable "AI Generated" group in the Source
filter. Opening one shows the same Composer the accountant would use to correct it, pre-filled via
`defaultValues`.

**Document AI / OCR Agent.** When a line's `ai_suggested_account_id` differs from its current `account_id`,
`AccountPicker` renders a small caption beneath itself: "AI suggested: 6400 · Bad Debt Expense" with an
inline "Use this" link. Clicking it calls `onChangeLine` with the suggested id; leaving the caption
untouched and instead picking a different account is itself the override the backend's Learning behavior
tracks (`ai_suggested_account_id ≠ account_id` at submit time) — the frontend does not need to fire a
separate "I disagree" signal; the divergence between the two stored fields *is* the signal.

**Fraud Detection.** Never renders a raw risk score anywhere on this screen — the score is an internal
signal attached to `journal_entries.custom_fields`, not a field this document's API table exposes for
direct display. Its only visible consequence is structural: a flagged entry's `ApprovalCard` gains an extra
required level with a `warning`-tone note, "+1 review required — flagged by Fraud Detection," fetched
automatically the moment the flag is created (the approval-chain query re-fetches without the user
refreshing). This mirrors the identical escalation pattern the platform uses elsewhere for a
`critical`-severity finding: additive to the approval chain, never a silent block on posting.

**Duplicate Detection.** Covered in full, with its worked JSON shape, under `# Interactions & Flows`. The
warning is deliberately non-blocking — the create/submit call it rides alongside already succeeded — because
false positives are expected (two legitimately identical recurring accruals in different periods look
identical to this check by design) and the backend explicitly does not auto-reject on a match.

**Suggested Entries.** The Composer's "AI suggests template" card (`# Layout & Regions`, Live Preview Rail)
is this agent's entire surface: a proactive nudge toward a recurring pattern, applied only on explicit
click, never silently pre-filled on page load.

**Explanation.** `GET /journal-entries/{id}/ai-explanation`, called only when `entry.ai_generated` is true,
powers the "View reasoning" trigger on the Summary Rail. The response is generated in the viewer's own
session locale — English or Arabic, matching the company's configured locale — and the frontend renders it
as plain, already-localized prose inside a `Sheet`; it is never machine-translated client-side, consistent
with `FRONTEND_ARCHITECTURE.md → AI Integration Layer`'s identical rule for the "Ask AI" chat surface. A
worked response:

```json
{
  "success": true,
  "data": {
    "journal_entry_id": 88231,
    "agent_code": "GENERAL_ACCOUNTANT",
    "confidence": 0.92,
    "reasoning": "No rent accrual had been posted for July by the 16th of the month, but this company has posted an identical KWD 850.000 accrual to account 5310 on or before the 5th of every month for the last 11 consecutive months. This entry proposes the same accrual, dated for the current open period.",
    "sources": [
      { "type": "journal_entries", "count": 11, "label": "Prior monthly rent accruals (Aug 2025–Jun 2026)" }
    ]
  },
  "message": "Explanation generated.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3f4e5d6c-7b8a-49c0-8d1e-2f3a4b5c6d7e",
  "timestamp": "2026-07-16T09:30:11Z"
}
```

**Confidence display.** `journal_entries.ai_confidence` and `journal_lines.ai_confidence` are both already
stored as a `0.0000–1.0000` fraction (`docs/accounting/JOURNAL_ENTRIES.md → Journal Header`/`→ Journal
Lines`), so every `ConfidenceBadge` on this screen calls `normalizeConfidence(entry.ai_confidence,
'fraction')` — never `'percentage'`, which is reserved for the AI Command Center's separately-scaled
`ai_decisions.confidence_score` (0–100). Passing the wrong mode would silently render a 92%-confident entry
as "1% confidence," so this is called out explicitly rather than left to a component author's assumption.

**Confidence-gated auto-post whitelist.** A narrow, CFO-approved, per-company policy lets a tightly-scoped
recurring pattern (a known bank fee under a small fixed amount, matched against a specific account pair, at
`ai_confidence ≥ 0.98`) post without any human approval step at all. On this screen, an entry created this
way is indistinguishable in its data shape from any other automatic entry — `status: 'posted'` immediately,
`ai_generated: true`, `entry_type: 'ai_generated'` — and its only distinguishing UI treatment is the
Summary Rail's "Posted automatically under company policy" line in place of a human "Posted by" name
(`# Layout & Regions`). There is no separate badge for "this one skipped approval," because the audit trail
(`journal_entry_history`, `audit_logs`) already carries the full, permanent record of the policy that
authorized it — repeating that fact prominently on every such row would be noise, not transparency.

# States

| State | Trigger | Rendering |
|---|---|---|
| Loading (first paint, no cache) | Initial navigation, no hydrated data | List: `Skeleton` rows shaped like the table; Detail: header + rail skeleton |
| Empty (no entries at all) | Brand-new company, zero `journal_entries` rows | `EmptyState`: "No journal entries yet," CTA "New Journal Entry" gated on `.create` |
| Empty (filtered to nothing) | Filters legitimately produce zero rows | Lighter `EmptyState` variant, "No entries match your filters," never conflated with the true-empty case |
| Draft (editable) | `status: 'draft'` | `JournalEntryForm` in edit mode; balance banner reflects current line totals |
| Draft, out of balance | Client-computed `Σdebit ≠ Σcredit` | Balance banner `danger`-toned; primary action disabled with inline reason; Save Draft remains enabled |
| Pending approval — viewer is the current approver | `status: 'pending_approval'`, viewer eligible for the open level | `ApprovalCard` fully interactive (Approve/Reject/Delegate) |
| Pending approval — viewer is not the current approver | Same status, viewer not eligible right now | `ApprovalCard` renders read-only (amount, requester, confidence, no action row), or the current level is shown as a locked, greyed preview naming the required role |
| Approved (transient) | `status: 'approved'` | Rare to observe directly — the response usually already reflects the synchronous post; if seen, renders as "Approved — posting…" |
| Rejected | `status: 'rejected'` | Read-only `JournalEntryDetail` with the rejection reason surfaced prominently; Edit is available (returns it to `draft` on save) to a holder of `.update` |
| Posted | `status: 'posted'` | Fully read-only `JournalEntryDetail`; Reverse and Archive are the only lifecycle actions offered |
| Reversed | `status: 'reversed'` | Read-only, with a persistent cross-link to the reversing entry |
| Voided | `status: 'voided'` | Read-only, visually identical to Reversed but labeled distinctly (same-period cancellation) |
| Archived | `status: 'archived'` | Read-only, with an "Unarchive" affordance; excluded from the List's default filter but reachable via Status |
| Duplicate suspected (inline) | `meta.warnings[].code === 'DUPLICATE_SUSPECTED'` on an otherwise-successful save | Dismissible banner in the Composer, never a blocking modal (`# Interactions & Flows`) |
| Version conflict | `409` on a `draft`/`rejected` save | Toast: "This entry was modified by someone else — reload and retry"; unsaved local edits are preserved in the form until the user chooses to reload |
| Period-locked at post time | `409` from the Posting Engine's re-check, even if the period was open at submit time | Inline error naming the locked period, with the `accounting.period.reopen` informational link (`# Route & Access`) |
| Bulk import result | Response from `POST .../bulk-import` | Results summary dialog: created count, failed count, per-row failure list with codes |
| Session permission revoked mid-view | A subsequent mutation attempt returns `403` after the permission set changes | The affected control collapses to its disabled-with-tooltip form; no stale enabled control is trusted from the page's initial load |
| Error | `403` / `404` / `5xx` / network failure | Shared `403` boundary, `not-found.tsx`, or `ErrorState` with retry, per which failure occurred (`# Route & Access`) |

# Responsive Behavior

**List, Mobile (`base`–`sm`).** Follows `RESPONSIVE_DESIGN.md → Adaptive Patterns → Pattern 1/2`: rows
become cards, each headed by the entry number and `StatusPill`, with a two-column definition list of the
`priority: 1–2` fields (date, reference, and a single signed Amount figure via `AmountCell mode="signed"`
rather than the desktop's two separate Debit/Credit columns — on a card, one clearly-signed figure reads
faster than two side-by-side numbers, and the desktop rationale for keeping both columns visible, spotting
an imbalance at a glance across many rows, does not apply to a single card the user is already about to open
anyway). Filters collapse behind a single badge-counted "Filters" trigger opening a `Sheet`; the density
toggle and column-visibility menu are hidden entirely (density is a wide-table concern with no card
equivalent).

**Composer, Mobile (`base`–`sm`).** `JournalLineGrid`'s dense grid rows are not rendered at all below `sm:` —
per `RESPONSIVE_DESIGN.md → Forms On Mobile → Multi-line editors`, each line instead renders as a stacked
card: `AccountPicker` full-width, a two-column `Debit`/`Credit` money-field pair beneath it, then a
full-width, `min-h-11` "Remove line" button. This is a deliberate, total departure from the ARIA `grid`
pattern on this breakpoint, not a shrunken version of it — arrow-key cell navigation has no purpose on a
touch keyboard where each field is already a full-width tap target, so the roving-tabindex machinery simply
does not mount below `sm:`, and `ACCESSIBILITY.md`'s own "never apply the grid pattern where the interaction
never needed cell-level navigation" caution applies here in the opposite direction from where it is usually
invoked: the mobile tier is exactly the case where the *desktop* pattern would be the wrong default. The
Live Preview Rail collapses into an inline panel directly above the sticky submit bar; the sticky
Cancel/Save Draft/Post bar sits `sticky bottom-0` above the safe area, and a failed submit auto-scrolls to
the first invalid field (`RESPONSIVE_DESIGN.md → Sticky submission and error handling`).

**Composer, Tablet (`md`–`lg`).** A two-column header grid; `JournalLineGrid` renders as the dense inline
grid row (`grid-cols-[2fr_1fr_1fr_1fr_2.5rem]`) from `lg:` up, matching `RESPONSIVE_DESIGN.md`'s own
`JournalLineRow` breakpoint boundary exactly; the Live Preview Rail is still an inline panel below `xl:`
rather than a true side rail, since a true 3–4/12 rail would crowd a tablet-width grid.

**Composer, Desktop (`xl:`+).** The full three-region Form Page Template: Form Body + a genuine Live Preview
Rail beside it, the ARIA `grid` line editor with full keyboard roving-tabindex navigation, and
`LineDimensionsPopover` triggers visible inline per row.

**Detail, all tiers.** The Summary Rail moves above the Tab/Segment Nav on Mobile/Tablet (key facts read
before the detail tabs, matching `LAYOUT_SYSTEM.md`'s Detail Page Template reflow) and returns to a true
4/12 rail from `lg:` up. `JournalLinesTable`'s plain `<table>` uses the same sticky-first-column,
horizontal-scroll treatment `RESPONSIVE_DESIGN.md → Finance Tables On Small Screens` specifies for any
tabular grid that does not decompose into a card (a posted entry's lines are read-only and homogeneous
enough that a card transformation would add friction, not clarity, for a two-to-five-line entry — the List
is the surface with genuinely varied per-row content that benefits from cards, not the Lines tab).

**Virtualization.** Both the List's `DataTable` and a Composer/Detail's line collection virtualize past
roughly 200 rows via `@tanstack/react-virtual`, with the estimated row height read from the active density
token (List) or the active breakpoint tier (Composer), per `RESPONSIVE_DESIGN.md → Virtualization at scale`
— a company posting a large multi-hundred-line consolidating entry is realistic (e.g. a payroll run's
per-employee breakdown) and must not degrade scroll performance.

**Touch targets.** Every interactive element — grid cell triggers, row-action icons, the mobile card's
Remove-line button — meets the platform's 44×44px minimum regardless of the active table density
(`LAYOUT_SYSTEM.md → Density Modes`), via hit-slop expansion on dense desktop rows rather than growing the
row itself.

# RTL & Localization

Every string on this screen ships as an EN/AR pair through `next-intl`, and the screen inherits
`THEMING.md`'s RTL-as-a-theming-dimension and `LAYOUT_SYSTEM.md → RTL Layout Mirroring` without a single
per-component RTL branch, plus several module-specific applications of those rules:

- **Bilingual accounts and dimensions.** `AccountPicker`, `LineDimensionsPopover`'s cost-center/project/
  department options, and `JournalLinesTable` all render `name_ar` when the active locale is `ar` and fall
  back to `name_en` only if the Arabic name is genuinely absent — never the reverse, and never a mixed-
  language row where the account code stays Latin (always LTR-isolated) but the name silently stays
  English under an Arabic session.
- **Debit/Credit column order is physically fixed, never mirrored** (`LAYOUT_SYSTEM.md → RTL Layout
  Mirroring → Rule 2, Exception B`), on both the List and `JournalLinesTable`, because Debit-before-Credit
  left-to-right is the universal accounting convention independent of interface language and must match
  printed/exported statements:

  ```tsx
  <TableCell dir="ltr" className="text-end tabular-nums">{formatAmount(line.debit, currencyCode)}</TableCell>
  <TableCell dir="ltr" className="text-end tabular-nums">{formatAmount(line.credit, currencyCode)}</TableCell>
  ```

- **Numeric alignment is physically fixed** (`Exception A`): every money column and every `JournalLineGrid`
  amount cell is `text-end` in both directions — never re-derived from `text-right`, so a bilingual Finance
  team scanning magnitude by decimal alignment sees amounts land on the same edge regardless of session
  language, exactly as `AmountCell`'s own `align="end"` default already guarantees.
- **Account codes, journal numbers, dates, and confidence percentages stay LTR-embedded** inside Arabic
  sentences (the duplicate-suspected banner's narrative text, the AI explanation `Sheet`'s prose) via the
  shared `Bidi`/`LtrInline` wrapper (`unicode-bidi: isolate` + `dir="ltr"`), so "JE-2026-000512" or "92%"
  never reorders inside a right-to-left paragraph.
- **AI reasoning is generated in the viewer's session locale**, not machine-translated client-side (`# AI
  Integration`), and Arabic accounting terminology is used precisely throughout this screen and its
  translations: قيد (journal entry), مسودة (draft), معلق الموافقة (pending approval), مرحّل (posted), مدين
  (debit), دائن (credit), ترحيل (posting), عكس القيد (reverse the entry), إلغاء (void), أرشفة (archive).
- **Numerals stay Western Arabic digits** (`numberingSystem: "latn"`) even under `ar`, matching
  `AmountCell`'s own `formatAmount` implementation and the Gulf financial-document convention that a mixed
  numeral system inside a single ledger row reads as an error to an accountant.
- **The roving-tabindex grid's arrow-key mapping is expressed in DOM row/column indices, not visual
  ones**, per `ACCESSIBILITY.md → Data Tables Accessibility`'s own statement about this exact screen: the
  grid's DOM order does not change per locale, only its visual presentation, so `←`/`→` continue to move
  between the semantically-correct neighboring cells under `dir="rtl"` without `useRovingGrid` needing any
  RTL-specific branch at all.
- **Directional chrome mirrors; content chrome does not.** The AI reasoning and mobile row-detail `Sheet`s
  slide from the logical end edge (left in RTL); breadcrumb chevrons and the Detail page's back-link arrow
  mirror via `rtl-flip`; the `NotebookPen` module icon and every status/severity glyph do not, per
  `ICONOGRAPHY.md`'s directional-icon table.

# Dark Mode

Dark mode is a token remap, never a second implementation, per `DARK_MODE.md`. Nothing on this screen
references a raw hex value or a bare Tailwind palette utility outside the shared component files it already
composes.

- **Surfaces and elevation.** The List and Detail canvases sit on `--surface-canvas`; every `Card` section
  in the Composer (Header Info, Lines, Attachments) and the Detail's Summary Rail sit on `--surface-base`;
  the AI reasoning `Sheet`, the Reverse/Reject/Import `Dialog`s, and `AccountPicker`'s `Popover` sit on
  `--surface-overlay`. In dark mode, elevation is communicated by the surface stepping *lighter* toward the
  viewer (canvas → base → raised → overlay), and every one of those containers carries its paired border
  token (`--border-subtle` at minimum) even where its light-mode equivalent relies on shadow alone — a
  borderless dark `Card` disappears against the near-black canvas, which is the single most common
  dark-mode regression this document's siblings guard against.
- **Status tones.** `StatusPill`'s `domain="journal_entry"` lookup table is fixed platform-wide
  (`COMPONENT_LIBRARY.md → StatusPill`) and this screen does not re-derive it: `draft` → neutral,
  `pending_approval` → warning, `approved` → accent, `rejected` → danger, `posted` → success, `reversed` →
  neutral, `voided` → danger, `archived` → neutral. Each tone resolves through `DARK_MODE.md`'s own
  semantic tokens (`--status-success`/`--status-warning`/`--status-error` plus the reserved `--accent` for
  the one genuinely interactive-adjacent state, `approved`, which is a transitional queued-for-posting
  state rather than a static badge) and is independently contrast-checked in both themes — a pairing is
  never assumed to pass in dark mode because its light-mode counterpart passed.
- **Debit and Credit figures are never status-colored.** Every amount rendered through `AmountCell` on this
  screen keeps its ink/text-primary tone in both themes; only `StatusPill`, `ConfidenceBadge`, and the
  balance banner's own success/danger state carry a semantic status color — a debit is not "good" and a
  credit is not "bad," and the UI never implies otherwise through color alone.
- **AI provenance uses one reserved hue, never a finance-status hue.** The `Sparkles` glyph on an
  `ai_generated` row, the AI-insight `Card` variant's accent border on the "AI suggests template" card, and
  every `ConfidenceBadge` fill use `--ai-accent`/`--ai-accent-subtle` exclusively, in both themes — never
  `--status-success`/`--status-warning`/`--status-error`, because a high confidence score and a good
  financial outcome are two independent facts the API returns as two independent fields
  (`ai_confidence` and `status`), and collapsing them into one color would imply a correlation the data
  does not assert. A Fraud-Detection-escalated `ApprovalCard` therefore shows **both** signals side by
  side: the card's warning-tone note in `--status-warning`, and its `ConfidenceBadge` in `--ai-accent`.
- **The dimension-required warning dot** on a `LineDimensionsPopover` trigger uses `--status-warning`, not
  `--status-error` — an unset required dimension blocks *submission*, not the ability to keep typing, and
  the visual weight matches that distinction in both themes.
- **Contrast.** Every pairing above is independently verified at ≥4.5:1 (text) / ≥3:1 (non-text, borders,
  focus rings) in both themes per `DARK_MODE.md → Color & Contrast In Dark`.
- **Exports render light, always.** A PDF/XLSX export of a journal entry or a filtered list — regardless of
  the exporting user's active theme — renders in QAYD's fixed light/print palette (`DARK_MODE.md → Exported
  PDFs always render light`), because a journal entry is routinely forwarded to an auditor who has never
  opened the dark-mode app at all.

# Accessibility

Baseline is WCAG 2.2 AA per `ACCESSIBILITY.md`, which this screen implements without deviation, plus the two
component-specific patterns that document names explicitly for this exact surface.

- **Two different table patterns, used deliberately, never interchangeably.** The List and
  `JournalLinesTable` use the plain, accessible `<table>` pattern — real `scope="col"` headers, sortable
  headers as real `<button>`s with `aria-sort` on the parent `<th>`, a horizontally-scrolling wrapper that
  is itself `role="region"` + `tabIndex={0}` + a real `aria-label`, and a uniquely-worded `aria-label` on
  every row action ("Approve journal entry JE-2026-00184," never a bare "Approve"). `JournalLineGrid` alone
  uses the ARIA `grid` pattern — `role="grid"`/`role="row"`/`role="gridcell"`, `aria-rowcount`/
  `aria-colcount`, and a roving `tabindex` where exactly one cell holds `0` at any moment — because
  `ACCESSIBILITY.md → Data Tables Accessibility` names the Journal Entry line editor by name as one of only
  two surfaces in the entire platform that warrant it. Applying the grid pattern to the List or to
  `JournalLinesTable` "to be safe" would be the documented anti-pattern that document specifically warns
  against, since neither of those tables needs cell-level arrow-key navigation.
- **The balance banner is a live region proportional to its severity.** `role="status" aria-live="polite"`
  as it moves from "Out of balance" to "Balanced ✓" while typing (a confirmation, not an interruption); a
  server-side rejection at submit (a genuine `422`) escalates to `role="alert"` (assertive), matching
  `ACCESSIBILITY.md`'s live-region tiers.
- **Debit/credit and confidence are never color-only.** Every `AmountCell` on this screen carries the
  platform's triple redundancy — a leading glyph/sign, a directional cue, and a `VisuallyHidden` text
  equivalent ("Debit of KWD 850.000") — and every `ConfidenceBadge` renders its percentage as a real text
  node beside the visual pill, never inferable only from a conic-gradient ring's `style` attribute.
- **RBAC-disabled controls explain themselves, and business-rule-disabled controls explain themselves
  differently.** A Post button disabled because the viewer lacks `accounting.journal.post` carries
  `aria-describedby` naming the exact missing permission ("Requires accounting.journal.post"); the same
  button disabled because the entry is out of balance carries a different, specific `aria-describedby`
  ("This entry is off balance by KWD 0.500 — add a rounding line before posting"). The two are never
  collapsed into one generic "You can't do this right now," per `ACCESSIBILITY.md`'s explicit rule that
  conflating them is a P1 defect.
- **A sensitive action with no permission is omitted, not disabled.** Reverse, Delete, Archive, Import, and
  Export are removed from the DOM entirely for a role lacking the corresponding permission — never rendered
  disabled with no explanation — exactly matching `NAVIGATION_SYSTEM.md → Command Palette`'s identical
  `actions.filter(hasPermission)` precedent, because a disabled control with no visible content behind it
  leaves a screen-reader user unable to tell whether the feature exists at all.
- **Focus management on overlays.** The AI reasoning `Sheet`'s `onOpenAutoFocus` moves focus to its heading,
  not its close button; closing any `Dialog`/`Sheet`/`AlertDialog` on this screen (Escape, backdrop click,
  or a successful confirm) returns focus to the control that opened it. `JournalLineGrid`'s per-line
  `LineDimensionsPopover`, when it auto-opens because a selected account requires a dimension, moves focus
  to its first field rather than leaving focus stranded on the `AccountPicker` that triggered it.
- **Keyboard.** `N` (scoped to this route, `# Route & Access`) opens the Composer; inside `JournalLineGrid`,
  arrow keys move the active grid cell and `Tab` exits the grid entirely to the next real control outside
  it, never requiring repeated presses to "escape" a 40-line entry. Every `Dialog`/`AlertDialog`'s primary
  action responds to `Cmd`/`Ctrl+Enter`.
- **Realtime updates never yank focus or splice silently.** A `.journal.posted` push affecting a row the
  Auditor is currently reading updates that row's cached data (`# Data & State`) without reordering the
  list or moving keyboard focus, per the platform-wide realtime-accessibility rule `# Edge Cases` restates
  for this screen specifically.

# Performance

- **Server-paginated, never client-sorted or client-filtered.** The List's `DataTable` issues a new request
  for every sort, filter, or page change (`COMPONENT_LIBRARY.md → DataTable`) — a company with tens of
  thousands of journal entries never ships more than one page's worth of rows to the browser at a time.
- **Virtualization past ~200 rows**, on both the List and any line collection large enough to need it
  (`# Responsive Behavior`), using `@tanstack/react-virtual` with a density- or breakpoint-aware row-height
  estimate so switching density or resizing the viewport never desyncs scroll position.
- **Cache tuning matches data volatility**, per `FRONTEND_ARCHITECTURE.md → Cache tuning by data class`:
  the List's `staleTime` is 30 seconds (transactional-list tier); reference data feeding `AccountPicker`,
  `LineDimensionsPopover`, and the Source filter groups is 5 minutes (rarely-changing reference tier); a
  single open Detail record additionally receives surgical realtime patches (`# Data & State`) so it is
  never meaningfully stale between polls regardless of the nominal `staleTime`.
- **Debounced, cancelled account search.** `AccountPicker`'s query debounces 250ms
  (`COMPONENT_LIBRARY.md → AccountPicker`) and is only `enabled` while its `Popover` is open, so a Chart of
  Accounts with several hundred rows never fires a request per keystroke nor fetches speculatively before
  the user has opened the picker at all.
- **Idempotent, single-attempt mutations.** Every write endpoint this screen calls (`create`, `submit`,
  `post`, `approve`, `reject`, `reverse`, `bulk-import`) carries a client-generated `Idempotency-Key` scoped
  to one logical submission attempt (`# Data & State → Mutations`), so a slow response on a flaky
  connection followed by an impatient second tap can never double-post, double-approve, or double-reverse
  an entry.
- **Async, non-blocking export and import.** `GET /journal-entries/export` fires an async `report_runs` job
  rather than holding a request open; the frontend never blocks the UI on either operation, subscribing
  instead to the job's own completion signal and surfacing a toast with a download link when ready.
- **Deferred, code-split overlays.** The Import `Dialog`, the AI reasoning `Sheet`, and the duplicate-
  suspected candidate preview are dynamically imported (`next/dynamic`) rather than bundled into the
  Composer's or List's initial route chunk, since none of the three is needed for first paint.
- **SSR-seeded first paint.** Both the List's first page and a Detail route's initial fetch happen
  server-side (`FRONTEND_ARCHITECTURE.md → Rendering Strategy`) and hydrate straight into the TanStack Query
  cache, so the client's first `useQuery` for the same keys resolves instantly rather than re-issuing a
  request the server already made.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| Multi-currency rounding leaves a sub-cent difference at posting | Post's `422` response names the exact tolerance and difference; the Composer surfaces a specific prompt to add a plug line against the company's configured Rounding Gain/Loss account rather than a generic balance error |
| Two users edit the same `draft` concurrently | `409` toast with "reload and retry"; the second writer's in-progress local edits are not silently discarded — they remain visible until the user chooses to reload |
| A draft sits until its dated period becomes `locked` | Posting fails at post time with a period-lock `409` even though it passed validation at submit time; the error names the exact locked period and offers the `accounting.period.reopen` informational link or a "re-date into the current open period" action |
| A partial reversal is requested (only some lines were wrong) | Not offered — Reverse is always whole-entry; the Dialog's own copy explains that a corrected replacement entry should be drafted fresh, matching the backend's "correction via new balanced entry" invariant |
| Approver is the same person as the creator (single-user company exception) | The Approve button is disabled with a specific `aria-describedby` reason distinct from a plain permission-denied message ("You created this entry — self-approval requires the documented single-user exception"), and every such approval renders a small, permanent "self-approved under exception" note on the Detail page once it occurs |
| AI proposes a duplicate of an already-posted entry | The duplicate-suspected banner's "View entry" link opens the posted original directly; submitting anyway is not blocked, and both the flag and the human's override are retained in `audit_logs`, visible later on the History tab |
| A recurring template's formula-based line cannot resolve | If the Composer was opened from that template (rather than the background job's own independent generation), an inline banner explains the template was paused and why, rather than silently producing a broken or empty draft |
| Exchange rate unavailable for the entry's currency/date | The currency field surfaces `FX_RATE_UNAVAILABLE` inline; Save Draft still succeeds (the backend allows an indefinite draft), but Submit/Post are blocked with a reason, and a Finance Manager sees an additional "override with a manual rate" affordance others do not |
| Company switch mid-Composer | Per the platform's company-switch contract, switching discards all cached Journal Entries state; if the Composer has unsaved changes, the switcher's own confirmation dialog names the in-progress draft specifically before proceeding, rather than a generic "unsaved changes" warning |
| Browser back/forward or a hard navigation away from a dirty Composer | A `beforeunload`/Next.js navigation guard prompts to discard, matching Cancel's own confirm-if-dirty behavior (`# Layout & Regions`) — a user never loses an in-progress entry to an accidental back-swipe with no warning |
| Session permission revoked mid-view | The next mutation attempt's `403` collapses the affected control to its disabled-with-tooltip form and shows a toast explaining why, rather than a stuck spinner or a silent no-op; the page never trusts its own initial-load permission snapshot as still valid |
| Realtime push arrives while a user is actively scrolling or reading the List | Held aside behind a dismissible "New activity — Refresh" banner rather than spliced into the visible rows, per the platform-wide non-disruptive-realtime-list pattern; the cached page itself is only marked stale, never silently replaced under the user's scroll position |
| Reconnecting after an extended disconnected period | Every realtime-fed query key for this screen is invalidated once on reconnect (WebSocket frames broadcast while disconnected are permanently missed, per `FRONTEND_ARCHITECTURE.md → Realtime`), so a stale cache is never silently trusted as current |
| A very long-running entry (40+ lines) | `JournalLineGrid` virtualizes past ~200 lines exactly like the List (`# Performance`); below that threshold, all lines render plainly since virtualizing a small, actively-being-typed-into form would add complexity with no benefit |
| Printing directly via the browser rather than Export | Not specially handled — users are steered toward the Export menu instead, the only path that produces a durable, re-downloadable, correctly light-themed record, per `DARK_MODE.md`'s exported-PDFs-always-render-light rule |
| A `posted_by` value that resolves to a system service account, not a human user | The Summary Rail renders "Posted automatically under company policy" rather than a blank field, a raw internal id, or a broken avatar (`# Layout & Regions`, `# AI Integration`) |
| An account referenced by a posted line is later deactivated | The posted entry and every historical view of it render entirely unaffected — `JournalLinesTable` shows the account exactly as it was at posting time; only the *live* `AccountPicker` used for new lines excludes the now-inactive account going forward |

# End of Document
