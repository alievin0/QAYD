# Settings Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: SETTINGS_SCREEN
---

# Purpose

This document is the concrete, implementation-ready screen specification for the Settings landing
route, `app/(app)/settings`, written against the platform's `SCREEN DOC STRUCTURE` template so it can be
read, reviewed, and diffed section-by-section alongside every other screen document in this series —
Dashboard, Accounting, General Ledger, Journal Entries, Trial Balance, Balance Sheet, Profit & Loss,
Cash Flow, Bank Reconciliation, the AI Command Center, and Home. It is the same kind of companion to
`docs/frontend/SETTINGS.md` that `ACCOUNTING_SCREEN.md` already is to `docs/frontend/ACCOUNTING.md`:
`SETTINGS.md` argues, at full policy depth, why Settings is fifteen independently-permissioned `Form
Page Template` instances grouped into twelve conceptual sections, resolves three real naming drifts
against `AUTHORIZATION_API.md` and `AUTHENTICATION_API.md`, and specifies every endpoint, mutation
strategy, permission key, and edge case for all twelve sections — **General, Accounting, Tax,
Numbering, Approval Workflows, Users & Roles, Branches & Departments, Security, Integrations,
Notifications, AI Preferences, and Billing**. This document does not re-argue, re-derive, or
re-decide a single one of those facts. What it adds is the layer an engineer opens beside their editor
while wiring the shell every one of those fifteen pages shares: the literal `layout.tsx` and
`SettingsSectionNav` source, the one file `SETTINGS.md`'s own fixed route tree never lists but its own
prose already promises (a bare `app/(app)/settings/page.tsx` redirect resolver — see `# Route &
Access`), the concrete render-tree resolution of "permission gating per section" as working `<Can>`
trees rather than a policy table, two fully worked form-mutation patterns (pessimistic/always-sensitive
and optimistic/ordinary) that the twelve sections are then classified against, and pixel-level
state/responsive/dark-mode/accessibility detail in place of a reference to "the shared token set."
Where this document is silent on a fact, `docs/frontend/SETTINGS.md` governs first, then
`FRONTEND_ARCHITECTURE.md`, `DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`,
`LAYOUT_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and `ACCESSIBILITY.md`, in that order. Where
this document appears to contradict one of them on a fact — a route, a permission key, an endpoint
path, a component name — that is a defect to raise in review, never a decision an engineer resolves
unilaterally in code.

Three constraints inherited unchanged from `FRONTEND_ARCHITECTURE.md` and restated by every sibling
screen document bind this one identically. **The frontend computes nothing new** — every figure, chain,
role grant, and confidence score this screen renders was validated and persisted by Laravel; this
screen's own logic is limited to resolving which tab a bare `/settings` hit should land on, rendering a
permission-filtered navigation rail, and routing a Save click into whichever mutation strategy
`SETTINGS.md` already assigns that tab. **AI is visible, labeled, and never silent** — the four agents
that touch this screen (Compliance Agent, Fraud Detection, Approval Assistant, CFO Agent) render through
the identical `AiCardShell`/`ConfidenceBadge`/`ReasoningDisclosure` contract every other screen uses,
never a Settings-specific shortcut. **RBAC is enforced by the API; the UI only reflects it** — a locked
Section Nav item, a disabled field, and a `403` on a direct URL hit are three courtesy layers over the
same server-side check, never the actual authority.

Concretely, this screen composes four pieces of surface, each owned elsewhere and only rendered, routed,
or (in one case) newly formalized here:

- **The bare-route redirect** — `app/(app)/settings/page.tsx`, this document's own addition to the fixed
  file tree, resolving the tab a visit to the un-suffixed `/settings` segment actually lands on.
- **`SettingsSectionNav`** — the grouped, four-section vertical rail `SETTINGS.md → Layout & Regions`
  specifies in prose and ASCII; this document gives it a literal, permission-filtered implementation.
- **The permission-gating render tree** — a concrete `<Can>`/`DisabledWithTooltip` composition per
  section, resolving one real ambiguity `SETTINGS.md` itself leaves implicit: whether the Security tab's
  Section Nav entry is gated by `settings.security.read` at all, given that document's own statement that
  "every role manages its own MFA factors, sessions... with no permission check at all" (resolved below,
  `# Route & Access`).
- **Two worked form-mutation patterns** — the always-sensitive/pessimistic pattern (General) and the
  ordinary/optimistic pattern (Branches & Departments) — against which all twelve sections are then
  classified in one table, rather than each of fifteen pages re-deriving its own mutation wiring from
  scratch.

# Route & Access

## File tree (adds one file to the fixed fifteen)

```text
app/(app)/settings/
├── page.tsx                  # ★ NEW, this document — bare-segment redirect resolver, see below
├── layout.tsx                 # Section Nav shell — literal source below
├── company/page.tsx           # General            — docs/frontend/SETTINGS.md
├── accounting/page.tsx        # Accounting          — docs/frontend/SETTINGS.md
├── tax/page.tsx                # Tax                 — docs/frontend/SETTINGS.md
├── numbering/page.tsx          # Numbering           — docs/frontend/SETTINGS.md
├── approvals/page.tsx          # Approval Workflows  — docs/frontend/SETTINGS.md
├── users/page.tsx               # Users & Roles (Users)   — docs/frontend/SETTINGS.md
├── roles/page.tsx                # Users & Roles (Roles)   — docs/frontend/SETTINGS.md
├── branches/page.tsx             # Branches & Departments (Branches) — docs/frontend/SETTINGS.md
├── departments/page.tsx          # Branches & Departments (Departments) — docs/frontend/SETTINGS.md
├── security/page.tsx              # Security            — docs/frontend/SETTINGS.md
├── api-keys/page.tsx               # Integrations (API Keys)  — docs/frontend/SETTINGS.md
├── webhooks/page.tsx                # Integrations (Webhooks)  — docs/frontend/SETTINGS.md
├── ai/page.tsx                       # AI Preferences      — docs/frontend/SETTINGS.md
└── billing/page.tsx                  # Billing/plan        — docs/frontend/SETTINGS.md
```

`SETTINGS.md → Interactions & Flows` states plainly that "`layout.tsx` redirects a request for the bare
segment to whichever tab the caller both can view and last visited... defaulting to General on a
first-ever visit," but that document's own file tree never lists a file at the exact `/settings`
segment. This is a real Next.js App Router requirement, not a stylistic omission: a `layout.tsx` wraps
whatever child segment is active, but a request for the parent segment itself with no matching child
still needs a `page.tsx` sitting directly at that segment, or the route 404s. This document supplies
that one missing file, in the same explicitly-flagged-addition spirit `SETTINGS.md` itself used for
`document_sequences` and `company_settings.default_timezone` — a small, necessary formalization of a
fact the sibling document already asserts in prose.

```tsx
// app/(app)/settings/page.tsx — NEW in this document
import { redirect } from "next/navigation";
import { cookies } from "next/headers";
import { apiServer } from "@/lib/api/server-client";
import { resolveDefaultSettingsTab } from "@/lib/settings/resolve-default-tab";

export const dynamic = "force-dynamic";

export default async function SettingsIndexPage() {
  const me = await apiServer.get("/auth/me"); // deduped by Next's per-request fetch memoization —
                                               // (app)/layout.tsx already resolved this identical call
  const lastTab = (await cookies()).get("qayd_settings_last_tab")?.value ?? null;
  redirect(resolveDefaultSettingsTab({ lastTab, permissions: me.data.active_company.permissions }));
}
```

`resolveDefaultSettingsTab` is a small, pure, fully testable function, matching the exact register
`HOME_SCREEN.md`'s own `resolveHomeMode` already established for a server-resolved default:

