# Form Patterns — QAYD Design System
Version: 1.0
Status: Design Specification
Module: Design System
Submodule: Patterns / FORMS
---

# Purpose

This document is the design-system contract for QAYD's **form UX patterns** — the recurring, composed
arrangements that turn a page of inputs into a submittable, recoverable, accessible form: the single-column
layout, field grouping into sections, inline validation timing, the React Hook Form + Zod architecture, the
dirty-state guard, the multi-step wizard form, autosave/draft persistence, field arrays (the journal-line
grid), RBAC-driven disabled/read-only fields, and the error-summary-plus-focus contract on a failed submit.

A pattern here is a *composition* of primitives, not a primitive itself. This document defers the atomic
controls to their own specs — the input catalogue to [`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md),
the `Select`/`Combobox` to [`../components/SELECT.md`](../components/SELECT.md), the date controls to
[`../components/DATE_PICKER.md`](../components/DATE_PICKER.md) — and defers the full form *system* (the
`<Form>` wrapper set, the submission paths, the server-error mapping helper, the testing matrix) to
[`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md), which is the app-level authority
this document restates at the pattern layer. Where a prop, endpoint, or exact wrapper behavior is needed,
that document governs; this one names the *shapes* and their token, motion, and accessibility rules.

One principle underwrites every pattern below and is worth stating once: **a QAYD form validates client-side
only to fail fast.** It rejects an obviously malformed submission before spending a round-trip, and it puts
the error next to the field that caused it — but Laravel re-validates every field of every submission
unconditionally and is the only party whose answer can persist anything. A client-side rule the backend does
not also enforce is a bug, not a feature. And where a form surfaces an AI-drafted value, that value renders
with its confidence and reasoning and is never auto-committed — the form only ever submits a human's decision.

Every value below references [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md): the `ink-1…12` scale, the single
brass `accent` focus ring, `negative` for the invalid state, `radius-md`/`radius-lg`, and the `motion.*`
durations.

> **Token reconciliation.** [`../../frontend/components/FORMS.md`](../../frontend/components/FORMS.md) and
> [`../../frontend/components/INPUTS.md`](../../frontend/components/INPUTS.md) are pre-canonical app drafts
> that name tokens in the older `ink-150` / `ink-500` / `ink-700` / `ink-950` / `accent-600` / `danger`
> scheme. This document is canonical and uses the [`../DESIGN_TOKENS.md`](../DESIGN_TOKENS.md) names: the
> draft's `ink-150` is `ink-6`, its `ink-500` is `ink-9`, its `ink-700`/`ink-950` are `ink-10`/`ink-12`,
> its `accent-600` is `accent`, and its `danger` is `negative`. Where a draft's literal class disagrees
> with a token here, the token here governs and the conflict is raised in review.

# When to Use

| Pattern | Use when | Do not use when |
|---|---|---|
| Single-column layout | Any form a user reads top-to-bottom and completes once | A dense data grid (that is a `DataTable`, not a form) |
| Field grouping / sections | One record has clusters of related fields (Identity, Locale, Registration) | Three or four fields — grouping them is over-structure |
| Inline validation (on-blur) | The default for every field | A live cross-field invariant (use on-change there only) |
| RHF + Zod | Every form with more than a trivial field or any server round-trip | A single one-field Server-Action toggle that can survive without JS |
| Dirty-state guard | A form whose in-progress values would be lost to an accidental exit | A read-only view, or a form that autosaves every change already |
| Multi-step wizard form | One long record split into reviewable stages | Independent settings that don't share a submit |
| Autosave / draft | A long-lived composition worth persisting between sittings | A quick create where a stray draft is more hazard than help |
| Field arrays | A record with a repeating child collection (journal lines, invoice items) | A fixed, known set of fields |
| RBAC disabled/read-only | A role may see a field or action but not change/use it | A field no role should ever see (omit it entirely) |
| Error summary + focus | Any multi-field form where a failed submit could strand the user | A single-field form where the one error is unmissable |

# Anatomy

A QAYD form is four concentric layers, each with one job, wrapped in a single-column body with a right-aligned
action row.

