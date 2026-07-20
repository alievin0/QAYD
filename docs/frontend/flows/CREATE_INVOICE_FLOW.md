# Create Invoice Flow — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Flows / CREATE_INVOICE
---

# Purpose

This document specifies the end-to-end journey of **creating and issuing a sales invoice** — the order-to-cash
entry point where a revenue event first becomes a ledger fact. It follows a user from an empty invoice
Builder (or an AI-drafted one) through customer and line entry, backend-authoritative totals and tax,
save-draft versus post, PDF generation, sending to the customer, and the balanced journal entry that posting
produces — into the invoice's status lifecycle. It is a *flow* document spanning the Invoices Builder and
detail screens, the customer/product pickers, and the Journal Entries surface the posted entry lands in; it
does not re-specify those, it links out. The invoice screens are owned by [`../INVOICES.md`](../INVOICES.md)
and the Sales hub by [`../SALES.md`](../SALES.md); the full order-to-cash choreography and the AI/segregation
rules are owned by [`../../ai/workflows/ORDER_TO_CASH.md`](../../ai/workflows/ORDER_TO_CASH.md); the resulting
journal entry is owned by [`../JOURNAL_ENTRIES.md`](../JOURNAL_ENTRIES.md). Every endpoint, permission key,
and status enum named here is restated from those and from the authoritative `docs/accounting/SALES.md`
backend spec only to make the journey legible; a contradiction with any of them is a defect to resolve in
review, never a code decision.

The single most important property of this flow is that **the frontend computes no financial truth.** The
Builder shows a running subtotal/discount/tax/total in a Live Totals Rail, but that figure is a fast,
friendly, purely cosmetic preview — the authoritative computation happens when the API creates the invoice
and, again, when it is posted; the server's own `chk_invoice_totals` constraint
(`SUM(line_total) + tax_amount == total_amount`) is the only arithmetic that can persist. The second property
is that **posting is a deliberate, human, money-moving act**: `draft` is the only mutable state, `Post` is a
confirming action gated on a permission most Sales roles do not hold, and issuing an invoice from an AI draft
is *never* one motion — the AI can draft, a human must post.

# Actors & Preconditions

| Actor | Role in this flow |
|---|---|
| Sales Employee / Sales Manager | Can build and save a `draft` invoice and accept an AI `invoice_draft` (`sales.invoice.create` + `sales.ai.approve_draft`) but typically **cannot post** — the create/post split is the hinge between the Sales and Finance audiences. |
| Accountant / Senior Accountant / Finance Manager | Build, save, **and post** (`sales.invoice.post`); issue credit notes and refunds (Finance Manager/CFO). |
| Owner / CEO / CFO | Everything, including post, void, credit note, refund. |
| Auditor / Read Only | View invoices and preview PDFs read-only; every mutating control omitted. |
| AI service account (Sales Agent, General Accountant, Fraud Detection, Approval Assistant) | Draft the invoice, sanity-check account mapping, score fraud, and route approvals — but hold **none** of `sales.invoice.create`, `sales.invoice.post`, `sales.order.confirm`, `sales.receipt.create`, `sales.credit_note.post`, or `sales.refund.create`. Creating *and* posting from AI is forbidden regardless of confidence. |

**Preconditions.** The user holds `sales.read` (to reach the module) plus `sales.invoice.create` (to build).
A customer either exists or is created inline (`accounting.customer.create`). At least one sellable product
exists (`products.read`) unless the invoice uses fully manual lines. Tax codes are resolvable for the
document's date (`accounting.read`). An open fiscal period exists for the invoice date. Posting additionally
requires `sales.invoice.post`.

# Entry Points

| Entry point | Screen / control | Gate |
|---|---|---|
| **"New Invoice"** button / `N` shortcut | [`../INVOICES.md`](../INVOICES.md) Invoices list, [`../screens/SALES_SCREEN.md`](../screens/SALES_SCREEN.md) hub | `sales.invoice.create` |
| **Sales Quick-Create menu** | Sales hub `SalesQuickCreateMenu` | `sales.invoice.create` |
| **Accept an AI `invoice_draft`** | Sales AI rail / an `ApprovalCard kind="ai_recommendation"` | `sales.ai.approve_draft` **and** `sales.invoice.create` |
| **Convert from a confirmed order** | Sales order detail "Create invoice" | `sales.invoice.create` |
| **Edit an existing draft** | An invoice row with `status="draft"` | `sales.invoice.create` (draft-only edit; no `/edit` route) |

