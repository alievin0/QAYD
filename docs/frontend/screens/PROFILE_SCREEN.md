# Profile Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: PROFILE_SCREEN
---

# Purpose

This document is the concrete, implementation-ready screen specification for the current user's own
profile route, `app/(app)/profile`, written against the platform's `SCREEN DOC STRUCTURE` template so it
can be read, reviewed, and diffed section-by-section alongside every other screen document in this
series — Dashboard, Accounting, General Ledger, Journal Entries, Trial Balance, the Financial
Statements, Bank Reconciliation, the AI Command Center, Home, Settings, and Notifications. It is the same
kind of companion to `docs/frontend/PROFILE.md` that `ACCOUNTING_SCREEN.md` already is to
`docs/frontend/ACCOUNTING.md`: `PROFILE.md` argues, at full policy depth, why Profile is the one screen
in QAYD that belongs to the **person** rather than the company, resolves three real ownership boundaries
(notification preferences owned by `docs/frontend/NOTIFICATIONS.md`, the superseded
`settings/appearance` route, and the `settings/team`/`settings/api-keys` admin surfaces), standardizes
the `user_preferences` storage-and-path drift across `THEMING.md`/`DARK_MODE.md`, and names every
endpoint, guard, and edge case for all six sections — **Personal Info, Preferences, Security, Company
Memberships, Personal API Tokens, and Data & Privacy** (plus the deep-linked Notifications rail item).
This document does not re-argue, re-derive, or re-decide a single one of those facts. What it adds is the
layer an engineer opens beside their editor while wiring the shell those six sections share: the literal
`layout.tsx`, `page.tsx`, and `ProfileSectionRail` source, the parallel `Promise.all` prefetch
`PROFILE.md → Performance` names but does not draw, two fully worked form-mutation patterns (the
save-button/pessimistic Personal Info form and the instant-apply/optimistic Preferences controls) that
this screen's own sections are then classified against, the `CopyableSecret` component `PROFILE.md`
introduces by name and prop-list but not source, and pixel-level
state/responsive/dark-mode/accessibility detail in place of a reference to "the shared token set."

Where this document is silent on a fact, `docs/frontend/PROFILE.md` governs first, then
`docs/frontend/NOTIFICATIONS.md` (for anything touching notification preferences),
`FRONTEND_ARCHITECTURE.md`, `DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `NAVIGATION_SYSTEM.md`,
`LAYOUT_SYSTEM.md`, `RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and `ACCESSIBILITY.md`, in that order — and,
for the authentication-shaped flows (password change, MFA enrollment, session revocation, personal
tokens), `docs/api/AUTHENTICATION_API.md`, which every one of those flows is a UI over rather than a
reimplementation of. Where this document appears to contradict one of them on a fact — a route, a
permission key, an endpoint path, a component name — that is a defect to raise in review, never a
decision an engineer resolves unilaterally in code.

