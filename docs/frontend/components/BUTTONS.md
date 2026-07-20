# Buttons — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / BUTTONS
---

# Purpose

`Button` is the most-instantiated interactive primitive in QAYD and therefore the one most able to make the
whole product feel either coherent or accidental. Every "Post entry," "Approve," "Reconcile statement,"
"Save draft," "Reject," "Import," and "Export" the user ever presses is one `Button`, styled from one `cva`
variant set, sized from one scale, and — critically — wired to exactly one of two mutation models. This
document is the binding contract for that primitive and its immediate family: `Button` itself (all
variants, sizes, icon-only form, loading and disabled states, and the `asChild` polymorphic escape hatch),
`IconButton`, `SplitButton`, and `ButtonGroup`. It also specifies the two decisions a button-authoring
engineer must get right every time and which the design tokens alone cannot make for them: *when a button
is a Server Action submit versus a TanStack `useMutation`-driven client button*, and *how an
RBAC-ungranted action is rendered* — disabled-with-reason versus omitted.

The button is where the platform's non-negotiable rules become physical. RBAC is a courtesy in the UI and a
truth on the server (`../README.md → # Purpose`), so a button never *enforces* a permission — it reflects
one, either by not rendering (`<PermissionGate>`) or by rendering disabled with a tooltip that names the
missing key (`../../foundation/PERMISSION_SYSTEM.md`). AI is visible and gated: the button that executes an
AI proposal is never the same affordance as the button that saves a human decision, and a low-confidence
proposal's "Do it" button is `disabled`, not merely styled to look reluctant (`../COMPONENT_LIBRARY.md →
AIProposalPanel`). And the platform's calm, never-alarming finance tone (`../DESIGN_LANGUAGE.md → Brand
Expression & Tone`) is encoded directly into the variant set: `destructive` exists but is rationed to
permanent-deletion contexts, and a separate, quieter `destructive-quiet` carries the far more common
"Reject" / "Void" actions so that reversible negative actions never shout.

Nothing in this document introduces a color, radius, spacing value, motion curve, or permission key not
already defined in `../DESIGN_LANGUAGE.md`, `../THEMING.md`, or `../../foundation/PERMISSION_SYSTEM.md`. It
composes them.

# Anatomy & Variants

## Anatomy

A `Button` is a single focusable control composed of, in inline order, an optional leading icon, a required
text label (except in the icon-only form), and an optional trailing icon or affordance (a `SplitButton`'s
caret, a dropdown chevron). Its internal layout is `inline-flex items-center justify-center gap-2`, so the
gap between icon and label is the `--space-2xs` (4px)→`gap-2` (8px) rhythm from `../DESIGN_LANGUAGE.md →
Spacing scale`, never a hand-tuned margin. The label is `text-sm` Inter medium; icons are Lucide at 16px in
the default and `sm` sizes and never mixed with a second icon set or an emoji
(`../ICONOGRAPHY.md`). The corner radius is `--qayd-radius-md` (`rounded-md`, 8px) for every rectangular
variant and `--qayd-radius-full` only for a deliberately pill-shaped context that is *not* an ordinary
button (a filter chip), which is why the base `Button` is never `rounded-full`.

Three structural rules hold across every variant:

1. **The label is a verb and an object.** "Post entry," never "Submit"; "Reconcile statement," never "Go"
   (`../DESIGN_LANGUAGE.md → Voice & Microcopy`). The label is always an i18n key, never a literal string
   (see `# i18n`).
2. **Loading does not change width.** The `loading` prop swaps the leading icon for a spinner and disables
   the control, but the label stays rendered (dimmed) so the button does not reflow the layout around it
   the instant a mutation fires — a reflowing toolbar reads as jumpy, the opposite of the platform's calm
   register.
3. **Color is never the only signal.** A `destructive` button is legible as destructive by its label and
   its position in a confirm dialog, not by red alone; the red is confirmation, not the message
   (`../DESIGN_LANGUAGE.md → Color & Ink`).

## Variant set

`Button` declares its variants with `class-variance-authority`, exactly as shadcn/ui ships and as
`../COMPONENT_LIBRARY.md → Variants via cva` establishes for the whole library. The full set:

