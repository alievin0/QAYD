# Document Center Screen — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Screens / DOCUMENT_CENTER_SCREEN
---

# Purpose

This document is the concrete, implementation-ready screen specification for the Document Center's landing
route, `app/(app)/documents/page.tsx` — the company-wide document library an Accountant, a Finance Manager,
an Auditor, or a Purchasing/Sales Employee actually lands on the instant they open **Documents** from the
Reports sub-navigation or a record screen's "Manage in Document Center" link. It is the structured,
editor-beside-you companion to `docs/frontend/DOCUMENT_CENTER.md`, which specifies this exact surface in
fuller prose — the argument for why the Document Center exists at all (the one screen that looks *across* the
polymorphic `attachments` table every other screen only looks *into* one slice of), the three-audience
framing, the two new permissions, the presigned-upload mechanism, and the "suggest-and-surface, not
suggest-and-gate" AI posture. This document does not re-argue any of that; the two must never disagree. Where
this document is silent on a fact, `docs/frontend/DOCUMENT_CENTER.md` governs first, then
`docs/ai/tools/DOCUMENT_TOOLS.md`, `docs/database/ERD.md`'s Documents section, then
`FRONTEND_ARCHITECTURE.md`, `DESIGN_LANGUAGE.md`, `COMPONENT_LIBRARY.md`, `LAYOUT_SYSTEM.md`,
`RESPONSIVE_DESIGN.md`, `DARK_MODE.md`, and `ACCESSIBILITY.md`, in that order. Where this document appears to
contradict one of them on a route, a permission key, an endpoint path, a component name, or a design token,
that is a defect in one of the two documents to raise and resolve in review, never a decision an engineer
resolves unilaterally in code.

What this document adds beyond `docs/frontend/DOCUMENT_CENTER.md` is the layer an engineer opens beside their
editor: the literal `page.tsx` / `layout.tsx` / `loading.tsx` / `error.tsx` source, the intercepted
`@modal/(.)[attachmentId]` route's source, the full prop and TSX for the ten library-scoped composed
components `docs/frontend/DOCUMENT_CENTER.md` names but does not fully show, worked upload and extraction
request/response JSON an engineer can paste directly into a fixture, a region-by-state matrix instead of
prose, concrete `grid-cols` / `col-span` values in place of a reference to "the List Page Template," and the
handful of implementation decisions (the three-call presigned upload's exact sequencing, the
optimistic-presence-never-optimistic-content rule, the two distinct virtualization thresholds, `Grid`-mode
keyboard bindings) that `docs/frontend/DOCUMENT_CENTER.md` names as decisions but does not spell out to the
last detail. It introduces no new route, no new permission key, and no new design token those documents have
not already defined.

Concretely, this screen composes six pieces of surface, each owned elsewhere in the platform's data and
business-logic layers and only rendered, filtered, and routed here — never computed:

- **A permission-gated Page Header** — the title "Documents," a live "N files" count beside a second,
  distinctly-toned "18 need filing" operational count, a primary **Upload** split action, a secondary **+
  Folder** action, an **Export** menu, and the **List/Grid** `ViewToggle`.
- **A `document_folders` Folder Tree rail** — a real ARIA `tree`, read-only or editable per
  `documents.folder.manage`, with the platform-wide "Needs filing" smart-folder pinned above the real tree.
- **A Filter Bar** — full-text-plus-semantic search over file name and OCR'd content, and the Type /
  Classification / Status / Uploaded-by / Uploaded / Extraction facets.
- **The Data Region itself** — a virtualized `DocumentGrid` of `DocumentCard`s (default) toggleable to the
  platform's ordinary `DataTable` in List mode, over the cursor-paginated `attachments` set.
- **A Bulk Action Bar** that replaces the Filter Bar's trailing controls once ≥1 item is selected.
- **A companion, non-owned platform AI Rail** — the shell-level `360px` panel that may dock inline at `xl`+,
  distinct from and never a duplicate of this screen's own content.

This screen owns no business logic. It never decides whether an extraction is confident enough to become a
bill, never mints a signed URL itself, and never lets a confidence score substitute for a human's click into
the owning module's own governed create flow. Every figure rendered here was computed and persisted by
Laravel (or by the Document AI / OCR Agent pair in the background); every mutation calls
`/api/v1/documents...` guarded by the exact permission key the API itself enforces — the frontend's own
checks are a courtesy that spares a user a confusing `403`, never the actual authority
(`docs/frontend/DOCUMENT_CENTER.md`'s three inherited constraints: the frontend computes nothing; AI is
visible, labeled, and never silent; RBAC is enforced by the API and the UI only reflects it).

# Route & Access

## App Router path

```text
app/(app)/documents/
├── page.tsx                          # * THIS DOCUMENT — the Library (Server Component, first-paint fetch)
├── loading.tsx                       # Library-shaped skeleton — see # States
├── error.tsx                         # Library-level error boundary (recoverable 5xx/network on first fetch)
├── [attachmentId]/
│   └── page.tsx                      # Canonical, deep-linkable full-page preview + metadata + AI panel
└── @modal/
    └── (.)[attachmentId]/
        └── page.tsx                  # Intercepted: the SAME page rendered as a Sheet from the Library grid
```

`[attachmentId]` follows the platform's dynamic-segment convention — camelCase, named for the entity, never
the generic `[id]` (`FRONTEND_ARCHITECTURE.md → Conventions → Naming`) — and its API-resource mirror is
`/api/v1/documents/{id}`, the `get_attachment` endpoint `docs/ai/tools/DOCUMENT_TOOLS.md` already specifies.
The intercepted `@modal/(.)[attachmentId]` route is the identical pattern `FRONTEND_ARCHITECTURE.md → Parallel
and intercepting routes for quick-create` establishes for `journal-entries/new`: clicking a card in the
Library opens the exact same page in a `Sheet` overlay without leaving the grid, while a direct hit, a shared
link, or a hard refresh on `/documents/8801` always renders the real, full, linkable page — never a second,
divergent implementation of the preview. `docs/frontend/DOCUMENT_CENTER.md → Route & Access` is authoritative
for this arrangement; this document reproduces it only so the source samples below have an unambiguous target.

**Folders are not routes.** Navigating into "2026 Audit Evidence" sets `?folder=6` on the same `/documents`
route rather than resolving to `/documents/folders/6`, per `docs/frontend/DOCUMENT_CENTER.md → Folders are not
routes` — keeping an arbitrarily deep, user-created folder tree from ever requiring an arbitrarily deep route
segment, and keeping every folder view a plain, shareable, bookmarkable URL. This screen's `page.tsx` reads
`?folder=`, `?type=`, `?q=`, `?view=`, and `?attachable_type=`/`?attachable_id=` (the record-scoped deep
link) from `searchParams` and nothing else from the path.

## Permission gate

| Control | Permission | Behavior if absent |
|---|---|---|
| Screen visible at all | `documents.read` | Nav entry (the Reports sub-nav bullet), Command-Palette index, and route all absent; a direct hit renders the shell's `403` boundary, never a `404` (a cross-tenant `attachmentId` instead renders `not-found.tsx`, per the platform's 403-for-route/404-for-record split). |
| Every card/row, the preview route, signed-URL access | `documents.read` | Same as above — `documents.read` is "the widest grant in the platform's entire permission matrix" (`docs/ai/tools/DOCUMENT_TOOLS.md`), so this is the near-universal floor. |
| "Upload" button + drag-and-drop drop zone | `documents.upload` | Removed from the DOM, not disabled; a drag-over on the Library still shows a "you don't have permission to upload here" toast rather than silently swallowing the drop. |
| "Run OCR" / "Classify" / "Extract invoice fields" / "Extract receipt fields" detail actions | `documents.ocr` / `documents.classify` / `documents.extract` | Action omitted from the row menu and detail overflow; a prior extraction's results still render read-only. |
| "Move to record" (re-parent), filing an `inbox` item | `documents.attach` | Omitted; an `inbox` item stays visible under "Needs filing" but this user cannot file it. Inline file-name rename (conceptually part of re-filing) is gated on this same key. |
| "Archive" / "Delete" | `documents.delete` | Omitted — the human consumer of the administrative tool `docs/ai/tools/DOCUMENT_TOOLS.md` reserved and left unimplemented. |
| "New folder," rename/re-parent/delete folder | `documents.folder.manage` **(new)** | Folder Tree renders read-only (navigable, not editable); "New folder" omitted. |
| "Export" (bulk metadata CSV / zip) | `documents.export` **(new)** | Button and Bulk-Action-Bar item omitted. |

