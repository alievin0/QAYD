# Database Monitoring & Observability — QAYD Database Layer
Version: 1.0
Status: Design Specification
Module: Database
Submodule: Database Monitoring & Observability
---

# Purpose

QAYD is an AI Financial Operating System. Every ledger post, invoice, payroll run, and tax
submission ultimately resolves to a write against PostgreSQL. If the database degrades — connection
exhaustion, replication lag, a runaway query, silent bloat — the effect is not a UI glitch, it is a
company unable to close its books or pay its employees. This document specifies the monitoring and
observability layer for QAYD's PostgreSQL 15+ primary database: what is measured, how it is measured,
where the thresholds are, what fires when a threshold is crossed, and how an on-call engineer resolves
the page. It is written for engineers operating the AWS + Cloudflare hosted stack (Laravel 12 / PHP
8.4+ backend, Redis cache/queue, Cloudflare R2 object storage) and assumes the multi-tenant schema
described in the canonical Database Design docs (`accounts`, `journal_entries`, `journal_lines`,
`ledger_entries`, `customers`, `vendors`, `products`, `invoices`, `bills`, `bank_accounts`,
`inventory_items`, `stock_movements`, `payroll_runs`, `tax_transactions`, `audit_logs`, etc.).

The monitoring layer has four jobs, in priority order:
1. Detect anything that threatens data integrity or availability before a customer notices.
2. Give engineers the query-level and lock-level detail needed to diagnose in minutes, not hours.
3. Feed capacity planning so storage, connections, and compute are provisioned ahead of growth.
4. Give Compliance and Security a durable, queryable trail of who touched what data and when,
   distinct from — but correlated with — the application-level `audit_logs` table.

Nothing in this document replaces `audit_logs` (the application's who/when/old/new/reason/ip/device
trail for business mutations). This document covers infrastructure- and query-level observability:
Prometheus/Grafana/Sentry, `pg_stat_statements`, `auto_explain`, replication topology, and the SQL used
to inspect Postgres internals directly.

# Monitoring Stack (Prometheus + postgres_exporter, Grafana dashboards, Sentry for errors)

QAYD standardizes on three tools, each with a distinct job. They are not interchangeable and none is
optional in production.

| Layer | Tool | Responsibility |
|---|---|---|
| Metrics collection | `postgres_exporter` (prometheus-community) | Scrapes `pg_stat_*` views, exposes Prometheus metrics |
| Metrics storage/alerting | Prometheus + Alertmanager | Time-series storage, rule evaluation, alert routing |
| Visualization | Grafana | Dashboards per audience (see `# Dashboards`) |
| Application/query error tracking | Sentry | PHP exceptions, slow-query breadcrumbs, N+1 detection |
| Log aggregation | CloudWatch Logs (RDS/Aurora) or self-managed Postgres logs shipped via Vector | `auto_explain`, `log_min_duration_statement`, connection/lock logs |

**Topology.** One `postgres_exporter` instance runs per database cluster (primary + each standby),
deployed as a sidecar container alongside the Laravel application tier on AWS ECS, or as a dedicated
small EC2/Fargate task if RDS/Aurora exporter integration is used instead. It connects to Postgres with
a dedicated, minimally-privileged monitoring role.

```sql
-- Dedicated monitoring role. Never reuse the application's connection role.
CREATE ROLE qayd_monitor WITH LOGIN PASSWORD '<secret, stored in AWS Secrets Manager>';
GRANT pg_monitor TO qayd_monitor;              -- built-in Postgres 10+ role: read-only stats access
GRANT CONNECT ON DATABASE qayd TO qayd_monitor;
-- pg_monitor already grants SELECT on pg_stat_activity, pg_stat_replication,
-- pg_stat_statements (once loaded), pg_stat_bgwriter, pg_stat_database, etc.
```

`postgres_exporter` is configured with a custom queries YAML so QAYD-specific business metrics (per-
tenant row counts, oldest unposted journal entry, etc.) are exposed alongside the generic Postgres
metrics:

```yaml
# postgres_exporter/queries.yaml
pg_qayd_unposted_journal_age_seconds:
  query: |
    SELECT company_id,
           EXTRACT(EPOCH FROM (now() - MIN(created_at))) AS age_seconds
    FROM journal_entries
    WHERE status = 'draft' AND deleted_at IS NULL
    GROUP BY company_id
  metrics:
    - company_id:
        usage: "LABEL"
    - age_seconds:
        usage: "GAUGE"
        description: "Age in seconds of the oldest draft journal entry per company"
```

**Scrape configuration** (Prometheus, one job per exporter target):

```yaml
scrape_configs:
  - job_name: 'postgres-primary'
    scrape_interval: 15s
    static_configs:
      - targets: ['pg-exporter-primary.internal:9187']
        labels: { role: primary, cluster: qayd-prod }
  - job_name: 'postgres-replica'
    scrape_interval: 15s
    static_configs:
      - targets: ['pg-exporter-replica-1.internal:9187']
        labels: { role: replica, cluster: qayd-prod }
```

**Sentry's role** is deliberately narrower: it captures PHP-level exceptions from Laravel (including
`QueryException`), tags each with `company_id` and `request_id` (matching the API response envelope's
`request_id`), and — via the `sentry/sentry-laravel` query-time breadcrumb integration — records the
last N SQL statements executed before an exception, so an engineer can correlate a Prometheus alert
("connection saturation on primary") with the exact Eloquent query that tipped it over.

```php
// config/sentry.php
'breadcrumbs' => [
    'sql_queries' => true,
    'sql_bindings' => false, // never log bound parameter VALUES — may contain financial PII
],
'traces_sample_rate' => 0.1,
```

Redis and Cloudflare R2 are monitored through their own exporters (`redis_exporter`,
CloudWatch/R2 usage metrics) but are out of scope for this document except where they interact with
database load (queue depth driving DB write bursts — see `# Capacity Planning`).