| `variant` | Visual treatment | Semantic role | Where it appears |
|---|---|---|---|
| `default` | `accent-600` fill, white label, hover `accent-500` | The **single** primary action on a surface | "Post entry," "Approve," "Save changes" — one per screen region |
| `secondary` | `ink-100` fill, `ink-950` label, hover `ink-150` | A secondary action of equal permanence but lower emphasis | "Save draft" beside "Post entry" |
| `outline` | Transparent, `ink-150` border, `ink-950` label, hover `ink-100` | A neutral action that must read as interactive but not primary | "Import statement," toolbar actions, `AccountPicker`'s trigger |
| `ghost` | Transparent, no border, `ink-950` label, hover `ink-100` | The lowest-emphasis actionable affordance | Row actions, icon buttons, "Add line," "Cancel" |
| `destructive` | `danger` fill, white label, hover `opacity-90` | **Permanent, irreversible** deletion only | "Delete draft permanently," "Remove connection" |
| `destructive-quiet` | Transparent, `danger/40` border, `danger` label, hover `danger/5` | A **reversible or reversing** negative action | "Reject," "Void" (which creates a reversing entry, not a deletion) |
| `link` | No fill, `accent-600` text, underline on hover, `h-auto p-0` | An action that reads as inline text, not a control | "View full reasoning," "Why 92%?" inline triggers |

The `destructive` / `destructive-quiet` split is the single most important variant decision in the set and
the one most often gotten wrong. **Void and Reject are not deletions.** Voiding a posted journal entry
creates an automatic reversing entry and preserves the original for audit (`../JOURNAL_ENTRIES.md`);
rejecting a pending approval returns it to its author with a reason. Neither destroys data, so neither uses
the loud `destructive` fill — they use `destructive-quiet`, whose bordered, unfilled treatment signals
"negative but recoverable." `destructive` (solid red) is reserved for the rare action that genuinely and
permanently removes something, and even then it is confirmed through an `AlertDialog`, never fired on a
single click (see `# Behavior & Interaction`).

## Size set

| `size` | Height | Padding | Label size | Typical use |
|---|---|---|---|---|
| `sm` | `h-8` (32px) | `px-3` | `text-[13px]` | Row actions, `ApprovalCard` footer, dense toolbars |
| `default` | `h-9` (36px) | `px-4` | `text-sm` (14px) | The overwhelming majority of buttons |
| `lg` | `h-10` (40px) | `px-5` | `text-[15px]` | Onboarding, auth screens, a marketing CTA |
| `icon` | `h-9 w-9` | `p-0` | — (icon-only) | `IconButton` base; a square control with one Lucide glyph |

Every size clears the AA target-size floor for pointer input; the `sm` height (32px) is used only where a
larger control would break a dense table's row height, and in those contexts the button still carries a
minimum 24×24 CSS target with adequate spacing per `../ACCESSIBILITY.md → Target size`. There is no `xs`
size — a control smaller than 32px tall is not a button in QAYD, it is a text `link` variant.

## `cva` declaration

```ts
// components/ui/button.tsx (excerpt)
import { cva, type VariantProps } from 'class-variance-authority';

export const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ' +
  'transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-600 ' +
  'focus-visible:ring-offset-2 focus-visible:ring-offset-surface ' +
  'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
  {
    variants: {
      variant: {
        default: 'bg-accent-600 text-white hover:bg-accent-500',
        secondary: 'bg-ink-100 text-ink-950 hover:bg-ink-150',
        outline: 'border border-ink-150 bg-transparent text-ink-950 hover:bg-ink-100',
        ghost: 'bg-transparent text-ink-950 hover:bg-ink-100',
        destructive: 'bg-danger text-white hover:opacity-90',
        'destructive-quiet': 'border border-danger/40 text-danger bg-transparent hover:bg-danger/5',
        link: 'text-accent-600 underline-offset-4 hover:underline p-0 h-auto',
      },
      size: {
        sm: 'h-8 px-3 text-[13px]',
        default: 'h-9 px-4',
        lg: 'h-10 px-5 text-[15px]',
        icon: 'h-9 w-9 p-0',
      },
    },
    defaultVariants: { variant: 'default', size: 'default' },
  },
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean;
  loading?: boolean;
  loadingLabel?: string;
}
```

Note the base class covers both `disabled:` (native disabled) and `aria-disabled:` (the disabled-with-reason
pattern) so a permission-gated button that must stay focusable — to expose its tooltip to a keyboard user —
still reads visually disabled. See `# States → Disabled` for why QAYD does not use native `disabled` for
RBAC gating.

## Implementation

```tsx
// components/ui/button.tsx
import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { buttonVariants, type ButtonProps } from './button.variants';

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { className, variant, size, asChild = false, loading = false, loadingLabel, children, disabled, ...props },
  ref,
) {
  const Comp = asChild ? Slot : 'button';
  // asChild + loading is a documented no-op combination — a Slot has no place to inject a spinner (see Edge Cases).
  return (
    <Comp
      ref={ref}
      className={cn(buttonVariants({ variant, size }), className)}
      disabled={asChild ? undefined : disabled || loading}
      data-loading={loading || undefined}
      aria-busy={loading || undefined}
      {...props}
    >
      {loading && !asChild && <Loader2 className="h-4 w-4 shrink-0 animate-spin motion-reduce:animate-none" aria-hidden />}
      {loading && loadingLabel ? loadingLabel : children}
    </Comp>
  );
});
```

