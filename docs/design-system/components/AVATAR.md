# Avatar — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / AVATAR
---

# Purpose

`Avatar` is QAYD's primitive for a **compact identity mark of a person or entity** — the accountant who
posted an entry, the manager in an approval chain, a team member on a mention, a vendor's contact. It
resolves an identity to one of three renderings in a strict fallback order — **image → initials → icon** —
inside a single circular shape, and it composes into a stacked `AvatarGroup` with an overflow count.

It also owns the boundary with one thing it is emphatically **not**: the AI agent's identity mark. The
fifteen AI agents are represented by `AgentAvatar`, a specialized variant whose "identity" is a Lucide
*domain glyph* in a tinted circle, owned in full by
[`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md). This document specifies
the human/entity avatar and its group behavior, and defers `AgentAvatar` to that spec so the two never
drift. The rule that keeps them distinct is deliberate and taste-driven: **a person may have a photo; an
agent never does** — no per-agent mascot, no photo, no color-per-agent scheme
([`../ICON_SYSTEM.md`](../ICON_SYSTEM.md) — "Represent each AI agent with a Lucide domain glyph").

One more QAYD-specific stance separates this from most avatar primitives: **initials avatars are neutral
ink, not a hashed rainbow.** Hashing a name to one of a dozen bright fill colors is the single most common
avatar pattern and it is exactly the "second brand color for visual interest" the color system forbids
([`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md)). QAYD avatars are warm-neutral graphite; identity is carried by
the initials and the accessible name, never by a color the user has to learn.

# Anatomy

```
        ring (AvatarGroup only:                 status dot (optional):
        1px ink-1 gap between stacked)          positive / warning / ink-7,
              │                                  positioned on the inline-end
        ┌─────┴─────┐                            bottom corner, with an ink-1 ring
        │           │◦ ◄──────────────────────────────────────────────┘
        │    A F    │   ◄─ content layer, in fallback order:
        │           │        1. <img>  (loaded, non-error)
        └───────────┘        2. initials  (derived from name)
          radius-full        3. fallback icon  (User / Building2)
```

1. **Root** — a `radius-full` `inline-flex` box at a fixed `size-*`. Circle only; QAYD has no squared
   avatars (the only fully-rounded shapes in the system are avatars, status dots, and pills —
   [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) Radius).
2. **Content layer** — exactly one of:
   - **Image** — an `<img>` (or `next/image`) covering the circle, `object-cover`. On load error it
     unmounts and the initials layer shows.
   - **Initials** — 1–2 letters derived from `name`, `ink-11` on `ink-4`.
   - **Icon fallback** — a Lucide `User` (person) or `Building2` (organization) at the size's icon token,
     `ink-9` on `ink-3`, when there is neither image nor name.
3. **Status dot (optional)** — a small dot on the inline-end bottom corner, ringed by `ink-1` so it reads
   off the avatar edge. Its meaning is always duplicated in the accessible name.
4. **Ring (group only)** — a `ring-2 ring-ink-1` (the canvas color) so overlapping avatars in a stack read
   as separated, not merged.

# Variants

## Rendering (fallback order, not a user choice)

| Rendering | Condition | Fill | Foreground |
|---|---|---|---|
| Image | `src` present and loads | image | — |
| Initials | no image, `name` present | `bg-ink-4` | `text-ink-11` initials |
| Icon fallback | neither | `bg-ink-3` | `text-ink-9` glyph (`User`/`Building2`) |

## Size

Sizes land on even Tailwind steps and align with the icon scale in
[`../ICON_SYSTEM.md`](../ICON_SYSTEM.md), so an inner glyph centers with integer padding.

