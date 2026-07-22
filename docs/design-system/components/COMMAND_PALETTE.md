# Command Palette — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / COMMAND_PALETTE
---

# Purpose

This document is the atomic component spec for `CommandPalette` — QAYD's global `⌘K` / `Ctrl+K` overlay,
built over shadcn/ui's `Command` primitive (the `cmdk`-backed component over Radix). It is the one
transient surface that jumps to any screen, runs any permitted action, opens any record, or asks the AI a
question from anywhere in the app. This spec fixes its anatomy, its grouped-result structure, the
keyboard/combobox contract it inherits from cmdk, the recent/suggested idle state, and its token, theming,
and RTL rules — the design-system layer beneath the behavior.

It is a **specialization** of, and defers to, the app-level component doc at
[`../../frontend/components/SEARCH_BAR.md`](../../frontend/components/SEARCH_BAR.md), which owns the two
call shapes (per-module fan-out vs. the aggregate endpoint), the debounce path, the RBAC belt-and-braces,
and the recent/suggested sourcing; and to
[`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md), which owns the palette's
search-and-action *grammar* (how Navigate / Records / AI & Actions rank). This document does not re-derive
that behavior; it restates the reusable palette shape and its design rules and cross-links back. The
sibling [`SEARCH.md`](./SEARCH.md) spec owns the inline search-input/results primitive; this one owns the
overlay.

Every value references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the `ink-1…12` scale, the single
brass `accent`, the `radius-*` primitives, the `surface-glass` treatment, and the `z-command` band.

# Anatomy

```
        ┌──────────────────────────────────────────────────────┐  ← surface-glass, z-command
        │  /  Search or jump to…                          Esc  │  ← CommandInput (combobox)
        ├──────────────────────────────────────────────────────┤
        │  NAVIGATE                                             │  ← CommandGroup heading
        │   ▸ Inventory                                        │     CommandItem (option, roving focus)
        │   ▸ Invoices                                         │
        │  ────────────────────────────────────────────────    │  ← CommandSeparator
        │  INVOICES                                            │
        │   ▸ INV-2026-0482 · Al-Fajr Trading   KWD 1,240.000  │  ← SearchResultItem (icon·title·amount·status)
        │   ▸ INV-2026-0455 · Gulf Motors       KWD 890.500    │
        │  ────────────────────────────────────────────────    │
        │  AI & ACTIONS                                         │
        │   * Ask AI about "unpaid Al-Fajr invoices"           │  ← accent Sparkles; last group, never first
        ├──────────────────────────────────────────────────────┤
        │  No results for "…"                                  │  ← CommandEmpty (still offers Ask AI + Navigate)
        └──────────────────────────────────────────────────────┘
```

| Part | Element | Rule |
|---|---|---|
| Dialog | `CommandDialog` portalled to document root | `surface-glass`, `z-command`, focus-trapped, `Esc`-closes |
| Input | `CommandInput` | `role="combobox"`, `aria-expanded`/`aria-controls`, auto-focused on open |
| Group | `CommandGroup` with heading | Navigate → Records → AI & Actions, stable order |
| Item | `CommandItem` / `SearchResultItem` | `role="option"`; icon + highlighted title (+ amount/status) |
| Separator | `CommandSeparator` | Hairline between groups |
| Empty | `CommandEmpty` | Never a dead end — Navigate matches and "Ask AI" stay offered |

The Navigate source is synchronous and permission-filtered (no network), so "you typed a page name" is the
fastest, most-certain answer and sits above the network-dependent Records group; the AI action sits last,
never first, so the palette never nudges toward an AI answer before a deterministic one exists.

# Variants

`CommandPalette` is a **singleton** — one instance mounted in the authenticated app shell, opened by `⌘K`
or the Topbar trigger pill. It takes no props; its open state and query live in the shell store. Its
behavior varies only by the sources it composes:

| Source | Mechanism | Network | Permission |
|---|---|---|---|
| Navigate | `useNavForPermissions(q)` over the permission-filtered nav tree | none (synchronous) | each nav item's own key; omitted if absent |
| Records | Debounced `GET /api/v1/search?q=&per_type=5` | yes, ≥2 chars | each group server-checked; unreadable groups never return |
| AI & Actions | Static action list + "Ask AI about '{q}'" | on activate | actions by their key; "Ask AI" by `ai.ask` |
| Recent | Client-only last-5 visited routes | none | only routes the user could reach |

The Topbar `CommandPaletteTrigger` (a pill showing `Search or jump to…` + a `⌘K` hint) and the keyboard
shortcut flip the *same* store flag — one open path, two entry points.

# Props / API

The palette is propless; the reusable pieces it composes carry the surface. Full tables in
[`../../frontend/components/SEARCH_BAR.md`](../../frontend/components/SEARCH_BAR.md).

```tsx
// components/shared/command-palette.tsx (design-system shape; grammar in NAVIGATION_SYSTEM.md)
'use client';
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import {
  CommandDialog, CommandInput, CommandList, CommandEmpty, CommandGroup, CommandItem, CommandSeparator,
} from '@/components/ui/command';
import { Sparkles } from 'lucide-react';
import { useShellStore } from '@/stores/shell-store';
import { useDebouncedValue } from '@/hooks/use-debounced-value';
import { useNavForPermissions } from '@/hooks/use-nav-for-permissions';
import { SearchResultItem } from '@/components/search/search-result-item';
import { RecentRoutes } from '@/components/search/recent-routes';
import { Highlight } from '@/components/search/highlight';
import { apiClient } from '@/lib/api-client';
import { useTranslations } from '@/lib/i18n';

export function CommandPalette() {
  const { t } = useTranslations('search');
  const router = useRouter();
  const open = useShellStore((s) => s.commandPaletteOpen);
  const setOpen = useShellStore((s) => s.setCommandPaletteOpen);
  const [query, setQuery] = useShellStore((s) => [s.paletteQuery, s.setPaletteQuery]);
  const debounced = useDebouncedValue(query, 200);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setOpen(!open); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, setOpen]);

  const navMatches = useNavForPermissions(debounced);              // synchronous, permission-filtered
  const { data: records, isFetching } = useQuery({
    queryKey: ['palette', 'records', debounced],
    queryFn: () => apiClient.get('/api/v1/search', { params: { q: debounced, per_type: 5 } }),
    enabled: debounced.trim().length >= 2,                          // 2-char full-text minimum
    staleTime: 30_000,
  });

  return (
    <CommandDialog open={open} onOpenChange={setOpen} label={t('paletteLabel')}>
      <CommandInput value={query} onValueChange={setQuery} placeholder={t('palettePlaceholder')} />
      <CommandList aria-busy={isFetching}>
        {debounced.trim().length === 0 && <RecentRoutes onSelect={(href) => { router.push(href); setOpen(false); }} />}

        {navMatches.length > 0 && (
          <CommandGroup heading={t('groupNavigate')}>
            {navMatches.map((n) => (
              <CommandItem key={n.href} value={`nav:${n.label}`} onSelect={() => { router.push(n.href); setOpen(false); }}>
                <n.icon className="me-2 h-4 w-4 text-ink-9" aria-hidden />
                <Highlight text={n.label} query={debounced} />
              </CommandItem>
            ))}
          </CommandGroup>
        )}

        {records?.groups?.length ? (
          <>
            <CommandSeparator />
            {records.groups.map((g) => (
              <CommandGroup key={g.type} heading={t(`type.${g.type}`)}>
                {g.items.map((item) => (
                  <SearchResultItem key={item.id} item={item} query={debounced}
                    onSelect={(href) => { router.push(href); setOpen(false); }} />
                ))}
              </CommandGroup>
            ))}
          </>
        ) : null}

        <CommandSeparator />
        <CommandGroup heading={t('groupAiActions')}>
          {debounced.trim().length >= 2 && (
            <CommandItem value="ai:ask" onSelect={() => router.push(`/search?q=${encodeURIComponent(debounced)}&ask=1`)}>
              <Sparkles className="me-2 h-4 w-4 text-accent" aria-hidden />
              {t('askAiAbout', { q: debounced })}
            </CommandItem>
          )}
        </CommandGroup>

        <CommandEmpty>{t('noResults')}</CommandEmpty>
      </CommandList>
    </CommandDialog>
  );
}
```

`SearchResultItem` and `Highlight` are the shared render pieces (their props are specified in
[`SEARCH.md`](./SEARCH.md)); a record result links to the *canonical filtered URL the owning screen would
produce*, not a bespoke detail route.

# States

| State | Palette treatment |
|---|---|
| Idle (empty query) | Recent routes (last 5) + suggested starter actions; **no network fired** |
| Typing, below 2 chars | Navigate matches only (synchronous); no Records call |
| Searching | `CommandList` sets `aria-busy`; a subtle inline "Searching…" under the Records heading; prior results kept via cache to avoid flash |
| Results | Grouped Navigate → Records → AI & Actions, each with `Highlight` on matched text |
| Empty (no matches) | `CommandEmpty` — "No results for '{q}'" **plus** the still-offered "Ask AI about '{q}'" and any Navigate matches |
| RBAC-empty | A role with zero readable record types still gets a working palette (Navigate + Ask AI), never an access wall |
| One-group error | The failed source degrades to an inline "Couldn't load {type} results" row; Navigate and Ask AI stay usable |
| AI unavailable (`503`) | The "Ask AI" item renders a distinct "temporarily unavailable" disabled state, not an infinite spinner |

The idle state is the common `⌘K` use — "take me back," not search — so it shows Recent first and fires no
query until the user types.

# Tokens Used

| Role | Token | Where |
|---|---|---|
| Overlay surface | `surface-glass` (`ink-1` @72% + `backdrop-blur(24px)`, `ink-6`@60% border, `shadow-md`) | The dialog — one of only two glass surfaces in the app |
| Scrim | `ink-12` @ 50% | Behind the dialog |
| Item text / icons | `ink-11`, `ink-9` | Result titles, group headings, leading icons |
| Row hover / active | `ink-4` / `ink-5` | cmdk roving highlight |
| Match highlight | `accent-subtle` bg + `accent` text (the `<mark>`) | `Highlight` — the one place accent marks a match |
| AI action | `accent` | The "Ask AI" `Sparkles` icon |
| Amount / status in a row | plain `ink` tabular numerals; `StatusPill` tone | `SearchResultItem` trailing cells |
| Radius | `radius-lg` (dialog inner), `radius-md` (rows) | — |
| Motion | `motion.moderate` / `easeOut` (fade+scale entrance) | Reduced-motion-gated to instant |
| Z-index | `z-command` (60) | Above modals and toasts, below tooltips |

`accent` appears in exactly two places — the highlighted-match `<mark>` and the "Ask AI" icon — both
legitimate under the rule that accent marks the primary action or an AI-touched element
([`../DESIGN_TOKENS.md → Color — Accent`](../DESIGN_TOKENS.md)). A result row's status uses its
`StatusPill` tone, never accent as decoration; amounts render in plain `ink`, never washed by polarity.

# Accessibility

The palette inherits cmdk/Radix's combobox/listbox wiring and must preserve it when re-skinned.

| Element | Keyboard | Screen reader |
|---|---|---|
| Dialog | `⌘K`/`Ctrl+K` opens, `Esc` closes; focus trapped, returns to trigger on close | Labelled `role="dialog"` |
| `CommandInput` | Standard text-input keys; auto-focused on open | `role="combobox"` with `aria-expanded`/`aria-controls` |
| Result list | `↓`/`↑` roving focus **across** group boundaries (one flattened list); `Home`/`End` first/last; `Enter` activates | `role="listbox"` of `role="option"` rows (cmdk-wired) |
| Group headings | Order (Navigate → Records → AI) is meaningful and stable | Each heading is a group label read before its options |
| Search-in-progress | — | `CommandList` sets `aria-busy="true"`; a visually-hidden `aria-live="polite"` region announces count changes ("6 results") |
| `Highlight` | — | The `<mark>` carries no separate announcement; the full title reads as one string, never fragmenting the accessible name; bidi-safe — never splits an Arabic ligature |
| Empty / RBAC-empty | The still-offered "Ask AI" / Navigate items are the reachable next actions | A labelled region with a heading, never a silent blank list |

Meets the **WCAG 2.1 AA** floor; the dialog's focus management comes from Radix/cmdk and is preserved,
never stripped. Under `prefers-reduced-motion` the fade/scale entrance, the AI "thinking" dots, and any
result stagger collapse to instant state changes.

# Theming, Dark Mode & RTL

- **The palette is `surface-glass`** — one of only two frosted-glass surfaces in the whole app (the other
  being the AI Command Center overlay), because it floats conceptually above the entire app rather than
  belonging to a page ([`../DESIGN_TOKENS.md → Elevation & Surfaces`](../DESIGN_TOKENS.md)). Everything
  else is ink-scale surface; the glass is intentionally rare because `backdrop-blur` is not free on
  back-office hardware.
- **Dark mode is a token remap only.** The glass tint, the highlight `<mark>`, and the row hover resolve to
  their dark values with no `dark:` raw-color variant; the raised dialog is *lighter* than the canvas per
  the elevation-lightens rule; the blur is kept performant.
- **RTL mirrors via logical properties.** The input's leading search icon and trailing `Esc`/shortcut hint
  use `ps-*`/`pe-*`; result-item icons use `me-2`; a row's trailing amount/status sits at the inline-end.
  The palette fades/scales in place (no directional slide), so it needs no mirroring beyond its logical
  layout. The `Search` glyph itself never mirrors.
- **Amounts, codes, and dates inside results never mirror** — a result's amount stays `dir="ltr"` `latn`,
  a currency code stays Latin, even inside an Arabic result row, per the numeral rule. **Result titles are
  bilingual data, not translations**: `title_en`/`title_ar` render by locale, so an Arabic-UI user can type
  an English account name and still match.

# Do / Don't

| Do | Don't |
|---|---|
| Mount one palette singleton in the app shell | Wire `⌘K` per screen or mount multiple instances |
| Keep Navigate → Records → AI & Actions order stable | Float an AI answer above a deterministic Navigate match |
| Fire no network on idle or below 2 chars | Query per keystroke or on an empty input |
| Filter Navigate and actions by permission before render | Show a disabled action a role lacks — its mere presence is information |
| Link a record to its canonical filtered URL | Invent a bespoke detail route for a search result |
| Keep `accent` to the `<mark>` and the Ask-AI icon | Use accent as row decoration or a status color |
| Preserve cmdk/Radix focus trap and roving focus | Strip the dialog's ARIA when re-skinning the glass surface |
| Degrade one failed source to an inline row | Blank or whole-error the palette when one fan-out fails |

# Usage & Composition

**Mount the singleton once** in the authenticated layout so `⌘K` works on every route:

```tsx
// app/(app)/layout.tsx (excerpt)
import { CommandPalette } from '@/components/shared/command-palette';
import { CommandPaletteTrigger } from '@/components/layout/command-palette-trigger';

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <AppShell topbar={<><CommandPaletteTrigger className="me-auto" />{/* … */}</>}>
      {children}
      <CommandPalette />   {/* portalled to the document root; opened by ⌘K or the Topbar trigger */}
    </AppShell>
  );
}
```

**The "Ask AI" hand-off is context, never a competing input.** Activating the AI item opens `/search`
pre-seeded with `q` and `ask=1` rather than answering inline — the answer renders on a surface that can
show reasoning, citations, and a follow-up composer (the `SearchAiAnswerCard`, which composes the same
`ConfidenceBadge` + `ReasoningPanel` primitives from [`AI_WIDGET.md`](./AI_WIDGET.md)). A search keystroke
posts nothing; below the confidence threshold the answer is still shown but its "do it" is withheld exactly
as [`AI_WIDGET.md → The confidence band table`](./AI_WIDGET.md) requires.

**Two surfaces, one machinery.** The palette fans out to per-module endpoints capped at ~5 rows for a fast
preview; the `/search` results page ([`SEARCH.md`](./SEARCH.md),
[`../../frontend/SEARCH.md`](../../frontend/SEARCH.md)) uses the aggregate endpoint for the deep,
deep-linkable view. A record result the user has since lost access to yields the server's `403`, caught by
the route's `error.tsx` into an explanatory `ErrorState` — search is a faster path to the permitted
surface, never a wider one.

For the full search grammar, ranking, debounce path, and RBAC belt-and-braces, see
[`../../frontend/components/SEARCH_BAR.md`](../../frontend/components/SEARCH_BAR.md) and
[`../../frontend/NAVIGATION_SYSTEM.md`](../../frontend/NAVIGATION_SYSTEM.md).

# End of Document
