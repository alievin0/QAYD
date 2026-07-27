import { NextResponse } from "next/server";

import { getSession } from "../../../../lib/server/session";

/**
 * `GET /api/auth/me` — the BFF read of the current session. Resolves the httpOnly session cookie into the
 * caller's `AuthMe` server-side (the browser never holds the token), returning `401` when there is no
 * resolvable session. Client components that need identity call this rather than hitting Laravel directly.
 */
export async function GET(): Promise<NextResponse> {
  const me = await getSession();
  if (me === null) {
    return NextResponse.json(
      { authenticated: false, me: null },
      { status: 401 },
    );
  }
  return NextResponse.json({ authenticated: true, me });
}
