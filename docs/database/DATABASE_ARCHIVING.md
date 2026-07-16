# Data Archiving & Lifecycle — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: Archiving & Lifecycle
---

# Purpose

QAYD is a multi-tenant AI Financial Operating System. Every company's `journal_entries`,
`journal_lines`, `ledger_entries`, `stock_movements`, `audit_logs`, and `notifications` grow without
bound as long as the company is active: a mid-size tenant posts tens of thousands of journal lines per
fiscal year, generates an audit-log row on every mutation (per the platform-wide audit rule), and can
accumulate millions of `stock_movements` rows across warehouses over several years. None of this data
may ever be hard-deleted while it is financially or legally relevant — posted accounting rows are
immutable and Gulf/Kuwait commercial law requires books to be retrievable for a decade — but leaving all
of it permanently in the hot, heavily-indexed operational tables degrades `INSERT` throughput, bloats
indexes, slows `VACUUM`, and inflates storage cost across thousands of tenants sharing the same Postgres
fleet.

This document defines QAYD's data lifecycle: how records move from hot operational storage to warm
low-cost SQL storage to cold object storage as they age past their operational usefulness but before
their legal retention window expires; how that movement is automated, verified, and made reversible;
how legal holds and per-company retention overrides are enforced; and how reporting, consolidation, and
the AI layer behave when a query needs to reach into archived data. It is scoped strictly to lifecycle
and archival concerns. Soft-delete semantics on individual rows, physical backup/DR, and query-level
performance tuning are cross-referenced but owned by `DATABASE_SOFT_DELETES.md`,
`DATABASE_BACKUP_RECOVERY.md`, and `DATABASE_PERFORMANCE.md` respectively — this document does not
duplicate their content, only the parts of it that archiving depends on.

Archiving in QAYD is never destructive in a way that cannot be reconstructed: every archived byte is
represented by a manifest (row counts, checksums, storage location) that lets a restore operation
reproduce it exactly. This document specifies that guarantee end-to-end: the SQL that moves data, the
storage tiers it moves through, the jobs that automate the movement, the checks that prove nothing was
lost, and the workflow that brings data back when a query, audit, or legal request needs it.

# Archiving vs Soft Delete vs Backup

These three mechanisms are frequently confused because they all "keep data around after it stops being
actively used." They solve different problems, operate at different granularities, and are triggered by
different events. QAYD engineers must not substitute one for another.

| Dimension | Soft Delete | Archiving | Backup |
|---|---|---|---|
| Trigger | A user/AI action removes or voids a single record | A scheduled job moves records that crossed an age/status threshold | A scheduled snapshot of the entire database, independent of record content |
| Granularity | Single row | A batch of rows sharing a partition key (date range, fiscal year, company) | Whole database / whole cluster |
| Mechanism | `UPDATE ... SET deleted_at = now()` | `DETACH PARTITION`, bulk `INSERT ... SELECT` + `DELETE`, or export-and-drop | `pg_basebackup`, WAL archiving, or managed snapshot (RDS/Aurora-style) |
| Reversibility | Instant — clear `deleted_at`, still in the same table, same indexes | Requires an explicit Restore-From-Archive operation (see below); not instant | Full point-in-time restore of an entire cluster/database, not a single row |
| Where the data lives after | Same table, same tablespace, excluded by default scopes | A different table/schema (warm) or object storage (cold); no longer in the primary operational table | A separate storage system entirely (S3/R2 snapshot store); not attached to any live database |
| Query visibility | Excluded from default Eloquent scopes; visible via `withTrashed()` to authorized users | Not visible to normal application queries at all; visible only via the Archive API / restore workflow | Not queryable — a backup is only restorable, never queried in place |
| Who/what triggers it | End user action, AI-proposed deletion (with approval), reversing entries | The Archiving Scheduler (cron/queue), never a manual per-row action | Infrastructure automation (WAL shipping, nightly snapshot job) |
| Owned by | `DATABASE_SOFT_DELETES.md` | This document | `DATABASE_BACKUP_RECOVERY.md` |
| Primary purpose | Undo / audit trail for a single business action | Keep hot tables small and fast while data ages toward its retention limit | Disaster recovery from total data loss, ransomware, or catastrophic corruption |

A row's lifecycle in QAYD is therefore: **live** → (optionally) **soft-deleted** (still hot) → **hot**
(current + prior fiscal year, fully indexed) → **warm** (closed fiscal years / aged operational data,
still SQL-queryable but on cheaper storage, fewer indexes) → **cold** (Parquet in Cloudflare R2, not
directly queryable, restore-on-demand) → **eligible for legal purge** once the retention period and any
legal hold both clear. Backups run in parallel across every stage and are not part of this chain.

# What Gets Archived

Four record families are archived on a recurring schedule. Eligibility is always evaluated per
`company_id` — a company on a longer configured retention (see `# Retention Policy`) is skipped even if
its peers are archived on schedule.

