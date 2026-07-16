# Soft Delete, Restore & Retention Governance — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_SOFT_DELETE
---

# Purpose

This document specifies the soft-delete, restore, permanent-deletion, and retention architecture for every tenant-owned table in the QAYD Accounting Engine's PostgreSQL database. It is the single normative reference for how "deletion" behaves anywhere in the platform: what happens to a row when a user deletes it from the UI, how that row can be brought back, when — if ever — it may be physically removed from disk, how long it must be kept, and which categories of data are permanently protected from deletion regardless of role, API path, or AI agent involvement.

The specification exists because "delete" in a financial system is never a single, uniform operation. A draft sales quotation that a salesperson created by mistake behaves nothing like a posted journal line that has already been reported to a tax authority. Treating both the same way — either by hard-deleting everything (destroying evidence a regulator or auditor may later require) or by soft-deleting everything uniformly (leaking storage and slowing every query with untracked exceptions) — produces a system that is either non-compliant or unmaintainable. This document draws the exact lines: which tables soft-delete, which never delete at all, how long deleted data survives, who can restore it, who can purge it, and how every one of those actions is proven after the fact.

This document is binding for every engineer implementing a Laravel migration, Eloquent model, controller, service, or repository that touches a row in a tenant table, and for the AI Layer team, since AI agents (Section 7 of the shared design context — General Accountant, Auditor, Tax Advisor, Payroll Manager, Inventory Manager, Treasury Manager, CFO, Fraud Detection, Reporting Agent, Document AI, OCR Agent, Forecast Agent, Compliance Agent, Approval Assistant) invoke the same Laravel API and are therefore bound by the exact same delete/restore/purge rules as a human user. An AI agent has no elevated deletion authority, no bypass path, and no direct database access — every deletion an agent proposes still passes through the identical FormRequest validation, permission check, and guard logic defined in this document.

Scope: this document governs the mechanics of the deletion lifecycle at the database and Eloquent-model layer. It does not redefine business workflows (e.g., how an invoice moves from draft to paid) — those are owned by their respective module documents — but it defines the one deletion contract that every module must implement identically so that cross-module joins, reports, and the AI layer can rely on a single, predictable behavior across `accounts`, `customers`, `vendors`, `products`, `invoices`, `bills`, `bank_accounts`, `inventory_items`, `payroll_runs`, `tax_transactions`, and every other canonical table defined in the shared platform context.

Audience: backend engineers (Laravel/PostgreSQL), the AI Layer team, QA/test engineers, and compliance/audit stakeholders who need to know, precisely, what "delete" means in QAYD.

# Soft Delete Strategy

## 1. The governing rule

No business record in QAYD is ever hard-deleted by direct user action. Every tenant table carries the standard `deleted_at TIMESTAMPTZ NULL` column defined in the shared platform context, and this document defines three additional columns that every soft-deletable table MUST carry:

```sql
ALTER TABLE customers
    ADD COLUMN deleted_by        BIGINT       NULL REFERENCES users(id),
    ADD COLUMN deletion_reason   VARCHAR(500) NULL,
    ADD COLUMN deletion_batch_id UUID         NULL;
```

- `deleted_by` — who performed the delete. Never null on a trashed row; always null on an active row.
- `deletion_reason` — free-text or coded reason captured from the UI/API caller. `FormRequest` validates a non-empty reason for any table flagged `requires_reason` in `config/soft_deletes.php`.
- `deletion_batch_id` — a UUID generated once per delete operation and stamped on the target row and on every row that soft-cascades from it in the same transaction (see `# Relationships (soft-cascade)`). This is what makes an accurate, atomic restore possible later.

A row with `deleted_at IS NULL` is **active**. A row with `deleted_at IS NOT NULL` is **trashed**. Trashed rows are excluded from every default query, every report, every foreign-key-style application lookup, and every AI-agent context window unless a caller explicitly requests trashed data with the correct permission.

## 2. Three deletion tiers

QAYD recognizes exactly three deletion tiers, and every table in the schema is classified into one of them in `config/soft_deletes.php`:

| Tier | Behavior | Applies to |
|---|---|---|
| `SOFT` | `deleted_at` set; row stays in the table; fully restorable | Master data and pre-posting transactional documents: `customers`, `vendors`, `products`, `sales_quotations`, `sales_orders`, `purchase_orders`, `employees`, `warehouses`, `bank_accounts`, `cost_centers`, `projects`, etc. |
| `SOFT_LOCKED` | `deleted_at` set only after a guard check; once posted/settled, deletion is blocked until reversed | `invoices`, `bills`, `receipts`, `vendor_payments`, `payroll_runs`, `bank_reconciliations`, `tax_returns` |
| `IMMUTABLE` | No delete path exists at all — not soft, not hard. The only row-level mutation permitted post-creation is a status transition owned by its own module (`posted -> reversed`) | `journal_entries`, `journal_lines`, `ledger_entries`, `audit_logs`, `stock_movements` once linked to a posted valuation layer |

This document's remaining sections describe `SOFT` and `SOFT_LOCKED` behavior in depth. The `IMMUTABLE` tier is specified fully in `# What Can Never Be Deleted (posted journal entries)`.

## 3. Eloquent model implementation

Every `SOFT` and `SOFT_LOCKED` model uses Laravel's native `SoftDeletes` trait plus a QAYD trait, `HasGovernedSoftDeletes`, that layers the reason/batch/guard behavior on top:

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\Audit\AuditLogger;

trait HasGovernedSoftDeletes
{
    protected static function bootHasGovernedSoftDeletes(): void
    {
        static::deleting(function ($model) {
            if (method_exists($model, 'guardAgainstDeletion')) {
                $model->guardAgainstDeletion();
            }

            $model->deleted_by        = Auth::id();
            $model->deletion_reason   = request('deletion_reason');
            $model->deletion_batch_id = $model->deletion_batch_id ?? (string) Str::uuid();
            $model->saveQuietly();
        });

        static::deleted(function ($model) {
            app(AuditLogger::class)->record(
                action: 'soft_deleted',
                model: $model,
                reason: $model->deletion_reason,
            );

            if (method_exists($model, 'softCascade')) {
                DB::transaction(fn () => $model->softCascade());
            }
        });

        static::restoring(function ($model) {
            if (method_exists($model, 'guardAgainstRestore')) {
                $model->guardAgainstRestore();
            }
        });

        static::restored(function ($model) {
            $model->deleted_by      = null;
            $model->deletion_reason = null;
            $model->saveQuietly();

            app(AuditLogger::class)->record(action: 'restored', model: $model);
        });
    }

    public function scopeActiveOnly($query)
    {
        return $query->whereNull('deleted_at');
    }

    public function scopeTrashedInBatch($query, string $batchId)
    {
        return $query->onlyTrashed()->where('deletion_batch_id', $batchId);
    }
}
```

A concrete model — `Customer` — combines Eloquent's `SoftDeletes` with this trait and defines the deletion guard specific to its own business rules:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasGovernedSoftDeletes;
use App\Exceptions\DeletionGuardException;

class Customer extends Model
{
    use SoftDeletes, HasGovernedSoftDeletes;

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name_en', 'name_ar',
        'email', 'phone', 'tax_registration_number', 'status',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
        'tags'       => 'array',
    ];

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function contacts()
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function guardAgainstDeletion(): void
    {
        $openInvoices = $this->invoices()
            ->whereIn('status', ['draft', 'posted', 'partially_paid'])
            ->exists();

        if ($openInvoices) {
            throw new DeletionGuardException(
                code: 'CUSTOMER_HAS_OPEN_INVOICES',
                message: 'Customer cannot be deleted while open or unpaid invoices exist.',
            );
        }
    }

    public function softCascade(): void
    {
        $this->contacts()->get()->each->delete();
    }
}
```

## 4. Partial unique indexes: the core enforcement mechanism

A soft-deleted row must stop occupying its unique business key so a new active row can reuse it (a customer code, an account code, an invoice number, a product SKU), while the old row's history stays intact. This is done exclusively through **partial unique indexes** scoped to `deleted_at IS NULL` — never through a regular unique constraint, which would block reuse forever, and never through application-level uniqueness checks alone, which race under concurrency.

```sql
-- Customer business code is unique per company, but ONLY among active rows.
CREATE UNIQUE INDEX uq_customers_company_code_active
    ON customers (company_id, code)
    WHERE deleted_at IS NULL;

-- Same pattern for the Chart of Accounts.
CREATE UNIQUE INDEX uq_accounts_company_code_active
    ON accounts (company_id, code)
    WHERE deleted_at IS NULL;

-- Product SKU, scoped per company, active rows only.
CREATE UNIQUE INDEX uq_products_company_sku_active
    ON products (company_id, sku)
    WHERE deleted_at IS NULL;

-- Sales invoice number must never collide with another ACTIVE invoice
-- in the same company, but a voided/deleted invoice's number can be
-- reissued later if the business process allows it.
CREATE UNIQUE INDEX uq_invoices_company_number_active
    ON invoices (company_id, invoice_number)
    WHERE deleted_at IS NULL;
```

