# OpenAPI Specification — QAYD API Layer
Version: 1.0
Status: Design Specification
Module: API
Submodule: OpenAPI Specification
---

# Purpose

QAYD exposes exactly one API surface: a REST/JSON API served by Laravel 12 under `/api/v1/`, consumed
by the Next.js web client, the Flutter mobile client, the FastAPI AI engine, and third-party
integrations. Nothing — no client, no the AI engine — talks to PostgreSQL directly. The OpenAPI
document is the single, machine-readable, versioned contract that describes every endpoint, every
request/response shape, every security requirement, and every error condition of that surface.

This document defines how QAYD authors, structures, publishes, tests, and governs its OpenAPI 3.1
specification. It is the reference an engineer opens before writing a new endpoint, before generating
a client SDK, before wiring contract tests into CI, or before onboarding a partner integration. The
spec is not documentation written after the fact — it is a build artifact with its own lifecycle,
versioning, linting, and test suite, on equal footing with the Laravel codebase it describes.

Three properties are non-negotiable for the spec:

1. **It is authoritative.** If the spec and the running API disagree, that is a bug — either the spec
   is stale or the implementation drifted. Contract tests (see `# Contract Testing`) exist to make that
   divergence impossible to ship silently.
2. **It is machine-consumable.** SDK generation (PHP, TypeScript, Python, Dart), mock servers, and the
   documentation portal are all generated from the same YAML — never hand-maintained in parallel.
3. **It mirrors the envelope exactly.** Every 2xx and 4xx/5xx schema in the spec uses the standard
   QAYD response envelope (`success`, `data`, `message`, `errors`, `meta.pagination`, `request_id`,
   `timestamp`). A response shape that doesn't match the envelope is a defect in the spec, not a
   legitimate exception.

# Standard (OpenAPI 3.1)

QAYD targets **OpenAPI 3.1.0** exclusively (not 3.0.x, not Swagger 2.0). 3.1 is fully JSON Schema
2020-12 compatible, which matters concretely for QAYD because:

- **`nullable` is gone.** QAYD has many nullable financial fields (`branch_id`, `cost_center_id`,
  `project_id`, `deleted_at`, `exchange_rate` on base-currency-only lines). Under 3.1 these are typed
  as `type: ["string", "null"]` / `type: ["integer", "null"]` instead of the 3.0 `nullable: true`
  sidecar flag, which several strict client generators mishandled.
- **`webhooks` is a first-class top-level object.** QAYD fires domain-event webhooks
  (`invoice.created`, `invoice.paid`, `payment.received`, `payroll.completed`, `inventory.updated`,
  `bank.synced`, `ai.finished`, `journal.posted`). These are documented as OpenAPI 3.1 `webhooks`
  entries, not bolted on as ad-hoc markdown.
- **`examples` is a JSON Schema map**, letting each schema carry multiple named, realistic examples
  (e.g. a `journal_entries` example with dimensions populated and one without) instead of a single
  ambiguous example field.
- **`$schema` and `$id` per-document** allow QAYD's component schemas to be validated standalone by
  any JSON Schema validator, which the contract-testing pipeline relies on.

QAYD does not use Swagger 2.0 constructs (`definitions`, `host`/`basePath`/`schemes`) anywhere, and PRs
introducing 3.0-only syntax (`nullable: true`, `example:` singular on a schema) are rejected by the
Spectral lint (`# Governance/Linting`).

The spec is authored as YAML (`.yaml`), not JSON, for diff-friendliness in code review. It is split
across multiple files using `$ref` (see `# Spec Structure`) and bundled into a single artifact before
publication.

# Spec Structure

The specification lives in the Laravel monorepo at `openapi/`, split by module so that each domain team
owns its own files without merge conflicts on a single 20,000-line document:

```
openapi/
├── openapi.yaml                  # root document: info, servers, security, tags, $ref to paths/
├── info.yaml                     # info block (title, version, contact, license, description)
├── servers.yaml                  # servers block
├── security-schemes.yaml         # securitySchemes block
├── components/
│   ├── envelope.yaml              # SuccessEnvelope, ErrorEnvelope, Pagination, Meta
│   ├── errors.yaml                # ErrorObject, error code enums, 4xx/5xx response templates
│   ├── parameters.yaml            # X-Company-Id header, page/per_page/cursor, sort, filter, search
│   └── schemas/
│       ├── accounting.yaml        # Account, JournalEntry, JournalLine, FiscalYear, FiscalPeriod
│       ├── sales.yaml             # Invoice, InvoiceItem, Customer, Receipt, CreditNote
│       ├── purchasing.yaml        # Bill, PurchaseOrder, Vendor, VendorPayment
│       ├── banking.yaml           # BankAccount, BankTransaction, BankReconciliation, Transfer
│       ├── inventory.yaml         # InventoryItem, StockMovement, StockAdjustment
│       ├── payroll.yaml           # Employee, PayrollRun, Payslip
│       └── tax.yaml               # TaxCode, TaxRate, TaxTransaction, TaxReturn
├── paths/
│   ├── accounting/
│   │   ├── journal-entries.yaml
│   │   ├── accounts.yaml
│   │   └── fiscal-periods.yaml
│   ├── sales/
│   │   ├── invoices.yaml
│   │   └── customers.yaml
│   ├── purchasing/
│   ├── banking/
│   ├── inventory/
│   ├── payroll/
│   └── tax/
└── webhooks/
    └── domain-events.yaml          # webhooks block: invoice.created, invoice.paid, journal.posted, ...
```

