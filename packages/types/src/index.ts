/**
 * `@qayd/types` — shared domain types and Zod schemas for the QAYD `/api/v1` Sprint-1 contract:
 * the response envelope, the coded error shape, and the auth + company DTOs. No accounting types yet
 * (Law: do not build the future). Consumed by `@qayd/sdk` and (later) `apps/web`.
 */
export {
  apiErrorItemSchema,
  envelopeMetaSchema,
  envelopeSchema,
  paginationSchema,
  type ApiError,
  type ApiErrorCode,
  type ApiErrorItem,
  type Envelope,
  type EnvelopeMeta,
  type Pagination,
} from "./envelope.js";

export {
  activeCompanyRefSchema,
  authMeSchema,
  authUserSchema,
  companySummarySchema,
  loginInputSchema,
  localeSchema,
  logoutInputSchema,
  refreshInputSchema,
  registerInputSchema,
  verifyEmailInputSchema,
  type ActiveCompanyRef,
  type AuthMe,
  type AuthTokens,
  type AuthUser,
  type CompanySummary,
  type LocaleCode,
  type LoginInput,
  type LoginResult,
  type LogoutInput,
  type RefreshInput,
  type RefreshResult,
  type RegisterInput,
  type RegisteredUser,
  type RegisterResult,
  type VerifyEmailInput,
  type VerifyEmailResult,
} from "./auth.js";

export {
  companySchema,
  createCompanyInputSchema,
  fiscalYearSchema,
  switchCompanyInputSchema,
  switchCompanyResultSchema,
  type Company,
  type CreateCompanyInput,
  type CreateCompanyResult,
  type FiscalYear,
  type SwitchCompanyInput,
  type SwitchCompanyResult,
} from "./company.js";

export {
  accountSchema,
  accountTypeSchema,
  createAccountInputSchema,
  reclassifyAccountInputSchema,
  updateAccountInputSchema,
  type Account,
  type AccountListResult,
  type AccountResult,
  type AccountTreeNode,
  type AccountTreeResult,
  type AccountType,
  type AccountTypeListResult,
  type CreateAccountInput,
  type ReclassifyAccountInput,
  type UpdateAccountInput,
} from "./accounting.js";
