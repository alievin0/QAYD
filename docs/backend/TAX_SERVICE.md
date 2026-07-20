# Tax Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: TAX
---

# Purpose

The Tax Service is the backend application layer that implements the Tax Management module described
functionally in [../accounting/TAX.md](../accounting/TAX.md). Where that document defines *what* the
module does — jurisdictions, rates, reverse charge, partial exemption, statutory returns — this
document specifies *how* the Laravel 12 / PHP 8.4 backend realizes it: the `TaxCalculationService`, the
Actions, DTOs, repositories, jobs, events, and policies between a validated `/api/v1/tax/*` request and
a committed database write, following the shared contract in
[./SERVICE_ARCHITECTURE.md](./SERVICE_ARCHITECTURE.md).

The Tax Service is the single authoritative subsystem that determines, calculates, records, reports, and
files every tax obligation a tenant company incurs. Every domain module — Sales, Purchasing, Payroll,
Inventory, Banking — calls into it through one versioned calculation contract
(`POST /api/v1/tax/calculate`) at the moment a taxable event occurs, and embeds the deterministic,
effective-dated result in its own document. The Tax Service is the sole writer of the tax configuration
and fact tables (`tax_jurisdictions`, `tax_registrations`, `tax_categories`, `tax_codes`, `tax_rates`,
`tax_rules`, `tax_groups`, `exemption_certificates`, `recovery_ratios`, `tax_transactions`,
`tax_returns`, `tax_return_lines`).

It does **not** own the ledger. Committed tax amounts post as balanced journal lines through the
Accounting Service ([../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md)) — batched into
the same journal entry as the source document's non-tax lines — via domain events; the Tax Service never
writes `journal_entries`/`journal_lines` directly.

Three principles dominate the design and are enforced structurally:

1. **Tax is derived, never authored twice.** A tax amount is a deterministic function of (taxable base,
   applicable rate as of the tax-point date, rounding rule, compounding order). Users pick a `tax_code`
   (or a `tax_rule` infers one); the engine computes the rate and amount. It is never a free-text number.
2. **The engine never silently charges zero.** A missing rate raises `TaxRateNotConfiguredException`
   (rendered 422), never a silent 0% — the single most common real-world tax bug, designed out.
3. **Filing is irreversible; two human gates guard it.** `tax_returns` moves `draft → under_review →
   ready_to_file → filed`, with `tax.filing.approve` then `tax.submit` both requiring a named human. No
   AI agent, automation, or scheduled job may perform the `ready_to_file → filed` transition.

All amounts are `NUMERIC(19,4)`; the platform base currency defaults to KWD.

# Responsibilities

The Tax Service is responsible for:

- **Jurisdiction and registration configuration.** Maintaining the self-referencing
  `tax_jurisdictions` tree (country → state/province → city/municipality → custom region) and one
  `tax_registrations` row per `(company_id, jurisdiction_id, system_type)`, each with its own
  `filing_frequency`, basis, currency, and registration number.
- **Rate/rule configuration.** Maintaining effective-dated, non-overlapping `tax_rates`
  (`percentage` / `per_unit` / `bracket`), the `tax_rules` priority-ordered decision logic (including
  `is_reverse_charge`), `tax_groups` for compound tax-on-tax, `exemption_certificates`, and partial-
  exemption `recovery_ratios`.
- **Deterministic calculation.** Running `TaxCalculationService` in `preview` (no persistence) and
  `commit` (persists `tax_transactions`) modes for every transaction type — sales, purchases, payroll,
  inventory deemed-supply, imports, exports, services, digital goods, subscriptions, refunds, credit/
  debit notes, manual adjustments.
- **The tax fact ledger.** Writing immutable `tax_transactions` rows with full `calculation_lineage`,
  `direction`, recovery split, reverse-charge pairing, and a frozen rate snapshot.
- **Statutory returns.** Generating `tax_returns` + box-mapped `tax_return_lines` (ZATCA VAT, UAE VAT
  201, and other country forms) and driving the four-state return lifecycle behind two human gates.
- **Compliance calendar.** Deriving due dates from `filing_frequency` + `due_date` and firing
  notifications; flagging expiring exemption certificates and nexus threshold crossings.
- **AI surface.** Exposing read + propose-only endpoints the Tax Advisor agent
  ([../ai/agents/TAX_AGENT.md](../ai/agents/TAX_AGENT.md)) calls under a scoped service account holding
  only `tax.read` / `tax.calculate` / `tax.filing.prepare` — never `tax.filing.approve` or `tax.submit`.

The service does **not** decide legal tax positions in ambiguous cases; it encodes the positions a
company's tax team configured, applies them deterministically, and surfaces exceptions for a human. It
is a calculation and compliance engine, not a legal-opinion engine.

