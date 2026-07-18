# Help Center & Support — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: HELP_CENTER
---

# Purpose

Help Center is QAYD's single, universally-reachable answer to "how do I do this" and "something is
wrong" — the one surface, alongside Global Search (`docs/frontend/SEARCH.md`) and Notifications
(`docs/frontend/NOTIFICATIONS.md`), that every seat in every company can open regardless of role,
plan tier, or onboarding stage. It ships as a full destination (`app/(app)/help`) for deliberate
browsing — searchable articles and guides in English and Arabic, an AI help assistant, guided product
tours, an onboarding checklist, a keyboard-shortcuts reference, release notes, system status, and a
contact-support flow — and as a **contextual help drawer**, a lighter, per-screen companion reachable
from any route without losing the user's place. The two are one product with two entry points, never
two separately maintained implementations: the drawer's "Suggested articles for this screen" and the
full Help Center's category browser both read the exact same article corpus through the exact same
endpoint family, and the drawer's "Ask about this screen" composer and the full Help Center's AI
assistant panel both mount the identical `AssistantView` client component `docs/frontend/AI_CHAT.md`
already specifies, seeded differently.

This document's founding architectural decision, stated here once because every later section depends
on it: **Help Center's "articles and guides" are not a parallel content model — they are QAYD's own
Knowledge Memory** (`docs/ai/memory/KNOWLEDGE_MEMORY.md`), specifically the rows where
`domain = 'platform_howto'` and `document_type IN ('howto_guide', 'glossary_entry')`. Knowledge Memory
already defines exactly the schema a help center needs — versioned, bilingual-capable, curator-authored,
citation-bearing documents (`knowledge_documents`) broken into retrievable, embedded passages
(`knowledge_chunks`) — and it already defines the governance (a **Platform Content Curator** drafts,
a **Platform Compliance Reviewer** is not required for `howto_guide`/`glossary_entry` content per that
document's own dual-control table, so a single curator may publish platform how-to content), the
retrieval mechanism (hybrid vector + keyword search, effective-dated, jurisdiction-filtered — trivially
satisfied here since `jurisdiction = '*'` for all platform how-to content), and the citation contract
(a concrete `knowledge_chunk_id` plus a human-readable `citation_text`) every other AI-authored surface
in QAYD already honors. Building a second, parallel `help_articles` table would duplicate a schema that
already exists for exactly this purpose and would fragment "what does QAYD say about closing a fiscal
period" across two unrelated corpora — one for agents reasoning about accounting standards and payroll
law, one for a human reading a help article — when a company's own Finance Manager asking the AI
assistant "how do I close a period" and that same Finance Manager clicking through to the how-to guide
from the Help Center should, and now do, land on the identical passage. Everywhere below, "article"
means a rendering of one or more `knowledge_documents`/`knowledge_chunks` rows; "product docs" in the
phrase "grounded in Knowledge Memory and product docs" refers to this exact same published corpus —
QAYD's internal engineering specification tree (`docs/frontend/*`, `docs/accounting/*`) is never a
citation source for an end user, only for the AI coding agents and engineers who build the product.

Three platform-wide rules bind this screen precisely because Help Center is where a violation of any
one would be most visible. First, **the frontend decides nothing about content authority** — which
article is current, which is superseded, which citation is trustworthy is entirely a Knowledge Memory
write-path decision (`KNOWLEDGE_MEMORY.md → Write Path & Governance`); this screen only renders
`published` content and never lets a tenant user edit, rate down to invisibility, or otherwise mutate
the shared corpus (a tenant's own "was this helpful?" signal is feedback, not an edit — see
`# Data & State`). Second, **AI is visible, cited, and confidence-scored here exactly as everywhere
else** — the Help AI assistant is not a lobotomized, sandboxed chatbot with a smaller vocabulary; it is
the same CEO Assistant orchestrator `docs/ai/agents/CEO_AGENT.md` and `docs/frontend/AI_CHAT.md`
already specify, reached through the identical `/api/v1/ai/chat` contract, merely seeded with context
that biases its tool selection toward `knowledge.search(domain='platform_howto')` rather than a
company's ledger — a question that genuinely needs tenant financial data ("why is my cash balance
low") still gets a real, fully-capable answer from here, because there is exactly one assistant in
QAYD, never a lesser one behind the help icon. Third, **Help Center content and a company's own
tenant data are on opposite sides of the isolation line** — articles, tours, the changelog, and system
status are platform-wide and identical for every company (no `company_id` anywhere in this document's
own data), while support tickets and per-user tour/checklist progress are personal and tenant-scoped
exactly the way `ai_conversations` is personal per `AI_CHAT.md` ("Mariam's conversation... and Fahad's
conversation... are fully independent rows"). Confusing the two — showing one company's support ticket
to another, or scoping an article's *content* by company the way a chart of accounts is scoped — would
be exactly the failure mode `docs/database/MULTI_TENANCY.md` and `KNOWLEDGE_MEMORY.md → Per-Company
Isolation` both exist to prevent, applied in the opposite direction from where it is usually applied.

**A note on scope reconciliation with three sibling documents**, following the same practice
`docs/frontend/AI_CHAT.md` and `docs/frontend/SEARCH.md` already established for naming and resolving
an overlap rather than silently duplicating or contradicting it:

1. `docs/frontend/NAVIGATION_SYSTEM.md → Topbar composition, start to end` fixes the Topbar's element
   order as Mobile menu trigger → Breadcrumb → Command Palette trigger → AI status indicator →
   Notifications bell → Theme toggle → User menu, written before this document existed. This document
   adds exactly one element, `HelpTrigger` (a `CircleHelp` icon button), immediately before
   `ThemeToggle`: `… AiStatusIndicator → NotificationsBell → HelpTrigger → ThemeToggle → UserMenu`.
   `NAVIGATION_SYSTEM.md`'s own Topbar composition list should adopt this row verbatim the next time
   that document is revised, the same way `docs/frontend/ONBOARDING.md` flagged its Wizard Template
   "for later adoption into `LAYOUT_SYSTEM.md`."
2. `docs/frontend/ACCESSIBILITY.md → Global keyboard shortcuts` already fixes `?` as the shortcut that
   "opens the Keyboard Shortcuts help dialog" and reserves the `G` chord for `G D`/`G A`/`G L`/`G B`/
   `G R`/`G I`. This document does not repurpose `?` — the dialog it opens (`KeyboardShortcutsDialog`,
   `# Components Used`) is specified here in full, and both it and the full `/help/shortcuts` page
   render from one shared `SHORTCUT_REGISTRY` (`# Data & State`) so the two surfaces can never drift out
   of sync with each other or with `ACCESSIBILITY.md`'s own table. This document additionally extends the
   `G` chord table with `G` then `H` → Help Center home, a row `ACCESSIBILITY.md` should adopt verbatim on
   its own next revision.
3. `docs/frontend/SEARCH.md`'s aggregate `GET /api/v1/search` already defines a group named **"Knowledge
   (AI)"** that indexes `ai_decisions`/`ai_risk_flags` — AI-generated insights and recommendations, not
   Knowledge Memory's `knowledge_documents` corpus this document is built on. To avoid two unrelated
   things both being called "Knowledge" in the same aggregate response, this document adds a *distinct*
   new group, `help_articles` (label "Help & Guides"), to `SEARCH.md`'s `SearchResultType` union and
   `/api/v1/search` response (`# Data & State`) — `SEARCH.md`'s own existing "Knowledge (AI)" group is
   untouched and unrenamed; the two now sit side by side in the same palette and results page, clearly
   labeled, never conflated.
4. `docs/frontend/ONBOARDING.md → Interactions & Flows → Invite Team` explicitly planted a forward
   reference: "a persistent 'Finish setting up your team' prompt Dashboard's own Recent Activity-adjacent
   chrome can surface (out of this document's scope, noted for `DASHBOARD.md`'s own future edge-case
   consideration)." This document is where that reference resolves: the **Onboarding Checklist**
   (`# Layout & Regions`, `# Interactions & Flows`) is that exact prompt, generalized to every skippable
   onboarding step, reading the identical `GET /api/v1/onboarding/progress` endpoint `ONBOARDING.md`
   already defines — never a second progress computation — and is additionally mirrored, in condensed
   form, inline in Dashboard's own Recent-Activity-adjacent chrome per that flagged note.

# Route & Access

```
app/(app)/help/
├── layout.tsx                       # Shared chrome: search header, category rail, breadcrumb root
├── page.tsx                         # Home: search-or-browse, popular articles, tours, checklist,
│                                     #   changelog teaser, status summary, contact CTA (see below)
├── loading.tsx
├── categories/
│   └── [category]/page.tsx          # One category's article list (List Page Template)
├── articles/
│   └── [code]/page.tsx              # Article detail — renders a knowledge_documents row's chunks
│       └── loading.tsx
├── tours/
│   └── page.tsx                     # All guided tours — start / replay / completed
├── shortcuts/
│   └── page.tsx                     # Full keyboard-shortcuts reference (same registry as `?`)
├── changelog/
│   └── page.tsx                     # What's New — dated, tagged release notes
├── status/
│   └── page.tsx                     # System status (wraps the public `/api/v1/status`)
└── contact/
    ├── page.tsx                     # New support ticket form
    └── tickets/
        ├── page.tsx                 # The caller's own tickets
        └── [ticketId]/page.tsx      # One ticket's thread
```

Two components are **not routes** — they are portalled overlays mounted once by `app/(app)/layout.tsx`,
exactly as `CommandPalette` already is (`NAVIGATION_SYSTEM.md`):

| Component | Trigger | Mounted |
|---|---|---|
| `ContextualHelpDrawer` | `HelpTrigger` icon in the Topbar (new, see above); `G` then `H` opens the full `/help` page instead — the drawer is reached only by click/tap or its own dedicated shortcut below | `app/(app)/layout.tsx`, portalled at `z-(--z-help-drawer)`, one step below the Command Palette's `z-(--z-command-palette)` per `LAYOUT_SYSTEM.md`'s z-index scale (a drawer must never fight the palette for stacking) |
| `KeyboardShortcutsDialog` | `?` (global, per `ACCESSIBILITY.md`) | `app/(app)/layout.tsx`, same portal root |
| `ProductTourOverlay` | Programmatic — a tour's own "Start"/"Replay" action, or a one-time auto-prompt on a user's first visit to a route with an available, not-yet-seen tour (see `# Interactions & Flows`) | `app/(app)/layout.tsx` — must render above any screen's own content regardless of which route is active |

**Gating permission.** Matching `docs/frontend/DASHBOARD.md`'s and `docs/frontend/SEARCH.md`'s own
precedent ("no single gating permission... every authenticated member of a company can open it"), Help
Center carries no feature-tier or role gate at all. `help.read` exists in the permission grammar purely
for consistency with the platform-wide `<area>.<action>` convention (`docs/foundation/PERMISSION_SYSTEM.md`)
and is granted to literally every account, including a user mid-Onboarding who has not yet finished
`ONBOARDING.md`'s required steps — a brand-new Owner stuck on the Chart of Accounts step must be able to
ask "what does a control account mean" without first finishing setup. The one exception is **support
tickets**, gated by ownership rather than role: any authenticated user may create and read their own
tickets; nobody, regardless of role, reads another user's ticket thread through this screen (a Finance
Manager wanting visibility into a teammate's ticket goes through the ticket's own email/portal thread,
out of this document's scope).

| Capability | Permission | Notes |
|---|---|---|
| Browse categories, read articles, use the AI assistant, view tours/changelog/status, use the contextual drawer | `help.read` | Universal — every role, every plan tier, every onboarding stage |
| Start/replay/dismiss a guided tour, mark checklist items | `help.tours.manage` | Universal — this is per-user progress, not a company setting |
| Create a support ticket | `help.support.create` | Universal |
| View a ticket thread | Row ownership (`support_tickets.user_id = auth()->id()`) — no separate permission key | A ticket has no company-wide visibility even for the Owner, unless the filer explicitly shares it |
| Rate an article "helpful"/"not helpful" | `help.read` (no separate permission — feedback is as open as reading) | See `# Data & State` for how this differs from `knowledge_citation_feedback` |
| Ask the AI assistant a question that needs tenant financial data (e.g. "why is my AR aging so high") | `ai.chat`, plus whatever tool-level permission the orchestrator's underlying call requires, exactly as `AI_CHAT.md` already specifies | The Help-scoped composer is not a reduced-permission surface; a Warehouse Employee gets exactly the answer their own permissions allow anywhere else in QAYD |

**Breadcrumb.** Help Center is not one of the ten primary-nav modules `NAVIGATION_SYSTEM.md`'s module
map fixes (Dashboard, Accounting, Banking, Sales, Purchasing, Inventory, Payroll, Tax, Reports, AI) —
like Search, Notifications, and Settings, it sits outside that map, reached from the Topbar, the
Command Palette, and, on mobile, the bottom tab bar's **More** destination (`Dashboard, Accounting,
Banking, Search, More` — `docs/frontend/SEARCH.md`'s own quoted mobile wireframe). Its breadcrumb root
is `Help` (non-linked, IA-root sibling to `Dashboard` and `Search`), with `Help / {Category}` and
`Help / {Category} / {Article title}` as a user drills in — the article title is the article's own
localized `title_en`/`title_ar`, never the literal `[code]` segment, matching the `generateBreadcrumb`
pattern `NAVIGATION_SYSTEM.md`'s Journal Entry example already establishes.

**Company/branch scope.** Every route in this document except `contact/*` is **company-agnostic** —
switching companies never navigates a user away from an open article or resets their tour progress,
because Knowledge Memory's `platform_howto` domain and `user_tour_progress` are not scoped by
`company_id` at all (tour progress is scoped to the user, matching `ai_conversations.user_id NOT NULL`
being personal rather than company-wide, per `AI_CHAT.md`). This is a deliberate, stated exception to
`docs/frontend/FRONTEND_ARCHITECTURE.md → Company switching`'s general rule that a company switch
clears the query cache and abandons the current screen — that rule protects *tenant data* from bleeding
across a switch; Help Center's own content has no tenant dimension to bleed. What *does* reset on a
company switch is the AI assistant's tool-calling scope (a question requiring the new company's own
figures is answered against the new `X-Company-Id`, per the platform-wide rule) and the contextual
drawer's suggestions (re-derived from the current route, unaffected by which company is active).
Support tickets, by contrast, do carry `company_id` (a ticket is filed as "Mariam, at Al-Noor Trading,
asks about—"), so the `contact/tickets` list is company-scoped like any ordinary tenant list.

# Layout & Regions

## Help Center home (`/help`)

```
Desktop / lg+
┌─────────────────────────────────────────────────────────────────────────────┐
│  Help                                                          [EN ▾] [☾]   │
│                                                                                │
│           How can we help? [ Search articles, guides, shortcuts…      🔍 ]    │
│                                                                                │
│  ┌ Getting Started ┐ ┌ Accounting ┐ ┌ Banking ┐ ┌ Payroll ┐ ┌ AI & Automation┐│
│  │ 12 articles      │ │ 34 articles│ │16 articles│ │19 articles│ │ 9 articles  ││
│  └──────────────────┘ └────────────┘ └──────────┘ └───────────┘ └─────────────┘│
│  ┌ Sales ┐ ┌ Purchasing ┐ ┌ Tax ┐ ┌ Account & Billing ┐ ┌ Troubleshooting ┐   │
│  └───────┘ └────────────┘ └─────┘ └───────────────────┘ └──────────────────┘  │
├──────────────────────────────────────────────────────┬───────────────────────┤
│  Popular articles                          See all →  │ ✦ Ask AI              │
│  ▸ How closing a fiscal period works                   │ "Ask anything about   │
│  ▸ Reading your close-readiness score                  │  how QAYD works"      │
│  ▸ What is a reversing entry?                          │ [ Ask a question…  ]  │
│  ▸ Setting up bank reconciliation matching rules       │                       │
├────────────────────────────────────────────────────────┤ Finish setting up     │
│  Guided tours                              See all →  │ your company           │
│  [▶ Journal Entries basics · 4 min] [▶ Bank Reconcil…] │ ● Team — skipped      │
├────────────────────────────────────────────────────────┤ ● Bank — pending      │
│  What's new                                 See all →  │ [ Continue setup → ]  │
│  Jul 14 — Bank reconciliation now suggests matches      │                       │
├────────────────────────────────────────────────────────┴───────────────────────┤
│  All systems operational · Status                          [ Contact support ]│
└─────────────────────────────────────────────────────────────────────────────────┘
```

`page.tsx` is a Server Component instantiating a sixth, narrower composition of `LAYOUT_SYSTEM.md`'s
existing **Dashboard Template** shape (KPI-strip-equivalent → wide region → narrow rail → secondary
row) rather than a new seventh template: the search header stands in for the KPI strip, the Popular
Articles / Guided Tours / What's New stack is the 8/12 wide region, and the Ask AI + Onboarding
Checklist column is the 4/12 rail — the same proportions `docs/frontend/DASHBOARD.md`'s own Chart
Region / Insights Feed split already uses. Submitting the search field (Enter, or 2+ characters with a
200ms debounce, identical to `SEARCH.md`'s own threshold) replaces the Categories grid and the three
"popular/tours/what's new" stacks with search results **in place**, never navigating to a second route
— matching `SEARCH.md`'s own "`/search` never runs a query the user did not ask for... Results column
replaced entirely" pattern precisely, just scoped to one corpus instead of ten.

```
Search active (`/help?q=fiscal+period`)
┌─────────────────────────────────────────────────────────────────────────────┐
│  Help                                                                        │
│           [ fiscal period                                              🔍 ]  │
│  [All 6] [Articles 4] [Glossary 1] [Shortcuts 1]                              │
├──────────────────────────────────────────────────────┬───────────────────────┤
│  ARTICLES                                  See all →  │ ✦ Closing a fiscal    │
│  ▸ How closing a fiscal period works                  │   period locks every  │
│  ▸ Reopening a closed period — what changes            │   posted entry in    │
│  ▸ Year-end close checklist                            │   that range so no   │
│  ▸ Fiscal period vs. fiscal year — what's the difference│  further posting     │
├──────────────────────────────────────────────────────┤  can happen without   │
│  GLOSSARY                                              │  a reversing entry   │
│  ▸ Fiscal period                                       │  [1]. 91% confidence │
├──────────────────────────────────────────────────────┤  ───────────────────  │
│  SHORTCUTS                                             │  [1] How closing a   │
│  ▸ `G` then `A` — Go to Accounting                     │  fiscal period works │
└──────────────────────────────────────────────────────┴───────────────────────┘
```

## Article detail (`/help/articles/[code]`)

A Detail Page Template variant, deliberately narrower than a financial record's own Detail Page (no
Summary Rail of "key facts," no Activity Timeline — an article has no lifecycle status to show):

| Region | Content |
|---|---|
| Page Header | Category breadcrumb, article title (localized), "Last reviewed {date}" from `knowledge_documents.reviewed_at`, a language toggle if the Arabic sibling exists (`# Edge Cases`) |
| Main Column (8/12) | The article body — one block per `knowledge_chunks` row in `chunk_index` order, each optionally carrying its own `section_label` as an in-page anchor heading |
| Contents Rail (4/12) | An auto-generated in-page table of contents (from `section_label`s), a compact `HelpAiAnswerCard` seeded with this article's own `document_id` ("Ask about this article"), and a "Was this helpful?" control |
| Footer | Related articles (same category, or explicitly cross-referenced), a "Still need help? Contact support" link pre-filling the ticket form's category from this article's own category |

## Contextual Help Drawer

A `Sheet` sliding from the inline-end edge (`right` in LTR, `left` in RTL, matching `Sheet`'s documented
direction rule in `docs/frontend/COMPONENT_LIBRARY.md`), `400px` wide at `lg`+, full-screen below it —
the same width class the AI Rail and Search's docked Ask AI variant already use, so a user's spatial
model of "a companion panel slides in from this edge" stays consistent app-wide.

```
┌──────────────────────────────────────┐
│  Help for: Journal Entries       [×] │
├───────────────────────────────────────┤
│  [ Ask about this screen…         ]  │
│                                        │
│  Suggested for this screen             │
│  ▸ How closing a fiscal period works   │
│  ▸ Reviewing an AI-drafted entry       │
│  ▸ Live debit/credit balance checks    │
│                                        │
│  ▶ Replay the Journal Entries tour     │
│                                        │
│  Shortcuts on this screen               │
│  N — New entry   A — Approve focused   │
│                                        │
│  ───────────────────────────────────  │
│  Open full Help Center →               │
│  Contact support →                     │
└──────────────────────────────────────┘
```

The drawer never duplicates content — "Suggested for this screen" is a filtered slice of the same
`/api/v1/help/articles` list the category browser reads, keyed by a static `HELP_CONTEXT_MAP` (`# Data
& State`); "Shortcuts on this screen" is a filtered slice of the identical `SHORTCUT_REGISTRY` the `?`
dialog and `/help/shortcuts` both render; "Ask about this screen" mounts the identical `AssistantView`
`AI_CHAT.md` specifies, seeded with `{ surface: "help", route: pathname }`.

## Keyboard Shortcuts (`?` dialog and `/help/shortcuts`)

The `?` dialog is a compact `Dialog` (not a `Sheet` — it is a lookup, not a workspace) grouped exactly
as `ACCESSIBILITY.md`'s own table: Global, Page-scoped, Card-scoped, Dialog-scoped, Grid-scoped, each
group a two-column `key → action` list. A single "View full reference" link at the bottom opens
`/help/shortcuts` and closes the dialog — the full page renders the identical groups with the addition
of a filter input (search by action name) and a "Print" affordance (`window.print()`, styled via a
print stylesheet that strips chrome, useful for a training handout), because a lookup dialog a user
reaches for mid-task and a reference page a training lead prints for a new hire are different jobs
served by the same data.

## Guided Product Tours (`/help/tours` and the in-context overlay)

`/help/tours` is a List Page Template variant rendered as a card grid rather than a table (a handful of
tours, not hundreds of rows): each card shows the tour's title, its target route, a duration estimate,
and a status pill (`Not started` / `In progress` / `Completed` / `Skipped`), with **Start** or **Replay**
as its single action. `ProductTourOverlay` itself is not a page — it is a sequence of anchored
`Popover`s (desktop/tablet) or a bottom `Sheet` (mobile, `# Responsive Behavior`) stepping through a
tour's `steps[]`, each step spotlighting one DOM element (`data-tour-target="journal-entries.new-button"`)
with a short caption, a step counter ("3 of 6"), and Back/Next/Skip/Finish controls — modeled directly
on `ONBOARDING.md`'s own Progress Rail node semantics (completed/current/upcoming/skipped) applied to a
five-step walkthrough instead of an eight-step wizard, and never blocking interaction with the rest of
the page beyond a dimmed backdrop a user can dismiss with `Esc` at any step.

## Onboarding Checklist

A single `Card` (not a full page) appearing in the Help Center home's rail and, per the resolved
`ONBOARDING.md` forward-reference above, inline in Dashboard's Recent-Activity-adjacent chrome. It lists
only the **skippable** `ONBOARDING.md` steps (Team, Banking, Import) whose `onboarding_progress.steps[step].status`
is `skipped` or `in_progress` — a step already `completed` is simply absent, and once all three are
resolved (completed or explicitly re-skipped-and-dismissed) the whole card disappears rather than
lingering at "0 remaining."

## What's New (`/help/changelog`)

A List Page Template variant with no filter bar beyond a tag toggle (`Added`/`Improved`/`Fixed`) —
dated entries grouped by month, newest first, each entry a short headline, one-paragraph description,
an optional "Try it" deep link to the relevant screen, and an optional "Read more" link to a fuller
Help Center article when the change is substantial enough to warrant one (a new reconciliation-matching
algorithm links to its own how-to article; a small copy fix does not).

## System Status (`/help/status`)

The simplest region in this document by design: a per-subsystem rollup (API, AI Engine, Realtime,
Banking Integrations, Email/SMS) each rendered as a `StatusPill` (`operational`/`degraded`/`outage`),
sourced from the existing public `GET /api/v1/status` (`docs/api/API_MONITORING.md`), plus a link out to
QAYD's public status page for incident subscription — this screen renders a summary, it is not itself
the incident-communication system of record.

## Contact Support (`/help/contact`)

A Form Page Template instance: Category (select, pre-filled from context — the article or screen the
user came from), Subject, Description (textarea), Severity (`Question` / `Something's not working` /
`Blocking my work` — deliberately no self-service "Critical/Emergency" tier; a genuine outage is
reported via the Status page's own incident channel, not a queued ticket), and a read-only "Diagnostics
attached" summary (company, role, browser/OS, app version, current route, and the most recent
`request_id` from a failed API call in this session, if any — see `# Data & State`). Submission returns
a ticket number and redirects to `/help/contact/tickets/{id}`.

# Components Used

Every visual element in this document is drawn from `docs/frontend/COMPONENT_LIBRARY.md`'s existing
catalogue or `docs/frontend/AI_CHAT.md`'s existing AI-chat component set, composed into a small set of
new, Help-scoped components living in `components/help/`, matching
`docs/foundation/PROJECT_STRUCTURE.md`'s one-subfolder-per-module convention. As with
`docs/frontend/SEARCH.md`'s own stated discipline, nothing here introduces a new design token, a new
confidence indicator, or a new citation style — a screen-specific need is a narrow, documented
composition of existing primitives, never a parallel reimplementation.

| Component | Source | Role on this screen |
|---|---|---|
| `Command`, `CommandDialog`, `CommandInput`, `CommandList`, `CommandGroup`, `CommandItem`, `CommandEmpty` | `components/ui/command.tsx` (existing) | `KeyboardShortcutsDialog`'s filterable list; the Help home's search-as-you-type affordance reuses the same `cmdk`-backed input pattern (not the palette itself — Help's own search is a full, dedicated results view, not an overlay) |
| `Tabs` | `components/ui/tabs.tsx` (existing) | Search-results Type Tabs (`All / Articles / Glossary / Shortcuts`); the changelog's tag toggle |
| `Card` | `components/ui/card.tsx` (existing) | Category tiles, popular-article rows, tour cards, the Onboarding Checklist card |
| `Sheet` | `components/ui/sheet.tsx` (existing) | `ContextualHelpDrawer`; the mobile rendering of `ProductTourOverlay`'s steps; the mobile ticket-attachment picker |
| `Dialog` | `components/ui/dialog.tsx` (existing) | `KeyboardShortcutsDialog` |
| `Popover` | `components/ui/popover.tsx` (existing) | `ProductTourOverlay`'s desktop/tablet anchored step bubbles; citation hover previews in `HelpAiAnswerCard` |
| `StatusPill` | `components/shared/status-pill.tsx` (existing) | Tour status (`not_started`/`in_progress`/`completed`/`skipped`), ticket status (`open`/`awaiting_you`/`resolved`), system-status rollup rows |
| `Badge` | `components/ui/badge.tsx` (existing) | Changelog tags (`Added`/`Improved`/`Fixed`), the "unread" dot on `HelpTrigger` |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` (existing) | `HelpAiAnswerCard`'s confidence pill |
| `AiCardShell` | `components/ai/ai-card-shell.tsx` (existing) | The mandatory colored-border-plus-badge envelope every AI answer on this screen renders through |
| `ReasoningDisclosure` | `components/ai/reasoning-disclosure.tsx` (existing) | Collapsed-by-default expansion of the AI assistant's retrieval trace |
| `useChat` (Vercel AI SDK) | Reused verbatim from `app/(app)/assistant/*` per `AI_CHAT.md` | Powers both the docked drawer/rail composer and the full `/assistant` hand-off — no second chat implementation anywhere in this document |
| `EmptyState`, `ErrorState` | `components/shared/*` (existing) | Per-region empty/error rendering — see `# States` |
| `PermissionGate` / `Can` | `components/shared/permission-gate.tsx`, `components/auth/can.tsx` (existing) | Wraps the AI answer's any "Do it"/"Send for approval" affordance on the rare occasion a Help answer resolves to an actionable item (see `# AI Integration`) |
| `Form`, `Input`, `Textarea`, `Select`, `RadioGroup` | `components/ui/*` (existing, React Hook Form + Zod wired) | `ContactSupportForm` |
| `HelpTrigger` | `components/help/help-trigger.tsx` (**new**) | The Topbar icon button; carries the unread-changelog dot |
| `ContextualHelpDrawer` | `components/help/contextual-help-drawer.tsx` (**new**) | The per-screen `Sheet` described in `# Layout & Regions` |
| `KeyboardShortcutsDialog` | `components/help/keyboard-shortcuts-dialog.tsx` (**new**) | The `?`-triggered compact reference |
| `ShortcutsReferenceTable` | `components/help/shortcuts-reference-table.tsx` (**new**) | Shared rendering body for both the dialog and `/help/shortcuts`, reading one `SHORTCUT_REGISTRY` |
| `HelpSearchInput` | `components/help/help-search-input.tsx` (**new**) | The home page's large search field; a Help-scoped sibling of `GlobalSearchInput` (`SEARCH.md`), sharing its debounce hook |
| `HelpCategoryGrid` | `components/help/help-category-grid.tsx` (**new**) | The category tiles on `/help` |
| `HelpArticleList` | `components/help/help-article-list.tsx` (**new**) | Popular articles, category listings, and search-results article rows — one component, three call sites |
| `HelpArticleBody` | `components/help/help-article-body.tsx` (**new**) | Renders an article's ordered `knowledge_chunks` into sectioned prose with anchor headings |
| `ArticleFeedbackWidget` | `components/help/article-feedback-widget.tsx` (**new**) | The "Was this helpful?" control |
| `HelpAiAnswerCard` | `components/help/help-ai-answer-card.tsx` (**new**) | Composes `AiCardShell` + `ConfidenceBadge` + inline citations, a narrow sibling of `SEARCH.md`'s own `SearchAiAnswerCard`, scoped to the `platform_howto` corpus |
| `ProductTourOverlay` | `components/help/product-tour-overlay.tsx` (**new**) | The global, portalled step-through overlay |
| `TourCard` | `components/help/tour-card.tsx` (**new**) | One tour's card on `/help/tours` |
| `OnboardingChecklistCard` | `components/help/onboarding-checklist-card.tsx` (**new**) | Reads `onboardingKeys.progress()` (existing, from `ONBOARDING.md`) — resolves the forward reference in `# Purpose` |
| `ChangelogList` | `components/help/changelog-list.tsx` (**new**) | Dated, tagged release-note entries |
| `SystemStatusPanel` | `components/help/system-status-panel.tsx` (**new**) | Wraps `GET /api/v1/status` |
| `ContactSupportForm` | `components/help/contact-support-form.tsx` (**new**) | The ticket-creation form, including the read-only diagnostics summary |
| `TicketThread` | `components/help/ticket-thread.tsx` (**new**) | A single ticket's message history |

# Data & State

## Endpoints

Help Center calls four endpoint families: a new, narrow `help` family this document defines (mirroring
how `docs/frontend/ONBOARDING.md` defines its own minimal `onboarding` family and states plainly that a
fuller surface belongs to a document it does not attempt to write); the existing `knowledge` family
`KNOWLEDGE_MEMORY.md` already specifies in full, called directly by the AI assistant's retrieval step
and indirectly, via the `help` wrapper, by article browsing; the existing `onboarding` family
(`ONBOARDING.md`) reused verbatim for the checklist; and the existing `GET /api/v1/status`
(`docs/api/API_MONITORING.md`) reused verbatim for the status panel.

| Purpose | Endpoint | Permission | Notes |
|---|---|---|---|
| List/browse articles | `GET /api/v1/help/articles` | `help.read` | Params: `category`, `q`, `locale`, cursor pagination. Laravel-side composition over `knowledge_documents`/`knowledge_chunks` filtered to `domain='platform_howto'`, `status='published'` — the same "aggregate wraps the existing per-source logic" pattern `SEARCH.md`'s own `GET /api/v1/search` already uses, not a parallel content query |
| Single article | `GET /api/v1/help/articles/{code}` | `help.read` | `locale` param resolves the `ar` sibling document where one exists (`# Edge Cases`); assembles ordered chunks, `citation_text`, `reviewed_at` |
| Categories | `GET /api/v1/help/categories` | `help.read` | Static-ish taxonomy with live per-category article counts: Getting Started, Accounting, Banking, Sales, Purchasing, Inventory, Payroll, Tax, Reports, AI & Automation, Account & Billing, Troubleshooting |
| Article feedback | `POST /api/v1/help/articles/{code}/feedback` | `help.read` | `{ helpful: boolean, comment?: string }` — a lightweight UX-satisfaction signal, deliberately **not** the same table as `knowledge_citation_feedback` (`KNOWLEDGE_MEMORY.md`'s curator-facing "this citation is outdated/incorrect" queue); a "not helpful" vote here does not itself flag the underlying chunk for curator review, though a repeated pattern of "not helpful" votes on one document is one of the coverage-gap signals a curator's own triage dashboard surfaces, sourced from this table, not from `knowledge_citation_feedback` |
| Search across articles/glossary/shortcuts | `GET /api/v1/help/search` | `help.read` | Convenience aggregate scoped to this document's own corpus, used by `HelpSearchInput`'s in-page results; distinct from `SEARCH.md`'s platform-wide `GET /api/v1/search`, which this document's `help_articles` group (below) also feeds |
| Guided tours — list | `GET /api/v1/help/tours` | `help.read` | Platform-authored, static-ish content (curated by the same Platform Content Curator role `KNOWLEDGE_MEMORY.md` already defines — no new curator role invented here) |
| Guided tours — one tour's steps | `GET /api/v1/help/tours/{id}` | `help.read` | `steps: [{ order, target, title, body, route }]` |
| Tour progress — read | `GET /api/v1/help/tours/progress` | `help.tours.manage` | Per-user (not per-company) map of `tour_id → { status, current_step, completed_at }`, mirroring `ai_conversations.user_id`'s personal-not-shared scoping |
| Tour progress — write | `POST /api/v1/help/tours/{id}/progress` | `help.tours.manage` | `{ status: 'in_progress' \| 'completed' \| 'skipped' \| 'dismissed', current_step }` |
| Onboarding checklist | `GET /api/v1/onboarding/progress` | Ownership rule, per `ONBOARDING.md` | Reused verbatim — not redefined here |
| Changelog | `GET /api/v1/help/changelog` | `help.read` | Cursor-paginated, `tag` filter (`added`/`improved`/`fixed`); each entry optionally carries `related_article_code` and `try_it_href` |
| Mark changelog seen | `PATCH /api/v1/users/me/preferences` | Session only | `{ help_changelog_last_seen_at: <ISO 8601> }` — the identical preferences endpoint `NAVIGATION_SYSTEM.md`'s `ThemeToggle` already writes to, extended with one more key, never a second preferences endpoint |
| System status | `GET /api/v1/status` | None (public) | Reused verbatim from `docs/api/API_MONITORING.md` |
| Knowledge retrieval (AI assistant's own tool call) | `GET /api/v1/knowledge/search?domain=platform_howto` | `knowledge.read` | Called by the FastAPI orchestrator during a Help-scoped conversation, exactly per `KNOWLEDGE_MEMORY.md → Retrieval`; the frontend never calls this directly — it calls `/api/v1/ai/chat` and renders what comes back |
| AI assistant — send/stream | `POST /api/v1/ai/chat` (SSE, proxied via `app/api/ai/chat/route.ts`) | `ai.chat` | Reused verbatim from `AI_CHAT.md`; Help seeds `context: { surface: "help", route, article_code? }` |
| Create a support ticket | `POST /api/v1/help/support-tickets` | `help.support.create` | `{ category, subject, description, severity, attachments?: AttachmentRef[], diagnostics: {...} }`, carries a client-generated `Idempotency-Key` per `FRONTEND_ARCHITECTURE.md → Principle 9` so a flaky connection cannot double-file a ticket |
| List my tickets | `GET /api/v1/help/support-tickets` | Row ownership | Cursor-paginated, scoped to `auth()->id()` within the active company |
| One ticket's thread | `GET /api/v1/help/support-tickets/{id}` | Row ownership | Messages ordered oldest-first; `POST .../{id}/messages` appends a follow-up |

Because no other document yet owns the `help` endpoint family, this document defines the minimal slice
above the same way `ONBOARDING.md`'s own `onboarding` family was introduced — noting explicitly that the
full support-operations backend (agent assignment, SLAs, canned macros, escalation routing) belongs to a
future `SUPPORT_OPERATIONS.md` this document does not attempt to write; what is specified here is exactly
and only the frontend contract for a user filing and reading their own ticket.

## Response shapes

```json
// GET /api/v1/help/articles/how-closing-a-fiscal-period-works?locale=en
{
  "success": true,
  "data": {
    "code": "how-closing-a-fiscal-period-works",
    "knowledge_document_id": 3021,
    "domain": "platform_howto",
    "document_type": "howto_guide",
    "language": "bilingual",
    "title_en": "How closing a fiscal period works",
    "title_ar": "كيف يعمل إقفال الفترة المالية",
    "category": "accounting",
    "reviewed_at": "2026-06-02T00:00:00Z",
    "sections": [
      {
        "section_label": "Overview",
        "knowledge_chunk_id": 55810,
        "content": "Closing a fiscal period locks every posted entry in that range so no further posting can happen without a reversing entry…",
        "citation_text": "QAYD Platform Guide — Closing a Fiscal Period §Overview"
      },
      {
        "section_label": "What you need before you close",
        "knowledge_chunk_id": 55811,
        "content": "…"
      }
    ],
    "related_articles": [
      { "code": "reopening-a-closed-period", "title_en": "Reopening a closed period — what changes" },
      { "code": "year-end-close-checklist", "title_en": "Year-end close checklist" }
    ],
    "helpful_count": 214,
    "not_helpful_count": 9
  },
  "message": "OK",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "8a5e...",
  "timestamp": "2026-07-18T09:40:11Z"
}
```

```json
// POST /api/v1/help/support-tickets
// Request
{
  "category": "accounting",
  "subject": "Trial balance won't generate for June",
  "description": "Clicking Generate on the Trial Balance screen spins forever and then shows a network error.",
  "severity": "blocking",
  "diagnostics": {
    "route": "/accounting/trial-balance",
    "locale": "en",
    "app_version": "2026.07.2",
    "browser": "Chrome 126 / macOS 14",
    "last_request_id": "b7e1c2d4-9f21-4a3e-8b0a-1122aabbccdd"
  }
}
// Response
{
  "success": true,
  "data": {
    "id": 4821,
    "ticket_number": "QAYD-4821",
    "status": "open",
    "created_at": "2026-07-18T09:41:02Z"
  },
  "message": "Ticket QAYD-4821 created.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "c19a...",
  "timestamp": "2026-07-18T09:41:02Z"
}
```

## `SEARCH.md` extension — the `help_articles` group

Per the reconciliation in `# Purpose`, this document adds one group to `docs/frontend/SEARCH.md`'s
existing `SearchResultType` union and its `/api/v1/search` aggregate response, distinct from that
document's pre-existing "Knowledge (AI)" group (`ai_decisions`/`ai_risk_flags`):

```ts
// types/search.ts — additive to SEARCH.md's existing union
export type SearchResultType =
  | "account" | "journal_entry" | "customer" | "vendor" | "product"
  | "invoice" | "bill" | "document" | "insight" | "approval" | "page"
  | "help_article"; // new — points at a knowledge_documents row, domain='platform_howto'
```

| Result group | Endpoint fan-out target | Permission | Label |
|---|---|---|---|
| `help_articles` | `GET /api/v1/help/articles?q=...` | `help.read` (effectively universal — never skipped client-side the way a role-gated source is) | "Help & Guides" |

The Command Palette's own "Records" group (`NAVIGATION_SYSTEM.md`) gains this same source in its
per-keystroke fan-out, capped at 5 rows exactly like every other palette source — typing "reconcile"
into `⌘K` now surfaces both a matching `Bank Reconciliation` nav destination and a "How bank
reconciliation matching works" help article side by side, each in its own labeled group.

## Query keys and cache tuning

```ts
// lib/query/keys.ts (Help-scoped factories, alongside the existing dashboardKeys/searchKeys/onboardingKeys)
export const helpKeys = {
  all: ["help"] as const,
  categories: () => [...helpKeys.all, "categories"] as const,
  articles: (filters: { category?: string; q?: string; locale: string }) =>
    [...helpKeys.all, "articles", filters] as const,
  article: (code: string, locale: string) =>
    [...helpKeys.all, "article", code, locale] as const,
  tours: () => [...helpKeys.all, "tours"] as const,
  tour: (id: string) => [...helpKeys.all, "tour", id] as const,
  tourProgress: () => [...helpKeys.all, "tour-progress"] as const,
  changelog: (filters: { tag?: string }) => [...helpKeys.all, "changelog", filters] as const,
  status: () => [...helpKeys.all, "status"] as const,
  tickets: () => [...helpKeys.all, "tickets"] as const,
  ticket: (id: number) => [...helpKeys.all, "ticket", id] as const,
  contextual: (route: string) => [...helpKeys.all, "contextual", route] as const,
};
```

| Data class | Resources | `staleTime` | Rationale |
|---|---|---|---|
| Reference/platform content | `categories()`, `tours()`, `tour(id)` | 5 minutes, matching `FRONTEND_ARCHITECTURE.md`'s reference-data class | Curator-published content changes on a release cadence, not per session |
| Article content | `article(code, locale)` | 5 minutes | A `published` article is stable between curator revisions; a superseded version is fetched explicitly by version, never silently swapped under a reading user (`KNOWLEDGE_MEMORY.md`'s own retention model) |
| Article browse/search | `articles(filters)` | 30 seconds | Cheap, index-backed query; short staleness keeps a newly-published article discoverable promptly without polling |
| Tour progress | `tourProgress()` | `0` (always stale), `refetchOnWindowFocus: true` | A tour just completed on a screen the user navigated away from must be reflected the instant they return to `/help/tours` |
| Changelog | `changelog(filters)` | 60 seconds | Release notes are infrequent but a user actively checking "what's new" expects a fresh list |
| System status | `status()` | 15 seconds, `refetchOnWindowFocus: true` | Matches the platform's general "live/derived figures" treatment for anything an outage could make stale within a session |
| Tickets | `tickets()`, `ticket(id)` | 15 seconds, `refetchOnWindowFocus: true` | A user waiting on a support reply should see a status flip without a manual refresh, mirroring `ONBOARDING.md`'s own invitations-list tuning |
| Contextual suggestions | `contextual(route)` | 5 minutes | Derived from a static `HELP_CONTEXT_MAP`, not a live query at all in the common case — see below |

## The contextual map

`HELP_CONTEXT_MAP` is a static, versioned lookup — authored and reviewed alongside the codebase, not
fetched — from a route pattern to the handful of article codes and an optional tour id most relevant to
that screen, the same authoring posture `docs/frontend/NAVIGATION_SYSTEM.md`'s own `NAV_TREE` has:

```ts
// lib/help/context-map.ts
export const HELP_CONTEXT_MAP: Record<string, { articleCodes: string[]; tourId?: string }> = {
  "/accounting/journal-entries": {
    articleCodes: [
      "how-closing-a-fiscal-period-works",
      "reviewing-an-ai-drafted-journal-entry",
      "live-debit-credit-balance-checks",
    ],
    tourId: "journal-entries-basics",
  },
  "/banking/[bankAccountId]/reconciliation": {
    articleCodes: ["bank-reconciliation-matching-rules", "handling-a-reconciliation-variance"],
    tourId: "bank-reconciliation-workbench",
  },
  // one entry per route this document's `HELP_CONTEXT_MAP` covers; a route with no entry
  // falls back to the current module's own category (accounting.read → the Accounting category)
};
```

`ContextualHelpDrawer` resolves the current route against this map on open (matching against the most
specific pattern, falling back to a module-level default derived from the active nav module) and issues
`GET /api/v1/help/articles?codes=...` for exactly those codes — no network round trip is needed to
*decide* what to suggest, only to fetch the up-to-date title/summary for what the map already named.

## AI agents feeding this screen

| Agent / role | Contribution |
|---|---|
| CEO Assistant (orchestrator) | Answers every question asked through `HelpAiAnswerCard` or the drawer's composer; biased, via `context.surface = "help"`, toward `knowledge.search(domain='platform_howto')` as its first tool call, but never prevented from reaching into tenant financial tools if the question genuinely needs them, exactly per `AI_CHAT.md`'s single-conversational-surface design |
| Document AI / general retrieval layer | Owns embedding maintenance for `knowledge_chunks` where `domain='platform_howto'`, the same "fully automated, read-only" agent `docs/accounting/PRODUCTS.md` and `SEARCH.md` already name for their own semantic-search columns |
| Platform Content Curator (platform role, not a tenant AI agent) | Authors and revises articles, glossary entries, tours, and changelog entries — a human role `KNOWLEDGE_MEMORY.md → Write Path & Governance` already defines; Help Center introduces no additional curator role and no AI-authored article ever publishes itself without this role's sign-off |

# Interactions & Flows

**Opening Help from anywhere.** Clicking `HelpTrigger` in the Topbar opens `ContextualHelpDrawer` with a
brief slide-in (Framer Motion, `reduced-motion`-gated to an instant appearance), pre-scoped to the
current route via `HELP_CONTEXT_MAP`. `G` then `H` instead navigates fully to `/help`, and `?` instead
opens `KeyboardShortcutsDialog` — three distinct, deliberately different-weight entry points for three
different intents ("help me with what I'm looking at right now," "let me browse everything," "what does
this key do"), never collapsed into one because collapsing them would force the lightest-weight one
(a shortcut lookup) to pay the cost of the heaviest (a full page navigation).

**Searching from the Help home.** Typing into `HelpSearchInput` debounces at 200ms (matching
`SEARCH.md`'s own threshold) and, at 2+ characters, calls `GET /api/v1/help/search`; results replace the
Categories/Popular/Tours/What's-New stack in place, grouped into `All / Articles / Glossary / Shortcuts`
Type Tabs exactly as `SEARCH.md`'s own Type Tabs behave, and the query is mirrored to `?q=` so a search
result page is itself a shareable link ("here's the article on reversing entries" is a URL a Finance
Manager can paste to a teammate). Clearing the field restores the browse view without a page reload.

**Reading an article.** Clicking any article row navigates to `/help/articles/{code}`, a Server
Component that fetches the article server-side (first paint has real content, no client-visible
spinner) and hydrates a thin client wrapper for the "Was this helpful?" widget and the contents rail's
`HelpAiAnswerCard`. Scrolling the body updates the Contents Rail's active-section highlight via
`IntersectionObserver`, and clicking a Contents Rail entry smooth-scrolls (respecting
`prefers-reduced-motion`, in which case it jumps instantly) to that `section_label`'s anchor.

**Asking the AI assistant a question.** Submitting `HelpAiAnswerCard`'s composer (from the Help home, an
article page, or the contextual drawer) calls `POST /api/v1/ai/chat` with `context.surface = "help"` and,
on an article page, `context.article_code` — the orchestrator's retrieval step is biased toward that
article's own `knowledge_document_id` first, then the wider `platform_howto` domain, then (only if the
question's own content requires it) the caller's tenant tools, exactly per `AI_CHAT.md`'s streaming
contract: optimistic user-bubble append, a thinking indicator, token-by-token streaming, and a final
message carrying `confidence`, `reasoning`, and numbered `sources`. Clicking "Ask a follow-up" promotes
the condensed card into the full docked `Sheet` rendering of `/assistant`, pre-seeded with the exact
retrieval context already computed — never a re-fetch of what the user already read — matching
`SEARCH.md`'s own identical promotion behavior for its `SearchAiAnswerCard`.

**Rating an article.** `ArticleFeedbackWidget` is two icon buttons ("Yes" / "No"); selecting either
posts immediately (optimistic, reversible by clicking the other button within the same session) and
reveals a single optional textarea ("What was missing?") only after "No," never demanded before the
vote itself can register — a user should never have to write an essay to say "this didn't help."

**Starting or replaying a tour.** Clicking **Start**/**Replay** on a `TourCard` (from `/help/tours`, the
Help home's Guided Tours row, or the contextual drawer's "Replay the tour for this screen") first
navigates to the tour's own target route if the user is not already on it, then mounts
`ProductTourOverlay` and posts `{ status: 'in_progress', current_step: 0 }`. Each **Next** advances the
step and patches `current_step`; **Skip** posts `{ status: 'skipped' }` immediately and closes the
overlay without judgment — a skipped tour is offered again from `/help/tours` at any time, never nagged
about. Finishing the last step posts `{ status: 'completed', completed_at: now }` and shows a brief,
dismissible confirmation ("You've completed this tour") rather than auto-advancing into another tour.

**The first-visit auto-prompt.** The first time a user's session lands on a route named in
`HELP_CONTEXT_MAP` with a `tourId` whose progress is `not_started` (never `dismissed` — a user who
explicitly closed the prompt is not re-asked), a small, non-blocking `Popover` anchored near the page
title offers "New here? Take a 4-minute tour" with **Start** / **Not now** — "Not now" posts
`{ status: 'dismissed' }` and this prompt never reappears for that tour on that account, though the tour
itself remains fully available from `/help/tours` forever. This is a courtesy nudge, never a modal
blocking the screen — a returning accountant who has used Journal Entries daily for two years is never
interrupted by it because their own progress row is already `completed` or `dismissed` from day one.

**Working through the Onboarding Checklist.** Clicking a checklist item ("Bank — pending") navigates
directly to that Onboarding step's own route (`/onboarding/banking`), reusing `ONBOARDING.md`'s own
"clicking a completed or skipped node navigates directly to that step" behavior — Help Center adds no
new navigation rule here, only a second, generalized surface for reaching the same destination.
Completing or explicitly re-skipping-and-dismissing every listed step removes the card from both its
Help Center and Dashboard renderings on the next `onboardingKeys.progress()` refetch — one query,
two presentations, never two independently-drifting progress computations.

**Reading What's New.** Opening `/help/changelog` (or expanding the Help home's teaser) immediately
fires `PATCH /api/v1/users/me/preferences { help_changelog_last_seen_at: now }`, clearing
`HelpTrigger`'s unread dot — the dot itself is a purely client-computed comparison (`latest entry's
`published_at`` vs. the stored preference), never a separate unread-count endpoint, so there is exactly
one source of truth for "has this user seen the newest entry."

**Filing a support ticket.** `ContactSupportForm` pre-fills Category from whatever context the user
arrived with (an article's own category, or the current route's module) and always shows its Diagnostics
summary as read-only, collected client-side without any action from the user: `route` (the pathname at
the moment "Contact support" was clicked, not wherever the user has since navigated), `app_version`
(build-time constant), `browser`/`OS` (`navigator.userAgent`, parsed, never sent raw), and
`last_request_id` — the most recent `ApiError.request_id` this session observed, if the user arrived via
an error state's own "Contact support" link (`# States`), so a bug report already carries the exact
trace an engineer needs without the user having to hunt for or transcribe it themselves, directly
answering `docs/api/API_SDK_GUIDELINES.md`'s own observation that "`request_id`... QAYD support always
asks for it." Submission is idempotent (see `# Data & State`) and redirects to the new ticket's own
thread, where a follow-up reply is a plain `POST .../messages` append.

# AI Integration

Help Center's AI surface follows the identical contract `docs/frontend/FRONTEND_ARCHITECTURE.md → AI
Integration Layer` and `AI_CHAT.md` already establish platform-wide, applied to a corpus (Knowledge
Memory's `platform_howto` domain) rather than a company's own transactional data. Four rules specific to
this screen's own framing, beyond what any other AI-authored surface needs to additionally observe:

**This is the same assistant, contextually biased — never a smaller one.** `HelpAiAnswerCard` and the
drawer's composer both mount `AssistantView` exactly as `AI_CHAT.md` specifies, differing only in the
`context` object passed to `POST /api/v1/ai/chat`. A question that is genuinely about the product
("what does a control account mean") resolves quickly against `platform_howto` chunks alone; a question
that happens to be asked *from* the help screen but is actually about the caller's own data ("why is my
AR aging so high") is answered exactly as it would be from `/assistant` directly, using whatever tenant
tools the orchestrator and the caller's own permissions allow — Help Center's `context.surface = "help"`
is a hint that shifts tool-selection *priority*, never a capability restriction, matching
`CEO_AGENT.md`'s foundational premise that there is exactly one conversational surface in QAYD.

**Every citation resolves to a real, versioned passage, never a vague "see the docs."** A `HelpAiAnswerCard`
answer's numbered citations are `knowledge_chunk_id` references carrying the exact `citation_text`
`KNOWLEDGE_MEMORY.md`'s schema already produces (e.g. "QAYD Platform Guide — Closing a Fiscal Period
§Overview"); clicking one scrolls to that section within the current article if it is already on
screen, or navigates to `/help/articles/{code}#{section_label}` if it is not — the same "a citation is
clicked, not merely disclosed" behavior `SEARCH.md`'s own `SearchCitation` type already establishes,
applied here exclusively to this document's own corpus rather than a mix of ten resource types.

**Confidence is never softened, and a genuinely unanswerable question says so plainly.** Matching
`AI_CHAT.md`'s and `SEARCH.md`'s identical rule, a Help answer whose confidence falls below the
platform's stated floor renders with **no confidence badge at all** and explicit prose — "I don't have a
confident answer for that in our help content yet — try Contact Support, or ask a broader question" —
never a badge showing an artificially low number. This is also, structurally, the primary signal
`KNOWLEDGE_MEMORY.md`'s own coverage-gap metric depends on: a Help-scoped conversation that repeatedly
resolves `unavailable` for the same topic is exactly the "hit rate" and "coverage" data
`KNOWLEDGE_MEMORY.md → Metrics` already defines, now with a second, complementary source (real user
questions) alongside agent-side retrieval misses.

**A Help answer almost never carries an actionable proposal, and when it does, the same governance
applies without exception.** Because this screen's questions are overwhelmingly "how does X work," a
`HelpAiAnswerCard` response's `recommended_action`/`suggested_actions` field is populated far less often
than a Dashboard or Search answer's — but on the rare occasion a Help conversation surfaces something
actionable (a user asks "how do I close June" and the assistant recognizes June is already
close-ready and offers to start the close), the identical three-button `RecommendationCard` pattern
(`Do it` / `Send for approval` / `Dismiss`) renders, `Do it` still gated on `can_execute_directly` being
`true` and structurally absent for anything on the sensitive-action list (`bank.transfer`,
`payroll.approve`, `tax.submit`, a permission change, a delete/void) regardless of confidence — Help
Center introduces no exception to this rule and no shortcut around the Approval Center.

# States

| Region | Loading | Empty | Error |
|---|---|---|---|
| Help home — Categories/Popular/Tours/Changelog | Skeleton tiles/rows matching real card height, each region resolving independently (per `DASHBOARD.md`'s own per-region independence) | Not applicable for Categories (a fixed platform taxonomy always has entries); "No popular articles yet" only for a genuinely new platform install, never shown in production | An isolated region failure shows a small inline "Couldn't load — Retry" card; one failed region never blocks the others |
| Search results (`?q=`) | Skeleton rows per active Type Tab | "No results for '{q}'" plus a "Ask AI about this instead" shortcut that pre-fills the composer with the literal query | Whole-search `ErrorBoundary` only for a genuine `5xx`; a single group's permission-driven absence is never rendered as an error |
| Article detail | Route-level `loading.tsx` skeleton matching the sectioned-prose shape | Not applicable — a published article always has at least one chunk | A `404` (unknown/unpublished code) renders `ErrorState` with "This article may have moved — search Help Center" rather than the framework's default not-found page; a `410`-equivalent (`status='retracted'`) renders the article's last-known content with a persistent banner, never a bare 404, per `# Edge Cases` |
| `HelpAiAnswerCard` | Three pulsing `accent-subtle` dots, identical to `AI_CHAT.md`'s calibrated thinking pattern | An unsubmitted composer shows a quiet placeholder, never a stale prior answer | `status: "unavailable"` renders the same "AI insights are temporarily unavailable" treatment `DASHBOARD.md` already defines for its own AI Summary Rail |
| Guided tours list | Skeleton cards | A brand-new platform release with zero authored tours yet is a real, if rare, empty state: "No guided tours yet — check back soon" | Inline retry card; never blocks the rest of `/help/tours` |
| `ProductTourOverlay` | n/a (renders only once its step definitions have loaded) | n/a | A target element not found for the current step auto-skips to the next step (`# Edge Cases`), never leaving a dangling spotlight pointed at nothing |
| Onboarding Checklist | Skeleton rows matching the eventual 1–3 item list | The entire card is absent once every listed step is resolved — this is a genuine, expected empty state, not an error, and is never rendered as a bare "Nothing to show" card | A failed `onboarding/progress` fetch simply omits the card for that render rather than showing a broken widget on an otherwise-fine screen |
| Changelog | Skeleton entries | "No updates yet" for a fresh install | Inline retry |
| System status | Skeleton pills | Not applicable — the platform always has at least one subsystem to report | The status endpoint itself being unreachable is reported as its own `degraded`/`unknown` pill for "Help Center's connection to Status," rather than a blank panel — status quo bias toward showing *something* even when the meta-question ("can we even check status") itself fails |
| Contact form | Submit button shows a spinner via `loading` prop (`COMPONENT_LIBRARY.md`'s `Button` convention), disabled for the duration | Not applicable | `422` field errors map onto the form via `useApiToast().fromApiError`, identical to every other QAYD form; a network failure preserves every typed field for retry, never discarding a half-written bug report |
| Ticket thread | Skeleton message bubbles | A ticket with only the opening message shows just that message, no "no replies yet" filler copy | Inline retry; the ticket's own status pill (`open`) is unaffected by a transient fetch failure |

# Responsive Behavior

| Region | Desktop / `xl`+ | Tablet (`md`–`lg`) | Mobile (`base`–`sm`) |
|---|---|---|---|
| Help home | Search header, two-row category grid, 8/4 wide-region/rail split | Category grid wraps to 3 columns, rail stacks below the wide region | Category grid becomes a horizontal-scroll chip row (matching `SEARCH.md`'s own Type Tab overflow pattern); Ask AI card and Onboarding Checklist stack full-width above Popular Articles, the Ask AI card collapsed by default so it never pushes content below the fold on a 375px screen — the identical "collapsed-by-default stacked AI card" pattern `SEARCH.md` already specifies for its own mobile layout |
| Article detail | 8/4 body/contents-rail split | Contents Rail collapses to a `Popover` triggered by a small "Contents" button in the sticky sub-header | Contents Rail becomes a bottom `Sheet`; `HelpAiAnswerCard` moves below the article body rather than beside it |
| `ContextualHelpDrawer` | `400px` `Sheet` from the inline-end edge | Same | Full-screen `Sheet` — identical to how the AI Rail and Search's docked Ask AI panel already degrade below their own breakpoints |
| `ProductTourOverlay` | Anchored `Popover` per step, precisely positioned against the target element | Same | Anchoring a small popover precisely against a target on a 375px screen is unreliable, so mobile steps render as a bottom `Sheet` naming the target region in plain language ("Look at the **New Entry** button, top right") with a directional chevron rather than a pixel-anchored callout — the same reasoning `AI_CHAT.md → Responsive Behavior` already applies when it swaps a hover-shaped `Popover` for a `Sheet` on touch |
| `KeyboardShortcutsDialog` / `/help/shortcuts` | Two-column grouped list | Same | The dialog becomes a full-screen `Sheet`; the full reference page's groups stack single-column, and the dialog's own content is deliberately identical either way since keyboard shortcuts are, definitionally, a desktop-primary concern — the page still renders correctly on a phone for a user looking something up on a colleague's behalf, but no special mobile affordance (like a "practice mode") is built for a feature whose entire subject is a physical keyboard |
| Contact form | Two-column field layout where fields pair naturally (Category + Severity) | Single column | Single column; the Diagnostics summary collapses to a single-line "Diagnostics attached ▾" disclosure rather than a multi-row read-only block, to keep the form's own required fields above the fold |

Touch targets across every button, tab, and tour-step control maintain the platform's 44×44px minimum
hit area below `md`, implemented once in the shared `Button`/`IconButton` components rather than per
instance on this screen, identical to the rule `SEARCH.md` and `AI_CHAT.md` both already state for their
own touch surfaces. On mobile specifically, Help Center appears under the bottom tab bar's **More**
destination (`Dashboard, Accounting, Banking, Search, More` — `SEARCH.md`'s own quoted wireframe), not as
a sixth tab of its own; `HelpTrigger` remains available in the mobile Topbar regardless.

# RTL & Localization

This screen is authored and reviewed in Arabic before it is considered done, per
`docs/frontend/DESIGN_LANGUAGE.md → Design Principle 6` and its own Voice & Microcopy brief ("Arabic
copy is written directly by a fluent professional-register writer, not machine-translated"). `dir="rtl"`
is set once at the root; no Help component toggles direction itself, and every gap/padding/alignment
value uses Tailwind's logical utilities (`ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`) exclusively,
per the platform-wide ESLint restriction `DESIGN_LANGUAGE.md → RTL mirroring` enforces.

Article content specifically carries one structural nuance beyond ordinary UI-chrome localization.
Because `knowledge_chunks.chunk_index` is unique per `document_id` (`KNOWLEDGE_MEMORY.md`'s schema), a
single bilingual `platform_howto` article is authored as a **document pair** — an `en` document and an
`ar` document sharing a topic but not a `document_id` — rather than one document with parallel-language
chunks at the same index. `KNOWLEDGE_MEMORY.md`'s own `knowledge_documents.language` column (`'en'` \|
`'ar'` \| `'bilingual'`) already anticipates this at the metadata level; this document is the first to
need the actual sibling-linking convention in practice, and flags — the same way `ONBOARDING.md` flagged
its own Wizard Template "for later adoption" — that `KNOWLEDGE_MEMORY.md` should adopt a
`sibling_document_code` column the next time it is revised, so `GET /api/v1/help/articles/{code}?locale=ar`
can resolve the Arabic sibling by a real foreign key rather than the curator-authored naming convention
(`{code}` / `{code}-ar`) this document relies on in the interim.

Three things never mirror or transliterate, identical to every other AI-authored and financial surface
in the product: **every keyboard shortcut keeps its physical key** (`ACCESSIBILITY.md → Keyboard
shortcuts keep their physical keys; on-screen hints mirror` — `Cmd/Ctrl+K` is `Cmd/Ctrl+K` in Arabic too,
only the *label describing it* is translated); **every citation number and confidence percentage renders
inside a `dir="ltr"` span**, exactly as `AI_CHAT.md` specifies for its own numbered citations; and
**Arabic copy is a parallel original, never a machine translation of the English string**, including
every changelog entry, tour caption, and ticket-category label. The one piece of content this document
does *not* control the translation of is an article's own body prose — that is the Platform Content
Curator's job per `KNOWLEDGE_MEMORY.md`'s governance model, not a frontend localization concern — but the
chrome around it (the "Was this helpful?" prompt, the Contents Rail heading, the "Last reviewed" label)
follows the exact same `en.ts`/`ar.ts` dictionary discipline `FRONTEND_ARCHITECTURE.md` fixes everywhere.

| Context | English | Arabic |
|---|---|---|
| Search placeholder | Search articles, guides, shortcuts… | ابحث في المقالات والأدلة واختصارات لوحة المفاتيح… |
| Was this helpful — yes | Yes, this helped | نعم، هذا ساعدني |
| Was this helpful — no | No, I need more | لا، أحتاج المزيد |
| Tour prompt | New here? Take a 4-minute tour | جديد هنا؟ خذ جولة مدتها 4 دقائق |
| Checklist header | Finish setting up your company | أكمل إعداد شركتك |
| Changelog empty | No updates yet | لا توجد تحديثات بعد |
| Status — all operational | All systems operational | جميع الأنظمة تعمل بشكل طبيعي |
| Contact — severity: blocking | Blocking my work | يعيق عملي |
| Ticket confirmation | Ticket {number} created. We'll follow up by email. | تم إنشاء التذكرة {number}. سنتواصل معك عبر البريد الإلكتروني. |

# Dark Mode

Dark mode is system-aware, user-overridable, and persisted via the platform's shared `class`/
`data-theme="dark"` strategy — no component in this document implements its own theme logic.
`AiCardShell`, `ConfidenceBadge`, and every citation marker resolve through the same recalibrated token
set every other AI-authored surface uses, per `docs/frontend/DARK_MODE.md`'s "the accent gets lighter,
not more saturated" rule. Two considerations specific to this screen's own new surfaces:

**`ProductTourOverlay`'s dimming scrim is a translucent ink tint, never a flat black overlay**, matching
`DESIGN_LANGUAGE.md`'s dark-mode elevation rule that surfaces get lighter, not darker, as they rise —
the scrim in dark mode uses `ink-950/60` rather than a pure-black `#000/60`, so the spotlighted element
and its `Popover` caption remain legible against a genuinely dark canvas rather than a harsh black void
that would read as more alarming than a guided tour warrants.

**Category tiles and tour-status pills reuse `StatusPill`'s and `Card`'s existing dark tokens verbatim**
— no bespoke "help-blue" or "tour-purple" palette is introduced anywhere in this document; category
tiles are visually differentiated by icon and label alone, never by a per-category accent color, per
`DESIGN_LANGUAGE.md`'s "one accent" rule restated in `# Purpose`'s founding constraints.

# Accessibility

This screen targets the same WCAG 2.2 AA floor as every other QAYD screen (`docs/frontend/ACCESSIBILITY.md`),
with particular weight on the two areas most load-bearing here: **WCAG 3.2.6 Consistent Help** and the
keyboard operability of an entirely new interaction pattern, the guided tour.

**Consistent Help, satisfied structurally, not by convention alone.** `ACCESSIBILITY.md`'s own `?`
shortcut row already states this criterion by name ("satisfies WCAG 3.2.6 Consistent Help — same
shortcut, same menu position, on every screen"). This document's `HelpTrigger` icon strengthens the same
guarantee for the *contextual* help mechanism: it occupies the identical Topbar position on every
authenticated route, `?` opens the identical dialog from every route, and `G` then `H` navigates to the
identical `/help` home from every route — three independent, redundant, always-in-the-same-place paths
to help, exactly what 3.2.6 requires and exactly the kind of redundancy `NAVIGATION_SYSTEM.md`'s own
"switch company" section already models ("frequent enough for power users... to deserve a shortcut from
wherever the eye lands," applied here to *finding help* rather than *switching context*).

**`ProductTourOverlay` is fully keyboard-operable and never traps focus outside a real control.** Each
step's `Popover` receives focus on mount (`role="dialog"`, labelled by the step's own title), `Tab`/
`Shift+Tab` cycle between Back/Next/Skip/Finish, `Esc` exits the tour entirely from any step (posting
`dismissed`, not `skipped` — a keyboard user who presses `Esc` mid-tour is treated identically to one who
clicks the close affordance), and a step's caption text is announced via the dialog's own accessible
name rather than a separate live region — there is no token-by-token or animation-driven announcement to
throttle here, unlike a streaming AI reply, because a tour step's text is static and complete the moment
it renders. A step never requires clicking the *spotlighted element itself* to advance — Next/Back are
always explicit, separately focusable buttons in the step's own caption, so a screen-reader or
keyboard-only user is never forced to locate and activate a control they cannot yet see clearly.

**The article body uses real heading semantics, not styled paragraphs.** Each `section_label` renders as
a genuine `<h2>` (the article's own `<h1>` is its title), so a screen-reader user can navigate an article
by heading exactly as they would any well-structured document, and the Contents Rail's links are real
in-page anchors (`href="#section-slug"`), not `onClick`-only scroll triggers, so they work identically
with assistive technology, middle-click-to-open-in-a-new-tab, and `Ctrl+F` browser search.

**Citations and confidence read unambiguously**, per `ACCESSIBILITY.md → Screen Readers`'s amount- and
confidence-reading rules already cited in `AI_CHAT.md` — a citation marker has a real accessible name
("Source 1: How closing a fiscal period works, Overview"), never a bare "[1]" with no context for a
screen-reader user, and `ConfidenceBadge`'s percentage and qualitative band are both real text nodes.

**The "Was this helpful?" control is two real, labelled buttons**, never a single ambiguous thumbs-up/
thumbs-down icon pair relying on color or shape alone — each carries a full accessible name ("This
article helped me" / "This article did not help me") satisfying the same "a button's purpose must be
knowable from its accessible name alone" standard `COMPONENT_LIBRARY.md`'s `DropdownMenu` row-action
precedent already establishes for icon-bearing controls elsewhere in the product.

# Performance

Help Center is held to the platform's ordinary interaction and Web Vitals budgets
(`FRONTEND_ARCHITECTURE.md → Web Vitals`), with three considerations specific to its own content shape.

**Article pages are Server Components with no client-side re-fetch of what already rendered.** An
article's prose is fetched once, server-side, and streamed as the first paint (`FRONTEND_ARCHITECTURE.md`'s
RSC-by-default rule) — only the Contents Rail's active-section tracking, the feedback widget, and the
`HelpAiAnswerCard` are Client Components, kept as small, isolated islands rather than hydrating the
entire article body, since static prose has nothing to hydrate.

**`ProductTourOverlay` and its step content are code-split, loaded only on first use** (`next/dynamic`,
matching `AI_CHAT.md`'s identical treatment of its own voice-recording module) — a user who never takes a
tour pays zero bytes for the overlay's positioning/anchoring logic, since it is meaningfully heavier than
a plain `Popover` (it must observe target-element geometry across scroll/resize).

**Search and the AI assistant reuse existing, already-warm infrastructure rather than opening new
connections.** `HelpAiAnswerCard`'s SSE stream and the drawer's docked chat both reuse the identical
`app/api/ai/chat/route.ts` proxy and, where applicable, the same shared `RealtimeProvider` connection
every other AI surface already holds open (`AI_CHAT.md → Performance`'s "no additional WebSocket is
opened") — opening Help Center never opens a second live connection alongside whatever the rest of the
shell already maintains.

**Tenant-scoped data on this screen is never statically cached**, per `FRONTEND_ARCHITECTURE.md`'s rule:
`contact/*` routes (tickets are company-scoped) set `dynamic = "force-dynamic"` /
`fetchCache = "default-no-store"` unconditionally; `articles/*`, `tours/*`, `changelog/*`, and `status/*`
carry no tenant dimension at all and are the rare `(app)` routes eligible for the Next.js Data Cache
with a short revalidation window, the same narrow exception `FRONTEND_ARCHITECTURE.md` reserves for
"reference data with no tenant dimension at all" — this is a deliberate, stated departure from that
document's otherwise-blanket "every `(app)` route is tenant-scoped and dynamic" posture, justified
precisely because this document's own core content genuinely has no `company_id` anywhere in it.

# Edge Cases

| # | Edge case | Handling |
|---|---|---|
| 1 | A tour's target element is absent for the current viewer (a permission-gated button they cannot see, or a layout that changed since the tour was authored) | `ProductTourOverlay` auto-skips that step and advances to the next one whose target resolves, logging the miss so a curator can retire or update the stale step; a tour with zero resolvable steps for a given viewer is silently not offered (no auto-prompt, absent from "Suggested for this screen") rather than opening to an empty spotlight |
| 2 | A bilingual article's Arabic sibling does not exist yet | The article renders in English with a small, honest notice — "This article isn't available in Arabic yet — showing English" — never silently mixing languages mid-article and never machine-translating on the fly, per `DESIGN_LANGUAGE.md`'s explicit rejection of machine-translated Arabic |
| 3 | A cited `knowledge_chunk` is later retracted or superseded after a `HelpAiAnswerCard` answer already referenced it | The citation link still opens, per `KNOWLEDGE_MEMORY.md → Retention`'s "never deleted" rule and `AI_CHAT.md`'s identical precedent for a citation whose record has since changed — it shows the article's current, superseded-aware state with a plain banner ("This guidance has since been updated") rather than a broken link or a silent swap of content under the citation's original wording |
| 4 | A user files a ticket, then the connection drops before the response returns | The client-generated `Idempotency-Key` (`FRONTEND_ARCHITECTURE.md → Principle 9`) means a retry of the exact same submission never creates a second ticket; the form's own fields remain populated for the retry, never cleared on a failed attempt |
| 5 | A user asks the Help assistant something that requires a permission they lack (e.g. a Sales Employee asking "how do I approve a payroll run") | The assistant answers the *how-to* question generally (the article content itself carries no RBAC dimension — anyone may read how payroll approval works) but is explicit that the caller's own account cannot perform the action, exactly mirroring `AI_CHAT.md`'s own rule that the assistant "never bypasses authorization" — explaining a process is not the same as granting access to it |
| 6 | Switching companies while an article or the AI assistant is open | Per `# Route & Access`'s stated exception to `FRONTEND_ARCHITECTURE.md`'s general company-switch rule, the open article and the conversation transcript are **not** discarded — only the assistant's next tenant-scoped tool call (if any) resolves against the new `X-Company-Id`, and the Onboarding Checklist and any open ticket thread (both genuinely tenant-scoped) do refresh to the new company's own state |
| 7 | The public `GET /api/v1/status` endpoint itself is unreachable | `SystemStatusPanel` reports its own meta-state ("Unable to reach status — try the public status page directly," with an outbound link) rather than showing a blank panel or, worse, silently rendering a stale "All systems operational" from a cached response past its `staleTime` |
| 8 | A user opens the contextual drawer on a route with no `HELP_CONTEXT_MAP` entry | The drawer falls back to the current nav module's own category (resolved from the same permission-filtered `NAV_TREE` the Sidebar uses) rather than showing an empty "Suggested for this screen" section — a route not yet explicitly mapped still gets a reasonable, module-level starting point, never a dead end |
| 9 | A changelog entry links to a screen or feature the viewer's role cannot access | The entry itself still renders (the changelog is a transparency surface, not a permission-scoped one — every user should be able to see what QAYD shipped this month even if their own role can't use every feature) but its "Try it" link, if clicked, resolves to the target route's own ordinary RBAC handling (hidden nav item, `403` on direct navigation) exactly as it would from anywhere else — the changelog introduces no bypass |
| 10 | A user's session has `ai.chat` revoked between opening Help Center and submitting a question | The composer's submit action still fires the request; the server's ordinary `403` renders inline in the transcript as a plain error bubble ("You don't currently have access to the AI assistant — contact your company admin"), never a client-side pre-check that could drift from the server's own authoritative answer, per the platform's standard RBAC-courtesy-in-client/hard-boundary-on-server posture already stated throughout `FRONTEND_ARCHITECTURE.md` |
| 11 | Two tabs have the same tour active simultaneously | Tour progress is last-write-wins on `POST .../progress`, matching the platform's general posture for low-stakes, reversible client state — a tour is a learning aid, not a financial record, so no optimistic-concurrency conflict handling beyond what TanStack Query's own request ordering already provides is warranted here |

# End of Document
