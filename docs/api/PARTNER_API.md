# Partner & Third-Party Integrations — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: PARTNER_API
---

# Purpose

QAYD is an AI Financial Operating System, and the Partner API is the single hardened boundary through
which QAYD's Laravel 12 backend — the sole source of truth for every business and financial fact in the
platform — exchanges data and money-movement instructions with the world outside a company's own users
and outside QAYD's own first-party clients (the Next.js web app, the Flutter mobile app) and its FastAPI
AI engine. Nothing in QAYD talks to an external bank, gateway, government portal, or third-party business
system directly from a controller or an AI agent; every such call is mediated by the Partner API's
connection, credential, scope, and permission machinery described in this document, so that the platform
has exactly one place to reason about partner risk instead of a different bespoke HTTP client per module.

Four categories of external counterpart are in scope:

1. **Banks and Open Banking aggregators.** The institutions and licensed intermediaries the Banking
   module connects to for account information (balances, statement lines) and payment initiation
   (transfers), under regional Open Banking regimes — the Central Bank of Kuwait's Open Banking
   framework, the Saudi Central Bank's SAMA Open Banking Framework, and the UAE's CBUAE Open Finance
   regime — plus conventional bank channels (MT940/CAMT.053 statement feeds, direct SWIFT messaging)
   that predate API-based banking and still dominate volume in several GCC markets.
2. **Payment gateways and card networks.** The processors that QAYD's Sales module's `receipts` rely on
   to actually move customer money: KNET as Kuwait's national payment switch, and regional/international
   gateways such as Tap Payments, PayTabs, MyFatoorah, and Amazon Payment Services, all of which sit in
   front of the Visa/Mastercard card networks and wallet rails (Apple Pay, STC Pay-class wallets).
3. **Government tax and e-invoicing authorities.** The statutory endpoints the Tax module submits to:
   Saudi Arabia's ZATCA (Fatoora e-invoicing — Phase 1 generation and Phase 2 real-time clearance/
   reporting), the UAE's Federal Tax Authority (FTA), and, prospectively, Kuwait's Ministry of Finance /
   Tax Department once Kuwait activates a domestic VAT e-filing channel (the platform's jurisdiction
   model is already provisioned for this — see Tax module, Country Specific Regulations).
4. **External ERP, POS, and CRM systems.** Pre-existing systems of record a company already runs: an
   on-premise ERP mid-migration onto QAYD, a fleet of branch POS terminals (including vertical F&B/retail
   POS such as Foodics), or a CRM such as Salesforce or HubSpot that needs to exchange customers,
   products, orders, inventory, and transactions with QAYD on an ongoing basis rather than a one-time
   import.

This document does not redefine `bank_accounts`, `bank_transactions`, `bank_statement_lines`,
`bank_reconciliations`, `transfers`, `tax_registrations`, `tax_returns`, `tax_transactions`, `receipts`,
`invoices`, `customers`, `vendors`, `products`, or `inventory_items` — those remain owned by Banking, Tax,
Sales, Purchasing, Vendors, Products, and Inventory respectively, and this document never contradicts
their field names, enums, or permission keys. What this document owns is the connection, credential,
scope, sandbox, certification, and monitoring plumbing that those modules' partner-facing endpoints
(`/banking/bank-accounts/{id}/open-banking/*`, `/tax/returns/{id}/file`) sit on top of, plus the new
surface area — payment-gateway charge/refund orchestration and ERP/POS/CRM sync — that no other module
doc yet owns.

Traffic through this layer flows in two directions, and every section below is organized around that
split:

- **Outbound** — QAYD, as an OAuth2 client, calls a partner's API (a bank aggregator, ZATCA, a payment
  gateway) using credentials QAYD holds in trust for the company.
- **Inbound** — a partner calls QAYD: either an external system (an ERP, a POS terminal, a CRM) acting as
  an OAuth2 client-credentials consumer of QAYD's own `/api/v1/partners/*` surface, or a partner
  delivering an asynchronous, signed webhook (a gateway's charge-status callback, a bank's real-time
  transaction push).

Both directions terminate in, or originate from, the same `partner_connections` record, and both pass
through the same scope check and RBAC permission check before touching a single row of tenant data. The
audience for this document is any engineer — human or AI — implementing or consuming this layer; it is
written to be sufficient on its own, without requiring a follow-up question.

# Partner Authentication (OAuth client-credentials / signed requests)

## Partner Data Model

Every partner relationship a company has is represented by exactly two new tables this document
introduces, plus three supporting tables used by later sections. `partner_providers` is a **platform-level
catalog** — it is not tenant data and carries no `company_id`, the same modeling choice the platform
already makes for `account_types` (a system-level classification table, not a business record). Every
other table below is a normal tenant table and carries the full standard-column set.

