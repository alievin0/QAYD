# Badge — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / BADGE
---

# Purpose

This document is the atomic specification for QAYD's **badge** primitive: the small, non-interactive,
pill-shaped label that annotates something with a compact piece of state, category, or count — a record's
status, an unread count on a nav item, a "New" tag, a leading provenance dot. It is the base primitive that
[`../../frontend/COMPONENT_LIBRARY.md → StatusPill`](../../frontend/COMPONENT_LIBRARY.md),
`CurrencyTag`, and `ConfidenceBadge` are all built on, so getting the atom right propagates correctness across
every stateful surface in the product.

The badge's one hard rule, inherited from the color system, is that **color never carries the meaning alone**.
Every semantic badge pairs its tone with a second, non-color signal — a leading dot, a glyph, and always a
text label — so the same information survives grayscale board-pack printing, colorblind vision, and a no-JS
PDF export ([`../COLOR_SYSTEM.md → Color never carries meaning alone`](../COLOR_SYSTEM.md)). A finance product
whose "overdue" and "reconciled" look identical in a printed statement is not merely inaccessible; it is
wrong. The badge is designed so that can never happen.

A badge is deliberately narrow. It **labels**; it does not act (a clickable pill is a `Button` or a filter
chip, not a badge), it does not float (a positioned overlay is a `Toast` or `Popover`), and it does not
persist a full record's worth of detail (that is a table cell or a `Card`). Its variants are backed by QAYD's
canonical palette — the twelve-step warm-neutral **ink** scale, the single **brass accent**, and the three
financial **semantic** colors (`positive` / `warning` / `negative`, with the deliberate absence of an "info"
hue) — never a raw Tailwind color.