```
┌─ <form> (noValidate) ────────────────────────────────────────────┐
│  ┌ Section — "Identity" (display-sm) ───────────────────────────┐ │
│  │  FormField → FormItem → FormLabel                            │ │
│  │                       → FormControl → <Input/>   (one input) │ │
│  │                       → FormDescription (optional)           │ │
│  │                       → FormMessage   (role="alert")         │ │
│  └──────────────────────────────────────────────────────────────┘ │
│  ┌ Section — "Locale & Fiscal" ─────────────────────────────────┐ │
│  │  … more fields …                                             │ │
│  └──────────────────────────────────────────────────────────────┘ │
│                                            [ Cancel ]  [ Save ]    │ ← action row, justify-end
└────────────────────────────────────────────────────────────────────┘
```

| Layer | Element | Responsibility |
|---|---|---|
| Zod schema | `lib/schemas/<entity>.ts` | Single source of truth for shape + fail-fast rules; mirrors the Laravel `FormRequest` field-for-field |
| `react-hook-form` | `useForm` instance | Owns live values, dirty/touched state, per-field errors — the only place a form's live values live |
| `<Form>` wrapper set | shadcn/ui + Radix | Wires each field's `id`/`for`/`aria-describedby`/`aria-invalid` so no field author hand-writes ARIA |
| Submission path | `useMutation` or Server Action | Optimistic/inline-recovery vs. simple round-trip |

Every field composes the same block — a `<FormField>` naming one schema key, an `<FormItem>` grouping label,
control, description, and message. A field that departs from that shape is a review red flag: it almost
always means ARIA is being hand-wired or an error is rendered somewhere a screen reader will not find it.

# Variants

## Single-column layout with sections

The default layout is one column. A form never runs two independent fields side by side just because there is
horizontal room; the only two-up case is a genuinely-paired field (a bilingual `name_en` / `name_ar` pair, a
from/to range) inside a `grid-cols-1 sm:grid-cols-2`. Sections are titled `display-sm` groups separated by
`space-y-8`, fields within a section by `space-y-6`.

```tsx
// A sectioned single-column form (shape — full system in FORMS.md)
<form onSubmit={form.handleSubmit(onSubmit)} className="space-y-8" noValidate>
  <section className="space-y-6">
    <h2 className="font-display text-lg text-ink-11">{t('identity')}</h2>
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <FormField control={form.control} name="name_en" render={({ field }) => (
        <FormItem>
          <FormLabel>{t('nameEn')}</FormLabel>
          <FormControl><Input {...field} autoComplete="off" /></FormControl>
          <FormMessage />
        </FormItem>
      )} />
      <FormField control={form.control} name="name_ar" render={({ field }) => (
        <FormItem>
          <FormLabel>{t('nameAr')}</FormLabel>
          <FormControl><Input {...field} dir="rtl" lang="ar" /></FormControl>
          <FormMessage />
        </FormItem>
      )} />
    </div>
  </section>

  <div className="flex justify-end gap-2">
    <Button variant="ghost" type="button" onClick={onCancel}>{t('cancel')}</Button>
    <Button type="submit" loading={mutation.isPending} disabled={mutation.isPending}>{t('save')}</Button>
  </div>
</form>
```

## The RHF + Zod pattern

Each form has exactly one Zod schema, mirroring the backend `FormRequest`. Two rules keep the mirror honest:
**error messages are i18n keys, not literals** (`'nameRequired'`, resolved at render), and **the schema never
encodes a rule the backend does not enforce** — it validates *shape*, the server owns *policy*.

```tsx
// lib/schemas/customer.ts — mirrors App\Http\Requests\StoreCustomerRequest
export const customerSchema = z.object({
  name_en: z.string().min(1, 'nameRequired').max(255),
  name_ar: z.string().min(1, 'nameRequired').max(255),
  currency_code: z.string().length(3),                       // server checks it is an enabled company currency
  credit_limit: z.string().regex(/^\d+(\.\d{1,4})?$/, 'invalidAmount').default('0.0000'),
  email: z.string().email('invalidEmail').optional().or(z.literal('')),
});
export type CustomerFormValues = z.infer<typeof customerSchema>;
```