## `info`

```yaml
info:
  title: QAYD API
  version: 1.4.0
  summary: AI Financial Operating System — core accounting, sales, purchasing, banking, inventory, payroll and tax API.
  description: |
    REST API for QAYD. All endpoints are versioned under /api/v1, require a Sanctum/JWT bearer token,
    and are scoped to the active company via the X-Company-Id header. Every response uses the standard
    QAYD envelope. See the Reusable Components section for the envelope, pagination, and error schemas.
  contact:
    name: QAYD Platform Team
    email: api@qayd.dev
  license:
    name: Proprietary
  termsOfService: https://qayd.dev/terms
```

`version` is the **spec document's** semantic version (see `# Versioning The Spec`), which is distinct
from the `/api/v1/` URL segment — the URL segment is the API's major contract version; `info.version`
tracks incremental, non-breaking additions within that major version.

## `servers`

```yaml
servers:
  - url: https://api.qayd.dev/api/v1
    description: Production
  - url: https://staging-api.qayd.dev/api/v1
    description: Staging
  - url: http://localhost:8000/api/v1
    description: Local development
```

## `security` / `securitySchemes`

```yaml
components:
  securitySchemes:
    sanctumBearer:
      type: http
      scheme: bearer
      bearerFormat: JWT
      description: >
        Laravel Sanctum-issued JWT bearer token. Obtained via POST /api/v1/auth/login.
        Send as `Authorization: Bearer <token>`.
security:
  - sanctumBearer: []
```

Every operation inherits this global `security` requirement by default. Only `POST /api/v1/auth/login`,
`POST /api/v1/auth/register`, `POST /api/v1/auth/forgot-password`, and `GET /api/v1/health` override it
with `security: []` (public). This asymmetry — default-secure, explicit opt-out for the handful of
public routes — mirrors the platform's default-deny RBAC posture and is enforced by a Spectral rule
(`no-unauthenticated-by-default`) that fails any new path missing a `security` key.

The `X-Company-Id` header required on every tenant-scoped request is NOT modeled as a security scheme
(it is not a credential); it is a required `parameter` with `in: header`, referenced via
`components/parameters.yaml#/CompanyIdHeader` on every operation whose path is not under `/auth` or
`/system`.

## `paths` and `components/schemas`

Each path file documents one resource collection (e.g. `paths/accounting/journal-entries.yaml`
documents `/accounting/journal-entries` and `/accounting/journal-entries/{id}` and
`/accounting/journal-entries/{id}/post`). Each schema file documents the canonical resources owned by
that module, matching the canonical table names verbatim (`JournalEntry` ↔ `journal_entries`,
`Invoice` ↔ `invoices`, `Bill` ↔ `bills`) so that anyone can jump from a schema name to the owning
table with zero translation.

# Reusable Components

Every response in the API — success or failure — is the same envelope shape. The spec models this once
in `components/envelope.yaml` and every operation's response composes it via `allOf`, never repeats it.

## Envelope

```yaml
components:
  schemas:
    SuccessEnvelope:
      type: object
      required: [success, data, message, errors, meta, request_id, timestamp]
      properties:
        success:
          type: boolean
          enum: [true]
        data: {}
        message:
          type: string
          example: "Journal entry created successfully."
        errors:
          type: array
          items: {}
          maxItems: 0
        meta:
          $ref: '#/components/schemas/Meta'
        request_id:
          type: string
          format: uuid
        timestamp:
          type: string
          format: date-time

    ErrorEnvelope:
      type: object
      required: [success, data, message, errors, meta, request_id, timestamp]
      properties:
        success:
          type: boolean
          enum: [false]
        data:
          type: ["null"]
        message:
          type: string
          example: "The given data was invalid."
        errors:
          type: array
          items:
            $ref: '#/components/schemas/ErrorObject'
        meta:
          $ref: '#/components/schemas/Meta'
        request_id:
          type: string
          format: uuid
        timestamp:
          type: string
          format: date-time

    ErrorObject:
      type: object
      required: [code, field, message]
      properties:
        code:
          type: string
          example: "VALIDATION_FAILED"
        field:
          type: ["string", "null"]
          example: "lines.0.credit"
        message:
          type: string
          example: "The credit amount must be greater than or equal to 0."

    Meta:
      type: object
      properties:
        pagination:
          $ref: '#/components/schemas/Pagination'

    Pagination:
      type: object
      properties:
        page:
          type: integer
          minimum: 1
          example: 1
        per_page:
          type: integer
          minimum: 1
          maximum: 200
          example: 25
        total:
          type: integer
          minimum: 0
          example: 134
        cursor:
          type: ["string", "null"]
          example: null
```

