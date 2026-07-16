# Audit Logging — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: Audit Logs
---

# Purpose

QAYD is an AI Financial Operating System. Every posted journal entry, every changed customer credit
limit, every voided invoice, and every AI-proposed adjustment must be reconstructable after the fact:
who did it, when, from where, on what device, why, and what the data looked like before and after.
`audit_logs` is the single system-wide ledger of mutation events across every tenant company. It exists
independently of application-level history features (e.g. invoice status timelines) because it must
survive even if the business feature that produced the change is later redesigned or removed.

This document specifies the `audit_logs` table, the capture strategy (application-layer vs
database-trigger, and the hybrid QAYD adopts), a worked PL/pgSQL trigger, the immutability and
tamper-evidence model, partitioning and retention, query/reconstruction patterns, performance
safeguards, privacy/PII masking, compliance framing, and edge cases. It is binding for every module
that mutates financial or master data: Accounting, Sales, Purchasing, Banking, Inventory, Payroll, Tax,
and the AI layer's write path through the Laravel API.

The guiding principle: **audit logging is not optional instrumentation bolted on later — it is a
first-class write path**. A mutation that is not audited did not happen, from a compliance standpoint.

# What Must Be Audited

QAYD audits four categories of events. Each category has a minimum required payload; modules may add
more context via `old_values`/`new_values`/`reason` but must never audit less than the minimum.

## 1. Financial and master-data mutations

Every `INSERT`, `UPDATE`, and soft-`DELETE` on a tenant-scoped business table. This includes, at minimum:

- **Accounting**: `accounts`, `journal_entries`, `journal_lines`, `fiscal_years`, `fiscal_periods`
  (opening/closing), `ledger_entries` (projection rebuilds are logged as a single batch event, not
  per-row).
- **Sales/AR**: `customers`, `sales_orders`, `invoices`, `invoice_items`, `credit_notes`, `receipts`,
  `receipt_allocations`.
- **Purchasing/AP**: `vendors`, `purchase_orders`, `bills`, `bill_items`, `debit_notes`,
  `vendor_payments`.
- **Banking**: `bank_accounts`, `bank_transactions`, `bank_reconciliations`, `transfers`.
- **Inventory**: `inventory_items`, `stock_movements`, `stock_adjustments`, `stock_transfers`,
  `stock_counts`, `inventory_valuations`.
- **Payroll**: `payroll_runs`, `payroll_items`, `salary_components`, `payslips`.
- **Tax**: `tax_codes`, `tax_rates`, `tax_transactions`, `tax_returns`.
- **Master data**: `products`, `price_lists`, `price_list_items`, `cost_centers`, `projects`,
  `warehouses`, `departments`, `branches`.

A "mutation" includes a *state transition* even when no column literally changes value in a way a naive
diff would show as interesting — e.g. `journal_entries.status: draft -> posted` is always audited even
if the triggering `UPDATE` also touched `updated_at` only.

## 2. Authentication and session events

`user.login_success`, `user.login_failed`, `user.logout`, `user.password_changed`,
`user.mfa_enabled`, `user.mfa_challenge_failed`, `user.session_revoked`, `user.impersonation_started`
(support/admin acting as a customer user — always logged with both actor and subject user ids).
These are written even though they do not have an `old_values`/`new_values` row diff in the traditional
sense; the "value" is the event itself, captured via `new_values` as a small JSON fact object,
e.g. `{"ip":"...", "result":"failed", "reason":"bad_password"}`.

## 3. Permission and access-control changes

Any change to `roles`, `permissions`, `role_permissions`, `company_users` (a user's role/company
membership), API token issuance/revocation (Sanctum tokens), and company-level settings that affect
security posture (e.g. enforced MFA, session timeout, IP allowlist). These are flagged with
`action = 'permission.granted' | 'permission.revoked' | 'role.assigned' | 'role.removed'` and are the
highest-priority audit rows for the Auditor and External Auditor roles to review.

## 4. AI actions

Every write the AI layer causes — whether autonomous or human-approved — is audited with
`actor_user_id` set to the *approving human* (or, for fully autonomous low-risk actions, the system
service user representing the AI agent, e.g. `ai:reporting-agent`), plus `entity_type = 'ai_action'`
metadata inside `new_values` capturing: agent name, confidence score, reasoning summary, source
document ids, and the downstream entity actually mutated (e.g. an AI-drafted journal entry that a human
then approved). AI never writes directly to the database (see DESIGN_CONTEXT §7); the audit row for the
resulting business mutation additionally carries `new_values.ai_assisted = true` and
`new_values.ai_confidence` so the trail distinguishes AI-originated changes from purely human ones.

## What is explicitly NOT audited at row-granularity

