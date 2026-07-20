# Drawers & Sheets — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / DRAWERS
---

# Purpose

A drawer is QAYD's affordance for **inspecting or acting on a record without leaving the context behind
it** — a row-detail panel that slides in over a list while the list stays visible, an approval that reads
with its AI reasoning and source citations alongside the queue, a filter panel on a tablet, a multi-step
wizard that runs beside a workbench. It is the deliberate counterpart to a [modal](./MODALS.md): where a
modal's scrim *obscures* the page to demand a focused, must-resolve interaction, a drawer *preserves* the
page so the user can act with the surrounding context still on screen. This document is the binding
specification for the entire drawer system: the `Sheet` primitive (shadcn/ui over Radix
`@radix-ui/react-dialog`'s side presentation), its side and width variants, the drawer kinds QAYD ships
(record-detail, the Approval Drawer, filter drawer, multi-step wizard), the focus/scroll/ESC contract,
the non-remount guarantee for inline errors, and the Modal-vs-Drawer-vs-Route decision that decides when
a drawer is the right surface at all.

Drawers inherit the platform's non-negotiables. **The frontend never contains business logic** — a
drawer surfaces server-owned data and fires server-validated mutations; it never computes a balance or
decides a permission. **AI is visible, never silent** — the Approval Drawer, the most important drawer in
the product, always renders the proposing agent, its confidence, its reasoning, and its source
citations, and never turns approve/reject/delegate into an auto-commit. And **sensitive mutations are
never composed in a quick-create surface** — a drawer may *approve* the final step of a bank transfer or
a payroll release, but the transfer/release is *authored* on its own full-page route
(`../NAVIGATION_SYSTEM.md`, `../FRONTEND_ARCHITECTURE.md`).

This document owns the *side-sheet* half of the overlay system; its sibling [`./MODALS.md`](./MODALS.md)
owns the centered-dialog half. The two share one z-index scale, one reduced-motion discipline, and one
decision matrix — restated identically in both so neither drifts. Geometry and the z-index scale are
`../LAYOUT_SYSTEM.md`'s; tokens and the `Sheet` primitive's base styling are `COMPONENT_LIBRARY.md`'s;
this file specifies behavior and composition on top of both.

# Anatomy & Variants

## The primitive

QAYD ships one drawer primitive: `Sheet`, a restyled shadcn/ui component over Radix
`@radix-ui/react-dialog` in its side presentation. It keeps Radix's full accessibility contract (focus
trap, focus return, scroll lock, `aria-modal`) untouched; QAYD adds `side` and `width` variants and a
non-remount composition discipline on top.

```
list / workbench stays visible ─────────────────╮   ╭─ scrim (--z-drawer-scrim 40, dimmer than a modal's)
                                                 │   │
┌──────────────────────────────┬────────────────┴───┴──────────┐
│  Journal entries (still here) │  JE-2026-07-0482          [✕] │ ← SheetContent, --z-drawer (41)
│  ▸ JE-…0480   1,200.000       │  ──────────────────────────── │   slides from inline-end (LTR: right)
│  ▸ JE-…0481     450.000       │  Rent accrual · Posted        │
│  ▸ JE-…0482 ◂ selected        │  [ ConfidenceBadge 92% ]      │
│  ▸ JE-…0483     980.000       │  Reasoning ▸ (disclosure)     │ ← body scrolls independently
│                               │  Lines · History · Sources    │
│                               │  ──────────────────────────── │
│                               │       [ Reject ] [ Approve ]  │ ← pinned footer, primary at inline-end
└──────────────────────────────┴───────────────────────────────┘
```

