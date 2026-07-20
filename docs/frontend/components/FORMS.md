# Forms — QAYD Frontend
Version: 1.0
Status: Design Specification
Module: Frontend
Submodule: Components / FORMS
---

# Purpose

Every place a QAYD user types a fact the system will store — a journal entry's memo, a customer's
trade name, a bank transfer amount, a tax period's dates, a payroll run's cut-off — is a form, and
this document is the binding contract for how all of them are built. It generalizes the one worked
form the component catalogue already ships (`JournalEntryForm` in
[`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)) into the shared form *system*: the
React Hook Form + Zod architecture, the `<Form>` / `<FormField>` / `<FormLabel>` / `<FormControl>` /
`<FormMessage>` wrapper set every field composes from, the one-schema-per-form rule and how that one
schema is shared with the Laravel `FormRequest` it mirrors, the two submission paths (a TanStack
Query `useMutation` when the mutation needs optimistic UI or inline error recovery, a Server Action
when it does not), how a server validation error travels from the API envelope back onto the exact
field that produced it, the dirty-state guard that stops a half-finished form from being lost to an
accidental navigation, multi-step wizard forms, autosave/draft persistence, field arrays (the
journal-line grid being the canonical case), RBAC-driven disabled/read-only fields, and the
accessibility, RTL, and bilingual-error contracts every form inherits without re-deriving them.

Nothing in this document introduces a business rule. A QAYD form validates client-side **only to
fail fast** — to reject an obviously malformed or unbalanced submission before spending a network
round-trip on it, and to put the error next to the field that caused it rather than in a toast. The
Laravel backend re-validates every field of every submission unconditionally and is the only party
whose answer can actually persist anything; a client-side rule the backend does not also enforce is a
bug, not a feature (see [`../README.md`](../README.md) `# Overview`, constraint 1, and
[`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Finance Components → JournalEntryForm`,
which states the same for its balanced-lines check). Where a form surfaces an AI-drafted value — a
suggested account, a proposed memo, a forecast figure pre-filled into an input — that value is
rendered with its confidence and reasoning affordance and is **never** auto-committed: the form only
ever submits a human's decision about the draft, per constraint 2 of the same overview.

This document assumes [`INPUTS.md`](./INPUTS.md) (the input-primitive catalogue every field wraps),
[`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) (tokens, motion, the debit/credit color rule),
[`../ACCESSIBILITY.md`](../ACCESSIBILITY.md) (the WCAG 2.1 AA baseline), and the platform's
`docs/api/REST_STANDARDS.md` (the response envelope and the `422` validation-error shape) are open
alongside it.

# Anatomy & Variants

A QAYD form is four concentric layers, each with exactly one job:

1. **The Zod schema** (`lib/schemas/<entity>.ts`) — the single source of truth for the form's shape
   and its fail-fast rules, mirroring the backend `FormRequest` field-for-field. It is imported by
   the form, by the form's unit test, and by nothing that would let it drift.
2. **`react-hook-form`** — owns the form's in-progress values, dirty/touched state, and per-field
   errors. It is the only place a single form's live values ever live (never Zustand, never Context —
   see [`../README.md`](../README.md) `# Data Fetching & State Management`, the state-ownership
   table).
3. **The `<Form>` wrapper set** — the shadcn/ui + Radix accessibility layer that wires each field's
   label, control, description, and error message together with the correct `id`/`for`/
   `aria-describedby`/`aria-invalid` relationships, so no field author hand-writes ARIA.
4. **The submission path** — either a `useMutation` (optimistic / inline-error mutations) or a Server
   Action (simple, no-optimistic mutations), described in `# Validation & Behavior`.

## The `<Form>` wrapper set

QAYD adopts shadcn/ui's `Form` primitives verbatim in behavior (they are a thin, typed context over
`react-hook-form`'s `FormProvider` + `Controller`) and re-skins them to QAYD tokens. The set:

| Component | Renders | Responsibility |
|---|---|---|
| `<Form>` | `FormProvider` context (no DOM of its own) | Makes the RHF instance available to every nested field without prop-drilling; wraps the native `<form>` in usage. |
| `<FormField>` | Nothing visible; a `Controller` + a per-field context providing a generated `id` | The single binding point between one schema key and one control. `name` is typed against `z.infer<typeof schema>`, so a typo is a compile error. |
| `<FormItem>` | `<div>` with the vertical field rhythm (`space-y-1.5`) | The layout box for one label + control + message stack; owns the generated `id` all the pieces share. |
| `<FormLabel>` | `<label htmlFor={id}>` | Associates the label with the control; turns `text-danger` when the field is invalid. |
| `<FormControl>` | A `Slot` that forwards `id`, `aria-describedby`, `aria-invalid` onto its single child input | The reason an `<Input>` inside it needs no ARIA props of its own. |
| `<FormDescription>` | `<p id={`${id}-description`}>` | Optional helper text, wired into the control's `aria-describedby`. |
| `<FormMessage>` | `<p id={`${id}-message`} role="alert">` | Renders the field's current error (translated), wired into `aria-describedby`; renders nothing when the field is valid. |

There is no visual "form" variant axis — forms inherit their density and columns from the page
template they sit in ([`../LAYOUT_SYSTEM.md`](../LAYOUT_SYSTEM.md)'s detail-page and wizard
templates). What *does* vary, and is documented here as the form-system's variant set, is the
**submission model** and the **shape** a form takes:

| Variant | When | Canonical example |
|---|---|---|
| Single-step form | One logical record, fits one view | Customer create, Settings panel |
| Field-array form | A record with a repeating child collection | `JournalEntryForm`'s line grid, an invoice's line items |
| Multi-step wizard | A long record split into reviewable stages | Company onboarding, a payroll-run setup |
| Autosaving/draft form | Long-lived composition worth persisting between sessions | A large manual journal entry, a bank-import mapping |
| Dialog/drawer form | A quick create/edit that must not lose state if the surface re-renders behind it | "New entry" modal, a row-detail `Sheet` edit |

A single form can combine variants (the manual `JournalEntryForm` is a field-array form that also
autosaves a draft); the axes are orthogonal, not mutually exclusive.

## The reference form

```tsx
// components/customers/customer-form.tsx
'use client';

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslations } from 'next-intl';
import {
  Form, FormField, FormItem, FormLabel, FormControl, FormDescription, FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { CurrencySelect } from '@/components/accounting/currency-select';
import { useCreateCustomer } from '@/hooks/accounting/use-customers';
import { useApiToast } from '@/hooks/use-api-toast';
import { customerSchema, type CustomerFormValues } from '@/lib/schemas/customer';
import { mapApiErrorsToForm } from '@/lib/forms/map-api-errors';

export function CustomerForm({ onCreated }: { onCreated?: (id: number) => void }) {
  const t = useTranslations('customerForm');
  const toast = useApiToast();
  const create = useCreateCustomer();

  const form = useForm<CustomerFormValues>({
    resolver: zodResolver(customerSchema),
    mode: 'onBlur',            // fail fast on leave, not on every keystroke — see Validation & Behavior
    defaultValues: { name_en: '', name_ar: '', currency_code: 'KWD', credit_limit: '0.0000' },
  });

  async function onSubmit(values: CustomerFormValues) {
    try {
      const customer = await create.mutateAsync(values);
      toast.success(t('created'));
      onCreated?.(customer.id);
    } catch (err) {
      // 422 field errors land on the exact fields; anything else becomes a toast — see Validation & Behavior
      if (!mapApiErrorsToForm(err, form.setError)) toast.fromApiError(err);
    }
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6" noValidate>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField
            control={form.control}
            name="name_en"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{t('nameEn')}</FormLabel>
                <FormControl><Input {...field} autoComplete="off" /></FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="name_ar"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{t('nameAr')}</FormLabel>
                <FormControl><Input {...field} dir="rtl" lang="ar" /></FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </div>

        <FormField
          control={form.control}
          name="currency_code"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t('currency')}</FormLabel>
              <FormControl><CurrencySelect value={field.value} onValueChange={field.onChange} /></FormControl>
              <FormDescription>{t('currencyHelp')}</FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="notes"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t('notes')}</FormLabel>
              <FormControl><Textarea rows={3} {...field} /></FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <div className="flex justify-end gap-2">
          <Button type="submit" loading={create.isPending} disabled={create.isPending}>
            {t('save')}
          </Button>
        </div>
      </form>
    </Form>
  );
}
```

Every field in QAYD looks like the blocks above: a `<FormField>` naming one schema key, an
`<FormItem>` grouping a `<FormLabel>`, a `<FormControl>` wrapping exactly one input primitive from
[`INPUTS.md`](./INPUTS.md), an optional `<FormDescription>`, and a `<FormMessage>`. A field that
departs from that shape is a red flag in review — it almost always means ARIA is being hand-wired or
an error is being rendered somewhere a screen reader will not find it.

# Props / API

## `useQaydForm` — the one form-initialization helper

Rather than let every form re-type the `useForm` options, QAYD wraps them in a thin
`useQaydForm(schema, options)` that fixes the resolver, the default validation mode, and the
Arabic-error message resolution (see `# i18n & Formatting`). It returns the standard RHF instance,
so everything below is just RHF's own API.

| Option | Type | Default | Description |
|---|---|---|---|
| `schema` | `ZodType<TValues>` | — (required) | The one schema for this form. Wired to `zodResolver` internally. |
| `defaultValues` | `DefaultValues<TValues>` | `{}` | Initial values; for edit forms, the server record mapped to the schema shape. |
| `mode` | `'onBlur' \| 'onChange' \| 'onSubmit'` | `'onBlur'` | When client validation runs. `'onBlur'` is the QAYD default — validating on every keystroke reads as nagging; validating only on submit hides errors too long. `'onChange'` is reserved for a live cross-field invariant (the journal balance). |
| `guardDirty` | `boolean` | `true` | Registers the form with the navigation dirty-guard (see `# Validation & Behavior`). |
| `autosave` | `AutosaveConfig \| undefined` | `undefined` | Enables draft autosave; see the field-array/autosave sections. |

## `<Form>` and field wrappers

| Component | Key props | Notes |
|---|---|---|
| `<Form>` | `...form` (the RHF instance, spread) | Provides context only. Always wraps a native `<form>` with `noValidate` so the browser's own bubble validation never competes with QAYD's messages. |
| `<FormField>` | `control`, `name`, `render` | `name` is `Path<TValues>` — typed, autocompleted, drift-proof. `render` receives `{ field, fieldState, formState }`. |
| `<FormLabel>` | `children` | Reads the field's invalid state from context; no `htmlFor` prop needed (derived from the field `id`). |
| `<FormControl>` | one child | Forwards `id`/`aria-*`; the child input takes no ARIA props of its own. |
| `<FormMessage>` | optional `children` override | With no children, renders the current field error translated; with children, renders a static message. |

## Submission-path props

| Concern | Optimistic path (`useMutation`) | Simple path (Server Action) |
|---|---|---|
| Hook / mechanism | `useCreate<Entity>()` / `useUpdate<Entity>()` in `hooks/<module>/*` | `use<Entity>Action` in `lib/actions/*` via React 19 `useActionState` |
| Pending state | `mutation.isPending` drives the specific button's `loading` | `isPending` from `useActionState` / `useFormStatus` |
| Error surface | `try/catch` → `mapApiErrorsToForm` then toast fallback | Returned `state.errors` mapped onto fields on the next render |
| Optimistic UI | `onMutate`/`onError` rollback + `useOptimistic` where a list must update instantly | Not available — Server Actions are for mutations that can wait for the round-trip |
| Use when | Posting an entry, approving a proposal, committing a match — anything needing inline recovery inside a drawer that must not remount | Dismissing a notice, toggling a setting, a one-field save that can survive without JS |

# States

Every QAYD form field renders one of seven states; the wrapper set makes them uniform so no two
forms drift.

| State | Visual | Wiring |
|---|---|---|
| Default | `ink-150` border, `ink-950` text, `ink-500` placeholder | Baseline `<Input>` from [`INPUTS.md`](./INPUTS.md). |
| Focus | 2px `accent-600` focus ring, `ring-offset-2` | `focus-visible:ring-2 focus-visible:ring-accent-600`; keyboard-only via `focus-visible`, never a mouse-click ring. |
| Filled | Same as default with a value present | No special styling — a filled field is not "done," only non-empty. |
| Disabled | `opacity-50`, `cursor-not-allowed`, no focus ring | `disabled` on the control; RBAC-gated disables carry a tooltip naming the required permission (see `# Validation & Behavior`). |
| Read-only | `bg-ink-100`, `ink-700` text, no border emphasis, still selectable/copyable | `readOnly` (not `disabled`) so the value is still reachable by keyboard and screen reader — used for server-computed fields (an entry's `journal_number`, a posted line). |
| Invalid | `danger` border, `aria-invalid="true"`, `<FormMessage>` visible | Set by RHF from the resolver or by `mapApiErrorsToForm` from a server `422`. |
| Loading | The whole form or a field replaced by a `Skeleton` at the control's height | Edit forms while the record loads; a single async-populated field (an AI-drafted memo streaming in). |

Form-level states mirror the field ones:

- **Submitting** — the primary button shows `loading` and disables; other buttons disable; fields are
  left editable unless the mutation is destructive (posting), where the whole form goes read-only for
  the round-trip to prevent a double edit racing the response.
- **Submit-blocked** — a live cross-field invariant fails (journal out of balance); the primary
  button is `disabled` with its reason exposed via `aria-describedby` pointing at the invariant
  banner, never silently greyed.
- **Server-rejected** — a `422` returned; field errors are mapped back (below), the first invalid
  field is focused, and no toast fires for field-level errors (the message is already next to the
  field); a `409`/`403`/`5xx` becomes a toast plus, for `409`, the preserve-and-reapply banner in
  `# Edge Cases`.

# Validation & Behavior

## One schema, shared with the backend `FormRequest`

Each form has exactly one Zod schema in `lib/schemas/<entity>.ts`. That schema is the mirror of the
Laravel `FormRequest` for the same resource — same field names, same optionality, same bounds — so a
value the client accepts is a value the server's rules were written to accept, and the client rejects
early only what the server would also reject.

```tsx
// lib/schemas/customer.ts
import { z } from 'zod';

// Mirrors App\Http\Requests\StoreCustomerRequest — every rule here has a Laravel counterpart.
export const customerSchema = z.object({
  name_en: z.string().min(1, 'nameRequired').max(255),
  name_ar: z.string().min(1, 'nameRequired').max(255),
  currency_code: z.string().length(3),                 // ISO 4217; server checks it is an enabled company currency
  credit_limit: z.string().regex(/^\d+(\.\d{1,4})?$/, 'invalidAmount').default('0.0000'),
  email: z.string().email('invalidEmail').optional().or(z.literal('')),
  notes: z.string().max(2000).optional(),
});

export type CustomerFormValues = z.infer<typeof customerSchema>;
```

Two rules keep the mirror honest. First, **error messages are i18n keys, not literals** —
`'nameRequired'`, not `'Name is required'` — resolved through the translation dictionary at render
(see `# i18n & Formatting`), so the same schema produces English and Arabic messages without a second
schema. Second, **the schema never encodes a rule the backend does not enforce.** A "credit limit
must be below the company ceiling" check belongs on the server (it depends on tenant state the client
does not authoritatively hold); the client schema validates *shape* (a well-formed amount string),
and the server owns *policy*. When a policy rule fails, it comes back as a server `422` and is mapped
onto the field exactly like a shape error would be — the user cannot tell which layer rejected them,
which is correct.

## Mapping a server `422` back onto fields

The API's validation-error envelope is `{ success: false, message, errors: { <field>: string[] }, request_id }`
(per `docs/api/REST_STANDARDS.md`). One helper turns that into RHF `setError` calls, keyed by the
same field paths the schema uses — including nested field-array paths like `lines.2.debit`:

```tsx
// lib/forms/map-api-errors.ts
import type { UseFormSetError, FieldValues, Path } from 'react-hook-form';
import { ApiError } from '@/lib/api/errors';

/** Returns true if it consumed field-level errors (caller then suppresses the generic toast). */
export function mapApiErrorsToForm<T extends FieldValues>(
  err: unknown,
  setError: UseFormSetError<T>,
): boolean {
  if (!(err instanceof ApiError) || err.status !== 422 || !err.errors) return false;
  const entries = Object.entries(err.errors);
  entries.forEach(([field, messages], i) => {
    setError(field as Path<T>, { type: 'server', message: messages[0] }, { shouldFocus: i === 0 });
  });
  return entries.length > 0;
}
```

`shouldFocus: i === 0` moves keyboard focus to the first server-rejected field, matching the
accessibility rule that a failed submit must not leave a keyboard or screen-reader user stranded at
the submit button with the errors somewhere above them. Server field paths use dot notation
(`lines.2.debit`) that RHF's `setError` understands directly, so a per-line server rejection lands on
the exact grid cell that produced it — never a generic form-top banner for a line-level problem.

## Optimistic mutation vs. Server Action — the decision

The split repeats [`../README.md`](../README.md) `# RSC vs. Client Components`: a mutation that needs
optimistic UI, inline error recovery inside a drawer/modal that must not remount, or a pending state
driving a spinner on one specific button is a **`useMutation`**; a mutation that can wait for the
round-trip and does not need optimistic UI is a **Server Action**.

```tsx
// Simple path — a settings toggle, no optimistic UI, survives without JS.
// lib/actions/update-notification-prefs.ts
'use server';
import { z } from 'zod';
import { notificationPrefsSchema } from '@/lib/schemas/notification-prefs';
import { apiServer } from '@/lib/api/server';

export async function updateNotificationPrefs(_prev: ActionState, formData: FormData): Promise<ActionState> {
  const parsed = notificationPrefsSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { ok: false, errors: parsed.error.flatten().fieldErrors };
  try {
    await apiServer.patch('/api/v1/users/me/preferences', parsed.data);  // forwards the session cookie
    return { ok: true };
  } catch (err) {
    return { ok: false, errors: apiErrorToActionErrors(err) };
  }
}
```

```tsx
// The component consuming it with React 19 useActionState.
'use client';
import { useActionState } from 'react';
import { updateNotificationPrefs } from '@/lib/actions/update-notification-prefs';

const [state, action, isPending] = useActionState(updateNotificationPrefs, { ok: false });
// <form action={action}> … field errors read from state.errors on the next render.
```

The optimistic path is the one QAYD's finance surfaces mostly use, because posting an entry or
approving a proposal must keep the drawer mounted, show a spinner on that one button, and recover
inline on a `422` — none of which a full-page Server Action transition allows. The `JournalEntryForm`
in [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) is the canonical optimistic-path form.

## Dirty-state navigation guard

A form with unsaved changes must not be lost to an accidental route change, a browser back, a tab
close, or a drawer/dialog dismiss. QAYD registers every guarded form with a single `useFormGuard`
that hooks all four exits:

```tsx
// hooks/use-form-guard.ts
'use client';
import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslations } from 'next-intl';
import { useConfirmDialog } from '@/hooks/use-confirm-dialog';

export function useFormGuard(isDirty: boolean) {
  const t = useTranslations('forms');
  const confirm = useConfirmDialog();

  // 1) Native tab-close / hard reload — the browser's own beforeunload prompt (text is UA-controlled).
  useEffect(() => {
    if (!isDirty) return;
    const onBeforeUnload = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = ''; };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [isDirty]);

  // 2) In-app navigation and 3) drawer/dialog dismiss both call this before proceeding.
  return async function confirmDiscard(): Promise<boolean> {
    if (!isDirty) return true;
    return confirm({
      title: t('discardTitle'),
      description: t('discardBody'),   // "You have unsaved changes. Discard them?"
      confirmLabel: t('discard'),
      tone: 'destructive-quiet',
    });
  };
}
```

- **In-app links** inside a guarded form route through the same `confirmDiscard()` before
  `router.push`.
- **A dialog/drawer form** passes `confirmDiscard` to the `Sheet`/`Dialog`'s `onOpenChange` so an
  outside-click or `Esc` asks before closing (Radix `Dialog` supports `onOpenChange` returning a veto
  by simply not calling the close).
- **The confirm copy is calm and specific** ("You have unsaved changes. Discard them?"), never
  alarmist, and offers Discard vs. Keep editing — matching the platform voice
  ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Voice & Microcopy`).

`isDirty` is RHF's own `formState.isDirty`, which is false again after a successful submit resets the
form, so the guard never fires on a form the user actually saved.

## Multi-step wizard forms

A long record (company onboarding, a payroll-run setup) is one form split into reviewable steps, not
several independent forms. The whole record is one RHF instance with one schema; each step validates
only its own slice before advancing, and the final submit re-validates the whole.

```tsx
// components/onboarding/company-wizard.tsx  (shape)
const STEPS = ['identity', 'fiscalYear', 'chartOfAccounts', 'review'] as const;
const STEP_FIELDS: Record<typeof STEPS[number], Path<CompanyFormValues>[]> = {
  identity: ['legal_name', 'name_ar', 'country_code'],
  fiscalYear: ['fiscal_year_start', 'fiscal_year_end'],
  chartOfAccounts: ['coa_template'],
  review: [],
};

async function next() {
  const ok = await form.trigger(STEP_FIELDS[STEPS[step]]);   // validate only this step's fields
  if (ok) setStep((s) => s + 1);
}
```

- **Step transitions** use `motion.base` cross-fade, `prefers-reduced-motion`-gated
  ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Motion`); no slide, matching the app's
  "no spatial navigation metaphor" rule.
- **A step is never marked complete on visual progress alone** — the stepper reads each step's
  validity from `form.getFieldState`, so a back-navigated step that was edited into an invalid state
  shows as incomplete again.
- **The final review step is read-only** — it renders the collected values via the same
  display components a detail page would, so the user reviews exactly what will be submitted, then one
  primary action fires the single create call. Server `422`s from that call route back to the
  owning step (the wizard maps each error field to its `STEP_FIELDS` entry and jumps focus there).
- **Wizard state survives a refresh** via the autosave draft mechanism below when the record is
  long-lived (onboarding); a short wizard keeps state in memory only.

## Autosave / draft persistence

A form worth not losing (a large manual journal entry, a bank-import column mapping) autosaves a
draft. QAYD's autosave is a debounced, dirty-gated `PATCH` to the resource's own draft endpoint — not
a bespoke local-storage cache the server never sees — so a draft opened on another device is the same
draft.

```tsx
// hooks/use-autosave.ts  (shape)
export function useAutosave<T extends FieldValues>(form: UseFormReturn<T>, cfg: AutosaveConfig) {
  const debounced = useDebouncedCallback(async (values: T) => {
    if (!form.formState.isDirty) return;
    await cfg.save(values);            // PATCH .../drafts/{id}
    setSavedAt(new Date());
  }, cfg.delayMs ?? 2000);
  useEffect(() => form.watch((values) => debounced(values as T)).unsubscribe, [form, debounced]);
  return { savedAt };                  // rendered as an unobtrusive "Saved 12:04" caption
}
```

- **Status is quiet.** A single `ink-500` caption ("Saved · 12:04", "Saving…") near the form title —
  never a toast per keystroke, never a modal.
- **Autosave saves a *draft*, not a *post*.** It calls the draft endpoint; it never triggers the
  balance-and-post path. The user still explicitly posts/submits. This preserves the AI/human-gate
  rule: nothing commits without an explicit human action, and autosave is not that action.
- **On a `409`** during autosave (the draft changed elsewhere), autosave stops and surfaces the same
  preserve-and-reapply banner as a manual submit conflict (`# Edge Cases`).

## Field arrays (the journal-line grid)

The repeating-child form is `react-hook-form`'s `useFieldArray`. The canonical instance is
`JournalEntryForm`'s line grid ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)); this
generalizes it. A field-array form adds three concerns over a flat form: per-row validation, a
cross-row invariant, and add/remove with a minimum count.

```tsx
// components/purchasing/bill-form.tsx  (field-array excerpt)
'use client';
import { useForm, useFieldArray } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { billSchema, type BillFormValues } from '@/lib/schemas/bill';
import { AccountPicker } from '@/components/accounting/account-picker';
import { CurrencyInput } from '@/components/ui/currency-input';
import { Button } from '@/components/ui/button';
import { Plus, Trash2 } from 'lucide-react';
import { useTranslations } from 'next-intl';

export function BillLines() {
  const t = useTranslations('billForm');
  const form = useForm<BillFormValues>({
    resolver: zodResolver(billSchema),
    defaultValues: { currency_code: 'KWD', lines: [{}, {}] },
  });
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'lines' });
  const lines = form.watch('lines');
  const total = lines.reduce((s, l) => s + Number(l.amount || 0), 0);   // display only; server re-sums

  return (
    <div className="rounded-lg border border-ink-150">
      <table className="w-full text-sm">
        <thead className="bg-ink-100 text-ink-700">
          <tr>
            <th className="p-2 text-start">{t('account')}</th>
            <th className="p-2 text-start">{t('description')}</th>
            <th className="p-2 text-end w-36">{t('amount')}</th>
            <th className="p-2 w-10" />
          </tr>
        </thead>
        <tbody>
          {fields.map((field, i) => (
            <tr key={field.id} className="border-t border-ink-150">
              <td className="p-2">
                <FormField control={form.control} name={`lines.${i}.account_id`} render={({ field: f }) => (
                  <FormItem><FormControl>
                    <AccountPicker value={f.value ?? null} onChange={(id) => f.onChange(id)} postableOnly />
                  </FormControl><FormMessage /></FormItem>
                )} />
              </td>
              <td className="p-2">
                <FormField control={form.control} name={`lines.${i}.description`} render={({ field: f }) => (
                  <FormItem><FormControl><Input {...f} /></FormControl></FormItem>
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
                  <Trash2 className="h-4 w-4 text-ink-500" />
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr className="border-t border-ink-150">
            <td className="p-2" colSpan={2}>
              <Button type="button" variant="ghost" size="sm" onClick={() => append({})}>
                <Plus className="h-3.5 w-3.5" /> {t('addLine')}
              </Button>
            </td>
            <td className="p-2 text-end">
              <AmountCell amount={total.toFixed(4)} currencyCode={form.watch('currency_code')} emphasis="strong" />
            </td>
            <td />
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
```

- **The `min(1)`/`min(2)` count is a schema rule** (`lines: z.array(lineSchema).min(2, 'minimumTwoLines')`
  for a journal entry), and the remove button disables at the floor so the user cannot delete below
  it — both the same rule, one enforced live, one at submit.
- **A cross-row invariant** (debits equal credits) is a `superRefine` on the array, exactly as
  `JournalEntryForm` does it, surfaced as a live balance banner and used to disable the primary
  action — never to block *saving a draft* (a draft may be unbalanced; only *posting* requires
  balance).
- **Per-row server errors** map onto `lines.<i>.<field>` paths (via `mapApiErrorsToForm`), landing on
  the exact cell.
- **Keyboard**: `Tab` walks the grid cell-to-cell; `Add line` is reachable without leaving the grid;
  `Enter` inside an amount cell does not submit the form (it is a field-array grid, and an accidental
  post from an amount field is a real hazard) — the primary action is reached by `Tab`.

## RBAC-driven disabled / read-only fields

A form field the current role may see but not change is `readOnly`; a whole action the role lacks is
`disabled` with a permission tooltip; an action whose mere existence is sensitive is omitted. This
matches the [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases` "who can see this
exists vs. why can't I do this right now" rule.

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

- **A whole permission-gated submit button** stays visible and `disabled` with a tooltip naming the
  permission (`"Requires accounting.journal.post"`), never silently missing — the platform never
  leaves a user staring at a greyed control with no explanation.
- **The client gate is courtesy, not security.** The mutation hook re-checks and surfaces the
  server's `403` as the final word if the cached permission was stale mid-session
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases → Permission removed
  mid-session`).

# Accessibility

Forms are where accessibility is most often broken and most consequential — a mis-wired error is
invisible to a screen-reader user filing a tax return. The `<Form>` wrapper set exists to make the
correct wiring the default.

| Concern | Contract |
|---|---|
| Label association | Every control has a programmatic label via `<FormLabel>` → `htmlFor` → the generated field `id`. A placeholder is never the only label (it vanishes on input and is invisible to some AT). |
| Invalid state | An invalid field sets `aria-invalid="true"` on the control (via `<FormControl>`) and links `<FormMessage>` through `aria-describedby`. |
| Error text | `<FormMessage>` is `role="alert"` so a newly appearing error is announced; it is real text next to the field, never color-only and never a toast for a field-level error. |
| Helper text | `<FormDescription>` is linked via `aria-describedby` alongside any error, so both are announced. |
| Required fields | Marked with `aria-required` and a visible indicator that is not color-only (a text "(required)" or an asterisk with a legend), per [`../ACCESSIBILITY.md`](../ACCESSIBILITY.md). |
| Focus on submit failure | The first invalid field receives focus — from the client resolver via RHF's `shouldFocusError`, from a server `422` via `mapApiErrorsToForm`'s `shouldFocus`. |
| Live cross-field state | The balance/invariant banner is `aria-live="polite"`, and a disabled primary action points `aria-describedby` at it, so the *reason* it is disabled is available to AT — never a silently disabled button. |
| Disabled vs. read-only | A field the user may read but not edit is `readOnly` (reachable, copyable, announced), not `disabled` (skipped by tab order and dimmed) — a posted entry's amounts are `readOnly`, not `disabled`. |
| Keyboard-only | Full tab order: header fields → any field array → footer actions; `Add line` reachable in-grid; no control is mouse-only; the dirty-guard confirm dialog traps focus (Radix). |
| Autofill / autocomplete | Sensitive finance fields set `autoComplete="off"`; genuine contact fields (email, phone on a customer) use the correct `autoComplete` token so a password manager or the browser helps rather than mis-fills. |

The `JournalEntryForm` accessibility row in [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md)
`# Accessibility per component` is the reference implementation of every row above; a new form is
expected to reproduce it, not re-invent it.

# Theming, Dark Mode & RTL

Forms hold **no** color of their own beyond the token set — a field is `ink` scale plus the one
`accent` focus ring plus `danger` for invalid, and nothing else.

- **Dark mode** is a token remap only (`ink-150` border, `accent-600`, `danger` all resolve to their
  `:root[data-theme="dark"]` values); no form file contains a `dark:` variant against a raw color, and
  the invalid `danger` border keeps its AA contrast in both themes
  ([`../THEMING.md`](../THEMING.md), [`../DARK_MODE.md`](../DARK_MODE.md)).
- **RTL is layout-only, via logical properties.** Field grids use `gap`/`grid-cols-*` (direction-
  agnostic); label and message alignment is `text-start`; icon padding inside inputs is `ps-*`/`pe-*`;
  a two-column form mirrors column order automatically under `dir="rtl"` with no conditional code.
  Physical `ml-*`/`text-left` are banned in form source
  ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md) `# Spacing & Grid → RTL mirroring`).