This is the mechanism that makes the whole soft-delete model viable at scale: PostgreSQL enforces the uniqueness invariant transactionally and atomically (no read-then-write race), the index only indexes active rows (small, fast — see `# Indexes`), and restoring a row later re-validates the same predicate automatically the moment `deleted_at` is set back to `NULL`. If a conflicting active row now holds that code, the `UPDATE` that performs the restore fails with a Postgres `23505 unique_violation`, which the application converts into a `409 Conflict` (see `# Restore`).

## 5. What soft delete is not

Soft delete is a **storage-and-visibility** concept, not a **business-state** concept. `invoices.status = 'voided'` and `invoices.deleted_at IS NOT NULL` are independent facts:

- A voided invoice (`status = 'voided'`) is still an **active**, visible row (`deleted_at IS NULL`) — it appears in lists and reports, clearly marked void, because voiding is itself a business event that must be seen and audited.
- A **deleted** invoice is hidden from normal views entirely, regardless of its `status` value, because the user has asked the system to stop showing it.

An invoice must be voided (business state) before it can be deleted (storage state) if it was ever posted — see the `guardAgainstDeletion()` pattern above, generalized per table in `config/soft_deletes.php` as a `required_status_before_delete` map (e.g., `invoices => ['draft', 'voided', 'cancelled']`).

# Restore

## 1. Restore semantics

Restoring a row sets `deleted_at`, `deleted_by`, and `deletion_reason` back to `NULL`. It is implemented through Eloquent's native `restore()` method, which QAYD's `HasGovernedSoftDeletes` trait intercepts with `restoring`/`restored` events (shown above) to run guard checks and write the audit trail.

Restore has an authorization ceiling of its own: the ability to delete a resource does not imply the ability to restore it. Both are distinct permission keys — `<area>.<entity>.delete` and `<area>.<entity>.restore` — and QAYD deliberately requires the restore permission to sit at least one role tier above delete for financial documents (see `# Compliance`).

## 2. API surface

```
Method  Path                                              Permission                  Description
POST    /api/v1/{module}/{resource}/{id}/restore           <area>.<entity>.restore      Restore one trashed row
POST    /api/v1/{module}/{resource}/restore-batch          <area>.<entity>.restore      Restore every row sharing a deletion_batch_id
GET     /api/v1/{module}/{resource}/trashed                <area>.<entity>.read.trashed List trashed rows (paginated)
```

Restoring a single customer:

```
POST /api/v1/sales/customers/4821/restore
X-Company-Id: 101
Authorization: Bearer <token>
```

Successful response:

```json
{
  "success": true,
  "data": {
    "id": 4821,
    "code": "CUST-00291",
    "name_en": "Al Salam Trading Co.",
    "deleted_at": null,
    "restored_at": "2026-07-16T09:14:02Z",
    "restored_by": 17
  },
  "message": "Customer restored successfully.",
  "errors": [],
  "meta": {},
  "request_id": "5f1e6c2a-9b3d-4e21-8b77-1a9f0e2c44aa",
  "timestamp": "2026-07-16T09:14:02Z"
}
```

Note `restored_at`/`restored_by` in the payload are computed from the most recent `audit_logs` row for this entity at serialization time — they are **not** persisted columns on the table itself. QAYD does not add a permanent `restored_at` column to every table, because a row can be deleted and restored many times over its life; the full timeline belongs in `audit_logs` (`# History`), and the API resource simply surfaces the latest event for convenience.

## 3. Conflict handling

Because uniqueness is enforced only among active rows (`# Soft Delete Strategy`, partial indexes), a restore can collide with a newer row that has legitimately reused the same business key. QAYD treats this as an expected, first-class outcome, not a bug:

```php
<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Exceptions\RestoreConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class CustomerRestoreService
{
    public function restore(int $customerId): Customer
    {
        return DB::transaction(function () use ($customerId) {
            $customer = Customer::onlyTrashed()->lockForUpdate()->findOrFail($customerId);

            $conflict = Customer::activeOnly()
                ->where('company_id', $customer->company_id)
                ->where('code', $customer->code)
                ->exists();

            if ($conflict) {
                throw new RestoreConflictException(
                    code: 'RESTORE_CODE_CONFLICT',
                    message: "Code '{$customer->code}' is already in use by an active customer. Rename before restoring.",
                    conflictingField: 'code',
                );
            }

            try {
                $customer->restore();
            } catch (QueryException $e) {
                if ($e->getCode() === '23505') {
                    throw new RestoreConflictException(
                        code: 'RESTORE_UNIQUE_VIOLATION',
                        message: 'A concurrent write created a conflicting record. Retry.',
                    );
                }
                throw $e;
            }

            return $customer->refresh();
        });
    }
}
```

`RestoreConflictException` maps to HTTP `409`:

```json
{
  "success": false,
  "data": null,
  "message": "Code 'CUST-00291' is already in use by an active customer. Rename before restoring.",
  "errors": [
    { "code": "RESTORE_CODE_CONFLICT", "field": "code" }
  ],
  "meta": {},
  "request_id": "1c9a7e33-6b40-4a11-9f0d-7d8e9a221cde",
  "timestamp": "2026-07-16T09:15:40Z"
}
```

The pre-check-then-lock pattern (`lockForUpdate()` on the trashed row, existence check on the active set, then `restore()` inside the same transaction) prevents a second, concurrent restore or a concurrent "create new customer with the same code" request from slipping through between the check and the write.

## 4. Restore window and eligibility

A row is restorable only while it is within its table's configured **trash retention window** (`# Retention`) and only while no permanent delete has already purged it. Attempting to restore a row past its window, or one that was already purged (which is by definition impossible via the normal `GET` path, since the row no longer exists — the API returns a plain `404`), is rejected with `410 Gone` if the platform still has an `audit_logs` record proving the row is permanently gone:

```json
{
  "success": false,
  "data": null,
  "message": "This record was permanently deleted on 2026-04-02 and can no longer be restored.",
  "errors": [ { "code": "RESTORE_WINDOW_EXPIRED" } ],
  "meta": {},
  "request_id": "a13c5590-2222-4f31-9e10-8e4a301bb001",
  "timestamp": "2026-07-16T09:16:03Z"
}
```

## 5. Cascade on restore

Restore does **not** automatically walk back down to children the way delete automatically cascades down (`# Relationships (soft-cascade)`). Instead, restore operates on a **deletion batch**: every row that was soft-deleted in the same transaction (sharing `deletion_batch_id`) can be restored together via the batch endpoint, but a parent restore alone leaves children trashed unless the caller explicitly restores the batch. This avoids silently resurrecting a child that a second, later, independent delete action removed on its own — which would have overwritten that child's `deletion_batch_id` with a new UUID, correctly excluding it from the parent's original batch.

# Permanent Delete

## 1. Definition and default posture

Permanent delete ("purge") physically removes a row with `DELETE FROM`. It is Eloquent's `forceDelete()`. QAYD's default posture is that **permanent delete is disabled** for every table unless that table is explicitly whitelisted in `config/soft_deletes.php` under `purgeable_after_days`. Tables never present in that whitelist cannot be force-deleted through any code path — `forceDelete()` is overridden at the base model level to throw unconditionally unless the child model explicitly opts in:

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use App\Exceptions\PermanentDeleteForbiddenException;
use App\Services\Audit\AuditLogger;

trait HasGovernedSoftDeletes
{
    // ...(deleting/deleted/restoring/restored bootstrapping shown earlier)...

    public function forceDelete()
    {
        if (! (static::$purgeable ?? false)) {
            throw new PermanentDeleteForbiddenException(
                code: 'PERMANENT_DELETE_NOT_ALLOWED',
                message: static::class . ' does not support permanent deletion. Records must remain soft-deleted indefinitely.',
            );
        }

        return DB::transaction(function () {
            $this->guardAgainstPurge();
            app(AuditLogger::class)->record(action: 'purged', model: $this, reason: request('deletion_reason'));
            return parent::forceDelete();
        });
    }
}
```

Only master-data models with no transactional footprint set `protected static $purgeable = true;` — e.g., `Customer`, `Vendor`, `Product`, `Employee`, `Warehouse`, `SalesQuotation` (while still in `draft`). Any table that can ever be referenced by a posted financial document is never purgeable, full stop; see `# What Can Never Be Deleted (posted journal entries)`.

## 2. Elevated authorization

Permanent delete requires:

1. Permission `<area>.<entity>.purge`, granted by default only to `Owner`.
2. A typed confirmation string matching the entity's business key, submitted in the request body (`"confirm": "CUST-00291"`), mirroring the double-confirmation pattern used for irreversible operations across the platform.
3. Step-up authentication — the bearer token must have been issued (or refreshed via re-auth) within the last 15 minutes; otherwise the API returns `401` with `code: "REAUTH_REQUIRED"`.
4. A second human approver for any purge touching more than one row or any row older than 30 days (see `# Audit`, `deletion_approvals`).

## 3. Endpoint and guard

```
Method  Path                                          Permission            Description
DELETE  /api/v1/{module}/{resource}/{id}/purge          <area>.<entity>.purge  Physically delete one trashed row
```

