# Drawer — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / DRAWER
---

# Purpose

This document is the atomic specification for QAYD's drawer/sheet primitive — the low-level, portalled,
edge-anchored overlay that slides in from an inline edge and every side-sheet composes. It is the
design-system foundation beneath the application-level
[`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md), which owns the *product*
rules: the drawer kinds (record-detail, the Approval Drawer, filter drawer, multi-step wizard), the
non-remount `useMutation` guarantee, the AI-contract that the Approval Drawer holds, and the shared
Modal-vs-Drawer-vs-Route decision matrix. **This document does not restate those product rules; it
specifies the primitive they all sit on** — its `side` and `width` variants, its RTL slide direction, its
focus/scroll/ESC contract, its inline-edge translate animation, its modal-vs-non-modal behavior, its
stacking, and the `aria-modal` semantics.

A drawer is QAYD's affordance for **inspecting or acting on a record without leaving the context behind
it** — a row-detail panel that slides in over a list while the list stays visible, an approval that reads
with its AI reasoning alongside the queue. It is the deliberate counterpart to a [Modal](./MODAL.md): where
a modal's scrim *obscures* the page to demand a focused, must-resolve interaction, a drawer *preserves* the
page (its scrim is dimmer) so the user acts with the surrounding context still on screen. That choice is
settled by the shared decision matrix in
[`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md); this document assumes the
choice is already "drawer" and specifies the object.

The primitive is shadcn/ui over Radix `@radix-ui/react-dialog` in its side presentation, keeping Radix's
`FocusScope`, scroll-lock, and `aria-modal` wiring untouched and adding QAYD's token styling, the
`side`/`width` variants, and the direction-aware translate entrance. All motion resolves to
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); all color, radius, shadow, and z-index resolve to
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

# Anatomy

```
 list / workbench stays visible ──────────────────╮   ╭─ scrim (z-overlay 30, ink-12/30 — dimmer than a modal)
                                                   │   │
 ┌──────────────────────────────┬─────────────────┴───┴─────────┐
 │  Journal entries (still here) │  JE-2026-07-0482          [x] │ ← SheetContent, z-modal 40, surface-3
 │  ▸ JE-…0481    450.000        │  ──────────────────────────── │   slides from inline-end (LTR: right)
 │  ▸ JE-…0482 ◂ selected        │  Rent accrual · Posted        │   via transform: translateX
 │  ▸ JE-…0483    980.000        │  [ ConfidenceBadge 92% ]      │
 │                               │  Reasoning ▸ (disclosure)     │ ← body scrolls independently
 │                               │  ──────────────────────────── │
 │                               │       [ Reject ] [ Approve ]  │ ← pinned footer, primary at inline-end
 └──────────────────────────────┴───────────────────────────────┘
```

| Part | Element | Role | Notes |
|---|---|---|---|
| Portal | `#portal-root` | — | Every drawer portals to the document root, outside the shell subtree, so no sticky header/sidebar can clip it. |
| Scrim | `motion.div` | presentational | `bg-ink-12/30` — deliberately **dimmer** than a modal's `/50`, because the page behind is meant to stay legible. Fades on the `scrim` variant. |
| `SheetContent` | `div` | `dialog` | `surface-3` panel at `z-modal` (40), anchored to one inline edge; `aria-modal="true"` (or `false` for a non-modal filter drawer); the focus trap's scope. |
| `SheetHeader` | `div` | — | Hosts the required `SheetTitle` (the accessible name) and the close `x` at the inline-end corner. |
| Body | `div` | — | Scrolls independently of the page via `overflow-y-auto` between a pinned header and footer. |
| `SheetFooter` | `div` | — | Optional; actions in source order (Cancel/Reject then primary) so the primary lands at the visual inline-end and flips in RTL. |

# Variants

Two variant axes: `side` (which edge the panel anchors to) and `width` (its inline size). Neither is a
style choice — `side` is resolved from `dir` at render time and `width` is capped so that anything larger
is a signal to use a route instead.