The spinner is `Loader2` at 16px with `motion-reduce:animate-none`, so under `prefers-reduced-motion` the
control shows a static spinner glyph rather than a spinning one — the state is still communicated (the icon
is present, the label is dimmed, `aria-busy` is set), only the rotation is removed, exactly per
`../DESIGN_LANGUAGE.md → Reduced motion` ("removes the *animation* of the change, never the change").

# Props / API

## `Button`

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `variant` | `'default' \| 'secondary' \| 'outline' \| 'ghost' \| 'destructive' \| 'destructive-quiet' \| 'link'` | no | `'default'` | See variant table above. |
| `size` | `'sm' \| 'default' \| 'lg' \| 'icon'` | no | `'default'` | See size table above. |
| `loading` | `boolean` | no | `false` | Renders the spinner, dims the label, sets `aria-busy`, and disables interaction — without changing the button's width. |
| `loadingLabel` | `string` | no | — | Optional i18n string shown in place of `children` while loading (e.g. "Posting…"); when omitted, the original label stays visible beside the spinner. |
| `asChild` | `boolean` | no | `false` | Renders the button's styles onto its single child element via Radix `Slot` instead of a `<button>` — used to make a `next/link` `<a>` look and behave as a button while staying a real anchor. |
| `disabled` | `boolean` | no | `false` | Native disabled. Use for *state*-based blocking (an unbalanced entry, a mutation in flight), **not** for RBAC (see `# States → Disabled`). |
| `type` | `'button' \| 'submit' \| 'reset'` | no | `'button'` | `'submit'` inside a `<form action={serverAction}>` is what makes a Server Action submit button; defaults to `'button'` to avoid accidental form submits. |
| …native `<button>` props | `React.ButtonHTMLAttributes` | — | — | `onClick`, `form`, `formAction`, `name`, `value`, `aria-*` all pass through. |

## `IconButton`

A thin wrapper over `Button size="icon"` that makes the mandatory accessible label non-optional at the type
level — an icon-only control with no text is invisible to a screen reader, so `IconButton` will not compile
without a `label`.

| Prop | Type | Required | Description |
|---|---|---|---|
| `icon` | `LucideIcon` | yes | The single Lucide glyph, rendered at 16px (`sm`/`default`) or 20px (`lg`). |
| `label` | `string` | yes | The accessible name (i18n key). Applied as `aria-label` and as the `Tooltip` content. Never omitted. |
| `variant` | same as `Button` | no | Defaults to `ghost` — icon buttons are almost always low-emphasis. |
| `size` | `'sm' \| 'default' \| 'lg'` | no | Maps to the square icon dimensions. |
| `loading` | `boolean` | no | Swaps `icon` for the spinner. |

## `SplitButton`

A primary action fused to a caret that opens a `DropdownMenu` of related secondary actions — used where one
action is clearly the default but a small set of variations exists (e.g. "Post entry" with a caret exposing
"Post and new," "Post and duplicate"). It is deliberately *not* a general-purpose menu button; the primary
segment always performs the single most common action directly, so a user never has to open a menu to do
the obvious thing.