# Key Metrics

Every metric below is either a native `pg_stat_*` column exposed by `postgres_exporter` or a QAYD
custom query. Metric names shown are the Prometheus metric names as exported.

## Connections

```sql
-- Current vs max connections, by state
SELECT state, count(*) AS n
FROM pg_stat_activity
WHERE datname = 'qayd'
GROUP BY state
ORDER BY n DESC;

-- Percent of max_connections in use
SELECT
  (SELECT count(*) FROM pg_stat_activity) AS current,
  (SELECT setting::int FROM pg_settings WHERE name = 'max_connections') AS max_conn,
  round(100.0 * (SELECT count(*) FROM pg_stat_activity)
        / (SELECT setting::int FROM pg_settings WHERE name = 'max_connections'), 1) AS pct_used;
```

Exported as `pg_stat_activity_count{state=...}` and `pg_settings_max_connections`. QAYD's Laravel
workers use PgBouncer in `transaction` pooling mode in front of Postgres (see `# Edge Cases`), so
`pg_stat_activity` reflects *pooled* backend connections, not raw application connection count —
application-side pool exhaustion is monitored separately via PgBouncer's own `SHOW POOLS`.

## Transactions Per Second (TPS)

```sql
SELECT datname, xact_commit, xact_rollback,
       xact_commit + xact_rollback AS total_xacts
FROM pg_stat_database
WHERE datname = 'qayd';
```

`postgres_exporter` exposes `pg_stat_database_xact_commit` and `pg_stat_database_xact_rollback` as
counters; Grafana computes TPS with `rate(pg_stat_database_xact_commit{datname="qayd"}[5m])`. A
sustained rollback rate above 2% of total transactions is itself an alerting signal (see
`# Alerting Rules`) — it usually means application-level constraint violations or optimistic-lock
contention on `journal_entries`/`invoices`.

## Cache Hit Ratio

```sql
SELECT
  sum(heap_blks_hit)::float / GREATEST(sum(heap_blks_hit) + sum(heap_blks_read), 1) AS table_hit_ratio,
  sum(idx_blks_hit)::float / GREATEST(sum(idx_blks_hit) + sum(idx_blks_read), 1) AS index_hit_ratio
FROM pg_statio_user_tables;
```

Target: table hit ratio and index hit ratio both **>= 0.99** in steady state for `shared_buffers`
sized correctly (QAYD RDS instances use `shared_buffers = 25%` of instance RAM per AWS RDS Postgres
guidance). A sustained drop below 0.95 signals either a working-set-larger-than-memory situation
(instance undersized for tenant count) or a single tenant's report/export query scanning far more
than its fair share of buffer pool (see `# Per-Tenant Usage Metrics`).

## Replication Lag

```sql
-- Run on the PRIMARY: per-replica lag in bytes and estimated seconds
SELECT application_name, client_addr,
       pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn) AS lag_bytes,
       replay_lag  -- interval, requires track_commit_timestamp or hot_standby_feedback context
FROM pg_stat_replication;

-- Run on a REPLICA: how far behind wall-clock time is this replica
SELECT now() - pg_last_xact_replay_timestamp() AS replication_delay;
```

Exported as `pg_replication_lag_bytes` (primary-side, per standby) and `pg_stat_wal_receiver_last_msg_
receipt_time` derived lag (replica-side). QAYD's read replicas serve reporting queries (`# Related`
in the Reports module) and MUST NOT serve any read that a user could reasonably believe is
authoritative-as-of-now for a just-posted journal entry — the application layer is responsible for
routing read-your-writes queries to the primary; monitoring exists to guarantee lag stays low enough
that the acceptable staleness window is never violated silently.

## Lock Waits & Deadlocks

```sql
-- Current lock waits: who is blocking whom, right now
SELECT
  blocked.pid AS blocked_pid,
  blocked_activity.query AS blocked_query,
  blocking.pid AS blocking_pid,
  blocking_activity.query AS blocking_query,
  now() - blocked_activity.query_start AS blocked_duration
FROM pg_locks blocked
JOIN pg_stat_activity blocked_activity ON blocked_activity.pid = blocked.pid
JOIN pg_locks blocking ON blocking.locktype = blocked.locktype
  AND blocking.database IS NOT DISTINCT FROM blocked.database
  AND blocking.relation IS NOT DISTINCT FROM blocked.relation
  AND blocking.pid != blocked.pid
JOIN pg_stat_activity blocking_activity ON blocking_activity.pid = blocking.pid
WHERE NOT blocked.granted AND blocking.granted;

-- Deadlock counter (cumulative, per database)
SELECT datname, deadlocks FROM pg_stat_database WHERE datname = 'qayd';
```

Exported as `pg_stat_database_deadlocks` (counter; alert on `rate(...[5m]) > 0`). Lock-wait duration is
polled every 10s by a lightweight background job (not `postgres_exporter` itself, which only scrapes
counters) and pushed to Prometheus via the Pushgateway, because a lock wait can resolve between two
15-second scrapes and still have caused a user-visible timeout.

## Bloat

```sql
-- Approximate table + index bloat estimate (pgstattuple extension gives exact figures; this is the
-- cheap heuristic used for the always-on dashboard, pgstattuple used on-demand for confirmation)
SELECT
  schemaname, relname,
  n_dead_tup, n_live_tup,
  round(100.0 * n_dead_tup / GREATEST(n_live_tup + n_dead_tup, 1), 2) AS dead_pct,
  last_autovacuum, last_autoanalyze
FROM pg_stat_user_tables
WHERE n_dead_tup > 10000
ORDER BY dead_pct DESC
LIMIT 25;
```