High-volume read-only operations (report views, dashboard loads), full-text search queries, and
`ledger_entries` per-row inserts when they are a mechanical projection of an already-audited
`journal_lines` post (the *cause* — the journal post — is audited; the *effect* — the GL projection
row — is derivable and is not separately audited to avoid doubling audit volume). This is a deliberate
scope boundary re-examined in `# Edge Cases`.

# audit_logs Table Design

```sql
-- Enum for the small, closed set of structural categories. The specific action string
-- (e.g. 'invoice.voided') stays a free-form indexed VARCHAR so new actions never require
-- a migration; the category exists for fast filtering by broad type.
CREATE TYPE audit_category AS ENUM (
    'data_mutation',
    'auth',
    'permission',
    'ai_action',
    'system'
);

CREATE TABLE audit_logs (
    id              BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id      BIGINT NULL REFERENCES companies(id),
        -- NULL only for platform-level events with no tenant context (e.g. a super-admin
        -- login before selecting a company). Every tenant mutation MUST set this.
    branch_id       BIGINT NULL REFERENCES branches(id),

    category        audit_category NOT NULL,
    action          VARCHAR(100) NOT NULL,
        -- dot-namespaced, e.g. 'journal_entry.posted', 'invoice.voided', 'user.login_failed',
        -- 'role.permission.granted', 'ai_action.journal_entry.drafted'

    entity_type     VARCHAR(100) NULL,       -- e.g. 'journal_entries', 'invoices', 'users'
    entity_id       BIGINT NULL,             -- PK of the affected row; NULL for auth/system events

    actor_user_id   BIGINT NULL REFERENCES users(id),
        -- NULL for unauthenticated events (e.g. failed login with unknown username) or
        -- system/cron-originated changes, which instead populate actor_service = 'scheduler:...'
    actor_service   VARCHAR(100) NULL,       -- e.g. 'ai:reporting-agent', 'scheduler:period-close'
    acting_as_user_id BIGINT NULL REFERENCES users(id),
        -- populated during impersonation: the real actor is actor_user_id (support/admin),
        -- acting_as_user_id is the customer user whose session was assumed

    old_values      JSONB NULL,
    new_values      JSONB NULL,
    changed_fields  TEXT[] NULL,
        -- denormalized list of top-level keys that differ between old_values and new_values,
        -- computed at write time to make "who changed column X" queries index-friendly

    reason          TEXT NULL,               -- required by app layer for voids/deletes/overrides
    request_id      UUID NULL,               -- correlates to the API response envelope's request_id
    ip_address      INET NULL,
    user_agent      TEXT NULL,
    device_id       VARCHAR(150) NULL,       -- mobile/device fingerprint if provided by client

    hash            CHAR(64) NULL,           -- SHA-256 of this row's canonical payload + prev_hash
    prev_hash       CHAR(64) NULL,           -- hash of the previous row in the same hash chain scope

    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
) PARTITION BY RANGE (created_at);

-- No `updated_at`, no `deleted_at`: audit_logs rows are append-only and never updated or
-- soft-deleted (see # Immutability & Tamper-Evidence). This is a deliberate departure from
-- the standard-columns convention in DESIGN_CONTEXT — that convention assumes mutable
-- business rows; audit_logs is the mechanism that records mutability of OTHER tables and
-- must not itself be mutable.

CREATE INDEX idx_audit_logs_company_created
    ON audit_logs (company_id, created_at DESC);

CREATE INDEX idx_audit_logs_entity
    ON audit_logs (entity_type, entity_id, created_at DESC);

CREATE INDEX idx_audit_logs_actor
    ON audit_logs (actor_user_id, created_at DESC);

CREATE INDEX idx_audit_logs_action
    ON audit_logs (company_id, action, created_at DESC);

CREATE INDEX idx_audit_logs_category
    ON audit_logs (company_id, category, created_at DESC);

CREATE INDEX idx_audit_logs_request
    ON audit_logs (request_id);

-- GIN indexes for ad-hoc JSONB search (used sparingly — see # Performance).
CREATE INDEX idx_audit_logs_new_values_gin
    ON audit_logs USING GIN (new_values jsonb_path_ops);
```

Notes on design choices:

- `id` is a per-partition-local identity column combined with `created_at` as the effective ordering
  key; global uniqueness across partitions is preserved because Postgres 15's `GENERATED ALWAYS AS
  IDENTITY` on a partitioned table allocates from a single sequence shared by all partitions.
- `entity_id` is a plain `BIGINT`, not a foreign key, by design: the referenced row may later be
  hard-deleted in rare, legally-mandated erasure cases (see `# Privacy`), and the audit row must
  survive that deletion. A dangling reference is expected and correct.
