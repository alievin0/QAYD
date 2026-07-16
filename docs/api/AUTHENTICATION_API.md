# Authentication API — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: AUTHENTICATION_API
---

# Purpose

The Authentication API is the single identity, credential, and access-token authority for the
entire QAYD platform. Every actor that ever calls `/api/v1/*` — the Next.js 15 web app, the
Flutter mobile app, the FastAPI AI engine, third-party integrations (banks, e-invoicing gateways,
accounting firms), and internal automation scripts — first passes through this module to obtain,
refresh, verify, or revoke a credential. Nothing in QAYD reaches PostgreSQL, Redis, or any
business table without first clearing this module's authentication and company-context checks.
The Accounting Engine, Sales, Purchasing, Inventory, Payroll, and every other module trust the
identity (`user_id`), tenant (`company_id`), role, and permission set that this module attaches to
every request; they perform no authentication logic of their own.

This document specifies, precisely and exhaustively, how QAYD:

1. Issues and verifies **JWT bearer tokens** (mobile app, third-party integrations, the AI engine,
   and any stateless API consumer).
2. Issues and verifies **Laravel Sanctum SPA session cookies** (the first-party Next.js web app)
   and **Sanctum personal access tokens** re-branded as **API Keys** (scripts, server-to-server
   integrations, Zapier-style connectors).
3. Acts as an **OAuth 2.1 authorization server** so third-party applications can request scoped,
   time-boxed access to one company's data with the company owner's consent, and separately acts
   as an **OAuth 2.0 client** so humans can sign in with Google or Microsoft SSO.
4. Issues, rotates, and revokes **refresh tokens**, with single-use rotation and replay detection.
5. Tracks and manages **session tokens** / active devices, including "sign out everywhere."
6. Enforces **multi-factor authentication** (TOTP, SMS OTP, backup codes, and optional WebAuthn)
   both at login and as step-up re-authentication before sensitive financial operations.
7. Rotates every category of credential on a defined schedule (signing keys, refresh tokens, API
   keys) and specifies exact **expiration** and **revocation** semantics for each.
8. Carries and switches the **active company context** (`X-Company-Id`) that every other module
   depends on for tenant isolation, since a single QAYD user (an accountant, an auditor, an
   external bookkeeper) commonly belongs to more than one company via `company_users`.

## Actors

| Actor | Credential type | Notes |
|---|---|---|
| Human user, Next.js web app | Sanctum SPA session cookie (`XSRF-TOKEN` + `qayd_session`) | Stateful, first-party only, CSRF-protected |
| Human user, Flutter mobile app | JWT access + refresh token pair | Stateless, offline-tolerant, biometric unlock re-uses cached refresh token |
| Human user, MFA-enrolled | Same as above + short-lived `mfa_token` during step-up | See `# MFA` |
| Script / server-to-server integration | API Key (`qyd_live_…` / `qyd_test_…`) | Sanctum personal access token under the hood, see `# API Keys` |
| Third-party application (e.g. a bank aggregator, an e-invoicing add-on) | OAuth 2.1 access + refresh token, scoped | QAYD is the Authorization Server, see `# OAuth` |
| Human user via SSO | Google/Microsoft `id_token` exchanged for a QAYD session | QAYD is the OAuth *client* here, see `# OAuth` |
| FastAPI AI engine | Internal **service JWT**, one per company context, client-credentials style | Never writes to Postgres directly; calls Laravel API like any other bearer client, permission-checked exactly like a human (Section 7 of the platform design context) |
| Support / Security admin | Sanctum session + `auth.session.manage_others` permission | Used only for forced revocation, never for silent impersonation without an `audit_logs` entry |

## Endpoint Inventory

All paths are relative to `https://api.qayd.app/api/v1/auth`. Every response — success or error —
uses the standard envelope defined in the platform design context, **with one explicit exception**
noted in `# OAuth` (the `oauth/token`, `oauth/introspect`, and `oauth/revoke` endpoints, which must
emit unwrapped RFC-compliant bodies for interoperability with generic OAuth client libraries).
This API never returns a bare `204 No Content`; deletions and revocations still return `200 OK`
with the full envelope and `"data": null`, because every response must carry `request_id` and
`timestamp` for traceability.

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | `/register` | public | Creates the first user + company (full spec owned by the Users/Onboarding module; referenced here only) |
| POST | `/login` | public | Password login; may return a full token pair or an `mfa_required` challenge |
| POST | `/refresh` | public (holder of a valid refresh token) | Rotates a refresh token for a new access + refresh pair |
| POST | `/logout` | self | Revokes the current session/refresh token/JWT family |
| POST | `/logout-all` | self | Revokes every session/token for the current user across all devices |
| GET | `/me` | self | Returns the authenticated identity, companies, active company, role, and permissions |
| POST | `/switch-company` | self (must hold `company_users` membership) | Re-issues a token/session scoped to a different company |
| GET | `/sessions` | self / `auth.session.manage_others` | Lists active sessions/devices |
| DELETE | `/sessions/{session_id}` | self / `auth.session.manage_others` | Revokes one session/device |
| DELETE | `/sessions` | self | Revokes every session except the caller's current one |
| POST | `/password/forgot` | public | Sends a password-reset email (full spec owned by Users module) |
| POST | `/password/reset` | public (holder of a valid reset token) | Consumes a reset token, sets a new password |
| POST | `/mfa/totp/enroll` | self | Begins TOTP enrollment, returns secret + QR |
| POST | `/mfa/totp/verify` | self | Confirms TOTP enrollment or verifies a step-up challenge |
| POST | `/mfa/sms/enroll` | self | Registers a phone number for SMS OTP |
| POST | `/mfa/sms/send` | self | Sends an OTP code to the enrolled phone |
| POST | `/mfa/verify` | self (holder of `mfa_token`) | Generic step-up verification used mid-login or before a sensitive action |
| GET | `/mfa/backup-codes` | self | Lists remaining (unused) backup code count |
| POST | `/mfa/backup-codes/regenerate` | self | Invalidates old backup codes, issues 10 new ones |
| DELETE | `/mfa/{factor_id}` | self / `auth.mfa.manage_others` | Removes an MFA factor |
| POST | `/mfa/webauthn/register/options` | self | Returns a WebAuthn registration challenge |
| POST | `/mfa/webauthn/register/verify` | self | Verifies and stores a WebAuthn credential |
| POST | `/api-keys` | `auth.apikey.create` | Creates a company-scoped API key |
| GET | `/api-keys` | `auth.apikey.read` | Lists API keys for the active company (never returns secrets) |
| GET | `/api-keys/{id}` | `auth.apikey.read` | Reads one API key's metadata |
| PATCH | `/api-keys/{id}` | `auth.apikey.create` | Renames or rescopes an API key |
| DELETE | `/api-keys/{id}` | `auth.apikey.revoke` | Revokes an API key immediately |
| GET | `/oauth/authorize` | public (interactive, requires a logged-in session) | OAuth 2.1 authorization endpoint (PKCE required) |
| POST | `/oauth/token` | public (client-authenticated) | OAuth 2.1 token endpoint (code exchange, refresh grant, client-credentials) |
| POST | `/oauth/introspect` | client-authenticated | RFC 7662 token introspection |
| POST | `/oauth/revoke` | client-authenticated | RFC 7009 token revocation |
| GET | `/oauth/clients` | `auth.oauth_client.manage` | Lists OAuth clients registered against the active company |
| POST | `/oauth/clients` | `auth.oauth_client.manage` | Registers a new OAuth client |
| DELETE | `/oauth/clients/{id}` | `auth.oauth_client.manage` | Revokes an OAuth client and all its grants |
| GET | `/sso/{provider}/redirect` | public | Redirects to Google/Microsoft for SSO login |
| GET | `/sso/{provider}/callback` | public | Handles the IdP callback, creates/links a `sso_identities` row, issues a QAYD session |
| GET | `/.well-known/jwks.json` | public | Publishes the current + overlapping public signing keys |

# JWT

## Purpose and Scope

JWT bearer tokens are the credential for every **stateless** API consumer: the Flutter mobile app,
third-party OAuth-authorized applications, and the FastAPI AI engine's internal service calls. The
Next.js SPA deliberately does **not** use JWTs for its own first-party session (it uses a Sanctum
cookie session, see `# Session Tokens`) because a cookie session lets QAYD revoke a browser session
instantly server-side, whereas a self-contained JWT is only as revocable as its denylist. JWTs are
issued for exactly the clients that need statelessness and cross-domain portability.

## Structure

QAYD signs access tokens as **RS256** JWTs (asymmetric), never HS256. HS256 would require every
verifying party — including the AI engine and any third-party OAuth resource server extension — to
hold the same shared secret capable of both signing and verifying, which is an unacceptable blast
radius if any single verifier is compromised. RS256 lets QAYD publish only the **public** key via
`/api/v1/auth/.well-known/jwks.json` and keep the private signing key inside the Laravel backend's
secrets vault exclusively.

**Header:**
```json
{
  "alg": "RS256",
  "typ": "JWT",
  "kid": "2026-07-01-a"
}
```

`kid` identifies which signing key produced the token, which is what makes zero-downtime key
rotation possible (see `# Token Rotation`) — a verifier fetches the JWKS document, finds the key
matching `kid`, and validates against it, even if the currently *active signing* key has since
rotated.

**Payload (claims):**
```json
{
  "iss": "https://api.qayd.app",
  "sub": "usr_9f21c3",
  "aud": "qayd-platform",
  "company_id": "cmp_4471",
  "role": "finance_manager",
  "perms_ver": 14,
  "sid": "sess_7a12ef98",
  "amr": ["pwd", "totp"],
  "token_use": "access",
  "iat": 1752650400,
  "nbf": 1752650400,
  "exp": 1752651300,
  "jti": "8b6e9e2b-df3d-4b8e-9c2e-9b6b9a1a5b40"
}
```

| Claim | Meaning |
|---|---|
| `iss` | Fixed issuer URL, always validated by every verifier |
| `sub` | `users.id`, the authenticated identity |
| `aud` | Fixed audience string per API surface (`qayd-platform` for the main API, `qayd-ai-internal` for AI-engine-issued service tokens) |
| `company_id` | The **active** company for this token; every downstream query filters by this value, never by a client-supplied one |
| `role` | Cached role slug within `company_id`, for fast authorization without a join; the permission table remains the source of truth on any mismatch |
| `perms_ver` | Monotonic version counter on the user's `company_users` row; bumped on every role/permission change so a stale token can be rejected with `PERMS_STALE` even before its natural `exp`, forcing a silent refresh |
| `sid` | Session/refresh-token-family identifier; used to revoke every access token that descends from one login in one action |
| `amr` | Authentication Methods References (RFC 8176-style) — records which factors were actually used (`pwd`, `totp`, `sms`, `webauthn`, `backup_code`), so a sensitive endpoint can require step-up if `amr` doesn't already contain a second factor |
| `token_use` | `"access"` vs the separate, shorter `"mfa"` token used only mid-challenge (see `# MFA`) |
| `jti` | Unique token ID, the denylist key on revocation |

