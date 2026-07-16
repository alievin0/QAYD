# Database Archiving & Data Lifecycle Management — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_ARCHIVING
---

# Purpose

QAYD is an AI Financial Operating System processing double-entry accounting, sales, purchasing,
banking, inventory, payroll, and tax data for multi-tenant companies across the Gulf, with Kuwait as
the anchor jurisdiction. Every posted financial fact — `journal_entries`, `journal_lines`,
`ledger_entries`, `invoice_items`, `bill_items`, `bank_transactions`, `stock_movements`,
`payroll_items`, `tax_transactions`, and `audit_logs` — is immutable once posted and, by the
platform-wide rule, is never hard-deleted. At QAYD's target scale (5,000+ active companies), this
produces on the order of 300 million detail rows per year across the transactional tables. Left
resident in the primary OLTP cluster indefinitely, this data does three damaging things at once: it
degrades planner and cache efficiency for every tenant sharing the cluster (a "noisy neighbor via
table bloat" failure mode), it inflates storage cost on the most expensive tier (NVMe-backed primary
volumes), and it accumulates statutory retention exposure without any structured process to prove,
search, or produce it on demand for an auditor or regulator.

This document defines the complete data lifecycle architecture that solves all three problems without
ever violating the immutability and retention guarantees the Accounting Engine depends on. It
specifies the three-tier **hot / warm / cold** model; the exact partition-detach SQL that moves data
between tiers as an O(1) metadata operation rather than a row-by-row `DELETE`; the archival job
architecture (Laravel-scheduled, queue-backed, idempotent, auditable); the policy engine that
reconciles engineering cost pressure against statutory retention floors for Kuwait and the wider GCC;
and the recovery, search, and restore mechanics that make archived data as legally discoverable as
live data, just slower and cheaper to reach.

This document builds directly on `DATABASE_PARTITIONING.md`, which establishes that every archivable
table is declaratively partitioned from creation and introduces the illustrative `archived_partitions`
catalog table and the base detach/export/re-attach mechanics. This document is the **owning
specification** for everything downstream of "a partition has been detached": the complete
`archived_partitions` schema, the policy engine, cold-tier storage format and encryption, the
compliance retention matrix, and the search/restore/recovery workflows an auditor, a support engineer,
or an AI Reporting Agent actually uses. It does not own live-cluster disaster recovery (WAL archiving,
PITR, replica failover — owned by `DATABASE_BACKUP_RECOVERY.md`), partition creation/pruning mechanics
(owned by `DATABASE_PARTITIONING.md`), or row-level tenant isolation policy definitions (owned by
`ROW_LEVEL_SECURITY.md`) — it references and depends on all three.

# Archive Strategy

## Guiding principle

Archiving in QAYD is never a `DELETE`. It is always: **detach the partition (metadata-only) → export
the detached table to durable storage → verify → catalog → drop the local copy only after
verification**. A row that was posted five years ago and a row posted five seconds ago are the same
row from a correctness standpoint; only its *tier* changes. This principle is what allows QAYD to
promise both "your data is never deleted" (a hard product and legal commitment) and "the primary
cluster stays small and fast" (an operational necessity) without those two promises being in tension.

## The three-tier model

| Tier | Location | Attached to parent? | Access latency | Who/what reads it |
|---|---|---|---|---|
| Hot | Primary Postgres, most recent 1–3 partitions | Yes | < 10 ms p95, cache-resident | Application OLTP paths: posting, live dashboards |
| Warm | Primary Postgres, older partitions, still attached per the retention window `DATABASE_PARTITIONING.md` registers with `pg_partman` | Yes | < 250 ms p95, partition-pruned, not cache-resident | Reporting, trial balance for prior periods, auditors within the online window |
| Cold | Cloudflare R2, Parquet/JSONL objects; partition detached and, after verification, dropped from Postgres | No | Seconds (metadata/search) to minutes (full rehydration) | Restore requests, legal holds, statutory audits beyond the online window |

A partition's hot→warm transition is a purely operational classification — no data moves, no SQL
runs; it simply ages out of the range `pg_partman`'s `p_premake` keeps freshly created and cache-warm.
The warm→cold transition is the one this document owns end-to-end: it is the `DETACH PARTITION` +
export + catalog + drop pipeline described below, gated by both the physical retention window
registered in `pg_partman` (`DATABASE_PARTITIONING.md`, e.g. 84 months for `journal_lines` and
`ledger_entries`, 36 months for `audit_logs`) **and** the fiscal/legal gates in this document (fiscal
period closed, no open audit, no legal hold — see Archive Policies).

## What is archived, what is summarized, what never leaves hot

1. **Fully archived detail rows** — high-cardinality, append-only, immutable-once-posted:
   `journal_lines`, `ledger_entries`, `invoice_items`, `bill_items`, `bank_transactions` (post
   reconciliation only), `stock_movements`, `tax_transactions`, `payroll_items`, `audit_logs`
   (`audit_log_payloads` follows the same partition and archives in lockstep with its parent
   `audit_logs` row, per `DATABASE_PARTITIONING.md`). `ledger_entries` partitions are always detached
   and archived in the same archive transaction as their corresponding `journal_lines` partitions —
   never independently — so the two tables remain reconcilable for any period both still cover.
2. **Header rows with a permanent lightweight index retained** — `journal_entries`, `invoices`,
   `bills`, `sales_orders`, `purchase_orders`, `credit_notes`, `debit_notes`, `receipts`,
   `vendor_payments`. Their detail lines archive on the schedule above; the header row itself is
   additionally mirrored, at archive time, into a small permanent `archived_document_index` row
   (`company_id`, `document_number`, `document_date`, `total_amount`, `currency_code`, `status`,
   `counterparty_id`, `source_table`, `archived_partition_id`) so a company's document list, global
   search, and "this record is archived — restore it" affordance never require a cold-tier hit merely
   to render.
3. **Never archived** — `accounts`, `account_types`, `customers`, `vendors`, `products`, `employees`,
   `tax_codes`, `cost_centers`, `projects`, `companies`, `users`, `roles`, `permissions`,
   `fiscal_years`, `fiscal_periods`. These are low-cardinality dimension/master tables; archiving them
   would break every historical foreign key join from warm and cold detail rows back to their
   reference data. They stay in hot Postgres for the life of the platform and are governed only by
   the standard soft-delete rule (`deleted_at`), never by this pipeline.

## Fiscal-period gating

Age alone is necessary but not sufficient to archive a financial partition. Before an
age-eligible partition is allowed to proceed past `DETACH` into export and drop, the archive job
verifies:

```sql
-- A partition covering [range_start, range_end) is archive-eligible only if every
-- fiscal_period it overlaps is closed, and no company touched by the partition has
-- an active legal hold covering that table/date range.
SELECT NOT EXISTS (
    SELECT 1 FROM fiscal_periods fp
    WHERE fp.status <> 'closed'
      AND fp.start_date < $range_end AND fp.end_date > $range_start
) AS all_periods_closed,
NOT EXISTS (
    SELECT 1 FROM legal_holds lh
    WHERE lh.status = 'active'
      AND lh.scope @> jsonb_build_object('tables', jsonb_build_array($table_name))
) AS no_blocking_hold;
```

If either check fails, the job logs `retained_past_threshold` on that candidate in `archive_jobs` and
retries on the next scheduled run rather than skipping silently — a partition that is old enough by
the physical clock but not yet closed/cleared is never force-archived.

## Tenant isolation inside shared partitions

QAYD's partitions (per `DATABASE_PARTITIONING.md`) are keyed by time (`posting_date`, `created_at`) or
by a hash of `company_id` at extreme single-tenant scale — either way, a single partition, and
therefore a single archived object, typically contains rows belonging to **hundreds or thousands of
different companies**. This has three concrete consequences this document must engineer around, since
none of them are automatically true just because the live tables enforce row-level security:

- **A per-company legal hold cannot change *when* a shared partition detaches** — detach is a
  platform-wide, table-level operation. A hold instead blocks the *drop* and the *eventual cold-tier
  purge* of any archived object proven (via `archived_partition_tenants`, below) to contain that
  company's rows, even though the object also contains other companies' rows that are otherwise
  purge-eligible.