Related reading: the tones and the "never color alone" contract in [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md);
radius/typography tokens in [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md); the `StatusPill` domain lookup and
`ConfidenceBadge` composition in [`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md);
a11y color rules in [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md). Sibling primitives:
[`./TOAST.md`](./TOAST.md), [`./TOOLTIP.md`](./TOOLTIP.md), [`./POPOVER.md`](./POPOVER.md).

# Anatomy

A badge is a single inline pill.

| Part | Role | Notes |
|---|---|---|
| Container | The pill | `inline-flex items-center`, `radius-full`, `text-xs` (12px, weight 500), `px-2.5 py-0.5`, `leading-none`. Never wraps. |
| Leading dot (status) | A 6px filled `radius-full` dot in the tone color | The non-color-alone partner for a `StatusPill`; `aria-hidden` (the label carries the meaning). |
| Leading glyph (optional) | A 12–14px Lucide glyph | Used where a shape reinforces meaning (a tick on "High confidence", a warning triangle). `aria-hidden`. |
| Label | The text — the accessible name | `text-xs`; always present for a semantic badge. Numeric-only for a count badge. |

The fill is a **desaturated tint, not a saturated block**: `bg-*-subtle` behind `text-*` for the semantic
tones, `bg-accent-subtle`/`text-accent` for the accent, `bg-ink-3`/`text-ink-9` for neutral. A badge is a
quiet annotation, never a loud chip — a screen full of saturated badges would fight the ink-first hierarchy
the whole system is built on.

```
 ● Posted     ● Pending approval     ● Overdue     New     ⌄3     •
 positive       warning                negative     accent  count  dot-only
```

# Variants

## Tone (semantic axis)

Five tones, each a `bg-*-subtle` + `text-*` pairing, driven by `cva`. Every tone except `neutral` and
`accent` maps to a canonical financial semantic token. There is no sixth "info" tone — a neutral, non-financial
annotation uses `neutral` (ink), never a blue tint ([`../COLOR_SYSTEM.md → no "info" hue`](../COLOR_SYSTEM.md)).

| Tone | Fill / text tokens | Meaning | Non-color partner |
|---|---|---|---|
| `neutral` | `bg-ink-3` / `text-ink-9`, dot `ink-9` | Draft, archived, non-financial category, "info"-style note | Dot + label |
| `accent` | `bg-accent-subtle` / `text-accent`, dot `accent` | Approved, AI-touched, "New" | Dot + label |
| `success` | `bg-positive-subtle` / `text-positive`, dot `positive` | Posted, reconciled, paid | Dot + label |
| `warning` | `bg-warning-subtle` / `text-warning`, dot `warning` | Pending, unreconciled, nearing a limit | Dot + label |
| `danger` | `bg-negative-subtle` / `text-negative`, dot `negative` | Rejected, overdue, voided, failed | Dot + label |

> Token naming note: the `cva` tone names `success` / `danger` are retained for API continuity with
> `StatusPill`'s existing lookup tables, but they resolve to QAYD's canonical **`positive`** / **`negative`**
> tokens ([`../COLOR_SYSTEM.md → Semantic Colors`](../COLOR_SYSTEM.md)) — the tone name is the component API,
> the color token is the source of truth. `accent`/`accent-subtle` remain the one brass, reserved for a
> primary/selected state or AI provenance and nothing else.

## Shape (structural axis)

| Shape | Use | Notes |
|---|---|---|
| Label badge | The default — dot/glyph + text | `StatusPill`, `CurrencyTag`, "New" |
| Count badge | A numeric count on a nav item / tab | `radius-full`, min-width square-ish; `99+` overflow; never zero (a 0-count badge is *hidden*, not shown as "0") |
| Dot indicator | A bare 6px/8px dot, no label | Presence-only signal ("has unread", "AI touched this row"); **requires** a visually-hidden text label or `aria-label` since it has no visible text (see Accessibility) |

## Size

| Size | Padding / text | Use |
|---|---|---|
| `sm` | `px-2 py-0 text-[11px]` | Dense tables, inline cell chips |
| `default` | `px-2.5 py-0.5 text-xs` | Standard |

# Props / API

## `Badge` (primitive)

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `tone` | `'neutral' \| 'accent' \| 'success' \| 'warning' \| 'danger'` | no | `'neutral'` | The semantic tone; resolves to the canonical color token. |
| `size` | `'sm' \| 'default'` | no | `'default'` | |
| `shape` | `'label' \| 'count' \| 'dot'` | no | `'label'` | Structural form. `dot`/`count` require an accessible name (see below). |
| `aria-label` | `string` | conditional | — | **Required** for `shape="dot"` and for a `count` whose surrounding context does not name it. |
| `className` | `string` | no | — | Composition escape hatch (e.g. `StatusPill`'s size tweak). Never used to inject a raw color. |
| `children` | `ReactNode` | conditional | — | The label / glyph + text. Omitted only for `shape="dot"`. |

`Badge` is intentionally **not** interactive: it renders a `<span>`, has no `onClick`, and takes no `href`. A
clickable pill (a removable filter chip, a toggle) is a different component built on `Button`, so a badge is
never mistaken for an affordance.

## `cva` definition

```tsx
// components/ui/badge.tsx
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

export const badgeVariants = cva(
  'inline-flex items-center gap-1.5 rounded-full font-medium leading-none whitespace-nowrap ' +
  'align-middle select-none',
  {
    variants: {
      tone: {
        neutral: 'bg-ink-3 text-ink-9',
        accent:  'bg-accent-subtle text-accent',
        success: 'bg-positive-subtle text-positive',   // token = --qayd-positive
        warning: 'bg-warning-subtle text-warning',
        danger:  'bg-negative-subtle text-negative',   // token = --qayd-negative
      },
      size: {
        sm:      'px-2 py-0 text-[11px]',
        default: 'px-2.5 py-0.5 text-xs',
      },
    },
    defaultVariants: { tone: 'neutral', size: 'default' },
  },
);

const DOT_TONE: Record<string, string> = {
  neutral: 'bg-ink-9', accent: 'bg-accent',
  success: 'bg-positive', warning: 'bg-warning', danger: 'bg-negative',
};

export function Badge({
  tone = 'neutral', size, shape = 'label', className, children, ...props
}: React.ComponentProps<'span'> & VariantProps<typeof badgeVariants> & { shape?: 'label' | 'count' | 'dot' }) {
  if (shape === 'dot') {
    // bare presence dot — MUST carry an aria-label (props), the meaning lives there
    return <span className={cn('inline-block h-1.5 w-1.5 rounded-full', DOT_TONE[tone!], className)} {...props} />;
  }
  return (
    <span className={cn(badgeVariants({ tone, size }), shape === 'count' && 'min-w-5 justify-center px-1.5', className)} {...props}>
      {children}
    </span>
  );
}
```

## `StatusPill` — the domain composition (from COMPONENT_LIBRARY)

`StatusPill` is the one component that turns a raw record enum into a `Badge` with the right tone and label,
via a per-domain lookup so `"posted"` never renders differently on two screens. It **always** prepends the
leading dot — the non-color partner:

```tsx
export function StatusPill({ status, domain, size = 'default' }: StatusPillProps) {
  const entry = STATUS_TABLES[domain]?.[status] ?? { label: status, tone: 'neutral' as const };
  return (
    <Badge tone={entry.tone} size={size}>
      <span className={cn('h-1.5 w-1.5 rounded-full', DOT_TONE[entry.tone])} aria-hidden />
      {entry.label}
    </Badge>
  );
}
```

The domain lookup tables (`journal_entry`, `invoice`, `bill`, `payroll_run`, `tax_return`, `bank_transfer`)
live in [`../../frontend/COMPONENT_LIBRARY.md → StatusPill`](../../frontend/COMPONENT_LIBRARY.md); this
document specifies the atom they compose, not the tables themselves.

# States

A badge is non-interactive, so it has no hover/focus/pressed states of its own — its "state" is entirely the
`tone` × `shape` × `size` it is rendered with. The behaviors that do vary:

| Concern | Behavior |
|---|---|
| Zero count | A `count` badge with value `0` renders **nothing** (the badge is absent), never a "0" pill. |
| Count overflow | Values above the cap render `99+` (`text-xs`, still one line). |
| Unknown status | `StatusPill` falls back to `tone="neutral"` and shows the raw enum string as the label rather than crashing or hiding it — a new server status degrades to a legible neutral pill. |
| Truncation | A badge never truncates its own label (it `whitespace-nowrap`s); an over-long category is a data problem, solved upstream, not by clipping the pill. |
| Inside an interactive row | When a badge sits in a clickable table row or button, it is `pointer-events-none` decorative — the row/button owns the interaction, the badge never intercepts it. |

# Tokens Used

| Concern | Token | Value |
|---|---|---|
| Radius | `radius-full` | 9999px (the pill) |
| Neutral fill / text | `ink-3` / `ink-9` | |
| Accent fill / text / dot | `accent-subtle` / `accent` | `#EADFBF`·`#9C7A34` (light) |
| Success fill / text / dot | `positive-subtle` / `positive` | `#E3F3EA`·`#17794A` (light) |
| Warning fill / text / dot | `warning-subtle` / `warning` | `#FBEBDA`·`#B45309` (light) |
| Danger fill / text / dot | `negative-subtle` / `negative` | `#FBE7E8`·`#B4232E` (light) |
| Text | `text-xs`, weight 500 | 12px |
| Dot | 6px `radius-full`, tone solid | |
| Padding | `px-2.5 py-0.5` (`default`) / `px-2 py-0` (`sm`) | |

A badge casts **no shadow** and sits at `z-base` — it is inline content, not a floating surface, so it never
touches the elevation or z-index scales ([`../ELEVATION_SHADOWS.md`](../ELEVATION_SHADOWS.md)). Its separation
from surrounding text is carried entirely by its subtle fill and pill shape.

# Accessibility

- **The label is the accessible name; the dot/glyph is decorative.** Every semantic badge has visible text,
  and its leading dot and any glyph are `aria-hidden`. Meaning never depends on color or a shape a screen
  reader cannot read ([`../ACCESSIBILITY.md`](../ACCESSIBILITY.md)).
- **A dot-only badge MUST carry text.** `shape="dot"` has no visible label, so it is required to supply an
  `aria-label` (or an adjacent visually-hidden `<span>`), e.g. `aria-label="AI suggested"` on a row-gutter
  provenance dot. A bare colored dot with no text name is a WCAG 1.4.1 failure and is rejected in review.
- **Count badges are announced with context.** A count badge either sits inside an element whose name already
  conveys it ("Approvals, 3 items") or supplies its own `aria-label` ("3 unread"); "3" alone, unlabeled, is
  not a meaningful announcement.
- **Never color alone — the printed-statement test.** Because "Posted"/"Overdue"/"Pending" are carried by
  their text label first, an exported grayscale board pack or a no-color PDF reads every badge correctly; the
  tone is reinforcement, not the signal. This is the same invariant `AmountCell`'s sign-glyph and
  `StatusPill`'s dot enforce ([`../../frontend/COMPONENT_LIBRARY.md → Printing`](../../frontend/COMPONENT_LIBRARY.md)).
- **Contrast holds in both themes.** Each `text-*` on its `*-subtle` fill clears AA (≈4.7–5.2:1), verified in
  [`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md); the tone remaps under `.dark` to its
  calibrated value so the pill keeps meaning and contrast without glowing.
- **Not a focus target.** A badge is not interactive and takes no tab stop; if a "badge" needs to be
  focusable/clickable, it is the wrong primitive (use a `Button`-based chip).

# Theming, Dark Mode & RTL

## Theming

The badge references only tokens — `bg-ink-3`, `text-ink-9`, `bg-accent-subtle`, `text-positive`,
`bg-negative-subtle`, etc. A raw hex or a Tailwind palette value (`bg-red-100`) in a badge is a review-blocking
error. The per-company white-label accent affects only the `accent` tone (the one brass); it never touches the
semantic tones, because financial status color is never brand color
([`../COLOR_SYSTEM.md → Usage Rules`](../COLOR_SYSTEM.md)). A screen must remain readable with the accent
swapped for `ink-9` — a status badge does not depend on the brand hue.

## Dark mode

No `dark:` variants on the badge itself. Every fill/text/dot token remaps under `.dark` to its calibrated
value — `positive-subtle` `#E3F3EA` → `#12301F`, `positive` `#17794A` → `#4ADE94`, and so on — so a "Posted"
pill keeps the same relationship (subtle wash behind a legible tone) against the dark canvas without a second
implementation. Dark badges are neither brighter nor more saturated than they need to be; the subtle-wash
approach is what keeps them quiet in both themes.

## RTL

- **The pill and its dot mirror for free.** `inline-flex` + logical `gap` means the leading dot sits on the
  reading-start edge in both directions; no `border-l`/`ml` is written, so the dot leads the label in Arabic
  exactly as in English.
- **A count badge positioned on a nav item uses logical insets** (`inset-inline-end`), so it pins to the
  reading-end corner of its host in both directions.
- **Latin/numeric labels render `dir="ltr"`.** A `CurrencyTag` ("KWD"), a `ConfidenceBadge` ("92%"), or a
  numeric count is a Latin/numeric token wrapped `dir="ltr"` so it reads correctly inside an Arabic layout —
  numerals and codes never mirror ([`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md)).
- **The dot and glyph are symmetric** and need no mirroring; a directional glyph would never belong in a badge
  anyway.

# Do / Don't

| Do | Don't |
|---|---|
| Pair every semantic tone with a dot/glyph **and** a text label | Ship a status conveyed by color alone (a bare colored pill) |
| Give a `shape="dot"` badge an `aria-label` | Render a naked provenance dot with no accessible name |
| Use `neutral` (ink) for a non-financial "info"-style note | Introduce a blue "info" tone and acquire a second brand color |
| Reserve `accent` for approved / AI-touched / "New" only | Use brass as a generic decorative tag color |
| Map `success`/`danger` tones to the `positive`/`negative` tokens | Hardcode `bg-green-100` / `text-red-600` in a badge |
| Hide a zero-count badge entirely | Render a "0" count pill |
| Route every record status through `StatusPill`'s domain lookup | Hand-write a one-off status pill on a screen with its own colors |
| Keep the badge a non-interactive `<span>` | Add `onClick`/`href` to a badge (use a Button-based chip) |
| Keep the fill a subtle wash | Use a saturated, full-strength block fill that fights the ink hierarchy |
| Wrap numeric/code labels `dir="ltr"` | Let a currency code or percentage mirror in an Arabic layout |

# Usage & Composition

**Record status** — the overwhelmingly common case, always via `StatusPill`:

```tsx
<StatusPill domain="journal_entry" status={entry.status} />   // ● Posted / ● Pending approval / ● Rejected
```

**Count badge on a nav item** — hidden at zero, `99+` overflow, logical positioning:

```tsx
{count > 0 && (
  <Badge tone="danger" shape="count" aria-label={t('unreadCount', { n: count })}
         className="absolute inset-inline-end-1 top-1">
    {count > 99 ? '99+' : count}
  </Badge>
)}
```

**Provenance dot** — a bare AI-touched indicator in a table row's leading gutter, with a required accessible
name (the `accent` dot is the sanctioned AI-provenance mark from
[`../COLOR_SYSTEM.md → Provenance in a table row`](../COLOR_SYSTEM.md)):

```tsx
<Badge tone="accent" shape="dot" aria-label={t('aiSuggested')} />
```

**"New" / category tag** — a plain label badge:

```tsx
<Badge tone="accent">{t('new')}</Badge>
<Badge tone="neutral">{account.type_label}</Badge>
```

**Compositions built on this atom** — `StatusPill` (domain status → tone + dot + label), `CurrencyTag`
(text-only ISO code, `dir="ltr"`), and `ConfidenceBadge` (percentage + band label, optional `Tooltip` for
reasoning) are all specified in [`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md);
each consumes `Badge` and adds domain logic, never a new color or a hand-rolled pill. A badge that needs to
explain itself pairs with a [`./TOOLTIP.md`](./TOOLTIP.md) (as `ConfidenceBadge` does); a badge is never the
trigger for a [`./POPOVER.md`](./POPOVER.md) or a [`./TOAST.md`](./TOAST.md), because it is not interactive.

# End of Document
