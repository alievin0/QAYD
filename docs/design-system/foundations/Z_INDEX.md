# Z-Index Scale — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Foundations / Z_INDEX
---

# Purpose

This document is the atomic reference for **stacking order** in QAYD — the AI-native, bilingual
(English LTR / Arabic RTL) accounting platform. It fixes the canonical eight-step z-index tier scale every
floating layer stacks on, the exact integer value of each tier, the rules that keep those integers meaningful
(stacking contexts, portalling out of `#portal-root`, one scrim per depth), and the single non-negotiable
law: **no component ever invents its own z-index.**

It specializes [`../ELEVATION_SHADOWS.md → Z-Index Tiers`](../ELEVATION_SHADOWS.md), which introduces the
same scale alongside the surface and shadow system, and it restates — unchanged — the eight tokens published
in [`../DESIGN_TOKENS.md → Z-Index`](../DESIGN_TOKENS.md), which remains the literal source of the values.
Elevation depth (surface levels, shadow steps) is in [`../ELEVATION_SHADOWS.md`](../ELEVATION_SHADOWS.md);
the two glass surfaces that sit at the top of this stack are in
[`../GLASSMORPHISM_GUIDELINES.md`](../GLASSMORPHISM_GUIDELINES.md). Where a rule here and a frontend doc
appear to disagree, this document states the intent and the frontend implementation is binding; the conflict
should be raised, not silently resolved.

Z-index is where a UI's layering quietly rots: one `z-50` bumped to `z-[9999]` to win a fight, then a second,
and soon nothing is predictable and an open menu is trapped behind a sticky header. QAYD avoids the entire
class of bug by defining the order **once**, in tokens with wide gaps, and portalling every escape-the-flow
overlay to a single root — so relative order is a property of the token, never of a hand-tuned integer.

# The Scale — Reference

Eight tiers, defined once, referenced everywhere. The values are spaced by 10 so a rare intra-tier need
(e.g. a scrim just under its content) has room without a new token, yet the ordering stays obvious at a
glance.

```css
/* app/globals.css — canonical, mirrors DESIGN_TOKENS.md */
@theme {
  --z-base:     0;   /* default document flow */
  --z-sticky:  10;   /* sticky table headers/footers, frozen columns, sticky sub-nav */
  --z-dropdown:20;   /* Radix DropdownMenu, Select, Popover, Combobox (+ non-portalled shell chrome) */
  --z-overlay: 30;   /* modal / drawer scrim (backdrop) */
  --z-modal:   40;   /* modal / drawer (Dialog, Sheet) content */
  --z-toast:   50;   /* Sonner toast stack */
  --z-command: 60;   /* ⌘K command palette, AI Command Center overlay */
  --z-tooltip: 70;   /* tooltips — never occluded by anything, including modals */
}
```

| Token | Value | Layer | Notes |
|---|---|---|---|
| `z-base` | `0` | Default document flow | Every in-flow element; no positioned `z-index` needed |
| `z-sticky` | `10` | Sticky table header/footer, frozen identifier column, sticky filter / sub-nav | Lives *inside* the content scroll; below every portalled overlay |
| `z-dropdown` | `20` | Dropdown menu, Select, Popover, Combobox | First portalled tier. Non-portalled shell chrome (sidebar, topbar, sticky form footer) also sits here as `sticky`/`fixed` and never overlaps an open menu — menus portal above their trigger |
| `z-overlay` | `30` | Modal / drawer **scrim** | The `ink-12`/50% wash beneath any Dialog or Sheet |
| `z-modal` | `40` | Modal / **drawer** content | Dialog (create/edit forms, confirmations) and Sheet (AI-rail overlay mode, mobile nav, detail drill-down) — the drawer/sheet content tier |
| `z-toast` | `50` | Sonner toast stack | Above modals so a confirmation toast is never hidden behind a dialog |
| `z-command` | `60` | ⌘K command palette, AI Command Center overlay | Above any open modal — reachable from every app state |
| `z-tooltip` | `70` | Tooltips | Always on top; a tooltip on a control inside a modal must never be clipped |