High-write, soft-deleted tables — `journal_lines`, `ledger_entries`, `stock_movements`,
`audit_logs` — are the most bloat-prone in QAYD because rows are updated (e.g. `deleted_at` set) far
more than they are physically removed. `dead_pct > 20` on any of these triggers investigation into
autovacuum tuning (see `# Alerting Rules`).

## Disk Usage

```sql
SELECT
  pg_size_pretty(pg_database_size('qayd')) AS database_size,
  pg_size_pretty(sum(pg_total_relation_size(relid))) AS tables_and_indexes
FROM pg_stat_user_tables;

-- Top 20 largest tables including indexes and TOAST
SELECT relname,
       pg_size_pretty(pg_total_relation_size(relid)) AS total_size,
       pg_size_pretty(pg_relation_size(relid)) AS table_only,
       pg_size_pretty(pg_indexes_size(relid)) AS indexes_only
FROM pg_stat_user_tables
ORDER BY pg_total_relation_size(relid) DESC
LIMIT 20;
```

Exported at the infrastructure level via CloudWatch (`FreeStorageSpace` for RDS) and at the
Postgres level via a scheduled custom query feeding `pg_qayd_database_size_bytes`. Both are alerted
(see `# Alerting Rules`) since CloudWatch free-space alone does not distinguish "disk full" from
"a single runaway table."

## Slow Queries

Covered in full in `# pg_stat_statements & Query Insights` and `# Slow Query & auto_explain` below;
the headline metric exported to Prometheus is `pg_stat_statements_mean_exec_time_seconds` per
`queryid`, and the count of statements currently exceeding the 500ms `log_min_duration_statement`
threshold in the last scrape window.

# pg_stat_statements & Query Insights

`pg_stat_statements` is loaded on every QAYD Postgres instance via `shared_preload_libraries` (requires
a restart to enable, so it is baked into the RDS parameter group / instance provisioning, never added
ad hoc):

```conf
# postgresql.conf (or RDS/Aurora parameter group equivalent)
shared_preload_libraries = 'pg_stat_statements,auto_explain'
pg_stat_statements.max = 10000
pg_stat_statements.track = all          -- track nested statements (functions), not just top-level
pg_stat_statements.track_utility = off  -- skip DDL/utility noise
pg_stat_statements.save = on
```

```sql
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
```

**Top queries by total time** (the single most useful triage query — finds the query that costs the
cluster the most aggregate time, which is not always the slowest individual execution):

```sql
SELECT
  queryid,
  round(total_exec_time::numeric, 2) AS total_ms,
  calls,
  round(mean_exec_time::numeric, 2) AS mean_ms,
  round((100 * total_exec_time / sum(total_exec_time) OVER ())::numeric, 2) AS pct_of_all,
  query
FROM pg_stat_statements
ORDER BY total_exec_time DESC
LIMIT 20;
```

**Queries with the worst cache behavior** (candidates for a missing index):

```sql
SELECT queryid, calls,
       shared_blks_hit, shared_blks_read,
       round(100.0 * shared_blks_hit / GREATEST(shared_blks_hit + shared_blks_read, 1), 2) AS hit_pct,
       query
FROM pg_stat_statements
WHERE shared_blks_read > 1000
ORDER BY shared_blks_read DESC
LIMIT 20;
```

**Per-tenant attribution.** Because `queryid` is a hash of the normalized query text (parameters
stripped), `pg_stat_statements` alone cannot tell you which `company_id` is driving load for a
parameterized query like `SELECT * FROM invoices WHERE company_id = $1 AND status = $2`. QAYD
attributes cost to tenants via `auto_explain` sampling (below) plus the application tagging every
query with a SQL comment via Laravel's query builder macro, which Postgres preserves verbatim in
`pg_stat_activity.query` (though NOT in `pg_stat_statements.query`, which normalizes away literals and
most comments depending on `pg_stat_statements.track`):

```php
// app/Support/Database/TenantQueryTag.php — appended by a query-execution listener
DB::listen(function ($query) {
    // Tags pg_stat_activity.query for in-flight correlation; does not affect pg_stat_statements
    // aggregation, which remains normalized by design.
});
```

```sql
-- What is company 4821 running RIGHT NOW, and for how long
SELECT pid, now() - query_start AS running_for, query
FROM pg_stat_activity
WHERE query ILIKE '%/* company:4821 */%'
ORDER BY running_for DESC;
```

**Resetting statistics** (only after a deploy that changes query shape materially, so historical
comparisons are not muddied by pre/post-deploy averaging):

```sql
SELECT pg_stat_statements_reset();
```

This is a manual, audited operation — never automated in a cron — because resetting hides evidence
during an active incident.

# Health Checks & Liveness/Readiness Probes

QAYD's Laravel API runs on AWS ECS Fargate behind an Application Load Balancer, with three distinct
probe types, each answering a different question and each backed by a different query cost budget.

**Liveness** — "is the PHP-FPM/Laravel process alive at all?" No database dependency. Returning
healthy here with a dead database is correct: liveness failing kills and restarts the container, which
would be actively harmful during a database outage (it would not help, and it would add container
churn to an already-degraded system).

```php
// routes/api.php
Route::get('/health/live', fn () => response()->json(['status' => 'ok']));
```

**Readiness** — "should the load balancer send this container traffic right now?" Checks a
cheap, bounded-cost database round trip plus Redis. Must complete in well under 1 second and must not
itself be a query that could contend with production traffic.

```php
// app/Http/Controllers/HealthController.php
public function ready(): JsonResponse
{
    $checks = ['database' => false, 'redis' => false];
    try {
        DB::select('SELECT 1');           // no table access, no lock, no plan cost worth mentioning
        $checks['database'] = true;
    } catch (\Throwable $e) {
        report($e);
    }
    try {
        Redis::ping();
        $checks['redis'] = true;
    } catch (\Throwable $e) {
        report($e);
    }
    $healthy = ! in_array(false, $checks, true);
    return response()->json(['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks],
        $healthy ? 200 : 503);
}
```

