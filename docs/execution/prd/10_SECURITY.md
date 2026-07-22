# 10 — Security — QAYD PRD
Version: 1.0
Status: Source of Truth
Module: PRD
Submodule: SECURITY

---

# Purpose

This chapter states QAYD's security posture at product-requirements altitude: what the platform
promises about the safety of the data it holds, why that promise is a first-class product feature
rather than a compliance afterthought, and how the promise decomposes into a small number of
load-bearing security models. It is deliberately *not* an implementation specification. Every
mechanism named here — the exact session lifetimes, the permission grammar's enforcement points, the
row-level-security policy shapes, the key-rotation runbooks — is owned in depth by the
[`docs/security/`](../../security/SECURITY_ARCHITECTURE.md) folder, and this chapter routes to those
documents rather than restating them. Where this chapter and a security document appear to differ, the
security document is authoritative on the detail and this chapter is authoritative only on the
product-level intent.

QAYD is an AI-native, multi-tenant, bilingual (Arabic/English, full RTL) financial operating system
for Kuwait and the wider GCC. It holds the most consequential data a business owns: its general
ledger, its bank balances and transfer capability, its payroll and the personally identifiable
information of its employees, and its tax position and the ability to file on the company's behalf.
For a product of this kind, security is not a horizontal that supports the features — it *is* a
feature, and arguably the one that most determines whether an owner will trust an AI to keep the
books at all. The commercial thesis in [`12_BUSINESS.md`](./12_BUSINESS.md) — that a supervised AI
workforce can do the mechanical finance work while a human approves the consequential decisions —
rests entirely on the security model being credible to a buyer, an auditor, and a regulator. The
non-negotiable success invariants in [`../MASTER_PRD.md`](../MASTER_PRD.md) make this explicit:
*every* sensitive action must pass a human approval chain, and cross-tenant data leakage must be
exactly zero. Both are pass/fail properties, not metrics to be optimized, and both are security
properties.

---

# The Security Posture

QAYD's security architecture assumes the compromise of any single layer and is built so that a single
compromise still does not yield the ledger. This assumption produces seven load-bearing beliefs that
every downstream security document specializes; they are the posture the whole product is designed
around, and they are stated in full in
[`../../security/SECURITY_ARCHITECTURE.md`](../../security/SECURITY_ARCHITECTURE.md).

| Principle | What it means at product level |
|---|---|
| **Server-authoritative, always** | Every security decision — who you are, which company you act on, what you may do — is made freshly on the Laravel backend on every request. A hidden button in the web app or a disabled field in the mobile app is a courtesy to the user, never a control; a request the interface would never have sent is treated exactly like one it would. |
| **Zero trust between layers** | No layer trusts its caller because of where the call came from. The AI engine authenticates and is authorized like any other client; a private-network position is never, by itself, permission. |
| **Deny by default** | The absence of an explicit grant is a denial. A new capability is unreachable until it is deliberately granted, even to the Owner — it is always safer for a sensitive capability to need a follow-up grant than to inherit access silently. |
| **Defense in depth** | The tenant boundary is enforced four independent times (HTTP middleware, the query scope, the policy layer, and PostgreSQL row-level security); any one alone would stop the attack, so a bug in one is not a breach. |
| **Two-key control on money and identity** | No single credential — human or AI — can drive a bank transfer, payroll release, tax submission, or permission change to completion alone. |
| **Everything auditable, the audit tamper-evident** | Every consequential action and every access denial is logged, and the log is append-only and integrity-protected — a control that cannot be proven to have fired is one an attacker can later deny. |
| **The AI is on-behalf-of, never instead-of** | The AI engine acts inside the calling user's permission envelope, never beyond it, and never holds an approval permission. It proposes; a human disposes. |

