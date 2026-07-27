/**
 * The names and attributes of the cookies the web BFF owns. Kept in one framework-free module so the
 * Edge middleware, the Node route handlers, and the server session helper all agree on exact spelling —
 * the "GUC name drift" class of bug, applied to cookies.
 *
 * `LOGIN_FLOW.md` carries a reconciliation note that different design docs name the session cookie
 * `qayd_session` vs a `qayd_at`/`qayd_rt` pair; they describe the same server-set, httpOnly, opaque
 * mechanism. This build settles on `qayd_session` as the canonical presence-gating cookie and keeps a
 * companion refresh cookie for when real tokens land in S1-15.
 */

/** The presence-gating session cookie the auth-gate middleware checks. httpOnly, Secure, SameSite=Lax. */
export const SESSION_COOKIE = "qayd_session";

/** The refresh-token cookie (reserved for S1-15; the shell only ever gates on the session cookie). */
export const REFRESH_COOKIE = "qayd_rt";

/** The active-company hint cookie, mirrored from `switch-company` for a fast SSR scope on next load. */
export const COMPANY_COOKIE = "qayd_company";

/** Shared attributes for the httpOnly auth cookies. `secure` is disabled outside production for http dev. */
export const sessionCookieOptions = {
  httpOnly: true,
  sameSite: "lax",
  secure: process.env.NODE_ENV === "production",
  path: "/",
} as const;
