# Accordion — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / ACCORDION
---

# Purpose

This document is the atomic specification for QAYD's `Accordion` primitive — the low-level disclosure
control that expands and collapses a section of content in place. It is the design-system foundation that
higher surfaces specialize: the AI reasoning drill-in (`ReasoningDisclosure` in
[`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md) `# The Approval Drawer`), a
grouped-settings surface, an FAQ/help section, and any "show the details under this row" affordance. That
catalogue names *where* disclosure is used; this document owns *how the primitive itself is built* — its
anatomy, its single-vs-multiple open policy, the one sanctioned height-animation, its keyboard model, and
the RTL and reduced-motion contracts.

An accordion is QAYD's affordance for **progressive disclosure of secondary content the user can choose to
reveal without leaving the page** — the "why this recommendation" evidence beneath an AI proposal, an
advanced-options group in a form, a rarely-needed settings block. It is the counterpart to a
[Tabs](./TABS.md) control: tabs show exactly one of N mutually-exclusive panels at a time and are always
visible as a set; an accordion lets zero, one, or several sections be open at once and is used when the
*default* is collapsed. If every section should always show one panel, that is `Tabs`; if the content is a
distinct destination, that is a route.

The primitive is shadcn/ui over Radix `@radix-ui/react-accordion`, keeping Radix's `aria-expanded`,
`region`, and keyboard wiring untouched and adding exactly two things: QAYD's token-driven styling and the
`height: auto` expand animation — the one sanctioned layout-animating exception in the system
(`../MOTION_SYSTEM.md → Performance`). All motion resolves to [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md);
all color, radius, and type resolve to [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md).

# Anatomy

```
 ┌ AccordionItem (value="reasoning") ─────────────────────────────────────┐
 │  ⌄  Why this recommendation                                     [chevron]│  ← AccordionTrigger (button)
 │  ───────────────────────────────────────────────────────────────────── │     inside AccordionHeader (h3)
 │                                                                         │
 │   Matched to bank transaction TXN-0412 on amount + date; the vendor    │  ← AccordionContent (region)
 │   name is a 0.94 fuzzy match to "Zain Telecom".                        │     height: 0 → auto on open
 │   ▸ TXN-0412   ▸ JE-2026-07-0480                                        │
 └─────────────────────────────────────────────────────────────────────────┘
 ┌ AccordionItem (value="advanced") ──────────────────────────────────────┐
 │  ⌄  Advanced options                                            [chevron]│
 └─────────────────────────────────────────────────────────────────────────┘
```

| Part | Element | Role | Notes |
|---|---|---|---|
| `Accordion` (root) | `div` | — | Owns `type` (`single`/`multiple`), `value`/`onValueChange`, and `collapsible`; provides context. |
| `AccordionItem` | `div` | — | One collapsible section, keyed by `value`; carries the hairline divider. |
| `AccordionHeader` | `h3` (configurable) | heading | Wraps the trigger so the section is a real heading in the document outline, not a bare button. |
| `AccordionTrigger` | `button` | — | Carries `aria-expanded` and `aria-controls`; contains the label and the rotating chevron. |
| Chevron | `svg` (`ChevronDown`) | presentational (`aria-hidden`) | Rotates 180° on open (`motion.micro`); mirrored in RTL at the icon layer via `scaleX(-1)`. |
| `AccordionContent` | `div` | `region` | Wired via `aria-labelledby` to its trigger; height-animates `0 ↔ auto` on toggle. |

The `AccordionHeader` wrapping every trigger in an actual heading element is not decoration — it is what
lets a screen-reader user navigate the accordion's sections by heading, and what keeps the disclosure from
being an anonymous button in the a11y tree.

# Variants

`Accordion` has no visual `cva` variant axis — its identity is one quiet hairline-divided list. Its
meaningful variation is behavioral, governed by two root props (`type` and `collapsible`) plus a `size`
for density. There is no "filled card" or "bordered box" visual variant; where a disclosure needs a card
frame, it is composed inside a [Card](../../frontend/components/CARDS.md), not given a second accordion
skin.

| Axis | Value | Behavior |
|---|---|---|
| `type` | `single` (**default**) | At most one item open at a time; opening one closes the others. Use for mutually-exclusive detail where the eye should hold one thing (a settings surface, an FAQ). |
| `type` | `multiple` | Any number open at once; each toggles independently. Use where a user legitimately compares two open sections (two AI proposals' reasoning side by side). |
| `collapsible` | `true` (default when `single`) | The open item can be collapsed back to all-closed. QAYD keeps this `true` — an accordion that can never fully collapse traps the user in an always-open panel. |
| `size` | `sm` | Trigger height 36px, `text-sm` label; the in-drawer/in-card default (matches `ReasoningDisclosure`). |
| `size` | `md` (**default**) | Trigger height 48px, `text-md` label; the page-level default. |

# Props / API

`Accordion` is a Client Component (it owns open state and height-animates). QAYD accordions are
**controlled** wherever the open state matters to a parent (persisted "expanded groups" preference, an
"expand all" affordance, a mutation that should collapse a section) and may be **uncontrolled** with
`defaultValue` for a purely local disclosure like `ReasoningDisclosure`.

## `Accordion` (root)

| Prop | Type | Required | Description |
|---|---|---|---|
| `type` | `'single' \| 'multiple'` | yes | Whether one or many items may be open. |
| `value` | `string \| string[]` | controlled | The open item(s) — `string` for `single`, `string[]` for `multiple`. |
| `onValueChange` | `(value: string \| string[]) => void` | with `value` | Fired on every toggle. |
| `defaultValue` | `string \| string[]` | uncontrolled | Initial open item(s) when uncontrolled. |
| `collapsible` | `boolean` | no (single only) | Allow collapsing the only-open item back to none. QAYD default `true`. |
| `size` | `'sm' \| 'md'` | no, default `'md'` | Trigger height and label step. |

## `AccordionItem` / `AccordionTrigger`

| Prop | Type | Required | Description |
|---|---|---|---|
| `value` | `string` | yes | Item identity; matches the root's `value`/`defaultValue`. |
| `disabled` | `boolean` | no | A disabled item cannot open, is skipped by arrow-key roving, and reads `aria-disabled`; used when a section has no content yet, never to hide permission-gated content (that section is simply not rendered). |

```tsx
// components/ui/accordion.tsx (the QAYD primitive — Radix wiring + height animation)
'use client';

import * as AccordionPrimitive from '@radix-ui/react-accordion';
import { motion, AnimatePresence, useReducedMotion } from 'framer-motion';
import { ChevronDown } from 'lucide-react';
import { duration, easeInOut } from '@/lib/motion';
import { cn } from '@/lib/utils';

export function AccordionTrigger({ children, className, ...props }: React.ComponentProps<typeof AccordionPrimitive.Trigger>) {
  return (
    <AccordionPrimitive.Header className="flex">
      <AccordionPrimitive.Trigger
        className={cn(
          'group flex flex-1 items-center justify-between gap-3 py-3 text-start font-medium text-ink-11',
          'hover:text-ink-12 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent',
          'focus-visible:ring-offset-2 rounded-sm disabled:pointer-events-none disabled:opacity-50',
          className,
        )}
        {...props}
      >
        {children}
        {/* chevron rotates on open; mirrored in RTL at the icon layer, not here */}
        <ChevronDown
          aria-hidden
          className="h-4 w-4 shrink-0 text-ink-9 transition-transform duration-[120ms] group-data-[state=open]:rotate-180 rtl:-scale-x-100"
        />
      </AccordionPrimitive.Trigger>
    </AccordionPrimitive.Header>
  );
}

// The ONE sanctioned height:auto animation in the system. Scoped to a single element,
// kept at motion.base, and collapsed to an instant state change under reduced motion.
export function AccordionContent({ children, className, ...props }: React.ComponentProps<typeof AccordionPrimitive.Content>) {
  const reduced = useReducedMotion();
  return (
    <AccordionPrimitive.Content asChild forceMount {...props}>
      <AnimatePresence initial={false}>
        <motion.div
          key="content"
          initial={reduced ? false : { height: 0, opacity: 0 }}
          animate={reduced ? { height: 'auto', opacity: 1 } : { height: 'auto', opacity: 1 }}
          exit={reduced ? { height: 0, opacity: 0 } : { height: 0, opacity: 0 }}
          transition={{ duration: reduced ? 0 : duration.base, ease: easeInOut }}
          className={cn('overflow-hidden', className)}
        >
          <div className="pb-4 text-ink-11">{children}</div>
        </motion.div>
      </AnimatePresence>
    </AccordionPrimitive.Content>
  );
}
```

The `height: auto` reveal is the single layout-animating exception the motion system permits
(`../MOTION_SYSTEM.md → Performance → Animate only transform and opacity`): it is scoped to one element,
kept at `motion.base` (200ms), on the `easeInOut` curve (a symmetric open↔close toggle), and never run on
more than one item on the same frame. Passing `initial={false}` under reduced motion mounts the content
already at its final height, so there is no first-frame flash before the zero-duration transition
resolves.

# States

Every state ships in full light/dark and LTR/RTL parity.

| State | Trigger appearance | Content behavior |
|---|---|---|
| **Collapsed** | Label `ink-11`; chevron points down; `aria-expanded="false"` | Content is present in the DOM (`forceMount`) but at `height: 0`, `overflow-hidden`, and inert to Tab — a collapsed section holds no focusable stops. |
| **Expanding** | Chevron rotates 180° over `motion.micro` | Content height-animates `0 → auto` over `motion.base`, `easeInOut`; the sections below reflow smoothly on the same timeline. |
| **Expanded** | Label `ink-11`; chevron up; `aria-expanded="true"` | Content at full height; its focusable children are now in the Tab sequence. |
| **Hover** | Label lifts to `ink-12` | `text-color` micro transition; single element, so a non-transform hover is permitted. |
| **Focus-visible** | 2px `accent` ring, 2px offset | Keyboard focus only. |
| **Disabled** | Label `opacity-50`, `aria-disabled` | Not togglable; skipped by arrow-key roving; carries a Tooltip reason where non-obvious. |

A key correctness rule lives in the collapsed state: a collapsed accordion's content, though present via
`forceMount` for the animation, must be **inert to keyboard focus** — Radix removes it from the tab
sequence while collapsed, so `Tab` never lands on an invisible link inside a closed section. This is the
accordion's contribution to the "focus never goes somewhere the user cannot see" rule in
[`../ACCESSIBILITY.md`](../ACCESSIBILITY.md).

# Tokens Used

Every value resolves to a token in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) or a motion token in
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md). No raw hex, px, or `cubic-bezier` appears in the source.

| Concern | Token | Value (light / dark) |
|---|---|---|
| Trigger label | `ink-11` | `#2B2820` / `#E4DEC9` |
| Hover label | `ink-12` | `#15130E` / `#F8F6EF` |
| Chevron | `ink-9` | `#645F53` / `#A39878` |
| Content body text | `ink-11` | `#2B2820` / `#E4DEC9` |
| Item divider | `ink-6` | `#C2BEB6` / `#46402F` |
| Focus ring | `accent` (via `--ring`) | `#9C7A34` / `#D9B96C` |
| Container corner (when carded) | `radius-lg` | 8px |
| Trigger corner (focus ring) | `radius-sm` | 4px |
| Expand / collapse height | `motion.base` + `easeInOut` | 200ms · `[0.65,0,0.35,1]` |
| Chevron rotation | `motion.micro` | 120ms |
| Reduced motion | `motion.instant` | 0ms — content jumps to/from `auto`, chevron flips instantly |

The expand/collapse curve is `easeInOut` — the correct curve for a toggle with two equally-weighted ends
(open and closed), per [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# The Easing Curves`. Unlike a modal
or drawer, an accordion is *not* an enter/exit pair with an asymmetric one-step-faster-out timing; it is a
symmetric size change, animated at the same `motion.base` in both directions.

# Accessibility

`Accordion` inherits Radix `@radix-ui/react-accordion`'s full contract; QAYD's rules on top mirror
[`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) `# Keyboard` and `# Motion`.

| Concern | Contract |
|---|---|
| Heading structure | Each trigger is wrapped in an `AccordionHeader` (`h3` by default, level configurable to fit the page outline) so sections are navigable by heading, never a flat list of anonymous buttons. |
| Expanded state | Each trigger carries `aria-expanded` and `aria-controls={contentId}`; each content region `aria-labelledby={triggerId}` and `role="region"` — all Radix-generated and linked. |
| Keyboard | `Enter`/`Space` toggle the focused item. `ArrowDown`/`ArrowUp` move focus between triggers (respecting `dir`); `Home`/`End` jump to first/last enabled trigger. `Tab` moves *through* an open item's content, and skips a collapsed item's inert content entirely. |
| Roving | Disabled items are skipped by arrow-key navigation. |
| Focus visibility | `:focus-visible` ring only — a mouse toggle leaves no ring; a keyboard user always sees the focused trigger. |
| Colour is never the only signal | Open/closed is carried by the chevron direction **and** `aria-expanded` **and** the revealed content — never by color alone. |
| Reduced motion | Under `prefers-reduced-motion: reduce`, the section opens/closes instantly (`height` jumps to/from `auto`, chevron flips with no tween) — the disclosure still works, per the motion system's contract that reduced motion removes the animation, never the state change. |
| No content trapped by animation | Because content is `forceMount`ed for the height animation, the collapsed state must keep it `overflow-hidden` and inert — a screen reader in browse mode still reaches it, but Tab/focus does not land in a visually-collapsed region. |

# Theming, Dark Mode & RTL

**Tokens only.** Labels, chevron, dividers, and content all reference `ink-*`/`accent` utilities
(`../DESIGN_TOKENS.md`); no `dark:`-raw-color branch, no hardcoded hex in `accordion.tsx`.

**Dark mode.** Applied by the shell's `next-themes` provider via the `.dark` re-declaration of the same
`--qayd-*` names (`../DESIGN_TOKENS.md → Light / Dark Remap Mechanism`). Labels flip from `ink-11`
near-black to near-white and the divider from a light warm-graphite to a dark one; the primitive carries
no dark branch of its own.

**RTL.** Set once at the root via `dir` on `<html>`. The trigger uses `text-start` and
`justify-between` so the label sits at the inline-start and the chevron at the inline-end in both scripts;
the chevron glyph is mirrored at the icon layer (`rtl:-scale-x-100`) per the iconography rule in
[`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md) `# RTL-Aware Directional Motion` (a directional glyph mirrors
via `scaleX(-1)`). The height animation is on the **block** axis, which does *not* mirror between LTR and
RTL — the section grows downward in both scripts — so the reveal itself needs no direction handling; only
the chevron, an inline-direction glyph, flips. Any amount or Latin reference inside the content (a cited
`JE-2026-07-0480`) stays `dir="ltr"`/`dir="auto"` per the `<Amount>` contract.

# Do / Don't

| Do | Don't |
|---|---|
| Use an accordion for secondary content whose *default* is collapsed (AI reasoning, advanced options). | Use an accordion where one panel should always be visible — that is [Tabs](./TABS.md). |
| Keep `collapsible: true` so `single` accordions can return to all-closed. | Force an always-one-open accordion that traps the user in a panel they cannot dismiss. |
| Animate `height: 0 ↔ auto` on one item at a time at `motion.base`, `easeInOut`. | Animate several items' heights on the same frame, or reach past `motion.base` — layout animation is the one costly exception, kept scarce. |
| Wrap every trigger in a real heading (`AccordionHeader`) at the right outline level. | Ship a bare `<button>` disclosure with no heading — it disappears from screen-reader heading navigation. |
| Keep collapsed content inert to Tab even though it is mounted for the animation. | Leave focusable links reachable by Tab inside a visually-collapsed section. |
| Render permission-gated sections by not including the item. | Render a sensitive section as a `disabled` item whose existence leaks. |
| Mirror only the chevron in RTL; let the reveal grow on the block axis unchanged. | Try to "flip" the vertical reveal for RTL — the block axis does not mirror. |

# Usage & Composition

The primitive is composed by every disclosure surface. Its most important composition is the AI
reasoning drill-in, where the accordion is the mechanism behind the platform's "no card shows a bare
number the AI computed without showing its work" rule
([`../../frontend/components/DRAWERS.md`](../../frontend/components/DRAWERS.md) `# Reasoning and source
citations`):

```tsx
// A reasoning disclosure — uncontrolled, single, collapsible; collapsed by default so a
// confident reviewer clearing a queue is not slowed, but the evidence is one click away.
'use client';
import { Accordion, AccordionItem, AccordionTrigger, AccordionContent } from '@/components/ui/accordion';

export function ReasoningDisclosure({ reasoning, sources }: { reasoning: string; sources: Source[] }) {
  return (
    <Accordion type="single" collapsible size="sm">
      <AccordionItem value="reasoning">
        <AccordionTrigger>Why this recommendation</AccordionTrigger>
        <AccordionContent>
          <p className="text-sm">{reasoning}</p>
          <ul className="mt-2 space-y-1" role="list">
            {sources.map((s) => (
              <li key={s.id}>
                {/* each citation resolves to a real, permission-checked route */}
                <a href={sourceHref(s)} className="text-accent hover:underline" dir="auto">{s.label}</a>
              </li>
            ))}
          </ul>
        </AccordionContent>
      </AccordionItem>
    </Accordion>
  );
}
```

```tsx
// A grouped-settings surface — multiple, controlled, with an "expand all" affordance in the parent
<Accordion type="multiple" value={openGroups} onValueChange={setOpenGroups}>
  <AccordionItem value="notifications"><AccordionTrigger>Notifications</AccordionTrigger>
    <AccordionContent>{/* … */}</AccordionContent></AccordionItem>
  <AccordionItem value="security"><AccordionTrigger>Security</AccordionTrigger>
    <AccordionContent>{/* … */}</AccordionContent></AccordionItem>
</Accordion>
```

Composition rules, each a platform discipline made concrete at the disclosure layer:

1. **Collapsed is the default; disclosure is opt-in.** An accordion exists precisely to keep secondary
   content out of the way until asked for — if content should be visible on load, it is not in an
   accordion.
2. **The primitive owns motion and a11y; the composing surface owns content.** A screen passes items,
   triggers, and content; it never re-implements the height animation, the keyboard model, or the
   reduced-motion branch — those live once in `components/ui/accordion.tsx`.
3. **AI evidence lives here, always resolvable.** When an accordion carries AI reasoning, its `sources[]`
   are navigable citations to real records, never dead labels — the accordion is the reveal, the routes
   are the proof (`../../frontend/components/DRAWERS.md`).
4. **Inside an overlay, the accordion inherits the overlay's stacking.** An accordion in a
   [Drawer](./DRAWER.md) or [Modal](./MODAL.md) carries no z-index of its own and never portals out
   (`../DESIGN_TOKENS.md → Z-Index`).

For the primitive's registration in the library and its finance-usage catalogue, see
[`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md); for the motion tokens the
height reveal and chevron consume, see [`../MOTION_SYSTEM.md`](../MOTION_SYSTEM.md); for the keyboard,
heading-structure, and colour-independence rules, see [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md).

# End of Document