Three constraints inherited unchanged from `FRONTEND_ARCHITECTURE.md` and restated by every sibling
screen document bind this one identically, though two of the three are trivially satisfied here because
Profile is a personal, non-financial surface. **The frontend computes nothing** — every value this
screen renders (the caller's own name, avatar, locale, session list, MFA-factor set, membership roles)
was persisted by Laravel and is read or written through a plain CRUD endpoint; this screen's own logic is
limited to resolving which section leaf is active, mapping a save into the right mutation strategy, and
looping a per-company action across the caller's own `companies` array where one is documented as
per-membership. **AI is visible, labeled, and never silent** — vacuously so on this screen: it renders no
`ConfidenceBadge`, `AiCardShell`, or `AIProposalPanel`, because there is no agent judgment about a
person's own name or theme to surface, exactly as `PROFILE.md → AI Integration` establishes and this
document's own `# AI Integration` restates. **RBAC is enforced by the API; the UI only reflects it** —
and Profile carries **no company-scoped permission at all**: every section operates on the caller's own
`user_id`, every mutation is a "mine" action, and the two narrow guards that do exist
(`LAST_MFA_FACTOR_REQUIRED`, `SOLE_OWNER_CANNOT_LEAVE`) are account-integrity checks enforced
server-side, mirrored client-side as a courtesy disable-with-tooltip, never as the authority.

Concretely, this screen composes four pieces of surface, each owned elsewhere and only rendered,
routed, or (in one case) newly formalized here:

- **The Section Rail shell** — `app/(app)/profile/layout.tsx` plus `ProfileSectionRail`, the persistent
  vertical rail `PROFILE.md → Layout & Regions` specifies in prose and ASCII; this document gives it a
  literal, `Promise.all`-prefetched implementation and its `md`-below horizontal-scroll fallback.
- **The bare-route Personal Info page** — `app/(app)/profile/page.tsx`, which unlike Settings needs **no**
  redirect resolver: Personal Info *is* the default section, rendered directly at the bare segment with no
  intermediate hop (`# Route & Access`).
- **Two worked form-mutation patterns** — the save-button/pessimistic pattern (Personal Info) and the
  instant-apply/optimistic pattern (Preferences) — against which all six sections' write surfaces are then
  classified in one table, rather than each section re-deriving its own mutation wiring.
- **`CopyableSecret`** — the one new component `PROFILE.md → Components Used` introduces, given its literal
  source here; the shown-once plaintext display shared by a freshly created personal API token and freshly
  regenerated MFA backup codes.

This screen owns no business logic and no company data. It never reads `X-Company-Id` for its own
sections' data (`PROFILE.md → Route & Access`'s "Company/branch scope: Fixed to none"), it never renders
an "others" action (every mutation is scoped to the caller's own `user_id`), and where its content
overlaps an admin capability — a personal API token, a session, a company membership — it is scoped to
"mine" with a plain link out to the admin surface (`/settings/users`, `/settings/api-keys`) for anyone
who separately holds the broader company-scoped permission.

# Route & Access

## App Router path

```text
app/(app)/profile/
├── layout.tsx                # Section Rail shell — Server Component; Promise.all-prefetches
│                             # profileKeys.me() (seeded from SessionProvider) + the active leaf's own query
├── page.tsx                  # * THIS DOCUMENT (primary) — Personal Info, the default section, NO redirect hop
├── preferences/page.tsx      # Appearance & Preferences — theme, density, text size, reduced motion
├── security/page.tsx         # Password, MFA factors, active sessions/devices
├── companies/page.tsx        # Company memberships list + switch/leave
├── tokens/page.tsx           # Personal API tokens ("mine" view over auth/api-keys)
└── privacy/page.tsx          # Data export request + AI-memory erasure + account-deletion request
```

Unlike `app/(app)/settings` — whose bare `/settings` segment needs a `page.tsx` redirect resolver
because it has no natural default tab (`SETTINGS_SCREEN.md → Route & Access`) — Profile's bare `/profile`
segment **is** Personal Info, rendered directly, with no `resolveDefaultSettingsTab`-style hop and no
zero-flash concern to engineer around. `PROFILE.md → Route & Access`'s route tree already lists
`page.tsx` as "Personal Info — the default section, no redirect hop"; this document builds exactly that,
and adds no second file at the bare segment. This is the deliberate structural difference between the two
personal-settings shells: Settings has twelve equal-weight destinations and must resolve which one a bare
hit lands on; Profile has one obvious front door, so its `page.tsx` is the front door itself.

Every leaf is a real nested route, independently bookmarkable, back-button-safe, and deep-linkable from a
toast, an email, or the Command Palette — `PROFILE.md → Route & Access`'s stated preference for
`<Link>`-driven sections over a single `page.tsx` branching on `?section=`, and the exact flat-file
pattern `NOTIFICATIONS.md`'s own Inbox/Preferences pair uses. There is no dynamic segment anywhere in the
tree because the "one entity" is always the caller's own account.

## Permission gate

Profile is the platform's clearest example of a route with **no gating permission**. The table below
states this at the same granularity `ACCOUNTING_SCREEN.md`'s own permission table uses — per control —
precisely so a reader arriving from an accounting screen does not expect a `usePermission()` wall that
is not there.

| Control | Permission | Behavior if absent |
|---|---|---|
| Screen visible at all | **None** — authenticated session only | There is no "absent" case for an authenticated user; a signed-out hit resolves through the platform's ordinary `(app)`-group auth redirect (`middleware.ts`), never a `403` boundary |
| Every section (Personal Info, Preferences, Security own-scope, Companies, Tokens, Privacy) | **None** — operates on the caller's own `user_id` | N/A — a user may always read and write their own account |
| "Remove" on the last **verified** MFA factor, for a role with mandatory MFA | Not an RBAC permission — an account-integrity guard | Disabled with a `Tooltip` ("Add another method before removing this one."); the server's `422 LAST_MFA_FACTOR_REQUIRED` is the authority if a stale UI reaches it |
| "Leave company" when the caller is the company's sole active Owner | Not an RBAC permission — an account-integrity guard | Disabled with a `Tooltip` ("Transfer ownership before leaving."); the server's `422 SOLE_OWNER_CANNOT_LEAVE` is the authority |
| "Manage all company keys" out-link (Personal API Tokens) | `auth.apikey.read` **at the active company** | The out-link is **omitted** (existence-sensitive) — a personal-scope token the caller created is always visible to them regardless; only the cross-company admin link is gated |
| "Delete my account" reachability | Not an RBAC permission — a fresh MFA step-up (`POST /api/v1/auth/mfa/verify`, `purpose: "step_up"`) | The action is unreachable until the step-up succeeds; there is no permission that omits it, only a re-authentication that unlocks it |

The one place this screen renders a company-scoped permission check at all is the Personal API Tokens
section's "Manage all company keys" out-link — and even there the check gates a *link to another screen*,
never any data or mutation Profile itself owns. Everything else on this screen is reachable by the
narrowest role in the platform (Read Only, External Auditor) exactly as it is by an Owner, per
`PROFILE.md → Edge Cases` #13.

## Roles

| Role | What renders on this screen |
|---|---|
| Every human role — Owner, CEO/Admin, CFO, Finance Manager, Senior Accountant, Accountant, Auditor, External Auditor, Read Only, and every module self-service role | The **identical** shell and every section, in full. The only per-user variation is data volume (a user in four companies has four Company Membership rows; a user in one has one) and the two account-integrity guards above — never a permission-driven omission of a section, a field, or a mutation |
| AI service account | Never renders this screen as a user, and never appears *inside* it either — the FastAPI AI layer authenticates with its own service JWT under a synthetic `ai_service` role (`AUTHENTICATION_API.md → AI Engine Service Tokens`), which is not a human `sessions`/`mfa_factors`/`company_users` row, so it can never surface in the caller's Active Sessions list or Company Memberships list, by construction rather than by filtering (`PROFILE.md → AI Integration`) |

Keyboard entry: `G` then `P` opens this screen from anywhere, `PROFILE.md → Route & Access`'s own
addition to `ACCESSIBILITY.md`'s "Go to" mnemonic registry (`P` was unclaimed; `G D`/`G A`/`G L`/`G B`/`G
R`/`G I`/`G S` are the neighbours). It always lands on Personal Info, the same place clicking the
Topbar `UserMenu`'s "Profile" item lands.

# Layout & Regions

This screen instantiates `LAYOUT_SYSTEM.md`'s **Detail Page Template** — "the full record view for one
entity," the caller's own account — with the one named extension `PROFILE.md → Layout & Regions` already
justifies: the Detail Template's ordinary horizontal `Tab/Segment Nav` slot is replaced by a **persistent
vertical Section Rail** at `md`+ (six sections plus a deep-linked Notifications item is too many for a
single-row strip), and the Template's `Summary Rail` slot is **dropped entirely** (a personal settings
screen has no lifecycle status, amount, or approval-chain progress to summarize) with the Section Rail
taking that column position. The desktop wireframe below is reproduced from `PROFILE.md` for a single
source of truth; the tablet and mobile wireframes are this document's own addition, since `PROFILE.md`
describes the responsive collapse in prose without drawing it.

## Desktop (`lg`+, ≥1024px)

```
┌───────────────┬───────────────────────────────────────────────────────────┐
│  ● Sara Al-K.  │  Personal Info                                            │  profile/layout.tsx
│  Finance Mgr   │  ─────────────────────────────────────────────────────── │  (Rail Header + Section Rail)
│  ────────────  │                                                           │
│  Personal Info │   [ [user] ]   Sara Al-Kandari                                │  page.tsx (Main Column)
│  Preferences   │            سارة الكندري          [Change photo]           │
│  Notifications↗│                                                           │
│  Security      │   Full name (EN)   [ Sara Al-Kandari              ]      │
│  Companies     │   Full name (AR)   [ سارة الكندري                  ]      │
│  API Tokens    │   Email            sara.alkandari@qayd-demo.com  [check]       │
│  Data & Privacy│   Phone            [ +965 5011 2233               ]      │
│                │   Language          ( ) English   (•) العربية            │
│                │   Timezone          [ Asia/Kuwait ▾ ]                    │
│                │                                          [Save changes]  │  ← sticky bar once header scrolls off
└───────────────┴───────────────────────────────────────────────────────────┘
     3/12                                          9/12  (max-w-[1440px] centered)
```

## Tablet (`md`, 768–1023px)

The Section Rail remains a horizontal `Tabs` strip pinned under the Rail Header (which moves inline
above it — avatar, name, active-company role in one row) rather than the persistent vertical column;
Personal Info's shorter fields (phone + timezone) become a two-column grid; every `Card`-grouped block in
Security remains full-width and single-column.

## Mobile (`base`–`sm`, <768px)

```
┌────────────────────────────────────┐
│ ● Sara Al-Kandari · Finance Mgr    │  Rail Header, inline
├────────────────────────────────────┤
│ ‹ Personal │ Preferences │ Notif ↗ │  Section Rail → horizontal-scroll Tabs strip,
│   Security │ Companies │ …         │  snap-aligned, no wrap
├────────────────────────────────────┤
│  Personal Info                     │  Main Column, full width, single column
│  [ [user] ] Sara Al-Kandari  [Change]  │
│  Full name (EN) [______________]  │
│  …                                  │
│                       [Save changes]│  sticky bottom bar
└────────────────────────────────────┘
```

Confirmation dialogs (password change, MFA removal, leave-company, account-erasure) render as
full-height bottom `Sheet`s below `sm` rather than centered `Dialog`s, per `RESPONSIVE_DESIGN.md → Pattern
4` and `PROFILE.md → Responsive Behavior`.

## Region table with implementation-level grid classes

| Region | Container classes | Content | Notes |
|---|---|---|---|
| Rail Header | `col-span-12 lg:col-span-3`, `flex items-center gap-3 pb-4 lg:flex-col lg:items-start` | `AvatarWithFallback` + name + **active-company role only** | Not a merged cross-membership role list — that is the Companies section's job |
| Section Rail | `col-span-12 lg:col-span-3` (shares the rail column with the header) | 7 `<Link>` items (incl. deep-linked Notifications) | Built on `Tabs`' `layoutId`-animated indicator, rendered vertically at `lg`+, horizontally below |
| Main Column | `col-span-12 lg:col-span-9` | The active section's form/list | Every leaf `page.tsx` renders here |
| Sticky Save Bar | inside Main Column, `sticky bottom-0` once the header scrolls off | Personal Info + Preferences' primary action | Preferences rarely needs it (instant-apply), present for the rare batched edit (`PROFILE.md`) |

```tsx
// app/(app)/profile/layout.tsx — Server Component
import { Promise as _keepTsHappy } from "node:timers"; // illustrative only; real impl uses global Promise
import { getQueryClient } from "@/lib/api/query-client-server";
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { apiServer } from "@/lib/api/server-client";
import { getSessionUser } from "@/lib/auth/session-server"; // SessionProvider's already-resolved /auth/me
import { profileKeys } from "@/lib/api/query-keys";
import { ProfileRailHeader } from "@/components/profile/profile-rail-header";
import { ProfileSectionRail } from "@/components/profile/profile-section-rail";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // user-scoped — never statically cached

export default async function ProfileLayout({ children }: { children: React.ReactNode }) {
  const qc = getQueryClient();
  const session = await getSessionUser(); // deduped by Next's per-request fetch memoization

  // Parallel, not waterfalled (PROFILE.md → Performance): the Rail Header's data and the active
  // leaf's own prefetch resolve concurrently. profileKeys.me() is SEEDED from the session's already
  // -resolved user object — no duplicate network call on navigation from elsewhere in the app.
  await Promise.all([
    qc.prefetchQuery({
      queryKey: profileKeys.me(),
      queryFn: () => apiServer.get("/users/me").then((r) => r.data),
      initialData: session.user, // seed; a real GET /users/me only fires on hard refresh / direct nav
    }),
  ]);

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <div className="mx-auto grid max-w-[1440px] grid-cols-12 gap-6 px-6 py-6">
        <div className="col-span-12 lg:col-span-3">
          <ProfileRailHeader
            user={session.user}
            activeCompanyRole={session.activeCompany.roleLabel}
          />
          <ProfileSectionRail className="mt-4" />
        </div>
        <div className="col-span-12 lg:col-span-9">{children}</div>
      </div>
    </HydrationBoundary>
  );
}
```

`ProfileLayout` mounts no realtime binder — Profile subscribes to **no** Reverb channel at all, a
deliberate omission `PROFILE.md → Data & State → Realtime` argues in full (every platform channel is
`private-company.{id}.<feature>`-scoped, and nothing on this screen is company-scoped). There is likewise
no dedicated `profile/loading.tsx` or `profile/error.tsx`: `layout.tsx`'s one dependency (`/auth/me`) is
already resolved by the parent `(app)/layout.tsx` before this nested layout mounts, and each leaf owns
its own `Suspense`/`HydrationBoundary`, so a shell-level boundary would have nothing left to guard —
the identical reasoning `HOME_SCREEN.md` and `SETTINGS_SCREEN.md` give for their own bare routes.

```tsx
// app/(app)/profile/page.tsx — Personal Info, the default section (Server Component)
import { getQueryClient } from "@/lib/api/query-client-server";
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getSessionUser } from "@/lib/auth/session-server";
import { profileKeys } from "@/lib/api/query-keys";
import { PersonalInfoForm } from "@/components/profile/personal-info-form";

export const dynamic = "force-dynamic";

