# Authentication & Identity Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: AUTH_SERVICE
---

# Purpose

This document specifies the **Authentication & Identity Service** — the backend module that proves
*who a caller is*, mints and revokes the credentials that carry that identity, and resolves the
permission set the caller holds in the active company. It is the implementation of the login,
session, token, MFA, API-key, role, and approval surfaces that the security folder describes
adversarially in [../security/AUTHENTICATION.md](../security/AUTHENTICATION.md) and
[../security/AUTHORIZATION.md](../security/AUTHORIZATION.md), and the mechanism behind the login and
registration UX in [../foundation/AUTHENTICATION.md](../foundation/AUTHENTICATION.md).

Where [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) defines the shared Action/Service shape and
[BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) defines the request lifecycle, this document
instantiates both for the `identity` module. It is the single most security-sensitive module in the
platform after Accounting: every other module trusts that by the time a Service runs, this module has
already established a real user, a real company membership, and a real, current permission set. That
trust is only safe because this module is deny-by-default, fail-closed, and server-authoritative in
every path.

The scope split is deliberate and rigorous. **Authentication identifies; it grants nothing.** A
perfect login yields an authenticated principal holding zero permissions until authorization resolves
a grant, per request, against the active company. This service owns *both* halves — identity and the
resolution of grants — but keeps them as two separate decisions that never leak into one another, so
that access is never a side effect of proving identity.

Everything here is Laravel 12 / PHP 8.4+ with **Laravel Sanctum** per
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md). The web SPA authenticates with first-party
same-site session cookies; mobile, partner, and the AI engine authenticate with bearer tokens. No
pattern in this document requires a package outside the authoritative stack.

# Responsibilities

The service owns the identity lifecycle end to end. Concretely, it is responsible for:

- **Registration and email verification** — creating a `users` row, sending and consuming the
  verification token, and enforcing the rule that an unverified user cannot create a company.
- **Two authentication models, chosen by client type** — the Sanctum stateful cookie session for the
  Next.js web SPA, and RS256-signed JWT access tokens plus opaque rotating refresh tokens for the
  Flutter mobile app, partner integrations, and the FastAPI AI engine.
- **Credential issuance, rotation, and single-request revocation** — sessions, access/refresh token
  pairs, MFA step-up credentials, and API keys, each killable within one request.
- **Multi-factor authentication** — TOTP, SMS, WebAuthn/passkey factors and single-use recovery
  codes, the enrollment flow, and the `mfa_pending → verified` step-up handshake.
- **Brute-force defense** — sliding-window rate limiting and bounded exponential-backoff lockout on
  the login, MFA, and reset endpoints, recorded in `login_attempts`.
- **Company membership and the active-company switch** — reading `company_users` to know which
  companies a user may act in, and issuing a re-scoped credential when they switch.
- **Permission resolution** — composing the effective permission set from a user's role plus custom
  grants in the active company, cached and invalidated by a `perms_ver` counter.
- **Role and permission administration** — the CRUD behind `roles`, `role_permissions`, and per-user
  custom grants, including the maker-checker approvals that govern permission changes.
- **Programmatic access** — issuing prefixed, hashed-at-rest personal access tokens / API keys with a
  confined ability set, and the OAuth2/OIDC client and SSO-identity records for enterprise login.
- **Signing-key custody at the service boundary** — the `jwt_signing_keys` rotation and the public
  JWKS endpoint that lets any verifier validate an RS256 token by `kid`.

What the service is **not** responsible for: the *policy* of who gets which role (the customer owns
that, per the shared-responsibility posture in [../security/SECURITY_ARCHITECTURE.md](../security/SECURITY_ARCHITECTURE.md)),
the tenant *boundary* resolution once identity is known (that is the `ResolveTenantCompany` middleware
and RLS, see [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md)), and custody/rotation of the
signing key material itself (deferred to secrets management). This module consumes those; it does not
own them.

# Domain Model

The identity domain is a small graph of long-lived principals and short-lived credentials. The
principals (user, company membership, role) change rarely and are authoritative; the credentials
(session, token pair, MFA challenge, API key) are numerous, expiring, and revocable.

```
                          ┌──────────────┐
                          │    users     │  identity, password hash, email-verified
                          └──────┬───────┘
                                 │ 1..*
                    ┌────────────┼───────────────┬───────────────┬──────────────┐
                    ▼            ▼               ▼               ▼              ▼
            ┌──────────────┐ ┌──────────┐ ┌──────────────┐ ┌──────────────┐ ┌────────────┐
            │ company_users│ │mfa_factors│ │personal_     │ │ sso_identities│ │ sessions   │
            │ (membership) │ │           │ │access_tokens │ │ (idp linkage) │ │ (web,Redis)│
            └──────┬───────┘ └──────────┘ └──────────────┘ └──────────────┘ └────────────┘
                   │ role_id + custom grants
                   ▼
            ┌──────────────┐   role_permissions   ┌──────────────┐
            │    roles     │◀────────────────────▶│ permissions  │
            └──────────────┘                       └──────────────┘

  refresh_tokens ── family/rotation ──▶ jwt_signing_keys (RS256, kid) ──▶ /.well-known/jwks
  login_attempts (sliding window)      mfa_challenges (pending step-up)   auth_approvals (maker-checker)
```

