# Audit Logging as a Security Control — QAYD Security
Version: 1.0
Status: Design Specification
Module: Security
Submodule: AUDIT_LOGS
---

# Purpose

Every other control in `docs/security/` answers the question *"can this actor do this thing?"* This
document answers the one that outlives the action: *"can we prove, later and to a hostile reviewer,
exactly who did what, when, from where, and what changed?"* Authentication, authorization, tenant
isolation, and encryption stop the wrong thing from happening. The audit trail is what makes the
right things — and the attempted wrong things — undeniable after the fact. In a multi-tenant
accounting platform holding thousands of companies' ledgers, payroll, and tax positions, a control
you cannot prove fired is a control an attacker (or a dishonest insider, or a company disputing its
own books) can later claim never fired. The audit trail is therefore treated here not as
instrumentation bolted on for debugging, but as a **first-class security boundary in its own right**:
it has its own threat model, its own tamper-evidence, its own access control, and its own failure
modes.

This document is the *security* specification for audit logging. It is deliberately narrow. It does
**not** re-derive the `audit_logs` table schema, its partitioning, its capture triggers, or its
reconstruction queries — those are owned, in full and authoritatively, by
[../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md), and by the Laravel write-path
service in [../backend/AUDIT_SERVICE.md](../backend/AUDIT_SERVICE.md). This document specifies the
security *requirements on* that machinery: what MUST be audited and can never be silently omitted;
why the trail is append-only and hash-chained rather than merely a table someone promises not to
edit; who may read the trail and why the trail itself is a sensitive asset; how audit data feeds the
SIEM and the anomaly-detection heuristics; and how the whole thing fails closed so that a sensitive
mutation which cannot be recorded simply does not happen.

Where this document and the database/backend documents appear to overlap, the division is fixed: they
own *how the row is written and stored*; this document owns *the security guarantees the row must
carry and the invariants the platform must never violate*. It assumes the reader has read
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md), whose Layer L9 (Audit & detection) this document
is the depth specialization of.

---

# Threat Model / Principles

## What the audit trail defends

The audit trail defends a single, structural asset named in the threat model of
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md): **non-repudiation**. Its adversaries are the
ones who benefit from the record being incomplete, editable, or erasable.

| # | Adversary | What they want from the trail | The control that denies it |
|---|---|---|---|
| A1 | The insider who acted and wants to hide it | A missing row, or an editable row they can quietly rewrite | Mandatory capture + append-only + hash chaining |
| A2 | The attacker with a stolen credential covering their tracks | To delete the log lines that name their session | `REVOKE UPDATE, DELETE`; partition-drop-only retention; immutability trigger |
| A3 | The infrastructure adversary with raw DB or backup access | To alter history in storage and restore it undetected | Per-company hash chain + external notarization of daily closing hash |
| A4 | The curious tenant reading another company's activity | To see who did what in a company that is not theirs | RLS on `audit_logs` + `audit.read` permission gate |
| A5 | The compromised AI engine acting without attribution | An AI-caused mutation that looks purely human, or no row at all | Every AI write carries `ai_assisted=true`, agent name, confidence; same trail as humans |
| A6 | The dishonest company disputing its own books | To claim a posting or a filing "never happened" or "was tampered by QAYD" | Cryptographic chain an external auditor recomputes without trusting QAYD's app |

## Principles

- **A mutation that is not audited did not happen.** For the always-sensitive operations (bank
  transfer, payroll release, tax submission, permission change, void/reverse of posted data), the
  audit record is written inside the *same database transaction* as the mutation. If the audit write
  cannot complete, the mutation rolls back. There is no reachable state in which money moved and no
  one can prove who moved it. This is the fail-closed rule stated in
  [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md) § Failure Modes and enforced here.
- **The trail is append-only and tamper-evident, not merely "we don't edit it."** A promise not to
  edit is a policy; an attacker with credentials ignores policy. QAYD makes editing history
  structurally detectable (hash chaining) and structurally difficult (privilege revocation + an
  immutability trigger that fires even against a superuser connection).