## Side

| `side` | LTR renders from | RTL renders from | Used for |
|---|---|---|---|
| `end` (**default**) | right edge | left edge | Record-detail panels, the Approval Drawer, multi-step wizards — the overwhelming majority |
| `start` | left edge | right edge | The mobile sidebar-as-sheet only (navigation slides from where the sidebar lives) |
| `bottom` | bottom edge | bottom edge | Mobile "More" nav sheet, mobile quick-create, a filter sheet on a phone |

`side="end"` is **not** "right" — it is the **inline-end** edge, resolved from the ambient `dir`, never
hardcoded. A detail drawer slides in from the right in English and from the left in Arabic with zero
conditional logic, because the primitive reads the resolved direction. This is the drawer-level application
of the RTL-aware directional-motion rule in [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# RTL-Aware
Directional Motion`: the slide is along the inline axis, which flips with `dir`; a `bottom` sheet is on the
**block** axis, which does *not* flip, so it enters from the bottom in both scripts.

## Width

| `width` | Inline size | Typical use |
|---|---|---|
| `sm` | `360px` | A compact filter panel, a single-record summary |
| `md` | `440px` — **default** | Record-detail panels, the Approval Drawer |
| `lg` | `560px` | A multi-step wizard or a detail panel with a data table inside |
| `full` | Fills the viewport (mobile) | Any drawer below `sm` — a detail panel becomes full-screen rather than a cramped sliver on a phone |

A drawer wider than `lg` is a signal the interaction should be a **route**, per the decision matrix in
[`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md). There is no persisted
"drawer width" preference — a drawer is transient, opened for one interaction and dismissed, unlike the
sidebar's persisted collapse. The panel's inner corners use `radius-lg` (8px); the outer surface is flush
to the viewport edge it anchors to.

# Props / API

The primitive is a Client Component (open state, focus, mutations), always **controlled** so a mutation's
success can close it and an in-flight mutation can keep it open. It is mounted next to the context it acts
on — a detail drawer lives in the list screen that opens it — never in a global registry.

## `Sheet` / `SheetContent`

| Prop | Type | Required | Description |
|---|---|---|---|
| `open` | `boolean` | yes (controlled) | The parent owns open state. |
| `onOpenChange` | `(open: boolean) => void` | yes | Radix fires on ESC, scrim click, and close-button click; QAYD wraps it to enforce the dirty-form guard (see the app spec). |
| `side` | `'end' \| 'start' \| 'bottom'` | no, default `'end'` | Inline-resolved; never a physical `left`/`right`. |
| `width` | `'sm' \| 'md' \| 'lg' \| 'full'` | no, default `'md'` | Panel inline size. |
| `dismissible` | `boolean` | no, default `true` | When `false`, ESC and scrim click are ignored and `x` is hidden — used while a non-cancelable mutation is mid-flight, and for a wizard past step 1 that must route a stray click through the discard guard. |
| `modal` | `boolean` | no, default `true` | `true` scrim-blocks the page behind (the norm). A rare **non-modal** filter drawer (`false`) lets the user keep scrolling the list behind it while adjusting filters — used only where interacting with the background *is* the point. |