# Domain Model

- **`TaxJurisdiction`** — a node in the jurisdiction tree; `region_type` (`country`, `state`,
  `province`, `city`, `municipality`, `custom`), `parent_id`, `sourcing_rule` (`origin` / `destination`
  / `mixed`), and a `compliance_profile` JSONB describing mandatory invoice fields, QR formats, and
  numbering per country. US-style stacking resolves as a *set* of nodes (state + county + city +
  district), not a single row; custom regions (free zones, customs unions) contribute *override* rules.
- **`TaxRegistration`** — the company's registration in one jurisdiction/system, with `filing_frequency`
  (monthly/quarterly/annual), basis (accrual/cash), and filing currency. Routes each document's tax
  lines to the correct registration by the issuing `branch_id`.
- **`TaxCategory` → `TaxCode` → `TaxRate`** — the layered configuration. A category carries the
  `system_type` enum (`vat`, `gst`, `sales_tax`, `use_tax`, `withholding`, `corporate_tax`,
  `income_tax`, `payroll_tax`, `custom`, `excise`, `digital_services`) and `calculation_basis`. A code
  is the stable human-facing identifier (`SA-VAT-STD`) independent of any rate value. A rate is the
  effective-dated number, exclusion-constrained against overlap.
- **`TaxRule`** — priority-ordered match logic (JSONB `conditions` validated against a fixed schema,
  never raw SQL) mapping transaction attributes to a resulting `tax_code` (or a reverse-charge pair).
- **`TaxGroup` / `TaxGroupItem`** — compound/stacked bundles with `sequence` and
  `apply_on_running_total` for tax-on-tax (e.g. VAT on an excise-inclusive base).
- **`ExemptionCertificate`** — customer/vendor exemption override with validity window and attachment.
- **`RecoveryRatio`** — the partial-exemption recoverable percentage per fiscal year.
- **`TaxTransaction`** — the immutable core fact: `tax_direction`, `taxable_base_amount`,
  frozen `rate_snapshot_*`, `recoverable`/`non_recoverable` split, `reverse_charge_pair_id`, and full
  `calculation_lineage`.
- **`TaxReturn` / `TaxReturnLine`** — the statutory filing and its box-mapped lines.

Value objects: `Money` (`NUMERIC(19,4)`), `TaxRateSnapshot` (frozen at calculation time),
`JurisdictionChain` (the resolved set), and `CalculationLineage` (the JSONB resolution trace).

# Key Classes

Classes live under `app/Tax/*`. The centerpiece is a **Service** (not an Action) because its operations
share substantial internals — jurisdiction resolution, rule evaluation, rate lookup, compounding,
recovery — that cannot be split cleanly.

- `TaxCalculationService` — the stateless-per-call engine behind `POST /api/v1/tax/calculate`. Takes a
  `TaxCalculationRequest` DTO, returns a `TaxCalculationResult` DTO, and persists `tax_transactions`
  only in `commit` mode.
- `JurisdictionResolver` — walks the ship-from/ship-to chains against active registrations and
  `sourcing_rule` to produce the applicable `TaxJurisdiction` set.
- `TaxRuleEvaluator` — evaluates `tax_rules` in `priority` order; first match wins; may resolve to a
  `tax_group`.
- `TaxRateService` — effective-dated rate lookup and `scheduleRateChange()` (atomic close-old/open-new).
- `ReverseChargeCalculator` / `RecoveryRatioApplier` — the reverse-charge pair and partial-exemption
  split.

**Return-lifecycle Actions** (each a single-purpose invokable):

- `GenerateDraftReturnAction` — builds a `draft` return and its box-mapped lines from `tax_transactions`.
- `SubmitReturnForReviewAction` — `draft → under_review`.
- `ApproveReturnAction` — `under_review → ready_to_file` (first human gate, `tax.filing.approve`).
- `FileReturnAction` — `ready_to_file → filed` (second human gate, `tax.submit`; human-only).

The calculation engine's rate-lookup step, showing the never-silently-zero rule:

```php
namespace App\Tax\Services;

use App\Tax\Data\TaxCalculationRequest;
use App\Tax\Data\TaxCalculationResult;
use App\Tax\Exceptions\TaxRateNotConfiguredException;
use App\Tax\Repositories\TaxRateRepository;
use Illuminate\Support\Facades\DB;

final class TaxCalculationService
{
    public function __construct(
        private readonly JurisdictionResolver $jurisdictions,
        private readonly TaxRuleEvaluator $rules,
        private readonly TaxRateRepository $rates,
        private readonly ReverseChargeCalculator $reverseCharge,
        private readonly RecoveryRatioApplier $recovery,
    ) {}

    public function calculate(TaxCalculationRequest $req): TaxCalculationResult
    {
        $result = TaxCalculationResult::empty();

        foreach ($req->lines as $line) {
            $set  = $this->jurisdictions->resolve($req, $line);         // step 1
            $base = $this->baseAmount($line);                           // step 2 (inclusive/exclusive)
            $code = $this->rules->firstMatch($req, $line, $set);        // step 3 — first matching rule wins

            // step 4 — the effective-dated rate that was in force on the tax-point date.
            $rate = $this->rates->effectiveOn($code->id, $set->primaryId(), $req->taxPointDate);
            if ($rate === null) {
                // Never charge silent zero for an unconfigured product/jurisdiction.
                throw new TaxRateNotConfiguredException($code, $set, $req->taxPointDate); // → 422
            }

            $amount = $this->apply($base, $rate, $code);                // steps 5–6, with lineage
            $result->push($line->ref, $amount->withLineage());
        }

        if ($req->mode === 'commit') {
            DB::transaction(fn () => $this->persistTransactions($result, $req)); // writes tax_transactions
        }
        return $result;
    }
}
```

The rate snapshot (`rate_snapshot_percentage` / `rate_snapshot_per_unit`) is frozen onto the
`tax_transactions` row at commit; a later `tax_rates` change never recalculates a historical
transaction. Domain modules call this service twice: `preview` while a user drafts a document (live
totals in the Next.js UI), and `commit` when the document posts.

**DTOs.** The engine's boundary is two readonly DTOs so the same code serves an HTTP caller, a domain
module's internal call, and the AI proposal path:

```php
namespace App\Tax\Data;

final readonly class TaxCalculationRequest
{
    /** @param list<TaxLineData> $lines */
    public function __construct(
        public string $mode,               // 'preview' | 'commit'
        public string $transactionType,    // 'sale' | 'purchase' | 'import' | 'export' | 'payroll' | ...
        public string $taxPointDate,       // resolves the effective-dated rate
        public string $currencyCode,
        public ?JurisdictionRef $shipFrom,
        public ?JurisdictionRef $shipTo,
        public ?CounterpartyRef $customer,
        public ?CounterpartyRef $vendor,
        public array $lines,
    ) {}
}
```

`TaxCalculationResult` carries, per line, the `taxable_base_amount`, resolved `tax_code`, jurisdiction,
frozen rate, `tax_amount`, and the full `calculation_lineage` (matched rule id, evaluated conditions,
jurisdiction chain walked, rounding applied) — so a result is fully explainable without a second query.

**Per-transaction-type specialization.** The generic seven-step algorithm specializes by
`transaction_type` without forking: `sale` uses the ship-to jurisdiction and accrues Output Tax at
invoice (accrual) or receipt (cash) posting; `purchase` accrues Input Tax subject to the recovery
ratio and cross-checks the vendor's registration status; `payroll` runs twice per employee (bracket-
based income-tax withholding on YTD earnings, plus employer+employee payroll-tax legs capped at
`wage_ceiling`); `import` self-assesses import VAT as a reverse-charge pair and books customs duty to
landed cost, not a tax account; `export` and B2B cross-border services zero-rate (in-scope, full input
recovery preserved); `refund`/`credit_notes`/`debit_notes` mirror the *original* transaction's rate and
lineage, never the current rate, to avoid an unreconciled variance in the tax account.

# Endpoints Backed

Every endpoint is under `/api/v1/tax/`, authenticated with a Sanctum/JWT bearer token, scoped to the
active company by the `X-Company-Id` header, returning the standard envelope (`success`, `data`,
`message`, `errors`, `meta.pagination`, `request_id`, `timestamp`) per
[../api/REST_STANDARDS.md](../api/REST_STANDARDS.md). Controllers are thin; each calls one
Action/Service.

