# Frontend README & Documentation Index — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: README
---

# Purpose

This document is the entry point to `docs/frontend/` — the complete engineering and design
specification set for the QAYD web client. It exists so that any engineer, designer, or AI coding
agent can open exactly one file, understand what the frontend is, how its repository is organized,
how to run it locally, which conventions are non-negotiable, and — most importantly — which of the
other documents in this folder to open next for the topic they actually need. It does not itself
specify any single screen, component, or design token in depth; every one of those has its own
authoritative document, indexed below, and this README never duplicates their content, only points
to it.

Everything in `docs/frontend/` inherits three constraints from the platform-wide design context that
this document restates once, up front, so no downstream document has to:

1. **The frontend is a pure presentation and interaction layer.** It calls the Laravel API
   (`/api/v1/...`) for every fact and every mutation. No business rule, no monetary calculation, no
   validation that the backend also enforces, and no permission decision is ever implemented
   client-side as a source of truth — client-side checks exist only to make the UI feel instant and
   to avoid a round trip for an action the user cannot legally take; the backend re-checks everything.
2. **AI is visible, never silent.** Every AI-originated number, suggestion, draft, or flag rendered
   anywhere in this application carries its confidence score and its reasoning, and every
   AI-originated action that touches money, tax, payroll, or posted financial data renders an
   explicit approve/reject/delegate affordance. The frontend never auto-commits an AI proposal; it
   only ever auto-commits a human's decision about one.
3. **RBAC is enforced in the UI as a matter of experience quality, not security.** Every permission
   key referenced anywhere in this document set (e.g. `accounting.journal.post`, `bank.reconcile`,
   `ai.approve`) exists first and authoritatively on the backend (see `../foundation/PERMISSION_SYSTEM.md`
   and `../api/AUTHENTICATION_API.md`). Hiding or disabling a control client-side when the permission
   is absent is a courtesy to the user, never the reason the action is actually blocked.

# Overview