```ts
// lib/settings/resolve-default-tab.ts
import { SETTINGS_SECTIONS } from "./section-nav-manifest";

export function resolveDefaultSettingsTab({
  lastTab, permissions,
}: { lastTab: string | null; permissions: string[] }): string {
  const flat = SETTINGS_SECTIONS.flatMap((g) => g.items).filter((i) => !i.external);
  const canView = (item: (typeof flat)[number]) => item.permission === null || permissions.includes(item.permission);

  if (lastTab) {
    const match = flat.find((i) => i.key === lastTab);
    if (match && canView(match)) return match.href;
  }
  const general = flat.find((i) => i.key === "general")!;
  if (canView(general)) return general.href;

  // Security's own-scope carve-out (permission: null, see the manifest and the Security
  // row below) guarantees `firstVisible` is never undefined for an authenticated company
  // member — there is no company member for whom every item in this list is locked.
  return flat.find(canView)!.href;
}
```

A `qayd_settings_last_tab` cookie is this document's own small, explicitly-flagged mechanism for making
`SETTINGS.md`'s "last visited" claim server-resolvable: `SettingsSectionNav`'s own `<Link>` `onClick`
mirrors the visited tab's `key` into a `path=/settings`, one-year, `samesite=lax` cookie (`#
Components Used`) in addition to whatever client-side Zustand preference `FRONTEND_ARCHITECTURE.md`'s
State Management table already tracks for other UI prefs — the cookie exists solely so the *next*
bare-segment visit can be resolved server-side, before first paint, with the identical zero-flash
discipline `HOME_SCREEN.md`'s own `home_mode` resolution already commits to; it is not a second source
of truth for "last visited," only an SSR-readable mirror of the same fact.

## Permission gate — Section Nav item vs. Content region

| Section (Section Nav label) | Nav item ever omitted? | View permission gating Nav interactivity | Manage permission | Notes |
|---|---|---|---|---|
| General | No — always listed | `settings.company.read` | `settings.company.manage` | Always-sensitive (Owner chain) |
| Accounting | No | `settings.company.read` | `settings.company.manage` | Same resource as General |
| Tax | No | `settings.company.read` | `settings.company.manage` | Defaults only |
| Numbering | No | `settings.company.read` | `settings.company.manage` | `next_number` raises are optimistic, see `# Interactions & Flows` |
| Approval Workflows | No | `auth.policies.read` | `auth.policies.manage` | |
| Users & Roles | No | `users.read` | `users.invite`/`users.manage`; Roles sub-tab additionally `auth.roles.manage`/`users.role.assign` | Two files, one Nav item, inner `Tabs` |
| Branches & Departments | No | `branches.read`, `departments.read` | `branches.manage`, `departments.manage` | Two files, one Nav item, inner `Tabs` |
| Security | **Never** — no `permission` in the manifest at all | N/A at the Nav level | `settings.security.manage`; `auth.session.manage_others`; `settings.security.emergency_lock` | Resolved below |
| Integrations | No | `integrations.read` | `auth.apikey.create`/`.revoke`, `integrations.webhook.manage` | Two files, one Nav item, inner `Tabs` |
| Notifications | No — always listed, external | N/A (self-scoped) | N/A | `ArrowUpRight`, navigates to `/notifications/preferences` |
| AI Preferences | No | `ai.autonomy.read` | `ai.autonomy.manage` | |
| Billing | No | `companies.billing.read` | `companies.billing.manage` | Owner-tier only in default grants |

Per `SETTINGS.md → Route & Access`, no Settings tab is ever omitted from the Section Nav the way a
Sidebar module is omitted for a role that fails its own gating permission — "its existence is not
sensitive information; every company has Billing." A caller who fails a tab's view permission still sees
the row, grayed, with a `Lock` glyph and a tooltip naming the missing key; the row renders
`aria-disabled="true"`, is not a real navigable `<Link>`, and a direct URL hit to that tab's own route
still renders that page's server-enforced `<ForbiddenState>` inside the Content region, exactly the same
belt-and-braces posture `ACCOUNTING_SCREEN.md`'s own permission table already uses for its Chart of
Accounts region.

**The Security row resolves one real tension in `SETTINGS.md` itself, rather than silently picking a
side.** That document's own Tab-strip table names `settings.security.read` as Security's View permission
— implying, read alone, that a role without it sees Security locked like any other tab. Its own
Interactions & Flows section states the opposite for the tab's own-scoped content: "every role manages
its own MFA factors, sessions... with no permission check at all," and its Role grants table already
splits "Security (own factors)" (every role: `manage`) from "Security (others' sessions, policy, lock)"
(most roles: blank) as two separate rows. This document resolves the tension the same way it resolves
every other cross-document fact: the two statements describe two different surfaces, not one
contradiction. **The Security *Nav item* is never locked, for anyone** — its `permission` field in the
manifest below is `null`, exactly like Notifications — because its own-scoped content (MFA enrollment,
the caller's own session list) needs no permission at all and is never legitimately empty for an
authenticated user. `settings.security.read`/`.manage` instead gate two specific panels **inside** the
tab once it is open — the "others'" active-sessions view, the security-events feed, and the
Security Policy form — which render `<ForbiddenState>`-omitted, not tab-locked, for a caller who lacks
them. This is a Nav-level clarification only; it changes no endpoint, no permission key, and no
component `SETTINGS.md` already names.

## Keyboard entry

`G` then `S` opens `/settings` from anywhere, this document's own addition to `ACCESSIBILITY.md`'s "Go
to" mnemonic registry (`G D` Dashboard, `G A` Accounting, `G L` General Ledger, `G B` Banking, `G R`
Reports, `G I` AI Command Center — `S` was unclaimed). It always lands via the bare-route resolver
above, never directly on a specific tab, so the mnemonic's behavior is identical to clicking the
Sidebar's own "Settings" item (`NAVIGATION_SYSTEM.md`'s bottom utility row, `href="/settings/company"`
today — this document's redirect resolver supersedes that hard-coded href with the same "last visited,
else General, else first visible" logic, the identical narrow amendment `HOME_SCREEN.md` made to five
other hard-coded destinations elsewhere in the platform).

# Layout & Regions

`SETTINGS.md → Layout & Regions` already establishes *why* Settings extends the `Form Page Template`
with a new named region (a grouped vertical **Section Nav** rail) rather than reusing the platform's
ordinary horizontal `Tabs` strip — twelve destinations do not fit a scrollable tab row without becoming
"its own scrollable, hard-to-scan list." This section gives that decision its literal grid geometry and
component source rather than restating the argument.

## Desktop (`lg`+, ≥1024px per `RESPONSIVE_DESIGN.md`'s canonical tier table)

```
┌──────────────┬──────────────────────────────────────────────────────────────┐
│ Settings      │  General                                          [Cancel][Save]
├──────────────┤  Company profile, fiscal year, currency, language, timezone   │
│ COMPANY       │ ┌──────────────────────────────────────────────────────────┐ │
│ ▸ General     │ │  Changes here require your own confirmation as Owner      │ │  ← inline notice,
│   Accounting  │ │  before they take effect.                                 │ │    always-sensitive
│   Tax         │ └──────────────────────────────────────────────────────────┘ │    tabs only
│   Numbering   │ ┌──────────────────────────────────────────────────────────┐ │
├──────────────┤ │ Logo        [🖼  Upload]                                  │ │
│ ACCESS        │ │ Legal name (EN)  [___________________]                    │ │
│   Users&Roles │ │ Legal name (AR)  [___________________]                    │ │
│   Branches    │ │ Country     [Kuwait ▾]      Base currency  [KWD — locked] │ │
│   Security 🔒 │ │ Fiscal year starts  [January ▾]                            │ │
├──────────────┤ │ Default language  [English ▾]  Timezone [Asia/Kuwait ▾]   │ │
│ AUTOMATION    │ └──────────────────────────────────────────────────────────┘ │
│   Approvals   │                                                              │
│   AI Prefs    │                                                              │
├──────────────┤                                                              │
│ PLATFORM      │                                                              │
│   Integrations│                                                              │
│   Notif. ↗    │                                                              │
│   Billing     │                                                              │
└──────────────┴──────────────────────────────────────────────────────────────┘
     3/12                              9/12  (max-w-[1440px] centered container, `RESPONSIVE_DESIGN.md`)
```

