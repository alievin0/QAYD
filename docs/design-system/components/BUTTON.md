# Button — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / BUTTON
---

# Purpose

This document is the atomic, token-level specification of QAYD's `Button` primitive — the single
`cva`-driven control every clickable action in the product is built from. It defines the primitive in
isolation: its anatomy, its full variant and size matrices, every interaction state, the exact design
tokens each variant references, its accessibility contract, and its prop/API surface. It is deliberately
implementation-first and screen-agnostic — it says nothing about *which* action a button performs, only
about how the primitive looks, behaves, and is themed.

It is the design-system half of a two-document pair. The application-integration half —
[`../../frontend/components/BUTTONS.md`](../../frontend/components/BUTTONS.md) — owns everything this
document deliberately omits: when a button is a Server Action submit versus a TanStack `useMutation`
client button, the `SplitButton`/`ButtonGroup` compositions, destructive-confirmation flows, the
Server-side RBAC truth, and the finance-specific wiring. Read this document for the primitive; read that
one for how the primitive is composed into a screen. Where the two touch the same surface (variants,
sizes, the RBAC-disabled affordance) they agree; where the application doc uses the pre-canonical draft
token names (`accent-600`, `ink-950`, `danger`), this document uses the **canonical** brass / `ink-1…12`
tokens from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and [`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md),
which govern (see [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md)).

The single binding rule inherited from the token catalogue holds absolutely here: **a button references
a token, never a literal.** No variant ships a raw hex, and the `default` variant's brass fill is one
`--qayd-accent` re-point away from a per-company white-label — that indirection is the entire reason the
`cva` matrix references `accent`/`ink`/`negative` utilities rather than Tailwind palette colors.

# Anatomy

A `Button` is one focusable control with, in inline order, an optional leading icon, a required text
label (except in the icon-only `size="icon"` form), and an optional trailing affordance (a chevron, a
`SplitButton` caret). Its internal layout is fixed:

```
┌──────────────────────────────────────────────┐
│  [leading icon]  Label text  [trailing icon]  │   inline-flex items-center justify-center gap-2
└──────────────────────────────────────────────┘
   16px Lucide      text-sm         16px Lucide
                    Inter 500
```

- **Container.** `inline-flex items-center justify-center gap-2 whitespace-nowrap`. The icon-to-label gap
  is `gap-2` (8px, `--space-xs`) — never a hand-tuned margin.
- **Radius.** `rounded-md` = `--radius-md` (6px) for every rectangular variant. A button is never
  `rounded-full`; the fully-rounded shape is reserved for pills/chips/avatars per
  [`../DESIGN_TOKENS.md → Radius`](../DESIGN_TOKENS.md).
- **Label.** `text-sm` (14px) Inter medium (`font-medium`). The label is a verb-object phrase and always
  an i18n key, never a literal string (governed by the application doc's `# i18n`).
- **Icons.** Lucide glyphs at 16px in `sm`/`default`, 20px in `lg`; a single icon set, never mixed with
  an emoji, per [`../README.md`](../README.md) restraint principle 8.
- **Elevation.** None. A button carries no shadow token — elevation on a button reads as heavy against
  QAYD's border-first surface model ([`../DESIGN_TOKENS.md → Elevation & Surfaces`](../DESIGN_TOKENS.md)).
- **Focus ring.** A 2px `accent` ring with a 2px surface-colored offset, part of the base class and never
  stripped (see [`# Accessibility`](#accessibility)).

Three structural invariants hold across every variant: **loading never changes width** (the spinner
replaces the leading icon and the label stays rendered, dimmed, so the control does not reflow); **color
is never the only signal** (a `destructive` button reads as destructive from its label and position, not
from red alone, per [`../COLOR_SYSTEM.md → Color never carries meaning alone`](../COLOR_SYSTEM.md)); and
the control is always a real `<button>` (or a real `<a>` under `asChild`), never a `<div onClick>`.

# Variants

`Button` declares its variants with `class-variance-authority`, as shadcn/ui ships and as
[`../DESIGN_TOKENS.md → Stage 4`](../DESIGN_TOKENS.md) establishes for the whole library. Seven variants:

| `variant` | Fill / border | Label token | Hover | Semantic role |
|---|---|---|---|---|
| `primary` | `bg-accent` (brass) | `text-accent-on` (near-black) | `bg-accent-strong` | The **one** primary action per surface |
| `secondary` | `bg-ink-3` | `text-ink-12` | `bg-ink-4` | An equal-permanence, lower-emphasis action |
| `outline` | transparent, `border-ink-7` | `text-ink-12` | `bg-ink-4` | A neutral interactive action that is not primary |
| `ghost` | transparent, no border | `text-ink-12` | `bg-ink-4` | The lowest-emphasis affordance (row/icon actions) |
| `destructive` | `bg-negative` | `text-white` | `bg-negative` + `opacity-90` | **Permanent, irreversible** deletion only |
| `destructive-quiet` | transparent, `border-negative/40` | `text-negative` | `bg-negative/5` | A **reversible / reversing** negative action |
| `link` | none, `h-auto p-0` | `text-accent` | underline | An inline text action, not a control |

Two variant decisions carry the platform's rules and must not be softened:

- **`primary` pairs brass with near-black, never white.** `bg-accent text-accent-on` — `accent-on` is
  `ink-12` (near-black). White on brass fails AA as text (~3.2:1); near-black clears it (~5.5:1), and
  engraved brass is a dark mark on a gold ground. This is the single most common button-color mistake,
  and it is a review-blocking error ([`../COLOR_SYSTEM.md → accent-on is near-black, never white`](../COLOR_SYSTEM.md)).
- **`destructive` vs. `destructive-quiet` is a data-permanence decision, not a loudness preference.**
  Void and Reject are **not** deletions — voiding a posted entry writes a reversing entry, rejecting an
  approval returns it with a reason. Neither destroys data, so both use the quiet, bordered
  `destructive-quiet`. The solid `bg-negative` `destructive` is reserved for the rare action that
  permanently removes something, and even then it is confirmed through an `AlertDialog` (see the
  application doc). The single accent is never spent on a button that is neither the primary action nor
  AI-touched — a "for interest" colored button is a defect ([`../COLOR_SYSTEM.md → Usage Rules`](../COLOR_SYSTEM.md)).

## Sizes

| `size` | Height | Padding | Label | Icon | Typical use |
|---|---|---|---|---|---|
| `sm` | `h-8` (32px) | `px-3` | `text-sm` | 16px | Row actions, dense toolbars |
| `default` | `h-9` (36px) | `px-4` | `text-sm` | 16px | The overwhelming majority of buttons |
| `lg` | `h-10` (40px) | `px-5` | `text-sm`/15px | 20px | Onboarding, auth, a marketing CTA |
| `icon` | `h-9 w-9` | `p-0` | — | 16px | Icon-only square control (see `IconButton` note) |

There is no `xs` size — a control under 32px tall is not a button in QAYD, it is a `link`. `sm` (32px) is
used only where a larger control would break a dense table's row height and still meets the WCAG 2.2
Target Size (Minimum) 24px floor with spacing.

## `cva` declaration (canonical tokens)

```tsx
// components/ui/button.variants.ts
import { cva, type VariantProps } from 'class-variance-authority';

export const buttonVariants = cva(
  // base: layout + radius + type + the never-stripped focus ring + both disabled models
  'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ' +
    'transition-colors motion-reduce:transition-none ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent ' +
    'focus-visible:ring-offset-2 focus-visible:ring-offset-ink-1 ' +
    'disabled:pointer-events-none disabled:opacity-50 ' +
    'aria-disabled:pointer-events-none aria-disabled:opacity-50',
  {
    variants: {
      variant: {
        primary: 'bg-accent text-accent-on hover:bg-accent-strong',
        secondary: 'bg-ink-3 text-ink-12 hover:bg-ink-4',
        outline: 'border border-ink-7 bg-transparent text-ink-12 hover:bg-ink-4',
        ghost: 'bg-transparent text-ink-12 hover:bg-ink-4',
        destructive: 'bg-negative text-white hover:opacity-90',
        'destructive-quiet':
          'border border-negative/40 bg-transparent text-negative hover:bg-negative/5',
        link: 'h-auto p-0 text-accent underline-offset-4 hover:underline',
      },
      size: {
        sm: 'h-8 px-3',
        default: 'h-9 px-4',
        lg: 'h-10 px-5',
        icon: 'h-9 w-9 p-0',
      },
    },
    defaultVariants: { variant: 'primary', size: 'default' },
  },
);

export type ButtonVariantProps = VariantProps<typeof buttonVariants>;
```

The base string covers both `disabled:` (native) and `aria-disabled:` (the explained/RBAC pattern) so a
permission-gated button that stays focusable to expose its tooltip still reads visually disabled — the
two disabled models are specified in [`# States`](#states).

## Implementation

```tsx
// components/ui/button.tsx
import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { buttonVariants, type ButtonVariantProps } from './button.variants';

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    ButtonVariantProps {
  asChild?: boolean;
  loading?: boolean;
  loadingLabel?: string;
}

export const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { className, variant, size, asChild = false, loading = false, loadingLabel, children, disabled, ...props },
  ref,
) {
  const Comp = asChild ? Slot : 'button';
  return (
    <Comp
      ref={ref}
      className={cn(buttonVariants({ variant, size }), className)}
      disabled={asChild ? undefined : disabled || loading}
      data-loading={loading || undefined}
      aria-busy={loading || undefined}
      {...props}
    >
      {loading && !asChild && (
        <Loader2 className="h-4 w-4 shrink-0 animate-spin motion-reduce:animate-none" aria-hidden />
      )}
      {loading && loadingLabel ? loadingLabel : children}
    </Comp>
  );
});
```

The spinner is `Loader2` at 16px with `motion-reduce:animate-none`: under `prefers-reduced-motion` the
glyph is present but static, so the pending state is still communicated (icon present, label dimmed,
`aria-busy` set) with only the rotation removed — the correct reading of the media query per
[`../DESIGN_TOKENS.md → Motion`](../DESIGN_TOKENS.md).

## IconButton note

`IconButton` is a thin wrapper over `Button size="icon"` whose sole reason to exist is to make the
accessible label **non-optional at the type level** — an icon-only control with no text is invisible to a
screen reader, so `IconButton` will not compile without a `label` (applied as both `aria-label` and the
`Tooltip` content). It defaults to `variant="ghost"` because icon buttons are almost always
low-emphasis. Its full API lives in the application doc; at the primitive level it is `Button size="icon"`
plus a mandatory name.

# Props / API

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `variant` | `'primary' \| 'secondary' \| 'outline' \| 'ghost' \| 'destructive' \| 'destructive-quiet' \| 'link'` | no | `'primary'` | See the variant table. |
| `size` | `'sm' \| 'default' \| 'lg' \| 'icon'` | no | `'default'` | See the size table. |
| `loading` | `boolean` | no | `false` | Renders the spinner, dims the label, sets `aria-busy`, and blocks interaction — **without changing width**. |
| `loadingLabel` | `string` | no | — | Optional i18n string shown in place of `children` while loading ("Posting…"); when omitted, the original label stays beside the spinner. |
| `asChild` | `boolean` | no | `false` | Renders the button styles onto a single child via Radix `Slot` (make a `next/link` `<a>` look and behave as a button while staying a real anchor). |
| `disabled` | `boolean` | no | `false` | Native disabled. For **state**-based blocking (a form invalid, a mutation in flight), never for RBAC — see States. |
| `type` | `'button' \| 'submit' \| 'reset'` | no | `'button'` | Defaults to `'button'` to avoid accidental form submits; `'submit'` inside a `<form>` makes a submit button. |
| `className` | `string` | no | — | Merged after the variant classes via `cn`; used for layout only (`w-full`, `justify-between`), never to override a token color. |
| …native props | `React.ButtonHTMLAttributes` | — | — | `onClick`, `form`, `formAction`, `name`, `value`, `aria-*` pass through. |

`VariantProps<typeof buttonVariants>` types `variant`/`size` off the `cva` matrix, so a value outside the
declared set is a compile error.

# States

Every state below is a real, testable rendering, not an aspiration.

| State | Trigger | Rendering |
|---|---|---|
| **Default** | Resting | The variant's base fill/border/label. No shadow. |
| **Hover** | Pointer over | The variant's `hover:` treatment, transitioned via `transition-colors` at `motion.fast` (160ms). Hover is never the only affordance. |
| **Focus-visible** | Keyboard focus | `focus-visible:ring-2 ring-accent ring-offset-2 ring-offset-ink-1` — a 2px brass ring meeting the ≥3:1 non-text target at every surface step. Pointer focus shows **no** ring (`:focus-visible`, not `:focus`). |
| **Active / pressed** | Pointer/`Space`/`Enter` down | `scale: 0.98` for `motion.micro` (120ms), `easeInOut` — the named "Button press" pattern. Collapses to no scale under reduced motion. |
| **Loading** | `loading` / a driving `isPending` | Spinner replaces the leading icon; label stays (dimmed) or swaps to `loadingLabel`; `aria-busy="true"`; activation blocked. Width unchanged. |
| **Disabled (state)** | `disabled` from business/form state | `opacity-50`, `pointer-events-none`, native `disabled` — **not** focusable, because the reason is already visible adjacent (a balance banner, a form error). Removed from tab order. |
| **Disabled (RBAC / explained)** | ungranted permission, shown not hidden | `aria-disabled="true"` (kept focusable), `opacity-50`, click intercepted, wrapped in a `Tooltip` naming the required key ("Requires `accounting.journal.post`"). Never a silently dead control. |

## The two disabled models

Conflating them is the most common button bug. **`disabled` (native)** is for *state*: a mutation in
flight, an unbalanced entry, a closed period — the reason is already on-screen, so the control need not be
focusable to explain itself, and it leaves the tab order. **`aria-disabled` (explained)** is for
*permission* when the design chose to show rather than hide the control: it stays in the tab order,
intercepts activation, and its wrapping `Tooltip` names the missing key. QAYD never leaves a user staring
at a grayed control with no explanation ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md),
disabled-with-tooltip clause). Whether a gated button is hidden (`<PermissionGate>`) or shown-disabled is
a per-screen decision owned by the application doc, never a primitive default.

There is deliberately no permanent "error" state: after a failed mutation the button returns to its
enabled/default state (ready to retry) and the error is surfaced by a toast or inline `ErrorState`, never
by recoloring the button red.

# Tokens Used

Every value resolves to a token from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md); no literal appears in
button source.

| Concern | Token(s) | Notes |
|---|---|---|
| Primary fill | `accent` (`#9C7A34` / `#D9B96C`) | `bg-accent`; hover `accent-strong` |
| Primary label | `accent-on` (`ink-12` / `#14130F`) | Near-black on brass; never white |
| Neutral fills | `ink-3` (secondary), `ink-4` (hover), transparent (ghost/outline) | Component-fill band |
| Neutral labels | `ink-12` | High-emphasis text |
| Borders | `ink-7` (outline) | Input/strong-divider band |
| Destructive fill | `negative` (`#B4232E` / `#F26B74`) | `text-white` clears AA (~5.8:1) |
| Destructive-quiet | `negative` text + `negative/40` border + `negative/5` wash | Opacity modifiers via `hsl(var(--x) / <alpha>)` |
| Link | `accent` text | `h-auto p-0`, underline on hover |
| Focus ring | `accent` + `ink-1` offset | `focus-visible:ring-2` |
| Radius | `radius-md` (6px) | `rounded-md` |
| Icon/label gap | `--space-xs` (8px) | `gap-2` |
| Spinner motion | `motion.micro`/`animate-spin` | `motion-reduce:animate-none` |
| Press motion | `motion.micro` 120ms, `easeInOut` | scale 0.98 |
| Hover transition | `motion.fast` 160ms | `transition-colors` |

Opacity-modified tokens (`negative/40`, `negative/5`) work because the semantic aliases are stored as
unitless HSL triplets consumed via `hsl(var(--x) / <alpha-value>)`
([`../DESIGN_TOKENS.md → Token Tiers`](../DESIGN_TOKENS.md)).

# Accessibility

- **Real elements only.** A `Button` is a `<button>`, or under `asChild` a real `<a>` — never a
  `<div onClick>`. This gives keyboard activation (`Enter`/`Space` on a button, `Enter` on an anchor),
  focusability, and the correct implicit role at no cost.
- **Icon-only buttons are always named.** `IconButton` makes `label` non-optional; a bare
  `<Button size="icon">` with no `aria-label` is a lint error, not a review nit.
- **Loading is announced.** `aria-busy="true"` during `loading`; the operation's *result* is announced
  through the app's live-region tiers (polite for success, assertive for a blocking failure), never by the
  button changing color.
- **Disabled explains itself.** RBAC/explained uses `aria-disabled` (focusable) plus a tooltip naming the
  permission; state-based uses native `disabled` only where the reason is already visible.
- **Focus indicator is never suppressed.** `focus-visible:ring-2 ring-accent ring-offset-2` is in the
  base `cva` string; removing it without a replacement ring is banned. It meets the ≥3:1 non-text contrast
  target on every surface in both themes ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).