Access tokens are deliberately short-lived (**15 minutes**, see `# Expiration`) precisely because
they are stateless and cannot be edited once issued; a 15-minute blast radius is the accepted cost
of statelessness, offset by rotation-on-refresh (`# Refresh Tokens`) and the `jti` denylist for the
rare case that immediate revocation is required inside that window.

## Signing Key Storage

```sql
CREATE TABLE jwt_signing_keys (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kid               VARCHAR(64) NOT NULL UNIQUE,
    algorithm         VARCHAR(16) NOT NULL DEFAULT 'RS256',
    public_key        TEXT NOT NULL,                 -- PEM, published via JWKS
    private_key_enc   TEXT NOT NULL,                  -- envelope-encrypted at rest (KMS-wrapped)
    status            VARCHAR(16) NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active','verify_only','retired')),
    activated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    verify_only_at    TIMESTAMPTZ NULL,
    retired_at        TIMESTAMPTZ NULL,
    created_by        BIGINT NULL REFERENCES users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX ux_jwt_signing_keys_single_active
    ON jwt_signing_keys (status) WHERE status = 'active';
```

Only one row may hold `status = 'active'` (the partial unique index enforces it); the currently
active key is the only one used to *sign* new tokens, while `verify_only` keys remain valid for
*verifying* tokens issued before the last rotation, until their overlap window elapses. This table
is deliberately global (no `company_id`) — signing keys are a platform-level secret, not a tenant
resource.

## Login (Issuing the First Token Pair)

```
POST /api/v1/auth/login
Content-Type: application/json
```
```json
{
  "email": "sara.alkandari@qayd-demo.com",
  "password": "••••••••••••",
  "device_name": "iPhone 15 Pro — Sara",
  "remember_me": false
}
```

Success (no MFA enrolled, or the device is already trusted — see `# MFA`):
```json
{
  "success": true,
  "data": {
    "status": "authenticated",
    "token_type": "Bearer",
    "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6IjIwMjYtMDctMDEtYSJ9…",
    "expires_in": 900,
    "refresh_token": "rft_9c2e1c7a4f2b4e2c8b9e9a6b1d4f2a7c.9d3f7b2a1e4c8f6a2b9d1e7c4a3f8b2e",
    "refresh_expires_in": 2592000,
    "user": {
      "id": "usr_9f21c3",
      "name": "Sara Al-Kandari",
      "email": "sara.alkandari@qayd-demo.com",
      "locale": "ar",
      "mfa_enrolled": true
    },
    "companies": [
      { "id": "cmp_4471", "name_en": "Al-Kandari Trading Co.", "name_ar": "شركة الكندري التجارية", "role": "finance_manager" },
      { "id": "cmp_5502", "name_en": "Gulf Fresh Foods W.L.L.", "name_ar": "مأكولات الخليج الطازجة", "role": "read_only" }
    ],
    "active_company_id": "cmp_4471"
  },
  "message": "Login successful.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9c6b2a3e-1f4d-4a7b-9e2c-6b1a4f8d2c9e",
  "timestamp": "2026-07-16T08:12:41Z"
}
```

When a user belongs to more than one company, QAYD picks the most-recently-active company as
`active_company_id` by default; the client is expected to call `/switch-company` if the user wants
a different one, rather than forcing a company picker before every login.

MFA-enrolled user, password correct, second factor required (see `# MFA` for the full flow):
```json
{
  "success": true,
  "data": {
    "status": "mfa_required",
    "mfa_token": "mft_1a7c9e3b2d4f8a6c9e1b4d7f2a8c6e9b",
    "mfa_token_expires_in": 300,
    "methods": ["totp", "sms", "backup_code"]
  },
  "message": "Multi-factor verification required.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3e7c9b2a-4f1d-4a8b-9e3c-6b2a4f9d1c8e",
  "timestamp": "2026-07-16T08:12:41Z"
}
```

This is returned as `200 OK` with `success: true`, not as an error, because an MFA challenge is an
expected, valid step of the protocol rather than a failure. Treating it as a hard error (`401`)
would force every client to special-case a "successful failure," which is worse ergonomics for the
Flutter and Next.js client SDKs QAYD ships.

Invalid credentials:
```json
{
  "success": false,
  "data": null,
  "message": "The provided credentials are incorrect.",
  "errors": [
    { "code": "INVALID_CREDENTIALS", "field": null, "detail": "Email or password is incorrect." }
  ],
  "meta": { "pagination": null },
  "request_id": "5d8b1a3f-2e4c-4b9a-8d1e-3f6a2b9c4d7e",
  "timestamp": "2026-07-16T08:13:02Z"
}
```
`HTTP 401`. QAYD never distinguishes "unknown email" from "wrong password" in the response body —
both collapse into `INVALID_CREDENTIALS` to avoid account enumeration.

Too many failed attempts:
```json
{
  "success": false,
  "data": null,
  "message": "Too many failed sign-in attempts. Try again in 12 minutes.",
  "errors": [
    { "code": "ACCOUNT_TEMPORARILY_LOCKED", "field": null, "detail": "Locked until 2026-07-16T08:27:02Z." }
  ],
  "meta": { "pagination": null },
  "request_id": "1f4a9c2b-3e7d-4a1b-9c2e-4f8a1b6c9d3e",
  "timestamp": "2026-07-16T08:15:02Z"
}
```
`HTTP 429` with a `Retry-After: 720` header. Lockout policy is tracked in `login_attempts`:

```sql
CREATE TABLE login_attempts (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email          VARCHAR(255) NOT NULL,
    ip_address     INET NOT NULL,
    user_agent     TEXT NULL,
    succeeded      BOOLEAN NOT NULL,
    failure_reason VARCHAR(32) NULL,
    attempted_at   TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_login_attempts_email_time ON login_attempts (email, attempted_at DESC);
CREATE INDEX ix_login_attempts_ip_time ON login_attempts (ip_address, attempted_at DESC);
```
Policy: 5 failed attempts for the same email within 15 minutes triggers a lockout that starts at 15
minutes and doubles on each subsequent lockout window up to a 4-hour ceiling; the counter resets on
a successful login. A parallel, coarser IP-based throttle (30 failed attempts/hour/IP across all
emails) catches credential-stuffing sweeps that spread attempts across many accounts.

## Verifying a JWT (Middleware)

```php
<?php

namespace App\Http\Middleware;

use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateJwt
{
    public function __construct(private JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized('MISSING_BEARER_TOKEN', 'Authorization header is required.');
        }

        try {
            $claims = $this->jwt->verify($token); // signature, iss, aud, exp/nbf (60s leeway), jti not denylisted
        } catch (\App\Exceptions\Auth\TokenExpiredException) {
            return $this->unauthorized('TOKEN_EXPIRED', 'Access token has expired.');
        } catch (\App\Exceptions\Auth\TokenRevokedException) {
            return $this->unauthorized('TOKEN_REVOKED', 'Access token has been revoked.');
        } catch (\Throwable) {
            return $this->unauthorized('INVALID_TOKEN', 'Access token is malformed or invalid.');
        }

        if ($claims->permsVer !== $request->attributes->get('current_perms_ver')
            ?? \App\Models\CompanyUser::permsVersionFor($claims->sub, $claims->companyId)) {
            return $this->unauthorized('PERMS_STALE', 'Permissions changed; refresh your token.');
        }

        $request->attributes->set('auth_user_id', $claims->sub);
        $request->attributes->set('auth_company_id', $claims->companyId);
        $request->attributes->set('auth_role', $claims->role);
        $request->attributes->set('auth_amr', $claims->amr);
        $request->attributes->set('auth_sid', $claims->sid);

        return $next($request);
    }

    private function unauthorized(string $code, string $detail): Response
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $detail,
            'errors' => [['code' => $code, 'field' => null, 'detail' => $detail]],
            'meta' => ['pagination' => null],
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'timestamp' => now()->toIso8601ZuluString(),
        ], 401);
    }
}
```

A companion middleware, `EnsureCompanyContext`, runs immediately after and cross-checks the
`X-Company-Id` request header (if the client sent one) against `auth_company_id` from the token: if
they disagree, the request is rejected with `403 COMPANY_CONTEXT_MISMATCH` rather than silently
trusting the header — the header is a convenience/assertion for the client and for logging, **the
JWT's `company_id` claim is always the actual source of truth** for row-level filtering.

## `GET /me`

```
GET /api/v1/auth/me
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
X-Company-Id: cmp_4471
```
```json
{
  "success": true,
  "data": {
    "user": {
      "id": "usr_9f21c3",
      "name": "Sara Al-Kandari",
      "email": "sara.alkandari@qayd-demo.com",
      "phone": "+965 5011 2233",
      "locale": "ar",
      "mfa_enrolled": true,
      "mfa_factors": ["totp", "backup_code"],
      "created_at": "2025-11-02T06:40:00Z"
    },
    "active_company": {
      "id": "cmp_4471",
      "name_en": "Al-Kandari Trading Co.",
      "name_ar": "شركة الكندري التجارية",
      "base_currency": "KWD",
      "role": "finance_manager",
      "permissions": [
        "accounting.read", "accounting.journal.create", "accounting.journal.post",
        "bank.reconcile", "reports.export", "auth.apikey.read"
      ]
    },
    "companies": [
      { "id": "cmp_4471", "name_en": "Al-Kandari Trading Co.", "role": "finance_manager" },
      { "id": "cmp_5502", "name_en": "Gulf Fresh Foods W.L.L.", "role": "read_only" }
    ],
    "session": { "sid": "sess_7a12ef98", "amr": ["pwd", "totp"], "issued_at": "2026-07-16T08:12:41Z" }
  },
  "message": "OK",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "2a7c9e1b-4f3d-4b8a-9e1c-6b4a2f8d9c1e",
  "timestamp": "2026-07-16T08:15:30Z"
}
```

`/me` is deliberately the one endpoint that returns the **full, resolved permission list**, not
just the role slug, so both the Next.js app and the Flutter app can render UI affordances without a
second round trip. It is intentionally cheap to call (cached for `perms_ver`'s TTL, invalidated the
instant a role changes) since every client calls it on cold start.

## AI Engine Service Tokens

The FastAPI AI engine authenticates to the Laravel API with its own JWTs, issued via a
client-credentials-style internal endpoint (`POST /api/v1/auth/oauth/token` with
`grant_type=client_credentials`, restricted to the internal `qayd-ai-engine` OAuth client — see
`# OAuth`). The resulting token carries `aud: "qayd-ai-internal"`, a `company_id` matching whichever
company's document or conversation the AI is currently processing, and a synthetic `role` of
`ai_service`. The permission set attached to `ai_service` is intentionally a **subset** of what any
human role can hold (read + draft-create only; never `*.post`, `*.approve`, `*.transfer`,
`*.release`, or `*.submit`), so the platform fact "AI never bypasses authorization and never writes
directly to the database" is enforced by the permission grant itself, not by application-level
trust in the caller.

# OAuth

QAYD plays **two distinct OAuth roles**, and this section documents both separately because
conflating them is the single most common design mistake in multi-tenant SaaS auth.

