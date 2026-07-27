/**
 * `next` deep-link allow-list. The auth-gate middleware appends `?next=<original path+search>` when it
 * bounces an unauthenticated `(app)` request to `/login`, and the login flow reads it back to return the
 * user to where they were headed. Because that value is attacker-controllable (anyone can craft a link
 * with `?next=https://evil.example/phish`), it is validated to a **same-origin, in-app path** before it
 * is ever used as a redirect target — the classic open-redirect guard.
 *
 * A value is accepted only if it is a root-relative path (`/…`) that resolves, against any origin, to
 * that same origin, and does not point back at an auth screen or the BFF API. Everything else — absolute
 * URLs, protocol-relative `//host`, backslash tricks, non-app paths — is rejected and the caller falls
 * back to the default post-auth destination.
 */

/** The default in-app destination when there is no valid `next`. */
export const DEFAULT_POST_AUTH_PATH = "/dashboard";

/** Auth-flow routes a `next` must never point at (that would loop, skip the gate, or re-enter onboarding). */
const AUTH_PATH_PREFIXES = [
  "/login",
  "/register",
  "/verify-email",
  "/mfa",
  "/forgot-password",
  "/reset-password",
  "/select-company",
  "/onboarding",
] as const;

/** A stable, arbitrary base used only to parse root-relative paths; never emitted. */
const PARSE_ORIGIN = "http://localhost";

function isAuthPath(pathname: string): boolean {
  return AUTH_PATH_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
}

/** Reject any string carrying an ASCII control char, DEL, or literal whitespace (CR/LF smuggling). */
function hasControlOrSpace(value: string): boolean {
  for (let i = 0; i < value.length; i += 1) {
    const code = value.charCodeAt(i);
    if (code <= 0x20 || code === 0x7f) return true;
  }
  return false;
}

/**
 * Return the sanitized, safe-to-redirect `next` path (`pathname` + `search`), or `null` if the input is
 * absent or fails the same-origin, in-app allow-list.
 */
export function sanitizeNext(next: string | null | undefined): string | null {
  if (typeof next !== "string" || next.length === 0) return null;

  // Must be root-relative. Reject absolute URLs, protocol-relative `//host`, and `/\` backslash tricks.
  if (!next.startsWith("/")) return null;
  if (next.startsWith("//") || next.startsWith("/\\")) return null;
  if (hasControlOrSpace(next)) return null;

  let url: URL;
  try {
    url = new URL(next, PARSE_ORIGIN);
  } catch {
    return null;
  }

  // If parsing pulled in a different origin (e.g. a sneaky `https:` slipped through), reject.
  if (url.origin !== PARSE_ORIGIN) return null;

  // Never redirect back into the auth flow or at the BFF API surface.
  if (isAuthPath(url.pathname) || url.pathname.startsWith("/api")) return null;

  return `${url.pathname}${url.search}`;
}

/** Resolve a request's `next` to a concrete destination, falling back to the default when invalid. */
export function resolveNext(next: string | null | undefined): string {
  return sanitizeNext(next) ?? DEFAULT_POST_AUTH_PATH;
}