- **Target size.** `default`/`lg` clear 36–40px; `sm` (32px) is used only in dense contexts and still
  meets the 24px minimum with spacing.
- **Motion respects the user.** Press-scale and spinner rotation collapse under `prefers-reduced-motion`;
  the button's *state* still changes, only the animation is removed.
- **Contrast, verified both themes.** `accent-on` on `accent` ≈ 5.5:1 (light) / 9.6:1 (dark); white on
  `negative` ≈ 5.8:1; `accent` link on `ink-1` ≈ 4.6:1 (light) / 9.0:1 (dark) — all pass AA.

# Theming, Dark Mode & RTL

**Theming.** Every button color is a QAYD semantic token (`bg-accent`, `text-ink-12`, `border-ink-7`,
`bg-negative`), mapped from the CSS variables via `tailwind.config.ts`
([`../DESIGN_TOKENS.md → Implementation`](../DESIGN_TOKENS.md)). A raw `bg-emerald-600` or `#9C7A34` in
button source is banned exactly as a raw hex is. A per-company white-label that re-points `--qayd-accent`
re-skins every `primary` button at once, because they all reference the same token — the entire reason for
the indirection.

**Dark mode.** The button set carries **no** `dark:` variants. Dark mode is a token remap under `.dark`
([`../DESIGN_TOKENS.md → Light / Dark Remap`](../DESIGN_TOKENS.md)): `accent` lifts to `#D9B96C` so a
`primary` button holds contrast against the dark canvas, `ink-3`/`ink-4` become the dark neutral fills,
`negative` shifts to `#F26B74`. Because the `cva` string references only semantic tokens, the identical
class list renders correctly in both themes — a `dark:bg-*` on a button is a bug. The accent gets
*lighter, not more saturated*, so it holds AA against `accent-on` rather than glowing.