## 1. Closed Fiscal Years (Accounting)

`journal_entries`, `journal_lines`, and the `ledger_entries` projection for a `fiscal_year` become
archival candidates only after ALL of the following hold:

```sql
SELECT fy.id, fy.company_id, fy.code, fy.status, fy.closed_at
FROM fiscal_years fy
WHERE fy.status = 'closed'
  AND fy.closed_at < now() - interval '90 days'          -- closed-year cooling-off period
  AND NOT EXISTS (
      SELECT 1 FROM legal_holds lh
      WHERE lh.company_id = fy.company_id
        AND lh.record_type = 'fiscal_year'
        AND lh.released_at IS NULL
        AND (lh.scope->>'fiscal_year_id')::bigint = fy.id
  );
```

The 90-day cooling-off window exists because a closed year can still be reopened for an audit adjustment
within that window (see `# Edge Cases`). Fiscal periods within the year, and every `journal_line`,
`ledger_entries` row, and dimension reference (`cost_center_id`, `project_id`) dated inside that fiscal
year, move together as one atomic unit — accounting data is never archived at a finer grain than a full
fiscal year, because trial balances and financial statements must reconstruct the whole year from a
single tier.

## 2. Audit Logs

`audit_logs` rows are the highest-volume table in the platform (one row per mutation, platform-wide).
They are archived by wall-clock age, independent of the entity they describe:

```sql
SELECT * FROM audit_logs
WHERE created_at < now() - interval '13 months'   -- hot window: rolling 13 months
ORDER BY created_at;
```

Audit logs are archived monthly (aligned to the monthly partitions described in `# Archiving
Strategies`), never per-row, and never before their legal minimum (see `# Retention Policy`) is
guaranteed to still be reachable in warm/cold tiers — audit logs are archived, never deleted, until the
retention clock for the underlying record type also expires.

## 3. Notifications

`notifications` are operational, not financial. They carry no multi-year legal retention requirement:

```sql
SELECT * FROM notifications
WHERE created_at < now() - interval '90 days'
  AND read_at IS NOT NULL;                 -- unread notifications are never auto-archived
```

Because notifications have no legal hold surface and a short useful life, their "archive" tier is
compressed cold storage only (skip warm) — see `# Hot/Warm/Cold Tiering`.

## 4. Historical Stock Movements

`stock_movements` are archived once (a) they are older than the configured window and (b) the inventory
valuation for the period they belong to has been finalized (an open `inventory_valuations` run for that
period blocks archiving, since valuation recomputation needs the raw movement rows):

```sql
SELECT sm.* FROM stock_movements sm
WHERE sm.created_at < now() - interval '24 months'
  AND NOT EXISTS (
      SELECT 1 FROM inventory_valuations iv
      WHERE iv.company_id = sm.company_id
        AND iv.warehouse_id = sm.warehouse_id
        AND iv.status = 'in_progress'
        AND sm.created_at BETWEEN iv.period_start AND iv.period_end
  );
```

`stock_reservations` and `stock_counts` in progress are never archived; only settled `stock_movements`
ledger rows are.

# Retention Policy

Gulf commercial and tax law sets the legal FLOOR for how long financial records must remain
retrievable. QAYD ships conservative platform defaults but lets each company raise (never lower) its own
retention, since a company may operate across multiple GCC jurisdictions simultaneously or be subject to
a stricter internal audit policy.

**Statutory floors referenced by the default configuration** (informational — Compliance owns the
authoritative source; this table is the engineering default, not legal advice):

| Jurisdiction | Basis | Minimum retention |
|---|---|---|
| Kuwait | Commercial Companies Law No. 1/2016 and Ministry of Commerce & Industry bookkeeping rules | 10 years from fiscal year-end |
| Kuwait tax (KPO government-contract retention, zakat/tax filings) | Kuwait Tax Authority practice | 10 years, aligned to commercial law |
| Saudi Arabia | ZATCA e-invoicing & bookkeeping regulations | 6 years from tax period end |
| UAE | Federal Tax Authority record-keeping requirement | 5 years from the end of the relevant tax period |
| Qatar / Bahrain / Oman | General commercial code bookkeeping rules | 5 years (platform default; verify per engagement) |

QAYD's **platform default retention is 10 years** for all financial record types (fiscal years, journal
data, invoices, bills, tax transactions) — the Kuwait floor, since Kuwait is the primary market — and
this is overridable per company and per record type:

```sql
CREATE TABLE company_retention_policies (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id       BIGINT NOT NULL REFERENCES companies(id),
    record_type      VARCHAR(64) NOT NULL,       -- 'fiscal_year' | 'audit_log' | 'notification' | 'stock_movement' | 'tax_transaction'
    retention_years  SMALLINT NOT NULL DEFAULT 10,
    legal_basis      VARCHAR(255) NULL,          -- free text citation, e.g. 'Kuwait CCL 1/2016 Art. 168'
    is_indefinite    BOOLEAN NOT NULL DEFAULT false,  -- true overrides retention_years (never purge)
    created_by       BIGINT NULL REFERENCES users(id),
    updated_by       BIGINT NULL REFERENCES users(id),
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at       TIMESTAMPTZ NULL,
    CONSTRAINT uq_company_record_type UNIQUE (company_id, record_type),
    CONSTRAINT chk_retention_years CHECK (retention_years BETWEEN 1 AND 100)
);
CREATE INDEX idx_company_retention_policies_company ON company_retention_policies(company_id);
```

Resolution order when the archiving job needs a retention value for `(company_id, record_type)`:

1. Row in `company_retention_policies` for that exact `(company_id, record_type)`.
2. Platform default for that `record_type` (hardcoded in `config/archiving.php`).
3. Global fallback: 10 years.

`notifications` are the one record type explicitly exempted from the legal-retention floor — they carry
`retention_years = 0` at the platform default level and are eligible for hard deletion after their cold
copy ages out (see `# Edge Cases` for the interaction with any company that overrides this).

Retention changes are themselves audited (`audit_logs`, `action = 'retention_policy.updated'`) and take
effect only for future archiving runs — a retention *increase* immediately protects any data still in
hot/warm storage and blocks any pending cold-export/purge for that company; a retention *decrease* never
retroactively shortens the life of data already archived under the longer policy (see `# Legal Hold` for
the analogous non-retroactivity rule).

# Archiving Strategies

QAYD uses three complementary strategies, chosen per table by access pattern and volume. All three are
implemented as idempotent, resumable batch operations — never a single unbounded transaction.

## Strategy A — Partition Detach-and-Archive (high-volume, date-ordered tables)

`audit_logs`, `stock_movements`, and `journal_lines`/`ledger_entries` are declared as **range-partitioned
by month** (audit_logs, stock_movements, notifications) or **by fiscal year** (journal_lines,
ledger_entries) from creation. Archiving a range becomes a metadata-only operation instead of a row-by-row
`DELETE`:

```sql
-- Declarative partitioning at table-creation time (audit_logs shown; same pattern for stock_movements)
CREATE TABLE audit_logs (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    branch_id     BIGINT NULL REFERENCES branches(id),
    user_id       BIGINT NULL REFERENCES users(id),
    action        VARCHAR(128) NOT NULL,
    entity_type   VARCHAR(128) NOT NULL,
    entity_id     BIGINT NOT NULL,
    old_values    JSONB NULL,
    new_values    JSONB NULL,
    reason        TEXT NULL,
    ip_address    INET NULL,
    device_info   JSONB NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);

CREATE INDEX idx_audit_logs_company_created ON audit_logs (company_id, created_at);
CREATE INDEX idx_audit_logs_entity ON audit_logs (entity_type, entity_id);

-- Monthly partitions, created ahead of time by pg_partman (see # Automation)
CREATE TABLE audit_logs_2026_06 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-06-01') TO ('2026-07-01');
CREATE TABLE audit_logs_2026_07 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-07-01') TO ('2026-08-01');
```

Detaching a month that has aged past the hot window is a near-instant metadata change (PostgreSQL 14+
supports `CONCURRENTLY` so it does not block concurrent reads/writes on sibling partitions):

```sql
ALTER TABLE audit_logs DETACH PARTITION audit_logs_2026_06 CONCURRENTLY;

-- The detached partition is now a standalone table. Move it into the archive schema
-- (this becomes the WARM tier — still full SQL, just outside the hot parent table):
ALTER TABLE audit_logs_2026_06 SET SCHEMA archive;
ALTER TABLE archive.audit_logs_2026_06 RENAME TO audit_logs_2026_06;
COMMENT ON TABLE archive.audit_logs_2026_06 IS 'Archived 2026-08-01 by archive:run; source partition audit_logs_2026_06';
```

For `journal_lines`/`ledger_entries`, the partition key is the fiscal year, and a full year is detached
in one statement only once every fiscal-year-closure precondition in `# What Gets Archived` is met:

```sql
ALTER TABLE journal_lines PARTITION BY RANGE (fiscal_year_id);  -- established at table creation, not retrofitted
ALTER TABLE journal_lines DETACH PARTITION journal_lines_fy_2023 CONCURRENTLY;
ALTER TABLE ledger_entries DETACH PARTITION ledger_entries_fy_2023 CONCURRENTLY;
ALTER TABLE journal_lines_fy_2023 SET SCHEMA archive;
ALTER TABLE ledger_entries_fy_2023 SET SCHEMA archive;
```

## Strategy B — Move to Cold/Archive Tables (low-volume or non-partitioned tables)

Tables too small to justify native partitioning (e.g. `tax_returns`, `payroll_runs`) are archived with
batched `INSERT ... SELECT` + `DELETE`, chunked by primary key range to bound lock time and avoid long
transactions:

```sql
DO $$
DECLARE
    v_batch_size INT := 5000;
    v_moved INT;
BEGIN
    LOOP
        WITH moved AS (
            DELETE FROM payroll_runs
            WHERE id IN (
                SELECT id FROM payroll_runs
                WHERE company_id = $1
                  AND status = 'closed'
                  AND period_end < now() - interval '10 years'
                ORDER BY id
                LIMIT v_batch_size
            )
            RETURNING *
        )
        INSERT INTO archive.payroll_runs SELECT * FROM moved;

        GET DIAGNOSTICS v_moved = ROW_COUNT;
        EXIT WHEN v_moved = 0;
        COMMIT;  -- release locks between batches
    END LOOP;
END $$;
```

`archive.*` tables share the exact column definitions of their source (created via
`CREATE TABLE archive.foo (LIKE public.foo INCLUDING ALL)`), so no application code change is needed to
query them through the read-only Archive API.

## Strategy C — Export to R2/S3 as Parquet (cold tier)

Once a warm-tier table (a detached partition or an `archive.*` table) ages past the **warm window**
(default 2 years past archiving — configurable per `company_retention_policies`), it is converted to
columnar Parquet and pushed to Cloudflare R2, then dropped from Postgres entirely. This is executed by a
queue job (`ExportPartitionToColdStorageJob`) that shells out to a Python/DuckDB or `pg_parquet`-based
exporter (the same AI-layer Python runtime used elsewhere in QAYD, invoked here purely as an ETL utility
— it does not touch the database directly, per the platform's AI-layer rule; it reads via a read-replica
connection string handed to it by Laravel):

```bash
# Executed by the queue worker, not by a human
python3 export_to_parquet.py \
  --dsn "$ARCHIVE_READ_REPLICA_DSN" \
  --table "archive.audit_logs_2024_03" \
  --company-scope-column company_id \
  --output "s3://qayd-archive/audit_logs/company_id={company_id}/2024/03/part-000.parquet" \
  --endpoint-url "$R2_ENDPOINT" \
  --checksum-manifest
```

```php
// app/Jobs/ExportPartitionToColdStorageJob.php
class ExportPartitionToColdStorageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $schemaQualifiedTable,
        public int $companyId,
        public string $recordType,
    ) {}

    public function handle(ArchiveManifestService $manifests): void
    {
        $rowCountBefore = DB::table($this->schemaQualifiedTable)->count();

        $result = Process::run([
            'python3', base_path('scripts/export_to_parquet.py'),
            '--dsn', config('archiving.read_replica_dsn'),
            '--table', $this->schemaQualifiedTable,
            '--output', $this->r2Path(),
        ]);

        abort_if(! $result->successful(), 500, 'Parquet export failed: '.$result->errorOutput());

        $manifest = $manifests->recordExport(
            table: $this->schemaQualifiedTable,
            companyId: $this->companyId,
            recordType: $this->recordType,
            r2Path: $this->r2Path(),
            rowCount: $rowCountBefore,
            checksumSha256: $result->json('checksum_sha256'),
        );

        VerifyArchiveIntegrityJob::dispatch($manifest->id)->onQueue('archiving-verify');
    }
}
```

Only after `VerifyArchiveIntegrityJob` confirms the Parquet file's row count and checksum match the
manifest (see `# Integrity & Verification Of Archives`) does a follow-up job drop the now-redundant
Postgres table (`DROP TABLE archive.audit_logs_2024_03;`). Nothing is ever dropped from Postgres before
its cold copy is verified.

# Hot/Warm/Cold Tiering

| Tier | Contents | Storage | Indexing | Query path | Typical age | Cost profile |
|---|---|---|---|---|---|---|
| **Hot** | Current + prior fiscal year; last 13 months of `audit_logs`; last 90 days of `notifications`; last 24 months of `stock_movements` | Primary Postgres cluster (attached partitions of the live tables) | Full index set, kept in shared_buffers/OS cache | Direct app queries via normal Eloquent models, sub-100ms p95 | 0–2 years | Highest — SSD-backed primary storage, replicated |
| **Warm** | Detached partitions / `archive.*` tables — closed fiscal years and aged operational data within 2 years of aging out of hot, but not yet cold-exported | Same Postgres cluster (or a smaller archive-tier instance), `archive` schema | Primary key + `company_id` index only; secondary indexes dropped to save space | Archive API only (read-only, restore-on-demand), typically 200ms–2s | 2–4 years | Medium — same compute tier, less storage per row (fewer indexes), often on a cheaper storage class (e.g. gp3 vs io2) |
| **Cold** | Parquet objects in Cloudflare R2, one file per partition/company/period | Cloudflare R2 (S3-compatible object storage) | None (columnar file, queried by DuckDB/Athena-style engine only during restore or ad-hoc analytics) | Not directly queryable by the app; requires Restore-From-Archive (minutes) or an offline analytics job | 4 years to end of legal retention | Lowest — object storage pricing, no compute attached at rest |

