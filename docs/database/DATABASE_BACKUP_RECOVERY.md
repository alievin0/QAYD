# Database Backup & Disaster Recovery — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: Backup & Disaster Recovery
---

# Purpose

QAYD is an AI Financial Operating System of record for its customer companies: chart of accounts,
posted journal entries, invoices, bills, payroll runs, tax transactions, and the immutable audit
trail that regulators and auditors rely on. Loss of this data is not an inconvenience — it is a
company-ending event for QAYD and a compliance breach for every tenant it serves. This document
defines the backup and disaster-recovery (DR) architecture for the PostgreSQL 15+ primary database
that backs the Laravel 12 (PHP 8.4+) application: what gets backed up, how often, where it is
stored, how it is encrypted, how it is restored, and how restore capability is proven on a schedule
rather than assumed. It covers logical and physical backup strategies, continuous WAL archiving and
point-in-time recovery (PITR), Grandfather-Father-Son (GFS) retention, Cloudflare R2/AWS S3 storage
with encryption at rest and cross-region replication, automation via pgBackRest/WAL-G, full and
partial restore procedures — including the hard problem of restoring a single tenant out of a
shared multi-tenant cluster — verification drills, the relationship between high availability and
backup, and financial-data retention compliance. Every command and configuration block below is
runnable as written against the QAYD production topology (AWS-hosted PostgreSQL primary + Cloudflare
R2 backup storage + Cloudflare-fronted application tier).

# Objectives

Recovery objectives are defined per data class, because not all QAYD data carries the same cost of
loss. Posted accounting rows (`journal_entries`, `journal_lines`, `ledger_entries`) and financial
source documents (`invoices`, `bills`, `payroll_runs`, `tax_transactions`) are Tier 0. Operational
and reference data (`products`, `customers`, `vendors`, `bank_accounts`) is Tier 1. Derived/cache
data (materialized reporting views, AI conversation transcripts) is Tier 2.

| Tier | Data classes | RPO (max data loss) | RTO (max time to restore service) |
|---|---|---|---|
| 0 — Financial ledger | journal_entries, journal_lines, ledger_entries, invoices, bills, payroll_runs, tax_transactions, audit_logs | ≤ 5 minutes | ≤ 1 hour (full cluster), ≤ 4 hours (single-tenant PITR restore) |
| 1 — Operational/master data | customers, vendors, products, bank_accounts, inventory_items, stock_movements, companies, users | ≤ 15 minutes | ≤ 2 hours |
| 2 — Derived/cache | ledger_entries materialized refresh, ai_conversations, ai_messages, report_runs | ≤ 24 hours | ≤ 8 hours (can be regenerated from Tier 0/1) |

Because Tier 0 and Tier 1 tables live in the same PostgreSQL cluster, the *technical* RPO for the
whole database is governed by continuous WAL archiving: **RPO ≤ 5 minutes**, bounded by
`archive_timeout` and the WAL shipping interval (below). The differentiation by tier matters for
prioritization during an incident and for defining "service restored" — Tier 0 correctness gates
the all-clear even if Tier 2 caches are still rebuilding.

Company-level SLA commitments (communicated to enterprise customers in the Master Services
Agreement) are derived from these internal targets with a safety margin: **RPO ≤ 15 minutes, RTO ≤
4 hours** for any single-company data-loss incident, **RPO ≤ 5 minutes, RTO ≤ 1 hour** for a
platform-wide outage.

# Backup Types

QAYD runs three complementary backup mechanisms in parallel. No single mechanism is sufficient on
its own: logical dumps are portable and human-restorable but do not give point-in-time recovery at
scale; physical base backups are fast to restore but need WAL to reach a precise point in time;
continuous WAL archiving is what makes PITR possible at all.

## 1. Logical backups — `pg_dump` / `pg_dumpall`

Logical backups are schema+data dumps taken via `pg_dump` (per-database) and `pg_dumpall`
(globals: roles, tablespaces). They are used for: disaster-recovery-of-last-resort, cross-version
migration, exporting a single company's data for offboarding/legal hold, and cheap ad-hoc snapshots
before risky migrations.

