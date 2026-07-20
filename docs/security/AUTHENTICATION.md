# Authentication Security — QAYD Security
Version: 1.0
Status: Design Specification
Module: Security
Submodule: AUTHENTICATION
---

# Purpose

This document specifies, in security depth, how QAYD proves *who a caller is* before any question of
*what they may do* is asked. It is the L2 (authentication) specialization of the layered model in
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md) and the security-hardening companion to the
mechanism docs [../foundation/AUTHENTICATION.md](../foundation/AUTHENTICATION.md) (the foundational
"what login methods exist") and [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md) (the service
that implements them). Where those describe the happy path, this document describes the adversarial
one: a stolen session cookie, a captured bearer token, a brute-forced password, a fixated session, a
replayed refresh token, a leaked partner key.

Authentication answers "who is this?" and nothing more. It never answers "what may they access?" —
that is [AUTHORIZATION.md](AUTHORIZATION.md). The two are kept rigorously separate because conflating
them is how systems accidentally grant access as a side effect of proving identity. A caller who has
authenticated perfectly still holds zero permissions until authorization grants them, per request,
against the active company.

The platform facts this document builds on are fixed: QAYD runs a Laravel 12 (PHP 8.4+) backend with
**Sanctum** as the authentication layer; the web application (Next.js 15) uses **first-party,
same-site session cookies**; the mobile app (Flutter) and partner/integration and AI-engine callers
use **bearer tokens**; all traffic is `/api/v1` over TLS; MFA is TOTP-based and mandatory on
financial-approval roles. Everything below hardens those facts against the threat model in
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md).

---

# Threat Model / Principles

## The adversary at the authentication boundary

The authentication layer defends boundary **B2** (client ↔ API) against a caller who is trying to be
someone they are not. Concretely:

- **Credential theft** — a phished password, a cookie stolen via XSS or a malicious extension, a
  bearer token lifted from a compromised device or a leaked log.
- **Credential guessing** — online brute force and credential stuffing against the login endpoint.
- **Session attacks** — fixation (forcing a victim onto an attacker-known session), replay of a
  captured token, and riding a session that outlived its usefulness.
- **Token attacks** — replay of a rotated refresh token, forgery of a JWT, use of a token after the
  underlying access should have ended.
- **Factor bypass** — attempting a sensitive action with only the first factor, or downgrading past
  MFA.

## Principles

- **Authentication proves identity; it grants nothing.** A perfect login yields an authenticated
  principal with no permissions. Authorization is a separate, per-request decision.
- **Credentials are short-lived and revocable.** Every credential can be killed within one request,
  and the ones that matter expire quickly on their own.
- **Step up for what matters.** The floor for logging in is not the floor for moving money. Sensitive
  actions demand MFA and, on anomaly, re-authentication — regardless of an already-valid session.
- **Fail closed.** Any doubt — expired, revoked, malformed, device-mismatched, replayed — is a
  `401`, never a best-effort allow.
- **Constant-time everywhere.** Every secret comparison in the auth path uses `hash_equals`, never
  `==`, so timing is not a side channel.
- **The server is the authority.** "Remember me", "trusted device", and any client-held flag are
  conveniences the server re-verifies, never trusts.

---

# Controls

## The two session models, and why

QAYD deliberately runs **two** authentication models, chosen by client type, because the browser and
a native/partner client have genuinely different threat profiles.

| Client | Model | Credential | Primary risks addressed |
|---|---|---|---|
| Next.js 15 web app | Sanctum stateful SPA session | First-party, same-site, `HttpOnly`, `Secure` session cookie | XSS token exfiltration (cookie is `HttpOnly`, unreadable by JS), CSRF (same-site + token), transport interception |
| Flutter mobile app | Bearer token | Signed JWT in the OS secure keystore | Device theft (device-bound, short-lived), keystore is not web-reachable |
| Partner / third-party integration | Bearer token / API key | Prefixed, hashed-at-rest key | Key leakage into repos/logs (greppable prefix, hashed store), scope confinement |
| FastAPI AI engine | Bearer service token | Narrow, signed service JWT + agent signature | Compromise of a semi-trusted client (narrow scope, read/draft only) |

