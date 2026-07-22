# Error-State Pattern — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / ERROR_STATES
---

# Purpose

This document specifies the **error-state pattern** — the composed way QAYD communicates *"we tried and it
failed."* It is a pattern doc, not a primitive: it does not re-specify a button, an alert glyph, or a toast,
but the arrangement of five error surfaces at five granularities — a page/region-level `ErrorState`, inline
field errors, a form-level error summary, toast errors, and the route boundaries `error.tsx`/`not-found.tsx`
— and the single mapping that ties them together: the typed `ApiError` envelope
(`{ success, message, errors, request_id }`) turned into the *right* UI at the *right* granularity.

It specializes the application-level contract in
[`../../frontend/components/ERROR_STATES.md`](../../frontend/components/ERROR_STATES.md) — that document owns
the `<ErrorState>` component, `useApiToast`, the `ApiError` type, and the boundary files. This pattern
document restates the arrangement in design-system terms: which semantic tokens tone it, how it composes with
`Button`, `Toast`, `FormMessage`, and `RequestIdRef`, and how it holds the line against its siblings — the
loading pattern ([`./LOADING_STATES.md`](./LOADING_STATES.md)) and the empty pattern
([`./EMPTY_STATES.md`](./EMPTY_STATES.md)). Keeping "failed" distinct from "empty" and "loading" is a
designed invariant; this doc is one third of it, and the success pattern
([`./SUCCESS_STATES.md`](./SUCCESS_STATES.md)) is the positive counterpart.

Errors in a financial product are high-stakes in a specific way: the user is often mid-action on money —
posting an entry, committing a reconciliation, approving a payment — and an error must tell them, calmly and
precisely, *what happened, why, and what to do next, in that order, without blaming them.* "This entry is
not balanced. Debits (KD 1,204.500) must equal credits (KD 1,150.000)," never "Oops, something went wrong."
Every error surface here carries that register, routes the four classes of failure — permission-denied,
validation, network, server — to distinct, appropriate UI, and surfaces the `request_id` on unexpected
failures so a support conversation starts with a correlation handle instead of a screenshot.

The governing principle is **fail at the smallest granularity that contains the failure.** A validation
error belongs on the field; a form-wide rule belongs in a form summary; a region's fetch failure belongs in
that region's `ErrorState` without taking down the page; only a genuinely page-fatal failure reaches
`error.tsx`. Collapsing a single widget's `4xx` into a full-page crash, or swallowing a real server error
into a silent empty table, are both defects.