```bash
# Full logical dump, custom format (parallel-restorable, compressed)
pg_dump \
  --host=qayd-prod.cluster-xxxx.us-east-1.rds.amazonaws.com \
  --port=5432 \
  --username=qayd_backup \
  --dbname=qayd_production \
  --format=custom \
  --compress=9 \
  --jobs=4 \
  --no-owner --no-privileges \
  --file=/var/backups/qayd/logical/qayd_production_$(date +%Y%m%dT%H%M%S).dump

# Cluster-wide globals (roles, grants, tablespaces) — required alongside the dump above
pg_dumpall --globals-only \
  --host=qayd-prod.cluster-xxxx.us-east-1.rds.amazonaws.com \
  --username=qayd_backup \
  > /var/backups/qayd/logical/globals_$(date +%Y%m%dT%H%M%S).sql
```

`--format=custom` is mandatory (not plain SQL) so that `pg_restore` can do parallel restore and
selective table/schema restore. The `qayd_backup` role is `LOGIN` only, granted `pg_read_all_data`,
and explicitly denied `SUPERUSER`/`CREATEROLE` — backups never run as a privileged role.

Before an application migration deploy, a targeted pre-migration dump is taken automatically by CI:

```bash
pg_dump --format=custom --schema-only --dbname=qayd_production \
  --file=/var/backups/qayd/pre_migration/schema_pre_$(git rev-parse --short HEAD).dump
```

## 2. Physical base backups

A physical base backup is a byte-for-byte copy of the PostgreSQL data directory (or, with
pgBackRest, an incremental/differential variant of it) plus the WAL segments needed to bring it to
consistency. Physical backups restore far faster than logical dumps at QAYD's data volume (tens to
hundreds of GB) because there is no row-by-row `INSERT`/index-rebuild step — PostgreSQL simply
starts up from the copied files.

```bash
# Full physical backup via pgBackRest (see Automation & Tooling for full stanza config)
pgbackrest --stanza=qayd-prod --type=full backup

# Incremental (default type once a full exists)
pgbackrest --stanza=qayd-prod --type=incr backup

# Differential (against the last full only, not the last incremental)
pgbackrest --stanza=qayd-prod --type=diff backup
```

Physical backups are what PITR restores from — they are combined with archived WAL to "fast-forward"
the restored cluster to any timestamp or LSN after the backup's start time.

## 3. Continuous WAL archiving

Every WAL segment PostgreSQL generates (16 MB units of the write-ahead log, or sub-segment slices
under `archive_timeout`) is shipped continuously to durable object storage. This is the mechanism
that turns "yesterday's backup" into "any point in time in the last N days."

```ini
# postgresql.conf — WAL archiving core settings
wal_level = replica            # minimum for archiving + replication
archive_mode = on
archive_command = 'pgbackrest --stanza=qayd-prod archive-push %p'
archive_timeout = 60           # force a segment switch at least every 60s -> bounds RPO
max_wal_senders = 10
wal_keep_size = 4GB            # local buffer if a replica/archiver falls behind
```

`archive_timeout = 60` is the direct lever on the 5-minute Tier-0 RPO target: even during low-write
periods, a WAL segment is forced to disk and shipped at least once a minute, so a total-loss event
never loses more than the last committed transactions plus at most 60 seconds of "nothing changed"
padding.

# Point-In-Time Recovery (PITR)

PITR restores the physical base backup and replays archived WAL up to an exact timestamp, LSN, or
named restore point — critical for recovering from logical corruption (a bad migration, an errant
`UPDATE` without a `WHERE`, a compromised credential used to delete rows) where simply restoring the
latest backup would also restore the damage.

## Setup

1. WAL archiving must be enabled and healthy (previous section) — verify with:
   ```bash
   pgbackrest --stanza=qayd-prod check
   ```
2. Named restore points are created before high-risk operations (major migrations, bulk data
   imports) so operators can restore to a precise pre-change LSN without needing an exact timestamp:
   ```sql
   SELECT pg_create_restore_point('pre_migration_2026_07_16_add_tax_returns');
   ```
3. Retention must cover the desired PITR window (see Backup Schedule & Retention) — WAL segments
   older than the oldest full backup you intend to restore from are pruned and cannot be replayed
   past.

## Procedure

```bash
# 1. Stop the target PostgreSQL instance (or provision a fresh one for restore-in-place-elsewhere)
sudo systemctl stop postgresql

# 2. Restore the base backup + WAL up to the desired point via pgBackRest
pgbackrest --stanza=qayd-prod \
  --delta \
  --type=time \
  --target="2026-07-16 09:14:00+03" \
  --target-action=promote \
  restore

# Alternative: restore to a named restore point instead of a wall-clock time
pgbackrest --stanza=qayd-prod --type=name \
  --target=pre_migration_2026_07_16_add_tax_returns \
  --target-action=promote restore

# 3. Start PostgreSQL — it enters recovery mode automatically (recovery.signal is written by pgBackRest)
sudo systemctl start postgresql

# 4. Monitor recovery progress
tail -f /var/log/postgresql/postgresql-15-main.log
# Look for: "recovery stopping before/after commit of transaction ..." then "database system is ready"

# 5. Verify the restored state before opening to the application tier
psql -U qayd_backup -d qayd_production -c \
  "SELECT max(created_at) FROM journal_entries; SELECT max(created_at) FROM audit_logs;"
```