- `old_values`/`new_values` store the **full row snapshot** for `INSERT`/`DELETE`-equivalent events and
  a **narrowed diff object** (only changed keys) for `UPDATE` events, to keep row size bounded. The
  `changed_fields` array is always the complete list of top-level keys present in the diff, enabling
  `WHERE changed_fields @> ARRAY['status']`-style queries without unpacking JSONB per row.
- `reason` is nullable at the schema level but enforced NOT NULL by application validation for a
  specific allowlist of high-risk actions (void, delete, permission change, journal reversal,
  payroll override) — see `# Business Rules`-equivalent enforcement in the Laravel `FormRequest` layer.

# Capture Strategy

QAYD uses a **hybrid** capture strategy: application-layer capture via Laravel Eloquent model events
and observers is the primary mechanism, backed by a defense-in-depth PostgreSQL trigger on the small set
of tables where a row could theoretically be mutated outside Eloquent (raw SQL migrations of data,
manual `psql` intervention, a future non-Laravel service). The two approaches are compared below.

| Aspect | App-layer (Eloquent observers) | DB triggers (PL/pgSQL) |
|---|---|---|
| Captures actor/request context (user id, IP, request_id) | Yes — natively available in the request lifecycle | No — the database has no notion of an HTTP actor unless the app sets a session variable per transaction |
| Captures changes from raw SQL / migrations / other services | No — bypassed entirely | Yes — fires regardless of the writer |
| Performance cost | Runs in PHP, inside the same request; can be queued (see `# Performance`) | Runs inside the same transaction as the write; adds trigger overhead per row |
| Business-meaning enrichment (e.g. "why was this voided") | Easy — the Service layer has the domain context | Hard — trigger only sees raw column values |
| Risk of silent gaps | High if a developer writes raw SQL and forgets the observer | Low — trigger is table-level and unconditional |

**QAYD's hybrid rule:**

1. **Primary path**: every Eloquent model for an audited table uses the `Auditable` trait (below),
   registered via a model `Observer`, which writes to `audit_logs` with full actor/request context. This
   is the path used for 100% of normal application traffic and is where `reason`, AI metadata, and
   business-level `action` names are attached.
2. **Safety-net path**: a PL/pgSQL trigger (`# Trigger-Based Capture`) is attached to the highest-risk
   tables only — `journal_entries`, `journal_lines`, `accounts`, `invoices`, `bills`,
   `payroll_runs`, `tax_transactions`, `role_permissions` — and writes a row to a **separate**
   `audit_logs_db_trigger_shadow` staging table, not directly into `audit_logs`. A scheduled
   reconciliation job (`php artisan audit:reconcile`) compares the shadow table against `audit_logs` for
   the same window and raises a `system.audit_gap_detected` alert (itself an audited event) if the
   trigger observed a write with no corresponding app-layer audit row. This catches bypass writes without
   double-counting normal traffic, and without forcing the trigger to guess at actor identity.
3. Session variable bridge: for the rare cases where a privileged raw-SQL migration or console command
   must write directly (e.g. a one-time data-fix), the operator is required to run
   `SET LOCAL app.current_user_id = '<id>'; SET LOCAL app.current_reason = '<text>';` before the
   statement inside the same transaction. The trigger reads these via `current_setting(..., true)` and,
   if present, writes a *primary* (non-shadow) `audit_logs` row directly — see the trigger body below.

## Laravel: the Auditable trait and Observer

```php
<?php

namespace App\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->writeAudit('created', null, $model->getAttributes()));

        static::updated(function ($model) {
            $old = $model->getOriginal();
            $new = $model->getChanges();
            // Never audit the case where ONLY updated_at changed.
            if (array_keys($new) === ['updated_at']) {
                return;
            }
            $model->writeAudit('updated', array_intersect_key($old, $new), $new);
        });

        static::deleted(function ($model) {
            // Soft delete: deleted_at transitions from NULL to a timestamp.
            $model->writeAudit('deleted', ['deleted_at' => null], ['deleted_at' => $model->deleted_at]);
        });
    }

    protected function writeAudit(string $verb, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'company_id'     => $this->company_id ?? null,
            'branch_id'      => $this->branch_id ?? null,
            'category'       => 'data_mutation',
            'action'         => $this->auditActionName($verb),
            'entity_type'    => $this->getTable(),
            'entity_id'      => $this->getKey(),
            'actor_user_id'  => Auth::id(),
            'old_values'     => $old,
            'new_values'     => $new,
            'changed_fields' => $new ? array_keys($new) : null,
            'reason'         => request()->input('reason'),
            'request_id'     => request()->attributes->get('request_id'),
            'ip_address'     => Request::ip(),
            'user_agent'     => Request::userAgent(),
        ]);
    }

    protected function auditActionName(string $verb): string
    {
        // e.g. "invoices" + "updated" -> "invoice.updated"; overridable per model for
        // domain-specific names like "journal_entry.posted".
        return \Str::singular($this->getTable()).'.'.$verb;
    }
}
```

