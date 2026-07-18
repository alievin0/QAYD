# Document Center — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: DOCUMENT_CENTER
---

# Purpose

Every module QAYD has documented so far already attaches files to its own records: `docs/frontend/JOURNAL_ENTRIES.md` gives a journal entry an Attachments tab keyed to `attachable_type = 'journal_entry'`; `docs/frontend/INVOICES.md` gives an invoice's Builder an Attachments card for the delivery note and purchase order behind it; `docs/frontend/EMPLOYEES.md` gives an employee profile a Documents tab wrapping `GET /api/v1/payroll/employees/{id}/documents`; `docs/frontend/CUSTOMERS.md` gives a customer record its own `GET/POST /customers/{id}/documents`. Every one of those is a *contextual* view — a scoped slice of one record's own files, correctly embedded in that record's own screen. None of them is a place a Finance Manager, an Auditor, or a Compliance Agent can go to ask a company-wide question: "show me every vendor invoice we have not yet filed," "find the lease agreement for Diyar Real Estate no matter which module it was uploaded from," "which documents are approaching the end of their statutory retention window." The Document Center is that place. It is the one screen in QAYD whose entire reason to exist is to look *across* the polymorphic `attachments` table that every other screen only ever looks *into* one slice of at a time.

This document specifies `app/(app)/documents` — the library, upload and OCR-status surface, grid/list browser, folder and tag organizer, full-text-and-semantic search, signed-URL preview, and record-linking screen for the whole company's stored files. It sits directly on top of the backend contract `docs/ai/tools/DOCUMENT_TOOLS.md` already specifies in full: the polymorphic `attachments` table (plus `document_folders` and `document_versions`) defined in `docs/database/ERD.md`'s Documents section, the eight `documents.*`-permissioned tools/endpoints under `/api/v1/documents` (`upload_document`, `ocr_document`, `classify_document`, `extract_invoice_fields`, `extract_receipt_fields`, `attach_document`, `get_attachment`, `list_attachments`), and the two named specialist agents — **Document AI** (`agent_code = 'DOCUMENT_AI'`, structured extraction from contracts/documents) and **OCR Agent** (`agent_code = 'OCR_AGENT'`, raw text/field extraction from scans and photos) — that already do every byte of the classification and extraction work this screen surfaces. This document introduces no new business rule, no new table, and no new AI capability. What it adds, narrowly and additively, is: the human-facing library shell around those already-specified primitives (folder browsing endpoints, a version-history read endpoint, a presigned direct-to-R2 upload path completing the one `DOCUMENT_TOOLS.md` only named in prose, a `documents.folder.manage` permission for human-only folder organization, and a `documents.export` permission for bulk metadata export) — each cited at the point it is introduced, and none contradicting a rule, permission, or endpoint already committed elsewhere.

The Document Center's single most important structural property is one this document repeats because it governs almost every design decision below: **an `attachments` row has no draft/pending-approval lifecycle of its own.** `docs/ai/tools/DOCUMENT_TOOLS.md` states this precisely — "`attachments.status` has exactly two values — `active` and `archived` — with no `draft`/`pending_approval` state of its own, because a stored file simply *is* stored the moment the call succeeds; there is no separate approval step to make an uploaded scan real." The `write_propose` tag on `upload_document`/`ocr_document`/`classify_document`/`extract_invoice_fields`/`extract_receipt_fields`/`attach_document` is used in a deliberately narrower sense here than everywhere else that tag appears in the platform: unlike `propose_journal_entry`, none of these six calls can create, post, approve, or move a financial fact, so none of them needs an `ApprovalCard`-style human gate on the call itself. What *does* need the platform's full approve/reject treatment is one layer downstream, in whichever module turns an extraction payload into a ledger fact — Purchasing's `create_bill_draft_from_ocr` landing a `bills` row in `draft`, never auto-posted. The Document Center renders every confidence score, every classification, and every extracted field with the same visible, humble, `AiCardShell`-bordered treatment Principle 3 requires platform-wide, but it never renders an approve/reject pair for the extraction itself — only a hand-off ("Create bill from this," "Create expense claim from this") that opens the *owning* module's own governed create flow, pre-filled, exactly as `docs/frontend/SEARCH.md`'s own rule states it for a citation: "Search's AI layer can help a user find the right record and understand what it says; it never becomes a second, parallel path to changing that record" — Document Center extends that identical posture to extraction, not just to citation.

Three audiences use this one screen, composed from the same components at different densities, per the platform's stated "two audiences share one shell" rule extended here to three: an **Accountant or Purchasing/Sales Employee** triaging an inbox of freshly-scanned vendor invoices and receipts, filing each one against the bill or expense claim it belongs to (dense, keyboard-fast, "what's unfiled" as the default lens); a **Finance Manager, Auditor, or External Auditor** gathering every source document behind a posted figure or a filing period (calmer, retention- and provenance-first, "prove this number" as the default lens — `docs/ai/tools/DOCUMENT_TOOLS.md` names exactly this: "`documents.read` is deliberately the widest grant in the platform's entire permission matrix... every audit/compliance workflow in the roster depends on unrestricted read access to source scans"); and the **Document AI / OCR Agent** pair itself, which populates this screen's `extracted_data`, `is_ai_extracted`, and classification columns entirely in the background, and is never rendered as a fourth human role because it never opens a page — it only ever produces the data three human roles above read.

# Route & Access

## Route tree

```text
app/(app)/documents/
├── page.tsx                          # Library — Server Component, first-paint fetch; ?folder=&type=&q=&view=
├── loading.tsx                       # Library skeleton (streamed while the Server Component fetches)
├── error.tsx                         # Library-level error boundary
├── [attachmentId]/
│   └── page.tsx                      # Canonical, deep-linkable full-page preview + metadata + AI panel
└── @modal/
    └── (.)[attachmentId]/
        └── page.tsx                  # Intercepted: same route, rendered as a Sheet when opened from the Library grid/list
```

`[attachmentId]` follows the platform's dynamic-segment convention — camelCase, named for the entity, never the generic `[id]` (`docs/frontend/FRONTEND_ARCHITECTURE.md → Conventions → Naming`) — and its API-resource mirror is `/api/v1/documents/{id}`, the exact `get_attachment` endpoint `docs/ai/tools/DOCUMENT_TOOLS.md` already specifies. The intercepted `@modal/(.)[attachmentId]` route is the identical pattern `docs/frontend/FRONTEND_ARCHITECTURE.md → Parallel and intercepting routes for quick-create` establishes for `journal-entries/new`: clicking a card in the Library opens the exact same page in a `Sheet` overlay without leaving the grid, while a direct hit, a shared link, or a hard refresh on `/documents/8801` always renders the real, full, linkable page — never a second, divergent implementation of the preview.

**Folders are not routes.** A folder is a filter, not a page: navigating into "2026 Audit Evidence" sets `?folder=6` on the same `/documents` route rather than resolving to `/documents/folders/6`, mirroring the exact convention `docs/frontend/INVOICES.md` uses for its own Credit Notes tab ("one screen, one route, specialized by filter/tab state, not a second near-duplicate screen") and `docs/frontend/SEARCH.md` uses for its Type Tabs (`?type=`). This keeps an arbitrarily deep, user-created folder tree from ever requiring an arbitrarily deep route segment, and it keeps every folder view — like every other filtered view on this screen — a plain, shareable, bookmarkable URL.

## Access gate

The route sits inside the `(app)` route group, behind `middleware.ts`'s session-cookie check and the resolved active-company context (`X-Company-Id`), per `FRONTEND_ARCHITECTURE.md`. The gating permission is `documents.read` — and per `docs/ai/tools/DOCUMENT_TOOLS.md`'s own role-grant table, this is "deliberately the widest grant in the platform's entire permission matrix": every operational role in the platform holds it, including Auditor and Read Only/External Auditor. In practice this means the Document Center is one of the very few screens in QAYD that renders identically-reachable for nearly every seat a company has — the differences between roles show up entirely in *which write actions* are present, never in whether the library itself opens. A user without `documents.read` at all (a hypothetical narrowly-scoped external integration account) never sees "Documents" in any nav surface or the Command Palette's fuzzy-search index, and a direct hit on `/documents` renders the shared `403` boundary rather than a `404`, per the platform's stated 403-for-route/404-for-record split. A cross-company hit (an `attachmentId` that exists but belongs to a different `company_id`) renders the shared `not-found.tsx` instead, per `FRONTEND_ARCHITECTURE.md`'s "403 vs. 404" rule — the API returns `404`, never `403`, for a cross-tenant attachment, so a caller can never learn that a document with that id exists somewhere they cannot see, exactly as `docs/ai/tools/DOCUMENT_TOOLS.md → get_attachment`'s own error catalog states it.

## Nav placement

`docs/frontend/NAVIGATION_SYSTEM.md`'s ten-item Sidebar module map is fixed platform-wide ("companies do not reorder or rename them… consistency across QAYD's entire customer base is worth more than per-tenant vanity naming"), and Documents is deliberately not an eleventh entry competing with it. Instead this screen follows the exact precedent that document's own **AI** section already set for the Approval Center — a cross-module surface named as a bullet under the sub-navigation of its nearest conceptual sibling, with explicit prose that it is not owned by that module:

- **Reports** sub-navigation (parent gate `reports.read` does not apply to it) gains one additional bullet: **Documents — `/documents` — `documents.read`** — grouped under Reports because both are "the lens that looks back across everything," not because Reports owns the `attachments` table (it does not; Documents is its own reserved Permission Category per `docs/foundation/PERMISSION_SYSTEM.md → Permission Categories`).
- Every record screen that already has an Attachments/Documents tab or card (Journal Entries, Invoices, Bills, Employees, Customers, Vendors, Payroll) gains a single additive link — **"Manage in Document Center"** — that deep-links `/documents?attachable_type=<type>&attachable_id=<id>`, pre-filtering the Library to exactly that record's own files. This is the same link-out convention `docs/frontend/EMPLOYEES.md` already uses for its own Compensation tab's "Manage in Loans" link to `/payroll/loans?employee_id=`.
- The Global Search Documents result group (`docs/frontend/SEARCH.md`'s `GET /api/v1/documents/search`) continues to deep-link a hit straight to its **owning record's** own attachment view (`/sales/customers/4471?attachment=90142&highlight=553`), unchanged — this document does not alter that behavior. Opening the identical attachment from inside the Document Center itself instead resolves to this screen's own `/documents/{id}`, and that page's "Go to source" action is the one place a user crosses from the library view back to the record view; the two entry points converge on the same underlying `attachments` row, never a forked copy of it.
- The Command Palette's Navigate group includes "Documents" once `filterNavByPermissions` resolves it visible, exactly like any other permission-visible leaf — no special-casing beyond what `docs/frontend/NAVIGATION_SYSTEM.md`'s existing `NAV_TREE` mechanism already does for every other leaf.
- On mobile, Documents does not claim one of the five bottom-tab slots (`Dashboard, Accounting, Banking, Search, More`, per `docs/frontend/SEARCH.md`) — it is reached through **More**, the same way every non-bottom-tab destination is.

## Permission surface on this screen

Every permission key below except the two marked **(new)** is defined once, verbatim, in `docs/ai/tools/DOCUMENT_TOOLS.md → Tool Catalog`, which itself draws from the `Documents` category `docs/foundation/PERMISSION_SYSTEM.md → Permission Categories` reserves platform-wide. This document does not restate that table's full role-by-role grant for the seven inherited keys — `usePermission()` is the only source either surface queries, and restating a second copy here is exactly the drift risk `docs/frontend/INVOICES.md` already declined to take for its own inherited Sales permissions.

| Permission key | Gates on this screen | Absent behavior |
|---|---|---|
| `documents.read` | The route itself; every card/row; the preview route; signed-URL access; `get_attachment`/`list_attachments` | Route renders the shared `403` boundary; nav entries hidden |
| `documents.upload` | "Upload" button in the Page Header; drag-and-drop drop zone; the Builder's inline "Upload" affordance on every other screen's Attachments tab | Button/drop zone removed from the DOM, not disabled; a drag-over on the Library still shows a "you don't have permission to upload here" toast rather than silently swallowing the drop |
| `documents.ocr` | "Run OCR" row/detail action | Action omitted from the row menu and the detail page |
| `documents.classify` | "Classify" row/detail action | Action omitted |
| `documents.extract` | "Extract invoice fields" / "Extract receipt fields" detail actions (shown per the document's own `classification.document_type`) | Action omitted; the extraction results panel still renders read-only if a prior extraction already exists |
| `documents.attach` | "Move to record" (re-parent) action, including filing an `inbox`-staged upload onto its real record | Action omitted; an `inbox` item stays visible under the "Needs filing" facet but cannot be filed by this user |
| `documents.delete` | "Archive" / "Delete" row and detail actions — the human consumer of the administrative tool `docs/ai/tools/DOCUMENT_TOOLS.md` explicitly reserved and left unimplemented ("reserved for a future `delete_attachment` administrative tool, out of scope here") | Action omitted |
| `documents.folder.manage` **(new)** | "New folder," rename, re-parent, and delete-folder actions in the Folder Tree | Folder Tree renders read-only (navigable, not editable); "New folder" omitted |
| `documents.export` **(new)** | "Export" (bulk metadata CSV / zip of selected files) in the Page Header and Bulk Action Bar | Button/bar item omitted |

Default role grants for the seven inherited keys are exactly `docs/ai/tools/DOCUMENT_TOOLS.md → Tool Catalog`'s own table — reproduced here only for the two new keys, each mirroring the nearest existing sibling exactly, the same technique `docs/frontend/INVOICES.md` used for its own `sales.invoice.send`/`sales.invoice.export` additions: `documents.folder.manage` mirrors `documents.upload`'s grant verbatim (Owner, CEO, CFO, Finance Manager, Senior Accountant, Accountant, Purchasing Mgr/Employee, Sales Mgr/Employee, HR Manager, Payroll Officer, Inventory Manager — organizing files into folders is exactly as low-risk as uploading one). `documents.export` mirrors the narrower shape `docs/frontend/INVOICES.md` established for `sales.invoice.export` (which itself mirrors `sales.report.export`): Owner, CEO, CFO, Finance Manager, Senior Accountant, Auditor — a bulk export can bundle payslips, national ID scans, and signed contracts in one download, so it is deliberately narrower than the near-universal `documents.read` a single open-and-view action requires.

## `attachableType` — reconciling three sources

The Library's own `attachableType` filter and every upload/re-parent flow's target picker is typed against a union this document must assemble from three sources that do not, today, perfectly agree, and this document resolves the mismatch explicitly rather than silently choosing one, the same discipline `docs/frontend/SEARCH.md` already applied to its own two small pre-existing cross-document inconsistencies. `docs/database/ERD.md → attachments`'s own attribute description and worked `INSERT` example use plural, table-name-shaped values (`'invoices'`, `'bills'`, `'employees'`, `'companies'`); `docs/ai/tools/DOCUMENT_TOOLS.md`'s literal JSON Schema for `upload_document`/`attach_document` — the actual wire contract this screen's every upload and re-parent call sends bytes-for-bytes against — enumerates singular values instead (`invoice`, `bill`, `sales_order`, `purchase_order`, `employee`, `customer`, `vendor`, `company`, `account`, `warehouse`, `payslip`, `tax_return`, `vendor_contract`, `expense_claim`, `bank_statement`, plus `inbox` for upload-only staging); and `docs/frontend/JOURNAL_ENTRIES.md` already attaches its own Attachments tab under a third value, `journal_entry`, absent from that JSON Schema's enumerated list entirely. Because this screen is a caller of the literal `/api/v1/documents` endpoint, not a re-describer of the database's own column comment, this document treats **`docs/ai/tools/DOCUMENT_TOOLS.md`'s JSON Schema as authoritative for what the frontend actually sends and receives**, extended with the one value it already omits in practice:

```ts
// types/documents.ts
export type AttachableType =
  | "inbox" // upload-only staging parent; never a valid attach_document target
  | "invoice" | "bill" | "sales_order" | "purchase_order" | "employee" | "customer"
  | "vendor" | "company" | "account" | "warehouse" | "payslip" | "tax_return"
  | "vendor_contract" | "expense_claim" | "bank_statement"
  | "journal_entry"; // additive: already consumed by docs/frontend/JOURNAL_ENTRIES.md's own Attachments tab
```

A future owning-module screen that needs a value neither list yet carries (a `sales_quotation`, say) adds it here the same additive way, never by inventing a second, parallel `attachable_type` grammar — this union is the one place the frontend's understanding of "which record kinds can hold a file" lives, imported everywhere a picker or a filter needs it, per `docs/frontend/COMPONENT_LIBRARY.md`'s stated "no screen hand-rolls a duplicate of something the shared layer already owns" convention.

# Layout & Regions

The Library is this document's one deliberate extension of `docs/frontend/LAYOUT_SYSTEM.md`'s **List Page Template** — reusing its Page Header, Filter Bar, Bulk Action Bar, and Pagination Footer regions verbatim, and substituting the Data Region's renderer, the identical kind of substitution `docs/frontend/SEARCH.md` already performed on the same template ("reinterprets each region for a multi-entity result set… the Data Region becomes a stack of independently-loading result groups instead of one `DataTable`"). `docs/frontend/LAYOUT_SYSTEM.md`'s own "a screen never invents a sixth layout shape" rule is honored precisely because this is an extension of an existing named slot, not a sixth template.

## Library — List Page Template, grid-first Data Region

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ Documents                                                    [⬆ Upload] [+ Folder]│
│ 2,140 files · 18 need filing                          [Export ▾]  [▤ List][▦ Grid]│
├──────────────────────────────────────────────────────────────────────────────────┤
│ 🏠 All Documents › 2026 Audit Evidence                                            │
├──────────────────┬─────────────────────────────────────────────────────────────────┤
│ FOLDERS           │ [Search files, text inside scans…]  [Type ▾][Folder][Status ▾][Uploaded ▾][⚙]│
│ ▸ 2026 Audit Ev.  ├─────────────────────────────────────────────────────────────────┤
│ ▸ Vendor Invoices │  ┌───────────┐ ┌───────────┐ ┌───────────┐ ┌───────────┐        │
│ ▸ Payroll         │  │ [thumb]   │ │ [PDF icon]│ │ [PDF icon]│ │ [thumb]   │        │
│ ▸ Tax Filings     │  │ ACME-INV..│ │ Lease-    │ │ CR-2026.. │ │ receipt-  │        │
│                    │  │ .pdf      │ │ Diyar.pdf │ │ .pdf      │ │ 0714.jpg  │        │
│ Needs filing (18)  │  │ Invoice · │ │ Contract ·│ │ Certificate│ │ Receipt · │        │
│                    │  │ 96% ✓     │ │ Filed     │ │ · Filed   │ │ Processing│        │
│                    │  └───────────┘ └───────────┘ └───────────┘ └───────────┘        │
│                    │  ┌───────────┐ ┌───────────┐ ┌───────────┐ ┌───────────┐        │
│                    │  │ …         │ │ …         │ │ …         │ │ …         │        │
├──────────────────┴─────────────────────────────────────────────────────────────────┤
│ Showing 1–24 of 2,140                                              [‹ Prev] [Next ›] │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

- **Page Header** — title, live count badge ("2,140 files," from `meta.pagination.total_hint`) plus a second, distinctly-toned count for the current user's own operational lens — **"18 need filing"** — a filtered count of `attachable_type = 'inbox'` rows, clicking through to the same list pre-filtered (`?folder=inbox`), mirroring the exact "a number always means go look at the source" discipline `docs/frontend/DASHBOARD.md` established for its own KPI tiles. Primary action **Upload** (`documents.upload`); secondary **+ Folder** (`documents.folder.manage`); an **Export** menu (`documents.export`); and the **List/Grid** `ViewToggle`, persisted per-user in `localStorage` (client-only preference, never round-tripped to the server) exactly as `docs/frontend/COMPONENT_LIBRARY.md`'s density toggle already is.
- **Breadcrumb strip** — the active folder's path, from `document_folders.parent_folder_id` chain, always anchored at "All Documents." Absent entirely when no `?folder=` is set (viewing the company-wide, unfiled root).
- **Folder Tree rail** (`3/12` at `lg:`+, a collapsible drawer below it) — `document_folders` rendered as a nestable tree, each node a link that sets `?folder=<id>`, plus the platform-wide **"Needs filing"** smart-folder (a saved filter, not a real `document_folders` row, for `attachable_type = 'inbox'`) pinned above the real tree so an unfiled scan is never more than one click from view. Read-only for a caller lacking `documents.folder.manage` — the tree still navigates, only "New folder," rename, and drag-to-re-parent are absent.
- **Filter Bar** — search (`q`, full-text-plus-semantic over file name and OCR'd content, `# Data & State`), and facets: **Type** (mime-group: Images / PDFs / Spreadsheets / Word / Other), **Classification** (the exact `classify_document` output enum: vendor invoice, customer invoice, receipt, delivery note, purchase order, contract, bank statement, government notice, identity document, certificate, other — plus "Unclassified"), **Status** (Active / Archived), **Uploaded by** (a specific user, or "AI agent"), **Uploaded** (date range), and — client-derived, never server-filtered — **Extraction** (Not started / Processing / Extracted / Failed), computed per `# Data & State`'s `deriveExtractionStatus`. A density/column-visibility menu on `List` view; on `Grid` view the equivalent control is **card size** (`Comfortable` / `Compact`), reusing the identical two-name density vocabulary `docs/frontend/INVOICES.md` already established for its own table density, applied here to thumbnail size instead of row height.
- **Bulk Action Bar** replaces the Filter Bar's trailing side once ≥1 item is selected: "6 selected · Move to folder · Download · Archive · Export · Clear," each action re-checking its own permission and, for Move/Archive, calling a bulk endpoint whose `data.results[i]` array reports per-item success/failure — never a single all-or-nothing call, matching the exact bulk-operation contract `docs/accounting/SALES.md`'s own bulk-post endpoint already established and `docs/frontend/INVOICES.md` already cites.
- **Data Region — Grid mode (default)** — `DocumentGrid` of `DocumentCard`s, virtualized above ~60 visible cards (`# Performance`). Each card: a thumbnail (a real, browser-rendered `<img>` at the signed URL for `image/*` mime types; a mime-type glyph — PDF, spreadsheet, Word, generic — for everything else, since no thumbnail-rendering service is specified anywhere in the platform's backend docs and this document does not invent one), file name (truncated, full name on `Tooltip`), a classification chip once known, and a status row combining the two independent signals a document can carry — its **filing state** (Filed / Needs filing, from whether `attachable_type = 'inbox'`) and its **extraction state** (`# AI Integration`) — never collapsed into one ambiguous pill.
- **Data Region — List mode** — the platform's ordinary `DataTable` (`resource="documents"`, `paginationMode="cursor"` — see `# Data & State` for why this resource is cursor-mode, not page-mode), columns: checkbox, file name + type icon, Classification, Linked record (`attachable_label`, a real link when `attachable_href` is present), Folder, Uploaded by, Uploaded at, Size, Extraction, row-action overflow. This is the same plain, accessible `<table>` pattern every other list screen in the platform uses (`# Accessibility`), never the ARIA `grid` pattern, which `docs/frontend/ACCESSIBILITY.md → Data Tables Accessibility` reserves "exclusively for surfaces that are genuinely spreadsheet-like" and names as exactly two: the Journal Entry line editor and the Bank Reconciliation matching grid — a document library, however visual, is a read/sort/filter/paginate/open surface, squarely the plain-table case.
- **Pagination Footer** — cursor "Load more"/infinite-scroll affordance in Grid mode (a library is naturally browsed by scrolling a wall of thumbnails); the identical cursor data feeding a "Showing 1–24 of 2,140" caption plus Prev/Next buttons in List mode, per `docs/api/API_PAGINATION.md`'s stated cursor-mode UI convention for `ledger-entries`/`audit-logs`-shaped resources.

## Preview — Detail Page Template, adapted for one file

```
┌───────────────────────────────────────────────────┬───────────────┐
│ ‹ Documents   ACME-Supplies-INV-2026-0715.pdf      │  Metadata      │
│                        [Download] [Move] [⋯]        │  ───────────   │
├─ Preview │ Extracted Data │ Versions │ Activity ───┤  482 KB · PDF  │
│                                                     │  Uploaded Jul 15│
│         [ embedded PDF / image preview,             │  by email inbox│
│           browser-native, signed-URL src ]           │  ────────────  │
│                                                     │  Linked to:    │
│                                                     │  Bill #4471 →  │
├─────────────────────────────────────────────────────┤  Folder: none  │
│  (Extracted Data tab: OCR text, classification,       │  ────────────  │
│   invoice_fields table, per-field confidence;         │  Retention:    │
│   Versions tab: v1, v2… ; Activity: upload/OCR/       │  10y (Kuwait   │
│   classify/extract/attach trail + audit_logs)         │  Commercial    │
│                                                        │  Code) until   │
│                                                        │  2036-07-15    │
└───────────────────────────────────────────────────┴───────────────┘
```

- **Page Header** — back-to-library link, file name (editable inline for a caller holding `documents.attach`, since renaming is conceptually part of re-filing, not a separate permission), no lifecycle-status pill (there is no lifecycle to show — `# Purpose`), and actions: **Download** (signed URL, always present under `documents.read`), **Move** (re-parent, `documents.attach`), an overflow menu for **Run OCR** / **Classify** / **Extract invoice fields** / **Extract receipt fields** (each present only when its own permission is held and, for the two extraction actions, only after `classify_document` has resolved a matching `document_type` or the caller explicitly overrides the suggested type), **Archive/Delete** (`documents.delete`, replaced by a disabled state with an explanatory `Tooltip` when a legal hold is active — `# Edge Cases` — never silently omitted, since a legal hold is exactly the kind of RBAC-adjacent-but-not-RBAC restriction `docs/frontend/ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves` requires an explanation for).
- **Tab/Segment Nav** — `Tabs`: **Preview** (default), **Extracted Data**, **Versions**, **Activity** — the same four-way shape `docs/frontend/LAYOUT_SYSTEM.md`'s Detail Page Template names for other entities, adapted here to a file rather than a ledger record.
- **Main Column (8/12)** — the active tab's content: `DocumentPreviewFrame` (Preview), `ExtractedDataPanel` (Extracted Data), `DocumentVersionsList` (Versions), or the merged upload/OCR/classify/extract/attach/archive trail plus `audit_logs` rows (Activity) — exactly the same "Activity Timeline always lives in the Main Column, never the rail" rule `docs/frontend/LAYOUT_SYSTEM.md` states.
- **Summary Rail (4/12)** — file facts (size, mime, uploaded by user or **AI agent** avatar, checksum in a collapsed monospace `Tooltip` for support/audit use), the linked record (`attachable_label` as a real link when `attachable_href` is present, or a "File this document" call-to-action when `attachable_type = 'inbox'`), the current folder, and the read-only **Retention** card (`# Data & State`, `# AI Integration` does not apply here — retention is a database-policy fact, not an AI output).

# Components Used

Every component below is drawn from `docs/frontend/COMPONENT_LIBRARY.md` as-is, or is a screen-specific composed component this document introduces for this exact surface, following that document's own stated convention that a genuine component gap is "proposed as an addition to that catalogue, never hand-rolled locally and left undocumented." No Attachment/Upload/Preview component existed anywhere in `COMPONENT_LIBRARY.md` prior to this document — the ten marked **(new)** below are this screen's contribution to that shared catalogue, immediately reusable by any future record screen's own Attachments tab instead of that screen hand-rolling a second uploader.

| Component | Source | Used for |
|---|---|---|
| `DataTable` | `components/shared/data-table.tsx` | The Library's List-mode Data Region (`resource="documents"`, `paginationMode="cursor"`) |
| `PermissionGate` | `components/shared/permission-gate.tsx` | Wraps every mutating affordance named in `# Route & Access` |
| `ConfidenceBadge` | `components/ai/confidence-badge.tsx` | Classification and extraction confidence, everywhere a percentage is shown |
| `AiCardShell` | `components/ai/ai-card-shell.tsx` | The Extracted Data tab's classification/extraction results envelope |
| `ReasoningDisclosure` | `components/ai/reasoning-disclosure.tsx` | Collapsed-by-default OCR raw text / field-confidence breakdown |
| `Tabs` | `components/ui/tabs.tsx` | Preview's Preview / Extracted Data / Versions / Activity segmentation |
| `Sheet` | `components/ui/sheet.tsx` | The intercepted `[attachmentId]` preview overlay; the mobile Folder Tree drawer; the mobile Filters drawer |
| `Dialog` / `AlertDialog` | `components/ui/dialog.tsx`, `components/ui/alert-dialog.tsx` | New-folder naming, delete/archive confirmation, re-parent target confirmation, discard-in-progress-upload confirmation |
| `DropdownMenu` | `components/ui/dropdown-menu.tsx` | Card/row overflow actions; the Page Header's Export menu |
| `Badge` | `components/ui/badge.tsx` | Classification chips; "AI agent" uploader badge; filing-state chip |
| `Skeleton` | `components/ui/skeleton.tsx` | Grid/list/preview loading placeholders |
| `EmptyState` / `ErrorState` | `components/shared/*` | Whole-library and per-folder empty/error rendering (`# States`) |
| Toast (`useApiToast`) | `hooks/use-api-toast.ts` | Every mutation's success/error surfacing, including the duplicate-upload notice (`# Edge Cases`) |
| `DocumentUploader` **(new)** | `components/documents/document-uploader.tsx` | Drag-and-drop zone + the Page Header's "Upload" button; drives the presigned-URL flow (`# Data & State`) |
| `DocumentGrid` **(new)** | `components/documents/document-grid.tsx` | Grid-mode Data Region: virtualization, card sizing, selection state |
| `DocumentCard` **(new)** | `components/documents/document-card.tsx` | One file's thumbnail/icon, name, classification, filing + extraction status |
| `DocumentPreviewFrame` **(new)** | `components/documents/document-preview-frame.tsx` | Browser-native inline preview for images and PDFs; a styled "Download to view" fallback for every other mime type |
| `ExtractedDataPanel` **(new)** | `components/documents/extracted-data-panel.tsx` | OCR text, classification, and invoice/receipt field tables with per-field `ConfidenceBadge`s |
| `DocumentVersionsList` **(new)** | `components/documents/document-versions-list.tsx` | `document_versions` history, each row a signed-URL download of that exact version |
| `FolderTree` **(new)** | `components/documents/folder-tree.tsx` | The Library rail's nestable `document_folders` navigator, read-only or editable per `documents.folder.manage` |
| `AttachablePicker` **(new)** | `components/documents/attachable-picker.tsx` | The "Move to record" target selector — a searchable combobox over `AttachableType` + a record-specific lookup, built on the identical shadcn `Command`+`Popover` pattern `AccountPicker`/`CustomerPicker` already establish |
| `RetentionBadge` **(new)** | `components/documents/retention-badge.tsx` | The Summary Rail's read-only retention/legal-hold indicator |
| `ExtractionStatusPill` **(new)** | `components/documents/extraction-status-pill.tsx` | The client-derived Not started / Processing / Extracted / Failed indicator (`# Data & State`) |

## `DocumentUploader`

Drives the platform's presigned-direct-to-R2 path — this screen's own chosen mechanism among the two `docs/ai/tools/DOCUMENT_TOOLS.md`'s `upload_document` tool already names in prose ("Never inline more than 8 MB of base64 content in this call; for larger files, request a direct-to-R2 upload URL via `POST /api/v1/documents/upload-url` and pass the returned object key here instead of raw bytes"). That tool's own JSON Schema fully specifies the small-file, in-memory `base64` path because that is the shape an AI agent handling an already-in-memory email attachment needs; a human's browser instead always has a `File` handle, so the Document Center always takes the presigned-URL branch regardless of file size, never round-tripping raw bytes through the Next.js server or Laravel at all. This is a deliberate, narrow, additive completion of a mechanism `DOCUMENT_TOOLS.md` had already named but not fully schema'd — see `# Data & State` for the three-call sequence this component drives.

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `attachableType` | `AttachableType` | no | Pre-targets the upload at a specific record (e.g. from a record screen's own "Upload" button); defaults to `'inbox'` when opened from the bare Library toolbar. |
| `attachableId` | `number` | conditional | Required unless `attachableType` is omitted/`'inbox'`. |
| `folderId` | `number \| null` | no | Pre-selects the destination folder; defaults to the currently browsed folder, `null` at the root. |
| `onUploaded` | `(attachment: Attachment) => void` | no | Called once per file after its finalize call resolves — used to append the new card without a full list refetch. |
| `maxConcurrent` | `number` | no, default `3` | Caps simultaneous in-flight uploads for a multi-file drop; additional files queue client-side. |

```tsx
// components/documents/document-uploader.tsx
'use client';

import { useCallback, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/api-client';
import { documentKeys } from '@/lib/query/keys';
import { useApiToast } from '@/hooks/use-api-toast';
import { UploadCloud } from 'lucide-react';
import { useTranslations } from 'next-intl';
import type { Attachment, AttachableType } from '@/types/documents';

interface UploadTask { file: File; progress: number; status: 'pending' | 'uploading' | 'finalizing' | 'done' | 'error'; }

export function DocumentUploader({ attachableType = 'inbox', attachableId, folderId = null, onUploaded, maxConcurrent = 3 }: DocumentUploaderProps) {
  const t = useTranslations('documents.uploader');
  const toast = useApiToast();
  const queryClient = useQueryClient();
  const [tasks, setTasks] = useState<UploadTask[]>([]);

  const uploadOne = useMutation({
    mutationFn: async (file: File) => {
      // 1) Ask Laravel for a presigned PUT — this document's own completion of the
      //    upload-url mechanism DOCUMENT_TOOLS.md's upload_document description already names.
      const { upload_url, object_key, expires_at } = await apiClient.post<UploadUrlResponse>(
        '/api/v1/documents/upload-url',
        { file_name: file.name, mime_type: file.type, size_bytes: file.size, attachable_type: attachableType, attachable_id: attachableId },
      );
      // 2) PUT the raw bytes directly to Cloudflare R2 — browser to R2, no Laravel/Next hop for the payload itself.
      await fetch(upload_url, { method: 'PUT', headers: { 'Content-Type': file.type }, body: file });
      // 3) Finalize: create the attachments row, referencing the object already in R2 rather than re-sending bytes.
      return apiClient.post<Attachment>('/api/v1/documents', {
        attachable_type: attachableType,
        attachable_id: attachableId ?? 0,
        file_name: file.name,
        source: { type: 'object_key', media_type: file.type, object_key },
        document_folder_id: folderId,
        source_channel: 'user_upload',
      }, { headers: { 'Idempotency-Key': crypto.randomUUID() } });
    },
    onSuccess: (attachment, file) => {
      if (attachment.is_duplicate) {
        toast.info(t('duplicateDetected', { fileName: attachment.file_name }));
      } else {
        toast.success(t('uploaded', { fileName: file.name }));
      }
      queryClient.invalidateQueries({ queryKey: documentKeys.lists() });
      onUploaded?.(attachment);
    },
    onError: (err, file) => toast.fromApiError(err, { context: file.name }),
  });

  const onDrop = useCallback((files: FileList) => {
    Array.from(files).forEach((file) => uploadOne.mutate(file));
  }, [uploadOne]);

  return (
    <div
      role="button"
      tabIndex={0}
      aria-label={t('dropZoneLabel')}
      onDrop={(e) => { e.preventDefault(); onDrop(e.dataTransfer.files); }}
      onDragOver={(e) => e.preventDefault()}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') document.getElementById('doc-upload-input')?.click(); }}
      className="rounded-lg border border-dashed border-ink-150 p-8 text-center hover:bg-ink-100/40 focus-visible:ring-2 focus-visible:ring-accent-600"
    >
      <UploadCloud className="mx-auto size-8 text-ink-500" aria-hidden="true" />
      <p className="mt-2 text-sm text-ink-700">{t('dropOrClick')}</p>
      <input
        id="doc-upload-input" type="file" multiple className="sr-only"
        onChange={(e) => e.target.files && onDrop(e.target.files)}
      />
    </div>
  );
}
```

The finalize call's `source.type: 'object_key'` is this document's own narrow, additive third member of `upload_document`'s `source` discriminated union — `docs/ai/tools/DOCUMENT_TOOLS.md`'s own JSON Schema enumerates only `base64`/`url` because those are the two shapes an AI agent needs, but its prose explicitly promises a caller may "pass the returned object key here instead of raw bytes"; this component is where that promised third shape is filled in concretely, never a contradiction of the two the schema already names. Every finalize call carries a client-generated `Idempotency-Key`, per the platform-wide idempotency convention `docs/frontend/FRONTEND_ARCHITECTURE.md` Principle 9 requires of every unsafe mutation, so a flaky connection retrying step 3 can never double-create the row — a retried key with an identical payload returns the original `201`, exactly as `docs/ai/tools/DOCUMENT_TOOLS.md`'s own `upload_document` endpoint note already states.

## `DocumentCard`

**Props**

| Prop | Type | Required | Description |
|---|---|---|---|
| `attachment` | `Attachment` | yes | The row to render. |
| `size` | `'comfortable' \| 'compact'` | no, default `'comfortable'` | Thumbnail size, bound to the Grid's own density control. |
| `selected` | `boolean` | yes | Bulk-selection state. |
| `onSelect` | `(id: number, selected: boolean) => void` | yes | |
| `onOpen` | `(id: number) => void` | yes | Opens the intercepted preview `Sheet`. |

```tsx
// components/documents/document-card.tsx
import Image from 'next/image';
import { FileText, FileSpreadsheet, FileImage, File as FileGeneric } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { ExtractionStatusPill } from '@/components/documents/extraction-status-pill';
import { useLocale, useTranslations } from 'next-intl';
import type { Attachment } from '@/types/documents';

const MIME_ICON: Record<string, typeof FileText> = {
  'application/pdf': FileText,
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': FileSpreadsheet,
  'image/jpeg': FileImage, 'image/png': FileImage,
};

export function DocumentCard({ attachment, size = 'comfortable', selected, onSelect, onOpen }: DocumentCardProps) {
  const locale = useLocale();
  const t = useTranslations('documents.card');
  const isImage = attachment.mime_type.startsWith('image/');
  const Icon = MIME_ICON[attachment.mime_type] ?? FileGeneric;

  return (
    <div
      role="button" tabIndex={0}
      aria-label={t('openA11y', { fileName: attachment.file_name })}
      onClick={() => onOpen(attachment.id)}
      onKeyDown={(e) => { if (e.key === 'Enter') onOpen(attachment.id); }}
      className="group relative rounded-lg border border-ink-150 bg-surface p-2 hover:shadow-md focus-visible:ring-2 focus-visible:ring-accent-600"
    >
      <Checkbox
        checked={selected}
        onCheckedChange={(v) => onSelect(attachment.id, Boolean(v))}
        onClick={(e) => e.stopPropagation()}
        aria-label={t('selectA11y', { fileName: attachment.file_name })}
        className="absolute start-2 top-2 z-10 bg-surface"
      />
      <div className={size === 'compact' ? 'aspect-[4/3]' : 'aspect-square'}>
        {isImage
          ? <Image src={attachment.signed_url} alt="" fill sizes="200px" className="rounded-md object-cover" unoptimized />
          : <div className="flex h-full items-center justify-center"><Icon className="size-10 text-ink-300" aria-hidden="true" /></div>}
      </div>
      <p className="mt-2 truncate text-sm font-medium text-ink-950" title={attachment.file_name}>{attachment.file_name}</p>
      <div className="mt-1 flex items-center gap-1.5">
        {attachment.classification && <Badge tone="neutral">{t(`classification.${attachment.classification.document_type}`)}</Badge>}
        {attachment.attachable_type === 'inbox'
          ? <Badge tone="warning">{t('needsFiling')}</Badge>
          : null}
      </div>
      <ExtractionStatusPill attachment={attachment} className="mt-1" />
    </div>
  );
}
```

An image thumbnail renders the *actual* signed URL at browser-downscaled size — no separate thumbnail-generation service is named anywhere in the platform's storage or Documents specifications, and this document does not invent one; a full-resolution image simply downloads once and the browser's own `object-cover` scales it, acceptable at Library-grid sizes and consistent with `docs/frontend/DARK_MODE.md`'s stated rule that "photographic and user-generated content is never recolored" — the same restraint applies to never being synthetically re-rendered into a thumbnail this platform's backend does not produce. PDFs and every other mime type render a plain glyph rather than a fabricated preview image, and the actual content only ever renders inline in `DocumentPreviewFrame` once a user opens the file.

# Data & State

## Endpoints

Rows marked **Backend-documented** are `docs/ai/tools/DOCUMENT_TOOLS.md`'s own eight tool endpoints, reused verbatim — same path, same permission, same request/response shape a human's click now drives instead of an agent's tool call. Rows marked **(new)** are this document's own narrow, additive endpoints: folder browsing/management (a human-only organizational feature no AI tool needs), version listing (likewise human-only browsing), the presigned-upload completion, and the two list-level conveniences (`attachable_label`/`attachable_href` resolution and `retention`) layered onto the existing list/detail responses — mirroring exactly how `docs/frontend/EMPLOYEES.md` added a `/stats` endpoint "thinly wraps an existing backend service" without contradicting it.

| Method & Path | Permission | Hook | Query key | Status |
|---|---|---|---|---|
| `GET /api/v1/documents` | `documents.read` | `useDocuments(filters)` | `documentKeys.list(filters)` | Backend-documented (`list_attachments`), **+`attachable_label`/`attachable_href`/`retention` fields (new)** |
| `GET /api/v1/documents/{id}` | `documents.read` | `useDocument(id)` | `documentKeys.detail(id)` | Backend-documented (`get_attachment`), **+ same additive fields** |
| `POST /api/v1/documents/upload-url` | `documents.upload` | `useCreateUploadUrl()` | not cached — one-shot mutation | **(new)** — schema-completes the path `upload_document`'s own description already names in prose |
| `POST /api/v1/documents` | `documents.upload` | `useUploadDocument()` | invalidates `documentKeys.lists()` | Backend-documented (`upload_document`), **+ `source.type: 'object_key'` (new)** |
| `POST /api/v1/documents/{id}/ocr` | `documents.ocr` | `useRunOcr()` | invalidates `documentKeys.detail(id)` | Backend-documented (`ocr_document`) |
| `POST /api/v1/documents/{id}/classify` | `documents.classify` | `useClassifyDocument()` | invalidates `documentKeys.detail(id)` | Backend-documented (`classify_document`) |
| `POST /api/v1/documents/{id}/extract-invoice-fields` | `documents.extract` | `useExtractInvoiceFields()` | invalidates `documentKeys.detail(id)` | Backend-documented (`extract_invoice_fields`) |
| `POST /api/v1/documents/{id}/extract-receipt-fields` | `documents.extract` | `useExtractReceiptFields()` | invalidates `documentKeys.detail(id)` | Backend-documented (`extract_receipt_fields`) |
| `PATCH /api/v1/documents/{id}/attach` | `documents.attach` | `useAttachDocument()` | invalidates `documentKeys.detail(id)` + `lists()` + the previous and new `attachable`'s own attachment queries | Backend-documented (`attach_document`) |
| `POST /api/v1/documents/{id}/archive` \| `DELETE /api/v1/documents/{id}` | `documents.delete` | `useArchiveDocument()` / `useDeleteDocument()` | invalidates `detail(id)` + `lists()` | **(new)** — the human-facing consumer of the permission `DOCUMENT_TOOLS.md` reserved for "a future `delete_attachment` administrative tool" |
| `GET /api/v1/documents/{id}/versions` | `documents.read` | `useDocumentVersions(id)` | `documentKeys.versions(id)` | **(new)** — read-only projection of the already-fully-specified `document_versions` table |
| `GET /api/v1/documents/folders` | `documents.read` | `useDocumentFolders(parentId)` | `folderKeys.children(parentId)` | **(new)** |
| `POST /api/v1/documents/folders` | `documents.folder.manage` | `useCreateFolder()` | invalidates `folderKeys.all` | **(new)** |
| `PATCH /api/v1/documents/folders/{id}` | `documents.folder.manage` | `useUpdateFolder()` | invalidates `folderKeys.all` | **(new)** |
| `DELETE /api/v1/documents/folders/{id}` | `documents.folder.manage` | `useDeleteFolder()` | invalidates `folderKeys.all` + `documentKeys.lists()` (contained files' `document_folder_id` becomes `null`, per `docs/database/ERD.md`'s stated delete rule) | **(new)** |
| `GET /api/v1/documents/search` | `documents.read` | `useDocumentSearch(q, filters)` | `documentKeys.search(q, filters)` | Backend-documented — the identical endpoint `docs/frontend/SEARCH.md`'s own Documents result group already calls |
| `POST /api/v1/documents/bulk/move` \| `/archive` \| `/export` | `documents.attach` / `.delete` / `.export` | `useBulkMoveDocuments()` etc. | invalidates `lists()` on settle | **(new)** — per-item `data.results[i]` reporting, mirroring `docs/accounting/SALES.md`'s bulk-post contract |

## The two Documents search endpoints, reconciled

`docs/frontend/SEARCH.md`'s own Documents result group already calls `GET /api/v1/documents/search`, and `docs/ai/tools/SEARCH_TOOLS.md`'s `semantic_search_documents` tool calls a second, parallel `GET /api/v1/ai/documents/search`. This document reconciles the two exactly the way `docs/frontend/SEARCH.md` itself models reconciling a small pre-existing inconsistency rather than silently picking one: they front the identical chunk-embedding index over OCR'd attachment text (`docs/database/DATABASE_ARCHITECTURE.md`'s named `vector`-extension use, "future semantic search over `attachments`"), reached through the platform's ordinary `/api/v1/...` vs `/api/v1/ai/...` split every other AI-tool sibling already uses — one path for a human-driven web request, one for an agent's own tool call. The Document Center's own search box, the Library's `q` parameter, and the Global Search Documents group all call the human-facing `GET /api/v1/documents/search`; only the FastAPI AI layer's own agents call the `/ai/` variant. Neither path is a duplicate implementation of ranking — `docs/frontend/SEARCH.md`'s own stated rule that "a ranking bug or a permission bug can live in exactly one place per entity type, never two" applies here identically, since both routes are documented as thin controllers over the same underlying `tsvector`/`pgvector` query.

## Example — List response (excerpt)

```json
{
  "success": true,
  "data": [
    {
      "id": 8801, "attachable_type": "bill", "attachable_id": 4471,
      "attachable_label": "Bill — ACME Supplies (BILL-2026-00812)",
      "attachable_href": "/purchasing/bills/4471",
      "document_folder_id": null,
      "file_name": "ACME-Supplies-INV-2026-0715.pdf", "mime_type": "application/pdf",
      "size_bytes": 84210, "status": "active",
      "uploaded_by": null, "ai_agent_id": 12, "is_ai_extracted": true,
      "classification": { "document_type": "vendor_invoice", "confidence": 0.99 },
      "extracted_data": { "ocr": { "overall_confidence": 0.98 }, "invoice_fields": { "extraction_confidence": 0.94 } },
      "retention": { "policy": "statutory", "expires_at": "2036-07-15", "legal_basis": "Kuwait Commercial Code Art. 72", "legal_hold": false },
      "version_count": 1, "created_at": "2026-07-15T08:41:02Z", "updated_at": "2026-07-15T08:59:40Z"
    },
    {
      "id": 9104, "attachable_type": "inbox", "attachable_id": 88431,
      "attachable_label": null, "attachable_href": null,
      "document_folder_id": null,
      "file_name": "receipt-0714.jpg", "mime_type": "image/jpeg",
      "size_bytes": 612000, "status": "active",
      "uploaded_by": 1042, "ai_agent_id": null, "is_ai_extracted": false,
      "classification": null, "extracted_data": null,
      "retention": { "policy": "none", "expires_at": null, "legal_basis": null, "legal_hold": false },
      "version_count": 1, "created_at": "2026-07-14T19:24:00Z", "updated_at": "2026-07-14T19:24:00Z"
    }
  ],
  "message": "Documents retrieved", "errors": [],
  "meta": { "pagination": { "mode": "cursor", "per_page": 24, "next_cursor": "eyJjcmVhdGVkX2F0IjoiMjAyNi0wNy0xNFQxOToyNDowMFoiLCJpZCI6OTEwNH0=", "total_hint": 2140 } },
  "request_id": "b7e1e2d3-4f5a-4b6c-9d0e-1f2a3b4c5d6e", "timestamp": "2026-07-16T09:02:11Z"
}
```

`attachable_label`/`attachable_href` are resolved server-side, per row, exactly the way `docs/frontend/SEARCH.md`'s own citation resolution works ("a citation the caller cannot read is never rendered") — `attachable_href` is `null`, not a broken link, whenever the caller lacks the owning record's own read permission (a Sales Employee browsing Documents sees a bill's attachment but no working link into Purchasing if they lack `purchasing.bill.read`). `retention` is this document's own additive field, computed the same way `docs/database/DATABASE_ARCHIVING.md`'s nightly purge-eligibility scan already resolves a table's effective retention (`archive_tier_defaults` joined with any company-specific `archive_policies` override, `MAX()`'d across every policy touching the record, per that document's own `# Compliance` section) — resolved here per attachment, read-only, and never itself editable from this screen; a `legal_hold: true` value always means the same `archive_policies`-level hold `DATABASE_ARCHIVING.md` already defines, surfaced here rather than re-derived.

## `useDocuments` and pagination mode

`docs/api/API_PAGINATION.md → Strategy` sorts every resource into page or cursor mode by shape, not client preference, and `attachments` matches the "large, append-only, unbounded growth" row precisely — every upload, OCR run, and AI auto-attach across the whole company inserts into the same table, exactly like `audit_logs` or `ledger_entries`. `GET /api/v1/documents` is therefore cursor-mode only, and `useDocuments` follows the identical `useInfiniteQuery` shape `docs/frontend/FRONTEND_ARCHITECTURE.md` already gives `useLedgerEntries`:

```ts
// hooks/documents/use-documents.ts
export function useDocuments(filters: DocumentFilters) {
  return useInfiniteQuery({
    queryKey: documentKeys.list(filters),
    queryFn: ({ pageParam }) =>
      apiClient.get<CursorPage<Attachment>>('/api/v1/documents', { params: { ...filters, cursor: pageParam, per_page: 24 } }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.meta.pagination.next_cursor,
    staleTime: 30_000, // transactional-list class, per FRONTEND_ARCHITECTURE.md's cache-tuning table
  });
}
```

## Query keys and cache tuning

```ts
// lib/query/keys.ts (documents-scoped factories)
export const documentKeys = {
  all: ['documents'] as const,
  lists: () => [...documentKeys.all, 'list'] as const,
  list: (filters: DocumentFilters) => [...documentKeys.lists(), filters] as const,
  detail: (id: number) => [...documentKeys.all, 'detail', id] as const,
  versions: (id: number) => [...documentKeys.all, 'versions', id] as const,
  search: (q: string, filters: Record<string, unknown>) => [...documentKeys.all, 'search', q, filters] as const,
};

export const folderKeys = {
  all: ['document-folders'] as const,
  children: (parentId: number | null) => [...folderKeys.all, 'children', parentId] as const,
};
```

| Data class | Resource | `staleTime` | Rationale |
|---|---|---|---|
| Library list/search | `documentKeys.list(*)`, `documentKeys.search(*)` | `30_000` | Transactional-list class per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s cache-tuning table — frequent enough writes (constant uploads) that a stale list is a real annoyance, not worth polling |
| Attachment detail | `documentKeys.detail(id)` | `30_000`, refetched on window focus while its Extracted Data tab is open | A caller may trigger OCR/classify/extract from another tab or device; refocusing should not show a stale "not yet extracted" state |
| Versions | `documentKeys.versions(id)` | `5 * 60_000` | Rarely-changing once a file stops being actively revised — reference-data class |
| Folder tree | `folderKeys.children(*)` | `5 * 60_000` | Rarely-changing reference/master data, identical tuning to `accounts`/`cost-centers` in `FRONTEND_ARCHITECTURE.md`'s own table |

## Realtime

A narrow, additive private channel, `private-company.{id}.documents`, mirrors the exact shape `docs/frontend/FRONTEND_ARCHITECTURE.md`'s realtime table already uses for `private-company.{id}.approvals` ("New/updated rows"): it fires `document.uploaded`, `document.attached` (re-parented), and `document.archived` events, each carrying just the attachment id and its folder, and the Library's own Echo listener merges the change directly into the relevant `documentKeys.list(*)` cache entries via `queryClient.setQueryData` — never a second, hand-reconciled "realtime documents" store, per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s stated rule for every other realtime surface. This channel exists purely for **multi-user visibility** (a colleague filing an inbox scan while this screen is open elsewhere) — it does not, and does not need to, carry OCR/classification/extraction progress, because every one of those four calls is a synchronous request/response this screen's own caller awaits directly (`docs/ai/tools/DOCUMENT_TOOLS.md`'s worked examples all show a `processing_ms` figure in the low seconds, consistent with an ordinary HTTP round trip, never a queued job with its own status column); a second person watching the same attachment mid-extraction simply sees the Library's own 30-second `staleTime` catch up, or refetches on their own action, exactly as any other transactional-list resource does.

# Interactions & Flows

**Uploading a file.** Dragging one or more files onto the Library (anywhere over the Data Region, not only the `DocumentUploader` drop zone — the whole Grid/List surface is a valid drop target when `documents.upload` is held) or clicking "Upload" opens the same component. Each file runs the three-call sequence in `# Components Used → DocumentUploader` independently and in parallel up to `maxConcurrent`; a `DocumentCard`-shaped placeholder with an inline progress bar appears immediately per file (optimistic *presence*, never optimistic *content* — the card shows "Uploading…" and only swaps to the real thumbnail once the finalize call actually returns, so a failed virus scan or a rejected mime type never leaves a phantom card behind). A file dropped with no `attachable_type` context defaults to `'inbox'`, immediately visible under "Needs filing." A file dropped from a record screen's own Attachments tab (Invoices, Journal Entries, Employees, …) instead carries that record's own `attachable_type`/`attachable_id` from the start and never touches the inbox at all — that existing per-screen upload affordance now calls this same `DocumentUploader` component rather than a screen-local, hand-rolled uploader, per `# Components Used`'s stated reuse intent.

**Filing an inbox item.** Clicking "Needs filing," then a card, opens the Preview with a prominent "File this document" call-to-action in the Summary Rail in place of the usual linked-record display. Choosing a target through `AttachablePicker` (a two-step combobox: pick the record type, then search that type's own records — reusing `CustomerPicker`/`AccountPicker`'s exact `Command`+`Popover` shape) and confirming calls `attach_document`; on success the card's filing-state chip flips from "Needs filing" to nothing (a filed document carries no chip at all, since "filed" is the unmarked default state, not itself worth a badge — only the exceptional "needs attention" state earns a chip, the same restraint `docs/frontend/DASHBOARD.md`'s own KPI framing applies to "nothing to report").

**Running OCR, classification, or extraction.** Each of the three overflow-menu actions is a single click that calls its own endpoint and awaits it inline — the triggering menu item shows a spinner and disables itself for the duration (`loading` prop on `Button`, per `docs/frontend/COMPONENT_LIBRARY.md`), and the Extracted Data tab's content streams in the moment the response resolves, with no separate "check back later" step because there is no queued job to check back on (`# Data & State`). Classification typically precedes extraction in the UI's own suggested order — the overflow menu greys in "Extract invoice fields" only once a `document_type` of `vendor_invoice`/`customer_invoice` is known (from a prior classification, or from the caller explicitly asserting a type via a small "I know what this is" override picker) — but a caller who already knows the type from context can skip straight to extraction, exactly as `docs/ai/tools/DOCUMENT_TOOLS.md`'s own `extract_invoice_fields` describes ("Internally re-runs OCR once, transparently, if the attachment has no `extracted_data.ocr` payload yet").

**Handing an extraction off to its owning module.** Once `extract_invoice_fields` has resolved for a `vendor_invoice`-classified attachment, the Extracted Data tab's header gains a single, prominent hand-off button — **"Create bill from this"** (visible only when the caller holds `purchasing.bill.create`, per that module's own permission, not a Documents-owned one) — navigating to `/purchasing/bills/new?from_attachment=8801`, pre-filling that screen's own Builder from the identical `invoice_fields` payload this screen already fetched, never a second, parallel bill-creation form living inside Document Center. This is the same posture `docs/frontend/SEARCH.md` states for its own citations: Document Center helps a user find and understand a scanned invoice; creating the bill it should become is always the owning module's own governed create flow, opened pre-filled, never short-circuited from here. The equivalent "Create expense claim from this" appears for a `receipt`-classified attachment once `extract_receipt_fields` has resolved.

**Moving files and folders.** Drag-and-drop within the Grid (a card dragged onto a Folder Tree node) and an explicit "Move to folder" menu action both call the same bulk-or-single move endpoint; dragging a Folder Tree node onto another node re-parents the folder itself via `PATCH /api/v1/documents/folders/{id}`. Every drag interaction has an equivalent keyboard/menu path — "Move to folder" opens a `Dialog` with the identical `FolderTree` picker — so drag-and-drop is always a convenience, never the only way to perform the action, per `docs/frontend/ACCESSIBILITY.md`'s general "every interaction has a non-pointer equivalent" posture.

**Bulk selection and action.** Checking multiple cards/rows (via the card's own checkbox, `Shift`-click range-select, or `Cmd/Ctrl+A` to select the current page) surfaces the Bulk Action Bar; each bulk action (`Move`, `Archive`, `Export`, `Download`) posts once to its own bulk endpoint and renders a per-item result toast summary ("5 moved, 1 failed — Diyar-Lease.pdf is under legal hold") rather than a single opaque success/failure, matching `docs/accounting/SALES.md`'s bulk-operations contract every other bulk action in the platform already follows.

**Searching.** Typing into the Filter Bar's search input debounces at 250ms (mirroring `docs/frontend/SEARCH.md`'s own palette debounce) and calls `GET /api/v1/documents/search`, which returns both literal file-name/metadata matches and semantic in-content matches in one ranked list, each row's `matched_by: "text" | "semantic"` rendered as the identical muted "Matched by meaning" chip `docs/frontend/SEARCH.md → AI Integration` already defines for its own Documents result group — reused verbatim here, not re-invented, since this is the same field on the same underlying row.

**Preview and download.** Opening a card (click, or `Enter` when focused) opens the intercepted `Sheet` preview; a middle-click or `Cmd/Ctrl`-click instead follows the card's own `href` (`/documents/{id}`) as a normal link into a new tab, since `DocumentCard`'s root is a real, focusable, `role="button"`-with-keyboard-handler element backed by an actual navigable route, not a `<div onClick>` with no link semantics. "Download" always re-requests a fresh signed URL immediately before use rather than reusing one already loaded on screen, because the Preview's own `signed_url` may have already crossed its 15-minute expiry (`docs/ai/tools/DOCUMENT_TOOLS.md`'s stated default) by the time a user gets around to clicking Download on a page they opened a while ago.

# AI Integration

Two named specialists feed this screen, and their division of labor is exactly `docs/ai/tools/DOCUMENT_TOOLS.md`'s own: **OCR Agent** (`agent_code = 'OCR_AGENT'`) performs raw digitization — text, per-page confidence, detected language — and never determines what a document *is* or structures anything into a typed schema; **Document AI** (`agent_code = 'DOCUMENT_AI'`) performs classification and structured field extraction on top of OCR Agent's output (or directly against a native-text PDF). Every result either agent produces renders through the platform's standard AI provenance treatment — `AiCardShell`'s colored border and "AI" badge, a `ConfidenceBadge` normalized to the canonical 0–1 range, and a collapsed-by-default `ReasoningDisclosure` — per Principle 3's platform-wide rule that "every AI-originated number, suggestion, draft, or flag... carries its confidence score and its reasoning."

## Autonomy: suggest-and-surface, not suggest-and-gate

This is the one screen in the platform whose AI-triggered actions are **not** wrapped in an `ApprovalCard`, and the reason is structural, not a shortcut: `docs/ai/tools/DOCUMENT_TOOLS.md` states plainly that these six tools are tagged `write_propose` in a "broader, explicitly non-financial sense... none of these six tools can create, post, approve, or move a financial fact," and `attachments` itself carries no `draft`/`pending_approval` state for a human to adjudicate — "a stored file simply *is* stored the moment the call succeeds." Clicking "Run OCR" is closer, in kind, to clicking "Regenerate" than to clicking "Post" — the caller who triggered it already decided they wanted the result, and the result is data about a file, not a change to a financial record. The platform's approval machinery still applies, in full, exactly one layer downstream: the moment an extraction's payload is handed to Purchasing's `create_bill_draft_from_ocr` (via this screen's own "Create bill from this" button, `# Interactions & Flows`), the resulting `bills` row lands in `draft`, and posting it runs through Purchasing's own `purchasing.bill.post`/approval chain exactly as `docs/accounting/PURCHASES.md` already specifies — Document Center's job ends at handing off a well-formed, confidence-scored draft payload, never at deciding whether it becomes a posted fact.

