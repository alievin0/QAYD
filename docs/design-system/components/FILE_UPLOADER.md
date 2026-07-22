# File Uploader — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Components / FILE_UPLOADER
---

# Purpose

This is the atomic design-system specification for QAYD's **File Uploader** — the `FileDropzone` primitive
and its supporting atoms (`FileList` row, per-file progress bar, rejection alert) that ingest documents into
QAYD: a bank statement, a bill scan, a CSV of transactions, a receipt photo. It is the design-system
counterpart to the input-catalogue entry in
[`../../frontend/components/INPUTS.md → FileDropzone`](../../frontend/components/INPUTS.md): that document
owns the *control's place in the form system* — how it sits inside a `<FormControl>`, the shared
controlled-component contract, and the client-validate-then-server-re-check security boundary. This document
owns the **atomic visual and state contract**: which token paints the dashed drop surface in each state,
how drag-active/rejecting/uploading are distinguished, the per-file progress and presigned-upload lifecycle,
and how the whole thing mirrors in RTL and remaps in dark mode.

The uploader is the front door to the Document Center and the import flows, so its states carry more weight
than a typical control: a user drops a quarter of bank statements and must know, per file, whether each was
accepted, is uploading, uploaded, or was rejected — never a silent drop. Two platform rules from
[`../../frontend/INPUTS.md`](../../frontend/components/INPUTS.md) and
[`../../frontend/COMPONENT_LIBRARY.md`](../../frontend/COMPONENT_LIBRARY.md) bind every line below. First,
**files go to object storage (Cloudflare R2) via a signed URL, never a static path** — the dropzone hands
accepted files to the consumer, which requests a presigned target and uploads directly. Second,
**client-side type/size validation is a courtesy that fails fast, never a security boundary** — the server
re-checks the real bytes (a renamed `.exe` is caught server-side regardless of a permissive `accept`).

The binding rule from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) governs the surface: **the uploader
references a token, never a literal.** The ink scale, accent, and semantic palette are defined in
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md).

> Token-naming note. The application snippet uses an earlier numeric-step palette (`ink-150`, `accent-100`,
> `accent-600`, `bg-ink-100`, `text-danger`, `text-body`, `text-caption`). Per
> [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md), the **canonical** names used here are
> `ink-1 … ink-12`, `accent`/`accent-subtle`, `negative`/`warning`/`positive`, and the `text-*` steps.
> Read a draft name as its canonical equivalent (`ink-150` → `ink-6`/`ink-7`, `accent-100` →
> `accent-subtle`, `accent-600` → `accent`, `bg-ink-100` → `ink-2`, `text-danger` → `text-negative`).

# Anatomy

The uploader is two token-styled atoms: the **drop surface** (a dashed, focusable region that also opens the
browse dialog) and the **file list** (one row per accepted file with its progress and status). Drag-drop is
never the *only* way to add a file — the surface is a labelled `<input type="file">`, so click-to-browse
and keyboard always work.

```
FileDropzone
├── Drop surface ── border-dashed ink-7 · bg-ink-2/50 · radius-lg · p-8 · text-center
│   │                focus-within: 2px accent ring + offset
│   │                drag-active:  border-accent · bg-accent-subtle
│   │                rejecting:    role="alert" · text-negative message
│   ├── UploadCloud icon (ink-9, aria-hidden) — a meaning icon, never flips in RTL
│   ├── Hint (text-sm ink-9) — "Drop files or browse · PDF, CSV · max 10 MB"
│   └── <input type="file"> (aria-label, visually covering the surface)
└── FileList (one row per accepted file)
    └── FileRow ── file-type icon · name (text-sm ink-11, truncate) · size (text-xs ink-9)
        ├── ProgressBar ── track ink-4 · fill accent · radius-full · h-1
        └── Status ── uploading (accent %) · done (positive check) · failed (negative + retry)
```

