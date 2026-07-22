# Accessibility — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: ACCESSIBILITY
---

# Purpose

This document is the accessibility specification for the QAYD **design system** — the tokens, primitives,
and shared component contracts that every screen is assembled from. It is the deeper, layer-below companion
to [`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md), which is the binding accessibility
contract at the *screen and app-shell* level. The two are one policy expressed at two altitudes:

- **`../frontend/ACCESSIBILITY.md` governs screens** — how a Trial Balance route, a Journal Entry form, or
  the AI Command Center is verified end-to-end, which flow is P0, what a release-blocking violation is.
- **This document governs the raw material those screens are built from** — the exact value of the focus
  ring token, why `accent-on` is near-black and not white, which Radix primitive owns which ARIA state,
  what a `ConfidenceMeter`'s shape channel must encode, and the per-primitive acceptance criteria a
  component must pass before any screen is allowed to compose it.

Where the two agree, they agree deliberately and this document restates the rule only to attach it to a
concrete token or primitive. Where a screen doc, a component doc, or this document appear to disagree on a
screen-level judgement, `../frontend/ACCESSIBILITY.md` wins; where they disagree on a token value, a
primitive's ARIA contract, or a contrast ratio, **this document wins**, because those are design-system
facts, not screen facts, and they are owned here.

Three properties of QAYD make this a stricter bar than a generic component library needs, each of which
this document keeps returning to:

1. **The primitives render load-bearing financial facts.** An `AmountCell`, a `DataTable` total, a
   `StatusBadge` on a posted entry — these are not decorative UI, they are the surface on which a licensed
   accountant independently verifies a number they are legally accountable for. An inaccessible primitive is
   a correctness defect of the same severity class as a miscalculated total, not a cosmetic bug.
2. **The AI layer is a first-class primitive surface, never exempt.** Confidence, reasoning, provenance, and
   the approve/reject affordance defined in [`../frontend/components/AI_WIDGETS.md`](../frontend/components/AI_WIDGETS.md)
   are real, keyboard-reachable, screen-reader-legible controls. "The AI produced it" is never a reason a
   control may be inaccessible; AI-authored primitives get *more* scrutiny, because the platform's own
   human-in-the-loop safety contract depends on a human actually perceiving the confidence and reasoning the
   AI is required to expose.
3. **Every primitive is verified in four permutations, not one.** A component is "accessible" only when it
   passes in `en`/LTR **and** `ar`/RTL, in light **and** dark. A primitive verified only in English light
   mode is, by this document's definition, unverified.

**Baseline.** WCAG 2.2 Level AA is the non-negotiable floor for 100% of design-system primitives, with zero
exceptions for "internal," "legacy," or "AI-generated." (WCAG 2.2 AA is a strict superset of 2.1 AA; the
design-system tokens were re-verified against both. Where a source component doc still cites the 2.1 AA
baseline, read it as the 2.2 AA floor this document holds.)

**The Radix rule, stated once and enforced everywhere below.** QAYD's primitives are shadcn/ui components
generated into `components/ui/` over Radix primitives, then restyled to the editorial design language of
[`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md). Radix supplies a correct, tested base of
focus trapping, ARIA roles/states, keyboard interaction, and dismissal. **That base is preserved, never
stripped, when restyling.** Restyling changes color, radius, spacing, and motion; it never removes a
`role`, a managed `aria-*` attribute, a focus scope, or a keyboard handler. A restyle that deletes any of
those is a P0 defect, not a style variation.

# Verification Baseline — How AA Is Proven, Not Assumed

## The four-permutation rule

Every primitive is verified in all four theme/direction permutations, because each surfaces a distinct,
non-overlapping class of defect:

| Permutation | Catches (that the others structurally cannot) |
|---|---|
| `en` / light | Baseline contrast, label association, focus order, ARIA correctness |
| `en` / dark | The dark ramp is its own calibrated scale (not an inversion) — a token that clears AA on white can fail on `ink-1` dark; the focus ring switches to `accent` dark to stay ≥3:1 |
| `ar` / light (RTL) | Logical-property mirroring, directional-icon flip, embedded-LTR-token bidi isolation, focus-ring clipping on the mirrored side |
| `ar` / dark (RTL) | The intersection — an RTL-only defect that only manifests against the dark ramp |

This is the same four-variant matrix the E2E suite enforces per screen
([`../testing/E2E_TESTS.md`](../testing/E2E_TESTS.md) → "The four-variant visual-regression rule"); at the
design-system layer it is enforced per *primitive*, through Storybook stories that render each of the four.

## Three verification layers

| Layer | Tooling | Scope | Gate |
|---|---|---|---|
| Automated (static) | `vitest` + `vitest-axe` (`toHaveNoViolations()`), `@storybook/addon-a11y` | Every primitive in `components/ui/` and `components/shared/`, rendered in isolation per state and per permutation | `serious`/`critical` axe violation blocks merge |
| Automated (integrated) | `@axe-core/playwright` across the four permutations | Every primitive as composed on a real route | Route-scoped on PR; full nightly crawl |
| Manual + assistive technology | Keyboard-only, NVDA/JAWS/VoiceOver/TalkBack, 200% zoom, reduced motion, contrast spot-check | The primitives on the P0 flows, in `en` and `ar` | Required before every release |