The AI-accept and order-convert entry points open the *same* Builder, pre-filled — they materialize only a
`draft` a human must still separately review and post.

# Flow Overview

```mermaid
flowchart TD
  A[New Invoice / Accept AI draft] --> B[Builder: customer]
  B --> C{Customer exists?}
  C -->|no| C1[Create customer inline]
  C -->|yes| D[Line items: product, qty, price, discount, tax]
  C1 --> D
  D --> E[Live Totals Rail<br/>cosmetic preview only]
  E --> F{Save draft or Post?}
  F -->|Save draft| G[POST /sales/invoices → draft]
  G --> H[Editable draft]
  H --> F
  F -->|Post| I[Confirm AlertDialog]
  I --> J[POST /sales/invoices/{id}/post]
  J --> K[Server validates totals<br/>Posting Engine → journal entry]
  K --> L[status posted<br/>invoice.posted event]
  L --> M[Preview PDF]
  M --> N{Send?}
  N -->|yes| O[Confirm recipient → POST /send]
  O --> P[last_sent_at stamped]
  L --> Q[Summary Rail: journal_entry_id links]
```

# Step-by-Step

Every money-affecting `POST` carries an `Idempotency-Key` header generated at form-open and stored in
`sessionStorage` keyed to the `formInstanceId`, per [`../../ai/workflows/ORDER_TO_CASH.md`](../../ai/workflows/ORDER_TO_CASH.md).

### Step 1 — Open the Builder

- **Screen / route:** `app/(app)/sales/invoices/new/page.tsx`, the `InvoiceForm` Builder (Form Page Template
  — header actions, sectioned body, Live Totals Rail, sticky footer). Editing a draft is the same `InvoiceForm`
  in `mode="edit"` at `[invoiceId]/page.tsx`, chosen by the server-known `status="draft"`.
- **User action:** starts a blank invoice, or arrives with an AI/order pre-fill.
- **UI state:** header info (date, period via `PeriodPicker`, reference), a customer field, an empty line
  table, and the Live Totals Rail reading zeros.
- **API call:** none yet; reference reads (fiscal periods) warm the pickers.
- **Success branch:** Step 2.

### Step 2 — Select or create the customer

- **User action:** picks the customer via `CustomerPicker` (a `Command`+`Popover` combobox modeled on
  `AccountPicker`), or clicks "Create customer" to open the inline customer-create handoff
  ([`../CUSTOMERS.md`](../CUSTOMERS.md)).
- **UI state:** selecting a customer auto-fills `currency_code` and a default `due_date` and, where enabled,
  shows a credit badge. A `credit_precheck_warning` from the Sales Agent may render if outstanding AR is near
  the limit.
- **API call:** `GET /api/v1/accounting/customers?q=&filter[status]=active&per_page=50&sort=name_en`
  (`accounting.customer.read`); inline create is `POST /api/v1/accounting/customers`
  (`accounting.customer.create`).
- **Success branch:** Step 3.
- **Failure branch:** a create validation error maps onto the customer sub-form; the Builder stays open.

### Step 3 — Add line items

- **User action:** adds rows in `InvoiceItemsTable` — per line a product (`ProductPicker`), quantity,
  unit price, discount, and tax. `ProductPicker` returns the resolved `unit_price` from the customer's price
  list plus `cogs_unit_cost`.
- **UI state:** the line editor is a **plain accessible `<table>`, deliberately not the ARIA `grid` /
  `JournalLineGrid` pattern** — a sales user is not an accountant driving a debit/credit grid. `discount_amount`
  is a plain amount (a percentage entry is a client convenience converted to an amount before submit);
  `tax_rate_snapshot` is the frozen rate — read-only for order-derived lines, editable only on fully manual
  ones. Per-line preview math: `lineSubtotal = qty × unit_price − discount_amount`;
  `lineTax = lineSubtotal × (tax_rate_snapshot/100)`.