```sql
CREATE TYPE partner_category AS ENUM (
  'bank', 'open_banking_aggregator', 'payment_gateway', 'card_network',
  'gov_efiling', 'erp', 'pos', 'crm'
);

CREATE TYPE partner_auth_type AS ENUM (
  'oauth2_client_credentials', 'oauth2_authorization_code', 'api_key', 'mtls_signed_request'
);

CREATE TYPE partner_provider_status AS ENUM ('active', 'beta', 'deprecated');

-- Platform-level catalog (no company_id — system-level, like account_types).
CREATE TABLE partner_providers (
  id                      BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code                    VARCHAR(60)  NOT NULL UNIQUE,   -- 'zatca_ksa','fta_uae','knet','tap_payments',
                                                            -- 'paytabs','myfatoorah','salesforce','foodics'
  category                partner_category NOT NULL,
  name_en                 VARCHAR(160) NOT NULL,
  name_ar                 VARCHAR(160) NOT NULL,
  country_scope           JSONB NOT NULL DEFAULT '[]',    -- ISO 3166-1 alpha-2, e.g. ["KW","SA","AE"]
  auth_type               partner_auth_type NOT NULL,
  requires_certification  BOOLEAN NOT NULL DEFAULT TRUE,
  sandbox_base_url        VARCHAR(255) NULL,
  production_base_url     VARCHAR(255) NULL,
  documentation_url       VARCHAR(255) NULL,
  logo_url                VARCHAR(255) NULL,
  status                  partner_provider_status NOT NULL DEFAULT 'active',
  tags                    JSONB NOT NULL DEFAULT '[]',
  custom_fields           JSONB NOT NULL DEFAULT '{}',
  created_by              BIGINT NULL REFERENCES users(id),
  updated_by              BIGINT NULL REFERENCES users(id),
  created_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at              TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at              TIMESTAMPTZ NULL
);
CREATE INDEX idx_partner_providers_category ON partner_providers(category) WHERE deleted_at IS NULL;

CREATE TYPE partner_environment AS ENUM ('sandbox', 'production');
CREATE TYPE partner_connection_status AS ENUM (
  'pending', 'awaiting_consent', 'connected', 'error', 'suspended', 'revoked'
);

-- Tenant table: one row per company-provider-environment relationship.
CREATE TABLE partner_connections (
  id                          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id                  BIGINT NOT NULL REFERENCES companies(id),
  branch_id                   BIGINT NULL REFERENCES branches(id),
  partner_provider_id         BIGINT NOT NULL REFERENCES partner_providers(id),
  display_name                VARCHAR(160) NOT NULL,
  environment                 partner_environment NOT NULL DEFAULT 'sandbox',
  status                      partner_connection_status NOT NULL DEFAULT 'pending',
  scopes                      JSONB NOT NULL DEFAULT '[]',
  client_id                   VARCHAR(255) NULL,
  client_secret_encrypted     TEXT NULL,
  certificate_encrypted       TEXT NULL,   -- mTLS / signed-request partners (e.g. a ZATCA cryptographic stamp)
  oauth_token_encrypted       TEXT NULL,
  refresh_token_encrypted     TEXT NULL,
  token_expires_at            TIMESTAMPTZ NULL,
  webhook_secret_encrypted    TEXT NULL,
  related_bank_account_id     BIGINT NULL REFERENCES bank_accounts(id),
  related_tax_registration_id BIGINT NULL REFERENCES tax_registrations(id),
  certification_id            BIGINT NULL REFERENCES partner_certifications(id), -- see # Certification
  last_synced_at               TIMESTAMPTZ NULL,
  last_health_check_at         TIMESTAMPTZ NULL,
  health_status                 VARCHAR(20) NOT NULL DEFAULT 'unknown'
                                 CHECK (health_status IN ('unknown','healthy','degraded','down')),
  consent_expires_at            TIMESTAMPTZ NULL,
  tags                           JSONB NOT NULL DEFAULT '[]',
  custom_fields                  JSONB NOT NULL DEFAULT '{}',
  created_by                     BIGINT NULL REFERENCES users(id),
  updated_by                     BIGINT NULL REFERENCES users(id),
  created_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                     TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                     TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX uq_partner_connections_company_provider_env
  ON partner_connections(company_id, partner_provider_id, environment) WHERE deleted_at IS NULL;
CREATE INDEX idx_partner_connections_company ON partner_connections(company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_partner_connections_status  ON partner_connections(status)     WHERE deleted_at IS NULL;

-- Inbound event log: every signed webhook/callback a partner delivers to QAYD.
CREATE TYPE partner_inbound_event_status AS ENUM (
  'received', 'verified', 'processed', 'failed', 'ignored_duplicate'
);

CREATE TABLE partner_webhook_events (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NULL REFERENCES companies(id),  -- nullable until resolved from connection
  partner_connection_id  BIGINT NOT NULL REFERENCES partner_connections(id),
  external_event_id      VARCHAR(180) NOT NULL,   -- partner's own event/message id, used for de-duplication
  event_type             VARCHAR(80)  NOT NULL,   -- e.g. 'gateway.charge.succeeded', 'bank.transaction.pushed'
  signature_valid        BOOLEAN NOT NULL DEFAULT FALSE,
  payload                JSONB NOT NULL,
  processing_status      partner_inbound_event_status NOT NULL DEFAULT 'received',
  processed_at           TIMESTAMPTZ NULL,
  error_message          VARCHAR(500) NULL,
  created_by             BIGINT NULL REFERENCES users(id),
  updated_by             BIGINT NULL REFERENCES users(id),
  created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at             TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX uq_partner_webhook_events_dedup
  ON partner_webhook_events(partner_connection_id, external_event_id);
CREATE INDEX idx_partner_webhook_events_status ON partner_webhook_events(processing_status);

-- ERP/POS/CRM field-level sync configuration between a connection and QAYD's canonical schema.
CREATE TYPE partner_mapping_direction AS ENUM ('inbound', 'outbound', 'bidirectional');

CREATE TABLE partner_field_mappings (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  partner_connection_id  BIGINT NOT NULL REFERENCES partner_connections(id),
  direction              partner_mapping_direction NOT NULL DEFAULT 'bidirectional',
  qayd_entity            VARCHAR(60)  NOT NULL,   -- 'customers' | 'products' | 'invoices' | 'vendors' | ...
  qayd_field             VARCHAR(80)  NOT NULL,
  partner_entity         VARCHAR(80)  NOT NULL,
  partner_field           VARCHAR(120) NOT NULL,
  transform_rule           JSONB NULL,             -- value maps, unit conversions, date-format rules
  is_required               BOOLEAN NOT NULL DEFAULT FALSE,
  created_by                BIGINT NULL REFERENCES users(id),
  updated_by                BIGINT NULL REFERENCES users(id),
  created_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at                TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at                TIMESTAMPTZ NULL
);
CREATE INDEX idx_partner_field_mappings_connection ON partner_field_mappings(partner_connection_id);
```

`partner_connections` deliberately does **not** duplicate consent state that another module already owns:
`bank_accounts.open_banking_consent_id` (see Banking module) remains the source of truth for which
consent is active on a given bank account. `partner_connections.related_bank_account_id` links a
bank-category connection to that `bank_accounts` row, and `partner_connections` itself stores only the
underlying OAuth2 client credentials, tokens, and certificate material that QAYD uses to service that
consent — never a second copy of the consent identifier or its status.

Similarly, a payment-gateway connection does not duplicate `receipts` (owned by Sales): a gateway charge
attempt that never resolves to settled money (declined, expired, abandoned mid-3-D-Secure) has no
corresponding `receipts` row, so a distinct table is legitimate rather than duplicative:

```sql
CREATE TYPE gateway_attempt_status AS ENUM (
  'initiated', 'requires_action', 'authorized', 'captured', 'declined', 'failed', 'expired', 'refunded'
);

CREATE TABLE partner_gateway_attempts (
  id                     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id             BIGINT NOT NULL REFERENCES companies(id),
  branch_id              BIGINT NULL REFERENCES branches(id),
  partner_connection_id  BIGINT NOT NULL REFERENCES partner_connections(id),
  invoice_id             BIGINT NULL REFERENCES invoices(id),
  receipt_id             BIGINT NULL REFERENCES receipts(id),   -- populated only once captured/settled
  amount                 NUMERIC(19,4) NOT NULL CHECK (amount > 0),
  currency_code          CHAR(3) NOT NULL,
  status                 gateway_attempt_status NOT NULL DEFAULT 'initiated',
  decline_code           VARCHAR(60)  NULL,
  three_ds_status        VARCHAR(30)  NULL,   -- 'not_required' | 'challenge_issued' | 'authenticated' | 'failed'
  gateway_reference      VARCHAR(120) NULL,
  idempotency_key        VARCHAR(120) NOT NULL,
  raw_gateway_payload     JSONB NULL,
  created_by              BIGINT NULL REFERENCES users(id),
  updated_by              BIGINT NULL REFERENCES users(id),
  created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at               TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX uq_partner_gateway_attempts_idem
  ON partner_gateway_attempts(company_id, idempotency_key);
CREATE INDEX idx_partner_gateway_attempts_connection ON partner_gateway_attempts(partner_connection_id);
```

## Outbound Authentication — QAYD as OAuth2 Client

When QAYD calls out to a bank aggregator, ZATCA, or a payment gateway, the credential that authorizes the
call belongs to the partner, not to QAYD, and is scoped to one `partner_connections` row. Token
acquisition runs through a single internal service, `PartnerTokenBroker`, so no module hand-rolls its own
OAuth2 client:

```
Laravel Job/Service                 PartnerTokenBroker                  Partner's OAuth Server
        |                                    |                                    |
        |--- getToken(connection) --------->|                                    |
        |                                    |--- cached & unexpired? ---.        |
        |                                    |<--- yes: return cached ---'        |
        |                                    |--- no --------------------------->|
        |                                    |   POST /oauth/token                |
        |                                    |   grant_type=client_credentials    |
        |                                    |   client_id, client_secret (or     |
        |                                    |   private_key_jwt / mTLS cert)     |
        |                                    |<-- 200 { access_token, expires_in }|
        |                                    |--- encrypt & cache in              |
        |                                    |    partner_connections            |
        |<--- bearer token ------------------|                                    |
        |--- GET/POST <partner API> with Authorization: Bearer <token> ---------->|
```

`PartnerTokenBroker::getToken()` refreshes 60 seconds before `token_expires_at` to avoid a request racing
an expiry, retries once on a `401` from the partner (treating it as a stale-cache signal, not a fatal
error), and raises a `PartnerConnectionDegraded` domain event — surfaced to Monitoring — after two
consecutive refresh failures rather than failing every subsequent caller individually.

```php
final class PartnerTokenBroker
{
    public function getToken(PartnerConnection $connection): string
    {
        if ($connection->oauth_token_encrypted && $connection->token_expires_at?->subSeconds(60)->isFuture()) {
            return Crypt::decryptString($connection->oauth_token_encrypted);
        }

        $provider = $connection->partnerProvider;

        $response = Http::asForm()->post("{$provider->baseUrl($connection->environment)}/oauth/token", [
            'grant_type'    => 'client_credentials',
            'client_id'     => $connection->client_id,
            'client_secret' => Crypt::decryptString($connection->client_secret_encrypted),
            'scope'         => implode(' ', $connection->scopes),
        ])->throw();

        $connection->update([
            'oauth_token_encrypted' => Crypt::encryptString($response->json('access_token')),
            'token_expires_at'      => now()->addSeconds($response->json('expires_in')),
            'health_status'         => 'healthy',
            'last_health_check_at'  => now(),
        ]);

        return $response->json('access_token');
    }
}
```

