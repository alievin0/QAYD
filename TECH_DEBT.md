# QAYD — Tech Debt Log

Per MANIFEST Law 5: every "we'll fix it later" is recorded here (or as a tracked issue), never left to
memory. Each item names its source story, severity, the decision taken, and the planned resolution.
Items are removed only when resolved (with the resolving commit noted).

| ID | Source | Severity | Item | Planned resolution |
|---|---|---|---|---|
| TD-01 | S1-05 | Medium | Raw query-builder / raw SQL (`DB::table(...)`, `DB::select(...)`) on the **default/owner** Postgres connection bypasses RLS. Tenant **Eloquent** models are safe — `BelongsToCompany` binds them to the non-superuser `pgsql_app` connection, enforced by an arch test — but a raw call on the owner connection would not be tenant-scoped. | Route any raw tenant queries through the `pgsql_app` connection; add a static/arch check that flags raw `DB::` calls against tenant tables. Revisit as query-heavy stories land (S1-09+). |
| TD-04 | S1-05 | Low | `is_platform_admin` exists and sets the `app.is_platform_admin` GUC, but is **not** wired into any cross-tenant read bypass in the RLS policies (kept strict / fail-closed). The platform-diagnostics read escape hatch is deferred. | Design the platform-admin cross-tenant path later as an **explicit, narrow, audited** service-role path (never an implicit policy bypass). |
| TD-05 | S1-04 / S1-06 | Low | Schema/RLS tests run `migrate:fresh` against the real dev `qayd` database (destructive by design; fine for a foundations/CI DB), and the "migrate once" guard is per-process. | A future `pest --parallel` run needs a per-worker database; wire this into the CI DB setup (S1-02) or the test bootstrap. |
| TD-06 | S1-16 | Low | The `audit_logs` skeleton defers the full audit spec: monthly range **partitioning**, the per-company **SHA-256 hash chain** (`hash`/`prev_hash` columns are present so it can be added without a rewrite), the PL/pgSQL row-diff **shadow-table** capture, and the **outbox/queue** write path. Table + append-only + RLS + `AuditLogger::record` writer only. | Implement partitioning + hash chain + outbox as audit volume/compliance needs arise (post-Sprint-1). |
| TD-07 | S1-08 | Medium | CSRF is not wired on the **Sanctum cookie (web SPA) login flow** — the stateful cookie session lacks CSRF/419 enforcement (bearer/JWT requests are unaffected). Correct wiring needs Sanctum's origin-gated `EnsureFrontendRequestsAreStateful` + `/sanctum/csrf-cookie`, which the API test client does not exercise. | Wire CSRF for the cookie flow in the frontend auth stage (S1-15) or a hardening story, **before** the SPA cookie login ships to users. |

## Resolved

| ID | Source | Item | Resolution |
|---|---|---|---|
| TD-02 | S1-04 | `users.uuid` had no unique index (AUTH_SERVICE.md DDL declared only `uq_users_email`). | Resolved — a UNIQUE index `uq_users_uuid` was added on `users (uuid)` in migration `2026_07_27_000013_add_unique_index_to_users_uuid.php`, per ADR-0010's uuid-uniqueness carve-out (resolved in this commit / ADR-0010). |
| TD-03 | S1-04 | Identity-schema source-of-truth divergence between `AUTH_SERVICE.md` and `MULTI_TENANCY.md` (single identity store vs. multi-region `global_users` mirror; differing `company_users` columns/status enums/404-vs-403). | Resolved — decided and documented in [ADR-0010](docs/architecture/adr/0010-auth-service-authoritative-for-identity-schema.md): AUTH_SERVICE.md is authoritative for the identity/RBAC schema; the multi-region model is deferred and must supersede ADR-0010 if ever adopted (resolved in this commit / ADR-0010). |

# End of Document