`🔒` above marks a hypothetical locked row for illustration only — Security itself, per `# Route &
Access`, is never locked; a genuinely locked row (e.g. Billing for a non-Owner role) renders identically
grayed with the same glyph.

## `layout.tsx` — the shell every one of the fifteen pages mounts inside

```tsx
// app/(app)/settings/layout.tsx
import { apiServer } from "@/lib/api/server-client";
import { SettingsSectionNav } from "@/components/settings/settings-section-nav";
import { SettingsRealtimeBinder } from "@/components/settings/settings-realtime-binder";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // tenant-scoped — never statically cached

export default async function SettingsLayout({ children }: { children: React.ReactNode }) {
  const me = await apiServer.get("/auth/me"); // deduped, see # Route & Access
  const permissions: string[] = me.data.active_company.permissions;

  return (
    <SettingsRealtimeBinder companyId={me.data.active_company.id}>
      <div className="mx-auto grid max-w-[1440px] grid-cols-12 gap-6 px-6 py-6">
        <SettingsSectionNav permissions={permissions} className="col-span-12 lg:col-span-3" />
        <div className="col-span-12 lg:col-span-9">{children}</div>
      </div>
    </SettingsRealtimeBinder>
  );
}
```

No dedicated `app/(app)/settings/loading.tsx` or `error.tsx` exists, for the identical reason
`HOME_SCREEN.md` gives for its own bare route: `layout.tsx`'s one dependency (`/auth/me`) is already
resolved by the parent `(app)/layout.tsx` before this nested layout mounts, and every one of the fifteen
tab pages already owns its own `Suspense`/`HydrationBoundary` boundary per `SETTINGS.md`'s SSR
hydration pattern — a shell-level boundary here would have nothing of its own left to guard.