For partners requiring mutual TLS or signed JWT assertions instead of a shared secret (ZATCA's onboarding
issues a cryptographic CSID/production certificate rather than a client secret), `partner_connections.
certificate_encrypted` holds the client certificate/private key pair and `PartnerTokenBroker` selects a
`private_key_jwt` or raw-mTLS code path per `partner_providers.auth_type`, never falling back to a
weaker scheme silently.

## Inbound Authentication — External Partners Calling QAYD

An ERP, POS fleet, or CRM that needs to call QAYD's own `/api/v1/partners/*` surface authenticates the
same way any OAuth2 client-credentials consumer would, against QAYD itself:

```
POST /api/v1/partners/oauth/token HTTP/1.1
Host: api.qayd.io
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&client_id=erp_9f2c1a&client_secret=***&scope=erp.sync.read+erp.sync.write
```

```json
{
  "success": true,
  "data": {
    "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "scope": "erp.sync.read erp.sync.write"
  },
  "message": "Token issued",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b1e6a2f0-7e21-4b3d-9a11-8f2c6e0d1a44",
  "timestamp": "2026-07-16T09:12:03Z"
}
```

The issued JWT carries `company_id`, `partner_connection_id`, and `scope` claims; every subsequent request
still requires the standard `X-Company-Id` header, and the middleware chain rejects any request where the
header's company does not match the token's `company_id` claim (`403`, `errors[].code =
"PARTNER_COMPANY_MISMATCH"`) even if the token is otherwise valid — a compromised or misconfigured client
cannot use one company's token to reach another's data by changing a header.

## Signed Webhook Requests

Asynchronous callbacks — a gateway's charge-status update, a bank's real-time transaction push, an ERP's
change notification — arrive at `POST /api/v1/partners/webhooks/receive/{connection_id}` and are verified
before any business logic runs:

| Header | Meaning |
|---|---|
| `X-Qayd-Partner-Signature` | `HMAC-SHA256(webhook_secret, timestamp + "." + raw_body)`, hex-encoded |
| `X-Qayd-Partner-Timestamp` | Unix epoch seconds the partner signed at |
| `X-Qayd-Partner-Event-Id` | The partner's own event id, mirrored into `external_event_id` |

```php
final class VerifyPartnerWebhookSignature
{
    public function handle(Request $request, Closure $next): mixed
    {
        $connection = PartnerConnection::findOrFail($request->route('connection_id'));
        $timestamp  = (int) $request->header('X-Qayd-Partner-Timestamp');

        abort_if(abs(now()->timestamp - $timestamp) > 300, 400, 'PARTNER_TIMESTAMP_SKEW');

        $expected = hash_hmac(
            'sha256',
            $timestamp . '.' . $request->getContent(),
            Crypt::decryptString($connection->webhook_secret_encrypted)
        );

        abort_unless(
            hash_equals($expected, (string) $request->header('X-Qayd-Partner-Signature')),
            401,
            'PARTNER_SIGNATURE_INVALID'
        );

        return $next($request);
    }
}
```

A request older than 300 seconds is rejected outright (replay protection); a valid signature on a
previously-seen `external_event_id` for the same `partner_connection_id` is accepted at the HTTP layer
(so the partner sees a `200` and stops retrying) but recorded with `processing_status =
'ignored_duplicate'` and never re-processed, via the unique index on `partner_webhook_events`.

## Credential Storage and Rotation