- **API call:** product/tax reads only: `GET /api/v1/products` (`products.read`),
  `GET /api/v1/accounting/tax-codes` (`accounting.read`).
- **Success branch:** Step 4.
- **Failure branch:** a line missing a resolvable tax code fails validation on save (`422`, the specific line
  flagged), never silently.

### Step 4 — Read the Live Totals Rail (preview, not truth)

- **User action:** none — reviews the running subtotal, discount, tax, and total.
- **UI state:** the rail mirrors the server's invariant client-side for instant feedback; it is explicitly
  cosmetic. **There is no recalc/preview endpoint** — recalculation is client-side arithmetic only; the
  authoritative computation happens on create and on post.
- **API call:** none.
- **Success branch:** Step 5.

### Step 5 — Save draft or post

**5a — Save draft.**
- **User action:** clicks "Save Draft."
- **API call:** `POST /api/v1/sales/invoices` (`sales.invoice.create`) — or `PATCH .../invoices/{id}`
  (draft-only) when editing.
- **UI state:** an optimistic-friendly create is acceptable here (a draft moves no money); a returned
  `meta.warnings` `DUPLICATE_SUSPECTED` (with `confidence` and `candidate_ids`) surfaces as a non-blocking
  advisory — the save still succeeded.
- **Success:** invoice created `status: 'draft'`, editable in place; back to Step 3 or on to Step 5b.
- **Failure:** `422` maps field errors onto the Builder (including nested `items.n.*` paths); the Builder
  keeps the user's input.

**5b — Post (issue).**
- **User action:** clicks the primary **Post** action.
- **UI state:** a confirming `AlertDialog` — posting is money-moving and **pessimistic** (no `onMutate`); the
  UI shows `posted` only after the server's `2xx`. The dialog names the total and customer before its confirm
  arms.
- **API call:** `POST /api/v1/sales/invoices/{id}/post` (`sales.invoice.post`). The server validates the
  invoice balances, then the Accounting Engine's Posting Engine creates the balanced journal entry.
- **Success:** `status: 'posted'`; `invoice.posted` fires, patching status and `journal_entry_id`. Proceed to
  Step 6 / 7.
- **Failure:** a credit or tax precondition returns a `409`/`422` (e.g. `CREDIT_LIMIT_EXCEEDED`, or a line
  whose tax code no longer resolves) — surfaced inline; the invoice stays `draft`. A posted invoice can
  **never** be voided in place; only `draft → void` (`POST .../void`, reason required) exists, and a posted
  invoice is corrected only by a credit note.

### Step 6 — Generate and preview the PDF