```yaml
# ALB target group health check
HealthCheckPath: /api/health/ready
HealthCheckIntervalSeconds: 10
HealthyThresholdCount: 2
UnhealthyThresholdCount: 3
HealthCheckTimeoutSeconds: 5
```

**Deep health** — an operator-facing, NOT load-balancer-facing endpoint (protected by an internal-
only permission, `ops.health.deep`) used by the on-call runbook, not by automated infra. It runs the
connections/replication/bloat snapshot queries above and returns a structured summary:

```php
Route::middleware(['auth:sanctum', 'permission:ops.health.deep'])
    ->get('/health/deep', [HealthController::class, 'deep']);
```

```json
{
  "success": true,
  "data": {
    "connections": { "current": 84, "max": 200, "pct_used": 42.0 },
    "replication": [{ "standby": "replica-1", "lag_bytes": 40960, "lag_seconds": 0.3 }],
    "cache_hit_ratio": 0.994,
    "oldest_draft_journal_entry_seconds": 312,
    "longest_running_query_seconds": 4.1
  },
  "message": "Deep health snapshot",
  "errors": [],
  "meta": { "pagination": null },
  "request_id": "a1b2c3d4-...",
  "timestamp": "2026-07-16T12:00:00Z"
}
```

**Database-level liveness** (used by RDS/Aurora's own failover logic and by PgBouncer's health check,
independent of the application):

```sql
-- PgBouncer periodic health-check query (configured in pgbouncer.ini: server_check_query)
SELECT 1;
```

RDS Multi-AZ handles primary-instance liveness and automatic failover itself; QAYD does not build a
custom failover trigger on top of it (see `# Replication & Failover Monitoring`), but the application
readiness probe above is what determines whether ECS keeps routing traffic to a given API container
during that failover window.

# Alerting Rules (thresholds for lag, connections saturation, disk, long transactions, failed backups)

Alerting rules live in Prometheus `rules/*.yml`, loaded by the Prometheus server and routed through
Alertmanager to PagerDuty (critical → pages on-call) or Slack `#qayd-db-alerts` (warning → visible,
non-paging). Every rule carries a `runbook_url` annotation pointing at the matching entry in
`# Runbooks For Common Alerts`.

```yaml
# rules/postgres.yml
groups:
  - name: postgres.availability
    rules:
      - alert: PostgresConnectionSaturationHigh
        expr: |
          100 * pg_stat_activity_count{datname="qayd"}
          / on() pg_settings_max_connections > 80
        for: 5m
        labels: { severity: warning }
        annotations:
          summary: "Connections at {{ $value | printf \"%.0f\" }}% of max_connections"
          runbook_url: "https://docs.qayd.internal/runbooks/db-connection-saturation"

      - alert: PostgresConnectionSaturationCritical
        expr: |
          100 * pg_stat_activity_count{datname="qayd"}
          / on() pg_settings_max_connections > 95
        for: 2m
        labels: { severity: critical }
        annotations:
          summary: "Connections at {{ $value | printf \"%.0f\" }}% of max_connections — imminent refusal"
          runbook_url: "https://docs.qayd.internal/runbooks/db-connection-saturation"

  - name: postgres.replication
    rules:
      - alert: ReplicationLagHigh
        expr: pg_replication_lag_bytes > 50 * 1024 * 1024   # 50 MB
        for: 5m
        labels: { severity: warning }
        annotations:
          summary: "Replica {{ $labels.application_name }} lagging {{ $value | humanize1024 }}B"
          runbook_url: "https://docs.qayd.internal/runbooks/replication-lag"

      - alert: ReplicationLagCritical
        expr: pg_replication_lag_bytes > 500 * 1024 * 1024  # 500 MB
        for: 2m
        labels: { severity: critical }
        annotations:
          summary: "Replica {{ $labels.application_name }} critically behind — remove from read pool"
          runbook_url: "https://docs.qayd.internal/runbooks/replication-lag"

  - name: postgres.disk
    rules:
      - alert: DiskUsageHigh
        expr: (1 - aws_rds_free_storage_space_bytes / aws_rds_allocated_storage_bytes) * 100 > 80
        for: 15m
        labels: { severity: warning }
        annotations: { summary: "RDS storage {{ $value | printf \"%.1f\" }}% used" }

      - alert: DiskUsageCritical
        expr: (1 - aws_rds_free_storage_space_bytes / aws_rds_allocated_storage_bytes) * 100 > 92
        for: 5m
        labels: { severity: critical }
        annotations: { summary: "RDS storage {{ $value | printf \"%.1f\" }}% used — storage autoscaling may not outrun this" }

  - name: postgres.transactions
    rules:
      - alert: LongRunningTransaction
        expr: pg_stat_activity_max_tx_duration_seconds{datname="qayd"} > 300
        for: 1m
        labels: { severity: warning }
        annotations:
          summary: "Transaction open > 5 minutes — blocks autovacuum on touched tables"
          runbook_url: "https://docs.qayd.internal/runbooks/long-running-transaction"

      - alert: LongRunningTransactionCritical
        expr: pg_stat_activity_max_tx_duration_seconds{datname="qayd"} > 1800
        for: 1m
        labels: { severity: critical }
        annotations:
          summary: "Transaction open > 30 minutes — likely holding row locks on financial tables"

      - alert: DeadlockDetected
        expr: increase(pg_stat_database_deadlocks{datname="qayd"}[5m]) > 0
        labels: { severity: warning }
        annotations: { summary: "{{ $value }} deadlock(s) in the last 5 minutes" }

  - name: postgres.backups
    rules:
      - alert: BackupFailed
        expr: aws_rds_backup_retention_period_status == 0 or increase(qayd_backup_job_failures_total[1d]) > 0
        for: 5m
        labels: { severity: critical }
        annotations:
          summary: "Automated backup failed or retention misconfigured"
          runbook_url: "https://docs.qayd.internal/runbooks/backup-failure"

      - alert: BackupStale
        expr: (time() - qayd_last_successful_backup_timestamp_seconds) > 86400 + 3600
        labels: { severity: critical }
        annotations: { summary: "No successful backup in > 25 hours" }
```

