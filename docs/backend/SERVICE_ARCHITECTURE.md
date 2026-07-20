# Service Architecture — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: SERVICE_ARCHITECTURE
---

# Purpose

This document defines the service-layer pattern that every QAYD backend module follows. Where
[BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) describes the whole Laravel application and the
request lifecycle, this document specifies the *inside* of step 8 of that lifecycle — what happens
between a validated request and a database write. It is the shared contract that every module's
`*_SERVICE.md` specification instantiates: the same Action/Service shape, the same DTOs, the same
event and job conventions, the same transaction and idempotency rules, and — most importantly — the
same enforcement of the double-entry posting invariant.

The goal is uniformity. A senior accountant service, a bank-transfer service, and a payroll-run
service must be recognizably the same kind of object with the same collaborators, so that any
engineer or AI agent reading one module can predict the structure of the next. This is what makes
the platform's fifty-plus future modules (per
[../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md)) maintainable at scale.

Everything here is Laravel 12 / PHP 8.4+ per [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md).
No pattern in this document requires a package outside the authoritative stack.

# The Core Rule: Thin Controllers, Rich Application Layer

The single most important convention is that **controllers contain no business logic**. A controller
authorizes, delegates, and shapes a response. All orchestration lives in the Application layer, and
all invariants live in the Domain layer. A "fat controller" — one that queries, calculates, or posts
to the ledger — is a defect, not a style choice, because it puts business rules on a code path that
events, queued jobs, and the AI internal API cannot reuse.

Two collaborator types carry the Application layer:

- **Actions** — a single-purpose, invokable use case. One public method (`execute`/`handle`), one
  reason to change. `CreateInvoiceAction`, `PostJournalEntryAction`, `ReleasePayrollRunAction`. An
  Action is the unit that a controller calls, that a queued listener calls, and that the AI internal
  API's proposal-commit calls — one implementation, three callers, one set of rules.
- **Services** — a cohesive object grouping several related operations over one aggregate or concern
  that share private helpers or state. `ReconciliationService`, `ApprovalService`,
  `TaxCalculationService`. A Service is used when operations are too intertwined to split cleanly
  into isolated Actions.

The rule of thumb: reach for an **Action** by default (it composes and tests best); promote to a
**Service** only when several operations genuinely share internals. Both live in `app/{Module}` and
both are resolved from the container, so their dependencies (repositories, the AI-engine client,
other Actions) are injected and mockable.

```
Controller (thin)
   └─ calls ─▶ Action / Service (Application layer)
                   ├─ uses ─▶ Repository / Model (Infrastructure + Domain)
                   ├─ uses ─▶ Domain services & value objects (invariants)
                   ├─ emits ─▶ Domain Events
                   └─ dispatches ─▶ Jobs (queue)
```

# Actions