`SheetContent` composes `SheetHeader` (with a required `SheetTitle`), a scrollable body, and an optional
`SheetFooter`. The product drawer kinds — `DetailSheet`, `ApprovalDrawer`, `FilterSheet`, `WizardSheet` —
and their data props are specified in
[`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md) `# Props / API`; they compose
this primitive and add no new overlay behavior.

```tsx
// components/ui/sheet.tsx (the QAYD primitive — Radix side presentation + direction-aware slide)
'use client';

import * as SheetPrimitive from '@radix-ui/react-dialog';
import { motion, useReducedMotion } from 'framer-motion';
import { cva, type VariantProps } from 'class-variance-authority';
import { duration, easeOut } from '@/lib/motion';
import { useInlineDirection } from '@/lib/motion';    // +1 for LTR, -1 for RTL
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

const contentVariants = cva(
  'fixed z-modal flex flex-col gap-4 border-ink-6 bg-[--surface-3] shadow-lg overflow-hidden',
  {
    variants: {
      side: {
        end: 'inset-y-0 end-0 h-full border-s rounded-s-lg',      // inline-end edge, logical
        start: 'inset-y-0 start-0 h-full border-e rounded-e-lg',
        bottom: 'inset-x-0 bottom-0 w-full border-t rounded-t-lg', // block axis — does not mirror
      },
      width: { sm: 'w-[360px]', md: 'w-[440px]', lg: 'w-[560px]', full: 'w-full' },
    },
    defaultVariants: { side: 'end', width: 'md' },
  },
);

export function SheetContent({
  side = 'end', width, dismissible = true, modal = true, className, children, ...props
}: React.ComponentProps<typeof SheetPrimitive.Content> &
   VariantProps<typeof contentVariants> & { dismissible?: boolean; modal?: boolean }) {
  const reduced = useReducedMotion();
  const sign = useInlineDirection();                  // resolves side="end" to the correct physical edge
  // inline slide for end/start (mirrors with dir); vertical slide for bottom (never mirrors)
  const from = side === 'bottom' ? { y: 24 } : { x: sign * (side === 'start' ? -24 : 24) };

  return (
    <SheetPrimitive.Portal>
      <SheetPrimitive.Overlay asChild>
        <motion.div
          className="fixed inset-0 z-[30] bg-ink-12/30"       // /30 — dimmer than a modal's /50
          initial={reduced ? false : { opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={reduced ? undefined : { opacity: 0 }}
          transition={{ duration: reduced ? 0 : duration.moderate, ease: easeOut }}
        />
      </SheetPrimitive.Overlay>

      <SheetPrimitive.Content
        onEscapeKeyDown={dismissible ? undefined : (e) => e.preventDefault()}
        onPointerDownOutside={dismissible ? undefined : (e) => e.preventDefault()}
        asChild
        {...props}
      >
        <motion.div
          className={cn(contentVariants({ side, width }), className)}
          // movement is transform-only (translate), never animating inset/left — compositor-friendly
          initial={reduced ? false : { opacity: 0, ...from }}
          animate={{ opacity: 1, x: 0, y: 0 }}
          exit={reduced ? undefined : { opacity: 0, ...from }}
          transition={{ duration: reduced ? 0 : duration.moderate, ease: easeOut }}
        >
          {children}
          {dismissible && (
            <SheetPrimitive.Close
              className="absolute end-4 top-4 rounded-sm text-ink-9 hover:text-ink-11
                         focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent">
              <X className="h-4 w-4" />
              <span className="sr-only">Close</span>
            </SheetPrimitive.Close>
          )}
        </motion.div>
      </SheetPrimitive.Content>
    </SheetPrimitive.Portal>
  );
}
```

The panel moves via `transform: translateX()` (or `translateY()` for `bottom`), never via
`left`/`inset-inline` — a compositor-friendly animation per
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# Performance`. The `useInlineDirection()` helper (from
`lib/motion.ts`) returns `+1` for LTR and `-1` for RTL, so the `end` slide's sign is expressed once and
mirrors automatically. The panel and its scrim share one timeline (`motion.moderate` in, `motion.fast`
out) so they read as one object.

# States