| Method | Path | Permission | Backed by |
|---|---|---|---|
| GET | `/tax/jurisdictions` | `tax.read` | `TaxJurisdictionRepository::tree` |
| POST/PUT | `/tax/jurisdictions[/{id}]` | `tax.jurisdiction.manage` | `UpsertJurisdictionAction` |
| GET | `/tax/registrations` | `tax.read` | `TaxRegistrationRepository::paginate` |
| POST | `/tax/registrations` | `tax.registration.manage` | `RegisterCompanyAction` |
| GET | `/tax/categories` | `tax.read` | `TaxCategoryRepository::paginate` |
| POST | `/tax/categories` | `tax.config.manage` | `CreateCategoryAction` (with GL account mapping) |
| GET/POST/PUT | `/tax/codes[/{id}]` | `tax.read` / `tax.config.manage` | `TaxCodeRepository` / `UpsertTaxCodeAction` |
| GET | `/tax/rates` | `tax.read` | `TaxRateRepository::filter` (`as_of_date`) |
| POST | `/tax/rates` | `tax.rate.manage` | `CreateRateAction` |
| POST | `/tax/rates/schedule-change` | `tax.rate.manage` | `TaxRateService::scheduleRateChange` (atomic) |
| GET/POST | `/tax/rules[/{id}/reorder]` | `tax.read` / `tax.rule.manage` | `TaxRuleRepository` / `UpsertRuleAction` / `ReorderRulesAction` |
| GET/POST | `/tax/groups` | `tax.read` / `tax.config.manage` | `TaxGroupRepository` / `CreateGroupAction` |
| GET/POST | `/tax/exemption-certificates` | `tax.read` / `tax.exemption.manage` | `ExemptionCertificateRepository` / `RegisterCertificateAction` |
| GET/POST | `/tax/recovery-ratios` | `tax.read` / `tax.recovery.manage` | `RecoveryRatioRepository` / `SetRecoveryRatioAction` |
| POST | `/tax/calculate` | `tax.calculate` | `TaxCalculationService::calculate` (preview/commit) |
| GET | `/tax/transactions` | `tax.read` | `TaxTransactionRepository::filter` |
| POST | `/tax/transactions/{id}/reverse` | `tax.adjust` | `ReverseTaxTransactionAction` |
| POST | `/tax/adjustments` | `tax.adjust` | `CreateManualAdjustmentAction` (reason + document required) |
| GET | `/tax/returns[/{id}]` | `tax.filing.read` | `TaxReturnRepository` |
| POST | `/tax/returns` | `tax.filing.prepare` | `GenerateDraftReturnAction` |
| POST | `/tax/returns/{id}/submit-for-review` | `tax.filing.prepare` | `SubmitReturnForReviewAction` |
| POST | `/tax/returns/{id}/approve` | `tax.filing.approve` | `ApproveReturnAction` (**first human gate**) |
| POST | `/tax/returns/{id}/file` | `tax.submit` | `FileReturnAction` (**second human gate, human-only**) |
| POST | `/tax/returns/{id}/record-ack` | `tax.submit` | `RecordAcknowledgmentAction` |
| GET | `/tax/reports/vat-report` | `reports.export` + `tax.filing.read` | `VatReportBuilder` |
| GET | `/tax/reports/liability` | `reports.export` + `tax.filing.read` | `LiabilityReportBuilder` |
| GET | `/tax/reports/audit-report` | `reports.export` + `tax.audit.read` | `AuditReportBuilder` |
| POST | `/tax/validate` | `tax.read` | `TaxValidationService::check` (no persist) |
| POST/GET | `/tax/import` / `/tax/export` | `tax.config.manage` / `tax.read` | `CountryPackImporter` / `ConfigExporter` |
| POST | `/tax/bulk/recalculate` | `tax.adjust` | `BulkRecalculateJob` (preview-only, never commits) |
| GET/POST | `/tax/ai/proposals[/{id}/approve\|reject]` | `tax.read` / `tax.config.manage` | `AiProposalRepository` / `ApplyProposalAction` |

A `preview` calculation request and its lineage-bearing response:

```json
POST /api/v1/tax/calculate
{ "mode": "preview", "transaction_type": "sale", "transaction_date": "2026-07-16",
  "currency_code": "SAR", "ship_to": { "jurisdiction_code": "SA-RIY" },
  "customer": { "id": 4821, "tax_status": "registered" },
  "lines": [ { "line_ref": "L1", "product_id": 9931, "product_tax_treatment": "standard",
               "quantity": 4, "unit_price": 250.0000, "price_treatment": "exclusive" } ] }
```

```json
{ "success": true,
  "data": { "lines": [ { "line_ref": "L1", "taxable_base_amount": "1000.0000",
      "tax_code": "SA-VAT-STD", "jurisdiction": "SA", "tax_rate_percentage": "15.0000",
      "tax_amount": "150.0000", "line_total": "1150.0000",
      "rule_matched": { "tax_rule_id": 12, "priority": 10 } } ] },
  "request_id": "req_01J...", "timestamp": "2026-07-16T09:14:22Z" }
```

# Database Tables Owned

The Tax Service is the sole writer of the following (full DDL in
[../accounting/TAX.md](../accounting/TAX.md) → Database Design). Every table carries the platform
standard columns; every index leads with `company_id`; posted tax rows are immutable and never
hard-deleted.