`SettingsRealtimeBinder` is this document's own answer to a question `SETTINGS.md → Data & State →
Realtime` names the channel and events for but never says where the subscription mounts: once, in the
shell, for the lifetime of any `/settings/*` route — not per-tab — matching `RealtimeProvider`'s own
once-per-shell mount convention (`FRONTEND_ARCHITECTURE.md`) applied one level down to a module-scoped
channel rather than the platform-wide one.

```tsx
// components/settings/settings-realtime-binder.tsx
"use client";

import { useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useEcho } from "@/lib/realtime/use-echo";
import { settingsKeys } from "@/lib/api/query-keys";

export function SettingsRealtimeBinder({ companyId, children }: { companyId: number; children: React.ReactNode }) {
  const echo = useEcho();
  const qc = useQueryClient();

  useEffect(() => {
    const channel = echo.private(`company.${companyId}.settings`);
    channel.listen(".company.settings.updated", () => {
      qc.invalidateQueries({ queryKey: settingsKeys.company() });
      qc.invalidateQueries({ queryKey: settingsKeys.companySettings() });
      qc.invalidateQueries({ queryKey: settingsKeys.documentSequences() });
    });
    channel.listen(".company.settings.approval_pending", () => qc.invalidateQueries({ queryKey: settingsKeys.all }));
    channel.listen(".company.settings.approval_decided", () => qc.invalidateQueries({ queryKey: settingsKeys.all }));
    return () => echo.leave(`company.${companyId}.settings`);
  }, [companyId, echo, qc]);

  return <>{children}</>;
}
```

## Mobile (`base`, <768px per `RESPONSIVE_DESIGN.md`'s Mobile tier)

```
┌────────────────────────────────────┐
│ ‹ Settings: General ▾               │  Page-Header-level trigger, opens Sheet
├────────────────────────────────────┤
│  General                [Cancel][Save]
├────────────────────────────────────┤
│  Changes here require your own      │
│  confirmation as Owner…             │
├────────────────────────────────────┤
│  Logo        [🖼  Upload]           │
│  Legal name (EN) [______________]  │  single column, full width
│  …                                   │
└────────────────────────────────────┘
```

See `# Responsive Behavior` for the Sheet's own content and the exact breakpoint table.

# Components Used

| Component | Source | New in this document |
|---|---|---|
| `PageHeader`, `Card`, `Tabs`, `Form`/`Input`/`Select`/`Switch`/`Textarea`/`RadioGroup`, `Combobox`, `CurrencyInput`, `FileUpload`/`Avatar`, `AlertDialog`, `Sheet`, `Tooltip`, `Skeleton`, `EmptyState`/`ErrorState`, `Badge`, `StatusPill`, `Can`, `useApiToast`, `ConfidenceBadge`, `AiCardShell`, `ReasoningDisclosure`, `ApprovalCard`, `CompanyHierarchyTree`, `ApprovalChainBuilder`, `PermissionMatrix`, `MemberTable`, `InviteMemberDialog`, `SessionList`, `EmergencyLockDialog`, `MfaEnrollmentFlow`, `PlanCard`, `SeatUsageBar` | `docs/frontend/SETTINGS.md → Components Used` (existing/already-specified) | No — reused verbatim, no prop shape changed |
| `SettingsSectionNav` | `components/settings/settings-section-nav.tsx` | **Yes** — full source below; `SETTINGS.md` names it, this document implements it |
| `SettingsRealtimeBinder` | `components/settings/settings-realtime-binder.tsx` | **Yes** — full source above |
| `DisabledWithTooltip` | `components/shared/disabled-with-tooltip.tsx` | **Yes** — small wrapper, full source below; `SETTINGS.md → Route & Access` names the pattern (`<Can permission="…" fallback={<DisabledWithTooltip …/>}>`) without giving its source |
| `SettingsFormSkeleton` | `components/settings/settings-form-skeleton.tsx` | **Yes** — full source in `# States` |
| `PendingApprovalBanner` | `components/settings/pending-approval-banner.tsx` | **Yes** — full source in `# Interactions & Flows`; the concrete implementation of the "persistent inline notice... replaced... by a live `ApprovalCard`" `SETTINGS.md → Layout & Regions` and `→ Interactions & Flows` both describe in prose |
| `useOwnPendingApproval` | `hooks/settings/use-own-pending-approval.ts` | **Yes** — full source in `# Interactions & Flows` |

None of the six "new" components introduces a design token, an API shape, a route, or a permission key
beyond what `# Route & Access` and `# Data & State` already name — each is a documented composition of
existing primitives, per `COMPONENT_LIBRARY.md`'s founding discipline, exactly as `ACCOUNTING_SCREEN.md`
and `HOME_SCREEN.md` both already commit to for their own new pieces.

## `SettingsSectionNav` — full source

```tsx
// components/settings/settings-section-nav.tsx
"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Lock, ArrowUpRight } from "lucide-react";
import { Tooltip, TooltipTrigger, TooltipContent } from "@/components/ui/tooltip";
import { cn } from "@/lib/utils";
import { useTranslations } from "@/lib/i18n/use-translations";
import { SETTINGS_SECTIONS } from "@/lib/settings/section-nav-manifest";
import { setLastVisitedSettingsTab } from "@/lib/settings/last-tab-cookie";

export function SettingsSectionNav({ permissions, className }: { permissions: string[]; className?: string }) {
  const pathname = usePathname();
  const t = useTranslations();
  const canView = (permission: string | null) => permission === null || permissions.includes(permission);

  return (
    <nav aria-label={t("settings.sectionNav.label")} className={cn("space-y-5", className)}>
      {SETTINGS_SECTIONS.map((group) => (
        <div key={group.group}>
          <p className="px-3 text-[11px] font-semibold uppercase tracking-wide text-ink-8">
            {t(`settings.group.${group.group.toLowerCase()}`)}
          </p>
          <ul className="mt-1.5 space-y-0.5" role="list">
            {group.items.map((item) => {
              const allowed = canView(item.permission);
              const active = !item.external && pathname.startsWith(item.href);
              if (!allowed) {
                return (
                  <li key={item.key}>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <span
                          role="link"
                          aria-disabled="true"
                          aria-describedby={`lock-reason-${item.key}`}
                          className="flex cursor-default items-center justify-between rounded-md border-s-2 border-transparent px-3 py-2 text-body text-ink-disabled"
                        >
                          {t(item.labelKey)}
                          <Lock className="h-3.5 w-3.5 shrink-0" aria-hidden />
                        </span>
                      </TooltipTrigger>
                      <TooltipContent id={`lock-reason-${item.key}`}>
                        {t("settings.sectionNav.requiresPermission", { permission: item.permission })}
                      </TooltipContent>
                    </Tooltip>
                  </li>
                );
              }
              return (
                <li key={item.key}>
                  <Link
                    href={item.href}
                    aria-current={active ? "page" : undefined}
                    onClick={() => !item.external && setLastVisitedSettingsTab(item.key)}
                    className={cn(
                      "flex items-center justify-between rounded-md border-s-2 px-3 py-2 text-body transition-colors",
                      active
                        ? "border-accent bg-accent-subtle/40 font-medium text-ink-12"
                        : "border-transparent text-ink-9 hover:bg-ink-3 hover:text-ink-11",
                    )}
                  >
                    {t(item.labelKey)}
                    {item.external && <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-ink-8" aria-hidden />}
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}
```

```ts
// lib/settings/section-nav-manifest.ts — single source of truth for both the desktop
// rail and the mobile Sheet (# Responsive Behavior); consumed, never re-declared, by either.
export const SETTINGS_SECTIONS = [
  { group: "COMPANY", items: [
    { key: "general",    labelKey: "settings.tab.general",    href: "/settings/company",    permission: "settings.company.read" },
    { key: "accounting", labelKey: "settings.tab.accounting", href: "/settings/accounting", permission: "settings.company.read" },
    { key: "tax",        labelKey: "settings.tab.tax",        href: "/settings/tax",        permission: "settings.company.read" },
    { key: "numbering",  labelKey: "settings.tab.numbering",  href: "/settings/numbering",  permission: "settings.company.read" },
  ]},
  { group: "ACCESS", items: [
    { key: "users",     labelKey: "settings.tab.usersRoles",           href: "/settings/users",    permission: "users.read" },
    { key: "branches",  labelKey: "settings.tab.branchesDepartments",  href: "/settings/branches", permission: "branches.read" },
    { key: "security",  labelKey: "settings.tab.security",             href: "/settings/security", permission: null }, // see # Route & Access
  ]},
  { group: "AUTOMATION", items: [
    { key: "approvals", labelKey: "settings.tab.approvalWorkflows", href: "/settings/approvals", permission: "auth.policies.read" },
    { key: "ai",         labelKey: "settings.tab.aiPreferences",     href: "/settings/ai",        permission: "ai.autonomy.read" },
  ]},
  { group: "PLATFORM", items: [
    { key: "integrations",   labelKey: "settings.tab.integrations",   href: "/settings/api-keys",           permission: "integrations.read" },
    { key: "notifications",  labelKey: "settings.tab.notifications",  href: "/notifications/preferences",   permission: null, external: true },
    { key: "billing",        labelKey: "settings.tab.billing",        href: "/settings/billing",             permission: "companies.billing.read" },
  ]},
] as const;
```

```ts
// lib/settings/last-tab-cookie.ts
"use client";
export function setLastVisitedSettingsTab(key: string) {
  document.cookie = `qayd_settings_last_tab=${key}; path=/settings; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
}
```

## `DisabledWithTooltip` — full source

```tsx
// components/shared/disabled-with-tooltip.tsx
import { Tooltip, TooltipTrigger, TooltipContent } from "@/components/ui/tooltip";

export function DisabledWithTooltip({
  children, reason,
}: { children: React.ReactElement; reason: string }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-block cursor-not-allowed" aria-disabled="true">
          {/* Cloning onto a native-disabled control keeps its own a11y semantics (aria-disabled,
              no pointer events) while the Tooltip wrapper supplies the *why*. */}
          {children}
        </span>
      </TooltipTrigger>
      <TooltipContent>{reason}</TooltipContent>
    </Tooltip>
  );
}
```

Used exactly as `SETTINGS.md → Route & Access` already specifies the pattern:
`<Can permission="settings.company.manage" fallback={<DisabledWithTooltip reason={t("settings.requiresPermission", { permission: "settings.company.manage" })}><Button disabled>Save</Button></DisabledWithTooltip>}>`.

# Data & State

This section does not re-list all fifteen tabs' own endpoints, mutations, or Zod schemas —
`SETTINGS.md → Data & State` already gives every one of them in full, and reproducing them here would
be exactly the kind of duplicated-source-of-truth risk `COMPONENT_LIBRARY.md`'s founding discipline
exists to prevent. What follows is the shell-level data contract this document is the first to specify,
plus a condensed cross-reference an engineer can scan without opening the sibling document for every
lookup.

## Query keys — reused, with one shell-level addition

```ts
// lib/api/query-keys.ts (Settings shell addition — everything else is settingsKeys.*, unchanged)
export const settingsShellKeys = {
  ownPendingApproval: (kind: string, resourceType?: string) =>
    ["settings", "shell", "own-pending-approval", kind, resourceType ?? null] as const,
};
// settingsKeys.company(), .companySettings(), .documentSequences(), .approvalChains(),
// .members(), .invitations(), .roles(), .permissionCatalog(), .branches(), .departments(),
// .sessions(), .securityPolicy(), .securityEvents(), .apiKeys(), .webhooks(), .aiAutonomy(),
// .subscription() are all defined verbatim in docs/frontend/SETTINGS.md → Data & State →
// Query keys and are not re-declared here.
```

## Condensed permission → endpoint cross-reference

| Section | Primary read endpoint | Primary write endpoint | Permission (read / write) |
|---|---|---|---|
| General | `GET /api/v1/companies/{id}` | `PATCH /api/v1/companies/{id}` | `settings.company.read` / `.manage` |
| Accounting / Tax | `GET /api/v1/companies/{id}/settings` | `PATCH /api/v1/companies/{id}/settings` | `settings.company.read` / `.manage` |
| Numbering | `GET /api/v1/companies/{id}/document-sequences` | `PATCH .../document-sequences/{document_type}` | `settings.company.read` / `.manage` |
| Approval Workflows | `GET /api/v1/auth/approval-chains` | `POST`/`PATCH`/`DELETE /api/v1/auth/approval-chains` | `auth.policies.read` / `.manage` |
| Users & Roles | `GET /api/v1/companies/{id}/members` | `POST /api/v1/user-roles`, `POST /api/v1/roles` | `users.read` / `users.invite`+`users.manage`, `auth.roles.manage` |
| Branches & Departments | `GET /api/v1/branches`, `/departments` | `POST`/`PATCH`/`DELETE` on each | `branches.read`/`departments.read` / `.manage` |
| Security | `GET /api/v1/auth/sessions`, `/security-policy`, `/security-events` | `POST /api/v1/companies/{id}/emergency-lock`, `PATCH .../security-policy` | self (own) / `settings.security.manage`, `.emergency_lock` |
| Integrations | `GET /api/v1/api-keys`, `/webhooks` | `POST /api/v1/api-keys`, `POST/PATCH /api/v1/webhooks` | `auth.apikey.read`/`integrations.read` / `auth.apikey.create`, `integrations.webhook.manage` |
| AI Preferences | `GET /api/v1/ai/autonomy-settings` | `PATCH /api/v1/ai/autonomy-settings/{permission_key}` | `ai.autonomy.read` / `.manage` |
| Billing | `GET /api/v1/companies/{id}/subscription` | `POST .../subscription/change-plan` | `companies.billing.read` / `.manage` |

Every path, verb, and permission key above is cited verbatim from `SETTINGS.md → Data & State`; this
table exists only to make the shell's own cross-cutting concerns (below) legible without a second
document open. See that document for request/response bodies, the full Zod schemas, and the four
extensions it formalizes (`document_sequences`, `company_settings.default_timezone`/
`.rounding_tolerance`/`.require_cost_center_on_journal_lines`/`.require_project_on_journal_lines`).

## Realtime — where each channel is actually bound

| Channel | Bound in | Effect |
|---|---|---|
| `private-company.{id}.settings` | `SettingsRealtimeBinder`, mounted once in `layout.tsx` (`# Layout & Regions`) — never re-bound per tab navigation | Invalidates `settingsKeys.company()`/`.companySettings()`/`.documentSequences()`, and broadly on an approval lifecycle event; the specific tab currently mounted re-renders from its own already-invalidated query, not from a channel handler it registered itself |
| `private-company.{id}.approvals` | The platform-wide binding `FRONTEND_ARCHITECTURE.md → Realtime` already establishes in `(app)/layout.tsx` | Resolves `PendingApprovalBanner`'s own live state (`# Interactions & Flows`) without Settings introducing a second approvals subscription |
| `private-company.{id}.notifications.{user_id}` | Same platform-wide binding | Topbar bell; also the channel `security.session_revoked`/`security.emergency_lock_triggered`/`security.mfa_reset` arrive on, per `SETTINGS.md`'s own Realtime table |

