# API Security in Depth — QAYD Security
Version: 1.0
Status: Design Specification
Module: Security
Submodule: API_SECURITY
---

# Purpose

The Laravel API is the single entry point to QAYD's data plane. No client — not the Next.js web tier,
not the Flutter app, not the FastAPI AI engine, not a partner integration — ever opens a direct
connection to PostgreSQL, Redis, or object storage. Every read and every write of company data flows
through one pipeline: authenticate → resolve tenant → authorize → validate → execute (scoped) → audit.
Because that pipeline is the *only* door, the security of the whole platform is, to a first
approximation, the security of this API surface. This document specifies how each request is defended
across that surface, from the transport layer down to the exact places where a financial platform's
API most often fails: cross-tenant object access, mass assignment, envelope information leakage,
webhook forgery, idempotency abuse, and low-and-slow business-flow abuse that raw rate limits miss.

This document sits alongside, and deliberately goes **beyond**,
[../api/API_SECURITY.md](../api/API_SECURITY.md) — the API-layer document that owns the ground truth of
transport config, the OWASP API Top 10 mapping, the authentication/token model, the CORS policy, the
rate-limit tiers, and the request-signing scheme. That document is binding by reference for the
mechanics it owns; this document is the *security folder's* view of the API boundary: it states the
security **invariants** the API must uphold, ties them back to the trust boundaries and defense-in-depth
layers in [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md), and specifies the depth on the handful
of controls that are load-bearing enough to warrant restating with the security rationale spelled out.
Where the two documents describe the same control, the API-layer document owns the *how* and this
document owns the *why it must never fail and how we prove it doesn't*.

The audience is the backend engineer adding a route, the reviewer checking that a new endpoint upholds
the tenant and permission invariants, and the security engineer who must be able to point at any route
and name the six pipeline stages it passes through.

---

# Threat Model / Principles

## What the API surface defends against

The API is where the untrusted and semi-trusted clients of boundary B2
([SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md) § Trust Boundaries) meet the trusted core. Its
adversaries are every client that can form an HTTP request.

| # | Threat | Primary control (this doc's section) |
|---|--------|--------------------------------------|
| E1 | A request the UI would never send (crafted directly against the API) | Server-authoritative auth/authz on *every* route; the UI is never a control |
| E2 | Cross-tenant object access by guessing/incrementing an id (IDOR/BOLA) | Tenant + ownership binding; cross-tenant → 404 |
| E3 | Mass assignment — smuggling an unlisted field to escalate or mis-scope | Whitelist FormRequest + `$guarded` ban + schema `additionalProperties:false` |
| E4 | Injection (SQL, command, template) via any input | Parameterized queries only; validated, encoded output |
| E5 | Volumetric and low-and-slow abuse (fund-draining, export exfiltration) | Redis sliding-window tiers + business-flow state machines + heuristics |
| E6 | Forged inbound webhook / partner call trusted as genuine | HMAC signature verification before any payload is trusted |
| E7 | Duplicate/replayed financial write (double-submit, retry storm) | `Idempotency-Key` with body-hash binding |
| E8 | Internal detail leaking through error envelopes | Envelope non-leakage: stable codes, no stack traces, no internals |
| E9 | Cookie-session request forged from another origin (CSRF) | Double-submit XSRF token on the stateful cookie flow + CORS |

## Principles

- **Server-authoritative, always.** Every security decision is made fresh on the backend, on every
  request. A hidden button in the UI or a disabled field in the app is a convenience, never a control;
  a request that the UI would never have sent is handled exactly like one it would have.
- **Every route runs the full pipeline; no caller is exempt.** The AI engine and partner integrations
  traverse the identical authenticate → resolve-tenant → authorize → validate → execute → audit path a
  human does. There is no privileged internal bypass.
- **Deny by default, at every stage.** Absent authentication → 401. Absent tenant membership → 403.
  Absent an explicit permission grant → 403. Unknown input field → 422. Nothing is implicitly allowed.
- **Cross-tenant is a 404, never a 403.** A 403 confirms a record exists somewhere; a 404 reveals
  nothing. Every cross-tenant probe is *also* a logged security signal even though the caller sees an
  ordinary 404.
- **Validate input as a whitelist; encode output for its sink.** Fields not explicitly declared are
  rejected, not ignored. Data is encoded for the context it lands in.
- **The envelope leaks nothing.** Success and failure share one shape; errors carry stable machine
  codes and safe messages — never a stack trace, a SQL fragment, an internal hostname, or whether a
  record exists in another tenant.