export default async function ProfilePersonalInfoPage() {
  const session = await getSessionUser();
  const qc = getQueryClient();
  qc.setQueryData(profileKeys.me(), session.user); // seeded, not re-fetched — see layout.tsx

  return (
    <HydrationBoundary state={dehydrate(qc)}>
      <h1 className="text-display-sm mb-4 font-medium text-ink-12">Personal Info</h1>
      <PersonalInfoForm initial={session.user} />
    </HydrationBoundary>
  );
}
```

# Components Used

Every visual element on this screen is drawn from `COMPONENT_LIBRARY.md`'s existing catalogue or is one
of the Profile-scoped compositions `docs/frontend/PROFILE.md → Components Used` already names and files
under `components/profile/`. This section gives the concrete prop contracts and, for the compositions
that document names without showing source, the actual implementation.

| Component | Source | New in this document |
|---|---|---|
| `AvatarWithFallback`, `ThemeToggle`, `ToggleGroup`, `RadioGroup`, `Switch`, `Card`, `Input`/`Textarea`/`Select`, `Form`/`FormField`/`FormItem`/`FormLabel`/`FormControl`/`FormDescription`/`FormMessage`, `AlertDialog`, `Dialog`, `Sheet`, `Badge`, `Tabs`, `Tooltip`, `Skeleton`, `EmptyState`/`ErrorState`, `useApiToast`, `FormattedRelativeTime` | `COMPONENT_LIBRARY.md` / `DARK_MODE.md` / `THEMING.md` (existing) | No — reused verbatim, props unchanged |
| `ProfileRailHeader` | `components/profile/profile-rail-header.tsx` | **Yes** — full source below |
| `ProfileSectionRail` | `components/profile/profile-section-rail.tsx` | **Yes** — full source below; `PROFILE.md` names it, this document implements it |
| `PersonalInfoForm` | `components/profile/personal-info-form.tsx` | **Yes** — full source below (the save-button/pessimistic worked example) |
| `PreferencesControls` | `components/profile/preferences-controls.tsx` | **Yes** — full source below (the instant-apply/optimistic worked example) |
| `CopyableSecret` | `components/shared/copyable-secret.tsx` | **Yes** — full source below; the one new shared primitive `PROFILE.md → Components Used` introduces |
| `SessionList`, `MfaFactorList`, `CompanyMembershipList`, `PersonalTokenList`, `DataPrivacyPanel` | `components/profile/*.tsx` | **Yes** — prop contracts below (bodies reuse `Card`/`Badge`/`AlertDialog`/`Dialog` per `PROFILE.md → Layout & Regions`) |
| `ProfileSectionSkeleton` | `components/profile/profile-section-skeleton.tsx` | **Yes** — full source in `# States` |

None of the new components introduces a design token, an API shape, a route, or a permission key beyond
what `# Route & Access` and `# Data & State` already name — each is a documented composition of existing
primitives, per `COMPONENT_LIBRARY.md`'s founding discipline, exactly as `ACCOUNTING_SCREEN.md` and
`SETTINGS_SCREEN.md` both commit to for their own new pieces.

## `ProfileRailHeader`

```tsx
// components/profile/profile-rail-header.tsx
import { AvatarWithFallback } from "@/components/shared/avatar-with-fallback";
import type { SessionUser } from "@/types/auth";

export function ProfileRailHeader({
  user, activeCompanyRole,
}: { user: SessionUser; activeCompanyRole: string }) {
  const displayName = user.full_name_en || user.full_name_ar;
  return (
    <div className="flex items-center gap-3 border-b border-ink-4 pb-4 lg:flex-col lg:items-start">
      <AvatarWithFallback src={user.avatar_url} name={displayName} size="lg" />
      <div className="min-w-0">
        <p className="truncate font-medium text-ink-12">{displayName}</p>
        {/* the caller's role at the CURRENTLY ACTIVE company only — never a merged cross-membership list */}
        <p className="truncate text-caption text-ink-9">{activeCompanyRole}</p>
      </div>
    </div>
  );
}
```

## `ProfileSectionRail`

```tsx
// components/profile/profile-section-rail.tsx
"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ArrowUpRight } from "lucide-react";
import { cn } from "@/lib/utils";
import { useTranslations } from "@/lib/i18n/use-translations";

// Single source of truth for both the desktop vertical rail and the md-below horizontal strip.
const PROFILE_SECTIONS = [
  { key: "personal",      labelKey: "profile.section.personal",     href: "/profile" },
  { key: "preferences",   labelKey: "profile.section.preferences",  href: "/profile/preferences" },
  { key: "notifications", labelKey: "profile.section.notifications", href: "/notifications/preferences", external: true },
  { key: "security",      labelKey: "profile.section.security",     href: "/profile/security" },
  { key: "companies",     labelKey: "profile.section.companies",    href: "/profile/companies" },
  { key: "tokens",        labelKey: "profile.section.tokens",       href: "/profile/tokens" },
  { key: "privacy",       labelKey: "profile.section.privacy",      href: "/profile/privacy" },
] as const;

export function ProfileSectionRail({ className }: { className?: string }) {
  const pathname = usePathname();
  const t = useTranslations();

  // "personal" is active for the bare /profile segment only; every other item matches its own prefix.
  const isActive = (item: (typeof PROFILE_SECTIONS)[number]) =>
    item.external ? false : item.key === "personal" ? pathname === "/profile" : pathname.startsWith(item.href);

  return (
    <nav
      aria-label={t("profile.sectionRail.label")}
      className={cn(
        "flex gap-1 overflow-x-auto lg:flex-col lg:gap-0.5 lg:overflow-visible",
        className,
      )}
    >
      {PROFILE_SECTIONS.map((item) => {
        const active = isActive(item);
        return (
          <Link
            key={item.key}
            href={item.href}
            aria-current={active ? "page" : undefined}
            className={cn(
              "flex shrink-0 items-center justify-between gap-2 rounded-md border-s-2 px-3 py-2 text-body transition-colors",
              active
                ? "border-accent bg-accent-subtle/40 font-medium text-ink-12"
                : "border-transparent text-ink-9 hover:bg-ink-3 hover:text-ink-11",
            )}
          >
            {t(item.labelKey)}
            {item.external && <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-ink-8" aria-hidden />}
          </Link>
        );
      })}
    </nav>
  );
}
```

The **Notifications** item alone carries `external: true` and an `ArrowUpRight` glyph, navigating to
`/notifications/preferences` — `NOTIFICATIONS.md`'s canonical route — rather than a Profile-local
segment, because notification channel/category preferences are owned in full by that document and this
screen adds an entry point, never a second matrix (`PROFILE.md → Purpose` #1). Its active-state check is
hard-`false` because the user is never "on" that item within the Profile shell; clicking it leaves the
shell entirely.

## `PersonalInfoForm` — the save-button / pessimistic pattern

```tsx
// components/profile/personal-info-form.tsx
"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useQuery } from "@tanstack/react-query";
import { Form, FormField, FormItem, FormLabel, FormControl, FormDescription, FormMessage } from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Button } from "@/components/ui/button";
import { AvatarChangeDialog } from "@/components/profile/avatar-change-dialog";
import { EmailChangeNotice } from "@/components/profile/email-change-notice";
import { profileKeys } from "@/lib/api/query-keys";
import { fetchMe } from "@/lib/api/profile";
import { personalInfoSchema, type PersonalInfoInput } from "@/lib/validation/profile";
import { useUpdateMe } from "@/hooks/profile/use-update-me";
import { useApiToast } from "@/hooks/use-api-toast";
import { TIMEZONES } from "@/lib/i18n/timezones";
import type { SessionUser } from "@/types/auth";

