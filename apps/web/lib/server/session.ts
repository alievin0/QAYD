import "server-only";

import { cookies } from "next/headers";
import type { AuthMe } from "@qayd/types";

import { COMPANY_COOKIE, SESSION_COOKIE } from "../auth/session-cookie";
import { createServerClient } from "./sdk";

/**
 * Read the current session server-side. Resolves the httpOnly session cookie into the caller's `AuthMe`
 * (identity, memberships, active company, resolved permissions) by proxying `GET /auth/me` through the
 * SDK — the client's single source of truth. Returns `null` for an anonymous or unresolvable session so
 * the shell can degrade gracefully rather than throw.
 *
 * Sprint-1 note: until the login BFF (S1-15) mints real tokens and the Laravel `/auth/me` is wired to
 * this env, this resolves to `null` (the `me()` call fails closed) and the shell renders its empty,
 * zero-company state. The seam is intentionally complete now so S1-15 only has to set the cookie.
 */
export async function getSession(): Promise<AuthMe | null> {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  if (!token) return null;

  const companyId = cookieStore.get(COMPANY_COOKIE)?.value;

  try {
    const client = createServerClient({ token, companyId });
    const envelope = await client.me();
    return envelope.data;
  } catch {
    // Fail closed: an expired/invalid session or an unreachable API reads as "not signed in".
    return null;
  }
}

/** Convenience boolean guard mirroring the middleware's presence check, for server components. */
export async function hasSessionCookie(): Promise<boolean> {
  const cookieStore = await cookies();
  return cookieStore.has(SESSION_COOKIE);
}
