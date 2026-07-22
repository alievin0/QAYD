# Tag — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / TAG
---

# Purpose

`Tag` is QAYD's primitive for a **user- or system-applied label that classifies a record along a
dimension** — a cost center, a project, a department, a tax dimension, a free-form label, or a live filter
token in a query bar. It is the atomic building block for anything the user *attaches*, *toggles*, or
*removes*, as opposed to anything the system *reports*.

That distinction from `Badge` is the reason this component exists as its own primitive rather than a variant
of `Badge`, and it is worth stating up front because the two look superficially similar (both are small,
rounded, low-height pills):

| | `Badge` / `StatusPill` (see [`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md)) | `Tag` (this document) |
|---|---|---|
| Semantics | **Output.** Reports a fact the system computed — a record's lifecycle status (`Posted`, `Voided`), a currency (`CurrencyTag`), an AI confidence band | **Attribute / input.** A dimension the user or an agent applied, toggled, or can remove |
| Interactivity | Read-only | Often interactive — removable, selectable, or typed into a tag input |
| Color | Carries a `tone` axis mapped to financial/status meaning (`success`/`warning`/`danger`) | Neutral ink by default; **never** carries a financial-polarity hue (a cost center is not "good" or "bad") |
| Canonical use | "This invoice is *Overdue*" | "Tag this expense to *Cost Center: Marketing*" |

Getting the choice right is a review gate: a lifecycle status rendered as a removable `Tag` invites a user
to "remove" a state they cannot actually change, and a cost-center dimension rendered as a semantic `Badge`
quietly invents the second brand hue the color system forbids
([`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) — "One accent, rationed"). This spec is the atomic contract;
domain tables (the cost-center list, the project list) live in their module specs and are consumed here, not
redefined.

# Anatomy

```
┌───────────────────────────────────────────────┐
│  ◦   Cost Center: Marketing            x       │   removable tag
│  │        │                            │       │
│  │        │                            └─ remove button (standalone, real <button>)
│  │        └─ label (may carry a dimension prefix)
│  └─ leading marker: dimension glyph OR a 6px dot (optional)
└───────────────────────────────────────────────┘
```

1. **Root** — an `inline-flex` container. `radius-full` for filter/dimension chips (the pill reads as
   "detachable"); `radius-sm` for input-field tokens that sit in a text-entry rhythm.
2. **Leading marker (optional)** — either a Lucide dimension glyph (`Boxes` for cost center, `FolderKanban`
   for project) at `xs`, or a 6px neutral dot, or nothing. It is always `aria-hidden` (the label carries the
   meaning) and uses a fixed-width gutter so labels align across a stack of mixed-width glyphs
   ([`../ICON_SYSTEM.md`](../ICON_SYSTEM.md) — "Fixed gutter in lists").
3. **Label** — the dimension value, `text-xs`/`text-sm`, `ink-11`. For dimensions it may carry a quiet
   prefix (`Cost Center:`) rendered one step lighter (`ink-9`) so the value reads first.
4. **Remove control (removable only)** — a real, separately-focusable `<button>` bearing an `X` glyph and an
   `aria-label`; it is **not** the tag body, so clicking the tag (to filter) and removing it are distinct
   targets.
5. **Count (optional)** — a trailing tabular number (`text-xs`, `ink-9`) for a filter chip that represents N
   matched rows.

The tag body and the remove button are two separate hit areas by construction — never one `<div onClick>`
that guesses which half the user meant.

# Variants

Two independent axes: **interaction mode** (what the tag does) and **tone** (deliberately shallow).

## Interaction mode

| Mode | Shape | Interactive element | Use |
|---|---|---|---|
| `static` | pill | none (a plain `<span>`) | A read-only dimension shown on a record detail ("Project: Atlas") |
| `removable` | pill | a trailing remove `<button>` | An applied dimension the user may detach; an active filter token in a query bar |
| `selectable` | pill | the whole tag is a toggle (`role` via `<button aria-pressed>`) | A filter facet the user turns on/off (e.g. an aging bucket, a tax code); a multi-select option chip |
| `input` | `radius-sm` token | the token sits inside a `TagInput` field; `Backspace` removes the last token | Free-form labels, keyword entry, recipient-style dimension entry |