| Size | px | Utility | Initials type | Typical use |
|---|---|---|---|---|
| `2xs` | 20 | `size-5` | `text-[10px]` | Dense table actor cell, timeline node |
| `xs` | 24 | `size-6` | `text-xs` | Comment author, inline mention |
| `sm` | 28 | `size-7` | `text-sm` | Approval-chain member, list row |
| `md` (default) | 32 | `size-8` | `text-sm` | Record header, card attribution |
| `lg` | 40 | `size-10` | `text-base` | Profile summary, detail hero |
| `xl` | 56 | `size-14` | `text-lg` | Profile / settings page header |

## AgentAvatar (deferred variant)

The AI variant — a domain glyph in a 28px `accent-subtle` circle — is **not re-specified here**. Use
`AgentAvatar` from [`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md) for
any of the fifteen agents. The one design-system obligation this document records: an agent mark is a Lucide
domain glyph on `accent-subtle`, never a photo, initials, or a per-agent hue — so a human avatar and an
agent avatar are visually distinguishable at a glance (graphite person vs. brass-tinted glyph).

## AvatarGroup

An overlapping stack for "N people on this record." Avatars overlap by ~30% of their width, each with an
`ink-1` ring so the edges read as separated rather than merged; past `max`, the remainder collapses into a
trailing `+N` count chip (same size, `bg-ink-5`, `ink-11`).

| Aspect | Rule |
|---|---|
| Overlap | ~30% (`-space-x-2` at `md`), resolved against `dir` so it mirrors in RTL |
| Stack order | Earliest actor on top in LTR reading order; z-index descends along the stack so no ring is clipped |
| Overflow | `+N` where `N = total − shownChildren`; `total` may exceed the rendered children for server-truncated lists |
| Overflow behavior | The `+N` chip is a real `<button>` opening a popover that lists the remaining names |
| Sizing | A single `size` applies to every child and the chip; children never mix sizes within one group |

A group never silently drops members below the fold: everyone is either a visible avatar or reachable
through the `+N` popover.

## Initials — neutral, grapheme-aware, bilingual

Initials are the most-rendered variant (most users have no photo), so their rules are explicit:

- **Neutral fill, always.** `bg-ink-4` / `text-ink-11` for every user — no name-hashed color. This is the
  deliberate departure from convention that keeps the palette to one accent
  ([`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md)).
- **Grapheme-aware derivation.** First grapheme of the first and last whitespace tokens, upper-cased,
  script-preserving; single-token names yield one letter; a byte slice that would split a combined character
  is never used.
- **Bilingual by construction.** "Sara Al-Fulan" → `SA` (LTR); "سارة الفلان" → `سا` (RTL). Mixed-script names
  take the script of the leading token. Symbol-/emoji-only names fall through to the icon fallback.

# Props / API

```tsx
// components/ui/avatar.tsx
import { cva, type VariantProps } from 'class-variance-authority';

export const avatarVariants = cva(
  'relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-medium select-none',
  {
    variants: {
      size: {
        '2xs': 'size-5 text-[10px]', xs: 'size-6 text-xs', sm: 'size-7 text-sm',
        md: 'size-8 text-sm', lg: 'size-10 text-base', xl: 'size-14 text-lg',
      },
      kind: {
        initials: 'bg-ink-4 text-ink-11',
        icon: 'bg-ink-3 text-ink-9',
        image: 'bg-ink-3',
      },
    },
    defaultVariants: { size: 'md', kind: 'initials' },
  },
);
```

| Prop | Type | Required | Description |
|---|---|---|---|
| `name` | `string` | yes* | Person/entity name — the accessible label and the initials source. Required unless the avatar is decorative beside a visible name. |
| `src` | `string` | no | Image URL; on load-error the component silently falls back to initials. |
| `size` | size token | no (default `md`) | See scale above. |
| `entity` | `'person' \| 'organization'` | no (default `person`) | Selects the icon-fallback glyph (`User` vs `Building2`). |
| `status` | `'active' \| 'away' \| 'offline' \| null` | no | Renders a status dot; its text is folded into the accessible name. |
| `decorative` | `boolean` | no | `true` when a visible name label sits beside it → `aria-hidden`, no redundant announcement. |
| `initials` | `string` | no | Explicit override when auto-derivation would be wrong (compound/multi-part names). |