A policy rule ("credit limit below the company ceiling") lives on the server and comes back as a `422`, mapped
onto the field exactly like a shape error — the user cannot tell which layer rejected them, which is correct.

## Inline validation timing

`mode: 'onBlur'` is the QAYD default — validating on every keystroke reads as nagging; validating only on
submit hides errors too long. `onChange` is reserved for a live cross-field invariant (the journal balance).

| Trigger | Mode | Rationale |
|---|---|---|
| Field leave | `onBlur` (default) | Fail fast without nagging mid-entry |
| Cross-field invariant | `onChange` | The balance banner must update as the user types the offsetting line |
| Submit | Always re-validates the whole form | The final gate before the network call |
| Server `422` | On the next render, mapped onto fields | Policy errors the client could not know |

## The dirty-state guard

A form with unsaved changes must survive an accidental route change, browser back, tab close, or drawer
dismiss. A single `useFormGuard(isDirty)` hooks all four exits; `isDirty` is RHF's own `formState.isDirty`,
false again after a successful submit resets the form, so the guard never fires on a saved form.

```tsx
// The guard pattern (shape — full hook in FORMS.md)
export function useFormGuard(isDirty: boolean) {
  const confirm = useConfirmDialog();
  useEffect(() => {
    if (!isDirty) return;
    const onBeforeUnload = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [isDirty]);
  return async function confirmDiscard() {
    if (!isDirty) return true;
    return confirm({ title: t('discardTitle'), description: t('discardBody'),
                    confirmLabel: t('discard'), tone: 'destructive-quiet' });
  };
}
```

The confirm copy is calm and specific ("You have unsaved changes. Discard them?"), never alarmist, and offers
Discard vs. Keep editing.

## Multi-step wizard form

A long record is one RHF instance with one schema, split into steps that each validate only their own slice
before advancing; the final submit re-validates the whole. See the pattern in full at
[`ONBOARDING_PATTERNS.md → The multi-step wizard`](./ONBOARDING_PATTERNS.md).

```tsx
const STEP_FIELDS: Record<Step, Path<FormValues>[]> = {
  identity: ['legal_name', 'name_ar', 'country_code'],
  fiscalYear: ['fiscal_year_start', 'fiscal_year_end'],
  review: [],
};
async function next() {
  const ok = await form.trigger(STEP_FIELDS[STEPS[step]]);
  if (ok) setStep((s) => s + 1);
}
```

- Step transitions cross-fade at `motion.base`, `prefers-reduced-motion`-gated — no slide.
- A step is complete only when its fields validate (read from `form.getFieldState`), never on visual progress
  alone — a back-edited step re-marks incomplete.
- The final review step is read-only, rendering the collected values via the same display components a detail
  page would, then one primary action fires the single create call. Server `422`s route back to the owning
  step and jump focus there.

## Autosave / draft

A form worth not losing autosaves a debounced, dirty-gated `PATCH` to the resource's own draft endpoint — not
a bespoke local-storage cache the server never sees — so a draft opened on another device is the same draft.

```tsx
// The autosave pattern (shape)
export function useAutosave<T extends FieldValues>(form: UseFormReturn<T>, cfg: AutosaveConfig) {
  const debounced = useDebouncedCallback(async (values: T) => {
    if (!form.formState.isDirty) return;
    await cfg.save(values);                 // PATCH .../drafts/{id}
    setSavedAt(new Date());
  }, cfg.delayMs ?? 2000);
  useEffect(() => form.watch((v) => debounced(v as T)).unsubscribe, [form, debounced]);
  return { savedAt };                        // rendered as a quiet "Saved · 12:04" caption
}
```

- Status is a single `ink-9` caption near the form title — never a toast per keystroke.
- Autosave saves a *draft*, never a *post* — it calls the draft endpoint and never triggers the
  balance-and-post path. Nothing commits without an explicit human action, and autosave is not that action.

## Field arrays (the journal-line grid)

The repeating-child form is `useFieldArray`. It adds three concerns over a flat form: per-row validation, a
cross-row invariant, and add/remove with a minimum count.

