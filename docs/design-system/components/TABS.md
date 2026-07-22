# Tabs — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / TABS
---

# Purpose

This document is the atomic specification for QAYD's `Tabs` primitive: the low-level, content-agnostic
tab control that every screen-level tabbed surface composes. It is the design-system restatement of the
`Tabs` row in [`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md) (`# Primitives`)
— that catalogue entry names the primitive and its finance uses (journal-entry detail's
`Lines / History / Attachments`, a settings surface's section switch); this document owns *how the
primitive itself is built*: its anatomy, its underline-indicator animation, its overflow behavior, its
full keyboard model, and the RTL and reduced-motion contracts a screen author must never re-derive.

`Tabs` is a **presentation control for switching between sibling panels of already-loaded content within
one context** — it is not navigation. Switching a tab never changes the URL, never triggers a route
transition, and never fetches a new primary resource; a surface where each "tab" is a distinct
bookmarkable destination is a **route with a sub-nav**, not a `Tabs` (see the decision note in
[`../../frontend/components/MODALS.md`](../../frontend/components/MODALS.md) `# Behavior` for the sibling
route-vs-overlay rule this mirrors). Tabs partition content the user already has in hand; routes partition
the application.

The primitive is shadcn/ui over Radix `@radix-ui/react-tabs`, keeping Radix's roving-tabindex and
`aria-*` wiring untouched and adding exactly two things: QAYD's token-driven styling and the
`layoutId`-animated underline indicator. All motion resolves to
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); all color, radius, and type resolve to
[`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md). Nothing here invents a value either of those does not already
define.

# Anatomy

```
 orientation="horizontal" (default)

 ┌ TabsList (role=tablist) ──────────────────────────────────────────────┐
 │  Lines        History        Attachments        Audit                  │
 │  ▔▔▔▔▔                                                                  │  ← indicator (accent, 2px)
 └────────────────────────────────────────────────────────────────────────┘
      │
      ▼  TabsContent (role=tabpanel, only the active one mounted-visible)
 ┌────────────────────────────────────────────────────────────────────────┐
 │  … the active panel's content …                                        │
 └────────────────────────────────────────────────────────────────────────┘
```

| Part | Element | Role | Notes |
|---|---|---|---|
| `Tabs` (root) | `div` | — | Owns `value`/`onValueChange` and `orientation`; provides context to descendants. |
| `TabsList` | `div` | `tablist` | The horizontal (or vertical) rail of triggers; hosts the shared animated indicator via a Framer `LayoutGroup`. |
| `TabsTrigger` | `button` | `tab` | One per panel; carries `aria-selected`, `aria-controls`, and roving tabindex from Radix. The active trigger owns the indicator. |
| Indicator | `motion.span` | presentational (`aria-hidden`) | A single 2px `accent` bar (horizontal) or inline-start rule (vertical) that slides between triggers on one shared `layoutId`. Never a per-trigger border that hard-cuts. |
| `TabsContent` | `div` | `tabpanel` | Wired to its trigger via `aria-labelledby`; the active panel fades up on swap. |

The indicator is the only QAYD structural addition to the Radix anatomy. It is **one** element shared
across triggers (via `layoutId="tab-indicator"` inside the list's `LayoutGroup`), not a border toggled on
each trigger — that is what makes the underline *slide* from the old tab to the new one rather than
disappear-and-reappear, and it is the single reason the list is wrapped in a Framer layout group.

# Variants

`Tabs` exposes two variant axes via `cva` (the convention in
[`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md) `# Variants via cva`):
`orientation` and `size`. There is deliberately no "pill" or "segmented" visual variant — QAYD's tab
identity is the single quiet underline, and a second visual treatment would fragment it.

| Axis | Value | Behavior |
|---|---|---|
| `orientation` | `horizontal` (**default**) | Triggers in a row; indicator is a 2px bar on the block-end edge of the list; overflow scrolls on the inline axis. |
| `orientation` | `vertical` | Triggers stacked; indicator is a 2px rule on the **inline-start** edge of the active trigger; used for tall settings surfaces where a left rail reads better than a top row. |
| `size` | `sm` | Trigger height 32px, `text-xs` label; for in-drawer or in-card tab rows where vertical space is tight. |
| `size` | `md` (**default**) | Trigger height 40px, `text-sm` label; the page-level default. |

