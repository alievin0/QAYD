# Import Patterns — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / IMPORT
---

# Purpose

This document specifies the reusable **data-import UX patterns** QAYD composes from atomic components:
source selection, the file-upload dropzone, AI-assisted field mapping under human review, the
dry-run/preview validation report, conflict resolution, the human-gated commit, rollback, and the
progress feedback for a long-running job. A *pattern* here is a recurring composed shape — a stack of
`FileDropzone`, `Select`, `ConfidenceBadge`, `AiCardShell`, `Table`, `Progress`, `Button`, and
`AlertDialog` primitives arranged the same way every time — not a new primitive. It exists so that
importing a Chart of Accounts, a vendor list, a product catalogue, or a raw CSV all *feel* like one
motion, and so that the platform's single non-negotiable import rule is structurally true on every path:
**AI proposes the mapping; a human commits it — no AI output ever calls `mode: "commit"`.**

This is the design-system, token-and-shape companion to the flow and component specs that own the
behavior. The end-to-end migration journey, its five stages, every endpoint, error code, and threshold
are owned by [`../../frontend/flows/IMPORT_ACCOUNTING_SYSTEM_FLOW.md`](../../frontend/flows/IMPORT_ACCOUNTING_SYSTEM_FLOW.md);
the dropzone's atomic state contract is owned by [`../components/FILE_UPLOADER.md`](../components/FILE_UPLOADER.md);
the confidence/reasoning/human-in-the-loop grammar these patterns inherit is owned by
[`../components/AI_WIDGET.md`](../components/AI_WIDGET.md). Where this document is silent on a
screen-level fact, the flow doc governs; where it is silent on the dropzone's state machine, the
uploader doc governs. This document adds only the **pattern layer**: how the primitives stack, which
canonical token paints each stage, and how the mapping stage upholds the AI contract.

Every color, radius, and motion value references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the
warm `ink-1…12` scale, the single brass `accent` (rationed to the one primary action per stage and to
AI-touched elements), and the `positive`/`negative`/`warning` financial set. There is no import-specific
palette and no literal a token already covers.

> **Token-reconciliation note.** Some app-level docs this pattern ties to were authored against an
> earlier numeric-step palette (`accent-600`, `accent-100`, `ink-500`, `ink-700`, `ink-950`, `ink-150`,
> `text-caption`, `destructive-quiet`). This document uses the **canonical** names from
> [`../DESIGN_TOKENS.md → Reconciliation`](../DESIGN_TOKENS.md): `accent`/`accent-subtle`,
> `ink-1…ink-12`, `positive`/`negative`/`warning`, and the `text-*`/`display-*` type steps. Read a
> draft name as its canonical equivalent (`accent-600` → `accent`, `accent-100` → `accent-subtle`,
> `ink-500` → `ink-9`, `ink-700`→`ink-10`, `ink-950` → `ink-12`, `ink-150` → `ink-6`/`ink-7`,
> `text-caption` → `text-xs`, `destructive-quiet` → a quiet `negative`).

# When to Use

Use the import patterns when a user brings **structured external data into QAYD in bulk** — an export
from another accounting system (QuickBooks, Xero, SAP, Oracle, Odoo, Zoho, or a raw CSV/Excel), a vendor
or customer list, a product catalogue, a set of opening balances. Reach for a **single-record create
form**, not these patterns, when the user is entering one row by hand; reach for the **Document Center**
ingest ([`../../frontend/DOCUMENT_CENTER.md`](../../frontend/DOCUMENT_CENTER.md)) when the payload is a
*document* (a scan, a receipt, a statement PDF) whose fields are extracted, not column-mapped.

| Use an import pattern | Use something else |
|---|---|
| A multi-row `.csv`/`.xlsx` export maps onto a module's fields | One record → a create form/route |
| Migrating a whole Chart of Accounts with opening balances | A single account → the account create dialog |
| A vendor/customer/product list from a prior system | A scanned invoice → Document Center extraction |
| Any batch that must be validated, previewed, then committed | A fire-and-forget toggle → a plain mutation |