- **User action:** clicks "Preview PDF."
- **UI state:** a `Sheet` shows a generation skeleton, then the document; the PDF renders in the fixed
  light/print palette regardless of the viewer's theme, and its **direction/language is a document property**
  (the customer's configured language) independent of the viewer's session — an Arabic-language PDF can be
  issued from an English session.
- **API call:** `GET /api/v1/sales/invoices/{id}/pdf` (`sales.invoice.read`, `enabled: previewOpen`) — returns
  the existing `pdf_attachment_id` signed URL if lines are unchanged, else regenerates synchronously for a
  typical small invoice; above a size threshold it returns `202` and generates async.
- **Success branch:** Step 7 (optional).

### Step 7 — Send to the customer

- **User action:** clicks "Send" (a separate, externally-visible act).
- **UI state:** a confirming `Dialog` reviews the recipient — defaulted from the customer's primary contact
  email, **editable** — before the send fires. This is the same category as "email a statement": an
  externally-visible send requiring explicit human recipient review, never a silent side effect. (A customer
  PDF email is *also* triggered automatically on `invoice.posted` per the module's events; the explicit "Send"
  is a deliberate re-send / on-demand send.)
- **API call:** `POST /api/v1/sales/invoices/{id}/send` (`sales.invoice.send`) — stamps `last_sent_at` and
  invalidates the detail.
- **Success branch:** `last_sent_at` shown on the invoice; the flow is complete.
- **Failure branch:** a send failure surfaces a toast and leaves the invoice posted-and-unsent, retryable.

### Step 8 — The resulting journal entry

- **Screen / route:** the invoice detail Summary Rail renders the returned `journal_entry_id`(s) as clickable
  references into `/accounting/journal-entries/{id}` ([`../JOURNAL_ENTRIES.md`](../JOURNAL_ENTRIES.md)), gated
  by that screen's own `accounting.journal.read`.
- **UI state / accounting:** the Posting Engine (not Sales) posts **Entry A** the instant `invoice.posted`
  fires: **Dr Accounts Receivable — Trade** (`total_amount`, tagged `customer_id`), **Cr Sales Revenue**
  (`subtotal_amount − discount_amount`), **Cr Output Tax Payable** (`tax_amount`). For a product invoice a
  second **Entry B — COGS relief** (Dr Cost of Goods Sold `Σ qty×cogs_unit_cost` / Cr Inventory) posts
  alongside Entry A for `billing_policy='on_order_confirmation'`, or at `delivery.shipped` for
  `on_delivery`. The Summary Rail shows Entry A always, Entry B alongside only for `on_order_confirmation`.

# Flow-Specific Guards

Two guards are specific to this flow. The first encodes the flow's core rule — **the AI can draft, only a
human posts** — by making "Accept draft" materialize a `draft` and nothing more, gated on *both* the AI
approval key and the invoice-create key, and never rendering a one-motion "issue":

```tsx
// components/sales/accept-invoice-draft-button.tsx
'use client';

import { useAcceptInvoiceDraft } from '@/hooks/sales/use-invoice-mutations';
import { usePermission } from '@/hooks/use-permission';
import { Button } from '@/components/ui/button';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { useRouter } from 'next/navigation';
import type { InvoiceDraftRecommendation } from '@/types/sales';

export function AcceptInvoiceDraftButton({ rec }: { rec: InvoiceDraftRecommendation }) {
  const router = useRouter();
  const accept = useAcceptInvoiceDraft();
  // BOTH gates required — the AI approval key never substitutes for the create key.
  const canAccept = usePermission('sales.ai.approve_draft') && usePermission('sales.invoice.create');
  if (!canAccept) return null; // omitted, not disabled

  return (
    <div className="flex items-center gap-2">
      <ConfidenceBadge confidence={normalizeConfidence(rec.confidence, 'fraction')} reasoning={rec.reasoning} />
      <Button
        size="sm"
        disabled={accept.isPending}
        onClick={async () => {
          // Materializes a *draft* under the human's session — never posts. `can_execute_directly`
          // is server-side never true for invoice_draft; there is no "Accept & issue".
          const { id } = await accept.mutateAsync(rec.id);
          router.push(`/sales/invoices/${id}`); // opens the draft Builder for review, then a separate Post
        }}
      >
        Accept draft
      </Button>
    </div>
  );
}
```

The second is the **post confirming dialog** — a money-moving, pessimistic action that names the total and
customer before its confirm arms, so issuing is always a deliberate second act:

```tsx
// components/sales/post-invoice-action.tsx — the confirming AlertDialog (abridged)
const post = usePostInvoice(); // pessimistic: no onMutate; status shows 'posted' only on 2xx
<AlertDialog>
  <AlertDialogTrigger asChild>
    <PermissionGate permission="sales.invoice.post">
      <Button>{t('post')}</Button>
    </PermissionGate>
  </AlertDialogTrigger>
  <AlertDialogContent>
    <AlertDialogTitle>{t('confirmPostTitle')}</AlertDialogTitle>
    <p><AmountCell amount={invoice.total_amount} currencyCode={invoice.currency_code} emphasis="strong" /> · {customer.name}</p>
    <AlertDialogAction onClick={() => post.mutate(invoice.id)}>{t('confirmPost')}</AlertDialogAction>
  </AlertDialogContent>
</AlertDialog>
```

`PermissionGate` omits Post entirely for a Sales user without `sales.invoice.post` — the create/post split
made concrete: they build and hand off, they do not issue.

# Happy Path

A Sales Employee accepts an AI `invoice_draft` in the Sales rail: order 9931 is fully confirmed, and the
Sales Agent's recommendation scores **0.960 ("96% confidence")** with a `reasoning` noting no amendments and a
passed account-mapping check. Accepting (under `sales.ai.approve_draft` + `sales.invoice.create`, in the
*human's* session) opens the Builder pre-filled: customer **Al-Manar Trading** (KWD), two product lines, a
subtotal of 1,748.000, a discount of 92.000, and 5% VAT of 87.400 for a total of **1,835.400** in the Live
Totals Rail. She reviews, but because she cannot post, she saves the draft and hands off. A Finance Manager
opens the draft, clicks **Post**, confirms the total-and-customer dialog; `POST .../post` validates the
balances and the Posting Engine writes Entry A (`journal_entry_id 553012`: Dr AR 1,835.40 / Cr Revenue
1,748.00 / Cr Output Tax 87.40) and Entry B (`553013`: Dr COGS 1,270.00 / Cr Inventory 1,270.00). The
invoice flips to `posted`, a PDF is generated, and the Finance Manager reviews the recipient and clicks
**Send**; `last_sent_at` is stamped. Human decisions: accept the draft, save, post-and-confirm, send-and-confirm
recipient — everything financial was the server's.

# Alternate & Error Paths

| Path | Trigger | Behavior |
|---|---|---|
| **Fully manual invoice** | No AI draft, no order | Same Builder from a blank state; manual lines allow editing `tax_rate_snapshot`. |
| **Customer over credit limit** | `409 CREDIT_LIMIT_EXCEEDED` (at order-confirm or post) | Inline error naming the exposure vs. limit; needs `sales.credit.override` to proceed, else blocked. |
| **Duplicate suspected** | `meta.warnings DUPLICATE_SUSPECTED` on save | Non-blocking advisory with `candidate_ids`; the save succeeded — the user decides whether to void. |
| **AI recommendation stale** | Underlying order/receipt changed before save | `422 AI_RECOMMENDATION_STALE`; the Builder re-fetches the draft. |
| **Stock insufficient** | `409 STOCK_INSUFFICIENT` on a stock-controlled line | Inline error; the line cannot be invoiced at that quantity. |
| **Void a draft** | `draft → void` | `POST .../void` with a required, stored reason; pure status transition, no accounting reversal (never posted). |
| **Correct a posted invoice** | Error found after posting | Never edited/voided in place — issue a `credit_notes` (`sales.credit_note.create`/`.post`), which posts its own reversing entry. |
| **Send failure** | Email delivery error | Toast; invoice stays posted-and-unsent; retry available. |
| **Idempotency collision** | Same key, different payload | `422 DUPLICATE_REQUEST`; the client regenerates the key on a genuinely new submission. |

# Data & State

## Endpoints across the flow

| Purpose | Endpoint | Permission | Mutation? |
|---|---|---|---|
| List / read invoices | `GET /api/v1/sales/invoices`, `.../{id}` | `sales.invoice.read` | no |
| Create (save draft) | `POST /api/v1/sales/invoices` | `sales.invoice.create` | yes |
| Update a draft | `PATCH /api/v1/sales/invoices/{id}` | `sales.invoice.create` | yes |
| Post (issue) | `POST /api/v1/sales/invoices/{id}/post` | `sales.invoice.post` | yes (pessimistic) |
| Void a draft | `POST /api/v1/sales/invoices/{id}/void` | `sales.invoice.void` | yes |
| Preview / generate PDF | `GET /api/v1/sales/invoices/{id}/pdf` | `sales.invoice.read` | no (may 202) |
| Send to customer | `POST /api/v1/sales/invoices/{id}/send` | `sales.invoice.send` | yes |
| Accept AI draft | `POST /api/v1/sales/invoices/{id}/accept-ai-draft` (thin wrapper over create) | `sales.ai.approve_draft` + `sales.invoice.create` | yes |
| Customer lookup / create | `GET/POST /api/v1/accounting/customers` | `accounting.customer.read` / `.create` | read / yes |
| Product / tax lookup | `GET /api/v1/products`, `GET /api/v1/accounting/tax-codes` | `products.read` / `accounting.read` | no |
| Record a payment (downstream) | `POST /api/v1/sales/receipts` | `sales.receipt.create` | yes |
| Open the resulting journal entry | `GET /api/v1/accounting/journal-entries/{id}` | `accounting.journal.read` | no |

## Mutations & invalidations

`invoiceKeys` (`all/lists/list/details/detail/activity/pdf/aging`). On create, `invoiceKeys.lists()` and the
aging summary (`GET /api/v1/sales/reports/aging`, `staleTime: 0`) invalidate. On post, `invoiceKeys.detail(id)`,
`invoiceKeys.lists()`, aging, and the customer's balance invalidate; the realtime `invoice.posted` patches the
detail's `status` and `journal_entry_id` in place. On send, only `invoiceKeys.detail(id)` invalidates
(`last_sent_at`). Posting is pessimistic; draft save may be optimistic. A payment (downstream) drives
`posted → partially_paid → paid` as `balance_due` reaches zero.

## Status lifecycle

`draft` (neutral) → `posted` (accent) → `partially_paid` (warning) → `paid` (success); `overdue` (danger, a
scheduled sweep when `due_date` passes with `balance_due > 0`); `void` (from `draft` only). **There is no
`sent`/`viewed` status on the invoice** — "sent" is the `last_sent_at` timestamp, not a status (those
lifecycle values live on the *quotation* entity). `draft` is the only editable status.

## Realtime

`company.{companyId}.accounting`/sales channels carry `invoice.posted`, `invoice.paid`, `invoice.overdue`;
each drives a targeted cache patch or invalidation rather than a blanket refetch.

# AI Touchpoints

Every AI element obeys confidence + reasoning + human-in-loop; **no AI path ever issues an invoice.**

| Agent | Touchpoint | Contract |
|---|---|---|
| Sales Agent (`sales_agent`) | Assembles the `invoice_draft` recommendation (from a confirmed order); `price_suggestion`, `credit_precheck_warning`, `stock_precheck_warning` | Suggest-only. `confidence` on `ai_recommendations` (`NUMERIC(4,3)`): `<0.500` suppressed, `0.500–0.850` an editable suggestion requiring a deliberate accept, `>0.850` a one-click Apply that still requires the click. `invoice_draft` from a confirmed order typically 0.94–0.98. |
| General Accountant | Account-mapping sanity check folded into the *same* `invoice_draft` `confidence` — never a second card | Suggest-only. |
| Fraud Detection | Scores the order/customer (`fraud_score`) | Advisory / may step up; never blocks issuance silently. |
| Approval Assistant | Routes any required approval (sole writer of `ai_approval_requests`/`ai_approval_steps`) | Routing only; decides nothing. |

Accepting an `invoice_draft` materializes a `draft` a human must still separately **Post** — `can_execute_directly`
is never `true` for `invoice_draft`, and the two-gate accept (`sales.ai.approve_draft` **and**
`sales.invoice.create`) runs under the human reviewer's session, never the agent's. The
`recommendation_type` values a Builder may see: `invoice_draft`, `price_suggestion`, `credit_precheck_warning`,
`stock_precheck_warning`, `fraud_score`.

# Permissions

| Step | Permission | If absent |
|---|---|---|
| Reach Sales / Invoices | `sales.read` + `sales.invoice.read` | Nav item and route absent; direct hit `403`. |
| Build / save draft / accept AI draft | `sales.invoice.create` (+ `sales.ai.approve_draft` for the AI path) | "New Invoice", `/new`, `N`, "Save Draft", and the Accept button omitted. |
| Post (issue) | `sales.invoice.post` | Post omitted — the create/post split; only Owner/Finance hold it by default. A Sales user builds and hands off. |
| Void a draft | `sales.invoice.void` | Void omitted. |
| Send | `sales.invoice.send` | "Send" omitted; "Preview PDF" still available under read. |
| Customer create | `accounting.customer.create` | Inline "Create customer" omitted; the user picks an existing one. |
| Open the journal entry | `accounting.journal.read` | The Summary Rail's `journal_entry_id` renders as plain text, not a link. |

Segregation of duties: no AI agent holds any create/post/confirm/receipt/credit-note/refund key; posting is
always a human act under a human's session ([`../../ai/workflows/ORDER_TO_CASH.md`](../../ai/workflows/ORDER_TO_CASH.md)).

# i18n & RTL

- Every Builder string, status label, and dialog is keyed in both `en.ts` and `ar.ts`; Arabic is
  professionally authored. The customer-facing PDF is a separate concern: its language is the *customer's*
  configured language, set on the document, independent of the issuing user's session.
- The Builder mirrors under `dir="rtl"` via logical properties; the line table's action affordances flip.
- **Amount columns and the Live Totals Rail use fixed numeric alignment** (Exception A) so figures land on the
  same edge in both languages; numerals, currency codes, and invoice numbers render `dir="ltr"` /
  `unicode-bidi: isolate` and never in Eastern Arabic-Indic digits.
- Bilingual customer and product names render `name_en`/`name_ar` per the active locale; the API returns both.

| Context | English | Arabic |
|---|---|---|
| Title | New Invoice | فاتورة جديدة |
| Action | Save Draft | حفظ كمسودة |
| Action | Post | ترحيل |
| Action | Send | إرسال |
| Total | Total | الإجمالي |
| Status | Posted | مُرحّلة |
| Status | Paid | مدفوعة |

# Accessibility

- The Builder is the Form Page Template: the header's Cancel/Save/Post are duplicated in a sticky footer once
  they scroll out of view, and the header copies get `aria-hidden` while the footer is in view so focus is
  never duplicated.
- The line editor is a real accessible `<table>` (not an ARIA grid) — column headers are associated, add/remove
  row controls are labeled, and per-line validation errors are announced against the offending cell.
- The Live Totals Rail is a real region with a visually-hidden heading; it announces `aria-live="polite"` as
  figures change — a preview total updating is not urgent.
- Post's confirming `AlertDialog` and the send-recipient `Dialog` trap focus and return it on close; the
  send dialog's editable recipient field is a real labeled input.
- Permission-gated controls (Post, Send, Void) are omitted rather than shown disabled-and-mysterious; the
  `ConfidenceBadge` on an AI draft carries its score and reasoning as real adjacent text, never color alone.

# Edge Cases

| Edge case | Behavior |
|---|---|
| **Abandonment mid-build** | The RHF dirty-guard intercepts navigation/ESC with a "Discard changes?" prompt; a saved `draft` persists server-side and is resumable, an unsaved build is discarded on confirm. |
| **Resume a draft** | Reopening a `draft` invoice loads `InvoiceForm mode="edit"` from server state — there is no separate `/edit` route; the status decides the mode. |
| **Back-button after save-draft** | Navigates to the list; the draft is safely persisted, not lost. |
| **Partial post** | Posting is atomic — an invoice is never "half posted"; a precondition failure leaves it wholly `draft`. |
| **Double-submit of Post** | The Post control disables on first click and the `POST` carries an `Idempotency-Key` (stored per `formInstanceId`); a duplicate is a no-op, a changed payload under the same key is `422 DUPLICATE_REQUEST`. |
| **Concurrent edit — two users open the same draft** | The draft carries optimistic-concurrency; the second save `409`s and re-fetches rather than clobbering the first. |
| **Concurrent post + edit** | Once posted the invoice is immutable; a stale edit tab's next save `409`s and re-renders read-only. |
| **Posted-then-wrong** | Never edited or voided; corrected only by a credit note, preserving the audit trail. |
| **Sent before posted?** | Not possible — "Send" acts on a posted invoice's PDF; a draft has no issuable document. |
| **Fiscal period closed for the invoice date** | Posting is blocked with an explanation; the user selects an open period or routes the correction through the period's reopen policy. |

# End of Document