The drop surface uses `radius-lg` and a **dashed** `ink-7` border — the one place a dashed border appears in
the system, signalling "drop target" — over a faint `ink-2/50` fill so the region reads as a distinct
receiving area without a heavy panel. Everything else is built from the standard tokens.

# Variants

The uploader varies along a small set of axes; the ones that change **tokens or layout** are:

| Axis | Values | Effect |
|---|---|---|
| `multiple` | `false` / `true` | Single-file replaces on a new drop; multi appends to the `FileList` |
| `layout` | `dropzone` / `compact` | Full dashed `p-8` region vs. an inline `sm`-height button + list (a drawer/toolbar context) |
| `state` | idle / drag-active / rejecting / uploading | Each is a distinct token treatment (see States) |
| `preview` | `none` / `thumbnail` | Image/PDF ingest may render a `radius-md` thumbnail in the `FileRow` leading slot |

The default is `dropzone` + `multiple` — the Document Center's batch ingest. A `compact` uploader (a single
attach button beside a form field) shares every token and state but omits the large dashed region.

## The `FileDropzone` primitive (tokens + a11y)

```tsx
// components/ui/file-dropzone.tsx — the drop surface. Markup, tokens, and the a11y contract.
'use client';
import { useDropzone } from '@/hooks/use-dropzone';
import { UploadCloud } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useTranslations } from 'next-intl';

export function FileDropzone({ accept, maxSizeMb, onFiles, disabled }: FileDropzoneProps) {
  const t = useTranslations('upload');
  const { getRootProps, getInputProps, isDragActive, rejections } = useDropzone({ accept, maxSizeMb, onFiles, disabled });
  return (
    <div
      {...getRootProps()}
      className={cn(
        // Dashed drop target: the one sanctioned dashed border in the system.
        'flex flex-col items-center gap-2 rounded-lg border border-dashed border-ink-7 bg-ink-2/50 p-8 text-center',
        'focus-within:outline-none focus-within:ring-2 focus-within:ring-accent focus-within:ring-offset-2',
        isDragActive && 'border-accent bg-accent-subtle',   // drag-active: accent border + subtle tint
        disabled && 'opacity-50 cursor-not-allowed',
      )}
    >
      <input {...getInputProps()} aria-label={t('chooseFiles')} />
      <UploadCloud className="h-6 w-6 text-ink-9" aria-hidden />
      <p className="text-sm text-ink-9">{t('dropHint', { types: accept.join(', '), max: maxSizeMb })}</p>
      {rejections.length > 0 && (
        // Rejections are announced, never a silent drop.
        <p role="alert" className="text-xs text-negative">{t('rejected', { count: rejections.length })}</p>
      )}
    </div>
  );
}
```

## The `FileRow` atom (per-file progress + status)

```tsx
// components/ui/file-row.tsx — one accepted file with its presigned-upload lifecycle.
'use client';
import { FileText, CheckCircle2, AlertCircle, RotateCcw, X } from 'lucide-react';
import { cn } from '@/lib/utils';

export function FileRow({ file, progress, status, onRetry, onRemove }: FileRowProps) {
  return (
    <li className="flex items-center gap-3 rounded-md border border-ink-6 bg-ink-3 px-3 py-2">
      <FileText className="h-4 w-4 shrink-0 text-ink-9" aria-hidden />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm text-ink-11" title={file.name}>{file.name}</p>
        <p className="text-xs text-ink-9" dir="ltr">{formatBytes(file.size)}</p>
        {status === 'uploading' && (
          <div className="mt-1 h-1 w-full overflow-hidden rounded-full bg-ink-4" role="progressbar"
               aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100}>
            <div className="h-full rounded-full bg-accent transition-[width]" style={{ inlineSize: `${progress}%` }} />
          </div>
        )}
      </div>
      {status === 'uploading' && <span className="text-xs tabular-nums text-accent" dir="ltr">{progress}%</span>}
      {status === 'done'    && <CheckCircle2 className="h-4 w-4 text-positive" aria-label="Uploaded" />}
      {status === 'failed'  && (
        <button type="button" onClick={onRetry} className="inline-flex items-center gap-1 text-xs text-negative">
          <AlertCircle className="h-4 w-4" aria-hidden /> <RotateCcw className="h-3 w-3" aria-hidden /> Retry
        </button>
      )}
      <button type="button" onClick={onRemove} aria-label={`Remove ${file.name}`} className="text-ink-9 hover:text-ink-11">
        <X className="h-4 w-4" aria-hidden />
      </button>
    </li>
  );
}
```