export function PersonalInfoForm({ initial }: { initial: SessionUser }) {
  const { data: me } = useQuery({
    queryKey: profileKeys.me(),
    queryFn: fetchMe,
    initialData: initial,
    staleTime: 5 * 60_000, // rarely-changing personal reference data (PROFILE.md → Cache tuning)
  });
  const form = useForm<PersonalInfoInput>({
    resolver: zodResolver(personalInfoSchema),
    defaultValues: {
      full_name_en: me.full_name_en, full_name_ar: me.full_name_ar,
      phone: me.phone ?? "", timezone: me.timezone, locale: me.locale,
    },
  });
  const update = useUpdateMe();
  const toast = useApiToast();

  // Save-button, NOT per-field autosave: a half-completed name edit is not committed keystroke by
  // keystroke (PROFILE.md → Interactions & Flows). Pessimistic — no onMutate; the form only reflects
  // the server's own 2xx. `email` is never in this payload — see EmailChangeNotice below.
  async function onSubmit(values: PersonalInfoInput) {
    try {
      await update.mutateAsync(values);
      toast.success("profile.personal.saved");
    } catch (e) {
      toast.mapFieldErrors(e, form.setError); // 422 errors[] mapped back onto RHF fields
    }
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-5">
        <div className="flex items-center gap-4">
          <AvatarChangeDialog user={me} /> {/* POST /users/me/avatar; writes profileKeys.me() directly */}
        </div>
        <FormField control={form.control} name="full_name_en" render={({ field }) => (
          <FormItem><FormLabel>Full name (English)</FormLabel><FormControl><Input {...field} /></FormControl><FormMessage /></FormItem>
        )} />
        <FormField control={form.control} name="full_name_ar" render={({ field }) => (
          <FormItem><FormLabel>Full name (Arabic)</FormLabel><FormControl><Input dir="rtl" {...field} /></FormControl><FormMessage /></FormItem>
        )} />
        <EmailChangeNotice email={me.email} verified={Boolean(me.email_verified_at)} />
        <FormField control={form.control} name="phone" render={({ field }) => (
          <FormItem>
            <FormLabel>Phone</FormLabel>
            {/* pinned dir="ltr" so a Kuwaiti number reads left-to-right inside a fully Arabic form */}
            <FormControl><Input dir="ltr" inputMode="tel" {...field} /></FormControl>
            <FormMessage />
          </FormItem>
        )} />
        <FormField control={form.control} name="locale" render={({ field }) => (
          <FormItem>
            <FormLabel>Language</FormLabel>
            <FormControl>
              <RadioGroup value={field.value} onValueChange={field.onChange} className="flex gap-4">
                <label className="flex items-center gap-2"><RadioGroupItem value="en" /> English</label>
                <label className="flex items-center gap-2"><RadioGroupItem value="ar" /> العربية</label>
              </RadioGroup>
            </FormControl>
            <FormDescription>Changing language reloads the app in the new direction.</FormDescription>
          </FormItem>
        )} />
        <FormField control={form.control} name="timezone" render={({ field }) => (
          <FormItem>
            <FormLabel>Timezone</FormLabel>
            <FormControl>
              <Select value={field.value} onValueChange={field.onChange}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>{TIMEZONES.map((tz) => <SelectItem key={tz} value={tz}>{tz}</SelectItem>)}</SelectContent>
              </Select>
            </FormControl>
          </FormItem>
        )} />
        <div className="sticky bottom-0 flex justify-end border-t border-ink-4 bg-surface py-3">
          <Button type="submit" disabled={update.isPending}>Save changes</Button>
        </div>
      </form>
    </Form>
  );
}
```

`useUpdateMe` calls `PATCH /api/v1/users/me` and, on success, writes the returned user object straight
into `profileKeys.me()`'s cache so the Topbar `UserMenu`, the Rail Header, and every other place an
avatar/name renders in the same session update from one seeded value (`PROFILE.md → Interactions &
Flows`). Changing `locale` is the one field that additionally triggers `router.refresh()` (it drives
`dir`, the font stack, and every `Intl.*` call across the shell) — the mutation's own `onSuccess` branches
on `variables.locale !== me.locale` to fire the refresh, never a client-side-only CSS flip.

`EmailChangeNotice` never edits `email` on the profile `PATCH`: submitting a new address is a separate
`POST /api/v1/users/me/email-change` that sends a verification link to the **new** address and shows a
"Verification sent — check your inbox" banner, since the change is not real until the link is followed
(`PROFILE.md → Data & State`, `ERD.md → users → Update Rules`). This document renders that as a distinct
inline control beside the read-only current email, never a live-editable field.

## `PreferencesControls` — the instant-apply / optimistic pattern

```tsx
// components/profile/preferences-controls.tsx
"use client";

import { useQuery } from "@tanstack/react-query";
import { ThemeToggle } from "@/components/settings/theme-toggle"; // existing (DARK_MODE.md)
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { profileKeys } from "@/lib/api/query-keys";
import { fetchPreferences } from "@/lib/api/profile";
import { useUpdatePreference } from "@/hooks/profile/use-update-preference";
import { applyDensity, applyTextScale, applyReducedMotion } from "@/lib/theming/apply"; // THEMING.md

export function PreferencesControls() {
  const { data: prefs } = useQuery({
    queryKey: profileKeys.preferences(),
    queryFn: fetchPreferences,
    staleTime: 5 * 60_000, // no refetchOnWindowFocus (PROFILE.md → Cache tuning)
  });
  const update = useUpdatePreference(); // optimistic, row-scoped rollback on error (see below)

  // Every control applies instantly, client-side, with NO loading state on the control itself
  // (DARK_MODE.md → Toggle UX → Interaction rules, extended to the three siblings). The background
  // PATCH persists; a network failure toasts quietly and leaves the client-applied value in place.
  return (
    <div className="space-y-6">
      <Row label="Theme">
        <ThemeToggle value={prefs?.theme} onChange={(v) => update.mutate({ theme: v })} />
      </Row>
      <Row label="Density">
        <ToggleGroup type="single" value={prefs?.density} onValueChange={(v) => { if (!v) return; applyDensity(v); update.mutate({ density: v }); }}>
          <ToggleGroupItem value="comfortable">Comfortable</ToggleGroupItem>
          <ToggleGroupItem value="compact">Compact</ToggleGroupItem>
        </ToggleGroup>
      </Row>
      <Row label="Text size">
        <ToggleGroup type="single" value={prefs?.text_scale} onValueChange={(v) => { if (!v) return; applyTextScale(v); update.mutate({ text_scale: v }); }}>
          <ToggleGroupItem value="small">Small</ToggleGroupItem>
          <ToggleGroupItem value="default">Default</ToggleGroupItem>
          <ToggleGroupItem value="large">Large</ToggleGroupItem>
        </ToggleGroup>
      </Row>
      <Row label="Reduced motion">
        <RadioGroup
          value={prefs?.reduced_motion_override ?? "system"}
          onValueChange={(v) => { applyReducedMotion(v); update.mutate({ reduced_motion_override: v === "system" ? null : v }); }}
          className="flex gap-4"
        >
          <label className="flex items-center gap-2"><RadioGroupItem value="system" /> Follow system</label>
          <label className="flex items-center gap-2"><RadioGroupItem value="always_on" /> Always on</label>
          <label className="flex items-center gap-2"><RadioGroupItem value="always_off" /> Always off</label>
        </RadioGroup>
      </Row>
    </div>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-6 border-b border-ink-4 pb-4 last:border-0">
      <span className="text-body text-ink-11">{label}</span>
      {children}
    </div>
  );
}
```

## `CopyableSecret` — the one new shared primitive

```tsx
// components/shared/copyable-secret.tsx
"use client";

import { useState } from "react";
import { Copy, Check, TriangleAlert } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { useTranslations } from "@/lib/i18n/use-translations";