- **The trail is itself a sensitive asset.** `old_values`/`new_values` contain salaries, IBANs, and
  national IDs. Reading the trail is a permissioned, tenant-scoped, and itself-audited action. The
  audit log is not a public firehose an ops engineer greps casually.
- **Attribution is never anonymous and never ambiguous.** Every consequential row names a principal:
  a human `actor_user_id`, an AI `actor_service` (e.g. `ai:reporting-agent`) plus the approving
  human, or a named `scheduler:*` service. Impersonation records *both* the real actor and the
  assumed identity. "The system did it" is never an acceptable terminal answer.
- **Denials are auditable, not just successes.** A cross-tenant probe, a permission-denied attempt,
  and a rejected AI proposal are logged. An attacker's *failed* attempts are often the earliest and
  clearest signal, and a control that only records what succeeded is blind to reconnaissance.
- **Access to the trail cannot include write to the trail.** Auditor and External Auditor roles get
  broad read and *zero* write to any table, `audit_logs` included. The people who verify the books
  can never alter the record of them.

---

# Controls: What MUST Be Audited

The security requirement is a floor, not a menu: the categories below are the minimum. A module may
enrich a row (`reason`, richer `new_values`), but it may never audit *less* than the minimum for its
category. The per-table capture mechanics live in
[../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) § What Must Be Audited; the
security-mandatory set is:

| Category | Security-mandatory events | Why it is non-negotiable |
|---|---|---|
| **Authentication** | `user.login_success`, `user.login_failed`, `user.logout`, `user.password_changed`, `user.mfa_enabled`, `user.mfa_challenge_failed`, `user.session_revoked`, `user.impersonation_started/stopped` | The credential lifecycle is where account takeover is detected; a failed-login pattern is A2's earliest tell |
| **Permission / access-control change** | Any change to `roles`, `permissions`, `role_permissions`, `company_users` membership, Sanctum token issue/revoke, security settings (enforced MFA, session timeout, IP allowlist) | A1/A2 escalate by granting themselves rights; a silent grant compounds into every action it enables |
| **Sensitive financial mutation** | Every posting/void/reversal on `journal_entries`, every `bank.transfer` state change, `payroll_runs` release, `tax_returns` submission, credit-limit changes | These are the money-moving and legally-binding actions; they carry a mandatory `reason` |
| **Every AI approval / rejection** | Each `ai_decisions` transition (`proposed → auto_executed / accepted / rejected / pending_approval → approved / denied / blocked`), plus the resulting business mutation | A5: an AI-caused change must be as attributable as a human one, and the human who approved it named |
| **Data export / bulk read of sensitive data** | `reports.export`, payroll exports, bulk `GET` of PII-bearing collections above a threshold | Exfiltration is a *read*, not a write; the trail is the only place a bulk pull is visible |
| **Cross-tenant-denied attempt** | Every `404` produced specifically because a record belongs to another company (a tenant-boundary probe) | A4: the caller sees an ordinary `404`; the trail sees reconnaissance |
| **Security-relevant AI safety events** | `prompt_injection_detected`, `jailbreak_attempt_detected`, `tool_call_rejected`, `grounding_check_failed` | These land in `ai_logs` (see [AI_SECURITY.md](AI_SECURITY.md)); the escalation of them is an audit event |

## The mandatory-payload contract

Every audited row, regardless of category, must be able to answer six questions. This is the
security contract on the row shape (the physical columns are in
[../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)):

| Question | Column(s) | Rule |
|---|---|---|
| **Who** | `actor_user_id` / `actor_service` (+ `acting_as_user_id` for impersonation) | Never both null on a consequential event; unauthenticated events name the attempt, not a user |
| **What** | `category` + `action` (dot-namespaced) + `entity_type` + `entity_id` | `action` is specific enough to filter on without unpacking JSONB |
| **When** | `created_at` (set by DB `now()`, not the app clock) | Immune to multi-node clock skew reordering the trail |
| **Where** | `company_id`, `ip_address`, `user_agent`, `device_id` | `company_id` is NOT NULL for every tenant mutation |
| **Before / after** | `old_values`, `new_values`, `changed_fields` | PII masked at write time (see § Data Handling) |
| **Correlation** | `request_id` (matches the `/api/v1` response envelope) | Ties an audit row to the exact API request that produced it |

