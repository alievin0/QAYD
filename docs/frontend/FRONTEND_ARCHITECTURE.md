# Frontend Architecture — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: FRONTEND_ARCHITECTURE
---

# Purpose

This document is the canonical engineering specification for the frontend layer of QAYD, the AI Financial Operating System. It defines the Next.js 15 application that a company's Owner, CFO, Finance Manager, Accountant, Auditor, Payroll Officer, Inventory Manager, and every other seat renders and interacts with in a browser or as an installed PWA, and it is the frontend counterpart to `API_ARCHITECTURE.md` (which owns the contract this application consumes), `DESIGN_SYSTEM.md` (which owns the visual language this application implements), `PERMISSION_SYSTEM.md` (which owns the authorization model this application renders and never re-derives), and `AI_COMMAND_CENTER.md` (which owns the product behavior of the AI-authored surfaces this application displays). Where those documents define *what is true* and *what is allowed*, this document defines *how a human sees and acts on it*: the App Router structure, the RSC/client component boundary, the data-fetching and caching architecture built on TanStack Query, the authentication and session model, client state management, the Laravel Reverb realtime wiring, form and validation architecture, the AI proposal/approval surfacing layer, error handling, performance budgets, the repository's folder structure, and the testing strategy.

The frontend's founding constraint, inherited directly from `API_ARCHITECTURE.md`'s own founding rule that "Laravel owns the API, and the API owns the data," is that **the frontend owns nothing**. It computes no tax, balances no journal entry, calculates no payroll, evaluates no permission from first principles, and commits no AI proposal on its own authority. Every screen in this specification is, structurally, a rendering of a `GET` response and a dispatcher of `POST`/`PATCH`/`DELETE` requests against `/api/v1/`. This is not a limitation to work around; it is what allows QAYD to ship a Next.js web client, a Flutter mobile client, and a FastAPI-driven AI engine that all agree, byte for byte, on what a balanced journal entry or an approved payroll run looks like — because none of them independently decided.

This document's scope covers everything an engineer or an AI coding agent needs to build, extend, or debug the frontend without reverse-engineering intent from source: how routes are organized and which segments render on the server versus the client; how data is fetched, cached, invalidated, and optimistically updated; how a session is established, refreshed, and switched between companies; where client state lives and why; how Laravel Reverb events reach a React tree; how forms validate against the same rules the API enforces server-side; how AI proposals, confidence scores, and human-approval gates are surfaced without ever collapsing into an auto-committed action; how errors and loading states are structured so no screen ever shows a blank void or an unrecoverable crash; the performance budgets the application is held to; the repository layout; and the Vitest/Playwright testing discipline. Screen-level specifications for individual pages (Dashboard, Journal Entries, Trial Balance, Bank Reconciliation, the AI Command Center panels, and so on) are written as their own documents and must conform to every architectural convention this document establishes; where a screen document appears to conflict with this one, this document is authoritative for cross-cutting frontend concerns and the screen document is authoritative for that screen's specific layout and interactions.

# Principles

QAYD's frontend follows eleven working principles, binding on every component and route added to the codebase, whether written by a human engineer or an AI coding agent, and checked explicitly in code review.

**1. The frontend never contains business or financial logic.** Whether a journal entry balances, whether a customer has exceeded their credit limit, whether a fiscal period is locked, whether a payroll run is ready to release — every one of these is a question the API answers, never a question a component recomputes locally as its source of truth. Client-side mirrors of a validation rule exist only as an immediate, optimistic UX convenience (so a user sees "lines don't balance" before round-tripping to the server) and are always re-validated by the `422` the server can still return; the frontend never trusts its own copy of a business rule over the server's answer.

**2. Server-first rendering, client islands for interactivity.** React Server Components are the default for every route. A component becomes a Client Component only when it needs something a server cannot provide: browser state (`useState`, `useEffect`), event handlers, TanStack Query hooks, Zustand stores, Laravel Echo subscriptions, Framer Motion, or a browser-only API (clipboard, `IntersectionObserver`, `localStorage`). This keeps the JavaScript shipped to a Finance Manager's browser proportional to what that screen actually needs to be interactive for, not to the whole application.

**3. AI proposes; a human (or an explicit, audited policy) approves.** Every AI-authored element — an insight, a recommendation, a drafted journal entry, a forecast — renders with its `confidence_score`, its `reasoning`, and its `sources`, in a visually distinct treatment (colored left border plus an "AI" badge, per `DESIGN_SYSTEM.md`'s AI Components and `FINANCIAL_STATEMENTS.md`'s stated convention), and no sensitive action (`bank.transfer`, `payroll.approve`, `tax.submit`, permission changes, deletions) is ever rendered with a one-click auto-execute affordance, regardless of confidence. The UI's "Do it" button, where it exists at all, is structurally absent for gated actions rather than merely disabled — there is no client-side code path that can execute one of these without the server-side approval chain the Approval Center owns.

**4. Respect RBAC by hiding and disabling, never by trusting the client.** A permission the current user lacks means the corresponding nav item, button, or field is hidden or disabled in the UI — this is a UX courtesy, not a security boundary. The security boundary is the `403` the API returns regardless of what the UI rendered; the frontend never assumes that hiding a button is sufficient and never skips handling the `403` a determined user could still trigger via devtools or a stale cached bundle.