Every state ships in full light/dark and LTR/RTL parity. The behavioral states (loading a detail,
submitting, inline error, the already-decided Approval empty state) are specified with their mutation
contracts in [`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md) `# States`; the
primitive-level visual states are:

| State | Behavior |
|---|---|
| **Closed** | Not in the DOM — Radix portals mount only while `open`; a closed drawer costs nothing and holds no stale focus or scroll position. |
| **Opening / open** | Scrim fades in (dimmer than a modal's); the panel slides in from the inline edge via `translate` over `motion.moderate`, `easeOut`. Focus moves to the first focusable element inside, or the panel itself. |
| **Non-dismissible** | `dismissible={false}` ignores ESC and scrim click and hides `x` — used while a non-cancelable mutation is in flight, and for a wizard past step 1. |
| **Non-modal** | `modal={false}` does **not** lock body scroll or inert the page — the one sanctioned case (a tablet filter drawer) where scrolling the list behind it while filtering is the point. |
| **Overflowing body** | Header and footer are pinned; the body scrolls internally (`overflow-y-auto`) so the footer's actions are always reachable; `<body>` behind stays scroll-locked (modal drawer). |
| **Closing** | Panel slides back to its inline edge on `motion.fast`, `easeIn`; scrim fades out on the same faster timeline; focus returns to the trigger. |
| **Reduced motion** | The panel appears/disappears instantly (no slide, no scrim fade); focus move, scroll lock, and trap are unchanged — motion removed, behavior intact. |

# Tokens Used

Every value resolves to a token in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) or a motion token in
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md). No raw hex, px, shadow, or `cubic-bezier` in the source.

| Concern | Token | Value (light / dark) |
|---|---|---|
| Scrim fill | `ink-12` @ 30% | `#15130E`/30% — dimmer than a modal (re-tuned, not inverted, in dark) |
| Panel surface | `surface-3` (`ink-1` light / `ink-3` dark) | elevated over the (still-legible) page |
| Panel border (inline edge) | `ink-6` | `#C2BEB6` / `#46402F` |
| Panel shadow | `shadow-lg` | `0 24px 48px -12px rgba(21,19,14,.16)` (dark ~50% alpha) |
| Inner corner (`rounded-s-lg`/`t-lg`) | `radius-lg` | 8px |
| Title | `ink-12` | `#15130E` / `#F8F6EF` |
| Body text | `ink-11` | `#2B2820` / `#E4DEC9` |
| Close `x` | `ink-9` → `ink-11` on hover | — |
| Reject action | `negative` (via `Button variant="destructive-quiet"`) | `#B4232E` / `#F26B74` |
| Focus ring | `accent` (via `--ring`) | `#9C7A34` / `#D9B96C` |
| Slide distance | inline `24px` (`translateX`) | mirrored by `useInlineDirection` sign |
| Scrim + panel enter | `motion.moderate` + `easeOut` | 280ms · `[0.16,1,0.3,1]` |
| Scrim + panel exit | `motion.fast` + `easeIn` | 160ms · `[0.7,0,0.84,0]` |
| Scrim z-layer | `z-overlay` | 30 |
| Panel z-layer | `z-modal` | 40 |
| Reduced motion | `motion.instant` | 0ms |

Unlike a centered [Modal](./MODAL.md) (which scales, having no edge to belong to), a drawer **slides** from
its inline edge — the slide is *what tells the user the panel belongs to the edge it came from*, an answer
to the "where did this come from" question in [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# Motion
Principles`. The enter uses `motion.moderate` on `easeOut`; the exit is one step faster (`motion.fast`) on
`easeIn`, the standard enter/exit asymmetry.

## Stacking & z-index

The design-system z-index tiers ([`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) `# Z-Index`) place the drawer
scrim at `z-overlay` (30) and the panel at `z-modal` (40) — the same shared overlay tier as a
[Modal](./MODAL.md), below `z-command` (60) and `z-tooltip` (70):

| Layer | Token | Value |
|---|---|---|
| Sticky headers | `z-sticky` | 10 |
| Dropdowns / popovers | `z-dropdown` | 20 |
| Drawer / modal scrim | `z-overlay` | 30 |
| Drawer / modal panel | `z-modal` | 40 |
| Command palette | `z-command` | 60 |
| Tooltip | `z-tooltip` | 70 |

