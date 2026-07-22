# Modal — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / MODAL
---

# Purpose

This document is the atomic specification for QAYD's dialog/modal primitive — the low-level, portalled,
focus-trapping overlay that every centered dialog composes. It is the design-system foundation beneath the
application-level [`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md), which owns
the *product* rules: the four dialog kinds (confirmation, form, destructive/type-to-confirm, AI-proposal
review), the `ConfirmDialog` convenience wrapper, and the Modal-vs-Drawer-vs-Route decision matrix. **This
document does not restate those product rules; it specifies the primitive they all sit on** — its sizes,
its focus-trap/focus-return contract, its ESC/backdrop dismissal policy, its scroll-lock, its enter/exit
animation, its stacking, and the `aria-modal` semantics — so both `Dialog` (recoverable) and `AlertDialog`
(explicit-choice) inherit one consistent, token-and-motion-correct foundation.

A modal is QAYD's affordance for a **short, self-contained, focus-demanding interaction that must be
resolved before the user returns to the page beneath it.** Its scrim deliberately *obscures* the page — if
the user needs to keep seeing the content behind while they act, the correct primitive is a
[Drawer](./DRAWER.md), whose scrim is dimmer and whose panel sits beside the content, not over it. That
choice is settled by the shared decision matrix in
[`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md); this document assumes the
choice is already "modal" and specifies the object.