```tsx
// components/purchasing/bill-lines.tsx (field-array excerpt — shape)
const { fields, append, remove } = useFieldArray({ control: form.control, name: 'lines' });
const lines = form.watch('lines');
const total = lines.reduce((s, l) => s + Number(l.amount || 0), 0);   // display only; server re-sums

return (
  <div className="rounded-lg border border-ink-6">
    <table className="w-full text-sm">
      <tbody>
        {fields.map((row, i) => (
          <tr key={row.id} className="border-t border-ink-6">
            <td className="p-2">
              <FormField control={form.control} name={`lines.${i}.account_id`} render={({ field: f }) => (
                <FormItem><FormControl>
                  <AccountPicker value={f.value ?? null} onChange={f.onChange} postableOnly />
                </FormControl><FormMessage /></FormItem>
              )} />
            </td>
            <td className="p-2">
              <FormField control={form.control} name={`lines.${i}.amount`} render={({ field: f }) => (
                <FormItem><FormControl>
                  <CurrencyInput currencyCode={form.watch('currency_code')} value={f.value} onValueChange={f.onChange} />
                </FormControl><FormMessage /></FormItem>
              )} />
            </td>
            <td className="p-2">
              <Button type="button" variant="ghost" size="icon" disabled={fields.length <= 1}
                      onClick={() => remove(i)} aria-label={t('removeLine')}>
                <Trash2 className="h-4 w-4 text-ink-9" />
              </Button>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
    <Button type="button" variant="ghost" size="sm" onClick={() => append({})}>
      <Plus className="h-3.5 w-3.5" /> {t('addLine')}
    </Button>
  </div>
);
```

- The `min(2)` count is a schema rule (`lines: z.array(lineSchema).min(2, 'minimumTwoLines')`), and the remove
  button disables at the floor — one rule, enforced live and at submit.
- The cross-row invariant (debits equal credits) is a `superRefine` on the array, surfaced as a live balance
  banner and used to disable *posting* — never to block *saving a draft* (a draft may be unbalanced).
- Per-row server errors map onto `lines.<i>.<field>` paths, landing on the exact cell.
- `Enter` inside an amount cell does not submit — an accidental post from an amount field is a real hazard;
  the primary action is reached by `Tab`.

## RBAC-driven disabled / read-only

A field a role may see but not change is `readOnly`; a whole action the role lacks is `disabled` with a
permission tooltip; an action whose mere existence is sensitive is omitted.

```tsx
const canEditCreditTerms = usePermission('accounting.customer.manage_credit');
<FormField control={form.control} name="credit_limit" render={({ field }) => (
  <FormItem>
    <FormLabel>{t('creditLimit')}</FormLabel>
    <FormControl>
      <CurrencyInput currencyCode="KWD" value={field.value} onValueChange={field.onChange}
                     readOnly={!canEditCreditTerms} />
    </FormControl>
    {!canEditCreditTerms && <FormDescription>{t('creditLimitReadOnlyHint')}</FormDescription>}
    <FormMessage />
  </FormItem>
)} />
```

`readOnly` (not `disabled`) keeps a server-computed value reachable and copyable by keyboard and screen
reader. The client gate is courtesy — the mutation hook re-checks and the server's `403` is the final word.

## Error summary + focus on submit failure

A failed submit must not strand a keyboard or screen-reader user at the submit button with the errors
somewhere above. The first invalid field receives focus — from the client resolver via RHF's
`shouldFocusError`, and from a server `422` via the mapping helper's `shouldFocus: i === 0`.

```tsx
// Server 422 → fields, first error focused (shape — full helper in FORMS.md)
export function mapApiErrorsToForm<T extends FieldValues>(err: unknown, setError: UseFormSetError<T>): boolean {
  if (!(err instanceof ApiError) || err.status !== 422 || !err.errors) return false;
  Object.entries(err.errors).forEach(([field, messages], i) => {
    setError(field as Path<T>, { type: 'server', message: messages[0] }, { shouldFocus: i === 0 });
  });
  return true;   // caller then suppresses the generic toast; the message is already next to the field
}
```

Nested field-array paths (`lines.2.debit`) are understood by `setError` directly, so a per-line rejection
lands on the exact grid cell — never a generic form-top banner for a line-level problem.

# Composition

## Field ↔ control ↔ wrapper

