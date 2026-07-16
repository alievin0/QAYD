# Public API — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: PUBLIC_API
---

# Purpose

QAYD is an AI Financial Operating System. Laravel 12 (PHP 8.4+) is the single source of truth for
every business and financial rule; the FastAPI/Python AI engine and every client — the Next.js 15
web app, the Flutter mobile app, and third-party software — talk to that Laravel API over
REST/JSON/HTTPS under `/api/v1/` and never touch PostgreSQL directly. Every other API-layer document
in this corpus (`AUTHENTICATION_API.md`, `AUTHORIZATION_API.md`, `REST_STANDARDS.md`,
`API_ERROR_HANDLING.md`, `API_PAGINATION.md`, `API_FILTERING_SORTING.md`, `API_VERSIONING.md`,
`API_WEBHOOKS.md`, `API_SDK_GUIDELINES.md`, `API_SECURITY.md`, `API_MONITORING.md`,
`API_OPENAPI_SPEC.md`, `API_ARCHITECTURE.md`, `API_DESIGN_PRINCIPLES.md`, `API_IDEMPOTENCY.md`)
specifies the *complete* API surface — the entire contract available to QAYD's own first-party web
and mobile clients, and to the AI engine, across every module: Accounting, Sales, Purchasing,
Banking, Inventory, Payroll, Tax, and Reports.

**This document specifies a narrower thing: the subset of that surface that QAYD deliberately opens
to external, third-party API consumers** — independent software vendors, point-of-sale and
e-commerce integrations, accounting-automation tools (Zapier/Make-style), and partner engineering
teams who are not QAYD employees and do not hold a first-party company-user session. It defines:

- Exactly which resource families are public (**invoices, customers, products, payments**) and,
  just as importantly, which are explicitly **not** public (payroll, banking transfers, tax
  submission, AI-engine internals, company/role administration) and why.
- The two authentication mechanisms an external consumer uses — **API keys** and **OAuth
  applications** — layered on top of the hybrid Sanctum + JWT model `AUTHENTICATION_API.md` already
  defines, without contradicting it.
- The rate-limit tiers, quotas, and headers that apply specifically to external traffic.
- The **Developer Portal**: the authenticated web application external consumers use to register,
  obtain credentials, monitor usage, and manage webhook subscriptions.
- The mechanics of API key issuance, scoping, rotation, and revocation from an external consumer's
  point of view.
- The four official **SDKs** (PHP, JavaScript/TypeScript, Python, Flutter/Dart) as consumed by
  external developers, reusing the exact packages `API_SDK_GUIDELINES.md` defines.
- The **webhook event catalog** made available to public consumers — a filtered view of the full
  catalog in `API_WEBHOOKS.md`.
- The persistent **Sandbox** environment external developers build and test against before going
  live.
- Fully worked **Examples** end to end: registering an app, minting keys, creating a customer, a
  product, an invoice, recording a payment, and receiving a webhook.
- The **Policies** governing acceptable use, data handling, versioning, and support for external
  consumers of the public API.

Every example below uses the standard QAYD response envelope, real `/api/v1/...` paths, and the
canonical resource and permission-key names already established across the corpus. Where this
document narrows or adds to a rule defined elsewhere (for example, opaque public resource
identifiers, or a public-specific rate-limit tier), it says so explicitly and cites the
authoritative document for the underlying mechanism. It never contradicts
`AUTHENTICATION_API.md`, `API_WEBHOOKS.md`, `API_SDK_GUIDELINES.md`, or the shared `DESIGN_CONTEXT`.

The running example used throughout this document: **Fatafeat POS**, a third-party point-of-sale
vendor, builds an integration that pushes retail sales as QAYD invoices and payments and pulls the
product catalog and customer list for **Al Rawda Trading Co.** (`company_id = 12`, base currency
`KWD`) — the same demo company used in `AUTHENTICATION_API.md`.

# Public Resources (invoices, customers, products, payments — NOT payroll/internal)

## What "public" means

A **public resource** is an internal QAYD entity that QAYD has deliberately given a stable,
versioned, externally documented JSON representation — deliberately narrower than the full
internal Eloquent model an employee-authenticated Next.js session would receive. Exposing a
resource publicly is an explicit allow-list decision made once per field, not a default. The
platform rule is: **internal by default, public only by declared exception.**

Four resource families are public in QAYD API v1:

| Public object | Backing table(s) | Owning module | Primary use by external consumers |
|---|---|---|---|
| `invoice` | `invoices`, `invoice_items` | Sales | Create/read sales invoices issued to a company's customers; reconcile against POS/e-commerce sales. |
| `customer` | `customers`, `customer_contacts`, `customer_addresses` | Accounting (Customer Management) | Sync a customer master between QAYD and an external CRM/POS/e-commerce platform. |
| `product` | `products`, `product_categories`, `units_of_measure` | Products | Sync a product/SKU catalog and its selling price for order and invoice creation. |
| `payment` | `receipts`, `receipt_allocations` | Sales | Record money collected against one or more invoices; reconcile settlement from a payment gateway or POS. |

**Explicitly not public in v1** — these exist elsewhere in the platform and are documented in their
owning module, but no API key or OAuth application scope can ever reach them, regardless of the
scopes requested or granted:

| Excluded area | Why it is excluded |
|---|---|
| Payroll (`employees`, `payroll_runs`, `payslips`) | Employee compensation data is the most sensitive category of company data under the platform's own classification; it is withheld from the external surface entirely rather than field-filtered. There is no `payroll.*` scope in the public scope catalog at all — not "denied by default," simply not offered. |
| Banking transfers (`bank_transfers`, `bank_accounts` mutation) | `bank.transfer` is a human-approval-chain-gated sensitive operation platform-wide (see `DESIGN_CONTEXT`); it is additionally never reachable by a machine credential of any kind, first- or third-party. |
| Tax submission (`tax_returns` filing, `tax.submit`) | Government e-filing (ZATCA, GCC VAT authorities) is performed only by QAYD's own Tax module service account under a signed integration with the relevant tax authority; a third-party app cannot file on a company's behalf through this API. |
| AI engine internals (`ai_conversations`, `ai_messages`, model invocation) | The AI layer is an implementation detail of QAYD's own product, not a capability QAYD resells through this API in v1. |
| Company/role administration (`companies`, `roles`, `permissions`, `company_users`) | Tenant and access-control configuration is administered only by an authenticated human company user through the first-party web app; letting an external app provision roles or company settings would undermine the entire RBAC model this platform is built on. |
| General Ledger, Journal Entries, Trial Balance, Financial Statements | Derived accounting truth is read through QAYD's own Reports module UI/exports; the public API exposes the *documents that produce* journal entries (invoices, payments) but never the ledger projection itself, to avoid a third party reconstructing a company's full chart of accounts and financial position without an accountant in the loop. |

## Public resource identifiers

Internal tables use `BIGINT GENERATED ALWAYS AS IDENTITY` primary keys, per the shared platform
standard, and every first-party document in this corpus shows those integers directly (for example
`"id": 88231` in `API_ARCHITECTURE.md`). For the four public resource families, an **additional,
opaque, prefixed identifier** is generated once at row creation and is the only identifier ever
returned to, or accepted from, an external (API-key or OAuth) caller:

```sql
ALTER TABLE invoices  ADD COLUMN public_id VARCHAR(40) NOT NULL DEFAULT '';
ALTER TABLE customers ADD COLUMN public_id VARCHAR(40) NOT NULL DEFAULT '';
ALTER TABLE products  ADD COLUMN public_id VARCHAR(40) NOT NULL DEFAULT '';
ALTER TABLE receipts  ADD COLUMN public_id VARCHAR(40) NOT NULL DEFAULT '';

CREATE UNIQUE INDEX uq_invoices_public_id  ON invoices  (public_id) WHERE public_id <> '';
CREATE UNIQUE INDEX uq_customers_public_id ON customers (public_id) WHERE public_id <> '';
CREATE UNIQUE INDEX uq_products_public_id  ON products  (public_id) WHERE public_id <> '';
CREATE UNIQUE INDEX uq_receipts_public_id  ON receipts  (public_id) WHERE public_id <> '';
```

`public_id` is generated by a model `creating` event as `{prefix}_{base58(random 8 bytes)}` —
`in_` for invoices, `cus_` for customers, `prod_` for products, `pay_` for payments (receipts) — and
is immutable thereafter. This is a deliberate, additive layer, not a breaking change: internal
route-model binding for first-party Sanctum/JWT sessions continues to accept the raw `BIGINT` id
exactly as shown elsewhere in this corpus (`GET /api/v1/accounting/customers/{id}` with
`{id}=4821`); a custom Laravel route-model-binding resolver additionally accepts the prefixed
public form on the same route (`GET /api/v1/accounting/customers/cus_8k2m1qzr`), and rejects a
public-form id presented on any endpoint outside the four public families. Two reasons this exists
specifically for the public surface and not internally:

1. **Enumeration and competitive-intelligence resistance.** A sequential `id` leaks the rate at
   which a company issues invoices or onboards customers to anyone who can see two IDs and do
   subtraction. QAYD's own authenticated employees already see this scoped to their own company (no
   leak), but an external app is a different trust boundary — the opaque id removes the signal
   entirely without changing the underlying schema.
2. **Stable public contract independent of internal migrations.** Internal ids are never reused,
   but a `public_id` gives QAYD room to, for example, re-key or shard a table internally in the
   future without ever touching a value an external integration has stored.

## Public resource shapes

Each public resource is a curated, explicitly allow-listed projection of its internal model, with an
`object` discriminator (Stripe-style) so a consumer can identify a payload's type without inspecting
the endpoint it came from — useful once the same shape starts appearing inside webhook deliveries
(see `# Webhooks For Public Consumers`).

### `invoice`

| Internal column (`invoices`) | Public field | Notes |
|---|---|---|
| `public_id` | `id` | `in_5b7q2wdx` |
| — | `object` | Always `"invoice"` |
| `invoice_number` | `invoice_number` | Unchanged |
| `customer_id` → `customers.public_id` | `customer` | Public customer id |
| `invoice_date` | `invoice_date` | `YYYY-MM-DD` |
| `due_date` | `due_date` | `YYYY-MM-DD` |
| `currency_code` | `currency` | ISO 4217 |
| `subtotal_amount` | `subtotal` | `NUMERIC(19,4)` as JSON string |
| `discount_amount` | `discount` | as JSON string |
| `tax_amount` | `tax` | as JSON string |
| `total_amount` | `total` | as JSON string |
| `amount_paid` | `amount_paid` | as JSON string |
| `balance_due` | `balance_due` | as JSON string, generated column |
| `status` | `status` | `draft \| posted \| paid \| partially_paid \| void` |
| `pdf_attachment_id` | `pdf_url` | Resolved to a short-lived signed URL, never the raw attachment id |
| `created_at`, `updated_at` | `created_at`, `updated_at` | ISO 8601 |
| `branch_id`, `sales_order_id`, `delivery_id`, `cost_center_id`, `project_id`, `journal_entry_id`, `posted_by`, `voided_by`, `created_by`, `updated_by`, `deleted_at` | *(excluded)* | Internal linkage, internal user identity, or accounting wiring — never serialized to an external caller under any scope. |

Worked example, `GET /api/v1/sales/invoices/in_5b7q2wdx` with a key scoped `sales.invoice.read`:

```json
{
  "success": true,
  "data": {
    "object": "invoice",
    "id": "in_5b7q2wdx",
    "invoice_number": "INV-2026-004821",
    "customer": "cus_8k2m1qzr",
    "invoice_date": "2026-07-10",
    "due_date": "2026-08-09",
    "currency": "KWD",
    "subtotal": "1250.0000",
    "discount": "0.0000",
    "tax": "62.5000",
    "total": "1312.5000",
    "amount_paid": "1312.5000",
    "balance_due": "0.0000",
    "status": "paid",
    "pdf_url": "https://api.qayd.dev/api/v1/sales/invoices/in_5b7q2wdx/pdf?sig=1f9a...&exp=1752700000",
    "created_at": "2026-07-10T09:14:02Z",
    "updated_at": "2026-07-14T11:02:47Z"
  },
  "message": "OK",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b6f2a1c4-9e3d-4a1b-8c2f-7d5e6a9b0c1d",
  "timestamp": "2026-07-16T10:00:00Z"
}
```

### `customer`

| Internal column (`customers`) | Public field | Notes |
|---|---|---|
| `public_id` | `id` | `cus_8k2m1qzr` |
| — | `object` | Always `"customer"` |
| `customer_code` | `reference` | Company-assigned code |
| `legal_name` | `legal_name` | |
| `display_name` | `display_name` | Falls back to `legal_name` if null |
| `name_en`, `name_ar` | `name_en`, `name_ar` | Bilingual fields, both nullable |
| `customer_type` | `type` | `individual \| company \| government \| nonprofit` |
| `preferred_currency` | `currency` | |
| `preferred_language` | `language` | `en \| ar` |
| `status`, `lifecycle_state` | `status` | Collapsed to one public enum: `active \| inactive \| blacklisted \| archived` |
| `tax_registration_number` | `tax_registration_number` | Only serialized when the key/app also holds `accounting.customer.tax_read` (a narrower opt-in scope; most integrations do not need it) |
| `created_at`, `updated_at` | `created_at`, `updated_at` | |
| `national_id`, `national_id_country`, `government_entity_code`, `nonprofit_registration_number`, `sales_owner_id`, `source_lead_id`, `credit_limit`, `credit_rating`, `blacklist_reason_code`, `blacklist_reason_notes`, `merged_into_customer_id`, `custom_fields`, `created_by`, `updated_by` | *(excluded, with one exception below)* | Government IDs are PII the public API never returns under any scope; credit and blacklist detail are financial-risk data internal to QAYD's own AR workflow; `custom_fields` is company-defined free-form data withheld unless the company explicitly opts a field into the public schema through the Developer Portal (see `# Developer Portal`). |

`merged_into_customer_id` is not returned as a raw column, but a merged customer's public
representation still resolves correctly: `GET` on a merged customer's `public_id` returns `301`-style
semantics inside the standard envelope — `data.merged: true` and `data.active_customer` pointing at
the surviving customer's `public_id` — so an integration following a stale id is redirected to
current data rather than silently reading a dead record.

### `product`

| Internal column (`products`) | Public field | Notes |
|---|---|---|
| `public_id` | `id` | `prod_9f3xh0az` |
| — | `object` | Always `"product"` |
| `sku` | `sku` | |
| `name_en`, `name_ar` | `name_en`, `name_ar` | |
| `description`, `description_ar` | `description`, `description_ar` | |
| `product_type` | `type` | `physical_product \| service \| digital_product \| subscription \| ...` |
| `status` | `status` | `draft \| active \| discontinued \| archived` |
| `brand`, `model_number` | `brand`, `model_number` | |
| `default_selling_price`, `currency_code` | `price`, `currency` | Selling price only |
| `weight`, `weight_unit`, `length_cm`, `width_cm`, `height_cm` | `weight`, `weight_unit`, `dimensions_cm` | Grouped under `dimensions_cm: {length, width, height}` |
| `unit_of_measure_id` | `unit_of_measure` | Resolved to its code (e.g. `"pcs"`, `"kg"`), not the internal FK |
| `category_id` | `category` | Resolved to `{id, name_en, name_ar}` of the public category tree |
| `default_cost_price`, `revenue_account_id`, `expense_account_id`, `inventory_account_id`, `cogs_account_id`, `tax_category_id`, `preferred_vendor_id`, `min_stock_level`, `max_stock_level`, `reorder_point`, `reorder_quantity`, `search_vector`, `embedding`, `custom_fields`, `created_by`, `updated_by` | *(excluded)* | Cost price is proprietary margin data — never exposed to an external integration regardless of scope, on the same principle as payroll. Account-mapping fields are pure internal GL wiring. Stock-planning thresholds and the AI `search_vector`/`embedding` columns are internal-only infrastructure. |

Note the asymmetry with the internal `PRODUCTS.md` document: `default_cost_price` is listed there as
a normal, fully-documented internal field, and is correctly so — the exclusion is a *public-API*
policy, not a statement that the field doesn't exist or isn't governed elsewhere.

### `payment`

