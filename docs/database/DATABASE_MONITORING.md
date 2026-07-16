# Database Monitoring — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: DATABASE_MONITORING
---

# Purpose

QAYD is an AI Financial Operating System. Its PostgreSQL database is the single source of financial
truth for every tenant company on the platform: the chart of accounts, journal entries, ledger
projections, sales and purchase documents, inventory valuations, payroll runs, and tax filings all
live in this one cluster (or cluster family, once sharded by region). A monitoring outage is not a
cosmetic problem — it is the difference between catching a replication lag event before it corrupts a
trial balance and discovering three days later that the CFO dashboard for a company has been serving
stale numbers.

This document specifies the complete observability stack for the QAYD PostgreSQL layer: what is
measured, how it is measured, where the thresholds are drawn, how alerts are routed, what the on-call
engineer does when a page fires, and how capacity is planned so that pages fire rarely. The stack is
built on four components, used together and never substituted:

- **Prometheus** — the metrics time-series database and alerting rule engine. Scrapes exporters on a
  15s interval in production, evaluates alerting rules, and forwards firing alerts to Alertmanager.
- **postgres_exporter** (`prometheus-community/postgres_exporter`) — translates PostgreSQL system
  catalogs and statistics views into Prometheus metrics. Runs as a sidecar next to every Postgres
  instance (primary and each standby), scraped by Prometheus over `/metrics`.
- **Grafana** — the dashboarding and visualization layer. Every dashboard referenced in this document
  is provisioned as JSON under version control (`infra/grafana/dashboards/*.json`), never edited by
  hand in the UI, so dashboards survive cluster rebuilds and code review catches broken panels before
  they reach production.
- **pg_stat_statements** — the PostgreSQL extension that aggregates per-normalized-query execution
  statistics (calls, total/mean/max time, rows, shared-block hits and reads, temp-block usage, WAL
  bytes generated) directly inside Postgres. postgres_exporter's `pg_stat_statements` custom query
  block re-exports this data as Prometheus metrics; the raw view is also queried ad hoc during
  incident response.

Scope of this document: cluster-level and instance-level health (CPU, memory, storage, connections,
locks, query performance, replication, backups), the Prometheus alerting rules that watch each of
those signals, the Grafana dashboards operators use to triage, the incident-response runbooks tied to
each alert, and the capacity-planning process that keeps the cluster ahead of tenant growth. This
document does NOT cover application-level business metrics (invoice volume, AI agent throughput) —
those belong to the Reports and AI Layer module docs — nor does it cover Redis or R2 monitoring, which
have their own submodules. `DATABASE_BACKUP_RECOVERY.md` owns the backup and disaster-recovery
*mechanism* (pgBackRest topology, WAL archiving, PITR, S3 retention); this document owns *observing*
that mechanism — the metrics, alerts, and dashboards that prove backups and replication are healthy —
and defers to it for the underlying procedures. `DATABASE_PERFORMANCE.md` owns query-tuning technique
(indexing, plan optimization, caching); this document owns detecting when tuning is needed and paging
a human when a resource envelope is breached. Every SQL statement, PromQL expression, and alerting
rule below is meant to be copied into the `infra/` repository as-is; nothing here is illustrative
pseudocode.

## Cluster topology this document monitors

All metrics, alerts, and dashboards in this document assume the production topology defined in
`DATABASE_BACKUP_RECOVERY.md`. It is restated here because every scrape target, alert label, and
runbook step below names these hosts directly:

| Node | Region | Role | Replication Mode | Monitored For |
|---|---|---|---|---|
| `qayd-pg-primary` | eu-central-1 | Primary | — | Everything in this document |
| `qayd-pg-standby-a` | eu-central-1 | Sync standby | Streaming, `synchronous_commit=remote_apply` | Replication lag (tight), failover readiness |
| `qayd-pg-standby-read` | eu-central-1 | Async standby (read replica) | Streaming, async, `hot_standby_feedback=on` | Replication lag (loose), read-offload saturation |
| `qayd-pg-standby-dr` | me-south-1 | Async standby (DR) | Streaming, async | Replication lag (DR-budgeted), DR readiness |
| `qayd-pg-repo` | eu-central-1 | pgBackRest repository host | — | Backup job success, repository growth |

`qayd-pg-repo` runs no PostgreSQL instance and is therefore never a `postgres_exporter` target — it is
monitored as a plain Linux host (`node_exporter`) plus the pgBackRest-specific metrics described in
`# Backups`.

# Health Monitoring

## Monitoring topology

Each PostgreSQL host (primary and every streaming standby) runs `postgres_exporter` as a sidecar
container listening on `:9187`. Prometheus, running as a dedicated monitoring-VPC service in
`eu-central-1`, scrapes every exporter every 15 seconds and retains raw samples for 15 days locally; a
`remote_write` job ships all series to a long-term store (Thanos/Mimir-compatible) with a 2-year
retention policy so that year-over-year capacity trends and audit-relevant historical queries remain
answerable. Grafana reads from both the local Prometheus (fast, recent) and the long-term store (slow,
historical) via a single data source using Prometheus's built-in federation.

Because `qayd-pg-standby-dr` and its region (`me-south-1`) exist specifically so QAYD survives a total
loss of `eu-central-1` (see `DATABASE_BACKUP_RECOVERY.md`, `# Disaster Recovery`), the primary
monitoring stack is not the only thing watching it: a second, minimal **DR-region shadow Prometheus**
runs in `me-south-1`, scraping only `qayd-pg-standby-dr`'s `postgres_exporter` and `node_exporter`,
with its own independent Alertmanager routing straight to PagerDuty. Its retention is short (48
hours — it exists purely as an operational instrument, not a historical store) and its only job is to
answer one question when `eu-central-1` is unreachable: "is the DR standby alive, and how far behind
was it the moment we lost the primary region?" Without this, an operator's first act during a genuine
regional outage would be flying blind on the exact system they most need visibility into.

```yaml
# infra/prometheus/prometheus.yml (relevant excerpt — eu-central-1 primary Prometheus)
global:
  scrape_interval: 15s
  evaluation_interval: 15s
  external_labels:
    cluster: qayd-prod
    prometheus_region: eu-central-1

scrape_configs:
  - job_name: postgres_exporter
    scrape_interval: 15s
    scrape_timeout: 10s
    static_configs:
      - targets:
          - qayd-pg-primary.internal:9187
        labels:
          service: postgresql
          role: primary
          db_region: eu-central-1
      - targets:
          - qayd-pg-standby-a.internal:9187
        labels:
          service: postgresql
          role: standby_sync
          db_region: eu-central-1
      - targets:
          - qayd-pg-standby-read.internal:9187
        labels:
          service: postgresql
          role: standby_read
          db_region: eu-central-1

  - job_name: postgres_exporter_dr_federated
    # Cross-region scrape of the DR standby's own exporter, kept for continuity/history in the
    # long-term store; NOT the system relied upon to detect a regional outage — see the DR-region
    # shadow Prometheus, above, for that.
    scrape_interval: 30s
    static_configs:
      - targets:
          - qayd-pg-standby-dr.internal:9187
        labels:
          service: postgresql
          role: standby_dr
          db_region: me-south-1

  - job_name: postgres_exporter_pgbouncer
    static_configs:
      - targets:
          - qayd-pg-primary.internal:9127
        labels:
          service: pgbouncer

  - job_name: node_exporter
    static_configs:
      - targets:
          - qayd-pg-primary.internal:9100
          - qayd-pg-standby-a.internal:9100
          - qayd-pg-standby-read.internal:9100
          - qayd-pg-standby-dr.internal:9100
          - qayd-pg-repo.internal:9100
        labels:
          service: node

rule_files:
  - /etc/prometheus/rules/postgres_*.yml

alerting:
  alertmanagers:
    - static_configs:
        - targets: ['alertmanager.qayd.internal:9093']
```

## The four-signal model

Every monitored subsystem in this document is scored against the same four signals, borrowed from the
Google SRE "four golden signals" but adapted to a stateful database tier:

1. **Saturation** — how full is the resource (connection slots, disk, memory, WAL disk)? Saturation
   metrics are leading indicators; they predict outages before they happen.
2. **Latency** — how long do operations take (query duration, lock wait, replication apply lag,
   checkpoint duration)? Latency metrics are the first thing users notice.
3. **Errors** — what is failing outright (deadlocks, failed connections, replication stream breaks,
   failed backups)? Error metrics are unambiguous and page immediately.
4. **Traffic** — what is the current load shape (queries per second, transactions per second, rows
   returned/fetched)? Traffic metrics provide context for the other three — a saturation spike matched
   by a traffic spike is expected load; a saturation spike with flat traffic is a leak.

Every alert rule defined later in this document is tagged in its annotations with which of these four
signals it represents, so the on-call dashboard can be filtered by signal type during a broad incident.

## postgres_exporter deployment and custom queries

The stock `postgres_exporter` binary exports a baseline set of metrics from `pg_stat_database`,
`pg_stat_bgwriter`, and `pg_stat_activity` out of the box. QAYD extends this with a custom queries
file that adds tenant-aware and accounting-aware metrics — because "the database is healthy" is not
the same claim as "Company 4711's ledger is being written correctly," and both must be observable.

```yaml
# infra/postgres_exporter/queries.yaml
pg_stat_statements_totals:
  query: |
    SELECT
      (SELECT sum(total_exec_time) FROM pg_stat_statements) AS total_exec_time_ms,
      (SELECT sum(calls) FROM pg_stat_statements) AS total_calls,
      (SELECT count(*) FROM pg_stat_statements WHERE mean_exec_time > 500) AS slow_query_count
  metrics:
    - total_exec_time_ms:
        usage: "COUNTER"
        description: "Cumulative execution time across all normalized queries, ms"
    - total_calls:
        usage: "COUNTER"
        description: "Cumulative call count across all normalized queries"
    - slow_query_count:
        usage: "GAUGE"
        description: "Distinct normalized queries with mean_exec_time over 500ms"

pg_table_bloat:
  query: |
    SELECT
      schemaname, relname,
      pg_total_relation_size(relid) AS total_bytes,
      n_dead_tup, n_live_tup,
      CASE WHEN n_live_tup > 0
        THEN round(100.0 * n_dead_tup / n_live_tup, 2)
        ELSE 0 END AS dead_ratio_pct
    FROM pg_stat_user_tables
  metrics:
    - schemaname:
        usage: "LABEL"
    - relname:
        usage: "LABEL"
    - total_bytes:
        usage: "GAUGE"
    - n_dead_tup:
        usage: "GAUGE"
    - n_live_tup:
        usage: "GAUGE"
    - dead_ratio_pct:
        usage: "GAUGE"

pg_tenant_row_counts:
  query: |
    SELECT company_id::text AS company_id,
           count(*) AS journal_line_count
    FROM journal_lines
    WHERE created_at > now() - interval '1 day'
    GROUP BY company_id
    ORDER BY journal_line_count DESC
    LIMIT 50
  metrics:
    - company_id:
        usage: "LABEL"
    - journal_line_count:
        usage: "GAUGE"
        description: "Journal lines written in the last 24h, top 50 tenants by volume"

pg_replication_status:
  query: |
    SELECT application_name,
           EXTRACT(EPOCH FROM replay_lag)::float AS replay_lag_seconds,
           pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn) AS replay_lag_bytes,
           sync_state
    FROM pg_stat_replication
  metrics:
    - application_name:
        usage: "LABEL"
    - replay_lag_seconds:
        usage: "GAUGE"
        description: "Primary-side view of replay lag per standby, seconds"
    - replay_lag_bytes:
        usage: "GAUGE"
        description: "Primary-side view of replay lag per standby, bytes (LSN distance)"
    - sync_state:
        usage: "LABEL"
```

`pg_tenant_row_counts` exists specifically because of QAYD's multi-tenant model: a single noisy or
runaway tenant (a buggy integration hammering the API, or an AI agent stuck in a retry loop) can
consume disproportionate database capacity while aggregate cluster metrics still look "green." Per-
tenant write-volume metrics let the on-call engineer distinguish "the database is unhealthy" from "one
company is unhealthy and dragging shared resources down with it," which lead to very different
remediations (the former is a database incident; the latter is rate-limiting a specific `company_id`).
`pg_replication_status` is the custom-query counterpart to the `pg_stat_replication`-derived metrics
detailed in `# Replication` — it exists because the stock `postgres_exporter` build does not label
per-standby lag by `application_name`, which this document's per-role alert thresholds require.

## Overall health score

Grafana's top-level "Postgres Fleet Overview" dashboard computes a single 0–100 health score per
instance as a weighted composite, used purely as an at-a-glance triage signal (never as an alerting
input on its own — every alert rule fires on the underlying raw metric, not the composite):

```
health_score = 100
  - 25 * (connections_used / connections_max > 0.85 ? 1 : (connections_used/connections_max - 0.5)/0.35)
  - 25 * (replication_lag_seconds > 30 ? 1 : replication_lag_seconds / 30)
  - 25 * (disk_used_pct > 0.85 ? 1 : (disk_used_pct - 0.6) / 0.25)
  - 25 * (blocked_queries_count > 5 ? 1 : blocked_queries_count / 5)
```

# Metrics

## Metric taxonomy

QAYD's Postgres metrics fall into six namespaces, each with a fixed Prometheus metric-name prefix so
that dashboards and alert rules can select by prefix without enumerating every metric:

