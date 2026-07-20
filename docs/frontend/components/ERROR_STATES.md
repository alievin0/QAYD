# Error States — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / ERROR_STATES
---

# Purpose

This document specifies how QAYD communicates *"we tried and it failed"* — the complete error vocabulary of
the frontend: the shared `<ErrorState>` region component, inline field-level errors, form-level error
summaries, toast errors, the route-level `error.tsx` and `not-found.tsx` boundaries, and the single mapping
that ties them all together — the typed `ApiError` envelope (`{ success, message, errors, request_id }`)
turned into the right UI at the right granularity. It is the third of the three components that partition a
data-bearing region's non-happy outcomes, alongside `./LOADING_STATES.md` ("we don't know yet") and
`./EMPTY_STATES.md` ("we know, and there's nothing"). Keeping "failed" visually and semantically distinct
from "empty" and "loading" is a designed invariant: a user must never mistake a transient server failure for
"there's just no data," nor a spinner-forever for a failure that a retry could fix.

Errors in a financial product are high-stakes in a specific way: the user is often mid-action on money —
posting an entry, committing a reconciliation, approving a payment — and an error must tell them, calmly and
precisely, *what happened, why, and what to do next, in that order, without blaming them*
(`../DESIGN_LANGUAGE.md → Voice & Microcopy`). "This entry is not balanced. Debits (KD 1,204.500) must equal
credits (KD 1,150.000)," never "Oops, something went wrong." Every error surface here is built to carry that
register, to route the four classes of failure — permission-denied, validation, network, server — to
distinct, appropriate UI, and to surface the `request_id` on unexpected failures so a support conversation
starts with a correlation handle instead of a screenshot.

The governing principle is **fail at the smallest granularity that contains the failure.** A validation
error belongs on the field; a form-wide rule belongs in a form summary; a region's fetch failure belongs in
that region's `<ErrorState>` without taking down the page; only a genuinely page-fatal failure reaches
`error.tsx`. Collapsing a single widget's `4xx` into a full-page crash, or swallowing a real server error
into a silent empty table, are both defects (`../ACCOUNTING.md → States`, `../DASHBOARD.md → States`).

# Anatomy & Variants

QAYD's error vocabulary is five coordinated surfaces, each owning a granularity. They are not
interchangeable; choosing the wrong one is the most common error-handling mistake in the frontend.

| Surface | Granularity | What it handles |
|---|---|---|
| Inline field error | One form field | A validation failure attributable to a single input (`FormMessage` under the field) |
| Form-level error summary | One form | A cross-field rule ("entry does not balance") or a server error not attributable to one field |
| Toast error | One action's outcome | A mutation's failure surfaced non-destructively over the current screen (`useApiToast`) |
| `<ErrorState>` | One region | A region's *fetch* failing — a table body, a panel, a widget — with a retry, without crashing the page |
| `error.tsx` / `not-found.tsx` | One route | A page-fatal failure or a nonexistent/cross-tenant resource — the outermost boundary |

## `<ErrorState>`

The region-level error component, rendered in place of a region's content when that region's query fails
(`isError`). It is the visual and semantic sibling of `<EmptyState>` but deliberately distinct from it — an
error is *not* an emptiness, so its treatment reads as "something went wrong here," and it always offers a
recovery path. Its anatomy, top to bottom: a single Lucide status glyph (`AlertTriangle` / `WifiOff` /
`ShieldAlert` by class) in a semantic color at 24px; a title stating what failed in plain language; an
optional one-line description; a **retry** affordance (the dominant case); and, for unexpected server
failures, a small, copyable `request_id` reference line. Like every QAYD surface it is flat — no heavy
border, no shadow — distinguished by its glyph color and its retry button, not by an alarming box treatment
(`../DESIGN_LANGUAGE.md → Color & Ink`: status color is desaturated and label-paired, never a large fill).