Domain services override `auditActionName()`-equivalent behavior by calling a richer
`AuditLog::record()` facade directly for state-transition events that are not plain CRUD, e.g.:

```php
AuditLog::record(
    action: 'journal_entry.posted',
    entity: $journalEntry,
    old: ['status' => 'draft'],
    new: ['status' => 'posted', 'posted_at' => now()],
    reason: null,
);
```

Writes are queued (see `# Performance`) via an `ShouldQueue`-implementing `AuditLogListener` so the
HTTP response is never blocked on the audit insert; the listener still executes inside the same Laravel
DB transaction commit boundary via `afterCommit()` to guarantee the audit row is only queued if the
business transaction actually committed.

# Trigger-Based Capture

The trigger is a generic, reusable function attached to each high-risk table. It compares `OLD` and
`NEW` (or handles `INSERT`/`DELETE`), builds a JSONB diff, and writes to the shadow table unless a
session-local actor has been set, in which case it writes directly to `audit_logs`.

```sql
CREATE OR REPLACE FUNCTION audit_capture_row_change()
RETURNS TRIGGER AS $$
DECLARE
    v_old JSONB;
    v_new JSONB;
    v_changed TEXT[];
    v_actor BIGINT;
    v_reason TEXT;
    v_target TEXT; -- 'primary' or 'shadow'
BEGIN
    IF TG_OP = 'INSERT' THEN
        v_old := NULL;
        v_new := to_jsonb(NEW);
    ELSIF TG_OP = 'UPDATE' THEN
        v_old := to_jsonb(OLD);
        v_new := to_jsonb(NEW);
        SELECT array_agg(key) INTO v_changed
        FROM jsonb_each(v_new) e
        WHERE v_old -> e.key IS DISTINCT FROM e.value;

        -- Skip no-op updates (e.g. only updated_at touched by a trivial re-save).
        IF v_changed IS NULL OR v_changed = ARRAY['updated_at'] THEN
            RETURN NEW;
        END IF;
    ELSIF TG_OP = 'DELETE' THEN
        v_old := to_jsonb(OLD);
        v_new := NULL;
    END IF;

    v_actor  := NULLIF(current_setting('app.current_user_id', true), '')::BIGINT;
    v_reason := NULLIF(current_setting('app.current_reason', true), '');
    v_target := CASE WHEN v_actor IS NOT NULL THEN 'primary' ELSE 'shadow' END;

    IF v_target = 'primary' THEN
        INSERT INTO audit_logs (
            company_id, category, action, entity_type, entity_id,
            actor_user_id, old_values, new_values, changed_fields, reason
        ) VALUES (
            COALESCE((v_new ->> 'company_id')::BIGINT, (v_old ->> 'company_id')::BIGINT),
            'data_mutation',
            TG_TABLE_NAME || '.' || lower(TG_OP),
            TG_TABLE_NAME,
            COALESCE((v_new ->> 'id')::BIGINT, (v_old ->> 'id')::BIGINT),
            v_actor, v_old, v_new, v_changed, v_reason
        );
    ELSE
        INSERT INTO audit_logs_db_trigger_shadow (
            company_id, table_name, operation, row_id, old_values, new_values, changed_fields, captured_at
        ) VALUES (
            COALESCE((v_new ->> 'company_id')::BIGINT, (v_old ->> 'company_id')::BIGINT),
            TG_TABLE_NAME, TG_OP,
            COALESCE((v_new ->> 'id')::BIGINT, (v_old ->> 'id')::BIGINT),
            v_old, v_new, v_changed, now()
        );
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

CREATE TABLE audit_logs_db_trigger_shadow (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id   BIGINT NULL,
    table_name   TEXT NOT NULL,
    operation    TEXT NOT NULL,
    row_id       BIGINT NULL,
    old_values   JSONB NULL,
    new_values   JSONB NULL,
    changed_fields TEXT[] NULL,
    captured_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    reconciled   BOOLEAN NOT NULL DEFAULT false
);

CREATE TRIGGER trg_audit_journal_entries
AFTER INSERT OR UPDATE OR DELETE ON journal_entries
FOR EACH ROW EXECUTE FUNCTION audit_capture_row_change();

CREATE TRIGGER trg_audit_invoices
AFTER INSERT OR UPDATE OR DELETE ON invoices
FOR EACH ROW EXECUTE FUNCTION audit_capture_row_change();

-- Repeat for: journal_lines, accounts, bills, payroll_runs, tax_transactions, role_permissions.
```