# Props / API

The full control-in-a-form prop surface is owned by
[`../../frontend/components/INPUTS.md → Bulk props`](../../frontend/components/INPUTS.md). This section fixes
the props whose values are **token, state, or validation decisions**.

## `FileDropzone`

| Prop | Type | Required | Description |
|---|---|---|---|
| `accept` | `string[]` | yes | Allowed MIME/extensions, e.g. `['application/pdf', '.csv']`. A client courtesy; the server re-checks bytes. |
| `maxSizeMb` | `number` | yes | Per-file ceiling; a breach surfaces the inline `role="alert"` rejection, **never** a silent drop. |
| `onFiles` | `(files: File[]) => void` | yes | Accepted files; the consumer requests the presigned R2 URL and starts the upload. |
| `multiple` | `boolean` | no, default `true` | Single-file replaces; multi appends to the `FileList`. |
| `disabled` | `boolean` | no | `opacity-50`, `cursor-not-allowed`, skipped by tab order; a permission-disabled uploader carries a tooltip naming the required key. |

## `FileRow`

| Prop | Type | Token / state effect |
|---|---|---|
| `progress` | `number` (0–100) | Drives the `accent` progress-bar width and the `tabular-nums` `%` label |
| `status` | `'queued' \| 'uploading' \| 'done' \| 'failed'` | Selects the trailing atom: spinner/`%` · `positive` check · `negative` + retry |
| `onRetry` | `() => void` | Re-requests a presigned URL and re-uploads; the row is **kept**, not cleared, on failure |
| `onRemove` | `() => void` | Removes the file from the list before or after upload |

# States

The uploader is a state machine, and each state has a designed, token-defined rendering — the front door to
document ingest cannot afford an ambiguous "did that work?" moment. Behavioral detail (backoff, the
presigned-URL handshake) is the app doc's; the **token treatment** is here.

| State | Token treatment |
|---|---|
| **Idle** | Dashed `ink-7` border, `ink-2/50` fill, `UploadCloud` `ink-9`, `text-sm ink-9` hint naming accepted types and the max size. |
| **Focus (keyboard)** | 2px `accent` `focus-within` ring + 2px offset — the browse dialog opens on `Enter`/`Space`. |
| **Drag-active** | Border switches to solid `accent`, fill to `accent-subtle` — an unmistakable "release to drop" affordance. Collapses to instant under reduced motion. |
| **Rejecting** | The offending file(s) never enter the list; an inline `role="alert"` `text-negative` message names the reason ("PDF only · max 10 MB"). Never a silent drop. |
| **Queued** | An accepted file appears as a `FileRow` (`ink-6` border, `ink-3` fill) with a static leading icon, awaiting its presigned target. |
| **Uploading** | A `role="progressbar"` bar: `ink-4` track, `accent` fill growing to the reported percentage, a `tabular-nums accent` `%` label (`dir="ltr"`). |
| **Uploaded (done)** | A `positive` check replaces the progress bar; the row stays listed as confirmation. |
| **Failed** | A `negative` `AlertCircle` + a Retry affordance; **the accepted file is kept in the list**, so a transient failure never loses the user's selection. |
| **Presigning / server error** | A failed presigned-URL request surfaces the same in-row `negative` retry with the request id in a focus tooltip — the failure is scoped to the one file, siblings keep uploading. |
| **Disabled** | `opacity-50`, `cursor-not-allowed`; a permission-disabled uploader carries a tooltip naming the required key rather than an unexplained grey box. |