The deciding question is *"does this need a dry-run before it is trusted?"* — a bulk write that a human
should preview and reversibly roll back is an import; a single validated write is a form.

# Anatomy

The import pattern is a five-stage `WizardShell`, forward-linear through the dry-run and **gated at
commit by an explicit human click**. Every stage is one composed region over the shared wizard chrome
(a `WizardProgressRail` + a stage body + a footer action row whose one primary action is the single
`accent` button on the stage).

```
┌─ WizardProgressRail ─ 1 Source ─ 2 Mapping ─ 3 Validate ─ 4 Commit ─ 5 Result ─┐
│                                                                                  │
│  Stage body (one composed pattern per stage)                                     │
│                                                                                  │
│  1 Source   → SourcePicker (wordmark grid) + FileDropzone                        │
│  2 Mapping  → ColumnMappingTable rows: [source col] → [Select] · ConfidenceBadge │
│               + MappingAssistant dock (AiCardShell + ReasoningPanel)             │
│  3 Validate → ValidationReport: "N succeed · M errors" + ImportErrorTable        │
│               + FraudFlagCard (negative) · acknowledge checkbox                   │
│  4 Commit   → ImportProgressCard (Progress bar, live tick)                        │
│  5 Result   → ResultSummary counts + RollbackConfirmDialog + reconcile checklist │
│                                                                                  │
├──────────────────────────────────────────────────────────────────────────────┤
│  [ Back ]                                            [ one accent primary action ] │
└──────────────────────────────────────────────────────────────────────────────┘
```

| Region | Primitives | Rule |
|---|---|---|
| Progress rail | `WizardProgressRail` (a `Stepper`) | Renders the five stages; mirrors in RTL with zero conditional code |
| Source | `SourcePicker` (card grid of plain wordmarks — no third-party logos) + `FileDropzone` | Upload is presigned direct-to-R2; nothing is written to the ledger |
| Mapping | `ColumnMappingTable` (`Table` of per-row `Select`) + `ConfidenceBadge` + `MappingAssistantDock` (`AiCardShell` + `ReasoningPanel`) | The one place AI does real work; confidence + reasoning always shown |
| Validate | `ValidationReport` + `ImportErrorTable` + `FraudFlagCard` | Server-side dry-run; writes nothing; conflicts + flags surface here |
| Commit | `ImportProgressCard` (`Progress` + live tick) | The single human-gated write |
| Result | `ImportResultSummary` + `RollbackConfirmDialog` | Counts, the reversible rollback affordance, the reconcile checklist |

The mapping row is the load-bearing shape: `source column → target-field Select`, with the
`ConfidenceBadge` rendered **next to** the `Select`, never inside it — a suggestion is a value a human
edits, and its certainty is metadata on that value, not part of it.

# Variants

| Axis | Values | Effect |
|---|---|---|
| Entry point | onboarding-embedded / standalone / module deep-link / resume | Same five-stage pipeline; only the container and pre-seeded source differ |
| Source kind | pre-trained ERP / generic CSV-Excel / another QAYD company | Pre-trained sources pre-fill mapping at higher confidence; generic runs the same pipeline at "honest degradation" (more rows land at "needs manual mapping") |
| Mapping density | `default` / `compact` | `compact` in an onboarding `WizardSheet`; `default` on the full-page `/import-export` route |
| Fraud flag | absent / present | A present flag inserts a `negative`-token `FraudFlagCard` + a mandatory acknowledgment checkbox before Commit enables |
| Terminal state | `completed` / `completed_with_errors` | `completed_with_errors` is a normal partial-success terminal state, styled `warning`, never an error screen |
| Rollback | offered / withheld | Offered only within the module's rollback window and while no created row was referenced downstream |

The AI-assisted mapping is **not** a style toggle: whether a `Do it`-style auto-commit ever renders is
structurally `false` for every import — there is no confidence at which the machine commits, so the
mapping stage instantiates the platform's proposal contract with the auto-execute half deliberately
absent.

# Composition

## Stage 1 — Source selection + upload

