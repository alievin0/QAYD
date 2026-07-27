import { resolveNext, sanitizeNext } from "./next-param";

/**
 * `resolvePostAuthDestination` — the single "where do I go now" decision shared by `LoginForm`,
 * `MfaVerifyForm` (stubbed this sprint), and `CompanySelectList`, per
 * [LOGIN_FLOW.md → Step 6](../../../docs/frontend/flows/LOGIN_FLOW.md). Kept as one pure function so the
 * routing decision exists exactly once and is unit-testable without a router.
 *
 * Sprint-1 resolution order (the exit journey; onboarding-status and Home-mode preference land later):
 *   1. **Zero companies** → `/onboarding` — a company-less session creates one before it can be scoped.
 *   2. **Company selection required** → `/select-company` (carrying a validated `next` forward), the
 *      multi-company fork where no membership is yet the established default.
 *   3. **Otherwise** → the validated `next` deep-link, or the default `/dashboard`.
 *
 * `next` is always run through the same-origin, in-app allow-list (`sanitizeNext`) before it is used, so a
 * crafted deep link can never turn a post-auth redirect into an open redirect.
 */
export interface PostAuthState {
  /** The caller's memberships from the login envelope (only the count is read here). */
  companies: readonly unknown[];
  /** The server's signal that a multi-company user must pick a company before entering. */
  company_selection_required: boolean;
}

export function resolvePostAuthDestination(
  state: PostAuthState,
  next?: string | null,
): string {
  if (state.companies.length === 0) {
    return "/onboarding";
  }
  if (state.company_selection_required) {
    const safeNext = sanitizeNext(next);
    return safeNext
      ? `/select-company?next=${encodeURIComponent(safeNext)}`
      : "/select-company";
  }
  return resolveNext(next);
}
