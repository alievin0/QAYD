import { z } from "zod";

/**
 * Auth DTOs for the QAYD identity API (`/api/v1/auth/*`). Field names mirror the Laravel controllers
 * and FormRequests exactly (App\Http\Controllers\Identity\*, App\Http\Requests\Identity\*), so a value
 * that validates here is accepted by the backend unchanged.
 */

/** The two Sprint-1 locales (backend `Rule::in(['ar','en'])`). */
export const localeSchema = z.enum(["ar", "en"]);
export type LocaleCode = z.infer<typeof localeSchema>;

// — Inputs (client-side mirrors of the FormRequest rules) —

/** `POST /auth/register` body (RegisterRequest). */
export const registerInputSchema = z.object({
  name: z.string().min(1).max(150),
  email: z.string().email(),
  password: z.string().min(8).max(255),
  locale: localeSchema.optional(),
});
export type RegisterInput = z.infer<typeof registerInputSchema>;

/** `POST /auth/login` body (LoginRequest). */
export const loginInputSchema = z.object({
  email: z.string().email(),
  password: z.string().min(1),
  remember_me: z.boolean().optional(),
  device_name: z.string().max(255).optional(),
});
export type LoginInput = z.infer<typeof loginInputSchema>;

/** `POST /auth/email/verify` params (VerifyEmailController reads `id` + `hash`; `expires`/`signature` sign the URL). */
export const verifyEmailInputSchema = z.object({
  id: z.number().int(),
  hash: z.string().min(1),
  expires: z.union([z.string(), z.number()]).optional(),
  signature: z.string().optional(),
});
export type VerifyEmailInput = z.infer<typeof verifyEmailInputSchema>;

/** `POST /auth/refresh` body (RefreshController). */
export const refreshInputSchema = z.object({ refresh_token: z.string().min(1) });
export type RefreshInput = z.infer<typeof refreshInputSchema>;

/** `POST /auth/logout` body (LogoutController — refresh token optional; the cookie session is always cleared). */
export const logoutInputSchema = z.object({ refresh_token: z.string().optional() });
export type LogoutInput = z.infer<typeof logoutInputSchema>;

// — Shared response fragments —

/** The signed-in user as returned by `/auth/me` and `/auth/login`. */
export const authUserSchema = z.object({
  uuid: z.string(),
  name: z.string(),
  email: z.string(),
  locale: z.string(),
  mfa_enrolled: z.boolean(),
});
export type AuthUser = z.infer<typeof authUserSchema>;

/** One company the caller is a member of (public UUID only — never the internal sequential id). */
export const companySummarySchema = z.object({
  uuid: z.string(),
  name_en: z.string(),
  name_ar: z.string().nullable(),
  role: z.string(),
});
export type CompanySummary = z.infer<typeof companySummarySchema>;

/** The active company reference (UUID + the caller's role in it). */
export const activeCompanyRefSchema = z.object({
  uuid: z.string(),
  role: z.string(),
});
export type ActiveCompanyRef = z.infer<typeof activeCompanyRefSchema>;

// — `GET /auth/me` (the client's source of truth for identity + scope + permissions) —

export const authMeSchema = z.object({
  user: authUserSchema,
  companies: z.array(companySummarySchema),
  active_company: activeCompanyRefSchema.nullable(),
  perms_ver: z.number().int().nullable(),
  permissions: z.array(z.string()),
});
export type AuthMe = z.infer<typeof authMeSchema>;

// — Response result payloads (TS interfaces; the SDK returns these typed) —

/** The bearer-token set returned by login / refresh / (bearer) switch-company. */
export interface AuthTokens {
  token_type: string;
  access_token: string;
  expires_in: number;
  refresh_token: string;
  refresh_expires_in: number;
}

/** `data` of a successful `POST /auth/login`. */
export interface LoginResult extends AuthTokens {
  status: string;
  user: AuthUser;
  companies: CompanySummary[];
  active_company_id: string | null;
  company_selection_required: boolean;
}

/** `data` of a successful `POST /auth/refresh`. */
export interface RefreshResult extends AuthTokens {
  status: string;
}

/** The minimal user projection returned by register / email-verify. */
export interface RegisteredUser {
  uuid: string;
  email: string;
  name: string;
  email_verified: boolean;
}

/** `data` of a successful `POST /auth/register`. */
export interface RegisterResult {
  user: RegisteredUser;
}

/** `data` of a successful `POST /auth/email/verify`. */
export interface VerifyEmailResult {
  user: Pick<RegisteredUser, "uuid" | "email" | "email_verified">;
}