The public object `payment` renames the internal `receipts` table for external clarity ("payment" is
the universally recognized term; "receipt" is QAYD's internal accounting term for the same event) and
folds in its `receipt_allocations`.

| Internal column (`receipts` / `receipt_allocations`) | Public field | Notes |
|---|---|---|
| `public_id` | `id` | `pay_2n8v6tsk` |
| — | `object` | Always `"payment"` |
| `receipt_number` | `reference` | |
| `customer_id` → `customers.public_id` | `customer` | |
| `receipt_date` | `date` | |
| `payment_method` | `method` | `cash \| bank_transfer \| card \| knet \| cheque` |
| `amount`, `currency_code` | `amount`, `currency` | |
| `status` | `status` | `cleared \| pending \| bounced \| reversed` |
| `receipt_allocations` rows | `allocations` | Array of `{invoice: public_id, amount_applied}` |
| `bank_account_id`, `journal_entry_id`, `created_by`, `updated_by` | *(excluded)* | Internal bank-account reference and GL linkage; never exposed publicly, matching the invoice/product exclusion pattern above. |

```json
{
  "object": "payment",
  "id": "pay_2n8v6tsk",
  "reference": "RCPT-2026-002214",
  "customer": "cus_8k2m1qzr",
  "date": "2026-07-14",
  "method": "knet",
  "amount": "1312.5000",
  "currency": "KWD",
  "status": "cleared",
  "allocations": [
    { "invoice": "in_5b7q2wdx", "amount_applied": "1312.5000" }
  ],
  "created_at": "2026-07-14T11:02:40Z",
  "updated_at": "2026-07-14T11:02:47Z"
}
```

## Public endpoint map

All public-resource endpoints live at the same paths first-party clients use — there is no separate
`/api/v1/public/*` namespace for these four families, because the underlying Laravel routes,
controllers, FormRequests, and services are identical; only the caller's credential type and scope
determine whether it may reach them. (`/api/v1/public/*` continues to mean something different and
narrower elsewhere in the corpus: fully unauthenticated endpoints such as status pages and e-invoice
verification links, per `API_ARCHITECTURE.md`'s Public surface row.)

| Method | Path | Required scope | Description |
|---|---|---|---|
| GET | `/api/v1/sales/invoices` | `sales.invoice.read` | List invoices for the active company. Cursor-paginated, filterable by `status`, `customer`, date range. |
| GET | `/api/v1/sales/invoices/{id}` | `sales.invoice.read` | Retrieve one invoice by public id. |
| POST | `/api/v1/sales/invoices` | `sales.invoice.create` | Create a draft invoice. |
| POST | `/api/v1/sales/invoices/{id}/post` | `sales.invoice.post` | Post a draft invoice (creates its journal entry internally; not itself exposed). |
| POST | `/api/v1/sales/invoices/{id}/void` | `sales.invoice.void` | Void a posted, unpaid invoice. |
| GET | `/api/v1/accounting/customers` | `accounting.customer.read` | List customers. |
| GET | `/api/v1/accounting/customers/{id}` | `accounting.customer.read` | Retrieve one customer. |
| POST | `/api/v1/accounting/customers` | `accounting.customer.create` | Create a customer. |
| PATCH | `/api/v1/accounting/customers/{id}` | `accounting.customer.update` | Update allow-listed public fields only (contact/address/name/language/currency — never credit, blacklist, or merge state). |
| GET | `/api/v1/products` | `products.read` | List products/catalog. |
| GET | `/api/v1/products/{id}` | `products.read` | Retrieve one product. |
| POST | `/api/v1/products` | `products.create` | Create a draft product. |
| PATCH | `/api/v1/products/{id}` | `products.update` | Update allow-listed public fields only (never cost price or account mapping). |
| GET | `/api/v1/sales/receipts` | `sales.receipt.read` | List payments. |
| GET | `/api/v1/sales/receipts/{id}` | `sales.receipt.read` | Retrieve one payment. |
| POST | `/api/v1/sales/receipts` | `sales.receipt.create` | Record a payment and allocate it to one or more invoices. |

Every endpoint above returns and accepts the standard envelope described in `API_ARCHITECTURE.md`
and honors the pagination, filtering, and error-handling contracts of `API_PAGINATION.md`,
`API_FILTERING_SORTING.md`, and `API_ERROR_HANDLING.md` unmodified — this document narrows *which*
endpoints and *which fields* are reachable, not *how* a reachable endpoint behaves.

# Authentication (API keys, OAuth apps)

External consumers authenticate with exactly one of two mechanisms — never a Sanctum SPA cookie
session and never a raw user password. Both mechanisms terminate, from Laravel's point of view, in
the same `Authorization: Bearer <token>` header and the same `auth:sanctum`-adjacent guard pipeline
described in `AUTHENTICATION_API.md`; a request-time discriminator distinguishes them before either
guard runs:

```
ResolveCredentialType middleware:
  if bearer starts with "qk_live_" or "qk_test_"  -> ApiKeyGuard   (# API Keys)
  else if bearer decodes as a valid RS256 JWT       -> OAuthTokenGuard or SanctumGuard,
                                                        distinguished by the "azp" (authorized party)
                                                        claim: absent/"qayd-web"/"qayd-mobile" ->
                                                        first-party Sanctum guard (unchanged, see
                                                        AUTHENTICATION_API.md); any other value ->
                                                        OAuthTokenGuard, matched against oauth_clients.
```

This keeps the request lifecycle identical to the one `API_ARCHITECTURE.md` documents (Gateway →
Auth → Permission → Validation → Business → DB → Response) for every caller type — an external
request is authenticated once, at the same layer, before anything else runs, exactly like a
first-party request.

## API keys

An **API key** is a static, long-lived, opaque bearer credential scoped to one company and a
declared subset of permission keys, created by a company admin (never by QAYD staff on a company's
behalf) either from the QAYD web app's Settings → Integrations screen or from the Developer Portal.
It is the right mechanism for **server-to-server integrations the company itself controls** — a
company's own backend, its POS vendor's dedicated per-merchant credential, or a script an
accountant's own developer runs. It is intentionally *not* usable to build a single app that serves
many companies with one shared credential — that use case is what OAuth applications are for.

API keys reuse the exact `api_keys` table `AUTHENTICATION_API.md` defines:

```sql
CREATE TABLE api_keys (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NULL REFERENCES companies(id),
    created_by      BIGINT NULL REFERENCES users(id),
    name            VARCHAR(120) NOT NULL,
    key_prefix      VARCHAR(16) NOT NULL,
    key_hash        CHAR(64) NOT NULL,
    scopes          JSONB NOT NULL DEFAULT '[]',
    environment     VARCHAR(10) NOT NULL DEFAULT 'live',
    rate_limit_tier VARCHAR(20) NOT NULL DEFAULT 'standard',
    last_used_at    TIMESTAMPTZ NULL,
    expires_at      TIMESTAMPTZ NULL,
    revoked_at      TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT api_keys_env_chk CHECK (environment IN ('live','test'))
);
```

This document fixes the concrete key format left open elsewhere: the full secret is
`qk_{environment}_{40 hex chars}`, e.g. `qk_live_9f2a1c88b0e4d5a6f7301829384756afeabc12309`, of which
`key_prefix` stores only `qk_live_9f2a` (12 visible characters) for display in the Developer Portal
and in logs — enough for a human to recognize *which* key a log line refers to, never enough to
reconstruct the secret. `environment = 'test'` keys authenticate only against the Sandbox base URL
(`# Sandbox`); `environment = 'live'` keys authenticate only against production
(`https://api.qayd.dev/api/v1`). Presenting a `test` key at the production host, or a `live` key at
the sandbox host, is rejected with `401 AUTHENTICATION_FAILED` before any permission check runs —
this is checked first specifically so a misconfigured integration fails loudly in the sandbox during
development rather than silently in production.

`scopes` is a JSON array of the exact dotted permission keys defined across the corpus (see
`# API Keys` for the curated public scope catalog) — an API key's effective permission set is the
**intersection** of its `scopes` array and whatever its `company_id`'s subscription plan and the
public scope catalog allow; a key can never exceed what a human admin could grant, and can never
reach a permission key outside the public catalog (payroll, tax submission, bank transfer, company
administration) no matter what is written into `scopes` — the authorization middleware validates
every requested scope against the public catalog allow-list on every request, not only at key
creation time, so a future narrowing of the public catalog automatically and immediately narrows
every previously issued key.

## OAuth applications

An **OAuth application** is the mechanism for **one piece of software serving many companies** —
the marketplace pattern. Fatafeat POS registers exactly one OAuth application in the Developer
Portal; every merchant using Fatafeat POS that also uses QAYD connects their own company to it
individually, via consent, without ever sharing a static secret with Fatafeat's engineers or with
each other. This is distinct from, and does not change, the "planned OAuth/SSO posture (not yet
implemented)" `AUTHENTICATION_API.md` tracks — that entry is about a QAYD *user* signing in with
Google/Microsoft SSO instead of a password; this section is about a **third-party application**
requesting delegated, scoped access to a company's data, which is a marketplace-integration concern,
not a login-method concern, and is implemented independently of it.

QAYD runs a dedicated OAuth2 authorization server (Laravel Passport) alongside — not instead of —
Sanctum. Sanctum continues to own first-party web/mobile session tokens and the `api_keys` table
above; Passport owns only third-party OAuth client and token lifecycle:

```sql
CREATE TABLE oauth_clients (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    owner_user_id     BIGINT NOT NULL REFERENCES users(id),      -- the developer account that registered it
    name              VARCHAR(120) NOT NULL,                     -- "Fatafeat POS"
    client_id         VARCHAR(40) NOT NULL,                      -- "oac_7f1b2a9c..."
    client_secret_hash CHAR(64) NOT NULL,
    logo_url          TEXT NULL,
    redirect_uris     JSONB NOT NULL DEFAULT '[]',                -- exact-match allow-list
    requested_scopes  JSONB NOT NULL DEFAULT '[]',                -- max scopes this app may ever request
    is_confidential   BOOLEAN NOT NULL DEFAULT true,               -- false => public client, PKCE mandatory
    status            VARCHAR(20) NOT NULL DEFAULT 'pending_review', -- pending_review|approved|suspended|revoked
    reviewed_by        BIGINT NULL REFERENCES users(id),
    reviewed_at         TIMESTAMPTZ NULL,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at             TIMESTAMPTZ NULL,
    CONSTRAINT oauth_clients_status_chk CHECK (status IN ('pending_review','approved','suspended','revoked'))
);
CREATE UNIQUE INDEX uq_oauth_clients_client_id ON oauth_clients (client_id);

CREATE TABLE oauth_company_grants (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    oauth_client_id BIGINT NOT NULL REFERENCES oauth_clients(id),
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    granted_by      BIGINT NOT NULL REFERENCES users(id),         -- the company admin who consented
    granted_scopes  JSONB NOT NULL DEFAULT '[]',                  -- subset of requested_scopes, company-approved
    granted_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at      TIMESTAMPTZ NULL,
    revoked_by      BIGINT NULL REFERENCES users(id)
);
CREATE UNIQUE INDEX uq_oauth_company_grants ON oauth_company_grants (oauth_client_id, company_id)
    WHERE revoked_at IS NULL;

CREATE TABLE oauth_access_tokens (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    oauth_client_id BIGINT NOT NULL REFERENCES oauth_clients(id),
    company_grant_id BIGINT NOT NULL REFERENCES oauth_company_grants(id),
    jti             UUID NOT NULL,
    scopes          JSONB NOT NULL DEFAULT '[]',
    expires_at      TIMESTAMPTZ NOT NULL,
    revoked_at      TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE oauth_refresh_tokens (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    oauth_access_token_id BIGINT NOT NULL REFERENCES oauth_access_tokens(id),
    token_hash            CHAR(64) NOT NULL,
    expires_at             TIMESTAMPTZ NOT NULL,
    revoked_at              TIMESTAMPTZ NULL
);
CREATE UNIQUE INDEX uq_oauth_refresh_hash ON oauth_refresh_tokens (token_hash);
```

Every new `oauth_clients` row starts `pending_review`: QAYD staff verify the developer's identity,
inspect the requested scopes against the app's stated purpose, and test its redirect URIs before
flipping it to `approved` — an unapproved app cannot complete step 3 of the flow below for any
company outside the developer's own sandbox account. This human review gate is the public-API
equivalent of the platform's general rule that sensitive actions are never automated end to end.

**Authorization Code + PKCE flow** (confidential or public clients), the flow Fatafeat POS uses when
a merchant clicks "Connect QAYD" inside Fatafeat's own dashboard:

```
1. Fatafeat's backend generates a code_verifier, derives code_challenge = base64url(sha256(verifier)),
   and redirects the merchant's browser to:

   GET https://developers.qayd.dev/oauth/authorize
       ?client_id=oac_7f1b2a9c
       &redirect_uri=https://app.fatafeat.com/qayd/callback
       &response_type=code
       &scope=sales.invoice.create%20sales.invoice.read%20accounting.customer.read%20products.read%20sales.receipt.create
       &state=9f1c2a3b...
       &code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
       &code_challenge_method=S256

2. The merchant, already logged into QAYD as an Owner/CFO of Al Rawda Trading Co., sees a consent
   screen: "Fatafeat POS wants to: create and read invoices, read customers, read products, create
   payments, for Al Rawda Trading Co. It will not be able to see payroll, banking, or tax data." The
   merchant approves (or narrows the scope list, if the Developer Portal consent UI allows
   per-scope toggling) and is redirected back with an authorization code:

   302 https://app.fatafeat.com/qayd/callback?code=ac_3f9b...&state=9f1c2a3b...

3. Fatafeat's backend exchanges the code for tokens, presenting the original code_verifier instead
   of a client secret if it is a public (mobile/SPA) client:

   POST /oauth/token
   Content-Type: application/x-www-form-urlencoded

   grant_type=authorization_code&code=ac_3f9b...&redirect_uri=https://app.fatafeat.com/qayd/callback
   &client_id=oac_7f1b2a9c&code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk

4. Response:
   {
     "access_token": "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9...",
     "token_type": "Bearer",
     "expires_in": 3600,
     "refresh_token": "def50200a1b2c3...",
     "scope": "sales.invoice.create sales.invoice.read accounting.customer.read products.read sales.receipt.create"
   }
```

The issued `access_token` is a signed JWT carrying `azp: "oac_7f1b2a9c"` and the granted scopes as
its permission set for exactly one company (the one identified in `company_grant_id`) — every
subsequent call still requires `X-Company-Id`, and the middleware rejects any value other than the
one company this specific token was granted against, even though the underlying user (the merchant)
may belong to several companies. OAuth access tokens last 1 hour (longer than a first-party 15-minute
token, because polling a refresh endpoint every 15 minutes across thousands of merchant connections
is needless load); refresh tokens last 90 days sliding, rotate on every use exactly like
`AUTHENTICATION_API.md`'s refresh-rotation model, and are immediately revoked in full if the company
admin revokes the grant from their own Settings → Connected Apps screen (`DELETE
/api/v1/oauth/grants/{id}`, permission `company.settings.manage`) — revocation is company-initiated
and instant, never dependent on the third-party app's cooperation.

**Client Credentials flow** (confidential clients only, no user in the loop) is offered for the
narrower case of a partner's own backend integration that is not a multi-merchant marketplace app —
functionally equivalent to a `live`-environment API key, issued through the OAuth mechanism instead
because the partner's infrastructure already standardizes on OAuth2 tooling:

```
POST /oauth/token
grant_type=client_credentials&client_id=oac_...&client_secret=...&scope=sales.invoice.read
```

returns an access token scoped to the single company the client was approved against at review
time; there is no refresh token (the client simply requests a new token with its secret when the
old one expires) and no consent screen, since no company-admin delegation is involved beyond the
original review-time approval.

# Rate Limits

Every rate limit in QAYD is enforced by the same Redis-backed sliding-window limiter the platform
uses internally (see `DESIGN_CONTEXT`); this section fixes the concrete tiers, keys, and headers
that apply to external callers specifically, which are more conservative by default than
first-party traffic — QAYD's own Next.js/Flutter clients are shaped by QAYD's own UI and default to
a generous 600 requests/minute per authenticated user (the figure shown in `API_ERROR_HANDLING.md`'s
canonical `RATE_LIMITED` example); external integrations are unknown-shaped traffic by definition and
start lower, with a clear path to a higher tier on request.

## Tiers

| Tier | Applies to | Sustained limit | Burst limit (10s window) | Notes |
|---|---|---|---|---|
| `sandbox` | Any `environment='test'` API key, or any OAuth token issued against a sandbox company grant | 60 req/min | 120 req/10s | Fixed; cannot be elevated. Sized for interactive development and CI, not load testing. |
| `standard` | Default tier for a new `environment='live'` API key or a newly approved OAuth client | 300 req/min per company per credential | 450 req/10s | The default every new integration starts on. |
| `elevated` | Companies on a paid API add-on, or OAuth apps with sustained legitimate volume | 1,200 req/min per company per credential | 1,800 req/10s | Self-service upgrade from the Developer Portal's Usage tab once a company's plan supports it. |
| `partner` | Negotiated, contractual partners (e.g. a national POS/e-commerce platform with thousands of connected merchants) | Custom, minimum 3,000 req/min, dedicated Redis partition | Custom | Requires a signed partner agreement; provisioned by QAYD, not self-service. |

`rate_limit_tier` on `api_keys` and an equivalent column on `oauth_clients` store the assigned tier;
the limiter's Redis key is `ratelimit:{tier}:{company_id}:{credential_id}:{minute_bucket}`, so two
different integrations connected to the same company each get their own bucket, and the same OAuth
app connected to two different companies (Fatafeat POS serving both Al Rawda Trading Co. and another
merchant) never lets one merchant's burst starve the other's quota.

## Headers and the 429 response

Identical to the platform-wide contract in `REST_STANDARDS.md`, present on every response regardless
of credential type:

```
HTTP/1.1 200 OK
X-RateLimit-Limit: 300
X-RateLimit-Remaining: 287
X-RateLimit-Reset: 1752661260
```

When the limit is exceeded:

```json
{
  "success": false,
  "data": null,
  "message": "Rate limit exceeded.",
  "errors": [
    {
      "field": null,
      "code": "RATE_LIMITED",
      "message": "You have exceeded 300 requests per minute for this API key.",
      "meta": { "limit": 300, "window_seconds": 60, "retry_after_seconds": 22 }
    }
  ],
  "meta": { "pagination": null },
  "request_id": "3c2d1e0f-8b7a-4c9d-9e1f-2a3b4c5d6e7f",
  "timestamp": "2026-07-16T10:04:38Z"
}
```

with a `Retry-After: 22` header alongside `X-RateLimit-Remaining: 0`. Per
`API_ERROR_HANDLING.md`'s retry classification, `RATE_LIMITED` is "retryable after a fixed wait" —
official SDKs honor `Retry-After` exactly and never apply exponential backoff on top of it (doing
both would make an integration wait far longer than necessary and is a common, avoidable bug in
hand-rolled clients).

## What counts against the limit

- Every authenticated `/api/v1/...` call from an API key or OAuth token counts, including reads.
- Webhook *deliveries* (QAYD calling out to a partner's endpoint) are never counted against the
  partner's inbound rate limit — that is a wholly separate, outbound concern governed by
  `# Webhooks For Public Consumers`.
- `POST /oauth/token` calls (token refresh, client-credentials issuance) are counted separately
  under a dedicated, more generous per-client-id bucket (120 req/min) so a burst of merchant
  sign-ins does not eat into an app's data-endpoint quota.
- Bulk/list endpoints requesting the maximum `per_page` (`API_PAGINATION.md`, up to `500`) cost the
  same single request as `per_page=1` against this limiter — pagination size and rate limiting are
  independent axes, matching the platform-wide rule — but list endpoints for the four public
  resources additionally apply a stricter **1,200-row-per-minute** read ceiling on top of the
  request-count limit specifically to bound total payload volume for the `standard` tier; an
  integration syncing a large catalog should page with a moderate `per_page` and expect this ceiling
  to shape burst-import speed, not treat it as a bug.

## Requesting an increase

A company (for its own API keys) or an approved OAuth developer (for their app, applied per
connected company) requests a tier increase from the Developer Portal's Usage tab; QAYD reviews
actual 30-day traffic shape before granting `elevated`, and `partner` tier is available only through
a signed agreement with QAYD's partnerships team, referenced further in `# Policies`.

# Developer Portal

The Developer Portal (`https://developers.qayd.dev`) is the authenticated web application external
developers use for everything this document describes — distinct from the QAYD product itself
(`app.qayd.dev`, the Next.js 15 web app company users work in day to day) and distinct from the
static API reference (`https://api.qayd.dev/docs`, Redoc-rendered from the OpenAPI document per
`API_OPENAPI_SPEC.md`). A developer account is independent of a company account: one developer
(a person or a vendor organization) can register once and build integrations that many companies
later connect to, which is exactly the OAuth-application model above.

## What the portal provides

| Area | Capability |
|---|---|
| Account | Register a developer account (email, organization name); invite teammates with `admin`/`member` roles scoped to the developer org, not to any QAYD company. |
| API Keys | Create/rotate/revoke `live` and `test` API keys for **a company the developer account itself administers** (i.e., a company's own admin using the portal for their own integration, not a multi-tenant marketplace app — see `# API Keys`). |
| OAuth Apps | Register an OAuth application (name, logo, redirect URIs, requested scopes, homepage/privacy-policy URL); submit for review; view approval status; rotate the client secret; view every company that has granted it access and the scopes each granted. |
| Usage & Logs | Per-credential request volume, error rate, p50/p95 latency, and a searchable log of the last 30 days of requests by `request_id` — the same `request_id` a company's own QAYD support ticket would reference, so QAYD support and a partner's engineer can look at the exact same trace. |
| Webhooks | Register/test/replay webhook endpoint subscriptions for the events in `# Webhooks For Public Consumers`; view delivery history and signing secret, identical in shape to the first-party `/api/v1/webhooks/*` surface in `API_WEBHOOKS.md`, exposed here as a UI over the same API. |
| Sandbox | One-click provisioning of a sandbox company and a fresh `test` key; a "reset sandbox data" action; a link to the Postman workspace and the Prism mock server instructions from `API_OPENAPI_SPEC.md`. |
| Docs | The rendered OpenAPI reference, this document and its sibling API-layer documents in developer-readable form, the changelog, and versioned archives of past OpenAPI snapshots (`https://api.qayd.dev/docs/versions/<tag>/openapi.bundled.json`). |
| Support | A ticketing form that auto-attaches the developer's most recent `request_id`s and API-key prefixes (never the secret) to speed up triage; status-page subscription for incident notifications. |

## Application review

Every new OAuth application starts `pending_review` (see `# Authentication`). QAYD's review checks,
at minimum: the redirect URIs resolve over HTTPS to a domain the developer controls (verified via a
DNS TXT challenge, `qayd-verification=<token>`, the same pattern used by most OAuth marketplaces),
the requested scopes are proportionate to the app's stated description (a "read-only reporting
dashboard" requesting `sales.invoice.create` is flagged for clarification before approval), and the
developer's contact email is confirmed. Median review time is targeted at two business days; a
rejected application receives a specific reason and may be resubmitted after addressing it. A
developer may test an application against their **own** sandbox company at any time, before or
during review, without waiting for approval — review gates only the ability to request a grant from
an unrelated company's admin, not sandbox self-testing.

## Company-side visibility

A company admin's own view mirrors the developer's: Settings → Connected Apps lists every OAuth
application and every API key active against their company, the scopes each holds, last-used
timestamp, and a one-click revoke — a company can sever a third-party integration unilaterally at
any moment, independent of whether the developer's app is still `approved` in QAYD's own registry.

# API Keys

This section specifies the concrete lifecycle a company admin (or a developer acting on a company
they administer) drives through `/api/v1/auth/api-keys` — the same endpoints
`AUTHENTICATION_API.md` defines, described here from the public-consumer's operational perspective.

## The public scope catalog

An API key or OAuth grant may only ever request scopes from this allow-list — the enforceable
definition of "public" for this document. Requesting any key outside this list at creation time is
rejected with `422 VALIDATION_ERROR`; a key already holding one somehow (a bug, or a future removal
of a scope from this list) has it silently stripped from its effective permission set at request
time, never granted "by accident."

| Scope | Grants |
|---|---|
| `sales.invoice.read` | List/retrieve invoices |
| `sales.invoice.create` | Create draft invoices |
| `sales.invoice.post` | Post a draft invoice |
| `sales.invoice.void` | Void a posted, unpaid invoice |
| `accounting.customer.read` | List/retrieve customers |
| `accounting.customer.create` | Create customers |
| `accounting.customer.update` | Update allow-listed customer fields |
| `accounting.customer.tax_read` | Additionally include `tax_registration_number` on customer reads (narrow opt-in, see `# Public Resources`) |
| `products.read` | List/retrieve products and categories |
| `products.create` | Create draft products |
| `products.update` | Update allow-listed product fields |
| `sales.receipt.read` | List/retrieve payments |
| `sales.receipt.create` | Record a payment and its invoice allocations |
| `integrations.webhooks.manage` | Register/update/delete webhook endpoint subscriptions (`# Webhooks For Public Consumers`) |

Sixteen scopes, total, as of this document's version — deliberately small and enumerable. Every
scope not on this list (`accounting.journal.*`, `bank.*`, `payroll.*`, `tax.submit`,
`company.settings.*`, `roles.manage`, `ai.*`, and all others) is categorically unavailable to any
external credential, matching `# Public Resources`.

## Creating a key

```
POST /api/v1/auth/api-keys
Authorization: Bearer eyJhbGciOiJSUzI1NiIs...     (first-party company-admin session)
X-Company-Id: 12
Content-Type: application/json

{
  "name": "Fatafeat POS — production sync",
  "environment": "live",
  "scopes": [
    "sales.invoice.create", "sales.invoice.read",
    "accounting.customer.read", "products.read",
    "sales.receipt.create", "integrations.webhooks.manage"
  ]
}
```

```json
{
  "success": true,
  "data": {
    "id": 4471,
    "name": "Fatafeat POS — production sync",
    "key_prefix": "qk_live_9f2a",
    "secret": "qk_live_9f2a1c88b0e4d5a6f7301829384756afeabc12309",
    "environment": "live",
    "scopes": ["sales.invoice.create", "sales.invoice.read", "accounting.customer.read", "products.read", "sales.receipt.create", "integrations.webhooks.manage"],
    "rate_limit_tier": "standard",
    "created_at": "2026-07-16T10:10:00Z"
  },
  "message": "API key created. Store the secret now — it will not be shown again.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "7a8b9c0d-1e2f-4a3b-8c4d-5e6f7a8b9c0d",
  "timestamp": "2026-07-16T10:10:00Z"
}
```

`data.secret` is the **only** time the plaintext value is ever transmitted or displayed; every
subsequent `GET /api/v1/auth/api-keys` returns `key_prefix` only. If it is lost, the only remedy is
revoking the key and creating a new one — QAYD cannot recover it, by design (`key_hash` is a one-way
SHA-256 digest).

## Rotation and revocation

```
DELETE /api/v1/auth/api-keys/4471
```

revokes immediately (`revoked_at` set; in-flight requests using it fail within one Redis-cache TTL,
60 seconds, matching the platform-wide credential-revocation SLA in `API_SECURITY.md`). Rotation is
modeled as create-new-then-revoke-old rather than an in-place secret replacement, so an integration
can deploy the new secret and confirm it works before the old one stops accepting traffic —
recommended pattern: create the replacement key with identical scopes, deploy it, verify a
successful call, then revoke the original. Webhook signing secrets rotate independently and support
a genuine overlap window (`# Webhooks For Public Consumers`); API keys do not have a soft-overlap
mode because, unlike a webhook secret, a revoked API key's replacement requires the integration to
change a stored value regardless, so there is no safety benefit to a dual-valid period.

## Scope bundle presets

The Developer Portal's key-creation UI offers named presets over the raw scope list above, so a
company admin who is not an API expert can still make an informed choice:

| Preset name | Scopes included |
|---|---|
| Read-only accounting sync | `sales.invoice.read`, `accounting.customer.read`, `products.read`, `sales.receipt.read` |
| Invoicing app | `sales.invoice.create`, `sales.invoice.read`, `sales.invoice.post`, `accounting.customer.read`, `products.read` |
| POS / payments reconciliation | `sales.invoice.create`, `sales.invoice.read`, `accounting.customer.read`, `products.read`, `sales.receipt.create`, `sales.receipt.read` |
| Full public catalog (all 16 scopes) | Every scope in the catalog above |

Choosing a preset does not prevent hand-editing the resulting scope array afterward; presets exist
to reduce over-scoping by default, following least-privilege as the path of least resistance rather
than mandating it.

## Best practices (documented for external developers)

- Never commit a key secret to source control; inject it via environment variable or a secrets
  manager, exactly as `API_SDK_GUIDELINES.md` assumes for every SDK's `apiKey` constructor argument.
- Use a distinct key per environment and, where volume justifies it, per deployment (a company
  running Fatafeat POS at three branches may reasonably run three keys) so a compromised or
  malfunctioning credential can be revoked without affecting unrelated traffic.
- Prefer the narrowest scope preset that satisfies the integration; add scopes later rather than
  starting broad, since a scope reduction can require re-authentication of a running integration
  while a scope addition never breaks one.
- Rotate a key immediately upon suspicion of exposure (a leaked log, a departing contractor,
  a compromised CI environment) — rotation is free and instantaneous; there is no reason to delay it
  pending investigation.
- Treat `last_used_at` in the Developer Portal as a decommissioning signal: a key unused for 90 days
  triggers an automatic "still needed?" notice to the company admin, and one unused for 180 days is
  auto-revoked with 14 days' advance warning, to shrink the platform's standing attack surface.

# SDK

External developers consume the exact same four official SDKs `API_SDK_GUIDELINES.md` specifies for
every QAYD client — there is no separate "partner SDK." The packages, repositories, and versioning
policy are identical:

| SDK | Package | Repository | Install |
|---|---|---|---|
| PHP | `qayd/sdk` (Packagist) | `qayd/sdk-php` | `composer require qayd/sdk` |
| JavaScript/TypeScript | `@qayd/sdk` (npm) | `qayd/sdk-js` | `npm install @qayd/sdk` |
| Python | `qayd-sdk` (PyPI) | `qayd/sdk-python` | `pip install qayd-sdk` |
| Flutter/Dart | `qayd_sdk` (pub.dev) | `qayd/sdk-dart` | `flutter pub add qayd_sdk` |

Each SDK's client constructor accepts either an API key or an OAuth access token in the same `auth`
parameter position, since both ultimately present as a bearer token at the transport layer; the SDK
does not need a different code path for "internal" versus "external" callers, only a different
credential value, which is itself the point of the unified authentication design in this document.

**PHP:**
```php
use Qayd\Client;

// API key
$client = new Client(apiKey: getenv('QAYD_API_KEY'), companyId: 'cmp_9f2a1c');

// OAuth (after completing the authorization-code exchange in # Authentication)
$client = new Client(accessToken: $oauthAccessToken, companyId: 'cmp_9f2a1c');

$invoice = $client->sales()->invoices()->create([
    'customer' => 'cus_8k2m1qzr',
    'due_date' => '2026-08-09',
    'items' => [['product' => 'prod_9f3xh0az', 'quantity' => 2, 'unit_price' => '625.0000']],
]);
```

**JavaScript/TypeScript:**
```ts
import { Qayd } from '@qayd/sdk';

const qayd = new Qayd({
  auth: { type: 'apiKey', apiKey: process.env.QAYD_API_KEY! },
  companyId: 'cmp_9f2a1c',
});

const payment = await qayd.sales.receipts.create({
  customer: 'cus_8k2m1qzr',
  method: 'knet',
  amount: '1312.5000',
  allocations: [{ invoice: 'in_5b7q2wdx', amountApplied: '1312.5000' }],
});
```

**Python:**
```python
from qayd import QaydClient

client = QaydClient(api_key=os.environ["QAYD_API_KEY"], company_id="cmp_9f2a1c")
product = client.products.retrieve("prod_9f3xh0az")
```

**Flutter/Dart:**
```dart
final client = QaydClient(apiKey: const String.fromEnvironment('QAYD_API_KEY'),
                           companyId: 'cmp_9f2a1c');
final customers = await client.accounting.customers.list(perPage: 50);
```

Every SDK's built-in `verifyWebhookSignature(payload, signatureHeader, secret)` helper — specified
in full in `API_SDK_GUIDELINES.md` — works identically for external consumers verifying deliveries
described in `# Webhooks For Public Consumers`; there is no separate verification helper for public
webhook subscribers. SDKs auto-generate and attach an `Idempotency-Key` on every method that maps to
a financial mutation (`invoices.create`, `receipts.create`), retry only the "always retryable" and
"retryable after a fixed wait" error classes from `API_ERROR_HANDLING.md`, and raise a typed
`QaydApiError` subclass (`ValidationError`, `AuthenticationError`, `PermissionError`, `NotFoundError`,
`ConflictError`, `RateLimitError`) for everything else — external developers get the same
error-handling ergonomics as QAYD's own Next.js and Flutter engineers, because they are, literally,
running the same library.

# Webhooks For Public Consumers

Webhooks for external consumers reuse the entire delivery subsystem `API_WEBHOOKS.md` specifies —
the same `webhook_endpoints`/`webhook_deliveries` tables, the same `X-Qayd-Signature: t=...,v1=...`
HMAC-SHA256 scheme over `"{timestamp}.{raw_body}"`, the same 16-attempt/~24-hour exponential backoff
with jitter, the same dead-letter and replay mechanics, and the same at-least-once, no-ordering-
guarantee delivery semantics. Nothing about the transport, signing, or retry contract is different
for a public consumer. What is different is **which events an external subscription may receive**
and **who may create a subscription in the first place**.

## Public event catalog

Of the eight events `API_WEBHOOKS.md` catalogs, only the subset touching the four public resource
families is deliverable to an external endpoint. An API key or OAuth grant may only subscribe
(`integrations.webhooks.manage` scope) to events in the left column below; the right column lists
events that exist in the platform but are never offered to an external subscription, matching
`# Public Resources`' exclusion list exactly:

| Public (subscribable externally) | Internal-only (never delivered externally) |
|---|---|
| `invoice.created` | `journal.posted` |
| `invoice.paid` | `payroll.completed` |
| `payment.received` | `inventory.updated` |
| `customer.created` *(new in this document)* | `bank.synced` |
| `customer.updated` *(new in this document)* | `ai.finished` |
| `product.created` *(new in this document)* | |
| `product.updated` *(new in this document)* | |

`customer.*` and `product.*` are additive events this document introduces to complete the public
surface — Sales' and Customer Management's existing event emission already fires equivalent internal
signals for use by Reports and the AI layer (`accounting.customer.updated` as an internal Laravel
event name, per `CUSTOMERS.md`); this document's contribution is the externally-facing rename and
guarantee (`customer.updated`, without the internal `accounting.` prefix, matching the `object`
naming used in `# Public Resources`) plus the field-filtering described there, so an external
receiver never sees a payload field the corresponding `GET` endpoint would also withhold. An
endpoint's registered `event_types` array is validated against this left-hand list only; attempting
to subscribe to `payroll.completed` from an API-key-authenticated call to `POST
/api/v1/webhooks/endpoints` returns `422` with `code: "EVENT_NOT_SUBSCRIBABLE"`, distinct from a
generic validation failure so an SDK can surface a precise, actionable message.

Additionally, an OAuth application's subscription is further intersected with its **granted**
scopes per company: Fatafeat POS's connection to Al Rawda Trading Co., holding
`sales.invoice.create`/`read` but not `accounting.customer.update`, may subscribe to
`invoice.created`, `invoice.paid`, and `payment.received`, but a subscription attempt for
`customer.updated` is rejected until the merchant grants `accounting.customer.read` or above — a
webhook subscription can never be a side channel to information a credential's REST scopes would
not otherwise expose.

## Registering an endpoint

```
POST /api/v1/webhooks/endpoints
Authorization: Bearer qk_live_9f2a1c88...
X-Company-Id: 12
Content-Type: application/json

{
  "url": "https://app.fatafeat.com/webhooks/qayd",
  "event_types": ["invoice.paid", "payment.received"],
  "description": "Fatafeat POS settlement sync"
}
```

```json
{
  "success": true,
  "data": {
    "id": 881,
    "url": "https://app.fatafeat.com/webhooks/qayd",
    "event_types": ["invoice.paid", "payment.received"],
    "status": "active",
    "signing_secret": "whsec_7f3a1c9e2b4d5f6071829384756afeabc1230912",
    "created_at": "2026-07-16T10:20:00Z"
  },
  "message": "Webhook endpoint registered. Store the signing secret now — it will not be shown again.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9c8d7e6f-5a4b-4c3d-8e2f-1a0b9c8d7e6f",
  "timestamp": "2026-07-16T10:20:00Z"
}
```

## Worked delivery example

```
POST https://app.fatafeat.com/webhooks/qayd
Content-Type: application/json
X-Qayd-Signature: t=1752661455,v1=5257a869e7bff9e13f9e1c3a...
User-Agent: Qayd-Webhooks/1.0

{
  "id": "evt_4f2a9c11b8",
  "type": "invoice.paid",
  "created_at": "2026-07-16T10:22:35Z",
  "data": {
    "object": "invoice",
    "id": "in_5b7q2wdx",
    "invoice_number": "INV-2026-004821",
    "customer": "cus_8k2m1qzr",
    "currency": "KWD",
    "total": "1312.5000",
    "balance_due": "0.0000",
    "status": "paid"
  }
}
```

A receiver must respond `2xx` within 15 seconds or the delivery enters the retry schedule; it must
verify `X-Qayd-Signature` before trusting the payload (`# SDK`'s `verifyWebhookSignature` helper, or
the worked PHP/JavaScript examples in `API_WEBHOOKS.md`, reused verbatim); and it should treat the
payload as a notification to re-fetch current state via `GET /api/v1/sales/invoices/in_5b7q2wdx`
rather than a system of record in its own right, per the at-least-once/no-ordering guarantee.

## Egress IPs and portal parity

`GET /api/v1/webhooks/egress-ips` — mirrored in the Developer Portal's Webhooks tab — publishes the
stable CIDR ranges QAYD delivers from, so a receiver behind a restrictive firewall can allowlist
QAYD rather than accept traffic from arbitrary sources. Every capability on the REST
`/api/v1/webhooks/*` surface (list deliveries, replay a dead-lettered delivery, rotate a signing
secret with the 24-hour dual-valid overlap window) is available both as a direct API call and as a
Developer Portal UI action — the portal is a layer over the same endpoints, never a separate system.

# Sandbox

Every integration is built and certified against the Sandbox before it can request `live` credentials
for a real company. QAYD runs a **persistent, real Laravel** sandbox — not a static mock — at:

```
https://sandbox-api.qayd.dev/api/v1
```

identical application code to production, an isolated PostgreSQL database, its own Redis and Reverb,
seeded with one or more demo companies in the reserved `company_id` range `900000+` so sandbox and
production ids can never collide or be confused in a log line. `bank.transfer`, `payroll.release`,
and `tax.submit` permission checks are force-denied in the sandbox regardless of role or scope —
structurally, not just by omission from the public scope catalog — so a partner integrator can
exercise the complete request/response contract, including webhook delivery to a partner-supplied
URL, with zero risk of an unintended real-world financial side effect, even if a future bug widened
the public scope catalog by mistake.

## Obtaining sandbox access

A `test`-environment API key or an OAuth grant against a sandbox company is obtained from the
Developer Portal with no review gate and no waiting period — sandbox self-service is intentionally
frictionless, since it carries no real-money risk. `POST /api/v1/auth/api-keys` with
`"environment": "test"` while authenticated against the sandbox host issues a
`qk_test_...`-prefixed key immediately.

## Seeded fixtures

The default demo company (`company_id = 900001`, "QAYD Sandbox Trading Co.", base currency `KWD`)
ships with a fixed, deterministic set of records so integration tests and CI pipelines can assert on
stable ids rather than re-deriving fixtures on every run:

| Fixture | Public id | Notes |
|---|---|---|
| Demo customer | `cus_sandbox0001` | `name_en: "Demo Customer LLC"`, active, no credit limit |
| Demo product | `prod_sandbox0001` | SKU `DEMO-SKU-001`, selling price `100.0000 KWD` |
| Demo unpaid invoice | `in_sandbox0001` | `total: 100.0000`, `balance_due: 100.0000`, status `posted` — useful for exercising the payment/allocation flow end to end |
| Demo paid invoice | `in_sandbox0002` | `status: paid`, useful for exercising read-only flows without side effects |

`POST /api/v1/sandbox/reset` (sandbox host only, requires a `test` key) restores every fixture to
its default state and deletes any records an integration created during testing, so a CI run can
reset between suites; it is rate-limited to once per 5 minutes per company to prevent accidental
reset-loops from masking a real bug.

## Mocking before the sandbox, and the go-live path

For UI work that needs to start before even the sandbox is convenient (offline development, design
review), the same static Prism mock server `API_OPENAPI_SPEC.md` documents
(`npx @stoplight/prism-cli mock openapi.bundled.yaml --port 4010 --dynamic`) serves schema-valid
randomized responses against the identical public resource shapes defined in this document; it
requires no credential at all. The recommended integration path is therefore three-staged:

```
1. Static mock (Prism, localhost)         -> build UI against schema-valid shapes, no account needed
2. Sandbox (sandbox-api.qayd.dev, test key) -> real request/response + real webhook delivery, zero financial risk
3. Production (api.qayd.dev, live key)      -> real company, real money, after Developer Portal
                                               application review (OAuth apps) or company-admin
                                               key creation (API keys)
```

A Postman collection generated from the same OpenAPI document is published to the QAYD public
Postman workspace on every spec release, pre-loaded with the sandbox host, the `X-Company-Id`
collection variable, and a placeholder for a `test` key — the fastest path from receiving sandbox
credentials to an interactive first call.

## Sandbox-to-production checklist

Before switching a `test` key for a `live` one, the Developer Portal's "Go Live" checklist requires:
webhook endpoint URL reachable over HTTPS with a valid certificate (verified by a live test
delivery); signature verification confirmed against a real delivery, not only the sandbox's; error
handling confirmed for at least `422`, `429`, and `409` (an integration that has only ever seen
sandbox happy-path traffic is the single most common cause of a broken production launch); and, for
OAuth applications, `approved` review status. None of these are enforced at the infrastructure
level — a determined developer could skip the checklist — but the `live` key creation screen
surfaces it prominently as a strong nudge, and QAYD support references the same checklist when
triaging a partner's post-launch incident.

# Examples

Five fully worked, end-to-end scenarios, all against the Sandbox host and the `900001` demo company
unless stated otherwise, chained so each builds on the previous one's output.

## 1. Create a customer, a product, and a draft invoice with curl

```bash
# Create a customer
curl -s -X POST https://sandbox-api.qayd.dev/api/v1/accounting/customers \
  -H "Authorization: Bearer qk_test_5e6f7a8b9c0d1e2f3a4b5c6d7e8f9012" \
  -H "X-Company-Id: 900001" \
  -H "Content-Type: application/json" \
  -d '{
    "legal_name": "Al-Bahar Marine Supplies W.L.L.",
    "name_en": "Al-Bahar Marine Supplies",
    "name_ar": "شركة البحار للتجهيزات البحرية",
    "customer_type": "company",
    "preferred_currency": "KWD",
    "preferred_language": "ar"
  }'
# => 201, data.id = "cus_8k2m1qzr"

# Create a product
curl -s -X POST https://sandbox-api.qayd.dev/api/v1/products \
  -H "Authorization: Bearer qk_test_5e6f7a8b9c0d1e2f3a4b5c6d7e8f9012" \
  -H "X-Company-Id: 900001" \
  -H "Content-Type: application/json" \
  -d '{
    "sku": "PMP-2200",
    "name_en": "Submersible Water Pump 2HP",
    "name_ar": "مضخة مياه غاطسة 2 حصان",
    "product_type": "physical_product",
    "default_selling_price": "625.0000",
    "currency_code": "KWD"
  }'
# => 201, data.id = "prod_9f3xh0az"

# Create a draft invoice for two units
curl -s -X POST https://sandbox-api.qayd.dev/api/v1/sales/invoices \
  -H "Authorization: Bearer qk_test_5e6f7a8b9c0d1e2f3a4b5c6d7e8f9012" \
  -H "X-Company-Id: 900001" \
  -H "Idempotency-Key: 3f29b6b0-9e2b-4c9a-9a1e-2b6a2b9c1a11" \
  -H "Content-Type: application/json" \
  -d '{
    "customer": "cus_8k2m1qzr",
    "due_date": "2026-08-09",
    "items": [{ "product": "prod_9f3xh0az", "quantity": 2, "unit_price": "625.0000" }]
  }'
# => 201, data.id = "in_5b7q2wdx", data.status = "draft", data.total = "1312.5000" (incl. 5% KWT VAT placeholder)
```

## 2. Post the invoice and record a full payment

```bash
curl -s -X POST https://sandbox-api.qayd.dev/api/v1/sales/invoices/in_5b7q2wdx/post \
  -H "Authorization: Bearer qk_test_5e6f7a8b9c0d1e2f3a4b5c6d7e8f9012" \
  -H "X-Company-Id: 900001"
# => 200, data.status = "posted"

curl -s -X POST https://sandbox-api.qayd.dev/api/v1/sales/receipts \
  -H "Authorization: Bearer qk_test_5e6f7a8b9c0d1e2f3a4b5c6d7e8f9012" \
  -H "X-Company-Id: 900001" \
  -H "Idempotency-Key: 8a1b2c3d-4e5f-6071-8293-84756afeabc1" \
  -H "Content-Type: application/json" \
  -d '{
    "customer": "cus_8k2m1qzr",
    "method": "knet",
    "amount": "1312.5000",
    "currency": "KWD",
    "allocations": [{ "invoice": "in_5b7q2wdx", "amount_applied": "1312.5000" }]
  }'
# => 201, data.id = "pay_2n8v6tsk"; the invoice's balance_due is now "0.0000" and its status "paid"
```

## 3. Complete the OAuth authorization-code flow (Node.js receiver)

```javascript
// Step 1: redirect the merchant (see full parameter list in # Authentication)
app.get('/qayd/connect', (req, res) => {
  const { verifier, challenge } = generatePkcePair();
  req.session.qayd_verifier = verifier;
  const url = new URL('https://developers.qayd.dev/oauth/authorize');
  url.search = new URLSearchParams({
    client_id: process.env.QAYD_CLIENT_ID,
    redirect_uri: 'https://app.fatafeat.com/qayd/callback',
    response_type: 'code',
    scope: 'sales.invoice.create sales.invoice.read accounting.customer.read products.read sales.receipt.create',
    state: req.session.csrfToken,
    code_challenge: challenge,
    code_challenge_method: 'S256',
  }).toString();
  res.redirect(url.toString());
});

// Step 2: handle the callback and exchange the code
app.get('/qayd/callback', async (req, res) => {
  if (req.query.state !== req.session.csrfToken) return res.status(400).send('State mismatch');
  const tokenRes = await fetch('https://developers.qayd.dev/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      code: req.query.code,
      redirect_uri: 'https://app.fatafeat.com/qayd/callback',
      client_id: process.env.QAYD_CLIENT_ID,
      code_verifier: req.session.qayd_verifier,
    }),
  });
  const tokens = await tokenRes.json();
  await saveMerchantTokens(req.session.merchantId, tokens); // access_token, refresh_token, expires_in
  res.redirect('/dashboard?connected=qayd');
});
```

## 4. Cursor-paginate every unpaid invoice

```javascript
import { Qayd } from '@qayd/sdk';
const qayd = new Qayd({ auth: { type: 'apiKey', apiKey: process.env.QAYD_API_KEY }, companyId: 'cmp_900001' });

let cursor = null;
const unpaid = [];
do {
  const page = await qayd.sales.invoices.list({ status: 'posted', perPage: 100, cursor });
  unpaid.push(...page.data.filter(inv => inv.balance_due !== '0.0000'));
  cursor = page.meta.pagination.cursor;
} while (cursor);
```

## 5. Verify a webhook and handle a rate-limit error (Python receiver)

```python
from flask import Flask, request, abort
from qayd import verify_webhook_signature, QaydClient, RateLimitError
import time

app = Flask(__name__)
client = QaydClient(api_key=os.environ["QAYD_API_KEY"], company_id="cmp_900001")

@app.post("/webhooks/qayd")
def handle_webhook():
    if not verify_webhook_signature(request.data, request.headers.get("X-Qayd-Signature", ""),
                                     os.environ["QAYD_WEBHOOK_SECRET"]):
        abort(401)
    event = request.get_json()
    if event["type"] == "invoice.paid":
        try:
            invoice = client.sales.invoices.retrieve(event["data"]["id"])
            reconcile_local_order(invoice)
        except RateLimitError as e:
            time.sleep(e.retry_after)   # honor Retry-After exactly, no exponential backoff on top
            invoice = client.sales.invoices.retrieve(event["data"]["id"])
            reconcile_local_order(invoice)
    return "", 200
```

# Policies

## Acceptable use

The public API may be used only to read and write data on behalf of a company that has itself
authenticated the request (via its own API key) or explicitly granted an OAuth application access
(via the consent flow in `# Authentication`). Scraping, bulk-harvesting data outside a legitimate
integration's operational need, reselling raw QAYD data as a standalone product, attempting to
enumerate `public_id` values, load-testing the sandbox or production hosts without prior written
coordination with QAYD, and circumventing rate limits via multiple credentials for what is
functionally one integration are all prohibited and grounds for immediate `suspended` status on the
offending API key or OAuth client, independent of any company-level consequence.

## Data handling and residency

External consumers may cache public resource data only as long as operationally necessary to serve
their own integration (e.g., a POS caching a product catalog for offline sale entry) and must treat
a webhook delivery as a signal to refresh, not as permanent storage of record — the canonical values
always live in QAYD. Consumers must not store the fields this document marks *(excluded)* in
`# Public Resources` because the API never furnishes them in the first place. QAYD's production
database resides in-region per each customer's contracted data-residency terms (documented in
`DATABASE_ARCHITECTURE.md`); the public API does not alter or bypass that residency — a request
against `api.qayd.dev` is routed to the correct regional deployment transparently. Financial records
reachable through this API are retained per `TAX.md`'s jurisdictional schedule (a minimum of 10
years by default, at least 6 for KSA ZATCA-governed entities, per local commercial-law minimums
elsewhere) even after a company disconnects an integration; a revoked API key or OAuth grant stops
future access but never retroactively deletes records the integration already legitimately wrote.

## Versioning and deprecation

The public API follows the platform-wide policy in `API_VERSIONING.md`: `/api/v1/` is stable for the
life of this major version; a breaking change ships only as a parallel `/api/v2/`, with a minimum
12-month deprecation window on `v1` communicated via the `Deprecation`/`Sunset` response headers, a
`Link: <...>; rel="deprecation"` header pointing at a migration guide, and an email/Developer-Portal
notice to every registered credential holder. New optional fields, new enum values, new webhook
event types, and new endpoints are additive and ship without a version bump or advance notice,
consistent with semantic-versioning norms every SDK's own package version follows in lockstep with
`openapi/qayd-v1.yaml`.

## Security disclosure

A suspected vulnerability in the public API, an SDK, or the Developer Portal is reported to
QAYD's security contact published in the OpenAPI document's `info.contact` field and on the
Developer Portal's Security page, not through a public GitHub issue. QAYD does not require a
signed NDA to receive a report and commits to an initial response within two business days; a
confirmed finding is patched before public disclosure, coordinated with the reporter, per standard
responsible-disclosure practice.

## Service level and status

Production API availability is published on a public status page linked from the Developer Portal,
with historical uptime and incident postmortems for any outage affecting the public resource
families in this document. The Sandbox carries no uptime commitment — it is a development aid, not
a production dependency — and is the reason `# Sandbox`'s go-live checklist exists: an integration
must never depend on the sandbox host being reachable from a production code path.

## Suspension and termination

QAYD may suspend an API key or OAuth application (independent of the owning company's account
standing) for: a confirmed acceptable-use violation above, a security incident originating from the
credential, sustained abuse of rate limits after a warning, or an OAuth application whose review
status lapses (an app found to no longer match its originally reviewed scope/purpose after a
material undisclosed change). Suspension is always accompanied by a stated reason and, except in an
active-security-incident case, advance notice proportionate to the severity; a suspended company or
developer may appeal through the Developer Portal's support channel. Termination of a company's
QAYD subscription automatically revokes every API key and OAuth grant against that company
effective immediately, independent of any separate action against the credential itself.

## Government and regulated data boundaries

Because `# Public Resources` deliberately excludes payroll, banking transfer initiation, and tax
filing, a company's use of the public API can never, by construction, satisfy or substitute for its
own regulatory obligations in those domains (WPS/payroll compliance, central-bank transfer
reporting, ZATCA/GCC VAT e-invoicing submission) — those remain first-party QAYD product
capabilities, exercised only by an authenticated human company user, exactly as `# Public Resources`
states. A partner integration that needs to *reflect* tax or e-invoicing status (for example, to
show a customer whether an invoice's ZATCA submission succeeded) reads the invoice's public
`status`/`payment_status` fields, which are updated by the internal Tax module's own posting logic,
rather than ever driving that process itself.

# End of Document