The task's "drawer" tier is the **`z-modal`** row: a Sheet (drawer) and a Dialog (modal) share `z-modal` for
their content and `z-overlay` for their scrim. Toast sits above both, command above toast, tooltip above all.

# Tokens & Naming

Application code references a **named token**, never a literal integer.

```
Named tier          →   Tailwind utility / inline style   →   rendered stacking
--z-modal (40)          z-modal  /  z-[var(--z-modal)]        Dialog content above its scrim
```

| Layer | Pattern | Example | Allowed value |
|---|---|---|---|
| Token | `--z-{tier}` | `--z-dropdown`, `--z-command` | One of the eight integers above |
| Tailwind utility | mapped in `theme.extend.zIndex` | `z-sticky`, `z-modal`, `z-tooltip` | `var(--z-…)` reference only |
| Radix / portal component | reads the tier via the mapped utility | `<DialogContent className="z-modal">` | A tier utility, never `z-[41]` |

```ts
// tailwind.config.ts (excerpt) — every entry is a var() reference; no raw integer
export default {
  theme: { extend: { zIndex: {
    base: "var(--z-base)", sticky: "var(--z-sticky)", dropdown: "var(--z-dropdown)",
    overlay: "var(--z-overlay)", modal: "var(--z-modal)", toast: "var(--z-toast)",
    command: "var(--z-command)", tooltip: "var(--z-tooltip)",
  } } },
} satisfies Config;
```

Two things are deliberately true. The tokens are **semantic, not numeric** (`z-modal`, not `z-40`), so a
reader knows *what* a layer is, not just where it lands; and the eight names are the whole vocabulary — there
is no `z-modal-2` or `z-super`, because a ninth layer is a design decision against this document, not a
number a component picks.

# Usage

## Stacking contexts — the rule that makes the integers mean anything

A z-index only compares elements **within the same stacking context**. A child in a low-z stacking context
can never rise above a sibling context with a higher z, no matter how large its own z-index — this is why
`z-[9999]` "doesn't work" and why bumping integers is a trap. QAYD's defense is structural:

- **Overlays escape their context by portalling, not by out-numbering.** Anything that must appear above the
  page — every Radix `DropdownMenu`, `Select`, `Popover`, `Dialog`, `Sheet`, `Tooltip`, and the command
  surfaces — renders through a single `#portal-root` mounted at the end of `<body>`, outside the shell's DOM
  subtree. Because the portal root is a top-level stacking context, tier order among overlays is decided
  purely by the eight tokens, and no sticky table header or `overflow: hidden` scroll container can trap or
  clip an open menu.
- **Avoid accidental stacking contexts on scroll containers.** A `transform`, `filter`, `opacity < 1`,
  `will-change`, or `isolation: isolate` on an ancestor creates a new stacking context that can imprison a
  non-portalled child. Data tables and scroll regions in QAYD avoid these on the wrapper; anything that
  genuinely needs to float is portalled instead of relying on the wrapper's z.
- **Sticky is inside content, portals are above it.** `z-sticky` (10) lives *within* the content scroll —
  a frozen column or sticky header is below every portalled overlay by construction, so an open dropdown is
  never clipped by the header it overlaps.

## Portal usage

```tsx
// components/providers/portal-root.tsx — one portal root, mounted once
export function PortalRoot() {
  return <div id="portal-root" />; // Radix Portal containers target this node
}

// A Dialog: scrim at z-overlay, content at z-modal, both portalled
<DialogPortal container={portalRoot}>
  <DialogOverlay className="z-overlay bg-ink-12/50" />
  <DialogContent className="z-modal" />
</DialogPortal>
```