Binding `private-company.{id}.settings` once at the shell level rather than once per tab means a
Finance Manager who navigates from Accounting to Tax to Numbering in one visit never tears down and
re-establishes a WebSocket subscription three times in a row — the binder's `useEffect` dependency array
is `[companyId]`, not `[pathname]`, so only an actual company switch re-subscribes it.

## AI agents feeding this screen

| Agent | Where it renders | Contribution |
|---|---|---|
| Compliance Agent | Inside `SecurityPolicyForm`, above the form body | MFA-coverage gap suggestion — link-only, never a direct mutation (`SETTINGS.md → AI Integration`) |
| Fraud Detection | Feeds `securityEvents()` rows only | No card of its own on this screen — a data source for the Security tab's activity panel |
| Approval Assistant | Inside `PendingApprovalBanner` | The routing engine behind every `ai_approval_requests` row; its only visible footprint is the `ApprovalCard` this document's `# Interactions & Flows` renders |
| CFO Agent | Inside `ApprovalChainBuilder`, above the step list | Threshold-tuning suggestion on Approval Workflows — link-only |

None of the four ever calls a mutation this screen owns directly — every one ends at a human opening an
already-pessimistic or already-permission-checked form with fields pre-filled, per Design Principle 7.

# Interactions & Flows

**Arriving via the bare route.** Hitting `/settings` — from the Sidebar's "Settings" item, the `G S`
mnemonic, or a bookmark — always resolves through `resolveDefaultSettingsTab` (`# Route & Access`)
before any pixel paints; there is no intermediate "choosing a tab" flash, matching the same zero-flash
discipline `HOME_SCREEN.md`'s own `resolveHomeMode` commits to. Clicking any Section Nav row thereafter
is a real `<Link>` navigation, never client-side tab-swap state, so every one of the twelve destinations
remains independently bookmarkable and survives a hard refresh — a direct hit on `/settings/security`
lands on Security, not on General.