Every secret column above (`client_secret_encrypted`, `certificate_encrypted`, `oauth_token_encrypted`,
`refresh_token_encrypted`, `webhook_secret_encrypted`) is stored using Laravel's encrypted casts, backed by
a company-specific data-encryption key derived from the platform's KMS-managed master key — the same
pattern the Tax module uses for government e-filing credentials. No secret is ever included in an API
response body, a webhook payload, an audit log's `new_values`/`old_values` JSONB, or a log line; the audit
log for a credential-touching action records that a rotation occurred, who performed it, and when, but
never the value. Rotation is manual (`POST /api/v1/partners/connections/{id}/rotate-credentials`) or
scheduled (a company can set a rotation cadence per connection, enforced by a queued job that also
notifies `integrations.manage` holders 7 days before a certificate's statutory expiry — mirroring the
Banking module's 7-day Open Banking consent-expiry notice).

## Endpoints

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/partners/providers` | `integrations.read` | List the platform catalog of partner providers, filterable by `category`, `country` |
| GET | `/api/v1/partners/connections` | `integrations.read` | List the company's partner connections |
| POST | `/api/v1/partners/connections` | `integrations.connect` | Create a connection; begins the OAuth/consent handshake |
| GET | `/api/v1/partners/connections/{id}` | `integrations.read` | Fetch one connection (secrets never included) |
| PATCH | `/api/v1/partners/connections/{id}` | `integrations.manage` | Update label, scopes, environment |
| DELETE | `/api/v1/partners/connections/{id}` | `integrations.disconnect` | Revoke and soft-delete a connection |
| POST | `/api/v1/partners/connections/{id}/rotate-credentials` | `integrations.credentials.rotate` | Rotate secret/certificate |
| POST | `/api/v1/partners/oauth/token` | none (client-credentials gated) | Issue a bearer token to an external partner client |
| POST | `/api/v1/partners/webhooks/receive/{connection_id}` | none (signature gated) | Inbound partner webhook receiver |

**Request — create a connection:**

```
POST /api/v1/partners/connections HTTP/1.1
Host: api.qayd.io
Authorization: Bearer <user JWT>
X-Company-Id: 1042
Content-Type: application/json

{
  "partner_provider_code": "tap_payments",
  "display_name": "Tap Payments — Kuwait KWD",
  "environment": "sandbox",
  "scopes": ["gateway.charge.write", "gateway.refund.write"]
}
```

```json
{
  "success": true,
  "data": {
    "id": 3311,
    "company_id": 1042,
    "partner_provider_id": 44,
    "display_name": "Tap Payments — Kuwait KWD",
    "environment": "sandbox",
    "status": "awaiting_consent",
    "scopes": ["gateway.charge.write", "gateway.refund.write"],
    "created_at": "2026-07-16T09:20:11Z"
  },
  "message": "Partner connection created; complete the provider handshake to activate",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "5a2f1c9e-8b30-4e7a-9d1c-3f60ab221d90",
  "timestamp": "2026-07-16T09:20:11Z"
}
```

# Scopes

Scopes are **app-level** grants: they describe what a given `partner_connections` row — the application
credential, whether QAYD-outbound or partner-inbound — is allowed to request at all, independent of which
human is behind any particular call. They are coarser and more stable than the RBAC permissions in the
next section, which govern what a specific user may do through the UI or API on any given day. A
connection's `scopes` JSONB column stores the granted subset of the catalog below; a request or an
outbound call that would need a scope the connection was never granted fails closed with `403` and
`errors[].code = "PARTNER_SCOPE_INSUFFICIENT"`, before the RBAC permission check even runs.

## Scope Catalog

| Scope | Domain | Description | Sensitivity |
|---|---|---|---|
| `bank.accounts.read` | Banks | Read account metadata and balances via Open Banking (AISP) | Normal |
| `bank.transactions.read` | Banks | Read statement/transaction history via Open Banking (AISP) | Normal |
| `bank.transfers.write` | Banks | Initiate a payment via Open Banking (PISP) | Elevated |
| `gateway.charge.write` | Payment gateways | Create/capture charges | Elevated |
| `gateway.refund.write` | Payment gateways | Issue refunds | Elevated |
| `gateway.dispute.read` | Payment gateways | Read chargeback/dispute case data | Normal |
| `gov.efiling.read` | Gov e-filing | Read filing status/acknowledgment payloads | Normal |
| `gov.efiling.submit` | Gov e-filing | Submit a tax return or e-invoice for clearance | Elevated |
| `erp.sync.read` | ERP | Pull products, vendors, inventory snapshots | Normal |
| `erp.sync.write` | ERP | Push/accept updates to products, vendors, inventory | Normal |
| `pos.sync.read` | POS | Pull POS transaction/Z-report data | Normal |
| `pos.sync.write` | POS | Push corrections/voids back to a POS terminal | Normal |
| `crm.contacts.read` | CRM | Pull leads/customers/contacts | Normal |
| `crm.contacts.write` | CRM | Push customer/contact updates into QAYD | Normal |

**Elevated** scopes are never auto-granted at connection-creation time regardless of what the requester
asks for: `POST /api/v1/partners/connections` silently downgrades any elevated scope in the request to a
`pending_approval` state inside `scopes_pending` (an additional JSONB column alongside `scopes`, omitted
from the DDL above for brevity but present on `partner_connections`) until a user holding
`integrations.manage` **and** the scope's owning module permission (`bank.transfer` for
`bank.transfers.write`, `tax.submit` for `gov.efiling.submit`) explicitly approves the elevation via `POST
/api/v1/partners/connections/{id}/scopes/approve`. This double-gate — scope approval at the connection
level, permission check at the call level — means an elevated capability cannot be switched on by a single
compromised or careless action; approving the scope only makes the *capability* available, the RBAC
permission in the next section still gates every individual *call*.

```json
{
  "success": true,
  "data": {
    "connection_id": 3311,
    "scopes": ["gateway.charge.write"],
    "scopes_pending": ["gateway.refund.write"],
    "requires_approval_by": ["integrations.manage", "gateway.refund.create"]
  },
  "message": "gateway.refund.write requires elevation approval before use",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9d6a0f21-4b2e-4a63-9a44-1e6c02f7a5b1",
  "timestamp": "2026-07-16T09:21:44Z"
}
```

Scope enforcement is a dedicated middleware, applied ahead of the normal RBAC gate on every
`/api/v1/partners/*` route and on every domain-module route that accepts partner-originated traffic
(e.g., a payment gateway's inbound webhook resolving into a `receipts` write):

```php
final class PartnerScopeMiddleware
{
    public function handle(Request $request, Closure $next, string $requiredScope): mixed
    {
        $connection = $request->attributes->get('partner_connection');

        abort_unless(
            in_array($requiredScope, $connection->scopes ?? [], true),
            403,
            'PARTNER_SCOPE_INSUFFICIENT'
        );

        return $next($request);
    }
}
```

# Permissions

Where Scopes gate the connection, Permissions gate the human (or AI agent) acting through it. All keys
follow the platform's dotted `<area>.<action>` convention. New areas introduced by this document:
`integrations`, `gateway`, `erp`, `pos`, `crm`. Bank and tax actions reuse the exact keys already defined
by Banking and Tax — they are listed again here only for completeness of the partner-facing picture, never
redefined.

| Permission Key | Description |
|---|---|
| `integrations.read` | View partner providers, connections, health, and mappings |
| `integrations.connect` | Create a new partner connection |
| `integrations.manage` | Edit a connection's label/scopes/environment; approve scope elevation |
| `integrations.disconnect` | Revoke and remove a partner connection |
| `integrations.credentials.rotate` | Rotate a connection's secret or certificate |
| `integrations.certify.submit` | Submit a connection for certification (see Certification) |
| `integrations.certify.approve` | Approve a certification, unlocking production credentials |
| `integrations.mapping.manage` | Create/edit ERP/POS/CRM field mappings |
| `integrations.sandbox.reset` | Reset sandbox fixtures for a connection |
| `integrations.webhook.simulate` | Fire a simulated inbound webhook (sandbox only) |
| `gateway.charge.create` | Create a gateway charge against an invoice/receipt |
| `gateway.charge.capture` | Capture a previously authorized charge |
| `gateway.charge.void` | Void an uncaptured authorization |
| `gateway.refund.create` | Issue a refund against a captured charge |
| `gateway.dispute.respond` | Submit evidence against a chargeback/dispute |
| `erp.sync.read` / `erp.sync.write` | Read / write ERP sync data |
| `pos.sync.read` / `pos.sync.write` | Read / write POS sync data |
| `crm.sync.read` / `crm.sync.write` | Read / write CRM sync data |
| `bank.account.manage` *(Banking)* | Manage Open Banking connections/sync |
| `bank.transfer` *(Banking)* | Create a transfer — reused when a PISP payment-initiation completes |
| `tax.filing.prepare` / `tax.filing.approve` / `tax.submit` *(Tax)* | Government e-filing lifecycle |

**Role assignment table:**

| Role | integrations.read | integrations.connect/manage | integrations.disconnect | credentials.rotate | certify.submit / .approve | gateway.charge.* | gateway.refund.* | erp/pos/crm.sync.* | mapping.manage |
|---|---|---|---|---|---|---|---|---|---|
| Owner | Yes | Yes | Yes | Yes | Yes / Yes | Yes | Yes | Yes | Yes |
| CEO | Yes | Yes | Yes | Yes | Yes / Yes | Yes | Yes | Yes | Yes |
| CFO | Yes | Yes | Yes | Yes | Yes / Yes | Yes | Yes | Yes | Yes |
| Finance Manager | Yes | Yes | No | Yes | Yes / No | Yes | Yes (initiates only) | Yes | Yes |
| Senior Accountant | Yes | No | No | No | No / No | Yes (create/capture) | No | Yes (read) | No |
| Accountant | Yes | No | No | No | No / No | Yes (create only) | No | Yes (read) | No |
| Sales Manager | Yes | No | No | No | No / No | Yes | No | Yes (crm) | No |
| Sales Employee | No | No | No | No | No / No | Yes (create only) | No | No | No |
| Inventory Manager | Yes | No | No | No | No / No | No | No | Yes (erp/pos) | No |
| Auditor / External Auditor | Yes | No | No | No | No / No | No | No | Yes (read) | No |
| Read Only | Yes | No | No | No | No / No | No | No | Yes (read) | No |
| AI Agent | Yes (scoped read) | No | No | No | No / No | Suggest-only (drafts a `partner_gateway_attempts` proposal a human submits) | **No** | Suggest-only sync proposals | No |

Consistent with the platform rule that sensitive operations always require a human approval chain, the AI
Agent principal type is structurally ineligible for `gateway.refund.create`, `integrations.disconnect`,
`integrations.credentials.rotate`, `integrations.certify.approve`, and `bank.transfer` regardless of any
permission grant an administrator might mistakenly assign — the same defense-in-depth pattern Banking
applies to `bank.payment.approve.final`: the check is on the authenticated principal's *type*, not solely
its permission set, so a misconfiguration cannot silently hand an AI agent an irreversible financial
capability.

# Sandbox

Every `partner_providers` row exposes both a `sandbox_base_url` and a `production_base_url`; every
`partner_connections` row is created in `environment = 'sandbox'` by default and must be explicitly
promoted (`PATCH /api/v1/partners/connections/{id}` with `"environment": "production"`), which itself
requires `integrations.manage` plus a passed certification (see Certification) — QAYD refuses to promote
an uncertified connection with `422` and `errors[].code = "PARTNER_CERTIFICATION_REQUIRED"`.

QAYD's own sandbox environment is reachable at `https://sandbox-api.qayd.io/api/v1/`, is provisioned with
an auto-seeded demo company on first use, and resets nightly; a company can also trigger an on-demand
reset of just its own partner fixtures via `POST /api/v1/partners/sandbox/reset`
(`integrations.sandbox.reset`) without affecting its live accounting data — sandbox partner fixtures are
namespaced under a dedicated `is_sandbox_fixture = true` flag on the rows they generate (test
`bank_transactions`, test `receipts`) so a reset can delete exactly those rows and nothing a real user
entered.

## Per-Domain Sandbox Behavior

| Domain | What the sandbox simulates | Notable fixtures |
|---|---|---|
| Banks / Open Banking | A mock aggregator returning deterministic balances and a rolling 90 days of statement lines; consent screens are a QAYD-hosted stub, not the real bank's UI | Test IBAN `KW00SANDBOX00000000000001`; a "flaky" test account that returns `503` on 1-in-5 calls to exercise retry logic |
| Payment gateways | KNET, Visa, and Mastercard test card numbers behind Tap Payments/PayTabs/MyFatoorah sandbox endpoints; deterministic 3-D Secure challenge/no-challenge outcomes keyed by card suffix | `4111 1111 1111 1111` (always authorizes), `4000 0000 0000 0002` (always declines, `decline_code = insufficient_funds`), `4000 0000 0000 3220` (forces a 3DS challenge) |
| Gov e-filing | Simulated ZATCA/FTA clearance responses, including a deliberately-broken hash-chain scenario and an expired-certificate scenario | `government_reference_number` prefixed `SANDBOX-ZATCA-…` / `SANDBOX-FTA-…`, never a real authority reference format |
| ERP / POS / CRM | A seeded mock dataset (200 products, 50 vendors, 1,000 customers) reachable through the same mapping engine as production, plus one intentionally malformed record per entity to exercise mapping-error handling | Mock CRM contact `crm_test_0001` with an intentionally invalid email to test validation rejection |

Sandbox responses use the identical standard envelope as production; the only observable difference is
that `data.object` values are drawn from fixtures and every persisted row carrying partner-sandbox origin
sets `meta.sandbox = true` inside the envelope's `meta` object so a client can visually flag sandbox data
in a shared UI without QAYD needing a second response shape.

```
GET /api/v1/partners/sandbox/reset HTTP/1.1
Host: sandbox-api.qayd.io
Authorization: Bearer <user JWT>
X-Company-Id: 1042
```

```json
{
  "success": true,
  "data": { "reset_connections": 3, "reset_fixture_rows": 812 },
  "message": "Sandbox fixtures reset",
  "errors": [],
  "meta": { "pagination": null, "sandbox": true },
  "request_id": "1f9c7e2a-6b1d-4a90-8b7e-4c9a2e1f6d30",
  "timestamp": "2026-07-16T09:25:00Z"
}
```

# Testing

## Tooling

QAYD publishes an OpenAPI 3.1 document for the entire `/api/v1/partners/*` surface (see the platform-wide
OpenAPI/Swagger contract convention) plus a matching Postman collection, both regenerated on every merge
to the API layer so they can never drift from the implementation. Backend contract tests run under
Pest/PHPUnit against the sandbox environment in CI; frontend integration tests (Vitest for unit-level
mapping logic, Playwright for the Connections UI) run against a dockerized sandbox stub.

## Test Scenario Matrix

| Domain | Required scenarios (non-exhaustive; full list lives in the certification test suite) |
|---|---|
| Banks / Open Banking | Consent grant success; consent denied by end user; consent expiry mid-session; statement sync with 0 new lines; statement sync with 10,000+ lines (pagination); aggregator 5xx with retry/backoff; PISP transfer success; PISP transfer rejected by bank |
| Payment gateways | Authorization + immediate capture; authorize now, capture later; decline (insufficient funds, stolen card, expired card); 3DS challenge completed; 3DS challenge abandoned; partial refund; full refund; duplicate charge with same idempotency key |
| Gov e-filing | Clean submission accepted; schema validation rejection; hash-chain break rejection; certificate expired mid-submission; authority sandbox timeout; partial acceptance (simplified/B2C batch) |
| ERP / POS / CRM | Full initial sync; incremental delta sync via `updated_at` watermark; conflicting concurrent edits (QAYD and partner both changed the same field); malformed record (mapping-error path); large batch (10,000+ records) |

## Idempotency and Retry Testing

Every partner-facing write endpoint accepts an `Idempotency-Key` header (required on
`/api/v1/partners/gateway/charges`, `/api/v1/partners/gateway/refunds`, and
`/api/v1/partners/*/sync` write endpoints). Replaying the same key with an identical body returns the
original `201`/`200` response verbatim (no second side effect); replaying the same key with a **different**
body is rejected with `409` and `errors[].code = "IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD"`. The
certification suite requires an explicit test proving both behaviors, plus a chaos scenario injecting
artificial latency (2–8 seconds) and a mid-flight connection drop to confirm the client-side retry does
not double-charge.

## Webhook Simulation

`POST /api/v1/partners/webhooks/simulate` (`integrations.webhook.simulate`, sandbox environment only)
lets an integrator fire any cataloged inbound event against their own connection without waiting for the
real partner:

```
POST /api/v1/partners/webhooks/simulate HTTP/1.1
Host: sandbox-api.qayd.io
Authorization: Bearer <user JWT>
X-Company-Id: 1042
Content-Type: application/json

{
  "connection_id": 3311,
  "event_type": "gateway.charge.succeeded",
  "external_event_id": "sim-evt-00981",
  "payload": { "gateway_reference": "TAP-SANDBOX-88213", "amount": "45.5000", "currency_code": "KWD" }
}
```

```json
{
  "success": true,
  "data": { "partner_webhook_event_id": 55210, "processing_status": "processed" },
  "message": "Simulated webhook accepted and processed",
  "errors": [],
  "meta": { "pagination": null, "sandbox": true },
  "request_id": "2b7e9a10-4f3d-4a12-8c60-9e1f7d2a5b30",
  "timestamp": "2026-07-16T09:27:41Z"
}
```

# Certification

A connection may transact freely in sandbox the moment it is created, but promotion to
`environment = 'production'` — and therefore any real money movement, real statutory filing, or real
customer data sync — is gated behind certification. This is the formal record that a connection has been
technically reviewed and has passed the required test scenarios, tracked in its own table:

```sql
CREATE TYPE partner_certification_level AS ENUM (
  'basic', 'standard', 'certified_financial_partner'
);
CREATE TYPE partner_certification_status AS ENUM (
  'not_started', 'in_progress', 'passed', 'failed', 'expired'
);

CREATE TABLE partner_certifications (
  id                       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  company_id               BIGINT NOT NULL REFERENCES companies(id),
  partner_provider_id      BIGINT NOT NULL REFERENCES partner_providers(id),
  certification_level      partner_certification_level NOT NULL DEFAULT 'basic',
  test_suite_version       VARCHAR(20) NOT NULL,
  scenarios_required       INT NOT NULL,
  scenarios_passed         INT NOT NULL DEFAULT 0,
  status                   partner_certification_status NOT NULL DEFAULT 'not_started',
  submitted_by             BIGINT NULL REFERENCES users(id),
  submitted_at             TIMESTAMPTZ NULL,
  reviewed_by              BIGINT NULL REFERENCES users(id),
  approved_by              BIGINT NULL REFERENCES users(id),
  approved_at              TIMESTAMPTZ NULL,
  expires_at               TIMESTAMPTZ NULL,
  evidence_attachment_id   BIGINT NULL REFERENCES attachments(id),
  rejection_reason         VARCHAR(500) NULL,
  created_by               BIGINT NULL REFERENCES users(id),
  updated_by               BIGINT NULL REFERENCES users(id),
  created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at               TIMESTAMPTZ NULL
);
CREATE INDEX idx_partner_certifications_company ON partner_certifications(company_id);
```

## Certification Levels

| Level | Unlocks | Requirement |
|---|---|---|
| Basic | Read-only scopes only (`*.read`) | Automated sandbox test suite passes (no human review) |
| Standard | Read + non-elevated writes (`erp.sync.write`, `pos.sync.write`, `crm.contacts.write`, `gateway.charge.create` up to a per-transaction cap) | Automated suite passes + a QAYD integration engineer reviews the connection's mapping configuration |
| Certified Financial Partner | Elevated scopes (`bank.transfers.write`, `gateway.refund.write`, `gov.efiling.submit`) with no per-transaction cap | Full scenario matrix passes (see Testing) + a signed security questionnaire + UAT sign-off by a QAYD integration engineer + a go-live checklist reviewed by the company's Owner or CFO |

## Workflow

```
draft connection (sandbox)
   |
   v
integrations.certify.submit ---> partner_certifications.status = 'in_progress'
   |                                   |
   |                          automated scenario matrix runs nightly
   |                                   |
   |                        scenarios_passed == scenarios_required?
   |                             /                        \
   |                          yes                          no
   |                           |                             |
   |                           v                             v
   |                 status = 'passed'              status = 'failed'
   |                 (Standard/Basic auto-approve;   (rejection_reason populated;
   |                  Certified Financial Partner     integrator notified; may
   |                  requires integrations.certify   resubmit after fixes)
   |                  .approve from a human)
   v
integrations.manage promotes connection to environment = 'production'
```

Certification is re-required, not merely renewed by default, whenever: the connection's requested scope
set changes to include a new elevated scope; the partner's own API issues a breaking version change (see
Support → versioning); or `expires_at` is reached — the default validity is 12 months for Certified
Financial Partner and 24 months for Standard/Basic, after which `status` flips to `expired` and
production calls on that connection begin failing closed with `403` /
`errors[].code = "PARTNER_CERTIFICATION_EXPIRED"` rather than silently continuing on a stale review.

## Endpoints

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/partners/certifications` | `integrations.read` | List certifications for the company |
| POST | `/api/v1/partners/certifications` | `integrations.certify.submit` | Start a certification for a connection |
| POST | `/api/v1/partners/certifications/{id}/submit` | `integrations.certify.submit` | Submit evidence/results for review |
| POST | `/api/v1/partners/certifications/{id}/approve` | `integrations.certify.approve` | Approve a Certified Financial Partner level (human approval chain) |
| POST | `/api/v1/partners/certifications/{id}/reject` | `integrations.certify.approve` | Reject with a required `rejection_reason` |

# Monitoring

## Health Telemetry

Every `partner_connections` row exposes `GET /api/v1/partners/connections/{id}/health`
(`integrations.read`), backed by a scheduled job that pings each active connection's lightweight
health-check endpoint (or, for partners without one, infers health from the success rate of the last N
real calls) every 5 minutes and writes `health_status` (`healthy` / `degraded` / `down`) and
`last_health_check_at`.

```json
{
  "success": true,
  "data": {
    "connection_id": 3311,
    "health_status": "degraded",
    "last_health_check_at": "2026-07-16T09:30:00Z",
    "rolling_24h_success_rate": 0.94,
    "rolling_24h_p95_latency_ms": 1120,
    "open_incidents": 1
  },
  "message": "Connection health",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "77c9a1e0-2b4f-4d3a-9c1e-5a7f0b3d2c11",
  "timestamp": "2026-07-16T09:30:05Z"
}
```

## Alerting and Circuit-Breaking

Two consecutive failed health checks, or a rolling 5-minute error rate above 20% on real traffic, opens a
circuit breaker on the connection: new outbound calls fail fast locally (`503`,
`errors[].code = "PARTNER_CIRCUIT_OPEN"`) instead of piling up against an unhealthy partner, and
`integrations.manage` holders for the affected company plus QAYD's own on-call are notified. The breaker
half-opens after a backoff window (starting at 30 seconds, doubling to a 30-minute ceiling) to probe
recovery with a single test call before fully closing again. This is the same posture Banking already
applies to a single flaky bank feed, generalized to every partner category so one misbehaving payment
gateway cannot exhaust worker capacity that healthy connections need.

## Rate Limiting

Redis backs per-connection and per-category rate-limit buckets, tiered by certification level:

| Certification level | Outbound calls/minute (per connection) | Inbound webhook deliveries/minute (per connection) |
|---|---|---|
| Basic | 60 | 60 |
| Standard | 300 | 300 |
| Certified Financial Partner | 1,200 | 1,200 |

A connection that exceeds its tier is throttled, not dropped: excess requests receive `429` with a
`Retry-After` header, and sustained breaches (more than 10 throttled minutes in an hour) raise a
Monitoring alert rather than a silent, permanent block, since a legitimate traffic-pattern change (a new
POS terminal fleet coming online) is a more common cause than abuse.

## Reconciliation Drift Detection

For bank and payment-gateway connections specifically, a nightly job compares QAYD's own ledger view
(`bank_transactions` for banks; `receipts`/`partner_gateway_attempts` for gateways) against the partner's
own statement/settlement report for the same period and raises a `bank.reconciliation.discrepancy_detected`
- or a new, analogous `gateway.settlement.discrepancy_detected` - notification (delivered to
`integrations.manage` and `bank.reconcile` holders respectively) whenever the two disagree by more than a
configurable tolerance (default 0.0000, i.e. any discrepancy at all, since these are financial figures).
This is in addition to, not a replacement for, the Banking module's own bank-reconciliation workbench —
Monitoring's version is an automated early-warning check, the workbench is where a human actually resolves
the discrepancy.

## Audit Logs

Every mutation on `partner_connections`, `partner_certifications`, and `partner_field_mappings` writes to
the foundation `audit_logs` table with the standard shape (`actor_type` `user`|`ai_agent`|`system`,
`action`, `old_values`, `new_values`, `reason`, `ip_address`, `device_info`) — with secret-bearing columns
always redacted to `"[redacted]"` in `old_values`/`new_values` rather than omitted, so the audit trail
still shows *that* a secret changed and *when*, without ever persisting the secret itself a second time
outside its encrypted column.

# Support

## Tiers and SLAs

| Tier | Definition | Example | First response | Resolution target |
|---|---|---|---|---|
| P1 | Production money-movement or filing is down for one or more companies | Payment gateway connection circuit-broken platform-wide; ZATCA clearance calls failing for all KSA companies | 15 minutes | 4 hours |
| P2 | A single company's production connection is degraded or a non-critical feature is broken | One company's bank feed sync delayed; ERP mapping error blocking one company's sync | 1 hour | 1 business day |
| P3 | Sandbox/certification issue, documentation gap, or a feature request | Sandbox test card not behaving as documented | 1 business day | Best-effort |

## Channels

The integration support portal (`/api/v1/partners/support/tickets`, gated by `integrations.read` for
viewing and open to any authenticated company user for creating a ticket) is the primary channel; P1
incidents additionally page QAYD's on-call directly and are mirrored to a public status page
(`status.qayd.io`) showing per-partner-category uptime, independent of any single company's view, so a
company can distinguish "my connection is broken" from "this partner is down for everyone."

## Dispute Handling

Payment-gateway chargebacks and disputes surface as a `partner_webhook_events` row with
`event_type = 'gateway.dispute.created'`, creating a case visible via
`GET /api/v1/partners/gateway/disputes` (`gateway.dispute.respond` to act on it). A company has the
gateway's own response window (typically 7–21 days depending on card network and reason code) to submit
evidence through `POST /api/v1/partners/gateway/disputes/{id}/respond`; QAYD tracks the deadline and
escalates via notification at the halfway point and again 24 hours before expiry, since a missed dispute
window is an automatic loss regardless of the merits.

## Change Management and Versioning

A partner integration adapter (e.g., `ZatcaFilingAdapter`, a gateway's SDK wrapper) is versioned
independently of QAYD's own `/api/v1/v1` surface. When an upstream partner announces a breaking change,
QAYD: (1) stands up the new adapter version behind a feature flag in sandbox immediately; (2) notifies
every affected company's `integrations.manage` holders with the partner's own deprecation timeline; (3)
requires a fresh certification pass on the new adapter version before flipping any production connection
over; (4) never flips a company over automatically before its own explicit opt-in or the upstream
partner's hard deprecation date, whichever is sooner. QAYD's own Partner API endpoints follow the
platform-wide `/api/v1/` versioning policy — no breaking change is introduced without a new version
segment, and a deprecated version is supported for a minimum 12-month sunset window with quarterly
reminder notifications to any connection still calling it.

# Open Banking & Government E-Filing

These two domains carry the platform's highest regulatory exposure — a bad Open Banking payment
initiation moves real customer money, and a bad e-invoice submission is a statutory compliance failure —
so this section treats them at greater depth than the generic sections above.

## Open Banking

QAYD's Open Banking integration follows an AISP/PISP split even though no single GCC regulator currently
uses exactly that EU/PSD2 terminology: **Account Information** (read-only: balances, transaction history)
and **Payment Initiation** (write: triggering a transfer) are modeled, scoped, and permissioned as
distinct capabilities, never bundled into one blanket "bank access" grant. This is deliberate: a company's
bookkeeper can reasonably need `bank.accounts.read` and `bank.transactions.read` for reconciliation
without ever needing `bank.transfers.write`, and QAYD's scope model makes that distinction enforceable
rather than aspirational.

**Regulatory frameworks recognized:**

| Jurisdiction | Framework | AISP consent validity | PISP supported |
|---|---|---|---|
| Kuwait | Central Bank of Kuwait (CBK) Open Banking framework | Per-bank, commonly 90 days | Where the bank's own API exposes it; otherwise QAYD falls back to a `transfers` row requiring manual bank-portal execution with the approval chain still enforced in QAYD |
| Saudi Arabia | SAMA Open Banking Framework | 90 days (SAMA-mandated re-consent cadence) | Yes, via SAMA-licensed aggregators |
| UAE | CBUAE Open Finance | 90–180 days depending on licensed provider | Yes, via CBUAE-licensed aggregators |

QAYD never stores a customer's actual online-banking username/password for any of these frameworks; every
connection is OAuth2-based, consented explicitly through the bank or aggregator's own hosted consent
screen (QAYD only stores the resulting `open_banking_consent_id`, on `bank_accounts` per the Banking
module, plus the OAuth token material in `partner_connections`). Where a bank has no Open Banking API at
all, the same `bank_accounts` row simply falls back to MT940/CAMT.053/CSV statement import — a difference
in ingestion channel, not in the accounting treatment downstream.

**Consent + sync sequence:**

```
Company user           QAYD (Laravel)          Aggregator/Bank            Bank's consent UI
     |                       |                        |                          |
     |-- POST .../open-banking/connect ------->|                          |
     |                       |-- create partner_connections (awaiting_consent) --|
     |                       |-- redirect_url ------->|                          |
     |<---------------------------------------- redirect_url -------------------|
     |-- (browser) follow redirect to bank's consent UI ------------------------>|
     |                       |                        |<-- user approves consent-|
     |                       |<-- callback: consent_id, auth_code --------------|
     |                       |-- exchange auth_code for tokens ----->|          |
     |                       |<-- access_token, refresh_token -------|          |
     |                       |-- store tokens (encrypted) in partner_connections |
     |                       |-- set bank_accounts.open_banking_consent_id       |
     |                       |-- status = 'connected'                            |
     |<-- 200 connected ------|                                                   |
     |                       |-- (scheduled) sync job pulls statement lines ----->|
     |                       |<-- statement lines --------------------------------|
     |                       |-- insert bank_statement_lines; fire bank.synced -->|
```

**Payment initiation (PISP)** reuses the Banking module's existing `transfers` table and its
`approval_chain_status` state machine unchanged — a PISP-initiated transfer is not a different kind of
transfer, only a different `transfer_type` origin. A PISP transfer still requires
`finance_manager_approved_by` then, above a company-configured threshold, `ceo_approved_by` before
`PartnerTokenBroker`-backed code ever calls the aggregator's payment-initiation endpoint; QAYD will not
call an aggregator's PISP endpoint for a `transfers` row still in `pending_finance_manager`, full stop.

## Government E-Filing

Government e-filing sits behind the Tax module's own `POST /api/v1/tax/returns/{id}/file`, which this
layer services via provider-specific adapters selected from `partner_connections` by
`related_tax_registration_id` and `partner_provider_id`:

| Adapter | Provider | Behavior |
|---|---|---|
| `ZatcaFilingAdapter` | ZATCA (Saudi Arabia) | Phase 1: generates structured XML/PDF-A3 with embedded QR per invoice, no network call. Phase 2: real-time clearance call at invoice-posting time (blocking) for standard/B2B invoices; asynchronous reporting within 24 hours for simplified/B2C invoices |
| `FtaFilingAdapter` | Federal Tax Authority (UAE) | Structured return submission via FTA's e-Services channel; asynchronous acknowledgment |
| `ManualFilingAdapter` | Any jurisdiction without an API (including Kuwait today) | Produces a downloadable XML/PDF package for manual upload to the authority's portal; no partner connection required |

Every adapter, regardless of provider, normalizes its response into the same `government_ack_payload`
JSONB shape already established by the Tax module, so `tax_returns.status` transitions identically
(`under_review` -> `filed`, or back to `under_review` with a rejection reason attached) no matter which
government system was actually called. This document's contribution is everything *before* that
normalization point: the `partner_connections` row holding ZATCA's cryptographic stamp / CSID or the
FTA's API credentials (encrypted, never returned in any response — see Authentication), the sandbox
simulation of each authority's clearance/rejection behavior (see Sandbox), and the certification gate
that must pass — including a deliberately-broken hash-chain test case for ZATCA Phase 2 — before a
`gov.efiling.submit` scope is ever elevated to production for a given company (see Certification, Scopes).

**Clearance sequence (ZATCA Phase 2, standard invoice):**

```
Sales module              Partner API (ZatcaFilingAdapter)         ZATCA
     |                              |                                 |
     |-- invoice reaching 'posted' -|                                 |
     |                              |-- build UBL XML + hash of       |
     |                              |   previous_invoice_hash          |
     |                              |-- sign with company's CSID ----->|
     |                              |   POST /invoices/clearance/single|
     |                              |<-- 200 cleared + QAYD stamp ------|
     |                              |   OR 4xx rejected (schema/hash)  |
     |                              |-- normalize to government_ack_payload
     |<-- invoice finalization unblocked (cleared) or blocked (rejected)|
```

A ZATCA Phase 2 rejection is a **blocking** validation error on the invoice itself — per the mandate, an
uncleared invoice cannot legally reach the customer — never a background warning a user could miss; this
mirrors exactly how the Tax module already treats it, and the Partner API layer's only job is to make sure
that blocking call is fast, monitored (see Monitoring's circuit breaker — a down ZATCA endpoint must not
silently freeze every KSA invoice without an operator being alerted within minutes), and never silently
retried in a way that could produce a duplicate cleared invoice for the same document.

## Edge Cases Specific to This Section

- **Consent revoked mid-sync.** A customer revokes Open Banking consent directly in their bank's app,
  outside QAYD entirely. The next sync call fails with the aggregator's `401`; QAYD does not treat this as
  a transient error to retry — it immediately sets `partner_connections.status = 'revoked'` and
  `bank_accounts` falls back to manual statement import, with a notification to `bank.account.manage`
  holders rather than a silent, repeating sync failure.
- **PISP transfer partially acknowledged.** An aggregator returns a `202 Accepted` for a payment
  initiation but the underlying bank rail settles asynchronously over 1–2 business days. The
  `transfers` row stays in a `submitted` sub-state (not `completed`) until a webhook or a polling
  reconciliation confirms settlement, so QAYD never marks money "sent" before it actually clears the
  bank.
- **ZATCA certificate expires between invoices.** A company's CSID expires; the next Phase 2 invoice's
  clearance call fails authentication. This is treated identically to a certification expiry
  (`PARTNER_CERTIFICATION_EXPIRED`-class failure): invoice finalization blocks with a clear reason rather
  than silently falling back to an uncleared invoice, and `integrations.manage`/`tax.filing.approve`
  holders are notified to renew the certificate through ZATCA's own onboarding portal.
- **Kuwait activates VAT e-filing mid-contract.** Because `ManualFilingAdapter` already handles Kuwait
  today, activating a future Kuwait e-filing API only requires adding a `KwtaxFilingAdapter`-class
  provider row to `partner_providers` and running the standard certification flow per company — no schema
  change to `tax_returns`, `partner_connections`, or any other already-shipped table.

# Examples

The following are complete, non-abbreviated request/response pairs, one per partner domain, each using
real QAYD resources, the standard envelope, and canonical permission keys.

## 1. Bank — Complete an Open Banking Connection and First Sync

```
POST /api/v1/banking/bank-accounts/501/open-banking/connect HTTP/1.1
Host: api.qayd.io
Authorization: Bearer <user JWT>
X-Company-Id: 1042
Content-Type: application/json

{ "partner_provider_code": "cbk_open_banking_nbk", "scopes": ["bank.accounts.read", "bank.transactions.read"] }
```

```json
{
  "success": true,
  "data": {
    "partner_connection_id": 3402,
    "bank_account_id": 501,
    "status": "awaiting_consent",
    "consent_redirect_url": "https://consent.nbk.com.kw/authorize?request_id=98a1c2..."
  },
  "message": "Follow consent_redirect_url to complete the bank's consent flow",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "e4a1f2b0-6c3d-4a91-8e2f-1a9c5d3e7b60",
  "timestamp": "2026-07-16T10:02:14Z"
}
```

After the user completes the bank's own consent screen and QAYD's callback exchanges the resulting code
for tokens:

```
POST /api/v1/banking/bank-accounts/501/open-banking/sync HTTP/1.1
Host: api.qayd.io
Authorization: Bearer <user JWT>
X-Company-Id: 1042
```

```json
{
  "success": true,
  "data": {
    "bank_account_id": 501,
    "partner_connection_id": 3402,
    "statement_lines_imported": 214,
    "skipped_duplicate_count": 0,
    "period_start": "2026-04-18",
    "period_end": "2026-07-16"
  },
  "message": "Open Banking sync completed",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9b2c7e10-3a4f-4d2b-9c1a-6e0f2d5b8a41",
  "timestamp": "2026-07-16T10:05:52Z"
}
```

This triggers the platform's `bank.synced` webhook to every subscribed endpoint, carrying the
`bank_account_id` and imported line count as its `data.object`.

## 2. Payment Gateway — Charge With a 3-D Secure Challenge

```
POST /api/v1/partners/gateway/charges HTTP/1.1
Host: api.qayd.io
Authorization: Bearer <user JWT>
X-Company-Id: 1042
Idempotency-Key: chg_20260716_inv88213_v1
Content-Type: application/json

{
  "partner_connection_id": 3311,
  "invoice_id": 88213,
  "amount": "45.5000",
  "currency_code": "KWD",
  "payment_method_token": "tok_sandbox_visa_3ds"
}
```

```json
{
  "success": true,
  "data": {
    "id": 71029,
    "status": "requires_action",
    "three_ds_status": "challenge_issued",
    "action_url": "https://secure.tappayments.com/3ds/challenge/8f21ac",
    "amount": "45.5000",
    "currency_code": "KWD",
    "invoice_id": 88213,
    "gateway_reference": "TAP-SANDBOX-88213"
  },
  "message": "3-D Secure challenge required to complete this charge",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "3c8f1a20-5b4d-4e91-8a2c-7f0e1d6b9c52",
  "timestamp": "2026-07-16T10:08:30Z"
}
```

Once the customer completes the challenge, the gateway delivers a signed webhook that QAYD verifies
(see Authentication) and converts into a settled receipt:

```json
{
  "event_type": "gateway.charge.succeeded",
  "external_event_id": "tap_evt_5f2a1c",
  "data": {
    "gateway_reference": "TAP-SANDBOX-88213",
    "three_ds_status": "authenticated",
    "amount": "45.5000",
    "currency_code": "KWD"
  }
}
```

```json
{
  "success": true,
  "data": {
    "partner_gateway_attempt_id": 71029,
    "status": "captured",
    "receipt_id": 60041,
    "invoice_id": 88213
  },
  "message": "Charge captured; receipt 60041 created and allocated to invoice 88213",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "8a4f2c10-7d3e-4b91-9c5a-2e1f0d6b8a73",
  "timestamp": "2026-07-16T10:11:05Z"
}
```

This also fires the platform's `payment.received` webhook with `receipts` as its resource, identical to a
manually-recorded cash receipt — downstream consumers never need to know a payment gateway was involved.

## 3. Government E-Filing — ZATCA Phase 2 Clearance

```
POST /api/v1/tax/returns/9021/file HTTP/1.1
Host: api.qayd.io
Authorization: Bearer <user JWT>
X-Company-Id: 2208
Content-Type: application/json

{ "partner_connection_id": 4110, "filing_note": "Q2 2026 standard VAT return, KSA registration" }
```

```json
{
  "success": true,
  "data": {
    "tax_return_id": 9021,
    "status": "filed",
    "government_reference_number": "ZATCA-VAT-2026-06-SA-000481223",
    "government_ack_payload": {
      "adapter": "ZatcaFilingAdapter",
      "clearance_status": "cleared",
      "previous_invoice_hash_chain_verified": true,
      "cleared_at": "2026-07-16T10:15:02Z"
    }
  },
  "message": "Return filed and cleared by ZATCA",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "5d2a9c10-4b3e-4f91-8c6a-1e0f7d2b9a34",
  "timestamp": "2026-07-16T10:15:03Z"
}
```

A rejected submission returns the same shape with `status: "under_review"` and a populated `errors[]`:

```json
{
  "success": false,
  "data": { "tax_return_id": 9021, "status": "under_review" },
  "message": "ZATCA rejected the submission",
  "errors": [
    { "code": "EFILING_HASH_CHAIN_BROKEN", "detail": "previous_invoice_hash does not match ZATCA's last accepted invoice" }
  ],
  "meta": { "pagination": null },
  "request_id": "6e3b0d21-5c4f-4a02-9d7b-2f1e8e3c0a45",
  "timestamp": "2026-07-16T10:15:03Z"
}
```

## 4. ERP — Incremental Product Sync

```
GET /api/v1/partners/erp/products?updated_since=2026-07-15T00:00:00Z&per_page=25 HTTP/1.1
Host: api.qayd.io
Authorization: Bearer <partner client-credentials JWT>
X-Company-Id: 1042
```

```json
{
  "success": true,
  "data": [
    {
      "id": 33812,
      "sku": "SKU-4471",
      "name_en": "A4 Copy Paper (5-Ream Box)",
      "name_ar": "ورق تصوير A4 (5 رزم)",
      "unit_of_measure": "box",
      "price": "3.2500",
      "currency_code": "KWD",
      "updated_at": "2026-07-16T06:12:00Z"
    }
  ],
  "message": "18 products updated since watermark",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 18, "cursor": "eyJpZCI6MzM4MTJ9" } },
  "request_id": "7f4c1a30-6d5e-4b12-9a8c-3f0e2d1b7c56",
  "timestamp": "2026-07-16T10:20:00Z"
}
```

## 5. POS — Ingest a Branch Z-Report

```
POST /api/v1/partners/pos/transactions HTTP/1.1
Host: api.qayd.io
Authorization: Bearer <partner client-credentials JWT>
X-Company-Id: 1042
Idempotency-Key: pos_branch7_zreport_20260716
Content-Type: application/json

{
  "partner_connection_id": 5210,
  "branch_id": 7,
  "external_transaction_id": "FOODICS-Z-88831",
  "total_amount": "612.7500",
  "currency_code": "KWD",
  "payment_method": "knet",
  "line_items": [ { "product_sku": "SKU-1092", "quantity": "14.0000", "unit_price": "43.7679" } ]
}
```

```json
{
  "success": true,
  "data": { "invoice_id": 88401, "receipt_id": 60088, "mapping_warnings": [] },
  "message": "POS transaction ingested and posted",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1a9c3e40-7f2d-4b91-8e5a-4c0f1d2b9a67",
  "timestamp": "2026-07-16T10:24:11Z"
}
```

## 6. CRM — Push a Contact Update Into QAYD

```
PATCH /api/v1/partners/crm/contacts/sync HTTP/1.1
Host: api.qayd.io
Authorization: Bearer <partner client-credentials JWT>
X-Company-Id: 1042
Content-Type: application/json

{
  "partner_connection_id": 6604,
  "external_contact_id": "sf_003D000001Xy9Z",
  "customer_id": 20415,
  "fields": { "email": "procurement@example-client.com", "phone": "+965 2224 5566" }
}
```

```json
{
  "success": true,
  "data": { "customer_id": 20415, "customer_contacts_updated": 1 },
  "message": "Customer contact synced from CRM",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "2b0d4f51-8a3e-4c02-9f6b-5d1e0a3c8b78",
  "timestamp": "2026-07-16T10:27:39Z"
}
```

# End of Document