`--target-action=promote` tells PostgreSQL to exit recovery and become writable once the target is
reached; use `pause` instead when an operator wants to inspect data at the target LSN before
committing to promote (useful when the exact restore point is being narrowed down interactively).

# Backup Schedule & Retention

QAYD uses a Grandfather-Father-Son (GFS) rotation layered on top of continuous WAL archiving, so
restore points exist at fine granularity recently and coarse granularity further back.

| Backup | Frequency | Type | Retention | Storage class |
|---|---|---|---|---|
| WAL archive | Continuous (≤60s segments) | Archived WAL | 35 days (rolling) | Cloudflare R2 Standard |
| Incremental (Son) | Every 6 hours | pgBackRest `incr` | 7 days | Cloudflare R2 Standard |
| Differential | Daily 02:00 UTC | pgBackRest `diff` | 14 days | Cloudflare R2 Standard |
| Full (Father) | Weekly, Sunday 01:00 UTC | pgBackRest `full` | 8 weeks | Cloudflare R2 Standard |
| Full (Grandfather) | Monthly, 1st at 00:30 UTC | pgBackRest `full` | 13 months | AWS S3 Glacier (cross-region) |
| Logical `pg_dump` | Daily 03:00 UTC | `pg_dump --format=custom` | 30 days | Cloudflare R2 (separate bucket) |
| Pre-migration snapshot | On every schema migration deploy | `pg_dump --schema-only` + `full` physical | 90 days | Cloudflare R2 |

Rules that keep this schedule internally consistent:
- WAL retention (35 days) must always exceed the age of the oldest full backup you need to PITR
  from plus a safety margin — since the oldest usable full for daily PITR is the weekly Father (max
  8 weeks old is too old for WAL replay economics), operational PITR is only promised within the
  **last 14 days**; anything older restores from the nearest full/differential without fine-grained
  replay.
- pgBackRest's own `repo-retention-full` / `repo-retention-diff` settings encode this table directly
  (see Automation & Tooling) so retention is enforced by the tool, not by a cron script deleting
  files.
- Monthly Grandfather backups satisfy the 7-year financial-record retention requirement (see
  Compliance) via lifecycle transition to cold storage rather than being deleted at 13 months —
  13 months is the *hot* retention window; a Glacier lifecycle rule moves them onward, it never
  deletes financial-grade fulls before the compliance clock expires (see Compliance & Financial
  Data Retention for the exact policy).

# Storage & Encryption

Backups are written to two independent providers so that a single vendor incident cannot destroy
both the live database and its backups.

- **Primary backup store: Cloudflare R2** (S3-compatible API), bucket `qayd-backups-prod`,
  same-provider as the application's object storage (R2 already hosts `attachments`), but in a
  **separate bucket and separate R2 access-key pair** from application file storage, so a leaked
  application-storage credential cannot read or delete backups.
- **Secondary/offsite store: AWS S3**, bucket `qayd-backups-dr`, region `us-west-2`, chosen to be a
  different cloud vendor and a different geography from both the primary AWS RDS region
  (`us-east-1`) and Cloudflare R2's storage. This is the cross-region/cross-vendor leg of the 3-2-1
  rule: 3 copies (production DB + R2 + S3), 2 different media/providers, 1 offsite.

```ini
# pgBackRest repo configuration — dual repo (R2 primary, S3 DR secondary)
[global]
repo1-type=s3
repo1-s3-endpoint=<accountid>.r2.cloudflarestorage.com
repo1-s3-region=auto
repo1-s3-bucket=qayd-backups-prod
repo1-s3-key-type=shared
repo1-path=/pgbackrest
repo1-retention-full=8
repo1-retention-diff=14
repo1-cipher-type=aes-256-cbc
repo1-cipher-pass=<from AWS Secrets Manager: pgbackrest/repo1-cipher>

repo2-type=s3
repo2-s3-endpoint=s3.us-west-2.amazonaws.com
repo2-s3-region=us-west-2
repo2-s3-bucket=qayd-backups-dr
repo2-path=/pgbackrest
repo2-retention-full=13
repo2-cipher-type=aes-256-cbc
repo2-cipher-pass=<from AWS Secrets Manager: pgbackrest/repo2-cipher>
```