## Confidence floors and low-confidence handling

`docs/ai/tools/DOCUMENT_TOOLS.md` states a concrete threshold this screen enforces visually: an extraction's overall `extraction_confidence` below 0.60 "must route to manual entry rather than a one-click accept, per `docs/accounting/PURCHASES.md`." Concretely, `ExtractedDataPanel` renders the "Create bill from this"/"Create expense claim from this" hand-off button in a visually de-emphasized, still-clickable-but-clearly-secondary treatment and prepends a plain-language caption — "Confidence is low; review every field before creating a bill" — rather than hiding the button outright, because a human reviewer legitimately may still want to jump into the owning module's Builder with whatever the model got right pre-filled and correct the rest by hand; what the low-confidence state removes is only the *appearance* of a trustworthy one-click action, never the underlying capability. Per-field confidence (`field_confidence`, already part of `extract_invoice_fields`'/`extract_receipt_fields`'s own output schema) renders as a small `ConfidenceBadge` beside each extracted value in the panel's table, so a reviewer can see at a glance that `vendor_name` resolved at 97% while `due_date` resolved at 61% without needing to open a separate reasoning drawer for that granularity.

## Classification routing and the Compliance/Fraud/Tax connection

`classify_document`'s `routing_hint` field (`purchasing_agent | general_accountant | tax_advisor | compliance_agent | payroll_agent | null`) renders as a small, muted "Routed to {Agent}" caption on the card and in the Preview's Activity tab whenever present — informational only, never itself an action button, since routing is the classification step's own signal about which downstream agent's workflow this document is relevant to, not an instruction this screen executes. A `government_notice`-classified upload, for instance, shows "Routed to Compliance Agent" and links to nothing further from this screen — the actual compliance workflow it feeds is `docs/ai/agents/COMPLIANCE_AGENT.md`'s own territory, out of scope here exactly as `docs/frontend/SEARCH.md`'s own citations stop at "here is the record," never "here is what happens to it next."