The load-bearing relationships:

- A **user** is global to the platform (one email, one password, one MFA enrollment) and belongs to
  zero or more companies through `company_users`. Identity is not company-scoped; *membership* is.
- A **company membership** (`company_users`) pins the user's `role_id` in that company plus any
  per-user custom permission grants and denials, plus the branch/department visibility scope. The same
  user is an `Accountant` in Company A and a `CFO` in Company B through two membership rows.
- A **role** is a named bundle of permissions inside one company (system-seeded defaults plus custom
  roles). `role_permissions` is the join; `permissions` is the platform-wide catalogue keyed by the
  `<area>.<action>` / `<area>.<entity>.<action>` grammar.
- The **resolved permission set** is the deterministic composition
  `role_permissions ∪ custom_grants − custom_denies`, computed for a (user, company) pair and cached
  under a `perms_ver` counter that is bumped on any role or grant mutation so a change is effective on
  the next request.
- A **credential** — a web session, a JWT `jti`, a refresh-token family, an MFA challenge, or an API
  key — maps to a durable record whose revocation is checked on the request critical path.

Two value objects recur across the module: `Credential` (the issued thing: type, subject user, active
company, abilities, expiry, `jti`/session-id) and `ResolvedPermissions` (the immutable, `perms_ver`-
stamped set a request is authorized against). Neither is ever an array in the Application layer.

# Key Classes

The module follows the Action-by-default rule from [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md):
single-purpose Actions for use cases, Services where operations share internals (the token mint/verify
routine, the permission resolver). Controllers stay thin; every entrypoint takes a DTO.

```php
namespace App\Actions\Identity;

use App\Data\Identity\LoginData;
use App\Domain\Identity\Credential;
use App\Events\Identity\UserLoggedIn;
use App\Exceptions\Identity\InvalidCredentialsException;
use App\Services\Identity\LoginThrottleService;
use App\Services\Identity\MfaService;
use App\Services\Identity\TokenService;
use App\Repositories\Identity\UserRepository;
use Illuminate\Support\Facades\Hash;

/** Verify a password, apply throttle/lockout, and issue either a full or an mfa_pending credential. */
final class LoginAction
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginThrottleService $throttle,
        private readonly MfaService $mfa,
        private readonly TokenService $tokens,
    ) {}

    public function execute(LoginData $data): Credential
    {
        $this->throttle->assertNotLocked($data->email, $data->ip);   // sliding window + backoff

        $user = $this->users->findByEmail($data->email);

        // Constant-time even on a missing user: hash a dummy so timing never reveals existence.
        if ($user === null || ! Hash::check($data->password, $user->password_hash)) {
            $this->throttle->recordFailure($data->email, $data->ip);  // → login_attempts
            throw new InvalidCredentialsException();                  // 401 invalid_credentials
        }

        $this->throttle->recordSuccess($data->email, $data->ip);

        // Step up if the user's role in *any* target company requires MFA, or MFA is enrolled.
        if ($this->mfa->isRequiredFor($user)) {
            return $this->tokens->issueMfaPending($user, $data->clientType);  // ability: auth:mfa_pending
        }

        $credential = $this->tokens->issueFull($user, $data->clientType, $data->deviceFingerprint);
        event(new UserLoggedIn($user->id, $data->clientType, $data->ip));
        return $credential;
    }
}
```

The core collaborators:

- **`LoginAction`, `RefreshTokenAction`, `LogoutAction`, `LogoutAllAction`** — the credential
  lifecycle use cases. `RefreshTokenAction` performs rotation-on-use with reuse detection.
- **`RegisterUserAction`, `VerifyEmailAction`, `RequestPasswordResetAction`, `ResetPasswordAction`** —
  account creation and recovery. `ResetPasswordAction` revokes every existing credential on success.
- **`SwitchCompanyAction`** — validates `company_users` membership, regenerates the session id (web) or
  re-mints a company-scoped access token (bearer), and re-resolves permissions for the new company.
- **`TokenService`** — the single place that mints and verifies bearer credentials: RS256 sign/verify
  by `kid`, `jti` denylist checks, refresh-family rotation, device-fingerprint binding.
- **`MfaService`** — enrollment (TOTP secret, WebAuthn registration, recovery-code generation), the
  `mfa_pending` step-up handshake, and the per-factor `mfa_challenges` verification.
- **`PermissionResolver`** — resolves and caches `ResolvedPermissions` for a (user, company) pair and
  invalidates on `perms_ver` bump. This is the object `User::canInCompany()` delegates to.
