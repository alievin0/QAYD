# Empty States — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / EMPTY_STATES
---

# Purpose

`<EmptyState>` is the one shared component QAYD renders whenever a region that would normally hold data
holds none — an empty ledger, a journal-entry list with nothing in it yet, a reconciliation queue with no
unmatched lines, a filtered table that matched zero rows, or a panel a user's role cannot populate. It
exists so that "there is nothing here" is never rendered as a silent blank rectangle (which reads as a
broken screen) and never as forty slightly different hand-rolled empty messages across forty screens. Every
empty region in the product routes through this component, and this document is its binding contract.

An empty state in a financial product is not a minor cosmetic case — it is a first-impression surface and a
navigational one. A brand-new company's first login is *almost entirely* empty states: no accounts, no
entries, no bank feed, no statements. Those emptinesses are the product's onboarding funnel, so each must do
real work — say plainly what is absent, say why, and offer the single most useful next action — while
holding the platform's calm, editorial register (`../DESIGN_LANGUAGE.md → Imagery & Illustration stance`):
at most one line-drawn abstraction at 1.5px stroke in `ink-7`, never an illustrated character reacting to
the void, never an emoji, never "Nothing here yet! Let's fix that 🚀." The copy carries the weight; the
graphic is a quiet anchor.

`<EmptyState>` is deliberately distinct from `<LoadingState>` (`./LOADING_STATES.md`) and `<ErrorState>`
(`./ERROR_STATES.md`). "We are fetching," "we fetched and there is genuinely nothing," and "we tried and
failed" are three different truths a user must be able to tell apart at a glance, and collapsing any two of
them is a defect (see `# Behavior & Interaction → How it differs from Loading and Error`). This is why
`DataTable`, per `../COMPONENT_LIBRARY.md`, branches on `isPending` → skeleton, `isError` → `ErrorState`,
and `data.length === 0` → `EmptyState`, three separate outcomes, never one shared "no data" fallback.

# Anatomy & Variants

## Anatomy

An `<EmptyState>` is a vertically-stacked, centered composition inside its region with generous whitespace
(`--space-lg`/`--space-xl` rhythm, `../DESIGN_LANGUAGE.md → Spacing scale`), composed of, top to bottom:

1. **Icon / illustration slot** — a single Lucide line icon at 24px (`../ICONOGRAPHY.md → Sizing`) in
   `ink-7`, or, for a small set of first-run states, one custom 1.5px line-drawing (a stack of documents, a
   ruled page, an empty tray) with its single focal element optionally in `accent`. Optional; a purely
   textual empty state is valid and common.
2. **Title** — one short line, `display-sm` (20px) General Sans in `ink-11`, stating what is absent as a
   plain noun phrase ("No journal entries yet," "No unmatched lines").
3. **Description** — one or two sentences, `text-sm` Inter in `ink-9`, explaining *why* it is empty and/or
   what populating it would take. Optional but strongly encouraged for first-run states.
