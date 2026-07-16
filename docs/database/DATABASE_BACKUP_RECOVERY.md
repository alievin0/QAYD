# Database Backup & Recovery — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_BACKUP_RECOVERY
---

# Purpose

QAYD is an AI Financial Operating System of record for double-entry accounting, sales, purchasing,
banking, inventory, payroll, and tax data across multi-tenant companies. Once a `journal_entries` row
transitions from `draft` to `posted`, its `journal_lines` are immutable and become the legal source of
truth for a company's financial statements. This document specifies exactly how the PostgreSQL data
plane backing that ledger is backed up, replicated, encrypted, validated, retained, monitored, and
restored — under normal operation, under partial failure, and under full regional disaster — so that no
posted financial fact is ever unrecoverable and every restore is auditable, encrypted, and provably
correct before it is trusted.

This is the canonical engineering reference for the Database Reliability Engineering (DRE) function at
QAYD. It exists so that:

- An on-call engineer can execute a recovery under pressure by following exact commands, not by
  reconstructing intent from tribal knowledge.
- Auditors and enterprise customers (banks, PE-backed holding companies, government-adjacent entities)
  can be shown a concrete, testable backup/DR posture during security questionnaires and SOC 2 /
  ISO 27001 audits.
- Every other module document (Accounting, Banking, Payroll, Tax) can assume the guarantees stated here
  — "posted journal lines are durable and recoverable to a point in time with near-zero loss" — without
  re-deriving them.

Scope: this document covers the **primary OLTP PostgreSQL cluster** (all canonical tables: `accounts`,
`account_types`, `fiscal_years`, `fiscal_periods`, `journal_entries`, `journal_lines`, `ledger_entries`,
`customers`, `vendors`, `products`, `invoices`, `bills`, `bank_accounts`, `bank_transactions`,
`inventory_items`, `stock_movements`, `payroll_runs`, `tax_transactions`, `audit_logs`, `companies`,
`branches`, `users`, and all sibling canonical tables from the shared design context), the **reporting
replica** used by `report_definitions` / `report_runs`, and the backup/DR posture for the adjacent Redis
cache/queue and Cloudflare R2 attachment store insofar as they affect recoverability of the system as a
whole. Laravel migration mechanics are covered in `DATABASE_MIGRATIONS.md`; this document assumes
migrations have already run and focuses purely on protecting the resulting data.

# Backup Strategy

## 1. Guiding principles

1. **Money is never approximately safe.** For Tier 0 financial tables (defined in `# RPO`), the target
   is zero committed-transaction loss under any single-component failure, and bounded, quantified loss
   under regional disaster.
2. **3-2-1-1-0, not 3-2-1.** QAYD extends the classic rule: **3** copies of data, on **2** different
   storage media/technologies, **1** off-site, **1** of those copies immutable (cannot be altered or
   deleted even by an administrator credential), and **0** unverified backups — every backup that is not
   periodically *restored and checked* does not count as a backup, it counts as an unverified file.
3. **Backups are a product, not a cron job.** They have an owner (Database Reliability Engineering),
   an SLA (see `# RPO` / `# RTO`), a monitoring surface (`# Monitoring`), and a test suite
   (`# Recovery Testing`).
4. **No single tool is a single point of failure.** The OLTP cluster's physical backups are produced by
   **pgBackRest**; the reporting replica's backups are produced independently by **WAL-G**, on a
   different schedule, against a different repository, using different IAM credentials. A defect,
   misconfiguration, or outage in one toolchain cannot silently disable the other.

## 2. Tool selection

| Concern | Tool | Applies to | Why |
|---|---|---|---|
| Full/incremental/differential physical backups + continuous WAL archiving of the OLTP cluster | **pgBackRest 2.53** | Primary + synchronous standby + async DR standby (`qayd-pg-primary-*`) | Native block-level incremental & differential backups, parallel compression, multi-repository (repo1/repo2) fan-out to two AWS regions in one invocation, built-in `verify`, page checksum awareness, delta restore. Mature C implementation with no Python/Go runtime dependency on the DB host. |
| Continuous WAL archiving + backups of the reporting replica | **WAL-G 3.0** | `qayd-pg-report-01` (logical replica feeding `report_runs`/BI) | Deliberately a *second* tool family so a pgBackRest bug/outage cannot take down both backup paths at once. Simpler footprint, good for a single non-HA instance with lighter SLAs. |
| Ad hoc verification, row-level checks, replication/LSN inspection | **psql / SQL** | All instances | The ground truth for "is the data actually correct," independent of what any backup tool *claims* about itself. |
| Volume-level crash-consistent snapshots | **AWS EBS Snapshots via AWS Backup** | `/data` (io2) and `/pg_wal` (io2) volumes on every cluster node | Fast (minutes) restore path for the common case (bad deploy, single-node corruption) without needing a full pgBackRest restore; independent of the PostgreSQL backup toolchain entirely. |
| Object storage durability | **S3 Versioning + Object Lock (Compliance mode)** | pgBackRest/WAL-G repositories | Immutability layer — the "1" in 3-2-1-1-0 — so a compromised operator or ransomware cannot delete or re-encrypt backup history inside the retention window. |

## 3. Backup types and cadence

| Backup type | Tool/command | Cadence | Retained on repo1 (hot, `me-south-1`) | Retained on repo2 (DR, `eu-central-1`) |
|---|---|---|---|---|
| Full | `pgbackrest --stanza=qayd-prod backup --type=full` | Sunday 01:00 Asia/Kuwait (UTC+3) | 14 days (2 fulls) | 90 days (13 fulls) |
| Differential | `pgbackrest --stanza=qayd-prod backup --type=diff` | Daily 01:00, Mon–Sat | 14 days | 90 days |
| Incremental | `pgbackrest --stanza=qayd-prod backup --type=incr` | Every 6 hours (07:00, 13:00, 19:00) | 3 days | 14 days |
| Continuous WAL | `archive_command` → `pgbackrest archive-push` | Continuous, per segment (~16 MB) or `archive_timeout=60s` | 14 days beyond the oldest retained full | 90 days beyond the oldest retained full |
| EBS crash-consistent snapshot | AWS Backup plan `qayd-prod-ebs-daily` | Daily 02:00 UTC+3 + pre-deploy ad hoc | 35 days | Copied cross-region, 35 days |
| WAL-G base backup (reporting replica) | `wal-g backup-push $PGDATA` | Daily 03:00 UTC+3 | 7 days | 30 days (cross-region copy of WAL-G repo) |
| Logical dump (compliance export, per company) | `pg_dump --format=custom --table='*' -Z6` | On-demand + monthly for the 20 largest tenants | 1 year in Glacier | 1 year in Glacier |

Rationale for the diff/incr split: a **differential** backup captures everything changed since the
last **full**, so restoring only ever needs (latest full + latest diff + WAL). An **incremental**
backup captures only what changed since the *previous backup of any type*, so the intraday 6-hour
incrementals are cheap to produce (typically < 20 GB on a database with a ~2.1 TB base size at current
scale) and let us bound restore time between the six-hourly cadence points without re-walking a full
day of WAL.

## 4. Repository layout (pgBackRest, primary OLTP cluster)

```ini
# /etc/pgbackrest/pgbackrest.conf — installed on qayd-pg-primary-01, -02, -03 (all cluster members)
[global]
repo1-type=s3
repo1-s3-bucket=qayd-pgbackrest-prod-mes1
repo1-s3-region=me-south-1
repo1-s3-endpoint=s3.me-south-1.amazonaws.com
repo1-path=/pgbackrest
repo1-retention-full=2
repo1-retention-full-type=count
repo1-retention-diff=14
repo1-cipher-type=aes-256-cbc
repo1-cipher-pass=<sourced from AWS Secrets Manager at boot, see # Backup Encryption>
repo1-bundle=y
repo1-block=y

repo2-type=s3
repo2-s3-bucket=qayd-pgbackrest-prod-euc1
repo2-s3-region=eu-central-1
repo2-s3-endpoint=s3.eu-central-1.amazonaws.com
repo2-path=/pgbackrest
repo2-retention-full=13
repo2-retention-full-type=count
repo2-retention-diff=90
repo2-cipher-type=aes-256-cbc
repo2-cipher-pass=<sourced from AWS Secrets Manager at boot>

process-max=4
compress-type=zst
compress-level=3
start-fast=y
delta=y
log-level-console=info
log-level-file=detail
archive-async=y
spool-path=/var/spool/pgbackrest

[qayd-prod]
pg1-path=/var/lib/postgresql/15/main
pg1-port=5432
pg1-socket-path=/var/run/postgresql
```

Every `backup` command pushes to **both** repo1 and repo2 in a single invocation — pgBackRest fans the
upload out in parallel, so the DR-region copy is never a manual "and then also copy it" afterthought;
it is created atomically with the primary backup. `repo1-bundle=y` and `repo1-block=y` enable file
bundling and block-level incremental delta within files (not just whole-file diffing), which matters at
QAYD's scale because a handful of very large tables (`journal_lines`, `ledger_entries`,
`stock_movements`) dominate the base backup size while changing only in their most recent partitions.

## 5. What is explicitly out of scope for "backup" and handled elsewhere

- **Redis** (cache/queue) is treated as disposable/rebuildable state for cache keys, and as
  at-least-once durable state for queue jobs via Redis AOF (`appendonly yes`, `appendfsync everysec`)
  snapshotted to S3 every 15 minutes by a sidecar `redis-cli --rdb` cron; a full Redis loss degrades
  performance and requires queue job replay from Laravel's `failed_jobs`/audit trail, it does not lose
  financial data because Redis never holds the ledger's source of truth.
- **Cloudflare R2** attachment objects (`attachments` table's binary payloads — receipts, ID scans,
  signed contracts) are versioned at the bucket level (R2 object versioning) and replicated to a second
  R2 bucket in a different Cloudflare jurisdiction via a nightly `rclone sync` job; R2 backup/versioning
  detail lives in the Attachments/Storage module doc and is referenced, not duplicated, here.
- **Application code, container images, and infrastructure-as-code** are backed up implicitly via Git
  (GitHub) and the container registry's own retention; not a database concern.

# Point In Time Recovery

## 1. Mechanics

