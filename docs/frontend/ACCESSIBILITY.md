# Accessibility (WCAG 2.2 AA) — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: ACCESSIBILITY
---

# Purpose

This document is the single, binding accessibility contract for every pixel QAYD ships on the web. It
applies to the marketing-adjacent authenticated app shell, every screen documented in the platform's
screen specs (Dashboard, Accounting, General Ledger, Journal Entries, Trial Balance, Balance Sheet, P&L,
Cash Flow, Banking, Bank Reconciliation, AI Command Center, and every module screen that follows the same
structure), and every primitive in the shared component library (`frontend/components/ui`). A screen doc's
own `# Accessibility` section is not a separate standard — it is a pointer back to this document plus the
handful of screen-specific instances (which table needs the grid pattern, which AI card needs which live
region) that this document's patterns generate. When a screen doc and this document appear to disagree,
this document wins and the screen doc is wrong and must be corrected.

QAYD is an accounting product used by people who are legally and financially accountable for what the
software tells them, and it is increasingly operated through an AI layer that drafts, proposes, and
explains rather than simply displaying. That combination raises the accessibility bar above what a
typical SaaS dashboard needs, for three concrete reasons this document keeps returning to:

1. **The numbers are load-bearing.** A screen-reader user reviewing a Trial Balance, or a keyboard-only
   Finance Manager approving a bank transfer, is not browsing content — they are independently verifying
   a financial fact they may be legally responsible for. An inaccessible amount, an ambiguous debit/credit
   cue, or a silently-swallowed error is not a cosmetic bug in this product category; it is a correctness
   defect with the same severity class as a miscalculated total.
2. **The AI layer adds a whole new surface that must not be exempt.** Every AI proposal, confidence score,
   piece of reasoning, and approve/reject affordance described in `docs/ai/AI_COMMAND_CENTER.md` and the
   platform's AI Responsibilities rules is real UI, reachable by keyboard, readable by a screen reader, and
   never conveyed by color or animation alone. "The AI decided" is never an acceptable reason for a control
   to be inaccessible — if anything, AI-authored surfaces get *more* scrutiny, because the platform's own
   safety contract depends on a human being able to actually perceive and act on the confidence/reasoning
   the AI is required to expose.
3. **Arabic and RTL are not a localization afterthought bolted onto an English-first accessibility pass.**
   QAYD ships English and Arabic from the same components with `dir="rtl"` mirroring. A pattern that is
   only verified accessible in `en`/LTR is, by this document's definition, unverified — see
   `# RTL Accessibility` and `# Screen Readers` for the specific, recurring defects RTL introduces that a
   purely LTR-trained engineer will not think to check.

**Baseline.** WCAG 2.2 Level AA is the non-negotiable floor for 100% of shipped UI, with zero exceptions
carved out for "legacy," "internal tool," or "AI-generated" screens. A small set of flows — posting a
journal entry, approving a bank transfer or payroll release, submitting a tax return, and any other flow
gated by the platform's sensitive-operations rule — are held to a stricter internal bar than AA requires
(documented inline where relevant) because the cost of an accessibility failure on those specific flows is
not "annoying," it is "a licensed accountant could not verify a number they are attesting to."

**Enforcement.** This is not an aspirational document. It is backed by an automated CI gate
(`# Testing`), a mandatory manual test pass before every release, and a severity taxonomy
(`# Checklist`, `# Edge Cases`) that Product and Engineering both use to triage. Nothing in this document
is a "nice to have."

# Standards & Targets

## Conformance target

QAYD's frontend conforms to **WCAG 2.2, Level AA** (W3C Recommendation, October 2023), which is a strict
superset of WCAG 2.1 AA. QAYD does not maintain separate regional accessibility profiles: WCAG 2.2 AA is
adopted globally, as a single bar, because it is a strict superset of every accessibility regime QAYD is
likely to be measured against in its actual markets — the EU's EN 301 549 (relevant the moment any GCC
holding company QAYD serves has a European subsidiary or EU-domiciled auditor), the US's Section 508
(relevant to any US-facing partner integration or procurement questionnaire), and the accessibility
provisions referenced by Kuwait's Law No. 8/2010 on the rights of persons with disabilities and equivalent
frameworks tightening across Saudi Arabia and the UAE. Standardizing on one bar, set higher than any single
jurisdiction currently mandates, means QAYD never has to ask "which country's rules apply to this tenant"
before deciding whether a component is compliant.

## Where each WCAG principle is enforced

| Principle | Representative success criteria | Level | Primary enforcement point in QAYD |
|---|---|---|---|
| Perceivable | 1.1.1 Non-text Content, 1.3.1 Info and Relationships, 1.4.3 Contrast (Minimum), 1.4.11 Non-text Contrast | AA | `# Color & Contrast`, `# ARIA & Semantics`, design-token layer |
| Perceivable | 1.4.10 Reflow (no horizontal scroll below 320 CSS px width at 400% zoom) | AA | Responsive layout rules in every screen doc; verified in the 200%-zoom manual pass (`# Testing`) |
| Operable | 2.1.1 Keyboard, 2.1.2 No Keyboard Trap, 2.4.3 Focus Order, 2.4.7 Focus Visible | A/AA | `# Keyboard Navigation`, `# Focus Management` |
| Operable | 2.4.11 Focus Not Obscured (Minimum) — *new in 2.2* | AA | Sticky headers/toasts must never fully hide a focused control; enforced in `# Focus Management` |
| Operable | 2.5.7 Dragging Movements — *new in 2.2* | AA | Every drag interaction (e.g. reordering report widgets, resizing a dashboard tile) ships a non-drag alternative (up/down buttons, a "Move to" menu) |
| Operable | 2.5.8 Target Size (Minimum) — *new in 2.2* | AA | 24×24 CSS px minimum hit target on every control; QAYD's internal bar is 44×44 for primary/mobile actions (`# Standards & Targets → Target size`, below) |
| Understandable | 3.2.6 Consistent Help — *new in 2.2* | A | The `?` shortcut and the Command Palette's "Help" entry appear in the same relative position on every authenticated screen |
| Understandable | 3.3.1 Error Identification, 3.3.3 Error Suggestion, 3.3.7 Redundant Entry — *new in 2.2*, 3.3.8 Accessible Authentication (Minimum) — *new in 2.2* | A/AA | `# Forms Accessibility` |
| Robust | 4.1.2 Name, Role, Value, 4.1.3 Status Messages | AA | `# ARIA & Semantics`, live-region rules |

## Contrast targets (restated precisely — full derivation in `# Color & Contrast`)

- Normal text: **≥ 4.5:1** against its background.
- Large text (≥ 24px regular weight, or ≥ 19px / 14pt bold): **≥ 3:1**.
- Non-text UI components (input borders, icon glyphs that carry meaning, focus indicators, chart data
  marks): **≥ 3:1** against adjacent colors (WCAG 1.4.11).
- Disabled/inactive controls are exempt from the contrast requirement per the WCAG 1.4.3 note — but QAYD's
  internal bar still requires disabled text to be legible enough to read the label (targeting ≈3:1 in
  practice) purely for usability, not as a compliance requirement.

## Target size

WCAG 2.2's SC 2.5.8 sets a 24×24 CSS px minimum pointer target (with narrow exceptions for inline text
links and spacing-based exemptions). QAYD's dense financial tables are exactly the surface where this bites
hardest: a row's inline action icons (view / edit / approve / reject / delete) are frequently drawn at
16–20px for visual density, but the **clickable/tappable box** around each one is a separate concern from
the glyph size and must never shrink below 24×24 CSS px. This is implemented as a shared utility rather
than left to each component to remember:

```css
/* frontend/styles/tokens.css */
:root {
  --tap-target-min: 24px;      /* WCAG 2.2 SC 2.5.8 floor — every icon-only control */
  --tap-target-primary: 44px;  /* QAYD internal bar for primary actions & anything on a touch surface */
}
```

```tsx
// frontend/components/ui/icon-button.tsx
export function IconButton({ className, ...props }: React.ComponentProps<'button'>) {
  return (
    <button
      className={cn(
        'inline-flex items-center justify-center rounded-md text-ink-600 hover:bg-ink-50',
        'min-h-[var(--tap-target-min)] min-w-[var(--tap-target-min)]', // hit area, not the glyph
        className,
      )}
      {...props}
    />
  );
}
```

The visual icon (via Lucide, 16–20px inside this button) stays small for density; the invisible padding
box around it is what satisfies 2.5.8. Every row-action icon button in every table in the platform is
built on this primitive — no screen is permitted to hand-roll a smaller tap target for density's sake.

## Governance and ownership

| Stage | Owner | Responsibility |
|---|---|---|
| Design (Figma) | Design | Contrast-checks every new color pairing against the token table before handoff; specifies focus order and states (loading/empty/error/RTL/dark) for every new screen, per the screen-doc template |
| Implementation | Frontend Engineering | Semantic HTML and ARIA per this document; component-level automated tests (`# Testing`); no PR merges with a new "serious"/"critical" axe violation |
| Verification | QA | Manual keyboard, screen-reader, zoom, and reduced-motion passes before every release (`# Testing`) |
| Arbitration | Product + Engineering leads | Own the severity taxonomy below and the release-block decision when a defect is found late |

## Severity taxonomy

| Tier | Definition | Examples | Release policy |
|---|---|---|---|
| P0 | Blocks a user from completing a task with an assistive technology at all | Keyboard trap; missing form label on a required field; a financial amount with no accessible text equivalent; an approve/reject control unreachable by keyboard | **Blocks release.** No exceptions, no "ship and fix later." |
| P1 | Materially degrades the experience but a workaround exists | Ambiguous accessible name on a row action ("Approve, button" ×25 with no row context); a live region that under-announces a status change; a contrast pairing at 4.1:1 instead of 4.5:1 | Must fix within the current sprint; tracked, does not block the specific release unless it touches a P0-adjacent flow (posting, approval, reconciliation) |
| P2 | Minor, cosmetic, or affects a rarely-used path | A heading level that skips visually but not structurally; a slightly slow-to-appear focus ring transition | Backlog, burn-down tracked, no release gate |

# Keyboard Navigation (focus order, shortcuts, command palette)

Every interactive element in QAYD is operable with a keyboard alone, with no exceptions for "power user"
features — the Command Palette, the AI Approval Center, and the Journal Entry line editor are all keyboard
*primary* surfaces, not keyboard-tolerated ones, because a meaningful share of QAYD's own target users
(accountants doing high-volume data entry) are faster on a keyboard than a mouse and will judge the product
on this directly.