A concrete resource response is then composed, never duplicated:

```yaml
    JournalEntryResponse:
      allOf:
        - $ref: '#/components/schemas/SuccessEnvelope'
        - type: object
          properties:
            data:
              $ref: '#/components/schemas/JournalEntry'
```

## Pagination parameters (shared across every list endpoint)

```yaml
components:
  parameters:
    PageParam:
      name: page
      in: query
      schema: { type: integer, minimum: 1, default: 1 }
    PerPageParam:
      name: per_page
      in: query
      schema: { type: integer, minimum: 1, maximum: 200, default: 25 }
    CursorParam:
      name: cursor
      in: query
      schema: { type: ["string", "null"] }
    SortParam:
      name: sort
      in: query
      description: Comma-separated fields, prefix with `-` for descending. Example: `-created_at,code`.
      schema: { type: string }
    FilterParam:
      name: filter
      in: query
      style: deepObject
      explode: true
      description: 'Field filters, e.g. filter[status]=posted&filter[fiscal_period_id]=12'
      schema: { type: object, additionalProperties: true }
    SearchParam:
      name: q
      in: query
      schema: { type: string }
    CompanyIdHeader:
      name: X-Company-Id
      in: header
      required: true
      schema: { type: integer, format: int64 }
      description: Active company context. Every tenant-scoped request MUST carry this header.
```

## Canonical resource schema pattern

Every resource schema follows the same skeleton, matching the standard columns present on every
tenant table: `id`, `company_id`, `branch_id` (nullable), `created_by`/`updated_by`, `created_at`,
`updated_at`, `deleted_at` (nullable). Example — the `Account` resource from the Chart of Accounts:

```yaml
    Account:
      type: object
      required: [id, company_id, code, name_en, account_type_id, normal_balance, status, created_at, updated_at]
      properties:
        id: { type: integer, format: int64, example: 4021 }
        company_id: { type: integer, format: int64, example: 100 }
        branch_id: { type: ["integer", "null"], format: int64 }
        code: { type: string, example: "1010" }
        name_en: { type: string, example: "Cash on Hand" }
        name_ar: { type: string, example: "النقد بالصندوق" }
        account_type_id: { type: integer, format: int64 }
        parent_id: { type: ["integer", "null"], format: int64 }
        normal_balance: { type: string, enum: [debit, credit] }
        status: { type: string, enum: [active, inactive] }
        currency_code: { type: string, example: "KWD" }
        tags: { type: array, items: { type: string } }
        custom_fields: { type: object, additionalProperties: true }
        created_by: { type: ["integer", "null"], format: int64 }
        updated_by: { type: ["integer", "null"], format: int64 }
        created_at: { type: string, format: date-time }
        updated_at: { type: string, format: date-time }
        deleted_at: { type: ["string", "null"], format: date-time }
```

Every other master-data and transactional schema (`JournalEntry`, `Invoice`, `Customer`, `Vendor`,
`InventoryItem`, `PayrollRun`, etc.) reuses this exact skeleton and only adds domain-specific fields,
so a client generator produces a consistent object shape across the entire SDK.

# Authoring Workflow

QAYD is **spec-first for new endpoints, code-verified for existing ones** — a hybrid chosen
deliberately because a pure spec-first flow drifts without enforcement, and a pure code-generated flow
produces schemas with no room for the deliberate documentation (descriptions, examples, deprecation
notes) an external SDK consumer needs.

## New endpoint (spec-first)

1. **Design the contract first.** The engineer adds/edits the relevant `paths/<module>/<resource>.yaml`
   and `components/schemas/<module>.yaml` files by hand, in a PR, before writing the Laravel route or
   controller. Reviewers approve the *contract* — field names, types, required-ness, permission key,
   error cases — independent of the implementation.
