# 09 — Database — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: DATABASE
---

# Purpose

This chapter states what QAYD's data platform *is as a product foundation* — the guarantees the database
must make, the strategy that makes them, and the isolation and integrity properties the whole business rests
on — at PRD altitude. It does not restate a table definition, a policy DDL, an index list, or a migration
recipe; those live authoritatively under [`../../database/`](../../database/DATABASE_ARCHITECTURE.md), and
this chapter routes to them. The backend that talks to this database is PRD chapter 08. The single reason
this chapter is written separately from that one: in a double-entry financial system for many companies, the
database is not merely where state is stored — it is the last line of defence for *correctness* and
*tenant isolation*, and those two properties are load-bearing enough to fix as their own requirements.

The data platform is PostgreSQL as the single primary database, Redis as the cache/lock/queue tier, and
Supabase Storage for object storage. The defining decision is that all companies share one database and one
schema, isolated row by row and enforced in depth — a choice made deliberately over schema-per-tenant or
database-per-tenant, and defended below.

# The Data Platform's Job

The database must make three guarantees that no application-layer code is trusted to make alone (owned by
[`../../database/DATABASE_ARCHITECTURE.md`](../../database/DATABASE_ARCHITECTURE.md)):

- **Isolation that cannot be forgotten.** Company A's rows are unreachable from company B's session even if an
  application query author forgets a `WHERE` clause — because the boundary is enforced by the database itself,
  not only by the ORM.
- **Correctness that cannot be bypassed.** The double-entry and financial-shape invariants hold for the AI
  layer's writes, a future non-PHP service, and a manual data-fix script under incident pressure alike,
  because they are database constraints, not only Laravel validation.
- **Durability and recoverability.** Financial records survive with statutory retention, point-in-time
  recovery, and tested restores — a finance platform that can lose or silently corrupt a ledger has no
  product.

PostgreSQL is chosen because it is the one engine that delivers strong transactional guarantees, row-level
security, rich constraint types, partitioning, and (via `pgvector`) the AI layer's memory store under a single
RLS boundary — keeping tenant data, including AI embeddings, inside one isolation model rather than scattered
across stores. Required extensions and the alternatives-considered rationale are in
[`../../database/DATABASE_ARCHITECTURE.md`](../../database/DATABASE_ARCHITECTURE.md).

# Schema Strategy

Every business table shares a standard column contract — a monotonic internal key, an external public key,
the tenant column, optional branch and actor columns, and timestamps — so tenant scoping, auditing, and soft
delete work uniformly across the whole schema (detail in
[`../../database/DATABASE_ARCHITECTURE.md`](../../database/DATABASE_ARCHITECTURE.md)). Two strategy decisions
are worth fixing at PRD altitude because downstream contracts depend on them:

- **Dual-key identity: internal `BIGINT`, external `UUID`.** Every table has a monotonically increasing
  `BIGINT` identity used for all joins, foreign keys, and index maintenance, plus a `UUID` exposed as the
  record's public identifier in the API, webhooks, and URLs. The internal sequential id never leaves the
  backend — API Resources serialize the UUID as the record's `id`. This gives QAYD B-tree insert locality and
  small foreign keys internally, and enumeration-proof, migration-safe, offline-assignable identifiers
  externally, without choosing one at the cost of the other.
- **`company_id` is `NOT NULL` on every business table, without exception.** There is no "global" business
  record; the only tables without a tenant column are genuinely platform-level ones (the companies table
  itself, system-defined roles, account-type reference data), and they are explicitly enumerated so an
  omission is never a guess.

Shared-schema, row-discriminated tenancy is the correct default for QAYD's SME-and-mid-market base: one schema
to migrate, one connection pool, one set of indexes to tune, and trivial platform-level aggregate reporting —
provided isolation is enforced at the row level by a mechanism that does not depend on every query author
remembering a filter. QAYD reserves schema-per-tenant as a future paid isolation tier for regulated enterprise
customers; no code assumes it exists until a company is explicitly flagged for it (see
[`../../database/MULTI_TENANCY.md`](../../database/MULTI_TENANCY.md)).

# Single-Database Multi-Tenancy With Row-Level Security

Tenant isolation is defence-in-depth across three layers, and the product requirement is that no single layer
is trusted alone (owned by [`../../database/MULTI_TENANCY.md`](../../database/MULTI_TENANCY.md) and
[`../../database/ROW_LEVEL_SECURITY.md`](../../database/ROW_LEVEL_SECURITY.md)):

| Layer | Mechanism | Role |
|---|---|---|
| HTTP middleware | Resolve the active company from the request, assert the user's membership, bind it for the request | Establishes tenant context once, up front |
| Eloquent global scope | A `BelongsToCompany` trait adds the tenant filter to every query and fills `company_id` on insert | Convenience and defence-in-depth — never the security boundary |
| PostgreSQL Row-Level Security | RLS **enabled and forced** on every tenant table; policies scope by a session GUC set per unit of work | The boundary of last resort that an application bug cannot bypass |