```php
public function guardAgainstPurge(): void
{
    if ($this->invoices()->withTrashed()->exists()) {
        throw new DeletionGuardException(
            code: 'PURGE_BLOCKED_REFERENCED',
            message: 'Customer has historical invoices (including trashed) and cannot be purged. Anonymize instead.',
        );
    }
}
```

Referential integrity is checked in application code *before* the `DELETE`, but the database is the final backstop: every foreign key from a transactional table back to a purgeable master-data table is declared `ON DELETE RESTRICT`, never `ON DELETE CASCADE` and never `ON DELETE SET NULL`:

```sql
ALTER TABLE invoices
    ADD CONSTRAINT invoices_customer_id_fkey
    FOREIGN KEY (customer_id) REFERENCES customers(id)
    ON DELETE RESTRICT;
```

`RESTRICT` guarantees that even if an application-level guard is ever buggy or bypassed, PostgreSQL itself refuses the `DELETE` with a `23503 foreign_key_violation` rather than silently cascading destruction through the schema or leaving dangling `customer_id` values in historical invoices.

## 4. Scheduled purge job

For the narrow set of purgeable tables, QAYD runs a nightly Artisan command rather than deleting synchronously on user request, so that even an approved purge has one more delay window before data is unrecoverable:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesQuotation;
use App\Services\Audit\AuditLogger;

class PurgeExpiredSoftDeletes extends Command
{
    protected $signature = 'qayd:purge-expired {--dry-run}';

    protected array $purgeableModels = [
        Customer::class       => 365,
        Product::class        => 180,
        SalesQuotation::class => 90,
    ];

    public function handle(AuditLogger $auditLogger): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach ($this->purgeableModels as $model => $retentionDays) {
            $model::onlyTrashed()
                ->where('deleted_at', '<', now()->subDays($retentionDays))
                ->whereDoesntHave('legalHold')
                ->chunkById(500, function ($rows) use ($dryRun, $auditLogger, $model) {
                    foreach ($rows as $row) {
                        $this->line(($dryRun ? '[DRY-RUN] ' : '') . "Purge candidate: {$model}#{$row->id}");

                        if (! $dryRun) {
                            try {
                                $row->forceDelete();
                            } catch (\Throwable $e) {
                                $auditLogger->record(
                                    action: 'purge_failed',
                                    model: $row,
                                    reason: $e->getMessage(),
                                );
                                $this->warn("  skipped (referenced): {$e->getMessage()}");
                            }
                        }
                    }
                });
        }

        return self::SUCCESS;
    }
}
```

Scheduled daily at low-traffic hours:

```php
// routes/console.php
Schedule::command('qayd:purge-expired')->dailyAt('02:30')->onOneServer();
```

## 5. GDPR erasure vs. bookkeeping retention

A "Right to Erasure" request against a customer or vendor contact frequently collides with statutory bookkeeping retention (`# Compliance`) — the person's data is embedded in invoices that must legally survive for years. QAYD resolves this with **anonymization instead of purge** whenever a purgeability guard would otherwise block deletion:

```sql
UPDATE customer_contacts
SET
    name_en    = 'REDACTED',
    name_ar    = 'محذوف',
    email      = NULL,
    phone      = NULL,
    pii_key_id = NULL,           -- drops the encryption key reference; see # Compliance
    deleted_at = now(),
    deleted_by = :actor_id,
    deletion_reason = 'gdpr_erasure_anonymized'
WHERE id = :contact_id;
```

The numeric and structural facts (invoice amounts, dates, account postings) remain fully intact and auditable; only person-identifying fields are destroyed. This satisfies erasure obligations without breaking `# What Can Never Be Deleted (posted journal entries)`.

# Retention

## 1. Retention policy model

Retention is configurable per company and per entity type through a dedicated table rather than hard-coded constants, because statutory bookkeeping-retention periods vary by jurisdiction and QAYD is multi-country from day one (Kuwait base currency, GCC expansion per the platform brief):

```sql
CREATE TABLE retention_policies (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NULL REFERENCES companies(id),   -- NULL = platform-wide default
    entity_type           VARCHAR(100) NOT NULL,                  -- e.g. 'invoices', 'customers'
    trash_retention_days   INTEGER NOT NULL DEFAULT 90,            -- soft-deleted -> eligible for purge
    archive_after_days     INTEGER NULL,                          -- active/immutable -> cold storage
    action_after_expiry    VARCHAR(20) NOT NULL DEFAULT 'purge'
        CHECK (action_after_expiry IN ('purge', 'archive', 'anonymize', 'retain_forever')),
    legal_basis            VARCHAR(255) NULL,                      -- e.g. 'statutory bookkeeping period'
    created_by             BIGINT NULL REFERENCES users(id),
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_retention_policies_company_entity
    ON retention_policies (COALESCE(company_id, 0), entity_type);
```

Resolution order: an explicit `company_id` row wins over the platform default (`company_id IS NULL`) for the same `entity_type`. The service layer resolves the effective policy with:

```sql
SELECT *
FROM retention_policies
WHERE entity_type = :entity_type
  AND (company_id = :company_id OR company_id IS NULL)
ORDER BY company_id NULLS LAST
LIMIT 1;
```

## 2. Default platform policy

| Entity category | Trash retention | After expiry | Rationale |
|---|---|---|---|
| Draft-only documents (`sales_quotations`, `purchase_requests`, `rfqs`) | 90 days | purge | Never touched the ledger; low retention value |
| Master data (`customers`, `vendors`, `products`, `employees`) | 365 days | anonymize (PII) / purge (non-PII) | Balances erasure requests against reference integrity |
| Posted financial documents (`invoices`, `bills`, `receipts`, `vendor_payments`) | Never eligible for trash-purge | archive after 7 years | Statutory bookkeeping retention |
| `journal_entries` / `journal_lines` / `ledger_entries` | N/A — immutable, never deleted | archive (partition detach) after 10 years | Legal + audit permanence |
| `audit_logs` | N/A — immutable, never deleted | archive after 10 years | Evidence of controls (SOC 2 / ISO 27001) |
| `tax_transactions` / `tax_returns` | Never eligible for trash-purge | archive after statutory tax period (configurable, default 5 years) | Tax authority audit window |

These defaults are seeded via a Laravel migration/seeder and can be overridden per company from Company Settings by a user holding `settings.retention.manage` — itself restricted to `Owner`/`CFO`. QAYD ships conservative platform defaults; each deployed company's finance/legal function is expected to review and, where its own jurisdiction requires a longer statutory window, tighten `trash_retention_days`/`archive_after_days` accordingly. This document defines the mechanism, not the legal conclusion for any specific jurisdiction.

## 3. Trash countdown mechanics

The trash countdown for a `SOFT`-tier row starts the instant `deleted_at` is set, not from `created_at`. The purge job (`# Permanent Delete`) reads `trash_retention_days` from the resolved policy rather than a hard-coded number per model, so operators can tighten or loosen it without a deploy:

```sql
SELECT c.*
FROM customers c
WHERE c.deleted_at IS NOT NULL
  AND c.deleted_at < now() - (
        SELECT (rp.trash_retention_days || ' days')::interval
        FROM retention_policies rp
        WHERE rp.entity_type = 'customers'
          AND (rp.company_id = c.company_id OR rp.company_id IS NULL)
        ORDER BY rp.company_id NULLS LAST
        LIMIT 1
      );
```

## 4. Archival instead of purge for financial data

Tables in the `SOFT_LOCKED` and `IMMUTABLE` tiers are never purged on a retention timer — they are **archived**: moved out of the hot, indexed operational table into cold, cheaper storage while remaining queryable for audits. QAYD implements this with native PostgreSQL declarative partitioning on the highest-volume ledger table, partitioned by fiscal year:

```sql
CREATE TABLE journal_lines (
    id               BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id       BIGINT NOT NULL REFERENCES companies(id),
    journal_entry_id BIGINT NOT NULL REFERENCES journal_entries(id),
    account_id       BIGINT NOT NULL REFERENCES accounts(id),
    fiscal_year      SMALLINT NOT NULL,
    debit            NUMERIC(19,4) NOT NULL DEFAULT 0,
    credit           NUMERIC(19,4) NOT NULL DEFAULT 0,
    cost_center_id   BIGINT NULL REFERENCES cost_centers(id),
    project_id       BIGINT NULL REFERENCES projects(id),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, fiscal_year)
) PARTITION BY RANGE (fiscal_year);

CREATE TABLE journal_lines_2024 PARTITION OF journal_lines FOR VALUES FROM (2024) TO (2025);
CREATE TABLE journal_lines_2025 PARTITION OF journal_lines FOR VALUES FROM (2025) TO (2026);
CREATE TABLE journal_lines_2026 PARTITION OF journal_lines FOR VALUES FROM (2026) TO (2027);
```

Archival of a fully-expired fiscal year is a `DETACH PARTITION`, not a `DELETE` — the data is never destroyed, only moved off the hot path:

```sql
ALTER TABLE journal_lines DETACH PARTITION journal_lines_2016 CONCURRENTLY;
```

The detached partition is then exported once to Cloudflare R2 as a compressed, checksummed artifact (`pg_dump --table=journal_lines_2016 | zstd`), and the manifest (file hash, row count, date range, exporting user) is written to `audit_logs` before the partition table is ever dropped locally. A `report_runs` entry of type `archive_export` records the operation for compliance retrieval.