Movement between tiers is always one direction forward (hot → warm → cold) under normal operation;
movement backward only happens through the explicit Restore workflow (`# Restore From Archive`), never
automatically. A company on `is_indefinite = true` retention still moves data hot → warm → cold on the
same schedule — indefinite retention affects only whether the data is ever eligible for legal purge, not
whether it is tiered for cost/performance reasons.

# Access To Archived Data

Archived data (warm or cold) is **never** exposed through the normal `/api/v1/**` resource endpoints
used for live data. It has its own read-only surface:

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/api/v1/archive/manifests` | `archiving.read` | List archive manifests for the active company (record type, period, tier, row count, location) |
| GET | `/api/v1/archive/{manifest_id}/preview` | `archiving.read` | Read-only paginated preview of a **warm**-tier archive (queries `archive.*` directly); 403 if the manifest is cold-tier |
| POST | `/api/v1/archive/{manifest_id}/restore-requests` | `archiving.restore` | Submit a Restore-From-Archive request (see `# Restore From Archive`); required for any cold-tier read |
| GET | `/api/v1/archive/restore-requests/{id}` | `archiving.restore` | Poll restore-request status |
| GET | `/api/v1/archive/restore-requests/{id}/data` | `archiving.restore` | Read the staged, restored rows during the restore window (auto-expires) |