// The shown-once plaintext display shared by a freshly created personal API token and freshly
// regenerated MFA backup codes — both of which AUTHENTICATION_API.md returns exactly once. It
// introduces no new visual language: Input (readOnly, monospace) + Button + an explicit warning.
export function CopyableSecret({ value, label }: { value: string; label: string }) {
  const t = useTranslations();
  const [copied, setCopied] = useState(false);

  async function copy() {
    await navigator.clipboard.writeText(value);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <div className="space-y-2">
      <p className="flex items-center gap-1.5 text-caption text-warning">
        <TriangleAlert className="h-3.5 w-3.5" aria-hidden /> {t("shared.secret.shownOnce")}
      </p>
      <div className="flex items-center gap-2">
        <Input aria-label={label} value={value} readOnly className="font-mono text-caption" />
        <Button type="button" variant="outline" size="icon" onClick={copy} aria-label={t("shared.secret.copy")}>
          {copied ? <Check className="h-4 w-4" aria-hidden /> : <Copy className="h-4 w-4" aria-hidden />}
        </Button>
      </div>
    </div>
  );
}
```

## Section list/panel prop contracts (bodies reuse existing primitives)

| Component | Key props | Notes |
|---|---|---|
| `SessionList` | `sessions: Session[]`; `onRevoke(id)`; `onRevokeAllOthers()` | The caller's own current session renders a static "This device" `Badge` and **no** revoke control; every other row renders "Sign out" (`DELETE /auth/sessions/{id}`, optimistic removal). "Sign out of other devices" (`DELETE /auth/sessions`, no id) always opens a confirming `AlertDialog` |
| `MfaFactorList` | `factors: MfaFactor[]`; `mandatory: boolean`; `onEnroll(type)`; `onRemove(id)` | "Remove" on the last verified factor is disabled with a `Tooltip` when `mandatory`; enrollment drives `POST /mfa/{type}/enroll` → verify, revealing `backup_codes` once via `CopyableSecret` |
| `CompanyMembershipList` | `companies: Membership[]`; `activeCompanyId`; `soleOwnerOf: string[]`; `onSwitch(id)`; `onLeave(id)` | Active row: no "Switch", no "Leave" if sole Owner. Reads `companies` off the already-fetched `/auth/me` (`PROFILE.md → Data & State`), never a second request |
| `PersonalTokenList` | `tokens: PersonalToken[]`; `canManageCompanyKeys: boolean`; `onCreate()`; `onRevoke(id)` | New token's `plaintext_key` renders once via `CopyableSecret`; "Manage all company keys" out-link renders only when `canManageCompanyKeys` (i.e. `auth.apikey.read` at the active company) |
| `DataPrivacyPanel` | `onRequestExport()`; `exportStatus`; `onEraseAiMemory()`; `onRequestDeletion()` | "Forget what AI has learned" loops `POST /ai/memory/erase-all` per company membership; "Delete my account" requires a fresh MFA step-up before `POST /users/me/erasure-request` is reachable, and returns `pending_review`, never an instant delete |

# Data & State

## Endpoints this screen calls

All are the caller's own, `user_id`-scoped or global — **none** carries `X-Company-Id` for its own data
(`PROFILE.md → Route & Access`). Reproduced from `PROFILE.md → Data & State` in condensed form, showing
only the calls this screen's own sections issue on first paint or from a control they render:

| Purpose | Endpoint | First-paint or on-demand |
|---|---|---|
| Read personal profile | `GET /api/v1/users/me` | First paint (seeded from `SessionProvider`) |
| Update personal profile | `PATCH /api/v1/users/me` (`full_name_en`, `full_name_ar`, `phone`, `locale`, `timezone`) | Personal Info save |
| Request email change | `POST /api/v1/users/me/email-change` | Email-change control |
| Upload / remove avatar | `POST` / `DELETE /api/v1/users/me/avatar` | Avatar dialog |
| Read / update preferences | `GET` / `PATCH /api/v1/users/me/preferences` | Preferences section (changed key(s) only) |
| Change password | `POST /api/v1/auth/password/change` | Change Password form (revokes every session — see Interactions) |
| List / revoke sessions | `GET /api/v1/auth/sessions`, `DELETE /api/v1/auth/sessions/{id}`, `DELETE /api/v1/auth/sessions` | Security → Sessions |
| MFA enroll/verify/remove/backup-codes | `POST /api/v1/auth/mfa/*`, `DELETE /api/v1/auth/mfa/{factor_id}` | Security → MFA |
| List / create / revoke my tokens | `GET /api/v1/auth/api-keys?filter[created_by]=me`, `POST`/`DELETE /api/v1/auth/api-keys/{id}` | Personal API Tokens |
| Switch / leave company | `POST /api/v1/auth/switch-company`, `DELETE /api/v1/users/me/companies/{company_id}` | Company Memberships |
| Request / poll data export | `POST` / `GET /api/v1/users/me/data-export/{export_id}` | Data & Privacy |
| Erase AI memory | `POST /api/v1/ai/memory/erase-all` (per membership) | Data & Privacy |
| Request account deletion | `POST /api/v1/users/me/erasure-request` (after MFA step-up) | Data & Privacy |

Several of these are `PROFILE.md`'s own explicitly-flagged extensions of an existing contract
(`password/change` as the named authenticated counterpart to the logged-out reset pair;
`filter[created_by]=me`; `DELETE /users/me/companies/{id}`; `users/me/data-export`;
`users/me/erasure-request`) — this document consumes them exactly as named there and introduces none of
its own.

## Worked request/response examples

`PATCH /api/v1/users/me` (Personal Info save), reproduced from `PROFILE.md → Data & State` for an
engineer wiring `useUpdateMe`:

```json
// Request
{ "full_name_en": "Sara Al-Kandari", "full_name_ar": "سارة الكندري", "phone": "+96550112233", "timezone": "Asia/Kuwait", "locale": "ar" }
```
```json
// 200 OK
{
  "success": true,
  "data": {
    "id": "usr_9f21c3", "full_name_en": "Sara Al-Kandari", "full_name_ar": "سارة الكندري",
    "email": "sara.alkandari@qayd-demo.com", "email_verified_at": "2026-06-01T08:12:00Z",
    "phone": "+96550112233", "phone_verified_at": null,
    "avatar_url": "https://cdn.qayd.app/avatars/usr_9f21c3.webp",
    "locale": "ar", "timezone": "Asia/Kuwait", "mfa_enabled": true, "created_at": "2025-11-02T06:40:00Z"
  },
  "message": "Profile updated.", "errors": [], "meta": { "pagination": null },
  "request_id": "6d2a9c1b-4f3e-4b8a-9e1c-6b4a2f8d9c22", "timestamp": "2026-07-16T09:05:00Z"
}
```

```json
// 422 Unprocessable Entity — mapped by useApiToast.mapFieldErrors onto the Personal Info form's fields
{
  "success": false, "data": null, "message": "The given data was invalid.",
  "errors": [
    { "field": "full_name_ar", "code": "NAME_REQUIRED", "message": "The Arabic name field is required." },
    { "field": "phone", "code": "PHONE_INVALID", "message": "Enter a valid phone number in E.164 format." }
  ],
  "meta": { "pagination": null }, "request_id": "3a71c9d2-88fd-4e2b-9d4a-6c112a55e6f4",
  "timestamp": "2026-07-16T09:05:41Z"
}
```

`PATCH /api/v1/users/me/preferences` toggling density only (the returned row carries the full
preference set, per `PROFILE.md`):

```json
// Request
{ "density": "compact" }
```
```json
// 200 OK
{
  "success": true,
  "data": {
    "theme": "system", "density": "compact", "text_scale": "default", "reduced_motion_override": null,
    "sidebar_collapsed": false, "ai_rail_open": true, "table_density": {},
    "dashboard_layout": null, "calendar_default_view": "week"
  },
  "message": "Preferences updated.", "errors": [], "meta": { "pagination": null },
  "request_id": "2f8a1c9e-3b7d-4a3c-9e1b-8a2c9f4d1e7b", "timestamp": "2026-07-16T09:07:12Z"
}
```

`POST /api/v1/users/me/data-export`:

```json
{
  "success": true,
  "data": { "export_id": "exp_7a1c9e", "status": "queued", "requested_scope": ["all_companies"] },
  "message": "We're preparing your export. We'll email you when it's ready.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "1a9c4e2b-3f7d-4b8a-9e2c-4a1b9f7d2c8e", "timestamp": "2026-07-16T09:11:00Z"
}
```

## Query keys

```ts
// lib/api/query-keys.ts (Profile factories — cited verbatim from PROFILE.md → Data & State → Query keys)
export const profileKeys = {
  me: () => ["users", "me"] as const,
  preferences: () => ["users", "me", "preferences"] as const,
  sessions: () => ["auth", "sessions"] as const,
  mfaFactors: () => ["auth", "mfa", "factors"] as const,
  myTokens: () => ["auth", "api-keys", "mine"] as const,
  dataExport: (exportId: string) => ["users", "me", "data-export", exportId] as const,
};
```

`profileKeys.me()` is deliberately a **separate** cache entry from `SessionProvider`'s own `/auth/me`
bootstrap query — the two overlap in content but not in shape or refresh cadence (`/auth/me` also carries
`active_company`/`companies`/`session` and is refetched on every company switch; `profileKeys.me()` never
is, since the caller's own name and avatar do not change when they switch companies). `page.tsx` and
`layout.tsx` above seed `profileKeys.me()`'s initial value from the already-resolved session `user`
object, so navigation from elsewhere in the app issues no duplicate network call.

## Cache tuning

| Resource | `staleTime` | Rationale |
|---|---|---|
| `profileKeys.me()` | 5 min | Changes only via the caller's own edit in this same tab (which writes the cache directly); the ceiling is a stale-multi-tab safety net |
| `profileKeys.preferences()` | 5 min, no `refetchOnWindowFocus` | User-driven, rarely changed by anything but the user's own action here |
| `profileKeys.sessions()` | `0`, refetched on Security-section mount | A stale "This device" list is actively misleading on a security screen |
| `profileKeys.mfaFactors()` | `0`, refetched after every enroll/remove | A security-state list should never show yesterday's factor set |
| `profileKeys.myTokens()` | 30 s | The platform's general "transactional list" tier; a just-revoked token disappears via the mutation's own optimistic update regardless |
| `companies` (off `/auth/me`, no dedicated key) | Governed by `SessionProvider`'s bootstrap lifetime | This screen never independently fetches or caches it |

## Mutation strategy — optimistic vs. pessimistic

Applied concretely to this screen's own mutations, following the same "reversible, non-financial actions
are optimistic; anything consequential or session-invalidating is pessimistic/confirm-then-commit"
discipline `ACCOUNTING_SCREEN.md → Data & State` and `SETTINGS_SCREEN.md → Interactions & Flows` both use
— noting that Profile has no *financial* mutation at all, so "consequential" here means "irreversible or
session-affecting" rather than "touches the ledger":

| Mutation | Strategy | Rationale |
|---|---|---|
| `useUpdateMe` (name/phone/timezone) | **Pessimistic** (save button; no `onMutate`) | A half-typed name is not committed keystroke by keystroke; the form reflects only the server's 2xx |
| `useUpdateMe` (locale change) | **Pessimistic**, then `router.refresh()` | `locale` drives `dir`/fonts/`Intl.*` shell-wide — far more than one component's CSS variable |
| `useUpdatePreference` (theme/density/text-size/reduced-motion) | **Optimistic**, instant client apply, row-scoped rollback on error | Reversible, personal, zero financial weight; a lost sync is never worth interrupting the user |
| `useUploadAvatar` | **Optimistic** (local file preview) until the server `avatar_url` is confirmed reachable | A failed upload never blanks the existing photo; falls back to initials, retries once (`PROFILE.md → Edge Cases` #5) |
| `useRevokeSession` (one, not current) | **Optimistic** removal from the list | Reversible only in the sense that the user can re-authenticate that device; low-stakes to show gone immediately |
| `useRevokeAllOtherSessions`, `useChangePassword` | **Confirm-then-commit** (`AlertDialog`), then immediate | Session-invalidating from every other device's point of view; the confirming dialog is the gate |
| `useRemoveMfaFactor` | **Confirm-then-commit** (`AlertDialog`); disabled if last verified + mandatory | The server's `422 LAST_MFA_FACTOR_REQUIRED` is the authority behind the client disable |
| `useRevokePersonalToken` | **Confirm-then-commit** (`AlertDialog`), then optimistic list removal | An automation depending on the key fails immediately and irrecoverably |
| `useLeaveCompany` | **Confirm-then-commit** (`AlertDialog` naming the company); disabled if sole Owner | Re-joining requires a new invitation; the server's `SOLE_OWNER_CANNOT_LEAVE`/`CANNOT_LEAVE_ACTIVE_COMPANY` are the authority |
| `useRequestAccountDeletion` | **Step-up + confirm-then-commit** (MFA re-prompt, then `AlertDialog`) | The single most irreversible action on the screen; returns `pending_review`, never an instant delete |

```ts
// hooks/profile/use-update-preference.ts — the optimistic, row-scoped pattern
export function useUpdatePreference() {
  const qc = useQueryClient();
  const toast = useApiToast();
  return useMutation({
    mutationFn: (patch: Partial<Preferences>) => api.patch("/users/me/preferences", patch),
    onMutate: async (patch) => {
      await qc.cancelQueries({ queryKey: profileKeys.preferences() });
      const previous = qc.getQueryData<Preferences>(profileKeys.preferences());
      qc.setQueryData<Preferences>(profileKeys.preferences(), (old) => old ? { ...old, ...patch } : old);
      return { previous };
    },
    // Row-scoped rollback: restore ONLY the previously cached value; the client-applied CSS variable
    // (density/text-scale/theme) stays until the user's next visit reconciles it (PROFILE.md).
    onError: (_e, _patch, ctx) => { if (ctx?.previous) qc.setQueryData(profileKeys.preferences(), ctx.previous); toast.error("profile.preferences.syncFailed"); },
    onSettled: () => qc.invalidateQueries({ queryKey: profileKeys.preferences() }),
  });
}
```

## Realtime

**None.** Profile subscribes to no Reverb channel — a deliberate omission `PROFILE.md → Data & State →
Realtime` argues in full: every platform channel is `private-company.{id}.<feature>`-scoped, and nothing
on this screen is company-scoped. A session revoked elsewhere, a password changed on another device, or a
token created by a script all take effect at the API layer regardless of what this screen has cached
(`AUTHENTICATION_API.md → Revocation`'s per-request checks, not a push), and the one place staleness would
be user-visible — the Active Sessions list — is refetched on every Security-section mount rather than kept
live. A future "signed in from a new device" alert would arrive as an ordinary `notifications` row over
the existing `private-company.{id}.notifications.{user_id}` channel and be handled by `NOTIFICATIONS.md`'s
bell, never by a second channel this screen would own.

# Interactions & Flows

Numbered as a concrete implementation sequence; the narrative form of each flow is `PROFILE.md →
Interactions & Flows`'s own territory and is not repeated here.

1. **Opening the screen.** `/profile` renders Personal Info directly — no redirect hop, no "choosing a
   section" flash. The Rail Header and Main Column resolve from the same `Promise.all`-prefetched,
   session-seeded data, so first paint already has the caller's real name and avatar.
2. **Navigating sections.** Every Section Rail row is a real `<Link>` navigation, never client-side tab
   state, so each of the six sections is independently bookmarkable and survives a hard refresh; the
   Notifications item alone leaves the shell for `/notifications/preferences`.
3. **Editing Personal Info.** One `PATCH /users/me` on the "Save changes" button — not per-field autosave.
   A `422` maps to field errors via `form.setError`; a `5xx` toasts and keeps every typed value intact.
4. **Changing the avatar.** "Change photo" opens a `Dialog` (file picker + square crop) → `POST
   /users/me/avatar`; success writes `avatar_url` into `profileKeys.me()` directly (no refetch) and
   propagates to the Topbar `UserMenu` and Rail Header in the same session. A failed upload never blanks
   the existing photo.
5. **Switching language.** Changing Language calls `PATCH /users/me` with the new `locale` and triggers
   `router.refresh()` (not an instant client flip), because `locale` drives `dir`, the font stack, and
   every `Intl.*` call shell-wide; the Section Rail's own labels switch in the same reload.
6. **Preferences.** Theme, density, text size, and reduced motion apply instantly client-side and persist
   in the background with no loading state on the control; a failed `PATCH` toasts quietly and leaves the
   applied value in place.
7. **Changing password.** Submitting the form opens an `AlertDialog` stating plainly "You'll be signed out
   of every device, including this one," per `AUTHENTICATION_API.md → Revocation`; confirming calls `POST
   /auth/password/change` and, since the server has already revoked the session, redirects straight to
   `/login` with "Password changed. Please sign in again." — never a jarring, unexplained logout.
8. **Enrolling / removing an MFA factor.** "Add method" opens a `Sheet`/`Dialog` and drives the exact
   `POST /mfa/*/enroll → verify` sequence, revealing `backup_codes` once via `CopyableSecret`. "Remove"
   opens a confirming `AlertDialog`; on the last verified factor for a mandatory-MFA role it is disabled
   with a `Tooltip` rather than allowed to fail server-side after the fact.
9. **Managing sessions.** Every row except the caller's own current session renders "Sign out" (`DELETE
   /auth/sessions/{id}`, optimistic); the current session shows a static "This device" `Badge` and no
   revoke action. "Sign out of other devices" (`DELETE /auth/sessions`, no id) always opens a confirming
   `AlertDialog`.
10. **Switching / leaving a company.** "Switch" reuses the identical `POST /auth/switch-company` mutation
    (`queryClient.clear()` + `router.refresh()`) the Topbar `CompanySwitcher` uses — a second entry point,
    not a reimplementation. "Leave company" opens a confirming `AlertDialog`; a sole Owner sees it disabled
    with a "Transfer ownership before leaving" `Tooltip`.
11. **Personal API tokens.** "Create token" opens the identical name/environment/abilities form
    `AUTHENTICATION_API.md → API Keys` specifies; the resulting `plaintext_key` renders once via
    `CopyableSecret`. Revoking opens a confirming `AlertDialog`.
12. **Data export vs. AI-memory erasure vs. account deletion.** "Export my data" summarizes what is
    included, then `POST /users/me/data-export` and polls to a signed R2 `download_url`. "Forget what AI
    has learned about me" loops `POST /ai/memory/erase-all` per company membership, instantly, no approval
    chain. "Delete my account" is visually and procedurally distinct, requires a fresh MFA step-up before
    `POST /users/me/erasure-request` is even reachable, and returns `pending_review` — a reviewed
    workflow, never a self-serve delete button.

# AI Integration

Profile is, by a wide margin, the smallest AI surface in the platform — `PROFILE.md → AI Integration`
frames it as smaller even than Dashboard's deliberately minimal one, and this document's implementation
adds no AI presence the prose does not have. Concretely, at the component level:

- **No AI widget is imported anywhere in `components/profile/`.** There is no `ConfidenceBadge`,
  `AiCardShell`, `AIProposalPanel`, `ReasoningDisclosure`, or `ConfidenceMeter` in any file this document
  specifies, because there is no agent judgment about a person's own name, theme, or password to surface.
  Every value rendered here is a plain CRUD read; every mutation is a plain CRUD write.
- **The AI service account never appears *inside* the screen either** — not in Active Sessions (it
  authenticates with a service JWT under a synthetic `ai_service` role, not a human `sessions` row) and
  not in Company Memberships (its scope is a synthetic role grant, never a `company_users` seat), so
  `SessionList` and `CompanyMembershipList` render none by construction, not by filtering one out
  (`PROFILE.md → AI Integration`).
- **The one genuine AI intersection is Data & Privacy's "Forget what AI has learned about me."** That
  action calls `POST /api/v1/ai/memory/erase-all` per company membership, clearing `USER_MEMORY.md`'s
  personalization graph — how the caller likes the Copilot to phrase things, which report they open first,
  their personal comfort threshold inside an approval chain — which is personal data about an identifiable
  individual by that document's own framing, and therefore belongs in both this screen's export bundle and
  its own dedicated, self-service erasure action, distinct from and never merged into the heavier,
  reviewed account-deletion request. This is data governance, not an AI proposal, and renders as a plain
  confirming `AlertDialog`, never an approve/reject affordance.
- **"AI Alerts" as a notification category is configured at `/notifications/preferences`, not here** — the
  Notifications rail item deep-links there rather than exposing a second, competing channel matrix.

This section exists to state that absence as a deliberate, cross-referenced design decision rather than an
oversight a reviewer might otherwise flag, exactly as `PROFILE.md → AI Integration` and `NOTIFICATIONS.md
→ AI agents feeding this screen` both do for their own screens.

# States

| Region | Loading | Empty | Error |
|---|---|---|---|
| Personal Info form | `ProfileSectionSkeleton` (field-shaped: label height + input height per field), never a generic spinner | N/A — every field always has a value (at minimum the signup defaults) | Inline `ErrorState` with retry in place of the form; a failed **save** (not a failed load) keeps every typed value and surfaces the `422` per field via `setError`, never a full-form reset |
| Preferences controls | Skeleton pills matching each `ToggleGroup`'s final width | N/A — `user_preferences` always has a full row (defaulted server-side) | A failed background `PATCH` toasts and reverts only the one control that failed to persist, never the whole section |
| Security → Sessions | 3 skeleton rows | Never truly empty (the caller's own current session always exists) | Inline retry card; the Password and MFA cards above it stay fully usable |
| Security → MFA factors | 2–3 skeleton rows | A genuinely unenrolled account shows "No two-factor methods yet" with "Add method" prominent, never an illustration (`DESIGN_LANGUAGE.md`'s no-illustration stance) | Inline retry; enrollment stays reachable even if the factor list failed to load |
| Company Memberships | 2 skeleton rows | Never empty for an authenticated session | `EmptyState`-styled retry card; "Switch company" elsewhere still works from cached `/auth/me` |
| Personal API Tokens | 2 skeleton rows | "You haven't created a personal API token yet" + "Create token" primary + a one-line explainer | Inline retry; creation stays available even if the list failed |
| Data & Privacy → export status | Indeterminate progress while `queued`/`processing` | N/A | `status: "failed"` surfaces a "Try again" action, never a silently-stuck spinner |

```tsx
// components/profile/profile-section-skeleton.tsx
import { Skeleton } from "@/components/ui/skeleton";