| Prop | Type | Required | Description |
|---|---|---|---|
| `children` | `ReactNode` | yes | The primary segment's label. |
| `onPrimary` | `() => void` | yes | The default action; runs on a click of the primary segment. |
| `actions` | `SplitAction[]` | yes | The caret menu items: `{ label, icon?, onSelect, permission?, disabled? }`. Items whose `permission` the caller lacks are omitted (per `DropdownMenu`'s rule in `../COMPONENT_LIBRARY.md`). |
| `variant` | `'default' \| 'secondary' \| 'outline'` | no | Applied to both segments; `link`/`ghost`/`destructive` are disallowed at the type level (a split destructive action is a footgun). |
| `loading` | `boolean` | no | Disables both segments and spins the primary. |
| `primaryPermission` | `string` | no | If set and ungranted, the primary segment renders disabled-with-tooltip while the caret may still expose permitted alternatives. |

## `ButtonGroup`

A layout primitive that arranges a set of related buttons as a single visual unit with shared borders and
consistent spacing, and that owns their responsive collapse. It carries no action logic of its own.

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `children` | `ReactNode` | yes | — | Two or more `Button`s. |
| `attached` | `boolean` | no | `false` | When `true`, renders a segmented control — buttons share borders with no gap, inner radii removed. When `false`, a plain `gap-2` row. |
| `orientation` | `'horizontal' \| 'vertical'` | no | `'horizontal'` | Vertical is used in narrow drawers and on mobile. |
| `collapseAt` | `'sm' \| 'md' \| 'lg'` | no | — | Below this breakpoint the group collapses into an overflow `IconButton` + `DropdownMenu` (the "…" pattern), so a five-button toolbar never wraps awkwardly on a tablet. |

# States

`Button` is specified across the full interaction lifecycle; every state below is a real, testable rendering,
not an aspiration.

| State | Trigger | Rendering |
|---|---|---|
| **Default** | Resting | Variant's base fill/border/label; `--shadow` is not used on buttons (elevation on a button reads as heavy — see `../DESIGN_LANGUAGE.md → Elevation`). |
| **Hover** | Pointer over | The variant's `hover:` treatment (`accent-500` fill, `ink-100` wash, etc.), transitioned via `transition-colors` at `motion.fast` (160ms). Hover is never the *only* affordance — every state is also reachable and legible via focus. |
| **Focus (visible)** | Keyboard focus | `focus-visible:ring-2 ring-accent-600 ring-offset-2` — a 2px accent ring with a surface-colored offset, meeting the ≥3:1 non-text contrast target at every surface step (`../ACCESSIBILITY.md → Focus-visible indicator`). Pointer focus does **not** show the ring (`:focus-visible`, not `:focus`). |
| **Active / pressed** | Pointer down / `Space`/`Enter` held | `scale: 0.98` for `motion.micro` (120ms), `easeInOut`, per the named "Button press" pattern in `../DESIGN_LANGUAGE.md → Motion`. Collapses to no scale under reduced motion. |
| **Loading (pending)** | `loading` / a driving mutation's `isPending` | Spinner replaces the leading icon; label stays (dimmed) or swaps to `loadingLabel`; `aria-busy="true"`; pointer + keyboard activation blocked. Width unchanged. |
| **Disabled (state-based)** | `disabled` from business/form state | `opacity-50`, `pointer-events-none`, native `disabled` — not focusable, because there is nothing to explain beyond the visible context (e.g. an unbalanced entry whose banner already states why). |
| **Disabled (RBAC/explained)** | ungranted permission, rendered not hidden | `aria-disabled="true"` (kept focusable), `opacity-50`, click intercepted, wrapped in a `Tooltip` naming the required permission — never a silently dead control (`../ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves`). |
| **Error (post-mutation)** | driving mutation's `isError` | The button itself returns to its default/enabled state (it is ready to retry); the *error* is surfaced by the mutation's `useApiToast().fromApiError(err)` or an inline `ErrorState`, not by recoloring the button red (see `./ERROR_STATES.md`). A button never becomes a permanent error indicator. |

## The two disabled models, stated precisely

QAYD uses `disabled` (native) and `aria-disabled` (explained) for two genuinely different reasons, and
conflating them is the most common button bug across the frontend:

- **`disabled` (native)** — for *state*: a mutation is in flight, a form is invalid, an entry is unbalanced,
  a period is closed. The reason is already visible on-screen next to the button (a balance banner, a form
  error, a status pill), so the control does not need to be focusable to explain itself. It is removed from
  the tab order.
- **`aria-disabled` (explained)** — for *permission*, when the design chose to *show* the control rather
  than hide it: the button stays in the tab order, intercepts activation, and its wrapping `Tooltip` names
  the missing key ("Requires `accounting.journal.post`"). This is the pattern `../DESIGN_LANGUAGE.md →
  Contrast targets` mandates: "QAYD never leaves a user staring at a grayed-out button with no explanation."

Whether a permission-gated button is *hidden* (`<PermissionGate>`) or *shown-disabled* (`aria-disabled` +
tooltip) is a per-screen decision, never a component default — see `# Behavior & Interaction → RBAC
affordance`.

# Behavior & Interaction

## Server Action submit vs. TanStack `useMutation` client button

This is the decision the tokens cannot make, and README's `# Conventions → RSC vs. Client Components` is the
authority; this section applies that rule specifically to buttons.

**A button is a Server Action submit** when its action is a simple, fire-and-forget mutation that (a) does
not need optimistic UI, (b) should survive with JavaScript disabled or still loading, and (c) does not live
inside a modal/drawer that must not remount on error. Dismissing a notification, flipping a settings toggle,
marking an insight read — these are `<form action={serverAction}>` with a `<Button type="submit">`, and the
pending state comes from React 19's `useFormStatus()`:

```tsx
// components/settings/toggle-two-factor.tsx
'use client';
import { useFormStatus } from 'react-dom';
import { Button } from '@/components/ui/button';
import { setTwoFactor } from '@/lib/actions/settings'; // 'use server'

function SubmitButton({ label }: { label: string }) {
  const { pending } = useFormStatus(); // reads the enclosing <form>'s in-flight state
  return <Button type="submit" loading={pending}>{label}</Button>;
}

export function TwoFactorToggle({ enabled }: { enabled: boolean }) {
  return (
    <form action={setTwoFactor}>
      <input type="hidden" name="enabled" value={String(!enabled)} />
      <SubmitButton label={enabled ? 'Disable 2FA' : 'Enable 2FA'} />
    </form>
  );
}
```

**A button is a TanStack `useMutation` client button** when its action needs any of: an optimistic update
(`useOptimistic`/`onMutate`), inline error recovery inside a drawer or dialog that must keep its state, a
per-button pending spinner independent of any surrounding form, or a cache invalidation the surrounding
screen must react to without a full-page transition. Posting a journal entry, committing a reconciliation
match, approving an AI proposal, saving a draft — these are `useMutation`, and the button reads `isPending`
directly:

```tsx
// components/accounting/post-entry-button.tsx
'use client';
import { Button } from '@/components/ui/button';
import { usePostJournalEntry } from '@/hooks/accounting/use-journal-entries';
import { usePermission } from '@/hooks/use-permission';
import { useApiToast } from '@/hooks/use-api-toast';
import { Check } from 'lucide-react';

export function PostEntryButton({ entryId, balanced }: { entryId: number; balanced: boolean }) {
  const post = usePostJournalEntry();
  const canPost = usePermission('accounting.journal.post');
  const toast = useApiToast();

  return (
    <Button
      loading={post.isPending}
      loadingLabel="Posting…"
      disabled={!balanced}                         // state-based: reason is the visible balance banner
      aria-disabled={!canPost || undefined}        // RBAC-based: kept focusable, tooltip explains
      onClick={() =>
        post.mutate(entryId, {
          onSuccess: () => toast.success('Entry posted.'),
          onError: (err) => toast.fromApiError(err), // never recolors the button — see ERROR_STATES.md
        })
      }
    >
      <Check className="h-4 w-4" /> Post entry
    </Button>
  );
}
```

The rule of thumb, restated from README: *if the button owns a spinner on itself, it is a `useMutation`
button; if the pending state belongs to a form as a whole, it is a Server Action submit button.* A finance
mutation the user watches complete on a specific control — the dominant case in QAYD — is almost always the
former.

## Pending and optimistic states

A button's pending state is bound to exactly one source of truth and never a local `useState(false)`
hand-managed flag:

- **`useMutation` button:** `loading={mutation.isPending}`. The mutation library owns the in-flight
  boolean, so the button cannot get out of sync with the request (a hand-rolled `setBusy(true)` that never
  reaches `setBusy(false)` on a thrown error is the classic stuck-spinner bug this avoids).
- **Server Action submit:** `loading={useFormStatus().pending}`.
- **Optimistic:** when the mutation uses `onMutate` to apply an optimistic cache update, the button's
  spinner is typically *not* shown at all — the UI already reflects the intended end state, so a spinner
  would contradict it. Instead the button briefly disables to prevent a double-submit and re-enables on
  settle; on a rollback (`onError`), the optimistic change reverts and the error is toasted. This matches
  `../COMPONENT_LIBRARY.md → Edge Cases → Optimistic update rolled back on 409` — the button's job on
  rollback is only to become pressable again, not to display the failure.

A button never fires its mutation twice: `loading`/`isPending` disables activation, and for the rare
Server-Action case without a client pending signal, the action is idempotent server-side (per
`../../api/API_IDEMPOTENCY.md`) so a double-submit is harmless.

## Destructive confirmation

`destructive` (solid) never executes on a single click. It opens an `AlertDialog` whose confirm button is
itself the `destructive` variant and whose copy states the consequence plainly ("Delete this draft
permanently? This cannot be undone.") per `../DESIGN_LANGUAGE.md → Voice & Microcopy`. `destructive-quiet`
(Reject/Void) opens a dialog too, but because those actions are reversible/auditable the dialog's job is to
collect the *mandatory reason* rather than to warn — mirroring `ApprovalCard`'s reject flow in
`../COMPONENT_LIBRARY.md`, where "Confirm reject" stays disabled until the reason field is non-empty.

## RBAC-disabled affordance pattern

Two renderings, one decision per screen:

```tsx
// Hidden — when the action's mere existence is sensitive (e.g. Void on a record a role has no path to void)
<PermissionGate permission="accounting.journal.void">
  <Button variant="destructive-quiet" onClick={openVoidDialog}>Void</Button>
</PermissionGate>

// Shown-disabled — when the action is ordinarily available and its absence would be confusing
<PermissionTooltip permission="accounting.journal.post">
  <Button aria-disabled={!canPost} onClick={canPost ? post : undefined}>Post entry</Button>
</PermissionTooltip>
```

`PermissionTooltip` wraps the disabled button in a Radix `Tooltip` whose content is the localized "Requires
`<key>`" string, keeps the trigger focusable (so the reason is reachable by keyboard, not hover-only), and
intercepts the click. The choice between the two is documented in each screen's own spec — `Void` hides,
`Post` shows-disabled — and is never left to the button author's momentary preference, exactly as
README's `# Conventions` requires.

## Keyboard & activation

`Button` is a native `<button>` (or, under `asChild`, a real `<a>`), so it inherits correct activation for
free: `Enter` and `Space` fire a `<button>`; `Enter` follows an `<a>`. `SplitButton`'s caret opens on
`Enter`/`Space`/`↓` and its menu is a full Radix `DropdownMenu` (roving focus, `Esc` to close). Focus never
gets trapped on a button; the only focus-trapping in this family is inside a destructive `AlertDialog`,
owned by Radix.

# Accessibility

Every rule here is either inherited from the native element or added deliberately; none may be stripped when
restyling.

- **Real elements only.** A `Button` is a `<button>` or, via `asChild`, a real `<a>` — never a `<div
  onClick>`. This is what gives keyboard activation, focusability, and the correct implicit role at no cost
  (`../ACCESSIBILITY.md → Custom widget roles`).
- **Icon-only buttons are always labeled.** `IconButton` makes `label` non-optional; it becomes both
  `aria-label` and the visible `Tooltip`, so the control is named for assistive tech and for a sighted user
  who is unsure of a glyph. A bare icon `<Button size="icon">` with no `aria-label` is a lint error, not
  just a review nit.
- **Loading is announced, not silent.** `aria-busy="true"` on the button during `loading`, and the
  operation's *result* (success/error) is announced through the live-region tiers in `../ACCESSIBILITY.md →
  Live regions` — polite for "Entry posted," assertive (`role="alert"`) for a blocking failure — via
  `useApiToast`, not by the button changing color.
- **Disabled explains itself.** The RBAC/explained disabled model uses `aria-disabled` (kept focusable) plus
  a tooltip naming the permission; the state-based model uses native `disabled` only where the reason is
  already visible adjacent to the control. QAYD never ships a focusable-but-mute grayed button.
- **Focus indicator is never suppressed.** `focus-visible:ring-2 ring-accent-600 ring-offset-2` is part of
  the base `cva` string; removing it (`focus:outline-none` without a replacement ring) is banned. The ring
  meets the ≥3:1 non-text contrast target on every surface, verified per `../ACCESSIBILITY.md → Color &
  Contrast`.
- **Target size.** `default`/`lg` clear 40px; `sm` (32px) is used only in dense contexts and still meets
  the 24px minimum with spacing exemption per WCAG 2.2 Target Size (Minimum).
- **Motion respects the user.** Press-scale and spinner rotation collapse under `prefers-reduced-motion`;
  the button's *state* still changes (it disables, the label dims, the icon appears) — only the animation is
  removed.
- **Destructive actions are double-gated.** A solid `destructive` action requires an `AlertDialog`
  confirmation whose focus is trapped and whose default focus lands on the *Cancel* button, not the
  destructive one, so an accidental `Enter` cancels rather than destroys.

# Theming, Dark Mode & RTL

## Theming

Every button color resolves to a QAYD semantic token, never a raw Tailwind palette value —
`bg-accent-600`, `text-ink-950`, `border-ink-150`, `bg-danger` — mapped from the CSS variables in
`../DESIGN_LANGUAGE.md → Design Tokens` via `tailwind.config.ts` (`../THEMING.md → Tailwind Integration`).
`bg-emerald-600` or `bg-red-500` in button source is banned exactly as a raw hex is. A company white-label
override that changes the accent hue (`../THEMING.md → Company Branding`) re-skins every `default` button in
the app at once, because they all reference the same `--qayd-accent-600` token rather than a literal color —
this is the entire reason for the token indirection.

## Dark mode

The button set carries **no** `dark:` Tailwind variants. Dark mode is a token remap under
`:root[data-theme="dark"]` (`../DARK_MODE.md → Token Mapping`): `accent-600` lifts to a higher-luminance
value so a `default` button keeps its contrast against a dark canvas, `ink-100`/`ink-150` become the dark
neutral fills for `secondary`/hover, and `danger` shifts to its dark-calibrated value. Because the `cva`
string references only semantic tokens, the identical class list renders correctly in both themes — a
`dark:bg-*` on a button is a bug, not a feature.

## RTL

Buttons mirror automatically because they hard-code no physical direction:

- **Logical spacing only.** Internal icon/label gap is `gap-2` (direction-agnostic); any asymmetric padding
  uses `ps-*`/`pe-*`, never `pl-*`/`pr-*`. A leading icon is *inline-start*, so in RTL it correctly moves to
  the right of the label with zero conditional code (`../DESIGN_LANGUAGE.md → RTL mirroring`).
- **Directional icons flip, meaningful icons don't.** A `SplitButton`'s caret and a "next"/"back" chevron
  mirror via the `[dir="rtl"] & { transform: scaleX(-1) }` rule at the icon wrapper (`../ICONOGRAPHY.md →
  RTL-Aware Icons`); a `Check`, `Trash2`, or the spinner never flips.
- **`ButtonGroup attached` borders mirror** through logical border utilities, so the segmented control's
  "first"/"last" rounded ends land on the correct visual edge per direction.
- **Labels are the localized string**, rendered in IBM Plex Sans Arabic in the `ar` locale via the font
  stack's Arabic fallback (`../DESIGN_LANGUAGE.md → Font loading`); a button never contains a Western-numeral
  amount that would need its own `dir="ltr"` span, because amounts live in `AmountCell`, not in button
  labels.

# i18n

- **Every label is a key.** Button `children` is always `t('...')`, never a literal — "Post entry" is
  `t('journalEntries.post')`, resolved through `useTranslations(namespace)` (`../README.md → Conventions →
  Internationalization`). A hard-coded English label is caught in review and by the `i18n:check` CI gate,
  since it would have no Arabic counterpart.
- **`loadingLabel` is a key too** ("Posting…" = `t('journalEntries.posting')`), and its Arabic form uses the
  proposing/continuous register appropriate to Gulf business Arabic (`../DESIGN_LANGUAGE.md → Arabic
  microcopy`).
- **Width is designed for the longer string.** Arabic labels commonly run longer than English at the same
  size; because buttons are `whitespace-nowrap` and width-flexible (not fixed-width), they grow to fit
  rather than truncate. A toolbar of buttons is laid out so the longest localized label per button still
  fits its breakpoint — never tuned against English sample text alone (`../COMPONENT_LIBRARY.md → Edge Cases
  → Long bilingual names`).
- **Verb-object labels survive translation.** The "verb + object" rule holds in both languages: "Post
  entry" → "ترحيل القيد," "Save draft" → "حفظ كمسودة," "Approve" → "اعتماد," "Reject" → "رفض" — the exact
  strings from `../DESIGN_LANGUAGE.md → Arabic microcopy`, imported as keys rather than re-translated per
  screen.
- **Permission tooltips localize the frame, not the key.** "Requires `accounting.journal.post`" → "يتطلب
  صلاحية `accounting.journal.post`" — the surrounding sentence is translated; the permission key itself is a
  fixed Latin token in both languages, rendered in a `dir="ltr"` code span.

# Testing

**Storybook.** `button.stories.tsx` renders the full variant × size matrix plus the `loading`, native
`disabled`, and `aria-disabled`-with-tooltip states, and `IconButton`, `SplitButton`, and `ButtonGroup`
each ship their own story file. The global decorator toggles `dir` and `data-theme`, so every button state is
inspectable in all four LTR/RTL × light/dark combinations without duplicated stories
(`../COMPONENT_LIBRARY.md → Testing`).

```tsx
// components/ui/button.stories.tsx (excerpt)
export const Variants: StoryObj<typeof Button> = {
  render: () => (
    <div className="flex flex-wrap gap-3">
      {(['default','secondary','outline','ghost','destructive','destructive-quiet','link'] as const)
        .map((v) => <Button key={v} variant={v}>{v}</Button>)}
    </div>
  ),
};
export const Loading: StoryObj<typeof Button> = { args: { loading: true, children: 'Post entry', loadingLabel: 'Posting…' } };
export const RbacDisabled: StoryObj<typeof Button> = {
  args: { children: 'Post entry', ['aria-disabled' as string]: true },
  parameters: { permissionTooltip: 'accounting.journal.post' },
};
```

**Vitest + Testing Library** covers the behaviors, not the pixels:

```tsx
// components/ui/button.test.tsx
test('loading disables activation and sets aria-busy without changing children', async () => {
  const onClick = vi.fn();
  const { rerender, getByRole } = render(<Button onClick={onClick}>Post entry</Button>);
  const btn = getByRole('button', { name: 'Post entry' });
  rerender(<Button loading onClick={onClick}>Post entry</Button>);
  expect(btn).toHaveAttribute('aria-busy', 'true');
  fireEvent.click(btn);
  expect(onClick).not.toHaveBeenCalled();         // click blocked while pending
  expect(btn).toHaveTextContent('Post entry');    // label not swapped away → no reflow
});

test('aria-disabled RBAC button stays focusable and intercepts click', () => {
  const onClick = vi.fn();
  const { getByRole } = render(<Button aria-disabled onClick={onClick}>Post entry</Button>);
  const btn = getByRole('button', { name: 'Post entry' });
  btn.focus();
  expect(btn).toHaveFocus();                       // unlike native disabled, still reachable
  fireEvent.click(btn);
  expect(onClick).not.toHaveBeenCalled();
});

test('asChild renders a real anchor, not a button', () => {
  const { getByRole } = render(<Button asChild><a href="/x">Go</a></Button>);
  expect(getByRole('link', { name: 'Go' })).toHaveAttribute('href', '/x');
});
```

**Playwright** covers the two integration behaviors a unit test cannot: a `useMutation` post button showing
a real spinner across an actual (seeded) API round-trip and returning to enabled on completion, and the
permission-gated label/behavior split across two personas — an Accountant (only `create`, whose primary
button reads "Submit for approval") and a Finance Manager (holds `post`, whose button reads "Post entry")
— asserting the label and the resulting request differ per persona, exactly as `../COMPONENT_LIBRARY.md →
JournalEntryForm` requires.

**Accessibility CI.** `axe-core` runs against every button story; a missing accessible name on an
icon-only button, a suppressed focus ring, or a color-contrast regression on any variant in either theme
fails the build (`../COMPONENT_LIBRARY.md → Accessibility CI gate`). `SplitButton`'s menu additionally gets a
manual keyboard pass each release, since `axe` cannot assert a correct roving-focus order.

# Edge Cases

- **`asChild` + `loading`.** A `Slot` has no element of its own into which the spinner can be injected, so
  `loading` on an `asChild` button is a documented no-op — the implementation skips the spinner and the
  disabled attribute (an anchor cannot be natively `disabled`). A polymorphic link-as-button that needs a
  pending state must render an actual `<button>` and handle navigation in its `onClick`, not use `asChild`.
- **`link` variant sizing.** The `link` variant forces `h-auto p-0`, overriding whatever `size` is passed —
  a text link has no button height. Passing `size="lg"` to a `link` button is harmless but ignored, and the
  story matrix documents this so it is not read as a bug.
- **Double-submit under a slow network.** The pending state (`loading`/`isPending`) blocks a second
  activation; for the Server-Action path without a client pending signal, the underlying action carries an
  idempotency key (`../../api/API_IDEMPOTENCY.md`) so a fast double-click cannot post twice.
- **Permission revoked mid-session.** A button rendered enabled on mount may be stale 20 minutes later; the
  driving mutation re-checks server-side and a `403` refetches the permission cache and toasts "access
  changed," per `../COMPONENT_LIBRARY.md → Edge Cases → Permission removed mid-session`. The button never
  treats its mount-time permission read as final for a sensitive action.
- **`destructive` fired by keyboard `Enter` on the underlying trigger.** Because solid destructive actions
  always route through an `AlertDialog` whose default focus is *Cancel*, a stray `Enter` cannot destroy
  data — it cancels. This is why a bare `<Button variant="destructive" onClick={hardDelete}>` with no
  confirmation dialog is a review-blocking pattern.
- **`SplitButton` with every alternative permission-denied.** If the caller lacks the permission for every
  `actions[]` item, the caret still renders but its menu shows the empty-but-explained state ("No
  additional actions available") rather than an empty popover; if the *primary* is also denied, the whole
  `SplitButton` collapses to a single disabled-with-tooltip primary segment.
- **`ButtonGroup collapseAt` and a primary action.** When a toolbar collapses into the "…" overflow menu on
  a narrow viewport, the single `default`/primary button is pinned *outside* the overflow (it stays a
  visible button) and only the secondary/`outline`/`ghost` actions move into the menu — the primary action
  is never hidden behind a "more" affordance.
- **Loading spinner under reduced motion.** `motion-reduce:animate-none` leaves a *static* `Loader2` glyph;
  combined with `aria-busy` and the dimmed label, the pending state is fully communicated without rotation.
  A reduced-motion user still sees that the button is working — this is the correct reading of the media
  query, not a degraded one.
- **A button inside an RTL toolbar with a Western-numeral count in its label** (e.g. "Post 3 entries").
  The numeral is wrapped in a `dir="ltr"` span inside the label so "3" reads correctly inside the RTL
  phrase — the same numerals-never-mirror rule `AmountCell` and `CurrencyTag` enforce
  (`../DESIGN_LANGUAGE.md → Spacing & Grid`); the button does not use `AmountCell` because the value is a
  count, not money.

# End of Document
