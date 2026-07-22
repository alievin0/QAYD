# Toast — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / TOAST
---

# Purpose

This document is the atomic specification for QAYD's **toast** primitive: the transient, non-blocking
notification that confirms the outcome of an action ("Entry posted", "Reconciliation committed") or reports a
failure that should not disturb the layout the user is looking at. It is one of the smallest surfaces in the
system and one of the most reused — every mutation in QAYD resolves to a toast — so its behavior is specified
once, here, and never re-decided per screen.

A toast answers exactly one question: *what just happened to the thing I did?* It is deliberately narrow. It
is **not** the vehicle for a failure the user must act on before continuing (that is an inline surface, a
form summary, or a dialog), **not** a place to hold information the user needs to read at leisure (that is a
notification in the notification center), and **not** a status that persists (that is a `StatusPill`). A
toast appears, is optionally paused by hover/focus, and leaves. If the information must survive the user
looking away, it does not belong in a toast.

QAYD builds toasts on **`sonner`** (shadcn/ui's current default toast), re-skinned to QAYD tokens and wrapped
by a single `useApiToast()` hook so that no screen ever hand-writes a toast string or picks a raw color. The
error side of that wrapper is owned by [`../../frontend/components/ERROR_STATES.md`](../../frontend/components/ERROR_STATES.md)
— this document specifies the *primitive and its visual/behavioral contract*; ERROR_STATES specifies *which
failures are allowed to become a toast at all* (the `422`→field, `409`→banner, `403/500/network`→toast
routing). The two are read together.

Related reading: the color roles the four tones draw from are in [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md);
the `z-toast` stacking tier and the shadow the toast surface uses are in
[`../ELEVATION_SHADOWS.md`](../ELEVATION_SHADOWS.md); enter/exit timing and the reduced-motion contract are in
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); the live-region rules are in
[`../ACCESSIBILITY.md`](../ACCESSIBILITY.md). Sibling primitives: [`./TOOLTIP.md`](./TOOLTIP.md),
[`./POPOVER.md`](./POPOVER.md), [`./BADGE.md`](./BADGE.md).

# Anatomy

A toast is a single horizontal card lifted onto the `z-toast` layer, anchored to a fixed corner of the
viewport. Its parts, in reading order:

| Part | Role | Notes |
|---|---|---|
| Container | The `surface-2` card that carries the toast | `bg-ink-1` (light) / `bg-ink-3` (dark), 1px `ink-6` border, `radius-lg` (8px), `shadow-sm`. Never a full-bleed colored fill. |
| Leading glyph | One 16px Lucide status glyph in the tone color | `CheckCircle2` / `AlertTriangle` / `Info` / `XCircle`; `strokeWidth={1.5}`, `aria-hidden`. Color is the *second* signal, never the only one. |
| Title | The one-line outcome, high-emphasis | `text-sm`, `ink-12`, the localized `message`. |
| Description | Optional supporting line | `text-sm`, `ink-9`, wraps to at most two lines then truncates. |
| Action | Optional single affordance | e.g. "Undo", "View entry", "Retry". One action only (see Variants → With action). |
| Dismiss | Optional close control | An `X` icon-button, `ink-9`; shown on hover/focus and always present for pinned toasts. |

The container is a **quiet surface, not an alarm box**: the tone lives in the leading glyph and, at most, a
2px `border-inline-start` in the tone color — never a saturated background wash. This is the same
border-first, "status color is desaturated and label-paired" discipline the rest of the system follows
([`../COLOR_SYSTEM.md → AI & Confidence Color`](../COLOR_SYSTEM.md)); a red-filled toast would read as an
emergency, which a posted journal entry's success confirmation is emphatically not.

```
┌──────────────────────────────────────────────┐
│ ▎ [check]  Entry posted.                        x  │   ← border-s-2 tone, glyph, title, dismiss
│      JE-2026-07-0482 · Rent accrual   [View] │   ← description + one action
└──────────────────────────────────────────────┘
```

# Variants

Four tones, one structural shape. The tone is chosen by the *nature of the outcome*, never by how much
attention it "deserves". There is deliberately **no fifth informational hue** — an `info` toast is styled
with the ink scale alone, exactly as [`../COLOR_SYSTEM.md → There is deliberately no "info" hue`](../COLOR_SYSTEM.md)
requires, so the toast layer never quietly acquires a second brand color.