## 5. Legal hold

A record subject to litigation, a tax audit, or a regulator's information request must survive its retention timer even if it would otherwise be purged or archived out of easy reach:

```sql
CREATE TABLE legal_holds (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    entity_type   VARCHAR(100) NOT NULL,
    entity_id     BIGINT NOT NULL,
    reason        VARCHAR(500) NOT NULL,
    placed_by     BIGINT NOT NULL REFERENCES users(id),
    placed_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    released_by   BIGINT NULL REFERENCES users(id),
    released_at   TIMESTAMPTZ NULL,
    UNIQUE (entity_type, entity_id, released_at)
);

CREATE INDEX idx_legal_holds_active_lookup
    ON legal_holds (entity_type, entity_id)
    WHERE released_at IS NULL;
```

Every purge/archive job left-joins against `legal_holds` (`whereDoesntHave('legalHold')` in the Eloquent example above) and unconditionally skips any row under an active hold, logging the skip. Only `Owner` or `Auditor`-role users may place or release a hold, and doing so is itself an audited action.

# Compliance

## 1. Regulatory posture

QAYD's soft-delete architecture is designed against four overlapping compliance demands that every Gulf-region financial platform must reconcile simultaneously:

1. **Statutory bookkeeping retention.** Commercial and tax law across GCC jurisdictions (and equivalents elsewhere QAYD expands to) generally requires a company's accounting books and supporting records to remain producible for a multi-year statutory window. QAYD does not hard-code a specific number of years in application logic; it exposes `legal_basis` and `trash_retention_days`/`archive_after_days` per jurisdiction through `retention_policies` (`# Retention`) so each deployed company's finance/legal team configures the correct window for its own jurisdiction. This document is a platform mechanism, not legal advice, and the default values shipped are conservative starting points intended to be reviewed per company.
2. **Data-subject erasure rights** (GDPR-style "right to be forgotten," and equivalent regional personal-data-protection regimes). Handled via anonymization rather than physical deletion whenever the personal data is embedded in an immutable or retention-locked financial record (`# Permanent Delete`, section 5).
3. **Audit-trail integrity** for SOC 2 / ISO 27001-style controls: every deletion, restoration, and purge must be attributable, timestamped, and itself tamper-evident (`# Audit`).
4. **Segregation of duties**, common to SOX-aligned internal-control frameworks: the person who deletes financial data cannot be the sole authority approving its permanent destruction (`# Audit`, `deletion_approvals`).

## 2. Personal data protection via crypto-shredding

For master-data tables carrying personal data (`customers`, `customer_contacts`, `vendors`, `vendor_contacts`, `employees`), QAYD stores personally identifying fields encrypted at the application layer with a per-subject data key, rather than relying solely on anonymization-by-overwrite:

```sql
CREATE TABLE data_encryption_keys (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    subject_type  VARCHAR(100) NOT NULL,   -- 'customer_contacts', 'employees', ...
    subject_id    BIGINT NOT NULL,
    wrapped_key   BYTEA NOT NULL,          -- envelope-encrypted with the platform KMS key
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    revoked_at    TIMESTAMPTZ NULL
);

CREATE UNIQUE INDEX uq_data_encryption_keys_subject_active
    ON data_encryption_keys (subject_type, subject_id)
    WHERE revoked_at IS NULL;

ALTER TABLE customer_contacts
    ADD COLUMN pii_key_id UUID NULL REFERENCES data_encryption_keys(id),
    ADD COLUMN name_enc   BYTEA NULL,
    ADD COLUMN email_enc  BYTEA NULL,
    ADD COLUMN phone_enc  BYTEA NULL;
```

Fulfilling an erasure request revokes the key (`UPDATE data_encryption_keys SET revoked_at = now() WHERE id = :key_id`) rather than mutating every row that referenced it. Once revoked, `name_enc`/`email_enc`/`phone_enc` are cryptographically unrecoverable — satisfying erasure — while the row itself, its `id`, its relationships to invoices, and every numeric fact remain intact for statutory bookkeeping. A Laravel cast pair (`PiiEncrypted::class`) transparently decrypts for authorized reads and returns `null` once the key is gone:

```php
protected $casts = [
    'name_enc'  => \App\Casts\PiiEncrypted::class,
    'email_enc' => \App\Casts\PiiEncrypted::class,
    'phone_enc' => \App\Casts\PiiEncrypted::class,
];
```

## 3. Auditor access without mutation rights

The `Auditor` and `External Auditor` roles must be able to see the full truth — including trashed rows and deletion history — without ever being able to alter it:

| Permission | Owner | CFO | Finance Manager | Senior Accountant | Accountant | Auditor | External Auditor | Read Only |
|---|---|---|---|---|---|---|---|---|
| `accounting.read.trashed` | Yes | Yes | Yes | Yes | No | Yes | Yes | No |
| `accounting.journal.delete` | No* | No* | No* | No* | No* | No | No | No |
| `customers.delete` | Yes | Yes | Yes | No | No | No | No | No |
| `customers.restore` | Yes | Yes | No | No | No | No | No | No |
| `customers.purge` | Yes | No | No | No | No | No | No | No |
| `audit.read` | Yes | Yes | Yes | No | No | Yes | Yes | No |
| `retention.manage` | Yes | Yes | No | No | No | No | No | No |
| `legal_hold.manage` | Yes | No | No | No | No | Yes | No | No |

\* Posted journal entries have no delete permission at any role — see `# What Can Never Be Deleted (posted journal entries)`.

## 4. Compliance reporting endpoint

```
Method  Path                                   Permission     Description
GET     /api/v1/compliance/deletion-report      audit.read     Every soft-delete/restore/purge in a date range
```

```
GET /api/v1/compliance/deletion-report?date_from=2026-01-01&date_to=2026-07-16&entity_type=invoices
```

```json
{
  "success": true,
  "data": [
    {
      "entity_type": "invoices",
      "entity_id": 88213,
      "action": "soft_deleted",
      "actor": { "id": 17, "name": "Fatima Al-Rashid" },
      "reason": "duplicate_entry",
      "occurred_at": "2026-03-11T08:22:10Z"
    },
    {
      "entity_type": "invoices",
      "entity_id": 88213,
      "action": "restored",
      "actor": { "id": 3, "name": "Owner" },
      "reason": null,
      "occurred_at": "2026-03-11T10:05:44Z"
    }
  ],
  "message": "Deletion report generated.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 2, "cursor": null } },
  "request_id": "9e0a1b22-44cd-4a10-9e77-2b6f0a883d10",
  "timestamp": "2026-07-16T09:20:00Z"
}
```

## 5. Data residency

Archived partitions and audit exports are written to the Cloudflare R2 bucket pinned to the company's configured region at company-creation time; retention-policy rows carry no cross-region replication by default, keeping each company's financial history inside its declared jurisdiction, consistent with typical Gulf data-residency expectations for financial records.

# History

## 1. Single source of truth for deletion timeline

Every lifecycle transition — soft delete, restore, purge attempt, purge success, legal hold placed/released, retention policy change — is written to the platform's shared `audit_logs` table. QAYD deliberately does not maintain a parallel, per-table "deletion_history" table: fragmenting history across dozens of tables would make the compliance report in `# Compliance` impossible to produce with a single query, and would create N different schemas to keep consistent.

```sql
CREATE TABLE audit_logs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id      BIGINT NOT NULL REFERENCES companies(id),
    user_id         BIGINT NULL REFERENCES users(id),          -- NULL for system/job actions
    action          VARCHAR(50) NOT NULL,                       -- soft_deleted | restored | purged | ...
    auditable_type  VARCHAR(100) NOT NULL,                      -- 'App\Models\Customer'
    auditable_id    BIGINT NOT NULL,
    old_values      JSONB NULL,
    new_values      JSONB NULL,
    reason          VARCHAR(500) NULL,
    ip_address      INET NULL,
    user_agent      VARCHAR(500) NULL,
    request_id      UUID NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_audit_logs_auditable_lookup
    ON audit_logs (auditable_type, auditable_id, created_at DESC);

CREATE INDEX idx_audit_logs_company_action
    ON audit_logs (company_id, action, created_at DESC);
```

## 2. Observer-driven capture

`AuditLogger`, invoked from the `HasGovernedSoftDeletes` lifecycle hooks shown earlier, captures a full JSONB snapshot of the row at the moment of deletion — not just the fact that a delete happened — so a restored row (or an auditor) can compare exactly what existed before:

```php
<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use App\Models\AuditLog;

class AuditLogger
{
    public function record(string $action, $model, ?string $reason = null): AuditLog
    {
        return AuditLog::create([
            'company_id'     => $model->company_id,
            'user_id'        => Auth::id(),
            'action'         => $action,
            'auditable_type' => get_class($model),
            'auditable_id'   => $model->getKey(),
            'old_values'     => $action === 'restored' ? null : $model->getOriginal(),
            'new_values'     => $action === 'restored' ? $model->getAttributes() : null,
            'reason'         => $reason,
            'ip_address'     => Request::ip(),
            'user_agent'     => Request::userAgent(),
            'request_id'     => Request::header('X-Request-Id'),
        ]);
    }
}
```