| Table | Role | Key constraints the service relies on |
|---|---|---|
| `tax_jurisdictions` | Self-referencing jurisdiction tree | `code` unique per company; `region_type`; `sourcing_rule` |
| `tax_registrations` | Per `(company, jurisdiction, system_type)` registration | own `filing_frequency`, basis, currency |
| `tax_categories` | `system_type` + `calculation_basis`; GL account FKs | maps `output/input/withholding/use/expense` accounts |
| `tax_codes` | Stable human-facing identifier | belongs to one category |
| `tax_rates` | Effective-dated numeric rate | `EXCLUDE USING gist` — no overlap per `(tax_code_id, jurisdiction_id)` |
| `tax_rules` | Priority-ordered decision logic | JSONB `conditions` validated by FormRequest, never raw SQL |
| `tax_groups` / `tax_group_items` | Compound/stacked bundles | `sequence`, `apply_on_running_total` |
| `exemption_certificates` | Customer/vendor exemption override | `valid_from`/`valid_to`; expired auto-excluded |
| `recovery_ratios` | Partial-exemption recoverable % | `recovery_rate NUMERIC(5,2)`, `approved_by` |
| `tax_transactions` | Immutable core fact; `calculation_lineage` JSONB | `recoverable + non_recoverable = tax_amount` for input directions |
| `tax_returns` | Statutory filing + lifecycle | `UNIQUE (tax_registration_id, period_start, period_end)` |
| `tax_return_lines` | Box-mapped lines | `UNIQUE (tax_return_id, line_code)`; `box_number` maps to the form |

The exclusion constraint is the structural guarantee that two rates can never both claim a given date:

```sql
-- tax_rates: no two active rates for the same code+jurisdiction may overlap in time
EXCLUDE USING gist (
  tax_code_id      WITH =,
  jurisdiction_id  WITH =,
  daterange(effective_from, COALESCE(effective_to, 'infinity'::date), '[]') WITH &&
)
```

`TaxRateService::scheduleRateChange()` closes the prior row's `effective_to` and inserts the new row in
one transaction so the constraint never sees a transient overlap or gap.

The service reads but never writes: `accounts` (Accounting), `journal_entries`/`journal_lines`
(Accounting), `invoices`/`credit_notes` (Sales), `bills`/`debit_notes` (Purchasing), `payroll_runs`
(Payroll), `stock_adjustments`/`stock_transfers` (Inventory), `attachments` (foundation),
`exchange_rates` (shared FX).

# Multi-Tenancy Enforcement

Tenancy is ambient and defence-in-depth per
[../database/MULTI_TENANCY.md](../database/MULTI_TENANCY.md):

- **Ambient `company_id`.** Every model uses `BelongsToCompany`; `CompanyScope` + PostgreSQL RLS
  (`app.current_company_id`) enforce isolation on every statement. A cross-tenant id → 404.
- **Per-country registration, one tenant.** `tax_registrations` scoped to `(company_id, jurisdiction_id,
  system_type)` lets one `companies` row hold a Kuwait corporate-tax registration and a KSA VAT
  registration simultaneously, each routed by the issuing `branch_id` — all within one tenant boundary.
- **Consolidation never merges filings.** A holding company can request a consolidated Tax Summary
  across its branches for management visibility, but every statutory return is generated and filed per
  legal entity per jurisdiction; QAYD never merges two legal entities' filings, and never posts a
  journal spanning two `company_id` values.
- **AI service account is tenant- and permission-scoped.** The Tax Advisor runs one scoped session per
  company (`actor_type = 'ai_agent'`), reads only that company's data, and holds only read/calculate/
  filing-prepare. It is structurally ineligible for `tax.filing.approve` and `tax.submit`.
- **Country-pack import stays in-tenant.** `CountryPackImporter` inserts jurisdiction/code/rate/rule
  rows only into the active company via the same scoped path — installing a country pack is a
  data-loading exercise, not a code change, and never touches another tenant.

# Events, Queues & Realtime

The service emits past-tense domain events inside the write transaction; queued listeners dispatch only
after commit. The canonical envelope is `{event_id, event_name, company_id, aggregate_type,
aggregate_id, occurred_at, causation_id, correlation_id, payload}`.

**Domain events:** `tax.calculated` (preview/commit), `tax.committed` (transactions persisted),
`tax.return.generated`, `tax.return.approved`, `tax.return.filed`, `tax.certificate.expiring`,
`tax.nexus.threshold_crossed`.

The Accounting core's posting listener consumes `tax.committed` to generate balanced journal lines —
never a separate, later "tax catch-up" entry; the tax lines batch into the same journal entry as the
source document's non-tax lines.

**Queues:**

| Queue | Tax work routed here |
|---|---|
| `realtime` | Reverb broadcasts (live preview totals, return-status changes) |
| `default` | Post-commit posting listeners, compliance-calendar notifications |
| `ai` | `NotifyAiLayerListener` fan-out on `events-ai`; Tax Advisor classification/anomaly proposals |
| `reports` | VAT/liability/audit report generation, `BulkRecalculateJob` reconciliation sweeps |
| `maintenance` | Nightly `TaxIntegrityCheckJob` (tax_transactions vs `ledger_entries` divergence > 0.01) |