> **Envelope exception.** `POST /oauth/token`, `POST /oauth/introspect`, and `POST /oauth/revoke`
> are the only endpoints in the entire QAYD API that do **not** use the standard response envelope.
> They return the exact wire format defined by RFC 6749 §5.1, RFC 7662, and RFC 7009 respectively,
> because third-party integrations authenticate using off-the-shelf OAuth client libraries
> (`requests-oauthlib`, `simple_oauth2`, Postman's built-in OAuth 2.0 helper) that parse those exact
> top-level fields and would break against a wrapped `{ "success": true, "data": {...} }` body. Every
> *other* OAuth-adjacent endpoint (`/oauth/authorize` redirects, `/oauth/clients` management,
> `/sso/*`) uses the standard envelope as normal.

## Role 1 — QAYD as Authorization Server (third-party integrations)

A third party — a bank-feed aggregator, a government e-invoicing add-on, an independent developer's
Zapier-style connector — registers an **OAuth client** against one or more QAYD companies and asks
a company Owner or CFO to grant it a scoped, revocable, time-boxed grant. This is how integrations
work; it is deliberately heavier than an API Key because the credential is issued to *someone else's
software acting on the company's behalf with the company's explicit, revocable consent*, not to the
company's own scripts (which use API Keys instead, see `# API Keys`).

```sql
CREATE TABLE oauth_clients (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    name              VARCHAR(120) NOT NULL,
    client_id         VARCHAR(64) NOT NULL UNIQUE,
    client_secret_hash VARCHAR(255) NULL,      -- NULL for public/native clients (PKCE-only)
    redirect_uris     JSONB NOT NULL DEFAULT '[]',
    scopes            JSONB NOT NULL DEFAULT '[]',   -- subset of permission keys, e.g. ["accounting.read","reports.export"]
    is_confidential   BOOLEAN NOT NULL DEFAULT true,
    status            VARCHAR(16) NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','revoked')),
    created_by        BIGINT NULL REFERENCES users(id),
    updated_by        BIGINT NULL REFERENCES users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at        TIMESTAMPTZ NULL
);
CREATE INDEX ix_oauth_clients_company ON oauth_clients (company_id);

CREATE TABLE oauth_auth_codes (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id       BIGINT NOT NULL REFERENCES oauth_clients(id),
    user_id         BIGINT NOT NULL REFERENCES users(id),
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    code_hash       VARCHAR(255) NOT NULL,
    scopes          JSONB NOT NULL DEFAULT '[]',
    redirect_uri    TEXT NOT NULL,
    code_challenge  VARCHAR(128) NOT NULL,        -- PKCE, mandatory for every client, public or confidential
    code_challenge_method VARCHAR(8) NOT NULL DEFAULT 'S256',
    expires_at      TIMESTAMPTZ NOT NULL,          -- iat + 60 seconds
    consumed_at     TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_oauth_auth_codes_expiry ON oauth_auth_codes (expires_at);

CREATE TABLE oauth_access_tokens (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id       BIGINT NOT NULL REFERENCES oauth_clients(id),
    user_id         BIGINT NULL REFERENCES users(id),      -- NULL for client_credentials grants
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    token_hash      VARCHAR(255) NOT NULL UNIQUE,
    scopes          JSONB NOT NULL DEFAULT '[]',
    revoked_at      TIMESTAMPTZ NULL,
    expires_at      TIMESTAMPTZ NOT NULL,          -- iat + 1 hour
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_oauth_access_tokens_client ON oauth_access_tokens (client_id);
CREATE INDEX ix_oauth_access_tokens_company ON oauth_access_tokens (company_id);

CREATE TABLE oauth_refresh_tokens (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    access_token_id   BIGINT NOT NULL REFERENCES oauth_access_tokens(id),
    token_hash        VARCHAR(255) NOT NULL UNIQUE,
    revoked_at        TIMESTAMPTZ NULL,
    expires_at        TIMESTAMPTZ NOT NULL,        -- iat + 90 days
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

`company_id` is `NOT NULL` on every OAuth table because, unlike a human's own login (which spans
companies via `company_users`), a third-party grant is always issued **for one company's data,
consented to by that company**, matching the multi-tenancy rule in the platform design context.

### Authorization Code + PKCE flow

```
GET /api/v1/auth/oauth/authorize
    ?response_type=code
    &client_id=oac_7f2b9e41
    &redirect_uri=https://partner-app.example.com/oauth/callback
    &scope=accounting.read%20reports.export
    &state=b64_random_csrf_state
    &code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
    &code_challenge_method=S256
```
The user must already hold a QAYD session (Sanctum cookie) or is redirected to `/login` first. QAYD
renders a consent screen naming the requesting application, the exact scopes requested (mapped to
human-readable permission descriptions, e.g. `reports.export` → "Export your financial reports"),
and the target company. On approval, QAYD redirects:

```
HTTP/1.1 302 Found
Location: https://partner-app.example.com/oauth/callback
    ?code=ac_3f8b2e9c1a7d4f6b
    &state=b64_random_csrf_state
```
`redirect_uri` is validated against `oauth_clients.redirect_uris` by **exact string match**, never
by prefix or pattern, to close the open-redirect class of OAuth vulnerabilities. `state` is opaque
to QAYD and echoed back verbatim; QAYD does not require the caller to use it, but every client
library QAYD ships treats a missing or mismatched `state` as a hard CSRF failure.

Token exchange (unwrapped, RFC 6749 §5.1 — see the envelope exception above):
```
POST /api/v1/auth/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&code=ac_3f8b2e9c1a7d4f6b
&redirect_uri=https://partner-app.example.com/oauth/callback
&client_id=oac_7f2b9e41
&code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
```
```json
{
  "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6IjIwMjYtMDctMDEtYSJ9…",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "ort_2c9e4f1a7b3d8e6c9a2f4b1d7e8c3a9f",
  "scope": "accounting.read reports.export"
}
```
A confidential client additionally authenticates this request via HTTP Basic (`client_id`:
`client_secret`) or `client_secret_post`; a public/native client (mobile partner apps) omits the
secret entirely and relies solely on `code_verifier`/`code_challenge` — which is why PKCE is
**mandatory for every client type** in QAYD, not just public ones: it costs nothing for confidential
clients and closes the authorization-code-interception class of attacks universally.

Introspection (used by resource servers or the partner's own backend to validate a token they were
handed by their frontend):
```
POST /api/v1/auth/oauth/introspect
Authorization: Basic b2FjXzdmMmI5ZTQxOnNlY3JldA==
Content-Type: application/x-www-form-urlencoded

token=eyJhbGciOiJSUzI1NiIs…
```
```json
{
  "active": true,
  "scope": "accounting.read reports.export",
  "client_id": "oac_7f2b9e41",
  "company_id": "cmp_4471",
  "sub": "usr_9f21c3",
  "exp": 1752654000,
  "token_type": "Bearer"
}
```

Revocation (RFC 7009):
```
POST /api/v1/auth/oauth/revoke
Authorization: Basic b2FjXzdmMmI5ZTQxOnNlY3JldA==
Content-Type: application/x-www-form-urlencoded

token=ort_2c9e4f1a7b3d8e6c9a2f4b1d7e8c3a9f
&token_type_hint=refresh_token
```
Returns `200 OK` with an empty body per spec (the one place in this document a truly empty body is
correct, since RFC 7009 mandates it and the caller is a generic OAuth library, not a QAYD client).

### Managing OAuth Clients

```
POST /api/v1/auth/oauth/clients
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
X-Company-Id: cmp_4471
Content-Type: application/json
```
```json
{
  "name": "Boursa Kuwait Bank Feed Connector",
  "redirect_uris": ["https://bkbf.example.com/oauth/callback"],
  "scopes": ["bank.reconcile", "accounting.read"],
  "is_confidential": true
}
```
```json
{
  "success": true,
  "data": {
    "id": "oac_7f2b9e41",
    "name": "Boursa Kuwait Bank Feed Connector",
    "client_id": "oac_7f2b9e41",
    "client_secret": "cs_2b8f4e1a9c7d3f6b8e2a4c9f1d7b3e8a",
    "redirect_uris": ["https://bkbf.example.com/oauth/callback"],
    "scopes": ["bank.reconcile", "accounting.read"],
    "status": "active",
    "created_at": "2026-07-16T08:20:00Z"
  },
  "message": "OAuth client registered. Store the client secret now — it will not be shown again.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "7b2e9c4a-1f3d-4b8a-9e2c-6b4a1f9d2c8e",
  "timestamp": "2026-07-16T08:20:00Z"
}
```
Only `Owner` and `CFO` hold `auth.oauth_client.manage` (see the permission table under
`# Revocation`'s sibling sections and the consolidated table below) — registering a client that can
request `bank.reconcile` or `accounting.read` against live financial data is equivalent in
sensitivity to granting a new employee those permissions directly.

## Role 2 — QAYD as OAuth Client (Single Sign-On)

Separately, QAYD lets a human **sign in to QAYD** using their existing Google or Microsoft
Workspace/Entra ID account. Here QAYD is the *relying party*, not the authorization server.

```sql
CREATE TABLE sso_identities (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id           BIGINT NOT NULL REFERENCES users(id),
    provider          VARCHAR(16) NOT NULL CHECK (provider IN ('google','microsoft')),
    provider_subject  VARCHAR(255) NOT NULL,     -- the IdP's stable "sub" claim
    email_at_link     VARCHAR(255) NOT NULL,
    linked_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_login_at     TIMESTAMPTZ NULL,
    UNIQUE (provider, provider_subject)
);
CREATE INDEX ix_sso_identities_user ON sso_identities (user_id);
```

```
GET /api/v1/auth/sso/google/redirect
```
```
HTTP/1.1 302 Found
Location: https://accounts.google.com/o/oauth2/v2/auth?client_id=…&redirect_uri=https%3A%2F%2Fapi.qayd.app%2Fapi%2Fv1%2Fauth%2Fsso%2Fgoogle%2Fcallback&response_type=code&scope=openid%20email%20profile&state=…
```
```
GET /api/v1/auth/sso/google/callback?code=4/0Ab_…&state=…
```
QAYD exchanges the code with Google, verifies the returned `id_token`'s signature against Google's
own JWKS, matches `provider_subject` against `sso_identities`, and — if this is the first SSO login
for that email — either links it to an existing QAYD user with the same verified email or, per the
Users module's onboarding rules, creates one. QAYD then issues its **own** session exactly as
`/login` would (full JSON below uses the same `data` shape as the login success example under
`# JWT`, with `"amr": ["sso_google"]`). Google's tokens are never persisted or forwarded to the
client; only QAYD's own access/refresh tokens leave this endpoint.

## Security Notes

- Every authorization code is single-use (`consumed_at`) and expires in 60 seconds; reuse is treated
  identically to refresh-token reuse (`# Refresh Tokens`) — the entire grant is revoked and the
  client owner is notified via `notifications`.
- Scopes requested at `/oauth/authorize` must be a subset of `oauth_clients.scopes`; QAYD never
  silently grants more than the client was registered for, even if the user's own role would permit
  more.
- `oauth_access_tokens` are still JWTs (RS256, same JWKS as `# JWT`) so any resource server can
  verify them offline; `oauth_access_tokens`/`oauth_refresh_tokens` rows exist purely for revocation
  and introspection bookkeeping, not because the JWT itself is opaque.

# API Keys

## Purpose

API Keys are QAYD's credential for **the company's own** scripts, cron jobs, warehouse scanner
firmware, and simple server-to-server automation — as opposed to OAuth (`# OAuth`), which exists
for *someone else's* application acting under a consent grant. An API Key is issued by the company
itself, to itself, and never asks for user-facing consent because none is needed: the company that
creates the key already had the permission to perform the actions it grants.

Under the hood, API Keys **are** Laravel Sanctum personal access tokens. QAYD does not invent a
parallel token table; it extends Sanctum's own `personal_access_tokens` migration with QAYD-specific
columns:

```sql
-- Sanctum's own migration creates the base table:
CREATE TABLE personal_access_tokens (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tokenable_type  VARCHAR(255) NOT NULL,
    tokenable_id    BIGINT NOT NULL,
    name            VARCHAR(255) NOT NULL,
    token           VARCHAR(64) NOT NULL UNIQUE,     -- sha256 hash, never the plaintext
    abilities       TEXT NULL,
    last_used_at    TIMESTAMPTZ NULL,
    expires_at      TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- QAYD's migration adds the tenant + product columns:
ALTER TABLE personal_access_tokens
    ADD COLUMN company_id     BIGINT NULL REFERENCES companies(id),
    ADD COLUMN environment    VARCHAR(8) NOT NULL DEFAULT 'live' CHECK (environment IN ('live','test')),
    ADD COLUMN key_prefix     VARCHAR(16) NOT NULL,
    ADD COLUMN last_used_ip   INET NULL,
    ADD COLUMN created_by     BIGINT NULL REFERENCES users(id),
    ADD COLUMN revoked_at     TIMESTAMPTZ NULL;
CREATE INDEX ix_pat_company ON personal_access_tokens (company_id);
```

`company_id` here **is** `NOT NULL` in practice for every row created through `/api-keys` (the
column is nullable only because Sanctum also uses this table for other tokenable models the
platform may add later that are not company-scoped). Every API Key is created for, and only ever
authorizes access to, exactly one company — unlike a human's session, which can switch between
companies, an API Key never does; a company that needs the same integration against two companies
creates two separate keys.

`abilities` (Sanctum's native ability list) stores the same dotted permission keys used everywhere
else in QAYD (`accounting.read`, `reports.export`, …), so the exact same `Gate`/`Policy` checks that
authorize a human role authorize an API Key — there is no separate authorization code path.

## Key Format

Plaintext keys are shown **exactly once**, at creation time, and are never recoverable afterward —
only their sha256 hash and a display-safe prefix are stored:

```
qyd_live_51H8pQK2c9E4mZ8aX7bT3nR6vY1sD0wF9jL2kA5xC8bN4qP7rM3tV6yU1zW9eS2gH
qyd_test_51H8pQK2c9E4mZ8aX7bT3nR6vY1sD0wF9jL2kA5xC8bN4qP7rM3tV6yU1zW9eS2gH
```
The `qyd_live_`/`qyd_test_` prefix is a deliberate, Stripe-style convention: it lets a static
secret-scanner (and QAYD's own pre-merge CI hook) flag any live key accidentally committed to a
public repository, and it lets QAYD reject a `test` key outright on any endpoint that mutates real
financial data.

## Endpoints

```
POST /api/v1/auth/api-keys
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
X-Company-Id: cmp_4471
Content-Type: application/json
```
```json
{
  "name": "Nightly Bank Reconciliation Script",
  "environment": "live",
  "abilities": ["bank.read", "bank.reconcile", "accounting.read"],
  "expires_at": null
}
```
```json
{
  "success": true,
  "data": {
    "id": "pat_3a9c7e2b",
    "name": "Nightly Bank Reconciliation Script",
    "key_prefix": "qyd_live_51H8",
    "plaintext_key": "qyd_live_51H8pQK2c9E4mZ8aX7bT3nR6vY1sD0wF9jL2kA5xC8bN4qP7rM3tV6yU1zW9eS2gH",
    "environment": "live",
    "abilities": ["bank.read", "bank.reconcile", "accounting.read"],
    "expires_at": null,
    "created_at": "2026-07-16T08:25:00Z"
  },
  "message": "API key created. Copy the plaintext_key now — QAYD cannot show it again.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "4c9e2b7a-1f8d-4a3b-9e7c-2b4a9f1d8c3e",
  "timestamp": "2026-07-16T08:25:00Z"
}
```
`201 Created`. Listing (`GET /api-keys`) returns the same shape **minus** `plaintext_key`, replaced
by `key_prefix` only, for every row — the plaintext is genuinely never retrievable again, matching
how Sanctum stores only the hash.

```
DELETE /api/v1/auth/api-keys/pat_3a9c7e2b
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
X-Company-Id: cmp_4471
```
```json
{
  "success": true,
  "data": null,
  "message": "API key revoked.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "8e1c4a9b-2f7d-4b3a-9e1c-4a2b9f8d1c7e",
  "timestamp": "2026-07-16T08:26:10Z"
}
```
Revocation sets `revoked_at` and is checked on every request in addition to Sanctum's own
`expires_at` check; a revoked key returns `401 TOKEN_REVOKED` immediately, and any in-flight request
using it that has not yet reached the middleware is still rejected because the check happens
per-request, not once per process lifetime.

## Usage

```bash
curl https://api.qayd.app/api/v1/accounting/journal-entries \
  -H "Authorization: Bearer qyd_live_51H8pQK2c9E4mZ8aX7bT3nR6vY1sD0wF9jL2kA5xC8bN4qP7rM3tV6yU1zW9eS2gH" \
  -H "X-Company-Id: cmp_4471"
```
Note that an API Key request still requires `X-Company-Id`, and QAYD still cross-checks it against
the key's own `company_id` column (`# JWT`'s `EnsureCompanyContext` middleware applies uniformly
across JWTs, Sanctum sessions, and API Keys) — a key scoped to `cmp_4471` can never be used with
`X-Company-Id: cmp_5502`, even by mistake, even if the same human created both companies.

## Rate Limiting

Every API Key is subject to a Redis token-bucket limiter distinct from a human session's limiter:
120 requests/minute sustained, burst to 180, keyed on `pat_id` (not on IP, since many keys share a
data-center egress IP). Exceeding it returns `429` with `errors: [{ "code": "RATE_LIMITED" }]` and a
`Retry-After` header; the limiter configuration itself lives in the platform-wide Redis-backed rate
limiting infrastructure referenced in the platform design context, not reinvented here.

# Refresh Tokens

## Purpose

A refresh token is the long-lived credential that lets a client obtain new 15-minute access tokens
without asking the user to re-enter a password every quarter hour. QAYD refresh tokens are opaque
(not JWTs) precisely because they must be revocable with a simple database flag — an unrecognizable
random string that means nothing until matched against a hash in Postgres is trivially and
instantly revocable, whereas revoking a *self-contained* token has no equivalent of "just delete the
row." Making the refresh token opaque and the access token stateless is the standard, deliberate
split: statelessness where it buys performance (every accounting/sales/inventory request, 15-minute
exposure ceiling), statefulness where it buys control (the rarer refresh call, unbounded exposure
window otherwise).

## Storage and Rotation-on-Use

```sql
CREATE TABLE refresh_tokens (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id           BIGINT NOT NULL REFERENCES users(id),
    family_id         UUID NOT NULL,             -- constant across an entire rotation chain
    token_hash        VARCHAR(255) NOT NULL UNIQUE,     -- sha256(selector.verifier), never the plaintext
    device_name       VARCHAR(120) NULL,
    device_fingerprint VARCHAR(64) NULL,          -- hash of user_agent + coarse IP block, for step-up decisions
    ip_address        INET NULL,
    active_company_id BIGINT NULL REFERENCES companies(id),   -- last company this device authenticated into
    parent_id         BIGINT NULL REFERENCES refresh_tokens(id),  -- the token this one rotated from
    used_at           TIMESTAMPTZ NULL,           -- set the instant it is exchanged; NULL = still valid & unused
    revoked_at        TIMESTAMPTZ NULL,
    revoked_reason     VARCHAR(32) NULL CHECK (revoked_reason IN
                        ('logout','logout_all','reuse_detected','password_changed',
                         'mfa_reset','admin_revoked','offboarded','expired_cleanup')),
    expires_at        TIMESTAMPTZ NOT NULL,       -- issued_at + 30 days (sliding) or +90 days (remember_me)
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_refresh_tokens_family ON refresh_tokens (family_id);
CREATE INDEX ix_refresh_tokens_user ON refresh_tokens (user_id);
CREATE UNIQUE INDEX ux_refresh_tokens_hash ON refresh_tokens (token_hash);
```

There is deliberately no `company_id NOT NULL` here either, for the same reason as `# JWT`'s claims:
a refresh token authenticates a **user**, and the active company is a per-token-pair *claim*,
re-selected on every `/switch-company` call or carried forward from `active_company_id` on refresh.

**Rotation-on-use**: every call to `/refresh` immediately marks the presented token `used_at = now()`
and issues a brand-new refresh token row with the same `family_id` and `parent_id` pointing back to
the one just consumed. A refresh token is therefore single-use by construction — the moment it is
redeemed, redeeming it again is by definition a reuse.

```
POST /api/v1/auth/refresh
Content-Type: application/json
```
```json
{
  "refresh_token": "rft_9c2e1c7a4f2b4e2c8b9e9a6b1d4f2a7c.9d3f7b2a1e4c8f6a2b9d1e7c4a3f8b2e"
}
```
```json
{
  "success": true,
  "data": {
    "token_type": "Bearer",
    "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6IjIwMjYtMDctMDEtYSJ9…",
    "expires_in": 900,
    "refresh_token": "rft_2f8b4e1a9c7d3f6b8e2a4c9f1d7b3e8a.4e1c9b2a7f3d8e6c1a9b4f2d7e8c3a9f",
    "refresh_expires_in": 2592000
  },
  "message": "Token refreshed.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "6a2c9e4b-1f7d-4a8b-9e2c-4b9a1f7d8c2e",
  "timestamp": "2026-07-16T08:27:41Z"
}
```

## Reuse Detection

If a refresh token whose `used_at` is already set is presented again, QAYD treats this as evidence
that the token was intercepted and both the legitimate client and an attacker are now racing to use
it — the standard signal for **refresh token theft**. The response:

```json
{
  "success": false,
  "data": null,
  "message": "This refresh token has already been used. All sessions for this device chain have been revoked as a precaution.",
  "errors": [
    { "code": "REFRESH_TOKEN_REUSE_DETECTED", "field": null, "detail": "Token family sess_7a12ef98 revoked." }
  ],
  "meta": { "pagination": null },
  "request_id": "3b8e1c9a-4f2d-4a7b-9e1c-2b4a9f8d1c7e",
  "timestamp": "2026-07-16T08:28:00Z"
}
```
`HTTP 409`. The handler, on detecting `used_at IS NOT NULL`, immediately revokes **every** row
sharing that `family_id` (`revoked_reason = 'reuse_detected'`), invalidates every access token
descending from that `sid` via the `jti` denylist for whatever remains of their 15-minute window,
writes an `audit_logs` entry, and enqueues a `notifications` row ("New sign-in activity" /
suspicious-activity email) to the user with the device name, IP, and approximate location of both
the original and the replaying request. The user must sign in from scratch on every device in that
family; QAYD trades convenience for certainty here deliberately, because refresh-token theft is a
higher-value attacker outcome than a password guess (it survives a password change unless the
family is also killed, which is exactly what a password change does — see `# Revocation`).

```
Legitimate device                 QAYD                          Attacker (stole rft #1)
      |--- refresh(rft #1) ------->|                                    |
      |<--- access + rft #2 -------|                                    |
      |  (rft #1.used_at = now)    |                                    |
      |                            |<--------- refresh(rft #1) ---------|
      |                            |  used_at already set -> REUSE      |
      |                            |  revoke family_id (rft #1, #2, …)  |
      |<---------------------- notification: "new sign-in, was this you?" |
```

## Device Binding and Sliding Expiration

`device_fingerprint` is a stable hash of the User-Agent and a coarse (/24 or /48) IP block, computed
at issuance and re-checked on every refresh. A mismatch does not fail the refresh outright (mobile
IPs change constantly on cellular networks, which would make the feature useless) but it does flag
the resulting access token's `amr` with a synthetic `"device_risk"` marker that `# MFA`'s step-up
guard treats as equivalent to a missing second factor for sensitive endpoints, forcing a fresh MFA
challenge before, say, `bank.transfer` or `payroll.release` even though the session itself is still
valid for ordinary reads.

Expiration is **sliding**: each successful rotation resets `expires_at` to `issued_at + 30 days`
(or `+90 days` if the original login set `remember_me: true`), but only up to an **absolute** cap of
180 days from the very first token in the family (`# Expiration` documents the exact precedence).
An idle family — no refresh call for 30/90 days straight — simply expires and the next `/refresh`
call returns `401 REFRESH_TOKEN_EXPIRED`, requiring a full `/login`.

# Session Tokens

## Two Session Models, One Reason

QAYD deliberately runs two different session mechanisms side by side:

| | Sanctum SPA session (Next.js web) | JWT + refresh pair (Flutter, integrations) |
|---|---|---|
| Storage | Server-side `sessions` table (or Redis session driver) | Client-held bearer tokens |
| Revocation | Instant — delete the row | Bounded by 15-minute access-token TTL + `jti` denylist for immediate cases |
| CSRF exposure | Yes (cookie-based) — mitigated by `XSRF-TOKEN` double-submit | No (no ambient cookie authority) |
| Cross-domain use | No — first-party origin only | Yes — designed for it |
| Typical lifetime | 2 weeks idle / 30 days absolute | 15 min access / 30–90 days refresh |

The web app is first-party and same-origin with the API's cookie domain, so a stateful,
instantly-revocable cookie session is strictly better there: there is no reason to accept a JWT's
revocation-latency trade-off when nothing about the web app needs statelessness. Everything that
*does* need statelessness (offline mobile use, third-party resource servers validating a token
without calling back to QAYD) uses `# JWT` instead.

## Sanctum SPA Flow

```
GET /sanctum/csrf-cookie
```
Sets the `XSRF-TOKEN` cookie (Next.js's `axios`/`fetch` client reads this and echoes it as the
`X-XSRF-TOKEN` header on every subsequent mutating request — Sanctum's standard double-submit CSRF
protection for cookie-based SPA sessions). Then:
```
POST /api/v1/auth/login
Cookie: XSRF-TOKEN=…
X-XSRF-TOKEN: …
Content-Type: application/json
```
```json
{ "email": "sara.alkandari@qayd-demo.com", "password": "••••••••••••", "device_name": "Chrome — MacBook Pro" }
```
On success, Laravel sets an `HttpOnly`, `Secure`, `SameSite=Lax` session cookie
(`qayd_session=…`) scoped to `api.qayd.app`; the response body uses the identical envelope and
`data` shape shown under `# JWT`'s login example, **without** an `access_token`/`refresh_token` pair
— the cookie itself is the credential for every subsequent request from that browser tab. Sanctum
resolves the cookie to a `sessions` row server-side on every request; there is no client-visible
token to rotate or leak.

```sql
-- Laravel's own sessions table, extended with QAYD's device/company bookkeeping:
CREATE TABLE sessions (
    id              VARCHAR(255) PRIMARY KEY,
    user_id         BIGINT NULL REFERENCES users(id),
    active_company_id BIGINT NULL REFERENCES companies(id),
    device_name     VARCHAR(120) NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      TEXT NULL,
    payload         TEXT NOT NULL,
    last_activity   INTEGER NOT NULL
);
CREATE INDEX ix_sessions_user ON sessions (user_id);
CREATE INDEX ix_sessions_last_activity ON sessions (last_activity);
```

## Managing Active Sessions/Devices

```
GET /api/v1/auth/sessions
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
X-Company-Id: cmp_4471
```
```json
{
  "success": true,
  "data": [
    { "id": "sess_7a12ef98", "device_name": "iPhone 15 Pro — Sara", "kind": "jwt", "ip_address": "37.36.12.4",
      "location_guess": "Kuwait City, KW", "last_active_at": "2026-07-16T08:27:41Z",
      "created_at": "2026-07-14T19:02:00Z", "is_current": true, "amr": ["pwd","totp"] },
    { "id": "sess_2b7e9c14", "device_name": "Chrome — MacBook Pro", "kind": "sanctum_session", "ip_address": "37.36.12.4",
      "location_guess": "Kuwait City, KW", "last_active_at": "2026-07-15T14:10:22Z",
      "created_at": "2026-07-10T09:00:00Z", "is_current": false, "amr": ["pwd","totp"] }
  ],
  "message": "OK",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 2, "cursor": null } },
  "request_id": "9a1c4e2b-3f8d-4b7a-9e1c-2b9a4f8d1c3e",
  "timestamp": "2026-07-16T08:30:00Z"
}
```
```
DELETE /api/v1/auth/sessions/sess_2b7e9c14
```
Revokes exactly that one session/refresh family (its `refresh_tokens.family_id` is fully revoked,
its Sanctum `sessions` row deleted). `DELETE /api/v1/auth/sessions` (no id) revokes **every** session
except the caller's own current one — the "sign out of all other devices" affordance shown after a
password change or a security scare, without also logging the user themself out mid-action.

## Concurrent Session Policy

| Role | Max concurrent devices | Idle timeout | Notes |
|---|---|---|---|
| Owner, CEO, CFO | Unlimited | 2 weeks | High-trust roles; unlimited by design, but every new device triggers a `notifications` alert |
| Finance Manager, Senior Accountant, Accountant | 5 | 2 weeks | Oldest session is force-revoked when a 6th login occurs |
| Auditor, External Auditor | 2 | 4 hours | Deliberately tight — auditors are typically short-engagement, high-scrutiny accounts |
| HR Manager, Payroll Officer | 3 | 1 week | Payroll sensitivity warrants tighter idle timeout than general accounting roles |
| Inventory Manager, Warehouse Employee | 3 | 8 hours | See shared-terminal note below |
| Sales/Purchasing Manager or Employee | 5 | 2 weeks | Standard |
| Read Only | Unlimited | 2 weeks | No write capability regardless of session count |

**Shared warehouse terminals**: a `Warehouse Employee` frequently shares a barcode-scanner terminal
across a shift. Rather than forcing full `/login` + MFA for every handoff, QAYD supports a
company-configurable **kiosk PIN mode** for that role only: the terminal holds one long-lived
device-bound refresh token (`device_name = "Warehouse Scanner #3"`), and each worker authenticates a
short 4–6 digit PIN (a dedicated, low-privilege `mfa` factor type scoped to `stock_movements`/
`stock_counts` writes only) that swaps `sub`/`created_by` attribution on the access token without a
new full session. The underlying device session is still subject to the same 8-hour idle timeout and
still shows up in `/sessions` as one row per company, auditable like any other.

## Switching Company Context

```
POST /api/v1/auth/switch-company
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
Content-Type: application/json
```
```json
{ "company_id": "cmp_5502" }
```
```json
{
  "success": true,
  "data": {
    "token_type": "Bearer",
    "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6IjIwMjYtMDctMDEtYSJ9…",
    "expires_in": 900,
    "active_company": {
      "id": "cmp_5502", "name_en": "Gulf Fresh Foods W.L.L.", "role": "read_only",
      "permissions": ["accounting.read", "reports.export"]
    }
  },
  "message": "Switched to Gulf Fresh Foods W.L.L.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1e9c4a2b-3f7d-4b8a-9e2c-4a1b9f7d2c8e",
  "timestamp": "2026-07-16T08:32:00Z"
}
```
The **refresh token is not reissued** on switch — only a fresh access token, scoped to the new
`company_id`, `role`, and `permissions`. This keeps the device's long-lived credential company-
agnostic while every short-lived access token is unambiguously single-company, so no accounting or
inventory query anywhere in the platform ever has to reason about "which of the user's companies did
this row belong to" — it is always exactly the one in the token that authenticated the request.
Attempting to switch into a company the user has no `company_users` row for returns `403
COMPANY_ACCESS_DENIED`, and attempting it for a company where the row exists but is `status =
'suspended'` (e.g., mid-offboarding) returns `403 COMPANY_MEMBERSHIP_SUSPENDED`.

# MFA

## Purpose and Enforcement Policy

Multi-factor authentication in QAYD serves two distinct moments: **login-time MFA** (proving who
you are before any session exists at all) and **step-up MFA** (re-proving it mid-session,
immediately before a specific sensitive action, regardless of how recently the session itself was
authenticated). The platform design context is explicit that bank transfers, payroll release, tax
submission, deletion/voiding of posted financial data, permission changes, and company settings
changes require a human approval chain and are never AI-only; step-up MFA is the mechanism that
makes "human approval" mean something stronger than "a session cookie that happened to still be
valid."

| Role | MFA required at login | Step-up required before sensitive ops |
|---|---|---|
| Owner, CEO, CFO | Mandatory | Always |
| Finance Manager, Senior Accountant | Mandatory | Always |
| Accountant | Mandatory | Only for `*.post`, `*.void` |
| Auditor, External Auditor | Mandatory | N/A (read-only role holds no sensitive-write permissions) |
| HR Manager, Payroll Officer | Mandatory | Always (payroll release) |
| Inventory Manager | Mandatory | For `stock_adjustments.approve` |
| Warehouse Employee | Optional (kiosk PIN mode substitutes, see `# Session Tokens`) | N/A |
| Sales/Purchasing Manager | Mandatory | For discount overrides beyond policy threshold |
| Sales/Purchasing Employee | Optional, company-configurable | N/A |
| Read Only | Optional, company-configurable | N/A |

"Mandatory" is enforced server-side by a company-level security policy flag checked at `/login`
(`companies.security_policy->>'mfa_required_roles'`) — QAYD never trusts a client to simply not skip
the MFA screen.

## Factors Supported

```sql
CREATE TABLE mfa_factors (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id),
    type            VARCHAR(16) NOT NULL CHECK (type IN ('totp','sms','backup_codes','webauthn')),
    label           VARCHAR(120) NULL,                  -- e.g. "iPhone — Google Authenticator", "YubiKey 5C"
    secret_enc      TEXT NULL,                            -- TOTP shared secret, envelope-encrypted
    phone_number    VARCHAR(32) NULL,                     -- E.164, for type = 'sms'
    webauthn_credential_id VARCHAR(255) NULL,
    webauthn_public_key    TEXT NULL,
    webauthn_sign_count    BIGINT NULL,
    backup_codes_hash JSONB NULL,                          -- array of sha256 hashes, one per unused code
    verified_at     TIMESTAMPTZ NULL,                      -- NULL until the enrollment challenge is confirmed
    last_used_at    TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at      TIMESTAMPTZ NULL
);
CREATE INDEX ix_mfa_factors_user ON mfa_factors (user_id) WHERE revoked_at IS NULL;

CREATE TABLE mfa_challenges (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT NOT NULL REFERENCES users(id),
    factor_id       BIGINT NULL REFERENCES mfa_factors(id),
    purpose         VARCHAR(16) NOT NULL CHECK (purpose IN ('login','step_up','enroll')),
    code_hash       VARCHAR(255) NULL,                     -- for SMS OTP; TOTP/WebAuthn verify without a stored code
    attempts        SMALLINT NOT NULL DEFAULT 0,
    max_attempts    SMALLINT NOT NULL DEFAULT 5,
    expires_at      TIMESTAMPTZ NOT NULL,                  -- +300 seconds
    consumed_at     TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX ix_mfa_challenges_expiry ON mfa_challenges (expires_at);
```

`user_id`-scoped, not `company_id`-scoped, for the same reason as refresh tokens and sessions — a
person's fingerprint, authenticator app, and phone number belong to them, not to any one company
they happen to work for.

## TOTP Enrollment

```
POST /api/v1/auth/mfa/totp/enroll
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
```
```json
{
  "success": true,
  "data": {
    "factor_id": "mfa_8c2e4b9a",
    "secret": "JBSWY3DPEHPK3PXP",
    "otpauth_uri": "otpauth://totp/QAYD:sara.alkandari@qayd-demo.com?secret=JBSWY3DPEHPK3PXP&issuer=QAYD&algorithm=SHA1&digits=6&period=30",
    "qr_svg_data_uri": "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcv…"
  },
  "message": "Scan the QR code with your authenticator app, then confirm with a 6-digit code.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "2c9e4b7a-1f8d-4a3b-9e2c-7b4a9f1d8c3e",
  "timestamp": "2026-07-16T08:35:00Z"
}
```
`mfa_factors.verified_at` remains `NULL` until confirmed:
```
POST /api/v1/auth/mfa/totp/verify
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
```
```json
{ "factor_id": "mfa_8c2e4b9a", "code": "482913" }
```
```json
{
  "success": true,
  "data": { "factor_id": "mfa_8c2e4b9a", "type": "totp", "verified": true,
            "backup_codes": ["7f3k-9d2m","2a8p-6q1x","5n4v-8r3z","1t9c-4y7b","6e2h-3w8k",
                              "9j5r-1v6d","4c8n-7s2m","3q1f-9x5t","8w6b-2k4p","5r7g-1n3z"] },
  "message": "TOTP enabled. Store these 10 backup codes somewhere safe — each works once.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "8a1c9e4b-2f7d-4b3a-9e1c-9a2b4f7d1c8e",
  "timestamp": "2026-07-16T08:35:40Z"
}
```
The same `/mfa/totp/verify` endpoint doubles as the **step-up/login verification** call when
`factor_id` is omitted and an `mfa_token` is supplied instead (see below) — one code path, two
callers, distinguished by which credential accompanies the request.

## Login Step-Up (the `mfa_token` handshake)

Continuing the `mfa_required` response shown under `# JWT`:
```
POST /api/v1/auth/mfa/verify
Content-Type: application/json
```
```json
{
  "mfa_token": "mft_1a7c9e3b2d4f8a6c9e1b4d7f2a8c6e9b",
  "method": "totp",
  "code": "482913"
}
```
```json
{
  "success": true,
  "data": {
    "status": "authenticated",
    "token_type": "Bearer",
    "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCIsImtpZCI6IjIwMjYtMDctMDEtYSJ9…",
    "expires_in": 900,
    "refresh_token": "rft_4d8a2b9c1e7f3a6b9d2c8e1a4f7b3d9c.7a1e4c9b2f8d3a6c1e9b4f2a7d8c3e1b",
    "refresh_expires_in": 2592000,
    "active_company_id": "cmp_4471"
  },
  "message": "Login successful.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "4f8a1c9e-2b7d-4a3c-9e1b-8a2c4f9d1e7b",
  "timestamp": "2026-07-16T08:36:00Z"
}
```
The issued access token's `amr` now reads `["pwd", "totp"]`, satisfying every endpoint that requires
two factors. A wrong code decrements `mfa_challenges.attempts`; exhausting `max_attempts` (5)
invalidates the `mfa_token` entirely and forces the client back to `/login`:
```json
{
  "success": false, "data": null,
  "message": "Too many incorrect codes. Please sign in again.",
  "errors": [{ "code": "MFA_CHALLENGE_EXHAUSTED", "field": null, "detail": null }],
  "meta": { "pagination": null }, "request_id": "1b9a4c2e-3f8d-4b7a-9e2c-1b4a9f8d2c3e",
  "timestamp": "2026-07-16T08:37:10Z"
}
```
`HTTP 401`.

## SMS OTP and Backup Codes

```
POST /api/v1/auth/mfa/sms/enroll
```
```json
{ "phone_number": "+96551122334" }
```
Sends a one-time verification SMS through the platform's outbound SMS gateway (abstracted behind a
`NotificationChannel` interface so the underlying provider is swappable without touching this API
contract) and creates an unverified `mfa_factors` row of `type = 'sms'`. Thereafter:
```
POST /api/v1/auth/mfa/sms/send
```
sends a fresh 6-digit code (hashed into `mfa_challenges.code_hash`, 5-minute expiry, rate-limited to
one send per 60 seconds per user to prevent SMS-bombing), and the same generic `/mfa/verify` endpoint
consumes it with `"method": "sms"`.

Backup codes are ten single-use 9-character codes generated at TOTP enrollment (shown above) and
independently regenerable:
```
POST /api/v1/auth/mfa/backup-codes/regenerate
```
```json
{
  "success": true,
  "data": { "backup_codes": ["3x8k-1p4m","9d2r-6q7z", "…8 more…"] },
  "message": "Previous backup codes are now invalid. Store the new set safely.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "7a2c9e1b-4f8d-4b3c-9e1a-2b7c4f9d8e1a", "timestamp": "2026-07-16T08:38:20Z"
}
```
Regenerating invalidates every previously issued code immediately (`backup_codes_hash` is replaced
wholesale, not appended to), so a leaked-but-unused old code list can never be quietly reused later.

## WebAuthn (Hardware Keys) — Owner/CFO Advanced Factor

For the highest-trust roles, QAYD supports registering a WebAuthn/FIDO2 authenticator (a YubiKey, a
platform authenticator like Touch ID/Windows Hello) as an alternative or additional factor:
```
POST /api/v1/auth/mfa/webauthn/register/options
```
returns a standard `PublicKeyCredentialCreationOptions` challenge (relying party ID
`api.qayd.app`, `userVerification: "required"`); the client performs the browser/OS ceremony and
posts the attestation to `/mfa/webauthn/register/verify`, which validates the attestation signature
and stores `webauthn_credential_id`/`webauthn_public_key`/`webauthn_sign_count`. Assertion at login
or step-up follows the same generic `/mfa/verify` path with `"method": "webauthn"` and the signed
assertion in place of a numeric `code`; `webauthn_sign_count` is checked to strictly increase on
every use as WebAuthn's native clone-detection mechanism.

## Removing a Factor

```
DELETE /api/v1/auth/mfa/mfa_8c2e4b9a
```
A user may remove their own factor only if at least one other **verified** factor remains when MFA
is mandatory for their role (QAYD refuses to leave a mandatory-MFA account with zero factors,
returning `422 LAST_MFA_FACTOR_REQUIRED`). `HR Manager`/`Owner`/`CFO` additionally hold
`auth.mfa.manage_others`, used exclusively for offboarding or a verified lost-device recovery case —
every such removal is itself an `audit_logs` entry and triggers a mandatory re-enrollment prompt on
the affected user's next login.

# Token Rotation

QAYD rotates four independent categories of credential, on four independent schedules, and this
section is the consolidated reference for all of them — the per-category mechanics are detailed in
their own sections above; this is the operational summary.

| Credential | Rotation trigger | Cadence / policy | Overlap / grace |
|---|---|---|---|
| JWT signing key (`jwt_signing_keys`) | Scheduled + emergency | Every 90 days, automatic | 14 days `verify_only` before `retired` |
| Refresh token (`refresh_tokens`) | Every use (rotation-on-use) | Per `/refresh` call | None — prior token is immediately `used_at`-marked; reuse is theft signal |
| API Key (`personal_access_tokens`) | Manual only | Whenever the company chooses | Company-managed: create the new key, deploy it, then revoke the old one — QAYD supports both being simultaneously active during a migration window |
| OAuth access/refresh token | Refresh-grant exchange | Access: 1 hour; refresh: 90 days | Standard OAuth refresh-grant semantics, no QAYD-specific overlap |

## JWT Signing Key Rotation (Scheduled)

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->job(new RotateJwtSigningKey)->cron('0 3 1 */3 *'); // quarterly, 03:00 UTC
    $schedule->job(new RetireExpiredSigningKeys)->daily();
    $schedule->job(new PruneExpiredRefreshTokens)->daily();
    $schedule->job(new PruneExpiredMfaChallenges)->hourly();
}
```

```php
final class RotateJwtSigningKey implements ShouldQueue
{
    public function handle(): void
    {
        DB::transaction(function () {
            $current = JwtSigningKey::where('status', 'active')->lockForUpdate()->first();
            $current?->update(['status' => 'verify_only', 'verify_only_at' => now()]);

            $new = JwtSigningKey::create([
                'kid' => now()->format('Y-m-d').'-'.Str::lower(Str::random(1)),
                'algorithm' => 'RS256',
                ...KeyPairGenerator::generateRsa4096(),
                'status' => 'active',
            ]);

            Cache::forget('jwks.document'); // force JWKS regeneration on next fetch
        });
    }
}
```

Zero-downtime sequence:
```
t=0     key A active, signs all new tokens
t=0     RotateJwtSigningKey runs -> key A becomes verify_only, key B becomes active
t=0..*  every in-flight token signed by A (issued before t=0, up to 15 min old) still verifies fine,
        because A is still published in the JWKS document and matched by its `kid`