## 3. Point-in-time reconstruction

Because `old_values` is a full-row JSONB snapshot taken at the instant of deletion, "what did this invoice look like right before it was deleted" is a direct, indexed lookup rather than a diff-replay:

```sql
SELECT old_values
FROM audit_logs
WHERE auditable_type = 'App\Models\Invoice'
  AND auditable_id   = 88213
  AND action         = 'soft_deleted'
ORDER BY created_at DESC
LIMIT 1;
```

The full timeline of an entity — every state it passed through, deletion or otherwise — is:

```sql
SELECT action, reason, user_id, created_at
FROM audit_logs
WHERE auditable_type = 'App\Models\Invoice'
  AND auditable_id   = 88213
ORDER BY created_at ASC;
```

## 4. Retention of history itself

`audit_logs` rows are never soft-deleted and never purged on the same timer as the data they describe (`# Retention`, default archive after 10 years) — a deletion record that could itself be deleted would defeat the entire compliance model. Enforcement of `audit_logs` immutability is specified in `# Audit`.

# Relationships (soft-cascade)

## 1. Cascade map

Deleting a parent row must not silently orphan its children (a sales order left pointing at a deleted customer contact via an FK that no longer resolves in application logic), but it also must never reach into another module's data or into immutable ledger rows. QAYD defines an explicit, per-model cascade map rather than relying on implicit "delete anything that references this row":

| Parent | Cascades to (soft-deleted together) | Blocked instead of cascaded | Independent (untouched) |
|---|---|---|---|
| `customers` | `customer_contacts`, `customer_addresses` | any `invoices` in `{draft,posted,partially_paid}` (guard blocks parent delete) | `invoices` in terminal states (`paid`, `void`) — kept, decoupled from the trashed customer in UI via `withTrashed()` |
| `sales_orders` | `sales_order_items` | a sales order with a linked `deliveries` row | `deliveries`, `invoices` generated from it |
| `invoices` | `invoice_items` | any `receipts`/`receipt_allocations` referencing it, and any status other than `draft`/`voided` | `journal_entries` posted from it — never touched; must be reversed first, independently, through the accounting module |
| `purchase_orders` | `purchase_order_items` | a PO with linked `goods_receipts` | `bills` generated from it |
| `bank_accounts` | none | any account with `bank_transactions` in the current fiscal year | `bank_reconciliations` |
| `products` | `product_barcodes`, `product_serials` (only if no stock on hand) | any product with `inventory_items.quantity_on_hand <> 0` | `stock_movements` history |
| `vendors` | `vendor_contacts`, `vendor_addresses` | open `bills` | historical `bills` in terminal state |

## 2. Cascade implementation

Cascade is implemented as an explicit method per model (`softCascade()`, shown on `Customer` in `# Soft Delete Strategy`), invoked from the `deleted` lifecycle hook inside the same database transaction as the parent's own delete, and every cascaded child is stamped with the **same** `deletion_batch_id` as the parent:

```php
public function softCascade(): void
{
    $batchId = $this->deletion_batch_id;

    $this->contacts()->get()->each(function ($contact) use ($batchId) {
        $contact->deletion_batch_id = $batchId;
        $contact->delete();
    });

    $this->addresses()->get()->each(function ($address) use ($batchId) {
        $address->deletion_batch_id = $batchId;
        $address->delete();
    });
}
```

Propagating the same `deletion_batch_id` through every level of the cascade is what lets `# Restore`'s batch-restore endpoint bring back the exact set of rows that went down together, and nothing else:

```sql
UPDATE customer_contacts
SET deleted_at = NULL, deleted_by = NULL, deletion_reason = NULL
WHERE deletion_batch_id = :batch_id AND deleted_at IS NOT NULL;
```

wrapped, in application code, by the same guard-and-conflict logic shown in `# Restore` for every row in the batch, inside one transaction — if any single row in the batch would violate a partial unique index on restore, the entire batch restore rolls back and returns a `409` listing every conflicting row, rather than partially restoring a batch and leaving the entity graph inconsistent.

## 3. Why database-level `ON DELETE CASCADE` is never used

Every foreign key in QAYD's schema is `ON DELETE RESTRICT` (or, for genuinely optional links, `ON DELETE SET NULL` on non-financial, non-required columns only, e.g. `sales_orders.referred_by_user_id`). `ON DELETE CASCADE` is never used, for three reasons:

1. QAYD's deletes are almost always `UPDATE ... SET deleted_at = now()`, not `DELETE`, so `ON DELETE CASCADE` would never even fire during normal operation — it would only activate during a `forceDelete()`/purge, which is exactly the moment the platform wants maximum friction and explicit, auditable, per-row control, not an automatic, invisible chain reaction.
2. A `CASCADE` chain crossing module boundaries (e.g., deleting a `product` cascading into `stock_movements`, into `inventory_valuations`, into `ledger_entries`) would silently violate `# What Can Never Be Deleted (posted journal entries)` the first time someone forgot this specific FK was cascading.
3. Explicit application-level cascade (the `softCascade()` pattern) is itself audited, batch-tagged, and testable; a database-level `ON DELETE CASCADE` is none of those things.

## 4. Polymorphic relationships

`attachments` (foundation table) is polymorphic (`attachable_type`, `attachable_id`) and is cascaded generically rather than per-parent-model, via a shared trait:

```php
trait CascadesAttachments
{
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function softCascadeAttachments(): void
    {
        $this->attachments()->get()->each(function ($attachment) {
            $attachment->deletion_batch_id = $this->deletion_batch_id;
            $attachment->delete();
        });
    }
}
```

Any model using `CascadesAttachments` calls `softCascadeAttachments()` from within its own `softCascade()` alongside its model-specific children.

# Queries

## 1. Default scoping

Eloquent's `SoftDeletes` trait applies a **global scope** that adds `WHERE deleted_at IS NULL` to every query built against a soft-deleting model, automatically:

```php
Customer::where('company_id', 101)->get();
// SELECT * FROM customers WHERE company_id = 101 AND deleted_at IS NULL
```

Trashed rows require an explicit opt-in, gated by permission at the controller/policy layer, never left to the query builder alone:

```php
// Requires customers.read.trashed
Customer::onlyTrashed()->where('company_id', 101)->get();
// SELECT * FROM customers WHERE company_id = 101 AND deleted_at IS NOT NULL

// Requires customers.read.trashed (superset view)
Customer::withTrashed()->where('company_id', 101)->get();
// SELECT * FROM customers WHERE company_id = 101   (no deleted_at filter at all)
```

## 2. Local scopes

`HasGovernedSoftDeletes` (`# Soft Delete Strategy`) defines `scopeActiveOnly()` as a readability-focused, intention-revealing alternative to the implicit global scope, used anywhere the code should make the exclusion obvious rather than rely on Eloquent's default behavior silently doing the right thing:

```php
Customer::activeOnly()->forCompany($companyId)->get();
```

A companion scope surfaces a batch:

```php
Customer::trashedInBatch($batchId)->get();
```

## 3. Joins require an explicit predicate

The global scope only protects queries built through the Eloquent model it's attached to. Raw SQL, query-builder joins, and reporting queries that touch a soft-deleting table via `DB::table()` or a `JOIN` must repeat the predicate explicitly — this is the single most common soft-delete bug in production systems, and QAYD's code-review checklist calls it out by name:

```sql
-- WRONG: trashed customers leak into the report because the JOIN has no
-- deleted_at guard of its own; Eloquent's global scope does not reach
-- into a raw JOIN clause.
SELECT i.invoice_number, c.name_en
FROM invoices i
JOIN customers c ON c.id = i.customer_id
WHERE i.company_id = 101 AND i.deleted_at IS NULL;

-- CORRECT: every soft-deleting table in the FROM/JOIN list gets its own
-- deleted_at IS NULL predicate.
SELECT i.invoice_number, c.name_en
FROM invoices i
JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
WHERE i.company_id = 101 AND i.deleted_at IS NULL;
```

For a `LEFT JOIN`, the guard belongs in the `ON` clause, not `WHERE` — putting it in `WHERE` silently turns the `LEFT JOIN` into an `INNER JOIN` whenever the right-hand row is trashed:

```sql
-- CORRECT: preserves left-side rows even when the related customer is trashed.
SELECT i.invoice_number, c.name_en
FROM invoices i
LEFT JOIN customers c ON c.id = i.customer_id AND c.deleted_at IS NULL
WHERE i.company_id = 101 AND i.deleted_at IS NULL;
```

## 4. Reporting queries that intentionally need trashed data

Financial reports (Trial Balance, historical invoice listings) must still display the name of a customer who was later soft-deleted — the report describes the past, and the past does not change because someone deleted a master-data row today. Eloquent relations used for reporting explicitly opt into `withTrashed()` on the relation itself:

```php
public function customer()
{
    return $this->belongsTo(Customer::class)->withTrashed();
}
```

This is safe specifically because it is a `belongsTo` toward master data for **display purposes only** — it never governs which `invoices` rows themselves are returned (those still obey `deleted_at IS NULL` on `invoices`).

## 5. Dashboard / operational queries