- **Trust no inbound payload because of where it came from.** A webhook or partner call is validated
  and signature-checked exactly like any client request; a private-network origin is never an
  authorization.

---

# Controls: Authentication & Authorization on Every Route

**Authentication (L2).** Every non-public route is authenticated before anything else runs: Sanctum
session cookie for the web tier (with the stateful-guard + CSRF flow below), bearer token for
mobile/partner, and the `ai_agent`-scoped service token + mTLS for the AI engine. Token issuance,
expiry, revocation denylist, MFA on financial-approval roles, and brute-force lockout are owned by
[../api/API_SECURITY.md](../api/API_SECURITY.md) § Authentication & Token Security and
[AUTHENTICATION.md](AUTHENTICATION.md); the invariant this document fixes is that **no route reaches a
controller unauthenticated** except the explicit public allow-list (login, health, docs), and that list
is enumerated in the OpenAPI spec so an accidentally-public route is a CI failure.

**Tenant resolution (L3).** Every authenticated request other than account-level endpoints must carry
`X-Company-Id`. The `ResolveActiveCompany` middleware fails closed on three ordered checks — header is a
valid integer, the id is in the token's company claim, and a *live* `company_users` query confirms the
(user, company) membership still exists and the company is not suspended. The third check is the one
that catches access revoked *after* the token was issued. The resolved `company_id` is bound into a
`TenantContext` singleton and **never re-read from client input again** — nothing downstream can be
tricked by a second, conflicting company id in the body. Details in
[../api/API_SECURITY.md](../api/API_SECURITY.md) § Authorization & Tenant Isolation and
[TENANT_ISOLATION.md](TENANT_ISOLATION.md).

**Authorization (L4).** Permissions are dotted `<area>.<action>` strings evaluated against the
`(user, active_company)` pair, never the user in isolation — a person can be Accountant in one company
and Read Only in another. Every route carries `can:<permission>` middleware; every model write
additionally passes a Policy (`$this->authorize(...)`). Default-deny is absolute: a new permission is
unreachable for *every* role, Owner included, until a migration explicitly seeds the grant.

**Owner is not a bypass, and initiation is not approval.** Even Owner does not skip the approval chain
on a sensitive operation. A permission like `bank.transfer` grants the right to *initiate*; the distinct
`bank.transfer.approve` must be held by a different confirming principal to release. This two-key
structure ([AUTHORIZATION.md](AUTHORIZATION.md)) is what makes E5's fund-drain uneconomical: no single
credential — human or AI — drives a sensitive flow to completion alone.

```php
// A sensitive write is a state machine, not a synchronous terminal action.
Route::post('banking/transfers', [TransferController::class, 'store'])
    ->middleware('can:bank.transfer');                      // initiate → status: pending_approval

Route::post('banking/transfers/{transfer}/approve', [TransferController::class, 'approve'])
    ->middleware('can:bank.transfer.approve');              // a DIFFERENT principal releases funds
```

---

# Controls: Input Validation, Output Encoding & Injection

**Whitelist validation (L5).** Every write endpoint is backed by a Laravel `FormRequest` whose
`rules()` is a whitelist: undeclared fields are rejected, not silently dropped. A
`ValidatesAgainstOpenApiSchema` middleware runs *ahead* of the FormRequest and diffs the raw body's
keys against the schema for that `operationId` (`additionalProperties: false`, enforced in code, not
just documented) — an unknown key is a `422` with a field-level `errors[]` entry before the request
touches business logic.

**Mass-assignment protection (E3).** Whitelist validation is only half the defense; the other half is
never letting a validated-but-unexpected attribute reach a model. `$guarded = []` is **banned
platform-wide** by a PHPStan rule — models declare `$fillable` explicitly, and Services assign only the
named, validated fields, never `Model::create($request->all())`. Security-relevant columns
(`company_id`, `id`, `status`, `approved_by`, `role_id`, price/amount overrides) are never fillable from
a request; they are set server-side. This closes the classic "add `"company_id": <other>` or
`"role_id": <admin>` to the JSON body" escalation.

```php
final class StoreInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [                                     // whitelist: anything not here is a 422
            'customer_id'  => ['required', 'integer', 'exists:customers,id'],
            'currency_code'=> ['required', 'string', 'size:3'],
            'lines'        => ['required', 'array', 'min:1'],
            'lines.*.amount' => ['required', 'decimal:0,4', 'min:0'],
            // NOTE: company_id, status, approved_by are NOT accepted from input — set server-side.
        ];
    }
}
```