t=0+14d key A retires -> removed from the JWKS document; any token still claiming kid=A
        (which would have to be >14 days past its 15-minute exp — impossible under normal operation,
         only reachable via a forged/backdated token) is rejected with INVALID_TOKEN
```
This is why the 14-day overlap so vastly exceeds the 15-minute access-token TTL: it is not sized for
access tokens (which age out in minutes regardless), it is sized so that **any consumer's cached copy
of the JWKS document** (third-party resource servers are expected to cache it, not fetch it on every
request) has ample time to refresh before the key it's relying on disappears. QAYD recommends a
10-minute JWKS cache TTL to consumers via `Cache-Control: max-age=600` on the JWKS response, which
leaves over 2,000x margin against the 14-day retirement window.

## Emergency Rotation (Compromise Response)

If a signing key's private key material is ever suspected compromised, the runbook skips the
overlap entirely: `RotateJwtSigningKey` runs immediately out-of-schedule, and the compromised key
moves straight to `retired` (not `verify_only`) in the same transaction — instantly invalidating
every access token signed by it, regardless of its stated `exp`. Because this forces every active
user session to re-authenticate simultaneously (there is no graceful path around it — that is the
entire point of an emergency rotation), it is deliberately a manual, break-glass action gated behind
a platform super-admin permission, never automated.

## API Key Rotation (Manual, Overlap-Friendly)

Unlike signing keys, API Keys rotate on the company's own schedule and are designed to support a
clean migration: create the replacement key (`POST /api-keys`), deploy it into whatever script or
integration used the old one, confirm the new key is seeing traffic (`last_used_at` advancing), then
revoke the old one (`DELETE /api-keys/{id}`). Both keys are valid simultaneously for as long as the
company wants — QAYD imposes no forced overlap ceiling here because, unlike a signing key, a single
compromised API Key's blast radius is already contained to one company and one ability set.

## Refresh Token Rotation — Cross-Reference

Already fully specified under `# Refresh Tokens`: single-use, family-linked, reuse triggers full
family revocation. Included here only for completeness of this section's inventory.