Overflow is a behavior of the `horizontal` variant, not a separate variant: when the triggers exceed the
list's inline width, the list becomes an inline-scroll container with masked edges (see `# States →
Overflow`). QAYD never wraps tabs onto a second line — a wrapped tab row loses the single-baseline the
indicator rides on and reads as chaotic on a dense screen.

# Props / API

`Tabs` is a Client Component (it owns selection state and animates). It is always **controlled** in QAYD
screens so the selected tab can be lifted into a parent (persisted, deep-linked into a query param where a
screen genuinely wants that, or reset by a mutation) rather than trapped inside the primitive.

## `Tabs` (root)

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `string` | yes (controlled) | The active tab's value. QAYD tabs are controlled; the parent owns selection. |
| `onValueChange` | `(value: string) => void` | yes | Fired on click and on keyboard activation. |
| `orientation` | `'horizontal' \| 'vertical'` | no, default `'horizontal'` | Sets the tablist axis and the indicator edge; also switches the arrow-key axis (see `# Accessibility`). |
| `size` | `'sm' \| 'md'` | no, default `'md'` | Trigger height and label step. |
| `activationMode` | `'automatic' \| 'manual'` | no, default `'automatic'` | Radix pass-through. `automatic` selects on focus (arrow-key lands = panel shows); `manual` requires Enter/Space to activate — used when a panel swap is expensive enough that selecting-on-arrow would thrash. |

## `TabsTrigger`

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `string` | yes | Matches the sibling `TabsContent`. |
| `disabled` | `boolean` | no | A disabled tab is skipped by arrow-key roving and reads `aria-disabled`; used when a panel is not yet applicable (e.g. an `Attachments` tab on a draft with none), never to hide a permission-gated panel (that panel is simply not rendered). |
| `count` | `number` | no | Optional trailing count chip (`ink-9` on `ink-3`) — e.g. `History 12`. Numeric, rendered via the `<Amount>` tabular contract, never a hand-formatted string. |

## `TabsContent`

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `string` | yes | Matches its trigger. |
| `forceMount` | `boolean` | no | Radix pass-through; keep an inactive panel mounted (e.g. to preserve an in-progress form or a scroll position). Off by default — an inactive panel is unmounted, so it costs nothing. |

```tsx
// components/ui/tabs.tsx (the QAYD primitive — Radix wiring + animated indicator)
'use client';

import * as TabsPrimitive from '@radix-ui/react-tabs';
import { motion, LayoutGroup } from 'framer-motion';
import { cva, type VariantProps } from 'class-variance-authority';
import { duration, easeInOut, easeOut } from '@/lib/motion';
import { useTransition } from '@/lib/motion';         // reduced-motion-aware helper
import { cn } from '@/lib/utils';

const listVariants = cva('relative flex', {
  variants: {
    orientation: { horizontal: 'flex-row gap-4 border-b border-ink-6', vertical: 'flex-col gap-1 border-e border-ink-6' },
  },
  defaultVariants: { orientation: 'horizontal' },
});

const triggerVariants = cva(
  'relative inline-flex items-center gap-2 whitespace-nowrap font-medium text-ink-9 ' +
    'data-[state=active]:text-ink-12 hover:text-ink-11 ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 ' +
    'disabled:pointer-events-none disabled:opacity-50 rounded-sm',
  {
    variants: { size: { sm: 'h-8 px-1 text-xs', md: 'h-10 px-1 text-sm' } },
    defaultVariants: { size: 'md' },
  },
);

export function TabsTrigger({
  value, size, orientation = 'horizontal', className, children, ...props
}: React.ComponentProps<typeof TabsPrimitive.Trigger> &
   VariantProps<typeof triggerVariants> & { orientation?: 'horizontal' | 'vertical' }) {
  const transition = useTransition({ duration: duration.base, ease: easeInOut });
  return (
    <TabsPrimitive.Trigger value={value} className={cn(triggerVariants({ size }), className)} {...props}>
      {children}
      {/* one shared indicator per list — Framer moves it between active triggers */}
      <TabsPrimitive.Trigger asChild value={value}>
        <span aria-hidden className="pointer-events-none absolute inset-0">
          <IndicatorSlot orientation={orientation} transition={transition} />
        </span>
      </TabsPrimitive.Trigger>
    </TabsPrimitive.Trigger>
  );
}

// The indicator renders ONLY under the active trigger; layoutId makes Framer tween its
// position from the previously-active trigger to this one on one timeline.
function IndicatorSlot({ orientation, transition }: { orientation: 'horizontal' | 'vertical'; transition: object }) {
  return (
    <motion.span
      layoutId="tab-indicator"
      transition={transition}
      className={cn(
        'absolute bg-accent',
        orientation === 'horizontal'
          ? 'inset-x-0 -bottom-px h-0.5'          // 2px bar on the block-end edge
          : '-inset-inline-start-px inset-y-0 w-0.5', // 2px rule on the inline-start edge
      )}
    />
  );
}
```