QAYD is an AI Financial Operating System: a multi-tenant, double-entry accounting core (Accounting,
Sales, Purchasing, Banking, Inventory, Payroll, Tax) wrapped in an AI layer that drafts, reconciles,
forecasts, and flags on the user's behalf, with a human always in the loop for anything sensitive.
The frontend documented here is the **web client** — the only first-party surface documented in this
folder. (The Flutter mobile app and the third-party/partner integrations are separate surfaces owned
by their own documentation trees; see `../api/API_ARCHITECTURE.md`'s surfaces table and
`../api/INTERNAL_API.md` for how all of QAYD's clients relate to the one shared `/api/v1` contract.)

The web client renders, in this initial documentation batch, the **AI Command Center**, the company
**Dashboard**, and the full first slice of the **Accounting Engine** surface — Chart of Accounts,
Journal Entries, General Ledger, Trial Balance, the three primary Financial Statements (Balance
Sheet, Profit & Loss, Cash Flow), Banking, and Bank Reconciliation — on the same architecture that
every later module (Sales, Purchasing, Inventory, Payroll, Tax, Reports) will reuse without
architectural change. A screen is added to this system by adding a route segment, a query hook, and a
handful of composed components; it never requires a new state-management pattern, a new fetch
mechanism, or a new permission model.

**Design taste is a stated product requirement, not a preference.** QAYD's frontend targets the
editorial calm of Linear, Stripe, and Tap Payments — near-monochrome ink, one disciplined accent
color, a grotesque display face paired with a clean text face for data, generous whitespace, and
purposeful micro-motion — and explicitly rejects the cartoonish, over-rounded, loudly-colorful,
emoji-decorated register common to generic SaaS admin templates. The canonical tokens that implement
this taste live in `DESIGN_LANGUAGE.md`; nothing in this document or any other frontend document
hard-codes a color, font, or spacing value that contradicts them.

**Two audiences share one shell.** The same component library and layout system serve dense,
information-rich accountant workflows (Journal Entries, Trial Balance, Reconciliation — high data
density, keyboard-first, no hand-holding) and lighter, insight-first executive workflows (Dashboard,
AI Command Center, financial statements at a glance — calmer density, narrative framing, drill-down on
demand rather than everything at once). Both are built from the same tokens and the same primitives;
the difference is composition and density, never a second design system.

**Two languages, one direction model.** The application ships English and Arabic on day one, with
full RTL mirroring (`dir="rtl"`, logical CSS properties, mirrored iconography where meaning requires
it) and locale-aware number/date/currency formatting defaulting to KWD. There is no "Arabic mode" as a
separate build or a separate component tree — direction is a single root-level attribute that every
component already respects because no component hard-codes a physical (`left`/`right`) direction.

**Light and dark, system-aware.** Every screen, every state (loading, empty, error), and every
AI-confidence or RBAC-disabled affordance is specified and verified in both themes; dark mode is a
first-class token remap (see `THEMING.md` and `DARK_MODE.md`), not an inverted filter.

**The web tier's relationship to the API is direct and unprivileged.** Per `../api/INTERNAL_API.md`'s
surfaces table, the Next.js web tier is its own Node.js deployable that calls
`https://api.qayd.app/api/v1/...` the same way a browser would — it holds no special internal trust,
no service credential, and no database access of its own. Server Components and Server Actions make
that call server-side (forwarding the visitor's own session, see `# Conventions` below); Client
Components make it from the browser. Either way, it is the same versioned, permissioned, enveloped
REST contract every other QAYD client uses.

# Tech Stack

| Layer | Technology | Detail |
|---|---|---|
| Framework | Next.js 15 | App Router only — no Pages Router anywhere in the tree. React Server Components by default, Server Actions for simple mutations, streaming SSR (`loading.tsx`, `<Suspense>`) on every data-bearing route. |
| UI runtime | React 19 | `useActionState`, `useOptimistic`, and `use` are used where they remove boilerplate around Server Actions and Suspense-driven data. |
| Language | TypeScript 5.x, strict | `strict: true`, `noUncheckedIndexedAccess: true`, `noImplicitAny`. `any` is not permitted in committed code; unknown external data is typed `unknown` and narrowed (typically via the Zod schema that already exists for that resource). |
| Styling | Tailwind CSS | Utility classes only, bound to the CSS-variable design tokens defined in `DESIGN_LANGUAGE.md` / `THEMING.md`. No component ships a literal hex, rgb, or px value that a token already covers. |
| Component primitives | shadcn/ui (Radix UI underneath) | Primitives are generated into `components/ui/` and owned by the repository, not consumed as an opaque npm dependency — this is what lets every primitive be restyled to the editorial design language without fighting an upstream release. Accessibility (focus trapping, ARIA roles, keyboard nav) comes from Radix and is preserved, never stripped, when restyling. |
| Motion | Framer Motion | Reserved for purposeful transitions: panel/drawer entrances, confidence-meter fills, page-level fade/slide on route change. Every animation is wrapped so it collapses to an instant state change under `prefers-reduced-motion: reduce`. |
| Icons | Lucide | One icon set, one stroke width (1.5px), two sizes (16/20px inline, 20/24px standalone). Never mixed with emoji or a second icon set; custom financial glyphs not covered by Lucide (confidence meter, reconciliation-match icon) are documented in `ICONOGRAPHY.md`. |
| Server state | TanStack Query v5 | Every `/api/v1` read is a `useQuery` or `useSuspenseQuery`; every write is a `useMutation` with an explicit cache invalidation or optimistic update. Query keys are centralized factories (see `# Conventions`), never inlined per-component. |
| Client/UI state | Zustand + React Context | Zustand holds ephemeral, non-persisted UI state only (sidebar collapsed, active drawer, command-palette open, wizard step). React Context holds cross-tree singletons: session, active company, resolved permission set, locale, theme. Neither ever holds server data — that is TanStack Query's exclusive job. |
| Forms | React Hook Form + Zod | One Zod schema per form, shared between client-side validation and the shape the corresponding Laravel `FormRequest` expects, wired via `zodResolver`. |
| Realtime | Laravel Reverb + Laravel Echo | Reverb is the WebSocket server (Pusher-protocol compatible); Echo is the browser client. Channels are private and company-scoped (`private-company.{companyId}.dashboard`, `.notifications`, `.ai`) and are subscribed to once, in the authenticated app shell, and torn down on company switch or sign-out. |
| i18n | Internal typed dictionaries | English + Arabic flat dictionaries (`lib/i18n/en.ts`, `lib/i18n/ar.ts`) sharing one `Dictionary` type, so a key present in one and missing in the other is a compile error, not a silent runtime fallback. `Intl.NumberFormat` / `Intl.DateTimeFormat` / `Intl.PluralRules` handle locale-aware formatting and pluralization; no bespoke date or currency formatter is written by hand anywhere in the app. |
| Testing | Vitest + Testing Library, Playwright | Vitest + Testing Library for unit/component tests; Playwright for end-to-end flows and visual-regression screenshots across both themes and both directions (four screenshot variants per covered screen: light/LTR, light/RTL, dark/LTR, dark/RTL). |
| Tooling | ESLint, Prettier, `tsc` | `@typescript-eslint`, `eslint-plugin-jsx-a11y`, `eslint-plugin-react-hooks` in the lint config; Prettier with `prettier-plugin-tailwindcss` for deterministic class ordering; `tsc --noEmit` as its own CI step, independent of the Next build. |
| Runtime | Node.js 20 LTS | Pinned via `.nvmrc` / `engines` in `package.json`; CI and local dev must match. |
| Package manager | npm | A single `frontend/` package, `package-lock.json` committed. This matches the platform-wide convention already used for QAYD's own published package, `npm install @qayd/sdk` (see `../api/API_SDK_GUIDELINES.md`). |

This stack is fixed. A change to any row above is an architectural decision, not a per-PR choice, and
must be reflected here and in `../foundation/TECH_STACK.md` before it lands in code.

# Repository & Folder Structure

The frontend lives in its own top-level `frontend/` package inside the QAYD monorepo, alongside
`backend/` (Laravel), `ai/` (FastAPI), `mobile/` (Flutter), and `database/` (per
`../foundation/PROJECT_STRUCTURE.md`). Inside `frontend/`:

```text
frontend/
├── app/
│   ├── (marketing)/
│   │   ├── layout.tsx
│   │   └── page.tsx
│   ├── (auth)/
│   │   ├── layout.tsx
│   │   ├── login/page.tsx
│   │   └── mfa/page.tsx
│   ├── (app)/
│   │   ├── layout.tsx
│   │   ├── dashboard/
│   │   │   ├── page.tsx
│   │   │   └── loading.tsx
│   │   ├── ai/
│   │   │   └── command-center/
│   │   │       ├── page.tsx
│   │   │       └── loading.tsx
│   │   ├── accounting/
│   │   │   ├── page.tsx
│   │   │   ├── chart-of-accounts/
│   │   │   │   └── page.tsx
│   │   │   ├── journal-entries/
│   │   │   │   ├── page.tsx
│   │   │   │   ├── new/page.tsx
│   │   │   │   └── [journalEntryId]/page.tsx
│   │   │   ├── general-ledger/
│   │   │   │   └── page.tsx
│   │   │   ├── trial-balance/
│   │   │   │   └── page.tsx
│   │   │   └── statements/
│   │   │       ├── balance-sheet/page.tsx
│   │   │       ├── profit-and-loss/page.tsx
│   │   │       └── cash-flow/page.tsx
│   │   ├── banking/
│   │   │   ├── page.tsx
│   │   │   └── [bankAccountId]/
│   │   │       └── reconciliation/page.tsx
│   │   └── settings/
│   │       └── page.tsx
│   ├── api/
│   │   └── health/route.ts
│   ├── layout.tsx
│   ├── globals.css
│   ├── error.tsx
│   └── not-found.tsx
├── components/
│   ├── ui/
│   ├── shared/
│   ├── dashboard/
│   ├── ai/
│   ├── accounting/
│   ├── banking/
│   └── charts/
├── lib/
│   ├── api/
│   ├── query/
│   ├── auth/
│   ├── realtime/
│   ├── i18n/
│   ├── validation/
│   └── utils/
├── hooks/
├── stores/
├── styles/
├── types/
├── public/
├── tests/
│   ├── unit/
│   └── e2e/
├── middleware.ts
├── next.config.ts
├── tailwind.config.ts
├── tsconfig.json
└── package.json
```

| Path | Owns | Rule |
|---|---|---|
| `app/` | Routing, layouts, and the Server Component that performs each route's first authenticated data fetch. | A `page.tsx` may fetch and pass data down; it never contains presentation logic beyond composing components from `components/`. Route groups `(marketing)`, `(auth)`, `(app)` exist so each gets its own root layout (public site, unauthenticated auth flow, authenticated shell) without one giant conditional layout. |
| `components/ui/` | shadcn/ui primitives (Button, Dialog, Sheet, Table, Tooltip, Popover, Command, …), restyled to QAYD's tokens. | Never import a primitive directly from `radix-ui`; always through `components/ui/`. Never add business logic here — a `<Table>` renders rows, it does not know what a journal line is. |
| `components/shared/` | Cross-module composed patterns: `AppShell`, `Sidebar`, `Topbar`, `CommandPalette`, `DataTable`, `EmptyState`, `ErrorState`, `ConfidenceBadge`, `ApprovalDrawer`, `CurrencyInput`, `DateRangePicker`, `PermissionGate`, `StatusPill`. | If two modules would otherwise duplicate a component, it belongs here, not in both module folders. |
| `components/{dashboard,ai,accounting,banking}/` | Screen-specific composed components (`ProposalCard`, `ReasoningPanel`, `JournalLineGrid`, `AccountPicker`, `TrialBalanceTable`, `ReconciliationWorkbench`, `MatchSuggestionCard`, …), one subfolder per module matching `components/` layout already established in `../foundation/PROJECT_STRUCTURE.md`. | A component moves out of a module folder into `shared/` the moment a second module needs it — it is never imported cross-module in place. |
| `components/charts/` | Thin wrappers around the platform's data-visualization primitives (sparkline, bar, line, waterfall, donut) used by Dashboard, AI Command Center, and financial statements. | Chart color, in every instance, comes from the design-token chart palette (`DESIGN_LANGUAGE.md`), never an ad hoc hex per chart. |
| `lib/api/` | The fetch wrapper (`client.ts`), per-module endpoint functions (`accounting.ts`, `banking.ts`, `ai.ts`, …), the response-envelope types (`envelope.ts`), and the typed `ApiError`. | This is the **only** place `fetch` is called directly against `/api/v1`. Components and hooks call functions exported from here, never `fetch` themselves. |
| `lib/query/` | `queryClient.ts` (the TanStack Query client + its default options) and `keys.ts` (every query-key factory, one function per resource). | A new list/detail hook adds its key factory here before it is used anywhere else — this is what keeps invalidation correct across the whole app. |
| `lib/auth/` | `session.ts` (reads the current session/company/permission context), `usePermission.ts`, `useCompany.ts`. | RBAC checks in components always go through `usePermission()`, never a hand-rolled `user.role === 'admin'` string comparison. |
| `lib/realtime/` | `echo.ts` (the Echo client singleton) and `channels.ts` (typed channel names and payload shapes). | Components subscribe via a hook (`useRealtimeChannel`), never by instantiating Echo themselves. |
| `lib/i18n/` | `en.ts`, `ar.ts`, `format.ts`, `useTranslations.ts`. | See `# Conventions` and `../frontend/` i18n-focused docs; every new user-facing string is added to both dictionaries in the same commit. |
| `lib/validation/` | Zod schemas, one file per resource, mirroring the corresponding Laravel `FormRequest` field-for-field. | A frontend validation rule that the backend does not also enforce is a bug, not a feature — it exists only to fail fast in the UI. |
| `hooks/` | Resource-level TanStack Query hooks (`useJournalEntries`, `useTrialBalance`, `useAiProposals`, `useReconciliation`, …) composing `lib/api/*` + `lib/query/keys.ts`. | Hooks are the seam between `lib/api` (transport) and components (presentation); a component never imports `lib/api` directly when a hook already exists for that resource. |
| `stores/` | Zustand stores for ephemeral UI state (`ui-store.ts`). | No server data, ever — see the Tech Stack row above. |
| `styles/` | `globals.css` (resets, base typography) and `tokens.css` (the CSS-variable design tokens consumed by `tailwind.config.ts`). | Token values are defined once here and referenced everywhere else; see `THEMING.md`. |
| `types/` | Hand-maintained TypeScript types mirroring API resources (`JournalEntry`, `TrialBalanceRow`, `CursorPage<T>`, …), kept in lock-step with the backend's resource shapes. | If the API adds a field, the type here is updated in the same PR that starts consuming it. |
| `public/` | Static assets: the QAYD wordmark/logo lockups, favicon set, the two variable font files (display + text face), Open Graph images. | No user-uploaded or tenant content ever lands here — that is Cloudflare R2's job via signed URLs. |
| `tests/unit/`, `tests/e2e/` | Vitest specs colocated by feature; Playwright specs organized by user flow (`journal-entry-posting.spec.ts`, `reconciliation-close.spec.ts`, …). | Every new screen ships with at least one Playwright happy-path spec and one Vitest spec for its riskiest piece of client logic (usually a form schema or a derived total). |
| `middleware.ts` | Locale resolution (cookie → `Accept-Language` → `NEXT_PUBLIC_DEFAULT_LOCALE`) and the auth-gate redirect for the `(app)` route group. | Middleware never calls the API for business data — it only inspects the session cookie's presence and the resolved locale. |

# Getting Started

## Prerequisites

- Node.js 20 LTS and npm 10+ (`node -v`, `npm -v`; version is pinned in `frontend/.nvmrc` and
  `package.json#engines`).
- A reachable QAYD API: either a local Laravel instance (see the backend repository's own
  `README.md` for `sail up`) or the shared staging API. Point `NEXT_PUBLIC_API_BASE_URL` at whichever
  you use.
- No API secret, token, or key is required to run the frontend itself. Authentication for the human
  web session is a Sanctum cookie session established by actually signing in through the running app
  (see `# Conventions` below) — there is nothing to put in `.env.local` to "log in as" anyone.

## Install

```bash
cd frontend
npm install
```

## Run (development)

```bash
npm run dev
```

Starts `next dev --turbo` on `http://localhost:3000`. Turbopack is used for the dev server only;
production builds use the standard Next.js build pipeline until Turbopack's production builder is
adopted platform-wide as its own documented decision.

## Build & run (production mode, locally)

```bash
npm run build
npm run start
```

## Quality gates

```bash
npm run lint        # ESLint (typescript-eslint, jsx-a11y, react-hooks)
npm run typecheck    # tsc --noEmit
npm run test         # Vitest + Testing Library
npm run test:e2e     # Playwright — same invocation CI uses (see ../api/API_TESTING.md)
npm run i18n:check   # fails if any key exists in en.ts but not ar.ts, or vice versa
```

All five are required to pass before merge; none are optional "nice to have" checks.

## Environment variables

| Variable | Scope | Example | Purpose |
|---|---|---|---|
| `NEXT_PUBLIC_API_BASE_URL` | Public (browser + server) | `https://api.qayd.app` | Base URL every `lib/api/client.ts` call is built against, whether the call originates in a Server Component or the browser. |
| `NEXT_PUBLIC_APP_ENV` | Public | `local` \| `staging` \| `production` | Drives error-reporting sample rate and which non-production-only UI affordances (e.g. a staging banner) render. |
| `NEXT_PUBLIC_REVERB_HOST` | Public | `reverb.qayd.app` | WebSocket host Echo connects to. |
| `NEXT_PUBLIC_REVERB_PORT` | Public | `443` | |
| `NEXT_PUBLIC_REVERB_SCHEME` | Public | `https` | |
| `NEXT_PUBLIC_REVERB_APP_KEY` | Public | (Reverb app key) | Identifies the Reverb application; this is an app identifier, not a secret — the same trust level as a Pusher app key. |
| `NEXT_PUBLIC_DEFAULT_LOCALE` | Public | `en` | Fallback locale before a session/browser locale resolves; read by `middleware.ts`. |
| `NEXT_PUBLIC_DEFAULT_CURRENCY` | Public | `KWD` | Fallback presentation currency before the active company's own base currency loads. |
| `NEXT_PUBLIC_SENTRY_DSN` | Public | `https://…@…ingest.sentry.io/…` | Browser-side error reporting. |
| `SENTRY_DSN` | Server only | `https://…@…ingest.sentry.io/…` | Server-side (Server Component / Server Action / Route Handler) error reporting; deliberately a separate variable from the public one so server-only project routing can differ. |
| `SESSION_COOKIE_DOMAIN` | Server only | `.qayd.app` | Used by `middleware.ts` in local/staging to validate the incoming Sanctum session cookie's domain before trusting it. |
| `PLAYWRIGHT_BASE_URL` | Test only | `http://localhost:3000` | Target for `npm run test:e2e`. |

There is deliberately no `API_SECRET_KEY` or bearer-token variable in this table: the web surface's
own authentication is a server-revocable Sanctum cookie session, not a client-held credential (see
`# Conventions`). Any future server-only integration secret (e.g. a build-time CMS token for the
`(marketing)` route group) is added to this table when it is introduced, with its scope stated
explicitly — a variable without a stated scope is never merged.

## Local preview against a real API

The typical loop is: run the local Next.js dev server against the shared staging API
(`NEXT_PUBLIC_API_BASE_URL=https://staging.qayd.app` — see `../database` and `../api` docs for what
staging is seeded with), sign in through the actual `/login` screen with a staging demo user, and let
`lib/api/client.ts` handle CSRF-cookie priming and session-cookie forwarding exactly as production
does. There is no mocked-API mode for day-to-day development — every screen doc in this folder is
written against the real, documented `/api/v1` contract, and the fastest way to catch a contract
mismatch is to never insulate the frontend from it locally.

# Conventions

## Naming

| Thing | Convention | Example |
|---|---|---|
| Route segment folders | Plural kebab-case, mirroring the API resource name where one exists | `app/(app)/accounting/journal-entries/` matches `/api/v1/accounting/journal-entries` |
| Dynamic segments | camelCase inside brackets, named for the entity, not generically `id` | `[journalEntryId]`, `[bankAccountId]` |
| Component files | PascalCase matching the default export; one component per file | `ConfidenceBadge.tsx` exports `ConfidenceBadge` |
| Non-component files | kebab-case | `journal-entries.ts`, `use-trial-balance.ts` |
| Hooks | `use` + PascalCase resource noun; verb suffix only for a mutation | `useJournalEntries` (query), `usePostJournalEntry` (mutation) |
| Zod schemas | `<Entity>Schema`; inferred type via `z.infer` as `<Entity>Input` for write payloads | `JournalEntrySchema`, `JournalEntryInput` |
| Query key factories | One function per resource in `lib/query/keys.ts`, most-general key first | `journalEntryKeys.list(filters)`, `journalEntryKeys.detail(id)` |
| Permission constants | Never re-typed as string literals in components; imported from a generated `lib/auth/permissions.ts` mirroring the backend's permission-key grammar `<area>.<action>` / `<area>.<entity>.<action>` | `PERMISSIONS.ACCOUNTING_JOURNAL_POST` → `"accounting.journal.post"` |

## RSC vs. Client Components

The default is a **Server Component**. A file becomes a **Client Component** (`"use client"`) only
when it needs at least one of: browser state or effects, event handlers, a TanStack Query hook, a
Zustand store, or Framer Motion. This is enforced by convention and caught in review, not by tooling,
because the two are meant to interleave deliberately: a Server Component performs the route's first
authenticated fetch (so the first paint already has real data, with no client-visible loading
spinner on initial load) and hands that data down as `initialData`/`initialPage` to a thin Client
Component that mounts a matching `useQuery` for subsequent refetching, mutation, and realtime updates.

```tsx
// app/(app)/accounting/journal-entries/page.tsx — Server Component
import { getJournalEntries } from "@/lib/api/accounting";
import { JournalEntriesTable } from "@/components/accounting/journal-entries-table";

export default async function JournalEntriesPage({
  searchParams,
}: {
  searchParams: Promise<{ status?: string; cursor?: string }>;
}) {
  const params = await searchParams;
  const initialPage = await getJournalEntries({
    status: params.status,
    cursor: params.cursor,
  });

  return (
    <JournalEntriesTable
      initialPage={initialPage}
      filters={{ status: params.status }}
    />
  );
}
```

```tsx
// components/accounting/journal-entries-table.tsx — Client Component
"use client";

import { useQuery } from "@tanstack/react-query";
import { journalEntryKeys } from "@/lib/query/keys";
import { fetchJournalEntries } from "@/lib/api/accounting";
import { PermissionGate } from "@/components/shared/permission-gate";
import type { CursorPage, JournalEntry } from "@/types/api";

export function JournalEntriesTable({
  initialPage,
  filters,
}: {
  initialPage: CursorPage<JournalEntry>;
  filters: { status?: string };
}) {
  const { data } = useQuery({
    queryKey: journalEntryKeys.list(filters),
    queryFn: () => fetchJournalEntries(filters),
    initialData: initialPage,
    staleTime: 30_000,
  });

  return (
    <>
      {/* <DataTable> renders data.data; status/pagination read from data.meta.pagination */}
      <PermissionGate permission="accounting.journal.create">
        {/* "New entry" affordance — absent entirely for a role without this permission */}
      </PermissionGate>
    </>
  );
}
```

Server Actions (`"use server"`, colocated in `lib/actions/*.ts`) are used for mutations that do not
need optimistic UI or must survive without JavaScript — dismissing a notification, saving a settings
toggle. Anything that needs optimistic UI, inline error recovery inside a modal or drawer that must
not remount, or a pending state driving a spinner on a specific button (posting a journal entry,
committing a reconciliation match, approving an AI proposal) is a TanStack Query `useMutation`, not a
Server Action, so the component can own its own pending/error/optimistic state without a full-page
transition.

## Data Fetching & State Management

Every `/api/v1` call, from either a Server Component or a Client Component, goes through the single
fetch wrapper in `lib/api/client.ts`:

```ts
// lib/api/client.ts
export async function apiFetch<T>(
  path: string,
  init: RequestInit & { companyId?: string } = {},
): Promise<T> {
  const res = await fetch(`${process.env.NEXT_PUBLIC_API_BASE_URL}${path}`, {
    ...init,
    credentials: "include", // forwards the Sanctum session cookie
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(init.companyId ? { "X-Company-Id": init.companyId } : {}),
      ...(isMutating(init.method) ? { "X-XSRF-TOKEN": readXsrfCookie() } : {}),
      ...init.headers,
    },
  });

  const envelope = await res.json();
  if (!res.ok || !envelope.success) {
    throw new ApiError(envelope.message, envelope.errors, res.status, envelope.request_id);
  }
  return envelope.data as T;
}
```

This one function is what guarantees every screen document's `# Data & State` section can simply name
an endpoint and a query key, without re-explaining the envelope: `apiFetch` always unwraps
`{ success, data, message, errors, meta, request_id, timestamp }` and always throws a typed
`ApiError` on `success: false` or a non-2xx status, so both a `try/catch` in a Server Component and a
TanStack Query `onError` handler in a Client Component see the same shape.

Cursor pagination is uniform: every list hook accepts `{ cursor?: string; per_page?: number }` and
resolves `{ data: T[]; meta: { pagination: { cursor: string | null; total: number } } }`; genuinely
infinite lists (an activity feed, an audit trail) use `useInfiniteQuery` against the same key factory,
with `getNextPageParam: (last) => last.meta.pagination.cursor`.

RBAC gates the same way everywhere. `usePermission()` reads the resolved permission set fetched once
at session bootstrap; `<PermissionGate permission="...">` renders its children only if the current
user (for the active company) holds that key, matching the exact permission grammar and keys defined
in `../foundation/PERMISSION_SYSTEM.md` and used throughout the module docs (`accounting.journal.post`,
`bank.reconcile`, `bank.reconcile.close`, `ai.approve`, `reports.export`, …). Whether an ungranted
control is hidden entirely or rendered disabled-with-tooltip is a per-screen decision documented in
that screen's own `# AI Integration` / `# Accessibility` sections — `PermissionGate` supports both via
a `fallback` prop, and the choice is never left to the component author's default.

State ownership is intentionally narrow per technology so there is only ever one place to look for a
given piece of state:

| State | Owner | Never lives in |
|---|---|---|
| Anything that came from `/api/v1` | TanStack Query cache | Zustand, React Context, component `useState` |
| Sidebar collapsed, active drawer, command-palette open, wizard step | Zustand (`stores/ui-store.ts`) | Route search params (unless the state must survive a refresh or be linkable) |
| Session, active company, resolved permissions, locale, theme | React Context, set once by the `(app)` layout | Re-fetched or re-derived by individual screens |
| A single form's in-progress values | React Hook Form's internal state | Zustand or Context |
| Realtime push payloads (dashboard KPI ticks, new AI proposal, notification) | Merged directly into the relevant TanStack Query cache entry via `queryClient.setQueryData` from the Echo listener | A separate "realtime state" store that the UI has to reconcile against the query cache by hand |

## Internationalization & RTL

Every user-facing string is a key in both `lib/i18n/en.ts` and `lib/i18n/ar.ts`, sharing one
`Dictionary` type — a key present in one and missing in the other fails `npm run i18n:check` in CI,
not silently at runtime. Components read strings through `useTranslations(namespace)`:

```tsx
const { t } = useTranslations("journalEntries");
<Button>{t("postAction")}</Button>;
// en.ts: journalEntries.postAction = "Post entry"
// ar.ts: journalEntries.postAction = "ترحيل القيد"
```

Direction is set once, at the root: `<html lang={locale} dir={locale === "ar" ? "rtl" : "ltr"}>` in
`app/layout.tsx`, derived from the resolved session/browser locale. Every component below that root
respects direction automatically because none of them hard-code a physical side — spacing and
alignment utilities are logical (`ms-4`/`me-4`, `text-start`, `border-s`), never physical (`ml-4`,
`text-left`, `border-l`); Tailwind's `rtl:`/`ltr:` variants are reserved for the rare asset that must
visually flip rather than mirror correctly on its own (a "forward" chevron; most icons, including
every Lucide glyph QAYD uses, do not need this). There is exactly one component tree, rendered in
either direction — never a parallel `*.rtl.tsx` file.

Numbers, dates, and currency are always formatted through `Intl.NumberFormat` / `Intl.DateTimeFormat`,
parameterized by the active locale and the active company's base currency (KWD by default, with
AED/SAR/USD supported per `../foundation/../accounting` multi-currency rules) — never a hand-rolled
string template. Financial figures render Western Arabic numerals (`0123…`) even in the Arabic
locale, because mixed numeral systems inside a single ledger row read as an error to an accountant;
this is a deliberate `Intl.NumberFormat(locale, { numberingSystem: "latn", ... })` override, not an
oversight, and is configurable per company in Settings for the rare tenant that wants Eastern Arabic
numerals throughout.

# Documentation Index

Every other document in `docs/frontend/` is indexed here. Foundation/system documents define the
platform every screen is built from; screen specifications apply that platform to one route. Read the
relevant foundation documents before the screen you are implementing — a screen spec assumes them and
does not re-derive them.

## Foundation & System Documents

- [`FRONTEND_ARCHITECTURE.md`](./FRONTEND_ARCHITECTURE.md) — the App Router composition model in
  full: route groups and their layouts, the Server/Client Component boundary, the data-fetching
  architecture (Server Component first paint → Client Component `useQuery` handoff), Server Action
  usage, and how the web tier calls `/api/v1` as an ordinary, unprivileged surface per
  `../api/API_ARCHITECTURE.md` and `../api/INTERNAL_API.md`.
- [`DESIGN_LANGUAGE.md`](./DESIGN_LANGUAGE.md) — the canonical design tokens: the near-monochrome
  ink and single accent color, the display/text type pair and its scale, spacing scale, elevation,
  border/radius rules, and motion curves that every other document and every component in
  `components/ui/` implements.
- [`NAVIGATION_SYSTEM.md`](./NAVIGATION_SYSTEM.md) — the primary sidebar, topbar, command palette
  (`⌘K`/`Ctrl+K`), breadcrumb trail, and company/module switcher; how each reflects the active
  company context and RBAC (hidden vs. disabled items) established in `# Conventions` above.
- [`COMPONENT_LIBRARY.md`](./COMPONENT_LIBRARY.md) — the full shadcn/ui-based component catalogue:
  every primitive in `components/ui/` and composed pattern in `components/shared/`, with prop/variant
  tables, usage do's/don'ts, and accessibility notes per component.
- [`LAYOUT_SYSTEM.md`](./LAYOUT_SYSTEM.md) — the container grid, spacing scale, and the shared page
  templates (list page, detail page, workbench, multi-step wizard) that every screen spec below
  composes from rather than reinventing.
- [`RESPONSIVE_DESIGN.md`](./RESPONSIVE_DESIGN.md) — breakpoints and the desktop-first-but-tablet-capable
  posture for dense finance workbenches, including which screens (Trial Balance, Reconciliation)
  deliberately degrade to a read-only summary below a stated minimum width instead of compressing a
  dense grid past legibility.
- [`ACCESSIBILITY.md`](./ACCESSIBILITY.md) — the WCAG 2.1 AA baseline, keyboard-first interaction
  model, focus management, and screen-reader patterns for data-dense tables, multi-step approval
  flows, and AI confidence/reasoning affordances.
- [`DARK_MODE.md`](./DARK_MODE.md) — dark-palette derivation rules, contrast re-verification against
  the same AA baseline, and the system-aware/manual-override toggle persisted per user.
- [`THEMING.md`](./THEMING.md) — the CSS-variable token pipeline from `DESIGN_LANGUAGE.md` into
  `tailwind.config.ts` and the shadcn theme provider, including the narrow set of per-company
  white-label overrides (logo, accent color) that are permitted without touching structure.
- [`ICONOGRAPHY.md`](./ICONOGRAPHY.md) — Lucide usage rules (stroke width, sizing, pairing with
  text) and the small set of custom financial glyphs — confidence meter, reconciliation match,
  AI-agent avatars — not covered by the base icon set.

## Screen Specifications

- [`AI_COMMAND_CENTER.md`](./AI_COMMAND_CENTER.md) — `app/(app)/ai/command-center`: daily briefings,
  AI proposals and insights, the Approval Center, the Automation Center, and the Ask AI / Voice
  Assistant panel; the frontend contract for rendering confidence, reasoning, and source-document
  citations, and for gating every sensitive action behind `ai.approve` / `ai.automation`.
- [`DASHBOARD.md`](./DASHBOARD.md) — `app/(app)/dashboard`: the company home screen — KPI tiles,
  AI-curated Top Recommended Actions and Top Risks, realtime updates over the `.dashboard` Reverb
  channel, and role-aware widget visibility (a widget a role cannot otherwise see is never rendered
  read-only as a preview).
- [`ACCOUNTING.md`](./ACCOUNTING.md) — `app/(app)/accounting`: the Accounting module hub and its
  sub-navigation into Chart of Accounts, Journal Entries, General Ledger, Trial Balance, and
  Financial Statements, plus the module-level permission surface and the General Accountant / Auditor
  agents that annotate it.
- [`GENERAL_LEDGER.md`](./GENERAL_LEDGER.md) — `app/(app)/accounting/general-ledger`: the posted-lines
  explorer over `GET /api/v1/accounting/ledger-entries/search`, with account drill-down, period and
  dimension filters (cost center, project, department, branch), and drill-through to the originating
  source document.
- [`JOURNAL_ENTRIES.md`](./JOURNAL_ENTRIES.md) — `app/(app)/accounting/journal-entries`: the list,
  composer, and detail screens over `journal_entries`/`journal_lines` — draft/posted/reversed states,
  live debit-equals-credit validation in the line grid, and the review flow for an AI-drafted entry
  awaiting `accounting.journal.post`.
- [`TRIAL_BALANCE.md`](./TRIAL_BALANCE.md) — `app/(app)/accounting/trial-balance`: generation,
  review, and approval over `POST /api/v1/accounting/trial-balance/generate` and
  `GET /api/v1/accounting/trial-balance`, including AI-flagged findings and the
  export/approve/history actions.
- [`BALANCE_SHEET.md`](./BALANCE_SHEET.md) — `app/(app)/accounting/statements/balance-sheet`: the
  statement of financial position over `/api/v1/financial-statements/...` — comparative periods,
  consolidation scope, drill-to-account, export, and share.
- [`PROFIT_AND_LOSS.md`](./PROFIT_AND_LOSS.md) — `app/(app)/accounting/statements/profit-and-loss`:
  the income statement — period-over-period comparison, margin ratios, and variance highlighting fed
  by the Forecast and CFO agents.
- [`CASH_FLOW.md`](./CASH_FLOW.md) — `app/(app)/accounting/statements/cash-flow`: the cash-flow
  statement alongside the short-horizon AI cash-forecast panel (`GET /api/v1/ai/cash-flow/forecast`).
- [`BANKING.md`](./BANKING.md) — `app/(app)/banking`: bank accounts list, transaction feed,
  transfers, and statement-import screens over `/api/v1/banking/...`.
- [`BANK_RECONCILIATION.md`](./BANK_RECONCILIATION.md) — `app/(app)/banking/[bankAccountId]/reconciliation`:
  the reconciliation workbench — unmatched-statement-lines queue, AI match suggestions, manual
  matching, variance handling, and period close, gated by `bank.reconcile` / `bank.reconcile.close`.

# End of Document