# Expiration

## Master TTL Table

| Artifact | Default TTL | Absolute ceiling | Configurable per company? |
|---|---|---|---|
| Access token (JWT) | 15 minutes | — | No — fixed platform-wide |
| Refresh token (web/default) | 30 days sliding | 180 days from first issuance in the family | No |
| Refresh token (`remember_me: true`, mobile) | 90 days sliding | 180 days from first issuance in the family | No |
| `mfa_token` (login step-up challenge) | 5 minutes | — | No |
| `mfa_challenges` SMS/TOTP verify window | 5 minutes | — | No |
| Sanctum SPA session cookie | 2 weeks idle | 30 days absolute | Yes — company security policy may tighten, never loosen |
| Email verification token | 24 hours | — | No |
| Password reset token | 60 minutes, single-use | — | No |
| API Key | None by default | Optional `expires_at` settable at creation | Yes — company may mandate a max TTL for all its keys |
| OAuth authorization code | 60 seconds, single-use | — | No |
| OAuth access token | 1 hour | — | No |
| OAuth refresh token | 90 days | — | No |
| JWT signing key (active -> verify_only) | 90 days | — | No (platform-level secret) |
| JWT signing key (verify_only -> retired) | 14 days after rotation | — | No |
| Account lockout (failed logins) | 15 min, doubling to 4h ceiling | — | No |
| `login_attempts` retention | 90 days (then purged by `PruneExpiredRefreshTokens`-adjacent job) | — | No |