**RTL.** Buttons mirror automatically because they hard-code no physical direction:

- **Logical spacing only.** The internal gap is `gap-2` (direction-agnostic); any asymmetric padding uses
  `ps-*`/`pe-*`, never `pl-*`/`pr-*`. A leading icon is inline-start, so in RTL it correctly moves to the
  right of the label with zero conditional code.
- **Directional icons flip, meaningful icons don't.** A caret/next-chevron mirrors via `rtl:rotate-180`
  (or the icon wrapper's flip rule); a `Check`, `Trash2`, or the spinner never flips.
- **Labels are the localized string**, rendered in IBM Plex Sans Arabic in the `ar` locale via the font
  stack's Arabic fallback. A button never contains a raw amount that would need its own `dir="ltr"` span —
  amounts live in `AmountCell`, not in button labels. Arabic labels commonly run longer than English;
  because buttons are `whitespace-nowrap` and width-flexible they grow to fit rather than truncate.

# Do / Don't

| Do | Don't |
|---|---|
| Reference `bg-accent`, `text-ink-12`, `border-ink-7` — tokens only | Write `bg-[#9C7A34]` or `bg-amber-600` in button source |
| Pair `primary` (`bg-accent`) with `text-accent-on` (near-black) | Put `text-white` on a brass fill — it fails AA |
| Reserve solid `destructive` (`bg-negative`) for permanent deletion | Use `destructive` for Void/Reject — those are `destructive-quiet` |
| Spend `primary`/brass on the one primary action per surface | Give a card its own brass "for interest" button |
| Keep the base focus ring intact | Add `focus:outline-none` without a replacement ring |
| Keep width stable in `loading` (label stays, spinner swaps the icon) | Swap the label for a spinner-only and let the button reflow |
| Let dark mode be the token remap | Add a `dark:bg-*` variant to a button |
| Use `aria-disabled` + tooltip for RBAC, native `disabled` for state | Ship a focusable-but-mute grayed button with no explanation |
| Use `ps-*`/`pe-*` and `gap-2` for internal spacing | Use `pl-*`/`pr-*` (breaks RTL mirroring) |
| Give every `size="icon"` button an accessible name via `IconButton` | Render a bare icon `<Button>` with no `aria-label` |

# Usage & Composition

At the primitive level a button is composed by choosing a variant + size and supplying a localized
verb-object label. Everything beyond that — the mutation model, confirmation dialogs, permission gating,
`SplitButton`/`ButtonGroup` — belongs to the application doc.

```tsx
// The one primary action on a surface.
<Button variant="primary" loading={post.isPending} loadingLabel={t('journalEntries.posting')}>
  <Check className="h-4 w-4" aria-hidden /> {t('journalEntries.post')}
</Button>

// A reversible negative action — quiet, not loud.
<Button variant="destructive-quiet" onClick={openRejectDialog}>
  {t('approvals.reject')}
</Button>

// Polymorphic: a link that looks and behaves like a button but stays a real anchor.
<Button asChild variant="outline">
  <Link href="/reports/trial-balance">{t('reports.openTrialBalance')}</Link>
</Button>

// Icon-only, named — the primitive form of IconButton.
<Button size="icon" variant="ghost" aria-label={t('common.delete')}>
  <Trash2 className="h-4 w-4" aria-hidden />
</Button>
```

**Composition contract.** One `primary` per surface region (the accent is rationed). Secondary
permanence sits beside it as `secondary`; neutral toolbar actions are `outline`; row/inline actions are
`ghost`. The `link` variant is text, not a control, and ignores `size` (it forces `h-auto p-0`).
`asChild` + `loading` is a documented no-op — a `Slot` has no element to inject a spinner into, so a
link-as-button that needs a pending state must render an actual `<button>`. For the finance compositions
(`SplitButton`, `ButtonGroup`, destructive `AlertDialog` flows, Server Action vs. `useMutation`) and the
RBAC hide-vs-show decision, see
[`../../frontend/components/BUTTONS.md`](../../frontend/components/BUTTONS.md).

# End of Document