| Variant | Meaning | Glyph | Tone color (token) | Live region | Default duration |
|---|---|---|---|---|---|
| `success` | An action completed | `CheckCircle2` | `positive` | `role="status"` (polite) | 4000ms |
| `info` | A neutral, non-financial notice | `Info` | `ink-9` on `ink-3` (no hue) | `role="status"` (polite) | 5000ms |
| `warning` | Completed with a caveat, or a soft limit reached | `AlertTriangle` | `warning` | `role="status"` (polite) | 6000ms |
| `danger` | An action failed | `XCircle` | `negative` | `role="alert"` (assertive) | 8000ms + dismissible |

Two axes cut across the tones:

- **With action.** A toast may carry **exactly one** action (`Undo`, `View`, `Retry`). A second competing
  action is a defect — if two follow-ups matter, the outcome belongs in a surface the user can dwell on, not
  a toast. An action toast's auto-dismiss timer is extended (minimum 6000ms) so the action is reachable, and
  a `danger` toast that offers `Retry` is **pinned** (no auto-dismiss) because a failure the user might want
  to retry must not vanish unread.
- **Pinned (persistent).** A toast with `duration: Infinity` stays until dismissed or programmatically
  updated. Reserved for (a) `danger` toasts with a `Retry`, and (b) a long-running action's
  loading→success/failure lifecycle via `toast.promise` (below). A pinned toast **always** shows its dismiss
  control.

Promise lifecycle (one toast, three states) is the sanctioned pattern for a mutation that takes long enough
to warrant progress:

```tsx
toast.promise(postEntry(entryId), {
  loading: t('toast.posting'),                    // spinner glyph, polite
  success: (entry) => t('toast.posted', { n: entry.journal_number }), // → success tone
  error:   (err) => err.message,                  // → danger tone, message already localized
});
```

# Props / API

QAYD does not expose `sonner`'s raw `toast()` to screens. Screens call `useApiToast()`; only
`components/ui/toaster.tsx` (the mounted `<Toaster>`) and the hook touch `sonner` directly.

## `useApiToast()`

| Method | Signature | Description |
|---|---|---|
| `success` | `(message: string, opts?: ToastOptions) => string \| number` | Polite success toast. Returns the toast id (for `update`/`dismiss`). |
| `info` | `(message: string, opts?: ToastOptions) => string \| number` | Neutral ink-only toast. |
| `warning` | `(message: string, opts?: ToastOptions) => string \| number` | Caveat toast. |
| `error` | `(message: string, opts?: ToastOptions) => string \| number` | Assertive failure toast for a non-`ApiError`. |
| `fromApiError` | `(err: ApiError, ctx?: { setError?: UseFormSetError }) => void` | The routing entry point. Maps the envelope per [`ERROR_STATES.md → Mapping the ApiError envelope`](../../frontend/components/ERROR_STATES.md); a `422` with field-scoped `errors` calls `setError` per field instead of toasting. |
| `promise` | `<T>(p: Promise<T>, msgs: PromiseMessages<T>) => void` | The loading→success/error lifecycle above. |
| `dismiss` | `(id?: string \| number) => void` | Dismiss one toast, or all when `id` omitted. |

## `ToastOptions`

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `description` | `string` | no | — | Second line (`ink-9`), localized key. |
| `action` | `{ label: string; onClick: () => void }` | no | — | The single action. Presence extends the minimum duration to 6000ms. |
| `duration` | `number` | no | tone default | ms; `Infinity` pins the toast. `danger`+`action` defaults to `Infinity`. |
| `dismissible` | `boolean` | no | `true` | Whether the `X` control renders; forced `true` for pinned toasts. |
| `id` | `string \| number` | no | auto | Supply to dedupe / update an existing toast (see Behavior → Deduplication). |

The `<Toaster>` itself (mounted once in the root layout) owns the container-level configuration — position,
stacking limit, gap, offset, and the RTL direction — and is **not** a per-toast prop:

```tsx
// components/ui/toaster.tsx — mounted once in app/(app)/layout.tsx
'use client';
import { Toaster as Sonner } from 'sonner';
import { useLocale } from 'next-intl';

export function Toaster() {
  const dir = useLocale() === 'ar' ? 'rtl' : 'ltr';
  return (
    <Sonner
      dir={dir}
      position="bottom-right"        // resolves to block-end / inline-end; sonner flips with dir
      visibleToasts={3}              // max stack; older ones collapse behind (see Behavior → Stacking)
      gap={8}                        // --space-xs between stacked toasts
      offset={24}                    // --space-lg from the viewport edge
      closeButton
      toastOptions={{
        classNames: {
          toast:
            'group flex items-start gap-3 rounded-lg border border-ink-6 bg-ink-1 dark:bg-ink-3 ' +
            'p-4 shadow-sm text-sm text-ink-12',
          description: 'text-ink-9',
          actionButton: 'text-accent hover:text-accent-strong font-medium',
          cancelButton: 'text-ink-9',
          success: 'border-s-2 border-s-positive',
          warning: 'border-s-2 border-s-warning',
          error:   'border-s-2 border-s-negative',
          info:    'border-s-2 border-s-ink-6',
        },
      }}
    />
  );
}
```

## `useApiToast` implementation (excerpt)

```tsx
// hooks/use-api-toast.ts
'use client';
import { toast as sonner } from 'sonner';
import { CheckCircle2, AlertTriangle, Info, XCircle } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { ApiError } from '@/lib/api/error';
import type { UseFormSetError } from 'react-hook-form';

export function useApiToast() {
  const t = useTranslations();

  return {
    success: (message: string, opts?: ToastOptions) =>
      sonner.success(message, { icon: <CheckCircle2 aria-hidden />, duration: 4000, ...opts }),
    info: (message: string, opts?: ToastOptions) =>
      sonner(message, { icon: <Info aria-hidden />, duration: 5000, ...opts }),
    warning: (message: string, opts?: ToastOptions) =>
      sonner.warning(message, { icon: <AlertTriangle aria-hidden />, duration: 6000, ...opts }),
    error: (message: string, opts?: ToastOptions) =>
      sonner.error(message, { icon: <XCircle aria-hidden />, duration: 8000, dismissible: true, ...opts }),

    fromApiError(err: ApiError, ctx?: { setError?: UseFormSetError<any> }) {
      // 422 with field attribution → the fields, not a toast (see ERROR_STATES → Mapping).
      if (err.status === 422 && err.errors && ctx?.setError) {
        for (const [field, msgs] of Object.entries(err.errors)) {
          ctx.setError(field, { message: t(msgs[0]) });
        }
        return;
      }
      // 403 / 500 / network / un-attributed 422 → assertive toast. Server message is pre-localized.
      this.error(err.message, {
        duration: err.status >= 500 ? Infinity : 8000, // server incidents pin until read
      });
    },

    promise: sonner.promise,
    dismiss: sonner.dismiss,
  };
}
```

# States

A toast moves through a small, fixed state machine, all of it inside `sonner` and none of it re-implemented
per screen.

| State | Trigger | Visual / behavior |
|---|---|---|
| Enter | `toast.*()` called | Slides + fades in from the block-end/inline-end corner over `motion.base` (200ms), `easeOut`. Announced to its live region. |
| Resting | Enter complete | Fully opaque; auto-dismiss timer running (unless pinned). |
| Paused | Pointer hovers the stack, or a toast receives keyboard focus | Timer freezes; the toast holds. Resumes on pointer-leave / blur. |
| Action-hover | Pointer over the action button | Action shifts to `accent-strong`; timer stays paused. |
| Stacked-behind | A newer toast pushes this one down past `visibleToasts` | Collapses behind the front toast, scaled down and dimmed; still counts against the cap. |
| Exit | Timer elapsed, dismissed, or superseded by `update` | Fades + slides out over `motion.fast` (160ms), `easeIn`; height collapses so the stack reflows. |
| Removed | Exit complete | Detached from the DOM and the live region. |

**Pause-on-hover is mandatory.** A user reading a toast — especially one carrying an action or a
`request_id` — must not have it disappear mid-read. `sonner` pauses the whole stack when the pointer is over
any toast and when focus lands on one; QAYD keeps this on and never sets a duration so short that a two-line
toast cannot be read before it starts to leave (4000ms floor).

# Tokens Used

Every value resolves to a token; the toast surface hardcodes nothing.

