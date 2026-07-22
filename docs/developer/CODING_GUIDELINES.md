# Coding Guidelines — QAYD Developer
Version: 1.0
Status: Design Specification
Module: Developer
Submodule: CODING_GUIDELINES
---

# Purpose

This document is the concrete, per-language specialization of
[../foundation/CODING_STANDARDS.md](../foundation/CODING_STANDARDS.md). Where the foundation standard
states the principles — readable, maintainable, testable, secure; thin controllers; no duplicated
business logic; never trust client input — this document says exactly what that means in QAYD's three
languages and how a reviewer verifies it. It is the reference a contributor keeps open while writing
code, and the checklist behind the "adheres to CODING_GUIDELINES.md" line in every review
([CONTRIBUTING.md](./CONTRIBUTING.md)).

QAYD is a multi-tenant, double-entry accounting core wrapped in an AI layer, split across a Laravel 12
/ PHP 8.4 backend, a Next.js 15 / React 19 / TypeScript frontend, and a FastAPI / Python AI engine,
all speaking one versioned `/api/v1` contract over a PostgreSQL row-level-security tenancy model
([../foundation/TECH_STACK.md](../foundation/TECH_STACK.md)). Three properties override every stylistic
preference in this document, and any rule below exists to serve one of them:

1. **Correctness.** The ledger always balances, money never double-posts, posted records are
   immutable. Money is never a float.
2. **Isolation.** Company A can never read or mutate company B's data. Every query is tenant-scoped;
   there is no such thing as a "quick unscoped query" in shipped code.
3. **Authority & visibility.** Every action is authenticated, authorized against RBAC, validated, and
   audited; every AI-originated figure carries confidence and reasoning and never auto-commits a
   sensitive action.

The rest of this document is these three properties made mechanical.

# Cross-Cutting Rules (all languages)

These hold everywhere, regardless of the file's language.

- **The backend is the only source of truth.** No business rule, money calculation, validation the
  backend also enforces, or permission decision is authoritative on a client. A client-side check
  exists only to make the UI feel instant; the backend re-checks everything.
- **Never trust client input.** Validate at the boundary (FormRequest on the backend, Zod on the
  frontend, Pydantic in the AI engine), and treat unknown external data as unknown until narrowed.
- **Tenancy is ambient, never hand-passed for isolation.** Code relies on the `BelongsToCompany`
  trait, `CompanyScope`, and PostgreSQL RLS — never a manual `where('company_id', …)` as the isolation
  mechanism.
- **Money is fixed-scale decimal, never a float.** `NUMERIC(19,4)` in the database, a decimal/money
  value object in PHP, integer-minor-units or a decimal library on the clients — never IEEE-754.