What QAYD defends is easy to name and hard to overstate: the general ledger and financial statements;
bank identifiers, balances, and the capability to move money; payroll and employee PII; tax returns
and the capability to submit them; the cross-tenant boundary itself; the authentication and signing
secrets; and the audit trail. It defends these against five adversary classes — the external attacker
with a stolen credential, the curious or malicious tenant reaching for another company's data, the
over-privileged or hostile insider, the compromised AI engine, and the infrastructure adversary who
reaches a network, container, backup, or secret store. The single most important structural fact,
carried through every layer, is that the Laravel API is the *only* entry point to the data plane: no
client — not the web tier, not the mobile app, not the AI engine, not any integration — ever opens a
direct connection to PostgreSQL, Redis, or object storage. There is exactly one place where the rules
live, and one canonical pipeline every request passes through: authenticate, resolve tenant,
authorize, validate, execute under scope, audit.

---

# Authentication

Authentication answers "who is this?" and nothing more; a perfect login yields an authenticated
principal holding zero permissions until authorization grants them. QAYD deliberately runs two
credential models chosen by client type, because a browser and a native or partner client have
genuinely different threat profiles. The web application uses first-party, same-site, `HttpOnly`
Sanctum session cookies so the access credential is never readable by JavaScript and cannot be
exfiltrated by a cross-site-scripting payload; the cost, cross-site request forgery, is paid down with
same-site cookies plus an explicit double-submit token. The mobile, partner, and AI-engine callers use
signed RS256 bearer JWTs stored in a hardware-backed keystore, carrying a device-fingerprint claim and
a `jti` checked against a revocation denylist so any credential dies within one request.

The product-level guarantees that matter to a buyer are that credentials are short-lived and
revocable, that the platform steps up for what matters, and that it fails closed. Multi-factor
authentication (TOTP, with passkeys as a stronger equivalent) is *mandatory*, not optional, for any
role that can approve a bank transfer, release payroll, or submit tax — a stolen password alone yields
a credential that can do nothing until a second factor is presented, and an anomaly signal forces a
fresh challenge even on an already-valid session. Passwords follow current NIST guidance (a 12-character
floor, no composition theatre, no forced periodic rotation, breach-corpus rejection, argon2id hashing)
because that is measurably safer, and it is a choice an auditor can trace to a standard. The full
adversarial treatment — session fixation, lifetimes, brute-force lockout, device trust, refresh-token
rotation with reuse detection, and forward-looking enterprise SSO — is owned by
[`../../security/AUTHENTICATION.md`](../../security/AUTHENTICATION.md). One cross-document divergence
worth flagging here: the exact first-party session-token lifetime is still being reconciled (15 minutes
vs. one hour) and is tracked as ARC-2 in [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md); the resolution
does not change the posture, only the number.

---

# Authorization

Authorization answers the narrower question "may *this* identity perform *this* action, on *this*
resource, in *this* company, right now?" It is kept rigorously separate from authentication and from
tenant resolution, because conflating them is exactly how a system accidentally grants access as a
side effect of proving identity. QAYD's model is role-based access control expressed as a closed,
auditable grammar of dotted permission keys — `<area>.<action>` and `<area>.<entity>.<action>` — with
a deliberate split between mutating a draft and making it irreversible: `accounting.journal.create`
is a different key from `accounting.journal.post`, `payroll.calculate` from `payroll.release`,
`tax.calculate` from `tax.submit`. That split is what lets a company grant broad draft-editing
capability without ever granting the power to move money or file with an authority. The catalog is
closed — a custom role can only compose keys QAYD has defined — so every permission that can appear on
a token or in an audit log is enumerable by an auditor.

Three properties make this a security model rather than a convenience layer, and all three are
enforced server-side and proven by test:

- **Deny by default with a single sanctioned exception.** A `Gate::before` floor grants nothing
  implicitly except the company Owner sentinel; every other principal must reach an explicit grant, and
  there is deliberately no "allow if nothing denied" backstop. Attribute-based rules can only *subtract*
  capability from what a role grants — an explicit resource-level deny always wins over a broad role
  grant.
- **No self-elevation, no self-approval.** A user can never grant themselves a capability they do not
  already hold, and the initiator of a sensitive action can never also be its approver — the
  maker-is-never-the-checker rule is the core of segregation of duties and is enforced at the approval
  endpoint, not merely hidden in the UI. Two-key money release is modeled as two *distinct* permissions
  held by two distinct humans, not one permission approved twice.