**The always-sensitive Save flow — worked in full for General.** This is the concrete implementation of
the flow `SETTINGS.md → Interactions & Flows` already narrates in prose ("the mutation's `onSuccess`
neither flips the form to 'saved' nor reverts it; it replaces the persistent inline notice... with a
live `ApprovalCard`, rendered inline"):

```ts
// hooks/settings/use-own-pending-approval.ts
import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api/client";
import { settingsShellKeys } from "@/lib/api/query-keys";

export function useOwnPendingApproval(kind: string, resourceType?: string) {
  return useQuery({
    queryKey: settingsShellKeys.ownPendingApproval(kind, resourceType),
    queryFn: () =>
      api.get("/approvals", {
        params: { "filter[kind]": kind, "filter[created_by]": "me", "filter[resource_type]": resourceType, status: "pending", per_page: 1 },
      }).then((r) => r.data.data[0] ?? null),
    staleTime: 0, // resolved live via private-company.{id}.approvals, not polling
  });
}
```

```tsx
// components/settings/pending-approval-banner.tsx
"use client";

import { ApprovalCard } from "@/components/shared/approval-card";
import { useOwnPendingApproval } from "@/hooks/settings/use-own-pending-approval";
import { useApproveApproval, useRejectApproval } from "@/hooks/approvals/use-approval-mutations";
import { useTranslations } from "@/lib/i18n/use-translations";

export function PendingApprovalBanner({ kind, resourceType }: { kind: string; resourceType?: string }) {
  const t = useTranslations();
  const { data: pending, isPending } = useOwnPendingApproval(kind, resourceType);
  const approve = useApproveApproval();
  const reject = useRejectApproval();

  if (isPending) return null; // the ordinary form renders underneath until this resolves
  if (!pending) {
    return (
      <p className="rounded-md border-s-2 border-s-accent bg-accent-subtle/30 px-3 py-2 text-caption text-ink-9">
        {t("settings.pendingBanner.ownerConfirmationNotice")}
      </p>
    );
  }
  return (
    <ApprovalCard
      kind={pending.kind}
      title={pending.title}
      diff={pending.payload_diff}
      requestedBy={pending.requested_by}
      requestedAt={pending.requested_at}
      compact
      // PERMISSION_BY_KIND (owned by ApprovalCard itself) decides whether this renders
      // fully interactive (the caller is the chain's current-step role) or a read-only
      // "Awaiting Owner approval" StatusPill preview — the same person can propose and
      // later approve, but never in the same render, per SETTINGS.md's own resolution.
      onApprove={() => approve.mutateAsync(pending.id)}
      onReject={(reason) => reject.mutateAsync({ id: pending.id, reason })}
    />
  );
}
```

```tsx
// components/settings/settings-general-form.tsx (excerpt — the always-sensitive pattern)
"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Form, FormField, FormItem, FormLabel, FormControl, FormMessage } from "@/components/ui/form";
import { Button } from "@/components/ui/button";
import { Can } from "@/components/auth/can";
import { DisabledWithTooltip } from "@/components/shared/disabled-with-tooltip";
import { PendingApprovalBanner } from "@/components/settings/pending-approval-banner";
import { companyGeneralSchema, type CompanyGeneralInput } from "@/lib/schemas/settings"; // SETTINGS.md
import { useUpdateCompanyGeneral } from "@/hooks/settings/use-update-company"; // SETTINGS.md, cited not reproduced
import { useApiToast } from "@/hooks/use-api-toast";

export function SettingsGeneralForm({ initial }: { initial: CompanyGeneralInput }) {
  const form = useForm<CompanyGeneralInput>({ resolver: zodResolver(companyGeneralSchema), defaultValues: initial });
  const update = useUpdateCompanyGeneral();
  const toast = useApiToast();

  async function onSubmit(values: CompanyGeneralInput) {
    try {
      await update.mutateAsync(values); // pessimistic — no onMutate, per SETTINGS.md's own rule
      toast.info("settings.general.submittedForConfirmation"); // never "saved" — see PendingApprovalBanner
    } catch (e) {
      toast.mapFieldErrors(e, form.setError);
    }
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
        <PendingApprovalBanner kind="company_settings" resourceType="companies" />
        {/* field Cards omitted — identical shape to every FormField block in SETTINGS.md's own schema */}
        <Can permission="settings.company.manage" fallback={
          <DisabledWithTooltip reason="settings.requiresManagePermission"><Button disabled>Save</Button></DisabledWithTooltip>
        }>
          <Button type="submit" disabled={update.isPending}>Save</Button>
        </Can>
      </form>
    </Form>
  );
}
```

**The ordinary/optimistic flow — worked for Branches.** `useUpdateBranch` is already given in full in
`SETTINGS.md → Data & State → Mutations`; this document adds only the form wrapping it, since the
sibling document names the hook but not the sheet:

```tsx
// components/settings/branch-edit-sheet.tsx (excerpt — the ordinary CRUD pattern)
"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetFooter } from "@/components/ui/sheet";
import { Form, FormField, FormItem, FormLabel, FormControl } from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { branchSchema, type BranchInput } from "@/lib/schemas/settings";
import { useUpdateBranch } from "@/hooks/settings/use-update-branch"; // SETTINGS.md, full source there
import { useApiToast } from "@/hooks/use-api-toast";

export function BranchEditSheet({ branch, open, onOpenChange }: { branch: BranchInput & { id: number }; open: boolean; onOpenChange: (v: boolean) => void }) {
  const form = useForm<BranchInput>({ resolver: zodResolver(branchSchema), defaultValues: branch });
  const update = useUpdateBranch(branch.id);
  const toast = useApiToast();

  async function onSubmit(values: BranchInput) {
    try {
      await update.mutateAsync(values); // optimistic — onMutate already flips the row, per SETTINGS.md
      toast.success("settings.branches.updated");
      onOpenChange(false);
    } catch (e) {
      toast.mapFieldErrors(e, form.setError); // onError already reverted the optimistic row
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="end">
        <SheetHeader><SheetTitle>Edit branch</SheetTitle></SheetHeader>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4 py-4">
            <FormField control={form.control} name="name_en" render={({ field }) => (
              <FormItem><FormLabel>Name (English)</FormLabel><FormControl><Input {...field} /></FormControl></FormItem>
            )} />
            <SheetFooter><Button type="submit" disabled={update.isPending}>Save</Button></SheetFooter>
          </form>
        </Form>
      </SheetContent>
    </Sheet>
  );
}
```

## Mutation-pattern classification — all twelve sections

| Section | Pattern | Strategy | Approval `kind` (if any) |
|---|---|---|---|
| General / Accounting / Tax / Numbering (prefix/padding/cadence) | Always-sensitive | Pessimistic | `company_settings` |
| Numbering (`next_number` raise only) | Ordinary (one-directional guard) | Optimistic | — |
| Approval Workflows | Always-sensitive | Pessimistic | `company_settings` |
| Users & Roles — invite, resend, status change | Ordinary | Optimistic | — |
| Users & Roles — role assignment, custom role save | Always-sensitive | Pessimistic | `role_grant` |
| Branches & Departments | Ordinary | Optimistic | — |
| Security — own MFA/sessions | Self-scoped, no permission check | Optimistic | — |
| Security — others' sessions, policy tightening, Emergency Lock | Permission- and step-up-gated, but **not** approval-chain-routed — see note below | Pessimistic-confirm (`AlertDialog` + MFA re-prompt), then immediate | — |
| Integrations — API key with write scope | Always-sensitive | Pessimistic | `api_key_write_scope` |
| Integrations — read-scope key, rename, revoke, webhooks | Ordinary | Optimistic | — |
| AI Preferences | Ordinary, server-validated floor/ceiling | Optimistic, row-scoped rollback on `422` | — |
| Billing — plan change/cancel | Confirm-then-commit (`AlertDialog`, proration shown) | Immediate on confirm, not approval-gated | — |

**A distinction worth stating precisely, since `SETTINGS.md`'s own prose groups both under "always
sensitive":** General/Accounting/Tax/Numbering/Approval Workflows/role assignment/write-scoped API keys
are gated by the `ai_approval_requests` **draft-then-decide** mechanism — a second, distinct UI action
resolves them. Security Policy tightening and Emergency Lock are gated by permission plus a step-up
MFA/biometric re-prompt on the *same* action — there is no separate pending row anyone else reviews
later; the confirming re-prompt **is** the entire gate, because `SETTINGS.md → Interactions & Flows`
never describes an `ApprovalCard` appearing for either of those two actions, only an `AlertDialog`. An
engineer wiring Security must not reuse `PendingApprovalBanner` there — it has no pending state to
render.

# AI Integration

Settings carries the platform's second-smallest AI surface after Notifications' "none at all"
(`SETTINGS.md → AI Integration`), and this screen's own contribution is limited to *where* the two
worked examples that document already specifies actually mount in the component tree, not a new
behavior.

- **Compliance Agent's MFA-coverage card** mounts as the first child inside `SecurityPolicyForm`'s own
  `Card`, above the `mfa_required_roles` control it links to — `<AiCardShell agentCode="compliance">`
  wrapping a `<ConfidenceBadge confidence={0.94} />` and a `<ReasoningDisclosure>`, exactly the contract
  every other AI card on the platform uses. Its two links (`Notify affected members`, `Review MFA
  policy`) are real `<Link>`/`scrollIntoView` calls, never a form-populating side effect fired without a
  click.
- **CFO Agent's threshold card** mounts above `ApprovalChainBuilder`'s step list, identical shell, single
  link (`Review suggested threshold`) that opens the existing chain pre-filled — it does not create a
  second, competing edit surface next to the human-authored one.
- **Neither card, nor any other AI surface on this screen, ever calls a mutation directly.** Every path
  from an AI card on Settings terminates at a human-operated form already covered by `# Interactions &
  Flows` above; this screen introduces no exception to that rule and no third agent beyond the two
  `SETTINGS.md` already names as rendering a card here (Fraud Detection and the Approval Assistant are
  both data/routing-only on this screen, per the table in `# Data & State`).

# States

`SETTINGS.md → States` already gives a full loading/empty/error/special matrix for all twelve tab
groups; this section covers only the shell's own two new surfaces (the Section Nav and the generic
form skeleton every tab reuses) at pixel-level geometry, and reconciles the one state that is genuinely
new here — the redirect resolver itself has no state at all.

| Region | Loading | Empty | Error | Special |
|---|---|---|---|---|
| Bare `/settings` redirect | None visible — `redirect()` throws before any HTML streams, per `# Route & Access`; there is no flash of an intermediate shell | N/A | If `GET /auth/me` itself fails, the shell's own root `error.tsx` boundary (`FRONTEND_ARCHITECTURE.md`) catches it — the redirect resolver introduces no error handling of its own | None |
| `SettingsSectionNav` | `SettingsSectionNavSkeleton` (below) on first paint only; never reloads on tab switch, since `permissions` comes from the already-resolved `layout.tsx` fetch | N/A — the twelve-item, four-group manifest is a fixed constant, never empty | A permission-check failure for one item renders that one item locked (`# Route & Access`), never a whole-rail error | Security's row never shows a lock state, per `# Route & Access` |
| Content region, any tab | `SettingsFormSkeleton` (below), matching that tab's own field count | Governed entirely by `SETTINGS.md`'s own per-tab Empty column | Governed entirely by `SETTINGS.md`'s own per-tab Error column | `PendingApprovalBanner`'s three renders — `null` while resolving, the plain notice, or a live `ApprovalCard` — are this document's own concrete form of `SETTINGS.md`'s "Pending confirmation" row |

```tsx
// components/settings/settings-section-nav-skeleton.tsx
import { Skeleton } from "@/components/ui/skeleton";

const GROUP_ITEM_COUNTS = [4, 3, 2, 3] as const; // COMPANY, ACCESS, AUTOMATION, PLATFORM — matches the fixed manifest

export function SettingsSectionNavSkeleton() {
  return (
    <div className="space-y-5" aria-hidden>
      {GROUP_ITEM_COUNTS.map((count, gi) => (
        <div key={gi} className="space-y-1.5">
          <Skeleton className="h-2.5 w-16 rounded" />
          {Array.from({ length: count }).map((_, i) => (
            <Skeleton key={i} className="h-8 w-full rounded-md" style={{ animationDelay: `${(gi * 4 + i) * 40}ms` }} />
          ))}
        </div>
      ))}
    </div>
  );
}
```