Token-reconciliation note. The application doc uses draft names (`text-danger`, `ink-500`, `ink-950`). Per
[`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md), the **canonical** tokens here are the
financial-semantic set `negative`/`warning` (there is no separate "danger" token — `text-danger → text-negative`),
plus `ink-1 … ink-12` (`ink-500 → ink-9`, `ink-950 → ink-11`). Status color is desaturated and label-paired,
used on the *glyph*, never as a large surface fill.

# When to Use

The error pattern applies when a query or mutation settled with a **failure** — distinct from a settled
*empty* result and from an in-flight *loading* state. The three-way branch every data-bearing region runs:

| Condition | Truth | Pattern |
|---|---|---|
| `isPending`, no prior data | "We don't know yet" | Loading ([`./LOADING_STATES.md`](./LOADING_STATES.md)) |
| Settled, `data.length === 0` | "We know, and there's nothing" | Empty ([`./EMPTY_STATES.md`](./EMPTY_STATES.md)) |
| Settled, `isError` | **"We tried and failed"** | **Error (this doc)** |

Use it when:

- A **region's fetch** fails with a `500`/`502`, a network drop, or a `403` — render that region's
  `ErrorState` without crashing the page.
- A **form field** fails validation (`422` attributable to one input) — render an inline field error.
- A **cross-field rule** fails (an entry that does not balance) — render a form-level summary.
- A **mutation** fails in a way that should not disturb the layout — surface a toast error.
- A **route** is page-fatally broken or points at a nonexistent/cross-tenant resource — reach the
  `error.tsx`/`not-found.tsx` boundary.

Do **not** use it when:

- A fetch settled empty — that is the empty pattern; do not swallow a real error into a blank table, and do
  not show a scary error box for "there's just no data."
- The absence is a documented AI soft-fail `503` — that is `EmptyState kind="error_adjacent"`, not an error
  ([`./EMPTY_STATES.md`](./EMPTY_STATES.md)).
- An ordinary region `4xx`/`5xx` occurs — catch it inline; `error.tsx` firing for it is a granularity bug.

# Anatomy

Five coordinated surfaces, each owning a granularity. They are **not** interchangeable; choosing the wrong
one is the most common error-handling mistake in the frontend.

| Surface | Granularity | Handles |
|---|---|---|
| Inline field error | One form field | A validation failure attributable to a single input (`FormMessage` under the field) |
| Form-level summary | One form | A cross-field rule, or a server error not attributable to one field |
| Toast error | One action's outcome | A mutation's failure surfaced non-destructively over the current screen |
| `ErrorState` | One region | A region's *fetch* failing — a table body, panel, or widget — with a retry, without crashing the page |
| `error.tsx` / `not-found.tsx` | One route | A page-fatal failure or a nonexistent/cross-tenant resource — the outermost net |

## The `ErrorState` region surface

The region-level surface, rendered in place of a region's content when its query fails (`isError`). It is
the visual and semantic sibling of the empty state but deliberately distinct — an error is *not* an
emptiness, so its treatment reads as "something went wrong here," and it always offers a recovery path.
Top to bottom:

```
        ┌─ region body ──────────────────────────────────┐
        │                 [ tone glyph ]     ← AlertTriangle / WifiOff / ShieldAlert, 24px, semantic tone
        │                                                 │
        │        Something went wrong on our end     ← title (display-sm, ink-11, real <h3>)
        │                                                 │
        │          Try again in a moment.          ← description (text-sm, ink-9, one line)
        │                                                 │
        │              [ ↻ Try again ]           ← retry (outline sm) — the dominant case
        │                                                 │
        │           Reference: req_01J9…          ← RequestIdRef — server/unknown failures only
        └─────────────────────────────────────────────────┘
```

| Slot | Atom | Token | Rule |
|---|---|---|---|
| Tone glyph | Lucide status icon, 24px, `strokeWidth={1.5}`, `aria-hidden` | `text-negative` / `text-warning` / `text-ink-9` by class | Color is the *second* signal; the text title carries the meaning |
| Title | Real `<h3>` | `display-sm` `text-ink-11` | Plain-language "what failed" |
| Description | `<p>` | `text-sm` `text-ink-9`, `max-w-sm` | One-line "why / what next" |
| Retry | `Button variant="outline" size="sm"` with `RefreshCw` | inherits ink outline | Present when a retry can help; **absent** for `permission` and `not_found` |
| `RequestIdRef` | Selectable, copyable `dir="ltr"` code text | `code-sm`, `ink-9` | **Server/unknown only** — noise elsewhere |

Like every QAYD surface it is flat — no heavy border, no shadow, no saturated red fill — distinguished by
its glyph tone and its retry button, not by an alarming box. A red-filled error region reads as an
emergency, which a transient `502` on one widget is not.

# Variants

`ErrorState` renders one of **four failure classes plus not-found**; the class sets the glyph, tone, copy,
and whether a retry is offered.

| Class | Trigger (status/shape) | Glyph (Lucide) | Tone | Retry? | Copy register |
|---|---|---|---|---|---|
| **Validation** | `422` with `errors` | — (goes to field/form, *not* `ErrorState`) | — | n/a | "This entry is not balanced. Debits must equal credits." |
| **Permission** | `403` | `ShieldAlert` | `warning` | **No** — a retry can't grant access | "You don't have access to this. Requires `bank.reconcile`." |
| **Network** | fetch rejection / offline / timeout | `WifiOff` | `ink-9` | **Yes** | "Couldn't reach the server. Check your connection and try again." |
| **Server** | `500`/`502`/`503` (non-soft-fail) | `AlertTriangle` | `negative` | **Yes** | "Something went wrong on our end. Try again in a moment." + `request_id` |
| **Not found** | `404` (route-level) | `AlertTriangle` | `ink-9` | No — a link home | "This page doesn't exist" — never "you can't see it" |

Per-surface state notes:

- **Field error** — the input is `aria-invalid`, its `FormMessage` renders the localized rule, its border
  shifts to `negative`; clears the instant the field validates.
- **Form summary** — appears only on submit-time failures, focuses itself (`role="alert"`, `tabIndex -1`),
  lists each failure as an in-page anchor link; disappears when all are resolved.
- **Toast error** — assertive, dismissible, auto-timeout for non-blocking failures; a *blocking* failure
  (period locked, version conflict) uses a pinned/inline surface instead of a toast that might be missed.
- **`error.tsx`** — the segment's fatal fallback, with a `reset()` "Try again" and a link to a safe route.

# Composition

The pattern composes from existing atoms; it introduces no new primitive.

| Concern | Atom / token source |
|---|---|
| Region surface | `ErrorState` fills a region body; sibling regions and chrome stay live ([`../components/CARD.md`](../components/CARD.md)) |
| Retry button | `Button variant="outline"` ([`../components/BUTTON.md`](../components/BUTTON.md)) |
| Field error | shadcn `FormMessage`, `aria-describedby`-linked ([`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md)) |
| Toast error | `Toast` `danger` variant via `useApiToast().fromApiError` ([`../components/TOAST.md`](../components/TOAST.md)) |
| `request_id` | `RequestIdRef` — copyable `dir="ltr"` code text |
| Tone glyphs | Lucide `AlertTriangle`/`WifiOff`/`ShieldAlert`/`RefreshCw`, meaning-glyphs that never mirror |
| Colors | `negative`/`warning`/`ink-9`/`ink-11` from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) |