| Concern | Token | Value |
|---|---|---|
| Container background | `ink-1` (light) / `ink-3` (dark) | `#FAFAF9` / `#24211A` |
| Container border | `ink-6` | `#C2BEB6` / `#46402F` |
| Radius | `radius-lg` | 8px |
| Shadow | `shadow-sm` | `0 2px 4px rgba(21,19,14,.06)` (light); half-alpha `rgba(0,0,0,…)` in dark |
| Title text | `ink-12` | near-black / near-white |
| Description text | `ink-9` | secondary |
| Success glyph + border-s | `positive` | `#17794A` / `#4ADE94` |
| Warning glyph + border-s | `warning` | `#B45309` / `#F5A855` |
| Danger glyph + border-s | `negative` | `#B4232E` / `#F26B74` |
| Info glyph + border-s | `ink-9` / `ink-6` | no hue, per the no-info-color rule |
| Action label | `accent` → `accent-strong` on hover | `#9C7A34` → `#7A5D22` |
| Stack tier | `z-toast` | 50 (above modals, below command palette and tooltips) |
| Gap between toasts | `--space-xs` | 8px |
| Edge offset | `--space-lg` | 24px |
| Enter / exit | `motion.base` / `motion.fast`, `easeOut` / `easeIn` | 200ms / 160ms |

The toast sits at `z-toast` (50) deliberately **above** `z-modal` (40): a confirmation toast fired by an
action taken inside a dialog must not be hidden behind that dialog ([`../ELEVATION_SHADOWS.md → Z-Index Tiers`](../ELEVATION_SHADOWS.md)).
It sits **below** `z-tooltip` (70) so a tooltip on a control is never occluded by a passing toast.

# Accessibility

- **Polite for outcomes, assertive for failures.** `success`/`info`/`warning` render in a `role="status"`
  (`aria-live="polite"`) region so a screen-reader user hears the confirmation after their current utterance
  finishes; `danger` renders in `role="alert"` (`aria-live="assertive"`) so a failure interrupts. This
  mirrors the polite/assertive split `EmptyState`/`LoadingState` vs. `ErrorState` use
  ([`../../frontend/components/ERROR_STATES.md → Accessibility`](../../frontend/components/ERROR_STATES.md)).