## Tab order

Tab order follows **DOM order**, and DOM order follows **reading order**, in both languages. In `en`/LTR
that reads left-to-right, top-to-bottom; in `ar`/RTL the exact same DOM order reads right-to-left,
top-to-bottom, because the layout mirrors via CSS logical properties and `dir`, not by reversing markup
(the mechanics are specified in full in `# RTL Accessibility` — this section only states the invariant:
**tab order is never manually re-ordered per locale**). Within the authenticated app shell, the order is:

```
1. Skip link ("Skip to main content", visually hidden until focused)
2. Primary navigation (sidebar): logo/home → nav sections → company switcher → user menu
3. Top bar: breadcrumb → global search / Command Palette trigger → notifications → AI status indicator
4. Main content landmark (<main>): page heading → page-level actions → body content in visual order
5. Footer (rarely focusable; version/status text only)
```

Inside `<main>`, a data table's row-level controls come after the row's own readable content in DOM order
(cells, then trailing action buttons), and a form's field order matches its visual top-to-bottom layout
exactly — visually reordering a field with CSS (`order`, grid placement) without moving it in the DOM is
prohibited, because it silently breaks this invariant for keyboard and screen-reader users while looking
correct to a sighted mouse user, which is precisely the kind of defect manual QA (`# Testing`) is built to
catch and automated tooling (`# Testing`) mostly cannot.

## Global keyboard shortcuts

| Shortcut | Action | Scope |
|---|---|---|
| `Cmd/Ctrl + K` | Open the Command Palette (search, navigate, run an action, "Ask AI") | Global |
| `/` | Focus the current page's primary search or filter field | Page, suppressed while any text input has focus |
| `?` | Open the Keyboard Shortcuts help dialog (satisfies WCAG 3.2.6 Consistent Help — same shortcut, same menu position, on every screen) | Global |
| `Esc` | Close the top-most dialog, sheet, popover, or the Command Palette; return focus to the trigger | Global |
| `G` then `D` | Go to Dashboard | Global (sequential chord, Linear-style) |
| `G` then `A` | Go to Accounting → Journal Entries | Global |
| `G` then `L` | Go to General Ledger | Global |
| `G` then `B` | Go to Banking | Global |
| `G` then `R` | Go to Reports | Global |
| `G` then `I` | Go to AI Command Center | Global |
| `N` | New (context-aware: New Journal Entry on the Journal Entries route, New Invoice on Sales, etc.) | Page-scoped |
| `A` | Approve the currently focused AI proposal or Approval Center card | Card-scoped, only active while a card has focus |
| `X` | Reject / dismiss the currently focused card | Card-scoped |
| `Cmd/Ctrl + Enter` | Confirm the primary action of the currently open dialog | Dialog-scoped |
| `Cmd/Ctrl + S` | Save the current draft (e.g. a Journal Entry line editor); `preventDefault()`s the browser's own Save dialog | Form-scoped |
| `↑ ↓ ← →` | Move the active cell within a data grid (Journal Entry line editor, Bank Reconciliation matching grid) | Grid-scoped only — see `# Data Tables Accessibility` |
| `Tab` / `Shift + Tab` | Move to the next / previous focusable control | Universal |

Single letter shortcuts (`A`, `X`, `N`) are only ever active when focus is **not** inside a text input,
textarea, or contenteditable region, checked centrally rather than per-component:

```tsx
// frontend/hooks/use-global-shortcuts.ts
function isTypingTarget(el: Element | null) {
  if (!el) return false;
  const tag = el.tagName;
  return (
    tag === 'INPUT' ||
    tag === 'TEXTAREA' ||
    tag === 'SELECT' ||
    (el as HTMLElement).isContentEditable
  );
}

export function useGlobalShortcuts() {
  useEffect(() => {
    let pendingChord: string | null = null;
    let chordTimer: ReturnType<typeof setTimeout>;

    function onKeyDown(e: KeyboardEvent) {
      if (isTypingTarget(document.activeElement)) return;

      if (e.key === 'g' || e.key === 'G') {
        pendingChord = 'g';
        clearTimeout(chordTimer);
        chordTimer = setTimeout(() => (pendingChord = null), 800); // chord window
        return;
      }
      if (pendingChord === 'g') {
        pendingChord = null;
        const dest = { d: '/dashboard', a: '/accounting/journal-entries', l: '/accounting/ledger',
                        b: '/banking', r: '/reports', i: '/ai' }[e.key.toLowerCase()];
        if (dest) { e.preventDefault(); router.push(dest); }
        return;
      }
      if (e.key === '?') { e.preventDefault(); openShortcutsHelp(); }
      if (e.key === '/') { e.preventDefault(); focusPrimarySearch(); }
    }

    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, []);
}
```

## Command Palette

The Command Palette (`frontend/components/shared/command-palette.tsx`) is built on shadcn/ui's `Command`
primitive (itself a wrapper over `cmdk`, which implements the ARIA combobox/listbox pattern). It is a
modal dialog (`role="dialog"`, `aria-modal="true"`, labelled by a visually-hidden title "Command Palette")
containing a text input (`role="combobox"`, `aria-expanded`, `aria-controls` pointing at the results list,
`aria-activedescendant` tracking the highlighted result) and a results list (`role="listbox"`, each result
`role="option"`), grouped under visually-and-programmatically labelled headings ("Navigate", "Actions",
"Ask AI", "Recent"):

```tsx
<CommandDialog open={open} onOpenChange={setOpen} aria-label={t('command_palette.title')}>
  <CommandInput placeholder={t('command_palette.placeholder')} aria-label={t('command_palette.title')} />
  <CommandList>
    <CommandEmpty>{t('command_palette.no_results')}</CommandEmpty>
    <CommandGroup heading={t('command_palette.group.navigate')}>
      {navItems.map((item) => (
        <CommandItem key={item.href} onSelect={() => router.push(item.href)}>
          <item.icon aria-hidden="true" className="me-2 h-4 w-4" />
          {item.label}
        </CommandItem>
      ))}
    </CommandGroup>
    <CommandGroup heading={t('command_palette.group.actions')}>
      {actions.filter((a) => hasPermission(a.permission)).map((a) => (
        <CommandItem key={a.id} onSelect={a.run}>{a.label}</CommandItem>
      ))}
    </CommandGroup>
  </CommandList>
</CommandDialog>
```

Two rules govern its content, both non-negotiable:

1. **RBAC filters at the data layer, not the render layer.** `actions.filter((a) => hasPermission(...))`
   runs before the list is ever constructed. A Sales Employee typing "reconcile" must get zero results,
   not a grayed-out "Reconcile Bank Account" entry — the platform-wide rule that AI and UI never reveal
   what a user cannot access (`docs/foundation/PERMISSION_SYSTEM.md`) applies exactly as strictly to the
   Command Palette's fuzzy search index as to a page route.