The `IndicatorSlot` is rendered conditionally on the active state in the real implementation (elided
above for the shape); Framer's `layoutId` is what makes the single element animate from one trigger's
bounds to the next. The panel itself uses the shared `fadeUp` variant on swap:

```tsx
export function TabsContent({ value, children, ...props }: React.ComponentProps<typeof TabsPrimitive.Content>) {
  const transition = useTransition({ duration: duration.base, ease: easeOut });
  return (
    <TabsPrimitive.Content value={value} {...props} asChild>
      <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={transition}>
        {children}
      </motion.div>
    </TabsPrimitive.Content>
  );
}
```

# States

Every state ships in full light/dark and LTR/RTL parity.

| State | Trigger appearance | Behavior |
|---|---|---|
| **Inactive** | Label `ink-9`; no indicator | Focusable via roving tabindex only when it is the roving stop; click/Enter/Space selects. |
| **Hover (inactive)** | Label lifts to `ink-11` | `text-color` transition on the micro token (120ms); the only non-transform hover, permitted because it is a single element, not a table row. |
| **Active** | Label `ink-12`; indicator sits under/beside it | Indicator arrived via the shared `layoutId` slide (`motion.base`, `easeInOut`). |
| **Focus-visible** | 2px `accent` ring, 2px offset | Keyboard focus only (`:focus-visible`), never a persistent ring on click. |
| **Disabled** | Label `opacity-50`, `aria-disabled` | Skipped by arrow-key roving; not selectable; carries a Tooltip reason where the disablement is non-obvious. |
| **Selected + transitioning** | Old label fades toward `ink-9`, new toward `ink-12` | Indicator slides between them on one timeline; the outgoing panel fades out (`fast`) as the incoming fades up (`base`) — coordinated, not chained (`../MOTION_SYSTEM.md → Choreography`). |

## Overflow (horizontal only)

When triggers exceed the list's inline width, the `TabsList` becomes an inline-scroll container:

- The list is `overflow-x-auto` with a hidden scrollbar and an `accent`-neutral gradient mask on whichever
  inline edge has more content off-screen, signaling "there is more this way."
- Selecting a partially- or fully-off-screen tab (by click or arrow key) **scrolls it into view** via
  `scrollIntoView({ inline: 'nearest' })`, so keyboard roving never lands focus on an invisible trigger.
- The scroll axis is the **inline** axis, so in RTL the overflow scrolls leftward-to-reveal correctly with
  no code change — the container reads `dir` from the ambient direction.
- Tabs never wrap to a second row (`# Variants`); a screen with more sections than fit is a signal to
  reduce tabs or move to a route sub-nav, not to stack them.

# Tokens Used

Every value resolves to a token in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) or a motion token in
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md). No raw hex, px, or `cubic-bezier` appears in the source.