Encryption is layered:
- **In transit**: all traffic to R2/S3 is TLS 1.2+ only; `archive_command` and `pgbackrest` calls
  never traverse plaintext HTTP.
- **At rest, application-layer**: pgBackRest encrypts every backup and WAL file client-side with
  AES-256-CBC before upload (`cipher-pass` above) — R2/S3 receive only ciphertext, so a bucket
  misconfiguration or provider-side breach does not expose financial data.
- **At rest, provider-layer**: R2 and S3 buckets additionally have provider-managed encryption at
  rest enabled (SSE-S3 / R2 default encryption) as defense-in-depth, and bucket policies deny
  unencrypted (`aws:SecureTransport: false`) requests.
- **Key custody**: cipher passphrases live in AWS Secrets Manager (`pgbackrest/repo1-cipher`,
  `pgbackrest/repo2-cipher`), rotated every 180 days, never in `pgbackrest.conf` checked into any
  repo, and pulled into the config file at deploy time by the provisioning script only.
- **Object lock / immutability**: `qayd-backups-dr` (S3) has S3 Object Lock in *Compliance* mode
  with a 90-day minimum retention on every object, so a compromised operator credential cannot
  delete or overwrite DR backups within that window — a direct mitigation against ransomware that
  targets backups themselves.

# Automation & Tooling

pgBackRest is the tool of record for physical backup, WAL archiving, and restore; it was chosen over
WAL-G for its mature multi-repo (dual-cloud) support, built-in retention enforcement, and parallel
backup/restore. WAL-G remains a documented fallback (same WAL format compatibility path) if a future
migration to a WAL-G-only managed offering (e.g. certain AWS RDS/Aurora configurations that restrict
custom `archive_command`) is required.

```ini
# /etc/pgbackrest/pgbackrest.conf — stanza definition
[qayd-prod]
pg1-path=/var/lib/postgresql/15/main
pg1-port=5432
pg1-socket-path=/var/run/postgresql

[global]
process-max=4
compress-type=zst
compress-level=6
start-fast=y
delta=y
log-level-console=info
log-level-file=detail
```

Cron schedule on the dedicated backup-orchestration host (not the DB primary, to avoid I/O
contention):

```cron
# /etc/cron.d/pgbackrest-qayd
0 */6 * * *  postgres  pgbackrest --stanza=qayd-prod --type=incr  backup >> /var/log/pgbackrest/incr.log 2>&1
0 2   * * *  postgres  pgbackrest --stanza=qayd-prod --type=diff  backup >> /var/log/pgbackrest/diff.log 2>&1
0 1   * * 0  postgres  pgbackrest --stanza=qayd-prod --type=full  backup >> /var/log/pgbackrest/full.log 2>&1
30 0  1 * *  postgres  pgbackrest --stanza=qayd-prod --type=full  backup --repo=2 >> /var/log/pgbackrest/monthly.log 2>&1
0 4   * * *  qayd      /usr/local/bin/qayd-logical-dump.sh >> /var/log/qayd/logical-dump.log 2>&1
15 4  * * *  qayd      /usr/local/bin/qayd-backup-verify.sh >> /var/log/qayd/backup-verify.log 2>&1
```

Where AWS RDS/Aurora is used as the managed PostgreSQL layer (QAYD's `us-east-1` primary runs on RDS
for Postgres 15), pgBackRest coexists with, rather than replaces, RDS's own automated snapshotting —
RDS automated snapshots give a fast one-click restore of the whole instance and are retained 7 days
at the platform level; pgBackRest gives the longer-horizon, cross-vendor, PITR-with-arbitrary-target
capability RDS snapshots alone do not provide (RDS PITR is limited to the automated-backup retention
window and cannot restore into a different cloud).

```bash
# Supplementary: on-demand RDS snapshot before a major version upgrade or high-risk change
aws rds create-db-snapshot \
  --db-instance-identifier qayd-prod \
  --db-snapshot-identifier qayd-prod-pre-upgrade-2026-07-16

# RDS automated backups / PITR window (set via Terraform, not console, to keep it in version control)
aws rds modify-db-instance \
  --db-instance-identifier qayd-prod \
  --backup-retention-period 7 \
  --preferred-backup-window "01:00-02:00" \
  --apply-immediately
```

