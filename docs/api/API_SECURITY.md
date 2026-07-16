# API Security — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: API_SECURITY
---

# Purpose

The QAYD API layer is the single entry point into the QAYD Accounting Engine and every adjacent
module — Sales, Purchasing, Banking, Inventory, Payroll, Tax, and Reports. It is implemented once,
in Laravel 12 (PHP 8.4+), and every consumer — the Next.js 15 web application, the Flutter mobile
application, the FastAPI-based AI engine, and any third-party integration — reaches company data
exclusively through this layer over HTTPS. No consumer, including QAYD's own AI agents, is
permitted to open a direct connection to PostgreSQL, Redis, or Cloudflare R2. This document is the
authoritative specification of the controls that keep that boundary intact: how a request proves
who it is, how the platform decides what that identity is allowed to touch, how the data on the
wire and at rest is protected, how QAYD detects and survives abuse, and how QAYD responds when a
control fails anyway.

This document assumes the reader has read `DESIGN_CONTEXT.md` and treats its platform facts — the
standard response envelope, `/api/v1/` versioning, the `X-Company-Id` tenant header, the dotted
permission-key scheme, and the rule that AI never writes to the database directly — as fixed. It
does not redefine them; it specifies how they are enforced under adversarial conditions: a stolen
bearer token, a company that tries to read another company's ledger by guessing an id, a webhook
replayed ten times by a flaky integration, a payroll-release request forged by a script instead of
a human being. Every control described here is enforceable in code today — in Laravel 12
middleware, Form Requests, Policies, and Redis-backed rate limiters — not aspirational.

The fixed request pipeline that every one of the following sections attaches to is:

```
Client (Next.js / Flutter / FastAPI AI engine / integration)
        │  HTTPS, Bearer token, X-Company-Id header
        ▼
[1] Authenticate  — is this a valid, unexpired, unrevoked credential?
        ▼
[2] Resolve tenant — does this credential's owner belong to the company in X-Company-Id?
        ▼
[3] Authorize     — does this (user, company) pair hold the permission this endpoint requires?
        ▼
[4] Validate      — does the request body satisfy the endpoint's Form Request rules?
        ▼
[5] Execute       — Service → Repository → Model, inside the tenant's scope only
        ▼
[6] Audit         — write an immutable record of what happened, for both business and security review
```

No step is skippable, no step is reorderable, and no caller — human or AI — is exempt from any
step. The audience for this document is the engineers who build and operate the API: backend
engineers implementing middleware and policies, mobile/web engineers integrating the SDKs, the
security engineer configuring secrets and WAF rules, and the on-call engineer who has thirty
minutes to contain an incident at 3 AM.

# Transport Security

QAYD terminates no plaintext HTTP traffic anywhere in the request path. Port 80 exists only to
issue a permanent redirect to HTTPS; it never proxies a request body.

**TLS configuration (table):**

| Layer | Control | Setting |
|---|---|---|
| Protocol versions | Minimum accepted | TLS 1.2 (TLS 1.3 preferred, negotiated whenever the client offers it) |
| Protocol versions | Disabled | SSLv3, TLS 1.0, TLS 1.1 — rejected at the edge, connection reset |
| Cipher suites | Allow-list only | `TLS_AES_256_GCM_SHA384`, `TLS_CHACHA20_POLY1305_SHA256`, `TLS_AES_128_GCM_SHA256` (TLS 1.3); `ECDHE-RSA-AES256-GCM-SHA384`, `ECDHE-RSA-CHACHA20-POLY1305` (TLS 1.2 fallback) |
| Cipher suites | Banned | RC4, 3DES, export-grade ciphers, any non-AEAD cipher, static RSA key exchange (no forward secrecy) |
| Key exchange | Required | ECDHE only — every session has Perfect Forward Secrecy; a future compromise of the server's private key cannot decrypt past traffic captures |
| HSTS | Header on every response | `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload` (see `# Security Headers`) |
| Certificates | Issuance | Automated (ACME), monitored for expiry with alerting at 30/14/3 days out, OCSP stapling enabled |
| HTTP version | Supported | HTTP/2 and HTTP/3 (QUIC); HTTP/0.9 and request smuggling–prone malformed HTTP/1.0 rejected at the edge |
| WebSockets (Reverb) | Transport | WSS only, same TLS floor as REST; channel authorization reuses the Sanctum bearer guard over an authenticated HTTPS call, never a bare `ws://` handshake |

**Edge topology.** The API sits behind a TLS-terminating edge network that also fronts the
Cloudflare R2 object storage already in use for attachments — the same edge provides the CDN,
WAF, and DDoS-absorption layer described in `# Rate Limiting & Abuse Prevention`, so TLS
termination, bot mitigation, and volumetric-attack absorption happen in one place before a request
ever reaches PHP-FPM. Laravel re-validates `X-Forwarded-Proto` and rejects any request that
reaches the application over anything but HTTPS, so a misconfigured internal hop cannot silently
downgrade a request the edge already terminated correctly.

**Service-to-service transport.** The FastAPI AI engine never calls the Laravel API over a network
path that skips TLS, even inside a private VPC. When both services share a private network, calls
are additionally protected by mutual TLS (mTLS): the AI engine presents a client certificate issued
by QAYD's internal CA, and Laravel's ingress verifies it before the request reaches routing. This
is defense in depth on top of the signed service JWT described in `# Authentication & Token
Security` — a network-level compromise that can reach the private subnet still cannot impersonate
the AI engine without also holding its client certificate's private key.

**Mobile certificate pinning.** The Flutter application pins the public key of QAYD's TLS leaf
certificate (or its issuing intermediate, to survive routine leaf renewal) using a
certificate-transparency-aware pinning library. Two pins are shipped at all times — the active key
and the next key already generated for the following rotation — so a planned rotation never bricks
installed apps; pin validation failures are treated as fail-closed (the request is aborted, not
retried over an unpinned channel) but only after a 14-day grace window per pin change to absorb app
store review delays, avoiding an outage caused by the pinning control itself.