2. **Generate FormRequest validation rules from the schema.** A Laravel Artisan command,
   `php artisan openapi:make-request accounting/journal-entries#create`, reads the bundled spec and
   scaffolds a `StoreJournalEntryRequest` with `rules()` seeded from the schema's `required` and
   `properties` (types, `enum`, `minLength`/`maximum`, `format`). The engineer then fills in
   QAYD-specific business validation (e.g. balanced-entry check) that the spec cannot express.
3. **Implement Controller → Service → Repository** per the Clean Architecture layering. The controller
   only calls the FormRequest and the Service; no business logic in the controller.
4. **Contract tests run in CI** against the actual response (`# Contract Testing`) before merge.

## Existing endpoint (code-verified / drift-check)

For legacy or fast-follow endpoints originally written directly in Laravel, a scheduled CI job
(`spec:verify-drift`) diffs the live OpenAPI-annotated route list (extracted via reflection over
FormRequests + API Resources) against `openapi.yaml` and opens an automatic issue if they diverge. This
is a safety net, not the primary workflow — it exists so an endpoint can never silently drift once it's
in the spec.

## Generation aids from Laravel (assistive, not authoritative)

QAYD uses `dedoc/scramble` in **assistive mode only**: run locally
(`php artisan scramble:export --path=openapi/generated/scramble.yaml`) to produce a first-draft schema
from an existing FormRequest + API Resource pair, which the engineer then hand-merges into the
canonical `openapi/` tree — correcting nullability, adding `example`s, wiring the shared envelope via
`allOf`, and adding the permission key in `x-permission`. The generated file is never committed and
never bundled directly; it is scaffolding, because auto-generated schemas from PHP types cannot express
the envelope composition, multi-currency `oneOf` shapes, or webhook payloads QAYD needs.

## Bundling

Before publication, the split multi-file source is bundled into one artifact with Redocly CLI:

```bash
npx @redocly/cli bundle openapi/openapi.yaml -o dist/openapi.bundled.yaml
npx @redocly/cli bundle openapi/openapi.yaml -o dist/openapi.bundled.json --ext json
```

The bundled YAML/JSON is what CI lints, what the contract tester loads, what the SDK generators
consume, and what the documentation portal serves — never the split source directly (avoids every
downstream tool needing its own `$ref` resolver).

# Example Spec Fragment

Below is the complete, real fragment for `POST /api/v1/accounting/journal-entries` — creating a draft
journal entry — including its request body, both response cases, security, and the shared parameters.
This is representative of every write endpoint in the spec.

```yaml
paths:
  /accounting/journal-entries:
    post:
      operationId: createJournalEntry
      summary: Create a draft journal entry
      description: >
        Creates a journal entry in `draft` status with its lines. Debits and credits need not balance
        yet at draft stage validation is deferred to the posting step but line-level amounts and
        account references are validated immediately. Requires permission `accounting.journal.create`.
      tags: [Accounting]
      x-permission: accounting.journal.create
      security:
        - sanctumBearer: []
      parameters:
        - $ref: '../../components/parameters.yaml#/CompanyIdHeader'
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '../../components/schemas/accounting.yaml#/CreateJournalEntryRequest'
            examples:
              standardEntry:
                summary: Two-line balanced entry with a cost center
                value:
                  fiscal_period_id: 44
                  entry_date: "2026-07-16"
                  memo: "July office rent accrual"
                  reference: "JE-2026-0714"
                  lines:
                    - account_id: 5010
                      debit: "850.0000"
                      credit: "0.0000"
                      cost_center_id: 12
                      description: "Rent expense — HQ"
                    - account_id: 2110
                      debit: "0.0000"
                      credit: "850.0000"
                      description: "Accrued liability — HQ landlord"
      responses:
        '201':
          description: Journal entry created in draft status.
          content:
            application/json:
              schema:
                $ref: '../../components/schemas/envelope.yaml#/JournalEntryResponse'
              example:
                success: true
                data:
                  id: 9931
                  company_id: 100
                  branch_id: 1
                  status: draft
                  fiscal_period_id: 44
                  entry_date: "2026-07-16"
                  memo: "July office rent accrual"
                  reference: "JE-2026-0714"
                  total_debit: "850.0000"
                  total_credit: "850.0000"
                  currency_code: "KWD"
                  lines:
                    - id: 44210
                      account_id: 5010
                      debit: "850.0000"
                      credit: "0.0000"
                      cost_center_id: 12
                      description: "Rent expense — HQ"
                    - id: 44211
                      account_id: 2110
                      debit: "0.0000"
                      credit: "850.0000"
                      cost_center_id: null
                      description: "Accrued liability — HQ landlord"
                  created_by: 7
                  created_at: "2026-07-16T09:12:44Z"
                  updated_at: "2026-07-16T09:12:44Z"
                  deleted_at: null
                message: "Journal entry created successfully."
                errors: []
                meta:
                  pagination: null
                request_id: "6f2a9b3e-8f0a-4a34-9d2e-6f1c2d0a55e2"
                timestamp: "2026-07-16T09:12:44Z"
        '401':
          $ref: '../../components/responses.yaml#/Unauthorized'
        '403':
          $ref: '../../components/responses.yaml#/Forbidden'
        '422':
          description: Validation failed (e.g. unbalanced amounts, closed fiscal period, unknown account).
          content:
            application/json:
              schema:
                $ref: '../../components/schemas/envelope.yaml#/ErrorEnvelope'
              example:
                success: false
                data: null
                message: "The given data was invalid."
                errors:
                  - code: "FISCAL_PERIOD_CLOSED"
                    field: "fiscal_period_id"
                    message: "Fiscal period 44 is closed for new entries."
                meta: { pagination: null }
                request_id: "a13cbb2e-2222-4a4a-9e11-8b0a7d3e9911"
                timestamp: "2026-07-16T09:13:01Z"
        '429':
          $ref: '../../components/responses.yaml#/TooManyRequests'
```