The Laravel application never triggers backups directly, but exposes an internal Artisan command
used by the on-call runbook and by CI pre-migration hooks to request a named restore point without
requiring shell access to the DB host:

```php
// app/Console/Commands/CreateRestorePoint.php
class CreateRestorePoint extends Command
{
    protected $signature = 'db:restore-point {name}';

    public function handle(): int
    {
        DB::statement('SELECT pg_create_restore_point(?)', [$this->argument('name')]);
        Log::channel('audit')->info('restore_point.created', ['name' => $this->argument('name')]);
        return self::SUCCESS;
    }
}
```

# Restore Procedures

## Full cluster restore (disaster recovery)

Used when the primary instance/region is lost entirely.

```bash
# 1. Provision a fresh PostgreSQL 15 host/instance in the DR region
# 2. Install pgBackRest and copy /etc/pgbackrest/pgbackrest.conf (from Secrets Manager-backed config mgmt)
# 3. Restore the latest full + WAL to "now" (or a specific target — see PITR)
pgbackrest --stanza=qayd-prod --delta restore

# 4. Start PostgreSQL, let it complete recovery to the latest available WAL
sudo systemctl start postgresql

# 5. Repoint Laravel's DB_HOST (and read replicas) via the app config / secrets store
# 6. Run migrations status check ONLY (never `migrate:fresh`) to confirm schema matches app expectations
php artisan migrate:status

# 7. Warm caches, verify queue workers, re-enable traffic at the load balancer / Cloudflare level
```

## Point-in-time restore (logical corruption / bad deploy)

Follow the PITR procedure above, but always restore to a **separate instance first**, never in
place over the live primary, so the pre-corruption data is still available for diffing and for
extracting only the rows that need to be reconciled back into production:

```bash
pgbackrest --stanza=qayd-prod --pg1-path=/var/lib/postgresql/15/restore_scratch \
  --type=time --target="2026-07-16 09:14:00+03" --target-action=pause restore
# Inspect at the scratch instance, confirm the target predates the bad change, then promote
pg_ctl -D /var/lib/postgresql/15/restore_scratch promote
```

## Single-table logical restore

For a narrower blast radius (e.g. an errant bulk `UPDATE` on `stock_movements` only), restore the
most recent `pg_dump --format=custom` into a scratch database and copy just the affected rows back:

```bash
createdb qayd_scratch_restore
pg_restore --dbname=qayd_scratch_restore \
  --table=stock_movements \
  /var/backups/qayd/logical/qayd_production_20260716T030000.dump

psql -d qayd_production -c "
  INSERT INTO stock_movements
  SELECT * FROM dblink('dbname=qayd_scratch_restore', 'SELECT * FROM stock_movements')
    AS t(/* full column list matching stock_movements */)
  ON CONFLICT (id) DO UPDATE SET
    quantity = EXCLUDED.quantity, movement_type = EXCLUDED.movement_type, updated_at = now();
"
```

## Single-tenant (single-company) restore — the hard case

QAYD's multi-tenancy is row-level (`company_id` on every table), not database- or schema-per-tenant.
This makes "restore Company X only" fundamentally harder than a normal restore, because a physical
or full-database logical restore brings back *every* company's data at the restore-point timestamp,
including companies whose current data must NOT be rolled back.

The supported procedure:

1. Restore the needed full/PITR snapshot into an **isolated scratch instance** (never production) —
   using the physical PITR procedure above, targeted at the timestamp the affected company needs.
2. From the scratch instance, run a `pg_dump` filtered by `company_id` using `--table` combined
   with row-level extraction, or (preferred, since `pg_dump` has no native row filter) a scripted
   per-table `COPY ... WHERE company_id = :id TO` export:
   ```bash
   psql -d qayd_scratch_restore -c "\copy (SELECT * FROM journal_entries WHERE company_id = 4821) TO '/tmp/je_4821.csv' CSV HEADER"
   psql -d qayd_scratch_restore -c "\copy (SELECT * FROM journal_lines  WHERE company_id = 4821) TO '/tmp/jl_4821.csv' CSV HEADER"
   -- repeat for every table with company_id, in FK-dependency order
   ```
3. In production, take an **immutable pre-restore snapshot of the affected company's current rows**
   (append-only audit copy, not a delete) before overwriting anything, and post a manual
   `audit_logs` entry documenting the operator, ticket, reason, and row counts.
