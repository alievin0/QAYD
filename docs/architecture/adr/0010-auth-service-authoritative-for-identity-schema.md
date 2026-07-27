# ADR-0010: AUTH_SERVICE.md is authoritative over MULTI_TENANCY.md for the identity/RBAC schema

Status: Accepted

Date: 2026-07

## Context

QAYD's specification set describes the identity and RBAC tables (`users`, `company_users`, `roles`,
`permissions`, `role_permissions`, `company_user_permissions`) in **two** documents that were found to
**disagree** while implementing Sprint 1 story S1-04 (core identity/tenant schema):

- **[../../backend/AUTH_SERVICE.md](../../backend/AUTH_SERVICE.md)** models `users` as a single identity
  store (`uuid`, `citext` email with `uq_users_email`, `password_hash`, `name`, `locale`,
  `mfa_enrolled`, `status`, soft-delete; `is_platform_admin` added in S1-05), and `company_users` with
  `branch_scope`/`department_scope`/`perms_ver`/`joined_at`/`deleted_at` and status
  `(active, suspended, revoked)`.
- **[../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md)** describes a future
  **multi-region** model: a thin regional `users` mirror (`global_user_uuid`, `display_name`) tied to a
  separate `global_users` identity store, and a `company_users` variant with `is_default`,
  `invited_at`/`accepted_at`/`revoked_at`, a status that includes `'invited'`, and a one-default-per-user
  partial index. One middleware snippet there also shows `abort(403)` for a non-member.

A single implementation cannot honor both shapes at once, so the build needs one authoritative source for
the identity/RBAC tables. Two facts make the choice clear:

1. The identity module **owns** these tables (per the module architecture), and AUTH_SERVICE.md is the
   document that specifies that ownership and the service that operates on them.
2. **[../../execution/SPRINT_01.md](../../execution/SPRINT_01.md)** explicitly scopes geographic
   sharding / multi-region **out** of the MVP ("the model … is honored in schema shape but not
   operationalized this sprint").

## Decision

For the MVP build, **`AUTH_SERVICE.md` is the authoritative source for the identity and RBAC tables** and
their columns, constraints, and status enums. The `MULTI_TENANCY.md` multi-region variant
(`global_users` + a regional `users` mirror) is treated as a **future design and is not built now**,
consistent with SPRINT_01's out-of-scope list.

Two deliberate carve-outs, decided once here:

- **`companies`** has no counterpart in AUTH_SERVICE.md, so it continues to come from MULTI_TENANCY.md
  (its only source).
- **`uuid` uniqueness follows MULTI_TENANCY.md's tenant-ID rule (every `uuid` is UNIQUE), overriding the
  AUTH_SERVICE.md DDL's omission.** A `uuid` is a stable, publicly-exposed identifier; a non-unique one
  is a latent correctness bug. `users.uuid` therefore carries a unique index (added in a follow-up
  migration; `companies.uuid` already had `companies_uuid_uk`). This is the one place AUTH_SERVICE's
  omission is treated as an oversight rather than authoritative.

Cross-cutting rules that are not identity-table *shape* follow SPRINT_01 and the governing
[FINAL_TECH_STACK.md](../FINAL_TECH_STACK.md): non-member access returns **404** (enumeration-safety,
not the `403` in one MULTI_TENANCY snippet), and the RLS GUC name is **`app.current_company_id`** (not
the `app.company_id` shown in one ROW_LEVEL_SECURITY.md snippet) — see [ADR-0005](./0005-multitenancy-rls.md).

## Consequences

Positive:
- One unambiguous identity schema; the auth/RBAC build proceeds on a single coherent model.
- The multi-region model is deferred cleanly, matching sprint scope ("do not build the future").
- Enumeration-safety (404) and the single GUC name are settled once, for the whole codebase.

Negative / trade-offs:
- `MULTI_TENANCY.md` and `AUTH_SERVICE.md` remain internally divergent documents; a future reader must
  know AUTH_SERVICE wins for the identity tables — **this ADR is that pointer.**
- Some MULTI_TENANCY-only features (`is_default` membership, the `invited/accepted/revoked` lifecycle) are
  **not** in the MVP schema; they are tracked as future work (see `TECH_DEBT.md` TD-03 and later stories).
- If/when multi-region identity is adopted, a **new ADR must supersede this one** and a migration must
  reconcile `users` → `global_users` + a regional mirror.

## Alternatives considered

- **Make MULTI_TENANCY.md authoritative (build multi-region `users`/`global_users` now):** rejected — it
  builds infrastructure SPRINT_01 explicitly defers, adding a global identity store and regional
  mirroring the MVP does not need, for no Sprint-1 user value (violates "do not build the future").
- **Invent a hybrid schema merging both:** rejected — that is an un-reviewed third design not present in
  either frozen document; the docs are the reference, not a place to improvise.
- **Edit the frozen docs to agree:** rejected for now — the specs are frozen at `architecture-freeze-v1`;
  the sanctioned mechanism for a decision made during the build is a **new ADR** (this one), not editing a
  frozen spec. A documentation reconciliation can follow later if desired.

## Related

- [../../backend/AUTH_SERVICE.md](../../backend/AUTH_SERVICE.md) — the authoritative identity/RBAC source.
- [../../database/MULTI_TENANCY.md](../../database/MULTI_TENANCY.md) — tenancy model + the future
  multi-region identity design.
- [ADR-0005](./0005-multitenancy-rls.md) (single-DB RLS on `company_id`), [ADR-0004](./0004-postgresql.md)
  (PostgreSQL as system of record).
- [../../execution/SPRINT_01.md](../../execution/SPRINT_01.md) — S1-04/S1-05 and the out-of-scope list.
- `TECH_DEBT.md` — TD-02 (`users.uuid` uniqueness) and TD-03 (this divergence).