The presigned-upload lifecycle per file is: **accepted → presign requested → uploading (progress) → done**,
with **failed** reachable from presign or upload and always returning to a retry, never a discard.

# Tokens Used

Every token this atom paints, drawn only from [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) and
[`../COLOR_SYSTEM.md`](../COLOR_SYSTEM.md).

| Role | Token | Notes |
|---|---|---|
| Drop surface fill | `ink-2` @ 50% | Faint receiving area |
| Drop surface border | 1px **dashed** `ink-7` + `radius-lg` | The one dashed border in the system |
| Drag-active border | `accent` | Solid; "release to drop" |
| Drag-active fill | `accent-subtle` | The learned drop-target tint |
| Focus ring | 2px `accent` + 2px offset | `focus-within` |
| Upload icon | `ink-9` | Meaning icon, never flips in RTL |
| Hint text | `text-sm`, `ink-9` | Types + max size |
| File-row surface | `ink-3` + 1px `ink-6` + `radius-md` | Standard card-adjacent row |
| File name | `text-sm`, `ink-11`, truncate + `title` | Bilingual-safe |
| File size / meta | `text-xs`, `ink-9`, `dir="ltr"` | Latin numerals |
| Progress track | `ink-4` | `radius-full`, `h-1` |
| Progress fill | `accent` | Grows via logical `inline-size` |
| Progress `%` label | `text-xs`, `tabular-nums`, `accent`, `dir="ltr"` | |
| Done status | `positive` | Check glyph |
| Failed / rejection | `negative` | `role="alert"` message + retry |
| Remove control | `ink-9` → `ink-11` on hover | `X` glyph |
| Thumbnail (preview) | `radius-md` | Image/PDF ingest only |
| Drag-tint transition | `motion.fast` | Reduced-motion → instant |

# Accessibility

Inherits the WCAG 2.1 AA baseline; the uploader's non-negotiables (control keyboard/SR detail in
[`../../frontend/components/INPUTS.md → Accessibility`](../../frontend/components/INPUTS.md)):

- **Drag-drop is never the only path.** The drop surface is a real labelled `<input type="file">`;
  `Enter`/`Space` opens the browse dialog, so a keyboard-only or assistive-tech user can always add a file.
- **Rejections are announced.** A type/size rejection renders `role="alert"` `text-negative` copy naming the
  reason — never a silent drop that leaves the user wondering why nothing happened.
- **Progress is a real `progressbar`.** Each uploading row exposes `role="progressbar"` with
  `aria-valuenow/min/max`, and the `%` is real text — status is not conveyed by the bar fill alone.
- **Status is glyph + meaning, not color alone.** Done is a `positive` **check**, failed is a `negative`
  **alert glyph + "Retry" text** — the same information survives grayscale, satisfying WCAG 1.4.1.
- **Meaning icons never flip; the upload cloud, the check, the alert stay put in RTL** (see Theming).
- **Contrast, verified both themes.** `text-negative` on the `ink-2` surface, the `accent` progress fill,
  and the dashed `ink-7` border all meet the AA / 1.4.11 targets in light and dark
  ([`../COLOR_SYSTEM.md → WCAG AA Contrast`](../COLOR_SYSTEM.md)).
- **Reduced motion** removes the drag-tint transition and any shimmer — the state still changes; only the
  animation is dropped.
- **A permission-disabled uploader** carries a tooltip naming the required key, never an unexplained grey
  box.

# Theming, Dark Mode & RTL

**Tokens only.** No raw hex, no numbered legacy step, no `dark:` variant against a raw color. The dashed
surface is `border-ink-7 bg-ink-2/50`; drag-active is `border-accent bg-accent-subtle`; progress is
`bg-accent` on `bg-ink-4`; rejection is `text-negative`.

