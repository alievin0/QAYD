# Internal Service-to-Service API — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: INTERNAL_API
---

# Purpose

QAYD's public contract is deliberately singular: every client — the Next.js 15 web app, the Flutter
mobile app, the FastAPI AI engine, and third-party integrations — calls the exact same versioned
`/api/v1/...` REST surface, wrapped in the exact same response envelope, gated by the exact same
default-deny RBAC, as established in **API Architecture**'s founding principle: *"One API, many
surfaces. There is no 'internal' API with looser rules."* This document does not create one. What it
specifies instead is the machinery that sits **behind** that one contract — the private,
network-isolated wiring by which QAYD's own deployables (the Laravel 12 monolith, the FastAPI/Python AI
engine, the Next.js server-side rendering tier, and the Horizon-supervised queue-worker fleet) physically
reach one another to make that single public contract true end to end. Nothing described here is a
second, looser doorway into QAYD's data; every hop this document defines either (a) is itself a normal,
fully-authenticated call into the same `/api/v1/...` surface Authentication API and Authorization API &
Enforcement already govern, just made by a service principal instead of a human one, or (b) is a
genuinely private, non-customer-facing channel (the FastAPI engine's own `/internal/*` routes) that no
public client, partner integration, or unauthenticated request can ever reach — enforced not only in
application code but at the network layer, which is this document's specific, previously-unwritten
contribution to the platform's documentation set.

Four other documents already own adjacent ground, and this document treats their content as
authoritative rather than restating it: **API Architecture** defines the request pipeline, the API
surfaces table, and the Gateway's routing of `/api/v1/ai/*`; **Authentication API** defines Sanctum/JWT
issuance, refresh rotation, and the `api_keys` machine-credential table; **Authorization API &
Enforcement** defines the RBAC model, the permission-key grammar, and how the FastAPI AI engine is
constrained to "the exact same permission surface as the human user or service account it acts on behalf
of"; **API Webhooks** and **API Idempotency** define the external notification contract and the
retry-safety guarantees every write, including AI-originated writes, must honor; and **Database Events**
(Database module) defines the outbox (`domain_events`), the Redis Streams event bus, the queue topology,
and the `# AI Events` mechanics — including the exact two-line sketch of the internal HTTP call this
document expands into a full contract. This document's job is narrower and deeper: it specifies (1) the
complete request/response contract for the internal HTTP hops that Database Events names but does not
fully specify (`POST {ai_layer.url}/internal/events` and `POST /api/v1/ai/proposals`), (2) how the
service credentials those calls carry are minted, verified, and rotated, (3) mutual TLS as the
transport-layer trust boundary beneath those credentials, (4) the VPC/network topology that makes "the
AI engine has no database credentials" true at the infrastructure level and not merely by code-review
convention, and (5) the latency, resilience, and capacity engineering specific to those hops.

Out of scope, by design: the external/partner-facing API (owned by API Architecture, API Versioning, API
SDK Guidelines), per-module business rules and endpoints (owned by each module's own document, e.g.
Purchasing, Accounting, Payroll), the full outbox/inbox/DLQ mechanics of the event bus (owned by Database
Events), and multi-step human-to-human approval workflows unrelated to AI proposals (e.g. a purchase
order's ordinary manager sign-off), which belong to their owning modules. Everything below is
implementation-ready: network diagrams, header/credential tables, and literal request/response JSON using
the platform's standard conventions.

# Service-to-Service APIs (web BFF, mobile, FastAPI AI engine, queue workers)

## The five surfaces, seen from the inside

API Architecture's surfaces table describes each client from the *outside* — which token type it
presents and which headers it sets. This section describes the same five surfaces from the *inside*:
which of them is a genuinely separate deployable reachable only over the private network, which of them
is simply "Laravel talking to itself," and what transport each hop actually uses.

| Surface | Deployable | Network relationship to Laravel | Trust model |
|---|---|---|---|
| Web (Next.js 15) | Separate Node.js deployable (Vercel-style edge/server runtime) | Its server-side route handlers (Route Handlers / Server Actions) make outbound HTTPS calls to `https://api.qayd.app` over the public internet, identically in shape to a browser call, but originated server-side to keep the user's bearer token out of client-side JS bundles for SSR pages | Not a privileged internal caller — forwards the *authenticated user's own* Sanctum/JWT bearer token; Laravel sees it as an ordinary Web-surface request per API Architecture's surfaces table, nothing more |
| Mobile (Flutter) | Separate compiled app, no server tier | Calls `https://api.qayd.app` directly over the public internet; no BFF layer exists for mobile today | Ordinary Mobile-surface request; not part of this document's internal trust boundary at all |
| AI Engine (FastAPI/Python) | Separate Python deployable, its own process fleet | Two distinct internal hops, both over the private VPC network, detailed fully below: Laravel → FastAPI (`/internal/events`) and FastAPI → Laravel (`/api/v1/ai/proposals`) | The only surface with a genuine, separate service identity requiring its own credential and mutual TLS — the sole subject of this document's Authentication and Network Isolation sections |
| Queue Workers | **Not a separate deployable** — Horizon-supervised PHP processes running the identical Laravel codebase and container image as the request-serving Octane tier | In-process: a worker calls Eloquent/the Repository layer directly, exactly as a request-serving Laravel process would; it never makes an HTTP call to "Laravel" because it *is* Laravel | Full trust — a queue worker inherits the same database credentials, the same `company_id` scoping discipline, and the same audit-logging obligations as the request-serving tier; it is never treated as an external or semi-trusted caller |
| Integrations / Public | Partner-owned or unauthenticated | Reach Laravel over the public internet through the Gateway, exactly as documented in API Architecture | Out of scope here — no internal hop involved |

The queue-worker row is the most common source of confusion when this platform is described casually, so
it is stated once, precisely: **"queue worker" is a deployment/runtime distinction (a Horizon process
versus an Octane request-handling process), not a trust-boundary distinction.** A job that reconciles a
bank feed, generates a payslip PDF, or relays a domain event onto the Redis Stream is Laravel code,
running with Laravel's own database role, inside Laravel's own container image. It is not "internal API
traffic" in the sense this document is otherwise concerned with, because no network hop and no service
credential are involved at all — there is nothing to authenticate. The one exception, covered in full
under **Events** below, is the small set of Horizon jobs whose entire job body *is* an outbound HTTP call
to the FastAPI engine (the `events-ai` queue's listener) — that job is ordinary Laravel code up until the
moment it opens a socket to a different deployable, at which point everything in this document applies.

## Topology

```
                      ┌─────────────────────────┐
   Browser  ────────▶ │   Next.js 15 (web tier)  │
                      │   server-side handlers   │──────┐
                      └─────────────────────────┘      │
                                                         │  HTTPS, public internet,
   Flutter app ───────────────────────────────────────┐ │  user's own bearer token
                                                        │ │  (NOT an internal-trust hop)
                                                        ▼ ▼
                                              ┌───────────────────────┐
                                              │  Gateway (Cloudflare  │
                                              │  + Laravel Octane LB) │
                                              └───────────┬───────────┘
                                                           │  private VPC, app subnet
                                                           ▼
                                              ┌───────────────────────┐        in-process,
                              ┌───────────────│   Laravel 12 (Octane) │◀──────  same codebase,
                              │               │   the single source   │        same DB role
                              │               │   of truth            │
                              │               └───────────┬───────────┘
        mTLS + bearer         │                           │
        POST /internal/events │                           │ Eloquent/SQL, TLS verify-full
        (fire-and-forget)     ▼                           ▼
                    ┌───────────────────┐       ┌───────────────────┐
                    │  FastAPI AI Engine │       │    PostgreSQL      │
                    │  (private subnet,  │       │  (data subnet,     │
                    │  NO db credentials)│       │  no route to AI)   │
                    └─────────┬─────────┘       └───────────────────┘
                              │  mTLS + bearer
                              │  POST /api/v1/ai/proposals
                              │  (ordinary authenticated Laravel route)
                              └────────────────────────────▶ (back into Laravel, above)

   Horizon workers (events-accounting, events-sales, events-ai, ... — see Database Events § Queues)
   run alongside the Octane tier in the same app subnet, same container image, same DB role.
```

## Internal endpoint inventory

The following are the only HTTP endpoints this document's trust boundary is concerned with — every one
of them is either unreachable from the public internet by network policy (the FastAPI `/internal/*`
group) or is an ordinary `/api/v1/...` route whose *caller* happens to be a service principal rather than
a human (Laravel's side of the AI contract). Per-module business endpoints are intentionally excluded;
see each module's own document.

| Method | Path | Host | Caller → Callee | Auth | Description |
|---|---|---|---|---|---|
| `POST` | `/internal/events` | `ai-engine.internal.qayd.app` | Laravel → FastAPI | Internal bearer token + mTLS | Fire-and-forget notification that a subscribed domain event was relayed; see **Events** |
| `GET` | `/internal/healthz` | both hosts | Load balancer / orchestrator → service | None (network-restricted to the orchestrator's subnet only) | Liveness probe |
| `GET` | `/internal/readyz` | both hosts | Load balancer / orchestrator → service | None (network-restricted) | Readiness probe — FastAPI reports `not_ready` if its model-provider egress path is unreachable; Laravel reports `not_ready` if it cannot reach Postgres or Redis |
| `GET` | `/internal/metrics` | both hosts | Prometheus scraper → service | mTLS only (scraper holds a monitoring-VPC client cert; no bearer token) | Prometheus exposition format; feeds the dashboards in **API Monitoring** |
| `POST` | `/api/v1/ai/proposals` | `api.qayd.app` | FastAPI → Laravel | `ai_agent`-scoped service-account bearer (Sanctum, `api_keys`-backed) + mTLS | The sole write path by which an AI agent's output reaches the database — an ordinary, fully-validated Laravel route; see **AI Engine Contract** |
| `POST` | `/api/v1/ai/proposals/{id}/retry-notify` | `api.qayd.app` | Horizon (`events-ai` consumer) → FastAPI notification path | Internal bearer token + mTLS | Re-sends a dropped `/internal/events` notification after a Horizon-level retry (see **Events § Retries**) — same credential and contract as the original notify call |

Two things are deliberately absent from this table because they would contradict established platform
canon: there is no `/api/v1/internal/*` prefix on the Laravel side. Every Laravel-hosted route a service
principal calls (`/api/v1/ai/proposals`) is a normal, versioned, publicly-documented-shape route under
`/api/v1/`, identical in envelope and error model to a route a human calls — the only thing that differs
is which principal is authenticated against it. A parallel Laravel-side "internal" namespace with a
different contract would directly violate API Architecture's "one API, many surfaces" principle. The
`/internal/*` prefix exists **only on the FastAPI side**, because FastAPI is not itself a customer-facing
product surface in the way Laravel is — it legitimately has two different kinds of routes: the
public-facing analysis routes the Gateway proxies to it (still authenticated and authorized by Laravel
first, per API Architecture's Gateway layer, before a byte reaches FastAPI), and the genuinely private
`/internal/*` routes that only Laravel is ever permitted to call. This document governs the latter plus
the one Laravel-hosted route (`/api/v1/ai/proposals`) that a service principal, rather than a human,
authenticates against.

# Authentication (service tokens/mTLS)

Internal authentication is deliberately two-layered: a **transport layer** (mutual TLS, proving *which
process* is on the other end of the socket) beneath an **application layer** (a bearer credential, proving
*what that process is authorized to claim it is doing*). Neither layer trusts the other to be sufficient
alone. This mirrors the platform's existing posture toward PostgreSQL — Database Architecture already
mandates `sslmode=verify-full` on every connection, "including for connections that originate from within
the same VPC, because 'the network is trusted' is exactly the assumption that turns a single compromised
internal host into a full plaintext credential and data interception point" — this document simply
extends that same zero-trust-network philosophy to the one cross-service hop Postgres's own server-auth
model does not cover: Laravel ↔ FastAPI.

## Layer 1: mutual TLS

Every socket between the Laravel Octane fleet and the FastAPI fleet is TLS 1.3, and — unlike the
verify-full-but-one-directional model used for Postgres — **mutual**: both peers present a certificate
and both peers verify the other's chain and hostname before a single application byte is exchanged. A
request that fails the mTLS handshake never reaches FastAPI's or Laravel's routing layer at all; it is
rejected by the TLS terminator with a handshake alert, indistinguishable from a network-layer failure to
an attacker probing for valid application-layer errors to learn from.

| Property | Value |
|---|---|
| Certificate authority | A private internal CA (AWS Private Certificate Authority), never a publicly-trusted CA — internal certs must never validate against the public web PKI |
| Subject / CN pattern | `laravel-api.internal.qayd.app`, `ai-engine.internal.qayd.app`, `metrics-scraper.internal.qayd.app` — one identity per logical service, not per host/instance |
| SAN | Matches CN; wildcarded per-environment (`*.internal.qayd-staging.app` vs `*.internal.qayd.app`) so a staging cert can never be accepted by a production peer even if leaked |
| Key algorithm | ECDSA P-256 (faster handshake than RSA-2048 at equivalent security margin, which matters given handshakes recur on every connection-pool cycle) |
| Validity | 24 hours, auto-rotated | 
| Rotation mechanism | A sidecar process on every instance (Laravel Octane worker and FastAPI worker alike) requests a fresh short-lived cert from the internal CA every 12 hours (half the validity window, so a rotation failure has a full 12-hour buffer before any cert actually expires) and hot-reloads it into the running process without a restart |
| Revocation | Short validity (24h) is the primary revocation mechanism — there is no CRL/OCSP round-trip on the hot path; compromising a leaked cert is bounded to, at most, the remaining hours of its validity, and the CA additionally refuses to reissue to an instance whose identity has been marked `decommissioned` in the fleet inventory |
| Verification mode | `verify-full`-equivalent: chain-of-trust AND hostname/SAN match, both directions |

## Layer 2: application-level service credentials

Two distinct credentials exist, one per direction, because the two hops have different shapes and
different existing owners in the platform's credential model:

**Laravel → FastAPI (`internal_token`).** The `NotifyAiLayerListener` job (Database Events § AI Events)
already names this credential: `Http::withToken(config('services.ai_layer.internal_token'))`. It is a
single, opaque, high-entropy static secret (256-bit, base64url, CSPRNG-generated) — not a JWT, because
this hop has no claims worth encoding (there is exactly one legitimate caller, Laravel itself, and exactly
one legitimate callee, the FastAPI internal-routes group; a signed-claims token would add complexity
without adding information). It is stored in AWS Secrets Manager, injected into both fleets' environments
at boot (never committed, never logged, never returned in any API response or error message), and
verified by FastAPI's `/internal/*` dependency-injected auth check using a constant-time comparison
(`hmac.compare_digest` in Python), never `==`. Rotation follows the same dual-validity-window pattern
already established for webhook signing secrets (API Webhooks § Security): a new token is generated,
both old and new are accepted by FastAPI for a 24-hour overlap window while every Laravel instance
picks up the new value on its next config cache refresh, and the old value is then permanently retired.

**FastAPI → Laravel (`ai_agent` service-account bearer).** This hop does *not* introduce a new credential
type — it reuses the platform's existing `api_keys` mechanism (Authentication API § Token Types), which
already anticipates exactly this case: `api_keys.company_id` is explicitly documented as "NULL only for
platform-internal service keys." One such row is provisioned for the AI engine:

```sql
INSERT INTO api_keys (company_id, created_by, name, key_prefix, key_hash, scopes, environment, rate_limit_tier)
VALUES (
    NULL,                                   -- platform-internal service key, not tied to one company
    NULL,
    'FastAPI AI Engine — proposal callback',
    'qk_svc_ai01',
    '<sha256 of the full secret>',
    '["ai.proposals.create"]'::jsonb,       -- see Authorization — this is deliberately narrow
    'live',
    'internal-service'
);
```

The resulting secret is presented as an ordinary `Authorization: Bearer qk_svc_ai01_...` header, validated
by the identical Sanctum guard every other request passes through — there is no second authentication
code path for this call. What makes the caller an `ai_agent` principal rather than a human one is which
row the bearer token resolves to, not a different verification mechanism. Critically, holding
`ai.proposals.create` grants **only** the ability to call the proposal-submission endpoint itself; it
grants nothing about what the *proposed* action may do — that is re-evaluated per-request against the
target module's own permission keys, exactly as **Authorization** below describes. This key is rotated
quarterly via `POST /api/v1/auth/api-keys` → `DELETE .../api-keys/{id}` (Authentication API's existing
endpoints), coordinated with a FastAPI deploy that picks up the new secret from Secrets Manager — there is
nothing AI-specific about key rotation mechanics; it is the platform's ordinary API-key lifecycle applied
to a service instead of an integration partner.

## Request-time verification order

```
Incoming request to /internal/*  (FastAPI)          Incoming request to /api/v1/ai/proposals  (Laravel)
        │                                                     │
        ▼                                                     ▼
[1] TLS terminator: verify client cert chain +       [1] TLS terminator: verify client cert chain +
    hostname (mTLS) — reject at transport if not         hostname (mTLS) — reject at transport if not
        │                                                     │
        ▼                                                     ▼
[2] Compare Authorization: Bearer against             [2] auth:sanctum resolves bearer → api_keys row
    internal_token, constant-time — 401 if mismatch        → ai_agent principal — 401 if invalid/revoked
        │                                                     │
        ▼                                                     ▼
[3] Handle request                                    [3] ResolveCompanyContext + Authorize (RBAC) —
                                                           identical to every other Laravel request
                                                           (see Authorization, below)
```

A request that presents a valid `internal_token` over a connection whose mTLS handshake failed never
reaches step 2 at all — the two layers are independently sufficient to reject a bad actor, and both must
independently pass for a legitimate one. This is deliberate defense in depth: a leaked bearer token alone
is useless without also possessing a valid short-lived certificate issued by the internal CA to the
correct service identity, and a compromised certificate alone (e.g. via a misconfigured sidecar) is
useless without also possessing the corresponding bearer secret from Secrets Manager.

## Replay protection

The `internal_token` is a static secret rather than a signed, time-boxed JWT, so replay protection is
provided by the surrounding layers rather than by claims inside the token itself: mTLS session
resumption is capped at 12 hours (matching cert rotation), and every `/internal/events` call carries a
`jti`-equivalent nonce (the domain event's own `uuid` from `domain_events`, per Database Events) which
FastAPI records in a short-TTL Redis set (5 minutes) purely to detect and log — never silently
drop — an unexpected exact duplicate outside the normal at-least-once redelivery pattern the Events
section below describes as expected behavior. The `ai_agent` bearer, being an ordinary Sanctum-backed
token, already inherits the platform's existing `jti`-in-Redis immediate-revocation mechanism from
Authentication API.

# Authorization

Authorization for internal callers is layered on top of — never a substitute for — the RBAC model
Authorization API & Enforcement already owns in full. This section adds exactly one new dimension:
**service-identity scoping**, which answers a question that does not exist for a human caller — *is this
process even allowed to call this internal endpoint at all*, independent of any permission key — before
the ordinary company/RBAC check ever runs.

## Service-identity allow-list

| Internal caller (by mTLS/cert identity) | Allowed internal endpoints | Forbidden |
|---|---|---|
| Laravel (`laravel-api.internal.qayd.app`) | `POST /internal/events` on the FastAPI host | Nothing else on the FastAPI host — Laravel has no reason to call any other FastAPI route directly; all analysis requests flow through the Gateway's existing `/api/v1/ai/*` proxy path (API Architecture), not a second internal path |
| FastAPI (`ai-engine.internal.qayd.app`) | `POST /api/v1/ai/proposals` on the Laravel host, and nothing else under `/api/v1/` | Every other `/api/v1/...` route — the `ai_agent` `api_keys` row is scoped to exactly one permission key (`ai.proposals.create`); presenting a valid bearer token to, say, `/api/v1/banking/transfers` directly is rejected at the ordinary RBAC layer with `403`, identically to how a human `sales-employee` role is rejected from the same route |
| Metrics scraper (`metrics-scraper.internal.qayd.app`) | `GET /internal/metrics` on both hosts | Everything else — this identity carries no bearer token at all, only an mTLS cert, and the `/internal/metrics` handler does not accept or need a second credential |

This table is enforced at two independent points: the mTLS terminator's SNI/client-cert-identity routing
rules (a connection presenting the `metrics-scraper` certificate is refused a route to anything but
`/internal/metrics` before the request is even parsed), and, for the Laravel-hosted route, the ordinary
Sanctum + RBAC pipeline once the request is inside Laravel.

## Two-question authorization for the AI proposal callback

Every call to `POST /api/v1/ai/proposals` must pass both of the following, in order, exactly as
Authorization API & Enforcement's resolution order prescribes for any principal:

1. **Does the `ai_agent` service-account principal itself hold the permission to call this endpoint at
   all?** Resolved by the ordinary `PermissionResolver` against `api_keys.scopes` (`ai.proposals.create`)
   — a missing scope is `403`, exactly as for a human missing a role grant.
2. **Does the specific proposed action, once unpacked, fall inside a permission the `ai_agent` principal
   is allowed to exercise on the target module?** The proposal payload's `target_type` (e.g.
   `purchase_order`) maps to a target permission key (`purchasing.order.create`) that must *also* be
   present in `api_keys.scopes` for the request to proceed past validation. This second check exists
   because `ai.proposals.create` is intentionally a thin envelope permission — it lets the AI engine speak
   to the endpoint at all, but grants nothing about which domain objects it may draft into.

```php
// app/Http/Controllers/Api/V1/AiProposalController.php (excerpt)
public function store(SubmitAiProposalRequest $request): JsonResponse
{
    $principal = $request->user();               // resolves to the ai_agent-scoped api_keys row
    abort_unless($principal->tokenCan('ai.proposals.create'), 403);

    $targetPermission = TargetPermissionMap::for($request->input('target_type')); // e.g. 'purchasing.order.create'
    abort_unless($principal->tokenCan($targetPermission), 403);

    abort_if(
        PermissionRegistry::isSensitive($targetPermission),
        403,
        "AI agents may never hold a sensitive permission key directly ({$targetPermission})."
    );
    // ... proceeds to FormRequest validation and the Service layer, identically to a human-submitted
    // request against the same target module, per AI Engine Contract below.
}
```

The third check — an explicit, hard-coded refusal of any `is_sensitive = true` permission key
(Authorization API's `permissions.is_sensitive` flag: `bank.transfer`, `payroll.release`, `tax.submit`,
`accounting.journal.void`, `roles.manage`, and the rest of that catalogue) — is a platform invariant, not
a per-key configuration choice: the `ai_agent` role is architecturally incapable of holding a sensitive
grant, so this check can never fire in correctly-configured production, and exists as a defense-in-depth
assertion against a misconfiguration (e.g. an operator accidentally adding a sensitive scope to the
service key) rather than as the primary control.

## Company-scope correlation

Every proposal must declare the company it targets (`X-Company-Id`, resolved identically to any other
request per API Architecture's tenancy layer), but because the caller is a service rather than a
human with a `company_users` membership row, Laravel additionally re-derives the expected company
independently and rejects a mismatch — the `ai_agent` principal is never simply trusted to self-report
the correct tenant:

```php
$sourceEvent = DomainEvent::where('uuid', $request->input('source_event_id'))->firstOrFail();
abort_unless(
    (string) $sourceEvent->company_id === (string) $request->header('X-Company-Id'),
    409,
    'source_event_id does not belong to the company declared in X-Company-Id.'
);
```

This closes a spoofing gap that would otherwise exist if the AI engine (or anything impersonating it)
could submit a proposal claiming to act for a company it was never actually notified about: `source_event_id`
must resolve to a real `domain_events` row (Database Events), and that row's `company_id` must match the
header, or the request is rejected with `409` before validation or the Service layer ever runs.

## Permission catalogue additions

This document introduces one new permission key to Authorization API & Enforcement's canonical catalogue,
following the platform's existing `<area>.<action>` grammar:

| Key | Area | Sensitive | Scope | Granted to |
|---|---|---|---|---|
| `ai.proposals.create` | ai | No (see note) | platform (company resolved per-request, not per-grant) | Exclusively the reserved `ai_agent` service-account principal; never assignable to a human role |
| `ai.actions.approve` | ai | Yes | company | Existing key (Authorization API canonical catalogue) — the human-side counterpart, required to promote an `ai_draft`-status row; unchanged by this document, cited for completeness |

`ai.proposals.create` is marked not-sensitive in the RBAC sense (it does not itself require a human
approval chain to *exercise*, since exercising it only ever produces an `ai_draft`-status row, never a
posted or executed one) — but it is also never eligible to be granted to a human user or a normal role;
platform policy hard-restricts it to the one reserved service-account row provisioned in **Authentication**
above.

# Internal Standards

## No parallel envelope, no parallel versioning — with one narrow exception

Every Laravel-hosted call in this document's scope (`POST /api/v1/ai/proposals`) returns the identical
standard response envelope (`success`, `data`, `message`, `errors`, `meta`, `request_id`, `timestamp`)
defined in API Architecture, versioned under the same `/api/v1/` scheme API Versioning governs, subject
to the same error-code palette (`400`/`401`/`403`/`404`/`409`/`422`/`429`/`500`) API Error Handling
defines. No exception is made for the caller being a service.

The one narrow exception is FastAPI's own `/internal/*` group, which is not a customer-facing contract
and is therefore not required to reproduce Laravel's `ApiResponse` formatter verbatim — it is, after all,
a different language runtime with no shared middleware to inherit it from. It instead uses a minimal,
internally-consistent envelope of its own:

```json
{ "accepted": true, "detail": "queued for analysis", "correlation_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10" }
```

This minimal shape is deliberate, not an oversight: it is never exposed to a customer, a partner
integration, or any surface documented in API Architecture's surfaces table, so it is not held to that
document's envelope contract. Every FastAPI `/internal/*` response nonetheless always includes
`correlation_id` (propagated from the caller's `X-Request-Id`, see below), so that even this minimal
contract remains traceable end to end alongside every other request in the system.

## Correlation and tracing

Every internal hop propagates the correlation identifier already established at the Gateway (API
Architecture: `X-Request-Id`, injected at the edge if the client did not supply one). Concretely:

| Hop | Correlation header carried | Source of the value |
|---|---|---|
| Laravel → FastAPI (`/internal/events`) | `X-Request-Id` | The relayed `domain_events.uuid` (the event's own stable id, per Database Events) — not the id of whatever original human/API request caused the event, since the event may be consumed long after that request finished |
| FastAPI → Laravel (`/api/v1/ai/proposals`) | `X-Request-Id` | The same value FastAPI received on the inbound `/internal/events` call, so the full loop (event → notify → infer → propose) shares one traceable id |
| Any internal call | `traceparent` (W3C Trace Context) | Emitted by both Laravel's and FastAPI's OpenTelemetry SDKs, feeding the distributed tracing pipeline API Monitoring already documents |

This lets an engineer paste one id into the "API Overview" or "AI Engine Health" dashboard (API
Monitoring) and see every hop of a single AI proposal's lifecycle — the originating domain event, the
notify call, the FastAPI inference span, and the resulting proposal write — as one trace, not four
disconnected log lines.

## Idempotency on the internal write path

`POST /api/v1/ai/proposals` is an ordinary money-adjacent `POST` and is therefore subject to API
Idempotency's platform-wide rule without exception — that document states this explicitly: *"The FastAPI
AI engine calls the Laravel API exactly like any other client — it is not a special path and does not
bypass idempotency."* This document adds the one piece that document leaves to the caller: the concrete
key-generation convention FastAPI uses, so that a crash-and-retry inside FastAPI's own process never
double-proposes the same action:

```
Idempotency-Key = "ai-proposal:{source_event_id}:{agent_type}:{attempt_seq}"
```

`source_event_id` is the triggering `domain_events.uuid`; `attempt_seq` increments only if the agent
*deliberately* re-analyzes the same event with materially different reasoning (a new logical attempt, per
API Idempotency's client contract), never on a bare network retry of the same HTTP call, which reuses the
identical key and therefore replays the original response rather than re-proposing.

## Timeouts and retry budgets (internal hops specifically)

These are distinct from — and generally tighter than — the external webhook retry schedule (API
Webhooks), because both peers here are QAYD's own infrastructure on the same private network, not a
third-party endpoint of unknown reliability:

| Call | Connect timeout | Total timeout | Retry policy |
|---|---|---|---|
| Laravel → FastAPI `/internal/events` | 1s | 5s | None inline (fire-and-forget); the wrapping Horizon job's own retry/backoff (Database Events § Retries) covers transient failure — see **Events** below |
| FastAPI → Laravel `/api/v1/ai/proposals` | 2s | 30s (bounds worst-case validation + DB commit, not the preceding inference time, which already completed before this call is made) | Up to 3 attempts, exponential backoff (1s, 4s, 9s), identical `Idempotency-Key` on every attempt per the convention above |
| Either → `/internal/healthz`, `/internal/readyz` | 500ms | 1s | N/A — probes; a failure is a signal, not a request to retry |

## Health, readiness, and internal OpenAPI contracts

Every internal-facing process exposes `/internal/healthz` (process is up) and `/internal/readyz`
(process can serve real traffic — Laravel checks Postgres + Redis reachability; FastAPI checks its
model-provider egress path and its own internal work-queue depth), following the identical
health/readiness contract API Monitoring already establishes for the platform's public-facing endpoints,
just on a network-restricted path rather than a public one. FastAPI's `/internal/*` surface, despite
being unpublished to the developer portal API OpenAPI Spec governs, is still described by its own private
OpenAPI 3.1 document, checked into the AI engine's repository and validated in CI against the live
routes on every deploy — "internal" narrows the *audience*, never the *rigor*. Contract tests run on both
sides of every internal hop: Pest (Laravel) asserts the shape of what it sends to `/internal/events` and
what it accepts back from `/api/v1/ai/proposals`'s own test doubles; pytest (FastAPI, the natural
counterpart to the platform's existing "Pest/PHPUnit (backend), Vitest + Playwright (frontend)" testing
stack once a Python service is in scope) asserts the reverse. A contract-test failure on either side
blocks that side's deploy — an internal contract break is exactly the kind of defect that is invisible to
end-to-end tests exercising only the public surface, since a customer never touches this hop directly.

# Events (internal event bus/queues)

## What this section owns versus what Database Events owns

Database Events is authoritative for the entire data-layer mechanics of QAYD's event system: the
transactional outbox (`domain_events`), the single Redis Stream (`domain-events`) with one consumer group
per module, the relay worker, the Horizon queue topology (`events-accounting`, `events-sales`,
`events-purchasing`, `events-inventory`, `events-payroll`, `events-banking`, `events-notifications`,
`events-ai`, `events-webhooks`, `events-audit`, `events-dlq-sweep`, `events-relay`), the inbox pattern,
retries, and the dead letter queue. None of that is restated here. This section picks up at exactly the
one point Database Events' `NotifyAiLayerListener` sketch leaves as a two-line `Http::post(...)` call —
the internal HTTP contract itself — and specifies it fully, since a wire-level request/response contract
between two independently-deployed services is an API-layer concern, not a database-layer one.

## The notify hop, in full

When the `events-ai` consumer group's Horizon job runs (concurrency 6, per Database Events' queue
topology table, 1–30s typical runtime because the call is network-bound), it issues:

```
POST /internal/events HTTP/1.1
Host: ai-engine.internal.qayd.app
Authorization: Bearer <internal_token>
X-Request-Id: 018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10
Content-Type: application/json

{
  "agent": "inventory_manager",
  "event": {
    "event_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10",
    "event_name": "inventory.low_stock",
    "company_id": 4471,
    "occurred_at": "2026-07-16T05:58:11Z",
    "payload": {
      "product_id": 4471,
      "warehouse_id": 3,
      "qty_on_hand": "4.0000",
      "reorder_point": "20.0000",
      "consecutive_breaches": 3
    }
  }
}
```

FastAPI's `/internal/events` handler acknowledges receipt only — it does not perform inference
synchronously inside this request, since inference latency is unbounded relative to the 5-second total
timeout this hop is held to (**Internal Standards**, above):

```
HTTP/1.1 202 Accepted
Content-Type: application/json

{ "accepted": true, "detail": "queued for analysis", "correlation_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10" }
```

This is intentionally fire-and-forget from Laravel's point of view: the Horizon job that made this call
completes the moment it has a `2xx`, and does not block waiting for FastAPI's eventual conclusion. If
FastAPI is unreachable, slow, or returns a non-2xx, the *job* (not this document, which only specifies the
wire contract) fails in the ordinary Horizon sense and is retried per Database Events' job-level retry
configuration (`backoff = [5, 15, 60, 300, 900]` seconds, per that document's Horizon supervisor
configuration) before eventually landing in `dead_letter_events` if FastAPI remains down past the retry
budget. This is a deliberately graceful degradation path: an AI-engine outage delays AI-assisted features
(a forecast runs late, a fraud flag is raised an hour after it could have been) but never blocks, slows,
or endangers the synchronous financial write path that produced the original domain event — the invoice
was already created and posted, in its own transaction, before `inventory.low_stock` was even emitted.

## The proposal callback, in full

Sometime later — inference time is model-bound and not part of any request's latency budget, as
**Performance** below details — FastAPI concludes and calls back:

```
POST /api/v1/ai/proposals HTTP/1.1
Host: api.qayd.app
Authorization: Bearer qk_svc_ai01_7f3a9c2e1b8d4f6a0c9e2b7d1a4f8c3e
X-Company-Id: 4471
X-Request-Id: 018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10
Idempotency-Key: ai-proposal:018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10:inventory_manager:1
Content-Type: application/json

{
  "agent_type": "inventory_manager",
  "target_type": "purchase_order",
  "confidence_score": "0.8700",
  "reasoning": "product_id 4471 crossed reorder_point 3x in 14 days; supplier lead time 5 days; proposing reorder_quantity 200 units from vendor_id 88 (last PO price basis).",
  "proposed_payload": {
    "vendor_id": 88,
    "warehouse_id": 3,
    "lines": [ { "product_id": 4471, "quantity": 200 } ]
  },
  "source_event_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10"
}
```

Laravel's response is the full standard envelope this document has not shown for this endpoint until now
— Database Events' own sketch of this call stopped short of the response, since the response contract is
this document's territory, not the data layer's:

```json
{
  "success": true,
  "data": {
    "id": 552091,
    "company_id": 4471,
    "target_type": "purchase_order",
    "purchase_order_id": 88820,
    "status": "ai_draft",
    "confidence_score": "0.8700",
    "reasoning": "product_id 4471 crossed reorder_point 3x in 14 days; supplier lead time 5 days; proposing reorder_quantity 200 units from vendor_id 88 (last PO price basis).",
    "requires_approval": true,
    "created_at": "2026-07-16T06:01:44Z"
  },
  "message": "Proposal accepted; purchase order created as ai_draft, pending human approval.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10",
  "timestamp": "2026-07-16T06:01:44Z"
}
```

Laravel's commit of this row is what actually emits `ai.finished` onto the event bus
(`producer_layer = 'ai_callback'`, per Database Events) — the AI engine itself never emits an event
directly, preserving the platform's rule that every event, regardless of which module or agent caused it,
flows through the identical outbox path.

## Backpressure and consumer scaling

The `events-ai` queue's concurrency (6, per Database Events) and its per-company rate limit
(`Limit::perMinute(60)->by('company:...')`, same document) both bound how much of this internal HTTP
traffic can be in flight at once, which matters for capacity planning on the FastAPI fleet: at steady
state, the FastAPI `/internal/events` endpoint sees at most 6 concurrent Laravel-originated requests
platform-wide (not per company), while the `/api/v1/ai/proposals` callback's volume is bound by however
many of those in-flight analyses conclude per unit time — which is why the callback's own rate limiting is
expressed on Laravel's ordinary API rate limiter (Redis-backed, per the fixed platform facts) keyed by the
`ai_agent` service-account key, not by company, since a single shared credential serves every company's AI
traffic.

# AI Engine Contract (AI proposes, backend validates/executes, never DB-direct)

## The rule, and why it is enforced three times, independently

Database Events states the platform rule once, precisely: *"the FastAPI AI layer never writes to
PostgreSQL directly, has no database credentials at all, and participates in the event pipeline
exclusively as a consumer of domain events and, symmetrically, as a caller of the same authenticated
Laravel REST API any human user would use."* This document's contribution is to show that this sentence
is not a single point of failure resting on one well-behaved code path, but the emergent property of
three independent enforcement layers, each of which this document or its neighbors already specifies in
isolation:

1. **Network layer (this document, § Network Isolation, below).** The FastAPI subnet has no route to the
   database subnet at all — not a firewalled-but-present route, an absent one. Even a fully compromised
   FastAPI process with arbitrary code execution cannot open a socket to Postgres, because no such path
   exists in the VPC's routing tables.
2. **Application layer (this document, §§ Authentication and Authorization, above).** The only way
   FastAPI's output can affect a row is by calling `POST /api/v1/ai/proposals` as the `ai_agent`
   principal, which runs through the identical `FormRequest` validation, RBAC permission gate, idempotency
   guard, and audit-log write that a human-submitted request against the same target module would —
   there is no second, AI-shaped code path inside any Controller/Service/Repository.
3. **Data layer (Database Events § "Production: how AI proposals reach the database").** Even if layers 1
   and 2 were somehow both defeated, `CHECK` constraints on every AI-proposable table
   (`chk_ai_draft_requires_review` on `journal_entries`, `purchase_orders`, and siblings) refuse at the
   PostgreSQL engine level to let a row whose `source = 'ai'` land in a terminal, money-moving status
   (`posted`, `approved`, `sent`) directly — this is the layer that holds even against a hypothetical bug
   in layers 1 or 2, not merely a redundant restatement of them.

No autonomy level an agent reports, including `auto` (see below), is ever capable of overriding layer 3,
and no scope an `api_keys` row is granted can widen layer 1 — this is a deliberate asymmetry: policy
(layer 2) can be misconfigured by a human operator; physics (layer 1) and engine-level constraints
(layer 3) cannot be talked out of their position by a misconfigured role grant.

## Agent roster and internal scoping

The fourteen agents named in the platform's shared design context each correspond to one `agent_type`
value on the proposal callback contract above, mapped here to the internal slug the `events-ai` consumer
and `NotifyAiLayerListener` use (Database Events already establishes several of these slugs verbatim;
the remainder follow the identical `lower_snake_case` convention for consistency) and to the default
autonomy level this document's Authorization section constrains:

| Agent (display name) | `agent_type` slug | Default autonomy | Typical target permission(s) |
|---|---|---|---|
| General Accountant | `general_accountant` | `suggest_only` | `accounting.journal.create` |
| Auditor | `auditor` | `suggest_only` | `audit.read` (read-only agent; rarely proposes writes) |
| Tax Advisor | `tax_advisor` | `requires_approval` | `tax.returns.prepare` |
| Payroll Manager | `payroll_manager` | `requires_approval` | `payroll.calculate` |
| Inventory Manager | `inventory_manager` | `suggest_only` | `purchasing.order.create` |
| Treasury Manager | `treasury_manager` | `requires_approval` | `bank.reconcile` (never `bank.transfer` — hard-blocked, see Authorization) |
| CFO | `cfo` | `suggest_only` | `reports.financial.view`, cross-module read synthesis |
| Fraud Detection | `fraud_detection` | `requires_approval` | `audit.read`, raises an approval-gated flag rather than acting |
| Reporting Agent | `reporting_agent` | `auto` (report generation is non-financial-write, reversible, and read-derived) | `reports.export` |
| Document AI | `document_ai` | `auto` (attaches extracted metadata to an existing document; does not itself post anything) | `accounting.read` |
| OCR Agent | `ocr_agent` | `suggest_only` | `purchasing.order.create` (bill/receipt line extraction feeding a draft) |
| Forecast Agent | `forecast_agent` | `auto` (produces a read-only projection artifact, never a ledger write) | `reports.financial.view` |
| Compliance Agent | `compliance_agent` | `requires_approval` | `tax.returns.prepare` |
| Approval Assistant | `approval_assistant` | `suggest_only` | `ai.actions.approve`-adjacent read access only — summarizes pending approvals for a human, never approves on their behalf |

Every row in this table is a *default*; the authoritative, enforced ceiling is still each agent's
`api_keys.scopes` entry (there is one shared `ai_agent` key today, scoped to `ai.proposals.create` plus
the union of target permissions listed above — a future hardening step, noted in each module's own
roadmap, is splitting this into one `api_keys` row per agent for tighter blast-radius containment, which
this document's contract already supports without a shape change, since `agent_type` is carried in the
payload independently of which key authenticated the call).

## Autonomy is a notification hint, never an authorization widener

Database Events is explicit that `autonomy` (`auto` | `suggest_only` | `requires_approval`) "drives how
downstream consumers treat the event" for notification purposes — `events-notifications` suppresses a
push/email for `auto`-autonomy completions and always alerts for the other two. This document adds the
one clarification that matters at the API-contract level: **`autonomy` never changes which HTTP call
succeeds or what status a row lands in.** An `auto`-autonomy proposal still lands as `ai_draft` if its
target permission is anything other than the small, explicitly reversible allow-list Database Events
names (auto-categorizing an already-matched bank transaction, generating a report artifact); it is not a
mechanism by which an agent can request elevated write authority. The distinction is purely: does a human
get interrupted with a notification, or does the (still `ai_draft`, still human-reviewable-on-demand) row
sit quietly until someone looks. Sensitive-operation targets (`bank.transfer`, `payroll.release`,
`tax.submit`, `accounting.journal.void`, and the rest of Authorization API's `is_sensitive` catalogue) are
hard-coded to behave as `requires_approval` regardless of what an agent's own payload claims, enforced by
the same `abort_if(PermissionRegistry::isSensitive(...))` check shown under **Authorization**, above — an
agent cannot self-report its way past this by setting `"autonomy": "auto"` in its own callback payload,
because the callback payload does not carry an `autonomy` field at all; autonomy is a platform-side
classification derived from the target permission's `is_sensitive` flag and the agent's own configured
default, never a client-supplied claim.

## Promotion out of `ai_draft` (contract boundary, not this document's endpoint)

Once a row exists at `status = 'ai_draft'`, promoting it to a normal, human-owned status is an ordinary
authenticated write against that row's *own* module — e.g. `PATCH /api/v1/purchasing/purchase-orders/{id}`
with a body that transitions `status` toward `draft` or directly approves it, gated by that module's own
permission key (`purchasing.order.approve`), never by `ai.proposals.create` or any credential this
document defines. This document deliberately does not invent a universal `/api/v1/ai/proposals/{id}/approve`
endpoint, because the correct promotion action differs by target module (a purchase order's approval
shape is not a journal entry's posting shape), and each module's own document is the authority on its
transition endpoints and their validation rules. The two fixed, cross-module facts this document does
assert, because they follow directly from the Authorization model above rather than from any one module's
business rules, are: (a) the promoting call is always made by a **human** principal holding that module's
real permission key — the `ai_agent` service-account can never call its own promotion endpoint, since it
was never granted the approval-level permission in the first place — and (b) the promotion runs through
the identical FormRequest/Service/Repository pipeline as any other status transition, meaning an
`ai_draft` row that fails validation on promotion (e.g. a referenced `vendor_id` was archived between
proposal and review) is rejected with the ordinary `422`, not silently force-promoted because "the AI
already checked it."

# Performance

## Latency budget for the synchronous path

API Monitoring already publishes the platform SLO this document's synchronous hop contributes to:
*"AI-assisted endpoints (`/ai/*` proxied through Laravel) p95 < 3s."* That figure covers the
Gateway-proxied, user-triggered path (a human calling `/api/v1/ai/analyses` and waiting on the response);
this document's two internal hops decompose where that budget actually goes:

| Segment | Typical p95 contribution | Notes |
|---|---|---|
| Gateway + Auth + Permission + Validation (Laravel, before any AI call) | 15–40ms | Identical pipeline cost to any other endpoint, per API Architecture |
| mTLS handshake, Laravel → FastAPI | <5ms | Amortized to near-zero in steady state — see connection reuse, below; only paid in full on a fresh connection |
| FastAPI inference | 500ms – 2.5s | The dominant, model-bound term; varies by agent and whether a cached embedding/lookup short-circuits full inference |
| FastAPI → Laravel proposal write, incl. DB commit | 30–120ms | Ordinary Laravel write-path cost, per API Architecture's lifecycle walkthrough |

The fire-and-forget `/internal/events` path (**Events**, above) is explicitly **not** part of any
user-facing request's latency budget — it happens after the domain event's own triggering request has
already committed and returned. Conflating the two is the single most common mistake when reasoning about
AI latency on this platform: a slow FastAPI fleet delays *asynchronous* AI features (a forecast, a fraud
flag) but does not, by construction, add one millisecond to the synchronous request that created the
underlying invoice, payment, or journal entry.

## Connection reuse

Laravel runs on Octane (API Architecture), meaning long-lived worker processes rather than a
bootstrap-per-request PHP-FPM model — this document's internal HTTP client to FastAPI takes advantage of
that by maintaining a warm, keep-alive connection pool (Guzzle's persistent-connection handler, one pool
per Octane worker) rather than opening and mTLS-handshaking a fresh socket per call. In steady state, the
"mTLS handshake" row in the table above is paid once per pooled connection's lifetime (bounded by the
12-hour cert rotation window, at which point the pool cycles), not once per request. FastAPI's own
outbound client back to Laravel follows the identical pattern (Python `httpx` with a persistent client
and connection-pool reuse across requests handled by the same worker process).

## Circuit breaking

The synchronous Gateway-proxied path to FastAPI is guarded by a circuit breaker at the Laravel side (a
`stale-if-error`-style policy, implemented via a Redis-backed failure counter keyed by destination):

| Parameter | Value |
|---|---|
| Failure threshold to open | 50% error/timeout rate over a rolling 20-request window |
| Open-state behavior | Immediate `503` with `Retry-After`, skipping the network call entirely — never let a known-down FastAPI fleet accumulate a queue of timed-out Laravel Octane workers |
| Half-open trial | 1 in 10 requests allowed through every 15s while open, to detect recovery without fully re-opening the floodgates |
| Close condition | 5 consecutive successful trial requests |

The fire-and-forget `/internal/events` path does not need an equivalent breaker on the *notify* call
itself (a single 5-second timeout per attempt already bounds the cost of a down FastAPI fleet to that job,
and Horizon's own retry/backoff spacing — 5s, 15s, 60s, 300s, 900s, per Database Events — naturally
backs off without a dedicated breaker), but the `events-ai` supervisor's `maxProcesses` ceiling (part of
the shared `supervisor-notifications-ai-webhooks` pool, Database Events) itself acts as a coarse breaker:
a sustained FastAPI outage saturates that pool with retrying jobs up to its process ceiling and no
further, rather than uncontrolled growth.

## Batching and per-company fairness

The per-company rate limit on `events-ai` (60/minute, Database Events) already prevents one tenant's AI
backlog from starving another's on the shared worker pool. This document's additional recommendation,
which is a FastAPI-side implementation detail rather than a wire-contract requirement (and is therefore
phrased as guidance, not a MUST): agents that naturally operate over a batch of related entities in one
pass — Fraud Detection scanning a day's vendor payments, Forecast Agent recomputing a company's full
projection — should request context once per batch rather than once per entity, since the FastAPI-side
context-fetch itself (a set of ordinary `/api/v1/...` reads, authenticated as the same `ai_agent`
principal, subject to the platform's normal read-endpoint rate limits) is otherwise the most common
source of avoidable N-times-chattier-than-necessary internal traffic.

## Caching

Read-heavy context FastAPI needs repeatedly within a short window (e.g. a company's chart of accounts
while reasoning about several journal proposals in sequence) is cached by Laravel's existing Redis
read-through cache for "hot, rarely-changing lookups" (API Architecture § DB layer) — the AI engine
benefits from this cache identically to a human-driven request hitting the same endpoint; no separate
AI-specific cache tier exists, keeping the caching story platform-wide rather than AI-specific.

# Network Isolation

## VPC and subnet layout

QAYD runs on AWS, consistent with the infrastructure already named elsewhere in this document set
(Database Caching's "AWS ElastiCache for Redis... behind the application's VPC," Database Backup &
Recovery's "AWS Transit Gateway," "Gateway VPC Endpoint" for S3). This document specifies the one part of
that VPC's shape none of those documents needed to: the subnet boundary that makes the FastAPI AI engine
a genuinely separate trust zone from the Laravel application tier, not merely a separate process on the
same tier.

| Subnet | Contains | Reachable from internet? | Reachable from data subnet? |
|---|---|---|---|
| `public` | Cloudflare-fronted ALB/NLB only (API Architecture's Gateway) | Yes — the only subnet that is | No |
| `app` | Laravel Octane fleet + all Horizon queue-worker processes (same subnet, same trust tier — see **Service-to-Service APIs**) | No — only via the `public` subnet's load balancer | Yes (this is the one legitimate path to `data`) |
| `ai` | FastAPI fleet exclusively | No | **No — no route exists, by design** |
| `data` | PostgreSQL (primary + replicas) and the Redis/ElastiCache cluster | No | N/A |
| `monitoring` | Prometheus, the metrics scraper identity, log aggregation | No (peered via Transit Gateway from a separate monitoring account, per Database Monitoring's existing "dedicated monitoring-VPC service" reference) | Read-only exporters in `app`/`ai`/`data` only |

The `ai` subnet's absent route to `data` is a routing-table fact, not a security-group rule layered on
top of an existing route — the distinction matters because a security-group misconfiguration (an
overly-broad rule accidentally added during an incident) can widen what a security group permits, but it
cannot conjure a route that does not exist in the VPC's route table. This is the strongest available
version of "the AI engine cannot reach the database," stronger than a firewall rule alone.

## Security group / traffic matrix

| Source SG | Destination SG | Port | Protocol | Purpose |
|---|---|---|---|---|
| `public-alb-sg` | `app-sg` | 443 | HTTPS (Cloudflare-terminated, re-encrypted to origin) | Customer/partner API traffic |
| `app-sg` | `ai-sg` | 8443 | HTTPS + mTLS | `POST /internal/events` |
| `ai-sg` | `app-sg` | 443 | HTTPS + mTLS | `POST /api/v1/ai/proposals` (and the Gateway-proxied public analysis path, per API Architecture) |
| `app-sg` | `data-sg` | 5432 | TLS, `sslmode=verify-full` | PostgreSQL (Database Architecture) |
| `app-sg` | `data-sg` | 6379 | TLS | Redis/ElastiCache (Database Caching) |
| `ai-sg` | `data-sg` | any | any | **Explicit DENY** — the enforcement point, restated as a security-group rule in addition to the routing-table fact above, so that a future VPC peering change cannot silently reopen the path without also relaxing this explicit deny |
| `ai-sg` | `0.0.0.0/0` (internet egress, via NAT + an egress firewall allow-list) | 443 | HTTPS | The external LLM/model-provider API only — no other destination is reachable; the allow-list is a small, explicit set of provider hostnames, reviewed on change |
| `monitoring-sg` | `app-sg`, `ai-sg` | 9187/9090-class exporter ports | HTTPS + mTLS (client cert only, no bearer token) | `GET /internal/metrics` |
| any | `app-sg`, `ai-sg` | anything not listed above | any | Implicit deny (default-deny security group posture, consistent with the platform's application-layer default-deny RBAC philosophy) |

## Internal DNS

`*.internal.qayd.app` resolves only within the VPC (a private Route 53 hosted zone, never published to
public DNS), so `ai-engine.internal.qayd.app` and `laravel-api.internal.qayd.app` (the same names used as
mTLS certificate CNs in **Authentication**) have no public A/AAAA record at all — a mistaken attempt to
reach either from outside the VPC fails at DNS resolution, before a connection is even attempted.

## How this closes the loop with Authentication and the AI Engine Contract

Network isolation is the layer that makes the mTLS certificate identities in **Authentication**
meaningful rather than merely decorative: a certificate for `ai-engine.internal.qayd.app` is only ever
presented from inside the `ai-sg` security group in practice, because that is the only place the private
key is provisioned, and the security-group/subnet boundaries above mean no other workload could present
it convincingly even if it were somehow exfiltrated to a host outside `ai-sg` — such a host still has no
route to `app-sg` or `data-sg` to use it against. This is the concrete, infrastructure-level realization of
the statement made once already, at the top of **AI Engine Contract**: the platform rule that the AI
engine never touches PostgreSQL directly holds because no code path, no granted permission, *and* no
network path exists — three independent reasons the same true sentence remains true even if one of them
is ever individually compromised.

# Examples

## Example 1 — Successful notify-then-propose round trip (Inventory Manager)

Request 1 (Laravel → FastAPI, fire-and-forget notify):

```
POST /internal/events HTTP/1.1
Host: ai-engine.internal.qayd.app
Authorization: Bearer <internal_token>
X-Request-Id: 018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10
Content-Type: application/json

{ "agent": "inventory_manager", "event": { "event_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10",
  "event_name": "inventory.low_stock", "company_id": 4471,
  "payload": { "product_id": 4471, "warehouse_id": 3, "qty_on_hand": "4.0000" } } }
```

```
HTTP/1.1 202 Accepted
{ "accepted": true, "detail": "queued for analysis", "correlation_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10" }
```

Request 2 (FastAPI → Laravel, proposal callback, ~40 seconds later):

```
POST /api/v1/ai/proposals HTTP/1.1
Host: api.qayd.app
Authorization: Bearer qk_svc_ai01_7f3a9c2e1b8d4f6a0c9e2b7d1a4f8c3e
X-Company-Id: 4471
Idempotency-Key: ai-proposal:018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10:inventory_manager:1
Content-Type: application/json

{ "agent_type": "inventory_manager", "target_type": "purchase_order", "confidence_score": "0.8700",
  "reasoning": "product_id 4471 crossed reorder_point 3x in 14 days; proposing 200 units from vendor_id 88.",
  "proposed_payload": { "vendor_id": 88, "warehouse_id": 3, "lines": [{"product_id": 4471, "quantity": 200}] },
  "source_event_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10" }
```

```json
{
  "success": true,
  "data": { "id": 552091, "purchase_order_id": 88820, "status": "ai_draft", "confidence_score": "0.8700",
             "requires_approval": true, "created_at": "2026-07-16T06:01:44Z" },
  "message": "Proposal accepted; purchase order created as ai_draft, pending human approval.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10", "timestamp": "2026-07-16T06:01:44Z"
}
```

## Example 2 — Rejected: agent attempts to target a sensitive permission directly

The Treasury Manager agent, misconfigured or manipulated by a bad prompt, attempts to propose a direct
bank transfer instead of a reconciliation note:

```
POST /api/v1/ai/proposals HTTP/1.1
Host: api.qayd.app
Authorization: Bearer qk_svc_ai01_7f3a9c2e1b8d4f6a0c9e2b7d1a4f8c3e
X-Company-Id: 4471
Idempotency-Key: ai-proposal:9c1e...:treasury_manager:1

{ "agent_type": "treasury_manager", "target_type": "bank_transfer", "confidence_score": "0.9900",
  "reasoning": "Vendor invoice due today; recommend immediate settlement.",
  "proposed_payload": { "from_account_id": 5, "to_account_id": 41, "amount": "8000.0000" },
  "source_event_id": "9c1e2a3b-4d5e-6f70-8192-a3b4c5d6e7f8" }
```

```
HTTP/1.1 403 Forbidden
{
  "success": false,
  "data": null,
  "message": "AI agents may never hold a sensitive permission key directly (bank.transfer).",
  "errors": [ { "field": "target_type", "code": "AI_SENSITIVE_TARGET_FORBIDDEN" } ],
  "meta": { "pagination": null },
  "request_id": "7a2b3c4d-5e6f-4a1b-8c9d-0e1f2a3b4c5d",
  "timestamp": "2026-07-16T06:05:12Z"
}
```

No `bank_transfers` row of any status is created — the request is rejected before validation, per the
`abort_if(PermissionRegistry::isSensitive(...))` check in **Authorization**. This is a `403`, not a `422`,
because the failure is an authorization decision, not a data-shape problem — a distinction worth
preserving in client/SDK error handling exactly as **API Idempotency**'s own `409` sub-code discriminator
pattern does for its two conflict types.

## Example 3 — Company-scope correlation failure

```
POST /api/v1/ai/proposals HTTP/1.1
X-Company-Id: 19
Idempotency-Key: ai-proposal:018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10:inventory_manager:1

{ "agent_type": "inventory_manager", "target_type": "purchase_order", ...,
  "source_event_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10" }
```

`source_event_id` resolves to a `domain_events` row whose `company_id` is `4471`, not `19`:

```
HTTP/1.1 409 Conflict
{
  "success": false,
  "data": null,
  "message": "source_event_id does not belong to the company declared in X-Company-Id.",
  "errors": [ { "field": "source_event_id", "code": "COMPANY_SCOPE_MISMATCH" } ],
  "meta": { "pagination": null },
  "request_id": "3f4a5b6c-7d8e-4f9a-8b1c-2d3e4f5a6b7c",
  "timestamp": "2026-07-16T06:07:30Z"
}
```

## Example 4 — Internal auth failure at each layer

**mTLS failure** (expired or wrong-identity client certificate) — rejected at the TLS terminator, never
reaching HTTP-level routing at all:

```
$ curl --cert expired-ai-engine.pem --key ai-engine.key https://laravel-api.internal.qayd.app/api/v1/ai/proposals
curl: (35) OpenSSL SSL_connect: SSL_ERROR_SSL in connection to laravel-api.internal.qayd.app:443
```

**Bearer failure** (valid mTLS, stale `internal_token`) on the notify hop:

```
HTTP/1.1 401 Unauthorized
{ "accepted": false, "detail": "invalid or expired internal token", "correlation_id": "b2c3d4e5-..." }
```

## Example 5 — Idempotent retry (FastAPI-side network blip)

FastAPI's own HTTP client times out waiting for Laravel's response to Example 1's proposal call (the
response was in fact already committed) and retries with the identical `Idempotency-Key`:

```
HTTP/1.1 201 Created
Idempotency-Replayed: true
Idempotency-Status: replayed

{ "success": true, "data": { "id": 552091, "purchase_order_id": 88820, "status": "ai_draft", ... },
  "message": "Proposal accepted; purchase order created as ai_draft, pending human approval.",
  "errors": [], "meta": { "pagination": null },
  "request_id": "018f2e6a-9b3a-7000-8b1e-3a2c5e6f7a10", "timestamp": "2026-07-16T06:01:44Z" }
```

No second `purchase_orders` row is created — per **Internal Standards**' idempotency-key convention and
API Idempotency's platform-wide replay guarantee, this is a pure cache hit against the original call's
stored response, `request_id` included, exactly as it would be for any other client of this platform.

## Example 6 — Manual internal health check during an incident

```
$ curl --cert ops-oncall.pem --key ops-oncall.key \
    https://ai-engine.internal.qayd.app/internal/readyz
{"status":"ready","checks":{"model_provider_egress":"ok","internal_queue_depth":3}}

$ curl --cert ops-oncall.pem --key ops-oncall.key \
    https://laravel-api.internal.qayd.app/internal/readyz
{"status":"ready","checks":{"postgres":"ok","redis":"ok"}}
```

Both probes require a valid mTLS client certificate issued to an on-call identity (network-restricted to
the `monitoring`/bastion path); neither accepts or needs a bearer token, consistent with the health-check
row in **Internal Standards**' timeout table.

# End of Document