`qayd_backup_job_failures_total` and `qayd_last_successful_backup_timestamp_seconds` are emitted by
the backup Lambda (AWS Backup / RDS automated snapshot completion hook) via a CloudWatch → Prometheus
exporter bridge, since RDS automated backups do not natively expose Prometheus metrics.

# Slow Query & auto_explain

`log_min_duration_statement` flags slow statements in the Postgres log; `auto_explain` additionally
captures the *plan*, which is what actually explains *why* a query is slow (missing index, bad row
estimate, sequential scan on a large table).

```conf
# postgresql.conf
log_min_duration_statement = 500        # log any statement taking > 500ms, full text
auto_explain.log_min_duration = 500     # attach EXPLAIN output for the same threshold
auto_explain.log_analyze = true         # include actual row counts/timings, not just estimates
auto_explain.log_buffers = true         # include buffer hit/read stats in the plan
auto_explain.log_timing = true
auto_explain.log_nested_statements = true  # capture slow statements inside PL/pgSQL functions
auto_explain.log_format = json          # structured, machine-parseable by the log shipper
```

Because `auto_explain.log_analyze = true` re-executes the query's actual work to gather real timings
(it does not add a second execution — `EXPLAIN ANALYZE` instruments the single execution already
happening), it has non-zero overhead. QAYD samples rather than logs every slow statement in
production once volume is high, via `auto_explain.sample_rate` (Postgres 13+):

```conf
auto_explain.sample_rate = 0.1   # explain-log 10% of statements crossing the duration threshold
```

Logs are shipped (Vector agent, or CloudWatch Logs subscription filter for RDS) into the same log
pipeline as application logs, tagged `source=postgres`, and parsed into a `slow_queries` searchable
index in the observability backend so an engineer can filter by `company_id` (extracted from the SQL
comment tag described above) and by table name pulled from the JSON plan's `Relation Name` fields.

**Manual EXPLAIN workflow** for a specific reported-slow query — always run `EXPLAIN (ANALYZE,
BUFFERS, FORMAT JSON)` against a read replica or a point-in-time clone for anything that would mutate
data, and against the primary read-only for SELECT-only investigation:

```sql
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT je.id, je.entry_date, jl.debit, jl.credit
FROM journal_entries je
JOIN journal_lines jl ON jl.journal_entry_id = je.id
WHERE je.company_id = 4821
  AND je.status = 'posted'
  AND je.entry_date BETWEEN '2026-01-01' AND '2026-06-30'
  AND je.deleted_at IS NULL;
```

A healthy plan for this query shows an `Index Scan` (or `Bitmap Index Scan`) on a composite index
`(company_id, status, entry_date)` rather than a `Seq Scan` — see the composite indexing guidance in
the Database Design doc for `journal_entries`. `Rows Removed by Filter` much larger than `Rows` in the
`ANALYZE` output is the standard signature of a missing or non-selective index.

# Replication & Failover Monitoring

QAYD runs AWS RDS PostgreSQL Multi-AZ for the primary (synchronous standby for zero-data-loss
failover) plus one or more asynchronous read replicas for reporting load. Both are monitored, but
differently, because their failure modes differ.

**Multi-AZ standby** (synchronous, not directly queryable by applications): monitored via
`aws_rds_multi_az_pending_maintenance` and CloudWatch's `DatabaseConnections`/failover event
notifications, not via `pg_stat_replication` — the Multi-AZ standby does not appear as a client of
`pg_stat_replication` on RDS in the same way a self-managed streaming replica does. RDS event
subscriptions notify on `Failover` category events directly to an SNS topic wired to PagerDuty.

```bash
aws rds describe-event-subscriptions --subscription-name qayd-prod-failover
aws rds create-event-subscription \
  --subscription-name qayd-prod-failover \
  --sns-topic-arn arn:aws:sns:me-south-1:xxxx:qayd-db-failover \
  --source-type db-instance \
  --event-categories failover failure
```

**Read replicas** (asynchronous, application-queryable): monitored via the `pg_stat_replication`
queries in `# Key Metrics` above, run from the primary, plus `ReplicaLag` CloudWatch metric per
replica. QAYD's Laravel database config routes connections using a read/write split
(`config/database.php` `read`/`write` arrays); the application layer additionally checks a Redis-
cached "max acceptable replica lag" flag before routing reporting queries, so if lag crosses the
`ReplicationLagCritical` threshold, new reporting queries are automatically routed to the primary
(degraded throughput, correct data) rather than silently serving stale reads.

```php
// config/database.php
'pgsql' => [
    'read' => [
        'host' => [env('DB_READ_HOST_1'), env('DB_READ_HOST_2')],
    ],
    'write' => [
        'host' => [env('DB_WRITE_HOST')],
    ],
    'sticky' => true,   // a write followed by a read in the same request hits the primary
    ...
],
```

**Failover drill.** QAYD runs a scheduled quarterly failover drill (forced `reboot-with-failover` on
a staging Multi-AZ cluster, never on production ad hoc) and records: time-to-detect (Prometheus alert
fired), time-to-recover (readiness probe green again), and any queries that errored non-idempotently
during the cutover window. Results feed the RTO/RPO figures quoted to Compliance.

**Split-brain / stale-primary protection.** Because Laravel's `sticky` read-after-write routing
depends on knowing which host is *actually* primary, the deployment's DNS-based writer endpoint (RDS
cluster endpoint) is the single source of truth — the application never hardcodes an instance
hostname, only the cluster endpoint, so a failover is transparent to connection routing (aside from
the brief DNS-propagation/connection-drop window, which the readiness probe and ECS health checks
absorb).