PostgreSQL PITR works by replaying Write-Ahead Log (WAL) records forward from a physically consistent
base backup up to a chosen recovery target. QAYD runs with:

```conf
# postgresql.conf (relevant excerpt) — all cluster members
wal_level = replica
archive_mode = always
archive_command = 'pgbackrest --stanza=qayd-prod archive-push %p'
archive_timeout = 60s
max_wal_senders = 10
wal_keep_size = 4GB
wal_compression = zstd
full_page_writes = on
checksum = on          # enabled at initdb time with `initdb --data-checksums`
```

`archive_mode = always` (not just `on`) ensures WAL is archived from standbys as well, so promotion of
any standby does not create an archiving gap. `archive_timeout = 60s` bounds how long an idle period can
delay WAL shipping — without it, a quiet Sunday afternoon could sit inside one unshipped 16 MB segment,
which would directly inflate RPO. Combined with `archive_command`'s per-segment push, the *maximum* WAL
we can ever lose from the primary's local disk before it reaches repo1 is bounded by
`archive_timeout` plus pgBackRest's async spool flush interval (typically a few seconds under
`archive-async=y`).

## 2. Recovery target types supported

| Target | GUC / option | Use case |
|---|---|---|
| Timestamp | `recovery_target_time = '2026-07-16 09:14:00+03'` | "Restore to just before the incident at 09:15." |
| Transaction ID | `recovery_target_xid = '48213907'` | A specific bad transaction is known (from `audit_logs` or `pg_waldump`). |
| LSN | `recovery_target_lsn = '2C1/4A8F1E20'` | Precise byte-exact recovery, usually derived from `pg_waldump` after inspecting the WAL for the offending commit. |
| Named restore point | `recovery_target_name = 'pre_deploy_2026_07_16'` | Planned risky operations (large migration, bulk import) create a named point first — see below. |
| Immediate | `recovery_target = 'immediate'` | Fastest consistent state; used when we only need *a* consistent copy (e.g., seeding a reporting sandbox), not a specific moment. |

Before any migration classified as risky (adds a `NOT NULL` column with backfill, bulk `UPDATE`/`DELETE`
over `journal_lines`, any operation touching more than one fiscal year), the Laravel deploy pipeline
calls:

```sql
SELECT pg_create_restore_point('pre_deploy_2026_07_16_add_cost_center_index');
```

This costs nothing and gives every subsequent PITR operation a human-legible, unambiguous rollback
target that does not depend on reconstructing a timestamp from deploy logs under pressure.

## 3. Standard PITR recipe

```bash
# 1. Provision a clean target host/volume (never restore PITR onto a live primary in place).
#    qayd-pg-restore-01 is a fresh EC2 instance, same AMI/instance family as production, empty PGDATA.

# 2. Confirm the stanza and available backup set.
pgbackrest --stanza=qayd-prod info

# 3. Restore the base backup with a time target. --delta is irrelevant on an empty target but kept
#    for idempotency if the run is interrupted and re-launched.
pgbackrest --stanza=qayd-prod \
  --pg1-path=/var/lib/postgresql/15/main \
  --type=time \
  --target="2026-07-16 09:14:00+03" \
  --target-action=promote \
  --delta \
  restore

# 4. Start Postgres. It replays archived WAL from the restore point up to the target and stops there.
systemctl start postgresql@15-main

# 5. Confirm recovery completed at the intended point.
sudo -u postgres psql -c "SELECT pg_last_wal_replay_lsn(), pg_is_in_recovery();"
```

`--target-action=promote` means PostgreSQL automatically exits recovery mode and becomes a normal
read/write primary once it reaches the target — appropriate for a genuine disaster restore. For
*inspection* restores (see `# Restore Procedures`, tenant-scoped recovery), we instead use
`--target-action=pause`, which halts exactly at the target in read-only mode so an engineer can run
verification queries before deciding whether to promote, rewind further, or advance further with
`SELECT pg_wal_replay_resume();`.

## 4. Limits of PITR at QAYD

- PITR recovers **physical** state, not **logical** intent. If a bad migration corrupted data and then
  five minutes of *legitimate* new invoices were also posted before anyone noticed, a naive PITR to
  "before the bad migration" throws away those five minutes of real invoices too. This is why
  `# Restore Procedures` describes a **side-by-side extract-and-reconcile** pattern instead of blind
  in-place PITR whenever any real transaction occurred after the incident.
- PITR cannot undo a *logically* posted, correctly-executed reversing entry — nor should it try to.
  Per the platform's double-entry rule, correcting a posted `journal_entries` row is done by posting a
  reversing entry, never by editing history. PITR is a disaster-recovery and forensic tool, not a
  substitute for the application's own reversal workflow.
- The recovery window is bounded by WAL retention on the repository (`# Retention Policies`). Anything
  older than the oldest retained full backup plus its subsequent WAL cannot be PITR'd; it can only be
  retrieved from the monthly logical `pg_dump` compliance archive if one exists for that period.

# Continuous Backup

## 1. WAL shipping pipeline

```
 PostgreSQL primary (qayd-pg-primary-01)
      │  writes WAL to /var/lib/postgresql/15/main/pg_wal
      ▼
 archive_command = 'pgbackrest --stanza=qayd-prod archive-push %p'
      │  (async: fsync'd into /var/spool/pgbackrest, then uploaded by a background worker)
      ▼
 pgBackRest archive-push  ──────────────┬────────────────────────────┐
      │                                 │                            │
      ▼                                 ▼                            ▼
 S3 repo1 (me-south-1)            S3 repo2 (eu-central-1)     local WAL retained
 qayd-pgbackrest-prod-mes1/       qayd-pgbackrest-prod-euc1/  wal_keep_size=4GB
 archive/qayd-prod/15-1/          archive/qayd-prod/15-1/     (covers brief network
                                                                blips to standbys)
```

Every synchronous and asynchronous standby also streams WAL directly over the replication protocol
(`# High Availability`, `# Geo Replication`); `archive_command` is a **second, independent** path that
exists specifically so that WAL survives even if every standby is simultaneously unreachable. The two
paths are deliberately redundant — replication for low-latency HA, archiving for durability — and
neither substitutes for the other.

## 2. Configuration for bounded RPO

```conf
archive_timeout = 60s        # forces a segment switch at least once a minute even if idle
archive_mode = always
max_wal_size = 8GB
min_wal_size = 2GB
wal_writer_delay = 200ms
wal_writer_flush_after = 1MB
```

`archive_timeout=60s` is the single most important RPO-bounding setting outside of synchronous
replication itself: it is the ceiling on how long a low-traffic period can "hide" committed transactions
inside a WAL segment that has not yet been forced to disk/S3. QAYD accepts the modest storage overhead of
more frequent, sometimes partially-empty segment switches in exchange for a hard upper bound on
archiving-path data loss (see the RPO decomposition in `# RPO`).

## 3. Async spool and backpressure

`archive-async=y` lets `archive_command` return immediately after handing the segment to a local spool
(`/var/spool/pgbackrest`), decoupling WAL generation speed from S3 upload latency. A background
`archive-push --async` process drains the spool. Two failure modes are explicitly monitored
(`# Monitoring`):

- **Spool backlog growth** — if S3 upload is slower than WAL generation for a sustained period (e.g.
  during a bulk import or an AWS S3 regional slowdown), the spool directory grows. Alerting fires at
  `> 50` unshipped segments (≈ 800 MB); at `> 200` segments the primary is considered at risk of disk
  exhaustion and the on-call DRE is paged, not just notified.
- **Archive command failure loop** — PostgreSQL retries a failed `archive_command` indefinitely; a
  persistently failing push (bad credentials, bucket policy change, KMS key access revoked) will not
  crash the primary, but will silently widen RPO until noticed. `pgbackrest_exporter` exposes
  `pgbackrest_archive_files_missing` and `pgbackrest_archive_files_ready`, wired to a PagerDuty alert
  at any non-zero missing count sustained for more than 5 minutes.

## 4. Reporting replica: WAL-G continuous archiving

```bash
# /etc/wal-g.d/env on qayd-pg-report-01
export WALG_S3_PREFIX="s3://qayd-walg-prod-mes1/report-replica"
export AWS_REGION="me-south-1"
export WALG_COMPRESSION_METHOD="zstd"
export WALG_DELTA_MAX_STEPS="6"
export PGDATA="/var/lib/postgresql/15/main"
```

```conf
# postgresql.conf on the reporting replica
archive_mode = always
archive_command = 'wal-g wal-push %p'
archive_timeout = 300s   # looser RPO is acceptable for BI/reporting data
```

The reporting replica intentionally uses a longer `archive_timeout` (5 minutes vs. 60 seconds) because
it is fed from logical replication of already-durable OLTP data — losing a few minutes of the
*reporting* copy costs re-running a `report_runs` job, never losing a financial fact, since the OLTP
cluster remains the durable source of truth. This is the deliberate RPO/cost tradeoff described in
`# RPO`.

# Snapshots

## 1. Purpose relative to pgBackRest

Snapshots are the **fast path** for the most common recovery need — "this one node is broken, or this
one deploy went bad, get me back to a known-good state in minutes" — without invoking a full logical or
physical pgBackRest restore. They are volume-level, not PostgreSQL-aware, and are deliberately kept
independent of the pgBackRest toolchain so that a defect in pgBackRest (a bad stanza, a corrupted
repository) cannot also take out this recovery path.

## 2. AWS Backup plan

```json
{
  "BackupPlanName": "qayd-prod-ebs-daily",
  "Rules": [
    {
      "RuleName": "DailyAtOhTwoHundred",
      "TargetBackupVaultName": "qayd-prod-vault",
      "ScheduleExpression": "cron(0 23 * * ? *)",
      "StartWindowMinutes": 60,
      "CompletionWindowMinutes": 180,
      "Lifecycle": { "DeleteAfterDays": 35 },
      "CopyActions": [
        {
          "DestinationBackupVaultArn": "arn:aws:backup:eu-central-1:104729xxxxx:backup-vault:qayd-dr-vault",
          "Lifecycle": { "DeleteAfterDays": 35 }
        }
      ]
    }
  ],
  "BackupPlanTags": { "module": "database", "tier": "0" }
}
```