| Concern | Token | Value (light / dark) |
|---|---|---|
| Active label | `ink-12` | `#15130E` / `#F8F6EF` |
| Inactive label | `ink-9` | `#645F53` / `#A39878` |
| Hover label | `ink-11` | `#2B2820` / `#E4DEC9` |
| Indicator fill | `accent` | `#9C7A34` / `#D9B96C` |
| List divider (`border-b`/`border-e`) | `ink-6` | `#C2BEB6` / `#46402F` |
| Focus ring | `accent` (via `--ring`) | `#9C7A34` / `#D9B96C` |
| Count chip | `ink-9` on `ink-3` | — |
| Trigger corner (focus ring only) | `radius-sm` | 4px |
| Indicator slide | `motion.base` + `easeInOut` | 200ms · `[0.65,0,0.35,1]` |
| Panel swap (in) | `motion.base` + `easeOut` | 200ms · `[0.16,1,0.3,1]` |
| Panel swap (out) | `motion.fast` + `easeIn` | 160ms · `[0.7,0,0.84,0]` |
| Hover color | `motion.micro` | 120ms |
| Reduced motion | `motion.instant` | 0ms — indicator jumps, panel appears instantly |
| Stacking (in an overlay) | inherits `z-modal` / `z-overlay` context | tabs carry no z of their own |

The indicator slide is `easeInOut` — correct for a position toggle with two equally-weighted ends
(`../MOTION_SYSTEM.md → The Easing Curves`), where the same element moves back and forth between tabs. The
panel swap is an entrance (`easeOut`) paired with an exit (`easeIn`), the standard enter/exit asymmetry.

# Accessibility