`AvatarGroup`:

| Prop | Type | Required | Description |
|---|---|---|---|
| `max` | `number` | no (default `4`) | Avatars shown before the `+N` overflow chip. |
| `total` | `number` | no | True total if larger than the passed children (server-truncated lists); drives `+N`. |
| `size` | size token | no | Applied to every child and the overflow chip. |
| `aria-label` | `string` | yes | Summarizes the group ("Approvers: 6 people"). |

**Initials derivation.** From `name`: take the first grapheme of the first and last whitespace-separated
tokens, upper-cased, script-preserving — a Latin "Sara Al-Fulan" → `SA`, an Arabic "سارة الفلان" → `سا`
(rendered RTL). Single-token names yield one letter. Emoji or symbol-only names fall through to the icon.
The derivation is grapheme-aware (never a byte slice that splits a combined character).

# States

| State | Treatment |
|---|---|
| Image loading | `bg-ink-3` placeholder (a subtle skeleton pulse, reduced-motion-safe); no layout shift — the box is already sized |
| Image loaded | Image fades in over `motion.fast`; reduced motion shows it instantly |
| Image error | Layer unmounts → initials (or icon); no broken-image glyph ever renders |
| Interactive (avatar-as-link/trigger) | Wrapped in a real `<button>`/`<a>`; hover lifts a `ring-2 ring-ink-6`; focus shows the `accent` ring at offset |
| Status: active / away / offline | Dot in `positive` / `warning` / `ink-7`; **always** mirrored in the accessible name |
| Group overflow | `+N` chip; hovering it opens a popover listing the remaining names (reachable by keyboard) |
| Disabled (e.g. deactivated user) | `opacity-40` + a tooltip stating the account is inactive |

# Tokens Used

| Role | Token | Utility |
|---|---|---|
| Initials fill | `ink-4` | `bg-ink-4` |
| Initials text | `ink-11` | `text-ink-11` |
| Icon-fallback fill | `ink-3` | `bg-ink-3` |
| Icon-fallback glyph | `ink-9` | `text-ink-9` |
| Stack separation ring | `ink-1` | `ring-ink-1` |
| Overflow chip fill | `ink-5` | `bg-ink-5` |
| Hover ring (interactive) | `ink-6` | `ring-ink-6` |
| Focus ring | `accent` | `ring-accent` |
| Status: active | `positive` | `bg-positive` |
| Status: away | `warning` | `bg-warning` |
| Status: offline | `ink-7` | `bg-ink-7` |
| AgentAvatar fill (deferred) | `accent-subtle` | `bg-accent-subtle` (see AI_WIDGETS) |
| Shape | `radius-full` | `rounded-full` |
| Load / fade motion | `motion.fast` (160ms) | via `lib/motion.ts` |

Every value is a token from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md); no per-user hashed color, no raw
hex.

# Accessibility

- **The accessible name is the identity.** A meaningful avatar exposes `name` (plus status, when present) as
  its accessible name: an `<img alt={name}>`, or `role="img" aria-label` on the initials/icon rendering.
  "SA" is never announced as the bare letters.
- **Status is never color-only.** A status dot's meaning is folded into the accessible name — "Sara Al-Fulan,
  active" — satisfying WCAG 1.4.1; the dot is decorative reinforcement (`aria-hidden`).
- **Decorative when duplicated.** When a visible name label sits beside the avatar (a comment row, a table
  cell), pass `decorative` so the avatar is `aria-hidden` and the name is not announced twice.
- **Real controls for interactive avatars.** An avatar that opens a profile card or menu is a `<button>`/
  `<a>` with its own `aria-label`, keyboard-operable, with a visible `accent` focus ring at non-zero offset —
  never an `<img onClick>`.
- **Group summary.** `AvatarGroup` carries an `aria-label` naming the set and count; the `+N` chip is a real
  button whose popover lists the hidden names, so no member is keyboard-unreachable.