## Clock Skew and Enforcement Point

All `exp`/`nbf` checks apply a **60-second leeway** in both directions to tolerate clock drift
between the issuing server and any distributed verifier (the AI engine's FastAPI pods, a
third-party OAuth resource server). Enforcement happens exclusively in the `AuthenticateJwt` /
Sanctum guard middleware layer, before any controller executes — no downstream service is ever
trusted to independently re-validate expiration, since that would create N slightly-different
implementations of the same security-critical check.

An expired access token returns:
```json
{
  "success": false,
  "data": null,
  "message": "Your session has expired. Please refresh your token.",
  "errors": [{ "code": "TOKEN_EXPIRED", "field": null, "detail": "exp 2026-07-16T08:12:00Z has passed." }],
  "meta": { "pagination": null },
  "request_id": "9c2e4a1b-3f7d-4b8a-9e1c-2b9a4f1d7c3e",
  "timestamp": "2026-07-16T08:27:05Z"
}
```
`HTTP 401`. This is the single most common error response in the entire API surface by volume — it
is expected to fire roughly every 15 minutes per active client — and every QAYD-authored SDK (PHP,
JS/TS, Python, Flutter/Dart) implements automatic, transparent retry-after-refresh specifically for
`TOKEN_EXPIRED`, so application code essentially never observes it directly.