3a. **Never restore posted journal entries by overwrite.** Because posted `journal_entries` /
   `journal_lines` are immutable by design, a single-tenant PITR that needs to "undo" posted
   entries must be expressed as **reversing entries** dated at the restore decision time, not as
   row replacement — row replacement would silently break the immutability guarantee the audit and
   compliance model depends on. Only Tier-1 master data (customers, vendors, products, inventory
   snapshots) may be restored by direct row overwrite.
4. Load the extracted rows for non-immutable tables into production inside a single transaction,
   scoped strictly by `company_id`, with FK-dependency-ordered `INSERT ... ON CONFLICT (id) DO
   UPDATE`.
5. Re-run company-scoped consistency checks (trial balance zero-sum, AR/AP sub-ledger
   reconciliation to control accounts) before informing the customer the restore is complete.
6. Decommission the scratch instance; retain its final dump under the same encrypted-at-rest policy
   for 90 days in case of a follow-up dispute.

This procedure is deliberately manual and reviewed by a second engineer — QAYD does not offer
automated self-service single-tenant restore, because the correctness of reversing immutable
financial entries requires human accounting judgment, not just a database operation.

# Backup Verification & Restore Drills

A backup that has never been restored is not a backup — it is an assumption. QAYD verifies backups
at two cadences: automated integrity checks on every backup, and full restore drills on a fixed
schedule.

```bash
# Automated: run after every backup job, checks repo integrity + WAL continuity
pgbackrest --stanza=qayd-prod check
pgbackrest --stanza=qayd-prod info --output=json   # parsed by monitoring to alert on gaps/failures
```

```bash
#!/usr/bin/env bash
# /usr/local/bin/qayd-backup-verify.sh — daily automated restore-and-checksum drill
set -euo pipefail
SCRATCH=/var/lib/postgresql/15/verify_scratch
rm -rf "$SCRATCH"
pgbackrest --stanza=qayd-prod --pg1-path="$SCRATCH" --type=default restore
pg_ctlcluster 15 verify_scratch start
psql -h /var/run/postgresql -p 5433 -d qayd_production -c \
  "SELECT count(*) FROM journal_entries; SELECT sum(debit_amount) - sum(credit_amount) FROM journal_lines;"
# Expect the debit/credit difference to equal exactly 0.0000 — trial-balance sanity check on the restored copy
pg_ctlcluster 15 verify_scratch stop
```

Cadence:

| Drill | Frequency | Scope | Success criterion |
|---|---|---|---|
| Automated restore-and-checksum | Daily (04:15 UTC, via cron above) | Latest incremental → scratch instance | Instance starts; trial balance sums to zero; row counts within 0.1% of production |
| Full DR failover drill | Quarterly | Full restore into the DR region, application pointed at it, smoke-tested end-to-end | RTO measured against the 1-hour/4-hour targets; documented in a drill report |
| Single-tenant restore drill | Semi-annually | Full single-company restore procedure executed against a synthetic test company | Procedure completes within RTO; second-engineer review sign-off recorded |
| Tabletop DR exercise | Semi-annually | Non-technical walkthrough with engineering + compliance leads | Runbook gaps identified and fixed within 2 weeks |

Every drill result (pass/fail, duration, discrepancies) is logged to `report_runs` with
`report_definitions.code = 'DR_DRILL'` and surfaced on an internal reliability dashboard; a failed
drill blocks the next scheduled production migration until remediated.

# High Availability vs Backup

High availability (HA) and backup solve different failure modes and must not be conflated:

- **Streaming replication** (synchronous or asynchronous physical replicas via `wal_level=replica`
  and `primary_conninfo`) protects against **hardware/instance failure and zone outages**. A replica
  promoted to primary resumes service in seconds to low-minutes.
- **Backups (this document)** protect against **data corruption, human error, and malicious
  deletion** — failure modes that replication actively *propagates* rather than protects against. A
  `DROP TABLE journal_lines` or a ransomware encryption event on the primary replicates to every
  synchronous standby just as fast as a legitimate write does.

```ini
# Standby configuration (HA leg) — separate from and complementary to backup
primary_conninfo = 'host=qayd-prod-primary port=5432 user=replicator application_name=qayd-standby-1'
primary_slot_name = 'qayd_standby_1_slot'
hot_standby = on
```