```tsx
// The Source stage: pick the target module + source system, then drop the file.
// Nothing is written; POST .../import (mode: "preview") only stages a batch.
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Combobox } from '@/components/ui/combobox';
import { cn } from '@/lib/utils';

export function ImportSourceStep({ sources, onPickModule, onPickSource, onFiles, selectedSource }: ImportSourceStepProps) {
  return (
    <div className="space-y-6">
      <Combobox label="Import into" options={moduleOptions} onValueChange={onPickModule} /> {/* permission-filtered */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        {sources.map((s) => (
          <button
            key={s.code}
            type="button"
            onClick={() => onPickSource(s.code)}
            aria-pressed={selectedSource === s.code}
            className={cn(
              'rounded-lg border border-ink-6 bg-ink-2 p-4 text-start text-sm text-ink-11',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
              selectedSource === s.code && 'border-accent bg-accent-subtle',
            )}
          >
            {s.wordmark} {/* plain text wordmark — never a third-party logo */}
          </button>
        ))}
      </div>
      <FileDropzone
        accept={['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '.csv']}
        maxSizeMb={20}
        onFiles={onFiles}   // presigns R2, PUTs directly; ≤20MB / ≤50,000 rows enforced before parse
      />
    </div>
  );
}
```

The dropzone is the [`../components/FILE_UPLOADER.md`](../components/FILE_UPLOADER.md) primitive verbatim:
dashed `ink-7` border on an `ink-2/50` fill, `accent` + `accent-subtle` on drag-active, an announced
`role="alert"` on a `FILE_TOO_LARGE`/`UNSUPPORTED_FORMAT` rejection. A rejected file never enters the
list silently.

## Stage 2 — AI-assisted mapping under human review

This is the stage the AI contract governs. Each row pairs a detected source column with a target-field
`Select`; the `ConfidenceBadge` sits beside the `Select`; a suggestion **at or below 0.5 confidence**
never pre-fills a value and is flagged "needs manual mapping."

```tsx
// One ColumnMappingTable row: source → target Select, with confidence AS METADATA beside the control.
import { Select } from '@/components/ui/select';
import { ConfidenceBadge, normalizeConfidence } from '@/components/ai/confidence-badge';

const NEEDS_MANUAL = 0.5; // below this, never pre-fill — the human maps it by hand

export function ColumnMappingRow({ column, fields, onMap }: ColumnMappingRowProps) {
  const confidence = normalizeConfidence(column.suggestionScore, 'percentage'); // 0–100 → 0–1, once
  const prefill = confidence > NEEDS_MANUAL ? column.suggestedFieldKey : undefined;
  return (
    <tr className="border-b border-ink-6">
      <td className="py-2 ps-3 text-sm text-ink-11">
        <span dir="auto" className="font-mono">{column.sourceLabel}</span>
        <span className="ms-2 text-xs text-ink-9" dir="ltr">e.g. {column.sample}</span>
      </td>
      <td aria-hidden className="px-2 text-ink-8"><span className="rtl:rotate-180 inline-block">→</span></td>
      <td className="py-2 pe-3">
        <div className="flex items-center gap-2">
          <Select defaultValue={prefill} onValueChange={(v) => onMap(column.id, v)}>
            {fields.map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
          </Select>
          {column.suggestedFieldKey && (
            // Badge is NEXT TO the Select, never inside it — a suggestion is an editable value.
            <ConfidenceBadge confidence={confidence} size="sm" reasoning={column.reasoning} />
          )}
          {confidence <= NEEDS_MANUAL && (
            <span className="text-xs text-warning">Needs manual mapping</span>
          )}
        </div>
      </td>
    </tr>
  );
}
```

The `MappingAssistantDock` narrates the single lowest-confidence or most-consequential suggestion through
`AiCardShell` + `ReasoningPanel`, one message at a time — never a wall of text. It offers exactly one
bulk affordance, **"Apply all suggestions above 90%,"** which still leaves every value individually
editable and still requires an explicit **Validate** click afterward. An AI-translated `name_ar` for a
source with no Arabic field renders labeled *"AI-translated — review before import"* with accept/edit —
**never silently written to the commit payload**.

