import { NextResponse, type NextRequest } from "next/server";

import { sanitizeNext } from "./lib/auth/next-param";
import { SESSION_COOKIE } from "./lib/auth/session-cookie";

/**
 * Auth-gate middleware (S1-12). Every request that resolves to an `(app)` route is checked for the
 * presence of the httpOnly session cookie the BFF sets on login. Absent it, the request is bounced to
 * `/login?next=<original path+search>` so the login flow (a later story) can return the user to exactly
 * where they were headed. The `next` value is passed through the same-origin allow-list before it is
 * written, so a crafted deep link can never turn the login redirect into an open redirect.
 *
 * Sprint-1 scope: "authenticated" == "the session cookie is present". The real cookie is issued by the
 * login BFF route in S1-15; this gate only reflects its presence, never mints or validates a token.
 */

/** Public auth routes that must render without a session (the anonymous front doors). */
const PUBLIC_PREFIXES = [
  "/login",
  "/mfa",
  "/forgot-password",
  "/reset-password",
] as const;

function isPublicPath(pathname: string): boolean {
  return PUBLIC_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
}

export function middleware(request: NextRequest): NextResponse {
  const { pathname, search } = request.nextUrl;

  // Anonymous front doors are always reachable; everything else the matcher lets through is `(app)`.
  if (isPublicPath(pathname)) {
    return NextResponse.next();
  }

  if (request.cookies.has(SESSION_COOKIE)) {
    return NextResponse.next();
  }

  const loginUrl = request.nextUrl.clone();
  loginUrl.pathname = "/login";
  loginUrl.search = "";

  const safeNext = sanitizeNext(`${pathname}${search}`);
  if (safeNext) {
    loginUrl.searchParams.set("next", safeNext);
  }

  return NextResponse.redirect(loginUrl);
}

/**
 * Run on everything except Next internals, static assets, the BFF API surface, and the liveness probe.
 * Route groups (`(app)` / `(auth)`) never appear in the URL, so the gate distinguishes them by path:
 * the public list above is anonymous, and the rest of what this matcher admits is authenticated `(app)`.
 */
export const config = {
  matcher: [
    "/((?!api/|_next/static|_next/image|favicon.ico|health|.*\\.[\\w]+$).*)",
  ],
};