# Capacity Planning & Growth Trends

Capacity planning combines three signals: raw storage growth, connection/CPU growth relative to
tenant count, and queue-driven write bursts.

```sql
-- Table growth trend: run weekly, store results in a time-series table for trend charts
CREATE TABLE db_capacity_snapshots (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    snapshot_date DATE NOT NULL DEFAULT CURRENT_DATE,
    relname       TEXT NOT NULL,
    total_bytes   BIGINT NOT NULL,
    row_estimate  BIGINT NOT NULL
);

INSERT INTO db_capacity_snapshots (relname, total_bytes, row_estimate)
SELECT relname, pg_total_relation_size(relid), n_live_tup
FROM pg_stat_user_tables;
```

```sql
-- Week-over-week growth rate per table, most recent 8 weeks
SELECT relname,
       snapshot_date,
       total_bytes,
       total_bytes - LAG(total_bytes) OVER (PARTITION BY relname ORDER BY snapshot_date) AS delta_bytes
FROM db_capacity_snapshots
WHERE snapshot_date > CURRENT_DATE - INTERVAL '8 weeks'
ORDER BY relname, snapshot_date;
```

This feeds a Grafana panel projecting when the current RDS storage allocation (with storage
autoscaling enabled, but autoscaling has a maximum ceiling per `max_allocated_storage`) will be
exhausted at the current growth rate, and a quarterly capacity review with three inputs: (1) tenant
count growth forecast from Sales/Onboarding, (2) bytes-per-tenant-per-month observed for the highest-
volume existing tenants (used as the worst-case per-tenant footprint), (3) queue-driven burst
capacity — Redis queue depth for `journal-posting`, `payroll-calculation`, and `inventory-valuation`
jobs is graphed alongside DB CPU, because these are the workloads most likely to produce a write
burst that outpaces steady-state IOPS provisioning.

```sql
-- Rows written per day, by table, last 30 days — used to project IOPS need
SELECT date_trunc('day', created_at) AS day, count(*) AS rows_written
FROM journal_lines
WHERE created_at > now() - INTERVAL '30 days'
GROUP BY 1 ORDER BY 1;
```

Instance-class upgrade decisions (vCPU/RAM) are triggered when: cache hit ratio trends below 0.97 for
two consecutive weeks (working set outgrowing `shared_buffers`), or CPU utilization exceeds 60% at
p95 for a full week (headroom for burst absorption before it becomes a `LongRunningTransaction` or
`ConnectionSaturation` incident).

# Per-Tenant Usage Metrics

Because every business table carries `company_id`, per-tenant cost attribution is a first-class,
always-available query, not an afterthought:

```sql
-- Storage footprint per tenant, top 20 heaviest tenants
SELECT company_id,
       count(*) AS journal_lines,
       pg_size_pretty(count(*) * 200) AS approx_bytes  -- 200 = avg row width estimate; refine with pgstattuple for precision
FROM journal_lines
WHERE deleted_at IS NULL
GROUP BY company_id
ORDER BY journal_lines DESC
LIMIT 20;

-- Write volume per tenant per day, across the core transactional tables
SELECT company_id, date_trunc('day', created_at) AS day, count(*) AS writes
FROM (
    SELECT company_id, created_at FROM invoices
    UNION ALL SELECT company_id, created_at FROM bills
    UNION ALL SELECT company_id, created_at FROM journal_entries
    UNION ALL SELECT company_id, created_at FROM stock_movements
) x
WHERE created_at > now() - INTERVAL '7 days'
GROUP BY company_id, day
ORDER BY writes DESC;
```

These feed two operational uses: (1) noisy-neighbor detection — a single tenant issuing an
unbounded report export or bulk import is visible immediately as an outlier and can be rate-limited
(`429` per the API convention) without needing to wait for cluster-wide symptoms; (2) usage-based
plan/tier enforcement signals handed to Billing (a domain event `usage.threshold_exceeded` is
published when a tenant's row count or query volume crosses their plan's fair-use limit, consumed by
the Billing module, never enforced by directly throttling inside a shared connection pool in a way
that would degrade other tenants).

```sql
-- Per-tenant Prometheus metric, computed on a 5-minute schedule, one gauge per company
-- Exposed as qayd_tenant_journal_line_count{company_id="4821"}
```

# Audit & Security Monitoring (unusual access, RLS violations)

This section is infrastructure-level security monitoring — it complements, and cross-references, the
row-level tenant isolation enforced in application queries and (where enabled) native PostgreSQL Row-
Level Security policies on financial tables.

```sql
-- RLS is enabled on all financial tables as defense-in-depth behind the application's own
-- company_id scoping. Policy violations do not throw a distinct "RLS violation" error class in
-- Postgres — a query that would return zero rows due to RLS looks identical to a legitimately
-- empty result. Detection therefore happens by comparing intended scope to executed scope:
CREATE POLICY tenant_isolation_journal_entries ON journal_entries
    USING (company_id = current_setting('app.current_company_id')::bigint);
```

```php
// Every request sets the session-local GUC before any query executes, inside a DB transaction
// or via a connection-level `SET` scoped to the request lifecycle:
DB::statement("SET app.current_company_id = ?", [$companyId]);
```

**Detecting a missing `SET app.current_company_id`** (the actual dangerous failure mode — not a
"violation" so much as an omission that would make RLS a no-op for that connection) is monitored by
asserting, in the connection-acquisition middleware, that the GUC is non-empty before any query runs;
a Sentry error is raised and the request is rejected with `500` rather than silently proceeding.

**Unusual access patterns** are monitored via `pgaudit` (the PostgreSQL audit extension), configured
to log role-level session/object access separately from the statement-level `auto_explain`/slow-query
logging:

```conf
shared_preload_libraries = 'pg_stat_statements,auto_explain,pgaudit'
pgaudit.log = 'write, ddl, role'   # log all writes, schema changes, and role/permission changes
pgaudit.log_relation = on         # log each relation touched, not just the top-level statement
```

```sql
-- Off-hours access anomaly: any DELETE/UPDATE against financial tables outside business hours,
-- from a role other than the application's own service role (application deletes should always
-- be soft, via UPDATE ... SET deleted_at; a hard DELETE from any role but a scoped admin task
-- role is itself the anomaly)
-- (queried from the shipped pgaudit log index, not live pg_stat_activity)
```

Alerting rule for this class of event:

```yaml
- alert: UnexpectedHardDeleteOnFinancialTable
  expr: increase(qayd_pgaudit_hard_delete_total{table=~"journal_lines|ledger_entries|invoices|bills|payroll_runs|tax_transactions"}[5m]) > 0
  labels: { severity: critical }
  annotations:
    summary: "Hard DELETE detected on immutable financial table {{ $labels.table }}"
    runbook_url: "https://docs.qayd.internal/runbooks/unexpected-hard-delete"
```

Every hit on this alert triggers an immediate correlation against `audit_logs` (application layer)
and the database session's `pg_stat_activity.usename`/`client_addr` to identify whether it originated
from the application's own service role (a bug — should never hard-delete) or an out-of-band
connection (a security incident — credential compromise or an engineer bypassing the ORM directly
against production, both of which require immediate credential rotation and incident response).

# Dashboards (which panels per audience)

QAYD maintains three Grafana dashboards, not one, because the primary/CFO-facing audience and the
on-call engineer audience need different altitudes of information and mixing them dilutes both.

| Dashboard | Audience | Panels |
|---|---|---|
| **DB Overview** | Engineering leadership, weekly review | TPS trend (7d), storage growth (90d), cache hit ratio (30d), replication lag (7d), incident count by severity (30d), top 5 slowest queries by total time (7d) |
| **DB On-Call** | On-call engineer, incident response | Live connections by state, live lock waits, live replication lag (all replicas), active long-running transactions (>60s) with query text, deadlock counter, autovacuum activity per table, disk free % with projected days-to-full, recent Alertmanager firing/resolved log |
| **DB Capacity** | Engineering + Finance (infra cost planning), monthly | Per-tenant storage top 20, table growth trend (52 weeks), instance CPU/RAM utilization p50/p95/p99 (90d), queue depth vs DB write rate correlation, projected instance-class upgrade date |

Each panel on **DB On-Call** links directly (Grafana data link) to the exact SQL query in this
document that would be run manually to get the same data, so an engineer under pressure at 3 AM does
not have to hunt for the query — the dashboard panel's "View in Postgres" link pre-fills it.

# Runbooks For Common Alerts

**`PostgresConnectionSaturationCritical`**
1. Run the connections-by-state query (`# Key Metrics — Connections`). If most connections are
   `idle in transaction`, find the offending sessions and their age:
   `SELECT pid, usename, now() - xact_start AS age, query FROM pg_stat_activity WHERE state = 'idle in transaction' ORDER BY age DESC;`
2. If a session has been idle-in-transaction for over 5 minutes, terminate it:
   `SELECT pg_terminate_backend(<pid>);` — never `pg_cancel_backend` for this case, it will not
   release the transaction.
3. Check PgBouncer pool utilization (`SHOW POOLS;` via `psql` against the PgBouncer admin port) —
   if PgBouncer itself is exhausted, this is a connection-leak bug in the application (a queue
   worker or scheduled job not releasing its connection), not a Postgres capacity problem; file a
   P1 bug against the offending worker.
4. If genuinely capacity-bound (real traffic growth), scale `max_connections` only after confirming
   `shared_buffers`/`work_mem` headroom — raising `max_connections` on a memory-constrained instance
   trades one incident for a worse one (OOM).

**`ReplicationLagCritical`**
1. Confirm the application's read-routing has already failed over to the primary (check the Redis
   flag set by the lag-monitor job). If not, set it manually:
   `redis-cli SET qayd:db:replica_lag_critical 1 EX 600`
2. Check replica CPU/IO — lag is almost always caused by the replica being under-provisioned for
   replay plus concurrent reporting-query load, or by a long-running query on the replica itself
   holding back WAL replay (`hot_standby_feedback` interaction). Identify and terminate the
   offending replica-side query if found.
3. If lag does not recover within 15 minutes, treat the replica as failed: remove it from the read
   pool permanently (not just via the Redis flag) and page for a replacement replica build.

**`LongRunningTransactionCritical`**
1. Identify it: `SELECT pid, usename, now() - xact_start AS age, query FROM pg_stat_activity WHERE state != 'idle' AND now() - xact_start > interval '30 minutes' ORDER BY age DESC;`
2. Check what it holds: run the lock-wait query (`# Key Metrics — Lock Waits`) to see if anything is
   actually blocked on it. If nothing is blocked yet, it is still a bloat/vacuum risk but not an
   active incident — schedule a fix, do not panic-kill it if it is a legitimate long batch (e.g.
   month-end close) that the business is depending on completing.
3. If something IS blocked and the transaction is confirmed non-critical (verify with the owning
   team before killing anything touching `journal_entries`/`payroll_runs`), terminate it:
   `SELECT pg_terminate_backend(<pid>);`

**`BackupFailed` / `BackupStale`**
1. Check AWS Backup / RDS console for the specific failure reason (storage quota, IAM permission,
   snapshot window collision with maintenance window).
2. Trigger a manual on-demand snapshot immediately as a stopgap while root-causing:
   `aws rds create-db-snapshot --db-instance-identifier qayd-prod --db-snapshot-identifier qayd-manual-$(date +%Y%m%d%H%M)`
3. This is always `severity: critical` regardless of time of day — an unrecoverable multi-day
   backup gap on a financial ledger is a business-continuity risk, not an inconvenience.