```sql
-- Count of currently trashed rows per table, for an admin "Recycle Bin" dashboard.
SELECT 'customers' AS entity, count(*) FROM customers WHERE company_id = 101 AND deleted_at IS NOT NULL
UNION ALL
SELECT 'vendors',   count(*) FROM vendors  WHERE company_id = 101 AND deleted_at IS NOT NULL
UNION ALL
SELECT 'products',  count(*) FROM products WHERE company_id = 101 AND deleted_at IS NOT NULL;

-- Rows approaching permanent purge within 7 days, for a pre-purge warning notification.
SELECT id, code, deleted_at
FROM customers
WHERE company_id = 101
  AND deleted_at IS NOT NULL
  AND deleted_at < now() - interval '358 days'   -- 365-day policy minus 7-day warning
  AND deleted_at >= now() - interval '365 days';
```

# Indexes

## 1. The partial-index pattern, applied platform-wide

Every soft-deleting tenant table follows the same two-index minimum: one partial index supporting the hot "active rows for this company" access path, and one partial unique index enforcing the table's business key among active rows only (`# Soft Delete Strategy`). The general template:

```sql
CREATE INDEX idx_<table>_company_active
    ON <table> (company_id)
    WHERE deleted_at IS NULL;

CREATE UNIQUE INDEX uq_<table>_<business_key>_active
    ON <table> (company_id, <business_key_column>)
    WHERE deleted_at IS NULL;
```

Applied across the canonical tables named in the shared design context:

```sql
CREATE INDEX idx_customers_company_active     ON customers     (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_vendors_company_active       ON vendors       (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_products_company_active      ON products      (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_invoices_company_active      ON invoices      (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_bills_company_active         ON bills         (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_bank_accounts_company_active ON bank_accounts (company_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_accounts_company_active      ON accounts      (company_id) WHERE deleted_at IS NULL;

CREATE UNIQUE INDEX uq_customers_company_code_active   ON customers (company_id, code)           WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX uq_vendors_company_code_active     ON vendors   (company_id, code)           WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX uq_products_company_sku_active     ON products  (company_id, sku)            WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX uq_invoices_company_number_active  ON invoices  (company_id, invoice_number) WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX uq_bills_company_number_active     ON bills     (company_id, bill_number)    WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX uq_accounts_company_code_active    ON accounts  (company_id, code)           WHERE deleted_at IS NULL;
```

## 2. Reverse-direction index for lifecycle jobs

The purge sweep (`# Permanent Delete`) and the "approaching purge" warning query (`# Queries`) scan in the opposite direction from every user-facing query — they look for old **trashed** rows, not active ones — so they need their own partial index with the inverted predicate:

```sql
CREATE INDEX idx_customers_deleted_at_trashed
    ON customers (deleted_at)
    WHERE deleted_at IS NOT NULL;
```

Without this, a nightly purge sweep across a multi-million-row table would force a sequential scan once per run; with it, the sweep touches only the (typically small) trashed subset directly.

## 3. Why partial indexes and not full indexes with a query filter

A full (non-partial) unique index on `(company_id, code)` would have to include every trashed row forever, which (a) makes the index larger than it needs to be for the query pattern that actually matters (active-row lookups, which are the overwhelming majority of traffic), and (b) — critically — would make code reuse impossible, since a full unique index enforces uniqueness across trashed rows too, defeating the entire point of allowing a business key to be reused after deletion. The partial index is not a performance optimization layered on top of a full index; it is the only correct way to express "unique among active rows" as a database-enforced invariant at all.

## 4. Composite covering indexes for list endpoints

The most frequent query shape in the platform — "list active rows for company X, filtered by status, sorted by created_at desc, paginated" — gets a composite partial index matching that exact access pattern per high-traffic table:

```sql
CREATE INDEX idx_invoices_company_status_created_active
    ON invoices (company_id, status, created_at DESC)
    WHERE deleted_at IS NULL;
```

This lets the default invoice list endpoint (`GET /api/v1/sales/invoices?status=posted`) satisfy filter, sort, and the implicit soft-delete predicate from a single index scan with no separate sort step.

## 5. Index maintenance on restore

Restoring a row is, from the index's perspective, an `UPDATE` that moves the row from outside the partial index's predicate to inside it (or vice versa for delete). PostgreSQL handles this natively — a partial index is just a B-tree with a predicate checked at write time, not a materialized subset requiring a separate rebuild — but every restore, like every soft-delete, is a write that touches every partial index on the row, which is one more reason cascades are batched inside a single transaction (`# Relationships (soft-cascade)`) rather than issued as N separate round trips.

# Performance

## 1. Autovacuum tuning for high-churn tables

Tables that soft-delete and restore frequently (`sales_quotations`, `stock_adjustments`, cart-like draft entities) accumulate dead tuples from `UPDATE`s at a higher rate than typical OLTP tables, because every soft-delete and every restore is itself an `UPDATE`, not just the original `INSERT`. QAYD lowers the autovacuum threshold specifically on these tables rather than globally, to avoid paying the vacuum cost everywhere:

```sql
ALTER TABLE sales_quotations SET (
    autovacuum_vacuum_scale_factor = 0.02,
    autovacuum_vacuum_cost_delay   = 2
);

ALTER TABLE stock_adjustments SET (
    autovacuum_vacuum_scale_factor = 0.02
);
```

For platform-wide baseline tuning relevant to the soft-delete access pattern (many partial indexes, frequent targeted `UPDATE`s), `postgresql.conf` carries:

```ini
autovacuum_max_workers = 6
autovacuum_naptime = 15s
default_statistics_target = 200
random_page_cost = 1.1   # SSD-backed managed Postgres (RDS/Cloud SQL-class storage)
```

## 2. Planner usage of partial indexes depends on exact predicate match

PostgreSQL will only use a partial index when the query's `WHERE` clause provably implies the index's predicate. A query that omits `deleted_at IS NULL` entirely will not use `idx_customers_company_active` and falls back to a full scan or a less selective index — this is precisely why the Eloquent global scope (`# Queries`) is load-bearing for performance, not just correctness: every query that "forgets" the predicate is also a query that silently degrades from an index scan to a sequential scan as the trashed-row count grows. QAYD's CI includes an `EXPLAIN`-based test helper that fails a query test if `Seq Scan` appears in the plan for any query against a table over a configured row-count threshold.

## 3. Cache invalidation on lifecycle transitions

Redis-cached read models must be invalidated on soft-delete, restore, and purge — a cached "active customer list" that doesn't know a customer was just deleted is a correctness bug, not just a staleness inconvenience, since a deleted customer must disappear from pickers and autocomplete immediately. Cache key scheme:

```
company:{company_id}:customers:list:{filters_hash}
company:{company_id}:customers:item:{id}
```

The `AuditLogger`/lifecycle-hook layer (`# History`) publishes a domain event on every transition (`customer.deleted`, `customer.restored`, `customer.purged`) that a `CacheInvalidationListener` subscribes to, deleting both the specific item key and bumping a per-company `list:version` token embedded in every list-cache key, so list caches invalidate without an expensive key-pattern scan:

```php
class CacheInvalidationListener
{
    public function handle(EntityLifecycleEvent $event): void
    {
        Cache::forget("company:{$event->companyId}:{$event->entityPlural}:item:{$event->entityId}");
        Cache::increment("company:{$event->companyId}:{$event->entityPlural}:list:version");
    }
}
```

## 4. Batch operations avoid long-held locks

Bulk soft-delete (e.g., deleting several thousand stale `stock_movements` staging rows in one operator action) is chunked rather than issued as one `UPDATE` touching every row, to avoid holding row locks across a large table for the duration of one long transaction and blocking concurrent readers/writers on unrelated rows:

```sql
WITH batch AS (
    SELECT id FROM stock_movements
    WHERE company_id = 101 AND deleted_at IS NULL AND status = 'draft'
    ORDER BY id
    LIMIT 1000
)
UPDATE stock_movements sm
SET deleted_at = now(), deleted_by = 42, deletion_batch_id = gen_random_uuid()
FROM batch
WHERE sm.id = batch.id;
```

Repeated in a loop (Laravel `chunkById` wrapping the same pattern) until no rows remain, with a short sleep between chunks under sustained write load to let replication lag and autovacuum keep pace.

## 5. Connection and query latency targets

Scoped, indexed list queries against soft-deleting tables target p95 latency under 50 ms at the database layer for result sets returned through the standard paginated envelope (`per_page` default 25); this budget assumes PgBouncer transaction-mode pooling in front of PostgreSQL (avoiding per-request connection setup cost) and that every list endpoint's query plan is backed by the composite partial index described in `# Indexes` section 4. Endpoints falling outside this budget are treated as a missing-index defect, not an acceptable steady state, and are caught by the `EXPLAIN`-assertion CI check described above before merge.

## 6. Partitioning as the long-term performance answer for ledger-scale tables

Autovacuum and partial indexes solve churn and lookup speed on transactional tables, but they do not solve the eventual size problem on `journal_lines`/`ledger_entries` for a company with years of daily posting volume. `# Retention` section 4's fiscal-year partitioning is also the primary performance lever here: queries scoped to the current fiscal year — the overwhelming majority of live traffic (Trial Balance, current-period reports) — only ever touch the current partition, keeping working-set size and index size roughly constant year over year regardless of how much history has accumulated in detached, archived partitions.