## Tone

Tags are neutral by design. There are exactly **two** sanctioned tones, and no financial-polarity tone:

| Tone | Fill | Text | When |
|---|---|---|---|
| `neutral` (default) | `bg-ink-3` | `text-ink-11` | Every dimension, label, and filter token — the overwhelming default |
| `accent` | `bg-accent-subtle` | `text-ink-11` + `text-accent` marker | A **selected** `selectable` tag, or a tag an **AI agent applied** (per the accent's two sanctioned uses) |

There is no `success`/`warning`/`danger` tone on `Tag`. A dimension is not a judgment; coloring cost centers
by a traffic-light scale is the exact anti-pattern [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md) prohibits. A
tag that genuinely needs to report *status* is the wrong component — use `StatusPill`. Category is conveyed
by the **leading glyph and the label**, never by a per-dimension hue: fifteen cost centers do not get
fifteen colors.

## Size

| Size | Height | Type | Padding | Remove target |
|---|---|---|---|---|
| `sm` | 20px | `text-xs` | `px-2` | 20×20 (inline-control exception) |
| `md` (default) | 24px | `text-sm` | `px-2.5` | 24×24 |

# Props / API

```tsx
// components/ui/tag.tsx
import { cva, type VariantProps } from 'class-variance-authority';

export const tagVariants = cva(
  'inline-flex items-center gap-1.5 font-medium leading-none whitespace-nowrap ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1',
  {
    variants: {
      tone: {
        neutral: 'bg-ink-3 text-ink-11',
        accent: 'bg-accent-subtle text-ink-11',
      },
      size: { sm: 'h-5 px-2 text-xs', md: 'h-6 px-2.5 text-sm' },
      shape: { pill: 'rounded-full', token: 'rounded-sm' },
      disabled: { true: 'opacity-40 pointer-events-none', false: '' },
    },
    defaultVariants: { tone: 'neutral', size: 'md', shape: 'pill', disabled: false },
  },
);
```

| Prop | Type | Required | Description |
|---|---|---|---|
| `label` | `string` | yes | The dimension value; the tag's visible and accessible text. |
| `value` | `string \| number` | no | The stable key passed back by `onRemove` / `onSelect` (defaults to `label`). |
| `dimension` | `'cost_center' \| 'project' \| 'department' \| 'tax_dimension' \| 'label'` | no | Selects the leading glyph via the icon map; drives the optional prefix. Never selects a color. |
| `tone` | `'neutral' \| 'accent'` | no (default `neutral`) | `accent` only for a selected `selectable` tag or an AI-applied tag. |
| `size` | `'sm' \| 'md'` | no (default `md`) | |
| `removable` | `boolean` | no | Renders the trailing remove `<button>`. Requires `onRemove`. |
| `onRemove` | `(value) => void` | conditionally | Called by the remove button and, in `input` mode, by `Backspace`. |
| `selected` | `boolean` | no | For `selectable` mode; maps to `aria-pressed` and `tone="accent"`. |
| `onSelect` | `(value) => void` | no | Makes the whole tag a toggle `<button>`. |
| `count` | `number` | no | Trailing tabular count (matched rows for a filter chip). |
| `leadingIcon` | `LucideIcon` | no | Overrides the `dimension`-derived glyph. |
| `disabled` | `boolean` | no | Dims and disables; a disabled removable tag carries a tooltip explaining why (e.g. a locked-period dimension). |
| `asChild` | `boolean` | no | Radix `Slot` passthrough so a `static` tag can render as an `<a>` drill-through. |

`TagList` (the overflow container) is a thin composition, not a separate primitive:

| Prop | Type | Required | Description |
|---|---|---|---|
| `max` | `number` | no | Collapse past N tags into a `+N` overflow chip that opens a popover with the remainder. |
| `wrap` | `boolean` | no (default `true`) | `false` → single-row horizontal scroll inside an `overflow-x-auto` container. |
| `aria-label` | `string` | yes | Names the group for assistive tech (e.g. "Applied dimensions"). |

# States

| State | Treatment |
|---|---|
| Default | `bg-ink-3` / `text-ink-11`; marker `ink-9` |
| Hover (interactive) | Body → `bg-ink-4`; a `removable` tag's `X` lifts from `ink-8` to `ink-11` |
| Focus | 2px `accent` ring at 1px offset on whichever element is focused (body toggle or remove button, independently) |
| Selected (`selectable`) | `bg-accent-subtle`, marker `text-accent`, `aria-pressed="true"` |
| Removing | Optional exit animation: `motion.micro` (120ms) scale + fade, `useReducedMotion`-gated to an instant removal |
| Loading (async apply) | Marker swaps to a static `Loader2` (no spin under reduced motion) while the mutation is in flight; body non-interactive |
| Disabled | `opacity-40`, `pointer-events-none`, `aria-disabled`; state reinforced by a tooltip, never by color alone |

Removal is optimistic-with-rollback at the screen layer, but the primitive only emits `onRemove`; it never
mutates data itself.

# Tokens Used

Every value resolves to a token from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md); no raw hex, rgb, or px a
token already covers.