All five endpoints enforce `company_id` scoping exactly like live endpoints (via `X-Company-Id` and the
requesting user's company membership) — archived data is exactly as tenant-isolated as live data.
Warm-tier previews are genuinely read-only at the database level: the application's archive-read
database role has `SELECT`-only grants on the `archive` schema and no `INSERT`/`UPDATE`/`DELETE`:

```sql
CREATE ROLE qayd_archive_reader NOLOGIN;
GRANT USAGE ON SCHEMA archive TO qayd_archive_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA archive TO qayd_archive_reader;
ALTER DEFAULT PRIVILEGES IN SCHEMA archive GRANT SELECT ON TABLES TO qayd_archive_reader;
-- The Laravel connection used for archive previews authenticates as a role that has been
-- GRANTed qayd_archive_reader and NOTHING else on the archive schema.
```

Cold-tier data has no live SQL role at all — the only way to read it is the Restore-From-Archive
workflow, which is intentionally asynchronous (never instant) so that pulling years of Parquet back into
a database is a deliberate, audited, rate-limited action rather than an accidental query pattern.

# Automation

Archiving is never triggered manually in production; it runs on a fixed schedule via Laravel's task
scheduler, which enqueues one job per company per record type so that a single slow company cannot block
others:

```php
// app/Console/Kernel.php (schedule() method)
$schedule->command('archive:run --record-type=audit_log')
    ->dailyAt('02:00')
    ->withoutOverlapping(1440)          // Redis lock, 24h TTL — see below
    ->onOneServer();

$schedule->command('archive:run --record-type=notification')
    ->dailyAt('02:15')
    ->withoutOverlapping(1440)
    ->onOneServer();

$schedule->command('archive:run --record-type=stock_movement')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping(2880)
    ->onOneServer();

$schedule->command('archive:run --record-type=fiscal_year')
    ->monthlyOn(1, '04:00')             // fiscal-year closures happen rarely; monthly sweep is sufficient
    ->withoutOverlapping(2880)
    ->onOneServer();

$schedule->command('archive:cold-export')
    ->dailyAt('05:00')
    ->withoutOverlapping(1440)
    ->onOneServer();

$schedule->command('archive:verify')
    ->dailyAt('06:00')
    ->onOneServer();
```

`withoutOverlapping()` uses a Redis-backed lock (`schedule-{command}` key, TTL in minutes) so a slow run
never double-executes; each `archive:run` invocation additionally acquires a per-company advisory lock
before touching that company's data:

```php
DB::statement('SELECT pg_advisory_lock(hashtext(?))', ["archive:{$companyId}:{$recordType}"]);
// ... do the work ...
DB::statement('SELECT pg_advisory_unlock(hashtext(?))', ["archive:{$companyId}:{$recordType}"]);
```

**Partition lifecycle is delegated to `pg_partman`** rather than hand-rolled cron SQL, since it already
solves premake, retention-based detach, and default-partition safety:

```sql
CREATE EXTENSION IF NOT EXISTS pg_partman;

SELECT partman.create_parent(
    p_parent_table  := 'public.audit_logs',
    p_control       := 'created_at',
    p_type          := 'range',
    p_interval      := 'monthly',
    p_premake       := 3               -- always have 3 months of future partitions ready
);

UPDATE partman.part_config
SET retention                = '13 months',
    retention_keep_table     = true,   -- detach, do NOT drop — Strategy A stops at "warm"
    retention_keep_index     = false,  -- drop secondary indexes on detach to save space
    infinite_time_partitions = true
WHERE parent_table = 'public.audit_logs';

-- pg_partman's own maintenance run, scheduled via pg_cron or the Laravel scheduler shelling to psql:
SELECT partman.run_maintenance_proc();
```

The Laravel scheduler calls `partman.run_maintenance_proc()` nightly ahead of `archive:run`, so newly
detached-but-not-yet-relocated partitions are already sitting in the `archive` schema (pg_partman is
configured with `retention_schema := 'archive'`) by the time the cold-export sweep runs. Every job run
writes a row to `archive_job_runs` (started_at, finished_at, record_type, companies_processed,
rows_moved, status, error) for observability; failures alert via the same on-call channel as other
production incidents and are retried with exponential backoff (`retry_after`, `tries = 3` on the queued
jobs).

# Integrity & Verification Of Archives

No row is considered archived until its move is provably lossless. Every export (Strategy B or C)
produces a manifest row before the source data is touched, and a verification job confirms it after:

```sql
CREATE TABLE archive_manifests (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    record_type       VARCHAR(64) NOT NULL,
    source_table       VARCHAR(128) NOT NULL,     -- e.g. 'public.audit_logs' or 'archive.audit_logs_2024_03'
    tier              VARCHAR(16) NOT NULL CHECK (tier IN ('warm','cold')),
    storage_location  TEXT NOT NULL,              -- schema-qualified table name (warm) or R2 URI (cold)
    row_count         BIGINT NOT NULL,
    checksum_sha256   VARCHAR(64) NULL,           -- populated for cold (file-level checksum); NULL for warm
    period_start      DATE NULL,
    period_end        DATE NULL,
    verified_at       TIMESTAMPTZ NULL,
    verification_status VARCHAR(16) NOT NULL DEFAULT 'pending' CHECK (verification_status IN ('pending','passed','failed')),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at        TIMESTAMPTZ NULL
);
CREATE INDEX idx_archive_manifests_company ON archive_manifests(company_id, record_type);
CREATE UNIQUE INDEX uq_archive_manifests_source ON archive_manifests(source_table) WHERE deleted_at IS NULL;
```

`VerifyArchiveIntegrityJob` performs three checks and only sets `verification_status = 'passed'` if all
three agree:

1. **Row-count reconciliation** — `SELECT COUNT(*)` against the archived location equals `row_count`
   captured before the move.
2. **Checksum verification** (cold only) — re-download the Parquet object's ETag/SHA-256 from R2 and
   compare to `checksum_sha256`; a mismatch means silent corruption in transit or at rest.
3. **Sample row-level diff** — for a random 0.1% sample of rows (by primary key), compare a hash of the
   full row (`md5(row_to_json(t)::text)`) captured at export time against the same hash recomputed from
   the archived copy, catching corruption that a naive row count would miss (e.g. truncated JSONB
   columns).

A failed verification **blocks** the follow-up `DROP TABLE` / partition-drop step indefinitely and pages
the on-call database engineer — QAYD never deletes the hot/warm copy of anything until its cold copy is
proven byte-identical. In addition to per-export verification, a scheduled **restore drill** runs
monthly against a random sample of cold manifests: it restores each into a scratch schema, reruns the
row-count and sample-hash checks, and records the result in `archive_manifests.verified_at`, catching
bit-rot or R2-side corruption that occurred after the original verification passed.

# Legal Hold

A legal hold suspends archiving-to-purge (never archiving-to-warm/cold, which is a lossless move) for a
scoped set of records — typically imposed for litigation, a regulatory audit, or a tax dispute:

```sql
CREATE TABLE legal_holds (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id    BIGINT NOT NULL REFERENCES companies(id),
    record_type   VARCHAR(64) NOT NULL,
    scope         JSONB NOT NULL,        -- e.g. {"fiscal_year_id": 2023} or {"customer_id": 4821, "date_from": "2022-01-01", "date_to": "2024-12-31"}
    reason        TEXT NOT NULL,
    imposed_by    BIGINT NOT NULL REFERENCES users(id),
    imposed_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    released_by   BIGINT NULL REFERENCES users(id),
    released_at   TIMESTAMPTZ NULL,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_legal_holds_company_active ON legal_holds(company_id, record_type) WHERE released_at IS NULL;
```

Enforcement rules:

- Every eligibility query in `# What Gets Archived` already excludes records under an active hold from
  moving hot → warm/cold **only when the operation is a purge**; QAYD's design choice is that a hold
  additionally freezes tier movement altogether for simplicity and auditability — held records stay hot
  or wherever they currently are until released, rather than allowing warm/cold movement while blocking
  only the final purge. This trades some storage cost for a much simpler mental model: "on hold" means
  "frozen in place."
- Placing a hold requires `legal.hold.create` (Owner, CFO, Auditor, External Auditor roles only) and is
  itself an `audit_logs` entry with `reason` mandatory.
- A hold is never silently auto-released; only an explicit `released_by`/`released_at` clears it, and
  release requires the same permission tier as creation.
- Holds are checked at the start of every archiving job run (`archive:run`) and again immediately before
  any purge step — a hold placed mid-run aborts that company's remaining batch for the current run
  (already-moved warm/cold data from earlier in the run is unaffected; the hold only stops what has not
  yet moved and blocks any future purge).
- A retention decrease (see `# Retention Policy`) can never make a held record eligible for purge before
  the hold is released, regardless of how much the configured retention shrinks.

# Impact On Reports & Consolidation

Financial statements, trial balances, and multi-company consolidation reports must be correct whether
the fiscal year they cover is entirely hot, entirely warm, or split across tiers (a report spanning
"last 3 fiscal years" against a company whose oldest of those years was just archived).

- **Reports scoped entirely to hot data** (the overwhelming majority — current/prior fiscal year) behave
  exactly as documented in the Accounting module: they read `ledger_entries` directly.
- **Reports that need a closed, archived fiscal year** (e.g. a 3-year trend report, or a consolidation
  that rolls up a subsidiary whose books for that period are warm) use a `UNION ALL` view assembled at
  query time by the Reporting service, not a permanent database view (permanent views over `archive.*`
  would defeat the purpose of dropping secondary indexes on warm tables by forcing the planner to
  consider them on every hot-table query):

```sql
-- Constructed dynamically by ReportQueryBuilder when a requested date range intersects archived fiscal years
SELECT * FROM ledger_entries WHERE company_id = :company_id AND fiscal_year_id = ANY(:hot_fy_ids)
UNION ALL
SELECT * FROM archive.ledger_entries_fy_2023 WHERE company_id = :company_id
UNION ALL
SELECT * FROM archive.ledger_entries_fy_2022 WHERE company_id = :company_id;
```

- **Reports that need cold-tier data** cannot be served synchronously. The Reporting service detects
  this at request time (by checking `archive_manifests` for a `tier = 'cold'` match on the requested
  period) and returns `202 Accepted` with a `report_runs` row in `status = 'awaiting_restore'`, which
  automatically triggers a Restore-From-Archive request; the report completes once the restore lands,
  and the caller is notified via the platform's realtime channel (Reverb) exactly like any other
  long-running `report_runs` job.
- **Consolidation** (parent company rolling up subsidiaries) applies the same rule per subsidiary: if
  subsidiary B's relevant fiscal year is warm while subsidiary A's is hot, the consolidation job unions
  across tiers per subsidiary before aggregating; if any subsidiary's data is cold, the entire
  consolidation run waits on that subsidiary's restore rather than silently omitting it — a consolidated
  financial statement is never allowed to be partially complete without an explicit warning surfaced to
  the requester.
- Trial balance opening-balance carryforward (`SUM` of all prior years to establish an opening balance
  for the earliest reported year) is precomputed and stored at the moment a fiscal year is closed
  (`fiscal_years.opening_balance_snapshot JSONB`), specifically so that archiving older years never
  requires touching them to compute a later year's opening balance.

# Restore From Archive

Restoring is always request-driven, permissioned, audited, and time-boxed. It never mutates the archive
copy — a restore is always a read of the archive into a new staging location, never a move.

**Workflow:**

```
1. Client -> POST /api/v1/archive/{manifest_id}/restore-requests {reason}
2. Laravel checks `archiving.restore` permission + company scope
3. restore_requests row created, status = 'queued'
4. RestoreFromArchiveJob dispatched
     if tier == 'warm':
         ATTACH the archive.* table back as a partition, OR
         query archive.* directly and materialize into restore_staging.* (default — safer, no risk to live partitioning)
     if tier == 'cold':
         download Parquet from R2
         COPY FROM the Parquet (via the Python/DuckDB bridge) into restore_staging.<manifest_id>_<table>
         verify row count + checksum against archive_manifests before marking ready
5. status -> 'ready'; restore_staging table is queryable for `restore_window_hours` (default 72h)
6. Notification sent to requester (Reverb + `notifications` row)
7. On expiry: DROP TABLE restore_staging.<manifest_id>_<table>; status -> 'expired'
```

```sql
CREATE TABLE restore_requests (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    manifest_id         BIGINT NOT NULL REFERENCES archive_manifests(id),
    requested_by        BIGINT NOT NULL REFERENCES users(id),
    reason              TEXT NOT NULL,
    status              VARCHAR(16) NOT NULL DEFAULT 'queued'
                        CHECK (status IN ('queued','restoring','ready','expired','failed')),
    staging_location    VARCHAR(128) NULL,
    restore_window_hours SMALLINT NOT NULL DEFAULT 72,
    ready_at            TIMESTAMPTZ NULL,
    expires_at          TIMESTAMPTZ NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_restore_requests_company ON restore_requests(company_id, status);
```

Attaching a warm partition back into its live parent (the rare case where a business genuinely needs to
resume querying an old fiscal year as if it were current — e.g. a reopened audit adjustment) uses the
inverse of detach:

```sql
ALTER TABLE archive.journal_lines_fy_2023 SET SCHEMA public;
ALTER TABLE journal_lines ATTACH PARTITION journal_lines_fy_2023
    FOR VALUES IN (2023);   -- fiscal-year-keyed list partition; range syntax if using date ranges
-- Secondary indexes dropped on detach must be recreated before attach for equivalent hot-tier performance:
CREATE INDEX CONCURRENTLY idx_journal_lines_fy_2023_account ON journal_lines_fy_2023(account_id);
```

Restore requests for **cold** data default to staging (step 4's safer branch), never a direct re-attach,
because re-attaching years-old cold data straight into a live partitioned table without going through
warm-tier verification first would bypass the integrity guarantees in `# Integrity & Verification Of
Archives`. If a business case genuinely requires permanent un-archiving, that is a separate, manually
reviewed operation (`archive:reinstate`), not an automatic outcome of a restore request.

# Edge Cases

- **Reopened fiscal year during the 90-day cooling-off window**: if an auditor reopens a `closed` fiscal
  year (status transitions `closed → reopened → closed`) before it was archived, the archiving
  eligibility query in `# What Gets Archived` naturally excludes it (status is no longer `closed`). If it
  was reopened *after* already being archived to warm, the reopen operation must go through
  Restore-From-Archive first (re-attach), make its adjustment as a new reversing entry per the
  immutable-posted-rows rule, then re-close and re-archive on the next cycle — QAYD never edits data
  in-place inside `archive.*`.
- **Retention policy changed while an export is in flight**: `ExportPartitionToColdStorageJob` re-checks
  the effective retention policy immediately before its final drop step, not only at enqueue time; if the
  policy was raised mid-flight, the job still completes the (harmless, additive) cold copy but skips the
  drop, leaving the data in both warm and cold until the next scheduled sweep re-evaluates it.
- **Legal hold placed after cold export, before purge**: purge is a separate, explicitly scheduled step
  (`archive:purge-expired`, gated by retention AND hold status) that never runs automatically as part of
  export — a hold placed any time before that step runs blocks it, full stop.
- **Company offboarding/closure while archives exist**: companies are never hard-deleted (per the
  platform-wide soft-delete rule); a closed company's data continues its normal hot→warm→cold lifecycle
  and remains subject to the same legal retention, since statutory retention is tied to when the records
  were created, not to whether the company is currently operating.
- **Partition boundary spanning a fiscal year that is only partially closed** (a company changes its
  fiscal year-end mid-year): `fiscal_periods` — not calendar months — is the authoritative boundary for
  the `journal_lines`/`ledger_entries` partition key, so a change to a company's fiscal calendar going
  forward does not retroactively misalign already-created partitions for prior, unaffected years.
- **Restore request for data already covered by an in-progress archiving run**: `RestoreFromArchiveJob`
  and `archive:run` both take the same per-company advisory lock (`# Automation`); a restore request that
  arrives mid-run waits for the lock rather than racing the export.
- **AI layer needing multi-year historical context** (e.g. the Forecast Agent modeling a 5-year revenue
  trend that spans cold-tier years): the AI layer cannot query `archive.*` or R2 directly — per the
  platform rule that AI never writes to (and, by extension in QAYD, never reads production storage
  outside) the Laravel API — so it submits the same Restore-From-Archive request as any user (subject to
  the same permission check under the requesting user's identity) and waits for the `ready` state before
  running its model on the restored data.
- **A restore request's staging table outliving `restore_window_hours` due to a stuck expiry job**: the
  expiry sweep (`archive:expire-restores`) is idempotent and runs hourly; additionally, `restore_staging`
  schema tables carry a hard `pg_cron`-enforced backstop TTL so a failed Laravel scheduler cannot leave
  restored sensitive financial data live indefinitely.
- **Notification exempted from legal retention but referenced by an active audit_log entry**: an
  `audit_logs` row that references a `notification_id` (e.g. "escalation SMS sent") retains that
  reference even after the notification itself is purged; the audit log is the system of record for what
  happened, and it is retained on the audit-log schedule regardless of whether the referenced entity still
  exists — foreign keys from `audit_logs` to ephemeral entities are therefore always nullable and never
  `ON DELETE CASCADE`.
- **Sample-hash verification false positive from non-deterministic JSONB key ordering**: row hashes are
  computed from `jsonb_build_object` with explicitly sorted keys (never `row_to_json` directly on a
  column containing JSONB with insertion-order-dependent serialization) so that a byte-identical logical
  row always produces the same hash regardless of how Postgres physically stored the JSONB.

# End of Document
