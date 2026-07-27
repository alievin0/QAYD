import { cookies } from "next/headers";
import { NextResponse } from "next/server";

import {
  COMPANY_COOKIE,
  REFRESH_COOKIE,
  SESSION_COOKIE,
} from "../../../../lib/auth/session-cookie";
import { createServerClient } from "../../../../lib/server/sdk";

/**
 * `POST /api/auth/logout` — clears the httpOnly session cookies and makes a best-effort call to revoke
 * the refresh-token family server-side. Clearing the cookies is authoritative for the browser; the
 * upstream revoke is best-effort so a logout always succeeds locally even if Laravel is unreachable.
 * After this, the next `(app)` request fails the middleware's cookie check and is bounced to `/login`.
 */
export async function POST(): Promise<NextResponse> {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  const refreshToken = cookieStore.get(REFRESH_COOKIE)?.value;

  if (token) {
    try {
      await createServerClient({ token }).logout({
        refresh_token: refreshToken,
      });
    } catch {
      // Best-effort: a failed upstream revoke never blocks clearing the local session.
    }
  }

  cookieStore.delete(SESSION_COOKIE);
  cookieStore.delete(REFRESH_COOKIE);
  cookieStore.delete(COMPANY_COOKIE);

  return NextResponse.json({ success: true, data: null });
}