(`cron(0 23 * * ? *)` is UTC 23:00 = 02:00 Asia/Kuwait.) The vault `qayd-prod-vault` has **AWS Backup
Vault Lock** enabled in `compliance` mode with a 35-day minimum retention — even an account-root
credential cannot shorten retention or delete a locked recovery point before its lock expires. This is
the AWS-native complement to the S3 Object Lock protection on the pgBackRest repositories.

## 3. Application-consistent multi-volume snapshots

Each cluster node has two EBS volumes: `/data` (io2, holds `PGDATA` minus `pg_wal`) and `/pg_wal` (io2,
dedicated low-latency volume for WAL). Because a transaction can be "committed" from WAL's perspective
before its data pages are flushed, snapshotting the two volumes independently and non-atomically could
capture WAL ahead of data (recoverable) or data ahead of WAL (not recoverable). AWS Backup's
**multi-volume, crash-consistent snapshot** feature is used to snapshot both volumes as a single atomic
operation, and we additionally bracket it with PostgreSQL's own backup API for a belt-and-suspenders
application-consistent snapshot when snapshotting the primary specifically:

```sql
-- On the node being snapshotted (PostgreSQL 15 renamed the pre-15 pg_start_backup/pg_stop_backup):
SELECT pg_backup_start(label => 'aws-backup-2026-07-16', fast => true);
-- (AWS Backup snapshot API call happens here, orchestrated by a Lambda triggered from the backup job)
SELECT pg_backup_stop(wait_for_archive => true);
```

`pg_backup_stop(wait_for_archive => true)` blocks until the WAL segments spanning the snapshot window
are confirmed archived, which guarantees that any restore of this snapshot has everything needed to
reach a consistent state via `recovery_target = 'immediate'` even before pgBackRest's own backup runs.

## 4. Restoring from a snapshot

```bash
# Fast path: new EBS volumes from the snapshot pair, attached to a fresh/replacement instance.
aws ec2 create-volume --availability-zone me-south-1a \
  --snapshot-id snap-0abc123data --volume-type io2 --iops 16000 --tag-specifications \
  'ResourceType=volume,Tags=[{Key=Name,Value=qayd-pg-restored-data}]'

aws ec2 create-volume --availability-zone me-south-1a \
  --snapshot-id snap-0abc123wal --volume-type io2 --iops 8000 --tag-specifications \
  'ResourceType=volume,Tags=[{Key=Name,Value=qayd-pg-restored-wal}]'

# Attach, mount, then let Postgres perform normal crash recovery on start — it will replay any WAL
# present on the /pg_wal volume up to the snapshot point, then (optionally) continue via
# restore_command from the pgBackRest archive for further PITR beyond the snapshot instant.
systemctl start postgresql@15-main
```

Snapshots are most valuable for **speed** (minutes, not the tens of minutes a multi-terabyte pgBackRest
full restore can take) and for **isolating** hardware/AZ failures; pgBackRest remains the tool of record
for anything requiring cross-region DR, long retention, encryption-at-the-application-layer, or
point-in-time precision beyond "the moment of the last snapshot."

# Disaster Recovery

## 1. Scenario classification

| Scenario | Blast radius | Primary defense | Section |
|---|---|---|---|
| Single disk/volume failure | 1 node | EBS io2 durability (99.999%) + standby failover | `# High Availability` |
| Single node/instance failure | 1 node | Patroni automatic failover to sync standby | `# High Availability` |
| Single AZ failure | 1 AZ, up to 2 nodes if co-located | Patroni failover to standby in a surviving AZ | `# High Availability`, RB-DB-01 |
| Data corruption / bad migration / accidental `DELETE` | Logical, cluster-wide | PITR to sandbox + reconcile | `# Restore Procedures`, RB-DB-03 |
| Full region (`me-south-1`) outage | Entire primary region | Promote DR standby in `eu-central-1` | `# Geo Replication`, RB-DB-02 |
| Ransomware / credential compromise | Cluster-wide, potentially backups too | Immutable Object-Locked repo2 + isolated recovery | RB-DB-04 |
| Single-tenant accidental data loss | 1 `company_id` | Sandbox restore + filtered extract | RB-DB-05 |

## 2. DR architecture summary

QAYD's DR posture is **warm standby**, not cold/backup-only and not full active-active:

- A fully provisioned, continuously-updated PostgreSQL standby (`qayd-pg-dr-01`) runs in `eu-central-1`
  at all times, receiving WAL via asynchronous streaming replication plus independently via pgBackRest's
  repo2 WAL archive as a fallback path.
- Application compute (Laravel/FastAPI containers) is **not** kept running at full scale in
  `eu-central-1` day-to-day (cost tradeoff); it is defined as versioned container images + Terraform and
  can be scaled up in the DR region within the RTO budget (`# RTO`).
- DNS/traffic cutover uses Route 53 with health-checked failover records, not a manual DNS edit under
  pressure.

## 3. Failover decision authority

A **region failover is a business decision with an engineering trigger**, not a purely automated
action — promoting the DR standby means accepting the async-replication RPO gap (`# RPO`) as reality,
which is irreversible once new writes land in `eu-central-1`. Declaring a DR event requires:

1. The on-call DRE confirms `me-south-1` is unreachable/degraded beyond the auto-failover envelope
   (i.e., this is not a single-AZ event Patroni already handled).
2. The Incident Commander (rotating senior engineering role) explicitly authorizes DR activation in the
   incident channel, timestamped.
3. RB-DB-02 (`# Runbook`) is executed, with each step's completion timestamped in the incident log for
   the post-incident RPO/RTO accounting.

## 4. What "disaster recovery" does not cover

DR as specified here protects against infrastructure and regional failure. It explicitly does not
substitute for: (a) apply-time data validation (`DATABASE_CONSTRAINTS.md`), (b) the application's own
soft-delete/reversal safety net (`DATABASE_SOFT_DELETE.md`), or (c) tenant-level authorization
(`ROW_LEVEL_SECURITY.md`, `MULTI_TENANCY.md`) — a DR failover faithfully reproduces whatever state the
primary was in, including any un-caught application bug, up to the replication cutoff.

# Geo Replication

## 1. Topology

```
                         ┌─────────────────────────────┐
                         │   me-south-1 (primary region)│
                         │                               │
   App tier ───────────► │  qayd-pg-primary-01 (leader)  │
                         │        │  sync repl (quorum=1) │
                         │        ▼                       │
                         │  qayd-pg-sync-02 (HA standby)   │
                         │        │                        │
                         │        │ logical repl (report_*) │
                         │        ▼                        │
                         │  qayd-pg-report-01 (reporting)   │
                         └────────┬─────────────────────────┘
                                  │ async physical streaming
                                  │ + independent WAL archive (repo2)
                                  ▼
                         ┌─────────────────────────────┐
                         │  eu-central-1 (DR region)     │
                         │                                │
                         │  qayd-pg-dr-01 (warm standby)  │
                         └────────────────────────────────┘
```

## 2. Cross-region streaming replication

```conf
# postgresql.conf on qayd-pg-dr-01 (eu-central-1)
primary_conninfo = 'host=qayd-pg-primary.internal.qayd.io port=5432 user=replicator
                     sslmode=verify-full sslrootcert=/etc/postgresql/certs/ca.pem
                     application_name=qayd_dr_euc1'
primary_slot_name = 'dr_euc1_slot'
hot_standby = on
hot_standby_feedback = on
recovery_min_apply_delay = 0
```

```sql
-- On the primary, a dedicated physical replication slot prevents WAL from being recycled before the
-- DR region has consumed it, at the cost of disk growth if the DR link is down for a long time
-- (monitored — see # Monitoring, "slot retention risk").
SELECT pg_create_physical_replication_slot('dr_euc1_slot');
```