- **`ApiKeyService`** — issues `qyd_live_…` / `qyd_test_…` keys, hashes them at rest, and confines
  their ability set so a key can never carry a sensitive `.approve` / `.release` / `.submit` ability.
- **`RoleService` and the maker-checker `AuthApprovalService`** — role/permission administration and
  the two-key approval that gates permission changes.

`PermissionResolver` is the hot path — it runs behind every authorization check in every module — so
its shape matters:

```php
namespace App\Services\Identity;

use App\Domain\Identity\ResolvedPermissions;
use App\Repositories\Identity\MembershipRepository;
use Illuminate\Support\Facades\Cache;

final class PermissionResolver
{
    public function __construct(private readonly MembershipRepository $memberships) {}

    /** The set a request is authorized against — role perms ∪ custom grants − custom denies. */
    public function resolve(int $userId, int $companyId): ResolvedPermissions
    {
        $membership = $this->memberships->activeFor($userId, $companyId);   // company_users row, or null
        if ($membership === null) {
            return ResolvedPermissions::empty();   // no membership ⇒ zero permissions (deny by default)
        }

        // Cache keyed by the perms_ver counter so any role/grant mutation is effective next request.
        $key = "perms:{$companyId}:{$userId}:v{$membership->perms_ver}";

        return Cache::remember($key, now()->addMinutes(30), function () use ($membership) {
            $rolePerms   = $this->memberships->rolePermissions($membership->role_id);
            $granted     = $this->memberships->customGrants($membership->id, notExpired: true);
            $denied      = $this->memberships->customDenies($membership->id);

            return ResolvedPermissions::from($rolePerms, $granted, $denied, $membership->perms_ver);
        });
    }
}
```

# Endpoints Backed

Every endpoint lives under `/api/v1/auth` and returns the platform envelope
`{success, data, message, errors, meta, request_id, timestamp}` per
[../api/REST_STANDARDS.md](../api/REST_STANDARDS.md). Session-issuing and token-issuing routes are on
the guest guard; everything else requires an authenticated credential and (except `me` /
`switch-company`) a resolved active company via `X-Company-Id`.

| Method & path | Action / Service | Auth required | Notes |
|---|---|---|---|
| `POST /auth/register` | `RegisterUserAction` | none | Creates user, sends verification email. |
| `POST /auth/email/verify` | `VerifyEmailAction` | none (signed link) | Marks `email_verified_at`; unverified users cannot create a company. |
| `POST /auth/login` | `LoginAction` | none | Returns a full credential or an `mfa_pending` one. 5/min per IP+account. |
| `POST /auth/mfa/verify` | `MfaService::verifyStepUp` | `mfa_pending` scope only | Exchanges the reduced credential for a full one. 3/min per account. |
| `POST /auth/refresh` | `RefreshTokenAction` | valid refresh token | Rotation-on-use; reuse revokes the family. |
| `POST /auth/logout` | `LogoutAction` | any | Revokes the calling credential only. |
| `POST /auth/logout-all` | `LogoutAllAction` | any | Revokes every credential except the caller's. |
| `GET  /auth/me` | `MeController` | any (no company needed) | Identity, companies, active company, resolved permissions. |
| `POST /auth/switch-company` | `SwitchCompanyAction` | any | Re-scopes the credential to another `company_users` membership. |
| `GET  /auth/sessions` | `SessionQueryService` | any | Lists active web sessions and bearer tokens/devices. |
| `DELETE /auth/sessions/{id}` | `RevokeSessionAction` | any | Revokes one named session/device. |
| `POST /auth/password/forgot` | `RequestPasswordResetAction` | none | 3/hour per account; response is identical whether or not the account exists. |
| `POST /auth/password/reset` | `ResetPasswordAction` | none (signed token) | Sets a new password; revokes all credentials. |
| `POST /auth/password/change` | `ChangePasswordAction` | any + MFA if enrolled | Revokes all other credentials. |
| `POST /auth/mfa/enroll` | `MfaService::beginEnrollment` | any | Issues TOTP secret / WebAuthn challenge; returns recovery codes once. |
| `POST /auth/mfa/enroll/confirm` | `MfaService::confirmEnrollment` | any | Verifies the first code before the factor becomes active. |
| `DELETE /auth/mfa/factors/{id}` | `MfaService::removeFactor` | any + step-up | Cannot remove the last factor on an MFA-mandatory role. |
| `POST /auth/mfa/recovery-codes` | `MfaService::regenerateRecoveryCodes` | any + step-up | Invalidates and reissues the 10 single-use codes. |
| `GET/POST /auth/api-keys` | `ApiKeyService` | `settings.api_keys.manage` | Lists / issues `qyd_live_…` keys (shown once). |
| `DELETE /auth/api-keys/{id}` | `ApiKeyService::revoke` | `settings.api_keys.manage` | Instant revocation. |
| `GET/POST/PATCH /auth/roles` | `RoleService` | `settings.roles.manage` | Role CRUD; permission changes route through approvals. |
| `GET  /auth/approvals` / `POST /auth/approvals/{id}/decide` | `AuthApprovalService` | `settings.roles.approve` | Maker-checker for sensitive identity changes. |
| `GET  /.well-known/jwks.json` | `JwksController` | none (public) | Publishes active RS256 public keys by `kid`. |

