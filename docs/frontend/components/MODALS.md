# Modals & Dialogs — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / MODALS
---

# Purpose

A modal is QAYD's affordance for a **short, self-contained, focus-demanding interaction that must be
resolved before the user returns to the page beneath it** — confirming a post, filling a small form,
approving an AI proposal, typing the name of a period to close it. This document is the binding
specification for the entire dialog system: the `Dialog` and `AlertDialog` primitives (shadcn/ui over
Radix `@radix-ui/react-dialog` / `@radix-ui/react-alert-dialog`), their size variants, the four dialog
kinds QAYD ships (confirmation, form, destructive-confirmation, AI-proposal review), the stacked-dialog
policy, the focus-trap/scroll-lock/dismissal contract, and — most importantly — the decision rule for
when an interaction is a **modal** versus a **[drawer](./DRAWERS.md)** versus its own **route**. Getting
that last decision wrong is the single most common way an ERP frontend accretes a swamp of modals that
should have been pages, so it leads the document's structural guidance rather than trailing it.

Modals inherit the platform's non-negotiables verbatim. **The frontend never contains business logic** —
a confirmation dialog surfaces the server's rule ("this period is open, are you sure you want to close
it"), it never *decides* the rule; every mutation a dialog fires is re-validated authoritatively by
Laravel. **AI is visible, never silent** — an AI-proposal review dialog always renders the proposal's
confidence, reasoning, and source citations, and never turns an "approve" click into an auto-commit.
And **sensitive mutations are never a quick-create modal**: initiating a bank transfer, releasing
payroll, or submitting a tax return is a full-page flow, not a dialog (`../NAVIGATION_SYSTEM.md`,
`../FRONTEND_ARCHITECTURE.md`) — a modal may *confirm* such an action's final step, but it is never the
surface on which the action is composed.

This document owns the *dialog* half of the overlay system; its sibling [`./DRAWERS.md`](./DRAWERS.md)
owns the side-sheet half, and the two share one z-index scale, one reduced-motion discipline, and one
decision matrix (restated identically in both so neither can drift). Geometry and the z-index scale are
`../LAYOUT_SYSTEM.md`'s; tokens and the `Dialog` primitive's base styling are `COMPONENT_LIBRARY.md`'s;
this file specifies behavior and composition on top of both.

# Anatomy & Variants

## The two primitives

QAYD ships exactly two modal primitives, both restyled shadcn/ui over Radix, both keeping their Radix
accessibility contract untouched:

| Primitive | Radix base | Dismissible by ESC / backdrop? | Used for |
|---|---|---|---|
| `Dialog` | `@radix-ui/react-dialog` | Yes, by default (overridable — see `# Behavior`) | Form dialogs, AI-proposal review, non-destructive informational modals |
| `AlertDialog` | `@radix-ui/react-alert-dialog` | **No** — Radix `AlertDialog` deliberately does not close on ESC-to-cancel-implicitly or backdrop click; it forces an explicit Cancel/Confirm choice | Confirmations, destructive-action and type-to-confirm dialogs |

Choosing between them is not stylistic: an `AlertDialog` is the correct primitive whenever dismissing
the dialog by accident (a stray backdrop click, a reflexive `Esc`) must **not** be interpretable as
either "confirm" or "silently proceed." Every destructive or irreversible confirmation uses
`AlertDialog`; every recoverable form or review uses `Dialog`.

## Anatomy

```
        ╱ scrim (--z-modal-scrim 50, bg-ink-950/40, click = dismiss for Dialog only) ╲
┌───────────────────────────────────────────────┐  ← DialogContent, --z-modal (51)
│  Post journal entry JE-2026-07-0482?      [✕]  │  ← DialogTitle (labelledby) + close (Dialog only)
│  ─────────────────────────────────────────────│
│  This will post 4 lines totalling             │  ← DialogDescription (describedby)
│  1,050.000 KWD to the general ledger and lock  │
│  the entry from further edits.                 │
│                                                │  ← body: form fields / AI proposal / type-to-confirm
│                          [ Cancel ]  [ Post ]  │  ← DialogFooter (primary action inline-end)
└───────────────────────────────────────────────┘
```

Every dialog is: a portalled scrim + a centered content panel; a `DialogTitle` (always present, wired as
the accessible name via `aria-labelledby`); an optional `DialogDescription` (wired via
`aria-describedby`); a body; and a `DialogFooter` whose primary action sits at the **inline-end** and
whose Cancel sits before it (both flip in RTL via logical order). An `AlertDialog` omits the `[✕]` close
affordance entirely — its only exits are Cancel and the confirming action.

