# Data Protection & Privacy — QAYD Security
Version: 1.0
Status: Design Specification
Module: Security
Submodule: DATA_PRIVACY
---

# Purpose

QAYD holds two categories of data whose exposure is not merely embarrassing but individually harmful
and legally reportable: a company's financial truth (ledgers, bank balances, tax positions) and the
personally identifiable information of real people (employees' salaries, civil IDs, passports, bank
accounts; counterparties' tax numbers and contact details). Encryption ([ENCRYPTION.md](ENCRYPTION.md))
keeps that data unreadable to an infrastructure adversary; authorization and tenant isolation keep it
unreadable to the wrong user or the wrong company. This document governs the questions those two do
not answer: *which* data is sensitive and how sensitive, how little of it QAYD is allowed to hold and
for how long, whose consent or legal basis justifies holding it, what a data subject may ask to see or
erase, where in the world it may physically live, and what QAYD must do when — despite every control —
some of it leaks.

This is the platform's **data-lifecycle and privacy specification**. It defines the classification
scheme every other security document refers to (Public / Internal / Confidential / Restricted), and it
resolves the one conflict that a naive privacy policy gets dangerously wrong for an accounting
platform: the collision between a data subject's right to erasure and the statutory obligation to
retain accounting records for years. It complements rather than restates its siblings: the cryptography
that enforces classification is [ENCRYPTION.md](ENCRYPTION.md); PII redaction in logs and API responses
at the API edge is [API_SECURITY.md](API_SECURITY.md); the same redaction on the path to the AI engine
is [AI_SECURITY.md](AI_SECURITY.md); and the record of every access and erasure is
[AUDIT_LOGS.md](AUDIT_LOGS.md). Where those documents fix a mechanism, this document fixes the
*policy the mechanism implements*.

The audience is the engineer choosing a field's classification when adding a column, the one wiring an
erasure or subject-access request, the platform engineer configuring data residency, and the security
officer who must answer a regulator's questions about what QAYD holds and why.

---

# Threat Model / Principles

## What this document defends against

Privacy failures are rarely a single dramatic breach; they are usually the slow accumulation of data
QAYD never needed, held too long, in the wrong place, visible to too many.

| # | Threat | The control that answers it |
|---|--------|-----------------------------|
| P1 | Over-collection — QAYD holds PII it has no operational need for | Data minimization; collection tied to a named purpose |
| P2 | Over-retention — data kept long after its purpose ended, expanding breach blast radius | Retention schedule + automated deletion, reconciled against legal hold |
| P3 | The erasure-vs-retention conflict resolved wrongly (either illegally deleting books, or illegally refusing erasure) | The explicit resolution in § Retention, Deletion & the Erasure Conflict |
| P4 | PII leaking through a side channel — a log line, an error message, an AI prompt, a URL | Classification-driven redaction in logs, responses, and AI context |
| P5 | Sensitive data leaving its permitted jurisdiction | Data-residency controls (GCC/Kuwait) on storage, backup, and processing |
| P6 | Uncontrolled onward sharing to a third party (subprocessor, integration, model provider) | Third-party data-sharing governance + DPAs + minimization on egress |
| P7 | A breach that goes unnoticed or unreported within the legal window | Breach-detection tie-in + a defined notification runbook |
| P8 | A data subject unable to exercise their rights (access, correction, erasure) | Defined subject-request workflow with identity verification |

## Principles

- **Classify first; every control follows from the class.** A field's classification is not a label
  for a policy document — it is the input that mechanically determines its encryption at rest, its
  masking in responses, its redaction in logs, its residency, and its retention. An unclassified field
  is a bug.
- **Collect the minimum, for a stated purpose.** QAYD collects a piece of PII only because a specific
  workflow needs it (payroll disbursement needs an IBAN; an invoice does not need an employee's home
  address). "It might be useful later" is not a purpose.
- **Retention is a schedule, not a default of "forever."** Every data class has a defined lifetime.
  Where statute *requires* retention (accounting records), that floor wins and is documented; where it
  does not, data expires and is deleted or crypto-shredded.
- **Erasure and legal retention are reconciled explicitly, never silently.** The platform never
  illegally deletes a statutorily-retained record to satisfy an erasure request, and never uses
  "we keep everything" as an excuse to ignore a legitimate erasure right. § Retention states exactly
  how the two coexist.
- **The company is the controller; QAYD is the processor.** For the tenant's business data, the
  customer company decides the purpose and legal basis; QAYD processes on its behalf under a data
  processing agreement. QAYD is the *controller* only for its own account/operational data (the login
  identity, billing).
- **Privacy is enforced by construction, tested, and residency-aware** — not asserted in a policy PDF.
  A redaction that only exists in a policy is a leak waiting to happen.

---

# Controls: Data Classification

Four classes. Every column, document, log field, and API response field maps to exactly one, and the
class is asserted in the endpoint's OpenAPI schema so it is visible to code and to review, not only to
a human reading prose.

| Class | Definition | QAYD examples | Encryption at rest | In logs | In API responses |
|---|---|---|---|---|---|
| **Public** | Safe to disclose to anyone | Marketing copy, currency codes, the agent catalogue (`ai_agents`), public help content | Volume only | Freely | Freely |
| **Internal** | Not secret, but not for outside eyes; no individual harm if leaked internally | Non-sensitive config, feature flags, aggregate non-identifying metrics | Volume only | Freely | To authenticated tenant users |
| **Confidential** | The company's business data; a leak damages the company | Ledger, invoices, journal lines, product/price lists, most master data; company/vendor `tax_id` | Volume; field-encrypted where standalone-identifier risk (tax IDs) | Redacted where it identifies a party | Tenant-scoped, permissioned |
| **Restricted** | Individually sensitive PII or credential-grade secrets; a single leak is reportable | Salaries/payroll amounts, `national_id`, `passport_no`, employee/vendor bank accounts & IBANs, `mfa_secret`, integration API keys | **Field-encrypted** (app-layer AEAD), per [ENCRYPTION.md](ENCRYPTION.md) | **Never** — redacted at source | Masked by default; full only with a specific reveal permission, the reveal audited |

The Restricted set is the same set [ENCRYPTION.md](ENCRYPTION.md) § Application-Level Field Encryption
enumerates for field encryption, by design: classification drives encryption, so the two lists cannot
drift. The mapping is authoritative here; the cryptographic *mechanism* is authoritative there.

**Assigning a class is a review-gated decision.** A CI check parses migrations: any new column whose
name matches the Restricted-pattern list (`*national_id`, `*account_number`, `*_secret`, `salary*`,
`iban`, `passport*`, `mfa_secret`, `*tax_id`) must carry both an `EncryptedField` cast
([ENCRYPTION.md](ENCRYPTION.md)) and a masking-registry entry ([AUDIT_LOGS.md](AUDIT_LOGS.md)), or the
build fails. A field that *looks* sensitive but is deliberately classified lower requires an explicit,
reviewed annotation stating why — the default for an ambiguous field is the *more* restrictive class.

---

# Controls: Minimization, Consent & Lawful Basis

**Minimization by purpose.** Each PII element QAYD stores is tied in the data map to the workflow that
justifies it. The security consequence is a smaller Restricted surface — the less PII held, the smaller
every breach.

| PII element | Purpose that justifies it | Held only when |
|---|---|---|
| Employee `national_id` / `passport_no` | Statutory payroll & WPS reporting, right-to-work | The company runs payroll for that employee |
| Employee `bank_account_number` / IBAN | Salary disbursement | Payroll disbursement is configured |
| Employee home address / next-of-kin | Specific HR/WPS workflows | That workflow is active; never summarized into durable AI memory |
| Counterparty `tax_id` | Tax reporting, e-invoicing | The company transacts with that party under tax rules |
| Login email / name | Authentication, attribution | The account exists |

A workflow that wants a new PII element must justify it against a purpose; "collect it in case" is
rejected in review. Fields collected for a purpose that later ends become candidates for deletion under
the retention schedule.

**Lawful basis and the controller/processor split.** For a tenant's business and employee data, the
**company is the data controller** — it determines the purpose and holds the lawful basis (in most
cases the legal obligation to keep accounting/payroll records and the contractual necessity of
employment). **QAYD is the data processor**, acting on the company's documented instructions under a
Data Processing Agreement. QAYD is the *controller* only for its own relationship with the account
holder: the login identity, security events, and billing. This split determines who answers a data
subject: an employee's subject-access request is fulfilled *by the company*, with QAYD providing the
tooling and the export; a request about QAYD's own account/security data is answered by QAYD.

**Consent where consent is the basis.** Where processing rests on consent rather than legal
obligation or contract (e.g. optional analytics, non-essential communications), consent is explicit,
recorded with timestamp and scope, and revocable — and revocation propagates to stop the processing it
covered. Consent is never bundled with the terms of using the product, and never assumed from silence.
The most privacy-preserving default is chosen for any optional processing.

---

# Controls: Retention, Deletion & the Erasure Conflict

This is the section an accounting platform cannot afford to get wrong. A generic "delete on request"
policy would have QAYD illegally destroying statutorily-retained books; a generic "keep everything"
policy would have QAYD illegally ignoring a valid erasure right. QAYD resolves the conflict explicitly.

**Retention schedule (floors are statutory; ceilings are minimization):**

| Data | Retention | Basis |
|---|---|---|
| Posted accounting records (journal entries, invoices, bills, tax filings) | Statutory floor (Kuwait/GCC commercial & tax law generally require multi-year retention; configurable *longer* via `companies.audit_retention_years`, never shorter than the platform floor) | Legal obligation — **overrides erasure** |
| `audit_logs` | Same statutory floor, partitioned; legal hold suspends expiry | Legal obligation + evidentiary integrity ([AUDIT_LOGS.md](AUDIT_LOGS.md)) |
| Employee PII not bound to a retained financial record (home address, next-of-kin, personal phone) | Duration of the employment relationship + a short statutory tail, then erased | Contractual necessity, then no basis |
| Login/security data of a closed account | A short, defined window after account closure, then deleted | Security & dispute window, then no basis |
| Optional/consent-based data | Until consent is withdrawn or the stated purpose ends | Consent |
| AI working memory / ephemeral context | Minutes to a task's lifetime; never a durable store of raw PII | No standing purpose to persist |

**The resolution, stated precisely.** When a data subject exercises a right to erasure, QAYD
partitions the subject's data into two sets:

1. **Data under a statutory retention obligation** (a salary that appears on a posted, retained
   payroll record; a counterparty tax ID on a filed return). This data is **not deleted**, because
   deleting it would violate accounting/tax law and destroy the integrity of the books. The subject is
   informed, with the specific legal basis for continued retention, that this data is retained for the
   statutory period and will then expire. The relevant record's *non-statutory* PII is still minimized.
2. **Data with no surviving legal basis** (the departed employee's home address, next-of-kin, personal
   contact details — PII collected for a workflow that has ended and that no statute requires). This is
   **erased**: the business row's PII columns are nulled/masked in place, and — for field-encrypted
   Restricted data — the platform can additionally **crypto-shred**, destroying the per-tenant field-KEK
   material so the ciphertext is permanently unrecoverable everywhere it exists (live, replica, backup)
   without touching another tenant. Crypto-shredding is the mechanism [ENCRYPTION.md](ENCRYPTION.md)
   § Key Hierarchy provides specifically because erasure cannot always be met by row deletion alone.

Every erasure writes an audit row (`action = '*.pii_erased'`) citing the legal basis, and the
historical audit rows referencing the subject are **left untouched** — they were masked at capture
time ([AUDIT_LOGS.md](AUDIT_LOGS.md) § Data Handling), so they never held the plaintext to begin with,
and the hash chain guarantees they cannot be retroactively edited. The erasure is thus complete for the
data with no basis, honest about the data with a basis, and evidentially intact throughout.

```php
// Simplified erasure orchestration: partition by legal basis, then erase only the unbound set.
public function eraseSubject(Employee $employee, string $legalBasisCitation): ErasureReceipt
{
    $bound   = $this->recordsUnderStatutoryRetention($employee);   // stays, subject informed
    $unbound = $this->pesonalDataWithNoSurvivingBasis($employee);  // erased now

    DB::transaction(function () use ($employee, $unbound, $legalBasisCitation) {
        foreach ($unbound as $field) {
            $employee->{$field} = null;                             // null/mask in place
        }
        $employee->save();

        AuditLog::record(
            action: 'employee.pii_erased',
            entity: $employee,
            new:    ['erased_fields' => array_keys($unbound), 'basis' => $legalBasisCitation],
            reason: $legalBasisCitation,                            // NOT NULL-enforced for erasure
        );
    });

    // Field-encrypted Restricted values with no surviving business row are crypto-shredded
    // via the per-tenant KEK mechanism in ENCRYPTION.md, not merely row-deleted.
    return new ErasureReceipt(erased: $unbound, retained: $bound, retainedBasis: $legalBasisCitation);
}
```

**Automated expiry.** Non-statutory data past its retention window is deleted by a scheduled job, not
left to accumulate. The job checks `companies.legal_hold` and open-tax-audit flags before deleting
anything, so a litigation hold freezes the relevant window intact (the hold mechanics are in
[AUDIT_LOGS.md](AUDIT_LOGS.md) / [../database/DATABASE_AUDIT_LOGS.md](../database/DATABASE_AUDIT_LOGS.md)).

---

# Implementation

**Subject-access requests (SAR).** A data subject may request a copy of the personal data held about
them. Because QAYD is usually the *processor*, the request is fulfilled through the controlling company,
with QAYD supplying the tooling:

- **Identity verification first.** A SAR is honored only after the requester's identity is verified —
  an unverified "send me everything about employee X" is itself an attack. Verification uses the
  company's existing authenticated relationship with the subject, never a self-asserted claim in the
  request text.
- **Scoped, tenant-bounded export.** The export is assembled server-side from the subject's own records
  within one company, RLS-scoped, and delivered as a signed, short-TTL download
  ([ENCRYPTION.md](ENCRYPTION.md) § Signed URLs) — never emailed as an attachment, never a permanent
  link.
- **The SAR itself is audited** (`action = 'subject_access.exported'`), so a request for a person's
  data is as attributable as any other access to it.

**PII redaction across side channels.** The same classification drives redaction everywhere PII could
escape the primary store:

- **In logs:** the logging pipeline redacts Restricted-pattern fields at source; a value that reached a
  log as ciphertext is still treated as sensitive and not emitted. Owned at the API edge by
  [API_SECURITY.md](API_SECURITY.md) and [../api/API_LOGGING.md](../api/API_LOGGING.md).
- **In API responses:** Restricted fields serialize masked by default (`**** **** **** 4471`) and in
  full only to a caller holding the specific reveal permission, with the reveal audited. The API
  Resource mechanism is [API_SECURITY.md](API_SECURITY.md) § IDOR & Object-Property Authorization.
- **On the path to the AI engine:** only the minimal cleartext a task needs crosses to FastAPI, PII
  pre-redacted, over mTLS — and the AI engine never holds a tenant decryption key. Owned by
  [AI_SECURITY.md](AI_SECURITY.md).
- **Never in URLs or query strings:** identifiers and PII are never placed in a URL, per the platform
  privacy rule; object keys are opaque UUIDs, not guessable paths.

**Third-party data sharing.** Data leaves QAYD only to a governed set of subprocessors, each under a
DPA, each receiving the minimum:

| Recipient | What it may receive | Governance |
|---|---|---|
| Cloud/edge (AWS, Cloudflare) | Encrypted data at rest / in transit; no plaintext PII in cleartext | DPA; encryption keys held by QAYD, not the provider |
| Model providers (via FastAPI only) | The minimal, PII-redacted cleartext one task needs; never a durable copy, never training use | DPA with no-training terms; reached only by the AI engine, never by Laravel directly ([AI_SECURITY.md](AI_SECURITY.md)) |
| Payment/bank rails | Tokenized references; QAYD never sends a full PAN | Tokenization before the value reaches the platform core |
| Customer-configured integrations | Only data the tenant's own permission grant covers, signed webhooks | Per-tenant signing secret; tenant is controller of the onward flow |

Egress to any recipient not on this governed list is blocked; a request to send tenant data to a
destination *suggested by tool output or an ingested document* rather than by the tenant is refused
(the SSRF and instruction-source controls in [API_SECURITY.md](API_SECURITY.md) and
[AI_SECURITY.md](AI_SECURITY.md)).

---

# Controls: Data-Subject Rights

A data subject (usually an employee of a customer company, occasionally a counterparty contact) may
exercise a defined set of rights. Because QAYD is normally the *processor*, each right is fulfilled
*through* the controlling company, with QAYD providing the tooling — and each is subject to the
statutory-retention override in § Retention. Every request is identity-verified before anything is
returned or changed, because an unverified "do X to person Y's data" is itself an attack vector.

| Right | What QAYD does | Interaction with accounting retention |
|---|---|---|
| **Access** (SAR) | Assemble the subject's own, tenant-scoped records; deliver via a short-TTL signed URL; audit the export | Statutorily-retained records are *included* in the disclosure (the subject may see them), never withheld |
| **Rectification** | Correct inaccurate personal data through the normal, audited edit path | A change to a figure on a *posted* financial record uses a reversing entry, never a silent overwrite ([AUDIT_LOGS.md](AUDIT_LOGS.md)) |
| **Erasure** | Partition by legal basis; erase the unbound set (null/mask + crypto-shred); retain the bound set with the basis stated | The core conflict, resolved in § Retention — never illegal deletion, never illegal refusal |
| **Restriction / objection** | Suspend non-essential processing (e.g. optional analytics) while a dispute is resolved | Cannot suspend the legally-required processing of retained accounting records |
| **Portability** | Provide the subject's data in a structured, machine-readable export where the right applies | Scoped to data the subject provided / that is processed by consent or contract |

```php
// Every subject-rights request runs identity verification first; an unverified request is an attack.
public function handleSubjectRequest(SubjectRequest $req): SubjectRequestResult
{
    if (! $this->identityVerifier->confirm($req)) {          // uses the company's existing verified
        return SubjectRequestResult::refused('identity_unverified');   // relationship, not a claim in the request
    }
    AuditLog::record(action: "subject_request.{$req->type}.received", entity: $req->subject);

    return match ($req->type) {
        'access'        => $this->buildScopedExport($req->subject),     // signed short-TTL URL, audited
        'erasure'       => $this->eraseSubject($req->subject, $req->basis),  // partition by legal basis
        'rectification' => $this->correctViaAuditedPath($req),
        default         => SubjectRequestResult::refused('unsupported'),
    };
}
```

The security property throughout: a subject-rights request can only ever act on *one* company's data
for *one* verified subject, is RLS-scoped, and is fully audited — the tooling that helps a person
exercise their rights cannot be turned into a tool for pulling someone else's data.

# Enforcement Points

| Enforcement point | Mechanism | Requirement enforced |
|---|---|---|
| OpenAPI schema `x-data-class` per field | Schema annotation checked in CI | Every field carries a classification |
| CI classification/encryption/masking diff | Migration parser | Restricted columns get field encryption + masking, or build fails |
| `EncryptedField` cast | App-layer AEAD ([ENCRYPTION.md](ENCRYPTION.md)) | Restricted data encrypted at rest |
| API Resource conditional fields | `$this->when($user->can('...reveal'), ...)` | Masked-by-default responses; reveal permissioned + audited |
| Log-redaction middleware | Restricted-pattern scrub before emit | No PII in logs |
| Retention/expiry scheduled job | Deletes non-statutory data past its window, checks legal hold | Minimization over time |
| Crypto-shred on erasure | Per-tenant KEK destruction | Erasure of field-encrypted data everywhere, incl. backups |
| SAR export builder | RLS-scoped, identity-verified, signed-URL delivery, audited | Subject access without leakage |
| Residency configuration (Terraform) | Region-pinned storage, backup, and processing | GCC/Kuwait data residency |
| Egress allow-list | Governed-subprocessor enforcement | No uncontrolled onward sharing |

---

# Data Handling

**Data residency (GCC / Kuwait).** For customers whose regulatory or contractual posture requires it,
QAYD pins the physical location of tenant data: the primary database, its replicas, object storage, and
backups are provisioned in a permitted region, and the per-tenant field-KEKs and their KMS root live in
that region's key store. Processing that touches cleartext — including the AI engine's per-request
handling — runs in-region, so PII does not transit out of the permitted jurisdiction to be reasoned
over. Residency is a Terraform-enforced infrastructure property, not a runtime toggle, so a plaintext
store outside the permitted region fails the infra policy check the same way an unencrypted volume does
([ENCRYPTION.md](ENCRYPTION.md) § Enforcement Points). Where a subprocessor cannot guarantee in-region
processing, that subprocessor is not used for Restricted data.

**Data in each state:**

- **At rest:** classified per the table above — volume encryption for all, field encryption for
  Restricted; backups inherit at-rest plus an independent backup key ([ENCRYPTION.md](ENCRYPTION.md)).
- **In transit:** TLS everywhere, mTLS to the AI engine; PII never in URLs.
- **In use (memory):** decrypted PII lives only for the request that needs it; never cached in cleartext
  in Redis; AI working memory holds task-scoped, PII-redacted context, never a durable PII store.
- **In the audit trail:** PII masked at write time, never persisted in cleartext even there
  ([AUDIT_LOGS.md](AUDIT_LOGS.md)).
- **On egress to third parties:** minimized and governed per the sharing table above.

---

# Failure Modes

Privacy controls fail toward *less* exposure, never more.

| Failure | Behavior | Rationale |
|---|---|---|
| A field is added without a classification | CI build fails | An unclassified field cannot be safely handled; block before it ships |
| Erasure request collides with statutory retention | Non-statutory PII erased; statutory records retained with the subject informed of the basis | Neither illegal deletion nor illegal refusal — the explicit resolution |
| Reveal-permission check indeterminate on a Restricted field | Field returns masked | Default to concealment when authorization is not a confident yes |
| Redaction pipeline unavailable for a log sink | The sink is skipped rather than emitting unredacted | Better a missing log line than a leaked salary |
| Residency check cannot confirm in-region placement | Provisioning fails; no out-of-region plaintext store is created | Residency is fail-closed, like encryption |
| Egress destination not on the governed subprocessor list | Blocked | No uncontrolled onward sharing |
| Breach detected | The notification runbook engages (below); containment first | A detected breach is an incident, not a ticket |
| SAR requester identity unverified | Request refused pending verification | An unverified data request is a potential attack |

**Breach notification runbook.** A confirmed or suspected exposure of Confidential or Restricted data
engages a defined sequence: (1) **contain** — revoke the implicated credentials/keys, isolate the
affected store, crypto-shred if a key is implicated; (2) **assess scope** using the audit trail and
SIEM to establish exactly which tenants and which data classes are affected, with attribution; (3)
**notify** — the affected controlling companies without undue delay and, where the applicable GCC /
data-protection regime requires it, the regulator within the mandated window, with the assessed scope;
(4) **record** — the incident, the timeline, and the remediation, as an auditable artifact. The
technical detection that triggers step 1 comes from [AUDIT_LOGS.md](AUDIT_LOGS.md) anomaly alerting and
the incident-response mechanics in [API_SECURITY.md](API_SECURITY.md); this document owns the *privacy
obligation* — who must be told, and by when.

---

# Compliance

| Framework | Privacy obligation | How QAYD meets it |
|---|---|---|
| GDPR (where applicable) | Lawful basis, minimization, storage limitation, subject rights, Art. 32 security, breach notice | Classification-driven controls; controller/processor split + DPAs; SAR & erasure workflows; crypto-shredding; breach runbook |
| GCC / Kuwait data-protection regimes | Protection of personal data, residency, breach handling | In-region residency; field encryption of PII; regulator-notification runbook |
| Kuwait / GCC commercial & tax law | Multi-year retention of accounting records | Statutory retention floor that overrides erasure, documented and reconciled |
| SOC 2 (Confidentiality) | Data classified, access-restricted, disposed of per policy | Four-class scheme; permissioned reveal; scheduled expiry |
| PCI-adjacent | No card data at rest; PII not in logs/URLs | Tokenization before ingress; redaction pipeline |

QAYD does not claim certification here; it claims that the privacy controls a certification would audit
are specified, classification-driven, enforced by construction, and testable. The classification scheme
is the spine that connects a field to its encryption, its masking, its residency, and its retention —
and that spine is asserted in each endpoint's OpenAPI schema so it is checkable, not merely asserted.

---

# Testing & Verification

**Static gates (CI):**

- Every OpenAPI field carries an `x-data-class`; a field without one fails the spec lint.
- The Restricted-pattern migration check: encryption cast + masking entry present, or build fails.
- An egress-allow-list lint: no HTTP client targets a host outside the governed subprocessor set.

**Behavioral tests (must be green):**

- **Masking-by-default:** a Restricted field serializes masked; unmasked only with the reveal grant;
  the reveal writes an audit row.
- **Erasure partition:** erasing a departed employee nulls the unbound PII, retains the salary that
  appears on a posted payroll record, informs with a basis, and leaves the (already-masked) audit rows
  intact.
- **Crypto-shred:** destroying a test tenant's field-KEK renders its Restricted values unrecoverable
  across live + backup, and no other tenant is affected ([ENCRYPTION.md](ENCRYPTION.md) drill).
- **SAR:** an identity-verified SAR returns only the subject's own, tenant-scoped data via a
  short-TTL signed URL, and audits the export; an unverified SAR is refused.
- **Log redaction:** a request carrying a salary and an IBAN produces log lines with neither in
  cleartext.
- **Residency:** provisioning an out-of-region plaintext store fails the infra policy check.
- **No-PII-in-URL:** a scan asserts no endpoint accepts or emits a Restricted value in a path or query
  string.

**Periodic verification:**

- An annual review of the data map: is every stored PII element still tied to a live purpose, or is it
  now over-retention?
- The crypto-shred and backup-restore drills run at least semi-annually.
- A tabletop of the breach-notification runbook, timing the path from detection to (simulated)
  regulator notice against the mandated window.

The acceptance bar: for any PII element in the schema, an engineer can name its class, show the
encryption and masking that class mandates, name the purpose that justifies holding it, state its
retention and how erasure interacts with it, and point at the test that proves it is not leaking through
logs, responses, URLs, or the AI path.

---

# End of Document