4. **Primary action** — at most one `Button variant="default"`, the single most useful next step ("New
   entry," "Import statement," "Set up chart of accounts"). Rendered only when the viewer can actually
   perform it (permission-checked — see Variants).
5. **Secondary action** — at most one lower-emphasis `Button variant="ghost"`/`link` (e.g. "Learn how
   reconciliation works," "Clear filters").

Nothing in the composition uses a colored background wash, a heavy border, or a shadow — an empty state
sits on the region's own surface, distinguished by whitespace and centering, not by a card treatment that
would read as "an error box."

## The four empty kinds

`<EmptyState>` renders one of four semantically distinct kinds, selected by the `kind` prop. The distinction
is not cosmetic — it changes the copy, whether a primary action appears, and what that action does.

| `kind` | Meaning | Primary action | Copy register |
|---|---|---|---|
| `first_run` | The dataset has genuinely never held anything (new company, unfinished onboarding) | The setup/create CTA, if the viewer holds the permission | Inviting, forward-looking: "No journal entries yet. Create your first entry to start your books." |
| `filtered` | The dataset is non-empty, but the active search/filter matched zero rows | "Clear filters" (never a create CTA) | Neutral, corrective: "No entries match your filters." |
| `permission_restricted` | Data may exist, but the viewer's role cannot populate or is not the intended actor | No create CTA; a redirect/explain link at most | Non-accusatory: "Ask your Finance Manager to generate this period's Balance Sheet." |
| `error_adjacent` | Data is genuinely absent *because* an upstream dependency is unavailable, but this is an expected, non-error condition (e.g. the AI engine is down, so there are no AI insights) | A retry or a "meanwhile" link, never a create CTA | Reassuring, temporary: "AI insights are temporarily unavailable." |

The `first_run` vs. `filtered` split is the single most important distinction and the one most often
collapsed. A user who has filtered a 4,000-row ledger down to zero must never be told "No entries yet — create
your first entry" (their books are full; the CTA is nonsense and the copy is alarming); they must be told
"No rows match your filters" with a way to clear them. `DataTable` makes this automatic — a zero-length
result *with active filters* renders `kind="filtered"`, without them renders `kind="first_run"` — but any
screen composing `<EmptyState>` directly is responsible for passing the correct `kind`
(`../COMPONENT_LIBRARY.md → DataTable → emptyState`).

## Illustration slot

Per `../DESIGN_LANGUAGE.md → Imagery & Illustration stance`, the illustration slot accepts one of exactly
three things and nothing else: a Lucide line icon (the default), one of the small library of custom 1.5px
line abstractions documented in `../ICONOGRAPHY.md → Custom/Brand Icons`, or nothing. It never accepts a
raster image, a multi-color illustration, a character, or an emoji. The slot is `aria-hidden` — it carries
no meaning the title and description do not already carry in text (see `# Accessibility`).

# Props / API

## `<EmptyState>`

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `kind` | `'first_run' \| 'filtered' \| 'permission_restricted' \| 'error_adjacent'` | no | `'first_run'` | Selects copy register and action policy (see table above). `DataTable` sets this automatically; direct callers set it explicitly. |
| `title` | `string` | yes | — | The one-line noun phrase; an i18n key resolved by the caller. |
| `description` | `string` | no | — | One or two sentences of context; i18n key. Omitted for a terse state where the title alone suffices. |
| `icon` | `LucideIcon \| 'documents' \| 'ruled-page' \| 'empty-tray' \| null` | no | a `kind`-appropriate default | The illustration slot. `null` renders no graphic. Named strings map to the custom line abstractions. |
| `primaryAction` | `{ label: string; onClick?: () => void; href?: string; permission?: string }` | no | — | The single default action. If `permission` is set and ungranted, the action is omitted and (for `first_run`) the copy falls back to a `permission_restricted`-style "ask someone who can" line. |
| `secondaryAction` | `{ label: string; onClick?: () => void; href?: string }` | no | — | A lower-emphasis action (e.g. "Clear filters," a docs link). |
| `size` | `'inline' \| 'region' \| 'page'` | no | `'region'` | `'inline'` for an empty state inside a small widget (tighter spacing, 20px icon); `'region'` for a table/panel body; `'page'` for a full route-level empty (roomier, may use `display-lg`). |
| `className` | `string` | no | — | Layout escape hatch; never used to introduce a background/border that contradicts the flat treatment. |

## Relationship to `DataTable`'s `emptyState` prop

`DataTable` accepts `emptyState?: { title; description; action? }` and internally renders `<EmptyState>` with
`kind` derived from whether filters are active. A screen passes only the *first-run* copy to `DataTable`; the
*filtered* copy ("No rows match your filters," + a "Clear filters" secondary) is supplied by `DataTable`
itself from a shared default, so no screen hand-writes the filtered-empty message and the two never diverge
(`../COMPONENT_LIBRARY.md → DataTable`).

## Implementation

```tsx
// components/shared/empty-state.tsx
import { type LucideIcon, FileText, SlidersHorizontal, Lock, Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { RuledPageGlyph, DocumentsGlyph, EmptyTrayGlyph } from '@/components/shared/empty-glyphs';
import { cn } from '@/lib/utils';

type EmptyKind = 'first_run' | 'filtered' | 'permission_restricted' | 'error_adjacent';

const DEFAULT_ICON: Record<EmptyKind, LucideIcon> = {
  first_run: FileText,
  filtered: SlidersHorizontal,
  permission_restricted: Lock,
  error_adjacent: Sparkles,
};

interface EmptyStateProps {
  kind?: EmptyKind;
  title: string;
  description?: string;
  icon?: LucideIcon | 'documents' | 'ruled-page' | 'empty-tray' | null;
  primaryAction?: { label: string; onClick?: () => void; href?: string; permission?: string };
  secondaryAction?: { label: string; onClick?: () => void; href?: string };
  size?: 'inline' | 'region' | 'page';
  className?: string;
}

export function EmptyState({
  kind = 'first_run', title, description, icon, primaryAction, secondaryAction, size = 'region', className,
}: EmptyStateProps) {
  const Glyph = resolveGlyph(icon, DEFAULT_ICON[kind]);
  const showPrimary = primaryAction && (!primaryAction.permission || usePermission(primaryAction.permission));

  return (
    <div
      role="status"                                   // an intentional, non-error state — see Accessibility
      className={cn(
        'flex flex-col items-center justify-center text-center',
        size === 'inline' ? 'gap-2 py-6' : size === 'page' ? 'gap-4 py-20' : 'gap-3 py-12',
        className,
      )}
    >
      {Glyph && (
        <Glyph
          className={cn('text-ink-300', size === 'inline' ? 'h-5 w-5' : 'h-6 w-6')}
          aria-hidden
          strokeWidth={1.5}
        />
      )}
      <h3 className={cn('font-display text-ink-950', size === 'page' ? 'text-display-lg' : 'text-display-sm')}>
        {title}
      </h3>
      {description && <p className="max-w-sm text-sm text-ink-500">{description}</p>}
      {(showPrimary || secondaryAction) && (
        <div className="mt-1 flex items-center gap-2">
          {secondaryAction && (
            <Button variant="ghost" size="sm" asChild={Boolean(secondaryAction.href)} onClick={secondaryAction.onClick}>
              {secondaryAction.href ? <a href={secondaryAction.href}>{secondaryAction.label}</a> : secondaryAction.label}
            </Button>
          )}
          {showPrimary && (
            <Button size="sm" asChild={Boolean(primaryAction!.href)} onClick={primaryAction!.onClick}>
              {primaryAction!.href ? <a href={primaryAction!.href}>{primaryAction!.label}</a> : primaryAction!.label}
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
```

## Usage

```tsx
// First-run empty ledger, gated primary action
<EmptyState
  kind="first_run"
  icon="ruled-page"
  title={t('generalLedger.empty.title')}          // "No posted entries yet"
  description={t('generalLedger.empty.body')}       // "Post your first journal entry to see it appear in the ledger."
  primaryAction={{ label: t('journalEntries.new'), href: '/accounting/journal-entries/new', permission: 'accounting.journal.create' }}
/>

// Filtered-to-nothing (DataTable supplies this variant itself; shown here for a hand-composed table)
<EmptyState
  kind="filtered"
  title={t('common.noRowsMatchFilters')}
  secondaryAction={{ label: t('common.clearFilters'), onClick: clearFilters }}
/>

// Permission-restricted statement
<EmptyState
  kind="permission_restricted"
  title={t('balanceSheet.empty.askManager')}        // "Ask your Finance Manager to generate this period's Balance Sheet"
/>
```

# States

`<EmptyState>` is itself a *terminal* rendering — it is what a region shows after loading resolves to
nothing — so its own "states" are its four `kind`s plus the permission-driven action fallback, rather than a
loading/hover/error lifecycle:

| State | Trigger | Rendering |
|---|---|---|
| **First-run** | Fetch resolved, dataset genuinely empty, no active filters | `kind="first_run"`, inviting copy, permitted create CTA if the viewer holds the key |
| **First-run, viewer can't create** | As above, but `primaryAction.permission` ungranted | CTA omitted; copy falls back to a `permission_restricted`-style "ask someone who can" line rather than dangling a button the viewer can't press |
| **Filtered-empty** | Fetch resolved to zero rows *with* active search/filter | `kind="filtered"`, corrective copy, "Clear filters" secondary — never a create CTA |
| **Permission-restricted** | Viewer's role cannot populate/generate this region | `kind="permission_restricted"`, non-accusatory copy, at most an explain/redirect link |
| **Error-adjacent** | Region is empty because an *expected, recoverable* upstream is unavailable (AI `503`, unconnected bank feed) | `kind="error_adjacent"`, reassuring "temporarily unavailable" copy, a retry or "meanwhile" link — distinct from `ErrorState` (see below) |
| **Hover/focus (actions only)** | Pointer/keyboard on a rendered action | Standard `Button` hover/focus states; the empty state's text and glyph are non-interactive |

There is no loading state on `<EmptyState>` itself — the region shows `<LoadingState>`/skeletons *until*
the query resolves, and only then, if the result is empty, does `<EmptyState>` mount. An empty state that
"spins" is a category error.

# Behavior & Interaction

## How it differs from `LoadingState` and `ErrorState`

These three components partition the non-happy outcomes of a data-bearing region, and their distinctness is
a designed invariant, not an accident of implementation:

- **`LoadingState`** (`./LOADING_STATES.md`) means *"we don't know yet."* It is shown while a query is
  `isPending`, mirrors the *shape* of the content to come (skeleton rows sized like real rows), and carries
  no title, no message, and no action. It is inherently temporary and must never persist.
- **`EmptyState`** means *"we know, and there is genuinely nothing."* It is a settled, correct, often
  expected condition (a new company's ledger *should* be empty), so it is calm, explanatory, and
  action-offering, not apologetic.
- **`ErrorState`** (`./ERROR_STATES.md`) means *"we tried and failed."* It implies something went wrong that
  a retry or a support request might fix, carries a retry affordance and often a `request_id`, and is styled
  distinctly so a user never mistakes a transient failure for "there's just no data."

The single hardest boundary is `error_adjacent` empty vs. a true `ErrorState`. The rule: if the absence is
an *expected* consequence of a known, self-healing condition documented to fail soft — the AI engine
returning `503` with `Retry-After` per `../../api/REST_STANDARDS.md`, or a bank account that simply has no
feed connected yet — it is an `EmptyState kind="error_adjacent"` ("AI insights are temporarily unavailable,"
per `../COMPONENT_LIBRARY.md → Edge Cases → AI engine unavailable`). If the absence is an *unexpected*
failure of a call that should have returned data — a `500`, a network drop on the ledger fetch — it is an
`ErrorState` with a retry. The two are never the same component, because a spinner-forever or a scary error
box in place of "the AI is briefly resting" both misinform the user.

## Action policy

- **At most one primary action**, always the single most useful next step, always permission-checked. If the
  viewer cannot perform it, it is omitted (not disabled) and the copy adjusts — an empty state is a poor
  place for a disabled-with-tooltip button, since there is no dense context to justify showing an unusable
  control.
- **At most one secondary action**, lower-emphasis, for the second-most-likely intent ("Clear filters,"
  "Learn how X works," a redirect for a restricted viewer).
- **`filtered` never offers a create CTA** — the corrective action is to change the filter, and offering
  "New entry" to someone whose books are full but filtered is actively confusing.
- **Actions are real `Button`s** with real `href`/`onClick`, inheriting all keyboard/focus behavior from
  `./BUTTONS.md`; they are never `<div onClick>`.

## Placement and scope

An `<EmptyState>` fills exactly the region that would have held the data and no more — a table's body, a
panel's content, a dashboard widget's interior — while the region's surrounding chrome (the table toolbar,
the panel header, the page's filters and action buttons) stays fully rendered and interactive, so the user
can still act to change the emptiness (import, create, adjust filters). A region-level empty state never
takes over the whole page; a *route*-level `size="page"` empty state (a genuinely empty screen, like a
first-run module hub) is the only full-bleed case.

# Accessibility

- **Announced as status, not alarm.** The container carries `role="status"` (`aria-live="polite"` implicit),
  so when a filtered fetch resolves to an empty state the change is announced calmly to a screen-reader user
  — "No entries match your filters" — without the interrupting `role="alert"` reserved for genuine errors
  (`../ACCESSIBILITY.md → Live regions`). An empty state is never `role="alert"`.
- **The graphic is decorative.** The illustration slot is `aria-hidden`; it repeats nothing the title and
  description do not already say in text, so a screen-reader user loses nothing by not "seeing" it. There is
  never an empty state whose meaning lives only in a picture.
- **A real heading.** The title is a real `<h3>` in the region's heading hierarchy (`../ACCESSIBILITY.md →
  Heading hierarchy`), so an empty region is a navigable landmark in the document outline, not an anonymous
  centered `<div>` — a screen-reader user scanning by heading finds "No journal entries yet" as a real stop.
- **Actions are keyboard-reachable and labeled.** Primary/secondary actions are `Button`s, so they are
  focusable, `Enter`/`Space`-activatable, and carry real accessible names — the entire `./BUTTONS.md`
  accessibility contract applies unchanged.
- **Not a silent blank.** The whole reason `<EmptyState>` exists is that a data region must never render as
  an empty `<table>` or a blank panel with no announced content — `DataTable`'s empty state is "a landmark
  region, not a silent blank table" per `../COMPONENT_LIBRARY.md → Accessibility per component`.
- **Contrast holds in both themes.** `ink-950` title / `ink-500` description / `ink-300` glyph all clear
  their AA targets against the region surface in light and dark (`../ACCESSIBILITY.md → Color & Contrast`);
  the glyph, being decorative, is exempt from the text-contrast floor but is kept legible regardless.

# Theming, Dark Mode & RTL

## Theming

`<EmptyState>` references only semantic tokens — `text-ink-950`, `text-ink-500`, `text-ink-300` — and its
actions inherit the accent through `Button`. There is no background or border token on the component
(intentionally: it sits flat on its region), so a white-label accent override (`../THEMING.md → Company
Branding`) touches only the primary action's fill, exactly as intended. No raw hex or Tailwind palette color
appears in the source.

## Dark mode

No `dark:` variants. The ink and accent tokens remap under `:root[data-theme="dark"]`
(`../DARK_MODE.md → Token Mapping`): the title flips to near-white `ink-950`, the description and glyph
lighten to keep the same relative contrast, and the custom line-drawing glyphs — being stroke-only,
`currentColor`-driven SVG — recolor automatically with the ink token rather than needing a separate dark
asset (`../DARK_MODE.md → Illustrations and empty-state art`). A raster illustration would need a dark
variant; QAYD's stroke-only stance is exactly what avoids that.

## RTL

- **Centered layout is direction-neutral**, so the vertical stack needs no mirroring; the title, description,
  and action row all center identically in LTR and RTL.
- **Action order mirrors logically.** The `flex` action row uses logical flow, so "secondary then primary"
  lands with the primary on the inline-end edge in both directions, matching `ButtonGroup`'s behavior in
  `./BUTTONS.md`.
- **Directional glyphs flip; object glyphs don't.** An "empty-tray"/"documents"/"ruled-page" abstraction
  depicts a static object and never mirrors; were an empty state ever to use a directional arrow glyph, it
  would flip via the `[dir="rtl"]` transform per `../ICONOGRAPHY.md → RTL-Aware Icons` — but the standard
  empty glyphs do not.
- **Arabic copy is first-class.** The title and description render in IBM Plex Sans Arabic in the `ar`
  locale, with the taller Arabic line-height applied (`../DESIGN_LANGUAGE.md → Typography`); the Arabic empty
  copy is authored to the same calm register, e.g. "لا توجد معاملات حتى الآن. اربط حسابًا بنكيًا أو أنشئ أول
  قيد محاسبي." from `../DESIGN_LANGUAGE.md → Arabic microcopy`.

# i18n

- **Title and description are always keys**, resolved via `useTranslations(namespace)` and present in both
  `en.ts` and `ar.ts` — a key in one and missing in the other fails `i18n:check` in CI (`../README.md →
  Conventions`). No screen passes a literal English empty message.
- **Per-module copy is authored, not templated.** Empty copy is specific to the module — "No posted entries
  yet" for the ledger, "No journal entries yet" for the entries list, "No unmatched lines" for
  reconciliation — because a generic "No data" reads as unfinished. Each screen owns its own empty keys; the
  shared *filtered* copy ("No rows match your filters") is the one string owned centrally by `DataTable`.
- **Arabic empties are parallel originals**, written to the professional Gulf-business register, never
  machine-translated (`../DESIGN_LANGUAGE.md → Arabic microcopy`) — an over-translated or too-casual empty
  state on a first-run screen undermines the exact first impression the empty state exists to make.
- **Any embedded numeral or permission key** in copy (rare in empties) follows the platform numeral rule —
  Western digits, `dir="ltr"` span — and a permission key named in `permission_restricted` copy stays a
  Latin code token in both languages.

## Per-module empty copy guidance

| Region | `kind` | Title (EN) | Primary action (permission) |
|---|---|---|---|
| Empty ledger (`GENERAL_LEDGER.md`) | `first_run` | "No posted entries yet" | "New entry" (`accounting.journal.create`) |
| No journal entries (`JOURNAL_ENTRIES.md`) | `first_run` | "No journal entries yet" | "New entry" (`accounting.journal.create`) |
| No unmatched bank lines (`BANK_RECONCILIATION.md`) | `first_run` | "No statement imported yet for this account" | "Import statement" (`bank.reconcile`) |
| Reconciliation filtered to zero | `filtered` | "No lines match your search" | — (Clear filters, secondary) |
| Empty Chart of Accounts (`AccountPicker`) | `first_run` | "No postable accounts yet" | "Set up chart of accounts" (`accounting.coa.manage`) |
| Balance Sheet, viewer can't generate | `permission_restricted` | "Ask your Finance Manager to generate this period's Balance Sheet" | — |
| AI insights down (`AIProposalPanel` parent) | `error_adjacent` | "AI insights are temporarily unavailable" | "Retry" (backs off on `Retry-After`) |

These match, verbatim where they overlap, the empty copy already established in the screen specs
(`../BANKING.md`, `../BANK_RECONCILIATION.md`, `../BALANCE_SHEET.md`, `../AUDIT_LOG.md`), so the same
condition never reads differently on two screens.

# Testing

**Storybook.** `empty-state.stories.tsx` renders all four `kind`s, the permission-granted vs.
permission-omitted primary action, the `inline`/`region`/`page` sizes, and the custom-glyph vs. Lucide-icon
vs. no-graphic variants, each inspectable across LTR/RTL × light/dark via the global decorator
(`../COMPONENT_LIBRARY.md → Testing`). `DataTable`'s own story additionally short-circuits its query to an
empty result *with* and *without* active filters to prove the `first_run`/`filtered` branch.

```tsx
// components/shared/empty-state.stories.tsx (excerpt)
export const FirstRun: StoryObj<typeof EmptyState> = {
  args: { kind: 'first_run', icon: 'ruled-page', title: 'No journal entries yet',
          description: 'Create your first entry to start your books.',
          primaryAction: { label: 'New entry', href: '#' } },
};
export const Filtered: StoryObj<typeof EmptyState> = {
  args: { kind: 'filtered', title: 'No rows match your filters',
          secondaryAction: { label: 'Clear filters', onClick: () => {} } },
};
export const PermissionRestricted: StoryObj<typeof EmptyState> = {
  args: { kind: 'permission_restricted', title: "Ask your Finance Manager to generate this period's Balance Sheet" } };
```

**Vitest + Testing Library** covers the behavioral rules:

```tsx
// components/shared/empty-state.test.tsx
test('first_run omits the primary action when the viewer lacks its permission', () => {
  mockPermission('accounting.journal.create', false);
  const { queryByRole } = render(
    <EmptyState kind="first_run" title="No entries yet"
      primaryAction={{ label: 'New entry', href: '/x', permission: 'accounting.journal.create' }} />,
  );
  expect(queryByRole('link', { name: 'New entry' })).toBeNull(); // omitted, not disabled
});

test('renders role=status, never role=alert', () => {
  const { getByRole } = render(<EmptyState title="No rows match your filters" kind="filtered" />);
  expect(getByRole('status')).toBeInTheDocument();
});

test('the illustration glyph is aria-hidden', () => {
  const { container } = render(<EmptyState title="Empty" icon="documents" />);
  expect(container.querySelector('[aria-hidden="true"]')).toBeTruthy();
});
```

**Playwright** covers the one flow a unit test cannot: a `DataTable` whose seeded API returns rows,
filtered by the user down to zero, asserting the *filtered* empty variant (with "Clear filters") renders —
not the first-run create-CTA variant — and that clearing the filter restores the rows. A brand-new-company
Playwright fixture additionally asserts the first-run empties across the ledger, entries, and reconciliation
screens render their module-specific copy and permission-gated CTAs.

**Accessibility CI.** `axe-core` runs against every empty-state story; a missing heading, a non-`aria-hidden`
decorative glyph, or a contrast regression on the description text in either theme fails the build.

# Edge Cases

- **First-run vs. filtered-to-zero on the same table.** The only reliable discriminator is "are filters/
  search currently active," which `DataTable` owns; a screen composing `<EmptyState>` by hand must pass the
  correct `kind` from its own filter state, because the copy and the offered action differ fundamentally
  (`../COMPONENT_LIBRARY.md → DataTable → emptyState`).
- **Empty because of a permission the viewer *does* hold on a *different* company.** In a multi-company
  session, a region empty for the active company is still `first_run`/`permission_restricted` for *that*
  company; the empty state never suggests switching companies to see data, which would leak the existence of
  other tenants' data.
- **`error_adjacent` that is actually a hard error.** If an "AI unavailable" empty state is rendered for a
  `500` (not the documented soft-fail `503` + `Retry-After`), that is a mis-routing bug — a `500` is an
  `ErrorState`. The parent query distinguishes them by status code, per `./ERROR_STATES.md → mapping the
  ApiError envelope`.
- **A `first_run` empty that should have been a loading state.** If `<EmptyState>` flashes for a frame before
  data arrives, the region rendered empty *before* its query resolved — the fix is to gate on `isPending`
  (show `<LoadingState>`) and only render `<EmptyState>` on a *settled* empty result, never on
  `data ?? []` being momentarily empty (`./LOADING_STATES.md → when to skeleton vs. nothing`).
- **Very long Arabic title wrapping.** Arabic titles run longer than English at the same size; the title is
  `max-w`-bounded and wraps to two lines rather than truncating (an empty-state title is short enough that
  wrapping is fine and truncation would hide meaning), with the taller Arabic line-height applied.
- **Empty state inside a `size="inline"` dashboard widget.** A KPI or insight widget with no data uses
  `size="inline"` (20px glyph, tighter padding, often no description) so it does not dominate a compact tile
  — the reassuring one-liner ("Nothing waiting on you") is the whole message, matching `../AI_COMMAND_CENTER.md`
  and `../APPROVAL_CENTER.md`'s compact-widget empties.
- **Printing / PDF export.** Because the empty state is flat text plus a stroke-only glyph with no color
  fill, it renders correctly in a grayscale, no-JS server-side export (`../../api/REST_STANDARDS.md`) — an
  exported statement for a zero-transaction period shows its "No Balance Sheet generated as of…" empty copy
  legibly without color, a designed invariant matching `../BALANCE_SHEET.md`.
- **Secondary "Clear filters" when the filter lives in the URL.** For a `filtered` empty whose filter state
  is a route search param, "Clear filters" navigates to the same route without the param rather than only
  mutating client state, so the empty→populated transition is a real, linkable navigation and a browser
  back-button restores the filter.

# End of Document