The web model is cookie-based specifically so that **the access credential is never readable by
JavaScript**. An `HttpOnly` cookie cannot be exfiltrated by an XSS payload the way a token stashed in
`localStorage` can. The cost of cookies is CSRF exposure, which QAYD pays down with same-site cookies
plus an explicit CSRF token (below). Native and partner clients do not run in a browser, have no
ambient-cookie problem, and benefit from an explicit bearer token they store in a hardware-backed
keystore; there, the token model is the safer one. Choosing per client type gets the best of both.

## Session cookie configuration (web)

```php
// config/session.php — security-relevant settings for the Sanctum SPA flow
return [
    'driver'          => 'redis',        // sessions in Redis, never in a client-readable store
    'lifetime'        => 120,            // minutes of inactivity before idle expiry (see lifetimes below)
    'expire_on_close' => false,
    'encrypt'         => true,           // session payload encrypted at rest in Redis
    'http_only'       => true,           // cookie invisible to document.cookie / JS
    'secure'          => true,           // cookie only sent over HTTPS
    'same_site'       => 'lax',          // first-party only; blocks cross-site cookie attachment
    'partitioned'     => false,
    'path'            => '/',
    'domain'          => env('SESSION_DOMAIN'), // app.qayd.com — never a bare parent domain
];
```

The web app and API are same-registrable-domain first-party (`app.qayd.com` calling
`api.qayd.com`), which is what lets Sanctum's stateful SPA guard work: the
`EnsureFrontendRequestsAreStateful` middleware recognizes a request from a configured first-party
origin and authenticates it from the session cookie, having first required a valid CSRF token.

## CSRF protection (cookie flow only)

CSRF is a risk **only** for the cookie-based web flow, because only a cookie is attached ambiently by
the browser. Bearer-token clients are structurally immune — there is no ambient credential for a
malicious page to ride, which is why [../api/API_SECURITY.md](../api/API_SECURITY.md) sets
`supports_credentials: false` on the general CORS config. For the web flow, QAYD layers three
defenses:

1. **`SameSite=Lax` cookies.** The browser will not attach the session cookie to a cross-site
   top-level POST, which neutralizes the classic form-POST CSRF outright.
2. **Double-submit CSRF token.** The Next.js app first calls `GET /sanctum/csrf-cookie`, receiving an
   `XSRF-TOKEN` cookie; it echoes that value in the `X-XSRF-TOKEN` header on every state-changing
   request. The server (`VerifyCsrfToken`) checks the two match with `hash_equals`. A cross-site
   attacker cannot read the token value to echo it (same-origin policy), so it cannot forge the
   header.
3. **Origin/Referer validation.** State-changing requests are additionally checked against the
   allow-listed first-party origin; a mismatch is rejected.

```php
// app/Http/Middleware/VerifyCsrfToken.php
protected $except = [
    'api/v1/webhooks/*',   // signed by HMAC instead (see API_SECURITY Request Signing)
];
// Everything else on the cookie flow requires a matching X-XSRF-TOKEN.
```

Bearer-token routes are exempt from CSRF because they require an `Authorization: Bearer …` header the
browser never adds automatically; there is no CSRF surface to protect.

## MFA / TOTP

MFA is TOTP (RFC 6238) with a strict enforcement policy driven by role capability, not user
preference.

| Population | MFA requirement |
|---|---|
| Any role holding `bank.transfer`, `bank.transfer.approve`, `payroll.approve`, `payroll.release`, or `tax.submit` (Owner, CEO, CFO, Finance Manager, HR Manager at minimum) | **Mandatory** — the account cannot complete login to a full-scope token/session without a verified second factor |
| Every other role | Offered and strongly encouraged; company Owners may make it mandatory company-wide |
| Machine credentials (API keys, AI service token) | N/A — protected by scope confinement, rotation, and IP allow-listing instead |

Enrollment issues a TOTP shared secret (shown once as a QR/provisioning URI, stored encrypted with
the MFA secret-encryption key from [SECRETS.md](SECRETS.md)) and **10 single-use recovery codes,
hashed at rest** (`bcrypt`), shown exactly once. WebAuthn/passkeys are supported as a stronger factor
and, where enrolled, satisfy the MFA requirement in place of TOTP.