| Prefix | Source | Examples |
|---|---|---|
| `pg_stat_database_*` | `pg_stat_database` | `pg_stat_database_xact_commit`, `pg_stat_database_deadlocks` |
| `pg_stat_bgwriter_*` | `pg_stat_bgwriter` | `pg_stat_bgwriter_checkpoints_timed`, `pg_stat_bgwriter_buffers_backend` |
| `pg_stat_activity_*` | `pg_stat_activity` | `pg_stat_activity_count` (by state) |
| `pg_locks_*` | `pg_locks` join `pg_stat_activity` | `pg_locks_count` (by mode/granted) |
| `pg_stat_statements_*` | `pg_stat_statements` (custom query) | `total_exec_time_ms`, `slow_query_count` |
| `pg_replication_*` | `pg_stat_replication`, `pg_stat_wal_receiver` (custom query) | `replay_lag_seconds`, `replay_lag_bytes` |
| `node_*` | `node_exporter` (OS-level, co-scraped) | `node_cpu_seconds_total`, `node_memory_MemAvailable_bytes` |
| `pgbouncer_*` | PgBouncer admin console (`SHOW STATS`/`SHOW POOLS`) | `pgbouncer_pools_client_waiting_count` |

## Core metrics reference

The following table is the canonical list of metrics every dashboard and alert in this document reads
from. Each is exact-named as it appears in `postgres_exporter`'s default and custom-query output.

| Metric | Type | Labels | Meaning |
|---|---|---|---|
| `pg_up` | gauge | `instance` | 1 if the exporter can query Postgres, 0 otherwise |
| `pg_stat_database_numbackends` | gauge | `datname` | current backend connections per database |
| `pg_stat_database_xact_commit` | counter | `datname` | committed transactions |
| `pg_stat_database_xact_rollback` | counter | `datname` | rolled-back transactions |
| `pg_stat_database_blks_hit` | counter | `datname` | buffer-cache hits |
| `pg_stat_database_blks_read` | counter | `datname` | disk block reads |
| `pg_stat_database_tup_returned` | counter | `datname` | rows returned by scans |
| `pg_stat_database_tup_fetched` | counter | `datname` | rows fetched by index/bitmap scans |
| `pg_stat_database_deadlocks` | counter | `datname` | deadlocks detected |
| `pg_stat_database_conflicts` | counter | `datname` | recovery conflicts (standby only) |
| `pg_stat_database_temp_files` | counter | `datname` | temp files created for sorts/hashes |
| `pg_stat_database_temp_bytes` | counter | `datname` | bytes written to temp files |
| `pg_stat_bgwriter_checkpoints_timed` | counter | — | scheduled checkpoints |
| `pg_stat_bgwriter_checkpoints_req` | counter | — | requested (forced) checkpoints |
| `pg_stat_bgwriter_buffers_checkpoint` | counter | — | buffers written during checkpoints |
| `pg_stat_bgwriter_buffers_backend` | counter | — | buffers backends wrote themselves (bypassing bgwriter) |
| `pg_settings_max_connections` | gauge | — | configured `max_connections` |
| `pg_stat_activity_count` | gauge | `state`, `datname` | backends by state (`active`,`idle`,`idle in transaction`,…) |
| `pg_locks_count` | gauge | `mode`, `datname` | held/waiting locks by mode |
| `replay_lag_seconds` | gauge | `application_name`, `sync_state` | primary's view of replay lag per standby (custom query) |
| `replay_lag_bytes` | gauge | `application_name`, `sync_state` | primary's view of replay LSN distance per standby (custom query) |
| `pg_stat_wal_receiver_last_msg_receipt_time` | gauge | — | standby-side: last WAL message receipt (staleness proxy) |
| `pg_replication_slots_wal_status` | gauge | `slot_name`, `slot_type` | health of a replication slot's retained WAL |
| `total_exec_time_ms` / `total_calls` / `slow_query_count` | counter/gauge | — | custom pg_stat_statements rollups (see above) |
| `dead_ratio_pct` | gauge | `schemaname`,`relname` | table bloat proxy |
| `node_filesystem_avail_bytes` | gauge | `mountpoint` | free disk space |
| `node_memory_MemAvailable_bytes` | gauge | — | OS-estimated available memory |
| `node_cpu_seconds_total` | counter | `mode` | CPU time by mode (idle/user/system/iowait) |
| `pgbouncer_pools_client_waiting_count` | gauge | `database` | clients waiting for a server connection |

## Derived rates (recording rules)

Raw counters are never graphed directly; Prometheus recording rules precompute rates so that every
dashboard panel and alert rule reads a cheap, pre-aggregated series instead of recomputing `rate()`
over raw counters on every query. Recording rules live in `infra/prometheus/rules/postgres_recording.yml`
and are evaluated every 30 seconds:

```yaml
groups:
  - name: postgres_recording_rules
    interval: 30s
    rules:
      - record: qayd:pg_tps:rate5m
        expr: sum(rate(pg_stat_database_xact_commit[5m]) + rate(pg_stat_database_xact_rollback[5m])) by (instance)

      - record: qayd:pg_cache_hit_ratio:rate5m
        expr: >
          sum(rate(pg_stat_database_blks_hit[5m])) by (instance)
          /
          (sum(rate(pg_stat_database_blks_hit[5m])) by (instance) + sum(rate(pg_stat_database_blks_read[5m])) by (instance))

      - record: qayd:pg_rollback_ratio:rate5m
        expr: >
          sum(rate(pg_stat_database_xact_rollback[5m])) by (instance)
          /
          sum(rate(pg_stat_database_xact_commit[5m]) + rate(pg_stat_database_xact_rollback[5m])) by (instance)

      - record: qayd:pg_connections_used_pct
        expr: sum(pg_stat_activity_count) by (instance) / on(instance) pg_settings_max_connections * 100

      - record: qayd:pg_disk_used_pct
        expr: 1 - (node_filesystem_avail_bytes{mountpoint="/var/lib/postgresql/data"} / node_filesystem_size_bytes{mountpoint="/var/lib/postgresql/data"})

      - record: qayd:pg_deadlocks:rate1h
        expr: sum(rate(pg_stat_database_deadlocks[1h])) by (instance) * 3600

      - record: qayd:pg_wal_generation_bytes:rate5m
        expr: sum(rate(pg_stat_wal_receiver_last_msg_receipt_time[5m])) by (instance) * 0
          # placeholder replaced below by the WAL-bytes recording rule sourced from pg_stat_wal, PG14+
      - record: qayd:pg_wal_bytes:rate5m
        expr: rate(pg_wal_bytes_total[5m])
```

`qayd:pg_cache_hit_ratio:rate5m` is the single most-watched derived metric on the fleet dashboard: a
healthy OLTP workload on QAYD's hardware profile (see `# Capacity Planning`) sustains a buffer-cache
hit ratio above 0.99; anything sustained below 0.95 means `shared_buffers` is undersized relative to
the working set, and it is the earliest, cheapest-to-fix warning sign before CPU and I/O saturation
alerts start firing downstream. `qayd:pg_deadlocks:rate1h` and `qayd:pg_wal_bytes:rate5m` exist to
back the Locks and Storage alert rules against the exact resource-envelope thresholds published in
`DATABASE_PERFORMANCE.md`'s Performance Targets table (deadlocks per hour, WAL generation rate), so
that the two documents' alerting and capacity-planning numbers stay in lockstep rather than drifting
apart as each is edited independently over time.

# CPU

## What is measured

CPU is monitored at two layers that must be read together: the OS layer (`node_exporter`, one process
per host, scraped alongside `postgres_exporter`) and the Postgres layer (which backends are consuming
CPU, via `pg_stat_activity` joined to OS process stats). OS-level CPU saturation without a
corresponding Postgres-level explanation is itself a signal — it usually means a neighboring process
(backup agent, log shipper, a runaway `autovacuum` worker) is competing with the database for cycles.

```promql
# Overall CPU utilization (1 - idle), percentage, per host
100 * (1 - avg by (instance) (rate(node_cpu_seconds_total{mode="idle"}[5m])))

# CPU time in iowait — high iowait with normal CPU util means the bottleneck is storage, not compute
100 * avg by (instance) (rate(node_cpu_seconds_total{mode="iowait"}[5m]))

# Per-core CPU utilization (for spotting single-core pegging, e.g. a runaway single-threaded query)
100 * (1 - (rate(node_cpu_seconds_total{mode="idle"}[5m])))

# Load average relative to core count — sustained >1.0 means queued work, not just busy work
node_load1 / count without (cpu, mode) (node_cpu_seconds_total{mode="idle"})
```

## Postgres-side CPU attribution

When OS CPU is high, the next question is always "which backend, and which query." QAYD ships a
cron-scheduled snapshot job (every 10s, `pg_cron` job `cpu_snapshot`) that samples `pg_stat_activity`
joined against `/proc/<pid>/stat` CPU-tick deltas and writes the top 20 consumers to a rolling
`pg_cpu_snapshots` diagnostics table (7-day retention, itself excluded from tenant backup/restore
scope since it is operational, not financial, data):

```sql
-- infra/postgres/diagnostics/cpu_snapshot.sql — run by pg_cron every 10s
INSERT INTO ops.pg_cpu_snapshots (captured_at, pid, usename, datname, company_id, query, state, cpu_pct)
SELECT
  now(),
  a.pid,
  a.usename,
  a.datname,
  (regexp_match(a.query, 'company_id\s*=\s*(\d+)'))[1]::bigint AS company_id,
  left(a.query, 500),
  a.state,
  round(100.0 * (p.cpu_ticks_delta / 10.0) / 100, 2) AS cpu_pct  -- 10s window, 100 ticks/sec assumed
FROM pg_stat_activity a
JOIN ops.proc_cpu_ticks() p ON p.pid = a.pid
WHERE a.state != 'idle'
ORDER BY cpu_pct DESC
LIMIT 20;
```

The `company_id` extraction regex is deliberately best-effort: QAYD enforces `company_id` scoping at
the query-builder level (every Laravel repository injects a `WHERE company_id = ?` bound parameter),
so nearly every OLTP query contains a literal or bound `company_id` predicate visible in
`pg_stat_activity.query` when `track_activity_query_size` is raised (see below) and statements are
logged with parameters. This lets an operator answer "is one tenant burning all the CPU" in one query
against `ops.pg_cpu_snapshots` instead of reading raw `EXPLAIN ANALYZE` output under incident pressure.

## Configuration that affects CPU-visibility

```
# postgresql.conf — CPU-visibility relevant settings
track_activity_query_size = 4096      # default 1024 truncates most journal-posting queries
track_io_timing = on                  # enables I/O timing in EXPLAIN and pg_stat_statements
track_functions = pl                  # tracks PL/pgSQL function CPU time (QAYD posts via functions)
```

## CPU alert thresholds (summary; full rules in Alerts)

| Condition | Warning | Critical | Rationale |
|---|---|---|---|
| Sustained CPU utilization | > 75% for 10m | > 90% for 5m | mirrors `DATABASE_PERFORMANCE.md`'s 60/85% envelope with duration-gating added to suppress transient spikes; above 90% queueing becomes visible in query latency within seconds |
| iowait share of total CPU | > 15% for 10m | > 30% for 5m | indicates storage, not compute, is the bottleneck — different runbook |
| Load average / core count | > 1.5 for 10m | > 3.0 for 5m | queued (not just running) work; predicts latency spikes |
| Single normalized query's CPU share | > 40% for 15m | > 70% for 5m | one query monopolizing the instance — candidate for `pg_terminate_backend` |

# Memory

## What is measured

PostgreSQL memory has three logical layers that are monitored separately because they fail
differently: the OS page cache (fails silently, shows up as rising `blks_read`), `shared_buffers`
(Postgres's own buffer pool, fails as falling cache-hit ratio), and per-backend working memory
(`work_mem`, `maintenance_work_mem`, fails loudly as temp-file spilling or OOM-killed backends).

```promql
# OS memory available as a percentage of total — the single most important OS memory signal
100 * node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes

# Swap usage — Postgres hosts should never swap; any nonzero sustained swap is an incident
node_memory_SwapTotal_bytes - node_memory_SwapFree_bytes

# Postgres buffer cache hit ratio (recording rule from Metrics section)
qayd:pg_cache_hit_ratio:rate5m

# Temp file bytes written per second — proxy for work_mem being too small for sort/hash workloads
sum(rate(pg_stat_database_temp_bytes[5m])) by (instance, datname)

# Backend count in each memory-relevant state — 'idle in transaction' backends each hold a snapshot
# and, worse, can hold locks indefinitely; they are a memory AND correctness risk, not just memory
pg_stat_activity_count{state="idle in transaction"}
```

## Sizing model

QAYD's standard primary instance sizing formula, re-derived at every capacity review (see
`# Capacity Planning`) and enforced by Terraform variable defaults in `infra/terraform/postgres.tf`:

```
shared_buffers          = 25% of instance RAM         (never above 16 GB per Postgres upstream guidance
                                                          past which OS page cache double-buffering
                                                          diminishes returns; use larger effective_cache_size
                                                          instead)
effective_cache_size    = 65-75% of instance RAM       (planner hint, not an allocation)
work_mem                = (25% of RAM) / max_connections / 2   (halved to leave room for parallel workers,
                                                                   each of which can allocate its own work_mem)
maintenance_work_mem    = 5% of RAM, capped at 2 GB    (used by VACUUM, CREATE INDEX, ALTER TABLE)
max_connections         = derived from PgBouncer pool size, NOT client count directly (see Connections)
```

```sql
-- Runtime memory configuration check — run in any incident where memory pressure is suspected
SELECT name, setting, unit, source
FROM pg_settings
WHERE name IN ('shared_buffers','effective_cache_size','work_mem','maintenance_work_mem',
               'max_connections','huge_pages')
ORDER BY name;
```

## Detecting per-query memory abuse

```sql
-- Top normalized queries by temp-file usage — the queries most likely to be undersized on work_mem
-- or performing an unindexed sort/hash that should instead use an index
SELECT
  query,
  calls,
  temp_blks_written,
  round((temp_blks_written * 8192) / 1024.0 / 1024.0, 1) AS temp_mb_total,
  round((temp_blks_written * 8192) / 1024.0 / 1024.0 / greatest(calls,1), 3) AS temp_mb_per_call
FROM pg_stat_statements
WHERE temp_blks_written > 0
ORDER BY temp_blks_written DESC
LIMIT 20;
```

