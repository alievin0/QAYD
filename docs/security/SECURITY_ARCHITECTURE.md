# Security Architecture — QAYD Security
Version: 1.0
Status: Design Specification
Module: Security
Submodule: SECURITY_ARCHITECTURE
---

# Purpose

This document is the entry point for `docs/security/`. It states, once and authoritatively, the
security posture that every other document in this folder specializes: what QAYD is defending, whom
it is defending against, where the trust boundaries sit, and which controls sit on each boundary.
The four sibling documents — [AUTHENTICATION.md](AUTHENTICATION.md),
[AUTHORIZATION.md](AUTHORIZATION.md), [TENANT_ISOLATION.md](TENANT_ISOLATION.md), and
[SECRETS.md](SECRETS.md) — are depth specializations of one layer each of the model described here.
Where they conflict with this document, this document is wrong and should be corrected; where they
go deeper, they win on the detail.

QAYD is a multi-tenant, AI-native, bilingual (English / Arabic, full RTL) accounting platform for
the Kuwait and wider GCC market. It holds the most consequential data a business owns — its general
ledger, its bank balances, its payroll, its tax position, and the personally identifiable
information of its employees and counterparties. It exposes that data through a single API to four
kinds of client (a Next.js web application, a Flutter mobile application, a FastAPI AI engine, and
third-party integrations), denominated in KWD and other GCC currencies, under regulatory regimes
that are still maturing. A breach here is not an inconvenience; it is a company's finances in an
attacker's hands. The security model is therefore built to assume compromise of any single layer and
still deny the attacker the ledger.