Cross-region replication runs over TLS 1.2+ (`sslmode=verify-full`, mutual certificate validation) across
a Site-to-Site path (AWS Transit Gateway peering between the two regions' VPCs), never over the public
internet in plaintext. `synchronous_commit` for the DR connection is deliberately **not** part of any
synchronous quorum — see `# High Availability` — because requiring a ~120–160 ms round trip
Bahrain↔Frankfurt to acknowledge every local commit would make QAYD's transaction latency hostage to
intercontinental network weather. This is the core RPO/latency tradeoff documented in `# RPO`.

## 3. Logical replication for the reporting fan-out

`report_definitions` / `report_runs` and ad hoc BI workloads read from `qayd-pg-report-01`, fed by
PostgreSQL logical replication (not the physical streaming used for HA/DR) so that its schema can carry
report-specific materialized/denormalized objects without living on the OLTP primary:

```sql
-- On the OLTP primary:
CREATE PUBLICATION qayd_reporting_pub FOR TABLE
  journal_lines, ledger_entries, invoices, invoice_items, bills, bill_items,
  customers, vendors, products, accounts, fiscal_periods;

-- On qayd-pg-report-01:
CREATE SUBSCRIPTION qayd_reporting_sub
  CONNECTION 'host=qayd-pg-primary.internal.qayd.io dbname=qayd user=replicator sslmode=verify-full'
  PUBLICATION qayd_reporting_pub
  WITH (copy_data = true, create_slot = true, slot_name = 'reporting_sub_slot');
```

Logical replication lag is monitored separately from physical replication lag (`# Monitoring`) because
it can stall independently (e.g., a schema change on the publisher without a matching subscriber-side
migration) without affecting HA/DR physical replication at all.

## 4. Replication lag monitoring query

```sql
-- Run from the primary; distinguishes physical (HA/DR) standbys from logical (reporting) subscribers.
SELECT application_name,
       client_addr,
       state,
       sync_state,
       pg_wal_lsn_diff(pg_current_wal_lsn(), sent_lsn)     AS send_lag_bytes,
       pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn)   AS replay_lag_bytes,
       write_lag, flush_lag, replay_lag
FROM pg_stat_replication
ORDER BY replay_lag_bytes DESC NULLS LAST;
```

Alert thresholds: `qayd-pg-sync-02` (in-region synchronous standby) paging at `replay_lag_bytes > 0`
sustained more than 30 seconds (it should essentially never lag meaningfully); `qayd-pg-dr-01`
(cross-region async) paging only at `replay_lag > 300s` (5 minutes), matching its documented DR RPO
budget.

# Restore Procedures

Every restore at QAYD follows one of four documented "shapes." All four write an entry into
`audit_logs` (`action = 'db.restore.*'`, actor = the executing engineer's `users.id`, reason = the
incident/ticket reference) before the restored data is exposed to any application traffic — a restore
is itself an auditable mutation event, consistent with the platform's universal audit rule.

## 1. Full cluster restore (disaster / total loss)

Used for RB-DB-02. Restores an entire fresh cluster from the latest full + diff + WAL, promotes it to
primary, and re-attaches standbys.

```bash
pgbackrest --stanza=qayd-prod --pg1-path=/var/lib/postgresql/15/main \
  --repo=1 --type=default --target-action=promote --delta restore
```

`--repo=1` pins the restore to the hot in-region repository; for a region-loss scenario this becomes
`--repo=2` executed against a host provisioned in `eu-central-1`.

## 2. Point-in-time restore to a sandbox (inspection before promotion)

Used for RB-DB-03 and any time the blast radius of "just roll back in place" is unclear. Restores to a
throwaway instance (`qayd-pg-sandbox-*`, no application traffic ever routed to it, isolated security
group with only DRE/engineering SSH access) and pauses recovery for inspection:

```bash
pgbackrest --stanza=qayd-prod --pg1-path=/var/lib/postgresql/15/main \
  --type=time --target="2026-07-16 09:14:00+03" --target-action=pause restore

sudo -u postgres psql -c "
  SELECT count(*) FROM journal_lines WHERE company_id = 4821;
  SELECT je.id, je.status, je.posted_at, sum(jl.debit) AS d, sum(jl.credit) AS c
  FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id = je.id
  WHERE je.company_id = 4821 AND je.posted_at > '2026-07-16 09:00:00+03'
  GROUP BY je.id, je.status, je.posted_at ORDER BY je.posted_at;
"
-- If more WAL needs replaying before deciding:
sudo -u postgres psql -c "SELECT pg_wal_replay_resume();"
```

## 3. Selective / single-tenant restore (RB-DB-05)

QAYD never restores a single company's rows by overwriting production tables in place — that would
risk clobbering other tenants' concurrent writes and violates the immutability rule for any posted rows
that happen to sit in the same physical table. Instead:

```bash
# 1. PITR-restore to a sandbox as in (2) above, promoted read-write once the target is confirmed.
# 2. Extract only the affected company's rows with pg_dump's row-level filtering via a view, or COPY:
sudo -u postgres psql -d qayd -c "
  COPY (SELECT * FROM journal_lines WHERE company_id = 4821
        AND id NOT IN (SELECT id FROM journal_lines_prod_snapshot_4821))
  TO '/tmp/recovered_journal_lines_4821.csv' WITH CSV HEADER;
"
# 3. Hand the extract to the Accounting module owner. Recovered *posted* rows are never re-inserted
#    as if they had always been there; they are re-created via the application's own posting API so a
#    fresh audit_logs entry and, where the row represents a previously-voided transaction, a proper
#    reversing/re-posting journal entry is generated — preserving the immutable-ledger guarantee.
# 4. Recovered *draft* or reference-data rows (a deleted `customers` row, a mis-edited `products` row)
#    may be re-inserted directly by the module owner via the normal API, since they carry no ledger
#    immutability constraint.
```

## 4. Table/schema-level restore for non-financial reference data

For a lower-stakes accidental change (e.g., a bad bulk edit to `price_lists`) that does not touch
posted financial tables, an engineer may restore to a sandbox and use `pg_dump`/`pg_restore` scoped to
just that table, then apply it as a normal, audited application-level `UPDATE`/`INSERT` rather than a
raw table swap:

```bash
pg_dump -h qayd-pg-sandbox-01 -U postgres -d qayd -t price_lists -t price_list_items \
  --format=custom --file=price_lists_recovered.dump
```

## 5. Restore verification checklist (mandatory before declaring any restore "done")

- [ ] `pg_is_in_recovery()` returns the expected value for the restore's intended end state.
- [ ] Row counts for every Tier 0 table match the pre-incident baseline ± the expected legitimate
      activity window (query in `# Examples`).
- [ ] `SUM(debit) = SUM(credit)` holds per `journal_entries.id` for all posted entries touched by the
      recovery window (double-entry invariant).
- [ ] `audit_logs` contains a contiguous timeline with no unexplained gap across the recovery boundary.
- [ ] Application smoke test (`# Recovery Testing`) passes against the restored instance before any
      DNS/traffic cutover.
- [ ] A restore record is written to `audit_logs` and the incident ticket, including target LSN/time,
      operator, and verification query outputs.

# Recovery Testing

## 1. Principle: an unrestored backup is a hypothesis, not a backup

Every backup artifact is periodically exercised end-to-end. "The `pgbackrest info` command shows a
successful backup" is necessary but never sufficient evidence of recoverability.

## 2. Automated nightly restore-verify job

```bash
#!/usr/bin/env bash
# /opt/qayd/dre/nightly-restore-verify.sh — runs on a dedicated, isolated verification account/VPC
set -euo pipefail

INSTANCE_ID=$(aws ec2 run-instances --launch-template LaunchTemplateName=qayd-restore-verify \
  --query 'Instances[0].InstanceId' --output text)
aws ec2 wait instance-status-ok --instance-ids "$INSTANCE_ID"

ssh "verify@${INSTANCE_ID}" "
  pgbackrest --stanza=qayd-prod --pg1-path=/var/lib/postgresql/15/main \
    --type=default --target-action=promote --delta restore &&
  systemctl start postgresql@15-main &&
  sleep 15 &&
  psql -d qayd -f /opt/qayd/dre/verify_queries.sql
"

RESULT=$?
aws ec2 terminate-instances --instance-ids "$INSTANCE_ID"

if [ $RESULT -ne 0 ]; then
  curl -X POST "$PAGERDUTY_EVENTS_URL" -d '{"routing_key":"'"$PD_KEY"'","event_action":"trigger",
    "payload":{"summary":"Nightly restore-verify FAILED for qayd-prod","severity":"critical",
    "source":"dre-nightly-restore-verify"}}'
fi
exit $RESULT
```

`verify_queries.sql` checks: expected table list exists, row counts within tolerance of a
previous-day baseline recorded in a small `dre.restore_baselines` tracking table, double-entry balance
invariant (`SUM(debit)=SUM(credit)` per posted entry), and that the most recent `audit_logs` timestamp
is within the expected staleness window for the backup's age. The entire job — spin-up, restore,
verify, teardown — is timed, and that duration is itself a tracked metric feeding the RTO drill history
(`# RTO`).

## 3. Quarterly DR game day

A scheduled, announced (to avoid false customer-facing incidents) full DR drill:

1. Simulate `me-south-1` unavailability by revoking the application tier's route to the primary
   (security group deny, not actually destroying anything in the primary region).
2. Execute RB-DB-02 in full against `eu-central-1`, including DNS cutover to a scoped drill hostname
   (never the production hostname during a drill).
3. Run the full application smoke-test suite (Laravel feature tests + a scripted "post an invoice,
   post a payment, run a trial balance" scenario) against the promoted DR cluster.
4. Record actual elapsed time per runbook step against the RTO budget; file a blameless postmortem for
   any step that overran its budget, with a corrective action tracked to closure before the next
   game day.
5. Fail back deliberately (do not leave the drill's DR promotion as the new production state) following
   the documented fail-back procedure, re-establishing `me-south-1` as primary and `eu-central-1` as
   standby.

## 4. Chaos testing for automatic failover

A monthly, unannounced-to-the-broader-team (but change-managed) chaos test terminates the current
Patroni leader (`pkill -9 postgres` or an EC2 instance stop) during business hours on a
lower-traffic day, and confirms:

- Patroni promotes a standby within its configured `ttl`/`loop_wait` bounds (target: leader change
  detected and a new primary accepting writes within 30 seconds).
- The application tier's connection routing (`# High Availability`) reconnects without manual
  intervention.
- No client observes a successful write acknowledged by the old leader that is absent from the new
  leader (checked via a canary write-then-read probe running throughout the test).

## 5. Metrics tracked per drill

| Metric | Target | Recorded from |
|---|---|---|
| Nightly restore-verify wall time | < 45 min | Job runner timestamp delta |
| Nightly restore-verify success rate (rolling 30 days) | 100% | PagerDuty/CI history |
| Quarterly DR game day actual RTO | ≤ documented RTO budget (`# RTO`) | Incident log timestamps |
| Chaos failover detection-to-writable time | < 30s | Patroni logs + canary probe |
| False-negative rate (drill flags a problem that turns out to be a test artifact) | Tracked, informational | Postmortem tagging |

# Backup Encryption

## 1. Encryption layers (defense in depth)

| Layer | Mechanism | Key owner |
|---|---|---|
| Data at rest on EBS volumes | EBS encryption-by-default (AES-256) | AWS KMS CMK `alias/qayd-ebs-prod` |
| pgBackRest repository contents | `repo-cipher-type=aes-256-cbc`, pgBackRest's own envelope | Passphrase stored in AWS Secrets Manager, itself encrypted under `alias/qayd-secrets-prod` |
| S3 object storage (backup buckets) | SSE-KMS | AWS KMS CMK `alias/qayd-pgbackrest-prod` |
| WAL-G repository (reporting replica) | `libsodium` client-side encryption | Key material in Secrets Manager, distinct from the pgBackRest passphrase |
| Data in transit — client to Postgres | TLS 1.2+, `sslmode=verify-full` | ACM-issued/rotated certs |
| Data in transit — replication (intra-region and cross-region) | TLS 1.2+, mutual cert validation | Internal CA (`qayd-internal-ca`), 1-year cert lifetime, automated renewal |
| Data in transit — pgBackRest to S3 | HTTPS/TLS 1.2+ (AWS SDK default) | AWS-managed |

## 2. Key hierarchy and envelope encryption

```
AWS KMS CMK  (alias/qayd-pgbackrest-prod, key rotation: annual, automatic)
      │
      ▼ generates & wraps
Data encryption key (DEK) — unique per S3 object, never persisted unwrapped
      │
      ▼ encrypts
pgBackRest backup/WAL files at rest in S3 (SSE-KMS)

Separately:

AWS Secrets Manager secret: qayd/prod/pgbackrest/repo-cipher-pass
      │  (itself encrypted under alias/qayd-secrets-prod, rotated every 90 days)
      ▼ fetched at service start by a small wrapper around `pgbackrest`
pgBackRest process env: PGBACKREST_REPO1_CIPHER_PASS / PGBACKREST_REPO2_CIPHER_PASS
      │
      ▼ used for
Application-layer AES-256-CBC encryption of the *logical content* of each backup file,
independent of and in addition to S3's own SSE-KMS
```

This gives two independent encryption boundaries: even a misconfigured S3 bucket policy that somehow
allowed unauthorized object retrieval would hand an attacker only pgBackRest's own AES-256-CBC
ciphertext, unusable without the Secrets-Manager-held passphrase, which is itself access-controlled by
a separate IAM policy from the one governing S3 object read.

## 3. Passphrase retrieval at process start

```bash
#!/usr/bin/env bash
# /usr/local/bin/pgbackrest-env-wrapper — invoked by systemd before any pgbackrest command
export PGBACKREST_REPO1_CIPHER_PASS=$(aws secretsmanager get-secret-value \
  --secret-id qayd/prod/pgbackrest/repo1-cipher-pass --query SecretString --output text \
  --region me-south-1)
export PGBACKREST_REPO2_CIPHER_PASS=$(aws secretsmanager get-secret-value \
  --secret-id qayd/prod/pgbackrest/repo2-cipher-pass --query SecretString --output text \
  --region eu-central-1)
exec "$@"
```

The passphrase is never written to disk, never baked into an AMI, and never checked into the Terraform
state or any config-management repository; it exists only as a process environment variable for the
lifetime of the `pgbackrest`/`postgres` process tree.

## 4. Key rotation procedure

1. **KMS CMK rotation** is AWS-automatic (annual) and requires no application change — KMS transparently
   re-wraps under the new key material version while old ciphertext remains decryptable.
2. **pgBackRest repo cipher passphrase rotation** (every 90 days, or immediately on suspected
   compromise) requires a **new stanza** — pgBackRest does not support in-place re-encryption of an
   existing repository with a new passphrase. Procedure: create `qayd-prod-v2` stanza with the new
   passphrase, take a fresh full backup under it, run both stanzas in parallel until the old stanza's
   retention window fully expires, then decommission the old stanza and passphrase.
3. **TLS certificate rotation** is automated via the internal CA's 30-day-before-expiry renewal job;
   manual rotation is only exercised during an incident response (RB-DB-04).

# Backup Validation

## 1. Validation is a distinct step from "backup succeeded" and from "restore-verify passed"

Three independent questions, three independent checks:

| Question | Check | Cadence |
|---|---|---|
| Did the backup command exit 0 and upload the expected files? | `pgbackrest info`, exit code monitoring | Every backup job |
| Is the backup's *content* structurally intact (no corrupt/missing blocks)? | `pgbackrest check` / `pgbackrest verify` | Daily |
| Does restoring the backup actually produce correct, queryable financial data? | Nightly restore-verify (`# Recovery Testing`) | Nightly |

## 2. `pgbackrest check`

```bash
# Validates archive_command / archive-push connectivity and stanza configuration without
# taking a backup — fast, cheap, run before every scheduled backup as a pre-flight.
pgbackrest --stanza=qayd-prod check
```

## 3. `pgbackrest verify`

```bash
# Validates every file in the repository against pgBackRest's own manifest checksums (SHA-256 per
# file) across both repo1 and repo2, and cross-checks WAL continuity (no missing segments between
# consecutive backups).
pgbackrest --stanza=qayd-prod --repo=1 verify
pgbackrest --stanza=qayd-prod --repo=2 verify
```

Sample healthy output (abbreviated):

```
INFO: verify command begin 2.53: --stanza=qayd-prod --repo=1
INFO: full backup 20260714-010000F: 100% verified, 0 error(s)
INFO: diff backup 20260715-010000F_20260715-010000D: 100% verified, 0 error(s)
INFO: incr backup 20260716-070000F_20260716-070000I: 100% verified, 0 error(s)
INFO: archive check: 100% valid, no gaps detected between 000000010000021A0000004F and 0000000100000221000000A3
INFO: verify command end: completed successfully
```

A `error(s) > 0` or a reported archive gap immediately pages the on-call DRE (`# Monitoring`) — a gap
found here means a restore across that boundary would silently fail or silently corrupt, and the
affected backup is marked unusable in the internal backup catalog until re-validated.

## 4. PostgreSQL page-level checksums

```conf
# set at cluster initialization; cannot be changed on a live cluster without a full re-initdb + reload
data_checksums = on   # initdb --data-checksums
```

Page checksums let PostgreSQL itself detect torn/corrupted pages on read (surfacing as a
`WARNING: page verification failed, calculated checksum ... but expected ...`), which is an
*independent* corruption detector from anything pgBackRest checks — pgBackRest validates that the
*backup* matches what was on disk when copied; page checksums validate that what was on disk was not
already silently corrupted by a hardware fault. `check_full_page_writes`-style validation is further
covered by weekly `pg_amcheck` runs against the restore-verify sandbox:

```bash
pg_amcheck --host=qayd-pg-sandbox-01 --all --heapallindexed --parent-check
```

## 5. Backup catalog

A lightweight internal table (`dre.backup_catalog`, living in a small operational Postgres instance
separate from the OLTP cluster it describes, to avoid a circular dependency) records every backup's id,
type, repo, size, duration, `pgbackrest verify` result, and nightly restore-verify result. A daily job
cross-references `pgbackrest info --output=json` against this catalog and pages if any expected backup
is missing, unexpectedly small, or unverified for more than 26 hours.

# Retention Policies

## 1. Retention matrix

| Data class | Hot repo (repo1, `me-south-1`) | DR repo (repo2, `eu-central-1`) | Cold archive | Rationale |
|---|---|---|---|---|
| OLTP full backups | 14 days | 90 days | Monthly full → Glacier Deep Archive, 10 years | Statutory accounting-record retention (Finance/Legal-confirmed; QAYD's engineering default assumes 10 years pending each customer jurisdiction's confirmed minimum, commonly cited in the 5–10 year range for commercial accounting records) |
| OLTP WAL (continuous) | 14 days beyond oldest full | 90 days beyond oldest full | Not archived independently (superseded by monthly logical export) | Bounds PITR window to the operationally realistic incident-discovery window |
| EBS/AWS Backup snapshots | 35 days | 35 days (cross-region copy) | N/A | Fast-path recovery only; not the long-term record |
| WAL-G (reporting replica) | 7 days | 30 days | N/A | Reporting data is reproducible from the OLTP source of truth |
| Monthly compliance `pg_dump` (per top-20 tenant + on request) | N/A (goes straight to cold) | N/A | 1 year rolling in S3 Glacier Instant Retrieval, then Glacier Deep Archive to the 10-year mark | Tenant-portable, restorable independent of the live cluster's schema version |
| `audit_logs` table itself | Lives in the OLTP backups above | Same | Additionally exported quarterly to an append-only Glacier Deep Archive object, independent of table backups | Audit trail must outlive any single backup chain and remain reconstructable even if the live `audit_logs` table were somehow truncated |

## 2. pgBackRest retention configuration

```ini
# excerpt from pgbackrest.conf, already shown in full in # Backup Strategy
repo1-retention-full=2
repo1-retention-full-type=count
repo1-retention-diff=14
repo2-retention-full=13
repo2-retention-full-type=count
repo2-retention-diff=90
```

`retention-full-type=count` (rather than `time`) is a deliberate choice: it guarantees at least N full
backups are always retained regardless of backup cadence drift (a missed Sunday full due to an
extended outage does not silently shrink the safety margin the way a naive time-based rule could).
Expiry is enforced automatically by `pgbackrest expire`, invoked at the end of every successful backup
job — retention is not a separate forgotten cron job.

## 3. S3 lifecycle policy (cold tier transition)

```json
{
  "Rules": [
    {
      "ID": "qayd-pgbackrest-cold-tier",
      "Filter": { "Prefix": "archive/" },
      "Status": "Enabled",
      "Transitions": [
        { "Days": 90, "StorageClass": "STANDARD_IA" },
        { "Days": 365, "StorageClass": "GLACIER" }
      ]
    },
    {
      "ID": "qayd-compliance-dumps-deep-archive",
      "Filter": { "Prefix": "compliance-dumps/" },
      "Status": "Enabled",
      "Transitions": [
        { "Days": 30, "StorageClass": "GLACIER_IR" },
        { "Days": 365, "StorageClass": "DEEP_ARCHIVE" }
      ],
      "Expiration": { "Days": 3650 }
    }
  ]
}
```

`archive/` (the pgBackRest repository prefix itself) is deliberately **not** given an `Expiration` rule
in the lifecycle policy — expiry of pgBackRest's own backup set is governed exclusively by
`pgbackrest expire`, which understands backup dependency chains (you cannot safely delete a full backup
that a still-retained differential depends on); a blind S3-lifecycle deletion could not make that
judgment and could silently break a restore chain.

## 4. Legal hold and litigation/audit exceptions

When Legal issues a hold notice referencing a specific `company_id` (litigation, regulator inquiry,
audit), the standard retention clock is suspended for any backup/archive object proven to contain that
tenant's data: the object is tagged `legal-hold=true` in S3 (which, combined with an object-level
bucket policy, blocks deletion regardless of lifecycle rules) until Legal issues an explicit release.
Because pgBackRest backups are not naturally tenant-partitioned files, a hold in practice is applied at
the *entire backup set* level for the affected time range, with a note in `dre.backup_catalog`
explaining which company/case justifies the extended retention — over-retaining is the safe default
when partitioning by tenant is not mechanically possible at the file level.

## 5. Right-to-erasure vs. immutable backups (multi-tenant tension)

QAYD's Object-Locked backups are, by design, impossible to selectively edit — this is a feature against
ransomware and a genuine constraint against ad hoc "delete this one customer's data everywhere
immediately" requests. The reconciliation:

1. **Live system**: an erasure/right-to-be-forgotten request is honored immediately in the live OLTP
   cluster via the standard soft-delete + (where legally required and contractually permitted)
   anonymization pipeline (`DATABASE_SOFT_DELETE.md`), which is what customers, auditors, and the
   application observe as "the data is gone."
2. **Backups already taken**: are **not** retroactively edited. They age out naturally per the retention
   matrix above. This is disclosed in QAYD's Data Processing Agreement: erasure is honored in the live
   system within the contractual SLA; historical immutable backups purge on their existing retention
   schedule (max 10 years for the cold compliance tier, 90 days for the DR operational tier).
3. Where a customer contract requires *faster* backup-level erasure than natural expiry allows, QAYD's
   fallback is **crypto-shredding at the tenant level for that customer's monthly compliance dump only**
   (each tenant's monthly `pg_dump` compliance export is encrypted with a per-tenant data key; destroying
   that specific key via KMS renders that specific archival dump permanently unreadable without touching
   the shared multi-tenant OLTP backup chain that other tenants' recoverability depends on).

# Monitoring

## 1. Metrics inventory

| Metric | Source | Alert condition | Severity |
|---|---|---|---|
| `pgbackrest_backup_last_success_timestamp` | `pgbackrest_exporter` | No successful backup of the expected type within 1.5× its cadence | Page |
| `pgbackrest_archive_files_missing` | `pgbackrest_exporter` | > 0 for 5 min | Page |
| `pgbackrest_repo_size_bytes` | `pgbackrest_exporter` | > 20% week-over-week growth unexplained by a full backup | Ticket |
| `pg_replication_lag_bytes{standby="qayd-pg-sync-02"}` | `postgres_exporter` | > 0 for 30s | Page |
| `pg_replication_lag_seconds{standby="qayd-pg-dr-01"}` | `postgres_exporter` | > 300s | Page |
| `pgbackrest_wal_archive_spool_files` | Custom exporter script | > 50 | Ticket; > 200 | Page |
| Nightly restore-verify exit code | CI/job runner → Pushgateway | != 0 | Page |
| `pgbackrest verify` error count | Daily job → Pushgateway | > 0 | Page |
| EBS snapshot job status (AWS Backup) | CloudWatch Events / EventBridge | `FAILED` or `EXPIRED` state | Ticket |
| S3 Object Lock / bucket policy drift | AWS Config rule `qayd-backup-bucket-compliance` | Any drift from the compliance baseline | Page |
| KMS key access denials on backup CMK | CloudTrail → CloudWatch metric filter | Any occurrence outside a scheduled rotation | Page (security) |
| `dre.backup_catalog` freshness | Daily reconciliation job | Any expected backup absent from catalog after 26h | Page |

## 2. Dashboards

A Grafana folder `QAYD / Database / Backup & DR` contains:

- **Backup Health** — last-success age per backup type/repo, repo size trend, `verify` pass/fail
  history (30-day heatmap).
- **Replication & Lag** — `pg_stat_replication` panels for every standby, logical subscription lag for
  the reporting replica, WAL archive spool depth over time.
- **DR Readiness** — DR standby lag trend, last successful chaos-test date, last game-day date and
  measured RTO vs. budget, days-since-last-nightly-restore-verify-failure counter.
- **Encryption & Compliance** — KMS key rotation status, TLS certificate expiry countdown, Object Lock
  configuration status per bucket, legal-hold count.

## 3. Alert routing

```yaml
# alertmanager.yml (excerpt)
route:
  receiver: dre-default
  routes:
    - matchers: ["severity=page"]
      receiver: pagerduty-dre-oncall
      group_wait: 0s
      repeat_interval: 15m
    - matchers: ["severity=ticket"]
      receiver: slack-qayd-db-alerts
receivers:
  - name: pagerduty-dre-oncall
    pagerduty_configs:
      - routing_key: "${PAGERDUTY_DRE_ROUTING_KEY}"
        severity: critical
  - name: slack-qayd-db-alerts
    slack_configs:
      - channel: "#qayd-db-alerts"
        send_resolved: true
```

## 4. SLO framing

Backup subsystem SLO: **99.9% of scheduled backup jobs (full/diff/incr combined) succeed and pass
`verify` on first attempt, measured monthly.** An error budget burn beyond 0.1% (roughly 1–2 job
failures per month at current cadence) triggers a mandatory retro even if every individual failure was
auto-remediated by a retry, because repeated near-misses are a leading indicator of an under-provisioned
or fragile backup path.

# RPO

## 1. Definition

Recovery Point Objective: the maximum acceptable amount of data (measured in time, and secondarily in
bytes of unshipped WAL) that QAYD may lose in a given failure scenario, measured from the moment of
the last durably-committed, recoverable transaction to the moment of failure.

## 2. Tiered targets

| Tier | Tables (examples) | Intra-region failure RPO | Full-region DR RPO |
|---|---|---|---|
| Tier 0 — posted ledger | `journal_entries` (posted), `journal_lines`, `ledger_entries`, `bank_transactions` (reconciled) | 0 (synchronous replication, `synchronous_commit=on`, quorum=1 standby) | ≤ 5 minutes (bounded by async replication lag + `archive_timeout=60s` fallback via repo2) |
| Tier 1 — operational financial | `customers`, `vendors`, `invoices`, `bills`, `inventory_items`, `stock_movements`, `payroll_runs`, `tax_transactions`, draft `journal_entries` | 0 (same synchronous path as Tier 0 — QAYD does not maintain a separate lower-durability write path for these tables) | ≤ 5 minutes |
| Tier 2 — derived/reporting | `report_runs`, materialized reporting-replica objects | ≤ 5 minutes (logical replication lag budget) | ≤ 1 hour (WAL-G cadence + reproducibility from Tier 0/1 source data) |
| Tier 3 — ancillary/cache | Redis cache keys, ephemeral session state | Best-effort, not a durability guarantee | N/A — rebuilt on demand |

Note that Tiers 0 and 1 share the same RPO in practice: QAYD does not offer a "faster but less durable"
write path for operational data, because a lost `invoices` row is exactly as much of an audit problem
as a lost `journal_lines` row once an invoice has triggered posting. The tiering exists to make explicit
*which* tables the zero-loss guarantee is contractually promised for (Tier 0) versus which tables happen
to receive the same technical protection as an engineering default (Tier 1) without being the subject of
the customer-facing guarantee.

## 3. How RPO is actually measured

```sql
-- "How much WAL exists that a synchronous standby has NOT yet confirmed flushed" —
-- this is as close to a live RPO gauge as SQL gets for the synchronous path.
SELECT application_name, pg_wal_lsn_diff(pg_current_wal_lsn(), flush_lsn) AS unconfirmed_bytes
FROM pg_stat_replication WHERE sync_state = 'sync';

-- For the async DR path, RPO is expressed as elapsed time, not bytes, because network variability
-- across regions makes a byte figure less actionable than a time figure for incident response:
SELECT application_name,
       extract(epoch FROM (now() - pg_last_xact_replay_timestamp())) AS replica_lag_seconds
FROM pg_stat_replication WHERE application_name = 'qayd_dr_euc1';
```

Post-incident, the *actual* RPO realized in a real event is computed as:
`(timestamp of last transaction visible on the promoted node) − (timestamp of last transaction
committed on the original primary before failure)`, cross-checked against `audit_logs` and
`journal_entries.posted_at` continuity, and recorded in the incident postmortem regardless of whether
it met the target.

## 4. Why synchronous replication is safe to require for Tier 0/1

`synchronous_commit=on` with `synchronous_standby_names='ANY 1 (qayd_sync_02, qayd_sync_03)'` means a
client's `COMMIT` does not return successfully until at least one in-region standby has confirmed the
WAL is flushed to its own disk — this bounds Tier 0/1 RPO to zero for any failure that doesn't destroy
*both* the primary and every synchronous standby simultaneously (a scenario mitigated by placing
synchronous standbys in different AZs within `me-south-1`, so an AZ-level event, the realistic ceiling
of "both nodes at once," is exactly the scenario this topology is built to survive).

# RTO

## 1. Definition

Recovery Time Objective: the maximum acceptable elapsed time from the moment a failure is detected (or
declared, for scenarios requiring human judgment) to the moment the system is back to serving correct
read/write traffic within its RPO guarantee.

## 2. Tiered targets

| Scenario | RTO target | Mechanism | Runbook |
|---|---|---|---|
| Single node/AZ failure (automatic) | ≤ 30 seconds detection + promotion, ≤ 2 minutes full traffic cutover | Patroni automatic failover + connection pooler re-routing | RB-DB-01 |
| Data corruption requiring PITR to sandbox + reconcile | ≤ 2 hours to verified sandbox state; total resolution time varies with reconciliation complexity | pgBackRest PITR + manual reconciliation | RB-DB-03 |
| Full region (`me-south-1`) disaster | ≤ 4 hours to DR cluster serving production traffic in `eu-central-1` | DR standby promotion + app-tier scale-up + DNS cutover | RB-DB-02 |
| Ransomware/compromise requiring clean-room restore | ≤ 8 hours (dominated by forensic isolation and credential rotation, not the restore itself) | Immutable Object-Locked repo restore into an isolated account | RB-DB-04 |
| Single-tenant data recovery | ≤ 4 hours to a verified extract ready for hand-off to the module owner | Sandbox PITR + filtered extract | RB-DB-05 |

## 3. RTO decomposition (full-region DR, the ≤ 4 hour budget)

| Phase | Budget | Notes |
|---|---|---|
| Detection | 5 min | CloudWatch/Route 53 health check failure + on-call paged |
| Decision (Incident Commander authorizes DR) | 10 min | Human-in-the-loop by design (`# Disaster Recovery`) |
| DR standby promotion | 10 min | `pg_ctl promote` equivalent via Patroni on `qayd-pg-dr-01`, or manual `pgbackrest restore --target-action=promote` from repo2 if the standby itself is unusable |
| App-tier scale-up in `eu-central-1` | 45 min | Terraform apply + container image pull + Laravel `php artisan config:cache` warm-up; pre-baked AMIs/images minimize this |
| DNS/traffic cutover | 5 min | Route 53 failover record propagation + client TTL expiry |
| Smoke test + verification (`# Restore Procedures` checklist) | 30 min | Mandatory gate before declaring "restored" |
| **Contingency buffer** | ~90 min | Explicitly budgeted, not assumed away — real incidents rarely follow the happy path |
| **Total** | **≤ 4 hours** | |

## 4. Historical drill results (rolling record, updated after every game day)

| Drill date | Scenario | Target RTO | Actual RTO | Notes |
|---|---|---|---|---|
| 2026-04-12 | Quarterly DR game day | 4h | 3h 41m | App-tier scale-up ran long due to a cold container registry cache; added a warm-image prefetch step |
| 2026-01-18 | Quarterly DR game day | 4h | 4h 22m | Missed target — Route 53 TTL was set too high (300s assumed, actually 3600s on a legacy record); corrected and re-tested |
| 2026-07 (upcoming) | Quarterly DR game day | 4h | — | Scheduled; will validate the TTL fix and image-prefetch corrective actions |

Consistently missing an RTO target on a drill is treated as a P1 engineering finding, not just a
process note — it means the number quoted to customers and auditors was not actually true, and the
document you are reading is corrected to reflect the *real*, drill-validated number rather than the
aspirational one until the gap is closed.

# High Availability

## 1. Cluster topology

```
                         me-south-1
        ┌──────────────────AZ-a──────────────────┐
        │   qayd-pg-primary-01  (leader)          │
        │   Patroni + etcd member                 │
        └───────────────┬──────────────────────────┘
                         │ sync replication (quorum=1 of 2)
        ┌────────────────┴────────────────┐
        │ AZ-b                            │ AZ-c
        │ qayd-pg-sync-02                 │ qayd-pg-sync-03
        │ Patroni + etcd member           │ Patroni + etcd member
        └──────────────────────────────────┘
                         │
                etcd cluster (3 nodes, 1 per AZ) — leader election + config store
                         │
              PgBouncer / HAProxy (per-AZ, behind an internal NLB)
                         │
                    Laravel app tier
```

## 2. Patroni configuration (excerpt)

```yaml
# /etc/patroni/patroni.yml on qayd-pg-primary-01
scope: qayd-prod
namespace: /qayd/
name: qayd-pg-primary-01

etcd3:
  hosts: etcd-a.internal.qayd.io:2379,etcd-b.internal.qayd.io:2379,etcd-c.internal.qayd.io:2379

bootstrap:
  dcs:
    ttl: 30
    loop_wait: 10
    retry_timeout: 10
    maximum_lag_on_failover: 1048576   # 1 MB — a standby lagging beyond this is not failover-eligible
    synchronous_mode: true
    synchronous_mode_strict: false     # do not block writes entirely if the sole sync standby is down
    postgresql:
      parameters:
        synchronous_standby_names: 'ANY 1 (qayd_sync_02, qayd_sync_03)'
        wal_level: replica
        hot_standby: "on"

postgresql:
  listen: 0.0.0.0:5432
  data_dir: /var/lib/postgresql/15/main
  authentication:
    replication: { username: replicator, password: "{{ from Secrets Manager }}" }
  parameters:
    archive_mode: always
    archive_command: 'pgbackrest --stanza=qayd-prod archive-push %p'

tags:
  nofailover: false
  noloadbalance: false
  clonefrom: false
```

`synchronous_mode_strict: false` is a deliberate availability/durability tradeoff: if the sole
synchronous standby becomes unreachable, Patroni allows the primary to continue accepting writes
(falling back to effectively asynchronous durability for a brief window) rather than halting all writes
cluster-wide — QAYD judges "briefly reduced durability with continued availability" as the better
default than "a single standby blip takes down write availability for the whole platform," while still
paging immediately (`# Monitoring`) so the window is short and someone is actively restoring the
redundant standby.

## 3. Automatic failover flow

1. `etcd` heartbeat/lease from the leader lapses (leader crash, network partition, or explicit `stop`).
2. Within `ttl=30s` / `loop_wait=10s`, the healthiest eligible standby (lowest replication lag, not
   tagged `nofailover`) acquires the leader lock in `etcd`.
3. Patroni promotes that standby (`pg_ctl promote` equivalent), updates its own DCS key, and the old
   primary — if it comes back — is fenced: Patroni will demote/reconfigure it as a standby rather than
   allow a split-brain dual-primary state, using the DCS leader lock as the single source of truth for
   "who is allowed to accept writes."
4. PgBouncer/HAProxy health checks detect the new leader via Patroni's REST API (`GET /primary` returns
   200 only from the current leader) and repoint traffic within one health-check interval (5s).
5. The freshly-demoted former primary rejoins as a standby once it can reconcile its WAL position with
   the new leader; if it diverged beyond what WAL replay can reconcile, it is re-seeded via
   `pg_rewind` or a fresh `pgbackrest restore` rather than manually reasoned about under pressure.

## 4. Planned maintenance: switchover vs. failover

A **switchover** (planned, e.g. before an OS patch on the current leader) uses
`patronictl switchover --master qayd-pg-primary-01 --candidate qayd-pg-sync-02` — this drains
in-flight transactions and performs a clean handoff with zero forced-abort connections, distinct from
an unplanned **failover**, which accepts that in-flight, unacknowledged transactions on the old leader
are lost per the RPO already budgeted for that failure class.

## 5. Connection routing

```ini
# pgbouncer.ini (excerpt, on each AZ's pooler)
[databases]
qayd = host=127.0.0.1 port=5433 dbname=qayd
; port 5433 is a local HAProxy listener that itself health-checks Patroni's REST API per (3) above,
; so PgBouncer never needs Patroni-awareness of its own — it just always talks to "whoever is primary
; right now" on localhost.

[pgbouncer]
pool_mode = transaction
max_client_conn = 4000
default_pool_size = 50
server_reset_query = DISCARD ALL
```

```
# haproxy.cfg (excerpt)
listen postgres_primary
    bind *:5433
    option httpchk GET /primary
    http-check expect status 200
    server primary1 qayd-pg-primary-01:5432 check port 8008
    server primary2 qayd-pg-sync-02:5432 check port 8008 backup
    server primary3 qayd-pg-sync-03:5432 check port 8008 backup
```

# Cloud Storage

## 1. Bucket inventory

| Bucket | Region | Purpose | Encryption | Object Lock | Lifecycle |
|---|---|---|---|---|---|
| `qayd-pgbackrest-prod-mes1` | me-south-1 | pgBackRest repo1 (hot) | SSE-KMS (`alias/qayd-pgbackrest-prod`) | Governance mode, 14-day min | Standard → IA at 90d |
| `qayd-pgbackrest-prod-euc1` | eu-central-1 | pgBackRest repo2 (DR) | SSE-KMS | **Compliance mode**, 90-day min | Standard → IA 90d → Glacier 365d |
| `qayd-walg-prod-mes1` | me-south-1 | WAL-G repo (reporting replica) | SSE-KMS (separate CMK) | Governance mode, 7-day min | Standard → IA 30d |
| `qayd-compliance-dumps` | me-south-1, replicated to eu-central-1 | Monthly per-tenant logical `pg_dump` exports | SSE-KMS, per-tenant DEK (crypto-shred capable) | Compliance mode, 1-year min | Glacier IR 30d → Deep Archive 365d, 10y expiry |
| `qayd-dre-artifacts` | me-south-1 | Restore-verify logs, `pg_amcheck` reports, drill records | SSE-S3 | None | 1 year |

## 2. IAM least-privilege for the backup role

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "PgBackRestRepoAccess",
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:GetObject", "s3:ListBucket", "s3:GetObjectVersion"],
      "Resource": [
        "arn:aws:s3:::qayd-pgbackrest-prod-mes1",
        "arn:aws:s3:::qayd-pgbackrest-prod-mes1/*"
      ]
    },
    {
      "Sid": "DenyDeleteEverAtIamLevel",
      "Effect": "Deny",
      "Action": ["s3:DeleteObject", "s3:DeleteObjectVersion", "s3:PutBucketObjectLockConfiguration"],
      "Resource": "arn:aws:s3:::qayd-pgbackrest-prod-mes1/*"
    },
    {
      "Sid": "KmsForBackupCmkOnly",
      "Effect": "Allow",
      "Action": ["kms:Decrypt", "kms:GenerateDataKey"],
      "Resource": "arn:aws:kms:me-south-1:104729xxxxx:key/qayd-pgbackrest-prod-key-id"
    }
  ]
}
```

The service credential that *writes* backups cannot delete them even in principle — `DeleteObject` is
explicitly denied at the IAM level, on top of and independent from the S3 Object Lock protection, so
that a compromised backup-writer credential is a confidentiality risk to mitigate via encryption, never
a "the attacker can also wipe our recovery path" risk. A separate, break-glass, MFA-gated role
(`qayd-dre-repository-admin`) is the only principal that can manage lifecycle/lock configuration, and
its use is itself alarmed (`# Monitoring`).