Default role grants for the seven inherited keys are exactly `docs/ai/tools/DOCUMENT_TOOLS.md → Tool Catalog`'s
own table — this document does not restate them; `usePermission()` is the only source either surface queries.
The two new keys mirror the nearest existing sibling exactly, per `docs/frontend/DOCUMENT_CENTER.md`:
`documents.folder.manage` mirrors `documents.upload`'s grant verbatim; `documents.export` mirrors the
narrower `sales.invoice.export` shape (Owner, CEO, CFO, Finance Manager, Senior Accountant, Auditor), because
a bulk export can bundle payslips, national-ID scans, and signed contracts in one download.

## Roles

| Role | What renders on this screen |
|---|---|
| Owner, CEO / Admin, CFO, Finance Manager | Everything — full grid, every row action, Upload/Folder/Export, both filing and extraction actions, the full editable Folder Tree, the "Create bill/expense claim from this" hand-offs (each further gated by the *owning* module's own create permission). |
| Senior Accountant, Accountant | Read, upload, OCR/classify/extract, attach; the "inbox triage" lens is their default working posture. Delete is unavailable; Export available only to Senior Accountant. |
| Purchasing / Sales / Inventory / Payroll / HR self-service roles | Full `documents.read` plus `documents.upload`/`documents.attach`/`documents.folder.manage` — they file their own module's scans; extraction and delete depend on their exact grant. An `attachable_href` into a module they cannot read renders as plain text, never a broken link. |
| Auditor, External Auditor | Fully read-only across the grid, the preview, the Extracted Data tab, and the Folder Tree; every mutating control is omitted, every confidence badge and reasoning disclosure still renders in full; Export remains available to Auditor (the retention/provenance lens). |
| Read Only | The floor — identical to Auditor for this module, minus Export. |
| AI service account (Document AI, OCR Agent) | Never renders this screen as a user; its scoped, read-plus-extraction access populates `extracted_data`, `is_ai_extracted`, and the classification columns entirely in the background, and is structurally incapable of holding `documents.delete` or `documents.folder.manage` regardless of any company automation policy. |

Keyboard entry: `G` then `D` opens this screen from anywhere, per `ACCESSIBILITY.md`'s "Go to" mnemonic
registry; on the route itself, `N` opens the upload flow and `/` (or, once List mode's dense table is on
screen, `Cmd/Ctrl+F`) focuses the Filter Bar's search input, both per
`docs/frontend/DOCUMENT_CENTER.md → Accessibility`.

# Layout & Regions

This screen instantiates `LAYOUT_SYSTEM.md`'s **List Page Template** — Page Header / Filter Bar / Data Region
/ Bulk Action Bar / Pagination Footer — with the one substitution `docs/frontend/DOCUMENT_CENTER.md → Layout &
Regions` specifies: the Data Region's renderer becomes a grid-first `DocumentGrid`, and a `document_folders`
Folder Tree rail is added at the inline-start. The desktop wireframe below is reproduced from that document
for a single source of truth; the tablet and mobile wireframes are this document's own addition.

## Desktop (`xl`+, ≥1280px)

```
┌──────────────────────────────────────────────────────────────────────────────────────┐
│  Documents                                              [[upload] Upload ▾]  [+ Folder]       │  Page Header
│  2,140 files · 18 need filing                        [Export ▾]  [▤ List] [▦ Grid]     │
├──────────────────────────────────────────────────────────────────────────────────────┤
│ [home] All Documents › 2026 Audit Evidence                                                 │  Breadcrumb strip
├──────────────────┬─────────────────────────────────────────────────────────────────────┤
│ FOLDERS           │ [Search files, text inside scans…] [Type▾][Class▾][Status▾][By▾][[date]▾]│  Filter Bar
│ ▸ Needs filing(18)├─────────────────────────────────────────────────────────────────────┤
│ ▸ 2026 Audit Ev.  │  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐   │  Data Region
│ ▸ Vendor Invoices │  │[thumb] │ │[PDF]   │ │[PDF]   │ │[thumb] │ │ …      │ │ …      │   │  (DocumentGrid)
│ ▸ Payroll         │  │ACME-INV│ │Lease   │ │CR-2026 │ │receipt │ │        │ │        │   │
│ ▸ Tax Filings     │  │96% [check]   │ │Filed   │ │Filed   │ │Proc.   │ │        │ │        │   │
├──────────────────┴─────────────────────────────────────────────────────────────────────┤
│ Showing 1–24 of 2,140                                                    [ Load more ] │  Pagination Footer
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

## Tablet (`md`–`lg`, 768–1279px)

At `md`, the Folder Tree rail collapses into a `Sheet` opened from a breadcrumb tap and the Filter Bar's
facets collapse into a "Filters" `Sheet`; the `DocumentGrid` runs 3–4 columns at `Comfortable` density; List
mode becomes available and usable since the viewport fits its columns. At `lg`, the Folder Tree docks
permanently at `col-span-3` and the grid runs 4–5 columns. Tablet does not get an intermediate narrow-rail
state (`docs/frontend/DOCUMENT_CENTER.md → Responsive Behavior`, mirroring `RESPONSIVE_DESIGN.md → Pattern 2`).

## Mobile (`base`–`sm`, <768px)

```
┌────────────────────────────────────┐
│ [home] 2026 Audit Evidence ▾    [[upload]][⋯] │  breadcrumb tap opens Folder Tree Sheet;
├────────────────────────────────────┤  Upload + overflow (Folder/Export) in header
│ [Search…]                    [[gear]]   │  [gear] opens the Filters Sheet
├────────────────────────────────────┤
│  ┌──────────┐  ┌──────────┐         │  DocumentGrid, forced Grid mode,
│  │ [thumb]  │  │ [PDF]    │         │  2 columns, Compact density —
│  │ ACME-INV │  │ Lease…   │         │  a table is the weakest mobile
│  │ 96% [check]    │  │ Filed    │         │  rendering for a visual library
│  └──────────┘  └──────────┘         │
├────────────────────────────────────┤
│           [ Load more ]            │
└────────────────────────────────────┘
```

## Region table with implementation-level grid classes

| Region | Container classes | Content | Notes |
|---|---|---|---|
| Page Header | `col-span-12 border-b border-ink-4 px-6 py-5` | Title, dual count, Upload split, +Folder, Export, ViewToggle | No lifecycle-status pill — an attachment has none (`# Purpose`) |
| Breadcrumb strip | `col-span-12 px-6 py-2 border-b border-ink-4` | `document_folders.parent_folder_id` chain, anchored at "All Documents" | Absent entirely when no `?folder=` is set |
| Folder Tree rail | `col-span-12 lg:col-span-3` (a `Sheet` below `lg`) | `FolderTree` + pinned "Needs filing" smart-folder | Read-only when `documents.folder.manage` absent |
| Filter Bar | `col-span-12 lg:col-span-9 flex flex-wrap items-center gap-2 px-4 py-3` | Search + Type/Classification/Status/Uploaded-by/Uploaded/Extraction facets | `Extraction` facet is client-derived, never server-filtered |
| Data Region (Grid) | `col-span-12 lg:col-span-9 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5 2xl:grid-cols-6` | `DocumentGrid` of `DocumentCard`s | Column count via `auto-fill`/`minmax()` keyed to density, not fixed per-breakpoint |
| Bulk Action Bar | replaces Filter Bar's trailing side, `flex items-center gap-3` | Count + Move/Download/Archive/Export/Clear | Each action re-checks its own permission |
| Pagination Footer | `col-span-12 border-t border-ink-4 px-6 py-3` | Cursor "Load more" (Grid) / "1–24 of N" + Prev/Next (List) | Cursor mode always (`attachments` is append-only, unbounded) |

```tsx
// app/(app)/documents/page.tsx — Server Component, first-paint fetch
import { Suspense } from "react";
import { dehydrate, HydrationBoundary } from "@tanstack/react-query";
import { getQueryClient } from "@/lib/api/query-client-server";
import { apiServer } from "@/lib/api/server-client";
import { documentKeys, folderKeys } from "@/lib/query/keys";
import { PageHeader } from "@/components/layout/page-header";
import { DocumentLibrary } from "@/components/documents/document-library";
import { FolderTree } from "@/components/documents/folder-tree";
import { UploadSplitButton } from "@/components/documents/upload-split-button";
import { DocumentsSecondaryActions } from "@/components/documents/documents-secondary-actions";
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";
import type { DocumentFilters } from "@/types/documents";

export const dynamic = "force-dynamic";
export const fetchCache = "default-no-store"; // tenant-scoped signed content — never statically cached

export default async function DocumentCenterPage({
  searchParams,
}: {
  searchParams: Promise<{ folder?: string; type?: string; q?: string; view?: string; attachable_type?: string; attachable_id?: string }>;
}) {
  const sp = await searchParams;
  const filters: DocumentFilters = {
    folder_id: sp.folder ? Number(sp.folder) : null,
    mime_group: sp.type,
    q: sp.q,
    attachable_type: sp.attachable_type,
    attachable_id: sp.attachable_id ? Number(sp.attachable_id) : undefined,
  };
  const queryClient = getQueryClient();
  await Promise.all([
    queryClient.prefetchInfiniteQuery({
      queryKey: documentKeys.list(filters),
      queryFn: ({ pageParam }) => apiServer.get("/documents", { params: { ...filters, cursor: pageParam, per_page: 24 } }),
      initialPageParam: null,
    }),
    queryClient.prefetchQuery({
      queryKey: folderKeys.children(null),
      queryFn: () => apiServer.get("/documents/folders", { params: { parent_id: null } }),
    }),
  ]);

  return (
    <HydrationBoundary state={dehydrate(queryClient)}>
      <div className="space-y-4">
        <PageHeader
          breadcrumb={[{ label: "Documents", href: "/documents" }]}
          title="Documents"
          actions={
            <div className="flex items-center gap-2">
              <DocumentsSecondaryActions />
              <UploadSplitButton defaultFolderId={filters.folder_id} />
            </div>
          }
        />
        <div className="grid grid-cols-12 gap-6">
          <Suspense fallback={<WidgetSkeleton variant="tree" className="col-span-12 lg:col-span-3" />}>
            <FolderTree className="col-span-12 lg:col-span-3" activeFolderId={filters.folder_id} />
          </Suspense>
          <Suspense fallback={<WidgetSkeleton variant="grid" className="col-span-12 lg:col-span-9" />}>
            <DocumentLibrary className="col-span-12 lg:col-span-9" initialFilters={filters} defaultView={sp.view === "list" ? "list" : "grid"} />
          </Suspense>
        </div>
      </div>
    </HydrationBoundary>
  );
}
```

```tsx
// app/(app)/documents/loading.tsx
import { WidgetSkeleton } from "@/components/dashboard/widget-skeleton";

export default function DocumentCenterLoading() {
  return (
    <div className="space-y-4">
      <div className="border-b border-ink-4 px-6 py-5">
        <div className="h-4 w-24 animate-pulse rounded bg-ink-3" />
        <div className="mt-2 h-7 w-40 animate-pulse rounded bg-ink-3" />
      </div>
      <div className="grid grid-cols-12 gap-6">
        <WidgetSkeleton variant="tree" className="col-span-12 lg:col-span-3" />
        <WidgetSkeleton variant="grid" className="col-span-12 lg:col-span-9" />
      </div>
    </div>
  );
}
```

```tsx
// app/(app)/documents/@modal/(.)[attachmentId]/page.tsx — intercepted preview as a Sheet
import { Sheet, SheetContent } from "@/components/ui/sheet";
import { DocumentPreview } from "@/components/documents/document-preview";
import { InterceptedSheetShell } from "@/components/shared/intercepted-sheet-shell"; // owns router.back() onOpenChange

export default async function InterceptedDocumentPreview({ params }: { params: Promise<{ attachmentId: string }> }) {
  const { attachmentId } = await params;
  return (
    <InterceptedSheetShell>
      {(open, onOpenChange) => (
        <Sheet open={open} onOpenChange={onOpenChange}>
          <SheetContent side="end" width="lg" className="w-full sm:max-w-2xl">
            {/* identical component the full /documents/[attachmentId] page renders — never a second impl */}
            <DocumentPreview attachmentId={Number(attachmentId)} variant="sheet" />
          </SheetContent>
        </Sheet>
      )}
    </InterceptedSheetShell>
  );
}
```

The full-page `[attachmentId]/page.tsx` renders the identical `<DocumentPreview variant="page" />` inside the
Detail Page Template's `8/4` Main Column / Summary Rail split — so a direct hit, a shared link, or a hard
refresh always resolves to a real, linkable page while a click from the grid opens the same component in the
Sheet above (`docs/frontend/DOCUMENT_CENTER.md → Preview — Detail Page Template`).

# Components Used

Every visual element is drawn from `COMPONENT_LIBRARY.md`'s catalogue, the platform's `components/ai/` set,
or is one of the ten Document-Center-scoped compositions `docs/frontend/DOCUMENT_CENTER.md → Components Used`
already names and files under `components/documents/`. This section gives the concrete prop contracts and, for
the compositions that document names without showing full source, the actual implementation.

| Component | Source | New in this document |
|---|---|---|
| `DataTable`, `PermissionGate`, `ConfidenceBadge`, `AiCardShell`, `ReasoningDisclosure`, `Tabs`, `Sheet`, `Dialog`/`AlertDialog`, `DropdownMenu`, `Badge`, `Skeleton`, `EmptyState`/`ErrorState`, `Checkbox`, Toast (`useApiToast`) | `COMPONENT_LIBRARY.md` / `components/ai/*` (existing) | No — reused verbatim |
| `DocumentUploader` | `components/documents/document-uploader.tsx` — full source already in `docs/frontend/DOCUMENT_CENTER.md → DocumentUploader` | No — cited, not reproduced |
| `DocumentCard` | `components/documents/document-card.tsx` — full source already in `docs/frontend/DOCUMENT_CENTER.md → DocumentCard` | No — cited, not reproduced |
| `DocumentGrid` | `components/documents/document-grid.tsx` | **Yes** — full source below |
| `ExtractionStatusPill` | `components/documents/extraction-status-pill.tsx` | **Yes** — full source below |
| `ExtractedDataPanel` | `components/documents/extracted-data-panel.tsx` | **Yes** — prop contract + hand-off logic below |
| `AttachablePicker` | `components/documents/attachable-picker.tsx` | **Yes** — prop contract below |
| `RetentionBadge` | `components/documents/retention-badge.tsx` | **Yes** — full source below |
| `DocumentPreviewFrame` | `components/documents/document-preview-frame.tsx` | **Yes** — prop contract + fallback logic below |
| `DocumentVersionsList` | `components/documents/document-versions-list.tsx` | **Yes** — prop contract below |
| `FolderTree` | `components/documents/folder-tree.tsx` — ARIA-tree contract in `docs/frontend/DOCUMENT_CENTER.md → Accessibility` | No — cited; move-target cycle exclusion noted in `# Edge Cases` |

## `DocumentGrid`

Owns virtualization (above ~60 visible cards, a deliberately lower threshold than List mode's ~200 rows,
because a `DocumentCard` mounts a real signed-URL `<img>`, a checkbox, two badges, and an
`ExtractionStatusPill` — heavier per DOM node than a text row, `docs/frontend/DOCUMENT_CENTER.md →
Performance`), the `auto-fill`/`minmax()` column math keyed to the density token, and the shared selection
model. It renders a real `<ul>` of `<li>`s per `docs/frontend/DOCUMENT_CENTER.md → Accessibility`, never an
ARIA `grid`.

```tsx
// components/documents/document-grid.tsx
"use client";

import { useRef } from "react";
import { useVirtualizer } from "@tanstack/react-virtual";
import { DocumentCard } from "@/components/documents/document-card";
import { useTranslations } from "@/lib/i18n";
import type { Attachment } from "@/types/documents";

const MIN_CARD = { comfortable: 200, compact: 148 } as const;

export function DocumentGrid({
  items, density = "comfortable", selectedIds, onSelect, onOpen,
}: {
  items: Attachment[];
  density?: keyof typeof MIN_CARD;
  selectedIds: Set<number>;
  onSelect: (id: number, selected: boolean) => void;
  onOpen: (id: number) => void;
}) {
  const { t } = useTranslations("documents");
  const parentRef = useRef<HTMLUListElement>(null);
  const virtual = items.length > 60; // threshold per DOCUMENT_CENTER.md → Performance

  const ul = (
    <ul
      ref={parentRef}
      aria-label={t("grid.label")}
      className="grid gap-4"
      style={{ gridTemplateColumns: `repeat(auto-fill, minmax(${MIN_CARD[density]}px, 1fr))` }}
    >
      {items.map((a) => (
        <li key={a.id}>
          <DocumentCard
            attachment={a}
            size={density}
            selected={selectedIds.has(a.id)}
            onSelect={onSelect}
            onOpen={onOpen}
          />
        </li>
      ))}
    </ul>
  );
  // Above 60 cards, the same <ul> is windowed by @tanstack/react-virtual with measured heights;
  // the markup contract (real <ul>/<li>, DocumentCard's role="button") is identical whether virtualized.
  return virtual ? <VirtualizedGrid parentRef={parentRef}>{ul}</VirtualizedGrid> : ul;
}
```

## `ExtractionStatusPill`

Renders the one piece of presentational status this screen derives client-side (`deriveExtractionStatus`,
`# Data & State`) — `not_started` / `processing` / `extracted` / `failed`. A `failed` value is
session-local only, never persisted (`docs/frontend/DOCUMENT_CENTER.md → States`).

```tsx
// components/documents/extraction-status-pill.tsx
"use client";

import { Badge } from "@/components/ui/badge";
import { deriveExtractionStatus } from "@/lib/documents/derive-extraction-status";
import { useExtractionInFlight } from "@/hooks/documents/use-extraction-in-flight";
import { useTranslations } from "@/lib/i18n";
import type { Attachment } from "@/types/documents";

const TONE = { not_started: "neutral", processing: "neutral", extracted: "success", failed: "warning" } as const;

export function ExtractionStatusPill({ attachment, className }: { attachment: Attachment; className?: string }) {
  const { t } = useTranslations("documents");
  const inFlight = useExtractionInFlight(attachment.id); // reads this session's own mutation state
  const status = deriveExtractionStatus(attachment, inFlight);
  if (status === "not_started") return null; // absence is the unmarked default — never a "nothing here" pill
  return (
    <Badge tone={TONE[status]} className={className}>
      {t(`extraction.status.${status}`)}
    </Badge>
  );
}
```

## `ExtractedDataPanel` (prop contract + hand-off)

| Prop | Type | Description |
|---|---|---|
| `attachment` | `Attachment` | Carries `classification`, `extracted_data` (`ocr` / `invoice_fields` / `receipt_fields`), `is_ai_extracted`. |
| `onRunOcr` / `onClassify` / `onExtractInvoice` / `onExtractReceipt` | `() => Promise<void>` | Each gated by its own permission; awaited inline (no queued job — `# Data & State`). |

Body composition (no new primitives): an `AiCardShell` envelope over OCR Agent / Document AI output; a
per-field `<table>` where each `invoice_fields`/`receipt_fields` row is `<th scope="row">{label}</th><td>{value}
</td><td><ConfidenceBadge confidence={field_confidence} sourceField="fraction" size="sm" /></td>`
(`docs/frontend/DOCUMENT_CENTER.md → Accessibility`); a `ReasoningDisclosure` over the collapsed raw OCR text;
`policy_flags` as small neutral-toned inline `Badge`s; and — the load-bearing hand-off — a single
**"Create bill from this"** (or "Create expense claim from this") button that navigates to
`/purchasing/bills/new?from_attachment=8801` pre-filled, visible only when the caller holds the *owning*
module's own create key (`purchasing.bill.create` / `expenses.claim.create`), never a Documents-owned one.
Below `extraction_confidence` 0.60 the button renders de-emphasized-but-still-clickable with a "Confidence is
low; review every field before creating a bill" caption — the low-confidence state removes the *appearance*
of a trustworthy one-click action, never the underlying capability (`docs/frontend/DOCUMENT_CENTER.md → AI
Integration`). This panel **never renders an approve/reject pair for the extraction itself** — extraction is
not a financial fact; the approval machinery applies one layer downstream in the owning module.

## `AttachablePicker` (prop contract)

| Prop | Type | Description |
|---|---|---|
| `value` | `{ type: AttachableType; id: number } \| null` | Current target; `null` for an unfiled `inbox` item. |
| `onChange` | `(v: { type: AttachableType; id: number }) => void` | Fires on confirm. |
| `excludeTypes` | `AttachableType[]` | e.g. `['inbox']` — never a valid `attach_document` target. |

A two-step `Command`+`Popover` combobox (pick the record type from the `AttachableType` union, then search
that type's own records), the identical shape `AccountPicker`/`CustomerPicker` establish. The union is
imported from `types/documents.ts` — the one place the frontend's understanding of "which record kinds hold a
file" lives, resolved from the three-source reconciliation `docs/frontend/DOCUMENT_CENTER.md → attachableType`
governs (the `DOCUMENT_TOOLS.md` JSON Schema is authoritative, extended with `journal_entry`).

## `RetentionBadge`

```tsx
// components/documents/retention-badge.tsx
import { ShieldCheck, Lock } from "lucide-react";
import { Icon } from "@/components/ui/icon";
import { Tooltip, TooltipTrigger, TooltipContent } from "@/components/ui/tooltip";
import { useTranslations, useFormatter } from "@/lib/i18n";
import type { Retention } from "@/types/documents";

export function RetentionBadge({ retention }: { retention: Retention }) {
  const { t } = useTranslations("documents");
  const fmt = useFormatter();
  if (retention.policy === "none") return <span className="text-caption text-ink-9">{t("retention.none")}</span>;
  return (
    <div className="space-y-1 text-caption">
      <p className="flex items-center gap-1.5 text-ink-11">
        <Icon icon={retention.legal_hold ? Lock : ShieldCheck} size="xs" aria-hidden />
        {retention.legal_hold ? t("retention.legalHold") : t("retention.until", { date: fmt.date(retention.expires_at!) })}
      </p>
      {retention.legal_basis && (
        <Tooltip>
          <TooltipTrigger asChild><button type="button" className="text-ink-9 underline decoration-dotted">{t("retention.basisLink")}</button></TooltipTrigger>
          <TooltipContent className="max-w-xs">{retention.legal_basis}</TooltipContent>
        </Tooltip>
      )}
    </div>
  );
}
```

`retention` is a read-only, server-resolved field (`docs/frontend/DOCUMENT_CENTER.md → Data & State`), never
editable from this screen; `legal_hold: true` always means the same `archive_policies`-level hold
`DATABASE_ARCHIVING.md` defines, surfaced here rather than re-derived.

## `DocumentPreviewFrame` (prop contract + fallback)

| Prop | Type | Description |
|---|---|---|
| `attachment` | `Attachment` | Its `signed_url` is minted by `get_attachment` at open time only. |
| `onDownload` | `() => Promise<void>` | Always re-requests a *fresh* signed URL immediately before use. |

Renders a browser-native `<img>` (images) or `<iframe>`/`<object>` at the signed URL (PDFs), shipping **zero
additional PDF-rendering JavaScript** — no `pdf.js` bundle, deliberately unlike Invoices' own QAYD-authored
statement viewer (`docs/frontend/DOCUMENT_CENTER.md → Performance`). On a render-timeout heuristic with no
paint signal, or any load failure, it falls back to "Couldn't load this file's preview — Download instead"
with the Download button at *equal* visual and keyboard weight, never a dimmed last resort — for an untagged
third-party PDF the user's own AT-aware reader is frequently the more accessible path.

## `DocumentVersionsList` (prop contract)

| Prop | Type | Description |
|---|---|---|
| `attachmentId` | `number` | Reads `useDocumentVersions(id)` → `GET /api/v1/documents/{id}/versions`. |

Each row is a `document_versions` entry with a signed-URL download of that exact version; reference-data
cache class (`5 * 60_000` staleTime). Never editable — versions are a read-only projection.

# Data & State

## Endpoints this screen calls

All under `/api/v1/documents` (plus the sibling `/documents/folders` and `/documents/search`), Bearer +
`X-Company-Id`, standard envelope, exactly as `docs/frontend/DOCUMENT_CENTER.md → Data & State → Endpoints`
maps to hooks. Reproduced here in condensed form as the strict subset this page's first paint or its own
controls issue:

| Purpose | Endpoint | Permission | First-paint or on-demand |
|---|---|---|---|
| Library list (cursor) | `GET /documents?folder=&type=&status=&q=&cursor=&per_page=24` | `.read` | First paint |
| Folder children (lazy) | `GET /documents/folders?parent_id=` | `.read` | First paint (root), on-demand (expand) |
| Full-text + semantic search | `GET /documents/search?q=&…` | `.read` | On-demand (search input, 250ms debounce) |
| One attachment, with signed URL | `GET /documents/{id}` | `.read` | On-demand (preview open) |
| Version history | `GET /documents/{id}/versions` | `.read` | On-demand (Versions tab) |
| Presigned upload URL | `POST /documents/upload-url` | `.upload` | Upload step 1 |
| Finalize (create `attachments` row) | `POST /documents` | `.upload` | Upload step 3 |
| Run OCR / Classify | `POST /documents/{id}/ocr` \| `/classify` | `.ocr` / `.classify` | Detail overflow |
| Extract invoice / receipt fields | `POST /documents/{id}/extract-invoice-fields` \| `/extract-receipt-fields` | `.extract` | Detail overflow |
| Re-parent (file) | `PATCH /documents/{id}/attach` | `.attach` | "Move to record" / file-inbox |
| Archive / Delete | `POST /documents/{id}/archive` \| `DELETE /documents/{id}` | `.delete` | Row/detail action |
| Folder create/update/delete | `POST` \| `PATCH` \| `DELETE /documents/folders/{id}` | `.folder.manage` | Folder Tree action |
| Bulk move / archive / export | `POST /documents/bulk/move` \| `/archive` \| `/export` | `.attach` / `.delete` / `.export` | Bulk Action Bar |

`docs/frontend/DOCUMENT_CENTER.md → Endpoints` documents the full table including the two Documents-search
endpoints' reconciliation (the human-facing `/documents/search` vs the agent-only `/ai/documents/search`) —
this table is the strict subset `page.tsx` and its immediate children issue.

## Worked request/response examples

### The three-call presigned upload

Reproduced from `docs/frontend/DOCUMENT_CENTER.md → DocumentUploader`, which drives it. Step 1 asks Laravel
for a presigned `PUT`; step 2 sends the raw bytes browser-to-R2 (never through Next.js or Laravel); step 3
finalizes the `attachments` row referencing the object already in R2. The finalize call's
`source.type: 'object_key'` is this document's stack's own third member of `upload_document`'s `source`
discriminated union — the schema names `base64`/`url`; the prose promises "pass the returned object key here
instead of raw bytes"; this is where that promise is filled in.

```json
// 1) POST /api/v1/documents/upload-url
{ "file_name": "receipt-0714.jpg", "mime_type": "image/jpeg", "size_bytes": 612000, "attachable_type": "inbox", "attachable_id": null }
```
```json
// 200 OK
{ "success": true, "data": {
    "upload_url": "https://r2.qayd.app/uploads/4821/9f2c…?X-Amz-Signature=…",
    "object_key": "uploads/4821/9f2c1e40-2a11-4d3a.jpg",
    "expires_at": "2026-07-16T09:17:00Z" },
  "message": "OK", "errors": [], "meta": { "pagination": null },
  "request_id": "a1b2…", "timestamp": "2026-07-16T09:02:00Z" }
```
```json
// 3) POST /api/v1/documents   (Idempotency-Key: <uuid>)
{ "attachable_type": "inbox", "attachable_id": 0, "file_name": "receipt-0714.jpg",
  "source": { "type": "object_key", "media_type": "image/jpeg", "object_key": "uploads/4821/9f2c1e40-2a11-4d3a.jpg" },
  "document_folder_id": null, "source_channel": "user_upload" }
```
```json
// 201 Created
{ "success": true, "data": {
    "id": 9104, "attachable_type": "inbox", "attachable_id": 88431,
    "file_name": "receipt-0714.jpg", "mime_type": "image/jpeg", "size_bytes": 612000, "status": "active",
    "uploaded_by": 1042, "ai_agent_id": null, "is_ai_extracted": false, "is_duplicate": false,
    "classification": null, "extracted_data": null,
    "retention": { "policy": "none", "expires_at": null, "legal_basis": null, "legal_hold": false },
    "version_count": 1, "created_at": "2026-07-14T19:24:00Z", "updated_at": "2026-07-14T19:24:00Z" },
  "message": "Document uploaded", "errors": [], "meta": { "pagination": null },
  "request_id": "b7e1…", "timestamp": "2026-07-14T19:24:00Z" }
```

A retried step 3 carrying the identical `Idempotency-Key` returns the original `201`, never a second row
(`docs/frontend/FRONTEND_ARCHITECTURE.md` Principle 9). A byte-identical re-upload instead resolves to
`is_duplicate: true` off the per-company `checksum_sha256` uniqueness — a deterministic data fact rendered
with a plain toast, no `ConfidenceBadge`, no `AiCardShell` (`# AI Integration`).

### An extraction call

```json
// POST /api/v1/documents/8801/extract-invoice-fields  →  200 OK (excerpt)
{ "success": true, "data": {
    "attachment_id": 8801, "processing_ms": 2140,
    "invoice_fields": {
      "extraction_confidence": 0.94,
      "fields": {
        "vendor_name": { "value": "ACME Supplies", "field_confidence": 0.97 },
        "invoice_number": { "value": "INV-2026-0715", "field_confidence": 0.98 },
        "grand_total": { "value": "500.0000", "currency": "KWD", "field_confidence": 0.99 },
        "due_date": { "value": "2026-08-14", "field_confidence": 0.61 }
      }
    },
    "reasoning": "Native-text PDF; totals block parsed from the right-aligned summary table; due_date inferred from 'Net 30' terms line at lower confidence." },
  "message": "Extraction complete", "errors": [], "meta": { "pagination": null },
  "request_id": "c4a9…", "timestamp": "2026-07-16T09:20:11Z" }
```

`ExtractedDataPanel` renders each field with its own `field_confidence` badge, so a reviewer sees at a glance
that `vendor_name` resolved at 97% while `due_date` resolved at 61% — the overall `0.94` clears the 0.60
floor, so "Create bill from this" renders at full weight.

## Query keys

```ts
// lib/query/keys.ts (documents-scoped factories, matching docs/frontend/DOCUMENT_CENTER.md exactly)
export const documentKeys = {
  all: ["documents"] as const,
  lists: () => [...documentKeys.all, "list"] as const,
  list: (filters: DocumentFilters) => [...documentKeys.lists(), filters] as const,
  detail: (id: number) => [...documentKeys.all, "detail", id] as const,
  versions: (id: number) => [...documentKeys.all, "versions", id] as const,
  search: (q: string, filters: Record<string, unknown>) => [...documentKeys.all, "search", q, filters] as const,
};
export const folderKeys = {
  all: ["document-folders"] as const,
  children: (parentId: number | null) => [...folderKeys.all, "children", parentId] as const,
};
```

## Cache tuning (unchanged from `docs/frontend/DOCUMENT_CENTER.md → Data & State`, restated for this file's hooks)

| Data class | Resources | `staleTime` |
|---|---|---|
| Library list / search | `documentKeys.list`, `documentKeys.search` | `30_000` (transactional-list) |
| Attachment detail | `documentKeys.detail` | `30_000`, `refetchOnWindowFocus` while the Extracted Data tab is open |
| Versions | `documentKeys.versions` | `5 * 60_000` (reference) |
| Folder tree | `folderKeys.children` | `5 * 60_000` (reference/master) |

`GET /documents` is cursor-mode only (`attachments` is append-only, unbounded — `docs/frontend/DOCUMENT_CENTER.md
→ useDocuments and pagination mode`), so `useDocuments` is a `useInfiniteQuery` mirroring `useLedgerEntries`.

## Mutation strategy — optimistic vs. pessimistic

Per `TRIAL_BALANCE.md`'s platform-wide Principle 10 ("reversible, non-financial actions are optimistic;
anything that changes authoritative state is pessimistic"), applied to this screen's own mutations:

| Mutation | Strategy | Rationale |
|---|---|---|
| `useUploadDocument` (finalize) | **Optimistic *presence*, never optimistic *content*** — a `DocumentCard`-shaped placeholder with an inline progress bar appears immediately, showing "Uploading…", and only swaps to the real thumbnail on the finalize `2xx`; a failed virus scan or rejected mime never leaves a phantom card | A stored file "simply is stored the moment the call succeeds" — but a card must never claim content the server has not confirmed |
| `useAttachDocument` (file / re-parent) | **Optimistic** — flips the filing-state chip immediately, rolls back on error | Reversible organizational change; no financial fact |
| `useRunOcr` / `useClassify` / `useExtractInvoiceFields` / `useExtractReceiptFields` | **Pessimistic** — the Extracted Data tab shows the result only after the server's `2xx`; the triggering menu item shows `loading` and disables for the duration | These are synchronous request/response calls with no queued job; showing extracted data before it returns would misrepresent the model's output |
| `useCreateFolder` / `useUpdateFolder` (rename/re-parent) | **Optimistic** | Reversible reference-data edit |
| `useDeleteFolder` | **Pessimistic** | Contained files' `document_folder_id` becomes `null` server-side (`ERD.md`); the tree must reflect the server's actual cascade |
| `useArchiveDocument` / `useDeleteDocument` | **Pessimistic** | A legal hold can block it server-side (`# Edge Cases`); the UI must never show it as done before the server confirms the precondition |
| `useBulkMove` / `useBulkArchive` | **Pessimistic** — per-item `data.results[i]`, one request, never N | A single ineligible id (a legal hold) fails only that id; the batch is never all-or-nothing |

## Realtime

Identical channel to `docs/frontend/DOCUMENT_CENTER.md → Realtime` — `private-company.{id}.documents`, firing
`document.uploaded` / `document.attached` / `document.archived`, each carrying just the attachment id and its
folder — subscribed once through the shared `RealtimeProvider` and merged into the relevant
`documentKeys.list(*)` cache via `queryClient.setQueryData`, never a second "realtime documents" store. The
channel exists purely for **multi-user visibility** (a colleague filing an inbox scan while this screen is
open elsewhere); it deliberately does **not** carry OCR/classification/extraction progress, because every one
of those four calls is a synchronous round trip this screen's own caller awaits directly.

# Interactions & Flows

Numbered as a concrete implementation sequence; the narrative form of each flow is
`docs/frontend/DOCUMENT_CENTER.md → Interactions & Flows`'s own territory and is not repeated here.

1. **Opening the screen.** `loading.tsx`'s shell renders instantly; `FolderTree` and `DocumentLibrary` each
   resolve behind their own `Suspense` boundary — a slow folder fetch never blocks the grid from painting.
2. **Toggling List ↔ Grid.** A `ViewToggle` local to the Data Region flips `view`, persisted per-user in
   `localStorage` (client-only, never round-tripped); both views key off the same `documentKeys.list(filters)`
   cache, so toggling never re-fetches.
3. **Searching.** The Filter Bar input debounces 250ms and calls `GET /documents/search`; results carry
   `matched_by: "text" | "semantic"`, rendering the muted "Matched by meaning" chip
   `docs/frontend/SEARCH.md → AI Integration` defines — reused verbatim, not re-invented.
4. **Uploading.** Dragging files anywhere over the Data Region (the whole Grid/List surface is a valid drop
   target when `documents.upload` is held) or clicking Upload runs the three-call sequence per file, in
   parallel up to `maxConcurrent` (default 3); a placeholder card appears immediately per file (optimistic
   presence). A drop with no `attachable_type` context defaults to `'inbox'`, visible under "Needs filing";
   a drop from a record screen's own Attachments tab carries that record's `attachable_type`/`attachable_id`
   and never touches the inbox.
5. **Filing an inbox item.** Opening a "Needs filing" card shows a "File this document" call-to-action in the
   Summary Rail; choosing a target through `AttachablePicker` and confirming calls `attach_document`; the
   card's chip flips from "Needs filing" to nothing (filed is the unmarked default state — only the
   exceptional "needs attention" earns a chip).
6. **Running OCR / Classify / Extract.** Each overflow action is a single click that awaits its endpoint
   inline (the item shows a spinner and disables); the Extracted Data tab streams in the moment the response
   resolves, no "check back later" step. Classification typically precedes extraction — "Extract invoice
   fields" greys in only once a `vendor_invoice`/`customer_invoice` `document_type` is known or the caller
   asserts a type via the "I know what this is" override.
7. **Handing an extraction off.** Once `extract_invoice_fields` resolves for a `vendor_invoice` attachment,
   the Extracted Data tab's header gains "Create bill from this" (visible only under `purchasing.bill.create`),
   navigating to `/purchasing/bills/new?from_attachment=8801` pre-filled — never a second, parallel
   bill-creation form inside Document Center.
8. **Moving files and folders.** Drag a card onto a Folder Tree node, or use "Move to folder"; drag a tree
   node onto another node to re-parent the folder. Every drag has an equivalent keyboard/menu path (a
   `Dialog` with the same `FolderTree` picker), per `ACCESSIBILITY.md`'s "every interaction has a non-pointer
   equivalent."
9. **Bulk action.** Checking cards/rows (checkbox, `Shift`-click range, `Cmd/Ctrl+A` for the page) surfaces
   the Bulk Action Bar; each action posts once to its own bulk endpoint and renders a per-item result toast
   ("5 moved, 1 failed — Diyar-Lease.pdf is under legal hold"), never a single opaque success/failure.
10. **Preview and download.** Opening a card (click, or `Enter` when focused) opens the intercepted `Sheet`;
    a middle-click or `Cmd/Ctrl`-click follows the card's real `href` (`/documents/{id}`) into a new tab.
    "Download" always re-requests a fresh signed URL immediately before use (the loaded one may have crossed
    its 15-minute expiry).

# AI Integration

This screen's AI surface obeys the platform-wide contract in full — confidence and reasoning are never
hidden, visual provenance is `AiCardShell`'s accent border plus `Sparkles` "AI" badge — but it is **the one
screen in the platform whose AI-triggered actions are not wrapped in an `ApprovalCard`**, and the reason is
structural, not a shortcut (`docs/frontend/DOCUMENT_CENTER.md → AI Integration → suggest-and-surface, not
suggest-and-gate`): the six document tools are tagged `write_propose` in the narrower, explicitly
non-financial sense — none can create, post, approve, or move a financial fact — and `attachments` carries no
`draft`/`pending_approval` state for a human to adjudicate. Clicking "Run OCR" is closer in kind to clicking
"Regenerate" than to clicking "Post." The approval machinery applies in full exactly one layer downstream,
the moment an extraction payload is handed to Purchasing's `create_bill_draft_from_ocr` (this screen's
hand-off button), which lands a `bills` row in `draft` and runs `purchasing.bill.post`'s own chain.