- **A toast never steals focus.** It announces via the live region while focus stays on the user's task. A
  failure the user *must* acknowledge before continuing is not a toast at all — it is an inline surface or a
  dialog (see Do / Don't). This is a hard rule: an auto-dismissing element that grabs focus is both a WCAG
  failure and a data-loss risk mid-form.
- **The glyph is decorative.** The leading status glyph is `aria-hidden`; the meaning lives entirely in the
  title/description text, so a colorblind or grayscale reader loses nothing. Color is never the sole carrier
  of "success" vs. "failure".
- **Keyboard reachable and dismissible.** Focus can move into the toast region (via the `F6`/`Alt`-style hotkey
  `sonner` provides, or Tab when a toast holds an action); the action and dismiss are real buttons, `Esc`
  dismisses the focused toast, and focus returns to where it was.
- **Pause on focus, not just hover.** Keyboard and screen-reader users, who never hover, still get the timer
  frozen the moment a toast receives focus — pause-on-hover alone would exclude them.
- **Contrast holds in both themes.** Title `ink-12` on `ink-1`/`ink-3` clears AA comfortably; the tone glyphs
  clear the 3:1 non-text floor on the toast surface in light and dark (verified in
  [`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).
- **`request_id` is real, copyable text.** When a server-incident toast surfaces a correlation handle, it is
  selectable text via `RequestIdRef`, `dir="ltr"`, labeled for assistive tech — never a hover-only tooltip
  on an ephemeral element.

# Theming, Dark Mode & RTL

## Theming

The toast references only semantic tokens — `bg-ink-1`, `border-ink-6`, `text-ink-12`, `text-positive`,
`text-warning`, `text-negative`, `text-accent`. No raw hex, no Tailwind palette value. The per-company
white-label accent affects only the `accent` action-label color; it never touches the semantic tone colors,
because financial status color is never brand color ([`../COLOR_SYSTEM.md → Usage Rules`](../COLOR_SYSTEM.md)).

## Dark mode

No `dark:` overrides beyond the one container-background swap (`bg-ink-1` → `dark:bg-ink-3`, matching
`surface-2`'s dark treatment). Every token remaps under `.dark` to its calibrated value, so the tone glyphs
keep their meaning and contrast against the darker toast surface without glowing, and the `shadow-sm` under
`.dark` drops to `rgba(0,0,0,…)` at half alpha ([`../ELEVATION_SHADOWS.md → Light vs. Dark Elevation`](../ELEVATION_SHADOWS.md)).
The elevated toast surface is a step *lighter* than the dark canvas, so it reads as raised even with its
shadow removed.

## RTL

- **The stack anchors to the inline-end corner.** In LTR that is bottom-right; in RTL the `<Toaster dir="rtl">`
  flips it to bottom-left, and toasts slide in from that corner. Offset and gap use logical values, so no
  physical `left`/`right` is written.
- **The tone accent is a `border-inline-start`.** The 2px tone stripe sits on the reading-start edge in both
  directions (`border-s-2`, never `border-l-2`), so it leads the toast's content in Arabic exactly as it does
  in English.
- **The status glyph never mirrors.** `CheckCircle2`/`AlertTriangle`/`Info`/`XCircle` are meaning-glyphs, not
  directional ones ([`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) / ICONOGRAPHY RTL rules); they render
  identically in both directions.
- **Numerals and codes inside the message render `dir="ltr"`.** An amount ("KD 1,204.500"), a journal number,
  or a `request_id` embedded in a toast string wraps LTR so it reads correctly inside an Arabic sentence,
  using the same formatter `AmountCell` uses.
- **Motion mirrors.** The slide-in direction is derived from `dir`, not hardcoded, so a toast enters from the
  correct corner in both directions ([`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md)).

## Reduced motion

Under `prefers-reduced-motion: reduce`, the slide is dropped and the toast cross-fades in place over
`motion.instant`/a short opacity step; the *state change* (appearing, dismissing) is preserved, only its
animation is removed — the reduced-motion contract from [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md). Auto-dismiss
timing is unchanged by reduced motion.

# Do / Don't

| Do | Don't |
|---|---|
| Fire a toast for the *outcome of an action* (posted, saved, failed) | Use a toast for information the user must read at leisure — that is the notification center |
| Route failures through `fromApiError`, letting it pick the surface | Hand-write a toast string or `toast.error('...')` with a raw message in a screen |
| Style the tone with a glyph + a 2px `border-s` stripe | Fill the whole toast with a saturated red/green background |
| Carry at most one action per toast | Put two competing actions ("Undo" and "View") in one toast |
| Pin a `danger`+`Retry` toast (no auto-dismiss) | Let a retryable failure auto-dismiss before it is read |
| Style an `info` toast with the ink scale only | Introduce a blue "info" hue and acquire a second brand color |
| Keep pause-on-hover *and* pause-on-focus on | Set a duration so short a two-line toast can't be read |
| Let a toast announce via its live region without moving focus | Move focus to a toast, or use a toast for a blocking must-acknowledge failure |
| Render the toast at `z-toast` (50), above modals | Lower it below a dialog where an in-dialog action's confirmation would hide |
| Embed amounts/`request_id` as `dir="ltr"` copyable text | Reverse Latin/numeric tokens inside an Arabic toast |

# Usage & Composition

**A mutation's success** — the overwhelmingly common case, one line:

```tsx
const toast = useApiToast();
const post = usePostJournalEntry();

await post.mutateAsync(entry.id, {
  onSuccess: (e) =>
    toast.success(t('toast.posted'), {
      description: `${e.journal_number} · ${e.reference}`,
      action: { label: t('view'), onClick: () => router.push(`/accounting/journal-entries/${e.id}`) },
    }),
  onError: (err) => toast.fromApiError(err, { setError: form.setError }),
});
```

**An undoable action** (soft-delete a draft, unmatch a reconciliation line) — the action *is* the safety net,
so the toast is the primary place the undo lives, and its duration is extended:

```tsx
toast.success(t('toast.draftDeleted'), {
  duration: 8000,
  action: { label: t('undo'), onClick: () => restoreDraft(draftId) },
});
```

**A long action** — `toast.promise` owns the loading→resolved lifecycle in a single toast (see Variants).

**What does NOT go in a toast** (the composition boundary, cross-referenced so the caller picks the right
surface):

- A `422` validation failure → field errors + form summary, via `fromApiError`'s `setError` branch
  ([`../../frontend/components/ERROR_STATES.md`](../../frontend/components/ERROR_STATES.md)).
- A `409` version conflict → a non-destructive inline banner that preserves the user's edits, never a toast.
- A period-locked-at-post-time or other *blocking* failure → an inline surface or a dialog the user must
  acknowledge, never an auto-dismissing toast that could vanish unread.
- A persistent record status → a [`./BADGE.md`](./BADGE.md) `StatusPill`, not a toast.

`useApiToast` is the only sanctioned entry point; the raw `sonner` `toast()` is confined to `use-api-toast.ts`
and `toaster.tsx`, enforced by an ESLint import rule so no screen re-invents a toast.

# End of Document