Every overlay pairs a **scrim** on the lower tier with **content** on the tier above it: modal
`overlay (30)` / `modal (40)`; the glass surfaces sit at `command (60)` above a `bg-ink-12/40` scrim. One
scrim per visible overlay depth — a Dialog opened from a Sheet shows only the Dialog's scrim over the Sheet
beneath; the Sheet does not also darken.

## Nesting limits

A Dialog opened from within a Sheet is the **maximum permitted overlay nesting** (drawer → modal). Both sit
at `z-modal`; the later-mounted Dialog wins by DOM order and carries its own `z-overlay` scrim over the
Sheet. A Dialog may **not** open a second Sheet or Dialog. The two glass surfaces (`z-command`) are mutually
exclusive — opening one closes the other — so two glass layers never coexist and the single-blur-layer budget
is structurally guaranteed.

## Never ad-hoc

There is no `z-[9999]`, no `z-[41]`, no inline `style={{ zIndex: 60 }}` in QAYD. If a layer appears in the
wrong order, the fix is to place it on the **correct tier** (and, almost always, to portal it), never to
out-number a neighbor. A genuinely new layer is a change to this document and the token set, design-reviewed,
not a number a component chooses on its own.

# Do / Don't

| Do | Don't |
|---|---|
| Reference one of the eight named tiers (`z-modal`, `z-command`) | Write a raw `z-[9999]` / `z-[41]` or inline `zIndex` |
| Portal escape-the-flow overlays through `#portal-root` | Inflate a sticky element's z-index into the overlay band |
| Keep `z-sticky` (10) inside the content scroll, below all portals | Raise a sticky header to sit above an open menu |
| Pair a scrim tier with its content tier (overlay 30 / modal 40) | Stack two scrims for one interaction |
| Cap nesting at drawer → modal (Sheet → Dialog) | Open a second Sheet or Dialog from inside a Dialog |
| Let tier order decide layering via the portal root | Fight layering by out-numbering integers |
| Add a new layer only by changing this document + the token set | Invent a component-local ninth z-index |
| Avoid `transform`/`filter`/`opacity` on scroll wrappers that would trap children | Rely on a bumped z-index to escape a self-made stacking context |

# Accessibility Notes

Stacking order is an accessibility contract, not only a visual one; QAYD keeps DOM order and visual order
aligned so keyboard and assistive-tech users experience the same layering sighted users do.

- **Focus follows the top layer.** When an overlay opens, focus moves into it and is trapped there until it
  closes (Radix `Dialog`/`Sheet` focus scope); on close, focus returns to the trigger. Because overlays sit
  on higher tiers *and* later in portal DOM order, the visually-topmost surface is also the one that owns the
  focus trap — the two never disagree.
- **Tooltips never occlude, and never trap.** `z-tooltip` (70) sits above everything, including a tooltip on
  a control *inside* a modal, so a keyboard user tabbing a form never triggers a tooltip that is clipped
  behind the dialog. Tooltips are non-modal and do not steal focus.
- **Toasts above modals, but not focus-stealing.** `z-toast` (50) renders a confirmation above an open dialog
  so it is seen, but the Sonner stack is polite (`aria-live="polite"`) and does not move focus out of the
  user's current task.
- **Scrims are inert to AT.** A scrim (`z-overlay`, 30) is decorative; the content it dims is marked
  `aria-hidden`/inert while the overlay is open, so a screen reader is not read the dimmed page behind an
  active dialog.
- **One order for both `dir` values.** The tier scale is direction-agnostic — nothing in it flips under
  `dir="rtl"`. What relocates in RTL is *position* (a Sheet's `side="end"` becomes the left edge), handled by
  logical properties, never by a different z-index; the stacking contract is identical in English and Arabic.
- **No layer hidden by inflation.** Because no component out-numbers its tier, an interactive element is never
  accidentally buried under a higher z sibling where it remains focusable but invisible — a trap for keyboard
  users that ad-hoc z-index frequently creates.

# End of Document