`Tabs` inherits Radix `@radix-ui/react-tabs`'s full contract; QAYD's rules are what must never be broken
on top. This mirrors [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) `# Keyboard` and the component table in
[`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md) `# Accessibility per component`.

| Concern | Contract |
|---|---|
| Roles | `TabsList` is `role="tablist"`, each `TabsTrigger` `role="tab"`, each `TabsContent` `role="tabpanel"` — all from Radix, never hand-set. |
| Selection wiring | Each trigger carries `aria-selected` and `aria-controls={panelId}`; each panel `aria-labelledby={triggerId}` — Radix generates and links the ids. |
| Roving tabindex | Only the active (or focused) trigger is in the Tab sequence; `Tab` moves *out* of the tablist to the panel, not between triggers. Arrow keys move between triggers. |
| Arrow keys | `horizontal`: `ArrowLeft`/`ArrowRight` move between triggers, **respecting `dir`** (in RTL, `ArrowRight` moves to the previous tab — Radix handles this from the resolved direction). `vertical`: `ArrowUp`/`ArrowDown`. `Home`/`End` jump to first/last enabled trigger. |
| Activation | `automatic` mode selects on focus; `manual` requires `Enter`/`Space`. A disabled trigger is skipped by roving entirely. |
| Focus visibility | `:focus-visible` ring only — a mouse click selects a tab without leaving a ring; a keyboard user always sees where focus is. |
| Colour is never the only signal | The active tab is distinguished by label weight/color **and** the indicator **and** `aria-selected` — never by the `accent` underline alone, so a colorblind or grayscale reader still reads the active state from the bolder `ink-12` label and the screen reader from `aria-selected`. |
| Count is text | A `count` chip is real text inside the trigger's accessible name (`History, 12`), not a color-coded dot. |
| Reduced motion | Under `prefers-reduced-motion: reduce`, the indicator jumps to the new tab and the panel appears with no fade — the selection still changes, per `../MOTION_SYSTEM.md`'s reduced-motion contract. |

# Theming, Dark Mode & RTL

**Tokens only.** Labels, indicator, and divider all reference `ink-*`/`accent` utilities
(`../DESIGN_TOKENS.md`); there is no `dark:`-raw-color branch and no hardcoded hex anywhere in
`tabs.tsx`.

**Dark mode.** Applied by the shell's `next-themes` provider via the `.dark` class re-declaring the same
`--qayd-*` names (`../DESIGN_TOKENS.md → Light / Dark Remap Mechanism`). The active label flips from
near-black `ink-12` to near-white, and the indicator's `accent` gets *lighter* (`#9C7A34` → `#D9B96C`),
holding contrast against the dark canvas without glowing. The primitive carries no dark branch of its own.

**RTL.** Set once at the root via `dir` on `<html>`. Because the tablist uses `flex` in source order and
the indicator is anchored with logical properties (`inset-inline-start`, `border-e`), the whole control
mirrors with zero conditional logic: in Arabic the horizontal tabs read right-to-left, the vertical
indicator sits on the right (inline-start) edge, `ArrowRight`/`ArrowLeft` semantics flip via Radix's
direction awareness, and overflow scrolls in the correct inline direction. This is the tab-level
application of the RTL-aware directional-motion rule in
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# RTL-Aware Directional Motion`: the indicator's slide is
along the inline axis, so it mirrors; a `count` numeral inside a trigger stays `dir="ltr"` per the
`<Amount>` contract and never reverses.

# Do / Don't

| Do | Don't |
|---|---|
| Use `Tabs` to switch between panels of content the user already has in one context (a record's detail sections). | Use `Tabs` for navigation between bookmarkable destinations — that is a route sub-nav, not a tab control. |
| Keep the single 2px `accent` underline as the only active-state visual treatment beyond the label weight. | Add a second visual variant (pill, filled, boxed) — QAYD has one tab identity by design. |
| Let a horizontal overflow scroll on the inline axis with an edge mask and `scrollIntoView` on selection. | Wrap tabs onto a second row — it breaks the indicator baseline and reads as chaotic. |
| Animate the indicator with one shared `layoutId` so it slides between triggers. | Toggle a per-trigger border on/off, which makes the underline blink instead of slide. |
| Render a permission-gated panel by simply not including its trigger. | Render a permission-gated tab as `disabled` — the sensitive panel's very existence should not leak. |
| Use `count` as tabular text inside the trigger's accessible name. | Signal "new items" with a bare colored dot that a colorblind or grayscale user cannot read. |
| Reach for `manual` activation when a panel swap is expensive. | Leave `automatic` activation on a tab whose panel triggers a heavy fetch on every arrow-key pass. |

# Usage & Composition

The primitive is composed, never hand-rolled, by every tabbed screen surface. Two representative
compositions:

```tsx
// A record-detail drawer's section switch — see ../../frontend/components/DRAWERS.md (DetailSheet)
'use client';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { useState } from 'react';

export function JournalEntryTabs({ entryId }: { entryId: number }) {
  const [tab, setTab] = useState('lines');
  return (
    <Tabs value={tab} onValueChange={setTab} size="sm">
      <TabsList>
        <TabsTrigger value="lines">Lines</TabsTrigger>
        <TabsTrigger value="history" count={12}>History</TabsTrigger>
        <TabsTrigger value="attachments">Attachments</TabsTrigger>
      </TabsList>
      <TabsContent value="lines">{/* line grid */}</TabsContent>
      <TabsContent value="history">{/* audit trail */}</TabsContent>
      <TabsContent value="attachments">{/* documents */}</TabsContent>
    </Tabs>
  );
}
```

```tsx
// A tall settings surface — vertical orientation, left rail on desktop
<Tabs value={section} onValueChange={setSection} orientation="vertical">
  <TabsList>
    <TabsTrigger value="company">Company</TabsTrigger>
    <TabsTrigger value="users">Users &amp; roles</TabsTrigger>
    <TabsTrigger value="branding">Branding</TabsTrigger>
    <TabsTrigger value="integrations">Integrations</TabsTrigger>
  </TabsList>
  {/* ...panels... */}
</Tabs>
```

Composition rules, each a platform discipline made concrete at the tab layer:

1. **Tabs partition content, not the app.** If a section deserves a URL, it is a route
   (`../../frontend/components/MODALS.md → decision matrix`), not a tab. A tab's `value` may be mirrored
   into a query param where a screen wants a shareable "which tab" state, but the tab switch itself is
   still a client interaction, not a navigation.
2. **The primitive owns motion and a11y; the screen owns content.** A composing screen passes `value`,
   `onValueChange`, triggers, and panels; it never re-implements the indicator, the keyboard model, or the
   reduced-motion branch — those live once in `components/ui/tabs.tsx`.
3. **Inside an overlay, tabs inherit the overlay's stacking.** Tabs rendered in a
   [Drawer](./DRAWER.md) or [Modal](./MODAL.md) carry no z-index of their own; they live inside the
   overlay's `z-modal` context and never portal out (`../DESIGN_TOKENS.md → Z-Index`).
4. **Counts and amounts flow through the tabular contract.** A `count` or any numeric label uses the
   `<Amount>` primitive's tabular figures and `dir="ltr"` protection, never a hand-formatted string.

For the primitive's registration in the library and its finance-usage catalogue, see
[`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md); for the motion tokens the
indicator and panel swap consume, see [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); for the keyboard and
colour-independence rules, see [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md).

# End of Document