The corresponding request schema, showing the `NUMERIC(19,4)`-as-string convention (money is always
transmitted as a decimal string, never a float, to avoid IEEE-754 rounding on the wire):

```yaml
components:
  schemas:
    CreateJournalEntryRequest:
      type: object
      required: [fiscal_period_id, entry_date, lines]
      properties:
        fiscal_period_id: { type: integer, format: int64 }
        entry_date: { type: string, format: date }
        memo: { type: string, maxLength: 500 }
        reference: { type: string, maxLength: 100 }
        lines:
          type: array
          minItems: 2
          items:
            type: object
            required: [account_id, debit, credit]
            properties:
              account_id: { type: integer, format: int64 }
              debit: { type: string, pattern: '^\d+\.\d{4}$', example: "850.0000" }
              credit: { type: string, pattern: '^\d+\.\d{4}$', example: "0.0000" }
              cost_center_id: { type: ["integer", "null"], format: int64 }
              project_id: { type: ["integer", "null"], format: int64 }
              department_id: { type: ["integer", "null"], format: int64 }
              description: { type: string, maxLength: 255 }
```

# Documentation Portal

QAYD publishes two rendered views of the same bundled spec, both auto-deployed on merge to `main`:

| Portal | Tool | Audience | URL |
|---|---|---|---|
| Interactive explorer | Swagger UI | Internal engineers, QA — "try it out" against staging | `https://api.qayd.dev/docs/explorer` |
| Reference documentation | Redoc | Partner integrators, external SDK consumers — read-only, printable | `https://api.qayd.dev/docs` |

Swagger UI is mounted as a Laravel route in non-production environments only (guarded by
`APP_ENV !== production` and a `docs.access` permission for authenticated internal staff in staging),
because "try it out" issues live bearer-token requests and QAYD does not expose that against production
data. Redoc's static reference build is public and served in all environments, since it renders the
spec without executing requests.

```php
// routes/web.php (excerpt)
Route::get('/docs', fn () => view('docs.redoc', [
    'specUrl' => asset('openapi/dist/openapi.bundled.json'),
]));

Route::middleware(['auth:sanctum', 'can:docs.access'])
    ->when(! app()->isProduction())
    ->get('/docs/explorer', fn () => view('docs.swagger', [
        'specUrl' => asset('openapi/dist/openapi.bundled.json'),
    ]));
```

Both portals read the identical bundled artifact produced in `# Authoring Workflow`; there is exactly
one source of truth rendered two ways, never two hand-maintained documentation sites.

# Contract Testing

The spec is worthless as a contract if the running API can drift from it silently. QAYD runs two layers
of contract testing, both mandatory in CI before merge:

## 1. Static validation (spec is well-formed)

```bash
npx @redocly/cli lint openapi/openapi.yaml --config .redocly.yaml
```

Fails the build on any `$ref` that doesn't resolve, any schema violating JSON Schema 2020-12, or any
Spectral rule violation (`# Governance/Linting`).

## 2. Dynamic validation (implementation matches spec)

Every Pest feature test that hits an API endpoint runs through a shared assertion helper that validates
the actual HTTP response against the bundled OpenAPI schema for that operation, using
`league/openapi-psr7-validator`:

```php
use League\OpenAPIValidation\PSR7\ValidatorBuilder;

function assertMatchesOpenApiSchema(TestResponse $response, string $method, string $path): void
{
    static $validator;
    $validator ??= (new ValidatorBuilder())
        ->fromYamlFile(base_path('openapi/dist/openapi.bundled.yaml'))
        ->getResponseValidator();

    $psrResponse = (new HttpFoundationFactory())->createResponse($response->baseResponse);
    $validator->validate(
        new OperationAddress($path, $method),
        $psrResponse
    );
}
```