## Mapping the `ApiError` envelope to UI

The single most important behavior: one caught `ApiError` is routed to **exactly one** surface by its status
and shape, never fanned out to several.

| Status | Shape | Surface | Rationale |
|---|---|---|---|
| `422` | `errors` keyed by field | **Field errors** via `form.setError`, plus a form summary if cross-field | Attributable and correctable inline |
| `403` | — | **Permission** `ErrorState` (region) or the module `error.tsx` (route) | Access, not a transient fault; no retry; name the required key |
| `404` | — | **`not-found.tsx`** (route) — never distinguishing "gone" from "not yours" | Cross-tenant enumeration discipline |
| `409` | version/idempotency conflict | **Inline, non-destructive banner** — refetch authoritative record, preserve the user's edits | Never a toast that discards work |
| `429` | `Retry-After` | **Toast** ("Too many requests — retrying shortly"), auto-backoff | Transient; TanStack `retry` honors `Retry-After` |
| `500`/`502` | — | **Server** `ErrorState` (region) with retry + `request_id` | Unexpected; user needs a retry and a support handle |
| `503` on an AI-only endpoint | soft-fail + `Retry-After` | **`EmptyState kind="error_adjacent"`**, not `ErrorState` | Documented fail-soft ([`./EMPTY_STATES.md`](./EMPTY_STATES.md)) |
| network / timeout | fetch rejection | **Network** `ErrorState` (region) with retry | Connectivity, not a server fault |

```tsx
// A mutation's onError — routing by class, not one-size-fits-all.
post.mutate(entryId, {
  onError: (err: ApiError) => {
    if (err.status === 422) toast.fromApiError(err, { setError: form.setError }); // → fields + summary
    else if (err.status === 409) reconcileVersionConflict(err);                    // → non-destructive banner
    else toast.fromApiError(err);      // 403 / 500 / network → assertive toast (+ request_id if server)
  },
});
```

`fromApiError` embodies the `422` branch: when the envelope's `errors` are field-scoped, it calls
`form.setError(field, { message: t(code) })` for each so the errors land on the fields, and only falls back
to a form summary or toast for a `422` with no field attribution. See
[`../components/TOAST.md → Props / API`](../components/TOAST.md).

## The route boundaries

```tsx
// app/(app)/accounting/error.tsx — segment-fatal fallback.
'use client';
import { ErrorState } from '@/components/shared/error-state';
export default function AccountingError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return <ErrorState size="page" errorClass="server" error={error} onRetry={reset} />;
}
```

```tsx
// app/(app)/accounting/journal-entries/[journalEntryId]/not-found.tsx
import { ErrorState } from '@/components/shared/error-state';
export default function EntryNotFound() {
  return (
    <ErrorState
      size="page"
      errorClass="not_found"
      title="This entry doesn't exist"
      description="It may have been deleted, or the link may be wrong."
    />
  );
}
```