**Injection prevention (E4).** All database access is through Eloquent/the query builder with bound
parameters; interpolated `DB::raw`/`whereRaw` on request input is banned by static analysis. No user
input is ever passed to a shell; server-initiated fetches go through the egress control below. Template
injection is prevented by never rendering user input through a server-side template engine with
interpolation — Blade `{!! !!}` raw output is banned outside one audited, escaping utility. Output is
encoded for its sink: JSON responses are properly serialized (the web/mobile clients render, never
`eval`), and any HTML-bound value is escaped.

**SSRF on server-initiated fetches (E4/E1).** QAYD genuinely fetches remote resources — bank feeds, an
OCR agent pulling a receipt from a pasted URL, outbound webhooks. Every such fetch goes through one
egress path that resolves DNS once and re-validates the resolved IP is not private/link-local/loopback
*immediately before connecting* (defeating DNS rebinding), refuses cross-host redirects, allows only
`https`, and runs through an egress proxy with **no route to QAYD's own internal services** — so even a
successful SSRF reaches only the public internet, never the database or the AI engine's admin surface.
Owned in full by [../api/API_SECURITY.md](../api/API_SECURITY.md) § OWASP API7.

---

# Controls: IDOR, Tenant + Ownership Binding

E2 is the single most important thing this API must get right, because it is the cross-tenant boundary
the whole multi-tenant business rests on. The defense is layered so that a bug in any one layer is not a
breach ([SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md) § Defense-in-Depth: L3, L4, L6, L7 are four
independent enforcements of the same boundary).

**Tenant-scoped route-model binding.** A path parameter like `{invoice}` resolves only within the
`CompanyScope`-filtered set. `Invoice::find($id)` cannot return another company's row even if the id is
guessed correctly, because the scope appends `WHERE company_id = ?` *before* the query executes, not as
a check after. If the id exists but belongs to another company, the query returns nothing and QAYD
responds **404, not 403** — a 403 would confirm the id refers to a real record somewhere; a 404 reveals
nothing about existence outside the caller's tenant.

**Ownership beyond tenancy.** Tenant scoping is necessary but not sufficient. Within a company, a Policy
still enforces that the specific actor may act on the specific object — a Warehouse Employee who can
list `sales_orders` still gets a `403` on a payroll action, and object-property authorization gates
individual fields (`net_pay` visible only with `payroll.view_amounts`) even inside an otherwise-readable
resource.

**The cross-tenant 404 is a logged security signal.** Every 404 produced *specifically because* the
record belongs to a different company (as opposed to genuinely not existing) is tagged and forwarded to
the access-log alerting pipeline. The caller sees an ordinary 404; QAYD's monitoring sees a
tenant-boundary probe ([AUDIT_LOGS.md](AUDIT_LOGS.md) § anomaly alerting).

**The database backstop.** Even if every app-layer scope above were somehow bypassed, PostgreSQL RLS on
the connection's per-request GUC returns zero rows for a mis-scoped query — the last line, designed to
fail to *empty*, never to *all* ([../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md),
[TENANT_ISOLATION.md](TENANT_ISOLATION.md)).

---

# Implementation

**Rate limiting & abuse prevention (E5).** All counters live in Redis, never in a single instance's
memory, so a client cannot evade a limit by spreading requests across the stateless Laravel fleet.
General endpoints use a **sliding-window counter** (resistant to fixed-window boundary bursts);
bulk-tolerant endpoints (CSV import) use a token bucket. The tiers are owned by
[../api/API_SECURITY.md](../api/API_SECURITY.md) § Rate Limiting; the security-load-bearing rows:

| Scope | Class | Limit |
|---|---|---|
| Per IP + per account | `POST /auth/login` | 5 / min (with lockout backoff + CAPTCHA after 3) |
| Per company | `POST /banking/transfers` | 10 / hour |
| Per company | AI-engine-originated (`ai_agent` token) | 60 / min |
| Per user + company | General authenticated traffic | 300 / min |
| Per integration key | Partner traffic | Configurable, default 600 / min |

Rate limiting alone does not stop a patient attacker, so QAYD adds **business-flow controls** (E5 is
OWASP API6): every sensitive flow is a two-actor state machine, so fund-draining in small "normal-looking"
amounts still cannot complete without a second approver; and **abuse heuristics** flag impossible-travel
(→ step-up MFA), credential-stuffing patterns (→ edge throttle), and a `reports.export` spike relative to
an account's own baseline (→ exfiltration review), none of which a raw per-minute ceiling would catch.