This document does not redefine the foundation. It goes **deeper** than
[../foundation/SECURITY_ARCHITECTURE.md](../foundation/SECURITY_ARCHITECTURE.md) — which states the
principles as aspirations ("everything encrypted, everything logged, everything verified, nothing
trusted") — by turning each of those principles into a boundary, a control, an enforcement point,
and a failure mode that an engineer can build and test. It assumes the reader has read the
foundation document and the platform facts in
[../foundation/TECH_STACK.md](../foundation/TECH_STACK.md).

---

# Threat Model / Principles

## What QAYD is defending

| Asset | Class | Why it matters |
|---|---|---|
| The general ledger, trial balance, financial statements | Confidential | The company's complete financial truth; exposure damages the company, tampering corrupts its books |
| Bank account numbers, IBANs, balances, and transfer capability | Restricted | Direct path to moving money; the highest-value target in the system |
| Payroll and employee PII (salaries, national IDs, tax IDs) | Restricted | Individually sensitive; regulated; a single leak is a reportable event |
| Tax returns and submission capability | Restricted | Legal filings on behalf of the company; a forged submission is fraud in the company's name |
| Cross-tenant boundary | Structural | The single assumption the entire multi-tenant business rests on: no company ever sees another's data |
| Authentication and signing secrets | Restricted | Custody of these is custody of every identity and every token in the platform |
| The audit trail | Confidential / integrity-critical | The record of who did what; if it can be forged or erased, every other control becomes deniable |

## Whom QAYD is defending against

QAYD's threat model names five adversary classes, in rough order of how much of this folder is
written to stop them:

1. **The external attacker with a stolen credential.** A phished CFO password, a bearer token
   captured on a compromised device, a leaked integration API key. The whole authentication and
   session model ([AUTHENTICATION.md](AUTHENTICATION.md)) exists to make a stolen credential
   short-lived, revocable, MFA-gated on the actions that matter, and detectable when it is replayed.
2. **The malicious or curious tenant.** A legitimate user of Company A who tries to read, guess, or
   enumerate Company B's data — by incrementing an id, smuggling a second `company_id` into a
   request body, or probing an endpoint the UI never showed them. The entire tenant-isolation model
   ([TENANT_ISOLATION.md](TENANT_ISOLATION.md)) is written for this adversary.
3. **The over-privileged or hostile insider.** A user with a real grant who tries to exceed it — to
   approve their own bank transfer, to escalate their own role, to read payslips their role does not
   entitle them to. Authorization ([AUTHORIZATION.md](AUTHORIZATION.md)) and the two-key approval
   chains are written for this adversary.
4. **The compromised AI engine.** The FastAPI service is a separate process with its own
   attack surface (prompt injection, a poisoned document, a compromised model provider). QAYD treats
   it as a *semi-trusted client*, never as part of the trusted core: it holds a narrow service
   credential, can read and draft but never post/approve/release/submit, and passes through the
   identical authorization pipeline as any human.
5. **The infrastructure adversary.** Someone who reaches the network, a container, a backup, or a
   secrets store. Defense here is encryption at rest, secret custody ([SECRETS.md](SECRETS.md)),
   least-privilege service identities, and the database's own row-level security as the boundary of
   last resort.

## The principles every other document specializes

These are the load-bearing beliefs. Each sibling document is, in effect, one of these principles
worked out in full.

- **Server-authoritative, always.** Every security decision — is this identity valid, does it belong
  to this company, does it hold this permission — is made on the Laravel backend, freshly, on every
  request. Client-side checks (a hidden button in the Next.js UI, a disabled field in the Flutter
  app) exist only to make the interface pleasant; they are never a security control and are assumed
  to be absent or hostile. A request that the UI would never have sent is treated exactly like one
  it would have.
- **Zero trust between layers.** No layer trusts the layer that called it just because of where the
  call came from. The AI engine calling the API is authenticated and authorized like any client. The
  API reaching the database sets a per-request tenant context the database independently enforces. A
  private-network position is never, by itself, an authorization.
- **Deny by default.** The absence of an explicit grant is a denial. A new endpoint, a new field, a
  new permission is unreachable until a migration explicitly grants it — to every role, Owner
  included. It is always safer for a new sensitive capability to need a deliberate follow-up grant
  than to inherit access silently through a broad pre-existing role.
- **Defense in depth.** No single control is the boundary. Tenant isolation is enforced at the HTTP
  middleware, the Eloquent global scope, the Policy layer, and PostgreSQL RLS — four independent
  layers, any one of which alone would stop the attack, precisely so that a bug in one is not a
  breach.
- **Two-key control on money and identity.** No single credential — human or AI — can drive a
  sensitive flow (bank transfer, payroll release, tax submission, permission change) to completion
  alone. These are modeled as two-actor state machines, so one compromised credential is contained.
- **Everything auditable, the audit tamper-evident.** Every consequential action and every access
  denial is logged with actor, company, IP, device, before/after, timestamp, and reason. The log is
  append-only and integrity-protected, because a control you cannot prove fired is a control an
  attacker can later deny.
- **The AI is on-behalf-of, never instead-of.** The AI engine acts within the calling user's
  permission envelope, never beyond it, and never holds an approval permission. It proposes; a human
  disposes.

---

# Trust Boundaries

A trust boundary is a line across which the level of trust changes and across which every crossing
must be authenticated, authorized, and validated. QAYD has seven, and naming them precisely is the
foundation for everything else — a control is only meaningful once you can say which boundary it
sits on.

```
                            TRUST BOUNDARIES (each │ is a boundary; nothing crosses it unchecked)

   Untrusted        Semi-trusted         Trusted core                     Data plane
   ┌────────┐   │   ┌──────────┐    │    ┌──────────────┐    │    ┌──────────────────────────┐
   │ Browser│   │   │ Next.js  │    │    │  Laravel 12  │    │    │  PostgreSQL (RLS)        │
   │ / mobile│──┼──▶│ web tier │────┼───▶│  API (single │────┼───▶│  Redis (cache/queue)     │
   │  device │   │   │ (BFF/SSR)│    │    │  entry point)│    │    │  Cloudflare R2 (storage) │
   └────────┘   │   └──────────┘    │    └──────┬───────┘    │    └──────────────────────────┘
        ▲       │                   │           │ ▲          │
        │       │   ┌──────────┐    │           ▼ │          │
   3rd-party ───┼──▶│ FastAPI  │────┼───────────┘ │ (service JWT + agent signature,
   integration  │   │ AI engine│◀───┼─────────────┘  narrow scope, read/draft only)
                │   └──────────┘    │
              (B1)               (B2)          (B3)                       (B4)
```

| # | Boundary | Lower-trust side | Higher-trust side | What must happen at the crossing |
|---|---|---|---|---|
| B1 | Client ↔ web tier | The browser / mobile device (fully untrusted; assume the user is hostile and the device may be compromised) | Next.js web tier | TLS 1.2+ (1.3 preferred); the web tier treats all rendered client state as advisory; CSP, secure cookies, XSS-safe rendering |
| B2 | Web tier / AI engine / integration ↔ API | Next.js (semi-trusted BFF), FastAPI AI engine (semi-trusted), third-party integrations (untrusted) | Laravel API | Authentication (Sanctum session cookie for web, bearer token for mobile/partner/AI), CSRF (cookie flow), `X-Company-Id` tenant resolution, RBAC authorization, input validation |
| B3 | API ↔ AI engine (return path) | Laravel API | FastAPI AI engine | The AI engine is never handed data the calling user cannot see; the API scopes every payload to the user's permission envelope before the AI ever receives it |
| B4 | API ↔ data plane | Laravel API | PostgreSQL, Redis, Cloudflare R2 | Per-request tenant GUCs set for RLS; least-privilege DB role; encrypted-at-rest Restricted columns; signed, expiring URLs for storage; authenticated Redis |

Two facts about this topology are load-bearing and repeated throughout the folder:

- **The Laravel API is the single entry point to the data plane.** No client — not the Next.js
  server, not the Flutter app, not the AI engine, not any integration — ever opens a direct
  connection to PostgreSQL, Redis, or R2. Every read and every write of company data flows through
  the API's authenticate → resolve-tenant → authorize → validate → execute → audit pipeline
  (specified in [../api/API_SECURITY.md](../api/API_SECURITY.md)). This is what makes it possible to
  reason about the system at all: there is exactly one place where the rules live.
- **The AI engine is on the untrusted side of B2, not inside the trusted core.** It runs as a
  separate FastAPI service (per [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md)) precisely
  so that its larger and fuzzier attack surface — natural-language input, third-party model
  providers, document ingestion — is quarantined behind the same boundary as any other client. When
  it calls the API it authenticates with a narrowly-scoped service credential and passes through the
  full pipeline; when it receives data, that data has already been scoped to the calling user.

---

# Defense-in-Depth Layers

QAYD's controls are organized as concentric layers. An attacker must defeat **every** layer between
them and an asset; a defender needs only one layer to hold. The table reads from the outermost
(closest to the attacker) to the innermost (closest to the data), and names the document that owns
each layer's detail.

| Layer | Control | Defeats | Owner document |
|---|---|---|---|
| L0 — Edge | Cloudflare WAF, DDoS absorption, bot mitigation, TLS termination | Volumetric floods, known-bad bots, raw scanning | [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| L1 — Transport | TLS 1.2+ (1.3 preferred), HSTS, cert pinning on mobile, mTLS service-to-service | Eavesdropping, downgrade, MITM | [AUTHENTICATION.md](AUTHENTICATION.md), [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| L2 — Authentication | Sanctum sessions/tokens, MFA/TOTP on financial roles, revocation, brute-force lockout | Stolen/guessed credentials, session replay | [AUTHENTICATION.md](AUTHENTICATION.md) |
| L3 — Tenant resolution | `X-Company-Id` validated against token/membership + live `company_users` | Cross-tenant access via a valid credential | [TENANT_ISOLATION.md](TENANT_ISOLATION.md) |
| L4 — Authorization | RBAC `<area>.<action>` / `<area>.<entity>.<action>`, deny-by-default, Policies, two-key chains | Privilege escalation, unauthorized actions | [AUTHORIZATION.md](AUTHORIZATION.md) |
| L5 — Input validation | Form Requests (whitelist), OpenAPI schema check, mass-assignment guard, injection prevention | Injection, malformed/oversized/forged payloads | [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| L6 — Data-access scoping | Eloquent `CompanyScope` global scope, tenant-scoped route-model binding | IDOR, accidental unscoped query | [TENANT_ISOLATION.md](TENANT_ISOLATION.md) |
| L7 — Database RLS | PostgreSQL row-level security keyed on per-request GUCs (`app.company_id`, `app.user_id`) | A bug in any layer above that lets a query through unscoped | [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md), [TENANT_ISOLATION.md](TENANT_ISOLATION.md) |
| L8 — Data at rest | AES-256-GCM app-layer encryption of Restricted columns, encrypted volumes, signed storage URLs | Infrastructure/backup compromise | [SECRETS.md](SECRETS.md), [ENCRYPTION.md](ENCRYPTION.md) |
| L9 — Audit & detection | Immutable audit log, access-log alerting, anomaly heuristics | Undetected abuse, deniability of actions | [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md), [../api/API_SECURITY.md](../api/API_SECURITY.md) |

The single most important property of this table is that **L3, L4, L6, and L7 are four independent
enforcements of the same tenant/permission boundary.** A cross-tenant read must slip past the HTTP
middleware *and* the Eloquent scope *and* the Policy *and* PostgreSQL RLS. Each was implemented by
different code, and each fails closed. This is not redundancy for its own sake; it is the explicit
acknowledgment that the tenant boundary is the one boundary QAYD cannot afford to get wrong, and so
it is enforced four times over. [TENANT_ISOLATION.md](TENANT_ISOLATION.md) is the document that walks
all four in detail.

---

# The Shared-Responsibility Posture

Security is a contract between QAYD-the-platform, the layers QAYD builds on, and the customer. Being
explicit about who owns what prevents the two classic failures: a gap everyone assumed someone else
covered, and a control duplicated so badly it creates its own bugs.

| Concern | Cloud / edge provider (AWS, Cloudflare) | QAYD platform (this folder) | Customer (the company) |
|---|---|---|---|
| Physical & network infrastructure | Owns | Configures least-privilege access | — |
| DDoS / volumetric absorption | Owns (edge) | Configures WAF rules and rate tiers | — |
| TLS termination & certificate issuance | Provides ACME/edge | Enforces the floor, pins on mobile | — |
| Encryption at rest (volume) | Provides | Adds app-layer AES-256-GCM on Restricted columns | — |
| Authentication of end users | — | Owns (Sanctum, MFA, lockout) | Enforces MFA enrollment for their staff; protects their own passwords/devices |
| Authorization (who can do what) | — | Owns the mechanism (RBAC, Policies, two-key) | Owns the policy (assigns roles, configures approval chains, revokes leavers) |
| Tenant isolation | — | Owns (4-layer enforcement) | — |
| Secret custody (QAYD's own) | Provides vault/KMS primitives | Owns rotation, least-privilege, break-glass | — |
| Data the customer enters | — | Protects it per its classification | Owns its accuracy and its legal basis for holding it |
| Third-party integration keys | — | Issues, scopes, hashes, monitors | Guards the one-time-shown key; revokes on suspicion |
| Offboarding a user | — | Provides instant revocation & emergency lock | Performs the offboarding promptly |

The recurring theme in the right-hand column: **QAYD owns the mechanism, the customer owns the
policy.** QAYD guarantees that a role without `bank.transfer.approve` cannot approve a transfer; the
customer decides who gets that role. QAYD guarantees a revoked session dies within one request; the
customer must actually click "revoke" when an employee leaves. The customer-facing surfaces that make
these responsibilities actionable are specified in the frontend settings and audit-log docs.

---

# Enforcement Points

Every control in this folder is anchored to a concrete, testable enforcement point in the code. This
section is the index that maps the abstract layers above to the places an engineer actually edits.

| Enforcement point | Mechanism | Layers enforced | Detail in |
|---|---|---|---|
| Edge / WAF | Cloudflare rules, rate tiers, bot rules | L0 | [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| Ingress / reverse proxy | TLS floor, `X-Forwarded-Proto` re-validation, mTLS for service calls | L1 | [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| `EnsureFrontendRequestsAreStateful` + CSRF | Sanctum SPA stateful guard, CSRF token check on cookie flow | L2 | [AUTHENTICATION.md](AUTHENTICATION.md) |
| `Authenticate` / `VerifyServiceToken` middleware | Session/bearer validation, revocation denylist, device binding | L2 | [AUTHENTICATION.md](AUTHENTICATION.md) |
| `ResolveTenantCompany` middleware | `X-Company-Id` → token claim → live `company_users` check; sets `TenantContext` and DB GUCs | L3, L7 | [TENANT_ISOLATION.md](TENANT_ISOLATION.md) |
| Route middleware `can:<permission>` | Deny-by-default permission gate on the route | L4 | [AUTHORIZATION.md](AUTHORIZATION.md) |
| Form Request `rules()` + OpenAPI schema check | Whitelist validation, mass-assignment guard, injection prevention | L5 | [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| Policy classes (`$this->authorize(...)`) | Per-model, per-action, attribute-aware authorization | L4, L6 | [AUTHORIZATION.md](AUTHORIZATION.md) |
| Eloquent `CompanyScope` global scope | Automatic `WHERE company_id = ?`; tenant-scoped route-model binding | L6 | [TENANT_ISOLATION.md](TENANT_ISOLATION.md) |
| PostgreSQL RLS policies | `USING (company_id = current_setting('app.company_id')::bigint)` on every tenant table | L7 | [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) |
| Encrypted attribute casts + KMS | AES-256-GCM on Restricted columns | L8 | [SECRETS.md](SECRETS.md), [ENCRYPTION.md](ENCRYPTION.md) |
| Audit observers / listeners | Append-only audit records on every consequential mutation | L9 | [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) |

The single canonical ordering — no step skippable, no step reorderable, no caller exempt — is:

```
authenticate → resolve tenant → authorize → validate → execute (scoped) → audit
```

This is the same pipeline specified in [../api/API_SECURITY.md](../api/API_SECURITY.md); it is
restated here because it is the spine the entire folder hangs on.

---

# Boundaries

This document owns the **map**; it deliberately does not own the **territory**. To keep the folder
free of contradiction, the following division of ownership is fixed:

- **Authentication mechanics** — session vs. token models, CSRF, MFA/TOTP, password hashing,
  lockout, device trust, SSO — are owned by [AUTHENTICATION.md](AUTHENTICATION.md). This document
  only states *that* B2 requires authentication.
- **Authorization mechanics** — the permission grammar, roles, Policy enforcement points,
  escalation prevention, two-key chains, AI on-behalf-of scoping — are owned by
  [AUTHORIZATION.md](AUTHORIZATION.md).
- **Tenant-isolation mechanics** — the `X-Company-Id` boundary, the four-layer enforcement, query
  scoping, storage/cache/search/job isolation, the anti-leakage test strategy — are owned by
  [TENANT_ISOLATION.md](TENANT_ISOLATION.md).
- **Secret and key mechanics** — env/secret handling, rotation, third-party credential custody, the
  no-secrets-in-the-frontend-bundle rule, CI/CD secrets, leaked-secret incident response — are owned
  by [SECRETS.md](SECRETS.md), with encryption-key custody deferred to
  [ENCRYPTION.md](ENCRYPTION.md).
- **API-surface mechanics** — transport, OWASP API Top 10, input validation, injection prevention,
  CORS, request signing, rate limiting, PII masking in responses — are owned by
  [../api/API_SECURITY.md](../api/API_SECURITY.md); this folder cross-links to it rather than
  duplicating it.
- **Database-enforcement mechanics** — RLS policy shapes, GUC contract, role separation — are owned
  by [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md) and
  [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md).

Out of scope for the whole `docs/security/` folder: application feature behavior (owned by the
module docs under `docs/accounting/`, `docs/ai/`, `docs/frontend/`), and the operational runbooks
for backup/DR (owned by [../database/DATABASE_BACKUP_RECOVERY.md](../database/DATABASE_BACKUP_RECOVERY.md)).
Security requirements *on* those areas are stated here and in the siblings; their implementation
lives in those docs.

---

# Failure Modes

A security architecture is defined as much by how it fails as by how it works. The invariant across
every layer is **fail closed**: when a control cannot make a positive, confident allow decision, it
denies. The table names the failure of each layer, the resulting behavior, and the layer that catches
it if this one is the one that is broken.

| Failing layer | Failure event | Fail-closed behavior | Caught by (if this layer is buggy) |
|---|---|---|---|
| L1 Transport | Cert pin mismatch / downgrade attempt | Request aborted, not retried over an unpinned channel | — (transport is the outer boundary) |
| L2 Authentication | Expired / revoked / malformed credential | `401 Unauthorized`; no downstream execution | — |
| L3 Tenant resolution | `X-Company-Id` missing, malformed, or not in membership | `403 TENANT_NOT_AUTHORIZED`; request halts | L6, L7 (scope + RLS still deny) |
| L4 Authorization | No explicit permission grant | `403` (in-tenant) — deny by default | L7 (RLS denies rows the query shouldn't touch) |
| L5 Input validation | Unknown field / malformed / oversized body | `422 Unprocessable Entity`; nothing executes | — |
| L6 Data scoping | `CompanyScope` accidentally omitted on a query | Cross-tenant row *would* return… | L7 RLS refuses it at the database |
| L7 Database RLS | GUC unset for the connection | Policy evaluates to false → **zero rows**, never all rows | — (this is the last line; it is designed to fail to empty, not to open) |
| L8 Data at rest | Decryption key unavailable | Field read fails loudly; the record is not served in cleartext | — |
| L9 Audit | Audit sink unavailable on a sensitive write | The write is refused, not silently completed unlogged | — |

Two failure modes deserve emphasis because they are where financial platforms most often go wrong:

- **RLS fails to *empty*, not to *all*.** If a connection reaches PostgreSQL without the
  per-request tenant GUC set, QAYD's policies are written so the `USING` clause evaluates false and
  the query returns **no rows** — never every tenant's rows. A forgotten `SET LOCAL` is a visible,
  self-announcing bug (a user sees an empty list) rather than a silent catastrophic leak. This is a
  deliberate design choice detailed in [../database/ROW_LEVEL_SECURITY.md](../database/ROW_LEVEL_SECURITY.md).
- **A sensitive write that cannot be audited does not happen.** For the always-sensitive operations
  (bank transfer, payroll release, tax submission, permission change), the audit record is written in
  the same database transaction as the mutation. If the audit write fails, the transaction rolls
  back. There is no state in which money moved and no one can prove who moved it.

---

# Compliance

QAYD's compliance posture is "ready for", tracking the foundation document's goals and turning them
into the concrete controls this folder specifies. The point of this section is traceability: an
auditor should be able to walk from a framework requirement to the exact control and the exact
document that implements it.

| Framework | Relevant expectation | Where QAYD's control lives |
|---|---|---|
| SOC 2 (Security, Confidentiality) | Logical access controls, least privilege, change management | [AUTHENTICATION.md](AUTHENTICATION.md), [AUTHORIZATION.md](AUTHORIZATION.md), [SECRETS.md](SECRETS.md) |
| SOC 2 (Processing Integrity) | Two-key approval on financial mutations, immutable audit | [AUTHORIZATION.md](AUTHORIZATION.md) (approval chains), audit log |
| ISO 27001 | Documented ISMS controls, secret management, incident response | This folder + [SECRETS.md](SECRETS.md) incident-response section |
| GDPR / GCC data-protection regimes | Data classification, encryption of PII, right of access/erasure, breach handling | [TENANT_ISOLATION.md](TENANT_ISOLATION.md), [SECRETS.md](SECRETS.md), PII handling in [../api/API_SECURITY.md](../api/API_SECURITY.md) |
| PCI-adjacent posture (payment context) | No card data at rest in QAYD; bank identifiers encrypted; scope minimization | [SECRETS.md](SECRETS.md), [ENCRYPTION.md](ENCRYPTION.md) |
| Kuwait / GCC e-invoicing & tax rules | Tamper-evident records, submission authorization, retention | Audit log + `tax.submit` two-key control in [AUTHORIZATION.md](AUTHORIZATION.md) |

QAYD does not claim certification in this document; it claims that the controls a certification would
audit are specified, enforced server-side, and testable. Data classification (Public / Internal /
Confidential / Restricted) is the spine that connects the frameworks to the code: the class of a
field drives its encryption at rest, its masking in API responses, and its redaction in logs, and
that classification is asserted in each endpoint's OpenAPI schema.

---

# Testing & Verification

The architecture is only real if it is continuously proven. Verification is layered the same way the
controls are, and every layer has an automated gate that fails the build or the deploy when the
control regresses.

**Static gates (CI, block merge):**

- Larastan/PHPStan rule bank: bans `$guarded = []`, bans interpolated `DB::raw`/`whereRaw`, bans
  raw Blade `{!! !!}` outside the one audited utility, flags any Eloquent model without a declared
  tenant scope.
- `gitleaks` secret scan on every push, plus a pre-commit hook — see [SECRETS.md](SECRETS.md).
- OpenAPI diff: any live route not present in the committed spec fails CI (closes OWASP API9,
  improper inventory management).
- Config linting: `APP_DEBUG=false` asserted for production; CORS wildcard-with-credentials rejected.

**Dynamic and behavioral tests (Pest / PHPUnit, must be green):**

- **Cross-tenant negative tests** are mandatory for every tenant-scoped resource: authenticate as
  Company A, attempt to read/update/delete a Company B object by id, assert `404`. This suite is the
  single most important test in the codebase and is expanded in
  [TENANT_ISOLATION.md](TENANT_ISOLATION.md).
- **Authorization negative tests**: for every sensitive endpoint, assert a role lacking the
  permission receives `403`, and assert the initiator of a two-key flow cannot also approve it.
- **RLS tests run against a connection with no GUC set**, asserting zero rows returned — proving the
  last line fails to empty.
- **Auth tests**: expired/revoked token rejected, refresh-reuse detection revokes the family, MFA
  required on financial-approval roles, lockout backoff engages.

**Periodic verification:**

- Quarterly access review of roles and API keys; rotation drills for the JWT keypair and DB
  credentials; a documented tabletop for the leaked-secret runbook in [SECRETS.md](SECRETS.md).
- External penetration testing before major releases, scoped explicitly to the seven trust
  boundaries above, with cross-tenant isolation and financial two-key control as required test cases.

The acceptance bar for this folder as a whole: an engineer can point at any asset in the first table
of the threat model, trace the layers that protect it, name the enforcement point in code for each,
and show the automated test that proves that enforcement point still fires. Where they cannot, the
gap is a defect, not a documentation nicety.

---

# End of Document