**Dark mode is a pure remap** under `class="dark"`: the identical markup renders correctly dark. Per
[`../COLOR_SYSTEM.md → Light ↔ Dark Remap`](../COLOR_SYSTEM.md), the drag-active `accent-subtle` and the
`accent` progress fill **lift rather than saturate** against the darker canvas, so the drop target reads as
gently distinct instead of glowing; the dashed `ink-7` border re-derives from the dark ink scale and keeps
its non-text contrast.

**RTL uses logical properties exclusively:**

- The `FileRow` layout (`gap`, the trailing status/remove cluster) and the progress fill (`inline-size`, not
  `width`) mirror automatically with `dir="rtl"`; the progress bar fills from the logical start.
- **File sizes and the progress `%` never mirror** — `formatBytes(...)` and the `%` label render `dir="ltr"`
  with `latn` numerals, so `1.4 MB` and `72%` read left-to-right inside a right-to-left row.
- **Meaning icons never flip.** The `UploadCloud`, the `positive` check, and the `negative` alert are
  meaning glyphs, not direction glyphs — they never `rtl:rotate-180`. Only a directional affordance (were
  one present) would flip.

# Do / Don't

| Do | Don't |
|---|---|
| Offer click-to-browse and keyboard alongside drag-drop | Make drag-drop the only way to add a file |
| Announce every type/size rejection with `role="alert"` | Silently drop a rejected file |
| Signal drag-active with `border-accent` + `accent-subtle` | Recolor the whole region to a new hue |
| Show a real `role="progressbar"` with a text `%` | Convey upload progress by bar fill alone |
| Pair done/failed with a check / alert **glyph** | Convey upload status by color alone |
| Keep a failed file in the list with a Retry | Clear a failed file and lose the user's selection |
| Upload to R2 via a presigned URL the consumer requests | Post to a static path or bake a bucket URL in the client |
| Treat client `accept`/size as a fast-fail courtesy | Trust client validation as a security boundary |
| Render sizes and `%` `dir="ltr"` with `latn` numerals | Let numerals reverse inside an RTL row |
| Fill the progress bar with logical `inline-size` | Fill it with physical `width` (breaks RTL) |

# Usage & Composition

```tsx
// Document Center batch ingest — the dropzone hands accepted files up; the consumer owns the presigned upload.
'use client';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { FileRow } from '@/components/ui/file-row';
import { useDocumentUpload } from '@/hooks/documents/use-document-upload';

export function StatementIngest() {
  const { files, accept, upload, retry, remove } = useDocumentUpload({ resource: 'documents/bank-statements' });
  return (
    <div className="space-y-3">
      <FileDropzone
        accept={['application/pdf', '.csv']}
        maxSizeMb={10}
        multiple
        onFiles={(picked) => picked.forEach(upload)}   // upload() presigns R2, then PUTs directly
      />
      {files.length > 0 && (
        <ul className="space-y-2">
          {files.map((f) => (
            <FileRow key={f.id} file={f.file} progress={f.progress} status={f.status}
                     onRetry={() => retry(f.id)} onRemove={() => remove(f.id)} />
          ))}
        </ul>
      )}
    </div>
  );
}
```

**Composition boundaries.**

- Inside a form, the dropzone sits in a `<FormControl>` and the `<Form>` wrapper owns its ARIA; the control
  stays presentational, exactly as every other input in
  [`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md).
- The rejection alert and the failed-upload retry share the `negative`/`role="alert"` treatment with the
  form-error atoms; the done/failed status glyphs share the `positive`/`negative` semantic tokens with
  [`./TABLE.md`](./TABLE.md)'s status system.
- Document ingest feeds the AI extraction pipeline downstream; an AI-extracted result is then presented as a
  proposal card ([`./CARD.md`](./CARD.md)) with its confidence — the uploader itself never asserts a parsed
  fact, it only delivers the bytes.

For the control's place in the form system and the client-validate-then-server-re-check contract, see
[`../../frontend/components/INPUTS.md → FileDropzone`](../../frontend/components/INPUTS.md).

# End of Document
