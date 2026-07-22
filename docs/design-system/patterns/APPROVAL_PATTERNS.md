# Approval Patterns — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / APPROVAL
---

# Purpose

This document specifies the reusable **approval UX patterns** QAYD composes from atomic components: the
AI-proposal review card, the Approval Drawer, the approve/reject/delegate action bar, the two-key chain
(a `bank_transfer`'s stepped sign-off) UI, bulk-approval with its sensitivity limits, the confidence +
reasoning + source-citation block every AI-originated request carries, the delegation picker, and the
audit-trail surfacing that makes a decision provable for the life of the tenant. A *pattern* here is a
recurring composed shape — a stack of `ConfidenceBadge`, `ReasoningPanel`, `ApprovalStepper`,
`ApprovalActionBar`, `Sheet`, `AlertDialog`, and `DelegatePicker` primitives arranged the same way every
time — not a new primitive.

Every approval pattern exists to make one platform rule structurally true: **AI proposes; a human, or an
explicit and audited policy, approves.** A request is in the queue *precisely because* it did not clear
its company's autonomy threshold, or because its subject type is one QAYD never auto-executes. On this
surface the only vocabulary is **Approve, Reject, Request Changes, Delegate** — **"Do it" never renders
here**, for any row, at any confidence. Every act on this surface is additionally gated by `ai.approve`
(plus the step's own permission).

This is the design-system, token-and-shape companion to the specs that own the behavior. The end-to-end
approval journey, its chain resolution, every endpoint and status transition are owned by
[`../../frontend/flows/APPROVAL_FLOW.md`](../../frontend/flows/APPROVAL_FLOW.md); the `ApprovalDrawer`
primitive, its focus/scroll/ESC contract, and the modal-vs-drawer-vs-route decision are owned by
[`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md); the atomic
confidence/reasoning/action-bar grammar these patterns inherit is owned by
[`../components/AI_WIDGET.md`](../components/AI_WIDGET.md). Where this document is silent on behavior,
those govern; this document adds only the **pattern layer** — how the primitives stack, which canonical
token paints each part, and how the human-in-the-loop guarantee is upheld in composition.

Every color, radius, and motion value references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the warm
`ink-1…12` scale, the single brass `accent` (the one Approve action + AI provenance, **never** status),
and the `positive`/`negative`/`warning` financial set. There is no "AI purple" and no approval-specific
palette.

> **Token-reconciliation note.** The app-level docs this pattern ties to were authored against an earlier
> numeric-step palette (`accent-600`, `ink-500`, `ink-700`, `ink-950`, `ink-150`, `text-caption`,
> `surface-raised`, `destructive-quiet`). This document uses the **canonical** names from
> [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md): `accent`/`accent-subtle`, `ink-1…ink-12`,
> `positive`/`negative`/`warning`, the `text-*` type steps, and `surface-1` for a raised panel. Read a
> draft name as its canonical equivalent (`accent-600` → `accent`, `ink-500` → `ink-9`, `ink-700` →
> `ink-10`, `ink-950`/`ink-12` → `ink-12`, `ink-150` → `ink-6`, `text-caption` → `text-xs`,
> `surface-raised` → `surface-1`, `destructive-quiet` → a quiet `negative` Reject). "Reject uses a quiet
> `negative`, never the loud `destructive`" — the loud destructive is reserved for permanent deletion.

# When to Use

Use the approval patterns whenever a proposed action **must be decided by a human before it takes
effect** — an AI recommendation that did not clear its autonomy threshold, a `bank_transfer` or
`payroll_release` or `tax_submission` that QAYD never auto-executes, a human-submitted document needing
sign-off, or a `FRAUD_DETECTION` hold. Reach for the **proposal patterns** (the three-button
`AIProposalCard`) instead when the action *can* be executed directly and the human choice is still "act
now / send for approval / dismiss"; reach for a plain **confirmation modal** when a single user confirms
their own reversible action with no chain and no delegation.

| Use an approval pattern | Use something else |
|---|---|
| A proposal crossed from "AI could act" to "a human must decide" | A proposal the human can still `Do it` on → `AIProposalCard` |
| A multi-tier sign-off chain (`bank_transfer` two-key) | A single-user self-confirm → confirmation modal |
| A held/flagged item a reviewer must clear | A read-only AI answer → `InsightCard` |
| A queue a reviewer clears while keeping context visible | A one-off focused decision from a notification → an AI-review modal (same record, same mutation) |

The tie-breaker for the *surface*: if the reviewer benefits from the **queue staying visible** behind
each decision, use the **Approval Drawer**; if the value is distraction-free focus on **one** decision,
use an AI-review modal. Both decide the same record through the same mutation — QAYD never has two
approval paths that could disagree.

# Anatomy

Every approval surface stacks the same envelope, so "a human is deciding this" reads identically whether
it renders as a queue card, a drawer, or an embedded chat card.

```
┌─ ApprovalRequest envelope ──────────────────────────────────────────────┐
│  Title · amount (plain ink, never washed negative)     SLA / urgency      │  ← header
│  * AI  [◕ 96% High confidence]   ◐ Fraud Detection                        │  ← provenance (AI rows only)
│                                                                           │
│  ▸ Why this?  (ReasoningPanel → reasoning + cited source records)         │  ← evidence, pull not push
│                                                                           │
│  ● Step 1 · Finance Manager · pending   ○ Step 2 · CEO · waiting          │  ← ApprovalStepper (the chain)
│                                                                           │
│              [ Delegate ]   [ Reject ]   [ Approve ]                       │  ← ApprovalActionBar
└───────────────────────────────────────────────────────────────────────────┘
```

| Part | Primitive | Rule |
|---|---|---|
| Header | title + `AmountCell` + SLA chip | Amount is plain `ink-12` tabular numerals; a raw magnitude is never washed `negative` |
| Provenance | `ConfidenceBadge` + `AgentAttributionChip` | AI-originated rows only; a human-submitted row omits confidence entirely, never a fake "N/A" |
| Evidence | `ReasoningPanel` over `sources[]` | Collapsed by default; each source a real drill-through `<Link>` to the grounding record |
| Chain | `ApprovalStepper` | Renders exactly the `steps[]` the API returns; only the viewer's own pending step is interactive |
| Decision | `ApprovalActionBar` | Approve (one filled `accent`), Reject (quiet `negative`, reason-gated), Delegate (`ghost`, scoped) |
| Held brake | `negative` leading edge, pinned | A `FRAUD_DETECTION` hold; `negative`, not accent — "AI-touched" and "urgent" stay two signals |

The leading edge is `border-s-4` (inline-start in both LTR and RTL). AI provenance is `accent`; severity
(a critical item, a hold) is `warning`/`negative` — an independent signal that never rides the accent.

# Variants

| Axis | Values | Effect |
|---|---|---|
| Surface | queue card / Approval Drawer / detail route / embedded chat card | Same `ApprovalRequest` shape, same `approvalKeys.detail(id)` cache, same mutation — no two surfaces disagree |
| Origin | AI-originated / human-submitted | AI rows carry `ConfidenceBadge` + `ReasoningPanel`; human rows omit confidence, keep the same action bar |
| Chain | single-step / multi-step (two-key) | `ApprovalStepper` renders the returned steps; a sensitive `bank_transfer` uses a stepped `stepPermission` gate |
| Severity | `none` / `held` | A `held` row gets a `negative` edge, pins ahead of same-bucket items, and is lifted only by re-evaluation |
| Decision verb | Approve / Reject / Request Changes / Delegate | Reject terminates the chain; Request Changes sends back; Delegate reassigns with attribution |
| Bulk | single / bulk | Bulk excludes sensitive keys + `held` rows, requires one shared `subjectType`, and is never optimistic |

# Composition

## The AI-proposal review card + action bar

The review card is `AiCardShell` (or the drawer body) carrying the provenance, the evidence, the chain,
and the `ApprovalActionBar`. Approve is the one filled `accent` action; Reject opens a mandatory-reason
dialog; Delegate renders only for the assigned approver.

```tsx
// The approve/reject/delegate cluster. "Do it" is structurally absent on this surface.
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { RejectDialog } from '@/components/ai/reject-dialog';
import { DelegatePicker } from '@/components/approvals/delegate-picker';
import { usePermission } from '@/hooks/use-permission';
import { Check, X, UserPlus } from 'lucide-react';

export function ApprovalActionBar({ permissionKey, onApprove, onReject, onDelegate, disabledReason }: ApprovalActionBarProps) {
  const canDecide = usePermission(permissionKey);      // ai.approve, bank.payment.approve.final, …
  const blocked = !canDecide || Boolean(disabledReason);
  const blockedTip = disabledReason ?? `Requires ${permissionKey}`; // permission-block vs business-block kept distinct
  const [rejectOpen, setRejectOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  return (
    <div className="flex items-center justify-end gap-2">
      {onDelegate && (
        <DelegatePicker onDelegate={onDelegate} disabled={blocked}>
          <Button variant="ghost" size="sm"><UserPlus className="h-3.5 w-3.5" /> Delegate</Button>
        </DelegatePicker>
      )}
      {/* quiet negative, never the loud destructive reserved for permanent deletion */}
      <Button variant="destructive-quiet" size="sm" disabled={blocked} onClick={() => setRejectOpen(true)}>
        <X className="h-3.5 w-3.5" /> Reject
      </Button>
      <Tooltip>
        <TooltipTrigger asChild>
          <span>
            <Button size="sm" loading={busy} disabled={blocked}
                    aria-describedby={blocked ? 'approve-blocked' : undefined}
                    onClick={async () => { setBusy(true); await onApprove(); setBusy(false); }}>
              <Check className="h-3.5 w-3.5" /> Approve
            </Button>
          </span>
        </TooltipTrigger>
        {blocked && <TooltipContent id="approve-blocked">{blockedTip}</TooltipContent>}
      </Tooltip>
      <RejectDialog open={rejectOpen} onOpenChange={setRejectOpen} onConfirm={onReject} />
    </div>
  );
}
```

## The confidence + reasoning + source-citation block

Every AI-originated request renders confidence (normalized once, `0–1`), a collapsed `ReasoningPanel`,
and its `sources[]` as **real drill-through links** — never a bare summary invented for the screen. A
reviewer can open the reasoning, click a cited transaction, confirm the agent read it right, and *then*
approve — the entire point of surfacing sources rather than just a number.

```tsx
// ReasoningPanel sources — each citation resolves to a real, permission-checked route.
import Link from 'next/link';
import { FileText } from 'lucide-react';
import { sourceHref } from '@/lib/ai/source-href';

export function ReasoningSources({ sources }: { sources: Source[] }) {
  if (sources.length === 0) return null;
  return (
    <ul className="mt-2 space-y-1" role="list">
      {sources.map((s, i) => (
        <li key={i}>
          <Link href={sourceHref(s)} className="flex items-center gap-1.5 text-xs text-accent hover:underline">
            <FileText className="h-3.5 w-3.5 shrink-0" aria-hidden />
            <span dir="auto" className="truncate">{s.label}</span>{/* mixed-language citation reads in its own direction */}
          </Link>
        </li>
      ))}
    </ul>
  );
}
```

A human-submitted row (`confidenceScore: null`) simply omits the `ConfidenceBadge` — never a fake or
zero-confidence badge.

## The Approval Drawer

The drawer is the everyday queue-clearing surface: it decides one `ApprovalRequest` **in place while the
queue stays visible behind it**. It holds five contracts, each a platform rule made concrete — the AI is
never silent; approve/reject/delegate never auto-commit; Reject always carries a reason; the per-step
gate wins over the request-level key; Delegate is scoped to eligible approvers.

```tsx
// The drawer footer: per-step gate resolution + the reason-gated reject. (Body: provenance + reasoning + stepper.)
const currentStep = request.steps.find((s) => s.status === 'pending');
const gate = currentStep?.stepPermission ?? request.requiredPermission;      // finer step gate wins
const canDecide = usePermission(gate) && currentStep?.approverUserId != null; // assigned to THIS viewer's step
const confidence = request.confidenceScore != null
  ? normalizeConfidence(request.confidenceScore, 'percentage')
  : null; // null for a human-submitted row → no badge

<Sheet open={open} onOpenChange={onOpenChange} width="md" dismissible={!busy}>
  <SheetContent> {/* surface-1: bg raised over the darkened page, dimmer scrim than a modal's */}
    <SheetHeader><SheetTitle>{request.title}</SheetTitle></SheetHeader>
    {request.amount && <AmountCell amount={request.amount} currencyCode={request.currencyCode!} emphasis="strong" showCurrency />}
    {confidence != null && <ConfidenceBadge confidence={confidence} reasoning={request.reasoning ?? undefined} />}
    {(request.reasoning || request.sources.length > 0) && <ReasoningDisclosure reasoning={request.reasoning} sources={request.sources} />}
    <ApprovalStepper steps={request.steps} />
    <SheetFooter>
      <ApprovalActionBar permissionKey={gate} onApprove={onApprove} onReject={onReject}
                         onDelegate={onDelegate} disabledReason={!canDecide ? 'Not your step' : undefined} />
    </SheetFooter>
  </SheetContent>
</Sheet>
```

A reject-reason `AlertDialog` stacks **above** the drawer (`z-modal` > `z-drawer`) and disables Confirm
until the reason is non-empty. On close it returns focus into the drawer, never to the page top.

## The two-key chain UI

A `bank_transfer`'s decision gate is finer than its request-level fallback: `ApprovalStep` carries a
`stepPermission` checked *in place of* the request's own key for that one step — step 1 by
`bank.payment.approve`, the final tier by `bank.payment.approve.final`. The `ApprovalStepper` renders
each step's role + status as **real text** ("Step 1 of 2 · Finance Manager · pending"), never a
color-only progress bar; only the reviewer whose step is `pending` sees an interactive Approve; everyone
else sees that step read-only with a tooltip naming the outstanding approver.

```tsx
// ApprovalStepper — the chain state is words, not hue. Colorblind/grayscale reviewers read it fully.
export function ApprovalStepper({ steps }: { steps: ApprovalStep[] }) {
  return (
    <ol role="list" className="space-y-2">
      {steps.map((s, i) => (
        <li key={s.id} className="flex items-center gap-2 text-sm">
          <StepDot status={s.status} aria-hidden /> {/* glyph, not the sole signal */}
          <span className="text-ink-11">Step {i + 1} of {steps.length} · {s.approverRoleLabel}</span>
          <span className="text-xs text-ink-9">· {s.statusLabel}</span>{/* "pending" / "approved" / "waiting" as text */}
        </li>
      ))}
    </ol>
  );
}
```

## The delegation picker

Delegate renders only when the viewer is the assigned approver for the current pending step — never a
general "reassign anyone's step" affordance. The `DelegatePicker` is a `Command`+`Popover` combobox
scoped to users who already hold the step's `approver_role` or an equivalent grant; the API rejects an
ineligible target with a `422`, and the picker filters client-side so an ineligible name is never offered.
A delegated step records **both** the original approver and the delegate — reassignment with attribution,
never an anonymous hand-off.

## Bulk approval with sensitivity limits

Selecting rows surfaces a Bulk Action Bar with the single bulk-safe verb ("3 selected · Approve ·
Clear"). A row's checkbox is **disabled** (with a tooltip) whenever its `requiredPermission` is one of
`bank.transfer` / `payroll.run.approve` / `tax.submit`, or whenever it is `held` — the
`SENSITIVE_PERMISSIONS` gate. A valid selection must share one `subjectType` (the Approve button is
*absent*, not disabled, for a mixed-type selection) and sit below that type's value threshold. Approve
opens one `AlertDialog` naming the exact count and total before firing. **Bulk approve is never
optimistic** — a multi-row financial decision — and every id is still individually audit-logged.

## Audit-trail surfacing

Every decision (who, when, reasoning, delegation attribution) writes to `audit_logs` and is retrievable
there in full — the durable source of truth, not a print of this screen's chrome. A compliance-grade
export is a separate `GET /api/v1/approvals/export` (permission `reports.export`), always rendered in a
fixed **light/print palette** regardless of the requester's in-app theme.

# States & Behavior

| Region | Default | Loading | Held / conflict | Degraded | Disabled (RBAC / business) |
|---|---|---|---|---|---|
| Review card | Provenance + reasoning + stepper + action bar | Skeleton shaped to the card | `held` → `negative` edge, pinned; Approve present but hold lifted only by re-evaluation | AI-originated content degrades to plain queue row, decision still gated | Card read-only when the viewer lacks `ai.approve` |
| `ApprovalActionBar` | Approve / Reject / Delegate | Acting button `loading`; others locked | Realtime race → footer replaced by "Already {approved/rejected} by {name}" | — | Disabled + tooltip; permission-block and "not your step" kept textually distinct |
| `ApprovalStepper` | Steps as text, own step interactive | Skeleton rows | A step advanced elsewhere → re-fetch fresh, never trust stale local state | — | Non-turn steps read-only with a tooltip naming the outstanding approver |
| Reject dialog | Mandatory reason | — | — | — | Confirm disabled until reason non-empty |
| Bulk bar | "N selected · Approve · Clear" | Not optimistic; awaits result | `failed[]` names rows that changed eligibility → partial-success toast | — | Sensitive keys + `held` rows checkbox-disabled with a tooltip |

**Live-target re-check before Approve.** Immediately before rendering Approve, the client re-fetches the
live target and diffs it against the proposal payload; on divergence (an edited beneficiary IBAN, changed
PO lines) Approve is **replaced** with "Review changed data" — never a silent approval of stale intent.

**Optimism is calibrated to what the action does, not who proposed it.** The four decision verbs are
optimistic (reversible-adjacent — a wrong approval can still be caught at the next step, and none of the
verbs itself moves money); the **money-moving execution** the approval authorizes (Banking's transfer
initiation, Payroll's disbursement, Tax's file-return) is a separate, **pessimistic** mutation on the
module's own screen, reached through the *same* permission and the *same* terminal transition — two
screens, never two gates that could disagree.

**Realtime.** A `held` transition patches the one affected row in place and surfaces as a polite "1
request now held — Refresh" banner, never a focus-yanking splice. On a WebSocket reconnect,
`approvalKeys.all` is invalidated once as a batch — a missed hold is a correctness bug, never left to a
manual refresh.

**Motion.** The drawer enters under `motion.moderate` with `easeOut`; the reasoning disclosure expands
under `motion.base`; a `held`-row arrival flashes `accent-subtle`… actually `negative-subtle` and eases
back over ~600ms. Every animation reads `useReducedMotion()` and degrades to an instant state change.

# Content & Copy Guidance

- **The four verbs are exact and distinct.** "Approve," "Reject," "Request Changes" (send back for
  revision, ≠ kill it), "Delegate." Never "Do it" — that verb belongs to the proposal surface, not here.
- **Reject and Request Changes each demand a written reason/note** — the field is mandatory and specific;
  Confirm stays disabled until it is non-empty. For an AI-originated row the reason feeds `ai_memory`.
- **A held row states the brake, not just urgency:** "Held by Fraud Detection — vendor bank detail
  changed 2 hours before the scheduled run," so the reviewer knows *why* money is prevented from moving.
- **The chain reads as text:** "Approval 1 of 2," "Finance Manager · pending," "CEO · waiting" — never a
  bare colored bar.
- **A stale target is named, not silently approved:** "This record changed since it entered the chain —
  review changed data," replacing the Approve button.
- **AI reasoning arrives already localized** from the API; the client localizes only chrome (button
  labels, filter labels, empty states, tooltips) — it never machine-translates a model's words.
- **Bilingual microcopy is authored directly** in the professional Gulf register: *الاعتمادات*, *اعتماد /
  رفض / طلب تعديل / تفويض*, *لا يوجد شيء بانتظارك*.

# Accessibility

Target **WCAG 2.2 AA** in both themes and both directions.

- **Every disabled control explains itself, and the two reasons are never conflated.** A step's Approve
  disabled because it is not yet your turn, a checkbox disabled for a `SENSITIVE_PERMISSIONS` key, and a
  whole card read-only for a missing `stepPermission` are three distinct conditions, each with its own
  `aria-describedby` sourced from the permission system.
- **Confidence and chain state are never color alone.** The `ConfidenceBadge` carries its band by fill +
  shape + a real percentage/label; the `ApprovalStepper` renders "Step 1 of 2 · role · status" as real
  text — a grayscale or colorblind reviewer reads both from words.
- **The reasoning "why" is reachable before the action** — the action bar's Approve is `aria-describedby`
  the card's reasoning, and `ReasoningPanel` is a real `aria-expanded` disclosure whose sources are a
  tabbable `<ul>` of drill-through links.
- **Live regions are calibrated, never alarming.** A push flipping a row to `held` mid-review never yanks
  focus or re-sorts under the reader; it announces as a polite dismissible banner. The Bulk Action Bar's
  appearance announces once via `role="status"`.
- **Bulk selection is real, tri-state, identity-tied.** The header checkbox is `aria-checked="mixed"` for
  a partial selection; each row checkbox has an accessible name tied to the request.
- **Focus continuity.** The Reject/Request-Changes `AlertDialog`s trap focus and return it to the
  triggering card's action row on close; the `ApprovalStepper` is arrow-key navigable inside a
  `role="list"`; a swipe-to-decide gesture is additive — the Approve/Reject buttons are always the real,
  screen-reader/switch-control/reduced-motion path.
- **Landmarks.** One `<h1>` ("Approvals"); each urgency group is a real `<h2>` (via `VisuallyHidden`
  where no visible label exists) so a heading list enumerates the same groups a sighted user scans.

# Theming, Dark Mode & RTL

**Tokens only.** Approve is the one filled `accent`; Reject is a quiet `negative`; Delegate is `ghost`;
the drawer panel is `surface-1` with a scrim dimmer than a modal's; a held edge is `negative`; the
confidence badge resolves through the shared token layer. No approval surface contains a raw hex or a
`dark:` variant against a literal.

**Dark mode is a first-class remap, not an inversion.** The accent gets *lighter, not more saturated*, so
Approve holds contrast against the dark canvas instead of glowing; the `negative` held edge and the
`ConfidenceBadge` re-tune per theme, not linearly brighten. Two AI-specific rules hold in both themes:
the accent stays reserved for AI provenance and the one Approve action (a held/critical row is `negative`,
never the accent), and confidence indicators re-tune rather than over-saturate.

**RTL is one tree, mirrored by logical properties.** The Approval Drawer slides in from the right in
English and from the left in Arabic with zero conditional code (`side="end"` resolves from `dir`); the
`x` stays at the inline-end corner; the footer's Delegate/Reject/Approve order flips; the
`ApprovalStepper`'s directional arrow flips via `rtl:` while the held-row border, the check, and the AI
mark do not. Three things never mirror: every **monetary figure, percentage, confidence score, and SLA
countdown** renders inside a `dir="ltr"` `latn` span ("اعتماد 2 من 2" keeps Latin digits); a **Latin
source-citation label** in the reasoning renders `dir="auto"` so a mixed-language citation reads in its
own direction inside the Arabic panel; and the **compliance export** is always the fixed light/print
palette regardless of the in-app theme. Logical Tailwind utilities only (`ms/me`, `ps/pe`,
`text-start/end`); physical classes are ESLint-banned.

# Do / Don't

| Do | Don't |
|---|---|
| Offer only Approve / Reject / Request Changes / Delegate on this surface | Render "Do it" or any auto-execute affordance in an approval |
| Render confidence + reasoning + cited sources on every AI-originated row | Show a bare percentage, or a fake "N/A" badge on a human-submitted row |
| Make each citation a real drill-through `<Link>` to the grounding record | Print a dead label the reviewer must go hunt for |
| Re-check the live target before Approve; replace with "Review changed data" on divergence | Approve stale intent silently after the target changed |
| Check the per-step `stepPermission` in place of the request-level key | Offer a false path to decide someone else's chain step |
| Require a stored reason on every Reject / Request Changes | Let a rejection commit with an empty reason |
| Keep a `held` row `negative`, pinned, lifted only by re-evaluation | Wave a fraud hold through on an ordinary Approve, or color it with the accent |
| Scope Delegate to users who could legally decide the step | Delegate to someone who couldn't hold the step's permission |
| Exclude sensitive keys + `held` rows from bulk; keep bulk non-optimistic | Bulk-approve a `bank_transfer`, or optimistically flip a multi-row financial decision |
| Render the audit export in a fixed light/print palette | Leak the in-app dark theme into a compliance document |

# End of Document