**CORS + CSRF (E9).** The web tier authenticates with a Sanctum session cookie, which makes CSRF a real
threat, so the stateful cookie flow requires a **double-submit XSRF token**: the value is issued in a
cookie readable by the SPA and must be echoed in an `X-XSRF-TOKEN` header, which a cross-origin attacker
cannot read (same-origin policy) and therefore cannot forge. CORS is a strict allow-list of QAYD's own
web origins — **never `*` with credentials**, a combination rejected by a config lint in CI. Bearer-token
clients (mobile, partner, AI) are not cookie-based and so are not CSRF-exposed; they carry no ambient
credential a browser would attach automatically.

```php
// A stateful cookie request must echo the XSRF token; a cross-origin page cannot read it to forge it.
Route::middleware(['web', 'auth:sanctum', VerifyCsrfToken::class])->group(function () {
    // CORS: allow-list of QAYD web origins only; supports_credentials => true is INCOMPATIBLE with '*'.
});
```

**Webhook & partner-API security (E6).** Every webhook QAYD fires is signed HMAC-SHA256 in an
`X-Qayd-Signature: t=<ts>,v1=<hex>` header over the literal `"{t}.{raw_body}"` with a per-company (or
per-endpoint) secret; receivers recompute and compare in constant time and reject a timestamp more than
five minutes skewed (bounding replay). **Inbound** partner and bank-aggregator payloads are treated as
untrusted (OWASP API10): the same validation as a client request, plus signature verification *before*
any field is trusted. The AI engine's calls to Laravel additionally carry an `X-Qayd-Agent-Signature`
so a stolen bearer token alone cannot forge a body the engine did not produce — defense in depth atop
the token. Full scheme in [../api/API_SECURITY.md](../api/API_SECURITY.md) § Request Signing.

**Idempotency (E7).** Double-submission-prone financial writes accept an `Idempotency-Key` header. QAYD
stores `(idempotency_key, request_body_hash) → response` for 24h: a repeat with the *same* key and body
replays the stored response without re-executing the mutation, while a repeat with the same key and a
*different* body is a `409` (`IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`). Binding the key to the
body hash is the security-critical part — it stops a client (or a MITM) from smuggling a different
transaction through under cover of an expected-idempotent retry.

**Envelope non-leakage (E8).** Success and failure share the platform envelope
(`{success, data, message, errors, meta, request_id, timestamp}`). Errors carry a **stable machine
code** (`TENANT_NOT_AUTHORIZED`, `RATE_LIMITED`, `VALIDATION_FAILED`) and a safe human message — never a
stack trace, a SQL fragment, an internal hostname, a framework version, or a hint about whether a record
exists in another tenant. `APP_DEBUG=false` is enforced in production by a deploy-time check, so a
verbose exception page can never ship. The `request_id` is the only correlation handle exposed, and it
maps to the full internal detail in the audit/log store, not to the client.

```json
{ "success": false, "data": null, "message": "You do not have access to this company.",
  "errors": [{ "code": "TENANT_NOT_AUTHORIZED" }], "meta": null,
  "request_id": "b6e1e6b0-9e3a-4b7a-9d3f-8e6a2c1d4f90", "timestamp": "2026-07-17T07:12:04Z" }
```

---

# Controls: Abuse Detection & Security Headers

**Abuse detection beyond rate limits.** A per-minute ceiling stops raw volume; it does not stop a
patient, business-aware attacker whose every individual call is within limits. QAYD layers
behavioral detection over the audit and access-log stream ([AUDIT_LOGS.md](AUDIT_LOGS.md) § Anomaly
Alerting), so the two work together — the limiter throttles the loud, the heuristics catch the quiet:

| Pattern | Signal | Response |
|---|---|---|
| Fund-draining via many small transfers | `banking/transfers` volume + amount distribution vs the company's baseline | The two-key state machine already blocks completion on one credential; the pattern is additionally flagged |
| Export exfiltration | `reports.export` rate far above an account's own baseline | Manual data-exfiltration review, not a silent allow |
| Credential stuffing | Many target accounts, one IP/UA fingerprint, high failure ratio | Edge WAF IP throttle |
| Impossible travel | Same account, geographically implausible locations in an implausible window | Step-up MFA on the next sensitive action |
| Enumeration | A run of cross-tenant `404`s from one actor | Boundary-probe alert; sustained → block |
| Schema fuzzing | A burst of `422`s with rotating unknown fields from one client | Flagged; repeated → throttle |