## 3. Cross-region replication

S3 Cross-Region Replication (CRR) is **not** used for the pgBackRest repositories — pgBackRest's own
multi-repo (`repo1`/`repo2`) feature already produces two independently-written, independently-encrypted
copies at backup time, which is more auditable than a downstream S3-level async copy of one canonical
write. CRR **is** used for `qayd-compliance-dumps` (a simple, tool-agnostic file that benefits from
S3-native replication) and for the Cloudflare R2 attachment store's cross-jurisdiction copy (handled at
the R2 layer, referenced in the Attachments/Storage module doc).

## 4. VPC endpoints

All backup traffic to S3 traverses a **Gateway VPC Endpoint** for S3 within each region, not the public
internet — this removes NAT Gateway data-processing cost for multi-terabyte backup traffic and ensures
backup upload/download never depends on internet egress availability, which matters specifically during
an incident where the app tier's normal internet path might itself be degraded.

# Runbook

## RB-DB-01 — Single node / AZ failure (should self-heal; this is the verification + fallback path)

1. Confirm via Grafana "DR Readiness" dashboard and `patronictl list` that a new leader was elected and
   is accepting writes.
2. Confirm application error rates returned to baseline within the 2-minute cutover budget.
3. If Patroni did **not** auto-promote within 60 seconds (etcd quorum lost, e.g. 2-of-3 etcd nodes down):
   ```bash
   # Manual promotion fallback, only if patronictl itself is unusable due to lost DCS quorum:
   sudo -u postgres pg_ctl promote -D /var/lib/postgresql/15/main
   ```
   then manually repoint HAProxy's active target before restoring etcd quorum and re-registering Patroni.