## Huge pages and THP

QAYD production hosts disable Linux Transparent Huge Pages (THP) entirely and instead configure
static huge pages sized to `shared_buffers`, because THP's background compaction (`khugepaged`)
produces unpredictable multi-second CPU stalls under memory pressure — exactly the failure mode a
financial OLTP system cannot tolerate during period-close processing:

```bash
# /etc/systemd/system/postgresql.service.d/hugepages.conf equivalent, applied via cloud-init
echo never > /sys/kernel/mm/transparent_hugepage/enabled
echo never > /sys/kernel/mm/transparent_hugepage/defrag
sysctl -w vm.nr_hugepages=8192   # sized to cover shared_buffers with headroom, recalculated per host
```

## Memory alert thresholds (summary; full rules in Alerts)

| Condition | Warning | Critical | Rationale |
|---|---|---|---|
| OS memory available | < 20% for 10m | < 10% for 5m | approaching OOM-killer territory |
| Swap in use | any nonzero for 5m | > 100 MB for 2m | Postgres should never swap; this precedes severe latency |
| Buffer cache hit ratio | < 0.97 for 15m | < 0.90 for 5m | matches `DATABASE_PERFORMANCE.md`'s yellow/red boundary (97%) for Warning; Critical is set materially past Performance's red band because a Grafana-visible page at that depth means tuning has already failed, not just drifted |
| Temp bytes written rate | > 50 MB/s for 10m | > 200 MB/s for 5m | undersized work_mem or missing index forcing disk sorts |
| `idle in transaction` count | > 10 for 10m | > 30 for 2m | connection/transaction leak, holds snapshots and locks |

# Storage

## What is measured

Storage is the slowest-moving and least forgiving of the resource classes covered in this document —
disk fills over hours or days, but the failure mode when it fills (Postgres refuses writes, including
WAL, and can require single-user-mode recovery) is the most severe outage class in this document.
Storage monitoring therefore emphasizes *rate of change* as much as absolute level.

```promql
# Disk space used, percentage, data volume
qayd:pg_disk_used_pct * 100

# Disk space used, percentage, WAL volume (a SEPARATE volume from data in QAYD's layout)
1 - (node_filesystem_avail_bytes{mountpoint="/var/lib/postgresql/wal"} / node_filesystem_size_bytes{mountpoint="/var/lib/postgresql/wal"})

# Projected time to full, based on the last 6 hours' fill rate — the single most actionable storage metric
node_filesystem_avail_bytes{mountpoint="/var/lib/postgresql/data"}
  / clamp_min(-deriv(node_filesystem_avail_bytes{mountpoint="/var/lib/postgresql/data"}[6h]), 1)

# WAL retention risk — bytes of WAL held back by a lagging replication slot
pg_replication_slots_wal_status{slot_type="physical"}

# Sustained WAL generation rate — matched against DATABASE_PERFORMANCE.md's 40/80 MB/s envelope
qayd:pg_wal_bytes:rate5m
```

## Database-internal storage attribution

OS-level disk metrics tell you the volume is filling; they never tell you *why*. The following queries
are the standard first three run in any storage incident, in this order:

```sql
-- 1. Largest tables and indexes, with bloat-adjusted "recoverable" estimate
SELECT
  schemaname, relname,
  pg_size_pretty(pg_total_relation_size(relid)) AS total_size,
  pg_size_pretty(pg_relation_size(relid)) AS table_size,
  pg_size_pretty(pg_total_relation_size(relid) - pg_relation_size(relid)) AS index_size,
  n_live_tup, n_dead_tup,
  round(100.0 * n_dead_tup / greatest(n_live_tup + n_dead_tup, 1), 1) AS dead_pct
FROM pg_stat_user_tables
ORDER BY pg_total_relation_size(relid) DESC
LIMIT 25;

-- 2. Is unbounded WAL growth caused by a stuck replication slot or a stalled archiver?
SELECT slot_name, slot_type, active, restart_lsn,
       pg_size_pretty(pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn)) AS retained_wal
FROM pg_replication_slots
ORDER BY pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn) DESC;

-- 3. Is a single tenant's data or attachment metadata disproportionately large?
SELECT company_id, count(*) AS row_count,
       pg_size_pretty(sum(pg_column_size(journal_lines.*))::bigint) AS approx_bytes
FROM journal_lines
GROUP BY company_id
ORDER BY approx_bytes DESC
LIMIT 20;
```

## Autovacuum and bloat as a storage-growth driver

Bloat (dead tuples not yet reclaimed) is the most common *avoidable* storage-growth driver on QAYD's
append-heavy accounting tables (`journal_lines`, `ledger_entries`, `audit_logs`). Because posted
journal lines are immutable and only ever inserted (never updated), bloat there is driven almost
entirely by the `deleted_at` soft-delete pattern on *other* tables (draft invoices, canceled sales
orders) rather than by journal data itself — which makes per-table bloat monitoring, not just
aggregate disk usage, essential to distinguishing "normal accounting growth" from "a leak."

```sql
-- Tables with autovacuum falling behind — the standard early-warning bloat query
SELECT relname,
       last_autovacuum, last_autoanalyze,
       autovacuum_count, n_dead_tup, n_live_tup,
       round(100.0 * n_dead_tup / greatest(n_live_tup, 1), 2) AS dead_ratio_pct
FROM pg_stat_user_tables
WHERE n_dead_tup > 10000
ORDER BY dead_ratio_pct DESC
LIMIT 25;
```

```
# postgresql.conf — autovacuum tuning for high-insert, low-update accounting tables
autovacuum_vacuum_scale_factor = 0.05      # default 0.2 is far too loose for large tenant tables
autovacuum_vacuum_cost_limit   = 2000       # default 200 makes vacuum too slow to keep up on big tables
autovacuum_max_workers         = 6          # scaled to core count; default 3 undersized for QAYD's table count
```

Per-table overrides are applied via `ALTER TABLE ... SET (autovacuum_vacuum_scale_factor = 0.02)` on
the highest-churn tables (`stock_movements`, `ai_messages`, `notifications`), which see far more
update/delete churn than the immutable accounting core and would otherwise starve for autovacuum
attention behind larger, slower-growing tables when workers are scheduled by naive size ordering.

## Storage alert thresholds (summary; full rules in Alerts)

| Condition | Warning | Critical | Rationale |
|---|---|---|---|
| Data volume used | > 75% for 15m | > 90% for 5m | Postgres refuses writes at 100%; recovery from full disk is manual |
| WAL volume used | > 70% for 10m | > 85% for 5m | WAL exhaustion halts the primary immediately, not gracefully |
| Projected time to full | < 7 days | < 24 hours | gives capacity/incident response a concrete SLA to react against |
| Replication slot retained WAL | > 20 GB for 30m | > 50 GB for 10m | a stuck slot silently drives WAL disk usage on the primary |
| Table dead-tuple ratio | > 20% for 1h (large tables only) | > 40% for 1h | autovacuum falling behind, storage and query-plan risk |
| Sustained WAL generation rate | > 40 MB/s for 15m | > 80 MB/s for 5m | reuses `DATABASE_PERFORMANCE.md`'s resource-envelope boundary verbatim |

# Connections

## Architecture: PgBouncer in front of Postgres

QAYD's Laravel application servers never connect to Postgres directly. Every connection routes
through PgBouncer in `transaction` pooling mode, because Laravel's per-request PHP process model
opens and closes a connection per request; without pooling, `max_connections` would need to scale
with PHP-FPM worker count across every app server, which is both wasteful (idle connections still
consume ~5-10 MB of backend memory each) and a hard ceiling on horizontal app scaling.

```ini
# /etc/pgbouncer/pgbouncer.ini (relevant excerpt)
[databases]
qayd_prod = host=qayd-pg-primary.internal port=5432 dbname=qayd_prod pool_size=40

[pgbouncer]
pool_mode = transaction
max_client_conn = 2000
default_pool_size = 40
reserve_pool_size = 10
reserve_pool_timeout = 3
server_idle_timeout = 600
query_wait_timeout = 30
```

This means `max_connections` on Postgres itself is sized to PgBouncer's `pool_size` (times the number
of PgBouncer instances, if more than one), NOT to the application's concurrency — Postgres is
configured for `max_connections = 300` (40 per pool × up to ~6 logical pools across read/write split
and background jobs, with headroom for superuser and replication connections), while PgBouncer alone
absorbs up to 2000 concurrent client connections.

## What is measured

```promql
# Connections used as a percentage of max_connections (recording rule)
qayd:pg_connections_used_pct

# Backends by state — the shape of this breakdown matters more than the total
pg_stat_activity_count{state=~"active|idle|idle in transaction|idle in transaction \\(aborted\\)"}

# PgBouncer-side: client connections waiting for a server connection (pool exhaustion signal)
pgbouncer_pools_client_waiting_count{database="qayd_prod"}

# PgBouncer-side: average wait time for a connection from the pool
rate(pgbouncer_stats_avg_wait_time_seconds[5m])

# Connection churn rate — new backends started per second; a proxy for connection-pool misconfiguration
# if it is unexpectedly high relative to transaction rate
rate(pg_stat_database_numbackends[1m])
```

PgBouncer itself exposes a Postgres-protocol admin console (`SHOW POOLS`, `SHOW STATS`, `SHOW
CLIENTS`) that `postgres_exporter` can scrape in `pgbouncer` mode (separate exporter instance, see the
`postgres_exporter_pgbouncer` scrape job in `# Health Monitoring`) — this is how `pgbouncer_pools_*`
and `pgbouncer_stats_*` series are produced.

## Diagnosing connection saturation

```sql
-- Breakdown of current connections by application and state — first query in any connection incident
SELECT application_name, state, count(*)
FROM pg_stat_activity
WHERE pid <> pg_backend_pid()
GROUP BY application_name, state
ORDER BY count(*) DESC;

-- Longest-idle-in-transaction backends — these hold snapshots/locks and are the #1 cause of
-- connection-pool exhaustion incidents on QAYD (usually an unhandled exception in a Laravel service
-- that left a DB transaction open, or a long-running AI Layer call awaited inside a transaction block
-- in violation of the "AI never blocks a DB transaction" rule)
SELECT pid, usename, application_name, state,
       now() - xact_start AS xact_duration,
       now() - state_change AS state_duration,
       left(query, 200) AS query
FROM pg_stat_activity
WHERE state = 'idle in transaction'
ORDER BY xact_start ASC
LIMIT 20;
```

```sql
-- Emergency remediation: terminate idle-in-transaction backends open longer than 10 minutes
-- (used only after the runbook's investigation step confirms it is safe — see Runbooks)
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE state = 'idle in transaction'
  AND now() - state_change > interval '10 minutes'
  AND application_name NOT LIKE 'pg_dump%';
```

## Connections alert thresholds (summary; full rules in Alerts)

| Condition | Warning | Critical | Rationale |
|---|---|---|---|
| Postgres connections used | > 70% for 10m | > 90% for 2m | approaching hard connection refusal |
| PgBouncer clients waiting | > 5 for 5m | > 50 for 1m | pool exhaustion; requests queueing behind PgBouncer |
| `idle in transaction` count | > 10 for 10m | > 30 for 2m | (duplicated from Memory: same signal, dual relevance) |
| Longest `idle in transaction` age | > 5 min | > 15 min | a single stuck transaction can block vacuum and DDL cluster-wide |
| Connection churn rate | > 3x 7-day baseline for 10m | > 6x baseline for 5m | connection storm, often from a misbehaving retry loop |

# Locks

## Lock model in Postgres and why QAYD watches it closely