```tsx
// MappingAssistantDock — the AI narrates its work; it never commits it.
import { AiCardShell } from '@/components/ai/ai-card-shell';
import { Button } from '@/components/ui/button';

export function MappingAssistantDock({ decision, onApplyHighConfidence, onValidate, canValidate }: MappingAssistantProps) {
  return (
    <aside className="rounded-lg border border-ink-6 bg-ink-2 p-4">
      <AiCardShell decision={decision} severity="none">
        <p className="text-sm text-ink-12">{decision.recommended_action.action}</p>
      </AiCardShell>
      <div className="mt-3 flex items-center justify-between gap-2">
        <Button variant="secondary" size="sm" onClick={onApplyHighConfidence}>Apply all suggestions above 90%</Button>
        {/* The one accent action on this stage. There is NO "Do it" / auto-commit — ever. */}
        <Button size="sm" disabled={!canValidate} onClick={onValidate}>Validate</Button>
      </div>
    </aside>
  );
}
```

## Stage 3 — Dry-run preview + validation report + conflict resolution

The dry-run re-runs the identical server-side per-row validation and **writes nothing**, returning
`rows_valid` / `rows_invalid`. The report leads with a plain count; the downloadable `ImportErrorTable`
is a semantic `<table>` pairing a `negative` border with an error-code column and a "Required" `Badge` —
color is never the only signal. A duplicate-within-batch natural key is a *batch-level* rejection, fixed
before proceeding; a Fraud Detection flag is a `negative`-token card that **never hard-blocks** but gates
Commit behind an acknowledgment checkbox.

```tsx
// ValidationReport + the Fraud flag gate. Commit stays disabled until ≥1 valid row AND every flag ack'd.
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { AiCardShell } from '@/components/ai/ai-card-shell';

export function ValidationReport({ result, fraudFlag, acknowledged, onAcknowledge }: ValidationReportProps) {
  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Badge tone="positive">{result.rows_valid} will succeed</Badge>
        {result.rows_invalid > 0 && <Badge tone="warning">{result.rows_invalid} have errors</Badge>}
        {result.errors_preview_url && (
          <a href={result.errors_preview_url} className="text-sm text-accent underline">Download error report</a>
        )}
      </div>
      {fraudFlag && (
        // A negative-token card — visible, never silent, never an auto-veto that strands a migration.
        <AiCardShell decision={fraudFlag} severity="critical">
          <p className="text-sm text-ink-12">{fraudFlag.reasoning}</p>
          <label className="mt-3 flex items-center gap-2 text-sm text-ink-11">
            <Checkbox checked={acknowledged} onCheckedChange={(v) => onAcknowledge(Boolean(v))} />
            I've reviewed this flag
          </label>
        </AiCardShell>
      )}
    </div>
  );
}
```

## Stage 4 — Commit + long-running-job progress

Commit is the **single human-gated write** — enabled only once ≥1 row validates and every flag is
acknowledged. It fires `mode: "commit"` (`202 Accepted`) and the `ImportProgressCard` fills from a live
progress tick, reconciled by a full invalidation at the terminal state, with a 2-second poll as the
reconnect fallback.

```tsx
// ImportProgressCard — the long-running-job feedback pattern. Announces politely, throttled.
import { Progress } from '@/components/ui/progress';

export function ImportProgressCard({ committed, total, status }: ImportProgressProps) {
  const pct = total > 0 ? Math.round((committed / total) * 100) : 0;
  return (
    <div className="rounded-lg border border-ink-6 bg-ink-2 p-4">
      <p className="text-sm text-ink-11" aria-live="polite">
        <span dir="ltr" className="tabular-nums">{committed}</span> of{' '}
        <span dir="ltr" className="tabular-nums">{total}</span> rows committed
      </p>
      <Progress value={pct} className="mt-2" aria-label={`Import ${pct}% complete`} />
    </div>
  );
}
```

## Stage 5 — Result + rollback