Failover runbook (HA) is out of scope for this document (owned by the Infrastructure/HA runbook),
but the interaction point that matters here: **after any failover, the new primary must be
re-registered as the pgBackRest `pg1-path` target**, and any in-flight backup job at the moment of
failover must be treated as failed and re-run — pgBackRest detects a `pg1` identity mismatch via
its `check` command and refuses to silently continue against the wrong node.

The practical rule communicated to every engineer: **replication answers "is the database up?";
backup answers "can we get last Tuesday's numbers back?"** Both are required; neither substitutes
for the other. A cluster with five synchronous replicas and zero tested backups has an RPO of zero
for hardware failure and an RPO of infinity for a bad `UPDATE` statement.

# Compliance & Financial Data Retention

QAYD's tenants are subject to jurisdiction-specific financial record-keeping requirements (Kuwait
Ministry of Commerce & Industry and GCC VAT/tax authority requirements commonly require 5-10 years
of retained accounting records; QAYD's default policy targets the stricter end for portability
across Gulf jurisdictions):

- **Minimum retention: 7 years** from the fiscal year-end for all Tier 0 financial data
  (`journal_entries`, `journal_lines`, `ledger_entries`, `invoices`, `bills`, `payroll_runs`,
  `tax_transactions`) and their corresponding `audit_logs` entries. This is enforced at two layers:
  application-level soft-delete-only (never hard delete, per platform rule) and backup-level —
  Grandfather monthly fulls are lifecycle-transitioned to S3 Glacier Deep Archive rather than
  expired, with an explicit 7-year minimum object lifecycle policy:
  ```json
  {
    "Rules": [{
      "ID": "qayd-financial-retention-7yr",
      "Status": "Enabled",
      "Filter": {"Prefix": "monthly/"},
      "Transitions": [
        {"Days": 90, "StorageClass": "GLACIER"},
        {"Days": 365, "StorageClass": "DEEP_ARCHIVE"}
      ],
      "Expiration": {"Days": 2555}
    }]
  }
  ```
- **Right-to-audit**: External Auditor and Auditor roles (see the platform RBAC roles) can request
  read-only access to a historical restore for audit purposes; this always goes through the scratch-
  instance restore procedure above, never a live production restore, and access is time-boxed and
  logged in `audit_logs` with `action = 'auditor_restore_access_granted'`.
- **Data residency**: backups of companies with a data-residency requirement (e.g. certain GCC
  public-sector customers) are pinned to region-restricted repos — QAYD's dual-repo pgBackRest
  config supports a residency-constrained tenant by disabling `repo2` cross-region replication for
  the shard/cluster hosting that tenant, documented per-contract rather than globally.
- **Backup deletion is itself an audited, sensitive operation**: no backup object may be deleted
  before its lifecycle policy expiry without a two-person approval recorded in `audit_logs`
  (`action = 'backup_deleted'`, `reason` required, `approved_by` a second `Owner`/`CFO`-role user) —
  this mirrors the platform-wide rule that deleting/voiding financial data always requires a human
  approval chain.

# Runbook

Step-by-step operator runbook for a production incident requiring database recovery. This is the
document paged on-call engineers open first.

```
STEP 0 — DECLARE
  - Confirm this is a DB-level incident (not application/network). Check:
      pg_isready -h qayd-prod.cluster-xxxx.us-east-1.rds.amazonaws.com
      psql -h <host> -U qayd_backup -c "SELECT 1"
  - Open an incident channel, page the on-call DBA lead + Engineering Manager.
  - Classify: (a) instance/AZ failure -> go to HA failover runbook, NOT this doc, unless HA also failed.
              (b) data corruption/bad deploy/malicious deletion -> continue below (PITR path).
              (c) total region/provider loss -> continue below (Full DR restore path).

STEP 1 — FREEZE
  - Put the application into maintenance mode (Cloudflare Pages Function returns 503 with Retry-After)
    to stop further writes against a possibly-corrupted primary.
  - If corruption is suspected, IMMEDIATELY note current WAL LSN:
      psql -c "SELECT pg_current_wal_lsn();"
  - Do NOT run any DDL or `migrate:fresh` at this point.

STEP 2 — DIAGNOSE TARGET RESTORE POINT
  - Pull recent deploys/migrations: `php artisan migrate:status`, git log, CI deploy log.
  - Identify the last known-good timestamp from audit_logs:
      SELECT * FROM audit_logs WHERE action IN ('journal.posted','row.deleted')
        ORDER BY created_at DESC LIMIT 200;
  - Decide: full-cluster restore, PITR-to-timestamp, PITR-to-restore-point, or single-table/tenant restore.

STEP 3 — RESTORE (pick the matching procedure from "Restore Procedures" above)
  - ALWAYS restore to a scratch/standby target first unless this is a total-loss full DR scenario.
  - Record start time (for RTO measurement) in the incident channel.

STEP 4 — VALIDATE
  - Run: SELECT count(*) FROM journal_entries; SELECT sum(debit_amount)-sum(credit_amount) FROM journal_lines;
  - Confirm trial balance = 0.0000, spot-check the last 10 posted invoices/bills against known values.
  - Confirm `audit_logs` continuity has no unexplained gap at the restore boundary.

STEP 5 — CUTOVER
  - Repoint DB_HOST in Laravel's config/secrets, restart queue workers and Reverb.
  - Take the application out of maintenance mode.
  - Immediately trigger an out-of-band pgBackRest full backup of the newly-live primary.

STEP 6 — POST-INCIDENT
  - Write the incident report within 24 hours: root cause, RPO/RTO actually achieved vs target,
    customer impact, remediation items.
  - File remediation tickets; a DR drill covering this exact failure mode is scheduled within 30 days.
  - Notify affected companies per the contractual incident-notification SLA.
```