# Audit

## 1. `audit_logs` is append-only by construction, not just by convention

Because `audit_logs` records the very deletions and restorations this document governs, its own immutability cannot depend on the same soft-delete mechanism it is auditing — an audit table that could itself be edited or soft-deleted would be circular and worthless as evidence. QAYD enforces this at the database level with triggers that reject `UPDATE` and `DELETE` outright, independent of any application code path:

```sql
CREATE FUNCTION prevent_audit_log_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'audit_logs rows are immutable and cannot be updated or deleted (attempted % on id=%)',
        TG_OP, OLD.id;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER audit_logs_no_update
    BEFORE UPDATE ON audit_logs
    FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();

CREATE TRIGGER audit_logs_no_delete
    BEFORE DELETE ON audit_logs
    FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_mutation();
```

The application's own database role is additionally stripped of the privilege at the grant level, so the trigger is defense-in-depth rather than the only line of protection:

```sql
REVOKE UPDATE, DELETE ON audit_logs FROM qayd_app_role;
GRANT INSERT, SELECT ON audit_logs TO qayd_app_role;
```

## 2. Segregation of duties on destructive operations

For any purge touching more than one row, or any row older than 30 days, or any table flagged `requires_dual_control` in `config/soft_deletes.php` (every table that is purgeable at all is flagged this way by default), execution requires a second, distinct human approver:

```sql
CREATE TABLE deletion_approvals (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id     BIGINT NOT NULL REFERENCES companies(id),
    target_type    VARCHAR(100) NOT NULL,
    target_id      BIGINT NOT NULL,
    action         VARCHAR(20) NOT NULL CHECK (action IN ('purge', 'legal_hold_release')),
    requested_by   BIGINT NOT NULL REFERENCES users(id),
    requested_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    approved_by    BIGINT NULL REFERENCES users(id),
    approved_at    TIMESTAMPTZ NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'approved', 'rejected', 'executed')),
    CHECK (requested_by IS DISTINCT FROM approved_by)
);

CREATE INDEX idx_deletion_approvals_pending
    ON deletion_approvals (company_id, status)
    WHERE status = 'pending';
```

The `CHECK (requested_by IS DISTINCT FROM approved_by)` constraint makes self-approval a database-level impossibility, not merely an application-level check that a bug could bypass.

```php
<?php

namespace App\Services\Audit;

use App\Models\DeletionApproval;
use App\Exceptions\SelfApprovalForbiddenException;

class DeletionApprovalService
{
    public function request(string $targetType, int $targetId, int $requestedBy): DeletionApproval
    {
        return DeletionApproval::create([
            'target_type'  => $targetType,
            'target_id'    => $targetId,
            'action'       => 'purge',
            'requested_by' => $requestedBy,
        ]);
    }

    public function approve(DeletionApproval $approval, int $approverId): DeletionApproval
    {
        if ($approval->requested_by === $approverId) {
            throw new SelfApprovalForbiddenException(
                code: 'SELF_APPROVAL_FORBIDDEN',
                message: 'The user who requested this purge cannot approve it.',
            );
        }

        $approval->update([
            'approved_by' => $approverId,
            'approved_at' => now(),
            'status'      => 'approved',
        ]);

        return $approval;
    }
}
```

## 3. What gets audited

Every one of the following is written to `audit_logs` with a distinct `action` value, no exceptions: `soft_deleted`, `restored`, `purge_requested`, `purge_approved`, `purge_rejected`, `purged`, `purge_failed`, `legal_hold_placed`, `legal_hold_released`, `retention_policy_changed`, `archive_exported`. Read access (viewing trashed data) is intentionally *not* written to `audit_logs` at the same row-level granularity — logging every read at row level would itself become an unmanageable volume — but is captured at the aggregate level through standard API access logs, which is sufficient for the "who could have seen this" question without duplicating the mutation-focused audit trail.

## 4. Exposure to auditors

`GET /api/v1/compliance/deletion-report` (`# Compliance`) is the primary auditor-facing surface over this data; direct SQL access to `audit_logs` is restricted to the `External Auditor` role connecting through a dedicated read-only replica connection string, never the primary write connection, so an audit query — however large or slow — can never contend with production write traffic or, more importantly, can never be positioned to mutate the very evidence it is reviewing.

# What Can Never Be Deleted (posted journal entries)

## 1. The invariant

Once a `journal_entries` row transitions to `status = 'posted'`, neither that row nor any of its child `journal_lines` rows may ever be updated or deleted — not soft-deleted, not force-deleted — by any user regardless of role, by any API request regardless of permission grant, by any AI agent regardless of confidence score, or by any migration, seeder, or maintenance script. This is the single most important invariant in the entire QAYD database layer: the double-entry ledger is only trustworthy as a financial record if every posted fact, once recorded, is permanent. `Owner` has no special override. There is no `force_delete_posted_entry` permission anywhere in the system, because creating one would mean the general ledger is not actually a ledger.

## 2. Enforcement at the database layer

Database-level enforcement is the authoritative layer — it holds even if every layer of application code has a bug, is bypassed, or is called directly against the database by a future engineer who has not read this document:

```sql
CREATE FUNCTION prevent_posted_journal_mutation() RETURNS trigger AS $$
BEGIN
    IF TG_TABLE_NAME = 'journal_entries' THEN
        IF OLD.status = 'posted' THEN
            RAISE EXCEPTION 'journal_entries id=% is posted and immutable (attempted %)', OLD.id, TG_OP;
        END IF;
    END IF;

    IF TG_TABLE_NAME = 'journal_lines' THEN
        IF EXISTS (
            SELECT 1 FROM journal_entries je
            WHERE je.id = OLD.journal_entry_id AND je.status = 'posted'
        ) THEN
            RAISE EXCEPTION 'journal_lines id=% belongs to a posted journal_entries row and is immutable (attempted %)', OLD.id, TG_OP;
        END IF;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER journal_entries_immutable_when_posted
    BEFORE UPDATE OR DELETE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();

CREATE TRIGGER journal_lines_immutable_when_posted
    BEFORE UPDATE OR DELETE ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();
```

Note the trigger fires on `UPDATE` as well as `DELETE`: immutability means the row cannot be *changed* at all post-posting, not merely that it cannot be removed. A posted `journal_entries` row's `status` column itself can only ever move forward to `'reversed'` through a dedicated, narrowly-scoped service path described below — never through a generic `UPDATE journal_entries SET ...` — so even that one legitimate transition does not rely on this trigger permitting an exception; it is implemented as a compensating `INSERT` of a new row, never a mutation of the old one.

## 3. Enforcement at the application layer (defense-in-depth)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Exceptions\ImmutablePostedEntryException;

class JournalEntry extends Model
{
    protected $fillable = ['company_id', 'branch_id', 'fiscal_period_id', 'entry_date', 'memo', 'status'];

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function delete()
    {
        $this->assertMutable('delete');
        return parent::delete();
    }

    public function forceDelete()
    {
        $this->assertMutable('force delete');
        return parent::forceDelete();
    }

    public function update(array $attributes = [], array $options = [])
    {
        if (array_diff_key($attributes, array_flip(['status'])) !== []) {
            $this->assertMutable('update');
        }
        return parent::update($attributes, $options);
    }

    protected function assertMutable(string $attemptedAction): void
    {
        if ($this->status === 'posted') {
            throw new ImmutablePostedEntryException(
                code: 'IMMUTABLE_POSTED_ENTRY',
                message: "Journal entry #{$this->id} is posted and cannot be {$attemptedAction}d. Create a reversing entry instead.",
            );
        }
    }
}
```

`JournalEntry` deliberately does not use `HasGovernedSoftDeletes` — it has no delete/restore lifecycle at all. This is an intentional structural signal to any engineer reading the model: the class shape itself communicates the tier from `# Soft Delete Strategy`'s classification table before a single test is run.

## 4. The only legitimate corrections

Two mechanisms exist for correcting a posted entry, and both create new rows rather than touching the old one:

1. **Reversing entry** — a new `journal_entries` row with `reversal_of_journal_entry_id` pointing at the original, carrying `journal_lines` with debit and credit swapped line-for-line, net effect zero against the original. `POST /api/v1/accounting/journal-entries/{id}/reverse` is the only endpoint permitted to read a posted entry's lines for this purpose, and it does so read-only, then writes an entirely new entry.
2. **Adjusting entry** — a new, independent journal entry posted in the current (still-open) fiscal period that corrects the financial effect going forward, used when the original period is closed and a reversal into a closed period is not permitted (see section 5, below).

```sql
ALTER TABLE journal_entries
    ADD COLUMN reversal_of_journal_entry_id BIGINT NULL REFERENCES journal_entries(id);

CREATE INDEX idx_journal_entries_reversal_of
    ON journal_entries (reversal_of_journal_entry_id)
    WHERE reversal_of_journal_entry_id IS NOT NULL;
```

## 5. Fiscal period locking extends the same immutability upstream