QAYD's write path is deliberately serialized at points where correctness demands it: posting a journal
entry recalculates a running balance and enforces `SUM(debits) = SUM(credits)`, which means the posting
transaction takes row-level locks on the affected `accounts` rows (via `SELECT ... FOR UPDATE` in the
Accounting service's `PostJournalEntry` use case) to prevent two concurrent postings from computing a
stale balance. This is correct and required — but it also means lock contention is an *expected*
background signal on QAYD's database, not just a bug indicator, and monitoring must distinguish
"normal serialization under load" from "a stuck lock holder blocking the queue."

```promql
# Total locks held, by mode
sum(pg_locks_count) by (mode, instance)

# Backends currently BLOCKED waiting for a lock (the critical signal — not lock count, but blocked count)
pg_stat_activity_count{state="active", wait_event_type="Lock"}

# Longest-waiting blocked backend's wait duration, seconds
max(time() - pg_stat_activity_query_start{wait_event_type="Lock"})
```

## Finding the blocking chain

`pg_locks_count` tells you *that* locks are held; it does not tell you *who is blocking whom*. The
canonical blocking-chain query, run manually during any lock-related incident and also exposed as a
Grafana table panel refreshed every 10s during declared incidents:

```sql
-- Blocking chain: who is waiting, on what, and who holds it
SELECT
  blocked.pid AS blocked_pid,
  blocked.usename AS blocked_user,
  blocked_activity.query AS blocked_query,
  now() - blocked_activity.query_start AS blocked_duration,
  blocking.pid AS blocking_pid,
  blocking.usename AS blocking_user,
  blocking_activity.query AS blocking_query,
  now() - blocking_activity.xact_start AS blocking_xact_duration
FROM pg_locks blocked
JOIN pg_stat_activity blocked_activity ON blocked_activity.pid = blocked.pid
JOIN pg_locks blocking ON blocking.locktype = blocked.locktype
  AND blocking.database IS DISTINCT FROM NULL AND blocking.database = blocked.database
  AND blocking.relation IS DISTINCT FROM NULL AND blocking.relation = blocked.relation
  AND blocking.pid != blocked.pid
JOIN pg_stat_activity blocking_activity ON blocking_activity.pid = blocking.pid
WHERE NOT blocked.granted
  AND blocking.granted
ORDER BY blocked_duration DESC;
```

## Deadlocks

Deadlocks are always an error signal (never "expected," unlike ordinary lock waits), and are watched
as a hard counter, normalized to a per-hour rate to match the resource envelope defined in
`DATABASE_PERFORMANCE.md`'s Performance Targets table:

```promql
# Deadlocks per hour, cluster-wide (recording rule from Metrics)
qayd:pg_deadlocks:rate1h
```

Every deadlock increments `pg_stat_database_deadlocks` and is also captured in the Postgres log (with
`log_lock_waits = on` and `deadlock_timeout = 1s`, see below), including the full blocking-chain detail
Postgres prints natively — that log line is forwarded to the centralized log pipeline and indexed by
`company_id` (extracted the same way as in `# CPU`) so recurring deadlocks against a specific tenant's
accounts can be traced to a specific application-level race condition, most commonly two concurrent
posting requests both trying to write against the same account within the same fiscal period without
going through the serialized posting queue.

```
# postgresql.conf — lock diagnosability settings
log_lock_waits = on
deadlock_timeout = 1s
lock_timeout = 30s          # application-level guard: no query waits on a lock for more than 30s
statement_timeout = 60s     # application-level guard: no single statement runs for more than 60s
```

`lock_timeout` and `statement_timeout` are QAYD-wide safety nets, not the primary correctness
mechanism (that is the explicit `FOR UPDATE` locking in the Service layer) — they exist so that a bug
in a specific code path degrades to a visible, retriable 500 error rather than an invisible pileup that
only monitoring would otherwise catch minutes later.

## Locks alert thresholds (summary; full rules in Alerts)

| Condition | Warning | Critical | Rationale |
|---|---|---|---|
| Blocked backend count | > 3 for 2m | > 10 for 1m | contention beyond expected posting-queue serialization |
| Longest blocked-wait duration | > 10s for 2m | > 25s for 1m | approaching `lock_timeout`; user-visible latency imminent |
| Deadlocks per hour (`qayd:pg_deadlocks:rate1h`) | > 1/h for 15m | > 3/h for 5m | aligned to `DATABASE_PERFORMANCE.md`'s green/yellow/red (0 / 1–3 / >3 per hour) envelope; any sustained rate above near-zero baseline is a code-level bug |
| `idle in transaction` holding a lock | any, for > 2m | any, for > 5m | single most common root cause of blocking-chain incidents |

# Slow Queries

## Division of responsibility with DATABASE_PERFORMANCE.md

`DATABASE_PERFORMANCE.md` owns the *triage decision tree* for a slow query once a human is already
looking at it (classify by `wait_event_type`, capture `EXPLAIN (ANALYZE, BUFFERS)`, decide kill-or-wait)
and the *tuning technique* that fixes it (indexing, plan optimization, caching). This document owns
the layer above that: how the fleet continuously measures query latency at scale, when a Prometheus
alert should tell a human that a slow query even exists, and what the standing Grafana dashboards look
like — the detection surface that feeds Performance's runbook, not a restatement of it.

## Detection: pg_stat_statements as a continuous instrument, not an ad-hoc tool

`pg_stat_statements` is enabled identically to the configuration fixed in `DATABASE_PERFORMANCE.md`,
because two different `shared_preload_libraries` values across environments is itself an operational
hazard (a query that looks "new" in staging because statistics reset differently is a false lead):

```
# postgresql.conf
shared_preload_libraries = 'pg_stat_statements'
pg_stat_statements.max = 10000
pg_stat_statements.track = all
pg_stat_statements.track_utility = off
pg_stat_statements.save = on
```

```sql
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
```

The custom `pg_stat_statements_totals` query (defined in `# Health Monitoring`) re-exports three
rollups every scrape: cumulative execution time, cumulative call count, and a count of normalized
queries whose `mean_exec_time` exceeds 500ms. Prometheus turns the first two into rates; the third is
graphed directly as a gauge, since it is already a point-in-time count of offenders, not a counter.

```promql
# Average query latency across the whole fleet, ms per call — the single top-line "is Postgres slow" number
(rate(total_exec_time_ms[5m]) / clamp_min(rate(total_calls[5m]), 1))

# Distinct slow-normalized-query count (mean_exec_time > 500ms) — a rising count means a regression
# just shipped, even if the p50 average above looks unchanged (a few very slow rare queries hide well
# inside an average dominated by thousands of fast ones)
slow_query_count

# Fleet-wide call rate — traffic context for the other two signals, per the four-signal model
rate(total_calls[5m])
```

## Per-query latency percentiles

`pg_stat_statements` stores `mean_exec_time`, `stddev_exec_time`, `min_exec_time`, and `max_exec_time`
per normalized query, but not a true percentile histogram — Postgres does not bucket individual query
executions internally. QAYD approximates p95/p99 tracking two ways, used together:

1. **Statistical approximation from mean + stddev**, cheap and always available, adequate for
   alerting (not for SLA reporting):

```sql
-- Approximate p95 assuming roughly normal per-query latency distribution (mean + 1.645*stddev);
-- flagged separately from a true percentile because heavy-tailed queries (most of them) violate
-- the normality assumption — this is a triage heuristic, not a reporting number
SELECT
  query,
  calls,
  round(mean_exec_time::numeric, 2) AS mean_ms,
  round((mean_exec_time + 1.645 * stddev_exec_time)::numeric, 2) AS approx_p95_ms,
  round(max_exec_time::numeric, 2) AS max_ms
FROM pg_stat_statements
WHERE calls > 20
ORDER BY approx_p95_ms DESC
LIMIT 25;
```

2. **True percentiles from the application-layer query log**, which is the source of truth used for
   the p50/p95/p99 targets published in `DATABASE_PERFORMANCE.md`'s Performance Targets table:
   Laravel's `DB::listen()` hook emits a structured log line per query (normalized query fingerprint,
   duration, `company_id`) to the same pipeline that feeds the centralized log store; a Vector/Loki
   pipeline stage computes true histogram-based percentiles per query-class label and exposes them to
   Prometheus via `histogram_quantile()`:

```promql
# True p95 latency for the "journal posting transaction" query class, from the app-layer histogram
histogram_quantile(0.95, sum(rate(qayd_query_duration_seconds_bucket{query_class="journal_posting"}[5m])) by (le))

# True p99 for the same class
histogram_quantile(0.99, sum(rate(qayd_query_duration_seconds_bucket{query_class="journal_posting"}[5m])) by (le))
```

## Slow query alert thresholds (summary; full rules in Alerts)

| Condition | Warning | Critical | Rationale |
|---|---|---|---|
| Fleet-wide mean latency (`total_exec_time_ms`/`total_calls`, rate5m) | > 20 ms for 15m | > 50 ms for 5m | broad regression across many query classes, not one query |
| Distinct slow-query count (`slow_query_count`) | > 15 for 15m | > 40 for 5m | a deploy just introduced (or a planner just chose) a bad plan somewhere |
| p95 for `journal_posting` query class | > 40 ms for 10m | > 100 ms for 5m | reuses `DATABASE_PERFORMANCE.md`'s Performance Targets row for this exact query class verbatim |
| p95 for `trial_balance` query class | > 150 ms for 10m | > 400 ms for 5m | reuses `DATABASE_PERFORMANCE.md`'s Performance Targets row for this exact query class verbatim |
| Any single normalized query's `max_exec_time` | > `statement_timeout` × 0.8 for any occurrence | at `statement_timeout` (query will be killed by Postgres itself) | early warning before the hard `statement_timeout` cutoff defined in `# Locks` starts truncating user requests |

## N+1 and per-tenant hot-query detection

The single most common QAYD-specific slow-query pattern is not a missing index — it is an N+1 query
pattern introduced by a new Laravel Eloquent relationship that AI-assisted or human code review missed,
which shows up in `pg_stat_statements` not as one slow query but as one *fast* query called an
anomalously large number of times per request. This is invisible to a "top queries by mean latency"
view and requires a call-count-relative-to-traffic check instead:

```sql
-- Queries whose call-rate grew far faster than overall traffic — the N+1 signature.
-- Run against two pg_stat_statements snapshots (e.g. captured hourly into ops.pg_stat_statements_history
-- by a pg_cron job) rather than the live view, since pg_stat_statements itself has no time dimension.
WITH latest AS (
  SELECT queryid, calls, captured_at FROM ops.pg_stat_statements_history
  WHERE captured_at = (SELECT max(captured_at) FROM ops.pg_stat_statements_history)
), previous AS (
  SELECT queryid, calls, captured_at FROM ops.pg_stat_statements_history
  WHERE captured_at = (SELECT max(captured_at) FROM ops.pg_stat_statements_history
                        WHERE captured_at < (SELECT max(captured_at) FROM ops.pg_stat_statements_history))
)
SELECT l.queryid, l.calls - p.calls AS call_delta,
       round((l.calls - p.calls) / EXTRACT(EPOCH FROM (l.captured_at - p.captured_at)) * 60, 1) AS calls_per_min
FROM latest l JOIN previous p ON p.queryid = l.queryid
ORDER BY call_delta DESC
LIMIT 20;
```

# Replication

## Topology and replication modes

QAYD's replication topology, sourced from and required to remain consistent with
`DATABASE_BACKUP_RECOVERY.md`'s `# Geo Replication`, has three standbys off the single primary, each
serving a distinct purpose and therefore monitored against a distinct lag budget:

| Standby | Mode | `synchronous_commit` | Purpose | Lag budget |
|---|---|---|---|---|
| `qayd-pg-standby-a` | Synchronous, streaming | `remote_apply` | Zero-data-loss local failover | Near-zero; alerts on any sustained nonzero lag |
| `qayd-pg-standby-read` | Asynchronous, streaming | `off`/`local` (session-level on primary writers, per `DATABASE_ARCHITECTURE.md`) | Read-offload for reporting, AI-agent analytics, exports | Loose; alerts on staleness relevant to reads, not durability |
| `qayd-pg-standby-dr` | Asynchronous, streaming, cross-region | — | Disaster recovery + GCC data residency | DR-budgeted; alerts on approaching the documented DR RPO |

```
# postgresql.conf on primary
wal_level = replica
max_wal_senders = 10
max_replication_slots = 10
synchronous_commit = remote_apply
synchronous_standby_names = 'FIRST 1 (standby_a)'
hot_standby = on
```

Each standby connects via its own dedicated physical replication slot, so the primary retains exactly
the WAL each standby still needs and no more:

```sql
SELECT pg_create_physical_replication_slot('standby_a_slot');
SELECT pg_create_physical_replication_slot('standby_dr_slot');
SELECT pg_create_physical_replication_slot('standby_read_slot');
```

## What is measured

The custom `pg_replication_status` query (defined in `# Health Monitoring`) is the primary source for
replication metrics, because it labels lag by `application_name` — which the stock `postgres_exporter`
build does not do by default — allowing per-standby thresholds rather than one fleet-wide number that
would be wrong for at least two of the three standbys at any given time.

```promql
# Time-based replay lag per standby, seconds (primary's view)
replay_lag_seconds{application_name="standby_a"}
replay_lag_seconds{application_name="standby_read"}
replay_lag_seconds{application_name="standby_dr"}

# Byte-based replay lag per standby (LSN distance) — catches slow-but-nonzero lag that a coarse
# time-based sample might momentarily miss between scrapes
replay_lag_bytes{application_name="standby_a"}

# Standby-side staleness proxy: seconds since the standby last received a WAL message from the primary
time() - pg_stat_wal_receiver_last_msg_receipt_time

# Is the standby actually in recovery mode (sanity check that it hasn't silently promoted itself
# or been detached)
pg_is_in_recovery_status  # custom boolean-as-gauge exported per standby: 1 = standby, 0 = primary/unexpected
```

`qayd-pg-standby-a` is watched on **two complementary lag dimensions simultaneously**: the time-based
`replay_lag_seconds`, which should sit at effectively zero because `synchronous_commit=remote_apply`
means the primary does not consider a transaction committed until this standby has applied it, and the
byte-based `replay_lag_bytes`, which catches the case where wall-clock lag looks momentarily fine
between two scrapes but the standby is measurably behind by WAL volume — the two together are strictly
more informative than either alone, and this is why `pg_replication_status` exports both.

## Monitoring queries run directly against Postgres

```sql
-- Run on the primary: per-standby view, the same data the custom exporter query re-exports,
-- kept here because it is also the first command run manually during any replication incident
SELECT application_name, client_addr, state, sync_state,
       sent_lsn, write_lsn, flush_lsn, replay_lsn,
       write_lag, flush_lag, replay_lag
FROM pg_stat_replication
ORDER BY application_name;

-- Run on any standby: the standby's own view of how far behind it is
SELECT now() - pg_last_xact_replay_timestamp() AS replication_lag,
       pg_is_in_recovery() AS is_standby,
       pg_last_wal_receive_lsn() AS receive_lsn,
       pg_last_wal_replay_lsn() AS replay_lsn;

-- Replication slot health — an inactive slot is the #1 cause of unbounded WAL growth on the primary
-- (see # Storage) because the primary must retain WAL for a slot until it is consumed or dropped
SELECT slot_name, slot_type, active, active_pid,
       pg_size_pretty(pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn)) AS retained_wal,
       wal_status
FROM pg_replication_slots;
```

## Replica-role-specific alert thresholds (summary; full rules in Alerts)