4. File a ticket to replace/re-seed the failed node; do not leave the cluster running at reduced
   redundancy (2 nodes instead of 3) longer than one business day.

## RB-DB-02 — Full region disaster (`me-south-1` unreachable)

1. On-call DRE confirms region-wide impact (not a single-AZ event) via AWS Health Dashboard + internal
   multi-AZ health checks all failing simultaneously.
2. Incident Commander authorizes DR activation in the incident channel (timestamped — starts the RTO
   clock for the postmortem).
3. Promote the DR standby:
   ```bash
   ssh qayd-pg-dr-01
   patronictl -c /etc/patroni/patroni.yml switchover --master qayd-pg-primary-01 --candidate qayd-pg-dr-01 --force
   # If the primary region's Patroni/etcd is itself unreachable (the expected case in a real regional
   # outage), skip patronictl entirely and promote directly:
   pg_ctl promote -D /var/lib/postgresql/15/main
   ```
4. Scale up the application tier in `eu-central-1`:
   ```bash
   terraform -chdir=infra/eu-central-1 apply -var="app_tier_capacity=production" -auto-approve
   ```
5. Update Route 53 failover record to point at the `eu-central-1` load balancer (normally automatic via
   health check failure; verify manually if not):
   ```bash
   aws route53 change-resource-record-sets --hosted-zone-id Z0XXXXX --change-batch file://dr-cutover.json
   ```