export function ProfileSectionSkeleton({ fieldCount = 6 }: { fieldCount?: number }) {
  return (
    <div className="space-y-5" aria-hidden>
      <div className="flex items-center gap-4">
        <Skeleton className="h-14 w-14 shrink-0 rounded-full" />
        <Skeleton className="h-4 w-40 rounded" />
      </div>
      {Array.from({ length: fieldCount }).map((_, i) => (
        <div key={i} className="space-y-1.5">
          <Skeleton className="h-3 w-24 rounded" style={{ animationDelay: `${i * 40}ms` }} />
          <Skeleton className="h-9 w-full rounded-md" style={{ animationDelay: `${i * 40}ms` }} />
        </div>
      ))}
    </div>
  );
}
```

The staggered `animationDelay` produces the platform's standard 1.6s single-wave shimmer
(`DESIGN_LANGUAGE.md → Motion → Named patterns`), the identical mechanic `ACCOUNTING_SCREEN.md`'s
`TreeSkeleton` and `SETTINGS_SCREEN.md`'s form skeletons already establish. A route-level `error.tsx` from
the `(app)` shell remains the outermost net for a genuinely unexpected failure (the session itself
becoming invalid mid-render); per the platform's three-granularity error model it should essentially
never fire for an individual section's ordinary `4xx`/`5xx`, which are caught and handled inline.

# Responsive Behavior

Breakpoints are `RESPONSIVE_DESIGN.md`'s fixed scale — this screen introduces no breakpoint of its own —
refining `PROFILE.md → Responsive Behavior`'s coarser tier labels at implementation precision, exactly as
`ACCOUNTING_SCREEN.md`'s own Tablet/Mobile table refines `ACCOUNTING.md`'s ranges.

| Token | Width | This screen's behavior |
|---|---|---|
| `base` | <640px | Section Rail becomes a horizontal-scroll `Tabs` strip pinned under the Rail Header (avatar/name/active-role inline above it); every `Card`-grouped Security block stacks full-width; confirmation dialogs render as full-height bottom `Sheet`s (`RESPONSIVE_DESIGN.md → Pattern 4`) |
| `sm` | 640px | Still single column; the MFA "Add method" picker and avatar dialog become full-width sheets |
| `md` | 768px | Section Rail remains a horizontal strip (six items fit without scrolling in the common case); Personal Info's phone + timezone become a two-column grid |
| `lg` | 1024px | Section Rail becomes the persistent vertical `col-span-3` column; Main Column and rail render side by side |
| `xl` | 1280px | No structural change — Profile has no data grid dense enough to want extra columns; content stays capped at the comfortable `max-w-[1440px]` reading width |
| `2xl` / `3xl` | 1536 / 1920px | Unchanged from `xl` — extra width becomes margin, not columns; precisely the screen `RESPONSIVE_DESIGN.md`'s tier table anticipates needing nothing new at these widths |

Every button, `Switch`, and `ToggleGroupItem` holds the platform's 44×44px minimum touch target with an
8px gap (`RESPONSIVE_DESIGN.md → Touch Targets`), material here because Security's "Sign out" and "Remove"
are exactly the controls a mis-tap on has real consequences for. No swipe gesture is offered on any row
(unlike `NOTIFICATIONS.md`'s swipe-to-mark-read) — a session or MFA-factor row is rare and consequential
enough that an explicit tap on a labeled button is the only interaction, never a gesture an accidental
scroll could trigger.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md → RTL mirroring` and `LAYOUT_SYSTEM.md`'s RTL
contract, applied concretely to this screen's own regions; `PROFILE.md → RTL & Localization` states the
same rules and this section adds the component-level application.