The mechanism that makes RLS safe under connection pooling is a product-critical detail: the tenant company id
is carried in a **session GUC** set with `SET LOCAL` (transaction-scoped), never plain `SET`, because QAYD runs
behind PgBouncer in transaction-pooling mode and a session-scoped variable would leak onto the next unrelated
request that reuses the physical connection — which would make RLS faithfully enforce the *wrong* company's
isolation. Policies read the GUC through a helper that returns `NULL` when it is unset, and `NULL = company_id`
denies all rows, so a session with no tenant context **fails closed** rather than defaulting open. Isolation
is treated as a release gate, not an optional test class: every module ships a test proving company A receives
`404` (never `403`) for company B's resource, and enumeration is blocked by exposing opaque external
identifiers rather than raw sequential ids.

# Double-Entry And Data Integrity

Financial correctness is enforced by the database as a structural property, so the same rules bind every write
path — human, AI, or script (owned by
[`../../database/DATABASE_ARCHITECTURE.md`](../../database/DATABASE_ARCHITECTURE.md)):

- **Money is fixed-scale, never float.** Monetary columns are `NUMERIC(19,4)`; rounding cannot silently
  unbalance an entry.
- **A journal line is a debit *or* a credit, never both** — a per-row `CHECK` constraint.
- **A journal entry's debits must equal its credits** — enforced by a `DEFERRABLE INITIALLY DEFERRED`
  constraint trigger that fires once at commit against the entry's final set of lines, so a multi-line entry
  inserted inside one transaction is checked as a whole and an intermediate mid-transaction state never raises
  a false failure. This is the database backstop to the same invariant the domain layer asserts before commit
  (PRD chapter 08).
- **Ledger lines are append-only and posted records are immutable** — a correction is a new reversing entry;
  a mutation of a posted document is rejected, with the response naming the reversal or void action to use.
- **Uniqueness is per-tenant, and ranges cannot overlap** — unique constraints are scoped by `company_id`
  (two companies may both have invoice `INV-0001`), and `EXCLUDE` constraints prevent overlapping fiscal
  periods or double-booked reservations under concurrency.

The governing principle is that validating only in Laravel is never treated as sufficient: every rule that
protects financial correctness is duplicated as a database constraint precisely so the AI layer's writes, a
future service, or an emergency data-fix cannot route around it.

# Migrations, Indexing, And Partitioning

Migrations are the versioned, forward-only path for schema change, run as a gated release step against the
primary before new code takes traffic (owned by
[`../../database/DATABASE_MIGRATIONS.md`](../../database/DATABASE_MIGRATIONS.md) and
[`../../database/DATABASE_VERSIONING.md`](../../database/DATABASE_VERSIONING.md)). Two performance decisions are
product-level because they bound QAYD's scale ceiling:

- **Every index leads with `company_id`.** The dominant query shape is always "this company, this period," so
  composite `(company_id, …)` indexes keep tenant-scoped queries sargable and are the primary performance
  lever (see [`../../database/DATABASE_INDEXING.md`](../../database/DATABASE_INDEXING.md)).
- **High-volume line and event tables are partitioned before they are large,** by monthly `RANGE` on the
  timestamp the row is financially "of" (`posted_at` for ledger lines, `created_at` where there is no separate
  posting concept). Header tables are not partitioned; their unbounded children are. Partition lifecycle is
  automated, expired partitions are archived to cold storage rather than dropped (7-year statutory retention
  for financial lines), and every query against a partitioned table must carry a bound on the partition key or
  lose the benefit — a rule enforced in review and CI. Detail and the partition summary are in
  [`../../database/DATABASE_PARTITIONING.md`](../../database/DATABASE_PARTITIONING.md).

# Audit, Backup, And Disaster Recovery

Every mutation to a business record — create, update, soft-delete, restore, and every state transition —
writes an immutable row to an append-only audit table, written inside the same transaction as the change it
records so business data and its history can never diverge. The audit table has no update or delete grant for
the application role at all; even platform support tooling can only read and export it, never mutate it. It
distinguishes actor type (user, AI agent, system, integration) so the "who changed what" record answers for
the AI workforce as clearly as for a human. Schema and mechanics are owned by
[`../../database/DATABASE_AUDIT_LOGS.md`](../../database/DATABASE_AUDIT_LOGS.md) and
[`../../security/AUDIT_LOGS.md`](../../security/AUDIT_LOGS.md).

| Recoverability property | Requirement | Owned by |
|---|---|---|
| Backups | Continuous, encrypted, immutable backups with defined cadence and retention; restores are verified, not assumed | [`../../database/DATABASE_BACKUP_RECOVERY.md`](../../database/DATABASE_BACKUP_RECOVERY.md) |
| Point-in-time recovery | PITR at the platform level and a per-tenant logical export/restore path for single-company recovery and migration | [`../../database/DATABASE_BACKUP_RECOVERY.md`](../../database/DATABASE_BACKUP_RECOVERY.md), [`../../database/MULTI_TENANCY.md`](../../database/MULTI_TENANCY.md) |
| Replication & HA | Primary + read replica, reporting reads routed to the replica (RLS-bound, never bypassed); automated failover | [`../../database/DATABASE_ARCHITECTURE.md`](../../database/DATABASE_ARCHITECTURE.md) |
| Soft delete | Delete is a soft delete by default, enforced at the database level, with partial indexes that exclude deleted rows and a defined restore path | [`../../database/DATABASE_SOFT_DELETE.md`](../../database/DATABASE_SOFT_DELETE.md) |