The primitive is shadcn/ui over Radix `@radix-ui/react-dialog` (and `@radix-ui/react-alert-dialog` for the
explicit-choice variant), keeping Radix's `FocusScope`, scroll-lock, `aria-modal`, and dismissal wiring
untouched and adding QAYD's token styling, `size` variant, and the `surfaceScale`+`scrim` Framer entrance.
All motion resolves to [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); all color, radius, shadow, and z-index
resolve to [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

# Anatomy

```
        ╱ scrim (z-overlay 30, bg-ink-12/50, click = dismiss for Dialog only) ╲
 ┌───────────────────────────────────────────────┐  ← DialogContent, z-modal 40, surface-3
 │  Post journal entry JE-2026-07-0482?      [x]  │  ← DialogTitle (aria-labelledby) + close (Dialog only)
 │  ─────────────────────────────────────────────│
 │  This will post 4 lines totalling             │  ← DialogDescription (aria-describedby)
 │  1,050.000 KWD to the general ledger.          │
 │  ┄┄┄┄ body scrolls internally if it overflows ┄│  ← body region (overflow-y-auto)
 │                          [ Cancel ]  [ Post ]  │  ← DialogFooter (primary at inline-end)
 └───────────────────────────────────────────────┘
```

| Part | Element | Role | Notes |
|---|---|---|---|
| Portal | `#portal-root` | — | Every modal portals to the document root, outside the shell subtree, so no sticky header/sidebar can clip or trap it. |
| Scrim | `motion.div` | presentational | `bg-ink-12/50` overlay at `z-overlay` (30); fades on the `scrim` variant. Click dismisses a `Dialog`; ignored by `AlertDialog`. |
| `DialogContent` | `div` | `dialog` / `alertdialog` | `surface-3` panel at `z-modal` (40); `aria-modal="true"`; the focus trap's scope. |
| `DialogTitle` | `h2` | — | **Required** — wired as the accessible name via `aria-labelledby`; a titleless dialog is a lint-time failure. |
| `DialogDescription` | `p` | — | Optional; wired via `aria-describedby`; where a destructive consequence is stated in words. |
| Close `x` | `button` | — | `Dialog` only, at the inline-end corner; `AlertDialog` omits it entirely (its only exits are Cancel and the confirming action). |
| `DialogFooter` | `div` | — | Actions in source order (Cancel then primary) so the primary lands at the visual inline-end and flips correctly in RTL. |

# Variants

Two primitives and one size axis. The two primitives are a behavioral choice, not a style one; the size
axis changes only the panel's max-width — every size shares one radius, shadow, and padding scale.

| Primitive | Radix base | ESC / backdrop dismiss? | For |
|---|---|---|---|
| `Dialog` | `@radix-ui/react-dialog` | Yes by default (overridable; guarded when dirty) | Recoverable forms, AI-proposal review, non-destructive informational modals |
| `AlertDialog` | `@radix-ui/react-alert-dialog` | **No** — forces an explicit Cancel/Confirm | Confirmations, destructive and type-to-confirm dialogs |

| `size` | Max width | Typical use |
|---|---|---|
| `sm` | `24rem` (384px) | Single-question confirmations, type-to-confirm |
| `md` | `32rem` (512px) — **default** | Short forms (2–5 fields), AI-proposal review |
| `lg` | `44rem` (704px) | A two-column form still short of a page |
| `full` | Inset 16px, fills the viewport | A line-grid form (e.g. `JournalEntryForm`) on **mobile widths only** |

There is no `xl`/`2xl`: an interaction needing more room than `lg` is a signal it should be a **route** or
a **[Drawer](./DRAWER.md)**, per the decision matrix in
[`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md). `full` is a responsive escape
hatch for one already-modal form on a phone, not a license for large desktop modals. The panel outer
surface uses `radius-xl` (10px — the one place the 10px ceiling is spent); its inner content uses
`radius-lg` (8px).

# Props / API

The primitive is a Client Component (it owns open state, focus, and event handlers), always **controlled**
so a mutation's success can close it and an in-flight mutation can keep it open. It is mounted where it is
triggered, not centralized, so its data and mutation live next to the record it acts on.

## `Dialog` / `DialogContent`

| Prop | Type | Required | Description |
|---|---|---|---|
| `open` | `boolean` | yes (controlled) | The parent owns open state. |
| `onOpenChange` | `(open: boolean) => void` | yes | Radix calls this on ESC, backdrop click, and close-button click; QAYD wraps it to enforce the dirty-form guard (see the app spec). |
| `size` | `'sm' \| 'md' \| 'lg' \| 'full'` | no, default `'md'` | Panel max-width. |
| `dismissible` | `boolean` | no, default `true` | When `false`, ESC and backdrop are ignored and `x` is hidden — used while a non-cancelable mutation is mid-flight. |
| `onCloseAutoFocus` | `(e: Event) => void` | no | Override the focus-return target only when the trigger was unmounted by the action (rare). |

## `AlertDialog`

| Prop | Type | Required | Description |
|---|---|---|---|
| `open` | `boolean` | yes | Controlled. |
| `onOpenChange` | `(open: boolean) => void` | yes | Fires on Cancel and on the confirming action; never on backdrop click (Radix construction). |

`AlertDialogAction` and `AlertDialogCancel` are required children; there is no close `x`. The product-level
`ConfirmDialog` wrapper (plain / destructive / type-to-confirm) is specified in
[`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md) `# ConfirmDialog`; it composes
this primitive and adds no new overlay behavior.

```tsx
// components/ui/dialog.tsx (the QAYD primitive — Radix wiring + token styling + animation)
'use client';

import * as DialogPrimitive from '@radix-ui/react-dialog';
import { motion, useReducedMotion } from 'framer-motion';
import { cva, type VariantProps } from 'class-variance-authority';
import { duration, easeOut, easeIn } from '@/lib/motion';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

const contentVariants = cva(
  'fixed left-1/2 top-1/2 z-modal grid w-full -translate-x-1/2 -translate-y-1/2 gap-4 ' +
    'rounded-xl border border-ink-6 bg-[--surface-3] p-6 shadow-lg ' +
    'max-h-[85vh] overflow-hidden',
  {
    variants: { size: { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-[44rem]', full: 'inset-4 max-w-none translate-x-0 translate-y-0 left-0 top-0' } },
    defaultVariants: { size: 'md' },
  },
);

export function DialogContent({
  size, dismissible = true, className, children, ...props
}: React.ComponentProps<typeof DialogPrimitive.Content> & VariantProps<typeof contentVariants> & { dismissible?: boolean }) {
  const reduced = useReducedMotion();
  return (
    <DialogPrimitive.Portal>
      {/* scrim — same timeline as the panel; obscures the page (a modal, not a drawer) */}
      <DialogPrimitive.Overlay asChild>
        <motion.div
          className="fixed inset-0 z-[30] bg-ink-12/50"
          initial={reduced ? false : { opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={reduced ? undefined : { opacity: 0 }}
          transition={{ duration: reduced ? 0 : duration.moderate, ease: easeOut }}
        />
      </DialogPrimitive.Overlay>

      <DialogPrimitive.Content
        onEscapeKeyDown={dismissible ? undefined : (e) => e.preventDefault()}
        onPointerDownOutside={dismissible ? undefined : (e) => e.preventDefault()}
        asChild
        {...props}
      >
        <motion.div
          className={cn(contentVariants({ size }), className)}
          initial={reduced ? false : { opacity: 0, scale: 0.98 }}
          animate={{ opacity: 1, scale: 1 }}
          exit={reduced ? undefined : { opacity: 0, scale: 0.98 }}
          transition={{ duration: reduced ? 0 : duration.moderate, ease: easeOut }}
        >
          {children}
          {dismissible && (
            <DialogPrimitive.Close
              className="absolute end-4 top-4 rounded-sm text-ink-9 hover:text-ink-11
                         focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
              <X className="h-4 w-4" />
              <span className="sr-only">Close</span>
            </DialogPrimitive.Close>
          )}
        </motion.div>
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}
```

The panel uses `surfaceScale` (`0.98 → 1` scale + fade) and the scrim uses `scrim` (fade) — the two shared
variants from [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# The variant library`. They share one timeline
(`motion.moderate` in, `motion.fast` out) so they read as one object, per the coordinated-pairs rule. The
exit half (elided in the snippet's `exit` props, driven by Radix + `AnimatePresence` at the call site) runs
one step faster on `easeIn`.

# States

Every state ships in full light/dark and LTR/RTL parity. The behavioral states (submitting, error,
dirty-blocked close) are specified with their mutation contracts in
[`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md) `# States`; the primitive-level
visual states are:

| State | Behavior |
|---|---|
| **Closed** | Not in the DOM — Radix portals mount only while `open`, so a closed modal costs nothing and holds no stale focus. |
| **Opening / open** | Scrim fades in (`moderate`, `easeOut`); panel scales `0.98 → 1` + fades on the same timeline. Focus moves to the first focusable element inside, or the panel itself if none. |
| **Non-dismissible** | `dismissible={false}` ignores ESC and backdrop and hides `x` — used while a non-cancelable mutation is in flight, so a stray ESC cannot orphan the write. |
| **Overflowing body** | Header and footer are pinned; the body region scrolls internally (`overflow-y-auto`) so the primary action is never pushed off-screen; `<body>` behind stays scroll-locked. |
| **Closing** | Panel scales `1 → 0.98` + fades on `motion.fast`, `easeIn`; scrim fades out on the same faster timeline; focus returns to the trigger. |
| **Reduced motion** | Scrim and panel appear/disappear instantly (no scale, no fade); focus move, scroll lock, and trap are unchanged — motion removed, behavior intact. |

# Tokens Used

Every value resolves to a token in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) or a motion token in
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md). No raw hex, px, shadow, or `cubic-bezier` in the source.

| Concern | Token | Value (light / dark) |
|---|---|---|
| Scrim fill | `ink-12` @ 50% | `#15130E`/50% · re-tuned (not inverted) in dark |
| Panel surface | `surface-3` (`ink-1` light / `ink-3` dark) | elevated over the scrimmed page |
| Panel border | `ink-6` | `#C2BEB6` / `#46402F` |
| Panel shadow | `shadow-lg` | `0 24px 48px -12px rgba(21,19,14,.16)` (dark: ~50% alpha) |
| Panel outer radius | `radius-xl` | 10px (the one 10px surface) |
| Inner content radius | `radius-lg` | 8px |
| Title | `ink-12` | `#15130E` / `#F8F6EF` |
| Description | `ink-11` | `#2B2820` / `#E4DEC9` |
| Close `x` | `ink-9` → `ink-11` on hover | — |
| Destructive action | `negative` (via `Button variant="destructive"`) | `#B4232E` / `#F26B74` |
| Focus ring | `accent` (via `--ring`) | `#9C7A34` / `#D9B96C` |
| Scrim + panel enter | `motion.moderate` + `easeOut` | 280ms · `[0.16,1,0.3,1]` |
| Scrim + panel exit | `motion.fast` + `easeIn` | 160ms · `[0.7,0,0.84,0]` |
| Scrim z-layer | `z-overlay` | 30 |
| Panel z-layer | `z-modal` | 40 |
| Reduced motion | `motion.instant` | 0ms |

The enter uses `motion.moderate` on `easeOut` (a placed entrance); the exit is one step faster
(`motion.fast`) on `easeIn` (an intentional dismissal), the standard enter/exit asymmetry from
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# Choreography`. A centered dialog **scales and fades** — it
never slides from off-screen, because a centered surface has no inline edge to belong to (that is a
drawer's motion).

## Stacking & z-index

The design-system z-index tiers ([`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) `# Z-Index`) place the modal
scrim at `z-overlay` (30) and the panel at `z-modal` (40), above the [Drawer](./DRAWER.md) tier and below
`z-command` (60) and `z-tooltip` (70):

| Layer | Token | Value |
|---|---|---|
| Sticky headers | `z-sticky` | 10 |
| Dropdowns / popovers | `z-dropdown` | 20 |
| Modal / drawer scrim | `z-overlay` | 30 |
| Modal / drawer panel | `z-modal` | 40 |
| Command palette | `z-command` | 60 |
| Tooltip | `z-tooltip` | 70 |

A confirmation raised *from within* a [Drawer](./DRAWER.md) stacks correctly with no manual ordering
because both live at the `z-modal` tier and each Radix `Root` owns its own focus scope — the top-most layer
holds the active trap, and closing it returns focus to the layer beneath. The application spec
([`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md) `# Mounting, portal, and
z-index`) documents a finer-grained internal split of this tier (scrim 50 / panel 51 above drawer 40/41);
those app values and these design-system tiers describe the same ordering — modal above drawer, command
above modal, tooltip above all — and must not be read as conflicting scales.

# Accessibility

The primitive inherits Radix's full accessibility contract; QAYD's rules are what must never be broken on
top, mirroring [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) and
[`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md) `# Accessibility`.

| Concern | Contract |
|---|---|
| Role & modality | `role="dialog"` (or `alertdialog`) with `aria-modal="true"`, both from Radix. |
| Accessible name | Every modal has a required `DialogTitle` wired via `aria-labelledby` — a titleless dialog is a lint-time failure, never shipped. A visually-hidden title covers the rare no-visible-heading case. |
| Description | The consequence/instruction is a `DialogDescription` via `aria-describedby`, so a screen reader hears *what the action does* before reaching the confirm button; a destructive action states its irreversibility here in words, not via a red button alone. |
| Focus trap | Radix `FocusScope` traps Tab within the panel while open; the page behind is `aria-hidden` + inert automatically. No manual trap. |
| Focus return | On close, focus returns to the trigger (which stays mounted); `onCloseAutoFocus` redirects to a stable anchor only when the trigger was removed by the action. |
| Scroll lock | Radix locks `<body>` scroll while open; the panel's own body scrolls internally between a pinned header and footer. |
| Keyboard | `Esc` closes a `Dialog` (subject to the dirty guard) and cancels an `AlertDialog`; `Tab`/`Shift+Tab` cycle within; `Enter` activates the focused action; the primary action is mouse-free reachable. |
| Colour is never the only signal | A destructive modal's danger is carried by the description wording and the action verb, not by button color — correct in grayscale and for colorblind users. |
| Reduced motion | Scrim fade and panel scale honor `prefers-reduced-motion: reduce` — the modal appears/disappears instantly; behavior is unchanged. |

# Theming, Dark Mode & RTL

**Tokens only.** The scrim is `bg-ink-12/50`, the panel `bg-[--surface-3]` with `shadow-lg` and
`radius-xl`; the destructive action uses the `negative` token via `Button variant="destructive"`. No raw
hex, px, or `dark:`-raw-color variant appears in the source.

**Dark mode.** Applied by the shell's `next-themes` provider via the `.dark` re-declaration of the same
`--qayd-*` names (`../DESIGN_TOKENS.md → Light / Dark Remap Mechanism`). `surface-3` is a distinct dark
token (elevation gets *lighter* as it rises, so the panel reads as clearly raised over the darkened page),
and the scrim opacity is re-tuned rather than inverted so the elevation contrast holds. The primitive
carries no dark branch of its own.

**RTL.** Set once at the root via `dir` on `<html>`. Because the modal uses logical layout — `DialogFooter`
orders Cancel then the primary action in *source* order (so the primary lands at the visual inline-end and
flips in RTL), padding is symmetric or `ps-*/pe-*`, and the close `x` sits at the inline-end corner
(`end-4`) — nothing needs an `if (locale === 'ar')` branch. The panel's scale+fade has **no inline
direction to mirror** (a centered scale is direction-agnostic, per
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# What mirrors and what does not`), so the modal's motion is
identical in both scripts. Two explicit pins: any **amount** in a confirmation ("post 1,050.000 KWD")
renders through the `<Amount>` contract (`dir="ltr"`, Western Arabic numerals), never reversed; and a
**type-to-confirm token** that is a Latin string is compared and displayed `dir="ltr"` so the user types it
in the same visual order it is shown.

# Do / Don't

| Do | Don't |
|---|---|
| Reach for a modal only when the interaction must be resolved before returning and needs no URL. | Use a modal where the user needs to see the list behind it — that is a [Drawer](./DRAWER.md); or where a URL is wanted — that is a route. |
| Use `AlertDialog` for every destructive or irreversible confirmation. | Let a stray backdrop click or reflexive ESC read as "confirm" — that is exactly what `AlertDialog` prevents. |
| Scale + fade a centered panel; share one timeline with the scrim. | Slide a centered modal in from an edge — a centered surface has no inline edge to belong to. |
| Keep `dismissible={false}` while a non-cancelable mutation is in flight. | Allow ESC/backdrop to orphan an in-flight write behind a closed modal. |
| Cap panel width at `lg`; drop to `full` only on mobile for a line-grid form. | Add an `xl`/`2xl` desktop modal — the interaction should be a route or drawer. |
| State a destructive consequence in the `DialogDescription` words. | Convey danger by red button color alone. |
| Let focus return to the trigger; use `onCloseAutoFocus` only when the trigger unmounted. | Let focus fall to `<body>` after a delete removes the triggering row. |

# Usage & Composition

The primitive is composed, never hand-rolled, by every dialog surface. The product kinds and their
mutation contracts are specified in
[`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md); here is the primitive-level
shape a form dialog composes:

```tsx
// A controlled form dialog closed by its own mutation, not by the submitting click.
'use client';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';

export function NewCostCenterDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const create = useCreateCostCenter();               // a useMutation, so a failure never remounts the dialog
  return (
    <Dialog open={open} onOpenChange={onOpenChange} dismissible={!create.isPending}>
      <DialogContent size="sm">
        <DialogHeader>
          <DialogTitle>New cost center</DialogTitle>
          <DialogDescription>Cost centers group entries for reporting.</DialogDescription>
        </DialogHeader>
        {/* fields … */}
        <DialogFooter>
          <Button variant="secondary" onClick={() => onOpenChange(false)} disabled={create.isPending}>Cancel</Button>
          <Button loading={create.isPending} onClick={submit}>Create</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
```

Composition rules, each a platform discipline made concrete at the overlay layer:

1. **The primitive owns overlay behavior; the composing dialog owns content and mutation.** A dialog passes
   `open`, `onOpenChange`, `size`, and children; it never re-implements the focus trap, scroll lock, or the
   scrim/panel animation — those live once in `components/ui/dialog.tsx`.
2. **Close on the mutation, never on the click.** The parent sets `open=false` in the mutation's
   `onSuccess`; a failure keeps the dialog mounted and re-enabled — which is exactly why an
   error-recoverable form dialog is a `useMutation`, not a Server Action
   ([`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md) `# States`).
3. **AI review renders the full contract.** An AI-proposal review dialog always shows confidence, reasoning
   (via [Accordion](./ACCORDION.md)/`ReasoningDisclosure`), and source citations, and never turns an
   "approve" click into an auto-commit (`../../frontend/components/MODALS.md` `# AI-proposal review
   dialog`).
4. **Stacking is automatic.** A confirmation raised from a drawer, or a discard/reason `AlertDialog` raised
   from a form dialog, stacks by the z-tiers with no manual ordering — the only sanctioned stacks; a
   form-opening-a-form is a decision-matrix violation, not a thing to style around.

For the dialog kinds, the `ConfirmDialog` wrapper, the dirty-form discard guard, the decision matrix, and
the full edge-case table, see [`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md);
for the sibling side-sheet primitive, see [`./DRAWER.md`](./DRAWER.md); for the motion tokens the
scrim/panel consume, see [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); for the tokens and z-index tiers, see
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

# End of Document