Automated tooling catches roughly a third of real WCAG failures by industry consensus — missing labels,
contrast, malformed ARIA. It **cannot** judge focus-order sense, whether a live-region announcement is
actually useful, or whether an RTL mirror is logically correct. The manual + AT layer exists precisely for
what axe cannot see, and no primitive is signed off on automated coverage alone. The AT support matrix
(NVDA + Chrome/Firefox on Windows 11; JAWS + Chrome on Windows 11; VoiceOver + Safari on macOS/iOS;
TalkBack + Chrome on Android) is inherited verbatim from
[`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "Supported assistive technology matrix" and
is not re-litigated here.

## Severity taxonomy (inherited)

The P0 / P1 / P2 severity taxonomy — P0 blocks release with no exception, P1 fixes within the sprint, P2 is
backlog — is defined in full in [`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "Severity
taxonomy" and applies unchanged to primitives. This document names a specific defect as P0/P1 only where the
primitive-level judgement is non-obvious (e.g. a `ConfidenceMeter` that encodes its band in color alone is
P0, because color-only confidence defeats the platform's own safety contract).

## The relationship to security testing

Two design-system accessibility rules are also security invariants, and are verified in
[`../testing/SECURITY_TESTS.md`](../testing/SECURITY_TESTS.md), not only in the a11y suite:

- **RBAC-hidden vs. RBAC-disabled.** A control a user has no permission for is genuinely absent from the DOM
  (not merely `aria-hidden`), so the permission matrix test and the accessibility tree agree: a screen
  reader cannot discover a capability the user cannot use. A control that is *present but disabled for a
  business-rule reason* carries an `aria-describedby` explanation. Conflating the two — leaking the existence
  of an unpermitted action to AT, or silently disabling with no reason — is both an a11y P1 and a security
  finding.
- **The accessible name never leaks unauthorized data.** A row-action `aria-label` that names an entry the
  user is not scoped to (cross-tenant) is caught by the tenant-isolation battery in
  [`../testing/SECURITY_TESTS.md`](../testing/SECURITY_TESTS.md), because an accessible name is a real data
  egress path, not a cosmetic string.

# Color & Contrast — At the Token Level

The design language ([`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md)) defines the palette
as a near-monochrome twelve-step warm-neutral ink scale, one rationed brass accent, and four semantic
financial colors. This section is the accessibility verification of those tokens: every pairing a primitive
is allowed to render, with its contrast ratio computed against its documented background using the WCAG 2.x
relative-luminance formula (`L = 0.2126R + 0.7152G + 0.0722B` on linearized channels;
`contrast = (L1 + 0.05) / (L2 + 0.05)`). No primitive may introduce a color pairing outside these tables
without a new row being added here first, ratio included.

## AA thresholds restated

- Normal text: **≥ 4.5:1** against its background.
- Large text (≥ 24px regular, or ≥ 19px / 14pt bold): **≥ 3:1**.
- Non-text UI (input/border glyphs that carry meaning, focus indicators, chart marks, the meter track):
  **≥ 3:1** against every adjacent color (WCAG 1.4.11).
- Disabled/inactive controls are exempt per the WCAG 1.4.3 note; QAYD still keeps disabled text legible
  (targeting ≈3:1) for usability, reinforced by a tooltip and `aria-disabled`, never by contrast alone.

## Text-on-canvas — light mode (canvas `ink-1` `#FAFAF9`)

| Token | Hex | Role | Contrast on `ink-1` | AA normal (≥4.5:1) | AA large / non-text (≥3:1) |
|---|---|---|---|---|---|
| `ink-12` | `#15130E` | Display text, near-black | ~18.9:1 | Pass | Pass |
| `ink-11` | `#2B2820` | Headings, high-emphasis body | ~13.6:1 | Pass | Pass |
| `ink-10` | `#4A4639` | Primary icon, strong secondary text | ~8.6:1 | Pass | Pass |
| `ink-9` | `#645F53` | Secondary text, default icon | ~5.4:1 | Pass | Pass |
| `ink-8` | `#878174` | Placeholder, **disabled only** | ~3.0:1 | Fail — disabled-exempt | Pass |
| `accent` | `#9C7A34` | Link / accent text on canvas | ~4.6:1 | Pass | Pass |
| `positive` | `#17794A` | Gain / reconciled text | ~4.7:1 | Pass | Pass |
| `negative` | `#B4232E` | Loss / overdue / destructive text | ~5.4:1 | Pass | Pass |
| `warning` | `#B45309` | Pending / unreconciled text | ~5.0:1 | Pass | Pass |

## Text-on-canvas — dark mode (canvas `ink-1` `#14130F`)

| Token | Hex | Role | Contrast on dark `ink-1` | AA normal (≥4.5:1) |
|---|---|---|---|---|
| `ink-12` | `#F8F6EF` | Display text, near-white | ~18.7:1 | Pass |
| `ink-11` | `#E4DEC9` | Headings, high-emphasis body | ~14.9:1 | Pass |
| `ink-9` | `#A39878` | Secondary text, default icon | ~6.2:1 | Pass |
| `accent` | `#D9B96C` | Link / accent text | ~9.6:1 | Pass |
| `positive` | `#4ADE94` | Gain / reconciled text | ~10.4:1 | Pass |
| `negative` | `#F26B74` | Loss / overdue text | ~6.6:1 | Pass |
| `warning` | `#F5A855` | Pending text | ~8.9:1 | Pass |

Dark mode is a **separately calibrated ramp, not an inversion** — this is why a token's dark value must be
verified independently. The relative-contrast intent (backgrounds stay warm-neutral, elevation gets
*lighter* not darker, the accent gets *lighter* not more saturated) is specified in
[`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md) → "Dark mode strategy" and in
[`../frontend/DARK_MODE.md`](../frontend/DARK_MODE.md); the accessibility requirement layered on top is that
**every dark token clears the same threshold as its light counterpart**, verified in the `dark` and
`dark-rtl` permutations, never assumed from the light pass.

## The `accent-on` rule — near-black on brass, never white

The single most important accent-contrast fact in the system: a mid-value warm brass does **not** clear
4.5:1 against white label text (roughly 3.2:1 at `accent` `#9C7A34` — passes only as a large/non-text
element), but comfortably clears it against near-black `accent-on` (`ink-12`, roughly 5.5:1). Therefore
**every filled `accent` surface pairs with `accent-on`, never with white** — the primary
`<Button variant="default">`, the active segmented-control tab, a selected chip. This is enforced at the
primitive level: the `Button` default variant hard-codes `text-accent-on`, and a variant that draws white
text on an `accent` fill fails the Storybook contrast story. It is also the more authentic material read —
engraved brass is a dark mark on a gold ground, not a white one.

## The focus-ring token

The focus indicator is a design-system token, not a per-component decision, and it satisfies both WCAG 2.4.7
(Focus Visible) and 1.4.11 (Non-text Contrast, ≥3:1 against every adjacent surface it can render over):

```css
/* tokens.css */
:root {
  --focus-ring-color: var(--qayd-accent);        /* #9C7A34 — ≥3:1 at every ink surface step, light */
  --focus-ring-width: 2px;
  --focus-ring-offset: 2px;
}
.dark {
  --focus-ring-color: var(--qayd-accent);        /* #D9B96C — the lighter dark-mode brass, ≥3:1 on dark ink */
}

:focus-visible {
  outline: var(--focus-ring-width) solid var(--focus-ring-color);
  outline-offset: var(--focus-ring-offset);
}
```

Three rules bind every primitive to this token:

1. **`:focus-visible`, never `:focus`.** A mouse click must not paint a ring on every button a sighted mouse
   user happens to click; a keyboard user must always see one. Radix and shadcn primitives already use
   `focus-visible`; a restyle never downgrades it to `:focus` or removes it.
2. **No `outline: none` without an adjacent replacement.** There is no `outline: none` anywhere in the
   codebase without an equally-visible replacement indicator in the same diff — a `focus-visible:ring-2
   focus-visible:ring-accent` or equivalent. A PR that removes the outline without replacing it does not
   merge.
3. **The ring is verified against every surface it can land on.** The `accent` ring is contrast-checked
   against `ink-1` through `ink-5` (canvas through selected-row fill) and against `accent-subtle` (an
   AI-proposal card it may focus a control inside), in both themes — because a focus ring that vanishes
   against the one surface a form happens to sit on is a real, permutation-specific defect.

## Confidence, status, and polarity are never color-only

This is the design-system expression of the platform's most accounting-specific accessibility rule.
Meaning is never carried by hue alone, at the primitive level:

- **`AmountCell` / `Amount`** carries a signed financial value through three redundant channels — a leading
  glyph (`−` for credit / a real `+`/`−` sign that survives copy-paste and grayscale print), a directional
  icon (distinct *shape*, not a colored dot), and a visually-hidden text equivalent ("Credit of KWD 50.000")
  — so removing any one channel (simulating color blindness, SR-only, monochrome export) still leaves the
  value unambiguous. Debit/credit are **never** colored red/green (a debit is not "bad," a credit is not
  "good" — revenue postings are credits); polarity color is opt-in and reserved for genuinely signed net
  metrics (Net Income, a variance), per [`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md) →
  "The debit/credit rule".
- **`StatusBadge`** requires both a `status` prop *and* a `label` string as required props with no default,
  so it is a TypeScript compile error to render a color-only badge — every badge pairs a color with a text
  label inside the same badge, never a bare colored dot.
- **`ConfidenceBadge` / `ConfidenceMeter`** encode the confidence band on three non-hue channels — a **fill
  level** (how many meter segments are solid), a **shape** (solid segments vs. a hollow/dashed track for the
  unfilled remainder), and a **real text label** ("High/Medium/Low confidence") — and QAYD deliberately does
  **not** color low confidence red or amber. Full banding in
  [`../frontend/components/AI_WIDGETS.md`](../frontend/components/AI_WIDGETS.md) → "The confidence-score band
  table"; the accessibility requirement is covered in `# AI-Affordance Accessibility` below.

The `--qayd-warning` token (`#B45309`) is the one hue in the system flagged for a text/non-text split: it
clears 3:1 for large text and non-text fills but must be verified per pairing when used as small text; a
mid-amber badge fill always pairs with dark ink text (the darker-on-lighter direction), never light text on
amber.

# Keyboard Interaction Model

QAYD's dense finance workbenches are keyboard-driven — a meaningful share of the target users (accountants
doing high-volume entry) are faster on a keyboard than a mouse and judge the product on this directly. Every
primitive is therefore keyboard-*primary*, not keyboard-*tolerated*.

## Tab order follows DOM order follows reading order

Tab order follows DOM order, and DOM order follows reading order, in both languages. A primitive **never**
reorders its DOM children or reverses an array to achieve a visual layout — visual order is a CSS-layer
concern (logical properties + `dir`), and reordering markup to "fix" a layout silently breaks Tab and SR
order while looking correct to a sighted mouse user. This is the single rule most likely to be violated by a
developer hand-fixing RTL; it is restated in `# RTL Accessibility` and is a lint-backed prohibition.

Within any primitive that lays out controls (a `DialogFooter`, a `Toolbar`, a form row), controls appear in
DOM order matching their visual top-to-bottom / start-to-end order, and CSS `order` / grid placement is
never used to move a focusable element visually without moving it in the DOM.

## Roving tabindex for composite widgets

Composite widgets — the ARIA `grid` (Journal Entry line editor, Bank Reconciliation matching grid), a
segmented control, a toolbar, a menu — expose a **single tab stop** to the outside and move an internal
roving focus with arrow keys, so the widget is one Tab stop from the page, not N. Exactly one descendant has
`tabIndex={0}` at any moment; every other has `tabIndex={-1}`; arrows move which one holds the `0`:

```tsx
// components/shared/use-roving-grid.ts — the canonical roving-tabindex pattern
function useRovingGrid(rowCount: number, colCount: number) {
  const [active, setActive] = useState({ row: 0, col: 0 });
  function onKeyDown(e: React.KeyboardEvent) {
    const deltas: Record<string, [number, number]> = {
      ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1],
    };
    const d = deltas[e.key];
    if (!d) return;
    e.preventDefault();
    setActive(({ row, col }) => ({
      row: clamp(row + d[0], 0, rowCount - 1),
      col: clamp(col + d[1], 0, colCount - 1),
    }));
  }
  return { active, onKeyDown, isActive: (r: number, c: number) => r === active.row && c === active.col };
}
```

The hook maps arrows to **DOM row/column indices**, not physical screen directions, which is exactly why
`←`/`→` keep working correctly under `dir="rtl"` — the DOM order does not change per locale, only its
painted direction does. A 40-line entry is thus traversable in a handful of arrow presses rather than 160+
Tab presses, which is the entire reason the heavier `grid` pattern exists (see `# Data-Dense Table
Accessibility`).

## No keyboard traps

No primitive traps the keyboard except a modal focus scope, which is an *intentional* trap Radix implements
correctly and which is always escapable with `Esc`. Specifically:

- A horizontally-scrollable region (a wide table wrapper) is a focusable, arrow-scrollable stop
  (`role="region"` + `aria-label` + `tabIndex={0}`) that **does not** trap `Tab` — `Tab` moves on to the
  next real control rather than requiring repeated presses to escape the table.
- A custom-key primitive (grid, menu, combobox) that intercepts arrows/`Enter`/`Space` always leaves `Tab`,
  `Shift+Tab`, and `Esc` free to leave it.
- A `contenteditable` or rich surface never swallows `Tab` as an editing key without an explicit,
  documented escape affordance.

## Shortcut layering

Global and scoped shortcuts (`Cmd/Ctrl+K` Command Palette, `/` focus search, `?` shortcuts help satisfying
WCAG 3.2.6 Consistent Help, `Esc` dismiss, `G`-chords for navigation, single-letter `A`/`X`/`N` card
actions, `Cmd/Ctrl+Enter` confirm, arrow-key grid movement) are specified in full in
[`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "Global keyboard shortcuts". The
design-system obligation is that single-letter shortcuts are suppressed whenever focus is inside a typing
target, checked centrally rather than per component:

```tsx
// hooks/use-global-shortcuts.ts
function isTypingTarget(el: Element | null) {
  if (!el) return false;
  const tag = el.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (el as HTMLElement).isContentEditable;
}
```

A card-scoped `A`-to-approve resolves against the live `document.activeElement`, never a cached "last focused
card" reference, so a real-time re-render (a higher-priority AI card streaming in) cannot cause a user to
approve a card they never intended to — see `# AI-Affordance Accessibility` and
[`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "Edge Cases".

## Skip links and landmark reach

The app shell renders a "Skip to main content" link (visually hidden until focused) as the first tab stop,
targeting the `<main tabIndex={-1}>` landmark. Every primitive that establishes a landmark region (`header`
`role="banner"`, `nav`, `main`, `footer` `role="contentinfo"`) renders exactly one of its type per page, and
a duplicated landmark type (a second in-page `<nav>` such as a report tab strip) always carries a
distinguishing `aria-label`. The full landmark skeleton lives in
[`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "Landmarks".

# Focus Management

Focus management is the discipline of deciding, at every state transition, exactly which element receives
focus next — because the browser's default answer (nothing, or `<body>`) is wrong for almost every
transition an SPA performs, and Next.js App Router client navigation makes it worse (no document reload means
no native focus reset).

## The visible focus ring is a token, not a per-component style

Covered under `# Color & Contrast → The focus-ring token`: one `--focus-ring-color` token, `:focus-visible`
only, no unpaired `outline: none`, verified against every surface in both themes. Restated here because focus
*visibility* and focus *management* are two halves of one contract — moving focus to an element that has no
visible ring is as broken as leaving focus nowhere.

## Focus trapping in modals and drawers

`Dialog`, `AlertDialog`, and `Sheet`/drawer are Radix primitives; Radix's `FocusScope` already implements a
correct focus trap and focus-return baseline that QAYD **preserves, never re-implements or strips**. The
design-system contract specifies only the parts Radix leaves to the consumer:

| Concern | Rule | Mechanism |
|---|---|---|
| Initial focus on open | Focus the first *logical* field, not merely the first DOM-focusable element, when a specific field is the obvious next action (the amount field in a "New Journal Line" dialog, not its close button) | `onOpenAutoFocus={(e) => { e.preventDefault(); fieldRef.current?.focus(); }}` |
| Focus return on close | Return focus to the trigger by default; Radix does this automatically while the trigger stays mounted | `onCloseAutoFocus` overridden only when the trigger was unmounted |
| Trigger removed by the action | If the action inside removes its own trigger (approving a row removes it from the list beneath), focus moves to a deliberate fallback — the next row's control, or a `tabIndex={-1}` container with a live-region announcement — never silently to `<body>` | `onCloseAutoFocus={(e) => { e.preventDefault(); fallbackRef.current?.focus(); }}` |
| Nested layers | A confirm/discard `AlertDialog` raised inside a `Sheet` owns the top focus scope; closing it returns focus *into* the drawer, not out to the page — each Radix `Root` manages its own scope, stacked, never a manually shared trap | Let them stack |
| Content behind | `aria-hidden` + inert, automatic via Radix's portal + sibling marking | No action; verified in the manual pass |

This table is the design-system reference implementation of
[`../frontend/components/MODALS.md`](../frontend/components/MODALS.md) → "Accessibility" and
[`../frontend/components/DRAWERS.md`](../frontend/components/DRAWERS.md) → "Accessibility". Two primitive-level
rules those docs make binding: **a titleless dialog/drawer is a lint-time failure** (`DialogTitle`/
`SheetTitle` wires the accessible name via `aria-labelledby`; a visually-hidden title is used when the design
shows no visible heading), and **a confirm button disabled by a precondition exposes its reason via
`aria-describedby`** (an empty rejection reason, an unsatisfied type-to-confirm token) — never a silently
disabled control.

## Toasts never steal focus

Toasts (Sonner) are **not** modal and must never move focus. A "Draft auto-saved" or "Entry JE-2026-00184
posted" toast announces via a live region without interrupting whatever the user is doing — critical,
because the most common moment a toast fires is while the user is still typing the next field. A toast that
calls `.focus()` on itself or its dismiss button on mount is a **P0** defect, not a style nit.

## Focus on route change

A shared route announcer, mounted once in the authenticated layout, does two things on every App Router
transition: it moves focus to the new page's `<h1>` (`tabIndex={-1}`), giving keyboard users a correct
starting point instead of a stale position, and it announces the new document title through a visually-hidden
`aria-live="polite"` region, giving SR users the "page changed" cue a full navigation would have provided
natively. Every route sets a distinct per-page `<title>` so the announcement is meaningful; a route with no
distinct title is a bug. Implementation in
[`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "Route changes".

# Screen-Reader Support

## Semantic HTML first, ARIA second

The primitives are built from real HTML elements — `<button>`, `<table>`, `<th scope>`, `<nav>`, `<main>`,
`<fieldset>`/`<legend>`, `<label htmlFor>` — and reach for ARIA only where no native element carries the
semantics (a `role="grid"` spreadsheet, a `role="status"` live region, a `role="article"` AI card). A
`<div onClick>` masquerading as a button is prohibited: it loses the role, the keyboard activation, and the
focusability a real `<button>` provides for free. `VisuallyHidden` is Radix's own primitive (re-exported
from `components/ui/visually-hidden.tsx`), preferred over a hand-rolled `.sr-only` class because it stays
correctly hidden from find-in-page and voice control.

## Roles and states by primitive

| Primitive | Role / pattern | Managed state |
|---|---|---|
| `Button` (icon-only) | native `<button>` | Requires a non-empty accessible name — `aria-label` or visually-hidden text; a bare icon button is a lint failure |
| `Dialog` / `AlertDialog` | `role="dialog"` / `alertdialog`, `aria-modal="true"` (Radix) | Labelled by `DialogTitle`, described by `DialogDescription` |
| `Sheet` (drawer) | `role="dialog"`, `aria-modal` per intent (a non-modal filter drawer sets `aria-modal="false"` and does not inert the background) | Labelled by `SheetTitle` |
| `DataTable` | real `<table>` + `<th scope="col|row">` | `aria-sort` on sortable headers; `aria-busy` on refetch |
| Editable grid | `role="grid"` / `row` / `gridcell`, `aria-rowcount`/`aria-colcount`/`aria-rowindex` | Roving `tabIndex` |
| `Tabs` / segmented control | Radix `Tabs` (`tablist`/`tab`/`tabpanel`) | `aria-selected`, roving `tabIndex` |
| `Accordion` / `Collapsible` | Radix (`aria-expanded` on trigger, `aria-controls` on region) | `aria-expanded` |
| `Select` / `Combobox` / Command Palette | Radix / `cmdk` (`combobox`/`listbox`/`option`, `aria-activedescendant`) | `aria-expanded`, `aria-activedescendant` |
| `Checkbox` (tri-state header) | Radix (`aria-checked="true|false|mixed"`) | `aria-checked="mixed"` for partial selection |
| Active nav link | native `<a>` | `aria-current="page"` from `usePathname()`, never a manually toggled class |
| AI Command Card | `role="article"`, `aria-labelledby` → headline | Confidence/reasoning as real text nodes |

These are the primitives' *managed* states — the ones Radix or the primitive owns and a restyle must
preserve. A screen composing a primitive never re-declares a role Radix already provides (which would
produce a double-role bug) and never removes one.

## Accessible names carry row/record context

An icon-only control inside a `.map()` over rows never ships a bare "Approve" — it carries a row-specific
accessible name: `aria-label={t('a11y.approve_entry', { number: entry.journalNumber })}` → "Approve journal
entry JE-2026-00184". Twenty-five identical "Approve, button" announcements with no way to tell which row is
one of the single most common real-world complaints about data-table-heavy enterprise software, and it is
preventable at the component-API level: the `IconButton`/row-action lint rule requires a non-empty,
row-specific `aria-label` template (not a static string) on every icon-only button rendered inside a row map.
This same rule is what makes the product usable under OS voice control ("Click Approve journal entry
JE-2026-00184"), so it is verified in the same manual rotation as screen readers.

## Live regions — the three-tier model

Every primitive that announces asynchronously resolves to exactly one of three tiers, so an engineer never
guesses which `aria-live` value a new notification needs:

| Tier | Attribute | Used for | Example |
|---|---|---|---|
| Assertive (interrupts) | `role="alert"` (implicit assertive) | Blocking errors that stop the current task | "Failed to post — fiscal period 2026-07 is locked." |
| Polite (queues) | `aria-live="polite"` | Non-blocking confirmations, background completions, realtime pushes | "Draft auto-saved." / "Entry JE-2026-00184 posted." |
| Status (continuous) | `role="status"`, `aria-live="polite"`, paired with `aria-busy` | Long-running or streaming operations | AI chat streaming; a report export running |

Two rules keep live regions from becoming the noise that makes a "technically compliant" region unusable in
practice: **streaming AI output is announced once at start (`aria-busy="true"`, "Pip is answering…") and
once at completion, never token-by-token**, and **realtime pushes never splice rows into a list a user is
mid-navigation through** — the correct pattern is a polite "3 new entries — Refresh" banner applied on
explicit user action. Both are specified in
[`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "Live regions" and "Edge Cases"; the
primitive-level obligation is that the AI streaming container and the realtime-aware list both consume the
shared live-region tier rather than wiring `aria-live` ad hoc.

## Arabic screen readers and bidi isolation

Every embedded LTR token inside Arabic text — a KWD amount, a reference code, an email, an ISO date — is
isolated with `unicode-bidi: isolate` so a mixed-direction string is not read out of sequence or with digits
reversed, a well-documented NVDA/JAWS/VoiceOver bidi glitch. This is baked into the `Amount`/`AmountCell`
primitive directly and into a shared `LtrInline` wrapper for prose, enforced by a component-library rule
rather than left to each screen:

```tsx
// components/shared/ltr-inline.tsx
export function LtrInline({ children }: { children: React.ReactNode }) {
  return <span dir="ltr" style={{ unicodeBidi: 'isolate' }}>{children}</span>;
}
```

Numerals stay Western Arabic digits (0–9) even in the `ar` locale — matching Gulf financial convention and
avoiding inconsistent Eastern-Arabic-Indic numeral pronunciation across Arabic SR voices — via
`Intl.NumberFormat`'s `numberingSystem: 'latn'`. See `# Internationalization` below.

# Data-Dense Table Accessibility

Financial tables — hundreds to tens of thousands of rows — are where table accessibility is most consequential
and most often broken. The design system provides exactly **two** table patterns, chosen deliberately per
table, never defaulted to the heavier one.

## Two patterns, chosen deliberately

1. **Plain accessible `<table>`** — for the large majority (Trial Balance, General Ledger list, Journal
   Entries list, Invoices, Bills, Customers, Vendors, Products) whose interaction is *read / sort / filter /
   paginate / click-row-to-navigate / click a row action*.
2. **ARIA `grid` pattern** — reserved for genuinely spreadsheet-like inline editing where a user expects
   arrow-key cell-to-cell navigation (Journal Entry line editor, Bank Reconciliation matching grid).

Applying `grid` to a plain display table is a documented anti-pattern: it adds `role`, `tabindex`, and
keyboard complexity that make the table *worse* for AT when cell-level navigation was never needed. Choosing
the heavier pattern "to be safe" is discouraged, not virtuous.

## Plain table — the primitive contract

- **Real `<table>`, never a styled `<div>` grid.** A `<div>` table forces re-implementing every semantic
  (`role="table"/"row"/"cell"`, `aria-rowindex`, header association) by hand and is reserved for the genuine
  `grid` case only. Real `<th scope="col">`/`<th scope="row">` association is also what lets a screen reader
  announce each amount cell with its column header for free — one more reason display tables are real markup.
- **A `<caption>`**, visually hidden when a visible heading already states the same thing immediately above,
  visible when the table sits in a generic card with no other heading. Abbreviated headers ("Dr"/"Cr")
  always carry a full-word `aria-label`.
- **Sortable headers are real `<button>`s inside the `<th>`**, with `aria-sort="ascending|descending|none"`
  on the `<th>`; the sort change announces via a visually-hidden `aria-live="polite"` region ("Sorted by
  date, descending"). Not a clickable `<th>` (no built-in interaction semantics), not a `<div>`.
- **Row-action accessible names are unique per row** (the lint-enforced rule above).
- **Loading is announced, not silently swapped.** `aria-busy` on refetch with existing rows kept in place
  (`placeholderData: keepPreviousData`, a thin top progress line), never a flash-to-empty; the empty state
  is a labelled region.
- **The horizontal-scroll wrapper is a focusable, labelled, non-trapping region** (`role="region"` +
  `aria-label` + `tabIndex={0}`) so a keyboard user can arrow-scroll a wide table without `Tab` being
  trapped.
- **Amounts always go through `AmountCell`** — no table-specific shortcut bypasses the debit/credit
  accessible-amount rules.

Full markup and the five demonstrating rules are in
[`../frontend/components/TABLES.md`](../frontend/components/TABLES.md) → "Accessibility" and
[`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "Data Tables Accessibility".

## Sort, pagination, expansion, bulk selection

- **Sort announce**: `aria-sort` on the `<th>` + a visually-hidden polite announcement of the new order.
- **Pagination**: a labelled `<nav>` with real `<button>`s (`aria-label` "Previous"/"Next", chevrons wrapped
  in `DirectionalIcon` so they point the visually-correct way in both directions) and a `role="status"`
  "Showing 1–100 of 4,213" live announcement of the page change.
- **Expandable rows**: `aria-expanded` on the trigger, `aria-controls` on the revealed row-group; expansion
  animation respects reduced motion.
- **Bulk selection**: a tri-state header checkbox (`aria-checked="mixed"` for partial), each row checkbox
  with an identity-bearing name ("Select journal entry JE-2026-00184"), and a `role="status"` announcement
  when the bulk-action toolbar appears ("5 entries selected — Approve, Reject, or Export").

## Virtualization and the screen reader

Tables expected to exceed ~200 rows are virtualized with `@tanstack/react-virtual` over
`@tanstack/react-table`, with `@tanstack/react-query` cursor pagination underneath. Virtualization removes
off-screen rows from the DOM, which has two accessibility consequences the primitive must handle:

- **`aria-rowcount` reflects the full logical row count, not the rendered window.** A screen reader announces
  "row 340 of 4,213" from `aria-rowcount`/`aria-rowindex`, so the user perceives the true table size even
  though only ~30 rows exist in the DOM at once. Each rendered `<tr>` carries its true `aria-rowindex`.
- **Table-scoped `Cmd/Ctrl+F` focuses the in-table filter, not the browser's native find** — because native
  find cannot see virtualized, off-screen rows, and a user searching a 4,000-line ledger with browser find
  would silently miss most of it. A "Load more" button is always provided as the non-scroll,
  keyboard-reachable way to advance, so infinite scroll is never the *only* path.

The editable `grid` pattern (roving tabindex, `aria-rowcount`/`aria-colcount`, arrow navigation that respects
RTL mirroring) is specified under `# Keyboard Interaction Model → Roving tabindex` and in full in
[`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "The ARIA grid pattern".

# Forms Accessibility

Forms are where accessibility is most often broken and most consequential — a mis-wired error is an error a
screen-reader user never hears. The design system provides the shadcn `Form` primitive set
(`FormField`/`FormItem`/`FormControl`/`FormLabel`/`FormDescription`/`FormMessage`) over Radix, which wires
the pieces most hand-rolled forms get wrong, so no field author writes ARIA by hand.

## Label association

Every field has a real, associated `<Label>` (`htmlFor` matching the input `id`) — **placeholder text is
never a label substitute**, because it disappears on first keystroke (losing the field's identity for a
magnifier or a distractible user) and most AT does not expose placeholder as an accessible name. `FormLabel`
wires `htmlFor` automatically; `FormControl` forwards `id`, `aria-describedby`, and `aria-invalid` onto its
single child input, which is why an `<Input>` inside a `FormControl` needs no ARIA props of its own.

## Invalid state, description, and error wiring

```tsx
<FormField control={form.control} name="amount" render={({ field }) => (
  <FormItem>
    <FormLabel>{t('journal_line.amount_label')}</FormLabel>
    <FormControl>
      <CurrencyInput {...field} currency={currency} />
    </FormControl>
    <FormDescription>{t('journal_line.amount_hint', { currency })}</FormDescription>
    <FormMessage /> {/* role="alert"; renders nothing when valid, announced text the instant Zod errors */}
  </FormItem>
)} />
```

- **Invalid** sets `aria-invalid="true"` on the control (via `FormControl`) and links `FormMessage` through
  `aria-describedby`.
- **Error text** is `FormMessage`, `role="alert"`, so a newly appearing error is announced *at the moment it
  appears*, not merely rendered as red text a user only meets if they happen to tab back. It is real text
  next to the field — never color-only, never a toast for a field-level error.
- **Helper text** is `FormDescription`, linked via `aria-describedby` alongside any error so both are
  announced.
- **Required fields** carry `aria-required="true"` and a visible non-color-only indicator (a text
  "(required)" or an asterisk with a form-level legend); neither the asterisk-with-no-legend nor
  `aria-required`-with-no-visible-cue ships alone.
- **Read-only vs. disabled**: server-computed fields (an entry's `journal_number`, a posted line) use
  `readOnly` (not `disabled`) so the value stays reachable and copyable by keyboard and SR; `disabled` is for
  genuinely inactive controls, and an RBAC-gated disable carries a tooltip naming the required permission.

Every Zod message is a translation key resolved through `t()`, never a hard-coded English string, so an
Arabic-locale user gets a fully Arabic, RTL-aligned, grammatically correct error, not an English string
dropped into an Arabic form.

## Error focus and announce on submit

On a failed submit the first invalid field receives focus (RHF `shouldFocusError` from the client resolver,
or `mapApiErrorsToForm`'s `shouldFocus: i === 0` from a server `422`), so a keyboard or SR user is never left
stranded. A server `422` maps its message back onto the exact RHF field it failed, producing the identical
visual and accessible state as a client-side failure — a user never experiences "client errors are announced
nicely, server errors silently fail" as two classes of behavior. For a long form (a Journal Entry line
editor routinely has a dozen-plus lines), a failed submit also moves focus to a form-level error-summary
region (`role="alert"`, `tabIndex={-1}`) listing every field error as a jump-link that scrolls to and
focuses the offending field — satisfying WCAG 3.3.1 (Error Identification) and 3.3.3 (Error Suggestion)
together. A live cross-field state (a balance/invariant banner) is `aria-live="polite"`, and a primary
action disabled by that invariant points `aria-describedby` at the banner so the *reason* is available to AT
— never a silently disabled Post button. Full patterns in
[`../frontend/components/FORMS.md`](../frontend/components/FORMS.md) → "Accessibility" and
[`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "The error summary pattern".

## Currency input, grouping, redundant entry, accessible auth

- `CurrencyInput` **formats on blur, not on keystroke** — injecting thousands separators mid-type causes
  caret-jump bugs far more disruptive for SR/voice users (no visible cursor to "just see" the jump) than for
  a mouse user; the raw numeric value is what Zod validates, the formatted string is presentation applied on
  blur. An always-present hint ("Amount in Kuwaiti Dinar, up to 3 decimal places") states constraints up
  front, not only after a failure.
- Related controls forming one choice (approval-routing tier, export-format options) are wrapped in a real
  `<fieldset>`/`<legend>`, not a `<div>` with a visual heading — a `<legend>` is programmatically associated
  with the group where a `<div>` heading is not.
- **Redundant Entry (WCAG 2.2 SC 3.3.7)**: information already captured in a flow is pre-populated and
  offered as a selectable choice, never re-prompted as a blank field.
- **Accessible Authentication (WCAG 2.2 SC 3.3.8)**: login never requires a transcription CAPTCHA as the
  only path; password fields never block paste (blocking paste breaks password managers — an a11y and
  security regression); 2FA one-time-code fields use `autoComplete="one-time-code"`.

# AI-Affordance Accessibility

The AI layer is where QAYD's accessibility bar is highest, because the platform's own human-in-the-loop
safety contract *depends* on a human being able to perceive and act on the confidence and reasoning the AI
is required to expose. Every rule here is drawn from and cross-references
[`../frontend/components/AI_WIDGETS.md`](../frontend/components/AI_WIDGETS.md).

## The AI-in-UI contract, restated as an accessibility contract

Every AI-originated number, suggestion, draft, or flag renders **both** its confidence and a reachable
explanation of its reasoning — no primitive shows a bare AI-computed value. In accessibility terms: the
confidence indicator has a real text equivalent, and the reasoning is reachable by keyboard and announced by
a screen reader before the user reaches the approve/reject control (`aria-describedby` on the action points
at the reasoning). "The AI is never silent" is an accessibility invariant, not only a product one.

## Confidence is never conveyed by color alone

The single rule most specific to this product's AI layer. QAYD deliberately does **not** use a traffic-light
red/amber/green for confidence — a debit/credit-style color mistake at the AI layer. Instead the band is
encoded on three independent, non-hue channels, so removing any one still leaves it legible:

| Channel | How the band is encoded |
|---|---|
| **Fill level** | How many of the `ConfidenceMeter`'s segments are solid (`≤ ⅓` for Low, `~⅔` for Medium, full for High) |
| **Shape** | Solid segments vs. a **hollow / dashed** outline track for the unfilled remainder — the band is legible from shape alone, before any color is perceived |
| **Text label** | A real "High / Medium / Low confidence" text node ("ثقة عالية / متوسطة / منخفضة" in Arabic), plus the numeric percentage — never inferred from a bar's `width` style alone |

A `ConfidenceBadge`/`ConfidenceMeter` that encodes the band in color alone (or in a bar width with no text
equivalent) is a **P0** defect under the severity taxonomy, because color-only confidence defeats the
platform's safety contract for exactly the users — color-blind, screen-reader — the redundancy exists to
protect. The full banding table (thresholds, tones, meter shapes, and the "Low confidence is withheld from
one-click execution" rule) is in
[`../frontend/components/AI_WIDGETS.md`](../frontend/components/AI_WIDGETS.md) → "The confidence-score band
table". Provenance in a table row follows the same rule: a small `accent` gutter dot marks an AI-proposed
row (not a colored row background), with a "Suggested" micro-label in the detail view and the confidence
available on keyboard-focusable hover as a tooltip — provenance visible until a human decides, then gone.

## Reasoning disclosure is a real, reachable control

The reasoning behind an AI proposal is exposed through a `ReasoningDisclosure`/`ReasoningPanel` that is a
real `aria-expanded` `<button>` toggling an `aria-controls` region — not a hover-only tooltip a keyboard
user cannot reach and a screen-reader user cannot open. Its short-form tooltip (on the `ConfidenceBadge`)
appears on **both hover and keyboard focus**, never hover alone. Source citations, where the agent provides
them, render inside the disclosure as a real list. The disclosure is collapsed by default so a confident
reviewer clearing a queue is not forced through it, but it is always one keyboard activation away.

## Approve / reject are reachable and announced

- The approve/reject/delegate actions on any AI proposal, Approval Center card, or Approval Drawer are real
  focusable `<button>`s in a logical order, never `<div onClick>`, never icon-only (always icon + visible
  text label).
- A decision button disabled by a precondition exposes *why* via `aria-describedby` — "no permission for
  this step," "not your step," or, for a one-click "Do it" gated below the auto-execute confidence
  threshold, the low-confidence reason — never a silently disabled control. This is the same disabled-reason
  rule as forms and modals, applied to the AI surface.
- The reject action's danger is carried by its verb and a mandatory-reason step, not by button color alone.
- An approve action mutation announces its result: on success a polite confirmation; on an **optimistic
  update that rolls back** (a `409 Conflict` because someone else already acted), an **assertive**
  announcement — "Could not approve — this entry was already approved by Khalid Marafie 2 minutes ago" —
  because a silent revert-the-pixel is invisible to a screen-reader user who has no way to know their action
  did not take. See [`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md) → "Edge Cases".

## AI streaming and the AI-unavailable state

A streaming AI response sets `role="status"`/`aria-busy="true"` **once** at start (announced "Pip is
answering…") and once at completion, with the full transcript available as static, navigable (non-live) text
underneath — never a token-by-token flood that reads as half-words in bursts. The `AiUnavailable` state
(the AI offline or a payload absent) is a real, labelled, keyboard-reachable region with text, not a blank
space or a dead spinner — the interface stays honest and operable when the AI is uncertain or offline. The
AI assistant is represented by a geometric mark, never a face, emoji, or mascot
([`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md) → "Imagery & Illustration stance"), which
also means there is no decorative image needing alt text — the mark carries an accessible name only where it
is itself a control.

# Motion & Reduced Motion

Motion in QAYD explains, never entertains — every animation answers a question (where did this panel come
from, what changed, is the system still working) in under ~300ms, and none of it bounces, confetti-bursts,
or spring-wobbles a financial state change. That restraint is also what makes reduced-motion support cheap:
there is little decorative motion to strip.

## `prefers-reduced-motion` is honored at the token layer

Motion is implemented with Framer Motion end to end (no ad-hoc CSS `transition` on interactive components),
so duration, easing, and reduced-motion handling stay centralized. A single `MotionProvider` reads
`useReducedMotion()` and every component reads its transition from the shared config rather than hard-coding
durations:

```tsx
// components/shared/motion-provider.tsx
export function MotionProvider({ children }: { children: React.ReactNode }) {
  const reduce = useReducedMotion();
  const transition = reduce ? { duration: 0.01 } : { duration: 0.2, ease: [0.16, 1, 0.3, 1] };
  return <MotionConfig transition={transition} reducedMotion={reduce ? 'always' : 'never'}>{children}</MotionConfig>;
}
```

A framework-independent CSS media query is the second safety net for anything outside Framer Motion's reach
(native `<details>`, `scroll-behavior`, a Radix-internal transition):

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

Durations use a duration `0.01ms` rather than a literal `0` because some `transitionend`/`animationend`
listeners never fire on a true `0` change.

## Reduced motion removes animation, never the state change

The correct interpretation of the media query: a user who set it still expects the UI to update, just without
the motion. So under reduced motion a Sheet still opens (instantly, no slide), a toast still appears (no
slide-in), a Dialog still enters (crossfade only), and a KPI count-up renders its final value immediately
rather than delaying for an animation to finish. Three categories, three rules:

| Category | Under reduced motion |
|---|---|
| Essential state-change motion (Sheet slide-in, Dialog entrance) | Kept, simplified to instant/near-instant crossfade — the change stays perceivable |
| Decorative motion (celebration, auto-play loops) | Fully disabled — communicates nothing a static state doesn't |
| Data-refresh motion (KPI count-up, chart line draw-in) | Disabled — final value renders immediately |

## No autoplay without control, no rapid flashing

Audio (a "read to me" briefing) is user-initiated only and never autoplays, satisfying WCAG 1.4.2 by
construction. Any status pulse (a critical Fraud Alert badge) runs well under the WCAG 2.3.1
three-flashes-per-second threshold (~1 cycle per 1.5s) and always ships a static, equally legible
alternative (a solid badge with the word "Critical"), so a reduced-motion user is left with visual weight,
not a pulse that merely stopped. The named-pattern catalog (button press `scale: 0.98`, row-entrance
`fadeUp` capped at 12 rows, the calm AI "thinking" dot loop, the 600ms realtime-row `accent-subtle` flash,
the quiet posted-entry checkmark with no confetti) lives in
[`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md) → "Motion"; each is verified to degrade
correctly under reduced motion.

# RTL Accessibility

Arabic is a first language in QAYD, designed and reviewed in Arabic before a screen is considered done — not
flipped with `dir="rtl"` at the end and eyeballed. RTL introduces a recurring, specific class of
accessibility defect that LTR testing structurally cannot catch, which is why it gets its own mandatory
verification pass.

## Mirroring is structural, never markup reordering

`dir="rtl"` is set once, on `<html>`, when `locale === 'ar'`, and every primitive is built from **logical
Tailwind utilities exclusively** — `ps-*`/`pe-*`, `ms-*`/`me-*`, `text-start`/`text-end`, `border-s`/
`border-e` — never physical `pl-`/`pr-`/`text-left`. This single discipline mirrors the entire app (sidebar
to the visual right, columns reading right-to-left, field icons on the visual left) with zero per-component
RTL branching. An ESLint rule flags physical-direction utilities in `components/` (outside a short, reviewed
allow-list of the intentionally-not-mirrored numeral-alignment cases) so the bug is caught before review.

**The one rule most likely to be violated by a well-intentioned developer**: never reorder DOM children or
reverse an array to make a layout look mirrored. CSS logical properties + `dir` already mirror the *visual*
presentation while leaving DOM order — and therefore Tab and screen-reader order — untouched. Reversing the
two buttons in a `[Cancel] [Save]` toolbar's render array to make Arabic read `[Save] [Cancel]` produces an
identical-looking result with silently reversed Tab order relative to English. Mirroring is a CSS-layer
concern only; fixing RTL visual order by reordering markup is prohibited.

## Directional icons flip; direction-neutral icons never do

| Icon type | Example | Flips under RTL? |
|---|---|---|
| Directional chevrons/arrows implying "back"/"forward" through a sequence | Breadcrumb separator, pagination chevron, "next step" arrow | **Yes** |
| Trend indicators where direction is a data fact | Revenue trend up-arrow, cash-flow trough down-arrow | **No** — up means increase regardless of reading direction |
| Symmetric / direction-neutral glyphs | Checkmark, bell, star, trash, the QAYD mark | **No** |
| `AmountCell` debit/credit directional icons | Inflow/outflow arrows | **No** — they encode money-direction (in vs. out), not reading-direction; a bilingual team reviewing the same ledger in two languages must see the same icon per transaction type |

The flip decision is made once, centrally, per icon *meaning*, through a single wrapper rather than ad hoc
`rtl:` utilities scattered per usage:

```tsx
// components/shared/directional-icon.tsx
export function DirectionalIcon({ icon: Icon, className, ...props }: { icon: LucideIcon } & IconProps) {
  return <Icon className={cn('rtl:-scale-x-100', className)} aria-hidden="true" {...props} />;
}
// Trend and AmountCell icons import Lucide directly, bypassing this wrapper, by design.
```

## Keyboard and shortcuts under RTL

Keyboard shortcuts keep their physical keys and are not remapped for RTL. In an editable grid, `←`/`→` still
move to the visually-next cell because the grid's *visual order* flipped (via logical properties) while the
roving-tabindex hook maps arrows to DOM row/column indices, which stay constant — it is the painting that
mirrored, not the key bindings. Small on-screen glyphs that *illustrate* a shortcut use `DirectionalIcon` so
the hint matches the mirrored UI.

## RTL gets its own mandatory pass

A primitive that passes every automated and manual check in `en`/LTR is **not** presumed to pass in
`ar`/RTL. Recurring RTL-only defect classes the LTR pass structurally cannot catch:

- A focus ring clipped by a parent's `overflow: hidden` because the ring now renders on the opposite side.
- A horizontally-scrolling wide table that scrolls the wrong direction on initial load, or produces a
  double/inverted scrollbar in some browser/OS combinations.
- A chevron hard-coded instead of using `DirectionalIcon`, now pointing the wrong way.
- Bidi reordering of an un-isolated embedded amount or code (see `# Internationalization`).

The `ar`/light and `ar`/dark permutations in the four-variant matrix are the enforcement point; the E2E
suite's `.light-rtl`/`.dark-rtl` visual baselines localize such a bug to exactly one variant
([`../testing/E2E_TESTS.md`](../testing/E2E_TESTS.md)).

# Touch-Target Sizing

WCAG 2.2 SC 2.5.8 sets a 24×24 CSS px minimum pointer target; QAYD's dense financial tables are exactly
where this bites, because row action icons are drawn at 16–20px for visual density. The **clickable box** is
a separate concern from the **glyph** and never shrinks below 24×24, implemented as shared tokens rather than
left to each component to remember:

```css
/* tokens.css */
:root {
  --tap-target-min: 24px;      /* WCAG 2.2 SC 2.5.8 floor — every icon-only control */
  --tap-target-primary: 44px;  /* QAYD internal bar for primary actions and any touch surface */
}
```

```tsx
// components/ui/icon-button.tsx
export function IconButton({ className, ...props }: React.ComponentProps<'button'>) {
  return (
    <button
      className={cn(
        'inline-flex items-center justify-center rounded-md',
        'min-h-[var(--tap-target-min)] min-w-[var(--tap-target-min)]', // hit area, not the glyph
        className,
      )}
      {...props}
    />
  );
}
```

The Lucide icon (16–20px) stays small for density; the invisible padding box around it satisfies 2.5.8.
Every row-action icon button in every table is built on this primitive — no screen hand-rolls a smaller tap
target for density's sake. On mobile and tablet surfaces, and for any primary action, the internal
`--tap-target-primary` 44px bar applies. QAYD also ships a non-drag alternative for every drag interaction
(WCAG 2.2 SC 2.5.7 Dragging Movements) — reordering a dashboard tile or a report widget offers up/down
buttons or a "Move to" menu, never drag-only.

# Internationalization

## `lang` and `dir` are set once, correctly

`<html lang={locale} dir={locale === 'ar' ? 'rtl' : 'ltr'}>` is the single source of both the document
language (so a screen reader selects the correct voice) and the base direction. No primitive re-declares
`lang` or `dir` except the deliberate, isolated `dir="ltr"` on numeral spans and `LtrInline` runs. A mixed
Arabic-character string inside an otherwise-English UI (a customer's Arabic trade name in a Latin-locale
list) still renders in IBM Plex Sans Arabic because it is the second fallback in both the display and text
font stacks — keeping mixed-script strings visually coherent with no per-string logic, and never falling
through to an illegible system Arabic font.

## Numerals stay Western in Arabic

Every financial figure uses `Intl.NumberFormat` with `numberingSystem: 'latn'` even in the `ar` locale:

```tsx
// lib/format-currency.ts
export function formatCurrency(amountMinor: number, currency: string, locale: 'en' | 'ar') {
  const decimals = currency === 'KWD' ? 3 : 2; // KWD/BHD/OMR use 3 decimals (fils)
  return new Intl.NumberFormat(locale === 'ar' ? 'ar-KW' : 'en-KW', {
    style: 'currency', currency, numberingSystem: 'latn',
    minimumFractionDigits: decimals, maximumFractionDigits: decimals,
  }).format(amountMinor / 10 ** decimals);
}
```

This is both a convention match (Gulf ledgers, invoices, and spreadsheets are written with Western digits)
and an accessibility fix (Eastern Arabic-Indic numeral pronunciation is inconsistent across NVDA/JAWS/
VoiceOver Arabic voices — an inconsistency QAYD does not expose its financial figures to). The KWD three-
decimal default is itself a correctness rule: a P&L that rounds `KD 1,204.500` to `KD 1,204.50` is a defect
a Kuwaiti finance team will not let pass review.

## Arabic is authored, not machine-translated

Every UI string, error message, and AI label exists as a parallel Arabic original written in professional
Gulf business register, held to the same tone brief as its English counterpart — not machine-translated and
adjusted. This is an accessibility concern as much as a quality one: a screen-reader user hearing a stiff,
over-translated, or grammatically wrong Arabic error is worse served than one hearing none. Correct
accounting terminology (مدين debit, دائن credit, ترحيل posting, قيد entry, ميزان المراجعة trial balance) is
used consistently. Arabic line height is taller at every type step (+0.15–0.2 over the Latin value) to give
connecting strokes and diacritics room, and negative letter-tracking is never applied to Arabic — details in
[`../frontend/DESIGN_LANGUAGE.md`](../frontend/DESIGN_LANGUAGE.md) → "Typography".

## Exported documents are their own i18n + a11y surface

A Trial Balance or Financial Statement exported to PDF ships as a **tagged PDF** — real heading structure,
real `/Table`/`/TH`/`/TD` tags, correct `Lang` and direction metadata — never a flattened image
rasterization, because a structureless export is unreadable by any AT the moment it leaves the browser. "The
on-screen version is accessible" does not satisfy this; the export pipeline is a binding, separate surface.

# The Acceptance Checklist

Every primitive, and every screen composing it, passes this before review. Each item traces to a section
above and to [`../frontend/ACCESSIBILITY.md`](../frontend/ACCESSIBILITY.md)'s screen-level checklist.

**Foundation**
- [ ] Verified in all four permutations: `en`/light, `en`/dark, `ar`/light (RTL), `ar`/dark (RTL).
- [ ] No `serious`/`critical` axe violation in any permutation (Storybook + Playwright).
- [ ] Radix base a11y (roles, states, focus scope, keyboard) is preserved, not stripped, by the restyle.

**Keyboard**
- [ ] Every interactive element reachable via `Tab`/`Shift+Tab` alone, in DOM order matching reading order.
- [ ] Composite widgets expose one tab stop and use roving `tabindex` for internal navigation.
- [ ] No keyboard trap except an intentional, `Esc`-escapable modal focus scope.
- [ ] Hover-only affordances also appear on `:focus-within`.

**Focus**
- [ ] Visible `:focus-visible` ring via the `--focus-ring-color` token; no unpaired `outline: none`.
- [ ] Dialogs/Sheets set a deliberate initial focus and correct return, including the trigger-removed fallback.
- [ ] Toasts never call `.focus()`; route changes focus the new `<h1>` and announce the title.

**Color & contrast**
- [ ] Every color pairing appears in the token table with a verified ratio (both themes) before merge.
- [ ] Filled `accent` surfaces pair with `accent-on` (near-black), never white.
- [ ] No signed amount, status, or confidence relies on color alone — glyph/shape + text always present.

**Screen readers & semantics**
- [ ] Real semantic HTML; icon-only controls carry a non-empty, context-bearing accessible name.
- [ ] Data tables are real `<table>` with `scope` headers; `aria-sort` announces on sort.
- [ ] Live-region tier (`alert` / `polite` / `status`+`aria-busy`) matches the three-tier model, not ad hoc.
- [ ] `aria-rowcount` reflects the full logical count on a virtualized table.

**Forms**
- [ ] Real associated `<Label>`; no placeholder-as-label; Zod messages are `t()` keys.
- [ ] `aria-invalid` + `aria-describedby` wired; `FormMessage` is `role="alert"`.
- [ ] Failed submit focuses the first error (and an error summary on long forms); `422` maps to the same fields.

**AI affordances**
- [ ] Confidence encoded on fill + shape + text; never color alone (color-only confidence is P0).
- [ ] Reasoning is a real `aria-expanded` disclosure reachable by keyboard; tooltip on hover *and* focus.
- [ ] Approve/reject/delegate are real focusable buttons; a disabled one exposes its reason via `aria-describedby`.
- [ ] Streaming announces once at start/end; `AiUnavailable` is a labelled, operable region.

**Motion, RTL, targets, i18n**
- [ ] Reduced motion removes animation, not state change; no autoplay, no >3 flashes/sec.
- [ ] Logical Tailwind utilities only; no DOM/array reorder to "fix" RTL; `DirectionalIcon` for directional glyphs.
- [ ] Every icon-only control ≥ 24×24 hit area; every drag has a non-drag alternative.
- [ ] Embedded LTR tokens isolated; numerals `latn` in `ar`; the screen is verified in `ar`, not assumed from `en`.

# End of Document