```tsx
// components/settings/settings-form-skeleton.tsx
import { Skeleton } from "@/components/ui/skeleton";

export function SettingsFormSkeleton({ fieldCount = 6 }: { fieldCount?: number }) {
  return (
    <div className="space-y-4" aria-hidden>
      <Skeleton className="h-4 w-64 rounded" /> {/* the inline notice row, always-sensitive tabs only */}
      <div className="space-y-4 rounded-lg border border-ink-6 p-5">
        {Array.from({ length: fieldCount }).map((_, i) => (
          <div key={i} className="space-y-1.5">
            <Skeleton className="h-3 w-24 rounded" style={{ animationDelay: `${i * 40}ms` }} />
            <Skeleton className="h-9 w-full rounded-md" style={{ animationDelay: `${i * 40}ms` }} />
          </div>
        ))}
      </div>
    </div>
  );
}
```

Both skeletons use the identical staggered `animationDelay` sweep `ACCOUNTING_SCREEN.md`'s own
`TreeSkeleton` already establishes (`DESIGN_LANGUAGE.md → Motion → Named patterns`, a 1.6s single-wave
shimmer) rather than every row pulsing in lockstep; `fieldCount` is passed per tab (General: 7,
Accounting: 4, Tax: 1, Numbering: 4 per row × up to 10 rows rendered as a table skeleton instead —
Numbering's own page composes `DataTable`'s existing skeleton, not this one).

# Responsive Behavior

`RESPONSIVE_DESIGN.md`'s canonical five-tier system (Mobile <640px unprefixed/`sm:` 640px, Tablet `md:`
768px, Laptop `lg:` 1024px, Desktop `xl:`/`2xl:` 1280px/1536px) is this document's source of truth for
breakpoint boundaries; it refines, rather than contradicts, `SETTINGS.md → Responsive Behavior`'s own
coarser range labels (`base–sm`, `md`, `lg`, `xl+`) the same way `ACCOUNTING_SCREEN.md`'s own Tablet/
Mobile tier table already refines that same document's ranges for its own screen — both describe the
identical collapse point (persistent rail above 1024px, off-canvas below it), stated at two different
levels of precision for two different audiences.

| Tier | Behavior |
|---|---|
| Mobile (<768px) | `SettingsSectionNav` is replaced entirely by a `Sheet` (`side="bottom"`), triggered by a Page-Header-level `"Settings: {activeTabLabel} ▾"` control, per `SETTINGS.md`'s own mobile table. The Sheet renders the identical `SETTINGS_SECTIONS` manifest (`# Components Used`) grouped under sticky headers; picking a row closes the Sheet, navigates, and fires the same `setLastVisitedSettingsTab` cookie write the desktop rail's `<Link>` does. `SettingsFormSkeleton`'s field rows stack single-column with no min-width; `SettingsSectionNavSkeleton` is not shown on mobile at all — the trigger button renders synchronously with a plain `Skeleton` chip in place of the tab-name label. |
| Tablet (768–1023px) | The rail returns as a persistent, icon-and-tooltip-only 72px column (mirroring the platform Sidebar's own collapsed state) rather than the full labeled 3/12 rail — there is not yet enough width for both a labeled rail and a comfortable form column. Locked items show the same `Lock` glyph with no visible label; the tooltip carries both the section name and the missing permission in that case, since the label itself is not on screen to read. |
| Laptop (1024–1279px) | The full labeled 3/12 rail returns exactly as drawn in `# Layout & Regions`; every inner two-item `Tabs` pair (Users/Roles, Branches/Departments, API Keys/Webhooks) sits inline with its Page Header. |
| Desktop (≥1280px) | Unchanged from Laptop in structure — the extra width is spent on the `max-w-[1440px]` centered container's own margin, per `RESPONSIVE_DESIGN.md`'s stated reasoning for why `2xl:` "does not add density." |

Every row action inside `MemberTable`, `SessionList`, and `ApprovalChainBuilder`'s step list, plus every
Section Nav item and the mobile trigger button, meets the platform's 44×44px minimum touch target with
an 8px gap at every tier, per `RESPONSIVE_DESIGN.md`'s Touch Targets rule — material here specifically
because a mis-tap on a Section Nav row silently opens the wrong configuration surface rather than
merely mis-scrolling a page.

# RTL & Localization

Every rule `SETTINGS.md → RTL & Localization` already states for the fifteen tabs' own content —
real, separately-authored Arabic tab/group labels, `dir="ltr"`-pinned IPs/timestamps/thresholds,
one-directional bilingual fallback, Western Arabic-Indic-never numerals, the fixed `ICONOGRAPHY.md`
mirror table — applies unchanged to this screen's own shell chrome, with the following concrete
implementation notes specific to the shell.

- **`SettingsSectionNav`'s active-item indicator uses `border-s-2`, never `border-l-2`/`border-r-2`**
  (literal, in the source above) — the one line of code that makes the accent rule land on the correct
  physical edge in both directions without a second, RTL-specific className branch.
- **The rail itself needs no `dir`-conditional grid placement.** Being `col-span-12 lg:col-span-3` at
  the logical *start* of a 12-column grid whose own container carries `dir="rtl"` under an Arabic
  session, CSS Grid alone relocates it to the visual right — `layout.tsx`'s own JSX in `# Layout &
  Regions` contains no conditional class for this.
- **The mobile trigger's disclosure chevron (`▾` in the wireframe) mirrors**, per `ICONOGRAPHY.md`'s
  reading-direction group, while the Section Nav's own `Lock` and `ArrowUpRight` glyphs do not, matching
  `SETTINGS.md`'s identical rule stated for the same two icons.
- **The `qayd_settings_last_tab` cookie value is the manifest's own `key` field** (`"general"`,
  `"security"`, …), never the translated label — a locale switch mid-session never invalidates or
  mis-resolves a previously-set redirect preference, since the value being compared is a stable
  identifier, not display text.

| Context | English | Arabic |
|---|---|---|
| Bare-route Page title (mobile trigger) | Settings: {tab} | الإعدادات: {tab} |
| Section Nav `aria-label` | Settings sections | أقسام الإعدادات |
| Locked-item tooltip | Requires `{permission}` | يتطلب صلاحية `{permission}` |
| Group — COMPANY | Company | الشركة |
| Group — ACCESS | Access | الوصول |
| Group — AUTOMATION | Automation | الأتمتة |
| Group — PLATFORM | Platform | المنصة |

# Dark Mode

Dark mode on this screen is a pure token remap, per the platform's binding "one token set, two themes"
rule — `SettingsSectionNav` and the two new skeleton components introduce no color, elevation, or
radius token beyond `DESIGN_LANGUAGE.md`'s existing twelve-step `ink` scale and single `accent` family.

- **The rail's active-item fill** is `bg-accent-subtle/40` with a `border-accent` inline-start rule in
  both themes — `accent-subtle` itself already remaps from `#EADFBF` (light) to `#3A2E14` (dark) at the
  token layer, so the component's own class list never branches on theme.
- **A locked item's text and `Lock` glyph** use `text-ink-disabled`, the same semantic class
  `SETTINGS.md → Layout & Regions` already names, which resolves through `ink-8` in both themes
  (`#878174` light / `#786F55` dark) — a dedicated "disabled" gray distinct from the ink scale is never
  introduced.
- **`PendingApprovalBanner`'s plain-notice state** uses the identical `border-s-2 border-s-accent
  bg-accent-subtle/30` treatment in both themes as the rail's active-item fill, at a slightly lower
  opacity so the two are visually distinguishable as "current location" versus "informational notice"
  without a second hue.
- **`SettingsFormSkeleton`'s shimmer** uses the platform's standard `Skeleton` component unchanged —
  `ink-3` base with an `ink-4` sweep in light mode, remapped identically to their dark-mode token values
  with no Settings-specific override.
- Every Storybook story for `SettingsSectionNav`, `SettingsFormSkeleton`, `SettingsSectionNavSkeleton`,
  and `PendingApprovalBanner` ships the platform's standard four-way parameter matrix (light/LTR,
  light/RTL, dark/LTR, dark/RTL), matching `HOME_SCREEN.md`'s identical testing convention for its own
  new shell-level components.

# Accessibility

This screen targets the same WCAG 2.2 AA floor as every other QAYD surface; `SETTINGS.md →
Accessibility` already specifies the Section Nav's landmark structure, roving `tabindex`, and
`PermissionMatrix`/`MemberTable`/`SessionList`'s own table semantics in full. This section adds the
concrete keyboard-handling code for the one interaction `SETTINGS.md` describes in prose but does not
implement — arrow-key roving focus within the rail.

```tsx
// components/settings/settings-section-nav.tsx (roving-tabindex excerpt, added to the source above)
function useRovingIndex(itemCount: number) {
  const [activeIndex, setActiveIndex] = useState(0);
  const onKeyDown = (e: React.KeyboardEvent, index: number) => {
    if (e.key === "ArrowDown") { e.preventDefault(); setActiveIndex(Math.min(index + 1, itemCount - 1)); }
    if (e.key === "ArrowUp") { e.preventDefault(); setActiveIndex(Math.max(index - 1, 0)); }
  };
  return { activeIndex, onKeyDown };
}
```

Each rendered `<Link>`/locked `<span>` in the rail carries `tabIndex={index === activeIndex ? 0 : -1}`
and a `ref` the roving hook focuses imperatively on `ArrowUp`/`ArrowDown`, so a screen-reader or
keyboard-only user presses `Tab` exactly once to enter the rail, arrows through all twelve items
(locked ones included, since a locked item's *reason* is itself information worth reaching), and a
second `Tab` leaves it for the Content region — identical behavior to `SETTINGS.md → Accessibility`'s
own stated requirement, expressed here as the literal hook rather than a description of the outcome.

- **`PendingApprovalBanner` announces `aria-live="polite"` on both of its transitions** (notice
  appearing on Save; resolving into an `ApprovalCard` or a rejection message), never `"assertive"`,
  matching `SETTINGS.md`'s own rule that neither event is a blocking error.
- **The mobile Sheet trigger is a real, labeled `<button>`** (`aria-haspopup="dialog"`,
  `aria-label="{t('settings.sectionNav.openLabel')}: {activeTabLabel}"`), never an icon-only chevron —
  its accessible name states both that it opens a picker and which tab is currently active.
- **`DisabledWithTooltip`'s wrapped control keeps native `disabled` semantics** rather than relying on
  the wrapper's own `aria-disabled` alone — a screen reader announces the control itself as unavailable,
  and the Tooltip supplies *why*, matching the two-part pattern `SETTINGS.md → Accessibility` requires
  for every gated field on every tab.
- **Focus never jumps on tab navigation.** Clicking a Section Nav row moves the browser's own focus to
  the newly rendered Content region's `<h1>` only via the platform's standard route-change focus
  management (`ACCESSIBILITY.md`'s App Router convention), never to an arbitrary field — a keyboard user
  landing on a new tab always starts oriented at its heading, not mid-form.

# Performance

- **Fifteen route segments, fifteen independent bundles**, unchanged from `SETTINGS.md → Performance` —
  this document's own addition (the bare `page.tsx` redirect) adds no client bundle at all, since it is
  a Server Component that never renders visible UI of its own.
- **The Section Nav's own data dependency is free.** `permissions` is a field already present on the
  `GET /auth/me` response every route in the shell fetches via Next's request-level fetch memoization
  (`# Route & Access`) — `SettingsSectionNav` triggers no query of its own, client or server; it is a
  pure function of a prop its parent `layout.tsx` already had.
- **The redirect resolver never round-trips the network for its decision.** `resolveDefaultSettingsTab`
  is a synchronous, in-memory function over data already fetched — the only asynchronous work on the
  bare-route path is the two calls (`/auth/me`, reading the cookie) that would have happened regardless
  of which tab was ultimately chosen.
- **The realtime channel binds once, not fifteen times.** Per `# Data & State`, `SettingsRealtimeBinder`
  mounts in `layout.tsx`, so navigating among tabs inside `/settings` never tears down and re-opens a
  WebSocket subscription — only entering or leaving the `/settings/*` subtree, or an actual company
  switch, does.
- **Heavy per-tab surfaces remain independently code-split**, unchanged from `SETTINGS.md → Performance`
  — `MfaEnrollmentFlow`'s WebAuthn path, the Stripe Elements payment form, and `CompanyHierarchyTree`
  each load via `next/dynamic` regardless of which tab a user's `resolveDefaultSettingsTab` call lands
  them on first.
- **Web Vitals** for the bare-route redirect itself are not tracked as a distinct page view — since it
  never paints, it contributes no LCP/CLS of its own; the first meaningful paint users and monitoring
  both experience is whichever tab's own budget (`SETTINGS.md`'s per-tab targets, inherited unchanged)
  the redirect resolved to.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| A caller's `qayd_settings_last_tab` cookie names a tab they could view when it was set, but a role change since then has revoked that permission | `resolveDefaultSettingsTab` re-checks `canView` against the *current* `permissions` array on every redirect, never a cached decision — the stale cookie value is simply skipped and the function falls through to General, then first-visible, exactly as if no cookie existed |