**Realtime.** Broadcast events use the company-scoped private channel `private-company.{id}` and its
`.approvals` sub-channel so a Tax Manager sees a return move `under_review → ready_to_file` live. Preview
`tax.calculated` events drive the Next.js document editor's live totals.

**Compliance calendar.** `ComplianceCalendarJob` runs daily, derives each open registration's next
`due_date` from its `filing_frequency`, and fires `tax.return.due_soon` notifications on a configurable
lead schedule; `ExemptionCertificateExpiryJob` notifies the Tax Manager 30 days before a certificate's
`valid_to`. Neither job may transition a return into `filed` — that gate is human-only.

```php
namespace App\Tax\Listeners;

use App\Tax\Events\TaxCommitted;
use App\Accounting\Actions\PostJournalEntryAction;    // Accounting owns the ledger
use App\Accounting\Data\JournalDraft;
use Illuminate\Contracts\Queue\ShouldQueue;

final class PostTaxToLedger implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly PostJournalEntryAction $post) {}

    public function handle(TaxCommitted $event): void
    {
        // Tax announced the fact; Accounting posts Output/Input Tax lines into the source document's entry.
        $this->post->execute(
            JournalDraft::forTaxTransactions($event->sourceDocumentType, $event->sourceDocumentId),
            idempotencyKey: "tax:{$event->sourceDocumentType}:{$event->sourceDocumentId}:committed",
        );
    }
}
```

# Integrations

- **Accounting Service** ([../backend/ACCOUNTING_SERVICE.md](../backend/ACCOUNTING_SERVICE.md)) — the
  only path to the ledger. Tax emits `tax.committed`; Accounting posts Output/Input/Withholding/Use tax
  control-account lines (worked examples — KSA sale, recoverable purchase, reverse-charge import — are in
  TAX.md → Accounting Integration). Non-recoverable input tax posts to Tax Expense, not Input Tax
  Receivable. Tax never duplicates the posting engine.
- **Sales / Purchasing / Payroll / Inventory** — each calls `POST /tax/calculate` at its taxable event.
  Purchasing's three-way match also validates the bill's `tax_code` against the PO's product/vendor
  combination, surfacing a mismatch as a warning. Inventory deemed-supply and cross-border-transfer tax
  events post through their own commit events; customs duty capitalizes as landed cost, never a tax
  account.
- **Banking Service** ([./BANKING_SERVICE.md](./BANKING_SERVICE.md)) — a net-VAT remittance is an
  ordinary `bank_transactions`/`transfers` row debiting the tax-payable account. Tax can *prepare* the
  payment instruction from the Liability report's "Record Payment" action, but the transfer runs the
  same `bank.transfer` human two-key chain; Tax can never execute the transfer itself.
- **Government e-filing** — `FileReturnAction` submits to the jurisdiction's e-filing API where one
  exists (ZATCA, UAE FTA) and stores the acknowledgment receipt verbatim in `government_ack_payload`;
  a successful ack is a precondition of the `filed` state for e-filing jurisdictions.
- **FastAPI AI engine** ([../api/INTERNAL_API.md](../api/INTERNAL_API.md)) — the Tax Advisor calls
  `/api/v1/tax/*` like any client, permission-checked as its scoped service account; classification,
  anomaly, and draft-return proposals arrive back through `/tax/ai/proposals` and the normal FormRequest
  + audit path, never a direct DB write. See [../ai/workflows/TAX_FILING.md](../ai/workflows/TAX_FILING.md).

# Return Lifecycle and the Two Human Gates

`tax_returns.status` is a hard state gate the service enforces at three layers simultaneously — Laravel
policy, state-machine transition guard, and UI:

```
draft ── submit-for-review ──▶ under_review ── approve ──▶ ready_to_file ── file ──▶ filed ──▶ accepted | rejected | amended
                (tax.filing.prepare)        (tax.filing.approve)        (tax.submit)
```

- `GenerateDraftReturnAction` builds the `draft` and its box-mapped `tax_return_lines` from
  `tax_transactions` for the period; the return's `tax_return_line_id` back-references lock in which
  transactions it covers when it leaves `draft`.
- **First human gate — `ApproveReturnAction` (`tax.filing.approve`).** `under_review → ready_to_file`;
  requires a named human and an explicit approval record.
- **Second human gate — `FileReturnAction` (`tax.submit`).** `ready_to_file → filed`; the state-machine
  guard rejects any request bearing only a service/AI-layer token outright, regardless of permission
  grant. Requires (a) a human holding `tax.submit`, (b) an approval record with reason/notes, and (c)
  for e-filing jurisdictions, a stored government acknowledgment. No AI agent, automation, or scheduled
  job can perform this transition.

