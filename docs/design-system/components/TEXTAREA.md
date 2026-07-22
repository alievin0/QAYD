# Textarea — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / TEXTAREA
---

# Purpose

This document is the atomic, token-level specification of QAYD's multiline `Textarea` primitive — the
control a user types a paragraph into: a journal entry's memo, a rejection reason, a customer note, an
adjustment justification. It defines the primitive in isolation: its anatomy, its auto-resize and
character-count behaviors, its variants and sizes, every field state, the resize-handle contract, the
exact tokens it references, its RTL/bilingual behavior, and its prop/API surface.

The `Textarea` is the sibling of the single-line [`./INPUT.md`](./INPUT.md) and shares its surface tokens
exactly — the same `ink-1` fill, `ink-7` border, `accent` focus ring, and `negative` invalid state — so a
memo field and a text field in the same form read as one system. It differs only in the ways multiline
entry requires: a `min-h`/`max-h` growth range, an optional auto-resize, an optional character counter,
and a deliberately constrained resize handle.

It is the design-system half of a pair with the application-integration catalogue,
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md), which lists `Textarea`
alongside the other controls and governs how it is wired into a field; the React Hook Form + Zod system
that owns its value and error is in
[`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md). Read this document for the
multiline surface itself; read those for the control catalogue and the form system. Where the application
docs use the pre-canonical draft token names (`ink-150`, `accent-600`, `danger`), this document uses the
**canonical** `ink-1…12` / brass / `negative` tokens from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md), which govern.

The primitive carries no business rule. A `max` character count is a fail-fast courtesy mirrored from the
schema; the server re-validates length unconditionally. A textarea formats nothing and computes nothing —
it captures free text and hands the raw string to the form.

# Anatomy

A `Textarea` is one editable multiline surface, optionally paired with a footer row carrying a character
counter and/or a helper affordance. In a form it is wrapped by `<FormControl>`, which owns its
`id`/`aria-invalid`/`aria-describedby`.

```
┌─────────────────────────────────────────────────────┐
│ editable multiline text …                            │   flex w-full rounded-md border
│ … wrapping to the field width, growing with content  │   bg-ink-1 min-h-[80px]
│                                                    ⌟ │   resize handle (block-only), ink-7
└─────────────────────────────────────────────────────┘
                                          142 / 500      optional counter, ink-9 → warning near cap
```

- **Surface.** `flex w-full rounded-md border bg-ink-1 text-ink-12 placeholder:text-ink-8`, radius
  `--radius-md` (6px) — identical surface tokens to `Input`.
- **Border.** `border-ink-7` at rest; `border-negative` when invalid.
- **Height range.** `min-h-[80px]` (roughly three text rows) by default, growing to a `max-h` ceiling
  after which the field scrolls internally. The min/max are props, so a compact note field and a long-memo
  field share one primitive.
- **Padding.** `px-3 py-2` — vertical padding is larger than a single-line input because the text stacks.
- **Text.** `text-md` (16px) Inter, `leading-relaxed` so wrapped lines are comfortable to read back.
- **Focus ring.** 2px `accent` ring, `focus-visible` only — part of the base class, never stripped.
- **Resize handle.** A single block-direction handle in the bottom inline-end corner (see
  [`# Resize handle`](#resize-handle)); `ink-7`, quiet, and never horizontal.
- **Counter (optional).** A right-aligned `ink-9` `text-xs` footer showing `used / max`, turning
  `warning` as it nears the cap and `negative` once over — always paired with the same limit enforced in
  the schema.

# Variants

The `Textarea` varies along the shared `invalid` flag and `size`, plus two multiline-specific booleans —
`autoResize` and `showCount` — that are behaviors rather than visual skins.

| Axis | Value | Effect |
|---|---|---|
| `invalid` | `false` / `true` | `border-ink-7` / `border-negative` + `focus-visible:ring-negative` + `aria-invalid` |
| `size` | `sm \| default \| lg` | Sets the text size and default `min-h` (`sm` ≈ 64px, `default` ≈ 80px, `lg` ≈ 120px) |
| `autoResize` | `boolean` | Grows the field to fit content up to `max-h`, then scrolls; off = a fixed `min-h` with the resize handle |
| `showCount` | `boolean` | Renders the `used / max` footer counter (requires `maxLength`) |

## `cva` declaration (canonical tokens)

```tsx
// components/ui/textarea.variants.ts
import { cva, type VariantProps } from 'class-variance-authority';

export const textareaVariants = cva(
  'flex w-full rounded-md border bg-ink-1 text-md leading-relaxed text-ink-12 placeholder:text-ink-8 ' +
    'px-3 py-2 transition-colors motion-reduce:transition-none ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 ' +
    'focus-visible:ring-offset-ink-1 ' +
    'disabled:cursor-not-allowed disabled:opacity-50 ' +
    'read-only:bg-ink-3 read-only:text-ink-10 ' +
    // resize is block-direction only, and disabled entirely under auto-resize
    'resize-y',
  {
    variants: {
      size: {
        sm: 'min-h-16 text-sm',
        default: 'min-h-20 text-md',
        lg: 'min-h-[7.5rem] text-md',
      },
      invalid: {
        true: 'border-negative focus-visible:ring-negative',
        false: 'border-ink-7',
      },
      autoResize: {
        true: 'resize-none overflow-hidden', // JS drives the height; the handle is removed
        false: '',
      },
    },
    defaultVariants: { size: 'default', invalid: false, autoResize: false },
  },
);

export type TextareaVariantProps = VariantProps<typeof textareaVariants>;
```

## Implementation

```tsx
// components/ui/textarea.tsx
import * as React from 'react';
import { cn } from '@/lib/utils';
import { textareaVariants, type TextareaVariantProps } from './textarea.variants';

export interface TextareaProps
  extends React.TextareaHTMLAttributes<HTMLTextAreaElement>,
    Omit<TextareaVariantProps, 'invalid' | 'autoResize'> {
  invalid?: boolean;
  autoResize?: boolean;
  showCount?: boolean;
  maxHeight?: number; // px ceiling before internal scroll (auto-resize)
}

export const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  { className, size, invalid, autoResize = false, showCount = false, maxHeight = 320, maxLength, value, onChange, ...props },
  ref,
) {
  const innerRef = React.useRef<HTMLTextAreaElement | null>(null);
  React.useImperativeHandle(ref, () => innerRef.current!, []);

  // Auto-resize: measure scrollHeight on each change, cap at maxHeight, then scroll.
  const fit = React.useCallback(() => {
    const el = innerRef.current;
    if (!el || !autoResize) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, maxHeight)}px`;
    el.style.overflowY = el.scrollHeight > maxHeight ? 'auto' : 'hidden';
  }, [autoResize, maxHeight]);

  React.useLayoutEffect(fit, [fit, value]);

  const count = typeof value === 'string' ? value.length : 0;

  return (
    <div className="space-y-1">
      <textarea
        ref={innerRef}
        aria-invalid={invalid || undefined}
        maxLength={maxLength}
        value={value}
        onChange={(e) => { onChange?.(e); fit(); }}
        className={cn(textareaVariants({ size, invalid, autoResize }), className)}
        {...props}
      />
      {showCount && maxLength != null && (
        <p
          className={cn(
            'text-end text-xs tabular-nums',
            count > maxLength ? 'text-negative' : count > maxLength * 0.9 ? 'text-warning' : 'text-ink-9',
          )}
          aria-live="polite"
        >
          {count} / {maxLength}
        </p>
      )}
    </div>
  );
});
```

## Auto-resize

`autoResize` grows the field to fit its content and no more, so a one-line note occupies one line and a
long memo expands smoothly — the calm alternative to a permanently-tall empty box or a tiny scrolling one.
The mechanism sets `height: auto` then `scrollHeight` (capped at `maxHeight`) on every change and on mount
(`useLayoutEffect`, so there is no post-paint jump). Above `maxHeight` the field stops growing and scrolls
internally. Under `autoResize` the manual resize handle is removed (`resize-none`), because the height is
now content-driven and a user drag would fight the measurement. Growth is a layout change, not an
animation, so it is exempt from motion tokens and needs no reduced-motion gate.

## Character count

`showCount` renders a `used / max` footer, right-aligned, `ink-9`, `tabular-nums`. It requires
`maxLength`, and the limit it displays is the *same* limit the schema enforces (`z.string().max(500)`), so
the counter never disagrees with validation. As the count crosses 90% of the cap the counter turns
`warning`; over the cap it turns `negative`. The footer is `aria-live="polite"` so a screen-reader user
hears the remaining budget update without it being read on every keystroke. The counter is an
informational courtesy — the authoritative length check is the schema's, re-validated by the server.

## Resize handle

When `autoResize` is off, the field carries a **single block-direction handle** (`resize-y`) in the
bottom inline-end corner. It is `resize-y`, never `resize` (both axes) or `resize-x`: a horizontal drag
would let a user pull the field wider than its column and break the form grid, and a memo never benefits
from being wider than its label's column. The handle is quiet (`ink-7`, the browser's native affordance
tinted by the border color) and honors the `max-h` ceiling. In RTL the handle sits in the bottom-left
corner automatically because the corner is inline-end.

# Props / API

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `size` | `'sm' \| 'default' \| 'lg'` | no | `'default'` | Sets text size and default `min-h` (≈64 / 80 / 120px). |
| `invalid` | `boolean` | no | `false` | `negative` border + `aria-invalid`; usually driven by `<FormControl>`. |
| `autoResize` | `boolean` | no | `false` | Grows to fit content up to `maxHeight`, then scrolls; removes the manual handle. |
| `showCount` | `boolean` | no | `false` | Renders the `used / max` footer counter; requires `maxLength`. |
| `maxLength` | `number` | no | — | Native cap; the same value the schema's `.max()` enforces. Drives the counter. |
| `maxHeight` | `number` (px) | no | `320` | Auto-resize ceiling before internal scroll. |
| `disabled` | `boolean` | no | `false` | Non-interactive, dimmed, out of tab order. |
| `readOnly` | `boolean` | no | `false` | Reachable, copyable, `ink-3` fill — for a posted/locked note shown read-only. |
| `value` / `onChange` | `string` / handler | — | — | Used controlled; the raw string is handed to the form. |
| `dir` | `'ltr' \| 'rtl'` | no | inherited | Follows the content's language (`dir="rtl" lang="ar"` for an Arabic memo). |
| `className` | `string` | no | — | Layout only; never a token-color override. |
| …native props | `React.TextareaHTMLAttributes` | — | — | `placeholder`, `rows`, `autoComplete`, `aria-*` pass through. |

# States

| State | Trigger | Rendering |
|---|---|---|
| **Default** | Resting, empty | `border-ink-7`, `ink-8` placeholder, `min-h` shown. |
| **Focus** | Keyboard/pointer focus | `focus-visible:ring-2 ring-accent ring-offset-2`; border color unchanged. |
| **Filled** | Content present | Same surface; under `autoResize` the height reflects the content. |
| **Invalid** | `invalid` / resolver / server `422` | `border-negative`, `focus-visible:ring-negative`, `aria-invalid`, `<FormMessage>` below; the counter turns `negative` if over cap. |
| **Near / over limit** | count > 90% / > 100% of `maxLength` | Counter turns `warning` / `negative`; the surface itself does not change until the field is actually invalid. |
| **Read-only** | `readOnly` | `bg-ink-3`, `ink-10` text, still selectable and announced; no resize handle. |
| **Disabled** | `disabled` | `opacity-50`, `cursor-not-allowed`, out of tab order. |
| **Loading** | async-populated | Replaced by a `Skeleton` at the field's `min-h` while an edit record loads or an AI-drafted memo streams in — the streamed value then lands editable, never auto-committed. |

# Tokens Used

| Concern | Token(s) | Notes |
|---|---|---|
| Surface fill | `ink-1` | `bg-ink-1` |
| Read-only fill | `ink-3` | `read-only:bg-ink-3` |
| Text | `ink-12` | Entered value, `leading-relaxed` |
| Read-only text | `ink-10` | Strong secondary |
| Placeholder | `ink-8` | Never the only label |
| Rest border / resize handle | `ink-7` | Input-border band |
| Invalid border/ring | `negative` | `border-negative` + `focus-visible:ring-negative` |
| Counter (normal / near / over) | `ink-9` / `warning` / `negative` | `text-xs tabular-nums` footer |
| Focus ring | `accent` + `ink-1` offset | `focus-visible:ring-2` |
| Radius | `radius-md` (6px) | `rounded-md` |
| Transition | `motion.fast` 160ms | `transition-colors`; growth is layout, not animation |

The counter is the one place `warning`/`negative` appear on this primitive, and each is paired with the
numeric `count / max` text and the `<FormMessage>` — never color alone, per
[`../COLOR_SYSTEM.md → Color never carries meaning alone`](../COLOR_SYSTEM.md).

# Accessibility

- **Programmatic label.** Comes from `<FormLabel>` → `htmlFor` → the field `id`; the placeholder is never
  the only label.
- **Invalid is wired.** `aria-invalid="true"` plus the `<FormMessage>` (`role="alert"`) linked via
  `aria-describedby`; the `negative` border is redundant with the message text.
- **The counter is announced, not nagging.** The `used / max` footer is `aria-live="polite"`, so remaining
  budget is available to AT without being read on every keystroke; the visible-vs-announced split keeps it
  from becoming noise.
- **Read-only stays reachable.** A `readOnly` memo is focusable, copyable, and announced; a locked note is
  `readOnly`, not `disabled`.
- **Resize is keyboard-independent.** Auto-resize needs no pointer; the manual handle is a pointer
  affordance only and never the sole way to see long content (the field scrolls). No functionality depends
  on dragging the handle.
- **Enter inserts a newline** — a `Textarea` never submits the form on `Enter` (unlike a single-line input
  in some forms), so a multi-paragraph memo is entered naturally; submission is reached by `Tab` to the
  primary action.
- **Contrast, both themes.** `ink-12` on `ink-1`, the `accent` ring, the `negative` invalid border, and
  the `warning`/`negative` counter all meet AA in light and dark
  ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).

# Theming, Dark Mode & RTL

**Theming.** Every color resolves to an `ink`/`accent`/`negative`/`warning` token; no `Textarea` file
carries a raw hex or a `dark:` variant against a raw color.

**Dark mode.** A token remap only — `ink-7` border, `accent` ring, `negative` invalid, `warning` counter
all resolve to their `.dark` values and keep AA against the dark canvas. No shadow to re-tune
(border-first).

**RTL & bilingual.**

- **Direction follows content.** An Arabic memo sets `dir="rtl" lang="ar"` so the caret starts at the
  right and letters shape and connect correctly; an English memo in the same Arabic-UI form keeps
  `dir="ltr"`. Direction follows the content's language, not the page.
- **The resize handle mirrors automatically** because it sits in the inline-end corner — bottom-right in
  LTR, bottom-left in RTL — with no conditional code.
- **The counter aligns to the end** (`text-end`), so it sits under the field's trailing edge in both
  directions, and its digits are `latn` `tabular-nums` — a count never renders in Eastern-Arabic digits.
- **Logical padding only.** `px-3 py-2` and any affordance offset use logical properties; physical
  `pl`/`pr`/`text-left`/`text-right` are banned in control source.

# Do / Don't

| Do | Don't |
|---|---|
| Reuse the `Input` surface tokens (`ink-1`, `ink-7`, `accent`, `negative`) | Invent a second textarea palette or a raw hex |
| Use `autoResize` for memos that vary a lot in length | Ship a permanently-tall empty box or a tiny scrolling one |
| Constrain the manual handle to `resize-y` | Allow `resize-x`/`resize` and let the field break the grid |
| Tie `showCount` to the same `maxLength` the schema enforces | Show a counter whose limit disagrees with validation |
| Turn the counter `warning`/`negative` near/over the cap, paired with text | Signal over-limit with color on the surface alone |
| Set `dir="rtl" lang="ar"` on an Arabic memo | Let an Arabic memo inherit the page direction and mis-cursor |
| Let `Enter` insert a newline | Submit the form on `Enter` inside a memo field |
| Use `readOnly` for a locked/posted note | Use `disabled` for a note the user must still read/copy |
| Let dark mode be the token remap | Add a `dark:border-*` variant to a textarea |

# Usage & Composition

At the primitive level, a textarea is composed inside a `<FormControl>` so the wrapper owns its ARIA and
RHF owns its value:

```tsx
// A required manual-entry memo with a live counter.
<FormField control={form.control} name="memo" render={({ field, fieldState }) => (
  <FormItem>
    <FormLabel>{t('journalEntries.memo')}</FormLabel>
    <FormControl>
      <Textarea {...field} autoResize showCount maxLength={500} invalid={!!fieldState.error} />
    </FormControl>
    <FormDescription>{t('journalEntries.memoHelp')}</FormDescription>
    <FormMessage />
  </FormItem>
)} />

// A mandatory rejection reason — quiet, fixed height, block-only resize.
<Textarea value={reason} onChange={(e) => setReason(e.target.value)}
          placeholder={t('approvals.reasonPlaceholder')} size="sm" />

// An Arabic memo in an English UI — direction follows the content.
<Textarea {...field} dir="rtl" lang="ar" />
```

**Composition contract.** The `invalid` prop comes from the wrapper; the label, description, and message
all come from the wrapper — the textarea never carries its own ARIA-error props. A mandatory
rejection/void reason is captured in a `Textarea` inside the confirming dialog, where the confirm button
stays disabled until the field is non-empty (the flow is owned by
[`../../frontend/components/BUTTONS.md`](../../frontend/components/BUTTONS.md) and the approval component).
An AI-drafted memo streams into a `Textarea` editable with its confidence affordance and posts only on the
human's submit ([`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md)). The
single-line sibling is [`./INPUT.md`](./INPUT.md); the control catalogue this primitive is listed in is
[`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md).

# End of Document