- **The AI takes the identical path.** An AI-originated request resolves permissions for the acting
  human exactly as if that human had called directly; the AI service credential itself authorizes
  nothing on any business resource, and the AI can never hold an approval permission. There is no
  separate, more-permissive code path for AI writes — that is the structural guarantee behind "the AI
  obeys the same permissions as the human."

The permission grammar, the role catalog, the policy interpreter, the approval-chain state machines,
and the escalation-prevention proofs are owned by
[`../../security/AUTHORIZATION.md`](../../security/AUTHORIZATION.md) and the foundational
[`../../foundation/PERMISSION_SYSTEM.md`](../../foundation/PERMISSION_SYSTEM.md).

---

# Encryption

Encryption keeps QAYD's data unreadable to an infrastructure adversary — someone who reaches a network,
a volume, a backup, or a secret store. The posture is layered by data state. In transit, everything
runs over TLS (1.2 floor, 1.3 preferred, HSTS, certificate pinning on mobile, and mutual TLS on the
service-to-service path to the AI engine). At rest, all data sits on encrypted volumes, and the subset
of data classified Restricted — salaries and payroll amounts, national IDs and passport numbers,
employee and vendor bank accounts and IBANs, MFA secrets, integration keys — additionally receives
application-layer authenticated field encryption, so a stolen volume or backup yields ciphertext, not
salaries.

The design choice that makes this operationally sustainable is a three-tier envelope key hierarchy: a
hardware-backed KMS root that never leaves the KMS, per-tenant key-encryption keys, and short-lived
data-encryption keys that actually encrypt the values. Two product-level consequences follow. First,
**rotation is cheap and never a big-bang event** — rotating a key re-wraps a few thousand small keys
rather than re-encrypting millions of business values, every ciphertext carries a version header so old
and new keys coexist during a rotation window, and no rotation ever requires downtime. Second,
**per-tenant keys enable cryptographic tenant deletion (crypto-shredding)** — destroying one company's
key material renders every one of its field-encrypted values permanently unrecoverable everywhere it
exists (live, replica, and backup) without touching another tenant. That capability is precisely what
lets QAYD satisfy an erasure obligation that cannot be met by row deletion alone. The key hierarchy,
the cipher choices, signed-URL mechanics for storage, and the rotation cadences are owned by
[`../../security/ENCRYPTION.md`](../../security/ENCRYPTION.md), with custody and vault topology in
[`../../security/SECRETS.md`](../../security/SECRETS.md).

---

# Audit Logs

For a financial platform, the audit trail is not a diagnostic convenience — it is the record that makes
every other control provable and every AI action defensible. QAYD's requirement is that every
consequential action and every access denial is captured with actor, company, resource,
before-and-after state, timestamp, and reason, that the actor is resolved server-side from the
authenticated session (never from a client-supplied field), and that the record is both immutable and
tamper-evident.

Immutability is enforced in depth so no single failure defeats it: no application role holds `UPDATE`
or `DELETE` on the audit table, a database trigger rejects mutation even from a superuser connection,
and retention removes whole partitions rather than rows. Tamper-evidence is cryptographic: each row
carries a hash chained per company (`hash = SHA256(payload || prev_hash)`), so altering any historical
row breaks verification deterministically, and an independent auditor can recompute the chain against a
raw export without trusting QAYD's code at all. For the most sensitive class of adversary — one holding
full database credentials who could replace the whole table — the daily closing hash is anchored to an
independent write-once store outside the blast radius. Crucially, a sensitive write that cannot be
audited *does not happen*: for money-moving actions the audit record is written in the same database
transaction as the mutation, so there is no state in which money moved and no one can prove who moved
it. The full immutability, hash-chain, anomaly-alerting, and PII-masking-at-capture mechanics are owned
by [`../../security/AUDIT_LOGS.md`](../../security/AUDIT_LOGS.md) and
[`../../database/DATABASE_AUDIT_LOGS.md`](../../database/DATABASE_AUDIT_LOGS.md).

---

# Tenant Isolation