```php
public function test_create_journal_entry_matches_openapi_contract(): void
{
    $response = $this->postJson('/api/v1/accounting/journal-entries', $this->validPayload())
        ->assertCreated();

    assertMatchesOpenApiSchema($response, 'post', '/accounting/journal-entries');
}
```

A schema mismatch (missing required field, wrong type, an undocumented extra field, an undocumented
status code) fails the test immediately, not weeks later when a partner's SDK breaks. This test runs
for every endpoint that has a spec entry; an endpoint with no contract test is flagged by the
`spec:coverage` CI check, which compares route list to test list and fails below 100% coverage on
endpoints tagged `stable`.

## 3. Consumer-side contract checks (Next.js / Flutter)

The generated TypeScript client (`# Code Generation`) is typed directly from the schemas, so a
frontend PR that sends a shape the spec doesn't allow fails `tsc` at compile time rather than at
runtime — the strongest and cheapest form of contract testing available to the frontend team.

# Code Generation

SDKs are generated, never hand-written, for all four targeted languages, from the same bundled spec,
on every tagged spec release (`# Versioning The Spec`):

| Target | Generator | Package | Trigger |
|---|---|---|---|
| TypeScript (Next.js) | `openapi-typescript` + `openapi-fetch` | `@qayd/api-client` (npm, private registry) | On spec tag |
| PHP (internal services, partner backends) | `openapi-generator-cli` (php generator, PSR-18 client) | `qayd/api-client-php` (Composer, private Packagist) | On spec tag |
| Python (AI engine, data scripts) | `openapi-python-client` | `qayd-api-client` (internal PyPI) | On spec tag |
| Dart/Flutter | `openapi-generator-cli` (dart-dio generator) | `qayd_api_client` (private pub.dev) | On spec tag |

```bash
# TypeScript client — types only, hand-rolled thin fetch wrapper on top
npx openapi-typescript dist/openapi.bundled.json -o packages/api-client/src/schema.d.ts

# Python client — full client incl. models + auth
openapi-python-client generate --url https://api.qayd.dev/docs/openapi.bundled.json \
  --config openapi/codegen/python-config.yaml

# Dart/Flutter client
openapi-generator-cli generate -i dist/openapi.bundled.yaml -g dart-dio \
  -o mobile/packages/qayd_api_client --additional-properties=pubName=qayd_api_client
```

The AI engine (FastAPI/Python) uses the generated Python client exclusively to call Laravel it never
constructs raw HTTP requests to QAYD endpoints by hand, which guarantees the AI layer cannot drift from
or bypass the same validation and permission checks a human-driven request goes through, per platform
rule that the AI never writes to the database directly or skips authorization.

Generated clients are published only after the dynamic contract test suite (`# Contract Testing`)
passes against that exact spec version, so a generated SDK is never released against a contract the
real API doesn't yet satisfy.

# Examples & Mock Servers

Two consumer needs are served without touching the real backend:

## Mock server (Prism)

```bash
npx @stoplight/prism-cli mock dist/openapi.bundled.yaml --port 4010 --dynamic
```

Frontend and mobile engineers point their local `.env` at `http://localhost:4010/api/v1` to build
against realistic, schema-valid mock responses before the Laravel endpoint exists — this un-blocks
spec-first parallel development between backend and frontend teams described in `# Authoring
Workflow`. `--dynamic` makes Prism generate randomized-but-schema-valid data per request rather than
always returning the static `example`; a `--example standardEntry` flag pins it to a named example
(e.g. the `standardEntry` example on `createJournalEntry` from `# Example Spec Fragment`) for
deterministic UI screenshots and Playwright fixtures.

## Sandbox environment (real Laravel, seeded data, no real money movement)

Beyond the static mock, QAYD runs a persistent sandbox at `https://sandbox-api.qayd.dev/api/v1`
identical Laravel code, isolated PostgreSQL database seeded with a demo company (`company_id`
reserved range `900000+`), Redis, and Reverb, but with `bank.transfer`, `payroll.release`, and
`tax.submit` permission checks force-denied regardless of role, so partner integrators can exercise the
full request/response contract, including webhooks (delivered to a partner-supplied sandbox URL),
without any risk of a real financial side effect. Sandbox API keys are obtained via the partner portal
and are visually distinguished (`sk_sandbox_...` prefix) from production keys (`sk_live_...`) so a
credential cannot be used against the wrong environment by mistake.

## Postman / Insomnia collections