| Standby | Condition | Warning | Critical | Rationale |
|---|---|---|---|---|
| `standby_a` (sync) | `replay_lag_seconds` | > 0 sustained for 15s | > 2s for 5s | any sustained nonzero lag means synchronous replication has silently degraded toward asynchronous, eroding the zero-data-loss guarantee for local failover |
| `standby_a` (sync) | `replay_lag_bytes` | > 0 sustained for 30s | > 8 MB for 15s | complementary byte-based signal per the note above |
| `standby_read` (async, local) | `replay_lag_seconds` | > 30s for 10m | > 120s for 5m | stale reporting/AI-agent reads are a correctness-adjacent nuisance, not a durability risk — looser budget than the sync standby by design |
| `standby_dr` (async, cross-region) | `replay_lag_seconds` | > 120s for 10m | > 300s (5 min) for 5m | matches the DR RPO budget fixed in `DATABASE_BACKUP_RECOVERY.md`'s `# Continuous Backup`; async cross-region lag is expected, only *excess* lag pages |
| Any standby | WAL receiver staleness | > 60s for 5m | > 180s for 2m | the standby has stopped hearing from the primary entirely — a network partition or a crashed WAL sender, distinct from "hearing but slow to apply" |
| Any replication slot | `wal_status` | `'keeping'` for 30m | `'lost'` (any duration) or `'keeping'` for 2h | `'lost'` means PITR/standby rebuild from this slot is no longer possible without a fresh base backup |
| Primary | `pg_is_in_recovery_status` on the primary itself | n/a | `= 1` (the "primary" is unexpectedly in recovery) | indicates an unplanned/unexpected role change — page immediately, this is a Sev-1 topology fact, not a metric drift |

## Failover and promotion detection