The launch DR posture (single-region with tested region-pinned backups versus full active/passive) and the DR
region's residency implications are open items — OPS-1 and CMP-2 in
[`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md).

# Known Inconsistencies To Reconcile

Two load-bearing choices were made more than once across the documentation set with slightly different answers.
They are not design bugs — they are decisions never formally closed — and both are blocking items in the
decisions register at [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md). The schema and the first end-to-end
integration cannot be frozen until they are decided and every source doc is reconciled to the decision.

| Item | The divergence | Why it is blocking | Register ID |
|---|---|---|---|
| **RLS GUC name** | The tenant session variable is `app.company_id` in [`../../database/ROW_LEVEL_SECURITY.md`](../../database/ROW_LEVEL_SECURITY.md), [`../../security/TENANT_ISOLATION.md`](../../security/TENANT_ISOLATION.md), and [`../../security/SECURITY_ARCHITECTURE.md`](../../security/SECURITY_ARCHITECTURE.md), but `app.current_company_id` in [`../../database/MULTI_TENANCY.md`](../../database/MULTI_TENANCY.md), the ERD, the migrations, and most backend/AI docs. | RLS policies read a GUC by exact name; two names means one silently resolves to `NULL` and the policy fails unpredictably. Recommended resolution: standardize on **`app.current_company_id`** (the majority usage) and grep CI for the old name. | ARC-1 |
| **`X-Company-Id` identifier type** | The company id/header is a `BIGINT` in the ERD and the OpenAPI schema (`type: integer`), but an opaque prefixed string (`cmp_…`) in [`../../api/AUTHENTICATION_API.md`](../../api/AUTHENTICATION_API.md) and several frontend flow docs; the companies table also carries a `uuid`. | The wire type of the tenant identifier is unpinned, which the API contract, the frontend client, and RLS casts all depend on. Recommended resolution: expose an **opaque `cmp_` external id backed by `companies.uuid`**, keep `BIGINT` strictly internal, and update the OpenAPI schema from `integer` to `string` — consistent with the "never expose raw sequential IDs" stance. | ARC-3 |

Recording these here keeps the platform's one memory of why each choice is made, rather than resolving them
silently and inconsistently in a single module.

## Related Documents

- **This chapter (09) expands and routes to:**
  [`../../database/DATABASE_ARCHITECTURE.md`](../../database/DATABASE_ARCHITECTURE.md),
  [`../../database/MULTI_TENANCY.md`](../../database/MULTI_TENANCY.md),
  [`../../database/ROW_LEVEL_SECURITY.md`](../../database/ROW_LEVEL_SECURITY.md),
  [`../../database/DATABASE_MIGRATIONS.md`](../../database/DATABASE_MIGRATIONS.md),
  [`../../database/DATABASE_INDEXING.md`](../../database/DATABASE_INDEXING.md),
  [`../../database/DATABASE_PARTITIONING.md`](../../database/DATABASE_PARTITIONING.md),
  [`../../database/DATABASE_CONSTRAINTS.md`](../../database/DATABASE_CONSTRAINTS.md),
  [`../../database/DATABASE_AUDIT_LOGS.md`](../../database/DATABASE_AUDIT_LOGS.md),
  [`../../database/DATABASE_BACKUP_RECOVERY.md`](../../database/DATABASE_BACKUP_RECOVERY.md),
  [`../../database/DATABASE_SOFT_DELETE.md`](../../database/DATABASE_SOFT_DELETE.md),
  [`../../database/DATABASE_VERSIONING.md`](../../database/DATABASE_VERSIONING.md),
  [`../../database/ERD.md`](../../database/ERD.md)
- **Security:** [`../../security/TENANT_ISOLATION.md`](../../security/TENANT_ISOLATION.md),
  [`../../security/SECURITY_ARCHITECTURE.md`](../../security/SECURITY_ARCHITECTURE.md),
  [`../../security/DATA_PRIVACY.md`](../../security/DATA_PRIVACY.md),
  [`../../security/ENCRYPTION.md`](../../security/ENCRYPTION.md),
  [`../../security/AUDIT_LOGS.md`](../../security/AUDIT_LOGS.md)
- **API contract that carries the tenant identifier:** [`../../api/API_ARCHITECTURE.md`](../../api/API_ARCHITECTURE.md),
  [`../../api/AUTHENTICATION_API.md`](../../api/AUTHENTICATION_API.md)
- **Cross-PRD:** [`./07_FRONTEND.md`](./07_FRONTEND.md), [`./08_BACKEND.md`](./08_BACKEND.md),
  [`../MASTER_PRD.md`](../MASTER_PRD.md), reconcile items in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md)

# End of Document