A Postman collection is generated alongside the SDKs (`openapi2postmanv2` converter) and published to
the QAYD public Postman workspace on every spec release, pre-loaded with the `sanctumBearer`
auth flow and the `X-Company-Id` header as a collection-level variable, so a partner can explore the
API interactively within minutes of receiving sandbox credentials.

# Versioning The Spec

Two distinct version numbers exist and must never be conflated:

1. **The API's major contract version** — the `/api/v1/` URL segment. This changes only on a breaking
   change to the wire contract (removed field, changed field type, removed endpoint, changed auth
   scheme) and requires running `/api/v1/` and `/api/v2/` side by side for a deprecation window
   (minimum 12 months, communicated via the `Deprecation` and `Sunset` HTTP headers on every `v1`
   response once `v2` ships).
2. **The spec document's semantic version** — `info.version` in `openapi.yaml` (e.g. `1.4.0`). This
   increments on every merge that changes the spec, independent of whether the API's major version
   changes:
   - **PATCH** (`1.4.0` → `1.4.1`): typo fixes, added `example`, clarified `description` no schema
     or behavior change.
   - **MINOR** (`1.4.1` → `1.5.0`): additive, backward-compatible change new optional field, new
     endpoint, new enum value, new webhook event. Existing generated SDKs continue to compile and
     function unchanged.
   - **MAJOR** (`1.5.0` → `2.0.0`) *within the same* `/api/v1/` URL: reserved for QAYD's internal
     tracking of an accumulating deprecation; QAYD's actual policy is that any wire-breaking change
     forces a new URL major version (`/api/v2/`) rather than a spec-only major bump, so a spec MAJOR
     bump without a URL version change should not occur in practice and is flagged for review if seen.

Every merge to `openapi/` that changes the bundled output is tagged in git as `openapi-v<info.version>`
and the bundled artifact for that tag is archived immutably at
`https://api.qayd.dev/docs/versions/<tag>/openapi.bundled.json`, so a partner pinned to an older MINOR
version can always retrieve the exact contract their integration was built against, and CI can diff any
two tags to produce a human-readable changelog (`openapi-diff` / `oasdiff`) automatically attached to
the release notes.

```bash
oasdiff breaking dist/versions/openapi-v1.4.0.yaml dist/versions/openapi-v1.5.0.yaml
# exits non-zero and lists any breaking change  fails CI if a "MINOR" bump introduced one
```

That `oasdiff breaking` check is what actually enforces the MINOR-must-be-backward-compatible rule
it is not a matter of trusting the PR author's judgment on the version bump.

# Governance/Linting (Spectral)

Every PR touching `openapi/` runs Spectral with a QAYD-specific ruleset (`.spectral.yaml`) extending
`spectral:oas` plus QAYD custom rules that encode the platform conventions from
`DESIGN_CONTEXT.md` directly into machine-enforceable checks:

```yaml
# .spectral.yaml
extends: [[spectral:oas, all]]
rules:
  qayd-envelope-required:
    description: Every 2xx response schema must compose SuccessEnvelope via allOf.
    severity: error
    given: "$.paths[*][*].responses[?(@property.match(/^2/))].content['application/json'].schema"
    then:
      function: schema
      functionOptions:
        schema:
          type: object
          required: [allOf]

  qayd-company-header-required:
    description: Tenant-scoped operations must reference the CompanyIdHeader parameter.
    severity: error
    given: "$.paths[?(!@property.match(/^\\/(auth|system|health)/))][*]"
    then:
      field: parameters
      function: schema
      functionOptions:
        schema:
          type: array
          contains:
            type: object
            properties:
              "$ref": { const: "../../components/parameters.yaml#/CompanyIdHeader" }

  qayd-permission-key-required:
    description: Every non-public operation must declare x-permission.
    severity: error
    given: "$.paths[*][*]"
    then:
      field: x-permission
      function: truthy

  qayd-money-as-decimal-string:
    description: Monetary fields must be typed as string with a 4-decimal pattern, never number/float.
    severity: error
    given: "$.components.schemas[*].properties[?(@property.match(/^(debit|credit|amount|balance|total_.*|.*_amount)$/))]"
    then:
      field: type
      function: pattern
      functionOptions:
        match: "^string$"

  qayd-no-3.0-nullable:
    description: Use 3.1 type-array nulls, not the 3.0 nullable flag.
    severity: error
    given: "$.components.schemas[*].properties[*]"
    then:
      field: nullable
      function: undefined

  qayd-operation-id-required:
    description: Every operation needs a unique camelCase operationId for SDK method naming.
    severity: error
    given: "$.paths[*][*]"
    then:
      field: operationId
      function: pattern
      functionOptions:
        match: "^[a-z][a-zA-Z0-9]+$"
```

