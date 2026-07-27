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
  control_account_of: z.string().nullable(),
  account_type: accountTypeSchema.nullable(),
});
export type Account = z.infer<typeof accountSchema>;

/** An account node in the tree read — the account plus its nested children (recursive). */
export type AccountTreeNode = Account & { children: AccountTreeNode[] };

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