## Precedence When Multiple Ceilings Apply

A refresh token's effective expiration on any given day is
`MIN(sliding_window_from_last_use, absolute_ceiling_from_first_issuance)`. Concretely, a
`remember_me` mobile refresh chain that has been rotated weekly for 200 days is **not** still valid
merely because each individual rotation reset a fresh 90-day sliding window — the 180-day absolute
ceiling (tracked via `family_id` → looking up the `created_at` of the *first* row in that family,
never the most recent) forces re-authentication regardless. This prevents a session from becoming
permanently un-expirable purely through continuous background refresh activity a user is not
actively aware of.

## Per-Company Security Policy Overrides

Only *tightening* is permitted, never loosening, and only on the specific fields listed:
```json
{
  "security_policy": {
    "mfa_required_roles": ["owner","cfo","finance_manager","senior_accountant","accountant","hr_manager","payroll_officer","inventory_manager","sales_manager","purchasing_manager"],
    "session_idle_timeout_minutes": 240,
    "max_concurrent_sessions": { "auditor": 1, "external_auditor": 1 },
    "api_key_max_ttl_days": 90
  }
}
```
`External Auditor`/`Auditor` engagements commonly set `session_idle_timeout_minutes` far below the
platform default of two weeks (down to a few hours) precisely because those are the shortest-tenure,
highest-scrutiny accounts on the platform — an idle auditor session is pure unnecessary risk surface.

# Revocation

## Reasons and Cascades

| Trigger | What gets revoked | Mechanism |
|---|---|---|
| User clicks "Log out" | Current session/refresh-token family only | `POST /logout` |
| User clicks "Log out everywhere" | Every session/refresh-token family for that user, all companies | `POST /logout-all` |
| Password changed | Every refresh-token family **except** none — all, including the one that just changed the password; user must sign in again with the new password | Automatic, inside the password-change transaction |
| MFA factor reset/removed by self or admin | Every refresh-token family for that user (a changed trust boundary invalidates existing sessions) | Automatic |
| Admin/security force-revoke | One targeted session, or all sessions for a targeted user | `POST /auth/revoke` (requires `auth.session.manage_others`) |
| Suspected token theft (refresh reuse) | The entire affected `family_id` | Automatic, see `# Refresh Tokens` |
| Employee offboarded from a company (`company_users` row set inactive) | Every access token's future validity for that `company_id` (via `perms_ver` bump forcing `PERMS_STALE`), plus any API Key with that `company_id` created by that user is flagged for the company's admins to review | Automatic on offboarding, cascaded |
| API Key explicitly revoked | That key only | `DELETE /api-keys/{id}` |
| OAuth client revoked | Every access/refresh token issued to that client, across every company that granted it | `DELETE /oauth/clients/{id}`, cascades through `oauth_access_tokens`/`oauth_refresh_tokens` |
| OAuth token explicitly revoked by the third party | That token only (RFC 7009) | `POST /oauth/revoke` |

## Mechanism per Token Type

Revocation looks different depending on whether the credential is stateless or stateful, and this
is the one area where the trade-off made in `# JWT`/`# Refresh Tokens` becomes operationally
concrete:

- **JWT access tokens** are self-contained; QAYD cannot "delete" one. Instant revocation is achieved
  by writing its `jti` into a Redis denylist (`revoked:jti:<jti>` → `1`, with a TTL exactly equal to
  the token's remaining lifetime, so the denylist entry expires naturally the moment the token would
  have expired anyway and never grows unbounded). `AuthenticateJwt` checks this denylist on every
  request in addition to signature/exp validation. For anything **not** requiring sub-15-minute
  revocation latency (the overwhelming majority of cases — logout, password change, offboarding),
  QAYD does not bother with the denylist at all and instead relies on the much simpler `perms_ver`
  bump / refresh-token-family revocation, since the access token will naturally expire within 15
  minutes regardless.
- **Refresh tokens, Sanctum sessions, API Keys, OAuth tokens** are all row-backed; revocation is a
  single `UPDATE … SET revoked_at = now()` (or, for Sanctum sessions, a `DELETE`), checked on every
  use. This is instantaneous and requires no denylist.

## Logout Endpoints

```
POST /api/v1/auth/logout
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…
```
```json
{
  "success": true,
  "data": null,
  "message": "Logged out.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "4b9a2c1e-3f8d-4b7a-9e1c-4a9b2f8d1c3e",
  "timestamp": "2026-07-16T08:40:00Z"
}
```
Revokes the presented token's `refresh_tokens.family_id` (or deletes the Sanctum `sessions` row) and
writes its `jti` to the Redis denylist for the remainder of its natural life, closing the small
window where the just-logged-out access token would otherwise keep working for up to 15 more
minutes.

```
POST /api/v1/auth/logout-all
```
Same envelope shape; iterates every `refresh_tokens` row and every `sessions` row for `user_id`
across every company, revoking all of them and denylisting every currently-unexpired `jti` derived
from any of their `sid` values.

## Admin-Initiated Revocation

```
POST /api/v1/auth/revoke
Authorization: Bearer eyJhbGciOiJSUzI1NiIs…   (caller holds auth.session.manage_others)
X-Company-Id: cmp_4471
Content-Type: application/json
```
```json
{ "target_user_id": "usr_2b7e9c14", "scope": "all_sessions", "reason": "Employee offboarded 2026-07-16" }
```
```json
{
  "success": true,
  "data": { "revoked_sessions": 3, "revoked_refresh_families": 2, "revoked_api_keys": 1 },
  "message": "All sessions and keys revoked for usr_2b7e9c14.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "2b9a4c1e-3f7d-4b8a-9e2c-2b4a9f1d8c3e",
  "timestamp": "2026-07-16T08:41:15Z"
}
```
This call **requires** a non-empty `reason`, which is written verbatim into `audit_logs` alongside
the caller's own identity, IP, and timestamp — QAYD never allows a silent forced revocation, even
one performed by an Owner against their own CFO's session, because both the target and every other
admin on the company can subsequently see exactly who revoked what and why via the audit trail.

## Domain Event and Notification

Every revocation — self-initiated or admin-initiated — fires an `auth.token.revoked` webhook (per
the platform's standard domain-event list) carrying `{ user_id, company_id, scope, reason,
revoked_by }`, and enqueues a `notifications` row to the affected user unless the revocation *was*
that user's own explicit logout action (no one needs to be told they logged themselves out).
Suspicious-context revocations (reuse detection, admin force-revoke, MFA reset) additionally send an
email/SMS alert, not just an in-app notification, since the affected user may not have any
authenticated session left to see an in-app one.

# Examples

## Example 1 — Full Login With MFA Step-Up, Company Switch, Refresh, Logout

```bash
# 1. Password login
curl -X POST https://api.qayd.app/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"sara.alkandari@qayd-demo.com","password":"correct-horse-battery-staple","device_name":"iPhone 15 Pro — Sara"}'
```
```json
{ "success": true, "data": { "status": "mfa_required", "mfa_token": "mft_1a7c9e3b2d4f8a6c9e1b4d7f2a8c6e9b", "mfa_token_expires_in": 300, "methods": ["totp","sms","backup_code"] }, "message": "Multi-factor verification required.", "errors": [], "meta": { "pagination": null }, "request_id": "req_001", "timestamp": "2026-07-16T08:12:41Z" }
```
```bash
# 2. Submit TOTP code against the mfa_token
curl -X POST https://api.qayd.app/api/v1/auth/mfa/verify \
  -H "Content-Type: application/json" \
  -d '{"mfa_token":"mft_1a7c9e3b2d4f8a6c9e1b4d7f2a8c6e9b","method":"totp","code":"482913"}'