The reconciliation job runs hourly, matches `audit_logs_db_trigger_shadow.captured_at` against
`audit_logs` rows for the same `(table_name, row_id, operation)` within a 5-minute window, marks matches
`reconciled = true`, and escalates unmatched shadow rows older than 15 minutes as
`system.audit_gap_detected` — itself written to `audit_logs` with `category = 'system'`.

# Immutability & Tamper-Evidence

`audit_logs` is **append-only**. This is enforced at three layers:

## 1. Database privilege revocation

```sql
REVOKE UPDATE, DELETE ON audit_logs FROM app_user;
REVOKE UPDATE, DELETE ON audit_logs FROM PUBLIC;
GRANT INSERT, SELECT ON audit_logs TO app_user;

-- A separate, rarely-used role exists ONLY for the retention job (# Partitioning & Retention),
-- which drops whole partitions rather than deleting rows, so DELETE is never granted to any
-- application-facing role.
```

Additionally, a `BEFORE UPDATE OR DELETE` trigger raises an exception even if a superuser-owned
connection somehow attempts it, so a compromised/misconfigured credential cannot silently rewrite
history:

```sql
CREATE OR REPLACE FUNCTION audit_logs_block_mutation()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'audit_logs is append-only: % is not permitted', TG_OP;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_audit_logs_immutable
BEFORE UPDATE OR DELETE ON audit_logs
FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();
```

## 2. Hash chaining (tamper-evidence)

Each row's `hash` is `SHA256(canonical_json(row) || prev_hash)`, computed by a `BEFORE INSERT` trigger,
chained **per company** so chains stay independently verifiable and partition pruning still works:

```sql
CREATE OR REPLACE FUNCTION audit_logs_compute_hash()
RETURNS TRIGGER AS $$
DECLARE
    v_prev CHAR(64);
    v_payload TEXT;
BEGIN
    SELECT hash INTO v_prev
    FROM audit_logs
    WHERE company_id = NEW.company_id
    ORDER BY created_at DESC, id DESC
    LIMIT 1;

    NEW.prev_hash := v_prev; -- NULL for the first row of a company's chain (genesis)

    v_payload := concat_ws('|',
        NEW.company_id, NEW.action, NEW.entity_type, NEW.entity_id,
        NEW.actor_user_id::TEXT, NEW.old_values::TEXT, NEW.new_values::TEXT,
        NEW.created_at::TEXT, COALESCE(NEW.prev_hash, 'GENESIS')
    );

    NEW.hash := encode(sha256(v_payload::bytea), 'hex');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_audit_logs_hash
BEFORE INSERT ON audit_logs
FOR EACH ROW EXECUTE FUNCTION audit_logs_compute_hash();
```

A nightly `audit:verify-chain` job recomputes the chain per company and flags any row whose stored
`hash` does not match its recomputed value — proof that a row was altered outside the application (e.g.
directly in storage, or via a restored-and-edited backup), since altering row N without also rewriting
every subsequent `prev_hash`/`hash` in that company's chain breaks verification deterministically.

## 3. Export-time notarization (optional, high-assurance tier)

For companies on the Enterprise/regulated tier, the daily closing hash (the `hash` of the last row of
the day per company) is additionally written to an external, independently-controlled log (e.g. a
separate object in Cloudflare R2 with object-lock/WORM retention, or a third-party timestamping
service). This defends against the scenario where an attacker has full database credentials AND the
ability to replace the whole table — the external anchor is outside that blast radius.

# Partitioning & Retention Of Audit Data

`audit_logs` is range-partitioned by `created_at`, monthly, created ahead of time by a scheduled job:

```sql
CREATE TABLE audit_logs_y2026m07 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');

CREATE TABLE audit_logs_y2026m08 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
```

A `pg_cron` job (`audit_partition_maintenance`) runs monthly and creates the next 3 months of
partitions in advance, so writes never block waiting on DDL. Each new partition inherits the parent's
indexes automatically via `CREATE TABLE ... PARTITION OF`.

**Retention policy** (configurable per company via `companies.audit_retention_years`, default per
Kuwait/GCC financial record-keeping norms of a minimum multi-year retention window):

- **Hot tier** (0–13 months): full partitions on primary NVMe-backed storage, all indexes present,
  serves live "who changed this" lookups.
- **Warm tier** (13 months–legal minimum, e.g. 5–7 years depending on jurisdiction and entity type):
  partitions are `ALTER TABLE ... SET (autovacuum_vacuum_scale_factor = ...)`-tuned, non-critical GIN
  indexes dropped to save space, and the partition is compressed and moved to a cheaper tablespace, or
  exported to Parquet in Cloudflare R2 with a Postgres foreign table (via `parquet_fdw` or a periodic
  export + drop) if the warm tier is fully externalized.
- **Beyond legal minimum**: partitions are exported as a signed, hash-chain-verified archive to R2 and
  the live partition is dropped:

```sql
-- Never DELETE FROM audit_logs. Retire whole partitions instead — this is O(1) and never
-- triggers per-row trigger overhead or WAL bloat that a bulk DELETE would.
ALTER TABLE audit_logs DETACH PARTITION audit_logs_y2019m01;
-- Export audit_logs_y2019m01 to R2 (pg_dump / COPY TO Parquet) before:
DROP TABLE audit_logs_y2019m01;
```

- Partitions are **never dropped** without: (1) confirming the export's hash chain matches the live
  chain at detach time, (2) confirming no company in that partition has an open tax audit or legal hold
  flag (`companies.legal_hold = true` suspends retention expiry entirely for that company, checked
  per-partition-per-company since partitions are time-based but holds are company-based — a hold
  therefore blocks the whole partition from being dropped, not just that company's rows within it, which
  is why high-hold-rate tenants are additionally migrated into per-company overflow partitions on
  request).

# Querying & Reconstructing History

## Who changed this invoice, and to what

```sql
SELECT created_at, actor_user_id, action, old_values, new_values, reason
FROM audit_logs
WHERE entity_type = 'invoices' AND entity_id = 48213
ORDER BY created_at ASC;
```

## Full point-in-time reconstruction of a row

Replaying diffs in order reconstructs the row's state at any timestamp `T`:

```sql
WITH ordered AS (
    SELECT new_values, old_values, created_at,
           ROW_NUMBER() OVER (ORDER BY created_at ASC) AS seq
    FROM audit_logs
    WHERE entity_type = 'invoices' AND entity_id = 48213 AND created_at <= :as_of
)
SELECT jsonb_object_agg(key, value) AS reconstructed_state
FROM ordered, jsonb_each(new_values)  -- application layer folds these left-to-right in seq order
GROUP BY seq;
-- In practice this fold is done in the Laravel service layer (AuditLog::reconstruct($entity, $asOf))
-- rather than pure SQL, since JSONB left-fold merge across rows is clearer imperatively.
```

```php
public function reconstruct(string $entityType, int $entityId, Carbon $asOf): array
{
    $state = [];
    AuditLog::where('entity_type', $entityType)
        ->where('entity_id', $entityId)
        ->where('created_at', '<=', $asOf)
        ->orderBy('created_at')
        ->each(function (AuditLog $log) use (&$state) {
            $state = array_merge($state, $log->new_values ?? []);
        });
    return $state;
}
```

## Everyone who touched permissions in the last 30 days

```sql
SELECT al.created_at, u.name, al.action, al.new_values, al.reason
FROM audit_logs al
JOIN users u ON u.id = al.actor_user_id
WHERE al.company_id = :company_id
  AND al.category = 'permission'
  AND al.created_at >= now() - interval '30 days'
ORDER BY al.created_at DESC;
```

## AI-originated changes pending or approved this week

```sql
SELECT entity_type, entity_id, actor_user_id, new_values->>'ai_confidence' AS confidence, created_at
FROM audit_logs
WHERE company_id = :company_id
  AND (new_values->>'ai_assisted')::boolean IS TRUE
  AND created_at >= date_trunc('week', now());
```

# Performance

Audit writes must never become the bottleneck of the primary financial write path.

- **Async by default**: the Eloquent `Auditable` trait dispatches an `AuditLogListener` job onto a
  dedicated `audit` Redis queue rather than writing synchronously inside the request. The job's
  `afterCommit()` binding guarantees it only fires after the enclosing DB transaction has committed, so
  a rolled-back business transaction never produces an orphan audit row.
- **Outbox pattern for cross-cutting consistency**: for actions where the audit row must be
  guaranteed-eventually-written even if Redis is briefly unavailable (posting a journal entry, voiding
  an invoice, permission changes), the write goes through an `audit_outbox` table in the SAME
  transaction as the business mutation (a plain INSERT, cheap, same ACID boundary), and a lightweight
  poller (`audit:drain-outbox`, running every 2 seconds) moves rows from `audit_outbox` into
  `audit_logs` and the Redis-based async path is used only for lower-stakes categories (e.g. routine
  `updated` events on non-financial master data).

```sql
CREATE TABLE audit_outbox (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    payload     JSONB NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    drained_at  TIMESTAMPTZ NULL
);
CREATE INDEX idx_audit_outbox_undrained ON audit_outbox (created_at) WHERE drained_at IS NULL;
```

- **Trigger overhead containment**: the PL/pgSQL trigger in `# Trigger-Based Capture` is attached only
  to the ~7 highest-risk tables, not universally, because `to_jsonb(NEW)`/`to_jsonb(OLD)` plus a JSONB
  diff computed per row adds measurable overhead on high-throughput tables (`stock_movements` can see
  thousands of rows/minute during a stock count) — those rely solely on the app-layer path.