| Role | Token | Utility |
|---|---|---|
| Default fill | `ink-3` | `bg-ink-3` |
| Hover fill | `ink-4` | `bg-ink-4` |
| Selected / AI fill | `accent-subtle` | `bg-accent-subtle` |
| Label text | `ink-11` | `text-ink-11` |
| Prefix / marker / count | `ink-9` | `text-ink-9` |
| Selected marker | `accent` | `text-accent` |
| Remove glyph (rest → hover) | `ink-8` → `ink-11` | `text-ink-8` / `text-ink-11` |
| Focus ring | `accent` | `ring-accent` |
| Pill radius | `radius-full` | `rounded-full` |
| Token radius | `radius-sm` | `rounded-sm` |
| Gap / padding | `--space-2xs` / `--space-xs` | `gap-1.5`, `px-2`/`px-2.5` |
| Remove / exit motion | `motion.micro` (120ms), `easeOut` | via `lib/motion.ts` |

The leading glyph follows [`../ICON_SYSTEM.md`](../ICON_SYSTEM.md): one Lucide set, `xs` size, `1.75`
stroke, `currentColor`, `aria-hidden`.

# Accessibility

- **Remove is a real button.** The remove control is a `<button type="button">` with
  `aria-label={t('tag.remove', { label })}` (e.g. "Remove Cost Center: Marketing"), focusable and operable
  by `Enter`/`Space`, separate from the tag body. It is never a bare `<svg onClick>`.
- **Selectable is a real toggle.** A `selectable` tag renders as a `<button>` with `aria-pressed`
  reflecting `selected`; the pressed state is exposed to assistive tech, not implied by fill alone.
- **Input-mode keyboard model.** Inside `TagInput`, `Backspace` on an empty field removes the last token
  (announced via a polite live region: "Removed Marketing"); `Enter`/`,` commits the typed token; each
  committed token is focusable with arrow keys.
- **Static tags are not focus traps.** A `static` tag is a plain `<span>` (or an `<a>` via `asChild` when it
  drills through); it does not receive a tabindex "for consistency."
- **Group semantics.** `TagList` is a `role="list"` (or `<ul>`) with each tag a `role="listitem"`, named by
  `aria-label`, so a screen reader announces "Applied dimensions, list, 4 items."
- **Overflow is reachable.** The `+N` overflow chip is a real button opening a popover; the hidden tags are
  never keyboard-unreachable.
- **Contrast.** `ink-11` on `ink-3` (~11.4:1) and on `accent-subtle` both clear AA in light and dark; the
  remove glyph at rest (`ink-8`) is a non-text control that reaches ≥3:1 and is reinforced on hover/focus.
- **Never color alone.** Selection and dimension are carried by `aria-pressed`, the label, and the glyph —
  a colorblind or grayscale reader loses nothing (WCAG 1.4.1).

# Theming, Dark Mode & RTL