| A brand-new user's very first visit to `/settings`, before any tab has ever been opened | No cookie exists; `resolveDefaultSettingsTab` lands on General if visible (the overwhelmingly common case, since `settings.company.read` is granted broadly per the Role grants table), otherwise the first section the caller's role can see |
| A role for whom literally every section is locked | Structurally impossible, by construction — Security's Nav-level `permission: null` (`# Route & Access`) means every authenticated company member can always view at least Security's own-scoped content, so `flat.find(canView)` in the redirect resolver is never `undefined` and no "nothing to show" state needs to exist on this screen |
| Two browser tabs open to different `/settings/*` routes; the user saves an always-sensitive change in one | `SettingsRealtimeBinder`'s shell-level subscription means both tabs' `PendingApprovalBanner` instances (if both are mounted on a tab sharing the same `resourceType`) resolve from the identical realtime event — there is no "one tab is stale" case the way `HOME_SCREEN.md`'s own cross-tab edge case describes for its low-stakes `home_mode` preference, because Settings' approval state is server-authoritative and realtime-pushed, not a per-tab local preference |
| The mobile Sheet is open and the user rotates the device past the 768px tablet boundary mid-interaction | The Sheet closes automatically (a `useEffect` watching `useBreakpoint()` per `RESPONSIVE_DESIGN.md`'s own hook) and the persistent rail mounts in its place — the underlying route does not change, only which of the two navigation surfaces renders |
| A direct URL hit on a locked tab's own route (e.g. a Sales Employee typing `/settings/billing`) | The Section Nav item itself is never reachable to click, but the route still resolves server-side to that tab's own `page.tsx`, which renders its documented `<ForbiddenState>` inside the Content region — `layout.tsx` and `SettingsSectionNav` render normally around it, so the caller still sees which other sections they *can* reach, never a blank shell |
| The `qayd_settings_last_tab` cookie is stripped or blocked (private browsing, cookie-clearing extension) | `resolveDefaultSettingsTab`'s `lastTab` argument is simply `null` in that case — behaviorally identical to a first-ever visit; no error, no fallback banner, since "no preference on file" is an ordinary, expected input to that function, not an exceptional one |
| A company switch fires while `/settings/{tab}` is open | Identical double mechanism to every other screen in the platform (`queryClient.clear()`, `router.refresh()`) followed by `router.push('/settings')` rather than re-pushing the same tab path — the bare route re-resolves against the newly active company's own permission set and its own `qayd_settings_last_tab` cookie is company-agnostic by design (a per-user, not per-company, browser-level preference), so a user who last had Billing open on Company A and switches to Company B where they hold no Billing access is redirected to General or first-visible for Company B, never left on a route their new company forbids |

# End of Document