- **A bilingual name pair** (`name_en` / `name_ar`) sets `dir="rtl" lang="ar"` on the Arabic input
  explicitly, so Arabic text shapes and cursors correctly even while the surrounding UI is English —
  and vice versa — rather than inheriting the page direction and rendering the wrong-direction caret.
- **Numeric fields never mirror their contents.** A `CurrencyInput` keeps its value `dir="ltr"` and
  right-aligned in both directions (per [`INPUTS.md`](./INPUTS.md) and the `AmountCell` rule) — a
  Kuwaiti amount reads `1,204.500` inside an RTL form, never digit-reversed.
- **The form's own direction is never toggled per-field** for layout; only a data field whose content
  language differs from the UI carries an explicit `dir`.

# i18n & Formatting

- **Error messages are keys, resolved at render.** A schema emits `'nameRequired'`; the form resolves
  it through `useTranslations('validation')` so the same schema yields "Name is required" or
  "الاسم مطلوب" with no second schema. Interpolated errors carry values
  (`outOfBalance` with `{ diff }`), formatted through the same locale-aware path.
- **Financial values in inputs go through the currency primitives**, never a raw
  `Intl.NumberFormat` call in the form — `CurrencyInput`/`AmountCell` own the `numberingSystem: 'latn'`
  forcing and the KWD/BHD/OMR 3-decimal display rule ([`INPUTS.md`](./INPUTS.md),
  [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases`). A form never re-implements
  money formatting.
- **Dates in inputs** are stored as ISO `YYYY-MM-DD` strings in the form values (what the schema and
  the API expect) and displayed locale-aware by the date input; the form value is never a localized
  string that would round-trip ambiguously.
- **Both dictionaries stay in lock-step.** A new form string is added to `en` and `ar` in the same
  commit; a key present in one and missing in the other fails `npm run i18n:check` in CI
  ([`../README.md`](../README.md) `# Getting Started → Quality gates`). Arabic copy is authored to the
  professional-Gulf register, not machine-translated ([`../DESIGN_LANGUAGE.md`](../DESIGN_LANGUAGE.md)
  `# Voice & Microcopy → Arabic microcopy`).
- **The default currency is KWD** and the default numbering is Western Arabic (`latn`) even under the
  Arabic locale, per company setting; a form inherits the active company's base currency for its
  defaults rather than hard-coding a value.

# Testing

Forms carry the platform's mandatory coverage because they are logic-bearing, not merely
presentational.

- **Schema unit tests (Vitest).** Each `lib/schemas/*` test asserts the fail-fast rules and, crucially,
  that the schema's field names and optionality match the backend `FormRequest` — a snapshot of the
  schema's `keyof` set is compared against a fixture generated from the API's own validation contract,
  so a backend rename that the frontend has not mirrored fails the frontend build rather than
  surfacing as a mysterious `422` in production.

  ```tsx
  // lib/schemas/customer.test.ts
  import { customerSchema } from './customer';
  test('rejects a malformed credit_limit before any network call', () => {
    const r = customerSchema.safeParse({ name_en: 'A', name_ar: 'أ', currency_code: 'KWD', credit_limit: '12.abc' });
    expect(r.success).toBe(false);
    expect(r.error!.issues[0].message).toBe('invalidAmount');
  });
  ```

- **Component tests (Vitest + Testing Library).** Assert the *behavior*, not the markup: the primary
  button is disabled while a cross-field invariant fails and enabled when it clears (the
  `JournalEntryForm` balance test in [`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Testing`
  is the template); a server `422` fixture lands its message on the exact field and focuses it; the
  dirty-guard's `confirmDiscard` fires on an attempted close of a dirty form and not on a clean one.

- **Server-error mapping test.** `mapApiErrorsToForm` is unit-tested against a real envelope fixture,
  including a nested field-array path (`lines.2.debit`), asserting the error lands on that path and
  focuses the first error.

- **Playwright (E2E).** The two flows too integration-heavy for a component test: the full
  create-then-submit lifecycle across two RBAC personas (asserting the primary button's label switches
  between "Post" and "Submit for approval" per permission, per the `JournalEntryForm` Playwright note),
  and an autosave round-trip (edit → wait for the debounced draft `PATCH` → reload → the draft is
  restored from the server, not local storage).

- **Accessibility CI gate.** Each form's Storybook stories (default, invalid, submitting, read-only,
  RTL, dark) run through `axe-core`; a keyboard-only pass on any field-array or multi-step form is a
  manual pre-release check, since `axe` catches missing ARIA but not a broken tab order across a grid.

# Edge Cases

- **`409 version_conflict` on an edit submit or autosave.** The edit form sends `If-Match` with the
  record's last-known `version`/`etag`; on a `409`, the mutation hook discards the optimistic cache
  update, refetches the authoritative record, and shows a non-destructive banner — *"This record
  changed since you opened it. Your edits are preserved below; please re-apply them."* — rather than
  silently overwriting or discarding the in-progress edits
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Edge Cases`). The dirty form is never
  auto-reset out from under the user.
- **A field-array submit that partially fails.** A `422` naming `lines.2.account_id` maps to that one
  cell and focuses it; the other, valid lines are untouched. The form does not clear or re-order the
  array — the user fixes one cell and resubmits.
- **AI-drafted defaults.** When a form is pre-filled from an AI proposal (an AI-drafted journal entry,
  a suggested customer categorization), the pre-filled fields render with a `ConfidenceBadge` +
  reasoning affordance and are fully editable; submitting is the human's decision and the only thing
  that commits. The form never posts an AI draft on mount or on a timer.
- **A read-only-by-status record reached via edit URL.** If a user deep-links to the edit route of a
  record the server has since locked (a posted entry), the route guard/branch renders the read view,
  not an editable form whose submit would only ever `403`/`422` — the client trusts the server-supplied
  status, never its own guess about editability
  ([`../COMPONENT_LIBRARY.md`](../COMPONENT_LIBRARY.md) `# Composition Patterns → Pattern 2`).
- **Number-input locale drift.** A user typing `1,204.500` with grouping separators, or an Arabic
  keyboard emitting Eastern-Arabic digits, is normalized by the `CurrencyInput` primitive to the
  canonical `latn` string the schema's regex expects before validation runs — the schema never sees a
  locale-formatted string ([`INPUTS.md`](./INPUTS.md)).
- **A wizard step invalidated by a later step's edit.** Changing the fiscal-year step after the
  chart-of-accounts step was completed re-marks any dependent step incomplete (validity is read live
  from RHF, not from a "visited" flag), so the user cannot submit a wizard whose earlier answer no
  longer holds.
- **Double-submit / rapid Enter.** The primary button disables on `isPending`, and the mutation hook is
  idempotency-keyed where the endpoint supports it, so a double-click or a held Enter cannot post two
  entries.
- **A form open when the session expires.** A mutation returning `401` triggers the app-shell's
  re-auth flow; the dirty form's values are preserved in memory (and, if it autosaves, on the server as
  a draft) so a re-authenticated user returns to their in-progress work rather than a blank form.
- **Submitting with JavaScript disabled.** Only the Server-Action-backed simple forms degrade to a
  native `<form action>` POST and still function; the optimistic finance forms require JS by design and
  render a "JavaScript is required to edit financial records" notice in the no-JS case rather than a
  broken, non-submitting form.

# End of Document