- **Row-level security is not automatically preserved across `DETACH`.** A detached partition is, the
  instant `DETACH PARTITION` commits, an ordinary standalone table. Any protection that depended on
  "this data is only ever reachable through the RLS-protected parent" no longer holds by construction.
  As a defense-in-depth control independent of partition-RLS inheritance semantics, this document
  requires every detached-but-not-yet-exported table to have `ALTER TABLE ... ENABLE ROW LEVEL
  SECURITY` and the standard `company_isolation` policy (owned by `ROW_LEVEL_SECURITY.md`) re-applied
  to it immediately as part of step (b) of the archive job below, and grants no interactive role
  (human or application) direct `SELECT` on it — only the `qayd_archive_worker` service role may touch
  a detached table, until it is either re-attached (regaining the parent's protection) or dropped.
- **Search and restore must always filter by the requesting company**, even though the physical
  Parquet/JSONL object they query is multi-tenant. See Searching and Restoring.

## Ownership and automation boundary

The pipeline is a first-class scheduled subsystem, not a manual DBA task:

- A Laravel Console Command, `php artisan qayd:archive:run`, is registered on the scheduler:
  `Schedule::command('qayd:archive:run')->dailyAt('02:15')`, fifteen minutes before
  `pg_partman`'s own `partman-maintenance` `pg_cron` job at `02:00` has finished detaching
  age-eligible partitions (per `DATABASE_PARTITIONING.md`, `retention_keep_table = true` means
  `pg_partman` detaches but never drops — disposal is explicitly this pipeline's job).
- The command enqueues one queued job per detached-but-unarchived candidate onto an isolated `archive`
  Redis queue (`--queue=archive --tries=3 --backoff=300,900,3600`), processed by a worker pool separate
  from the request-serving and webhook-delivery queues, so a slow R2 upload never starves user-facing
  background work.
- Every state transition is written to `audit_logs` (`action IN
  ('partition.detached','partition.exported','partition.dropped','partition.restored')`,
  `actor_type = 'system'`), giving the archive pipeline the same forensic trail as any business
  mutation.

## Partition-detach SQL

Detach is metadata-only and safe against a live, continuously-written cluster:

```sql
-- Runs outside an explicit multi-statement transaction block (a requirement of CONCURRENTLY).
-- Takes SHARE UPDATE EXCLUSIVE, not ACCESS EXCLUSIVE, so concurrent inserts into sibling
-- partitions of journal_lines are never blocked.
ALTER TABLE journal_lines DETACH PARTITION journal_lines_p2019_06 CONCURRENTLY;

-- The partition is now an ordinary standalone table: still holds every row, still fully
-- queryable by name, but excluded from all journal_lines-level scans, pruning, and (per the
-- Tenant Isolation note above) excluded from the parent's inherited protections.
ALTER TABLE journal_lines_p2019_06 ENABLE ROW LEVEL SECURITY;
CREATE POLICY company_isolation ON journal_lines_p2019_06
    USING (company_id = current_setting('app.current_company_id')::bigint);
REVOKE ALL ON journal_lines_p2019_06 FROM qayd_app, qayd_readonly;
GRANT SELECT ON journal_lines_p2019_06 TO qayd_archive_worker;

-- Composite/lockstep detach for the reconciling ledger_entries partitions in the same range
-- (all 16 hash branches' matching monthly sub-partition, since ledger_entries is
-- HASH(company_id) -> RANGE(posting_date) per DATABASE_PARTITIONING.md):
ALTER TABLE ledger_entries_h00 DETACH PARTITION ledger_entries_h00_p2019_06 CONCURRENTLY;
-- ... repeated for h01..h15 by the same job, inside one archive_jobs "batch" grouping.

-- Only after export + checksum verification succeed (see Recovery):
DROP TABLE journal_lines_p2019_06;
```

Detach and drop are always two separate steps, never combined: if export or verification fails, the
detached table is still sitting on local disk, fully intact and directly queryable by the archive
worker role, and can be re-exported without touching a database backup at all.

## Archival job outline

```
qayd:archive:run  (daily 02:15 Asia/Kuwait, 15 min after pg_partman's 02:00 maintenance window)
|
+-- 1. SELECT candidates
|      FROM pg_partition_tree view (pg_inherits + pg_class), WHERE the partition is currently
|      detached (no longer a child in the parent's partition set) AND has no row yet in
|      archived_partitions AND passes the fiscal-period + legal-hold gate above.
|
+-- 2. For each candidate -> enqueue ArchivePartitionJob (queue=archive), grouped into an
|      archive_jobs "batch_id" so lockstep tables (journal_lines + its ledger_entries siblings)
|      are tracked, retried, and reported on together.
|
|      ArchivePartitionJob::handle()
|      +-- a. pg_advisory_lock(hashtext(partition_name))         -- no two workers race one partition
|      +-- b. Re-apply RLS + restrict grants to qayd_archive_worker (Tenant Isolation control)
|      +-- c. Compute the per-company row-count breakdown for this partition
|      |        SELECT company_id, count(*) FROM journal_lines_p2019_06 GROUP BY company_id;
|      +-- d. Export to Parquet (Zstandard) via a Python worker (psycopg + pyarrow) reading the
|      |        detached table directly; audit_log_payloads exports as gzip JSONL instead
|      |        (JSONB content maps naturally to JSON Lines; Parquet would force a lossy flatten)
|      +-- e. Client-side envelope-encrypt the exported object (AES-256-GCM, per-company-scoped
|      |        data key wrapped by a KMS key) before upload
|      +-- f. Upload object + manifest.json sidecar to Cloudflare R2
|      +-- g. Re-download a sample and re-verify the row-hash checksum (defense against silent
|      |        upload corruption)
|      +-- h. INSERT INTO archived_partitions (...)              -- the authoritative catalog row
|      +-- i. INSERT INTO archived_partition_tenants (...)        -- one row per company in (c)
|      +-- j. INSERT INTO archived_document_index (...)           -- for header/master-transaction
|      |        tables only, per the "header rows" category above
|      +-- k. DROP TABLE journal_lines_p2019_06
|      +-- l. UPDATE archived_partitions SET dropped_at = now()
|      +-- m. audit_logs: action='partition.dropped'
|      +-- n. pg_advisory_unlock(...)
|
+-- 3. qayd:archive:verify (independent nightly job) samples 2% of archived_partitions rows,
       re-downloads from R2, re-checksums, pages on-call on any mismatch.
```

Laravel job skeleton:

```php
// app/Jobs/ArchivePartitionJob.php
class ArchivePartitionJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [300, 900, 3600];

    public function __construct(
        private readonly string $table,
        private readonly string $partitionName,
        private readonly string $batchId,
    ) {}

    public function handle(
        ArchiveExportService $exporter,
        ArchiveManifestRepository $manifests,
        LegalHoldGuard $holdGuard,
    ): void {
        $lockKey = crc32($this->partitionName);
        DB::statement('SELECT pg_advisory_lock(?)', [$lockKey]);

        try {
            if ($manifests->alreadyArchived($this->partitionName)) {
                return; // idempotent re-run after a retry or duplicate dispatch
            }

            $holdGuard->assertNoBlockingHold($this->table, $this->partitionName);
            $exporter->reapplyRowLevelSecurity($this->partitionName);

            $tenants = $exporter->computeTenantFootprint($this->partitionName);
            $export  = $exporter->exportToObjectStorage($this->table, $this->partitionName);
            $exporter->verifyRoundTrip($export);

            $manifestId = $manifests->record($this->table, $this->partitionName, $export, $this->batchId);
            $manifests->recordTenantFootprint($manifestId, $tenants);
            $manifests->recordDocumentIndexIfHeaderTable($this->table, $this->partitionName, $manifestId);

            DB::statement("DROP TABLE {$this->partitionName}");
            $manifests->markDropped($manifestId);

            AuditLog::create([
                'action' => 'partition.dropped',
                'auditable_type' => $this->table,
                'auditable_id' => null,
                'reason' => "batch={$this->batchId}",
            ]);
        } finally {
            DB::statement('SELECT pg_advisory_unlock(?)', [$lockKey]);
        }
    }
}
```

# Cold Storage

## Definition and scope

Cold storage is the terminal tier short of statutory-authorized final disposal: a detached partition
that has been exported, verified, cataloged, and dropped from Postgres. It exists exclusively as
encrypted objects in Cloudflare R2, discoverable and rehydratable via the `archived_partitions`
catalog, never accessed by direct SQL against the live cluster.

## Object layout

```
Bucket: qayd-archive-{environment}                       (e.g. qayd-archive-production)

Key layout (one primary object + one manifest per archived partition):
  /{table_name}/{yyyy}/{mm}/{partition_name}.parquet            (or .jsonl.gz for audit_log_payloads)
  /{table_name}/{yyyy}/{mm}/{partition_name}.manifest.json

Full-tenant export (offboarding / legal-hold response), a derived, on-demand artifact:
  /_exports/{company_id}/{export_job_id}/{table_name}.parquet
```

Objects are keyed by table and date, not by company, because a single physical partition spans many
tenants (see Tenant Isolation Inside Shared Partitions) — per-company scoping is metadata
(`archived_partition_tenants`), not a key-space partition. Per-company full exports are a distinct,
derived artifact generated on request (see Restoring), not the steady-state archival unit.

## Format

- **Parquet, Zstandard-compressed**, for every numeric-heavy accounting/inventory/payroll table
  (`journal_lines`, `ledger_entries`, `invoice_items`, `bill_items`, `bank_transactions`,
  `stock_movements`, `tax_transactions`, `payroll_items`). Parquet preserves column types (critically,
  `NUMERIC(19,4)` precision, which a CSV export would silently degrade to a text/float round-trip
  risk) and is directly queryable by columnar engines (DuckDB, Athena-style federated query) without a
  full rehydration — see Searching.
- **Gzip-compressed JSON Lines**, for `audit_log_payloads` specifically, whose `old_value`/`new_value`
  columns are JSONB — a natural fit for line-delimited JSON rather than a forced Parquet schema
  flatten that would lose nested-key flexibility.
- Every object gets a `.manifest.json` sidecar, produced by the export step and duplicated into the
  `archived_partitions.schema_snapshot` column so the schema at time of archiving survives even if the
  live table's DDL evolves afterward (see Historical Data — schema drift):

```json
{
  "table": "journal_lines",
  "partition_name": "journal_lines_p2019_06",
  "row_count": 1204331,
  "min_posting_date": "2019-06-01",
  "max_posting_date": "2019-06-30",
  "column_schema_version": "v4",
  "source_row_hash_sha256": "9f2b7e1c4a...",
  "exported_at": "2026-07-14T02:31:07+03:00",
  "exported_by_batch_id": "arch_2026w28_b17",
  "tenant_count": 341,
  "compliance_retain_until": "2029-06-30T23:59:59+03:00",
  "postgres_partition_ddl": "CREATE TABLE journal_lines_p2019_06 PARTITION OF journal_lines FOR VALUES FROM ('2019-06-01') TO ('2019-07-01');"
}
```

## Encryption and durability

- R2 server-side encryption (AES-256) is enabled bucket-wide by default.
- QAYD additionally client-side encrypts every object before upload using an envelope scheme: a
  per-object 256-bit data key from `random_bytes(32)`, used with AES-256-GCM on the Parquet/JSONL
  bytes; the data key itself is wrapped by a KMS master key and the wrapped key stored in
  `archived_partitions.encrypted_data_key`. Because a company's rows can only be decrypted with a key
  QAYD's own infrastructure operators do not hold in plaintext form outside the KMS boundary, this
  satisfies the cryptographic-isolation expectation Gulf enterprise customers raise in vendor security
  review — R2 storage-layer access alone is insufficient to read tenant data.
- R2 does not expose native Object Lock/WORM at the time of writing. QAYD compensates with an
  application-level WORM guarantee: `archived_partitions` is the authoritative ledger of what must
  exist, and the independent nightly `qayd:archive:verify` job re-lists and re-checksums a sample via
  the R2 S3-compatible API, alerting on any drift, missing object, or unauthorized overwrite (R2
  object versioning is enabled, so an overwrite is detectable even without a hard lock).
- Cross-region durability: R2 is inherently replicated across Cloudflare's network. QAYD additionally
  never deletes an `archived_partitions` catalog row, even after the physical object's eventual
  statutory-authorized purge — a redacted tombstone (`purged_at`, checksum preserved, payload
  reference nulled) proves *that* the data existed and *when* it was lawfully disposed, independent of
  whether the object itself survives.

## Retrieval SLA

| Restore type | Mechanism | Target latency |
|---|---|---|
| Single partition rehydration | Download Parquet, `COPY` into a freshly created matching partition, `ATTACH PARTITION` | < 5 minutes for a ~1M-row partition |
| Single document lookup (one invoice + lines) | Federated point query via DuckDB reading the Parquet object directly from R2, no rehydration | < 3 seconds |
| Full company export (offboarding, legal-hold response) | Batch job scanning `archived_partition_tenants` for the company, extracting matching rows across every affected object, zipped to a signed download URL | < 2 hours for a 10-year, 500 GB tenant |

# Warm Storage

## Definition and boundary

Warm storage is still inside PostgreSQL, on the same cluster and disks as hot data — the boundary
between hot and warm is an access-pattern classification, not a different storage medium. A partition
is warm once it has aged out of `pg_partman`'s `p_premake` freshly-created window and is no longer
expected to be resident in `shared_buffers` or the OS page cache under normal load, but it remains
attached, indexed, and fully part of ordinary SQL queries against the parent table until it crosses
the retention threshold and is detached per Archive Strategy.

## Why warm exists as a distinct tier at all

Without a warm tier, QAYD would face a binary choice: keep everything expensively hot, or archive
aggressively and pay a cold-tier latency tax on routine prior-quarter reporting. Warm is the tier that
makes "show me last year's trial balance" fast (partition-pruned SQL, sub-250ms) while still letting
"show me the trial balance from seven years ago" be slow and cheap (cold-tier restore or federated
query) — most reporting and audit demand is warm-tier, not cold-tier, and the retention windows in
`DATABASE_PARTITIONING.md` (84 months for `journal_lines`/`ledger_entries`, 36 months for `audit_logs`)
are sized specifically so that the overwhelming majority of "prior period" business questions never
touch cold storage at all.

## Indexing strategy differs between hot and warm

All indexes defined on the parent table propagate to every partition automatically, so warm partitions
carry the same B-tree indexes as hot ones by default (`(company_id, posting_date)`,
`(account_id, posting_date)`, etc., per the canonical DDL in `DATABASE_PARTITIONING.md`). This
document adds one refinement for the highest-volume append-only tables once a partition is confirmed
warm (age > hot window, still attached): a scheduled `REINDEX ... (CONCURRENTLY)` pass converts the
`created_at`/`posting_date` B-tree on partitions older than 12 months into a **BRIN** index instead,
for `audit_logs` and `stock_movements` specifically — BRIN is near-free to maintain on append-only,
naturally time-ordered data and shrinks the index footprint of a table whose B-tree would otherwise
rival the heap in size:

```sql
-- Run once a monthly audit_logs partition is confirmed warm (age > 12 months, still attached):
DROP INDEX CONCURRENTLY idx_audit_logs_p2025_03_created;
CREATE INDEX CONCURRENTLY idx_audit_logs_p2025_03_created_brin
    ON audit_logs_p2025_03 USING BRIN (created_at) WITH (pages_per_range = 32);
```

`journal_lines`, `ledger_entries`, and the other core accounting tables keep their B-tree indexes for
the entire warm window unchanged, because auditors and the Reporting Agent run selective
`account_id`/`company_id` lookups against warm accounting data far more often than the purely
range-scan access pattern BRIN is optimized for.

## Query behavior and the date-bound contract

Warm-tier correctness and performance both depend on every query against a partitioned table
including a predicate on the partition key, so the planner can prune. QAYD's `ArchivableRepository`
base class (shared with `DATABASE_PARTITIONING.md`'s performance guidance) throws
`UnboundedPartitionQueryException` in non-production environments for any query builder call about to
execute without a `posting_date`/`created_at` bound, catching the mistake in CI rather than in a
production slow-query page. This matters more, not less, once warm partitions exist in volume: an
unbounded query against `journal_lines` with 84 months of monthly partitions attached scans up to 84
partitions instead of one or two.

## Warm-tier capacity ceiling

QAYD targets 36–84 attached monthly partitions per table at steady state (the exact ceiling is the
`pg_partman` retention window per table). Beyond that, planner overhead per query and the operational
cost of `pg_dump`/`pg_restore` against a table with hundreds of partitions both grow; this ceiling is
precisely why the retention windows in `DATABASE_PARTITIONING.md` were chosen at 84 (not, say, 240)
months for the core accounting tables — the number is a deliberate balance between "keep enough online
for routine multi-year reporting without a restore" and "keep the partition count in a range Postgres
handles gracefully."

# Archive Policies

## Two-layer policy model

Because a shared partition cannot have its detach timing vary per company (see Tenant Isolation Inside
Shared Partitions), QAYD's policy engine is deliberately split into two layers with different scopes:

1. **Table-level physical retention** — a single value per table, platform-wide, enforced by
   `pg_partman`'s `part_config.retention` (owned and registered in `DATABASE_PARTITIONING.md`). This
   layer answers "when does a partition detach." It cannot vary by company because the partition is
   shared.
2. **Company-level compliance and disposal policy** — `archive_policies`, owned by this document.
   This layer answers "how long must this company's data survive in cold storage before it is even
   eligible for the separate, rare, explicitly-authorized final purge procedure," and "does this
   company have a legal hold that blocks disposal entirely." It can and does vary by company, because
   it governs disposal timing and search/restore behavior, not detach timing.

```sql
-- System-level defaults, one row per archivable table, analogous in scope to account_types
-- (a global classification table with no company_id) — the platform default every company
-- inherits unless it has its own archive_policies override row.
CREATE TABLE archive_tier_defaults (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    table_name            TEXT NOT NULL UNIQUE,
    domain                TEXT NOT NULL,          -- 'accounting' | 'inventory' | 'payroll' | 'tax' | 'audit' | 'ai'
    cold_retention_years  INTEGER NOT NULL CHECK (cold_retention_years >= 0),
    legal_basis           TEXT NOT NULL,          -- e.g. 'Kuwait Commercial Code Art. 72; GCC VAT norms'
    auto_purge_eligible   BOOLEAN NOT NULL DEFAULT false,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO archive_tier_defaults (table_name, domain, cold_retention_years, legal_basis, auto_purge_eligible) VALUES
    ('journal_lines',    'accounting', 10, 'Kuwait Commercial Code retention norm for commercial books', false),
    ('ledger_entries',   'accounting', 10, 'Follows journal_lines for reconciliation integrity',          false),
    ('bank_transactions','accounting', 10, 'Kuwait Commercial Code; CBK AML/CFT record-keeping norms',     false),
    ('tax_transactions', 'tax',        10, 'GCC tax-authority record-retention norms (jurisdiction-specific, see Compliance)', false),
    ('payroll_items',    'payroll',    10, 'Kuwait labor-record retention norms; post-employment minimum 5y', false),
    ('stock_movements',  'inventory',   7, 'Commercial-books retention norm, lower-risk detail class',      true),
    ('audit_logs',       'audit',      10, 'Chain-of-custody for all of the above; retained at least as long as the longest business-data floor', false),
    ('ai_messages',      'ai',          3, 'Operational log, not financial evidence',                       true);

-- Per-company overrides / exceptions. company_id is NOT NULL here (unlike the defaults table
-- above) because every row is a specific tenant's deviation from platform policy — an
-- enterprise contract with a longer retention SLA, or a jurisdiction-specific extension for a
-- company operating under Saudi ZATCA or UAE FTA rules rather than Kuwait's.
CREATE TABLE archive_policies (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id            BIGINT NOT NULL REFERENCES companies(id),
    branch_id             BIGINT NULL REFERENCES branches(id),
    table_name            TEXT NOT NULL,
    cold_retention_years  INTEGER NULL,              -- NULL = inherit archive_tier_defaults
    jurisdiction          TEXT NULL,                 -- 'KW' | 'SA' | 'AE' | ... ISO 3166-1 alpha-2
    legal_basis           TEXT NULL,
    auto_purge_eligible   BOOLEAN NULL,
    created_by            BIGINT NULL REFERENCES users(id),
    updated_by            BIGINT NULL REFERENCES users(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at            TIMESTAMPTZ NULL,
    UNIQUE (company_id, table_name)
);

CREATE INDEX idx_archive_policies_company ON archive_policies (company_id) WHERE deleted_at IS NULL;
```

The effective policy for any (company, table) pair is `COALESCE(archive_policies override,
archive_tier_defaults)`, resolved by an `ArchivePolicyResolver` service consulted by every job that
needs a retention figure (the nightly purge-eligibility scan, the Compliance reporting endpoint, and
the restore-request expiry calculator).

## Legal holds

```sql
CREATE TABLE legal_holds (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id       BIGINT NOT NULL REFERENCES companies(id),
    branch_id        BIGINT NULL REFERENCES branches(id),
    case_reference   TEXT NOT NULL,
    description      TEXT NOT NULL,
    scope            JSONB NOT NULL,        -- {"tables": ["journal_lines","bills"], "date_from": "...", "date_to": "..."}
    status           TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','released')),
    imposed_by       BIGINT NOT NULL REFERENCES users(id),
    imposed_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    released_by      BIGINT NULL REFERENCES users(id),
    released_at      TIMESTAMPTZ NULL,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT chk_release_consistency CHECK (
        (status = 'active' AND released_at IS NULL) OR
        (status = 'released' AND released_at IS NOT NULL)
    )
);

CREATE INDEX idx_legal_holds_company_active ON legal_holds (company_id) WHERE status = 'active';
CREATE INDEX idx_legal_holds_scope_gin ON legal_holds USING GIN (scope);
```

A legal hold overrides every other policy signal: `auto_purge_eligible`, expired `cold_retention_years`,
and even an approved `restore_requests.expires_at` cleanup are all suppressed for any partition the
`archived_partition_tenants` junction proves contains a held company's rows. Only a user holding
`database.legal_hold.manage` (mapped to Owner, CFO, and General Counsel-equivalent roles; never AI
agents, per the platform AI-autonomy rule) may create or release a hold.

Permission API — imposing a hold:

```
POST /api/v1/database/legal-holds
```

```json
{
  "case_reference": "MOCI-2026-KW-00417",
  "description": "Ministry of Commerce inquiry into FY2021-2023 VAT-equivalent filings",
  "scope": {
    "tables": ["journal_lines", "bills", "tax_transactions"],
    "date_from": "2021-01-01",
    "date_to": "2023-12-31"
  }
}
```

```json
{
  "success": true,
  "data": {
    "id": 88,
    "company_id": 4821,
    "case_reference": "MOCI-2026-KW-00417",
    "status": "active",
    "imposed_by": 17,
    "imposed_at": "2026-07-16T09:12:03+03:00",
    "affected_archived_partitions": 34,
    "affected_warm_partitions": 6
  },
  "message": "Legal hold imposed. 34 archived and 6 warm partitions are now disposal-frozen for this company.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "7c2e0a3e-9c31-4b2a-9a55-3b214e6a9a11",
  "timestamp": "2026-07-16T09:12:03Z"
}
```

# Historical Data

## What "historical" means precisely

A row is historical, for the purposes of this document, once its owning `fiscal_period` is closed. A
closed period is never reopened for ordinary editing (corrections happen via reversing/adjusting
entries in a later open period, per the platform's immutability rule); this is what makes fiscal-period
closure — not mere calendar age — the correctness gate for archiving, layered on top of the
age-based `pg_partman` retention window described in Archive Strategy.

## The permanent closing-balance aggregate

The overwhelming majority of "historical" reporting demand is not row-level drill-down; it is
opening-balance carry-forward and N-year comparative trend lines (a five-year trial balance
comparison, a "revenue this year vs. the same month three years ago" chart). Forcing every such query
to touch warm or, worse, cold `ledger_entries` would be wasteful given how predictable the aggregate
shape is. QAYD therefore maintains a permanent, tiny, **never-archived** rollup table, populated as the
final step of each fiscal period's close workflow (owned by the Accounting Engine module) and
re-validated against `ledger_entries` before that period's partitions become archive-eligible:

```sql
CREATE TABLE account_period_balances (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id          BIGINT NOT NULL REFERENCES companies(id),
    branch_id           BIGINT NULL REFERENCES branches(id),
    fiscal_period_id    BIGINT NOT NULL REFERENCES fiscal_periods(id),
    account_id           BIGINT NOT NULL REFERENCES accounts(id),
    cost_center_id       BIGINT NULL REFERENCES cost_centers(id),
    opening_debit        NUMERIC(19,4) NOT NULL DEFAULT 0,
    opening_credit       NUMERIC(19,4) NOT NULL DEFAULT 0,
    period_debit         NUMERIC(19,4) NOT NULL DEFAULT 0,
    period_credit        NUMERIC(19,4) NOT NULL DEFAULT 0,
    closing_debit         NUMERIC(19,4) NOT NULL DEFAULT 0,
    closing_credit        NUMERIC(19,4) NOT NULL DEFAULT 0,
    source_archived_partition_id BIGINT NULL REFERENCES archived_partitions(id),
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (company_id, fiscal_period_id, account_id, cost_center_id)
);

CREATE INDEX idx_account_period_balances_lookup
    ON account_period_balances (company_id, account_id, fiscal_period_id);
```

`source_archived_partition_id` is populated (nullable until then) once the underlying `ledger_entries`
partition actually archives, giving every closing-balance row a documented lineage back to exactly
which cold object it was last validated against — an auditor questioning a five-year-old opening
balance can be pointed at both the aggregate row and its verifiable source object in one hop.

## Schema drift across archived generations

QAYD's schema evolves — a column gets added, a `CHECK` constraint tightens, an enum grows a new value
— over a platform lifetime measured in years, while individual archived objects can be a decade old by
the time anyone reads them again. Every `archived_partitions` row carries the exact column set, types,
and constraints of the source table **at the moment of export** in its `schema_snapshot` JSONB column
(sourced from `information_schema.columns` at export time, not reconstructed later). A restore that
targets today's live schema (see Restoring) runs the column list through the same migration-mapping
table Laravel's own migration history already provides, adding `NULL`/`DEFAULT` for columns that did
not exist when the data was archived and dropping columns that have since been removed from the live
schema (with those values preserved in the restore staging table for audit, never silently discarded).

# Performance

## Partition pruning is the entire performance story for warm data

Every benefit warm storage provides collapses to one Postgres planner behavior: constraint exclusion
over partition bounds. A query bounded by `posting_date` against `journal_lines` with 84 monthly
partitions attached opens only the 1–3 partitions the predicate can possibly match; an unbounded query
opens all 84. This is why the `ArchivableRepository` date-bound contract (Warm Storage, above) is
treated as a correctness rule in CI, not merely a performance suggestion.

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT account_id, SUM(base_debit), SUM(base_credit)
FROM journal_lines
WHERE company_id = 4821
  AND posting_date >= '2025-01-01' AND posting_date < '2025-04-01'
GROUP BY account_id;
-- Plan: "Partitions removed by initial pruning: 81" (of 84 attached) — the query touches 3.
```

## Benchmarks (representative, 5,000-tenant scale)

| Scenario | Rows touched | p95 latency | Tier |
|---|---|---|---|
| Post a journal entry (current month) | 1 partition, single-digit rows | 4 ms | Hot |
| Trial balance, current fiscal year | 3–12 partitions | 90 ms | Hot/Warm boundary |
| Prior-year comparative report (Y-1, Y-2, Y-3) | 36 partitions, pruned | 210 ms | Warm |
| Single archived invoice lookup via manifest + federated query | 1 R2 object, no rehydration | 2.1 s | Cold |
| Full partition rehydration (1M rows) | 1 object → 1 new partition | 4 min | Cold → Warm |
| Full 10-year, 500 GB tenant export | ~10,000 objects scanned | 95 min | Cold |

## Vacuum and statistics before detach

A partition about to be detached and exported benefits from one final `VACUUM (ANALYZE)` immediately
before the export read, both to guarantee the visibility map is current (so the sequential export scan
skips dead tuples cheaply) and so the row-count and checksum computed for the manifest reflect a
freshly-settled table rather than one with in-flight autovacuum activity racing the export:

```sql
VACUUM (ANALYZE) journal_lines_p2019_06;
```

This is scheduled as step 0 of `ArchivePartitionJob`, before the advisory lock's protected section
begins, since `VACUUM` does not require exclusive access and gains nothing from being inside the lock.

## Anti-patterns

- **Never** query a warm or cold-eligible table without a partition-key predicate "just this once" —
  there is no such thing as a one-off unbounded scan on an 80-partition table that stays cheap; the
  `UnboundedPartitionQueryException` guard exists specifically because "just this once" is how
  production incidents on partitioned tables actually start.
- **Never** treat `archived_partitions.row_count` as a substitute for re-verifying the checksum before
  trusting a restored dataset — a truncated export can still report a plausible row count if the
  truncation happened mid-file after the count was already materialized client-side.
- **Never** let application code assume a company's full history is always in exactly one physical
  location — by design, a company's data is hot, warm, and cold simultaneously (different periods in
  different tiers), and any "export everything for company X" feature must walk all three, not just
  query the live tables.
- **Never** run `ArchivePartitionJob` outside the isolated `archive` queue — its steps (Python export
  subprocess, R2 upload, re-download verification) have a fundamentally different latency and failure
  profile than request-serving background jobs and will starve them if co-queued.

# Compliance (Gulf/Kuwait financial retention)

## Purpose of this section

Retention is a legal floor; archiving is an engineering ceiling. The policy engine in Archive Policies
exists specifically so those two constraints are reconciled explicitly, in data, rather than emerging
by accident from an engineering default. The figures below reflect commonly cited Gulf retention norms
as of this document's writing and are deliberately made **configurable per company** via
`archive_policies` rather than hardcoded, precisely because exact statutory minimums vary by entity
type, license category, and amendment history, and QAYD's compliance/legal function — not this
engineering document — is the authority of record for confirming the exact figure applicable to a
specific customer before go-live in a given jurisdiction.

## Jurisdictional retention matrix

| Jurisdiction | Instrument (commonly cited basis) | Typical minimum retention | Applies to |
|---|---|---|---|
| Kuwait | Commercial Code (Law No. 68/1980) and Companies Law No. 1/2016 | 10 years from fiscal year end | Commercial books, journals, vouchers, correspondence |
| Kuwait | Central Bank of Kuwait AML/CFT instructions, Law No. 106/2013 | 10 years (regulated financial-service tenants only) | Customer due-diligence and transaction records |
| Kuwait | Public Authority for Manpower / labor-record norms | 5 years post-employment minimum | `payroll_runs`, `payroll_items`, `payslips`, `employees` |
| Saudi Arabia | ZATCA e-invoicing regulations; Saudi Companies Law | 6 years (tax/e-invoicing); up to 10 years (certain company records) | `invoices`, `tax_transactions`, `journal_lines` for SA-jurisdiction entities |
| UAE | Federal Tax Authority (FTA) VAT executive regulations | 5 years (standard); 15 years (real-estate-related records) | `tax_transactions`, `invoices`, `bills` for AE-jurisdiction entities |
| GCC (general) | VAT/e-invoicing frameworks where implemented | 5–6 years typical | Tax-relevant transaction detail |

Kuwait has not, at the time of this document, implemented a domestic VAT; QAYD's Kuwait-jurisdiction
tenants are governed by the Commercial Code retention norm above rather than a VAT-specific record-
keeping rule, while AED/SAR-denominated tenants operating under UAE or Saudi tax registration inherit
the VAT/e-invoicing figures instead — this is exactly why `archive_policies.jurisdiction` is a
per-company field rather than a platform-wide constant.

## Why the retention floor is a hard `AND`, never an `OR`, with engineering convenience

The fiscal-period gate in Archive Strategy and the `cold_retention_years` figure in Archive Policies
compose as a logical **AND** at every decision point: a partition may only move toward eventual
disposal if it is *both* past the engineering-convenience threshold *and* past its compliance floor
*and* free of any active legal hold. It is never sufficient for a partition to merely be old by the
clock. This is enforced structurally, not just by convention — the purge-eligibility query itself
encodes all three:

```sql
SELECT ap.id
FROM archived_partitions ap
JOIN archive_tier_defaults atd ON atd.table_name = ap.source_table
WHERE ap.dropped_at IS NOT NULL
  AND ap.purged_at IS NULL
  AND ap.created_at < now() - (COALESCE(
        (SELECT MAX(pol.cold_retention_years) FROM archive_policies pol
           JOIN archived_partition_tenants apt ON apt.company_id = pol.company_id
          WHERE apt.archived_partition_id = ap.id AND pol.table_name = ap.source_table),
        atd.cold_retention_years
      ) || ' years')::interval
  AND NOT EXISTS (
        SELECT 1 FROM archived_partition_tenants apt
        JOIN legal_holds lh ON lh.company_id = apt.company_id AND lh.status = 'active'
        WHERE apt.archived_partition_id = ap.id
          AND lh.scope @> jsonb_build_object('tables', jsonb_build_array(ap.source_table))
      );
```

Note the `MAX(pol.cold_retention_years)` across every company touched by a shared object: because one
archived partition can serve many tenants with different jurisdictions, the object as a whole is only
purge-eligible once **every** tenant it contains has individually cleared its own floor — the strictest
tenant's requirement governs the whole shared object. This query never runs automatically to completion
— it only ever *proposes* candidates to a human-gated final purge procedure (see Recovery), and results
are always reviewed by a holder of `database.archive.purge.approve` before any object is destroyed.

## Data residency and cross-border transfer

Cloudflare R2 buckets for QAYD's Gulf tenant base are provisioned in a region configuration agreed with
each enterprise customer's compliance function; where a customer's contract or local regulator requires
in-region residency and no suitable hyperscale region exists yet, QAYD's fallback is
customer-managed-key encryption plus a documented data-processing addendum, so that even if bytes
transit through a non-Gulf region, no party other than the customer (via their KMS key) can read
plaintext content — satisfying the substance of a residency requirement even where infrastructure
geography cannot yet satisfy its letter.

## Right-to-erasure conflicts

Where a company's end customer (e.g., an individual named on an invoice) asserts a personal-data
erasure request under an applicable data-protection regime, QAYD's platform position is that **financial
retention law overrides a personal-data erasure request for the specific fields required to satisfy
that retention obligation** (amounts, dates, tax identifiers, transaction references) — this is a
standard, well-established carve-out in data-protection frameworks generally, not a QAYD-specific
policy. What QAYD *can* do on such a request, and does via a scoped redaction job rather than deletion,
is null out genuinely non-essential personally identifying free-text fields (e.g., a customer contact's
personal notes field) on both the live and, if already archived, the cold-tier record's next-restore
pass — the retained financial fact survives; incidental personal narrative attached to it does not.

## Compliance attestation

`GET /api/v1/database/compliance/retention-report?company_id=4821` produces a signed, timestamped PDF
+ JSON attestation for exactly this purpose — "prove what you have retained, since when, under which
policy" — consumed by external auditors and regulators without granting them direct data access:

```json
{
  "success": true,
  "data": {
    "company_id": 4821,
    "jurisdiction": "KW",
    "generated_at": "2026-07-16T10:00:00+03:00",
    "tables": [
      { "table_name": "journal_lines", "effective_retention_years": 10, "legal_basis": "Kuwait Commercial Code Art. 72", "oldest_available_record": "2016-04-01", "active_legal_holds": 1 },
      { "table_name": "tax_transactions", "effective_retention_years": 10, "legal_basis": "Kuwait Commercial Code Art. 72 (no domestic VAT regime)", "oldest_available_record": "2016-04-01", "active_legal_holds": 0 }
    ]
  },
  "message": "Retention attestation generated.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "b1a9e6c0-2211-4f3a-9b8d-2b6a7c9d0e12",
  "timestamp": "2026-07-16T10:00:00Z"
}
```

# Recovery

## Archival recovery vs. disaster recovery

These are deliberately distinct concerns owned by different documents. Disaster recovery
(`DATABASE_BACKUP_RECOVERY.md`) answers "the primary cluster is damaged or lost; restore it from WAL +
base backup to a point in time." Archival recovery, owned here, answers a narrower and much more
common question: "this specific partition was correctly detached, exported, and dropped months or
years ago; bring its data back into a queryable state." The two only intersect in one edge case
(Recovery — Edge Case, below): a disaster-recovery restore of the primary cluster to a point in time
*before* a partition was dropped will bring that partition back as a live, attached partition, exactly
as if the archive job had never run — this is expected and correct, and the archive job's idempotency
check (`manifests->alreadyArchived`) prevents it from being re-archived and re-exported redundantly.

## RPO/RTO by tier

| Tier | Recovery Point Objective | Recovery Time Objective |
|---|---|---|
| Hot/Warm (live cluster) | Seconds (continuous WAL streaming, owned by Backup & Recovery) | Minutes (replica promotion) |
| Detached-but-not-yet-dropped (local safety window) | Zero — no export step involved, re-run export from local table | Minutes |
| Cold (R2, dropped locally) | Zero for data already exported and checksum-verified | Single partition: minutes. Full tenant: hours |

## Local safety window before drop

Every detached partition remains on local disk, unattached but undropped, for a minimum 14-day safety
window after successful export and verification, specifically so a checksum mismatch discovered by the
nightly `qayd:archive:verify` sampler — or a downstream discovery that an export was subtly incomplete
— can be corrected by re-exporting from the still-present local table rather than performing a full
cold-tier restore. Only after the window elapses **and** a human operator holding
`database.archive.drop.approve` confirms the `archived_partitions` row's checksum against the R2 object
does the scheduled drop proceed; this confirmation is itself logged to `audit_logs`.

## Partition reattachment SQL

```sql
-- Step 1: recreate a table with the archived partition's shape (from schema_snapshot,
-- not from today's live journal_lines definition, to avoid silently coercing old data
-- into a schema it never actually had).
CREATE TABLE journal_lines_p2019_06_restored (LIKE journal_lines);

-- Step 2: load the verified Parquet object (via a Python/Arrow loader, or \copy for the
-- csv_gz fallback format) into the new table.
-- (loader invocation shown in Restoring, below)

-- Step 3: validate the restored data satisfies the partition bound BEFORE attaching, using
-- NOT VALID + VALIDATE so the table remains usable during the (potentially slow) validation
-- scan rather than blocking on it up front.
ALTER TABLE journal_lines_p2019_06_restored
    ADD CONSTRAINT chk_bound CHECK (posting_date >= '2019-06-01' AND posting_date < '2019-07-01')
    NOT VALID;
ALTER TABLE journal_lines_p2019_06_restored VALIDATE CONSTRAINT chk_bound;

-- Step 4: attach. PostgreSQL uses the now-validated CHECK constraint to skip its own
-- redundant full-table validation scan on ATTACH.
ALTER TABLE journal_lines ATTACH PARTITION journal_lines_p2019_06_restored
    FOR VALUES FROM ('2019-06-01') TO ('2019-07-01');
ALTER TABLE journal_lines_p2019_06_restored DROP CONSTRAINT chk_bound;  -- redundant post-attach

-- Step 5: re-run the same lockstep restore for the corresponding ledger_entries hash-branch
-- sub-partitions, since the two tables must remain reconcilable once both are live again.
```

## Recovery drills

A quarterly, calendar-scheduled drill restores one randomly selected archived partition per major
table into a non-production database, verifies row count and checksum against the manifest, and
verifies the restored `journal_lines` partition reconciles against its `ledger_entries` counterpart
(`SUM(debit) - SUM(credit)` per account matches). The drill's pass/fail result and duration are written
to a `recovery_drill_log` entry consumed by the Compliance attestation report above, so "we have tested
that our archives actually restore" is itself an auditable, timestamped claim rather than an assumption.

# Searching

## Search is tier-aware, not tier-blind

A search across a company's full history must not force a cold-tier hit merely to answer "does this
exist," because the vast majority of searches resolve against hot/warm data or the permanent
`archived_document_index`/`account_period_balances` summaries without ever touching R2:

| Tier | Search mechanism |
|---|---|
| Hot/Warm | Ordinary indexed SQL against the live partitioned tables |
| Header/summary, any age | `archived_document_index` — permanent, never archived, full-text + structured index |
| Cold, metadata only | `archived_partitions` + `archived_partition_tenants`, indexed by table/date-range/company |
| Cold, content | Federated point query via DuckDB reading the target Parquet object(s) directly from R2, or a full restore for open-ended exploration |

## Manifest search index

```sql
ALTER TABLE archived_partitions ADD COLUMN search_vector TSVECTOR
    GENERATED ALWAYS AS (to_tsvector('simple', source_table || ' ' || partition_name)) STORED;

CREATE INDEX idx_archived_partitions_search ON archived_partitions USING GIN (search_vector);
CREATE INDEX idx_archived_partitions_table_range
    ON archived_partitions (source_table, range_start, range_end);
CREATE INDEX idx_archived_partition_tenants_company
    ON archived_partition_tenants (company_id, archived_partition_id);
```

A company-scoped search for "everything archived that could contain invoice INV-2019-00417" resolves
in two hops: `archived_document_index` for an immediate header hit (the common case — this is why the
index exists), falling back to `archived_partition_tenants` filtered by `company_id` joined to
`archived_partitions` filtered by the invoice's known date range, to locate the specific object(s) to
query or restore for line-item drill-down.

## Federated cold-tier content query

For ad-hoc exploration that does not justify a full rehydration, QAYD's `qayd:archive:search` command
line and the API below use DuckDB's `httpfs` extension to query a Parquet object directly over HTTPS
from R2 without downloading or restoring it:

```sql
INSTALL httpfs; LOAD httpfs;
SELECT account_id, debit, credit, memo
FROM read_parquet('https://qayd-archive-production.r2.example/journal_lines/2019/06/journal_lines_p2019_06.parquet')
WHERE company_id = 4821 AND account_id = 1105;
```

This is always executed by the archive service, never by directly exposing R2 credentials to a client,
and the result set is filtered server-side by `company_id` before any row leaves the service boundary —
the same tenant-isolation obligation that applies to a live query applies here even though Postgres RLS
itself is not in the code path for a Parquet file.

## Search API

```
GET /api/v1/database/archives/search?table=invoices&query=INV-2019-00417
```

```json
{
  "success": true,
  "data": [
    {
      "match_type": "archived_document_index",
      "document_number": "INV-2019-00417",
      "document_date": "2019-06-14",
      "total_amount": "2450.000",
      "currency_code": "KWD",
      "status": "paid",
      "archived_partition_id": 5502,
      "restorable": true,
      "estimated_restore_seconds": 8
    }
  ],
  "message": "1 match.",
  "errors": [],
  "meta": { "pagination": { "page": 1, "per_page": 25, "total": 1, "cursor": null } },
  "request_id": "3fe1a9d2-5c30-4e4b-8a11-1d9c9d6b7a02",
  "timestamp": "2026-07-16T10:04:11Z"
}
```

## AI-assisted search

The Reporting Agent and Document AI (per the platform AI-responsibilities rules) may issue searches
against `archived_document_index` and `archived_partitions` on a user's behalf — for example, resolving
"find last year's Q2 purchase orders from this vendor" into the correct restore-eligible objects — but
never issues a restore or a purge on its own authority. Every AI-originated search response is returned
with `confidence`, the underlying SQL/DuckDB predicate used (for human verification), and a citation to
the specific `archived_partitions` row(s), consistent with the platform rule that AI output must carry
confidence, reasoning, and supporting documents; autonomy level here is **suggest-only** — the agent
locates candidates and drafts a restore request, a human approves it (see Restoring).

# Restoring

## Workflow

```
requester              API                  queue                 R2                  Postgres
  |--POST restore-requests->|                  |                     |                    |
  |                          |--approval gate-->|                     |                    |
  |                          |  (auto if requester has database.archive.restore.approve;    |
  |                          |   otherwise queued for a second approver)                    |
  |                          |--enqueue-------->|                     |                    |
  |                          |                  |--GET object-------->|                    |
  |                          |                  |<--stream bytes------|                    |
  |                          |                  |--CREATE + \copy staging table------------>|
  |                          |                  |--verify checksum + row_count-------------->|
  |                          |                  |--filter rows by company_id (partial only)->|
  |                          |                  |--ATTACH or hand back staging schema------->|
  |                          |<--status=completed-|                    |                    |
  |<--GET restore-requests/id-|                  |                     |                    |
```

## Table

```sql
CREATE TABLE restore_requests (
    id                BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_id        BIGINT NOT NULL REFERENCES companies(id),
    branch_id         BIGINT NULL REFERENCES branches(id),
    archived_partition_id BIGINT NOT NULL REFERENCES archived_partitions(id),
    requested_by      BIGINT NOT NULL REFERENCES users(id),
    reason            TEXT NOT NULL,
    restore_type      TEXT NOT NULL CHECK (restore_type IN ('full_reattach','partial_extract','federated_preview')),
    filter_criteria   JSONB NULL,             -- e.g. {"document_number": "INV-2019-00417"} for partial_extract
    approval_status   TEXT NOT NULL DEFAULT 'pending' CHECK (approval_status IN ('pending','approved','rejected')),
    approved_by       BIGINT NULL REFERENCES users(id),
    approved_at       TIMESTAMPTZ NULL,
    status            TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','in_progress','completed','failed','expired')),
    target_location   TEXT NULL,              -- staging schema name or attached partition name
    row_count         BIGINT NULL,
    started_at        TIMESTAMPTZ NULL,
    completed_at      TIMESTAMPTZ NULL,
    expires_at        TIMESTAMPTZ NULL,       -- staging extracts auto-purge; full reattach never expires
    created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_restore_requests_company ON restore_requests (company_id, status);
CREATE INDEX idx_restore_requests_partition ON restore_requests (archived_partition_id);
```

## Restore types

- **`full_reattach`** — the entire partition (all tenants it contains) is rehydrated and
  `ATTACH PARTITION`ed back onto the live table, per the SQL in Recovery. Used for a full audit
  spanning a whole period, or a bulk correction. Requires `database.archive.restore.approve` from a
  second approver distinct from the requester, given its platform-wide (multi-tenant) blast radius.
- **`partial_extract`** — the object is rehydrated into an isolated staging schema
  (`restore_staging_{request_id}`), rows are filtered to the requesting company only
  (`WHERE company_id = :company_id AND <filter_criteria>`), and only the filtered rows are exposed to
  the requester — the multi-tenant source object is never exposed wholesale. This is the default and
  overwhelmingly common path (a single auditor asking for a single company's records).
  `restore_requests.expires_at` defaults to 30 days, after which the staging schema is automatically
  dropped by a scheduled cleanup job.
- **`federated_preview`** — no rehydration at all; a DuckDB `read_parquet(...)` query answers the
  request directly against R2 (see Searching). Used for quick verification before committing to a full
  `partial_extract`.

## API

```
POST /api/v1/database/restore-requests
```

```json
{
  "archived_partition_id": 5502,
  "restore_type": "partial_extract",
  "reason": "External auditor request — MOCI-2026-KW-00417, invoice INV-2019-00417 line detail",
  "filter_criteria": { "document_number": "INV-2019-00417" }
}
```

```json
{
  "success": true,
  "data": {
    "id": 991,
    "company_id": 4821,
    "restore_type": "partial_extract",
    "approval_status": "pending",
    "status": "queued",
    "estimated_completion_seconds": 45
  },
  "message": "Restore request created and pending approval.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "1a7c9e21-44df-4a2b-9e11-5f0a2b3c9d88",
  "timestamp": "2026-07-16T10:10:00Z"
}
```

```
GET /api/v1/database/restore-requests/991
```

```json
{
  "success": true,
  "data": {
    "id": 991,
    "status": "completed",
    "approval_status": "approved",
    "approved_by": 17,
    "target_location": "restore_staging_991",
    "row_count": 6,
    "started_at": "2026-07-16T10:11:02+03:00",
    "completed_at": "2026-07-16T10:11:47+03:00",
    "expires_at": "2026-08-15T10:11:47+03:00"
  },
  "message": "Restore complete. 6 matching rows available in restore_staging_991 until 2026-08-15.",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "9d4e2b71-0a3c-4f5e-8b22-6a1d0c2e7f44",
  "timestamp": "2026-07-16T10:11:47Z"
}
```

## Approval chain and permissions

| Permission | Grants | Default roles |
|---|---|---|
| `database.archive.search` | Search manifests and archived_document_index | Accountant, Senior Accountant, Auditor, External Auditor, Finance Manager, CFO |
| `database.archive.restore.request` | Create a `partial_extract`/`federated_preview` restore request | Senior Accountant, Auditor, Finance Manager, CFO |
| `database.archive.restore.approve` | Approve any restore request; required as second approver on `full_reattach` | Finance Manager, CFO, Owner |
| `database.legal_hold.manage` | Create/release legal holds | CFO, Owner |
| `database.archive.drop.approve` | Confirm a verified detached partition may be physically dropped | Finance Manager, CFO |
| `database.archive.purge.approve` | Authorize final, statutory-floor-cleared disposal of a cold object | Owner only |

`External Auditor` is deliberately given `database.archive.search` and `database.archive.restore.request`
but never `.approve` of any kind — an external party can ask, never authorize.

# Hot/Warm/Cold Tiering

## Tier state machine

```
                 age > hot window                 age > table's pg_partman retention
                 (operational reclass,             (physical: DETACH + export + verify + DROP,
                  no data movement)                 gated by fiscal-period close + no legal hold)
   ┌─────────┐  ───────────────────────►  ┌─────────┐  ─────────────────────────────────────►  ┌─────────┐
   │   HOT    │                           │   WARM   │                                          │   COLD   │
   │ Postgres │                           │ Postgres │                                          │ R2 object│
   │ cache-   │  ◄───────────────────────  │ pruned,  │  ◄─────────────────────────────────────  │ + catalog│
   │ resident │   (never restored to;       │ attached │   partial_extract / full_reattach          │  row     │
   └─────────┘    warm is simply "not       └─────────┘   restore_requests (see Restoring)        └─────────┘
                  hot anymore")                  │                                                     │
                                                  │ legal_holds.status = 'active'                       │ every tenant in
                                                  ▼ freezes any transition out of warm too              ▼ archived_partition_tenants
                                            ┌─────────┐                                            has cleared cold_retention_years
                                            │ FROZEN   │                                            AND no active hold
                                            │ (hold)   │                                            ┌─────────┐
                                            └─────────┘                                            │ PURGE-   │
                                                                                                     │ ELIGIBLE │
                                                                                                     └─────────┘
```

`PURGE-ELIGIBLE` is a proposal state, never an automatic action — see Compliance and Recovery for the
human-gated final disposal procedure, which is intentionally out of the archive job's own automation
boundary.

## Automation summary

| Job | Schedule | Owns |
|---|---|---|
| `partman-maintenance` (pg_cron, inside Postgres) | Daily 02:00 | Create upcoming partitions; detach (not drop) age-eligible ones |
| `qayd:archive:run` | Daily 02:15 | Export, catalog, verify, drop detached partitions |
| `qayd:archive:verify` | Nightly, offset from the above | Sample-checksum existing cold objects |
| `qayd:archive:purge-scan` | Weekly | Propose (never execute) purge-eligible candidates for human approval |
| Recovery drill | Quarterly | Restore-and-verify one sampled partition per major table |

## Scheme-agnostic mechanics

The detach/export/catalog/drop pipeline operates identically regardless of which partition key a table
uses — pure time-range (`journal_lines`), hash-of-tenant-then-range (`ledger_entries`), or
list-then-range (`stock_movements`), per `DATABASE_PARTITIONING.md`. Should a single tenant grow large
enough to warrant the dedicated per-tenant hash-repartitioning path described in the platform's
multi-tenancy scaling playbook, this pipeline requires no structural change: `DETACH PARTITION`,
Parquet export, and `archived_partition_tenants` bookkeeping are defined at the level of "a partition,"
not "a time range," and a hash-bucket partition detaches, exports, and restores by the identical SQL
shown throughout this document.

## Company-visible retention window

A company's own in-app "history" view queries only hot+warm (the online window) by default — for
`journal_lines`-backed views, up to 84 months, matching the platform default in `archive_tier_defaults`.
Anything older surfaces as a "records available on request" affordance backed by
`archived_document_index`, which triggers a `restore_requests` flow rather than a live query, keeping
the product's default experience fast while never actually hiding or losing older data.

# Examples

## Example 1 — Archiving a closed fiscal year's journal_lines partition end to end

Company 4821's FY2019 closed years ago; `journal_lines_p2019_06` aged past the 84-month `pg_partman`
retention window and was detached by `partman-maintenance` last night. `qayd:archive:run` picks it up:

```sql
-- Candidate check (abbreviated): fiscal period closed? yes. Legal hold on any touched company? no.
SELECT company_id, count(*) AS rows FROM journal_lines_p2019_06 GROUP BY company_id;
--  company_id | rows
--        4821  | 118422
--        5510  |  6091
--        ... (339 more tenants)

VACUUM (ANALYZE) journal_lines_p2019_06;
-- export to Parquet (Zstandard) by the Python worker; row-hash checksum computed
-- upload to qayd-archive-production/journal_lines/2019/06/journal_lines_p2019_06.parquet
-- re-download sample, re-verify checksum: OK

INSERT INTO archived_partitions
    (source_table, partition_name, range_start, range_end, storage_location, file_format,
     row_count, checksum_sha256, schema_snapshot, encrypted_data_key)
VALUES
    ('journal_lines', 'journal_lines_p2019_06', '2019-06-01', '2019-07-01',
     'journal_lines/2019/06/journal_lines_p2019_06.parquet', 'parquet',
     1204331, '9f2b7e1c4a...', '{"columns":[...]}', 'AQICAHg...');

-- 341 rows inserted into archived_partition_tenants, one per company shown above.
-- ledger_entries_h00_p2019_06 .. h15_p2019_06 detached and archived in the same batch.

DROP TABLE journal_lines_p2019_06;
UPDATE archived_partitions SET dropped_at = now() WHERE partition_name = 'journal_lines_p2019_06';
```

## Example 2 — Cold-archiving stock_movements past its shorter, purge-eligible floor

`stock_movements` carries a 7-year cold floor and `auto_purge_eligible = true` in
`archive_tier_defaults` (lower risk than core ledger tables). A partition from 2016 has cleared both the
pg_partman retention and the compliance floor for every tenant it touches, with no legal holds:

```sql
SELECT ap.id, ap.partition_name, ap.range_start
FROM archived_partitions ap
WHERE ap.source_table = 'stock_movements_outbound'
  AND ap.dropped_at IS NOT NULL AND ap.purged_at IS NULL
  AND ap.range_end < now() - INTERVAL '7 years'
  AND NOT EXISTS (
    SELECT 1 FROM archived_partition_tenants apt
    JOIN legal_holds lh ON lh.company_id = apt.company_id AND lh.status = 'active'
    WHERE apt.archived_partition_id = ap.id
  );
--  id  | partition_name                       | range_start
--  341 | stock_movements_outbound_p2016_03     | 2016-03-01
```

This candidate is surfaced by the weekly `qayd:archive:purge-scan` to a holder of
`database.archive.purge.approve` as a proposal; it is never destroyed automatically.

## Example 3 — External auditor restore for a bills line-item detail

An external auditor engaged by company 6002 needs the line detail behind `BILL-2020-00299`. The
requester (an internal Finance Manager acting on the auditor's behalf, since External Auditor role
cannot self-approve) issues:

```json
POST /api/v1/database/restore-requests
{
  "archived_partition_id": 5890,
  "restore_type": "partial_extract",
  "reason": "External audit — vendor bill detail requested by [auditor firm], case ref AUD-2026-0091",
  "filter_criteria": { "document_number": "BILL-2020-00299" }
}
```

The job rehydrates only company 6002's matching rows into `restore_staging_1042`, expiring in 30 days;
the auditor is handed read access scoped to that staging schema only, never to the underlying
multi-tenant Parquet object.

## Example 4 — Search resolving an archived invoice without touching R2

```
GET /api/v1/database/archives/search?table=invoices&query=INV-2018-00812
```

resolves entirely against the permanent `archived_document_index` (populated at archive time, per
Archive Strategy's "header rows" category) — total round trip under 20ms, zero R2 requests, because
the header summary answers "does it exist, what's the total, what status" without needing the archived
line-item Parquet at all. Only a follow-up request for line-item detail triggers a `partial_extract`.

## Example 5 — A legal hold blocking a scheduled purge

Company 4821 is issued the legal hold from the Archive Policies example above
(`MOCI-2026-KW-00417`, scoped to `journal_lines`, 2021–2023). The weekly `qayd:archive:purge-scan`
evaluates a candidate object covering 2021-Q3 that also contains rows for companies 5510 and 7003, both
otherwise purge-eligible:

```sql
-- The MAX() strictest-tenant rule from Compliance means this whole shared object is
-- retained until 4821's hold is released, even though 5510 and 7003 individually cleared
-- every floor. The scan reports it explicitly rather than silently dropping it:
SELECT 'RETAINED: object 5501 blocked by active hold on company_id=4821 (case MOCI-2026-KW-00417)';
```

Once Legal releases the hold (`legal_holds.status = 'released'`), the object is re-evaluated on the
next weekly scan and, assuming no other blocking condition, proceeds to the purge-approval proposal
queue.

# End of Document