The `reason` field is nullable in the schema but enforced **NOT NULL by application validation** for
the high-risk allowlist — void, delete, permission change, journal reversal, payroll override, tax
submission. A sensitive action submitted without a reason is a `422`, not a silently unexplained row.

---

# Controls: Immutability and Tamper-Evidence

Append-only is enforced in depth, so no single failure — a misconfigured grant, a compromised
credential, a raw-storage edit — defeats it alone. The three layers below are the security core;
their SQL is authoritative in [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)
§ Immutability & Tamper-Evidence and summarized here only for the security rationale.

**Layer 1 — Privilege revocation.** No application-facing role holds `UPDATE` or `DELETE` on
`audit_logs`. The application can `INSERT` and `SELECT`, nothing more; retention removes *whole
partitions*, never rows, so `DELETE` is never granted to any role that a request could run under.

```sql
REVOKE UPDATE, DELETE ON audit_logs FROM app_user;
REVOKE UPDATE, DELETE ON audit_logs FROM PUBLIC;
GRANT  INSERT, SELECT  ON audit_logs TO   app_user;
```

**Layer 2 — An immutability trigger that fires even against a superuser.** Privilege revocation
protects against `app_user`; it does not protect against a compromised admin connection. A
`BEFORE UPDATE OR DELETE` trigger raises unconditionally, so a mutation attempt aborts regardless of
the connecting role.

```sql
CREATE OR REPLACE FUNCTION audit_logs_block_mutation()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'audit_logs is append-only: % is not permitted', TG_OP;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_audit_logs_immutable
BEFORE UPDATE OR DELETE ON audit_logs
FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();
```

**Layer 3 — Per-company hash chaining (the tamper-*evidence*).** Layers 1 and 2 make history hard to
edit *through the database*. Layer 3 makes an edit *outside* the database — a raw storage write, a
restored-and-doctored backup — detectable. Each row stores `hash = SHA256(canonical_payload ||
prev_hash)`, chained per company so chains stay independently verifiable and partition pruning still
works. Altering row *N* without also rewriting every subsequent `prev_hash`/`hash` in that company's
chain breaks verification deterministically.

```
row N-1:  hash = H(payload_{N-1} || hash_{N-2})
row N:    prev_hash = hash_{N-1};  hash = H(payload_N || hash_{N-1})
row N+1:  prev_hash = hash_N;      hash = H(payload_{N+1} || hash_N)
          ^ change payload_N and every hash from N onward no longer verifies
```

A nightly `audit:verify-chain` job recomputes each company's chain and raises
`system.audit_chain_broken` (itself an audited event) on the first mismatch. The security property:
the integrity guarantee lives in the cryptographic chain, **not** in "the application says so" — an
external auditor recomputes it against a raw export without trusting QAYD's code at all.