- **Logical properties only.** `ProfileSectionRail`'s active-item indicator uses `border-s-2` (literal, in
  the source above), never `border-l-2`/`border-r-2`; the rail's own `col-span-12 lg:col-span-3` position
  relocates to the visual right under `dir="rtl"` by CSS Grid alone, with no `dir`-conditional class.
- **Phone numbers and every timestamp/relative-time render `dir="ltr"`.** Personal Info's phone `Input`
  carries an explicit `dir="ltr"` (in the source above); `SessionList`/`PersonalTokenList`'s "last active"
  / "last used" strings pin `dir="ltr"` via `FormattedRelativeTime` — a Kuwaiti number (`+965 5011 2233`)
  reads left-to-right inside a fully Arabic form, exactly as an amount does inside a fully Arabic invoice.
- **Bilingual name fields show both simultaneously.** `full_name_en` and `full_name_ar` are always
  rendered together, never one behind a language toggle; the Arabic field carries `dir="rtl"`.
- **A company's `name_ar` renders in the Company Memberships list when the caller's own `locale` is
  Arabic**, independent of what language that company keeps its books in — `companies.name_en`/`name_ar`
  are display fields the *viewer's* locale picks between (unlike a notification's frozen `title`/`body`,
  `NOTIFICATIONS.md → RTL & Localization`); switching Language on this very screen changes which the row
  shows as a side effect of the same `router.refresh()`.
- **The Notifications item's `ArrowUpRight` glyph does not mirror** — it is a fixed reading-direction
  external-link marker per `ICONOGRAPHY.md`, matching the identical rule `SETTINGS_SCREEN.md` states for
  the same glyph on its own external Notifications row.

| Context | English | Arabic |
|---|---|---|
| Section: Personal Info | Personal Info | المعلومات الشخصية |
| Section: Preferences | Preferences | التفضيلات |
| Section: Security | Security | الأمان |
| Section: Companies | Companies | الشركات |
| Section: API Tokens | API Tokens | رموز الوصول |
| Section: Data & Privacy | Data & Privacy | البيانات والخصوصية |
| Password-change warning | You'll be signed out of every device, including this one. | سيتم تسجيل خروجك من جميع الأجهزة، بما فيها هذا الجهاز. |
| Last-MFA-factor tooltip | Add another method before removing this one. | أضف طريقة أخرى قبل إزالة هذه الطريقة. |
| Sole-owner tooltip | Transfer ownership before leaving. | انقل الملكية قبل مغادرة الشركة. |
| Shown-once secret warning | Copy this now — it won't be shown again. | انسخه الآن — لن يظهر مرة أخرى. |
| Account-deletion confirm | This cannot be undone once processed. | لا يمكن التراجع عن هذا بعد معالجته. |

Arabic copy is authored directly by a fluent professional-register writer, never machine-translated,
matching `DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief and reusing the exact
account/security terminology `PROFILE.md` and `AUTHENTICATION_API.md` already established.

# Dark Mode

This screen introduces no new color, elevation, or radius token — every surface resolves through the
platform's `.dark` class remap defined once in `DESIGN_LANGUAGE.md`, which is exactly what lets Profile,
like `DARK_MODE.md`'s own worked "Appearance settings screen" example, require zero `dark:`-prefixed
classes of its own. The concrete pairs this screen's components read most often:

| Token | Light | Dark | Used on this screen for |
|---|---|---|---|
| `--qayd-ink-1` / `--qayd-ink-12` | `#FAFAF9` / `#15130E` | `#14130F` / `#F8F6EF` | Canvas / primary text — `bg-ink-1 text-ink-12` needs no per-theme branch (dark-mode `ink-1` is the darkest surface, `ink-12` the lightest text) |
| `--qayd-ink-3` / `--qayd-ink-4` | `#EBE9E6` / `#E0DEDA` | `#24211A` / `#2D2921` | Section Rail item hover; section-divider borders; the sticky Save Bar's top border |
| `--qayd-accent` | `#9C7A34` | `#D9B96C` | Section Rail active-item `border-s` + `bg-accent-subtle/40`; the one primary-action color (Save changes) |
| `--qayd-accent-subtle` | `#EADFBF` | `#3A2E14` | Section Rail active-item fill |
| `--qayd-warning` | `#B45309` | `#F5A855` | `CopyableSecret`'s "shown once" warning line |
| `--qayd-positive` / `--qayd-negative` | `#17794A` / `#B4232E` | `#4ADE94` / `#F26B74` | `Badge` tones — "Active" membership, verified email checkmark, "Suspended"/unverified |