The result stage shows created/updated/skipped counts and, within the module's rollback window, a
`RollbackConfirmDialog` that states the impact and requires a mandatory reason — the same
reason-on-every-reversal rule the approval patterns enforce. Rollback is the *reconciliation budget*, not
an error path; past the window or past first downstream use, corrections become ordinary edits.

# States & Behavior

Each stage ships an explicit treatment for every state; none conveys a state by color alone.

| Region | Idle / default | Loading | Error / conflict | Degraded (AI `503`) | Disabled (RBAC) |
|---|---|---|---|---|---|
| `SourcePicker` | Wordmark grid, none selected | — | `FILE_TOO_LARGE`/`UNSUPPORTED_FORMAT` → inline `role="alert"` with the exact ceiling | N/A (deterministic) | Source hidden if the module's `.import` key is absent |
| `FileDropzone` | Dashed `ink-7`, `ink-2/50` | Per-file `Progress` | Rejection announced; file kept with Retry | N/A | `opacity-50` + tooltip naming the key |
| `ColumnMappingTable` | Pre-filled `Select`s + badges | Skeleton rows | Unmapped required field blocks Validate with an inline reason | Suggestions absent; every `Select` starts empty for manual mapping | Read-only when the `.import` key is revoked mid-wizard |
| `MappingAssistantDock` | `AiCardShell` narration | `AiTypingIndicator` | — | Replaced by an "AI mapping unavailable — map columns manually" note; the wizard stays fully usable | — |
| `ValidationReport` | Counts + error link | Skeleton | `ImportErrorTable`, downloadable; loop back to Mapping costs nothing | Fraud flag absent; validation itself is server-side, unaffected | — |
| `ImportProgressCard` | `Progress` + live tick | It *is* the loading state | Cancel takes effect at the next 500-row boundary; committed batches remain | N/A | — |
| `ImportResultSummary` | Counts + rollback affordance | — | Partial-success `completed_with_errors`, styled `warning` | N/A | Rollback disabled with a tooltip if the window closed or a row was referenced |

**Absent ≠ disabled.** There is no `Do it`/auto-commit control anywhere in the import pattern — it is
*structurally absent*, not a disabled button with a re-enable path. A control disabled for a *permission*
reason (a revoked `.import` key mid-wizard) renders greyed with a tooltip naming the missing key, and the
job persists as `ready_to_commit`, resumable once the permission returns — no work is lost.

**Motion.** Stage transitions run under `motion.base`; the progress bar tween is `motion.moderate`; a
posted-import confirmation is a single checkmark that fades in and holds, never confetti. Every animation
reads `useReducedMotion()` and degrades to an instant state change.

# Content & Copy Guidance

- **Name the stage in the imperative, name the count in plain numbers.** "Map your columns," "Review 214
  rows," "Commit import." Counts are Western digits in a `dir="ltr"` span: "5,400 rows," "62%," "12MB."
- **A rejection states the ceiling, then the fix.** "This file is 24MB — the limit is 20MB. Split it and
  import in batches," not a bare "File too large."
- **A suggestion is labeled as a suggestion.** "AI-translated — review before import," "Needs manual
  mapping," never a value presented as already-set fact.
- **The Fraud flag acknowledgment is a sentence a human agrees to:** "I've reviewed this flag" — a real
  labeled checkbox, not a color cue.
- **The rollback reason is mandatory and specific.** The dialog states what will be reversed and how many
  rows, and Confirm stays disabled until a reason is typed.
- **`completed_with_errors` is framed as success with a footnote:** "212 of 214 rows imported — 2
  skipped, view details," never an error banner.
- **Copy is bilingual (`en`/`ar`) at authoring time.** Full sentences with interpolation are single keys
  so Arabic word order is correct; a one-sided key fails `i18n:check`.

# Accessibility

Target **WCAG 2.2 AA** in both themes and both directions.

- **No drag is ever required.** Mapping is a plain per-row `Select` and the dropzone is a real labelled
  `<input type="file">` — the whole wizard is keyboard-complete, deliberately sidestepping WCAG 2.2
  2.5.7 Dragging Movements.
