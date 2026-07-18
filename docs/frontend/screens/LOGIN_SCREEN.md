# Login & Authentication Screens — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: LOGIN_SCREEN
---

# Purpose

This document specifies the family of screens a QAYD user sees before a session, a company context, or a
single ledger figure exists: `/login`, `/mfa`, `/forgot-password`, `/reset-password`, and `/select-company`.
Every other document in `docs/frontend/` describes something rendered *inside* the authenticated shell —
behind the Sidebar, the Topbar, and the `SessionProvider` that `FRONTEND_ARCHITECTURE.md`'s `(app)/layout.tsx`
establishes. This document describes the five routes that exist specifically because that shell cannot be
reached directly: a company's books are not a public surface, and the frontend's own founding rule —
"the frontend owns nothing" — applies here with unusual force, because the one thing this screen family
is entirely responsible for is correctly *establishing* the identity every other screen in the product
then trusts without re-checking.

This is the frontend counterpart to `docs/api/AUTHENTICATION_API.md`, in the same relationship
`DASHBOARD.md` has to `docs/api/REPORTING_TOOLS.md` or `JOURNAL_ENTRIES.md` has to the Accounting Engine's
posting rules: `AUTHENTICATION_API.md` owns the wire contract — the JWT claims, the Sanctum session cookie,
the refresh-token rotation-on-use, the MFA challenge lifecycle, the OAuth authorization-server and
OAuth-client roles, the exact envelope shape of every request and response. This document owns the pixels,
the interaction sequencing, the client-side state machine that carries a person from an empty email field to
a fully scoped, company-aware session inside the AI Command Center, and every loading/empty/error/RTL/dark
permutation along the way. Where the two disagree, `AUTHENTICATION_API.md` is authoritative for the contract
and this document is authoritative for how that contract is presented and sequenced on screen — identical to
the precedence rule `DASHBOARD.md` states for its own relationship to `FRONTEND_ARCHITECTURE.md`.

Three constraints inherited from `FRONTEND_ARCHITECTURE.md` bind this screen family exactly as they bind
every other screen in the product, applied here at their most literal:

1. **The frontend computes nothing.** A lockout countdown is the server's `Retry-After` header, not a
   client-side guess; a "your password is too common" hint is a same-shape mirror of the Zod schema the
   server also runs, re-validated unconditionally by the `422` Laravel can still return; whether an account
   is locked, whether a reset token is still valid, and whether a device may skip a second factor are every
   one of them answers the API gives, never inferences the client draws from what it has cached.
2. **AI is visible, labeled, and never silent — except here, where it is correctly and deliberately absent.**
   `# AI Integration` below explains why this is the one screen family in the product with zero AI-authored
   surface, and why that absence is itself a direct, careful application of the platform's AI rules rather
   than an oversight.
3. **RBAC is enforced by the API; the UI only reflects it.** Four of these five routes are intentionally
   `public` (no bearer token, no session) by the nature of what they do; the fifth, `/select-company`, is the
   first screen in the entire product where a permission-shaped check — "does this user hold a
   `company_users` row for the company they are about to enter" — actually matters, and it is enforced
   exactly the way every other screen enforces RBAC: the API's `403`, not a client-side guess.

The five routes, in the order a new sign-in most commonly visits them:

| Route | Purpose |
|---|---|
| `/login` | Email + password sign-in; may resolve directly to a session or hand off to `/mfa` |
| `/mfa` | Second-factor verification (TOTP, SMS OTP, backup code) continuing an in-flight login |
| `/forgot-password` | Requests a password-reset email without ever confirming or denying an account exists |
| `/reset-password` | Consumes a mailed reset token and sets a new password |
| `/select-company` | Lets a user who belongs to more than one company choose which one to enter, both immediately after login and later, on demand, from inside the app |

# Route & Access

