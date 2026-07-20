# File Service — QAYD Backend
Version: 1.0
Status: Design Specification
Module: Backend
Submodule: FILE_SERVICE
---

# Purpose

The File Service is the Laravel-side authority over every stored file in QAYD — a photographed receipt,
an emailed PDF invoice, a scanned bank statement, a signed lease, a payslip. It owns the private object
storage boundary (Cloudflare R2 / S3), the presigned upload and download flow, the polymorphic
`attachments` model that links a stored object to any business record, and the entry point into the AI
extraction pipeline that turns an unstructured scan into structured, confidence-scored fields. It is the
backend the Document Center screen ([../frontend/DOCUMENT_CENTER.md](../frontend/DOCUMENT_CENTER.md))
renders and every record screen's Attachments tab writes through.

Two rules govern every design decision below. First, **no file is ever public**. Objects live in a
private R2 bucket with no public read; every byte a user sees is delivered through a short-lived,
signed URL minted for that user, for that object, after an RBAC check — the storage layer has no
ambient public path to leak. Second, **the AI never turns an extraction into a financial fact by
itself**. Document AI and the OCR Agent classify and extract with per-field confidence, and the File
Service files those results onto the `attachments` row and hands off to the *owning* module's own
governed create flow (Purchasing's bill draft, an expense claim) — it never posts a ledger entry, and
even a high-confidence extraction becomes a *draft* a human approves, never an auto-committed
transaction. A stored file, by contrast, simply *is* stored the moment the upload succeeds: an
`attachments` row has no draft/approval lifecycle of its own (its status is `active` or `archived`,
nothing more), because saving a scan moves no money — the approval gate lives one layer downstream, in
whichever module makes a ledger fact out of the extraction, per
[../frontend/DOCUMENT_CENTER.md](../frontend/DOCUMENT_CENTER.md).

This document is the backend contract behind the Document Center and the platform's document tools. It
cross-links [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md) for the data-classification,
retention, and access-audit obligations that govern stored files (many of which carry PII — national ID
scans, payslips, bank statements), and defers to it on the privacy policy this service *enforces*.
Everything here is Laravel 12 / PHP 8.4 per [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md).

# Responsibilities

| # | Responsibility | Boundary |
|---|---|---|
| 1 | Mint presigned upload URLs and finalize uploaded objects into `attachments` rows | The bytes go browser→R2 directly; Laravel never proxies the payload |
| 2 | Mint short-lived signed download/preview URLs after an RBAC check | No object is ever public; every read is a scoped, expiring URL |
| 3 | Own the polymorphic `attachments` model and its links to any business record | It links a file to an entity; it never mutates that entity's financial data |
| 4 | Validate uploads: MIME/type allow-list, size, and virus/malware scan before an object is usable | An unvalidated or infected object is quarantined, never linked or served |
| 5 | Drive the AI extraction pipeline: register → OCR → classify → extract with per-field confidence | ≥95% per-field confidence auto-fills; below-threshold fields go to human review |
| 6 | File extraction results onto the `attachments` row and hand off to the owning module's create flow | It stores `extracted_data`; the owning module turns it into a governed draft |
| 7 | Enforce retention windows and legal holds on stored files | A file under statutory retention or legal hold cannot be hard-deleted |
| 8 | Version files, and record every access for audit | A re-upload is a new version; every signed-URL mint is audited |

What this service does **not** own: the *AI reasoning* itself (Document AI / OCR Agent in the FastAPI
engine — [AI_SERVICE.md](./AI_SERVICE.md)); the *ledger effect* of an extraction (the owning module's
Service); the *Document Center screen* ([../frontend/DOCUMENT_CENTER.md](../frontend/DOCUMENT_CENTER.md));
and the *privacy policy* it enforces ([../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md)). It is
the storage, linking, validation, extraction-pipeline, and access-control layer for files.

# Domain Model

The File Service owns the Documents family of tables, whose DDL is authoritative in the platform ERD and
[../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md).

- **`attachments`** — the polymorphic core. `id`, `company_id`, `attachable_type`, `attachable_id`
  (the record it is filed against — a `bill`, `journal_entry`, `employee`, `bank_statement`, or the
  upload-only staging parent `inbox`), `file_name`, `mime_type`, `size_bytes`, `object_key` (the private
  R2 key — never a URL), `checksum` (SHA-256, for dedupe and integrity), `status`
  (`active` | `archived` — no draft/approval state), `document_folder_id`, `classification` (the
  `classify_document` output enum + confidence), `extracted_data` (JSONB — OCR text, per-field values +
  confidences), `is_ai_extracted`, `source_channel` (`user_upload` | `email_inbox` | `api` | `ai_agent`),
  `uploaded_by`, `created_at`. Because the pair is polymorphic, there is no real FK on
  `(attachable_type, attachable_id)`; referential cleanup is a model observer, the one deliberate
  exception to "every relationship is a real FK" noted in
  [../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md).
- **`document_folders`** — the human organizer tree. `id`, `company_id`, `name`, `parent_folder_id`
  (a nestable tree), `created_by`. A folder is organization, not a lifecycle; a file's `document_folder_id`
  is nullable.
- **`document_versions`** — the version history. `id`, `company_id`, `attachment_id`, `version_no`,
  `object_key`, `checksum`, `size_bytes`, `uploaded_by`, `created_at`. A re-upload against an existing
  attachment appends a version; the `attachments` row always points at the current one, and every prior
  version remains individually downloadable via its own signed URL.
- **Retention / legal hold** — a stored file carries a computed retention window (e.g. 10 years for a
  commercial document under the Kuwait Commercial Code) and an optional legal-hold flag. These are
  policy facts, not AI outputs, sourced per [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md);
  the service enforces them on delete.

The extraction lifecycle the pipeline drives over an `attachments` row's `extracted_data`:

```
registered ──► ocr_done ──► classified ──► extracted            (Document AI + OCR Agent, per-field confidence)
                                    │
                                    ├─ every field ≥95% ──► auto_filled   (owning module create flow pre-filled)
                                    └─ any field <95%  ──► needs_review    (surfaced to a human before use)
```

Note what this lifecycle is *not*: it is never `pending_approval` on the `attachments` row itself. The
row is `active` from the instant the object finalizes; extraction enriches it, and the approval gate for
the *financial fact* the extraction feeds lives in the owning module (a `bills` row in `draft`, never
auto-posted), exactly as [../frontend/DOCUMENT_CENTER.md](../frontend/DOCUMENT_CENTER.md) requires.

# Key Classes

Namespaced under `App\Services\Files`, controllers under `App\Http\Controllers\Api\V1\Documents`. The
storage transport sits behind an interface so R2/S3 is swappable and fakeable in tests, and the object
store is the only place that holds bucket credentials.

```php
<?php

namespace App\Services\Files;

/** The private object-storage boundary. The only class that speaks to R2/S3 and mints signed URLs. */
interface ObjectStore
{
    /** A short-lived presigned PUT the browser uploads directly to — bytes never touch Laravel. */
    public function presignedPut(string $objectKey, string $mimeType, int $maxBytes, int $ttlSeconds): PresignedUrl;

    /** A short-lived presigned GET for preview/download — minted per user, per object, after an RBAC check. */
    public function presignedGet(string $objectKey, int $ttlSeconds, ?string $downloadName = null): PresignedUrl;

    public function head(string $objectKey): ObjectMetadata;   // confirm existence, size, content-type post-upload
    public function delete(string $objectKey): void;           // hard delete — gated by retention/legal-hold upstream
}
```

```php
<?php

namespace App\Services\Files;

use App\Data\Files\PresignedUploadRequest;
use App\Data\Files\PresignedUploadResponse;
use App\Models\User;

/** Step 1 of the three-step upload: hand the browser a presigned PUT and a reserved object key. */
final class PresignUploadAction
{
    public function __construct(
        private readonly ObjectStore $store,
        private readonly FileValidationPolicy $validation,
    ) {}

    public function execute(PresignedUploadRequest $req, User $actor): PresignedUploadResponse
    {
        $this->validation->assertAllowed($req->mimeType, $req->sizeBytes);   // MIME allow-list + size cap, pre-issue

        // Tenant-partitioned key: no object is addressable without knowing company + a random ULID.
        $objectKey = sprintf('c/%d/%s/%s', $actor->company_id, now()->format('Y/m'), (string) Str::ulid());

        $presigned = $this->store->presignedPut(
            $objectKey, $req->mimeType, maxBytes: $this->validation->cap($req->mimeType), ttlSeconds: 300,
        );

        return new PresignedUploadResponse(
            uploadUrl: $presigned->url,
            objectKey: $objectKey,          // opaque; the finalize call references it, never raw bytes
            expiresAt: $presigned->expiresAt,
        );
    }
}
```

```php
<?php

namespace App\Services\Files;

use App\Data\Files\FinalizeUploadData;
use App\Events\Files\DocumentRegistered;
use App\Models\Files\Attachment;
use Illuminate\Support\Facades\DB;

/** Step 3: after the browser PUT the bytes, create the attachments row referencing the object already in R2. */
final class FinalizeUploadAction
{
    public function __construct(
        private readonly ObjectStore $store,
        private readonly AttachmentRepository $attachments,
        private readonly FileValidationPolicy $validation,
    ) {}

    public function execute(FinalizeUploadData $data, User $actor): Attachment
    {
        $meta = $this->store->head($data->objectKey);                 // confirm the object exists & matches claims
        $this->validation->assertMatches($meta, $data);               // size/mime the object actually is, not just claimed

        return DB::transaction(function () use ($data, $meta, $actor): Attachment {
            $existing = $this->attachments->findByChecksum($meta->checksum, $data->attachableRef());
            if ($existing) {
                return $existing->markDuplicate();                    // idempotent dedupe on (checksum, attachable)
            }

            $attachment = $this->attachments->create([
                'attachable_type' => $data->attachableType,           // 'inbox' when unfiled
                'attachable_id'   => $data->attachableId ?? 0,
                'file_name'       => $data->fileName,
                'mime_type'       => $meta->contentType,
                'size_bytes'      => $meta->sizeBytes,
                'object_key'      => $data->objectKey,
                'checksum'        => $meta->checksum,
                'status'          => AttachmentStatus::Active,        // active immediately — no draft state
                'document_folder_id' => $data->folderId,
                'source_channel'  => $data->sourceChannel,
                // company_id, uploaded_by auto-filled by BelongsToCompany
            ]);

            event(new DocumentRegistered($attachment));               // → virus scan + AI classify pipeline, afterCommit
            return $attachment;
        });
    }
}
```

| Class | Layer | Responsibility |
|---|---|---|
| `ObjectStore` (interface) | Infrastructure | The sole R2/S3 boundary; mints presigned PUT/GET; holds bucket creds |
| `R2ObjectStore` | Infrastructure | Real Cloudflare R2 (S3-compatible) implementation |
| `PresignUploadAction` | Application | Step 1: validate + reserve key + presigned PUT |
| `FinalizeUploadAction` | Application | Step 3: head-check + dedupe + create the `attachments` row |
| `SignedUrlService` | Application | Mints RBAC-checked, audited, short-lived download/preview URLs |
| `FileValidationPolicy` | Domain | MIME allow-list, size caps, and the object-matches-claim check |
| `VirusScanJob` | Application (queued) | Scans the object; quarantines on a hit before it is usable |
| `DocumentExtractionPipeline` | Application | register→OCR→classify→extract; applies the ≥95% auto-fill threshold |
| `AttachmentRepository` | Infrastructure | Reads/writes `attachments`/`document_versions`; enforces `company_id` scoping |
| `RetentionPolicy` | Domain | Computes the retention window; enforces legal-hold on delete |
| `AttachableResolver` | Domain | Validates an `(attachable_type, attachable_id)` belongs to the tenant and is a real record |

`SignedUrlService` is the class that makes "no file is ever public" true — every read is a per-user,
per-object, RBAC-checked, expiring URL, and every mint is audited:

```php
<?php

namespace App\Services\Files;

use App\Models\Files\Attachment;
use App\Models\User;

final class SignedUrlService
{
    public function __construct(
        private readonly ObjectStore $store,
        private readonly AuditWriter $audit,
    ) {}

    /** A signed GET the caller may use for ~2 minutes. Throws if they cannot read the file. */
    public function forDownload(Attachment $attachment, User $actor, bool $inline = true): string
    {
        // The file inherits the read permission of the record it is attached to (belt-and-braces
        // over documents.read): a payroll scan is only reachable by someone who can read that payroll record.
        if (! $actor->canReadEntity($attachment->attachableRef()) && ! $actor->canInCompany('documents.read')) {
            throw new FileAccessDeniedException($attachment->id);   // → 403
        }

        $url = $this->store->presignedGet(
            $attachment->object_key,
            ttlSeconds: 120,
            downloadName: $inline ? null : $attachment->file_name,
        );

        $this->audit->record('document.accessed', $attachment, $actor);  // every access is auditable
        return $url->url;
    }
}
```

# Endpoints Backed

All endpoints follow the platform envelope, require `X-Company-Id`, and enforce the `documents.*` RBAC
keys ([../frontend/DOCUMENT_CENTER.md](../frontend/DOCUMENT_CENTER.md),
[../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)). The upload is a three-call
sequence so raw bytes go browser→R2 directly, never through Laravel or Next.

| Method | Path | Permission | Description |
|---|---|---|---|
| `POST` | `/api/v1/documents/upload-url` | `documents.upload` | Step 1: presigned PUT + reserved `object_key`; validates MIME/size before issuing |
| `POST` | `/api/v1/documents` | `documents.upload` | Step 3: finalize — create the `attachments` row referencing `object_key` (or inline `base64` ≤8MB for AI agents) |
| `GET` | `/api/v1/documents` | `documents.read` | List/browse (cursor); filter by `attachable_type`, `folder`, `classification`, `status`, `q` |
| `GET` | `/api/v1/documents/{id}` | `documents.read` | One attachment's metadata + a freshly-minted signed preview URL |
| `GET` | `/api/v1/documents/{id}/download` | `documents.read` | 302 to a short-lived signed GET (`Content-Disposition: attachment`), audited |
| `GET` | `/api/v1/documents/{id}/versions` | `documents.read` | `document_versions` history; each row a signed-URL download of that exact version |
| `POST` | `/api/v1/documents/{id}/ocr` | `documents.ocr` | Run/re-run OCR (→ engine) |
| `POST` | `/api/v1/documents/{id}/classify` | `documents.classify` | Run/re-run classification |
| `POST` | `/api/v1/documents/{id}/extract` | `documents.extract` | Extract invoice/receipt fields with per-field confidence |
| `POST` | `/api/v1/documents/{id}/attach` | `documents.attach` | Re-parent (file an `inbox` item onto its real record) |
| `POST` | `/api/v1/documents/{id}/archive` | `documents.delete` | Archive (soft); hard-delete blocked under retention/legal hold |
| `GET` | `/api/v1/documents/folders` | `documents.read` | The folder tree |
| `POST`/`PATCH`/`DELETE` | `/api/v1/documents/folders/{id}` | `documents.folder.manage` | Human folder organization |
| `POST` | `/api/v1/documents/export` | `documents.export` | Bulk metadata CSV / zip of selected files (narrow grant — bundles payslips/IDs) |

The upload sequence in full:

```
1. POST /api/v1/documents/upload-url         → { upload_url, object_key, expires_at }   (documents.upload, MIME/size validated)
2. PUT  <upload_url>   (browser → Cloudflare R2, raw bytes, Content-Type)               (no Laravel/Next hop for the payload)
3. POST /api/v1/documents  { attachable_type, attachable_id, file_name,                 (finalize: head-check, dedupe,
        source: { type: 'object_key', object_key, media_type }, document_folder_id }      create attachments row → DocumentRegistered)
   Idempotency-Key: <uuid>                                                              (a retried finalize returns the original 201)
```

The finalize `source` is a discriminated union: `object_key` (the browser presigned path, always taken
by the Document Center regardless of size), `base64` (≤8MB inline, for an AI agent handling an
already-in-memory email attachment), or `url` (fetch-and-store). Every finalize carries an
`Idempotency-Key` per [../api/API_IDEMPOTENCY.md](../api/API_IDEMPOTENCY.md), so a flaky retry never
double-creates the row.

The `{id}/extract` response returns per-field confidence and the auto-fill decision:

```json
POST /api/v1/documents/88231/extract
{ "document_type": "vendor_invoice" }

{
  "success": true,
  "data": {
    "attachment_id": 88231,
    "classification": { "document_type": "vendor_invoice", "confidence": "0.9700" },
    "fields": {
      "vendor_name":  { "value": "Gulf Prime Distribution Co.", "confidence": "0.9900", "auto_fill": true },
      "total_amount": { "value": "186.5000", "currency_code": "KWD", "confidence": "0.9600", "auto_fill": true },
      "invoice_date": { "value": "2026-07-14", "confidence": "0.9100", "auto_fill": false },
      "tax_amount":   { "value": "8.8800", "confidence": "0.7200", "auto_fill": false }
    },
    "handoff": { "action": "create_bill_draft", "endpoint": "POST /api/v1/purchasing/bills",
                 "prefill_ready": true, "note": "Fields below 95% flagged for human review before posting." }
  }
}
```

Fields ≥95% carry `auto_fill: true`; anything below is surfaced for review and never silently guessed
into a posted figure. The `handoff` names the *owning module's* governed create flow — the File Service
pre-fills, it never posts.

# Database Tables Owned

| Table | Owned write path | Notes |
|---|---|---|
| `attachments` | `FinalizeUploadAction`, `/attach`, `/archive`, extraction pipeline | Polymorphic; `company_id` scoped + RLS; `status` ∈ {active, archived}, no draft; model-observer cleanup (no polymorphic FK) |
| `document_folders` | `documents.folder.manage` endpoints | Nestable per-tenant tree; `company_id` scoped + RLS |
| `document_versions` | Appended on re-upload | Each version its own `object_key`/`checksum`; `attachments` points at current |

Every table carries `company_id BIGINT NOT NULL` under the identical Row Level Security policy that
protects the ledger, scoped to `current_setting('app.current_company_id')::bigint` — so an
`attachments` query, an `extracted_data` read, or a version lookup can only ever touch the active
tenant's rows. The `object_key` is tenant-partitioned (`c/{company_id}/YYYY/MM/{ulid}`) so a key is
neither guessable nor cross-addressable, and it is stored *as a key, never a URL* — a URL only ever
exists transiently, minted signed and expiring. `checksum` is indexed for dedupe; `attachable_type,
attachable_id` is indexed for the "one record's files" query the Attachments tabs run.

# Multi-Tenancy Enforcement

Tenancy is enforced in four layers, and the object store adds one the database cannot.

1. **The header ring.** Every `/api/v1/documents/*` request carries `X-Company-Id`; `EnsureCompanyScope`
   verifies the `company_users` row (else `403`) and sets `app.current_company_id`.
2. **The RLS ring.** `attachments`, `document_folders`, `document_versions` carry Row Level Security
   scoped to `app.current_company_id`; a cross-tenant `{id}` returns `404` (never `403` — the row's
   existence is not disclosed), per [../frontend/DOCUMENT_CENTER.md](../frontend/DOCUMENT_CENTER.md)'s
   403-for-route / 404-for-record rule.
3. **The attachable-integrity ring.** `AttachableResolver` validates that every
   `(attachable_type, attachable_id)` an upload or re-parent names belongs to the header's company and is
   a real record; a mismatch is a `403 COMPANY_MISMATCH` logged as a security event, because a
   cross-tenant attach attempt is a signal, not a bookkeeping error.
4. **The object-key ring.** R2 keys are tenant-partitioned and never public. A signed GET is minted only
   for a specific `object_key` after `SignedUrlService`'s RBAC check, and expires in ~2 minutes — so even
   a leaked URL is scoped to one object and self-expires, and no object is reachable without a fresh,
   authorized mint. The bucket has no public read and no list permission from the application role.

A file inherits the *read permission of the record it is attached to*: a payslip filed against an
`employee` is reachable only by someone who can read that employee (belt-and-braces over the broad
`documents.read`), so the widest read grant in the platform still cannot surface a PII scan to someone
who cannot see its subject. Jobs (virus scan, extraction) run off the request thread and re-establish
tenant context via `TenantAwareJob` before touching any model or minting any URL.

# Events, Queues & Realtime

**Registration → validation + AI pipeline.** `FinalizeUploadAction` emits `DocumentRegistered` inside
the transaction; a queued listener bound `afterCommit()` kicks off the validation-and-extraction pipeline
on the `documents` queue so a rolled-back finalize never scans or extracts:

```php
<?php

namespace App\Listeners\Files;

use App\Jobs\Files\VirusScanJob;
use App\Jobs\Files\ClassifyDocumentJob;
use App\Events\Files\DocumentRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ProcessRegisteredDocument implements ShouldQueue
{
    public string $queue = 'documents';
    public bool $afterCommit = true;

    public function handle(DocumentRegistered $event): void
    {
        // Scan first; classification/extraction is chained only after a clean scan.
        VirusScanJob::withChain([
            new ClassifyDocumentJob($event->attachmentId),   // → Document AI via the AI Service boundary
        ])->dispatch($event->attachmentId);
    }
}
```

**Virus/type validation.** `VirusScanJob` scans the finalized object (via the platform's malware-scan
integration) *before* it is usable; a hit quarantines the object (`status` held, object flagged) and
raises a `system` audit event, and the chained classify/extract never runs. A clean scan releases the
object into the pipeline. Type validation is two-stage: the MIME allow-list + size cap at
presign/finalize (claim-time), and the `head`-check that the object *is* what was claimed (object-time) —
a renamed executable cannot masquerade past both.

**Extraction pipeline → engine.** `ClassifyDocumentJob` and the extraction steps call the AI Service
boundary ([AI_SERVICE.md](./AI_SERVICE.md)), which relays to the FastAPI Document AI / OCR Agent. The
engine returns classification and per-field values with confidence; `DocumentExtractionPipeline` writes
them onto `attachments.extracted_data`/`classification`, applies the ≥95% auto-fill threshold per field,
and — crucially — hands off to the owning module's *governed create flow* rather than posting anything.
Every step writes an `ai_logs` row for explainability. The engine holds no storage credentials; it
receives document content only through a signed URL the File Service mints for it in the initiating
scope.

**Realtime.** Extraction progress and completion broadcast on the tenant's private channel so the
Document Center's status pills update live:

```php
broadcast(new DocumentExtractionCompleted($attachment));   // private-company.{id}.ai (extraction is AI output)
```

A `needs_review` extraction additionally raises the notification path, fanning a "review these fields"
notification to the relevant role via [NOTIFICATION_SERVICE.md](./NOTIFICATION_SERVICE.md); the File
Service raises the event, the Notification Service routes it.

# Integrations

- **Cloudflare R2 (S3-compatible)** — the private object store, reached only through `ObjectStore`. No
  public bucket policy; the application role has put/get/head/delete on tenant-partitioned keys but no
  public-read or bucket-list grant. Archived cold data and the platform's other R2 usage
  ([../database/DATABASE_ARCHITECTURE.md](../database/DATABASE_ARCHITECTURE.md)) share the account, not
  the document bucket.
- **The AI Service / FastAPI Document AI + OCR Agent** — the extraction brains
  ([AI_SERVICE.md](./AI_SERVICE.md)). The File Service drives the pipeline and stores the results; the
  engine reasons and never writes storage or the ledger.
- **The owning business modules** — Purchasing (`create_bill_draft_from_ocr`), Expenses, Banking (a
  `bank_statement` scan feeding reconciliation), Payroll (`payslip`) — receive the *handoff*: a pre-filled
  draft in their own governed create flow. The File Service never posts on their behalf.
- **The malware/virus scan integration** — the platform's file-scanning provider, invoked by
  `VirusScanJob` before an object is usable.
- **The Notification Service** ([NOTIFICATION_SERVICE.md](./NOTIFICATION_SERVICE.md)) — a `needs_review`
  extraction or a quarantine event fans out to the responsible humans.
- **The Search Service** ([SEARCH_SERVICE.md](./SEARCH_SERVICE.md)) — a `document` becomes searchable
  (file name + OCR'd text) via the reindex path; a search hit's `href` resolves back through this
  service's signed-URL mint, never a public path.

# Permissions

The `documents.*` category ([../frontend/DOCUMENT_CENTER.md](../frontend/DOCUMENT_CENTER.md),
[../foundation/PERMISSION_SYSTEM.md](../foundation/PERMISSION_SYSTEM.md)), applied default-deny and
company-scoped.

| Permission | Grants |
|---|---|
| `documents.read` | Open/list files, mint preview/download signed URLs — the widest grant in the platform (nearly every role, incl. Auditor) |
| `documents.upload` | The three-step upload; drag-and-drop; a record screen's inline upload |
| `documents.ocr` | Run/re-run OCR |
| `documents.classify` | Run/re-run classification |
| `documents.extract` | Extract invoice/receipt fields |
| `documents.attach` | Re-parent / file an `inbox` item onto its record (and inline rename) |
| `documents.delete` | Archive / (retention-permitting) delete |
| `documents.folder.manage` | Create/rename/re-parent folders (mirrors `documents.upload`'s grant) |
| `documents.export` | Bulk metadata/zip export — deliberately narrow (bundles payslips, ID scans, contracts) |

Two invariants sit above the key grants. First, **a signed URL is minted only after an RBAC check and
only for the specific object**, so `documents.read` is a necessary but not blunt instrument — the
per-record read check narrows a PII scan to those who can see its subject. Second, **access is audited**:
every signed-URL mint writes a `document.accessed` audit row, so "who looked at this national-ID scan"
is answerable, an obligation [../security/DATA_PRIVACY.md](../security/DATA_PRIVACY.md) requires of
PII-bearing files. Retention and legal-hold are *data policies* enforced here, not RBAC: even a holder of
`documents.delete` cannot hard-delete a file inside its statutory window or under legal hold — the action
returns a stated, explained block rather than silently succeeding or silently disappearing.

# Error Handling

| Condition | Response | Notes |
|---|---|---|
| Upload MIME/size not allowed | `422 file_type_not_allowed` / `413 file_too_large` | Enforced at presign (claim) and finalize (object) — both stages |
| Finalize references a missing/mismatched object | `422 object_not_found` / `422 object_mismatch` | `head`-check catches a claim the object does not satisfy |
| Cross-tenant `attachable_id` on upload/attach | `403 COMPANY_MISMATCH` + `system` audit event | A security signal, escalated, not a routine rejection |
| Cross-tenant `{id}` read | `404` | Existence never disclosed (403-for-route / 404-for-record) |
| Virus scan hit | Object quarantined; `422 file_rejected_malware`; classify/extract never runs | Raises a `system` audit event; the row is not served or linked |
| Download by a caller lacking read of the subject | `403` | Even with `documents.read`, the per-record check must pass |
| Hard-delete under retention/legal hold | `409 retention_locked` / `409 legal_hold` | Explained block; archive is offered instead |
| Duplicate finalize (same `Idempotency-Key`) | Original `201` returned | Retries never double-create |
| Duplicate content (same checksum + attachable) | `is_duplicate: true` on the existing row | Dedupe; the user is told, not errored |
| Extraction engine unreachable | `503` on the on-demand `/extract`; pipeline retries the queued path | Storage is unaffected — the file is already safely stored |
| Signed-URL expired at click | Frontend re-mints via `/{id}` | URLs are intentionally short-lived; a re-mint is one call |

Typed exceptions (`FileAccessDeniedException` → `403`, `RetentionLockedException` → `409`,
`MalwareDetectedException` → `422`, `CompanyMismatchException` → `403`) are mapped by the global handler.
Every mutation and every access is correlated by `request_id` and, for access and security events,
written to the audit trail.

# Testing

- **Presigned upload (feature).** `POST /upload-url` returns a scoped, tenant-partitioned key and a
  short-TTL PUT; a disallowed MIME or oversize is rejected at presign; finalize head-checks the object
  and creates an `active` row (never a draft state); a retried finalize with the same `Idempotency-Key`
  returns the original row.
- **No-public-access (feature, security-critical).** No endpoint ever returns a public URL; a download is
  always a fresh, short-lived signed GET minted after an RBAC check; the object bucket has no public read.
- **Per-record access inheritance (feature).** A user with `documents.read` but no read on the attached
  payroll record cannot download that payslip (`403`); every successful download writes a
  `document.accessed` audit row.
- **Tenant isolation (feature).** A cross-tenant `attachable_id` on upload/attach → `403 COMPANY_MISMATCH`
  + `system` audit; a cross-tenant `{id}` read → `404`, never disclosing existence.
- **Virus/type validation (feature).** A seeded malware object is quarantined, `/extract` never runs, and
  a `system` audit event is written; a renamed executable fails the object-time head-check.
- **Extraction threshold (feature).** With faked engine output, fields ≥95% carry `auto_fill: true` and
  pre-fill the owning module's draft; a <95% field is flagged `needs_review` and never auto-posted; the
  handoff points at the owning module's create flow, and no ledger row is written by the File Service.
- **Versions (feature).** A re-upload appends a `document_versions` row; the `attachments` row points at
  the current version; each prior version downloads via its own signed URL.
- **Retention / legal hold (feature).** A hard-delete under retention returns `409 retention_locked` and
  archive is offered; a legal hold blocks delete with an explained state.
- **Dedupe (feature).** Uploading identical content against the same record returns `is_duplicate` on the
  existing row, not a second object.

Tooling per [../foundation/TECH_STACK.md](../foundation/TECH_STACK.md): Pest/PHPUnit, PHPStan + Pint in
CI; `ObjectStore` and the AI engine are faked. The tests prove the storage boundary, the access control,
the validation, and the extraction governance — never the model's OCR accuracy, which lives in the
FastAPI repo's own evals.

# End of Document
