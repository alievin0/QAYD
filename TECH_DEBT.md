# QAYD — Tech Debt Log

Per MANIFEST Law 5: every "we'll fix it later" is recorded here (or as a tracked issue), never left to
memory. Each item names its source story, severity, the decision taken, and the planned resolution.
Items are removed only when resolved (with the resolving commit noted).

| ID | Source | Severity | Item | Planned resolution |
|---|---|---|---|---|
| TD-01 | S1-05 | Medium | Raw query-builder / raw SQL (`DB::table(...)`, `DB::select(...)`) on the **default/owner** Postgres connection bypasses RLS. Tenant **Eloquent** models are safe — `BelongsToCompany` binds them to the non-superuser `pgsql_app` connection, enforced by an arch test — but a raw call on the owner connection would not be tenant-scoped. | Route any raw tenant queries through the `pgsql_app` connection; add a static/arch check that flags raw `DB::` calls against tenant tables. Revisit as query-heavy stories land (S1-09+). |
| TD-02 | S1-04 | Low | `users.uuid` has **no unique index** — the `AUTH_SERVICE.md` DDL declares only `uq_users_email`, while the generic tenant-ID rule in `MULTI_TENANCY.md` gives every `uuid` a unique index. Followed AUTH_SERVICE exactly. | Confirm intent; if `users.uuid` should be unique, add a unique index in a new migration. |
| TD-03 | S1-04 | Medium | **Identity-schema source-of-truth divergence.** `AUTH_SERVICE.md` and `MULTI_TENANCY.md` disagree on `users`/`company_users` (single identity store vs. a multi-region `global_users` mirror model, plus differing `company_users` columns/status enums/404-vs-403). Chose **AUTH_SERVICE.md** as authoritative (the multi-region model is explicitly out of scope this sprint). | If/when multi-region identity is adopted, write a new ADR and reconcile the schema via migration. Until then, AUTH_SERVICE is canonical for identity/RBAC. |
| TD-04 | S1-05 | Low | `is_platform_admin` exists and sets the `app.is_platform_admin` GUC, but is **not** wired into any cross-tenant read bypass in the RLS policies (kept strict / fail-closed). The platform-diagnostics read escape hatch is deferred. | Design the platform-admin cross-tenant path later as an **explicit, narrow, audited** service-role path (never an implicit policy bypass). |
| TD-05 | S1-04 / S1-06 | Low | Schema/RLS tests run `migrate:fresh` against the real dev `qayd` database (destructive by design; fine for a foundations/CI DB), and the "migrate once" guard is per-process. | A future `pest --parallel` run needs a per-worker database; wire this into the CI DB setup (S1-02) or the test bootstrap. |
| TD-06 | S1-16 | Low | The `audit_logs` skeleton defers the full audit spec: monthly range **partitioning**, the per-company **SHA-256 hash chain** (`hash`/`prev_hash` columns are present so it can be added without a rewrite), the PL/pgSQL row-diff **shadow-table** capture, and the **outbox/queue** write path. Table + append-only + RLS + `AuditLogger::record` writer only. | Implement partitioning + hash chain + outbox as audit volume/compliance needs arise (post-Sprint-1). |

# End of Document