The AI Advisor may draft (`tax.filing.prepare`) and move `draft → under_review`, but the two approval
transitions are structurally beyond it.

**Reverse charge and partial exemption, in the fact row.** Both are first-class, not bolt-ons. A
`tax_rules` row resolving `is_reverse_charge = true` makes `ReverseChargeCalculator` write a matched
pair of `tax_transactions` on the buyer's own books — one `output_self_assessed` and one
`input_reverse_charge` — joined by `reverse_charge_pair_id` and posted in the same journal entry, so the
net GL impact is zero at full recovery and equal to the non-recoverable portion under partial exemption.
`RecoveryRatioApplier` splits every non-directly-attributable input line into `recoverable_amount =
input_tax × recovery_rate` and `non_recoverable_amount = input_tax × (1 − recovery_rate)`, posting the
non-recoverable portion to Tax Expense; the database enforces
`recoverable_amount + non_recoverable_amount = tax_amount` for input directions. The provisional annual
ratio is trued up at year-end as a `tax_return_lines` row of `line_type = 'partial_exemption_adjustment'`.

# GCC Tax Posture

The engine describes *mechanism*; statutory rates are data, shipped as versioned country packs
(`php artisan tax:install-country-pack {code}`), not code. QAYD ships packs for Kuwait, Saudi Arabia,
UAE, Bahrain, Oman, and Qatar. Adding a country is inserting `tax_jurisdictions` / `tax_categories` /
`tax_codes` / `tax_rates` / `tax_rules` rows — never a PHP release.

| Jurisdiction | Posture (as configured in the country pack, per TAX.md) |
|---|---|
| Kuwait (base, KWD) | VAT framework provisioned but **dormant**; the default treatment is out-of-scope / 0% headline. Corporate tax on foreign corporate bodies' profits is a data-only pack. |
| Saudi Arabia | VAT standard rate under ZATCA with e-invoicing (QR + mandatory fields + previous-invoice hash chain via `compliance_profile`); withholding, corporate tax, and Zakat as data-only packs. |
| UAE | VAT standard rate under the FTA; VAT 201 box mapping in `tax_return_lines.box_number`; free-zone designated-zone overrides via custom-region rules. |
| Bahrain / Oman / Qatar | VAT/framework packs shipped as data. |
| Cross-GCC | Corporate tax, withholding, Zakat, payroll tax (PIFSS/GOSI-style employer+employee legs), excise (ad-valorem and per-unit), and digital-services tax are all modeled as data-only `system_type` packs with their own rates. |

This document does not assert specific numeric rates beyond what the source packs configure; the
service resolves whatever the effective-dated `tax_rates` rows say for the tax-point date. Kuwait's
dormant VAT means most Kuwaiti transactions resolve to an out-of-scope/exempt code — but through the
same engine, so switching it on later is a rate/rule data change, not a code change.

**Country-pack import as configuration.** `CountryPackImporter` consumes the versioned JSON fixture
(`tax:install-country-pack {code}`) and inserts `tax_jurisdictions`, `tax_categories`, `tax_codes`,
`tax_rates`, and `tax_rules` for the active company inside one transaction. The importer validates that
inserted rates do not violate the exclusion constraint and that every code maps to a category with the
correct `system_type`, so a malformed pack fails atomically rather than half-loading. The same fixture
format is what an implementation partner uses to add a new country without touching PHP — the strongest
expression of "jurisdiction expansion is a configuration change, not a code change." `ConfigExporter`
(`GET /tax/export`) serializes a company's current configuration back into the same portable pack
format for backup, review, or migration between environments.

**Compliance calendar mechanics.** The calendar is driven purely by data: each active
`tax_registrations` row carries a `filing_frequency`; `ComplianceCalendarJob` computes the next
`period_end` and `due_date` from it and raises `tax.return.due_soon` on a configurable lead schedule
(e.g. 30/14/3 days out), escalating to the Tax Manager and Finance Manager if a return for a due period
is still in `draft`. US-style economic-nexus thresholds tracked per state let the Tax Advisor flag a
`tax.nexus.threshold_crossed` before a registration obligation becomes an exposure. None of these jobs
may move a return to `filed` — the calendar reminds; the human files.

# Permissions

Permissions follow the RBAC grammar `<area>.<action>` / `<area>.<entity>.<action>` from
[../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md), default-deny, company-scoped.