## Size variants

The `Dialog` primitive carries a `size` variant (via `cva`, `COMPONENT_LIBRARY.md`'s convention); the
panel's max-width is the only thing it changes — every size shares one radius (`--qayd-radius-lg`),
shadow (`--qayd-shadow-lg`), and padding scale:

| `size` | Max width | Typical use |
|---|---|---|
| `sm` | `24rem` (384px) | Single-question confirmations, type-to-confirm |
| `md` | `32rem` (512px) — **default** | Short forms (2–5 fields), AI-proposal review |
| `lg` | `44rem` (704px) | A form dense enough to need two columns but still not a page (e.g. a quick expense claim) |
| `full` | Inset `16px` on every side, fills the viewport | `JournalEntryForm` and other line-grid forms **on mobile widths only** — where a `md` dialog cannot hold a debit/credit grid legibly, the same form renders full-screen rather than as a separate mobile component |

There is no `xl`/`2xl` size: an interaction that needs more room than `lg` is a signal it should be a
**route or a [drawer](./DRAWERS.md)**, not a bigger modal — see the decision matrix below. `full` is a
responsive escape hatch for one already-modal form on a phone, not a license for large desktop modals.

## The four dialog kinds

| Kind | Primitive | Dismissal | Distinguishing rule |
|---|---|---|---|
| **Confirmation** | `AlertDialog` | Explicit Cancel/Confirm only | Surfaces a server-owned consequence in plain language; the confirming button carries the action verb ("Post," "Reverse"), never a generic "OK." |
| **Form** | `Dialog` | ESC/backdrop **unless dirty** (see `# Behavior`) | A short create/edit whose scope genuinely fits a modal; anything with an inline-error-recovery requirement drives its own `useMutation` so the dialog is not torn down on a validation failure. |
| **Destructive / type-to-confirm** | `AlertDialog` | Explicit only; confirm disabled until the typed token matches | For posting, closing/locking a fiscal period, voiding a posted entry, or any action the server treats as irreversible — the user types an exact token (the period name, the entry number) to enable the confirm button. |
| **AI-proposal review** | `Dialog` | ESC/backdrop allowed (reviewing is non-destructive; the *decision* is the guarded step) | Renders one `ai_decisions`/proposal with its `ConfidenceBadge`, `ReasoningDisclosure`, and source citations; its actions ("Approve," "Send for approval," "Dismiss") route through the platform's normal approval/autonomy rules and never auto-commit on a single click. |

# Props / API

All dialog components are Client Components (they own open state, focus, and event handlers). They are
mounted where they are triggered, not centralized in a global registry, so each dialog's data and
mutation live next to the record it acts on.

## `Dialog` / `DialogContent`

| Prop | Type | Required | Description |
|---|---|---|---|
| `open` | `boolean` | yes (controlled) | QAYD dialogs are always controlled — the parent owns `open`, so a mutation's success can close the dialog and an in-flight mutation can keep it open. |
| `onOpenChange` | `(open: boolean) => void` | yes | Radix calls this on ESC, backdrop click, and close-button click. QAYD wraps it to enforce the dirty-form guard (below). |
| `size` | `'sm' \| 'md' \| 'lg' \| 'full'` | no, default `'md'` | Panel max-width variant. |
| `dismissible` | `boolean` | no, default `true` | When `false`, ESC and backdrop click are ignored and the `[✕]` is hidden (used while a non-cancelable mutation is mid-flight). Distinct from `AlertDialog`, which is never backdrop-dismissible by construction. |
| `onCloseAutoFocus` | `(e: Event) => void` | no | Override focus-return target only when the trigger was unmounted (rare; see `# Behavior`). |

`DialogContent` composes `DialogTitle` (required for the accessible name — a dialog with no title is a
build-time lint error via a custom rule, never shipped) and optional `DialogDescription`, `DialogFooter`.

## `AlertDialog`

| Prop | Type | Required | Description |
|---|---|---|---|
| `open` | `boolean` | yes | Controlled. |
| `onOpenChange` | `(open: boolean) => void` | yes | Fires on Cancel and on the confirming action; never on backdrop click (Radix `AlertDialog` ignores backdrop). |

`AlertDialogAction` (the confirming button) and `AlertDialogCancel` are required children; there is no
close-`✕`. The confirming action is disabled until its precondition is met (a non-empty reason, a
matching type-to-confirm token).

## `ConfirmDialog` (shared convenience)

A thin `components/shared/confirm-dialog.tsx` wrapper over `AlertDialog` so the dozens of confirmations
across modules never hand-roll the same markup:

| Prop | Type | Required | Description |
|---|---|---|---|
| `open` / `onOpenChange` | `boolean` / `(o) => void` | yes | Controlled. |
| `title` | `string` | yes | e.g. "Reverse JE-2026-07-0482?" |
| `description` | `ReactNode` | no | The server-owned consequence, in plain language. |
| `confirmLabel` | `string` | yes | The action verb — "Reverse," "Post," never "OK." |
| `tone` | `'default' \| 'destructive'` | no, default `'default'` | `'destructive'` renders the confirm button `variant="destructive"`; a *quiet* reject/void uses `destructive-quiet` inside the calling card, not here. |
| `confirmToken` | `string` | no | When present, upgrades to type-to-confirm: the confirm button stays disabled until the user types this exact token. |
| `onConfirm` | `() => Promise<void>` | yes | Awaited; the dialog shows a pending state on the confirm button and closes on resolve, stays open + surfaces the error on reject. |

# States

Every dialog ships these states in full light/dark and LTR/RTL parity:

| State | Behavior |
|---|---|
| **Closed** | Not in the DOM at all — Radix portals mount only while `open`, so a closed dialog costs nothing and holds no stale focus. |
| **Opening / open** | Scrim fades in, panel scales+fades from 98%→100% over ~150ms (reduced-motion: instant). Focus moves to the first focusable element inside, or to the panel itself if none. |
| **Submitting** | The primary action shows a spinner via `Button`'s `loading` prop (width preserved, never a layout jump); the dialog becomes non-dismissible for the duration (`dismissible={false}`) so a stray ESC cannot orphan an in-flight mutation. |
| **Error** | A failed mutation keeps the dialog open, maps `ApiError.errors[].field` onto the form's field errors (`useApiToast().fromApiError`) or renders a single inline error line for a non-form dialog, and re-enables the action — the dialog is never torn down on a recoverable failure, which is precisely why an error-recoverable form is a `useMutation`, not a Server Action (`../README.md → RSC vs Client Components`). |
| **Success** | The mutation resolves; the parent sets `open=false`; a `useApiToast` success toast (portalled above the dialog's z-tier, so it survives the close) confirms the result. |
| **Dirty-blocked close** | An attempt to dismiss a dirty form dialog does not close it; it raises a nested "Discard changes?" `AlertDialog` (below). |

# Behavior & Interaction

## Modal vs. Drawer vs. Route — the decision matrix

This is the load-bearing decision, identical in [`./DRAWERS.md`](./DRAWERS.md):

| Use a… | When | Examples |
|---|---|---|
| **Route (full page)** | The interaction is a primary task, benefits from a shareable/bookmarkable URL, is long or multi-section, or is a **sensitive mutation** the platform forbids from a quick-create surface. | Composing a journal entry, initiating a bank transfer, a payroll run's stages, a tax-return filing, any report canvas. |
| **[Drawer](./DRAWERS.md) (side sheet)** | The user needs to inspect or act on a record's detail **without losing the context of the list/workbench behind it**, or a secondary panel (filters, an approval with reasoning) that reads alongside the primary content. | A row-detail panel over a list, the Approval Drawer, a filter panel on tablet, a multi-step drawer wizard adjacent to a workbench. |
| **Modal (this document)** | A short, focus-demanding interaction that **must be resolved before returning** to the page beneath, and that does *not* need its own URL. | Confirming/posting, a 2–5-field quick form, type-to-confirm a period close, reviewing a single AI proposal's decision. |

Two tie-breakers settle most real cases: **if it needs a URL, it is a route, never a modal** (a modal
state is not deep-linkable, and QAYD's parallel/intercepted-route pattern means a "quick create" that is
*also* a page renders full-page on direct navigation and only as an intercepted modal on an in-app link,
`../NAVIGATION_SYSTEM.md → Routing`); and **if the user needs to see the list behind it while acting, it
is a drawer, not a modal** (a modal's scrim deliberately obscures the background; a drawer deliberately
does not).

## Controlled open + mutation-driven close

QAYD dialogs are always controlled, so lifecycle is explicit:

```tsx
// A form dialog whose close is driven by its own mutation, not by the click that submitted it.
'use client';

import { useState } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCreateCostCenter } from '@/hooks/accounting/use-cost-centers';
import { useApiToast } from '@/hooks/use-api-toast';
import { useTranslations } from 'next-intl';

export function NewCostCenterDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const t = useTranslations('costCenters');
  const toast = useApiToast();
  const create = useCreateCostCenter();
  const [name, setName] = useState('');
  const dirty = name.trim().length > 0;

  async function submit() {
    try {
      await create.mutateAsync({ name_en: name });
      toast.success(t('created'));
      onOpenChange(false);                         // close only on success
      setName('');
    } catch (err) {
      toast.fromApiError(err);                     // stays open, surfaces field errors
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => { if (!next && dirty) { /* raise discard guard */ } else onOpenChange(next); }}
      // while the mutation is in flight the dialog is not dismissible
      dismissible={!create.isPending}
    >
      <DialogContent size="sm">
        <DialogHeader>
          <DialogTitle>{t('newTitle')}</DialogTitle>
          <DialogDescription>{t('newDescription')}</DialogDescription>
        </DialogHeader>
        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder={t('namePlaceholder')} aria-label={t('name')} />
        <DialogFooter>
          <Button variant="secondary" onClick={() => onOpenChange(false)} disabled={create.isPending}>{t('cancel')}</Button>
          <Button onClick={submit} loading={create.isPending}>{t('create')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
```

The `Button loading` prop shows a spinner without changing width; the dialog is not dismissible while
`create.isPending`; and close happens only on the mutation's `onSuccess`, never on the click itself —
so a slow or failing network never leaves the dialog closed over an unfinished write.

## `ConfirmDialog` — the shared confirmation implementation

The dozens of confirmations across modules never hand-roll `AlertDialog` markup; they compose one
`components/shared/confirm-dialog.tsx`, which folds the plain-confirm, destructive, and type-to-confirm
variants into one component driven by props:

```tsx
// components/shared/confirm-dialog.tsx
'use client';

import { useState } from 'react';
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription,
         AlertDialogFooter, AlertDialogCancel, AlertDialogAction } from '@/components/ui/alert-dialog';
import { Input } from '@/components/ui/input';
import { useTranslations } from 'next-intl';
import { cn } from '@/lib/utils';

export function ConfirmDialog({
  open, onOpenChange, title, description, confirmLabel, tone = 'default', confirmToken, onConfirm,
}: ConfirmDialogProps) {
  const t = useTranslations('dialogs');
  const [token, setToken] = useState('');
  const [busy, setBusy] = useState(false);
  const tokenSatisfied = !confirmToken || token.trim() === confirmToken;

  async function confirm() {
    setBusy(true);
    try { await onConfirm(); onOpenChange(false); setToken(''); }
    finally { setBusy(false); }                     // stays open + re-enabled if onConfirm threw
  }

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          {description && <AlertDialogDescription>{description}</AlertDialogDescription>}
        </AlertDialogHeader>

        {confirmToken && (
          <div className="space-y-1.5">
            <label htmlFor="confirm-token" className="text-caption text-ink-500">
              {t('typeToConfirm', { token: confirmToken })}
            </label>
            <Input id="confirm-token" value={token} onChange={(e) => setToken(e.target.value)}
                   autoComplete="off" dir="auto" aria-describedby="confirm-token-hint" />
          </div>
        )}

        <AlertDialogFooter>
          <AlertDialogCancel disabled={busy}>{t('cancel')}</AlertDialogCancel>
          <AlertDialogAction
            onClick={confirm}
            disabled={!tokenSatisfied || busy}
            aria-describedby={!tokenSatisfied ? 'confirm-token-hint' : undefined}
            className={cn(tone === 'destructive' && 'bg-danger text-white hover:opacity-90')}
          >
            {confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
```

One component covers all three confirmation shapes: omit `confirmToken` and `tone` for a plain confirm;
pass `tone="destructive"` for a red confirming action; pass `confirmToken` to require type-to-confirm.
The disabled confirm always carries an `aria-describedby` pointing at the token hint, so assistive tech
reads *why* it is disabled — never a silently dead button.

## Form dialog vs. intercepted-route modal

QAYD has two mechanically different things that both look like "a form in a modal," and they must not be
confused. A **form dialog** (this document) is a `Dialog` mounted inside a screen for a short create/edit
that has *no URL of its own* — a "New cost center" that only ever makes sense from the cost-centers list.
An **intercepted-route modal** (`../NAVIGATION_SYSTEM.md → Routing`) is a real route (`journal-entries/new`)
that Next.js *presents* as a modal on an in-app soft navigation and as a full page on direct navigation,
bookmark, or shared link — the URL is canonical and the modal is a presentation nicety on top of it.

The rule: **if the surface needs to be deep-linkable, it is an intercepted route, never a form dialog.**
A form dialog's open state is not in the URL and cannot be shared; that is exactly why it is reserved for
interactions no one would ever bookmark. When in doubt, an authoring surface is a route with a modal
presentation, not a dialog — which also means the "quick create" and the "full page" share one data path
and one validation, never two that could disagree.

## Focus trap, focus return, scroll lock

All three come from Radix and are **preserved, never stripped** when restyling (`COMPONENT_LIBRARY.md →
Foundations`):

- **Focus trap.** While open, Tab cycles within the dialog only; the content behind is `aria-hidden` and
  inert to Tab (Radix marks the portal siblings automatically). There is no manual trap to maintain.
- **Focus return.** On close, focus returns to the element that opened the dialog (the trigger), by
  default, because the trigger stays mounted. The one case that needs `onCloseAutoFocus` is when the
  trigger itself was removed by the action (e.g. a row's "Delete" that also removes the row) — then the
  handler redirects focus to a stable nearby anchor (the table's toolbar) rather than letting focus fall
  to `<body>`.
- **Scroll lock.** Radix locks `<body>` scroll while a dialog is open (the document body never scrolls
  behind a modal, `../LAYOUT_SYSTEM.md`); the dialog's own body scrolls internally if its content
  overflows, via an `overflow-y-auto` region between a pinned header and footer, so a long form never
  pushes its action buttons off-screen.

## ESC and backdrop dismissal — blocked for dirty forms

The dismissal policy is the one place QAYD overrides Radix's defaults deliberately:

- A **confirmation / destructive `AlertDialog`** is never backdrop-dismissible (Radix construction) and
  its `Esc` maps to Cancel, never to an implicit confirm.
- A **clean `Dialog`** (an unedited form, an AI review the user only read) dismisses freely on ESC and
  backdrop — cheap to reopen, nothing to lose.
- A **dirty `Dialog`** (a form with unsaved edits) intercepts both ESC and backdrop in `onOpenChange`
  and, instead of closing, raises a nested "Discard changes?" `AlertDialog`. Confirming discards and
  closes both; canceling returns to the still-open form with its edits intact. This is the only
  auto-generated nested dialog in the system, and it exists so a reflexive `Esc` never silently throws
  away typed work:

```tsx
function guardedOpenChange(next: boolean, dirty: boolean, setDiscardOpen: (o: boolean) => void, onOpenChange: (o: boolean) => void) {
  if (!next && dirty) { setDiscardOpen(true); return; }   // intercept the close, raise the guard
  onOpenChange(next);
}
```

## Destructive & type-to-confirm

For an irreversible action the server treats as final — posting to the ledger, closing/locking a fiscal
period, voiding a posted entry — a plain "are you sure" is too easy to click through. The dialog upgrades
to **type-to-confirm**: the confirm button stays disabled until the user types an exact token (the
period name, the entry number). This matches the platform's "every destructive step is explicit and
audited" discipline and makes muscle-memory double-clicks impossible:

```tsx
// Closing a fiscal period — irreversible; requires typing the period name.
<AlertDialog open={open} onOpenChange={onOpenChange}>
  <AlertDialogContent>
    <AlertDialogHeader>
      <AlertDialogTitle>{t('closePeriodTitle', { period: period.name_en })}</AlertDialogTitle>
    </AlertDialogHeader>
    <p className="text-body text-ink-700">{t('closePeriodConsequence')}</p>
    <label className="text-caption text-ink-500" htmlFor="confirm-token">
      {t('typeToConfirm', { token: period.name_en })}
    </label>
    <Input id="confirm-token" value={token} onChange={(e) => setToken(e.target.value)} autoComplete="off" />
    <AlertDialogFooter>
      <AlertDialogCancel>{t('cancel')}</AlertDialogCancel>
      <AlertDialogAction
        disabled={token.trim() !== period.name_en || close.isPending}
        onClick={async () => { await close.mutateAsync(period.id); onOpenChange(false); }}
      >
        {t('closePeriod')}
      </AlertDialogAction>
    </AlertDialogFooter>
  </AlertDialogContent>
</AlertDialog>
```

The server re-checks that the period is closable and that the user holds `accounting.period.lock`
regardless — the type-to-confirm is a UX gate against accidental clicks, never the authority.

## AI-proposal review dialog

When a proposal is reviewed in a modal (as opposed to inline on a card or in the [Approval
Drawer](./DRAWERS.md)), the dialog renders the full AI contract — never a bare "approve":

```tsx
<Dialog open={open} onOpenChange={onOpenChange}>
  <DialogContent size="md">
    <DialogHeader><DialogTitle>{decision.recommendedAction}</DialogTitle></DialogHeader>

    <div className="flex items-center gap-2">
      <ConfidenceBadge confidence={normalizeConfidence(decision.confidenceScore, 'percentage')} reasoning={decision.reasoning} />
    </div>
    <ReasoningDisclosure reasoning={decision.reasoning} sources={decision.sources} />  {/* collapsed by default */}

    <DialogFooter>
      <Button variant="ghost" onClick={() => setDismissOpen(true)}>{t('dismiss')}</Button>
      <Button variant="outline" onClick={onSendForApproval}>{t('sendForApproval')}</Button>
      {decision.autonomyLevel === 'auto' && (
        <Button disabled={confidence < AUTO_EXECUTE_THRESHOLD} onClick={onAccept}>{t('doIt')}</Button>
      )}
    </DialogFooter>
  </DialogContent>
</Dialog>
```

The three actions mirror `AIProposalPanel`'s exactly (`COMPONENT_LIBRARY.md`): "Do it" renders only when
`autonomyLevel === 'auto'` **and** is disabled below the confidence threshold — a low-confidence output
may be *shown* but can never be the thing that executes itself; "Send for approval" hands off to the
normal approval flow; "Dismiss" requires a reason (a nested reason dialog) stored as `rejected_reason`
and fed back into `ai_memory`. Reviewing is non-destructive, so the outer review dialog dismisses freely
— it is the *decision*, not the reading of it, that the platform guards.

## Mounting, portal, and z-index

Every dialog portals to the document root (`#portal-root`), outside the shell's DOM subtree, so no
sticky table header, topbar, or sidebar can clip or trap it. The scale (`../LAYOUT_SYSTEM.md`):

| Layer | Token | Value |
|---|---|---|
| Modal scrim | `--z-modal-scrim` | 50 |
| Modal panel | `--z-modal` | 51 |
| Command palette | `--z-command-palette` | 60 — always above any open modal |
| Toast | `--z-toast` | 70 — a success/error toast survives a dialog's close |
| Tooltip | `--z-tooltip` | 80 — never occluded, even inside a dialog |

Modals sit **above** drawers (`--z-drawer` 41) intentionally: a confirmation raised *from within* a
drawer (confirming a delete inside a detail panel) must stack over that drawer, which the z-tiers
guarantee without any manual ordering.

## Stacked / nested dialogs

QAYD permits stacking but constrains it hard. The two legitimate stacks are: a **confirmation raised
from within a form dialog or a drawer** (the discard guard; a delete-confirm inside a detail panel), and
a **reason/second-step `AlertDialog` from an AI review** (the dismiss-reason prompt). Each Radix `Root`
manages its own focus scope, so the top-most layer owns the active trap and closing it returns focus to
the layer beneath — never out to the page (`../ACCESSIBILITY.md`). What is **not** permitted: a form
dialog opening another form dialog to author a related record (opening "New Customer" from a "New
Invoice" modal). That is the signal the parent should have been a route or a drawer in the first place —
a two-deep authoring stack is a decision-matrix violation, caught in review, not styled around.

# Accessibility

Dialogs inherit Radix's full accessibility contract; QAYD's rules are what must never be broken on top:

| Concern | Contract |
|---|---|
| Role & modality | `role="dialog"` (or `alertdialog`) with `aria-modal="true"`, both provided by Radix. |
| Accessible name | Every dialog has a `DialogTitle` wired via `aria-labelledby` — a titleless dialog is a lint-time failure, never shipped. A visually-hidden title is used where the design shows no visible heading (rare). |
| Description | The consequence/instruction text is a `DialogDescription` wired via `aria-describedby`, so a screen reader reads *what the action does* before the user reaches the confirm button. For a destructive action, this is where the irreversibility is stated in words, not conveyed by a red button alone. |
| Focus trap & return | Radix `FocusScope` traps focus while open and returns it to the trigger on close; QAYD only overrides via `onCloseAutoFocus` when the trigger was unmounted by the action. |
| Content behind | `aria-hidden` + inert, automatic — a screen-reader user cannot wander into the frozen page beneath. |
| Keyboard | `Esc` closes a `Dialog` (subject to the dirty guard) and cancels an `AlertDialog`; `Tab`/`Shift+Tab` cycle within; `Enter` activates the focused action. The primary action is reachable without a mouse in every dialog. |
| Disabled confirm has a reason | A confirm button disabled by a precondition (empty reason, unmatched type-to-confirm token) exposes *why* via `aria-describedby` pointing at the token/reason field's label — never a silently disabled button (`../ACCESSIBILITY.md`). |
| Color is never the only signal | A destructive dialog's danger is carried by the `DialogDescription` wording and the action verb, not by button color alone — so it reads correctly in grayscale and for colorblind users. |
| Reduced motion | The scrim fade and panel scale-in are honored under `prefers-reduced-motion: reduce` — the dialog appears/disappears instantly rather than with a faster animation. |

# Theming, Dark Mode & RTL

**Tokens only.** The scrim is `bg-ink-950/40`, the panel `bg-surface-raised` with `--qayd-shadow-lg` and
`--qayd-radius-lg`; the destructive action uses the `danger` token via `Button variant="destructive"`.
No raw hex, px, or `dark:`-raw-color variant appears in any dialog source.

**Dark mode.** `bg-surface-raised` is a distinct dark token (`#141F1B`), never a tint of the canvas, so a
dialog reads as clearly elevated over the darkened page beneath; the scrim opacity is re-tuned (not
inverted) under `data-theme="dark"` so the elevation contrast holds. Applied by the shell's `next-themes`
provider (`../THEMING.md`); dialogs need no dark branch of their own.

**RTL.** Set once at the root. Because dialogs use logical layout — `DialogFooter` orders Cancel then the
primary action in *source* order (so the primary lands at the visual inline-end and flips correctly in
RTL), padding is `p-*` symmetric or `ps-*/pe-*`, the close `✕` sits at the inline-end corner
(`inset-inline-end`) — nothing needs an `if (locale === 'ar')` branch. Two explicit pins: any **numeral
or amount** shown in a confirmation ("post 1,050.000 KWD") renders through `AmountCell` (`dir="ltr"`,
Western Arabic numerals), never reversed digit-by-digit; and a **type-to-confirm token** that is a Latin
string (an entry number "JE-2026-07-0482") is compared and displayed `dir="ltr"` so the user types it
in the same visual order it is shown, regardless of the surrounding Arabic.

# i18n

Every dialog string is a key in both dictionaries, sharing one `Dictionary` type (a one-sided key fails
`npm run i18n:check`). Dialog copy is never concatenated from fragments — a full sentence with
interpolation is one key, so Arabic word order is correct rather than English-shaped:

| Key | English | Arabic |
|---|---|---|
| `dialogs.cancel` | Cancel | إلغاء |
| `dialogs.discardTitle` | Discard changes? | تجاهل التغييرات؟ |
| `dialogs.discardBody` | Your unsaved changes will be lost. | ستفقد التغييرات غير المحفوظة. |
| `dialogs.discardConfirm` | Discard | تجاهل |
| `costCenters.typeToConfirm` | Type "{token}" to confirm | اكتب «{token}» للتأكيد |
| `accounting.closePeriodConsequence` | Closing this period locks all its entries from further edits and cannot be undone. | إغلاق هذه الفترة يقفل جميع قيودها من التعديل ولا يمكن التراجع عنه. |

Amounts, dates, and tokens embedded in a string are passed as `AmountCell`/`FormattedDate` React nodes
or `dir="ltr"` spans via interpolation, never formatted into the string by hand, so the numeral-system
and direction rules hold inside translated copy.

# Testing

**Storybook** (`components/ui/dialog.stories.tsx`, `components/shared/confirm-dialog.stories.tsx`)
renders each size and kind with the `direction`/`theme` decorators:

```tsx
export const ConfirmDefault: StoryObj = { args: { title: 'Post entry?', confirmLabel: 'Post' } };
export const ConfirmDestructive: StoryObj = { args: { title: 'Void JE-2026-07-0482?', confirmLabel: 'Void', tone: 'destructive' } };
export const TypeToConfirm: StoryObj = { args: { title: 'Close July 2026?', confirmLabel: 'Close period', confirmToken: 'July 2026' } };
export const FormMd: StoryObj = { args: { size: 'md' } };
export const FullOnMobile: StoryObj = { args: { size: 'full' }, parameters: { viewport: 'mobile1' } };
export const RTL: StoryObj = { args: { title: 'ترحيل القيد؟', confirmLabel: 'ترحيل' }, parameters: { direction: 'rtl' } };
export const Dark: StoryObj = { parameters: { theme: 'dark' } };
```

**Vitest + Testing Library** covers the behavior-bearing logic: the dirty-form discard guard, the
type-to-confirm gate, and the mutation-driven close:

```tsx
test('type-to-confirm keeps the confirm button disabled until the exact token is typed', () => {
  render(<ConfirmDialog open title="Close July 2026?" confirmLabel="Close period" confirmToken="July 2026" onConfirm={vi.fn()} onOpenChange={vi.fn()} />);
  expect(screen.getByRole('button', { name: /close period/i })).toBeDisabled();
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'July 2026' } });
  expect(screen.getByRole('button', { name: /close period/i })).toBeEnabled();
});

test('a dirty form dialog raises the discard guard instead of closing on Esc', () => {
  render(<NewCostCenterDialog open onOpenChange={vi.fn()} />);
  fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Marketing' } });
  fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
  expect(screen.getByText(/discard changes/i)).toBeInTheDocument();  // still open, guard shown
});
```

**Playwright** covers the flows too stateful for a component test: the full post-confirmation lifecycle
(open confirm → mutation pending disables dismissal → success closes + toast), a `409 version_conflict`
on an edit dialog surfacing the non-destructive "this changed since you opened it" banner without
discarding edits, and the focus-return path (open from a row action, close, assert focus is back on the
trigger).

**Accessibility CI gate.** `axe-core` runs against every dialog story; a manual keyboard pass verifies
`Esc`/Tab/focus-return and that every disabled confirm has an `aria-describedby` reason, since automated
checks catch a missing `aria-modal` but not a broken discard guard.

# Edge Cases

| # | Scenario | Resolution |
|---|---|---|
| 1 | User hits `Esc` on a form dialog with unsaved edits. | Intercepted in `onOpenChange`; a "Discard changes?" `AlertDialog` stacks over it. Confirm discards + closes both; Cancel returns to the intact form. A clean (unedited) dialog closes freely. |
| 2 | The submit mutation fails with field errors. | The dialog stays open; `ApiError.errors[].field` maps onto the form's field errors via `toast.fromApiError`; the action re-enables. This recoverability is exactly why an error-recoverable form is a `useMutation`, never a Server Action that would remount the dialog. |
| 3 | An optimistic edit hits `409 version_conflict`. | The mutation discards the optimistic cache update, refetches the authoritative record, and the dialog surfaces "this changed since you opened it — your edits are preserved below, re-apply them," never silently overwriting or discarding the user's work (`COMPONENT_LIBRARY.md → Edge Cases`). |
| 4 | A mutation is in flight and the user clicks the backdrop. | `dismissible={false}` while pending — the backdrop click is ignored, so a slow network can never orphan an in-flight write behind a closed dialog. |
| 5 | Someone tries to open a second authoring modal from a form modal ("New Customer" from "New Invoice"). | Disallowed by the decision matrix — the parent should be a route or drawer. Caught in review; the pattern is not styled around. The only sanctioned stacks are the discard guard, a delete-confirm, and an AI dismiss-reason prompt. |
| 6 | A confirmation is raised from inside an open [drawer](./DRAWERS.md). | The modal's `--z-modal` (51) sits above the drawer's `--z-drawer` (41), so it stacks correctly with no manual ordering; closing it returns focus into the drawer, not to the page. |
| 7 | A type-to-confirm token is a Latin entry number typed in an Arabic (RTL) session. | The token input and its comparison are `dir="ltr"`, so the user types "JE-2026-07-0482" in the same visual order it is displayed; the match is exact-string, unaffected by surrounding direction. |
| 8 | The dialog's body content overflows a short viewport. | The header and footer are pinned; the body region scrolls internally (`overflow-y-auto`) so the primary action is never pushed off-screen; `<body>` behind stays scroll-locked. On a phone, a form that would overflow uses `size="full"` instead. |
| 9 | A success toast fires as the dialog closes. | The toast portals at `--z-toast` (70), above the modal tier, so it renders and persists after the dialog unmounts rather than being clipped by the closing panel. |
| 10 | Reduced-motion user opens a dialog. | Scrim and panel appear instantly (no scale/fade); all functional state (focus move, scroll lock, trap) is unchanged — motion is removed, behavior is not. |
| 11 | The trigger that opened the dialog is removed by the dialog's own action (a row "Delete"). | `onCloseAutoFocus` redirects focus to a stable anchor (the list toolbar) so focus never falls to `<body>` after the trigger unmounts. |
| 12 | An AI review dialog for a proposal whose confidence is below the auto-execute threshold. | The dialog renders normally and shows the confidence + reasoning, but the "Do it" action is `disabled` (not merely styled) — the proposal can be read and sent for approval, never auto-executed on a click. |

# End of Document