Every drawer is: a portalled scrim (dimmer than a modal's — the page behind is meant to stay legible) +
a panel anchored to one inline edge; a `SheetHeader` with a `SheetTitle` (the accessible name) and a
close `✕`; a body that scrolls independently of the page; and an optional pinned `SheetFooter` whose
primary action sits at the inline-end.

## Side variant

| `side` | LTR renders from | RTL renders from | Used for |
|---|---|---|---|
| `end` (**default**) | right edge | left edge | Record-detail panels, the Approval Drawer, multi-step wizards — the overwhelming majority |
| `start` | left edge | right edge | The mobile [Sidebar](./SIDEBAR.md)-as-Sheet only (navigation slides from where the sidebar lives) |
| `bottom` | bottom edge | bottom edge | Mobile "More" nav sheet, mobile quick-create sheet, a filter sheet on a phone |

The rule: `side="end"` is not "right" — it is the **inline-end** edge, resolved from `dir` at render
time, never hardcoded. A detail drawer slides in from the right in English and from the left in Arabic
with zero conditional logic, because the primitive reads the ambient direction (`COMPONENT_LIBRARY.md →
Sheet`; `../NAVIGATION_SYSTEM.md → RTL Mirroring`). This is why the entire RTL story for drawers is
structural rather than a per-component patch.

## Width variant

The panel's inline size (its width in LTR, still its inline size in RTL) is a `width` variant; every
width shares one radius on its inner corners, one `--qayd-shadow-lg`, and the same pinned-header/footer
structure:

| `width` | Inline size | Typical use |
|---|---|---|
| `sm` | `360px` | A compact filter panel, a single-record summary |
| `md` | `440px` — **default** | Record-detail panels, the Approval Drawer |
| `lg` | `560px` | A multi-step wizard or a detail panel with a data table inside |
| `full` | Fills the viewport (mobile) | Any drawer below `sm` — a detail panel becomes full-screen rather than a cramped 360px sliver on a phone |

`md` (`440px`) intentionally matches the reading width a detail panel needs without competing with the
`360px` AI Rail's ambient width; a drawer wider than `lg` is a signal the interaction should be a
**route**, not a drawer — see the decision matrix. There is no persisted "drawer width" preference: a
drawer is transient, opened for one interaction and dismissed, unlike the Sidebar's persisted collapse.

## The drawer kinds

| Kind | Component | Width | Distinguishing rule |
|---|---|---|---|
| **Record detail** | `DetailSheet` (composed per module) | `md` | Opens from a `DataTable` row click; shows the record's summary + tabs (Lines / History / Attachments), read or act without leaving the list. |
| **Approval Drawer** | `ApprovalDrawer` (`components/ai/approval-drawer.tsx`) | `md` | The AI-gated approve/reject/delegate surface — confidence, reasoning, source citations, and the approval chain, decided in place. The most important drawer in the product; specified in full below. |
| **Filter drawer** | `FilterSheet` | `sm` (tablet) / `bottom` (phone) | On desktop, filters are an inline bar; on tablet/phone they collapse into a drawer so a dense workbench keeps its width. |
| **Multi-step wizard** | `WizardSheet` | `lg` | A short guided flow (importing a statement's column mapping, a reconciliation setup) that reads beside its workbench; distinct from a full-page wizard route, which is used when the flow is a primary task with its own URL. |

# Props / API

All drawer components are Client Components (open state, focus, mutations). Each is mounted next to the
context it acts on — a detail drawer lives in the list screen that opens it — never in a global registry,
so its data and mutation are co-located with the record.

## `Sheet` / `SheetContent`

| Prop | Type | Required | Description |
|---|---|---|---|
| `open` | `boolean` | yes (controlled) | The parent owns open state, so a mutation's success can close the drawer and an in-flight mutation can keep it open. |
| `onOpenChange` | `(open: boolean) => void` | yes | Radix fires this on ESC, scrim click, and close-button click; QAYD wraps it to enforce the dirty-form guard (below). |
| `side` | `'end' \| 'start' \| 'bottom'` | no, default `'end'` | Inline-resolved; never a physical `left`/`right`. |
| `width` | `'sm' \| 'md' \| 'lg' \| 'full'` | no, default `'md'` | Panel inline size. |
| `dismissible` | `boolean` | no, default `true` | When `false`, ESC and scrim click are ignored and the `✕` is hidden (used while a non-cancelable mutation is mid-flight, and for a wizard mid-step that must not lose progress to a stray scrim click). |
| `modal` | `boolean` | no, default `true` | `true` scrim-blocks interaction with the page behind (the norm). A rare **non-modal** filter drawer (`false`) lets the user keep scrolling the list behind it while adjusting filters — used only where interacting with the background *is* the point. |

`SheetContent` composes `SheetHeader` (with a required `SheetTitle`), a scrollable body, and an optional
`SheetFooter`.

## `ApprovalDrawer`

| Prop | Type | Required | Description |
|---|---|---|---|
| `request` | `ApprovalRequest` | yes | The normalized request from `GET /api/v1/approvals/{id}` — `subjectType`, `title`, `amount`/`currencyCode`, `status`, `requiredPermission`, `sourceAgentCode`, `confidenceScore` (0–100, `null` when human-submitted), `reasoning`, `sources[]`, and `steps[]` (the approval chain). Shape owned by `../APPROVAL_CENTER.md → Data & State`. |
| `open` / `onOpenChange` | `boolean` / `(o) => void` | yes | Controlled. |
| `onApprove` | `(note?: string) => Promise<void>` | yes | Awaited; the drawer shows a pending state and closes on resolve. |
| `onReject` | `(reason: string) => Promise<void>` | yes | Reason mandatory — the platform-wide rule that every rejection carries a stored reason. |
| `onDelegate` | `(userId: number) => Promise<void>` | no | Rendered only when the viewer is the assigned approver for the current pending step. |

## `DetailSheet` / `FilterSheet` / `WizardSheet`

`DetailSheet` and `FilterSheet` accept the module-specific record/filter shape plus the shared
`open`/`onOpenChange`. `WizardSheet` additionally takes `steps: WizardStep[]`, a controlled
`stepIndex`, and `onStepChange`; step state lives in the parent (or React Hook Form), never re-derived
from the drawer's own remount, which is the whole point of the non-remount guarantee below.

# States

Every drawer ships these states in full light/dark and LTR/RTL parity:

| State | Behavior |
|---|---|
| **Closed** | Not in the DOM — Radix portals mount only while `open`; a closed drawer costs nothing and holds no stale focus or scroll position. |
| **Opening / open** | Scrim fades in (dimmer than a modal's); the panel slides in from the inline edge over ~200ms with an ease-out curve (reduced-motion: instant). Focus moves to the first focusable element inside, or the panel itself. |
| **Loading (detail)** | A `DetailSheet` opened from a row already has the row's summary data (passed from the list) and shows it immediately, streaming the fuller detail (history, attachments) into its tabs with per-tab `Skeleton`s — the drawer never opens to a blank panel. |
| **Submitting** | The footer's primary action shows a spinner (`Button loading`, width preserved); `dismissible={false}` for the duration so a stray ESC/scrim click cannot orphan an in-flight mutation. |
| **Inline error** | A failed mutation keeps the drawer open, maps `ApiError.errors[].field` onto the relevant field or renders an inline error line, and re-enables the action — the drawer is **never remounted** on a recoverable failure (see `# Behavior`). |
| **Success** | The mutation resolves; the parent sets `open=false`; a `useApiToast` success toast (portalled above the drawer tier) confirms. For the Approval Drawer, the underlying queue query is invalidated so the decided item leaves the list. |
| **Empty (Approval Drawer, already-decided)** | If the request was decided by someone else while the drawer was open (a realtime race), the action footer is replaced by a read-only "Already {approved|rejected} by {name}" banner — never a live approve button on a settled item. |

# Behavior & Interaction

## Modal vs. Drawer vs. Route — the decision matrix

Identical to [`./MODALS.md`](./MODALS.md); repeated so a drawer is never chosen by default:

| Use a… | When | Examples |
|---|---|---|
| **Route (full page)** | The interaction is a primary task, needs a shareable/bookmarkable URL, is long/multi-section, or is a **sensitive mutation** forbidden from a quick surface. | Composing a journal entry, initiating a bank transfer, payroll stages, tax filing, a report canvas. |
| **Drawer (this document)** | The user must inspect or act on a record **while keeping the list/workbench behind it visible**, or a secondary panel (filters, an approval + reasoning) that reads *alongside* primary content. | A row-detail panel, the Approval Drawer, a tablet filter panel, a wizard beside a workbench. |
| **[Modal](./MODALS.md)** | A short, focus-demanding interaction that must be resolved **before returning** to the page, needing no URL. | Confirming/posting, a 2–5-field quick form, type-to-confirm, reviewing one AI proposal's decision in isolation. |

The drawer-specific tie-breaker: **if the value comes from seeing the list behind it while you act, it is
a drawer; if the value comes from a distraction-free focus on one decision, it is a modal.** The
Approval *Drawer* exists (rather than an approval modal) precisely because a reviewer clearing a queue
benefits from the queue staying visible behind each decision; a single one-off approval reached from a
notification may instead render as an AI-review modal (`./MODALS.md`), and both decide the *same* record
through the *same* mutation — QAYD never has two approval paths that could disagree (`../APPROVAL_CENTER.md`).

## The non-remount guarantee — why a drawer with an editable body is a `useMutation`, not a Server Action

This is the drawer system's single most important behavioral rule, and it ties directly to
`../README.md → RSC vs. Client Components`: a Server Action mutation triggers a route transition, which
can tear down and rebuild the tree — acceptable for a fire-and-forget toggle, **fatal** for a drawer
whose body holds in-progress edits or wizard-step state. If a validation error came back and the drawer
remounted, the user's typed work and their current step would be gone. Therefore:

> **Any drawer with an editable body, a multi-step flow, or an inline-error-recovery requirement drives
> its mutation through a TanStack Query `useMutation`, never a Server Action** — so the component owns
> its own pending/error/optimistic state and the drawer is never remounted on a failure.

```tsx
// components/banking/reconciliation-detail-sheet.tsx (excerpt)
'use client';

import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetFooter } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { useMatchStatementLine } from '@/hooks/banking/use-reconciliation';   // a useMutation, not a Server Action
import { useApiToast } from '@/hooks/use-api-toast';

export function ReconciliationDetailSheet({ line, open, onOpenChange }: ReconDetailProps) {
  const toast = useApiToast();
  const match = useMatchStatementLine();          // owns pending/error state locally

  async function confirmMatch(ledgerEntryId: number) {
    try {
      await match.mutateAsync({ lineId: line.id, ledgerEntryId });
      onOpenChange(false);                          // close only on success
    } catch (err) {
      toast.fromApiError(err);                      // drawer stays mounted; edits + selection preserved
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange} width="lg" dismissible={!match.isPending}>
      <SheetContent>
        <SheetHeader><SheetTitle>{line.description}</SheetTitle></SheetHeader>
        {/* candidate-match list; selecting a candidate does NOT remount the sheet on a server error */}
        <SheetFooter>
          <Button variant="secondary" onClick={() => onOpenChange(false)} disabled={match.isPending}>Cancel</Button>
          <Button loading={match.isPending} onClick={() => confirmMatch(selectedCandidateId)}>Confirm match</Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  );
}
```

A read-only detail drawer with no editable body *may* use a Server Action for a trivial side effect (a
"mark as read"), but the moment a drawer holds an edit or a step, it is a `useMutation` — this is the
concrete application of the README's Server-Action-vs-`useMutation` guidance to the drawer surface.

## Focus, scroll, ESC — from Radix, preserved

- **Focus trap.** Tab cycles within the drawer while open; the page behind is `aria-hidden` + inert
  (Radix marks portal siblings automatically). No manual trap.
- **Focus return.** On close, focus returns to the trigger (the row, the "Filters" button) by default,
  since it stays mounted; `onCloseAutoFocus` redirects to a stable anchor only when the trigger was
  removed by the action (e.g. the row was matched-and-removed from the list).
- **Scroll lock.** For a `modal` drawer, Radix locks `<body>` scroll; the drawer's own body scrolls
  independently via an `overflow-y-auto` region between a pinned header and footer, so a long detail
  panel never pushes its action buttons off-screen. A **non-modal** filter drawer (`modal={false}`)
  deliberately does *not* lock body scroll, because scrolling the list behind it while filtering is the
  point.
- **ESC + scrim dismissal, blocked for dirty forms.** Same policy as modals: a clean drawer dismisses on
  ESC/scrim freely; a **dirty** drawer (unsaved edits, a wizard past step 1) intercepts both and raises a
  nested "Discard changes?" `AlertDialog` (`./MODALS.md`) instead of closing — a reflexive ESC never
  discards typed work or wizard progress silently.

## The Approval Drawer

The Approval Drawer is where the platform's "AI proposes, a human approves" rule becomes a concrete
surface. It renders one `ApprovalRequest` with the full AI contract and the four possible human
responses, deciding in place while the queue stays visible behind it:

```tsx
// components/ai/approval-drawer.tsx
'use client';

import { useState } from 'react';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetFooter } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { AmountCell } from '@/components/accounting/amount-cell';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';
import { ReasoningDisclosure } from '@/components/ai/reasoning-disclosure';
import { ApprovalStepper } from '@/components/ai/approval-stepper';
import { DelegatePicker } from '@/components/approvals/delegate-picker';
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
         AlertDialogFooter, AlertDialogCancel, AlertDialogAction } from '@/components/ui/alert-dialog';
import { Textarea } from '@/components/ui/textarea';
import { usePermission } from '@/hooks/use-permission';
import { useTranslations } from 'next-intl';
import { Check, X, UserPlus } from 'lucide-react';

export function ApprovalDrawer({ request, open, onOpenChange, onApprove, onReject, onDelegate }: ApprovalDrawerProps) {
  const t = useTranslations('approvals');
  // The finer-grained per-step gate (bank_transfer's two-key chain) wins over the request-level key.
  const currentStep = request.steps.find((s) => s.status === 'pending');
  const gate = currentStep?.stepPermission ?? request.requiredPermission;
  const canDecide = usePermission(gate) && currentStep?.approverUserId != null; // assigned to this viewer's step
  const confidence = request.confidenceScore != null
    ? normalizeConfidence(request.confidenceScore, 'percentage')
    : null;

  const [rejectOpen, setRejectOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);

  const settled = request.status !== 'pending' && request.status !== 'in_review' && request.status !== 'held';

  return (
    <Sheet open={open} onOpenChange={onOpenChange} width="md" dismissible={!busy}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{request.title}</SheetTitle>
          <p className="text-caption text-ink-500">
            {request.sourceAgentCode ?? t('humanSubmitted')} · <FormattedRelativeTime value={request.createdAt} />
          </p>
        </SheetHeader>

        {request.amount && (
          <AmountCell amount={request.amount} currencyCode={request.currencyCode!} emphasis="strong" showCurrency />
        )}

        {/* AI contract: confidence + reasoning + source citations. Absent entirely on a human-submitted row. */}
        {confidence != null && <ConfidenceBadge confidence={confidence} reasoning={request.reasoning ?? undefined} />}
        {(request.reasoning || request.sources.length > 0) && (
          <ReasoningDisclosure reasoning={request.reasoning} sources={request.sources} />
        )}

        {/* the approval chain — every step's role + status; only the viewer's own pending step is interactive */}
        <ApprovalStepper steps={request.steps} />

        <SheetFooter>
          {settled ? (
            <p className="text-caption text-ink-500">{t('alreadyDecided', { status: request.status })}</p>
          ) : (
            <>
              {onDelegate && currentStep && (
                <DelegatePicker stepPermission={gate} onDelegate={onDelegate} triggerVariant="ghost" />
              )}
              <Button variant="destructive-quiet" size="sm" disabled={!canDecide} onClick={() => setRejectOpen(true)}>
                <X className="h-3.5 w-3.5" /> {t('reject')}
              </Button>
              <Button size="sm" disabled={!canDecide || busy}
                      onClick={async () => { setBusy(true); await onApprove(); setBusy(false); onOpenChange(false); }}>
                <Check className="h-3.5 w-3.5" /> {t('approve')}
              </Button>
            </>
          )}
        </SheetFooter>

        {/* rejection reason is mandatory — a nested AlertDialog, stacked above the drawer's z-tier */}
        <AlertDialog open={rejectOpen} onOpenChange={setRejectOpen}>
          <AlertDialogContent>
            <AlertDialogHeader><AlertDialogTitle>{t('rejectTitle', { title: request.title })}</AlertDialogTitle></AlertDialogHeader>
            <Textarea placeholder={t('reasonRequired')} value={reason} onChange={(e) => setReason(e.target.value)} aria-label={t('rejectionReason')} />
            <AlertDialogFooter>
              <AlertDialogCancel>{t('cancel')}</AlertDialogCancel>
              <AlertDialogAction disabled={reason.trim().length === 0}
                onClick={async () => { await onReject(reason); setRejectOpen(false); onOpenChange(false); }}>
                {t('confirmReject')}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </SheetContent>
    </Sheet>
  );
}
```

Five contracts this drawer holds, each a platform rule made concrete:

1. **The AI is never silent.** Confidence (`ConfidenceBadge`), reasoning, and source citations
   (`ReasoningDisclosure` over `request.sources[]`) always render for an AI-originated request, and are
   *absent entirely* for a human-submitted one (`confidenceScore: null`) — never a fake "N/A" badge.
2. **Approve/reject/delegate never auto-commit.** Each is an explicit human action; approve awaits a real
   mutation and closes only on resolve; the drawer never fires on a confidence score.
3. **Reject always carries a reason.** The mandatory-reason `AlertDialog` stacks above the drawer
   (`--z-modal` > `--z-drawer`) and disables confirm until the reason is non-empty — stored against the
   decision row per the platform-wide rule.
4. **The per-step gate wins over the request-level key.** For a chained request (the `bank_transfer`
   two-key chain, `../APPROVAL_CENTER.md`), the current step's `stepPermission`
   (`bank.payment.approve` → `bank.payment.approve.final`) is checked in place of the request's own
   `requiredPermission`; a viewer who is not the assigned approver for the *current* step sees the
   actions disabled, not a false path to decide someone else's step.
5. **Delegate is scoped.** `DelegatePicker` is a `Command`+`Popover` combobox scoped to users who hold
   the *same* role/permission the pending step requires — you cannot delegate to someone who could not
   legally decide it.

### Reasoning and source citations

The Approval Drawer's `ReasoningDisclosure` is where the platform's "no card shows a bare number the AI
computed without showing its work" rule is honored at the point of decision. It renders, collapsed by
default beneath the action row, the proposal's free-text `reasoning` and — the part that makes a
reviewer able to *trust* a proposal rather than take it on faith — its `sources[]`: the concrete records
the agent grounded its recommendation in, each a resolvable citation:

```tsx
// components/ai/reasoning-disclosure.tsx (excerpt — the sources list)
'use client';

import { useState } from 'react';
import Link from 'next/link';
import { ChevronDown, FileText } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { sourceHref } from '@/lib/ai/source-href';

export function ReasoningDisclosure({ reasoning, sources }: ReasoningDisclosureProps) {
  const [open, setOpen] = useState(false);
  const reduced = useReducedMotion();

  return (
    <div className="rounded-md border border-ink-150">
      <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open}
              className="flex w-full items-center gap-1.5 px-3 py-2 text-caption text-ink-700">
        <ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
        Why this recommendation
      </button>
      <AnimatePresence initial={false}>
        {open && (
          <motion.div
            initial={reduced ? false : { height: 0, opacity: 0 }}
            animate={reduced ? undefined : { height: 'auto', opacity: 1 }}
            exit={reduced ? undefined : { height: 0, opacity: 0 }}
            className="overflow-hidden px-3 pb-3"
          >
            <p className="text-caption text-ink-700">{reasoning}</p>
            {sources.length > 0 && (
              <ul className="mt-2 space-y-1" role="list">
                {sources.map((s, i) => (
                  <li key={i}>
                    {/* every citation resolves to a real, permission-checked route — never free text */}
                    <Link href={sourceHref(s)} className="flex items-center gap-1.5 text-caption text-accent-600 hover:underline">
                      <FileText className="h-3.5 w-3.5 shrink-0" />
                      <span dir="auto" className="truncate">{s.label}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}
```

Each source's `sourceHref(s)` resolves to the same finite, CI-checked route table every AI-surfaced
target draws from (`../NAVIGATION_SYSTEM.md → Every AI-surfaced target is a real, resolvable route`) — a
citation is a navigable link to the grounding record (a bank transaction, a prior journal entry, an
invoice), never a dead label the reviewer has to go hunt for. A reviewer can therefore verify a
recommendation *from inside the drawer* — open the reasoning, click a cited transaction, confirm the
agent read it right — before approving, which is the entire point of surfacing sources rather than just a
confidence number. The disclosure is collapsed by default so a confident reviewer clearing a queue is
not slowed by walls of reasoning, but the evidence is always one click away, never hidden behind a
navigation.

## Record-detail drawer

The most common drawer is a `DetailSheet` opened from a `DataTable` row — it shows a record's summary and
tabs without leaving the list, so a reviewer can scan a list and drill into rows one after another
without a page transition per row:

```tsx
// components/accounting/journal-entry-detail-sheet.tsx (shape)
'use client';

import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { StatusPill } from '@/components/shared/status-pill';
import { AmountCell } from '@/components/accounting/amount-cell';
import { useJournalEntryQuery } from '@/hooks/accounting/use-journal-entries';

export function JournalEntryDetailSheet({ summary, open, onOpenChange }: DetailSheetProps) {
  // `summary` came from the row the user clicked — the drawer paints instantly, then streams the rest.
  const { data: entry, isPending } = useJournalEntryQuery(summary.id, { initialData: summary, enabled: open });

  return (
    <Sheet open={open} onOpenChange={onOpenChange} width="md">
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{entry.journal_number}</SheetTitle>
          <div className="flex items-center gap-2">
            <StatusPill domain="journal_entry" status={entry.status} />
            <AmountCell amount={entry.total_debit} currencyCode={entry.currency_code} emphasis="strong" showCurrency />
          </div>
        </SheetHeader>
        <Tabs defaultValue="lines">
          <TabsList>
            <TabsTrigger value="lines">Lines</TabsTrigger>
            <TabsTrigger value="history">History</TabsTrigger>
            <TabsTrigger value="attachments">Attachments</TabsTrigger>
          </TabsList>
          <TabsContent value="lines">{/* line grid; skeletons while isPending on the fuller fetch */}</TabsContent>
          <TabsContent value="history">{/* audit trail */}</TabsContent>
          <TabsContent value="attachments">{/* documents */}</TabsContent>
        </Tabs>
      </SheetContent>
    </Sheet>
  );
}
```

Because the row's `summary` seeds `initialData`, the drawer opens to real content, never a blank panel or
a full-drawer spinner; the deeper tabs (history, attachments) stream in with per-tab skeletons. A
`DetailSheet` that offers an *edit* action drives that edit through a `useMutation` for the non-remount
reasons above; a pure read-only detail may fetch server-side, but the moment it holds an edit it follows
the `useMutation` rule.

## Multi-step drawer wizard

A `WizardSheet` runs a short guided flow beside its workbench (a statement-import column mapping, a
reconciliation setup). Its step state lives in the parent / React Hook Form, and it drives its
per-step mutations through `useMutation` — so a validation error on step 2 keeps the drawer mounted at
step 2 with steps 0–1's data intact, never bouncing back to step 0. `dismissible={false}` while past
step 1 routes a stray ESC/scrim click through the discard guard rather than dumping progress.

## Filter drawer on tablet

On desktop, list/workbench filters are an inline sticky bar (`../LAYOUT_SYSTEM.md`). On **tablet**, that
bar collapses into a `FilterSheet` (`width="sm"`, `side="end"`) so a dense grid keeps its full width; on
**phone**, filters become a `side="bottom"` sheet. The filter drawer is the one place a **non-modal**
drawer (`modal={false}`) is sometimes right — letting the user watch the list re-filter behind the open
panel — but it defaults to modal, and only opts out where seeing the live result *is* the interaction.

## Mounting, portal, and z-index

Every drawer portals to `#portal-root`, outside the shell subtree, so no sticky header/sidebar clips it.
The scale (`../LAYOUT_SYSTEM.md`):

| Layer | Token | Value |
|---|---|---|
| Drawer scrim | `--z-drawer-scrim` | 40 |
| Drawer panel | `--z-drawer` | 41 (AI Rail overlay, mobile Sidebar Sheet, detail sheets — all share this tier) |
| Modal scrim / panel | `--z-modal-scrim` / `--z-modal` | 50 / 51 — a confirmation raised *from* a drawer stacks above it |
| Command palette | `--z-command-palette` | 60 |
| Toast | `--z-toast` | 70 — a success toast survives the drawer's close |
| Tooltip | `--z-tooltip` | 80 |

Drawers sit **below** modals by design: a discard guard or a reject-reason prompt raised inside a drawer
must stack over it, which the tiers guarantee with no manual ordering. When both the AI Rail and a
detail Sheet are open (both `--z-drawer`), the Sheet is mounted later in DOM order and visually wins
(`../LAYOUT_SYSTEM.md`).

# Accessibility

Drawers inherit Radix's full contract; QAYD's rules on top:

| Concern | Contract |
|---|---|
| Role & modality | `role="dialog"` + `aria-modal="true"` (Radix). A non-modal filter drawer sets `aria-modal="false"` and does not inert the background, matching its intent. |
| Accessible name | Every drawer has a `SheetTitle` wired via `aria-labelledby` — a titleless drawer is a lint failure. |
| Description | A drawer with an instruction/consequence uses a `SheetDescription` via `aria-describedby`; the Approval Drawer's reasoning text is additionally exposed so the "why" is reachable before the approve button. |
| Focus trap & return | Radix `FocusScope` traps and returns focus to the trigger; `onCloseAutoFocus` only when the trigger was removed (a matched-away row). |
| Nested layers | A confirmation/discard/reject `AlertDialog` raised inside a drawer owns the top focus scope; closing it returns focus **into** the drawer, not to the page (`../ACCESSIBILITY.md`). |
| Keyboard | `Esc` closes a clean drawer / routes through the discard guard for a dirty one; `Tab` cycles within; the Approval Drawer's approve/reject/delegate are real focusable `<button>`s in a logical order, and a disabled decision button exposes *why* via `aria-describedby` (no permission for this step / not your step) — never a silently disabled control. |
| Approval chain semantics | `ApprovalStepper` renders "Step 1 of 2" and each step's role/status as real text, not a color-only progress bar — a colorblind or grayscale reviewer reads the chain state from words, not hue. |
| Color is never the only signal | Confidence is a percentage + qualitative band label, not a traffic-light color; the reject action's danger is carried by its verb and the mandatory-reason step, not button color alone. |
| Reduced motion | The slide-in and scrim fade are honored under `prefers-reduced-motion: reduce` — the drawer appears/disappears instantly (no slide), while focus, scroll lock, and trap are unchanged. |

# Theming, Dark Mode & RTL

**Tokens only.** The scrim is `bg-ink-950/30` (deliberately dimmer than a modal's `/40` — the page
behind stays legible), the panel `bg-surface-raised` with `--qayd-shadow-lg`; the reject action uses
`Button variant="destructive-quiet"`. No raw hex, px, or `dark:`-raw-color variant in any drawer source.

**Dark mode.** `bg-surface-raised` (`#141F1B`) reads as elevated over the darkened page; the scrim
opacity is re-tuned (not inverted) under `data-theme="dark"`. Applied by the shell's `next-themes`
provider (`../THEMING.md`); drawers carry no dark branch.

**RTL.** Set once at the root. Because the primitive resolves `side="end"` from `dir` and every offset is
logical (`inset-inline-*`, `ps-*/pe-*`, `SheetFooter` orders actions in source order so the primary
lands at the inline-end), the entire drawer mirrors with zero conditional logic: **a detail/Approval
drawer slides in from the right in English and from the left in Arabic** (`side="start"` and `"bottom"`
mirror correspondingly), the close `✕` stays at the inline-end corner, and the footer's Cancel/primary
order flips correctly. Two explicit pins, both inherited from `AmountCell`: any **amount** in the
Approval Drawer ("approve 12,500.000 KWD") renders `dir="ltr"`, Western Arabic numerals, never reversed;
and a **Latin source-citation label or reference number** in `ReasoningDisclosure`'s `sources[]` renders
`dir="auto"` so a mixed-language citation reads in its own correct direction inside the Arabic panel.

# i18n

Every drawer string is a key in both dictionaries, sharing one `Dictionary` type (a one-sided key fails
`npm run i18n:check`). Full sentences with interpolation are single keys so Arabic word order is correct:

| Key | English | Arabic |
|---|---|---|
| `approvals.approve` | Approve | اعتماد |
| `approvals.reject` | Reject | رفض |
| `approvals.reasonRequired` | Reason (required)… | السبب (مطلوب)… |
| `approvals.rejectTitle` | Reject {title}? | رفض {title}؟ |
| `approvals.alreadyDecided` | Already {status} | تم {status} مسبقًا |
| `approvals.humanSubmitted` | Submitted for review | مُقدَّم للمراجعة |
| `drawers.filters` | Filters | عوامل التصفية |
| `drawers.discardTitle` | Discard changes? | تجاهل التغييرات؟ |

Amounts, dates, agent labels, and citation references embedded in copy are passed as React nodes
(`AmountCell`, `dir="auto"` spans) via interpolation, never formatted into the string, so the
numeral-system and direction rules hold inside translated text.

# Testing

**Storybook** (`components/ui/sheet.stories.tsx`, `components/ai/approval-drawer.stories.tsx`) renders
each side/width/kind with the `direction`/`theme` decorators:

```tsx
export const DetailMdEnd: StoryObj = { args: { width: 'md', side: 'end' } };
export const WizardLg: StoryObj = { args: { width: 'lg', side: 'end' } };
export const FilterBottomMobile: StoryObj = { args: { side: 'bottom' }, parameters: { viewport: 'mobile1' } };
export const ApprovalAiOriginated: StoryObj = { args: { request: aiRequestFixture } };        // confidence + reasoning + sources
export const ApprovalHumanSubmitted: StoryObj = { args: { request: humanRequestFixture } };   // no confidence badge at all
export const ApprovalNotYourStep: StoryObj = { args: { request: chainedRequestFixture } };    // actions disabled w/ reason
export const RTL: StoryObj = { args: { request: aiRequestFixture }, parameters: { direction: 'rtl' } };  // slides from left
export const Dark: StoryObj = { parameters: { theme: 'dark' } };
```

**Vitest + Testing Library** covers the behavior-bearing logic: the per-step gate resolution, the
mandatory-reason reject, the human-vs-AI confidence rendering, and the non-remount error path:

```tsx
test('the current step permission overrides the request-level permission for the decision gate', () => {
  render(<ApprovalDrawer open request={bankTransferChain} onApprove={vi.fn()} onReject={vi.fn()} {...withPermission('bank.payment.approve.final')} />);
  // viewer holds the FINAL key but the current pending step needs the FIRST key → actions disabled
  expect(screen.getByRole('button', { name: /approve/i })).toBeDisabled();
});

test('a human-submitted request renders no confidence badge', () => {
  render(<ApprovalDrawer open request={{ ...humanRequestFixture, confidenceScore: null }} onApprove={vi.fn()} onReject={vi.fn()} />);
  expect(screen.queryByText(/confidence/i)).toBeNull();
});

test('reject stays disabled until a reason is typed', () => {
  render(<ApprovalDrawer open request={aiRequestFixture} onApprove={vi.fn()} onReject={vi.fn()} {...canDecide} />);
  fireEvent.click(screen.getByRole('button', { name: /reject/i }));
  expect(screen.getByRole('button', { name: /confirm reject/i })).toBeDisabled();
  fireEvent.change(screen.getByRole('textbox', { name: /rejection reason/i }), { target: { value: 'Duplicate of JE-0480' } });
  expect(screen.getByRole('button', { name: /confirm reject/i })).toBeEnabled();
});
```

**Playwright** covers the flows too stateful for a component test: opening a detail drawer from a row and
asserting the list stays visible behind it; a wizard surviving a step-2 validation error without
resetting to step 0 (the non-remount guarantee); the dirty-drawer discard guard; and an approve
mutation invalidating the queue so the decided item leaves the list. It also asserts the RTL slide
direction — a Playwright screenshot in an `ar` locale verifies the panel enters from the left, the exact
regression a Kuwait QA pass exists to catch.

**Accessibility CI gate.** `axe-core` runs against every drawer story; a manual keyboard pass verifies
`Esc`/trap/focus-return, the nested reject/discard `AlertDialog` returning focus into the drawer, and
that every disabled decision button carries an `aria-describedby` reason.

# Edge Cases

| # | Scenario | Resolution |
|---|---|---|
| 1 | A step-2 wizard hits a server validation error. | The mutation is a `useMutation`; the drawer stays mounted at step 2 with steps 0–1's data intact, surfaces the field error inline, and re-enables the action — never a remount that would reset the wizard to step 0. This is the concrete reason a drawer body edit is never a Server Action. |
| 2 | A reviewer approves in the Approval Drawer while a colleague decided the same item a second earlier (realtime race). | The approve mutation returns the server's authoritative state; the drawer replaces its footer with a read-only "Already {approved/rejected} by {name}" banner and the queue invalidates — a settled item never shows a live approve button. |
| 3 | The viewer holds the request-level permission but is not the assigned approver for the current chain step. | The per-step `stepPermission` + `approverUserId` gate disables the actions with an `aria-describedby` reason ("not your step"), rather than falsely offering a decision the server would `403`. |
| 4 | A dirty detail/wizard drawer is dismissed by a stray ESC or scrim click. | Intercepted in `onOpenChange`; a "Discard changes?" `AlertDialog` stacks above the drawer. Confirm discards + closes; Cancel returns to the intact drawer at its current step. A clean drawer closes freely. |
| 5 | A confirmation/reject `AlertDialog` is raised from inside a drawer. | The modal tier (`--z-modal` 51) sits above the drawer tier (`--z-drawer` 41), so it stacks correctly; closing it returns focus into the drawer, not to the page. |
| 6 | Both the AI Rail and a detail Sheet are open at once. | Both live at `--z-drawer`; the Sheet is mounted later in DOM order and visually wins, per `../LAYOUT_SYSTEM.md` — no manual z-bump needed. |
| 7 | An RTL (Arabic) session. | `side="end"` resolves to the left edge; the panel slides in from the left, the `✕` stays at the inline-end corner, the footer order flips — all via logical properties. Amounts and Latin references stay `dir="ltr"`/`dir="auto"`. Verified by an RTL Playwright screenshot. |
| 8 | A detail drawer opened from a row whose record was just deleted elsewhere. | The drawer's detail query returns a not-found; the drawer body shows a "This record is no longer available" state (not a blank panel or an error toast fired inside an open drawer), and the row is removed from the list behind it on the next invalidation. |
| 9 | A filter drawer on tablet where the user wants to watch the list re-filter. | That specific `FilterSheet` opts into `modal={false}` so body scroll is not locked and the live result is visible behind it — the one sanctioned non-modal drawer, used only where interacting with the background is the point. |
| 10 | A mutation is in flight and the user clicks the scrim. | `dismissible={false}` while pending ignores the scrim click, so a slow network cannot orphan an in-flight write behind a closed drawer. |
| 11 | Reduced-motion user opens a drawer. | The panel appears/disappears instantly (no slide, no scrim fade); focus move, scroll lock, and trap are unchanged — motion removed, behavior intact. |
| 12 | A very tall detail panel on a short viewport. | The `SheetHeader` and `SheetFooter` are pinned; the body scrolls internally (`overflow-y-auto`), so the approve/reject footer is always reachable without scrolling the page behind (which is scroll-locked for a modal drawer). |

# End of Document
