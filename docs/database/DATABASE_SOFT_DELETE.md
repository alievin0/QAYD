# Soft Delete Strategy — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: Soft Delete Strategy
---

# Purpose

QAYD is an AI Financial Operating System of record for double-entry accounting, sales, purchasing,
banking, inventory, payroll, and tax. In this domain, deletion is rarely a real business event —
what looks like "delete" is almost always "this record should no longer be active," "this document
was created by mistake and needs to disappear from active views," or "this person's data must be
erased for compliance reasons while the financial trail it produced remains intact." Treating all
three as a single `DELETE FROM table WHERE id = ?` statement destroys information that auditors,
tax authorities, and the company itself are legally and operationally required to retain.

This document defines QAYD's soft delete strategy: the `deleted_at TIMESTAMPTZ` convention applied
uniformly across every tenant table, the Laravel `SoftDeletes` trait and global scope mechanics that
implement it, how queries must be written to respect it, how uniqueness and foreign-key integrity are
preserved once "deleted" rows can coexist with active ones, the small, explicit set of hard-delete
exceptions permitted, and the operational concerns — performance, auditing, restore workflows, and
edge cases — that a soft-delete-everywhere policy introduces. It is binding for every engineer
authoring a migration or an Eloquent model in the Laravel 12 / PostgreSQL 15+ backend.

This document does not define archiving (moving cold data to separate storage or partitions for
performance) — see `DATABASE_ARCHIVING.md` for that concern, referenced in the `# Archiving vs Soft
Delete` section below. It also does not define the audit log schema in full; only the deletion-
specific slice of `audit_logs` is covered here.

# Policy

1. **Financial and master data are never hard-deleted.** Every table listed in the canonical table
   set — `accounts`, `journal_entries`, `journal_lines`, `ledger_entries`, `customers`, `vendors`,
   `products`, `invoices`, `invoice_items`, `bills`, `bill_items`, `bank_accounts`,
   `bank_transactions`, `inventory_items`, `stock_movements`, `payroll_runs`, `payslips`,
   `tax_transactions`, and every other tenant-owned business table — carries a nullable
   `deleted_at TIMESTAMPTZ` column. A row is considered **active** when `deleted_at IS NULL` and
   **soft-deleted** when `deleted_at IS NOT NULL`.
2. **Soft delete is the ONLY delete operation exposed to the API, to users, and to the AI layer.**
   `DELETE /api/v1/{resource}/{id}` always maps to `UPDATE ... SET deleted_at = now(), updated_by = ?`
   never to `DELETE FROM ...`. The AI layer never issues hard deletes under any circumstance — see
   `# Hard Delete Exceptions` for the narrow, human-gated cases where physical removal occurs at all.
3. **`deleted_at` is a business-visible state, not a hidden implementation detail.** It participates
   in permission checks (`accounting.journal.delete`, `sales.invoice.delete`, ...), in the audit log,
   and in every default query scope. A soft-deleted row is invisible to normal application flow but
   remains fully queryable by administrators, auditors, and compliance tooling via `withTrashed()`.
4. **Posted, immutable financial records cannot be soft-deleted either — they must be voided or
   reversed.** Soft delete communicates "this record should not have existed"; a posted journal
   entry, by definition, DID exist and affected the ledger. See `# What Can Never Be Deleted`.
5. **Cascading a delete never silently destroys unrelated financial history.** Deleting a parent
   (e.g. a `customer`) soft-deletes the parent only; child financial documents (`invoices`) are
   evaluated independently under the rules in `# Foreign Keys & Cascades With Soft Delete`.
6. **Retention overrides erasure.** Where a legal retention duty (tax law, AML, audit trail) conflicts
   with a data-subject erasure request, financial retention wins for the accounting-relevant fields;
   only the personally-identifying fields not required for the financial record are erased. See
   `# Hard Delete Exceptions`.

DDL convention — every migration that creates a tenant table includes:

```sql
CREATE TABLE invoices (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    branch_id     BIGINT NULL REFERENCES branches(id),
    -- ... business columns ...
    created_by    BIGINT NULL REFERENCES users(id),
    updated_by    BIGINT NULL REFERENCES users(id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ NULL
);

CREATE INDEX idx_invoices_company_id   ON invoices (company_id);
CREATE INDEX idx_invoices_deleted_at   ON invoices (deleted_at);
CREATE INDEX idx_invoices_company_live ON invoices (company_id) WHERE deleted_at IS NULL;
```

The `idx_invoices_company_live` partial index is the one that matters for day-to-day query
performance: nearly every list/search endpoint filters `company_id = ? AND deleted_at IS NULL`, and a
partial index on exactly that predicate keeps the index small and the planner's choice cheap even as
soft-deleted rows accumulate over years.

# Laravel SoftDeletes Trait & Global Scope