CI runs `npx @stoplight/spectral-cli lint dist/openapi.bundled.yaml --fail-severity error` as a required
check on every pull request; `error`-severity violations block merge, `warn`-severity (e.g. missing
`description` on a schema property) are surfaced in the PR but non-blocking. The ruleset itself is
peer-reviewed and versioned in the same repository, so a new platform-wide convention (e.g. a new
required header) is enforced retroactively across every module doc the next time anyone touches an
adjacent path file.

Ownership: the Platform/API guild owns `.spectral.yaml` and `components/`; each domain module team owns
its own `paths/<module>/` and `components/schemas/<module>.yaml` files, reviewed by the Platform/API
guild for convention compliance before merge (a lightweight CODEOWNERS entry routes those paths to
both the module team and the platform team).

# Edge Cases

- **Polymorphic/`oneOf` bodies.** Some resources vary shape by type such as `Invoice` carrying
  different required fields for a standard invoice vs. a recurring-template invoice. These are modeled
  with `oneOf` + `discriminator` (`propertyName: type`), never with a single loosely-typed schema and
  prose explaining "field X only applies when Y" the discriminator lets generators produce a proper
  tagged union in TypeScript/Dart instead of an all-optional bag of fields.
- **Idempotency on financial POSTs.** Endpoints that trigger money movement or posting (`bank.transfer`,
  journal posting, payroll release) accept an `Idempotency-Key` header, modeled as a shared
  `components/parameters.yaml#IdempotencyKeyHeader`, `required: false` but documented with a `409
  Conflict` response (`IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_BODY`) as a defined, spec-level outcome
  rather than an undocumented implementation detail.
- **Bulk operations exceeding pagination norms.** Bulk endpoints (e.g.
  `POST /api/v1/accounting/journal-entries/bulk-post`) accept up to 200 IDs per call (matching
  `per_page` max) and return a per-item result array in `data` (`{id, success, errors}[]`) rather than
  a single pass/fail — modeled as its own `BulkOperationResult` schema, not reusing the single-resource
  response envelope's `data` shape, because a partial-success 200 with row-level errors is a distinct
  contract from "the whole request either fully succeeded or fully failed."
- **Long-running AI operations.** AI-driven actions (e.g. `Forecast Agent` generating a cash-flow
  projection) that exceed a reasonable synchronous timeout return `202 Accepted` with a `data.job_id`
  and are polled via `GET /api/v1/ai/jobs/{job_id}`, or subscribed to via the `ai.finished` webhook /
  Reverb channel `private-company.{id}.ai-jobs` documented in the `webhooks` object rather than the
  client polling blindly with no documented contract for the interim state.
- **Multi-currency numeric precision across the wire.** Because money is JSON `string`, not `number`,
  every generated SDK must NOT auto-coerce these fields to a native float type; the codegen config
  (`x-go-type`/`format` overrides per generator) pins them to `Decimal`/`BigDecimal`/arbitrary-precision
  string types in every target language (`decimal.Decimal` in Python, `Decimal` via `brick/math` in PHP,
  `Decimal` via `decimal` package in Dart, a branded `Money` string type in TypeScript) this is
  enforced by a codegen smoke test, not left to each generator's default `number` mapping.
- **Deprecating a field or endpoint.** A field slated for removal is marked `deprecated: true` in its
  schema with a `description` stating the replacement and the earliest removal spec version; an
  endpoint slated for removal gets `deprecated: true` at the operation level plus a runtime
  `Deprecation: true` / `Sunset: <date>` response header pair, and Spectral's `qayd-deprecation-has-sunset`
  rule fails the build if `deprecated: true` is set without a documented sunset date in `x-sunset`.
- **Spec/route divergence at scale.** Endpoints added directly to Laravel without a spec entry (the
  drift the `spec:verify-drift` job in `# Authoring Workflow` catches) are treated as a P2 defect, not a
  documentation nice-to-have they are invisible to SDK consumers and to contract tests, meaning any bug
  in them can ship undetected by the safety net every other endpoint has.
- **Webhook payload contract vs. resource contract.** A webhook payload (e.g. `invoice.paid`) is
  intentionally NOT identical to the `Invoice` resource schema returned by `GET
  /api/v1/sales/invoices/{id}` it is a smaller, stable "event" shape (`event`, `occurred_at`,
  `company_id`, `data: {id, invoice_number, amount_paid, currency_code}`) that changes far less often
  than the full resource, and is versioned independently under `webhooks` with its own `x-webhook-version`
  extension so that widening the `Invoice` resource schema never forces every existing webhook
  subscriber to re-parse a changed payload.

# End of Document