**Dark mode** is a token re-point, not a restyle: `bg-ink-3` becomes `#24211A`, `accent-subtle` becomes
`#3A2E14`, and the same classes render correctly with no conditional branch — the dark values are authored
in [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md), never derived by `invert()`.

**RTL** is handled entirely with logical properties so a tag mirrors with zero `dir === 'rtl'` checks:

- The leading marker sits on the **inline-start** edge and the remove button on the **inline-end** edge in
  both languages — in Arabic that puts the glyph on the right and the `X` on the left automatically.
- Gaps use `gap-*` on the flex row (not `ml-*`/`mr-*`), and any prefix separator uses `ms-*`/`me-*`.
- The `X` glyph is symmetric and **does not** mirror (`mirrorRtl={false}`); a directional dimension glyph
  never appears here.
- In a horizontally-scrolling `TagList`, scroll direction follows `dir`; the container is `overflow-x-auto`
  so the page body never scrolls sideways.
- `label` text is bidi-isolated; a Latin dimension value inside an Arabic sentence (or vice-versa) is
  wrapped so digits and codes keep their order.

# Do / Don't

| Do | Don't |
|---|---|
| Use `Tag` for dimensions the user applies, toggles, or removes (cost center, project, filter token) | Use `Tag` to report a record's lifecycle status — that is `StatusPill` |
| Keep dimension tags `neutral`; distinguish categories by glyph + label | Give each cost center or project its own color |
| Spend `accent` only on a *selected* tag or an *AI-applied* tag | Tint a static dimension brass "for emphasis" |
| Render the remove control as a separate, labelled `<button>` | Make the whole tag one `<div onClick>` that guesses remove vs. select |
| Collapse long sets into a `+N` popover reachable by keyboard | Wrap forty filter chips into a wall that pushes content off-screen |
| Reinforce a disabled tag with a tooltip | Leave a greyed dimension with no explanation of why it's locked |
| Use logical `ms-*`/`me-*` and `gap-*` so RTL mirrors for free | Hardcode `ml-*`/`pr-*` on the marker or `X` |
| Bidi-isolate mixed-script labels | Let a Latin project code scramble inside Arabic text |

# Usage & Composition

```tsx
// Applied dimensions on an expense detail — static, drill-through, RTL-safe
<TagList aria-label={t('dimensions.applied')} max={5}>
  <Tag dimension="cost_center" label={t('dim.costCenter', { name: 'Marketing' })} asChild>
    <Link href={ccHref(costCenter.id)} />
  </Tag>
  <Tag dimension="project" label={t('dim.project', { name: 'Atlas' })} />
  <Tag dimension="tax_dimension" label="VAT · Standard 5%" />
</TagList>
```

```tsx
// Filter facets in a query bar — selectable + removable, count-annotated
<TagList aria-label={t('filters.active')} wrap>
  {facets.map((f) => (
    <Tag
      key={f.value}
      label={f.label}
      value={f.value}
      selected={f.active}
      onSelect={toggleFacet}
      count={f.matchCount}
      removable
      onRemove={clearFacet}
    />
  ))}
</TagList>
```

```tsx
// An AI-applied dimension inside an AiCardShell proposal — accent tone, agent-sourced
<Tag
  dimension="cost_center"
  tone="accent"
  label={t('dim.costCenter', { name: 'R&D' })}
  removable
  onRemove={rejectSuggestedDimension}
/>
// Pairs with the AiCardShell provenance treatment in
// ../../frontend/components/AI_WIDGETS.md — the brass here reads as "AI suggested,
// not yet committed," consistent with the accent's second sanctioned use.
```

`Tag` composes into filter bars ([`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md)
patterns), the `AiCardShell` proposal surface
([`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md)), and record-detail
headers. It never replaces `StatusPill` (lifecycle), `CurrencyTag` (ISO currency), or `Badge` (computed
status) — see the disambiguation table in [Purpose](#purpose). Sibling primitives:
[`./AVATAR.md`](./AVATAR.md), [`./PROGRESS.md`](./PROGRESS.md), [`./TIMELINE.md`](./TIMELINE.md).

# End of Document