**Login step-up handshake.** When MFA is required, login returns a **reduced-scope** credential
(ability `auth:mfa_pending`) that can call nothing except `POST /api/v1/auth/mfa/verify`. Only a
correct TOTP (or passkey assertion) exchanges it for a full-scope credential. This is the same
mechanism described in [../api/API_SECURITY.md](../api/API_SECURITY.md); it means a stolen password
alone, without the second factor, yields a credential that is authorization-inert.

**Step-up on anomaly.** Even with a fully authenticated session, an anomaly signal (impossible
travel, new device, a sensitive action after a long idle gap) forces a fresh MFA challenge before the
next sensitive action proceeds. A valid session is a reason to *ask for one factor*, not a reason to
*skip the second*.

## Password policy and hashing

| Control | Setting | Rationale |
|---|---|---|
| Minimum length | 12 characters | Length dominates entropy; short "complex" passwords are weaker and more annoying |
| Composition rules | None mandated | NIST 800-63B: composition rules push users to predictable patterns |
| Breach check | k-anonymity range query against a breached-corpus | Rejects known-compromised passwords; only a hash *prefix* ever leaves QAYD, never the password or full hash |
| Hashing algorithm | `argon2id`, tuned to ~250ms/hash on production hardware | Memory-hard; resists GPU/ASIC cracking; the platform stack mandates argon2id |
| Forced periodic rotation | **None** | Rotation without evidence of compromise measurably weakens passwords (NIST 800-63B) |
| Forced rotation on compromise | Immediate | Breach-corpus match, credential-stuffing detection, or manual security action invalidates the password and forces a reset |

```php
// config/hashing.php
'argon' => [
    'memory'  => 65536,  // 64 MB
    'threads' => 1,
    'time'    => 4,
],
'driver' => 'argon2id',
```

Passwords are never logged, never placed in a URL, and never returned in any API response. The
handling detail is shared with [../api/API_SECURITY.md](../api/API_SECURITY.md); this document is the
authority for the policy.

## Session fixation, lifetime, and revocation

**Fixation.** The session identifier is **regenerated on every privilege transition** — on login, on
MFA completion, and on switching active company — so a session id an attacker planted before login
is useless after it. Laravel's `$request->session()->regenerate()` is called at each of these points;
a session id is never carried across an authentication-state change.

**Lifetimes.**

| Credential | Idle timeout | Absolute lifetime | Notes |
|---|---|---|---|
| Web session cookie | 120 min inactivity | 12 h, then re-auth | Sliding within the idle window; hard cap regardless of activity |
| Mobile access token (JWT) | — | 15 min | Refreshed silently via refresh token |
| Refresh token | — | 30 days, sliding | Rotated on every use (below) |
| `auth:mfa_pending` credential | 5 min | 5 min | Just long enough to enter a code |
| Partner API key | — | No natural expiry | Killed by revocation or rotation; audited on a schedule |