| Gate | Value | Effect on this screen |
|---|---|---|
| Confidence scale | `classification.confidence` and `extraction_confidence`/`field_confidence` are `0.0000–1.0000` | Passed through `normalizeConfidence(raw, 'fraction')` — never divided by 100. The single most common implementation bug on this screen is passing an already-fractional field as `'percentage'`. |
| Extraction confidence floor | `< 0.60` | The "Create bill/expense claim from this" hand-off renders de-emphasized-but-clickable with a "review every field" caption; the button is never hidden outright (`docs/ai/tools/DOCUMENT_TOOLS.md`'s "route to manual entry rather than one-click accept"). |
| Per-field confidence | `field_confidence` per extracted value | A small `ConfidenceBadge` beside each value in the panel's `<table>`, so granular confidence is visible without opening a reasoning drawer. |
| Classification routing | `routing_hint` (`purchasing_agent`/`general_accountant`/`tax_advisor`/`compliance_agent`/`payroll_agent`/`null`) | A muted "Routed to {Agent}" caption on the card and in the Activity tab — informational only, never an action button. |
| Fraud-adjacent signals | `policy_flags` (`round_number_amount`, `no_itemization`, `weekend_date`) | Small neutral-toned inline badges on a receipt's Extracted Data tab — observations, not conclusions; never withhold a download, hand-off, or filing action. |
| Duplicate detection | `is_duplicate` (deterministic, off `checksum_sha256`) | Plain "Already on file" toast — no `ConfidenceBadge`, no `AiCardShell`, because a byte-for-byte checksum match is a fact, not a probabilistic judgment (`DARK_MODE.md → AI provenance color`'s fact-vs-certainty distinction). |

Two named specialists feed this screen, division of labor exactly `docs/ai/tools/DOCUMENT_TOOLS.md`'s:
**OCR Agent** (`OCR_AGENT`) performs raw digitization — text, per-page confidence, detected language — and
never determines what a document *is*; **Document AI** (`DOCUMENT_AI`) performs classification and structured
field extraction on top of OCR Agent's output. Their `agent_code` populates the `AgentAttributionChip` on the
Extracted Data tab's `AiCardShell`; neither is ever rendered as a fourth human role, because neither opens a
page.

# States

`deriveExtractionStatus` is the one piece of presentational logic this screen computes client-side rather
than reading as a server field — deliberately, since `attachments` has no such column and Principle 1 permits
exactly this derived-for-display convenience as long as it never becomes a value submitted back:

```ts
// lib/documents/derive-extraction-status.ts
export type ExtractionStatus = "not_started" | "processing" | "extracted" | "failed";

export function deriveExtractionStatus(
  a: Attachment,
  inFlight: { ocr: boolean; classify: boolean; extract: boolean; failed?: boolean },
): ExtractionStatus {
  if (inFlight.ocr || inFlight.classify || inFlight.extract) return "processing";
  if (inFlight.failed) return "failed"; // session-local only, from this session's own onError — never persisted
  if (a.extracted_data?.invoice_fields || a.extracted_data?.receipt_fields
      || a.extracted_data?.classification || a.extracted_data?.ocr) return "extracted";
  return "not_started";
}
```

| Region | Loading | Empty | Error |
|---|---|---|---|
| Library — first paint | `loading.tsx`: a grid of `Skeleton` card shapes matching real dimensions, never a spinner | Company-root-empty: "No documents yet — upload your first file" + inline `DocumentUploader` drop zone | `error.tsx`: recoverable "Retry" card, reserved for genuine `5xx`/network on the initial fetch |
| Library — filtered/searched | `keepPreviousData`-held previous grid, dimmed — never a flash to empty mid-type | "No files match your filters" / "No results for '{q}'" with a "Clear filters" action, distinct from the true-zero state above | Inline retry card scoped to the Data Region; Folder Tree + Filter Bar stay interactive |
| "Needs filing" smart-folder | Same skeleton | "Nothing to file — every document is linked to a record" (a genuinely good state, styled success-neutral) | Same inline retry |
| Folder Tree | `Skeleton` tree rows on first load only | A brand-new company shows only "All Documents" and "Needs filing," no create prompt unless `documents.folder.manage` | Keeps last-known state + a small "Couldn't refresh folders" notice, never collapsing the rail |
| Upload in progress | Per-file inline progress bar on its placeholder card | N/A | A failed step (presign / R2 PUT / finalize) replaces that card with an error naming which step failed + "Retry" that re-runs only the failed step (never re-uploading bytes already in R2) |
| Preview — file content | `Skeleton` frame at the target aspect ratio | N/A (`size_bytes` is `NOT NULL`) | "Couldn't load this file's preview — Download instead," Download fully functional; a preview failure never blocks metadata/extraction |
| Extracted Data — in flight | Three `accent-subtle` pulsing dots on the triggering button + a skeleton panel body | "Not yet processed — Run OCR / Classify / Extract" inline CTA per missing step, gated by permission | A failed call surfaces the session-local `'failed'` pill + the API's own `message` verbatim + an immediate "Retry" |
| Versions tab | `Skeleton` rows | "No prior versions — this is the only version on file" | Inline retry, scoped to the tab |
| Bulk Action Bar | Per-item spinners inside the settling toast summary | N/A (only appears with ≥1 selected) | Partial-failure summary toast, never a single opaque failure for the batch |

A route-level `error.tsx` remains the outermost net for a genuinely unexpected failure; per the platform's
three-granularity error model it should essentially never fire for an individual region's ordinary
`4xx`/`5xx`, which are caught inline one region at a time.

# Responsive Behavior

Breakpoints are `LAYOUT_SYSTEM.md → Breakpoints`'s fixed scale — this screen introduces none of its own.
`DocumentGrid` sizes its columns via `auto-fill`/`minmax()` keyed to the density token, so the same component
produces the correct column count at any width between the named tiers, not only at the boundaries.

| Token | Width | This screen's behavior |
|---|---|---|
| `base` | <640px | Grid forced (List has no legible mobile shape), 2 columns, `Compact` density; Folder Tree → `Sheet` from breadcrumb tap; Filters → `Sheet` from a filter-icon trigger; Preview always a full-height `Sheet`, its Tabs horizontally scrollable |
| `sm` | 640px | Still 2-column Grid; upload `Sheet` full-height |
| `md` | 768px | Grid 3–4 columns at `Comfortable`; Folder Tree stays a `Sheet`; List mode becomes available |
| `lg` | 1024px | Folder Tree docks permanently at `col-span-3`; grid 4–5 columns; Preview `Sheet` widens toward the Detail template's `8/4` |
| `xl` | 1280px | Full layout as drawn; grid 5–6 columns (`Comfortable`) / 7–8 (`Compact`); the platform-wide `360px` AI Rail may dock inline without competing for the grid's columns |
| `2xl`/`3xl` | 1536/1920px | Same layout, wider outer margins; container ceiling reached at `3xl` |

Two virtualization thresholds, for two item weights: `DocumentGrid` virtualizes above ~60 cards, List mode
follows the ordinary ~200-row threshold — a card is meaningfully heavier per DOM node than a table row.
Touch targets on every card checkbox, row action, and Folder Tree disclosure triangle meet the 44×44px
minimum below `md`, implemented once in shared `Checkbox`/`IconButton`.

# RTL & Localization

Every rule below is inherited from `DESIGN_LANGUAGE.md → RTL mirroring` and `LAYOUT_SYSTEM.md`'s RTL contract,
applied concretely to this screen's own regions; `docs/frontend/DOCUMENT_CENTER.md → RTL & Localization`
states the same rules and this section adds component-level application.

- **Logical properties only.** The Folder Tree rail sits on the inline-end edge in Arabic (a
  `border-inline-end`/`border-inline-start` pair, never a hardcoded `border-left`/`border-right`); the
  Breadcrumb chevrons flip via `DirectionalIcon`; the Preview's Summary Rail moves to the inline-start side —
  all with zero screen-specific RTL code.
- **Numerals, sizes, and confidence never mirror.** Every `size_bytes` ("482 KB"), `version_count`, date, and
  confidence percentage renders inside a `dir="ltr"` span with `numberingSystem: "latn"` even under `ar`,
  matching `README.md`'s platform-wide numeral rule.
- **File content is never mirrored or translated.** A scanned Arabic lease, an English vendor invoice, and a
  bilingual government notice all render in `DocumentPreviewFrame` exactly as authored — only the frame's own
  chrome mirrors, never the embedded PDF/image's internal layout. OCR'd text from an RTL source displays as
  plain text with `dir="auto"`/Unicode bidi-isolation inside `ExtractedDataPanel`.
- **Classification values reuse the enum as keys.** `documents.classification.vendor_invoice`, `.receipt`, …
  — never a screen-local re-spelling of the same ten values.

| Context | English | Arabic |
|---|---|---|
| Screen title | Documents | المستندات |
| Primary action | Upload | رفع |
| Secondary action | New folder | مجلد جديد |
| Operational count | {n} need filing | {n} بحاجة للأرشفة |
| Smart-folder | Needs filing | بحاجة للأرشفة |
| Filing chip | Needs filing | بحاجة للأرشفة |
| Hand-off | Create bill from this | إنشاء فاتورة من هذا |
| Low-confidence caption | Confidence is low; review every field before creating a bill | الثقة منخفضة؛ راجع كل حقل قبل إنشاء الفاتورة |
| Duplicate toast | Already on file | موجود مسبقًا |
| Empty (root) | No documents yet — upload your first file | لا توجد مستندات بعد — ارفع ملفك الأول |
| Legal-hold disabled | This document is under an active legal hold and cannot be archived or deleted | هذا المستند تحت حجز قانوني نشط ولا يمكن أرشفته أو حذفه |

Arabic copy is authored directly by a fluent professional-register writer, never machine-translated, per
`DESIGN_LANGUAGE.md → Voice & Microcopy`'s Gulf-business-register brief.

# Dark Mode

This screen introduces no new color, elevation, or radius token — every surface resolves through the
platform's `.dark`/`data-theme="dark"` remap defined once in `DESIGN_LANGUAGE.md → Design Tokens`. The
concrete pairs this screen's components read most often:

| Token | Used on this screen for |
|---|---|
| `--qayd-surface` / `--surface-raised` | Card fill; the preview frame's own chrome background |
| `--ink-150` / `--ink-6` | Card borders; Folder Tree dividers |
| `--ai-accent` | `ConfidenceBadge` fill and every `AiCardShell` border — in both themes, never the accent-green or success-green, per `DARK_MODE.md → AI provenance color` |
| `warning` | The "Needs filing" chip (`tone="warning"` — an unfiled document is "needs attention," not a `danger`-class failure) |

Elevation gets *lighter*, not darker, toward the viewer in dark mode (`DESIGN_LANGUAGE.md → Dark mode
strategy`). Two rules specific to this screen: **thumbnails and previews never get a dark-mode treatment** —
an uploaded photograph, a scanned white-paper invoice, or an inline PDF keeps its own original colors and (for
a PDF) its own white page background; `filter: invert()` or any automatic recoloring is never applied to
user content, only to the frame's own chrome. A generic mime-type glyph (`FileText`/`FileSpreadsheet`/
`FileImage`) *does* remap normally, since those are QAYD's own UI elements. Every Storybook story for the ten
new components ships the standard four-way matrix (`light/LTR`, `light/RTL`, `dark/LTR`, `dark/RTL`), and this
route's Playwright suite captures the same four-way screenshot set at the route level.

# Accessibility

Baseline WCAG 2.2 AA, identically in both languages and both themes, restating
`docs/frontend/DOCUMENT_CENTER.md → Accessibility`'s rules with the concrete implementation checklist an
engineer verifies against:

- **List mode is the plain accessible `<table>` pattern, never the ARIA `grid` pattern.** `ACCESSIBILITY.md →
  Data Tables Accessibility` reserves `grid` for exactly two surfaces (the Journal Entry line editor, the
  Bank Reconciliation matching grid); a document library is a read/sort/filter/paginate/open surface,
  squarely the plain-table case.
- **Grid mode is a real `<ul aria-label>` of `<li>`s.** Each `DocumentCard`'s root keeps `role="button"` for
  its single primary action (open the preview); the selection `Checkbox` is a second, independently-focusable
  control inside the same `<li>`. A card is never given `role="gridcell"`/`role="row"`.
- **`FolderTree` is a real ARIA `tree`** built on `WAREHOUSES.md`'s `WarehouseStructureTree` pattern:
  `role="tree"`, `role="treeitem"` per node, `aria-expanded` on any node with children, `aria-level`,
  `aria-selected`, and the roving-`tabindex`/arrow-key contract (`→` expands or enters first child; `←`
  collapses or moves to parent; `↑`/`↓` between visible nodes only; `Home`/`End`; `Enter`/`Space` activates).
  A read-only tree (no `documents.folder.manage`) stays fully navigable — three actions removed, never the
  navigation.
- **The upload drop zone satisfies WCAG 2.5.7 by construction.** `DocumentUploader`'s root is a real
  `role="button" tabIndex={0}` whose `Enter`/`Space` opens the native file picker — the non-drag equivalent
  is the same control a mouse user drags onto, not a bolted-on afterthought.
- **Upload progress announces at its two edges only.** A per-file task announces once on start ("Uploading
  {name}…") and once on settle ("Uploaded" / "Couldn't upload — {reason}") via `aria-busy`-at-start/announce-
  at-completion — a progress bar ticking 12→97% is visual feedback for a sighted user, not screen-reader
  content.
- **`ExtractedDataPanel`'s field tables are real `<table>` markup** — `<th scope="row">{label}</th><td>{value}
  </td><td><ConfidenceBadge/></td>` — so a screen reader announces "Grand total, KWD 500.000, 99% confidence"
  as one column-labelled unit. Extracted monetary values are tabular-numeral, right-aligned, but never routed
  through the ledger-specific `AmountCell`/debit-credit treatment — a receipt's `amount` is a plain extracted
  quantity, not a signed ledger fact.
- **RBAC-denial and business-rule-denial are never the same disabled state.** Archive/Delete disabled for a
  missing `documents.delete` carries "Requires `documents.delete`"; the same button disabled for an active
  legal hold carries "This document is under an active legal hold and cannot be archived or deleted" —
  conflating the two is the exact P1 defect `ACCESSIBILITY.md → RBAC-aware disabled controls must explain
  themselves` names.
- **Bulk selection announces its own state.** The List header checkbox and the Grid "select all visible" are
  both tri-state (`aria-checked="mixed"`); the Bulk Action Bar's appearance is announced via `role="status"`
  ("6 selected — Move, Download, Archive, Export, or Clear"), not only a visual slide-in.
- **Download is a first-class equal, never a last resort.** QAYD cannot retroactively tag a third-party PDF it
  did not produce; `DocumentPreviewFrame`'s "Download instead" state is rendered at equal visual and keyboard
  weight, because for an untagged source file the user's own AT-aware reader is frequently more accessible.
- **Testing.** `/documents` and `/documents/{id}` are in the four-permutation automated sweep (`en`/light,
  `en`/dark, `ar`/light, `ar`/dark) with zero serious/critical `axe` violations as a merge gate; the manual
  pass adds filing an inbox item end-to-end with only a keyboard, a screen-reader pass over the Extracted Data
  field table, and an `ar`/RTL pass over `FolderTree`'s inline-end position and mirrored chevrons.

# Performance

- **Streamed, not blocking.** Per `# Layout & Regions`'s `page.tsx`, `FolderTree` and `DocumentLibrary` are
  each their own `Suspense` boundary; a slow folder fetch never delays the grid's first paint.
- **List responses never carry a signed URL.** `list_attachments`/`GET /documents` omits a per-row
  `signed_url` by design; minting one is a real R2/crypto operation, and doing it for 24–100 rows a user may
  scroll past without opening a file is pure wasted work. Only `get_attachment` — one file, at open time —
  ever mints one, keeping the list-fetch cost flat regardless of how many rows anyone opens.
- **Uploads bypass Next.js for the large part.** The three-call sequence sends small JSON for the presigned
  URL, `PUT`s raw bytes browser-to-R2, then sends small JSON to finalize — the file's own bytes never transit
  the Next.js Route Handler or a Laravel request-body limit. `maxConcurrent` (default 3) caps concurrent
  uploads so a 40-file drop saturates neither the user's bandwidth nor the browser's connection ceiling.
- **Thumbnails opt out of Next Image Optimization.** `DocumentCard`'s `<Image ... unoptimized />` is
  deliberate: the optimizer assumes a stable, cacheable URL, and a `signed_url` expires in 15 minutes —
  optimizing a URL that will `403` within the hour only adds latency and a guaranteed future cache miss.
- **`DocumentPreviewFrame` ships zero PDF-rendering JavaScript** — a plain `<iframe>`/`<object>` at the signed
  URL lets the browser's own native renderer do the work; no `pdf.js`-equivalent bundle ships to this route.
- **Search is debounced at 250ms** specifically to protect the combined literal-plus-`pgvector` query
  (`SEARCH.md`'s "single most expensive query shape") from firing on every keystroke.
- **Two virtualization thresholds** (60 grid / 200 list) reflect two genuinely different per-item DOM weights.
- **`FolderTree` loads its levels lazily** — `GET /documents/folders?parent_id=` is called again only on
  actual expand, the same lazy-per-level pattern `WAREHOUSES.md`'s tree establishes.
- **Extraction calls carry a 30s `AbortController` ceiling** — synchronous but occasionally slow for a large
  multi-page scan; a call that exceeds it surfaces the session-local `'failed'` state with immediate "Retry."
- **Bulk actions are one request, not N,** at any selection size.
- **Web Vitals against a company-size-band baseline** — a holding company with tens of thousands of files and
  a brand-new company have legitimately different tail latencies; conflating them masks real regressions.

# Edge Cases

`docs/frontend/DOCUMENT_CENTER.md → Edge Cases` already covers the scenarios specific to the library's own
data model (legal-hold in a bulk selection, checksum dedupe against a second target, folder deleted while
browsed, a folder-cycle move, `DOCUMENT_UNREADABLE`/`EXTRACTION_FAILED`, a signed URL expiring mid-session, a
revoked `attachable_href` permission, concurrent `attach_document`, a mobile PDF-render failure, a vanished
upload target, an off-`candidate_types` classification, duplicate file names, a brand-new company, an
overflowing file name) — this document does not repeat them. The rows below are this implementation layer's
own additions: races and environment conditions that surface once `page.tsx`, its mutation hooks, and its
optimistic-presence logic are actually written.

| Edge case | Frontend behavior |
|---|---|
| The R2 `PUT` (step 2) succeeds but the finalize (step 3) fails or the tab closes before it fires | The uploaded object sits in R2 orphaned (server-side lifecycle sweep reclaims it); the placeholder card converts to a per-file error state with "Retry" that re-runs only step 3 against the already-uploaded `object_key`, never re-uploading bytes. |
| A second file drop lands while the first batch's optimistic placeholder cards are still pending | Each `UploadTask` is tracked by its own key and resolves independently; a second drop neither cancels nor reorders the first — both batches' placeholders coexist, each swapping to its real card on its own finalize. |
| The locale is switched (EN → AR) while a debounced search request is in flight | The in-flight request completes and is applied; the search placeholder and "No results" copy re-render in the new locale immediately, since locale and result data are independent query keys — a locale switch never clears `documentKeys.list`/`search`, since file metadata is locale-invariant. |
| `deriveExtractionStatus` shows `'failed'`, then the user reloads the page or opens the file from another device | `'failed'` is session-local only (there is no server column recording a past failure); the reloaded view shows `'not_started'`, not a stale `'failed'` — the one documented place this screen's state model diverges from "the server is the only source of truth," scoped tightly to a transient UI hint. |
| The intercepted `@modal/(.)[attachmentId]` Sheet is open and the user hits the browser Back button | `router.back()` (wired in `InterceptedSheetShell.onOpenChange`) closes the Sheet and returns to the grid at its prior scroll position; a hard refresh on the same URL instead renders the full `[attachmentId]/page.tsx`, never the Sheet — the two entry points converge on the same `DocumentPreview` component. |
| A `documents.upload` grant is revoked in another tab while a multi-file drop is mid-flight | Files already past step 1 (holding a presigned URL) complete, since the URL is a bearer token the server already issued; a new drop after the revocation is rejected at step 1 with the "you don't have permission to upload here" toast — the frontend never pre-blocks an in-flight, already-authorized upload it cannot cleanly cancel. |
| The Folder Tree's optimistic rename resolves against a folder a colleague deleted a moment earlier | `useUpdateFolder`'s `onError` rolls the tree back to its last server state and surfaces "This folder no longer exists — reload the tree," invalidating `folderKeys.all` rather than leaving a renamed ghost node. |
| A signed-URL `<img>` in an open preview Sheet goes stale (past 15 min) while the Sheet stays open | The image `src` is refreshed the moment the user next interacts with the Sheet (focus, or clicking Download, which always re-mints), so an hours-old open Sheet never presents a broken-image icon as though the file were missing. |

# End of Document
