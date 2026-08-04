import { z } from "zod";

/**
 * Chart-of-accounts DTOs (`/api/v1/accounting/accounts`, SPRINT_02 §S2-02). Field names mirror
 * App\Http\Controllers\Accounting\AccountController and App\Http\Requests\Accounting\* exactly.
 */

// — Inputs —

/** `POST /accounting/accounts` body (StoreAccountRequest). Business rules are enforced server-side. */
export const createAccountInputSchema = z.object({
  account_type_id: z.number().int(),
  code: z.string().min(1).max(40),
  name_en: z.string().min(1).max(255),
  name_ar: z.string().min(1).max(255),
  parent_id: z.number().int().nullish(),
  is_control_account: z.boolean().optional(),
  control_account_of: z.string().max(40).nullish(),
});
export type CreateAccountInput = z.infer<typeof createAccountInputSchema>;

/** `PATCH /accounting/accounts/{id}` body (UpdateAccountRequest) — all optional (a partial update). */
export const updateAccountInputSchema = z.object({
  code: z.string().min(1).max(40).optional(),
  name_en: z.string().min(1).max(255).optional(),
  name_ar: z.string().min(1).max(255).optional(),
});
export type UpdateAccountInput = z.infer<typeof updateAccountInputSchema>;

/** `POST /accounting/accounts/{id}/reclassify` body (ReclassifyAccountRequest). */
export const reclassifyAccountInputSchema = z.object({
  account_type_id: z.number().int(),
});
export type ReclassifyAccountInput = z.infer<typeof reclassifyAccountInputSchema>;

// — Responses —

/** The account type embedded in an account payload (`data.account.account_type`). */
export const accountTypeSchema = z.object({
  id: z.number().int(),
  key: z.string(),
  name_en: z.string(),
  name_ar: z.string(),
  normal_balance: z.string(),
  is_balance_sheet: z.boolean(),
});
export type AccountType = z.infer<typeof accountTypeSchema>;

/** A single account (the flat projection), with its account type embedded. */
export const accountSchema = z.object({
  id: z.number().int(),
  code: z.string(),
  name_en: z.string(),
  name_ar: z.string(),
  parent_id: z.number().int().nullable(),
  normal_balance: z.string(),
  status: z.string(),
  is_control_account: z.boolean(),
  /**
   * Whether a journal line may reference this account directly. Read it; never derive it — a leaf can
   * be a header (CHART_OF_ACCOUNTS.md), so "has no children" is not the same question.
   */
  allow_posting: z.boolean(),
  control_account_of: z.string().nullable(),
  account_type: accountTypeSchema.nullable(),
});
export type Account = z.infer<typeof accountSchema>;

/** An account node in the tree read — the account plus its nested children (recursive). */
export type AccountTreeNode = Account & { children: AccountTreeNode[] };

/**
 * `data` of `GET /accounting/account-types` — the seven global classifications, in presentation order.
 *
 * Read-only: there is no create/update counterpart, because the catalogue is shared by every tenant and
 * the runtime database role has no write grant on it. The New Account dialog needs it because
 * `account_type_id` is required and its ids are database-generated, so they cannot be hard-coded.
 */
export interface AccountTypeListResult {
  account_types: AccountType[];
}

/** `data` of `GET /accounting/accounts`. */
export interface AccountListResult {
  accounts: Account[];
}

/** `data` of `GET /accounting/accounts/tree`. */
export interface AccountTreeResult {
  accounts: AccountTreeNode[];
}

/** `data` of a single-account response (create / update / reclassify / deactivate / show). */
export interface AccountResult {
  account: Account;
}

// — Journal entries —

/**
 * One line of a journal-entry request body.
 *
 * Money is a STRING throughout — `"40.0000"`, never `40.0` — because a float cannot carry
 * `NUMERIC(19,4)` faithfully, and a rounding error in a ledger is not a display bug. Which side an
 * amount belongs on, and whether the entry balances, are the server's to decide.
 */
export const journalLineInputSchema = z.object({
  account_id: z.number().int(),
  debit: z.string(),
  credit: z.string(),
  description: z.string().nullable().optional(),
});
export type JournalLineInput = z.infer<typeof journalLineInputSchema>;

/** `POST /accounting/journal-entries` body. Always creates a DRAFT; posting is a separate call. */
export const createJournalEntryInputSchema = z.object({
  journal_date: z.string(),
  entry_type: z.string(),
  currency_code: z.string().length(3),
  exchange_rate: z.string().optional(),
  reference: z.string().nullable().optional(),
  memo: z.string().nullable().optional(),
  lines: z.array(journalLineInputSchema).min(1),
});
export type CreateJournalEntryInput = z.infer<typeof createJournalEntryInputSchema>;

/**
 * `PATCH /accounting/journal-entries/{id}` body. `version` is required, not optional: it is the
 * optimistic-concurrency token, and a caller who could omit it would be opting out of the protection
 * that stops one editor silently overwriting another.
 */
export const updateJournalEntryInputSchema = createJournalEntryInputSchema.extend({
  version: z.number().int().min(1),
});
export type UpdateJournalEntryInput = z.infer<typeof updateJournalEntryInputSchema>;

/** `POST /accounting/journal-entries/{id}/submit` body. */
export const submitJournalEntryInputSchema = z.object({
  version: z.number().int().min(1),
});
export type SubmitJournalEntryInput = z.infer<typeof submitJournalEntryInputSchema>;

/** `POST /accounting/journal-entries/{id}/reverse` body — a reversal must say why. */
export const reverseJournalEntryInputSchema = z.object({
  reason: z.string().min(1),
  reversal_date: z.string().nullable().optional(),
});
export type ReverseJournalEntryInput = z.infer<typeof reverseJournalEntryInputSchema>;

/** A persisted journal line as the API returns it. */
export const journalLineSchema = z.object({
  id: z.number().int(),
  line_number: z.number().int(),
  account_id: z.number().int(),
  debit: z.string(),
  credit: z.string(),
  base_debit: z.string(),
  base_credit: z.string(),
  currency_code: z.string(),
  description: z.string().nullable(),
});
export type JournalLine = z.infer<typeof journalLineSchema>;

/**
 * A journal entry header. `lines` is present on every single-entry response and absent from the list,
 * which is why it is optional rather than defaulted — an empty array would claim the entry has no
 * lines, which is a different statement from "this response did not include them".
 */
export const journalEntrySchema = z.object({
  id: z.number().int(),
  journal_number: z.string(),
  journal_date: z.string(),
  entry_type: z.string(),
  status: z.string(),
  currency_code: z.string(),
  exchange_rate: z.string(),
  total_debit: z.string(),
  total_credit: z.string(),
  base_total_debit: z.string(),
  base_total_credit: z.string(),
  version: z.number().int(),
  is_reversal: z.boolean(),
  reversed_entry_id: z.number().int().nullable(),
  reversal_entry_id: z.number().int().nullable(),
  reference: z.string().nullable(),
  memo: z.string().nullable(),
  lines: z.array(journalLineSchema).optional(),
});
export type JournalEntry = z.infer<typeof journalEntrySchema>;

/** `data` of `GET /accounting/journal-entries`. */
export interface JournalEntryListResult {
  journal_entries: JournalEntry[];
}

/** `data` of every single-entry journal response (show / create / update / submit / post / …). */
export interface JournalEntryResult {
  journal_entry: JournalEntry;
}