- **Bulk operations write one audit row per logical batch, not per row**: a `stock_count` finalization
  that adjusts 5,000 `inventory_items` rows writes ONE `audit_logs` row with
  `action = 'stock_count.finalized'` and `new_values` containing a reference to the `stock_counts.id`
  (whose own child rows in `stock_count_lines` carry the per-item detail) plus an aggregate summary —
  not 5,000 audit rows. The per-item detail is queryable via the business table itself; `audit_logs`
  captures the authorizing event.
- **Index discipline**: the GIN index on `new_values` is deliberately narrow (`jsonb_path_ops`, which
  supports `@>` containment but not arbitrary key extraction) to keep it small; ad-hoc unstructured
  JSONB search is expected to be rare (used by Auditor-role investigations, not routine app queries) and
  is allowed to be somewhat slower in exchange for a materially smaller index footprint on a table that
  is, by nature, the largest table in the database.
- **Partition pruning**: every query in `# Querying & Reconstructing History` and every dashboard widget
  MUST include a `created_at` bound (even a generous one, e.g. "last 2 years") so the planner prunes
  partitions; an unbounded `entity_id` lookup across all history is the one query pattern allowed to
  scan multiple partitions, and is bounded instead by the `idx_audit_logs_entity` index.
- **No foreign key from business tables to `audit_logs`**: nothing ever joins FROM a hot business table
  INTO `audit_logs` in the write path; `audit_logs` is always the dependent, never a dependency, so its
  size and partition churn cannot slow down invoice/journal writes.

# Privacy

`audit_logs` inevitably contains sensitive data inside `old_values`/`new_values` — salaries, bank
account numbers, national IDs on customer/vendor records, tax numbers. QAYD masks at write time, not
read time, so sensitive values are never persisted in plaintext even inside the audit trail.

## Field-level masking registry

```php
// config/audit-masking.php
return [
    'payroll_items'   => ['gross_salary', 'net_salary', 'bank_iban'],
    'payslips'        => ['gross_salary', 'net_salary', 'deductions'],
    'employees'       => ['national_id', 'bank_account_number', 'date_of_birth'],
    'customers'       => ['tax_registration_number'],
    'vendor_bank_accounts' => ['account_number', 'iban'],
    'bank_accounts'   => ['account_number', 'iban'],
];
```

```php
protected function maskSensitiveFields(string $table, array $values): array
{
    $sensitive = config("audit-masking.$table", []);
    foreach ($sensitive as $field) {
        if (array_key_exists($field, $values) && $values[$field] !== null) {
            $values[$field] = 'MASKED:' . substr(hash('sha256', (string) $values[$field]), 0, 12);
        }
    }
    return $values;
}
```

The masked hash is stable per underlying value (same input -> same masked token), which preserves the
ability to detect "did this IBAN change" (`old` token != `new` token) without ever exposing the actual
IBAN in the audit trail. The real value is only ever visible through the business table itself under
its own field-level access control, never reconstructable from `audit_logs`.

## Right-to-erasure interaction