The controller for each is thin — it authorizes (via the FormRequest or a route `can:` gate), builds a
DTO, calls the Action/Service, and wraps the result in a Resource. No controller assembles a token,
checks a password, or touches the denylist directly.

`GET /auth/me` is the client's source of truth for identity, membership, and grants — it deliberately
carries no company data, only the memberships the user may switch into and the permissions resolved
for the *currently active* company:

```jsonc
// GET /api/v1/auth/me  (X-Company-Id optional; omit to get identity + memberships only)
{
  "success": true,
  "data": {
    "user": { "uuid": "9f3c...", "email": "ali@example.com", "name": "Ali", "mfa_enrolled": true },
    "companies": [
      { "uuid": "c1-...", "name_en": "ABC Trading", "role": "senior_accountant" },
      { "uuid": "c2-...", "name_en": "XYZ Holding",  "role": "cfo" }
    ],
    "active_company": { "uuid": "c1-...", "role": "senior_accountant" },
    "permissions": [
      "accounting.journal.read", "accounting.journal.create", "reports.export"
    ],
    "perms_ver": 7
  },
  "message": "identity.me",
  "errors": [],
  "meta": {},
  "request_id": "01J...",
  "timestamp": "2026-07-20T09:14:22Z"
}
```

A login that requires a second factor returns the reduced credential and a directive rather than a
session — the client cannot act until it completes `mfa/verify`:

```jsonc
// 200 OK — POST /api/v1/auth/login when MFA is mandatory for the role
{
  "success": true,
  "data": { "status": "mfa_required", "scope": "auth:mfa_pending", "factors": ["totp", "webauthn"],
            "challenge_id": 55021, "expires_in": 300 },
  "message": "identity.mfa.required",
  "errors": [], "meta": {}, "request_id": "01J...", "timestamp": "2026-07-20T09:14:22Z"
}
```

# Database Tables Owned

The `identity` module owns the tables below. All carry the platform's standard columns (`id`,
`created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at`) where applicable; company-scoped
tables additionally carry `company_id`. Global-identity tables (`users`, `jwt_signing_keys`) are
deliberately *not* company-scoped.