The principle: abuse detection is *business-logic-aware*, keyed on each actor's own baseline, and fed by
the same audit stream that already records every consequential event — so detection is a read over data
QAYD is keeping anyway, not a separate, spoofable telemetry channel.

**Security response headers.** Every response carries the hardening headers, so a compromised or
malicious page cannot leverage a QAYD response against a user:

| Header | Value (intent) | Defends |
|---|---|---|
| `Strict-Transport-Security` | `max-age=63072000; includeSubDomains; preload` | Transport downgrade after first contact |
| `Content-Security-Policy` | Strict allow-list; no inline-script `unsafe-inline` on app origins | XSS, data injection into the SPA |
| `X-Content-Type-Options` | `nosniff` | MIME-sniffing a response into executable content |
| `X-Frame-Options` / `frame-ancestors` | `DENY` / self | Clickjacking |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Leaking URLs (never PII-bearing) to third parties |
| `Cache-Control` on sensitive responses | `private, no-store` | A shared cache retaining a financial/PII payload |

The TLS floor, cipher allow-list, and HSTS preload themselves are owned by
[../api/API_SECURITY.md](../api/API_SECURITY.md) § Transport Security and [ENCRYPTION.md](ENCRYPTION.md)
§ Encryption In Transit; this section fixes only that every response *carries* the hardening set and
that a sensitive response is never cacheable by a shared intermediary.

# Enforcement Points

| Enforcement point | Mechanism | Threat |
|---|---|---|
| Public-route allow-list vs OpenAPI | CI diff of live routes against the spec | E1 (accidental public route) |
| `Authenticate` / `VerifyServiceToken` middleware | Session/bearer/service-token validation | E1 |
| `ResolveActiveCompany` middleware | 3-check tenant resolution → `TenantContext` | E2 |
| `can:<permission>` route middleware + Policies | Deny-by-default RBAC, two-key on sensitive | E1, E5 |
| Tenant-scoped route-model binding + `CompanyScope` | Cross-tenant id → 404 (+ signal) | E2 |
| `ValidatesAgainstOpenApiSchema` + FormRequest | `additionalProperties:false`, whitelist | E3 |
| PHPStan `$guarded=[]` / `whereRaw` / raw-Blade bans | Static analysis, blocks merge | E3, E4 |
| Egress proxy + DNS-rebind revalidation | Server-fetch isolation | E1, E4 (SSRF) |
| Redis sliding-window limiter + heuristics | Volumetric + low-and-slow abuse | E5 |
| Double-submit XSRF + CORS allow-list lint | Cookie-flow CSRF, no `*`+credentials | E9 |
| HMAC signature verify (in/out) + agent signature | Webhook/partner forgery | E6 |
| `Idempotency-Key` + body-hash binding | Replay/double-write | E7 |
| Stable error codes + `APP_DEBUG=false` deploy check | Envelope non-leakage | E8 |
| Cross-tenant-404 tagging → SIEM | Probe detection | E2 |

The canonical, non-skippable, non-reorderable ordering — the same spine as
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md):

```
authenticate → resolve tenant → authorize → validate → execute (scoped) → audit
```

---

# Data Handling

- **In responses:** Restricted fields ([DATA_PRIVACY.md](DATA_PRIVACY.md)) are masked by default and
  returned in full only to a caller holding the specific reveal permission, on a specific record, with
  the reveal audited. Pagination is capped (`per_page` ceiling) so a single request cannot pull an
  unbounded PII collection (OWASP API4).
- **In logs:** the Restricted-pattern redaction pipeline scrubs PII before emit; the `request_id` is
  logged, the payload is not, beyond its safe, redacted form ([../api/API_LOGGING.md](../api/API_LOGGING.md)).
- **In URLs:** never a PII value or a secret in a path or query string; object keys are opaque UUIDs.
- **To the AI engine:** the API scopes every payload to the calling user's permission envelope *before*
  the AI receives it, and PII is pre-redacted — the return path across boundary B3 never hands the AI
  data the user could not see ([AI_SECURITY.md](AI_SECURITY.md)).
- **Secrets:** never in the frontend bundle, never in a response, never in a log; resolved from the
  vault at runtime ([SECRETS.md](SECRETS.md)).

---

# Failure Modes

The API fails closed at every stage — an indeterminate allow decision is a denial.