```sql
ALTER TABLE fiscal_periods
    ADD CONSTRAINT fiscal_periods_status_check
    CHECK (status IN ('open', 'closed', 'locked'));

CREATE FUNCTION prevent_posting_into_closed_period() RETURNS trigger AS $$
DECLARE
    period_status TEXT;
BEGIN
    IF NEW.status = 'posted' THEN
        SELECT fp.status INTO period_status
        FROM fiscal_periods fp
        WHERE fp.id = NEW.fiscal_period_id;

        IF period_status IN ('closed', 'locked') THEN
            RAISE EXCEPTION 'Cannot post journal_entries id=% into fiscal_period_id=% with status=%',
                NEW.id, NEW.fiscal_period_id, period_status;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER journal_entries_respect_period_lock
    BEFORE INSERT OR UPDATE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION prevent_posting_into_closed_period();
```

A closed `fiscal_periods` row is itself never soft-deletable or purgeable — it is added to the `IMMUTABLE` tier the moment any `journal_entries` row references it as `posted`, enforced by the same `guardAgainstDeletion()` pattern used elsewhere in this document, applied to `FiscalPeriod`.

## 6. Full inventory of never-deletable categories

| Entity | Trigger condition | Compensating action |
|---|---|---|
| `journal_entries` / `journal_lines` | `status = 'posted'` | Reversing entry |
| `ledger_entries` | Always (pure projection, no direct writes ever) | Regenerated from posted `journal_lines` only |
| `fiscal_periods` | `status IN ('closed','locked')` | None — reopen only via a dedicated, dual-controlled `reopen` action logged to `audit_logs` |
| `bank_reconciliations` | `status = 'reconciled'` | New reconciliation adjustment entry |
| `tax_returns` | `status = 'filed'` | Amended return (`tax_returns.amends_return_id`) |
| `payslips` | `status = 'paid'` | Correction payslip in next payroll run |
| `invoices` | Has a `receipts`/`receipt_allocations` row in any non-voided state | Credit note (`credit_notes`) |
| `audit_logs` | Always | None — permanent by design (`# Audit`) |

# Examples

## Example 1 — Soft-deleting a draft sales quotation (allowed, cascades)

Request:

```
DELETE /api/v1/sales/quotations/5510
X-Company-Id: 101
Authorization: Bearer <token>
Content-Type: application/json

{ "deletion_reason": "customer_cancelled_request" }
```

Resulting SQL (inside one transaction):

```sql
BEGIN;

UPDATE sales_quotations
SET deleted_at = now(), deleted_by = 17, deletion_reason = 'customer_cancelled_request',
    deletion_batch_id = 'b2f1c9de-2222-4a10-9e77-000000000001'
WHERE id = 5510 AND deleted_at IS NULL;

UPDATE sales_quotation_items
SET deleted_at = now(), deleted_by = 17, deletion_reason = 'customer_cancelled_request',
    deletion_batch_id = 'b2f1c9de-2222-4a10-9e77-000000000001'
WHERE sales_quotation_id = 5510 AND deleted_at IS NULL;

INSERT INTO audit_logs (company_id, user_id, action, auditable_type, auditable_id, old_values, reason, created_at)
VALUES (101, 17, 'soft_deleted', 'App\Models\SalesQuotation', 5510, '{"status":"draft"}', 'customer_cancelled_request', now());

COMMIT;
```

Response:

```json
{
  "success": true,
  "data": { "id": 5510, "deleted_at": "2026-07-16T09:30:11Z" },
  "message": "Sales quotation deleted.",
  "errors": [],
  "meta": {},
  "request_id": "77b1e4f0-1111-4a10-9e77-000000000002",
  "timestamp": "2026-07-16T09:30:11Z"
}
```

## Example 2 — Blocked deletion (customer with open invoices)

```
DELETE /api/v1/sales/customers/4821
```

```json
{
  "success": false,
  "data": null,
  "message": "Customer cannot be deleted while open or unpaid invoices exist.",
  "errors": [ { "code": "CUSTOMER_HAS_OPEN_INVOICES" } ],
  "meta": {},
  "request_id": "1a2b3c4d-5555-4a10-9e77-000000000003",
  "timestamp": "2026-07-16T09:31:02Z"
}
```

HTTP status: `422`.

## Example 3 — Restore conflict and resolution

```
POST /api/v1/purchasing/vendors/990/restore
```

```json
{
  "success": false,
  "data": null,
  "message": "Code 'VEND-0044' is already in use by an active vendor. Rename before restoring.",
  "errors": [ { "code": "RESTORE_CODE_CONFLICT", "field": "code" } ],
  "meta": {},
  "request_id": "6c7d8e9f-6666-4a10-9e77-000000000004",
  "timestamp": "2026-07-16T09:32:40Z"
}
```

Resolution — rename the active conflicting vendor first, then retry:

```
PATCH /api/v1/purchasing/vendors/1204 { "code": "VEND-0044-OLD" }
POST  /api/v1/purchasing/vendors/990/restore
```

```json
{
  "success": true,
  "data": { "id": 990, "code": "VEND-0044", "deleted_at": null },
  "message": "Vendor restored successfully.",
  "errors": [],
  "meta": {},
  "request_id": "9f8e7d6c-7777-4a10-9e77-000000000005",
  "timestamp": "2026-07-16T09:34:12Z"
}
```

## Example 4 — Attempting to delete a posted journal entry

```
DELETE /api/v1/accounting/journal-entries/33107
```

```json
{
  "success": false,
  "data": null,
  "message": "Journal entry #33107 is posted and cannot be deleted. Create a reversing entry instead.",
  "errors": [ { "code": "IMMUTABLE_POSTED_ENTRY" } ],
  "meta": {},
  "request_id": "0a1b2c3d-8888-4a10-9e77-000000000006",
  "timestamp": "2026-07-16T09:35:00Z"
}
```

HTTP status: `422`. The correct follow-up:

```
POST /api/v1/accounting/journal-entries/33107/reverse
{ "memo": "Reversal: duplicate posting", "entry_date": "2026-07-16" }
```

```json
{
  "success": true,
  "data": {
    "id": 33481,
    "reversal_of_journal_entry_id": 33107,
    "status": "posted",
    "lines": [
      { "account_id": 4010, "debit": "0.0000",   "credit": "1250.0000" },
      { "account_id": 1100, "debit": "1250.0000", "credit": "0.0000" }
    ]
  },
  "message": "Reversing entry posted.",
  "errors": [],
  "meta": {},
  "request_id": "5d4c3b2a-9999-4a10-9e77-000000000007",
  "timestamp": "2026-07-16T09:36:55Z"
}
```

## Example 5 — Purge dry-run output

```
$ php artisan qayd:purge-expired --dry-run

[DRY-RUN] Purge candidate: App\Models\Customer#4102
[DRY-RUN] Purge candidate: App\Models\Customer#4188
[DRY-RUN] Purge candidate: App\Models\Product#8834
[DRY-RUN] Purge candidate: App\Models\SalesQuotation#5321
[DRY-RUN] Purge candidate: App\Models\SalesQuotation#5322
5 candidate(s) found across 3 model(s). No rows were deleted (--dry-run).
```

## Example 6 — Full vertical slice: Vendor deletion, from route to model

```php
// routes/api.php
Route::delete('/purchasing/vendors/{vendor}', [VendorController::class, 'destroy'])
    ->middleware('permission:vendors.delete');
Route::post('/purchasing/vendors/{vendor}/restore', [VendorController::class, 'restore'])
    ->middleware('permission:vendors.restore');

// app/Http/Requests/DeleteVendorRequest.php
class DeleteVendorRequest extends FormRequest
{
    public function rules(): array
    {
        return ['deletion_reason' => ['required', 'string', 'max:500']];
    }
}

// app/Http/Controllers/Api/V1/VendorController.php
class VendorController extends Controller
{
    public function __construct(private VendorService $vendors) {}

    public function destroy(DeleteVendorRequest $request, Vendor $vendor)
    {
        $this->vendors->delete($vendor, $request->validated('deletion_reason'));

        return response()->json([
            'success' => true, 'data' => ['id' => $vendor->id, 'deleted_at' => $vendor->deleted_at],
            'message' => 'Vendor deleted.', 'errors' => [], 'meta' => [],
            'request_id' => (string) Str::uuid(), 'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function restore(int $id, VendorRestoreService $restoreService)
    {
        $vendor = $restoreService->restore($id);

        return response()->json([
            'success' => true, 'data' => $vendor, 'message' => 'Vendor restored successfully.',
            'errors' => [], 'meta' => [], 'request_id' => (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

// app/Services/Purchasing/VendorService.php
class VendorService
{
    public function delete(Vendor $vendor, string $reason): void
    {
        request()->merge(['deletion_reason' => $reason]);
        $vendor->delete(); // triggers guardAgainstDeletion(), softCascade(), audit log
    }
}
```

This mirrors the `Customer` pattern shown in `# Soft Delete Strategy` exactly — every soft-deletable entity in QAYD is required to follow this same Controller → FormRequest → Service → Model shape (Clean Architecture, per the shared platform context), so a Vendor, a Customer, or a Product deletion path is structurally identical and equally auditable.

# End of Document