**5. One design system, one accent, no exceptions.** Every screen is built from the shared component library (`components/ui`, shadcn/ui primitives styled to QAYD's tokens) and the shared design tokens (color, spacing, radius, type scale) defined in `DESIGN_SYSTEM.md`. No screen introduces a one-off color, a bespoke button style, or an ad hoc spacing value; new visual needs are added to the token set and the shared library first, then consumed, never bolted on locally.

**6. Full parity between English/LTR and Arabic/RTL, and between light and dark.** Every component ships both directions and both themes as first-class states, verified in the same pull request that introduces the component — never as a follow-up pass. Logical CSS properties (`ms-`, `me-`, `ps-`, `pe-` in Tailwind's RTL-aware utilities) are used in place of physical ones (`ml-`, `mr-`) everywhere direction can flip.

**7. Type safety is end-to-end, generated, not hand-maintained.** The TypeScript types for every API resource are generated from the Laravel-served OpenAPI contract (`GET /api/v1/openapi.json`, per `API_ARCHITECTURE.md`) via `openapi-typescript`, never hand-written and re-typed from documentation. A breaking API change surfaces as a TypeScript compile error in CI before it ships as a runtime bug.

**8. Server state lives in TanStack Query; nothing else is allowed to cache API data.** There is exactly one cache for anything that came from `/api/v1/`. Zustand and React Context hold client-only state (UI preferences, ephemeral form-builder state, the current user/company/permission snapshot). A component that finds itself copying a query result into `useState` "just to tweak it" is a code-review finding, not a pattern.

**9. Every unsafe mutation is idempotent from the client's own hand.** Per `API_ARCHITECTURE.md`'s idempotency contract, every financial-mutation `POST`/`PATCH` the frontend issues carries a client-generated `Idempotency-Key`, generated once per logical submission attempt and reused across retries of that same attempt — a flaky connection on a Kuwait mobile network must never be able to double-post a journal entry because a spinner sat too long and a user tapped twice.

**10. Optimistic where safe, pessimistic where it moves money.** A reversible, low-stakes interaction (dismissing an insight, reordering a dashboard widget, marking a notification read) updates the UI instantly and reconciles quietly in the background. A financial mutation (posting a journal entry, approving a payroll run, submitting a tax return) waits for the server's `2xx` before the UI treats it as done, and always renders a confirming dialog before it fires.

**11. Accessibility and reduced motion are not an afterthought pass.** AA contrast, full keyboard operability, ARIA live regions for streaming AI content, and `prefers-reduced-motion` handling in every Framer Motion animation are requirements of "done," identical in weight to the component rendering the correct data.

# App Router Structure

QAYD's Next.js 15 application uses the App Router exclusively — there is no `pages/` directory anywhere in the codebase. Routes are organized into three top-level route groups, none of which affects the URL path (route groups exist purely to give each area of the application its own root layout without nesting the URL):

- **`(marketing)`** — the public, unauthenticated marketing site (pricing, product pages, blog). Out of scope for this document; it is statically generated, has no dependency on the API beyond a public content endpoint, and shares only the design tokens with the application described here.
- **`(auth)`** — login, MFA challenge, password reset, and the post-login company-selection step. No sidebar, no data tables, minimal JavaScript.
- **`(app)`** — the authenticated product itself: everything behind the sidebar shell. This is the group this document specifies in depth below.

## Route tree

```
app/
├── layout.tsx                          # Root layout: <html>, fonts, ThemeProvider, dir="ltr|rtl"
├── globals.css                         # Tailwind base + design tokens (CSS variables)
│
├── (marketing)/
│   ├── layout.tsx
│   └── page.tsx                        # Public marketing home (out of scope here)
│
├── (auth)/
│   ├── layout.tsx                      # Centered card layout, no sidebar, locale switcher only
│   ├── login/
│   │   └── page.tsx                    # Server Component shell + <LoginForm /> (client)
│   ├── mfa/
│   │   └── page.tsx                    # Renders only if an mfa_token is present (see Authentication)
│   ├── forgot-password/page.tsx
│   ├── reset-password/page.tsx
│   └── select-company/
│       └── page.tsx                    # Shown when a user's token maps to >1 company and none is "last active"
│
└── (app)/
    ├── layout.tsx                      # Shell: <Sidebar/> <Topbar/> <CompanySwitcher/> <CommandPalette/>
    ├── loading.tsx                     # Shell-level skeleton (route-level Suspense fallback)
    ├── error.tsx                       # Shell-level error boundary
    ├── template.tsx                    # Per-navigation view-transition wrapper (Framer Motion page fade)
    │
    ├── dashboard/
    │   ├── page.tsx                    # AI Command Center home — see AI_COMMAND_CENTER.md
    │   └── @modal/(.)widgets/[id]/settings/page.tsx   # Intercepted route: widget settings as a modal
    │
    ├── accounting/
    │   ├── layout.tsx                  # Sub-nav: Chart of Accounts | Journal Entries | Ledger | Trial Balance
    │   ├── accounts/
    │   │   ├── page.tsx                # Chart of Accounts tree/table
    │   │   └── [accountId]/page.tsx
    │   ├── journal-entries/
    │   │   ├── page.tsx                # List (filter/sort/paginate)
    │   │   ├── new/page.tsx            # Full-page create (mirrors @modal quick-create)
    │   │   └── [entryId]/
    │   │       ├── page.tsx            # Detail: lines, status, audit trail, AI reasoning if AI-drafted
    │   │       └── reverse/page.tsx
    │   ├── ledger/
    │   │   └── page.tsx                # GET /api/v1/accounting/ledger-entries (cursor-paginated, virtualized)
    │   ├── trial-balance/
    │   │   └── page.tsx
    │   ├── fiscal-periods/page.tsx
    │   ├── cost-centers/page.tsx
    │   └── projects/page.tsx
    │
    ├── financial-statements/
    │   ├── page.tsx                    # Statement picker (Balance Sheet | P&L | Cash Flow)
    │   └── [statementType]/page.tsx
    │
    ├── banking/
    │   ├── layout.tsx
    │   ├── accounts/page.tsx
    │   ├── transactions/page.tsx
    │   ├── reconciliations/[id]/page.tsx
    │   └── transfers/
    │       ├── page.tsx
    │       └── new/page.tsx            # Sensitive — always full-page, never a quick-create modal
    │
    ├── sales/
    │   ├── layout.tsx
    │   ├── customers/[[...segment]]/page.tsx
    │   ├── quotations/page.tsx
    │   ├── orders/page.tsx
    │   ├── invoices/
    │   │   ├── page.tsx
    │   │   └── [invoiceId]/page.tsx
    │   ├── credit-notes/page.tsx
    │   └── receipts/page.tsx
    │
    ├── purchasing/
    │   ├── vendors/page.tsx
    │   ├── purchase-orders/page.tsx
    │   ├── bills/page.tsx
    │   └── vendor-payments/page.tsx
    │
    ├── inventory/
    │   ├── products/page.tsx
    │   ├── items/page.tsx
    │   ├── movements/page.tsx
    │   ├── transfers/page.tsx
    │   ├── counts/[countId]/page.tsx
    │   └── warehouses/page.tsx
    │
    ├── payroll/
    │   ├── employees/[employeeId]/page.tsx
    │   ├── runs/
    │   │   ├── page.tsx
    │   │   └── [runId]/page.tsx        # Calculate → Submit → Approve → Post → Disburse, one page, tab per stage
    │   ├── payslips/page.tsx
    │   ├── attendance/page.tsx
    │   └── loans/page.tsx
    │
    ├── tax/
    │   ├── codes/page.tsx
    │   └── returns/[returnId]/page.tsx
    │
    ├── reports/
    │   ├── page.tsx                    # Report definition library
    │   ├── [definitionId]/page.tsx
    │   └── runs/[runId]/page.tsx       # Streams status via private-company.{id}.report-runs.{id}
    │
    ├── ai/
    │   ├── layout.tsx                  # Sub-nav mirrors AI Command Center panel taxonomy
    │   ├── insights/page.tsx           # Full-page feed behind GET /api/v1/ai/insights
    │   ├── recommendations/page.tsx    # GET /api/v1/ai/recommendations
    │   ├── risks/page.tsx              # GET /api/v1/ai/risks?category=all
    │   ├── forecast/page.tsx           # GET /api/v1/ai/cash-flow/forecast
    │   └── chat/page.tsx               # Streaming "Ask AI" — see AI Integration Layer
    │
    ├── approvals/
    │   ├── page.tsx                    # The Approval Center queue — GET /api/v1/approvals
    │   └── [approvalId]/page.tsx
    │
    └── settings/
        ├── layout.tsx
        ├── company/page.tsx
        ├── branches/page.tsx
        ├── departments/page.tsx
        ├── users/page.tsx
        ├── roles/page.tsx              # Permission Studio (see PERMISSION_SYSTEM.md, Long-Term Vision)
        ├── api-keys/page.tsx
        ├── webhooks/page.tsx
        └── billing/page.tsx

app/api/                                # Route Handlers — BFF concerns only, never business logic
├── auth/
│   ├── login/route.ts                  # Proxies to POST /api/v1/auth/login, sets httpOnly cookies
│   ├── refresh/route.ts
│   ├── switch-company/route.ts
│   └── logout/route.ts
├── broadcasting/
│   └── auth/route.ts                   # Proxies Echo's channel-auth handshake with the httpOnly bearer
└── ai/
    └── chat/route.ts                   # SSE proxy for streaming chat completions
```

## Layout nesting and composition

The root `layout.tsx` renders `<html lang={locale} dir={direction}>`, loads the Schibsted-neutral grotesque display face and the text face referenced in `DESIGN_SYSTEM.md` via `next/font`, and wraps children in a `ThemeProvider` (light/dark, system-aware) and a top-level `I18nProvider`. It does not know about authentication or companies — that begins one level down.

The `(app)/layout.tsx` is a Server Component that resolves the session server-side (see Authentication & Session), fetches `GET /api/v1/auth/me` once per navigation-root render, and passes the result down through a client `SessionProvider`. It renders the persistent chrome — `<Sidebar>` (nav items filtered by the permissions array from `/me`), `<Topbar>` (company switcher, notifications bell wired to a `private-company.{id}.notifications.{user_id}` channel, AI status pulse, user menu), and a `<CommandPalette>` (⌘K) — around a `{children}` slot that every route below renders into:

```tsx
// app/(app)/layout.tsx
import { getSession } from "@/lib/auth/session";
import { SessionProvider } from "@/components/providers/session-provider";
import { RealtimeProvider } from "@/components/providers/realtime-provider";
import { Sidebar } from "@/components/layout/sidebar";
import { Topbar } from "@/components/layout/topbar";

export default async function AppLayout({ children }: { children: React.ReactNode }) {
  const session = await getSession(); // reads httpOnly cookies, redirects via middleware if absent
  const me = await fetchMe(session);  // GET /api/v1/auth/me, cache: "no-store" — tenant-scoped, never shared

  return (
    <SessionProvider user={me.user} activeCompany={me.active_company} companies={me.companies}>
      <RealtimeProvider companyId={me.active_company.id} userId={me.user.id}>
        <div className="flex h-dvh">
          <Sidebar permissions={me.active_company.permissions} />
          <div className="flex flex-1 flex-col overflow-hidden">
            <Topbar />
            <main className="flex-1 overflow-y-auto p-6">{children}</main>
          </div>
        </div>
      </RealtimeProvider>
    </SessionProvider>
  );
}
```

Module-level layouts (`accounting/layout.tsx`, `banking/layout.tsx`, `sales/layout.tsx`) add a second-tier sub-navigation (tabs) scoped to that module, and are themselves Server Components — the tabs are `<Link>`s, not client-side tab state, so each is a real navigable, bookmarkable, back-button-safe URL.

## Parallel and intercepting routes for quick-create

Several modules need a "create without leaving the list" affordance without duplicating the creation form as two separate implementations. QAYD uses Next.js intercepting routes for this: `journal-entries/new/page.tsx` is the real, full-page, linkable route; a sibling `@modal/(.)journal-entries/new/page.tsx` intercepts navigation to that same URL when it originates from within the list page and renders it in a `<Dialog>` instead. Deep-linking or a hard refresh always lands on the full page — the modal is a presentational convenience over the identical route and the identical Server Action / TanStack Query mutation, never a second implementation of the form.

```
app/(app)/accounting/journal-entries/
├── page.tsx
├── new/page.tsx                        # Canonical full-page route
└── @modal/
    └── (.)new/page.tsx                 # Intercepted: same page, rendered in a modal when linked-to from the list
```

## Dynamic segments, loading, and error conventions

Every leaf route that fetches a single resource by id uses a dynamic segment (`[entryId]`, `[invoiceId]`) and colocates three files as a matter of house style, not merely when convenient: `page.tsx` (the Server Component that fetches and renders), `loading.tsx` (a skeleton matching the eventual layout's shape exactly, never a generic spinner — per `DESIGN_SYSTEM.md`'s "never blank" rule), and `error.tsx` (a Client Component boundary that renders a recoverable, permission-aware error state rather than the Next.js default). `not-found.tsx` is used for the specific case of a resource that does not exist in the caller's own company — see Error Handling & Boundaries for how this is deliberately indistinguishable, from the outside, from a resource that exists in a different company entirely.

# Rendering Strategy

## RSC by default

Every `page.tsx` in `(app)/` starts life as a Server Component. It fetches whatever data the initial paint needs directly, server-side, using the server-only API client (see Data Layer), and renders as far down the tree as it can before handing off to a Client Component boundary. A React Server Component in QAYD never ships its data-fetching code to the browser bundle, never exposes the bearer token to client JavaScript, and never re-fetches on the client what it already rendered on the server — the corresponding TanStack Query cache is *hydrated* from the server fetch (see Data Layer's SSR hydration pattern), not independently re-requested.

```tsx
// app/(app)/accounting/journal-entries/[entryId]/page.tsx
import { apiServer } from "@/lib/api/server-client";
import { JournalEntryDetail } from "@/components/accounting/journal-entry-detail";

export default async function JournalEntryPage({ params }: { params: { entryId: string } }) {
  const entry = await apiServer.get(`/accounting/journal-entries/${params.entryId}`);
  // entry.data is fully typed from the generated OpenAPI types; entry.data.ai_reasoning is
  // present only if the caller's role has AI-visibility scope (see PERMISSION_SYSTEM.md field-level note)
  return <JournalEntryDetail initialData={entry.data} entryId={params.entryId} />;
}
```

`JournalEntryDetail` itself is a thin Client Component that takes `initialData` as the TanStack Query `initialData`/hydration seed and immediately becomes responsible for everything interactive from that point forward — approve/reverse buttons, the realtime subscription, the AI reasoning popover. The Server Component's job ends the moment the first paint is described; it does not re-run on every user click the way a client-fetched page would.

## Streaming with Suspense

Screens composed of many independent panels — the AI Command Center dashboard above all, with its Morning Briefing, Business Health Score, Cash Flow Status, Revenue Trends, Detected Risks, and a dozen other widgets each backed by its own endpoint — never wait for the slowest panel to block the first paint. Each widget is wrapped in its own `<Suspense>` boundary with a skeleton `fallback`, and the page's Server Component streams the shell immediately while each panel's data-fetching component resolves independently:

```tsx
// app/(app)/dashboard/page.tsx
import { Suspense } from "react";
import { MorningBriefing } from "@/components/dashboard/morning-briefing";
import { BusinessHealthScore } from "@/components/dashboard/business-health-score";
import { CashFlowStatus } from "@/components/dashboard/cash-flow-status";
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";

export default function DashboardPage() {
  return (
    <div className="grid grid-cols-12 gap-4">
      <Suspense fallback={<WidgetSkeleton span={12} />}>
        <MorningBriefing />                {/* GET /api/v1/ai/briefings/{date} */}
      </Suspense>
      <Suspense fallback={<WidgetSkeleton span={4} />}>
        <BusinessHealthScore />             {/* GET /api/v1/ai/health-score */}
      </Suspense>
      <Suspense fallback={<WidgetSkeleton span={4} />}>
        <CashFlowStatus />                  {/* GET /api/v1/banking/accounts + /api/v1/ai/cash-flow/forecast */}
      </Suspense>
      {/* ...remaining widgets, each independently streamed */}
    </div>
  );
}
```

A slow Forecast Agent computation never delays the Morning Briefing from appearing, and — per Error Handling & Boundaries — a failure in one widget's data fetch never takes down the rest of the dashboard, because each `Suspense` boundary is paired with its own `error.tsx`-equivalent (an `<ErrorBoundary>` from `react-error-boundary`, since Next's file-based `error.tsx` operates at the route level, not the component level).

## Client Component boundaries

A component adds `"use client"` when, and only when, it needs one of: local interactive state, a TanStack Query hook (`useQuery`/`useMutation`/`useInfiniteQuery`), a Zustand store, a Laravel Echo subscription, Framer Motion, React Hook Form, or a browser API. Everything else — layout shells, static labels, server-fetched tables passed pre-rendered rows — stays a Server Component. In practice this means the *leaf* interactive controls (an `<ApproveButton>`, a `<JournalLineRow>` with inline editing, a `<StatusFilterDropdown>`) are client islands inside an otherwise server-rendered page shell, not the page itself becoming a client component wholesale — the common anti-pattern of stamping `"use client"` at the top of `page.tsx` and losing every RSC benefit for the whole route is a code-review rejection.

## Server Actions vs. TanStack Query mutations

QAYD uses both, for different jobs, and the choice is deliberate rather than a matter of engineer preference:

- **Server Actions** are used for simple, low-frequency, progressively-enhanceable form submissions where the page navigates or fully revalidates afterward anyway — company profile settings, inviting a user, updating a branch address. These benefit from working without client JavaScript and from Next's built-in `revalidatePath`/`revalidateTag` integration.
- **TanStack Query mutations** are used for anything inside an already-interactive, client-rendered surface where the UI needs to reflect the in-flight and optimistic state precisely — approving an approval request, posting a journal entry from a detail view, adjusting inventory from a table row. These need `isPending`, `onMutate` optimistic updates, and precise cache invalidation that a Server Action's coarser revalidation model does not give fine-grained control over.

The dividing line in one sentence: if the surface is already a client-rendered, TanStack-Query-backed view (which almost everything past the first paint is), mutate through TanStack Query; reach for a Server Action only for an isolated, mostly-static settings form that has no other client-side query state to keep in sync.

## Tenant-scoped data is never statically cached

Because every byte of data in QAYD belongs to exactly one company, no Server Component fetch that is scoped by `X-Company-Id` may use Next's default `fetch` caching (`force-cache`) or route-level static generation. Every such route sets:

```ts
export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store";
```

and every server-side `fetch` call the API client makes explicitly passes `cache: "no-store"`. Static generation and the Next.js Data Cache remain available — and are used — only for the truly public, non-tenant `(marketing)` route group and for reference data with no tenant dimension at all (e.g., a public list of supported currencies). This is treated with the same severity as the backend's row-level tenant isolation: a CDN or Next.js cache entry that mixed one company's dashboard HTML into another company's request would be as serious a breach as a missing `WHERE company_id` clause, so the frontend simply removes the caching layer that could do it rather than relying on cache keys alone to get it right every time.

## Partial Prerendering (experimental)

For the small set of routes with a genuinely static shell and a narrow dynamic hole — the `(auth)` login screen's card chrome around a dynamic CSRF/session check, for instance — QAYD opts into Next 15's Partial Prerendering (`experimental_ppr = true` at the route segment) so the static shell is served instantly from the edge while the dynamic hole streams in. PPR is not enabled application-wide and is never enabled on any `(app)/` route, because every one of those routes is tenant-scoped and dynamic by the rule above; it is a targeted optimization for the handful of screens that are mostly chrome around a small dynamic core, not a default posture.

# Data Layer

## Two fetching paths, one cache

QAYD's data layer has exactly two places a component ever gets server data from, and they are designed to hand off to each other rather than compete:

1. **Server Components fetch directly** against the API using `lib/api/server-client.ts`, for the data needed to produce the first paint.
2. **Client Components read exclusively through TanStack Query**, for everything after hydration — refetching, pagination, mutations, realtime-triggered invalidation, and polling.

The two are connected by the standard Next.js + TanStack Query SSR pattern: a Server Component that fetches data for a client subtree also seeds a `QueryClient`, `dehydrate`s it, and wraps the subtree in a `HydrationBoundary`, so the client's first `useQuery` call for that same query key resolves instantly from the hydrated cache instead of re-issuing the request the server already made:

```tsx
// app/(app)/accounting/journal-entries/page.tsx
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { journalEntryKeys } from "@/lib/api/query-keys";
import { apiServer } from "@/lib/api/server-client";
import { JournalEntriesTable } from "@/components/accounting/journal-entries-table";

export default async function JournalEntriesPage({
  searchParams,
}: { searchParams: Record<string, string> }) {
  const queryClient = getQueryClient();
  const filters = parseJournalEntryFilters(searchParams);

  await queryClient.prefetchQuery({
    queryKey: journalEntryKeys.list(filters),
    queryFn: () => apiServer.get("/accounting/journal-entries", { params: filters }),
  });

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <JournalEntriesTable initialFilters={filters} />
    </HydrationBoundary>
  );
}
```

## The API client and the envelope

There is exactly one HTTP client shape, implemented twice (a server variant using Next's `fetch` with `no-store` and cookie-forwarded auth, and a client variant used inside TanStack Query hooks), both unwrapping the identical response envelope QAYD's API commits to on every response:

```ts
// lib/api/http.ts
export interface ApiEnvelope<T> {
  success: boolean;
  data: T | null;
  message: string;
  errors: Array<{ code: string; field: string | null; message: string; meta?: Record<string, unknown> }>;
  meta: { pagination: PaginationMeta | null };
  request_id: string;
  timestamp: string;
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly envelope: ApiEnvelope<null>,
  ) {
    super(envelope.message);
  }
  get code() { return this.envelope.errors[0]?.code ?? "UNKNOWN_ERROR"; }
  get fieldErrors() { return this.envelope.errors; }
}

export async function unwrap<T>(res: Response): Promise<T> {
  const envelope = (await res.json()) as ApiEnvelope<T>;
  if (!res.ok || !envelope.success) throw new ApiError(res.status, envelope as ApiEnvelope<null>);
  return envelope.data as T;
}
```

```ts
// lib/api/client.ts  (browser-side; used inside TanStack Query hooks)
import { unwrap } from "./http";
import { useSessionStore } from "@/lib/stores/session-store";

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const { activeCompanyId, locale } = useSessionStore.getState();
  const res = await fetch(`/api/v1${path}`, {
    ...init,
    headers: {
      "Content-Type": "application/json",
      "X-Company-Id": String(activeCompanyId),
      "Accept-Language": locale,
      ...(init.headers ?? {}),
    },
    credentials: "include", // httpOnly access-token cookie travels automatically; see Authentication
  });
  return unwrap<T>(res);
}

export const api = {
  get: <T>(path: string, opts?: { params?: Record<string, unknown> }) =>
    request<T>(withQuery(path, opts?.params)),
  post: <T>(path: string, body?: unknown, idempotencyKey?: string) =>
    request<T>(path, {
      method: "POST",
      body: JSON.stringify(body ?? {}),
      headers: idempotencyKey ? { "Idempotency-Key": idempotencyKey } : {},
    }),
  patch: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: "PATCH", body: JSON.stringify(body ?? {}) }),
  delete: <T>(path: string) => request<T>(path, { method: "DELETE" }),
};
```

Note that the browser client calls its *own* Next.js origin (`/api/v1/...`), not `api.qayd.com` directly. A thin Next.js Route Handler segment (`app/api/v1/[...path]/route.ts`) proxies these to the real Laravel API, attaching the bearer token read from the httpOnly cookie server-side — the browser's JavaScript never holds the token in a readable form. This proxy is intentionally "dumb": it forwards method, body, and query string unchanged, injects `Authorization` and re-validates `X-Company-Id` against the session, and streams the response back verbatim; it contains no business logic, matching Principle 1.

## Query key architecture

Query keys are produced exclusively by a typed factory per resource, never as ad hoc inline arrays, so that invalidation from a mutation or a realtime event can target exactly the right slice of the cache without guessing at a hand-written key's shape elsewhere in the codebase:

```ts
// lib/api/query-keys.ts
export const journalEntryKeys = {
  all: ["accounting", "journal-entries"] as const,
  list: (filters: JournalEntryFilters) => [...journalEntryKeys.all, "list", filters] as const,
  detail: (id: number) => [...journalEntryKeys.all, "detail", id] as const,
};

export const approvalKeys = {
  all: ["approvals"] as const,
  queue: (filters: ApprovalFilters) => [...approvalKeys.all, "queue", filters] as const,
  detail: (id: number) => [...approvalKeys.all, "detail", id] as const,
};

export const ledgerKeys = {
  all: ["accounting", "ledger-entries"] as const,
  infinite: (filters: LedgerFilters) => [...ledgerKeys.all, "infinite", filters] as const,
};
```

Every top-level key array is scoped implicitly by company because `QueryClient` itself is re-created on company switch (see Authentication & Session) rather than sharing one client across companies — this is a deliberate defense-in-depth choice mirroring the backend's own layered tenant isolation (`GENERAL_LEDGER.md`'s "QAYD never merges two companies' ledgers in a single query"): even if a component forgot to invalidate on switch, there is no live cache instance left holding the previous company's entries for it to accidentally serve.

## Cache tuning by data class

`staleTime` and `gcTime` are set per resource class, not globally defaulted, because a chart of accounts and a live ledger have very different volatility:

| Data class | Examples | `staleTime` | Rationale |
|---|---|---|---|
| Rarely-changing reference/master data | `accounts`, `cost-centers`, `tax-codes`, `warehouses` | 5 minutes | Changes are infrequent, human-initiated, and not time-critical to reflect instantly. |
| Transactional lists | `journal-entries`, `invoices`, `bills`, `purchase-orders` | 30 seconds | Frequent enough writes that a stale list is a real annoyance, but not worth polling. |
| Live/derived figures | `ledger-entries`, dashboard widgets, `bank-accounts` balances | 0 (always considered stale) | Correctness matters more than avoiding a refetch; these are additionally kept fresh via Realtime invalidation rather than polling. |
| AI feeds | `ai/insights`, `ai/recommendations`, `ai/risks` | 10 seconds, `refetchOnWindowFocus: true` | Async, agent-produced; a returning user should see what accumulated while they were away. |

```ts
// lib/api/query-client.ts
export function makeQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        gcTime: 5 * 60_000,
        retry: (failureCount, error) => isRetryable(error) && failureCount < 3,
        refetchOnWindowFocus: false, // overridden per-query for AI feeds, per table above
      },
      mutations: { retry: false }, // mutations are never blindly retried — see Error Handling & Boundaries
    },
  });
}
```

## Optimistic updates

Reversible, non-financial actions update the cache optimistically and roll back on failure. The Approval Center's "Approve" action is the canonical example — the card should feel instant even though it is, underneath, a sensitive-adjacent action gated by `ai.approve`/the target's own permission:

```ts
// lib/api/hooks/use-approve-request.ts
export function useApproveRequest(approvalId: number) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (comment?: string) =>
      api.post(`/approvals/${approvalId}/approve`, { comment }, crypto.randomUUID()),
    onMutate: async (comment) => {
      await queryClient.cancelQueries({ queryKey: approvalKeys.detail(approvalId) });
      const previous = queryClient.getQueryData(approvalKeys.detail(approvalId));
      queryClient.setQueryData(approvalKeys.detail(approvalId), (old: ApprovalRequest) => ({
        ...old,
        status: "approved",
        steps: markCurrentStepApproved(old.steps, comment),
      }));
      return { previous };
    },
    onError: (_err, _vars, context) => {
      queryClient.setQueryData(approvalKeys.detail(approvalId), context?.previous);
      toast.error(t("approvals.approveFailed"));
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: approvalKeys.all });
      queryClient.invalidateQueries({ queryKey: ["dashboard"] }); // urgent-actions count badge
    },
  });
}
```

Money-moving mutations (`bank.transfer`, `payroll.run.post`) deliberately omit `onMutate` — no optimistic state at all — so the UI only ever shows "transferred" after the server has actually said so, per Principle 10.

## Pagination: page mode vs. cursor mode

The frontend mirrors `API_PAGINATION.md`'s per-resource strategy exactly rather than picking one pattern for every table. Bounded lists (`accounts`, `invoices`, `vendors`) use `useQuery` with `page`/`per_page` and `placeholderData: keepPreviousData` so the table doesn't flash empty while a new page loads:

```ts
export function useJournalEntries(filters: JournalEntryFilters) {
  return useQuery({
    queryKey: journalEntryKeys.list(filters),
    queryFn: () => api.get<JournalEntry[]>("/accounting/journal-entries", { params: filters }),
    placeholderData: keepPreviousData,
  });
}
```

Unbounded, append-only tables (`ledger-entries`, `audit-logs`, `stock-movements`) use `useInfiniteQuery` against the cursor contract, feeding a virtualized table (see Performance) rather than a "page 4 of 190" control that the API itself does not support for these resources:

```ts
export function useLedgerEntries(filters: LedgerFilters) {
  return useInfiniteQuery({
    queryKey: ledgerKeys.infinite(filters),
    queryFn: ({ pageParam }) =>
      api.get<LedgerPage>("/accounting/ledger-entries", { params: { ...filters, cursor: pageParam } }),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.meta.pagination.next_cursor,
  });
}
```

# Authentication & Session

## Token custody: httpOnly cookies, not client-readable storage

QAYD's API issues a Sanctum/JWT access token (900-second lifetime) and a refresh token (30-day lifetime) on login, per `AUTHENTICATION_API.md`. The frontend never stores either token anywhere client JavaScript can read — not `localStorage`, not `sessionStorage`, not a client-side cookie without `httpOnly`. Instead, the Next.js Route Handlers under `app/api/auth/` act as the only code in the frontend that ever sees a raw token:

```ts
// app/api/auth/login/route.ts
export async function POST(req: Request) {
  const body = await req.json();
  const res = await fetch(`${process.env.QAYD_API_URL}/api/v1/auth/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const envelope = await res.json();
  if (!envelope.success) {
    return Response.json(envelope, { status: res.status });
  }
  if (envelope.data.status === "mfa_required") {
    // No cookies set yet — the mfa_token is short-lived and passed to the client only
    // to be immediately resubmitted to /api/auth/mfa/verify; it cannot act as a session token.
    return Response.json(envelope);
  }

  const response = Response.json({
    success: true,
    data: { user: envelope.data.user, companies: envelope.data.companies, active_company_id: envelope.data.active_company_id },
    message: envelope.message, errors: [], meta: { pagination: null },
    request_id: envelope.request_id, timestamp: envelope.timestamp,
  });
  setSessionCookies(response, envelope.data); // httpOnly, Secure, SameSite=Lax, access + refresh + active_company_id
  return response;
}
```

The browser never receives `access_token` or `refresh_token` in a JSON body it could read — `setSessionCookies` writes them as `httpOnly` cookies in the same response, and the sanitized body returned to the client contains only what the UI needs to render (`user`, `companies`, `active_company_id`). This closes the most common XSS-to-token-theft path outright: a script-injection vulnerability elsewhere on the page cannot read a cookie it has no access to.

## Middleware: route protection and token refresh

`middleware.ts` runs on every request to an `(app)/*` path, checks for the presence and expiry of the access-token cookie, and redirects unauthenticated requests to `/login` before any Server Component in the matched route even begins rendering:

```ts
// middleware.ts
import { NextResponse, type NextRequest } from "next/server";
import { decodeJwtExpiry } from "@/lib/auth/jwt";

export function middleware(req: NextRequest) {
  const accessToken = req.cookies.get("qayd_at")?.value;
  const refreshToken = req.cookies.get("qayd_rt")?.value;

  if (!accessToken && !refreshToken) {
    return NextResponse.redirect(new URL("/login", req.url));
  }

  if (!accessToken || decodeJwtExpiry(accessToken) < Date.now() / 1000 + 30) {
    // Access token missing or expiring within 30s: let the request through, but flag it —
    // a Server Component-level fetch failure with TOKEN_EXPIRED triggers a silent refresh
    // (app/api/auth/refresh) exactly once per request, never a redirect loop.
    const res = NextResponse.next();
    res.headers.set("x-qayd-refresh-hint", "1");
    return res;
  }

  return NextResponse.next();
}

export const config = { matcher: ["/(app)/:path*"] };
```

Refresh itself is a single-flight operation: concurrent requests across multiple browser tabs that all discover an expired token within the same instant do not each independently call `/auth/refresh` and race to rotate the refresh token (which would invalidate the others). The client-side API wrapper serializes refresh attempts through a module-level promise so only the first caller actually hits the network and every concurrent caller awaits the same in-flight promise — see Edge Cases for the cross-tab race this specifically prevents.

## `GET /me`, permissions, and the `SessionProvider`

Every `(app)/layout.tsx` render resolves `GET /api/v1/auth/me` server-side and hydrates a client `SessionProvider` with `user`, `active_company` (including its `permissions` array), and `companies`. Permission checks anywhere in the client tree go through a single hook rather than components reaching into the array by hand:

```tsx
// lib/auth/use-permission.ts
export function usePermission(permission: string): boolean {
  const permissions = useSessionStore((s) => s.activeCompany.permissions);
  return permissions.includes(permission);
}

// components/auth/can.tsx
export function Can({ permission, children, fallback = null }: CanProps) {
  const allowed = usePermission(permission);
  return allowed ? <>{children}</> : <>{fallback}</>;
}
```

```tsx
// Usage inside a journal entry detail view
<Can permission="accounting.journal.post">
  <PostJournalEntryButton entryId={entry.id} />
</Can>
<Can permission="accounting.journal.post" fallback={<DisabledWithTooltip label={t("common.noPermission")} />}>
  <ReverseJournalEntryButton entryId={entry.id} />
</Can>
```

`Can` is a UX courtesy exactly as Principle 4 describes: it prevents a Senior Accountant without `accounting.journal.post` from seeing a button that would only fail, but the button's own mutation handler still surfaces a normal `403` gracefully if it is ever reached (a stale permissions snapshot in an old tab, for instance) — the frontend never assumes `Can`'s absence is the only thing standing between a click and an unauthorized write.

## MFA challenge flow

A login that returns `status: "mfa_required"` renders `(auth)/mfa/page.tsx`, carrying the short-lived `mfa_token` (never a real session credential) in client state only, and posts the chosen method's code to `app/api/auth/mfa/verify/route.ts`, which forwards it to Laravel and, only on success, performs the same `setSessionCookies` step login does. The MFA screen never writes any cookie itself before that point — an abandoned MFA challenge leaves no session artifact behind.

## Company switching

Switching companies is one of the few flows in the frontend that intentionally discards state rather than trying to reconcile it, because per-company data must never bleed across the switch even transiently:

```ts
// app/api/auth/switch-company/route.ts
export async function POST(req: Request) {
  const { company_id } = await req.json();
  const res = await callLaravel("/auth/switch-company", { method: "POST", body: { company_id } });
  const response = Response.json(sanitize(res));
  setSessionCookies(response, res.data); // new access_token scoped to company_id, per AUTHORIZATION_API.md
  return response;
}
```

```tsx
// components/layout/company-switcher.tsx ("use client")
export function CompanySwitcher() {
  const queryClient = useQueryClient();
  const router = useRouter();

  const { mutate: switchCompany, isPending } = useMutation({
    mutationFn: (companyId: number) =>
      fetch("/api/auth/switch-company", { method: "POST", body: JSON.stringify({ company_id: companyId }) }),
    onSuccess: () => {
      queryClient.clear();       // Layer 1 of defense-in-depth: drop every cached query outright
      router.refresh();         // Layer 2: force every Server Component on screen to re-fetch under the new company
    },
  });
  // ...render <DropdownMenu> of session.companies, disabled while isPending
}
```

`queryClient.clear()` and `router.refresh()` are deliberately both present rather than relying on either alone: `clear()` guarantees no stale-company entry can be read even for a single render, and `refresh()` guarantees every RSC-fetched value on the current screen (which `clear()` cannot touch, since RSC output isn't a TanStack Query cache entry) is re-derived under the new `X-Company-Id`. If the user has an unsaved draft (a partially-built journal entry, an in-progress payroll run form) open in a Zustand-backed builder store at the moment they switch, the switcher shows a confirmation dialog naming exactly what will be discarded before proceeding — company switching is common enough (a bookkeeper serving several client companies) that silently losing a half-typed entry is not acceptable.

## Impersonation banner

Per `AUTHORIZATION_API.md`'s support-impersonation model, a session where `auth_amr`/session claims indicate an active impersonation renders a persistent, undismissable banner (`<ImpersonationBanner>`, fixed to the top of the `(app)` shell, above the topbar, in the Warning-Orange treatment) for the entire duration, naming the support engineer and the time-boxed expiry — this is rendered from the session claims directly and cannot be hidden by any client-side state, matching the backend's requirement that every impersonated action remain visibly, unmissably flagged in every client surface.

# State Management

QAYD draws a hard line between **server state** (anything that originated from `/api/v1/`) and **client state** (anything that exists only in the browser to support the UI), and the two are never allowed to blur:

| State type | Tool | Lives for | Examples |
|---|---|---|---|
| Server state | TanStack Query | Duration of the query's cache lifetime | Journal entries, invoices, approval queue, AI insights, `/me` |
| Cross-cutting session/app state | React Context (`SessionProvider`) | Life of the authenticated session / until company switch | Current user, active company, permissions array, locale/direction |
| Ephemeral client-only UI state | Zustand | Life of the browser tab, some persisted | Sidebar collapsed, active dashboard layout order, table column visibility, theme override, in-progress multi-step form builders |
| Transient local UI state | `useState`/`useReducer` | Life of the component | Dropdown open/closed, hover, local input echo before debounce |

React Context is used specifically for state that changes rarely (once per login, once per company switch, once per locale change) and is read widely — a `Context` that changes on every keystroke would re-render the entire subtree beneath it, which is exactly the failure mode Zustand's selector-based subscriptions exist to avoid for anything higher-frequency.

```ts
// lib/stores/ui-store.ts
interface UiState {
  sidebarCollapsed: boolean;
  toggleSidebar: () => void;
  dashboardLayout: string[]; // widget_id order, user-customized
  setDashboardLayout: (order: string[]) => void;
}

export const useUiStore = create<UiState>()(
  persist(
    (set, get) => ({
      sidebarCollapsed: false,
      toggleSidebar: () => set({ sidebarCollapsed: !get().sidebarCollapsed }),
      dashboardLayout: DEFAULT_WIDGET_ORDER,
      setDashboardLayout: (order) => set({ dashboardLayout: order }),
    }),
    { name: "qayd-ui-prefs", partialize: (s) => ({ sidebarCollapsed: s.sidebarCollapsed, dashboardLayout: s.dashboardLayout }) },
  ),
);
```

Only inert UI preferences are ever passed to `persist`'s `partialize` — never a token, a permission snapshot, a financial figure, or anything company-specific that could survive a company switch or `logout` and leak into the next session on a shared machine. A multi-step builder store (the journal-entry line-item draft, a payroll-run wizard's per-step form state) is Zustand-backed but explicitly *not* persisted to `localStorage`, and is reset on route leave via a `useEffect` cleanup — a half-entered, unbalanced journal entry has no business surviving a tab close.

The one rule that is never violated in either direction: a component never copies a `useQuery` result into a Zustand store or local `useState` "to make it easier to edit," and a Zustand store never independently issues a fetch to `/api/v1/`. If a screen needs to edit server data, it holds the *pending edit* in Zustand or React Hook Form state and reconciles by calling a TanStack Query mutation, not by mutating a private copy of the server response.

# Realtime

## Transport and channel convention

QAYD's realtime layer is Laravel Reverb over WebSockets, consumed via Laravel Echo (using its Pusher-protocol-compatible client) rather than polling, for exactly the surfaces where staleness is either visible or costly: live dashboard figures, AI job status, report-generation progress, and notifications. Every private channel follows the platform-wide naming convention already established across the API and AI documentation — `private-company.{company_id}.<feature>[.{sub_id}]` — which the frontend treats as load-bearing: a channel name the frontend subscribes to is always predictable from the feature and the active company, never a value the frontend has to be told out-of-band.

| Channel | Purpose | Subscribed by |
|---|---|---|
| `private-company.{id}.dashboard.{dashboard_id}` | Live-refresh dashboard tiles flagged `realtime: true` | `app/(app)/dashboard` |
| `private-company.{id}.ai-jobs` | AI job lifecycle (`queued`, `running`, `finished`, `failed`) for the topbar's AI status pulse | `RealtimeProvider` (global, mounted once) |
| `private-company.{id}.approvals` | New/updated `ai_approval_requests` rows | `app/(app)/approvals`, topbar badge count |
| `private-company.{id}.report-runs.{run_id}` | Progress/completion of a single async report generation | `app/(app)/reports/runs/[runId]` |
| `private-company.{id}.revrec` | Revenue-recognition schedule commits | `app/(app)/financial-statements` (P&L accuracy banner) |
| `private-company.{id}.notifications.{user_id}` | Per-user notification stream (Laravel's standard database+broadcast notification channel) | `Topbar` notification bell (global) |

## Authenticating the channel handshake without exposing the bearer

Echo's private-channel subscription requires a signed authorization response from `POST /api/v1/broadcasting/auth`, which itself requires a bearer token — but per Authentication & Session, client JavaScript never holds one. QAYD resolves this the same way it resolves every other client-to-Laravel call: a Next.js Route Handler proxy reads the httpOnly cookie server-side and performs the actual authenticated call, so Echo's client-side configuration points at the *local* proxy, never at Laravel directly:

```ts
// app/api/broadcasting/auth/route.ts
export async function POST(req: Request) {
  const accessToken = req.cookies.get("qayd_at")?.value;
  const body = await req.text(); // socket_id + channel_name, exactly as Echo sent them
  const res = await fetch(`${process.env.QAYD_API_URL}/api/v1/broadcasting/auth`, {
    method: "POST",
    headers: { Authorization: `Bearer ${accessToken}`, "Content-Type": "application/x-www-form-urlencoded" },
    body,
  });
  return new Response(await res.text(), { status: res.status });
}
```

```tsx
// components/providers/realtime-provider.tsx ("use client")
import Echo from "laravel-echo";
import Pusher from "pusher-js";

export function RealtimeProvider({ companyId, userId, children }: RealtimeProviderProps) {
  const queryClient = useQueryClient();
  const echoRef = useRef<Echo<"reverb"> | null>(null);

  useEffect(() => {
    const echo = new Echo({
      broadcaster: "reverb",
      key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
      wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
      wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT),
      forceTLS: true,
      authEndpoint: "/api/broadcasting/auth", // the local proxy above, never the Laravel origin directly
    });
    echoRef.current = echo;

    echo.private(`company.${companyId}.ai-jobs`)
      .listen(".ai.job.finished", (e: AiJobFinishedEvent) => {
        queryClient.invalidateQueries({ queryKey: ["ai", "insights"] });
        queryClient.invalidateQueries({ queryKey: ["ai", "recommendations"] });
        toast(t("ai.jobFinished", { agent: e.agent_code }));
      });

    echo.private(`company.${companyId}.approvals`)
      .listen(".approval.status_changed", () => {
        queryClient.invalidateQueries({ queryKey: approvalKeys.all });
      });

    echo.private(`company.${companyId}.notifications.${userId}`)
      .notification((n: NotificationPayload) => {
        queryClient.setQueryData(["notifications", "unread-count"], (c: number) => c + 1);
        queryClient.invalidateQueries({ queryKey: ["notifications", "list"] });
      });

    return () => { echo.disconnect(); };
  }, [companyId, userId, queryClient]);

  return children;
}
```

## Invalidate, or patch — chosen per event, not by default

Two strategies coexist deliberately. Most domain events (`approval.status_changed`, `journal_entry.posted`, `invoice.paid`) simply call `invalidateQueries` against the affected key and let TanStack Query's normal refetch bring the cache back to truth — this is the default and the safe choice, because it can never drift from what the server actually holds. A small number of high-frequency, low-risk figures (a dashboard tile's live count, an AI job's progress percentage while `running`) instead call `setQueryData` to patch the cache directly with the event's payload, purely to avoid a refetch round-trip on every tick; even these are still corrected by an ordinary invalidation once the job reaches a terminal state (`finished`/`failed`), so a missed or out-of-order WebSocket frame can never leave the UI permanently wrong.

## Connection state is shown, never alarming

A small, static-position indicator in the topbar (a dot, per `DESIGN_SYSTEM.md`'s restrained motion principle — never a banner, never a modal) reflects Echo's `pusher:connection_state_change` events: connected, connecting, or disconnected-and-retrying. Reverb/Pusher-js's built-in exponential backoff handles reconnection automatically; the frontend's only responsibility on reconnect is to invalidate every realtime-fed query key once, since any events broadcast during the disconnected window are permanently missed by a WebSocket (there is no replay), and a stale cache silently trusted after a reconnect would be a correctness bug, not merely a UX rough edge. See Edge Cases for how this interacts with a user who has been on a flaky connection for an extended period.

# Forms & Validation

## React Hook Form + Zod, mirroring the server's own rules

Every form in QAYD is built on React Hook Form with a Zod schema resolver. The schema's job is to give the user instant, client-side feedback for the subset of validation rules that are safe to check without a round trip — required fields, formats, cross-field arithmetic like debit/credit balance — while the server's `422` response remains the actual authority, exactly as Principle 1 states. A schema is written to *mirror* a known server rule, never to invent a stricter or looser one that could drift from it.

```ts
// lib/validators/journal-entry.ts
import { z } from "zod";

const moneyString = z.string().regex(/^\d+(\.\d{1,4})?$/, "invalid_amount_format");

export const journalLineSchema = z.object({
  account_id: z.number({ required_error: "account_required" }),
  debit: moneyString.default("0.0000"),
  credit: moneyString.default("0.0000"),
  cost_center_id: z.number().nullable().optional(),
  project_id: z.number().nullable().optional(),
});

export const journalEntrySchema = z
  .object({
    fiscal_period_id: z.number({ required_error: "period_required" }),
    description: z.string().min(3, "description_too_short").max(500),
    lines: z.array(journalLineSchema).min(2, "minimum_two_lines"),
  })
  .refine(
    (entry) => {
      const totalDebit = entry.lines.reduce((sum, l) => sum.plus(l.debit), Decimal(0));
      const totalCredit = entry.lines.reduce((sum, l) => sum.plus(l.credit), Decimal(0));
      return totalDebit.equals(totalCredit);
    },
    { message: "unbalanced_entry", path: ["lines"] }, // mirrors the server's UNBALANCED_ENTRY code
  );

export type JournalEntryFormValues = z.infer<typeof journalEntrySchema>;
```

Money fields are `z.string()`, never `z.number()` — a direct consequence of `API_ARCHITECTURE.md`'s rule that money is serialized as a decimal string over the wire specifically to avoid IEEE-754 floating-point corruption; the frontend uses `decimal.js` (aliased `Decimal` above) for any client-side arithmetic on these strings rather than coercing to a native `number` at any point in the form pipeline.

```tsx
// components/accounting/journal-entry-form.tsx ("use client")
export function JournalEntryForm({ onSubmit }: { onSubmit: (v: JournalEntryFormValues) => void }) {
  const form = useForm<JournalEntryFormValues>({
    resolver: zodResolver(journalEntrySchema),
    defaultValues: { lines: [emptyLine(), emptyLine()] },
  });
  const { fields, append, remove } = useFieldArray({ control: form.control, name: "lines" });
  const { t } = useTranslation();

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
        {fields.map((field, i) => (
          <JournalLineRow key={field.id} index={i} control={form.control} onRemove={() => remove(i)} />
        ))}
        <Button type="button" variant="ghost" onClick={() => append(emptyLine())}>
          {t("journalEntry.addLine")}
        </Button>
        {form.formState.errors.lines?.root?.message === "unbalanced_entry" && (
          <Alert variant="warning">{t("journalEntry.unbalanced", { diff: computeDiff(form.watch("lines")) })}</Alert>
        )}
        <Button type="submit" disabled={form.formState.isSubmitting}>{t("common.save")}</Button>
      </form>
    </Form>
  );
}
```

## Mapping server-side `422` back onto the form

When the optimistic client-side schema passes but the server still returns `422` (a `PERIOD_LOCKED` that only became true after the form was loaded, for instance), every entry in `errors[]` is mapped back onto the exact RHF field it names, including array-indexed paths for line items, so the message appears next to the specific input rather than as a disconnected global toast:

```ts
// lib/api/apply-server-errors.ts
export function applyServerErrors<T extends FieldValues>(error: ApiError, form: UseFormReturn<T>) {
  for (const e of error.fieldErrors) {
    if (e.field) {
      form.setError(e.field.replace(/\[(\d+)\]/g, ".$1") as Path<T>, { type: "server", message: e.message });
    }
  }
  if (!error.fieldErrors.some((e) => e.field)) {
    toast.error(error.message); // a non-field-scoped business rule (e.g. PERIOD_LOCKED at the entry level)
  }
}
```

`field: "lines.1.account_id"` from the API becomes `form.setError("lines.1.account_id", ...)` directly — RHF's dot/bracket path syntax for field arrays is deliberately kept in sync with the API's own `field` naming so this mapping never needs a bespoke translation table per form.

## Currency-aware money inputs

A dedicated `<CurrencyInput>` (never a native `<input type="number">` for anything financial, consistent with Principle 1 and the money-as-string rule) formats for display using `Intl.NumberFormat` with the *currency's own minor-unit precision* — KWD is 3 decimal places (1 dinar = 1,000 fils), while USD, SAR, and AED are 2 — while keeping the underlying RHF value a plain decimal string with no locale grouping characters, so what round-trips to the API is always `"1250.5000"`, never `"1,250.500 KWD"`:

```tsx
export function CurrencyInput({ value, onChange, currencyCode }: CurrencyInputProps) {
  const minorUnitDigits = CURRENCY_MINOR_UNITS[currencyCode] ?? 2; // KWD: 3, BHD: 3, USD/SAR/AED: 2
  const formatter = useMemo(
    () => new Intl.NumberFormat(locale, { minimumFractionDigits: minorUnitDigits, maximumFractionDigits: minorUnitDigits }),
    [locale, minorUnitDigits],
  );
  // renders a display-formatted, locale-aware string; commits the raw decimal string to RHF on blur/change
}
```

## Multi-step wizards

Payroll-run creation, bank reconciliation, and onboarding are modeled as a Zustand-backed wizard store holding per-step form values plus the current step index, with each step's own Zod schema validated independently via a discriminated union keyed on step name — this lets the wizard validate "is step 2 complete" without requiring step 4's fields to exist yet, and lets the whole wizard be resumed mid-flow (the store, unlike the journal-entry draft mentioned under State Management, *is* persisted for these specific long-running flows, since losing 20 minutes of payroll-run configuration to an accidental tab close is a worse outcome than the minor risk of a stale draft).

## RTL forms and bidirectional input

Every form respects `dir="rtl"` at the container level when the active locale is Arabic — labels, field order, and validation icon placement all mirror correctly via logical CSS properties, per Principle 6. Numeric and currency inputs are the one deliberate exception to blanket mirroring: they are pinned `dir="ltr"` even inside an RTL form, because a right-to-left rendering of a decimal amount or an account code (`"1250.5000"` or `"IBAN KW81 CBKU..."`) is not a stylistic preference but a correctness issue — digits and structured codes are read left-to-right by every user regardless of interface language, and QAYD's forms apply an explicit Unicode bidi-isolation wrapper (`<span dir="ltr" style={{ unicodeBidi: "isolate" }}>`) around any such value embedded in otherwise-Arabic surrounding text (an audit-log line, an AI reasoning sentence citing an amount) so the digits never visually reorder.

# AI Integration Layer

## The contract the frontend renders, never re-derives

Every AI-authored payload the frontend receives — from `ai_decisions` (insights, recommendations, forecasts) or `ai_approval_requests` (anything gated) — carries the same shape: `confidence_score`, `reasoning`, `sources`, and, for actionable items, `recommended_action` and `alternatives`. The frontend's entire job for this layer is to render that shape faithfully and to route the user's response (approve, reject, dismiss, delegate, "do it") back through the exact endpoint the AI Command Center specification names, never to compute its own summary of what the AI "probably means" or to soften/embellish a confidence score cosmetically.

```ts
// types/ai.ts (subset generated from the OpenAPI contract, shown for reference)
export interface AiDecision {
  id: number;
  agent_code: AgentCode; // "GENERAL_ACCOUNTANT" | "CFO_AGENT" | "FRAUD_DETECTION" | ...
  decision_type: string;
  confidence_score: number; // 0–100
  reasoning: string;
  sources: Array<{ type: string; id: number; label: string }>;
  recommended_action?: { action: string; payload: Record<string, unknown> };
  alternatives?: Array<{ action: string; tradeoff: string }>;
  generated_at: string;
}
```

## Shared components

```tsx
// components/ai/ai-card-shell.tsx — the mandatory visual envelope for any AI-authored card
export function AiCardShell({ decision, children }: { decision: AiDecision; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border-l-4 border-l-[--color-ai-accent] bg-card p-4">
      <div className="mb-2 flex items-center gap-2">
        <Badge variant="ai">{t("ai.badge")}</Badge>
        <ConfidenceBadge score={decision.confidence_score} />
        <span className="text-xs text-muted-foreground">{decision.agent_code}</span>
      </div>
      {children}
      <ReasoningDisclosure reasoning={decision.reasoning} sources={decision.sources} />
    </div>
  );
}
```

`AiCardShell` is not optional dressing — it is the one component every AI-authored surface in the codebase is required to render through, so the "colored border plus AI badge" rule from `DESIGN_SYSTEM.md`/`FINANCIAL_STATEMENTS.md` cannot silently drift screen to screen. `ConfidenceBadge` renders the numeric score plus a qualitative band (below 70 renders amber with a "low confidence" label even though the number is also shown — the badge never hides the number behind a purely qualitative label). `ReasoningDisclosure` is a collapsed-by-default popover, never inline paragraph text competing with the card's primary content, so a dense feed of twenty insights stays scannable.

## The three-button pattern

Every actionable recommendation renders at most three controls, matching `AI_COMMAND_CENTER.md`'s Interaction spec exactly, and which of the three appear is computed server-side (via the `autonomy`/threshold evaluation `APPROVAL_ASSISTANT` already performs), not guessed at by the client:

```tsx
// components/ai/recommendation-card.tsx ("use client")
export function RecommendationCard({ decision }: { decision: AiDecisionWithAction }) {
  const { mutate: execute, isPending: executing } = useExecuteRecommendation(decision.id);
  const { mutate: sendForApproval } = useSendForApproval(decision.id);
  const [dismissOpen, setDismissOpen] = useState(false);

  return (
    <AiCardShell decision={decision}>
      <p>{decision.recommended_action.action}</p>
      <AlternativesList alternatives={decision.alternatives} />
      <div className="flex gap-2">
        {decision.can_execute_directly && ( // server-computed: autonomy === "auto" AND confidence >= threshold
          <Button onClick={() => execute()} loading={executing}>{t("ai.doIt")}</Button>
        )}
        <Button variant="outline" onClick={() => sendForApproval()}>{t("ai.sendForApproval")}</Button>
        <Button variant="ghost" onClick={() => setDismissOpen(true)}>{t("ai.dismiss")}</Button>
      </div>
      <DismissDialog open={dismissOpen} onOpenChange={setDismissOpen} decisionId={decision.id} requireReason />
    </AiCardShell>
  );
}
```

Crucially, `can_execute_directly` is a field the API response computes and sends — the frontend never independently evaluates "is this agent's autonomy `auto` and is the confidence above threshold" from raw `ai_automation_rules` data, because that evaluation is exactly the kind of business logic Principle 1 forbids duplicating client-side. If the field is `false`, the "Do it" button does not render dimmed-and-disabled; it does not render at all, which is what makes the earlier claim in Principles — that a gated action has no client-side path to execute it — structurally true rather than a UI convention a determined user could route around by re-enabling a button in devtools (the underlying mutation endpoint itself would still 403, per the same defense-in-depth reasoning `INTERNAL_API.md` applies at the network and database layers).

## The Approval Center

`app/(app)/approvals/page.tsx` renders the queue from `GET /api/v1/approvals`, grouped by `sla_due_at` urgency, each row exposing Approve/Reject/Request Changes/Delegate against `POST /api/v1/approvals/{id}/approve|reject|delegate`. Bulk-approve is offered as a UI affordance only when every selected row shares a type, is below its company-configured value threshold, and is not `held`; the checkbox that would select a row carrying `required_permission IN ('bank.transfer','payroll.approve','tax.submit')` is rendered disabled with an inline explanation rather than simply omitted, so the restriction is legible rather than mysterious:

```tsx
<Checkbox
  disabled={SENSITIVE_PERMISSIONS.includes(row.required_permission)}
  title={SENSITIVE_PERMISSIONS.includes(row.required_permission) ? t("approvals.bulkNotAllowed") : undefined}
/>
```

A multi-step chain renders as a `<Stepper>` naming every step's `approver_role`, its `status`, and — once decided — the `comment`; the current user's own action is only enabled on the step that is actually `pending` and assigned to their role, never on a future step, so a CFO cannot accidentally approve step 1 out of order before a Senior Accountant has.

## Streaming AI chat

"Ask AI" (`app/(app)/ai/chat/page.tsx`) streams tokens rather than waiting for a complete response, via a Next.js Route Handler that proxies to the backend chat endpoint using Server-Sent Events and the Vercel AI SDK's `useChat` hook on the client:

```ts
// app/api/ai/chat/route.ts
export async function POST(req: Request) {
  const accessToken = req.cookies.get("qayd_at")?.value;
  const upstream = await fetch(`${process.env.QAYD_API_URL}/api/v1/ai/chat`, {
    method: "POST",
    headers: { Authorization: `Bearer ${accessToken}`, "Content-Type": "application/json" },
    body: await req.text(),
  });
  return new Response(upstream.body, { headers: { "Content-Type": "text/event-stream" } });
}
```

```tsx
// app/(app)/ai/chat/page.tsx ("use client" subtree)
const { messages, input, handleInputChange, handleSubmit, isLoading } = useChat({ api: "/api/ai/chat" });
```

Streamed prose from the chat endpoint arrives already localized to the caller's `Accept-Language` — the frontend never machine-translates AI-authored text itself, matching `API_ARCHITECTURE.md`'s content-negotiation contract; the client's own localization responsibility is limited to its chrome (button labels, the composer placeholder, error toasts), never the model's words. Every chat response that references a specific financial figure renders that figure through the same bidi-isolated, currency-aware formatting components the rest of the application uses, so an amount cited by the assistant in an Arabic conversation is exactly as unambiguous as one shown in a table.

# Error Handling & Boundaries

## Boundaries at three granularities

QAYD nests error handling at three levels so a failure's blast radius matches its actual severity, never wider:

1. **Route-level `error.tsx`** catches anything an entire route segment's Server or Client Components throw, rendering a full-page recoverable state (retry button, link back to the dashboard) — reserved for genuine, unexpected failures, not for ordinary `4xx` responses a component should handle locally.
2. **Widget-level `<ErrorBoundary>`** (from `react-error-boundary`, since streamed dashboard panels need boundaries finer than the route) isolates one AI Command Center panel's failure from the eleven others on the same page — a Forecast Agent timeout renders a small inline "couldn't load forecast, retry" card without disturbing Morning Briefing or Cash Flow Status next to it.
3. **Query/mutation-level handling** inside TanStack Query's own `error` state, rendered inline by the component that issued the query (an empty-with-message table row, a form-level alert), for the ordinary, expected `4xx` cases that are not really "errors" so much as answers the UI needs to react to.

## Global classification, not per-screen special-casing

A single `QueryCache`/`MutationCache` `onError` hook centralizes the handful of response classes that always mean the same thing everywhere in the application, so no individual screen re-implements this branching:

```ts
// lib/api/query-client.ts
const queryCache = new QueryCache({
  onError: (error) => {
    if (!(error instanceof ApiError)) return;
    switch (error.status) {
      case 401:
        window.location.assign("/login"); // session is unrecoverable client-side; full reload re-runs middleware
        break;
      case 429: {
        const retryAfter = error.envelope.errors[0]?.meta?.retry_after as number | undefined;
        toast.warning(t("errors.rateLimited", { seconds: retryAfter ?? 60 }));
        break;
      }
      case 500:
        toast.error(t("errors.serverError", { requestId: error.envelope.request_id }));
        Sentry.captureException(error, { tags: { request_id: error.envelope.request_id } });
        break;
      // 403, 404, 409, 422 are NOT handled here — they are meaningful, expected outcomes
      // the calling component/form must react to specifically, never a generic toast.
    }
  },
});
```

`422` in particular is explicitly excluded from any global toast: it always carries field-level detail that belongs next to the input that caused it (via `applyServerErrors`, above), and a generic "something went wrong" toast on top of that would be redundant at best and misleading at worst. `403` and `404` are likewise left to the calling context because their correct UI response differs by screen — a `403` on a bulk-approve action means "re-render without this row's checkbox enabled," while a `403` on an entire page navigation means "render the page-level forbidden state" (see below).

## 403 vs. 404: the frontend keeps the same enumeration discipline the API does

`API_ERROR_HANDLING.md` and `AUTHORIZATION_API.md` are explicit that a cross-tenant resource returns `404`, never `403`, specifically so a caller can never learn that a resource exists in a company they don't belong to. The frontend's `not-found.tsx` therefore renders identically whether the underlying cause was "this journal entry ID has never existed" or "this journal entry belongs to a different company" — no error copy anywhere in the application says or implies "this exists but you can't see it" for a `404`, because saying so would leak exactly the information the server's status-code choice was designed to withhold. A genuine `403` (the resource is confirmed to exist in the caller's own active company, but the caller's role lacks the permission) does get a more specific, permission-named message, because at that point existence is already established and naming the missing permission (`"You need accounting.journal.post to complete this."`) is helpful rather than a leak.

## Retry semantics mirror the API's own table

```ts
function isRetryable(error: unknown): boolean {
  if (!(error instanceof ApiError)) return true; // network-level failure, no response at all — retry
  return [429, 500].includes(error.status); // everything else in API_ERROR_HANDLING's table is non-retryable
}
```

`429` retries respect the server's `Retry-After` via a custom `retryDelay` rather than TanStack Query's default exponential backoff, so the client never hammers a rate limiter it has already been told the exact wait for. Mutations are never auto-retried at all (`mutations: { retry: false }` from Data Layer) regardless of status, because retrying a `POST` automatically is precisely the failure mode the client-generated `Idempotency-Key` exists to make safe *if* the user explicitly resubmits — but an automatic, silent retry of a financial mutation is a decision QAYD reserves for the human, not the query client.

## Idempotency keys, generated and scoped correctly

```ts
// lib/api/use-idempotency-key.ts
export function useIdempotencyKey(formInstanceId: string) {
  return useMemo(() => {
    const stored = sessionStorage.getItem(`idem:${formInstanceId}`);
    if (stored) return stored;
    const fresh = crypto.randomUUID();
    sessionStorage.setItem(`idem:${formInstanceId}`, fresh);
    return fresh;
  }, [formInstanceId]);
}
```

The key is scoped to one logical submission attempt (keyed by a stable `formInstanceId` minted when the form mounts) and is deliberately *not* regenerated on a retry of that same attempt — a user who taps "Post" twice because the first tap's spinner was slow must produce exactly one journal entry, which is the entire point of the header. A genuinely new attempt (the user closes the form and reopens it to create a different entry) mints a new `formInstanceId` and therefore a new key; `sessionStorage`, not `localStorage`, is used so a stale key never survives past the tab's life into an unrelated future session.

## What the frontend never shows

Consistent with `API_ERROR_HANDLING.md`'s own non-leakage stance, the frontend never renders a raw stack trace, a raw SQL fragment, an internal file path, or another tenant's identifiers in any error surface a user can see — Sentry receives the full detail (tagged with `request_id` for support correlation), while the on-screen message is always one of the localized, catalog-backed strings the `code` maps to. An unrecognized `code` the frontend's local catalog doesn't yet have a translation for falls back to the server-supplied `message` (already localized server-side) rather than a hardcoded "Unknown error," so a newly added backend error code degrades gracefully on an un-updated client instead of showing nothing.

# Performance

## Code splitting

Route-level splitting is automatic under the App Router — each `page.tsx` and its unique dependencies ship as their own chunk, so a Payroll Officer who never opens the AI Command Center never downloads its chart library. Beyond that automatic boundary, QAYD explicitly `next/dynamic`-splits anything heavy and screen-specific: the financial-statement PDF viewer, the dose of Recharts used only by trend widgets, the signature-capture component used only in one onboarding step, and the barcode scanner used only in Inventory's mobile-web counting flow:

```tsx
const InvoicePdfViewer = dynamic(() => import("@/components/sales/invoice-pdf-viewer"), {
  ssr: false, // relies on browser Canvas/PDF.js — never useful to render on the server
  loading: () => <PdfViewerSkeleton />,
});
```

## RSC payload discipline

A Server Component's job is to describe the first paint, not to smuggle an entire nested API response graph across the RSC wire for a client subtree to pick through later. Detail pages fetch the fields that page actually renders and let deeper, optional relations (an invoice's full payment-allocation history, a vendor's full contract archive) load as separate, lazily-triggered TanStack queries once the user expands that section — mirroring the same "don't over-fetch" discipline `API_FILTERING_SORTING.md` applies server-side, just enforced one layer up. Where the API supports sparse fieldsets, list views request only the columns the current table configuration actually renders, not the full resource representation, shrinking both the RSC payload and the client cache footprint for wide tables like `journal-entries` or `invoices`.

## Caching layers, stacked deliberately

Four caching layers exist in the stack, each with a distinct, non-overlapping job: the browser's HTTP cache honors `ETag`/conditional `GET` support the API exposes (per `API_ARCHITECTURE.md`'s headers table) for genuinely cacheable reference `GET`s via `If-None-Match`, letting a `304` skip re-serialization entirely; the Next.js Data Cache and static generation apply only to the non-tenant `(marketing)` group and genuinely global reference data, never to anything under `X-Company-Id` (per Rendering Strategy); TanStack Query's in-memory cache is the workhorse for everything else, tuned per data class (Data Layer); and a CDN edge cache in front of Cloudflare serves only static assets and the marketing group, never an authenticated API response. No layer is allowed to silently substitute for another — in particular, nothing tenant-scoped is ever pushed up to the CDN layer, which is the layer with the least visibility into per-request authorization.

## Virtualization for large tables

`ledger-entries`, `audit-logs`, and `stock-movements` — the same unbounded tables that use cursor pagination server-side — render through `@tanstack/react-virtual` on the client, so a table showing "the next 50 rows" from `useInfiniteQuery` never mounts more DOM nodes than are actually visible in the viewport, regardless of how many pages have been fetched into the query cache over a long scroll session:

```tsx
const virtualizer = useVirtualizer({
  count: flatRows.length,
  getScrollElement: () => scrollRef.current,
  estimateSize: () => 44, // row height, px
  overscan: 12,
});
```

## Debounced input, stable placeholders

Search and filter inputs debounce at 300ms before triggering a new query key, and every filtered/paginated view uses `placeholderData: keepPreviousData` (Data Layer) so the table shows the previous page's rows, dimmed, rather than an empty state, while the next page or filter result resolves — a filtered `journal-entries` list never flashes to a blank table on every keystroke.

## Bundle budgets

Each top-level route carries an enforced first-load JS budget, checked in CI via `next build`'s bundle analyzer output against a manifest (`performance-budgets.json`) rather than left to reviewer eyeballing: the `(auth)` login route is budgeted under 90KB gzipped first-load JS; the dashboard shell (excluding individually-split widget chunks) under 180KB; any individual route exceeding its budget by more than 10% fails the build. Lucide icons are imported by name (`import { ArrowUpRight } from "lucide-react"`) exclusively, never as a namespace import, so tree-shaking removes every icon the bundle doesn't reference. Framer Motion is imported through its `LazyMotion`/`domAnimation` reduced feature set for the common fade/slide/scale animations used throughout the design system, reserving the full `motion` bundle only for the handful of screens with more elaborate gesture-driven interaction.

## Web Vitals

A `useReportWebVitals` hook forwards Core Web Vitals (LCP, CLS, INP) to the same observability pipeline Sentry Performance uses, tagged with route and company-size band (companies with a materially larger chart of accounts or transaction volume are expected, and monitored, to have different tail latencies than a new company with an empty ledger), so a regression is caught against a realistic baseline rather than a single global target that masks scale-dependent slowdowns.

# Folder Structure

```
apps/web/
├── app/                             # App Router — see App Router Structure for the full route tree
├── components/
│   ├── ui/                         # shadcn/ui primitives, restyled to QAYD design tokens (Button, Input, Dialog...)
│   ├── layout/                     # Sidebar, Topbar, CompanySwitcher, CommandPalette, ImpersonationBanner
│   ├── ai/                         # AiCardShell, ConfidenceBadge, ReasoningDisclosure, RecommendationCard, ApprovalStepper
│   ├── forms/                      # CurrencyInput, DatePicker (locale-aware), FieldArrayTable, FormErrorSummary
│   ├── charts/                     # Recharts wrappers pre-themed to the design system's chart palette
│   ├── accounting/                 # Module-specific composed components (JournalEntryForm, LedgerTable, ...)
│   ├── banking/
│   ├── sales/
│   ├── purchasing/
│   ├── inventory/
│   ├── payroll/
│   ├── tax/
│   ├── reports/
│   ├── dashboard/                  # Widget components + WidgetSkeleton
│   ├── approvals/
│   └── providers/                  # SessionProvider, RealtimeProvider, ThemeProvider, I18nProvider
│
├── lib/
│   ├── api/
│   │   ├── http.ts                 # ApiEnvelope, ApiError, unwrap()
│   │   ├── client.ts                # Browser-side request client
│   │   ├── server-client.ts         # RSC/Server Action request client (cookie-forwarded, no-store)
│   │   ├── query-client.ts          # makeQueryClient(), QueryCache/MutationCache onError
│   │   ├── query-keys.ts            # Per-resource key factories
│   │   └── hooks/                   # useJournalEntries, useApproveRequest, useLedgerEntries, ...
│   ├── auth/
│   │   ├── session.ts                # getSession(), setSessionCookies()
│   │   ├── jwt.ts                    # decodeJwtExpiry() (client-safe, no verification — display/refresh-timing only)
│   │   └── use-permission.ts
│   ├── stores/                       # Zustand: ui-store, journal-entry-draft-store, wizard-store
│   ├── validators/                   # Zod schemas, one file per resource, mirroring server rules
│   ├── realtime/                     # Echo instance factory, event-name constants
│   ├── i18n/                         # useTranslation, locale/direction resolution
│   └── utils/                        # decimal formatting, cursor helpers, date/timezone helpers
│
├── types/
│   ├── generated/                    # openapi-typescript output — never hand-edited
│   └── ai.ts                         # Hand-authored narrow types layered on top of generated ones (AiDecision, etc.)
│
├── messages/
│   ├── en.json
│   └── ar.json
│
├── middleware.ts
├── next.config.ts
├── tailwind.config.ts                # Design tokens as CSS variables, RTL-aware logical utilities enabled
└── package.json
```

Module folders under `components/` mirror the module boundary the backend itself enforces (`MODULE_ARCHITECTURE.md`'s "modules are independent, modules communicate through defined interfaces") — a component inside `components/payroll/` may import from `components/ui/`, `components/ai/`, or `lib/`, but never reaches directly into `components/accounting/`'s internals; if two modules' screens need to share a composed pattern, that pattern is promoted to `components/ui/` or a new cross-cutting folder, not imported sideways.

# Testing

## Vitest — unit and component

Vitest covers four categories, each with a distinct purpose: pure utility functions (decimal formatting, cursor decoding, currency minor-unit lookups) tested as plain input/output; Zod schemas tested against both valid payloads and the exact invalid shapes the server is known to reject, asserting the schema's error path/message matches what `applyServerErrors` expects to map; query-key factories tested for stability (the same filters object must always produce a referentially-comparable key, since TanStack Query's cache matching depends on it); and components tested with React Testing Library plus MSW (Mock Service Worker) intercepting `/api/v1/*` and responding with the real envelope shape, never a hand-shortened mock object, so a test can never pass against a response shape the real API would never send:

```ts
// components/accounting/journal-entry-form.test.tsx
const server = setupServer(
  http.post("/api/v1/accounting/journal-entries", () =>
    HttpResponse.json(errorEnvelope(422, [{ code: "UNBALANCED_ENTRY", field: "lines", message: "Debits and credits do not match." }])),
  ),
);

it("surfaces UNBALANCED_ENTRY as a form-level alert, not a toast", async () => {
  render(<JournalEntryForm onSubmit={submitViaMutation} />);
  await userEvent.click(screen.getByRole("button", { name: /save/i }));
  expect(await screen.findByRole("alert")).toHaveTextContent(/debits and credits/i);
  expect(mockToastError).not.toHaveBeenCalled(); // 422 must never also fire the global toast
});
```

## Contract fidelity against the OpenAPI spec

MSW's handlers are generated, not hand-written, from the same `GET /api/v1/openapi.json` contract the TypeScript types come from, via a small internal codegen step that turns each documented endpoint + example into a default handler. This is what keeps frontend tests from quietly drifting away from the real API shape over time — a backend change that removes a field or renames an error code regenerates the handlers and breaks the relevant test immediately, rather than leaving a stale hand-written mock silently green forever.

## Playwright — end to end

Playwright drives the critical, cross-cutting flows that unit tests cannot meaningfully cover because they span multiple pages, real navigation, and session state:

| Flow | What it asserts |
|---|---|
| Login → MFA → dashboard | Cookie is httpOnly (asserted via `context.cookies()` inspection, never readable from `document.cookie` in-page), correct company auto-selected |
| Company switch mid-session | Query cache is fully cleared (a spy on `queryClient.clear`) and no page shows a flash of the previous company's figures |
| Create → balance-check → post a journal entry | Client-side unbalanced-entry warning appears before submit; server `UNBALANCED_ENTRY` still handled correctly if the client check is bypassed via direct API call in the test |
| Full approval chain | Senior Accountant approves step 1, CFO's session (a second browser context) sees the request move to step 2 in real time via the Reverb subscription, without a manual refresh |
| RTL snapshot | Switching locale to Arabic sets `dir="rtl"`, mirrors the sidebar, and pins numeric fields `dir="ltr"` |
| Dark mode toggle | Every design token resolves to its dark-mode CSS variable value; no hardcoded light-mode color remains visible (checked via a computed-style sweep) |
| Offline/reconnect | A simulated WebSocket drop followed by reconnect triggers the expected cache invalidation, not a silently stale dashboard |

Accessibility is checked as part of the same Playwright suite via `@axe-core/playwright` run against every top-level route in both light/dark and LTR/RTL, failing the build on any new critical or serious violation rather than being a separate, occasionally-run audit.

## Visual regression

Chromatic (or an equivalent Storybook-driven visual diff tool) runs against the `components/ui/` design-system layer specifically — not every screen in the application — because that is the layer where an unintended visual drift (a shadow, a radius, a spacing token silently changing) would otherwise propagate invisibly into every module that consumes it.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| Token refresh race across multiple open tabs | Refresh is serialized through a single in-flight promise per browser context (a `BroadcastChannel`-coordinated lock across tabs); only one tab actually calls `/auth/refresh`, others await its result, preventing a refresh-token rotation from invalidating a sibling tab's in-flight refresh. |
| Company switched in one tab while another tab is mid-edit | The idle tab's next API call fails with `COMPANY_CONTEXT_MISMATCH` (403) rather than silently succeeding against the wrong tenant; the frontend catches this specific code globally and forces a full reload of that tab, re-deriving its session fresh rather than trying to reconcile in place. |
| Permission revoked mid-session (role change by an admin) | The next request carrying a stale `permsVer` claim receives `401 PERMS_STALE` (per `AUTHENTICATION_API.md`'s middleware); the global 401 handler's redirect-to-login is intentionally the same path used for outright expiry, since a permission change and a token expiry both require the client's next request to happen only after re-establishing a session with current claims. |
| WebSocket disconnected for an extended period (flaky mobile network) | On reconnect, every realtime-fed query key is invalidated once as a batch — the frontend never assumes the socket's silence meant "nothing changed," since a missed event is unrecoverable by definition over a non-replaying transport. |
| Unsaved journal-entry/payroll-wizard draft during a company switch or tab close | A confirmation dialog names what will be discarded before a company switch proceeds; a wizard-store draft (payroll run, reconciliation) persists across an accidental tab close, while a short-lived draft (a single journal entry) deliberately does not, per the distinction drawn in State Management. |
| Brand-new company with an empty chart of accounts | List and dashboard screens render a specific "not yet configured" empty state with a setup CTA (per `DESIGN_SYSTEM.md`'s Empty States rule: explain why, what to do next, and an action button), never the same generic "no results" state a filtered-to-zero search produces — the two are visually and textually distinct so a new customer is never mistaken into thinking their data is missing rather than simply not yet entered. |
| Bulk export at `per_page=500` (bulk-marked endpoints) | Never awaited synchronously in the UI thread; the export triggers a background job and the frontend polls (or subscribes via the report-runs Reverb channel) for completion, downloading the resulting signed URL only once the job reports done, so a 500-row export can never appear to freeze the screen. |
| Mixed-currency data in one list (a multi-currency invoice list showing KWD and USD side by side) | Each row's amount renders with its own currency's minor-unit precision (3 decimals for KWD, 2 for USD) rather than a single table-wide decimal format, and every amount carries an explicit currency code/symbol rather than relying on column position to convey which currency a given row is in. |
| Clock skew between client and server | Relative timestamps ("2 minutes ago") are computed from the server-supplied `timestamp`/`created_at` fields and the client's `Date.now()` only after reconciling a measured clock-offset (captured once per session from the envelope's `timestamp` versus local time at request completion), so a client with a materially wrong system clock does not display an impossible "in the future" or wildly exaggerated relative time. |
| Screen-reader users on a live AI feed | New cards in `ai_insights_feed`/`ai_recommendations_feed` announce via `aria-live="polite"`, never `"assertive"` — a fast-moving feed of AI observations must not interrupt whatever the user is currently reading, matching the same "auto-detect, never alarming" posture `AI_COMMAND_CENTER.md` applies to payroll anomaly surfacing. |
| Printing a financial statement or payslip | A dedicated print stylesheet (`@media print`) strips the sidebar/topbar/AI chrome entirely and renders only the statement/payslip content at document scale, since a printed Balance Sheet handed to an auditor should never carry the application's navigation furniture or an "AI badge" that has no meaning on paper. |
| Browser back/forward after a company switch | Because every `(app)` route is `force-dynamic` (Rendering Strategy) and the switch calls `router.refresh()`, a back-navigation re-renders the target route fresh under whatever company is currently active rather than resurrecting a bfcache snapshot rendered under a previous company — QAYD explicitly disables the back/forward cache for `(app)/*` via a `Cache-Control` response header for this reason. |
| An AI proposal's target record is edited by a human between proposal and approval | The Approval Center re-fetches the live target record immediately before rendering the approve action and diffs it against the proposal's `proposed_payload`; if they've diverged, the approve button is replaced with a "review changed data" state rather than silently approving a proposal against data that has since moved, mirroring the API's own `409`-on-state-conflict posture at the UI layer. |

# End of Document