2. **Every result has a real accessible name.** Icon-only entries (e.g. a bare star for "favorite this
   report") never ship in the palette; every row is icon **plus** text, because `cmdk`'s fuzzy match itself
   depends on text content, and a screen-reader user depends on the same text to know what they are about
   to trigger.

## Focus-visible indicator

Every focusable element in QAYD renders a visible focus indicator that satisfies WCAG 2.4.7 (Focus Visible)
and 1.4.11 (Non-text Contrast, ≥3:1 against every adjacent color) using a shared token:

```css
:root {
  --focus-ring-color: var(--color-accent-600);       /* #0F7A5C — 5.3:1 on white, see Color & Contrast */
  --focus-ring-color-dark: var(--color-accent-400);  /* #34D399 — 10.0:1 on the dark surface */
  --focus-ring-width: 2px;
  --focus-ring-offset: 2px;
}

:focus-visible {
  outline: var(--focus-ring-width) solid var(--focus-ring-color);
  outline-offset: var(--focus-ring-offset);
}
.dark :focus-visible {
  outline-color: var(--focus-ring-color-dark);
}
```

QAYD uses `:focus-visible`, not `:focus`, specifically so a mouse click does not paint a ring around every
button a sighted mouse user happens to click (the well-known "focus ring pollution" complaint that leads
teams to suppress focus rings altogether with `outline: none` — which QAYD explicitly forbids anywhere in
the codebase; there is no `outline: none` without an immediately adjacent, equally visible replacement
indicator, and no PR may ship one without an accompanying focus-visible style in the same diff).

Hover-only affordances are prohibited: any control that appears on `:hover` (e.g. a table row's action
icons, a card's "dismiss" button) must appear identically on `:focus-within`, so a keyboard user tabbing
into a row sees the exact controls a mouse user sees on hover, in the same location, at the same time. This
is enforced structurally by never gating an action's visibility on `group-hover:` alone in Tailwind —
every such utility is paired with `group-focus-within:` in the same class list. See `# Data Tables
Accessibility` for the table-row-specific version of this rule.

# Focus Management (dialogs, sheets, route changes)

Focus management is the discipline of deciding, at every state transition, exactly which element receives
focus next — because the browser's default answer ("nothing, or `<body>`") is wrong for almost every
transition a modern app performs, and QAYD's Next.js App Router client-side navigation model makes this
worse by default (no full page load means no default browser focus reset at all).

## Dialogs, alert dialogs, and sheets

QAYD's `Dialog`, `AlertDialog`, and `Sheet` (drawer) components are Radix primitives wrapped by shadcn/ui,
and Radix already implements a correct, tested focus trap and focus-return baseline (`FocusScope`) — this
document does not re-implement that, it specifies the parts Radix leaves to the consumer:

| Concern | Rule | Radix hook |
|---|---|---|
| Initial focus on open | Focus the first logical field, not merely the first DOM-focusable element, when a specific field is the obvious next action (e.g. the amount field in a "New Journal Line" dialog, not its close button) | `onOpenAutoFocus={(e) => { e.preventDefault(); amountInputRef.current?.focus(); }}` |
| Focus return on close | Return focus to the element that opened the dialog (the trigger button) by default; Radix does this automatically as long as the trigger remains mounted | `onCloseAutoFocus` (override only when the trigger itself was removed — see next row) |
| Trigger removed while dialog was open | If the action inside the dialog causes its own trigger to disappear (e.g. approving a row inside a dialog removes that row from the list behind it), focus must move to a deliberate fallback — the next row's equivalent control, or the list's own `tabIndex={-1}` container with a live-region announcement — never silently to `<body>` | `onCloseAutoFocus={(e) => { e.preventDefault(); fallbackRef.current?.focus(); }}` |
| Nested dialogs (a Sheet opened from within a Dialog — e.g. "Add new customer" opened from the Journal Entry line editor) | The most-recently-opened layer owns the active focus trap; closing it returns focus to the layer beneath, not out to the page | Each Radix `Root` manages its own scope; do not manually manage a shared trap — let them stack |
| Scroll and content behind the dialog | Content behind an open modal is `aria-hidden` and inert to Tab, handled automatically by Radix's portal + `aria-hidden` sibling marking | No action needed, verified in `# Testing` |

```tsx
// frontend/components/accounting/new-journal-line-dialog.tsx
<Dialog open={open} onOpenChange={setOpen}>
  <DialogContent
    onOpenAutoFocus={(e) => {
      e.preventDefault();
      amountFieldRef.current?.focus();
    }}
    onCloseAutoFocus={(e) => {
      if (!triggerStillMounted.current) {
        e.preventDefault();
        lineListRef.current?.focus(); // tabIndex={-1} fallback container
      }
    }}
  >
    <DialogTitle>{t('journal_line.new_title')}</DialogTitle>
    <DialogDescription>{t('journal_line.new_description')}</DialogDescription>
    {/* form fields, amountFieldRef attached to the first one */}
  </DialogContent>
</Dialog>
```

## Route changes (App Router client-side navigation)

A Next.js App Router transition between, say, `/accounting/journal-entries` and
`/accounting/journal-entries/1024` does not reload the document, so the browser never resets focus and a
screen reader never announces anything happened — from an assistive-technology user's perspective, nothing
occurred, even though the entire page content changed. QAYD fixes this with a shared route announcer
mounted once in the root authenticated layout:

```tsx
// frontend/components/shared/route-announcer.tsx
'use client';
export function RouteAnnouncer() {
  const pathname = usePathname();
  const [message, setMessage] = useState('');

  useEffect(() => {
    // Defer to the next tick so the new <h1> is already painted before we move focus.
    const heading = document.querySelector('main h1');
    if (heading instanceof HTMLElement) {
      heading.setAttribute('tabindex', '-1');
      heading.focus();
    }
    setMessage(document.title);
  }, [pathname]);

  return (
    <div aria-live="polite" role="status" className="sr-only">
      {message}
    </div>
  );
}
```

Two effects fire on every route change: focus moves to the new page's `<h1>` (giving keyboard users an
immediate, correct starting point instead of a stale focus position from the previous page), and a
visually-hidden live region announces the new document title, giving screen-reader users the equivalent of
the "page changed" cue a full navigation would have provided natively. Every route in the App Router tree
sets a real per-page `<title>` via the `metadata` export specifically so this announcement is meaningful —
a route without a distinct title is treated as a bug.

## Toasts do not steal focus

Toasts (Sonner, the shadcn-recommended toast library) are **not** modal and must never move focus. A
Journal Entry line editor's "Draft auto-saved" toast, or an Approval Center's "Entry JE-2026-00184 posted"
confirmation, appears via `aria-live="polite"` (errors escalate to `role="alert"`, effectively
`aria-live="assertive"`) so it is announced to a screen-reader user without interrupting whatever they were
doing — critical, because the most common moment a toast fires is exactly while a user is still typing the
next field. A toast implementation that calls `.focus()` on itself, or on its dismiss button, on mount is
a P0 defect under this document's severity taxonomy, not a style nit.

# ARIA & Semantics (landmarks, roles, live regions for AI status/toasts)

## Landmarks

Every authenticated page renders exactly one of each primary landmark, from the root layout down:

```tsx
// frontend/app/(app)/layout.tsx
<body>
  <a href="#main-content" className="skip-link">{t('a11y.skip_to_content')}</a>
  <header role="banner">{/* top bar: breadcrumb, search, notifications, AI status */}</header>
  <nav aria-label={t('a11y.nav_primary')}>{/* sidebar */}</nav>
  <main id="main-content" tabIndex={-1}>
    <h1>{/* exactly one per route */}</h1>
    {children}
  </main>
  <footer role="contentinfo">{/* version/status only */}</footer>
</body>
```

`<main>` carries `tabIndex={-1}` specifically so the skip link (and the route announcer above) can move
focus into it programmatically without it being part of the normal Tab sequence. The sidebar's `<nav>` has
an explicit `aria-label` ("Primary") to distinguish it from any secondary in-page navigation (e.g. a
report's own tab strip), because a page with two unlabeled `<nav>` elements is ambiguous to a screen-reader
user's landmark list — QAYD never ships an unlabeled second landmark of the same type.

## Heading hierarchy

Exactly one `<h1>` per route (the page title, e.g. "Journal Entries"), sequential nesting below it with no
skipped levels, and every visually-headingless card or widget still carries a real (if visually hidden)
heading for its own region:

```tsx
<Card aria-labelledby="cash-flow-heading">
  <VisuallyHidden asChild><h2 id="cash-flow-heading">{t('dashboard.cash_flow_status')}</h2></VisuallyHidden>
  {/* visible content uses its own styled label, which may repeat the same text visibly */}
</Card>
```

`VisuallyHidden` is Radix's own primitive (re-exported from `frontend/components/ui/visually-hidden.tsx`),
preferred over a hand-rolled `.sr-only` class because it correctly stays hidden even from browser
find-in-page and voice control in the way a generic clip-based CSS class sometimes does not.

## Custom widget roles

| Widget | Role / pattern | Notes |
|---|---|---|
| AI Command Card (`docs/ai/AI_COMMAND_CENTER.md` "Command Card" envelope) | `role="article"`, `aria-labelledby` → the card's `headline` | Confidence and status are exposed as real text nodes, never encoded in color/icon alone — see next row and `# Color & Contrast` |
| Confidence badge (e.g. "92% confidence") | Visual pill is `aria-hidden="true"`; an adjacent real text node carries `{score}% {t('ai.confidence')}` | A screen-reader user must never have to infer confidence from a progress-bar's `width` style alone |
| AI "thinking" / streaming indicator (Ask AI, Pip-style chat) | `role="status"`, `aria-live="polite"`, `aria-busy="true"` on the response container while streaming, flipped to `false` on completion | Throttled — see "Live regions" below |
| Approve / Reject buttons on any AI proposal | Real `<button>` elements, never `<div onClick>`; `aria-describedby` points at the card's reasoning text so the "why" is read before the action is taken | Never a bare icon — always icon + visible text label |
| Data grid (Journal Entry line editor, Bank Reconciliation matching grid) | `role="grid"` / `role="row"` / `role="gridcell"`, `aria-rowcount`, `aria-colcount` | Full pattern in `# Data Tables Accessibility` |
| Sidebar collapsible section | `aria-expanded` on the trigger, `aria-controls` on the region it reveals | Standard Radix `Collapsible` / `Accordion` primitive, no custom implementation |
| Active nav link | `aria-current="page"` | Set from `usePathname()` comparison, not a manually-toggled class |

## Live regions

QAYD standardizes on exactly three live-region tiers, mapped to Sonner/toast severity and to the AI status
model, so engineers are never guessing which `aria-live` value a new notification needs:

| Tier | `aria-live` | Used for | Example |
|---|---|---|---|
| Assertive (interrupts) | `role="alert"` (implicit assertive) | Blocking errors that stop the current task | "Failed to post journal entry — fiscal period 2026-07 is locked." |
| Polite (queues) | `aria-live="polite"` | Non-blocking confirmations, background completions, realtime pushes from Reverb | "Draft auto-saved." / "Entry JE-2026-00184 posted by Khalid Marafie." |
| Status (continuous) | `role="status"`, `aria-live="polite"`, paired with `aria-busy` | Long-running or streaming operations | AI chat streaming a response; a report export running in the background |

Two rules prevent live regions from becoming noise, which is the single most common way a technically
"compliant" live region still makes a screen reader unusable in practice:

1. **AI streaming output is throttled, not announced token-by-token.** The Ask AI panel's response
   container sets `aria-busy="true"` the instant streaming starts (announced once: "Pip is answering…")
   and does **not** fire a fresh `aria-live` announcement on every incoming token. It announces once more,
   in full, when the stream completes and `aria-busy` flips to `false`. A user who wants to read along
   incrementally can do so visually or by navigating into the (non-live, static) transcript region below
   the streaming one — the live region's job is only to say "something is happening" and then "it's done,"
   never to narrate every word as it arrives.
2. **Realtime Reverb pushes never yank focus or silently mutate what a user is currently reading.** If
   another user posts a journal entry to a General Ledger table the Auditor is currently tabbing through,
   the correct behavior is a polite, non-disruptive banner — "3 new entries posted — Refresh" — not a live
   splice of new rows into the list the Auditor's screen reader has already indexed. See `# Edge Cases` for
   the full rationale and the TanStack Query cache pattern this implies.

## RBAC-aware disabled controls must explain themselves

A control disabled because the current user lacks a permission (e.g. a "Transfer Funds" button disabled
for a Sales Employee who lacks `bank.transfer`) is never a bare `disabled` attribute with no explanation.
Silence is not accessible — a sighted user can at least guess from context; a screen-reader user hears
only "Transfer Funds, button, dimmed" with no reason. QAYD's disabled-control pattern always pairs
`disabled` with an `aria-describedby` explanation, sourced from the permission system, not hand-written per
screen:

```tsx
<Tooltip>
  <TooltipTrigger asChild>
    <span> {/* wrapper needed because disabled buttons don't fire hover/focus events reliably */}
      <Button disabled={!canTransfer} aria-describedby={!canTransfer ? 'transfer-disabled-reason' : undefined}>
        {t('banking.transfer_funds')}
      </Button>
    </span>
  </TooltipTrigger>
  {!canTransfer && (
    <TooltipContent id="transfer-disabled-reason" role="tooltip">
      {t('a11y.permission_denied', { permission: 'bank.transfer', role: t('roles.finance_manager') })}
    </TooltipContent>
  )}
</Tooltip>
```

This is distinct from — and must never be confused with — a control disabled because of a **business-rule**
state (e.g. "Post" disabled because the entry does not balance). Both render as a visually-disabled button,
but the `aria-describedby` text is different and specific in each case ("You don't have permission to
transfer funds — this requires the Finance Manager role or above" vs. "This entry is off balance by KWD
0.500 — add a rounding line before posting"). Conflating the two into a generic "You can't do this right
now" is a P1 defect: it is technically announced, but not actionable. See `# Edge Cases` for the full rule.

# Color & Contrast (AA, debit/credit not color-only)

## Verified token table

QAYD's design system (`docs/foundation/DESIGN_SYSTEM.md`) names the color categories — Primary (Emerald
Green), Secondary (Neutral Gray), Success (Green), Warning (Orange), Error (Red), Info (Blue), Background
(White / Dark Gray) — without pinning exact hex values. This section is the accessibility-verified
implementation of those categories: the concrete CSS variables every component consumes, each with its
contrast ratio computed against its documented background using the WCAG 2.x relative-luminance formula
(`L = 0.2126R + 0.7152G + 0.0722B` on linearized channels; `contrast = (L1 + 0.05) / (L2 + 0.05)`). No
component may introduce a new color outside this table without a new row being added here first, ratio
included.

**Light mode** (background `--color-surface-0: #FFFFFF`):

| Token | Hex | Role | Verified contrast on white | AA text (≥4.5:1) | AA large/non-text (≥3:1) |
|---|---|---|---|---|---|
| `--color-ink-900` | `#111827` | Primary text, headings | 17.7:1 | Pass | Pass |
| `--color-ink-600` | `#4B5563` | Secondary / muted text, table sub-labels | 7.6:1 | Pass | Pass |
| `--color-accent-600` | `#0F7A5C` | Links, primary button fill (with white label), focus ring | 5.3:1 | Pass | Pass |
| `--color-error-600` | `#DC2626` | Error text, destructive-action labels | 4.83:1 | Pass | Pass |
| `--color-warning-700` | `#B45309` | Warning **text** | 5.02:1 | Pass | Pass |
| `--color-warning-500` | `#D97706` | Warning **non-text fills only** (badge background, icon fill) | 3.19:1 | **Fail — text-prohibited** | Pass |
| `--color-info-600` | `#2563EB` | Info text, informational badge text | 5.17:1 | Pass | Pass |

**Dark mode** (background `--color-surface-950: #0B0F0E`):

| Token | Hex | Role | Verified contrast on `surface-950` | AA text (≥4.5:1) |
|---|---|---|---|---|
| `--color-ink-50` | `#F3F5F4` | Primary text, headings | 17.6:1 | Pass |
| `--color-accent-400` | `#34D399` | Links, accent text, focus ring | 10.0:1 | Pass |
| `--color-error-400` | `#F87171` | Error text | 6.97:1 | Pass |
| `--color-warning-400` | `#FBBF24` | Warning text | 11.55:1 | Pass |
| `--color-info-400` | `#60A5FA` | Info text | 7.58:1 | Pass |

`--color-warning-500` is the one token in the system flagged **text-prohibited**: it clears the 3:1 bar for
large text and non-text UI (icon fills, badge backgrounds, chart marks) but falls short of 4.5:1 for normal
body text, so it is a compile-time lint rule (a custom ESLint rule over the Tailwind class list) rather
than a matter of developer discipline — `text-warning-500` on a light background fails CI; `bg-warning-500`
with `text-warning-900` (dark text on the mid-amber fill, a badge pattern) is the only sanctioned usage, and
is itself contrast-checked as its own pairing (dark ink text on `#D97706` clears 3:1 comfortably as the
darker-on-lighter direction is more forgiving; verified 4.6:1 using `--color-ink-900` on `#D97706`).

Disabled/inactive text is explicitly exempted from these minimums by WCAG's own 1.4.3 note, and QAYD uses a
dedicated `--color-ink-400` (`#9CA3AF`) for it — legible, deliberately lower-contrast, never used for
anything that is not actually disabled.

## Debit/credit and positive/negative are never color-only

This is the single most important rule in this section, because it is the one most specific to accounting
software and the one a generic SaaS accessibility checklist will not think to include. Roughly 1 in 12 men
have some form of red-green color vision deficiency; a debit/credit or positive/negative distinction
conveyed by red-vs-green text alone is, for that population, not merely harder to read — it is
**indistinguishable**, on a screen whose entire purpose is showing them which way money moved.

QAYD's `AmountCell` component (`frontend/components/shared/amount-cell.tsx`) is the single path every
screen uses to render a signed financial amount, and it never relies on color alone:

```tsx
type AmountCellProps = {
  amountMinor: number;        // in the smallest unit already scaled per currency decimals
  currency: string;            // ISO 4217, e.g. 'KWD'
  kind: 'debit' | 'credit' | 'neutral';
  locale: 'en' | 'ar';
};

export function AmountCell({ amountMinor, currency, kind, locale }: AmountCellProps) {
  const formatted = formatCurrency(amountMinor, currency, locale); // Intl.NumberFormat, see below
  const sign = kind === 'credit' ? '−' : '';                       // leading glyph, not color, carries sign
  const label =
    kind === 'debit'
      ? t('a11y.debit_amount', { amount: formatted })
      : kind === 'credit'
        ? t('a11y.credit_amount', { amount: formatted })
        : formatted;

  return (
    <span
      className={cn(
        'font-mono tabular-nums',
        kind === 'debit' && 'text-ink-900',
        kind === 'credit' && 'text-ink-900', // color reinforces, never carries, the distinction
      )}
    >
      <span aria-hidden="true">
        {kind === 'debit' && <ArrowUpRight className="inline size-3.5 me-1 text-accent-600" />}
        {kind === 'credit' && <ArrowDownLeft className="inline size-3.5 me-1 text-ink-600" />}
        {sign}{formatted}
      </span>
      <VisuallyHidden>{label}</VisuallyHidden>
    </span>
  );
}
```

Every signed amount in the product therefore carries **three** independent, redundant cues, so that
removing any one of them (simulating color blindness, screen-reader-only access, or a monochrome print
export) still leaves the value fully unambiguous:

1. A **leading glyph** (`−` for credit; nothing, or an explicit `+` where the context is genuinely
   ambiguous, for debit) that survives copy-paste into a spreadsheet and monochrome printing.
2. A **directional icon** (distinct shape, not just color) — an inflow/outflow arrow, never a colored dot
   alone.
3. A **text equivalent** exposed to assistive technology ("Debit of KWD 1,050.000" / "Credit of KWD
   50.000") that says the word "debit" or "credit" outright rather than expecting the listener to infer it
   from a symbol.

The same rule extends beyond individual cells:

- **Status badges** (`draft`, `pending_approval`, `posted`, `rejected`, `reversed`, from the Journal Entry
  lifecycle in `docs/accounting/JOURNAL_ENTRIES.md`) always pair a color with a text label inside the same
  badge — never a colored dot alone. A `StatusBadge` component enforces this by requiring both a `status`
  prop *and* a `label` string as required props with no default, so it is a TypeScript compile error to
  render a color-only badge.
- **Charts** (Cash Flow Status, Revenue/Expense Trends) use distinct line styles or direct data-point labels
  in addition to a categorical color palette, and every chart ships a "View as table" toggle that renders
  the exact same series as an accessible `<table>` — the chart is a supplementary visualization of data that
  always has a fully-accessible tabular form available, per the platform's dataviz accessibility baseline.
- **Budget variance and forecast trough indicators** (e.g. the Cash Flow Status trough at KWD 18,900 on
  2026-07-29) render an explicit numeric label and a text status word ("Warning") on the chart itself, not
  merely a color change in the line.

## Contrast is verified, not assumed, for every new pairing

Any new color pairing introduced by a feature — a new chart series color, a new badge variant — is checked
against the formula above (or an equivalent contrast-checker tool) and added to the token table before
merge; this is a CI-adjacent manual step during design review (`# Standards & Targets → Governance`) because
automated tooling (`# Testing`) catches *rendered* contrast violations but cannot stop a new token from
being invented in the first place without a human check at design time.

# Screen Readers (tables, forms, amounts, Arabic screen readers)

## Supported assistive technology matrix

QAYD is developed against, and manually tested on, the following combinations before every release that
touches a data-heavy or form-heavy screen:

| Screen reader | Browser | OS | Priority |
|---|---|---|---|
| NVDA | Chrome, Firefox | Windows 11 | Primary — largest share of QAYD's accountant/finance-manager user base |
| JAWS | Chrome | Windows 11 | Primary — common in enterprise/government procurement contexts QAYD targets for larger tenants |
| VoiceOver | Safari | macOS | Primary — engineering team's own daily-driver combination |
| VoiceOver | Safari | iOS | Secondary — mobile web usage (approvals on the go) |
| TalkBack | Chrome | Android | Secondary |

## Tables use real table semantics

Every display table (Trial Balance, General Ledger, Journal Entries list, Invoices, Bills, Bank
Reconciliation lines) is a real `<table>`, never a `<div>` grid styled to look like one — `<div>`-based
tables require re-implementing every piece of semantics (`role="table"`, `role="row"`, `role="cell"`,
`aria-rowindex`, `aria-colindex`) by hand and are reserved exclusively for the genuinely spreadsheet-like
inline-editable grids covered in `# Data Tables Accessibility`, where the ARIA `grid` pattern is the correct
choice. A plain list is not a grid; do not reach for the heavier pattern by default.

```tsx
<table>
  <caption className="sr-only">{t('trial_balance.table_caption', { period: 'July 2026' })}</caption>
  <thead>
    <tr>
      <th scope="col">{t('common.account_code')}</th>
      <th scope="col">{t('common.account_name')}</th>
      <th scope="col" aria-label={t('common.debit_full')}>{t('common.debit_abbr')}</th>
      <th scope="col" aria-label={t('common.credit_full')}>{t('common.credit_abbr')}</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <th scope="row">1120</th>
      <td>{t('accounts.ar_control')}</td>
      <td><AmountCell amountMinor={1050000} currency="KWD" kind="debit" locale={locale} /></td>
      <td>—</td>
    </tr>
  </tbody>
</table>
```

The `<caption>` is visually hidden only when a visible page/card heading already states the same thing
immediately above the table (avoiding duplicate announcement) — when a table sits inside a generic card
with no other heading, the caption is visible. Abbreviated headers ("Dr", "Cr") always carry a full-word
`aria-label` so a screen reader announces "Debit" rather than spelling out or mis-reading the abbreviation.

## Amounts must be read unambiguously

`Intl.NumberFormat` handles all currency formatting, with one deliberate, documented override: **numerals
stay Western Arabic digits (0–9) even in the `ar` locale**, because Kuwaiti and wider-Gulf financial
documents conventionally render figures in Western digits regardless of surrounding Arabic script, and
because screen-reader numeral pronunciation for Eastern Arabic-Indic digits (٠١٢٣٤٥٦٧٨٩) is inconsistent
across NVDA/JAWS/VoiceOver's Arabic voices in practice — an inconsistency QAYD does not want its financial
figures exposed to.

```tsx
// frontend/lib/format-currency.ts
export function formatCurrency(amountMinor: number, currency: string, locale: 'en' | 'ar') {
  const decimals = currency === 'KWD' ? 3 : 2; // KWD/BHD/OMR use 3 decimal places (fils)
  const amount = amountMinor / 10 ** decimals;
  return new Intl.NumberFormat(locale === 'ar' ? 'ar-KW' : 'en-KW', {
    style: 'currency',
    currency,
    numberingSystem: 'latn', // force Western digits even under the ar-KW locale
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(amount);
}
```

Negative amounts are never conveyed by a bare minus glyph with no textual reinforcement in contexts where
the sign carries real meaning (see `AmountCell` above); large magnitudes are never truncated into an
abbreviated form ("1.2M") without the exact figure remaining available to assistive technology (see
`# Edge Cases`); and every amount-bearing cell in a table is announced with its column header context
(native `<th scope="col">` association handles this automatically — this is one more reason display tables
must be real `<table>` markup and not a styled `<div>` grid).

## Forms and errors

Full detail in `# Forms Accessibility`; the screen-reader-specific requirement restated here is that a
validation error must be **announced at the moment it appears**, not merely rendered as red text a screen
reader will only encounter if the user happens to tab back to that exact field.

## Arabic screen readers and bidirectional text

VoiceOver and NVDA both support Arabic voices, and QAYD tests both, but mixed-direction content — an
Arabic sentence containing an embedded Latin-script token (a KWD amount, an email address, a product SKU,
an ISO date) — is a well-documented source of bidi reordering glitches where the embedded LTR run gets read
out of sequence or with digits reversed. QAYD isolates every embedded LTR token inside Arabic text with a
shared component rather than leaving it to ad hoc CSS on each screen:

```tsx
// frontend/components/shared/ltr-inline.tsx
export function LtrInline({ children }: { children: React.ReactNode }) {
  return (
    <span dir="ltr" style={{ unicodeBidi: 'isolate' }}>
      {children}
    </span>
  );
}
```

```tsx
// Arabic sentence with an embedded amount and a reference code:
<p>
  {t('invoice.paid_notice_prefix')} <LtrInline>{formatCurrency(1050000, 'KWD', 'ar')}</LtrInline>{' '}
  {t('invoice.paid_notice_suffix', { ref: undefined })} <LtrInline>INV-2026-00913</LtrInline>
</p>
```

Every currency amount, reference/invoice number, email address, and ISO-formatted date rendered inside
Arabic-locale copy goes through `LtrInline` (or the equivalent built directly into `AmountCell`, which
applies the same `unicode-bidi: isolate` internally) — this is enforced by a component-library rule, not
left to individual screens to remember, precisely because the failure mode (a screen reader mangling a
KWD figure's digit order inside an Arabic sentence) is subtle enough that a sighted developer testing only
visually will not notice it. Every release that touches a data table or an amount-heavy screen requires a
manual Arabic-locale screen-reader pass in addition to the English one — a component that passes an
automated or manual check in `en` is explicitly **not** assumed to pass in `ar` (see `# Testing` and
`# RTL Accessibility`).

# Forms Accessibility (labels, errors, RHF+Zod)

## Labels, never placeholders

Every field has a real, associated `<Label>` (shadcn's `Label`, wrapping Radix's `Label` primitive) with a
`htmlFor` matching the input's `id` — placeholder text is never used as a label substitute, both because
placeholder text disappears the instant a user starts typing (losing the field's identity for a screen
magnifier or a distractible user) and because most assistive technology does not reliably expose
placeholder text as an accessible name in the first place.

```tsx
<FormField
  control={form.control}
  name="amount"
  render={({ field }) => (
    <FormItem>
      <FormLabel>{t('journal_line.amount_label')}</FormLabel>
      <FormControl>
        <CurrencyInput {...field} currency={currency} aria-describedby="amount-hint amount-error" />
      </FormControl>
      <FormDescription id="amount-hint">{t('journal_line.amount_hint', { currency })}</FormDescription>
      <FormMessage id="amount-error" />
    </FormItem>
  )}
/>
```

## React Hook Form + Zod, wired for accessibility

shadcn's `Form` primitives (`FormField`/`FormItem`/`FormControl`/`FormLabel`/`FormDescription`/
`FormMessage`) already wire the pieces most implementations get wrong by hand: `FormControl` sets
`aria-invalid` and `aria-describedby` (pointing at both the description and the error message IDs) on the
underlying input automatically, and `FormMessage` renders nothing when there is no error and a `role="alert"`
text node the instant Zod produces one for that field. The schema itself carries the message, localized:

```tsx
// frontend/lib/schemas/journal-line.ts
export const journalLineSchema = (t: TFunction) =>
  z.object({
    accountId: z.coerce.number({ invalid_type_error: t('validation.account_required') }),
    debit: z.coerce.number().nonnegative(t('validation.amount_nonnegative')).optional(),
    credit: z.coerce.number().nonnegative(t('validation.amount_nonnegative')).optional(),
    costCenterId: z.coerce.number().optional(),
  }).refine(
    (line) => (line.debit ?? 0) > 0 !== (line.credit ?? 0) > 0,
    { message: t('validation.exactly_one_of_debit_credit'), path: ['debit'] },
  );
```

Every message is a translation key resolved through `t()`, never a hard-coded English string, per the
platform's localization rule — a Zod error surfaced to an Arabic-locale user is fully Arabic, RTL-aligned,
and grammatically correct, not an English string awkwardly dropped into an otherwise-Arabic form.

## Server errors map back onto the same fields

A `422` response from the Laravel API (the standard envelope's `errors[]` array) is mapped back onto the
exact RHF field it failed, using `form.setError`, so a server-side rejection (e.g. the Posting Engine's
independent balance re-validation catching something the client's own Zod check missed) produces the
identical visual and accessible error state as a client-side validation failure — a screen-reader user
never experiences "client errors are announced nicely, server errors just silently fail" as two different
classes of behavior:

```tsx
try {
  await postJournalEntry(payload);
} catch (err) {
  if (isApiValidationError(err)) {
    err.errors.forEach((e) => form.setError(mapApiFieldToFormField(e.field), { message: e.message }));
    errorSummaryRef.current?.focus(); // see below
  }
}
```

## The error summary pattern

A form with many fields — the Journal Entry line editor routinely has a dozen or more lines — cannot rely
on a user discovering a red border 40 rows down after clicking Submit. On a failed submit, focus moves to
a form-level error summary region that lists every field error as a link, satisfying WCAG 3.3.1 (Error
Identification) and 3.3.3 (Error Suggestion) together rather than the identification-only half most
implementations stop at:

```tsx
<div ref={errorSummaryRef} role="alert" tabIndex={-1} className="rounded-md border border-error-600 p-4">
  <h2 className="font-medium text-error-600">
    {t('form.error_summary_heading', { count: errorCount })}
  </h2>
  <ul>
    {Object.entries(form.formState.errors).map(([field, error]) => (
      <li key={field}>
        <button type="button" onClick={() => focusField(field)} className="underline">
          {t(`fields.${field}`)}: {error?.message as string}
        </button>
      </li>
    ))}
  </ul>
</div>
```

Each entry is a real button that both scrolls to and focuses the offending field, so "jump to the problem"
is a single activation for keyboard and screen-reader users alike, not a manual hunt.

## Required fields, redundant entry, and accessible authentication

- **Required-field convention.** A visible legend at the top of every form states `* {t('form.required_legend')}`,
  and every required field carries both the asterisk and `aria-required="true"` — an asterisk with no legend,
  or `aria-required` with no visible cue, are both incomplete on their own and neither ships alone.
- **Redundant Entry (WCAG 2.2 SC 3.3.7).** QAYD never makes a user re-type information the system already
  captured earlier in the same flow. If a customer was already selected on step 1 of a multi-step Sales
  Order flow, step 3's billing-address field is pre-populated from that customer's `customer_addresses` and
  offered as a selectable choice, not a blank field the user must fill in identically again; the same
  applies to re-entering a company's own base currency, which is never re-prompted once known.
- **Accessible Authentication (WCAG 2.2 SC 3.3.8).** Login (Laravel Sanctum) never requires solving a
  transcription-style cognitive test (a distorted-text CAPTCHA with no accessible alternative) as the only
  path to authenticate; password fields never block paste (blocking paste breaks password managers, which
  is itself an accessibility and security regression); one-time codes for 2FA use
  `autoComplete="one-time-code"` so they can be filled by the OS's own SMS/authenticator autofill rather
  than requiring manual transcription digit-by-digit.

## Currency and number inputs

`CurrencyInput` (`frontend/components/shared/currency-input.tsx`) is the single component every
amount-entry field in the platform uses, and it deliberately **formats on blur, not on keystroke**:
injecting thousands separators while the user is actively typing is a well-known source of caret-jump bugs
that are far more disruptive for screen-reader and voice-control users (who do not have a visual cursor to
"just see" where the caret jumped to) than for a sighted mouse user who barely notices. The raw numeric
value is what RHF/Zod ever validates against; the formatted, currency-symbol-bearing string is a
presentation layer applied only once the field loses focus:

```tsx
export function CurrencyInput({ value, onChange, currency, ...props }: CurrencyInputProps) {
  const [display, setDisplay] = useState(() => String(value ?? ''));
  const [focused, setFocused] = useState(false);

  return (
    <input
      {...props}
      inputMode="decimal"
      aria-describedby={cn(props['aria-describedby'], 'currency-hint')}
      value={focused ? display : formatCurrency(value, currency, locale)}
      onFocus={() => setFocused(true)}
      onBlur={() => { setFocused(false); onChange(parseNumeric(display)); }}
      onChange={(e) => setDisplay(e.target.value)}
    />
  );
}
```

An adjacent, always-present `#currency-hint` text ("Amount in Kuwaiti Dinar, up to 3 decimal places")
tells a screen-reader user the field's constraints up front rather than only after a validation failure.

## Grouping related controls

Radio/checkbox clusters that form one logical choice (e.g. selecting an approval-routing tier, or a set of
report-export format options) are wrapped in a real `<fieldset>` with a `<legend>`, not a `<div>` with a
visual heading above it — a `<div>` heading is not programmatically associated with the group the way a
`<legend>` is, and a screen reader announcing each radio option in isolation without its group's legend
("Tier 1... Tier 2... Tier 3...") is meaningfully worse than one that prefixes each with the group name.

# Motion & reduced-motion

## `prefers-reduced-motion` is honored everywhere, not just on the marketing site

QAYD's marketing/landing surface already gates its parallax and scroll-reveal effects behind
`prefers-reduced-motion`; this document extends the same discipline, with a stricter default, into the
authenticated product itself — where QAYD's own design taste already calls for calm, purposeful
micro-motion rather than decoration, so there is little to strip out in the first place, but what remains
must still degrade correctly for users who have told their OS they want less of it.

A single Framer Motion configuration wraps the authenticated app and every component reads its transition
values from it rather than hard-coding durations inline:

```tsx
// frontend/components/shared/motion-provider.tsx
'use client';
export function MotionProvider({ children }: { children: React.ReactNode }) {
  const shouldReduceMotion = useReducedMotion(); // Framer Motion's own media-query hook

  const transition = shouldReduceMotion
    ? { duration: 0.01 } // not literally 0 — some transition-end listeners never fire on a true 0ms change
    : { duration: 0.2, ease: [0.4, 0, 0.2, 1] };

  return (
    <MotionConfig transition={transition} reducedMotion={shouldReduceMotion ? 'always' : 'never'}>
      {children}
    </MotionConfig>
  );
}
```

Framer Motion's own `reducedMotion="user"` mode (or the explicit `"always"` override above) automatically
strips transform-based animation (slides, scales, springs) down to opacity-only crossfades, which QAYD
treats as the correct default rather than something to fight against per component.

## Three categories of motion, three different rules

| Category | Examples | Under reduced motion |
|---|---|---|
| Essential state-change motion | A Sheet sliding in from the edge; a Dialog's entrance | Kept, but simplified to an instant or near-instant crossfade — the state change itself must still be perceivable, just without the spring/slide |
| Decorative motion | Background parallax (landing-page only — the authenticated app has none by design), confetti/celebration on an approval, auto-playing illustrative loops | Fully disabled, no reduced substitute needed — these communicate nothing that a static state doesn't already communicate |
| Data-refresh motion | A dashboard KPI "counting up" from 0 to its value, an animated chart line draw-in | Disabled — the final value renders immediately; counting animation is replaced with a static number the instant data is available, never delayed for the sake of the animation finishing |

## No autoplay without control, no rapid flashing

The Morning Briefing's "read to me" audio (`docs/ai/AI_COMMAND_CENTER.md`) is **user-initiated only** — it
never autoplays on page load, satisfying WCAG 1.4.2 (Audio Control) by construction rather than by adding a
mute button after the fact. Any animated status indicator (e.g. a pulsing badge on a critical Fraud Alert)
is capped well under the WCAG 2.3.1 three-flashes-per-second seizure threshold — QAYD's pulse animations run
at approximately 1 cycle per 1.5 seconds — and always ships a static, equally legible alternative state
(a solid badge with the word "Critical") for any user who has reduced motion enabled, rather than a pulse
that simply stops animating and leaves no visual weight behind.

## Motion tokens

```css
:root {
  --motion-duration-fast: 120ms;
  --motion-duration-base: 200ms;
  --motion-duration-slow: 320ms;
  --motion-ease-standard: cubic-bezier(0.4, 0, 0.2, 1);
}

@media (prefers-reduced-motion: reduce) {
  :root {
    --motion-duration-fast: 0.01ms;
    --motion-duration-base: 0.01ms;
    --motion-duration-slow: 0.01ms;
  }
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

This global CSS fallback exists specifically to catch any third-party or Radix-internal transition that
does not run through the Framer Motion provider above, so `prefers-reduced-motion` is honored even for
animation QAYD's own code did not author directly.

# RTL Accessibility

## Mirroring is structural, not cosmetic

`dir="rtl"` is set once, on `<html>`, when `locale === 'ar'`, and every layout in QAYD is built from
Tailwind's logical-property utilities (`ps-4`/`pe-4` for start/end padding, `text-start`/`text-end`,
`ms-auto`/`me-auto`) rather than physical-direction utilities (`pl-4`/`pr-4`, `text-left`). This single
discipline is what makes the *entire* authenticated app — sidebar on the visual right instead of the left,
table columns reading right-to-left, form field icons on the visual left instead of the right — mirror
correctly with zero per-component RTL branching logic. A component that hard-codes `pl-4` where it means
"the padding on the side closest to the icon" is an RTL bug waiting to be filed the day someone switches
the locale, and QAYD's lint configuration (a Tailwind-aware ESLint rule) flags physical-direction utilities
in any file under `frontend/components` as a warning specifically to catch this before review.

## Tab order stays correct automatically — as long as no one reverses the DOM

This is the single rule in this document most likely to be violated by a well-intentioned developer trying
to "fix" RTL by hand: **never reorder DOM children or reverse an array purely to make a layout look
mirrored.** CSS logical properties plus `dir="rtl"` already mirror the *visual* presentation while leaving
DOM order — and therefore Tab order — semantically untouched; a screen reader and a keyboard user in `ar`
still traverse the page in the same logical sequence as in `en`, and because the visual layout is mirrored
by the browser's own bidi and logical-property handling, tabbing forward in Arabic correctly *feels* like
moving right-to-left across a mirrored row, with no JavaScript involved. The bug this rule prevents: a
developer looks at a toolbar that visually reads `[Cancel] [Save]` in English and, wanting the Arabic
version to visually read the mirrored `[Save] [Cancel]` from right to left, reverses the two buttons'
order *in the array that renders them* instead of using `flex-row-reverse`-equivalent logical properties.
The visual result can look identical, but Tab order is now silently reversed relative to the English
version, and QAYD explicitly forbids fixing RTL visual order by reordering markup or component arrays —
mirroring is a CSS-layer concern only.

## Directional icons flip; direction-neutral icons never do

| Icon type | Example | Flips under RTL? |
|---|---|---|
| Directional chevrons / arrows implying "back"/"forward" through a sequence | Breadcrumb separator, pagination chevron, "next step" arrow | **Yes** |
| Trend indicators where the direction is a data fact, not a navigation direction | Revenue trend up-arrow, cash-flow trough down-arrow | **No** — up means increase and down means decrease regardless of reading direction |
| Symmetric or direction-neutral glyphs | Checkmark, bell, star, trash icon | **No** |
| The `AmountCell` debit/credit directional icons (`# Color & Contrast`) | Inflow/outflow arrows | **No** — they encode a financial direction (money in vs. out), not a reading direction, and must stay fixed so a bilingual team reviewing the same ledger in two languages sees the same icon per transaction type |

Flipping is applied through a single wrapper rather than ad hoc `rtl:` utilities scattered per icon usage,
so the flip-or-not decision is made once, centrally, per icon meaning:

```tsx
// frontend/components/shared/directional-icon.tsx
export function DirectionalIcon({ icon: Icon, className, ...props }: { icon: LucideIcon } & IconProps) {
  return <Icon className={cn('rtl:-scale-x-100', className)} aria-hidden="true" {...props} />;
}
// Usage: <DirectionalIcon icon={ChevronRight} /> for a "next" affordance.
// Trend/AmountCell icons import Lucide icons directly, bypassing this wrapper entirely, by design.
```

## Bidirectional text isolation (cross-reference)

The `LtrInline` component and the numeral-system override documented in `# Screen Readers` are the RTL
accessibility mechanism as much as they are the screen-reader mechanism — the same embedded-LTR-token
reordering problem affects sighted RTL readers too (a KWD amount's digits visually re-ordering inside a
right-to-left paragraph is a real, reported bug pattern in RTL financial UIs, not only a screen-reader
issue), so `unicode-bidi: isolate` is applied for both populations by the same fix, applied once.

## Keyboard shortcuts keep their physical keys; on-screen hints mirror

Keyboard shortcuts are bound to physical keys and are **not** remapped for RTL (`←`/`→` inside the Journal
Entry line editor's data grid still move focus in the visually-correct direction because the grid's own
layout is mirrored by the same CSS logical-property mechanism — the arrow key's *meaning* ("move to the
visually-next cell") stays constant even though which physical key produces "visually-next" is unchanged;
it is the grid's visual order that flipped, not the key bindings). Small on-screen glyphs that *illustrate*
a shortcut (e.g. a tooltip showing "→" next to "Next field") use the same `DirectionalIcon` wrapper so the
hint glyph matches what the mirrored UI actually shows.

## RTL gets its own mandatory test pass

A component or screen that passes every automated and manual accessibility check in `en`/LTR is not
presumed to pass in `ar`/RTL. Recurring, real defect classes that only surface in RTL and that LTR testing
structurally cannot catch:

- A focus ring clipped by a parent's `overflow: hidden` because the ring now renders on the opposite side
  of an element than the side the developer tested.
- A horizontally-scrolling container (a wide table) that scrolls the wrong direction on initial load, or
  that produces a double/inverted scrollbar in some browser/OS combinations.
- A chevron or arrow icon that was hard-coded instead of using `DirectionalIcon` and now points the wrong
  way.
- Bidi reordering of an un-isolated embedded amount or code (`# Screen Readers`).

`# Testing` makes this explicit as a required, separate pass — not a checkbox that inherits a "pass" from
the English run.

# Data Tables Accessibility

## Two patterns, chosen deliberately per table, never by default

QAYD uses exactly two accessible table patterns, and the choice between them is made once per screen and
documented in that screen's own spec, not left to whichever pattern a component happened to reach for:

1. **Plain accessible `<table>`** — for every table whose primary interaction is *read, sort, filter,
   paginate, click a row to navigate, click a row action*. This is the large majority: Trial Balance,
   General Ledger, Journal Entries list, Invoices, Bills, Customers, Vendors, Products, Payroll runs list.
2. **ARIA `grid` pattern** (WAI-ARIA Authoring Practices Guide "Data Grid") — reserved exclusively for
   surfaces that are genuinely spreadsheet-like, where a user reasonably expects arrow-key cell-to-cell
   navigation because they are editing many small values in place: the Journal Entry line editor, and the
   Bank Reconciliation matching grid.

Applying the `grid` pattern to a plain display table is explicitly discouraged in this document — it adds
`role`, `tabindex`, and keyboard-handling complexity that makes the table *worse* for assistive technology
when the underlying interaction never needed cell-level navigation in the first place. Choosing the heavier
pattern by default, "to be safe," is a documented anti-pattern here, not a virtue.

## Plain table pattern, in full

```tsx
<div role="region" aria-label={t('journal_entries.table_region')} tabIndex={0} className="overflow-x-auto">
  <table>
    <caption className="sr-only">{t('journal_entries.table_caption')}</caption>
    <thead>
      <tr>
        <th scope="col">
          <button onClick={() => toggleSort('journal_number')} aria-sort={sortState('journal_number')}>
            {t('journal_entries.number')} <SortIcon aria-hidden="true" />
          </button>
        </th>
        <th scope="col">{t('journal_entries.date')}</th>
        <th scope="col">{t('journal_entries.status')}</th>
        <th scope="col" className="text-end">{t('journal_entries.total')}</th>
        <th scope="col"><span className="sr-only">{t('common.row_actions')}</span></th>
      </tr>
    </thead>
    <tbody aria-live="polite" aria-busy={isLoading}>
      {entries.map((entry) => (
        <tr key={entry.id}>
          <td>{entry.journalNumber}</td>
          <td>{formatDate(entry.journalDate, locale)}</td>
          <td><StatusBadge status={entry.status} label={t(`journal_status.${entry.status}`)} /></td>
          <td className="text-end">
            <AmountCell amountMinor={entry.totalDebit} currency="KWD" kind="neutral" locale={locale} />
          </td>
          <td>
            <IconButton aria-label={t('a11y.approve_entry', { number: entry.journalNumber })}>
              <Check aria-hidden="true" />
            </IconButton>
          </td>
        </tr>
      ))}
    </tbody>
  </table>
</div>
```

Five rules this snippet exists to demonstrate concretely:

- **The scroll wrapper is itself keyboard-operable.** `role="region"` + `aria-label` + `tabIndex={0}` on
  the horizontally-scrolling container means a keyboard user can focus the region and scroll it with arrow
  keys per WCAG 1.4.10/the APG scrollable-region pattern, without that focus stop trapping `Tab` — `Tab`
  still moves on to the next real control rather than needing repeated presses to "escape" the table.
- **Sortable headers are real buttons with `aria-sort` on the `<th>`.** Not a clickable `<th>` itself (which
  has no built-in interaction semantics), and not a `<div>` — a `<button>` inside the header cell, with
  `aria-sort="ascending" | "descending" | "none"` set on the parent `<th>`.
- **Row-action accessible names are unique per row, always.** `aria-label={t('a11y.approve_entry', {
  number: entry.journalNumber })}` renders "Approve journal entry JE-2026-00184" — never a bare "Approve."
  A screen-reader user tabbing through 25 identical-looking "Approve, button" announcements with no way to
  tell which row they are on is one of the single most common real-world accessibility complaints about
  data-table-heavy enterprise software, and it is entirely preventable at the component-API level: QAYD's
  `IconButton` usage lint rule requires a non-empty, row-specific `aria-label` string literal template
  (not a bare static string) on every icon-only button rendered inside a `.map()` over rows.
- **Loading state is announced, not silently swapped.** `aria-live="polite"` and `aria-busy` on `<tbody>`
  mean "Loading journal entries…" and, on completion, the new row count are both announced — a screen
  reader never experiences a table that appears to freeze with no feedback while a `TanStack Query` fetch
  is in flight.
- **Amounts still go through `AmountCell`**, even inside a table — no table-specific shortcut bypasses the
  debit/credit accessible-amount rules from `# Color & Contrast`.

## Pagination

QAYD's cursor-paginated lists (per `docs/api/API_PAGINATION.md`'s standard `meta.pagination` envelope)
announce page changes and expose real, labelled controls:

```tsx
<nav aria-label={t('pagination.label')}>
  <Button onClick={goPrev} disabled={!hasPrev} aria-label={t('pagination.previous')}>
    <ChevronLeftIcon aria-hidden="true" />
  </Button>
  <span aria-live="polite" role="status">
    {t('pagination.showing', { from: page.from, to: page.to, total: page.total })}
  </span>
  <Button onClick={goNext} disabled={!hasNext} aria-label={t('pagination.next')}>
    <ChevronRightIcon aria-hidden="true" />
  </Button>
</nav>
```

`ChevronLeftIcon`/`ChevronRightIcon` here are wrapped in `DirectionalIcon` (`# RTL Accessibility`) so
"Previous" points the visually-correct direction in both `en` and `ar`.

## Expandable rows and bulk selection

An expandable row (e.g. a Journal Entry row that expands inline to show its lines) uses `aria-expanded` on
its trigger and `aria-controls` pointing at the revealed row-group's `id`. Bulk-selection checkboxes (for
batch actions like "Approve 5 selected entries") use a real, tri-state header checkbox
(`aria-checked="mixed"` when some but not all rows are selected) and give each row checkbox an accessible
name tied to that row's identity ("Select journal entry JE-2026-00184"), never a bare "Select." The bulk
action toolbar that appears once a selection is non-empty announces its own appearance and count
(`role="status"`, "5 entries selected — Approve, Reject, or Export") rather than only sliding into view
visually with no equivalent announcement.

## The ARIA `grid` pattern for genuinely spreadsheet-like editing

The Journal Entry line editor is QAYD's canonical `grid` implementation, and every future inline-editable
tabular surface (Bank Reconciliation's matching grid) follows the same skeleton: `role="grid"` on the
container with `aria-rowcount`/`aria-colcount`, `role="row"` per line, `role="gridcell"` per editable cell,
and a **roving tabindex** — exactly one cell in the whole grid has `tabIndex={0}` at any moment (the
"active" cell), every other cell has `tabIndex={-1}`, and arrow keys move which cell holds the `0` rather
than the browser's native Tab sequence walking every cell individually (which would make a 40-line entry
take 160+ Tab presses to traverse — the entire reason the heavier pattern exists at all).

```tsx
function useRovingGrid(rowCount: number, colCount: number) {
  const [active, setActive] = useState({ row: 0, col: 0 });

  function onKeyDown(e: React.KeyboardEvent) {
    const deltas: Record<string, [number, number]> = {
      ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1],
    };
    const delta = deltas[e.key];
    if (!delta) return;
    e.preventDefault();
    setActive(({ row, col }) => ({
      row: clamp(row + delta[0], 0, rowCount - 1),
      col: clamp(col + delta[1], 0, colCount - 1),
    }));
  }

  return { active, onKeyDown, isActive: (r: number, c: number) => r === active.row && c === active.col };
}
```

```tsx
<div role="grid" aria-label={t('journal_line_editor.grid_label')} aria-rowcount={lines.length} aria-colcount={5}>
  {lines.map((line, r) => (
    <div role="row" key={line.id} aria-rowindex={r + 1}>
      {columns.map((col, c) => (
        <div
          role="gridcell"
          key={col.key}
          tabIndex={isActive(r, c) ? 0 : -1}
          onFocus={() => setActive({ row: r, col: c })}
          onKeyDown={onKeyDown}
        >
          <col.CellEditor line={line} />
        </div>
      ))}
    </div>
  ))}
</div>
```

`←`/`→` respect the mirrored visual order automatically under `dir="rtl"` for the same reason covered in
`# RTL Accessibility` — the grid's DOM order does not change per locale, only its visual presentation, and
the roving-tabindex hook's up/down/left/right mapping is expressed in terms of the DOM's row/column
indices, which remain the correct semantic mapping regardless of which physical direction "column + 1"
paints on screen.

# Testing (axe, Playwright, manual)

## Three layers of automated coverage

| Layer | Tool | Scope | Runs |
|---|---|---|---|
| Component / unit | `vitest` + `vitest-axe` (`toHaveNoViolations()`) | Every component in `frontend/components/ui` and `frontend/components/shared`, rendered in isolation via Storybook stories | Every PR touching a component, plus the full suite nightly |
| Story-level visual + a11y | Storybook + `@storybook/addon-a11y` (axe under the hood) | Every documented component state (default/hover/focus/disabled/loading/error/RTL/dark — the same state matrix every screen doc's own `# States` section requires) | Every PR; results visible directly in the Storybook UI during review |
| Integration / E2E | `@axe-core/playwright` | Every screen route, in **4 permutations**: `en`/light, `en`/dark, `ar`/light (RTL), `ar`/dark (RTL) | Every PR touching a route; full route matrix nightly |

```ts
// frontend/tests/a11y/journal-entries.spec.ts
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

for (const [locale, theme] of [['en', 'light'], ['en', 'dark'], ['ar', 'light'], ['ar', 'dark']] as const) {
  test(`journal entries list has no serious/critical a11y violations — ${locale}/${theme}`, async ({ page }) => {
    await page.goto(`/accounting/journal-entries?locale=${locale}&theme=${theme}`);
    await page.waitForSelector('table');

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
      .analyze();

    const blocking = results.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''));
    expect(blocking, JSON.stringify(blocking, null, 2)).toHaveLength(0);
  });
}
```

## CI gate policy

- **`serious` or `critical`** axe violations on any route or component touched by a PR **block merge** —
  this is a required GitHub Actions check, with no override short of a documented, time-boxed exception
  signed off by the Frontend Engineering lead and tracked as a P0 in the severity taxonomy
  (`# Standards & Targets`).
- **`moderate`** violations do not block merge (to avoid inherited/legacy debt making the repository
  permanently unmergeable) but open a tracked issue automatically and count against a burn-down target
  reviewed monthly.
- **`minor`** violations are logged to the nightly crawl's regression dashboard only.
- A **nightly full-site crawl** runs the same `AxeBuilder` configuration against a seeded staging tenant
  across every route in the App Router tree (not just routes touched by recent PRs), tracked over time
  rather than as a pass/fail gate, specifically to catch regressions introduced by shared-component changes
  that a route-scoped PR check would miss because that route wasn't in the diff.

## Manual testing (required before every release)

Automated tooling (axe-core and equivalents) reliably catches roughly a third of real WCAG failures by
industry consensus — missing labels, contrast, missing landmarks, malformed ARIA — and structurally
**cannot** catch focus-order sense, whether a live region's announcement is actually useful, whether a
keyboard-only user can complete a task in a reasonable number of steps, or whether an RTL mirror is
visually and logically correct. QAYD's manual pass, run before every release, therefore always includes:

| Pass | Method | Minimum coverage |
|---|---|---|
| Keyboard-only | Physically unplug the mouse (or disable the trackpad); complete each P0 flow | Login → post a journal entry → approve a pending Approval Center item → reconcile a bank transaction → run and export a report |
| Screen reader | NVDA + Chrome (Windows), VoiceOver + Safari (macOS); both `en` and `ar` | Dashboard / AI Command Center, Journal Entry form, Trial Balance, Approval Center — the four highest-traffic, highest-financial-risk surfaces, at minimum |
| 200% zoom / reflow | Browser zoom to 200%, viewport narrowed to 320 CSS px width | No horizontal scrolling of the page itself, no clipped or overlapping content (WCAG 1.4.10) |
| Reduced motion | Toggle the OS-level "Reduce motion" setting | Dashboard KPI count-up disabled, Sheet/Dialog transitions crossfade only, no residual parallax |
| Color/contrast spot-check | A physical contrast-checker tool against any newly introduced pairing not already in the `# Color & Contrast` token table | Every PR introducing a new color usage |

## Ownership recap

Design reviews contrast and target size in Figma before handoff; Frontend Engineering owns semantic HTML,
ARIA wiring, and the component-level automated suite; QA owns the manual keyboard/screen-reader/zoom/
reduced-motion pass per release; every individual screen doc's own `# Accessibility` section is that
screen's specific checklist item tracing back to this document, not an independent standard.

## Real users, not just simulation

Engineers testing with a screen reader they do not use daily is a reasonable first pass but is not a
substitute for testing with someone who does. QAYD commits to at least one testing session with an actual,
experienced screen-reader user per major release — proportionate to the fact that QAYD's own target roles
explicitly include External Auditor and Read Only users who may be professionally dependent on assistive
technology, not merely occasional users of it.

# Checklist

A condensed, per-PR / per-screen checklist. Every item traces back to a section above; use this as the
literal thing to tick through before requesting review on any UI change.

**Keyboard**
- [ ] Every interactive element is reachable via `Tab`/`Shift+Tab` alone, in a sensible order matching the DOM.
- [ ] No keyboard trap — every dialog/sheet/palette can be closed with `Esc` and returns focus correctly.
- [ ] Hover-only affordances also appear on `:focus-within`.
- [ ] New global actions are added to the Command Palette with RBAC filtering applied at the data layer.

**Focus**
- [ ] Dialogs/Sheets set a deliberate initial focus target, not just "first focusable."
- [ ] Focus return is verified when the triggering element can disappear after the action completes.
- [ ] Route changes move focus to the new page's `<h1>` and announce the new title.
- [ ] Toasts never call `.focus()` on themselves or their controls.

**ARIA & Semantics**
- [ ] Exactly one `<h1>` per route; no skipped heading levels.
- [ ] Every landmark (`header`, `nav`, `main`, `footer`) is present once and, where duplicated in type, labelled.
- [ ] AI confidence/status is exposed as real text, never color/icon alone.
- [ ] Live-region tier (`alert` / `polite` / `status`+`aria-busy`) matches the three-tier table, not invented ad hoc.
- [ ] Disabled controls carry a specific `aria-describedby` explanation, distinguishing RBAC from business-rule causes.

**Color & Contrast**
- [ ] Every new color pairing is checked against the `# Color & Contrast` token table before merge.
- [ ] No signed/financial amount relies on color alone — glyph + icon + text equivalent all present.
- [ ] Status badges pair color with a text label, never a colored dot alone.

**Screen Readers**
- [ ] Data tables use real `<table>` markup with `scope` on headers, not styled `<div>` grids.
- [ ] Amounts format through `formatCurrency()` with `numberingSystem: 'latn'` preserved in `ar`.
- [ ] Embedded LTR tokens inside Arabic copy are wrapped in `LtrInline` (or `AmountCell`'s built-in isolation).

**Forms**
- [ ] Every field has a real associated `<Label>`; no placeholder-as-label.
- [ ] Zod messages are translation keys, not hard-coded English strings.
- [ ] A failed submit focuses a form-level error summary listing every field error as a jump-link.
- [ ] Server-side (422) errors map onto the same RHF fields as client-side Zod errors.

**Motion**
- [ ] Every new Framer Motion transition reads its duration from the shared `MotionConfig`, not an inline literal.
- [ ] Decorative/data-refresh animation is fully disabled, not just shortened, under reduced motion.

**RTL**
- [ ] Layout uses logical Tailwind utilities (`ps-`/`pe-`/`text-start`/`text-end`), never `pl-`/`pr-`/`text-left` in component code.
- [ ] No DOM/array reordering was used to "fix" RTL visual order.
- [ ] Directional icons use `DirectionalIcon`; direction-neutral icons do not.
- [ ] The screen has been manually verified in `ar`/RTL, not assumed from its `en`/LTR pass.

**Data Tables**
- [ ] The correct pattern (plain table vs. ARIA `grid`) was chosen deliberately, not defaulted to the heavier one.
- [ ] Every icon-only row action has a row-specific, non-generic `aria-label`.
- [ ] The horizontal scroll wrapper is itself a focusable, labelled region that does not trap `Tab`.

# Edge Cases

**AI streaming output flooding a live region.** Naively wiring `aria-live="polite"` directly onto a
token-by-token streaming container announces every fragment as it arrives, which is technically compliant
and practically unusable — a screen reader reading half-words in bursts. The fix (`# ARIA & Semantics`) is
`aria-busy` at start/end with a single "answering… / done" announcement pair, leaving the full transcript
available as static, navigable (non-live) text underneath.

**Realtime pushes mutating a list a screen-reader user is mid-navigation through.** A Reverb event
(`journal.posted`, `ai.finished`, or any other domain event feeding a live dashboard) must never
directly splice new rows into a TanStack Query cache that backs a list the user is actively tabbing
through — the correct pattern is to hold the update aside behind a "3 new entries — Refresh" banner
(`role="status"`, `aria-live="polite"`) and apply it only on explicit user action, exactly mirroring the
non-disruptive UX pattern QAYD already wants for sighted users (nobody wants rows reflowing under their
mouse cursor either) — accessibility and good UX are the same fix here, not competing concerns.

**Optimistic updates that roll back.** A TanStack Query optimistic update (e.g. an Approve button flips a
row to "Approved" immediately, before the server confirms) that is later rejected by the server — most
commonly a `409 Conflict` because someone else already acted on the same entry — must announce the rollback
explicitly: "Could not approve — this entry was already approved by Khalid Marafie 2 minutes ago," via the
assertive live-region tier. A silent revert-the-pixel-back is invisible to a screen-reader user, who has no
way to know their action didn't actually happen; for a sighted user it merely looks like a flicker, which
is still bad UX but not a correctness-of-information failure the way it is for someone who cannot see the
flicker at all.

**RBAC-denial vs. business-rule-denial on the same control.** Restated from `# ARIA & Semantics` because it
recurs constantly in practice: a "Post" button can be disabled because the user lacks
`accounting.journal.post`, or because the entry does not balance, or because the fiscal period is locked —
three entirely different reasons that all render as one grayed-out button. Each has its own specific
`aria-describedby` text sourced from the actual condition that is true, never a single generic "You can't
do this" string covering all three; conflating them is a shipped P1 defect, not a documentation nuance.

**Large-magnitude and abbreviated numbers.** A dashboard KPI tile may display "KWD 1.2M" for visual density
at enterprise scale, but the full, exact figure ("1,200,450.500") is always available to assistive
technology via a `title` attribute and matching `aria-label` on the same element — abbreviation is a visual
convenience layered on top of a fully precise underlying value, never a substitute for it, and
`Intl.NumberFormat` configuration is verified to never silently drop into exponential/scientific notation
for any KWD figure a real enterprise tenant could plausibly reach.

**A keyboard shortcut firing on a stale card after a re-render.** The `A`-to-approve shortcut
(`# Keyboard Navigation`) must act only on whichever card currently, unambiguously holds DOM focus — never
on a cached "last known focused card" reference — because AI Recommendations and Approval Center feeds can
receive new items in real time; if a new higher-priority card streams in and re-renders the list at the
exact moment a user presses `A`, the shortcut must resolve against the live `document.activeElement`, not
a snapshot taken before the re-render, or a user can end up approving something they never intended to.

**Session expiry mid-form.** A Sanctum token expiring while a user is 40 lines into a Journal Entry must
never simply redirect to `/login` and discard the in-progress draft — the accessible (and simply humane)
pattern is a re-authentication dialog that preserves the draft in memory/local storage, clearly announces
what happened ("Your session expired — sign in again to continue; your draft has been saved"), and returns
the user to exactly where they were after re-auth, rather than a silent, jarring loss of 40 rows of typed
data with no explanation of why the screen just changed.

**Exported PDFs are a separate, real accessibility surface.** A Trial Balance or Financial Statement
exported to PDF via the Reports submodule ships as a **tagged PDF** — real heading structure, real table
headers (`/Table`, `/TH`, `/TD` PDF tags) — never a flattened, image-only rasterization of the on-screen
report, because an exported document with no underlying structure is unreadable by any AT the moment it
leaves the browser. Ownership of the export pipeline itself sits with the Reports module's own
documentation, but the requirement is binding here and is not satisfied by "the on-screen version is
accessible" alone.

**Voice control targeting.** A user operating QAYD entirely through OS-level voice control (macOS Voice
Control, Android Voice Access) activates a control by speaking its accessible name aloud — "Click Approve
journal entry JE-2026-00184." Every rule in this document requiring a specific, unique accessible name on
icon-only buttons (`# Data Tables Accessibility`, `# ARIA & Semantics`) is therefore not solely a
screen-reader requirement; it is equally what makes the product usable hands-free, and voice-control
walkthroughs are included in the same manual test rotation as screen-reader passes for any release
touching a dense table.

**No kiosk mode.** Unlike consumer-facing kiosk products, QAYD's B2B accounting frontend has no
public/unattended kiosk surface and no kiosk-specific accessibility profile — every screen assumes an
authenticated, individually-accountable user, and this document does not carve out an exception anywhere
for an "untrusted public terminal" use case because QAYD does not have one.

# End of Document