Every input primitive is consumed inside a `<FormControl>` so the `<Form>` wrapper owns its ARIA and RHF owns
its value — the control stays presentational, its `invalid` prop driven from `fieldState.error`.

```tsx
<FormField control={form.control} name="amount" render={({ field, fieldState }) => (
  <FormItem>
    <FormLabel>{t('amount')}</FormLabel>
    <FormControl>
      <CurrencyInput currencyCode={form.watch('currency_code')} value={field.value}
                     onValueChange={field.onChange} invalid={!!fieldState.error} />
    </FormControl>
    <FormMessage />
  </FormItem>
)} />
```

## Submission-path choice

| Concern | Optimistic (`useMutation`) | Simple (Server Action) |
|---|---|---|
| Use when | Posting an entry, approving a proposal, any inline recovery in a drawer that must not remount | A settings toggle, a one-field save that can survive without JS |
| Pending | `mutation.isPending` drives one button's `loading` | `isPending` from `useActionState` |
| Errors | `try/catch` → `mapApiErrorsToForm` then toast fallback | Returned `state.errors` mapped on next render |
| Optimistic UI | Yes (`onMutate`/`onError` rollback) | Not available |

## Form in a dialog/drawer

A quick create/edit inside a `Sheet`/`Dialog` passes `confirmDiscard` to `onOpenChange` so an outside-click or
`Esc` asks before closing a dirty form, and keeps the surface mounted through a `422` so inline recovery works.

# States & Behavior

Every field renders one of seven states; the wrapper set makes them uniform so no two forms drift.

| State | Visual | Wiring |
|---|---|---|
| Default | `ink-6` border, `ink-12` text, `ink-9` placeholder | Baseline input |
| Focus | 2px `accent` ring, `ring-offset-2` | `focus-visible` only — keyboard, never a mouse-click ring |
| Filled | Default with a value | No special styling — filled is not "done," only non-empty |
| Disabled | `opacity-50`, `cursor-not-allowed`, no ring | RBAC disables carry a permission tooltip |
| Read-only | `ink-2` fill, `ink-10` text, still selectable | `readOnly`, not `disabled` — reachable for server-computed fields |
| Invalid | `negative` border, `aria-invalid`, `<FormMessage>` visible | Set by the resolver or by `mapApiErrorsToForm` |
| Loading | `Skeleton` at the control's height | Edit forms while the record loads |

Form-level states:

- **Submitting** — the primary button shows `loading` and disables; other buttons disable; fields stay
  editable unless the mutation is destructive (posting), where the whole form goes read-only for the
  round-trip to prevent a double edit racing the response.
- **Submit-blocked** — a live cross-field invariant fails; the primary button is `disabled` with its reason
  exposed via `aria-describedby` pointing at the invariant banner, never silently greyed.
- **Server-rejected** — a `422` maps field errors back, focuses the first, and fires no toast for
  field-level errors; a `409`/`403`/`5xx` becomes a toast, and a `409` shows the preserve-and-reapply banner
  ("This record changed since you opened it. Your edits are preserved below; please re-apply them.").
- **Double-submit** — the primary disables on `isPending` and the mutation is idempotency-keyed where the
  endpoint supports it, so a held Enter cannot post two entries.

# Content & Copy Guidance

| Surface | Guidance | Example |
|---|---|---|
| Field label | A noun, sentence case, no trailing colon | "Legal name" — not "Legal Name:" |
| Required marker | Text, not color-only | "Legal name (required)" or an asterisk with a legend |
| Helper text (`FormDescription`) | One line, states *why* or *what format* | "Used on invoices and legal documents." |
| Validation message | Names the fix, not just the fault | "Enter a valid amount (up to 4 decimals)." — not "Invalid." |
| Primary action | Names the outcome for a consequential write | "Post entry" / "Send for approval" — not always "Save" |
| Discard confirm | Calm, offers both paths | "You have unsaved changes. Discard them?" [Discard] [Keep editing] |
| Autosave caption | Quiet, factual | "Saved · 12:04" / "Saving…" |
| Conflict banner | States what happened and preserves work | "This record changed since you opened it. Your edits are preserved below." |
| AI-drafted field | Marked as a proposal, editable | Rendered with a `ConfidenceBadge`; submitting is the human's decision |

