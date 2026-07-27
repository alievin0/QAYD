import { z } from "zod";

import { activeCompanyRefSchema, localeSchema } from "./auth.js";

/**
 * Company / onboarding DTOs (`POST /api/v1/companies`, `POST /api/v1/auth/switch-company`). Field names
 * mirror App\Http\Controllers\Identity\{CreateCompanyController,SwitchCompanyController} and
 * App\Http\Requests\Onboarding\CreateCompanyRequest exactly.
 */

// — Inputs —

/** `POST /companies` body (CreateCompanyRequest). Four required fields; the rest fall back server-side. */
export const createCompanyInputSchema = z.object({
  legal_name: z.string().min(2).max(255),
  name_en: z.string().min(2).max(255),
  // ISO 4217 alpha-3, normalised to upper-case server-side.
  base_currency: z.string().regex(/^[A-Za-z]{3}$/),
  fiscal_year_start_month: z.number().int().min(1).max(12),
  trade_name: z.string().max(255).nullish(),
  name_ar: z.string().max(255).nullish(),
  timezone: z.string().optional(),
  locale: localeSchema.optional(),
});
export type CreateCompanyInput = z.infer<typeof createCompanyInputSchema>;

/** `POST /auth/switch-company` body — the target company's public UUID. */
export const switchCompanyInputSchema = z.object({
  company_id: z.string().min(1),
});
export type SwitchCompanyInput = z.infer<typeof switchCompanyInputSchema>;

// — Responses —

/** The first fiscal year seeded with a new company. */
export const fiscalYearSchema = z.object({
  name: z.string(),
  start_date: z.string(),
  end_date: z.string(),
  status: z.string(),
});
export type FiscalYear = z.infer<typeof fiscalYearSchema>;

/** `data.company` of a successful `POST /companies` — the client-safe tenant projection. */
export const companySchema = z.object({
  uuid: z.string(),
  legal_name: z.string(),
  trade_name: z.string().nullable(),
  name_en: z.string(),
  name_ar: z.string().nullable(),
  base_currency: z.string(),
  fiscal_year_start_month: z.number().int(),
  timezone: z.string(),
  locale: z.string(),
  status: z.string(),
  role: z.string(),
  fiscal_year: fiscalYearSchema,
});
export type Company = z.infer<typeof companySchema>;

/** `data` of a successful `POST /companies`. */
export interface CreateCompanyResult {
  company: Company;
}

/** `data` of a successful `POST /auth/switch-company` (bearer clients also get a fresh token set). */
export const switchCompanyResultSchema = z.object({
  active_company: activeCompanyRefSchema,
  perms_ver: z.number().int().nullable(),
  permissions: z.array(z.string()),
  token_type: z.string().optional(),
  access_token: z.string().optional(),
  expires_in: z.number().optional(),
  refresh_token: z.string().optional(),
  refresh_expires_in: z.number().optional(),
});
export type SwitchCompanyResult = z.infer<typeof switchCompanyResultSchema>;