- **Names carry meaning.** `invoiceRepository`, not `data`; `companyUser`, not `a` (the foundation
  standard's example). A reader should never have to guess what a symbol holds.
- **Comments explain WHY, not WHAT.** The code says what; a comment justifies a non-obvious choice, a
  rounding rule, an invariant. Redundant comments are removed in review.
- **Errors are never swallowed.** Every caught exception is either handled meaningfully or rethrown;
  an empty `catch`, a bare `except: pass`, or a discarded promise rejection is a defect.
- **Files, folders, and identifiers follow the platform naming rules** from
  [../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md): folders `lower_snake`,
  URLs `kebab-case`, database `snake_case`, PHP classes `PascalCase`, variables `camelCase`.

# TypeScript (Frontend)

## Strictness

The frontend compiles under `strict: true`, `noUncheckedIndexedAccess: true`, and `noImplicitAny`.

- **`any` is not permitted in committed code.** External or unshaped data is typed `unknown` and
  narrowed — in practice through the Zod schema that already exists for that resource. A cast to
  `any` to "make the type error go away" is a defect; the type error is information.
- **Narrow, don't assert.** Prefer a type guard or a Zod `parse` over an `as` cast. An `as` cast is a
  promise to the compiler you may not keep; a parse is a checked fact.

```ts
// Bad — silences the compiler, keeps the risk
const total = (row as any).total_amount as number;

// Good — unknown in, narrowed by the schema that mirrors the API resource
const row = TrialBalanceRowSchema.parse(raw); // raw: unknown
const total = row.totalAmount;                // typed, validated
```

## RTL and design tokens are type-and-lint enforced

QAYD ships English and Arabic with full RTL, and its visual identity is a stated product requirement,
so two rules are enforced (by lint and review), not left to taste:

- **Logical CSS properties only.** Spacing, alignment, and borders are logical (`ms-4`, `me-4`,
  `text-start`, `border-s`), never physical (`ml-4`, `text-left`, `border-l`). One component tree
  renders correctly in both directions; there is never a parallel `*.rtl.tsx`. The `rtl:`/`ltr:`
  variant is reserved for the rare asset that must visually flip (a directional chevron).
- **Tokens, not literals.** No component ships a literal hex, rgb, or px value a design token already
  covers. Colors, spacing, radius, and elevation come from the CSS-variable tokens; chart colors come
  from the chart palette, never an ad-hoc hex per chart.

## Naming

| Thing | Convention | Example |
|---|---|---|
| Component files | PascalCase matching the default export; one component per file | `ConfidenceBadge.tsx` → `ConfidenceBadge` |
| Non-component files | kebab-case | `journal-entries.ts`, `use-trial-balance.ts` |
| Route segment folders | plural kebab-case, mirroring the API resource | `accounting/journal-entries/` |
| Dynamic segments | camelCase, named for the entity | `[journalEntryId]`, not `[id]` |
| Hooks | `use` + PascalCase resource noun; verb suffix for a mutation | `useJournalEntries`, `usePostJournalEntry` |
| Zod schemas | `<Entity>Schema`; write-payload type `<Entity>Input` via `z.infer` | `JournalEntrySchema`, `JournalEntryInput` |
| Permission constants | imported from `lib/auth/permissions.ts`, never re-typed as string literals | `PERMISSIONS.ACCOUNTING_JOURNAL_POST` |

## Data, state, and the envelope

Every `/api/v1` call goes through the single fetch wrapper in `lib/api/client.ts`; components and
hooks never call `fetch` directly. That wrapper is the one place the response envelope
(`{ success, data, message, errors, meta, request_id, timestamp }`) is unwrapped and the one place a
typed `ApiError` is thrown, so every caller — Server Component `try/catch` or TanStack Query `onError`
— sees the same shape. State ownership is deliberately narrow: anything from `/api/v1` lives only in
the TanStack Query cache; ephemeral UI state lives only in Zustand; session/company/permissions/locale
live only in React Context. Server data never leaks into Zustand or `useState`.

RBAC gates through `usePermission()` and `<PermissionGate permission="...">`, never a hand-rolled
`user.role === 'admin'` comparison — the string comparison is both a security smell and a maintenance
trap when roles change.

# PHP / Laravel (Backend)

## Thin controllers, rich application layer

This is the single most important backend convention, straight from
[../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md): **a controller contains no
business logic.** It authorizes, delegates to an Action or Service, and shapes a response. A controller
that queries, calculates, or posts to the ledger is a defect — it strands the business rule on a code
path that queued jobs, listeners, and the AI proposal-commit path cannot reuse.

- **Actions** are single-purpose invokable use cases — one public `execute`/`handle`, one reason to
  change (`CreateInvoiceAction`, `PostJournalEntryAction`). Reach for an Action by default.
- **Services** group several intertwined operations over one concern (`ReconciliationService`,
  `TaxCalculationService`). Promote to a Service only when operations genuinely share internals.
- **Domain services** hold invariants not owned by a single model — the posting routine, tax
  calculation, currency conversion — and are pure with respect to HTTP.

An Action receives a DTO (never the raw `Request`), does its work inside a transaction where a write
is involved, emits past-tense domain events *inside* the transaction, and returns a domain object
(never an array or an HTTP response — that is the Resource's job).

```php
final class CreateInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly TaxCalculationService $tax,
    ) {}

    public function execute(InvoiceData $data, User $actor): Invoice
    {
        return DB::transaction(function () use ($data, $actor): Invoice {
            $invoice = $this->invoices->create([
                'customer_id'   => $data->customerId,
                'currency_code' => $data->currencyCode,
                'status'        => InvoiceStatus::Draft,
                // company_id, created_by auto-filled by the BelongsToCompany trait
            ]);
            foreach ($data->items as $line) {
                $invoice->items()->create($line->withTax($this->tax->forLine($line))->toArray());
            }
            $invoice->recalculateTotals();                 // domain invariant on the aggregate
            event(new InvoiceCreated($invoice, actorId: $actor->id));
            return $invoice->refresh();
        });
    }
}
```

## Validation and typed exceptions

- **Validation lives in a `FormRequest`**, never inline in a controller. The FormRequest is also where
  the endpoint authorization gate (`authorize()` → Policy) sits, so a request is authenticated →
  authorized → validated before any Action runs. Its field shape mirrors the frontend Zod schema
  field-for-field.
- **DTOs are `final readonly`**, built from the FormRequest (`fromRequest`) or constructed directly by
  jobs, console, and the AI proposal-commit path — so an AI-originated and human-originated write run
  through byte-for-byte the same Action and the same invariants.
- **Services throw typed domain exceptions**; the global handler maps them to the envelope and status.
  A service never returns an error array or an HTTP response.

| Thrown | Meaning | Rendered |
|---|---|---|
| `UnbalancedEntryException` | debits ≠ credits | 422 `balance_mismatch` |
| `ImmutableRecordException` | mutation of a posted/released record | 409 `immutable_record` |
| `InvalidStateTransitionException` | action invalid for current state | 409 `invalid_state_transition` |
| `MakerCheckerViolationException` | approver == maker on a sensitive action | 403 `maker_checker_violation` |
| `IdempotencyKeyReusedException` | same key, different body | 409 `idempotency_key_conflict` |

## Money and the posting invariant

Money is a fixed-scale value object backed by `NUMERIC(19,4)` — never a float, because a float can
silently unbalance an entry through rounding. Every financial mutation runs inside a single
transaction; balance is asserted before commit; ledger lines are append-only (a correction is a
reversing entry, never an update); posted documents are immutable; and the idempotency row commits in
the same transaction as the business record so a retried money-moving request can never create a second
document. These are structural, not conventional — the service layer enforces them (see
[../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md)).

## Policies

Authorization lives in Policies implementing the RBAC grammar `<area>.<action>` /
`<area>.<entity>.<action>`, always company-scoped and default-deny. A Policy answers only "may this
actor do this in the active company?" — the tenant boundary itself is enforced earlier (middleware +
RLS), so a Policy never re-checks company ownership; a cross-tenant object has already 404'd at
route-model binding.

# Python / FastAPI (AI Engine)

The AI engine reasons, drafts, and proposes; **it never writes to PostgreSQL.** It calls `/api/v1`
like any other client and is permission-checked as the acting user, so every rule about tenancy,
authority, and the posting invariant is enforced by the backend it calls, not re-implemented here.

- **Typed throughout.** `mypy --strict` is a gate. Every function has parameter and return
  annotations; `Any` is avoided the way `any` is on the frontend — external data enters as a Pydantic
  model or `dict[str, object]` and is validated before use.
- **Pydantic models validate every boundary** — inbound events on the `events-ai` queue, the
  request/response of the proposal callback, and every tool's arguments. The proposal request/response
  is validated against the *same* shared JSON Schema the backend tests against, so the two sides of
  the contract cannot drift.
- **Tools are deterministic and pure where possible.** A tool function that computes, normalizes
  confidence, or assembles a prompt takes typed input and returns typed output with no hidden global
  state, so it is unit-testable offline with no live model call.
- **The human-in-the-loop contract is code, not comment.** The engine's output is a *proposal* posted
  to `POST /api/v1/ai/proposals` for human approval; it never commits a sensitive action itself. Every
  proposal carries its confidence score and its reasoning, because the frontend is contractually
  required to render both.
- **Structured logging, never sensitive payloads.** Logs record correlation ids, actor and company
  ids, and outcomes — never monetary payloads or PII.

```python
class ProposalRequest(BaseModel):
    company_id: int
    agent_type: AgentType
    source_event_id: str
    proposed_payload: dict[str, object]   # validated further against the action's schema
    confidence: float = Field(ge=0.0, le=1.0)
    reasoning: str

async def submit_proposal(req: ProposalRequest, client: QaydApiClient) -> ProposalResult:
    # The engine proposes; the backend (and a human) dispose. No DB write here.
    return await client.post("/api/v1/ai/proposals", json=req.model_dump())
```

# Tenancy Discipline (the rule that overrides convenience)

Isolation is the property a customer trusts most, so it gets its own section. In shipped code there is
no unscoped tenant query.

- **Every tenant model uses the `BelongsToCompany` trait**, which adds the `CompanyScope` global scope
  and auto-fills `company_id` on create. A Pest architecture test asserts this for every tenant model;
  a model that forgets the trait fails CI.
- **Never `DB::table()` for a tenant read or write.** It bypasses the global scope and RLS. Repositories
  are thin over Eloquent precisely so the scope always applies; an architecture test forbids
  `DB::table` in repositories.
- **`withoutCompanyScope()` / `forCompany()` are sanctioned only on the platform-analytics path**
  guarded by `RequirePlatformAdmin`. A normal module query that calls them is rejected in review.
- **Every index leads with `company_id`**, so scoped queries stay sargable — write repository queries
  knowing the composite `(company_id, …)` index exists.
- **The frontend and AI engine inherit isolation for free** by never holding database access: they
  call `/api/v1`, which is tenant-scoped on the server. The frontend passes the active company as
  `X-Company-Id`; it never filters by company client-side as a security measure.

The test that proves it, at every layer, is the negative one: company A requesting company B's resource
receives a 404, never a leaked row (the CI RLS negative suite,
[../testing/TESTING_STRATEGY.md](../testing/TESTING_STRATEGY.md)).

# Error Handling and the `/api/v1` Envelope

Every `/api/v1` response — success or failure — is the same envelope:

```json
{
  "success": true,
  "data": {},
  "message": "Human-readable, localized to the caller's language",
  "errors": [],
  "meta": { "pagination": { "cursor": null, "total": 0 } },
  "request_id": "req_...",
  "timestamp": "2026-07-22T12:00:00Z"
}
```

- **The backend never leaks internals.** Domain exceptions map to stable codes and localized messages;
  an unexpected exception renders a generic `500` with the `request_id` for correlation, never a stack
  trace. Structured context on the exception (the two unbalanced totals, the current and allowed
  states) populates `errors[].message` in the caller's language without the service knowing about HTTP.
- **The frontend handles one shape.** `apiFetch` throws a typed `ApiError(message, errors, status,
  request_id)` on `success: false` or a non-2xx status; there is no per-call envelope parsing scattered
  through components.
- **Errors are localized like everything else** — the `message` respects the caller's `Accept-Language`,
  and any user-facing error string exists in both `en` and `ar`.
- **Cursor pagination is uniform**: every list carries `meta.pagination.cursor`; infinite lists page
  with `getNextPageParam: (last) => last.meta.pagination.cursor`.

# Formatting and Linting

Formatting is never argued in review — a formatter owns it, and its check is a required gate
([CONTRIBUTING.md](./CONTRIBUTING.md)). Every rule below runs both locally and in CI, and the two are
identical.

| Language | Formatter | Linter | Type gate |
|---|---|---|---|
| TypeScript | Prettier (`prettier-plugin-tailwindcss` for deterministic class order) | ESLint (`typescript-eslint`, `jsx-a11y`, `react-hooks`) | `tsc --noEmit`, strict |
| PHP | Laravel Pint (PSR-12) | Pint + PHPStan (max) | PHPStan max |
| Python | Black | Ruff | mypy `--strict` |

- **No literal a token already covers.** ESLint plus review keep hex/rgb/px out of components; the
  same spirit keeps magic numbers out of PHP and Python — a named constant or enum instead.
- **Import boundaries are enforced.** A module never imports another module's internals; cross-module
  effects go through domain events (backend) or the `/api/v1` client (frontend, AI). Lint/architecture
  checks reject a cross-module reach.
- **Deterministic ordering.** Tailwind classes (Prettier plugin), imports, and PHP `use` statements are
  ordered by tooling so diffs stay minimal and reviewable.

# Comments and Documentation

- **Comment the WHY.** A comment justifies a rounding rule, an invariant, a non-obvious ordering, a
  workaround with a ticket reference — never restates the line it sits above.
- **Public surfaces are documented in place.** An exported hook, an Action, a tool function, a
  permission constant, and an env var carry a short doc comment stating intent and, for env vars, scope
  — a variable without a stated scope is never merged.
- **A feature is not done without its docs.** A new endpoint, permission key, module, or env var
  updates the relevant `docs/` document in the same PR (the definition of done in
  [CONTRIBUTING.md](./CONTRIBUTING.md)). Documentation drift is treated as a bug, because in QAYD the
  documents are the specification the code implements.
- **AI-authored code is held to the identical bar.** An AI coding agent follows every rule in this
  document; "the AI wrote it" is never a reason a gate is relaxed or a review is lighter.

# Related Documents

- [../foundation/CODING_STANDARDS.md](../foundation/CODING_STANDARDS.md) — the platform coding
  principles this document specializes.
- [../backend/SERVICE_ARCHITECTURE.md](../backend/SERVICE_ARCHITECTURE.md) — the full Action/Service/DTO
  pattern, the double-entry posting invariant, and the idempotency rules summarized here.
- [../frontend/README.md](../frontend/README.md) — the frontend conventions (RSC vs. Client Components,
  data/state ownership, i18n/RTL) this document's TypeScript section draws on.
- [CONTRIBUTING.md](./CONTRIBUTING.md) — the branch/commit/PR workflow and the CI gates that enforce
  these guidelines.
- [LOCAL_SETUP.md](./LOCAL_SETUP.md) — running the linters, type gates, and test suites locally.
- [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md),
  [../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md) — the RBAC grammar and naming
  rules referenced throughout.

# End of Document