- **Contrast.** Initials `ink-11` on `ink-4` clears AA in both themes; the icon-fallback glyph `ink-9` on
  `ink-3` clears the 3:1 non-text floor. The `ink-1` separation ring is decorative and exempt.
- **Reduced motion.** The load fade and skeleton pulse collapse to instant/static under
  `prefers-reduced-motion`.

# Theming, Dark Mode & RTL

**Dark mode** is a token re-point: `ink-4` → `#2D2921`, `ink-11` → `#E4DEC9`; the same classes render a
legible graphite avatar in both themes with no branch. Status hues use the authored dark values
(`positive` `#4ADE94`, `warning` `#F5A855`) so a dot never glows against the dark canvas.

**RTL:**

- **Stack direction follows `dir`.** In LTR an `AvatarGroup` overlaps left-over-right and the `+N` chip
  trails on the inline-end (right); in RTL the overlap and the `+N` chip mirror automatically because the
  stack is built with logical spacing (`-space-x-*` resolved against `dir`, `ms-*`/`me-*` for the chip), not
  hardcoded `margin-left`.
- **Status dot** is positioned with logical insets (`end-0 bottom-0`), so it lands on the inline-end bottom
  corner in both languages — bottom-right in English, bottom-left in Arabic.
- **Initials render in the name's script and direction.** Arabic initials are RTL-ordered; the box itself is
  direction-neutral (centered), so no mirroring of the glyph is needed.
- The fallback `User`/`Building2` glyphs are symmetric and **do not** mirror.

# Do / Don't

| Do | Don't |
|---|---|
| Fall back image → initials → icon, silently | Render a broken-image glyph when a photo 404s |
| Keep initials avatars neutral graphite | Hash the name to a bright per-user fill color |
| Use `AgentAvatar` (AI_WIDGETS) for the fifteen agents | Give an agent a photo, initials, or a per-agent color |
| Fold status into the accessible name | Convey "online/away" by the dot color alone |
| Pass `decorative` when a name label is already visible | Announce the same person twice to a screen reader |
| Ring stacked avatars with `ink-1` and cap with a real `+N` button | Merge overlapping avatars into an unreadable blob or hide members from keyboard |
| Position the status dot and `+N` chip with logical `end-*`/`ms-*` | Hardcode `right-0`/`margin-left` so RTL breaks |
| Size on even steps from the scale | Build a `size-[34px]` one-off |

# Usage & Composition

```tsx
// Actor cell in a table — decorative avatar beside a visible name (RTL-safe)
<span className="inline-flex items-center gap-2">
  <Avatar name={row.actor.name} src={row.actor.avatarUrl} size="2xs" decorative />
  <span className="text-sm text-ink-11">{row.actor.name}</span>
</span>
```

```tsx
// Approval chain — a stack with overflow, keyboard-reachable
<AvatarGroup max={4} total={approval.totalApprovers} size="sm"
  aria-label={t('approval.approvers', { count: approval.totalApprovers })}>
  {approval.approvers.map((a) => (
    <Avatar key={a.id} name={a.name} src={a.avatarUrl} status={a.presence} />
  ))}
</AvatarGroup>
```

```tsx
// Vendor contact — organization fallback glyph, no photo/name known
<Avatar name={vendor.contactName} entity="organization" size="md" />
```

`Avatar` composes into `TIMELINE` event nodes ([`./TIMELINE.md`](./TIMELINE.md)), `DataTable` actor cells,
`ApprovalCard` chains, and profile headers. For AI attribution use `AgentAvatar` /
`AgentAttributionChip` from
[`../../frontend/components/AI_WIDGETS.md`](../../frontend/components/AI_WIDGETS.md), not this primitive.
Sibling primitives: [`./TAG.md`](./TAG.md), [`./PROGRESS.md`](./PROGRESS.md),
[`./TIMELINE.md`](./TIMELINE.md).

# End of Document