An Action is a final class with one job. It receives a DTO (never the raw `Request`), does its work
inside a transaction where a write is involved, emits events, and returns a domain object (never an
array or an HTTP response — response shaping is the Resource's job).

```php
namespace App\Actions\Sales;

use App\Data\Sales\InvoiceData;
use App\Events\Sales\InvoiceCreated;
use App\Models\Sales\Invoice;
use App\Models\User;
use App\Repositories\Sales\InvoiceRepository;
use App\Services\Tax\TaxCalculationService;
use Illuminate\Support\Facades\DB;

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
                'branch_id'     => $data->branchId,
                'currency_code' => $data->currencyCode,
                'issue_date'    => $data->issueDate,
                'due_date'      => $data->dueDate,
                'status'        => InvoiceStatus::Draft,
                // company_id, created_by auto-filled by the BelongsToCompany trait
            ]);

            foreach ($data->items as $line) {
                $tax = $this->tax->forLine($line);            // domain calculation
                $invoice->items()->create($line->withTax($tax)->toArray());
            }

            $invoice->recalculateTotals();                     // domain invariant on the aggregate

            event(new InvoiceCreated($invoice, actorId: $actor->id));

            return $invoice->refresh();
        });
    }
}
```

Action conventions:

- **One public entrypoint** (`execute` or `handle`); no side-quests. If an Action needs to do two
  unrelated things, it is two Actions.
- **Input is a DTO**, so the same Action is callable from a controller, a job, a console command, and
  the AI proposal-commit path without depending on HTTP.
- **Output is a domain object.** The caller decides how to present it.
- **Writes are transactional** and emit events *inside* the transaction (events are only dispatched
  to listeners after commit — see Events).
- **Tenant scope is ambient.** The Action never sets `company_id` for isolation; the
  `BelongsToCompany` trait fills it and `CompanyScope` + RLS enforce it (see
  [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md)).

# Domain Services

Domain services hold logic that is not naturally owned by a single Eloquent model and must not be
duplicated across Actions. Tax calculation, currency conversion, three-way match, and the
double-entry posting routine are domain services. They are pure with respect to HTTP and are the home
of business invariants.

```php
namespace App\Services\Accounting;

use App\Domain\Accounting\JournalDraft;
use App\Exceptions\Accounting\UnbalancedEntryException;
use App\Models\Accounting\JournalEntry;
use App\Repositories\Accounting\LedgerRepository;
use Illuminate\Support\Facades\DB;

final class PostingService
{
    public function __construct(private readonly LedgerRepository $ledger) {}

    /** Post a balanced draft to the ledger. Enforces the double-entry invariant. */
    public function post(JournalDraft $draft): JournalEntry
    {
        $this->assertBalanced($draft);                 // invariant — see below

        return DB::transaction(function () use ($draft): JournalEntry {
            $entry = $this->ledger->createEntry($draft->header());
            foreach ($draft->lines() as $line) {
                $this->ledger->appendLine($entry, $line);   // ledger_entries are append-only
            }
            $entry->markPosted();
            return $entry;
        });
    }

    private function assertBalanced(JournalDraft $draft): void
    {
        $debit  = $draft->totalDebit();     // BigDecimal-style money value objects, NUMERIC(19,4)
        $credit = $draft->totalCredit();

        if (! $debit->equals($credit)) {
            throw new UnbalancedEntryException($debit, $credit);   // → 422 balance_mismatch
        }
    }
}
```

# Repository and Query Patterns

Repositories isolate persistence from the Application layer. They are thin over Eloquent — QAYD does
not hide Eloquent behind a generic ORM abstraction, it uses repositories to (1) centralize the
non-trivial queries a module needs, (2) keep tenant-scoping and eager-loading decisions in one place,
and (3) provide a mock seam for Action unit tests.

```php
namespace App\Repositories\Sales;

use App\Models\Sales\Invoice;
use Illuminate\Contracts\Pagination\CursorPaginator;

final class InvoiceRepository
{
    public function create(array $attributes): Invoice
    {
        return Invoice::create($attributes);   // CompanyScope + trait apply automatically
    }

    public function findOrFail(int $id): Invoice
    {
        return Invoice::query()->findOrFail($id);   // cross-tenant id → ModelNotFound → 404
    }

    /** Cursor pagination for high-volume, frequently-mutated collections. */
    public function outstanding(array $filters): CursorPaginator
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Posted)
            ->whereColumn('paid_amount', '<', 'total_amount')
            ->when($filters['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))
            ->orderByDesc('issue_date')
            ->cursorPaginate(perPage: 25);
    }
}
```

Query rules:

- **Never bypass the global scope for tenant reads.** `withoutCompanyScope()` / `forCompany()` exist
  only for the sanctioned platform-analytics path guarded by `RequirePlatformAdmin`; a normal module
  query never uses them.
- **Reads route to the replica** where a read-only intent is declared (reporting queries); writes go
  to the primary. Repositories that expose reporting queries mark them read-only.
- **Every index leads with `company_id`.** Repository queries are written knowing the composite
  `(company_id, …)` indexes exist, so filters stay sargable.
- **N+1 is prevented at the boundary** via `include=` eager-loading decisions surfaced through the
  repository, not scattered `->load()` calls in controllers.

# DTOs (Data Transfer Objects)

DTOs carry validated, typed data from the Presentation layer into the Application layer, decoupling
Actions from the HTTP request. A DTO is an immutable, readonly object built from a FormRequest (HTTP)
or constructed directly (jobs, console, AI proposal-commit).

```php
namespace App\Data\Sales;

use App\Http\Requests\Sales\StoreInvoiceRequest;

final readonly class InvoiceData
{
    /** @param list<InvoiceLineData> $items */
    public function __construct(
        public int $customerId,
        public int $branchId,
        public string $currencyCode,
        public string $issueDate,
        public string $dueDate,
        public array $items,
        public ?string $notes = null,
    ) {}

    public static function fromRequest(StoreInvoiceRequest $request): self
    {
        return new self(
            customerId:   (int) $request->integer('customer_id'),
            branchId:     (int) $request->integer('branch_id'),
            currencyCode: $request->string('currency_code'),
            issueDate:    $request->string('issue_date'),
            dueDate:      $request->string('due_date'),
            items:        InvoiceLineData::collect($request->array('invoice_items')),
            notes:        $request->input('notes'),
        );
    }
}
```

Because the AI proposal-commit path constructs the same DTO from a validated `proposed_payload`
(see [WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md)), an AI-originated invoice and a human-originated
invoice run through byte-for-byte the same Action and invariants.

# Events and Listeners

Events are how modules stay decoupled. An Action emits a domain event describing *what happened* in
its own module; listeners in *other* modules react. Per MODULE_ARCHITECTURE, this is the only
sanctioned way one module triggers work in another — never a direct cross-module write.

Conventions:

- **Events are past-tense facts**: `InvoiceCreated`, `InvoicePosted`, `PaymentReceived`,
  `JournalPosted`, `PayrollReleased`. They carry ids and a minimal payload, not whole models.
- **Cross-module listeners are queued** (`ShouldQueue`) so the triggering request returns fast and a
  slow reaction cannot block a fast write. Same-module, must-be-synchronous reactions (e.g. writing
  an audit row inside the transaction) are handled in the Action itself, not a listener.
- **Events dispatch after commit.** Actions emit inside `DB::transaction`, but Laravel holds queued
  listener dispatch until the transaction commits, so a rolled-back write never fires a downstream
  ledger post. Listeners therefore never observe uncommitted state.
- **Listeners are idempotent** (see Jobs), because the queue guarantees at-least-once delivery.
- **Broadcast events** additionally implement `ShouldBroadcast` and name a company-scoped private
  channel (`private-company.{id}` and its `.ai` / `.approvals` / `.automation` sub-channels).

```php
namespace App\Listeners\Accounting;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Events\Sales\InvoicePosted;
use Illuminate\Contracts\Queue\ShouldQueue;

final class CreateJournalForInvoice implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly PostJournalEntryAction $post) {}

    public function handle(InvoicePosted $event): void
    {
        // Accounting owns the ledger write; Sales only announced the fact.
        $this->post->execute(
            JournalDraft::forInvoice($event->invoiceId),
            idempotencyKey: "invoice:{$event->invoiceId}:posted",
        );
    }
}
```

The canonical domain-event envelope shared across the platform is
`{event_id, event_name, company_id, aggregate_type, aggregate_id, occurred_at, causation_id,
correlation_id, payload}`; the AI-relevant subset is additionally fanned out to the FastAPI engine on
the `events-ai` queue via `NotifyAiLayerListener` (see [../api/INTERNAL_API.md](../api/INTERNAL_API.md)).

# Jobs and Queues

Jobs run deferred or heavy work off the request thread. Every job is tenant-aware and idempotent.

- **Tenant re-binding.** A job is executed by a worker with no ambient request context, so it must
  carry `companyId` and re-establish tenant context in `handle()` — re-binding `tenant.company_id`
  and issuing `SET LOCAL app.current_company_id` — before touching any model. The base
  `TenantAwareJob` centralizes this so a subclass cannot forget it.
- **Idempotency.** A job carries a natural key and checks-then-acts inside a transaction so a retried
  job never double-posts. Money-affecting jobs use the source document key
  (`{document_type}:{document_id}:{event_type}`) as their guard.
- **Named queues** route by priority (`realtime`, `default`, `ai`, `reports`, `integrations`,
  `maintenance` — see BACKEND_ARCHITECTURE).
- **Failure.** Exhausted retries land on `failed_jobs` with the correlation `request_id`; poison
  messages in the event pipeline route to `dead_letter_events`.

```php
namespace App\Jobs\Reports;

use App\Jobs\Concerns\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;

final class GenerateReport extends TenantAwareJob implements ShouldQueue
{
    public string $queue = 'reports';
    public int $tries = 3;
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $companyId,      // required for re-binding
        public readonly int $reportRunId,
    ) {}

    public function handle(): void
    {
        $this->bindTenant($this->companyId);        // SET LOCAL + container bind
        // ... generate, store to R2, mark report_run completed, emit ReportGenerated
    }
}
```

# Policies

Authorization decisions live in Policies, invoked from FormRequests (endpoint gate) and, where a
mid-operation check is needed, from Actions. Policies implement the RBAC grammar `<area>.<action>` /
`<area>.<entity>.<action>` from [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md),
always company-scoped and default-deny.

```php
namespace App\Policies\Sales;

use App\Models\Sales\Invoice;
use App\Models\User;

final class InvoicePolicy
{
    public function create(User $user): bool
    {
        return $user->canInCompany('sales.invoice.create');
    }

    public function void(User $user, Invoice $invoice): bool
    {
        // Maker cannot check their own sensitive action.
        return $user->canInCompany('sales.invoice.void')
            && $invoice->created_by !== $user->id;
    }
}
```

Policies never widen scope: they answer only "may this actor do this in the active company?" The
tenant boundary itself is enforced earlier (middleware + RLS), so a policy never has to re-check
company ownership — a cross-tenant object would already have 404'd at route-model binding.

# Transactions and the Double-Entry Posting Invariant

The most important invariant in the platform is that the ledger always balances and posted records
are immutable. The service layer enforces it structurally, not by convention:

1. **Every financial mutation runs inside a single database transaction.** The document write and its
   ledger effect either both commit or both roll back — there is no window where an invoice exists
   without its journal, or a journal posts without balancing.
2. **Balance is asserted before commit.** `PostingService::assertBalanced` throws
   `UnbalancedEntryException` (rendered `422 balance_mismatch`) if `SUM(debits) != SUM(credits)`,
   using fixed-scale `NUMERIC(19,4)` money value objects — never floats — so rounding cannot silently
   unbalance an entry.
3. **Ledger lines are append-only.** `ledger_entries` are never updated or deleted; a correction is a
   new reversing entry (`/journal-entries/{id}/reverse`), preserving an immutable audit chain.
4. **Posted documents are immutable.** A `PATCH`/`DELETE` on a `posted` journal entry, a released
   payroll run, or a sent invoice past its grace window throws `immutable_record` → `409`; the
   response names the reversal/void action to use instead.
5. **The idempotency row commits in the same transaction as the business record** (see below), so a
   retried money-moving request can never create a second document.

```php
DB::transaction(function () use ($draft, $key) {
    $this->guardIdempotent($key);           // insert idempotency_keys row (unique) — throws on reuse
    $entry = $this->posting->post($draft);  // asserts balance, appends ledger lines, marks posted
    event(new JournalPosted($entry));       // dispatched to listeners only after commit
    return $entry;
});                                          // commit: business record + idempotency row together
```

Because the transaction wraps `SET LOCAL app.current_company_id`, tenant scoping and RLS hold for
every statement inside it even under PgBouncer transaction pooling (the `TransactionalRequest`
companion middleware guarantees the transaction envelope).

# Idempotency in the Service Layer

Idempotency is enforced by the `EnforceIdempotency` middleware for inbound HTTP (before validation),
and re-used as a guard inside Actions for internally-dispatched work. The service layer's obligations:

- **Fingerprint match ⇒ replay.** A completed key with a matching SHA-256 request fingerprint returns
  the stored envelope verbatim (same original `request_id`); the Action never runs a second time.
- **In-progress ⇒ 409 `IDEMPOTENCY_IN_PROGRESS`.** A concurrent retry holding the same key is
  rejected while the first is running (Redis lock, 30s auto-renewed).
- **Fingerprint mismatch ⇒ 409 `IDEMPOTENCY_KEY_CONFLICT`.** Same key, different body never executes.
- **Durable record.** The system of record is the PostgreSQL `idempotency_keys` table, scoped
  `UNIQUE (company_id, route, idempotency_key)`; Redis is only the fast path. Default TTL 24h, 72h for
  the most sensitive operations (`bank.transfer`, `payroll.release`, `tax.submit`).
- **Required scope.** Every money-moving `POST` and sensitive `PATCH` (`journal.post`,
  `payroll.release`, `bank.transfer`) requires the key; AI writes use `Idempotency-Key:
  ai-proposal-<uuid>` with the convention `ai-proposal:{source_event_id}:{agent_type}:{attempt_seq}`.

Full mechanics live in [../api/API_IDEMPOTENCY.md](../api/API_IDEMPOTENCY.md); the service layer's
job is to make the business write and the key row atomic.

# Inter-Service Calls

Services call other services through three sanctioned paths, in order of preference:

1. **Direct Action invocation within the same module** — a Service composing its own module's Actions
   is normal and synchronous.
2. **Domain events across modules** — the default for cross-module effects; asynchronous, decoupled,
   idempotent (see Events).
3. **The internal API to the AI engine** — for reasoning the backend cannot do itself (OCR, analysis,
   forecasting). This is an out-of-process HTTP/queue call through `AiEngineClient`, fire-and-forget
   for notifications (`POST /internal/events`, 202) and synchronous-with-retry for the engine's
   proposal callback (`POST /api/v1/ai/proposals`). The AI engine never calls a Service directly; it
   calls `/api/v1` like any other client and is permission-checked as the acting user.

What a Service must **never** do: query or write another module's tables, new up another module's
model directly for a write, or reach into another module's repository. Those couplings defeat module
independence and are rejected in review.

# Error Handling

Services throw typed domain exceptions; the global handler (BACKEND_ARCHITECTURE) maps them to the
envelope and status code. The service layer never returns error arrays or HTTP responses.

| Thrown by service | Meaning | Rendered |
|---|---|---|
| `UnbalancedEntryException` | debits ≠ credits | 422 `balance_mismatch` |
| `InvalidStateTransitionException` | action not valid for current state | 409 `invalid_state_transition` |
| `ImmutableRecordException` | mutation of a posted/released record | 409 `immutable_record` |
| `InsufficientStockException` | stock would go negative | 422 `insufficient_stock` |
| `CurrencyMismatchException` | cross-currency allocation without opt-in | 422 `currency_mismatch` |
| `IdempotencyKeyReusedException` | same key, different body | 409 `idempotency_key_conflict` |
| `MakerCheckerViolationException` | approver == maker on a sensitive action | 403 `maker_checker_violation` |
| `AiEngineUnavailableException` | FastAPI engine unreachable on an AI-only path | 503 `ai_engine_unavailable` |

Exceptions carry structured context (the two totals, the current and allowed states) so the handler
can populate `errors[].message` in the caller's language without the service knowing about HTTP.

# Observability

Every Action and Service participates in the platform correlation model:

- The `request_id` / `X-Request-Id` (seeded from the originating `domain_events.uuid` where relevant)
  is attached to every log line, event, job, and AI-engine call the operation spawns.
- Structured logs record the actor id, company id, permission checked, and outcome — never the
  monetary payload or PII.
- Prometheus counters track Action execution counts, failure rates by exception type, transaction
  durations, and idempotency replay/conflict rates; Grafana dashboards surface them.
- The audit trail is written inside the same transaction as the state change it records, so business
  history and data can never diverge (see [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)).

# Testing

The layering is chosen for testability:

- **Unit-test Actions** with repositories and the AI-engine client mocked; assert the returned domain
  object, the events emitted, and the transaction boundary.
- **Unit-test Domain services** for invariants in isolation — the posting service rejects an
  unbalanced draft; the tax service computes the right line tax; the state machine refuses illegal
  transitions.
- **Feature-test through `/api/v1`** to prove the thin controller, FormRequest, policy, and envelope
  cooperate, and to prove tenant isolation (company A gets 404 for company B's resource) and
  default-deny (missing permission ⇒ 403).
- **Idempotency tests** assert replay returns the stored envelope and mismatch returns 409, with no
  second side effect.
- **Event/listener tests** assert that posting an invoice dispatches the expected events after commit
  and that listeners are safe to run twice.

A feature is not done until it ships with its migration, API, permissions, audit logging, AI support,
documentation, and tests — the MODULE_ARCHITECTURE release checklist.

# Related Documents

- [BACKEND_ARCHITECTURE.md](./BACKEND_ARCHITECTURE.md) — the overall backend and request lifecycle.
- [WORKFLOW_ENGINE.md](./WORKFLOW_ENGINE.md) — how an approval-gated Action is deferred and committed.
- [AUTOMATION_ENGINE.md](./AUTOMATION_ENGINE.md) — how rule actions reuse the same Actions.
- [../foundation/MODULE_ARCHITECTURE.md](../foundation/MODULE_ARCHITECTURE.md), [../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), [../foundation/PROJECT_STRUCTURE.md](../foundation/PROJECT_STRUCTURE.md)
- [../api/REST_STANDARDS.md](../api/REST_STANDARDS.md), [../api/API_IDEMPOTENCY.md](../api/API_IDEMPOTENCY.md), [../api/INTERNAL_API.md](../api/INTERNAL_API.md)
- [../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md), [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)

# End of Document