A confirmation, discard guard, or reject-reason `AlertDialog` raised *from within* a drawer must stack over
it — guaranteed because each Radix `Root` owns its own focus scope, and a modal raised later mounts later
in DOM order and wins within the shared tier, with focus returning *into* the drawer on close, never out to
the page. The application spec
([`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md) `# Mounting, portal, and
z-index`) documents a finer-grained internal split (drawer scrim 40 / panel 41 below modal 50/51); those
app values and these design-system tiers describe the same ordering — a `AlertDialog` raised from a drawer
sits above it, command above both, tooltip above all — and must not be read as conflicting scales.

# Accessibility

The primitive inherits Radix's full accessibility contract; QAYD's rules on top mirror
[`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) and
[`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md) `# Accessibility`.

| Concern | Contract |
|---|---|
| Role & modality | `role="dialog"` + `aria-modal="true"` (Radix). A non-modal filter drawer sets `aria-modal="false"` and does not inert the background, matching its intent. |
| Accessible name | Every drawer has a required `SheetTitle` wired via `aria-labelledby` — a titleless drawer is a lint failure. |
| Description | A drawer with an instruction/consequence uses a `SheetDescription` via `aria-describedby`; the Approval Drawer's reasoning is additionally exposed so the "why" is reachable before the approve button. |
| Focus trap | Radix `FocusScope` traps Tab within the panel while open; the page behind is `aria-hidden` + inert (for a modal drawer). No manual trap. |
| Focus return | On close, focus returns to the trigger (the row, the "Filters" button) by default; `onCloseAutoFocus` redirects to a stable anchor only when the trigger was removed by the action (a matched-away row). |
| Scroll lock | A `modal` drawer locks `<body>` scroll; the panel's own body scrolls independently between a pinned header and footer. A `modal={false}` filter drawer deliberately does *not* lock, because scrolling the list behind it is the point. |
| Keyboard | `Esc` closes a clean drawer / routes a dirty one through the discard guard; `Tab` cycles within; a disabled decision button exposes *why* via `aria-describedby` (no permission / not your step) — never a silently disabled control. |
| Colour is never the only signal | The reject action's danger is carried by its verb and the mandatory-reason step, not button color alone; approval-chain state is real text, not a color-only progress bar. |
| Reduced motion | The slide-in and scrim fade honor `prefers-reduced-motion: reduce` — the drawer appears/disappears instantly; focus, scroll lock, and trap unchanged. |

# Theming, Dark Mode & RTL

**Tokens only.** The scrim is `bg-ink-12/30` (deliberately dimmer than a modal's `/50`), the panel
`bg-[--surface-3]` with `shadow-lg`; the reject action uses `Button variant="destructive-quiet"`. No raw
hex, px, or `dark:`-raw-color variant in the source.

**Dark mode.** Applied by the shell's `next-themes` provider via the `.dark` re-declaration of the same
`--qayd-*` names (`../DESIGN_TOKENS.md → Light / Dark Remap Mechanism`). `surface-3` reads as elevated over
the darkened page (elevation gets *lighter* as it rises); the scrim opacity is re-tuned rather than
inverted. The primitive carries no dark branch of its own.

**RTL.** Set once at the root via `dir` on `<html>`. This is the primitive whose RTL story is most
load-bearing, and it is entirely structural: because `side="end"` resolves from `dir`, every offset is
logical (`inset-inline-*`, `border-s`, `ps-*/pe-*`, `SheetFooter` in source order), and the slide's sign
comes from `useInlineDirection()`, **a detail/Approval drawer slides in from the right in English and from
the left in Arabic** with zero conditional logic. `side="start"` and `"bottom"` mirror correspondingly; the
close `x` stays at the inline-end corner; the footer's Cancel/primary order flips. A `bottom` sheet is on
the **block** axis, which does not mirror, so it enters from the bottom in both scripts (per
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# What mirrors and what does not`). Two explicit pins, both
inherited from the `<Amount>` contract: any **amount** in the drawer ("approve 12,500.000 KWD") renders
`dir="ltr"`, Western Arabic numerals, never reversed; and a **Latin source-citation label or reference
number** renders `dir="auto"` so a mixed-language citation reads in its own correct direction inside the
Arabic panel.

# Do / Don't

| Do | Don't |
|---|---|
| Reach for a drawer when the user must act on a record while keeping the list/workbench behind it visible. | Use a drawer where the interaction should demand full focus with the background obscured — that is a [Modal](./MODAL.md). |
| Author `side` logically (`end`/`start`/`bottom`) and let `dir` resolve the physical edge. | Hardcode a `left`/`right` edge — it makes the Arabic product a mechanical mirror of the English one. |
| Slide the panel via `transform: translateX/Y`. | Animate `left`/`inset-inline`/`margin` for the slide — banned in review as a layout-thrashing property. |
| Keep the scrim dimmer (`/30`) than a modal's so the page stays legible. | Darken a drawer's scrim to a modal's opacity — it defeats the reason a drawer exists. |
| Cap `width` at `lg`; drop to `full` only on mobile. | Ship a wider-than-`lg` drawer — that interaction should be a route. |
| Use `modal={false}` only for a filter drawer where watching the list re-filter is the point. | Make a data-editing drawer non-modal, letting a background click compete with an in-progress edit. |
| Return focus into the drawer when a nested `AlertDialog` closes. | Let focus fall to the page behind after a nested reject/discard prompt. |

# Usage & Composition

The primitive is composed, never hand-rolled, by every side-sheet surface. The product kinds and their
data/mutation contracts are specified in
[`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md); here is the primitive-level
shape a record-detail drawer composes:

```tsx
// A record-detail drawer opened from a table row — the list stays visible behind it.
'use client';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';

export function JournalEntryDetailSheet({ summary, open, onOpenChange }: DetailSheetProps) {
  const { data: entry } = useJournalEntryQuery(summary.id, { initialData: summary, enabled: open });
  return (
    <Sheet open={open} onOpenChange={onOpenChange} width="md">
      <SheetContent>
        <SheetHeader><SheetTitle>{entry.journal_number}</SheetTitle></SheetHeader>
        <Tabs value={tab} onValueChange={setTab} size="sm">
          <TabsList>
            <TabsTrigger value="lines">Lines</TabsTrigger>
            <TabsTrigger value="history">History</TabsTrigger>
          </TabsList>
          <TabsContent value="lines">{/* line grid */}</TabsContent>
          <TabsContent value="history">{/* audit trail */}</TabsContent>
        </Tabs>
      </SheetContent>
    </Sheet>
  );
}
```

Composition rules, each a platform discipline made concrete at the overlay layer:

1. **The primitive owns overlay behavior; the composing drawer owns content and mutation.** A drawer passes
   `open`, `onOpenChange`, `side`, `width`, and children; it never re-implements the focus trap, scroll
   lock, or the direction-aware slide — those live once in `components/ui/sheet.tsx`.
2. **A drawer with an editable body is a `useMutation`, never a Server Action.** A Server Action mutation
   triggers a route transition that can remount the tree — fatal for a drawer holding in-progress edits or
   wizard-step state; the drawer must stay mounted on a validation error with its work intact. This is the
   drawer system's single most important behavioral rule
   ([`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md) `# The non-remount
   guarantee`).
3. **The Approval Drawer renders the full AI contract.** Confidence, reasoning (via
   [Accordion](./ACCORDION.md)/`ReasoningDisclosure`), and resolvable source citations always render for an
   AI-originated request and are absent entirely for a human-submitted one; approve/reject/delegate never
   auto-commit (`../../frontend/components/DRAWERS.md` `# The Approval Drawer`).
4. **Nested prompts stack, focus returns inward.** A discard guard or a mandatory reject-reason
   `AlertDialog` raised inside a drawer stacks above it by the z-tiers and returns focus into the drawer on
   close — never out to the page.

For the drawer kinds, the non-remount guarantee, the Approval Drawer's five contracts, the decision matrix,
and the full edge-case table, see
[`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md); for the sibling
centered-dialog primitive, see [`./MODAL.md`](./MODAL.md); for the direction-aware slide helper and motion
tokens, see [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); for the tokens and z-index tiers, see
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

# End of Document