## Motion

The error state does not animate on its own — it appears with the region's standard content-swap (a single
`fadeUp`, `motion.base` in / `motion.fast` out from [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md)), instant
under reduced motion. The tone glyph never pulses, shakes, or flashes — a shaking error icon would read as
alarm, violating the calm register. A toast error slides from the block-end edge on `motion.moderate` in /
`motion.fast` out, direction-resolved for RTL. The only other motion is the `RefreshCw` glyph's standard
button press micro-state on the retry.

# States & Behavior

`ErrorState` renders one of the four classes plus not-found (see Variants). The broader behavioral rules:

## Retry affordances

A retry is offered wherever a failure is plausibly transient (network, `5xx`, timeout) and *not* offered
where it cannot help (`403` — retrying won't grant a permission; `404` — the resource still won't exist).
Region retries call the query's own `refetch`; the button is an `outline`/`sm` "Try again." Repeated
failures escalate the copy ("Still can't reach the server — this may be a connection issue") rather than
looping the same message. `error.tsx`'s `reset()` re-renders the boundary; `429`/`503` retries are automatic
via TanStack Query's `Retry-After`-aware backoff, so the user rarely sees a manual retry for those.

## Surfacing `request_id` for support

Every envelope carries a `request_id`. On *unexpected* failures (`5xx`, unknown), `ErrorState` and the
server-error toast render a small, copyable `RequestIdRef` so a user opening a support ticket starts with a
correlation handle that ties directly to the backend logs, instead of a screenshot. It is **not** shown for
validation, permission, or network errors, where a `request_id` is noise — the user's action, not a server
incident, is the cause.

## A region error must not crash the page

A single widget's fetch failing renders that widget's `ErrorState`; the surrounding toolbar, header actions,
and sibling regions stay live so a transient failure never locks the whole screen. `error.tsx` firing for an
ordinary region `4xx`/`5xx` is a granularity bug — those are caught inline.

## Class transitions

| From → to | Trigger |
|---|---|
| Loading → error | Query settles `isError`; `ErrorState` mounts in the region body |
| Error → loaded | Retry `refetch` succeeds; region swaps back to content |
| Error → error (escalated) | Repeated retry failures escalate the copy, not the surface |
| Permission revoked mid-session | A `403` on an enabled control refetches the permission cache, toasts "access changed," and the control collapses to its disabled-with-tooltip form — the page never crashes |

# Content & Copy Guidance

Copy is the load-bearing part of an error — the glyph is only a second signal.

- **What happened, why, what to do — in that order, without blame.** "This entry is not balanced. Debits
  (KD 1,204.500) must equal credits (KD 1,150.000)," never "Oops, something went wrong," and never "you did
  X wrong."
- **Name the required permission** in a `403`: "You don't have access to this. Requires `bank.reconcile`" —
  the key stays a Latin `dir="ltr"` code token in both languages.
- **`404` never distinguishes "deleted" from "not yours."** A cross-tenant id and a genuinely deleted one
  both resolve to identical copy — "This entry doesn't exist" — never "this exists but you can't see it,"
  which would leak the existence of other tenants' records.
- **Server `message` is already localized** server-side per `Accept-Language`, so `fromApiError` surfaces it
  directly; QAYD re-keys only the *field-level* `errors[]` codes to its own client strings, where a precise,
  form-context-aware wording beats the generic server default.
- **Every error string is an i18n key** present in `en.ts` and `ar.ts` and checked by `i18n:check`.
- **Arabic errors are parallel originals** in the calm register — "This entry is not balanced. Debits must
  equal credits." → "هذا القيد غير متوازن. يجب أن يتساوى إجمالي المدين مع إجمالي الدائن." A blaming or
  over-casual Arabic error fails the same review its English counterpart would.
- **Amounts inside error copy** use the platform numeral rule — Western digits, KWD three-decimal,
  `dir="ltr"` — via the same formatter `AmountCell` uses, so "KD 1,204.500" reads correctly inside an Arabic
  error sentence.

# Accessibility