Financial rows are never hard-deleted (see DESIGN_CONTEXT §2), which usually means audit history and
erasure never conflict. In the rare, legally-compelled erasure case (e.g. a departed employee's personal
data under a data-protection request that does not conflict with statutory financial retention), the
business row's PII columns are nulled/masked in place, a fresh audit row records
`action = 'employee.pii_erased'` with `reason` citing the legal basis, and historical `audit_logs` rows
referencing that entity are **left untouched** — the hash chain guarantees they cannot be retroactively
edited anyway — but any PII inside their `old_values`/`new_values` that was NOT already masked at
capture time (a bug, in QAYD's design) triggers a `system.audit_masking_gap` finding for the security
team, since the masking registry is meant to make this scenario structurally impossible rather than
something remediated after the fact.

## Access control on audit_logs itself

Reading `audit_logs` requires `audit.read` (broad) or narrower `audit.read.own_company`; the Auditor and
External Auditor roles get `audit.read` company-wide but never get write access to any table, including
`audit_logs`. Row Level Security enforces company isolation identically to every other tenant table:

```sql
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;

CREATE POLICY audit_logs_company_isolation ON audit_logs
    USING (company_id = current_setting('app.current_company_id', true)::BIGINT
           OR current_setting('app.is_platform_admin', true)::BOOLEAN IS TRUE);
```

# Compliance

Audit logging in QAYD is designed to satisfy the evidentiary expectations of a statutory financial
audit (external auditor sign-off) and, secondarily, GCC/Kuwait tax-authority audit requests:

- **Completeness**: every posted journal entry has a matching `journal_entry.posted` audit row whose
  `new_values` includes the full balanced line set reference; an external auditor can be handed a
  read-only `audit.read` credential and independently verify no posted entry lacks a trail.
- **Non-repudiation**: `actor_user_id` + `ip_address` + `request_id` + hash chaining together mean a
  specific human (or named AI agent + approving human) can always be attributed to a change, and that
  attribution cannot be silently altered after the fact.
- **Segregation of duties evidence**: because permission changes are audited with the same rigor as
  financial changes, an auditor can verify that the person who approved a payment was not the same
  person who created it, by cross-referencing `audit_logs.actor_user_id` across the approval chain
  (`payments.created_by` vs `payments.approved_by`, both independently audited).
- **Retention alignment**: the partition retention policy in `# Partitioning & Retention Of Audit Data`
  defaults to a multi-year warm tier specifically so it meets-or-exceeds common statutory bookkeeping
  retention windows (commercial and tax law in Kuwait and neighboring GCC states generally requires
  multi-year retention of accounting records; `companies.audit_retention_years` lets a company configure
  a longer window where local rules or group policy require it, but never a shorter one than the
  platform-enforced floor).
- **Tamper evidence as audit deliverable**: the `audit:verify-chain` output (per-company hash chain
  verification report) is itself an exportable artifact an external auditor can request and independently
  recompute against a raw export, without trusting QAYD's application layer at all — the guarantee lives
  in the cryptographic chain, not in "the app says so."

# Edge Cases

- **Bulk import/migration writes**: a one-time company onboarding import of 50,000 historical invoices
  does not write 50,000 individual `audit_logs` rows with a live human actor; it writes ONE
  `data_import.completed` row referencing an `import_batches` table that itself lists the row counts and
  a checksum of the imported file, with `actor_service = 'import:onboarding'`. Retroactively-dated
  business rows are marked `imported_at` distinct from `created_at` so reports do not misrepresent import
  bursts as same-day activity.
- **Reversing entries**: reversing a posted journal entry never edits or deletes the original
  `journal_entries`/`journal_lines` rows (immutable once posted). It creates a new reversing entry and
  both the original's status change (`posted -> reversed`) and the new entry's creation are separately
  audited, cross-referenced via `new_values.reverses_journal_entry_id` / `reversed_by_journal_entry_id`.
- **Clock skew / out-of-order writes across app servers**: `created_at` is set by `now()` inside the
  same transaction as the INSERT, not by the application server's clock, so multi-node clock drift
  cannot reorder the audit trail relative to the database's own commit order; ties are broken by `id`.
- **Failed/rolled-back transactions**: because audit writes for the outbox path are inside the same
  transaction as the business mutation, a rollback removes the audit row too — there is no
  "audited-but-didn't-happen" row. The Redis-queued async path is safe for the same reason via
  `afterCommit()`; if the job never fires because the process crashed after commit but before dispatch,
  the reconciliation job (`# Trigger-Based Capture`) surfaces the resulting gap since the DB trigger
  shadow row exists on the high-risk tables regardless of app-layer success.
- **Impersonation**: support staff assuming a customer user's session for troubleshooting always audits
  both `actor_user_id` (the real support staff member) and `acting_as_user_id` (the customer user), and
  every subsequent action performed during that session ALSO carries both fields — the impersonated
  identity never fully "becomes" the actor of record.
- **Very large single-row diffs**: a `new_values` payload approaching Postgres's practical JSONB
  comfort zone (multi-MB, e.g. a bulk price-list re-import staged as a single logical update) is instead
  stored as a reference: `new_values = {"ref": "attachments/...", "summary": {...}}` with the full diff
  written to Cloudflare R2 via the polymorphic `attachments` table, keeping the hot `audit_logs` row
  small while remaining fully reconstructable.
- **Concurrent updates to the same row**: two users updating the same `invoice` in overlapping
  transactions produce two ordered audit rows reflecting Postgres's actual serialization order (whichever
  transaction's `UPDATE` committed second sees the first's row in its `OLD`), which is exactly the
  correct history — the audit trail does not need to "resolve" the conflict, only faithfully record what
  the database actually did, including the fact that the second writer's `old_values` already reflects
  the first writer's change (a legitimate lost-update warning signal an auditor can spot from the trail
  itself: `old_values` differing from what the second actor's UI likely displayed when they submitted).
- **Schema evolution**: adding a new column to an audited table does not require an `audit_logs` schema
  change (JSONB payload absorbs it automatically), but does require adding the column to the relevant
  entry in `config/audit-masking.php` if it is sensitive, and this is enforced by a CI check that diffs
  each audited migration's new columns against the masking registry and fails the build if a
  known-sensitive-looking column name (matched against a pattern list: `salary`, `iban`, `account_number`,
  `national_id`, `ssn`, `password`, `token`, `secret`) is added without a corresponding masking entry.

# End of Document