| Stage | Failure | Behavior |
|---|---|---|
| Authentication | Expired/revoked/malformed credential | `401`; nothing downstream runs |
| Tenant resolution | `X-Company-Id` missing/invalid/not a live membership | `403 TENANT_NOT_AUTHORIZED`; halts (RLS still backstops) |
| Authorization | No explicit grant | `403`; deny by default |
| Validation | Unknown/oversized/malformed field | `422`; nothing executes |
| Object binding | Cross-tenant id | `404` (+ security signal), never `403` |
| Rate limit | Tier exceeded | `429` + `Retry-After`; sensitive flows also require the second approver regardless |
| Idempotency | Same key, different body | `409`; no second mutation |
| Signature | Webhook/partner HMAC mismatch or stale timestamp | Rejected before the payload is trusted |
| Error rendering | Unhandled exception | Generic safe envelope + stable code; **never** a stack trace or internal detail |
| Audit | Sensitive write cannot be recorded | Transaction rolls back ([AUDIT_LOGS.md](AUDIT_LOGS.md)) |

The load-bearing failure modes: **a cross-tenant access fails to a 404 that reveals nothing**, and **a
sensitive write that cannot be audited does not happen**. Both are deliberate designs, not incidental
behaviors.

---

# Compliance

| Framework | API-surface expectation | Where the control lives |
|---|---|---|
| OWASP API Security Top 10 (2023) | The industry taxonomy for exactly this class of system | [../api/API_SECURITY.md](../api/API_SECURITY.md) § OWASP mapping; invariants here |
| SOC 2 (Security) | Access control, change management, monitored access | Pipeline + audit + envelope-safe errors |
| ISO 27001 | Secure development, input validation, logging | Whitelist validation, injection bans, [AUDIT_LOGS.md](AUDIT_LOGS.md) |
| GDPR / GCC data-protection | PII minimization in responses/logs, no leakage | Masking, redaction, no-PII-in-URL ([DATA_PRIVACY.md](DATA_PRIVACY.md)) |
| PCI-adjacent | No card data at rest; strong transport | Tokenization pre-ingress; TLS floor ([ENCRYPTION.md](ENCRYPTION.md)) |

QAYD does not claim certification here; it claims the API controls a certification would audit are
specified, enforced server-side on every route, and testable — with the OWASP API Top 10 as the
organizing spine and the six-stage pipeline as the guarantee that no route is a special case.

---

# Testing & Verification

**Static gates (CI, block merge):**

- OpenAPI diff: any live route not in the committed spec fails CI (closes API9); the public-route
  allow-list is part of the spec.
- PHPStan bans: `$guarded = []`, interpolated `whereRaw`/`DB::raw` on input, raw Blade outside the
  audited utility.
- Config lint: `APP_DEBUG=false` in production; CORS `*`-with-credentials rejected.

**Behavioral tests (must be green):**

- **Cross-tenant negative tests** for every tenant-scoped resource: authenticate as company A, attempt
  read/update/delete of a company B object by id, assert `404` — the single most important suite in the
  codebase ([TENANT_ISOLATION.md](TENANT_ISOLATION.md)).
- **Authorization negative tests:** a role lacking the permission gets `403`; the initiator of a
  two-key flow cannot also approve it.
- **Mass-assignment:** a request smuggling `company_id`/`role_id`/`approved_by` in the body has those
  fields ignored (set server-side), and the write lands in the caller's own tenant with default status.
- **Injection:** a payload with SQL/template metacharacters is parameterized/escaped and neither
  executes nor errors with an internal detail.
- **Idempotency:** same key + body replays; same key + different body → `409`.
- **Webhook signature:** a payload with a wrong/stale signature is rejected; a correct one is accepted;
  constant-time comparison verified.
- **Envelope non-leakage:** a forced 500 returns the generic safe envelope with a stable code and no
  stack trace; a cross-tenant access returns `404`, not `403`.
- **Rate limit + abuse:** the tiers engage; an export spike relative to baseline is flagged; a
  sensitive flow cannot complete on one credential regardless of pacing.

**Periodic verification:**

- External penetration testing before major releases, scoped to the seven trust boundaries, with
  cross-tenant isolation and the financial two-key control as required test cases.
- A recurring SSRF probe against the egress path (metadata endpoint, DNS rebinding, redirect-to-internal).

The acceptance bar: point at any route, name the six pipeline stages it passes through, name the control
for each of E1–E9 that applies to it, and show the automated test that proves that control still fires.

---

# End of Document