```
```json
{ "success": true, "data": { "status": "authenticated", "token_type": "Bearer", "access_token": "eyJ...A", "expires_in": 900, "refresh_token": "rft_A.verA", "refresh_expires_in": 2592000, "active_company_id": "cmp_4471" }, "message": "Login successful.", "errors": [], "meta": { "pagination": null }, "request_id": "req_002", "timestamp": "2026-07-16T08:12:55Z" }
```
```bash
# 3. Authenticated request against the default company
curl https://api.qayd.app/api/v1/accounting/journal-entries?per_page=25 \
  -H "Authorization: Bearer eyJ...A" -H "X-Company-Id: cmp_4471"
```
```bash
# 4. Switch to the second company
curl -X POST https://api.qayd.app/api/v1/auth/switch-company \
  -H "Authorization: Bearer eyJ...A" -H "Content-Type: application/json" \
  -d '{"company_id":"cmp_5502"}'
```
```json
{ "success": true, "data": { "token_type": "Bearer", "access_token": "eyJ...B", "expires_in": 900, "active_company": { "id": "cmp_5502", "name_en": "Gulf Fresh Foods W.L.L.", "role": "read_only", "permissions": ["accounting.read","reports.export"] } }, "message": "Switched to Gulf Fresh Foods W.L.L.", "errors": [], "meta": { "pagination": null }, "request_id": "req_003", "timestamp": "2026-07-16T08:20:00Z" }
```
```bash
# 5. 15 minutes later, eyJ...B has expired -> refresh
curl -X POST https://api.qayd.app/api/v1/auth/refresh \
  -H "Content-Type: application/json" -d '{"refresh_token":"rft_A.verA"}'
```
```json
{ "success": true, "data": { "token_type": "Bearer", "access_token": "eyJ...C", "expires_in": 900, "refresh_token": "rft_A.verB", "refresh_expires_in": 2592000 }, "message": "Token refreshed.", "errors": [], "meta": { "pagination": null }, "request_id": "req_004", "timestamp": "2026-07-16T08:35:10Z" }
```
Note the refreshed access token (`eyJ...C`) carries `company_id: cmp_4471` again, **not** `cmp_5502`
— refresh restores the device's last *persisted* `active_company_id` on the refresh-token row, which
only `/switch-company` updates; a switch without a subsequent refresh is scoped to that one access
token only, by design (see `# Session Tokens`).
```bash
# 6. Logout
curl -X POST https://api.qayd.app/api/v1/auth/logout -H "Authorization: Bearer eyJ...C"
```
```json
{ "success": true, "data": null, "message": "Logged out.", "errors": [], "meta": { "pagination": null }, "request_id": "req_005", "timestamp": "2026-07-16T09:00:00Z" }
```

## Example 2 — Third-Party OAuth Integration, Authorization Code + PKCE, End to End

```bash
# 1. Partner app generates a PKCE pair and redirects the user's browser
#    code_verifier = dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
#    code_challenge = S256(code_verifier) = E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
open "https://api.qayd.app/api/v1/auth/oauth/authorize?response_type=code&client_id=oac_7f2b9e41&redirect_uri=https://bkbf.example.com/oauth/callback&scope=bank.reconcile%20accounting.read&state=xyz123&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256"
# 2. User (already logged in) approves the consent screen -> QAYD redirects:
#    https://bkbf.example.com/oauth/callback?code=ac_3f8b2e9c1a7d4f6b&state=xyz123
# 3. Partner backend exchanges the code
curl -X POST https://api.qayd.app/api/v1/auth/oauth/token \
  -u "oac_7f2b9e41:cs_2b8f4e1a9c7d3f6b8e2a4c9f1d7b3e8a" \
  -d "grant_type=authorization_code&code=ac_3f8b2e9c1a7d4f6b&redirect_uri=https://bkbf.example.com/oauth/callback&code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"
```
```json
{ "access_token": "eyJ...D", "token_type": "Bearer", "expires_in": 3600, "refresh_token": "ort_2c9e4f1a7b3d8e6c9a2f4b1d7e8c3a9f", "scope": "bank.reconcile accounting.read" }
```
```bash
# 4. Partner calls the API using the access token, exactly like any bearer client
curl https://api.qayd.app/api/v1/banking/bank-transactions \
  -H "Authorization: Bearer eyJ...D" -H "X-Company-Id: cmp_4471"
# 5. An hour later, the partner refreshes
curl -X POST https://api.qayd.app/api/v1/auth/oauth/token \
  -u "oac_7f2b9e41:cs_2b8f4e1a9c7d3f6b8e2a4c9f1d7b3e8a" \
  -d "grant_type=refresh_token&refresh_token=ort_2c9e4f1a7b3d8e6c9a2f4b1d7e8c3a9f"
```
```json
{ "access_token": "eyJ...E", "token_type": "Bearer", "expires_in": 3600, "refresh_token": "ort_9d1c4b8a2f7e3d6c9a1b4f8d2c7e3a9b", "scope": "bank.reconcile accounting.read" }
```
```bash
# 6. Company later revokes the integration entirely
curl -X DELETE https://api.qayd.app/api/v1/auth/oauth/clients/oac_7f2b9e41 \
  -H "Authorization: Bearer eyJ...A" -H "X-Company-Id: cmp_4471"
```

## Example 3 — API Key Creation, Scripted Usage, Rotation, Revocation

```bash
# 1. Create a live key scoped narrowly to bank reconciliation
curl -X POST https://api.qayd.app/api/v1/auth/api-keys \
  -H "Authorization: Bearer eyJ...A" -H "X-Company-Id: cmp_4471" -H "Content-Type: application/json" \
  -d '{"name":"Nightly Bank Reconciliation Script","environment":"live","abilities":["bank.read","bank.reconcile","accounting.read"]}'
# -> plaintext_key returned once: qyd_live_51H8pQK2c9E4mZ8aX7bT3nR6vY1sD0wF9jL2kA5xC8bN4qP7rM3tV6yU1zW9eS2gH

# 2. Cron job uses it nightly
curl https://api.qayd.app/api/v1/banking/bank-reconciliations \
  -H "Authorization: Bearer qyd_live_51H8pQK2c9E4mZ8aX7bT3nR6vY1sD0wF9jL2kA5xC8bN4qP7rM3tV6yU1zW9eS2gH" \
  -H "X-Company-Id: cmp_4471"

# 3. Six months later, rotate: create the replacement first
curl -X POST https://api.qayd.app/api/v1/auth/api-keys \
  -H "Authorization: Bearer eyJ...A" -H "X-Company-Id: cmp_4471" -H "Content-Type: application/json" \
  -d '{"name":"Nightly Bank Reconciliation Script (2026-Q3)","environment":"live","abilities":["bank.read","bank.reconcile","accounting.read"]}'
# deploy the new key into the cron job, confirm last_used_at is advancing, THEN:

# 4. Revoke the old key
curl -X DELETE https://api.qayd.app/api/v1/auth/api-keys/pat_3a9c7e2b \
  -H "Authorization: Bearer eyJ...A" -H "X-Company-Id: cmp_4471"
```

## Example 4 — Refresh Token Theft and Automatic Family Revocation

```bash
# Legitimate device refreshes normally
curl -X POST https://api.qayd.app/api/v1/auth/refresh -d '{"refresh_token":"rft_A.verA"}'
# -> success, returns rft_A.verB, marks rft_A.verA as used_at
```
```bash
# An attacker who intercepted rft_A.verA in transit before it was consumed tries it after the fact
curl -X POST https://api.qayd.app/api/v1/auth/refresh -d '{"refresh_token":"rft_A.verA"}'
```
```json
{ "success": false, "data": null, "message": "This refresh token has already been used. All sessions for this device chain have been revoked as a precaution.", "errors": [{ "code": "REFRESH_TOKEN_REUSE_DETECTED", "field": null, "detail": "Token family sess_7a12ef98 revoked." }], "meta": { "pagination": null }, "request_id": "req_009", "timestamp": "2026-07-16T09:10:00Z" }
```
`HTTP 409`. The legitimate device's *next* refresh attempt (using `rft_A.verB`, which is now also
revoked as part of the family) fails with `REFRESH_TOKEN_REVOKED`, forcing a fresh `/login` — by
design, since QAYD cannot distinguish which of the two callers was legitimate once reuse is
detected, and defaults to trusting neither.

## Example 5 — Consolidated Endpoint-to-Error Matrix

| Scenario | Endpoint | HTTP | `errors[0].code` |
|---|---|---|---|
| Wrong password | `POST /login` | 401 | `INVALID_CREDENTIALS` |
| 5 failed attempts | `POST /login` | 429 | `ACCOUNT_TEMPORARILY_LOCKED` |
| Missing bearer token | any protected route | 401 | `MISSING_BEARER_TOKEN` |
| Access token past `exp` | any protected route | 401 | `TOKEN_EXPIRED` |
| Access token `jti` denylisted | any protected route | 401 | `TOKEN_REVOKED` |
| Role/permission changed since issuance | any protected route | 401 | `PERMS_STALE` |
| `X-Company-Id` disagrees with token claim | any protected route | 403 | `COMPANY_CONTEXT_MISMATCH` |
| Switch to a company with no membership | `POST /switch-company` | 403 | `COMPANY_ACCESS_DENIED` |
| Switch to a suspended membership | `POST /switch-company` | 403 | `COMPANY_MEMBERSHIP_SUSPENDED` |
| Refresh token expired | `POST /refresh` | 401 | `REFRESH_TOKEN_EXPIRED` |
| Refresh token reused | `POST /refresh` | 409 | `REFRESH_TOKEN_REUSE_DETECTED` |
| MFA code wrong, attempts remain | `POST /mfa/verify` | 422 | `MFA_CODE_INVALID` |
| MFA attempts exhausted | `POST /mfa/verify` | 401 | `MFA_CHALLENGE_EXHAUSTED` |
| Remove last mandatory MFA factor | `DELETE /mfa/{id}` | 422 | `LAST_MFA_FACTOR_REQUIRED` |
| API Key over rate limit | any route, API Key auth | 429 | `RATE_LIMITED` |
| Test-mode key on a live-only endpoint | any mutating route | 403 | `TEST_KEY_NOT_ALLOWED` |
| OAuth redirect_uri not an exact match | `GET /oauth/authorize` | 400 | `INVALID_REDIRECT_URI` |
| OAuth authorization code reused | `POST /oauth/token` | 400 | `INVALID_GRANT` (RFC-shaped, unwrapped) |

# End of Document