`CopyableSecret`'s monospace field uses the platform's existing `code`-role surface (a subtly recessed
`ink-3`/dark-`ink-3`-equivalent background per `DESIGN_LANGUAGE.md → Elevation & Surfaces`), never a
bespoke "secret reveal" color in either theme. Elevation gets *lighter*, not darker, toward the viewer in
dark mode — a `Card`-grouped Security block sits a step lighter than the page canvas behind it in both
themes. Every Storybook story for `ProfileSectionRail`, `PersonalInfoForm`, `PreferencesControls`,
`CopyableSecret`, and `ProfileSectionSkeleton` ships the platform's standard four-way parameter matrix
(`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this route's Playwright suite captures the same
four-way screenshot set at the route level.

# Accessibility

Baseline WCAG 2.2 AA, identically in both languages and both themes, with particular weight on forms and
on the several irreversible confirmations this screen uniquely concentrates in one place — restating
`PROFILE.md → Accessibility`'s rules as the concrete implementation checklist an engineer verifies
against.

- **Landmarks and headings.** Each section renders inside the shared `<main>` with one visible `<h1>`
  matching the active Section Rail item; the rail itself is a `<nav aria-label="Profile sections">` with
  the current route's item carrying `aria-current="page"`.
- **Forms wired exactly per `ACCESSIBILITY.md → Forms Accessibility`.** Every field on Personal Info and
  Change Password uses a real `<Label>` (never placeholder-as-label), `FormControl`'s automatic
  `aria-invalid`/`aria-describedby`, and Zod messages resolved through `t()` so an Arabic-locale validation
  failure is fully Arabic and RTL-aligned; a failed `PATCH` maps its `422 errors[]` back onto the same RHF
  fields via `form.setError`.
- **New-password fields never block paste; one-time codes use `autoComplete="one-time-code"`** — both
  restated from `ACCESSIBILITY.md → Accessible authentication` (WCAG 2.2 SC 3.3.8); blocking paste breaks
  password managers, and an OS-autofilled code beats manual transcription, especially on a security screen.
- **Every irreversible action is a real, focus-managed `AlertDialog`**, never a bare `confirm()` or inline
  toggle — password change, MFA-factor removal, leaving a company, revoking a token, and the
  account-erasure request all trap focus on open, return it to the triggering control on close/cancel, and
  require an explicit labeled confirm button rather than an "Enter to confirm" default a keyboard user
  could trigger by accident.
- **The data-export status is an `aria-live="polite"` region, never assertive** — a background job
  finishing while a screen-reader user reads elsewhere on the page announces politely, matching the
  platform's "auto-detect, never alarming" live-region model, reused verbatim from `NOTIFICATIONS.md`.
- **Color is never the only channel.** The active company's marker is a filled dot **plus** an "Active"
  `Badge` with real text; an unverified email shows a literal "Not verified — resend" link, not merely a
  muted color; `CopyableSecret`'s warning pairs the `TriangleAlert` icon with real warning text.
- **Account-integrity disables explain themselves.** A last-MFA-factor "Remove" and a sole-Owner "Leave"
  render disabled with `aria-describedby` pointing at their `Tooltip`, distinct from the platform's
  separate rule that a *permission*-absent action is omitted — this screen has essentially no permission-
  absent action (the sole exception being the "Manage all company keys" out-link, which is omitted, not
  disabled), only these two account-integrity disables which are shown-and-explained.
- **Keyboard path.** `Tab` flows Rail Header → Section Rail (one stop, `Arrow` keys roving within it via
  `Tabs`' native roving-tabindex) → the active section's fields in visual order → its primary action; `G`
  then `P` reaches the screen from anywhere; no additional chord is introduced inside.

# Performance

- **Route-segmented code splitting is free, not an added optimization.** Because each section is its own
  nested route rather than a client-side tab switch inside one `page.tsx`, Next.js already splits
  Security's MFA/WebAuthn-adjacent bundle away from Preferences' `ThemeToggle`/`ToggleGroup` bundle and
  from Data & Privacy's export-polling logic — a user who only ever opens Personal Info never downloads the
  other five sections' JavaScript.
- **Parallel, not waterfalled, first paint.** `layout.tsx`'s `Promise.all` (in the source above) resolves
  the Rail Header's data (seeded from `SessionProvider`) and the active leaf's own query concurrently — a
  sequential `await` would add a round trip's latency to every navigation for no correctness benefit.
- **Avatar images are never unoptimized.** `AvatarWithFallback` renders through `next/image` with an
  explicit `sizes` matching its actual dimensions per tier, so a desktop-resolution upload is never
  downscaled client-side on a phone.
- **No search, virtualization, or pagination machinery.** Every list here — sessions, MFA factors,
  memberships, tokens — is realistically single-digit-to-low-double-digit rows for any human account; none
  uses `DataTable`, cursor pagination, or `TanStack Virtual`, because that machinery for a five-row list
  would be complexity spent on a problem this screen does not have.
- **No realtime cost.** With no Reverb subscription (`# Data & State`), this screen pays no WebSocket
  keepalive of its own; the security-state lists' freshness is achieved by a targeted refetch on section
  mount, not a persistent channel.
- **Web Vitals.** LCP is measured against the active section's primary content rendering (the Personal
  Info form, the Preferences controls), not the Rail shell; CLS stays near zero because every `Skeleton`
  in `# States` is sized to its final content's exact height rather than a generic placeholder block.

# Edge Cases

`PROFILE.md → Edge Cases` already covers the fourteen scenarios specific to the person-vs-company model
(company switch not refetching this screen, cross-tab password revocation, last-MFA-factor removal,
avatar CDN propagation lag, leaving every company, sole-membership leave, abandoned TOTP enrollment,
locale change closing an open dialog, `pending_review` staying functional, stale backup-code display,
narrowest-role access, and idle-tab membership staleness) — this document does not repeat them. The rows
below are this implementation layer's own additions: races and environment conditions that surface
specifically once `layout.tsx`, its section forms, and their mutation hooks are actually written.

| Edge case | Frontend behavior |
|---|---|
| `layout.tsx`'s `Promise.all` seed (`profileKeys.me()` from `SessionProvider`) is present, but the user hard-refreshes `/profile/security` directly (no elsewhere-in-app navigation to seed from) | The seed still comes from the parent `(app)/layout.tsx`'s own `/auth/me` resolution (already awaited before this nested layout mounts), so `profileKeys.me()` is never `undefined` on a cold direct hit; only `profileKeys.sessions()`/`.mfaFactors()` (the leaf's own `staleTime: 0` queries) actually round-trip on that load |
| A `useUpdatePreference` optimistic write and its background `PATCH` are still in flight when the user navigates to another section and back | The section re-mounts against `profileKeys.preferences()`'s already-optimistically-updated cache, so the applied value is shown immediately with no flash; `onSettled`'s invalidation reconciles against the server whenever the request lands, independent of which section is mounted |
| The avatar `Dialog` is submitting (`POST /users/me/avatar`) when the user closes it before the server responds | The upload continues to completion in the background; `onSuccess` still writes `avatar_url` into `profileKeys.me()`, so the new photo appears in the Rail Header and Topbar even though the dialog that triggered it is already closed — a closed dialog never cancels an in-flight avatar write |
| A locale-change `PATCH /users/me` succeeds but the subsequent `router.refresh()` is interrupted (navigation away mid-refresh) | The persisted `locale` is authoritative server-side regardless; the next full navigation resolves the shell in the new direction — an interrupted refresh degrades to "applies on next load," never a half-flipped `dir` |
| `useRevokeSession`'s optimistic removal fires for the caller's own current session because a stale list mislabeled `is_current` | Guarded structurally: `SessionList` renders no "Sign out" control on the row whose `is_current` is `true`, so the mutation is unreachable for the current session by construction; a `409`/`422` from the server on any residual race re-inserts the row with a toast rather than silently signing the user out of the tab they are using |
| The Change Password `AlertDialog` is confirmed, `POST /auth/password/change` succeeds, and the client attempts a follow-up cache write before the redirect to `/login` | No follow-up cache write is attempted — the mutation's `onSuccess` performs only the `/login` redirect, because the session is already revoked server-side and any subsequent `/api/v1` call from this tab would `401`; the screen never tries to keep the just-invalidated session alive |
| `CopyableSecret`'s clipboard write is blocked (insecure context, or the browser denies `navigator.clipboard`) | The "Copy" button's `onClick` catches the rejection and leaves the secret visible and selectable in its `readOnly` `Input` so the user can select-and-copy manually; the shown-once value is never hidden merely because the programmatic copy failed |
| A data-export poll (`GET /users/me/data-export/{id}`) is still running when the user leaves the Data & Privacy section | The poll is a section-scoped `useQuery` that unmounts with the section; the export job itself continues server-side, and the "we'll email you when it's ready" message (shown at request time) is what carries the user past a closed tab — the frontend never depends on a kept-open poll to deliver the result |

# End of Document