Every Eloquent model backed by a tenant table uses Laravel's built-in `Illuminate\Database\Eloquent\
SoftDeletes` trait. The trait registers a global scope (`SoftDeletingScope`) that automatically
appends `WHERE deleted_at IS NULL` to every query built through the model unless the scope is
explicitly removed, and it overrides `delete()` to perform an `UPDATE` instead of a `DELETE`.

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Invoice extends Model
{
    use SoftDeletes;

    protected $table = 'invoices';

    protected $casts = [
        'deleted_at'   => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
        'total_amount' => 'decimal:4',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'status',
        'currency_code', 'exchange_rate', 'total_amount', 'base_total_amount',
    ];

    // Stamp updated_by / deleted_by on every soft delete, and refuse to delete a posted invoice.
    protected static function booted(): void
    {
        static::deleting(function (Invoice $invoice) {
            if ($invoice->status === 'posted') {
                throw new \App\Exceptions\ImmutableRecordException(
                    'Posted invoices cannot be deleted. Void or credit-note it instead.'
                );
            }
            $invoice->updated_by = auth()->id();
            $invoice->saveQuietly(); // persist updated_by before the deleted_at UPDATE fires
        });

        static::deleted(function (Invoice $invoice) {
            \App\Models\AuditLog::recordDeletion($invoice, auth()->user());
        });

        static::restored(function (Invoice $invoice) {
            \App\Models\AuditLog::recordRestore($invoice, auth()->user());
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
```

Every tenant model additionally uses a project-wide `BelongsToCompany` trait that scopes queries to
`company_id` from the request's `X-Company-Id` context; that trait and `SoftDeletes` compose cleanly
because Laravel supports multiple global scopes per model:

```php
class Invoice extends Model
{
    use SoftDeletes, BelongsToCompany, HasAuditedMutations;
}
```

`SoftDeletes` requires no manual boilerplate for the column name (`deleted_at` is the framework
default and QAYD never renames it) but the migration must declare it explicitly:

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained('companies');
    $table->foreignId('branch_id')->nullable()->constrained('branches');
    $table->foreignId('customer_id')->constrained('customers');
    $table->string('status', 20)->default('draft');
    $table->char('currency_code', 3)->default('KWD');
    $table->decimal('exchange_rate', 18, 6)->default(1);
    $table->decimal('total_amount', 19, 4)->default(0);
    $table->decimal('base_total_amount', 19, 4)->default(0);
    $table->foreignId('created_by')->nullable()->constrained('users');
    $table->foreignId('updated_by')->nullable()->constrained('users');
    $table->timestamps();      // created_at, updated_at
    $table->softDeletes();     // deleted_at TIMESTAMPTZ NULL, indexed
    $table->index('company_id');
});
```

`$table->softDeletes()` generates a plain (non-partial) B-tree index on `deleted_at` by default;
QAYD's convention adds the partial composite index shown in `# Policy` as a follow-up statement in
the same migration, using raw SQL because Laravel's schema builder does not express partial indexes
natively for every driver:

```php
DB::statement('CREATE INDEX idx_invoices_company_live ON invoices (company_id) WHERE deleted_at IS NULL');
```

# Querying

Laravel's `SoftDeletingScope` changes the default behavior of every Eloquent query on a soft-
deleting model. Engineers must know the three explicit modes and choose deliberately — never rely on
implicit behavior for anything touching money.

**Default (excludes deleted).** `Invoice::where('status', 'overdue')->get()` compiles to:

```sql
SELECT * FROM invoices
WHERE status = 'overdue' AND company_id = ? AND deleted_at IS NULL;
```

This is the correct and default mode for every controller action a normal user triggers: dashboards,
lists, search, exports, AI context retrieval. The AI layer's Laravel-mediated data access (Section 7
of the platform's shared design context) must always use this default mode; it is never given raw
SQL access, so `withTrashed()` is only reachable through an explicit, permissioned service method.

**`withTrashed()` (includes deleted).** Used by audit views, admin "Recently Deleted" screens, and
recovery tooling:

```php
$allInvoices = Invoice::withTrashed()
    ->where('company_id', $companyId)
    ->orderByDesc('deleted_at')
    ->paginate(25);
```

```sql
SELECT * FROM invoices WHERE company_id = ?;   -- no deleted_at predicate at all
```

**`onlyTrashed()` (deleted only).** Powers the "Trash / Recently Deleted" UI and the restore
workflow's listing endpoint:

```php
$trashed = Invoice::onlyTrashed()
    ->where('company_id', $companyId)
    ->where('deleted_at', '>=', now()->subDays(30))
    ->get();
```

```sql
SELECT * FROM invoices WHERE company_id = ? AND deleted_at >= ? AND deleted_at IS NOT NULL;
```

**Eager-loaded relations respect the same rule independently.** `Invoice::with('items')->find($id)`
excludes soft-deleted `invoice_items` unless the relation is redefined to include trashed rows:

```php
public function items()
{
    return $this->hasMany(InvoiceItem::class)->withTrashed();
}
```

This matters for financial correctness: if a line item on a posted invoice were ever soft-deleted
(which `# What Can Never Be Deleted` in fact forbids for posted documents), a naive `with('items')`
would silently understate the invoice total in any code that recomputes it from relations rather
than from the stored `total_amount`.

**Repository layer contract.** Per the Controller → FormRequest → Service → Repository → Model
architecture, repositories are the only place `withTrashed()` / `onlyTrashed()` may appear; services
and controllers call named repository methods (`findIncludingTrashed()`, `listTrashed()`) rather than
chaining scope methods themselves. This keeps the "who is allowed to see deleted data" decision
centralized and auditable.

```php
class InvoiceRepository
{
    public function activeById(int $companyId, int $id): ?Invoice
    {
        return Invoice::where('company_id', $companyId)->find($id);
    }

    public function includingTrashedById(int $companyId, int $id): ?Invoice
    {
        $this->authorizeTrashedAccess();
        return Invoice::withTrashed()->where('company_id', $companyId)->find($id);
    }

    public function trashed(int $companyId, int $days = 30): \Illuminate\Support\Collection
    {
        $this->authorizeTrashedAccess();
        return Invoice::onlyTrashed()
            ->where('company_id', $companyId)
            ->where('deleted_at', '>=', now()->subDays($days))
            ->get();
    }

    private function authorizeTrashedAccess(): void
    {
        if (! auth()->user()?->can('accounting.deleted.view')) {
            throw new \Illuminate\Auth\Access\AuthorizationException();
        }
    }
}
```

# Unique Constraints With Soft Delete

A plain `UNIQUE` constraint on, say, `vendors (company_id, tax_registration_number)` breaks the
moment a vendor is soft-deleted and the company later onboards a new, unrelated vendor that happens
to reuse the same tax registration number (common when a vendor is re-added after being deleted by
mistake, or when a code like an invoice number must be reusable once its original document is gone).
PostgreSQL's ordinary unique index enforces uniqueness across ALL rows regardless of `deleted_at`,
which is not what QAYD needs.

**Solution: a partial unique index scoped to live rows.**

```sql
-- Vendor tax registration number must be unique among ACTIVE vendors only.
CREATE UNIQUE INDEX uq_vendors_company_tax_reg_live
    ON vendors (company_id, tax_registration_number)
    WHERE deleted_at IS NULL AND tax_registration_number IS NOT NULL;

-- Invoice number must be unique among ACTIVE invoices only, per company.
CREATE UNIQUE INDEX uq_invoices_company_number_live
    ON invoices (company_id, invoice_number)
    WHERE deleted_at IS NULL;

-- SKU uniqueness among active products only.
CREATE UNIQUE INDEX uq_products_company_sku_live
    ON products (company_id, sku)
    WHERE deleted_at IS NULL;
```

Once a row is soft-deleted, it drops out of the partial index's predicate and no longer participates
in the uniqueness check, so a new active row can legally reuse the value. A second soft-deleted row
with the same value is also permitted (soft-deleted rows are never unique-constrained against each
other), which is intentional: QAYD does not need "no two deleted rows can share a SKU" — only "no
two ACTIVE rows can."

Laravel migration syntax for a partial unique index (the schema builder's `unique()` cannot express a
`WHERE` clause, so this is always raw SQL inside the migration's `up()`):

```php
public function up(): void
{
    Schema::table('invoices', function (Blueprint $table) {
        $table->string('invoice_number', 40);
    });

    DB::statement(<<<'SQL'
        CREATE UNIQUE INDEX uq_invoices_company_number_live
            ON invoices (company_id, invoice_number)
            WHERE deleted_at IS NULL
    SQL);
}

public function down(): void
{
    DB::statement('DROP INDEX IF EXISTS uq_invoices_company_number_live');
    Schema::table('invoices', function (Blueprint $table) {
        $table->dropColumn('invoice_number');
    });
}
```

**Alternative: a generated column that folds `deleted_at` into the uniqueness key.** Where a partial
index is awkward (e.g. some ORMs/tools that inspect constraints do not surface partial indexes as
"real" unique constraints, which occasionally matters for tooling that generates foreign keys or
GraphQL schemas from introspection), QAYD allows a generated column pattern instead:

```sql
ALTER TABLE customers
    ADD COLUMN dedupe_key TEXT GENERATED ALWAYS AS (
        CASE WHEN deleted_at IS NULL THEN company_id::text || ':' || lower(email)
             ELSE NULL END
    ) STORED;

CREATE UNIQUE INDEX uq_customers_dedupe_key ON customers (dedupe_key);
```

When `deleted_at IS NOT NULL`, `dedupe_key` evaluates to `NULL`, and PostgreSQL unique indexes treat
`NULL` as distinct from every other `NULL` (multiple `NULL`s are allowed), so soft-deleted rows never
collide. This achieves the same effect as the partial index and is QAYD's preferred pattern only when
a tool in the stack specifically requires a "visible" unique constraint; the partial index in
`uq_*_live` naming is the default for all new tables because it is simpler and does not consume a
stored column.

Both patterns MUST be paired with **application-level pre-checks** in the FormRequest/Service layer
so users get a clean 422 validation error instead of a raw Postgres `23505 unique_violation` bubbling
up as a 500:

```php
public function rules(): array
{
    return [
        'invoice_number' => [
            'required', 'string', 'max:40',
            Rule::unique('invoices', 'invoice_number')
                ->where('company_id', $this->companyId())
                ->whereNull('deleted_at'),
        ],
    ];
}
```

# Foreign Keys & Cascades With Soft Delete

PostgreSQL's native `ON DELETE CASCADE` / `ON DELETE SET NULL` / `ON DELETE RESTRICT` only fire on a
real `DELETE` statement. Because QAYD never issues a real `DELETE` on business data, native FK cascade
actions are largely inert for the soft-delete path — cascading on soft delete has to be implemented in
the application layer, deliberately, per relationship. This is a feature, not a gap: it lets QAYD
apply different rules to different relationships instead of one blunt cascade behavior.

**Soft-cascade rule set:**

| Parent deleted | Child table | Rule | Rationale |
|---|---|---|---|
| `customers` | `invoices` (posted) | **Block** — refuse the parent soft delete (422) | An active customer with posted invoices still owes money; deleting them would orphan AR. |
| `customers` | `invoices` (draft) | **Soft-cascade** — soft-delete the draft invoices too | Drafts have no financial effect; they should disappear with the customer. |
| `customers` | `customer_contacts`, `customer_addresses` | **Soft-cascade** | Pure child records with no independent financial meaning. |
| `vendors` | `bills` (posted) | **Block** | Same reasoning as customers/invoices. |
| `products` | `inventory_items` (qty_on_hand > 0) | **Block** | Cannot delete a product that still has stock; force a stock adjustment/write-off first. |
| `products` | `price_list_items` | **Soft-cascade** | Pricing rows are meaningless without the product. |
| `journal_entries` | `journal_lines` | **N/A — never deletable if posted**; draft journals soft-cascade their lines | See `# What Can Never Be Deleted`. |
| `bank_accounts` | `bank_transactions` | **Block** if any transaction is unreconciled or reconciled; **Block** unconditionally | Bank accounts with any transaction history are never deletable — deactivate (`status = 'closed'`) instead. |
| `invoices` | `invoice_items` | **Soft-cascade** together, atomically, only while the invoice is in `draft` | Once posted, immutable — see below. |

Implementation lives in each parent model's `deleting` event, wrapped in a DB transaction so the
parent and its cascaded children soft-delete atomically:

```php
protected static function booted(): void
{
    static::deleting(function (Customer $customer) {
        DB::transaction(function () use ($customer) {
            $postedInvoiceCount = $customer->invoices()
                ->where('status', 'posted')
                ->count();

            if ($postedInvoiceCount > 0) {
                throw new \App\Exceptions\ForeignKeyProtectedException(
                    "Cannot delete customer #{$customer->id}: {$postedInvoiceCount} posted invoice(s) exist."
                );
            }

            // Soft-cascade harmless children.
            $customer->invoices()->where('status', 'draft')->each(fn ($inv) => $inv->delete());
            $customer->contacts()->delete();
            $customer->addresses()->delete();
        });
    });
}
```

**Orphan prevention on restore is the mirror problem.** Restoring a soft-deleted `invoice` whose
`customer_id` points at a still-soft-deleted `customer` would resurrect a document with an invisible
parent. The `restoring` event enforces parent-first restore:

```php
static::restoring(function (Invoice $invoice) {
    $customer = Customer::withTrashed()->find($invoice->customer_id);
    if ($customer && $customer->trashed()) {
        throw new \App\Exceptions\OrphanRestoreException(
            'Restore the customer before restoring this invoice.'
        );
    }
});
```

**Database-level backstop.** Even though cascading is application-driven, every FK column still
carries a real PostgreSQL foreign key with `ON DELETE RESTRICT` (never `CASCADE`) as a safety net
against any code path — a raw migration, a console `tinker` session, a bulk import script — that
attempts a genuine hard `DELETE`:

```sql
ALTER TABLE invoices
    ADD CONSTRAINT fk_invoices_customer
    FOREIGN KEY (customer_id) REFERENCES customers (id)
    ON DELETE RESTRICT;
```

`RESTRICT` guarantees that even a bug bypassing Eloquent entirely cannot silently cascade-hard-delete
financial history; the hard delete simply fails with a foreign key violation, which is the safe
failure mode.

# What Can Never Be Deleted

**Posted journal entries and their lines are permanently immutable — soft delete included.** Once a
`journal_entries` row transitions `draft → posted`, neither the entry nor its `journal_lines` may be
soft-deleted, updated, or hard-deleted under any role, including Owner and CFO. This is enforced at
three layers:

1. **Application guard** on the model:

```php
class JournalEntry extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry) {
            if ($entry->getOriginal('status') === 'posted' && $entry->isDirty()) {
                throw new \App\Exceptions\ImmutableRecordException(
                    'Posted journal entries cannot be modified. Reverse or void instead.'
                );
            }
        });

        static::deleting(function (JournalEntry $entry) {
            if ($entry->status === 'posted') {
                throw new \App\Exceptions\ImmutableRecordException(
                    'Posted journal entries cannot be deleted. Use JournalEntry::reverse() or void().'
                );
            }
        });
    }
}
```

2. **Database-level trigger** as the backstop, because application guards can be bypassed by a bug or
   a direct SQL client with production access:

```sql
CREATE OR REPLACE FUNCTION prevent_posted_journal_mutation() RETURNS trigger AS $$
BEGIN
    IF OLD.status = 'posted' THEN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'Posted journal entries cannot be deleted (id=%)', OLD.id;
        END IF;
        IF TG_OP = 'UPDATE' AND (NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
                                   OR NEW.status <> OLD.status
                                   OR NEW.total_debit <> OLD.total_debit
                                   OR NEW.total_credit <> OLD.total_credit) THEN
            RAISE EXCEPTION 'Posted journal entries are immutable (id=%)', OLD.id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_journal_entries_immutable
    BEFORE UPDATE OR DELETE ON journal_entries
    FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();
```

Note the trigger permits `status` to move from `posted` to `reversed` only through the dedicated
reversal path (which the trigger's `status <> OLD.status` check would otherwise also block); QAYD
implements this by having `JournalEntry::reverse()` create a brand-new reversing entry rather than
flip the original's status at all — the original stays `posted` forever, and a new entry with equal
and opposite debits/credits is posted alongside it, cross-referenced via `reversed_journal_entry_id`.
This sidesteps the trigger cleanly because the original row is truly never mutated.

3. **Permission-layer denial**: no permission key exists that grants "delete a posted journal entry."
   `accounting.journal.delete` is only ever checked against, and only ever succeeds for, journals in
   `draft` status; the permission matrix in the Accounting module doc does not define a bypass.

**The same immutability applies transitively** to: `ledger_entries` (a derived projection — it is
regenerated, never edited, and is truncate-and-rebuild only inside a maintenance job, never via user
action), posted `invoices` and `bills` (void/credit-note/debit-note instead of delete), released
`payroll_runs` and issued `payslips` (reversing payroll adjustment instead), and submitted
`tax_returns` / posted `tax_transactions` (amended return instead). The universal pattern is:
**correction is always additive** (a new, opposite, or adjusting record), never subtractive.

# Restore/Recovery Workflow

Soft delete exists precisely so restoration is possible. QAYD exposes a first-class recovery flow
rather than treating `withTrashed()` as a developer-only escape hatch.

**API surface:**

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/{module}/{resource}/trashed` | `<area>.deleted.view` | List soft-deleted rows for the active company, most recently deleted first. |
| POST | `/api/v1/{module}/{resource}/{id}/restore` | `<area>.deleted.restore` | Restore one row (and, per the cascade table, offer to restore soft-cascaded children). |
| DELETE | `/api/v1/{module}/{resource}/{id}/purge` | `<area>.deleted.purge` (Owner/CFO only) | Hard-delete a row that is ALREADY soft-deleted and eligible per `# Hard Delete Exceptions`. |

**Restore semantics:**

```php
class InvoiceService
{
    public function restore(int $companyId, int $invoiceId, User $actor): Invoice
    {
        return DB::transaction(function () use ($companyId, $invoiceId, $actor) {
            $invoice = Invoice::withTrashed()
                ->where('company_id', $companyId)
                ->findOrFail($invoiceId);

            abort_if(! $invoice->trashed(), 409, 'Invoice is not deleted.');

            $invoice->restore();                       // deleted_at = NULL
            $invoice->items()->onlyTrashed()->each(fn ($i) => $i->restore());
            $invoice->updated_by = $actor->id;
            $invoice->save();

            AuditLog::recordRestore($invoice, $actor);
            event(new InvoiceRestored($invoice));

            return $invoice->refresh();
        });
    }
}
```

**Restore window and visibility.** Soft-deleted rows remain restorable indefinitely by default (no
row is ever purged automatically); a company-level setting `soft_delete_retention_days` (default 365)
governs only how long a row is surfaced in the "Recently Deleted" UI before it is filtered from that
convenience list — the row itself, and its `withTrashed()` queryability, is unaffected. Actual
physical removal only ever happens through the narrow paths in `# Hard Delete Exceptions`, never on a
timer.

**Restore of a uniqueness collision.** If a soft-deleted `product` is restored and its SKU has since
been reused by a new active product, the partial unique index (`uq_products_company_sku_live`)
raises a `23505` on the `UPDATE ... SET deleted_at = NULL`. The service layer catches this and returns
a 409 with an actionable message rather than a generic 500:

```php
try {
    $product->restore();
} catch (\Illuminate\Database\QueryException $e) {
    if ($e->getCode() === '23505') {
        throw new \App\Exceptions\RestoreConflictException(
            "Cannot restore: SKU '{$product->sku}' is already used by another active product. ".
            "Rename the SKU before restoring."
        );
    }
    throw $e;
}
```

# Hard Delete Exceptions

Physical `DELETE FROM` is permitted in exactly four situations. Every one of them requires the row to
already be soft-deleted first (defense in depth: no code path may hard-delete an active row), and
every one is logged to `audit_logs` before the row disappears, since the row obviously cannot log its
own deletion afterward.

**1. True drafts with zero financial effect.** A `sales_quotations` draft, an unposted
`journal_entries` draft that was never saved beyond a single session, or an `ai_conversations`
scratch thread has never touched the ledger and never appeared on any statement. These MAY be hard-
deleted by their owner directly, without going through the soft-delete-then-purge two-step, because
there is nothing to preserve:

```php
class JournalEntryService
{
    public function discardDraft(JournalEntry $entry, User $actor): void
    {
        abort_unless($entry->status === 'draft', 403, 'Only drafts can be discarded.');
        abort_unless($entry->created_by === $actor->id || $actor->can('accounting.journal.delete.any'), 403);

        AuditLog::record('journal_entries', $entry->id, 'hard_delete', $actor, [
            'reason' => 'draft_discarded', 'snapshot' => $entry->load('lines')->toArray(),
        ]);

        $entry->lines()->forceDelete();
        $entry->forceDelete();
    }
}
```

Note the audit log stores a full JSON **snapshot** of the row (and its lines) at deletion time,
because after `forceDelete()` there is no other record of what the draft contained. This snapshot is
what makes hard-deleting a zero-impact draft safe: nothing financially relevant is lost, but nothing
is silently un-auditable either.

**2. Explicit administrative purge of already-soft-deleted, retention-expired rows.** Only
`Owner`/`CFO` (permission `<area>.deleted.purge`), only on rows where `deleted_at` is already set,
and only after the module's minimum retention period has elapsed (7 years for anything that ever
touched a posted financial document, per most GCC/Kuwait tax-record retention norms; configurable per
company/jurisdiction in `company_settings.financial_retention_years`). The purge endpoint re-checks
that the row was never posted:

```php
public function purge(int $companyId, string $table, int $id, User $actor): void
{
    abort_unless($actor->can('accounting.deleted.purge'), 403);

    $row = DB::table($table)->where('company_id', $companyId)->where('id', $id)->first();
    abort_if(! $row, 404);
    abort_if(is_null($row->deleted_at), 409, 'Row must be soft-deleted before it can be purged.');
    abort_if(($row->status ?? null) === 'posted', 409, 'Posted records can never be purged.');

    $retentionYears = Company::find($companyId)->financial_retention_years ?? 7;
    abort_if(
        Carbon::parse($row->deleted_at)->addYears($retentionYears)->isFuture(),
        409,
        "Retention period not yet elapsed ({$retentionYears} years from deletion)."
    );

    AuditLog::record($table, $id, 'hard_delete_purge', $actor, ['snapshot' => (array) $row]);
    DB::table($table)->where('id', $id)->delete();
}
```

**3. PII erasure for compliance / data-subject requests.** A GDPR-style "right to erasure" (or a
Kuwait PDPL-equivalent request) cannot force QAYD to delete a customer's invoice history — that
financial retention duty overrides the erasure request — but it CAN force removal of personally
identifying fields that are not themselves required for the financial record. QAYD handles this as a
**field-level scrub**, not a row deletion:

```php
class PiiErasureService
{
    /**
     * Erase personal data for a customer while preserving the financial trail.
     * Financial amounts, dates, statuses, and account linkages are untouched.
     */
    public function eraseCustomerPii(Customer $customer, User $actor): void
    {
        DB::transaction(function () use ($customer, $actor) {
            AuditLog::record('customers', $customer->id, 'pii_erasure', $actor, [
                'fields_erased' => ['name', 'email', 'phone', 'national_id', 'address'],
            ]);

            $customer->forceFill([
                'name'         => 'Erased Customer #' . $customer->id,
                'email'        => null,
                'phone'        => null,
                'national_id'  => null,
                'address'      => null,
                'pii_erased_at'=> now(),
            ])->save();

            $customer->contacts()->update(['name' => null, 'phone' => null, 'email' => null]);
            $customer->addresses()->delete(); // soft-delete; addresses have no financial meaning
        });
    }
}
```

The `invoices` rows tied to this customer keep their `customer_id`, `total_amount`, `currency_code`,
dates, and status exactly as posted — only the human-identifying attributes on `customers` and its
contact/address children are scrubbed. `pii_erased_at` flags the row so the UI can render "Erased
Customer #482" instead of a blank name, and so the erasure itself is discoverable and auditable
rather than indistinguishable from ordinary data.

**4. Non-financial, purely operational rows with no audit value.** Expired password-reset tokens,
device push-notification tokens, rate-limit counters, and similar ephemeral rows that live outside
the tenant business-table set may be hard-deleted on a schedule with no soft-delete step and no audit
log entry at all — they were never part of the financial or master-data surface this policy governs.
This exception is scoped narrowly to infrastructure tables explicitly marked `ephemeral` in their
migration comment; it is not a loophole for any table listed in the canonical table set.

**Interaction with financial retention, summarized:**

| Request | What happens |
|---|---|
| "Delete my invoice" (user) | Soft delete only. Refused entirely if posted. |
| "Delete this draft I never posted" | Hard delete allowed (Exception 1). |
| "Purge old deleted vendors" (Owner, after retention window) | Hard delete allowed (Exception 2), only if never posted-against. |
| "Erase my personal data" (data subject) | Field-level PII scrub (Exception 3); financial rows and amounts survive untouched. |
| "Delete this posted journal entry" | Never permitted, under any exception. Reverse it. |

# Archiving vs Soft Delete

Soft delete and archiving solve different problems and QAYD does not conflate them. **Soft delete is
about correctness and recoverability**: a row is hidden from active use but stays in its original
table, fully joinable, fully restorable, participating in the same indexes and constraints as live
data. **Archiving is about performance and cost at scale**: moving old-but-still-valid rows (typically
`journal_lines`, `ledger_entries`, and `stock_movements` older than the current fiscal year) out of
the hot table into cheaper, separately-indexed storage (partitioned tables or Cloudflare R2-backed
cold storage), without changing whether the data is "deleted" at all — archived rows are almost
always still `deleted_at IS NULL`, i.e. fully active and correct, just physically relocated.

A row can be soft-deleted without being archived (the common case: a mistaken draft invoice from
yesterday), and a row can be archived without ever being soft-deleted (a five-year-old posted journal
entry that is still perfectly valid and immutable, just moved to a yearly partition). The two
dimensions are independent columns of the same lifecycle, not sequential stages. Full partitioning
strategy, the `journal_lines_2024`, `journal_lines_2025` style partition scheme, retention-tiered
storage, and R2 cold-export jobs are specified in `DATABASE_ARCHIVING.md`; this document's only
contract with that one is: **archiving must never bypass the soft-delete and immutability rules
above** — an archive job may relocate a posted journal line, but it may never delete, mutate, or
un-delete one in the process.

# Performance

Soft-delete-everywhere has a direct, measurable cost: every table's living rows share space with an
ever-growing set of dead-but-undeleted rows, and every default query scope adds a `deleted_at IS NULL`
predicate that must be indexable or every list/search endpoint degrades as the table ages.

**Partial indexes are the primary lever.** As shown in `# Policy`, the standard per-table index is:

```sql
CREATE INDEX idx_<table>_company_live ON <table> (company_id) WHERE deleted_at IS NULL;
```

This index only contains entries for active rows, so its size tracks the *active* dataset, not the
all-time dataset. A company with 5 years of invoicing history and a 20% historical soft-delete rate
gets an index roughly 20% smaller and correspondingly faster than an unfiltered index would be — and
that gap widens every year as more rows accumulate on the deleted side. Every composite index that
backs a hot query (search, filtering, sorting) should be defined as partial in the same way:

```sql
CREATE INDEX idx_invoices_company_customer_live
    ON invoices (company_id, customer_id)
    WHERE deleted_at IS NULL;

CREATE INDEX idx_invoices_company_status_live
    ON invoices (company_id, status)
    WHERE deleted_at IS NULL;
```

**Table bloat from soft delete is real and must be monitored.** Unlike a hard `DELETE`, a soft delete
is an `UPDATE`, which in PostgreSQL's MVCC model leaves the old row version as dead tuple space to be
reclaimed by autovacuum — but the row itself never becomes reclaimable garbage the way a truly deleted
row does, because the "new" (soft-deleted) version is still a live row from Postgres's point of view.
Practically this means soft delete does NOT bloat tables the way people sometimes assume; the row
count simply grows and stays. The actual bloat risk is the ordinary UPDATE churn (each `deleted_at`
UPDATE produces a new heap tuple and marks the old one dead), which `autovacuum` handles under normal
settings. Tables with very high delete/restore churn (rare, but possible for `stock_movements`
correction workflows) should have `autovacuum_vacuum_scale_factor` tuned down:

```sql
ALTER TABLE stock_movements SET (autovacuum_vacuum_scale_factor = 0.05);
```

**Query planner guidance for `withTrashed()` paths.** Because `withTrashed()` queries omit the
`deleted_at` predicate entirely, they cannot use the partial `_live` indexes and instead need either a
plain B-tree on `(company_id, deleted_at)` for admin/audit screens, or — for genuinely large tables —
a `deleted_at` BRIN index, which is cheap to maintain and effective for the append-mostly, roughly
time-ordered pattern of deletion timestamps:

```sql
CREATE INDEX idx_invoices_deleted_at_brin ON invoices USING BRIN (deleted_at);
```

**Application-level guardrail:** any repository method that calls `withTrashed()` or `onlyTrashed()`
must always pair it with a `company_id` filter and, wherever the UI allows it, a bounded date range
(as shown in the `onlyTrashed()` example in `# Querying`) — an unscoped `withTrashed()` across a large
multi-year table is the single most common soft-delete-related performance regression, and code
review for any new repository method must check for it explicitly.

# Audit Of Deletions

Every soft delete, restore, and hard-delete-exception writes a row to `audit_logs` (the foundation
table shared across all modules) BEFORE the mutation completes, following the who/when/old/new/
reason/IP/device contract defined at the platform level.

```sql
CREATE TABLE audit_logs (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    table_name    VARCHAR(64) NOT NULL,
    record_id     BIGINT NOT NULL,
    action        VARCHAR(30) NOT NULL,   -- 'soft_delete' | 'restore' | 'hard_delete_draft'
                                           -- | 'hard_delete_purge' | 'pii_erasure'
    actor_id      BIGINT NULL REFERENCES users(id),
    actor_role    VARCHAR(50) NULL,
    reason        TEXT NULL,
    old_values    JSONB NULL,
    new_values    JSONB NULL,
    ip_address    INET NULL,
    device_info   TEXT NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_audit_logs_company_table_record
    ON audit_logs (company_id, table_name, record_id);
CREATE INDEX idx_audit_logs_action_created_at
    ON audit_logs (action, created_at);
```

`AuditLog::recordDeletion()` is called from every `deleted` model event (see the `Invoice` example in
`# Laravel SoftDeletes Trait & Global Scope`) and captures the full row as `old_values` so the audit
trail is self-contained even if the underlying business columns change shape later:

```php
class AuditLog extends Model
{
    public static function recordDeletion(Model $model, ?User $actor): void
    {
        static::create([
            'company_id'  => $model->company_id,
            'table_name'  => $model->getTable(),
            'record_id'   => $model->getKey(),
            'action'      => 'soft_delete',
            'actor_id'    => $actor?->id,
            'actor_role'  => $actor?->currentRole()?->name,
            'reason'      => request()->input('reason'),
            'old_values'  => $model->getOriginal(),
            'ip_address'  => request()->ip(),
            'device_info' => request()->userAgent(),
        ]);
    }

    public static function recordRestore(Model $model, ?User $actor): void
    {
        static::create([
            'company_id' => $model->company_id,
            'table_name' => $model->getTable(),
            'record_id'  => $model->getKey(),
            'action'     => 'restore',
            'actor_id'   => $actor?->id,
            'new_values' => $model->getAttributes(),
            'ip_address' => request()->ip(),
        ]);
    }
}
```

**Delete reason is mandatory on every user-facing delete endpoint.** The FormRequest behind
`DELETE /api/v1/{resource}/{id}` requires a `reason` string (min 3 characters) for anything in the
Accounting, Sales, Purchasing, Banking, Inventory, Payroll, or Tax modules; this is a UX/compliance
decision, not merely a technical one — auditors reviewing the "Recently Deleted" report need to see
*why*, not only *who/when*.

```php
class DeleteInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
```

**Reporting surface.** A dedicated `GET /api/v1/audit/deletions` endpoint (permission
`audit.deletions.view`, granted to Auditor/External Auditor/CFO/Owner by default) lets compliance
staff review every deletion/restore/purge across the company in one feed, filterable by table, actor,
date range, and action type — this is the primary tool an External Auditor uses during a financial
review, and it must never be gated behind module-specific permissions since audit oversight cuts
across every module.

# Edge Cases

- **Deleting then immediately re-creating the "same" record.** A user soft-deletes `customer #42`
  ("ACME Trading") and, minutes later, creates a brand-new customer also named "ACME Trading" with a
  different `id`. This is allowed and correct — soft delete does not merge or alias identities. If the
  intent was actually "undo my mistake," the UI must surface the trashed row and offer restore before
  allowing a same-named create, but the database layer itself imposes no such linkage.
- **Restoring a row whose unique sibling now exists.** Covered in `# Restore/Recovery Workflow` — the
  partial unique index raises `23505` on restore, and the service layer converts it into an
  actionable `409 RestoreConflictException` rather than a generic failure.
- **Soft-deleting a row that is mid-transaction elsewhere.** Two concurrent requests — one soft-
  deleting a `bank_account`, another posting a `bank_transactions` row against it — must not race. The
  `deleting` guard on `BankAccount` re-checks `bank_transactions()->exists()` inside the same
  transaction using `SELECT ... FOR UPDATE` on the parent row, so the delete either sees the
  just-inserted transaction and blocks, or the insert waits for the delete's transaction to commit or
  roll back first. Optimistic, unlocked existence checks are not sufficient for financial parents.
- **`deleted_at` set but `deleted_by` missing.** Laravel's `SoftDeletes` trait does not natively track
  "who" deleted a row on the row itself (only `audit_logs` does structurally). QAYD adds a nullable
  `deleted_by BIGINT REFERENCES users(id)` column alongside `deleted_at` on every financial table
  specifically so "who deleted this" is answerable from the row itself without an `audit_logs` join,
  which matters for fast list-view rendering ("deleted by Sara, 2 days ago"). The `deleting` event sets
  both columns atomically.
- **Cascading soft delete across a very large object graph.** Soft-deleting a `sales_order` with
  hundreds of `sales_order_items` and downstream `deliveries` must not perform hundreds of individual
  `UPDATE` statements inside a request-scoped transaction. QAYD batches these as a single `UPDATE ...
  WHERE parent_id = ?` per child table rather than per-row Eloquent model events, falling back to
  per-row events only where a model's own `deleting` hook needs to run business logic (e.g. releasing
  a stock reservation).
- **Timezone ambiguity in retention calculations.** `deleted_at` is `TIMESTAMPTZ`, always stored and
  compared in UTC; retention-window math (`# Hard Delete Exceptions`, Exception 2) must never compare
  against a naive local "now" — `Carbon::parse($row->deleted_at)->addYears($n)->isFuture()` is safe
  specifically because Carbon and Postgres both resolve `TIMESTAMPTZ` to an absolute instant, not a
  wall-clock string.
- **A soft-deleted row referenced by a webhook payload already sent.** If `invoice.created` fired a
  webhook and the invoice is later soft-deleted (while still a draft, so this is legal), the webhook
  consumer may still be holding stale data. QAYD fires a corresponding `invoice.deleted` webhook event
  on every soft delete of a row that previously had a `*.created`/`*.updated` event, so external
  systems can react, but QAYD does not attempt to "recall" already-delivered webhook payloads.
- **AI-assisted bulk cleanup suggestions.** The AI layer (e.g. a "Duplicate Vendor Detection" agent)
  may recommend deleting a batch of rows, but it can only ever produce a *draft list* for human
  approval; the actual `DELETE` API calls are issued by the human's confirmed action, individually
  permission-checked and individually audit-logged exactly as if the human had selected them by hand.
  No bulk-delete endpoint exists that accepts an AI-originated batch without a human confirmation step
  in between.

# End of Document