**No sensitive data in URLs.** Query strings and path segments are logged by proxies, browsers,
and third-party analytics far more often than request bodies or headers. QAYD's routing convention
therefore never places a bearer token, a bank account number, a national ID, or any other
Restricted-class value (`# PII & Financial Data Protection`) in a URL. Search/filter parameters
that could echo such values (e.g. searching invoices by a customer's tax ID) are accepted as query
parameters for read convenience but are excluded from access-log persistence of the full query
string; the request path and route name are logged, the sensitive query value is redacted.

# Authentication & Token Security

QAYD recognizes two credential families: **interactive credentials** (a human logging in through
the Next.js web app or the Flutter mobile app) and **machine credentials** (the FastAPI AI engine's
service token, and third-party integrations' API keys). Both are ultimately bearer tokens validated
by the same Laravel Sanctum guard, and both pass through the identical authorize → validate →
execute pipeline — a machine credential receives no implicit trust a human credential would not
also need to earn.

**Token construction.** QAYD's Sanctum guard is configured to issue **signed JSON Web Tokens** as
the bearer token value (a custom `TokenGuard` rather than Sanctum's default opaque string), signed
with **RS256** (asymmetric). The private signing key lives only inside the Laravel auth service and
in the secrets vault (`# Secrets Management`); the corresponding public key is distributed to every
resource server that needs to verify a token without a database round-trip — Laravel Reverb for
WebSocket channel authorization, and the FastAPI AI engine for validating that an inbound call
really carries a token QAYD issued. A companion `api_tokens` table is the revocation source of
truth: every JWT's `jti` claim maps to a row recording the owning user, the companies it may act
on, its ability scopes, and a `revoked_at` timestamp, so a token that is cryptographically valid but
administratively revoked is still rejected.

**JWT claims (table):**

| Claim | Meaning | Example |
|---|---|---|
| `iss` | Token issuer | `https://api.qayd.com` |
| `sub` | Subject — the authenticated user id | `"482913"` |
| `cids` | Array of company ids this user is authorized to act on (mirrors `company_users`) | `[101, 204]` |
| `abilities` | Sanctum ability scopes for this token | `["api:full"]` or `["ai-engine:read", "ai-engine:draft"]` |
| `jti` | Unique token id, the revocation key | `"tok_9f3c1b2e"` |
| `iat` / `nbf` / `exp` | Issued-at, not-before, expiry (Unix time) | — |
| `amr` | Authentication methods used | `["pwd", "totp"]` |
| `dvc` | Bound device fingerprint (mobile only) | `"a1b2c3…"` |

The active company for a given request is **never** baked permanently into the token as a single
value — a user who belongs to multiple companies switches context by sending a different
`X-Company-Id` header on a per-request basis, and the `ResolveActiveCompany` middleware
(`# Authorization & Tenant Isolation`) checks that header's value against the token's `cids` claim
and the live `company_users` table on every single request. A token cannot be pre-loaded with a
company claim and then used to silently act on a company added to the user's access list after
issuance, nor does it remain valid for a company removed from that list mid-session — removal is
checked live, not cached in the token.

**Token lifetimes and rotation.** Access tokens are short-lived (15 minutes). Refresh tokens are
longer-lived (30 days, sliding) and are **rotated on every use**: each call to
`POST /api/v1/auth/refresh` invalidates the presented refresh token and issues a new one. QAYD
implements refresh-token **reuse detection** — if a refresh token that has already been rotated
away is presented again, the entire token family descending from it is revoked immediately and the
user is forced to re-authenticate, on the assumption that a duplicate presentation of an
already-rotated token means it was captured and is being replayed by an attacker racing the
legitimate client.

**Revocation.** Every `jti` that is administratively revoked (logout, "sign out all devices",
password change, role change, company removal, suspected compromise) is written to a Redis-backed
denylist with a TTL equal to the token's remaining natural lifetime, so the denylist never grows
unbounded and a lookup is a single fast Redis `EXISTS` check on the request's critical path.

```
POST /api/v1/auth/login
Content-Type: application/json
Accept-Language: en

{
  "email": "cfo@example-company.com",
  "password": "••••••••••••"
}
```

```
HTTP/1.1 200 OK
Content-Type: application/json

{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
    "refresh_token": "rt_8f2a91c3e4b7...",
    "token_type": "Bearer",
    "expires_in": 900,
    "mfa_required": true,
    "mfa_methods": ["totp"],
    "companies": [
      { "id": 101, "name_en": "Al-Noor Trading Co.", "role": "cfo" },
      { "id": 204, "name_en": "Al-Noor Retail LLC", "role": "read_only" }
    ]
  },
  "message": "MFA verification required to complete authentication.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3f9a1b7e-2c4d-4e6a-9b1f-8d2e5c7a9f01",
  "timestamp": "2026-07-16T09:12:04Z"
}
```

Because `mfa_required` is `true`, this `access_token` is issued at a reduced ability scope
(`auth:mfa_pending` only) — it can call nothing else until
`POST /api/v1/auth/mfa/verify` succeeds with a valid TOTP code, at which point Laravel issues the
full-scope token pair.

**Multi-factor authentication.** TOTP (RFC 6238) is mandatory — not optional — for any role capable
of `bank.transfer`, `payroll.approve`, `payroll.release`, or `tax.submit` (Owner, CEO, CFO, Finance
Manager at minimum), and offered to every other role. Recovery codes are issued at enrollment (10
single-use codes, hashed at rest). WebAuthn/passkey enrollment is supported as a stronger optional
factor and, where enrolled, satisfies the MFA requirement in place of TOTP.

**Password handling.** Passwords are hashed with `argon2id` (cost parameters tuned so a single hash
takes roughly 250ms on production hardware). At signup and at password change, the candidate
password is checked against a breached-password corpus using k-anonymity range queries — only a
truncated hash prefix of the password ever leaves QAYD's infrastructure, never the password or its
full hash. Minimum length is 12 characters; there is no forced periodic rotation (per NIST
800-63B — forced rotation with no evidence of compromise measurably pushes users toward weaker,
predictable passwords), but a password is forcibly invalidated and rotation demanded the moment
compromise is suspected (breach corpus match, credential-stuffing detection, or manual security
action).

**Device and session management.**

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/auth/sessions` | Owner (self) | List this user's active tokens: device, IP, location estimate, last-seen, current-session flag |
| DELETE | `/api/v1/auth/sessions/{id}` | Owner (self) | Revoke one session/token by id |
| POST | `/api/v1/auth/sessions/revoke-all` | Owner (self) | Revoke every token except the one making this call |
| POST | `/api/v1/auth/mfa/enroll` | Owner (self) | Begin TOTP enrollment, returns a provisioning URI/QR payload |
| POST | `/api/v1/auth/mfa/verify` | Owner (self) | Complete login MFA challenge or confirm enrollment |

**Machine credentials.** The FastAPI AI engine authenticates with a long-lived but
narrowly-scoped service token (`abilities: ["ai-engine:*"]` mapped only to the specific permission
keys the AI is allowed to hold — always read and draft-create, never `.post`, `.approve`, `.release`,
or `.submit` on any financial action, per the platform's AI rules). This token is rotated quarterly
and lives only in the secrets vault, never in FastAPI source or its container image. Third-party
integrations receive a distinctly-namespaced API key (`qak_live_…` in production, `qak_test_…`
against the sandbox), styled after Stripe's prefixed-key convention specifically so a key that
leaks into a public repository or log is instantly greppable and its environment identifiable
without needing to look it up. Integration keys are stored hashed (SHA-256) — QAYD's own database
never holds a usable copy of an issued key — and are shown to the integrator exactly once, at
creation time.

**Timing-safe comparisons.** Every secret-equality check in the authentication path — signature
verification, API-key comparison, webhook-secret comparison — uses a constant-time comparison
function (`hash_equals` in PHP) rather than `==`/`===`, so response-time variance cannot be used as
a side channel to guess a secret byte-by-byte.

```php
// app/Http/Middleware/VerifyServiceToken.php
public function handle(Request $request, Closure $next): Response
{
    $token = $request->bearerToken();
    if (! $token) {
        return $this->deny('MISSING_TOKEN');
    }

    try {
        $claims = JWT::decode($token, new Key($this->publicKey(), 'RS256'));
    } catch (\Throwable $e) {
        return $this->deny('INVALID_TOKEN');
    }

    if (Redis::exists("revoked_jti:{$claims->jti}")) {
        return $this->deny('TOKEN_REVOKED');
    }

    // Mobile-issued tokens carry a device-fingerprint claim ('dvc'); a token presented from a
    // materially different device than the one recorded at issuance is rejected outright.
    if (isset($claims->dvc) && ! hash_equals($claims->dvc, $request->header('X-Device-Fingerprint', ''))) {
        return $this->deny('DEVICE_MISMATCH');
    }

    $request->attributes->set('token_claims', $claims);
    return $next($request);
}
```

# Authorization & Tenant Isolation

Authentication answers "who is this?" Authorization answers a narrower, per-request question:
"does this identity, acting on this company, hold the permission this endpoint requires?" QAYD
evaluates that question fresh on every request — never caches a stale "yes" across requests, and
never treats a permission granted in one company as implying anything about another.

**Tenant resolution.** Every authenticated request other than account-level endpoints (list my
companies, switch active company, manage my own sessions) must carry `X-Company-Id`. The
`ResolveActiveCompany` middleware performs three checks, in order, and fails closed on any of them:

1. The header is present and is a syntactically valid integer id.
2. The id appears in the token's `cids` claim (fast, in-token check).
3. A live query against `company_users` confirms the (user, company) row still exists, is not
   soft-deleted, and the company itself is not suspended (e.g. for billing) — this is the check
   that catches access removed *after* the token was issued, since claim 2 alone would not.

Failing any of these returns `403 Forbidden` with `errors: [{ "code": "TENANT_NOT_AUTHORIZED" }]`.
Once resolved, the active `company_id` is bound into the request context for the remainder of the
pipeline and is available to every Policy, Service, and Repository via a `TenantContext` singleton
— it is never re-read from client input again downstream, precisely so nothing later in the
pipeline can be tricked into trusting a second, conflicting company id smuggled into the request
body.

**RBAC model.** Permissions are dotted `<area>.<action>` strings (`accounting.journal.create`,
`bank.transfer`, `payroll.approve`, `tax.submit`, `reports.export`). A `roles` ↔ `permissions`
many-to-many mapping defines what each named role (Owner, CEO, CFO, Finance Manager, Senior
Accountant, Accountant, Auditor, HR Manager, Payroll Officer, Inventory Manager, Warehouse
Employee, Sales Manager, Sales Employee, Purchasing Manager, Purchasing Employee, Read Only,
External Auditor) can do, and `company_users` assigns one or more roles **per company** — a person
can be Accountant in one company and Read Only in another, and QAYD's authorization check is always
evaluated against the `(user, active_company)` pair, never against the user in isolation.

**Default-deny.** The absence of an explicit grant is a `403`, full stop — there is no implicit
"unlisted permission defaults to allowed." When a new permission ships (a new endpoint, a new
action on an existing resource), it is deny-by-default for every role, including Owner, until a
migration explicitly seeds the grant; this is deliberate, because it is far safer for a new
sensitive action to require an explicit follow-up grant than to accidentally inherit access through
a broad pre-existing role definition.

**Owner is not a bypass.** Even the Owner role does not skip the approval-chain requirement on
sensitive operations (`# OWASP API Security Top 10` → API6, and the platform rule that bank
transfers, payroll release, and tax submission always require human approval). RBAC in QAYD grants
the *ability to participate in* a sensitive workflow — to request it, or to approve it — never the
ability to bypass the workflow's existence. A permission like `bank.transfer` grants the right to
*initiate* a transfer request; `bank.transfer.approve` is a distinct permission that a different,
or at minimum deliberately-confirming, principal must hold to release it.

**Enforcement mechanics in Laravel.** Every tenant-scoped Eloquent model uses a global
`CompanyScope` that automatically appends `WHERE company_id = ?` (the current `TenantContext`
value) to every query touching that model — `Invoice::find($id)` cannot return another company's
row even if the id is guessed correctly, because the scope is applied before the query executes,
not checked after. Removing the scope requires an explicit `Model::withoutCompanyScope()` call,
which is reserved for internal system jobs (nightly close, scheduled report runs, migrations) that
never execute inside a request's authorization context, and every such call is itself logged.
Controller actions authorize through Policy classes registered per model
(`JournalEntryPolicy`, `InvoicePolicy`, `PayrollRunPolicy`, `BankTransferPolicy`, …), invoked via
`$this->authorize('post', $journalEntry)` or route middleware `can:accounting.journal.post`.

**Preventing IDOR (Insecure Direct Object Reference).** Route-model binding resolves a path
parameter like `{invoice}` only within the `CompanyScope`-filtered set — if the id exists but
belongs to another company, the query returns nothing and QAYD responds `404 Not Found`, **not**
`403 Forbidden`. This is a deliberate choice: a `403` confirms to a prober that the id refers to a
real record somewhere; a `404` reveals nothing about whether the id exists at all outside the
caller's own tenant. Within the *same* tenant, an access attempt that fails only on permission
(the record exists and belongs to the caller's company, but the caller's role lacks the specific
permission) correctly returns `403`, since no cross-tenant information is at risk in that case.

**Object-property-level and function-level authorization.** Some fields on an otherwise-readable
resource are independently gated — a Warehouse Employee holding generic `reports.read` should not
see `payslips.net_pay` inside a report payload merely because the parent resource is visible.
API Resource classes conditionally include such fields:
`'net_pay' => $this->when($request->user()->can('payroll.view_amounts'), $this->net_pay)`.
Separately, function-level authorization distinguishes "this resource type is visible in navigation"
from "this specific write action is callable" — a Sales Employee who can list `sales_orders` still
receives `403` calling `POST /api/v1/accounting/journal-entries`, because visibility of a resource
family never implies permission over an unrelated action (this maps directly onto OWASP API5 below).

**Approval chains as an API-level pattern.** A sensitive write does not execute synchronously to a
terminal state. `POST /api/v1/banking/transfers` with a valid `bank.transfer` grant creates the
transfer in a `pending_approval` status and returns `202`-equivalent semantics inside the standard
200 envelope (`data.status: "pending_approval"`); a second call,
`POST /api/v1/banking/transfers/{id}/approve`, gated by the distinct `bank.transfer.approve`
permission, is required before funds actually move. The AI engine may create the first request
(a draft, with its confidence score and reasoning attached) but is never granted the `.approve`
permission on any sensitive action — it cannot approve its own proposal under any circumstance,
regardless of how high its confidence score is.

**Cross-tenant access attempts are a security signal, not just a 404.** Every `404` produced by the
`CompanyScope`/route-binding path specifically because the record belongs to a different company
(as opposed to genuinely not existing) is tagged internally and forwarded to the access-log
alerting pipeline (`# Audit Logging Of API Access`) — the caller sees an ordinary `404`, but QAYD's
own monitoring sees a tenant-boundary probe.

**Permission reference (excerpt):**

| Permission key | Example endpoint | Default roles granted |
|---|---|---|
| `accounting.journal.create` | `POST /api/v1/accounting/journal-entries` | Owner, CFO, Finance Manager, Senior Accountant, Accountant |
| `accounting.journal.post` | `POST /api/v1/accounting/journal-entries/{id}/post` | Owner, CFO, Finance Manager, Senior Accountant |
| `bank.transfer` | `POST /api/v1/banking/transfers` | Owner, CFO, Finance Manager |
| `bank.transfer.approve` | `POST /api/v1/banking/transfers/{id}/approve` | Owner, CFO |
| `payroll.calculate` | `POST /api/v1/payroll/runs/{id}/calculate` | Payroll Officer, HR Manager |
| `payroll.approve` | `POST /api/v1/payroll/runs/{id}/approve` | Owner, CFO, HR Manager |
| `payroll.release` | `POST /api/v1/payroll/runs/{id}/release` | Owner, CFO |
| `tax.submit` | `POST /api/v1/tax/returns/{id}/submit` | Owner, CFO, Finance Manager |
| `reports.export` | `GET /api/v1/reports/{id}/export` | Owner, CFO, Finance Manager, Auditor, External Auditor |
| `inventory.adjust` | `POST /api/v1/inventory/stock-adjustments` | Inventory Manager |

# OWASP API Security Top 10

QAYD's threat model is organized against the OWASP API Security Top 10 (2023 edition) because it
is the industry-standard taxonomy for exactly the class of system QAYD is: an API that is the sole
gateway to sensitive, financial, multi-tenant data. Every item below states the generic risk, the
concrete way it could manifest against QAYD specifically, and the primary control already specified
elsewhere in this document.

| ID | Risk | QAYD-specific attack scenario | Primary mitigation |
|---|---|---|---|
| API1:2023 | Broken Object Level Authorization (BOLA) | Incrementing `{invoice}` in `GET /api/v1/sales/invoices/{invoice}` to read another company's invoices | `CompanyScope` global scope + tenant-scoped route-model binding, `# Authorization & Tenant Isolation` |
| API2:2023 | Broken Authentication | Weak token expiry, no revocation, predictable tokens, missing MFA on a CFO account | RS256 JWT + Redis denylist + mandatory MFA for financial-approval roles, `# Authentication & Token Security` |
| API3:2023 | Broken Object Property Level Authorization | A Sales Employee's `reports.read` grant incidentally exposing `payslips.net_pay` inside a combined report payload | Conditional field inclusion in API Resources, `# Authorization & Tenant Isolation` |
| API4:2023 | Unrestricted Resource Consumption | A scripted client requesting `per_page=100000` on `GET /api/v1/accounting/ledger-entries`, or hammering the AI-forecast endpoint | Enforced pagination ceiling, Redis-backed rate limits, request body size caps, `# Rate Limiting & Abuse Prevention` |
| API5:2023 | Broken Function Level Authorization | A Sales Employee token calling `POST /api/v1/accounting/journal-entries` directly, bypassing the UI that would never show that action | Route-level `can:` middleware checked independently of resource visibility, `# Authorization & Tenant Isolation` |
| API6:2023 | Unrestricted Access to Sensitive Business Flows | Automating `bank.transfer` calls in a tight loop to drain funds via many small transfers before a human notices | Mandatory approval-chain state machine + per-endpoint sensitive-action rate ceilings + anomaly alerting, `# Authorization & Tenant Isolation`, `# Rate Limiting & Abuse Prevention` |
| API7:2023 | Server-Side Request Forgery (SSRF) | A configured bank-feed webhook URL or an OCR "fetch this receipt image URL" feature pointed at `http://169.254.169.254/` (cloud metadata endpoint) or an internal-only service | URL scheme/host allow-listing, DNS-rebinding-resistant resolution, blocking link-local/private ranges, egress proxy for any server-initiated fetch, detailed below |
| API8:2023 | Security Misconfiguration | A staging CORS wildcard accidentally shipped to production, verbose Laravel debug pages left on, default credentials on an admin tool | Environment-specific config linting in CI, `APP_DEBUG=false` enforced in production by a deploy-time check, `# CORS Policy`, `# Security Headers` |
| API9:2023 | Improper Inventory Management | A deprecated `/api/v1/legacy/...` route or an old API key from a decommissioned integration still live and unmonitored | OpenAPI spec as the single enumerated inventory of every route, automated diffing that fails CI if a live route is undocumented, scheduled API-key audits, `# Secrets Management` |
| API10:2023 | Unsafe Consumption of APIs | Trusting an unvalidated response from a third-party bank-aggregator or payment-processor webhook as if it were internally generated | Same input-validation rules applied to inbound third-party payloads as to client requests; webhook signature verification before any payload is trusted, `# Request Signing` |

**SSRF deserves elaboration** because QAYD's AI and integration surface genuinely fetches remote
resources — bank-feed synchronization, an OCR agent pulling a receipt image from a URL a user
pasted, a webhook the platform must itself deliver to a company-configured endpoint. Every one of
these server-initiated fetches goes through a single egress path that: resolves DNS once and
re-validates the resolved IP is not in a private/link-local/loopback range immediately before
connecting (defeating DNS-rebinding, where a hostname resolves to a public IP at validation time
and a private IP at connection time); refuses redirects to a different host than initially
validated; enforces an allow-list of schemes (`https` only, never `file://`, `gopher://`, or
internal `http://`); and runs the outbound call through an egress proxy that has no route to
QAYD's own internal services, so even a successful SSRF cannot reach the database or the AI
engine's internal admin surface — it can only reach the same public internet any other outbound
call could reach.

**Unrestricted access to sensitive business flows deserves elaboration** because it is the item
most specific to a financial platform: rate limiting alone (API4) stops raw volume, but a
sufficiently patient attacker moving funds in amounts and intervals designed to look like normal
usage would not trip a simple rate ceiling. QAYD's control here is structural, not just
throttling — every flow named "sensitive" in `DESIGN_CONTEXT.md` (bank transfer, payroll release,
tax submission, permission changes) is modeled as a two-actor state machine (`# Authorization &
Tenant Isolation`), so no single compromised credential — human or the AI engine's own — can drive
a sensitive flow to completion alone, regardless of request volume or pacing.

# Input Validation & Output Encoding

Every write endpoint is backed by a Laravel `FormRequest` class whose `rules()` method is a
**whitelist**: fields not explicitly declared are rejected, not silently ignored. A
`ValidatesAgainstOpenApiSchema` middleware runs ahead of the `FormRequest` and diffs the raw
request body's top-level keys against the OpenAPI-defined schema for that `operationId`
(`additionalProperties: false` in the spec, enforced in code, not just documented); an unknown key
produces `422 Unprocessable Entity` with a field-level entry in `errors[]` before the request even
reaches business logic:

```json
{
  "success": false,
  "data": null,
  "message": "Validation failed.",
  "errors": [
    { "field": "lines[1].tax_rate_id", "code": "UNKNOWN_FIELD", "message": "tax_rate_id is not a recognized field on this resource." }
  ],
  "meta": { "pagination": null },
  "request_id": "6b3a2e91-4c8f-4a1d-9e2b-1f7c3d5a8b02",
  "timestamp": "2026-07-16T09:20:11Z"
}
```

**Type and format rules.** Money fields are validated as decimal strings, maximum 4 decimal
places, range-checked against the column's `NUMERIC(19,4)` bounds before ever reaching the
database — a value that would overflow or silently truncate at the database layer is rejected at
the API boundary with a clear error rather than allowed to produce a rounding discrepancy in a
ledger. `currency_code` is validated against the ISO 4217 enum. Dates and datetimes are required in
ISO-8601 form. Status/enum fields are validated against the live database-backed set of valid
values for that column (not a hard-coded PHP enum that can drift out of sync with a migration that
added a new status). IBANs are validated with the mod-97 checksum algorithm before being accepted
into `vendor_bank_accounts.iban` or `bank_accounts.iban`; national ID fields are validated against
the check-digit algorithm of the issuing jurisdiction configured for the company.

**`custom_fields` (JSONB) validation.** Because `custom_fields` is schemaless at the database
level by design, it is not schemaless at the API level: each company defines the permitted shape of
`custom_fields` per entity type, and the API validates an incoming `custom_fields` object against
that company's stored schema for the target entity — unknown keys are rejected with the same
`UNKNOWN_FIELD` treatment as a top-level field, and value types (string/number/boolean/date/enum)
declared in the schema are enforced exactly as if they were first-class columns.

**File upload validation.** Attachments (the polymorphic `attachments` foundation table) are
validated on: real content type via magic-byte sniffing of the first bytes of the file — never
trusting the client-supplied `Content-Type` header alone; maximum size per accepted type (for
example 15 MB for images, 25 MB for PDFs); an extension allow-list; filename sanitization that
strips path-traversal sequences (`../`), null bytes, and control characters before the filename is
ever used to construct a storage key; a malware-scan hook (an antivirus scanning service) that
every upload passes through before it is persisted to Cloudflare R2, with infected files rejected
and logged; and, for images specifically, re-encoding through an image-processing library that
strips EXIF metadata (including embedded GPS coordinates) before storage, both for privacy and to
neutralize any malformed-metadata exploit targeting a downstream image viewer.

**Output encoding.** The API's `Content-Type` response header is always `application/json`; it
never reflects user-supplied input back as `text/html`, which removes reflected-XSS as a
possibility at the API layer entirely — there is no code path in which a crafted query parameter
becomes rendered markup in the API's own response. On the Next.js side, React's default JSX
interpolation escapes all rendered text; `dangerouslySetInnerHTML` is banned everywhere in the
codebase except through one reviewed utility function that first runs the content through
DOMPurify with a strict, minimal allow-list of tags — used specifically for AI-generated
explanatory text that may contain basic formatting, never for arbitrary user or third-party HTML.
`X-Content-Type-Options: nosniff` (`# Security Headers`) further ensures a browser cannot be
tricked into MIME-sniffing a JSON response as executable HTML even under a client misconfiguration.

**Bilingual field handling.** `name_en` and `name_ar` (and other bilingual pairs) are validated
independently for length and script; both are scanned for and reject Unicode bidirectional-override
control characters (such as `U+202E`, RIGHT-TO-LEFT OVERRIDE) that could otherwise be used to make
an amount or account name visually misrepresent itself in a right-to-left rendering context — a
narrow but real spoofing vector on a bilingual financial platform.

**Payload size limits.** A global maximum request body size (2 MB for ordinary write endpoints,
raised only for explicitly designated bulk-import endpoints, which instead switch to chunked/async
processing) is enforced at the edge, before the payload reaches PHP-FPM, so an oversized body is
rejected cheaply rather than consuming application-server memory and CPU parsing it.

```php
// app/Http/Requests/Accounting/PostJournalEntryRequest.php
public function rules(): array
{
    return [
        'lines' => ['required', 'array', 'min:2'],
        'lines.*.account_id' => [
            'required', 'integer',
            Rule::exists('accounts', 'id')->where('company_id', $this->activeCompanyId()),
        ],
        'lines.*.debit'  => ['required_without:lines.*.credit', 'decimal:0,4', 'min:0'],
        'lines.*.credit' => ['required_without:lines.*.debit', 'decimal:0,4', 'min:0'],
        'lines.*.cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')],
        'lines.*.project_id'     => ['nullable', 'integer', Rule::exists('projects', 'id')],
        'currency_code' => ['required', Rule::in(config('qayd.supported_currencies'))],
        'memo' => ['nullable', 'string', 'max:500'],
    ];
}

public function withValidator(Validator $validator): void
{
    $validator->after(function (Validator $v) {
        $lines = collect($this->input('lines', []));
        $debits  = $lines->sum(fn ($l) => (float) ($l['debit']  ?? 0));
        $credits = $lines->sum(fn ($l) => (float) ($l['credit'] ?? 0));
        if (round($debits, 4) !== round($credits, 4)) {
            $v->errors()->add('lines', 'Journal entry is not balanced: debits must equal credits.');
        }
    });
}
```

# Injection Prevention

**SQL injection.** Eloquent and the Query Builder's parameter binding is the only sanctioned way to
build a query with dynamic input. `DB::raw()` and `whereRaw()` calls that interpolate a variable
directly into the SQL string are banned by a custom Larastan/PHPStan rule that runs in CI and fails
the build — the only accepted form of raw SQL uses bound placeholders (`?` or named bindings)
exclusively, even for something as innocuous-seeming as an `ORDER BY` column name, which is instead
validated against an explicit allow-list of sortable columns before being interpolated into the
identifier position (which cannot be parameter-bound in standard SQL).

**Mass assignment.** Every Eloquent model declares `$guarded` explicitly, and it always includes,
at minimum, `id`, `company_id`, `branch_id`, `created_by`, `updated_by`, `created_at`, `updated_at`,
and `deleted_at` — a bare `$guarded = []` (Laravel's permissive default) is banned by the same
static-analysis gate. Tenant and audit columns are set exclusively by the Service layer from the
authenticated request context, never taken from client input, even defensively: if a request body
includes a `company_id` key at all on a create/update call, the API rejects it outright with
`422` / `IMMUTABLE_FIELD` rather than silently discarding it, because silent discarding hides a
client bug (or probe) that a hard rejection surfaces immediately.

**JSONB injection.** Queries against `custom_fields` (`custom_fields->>'key'` style JSONB path
operators) always bind both the key name and the compared value as parameters — a key name is never
string-concatenated into the SQL text — and only keys present in the entity's registered
`custom_fields` schema (`# Input Validation & Output Encoding`) are queryable at all, which
additionally prevents an attacker from using an arbitrary, unindexed JSONB path expression as a
query-planner denial-of-service vector.

**Command and template injection.** No endpoint invokes a shell command with any user-controlled
argument. Report and document generation uses a templating/PDF library rather than a
headless-browser-renders-arbitrary-HTML pattern; where HTML-to-PDF rendering is used at all, the
HTML is assembled from a fixed template with Blade's default escaped `{{ }}` interpolation — the
raw, unescaped `{!! !!}` syntax is banned everywhere except inside the one reviewed AI-explanation
rendering utility, and only after that utility's DOMPurify pass (`# Input Validation & Output
Encoding`).

**Log injection.** Free-text fields that flow into audit or access logs — journal narrations,
invoice memos, rejection reasons — have newline and control characters stripped or escaped before
being written to any log sink, so a crafted memo field cannot forge what looks like an additional,
independent log line to anyone reviewing raw logs later.

**Webhook and callback URL injection.** Any user-configurable URL — a company's webhook endpoint,
a bank-feed callback — is restricted to the `https` scheme and is subject to the identical
SSRF-hardening egress path described under API7 in `# OWASP API Security Top 10`: private,
link-local, and loopback address ranges are refused regardless of what hostname was configured,
closing off the obvious path of registering `http://localhost/...` or an internal service address
as a "webhook."

**ReDoS (regular-expression denial of service).** Validation regexes used across `FormRequest`
rules are reviewed for catastrophic backtracking patterns (nested quantifiers over the same
character class) as part of code review, and input length is bounded before any regex is applied to
it, so even an unnoticed pathological pattern cannot be driven into worst-case behavior by an
arbitrarily long input string.

**Injection control summary (table):**

| Injection class | QAYD-specific vector | Control |
|---|---|---|
| SQL injection | Dynamic `whereRaw`/`DB::raw` string building | Parameter binding mandatory; CI static-analysis ban on interpolated raw SQL |
| Mass assignment | Client-supplied `company_id`/`created_by` in a write body | Explicit `$guarded`, hard `422 IMMUTABLE_FIELD` rejection, never silent drop |
| JSONB injection | Dynamic `custom_fields` path/key building | Bound key + value, schema-restricted queryable key allow-list |
| Command injection | Report/PDF generation shelling out | No shell invocation with user input; template-based rendering only |
| Template injection | Raw Blade `{!! !!}` on user/AI text | Escaped interpolation mandatory; one audited exception path with DOMPurify |
| Log injection | Newlines in memo/narration fields | Control-character stripping before any log write |
| SSRF via configured URL | Webhook/bank-feed endpoint pointed at an internal address | Scheme allow-list, private/link-local IP rejection at connect time, egress proxy |
| ReDoS | Pathological validation regex | Backtracking review in code review, length-bounded input before regex |

# CORS Policy

Cross-Origin Resource Sharing governs one thing only: which browser-hosted origins may read the
API's responses to a cross-origin request. It is a browser-enforced control, not a server-side
authorization control — QAYD treats it strictly as a first line of defense for the browser-facing
surface, never as a substitute for the RBAC checks in `# Authorization & Tenant Isolation`, which
apply identically regardless of what CORS did or did not permit.

**Allow-listed origins**, by environment, explicitly enumerated — never a wildcard:

| Environment | Allowed origins |
|---|---|
| Production | `https://app.qayd.com` |
| Staging | `https://staging.qayd.com` |
| Local development | `http://localhost:3000` (development configuration only; this entry does not exist in the production or staging config file at all, so it cannot be accidentally shipped) |

`Access-Control-Allow-Origin: *` is never combined with `Access-Control-Allow-Credentials: true` —
this combination is browser-illegal and QAYD additionally lints for it in CI so a misconfiguration
cannot silently ship even in a browser lenient enough to allow it.

**No ambient credentials, by design.** Because authentication is a bearer token carried explicitly
in the `Authorization` header — never a cookie the browser attaches automatically — QAYD's general
API does not set `Access-Control-Allow-Credentials: true` at all for most routes. A malicious page
loaded in a victim's browser cannot ride the victim's QAYD session merely by issuing a cross-origin
request, because there is no ambient session for the browser to attach; the malicious page would
need to already possess the bearer token, which is a different (and separately defended) problem.
This removes an entire class of CORS-adjacent CSRF risk by architectural choice rather than by
configuration diligence alone. The one place a browser-held credential is relevant is Laravel
Reverb's WebSocket channel authorization; QAYD keeps the same invariant there by having the client
perform channel authorization as a normal bearer-authenticated HTTPS call rather than relying on any
ambient cookie.

**Allowed methods and headers.**

```php
// config/cors.php
return [
    'paths' => ['api/v1/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => [env('FRONTEND_ORIGIN')], // exactly one, per environment — never '*'
    'allowed_headers' => [
        'Authorization', 'Content-Type', 'X-Company-Id', 'X-Request-Id',
        'Idempotency-Key', 'Accept-Language',
    ],
    'exposed_headers' => [
        'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset',
        'Retry-After', 'X-Request-Id',
    ],
    'max_age' => 86400,
    'supports_credentials' => false,
];
```

**Server-to-server callers are out of CORS's scope entirely.** The FastAPI AI engine and
third-party integrations never call the API from a browser context — CORS headers are irrelevant to
them, and QAYD's CORS configuration makes no special case for them. Their access is instead scoped
and isolated by the service-JWT/API-key mechanisms in `# Authentication & Token Security` and, where
applicable, network-level allow-listing — a permissive CORS entry is never used as a workaround for
integrating a server-side caller.

# Secrets Management

**Inventory.** The secrets the API layer depends on fall into five groups: database credentials
(PostgreSQL); the Redis auth password; the JWT RS256 keypair used to sign and verify bearer tokens;
object-storage credentials (Cloudflare R2 access keys); and a set of narrower per-purpose secrets —
Laravel Reverb app credentials, per-company webhook signing secrets, the FastAPI AI engine's service
token and its own upstream model-provider key, TLS private keys, the TOTP/MFA secret-encryption
key, and the KMS-managed column-encryption keys for Restricted-class data (`# PII & Financial Data
Protection`).

**Storage.** No secret is ever committed to source control. `.env` files are git-ignored, a
pre-commit hook scans staged changes for secret-shaped strings before they can be committed, and a
CI-stage `gitleaks` scan blocks any merge that slips a credential-shaped pattern past the local
hook. Production secrets are held in a managed secrets vault and injected as environment variables
at container start — never baked into a container image, where they would persist in every layer
and registry copy of that image indefinitely. Local development and staging use entirely separate
credential sets from production for every one of the five groups above; a developer's laptop
configuration cannot reach a production database or a production R2 bucket under any circumstance,
because the credentials that would be required simply do not exist outside the production vault.

**Least privilege per service.** The Laravel application's runtime identity can read the database,
Redis, R2, and JWT-signing-key secrets it needs to operate. The FastAPI AI engine's runtime identity
can read only its own narrow service-token secret and its own model-provider key — it cannot read
the JWT signing key, the database credentials, or any other service's secret, so a compromise of the
AI engine's runtime does not, by itself, grant the ability to mint valid QAYD API tokens or reach
the database directly. Every read of a secret from the vault is itself logged: which service
identity, which secret name, when — a secret-access log distinct from, but reviewed alongside, the
API access log in `# Audit Logging Of API Access`.

**Rotation.** The JWT signing keypair rotates on a fixed schedule (every 90 days) with an overlap
window: Laravel publishes both the outgoing and incoming public keys to an internal
JWKS-style endpoint consumed by every resource server that verifies tokens (Reverb, the AI engine),
so a token signed under the outgoing key remains verifiable until the longest-lived token issued
under it naturally expires, at which point the outgoing key is retired entirely. Database and Redis
credentials rotate quarterly on a normal schedule and immediately, out of cycle, on any suspected
exposure. Per-company webhook signing secrets are rotatable on demand from company settings, with
the same dual-secret grace-period mechanism described in `# Request Signing`, so rotating a secret
never breaks a delivery already in flight.

**Break-glass access.** A documented emergency procedure allows access to a secret outside its
normal automated path — for example, a production database credential needed for an emergency
manual intervention during an incident — but only under dual control (two authorized engineers
acting together) and only with the access itself logged and reviewed by the security lead after the
fact, whether or not the emergency turned out to require it.

**CI/CD secret separation.** Build-pipeline credentials (used to push a deploy artifact) are scoped
per environment, and the credential capable of deploying to production requires a distinct,
separately-gated approval step from the credential used for staging deploys — a compromised staging
deploy credential cannot, by itself, push a change to production.

# Request Signing

**Webhook delivery signing.** Every webhook QAYD fires — `invoice.created`, `invoice.paid`,
`payment.received`, `payroll.completed`, `inventory.updated`, `bank.synced`, `ai.finished`,
`journal.posted` — is signed with an HMAC-SHA256 signature carried in an
`X-Qayd-Signature` header of the form `t=<unix_timestamp>,v1=<hex_hmac>`, where the HMAC is computed
over the literal string `"{t}.{raw_request_body}"` using the destination's per-company (or,
where a company configures more than one endpoint, per-endpoint) signing secret. A receiver must
recompute the same HMAC with its own copy of the secret and compare it to the delivered value using
a constant-time comparison, and must reject the delivery if the `t` timestamp is more than five
minutes from the receiver's own clock — this bounds the replay window even if a delivered payload
were somehow captured and resent later by a party other than QAYD.

```
POST https://receiver.example.com/webhooks/qayd
Content-Type: application/json
X-Qayd-Signature: t=1752657600,v1=8f3a1c2e9b7d4f6a1e2c3b4d5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a
X-Qayd-Event: invoice.paid
X-Request-Id: 7c1a9b3e-5f2d-4e8a-9c1b-3a5d7e9f1c2b

{
  "event": "invoice.paid",
  "data": { "invoice_id": 88231, "company_id": 101, "amount": "1250.0000", "currency_code": "KWD" },
  "id": "evt_9f3a2b1c8e7d",
  "timestamp": "2026-07-16T09:31:44Z"
}
```

```php
// Receiver-side verification (reference implementation QAYD publishes in its SDK docs)
$signatureHeader = $request->header('X-Qayd-Signature');
[$tPart, $v1Part] = explode(',', $signatureHeader);
$timestamp = (int) str_replace('t=', '', $tPart);
$signature = str_replace('v1=', '', $v1Part);

if (abs(time() - $timestamp) > 300) {
    abort(400, 'Signature timestamp outside the acceptable window.');
}

$expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $webhookSigningSecret);

if (! hash_equals($expected, $signature)) {
    abort(401, 'Invalid webhook signature.');
}
```

**Delivery retries.** A failed delivery (non-2xx response, or timeout) retries with exponential
backoff — immediately, then at 1 minute, 5 minutes, 30 minutes, 2 hours, up to a maximum window of
24 hours before QAYD gives up and surfaces the failure in the company's webhook-delivery dashboard.
The event payload is immutable once fired — a retry resends the exact original body with a freshly
computed signature (new `t`, same body, so the hash differs only in timestamp) — which lets a
receiver safely deduplicate on the payload's own `id` field regardless of how many times it was
attempted.

**Idempotency keys on financial writes.** Endpoints prone to client-side double-submission —
`POST /api/v1/sales/invoices`, `POST /api/v1/purchasing/vendor-payments`,
`POST /api/v1/payroll/runs/{id}/release` among others — accept an `Idempotency-Key` header. QAYD
stores a mapping of `(idempotency_key, request_body_hash) → original response` for 24 hours; a
repeated request with the same key and the same body hash replays the stored response verbatim
without re-executing the underlying financial mutation, while a repeated request with the same key
but a *different* body hash is rejected with `409 Conflict` and
`errors: [{ "code": "IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD" }]` — a client cannot silently
reuse a key to smuggle a different transaction through under cover of an expected-idempotent retry.

**Service-to-service signing.** Beyond the AI engine's service JWT (`# Authentication & Token
Security`), every request the AI engine sends to the Laravel API additionally carries an
`X-Qayd-Agent-Signature` header, computed identically to the webhook scheme but with a secret
specific to the AI engine's service identity. This is deliberate defense in depth: a network
position that could steal or relay a bearer token in transit still cannot forge a request body the
AI engine did not actually produce, because doing so would also require the separate signing
secret, which is never transmitted alongside the token itself.

**Canonicalization.** A signature is always computed over the exact raw bytes transmitted — never
a value that has been parsed and re-serialized — because differences in key ordering or whitespace
between a signer's and a verifier's JSON serializer would otherwise produce mismatched signatures
for a semantically identical payload; QAYD's SDKs sign (and verify) against the literal request
body bytes for this reason.

**Signing-secret rotation.** Webhook and service-signing secrets rotate through the same
dual-secret grace-period mechanism as other secrets (`# Secrets Management`): both the outgoing and
incoming secret verify successfully for a bounded overlap period so an in-flight delivery signed
under the old secret is not spuriously rejected during a rotation.

# Rate Limiting & Abuse Prevention

**Backing store.** All rate-limit counters live in Redis (the fixed platform cache/queue store),
never in an individual application server's memory — because Laravel runs as multiple stateless
instances behind a load balancer, an in-process counter would let a client simply spread requests
across instances to evade a limit; a shared Redis counter closes that gap. General endpoints use a
sliding-window-counter algorithm (more resistant to the burst-at-the-boundary problem of a naive
fixed window); bulk-tolerant endpoints such as CSV/bulk import use a token bucket that permits
short bursts within an overall budget.

**Rate-limit tiers (table):**

| Scope | Endpoint class | Limit | Window |
|---|---|---|---|
| Per IP, unauthenticated | `POST /api/v1/auth/login` | 5 requests | per minute, per IP **and** per account attempted |
| Per IP, unauthenticated | `POST /api/v1/auth/mfa/verify`, OTP send | 3 requests | per minute |
| Per account | `POST /api/v1/auth/password/reset-request` | 3 requests | per hour |
| Per user + company | General authenticated read/write traffic | 300 requests | per minute |
| Per company | `POST /api/v1/banking/transfers` | 10 requests | per hour |
| Per company | AI-engine-originated calls (`ai-engine:*` token) | 60 requests | per minute |
| Per integration API key | Server-to-server integration traffic | Configurable per integration tier, default 600 requests | per minute |

A request rejected for rate limiting receives `429 Too Many Requests` in the standard envelope,
with `errors: [{ "code": "RATE_LIMITED" }]`, and headers `X-RateLimit-Limit`,
`X-RateLimit-Remaining`, `X-RateLimit-Reset`, and `Retry-After` so a well-behaved client can back
off precisely rather than guessing.

**Account lockout with bounded backoff.** Repeated failed logins trigger an exponentially
increasing cooldown (1 minute after 5 failures, doubling up to a capped ceiling) rather than a hard
permanent lock — a permanent lock keyed on a known email address would itself be a trivial
denial-of-service vector against a legitimate user by a third party who merely knows their address.
A CAPTCHA challenge is additionally required on the human-facing login form after 3 consecutive
failures. Machine credentials (integration API keys, the AI engine's service token) have no CAPTCHA
option by construction; repeated authentication failures on a machine credential instead escalate
directly to temporary key suspension plus an alert to the security channel.

**Abuse heuristics beyond raw volume.** Rate limiting alone does not catch a patient, low-and-slow
attacker. QAYD additionally flags: impossible-travel (the same account authenticating from two
geographically implausible locations inside an implausible time window), which triggers a
step-up MFA challenge on the next sensitive action rather than an outright block; credential-stuffing
patterns (many distinct target accounts, one IP or user-agent fingerprint, an abnormally high
failure ratio), which triggers IP-level throttling at the edge WAF; and a sudden spike in
`reports.export` calls from a single account relative to its own baseline, which is flagged for
manual data-exfiltration review rather than silently allowed through merely because each individual
call is within its own per-minute limit.

**Edge-layer absorption.** The WAF/CDN edge in front of the API absorbs volumetric and known
bad-bot traffic before it ever reaches PHP-FPM, which keeps the Redis-backed application-level
limiter focused on business-logic-aware abuse (the tiers and heuristics above) rather than having to
also serve as QAYD's only line of defense against raw traffic floods.

# PII & Financial Data Protection

**Data classification.** Every field QAYD stores or returns falls into one of four classes:

| Class | Definition | Examples |
|---|---|---|
| Public | No confidentiality requirement | Product catalog names, published report templates |
| Internal | Ordinary business data, confidential to the company but not individually sensitive | Invoice line items, purchase order status, cost-center names |
| Confidential | Company financial data whose exposure would damage the company | Full ledger balances, profit figures, unposted journal drafts |
| Restricted | Individually sensitive PII or financial-instrument identifiers | National ID numbers, bank account numbers, IBANs, salary figures, tax identification numbers |

Every endpoint's OpenAPI schema tags each response field with its class, and the classification
drives three independent behaviors: encryption at rest, masking in API responses, and log
redaction — a field is never exempted from one of these three just because it happens to pass the
others.

**Encryption at rest.** Restricted-class columns — `vendor_bank_accounts.account_number`,
`vendor_bank_accounts.iban`, `bank_accounts.account_number`, `employees.national_id`,
`employees.tax_id`, and equivalent columns wherever they occur — are encrypted at the application
layer using AES-256-GCM (Laravel's encrypted attribute casting), in addition to whatever
storage-level encryption PostgreSQL's underlying volume already provides. Application-level
encryption is the layer QAYD actually depends on for this data, because it protects the value even
against someone with direct, unfiltered access to a database dump or backup — volume encryption
alone would not. Encryption keys are envelope-encrypted via the KMS described in `# Secrets
Management`: a per-record data-encryption key is itself encrypted by a key-encryption key that never
leaves the KMS boundary, so no application process ever holds the master key in memory for longer
than a single encrypt/decrypt call.

**Masking by default.** A Restricted-class field is never returned unmasked by default, regardless
of whether the caller can otherwise read the parent resource:

```json
{
  "success": true,
  "data": {
    "id": 4471,
    "vendor_id": 812,
    "bank_name": "Gulf Commercial Bank",
    "iban": "KW** **** **** **** **34",
    "account_number_masked": "••••••2290"
  },
  "message": "Vendor bank account retrieved.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d",
  "timestamp": "2026-07-16T09:40:02Z"
}
```

Viewing the unmasked value requires a distinct permission from viewing the resource itself —
`vendors.bank_details.view_full`, separate from plain `vendors.read` — and calling the
unmasked-view action is itself logged as its own audited event (who viewed which record's unmasked
value, and when), independent of the ordinary read-access log entry, precisely because "can see
that this vendor has a bank account on file" and "can see the account number" are different
authorizations under `# Authorization & Tenant Isolation`'s object-property-level model (OWASP
API3).

**Card data is out of scope by design.** QAYD's own database never stores a card primary account
number (PAN), CVV, or magnetic-stripe/track data. Online card collection (for a customer receipt,
for example) is handled by tokenizing directly through a PCI-DSS-compliant payment processor; QAYD
persists only the processor's opaque reference token. This keeps QAYD's own systems outside the
PCI-DSS cardholder-data-environment boundary rather than attempting to build PCI-compliant storage
in-house.

**Data residency.** Company data is stored in-region by default per each company's configured
jurisdiction. Where the AI engine's underlying model inference occurs outside that region, only
de-identified data — or data the company has explicitly consented to process cross-border — is
sent to it, and the fact of any cross-border processing is recorded in the company's
data-processing record, visible to the company's Owner in settings.

**Retention versus the right to erasure.** Financial records are never hard-deleted (the fixed
platform rule), and most jurisdictions QAYD operates in mandate multi-year retention of accounting
records regardless of any individual's erasure request. QAYD resolves this by distinguishing the
*person* from the *transaction*: an erasure request is honored for directly identifying fields not
required for statutory retention (a contact's name is replaced with a stable pseudonym, direct
contact fields are nulled), while the underlying financial transaction rows — amounts, account
references, dates, the fact that a transaction occurred — remain intact and immutable for the
mandated retention period. The anonymization operation is itself service-mediated (never a raw
`UPDATE` run by hand) and is captured in `audit_logs` like any other mutation, so the erasure event
itself remains auditable even though the personal data it acted on is now gone.

**Redaction in logs and telemetry.** A single, centrally maintained allow-list of sensitive field
names (`national_id`, `account_number`, `iban`, `password`, `token`, `otp_code`, `tax_id`, and their
equivalents) is redacted by one shared logging formatter before any log line, stack trace, or
error-tracking event leaves the process. This is enforced centrally — as a formatter every log
transport passes through — specifically so an individual engineer's forgotten
`Log::info($request->all())` during debugging cannot leak a national ID or bank number into a log
aggregator that a much larger set of people can query than should ever see that value.

**Export controls.** Bulk-export endpoints (`reports.export` and equivalents) require a permission
distinct from the underlying `reports.read`, apply the identical masking rules to the exported file
that would apply to the same data on screen (an export is never a way to see more than the UI
would), and record who exported what filter set and how many rows per export — a large or unusual
export is therefore both prevented from over-exposing Restricted data and independently detectable
as a potential exfiltration pattern by the alerting rules in `# Audit Logging Of API Access` and the
abuse heuristics in `# Rate Limiting & Abuse Prevention`.

# Audit Logging Of API Access

QAYD maintains two logs that must not be conflated. The business `audit_logs` table (the fixed
foundation table) captures **data-level mutations** — who changed which row, the old value, the new
value, a reason where applicable. **API access logging**, the subject of this section, captures
**every request the API receives**, including reads that never touch a mutation at all — this
second log is what makes reconnaissance visible: an attacker enumerating invoice ids across
companies produces no row in `audit_logs` (nothing changed) but produces a very visible pattern of
404s in the access log.

**Access log fields (table):**

| Field | Description |
|---|---|
| `timestamp` | Request received time, UTC |
| `request_id` | The same id returned in the response envelope; threads through any FastAPI call or webhook the request triggers |
| `method`, `path`, `route_name` | The HTTP method and the resolved route/`operationId` |
| `user_id` | Nullable — absent for pre-authentication requests |
| `company_id` | The resolved active company, where applicable |
| `ip_address`, `user_agent`, `device_id` | Caller network/device identity |
| `auth_method` | `bearer` \| `service_token` \| `api_key` |
| `token_jti` | The token's revocation-key claim — never the token itself |
| `response_status` | HTTP status returned |
| `latency_ms` | Request processing time |
| `rate_limit_bucket` | Which rate-limit tier this request was evaluated against |
| `cross_tenant_probe` | Boolean flag set when a 404 resulted specifically from `CompanyScope` excluding another company's record |

**`request_id` propagation.** The id is generated at the edge (or accepted from a trusted, already
TLS-terminated upstream) and threaded through every system a single logical operation touches:
Laravel's own processing, any call it makes to the FastAPI AI engine, and any webhook delivery that
operation causes. One id therefore lets an engineer reconstruct the full cross-system story of a
single client-visible operation from three separate log pipelines, which is the difference between
a five-minute investigation and a multi-hour one during an incident.

**Redaction and tamper resistance.** The identical sensitive-field allow-list from `# PII &
Financial Data Protection` is applied before an access-log entry is persisted; the `Authorization`
header's value itself is never logged, only the `jti` and subject it resolves to. Access logs are
append-only and shipped near-real-time to a log store the application servers hold no delete
permission against — from the application's own perspective, its credential to the log pipeline is
write-only — with hash-chaining (or an equivalent WORM retention policy) applied to the
security-relevant subset specifically so that a compromised application server cannot retroactively
erase evidence of its own compromise.

**Retention.** Access logs are retained a minimum of one year, aligned to typical financial-audit
review windows. The business `audit_logs` table is retained for the full statutory record-retention
period applicable to the company's jurisdiction, and is never purged early even after a related
record has been anonymized under the erasure process in `# PII & Financial Data Protection` — the
audit trail of *that anonymization having happened* is itself a permanent record.

**Alerting rules derived from the access log (table):**

| Pattern | Interpretation | Response |
|---|---|---|
| Spike in `401` responses from one IP across many accounts | Credential-stuffing attempt | IP-level throttling at the edge, security alert |
| Spike in `403` for one user across many different companies | Possible compromised-account tenant-hopping attempt | Force re-authentication, security review |
| Unusually broad `reports.export` activity from one account | Potential data exfiltration | Flag for manual review, temporary export throttle |
| Repeated `404` on sequentially incrementing resource ids | BOLA/enumeration probing (OWASP API1) | Security alert, consider temporary IP block |
| Successful call to a `.approve` / `.release` / `.submit` action outside business hours from a new device | Possible compromised-approver-credential | Step-up review, notify the account's other approvers |

**SIEM export.** The access-log stream is exported to a security analytics platform where the
alerting rules above are implemented as standing detections, not left as raw log volume nobody is
actively watching — the alerting table is a specification of detections that must exist as running
queries, not merely documentation of what an engineer could look for manually.

# Security Headers

Every API response carries the following headers, applied by a single global Laravel middleware so
no individual controller can accidentally omit them:

| Header | Value | Rationale |
|---|---|---|
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains; preload` | Forces HTTPS for two years, including subdomains; eligible for browser HSTS-preload lists |
| `X-Content-Type-Options` | `nosniff` | Prevents a browser from MIME-sniffing a JSON response into executable content |
| `X-Frame-Options` | `DENY` | Legacy clickjacking protection for any browser that does not honor `frame-ancestors` |
| `Content-Security-Policy` | `default-src 'self'; connect-src 'self' https://api.qayd.com wss://realtime.qayd.com; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'` | Applied to the Next.js web app's own document responses; no inline/`eval` script execution, no framing |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Avoids leaking full request paths (which may contain resource ids) to third-party referrer targets |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(self)` | Camera reserved for the receipt/document-photo capture feature; every other sensitive browser capability denied by default |
| `Cross-Origin-Opener-Policy` | `same-origin` | Isolates the browsing context from cross-origin popups/windows |
| `Cross-Origin-Resource-Policy` | `same-site` | Prevents another site from embedding QAYD API responses as a subresource |
| `Cache-Control` | `no-store` on any response carrying Confidential or Restricted-class data | Ensures bank details, payslips, and similar responses are never persisted by a browser or intermediary cache |
| `Server` | Suppressed/generic | Avoids advertising the exact Laravel/PHP version to reduce fingerprinting |

```php
// app/Http/Middleware/SecurityHeaders.php
public function handle(Request $request, Closure $next): Response
{
    $response = $next($request);

    $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
    $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
    $response->headers->remove('Server');

    if ($request->attributes->get('response_data_class') >= DataClass::CONFIDENTIAL) {
        $response->headers->set('Cache-Control', 'no-store');
    }

    return $response;
}
```

```js
// next.config.js — headers for the Next.js web app's own document responses
module.exports = {
  async headers() {
    return [{
      source: '/(.*)',
      headers: [
        { key: 'Content-Security-Policy', value: "default-src 'self'; connect-src 'self' https://api.qayd.com wss://realtime.qayd.com; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'" },
        { key: 'X-Content-Type-Options', value: 'nosniff' },
        { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        { key: 'Permissions-Policy', value: 'geolocation=(), microphone=(), camera=(self)' },
      ],
    }];
  },
};
```

# Penetration Testing & Bug Bounty

**Cadence.** An independent third-party penetration test is commissioned at least annually, and
additionally after any material architecture change — adding a new payment rail, launching the
mobile app in a new market, or introducing a new AI agent with write-adjacent capability. An
internal, security-focused code review of the API layer runs quarterly between external
engagements, so the gap between independent assessments is never more than a few months of
unreviewed change.

**Scope.** A penetration test covers the full `/api/v1/` surface, including flows the AI engine
triggers on a user's behalf; the Next.js web application; the Flutter mobile application, including
its local storage and keychain/keystore handling of tokens; webhook delivery and signature
verification; and any internal admin/back-office surface. Explicitly out of scope: physical
security of QAYD's own offices, social engineering of staff (handled through a separate security-
awareness process), and production customer data — every engagement runs exclusively against a
seeded staging tenant populated with synthetic companies, never a live customer's ledger.

**Methodology.** Testing is scoped against OWASP ASVS Level 2 as the baseline control checklist
platform-wide, stepping up to Level 3 specifically for authentication, session management, and any
payment-adjacent control, given the financial-data context. The OWASP API Security Top 10 table in
this document is used directly as the API-specific checklist. Findings are scored with CVSS 3.1 to
establish a consistent, comparable severity across engagements and across the different vendors QAYD
may engage over time.

**Bug bounty program.** QAYD's program begins as a **private, invite-only** program with a small
set of vetted researchers, before any public program is opened — this lets QAYD calibrate its triage
process and remediation throughput against a manageable volume of reports first. Scope is explicit:
`api.qayd.com`, the web application, and the mobile application. Safe-harbor language explicitly
authorizes good-faith testing against the **staging** environment only, explicitly forbids any
testing that would touch another tenant's real production data, and commits QAYD to not pursue
legal action against research conducted within the stated scope and rules.

**Severity-to-payout table:**

| Severity | Example vulnerability class | Indicative payout tier |
|---|---|---|
| Critical | Cross-tenant financial data access; ability to move funds or release payroll without the required approval step | Highest tier |
| High | Authentication bypass; token forgery; privilege escalation to an unauthorized role | High tier |
| Medium | Rate-limit or CORS bypass; information disclosure not directly exposing Restricted data | Medium tier |
| Low | Missing security header; verbose error message; minor misconfiguration with no direct exploitability shown | Low tier |

**Responsible disclosure.** QAYD publishes a `security.txt` file (per RFC 9116) at the API's
well-known location, naming a security contact address and a PGP key for encrypted reports, a
target acknowledgement time of two business days, and a target time to first substantive response
of five business days.

**Remediation SLA (table):**

| Severity | Time to remediation |
|---|---|
| Critical | 24–72 hours |
| High | 7 days |
| Medium | 30 days |
| Low | Next scheduled release, target 90 days |

Every finding is retested before closure, regardless of severity. A finding that recurs after a
prior fix triggers a root-cause review rather than a second point patch — a recurrence usually means
the original fix addressed a symptom (one vulnerable endpoint) rather than the underlying pattern
(a missing check in a shared middleware or base class), and the review exists to close the pattern,
not just the instance.

**A living control set.** Findings from both penetration tests and the bug bounty program feed
directly back into this document and into the OWASP Top 10 mitigations table — this specification
is versioned and updated as the threat model matures, not written once and left static while the
platform grows around it.

# Incident Response

**Phases.** QAYD's incident response follows the standard six phases, mapped to concrete actions at
the API layer:

1. **Preparation** — this document, the runbooks below, the on-call rotation, and the pre-provisioned
   ability to revoke any token, key, or secret within minutes are all in place *before* an incident,
   not assembled during one.
2. **Identification** — an alert from `# Audit Logging Of API Access`'s detections, a penetration-test
   or bug-bounty report, or an external notification (a customer, a researcher, a partner bank)
   confirms a security-relevant event is underway or has occurred.
3. **Containment** — the fastest available control is applied first: revoke the specific compromised
   token/key (seconds, via the Redis denylist), suspend a compromised integration's API key, or, in
   the most severe case, disable a specific endpoint or the AI engine's write-adjacent abilities
   entirely while the investigation continues, without necessarily taking the whole API down.
4. **Eradication** — the root cause (a vulnerable code path, an over-broad permission grant, a leaked
   secret) is fixed, not merely the symptom that was contained.
5. **Recovery** — access is restored in the reverse order it was contained, with heightened monitoring
   on the affected surface for an extended period after restoration.
6. **Lessons learned** — a blameless post-mortem, described below.

**Roles.** An **Incident Commander** drives the response and holds the authority to make
containment calls (including revoking keys or disabling an endpoint) without waiting for a full
consensus. A **Security Lead** owns the technical investigation. A **Communications Lead** owns
customer and, where applicable, regulator notifications, so technical responders are not also
drafting customer-facing messages under time pressure. A **Scribe** maintains the incident timeline
in real time, which becomes the backbone of the post-mortem.

**Severity classification (table):**

| Severity | Criteria | Example |
|---|---|---|
| SEV1 | Confirmed cross-tenant financial data exposure, or a confirmed ability to move funds or release payroll without proper authorization | An exploited BOLA vulnerability returning another company's ledger |
| SEV2 | Confirmed authentication bypass or PII exposure, without evidence it was actually exploited against real data yet | A JWT validation bug discovered internally before any abuse is observed |
| SEV3 | A control gap identified before exploitation, via testing or review | A pentest finding with no evidence of prior abuse |
| SEV4 | A hardening opportunity with no realistic exploitability | A missing security header with no demonstrated impact |

**Breach notification.** Affected customers are notified without undue delay once impact is
confirmed. Where personal data of individuals in a jurisdiction with a GDPR-style notification rule
is affected, the 72-hour regulatory notification clock is treated as authoritative regardless of
where QAYD itself is headquartered. A customer notification always states, at minimum: what
happened, what data was involved, what QAYD has already done in response, and what action (if any)
the customer should take.

**Named runbooks.**

- **Leaked JWT signing private key.** Immediately begin emergency key rotation (outside the normal
  90-day schedule): generate a new RS256 keypair, publish the new public key, and — because a leaked
  *signing* key means an attacker could mint arbitrary valid tokens — treat every currently-issued
  token as untrustworthy and force a full re-authentication of every active session rather than
  relying on the normal overlap-window rotation process.
- **Compromised AI-engine service credential.** Revoke the AI engine's service token and its request-
  signing secret immediately; because the credential's ability scope is narrow by design
  (`# Authentication & Token Security`), the blast radius is bounded to read access and draft
  creation — confirm no approval-gated action was taken using the compromised credential (approvals
  require a separate human-held permission the AI credential never has), then reissue a new
  credential and secret before restoring the AI engine's access.
- **Mass token-replay or credential-stuffing wave.** Engage edge-layer IP throttling and CAPTCHA
  enforcement immediately; force-expire the Redis-backed denylist window for any account showing the
  impossible-travel or credential-stuffing heuristic pattern (`# Rate Limiting & Abuse Prevention`),
  requiring fresh authentication; review whether the source lists overlap with a known public
  credential-breach corpus, which would indicate stuffing rather than a QAYD-specific leak.
- **Webhook signing-secret leak.** Rotate the affected company's (or, if scope is unclear, all
  companies') webhook signing secret immediately using the dual-secret grace window
  (`# Request Signing`) so legitimate in-flight deliveries are not broken by the emergency rotation;
  notify the affected company that any webhook payload delivered and *not* verified against the new
  secret going forward should be treated as unverifiable.
- **Compromised third-party integration API key.** Suspend the specific `qak_live_…` key immediately;
  because keys are scoped per integration with their own permission set, review the specific
  permissions that integration held to bound what could have been read or written, rather than
  assuming platform-wide exposure; issue a replacement key only after confirming the integration's
  own environment (not QAYD's) was the source of the leak, or after remediating the QAYD-side cause
  if it was not.

**Forensics.** The access-log window around a confirmed incident is preserved before any routine
log-rotation or retention job could prune it. Affected database rows are snapshotted, never modified
in place during an active investigation, so the state at time of discovery remains available for
later analysis regardless of what remediation subsequently changes. A chain-of-custody note
accompanies any evidence extracted for review.

**Post-incident.** A blameless post-mortem is produced within five business days of resolution,
listing corrective actions with named owners and target dates. QAYD rehearses at least two of the
named runbooks above per year as a tabletop exercise, deliberately run by engineers who were not the
original authors of the runbook being tested, so the document's clarity — not the original author's
memory of writing it — is what is actually being validated.

# Edge Cases

- **A token remains valid in its `cids` claim for a company the user was removed from mid-session.**
  The `cids` claim is a convenience/fast-path check only; the authoritative check is the live
  `company_users` lookup in `ResolveActiveCompany` on every request, so removal takes effect on the
  very next request regardless of what the token's claims still say.
- **`X-Company-Id` is present but the user never belonged to that company.** Treated identically to a
  removed user — `403 TENANT_NOT_AUTHORIZED` — with no distinction in the client-visible error
  between "never had access" and "access was removed," so the response itself does not leak which
  case applies.
- **Concurrent refresh-token use from two devices racing the same rotation.** The first presentation
  rotates the token and succeeds; the second, now-stale presentation is treated as reuse-after-
  rotation and triggers revocation of the entire token family, forcing re-authentication on both
  devices — a deliberate fail-safe bias toward assuming compromise over assuming a benign race.
- **A revoked token is still accepted because the Redis denylist entry has not yet propagated.** The
  denylist write happens synchronously as part of the revocation request before that request returns
  success, and all application instances read from the same Redis cluster, so there is no
  eventually-consistent propagation delay to account for; a revocation is effective for the very next
  request system-wide.
- **A webhook retry arrives after the receiver already processed the original delivery, without an
  `Idempotency-Key` involved.** Webhook payloads carry their own stable `id`; QAYD's documentation and
  SDKs instruct receivers to deduplicate on that `id` specifically because retries are expected
  behavior, not an edge case the receiver can opt out of encountering.
- **The AI engine proposes an action based on a confidence score computed against data that changed
  before a human approved it.** Every approval-gated action re-validates its preconditions (account
  balances, document existence, current status) at approval time, not merely at proposal time; if the
  underlying state has materially changed, the approval step fails with a specific error requiring the
  AI to regenerate the proposal against current data rather than allowing a stale proposal to execute
  against new reality.
- **Clock skew between Laravel, the FastAPI AI engine, and Redis affects token expiry or signature
  timestamp checks.** All three run against NTP-synchronized clocks with a small explicit tolerance
  (a few seconds) built into expiry and signature-timestamp comparisons specifically to absorb minor
  skew without either accepting a meaningfully expired token or rejecting a valid one over a
  fractional-second difference.
- **A JWT is presented with `alg: none` or with an algorithm the verifier did not expect.** The
  verification library is configured with an explicit, hard-coded allow-list containing only `RS256`;
  any token whose header specifies a different algorithm — including `none` — is rejected before
  signature verification is even attempted, closing the classic "attacker sets alg to none and strips
  the signature" and "attacker resigns with the public key as an HMAC secret" (algorithm-confusion)
  attacks.
- **A rate limit is bypassed by rotating source IPs.** Authenticated-tier limits key on the user and
  company identity from the validated token, not on IP address, specifically so IP rotation does not
  reset an authenticated caller's budget; only the pre-authentication tier (login attempts) is
  IP-keyed, and it is additionally keyed on the target account to prevent a distributed-IP attack from
  bypassing the per-account protection on any single targeted account.
- **A CORS misconfiguration in a lower environment allows a credentialed cross-origin read.** Because
  `supports_credentials` is `false` platform-wide (`# CORS Policy`) and origins are hard-enumerated
  per environment file (not computed or wildcarded), there is no configuration path in which a
  non-production origin becomes valid in the production config short of directly editing the
  production file — a class of error a config-diff step in the deploy pipeline is specifically
  designed to catch.
- **A mobile device is rooted or jailbroken and its local token storage is extracted.** The stolen
  access token is valid for at most 15 minutes; the stolen refresh token is subject to reuse
  detection the moment the legitimate app also tries to use it, at which point the whole token family
  is revoked. Device-bound tokens (the `dvc` claim) additionally fail validation if presented from a
  meaningfully different device fingerprint than the one recorded at issuance, narrowing (though not
  eliminating) the window of usefulness of a purely exfiltrated token.
- **An offline-first Flutter cache persists a token to shared, unencrypted device storage by
  mistake.** Tokens are stored exclusively through the platform secure-storage APIs (Keychain on iOS,
  Keystore-backed encrypted storage on Android) via a single wrapped storage utility; no other part of
  the mobile codebase is permitted to write a token to `SharedPreferences`, a plain file, or any other
  unencrypted store, enforced by code review and a static-analysis rule scanning for direct token
  writes outside the utility.
- **A third-party integration holding a read-only scoped key attempts to call a write endpoint.** The
  key's ability scope is checked at the same authorization step as a human user's role — a `read`-only
  scope produces an ordinary `403`, identical in shape to an under-permissioned human user's response,
  with no special-cased leniency for integrations.
- **A soft-deleted record is still returned via a cached cursor-paginated listing.** Cache entries for
  paginated listings are keyed to include a company-scoped version/watermark that increments on any
  create, update, delete, or restore affecting that resource type, so a soft-delete invalidates the
  relevant cached pages rather than allowing a stale page to keep surfacing a now-deleted row.
- **A browser back button replays a cached page that resubmits a completed financial POST.** Write
  endpoints prone to this are exactly the ones documented as requiring `Idempotency-Key`
  (`# Request Signing`); a resubmission with the same key returns the original, already-completed
  response instead of executing a second time, regardless of how the resubmission was triggered.
- **A partial network failure between Laravel and the FastAPI AI engine leaves an AI-proposed action
  in an ambiguous state.** The AI engine never has direct database write access (fixed platform rule),
  so a failure mid-call can leave, at worst, a `pending_approval` draft with no corresponding
  completed action — there is no partial financial state to reconcile, only a draft to accept, reject,
  or regenerate, because sensitive execution never happens on the AI's side of the boundary in the
  first place.
- **A large or compressed payload is used to exhaust server resources ("decompression bomb").** The
  edge and application layers both enforce a maximum decompressed body size, and decompression itself
  is bounded (aborted if the decompressed size exceeds the configured ceiling partway through), so a
  small compressed payload cannot be used to force an unbounded expansion in application memory.
- **Unicode normalization differences allow a permission-key string comparison to be bypassed.**
  Permission keys are drawn from a fixed, code-defined enumeration compared by exact byte equality
  against that enumeration — never constructed from or normalized against arbitrary user input — so
  there is no user-influenced string that participates in a permission-key comparison in the first
  place.
- **A timing attack attempts to recover a secret byte-by-byte from response-time variance.** Every
  secret-bearing comparison in the authentication and signing paths uses `hash_equals` (constant-time
  comparison), as stated in `# Authentication & Token Security` and `# Request Signing`, specifically
  to remove response-time variance as an exploitable side channel.
- **A webhook signing-secret rotation lands while deliveries signed under the old secret are still in
  flight (retry backlog).** The dual-secret grace-period mechanism (`# Secrets Management`,
  `# Request Signing`) verifies against both the outgoing and incoming secret for a bounded overlap
  window specifically to cover this case; a delivery is only rejected for an invalid signature once
  the overlap window has fully elapsed.
- **A user who belongs to multiple companies has a stale, cached notion of "current company" in a
  long-lived client session.** The server never infers the active company from anything other than
  the `X-Company-Id` header on the current request; a client-side cache of "which company was
  selected" is purely a UI convenience on the client and carries no authority — if the client sends a
  header for a company the token no longer authorizes, the request is rejected exactly as it would be
  for any other unauthorized `X-Company-Id`, with no special-case trust extended to a "remembered"
  selection.
- **Repeated, individually-small paginated reads are used to scrape an entire dataset an export
  permission would otherwise gate.** The abuse-heuristic layer (`# Rate Limiting & Abuse Prevention`)
  evaluates cumulative read volume per account against its own baseline, not merely each request's
  compliance with its per-minute ceiling, so a slow, patient page-by-page scrape is still visible as
  an anomaly distinct from ordinary usage, even though no single request in the sequence would trip a
  simple rate limit on its own.

# End of Document