**Layer 4 — External notarization (regulated tier).** For A3 — an adversary who holds full DB
credentials *and* can replace the entire table, chain included — the daily closing hash (the last
row's `hash` per company per day) is written to an independently-controlled, WORM/object-locked store
(Cloudflare R2 with object-lock, or a third-party timestamping service). The external anchor sits
outside the blast radius of a full database compromise, so even a wholesale table replacement is
caught by a mismatch against the notarized daily hash.

The primitive choices — SHA-256 for the chain, `random_bytes` CSPRNG for any nonce — are fixed by
[ENCRYPTION.md](ENCRYPTION.md) § Crypto Primitives & Libraries; the chain is integrity-only (no
confidentiality), which is the correct classification for tamper-evidence.

---

# Implementation

Audit writing is a security-critical path, so it is written to survive partial failure without either
blocking the business write forever or silently dropping the record. The write-path service is owned
by [../backend/AUDIT_SERVICE.md](../backend/AUDIT_SERVICE.md); this section fixes the security-relevant
implementation rules.

**Two write disciplines, chosen by risk class.** The security requirement differs for a payroll
release versus a routine master-data edit, so the write path differs:

- **Transactional / outbox (sensitive class).** For the mandatory sensitive events, the audit row
  is written through an `audit_outbox` INSERT in the *same transaction* as the business mutation — a
  cheap, same-ACID-boundary write. A rollback removes both; a commit guarantees the record exists. A
  poller drains the outbox into `audit_logs`. This is what makes "audited-but-didn't-happen" and
  "happened-but-not-audited" both impossible for money-moving actions.
- **Queued / after-commit (routine class).** Lower-stakes events dispatch an `afterCommit()` queued
  listener, so the HTTP response is never blocked on the insert, but the row is only queued if the
  business transaction actually committed.

```php
// The security-critical binding: sensitive mutations audit inside the same transaction, and
// the transaction fails if the audit cannot be recorded. Simplified from AUDIT_SERVICE.md.
DB::transaction(function () use ($transfer, $actor) {
    $transfer->approveAndRelease();                 // the money-moving state transition

    // Same transaction, same ACID boundary. If this INSERT throws, the release rolls back.
    AuditOutbox::create([
        'payload' => AuditPayload::forSensitive(
            action:    'bank.transfer.released',
            entity:    $transfer,
            old:       ['status' => 'pending_approval'],
            new:       ['status' => 'released', 'released_at' => now()],
            actor:     $actor,
            reason:    request()->input('reason'),   // NOT NULL-enforced for this action
            requestId: request()->attributes->get('request_id'),
        )->toArray(),
    ]);
});
```

**Actor resolution is server-authoritative.** `actor_user_id` is read from the authenticated session
(`Auth::id()`), never from a client-supplied field — a request body claiming `"actor": 42` is ignored
for attribution exactly as a smuggled `company_id` is ignored for scoping
([API_SECURITY.md](API_SECURITY.md) § IDOR & Mass-Assignment). For an autonomous AI action the actor
is the AI service principal *and* the approving human where one exists; the AI-write contract is in
[AI_SECURITY.md](AI_SECURITY.md).

**The database trigger is a safety net, not the primary path.** A PL/pgSQL trigger on the highest-risk
tables writes to a *shadow* staging table; a reconciliation job compares the shadow against
`audit_logs` and raises `system.audit_gap_detected` if a write reached the table with no corresponding
app-layer audit row — catching a raw-SQL bypass that skipped the Laravel path. The mechanics are in
[../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) § Trigger-Based Capture; the
security value is that a developer who writes raw SQL and forgets the observer cannot create a silent
gap — the gap announces itself.

---

# Enforcement Points

Every requirement in this document anchors to a concrete, testable point an engineer edits.

| Enforcement point | Mechanism | Requirement enforced |
|---|---|---|
| `Auditable` trait / `AuditLog::record()` | Eloquent observers + explicit service calls | Mandatory capture on every audited model mutation |
| `audit_outbox` in-transaction INSERT | Same-transaction write for the sensitive class | Fail-closed: sensitive mutation rolls back if unaudited |
| `reason` FormRequest rule | `required` on the high-risk action allowlist | No unexplained void/delete/permission/tax event |
| `REVOKE UPDATE, DELETE` + immutability trigger | Postgres grants + `BEFORE UPDATE/DELETE` trigger | Append-only, even against a privileged connection |
| `trg_audit_logs_hash` (`BEFORE INSERT`) | Per-company SHA-256 hash chain | Tamper-evidence |
| `audit:verify-chain` (nightly) | Chain recomputation per company | Detection of out-of-band edits |
| Daily-hash notarization job | WORM/object-lock external write | Survives full-DB-compromise (A3) |
| RLS policy on `audit_logs` | `company_id = current_setting('app.current_company_id')` | Tenant isolation of the trail |
| `can:audit.read` route middleware | Permission gate on every audit-read endpoint | Read is permissioned and itself audited |
| Access-log alerting pipeline | Cross-tenant `404` tagging → SIEM | Denials and probes recorded |
| CI masking-registry diff | Migration-vs-`config/audit-masking.php` check | No new sensitive column reaches the trail unmasked |

The canonical ordering for a sensitive write, restating the platform spine from
[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md), places audit last and *inside* the boundary:

```
authenticate → resolve tenant → authorize → validate → execute (scoped) → audit (same tx)
                                                                            └─ rollback if this fails
```

---

# Data Handling

The audit trail inevitably contains the platform's most sensitive values — salaries, IBANs, national
IDs — inside `old_values`/`new_values`. Two rules keep the trail from becoming the softest place to
steal them.

**Mask at write time, never at read time.** Sensitive fields are masked *before* the row is
persisted, so a plaintext salary or IBAN is never written into the trail at all — not even encrypted,
not even "temporarily." A stable, keyed token replaces the value, so the trail can still answer "did
this IBAN change?" (`old` token ≠ `new` token) without ever holding the real IBAN. The masking
registry and the pattern list are in
[../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) § Privacy; the classification
that drives *which* fields are masked is owned by [DATA_PRIVACY.md](DATA_PRIVACY.md).

```php
// config/audit-masking.php — the registry the writer consults before persisting old/new values.
return [
    'payroll_items' => ['gross', 'net', 'deductions'],
    'employees'     => ['national_id', 'passport_no', 'bank_account_number'],
    'bank_accounts' => ['account_number', 'iban'],
    'companies'     => ['tax_id'],
];
```

A CI check diffs every audited migration's new columns against this registry and *fails the build*
if a column whose name matches the sensitive pattern list (`salary`, `iban`, `account_number`,
`national_id`, `passport`, `mfa_secret`, `token`, `secret`) is added without a masking entry — so a
sensitive column reaching the trail in cleartext is structurally prevented, not remediated after a
leak.

**Reading the trail is permissioned, tenant-scoped, and audited.** `audit_logs` carries RLS
identical to every tenant table, so a read is confined to the caller's company; a platform admin
override is itself a flagged, narrow path.

```sql
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;

CREATE POLICY audit_logs_company_isolation ON audit_logs
    USING (company_id = current_setting('app.current_company_id', true)::BIGINT
           OR current_setting('app.is_platform_admin', true)::BOOLEAN IS TRUE);
```

Reading requires `audit.read` (or the narrower `audit.read.own_company`); the Auditor and External
Auditor roles hold read company-wide and *no* write to any table. A bulk export of the audit trail is
itself an audited `audit.export` event — reading the record of activity is activity.

**Retention respects legal hold.** Retention removes whole partitions, never rows, and a partition is
never dropped while any company in it has `companies.legal_hold = true` or an open tax audit. The
security consequence: an adversary cannot accelerate the disappearance of incriminating history by
running out the retention clock, and a litigation hold freezes the relevant window intact. The full
tiering (hot / warm / archive) and the GCC multi-year floor are in
[../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md) § Partitioning & Retention;
the right-to-erasure-versus-retention conflict is resolved in [DATA_PRIVACY.md](DATA_PRIVACY.md).

**SIEM export.** Auth events, permission changes, cross-tenant-denied probes, and AI-safety events are
streamed to the external SIEM as they are written (an outbox-fed forwarder, not a nightly batch), so
detection is near-real-time and the SIEM copy is a second, independently-held record an attacker who
reaches the primary database does not automatically control. The export carries the masked payload —
the SIEM never receives an unmasked salary either.

---

# Anomaly Alerting

A trail nobody watches is a forensic tool, not a detective control. QAYD derives near-real-time
security signals from the audit stream so that abuse is *noticed*, not merely *reconstructable after a
customer complains*. Detection runs off the SIEM-forwarded copy (so a heavy analytical query never
touches the primary financial write path) and raises alerts that are themselves audited
(`category = 'system'`).

| Signal | Heuristic over the audit stream | Response |
|---|---|---|
| Cross-tenant probing | A rising count of cross-tenant-`404` events tagged to one actor/IP within a window | Step-up scrutiny; sustained → temporary block + security page |
| Credential-stuffing / brute force | Many `user.login_failed` across distinct accounts from one IP/UA fingerprint | Edge-level IP throttle; alert |
| Impossible travel | `user.login_success` from geographically implausible locations inside an implausible interval | Step-up MFA on the next sensitive action |
| Privilege-escalation burst | A spike of `permission.granted` / `role.assigned` events, or a grant to the same actor who then acts on it | Immediate alert to Owner/Admin; the grant chain is reviewed |
| Exfiltration pattern | `reports.export` / bulk PII reads far above an account's own baseline | Data-exfiltration review; not silently allowed because each call is within its per-minute limit |
| After-hours sensitive activity | Bank transfers / payroll releases outside a company's configured business-hours profile | Flagged for review; not blocked, but surfaced |
| Rubber-stamp approvals | Approval latency per approver implausibly low across many AI proposals | Governance signal surfaced to Compliance/Owner (a metric, never an AI accusation) |
| Chain integrity | `audit_chain_broken` from the nightly verifier | Treated as a suspected intrusion, security team paged |

```sql
-- Detect a single actor probing the tenant boundary: cross-tenant 404s clustered in time.
SELECT actor_user_id, ip_address, count(*) AS probes, min(created_at), max(created_at)
FROM audit_logs
WHERE category = 'system'
  AND action  = 'access.cross_tenant_denied'
  AND created_at >= now() - interval '15 minutes'
GROUP BY actor_user_id, ip_address
HAVING count(*) >= 5;                            -- threshold tuned per environment
```

The design intent: the *attempts* an attacker makes before they succeed — failed logins, boundary
probes, escalation bursts — are the earliest and clearest signal, and QAYD records and alerts on them
precisely because a control that only watches successes is blind to reconnaissance. Detection thresholds
are tuned to keep false positives low (an alert nobody trusts is an alert nobody reads), and every alert
carries the `request_id` and actor so a responder lands on the exact events, not a vague summary.

# Failure Modes

Audit logging fails closed. The governing rule: when the platform cannot *guarantee* a record of a
consequential action, it declines the action rather than perform it silently.

| Failure | Behavior | Rationale |
|---|---|---|
| Audit sink unavailable on a **sensitive** write | The enclosing transaction rolls back; the action is refused (`503` envelope) | No money moves without a record; the same-transaction outbox makes this automatic |
| Audit queue unavailable on a **routine** write | The business write commits; the `afterCommit` job retries; the DB-trigger shadow row backstops the gap | A routine master-data edit need not be blocked, but the gap is detectable and reconciled |
| Hash-chain verification mismatch | `system.audit_chain_broken` raised, security team paged; the suspect window is quarantined for investigation | A mismatch is potential tampering (A3), not a cosmetic error |
| A `BEFORE UPDATE/DELETE` on `audit_logs` is attempted | The immutability trigger raises; the attempt is refused and logged | Append-only holds even against a privileged connection |
| Reconciliation finds a shadow row with no app-layer audit | `system.audit_gap_detected` raised | Catches a raw-SQL bypass of the Laravel path |
| A sensitive action arrives without a required `reason` | `422`; nothing executes | An unexplained void/transfer/permission-change is not a valid request |
| Masking registry missing an entry for a new sensitive column | CI build fails pre-merge; runtime write of a would-be-plaintext sensitive value to the trail is blocked by the writer | Belt and suspenders — the leak is prevented, not cleaned up |
| SIEM forwarder backlog / outage | Primary trail unaffected; forwarder buffers and replays; a sustained outage alerts | The database trail is the source of truth; SIEM is a downstream copy |

Two of these deserve emphasis because they are where financial platforms most often go wrong. First,
**a sensitive mutation that cannot be audited does not happen** — the same-transaction outbox write is
not an optimization, it is the mechanism that makes this true. Second, **a broken hash chain is a
security incident, not a data-quality ticket** — it is treated with the same urgency as a detected
intrusion, because in this threat model that is exactly what it may be.

---

# Compliance

Audit logging is the evidentiary backbone behind several of QAYD's compliance goals, tracing the
framework expectations in [SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md) § Compliance to the
concrete control.

| Framework | Expectation the audit trail satisfies | How |
|---|---|---|
| SOC 2 (Security, Processing Integrity) | Logical-access logging, change management, evidence that sensitive changes were authorized | Mandatory capture of auth + permission + financial events; two-key approval both sides audited |
| ISO 27001 (A.8 logging & monitoring) | Event logging, protection of log information, administrator/operator logs | Append-only + RLS + `audit.read` gate; impersonation double-attribution |
| GDPR / GCC data-protection | Accountability, ability to demonstrate processing, breach evidence | Attributable, tamper-evident trail; PII masked in the trail itself |
| Kuwait / GCC commercial & tax law | Multi-year retention of accounting records; tamper-evident books | Partition retention floor + legal hold; hash chain + external notarization |
| PCI-adjacent posture | Track and monitor all access to sensitive data and resources | Export/bulk-read auditing; card data tokenized before it reaches the trail |

The specific deliverables an auditor is handed: a read-only `audit.read` credential to independently
confirm that no posted journal entry lacks a `journal_entry.posted` row; the `audit:verify-chain`
report they recompute themselves against a raw export; and the segregation-of-duties evidence that the
approver of a payment was not its creator, cross-referenced across independently-audited approval-chain
rows. The guarantee lives in the cryptographic chain, not in QAYD's assurances.

---

# Testing & Verification

The audit trail is only a control if its guarantees are continuously proven. Verification is layered
the way the controls are.

**Static gates (CI, block merge):**

- The masking-registry diff: any audited migration adding a sensitive-pattern column without a
  `config/audit-masking.php` entry fails the build.
- A PHPStan rule flags a state-transition Service method on a sensitive entity that has no
  corresponding `AuditLog::record()` / outbox write in the same method.
- A grant-lint asserts no application role holds `UPDATE`/`DELETE` on `audit_logs`.

**Behavioral tests (Pest/PHPUnit, must be green):**

- **Fail-closed:** with the audit sink forced to error, a bank-transfer release is attempted and
  asserted to roll back — no `released` status, no funds moved, `503` returned.
- **Mandatory capture:** posting a journal entry writes exactly one `journal_entry.posted` row with
  a resolvable `entity_id`, correct `actor_user_id`, and matching `request_id`.
- **Masking:** an employee salary update writes `old`/`new` values whose salary field is a stable
  masked token, never the cleartext amount; equal inputs yield equal tokens, different inputs differ.
- **Immutability:** an `UPDATE`/`DELETE` against `audit_logs` (as `app_user` and as a privileged
  role) is rejected.
- **Tamper-evidence:** mutating a stored `hash` out of band and running `audit:verify-chain` flags
  the exact row and every row after it in that company's chain.
- **Tenant isolation:** a user authenticated to company A cannot read company B's audit rows (RLS
  returns zero, not a `403` that confirms existence).
- **AI attribution:** an accepted AI proposal produces a business-mutation audit row carrying
  `ai_assisted=true`, the agent name, the confidence, and the accepting human as actor.
- **Impersonation:** every action during a support-impersonation session carries both
  `actor_user_id` (support) and `acting_as_user_id` (customer).

**Periodic verification:**

- The nightly chain verification and the daily external-hash notarization are monitored for
  successful completion; a missed run alerts.
- Quarterly: a restore-and-verify drill confirming a restored backup's chain still verifies against
  the notarized daily hashes.
- External penetration testing includes an explicit "cover your tracks" objective — the tester
  attempts to act and then erase or edit the record, and the required result is that every attempt is
  either refused or detected by chain verification.

The acceptance bar: for any consequential action in the system, an engineer can name the audited
event it produces, show the test that proves the event is written, show that the write fails closed
when it cannot be recorded, and demonstrate that an out-of-band edit to that record is detectable.
Where they cannot, the gap is a security defect.

---

# End of Document