Prometheus cannot initiate a failover (QAYD does not run automatic failover — promotion is always a
deliberate, runbook-driven human action per `DATABASE_BACKUP_RECOVERY.md`'s `# Disaster Recovery`),
but it must detect one immediately after it happens, whether planned or not, because every dashboard,
alert label, and PgBouncer target in this document assumes a specific host is "the primary":

```promql
# Alerts if more than one instance simultaneously reports pg_is_in_recovery_status = 0 (two primaries —
# a split-brain condition that must never occur, and if it does, is the single highest-severity alert
# this document defines) or if zero instances do (no primary at all)
count(pg_is_in_recovery_status == 0)
```

```yaml
- alert: PostgresSplitBrainDetected
  expr: count(pg_is_in_recovery_status == 0) > 1
  for: 30s
  labels:
    severity: critical
    signal: errors
    sev: sev1
  annotations:
    summary: "More than one PostgreSQL instance reports itself as primary"
    description: >
      {{ $value }} instances currently report pg_is_in_recovery_status == 0. This must be exactly 1.
      Stop all application writes immediately and follow the Split-Brain runbook before taking any
      other action — this condition risks silent, divergent, unmergeable financial data.
```

# Backups

## Division of responsibility with DATABASE_BACKUP_RECOVERY.md

`DATABASE_BACKUP_RECOVERY.md` is the binding specification for *how* QAYD backs up and restores:
pgBackRest topology, the full/differential/incremental cadence, WAL archiving mechanics, PITR
procedure, S3 repository structure, encryption, and retention policy. This document does not repeat
that mechanism. It specifies how the *outcome* of that mechanism is continuously observed, so that a
silently broken backup process is a Prometheus alert within minutes, never a discovery made three
weeks later while attempting an actual restore.

## What is measured

pgBackRest exposes its own state via `pgbackrest info --output=json`, which is not natively a
Prometheus format; QAYD runs a small textfile-collector script on `qayd-pg-repo` that parses this JSON
every 5 minutes and writes Prometheus-format metrics to `node_exporter`'s textfile collector directory,
where they are picked up on the next scrape like any other `node_exporter` metric:

```bash
#!/usr/bin/env bash
# /opt/qayd/scripts/pgbackrest_textfile_exporter.sh — run every 5 minutes by cron on qayd-pg-repo
set -euo pipefail
OUT=/var/lib/node_exporter/textfile_collector/pgbackrest.prom.$$
INFO=$(sudo -u postgres pgbackrest --stanza=qayd info --output=json)

FULL_TS=$(echo "$INFO" | jq -r '.[0].backup[] | select(.type=="full") | .timestamp.stop' | tail -1)
LATEST_TS=$(echo "$INFO" | jq -r '.[0].backup[-1].timestamp.stop')
LATEST_TYPE=$(echo "$INFO" | jq -r '.[0].backup[-1].type')
LATEST_ERROR=$(echo "$INFO" | jq -r '.[0].backup[-1].error // false')
REPO_SIZE=$(echo "$INFO" | jq -r '.[0].backup[-1].info.repository.size')
ARCHIVE_MIN=$(echo "$INFO" | jq -r '.[0].archive[0].min')
ARCHIVE_MAX=$(echo "$INFO" | jq -r '.[0].archive[0].max')

{
  echo "# HELP pgbackrest_last_full_backup_timestamp Unix epoch of the last successful full backup"
  echo "# TYPE pgbackrest_last_full_backup_timestamp gauge"
  echo "pgbackrest_last_full_backup_timestamp{stanza=\"qayd\"} $(date -d "$FULL_TS" +%s)"

  echo "# HELP pgbackrest_last_backup_timestamp Unix epoch of the most recent backup of any type"
  echo "# TYPE pgbackrest_last_backup_timestamp gauge"
  echo "pgbackrest_last_backup_timestamp{stanza=\"qayd\",type=\"$LATEST_TYPE\"} $(date -d "$LATEST_TS" +%s)"

  echo "# HELP pgbackrest_last_backup_error 1 if the most recent backup job reported an error"
  echo "# TYPE pgbackrest_last_backup_error gauge"
  echo "pgbackrest_last_backup_error{stanza=\"qayd\"} $([ "$LATEST_ERROR" = "true" ] && echo 1 || echo 0)"

  echo "# HELP pgbackrest_repository_size_bytes Size of the most recent backup set in the repository"
  echo "# TYPE pgbackrest_repository_size_bytes gauge"
  echo "pgbackrest_repository_size_bytes{stanza=\"qayd\"} $REPO_SIZE"
} > "$OUT"
mv "$OUT" /var/lib/node_exporter/textfile_collector/pgbackrest.prom
```

```promql
# Age of the last successful full backup, seconds — the single most important backup-health metric
time() - pgbackrest_last_full_backup_timestamp

# Age of the last backup of ANY type (full/diff/incr) — catches a stalled cadence sooner than the
# full-backup-only metric above, since incrementals run every 4 hours per DATABASE_BACKUP_RECOVERY.md
time() - pgbackrest_last_backup_timestamp

# Did the most recent backup job report an error
pgbackrest_last_backup_error

# WAL archiving lag — the age of the oldest un-archived WAL file, mirroring
# check_wal_archiving_lag.sh from DATABASE_BACKUP_RECOVERY.md but as a continuously-scraped metric
# rather than only a cron-triggered page, giving Grafana a trend line as well as an alert
pg_stat_archiver_last_archived_age_seconds

# Repository growth rate — feeds both alerting (unexpected jump) and capacity planning (steady trend)
deriv(pgbackrest_repository_size_bytes[6h])
```

## Recovery-test results as a monitored signal

Per `DATABASE_BACKUP_RECOVERY.md`'s `# Recovery Testing`, the nightly automated restore drill's
pass/fail result — including its trial-balance invariant check
(`SUM(debit_amount) - SUM(credit_amount) = 0` across posted `journal_lines`) — is written to a metric,
not just a log line, specifically so it can back a Grafana panel and an Alertmanager rule rather than
depending on someone reading a cron job's log output after the fact:

```bash
# Appended to the end of /opt/qayd/scripts/nightly_restore_drill.sh
RESULT=$([ "$RECOVERY_STATE" = "f" ] && [ "$ROW_COUNT" -ge 1 ] && [ "$BALANCE_CHECK" = "0.0000" ] && echo 1 || echo 0)
cat <<EOF > /var/lib/node_exporter/textfile_collector/restore_drill.prom
# HELP qayd_restore_drill_last_result 1 = passed, 0 = failed
# TYPE qayd_restore_drill_last_result gauge
qayd_restore_drill_last_result{drill="nightly"} $RESULT
# HELP qayd_restore_drill_last_run_timestamp Unix epoch of the last drill attempt
# TYPE qayd_restore_drill_last_run_timestamp gauge
qayd_restore_drill_last_run_timestamp{drill="nightly"} $(date +%s)
EOF
```

This same pattern (`qayd_restore_drill_last_result{drill="pitr_weekly"}`,
`qayd_restore_drill_last_result{drill="dr_failover_quarterly"}`) covers all four recovery-test tiers
defined in `DATABASE_BACKUP_RECOVERY.md`, and their results feed the "DR Readiness" Grafana dashboard
described in `# Dashboards`.

## Backup alert thresholds (summary; full rules in Alerts)

| Condition | Warning | Critical | Rationale |
|---|---|---|---|
| Age of last full backup | > 8 days | > 9 days | full backups run weekly per `DATABASE_BACKUP_RECOVERY.md`; 8 days already means one cycle was missed |
| Age of last backup (any type) | > 6 hours | > 10 hours | incrementals run every 4 hours; 6h already means one was missed |
| `pgbackrest_last_backup_error` | any occurrence | any occurrence sustained 5m | a backup job errored — treated as Warning on first sight (may self-heal on retry) and Critical if it persists |
| WAL archiving lag (`pg_stat_archiver_last_archived_age_seconds`) | > 120s for 5m | > 300s for 2m | mirrors the 300s threshold already enforced by `check_wal_archiving_lag.sh`'s direct paging path, now also visible on dashboards and alertable through the standard pipeline |
| Nightly restore drill result | `= 0` (failed), first occurrence | `= 0` for two consecutive nights | matches `DATABASE_BACKUP_RECOVERY.md`'s own escalation rule verbatim (single failure = Sev-2, two consecutive = Sev-1) |
| Repository size growth rate | > 2x 30-day baseline for 6h | > 5x baseline for 2h | sudden repository growth without a corresponding tenant-growth signal suggests a bloat or retention-policy bug, not legitimate data growth |
| `qayd-pg-repo` host disk used | > 75% | > 90% | the repo host filling up silently breaks every future backup even while past backups remain intact and unnoticed |

# Alerts

## Severity model

Every alert in this document resolves to one of three severities, using the same `sev1`/`sev2`/`sev3`
vocabulary as `DATABASE_BACKUP_RECOVERY.md`'s drill-escalation rules, so an engineer moving between
documents never has to mentally translate severity naming:

| Severity | Meaning | Routing | Response expectation |
|---|---|---|---|
| `sev1` (Critical) | Active or imminent customer-visible impact, data-integrity risk, or total loss of a core capability (e.g. split-brain, primary down, disk full) | PagerDuty, immediate phone/push escalation | Acknowledge within 5 minutes, engage Incident Commander per `# Incident Response` |
| `sev2` (Warning that requires same-shift action) | Degraded but not yet customer-visible, or a leading indicator with a short runway (e.g. approaching connection saturation, backup one cycle late) | PagerDuty (business hours), Slack `#qayd-db-watch` (after hours, no page) | Acknowledge within 30 minutes, investigate same shift |
| `sev3` (Informational / capacity signal) | No near-term risk; feeds capacity planning and trend review | Slack `#qayd-db-watch` only, no page | Reviewed in the next business day / weekly capacity review |

This maps directly onto every "Warning / Critical" threshold pair defined throughout this document:
**Warning → `sev2`**, **Critical → `sev1`**, with a small number of explicitly-noted exceptions (e.g.
`sev3`-only capacity-trend alerts defined in `# Capacity Planning`) called out individually where they
deviate from this default mapping.

## Alertmanager routing configuration

```yaml
# infra/alertmanager/alertmanager.yml
global:
  resolve_timeout: 5m
  slack_api_url: 'https://hooks.slack.com/services/REDACTED'

route:
  receiver: default_slack
  group_by: ['alertname', 'instance']
  group_wait: 30s
  group_interval: 5m
  repeat_interval: 4h
  routes:
    - match:
        severity: critical
      receiver: pagerduty_db_oncall
      continue: true
    - match:
        severity: critical
      receiver: slack_db_watch_critical
    - match:
        severity: warning
      receiver: slack_db_watch_warning
      routes:
        - match_re:
            hour: "^(09|10|11|12|13|14|15|16|17)$"   # business-hours-only page for sev2, evaluated
                                                        # by a pre-routing label injected at scrape time
          receiver: pagerduty_db_oncall_low_urgency

receivers:
  - name: default_slack
    slack_configs:
      - channel: '#qayd-db-watch'
        send_resolved: true

  - name: slack_db_watch_critical
    slack_configs:
      - channel: '#qayd-db-watch'
        send_resolved: true
        title: '{{ "\U0001F534" }} {{ .CommonLabels.alertname }}'

  - name: slack_db_watch_warning
    slack_configs:
      - channel: '#qayd-db-watch'
        send_resolved: true
        title: '{{ "\U0001F7E1" }} {{ .CommonLabels.alertname }}'

  - name: pagerduty_db_oncall
    pagerduty_configs:
      - service_key: 'REDACTED_PAGERDUTY_INTEGRATION_KEY'
        severity: critical

  - name: pagerduty_db_oncall_low_urgency
    pagerduty_configs:
      - service_key: 'REDACTED_PAGERDUTY_INTEGRATION_KEY'
        severity: warning

inhibit_rules:
  # A firing PostgresPrimaryDown makes every downstream alert on that instance redundant noise —
  # inhibit them so the on-call engineer sees one page, not fifteen.
  - source_match:
      alertname: 'PostgresPrimaryDown'
    target_match_re:
      alertname: '.*'
    equal: ['instance']

  # A firing PostgresSplitBrainDetected inhibits every individual replication-lag alert, since those
  # readings are meaningless (and possibly actively misleading) until split-brain is resolved.
  - source_match:
      alertname: 'PostgresSplitBrainDetected'
    target_match_re:
      alertname: 'PostgresReplication.*'
```

## Representative alert rules

The complete rule set lives in `infra/prometheus/rules/postgres_alerts.yml`, organized into one group
per section of this document. The following is a representative sample — one fully-specified rule per
earlier section — demonstrating the exact syntax every other threshold table in this document expands
into:

```yaml
groups:
  - name: postgres_cpu
    rules:
      - alert: PostgresCPUHighWarning
        expr: 100 * (1 - avg by (instance) (rate(node_cpu_seconds_total{mode="idle",service="node"}[5m]))) > 75
        for: 10m
        labels:
          severity: warning
          signal: saturation
          sev: sev2
        annotations:
          summary: "{{ $labels.instance }} sustained CPU > 75%"
          description: "CPU utilization has been above 75% for 10 minutes on {{ $labels.instance }}. Current value: {{ $value | printf \"%.1f\" }}%."
          runbook: "https://runbooks.qayd.internal/db/high-cpu"

      - alert: PostgresCPUHighCritical
        expr: 100 * (1 - avg by (instance) (rate(node_cpu_seconds_total{mode="idle",service="node"}[5m]))) > 90
        for: 5m
        labels:
          severity: critical
          signal: saturation
          sev: sev1
        annotations:
          summary: "{{ $labels.instance }} sustained CPU > 90%"
          description: "CPU utilization has been above 90% for 5 minutes on {{ $labels.instance }}. Query latency degradation is expected imminently."
          runbook: "https://runbooks.qayd.internal/db/high-cpu"

  - name: postgres_connections
    rules:
      - alert: PostgresConnectionsSaturatedCritical
        expr: qayd:pg_connections_used_pct > 90
        for: 2m
        labels:
          severity: critical
          signal: saturation
          sev: sev1
        annotations:
          summary: "{{ $labels.instance }} connections > 90% of max_connections"
          description: "{{ $value | printf \"%.1f\" }}% of max_connections in use. New connections will begin failing imminently."
          runbook: "https://runbooks.qayd.internal/db/connection-exhaustion"

  - name: postgres_locks
    rules:
      - alert: PostgresBlockedBackendsCritical
        expr: sum(pg_stat_activity_count{state="active", wait_event_type="Lock"}) by (instance) > 10
        for: 1m
        labels:
          severity: critical
          signal: latency
          sev: sev1
        annotations:
          summary: "{{ $labels.instance }} has {{ $value }} backends blocked on locks"
          description: "More than 10 backends have been blocked waiting on a lock for over a minute — beyond expected posting-queue serialization."
          runbook: "https://runbooks.qayd.internal/db/lock-contention"

      - alert: PostgresDeadlockRateCritical
        expr: qayd:pg_deadlocks:rate1h > 3
        for: 5m
        labels:
          severity: critical
          signal: errors
          sev: sev1
        annotations:
          summary: "{{ $labels.instance }} deadlock rate exceeds 3/hour"
          description: "Deadlock rate is {{ $value | printf \"%.1f\" }}/hour, above the red threshold in DATABASE_PERFORMANCE.md's resource envelope."
          runbook: "https://runbooks.qayd.internal/db/deadlock-spike"

  - name: postgres_replication
    rules:
      - alert: PostgresSyncStandbyLagCritical
        expr: replay_lag_seconds{application_name="standby_a"} > 2
        for: 5s
        labels:
          severity: critical
          signal: latency
          sev: sev1
        annotations:
          summary: "Synchronous standby standby_a replay lag exceeds 2s"
          description: "synchronous_commit=remote_apply's zero-data-loss guarantee is degraded. Check for network partition or standby resource exhaustion."
          runbook: "https://runbooks.qayd.internal/db/replication-lag"

      - alert: PostgresDRStandbyLagCritical
        expr: replay_lag_seconds{application_name="standby_dr"} > 300
        for: 5m
        labels:
          severity: critical
          signal: latency
          sev: sev1
        annotations:
          summary: "DR standby standby_dr replay lag exceeds documented 5-minute RPO budget"
          description: "Cross-region DR lag is {{ $value | printf \"%.0f\" }}s, past the RPO budget fixed in DATABASE_BACKUP_RECOVERY.md."
          runbook: "https://runbooks.qayd.internal/db/dr-lag"

  - name: postgres_backups
    rules:
      - alert: PostgresBackupStaleCritical
        expr: time() - pgbackrest_last_backup_timestamp > 36000
        for: 0m
        labels:
          severity: critical
          signal: errors
          sev: sev1
        annotations:
          summary: "No successful pgBackRest backup of any type in over 10 hours"
          description: "Last backup was {{ $value | printf \"%.0f\" }}s ago on qayd-pg-repo. Incrementals are scheduled every 4 hours."
          runbook: "https://runbooks.qayd.internal/db/backup-failure"

      - alert: PostgresRestoreDrillFailed
        expr: qayd_restore_drill_last_result{drill="nightly"} == 0
        for: 0m
        labels:
          severity: warning
          signal: errors
          sev: sev2
        annotations:
          summary: "Nightly automated restore drill failed"
          description: "The trial-balance-verified restore drill did not pass. A second consecutive failure escalates to sev1 automatically via PostgresRestoreDrillFailedTwice."
          runbook: "https://runbooks.qayd.internal/db/restore-drill-failure"

      - alert: PostgresRestoreDrillFailedTwice
        expr: qayd_restore_drill_last_result{drill="nightly"} == 0 and qayd_restore_drill_last_result{drill="nightly"} offset 1d == 0
        for: 0m
        labels:
          severity: critical
          signal: errors
          sev: sev1
        annotations:
          summary: "Nightly restore drill failed two nights in a row"
          description: "Per DATABASE_BACKUP_RECOVERY.md's escalation rule, two consecutive failures mean QAYD may currently be unable to recover from a real incident, regardless of production impact."
          runbook: "https://runbooks.qayd.internal/db/restore-drill-failure"
```

## Meta-monitoring: watching the watchers

An alerting stack that silently stops evaluating rules is worse than no alerting stack, because it
manufactures false confidence. QAYD runs three independent layers to make "Prometheus itself is down"
or "Alertmanager stopped delivering" a detectable, paged condition:

1. **Prometheus's own `up` metric, scraped cross-instance.** The `eu-central-1` Prometheus and the
   `me-south-1` DR-region shadow Prometheus (see `# Health Monitoring`) each scrape a `blackbox_exporter`
   probe of the other's `/-/healthy` endpoint, so neither region's monitoring stack can fail silently
   without the other observing it.
2. **Alertmanager's "dead man's switch."** A synthetic alert rule that is *always* firing is configured
   with `repeat_interval: 5m` and routed to a third-party heartbeat service (e.g. a Healthchecks.io-style
   endpoint) rather than to Slack/PagerDuty directly; if the heartbeat is not pinged within 10 minutes,
   the third-party service pages independently of QAYD's own infrastructure:

```yaml
  - name: postgres_meta
    rules:
      - alert: Watchdog
        expr: vector(1)
        labels:
          severity: none
          signal: none
        annotations:
          summary: "Alerting pipeline heartbeat — this alert should always be firing"
```

3. **`pg_up == 0` from a *different* vantage point than the exporter under test.** Because
   `postgres_exporter` runs as a sidecar on the same host it monitors, a total host failure takes down
   both the database and its own exporter simultaneously, which Prometheus correctly reports as
   `pg_up{instance="..."} == 0` — but a network partition that isolates Prometheus itself from an
   otherwise-healthy host would look identical. `blackbox_exporter` additionally probes raw TCP
   connectivity to port 5432 on every host from a second, independent network path (the monitoring VPC's
   secondary AZ) specifically to distinguish "the database is down" from "our view of the database is
   down," which call for entirely different runbooks.

## Alert fatigue governance

Every alert rule added to `infra/prometheus/rules/postgres_*.yml` requires, in the same pull request, a
linked `runbook` annotation URL (enforced by a CI lint step that fails the PR if `runbook` is missing) —
an alert with no corresponding action is a Slack notification, not an alert, and belongs in a dashboard
instead. Alert rules are reviewed quarterly against their firing history in a fixed process: any rule
that fired more than 10 times in the quarter and was acknowledged without action being taken (tracked
via the PagerDuty incident's resolution notes) is either tightened (threshold, duration) or demoted from
`sev1`/`sev2` to a dashboard-only `sev3` signal, and any rule that never fired in two consecutive
quarters is reviewed for whether its threshold is still meaningful against current traffic levels.

# Dashboards

## Provisioning convention

Every dashboard is a JSON file under `infra/grafana/dashboards/`, provisioned via Grafana's file-based
provisioning (`provisioning/dashboards/postgres.yml` pointing at that directory), never hand-edited in
the Grafana UI in production. A change to a dashboard goes through the same pull-request review as a
schema migration or an alert rule, specifically so that a panel referencing a metric that was renamed
or removed is caught by a reviewer (or a CI job that lints dashboard JSON against the current
`postgres_recording.yml` and `queries.yaml` metric names) before it silently goes blank in production.

```yaml
# infra/grafana/provisioning/dashboards/postgres.yml
apiVersion: 1
providers:
  - name: postgres
    folder: 'Database'
    type: file
    disableDeletion: true
    updateIntervalSeconds: 30
    options:
      path: /etc/grafana/dashboards/postgres
      foldersFromFilesStructure: false
```

## Dashboard inventory

| Dashboard | Audience | Refresh | Purpose |
|---|---|---|---|
| Postgres Fleet Overview | Everyone (default landing page, NOC/TV mode) | 30s | Health score per instance (see `# Health Monitoring`), fleet-wide traffic/error/saturation at a glance |
| Instance Drill-Down | On-call engineer during triage | 10s | Per-instance CPU/memory/storage/connections/locks, templated by `$instance` |
| Connections & PgBouncer | On-call engineer, capacity reviewer | 15s | Pool utilization, waiting clients, backend state breakdown, churn rate |
| Locks & Blocking | On-call engineer during a lock incident | 10s (5s during a declared incident, toggled manually) | Live blocking-chain table, blocked-backend count trend, deadlock rate |
| Replication & DR Readiness | On-call engineer, DR drill reviewer, auditors | 15s | Per-standby lag (both dimensions), slot health, recovery-test pass/fail history, RTO drill timing trend |
| Backup Health | On-call engineer, compliance reviewer | 60s | Backup age/type/error, repository growth, WAL archiving lag |
| Slow Query / pg_stat_statements Top-N | Engineer investigating a performance regression | 30s | Top queries by total time and by mean latency, N+1 call-rate anomalies |
| Capacity Trends | Capacity planning review (monthly), engineering leadership | N/A (long-range, 90-day default window) | Disk/connections/CPU/tenant-count trend lines with linear projection, feeding `# Capacity Planning` |

## Fleet Overview dashboard — key panels

```json
{
  "title": "Postgres Fleet Overview",
  "panels": [
    {
      "title": "Health Score by Instance",
      "type": "bargauge",
      "targets": [
        { "expr": "100 - 25*clamp_max((qayd:pg_connections_used_pct/100 - 0.5)/0.35, 1) - 25*clamp_max(max(replay_lag_seconds{application_name=\"standby_a\"})/30, 1) - 25*clamp_max((qayd:pg_disk_used_pct - 0.6)/0.25, 1) - 25*clamp_max(sum(pg_stat_activity_count{state=\"active\",wait_event_type=\"Lock\"})/5, 1)" }
      ],
      "fieldConfig": {
        "thresholds": { "steps": [
          { "value": 0, "color": "red" },
          { "value": 60, "color": "yellow" },
          { "value": 85, "color": "green" }
        ]}
      }
    },
    {
      "title": "Cluster Transactions/sec",
      "type": "timeseries",
      "targets": [{ "expr": "qayd:pg_tps:rate5m" }]
    },
    {
      "title": "Buffer Cache Hit Ratio",
      "type": "timeseries",
      "targets": [{ "expr": "qayd:pg_cache_hit_ratio:rate5m" }],
      "fieldConfig": { "defaults": { "min": 0.9, "max": 1.0, "unit": "percentunit" } }
    },
    {
      "title": "Active Alerts",
      "type": "alertlist",
      "options": { "alertName": "", "dashboardAlerts": false, "groupBy": ["severity"] }
    }
  ]
}
```

## Replication & DR Readiness dashboard — key panels

This dashboard exists specifically to answer, at a glance and without running a single manual SQL
query, the questions an auditor or an incident commander asks first: "are we protected right now, and
when did we last prove it?"

```json
{
  "title": "Replication & DR Readiness",
  "panels": [
    {
      "title": "Replay Lag by Standby (seconds, log scale)",
      "type": "timeseries",
      "targets": [
        { "expr": "replay_lag_seconds{application_name=\"standby_a\"}", "legendFormat": "standby_a (sync)" },
        { "expr": "replay_lag_seconds{application_name=\"standby_read\"}", "legendFormat": "standby_read (async, local)" },
        { "expr": "replay_lag_seconds{application_name=\"standby_dr\"}", "legendFormat": "standby_dr (async, DR)" }
      ],
      "fieldConfig": { "defaults": { "custom": { "scaleDistribution": { "type": "log" } } } }
    },
    {
      "title": "Replication Slot WAL Retention",
      "type": "table",
      "targets": [{ "expr": "pg_replication_slots_wal_status" }]
    },
    {
      "title": "Recovery Test Pass/Fail History (90 days)",
      "type": "state-timeline",
      "targets": [
        { "expr": "qayd_restore_drill_last_result{drill=\"nightly\"}", "legendFormat": "Nightly restore" },
        { "expr": "qayd_restore_drill_last_result{drill=\"pitr_weekly\"}", "legendFormat": "Weekly PITR" },
        { "expr": "qayd_restore_drill_last_result{drill=\"dr_failover_quarterly\"}", "legendFormat": "Quarterly DR failover" }
      ]
    },
    {
      "title": "Days Since Last Passed Quarterly DR Drill",
      "type": "stat",
      "targets": [{ "expr": "(time() - qayd_restore_drill_last_run_timestamp{drill=\"dr_failover_quarterly\"}) / 86400" }],
      "fieldConfig": { "thresholds": { "steps": [
        { "value": 0, "color": "green" }, { "value": 100, "color": "yellow" }, { "value": 120, "color": "red" }
      ]}}
    }
  ]
}
```

## Access control and NOC mode

Dashboards are read-only for all authenticated staff via Grafana's Viewer role mapped from the
platform SSO group `qayd-engineering`; only the database-owner group can edit dashboard JSON (and, per
the provisioning convention above, that editing happens in git, not the Grafana UI — the Editor role is
not granted to anyone in production). The Fleet Overview dashboard runs in Grafana's kiosk/TV mode on a
physical display in the SRE area, auto-cycling to the Replication & DR Readiness dashboard every 60
seconds, so ambient awareness of fleet health does not depend on anyone actively opening Grafana.

# Incident Response

## Severity and roles

Incident severity reuses the `sev1`/`sev2`/`sev3` vocabulary from `# Alerts`, promoted from an
individual alert's label to a whole-incident classification the moment a human declares an incident
(a single `sev1` alert almost always starts an incident; a cluster of `sev2` alerts sometimes escalates
into one). Three roles are staffed for any `sev1` and any `sev2` that survives first triage:

| Role | Responsibility | Who |
|---|---|---|
| Incident Commander (IC) | Owns the incident timeline, makes the call/escalate/stand-down decisions, is NOT the person typing commands into `psql` | On-call SRE lead, rotates independently of the DB on-call |
| Database Owner (DBO) | Executes diagnosis and mitigation against Postgres directly, using this document's queries and the Runbooks below | Database on-call, paged directly by the alert |
| Communications Lead | Owns customer/status-page communication and internal stakeholder updates, freeing the IC and DBO to work the problem | Support/Comms on-call, paged for any `sev1` automatically |

For a `sev3` signal, no roles are paged; it is reviewed at the next capacity or engineering sync.

## Incident lifecycle

```
 ALERT FIRES
     │
     ▼
 ACKNOWLEDGE  (PagerDuty ack within 5 min for sev1, 30 min for sev2 — see # Alerts)
     │
     ▼
 TRIAGE  (DBO runs the "first query" from this document's relevant section — e.g. the blocking-chain
          query for a Locks alert, the pg_stat_replication query for a Replication alert — to confirm
          real impact vs. a false positive, and to classify which Runbook applies)
     │
     ├──── false positive / self-resolved ──► ACKNOWLEDGE + annotate the alert, close, no incident declared
     │
     ▼
 DECLARE INCIDENT  (IC assigned, incident channel opened, severity confirmed or revised)
     │
     ▼
 MITIGATE  (Runbook's Immediate Actions executed — the goal is restoring service, not root-causing yet;
            e.g. terminate an idle-in-transaction backend, fail traffic away from a lagging replica —
            root cause can wait, customer impact cannot)
     │
     ▼
 CONFIRM RESOLUTION  (the triggering alert has resolved AND the underlying metric has been stable for
                      at least one full evaluation window beyond the alert's `for:` duration)
     │
     ▼
 STAND DOWN  (IC closes the incident channel, Comms sends final customer update if one was sent)
     │
     ▼
 POSTMORTEM  (required for every sev1, and for any sev2 that took longer than 1 hour to mitigate;
              blameless, written within 5 business days, reviewed in the next engineering sync)
```

## Communication triggers

The Communications Lead sends an external status-page update only when customer-visible impact is
confirmed or highly likely — never speculatively on an internal saturation warning alone:

| Trigger | Action |
|---|---|
| Any `sev1` where the primary is unreachable, in split-brain, or refusing writes | Status page "Investigating" within 10 minutes of declaration |
| Any `sev1` where impact is confirmed scoped to a small number of tenants (e.g. one company's runaway query) | Direct, individual notification to affected tenant(s) only — no public status page entry |
| A `sev2` that has not resolved within 2 hours | Status page "Investigating," framed conservatively ("degraded performance for some customers") |
| Any incident, once mitigated | Status page "Resolved," with a one-paragraph plain-language summary — never internal alert names, query text, or table names |

## Escalation ladder

If the paged DBO does not acknowledge within the target window, or acknowledges but cannot make
progress within the timers below, PagerDuty auto-escalates:

| Elapsed since page | Action |
|---|---|
| 5 min (sev1) / 30 min (sev2), no ack | Escalate to secondary on-call DBO |
| 20 min, `sev1` unresolved | IC paged (if not already engaged) and joins the incident channel |
| 45 min, `sev1` unresolved | Database-owning engineering manager paged; go/no-go decision on invoking `DATABASE_BACKUP_RECOVERY.md`'s Disaster Recovery runbook if the incident is topology-level (primary loss, split-brain, region loss) |
| 90 min, `sev1` unresolved | CTO-level notification; Comms prepares a customer-facing incident summary regardless of current status-page state |

## Postmortem requirements

Every `sev1` postmortem is a written document (not a meeting-only discussion) containing, at minimum:
a timeline reconstructed from Alertmanager/PagerDuty/incident-channel timestamps (not memory), the
customer-visible impact window and estimated affected tenant count (queryable via the per-tenant
metrics in `# Health Monitoring`'s `pg_tenant_row_counts`-style breakdowns where relevant), the
technical root cause, what monitoring did and did not catch (explicitly: "would an existing alert in
this document have caught this sooner, and if not, what alert or threshold change closes that gap"),
and a list of follow-up actions each with an owner and a due date tracked to closure — an incident is
not closed until its follow-ups are, even though the incident channel itself is stood down immediately
on resolution.

# Capacity Planning

## Growth model

QAYD's primary cluster tier is provisioned against a baseline of roughly 5,000 active tenant companies
sharing a cluster (the same baseline `DATABASE_PERFORMANCE.md`'s Performance Targets table is written
against), with dedicated isolated clusters carved out for individual large tenants per
`DATABASE_ARCHITECTURE.md`. Capacity planning tracks four leading indicators monthly and reviews trend
projections quarterly, deliberately using the same recording-rule infrastructure already built for
alerting rather than a separate reporting pipeline:

```promql
# Disk: days until full at current fill rate (already defined as an alert in # Storage; reused here
# as a trend, not a threshold)
node_filesystem_avail_bytes{mountpoint="/var/lib/postgresql/data"}
  / clamp_min(-deriv(node_filesystem_avail_bytes{mountpoint="/var/lib/postgresql/data"}[30d]), 1) / 86400

# Connections: 30-day trend of peak daily connections-used percentage
max_over_time(qayd:pg_connections_used_pct[1d])

# CPU: 30-day trend of peak daily CPU utilization
max_over_time((100 * (1 - avg by (instance) (rate(node_cpu_seconds_total{mode="idle"}[5m]))))[1d:5m])

# Tenant growth: distinct active company_id count writing in the last 24h, trended monthly
count(count by (company_id) (pg_tenant_row_counts))
```

## Forecasting methodology

Each of the four series above is fit with a simple linear regression over the trailing 90 days
(computed in the monthly capacity-review job, not as a live Prometheus function — `deriv()` over long
windows is a reasonable real-time approximation but a proper least-squares fit on exported data is used
for the actual planning decision, since capacity decisions should not be made on a single noisy
window). The output of each review is a single number: projected date the metric crosses its Critical
threshold from `# Alerts` at the current growth rate. Any projection inside a 90-day horizon triggers a
procurement/scaling action; a projection between 90 and 180 days is flagged for the next quarterly
review; beyond 180 days requires no action beyond noting it.

```sql
-- Monthly capacity snapshot, written to a long-term ops table for trend review and audit
-- (Prometheus's 2-year remote_write retention is the primary store; this table is a convenient,
-- SQL-queryable secondary view used in the capacity-review meeting itself)
INSERT INTO ops.capacity_snapshots (
  captured_at, instance, disk_used_pct, connections_used_pct, cpu_p95_pct,
  active_tenant_count, wal_generation_mb_per_day
)
SELECT now(), 'qayd-pg-primary',
  (SELECT round(100 * (1 - (pg_database_size('qayd_prod')::numeric / (500::bigint * 1024*1024*1024))), 2)),
  -- illustrative placeholder for the Prometheus-sourced values normally joined in by the review job
  NULL, NULL,
  (SELECT count(DISTINCT company_id) FROM journal_lines WHERE created_at > now() - interval '1 day'),
  NULL;
```

## Vertical scaling ceiling and the scale-out decision

Per `DATABASE_PERFORMANCE.md`'s `# Scaling`, the primary is scaled up (more vCPU/RAM) as the first
response to sustained saturation, up to the platform's current largest supported tier
(approximately 64 vCPU, 512 GB–1 TB RAM); this comfortably serves QAYD's projected multi-thousand-tenant
shared-cluster tier. Monitoring's role in that decision is providing the *evidence* the scaling decision
is based on — a scale-up is only justified once the capacity review shows sustained (not transient)
pressure across at least two consecutive monthly reviews on the same resource dimension, cross-checked
against the underlying driver (traffic growth is a healthy reason to scale; a regression introduced by
a specific deploy is a bug to fix, not a scaling event, and monitoring must distinguish the two before
recommending spend):

```promql
# Did CPU pressure track tenant growth (scale event) or diverge from it (regression)?
# A ratio holding roughly steady over time supports "scale"; a ratio climbing supports "investigate a regression"
avg_over_time((100 * (1 - avg by (instance) (rate(node_cpu_seconds_total{mode="idle"}[5m]))))[30d:1h])
  /
avg_over_time(count(count by (company_id) (pg_tenant_row_counts))[30d:1h])
```

Once the vertical ceiling is approached (sustained utilization requiring the next tier up would exceed
the largest available instance type), the platform's documented next step is horizontal: routing more
read traffic to `qayd-pg-standby-read` (already the default for reporting/AI-agent analytics per
`# Replication`), and — per `DATABASE_ARCHITECTURE.md`'s stated future-proofing — sharding by region once
a single region's tenant count justifies it, which also directly serves the GCC data-residency
motivation already driving `qayd-pg-standby-dr`'s placement in `me-south-1`. Monitoring's specific
trigger for opening a sharding design review is the tenant-growth series above sustaining a trajectory
that would exceed the vertical ceiling within the 180-day planning horizon.

## Procurement and lead-time buffer

Self-managed EC2 instances (per the platform's hosting model — see `DATABASE_BACKUP_RECOVERY.md`'s
`# Purpose`) are not instantly resizable without a maintenance window (an EC2 instance-type change
requires a stop/start cycle, and a Nitro-based resize is typically minutes, not hours, but still
requires a planned window given the primary's write-availability requirements). Capacity planning
therefore budgets a fixed 21-day buffer between "a projection crosses the 90-day trigger" and "the
scale-up is scheduled," covering the maintenance-window scheduling, a pre-change load test against a
staging clone at the new instance size, and a rollback plan — the review itself is cheap; skipping the
buffer and improvising a resize under active saturation pressure is the failure mode this buffer
exists to prevent.

# Runbooks

Each runbook below is written to be executed under pressure by whichever on-call engineer is paged,
not only by someone who already has this document memorized. Every runbook follows the same shape:
Trigger (which alert from `# Alerts` fires this), Immediate Actions (stop-the-bleeding, safe to run
before full diagnosis), Diagnosis (confirm root cause), Mitigation (fix or work around it), and
Escalation (when to stop and hand this to someone else or to a bigger process).

## Runbook: High CPU / runaway query

**Trigger:** `PostgresCPUHighWarning` or `PostgresCPUHighCritical` (`# CPU`).

**Immediate actions:**
```sql
-- Identify the top CPU consumer right now
SELECT pid, now() - query_start AS duration, state, left(query, 200) AS query
FROM pg_stat_activity
WHERE state = 'active'
ORDER BY duration DESC
LIMIT 10;
```
Cross-check against `ops.pg_cpu_snapshots` (`# CPU`) for the actual top-20-by-CPU-percent list, which
is more precise than query duration alone.

**Diagnosis:** Is CPU concentrated in one `company_id` (per the CPU-attribution query), or spread
across many queries (broad load increase)? Is `iowait` also elevated (storage bottleneck masquerading
as CPU pressure — switch to the Storage runbook)?

**Mitigation:** For a single runaway query past a sane bound for its class, `pg_cancel_backend(pid)`
first; escalate to `pg_terminate_backend(pid)` only if cancel does not clear it within 10 seconds. For
a single tenant driving disproportionate load, apply the API-gateway rate limit for that `company_id`
(see the AI Layer and API module docs for the rate-limiting mechanism) rather than touching the
database further. For broad load growth, this is a capacity signal, not an incident — file it into the
next `# Capacity Planning` review instead of over-mitigating a healthy-traffic spike.

**Escalation:** If CPU stays critical for more than 15 minutes after mitigation attempts, or if killing
the top query does not reduce utilization (indicating the load is genuinely distributed), engage the
IC and consider a read-traffic failover to `qayd-pg-standby-read` to shed load from the primary.

## Runbook: Connections exhausted / PgBouncer pool exhaustion

**Trigger:** `PostgresConnectionsSaturatedCritical` (`# Connections`) or a PgBouncer
clients-waiting alert.

**Immediate actions:** Run the connection-by-application-and-state breakdown query from `# Connections`.
If `idle in transaction` dominates, this is almost always the cause — proceed to the longest-idle query.
If `active` dominates instead, this is a genuine load/CPU problem — switch to the High CPU runbook.

**Diagnosis:** The longest-idle-in-transaction query from `# Connections` identifies the specific
backend(s) holding connections open without doing work. Cross-reference `application_name` against
known Laravel services to identify which code path is leaking a transaction.

**Mitigation:** Run the emergency-remediation `pg_terminate_backend` statement from `# Connections`,
scoped by the confirmed idle duration threshold (10 minutes), never as a blanket kill of all
`idle in transaction` backends without first confirming none are legitimate long-running maintenance
operations (`pg_dump`, an online index build) — the query's `application_name NOT LIKE 'pg_dump%'`
guard is a minimum, not a substitute for looking at the list first.

**Escalation:** If terminating idle backends does not recover headroom within 5 minutes, or if the
leak recurs within the hour, this is a code-level bug requiring an emergency Laravel deploy (a missing
`DB::commit()`/`rollback()` in an exception path is the most common cause) — engage the on-call
backend engineer, not just the DBO.

## Runbook: Lock contention / blocked-backend pileup

**Trigger:** `PostgresBlockedBackendsCritical` (`# Locks`).

**Immediate actions:** Run the blocking-chain query from `# Locks` immediately — this is the single
query that turns "something is blocked" into "backend 48213 is blocking 6 others, and it has been in
an open transaction for 4 minutes."

**Diagnosis:** Is the blocking backend actively running a legitimate long transaction (e.g. a large
batch import), or is it idle-in-transaction (in which case this runbook converges with the Connections
runbook above)? Is the blocked set concentrated on one `company_id`'s `accounts` rows (expected
posting-queue serialization under a legitimate burst of concurrent postings for one company) or spread
across unrelated tenants (a bug — RLS/company scoping should make this impossible under normal
operation, and if it is happening, it may indicate a query missing its `company_id` predicate and
locking more broadly than intended)?

**Mitigation:** If the blocking transaction is confirmed idle or confirmed to be a runaway/erroneous
operation (never a legitimate in-flight financial posting — killing an in-flight journal posting
requires DBO judgment that the transaction has not partially committed side effects outside the DB),
terminate it. If it is a legitimate but slow long transaction, let it complete under close watch rather
than killing it, since Postgres's `FOR UPDATE` locking is protecting a real invariant.

**Escalation:** Any lock contention that spans more than one `company_id` unexpectedly is treated as a
correctness bug, not just a performance incident — engage the Accounting module owner in addition to
the standard escalation ladder, since it may indicate a missing tenant-scoping predicate.

## Runbook: Replication lag / standby falling behind

**Trigger:** Any `PostgresSyncStandbyLag*`, `PostgresDRStandbyLag*`, or standby-staleness alert
(`# Replication`).

**Immediate actions:** Run the `pg_stat_replication` query from `# Replication` on the primary to see
all three standbys' lag simultaneously — confirm whether this is isolated to one standby (host/network
issue on that standby) or all three (primary-side WAL generation or network egress issue).

**Diagnosis:** For `standby_a` (sync): check the standby's own CPU/disk/network (the Instance
Drill-Down dashboard, templated to that host) — a saturated sync standby is the most common cause of
degraded `synchronous_commit=remote_apply` behavior. For `standby_dr`: check for a genuine
`eu-central-1`-to-`me-south-1` network issue (AWS Service Health Dashboard) versus a resource issue on
the DR standby itself. For `standby_read`: check whether a long-running analytical query is holding
back WAL replay (a known Postgres standby behavior) — `hot_standby_feedback=on` prevents the primary
from vacuuming rows the query needs, but a sufficiently long query still delays that standby's own
replay progress.

**Mitigation:** For `standby_a` sync degradation, if the standby cannot be recovered quickly and the
lag is actively climbing, the DBO (with IC sign-off, since this trades durability for availability)
may temporarily change `synchronous_standby_names` to remove the degraded standby, restoring full write
availability at the cost of the zero-data-loss guarantee until the standby is repaired — this is a
deliberate, logged, IC-approved action, never a default/automatic response. For `standby_dr` lag, this
is monitored but rarely mitigated directly (it is async by design); log the duration and cause for the
next DR-readiness review. For `standby_read`, killing the long-running query that is blocking replay is
usually sufficient and low-risk (it is a read-only reporting query by definition of that standby's
role).

**Escalation:** Sync-standby degradation lasting more than 30 minutes, or any replication slot showing
`wal_status = 'lost'`, escalates to `sev1` regardless of the originating alert's severity and triggers a
review of whether `DATABASE_BACKUP_RECOVERY.md`'s Disaster Recovery runbook should be invoked instead
of continuing to wait for the standby to catch up.

## Runbook: Disk approaching full

**Trigger:** `PostgresDiskUsedCritical` or the "projected time to full" alert (`# Storage`).

**Immediate actions:** Run the three storage-attribution queries from `# Storage` in order (largest
tables, replication-slot WAL retention, per-tenant size) — in QAYD's operational history, a stuck
replication slot has been a more common cause of sudden WAL-volume storage pressure than genuine data
growth, so check it before assuming a capacity emergency.

**Diagnosis:** Is growth on the data volume (bloat, genuine tenant growth) or the WAL volume (stuck
slot, archiving failure — cross-check the WAL archiving lag metric from `# Backups`)? Is it
concentrated in one table (candidate for an emergency `VACUUM` or a schema-level fix) or one tenant
(candidate for the AI Layer/API rate-limiting path, same as the CPU runbook)?

**Mitigation:** For bloat, an off-hours `VACUUM (VERBOSE, ANALYZE)` on the specific offending table
(never a cluster-wide `VACUUM FULL` under pressure — it takes an exclusive lock and is a bigger outage
than the disk pressure it would fix). For a stuck replication slot with no active consumer, confirm
with the Replication runbook's diagnosis before dropping it (`SELECT pg_drop_replication_slot(...)`,
which permanently breaks that standby's ability to resume without a fresh base backup — an IC-approved
action). For genuine growth outpacing the current volume, this is an emergency capacity action: an EBS
volume can be grown online (`aws ec2 modify-volume` followed by an online filesystem resize), which
does not require a maintenance window the way an instance-type change does, and should be QAYD's first
response to a storage emergency specifically because of that.

**Escalation:** If disk-used percentage reaches the Critical threshold with less than 24 hours
projected to full and no single attributable cause is found within 30 minutes, engage the IC to
authorize the online EBS grow immediately rather than continuing diagnosis — buying time is cheaper
than being right immediately in a filling-disk emergency.

## Runbook: Backup failure / WAL archiving stalled

**Trigger:** `PostgresBackupStaleCritical`, `PostgresRestoreDrillFailed(Twice)`, or the WAL-archiving-lag
alert (`# Backups`).

**Immediate actions:** `sudo -u postgres pgbackrest --stanza=qayd check` from `qayd-pg-repo` (`# Backup
Validation` in `DATABASE_BACKUP_RECOVERY.md`) to get pgBackRest's own diagnosis of what is failing —
repository connectivity, disk space on `qayd-pg-repo`, or a stanza/configuration mismatch after a
recent host change.

**Diagnosis:** Cross-check `qayd-pg-repo`'s own disk usage (a full repo host is a common self-inflicted
cause) and its IAM role's ability to reach the S3 bucket (a rotated credential or a changed bucket
policy is the second most common cause, per `DATABASE_BACKUP_RECOVERY.md`'s encryption/IAM section).

**Mitigation:** Resolve the underlying blocker (free disk, restore IAM access) and manually trigger the
next scheduled job type immediately rather than waiting for its next cron slot, to close the staleness
window as fast as possible: `pgbackrest --stanza=qayd --type=incr backup`.

**Escalation:** Two consecutive nightly restore-drill failures is automatically `sev1` per the alert
rule itself (`PostgresRestoreDrillFailedTwice`) — this always engages the IC immediately, since it
means QAYD's actual DR posture, not just its monitoring, is currently degraded.

# Examples

## Example 1 — worked incident: sync standby lag caused by a saturated standby host

`PostgresSyncStandbyLagCritical` fires: `replay_lag_seconds{application_name="standby_a"}` has been
above 2s for 5 seconds, currently reading 6.8s. PagerDuty pages the on-call DBO; Alertmanager's
`postgres_replication` group also posts to `#qayd-db-watch` per the routing config in `# Alerts`.

DBO acknowledges within 90 seconds and runs the `pg_stat_replication` query from `# Replication` on
`qayd-pg-primary`:

```
 application_name | state     | sync_state | replay_lag
------------------+-----------+------------+------------
 standby_a        | streaming | sync       | 00:00:07.1
 standby_read      | streaming | async      | 00:00:00.4
 standby_dr        | streaming | async      | 00:00:41.2
```

Only `standby_a` is anomalous (`standby_dr`'s 41s is well inside its 300s budget). DBO opens the
Instance Drill-Down dashboard templated to `qayd-pg-standby-a` and finds CPU pegged at 97% with no
Postgres-side query activity — a strong signal the pressure is OS-level, not database-level. `node
processes` panel (a standard `node_exporter` textfile panel) shows an unrelated log-shipping agent
consuming the CPU after a misconfigured log-rotation triggered a large re-index of historical logs.

IC is engaged per the escalation ladder at the 20-minute mark (lag has not recovered). DBO and IC agree
on the runbook's documented mitigation path: rather than wait, they kill the log-shipping agent's
re-index job on `standby_a` directly (a non-database action, but on a database host, so within DBO/IC
authority). Lag recovers to under 100ms within 90 seconds. The alert resolves; Alertmanager posts the
resolution to Slack automatically (`send_resolved: true`).

Postmortem (required — this was `sev1`) identifies the root cause as a change to the log-rotation cron
on `standby_a` that was never applied consistently across all four hosts in `# Health Monitoring`'s
topology table, and adds a follow-up: a new `sev3` capacity/config-drift check comparing installed cron
jobs across all Postgres hosts, closing the specific gap the postmortem's mandatory "would an alert
have caught this sooner" question surfaced.

## Example 2 — worked capacity-planning calculation

The monthly capacity review pulls the 90-day linear fit for `qayd-pg-primary`'s disk-used percentage:
day 0 at 61%, day 90 at 74%, a slope of approximately +0.144 percentage points/day. Projected to the
Critical threshold of 90% (`# Storage`):

```
days_to_critical = (90 - 74) / 0.144 ≈ 111 days
```

111 days is inside the 180-day flag-for-next-review window but outside the 90-day mandatory-action
trigger. The review logs the projection, notes the primary driver (tenant count grew from 4,100 to
4,650 over the same period — a proportional, expected driver, confirmed via the CPU-vs-tenant-growth
ratio check in `# Capacity Planning`, which held flat rather than climbing), and schedules a follow-up
recheck at next month's review rather than an immediate storage expansion — an early EBS grow is cheap
and low-risk, but the review process still requires the trigger condition before acting, so that
capacity spend has a documented, repeatable justification trail rather than being triggered by whoever
happens to notice a chart.

## Example 3 — worked PagerDuty payload for a critical alert

The rendered Alertmanager-to-PagerDuty payload for `PostgresConnectionsSaturatedCritical` firing on
`qayd-pg-primary`:

```json
{
  "routing_key": "REDACTED_PAGERDUTY_INTEGRATION_KEY",
  "event_action": "trigger",
  "dedup_key": "PostgresConnectionsSaturatedCritical/qayd-pg-primary",
  "payload": {
    "summary": "qayd-pg-primary connections > 90% of max_connections",
    "source": "qayd-pg-primary.internal",
    "severity": "critical",
    "custom_details": {
      "value": "93.7",
      "threshold": "90",
      "signal": "saturation",
      "sev": "sev1",
      "runbook": "https://runbooks.qayd.internal/db/connection-exhaustion"
    }
  }
}
```

## Example 4 — worked slow-query detection and resolution

The `PostgresSlowQueryCountWarning`-class alert (`# Slow Queries`) fires: `slow_query_count` has risen
from a 7-day baseline of 4 to 19. The on-call engineer runs the top-queries-by-total-time query from
`DATABASE_PERFORMANCE.md`'s `# Monitoring` (the same query this document's `# Slow Queries` section
defers to) and finds a new normalized query, deployed two hours earlier, performing a full sequential
scan on `invoice_items` for a newly-added "items awaiting quality inspection" filter that shipped
without its supporting index. Because the query is a report-class query (not on the OLTP hot path),
the on-call engineer does not treat this as a `sev1` — the query is left running (it does not block
other transactions) while a hotfix index is prepared, reviewed, and applied online
(`CREATE INDEX CONCURRENTLY`) within the hour, per the indexing guidance in `DATABASE_PERFORMANCE.md`.
`slow_query_count` returns to baseline within one `pg_stat_statements` scrape interval of the index
build completing; no incident is declared, and the event is logged as a `sev3` note in the next
capacity/quality review as a reminder to add an index-coverage check to the PR template for new
filterable columns.

## Example 5 — worked meta-monitoring failure

The `me-south-1` DR-region shadow Prometheus's blackbox probe of the `eu-central-1` Prometheus's
`/-/healthy` endpoint starts failing. Because this is a monitoring-of-monitoring signal, it routes
through the DR-region Prometheus's independent Alertmanager directly to PagerDuty (bypassing the
primary region's Alertmanager entirely, since that is the very system whose health is in question).
The on-call engineer discovers the primary Prometheus's disk (a separate volume from any Postgres data
volume) filled from an unbounded local WAL retention misconfiguration introduced in a recent Prometheus
version upgrade. Because the underlying PostgreSQL fleet was never actually unhealthy during this
window — only the observability of it was degraded — no customer-facing incident is declared, but the
event is still treated as a `sev2` internally (per the severity table's "degraded but not yet
customer-visible" definition) precisely because a real, simultaneous database incident during this
window would have gone undetected by the primary path, relying entirely on the meta-monitoring layer
described in `# Alerts` to have caught anything at all.

# End of Document