The cross-tenant boundary is the single assumption the entire multi-tenant business rests on: no
company ever sees another company's data, under any bug. QAYD does not treat isolation as one control;
it treats it as four independent enforcements of the same predicate, written by different code, each
failing closed. A cross-tenant read must slip past the HTTP tenant-resolution middleware, the automatic
query scope, the policy re-check, *and* PostgreSQL row-level security — and RLS is deliberately named
the boundary of last resort, with the application scopes framed as convenience and clarity rather than
the guarantee. Losing an application scope is a bug the database catches; losing the database
enforcement would be the breach, so the redundancy is asymmetric on purpose.

Two product-level properties are worth stating plainly. First, **the boundary fails to empty, not to
all**: a request that reaches the database without a resolved tenant context returns zero rows, never
every tenant's rows, so a forgotten scope is a self-announcing bug (a user sees an empty list) rather
than a silent catastrophic disclosure. Second, **a foreign-tenant resource returns "not found," never
"forbidden,"** because "forbidden" would itself confirm the record exists and leak across the boundary
through enumeration. The boundary extends beyond the database to every store — cache keys, search
indices, object storage, and background jobs each carry the tenant namespace — because a boundary that
held only on the synchronous database path would be no boundary at all. The four-layer enforcement, the
row-level-security policy shapes, and the mandatory cross-tenant negative test suite (the single most
important test in the codebase) are owned by
[`../../security/TENANT_ISOLATION.md`](../../security/TENANT_ISOLATION.md) and
[`../../database/MULTI_TENANCY.md`](../../database/MULTI_TENANCY.md). One reconciliation is in flight —
the exact name of the tenant session variable the policies read — tracked as ARC-1 in
[`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md); it is a naming fix, not a posture change.

---

# Data Privacy

Encryption and isolation keep data unreadable to the wrong infrastructure and the wrong tenant; data
privacy governs the questions they do not answer — which data is sensitive and how sensitive, how
little QAYD may hold and for how long, whose legal basis justifies holding it, what a data subject may
see or erase, and where in the world the data may physically live. The spine of the whole scheme is a
four-class data classification (Public, Internal, Confidential, Restricted) asserted per field in the
API schema, so that a field's class mechanically determines its encryption at rest, its masking in
responses, its redaction in logs, its residency, and its retention. An unclassified field is treated as
a build-blocking bug.

Three privacy commitments are load-bearing for the product:

- **Minimization and the controller/processor split.** QAYD collects a PII element only because a
  specific workflow needs it, and for a tenant's business and employee data the *company* is the data
  controller while *QAYD* is the processor acting under a data processing agreement — QAYD is the
  controller only for its own account, security, and billing data. This split determines who answers a
  data subject's request.
- **The erasure-versus-retention conflict, resolved explicitly.** An accounting platform cannot
  naively "delete on request," because statute requires multi-year retention of accounting records;
  nor can it hide behind "we keep everything" and ignore a valid erasure right. QAYD partitions a
  subject's data by legal basis: data bound to a statutorily-retained record is retained (with the
  subject informed of the basis), and data with no surviving legal basis is erased — nulled in place
  and, for field-encrypted data, crypto-shredded so it is unrecoverable even in backups. Neither
  illegal deletion nor illegal refusal.
- **Residency by construction.** For a residency-bound tenant, the physical location of the primary
  database, its replicas, object storage, backups, and the KMS key material is pinned to a permitted
  region, and processing that touches cleartext — including the AI engine's per-request handling — runs
  in-region. Residency is enforced as a fail-closed infrastructure property, not a runtime toggle.

The classification scheme, the minimization data-map, subject-access and erasure workflows, the
third-party-sharing governance, and the breach-notification runbook are owned by
[`../../security/DATA_PRIVACY.md`](../../security/DATA_PRIVACY.md). The residency posture directly
constrains the infrastructure strategy in [`11_INFRASTRUCTURE.md`](./11_INFRASTRUCTURE.md), where the
tension between residency and cross-region disaster recovery is worked through.

---

# Compliance Posture

QAYD's compliance stance is "ready for" rather than "certified": it does not claim certification in
this document, it claims that the controls a certification would audit are specified, enforced
server-side, and testable, with a traceable path from a framework requirement to the exact control and
the document that implements it. The classification scheme and the `is_sensitive`/`requires_approval`
flags on the permission catalog are the spine that connects a regulator's language to the code.

| Framework / regime | What QAYD targets | Where the control lives |
|---|---|---|
| **SOC 2** (Security, Confidentiality, Processing Integrity) | Logical access control and least privilege; segregation of duties; two-key approval on financial mutations; immutable audit | [`../../security/AUTHORIZATION.md`](../../security/AUTHORIZATION.md), [`../../security/AUTHENTICATION.md`](../../security/AUTHENTICATION.md), [`../../security/AUDIT_LOGS.md`](../../security/AUDIT_LOGS.md) |
| **ISO 27001** | A documented information-security management system, secret management, access-control lifecycle, incident response | The whole [`docs/security/`](../../security/SECURITY_ARCHITECTURE.md) folder + [`../../security/SECRETS.md`](../../security/SECRETS.md) |
| **GDPR** (where applicable) | Lawful basis, minimization, storage limitation, subject rights, Art. 32 security, breach notice | [`../../security/DATA_PRIVACY.md`](../../security/DATA_PRIVACY.md), [`../../security/TENANT_ISOLATION.md`](../../security/TENANT_ISOLATION.md) |
| **GCC / Kuwait data-protection** | In-region residency, field encryption of PII, regulator breach notification | [`../../security/DATA_PRIVACY.md`](../../security/DATA_PRIVACY.md), residency in [`11_INFRASTRUCTURE.md`](./11_INFRASTRUCTURE.md) |
| **Kuwait / GCC commercial & tax law** | Multi-year tamper-evident retention of accounting records; authorized, auditable tax submission | Audit hash chain + the `tax.submit` two-key control |

Two of the compliance-adjacent decisions remain open and are tracked in
[`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md): the residency-vs-DR-region tension (CMP-2), which is a
security boundary that must be frozen before the infrastructure topology is, and in-region AI inference
for residency-bound tenants (AI-2), where frontier model providers may not offer a GCC-region
endpoint. Both are flagged as blocking precisely because a promise made to a buyer or a regulator must
match what the infrastructure actually does.