- **Errors interrupt; empties and loads do not.** `ErrorState`, the form summary, and error toasts use
  `role="alert"` (implicit `aria-live="assertive"`), so a screen-reader user is told immediately that
  something failed — distinct from the polite `role="status"` of the empty and loading patterns. This
  assertive/polite split is the auditory version of the visual distinction between the three patterns.
- **Field errors are programmatically linked.** A failing input gets `aria-invalid="true"` and
  `aria-describedby` pointing at its `FormMessage`, so a screen reader announces the input *and* its error
  together, not as two disconnected texts.
- **The error summary manages focus.** On a submit-time failure the summary block receives focus (`tabIndex
  -1`, `role="alert"`), reads the count and list of failures, and each item links to its field — a
  keyboard/screen-reader user is taken to the problem, not left to hunt.
- **The glyph is decorative.** `ErrorState`'s status icon is `aria-hidden`; the failure lives entirely in
  the text title/description, so meaning never depends on a color or a picture — a colorblind or grayscale
  user reads the same error.
- **`request_id` is real, copyable text.** `RequestIdRef` is selectable text with a copy button, labeled for
  assistive tech ("Reference ID for support"), never an image or a hover-only tooltip.
- **Toasts don't steal focus.** Error toasts announce via the live region without moving focus away from the
  user's task; a *blocking* error that must be acknowledged uses an inline surface or a dialog, not a toast
  that could be dismissed unread.
- **No blame, in copy and in structure.** The accessible name follows the same "what happened / what to do"
  rule so the screen-reader experience is as calm as the visual one.

# Theming, Dark Mode & RTL

## Theming

Error surfaces reference only semantic tokens — `text-negative`, `text-warning`, `text-ink-9`, `text-ink-11`
— never a raw red hex or a Tailwind palette value. The status colors are the desaturated, label-paired
`negative`/`warning` from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md), used on the glyph and never as a
large surface fill, so an error region reads as "something's wrong here," not an alarming red box. A
white-label accent override does not touch error color — financial status color is never brand color.

## Dark mode

No `dark:` variants. `negative`/`warning`/`ink-*` remap under `.dark` to their dark-calibrated values, so
the status glyph keeps its meaning and contrast against the dark canvas without glowing: `negative` lifts
from `#B4232E` to `#F26B74`, `warning` from `#B45309` to `#F5A855`, each holding AA on the dark surface.

## RTL

- **Centered layout is direction-neutral**, so `ErrorState` needs no mirroring; title, description, retry,
  and `request_id` all center identically in both directions.
- **The status glyph never flips** — `AlertTriangle`/`ShieldAlert`/`WifiOff` are meaning-glyphs, not
  directional ones; the retry's `RefreshCw` likewise stays as-is.
- **Field errors and the summary mirror for free** through the form's own logical-property layout; the
  summary's anchor links land beside their fields correctly in RTL because the fields do.
- **`request_id` renders `dir="ltr"`** — a Latin/numeric token wrapped like every amount and code, so it
  reads correctly inside an Arabic error region, never with reversed characters.
- **Toasts slide from the block-end edge**, direction-resolved, so they enter from the correct corner in
  RTL.

# Do / Don't

| Do | Don't |
|---|---|
| Route one `ApiError` to exactly one surface by status/shape | Fan a single error out to a field error *and* a toast *and* a region error |
| Fail at the smallest granularity that contains the failure | Collapse a single widget's `4xx` into a full-page `error.tsx` crash |
| Send a `422` to the fields (+ summary if cross-field) | Toast a validation failure the user could fix inline |
| Offer a retry for network/`5xx`/timeout | Offer a retry on `403`/`404`, where it cannot help |
| Surface `request_id` on `5xx`/unknown only | Show a correlation handle on a user's own unbalanced entry |
| Resolve `404` to identical "doesn't exist" copy | Distinguish "gone" from "not yours" and leak cross-tenant existence |
| Tone with a glyph + `negative`/`warning` on the glyph | Fill the whole region with a saturated red background |
| Use `role="alert"` (assertive) for errors | Use polite `role="status"` and let a failure go unheard |
| Preserve the user's edits on a `409` in a non-destructive banner | Discard in-progress work or silently overwrite on a version conflict |
| Keep sibling regions and chrome interactive during a region error | Lock the whole screen because one region's fetch failed |

# End of Document