**Revocation** is single-request-effective. Every credential maps to a record whose revocation is
checked on the request critical path: bearer JWTs carry a `jti` checked against a Redis denylist
(TTL = the token's remaining life, so the list self-prunes); web sessions are deleted from the Redis
session store. Revocation is triggered by explicit logout, "sign out all devices", password change,
role change, company removal, and any suspected-compromise signal. "Sign out all devices" revokes
every credential for the user except the one making the call.

```php
// Revocation on a security-relevant change (password change shown)
public function afterPasswordChanged(User $user, ?string $keepTokenId = null): void
{
    // Kill every bearer token except optionally the current one.
    $user->tokens()
        ->when($keepTokenId, fn ($q) => $q->where('id', '!=', $keepTokenId))
        ->get()
        ->each(function ($token) {
            Redis::setex("revoked_jti:{$token->jti}", $token->ttlSeconds(), 1);
            $token->forceFill(['revoked_at' => now()])->save();
        });

    // Kill every web session for this user.
    SessionStore::flushForUser($user->id);

    AuditLog::security('auth.credentials.revoked_all', $user, reason: 'password_change');
}
```

## Brute-force, rate limiting, and lockout

Login and other credential endpoints are rate-limited in Redis (shared across the stateless Laravel
instances, so an attacker cannot spread attempts across servers to evade a per-instance counter).

| Endpoint | Limit | Keyed on |
|---|---|---|
| `POST /api/v1/auth/login` | 5 / minute | IP **and** attempted account (both counters) |
| `POST /api/v1/auth/mfa/verify` | 3 / minute | Account |
| `POST /api/v1/auth/password/reset-request` | 3 / hour | Account |

**Lockout uses bounded exponential backoff, never a permanent lock.** After 5 failures the account
enters a 1-minute cooldown that doubles with continued failure up to a capped ceiling. A permanent
lock keyed on an email address would itself be a denial-of-service weapon against a legitimate user
by anyone who knows their address. A CAPTCHA is required on the human login form after 3 consecutive
failures. **Credential stuffing** (many distinct target accounts from one IP/fingerprint with a high
failure ratio) escalates to edge-level IP throttling. Machine credentials have no CAPTCHA path;
repeated auth failures on an API key or the AI service token trigger temporary key suspension plus a
security-channel alert, since a real integration does not fail authentication repeatedly.

## Device trust

Mobile tokens are **device-bound**: the JWT carries a `dvc` device-fingerprint claim, and
`VerifyServiceToken` rejects a token presented from a materially different device fingerprint than the
one recorded at issuance (constant-time compared). "Remember this device" on the web reduces MFA
re-prompt frequency for a recognized device, but the recognition is a *server-side* record keyed to a
signed device cookie plus fingerprint; it never suppresses MFA for a sensitive action and never
survives a password change. A user can list and individually revoke devices/sessions.

## Token security for mobile and partner

The bearer-token mechanics — RS256-signed JWTs, the `jti` revocation denylist, 15-minute access
tokens, 30-day sliding refresh tokens **rotated on every use with reuse detection** (a re-presented,
already-rotated refresh token revokes the entire descendant family and forces re-auth), and prefixed
hashed-at-rest partner keys (`qak_live_…` / `qak_test_…`) — are specified in full in
[../api/API_SECURITY.md](../api/API_SECURITY.md) and [../api/AUTHENTICATION_API.md](../api/AUTHENTICATION_API.md).
This document adds the security posture around them: partner keys are shown once, stored SHA-256
hashed (QAYD never holds a usable copy), scoped to a permission subset that can never include a
sensitive `.approve`/`.release`/`.submit` action, and monitored — a leaked key's prefix makes it
greppable in logs and repos, and any key can be revoked instantly from company settings.

## SSO considerations (forward-looking)

Enterprise SSO (Azure AD / Microsoft Entra ID, Google Workspace, Okta) is a roadmap item
([../foundation/AUTHENTICATION.md](../foundation/AUTHENTICATION.md)). Its security requirements are
fixed now so the design does not paint itself into a corner: QAYD acts as an OAuth2/OIDC **client**
using Authorization Code + PKCE; the IdP-asserted email must be **verified** before it maps to a QAYD
account (an unverified assertion never auto-provisions access); SSO establishes *identity only* — it
never carries permissions, which are always resolved from QAYD's own RBAC against the active company;
and where a company mandates SSO, local password login is disabled for that company's users so there
is no weaker parallel path. MFA policy still applies unless the IdP asserts an equivalent factor
(`amr` claim).

---

# Enforcement Points

| Enforcement point | Layer / mechanism | Defends |
|---|---|---|
| `EnsureFrontendRequestsAreStateful` | Sanctum SPA stateful guard | Recognizes first-party web session cookie |
| `VerifyCsrfToken` | Double-submit + same-site + origin check | CSRF on the cookie flow |
| `Authenticate` (session/bearer) | Session lookup / JWT verify + `jti` denylist | Invalid, expired, revoked credentials |
| `VerifyServiceToken` | RS256 verify + device-fingerprint check | Forged/replayed bearer tokens, device swap |
| `RequireMfa` / `mfa_pending` scope gate | Step-up handshake | First-factor-only access to sensitive actions |
| `ThrottleRequests` (Redis) | Rate-limit tiers | Brute force, stuffing, OTP hammering |
| Session `regenerate()` at privilege transitions | Fixation defense | Planted/pre-login session ids |
| Revocation denylist / session flush | Redis | Logout, sign-out-all, compromise response |

The authentication step is the second in the fixed pipeline
(`authenticate → resolve tenant → authorize → validate → execute → audit`); it never runs after
tenant resolution and never authorizes anything itself.

---

# Boundaries

- **In scope:** proving identity — sessions, tokens, CSRF, MFA/TOTP, passwords, fixation/lifetime/
  revocation, device trust, brute-force defense, SSO identity assertion.
- **Out of scope, deferred to [AUTHORIZATION.md](AUTHORIZATION.md):** everything about *what* an
  authenticated principal may do — permissions, roles, Policies, approval chains. An authenticated
  user with no grants can do nothing; that "nothing" is authorization's to enforce.
- **Out of scope, deferred to [TENANT_ISOLATION.md](TENANT_ISOLATION.md):** which company a request
  acts on. Authentication establishes the user and the set of companies they *may* act on (the
  membership); resolving the *active* company per request is tenant isolation's job.
- **Out of scope, deferred to [SECRETS.md](SECRETS.md):** custody and rotation of the signing keys,
  the MFA secret-encryption key, and partner-key hashing pepper.
- **Cross-linked, not duplicated:** the JWT claim table, refresh-rotation state machine, and partner
  key format live in [../api/AUTHENTICATION_API.md](../api/AUTHENTICATION_API.md) and
  [../api/API_SECURITY.md](../api/API_SECURITY.md); the login/registration UX in
  [../foundation/AUTHENTICATION.md](../foundation/AUTHENTICATION.md); the implementing service in
  [../backend/AUTH_SERVICE.md](../backend/AUTH_SERVICE.md).

---

# Failure Modes

| Event | Fail-closed behavior |
|---|---|
| Password correct, MFA required, no second factor | Credential issued at `auth:mfa_pending` scope only — cannot call any business endpoint |
| Bearer token expired / revoked (`jti` on denylist) | `401 Unauthorized`; no downstream execution |
| Refresh token replayed after rotation | Entire token family revoked; user forced to re-authenticate |
| Device fingerprint mismatch on a mobile token | `401 DEVICE_MISMATCH`; token not honored |
| CSRF token missing/mismatched on cookie flow | `419`/`403`; state-changing request refused |
| Session id presented after a privilege transition | Rejected — id was regenerated; old id no longer valid |
| MFA/session store (Redis) unreachable | Fail closed: authentication denied rather than bypassed |
| Repeated login failures | Exponential backoff cooldown + CAPTCHA; never permanent account lock |
| Repeated machine-credential auth failure | Temporary key suspension + security alert |

The invariant: an authentication component that cannot make a confident positive decision returns a
`401`/`403` and executes nothing. There is no "degrade to allow" path anywhere in this layer.

---

# Compliance

| Framework expectation | Control in this document |
|---|---|
| SOC 2 — logical access, MFA on privileged access | Mandatory MFA for financial-approval roles; step-up on anomaly |
| SOC 2 — session management | Idle/absolute lifetimes, fixation regeneration, single-request revocation |
| ISO 27001 — access control, credential lifecycle | Password policy, breach checking, revocation on offboarding |
| GDPR / GCC — protecting authentication data | Passwords argon2id-hashed, recovery codes hashed, MFA secret encrypted, credentials never logged |
| NIST 800-63B alignment | 12-char minimum, no composition rules, no forced rotation, breach-corpus rejection |

QAYD does not force periodic password rotation *because* NIST 800-63B evidence shows it harms
security — a deliberate, defensible choice an auditor can trace to a standard rather than to
convenience.

---

# Testing & Verification

- **Unit / feature (Pest, must be green):** login issues `mfa_pending` scope when MFA required and
  full scope only after `mfa/verify`; expired and revoked tokens are rejected; refresh-token reuse
  revokes the family; device-fingerprint mismatch is rejected; CSRF-missing state changes on the
  cookie flow return `419`; session id changes across login/MFA/company-switch.
- **Brute-force tests:** the 6th login attempt in a minute is throttled; backoff doubles; CAPTCHA is
  demanded after 3 failures; machine-credential failures suspend the key.
- **Policy tests:** a financial-approval role cannot obtain a full-scope credential without MFA;
  "sign out all devices" invalidates all but the current credential.
- **Static gates (CI):** no password, token, or session id appears in any log formatter output
  (assert via a log-scrubbing test); argon2id (not bcrypt/plain) is the configured driver in every
  non-test environment.
- **Manual / periodic:** SSO integration tests against a staging IdP verifying unverified-email
  assertions do not auto-provision; penetration testing scoped to session fixation, token replay, and
  MFA bypass before major releases.

The bar: for each threat in the threat model, a named test proves the corresponding control fires and
fails closed.

---

# End of Document