---

## Related Documents

- [`../../security/SECURITY_ARCHITECTURE.md`](../../security/SECURITY_ARCHITECTURE.md) — the trust-boundary map and layered model this chapter summarizes.
- [`../../security/AUTHENTICATION.md`](../../security/AUTHENTICATION.md), [`../../security/AUTHORIZATION.md`](../../security/AUTHORIZATION.md) — the authN/authZ mechanics in depth.
- [`../../security/ENCRYPTION.md`](../../security/ENCRYPTION.md), [`../../security/SECRETS.md`](../../security/SECRETS.md) — encryption, the key hierarchy, and secret custody.
- [`../../security/AUDIT_LOGS.md`](../../security/AUDIT_LOGS.md) — immutable, tamper-evident audit logging.
- [`../../security/TENANT_ISOLATION.md`](../../security/TENANT_ISOLATION.md), [`../../security/DATA_PRIVACY.md`](../../security/DATA_PRIVACY.md) — tenant isolation and the data-lifecycle/privacy policy.
- [`../../foundation/PERMISSION_SYSTEM.md`](../../foundation/PERMISSION_SYSTEM.md), [`../../foundation/SECURITY_ARCHITECTURE.md`](../../foundation/SECURITY_ARCHITECTURE.md) — the foundational permission and security principles.
- [`11_INFRASTRUCTURE.md`](./11_INFRASTRUCTURE.md) — where residency and disaster-recovery topology are resolved.
- [`../MASTER_PRD.md`](../MASTER_PRD.md) — the product invariants (100% human-approved sensitive actions, zero cross-tenant leakage) this chapter enforces.
- [`../OPEN_QUESTIONS.md`](../OPEN_QUESTIONS.md) — open items ARC-1 (RLS variable name), ARC-2 (token TTL), CMP-2 (residency vs DR), AI-2 (in-region inference).

# End of Document