## Fraud-adjacent signals surface, never auto-block

`extract_receipt_fields`'s `policy_flags` (heuristic observations like `round_number_amount`, `no_itemization`, `weekend_date`) render as small, neutral-toned inline badges on a receipt's Extracted Data tab — per `docs/ai/tools/DOCUMENT_TOOLS.md`'s own framing, "these are observations, not conclusions, and never themselves block or approve an expense claim." Document Center never withholds a download, a hand-off, or a filing action on the strength of a policy flag; the flags exist so a human reviewer (or, downstream, the expense-approval workflow `docs/ai/workflows/EXPENSE_APPROVAL.md` owns) has the same signal Fraud Detection would reason from, rendered plainly rather than acted on here.

## Duplicate detection is a data fact, not a model opinion

`is_duplicate` (from `upload_document`'s own output, keyed off `checksum_sha256` uniqueness per company) is deterministic, not an AI inference, and this screen is careful not to dress it up as one — the duplicate-detected toast (`# Interactions & Flows`) carries no `ConfidenceBadge` and no `AiCardShell` styling, because a byte-for-byte checksum match is a fact, not a probabilistic judgment, and conflating the two would blur the exact distinction `docs/frontend/DARK_MODE.md → AI provenance color` draws between "a static fact" and "a statement of the AI's certainty."

# States

`deriveExtractionStatus` is the one piece of presentational logic this screen computes client-side rather than reading as a server-owned field — deliberately, since `attachments` has no such column and Principle 1 permits exactly this kind of derived-for-display convenience as long as it never becomes a value submitted back to the server:

```ts
// lib/documents/derive-extraction-status.ts
export type ExtractionStatus = 'not_started' | 'processing' | 'extracted' | 'failed';

export function deriveExtractionStatus(
  attachment: Attachment,
  inFlight: { ocr: boolean; classify: boolean; extract: boolean },
): ExtractionStatus {
  if (inFlight.ocr || inFlight.classify || inFlight.extract) return 'processing';
  if (attachment.extracted_data?.invoice_fields || attachment.extracted_data?.receipt_fields
      || attachment.extracted_data?.classification || attachment.extracted_data?.ocr) return 'extracted';
  return 'not_started'; // 'failed' is set locally, per-attachment, only from this session's own onError — never persisted
}
```

A `'failed'` status is held only in local component/query-cache state for the duration of the current session (there is no server column recording a past failure — an unsuccessful `ocr_document`/`classify_document`/`extract_*` call simply returns an error and leaves `extracted_data` unchanged), so reloading the page or opening the attachment from a different device always shows `'not_started'`, not a stale `'failed'` — this is stated explicitly here because it is the one place this screen's own state model diverges from "the server is the only source of truth," and the divergence is scoped tightly enough (a transient UI hint, never persisted, never affecting a permission or a financial figure) to be consistent with Principle 1 rather than a violation of it.

| Region | Loading | Empty | Error |
|---|---|---|---|
| Library — first paint | Route-level `loading.tsx`: a grid of `Skeleton` card shapes (Grid mode) or `Skeleton` rows (List mode) matching the real layout's dimensions exactly, never a generic spinner, per `docs/frontend/FRONTEND_ARCHITECTURE.md`'s "never blank" convention | "No documents yet — upload your first file" plus the `DocumentUploader` drop zone inline, when the company-wide root has zero attachments at all | Route-level `error.tsx`: a recoverable card with "Retry" — reserved for a genuine `5xx`/network failure on the initial fetch |
| Library — filtered/searched | `keepPreviousData`-held previous grid, dimmed, while the new filter/query resolves — never a flash to empty mid-type | "No files match your filters" / "No results for '{q}'" — styled through the shared `EmptyState`, never an illustrated character, per `docs/frontend/DESIGN_LANGUAGE.md`'s imagery stance, distinguished from the true zero-documents empty state above by always including a "Clear filters" action | Inline retry card scoped to the Data Region only; the Folder Tree and Filter Bar remain interactive |
| "Needs filing" smart folder | Same skeleton as the Library | "Nothing to file — every document is linked to a record" (a genuinely good state, styled success-neutral, not a dead end) | Same inline retry |
| Folder Tree | `Skeleton` tree rows on first load only (rarely-changing reference data, `# Data & State`) | A brand-new company shows only "All Documents" and "Needs filing," with no prompt to create folders unless `documents.folder.manage` is held | Folder Tree keeps its last-known state and shows a small inline "Couldn't refresh folders" notice rather than collapsing the whole rail |
| Upload in progress | Per-file inline progress bar on its placeholder card (`# Interactions & Flows`) | n/a | A failed step (presign, R2 PUT, or finalize) replaces that file's card with an error state naming which step failed and a "Retry" action that re-runs only the failed step, never re-uploading bytes already sitting in R2 unnecessarily |
| Preview — file content | `Skeleton` frame at the target aspect ratio while the signed URL/embed resolves | n/a (a document with zero bytes cannot exist — `size_bytes` is `NOT NULL`) | "Couldn't load this file's preview — Download instead" with the Download button still fully functional; a preview failure never blocks metadata/extraction data from rendering |
| Extracted Data tab — OCR/classify/extract in flight | Three pulsing `accent-subtle` dots per `docs/frontend/DESIGN_LANGUAGE.md`'s calm AI-thinking pattern on the triggering action's own button, and a skeleton panel body | "Not yet processed — Run OCR / Classify / Extract" inline call-to-action per missing step, gated by the caller's own permission | A failed call surfaces `deriveExtractionStatus`'s local `'failed'` pill plus the API's own `message` inline (`# Edge Cases`), with an immediate "Retry" that re-issues the identical call |
| Versions tab | `Skeleton` rows | "No prior versions — this is the only version on file" | Inline retry, scoped to the tab |
| Bulk Action Bar | Individual per-item spinners inside the settling toast summary, never a page-level blocking overlay for a bulk action | n/a — the bar only ever appears with ≥1 row selected | Partial-failure summary toast (`# Interactions & Flows`), never a single opaque failure for the whole batch |

# Responsive Behavior

| Breakpoint | Behavior |
|---|---|
| `base`–`sm` (<768px) | `ViewToggle` defaults to Grid at 2 columns (`Compact` density) since a table is the weakest possible mobile rendering for a visual file library — this is the one screen where `docs/frontend/RESPONSIVE_DESIGN.md → Pattern 1`'s usual "table becomes cards below `md:`" behavior is simply the screen's own native state at every breakpoint, never a fallback. The Folder Tree collapses into a `Sheet` opened from a breadcrumb tap; Filters collapse into a `Sheet` opened from a filter-icon trigger in the Filter Bar, per `docs/frontend/RESPONSIVE_DESIGN.md → Pattern 4`. The Preview always opens as a full-height `Sheet` (never the desktop's centered `Dialog`-adjacent intercepted route), and its own Tabs/Segment Nav becomes horizontally scrollable. |
| `md` (768–1023px) | Grid moves to 3–4 columns at `Comfortable` density; the Folder Tree rail remains a `Sheet` (tablet does not get an intermediate narrow-rail state, mirroring `docs/frontend/RESPONSIVE_DESIGN.md → Pattern 2`'s own stated tablet-usability finding for the primary Sidebar, applied identically here); List mode becomes available and usable since the viewport comfortably fits its columns. |
| `lg` (1024–1279px) | The Folder Tree rail docks permanently at `3/12`; the Preview's intercepted-route `Sheet` widens toward the Detail Page Template's usual `8/4` split. |
| `xl`+ (≥1280px) | Full desktop layout: 5–6 grid columns at `Comfortable` density (7–8 at `Compact`), the Preview `Sheet`/Dialog reaches its full `8/4` Main Column/Summary Rail split, and the platform-wide `360px` AI Rail companion panel may dock independently alongside it, per the same non-competing relationship `docs/frontend/DASHBOARD.md` and `docs/frontend/SEARCH.md` both already document between a screen's own right-hand content and the global AI Rail. |

`DocumentGrid` sizes its own columns via a CSS Grid `auto-fill`/`minmax()` pattern keyed to the current density token rather than a fixed per-breakpoint column count, so the exact same component code produces the correct column count at any viewport width between the named tiers, not only at the tier boundaries — the same container-query-driven resilience `docs/frontend/SEARCH.md` already established for its own `SearchResultCard`/`SearchResultGroup`. Touch targets on every card's checkbox, every row action, and the Folder Tree's disclosure triangles maintain the platform's 44×44px minimum hit area below `md:`, implemented once in the shared `Checkbox`/`IconButton` components rather than per instance here.

# RTL & Localization

Every user-facing string in this screen — "Upload," "Needs filing," classification labels, the Extracted Data field names, every toast — is a key in both `lib/i18n/en.ts` and `lib/i18n/ar.ts` under a `documents.*` namespace, following the exact `useTranslations(namespace)` convention `docs/frontend/README.md → Internationalization & RTL` establishes, so a key present in one and missing in the other fails `npm run i18n:check` in CI rather than silently falling back at runtime. Classification values reuse `docs/ai/tools/DOCUMENT_TOOLS.md`'s own enum as translation keys (`documents.classification.vendor_invoice`, `.receipt`, …), never a screen-local re-spelling of the same ten values.

Layout mirrors under `dir="rtl"` the same way every other list screen does: the Folder Tree rail sits on the inline-end edge in Arabic rather than the inline-start edge in English (a logical `border-inline-end`/`border-inline-start` pair, never a hardcoded `border-left`/`border-right`), the Breadcrumb strip's chevrons flip via `DirectionalIcon` (`docs/frontend/ACCESSIBILITY.md → RTL Accessibility`), and the Preview's Summary Rail moves to the inline-start side, mirroring the Detail Page Template's own stated RTL behavior. `size_bytes`, `version_count`, dates, and every confidence percentage render through `Intl.NumberFormat`/`Intl.DateTimeFormat` with Western Arabic numerals even under the Arabic locale, per `docs/frontend/README.md`'s platform-wide numeral rule — a "482 KB" caption and a "94%" confidence badge read identically in shape whether the surrounding sentence is English or Arabic.

**File content itself is never mirrored or translated.** A scanned Arabic lease agreement, an English-language vendor invoice, and a bilingual government notice all render in `DocumentPreviewFrame` exactly as authored — the platform's stated rule that "photographic and user-generated content is never recolored" (`docs/frontend/DARK_MODE.md`) extends here to direction as well as color: only the preview frame's own chrome (the Tabs, the Download button, the Summary Rail) mirrors under RTL, never the embedded PDF or image's internal layout, which the browser's native PDF/image renderer displays unmodified regardless of the active application locale. OCR'd text extracted *from* a right-to-left source document is stored and displayed as plain text with its own natural bidirectional runs isolated via `dir="auto"`/Unicode bidi-isolation markers inside `ExtractedDataPanel`'s OCR-text disclosure, matching `docs/frontend/ACCESSIBILITY.md → Bidirectional text isolation`'s existing rule for any mixed-direction field content elsewhere in the platform.

# Dark Mode

Every token this screen uses is inherited, not invented: card surfaces (`--qayd-surface`/`--surface-raised`), borders (`--ink-150`), classification chips (`Badge tone="neutral"`), and the filing-state "Needs filing" chip (`tone="warning"`, per `docs/frontend/DARK_MODE.md`'s four-state finance-status mapping, since an unfiled document is a `warning`-class "needs attention," not a `danger`-class failure) all remap automatically under `:root[data-theme="dark"]` with zero screen-local overrides. Confidence badges and every `AiCardShell` border use `--ai-accent` (violet) in both themes, never the accent-green or the success-green, per `docs/frontend/DARK_MODE.md → AI provenance color`'s explicit rule that confidence is "a statement about the AI's certainty, not about whether an outcome was financially good" — a 94%-confidence classification and a "Needs filing" warning chip on the same card render as two visually distinct signals in dark mode exactly as they do in light.

**Thumbnails and previews never get a dark-mode treatment of their own.** An uploaded photograph, a scanned white-paper invoice, or a PDF rendered inline in `DocumentPreviewFrame` keeps its own original colors and, for a PDF, its own white page background — QAYD's platform-wide "exported PDFs always render light" rule (`docs/frontend/DARK_MODE.md → Images/Logos In Dark`) extends naturally here: a stored PDF is somebody else's document, not a piece of QAYD's own UI, so `filter: invert()` or any other automatic dark-mode recoloring is never applied to the preview frame's embedded content, only to the frame's own chrome (tab bar, toolbar, background behind the document). A generic mime-type glyph (the `FileText`/`FileSpreadsheet`/`FileImage` icons `DocumentCard` falls back to) is drawn from Lucide at the platform's standard icon token color and therefore does remap normally between themes, since those are QAYD's own UI elements, not user content.

# Accessibility

Baseline is WCAG 2.2 AA per `docs/frontend/ACCESSIBILITY.md`, implemented here without deviation; the notes below are this screen's own application of that platform-wide contract, not a competing standard.

- **The Library's List mode is the plain accessible `<table>` pattern, never the ARIA `grid` pattern** — restated here as the definitive rule `# Layout & Regions` and `# Components Used` both already assume. `docs/frontend/ACCESSIBILITY.md → Data Tables Accessibility` reserves the heavier `grid` pattern for "exactly two" platform surfaces (the Journal Entry line editor, the Bank Reconciliation matching grid) and separately names Trial Balance, General Ledger, Journal Entries, Invoices, Bills, Products, and Payroll runs as the plain-table case; this document extends that same named list, additively, to include the Library's List mode — a document is opened, filed, downloaded, and archived, never edited cell-by-cell, so it was never a `grid` candidate in the first place.
- **Grid mode is a list of cards, not a table, and is marked up accordingly.** `DocumentGrid` renders a real `<ul aria-label={t('documents.grid_label')}>` with each `DocumentCard` as an `<li>`; the card's own root keeps the `role="button"` shown in `# Components Used` for its single primary action (open the preview), and the selection `Checkbox` is a second, independently-focusable control inside the same `<li>`, never a click target layered invisibly on top of the card's own button semantics. A card is never given `role="gridcell"`/`role="row"` — the plain-vs-`grid` distinction the platform draws for tables applies one level down here, at the granularity of a card instead of a row.
- **`FolderTree` reuses the platform's one existing tree implementation rather than inventing a second.** `docs/frontend/WAREHOUSES.md`'s `WarehouseStructureTree` is, by that document's own account, "this platform's first ARIA `tree` implementation," and `FolderTree` is built on the identical pattern rather than a variant of it: `role="tree"` on the container, `role="treeitem"` per node, `aria-expanded` on any node with children, `aria-level` reflecting nesting depth, `aria-selected` on the currently-focused node, and the same roving-`tabindex`/arrow-key contract (`→` expands or moves into the first child; `←` collapses or moves to the parent; `↑`/`↓` move between visible nodes only, never into a collapsed node's hidden children; `Home`/`End` jump to the first/last visible node; `Enter`/`Space` activates). The pinned "Needs filing" smart-folder (`# Layout & Regions`) participates in the identical roving sequence as an `aria-level="1"` `treeitem` with no `aria-expanded` (it has no children of its own), rather than existing as a visually-similar sibling element outside the tree's own semantics.
- **A read-only `FolderTree` (a caller lacking `documents.folder.manage`) stays fully navigable.** Removing "New folder," rename, and drag-to-re-parent removes three specific actions, never the tree's own keyboard operability — a caller who can only browse still gets the complete arrow-key/roving-tabindex contract above, per the same "hide the action, never the navigation" discipline `docs/frontend/ACCESSIBILITY.md` applies everywhere a permission narrows a control's write behavior without narrowing what a user can see.
- **The upload drop zone satisfies WCAG 2.5.7 (Dragging Movements) by construction.** `DocumentUploader`'s root (`# Components Used`) is a real `role="button" tabIndex={0}` element whose `Enter`/`Space` handler opens the native file picker — the non-drag equivalent 2.5.7 requires is not a bolted-on afterthought, it is the same control a mouse user drags onto. The Library's own whole-surface drop target (`# Interactions & Flows` — "the whole Grid/List surface is a valid drop target") is additive convenience layered on top of that button, never a replacement for it, exactly as `# Interactions & Flows` already states for the Folder Tree's own drag-to-re-parent ("every drag interaction has an equivalent keyboard/menu path").
- **Upload progress is announced at its two meaningful edges, never per percentage tick.** A per-file `UploadTask` announces once when it begins ("Uploading ACME-Supplies-INV-2026-0715.pdf…") and once when it settles ("Uploaded" or "Couldn't upload — {reason}"), via the identical `aria-busy`-at-start/announce-at-completion discipline `docs/frontend/ACCESSIBILITY.md → ARIA & Semantics → Live regions` specifies for AI streaming output — a progress bar ticking from 12% to 97% is real-time visual feedback for a sighted user, not screen-reader content, and narrating every increment would be exactly the "noise" that document's own live-region rules exist to prevent.
- **Every AI provenance widget is reused, never re-skinned.** `ConfidenceBadge`, `AiCardShell`, and `ReasoningDisclosure` (`# Components Used`) carry `docs/frontend/ACCESSIBILITY.md → ARIA & Semantics → Custom widget roles`'s exact contract into this screen unchanged: a confidence percentage is always a real, adjacent text node, never a bare visual pill or a progress-bar `width` a screen reader has no way to read; the classification/extraction envelope is `role="article"` labelled by its own heading, exactly as an AI Command Card is elsewhere in the platform.
- **`ExtractedDataPanel`'s field tables are real `<table>` markup, one row per extracted field.** `invoice_fields`/`receipt_fields` render as `<th scope="row">{fieldLabel}</th><td>{value}</td><td><ConfidenceBadge .../></td>`, so a screen reader announces "Grand total, KWD 500.000, 99% confidence" as one coherent, column-labelled unit rather than three disconnected cells — the same discipline `docs/frontend/ACCESSIBILITY.md → Screen Readers → Tables use real table semantics` requires of every financial table in the platform, applied here to an AI-extracted one. Extracted monetary values are tabular-numeral, right-aligned per `docs/frontend/DESIGN_LANGUAGE.md`'s numeral rules, but are never routed through the ledger-specific `AmountCell`/debit-credit treatment (`docs/frontend/ACCESSIBILITY.md → Color & Contrast`) — a receipt's `amount` field is a plain extracted quantity, not a signed ledger fact, and dressing it up as one would misstate what kind of number it is.
- **A third-party document's own internal accessibility is outside this screen's control, so Download is a first-class equal, never a last resort.** `docs/frontend/ACCESSIBILITY.md → Edge Cases → Exported PDFs are a separate, real accessibility surface` binds QAYD to shipping its *own* exports (Reports, financial statements) as tagged, structured PDFs; a vendor's invoice or a scanned lease agreement uploaded into this screen was never QAYD's own output, and this document cannot retroactively tag a PDF QAYD did not produce. `DocumentPreviewFrame`'s "Couldn't load this file's preview — Download instead" state (`# States`) is therefore rendered with exactly the same visual and keyboard weight as a successful inline preview, not a dimmed fallback, because for an untagged or malformed source file, downloading and opening it in the user's own, already-configured assistive-technology-aware PDF reader is frequently the *more* accessible path, not a degraded one.
- **Keyboard shortcuts extend, rather than duplicate, the platform's global contract.** `N` opens the upload flow on this route, the identical context-aware pattern `docs/frontend/ACCESSIBILITY.md → Keyboard Navigation`'s global shortcut table already documents for "New Journal Entry on the Journal Entries route, New Invoice on Sales, etc."; the global `/` shortcut focuses the Filter Bar's search input from anywhere on the page, and, once List mode's dense table is on screen, the identical input additionally answers `Cmd/Ctrl+F`, matching `docs/frontend/DESIGN_LANGUAGE.md → Virtualization & interaction`'s table-scoped convention — both bindings resolve to the one shared search input, never two competing implementations. Arrow keys are never repurposed for cell-to-cell navigation on this screen, because — restated plainly here — Document Center is not one of the platform's two ARIA-`grid` surfaces; in List mode they scroll the page exactly as a user expects, and in Grid mode they move native document focus between links/buttons in DOM order, not a custom roving scheme.
- **RBAC-denial and business-rule-denial are never the same disabled state.** The Archive/Delete action disabled for a missing `documents.delete` permission carries `aria-describedby` text naming the permission ("Requires `documents.delete`"); the identical button disabled instead because an active legal hold is in effect (`# Route & Access`, `# Edge Cases`) carries entirely different text ("This document is under an active legal hold and cannot be archived or deleted") — conflating a permission gap with a compliance restriction into one generic "you can't do this" string is the exact P1 defect `docs/frontend/ACCESSIBILITY.md → RBAC-aware disabled controls must explain themselves` calls out by name, and this screen draws the same distinction its own sibling screens already do for their own business-rule-disabled controls.
- **Bulk selection announces its own state, in both Data Region modes.** The List's header checkbox and the Grid's own "select all visible" control are both real, tri-state (`aria-checked="mixed"`) checkboxes per `docs/frontend/ACCESSIBILITY.md → Data Tables Accessibility → Expandable rows and bulk selection`, and the Bulk Action Bar's appearance is itself announced (`role="status"`, "6 selected — Move, Download, Archive, Export, or Clear") rather than only sliding into view visually — the identical announcement-on-appearance rule the cited section already states for any bulk toolbar, applied here to a card grid's own selection model as much as to a table's.
- **Testing.** `/documents` and `/documents/{id}` are carried in the platform's standard four-permutation automated sweep (`en`/light, `en`/dark, `ar`/light, `ar`/dark — `docs/frontend/ACCESSIBILITY.md → Testing`) with zero serious/critical `axe` violations as a merge-blocking gate. The manual pass this screen adds to the platform's own release checklist: filing an inbox item end to end using only a keyboard (open Needs filing → open a card → tab to `AttachablePicker` → search and confirm a target, with no mouse at any step); a screen-reader pass specifically over the Extracted Data tab's field table, since it is this screen's own densest, most novel piece of markup; and an `ar`/RTL pass over `FolderTree` confirming the rail's inline-end position, the breadcrumb's mirrored chevrons, and that the tree's own arrow-key semantics still map to visually-correct movement per `docs/frontend/ACCESSIBILITY.md → RTL Accessibility`.

# Performance

- **Two different virtualization thresholds, for two different item weights, not one platform-wide number.** `DocumentGrid` virtualizes above ~60 visible cards (`# Layout & Regions`) while List mode follows the platform's ordinary ~200-row threshold (`docs/frontend/DESIGN_LANGUAGE.md → Data Density → Virtualization & interaction`); the two numbers differ deliberately, not inconsistently — a `DocumentCard` mounts a real signed-URL `<img>`, a checkbox, two badges, and an `ExtractionStatusPill`, which is meaningfully heavier per DOM node than a text-and-`AmountCell` table row, so the point at which un-virtualized rendering starts costing real frame time arrives sooner for cards than for rows.
- **List responses never carry a signed URL, and this is a performance decision as much as a data-shape one.** `# Data & State` already states that `list_attachments`/`GET /api/v1/documents` omits a per-row `signed_url` by design; the reason stated here is that minting a signed URL is a real, non-free R2/crypto operation server-side, and doing it for every one of 24–100 rows on a page a user may scroll past without opening a single file would be pure wasted work at scale. Only `get_attachment` — called once, for one file, the moment a user actually opens it — ever mints one, which keeps the Library's own list-fetch cost flat regardless of how many of its rows anyone ever actually opens.
- **Uploads bypass the Next.js server entirely for the one part of the request that is actually large.** `DocumentUploader`'s three-call sequence (`# Components Used`) sends a small JSON request to get a presigned URL, `PUT`s the file's raw bytes directly from the browser to Cloudflare R2, and only then sends a second small JSON request to finalize the row — the file's own bytes never transit the Next.js Route Handler proxy or a Laravel request body limit, which is exactly why this screen always takes the presigned-URL branch regardless of file size rather than ever inlining `base64` the way an AI agent's own small-file call (`docs/ai/tools/DOCUMENT_TOOLS.md → upload_document`) legitimately does. `maxConcurrent` (default 3, `# Components Used`) caps how many of these three-call sequences run at once for a large multi-file drop, protecting both the user's own upload bandwidth and the browser's concurrent-connection ceiling from a 40-file drag-and-drop saturating either.
- **Thumbnails intentionally opt out of Next's Image Optimization pipeline.** `DocumentCard`'s `<Image ... unoptimized />` (`# Components Used`) is a deliberate setting, not a missed optimization: Next's remote-image optimizer assumes a stable, repeatedly-fetchable source URL it can cache and re-serve at multiple sizes, and a `signed_url` expires in 15 minutes (`docs/ai/tools/DOCUMENT_TOOLS.md`) — optimizing and caching a URL that will itself return `403` within the hour would only add latency and a guaranteed future cache miss, not remove one. The browser's own single, uncached fetch of the full-resolution image, downscaled by `object-cover` at render size, is the cheaper and simpler correct choice here.
- **`DocumentPreviewFrame` ships zero additional JavaScript for PDF rendering, by design, and deliberately unlike Invoices' own PDF viewer.** `docs/frontend/FRONTEND_ARCHITECTURE.md → Rendering Strategy → Code splitting` `next/dynamic`-splits Invoices' own PDF viewer because that surface renders a QAYD-generated statement and needs pixel-exact, canvas-level control over its own layout; this screen's Preview instead points a plain `<iframe>`/`<object>` at the signed URL and lets the visiting browser's own native PDF renderer (present in every evergreen desktop and most mobile browsers) do the work, because the file being previewed is an arbitrary third-party document, not something QAYD authored and needs to control the exact rendering of. No `pdf.js`-equivalent bundle ships to this route at all.
- **Search is debounced specifically to protect the platform's single most expensive query shape from firing on every keystroke.** The Filter Bar's `q` input debounces at 250ms (`# Interactions & Flows`) before calling `GET /api/v1/documents/search`, which — per `docs/frontend/SEARCH.md → Performance`'s own framing of the identical underlying index — combines a literal and a `pgvector` semantic pass, "the single most expensive query shape" that document names platform-wide; debouncing here is not a generic input-hygiene habit, it is specifically what keeps a five-character search term from firing five expensive combined queries in immediate succession.
- **Reference-grade data is cached generously, transactional data is not, per the platform's own tiering, not a screen-local guess.** `# Data & State`'s cache-tuning table already assigns the Library list/search a 30-second `staleTime` (the "transactional list" tier `docs/frontend/FRONTEND_ARCHITECTURE.md` also assigns `journal-entries`/`invoices`) and the Folder Tree/Versions a 5-minute tier (the same tier `accounts`/`cost-centers` get) — restated here only to name the performance consequence directly: a user re-opening the Library minutes apart sees an instantly-warm Folder Tree and a freshly-checked file list, never the reverse.
- **`FolderTree` loads its own levels lazily, never the whole company's tree in one payload.** `GET /api/v1/documents/folders` takes a `parentId` (`# Data & State`) and is called again only when a node is actually expanded — the identical lazy-per-level loading `docs/frontend/WAREHOUSES.md`'s own `WarehouseStructureTree` already establishes for a comparably-shaped nested structure — a company with a deep, wide folder hierarchy never pays for the branches a user never opens.
- **Extraction calls are synchronous and therefore carry a client-side ceiling of their own.** `# AI Integration` and `# Data & State` both note that `ocr_document`/`classify_document`/`extract_invoice_fields`/`extract_receipt_fields` are ordinary request/response calls with no queued job behind them, typically resolving in low single-digit seconds per `docs/ai/tools/DOCUMENT_TOOLS.md`'s own worked `processing_ms` figures — but "typically" is not "always" for an unusually large multi-page scan, so the triggering button's own mutation carries a generous 30-second `AbortController` ceiling rather than waiting indefinitely; a call that exceeds it surfaces the same local `'failed'` state (`# States`) with an immediate "Retry," so a genuinely slow-but-eventually-successful call is never left indistinguishable from a truly stuck one.
- **Bulk actions are one request, not N, at any selection size.** `POST /api/v1/documents/bulk/move` / `/archive` / `/export` (`# Data & State`) takes the full selected id list in one call and reports per-item results in one response — selecting 40 cards and archiving them fires one request, never 40, matching the identical bulk-endpoint shape `docs/accounting/SALES.md` and `docs/frontend/INVOICES.md` both already establish for their own bulk actions.
- **Web Vitals are tracked against a company-size-aware baseline, not one global number.** LCP for `/documents` is measured against the first meaningful paint of the Grid/List Data Region (or the empty-state's paint, for a brand-new company), tagged by document-count band exactly as `docs/frontend/DASHBOARD.md` and `docs/frontend/SEARCH.md` both tag their own routes by company-size band — a holding company with tens of thousands of stored files and a brand-new company with none are expected to have different tail latencies for reasons that have nothing to do with a genuine regression, and conflating the two into one target would mask real regressions at either end.

# Edge Cases

| Case | Handling |
|---|---|
| A bulk Archive/Delete selection includes a document under an active legal hold | The bulk endpoint's own per-item `data.results[i]` reporting (`# Interactions & Flows`) surfaces it as a named partial failure — "5 archived, 1 failed — Diyar-Lease.pdf is under legal hold" — the identical shape already established for a partial Move failure, never a single all-or-nothing rejection of the whole batch. |
| Two files with byte-identical content are uploaded against different targets | `upload_document`'s checksum-based dedupe (`docs/ai/tools/DOCUMENT_TOOLS.md`) returns the pre-existing attachment rather than creating a second row; `DocumentUploader`'s success handler recognizes `is_duplicate: true` and shows the informational (non-`ConfidenceBadge`, non-`AiCardShell` — `# AI Integration`) "Already on file" toast rather than a generic success message, and does not silently re-parent the existing file onto the new target on the caller's behalf — a duplicate at a second location is disclosed, never auto-filed. |
| A folder is deleted while a user is currently browsing it (`?folder=6`) | Per `docs/database/ERD.md`'s own delete rule for `document_folders`, the folder's children move up one level and its contained attachments' `document_folder_id` becomes `null` — never deleted. The Library's own realtime channel (`# Data & State`) invalidates the stale `?folder=6` view; a fresh navigation to that same URL resolves to the root with a toast ("This folder no longer exists") rather than a broken or infinitely-loading breadcrumb. |
| Moving a folder into one of its own descendants | `docs/database/ERD.md`'s own `CHECK (id <> parent_folder_id)` only prevents a folder from becoming its own *direct* parent — it does not stop a deeper cycle (A becomes a child of B, which is already a child of A) constructed across two separate moves. `FolderTree`'s own move-target picker computes the full descendant subtree of the folder being moved, client-side, from the already-loaded tree, and excludes every one of those nodes as a selectable target — a cycle is therefore never constructible through this screen, even though the database constraint alone would not have stopped one entered through a different path. |
| An extraction call returns `DOCUMENT_UNREADABLE` or `EXTRACTION_FAILED` (`docs/ai/tools/DOCUMENT_TOOLS.md → Error Handling`) | `deriveExtractionStatus` (`# States`) resolves to its local, session-only `'failed'` value; the Extracted Data tab shows the API's own `message` verbatim ("This file could not be read — it may be corrupt or password-protected") alongside an immediate "Retry" that re-issues the identical call, never a generic "something went wrong." |
| A signed URL backing an open Preview or an in-progress Download expires mid-session (past its 15-minute `signed_url_expires_at`) | `# Interactions & Flows` already states Download always re-requests a fresh signed URL immediately before use rather than reusing whatever the Preview loaded with; a long-idle Preview tab's inline `<img>`/`<iframe>` source that has gone stale is refreshed the same way the moment the user interacts with the tab again (switching back to it, or clicking Download), so an hours-old open browser tab never presents a broken-image icon as though the file itself were missing. |
| A permission that gated a visible `attachable_href` is revoked mid-session | The Library's own cached list still shows the row and its link for up to its 30-second `staleTime` (`# Data & State`), or longer if the user simply never triggers a refetch; clicking the now-stale link is not intercepted or specially handled by this screen — it resolves to the destination record's own `403`/`404` boundary, the identical resolution `docs/frontend/SEARCH.md`'s own equivalent edge case already specifies for a stale citation click. |
| Two agents or users call `attach_document` on the same attachment toward two different targets nearly simultaneously | The loser receives `409 CONCURRENT_MODIFICATION` (`docs/ai/tools/DOCUMENT_TOOLS.md`); `useAttachDocument`'s `onError` surfaces "This document was just filed elsewhere — reload to see where" and invalidates the attachment's own detail query rather than silently retrying the stale re-parent against assumptions that are no longer true. |
| A document is archived or deleted by a second user while a first user's Preview is already open on it | The next action that user takes against it (Download, Move, a further extraction call) receives the API's own `404`/`409`; the Preview surfaces an inline "This document is no longer available" banner in place of the failed action's own result, rather than a raw error toast or a silent no-op, and offers "Back to Documents" as the only remaining affordance. |
| A mobile browser renders an embedded PDF unreliably or not at all inside `DocumentPreviewFrame`'s `<iframe>` (a known limitation of some in-app and older mobile browser PDF viewers) | After a short render-timeout heuristic with no successful paint signal from the frame, `DocumentPreviewFrame` falls back to the identical "Couldn't load this file's preview — Download instead" state `# States` already defines for a genuine load failure — from the user's perspective, an unreliable in-frame renderer and an actually-corrupt file resolve to the same graceful, fully-functional-Download outcome. |
| Uploading against a record that is deleted by another user in the moment between opening the uploader and the finalize call completing | The finalize `POST /api/v1/documents` receives `404`/`INVALID_ATTACHABLE_TYPE` (`docs/ai/tools/DOCUMENT_TOOLS.md`); that file's own placeholder card converts to its own per-file error state (`# States`) naming the failure, and is never silently redirected to upload against `'inbox'` instead — a target that vanished out from under an upload is surfaced, not quietly worked around. |
| `classify_document` returns a type outside the caller's own `candidate_types` hint, or below the confidence a reviewer would trust | Per `docs/ai/tools/DOCUMENT_TOOLS.md`'s own stated behavior, the tool always returns its actual best classification rather than forcing a match within a caller's restriction; this screen renders exactly that returned type and gates the extraction actions on it (`# Route & Access`), never on the caller's original guess, and a low `extraction_confidence` de-emphasizes (never hides) the resulting hand-off button per `# AI Integration`'s stated confidence-floor treatment. |
| Two files with identical, human-chosen file names land in the same folder (different content, different checksum, so no dedupe applies) | Both persist as independent `attachments` rows and both render as separate cards/rows with the identical visible `file_name` — `DocumentCard`/the List's own file-name column make no attempt to disambiguate them, and a user must rely on the adjacent Uploaded-by/Uploaded-at metadata (always rendered alongside the name, never hidden behind a click) to tell them apart. |
| A brand-new company with zero attachments and zero folders | Distinct from the filtered-to-zero empty state (`# States`): the Library's true root-empty state pairs "No documents yet — upload your first file" with the `DocumentUploader` drop zone inline, and the Folder Tree shows only "All Documents" and "Needs filing" with no "create your first folder" prompt unless the caller holds `documents.folder.manage` — an onboarding company is never shown a call-to-action for a permission it does not have. |
| A very long, or bilingual, file name overflows a `DocumentCard`'s title line or the List's file-name column | The name truncates (`truncate`) in both Data Region modes and exposes its full, untruncated form via the same `title`/`Tooltip` pattern `docs/frontend/SEARCH.md`'s own result cards use for an overlong customer or vendor legal name — never a silently clipped name with no way to read the rest of it. |

# End of Document