6. Run the restore verification checklist (`# Restore Procedures`, section 5) against the promoted
   cluster before declaring the incident's data plane "restored."
7. Run the full smoke-test suite; only then remove any "degraded service" banner from the status page.
8. Post-incident: compute actual RPO/RTO, file the postmortem, and schedule the deliberate fail-back to
   `me-south-1` once it is confirmed healthy (fail-back is its own planned, low-urgency switchover, not
   a rushed mirror of the emergency failover).

## RB-DB-03 — Data corruption / accidental bulk mutation

1. Identify the approximate incident time and affected table(s) from `audit_logs` and application error
   reports.
2. Restore to an isolated sandbox with `--target-action=pause` at a time just before the incident
   (`# Point In Time Recovery`, `# Restore Procedures`).
3. Run the verification queries; if legitimate transactions occurred after the incident, use the
   selective-restore extract-and-reconcile pattern rather than promoting the sandbox in place of
   production.
4. Hand the reconciled extract to the owning module team for re-application through the application's
   own API (never a raw table overwrite of production).
5. Tear down the sandbox once the extract is confirmed received; log the full incident timeline in
   `audit_logs` and the incident ticket.

## RB-DB-04 — Ransomware / credential compromise

1. **Isolate immediately**: revoke/rotate all database and AWS credentials with any plausible exposure;
   security-group-isolate affected hosts (do not power them off yet if forensic preservation is required
   — coordinate with Security).