# Edge Cases

- **WAL archive gap during a long-running backup.** If `archive_command` fails silently (e.g. a
  temporary R2 outage) while backups continue to report success, PITR beyond the gap becomes
  impossible even though "backups look fine." Mitigation: `pgbackrest check` runs after every cron
  job and alerts on WAL continuity gaps specifically, not just on backup completion.
- **Clock skew between application server and DB host** can make timestamp-based PITR targets
  (`--target="..."`) off by the skew amount. Always cross-check the target against
  `pg_current_wal_lsn()`/`pg_walfile_name()` captured at incident time rather than trusting a
  human-read clock alone for sub-minute precision restores.
- **Restoring across a major PostgreSQL version boundary** (e.g. a backup taken under PG15 restored
  onto a PG16 host after an in-place upgrade) is not supported by physical restore — physical
  backups are binary-compatible only within the same major version. Cross-version restore must use
  the logical `pg_dump`/`pg_restore` path, which is slower and is why the daily logical dump is
  retained even though physical/WAL is the primary mechanism.
- **Backup during an in-flight long transaction** (e.g. a multi-hour data migration job holding open
  a transaction) can bloat WAL retention needs and delay `pgbackrest backup`'s consistency point.
  Migrations that touch large tables are required to run in bounded batches specifically so they
  never hold a single transaction open longer than 5 minutes.
- **Company deleted then restored ("undelete") within the same PITR window** — because deletion is
  soft (`deleted_at` set, per platform rule), this is NOT a restore-from-backup scenario at all; it
  is a simple `UPDATE ... SET deleted_at = NULL`. Restore procedures should only be invoked for hard
  data loss, never for reversible soft-delete states — using PITR for this would unnecessarily roll
  back unrelated concurrent data.
- **Encryption key rotation invalidates old cipher-pass mid-retention-window.** pgBackRest supports
  multiple cipher passphrases only via a full re-encryption pass; when rotating
  `pgbackrest/repo1-cipher`, old backups within retention must be re-keyed via `pgbackrest ... 
  --repo1-cipher-pass=<old>` restore + re-backup with the new pass, or the rotation must be deferred
  until the old backups age out naturally — silently rotating without a plan orphans everything
  backed up under the old key.
- **Single-tenant restore where the tenant has cross-company shared reference data** (e.g. a
  consolidated group of companies under one parent using `company_users` links) — restoring
  Company A's `company_id`-scoped rows can leave dangling references if Company A's restore point
  predates a shared vendor/customer record that was later also linked to Company B. The runbook
  requires a dependency-graph check (FK validation pass) before the transaction in single-tenant
  restore Step 4 commits.
- **Backup storage provider outage during an active recovery.** If Cloudflare R2 (repo1) is down
  exactly when a restore is needed, `pgbackrest` automatically falls back to `repo2` (AWS S3) per
  its multi-repo priority order — this is the operational reason the DR leg must always be kept in
  sync on the same schedule as the primary, not as an infrequent afterthought copy.
- **Partial WAL segment loss (bit rot / silent corruption in object storage).** pgBackRest stores a
  checksum manifest per backup; `pgbackrest verify` (run monthly, separate from the daily restore
  drill) walks the full repo and flags checksum mismatches so corruption is caught before it is
  needed for a real restore, not during one.

# End of Document