```sql
-- Global identity. NOT company-scoped: one human, one row, many memberships.
CREATE TABLE users (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    uuid                UUID NOT NULL DEFAULT gen_random_uuid(),
    email               CITEXT NOT NULL,                 -- case-insensitive, unique
    email_verified_at   TIMESTAMPTZ NULL,
    password_hash       TEXT NULL,                       -- argon2id; NULL for SSO-only accounts
    name                VARCHAR(150) NOT NULL,
    locale              VARCHAR(8) NOT NULL DEFAULT 'ar',
    mfa_enrolled        BOOLEAN NOT NULL DEFAULT false,
    status              VARCHAR(16) NOT NULL DEFAULT 'active'
                           CHECK (status IN ('active','suspended','locked')),
    last_login_at       TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,
    CONSTRAINT uq_users_email UNIQUE (email)
);

-- Membership pivot: the user's identity *inside* one company. Company-scoped, RLS-enforced.
CREATE TABLE company_users (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    user_id             BIGINT NOT NULL REFERENCES users(id),
    role_id             BIGINT NOT NULL REFERENCES roles(id),
    branch_scope        JSONB NOT NULL DEFAULT '[]',      -- visible branch ids; [] = all
    department_scope    JSONB NOT NULL DEFAULT '[]',
    perms_ver           INTEGER NOT NULL DEFAULT 1,       -- bumped on any role/grant change
    status              VARCHAR(16) NOT NULL DEFAULT 'active'
                           CHECK (status IN ('active','suspended','revoked')),
    invited_by          BIGINT NULL REFERENCES users(id),
    joined_at           TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at          TIMESTAMPTZ NULL,
    CONSTRAINT uq_company_users UNIQUE (company_id, user_id)
);
CREATE INDEX idx_company_users_user ON company_users (user_id) WHERE deleted_at IS NULL;

CREATE TABLE roles (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NULL REFERENCES companies(id),   -- NULL = system-seeded default role
    key           VARCHAR(50) NOT NULL,                   -- 'owner','cfo','senior_accountant',...
    name_en       VARCHAR(100) NOT NULL,
    name_ar       VARCHAR(100) NOT NULL,
    is_system     BOOLEAN NOT NULL DEFAULT false,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_roles_company_key UNIQUE (company_id, key)
);

CREATE TABLE permissions (                                -- platform-wide catalogue, seeded
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key           VARCHAR(80) NOT NULL,                   -- '<area>.<action>' / '<area>.<entity>.<action>'
    area          VARCHAR(40) NOT NULL,
    is_sensitive  BOOLEAN NOT NULL DEFAULT false,         -- true for .approve/.release/.submit/.transfer
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_permissions_key UNIQUE (key)
);

CREATE TABLE role_permissions (
    role_id       BIGINT NOT NULL REFERENCES roles(id),
    permission_id BIGINT NOT NULL REFERENCES permissions(id),
    PRIMARY KEY (role_id, permission_id)
);

-- Per-user overrides within a membership (the "custom permissions" of the foundation doc).
CREATE TABLE company_user_permissions (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_user_id   BIGINT NOT NULL REFERENCES company_users(id),
    permission_id     BIGINT NOT NULL REFERENCES permissions(id),
    effect            VARCHAR(6) NOT NULL CHECK (effect IN ('grant','deny')),
    expires_at        TIMESTAMPTZ NULL,                   -- temporary grants (e.g. external auditor)
    created_by        BIGINT NULL REFERENCES users(id),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_cup UNIQUE (company_user_id, permission_id)
);

CREATE TABLE mfa_factors (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id       BIGINT NOT NULL REFERENCES users(id),
    type          VARCHAR(12) NOT NULL CHECK (type IN ('totp','sms','webauthn','backup')),
    secret_enc    BYTEA NULL,                             -- TOTP secret / webauthn public key, encrypted
    label         VARCHAR(80) NULL,
    confirmed_at  TIMESTAMPTZ NULL,                       -- factor inactive until confirmed
    last_used_at  TIMESTAMPTZ NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL
);

CREATE TABLE mfa_challenges (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id       BIGINT NOT NULL REFERENCES users(id),
    factor_id     BIGINT NULL REFERENCES mfa_factors(id),
    purpose       VARCHAR(16) NOT NULL,                   -- 'login','step_up','enroll'
    consumed_at   TIMESTAMPTZ NULL,
    expires_at    TIMESTAMPTZ NOT NULL,                   -- 5 minutes
    attempts      SMALLINT NOT NULL DEFAULT 0,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE login_attempts (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email         CITEXT NOT NULL,
    ip            INET NOT NULL,
    successful    BOOLEAN NOT NULL,
    attempted_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_login_attempts_window ON login_attempts (email, attempted_at);

-- Personal access tokens double as partner/integration API keys. Hashed at rest.
CREATE TABLE personal_access_tokens (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id       BIGINT NOT NULL REFERENCES users(id),
    company_id    BIGINT NULL REFERENCES companies(id),   -- key confined to one company
    name          VARCHAR(120) NOT NULL,
    token_prefix  VARCHAR(16) NOT NULL,                   -- 'qyd_live_' / 'qyd_test_' greppable prefix
    token_hash    CHAR(64) NOT NULL,                      -- SHA-256; QAYD never holds the cleartext
    abilities     JSONB NOT NULL DEFAULT '[]',            -- confined permission subset
    last_used_at  TIMESTAMPTZ NULL,
    expires_at    TIMESTAMPTZ NULL,
    revoked_at    TIMESTAMPTZ NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_pat_hash UNIQUE (token_hash)
);

-- Opaque refresh tokens for bearer clients; rotation + reuse detection by family.
CREATE TABLE refresh_tokens (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id       BIGINT NOT NULL REFERENCES users(id),
    company_id    BIGINT NULL REFERENCES companies(id),
    family_id     UUID NOT NULL,                          -- shared across a rotation chain
    token_hash    CHAR(64) NOT NULL,                      -- SHA-256 of the opaque secret
    device_fp     VARCHAR(128) NULL,
    rotated_to    BIGINT NULL REFERENCES refresh_tokens(id),
    revoked_at    TIMESTAMPTZ NULL,
    expires_at    TIMESTAMPTZ NOT NULL,                   -- 30 days sliding
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_refresh_hash UNIQUE (token_hash)
);
CREATE INDEX idx_refresh_family ON refresh_tokens (family_id);

CREATE TABLE oauth_clients (                              -- enterprise SSO / partner OAuth
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NULL REFERENCES companies(id),
    name          VARCHAR(120) NOT NULL,
    client_id     UUID NOT NULL DEFAULT gen_random_uuid(),
    secret_hash   CHAR(64) NULL,
    redirect_uris JSONB NOT NULL DEFAULT '[]',
    grant_types   JSONB NOT NULL DEFAULT '["authorization_code"]',  -- + PKCE required
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at    TIMESTAMPTZ NULL
);

CREATE TABLE sso_identities (                            -- links an IdP subject to a QAYD user
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id       BIGINT NOT NULL REFERENCES users(id),
    provider      VARCHAR(32) NOT NULL,                   -- 'google','apple','microsoft','okta'
    subject       VARCHAR(255) NOT NULL,                  -- IdP 'sub'
    email         CITEXT NOT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT uq_sso_provider_subject UNIQUE (provider, subject)
);

-- RS256 signing keys for bearer JWTs. Rotated; old keys stay verifiable via JWKS until drained.
CREATE TABLE jwt_signing_keys (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kid           VARCHAR(64) NOT NULL,                   -- key id embedded in every token header
    public_pem    TEXT NOT NULL,
    private_ref   VARCHAR(255) NOT NULL,                  -- reference into the secrets store, never the key
    status        VARCHAR(12) NOT NULL DEFAULT 'active'
                     CHECK (status IN ('active','retiring','revoked')),
    activated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    retire_after  TIMESTAMPTZ NULL,
    CONSTRAINT uq_jwt_kid UNIQUE (kid)
);

-- Maker-checker queue for sensitive identity changes (role grants, permission changes, key issue).
CREATE TABLE auth_approvals (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    subject_type  VARCHAR(40) NOT NULL,                   -- 'role_change','permission_grant','api_key'
    payload       JSONB NOT NULL,
    requested_by  BIGINT NOT NULL REFERENCES users(id),
    status        VARCHAR(12) NOT NULL DEFAULT 'pending'
                     CHECK (status IN ('pending','approved','rejected')),
    decided_by    BIGINT NULL REFERENCES users(id),
    decided_at    TIMESTAMPTZ NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

Web sessions live in Redis (the encrypted Sanctum session store), not in a table — they are
credentials, not durable identity, and are flushed by `SessionStore::flushForUser` on revocation.

# Multi-Tenancy Enforcement

Identity is the one module where the tenancy rule is *asymmetric*, and getting that asymmetry right is
the whole game:

- **Global-identity tables are not company-scoped.** `users`, `mfa_factors`, `refresh_tokens`,
  `jwt_signing_keys`, and `login_attempts` describe a human or a signing key, not a company's business
  data. They carry no `company_id` and no `CompanyScope`. A user is one row regardless of how many
  companies they belong to. These tables are protected by their own access rules (a user reads only
  their own factors/sessions), never by the tenant scope.
- **Membership and authorization tables are strictly company-scoped.** `company_users`,
  `company_user_permissions`, company-owned `roles`, and `auth_approvals` carry `company_id`, use the
  `BelongsToCompany` trait, and are covered by PostgreSQL RLS exactly like every other tenant table
  (`USING (company_id = current_setting('app.current_company_id', true)::bigint)`), per
  [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md) and
  [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md).

The service is the *producer* of the tenant boundary that the rest of the platform consumes. The
sequence is exact and unskippable:

1. Authentication (this service, via Sanctum/`TokenService`) establishes **the user** — full stop. It
   attaches no company and grants no permission.
2. `/auth/me` and `/auth/switch-company` read `company_users` (an authenticated, self-scoped read of
   the caller's own memberships) to tell the client which companies the user *may* act in.
3. On every subsequent business request, the `ResolveTenantCompany` middleware validates the
   `X-Company-Id` header against a live, non-revoked `company_users` row and issues
   `SET LOCAL app.current_company_id` — this service supplies the membership check that middleware
   depends on, but does not itself run on the business path.
4. `PermissionResolver` then composes the grant set for that exact (user, company) pair.

Because permission resolution is keyed by `perms_ver` per membership, a role change, a custom-grant
addition, an expiry, or an emergency lock in Company A can never affect the caller's cached
permissions in Company B, and takes effect on the *next* request in Company A without a stale-cache
window. An emergency lock (Owner suspends a user/branch/department/company) flips
`company_users.status` and bumps `perms_ver`, so the very next authorization check resolves to the
empty set.

Cross-tenant probing fails closed the same way it does everywhere: a `switch-company` to a company the
user has no membership row for resolves to `ModelNotFoundException` → `404`, never `403`, so existence
never leaks.

# Events, Queues & Realtime

The service emits past-tense identity facts that other modules and the audit/notification pipeline
react to. Cross-module listeners are queued (`ShouldQueue`) and dispatch only after commit, per the
Events conventions in [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md).

| Event | Emitted by | Representative reactions |
|---|---|---|
| `identity.user.registered` | `RegisterUserAction` | Send verification email (`realtime` queue). |
| `identity.user.logged_in` | `LoginAction` (after full credential) | Audit row; anomaly heuristic (impossible travel / new device). |
| `identity.mfa.verified` | `MfaService` | Audit; clears the step-up flag. |
| `identity.credential.revoked` | `Logout*` / password change | `jti` → Redis denylist; session flush. |
| `identity.refresh.reuse_detected` | `RefreshTokenAction` | Revoke the whole family; security-channel alert; force re-auth. |
| `identity.company.switched` | `SwitchCompanyAction` | Re-resolve permissions; broadcast a session-context change. |
| `identity.role.assigned` / `identity.permission.changed` | `RoleService` / `AuthApprovalService` | Bump `perms_ver`; flush the permission cache for affected users. |
| `identity.user.invited` | invite flow | Notification to the invitee; audit. |
| `identity.emergency_lock.applied` | Owner lock action | Suspend memberships; revoke all credentials for the target scope. |

Queues used: `realtime` for verification/notification email and SMS OTP dispatch; `default` for
permission-cache invalidation and audit fan-out; `integrations` for SSO/IdP callbacks and partner
webhook notification of key revocation. No identity work runs the AI or reports queues.

**Realtime (Reverb).** Identity broadcasts are personal, on the `private-user.{userId}` channel:
`credential.revoked` (so an open web tab whose session was killed by "sign out all devices" logs
itself out immediately), `company.switched`, and `mfa.required` prompts. A permission change that
alters what a user can see broadcasts a lightweight `permissions.changed` signal on
`private-company.{companyId}` so subscribed clients re-fetch `/auth/me` rather than trusting a stale
menu. Payloads are compact projections (ids + status), never tokens or secrets.

A scheduled sweep (`routes/console.php`) prunes expired `login_attempts` and `mfa_challenges`, retires
drained `jwt_signing_keys` past `retire_after`, and expires elapsed `company_user_permissions`
temporary grants (bumping `perms_ver` on each).

# Integrations

The service is both a caller and a callee across the platform boundaries drawn in
[BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md):

- **Sanctum (web).** The Next.js SPA calls `GET /sanctum/csrf-cookie`, then authenticates from the
  first-party `HttpOnly`, `Secure`, `SameSite=Lax` `qayd_session` cookie backed by the encrypted Redis
  session store. Every state-changing request carries the double-submit `X-XSRF-TOKEN`. Session ids are
  regenerated on login, MFA completion, and company switch to defeat fixation.
- **RS256 JWT + JWKS (bearer clients).** `TokenService` signs access tokens (15-minute lifetime) with
  the active `jwt_signing_keys` private key, embedding its `kid`. Any verifier — the FastAPI AI engine,
  a partner, an API gateway — validates by fetching the public key for that `kid` from the public
  `GET /.well-known/jwks.json` endpoint, which serves `active` and `retiring` keys so tokens signed
  just before a rotation still verify. Refresh tokens are opaque, hashed at rest, and rotated on every
  use with family-wide reuse detection.
- **Email / SMS gateways.** Verification links, password-reset links, and SMS OTP factors dispatch
  through the platform notification gateways (Mailgun/SES, Twilio) on the `realtime` queue.
- **Enterprise SSO (OIDC).** For roadmap SSO, QAYD acts as an OAuth2/OIDC **client** using
  Authorization Code + PKCE against Azure AD / Entra ID, Google Workspace, or Okta. An IdP assertion
  must carry a *verified* email before `sso_identities` links it to a `users` row; SSO establishes
  identity only and never carries permissions, which always resolve from QAYD's own RBAC.
- **The AI engine.** The FastAPI engine authenticates to `/api/v1` with a short-lived internal service
  token this module mints, bound to the acting user and company and scoped to a read/draft ability set
  that can never include a sensitive `.approve` / `.release` / `.submit` ability. The AI is a client of
  authentication like any other, never an exception to it.
- **Consumers.** Every other module consumes this service transitively: they call `User::canInCompany`
  (→ `PermissionResolver`), and they trust the `ResolveTenantCompany` membership check. Approval-gated
  actions across the platform read the resolved permission set to enforce maker-checker.

# Permissions

Authentication holds *no* business permission — proving identity grants nothing. The permissions this
module *checks* are the identity-administration ones; the permissions it *resolves* are the whole
platform's. Administration follows the standard `<area>.<action>` grammar and is deny-by-default.

| Permission | Guards |
|---|---|
| `settings.users.invite` | Inviting a user into the company (creating a `company_users` row). |
| `settings.users.manage` | Suspending, re-scoping, or offboarding a member. |
| `settings.roles.manage` | Creating/editing roles and role-permission bundles. |
| `settings.roles.approve` | Deciding an `auth_approvals` maker-checker item (permission changes). |
| `settings.permissions.grant` | Adding a per-user custom grant/deny (routes through approval when sensitive). |
| `settings.api_keys.manage` | Issuing and revoking `qyd_live_…` API keys. |
| `settings.company.lock` | Emergency lock of a user/branch/department/company (Owner-level). |

Two rules bind the administration surface, echoing the security folder:

- **Sensitive identity changes are two-key.** A permission change, a role grant that would confer a
  sensitive ability, or an API-key issuance is created as an `auth_approvals` row by the maker and only
  takes effect when a *different* holder of `settings.roles.approve` decides it — the initiator can
  never approve their own request (`MakerCheckerViolationException` → `403`).
- **A partner key can never hold a sensitive ability.** `ApiKeyService` rejects at issuance any
  `abilities` entry whose `permissions.is_sensitive = true`, so a leaked integration key can read and
  create but never approve a transfer, release payroll, or submit tax.

The AI-on-behalf-of rule is enforced here too: the service token's ability set is intersected with the
acting user's *own* resolved permissions, so the AI can never exceed the human it acts for.

# Error Handling

The service throws typed domain exceptions; the global handler
([BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md)) maps them to the envelope and status code. The
invariant is **fail closed**: any doubt is a `401`/`403`, never a best-effort allow.

| Thrown by service | Meaning | Rendered |
|---|---|---|
| `InvalidCredentialsException` | Bad email/password (constant-time, no user-existence leak) | 401 `invalid_credentials` |
| `MfaRequiredException` | Full-scope action attempted with an `mfa_pending` credential | 401 `mfa_required` |
| `MfaChallengeFailedException` | Wrong/expired TOTP or passkey assertion | 401 `mfa_failed` |
| `AccountLockedException` | Backoff cooldown active after repeated failures | 429 `account_locked` (with `Retry-After`) |
| `RefreshTokenReusedException` | A rotated refresh token was replayed | 401 `refresh_reuse_detected` (family revoked) |
| `DeviceMismatchException` | Bearer token presented from a different device fingerprint | 401 `device_mismatch` |
| `CredentialRevokedException` | `jti` on the denylist / session flushed | 401 `credential_revoked` |
| `EmailNotVerifiedException` | Unverified user attempting a gated action (e.g. create company) | 403 `email_not_verified` |
| `NotACompanyMemberException` | `switch-company` to a non-membership | 404 `not_found` (never 403) |
| `MakerCheckerViolationException` | Approver == maker on a sensitive identity change | 403 `maker_checker_violation` |
| `SensitiveAbilityForbiddenException` | API key requested a `.approve`/`.release`/`.submit` ability | 422 `sensitive_ability_forbidden` |

Every exception carries structured context (the throttle `Retry-After`, the offending ability, the
required factor) so the handler populates `errors[].message` in the caller's language without knowing
about HTTP. Passwords, tokens, session ids, and MFA secrets are **never** placed in an exception
message, a log line, or a URL — a CI log-scrubbing test asserts it.

If the credential store (Redis) that backs sessions, the `jti` denylist, or the throttle counters is
unreachable, the service denies rather than bypasses: authentication returns `503`/`401` and executes
nothing, because a control that cannot make a confident positive decision must not allow.

# Testing

The layering is chosen so each security control has a named test that proves it fires and fails
closed, following the [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) testing model and the
security-verification bar in [../security/AUTHENTICATION.md](../security/AUTHENTICATION.md).

- **Unit-test Actions** with repositories, `TokenService`, and `MfaService` mocked: `LoginAction`
  returns an `mfa_pending` credential when the role requires MFA and a full one only after
  `MfaService::verifyStepUp`; `RefreshTokenAction` rotates on use and revokes the family on reuse;
  `ResetPasswordAction` revokes every existing credential; `SwitchCompanyAction` re-resolves
  permissions and regenerates the session id.
- **Unit-test the domain invariants** in isolation: `PermissionResolver` composes
  `role ∪ grant − deny`, returns the empty set for a missing membership, and honors `perms_ver` cache
  invalidation; `ApiKeyService` refuses any sensitive ability; the constant-time password check hashes
  a dummy on a missing user.
- **Feature-test through `/api/v1/auth/*`** for the full pipeline: the throttle blocks the 6th login in
  a minute and backoff doubles; a `mfa_pending` credential can call *only* `mfa/verify`; a CSRF-missing
  state change on the cookie flow returns `419`; a bearer token with a mismatched device fingerprint is
  rejected; the JWKS endpoint serves `active` and `retiring` keys.
- **Tenant-isolation tests**: `switch-company` to a non-membership returns `404`; a permission change in
  Company A never alters the caller's resolved permissions in Company B; an emergency lock empties the
  permission set on the next request.
- **Maker-checker tests**: the initiator of a permission change cannot approve it (`403`); a partner key
  issuance requesting a `.approve` ability fails with `422`.
- **Static gates (CI)**: `argon2id` is the configured hashing driver in every non-test environment; no
  password, token, or session id appears in any log formatter output; the `.well-known/jwks.json` route
  is present in the committed OpenAPI spec.

A feature is not done until it ships with its migration, API, permissions, audit logging, AI support,
documentation, and tests — the MODULE_ARCHITECTURE release checklist.

# Related Documents

- [SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md) — the Action/Service pattern this module instantiates.
- [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) — the request lifecycle and the four gates.
- [../security/AUTHENTICATION.md](../security/AUTHENTICATION.md), [../security/AUTHORIZATION.md](../security/AUTHORIZATION.md) — the adversarial security model this service implements.
- [../foundation/AUTHENTICATION.md](../foundation/AUTHENTICATION.md), [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md) — the foundational login/permission facts.
- [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) — membership scoping and RLS.
- [../api/REST_STANDARDS.md](../api/REST_STANDARDS.md), [../api/INTERNAL_API.md](../api/INTERNAL_API.md) — envelope, and the AI service-token boundary.

# End of Document