- **Focus lands on each new stage's header** on every transition (`aria-live`), so a screen-reader user
  always knows which of the five stages they reached.
- **Progress is announced politely and throttled** — `aria-live="polite"` at most once per 5 seconds or
  per 10% boundary; a fast-ticking commit never spams the reader.
- **Color is never the only signal for a validation error** — the `ImportErrorTable` pairs a `negative`
  border with a "Required" `Badge` and an error-code column, as a plain semantic `<table>` with
  `<th scope="col">`, never an ARIA `grid`.
- **Confidence follows the never-color-alone rule** — the `ConfidenceBadge` beside each `Select` carries
  its band by fill + shape + a real percentage/label text node, surviving grayscale and dark mode.
- **The Fraud flag's acknowledgment is a real, labeled checkbox in the tab order before Commit** — the
  one gate between review and an irreversible write is unmissable.
- **Every AI value has a reachable "why"** — the mapping badge's `reasoning` tooltip is reachable on
  keyboard focus, and the `MappingAssistantDock`'s `ReasoningPanel` is a real `aria-expanded` disclosure.

# Theming, Dark Mode & RTL

**Tokens only.** Every surface resolves to a `--qayd-*`-backed Tailwind utility — the dropzone's dashed
`ink-7`/`ink-2`, the drag-active `accent`/`accent-subtle`, the progress `accent` on `ink-4`, the Fraud
card's `negative` edge, the wizard footer's one `accent` primary. No import surface contains a raw hex or
a `dark:` variant against a literal.

**Dark mode is a first-class remap, not an inversion** ([`../DESIGN_TOKENS.md → Light / Dark Remap`](../DESIGN_TOKENS.md)).
The drag-active `accent-subtle` and the progress `accent` fill **lift rather than saturate** against the
darker canvas so the drop target and the growing bar read as gently distinct, not glowing; the dashed
`ink-7` border re-derives from the dark ink scale and keeps its non-text contrast. The one accent stays
reserved for the stage's single primary action and for AI-touched elements; a Fraud flag is `negative` in
both themes, never the accent.

**RTL is one tree, mirrored by logical properties.** The `WizardProgressRail` mirrors with zero
conditional code; the mapping row's `source → target` chevron flips via `rtl:rotate-180` while the
`ConfidenceBadge` dot, the check, and the trash glyph stay put. Three things never mirror: every **row
count, percentage, and file size** renders inside a `dir="ltr"` `latn` span (a `62%` reads LTR inside
Arabic text); **source-system and file names** (QuickBooks, SAP, an uploaded filename) render
LTR-embedded mid-Arabic-sentence so the bidi algorithm never reorders their Latin structure; and an
**AI-translated `name_ar`** arrives already localized from the API — the frontend never machine-translates
a model's words. Logical Tailwind utilities only (`ms/me`, `ps/pe`, `text-start/end`); physical classes
are ESLint-banned.

# Do / Don't

| Do | Don't |
|---|---|
| Gate the single write behind an explicit human "Commit import" click | Add an "auto-import when confidence is high" setting anywhere |
| Render the `ConfidenceBadge` next to the mapping `Select` | Put the badge inside the `Select`, or threshold a raw `0–100` field un-normalized |
| Leave suggestions ≤0.5 confidence unfilled and flagged "needs manual mapping" | Silently pre-fill a low-confidence guess as if it were set |
| Label an AI-translated `name_ar` "review before import" with accept/edit | Write an AI translation straight into the commit payload |
| Make the Fraud flag a visible, acknowledged, non-blocking gate | Hard-block a legitimate migration on an anomaly flag, or wave it through silently |
| Run a dry-run that writes nothing before Commit | Commit rows the user never previewed |
| Keep `completed_with_errors` a normal partial-success terminal state | Render a partial success as an error screen |
| Offer rollback with a mandatory reason inside the window | Silently overwrite on a cross-batch key conflict |
| Announce rejections and progress politely, throttled | Convey a rejection or progress by color/bar-fill alone |
| Reference `--qayd-*`-backed utilities only | Ship a raw hex, a `dark:`-literal, or an import-specific palette |

# End of Document