| Permission | Grants |
|---|---|
| `tax.read` | View configuration, transactions, returns |
| `tax.calculate` | Invoke the engine (preview or via document commit) |
| `tax.jurisdiction.manage` / `tax.registration.manage` | Jurisdictions / registrations |
| `tax.config.manage` | Categories, codes, groups; approve AI classification proposals |
| `tax.rate.manage` / `tax.rule.manage` | Effective-dated rates / priority-ordered rules |
| `tax.exemption.manage` / `tax.recovery.manage` | Exemption certificates / recovery ratios |
| `tax.adjust` | Manual adjustments; reverse posted transactions |
| `tax.filing.read` / `tax.filing.prepare` | View returns / generate drafts, `draft → under_review` |
| `tax.filing.approve` | **First human gate** — `under_review → ready_to_file` |
| `tax.submit` | **Second human gate — file with the government; sensitive, human-only, never AI-only** |
| `tax.audit.read` | Full Tax Audit Report and raw lineage |
| `reports.export` | Export tax reports to PDF/XLSX/XML |

**The AI Agent grant is read + draft only.** The Tax Advisor holds `tax.read`, `tax.calculate`
(system-to-system, no human data entry), `tax.filing.prepare` (drafts only), and `tax.audit.read`
(read-only); it *proposes* configuration and adjustments via `/tax/ai/proposals` but holds neither
`tax.config.manage` nor `tax.adjust` to apply them, and is structurally forbidden `tax.filing.approve`
and `tax.submit`. `tax.submit` additionally rejects any request originating from a service/AI token at
the state-machine layer, regardless of grant. External Auditors get `tax.read` / `tax.filing.read` /
`tax.audit.read` only, time-boxed via `company_users.access_expires_at`, never any write.

# Error Handling

Actions and services throw typed domain exceptions; the global handler maps them to the platform
envelope and status code. The service never returns error arrays or HTTP responses.

| Exception | Meaning | Rendered |
|---|---|---|
| `TaxRateNotConfiguredException` | no effective rate for code+jurisdiction on the tax-point date | 422 `tax_rate_not_configured` (never silent 0) |
| `OverlappingRateException` | a rate insert would violate the exclusion constraint | 422 `rate_overlap` |
| `InvalidReturnTransitionException` | illegal `tax_returns.status` move | 409 `invalid_state_transition` |
| `AiSubmitForbiddenException` | service/AI token attempted `ready_to_file → filed` | 403 `ai_forbidden` |
| `MissingGovernmentAckException` | filing an e-filing return with no stored ack | 422 `government_ack_required` |
| `ImmutableTaxTransactionException` | editing a `posted` `tax_transactions` row | 409 `immutable_record` (names `/reverse`) |
| `ExpiredCertificateException` | applying an out-of-window exemption certificate | 422 `certificate_expired` |
| `RecoverySumMismatchException` | `recoverable + non_recoverable ≠ tax_amount` | 422 `recovery_sum_mismatch` |
| `JurisdictionNotRegisteredException` | charging tax where the company holds no active registration | 422 `jurisdiction_not_registered` |

Exceptions carry structured context (the resolved code/jurisdiction/date, the two recovery amounts, the
current and allowed states) so the handler can render `errors[].message` in the caller's language
without the service knowing about HTTP.

# Testing

A Tax feature is not done until it ships with its migration, API, permissions, audit logging, AI
support, and tests.

- **Unit-test `TaxCalculationService`** with repositories mocked: a standard-rated line computes the
  configured rate; a zero-rated line stays in-scope at 0%; an unconfigured product raises
  `TaxRateNotConfiguredException → 422` (never a silent 0); a tax-inclusive line back-calculates the
  base; a compound group applies members in `sequence` with `apply_on_running_total`; a reverse-charge
  rule produces a matched output/input pair on the buyer's books netting to zero at full recovery and to
  the non-recoverable portion under partial exemption; the rate snapshot is frozen and a later rate
  change does not recompute a historical transaction.
- **Unit-test `TaxRateService::scheduleRateChange`**: the atomic close-old/open-new never trips the
  exclusion constraint; a manual insert that would overlap raises `OverlappingRateException`.
- **Unit-test the return state machine**: only `tax.filing.approve` moves `under_review → ready_to_file`;
  only a *human* `tax.submit` moves `ready_to_file → filed`; a service/AI token is rejected at the
  transition guard even with the permission granted; an e-filing return cannot reach `filed` without a
  stored acknowledgment.
- **Feature-test through `/api/v1/tax`**: thin controller + FormRequest + policy + envelope cooperate;
  tenant isolation (company A gets 404 for company B's return); default-deny (missing permission ⇒ 403);
  the AI principal type gets 403 on `/returns/{id}/approve` and `/returns/{id}/file`.
- **Integration tests**: `tax.committed` drives Accounting to post the tax lines into the source
  document's own journal entry (not a separate one); the nightly integrity check flags any divergence >
  0.01 base currency between `tax_transactions` and the tax GL accounts.
- **Idempotency/event tests**: a retried commit (same `tax:{type}:{id}:committed` key) posts no second
  journal; `PostTaxToLedger` is safe to run twice.

# End of Document