**`DeadlockDetected`**
1. Deadlocks are logged with full detail (both queries, both lock modes) in the Postgres error log
   at `LOG` level automatically (`log_lock_waits` and Postgres's built-in deadlock detector logging
   need no extra config). Pull the log entry for the exact queries involved.
2. Single, infrequent deadlocks between two legitimate concurrent postings (e.g. two journal entries
   touching the same control account in different lock order) are usually resolved by Laravel's
   retry-on-deadlock wrapper (`DB::transaction($callback, $attempts = 3)`), already used everywhere
   journal posting occurs. Confirm the retry succeeded on the paired request in application logs.
3. If deadlocks recur repeatedly on the same table pair, it is a lock-ordering bug, not noise — file
   a P1 to fix the code path to acquire locks in a consistent order (e.g. always lock the lower
   `account_id` first).

# Edge Cases

**PgBouncer transaction pooling hides session-level state.** `SET app.current_company_id` (the RLS
GUC) and any other session-level `SET` do not survive across pooled connections in `transaction`
pooling mode — the GUC must be set at the *start of every transaction*, not once per logical
application "session," or RLS silently scopes to whatever the previous tenant's transaction left
behind. QAYD's Laravel database layer wraps every request in a transaction that always begins with
the `SET app.current_company_id` statement; monitoring for this specific failure mode is covered
under `# Audit & Security Monitoring`, but it is called out again here because it is the single most
dangerous interaction between the connection-pooling architecture and the observability/security
model — a passing `pg_stat_activity` snapshot can look completely normal while this bug is active,
because the query text and connection state reveal nothing about which GUC value was actually in
effect for a given statement.

**Autovacuum starvation from long-running reporting transactions.** A read replica or primary
connection held open for a large export (financial-year report generation) blocks autovacuum's
ability to reclaim dead tuples on any table that transaction has touched, for the full duration of
the transaction, even though the transaction is read-only. This shows up as `n_dead_tup` climbing on
`journal_lines` with no obvious write spike to explain it. The fix is architectural — long exports
must run on a read replica with a bounded `statement_timeout`, or via a snapshot export (`pg_dump`
with `--snapshot`) rather than a live long-lived transaction against the primary — and is monitored
by cross-referencing `# Bloat` metric spikes against `# Lock Waits & Deadlocks`'s "long-running
transaction, read-only" case, which the `LongRunningTransactionCritical` alert intentionally does not
distinguish from a write-holding transaction, because both are equally harmful to vacuum health even
though only one blocks other writers.

**Prometheus scrape failure is itself silent unless explicitly alerted.** If `postgres_exporter`
crashes or its target becomes unreachable, Prometheus does not raise an error about the *database* —
it simply stops receiving fresh samples, and depending on `for:` durations, some alert rules
(built on `rate()` or comparisons) may evaluate to "no data" rather than firing, which some
Alertmanager configurations treat as "resolved." QAYD mitigates this with a dedicated meta-alert:

```yaml
- alert: PostgresExporterDown
  expr: up{job=~"postgres-.*"} == 0
  for: 2m
  labels: { severity: critical }
  annotations: { summary: "postgres_exporter target {{ $labels.instance }} is down — all DB metrics for this target are stale" }
```

**Clock skew between application servers and the database host** can make `now() - created_at`-style
age calculations (used throughout this document, e.g. the custom `unposted_journal_age_seconds`
metric) misleading if computed partly in PHP and partly in SQL. QAYD standardizes on computing all
age/duration metrics entirely inside PostgreSQL (`now()` evaluated server-side, never
`Carbon::now()` compared against a database timestamp) specifically to eliminate this class of error;
NTP is additionally enforced on all EC2/RDS hosts via the AWS Time Sync Service.

**Multi-region / DR replica lag has a different acceptable threshold than same-region replicas.**
QAYD's disaster-recovery replica (cross-region, for regional AWS outage recovery, distinct from the
same-region reporting replicas) is intentionally allowed higher steady-state lag (up to 5 minutes)
because cross-region network latency makes tight synchronous-like lag uneconomical, and its RPO
target (5 minutes of acceptable data loss in a true regional failure) is a deliberate, documented
business decision, not a monitoring gap. The `ReplicationLagCritical` alert therefore uses a
per-replica threshold via label matching (`application_name` distinguishes reporting replicas from
the DR replica) rather than one global threshold for `pg_replication_lag_bytes`.

**Soft-deleted rows inflate naive "row count" capacity metrics.** Because financial rows are never
hard-deleted, `pg_stat_user_tables.n_live_tup` on `journal_lines`, `ledger_entries`, and `audit_logs`
includes soft-deleted rows (Postgres considers a row with `deleted_at IS NOT NULL` still "live" from a
storage-visibility perspective until vacuumed away by the normal MVCC lifecycle of whatever UPDATE
set that column — the row itself was never marked dead by that UPDATE, a *new* row version was
created and the old one became dead, so soft-delete's storage cost is actually paid at UPDATE time,
not permanently). Capacity projections in `# Capacity Planning` account for this by tracking
`pg_total_relation_size` (physical bytes) rather than `n_live_tup` (logical row count) as the primary
growth signal, since the two diverge meaningfully on high-soft-delete-churn tables.

**Alert fatigue from noisy per-tenant metrics.** With thousands of tenants, a naive per-company gauge
for every metric in `# Per-Tenant Usage Metrics` would produce cardinality explosion in Prometheus
(each `company_id` becomes a label value) and noisy per-tenant alerting. QAYD caps per-tenant
Prometheus labels to the top 100 tenants by volume (re-evaluated daily) and handles the long tail via
aggregate percentile metrics instead, with full per-tenant detail available on-demand via the SQL
queries in this document rather than pre-computed for every tenant continuously.

# End of Document