All five routes live in the `(auth)` route group `FRONTEND_ARCHITECTURE.md` establishes: a route group that
changes nothing about the URL but gives this entire family its own root layout, with no `Sidebar`, no
`Topbar`, and a fraction of the JavaScript the `(app)` group ships, per Principle 2 ("server-first rendering,
client islands for interactivity"). None of the four earlier documents' access model — permission keys,
role tables, `Can`/`PermissionGate` — applies to `/login`, `/mfa`, `/forgot-password`, or `/reset-password`;
they are `public` in exactly the sense `AUTHENTICATION_API.md`'s endpoint inventory marks `POST /login`,
`POST /refresh`, `POST /password/forgot`, and `POST /password/reset` as `public`. `/select-company` is the
one exception, gated the same way `POST /switch-company` is gated in that same inventory: `self`, requiring
the caller to already hold a valid session and a `company_users` row for whichever company they attempt to
enter.

```
app/
└── (auth)/
    ├── layout.tsx                # AuthShell — split-panel frame, no sidebar, locale + theme controls only
    ├── login/
    │   └── page.tsx              # Server Component shell + <LoginForm/> (client)
    ├── mfa/
    │   └── page.tsx              # Renders only while an in-flight mfa challenge exists; see # Interactions & Flows
    ├── forgot-password/
    │   └── page.tsx              # Server Component shell + <ForgotPasswordForm/> (client)
    ├── reset-password/
    │   └── page.tsx              # Reads ?token=; Server Component shell + <ResetPasswordForm/> (client)
    └── select-company/
        └── page.tsx              # Server Component; reads the session's `companies` array server-side

app/api/auth/                     # Route Handlers — BFF concerns only, per FRONTEND_ARCHITECTURE.md
├── login/route.ts                # Existing — proxies POST /api/v1/auth/login, sets httpOnly cookies on success
├── mfa/
│   └── verify/route.ts           # New in this document — proxies POST /api/v1/auth/mfa/verify identically
├── password/
│   ├── forgot/route.ts           # New in this document — thin pass-through, sets no cookies
│   └── reset/route.ts            # New in this document — thin pass-through, sets no cookies
├── switch-company/route.ts       # Existing — reused verbatim by /select-company, see # Data & State
├── refresh/route.ts               # Existing — not called from this screen family directly
└── logout/route.ts                # Existing — not called from this screen family directly
```

`app/api/auth/mfa/verify/route.ts` and the two `password/*` handlers are the only additions this document
makes to `FRONTEND_ARCHITECTURE.md`'s existing Route Handler set, and each is a direct structural sibling of
`login/route.ts`: a Next.js Route Handler is the only frontend code that ever sees a raw
`access_token`/`refresh_token`, and it exists specifically so browser JavaScript never does. `mfa/verify`
mirrors `login`'s exact `setSessionCookies` step on success (per `FRONTEND_ARCHITECTURE.md → MFA challenge
flow`); the two `password/*` handlers set no cookies at all, because neither forgot-password nor
reset-password ever issues a session — reset-password's success is "you may now sign in," not "you are now
signed in," which is itself a deliberate, security-relevant choice explained in `# Interactions & Flows`.

## Anonymous-only guard

Unlike `middleware.ts`'s `(app)/:path*` matcher, which redirects an unauthenticated request *away* from the
product, each `(auth)` page performs the inverse check on the server before rendering: `login/page.tsx`,
`forgot-password/page.tsx`, and `reset-password/page.tsx` call the same `getSession()` helper
`(app)/layout.tsx` uses and, if a valid `qayd_at`/`qayd_rt` cookie pair is already present, redirect
immediately to `/select-company` or `/command-center` (whichever this document's own post-authentication
routing rule in `# Interactions & Flows` resolves to) rather than rendering a sign-in form to someone who is
already signed in. `/mfa` performs a narrower version of the same check — it renders only while an in-flight
challenge exists in the client-side auth-flow store described in `# Components Used`; a direct or refreshed
visit with no challenge in flight redirects to `/login` outright, never to a stale, unusable OTP form.
`/select-company`, conversely, requires a session and redirects *to* `/login` if one is absent — the one
route in this family gated the normal, positive way every `(app)` route is gated.

## The `next` deep-link parameter

`middleware.ts`'s redirect to `/login` is extended, by this document, to carry the original destination
forward: `NextResponse.redirect(new URL(\`/login?next=${encodeURIComponent(pathname + search)}\`, req.url))`
rather than a bare `/login`. Every page in this route group that hands off to the next one in the chain
(`/login` → `/mfa` → `/select-company`) forwards the same `next` value as a query parameter rather than
consuming it, so a Senior Accountant whose session lapsed while reading `/accounting/journal-entries/482`
lands back on that exact entry after signing in again, MFA and company selection included, instead of always
surfacing on `/command-center` — the concrete mechanism behind `AI_COMMAND_CENTER.md`'s own statement that
login "sends an authenticated user to `/command-center` unless a deep link was pending." An absent or
malformed `next` (a stale bookmark, a value that doesn't resolve to a same-origin path under `(app)`) is
never trusted as an open redirect target: the value is validated against a same-origin, `(app)`-prefixed
allow-list before use and silently discarded to the default destination otherwise.

## Access summary

| Route | Session required | Redirect if already authenticated | Redirect if precondition missing | Breadcrumb / Nav item |
|---|---|---|---|---|
| `/login` | No (anonymous-only) | `/select-company` or `next`/`/command-center` | — | None — outside the `(app)` shell entirely |
| `/mfa` | No, but requires an in-flight `mfa_token` | Same as `/login` | No `mfa_token` in the auth-flow store → `/login` | None |
| `/forgot-password` | No (anonymous-only) | Same as `/login` | — | None |
| `/reset-password` | No (anonymous-only), requires `?token=` | Same as `/login` | Missing `?token=` → `/forgot-password`; expired/consumed token → inline state, not a redirect (see `# States`) | None |
| `/select-company` | Yes | N/A (this route is the destination, not a bounce) | No session → `/login`; session but exactly one company → immediate silent redirect past this screen entirely | None |

# Layout & Regions

## The `AuthShell` split panel

`(auth)/layout.tsx` renders a two-region editorial split screen — a fixed brand panel and a scrollable form
panel — used identically by all five pages; each page supplies only the form-panel content, never a
different shell. This is a deliberate design choice distinct from every `(app)` screen's shared chrome: where
`DASHBOARD.md` and its siblings live inside `Sidebar` + `Topbar` + `Content`, the `(auth)` group's chrome is
this single, calmer two-region frame, because there is no company, no notification bell, and no AI status
pulse to show yet.

```
┌───────────────────────────────────┬──────────────────────────────────────────────┐
│  BRAND PANEL (inline-start, 42%)  │  FORM PANEL (inline-end, 58%, scrollable)     │
│  bg: ink-950, fixed — see         │  bg: canvas (theme-aware)                     │
│  # Dark Mode                      │                                                │
│                                    │                    [mark]  (mobile only)      │
│   ╱‾‾‾╲  QAYD                     │                                                │
│   ╲___╱  قيد                      │        Sign in to QAYD                        │
│                                    │        Continue to Al-Kandari Trading Co.     │
│   "Every entry,                   │                                                │
│    accounted for."                │        Email                                  │
│                                    │        [_____________________________]        │
│   (ledger-rule motif — slow,      │        Password                    [👁]        │
│    drifting hairlines, reduced-   │        [_____________________________]        │
│    motion gated, decorative)      │                                                │
│                                    │        [ ] Remember me      Forgot password?  │
│   ──────────────────────────      │                                                │
│   ✓ Bank-grade security           │        [          Sign in          ]          │
│   ✓ Human-approved AI, always     │                                                │
│   ✓ Built for Kuwait's finance    │        ───────────── or ─────────────         │
│     teams                         │        [ Continue with Google ]  (flagged)    │
│                                    │        [ Continue with Microsoft ]            │
│                                    │                                                │
│                                    │   EN | AR      ☾ Theme      Need help?        │
└───────────────────────────────────┴──────────────────────────────────────────────┘
```

| Region | Width | Content | Notes |
|---|---|---|---|
| Brand panel | `42%` at `xl`+, hidden below `md` (see `# Responsive Behavior`) | Wordmark lockup, one rotating editorial headline (EN/AR pair, not machine-translated), the ledger-rule motif, three trust markers | Fixed `position: sticky; inset-block-start: 0` so it never scrolls independently of a tall form (e.g. `/select-company` with many rows) |
| Form panel | Remaining width, own scroll region | Page-specific heading, subheading, form fields, primary action, secondary links, locale/theme controls in a lightweight footer | `max-width: 26rem` centered column inside the panel at `lg`+; full width with page padding below `lg` |

The brand panel is built from `flex-row` with no logical-property override for its own placement — a
deliberate, low-code RTL decision explained fully in `# RTL & Localization`: CSS `flex-direction: row`
already reverses under `dir="rtl"` per the flexbox specification, so the brand panel lands at the visual
*end* in Arabic and the visual *start* in English with zero conditional code, the same way `Sidebar` moves to
the opposite edge in `NAVIGATION_SYSTEM.md`'s own RTL section — this screen family's one "anchored" element
behaves exactly like the app shell's own anchored element.

## Per-route form-panel content

**`/login`.** Heading `t("login.heading")` ("Sign in to QAYD"); an optional subheading naming the
last-active company by `name_en`/`name_ar` when a non-httpOnly, non-sensitive `qayd_last_company_hint`
cookie (set on a previous successful login, containing only a display name and never a token or ID a page
could act on without also holding a valid session) is present — a returning user sees "Continue to Al-Kandari
Trading Co." rather than a cold, context-free "Sign in to QAYD"; email field, password field with a
show/hide toggle, a `Remember me` checkbox, a `Forgot password?` link, the primary `Sign in` button, an `or`
divider, and the two SSO buttons described in `# Components Used`.

**`/mfa`.** Heading `t("mfa.heading")` ("Verify it's you"); a subheading naming the masked destination for
the active method ("Enter the code from your authenticator app" / "We sent a code to •••• 2233"); a
`Tabs` control switching between the methods the account actually has enrolled (never showing a method the
account doesn't hold); a six-digit `InputOTP`; for SMS, a "Resend code" link that disables and shows a
60-second countdown immediately after use, per `AUTHENTICATION_API.md`'s stated one-send-per-60-seconds
throttle; a `Trust this device for 30 days` checkbox (see `# Interactions & Flows` for the additive backend
surface this checkbox assumes); a `Use a backup code instead` link; and a `Not you? Sign in again` link that
clears the auth-flow store and returns to `/login`.

**`/forgot-password`.** Heading `t("forgotPassword.heading")` ("Reset your password"); a short explanatory
line; the email field; the primary `Send reset link` button. On submit, the form itself is replaced in place
by a confirmation panel (never a route change — see `# States`) reading a single, deliberately
non-committal sentence explained in `# Interactions & Flows`, plus a `Back to sign in` link.

**`/reset-password`.** Heading `t("resetPassword.heading")` ("Choose a new password"); new-password and
confirm-password fields, each with the show/hide toggle; an inline `PasswordStrengthMeter`; the primary
`Reset password` button. If `?token=` is missing, malformed, expired, or already consumed, the entire form is
replaced by an error panel (see `# States`) rather than rendering fields against a token that cannot possibly
succeed.

**`/select-company`.** Not a form at all — a picker. Heading `t("selectCompany.heading")` ("Choose a
company"); a search input (rendered only once the caller's `companies` array exceeds eight rows, mirroring
`NAVIGATION_SYSTEM.md`'s own `WorkspaceSwitcher` threshold reasoning); a scrollable list of company rows,
each showing `name_en`/`name_ar`, a role `Badge`, and — when present — a relative "last active" timestamp;
and a trailing `+ Add a company` row that exits this screen family entirely into
`ONBOARDING.md`'s company-creation flow. Selecting a row is the action itself; there is no separate "Continue"
button to press afterward.

```
┌───────────────────────────────────┬──────────────────────────────────────────────┐
│  BRAND PANEL (unchanged)          │        Choose a company                        │
│                                    │        You belong to more than one company.   │
│                                    │                                                 │
│                                    │        🔍 Search companies…                    │
│                                    │        ┌────────────────────────────────────┐ │
│                                    │        │ Al-Kandari Trading Co.   Finance Mgr│ │
│                                    │        │ شركة الكندري التجارية    last: today │ │
│                                    │        ├────────────────────────────────────┤ │
│                                    │        │ Gulf Fresh Foods W.L.L.   Read Only │ │
│                                    │        │ مأكولات الخليج الطازجة              │ │
│                                    │        ├────────────────────────────────────┤ │
│                                    │        │ + Add a company                     │ │
│                                    │        └────────────────────────────────────┘ │
└───────────────────────────────────┴──────────────────────────────────────────────┘
```

# Components Used

Every visual element on these five screens is drawn from `COMPONENT_LIBRARY.md`'s existing primitive
catalogue, plus a small set of auth-scoped components living in `components/auth/` per
`PROJECT_STRUCTURE.md`'s module-folder convention. No screen in this family hand-rolls a button, an input, or
a toast — the one place this document extends the shared library at all is `InputOTP` and `Checkbox`, two
shadcn/ui primitives the platform's existing components (`Button`, `Input`, `Select`, `Card`, `Badge`,
`Tooltip`, `Alert`) already imply but which no prior document had a reason to catalogue, since nothing before
this screen family needed a one-time-code field or a plain boolean checkbox.

| Component | Source | Role on this screen |
|---|---|---|
| `Button` | `components/ui/button.tsx` (existing) | Sign in, Verify, Send reset link, Reset password, SSO buttons (`variant="outline"`) |
| `Input` | `components/ui/input.tsx` (existing) | Email, password (with a trailing icon-button for show/hide, not a separate component), search on `/select-company` |
| `Checkbox` | `components/ui/checkbox.tsx` (**new to the catalogue, standard shadcn/Radix primitive**) | `Remember me` on `/login`, `Trust this device` on `/mfa` |
| `InputOTP` | `components/ui/input-otp.tsx` (**new to the catalogue, standard shadcn primitive over `input-otp`**) | The six-digit code field on `/mfa`; each slot wrapped `dir="ltr"` — see `# RTL & Localization` |
| `Label` | `components/ui/label.tsx` (existing) | Every field label, wired to its input via `htmlFor`/`aria-describedby` per `ACCESSIBILITY.md`'s form pattern |
| `Card` | `components/ui/card.tsx` (existing) | Each row in `CompanySelectList`; the confirmation panel on `/forgot-password` |
| `Badge` | `components/ui/badge.tsx` (existing) | Role badge on each `/select-company` row, reusing the exact `tone` variant set `COMPONENT_LIBRARY.md` defines |
| `Alert` | `components/ui/alert.tsx` (existing, `FRONTEND_ARCHITECTURE.md`'s own `Alert variant="warning"` usage) | The lockout banner, the expired-token panel, the generic rate-limit notice |
| `Tabs` | `components/ui/tabs.tsx` (existing) | Method switcher on `/mfa` (Authenticator / SMS / Backup code) |
| `Tooltip` | `components/ui/tooltip.tsx` (existing) | "Why do we ask for this?" affordance beside `Trust this device`, and the disabled-SSO-button explanation while a company has not enabled it |
| `Separator` | `components/ui/separator.tsx` (existing) | The `or` divider between password sign-in and SSO |
| `Skeleton` | `components/ui/skeleton.tsx` (existing) | `/select-company`'s row-shape loading state |
| `useApiToast` | `hooks/use-api-toast.ts` (existing) | Every mutation's success/error surfacing, identical mapping to every other QAYD form (`toast.fromApiError(err)`) |

## New, auth-scoped components

| Component | File | Responsibility |
|---|---|---|
| `AuthShell` | `components/auth/auth-shell.tsx` | The `(auth)/layout.tsx` split-panel frame itself — brand panel + scrollable form panel, theme/locale footer |
| `BrandPanel` | `components/auth/brand-panel.tsx` | Wordmark, rotating headline pair, the ledger-rule motif, the three trust markers; entirely decorative/static, no data fetching |
| `LoginForm` | `components/auth/login-form.tsx` | Email/password fields, `Remember me`, submit handling, the `mfa_required` branch, the lockout/rate-limit branches |
| `MfaVerifyForm` | `components/auth/mfa-verify-form.tsx` | Method tabs, `InputOTP`, resend timer, `Trust this device`, backup-code fallback |
| `ForgotPasswordForm` | `components/auth/forgot-password-form.tsx` | Single email field, the in-place confirmation swap |
| `ResetPasswordForm` | `components/auth/reset-password-form.tsx` | New/confirm password fields, `PasswordStrengthMeter`, token-validity branching |
| `PasswordStrengthMeter` | `components/auth/password-strength-meter.tsx` | A four-segment bar plus a text label (`Weak` / `Fair` / `Good` / `Strong`), computed client-side purely as a typing hint — never the authority on whether a password is accepted, which remains the server's `422` |
| `CompanySelectList` | `components/auth/company-select-list.tsx` | The searchable row list on `/select-company`; deliberately a sibling of, not a reuse of, `WorkspaceSwitcher` — see the callout below |
| `SsoButtonRow` | `components/auth/sso-button-row.tsx` | The Google/Microsoft buttons, feature-flag-gated per `# Interactions & Flows` |
| `AuthLocaleThemeBar` | `components/auth/auth-locale-theme-bar.tsx` | The footer strip's language switcher + `ThemeToggle` + a `Need help?` link into `HELP_CENTER.md` |

**Why `CompanySelectList` is not `WorkspaceSwitcher`.** `NAVIGATION_SYSTEM.md`'s `WorkspaceSwitcher` is a
`Command`-driven combobox living inside a `56px` Topbar/Sidebar header band — correct for a frequent,
already-oriented power-user action taken while the rest of the product is fully visible behind it.
`/select-company` is a full-page, first-impression moment reached at most a few times per account (once
post-login when it applies, and again only if the caller deliberately revisits it), so it renders as a plain,
generously-spaced list of `Card` rows rather than a command palette — the same reasoning
`DASHBOARD.md` applies when it keeps its own KPI Strip to "four to six tiles, generous whitespace" rather
than adopting the AI Command Center's dense bento grid. Both components read the identical
`{id, name_en, name_ar, role}` shape from the same session payload; `CompanySelectList` is simply not built
on `Command`/`Popover`, because there is no trigger to open or close here.

## Client-only auth-flow store

A login that returns `status: "mfa_required"` must carry its short-lived `mfa_token` from `/login` to `/mfa`
without ever writing it to a cookie or `localStorage` — `FRONTEND_ARCHITECTURE.md`'s MFA challenge flow is
explicit that "the MFA screen never writes any cookie itself before that point." This document specifies the
exact mechanism: a small, **deliberately non-persisted** Zustand store, distinct from `useUiStore`
(`FRONTEND_ARCHITECTURE.md`'s own example, which *does* persist) precisely because an abandoned or refreshed
MFA challenge must leave nothing recoverable behind.

```ts
// lib/stores/auth-flow-store.ts
import { create } from "zustand";

interface AuthFlowState {
  mfaToken: string | null;
  mfaMethods: Array<"totp" | "sms" | "backup_code">;
  maskedPhone: string | null;
  email: string | null;
  nextPath: string | null;
  setMfaChallenge: (input: {
    mfaToken: string;
    methods: AuthFlowState["mfaMethods"];
    maskedPhone: string | null;
    email: string;
    nextPath: string | null;
  }) => void;
  clear: () => void;
}

// No `persist` middleware — deliberate. A hard refresh or a closed tab must invalidate
// any in-flight MFA challenge rather than let it linger in storage a user did not choose.
export const useAuthFlowStore = create<AuthFlowState>((set) => ({
  mfaToken: null,
  mfaMethods: [],
  maskedPhone: null,
  email: null,
  nextPath: null,
  setMfaChallenge: (input) => set({ ...input }),
  clear: () => set({ mfaToken: null, mfaMethods: [], maskedPhone: null, email: null, nextPath: null }),
}));
```

`mfa/page.tsx` is a thin Server Component that renders `<MfaVerifyForm/>` unconditionally; `MfaVerifyForm`
itself, on mount, reads `useAuthFlowStore((s) => s.mfaToken)` and redirects to `/login` immediately if it is
`null` — the concrete implementation of the "no `mfa_token` in the auth-flow store → `/login`" row in
`# Route & Access`'s access table.

## Zod schemas

```ts
// lib/schemas/auth.ts
import { z } from "zod";

export const loginSchema = z.object({
  email: z.string().min(1, "emailRequired").email("emailInvalid"),
  password: z.string().min(1, "passwordRequired"),
  rememberMe: z.boolean().default(false),
});
export type LoginFormValues = z.infer<typeof loginSchema>;

export const mfaVerifySchema = z.object({
  method: z.enum(["totp", "sms", "backup_code"]),
  code: z.string().min(6, "codeIncomplete").max(9), // 6 digits, or a 9-char backup code (xxxx-xxxx)
  trustDevice: z.boolean().default(false),
});
export type MfaVerifyFormValues = z.infer<typeof mfaVerifySchema>;

export const forgotPasswordSchema = z.object({
  email: z.string().min(1, "emailRequired").email("emailInvalid"),
});
export type ForgotPasswordFormValues = z.infer<typeof forgotPasswordSchema>;

// Mirrors, as a typing convenience only, the server's authoritative password policy — the
// server's own 422 on POST /password/reset remains the only source of truth for what is accepted.
const passwordPolicy = z
  .string()
  .min(10, "passwordTooShort")
  .regex(/[a-z]/, "passwordNeedsLowercase")
  .regex(/[A-Z]/, "passwordNeedsUppercase")
  .regex(/[0-9]/, "passwordNeedsNumber");

export const resetPasswordSchema = z
  .object({
    password: passwordPolicy,
    passwordConfirmation: z.string(),
  })
  .refine((v) => v.password === v.passwordConfirmation, {
    message: "passwordsDoNotMatch",
    path: ["passwordConfirmation"],
  });
export type ResetPasswordFormValues = z.infer<typeof resetPasswordSchema>;
```

## `LoginForm` (full implementation)

```tsx
// components/auth/login-form.tsx
"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation } from "@tanstack/react-query";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { loginSchema, type LoginFormValues } from "@/lib/schemas/auth";
import { useAuthFlowStore } from "@/lib/stores/auth-flow-store";
import { useApiToast } from "@/hooks/use-api-toast";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Alert, AlertTitle, AlertDescription } from "@/components/ui/alert";
import { EyeIcon, EyeOffIcon } from "lucide-react";
import { useState } from "react";
import { SsoButtonRow } from "@/components/auth/sso-button-row";

export function LoginForm() {
  const t = useTranslations("login");
  const router = useRouter();
  const search = useSearchParams();
  const next = search.get("next");
  const toast = useApiToast();
  const setMfaChallenge = useAuthFlowStore((s) => s.setMfaChallenge);
  const [showPassword, setShowPassword] = useState(false);
  const [lockout, setLockout] = useState<{ retryAfterSeconds: number } | null>(null);

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "", rememberMe: false },
  });

  const login = useMutation({
    mutationFn: (values: LoginFormValues) =>
      fetch("/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email: values.email,
          password: values.password,
          remember_me: values.rememberMe,
          device_name: getDeviceName(), // browser/OS heuristic, e.g. "Chrome — macOS"
        }),
      }).then((r) => r.json()),
    onSuccess: (envelope) => {
      if (!envelope.success) {
        if (envelope.errors?.[0]?.code === "ACCOUNT_TEMPORARILY_LOCKED") {
          setLockout({ retryAfterSeconds: envelope.retryAfterSeconds ?? 720 });
          return;
        }
        toast.fromApiError(envelope, { form }); // maps INVALID_CREDENTIALS onto both fields, generically
        return;
      }
      if (envelope.data.status === "mfa_required") {
        setMfaChallenge({
          mfaToken: envelope.data.mfa_token,
          methods: envelope.data.methods,
          maskedPhone: envelope.data.masked_phone ?? null,
          email: form.getValues("email"),
          nextPath: next,
        });
        router.push("/mfa");
        return;
      }
      // status === "authenticated": cookies are already set by the route handler.
      router.push(resolvePostAuthDestination(envelope.data, next));
    },
  });

  return (
    <form onSubmit={form.handleSubmit((v) => login.mutate(v))} className="space-y-5" noValidate>
      {lockout && (
        <Alert variant="warning" role="alert">
          <AlertTitle>{t("lockedTitle")}</AlertTitle>
          <AlertDescription>
            <LockoutCountdown seconds={lockout.retryAfterSeconds} />
          </AlertDescription>
        </Alert>
      )}
      <div className="space-y-1.5">
        <Label htmlFor="email">{t("email")}</Label>
        <Input
          id="email"
          type="email"
          autoComplete="username"
          dir="ltr"
          disabled={!!lockout}
          aria-invalid={!!form.formState.errors.email}
          {...form.register("email")}
        />
      </div>
      <div className="space-y-1.5">
        <Label htmlFor="password">{t("password")}</Label>
        <div className="relative">
          <Input
            id="password"
            type={showPassword ? "text" : "password"}
            autoComplete="current-password"
            disabled={!!lockout}
            aria-invalid={!!form.formState.errors.password}
            {...form.register("password")}
          />
          <button
            type="button"
            className="absolute inset-inline-end-2 inset-block-start-1/2 -translate-y-1/2 text-ink-500"
            onClick={() => setShowPassword((v) => !v)}
            aria-label={showPassword ? t("hidePassword") : t("showPassword")}
          >
            {showPassword ? <EyeOffIcon className="size-4" /> : <EyeIcon className="size-4" />}
          </button>
        </div>
      </div>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Checkbox id="remember" disabled={!!lockout} {...form.register("rememberMe")} />
          <Label htmlFor="remember" className="font-normal text-ink-700">{t("rememberMe")}</Label>
        </div>
        <a href="/forgot-password" className="text-sm text-accent-600 hover:underline">
          {t("forgotPassword")}
        </a>
      </div>
      <Button type="submit" className="w-full" loading={login.isPending} disabled={!!lockout}>
        {t("signIn")}
      </Button>
      <SsoButtonRow />
    </form>
  );
}
```

`resolvePostAuthDestination` and `getDeviceName` are small, pure helpers in `lib/auth/*`; the former is the
single implementation of the routing decision described in full in `# Interactions & Flows`, called
identically from `LoginForm`, `MfaVerifyForm`, and `CompanySelectList` so the "where do I go now" logic exists
exactly once rather than three times with a risk of drifting out of sync.

# Data & State

## Why there are no TanStack Query cache keys here

Every other screen document in this platform opens `# Data & State` with a table of `GET` endpoints, query
keys, and `staleTime` tuning. This screen family has almost none of that, and the absence is structural, not
an oversight: **TanStack Query caches server state for an authenticated session that does not yet exist for
four of these five routes.** There is no `company_id` to scope a cache key to, no `X-Company-Id` header to
send, and nothing worth caching for longer than the single request/response pair a mutation produces. Every
network call this screen family makes is therefore a `useMutation`, never a `useQuery` — the same distinction
`FRONTEND_ARCHITECTURE.md`'s own State Management table draws between "server state" and one-shot actions.
`/select-company` is the sole partial exception: it reads the `companies` array **already returned inside the
session payload** `login`/`mfa/verify` produced, server-side, in its own Server Component — a second network
round trip to re-fetch the same array the client already received would be redundant, not a caching decision
this document needs TanStack Query for at all.

## Endpoints

| Purpose | Endpoint | Permission (per `AUTHENTICATION_API.md`) | Notes |
|---|---|---|---|
| Sign in | `POST /api/v1/auth/login` (proxied via `app/api/auth/login/route.ts`) | public | Returns `status: "authenticated"` (cookies set) or `status: "mfa_required"` (no cookies yet) |
| Verify second factor | `POST /api/v1/auth/mfa/verify` (proxied via `app/api/auth/mfa/verify/route.ts`, **new**) | public, holder of a valid `mfa_token` | Generic endpoint per `AUTHENTICATION_API.md → MFA`; `method` distinguishes `totp`/`sms`/`backup_code` |
| Resend SMS code | `POST /api/v1/auth/mfa/sms/send` | public, holder of a valid `mfa_token` | Rate-limited to one send per 60 seconds per `AUTHENTICATION_API.md`; the resend link itself enforces the same 60s client-side as a UX courtesy, never as the actual limiter |
| Request password reset | `POST /api/v1/auth/password/forgot` (proxied via `app/api/auth/password/forgot/route.ts`, **new**) | public | Always returns the identical generic success envelope regardless of whether the email matches an account — see `# Interactions & Flows` |
| Consume reset token | `POST /api/v1/auth/password/reset` (proxied via `app/api/auth/password/reset/route.ts`, **new**) | public, holder of a valid reset token | Sets no session; success means "sign in again," not "signed in" |
| Switch/confirm active company | `POST /api/v1/auth/switch-company` (proxied via the existing `app/api/auth/switch-company/route.ts`) | self, requires a `company_users` row | Reused verbatim by `/select-company`; see callout below |
| Resolve identity for the anonymous-only guard | `GET /api/v1/auth/me` | self | Called server-side only, by the anonymous-only guard described in `# Route & Access`, never by client JavaScript on this screen family |

**Reusing `/switch-company` for initial selection.** `/select-company` does not call a distinct
"confirm my company" endpoint; it calls the exact same `POST /api/v1/auth/switch-company` mutation
`CompanySwitcher` uses from the Topbar (`FRONTEND_ARCHITECTURE.md → Company switching`), including the same
`queryClient.clear()` + `router.refresh()` pair once the caller is inside `(app)` — trivial here since no
query cache exists yet — followed by `resolvePostAuthDestination`'s redirect. Calling `/switch-company` to
"switch" into the company the login response already scoped the token to by default is harmless and
idempotent by the endpoint's own contract; it simply re-issues a fresh access token against the same
`company_id` and, as a side effect this document specifies, marks that `company_users` row as the account's
now-established "last active" company, which is precisely the signal that prevents `/select-company` from
reappearing on the account's next login.

## The `company_selection_required` field

`FRONTEND_ARCHITECTURE.md`'s route-tree comment describes `/select-company` as shown "when a user's token
maps to >1 company and none is 'last active.'" Because a JWT's `company_id` claim is structurally `NOT NULL`
(`AUTHENTICATION_API.md → JWT → Structure`), the login/MFA-verify response can never omit an
`active_company_id` — a token must always carry *some* scope. This document therefore specifies one small,
additive boolean the `authenticated` envelope carries alongside the fields `AUTHENTICATION_API.md` already
documents:

```json
{
  "status": "authenticated",
  "access_token": "…",
  "refresh_token": "…",
  "active_company_id": "cmp_4471",
  "company_selection_required": true,
  "companies": [
    { "id": "cmp_4471", "name_en": "Al-Kandari Trading Co.", "name_ar": "شركة الكندري التجارية", "role": "finance_manager", "last_active_at": null },
    { "id": "cmp_5502", "name_en": "Gulf Fresh Foods W.L.L.", "name_ar": "مأكولات الخليج الطازجة", "role": "read_only", "last_active_at": null }
  ]
}
```

`company_selection_required` is `true` precisely when `companies.length > 1` and no row in the caller's
`company_users` set has ever had `last_active_at` populated — the literal "none is 'last active'" case, most
commonly a brand-new external auditor or bookkeeper account invited into several client companies before
their very first sign-in. `active_company_id` in that case is a harmless, order-stable default (the
lowest `company_users.id`, i.e. the oldest membership) that the token is scoped to only until the user's
first `/switch-company` call — which `/select-company`'s own selection always performs, even when the user's
choice happens to match the default already in place. This is the one field this document adds to the
envelope `AUTHENTICATION_API.md` defines; every other field above is reused verbatim from that document.

## Route Handlers this document adds

```ts
// app/api/auth/mfa/verify/route.ts — structural sibling of login/route.ts
export async function POST(req: Request) {
  const body = await req.json(); // { mfa_token, method, code, trust_device }
  const res = await fetch(`${process.env.QAYD_API_URL}/api/v1/auth/mfa/verify`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const envelope = await res.json();
  if (!envelope.success) return Response.json(envelope, { status: res.status });

  const response = Response.json({
    success: true,
    data: {
      user: envelope.data.user,
      companies: envelope.data.companies,
      active_company_id: envelope.data.active_company_id,
      company_selection_required: envelope.data.company_selection_required,
    },
    message: envelope.message, errors: [], meta: { pagination: null },
    request_id: envelope.request_id, timestamp: envelope.timestamp,
  });
  setSessionCookies(response, envelope.data); // identical helper login/route.ts uses
  if (body.trust_device) {
    response.cookies.set("qayd_device_trust_hint", "1", { httpOnly: true, secure: true, sameSite: "lax", maxAge: 60 * 60 * 24 * 30 });
  }
  return response;
}
```

```ts
// app/api/auth/password/forgot/route.ts — thin pass-through, no cookies, ever
export async function POST(req: Request) {
  const body = await req.json(); // { email }
  const res = await fetch(`${process.env.QAYD_API_URL}/api/v1/auth/password/forgot`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  return Response.json(await res.json(), { status: res.status });
}
// app/api/auth/password/reset/route.ts follows the identical shape, forwarding { token, password, password_confirmation }.
```

## The "trust this device" surface

`AUTHENTICATION_API.md` documents `refresh_tokens.device_fingerprint` as a signal already computed for
step-up risk scoring, but no existing endpoint lets a verified MFA session mark that fingerprint as
trusted going forward. This document specifies the one additive backend surface the `Trust this device`
checkbox on `/mfa` assumes, deliberately shaped to match the platform's own conventions rather than invent a
parallel style:

```sql
CREATE TABLE trusted_devices (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id             BIGINT NOT NULL REFERENCES users(id),
    device_fingerprint  VARCHAR(64) NOT NULL,
    label               VARCHAR(120) NULL,
    trusted_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at          TIMESTAMPTZ NOT NULL,        -- trusted_at + 30 days
    revoked_at          TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_trusted_devices_user_fp ON trusted_devices (user_id, device_fingerprint) WHERE revoked_at IS NULL;
```

`POST /api/v1/auth/mfa/verify` accepts an additional `trust_device: boolean` field; on `true`, it upserts a
`trusted_devices` row for the verifying `user_id` and the request's own `device_fingerprint`. `POST
/api/v1/auth/login` then checks this table **before** deciding whether to return `mfa_required`: a
`device_fingerprint` match with a live, unexpired `trusted_devices` row lets a login skip straight to
`status: "authenticated"` even for a role where MFA is otherwise mandatory, with the resulting access token's
`amr` still faithfully recording only `["pwd"]` — a trusted-device login is never misrepresented as if a
second factor had actually been presented that day. This is additive and safely degrades: if this table is
never implemented, the checkbox's `trust_device` field is simply ignored server-side and every login
continues to ask for MFA, which is the platform's existing, safe default behavior. `# Edge Cases` covers
revocation and the interaction with `AUTHENTICATION_API.md`'s existing device-risk step-up rule.

# Interactions & Flows

**Opening `/login`.** The Server Component checks for an existing session first (`# Route & Access`); absent
one, it renders `AuthShell` + `LoginForm` with the email field auto-focused. If a `qayd_last_company_hint`
cookie names a company, the subheading reads "Continue to {name}" in the active locale; otherwise it reads a
plain "Sign in to QAYD." The `next` query parameter, if present and same-origin, is read once and threaded
through every subsequent step via `useAuthFlowStore`/route pushes rather than re-parsed from `location.search`
at each step.

**Submitting credentials.** `LoginForm`'s `Button` enters its `loading` state (per `COMPONENT_LIBRARY.md`'s
`Button` contract — spinner in place, width unchanged, `disabled`) the instant the mutation fires, and both
fields plus the checkbox disable alongside it, so a slow network cannot produce a double submission. Four
branches follow:

1. **`status: "authenticated"`.** Cookies are already set by the route handler. `resolvePostAuthDestination`
   decides the next hop: `company_selection_required` → `/select-company` (carrying `next` forward as its own
   query param); else a valid `next` → that path directly; else `/command-center`, matching
   `AI_COMMAND_CENTER.md`'s stated default post-login destination.
2. **`status: "mfa_required"`.** `setMfaChallenge` populates the auth-flow store with the `mfa_token`, the
   account's enrolled `methods`, an optional `maskedPhone`, the typed `email` (redisplayed on `/mfa`'s
   subheading so a user mid-flow always sees which account they're verifying), and the pending `next`. The
   client then `router.push("/mfa")`.
3. **`errors[0].code === "INVALID_CREDENTIALS"`.** Both the email and password fields receive a shared,
   generic inline error — never "email not found" or "password incorrect" separately, matching
   `AUTHENTICATION_API.md`'s explicit anti-enumeration stance — rendered via the same
   `useApiToast().fromApiError` mapping every other QAYD form uses, escalated to `role="alert"` per
   `ACCESSIBILITY.md`'s live-region tiers because a rejected sign-in blocks the only task this screen has.
4. **`errors[0].code === "ACCOUNT_TEMPORARILY_LOCKED"`.** The form's fields disable entirely (not merely the
   submit button) and an `Alert variant="warning"` renders a live countdown derived from the response's own
   `Retry-After` value — never a client-guessed duration — ticking down to zero, at which point the fields
   re-enable automatically with no page reload required. The `Forgot password?` link stays enabled throughout
   a lockout, since it is the one legitimate way out of a lockout a user caused by a genuinely forgotten
   password rather than a brute-force attempt.

**The MFA screen.** `MfaVerifyForm` defaults to the first method in `mfaMethods` (TOTP first when enrolled,
since it requires no network round trip to receive a code) and renders only the tabs for methods the account
actually holds — never a method the user hasn't enrolled, which would be a dead end. Entering a wrong TOTP or
SMS code decrements the server's own attempt counter and re-renders the same `INVALID` inline error pattern as
`/login`'s step 3; five wrong attempts return `MFA_CHALLENGE_EXHAUSTED` (`401`), at which point the client
clears the auth-flow store and redirects to `/login` with the exact message `AUTHENTICATION_API.md`'s own
example specifies, `"Too many incorrect codes. Please sign in again."`, surfaced as a one-shot toast rather
than a URL-embedded query string a refresh would re-trigger. On success, the response carries the same
`status: "authenticated"` shape `/login` can return directly, and the client follows the identical
`resolvePostAuthDestination` branch — `/mfa` never re-implements that decision, it calls the same helper.

**Requesting a password reset.** `ForgotPasswordForm` submits a bare email and — regardless of whether that
address matches an account — receives and displays the identical envelope: `"If an account exists for that
email address, we've sent a link to reset the password. The link expires in 30 minutes."` The form itself is
replaced in place (a state flip inside the same client component, not a route change) by a `Card` carrying
that sentence and a `Back to sign in` link, so the browser's back button returns to a fresh, empty
`/forgot-password` rather than resubmitting. This non-committal response is a direct, deliberate application
of `AUTHENTICATION_API.md → Login`'s stated anti-enumeration rule extended to this endpoint: confirming or
denying an account's existence from `/forgot-password` would otherwise let anyone test a list of email
addresses against QAYD one at a time.

**Resetting the password.** `/reset-password?token=…` reads the token server-side only to decide which of two
states to render (`# States` below) — the token itself is never displayed, logged to the client console, or
placed anywhere but the hidden field the form submits back. A successful `POST /password/reset` sets no
session (`AUTHENTICATION_API.md`'s revocation rule already states a password change "revokes every
refresh-token family... including the one that just changed the password" — a reset is the unauthenticated
sibling of that same rule, so there is no session to preserve even if QAYD wanted to), and the client
redirects to `/login?message=password_reset`, where `LoginForm`'s parent Server Component reads that param
once to render a one-time, dismissible success banner above the form — the identical pattern
`PROFILE.md → Changing password` already specifies for its own post-change redirect to `/login`, reused here
verbatim rather than invented a second time.

**Choosing a company.** `CompanySelectList` renders every row from the session payload's `companies` array
with no additional fetch. Clicking a row immediately fires the `switch-company` mutation for that row's `id`
(a `Skeleton` overlay replaces the clicked row's content while pending, per `COMPONENT_LIBRARY.md`'s loading
conventions — the other rows stay interactive so a misclick can be corrected without waiting), and on success
calls the same `resolvePostAuthDestination` helper, this time with `company_selection_required` implicitly
resolved and a guaranteed `active_company_id`. The trailing `+ Add a company` row instead navigates to
`ONBOARDING.md`'s own company-creation entry point (`/onboarding?intent=new-company`), leaving this screen
family and its session-establishment job behind entirely — creating a company is `ONBOARDING.md`'s
responsibility, not this document's.

**SSO buttons — present, not prominent.** `AUTHENTICATION_API.md → OAuth → Role 2` already defines
`GET /sso/{provider}/redirect` and `GET /sso/{provider}/callback` in full; this document does not add to that
contract, only decides how the buttons appear before the feature is broadly enabled. `SsoButtonRow` reads a
company-agnostic platform feature flag (`sso_enabled`, resolved the same way any other platform flag is
resolved for a pre-session request — by hostname/build-time config, since there is no company context yet to
scope a flag to); while disabled, the two buttons render with `disabled` state and a `Tooltip` reading
"Sign-in with Google/Microsoft isn't available yet," rather than being hidden outright, so the row's future
arrival never requires a layout change once the flag flips on. Clicking an enabled button is a plain
navigation (`<a href="/api/v1/auth/sso/google/redirect">`, full page load, not a fetch) — this is the one
control on this entire screen family that is intentionally a hard navigation rather than a client mutation,
because an OAuth redirect dance cannot be an XHR by construction.

# AI Integration

This is the one screen family in the entire QAYD product with **no AI-authored surface at all** — no
confidence badge, no `AIProposalPanel`, no "Suggested" label, no assistant presence anywhere in `AuthShell`.
That absence is a careful, positive application of the platform's AI rules, not an exception to them, for
three concrete reasons:

1. **There is no `company_id` for an AI agent to reason within.** Every agent this platform ever produces
   output through — `GENERAL_ACCOUNTANT`, `CFO_AGENT`, `FRAUD_DETECTION`, and the rest — operates against one
   company's data, and the platform's own foundational rule states "AI only sees the active company." Before
   `/select-company` resolves, there is no active company, and before `/login`/`/mfa` resolve, there is no
   authenticated user at all — there is structurally nothing for an agent to be scoped to, so rendering an AI
   panel here would mean showing one that has nothing true to say.
2. **Nothing on this screen is a financial or operational decision.** `DESIGN_LANGUAGE.md`'s AI rule reserves
   the accent color and the "Suggested" language for something a human approves or rejects; signing in is
   neither a proposal nor an approval, it is an identity check, and dressing it in AI-panel styling would
   dilute the one signal — "brass on this screen means either the primary action or something AI touched" —
   the rest of the product trains a user to trust.
3. **A calmer, quieter screen is the correct trust signal for this specific moment.** `DESIGN_LANGUAGE.md`'s
   brand tone is "the calm expert in the room"; nowhere is that more load-bearing than the screen a person
   sees immediately before trusting QAYD with their company's ledger. A login screen crowded with an AI
   assistant bubble or a "let AI help you sign in" affordance would read as exactly the generic, over-eager
   "AI startup" register the platform's own Brand Expression section explicitly rejects.

The one place AI-adjacent activity genuinely exists near this screen family is entirely server-side and
never rendered here: `AUTHENTICATION_API.md → Refresh Tokens → Reuse Detection` already specifies that a
suspicious sign-in (a reused refresh token, or — extended by this document's own device-trust surface above —
a login from an unrecognized fingerprint claiming a trusted-device skip) enqueues a `notifications` row and a
security email, evaluated by the platform's fraud/security tooling, not by a chat-style agent a user
interacts with here. That notification is surfaced later, inside `(app)`, through the ordinary
`NOTIFICATIONS.md` bell — never as a pop-up on the sign-in screen itself, which would only alarm someone in
the middle of the one flow that must stay calm and legible above all else. The very first AI-authored content
any QAYD session ever shows a user is, correctly, the Business Health Score chip on `/command-center` or the
condensed AI Summary Rail on `/dashboard` — both several redirects downstream of this document's own last
responsibility, which ends the moment `resolvePostAuthDestination` fires.

# States

| Screen | Loading | Empty | Error |
|---|---|---|---|
| `/login` | `Button`'s own `loading` prop (spinner, fixed width, disabled) — no full-page skeleton, since the form itself is static and requires no fetch to render | Not applicable — a login form is never "empty" | Inline generic credential error (`role="alert"`); lockout `Alert` replacing the form's enabled state; a network-failure `Alert` ("Can't reach QAYD right now — check your connection") preserving whatever was typed |
| `/mfa` | `InputOTP` and the resend link show a small inline spinner while a verify/resend call is pending; the tab switch itself is instant, client-only state | Not applicable | Inline wrong-code error under the `InputOTP`; `MFA_CHALLENGE_EXHAUSTED` redirects to `/login` with a toast rather than rendering an error in place, since the challenge itself is now dead |
| `/forgot-password` | `Button`'s `loading` prop only | Not applicable | A network-failure `Alert` only — the endpoint's own business response is always a success shape by design (`# Interactions & Flows`), so there is no "email not found" empty/error state to design for |
| `/reset-password` | `Button`'s `loading` prop on submit; a brief server-side check before first paint to classify the token (see below) | Not applicable | **Two distinct token-invalid states**, both replacing the form outright rather than letting it render against a token that cannot work: (a) missing/malformed `?token=` — "This password reset link is invalid. Request a new one," linking to `/forgot-password`; (b) expired or already-consumed token (`RESET_TOKEN_EXPIRED`/`RESET_TOKEN_CONSUMED`) — "This link has expired. Reset links are valid for 30 minutes," same link out. A genuine `422` on submit (e.g., the new password fails the server's policy after passing the client's Zod mirror) is a normal inline field error, not one of these two full-panel states |
| `/select-company` | `Skeleton` rows matching `CompanySelectList`'s real row height while the (already-available) `companies` array is briefly being read/rendered; a `Skeleton` overlay on the specific row just clicked while `switch-company` resolves | Not reachable with zero companies — a user always has at least the company they were invited into to reach this screen at all; **not shown at all** when `companies.length === 1`, which redirects silently and instantly rather than rendering a one-row picker no one needs to choose from | `COMPANY_ACCESS_DENIED`/`COMPANY_MEMBERSHIP_SUSPENDED` on the attempted row surfaces inline on that row only (a small `Alert` beneath it) — the other rows remain clickable, since a suspended membership in one company says nothing about the caller's standing in another |

# Responsive Behavior

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | Brand panel is entirely hidden — not collapsed, hidden — and the form panel becomes the full viewport with standard page padding (`--space-md`); a small, static wordmark lockup (no motif, no rotating headline, no trust markers) renders once at the top of the form panel itself so brand presence is not lost entirely, per `BrandPanel`'s own `compact` variant; `InputOTP` slots grow to a minimum 44×44px touch target per the platform's universal touch-target rule; the footer locale/theme bar wraps to two lines rather than truncating |
| `md` (768–1023px) | Brand panel reappears as a `160px`-tall top band (wordmark + a single static headline, no motif animation, no trust markers — a deliberately lighter version, not the full desktop panel scaled down) sitting above a full-width form panel; this is a transitional layout, not a scaled-down split |
| `lg` (1024–1279px) | Full split-panel layout activates: brand panel `38%`, form panel `62%`, form column capped at `24rem` and centered within its panel |
| `xl`+ (≥1280px) | Brand panel widens slightly to `42%` (more breathing room for the rotating headline at a larger type size); form column remains capped at `26rem` — this screen family deliberately never uses the extra width to show more content, only more margin, since a sign-in form has a fixed, small amount of true content regardless of viewport |

`CompanySelectList` on `/select-company` gets one additional rule below `md`: the row list becomes the
primary scrollable region and the brand panel's compact top band scrolls away with the page (not sticky)
so a caller with a dozen companies is never fighting a pinned header for vertical space on a small phone —
the one deliberate exception to `AuthShell`'s otherwise-sticky brand panel, justified because this is the one
page in the family whose content can genuinely be long.

# RTL & Localization

- **The split itself mirrors for free.** `AuthShell`'s brand panel and form panel are laid out with a plain
  `flex-row` container and no logical-property override on the panels' own ordering, because CSS
  `flex-direction: row` already reverses under `dir="rtl"` per the flexbox specification — the brand panel
  lands at the visual end in Arabic and the visual start in English with zero conditional code, exactly
  mirroring how `NAVIGATION_SYSTEM.md`'s own `Sidebar` moves to the opposite edge under RTL with no
  screen-specific logic.
- **Every internal gap and icon placement uses logical properties.** The password field's show/hide toggle
  is positioned `inset-inline-end-2`, never `right-2`; the OTP resend link, the `Remember me` checkbox's gap
  to its label, and the `CompanySelectList` row's role badge all use `ms-*`/`me-*`/`ps-*`/`pe-*` exclusively,
  enforced by the same `eslint-plugin-tailwindcss` restricted-class rule `DESIGN_LANGUAGE.md` and
  `LAYOUT_SYSTEM.md` both specify platform-wide.
- **The wordmark and its mark never mirror.** The small ledger-tick-mark glyph beside "QAYD"/"قيد" in
  `BrandPanel` is a static, non-directional logomark — per `DESIGN_LANGUAGE.md`'s icon rule, only icons that
  *encode* direction (a chevron, a back arrow) flip under `[dir="rtl"]`; a brand mark is never one of them.
- **Email addresses and the OTP code are always `dir="ltr"`, even in an Arabic UI.** An email input wrapped
  in `dir="ltr"` prevents the Unicode bidi algorithm from reordering the `@`/`.` structure of a Latin-script
  address inside an RTL form; each `InputOTP` slot is likewise rendered inside a `dir="ltr"` wrapper so a
  six-digit code such as `482913` is both displayed and *typed* in the same left-to-right digit order
  regardless of interface language — the identical rule `AmountCell` applies to monetary figures
  (`DESIGN_LANGUAGE.md → Typography → Tabular numerals`), generalized here to any field whose content is
  numeric-Latin by nature rather than by the surrounding language. Getting this wrong is the single most
  likely RTL defect on this screen family: an `InputOTP` that inherits ambient `dir="rtl"` would visually
  reverse the digit order the moment a user's authenticator app shows a code, which is confusing in a way no
  amount of correct Arabic label translation would fix.
- **Password managers and autofill are direction-agnostic by design.** `autoComplete="username"` /
  `"current-password"` / `"new-password"` / `"one-time-code"` attributes are set on every relevant field
  regardless of locale — these WHATWG autofill tokens are what lets a password manager (and, on `/mfa`, an
  SMS autofill on mobile Safari/Chrome) correctly recognize and fill the field irrespective of the label's
  language, and this document treats getting them right as an accessibility and usability requirement, not a
  cosmetic nicety.
- **Copy is authored, not translated, in both languages**, per `DESIGN_LANGUAGE.md`'s Voice & Microcopy
  discipline:

| Context | English | Arabic |
|---|---|---|
| Login heading | Sign in to QAYD | تسجيل الدخول إلى قيد |
| Returning-user subheading | Continue to Al-Kandari Trading Co. | المتابعة إلى شركة الكندري التجارية |
| Locked account | Too many failed sign-in attempts. Try again in 12 minutes. | محاولات دخول فاشلة كثيرة. حاول مرة أخرى بعد 12 دقيقة. |
| Invalid credentials | Email or password is incorrect. | البريد الإلكتروني أو كلمة المرور غير صحيحة. |
| MFA heading | Verify it's you | تأكيد هويتك |
| MFA SMS subheading | We sent a code to •••• 2233 | أرسلنا رمزًا إلى •••• 2233 |
| Trust this device | Trust this device for 30 days | الوثوق بهذا الجهاز لمدة 30 يومًا |
| Forgot-password confirmation | If an account exists for that email address, we've sent a link to reset the password. | إذا كان هناك حساب مرتبط بهذا البريد الإلكتروني، فقد أرسلنا رابطًا لإعادة تعيين كلمة المرور. |
| Reset-link expired | This link has expired. Reset links are valid for 30 minutes. | انتهت صلاحية هذا الرابط. روابط إعادة التعيين صالحة لمدة 30 دقيقة. |
| Select-company heading | Choose a company | اختر شركة |
| Add a company | Add a company | إضافة شركة |

  Every Arabic string above is written directly by a fluent professional-register writer and held to the
  same "would a CFO reading this find it precise and calm" bar `DESIGN_LANGUAGE.md` applies platform-wide —
  the lockout and invalid-credentials strings in particular are exactly the moments a stiff, machine-translated
  register would read worst, since they are already the most stressful sentence on this screen family.

# Dark Mode

The form panel is a fully theme-aware surface — `canvas`/`surface` background, `ink-950`/`ink-0` text,
`ink-150` borders, every `Input`/`Button`/`Alert` resolving through the identical dark-mode token remap
`COMPONENT_LIBRARY.md` defines for the rest of the product, verified in the same four-way
(`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`) Storybook and Playwright matrix `DASHBOARD.md` and
`LAYOUT_SYSTEM.md` both hold every component to.

**The brand panel is the one deliberate, theme-invariant exception in the entire product.** `BrandPanel`
renders at a fixed `ink-950` background with `ink-0`/near-white text **regardless of whether the signed-out
visitor's system or stored preference is light or dark** — the one surface in QAYD that does not flip. This
is a considered exception in the same spirit `DESIGN_LANGUAGE.md` grants `surface-glass`: rare enough to earn
its distinctiveness, and deliberately reserved for a single, specific moment rather than generalized. A split
auth screen with one permanently-dark editorial panel is a well-established, premium pattern precisely
because it reads as the product's own fixed cover — like a bound ledger's cover looking the same whether the
room is lit for daytime bookkeeping or a partner reviewing figures after hours — rather than as an
inconsistency in the theming system. The theme toggle in `AuthLocaleThemeBar` visibly and immediately affects
only the form panel beside it, which is itself useful, honest signal: a person can preview how their chosen
theme will look on every other QAYD screen before they have even signed in. Contrast on the brand panel is
fixed and pre-verified rather than computed per-theme: `ink-0` text on `ink-950` clears roughly 18:1, several
multiples past the 4.5:1 AA floor, with headroom to spare for the ledger-rule motif sitting behind it at low
opacity.

# Accessibility

QAYD targets WCAG 2.1 AA as a floor on this screen family exactly as on every other, with the following
applications specific to an authentication flow:

- **Landmark structure.** `AuthShell` renders the brand panel as `<aside aria-hidden="true">` — it is
  decorative and duplicates no unique information a screen-reader user needs — and the form panel as
  `<main>`, with each page's own `<h1>` (the heading named in `# Layout & Regions`) as the first focusable,
  announced element, matching the route-change focus-reset convention `DASHBOARD.md`'s own Accessibility
  section establishes for the rest of the product.
- **Autofocus is used exactly once per page, deliberately.** The email field on `/login`, the first `InputOTP`
  slot on `/mfa`, the email field on `/forgot-password`, and the new-password field on `/reset-password` each
  receive focus on mount — the one broad exception to the platform's general caution around autofocus,
  justified because each of these pages has exactly one obvious next action and no competing content above
  it to skip past.
- **Every field is labeled, described, and error-wired the standard way.** `Label`/`Input` pairs use
  `htmlFor`/`id`; `aria-invalid` and `aria-describedby` point at the associated `FormMessage` exactly as
  `ACCESSIBILITY.md → Forms` specifies platform-wide; no field on this screen family is ever identified by
  placeholder text alone.
- **Errors escalate to the assertive tier, deliberately.** Per `ACCESSIBILITY.md`'s live-region severity
  table, an invalid-credentials message, a lockout banner, and an MFA wrong-code message all use
  `role="alert"` (implicit assertive) rather than `role="status"` — each one blocks the single task this
  screen exists to complete, which is precisely the case that table reserves the assertive tier for.
- **The lockout countdown is visible but not verbally spammed.** The timer's numeral updates every second
  visually but is **not** wrapped in `aria-live` at every tick — only the initial lockout message announces
  once, matching `DASHBOARD.md`'s own "a fast-moving feed must never interrupt whatever a screen-reader user
  is currently reading" principle, generalized here to "a ticking clock must never re-announce itself every
  second." A screen-reader user can still read the current remaining time on demand by navigating back into
  the `Alert` region.
- **`InputOTP` supports paste, autofill, and backspace navigation natively.** Pasting a full six-digit code
  (copied from an authenticator app or an SMS autofill suggestion) fills every slot in one action rather than
  requiring six individual keystrokes; backspace from an empty slot moves focus to the previous one; the
  underlying shadcn primitive's own `aria-label`s per slot ("Digit 1 of 6," etc.) are kept intact rather than
  overridden.
- **The password show/hide toggle has a real accessible name that changes with its state** —
  `aria-label={t("showPassword")}` / `t("hidePassword")` — never an icon-only button with no name at all, and
  is reachable by keyboard as an ordinary tab stop immediately after the password field.
- **Reduced motion is honored identically to the rest of the product.** The brand panel's ledger-rule motif
  and the page-fade `template.tsx` transition between `/login` → `/mfa` → `/select-company` both read
  `useReducedMotion()`/the `prefers-reduced-motion` media query exactly as `DESIGN_LANGUAGE.md → Motion`
  specifies; with it set, the motif renders as a single static frame and page changes cut instantly rather
  than cross-fading.
- **No control on this screen is ever a mystery.** The two SSO buttons are disabled-with-tooltip rather than
  hidden while the platform flag is off, per `ACCESSIBILITY.md`'s general rule that a disabled,
  currently-unavailable control still names why — even though this is not a permission-gated case, the same
  courtesy applies to a feature-gated one.
- **No CAPTCHA, no bot-detection puzzle.** This document deliberately specifies none on any of the five
  screens — abuse resistance here is entirely the rate-limiting and lockout mechanics `AUTHENTICATION_API.md`
  and `API_RATE_LIMITING.md` already define, which degrade far more gracefully for a legitimate user (and for
  a screen-reader or keyboard-only user in particular, for whom most CAPTCHA styles are actively hostile)
  than an interactive challenge would.

# Performance

- **This route ships the smallest JavaScript bundle in the product**, by design and by Principle 2: `(auth)`
  pages are Server Components wrapping a single client-island form, with none of `(app)`'s `Sidebar`,
  `Topbar`, `RealtimeProvider`/Echo client, or TanStack Query's broader query-key infrastructure in the
  critical path — `RealtimeProvider` in particular never mounts here at all, since Laravel Echo's own
  channel-authorization handshake (`FRONTEND_ARCHITECTURE.md → Authenticating the channel handshake`)
  requires a bearer identity this screen family exists specifically to not yet have.
- **No render-blocking data fetch.** Every one of the five pages' first paint depends on nothing but the
  Server Component's own anonymous-only/session check (a single cookie read, no network call in the common
  case) — there is no dashboard-style "wait for Redis-cached tiles" budget to hit here at all, and this
  document's own target is simply that the form is interactive as fast as the shared `next/font` display and
  text faces resolve, with `font-display: swap` preventing a blocked paint per `DESIGN_LANGUAGE.md`'s existing
  font-loading strategy.
- **`InputOTP` and `PasswordStrengthMeter` are code-split** (`next/dynamic`) away from `/login`'s own bundle,
  since neither is needed until a user actually reaches `/mfa` or `/reset-password` respectively — a person
  who signs in with no MFA enrolled and never forgets a password never downloads either chunk.
- **Zero imagery, per `DESIGN_LANGUAGE.md`'s stance.** The brand panel's ledger-rule motif is CSS/SVG, not a
  raster asset — there is no hero photograph, illustration, or gradient-mesh background to optimize, lazy-load,
  or serve at multiple densities, which is as much a performance decision as a design one for the single
  screen family every visitor's very first QAYD paint depends on.
- **The ephemeral auth-flow store adds negligible weight.** `useAuthFlowStore` holds five primitive/short-array
  fields with no `persist` middleware and no hydration step, unlike `useUiStore` — there is no
  localStorage read to block on before `/mfa` can render its first frame.
- **Idempotency still applies, even here.** `POST /login` and `POST /mfa/verify` are not literally idempotent
  operations in the ledger sense, but the client still guards against a double-submit the same way
  `FRONTEND_ARCHITECTURE.md`'s Principle 9 requires elsewhere: the submit button's `loading`-driven `disabled`
  state, not a generated `Idempotency-Key`, is this screen family's mechanism, since a duplicate login attempt
  is naturally idempotent at the identity layer in a way a duplicate journal-entry post is not — two identical
  successful logins simply produce two session/refresh-token rows, not a corrupted financial record, so the
  heavier key-based mechanism used for money-moving mutations is not needed here.
- **Web Vitals are tracked against a much stricter baseline than any `(app)` screen**, precisely because there
  is no legitimate reason for `/login` to ever be slow: no company-scale variance, no large-ledger tail
  latency to account for the way `DASHBOARD.md`'s own Web Vitals section allows for — a regression on this
  route is always a regression, never an expected scale effect.

# Edge Cases

| Edge case | Frontend behavior |
|---|---|
| An already-authenticated user navigates directly to `/login` (a stale bookmark, a link shared from an old email) | The anonymous-only guard (`# Route & Access`) redirects server-side before any form renders, to `/select-company` or the resolved default — the user never sees a sign-in form while already signed in, and no client-side flash of the form occurs first, since the check runs in the Server Component before the client bundle for `LoginForm` is even requested |
| A user opens `/mfa` directly (refresh, bookmark, browser restore) with no challenge in flight | `useAuthFlowStore().mfaToken` is `null` (the store was never persisted); `MfaVerifyForm` redirects to `/login` immediately on mount rather than rendering a code field that could never succeed |
| Two browser tabs both have `/login` open; the user signs in successfully in Tab A | Tab B is entirely unaffected until its own next navigation or submit — there is no cross-tab session-broadcast on this screen family the way `FRONTEND_ARCHITECTURE.md`'s refresh-token single-flight logic coordinates *after* a session exists. If Tab B's user also submits, they simply sign in a second time (a second, independent `refresh_tokens` row/family), which is harmless per the Performance section above; Tab B does not need to detect Tab A's success to behave correctly |
| The reset-password link is opened a second time after already being used | The server's `consumed_at`-style check on the reset token (mirroring `AUTHENTICATION_API.md`'s identical `oauth_auth_codes.consumed_at` single-use pattern) returns `RESET_TOKEN_CONSUMED`; the page renders the same expired-link panel as a time-based expiry, with identical copy — a consumed and an expired token are indistinguishable to the user and require the identical next action (request a new link), so this document deliberately does not give them different messages |
| A user requests a password reset, then requests a second one for the same address before using the first | Both tokens are valid until each's own 30-minute window or first use; consuming either one should reasonably invalidate the other (a superseded reset-token row), so a stale email tab clicked later fails cleanly as `RESET_TOKEN_CONSUMED` rather than silently succeeding against an address whose password a different link already changed |
| `company_selection_required: true` arrives but `companies.length === 1` | Defensive-only; this combination should not occur given this document's own trigger rule, but if it ever does, `/select-company` treats a single-company array as the "skip silently" case regardless of the flag, per the `# States` table — the flag informs whether the screen is *reachable*, but a one-company array never needs a picker to make sense of it |
| A `trusted_devices` row exists but has since been revoked (a user's "sign out of all other devices" action, `PROFILE.md → Managing sessions`, or a security team's forced revocation) | `PROFILE.md`'s bulk revocation and `AUTHENTICATION_API.md`'s admin-forced revocation are not specified as cascading into `trusted_devices` automatically; this document specifies that they should, exactly as a password change already cascades into revoking every refresh-token family — a device no longer trusted at the session layer must not remain trusted at the MFA-skip layer, or "sign out everywhere" would quietly leave a working bypass behind |
| A device previously trusted (`trust_device: true`) later trips `AUTHENTICATION_API.md`'s own unrelated device-risk marker (a materially different IP block on the same fingerprint hash) | The pre-existing device-risk step-up rule wins: a `device_risk` marker forces a fresh MFA challenge for sensitive actions regardless of the trusted-device flag, since trusting a device against replay of the *same* device is a narrower guarantee than "this specific network request is definitely low-risk" — the two mechanisms are complementary, not redundant, and this document does not let one silently override the other |
| The IP-based unauthenticated rate limit (`AUTHENTICATION_API.md`'s 30-failed-attempts/hour/IP throttle) is tripped by a shared office NAT rather than a single attacker | The response is IP-scoped `RATE_LIMITED`, distinct from the email-scoped `ACCOUNT_TEMPORARILY_LOCKED` — the UI renders a generic "Too many attempts from this network. Try again shortly." banner rather than the personalized lockout copy, since the platform cannot and should not imply a specific account was targeted; this is a deliberately less alarming message for a deliberately less certain situation |
| A user's authenticator app and QAYD's server clock have drifted (a stale phone clock, a VM with no NTP sync) | TOTP codes are time-derived and QAYD's server already accepts one step of clock skew in either direction per common TOTP practice; a code failing purely from drift looks identical, client-side, to any other wrong code — this document does not attempt to detect or explain clock drift specifically (the frontend cannot know the true cause of a wrong code), and instead relies on the "Use a backup code instead" link as the universal escape hatch regardless of *why* TOTP is failing |
| A very long bilingual company name overflows a `CompanySelectList` row at narrower widths | The row truncates with `truncate` and exposes the full name via the row's own `title` attribute, identical to `WorkspaceSwitcher`'s and `NAVIGATION_SYSTEM.md`'s long-name handling — this document reuses that exact pattern rather than inventing a second one for a visually similar list |
| A user's browser has autofill/password-manager suggestions that include an old, already-rotated password | This is expected and handled entirely server-side (an `INVALID_CREDENTIALS` response like any other wrong password); the frontend takes no special action beyond its ordinary error path, and specifically never attempts to detect or warn about "this looks like an old password," which QAYD has no way to verify without weakening its own password-storage guarantees |
| JavaScript fails to load or is disabled | Every form's action still falls back to a plain HTML form submission target is **not** provided by this document — QAYD's authentication flow, like the rest of the `(app)` product, requires JavaScript, and this is stated here explicitly rather than left implicit: a no-JS visitor sees the static server-rendered shell (heading, labeled fields) but cannot submit, which this document accepts as an intentional platform-wide constraint rather than a gap specific to this screen |
| The visual keyboard on a mobile device covers the active `InputOTP` field | The form panel's own scroll container (not the whole page) scrolls the focused input into view using the standard `scrollIntoView({ block: "center" })` behavior on focus, consistent with how any other QAYD form handles an on-screen keyboard obscuring a field on narrow viewports |
| A user's `next` deep link points at a route their eventual, resolved role cannot access | `/login`, `/mfa`, and `/select-company` never pre-validate `next` against a permission — permission checks belong entirely to the destination route's own server-side guard (`FRONTEND_ARCHITECTURE.md`'s Principle 4: hiding/redirecting here is a courtesy, the destination's own check is authoritative). The user is sent to `next`, and if that route's own access rules reject them, that route's own `403`/redirect handling takes over from there — this screen family's only job was getting them signed in, not pre-clearing every downstream permission |
| A support engineer needs to sign in as a user for troubleshooting | Impersonation is initiated entirely from an internal support tool, never from `/login` itself — there is no "sign in as" affordance anywhere in this screen family, matching `AUTHORIZATION_API.md`'s impersonation model, where the resulting session's persistent `ImpersonationBanner` (`FRONTEND_ARCHITECTURE.md → Impersonation banner`) only ever appears after the fact, inside `(app)` |

# End of Document