2. **Assume the live cluster and any credential-reachable backups are untrusted.** Recovery uses only
   the Object-Locked repo2 (`qayd-pgbackrest-prod-euc1`, Compliance mode) — its lock guarantees no
   attacker with even root/admin credentials could have altered or deleted backups inside the retention
   window.
3. Provision a **brand-new AWS account or tightly firewalled clean VPC**, restore from repo2 into it:
   ```bash
   pgbackrest --stanza=qayd-prod --repo=2 --pg1-path=/var/lib/postgresql/15/main \
     --type=time --target="<last known-clean timestamp>" --target-action=pause --delta restore
   ```
4. Verify integrity thoroughly (`# Backup Validation`, `# Restore Procedures` checklist) before
   promoting; run `pg_amcheck` and a full application smoke test against the clean-room copy.
5. Rotate every secret referenced anywhere in this document (repo cipher passphrases, replication
   passwords, TLS certs, IAM keys) before reconnecting the restored cluster to production networking.
6. Preserve forensic images of the compromised hosts for Security's investigation before any decommission.
7. This runbook explicitly has the longest RTO budget (`# RTO`) because correctness and forensic
   integrity dominate speed in this scenario — a fast but incompletely-clean recovery is a worse outcome
   than a slower, verified one.

## RB-DB-05 — Single-tenant accidental data loss (customer request)

1. Confirm scope with the customer/support ticket: exact `company_id`, approximate time window, affected
   tables.
2. Restore to sandbox at a time just before the reported loss (`--target-action=pause`).
3. Extract only that `company_id`'s affected rows (`# Restore Procedures`, section 3).
4. Hand off to the module owner for re-insertion via the application API, generating fresh, correctly
   audited records rather than resurrecting raw rows with stale metadata.
5. Confirm with the customer and close the ticket; tear down the sandbox.

# Examples

## Example 1 — Healthy `pgbackrest info` output

```
$ pgbackrest --stanza=qayd-prod info
stanza: qayd-prod
    status: ok
    cipher: aes-256-cbc

    db (current)
        wal archive min/max (15): 000000010000021A0000004F/0000000100000229000000C1

        full backup: 20260714-010000F
            timestamp start/stop: 2026-07-14 01:00:03 / 2026-07-14 01:47:21
            wal start/stop: 000000010000021A0000004F / 000000010000021A0000006A
            database size: 2.1TB, database backup size: 2.1TB
            repo1: backup size: 612.4GB
            repo2: backup size: 612.1GB

        diff backup: 20260715-010000F_20260715-010000D
            timestamp start/stop: 2026-07-15 01:00:02 / 2026-07-15 01:11:47
            database size: 2.1TB, database backup size: 41.2GB
            repo1: backup size: 9.8GB

        incr backup: 20260716-070000F_20260716-070000I
            timestamp start/stop: 2026-07-16 07:00:01 / 2026-07-16 07:03:12
            database size: 2.1TB, database backup size: 6.7GB
            repo1: backup size: 1.9GB
```

## Example 2 — PITR to a named restore point, end to end

```bash
$ sudo -u postgres psql -c "SELECT pg_create_restore_point('pre_deploy_2026_07_16_add_cost_center_index');"
 pg_create_restore_point
--------------------------
 21A/6C001F88
(1 row)

$ pgbackrest --stanza=qayd-prod --pg1-path=/var/lib/postgresql/15/main \
    --type=name --target=pre_deploy_2026_07_16_add_cost_center_index \
    --target-action=pause --delta restore
INFO: restore command begin 2.53
INFO: restore size = 2.1TB, file total = 184213
INFO: restore global/pg_control (performed last to ensure consistency)
INFO: restore command end: completed successfully

$ systemctl start postgresql@15-main
$ sudo -u postgres psql -c "SELECT pg_is_in_recovery(), pg_last_wal_replay_lsn();"
 pg_is_in_recovery | pg_last_wal_replay_lsn
--------------------+-------------------------
 t                  | 21A/6C001F88
(1 row)
```

## Example 3 — Double-entry integrity check after any restore

```sql
-- Every posted journal entry must balance to the cent. Any row returned here is a restore-blocking
-- finding, not a warning.
SELECT je.id, je.company_id, je.status,
       sum(jl.debit) AS total_debit, sum(jl.credit) AS total_credit,
       sum(jl.debit) - sum(jl.credit) AS imbalance
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
WHERE je.status = 'posted'
GROUP BY je.id, je.company_id, je.status
HAVING sum(jl.debit) <> sum(jl.credit);
-- Expected result on a healthy restore: 0 rows.
```

## Example 4 — WAL-G backup listing for the reporting replica

```bash
$ wal-g backup-list --detail
name                          modified                  wal_segment_backup_start  data_dir
base_000000010000031A0000002B 2026-07-16T03:00:11+03:00 000000010000031A0000002B  /var/lib/postgresql/15/main
base_0000000100000318000000A9 2026-07-15T03:00:08+03:00 0000000100000318000000A9  /var/lib/postgresql/15/main
```

## Example 5 — Audit trail entry generated by a restore action

```json
{
  "id": "9c1e2b3a-88f1-4b7a-9e2d-1a2b3c4d5e6f",
  "company_id": null,
  "action": "db.restore.pitr_sandbox",
  "actor_user_id": 4021,
  "actor_name": "M. Al-Rashid (DRE on-call)",
  "old_value": null,
  "new_value": {
    "stanza": "qayd-prod",
    "repo": 1,
    "target_type": "time",
    "target": "2026-07-16T09:14:00+03:00",
    "target_action": "pause",
    "resulting_lsn": "21A/6C001F88",
    "verification": "passed",
    "ticket": "INC-4821"
  },
  "reason": "Investigate suspected bad migration affecting company_id=4821 journal_lines",
  "ip_address": "10.20.4.15",
  "device": "qayd-dre-jumpbox-01",
  "created_at": "2026-07-16T09:41:07+03:00"
}
```

## Example 6 — S3 Object Lock verification for the DR repository (ransomware defense proof)

```bash
$ aws s3api get-object-lock-configuration --bucket qayd-pgbackrest-prod-euc1
{
  "ObjectLockConfiguration": {
    "ObjectLockEnabled": "Enabled",
    "Rule": {
      "DefaultRetention": {
        "Mode": "COMPLIANCE",
        "Days": 90
      }
    }
  }
}

$ aws s3api delete-object --bucket qayd-pgbackrest-prod-euc1 --key archive/qayd-prod/15-1/000000010000021A0000004F
An error occurred (AccessDenied) when calling the DeleteObject operation: Object is WORM protected and
locked in COMPLIANCE mode until 2026-10-14T01:00:00Z. Even the bucket owner cannot delete or overwrite
this object before that date.
```

## Example 7 — Nightly restore-verify summary (posted to `#qayd-db-alerts` on success)

```json
{
  "job": "nightly-restore-verify",
  "date": "2026-07-16",
  "instance": "qayd-restore-verify-20260716",
  "backup_used": "20260716-070000F_20260716-070000I",
  "restore_duration_seconds": 1847,
  "verification_queries_passed": 14,
  "verification_queries_failed": 0,
  "row_count_deltas_within_tolerance": true,
  "double_entry_balance_check": "passed",
  "result": "PASS",
  "total_job_duration_seconds": 2210
}
```

# End of Document