```tsx
// components/shared/error-state.tsx
'use client';
import { AlertTriangle, WifiOff, ShieldAlert, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { RequestIdRef } from '@/components/shared/request-id-ref';
import type { ApiError } from '@/lib/api/error';
import { cn } from '@/lib/utils';

type ErrorClass = 'network' | 'server' | 'permission' | 'not_found' | 'unknown';

const GLYPH: Record<ErrorClass, typeof AlertTriangle> = {
  network: WifiOff, server: AlertTriangle, permission: ShieldAlert, not_found: AlertTriangle, unknown: AlertTriangle,
};
const TONE: Record<ErrorClass, string> = {
  network: 'text-ink-500', server: 'text-danger', permission: 'text-warning', not_found: 'text-ink-500', unknown: 'text-danger',
};

interface ErrorStateProps {
  error?: ApiError | Error | null;
  errorClass?: ErrorClass;               // overrides the class derived from the error
  title?: string;                        // i18n key; falls back to a class-appropriate default
  description?: string;
  onRetry?: () => void;                  // present ⇒ a "Try again" button; absent ⇒ no retry (e.g. permission)
  size?: 'inline' | 'region' | 'page';
}

export function ErrorState({ error, errorClass, title, description, onRetry, size = 'region' }: ErrorStateProps) {
  const cls = errorClass ?? classifyError(error);        // maps status/shape → ErrorClass (see Behavior)
  const Glyph = GLYPH[cls];
  const requestId = error && 'requestId' in error ? error.requestId : undefined;

  return (
    <div
      role="alert"                        // an error interrupts — assertive live region (see Accessibility)
      className={cn('flex flex-col items-center justify-center gap-3 text-center',
        size === 'inline' ? 'py-6' : size === 'page' ? 'py-20' : 'py-12')}
    >
      <Glyph className={cn('h-6 w-6', TONE[cls])} strokeWidth={1.5} aria-hidden />
      <h3 className={cn('font-display text-ink-950', size === 'page' ? 'text-display-lg' : 'text-display-sm')}>
        {title ?? defaultTitle(cls)}
      </h3>
      {(description ?? defaultDescription(cls)) && (
        <p className="max-w-sm text-sm text-ink-500">{description ?? defaultDescription(cls)}</p>
      )}
      {onRetry && cls !== 'permission' && (
        <Button variant="outline" size="sm" onClick={onRetry}>
          <RefreshCw className="h-3.5 w-3.5" /> Try again
        </Button>
      )}
      {requestId && (cls === 'server' || cls === 'unknown') && <RequestIdRef id={requestId} />}
    </div>
  );
}
```

## Inline field error and form-level summary

Field errors are rendered by shadcn/ui's `FormMessage` (Radix-wired) directly beneath the field, linked to
the input via `aria-describedby` and setting `aria-invalid` on it (`../ACCESSIBILITY.md → Forms`). The
form-level summary is a `role="alert"` block at the top of the form listing cross-field failures with
in-page anchor links to each offending field — the standard error-summary pattern
(`../ACCESSIBILITY.md → The error summary pattern`).

## Toast error

A mutation's failure that should not disturb the current layout is surfaced through `useApiToast()`, whose
`fromApiError(err)` maps the envelope's `message` (and, where field-scoped, its `errors[]`) onto a
`sonner`-based error toast — a single wrapper so no screen hand-writes a toast string
(`../COMPONENT_LIBRARY.md → Toast`).

## `error.tsx` / `not-found.tsx`

Route-segment boundaries. `error.tsx` is a client component catching a thrown, page-fatal error in its
segment (with a `reset()` to re-attempt render); `not-found.tsx` renders for a `notFound()` call or an
unmatched dynamic segment. Both are the *outermost* net — reached only when a failure could not be handled
inline at a smaller granularity.

# Props / API