Error messages are i18n keys resolved at render, so one schema yields "Name is required" or "الاسم مطلوب"
with no second schema. Both dictionaries stay in lock-step — a key in one and missing in the other fails
`npm run i18n:check`. Arabic copy is the professional-Gulf register, authored in parallel, never
machine-translated.

# Accessibility

Forms are where accessibility is most often broken and most consequential — a mis-wired error is invisible to
a screen-reader user filing a tax return. The `<Form>` wrapper set exists to make the correct wiring the
default.

| Concern | Contract |
|---|---|
| Label association | Every control has a programmatic label via `<FormLabel>` → `htmlFor` → the field `id`; a placeholder is never the only label |
| Invalid state | An invalid field sets `aria-invalid="true"` on the control and links `<FormMessage>` via `aria-describedby` |
| Error text | `<FormMessage>` is `role="alert"` (a new error is announced), real text next to the field, never color-only and never a toast for a field-level error |
| Helper text | `<FormDescription>` is linked via `aria-describedby` alongside any error, so both are announced |
| Required fields | `aria-required` plus a visible non-color indicator |
| Focus on submit failure | The first invalid field receives focus — client resolver via `shouldFocusError`, server `422` via the mapping helper |
| Live cross-field state | The invariant banner is `aria-live="polite"`; a disabled primary action points `aria-describedby` at it, so the *reason* is available to AT |
| Disabled vs read-only | A readable-not-editable field is `readOnly` (reachable, copyable, announced), not `disabled` (skipped, dimmed) |
| Keyboard-only | Full tab order: header fields → field array → footer actions; `Add line` reachable in-grid; the discard-confirm dialog traps focus |
| Autofill | Finance-sensitive fields set `autoComplete="off"`; genuine contact fields use the correct token |

All of the above sit on the WCAG 2.1 AA floor. Under `prefers-reduced-motion`, the step cross-fade, the
posted-entry confirmation checkmark, and any row-entrance stagger collapse to instant state changes.

# Theming, Dark Mode & RTL

- **Forms hold no color of their own beyond the token set** — a field is `ink` scale plus the one `accent`
  focus ring plus `negative` for invalid, and nothing else. Dark mode is a token remap only; no form file
  contains a `dark:` variant against a raw color, and the invalid `negative` border keeps its AA contrast in
  both themes.
- **RTL is layout-only, via logical properties.** Field grids use `gap`/`grid-cols-*` (direction-agnostic);
  label and message alignment is `text-start`; input icon padding is `ps-*`/`pe-*`; a two-column pair mirrors
  automatically under `dir="rtl"`. Physical `ml-*`/`text-left` are banned in form source.
- **A bilingual name pair** sets `dir="rtl" lang="ar"` on the Arabic input explicitly, so Arabic text shapes
  and cursors correctly even while the surrounding UI is English.
- **Numeric fields never mirror their contents.** A `CurrencyInput` keeps its value `dir="ltr"` and
  right-aligned in both directions — a Kuwaiti amount reads `1,204.500` inside an RTL form, never
  digit-reversed. Dates are stored as ISO `YYYY-MM-DD` and displayed locale-aware with `latn` numerals.

# Do / Don't

| Do | Don't |
|---|---|
| Lay out one column with titled sections | Run independent fields side by side to fill horizontal space |
| Validate `onBlur` by default | Nag with `onChange` on every field, or hide errors until submit |
| Keep one Zod schema mirroring the `FormRequest` | Duplicate rules across two schemas, or diverge from the backend |
| Encode only *shape* client-side; let the server own *policy* | Ship a client rule the backend doesn't also enforce |
| Map a server `422` onto the exact field and focus the first | Dump server errors into a form-top toast |
| Guard a dirty form's four exits | Let an accidental navigation silently discard in-progress work |
| Autosave to the server draft endpoint | Cache drafts only in `localStorage` the server never sees |
| Post only on an explicit human action | Let autosave or an AI draft commit a post |
| Use `readOnly` for a see-not-edit field | Use `disabled`, which strips it from tab order |
| Disable `Enter`-to-submit inside an amount cell | Let a held Enter in a grid post the entry twice |

# End of Document