## `<ErrorState>`

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `error` | `ApiError \| Error \| null` | no | — | The caught error; its status/shape drives the derived `errorClass` and exposes `request_id`. |
| `errorClass` | `'network' \| 'server' \| 'permission' \| 'not_found' \| 'unknown'` | no | derived from `error` | Overrides the automatic classification when the caller knows better than the raw status. |
| `title` | `string` | no | class-appropriate default | i18n key; the plain-language "what failed." |
| `description` | `string` | no | class-appropriate default | i18n key; a one-line "why/what next." |
| `onRetry` | `() => void` | no | — | When present, renders a "Try again" button (typically `query.refetch`); omitted or ignored for `permission` (a retry cannot grant access). |
| `size` | `'inline' \| 'region' \| 'page'` | no | `'region'` | Matches `EmptyState`'s sizing; `inline` for a widget, `region` for a table/panel body, `page` for a full-route boundary. |

## `useApiToast`

| Method | Signature | Description |
|---|---|---|
| `success` | `(message: string) => void` | Polite success toast ("Entry posted."). |
| `fromApiError` | `(err: ApiError) => void` | Maps `err.message` onto an assertive error toast; when `err.errors` is field-scoped and a form is in context, also calls `form.setError` per field (see Behavior). |
| `error` | `(message: string) => void` | An explicit error toast for a non-`ApiError` failure. |

## `ApiError`

The typed error thrown by `apiFetch` (`../README.md → Data Fetching`), constructed as
`new ApiError(message, errors, status, request_id)`:

| Field | Type | Description |
|---|---|---|
| `message` | `string` | Human-readable summary from the envelope (already localized server-side per `Accept-Language`). |
| `errors` | `Record<string, string[]> \| null` | Field-scoped validation errors, keyed by the `FormRequest` field name (`errors['lines.0.debit']`). |
| `status` | `number` | HTTP status — the primary input to classification. |
| `requestId` | `string` | The envelope's `request_id`, surfaced on unexpected failures for support correlation. |

# States

`<ErrorState>` renders one of the four failure classes plus not-found; each class changes the glyph, tone,
copy, and whether a retry is offered:

| Class | Trigger (status/shape) | Glyph / tone | Retry? | Copy register |
|---|---|---|---|---|
| **Validation** | `422` with `errors` | — (goes to field/form, *not* `ErrorState`) | n/a | "This entry is not balanced. Debits must equal credits." |
| **Permission** | `403` | `ShieldAlert` / `warning` | **No** (a retry can't grant access) | "You don't have access to this. Requires `bank.reconcile`." |
| **Network** | fetch rejection / offline / timeout | `WifiOff` / `ink-500` | **Yes** | "Couldn't reach the server. Check your connection and try again." |
| **Server** | `500`/`502`/`503` (non-soft-fail) | `AlertTriangle` / `danger` | **Yes** | "Something went wrong on our end. Try again in a moment." + `request_id` |
| **Not found** | `404` (route-level) | `AlertTriangle` / `ink-500` | No (a link home) | "This page doesn't exist" — never "you can't see it" (see Edge Cases) |

Per-surface state notes:

- **Field error state** — the input is `aria-invalid`, its `FormMessage` renders the localized rule, and the
  field's border shifts to the `danger` token; clears the instant the field validates.
- **Form summary state** — appears only on submit-time failures, focuses itself (`role="alert"`, `tabIndex
  -1`), and lists each failure as an anchor link; disappears when all are resolved.
- **Toast error state** — assertive, dismissible, auto-timeout for non-blocking failures; a blocking failure
  (period locked, version conflict) uses a longer/pinned toast or an inline surface instead of a toast that
  might be missed.
- **`error.tsx` state** — the segment's fatal fallback, with a `reset()` "Try again" that re-renders the
  boundary and a link back to a safe route.

# Behavior & Interaction

## Mapping the `ApiError` envelope to UI

The single most important behavior: one caught `ApiError` is routed to exactly one surface by its status and
shape, never fanned out to several. The classification, applied by `classifyError` and by each caller's
`onError`:

| Status | Shape | Surface | Rationale |
|---|---|---|---|
| `422` | `errors` keyed by field | **Field errors** via `form.setError`, plus a form summary if cross-field | The failure is attributable and correctable inline; a toast or region error would be the wrong granularity |
| `403` | — | **Permission** `ErrorState` (region) or the module `error.tsx` boundary (route) | Access, not a transient fault; no retry, name the required key |
| `404` | — | **`not-found.tsx`** (route) — never distinguishing "gone" from "not yours" | Cross-tenant enumeration discipline (see Edge Cases) |
| `409` | version/idempotency conflict | **Inline, non-destructive banner** — refetch authoritative record, preserve the user's edits | Per `../COMPONENT_LIBRARY.md → Edge Cases → Optimistic update rolled back on 409` |
| `429` | `Retry-After` | **Toast** ("Too many requests — retrying shortly"), auto-backoff | Transient; TanStack `retry` honors `Retry-After` |
| `500`/`502` | — | **Server** `ErrorState` (region) with retry + `request_id` | Unexpected; user needs a retry and a support handle |
| `503` on an AI-only endpoint | soft-fail + `Retry-After` | **`EmptyState kind="error_adjacent"`**, not `ErrorState` | Documented fail-soft; "AI insights temporarily unavailable," backs off on the header (`../COMPONENT_LIBRARY.md → Edge Cases`) |
| network/timeout | fetch rejection | **Network** `ErrorState` (region) with retry | Connectivity, not a server fault |

`fromApiError` embodies the `422` branch: when the envelope's `errors` are field-scoped, it calls
`form.setError(field, { message: t(code) })` for each so the errors land on the fields
(`../ACCESSIBILITY.md → Server errors map back onto the same fields`), and only falls back to a form-summary
or toast for a `422` with no field attribution.

```tsx
// a mutation's onError — routing by class, not one-size-fits-all
post.mutate(entryId, {
  onError: (err: ApiError) => {
    if (err.status === 422) toast.fromApiError(err);        // → fields + summary
    else if (err.status === 409) reconcileVersionConflict(err); // → non-destructive banner, edits preserved
    else toast.fromApiError(err);                            // 403/500/network → assertive toast with message (+request_id if server)
  },
});
```

## Retry affordances

A retry is offered wherever a failure is plausibly transient (network, `5xx`, timeout) and *not* offered
where it cannot help (`403` — retrying won't grant a permission; `404` — the resource still won't exist).
Region retries call the query's own `refetch`; the button is an `outline`/`sm` "Try again"
(`./BUTTONS.md`), and repeated failures escalate the copy ("Still can't reach the server — this may be a
connection issue") rather than looping the same message. `error.tsx`'s `reset()` re-renders the boundary;
`429`/`503` retries are automatic via TanStack Query's `Retry-After`-aware backoff, so the user rarely sees a
manual retry for those.

## `error.tsx` and `not-found.tsx` boundaries

```tsx
// app/(app)/accounting/error.tsx — segment-fatal fallback
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

The module-layout `error.tsx` also renders the shared forbidden boundary for a route-level `403` — "You
don't have access to this," with a link back to the module hub — never a silent redirect that could be
mistaken for the page not existing (`../NAVIGATION_SYSTEM.md → Server truth, client courtesy`, matching
`../BANKING.md`/`../APPROVAL_CENTER.md`).

## Surfacing `request_id` for support

Every envelope carries a `request_id` (`../README.md → Data Fetching`). On *unexpected* failures (`5xx`,
unknown), `<ErrorState>` and the server-error toast render a small, copyable reference — `RequestIdRef` —
so a user opening a support ticket starts with a correlation handle that ties directly to the backend logs
(`../../api/API_LOGGING.md`), instead of a screenshot. It is not shown for validation, permission, or
network errors, where a `request_id` is noise: the user's action, not a server incident, is the cause.

# Accessibility

- **Errors interrupt; empties and loads do not.** `<ErrorState>`, the form summary, and error toasts use
  `role="alert"` (implicit `aria-live="assertive"`), so a screen-reader user is told immediately that
  something failed — distinct from the polite `role="status"` of `EmptyState`/`LoadingState`
  (`../ACCESSIBILITY.md → Live regions`). This assertive/polite split is the auditory version of the
  visual distinction between the three components.
- **Field errors are programmatically linked.** A failing input gets `aria-invalid="true"` and
  `aria-describedby` pointing at its `FormMessage`, so a screen reader announces the input *and* its error
  together, not as two disconnected texts (`../ACCESSIBILITY.md → Forms`).
- **The error summary manages focus.** On a submit-time failure the summary block receives focus
  (`tabIndex -1`, `role="alert"`), reads the count and list of failures, and each item links to its field —
  so a keyboard/screen-reader user is taken to the problem, not left to hunt (`../ACCESSIBILITY.md → The
  error summary pattern`).
- **The glyph is decorative.** `<ErrorState>`'s status icon is `aria-hidden`; the failure lives entirely in
  the text title/description, so meaning never depends on a color or a picture — a colorblind or grayscale
  user reads the same error (`../DESIGN_LANGUAGE.md → Color & Ink`).
- **`request_id` is real, copyable text.** `RequestIdRef` is selectable text with a copy button, labeled for
  assistive tech ("Reference ID for support"), never an image or a hover-only tooltip.
- **Toasts don't steal focus.** Error toasts announce via the live region without moving focus away from the
  user's current task (`../ACCESSIBILITY.md → Toasts do not steal focus`); a *blocking* error that must be
  acknowledged uses an inline surface or a dialog, not a toast that could be dismissed unread.
- **No blame, in copy and in structure.** Error copy states what happened and what to do, never "you did X
  wrong"; the accessible name follows the same rule so the screen-reader experience is as calm as the visual
  one.

# Theming, Dark Mode & RTL

## Theming

Error surfaces reference only semantic tokens — `text-danger`, `text-warning`, `text-ink-500`,
`text-ink-950` — never a raw red hex or a Tailwind palette value. The status colors are the desaturated,
label-paired `danger`/`warning` from `../DESIGN_LANGUAGE.md → Color & Ink`, used on the glyph and never as a
large surface fill, so an error region reads as "something's wrong here," not as an alarming red box. A
white-label accent override does not touch error color (correctly — financial status color is never brand
color; `../DARK_MODE.md → AI provenance color / status semantics`).

## Dark mode

No `dark:` variants. `danger`/`warning`/`ink-*` remap under `:root[data-theme="dark"]` to their
dark-calibrated values (`../DARK_MODE.md → Finance status semantics`), so the status glyph keeps its meaning
and contrast against the dark canvas without glowing. The `danger` token in dark is the lifted `#D06A57`
value from `../COMPONENT_LIBRARY.md`'s dark block, keeping AA contrast on the dark surface.

## RTL

- **Centered layout is direction-neutral**, so `<ErrorState>` needs no mirroring; title, description, retry,
  and `request_id` all center identically in both directions.
- **The status glyph never flips** — `AlertTriangle`/`ShieldAlert`/`WifiOff` are meaning-glyphs, not
  directional ones (`../ICONOGRAPHY.md → RTL-Aware Icons`); the retry's `RefreshCw` likewise stays as-is.
- **Field errors and the summary mirror for free** through the form's own logical-property layout; the
  summary's anchor links land beside their fields correctly in RTL because the fields do.
- **`request_id` renders `dir="ltr"`** — it is a Latin/numeric token, wrapped like every amount and code so
  it reads correctly inside an Arabic error region, never with reversed characters (`../DESIGN_LANGUAGE.md →
  Spacing & Grid → Numerals and currency codes never mirror`).
- **Toasts slide from the block-end edge**, direction-resolved, so they enter from the correct corner in RTL
  (`../DESIGN_LANGUAGE.md → Motion → Toast enter/exit`).

# i18n

- **Every error string is a key**, present in `en.ts` and `ar.ts` and checked by `i18n:check`
  (`../README.md → Conventions`). This includes `<ErrorState>` titles/descriptions, class-default copy,
  field-error messages (keyed by the validation `code`, so `422` `errors` map to localized text via
  `t(code)`, not a raw English string from the server), and toast strings.
- **Server `message` is already localized.** The envelope's `message` is returned in the request's
  `Accept-Language` (`../../api/REST_STANDARDS.md → Localization`), so `fromApiError` can surface it
  directly; QAYD only re-keys the *field-level* `errors[]` codes to its own client strings, where a precise,
  form-context-aware wording beats the generic server default.
- **Error copy holds the calm register in both languages** — "This entry is not balanced. Debits must equal
  credits." → "هذا القيد غير متوازن. يجب أن يتساوى إجمالي المدين مع إجمالي الدائن." — authored as parallel
  originals, never machine-translated (`../DESIGN_LANGUAGE.md → Arabic microcopy`). A blaming or over-casual
  Arabic error fails the same review its English counterpart would.
- **Permission keys stay Latin code tokens** in both languages ("Requires `bank.reconcile`" → "يتطلب صلاحية
  `bank.reconcile`"), rendered in a `dir="ltr"` code span; the surrounding sentence translates, the key does
  not.
- **Amounts inside error copy** use the platform numeral rule — Western digits, KWD three-decimal, `dir="ltr"`
  — via the same formatter `AmountCell` uses, so "KD 1,204.500" reads correctly inside an Arabic error
  sentence (`../DESIGN_LANGUAGE.md → Data Density`).

# Testing

**Storybook.** `error-state.stories.tsx` renders each class (`network`/`server`/`permission`/`not_found`/
`unknown`), the with-retry vs. no-retry variants, and the `request_id`-present server case, across LTR/RTL ×
light/dark. Form-error and toast-error stories cover the field-mapping and assertive-announcement paths.
`DataTable`'s story short-circuits its query to `isError` to prove the region renders `<ErrorState>`, not an
empty table (`../COMPONENT_LIBRARY.md → Testing`).

```tsx
// components/shared/error-state.stories.tsx (excerpt)
export const ServerWithRequestId: StoryObj<typeof ErrorState> = {
  args: { errorClass: 'server', error: mockApiError({ status: 500, requestId: 'req_01J9…' }), onRetry: () => {} } };
export const PermissionNoRetry: StoryObj<typeof ErrorState> = {
  args: { errorClass: 'permission', title: 'You don’t have access to this', description: 'Requires bank.reconcile.' } };
export const Network: StoryObj<typeof ErrorState> = { args: { errorClass: 'network', onRetry: () => {} } };
```

**Vitest + Testing Library** covers the routing and mapping logic — the riskiest part:

```tsx
// lib/api/classify-error.test.ts
test('classifies status codes to the correct ErrorState class', () => {
  expect(classifyError(mockApiError({ status: 403 }))).toBe('permission');
  expect(classifyError(mockApiError({ status: 500 }))).toBe('server');
  expect(classifyError(new TypeError('Failed to fetch'))).toBe('network');
});

// components/shared/error-state.test.tsx
test('permission errors offer no retry; server errors offer retry and show request_id', () => {
  const onRetry = vi.fn();
  const { rerender, queryByRole, getByText } = render(
    <ErrorState errorClass="permission" onRetry={onRetry} />);
  expect(queryByRole('button', { name: /try again/i })).toBeNull();         // no retry on 403

  rerender(<ErrorState errorClass="server" error={mockApiError({ status: 500, requestId: 'req_9' })} onRetry={onRetry} />);
  expect(queryByRole('button', { name: /try again/i })).toBeInTheDocument();
  expect(getByText(/req_9/)).toBeInTheDocument();                            // request_id surfaced
});

// hooks/use-api-toast.test.tsx
test('fromApiError maps 422 field errors back onto the form fields', () => {
  const setError = vi.fn();
  toast.fromApiError(mockApiError({ status: 422, errors: { 'lines.0.debit': ['exactlyOneOfDebitCredit'] } }), { setError });
  expect(setError).toHaveBeenCalledWith('lines.0.debit', expect.objectContaining({ message: expect.any(String) }));
});
```

**Playwright** covers the boundary and cross-tenant behavior a unit test cannot: a seeded API returning
`500` on a region fetch renders that region's `<ErrorState>` with a working retry while the rest of the page
stays interactive; a direct hit to another tenant's `[journalEntryId]` returns `404` and renders
`not-found.tsx` (never a `403` that would confirm the record exists); and a `403` on a module route renders
the forbidden boundary with a link home, not a silent redirect.

**Accessibility CI.** `axe-core` runs against every error story; a non-`role="alert"` error region, a
field error not linked via `aria-describedby`, a decorative glyph missing `aria-hidden`, or a status-color
contrast regression in either theme fails the build. The form-summary focus-management flow gets a manual
keyboard/screen-reader pass each release.

# Edge Cases

- **`404` never distinguishes "deleted" from "not yours."** A resource id belonging to another tenant, or a
  genuinely deleted one, both resolve to `not-found.tsx` with identical copy — the not-found page never
  implies "this exists but you can't see it," which would leak the existence of other tenants' records
  (`../BANK_RECONCILIATION.md → Edge Cases`, `../FRONTEND_ARCHITECTURE.md → 403 vs. 404`). A `403` is only
  used where existence is *already* established (a record the viewer can see but not act on).
- **`503` on an AI endpoint is an empty state, not an error.** A documented soft-fail (`Retry-After`) on an
  AI-only endpoint routes to `EmptyState kind="error_adjacent"` ("AI insights are temporarily
  unavailable"), not `<ErrorState>` — a genuine `500` on the same endpoint *is* an `ErrorState`. The
  discriminator is the status code and the documented fail-soft contract, not the endpoint
  (`./EMPTY_STATES.md`, `../COMPONENT_LIBRARY.md → Edge Cases`).
- **`409` version conflict preserves edits.** An edit collision does not error-out the form; it refetches the
  authoritative record and shows a non-destructive "this changed since you opened it — your edits are
  preserved below, please re-apply them" banner, never silently overwriting or discarding the user's
  in-progress work (`../COMPONENT_LIBRARY.md → Edge Cases`).
- **Permission revoked mid-session.** A `403` on a mutation whose button was enabled at mount refetches the
  permission cache and toasts "access changed," and the affected control collapses to its
  disabled-with-tooltip form rather than the page crashing (`../COMPONENT_LIBRARY.md → Edge Cases →
  Permission removed mid-session`). The stale mount-time permission is never treated as final.
- **A region error must not crash the page.** A single widget's fetch failing renders that widget's
  `<ErrorState>`; the surrounding toolbar, header actions, and sibling regions stay live so a transient
  failure never locks the whole screen (`../DASHBOARD.md → States`, `../ACCOUNTING.md → States`).
  `error.tsx` firing for an ordinary region `4xx`/`5xx` is a granularity bug — those are caught inline.
- **Offline / network drop.** A fetch rejection (not an HTTP status) is the `network` class — `WifiOff`
  glyph, retry, no `request_id` (there is no server incident to correlate); when connectivity returns,
  TanStack Query's `refetchOnReconnect` can auto-recover without the user pressing retry.
- **`request_id` shown only where it helps.** Validation, permission, and network errors omit the
  `request_id` — surfacing a correlation handle for a user's own unbalanced entry or a connectivity blip is
  noise; it appears only on `5xx`/unknown server incidents where support will actually need it.
- **Error toast missed while away.** A *non-blocking* failure is a dismissible/auto-timeout toast; a
  *blocking* failure the user must act on (period locked at post time, version conflict) is an inline
  surface or a pinned toast, never an auto-dismissing toast that could vanish unread before the user returns
  to the tab.
- **Grayscale / PDF export of an errored screen.** Because error meaning is carried by text and a stroke-only
  glyph — never by color alone — an exported or printed view of an error surface (rare, but possible for a
  server-rendered report that failed a sub-section) reads correctly without color, the same designed
  invariant that governs `AmountCell` and `StatusPill` (`../COMPONENT_LIBRARY.md → Edge Cases → Printing`).

# End of Document
