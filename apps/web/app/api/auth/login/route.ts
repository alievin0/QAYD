import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { loginInputSchema } from "@qayd/types";

import {
  COMPANY_COOKIE,
  REFRESH_COOKIE,
  SESSION_COOKIE,
  sessionCookieOptions,
} from "../../../../lib/auth/session-cookie";
import { errorResponse, readJsonBody } from "../../../../lib/server/bff";
import { createServerClient } from "../../../../lib/server/sdk";

/**
 * `POST /api/auth/login` — the BFF that exchanges credentials for a session. It proxies `POST /auth/login`
 * through the SDK, writes the returned access/refresh tokens into **httpOnly** cookies (so the browser
 * never sees a token), and returns only the browser-safe projection (identity, memberships, whether a
 * company must be selected). The token fields are stripped from the response body by construction.
 *
 * TODO(S1-15): pair this with the real `/login` screen — CSRF priming, the MFA branch, and the
 * `resolvePostAuthDestination` redirect. The cookie/proxy seam itself is complete.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const parsed = loginInputSchema.safeParse(await readJsonBody(request));
  if (!parsed.success) {
    return NextResponse.json(
      {
        success: false,
        code: "VALIDATION_ERROR",
        message: "Invalid login payload.",
      },
      { status: 422 },
    );
  }

  try {
    const client = createServerClient();
    const { data } = await client.login(parsed.data);
    if (data === null) {
      return errorResponse(new Error("Empty login response."));
    }

    const cookieStore = await cookies();
    cookieStore.set(SESSION_COOKIE, data.access_token, {
      ...sessionCookieOptions,
      maxAge: data.expires_in,
    });
    cookieStore.set(REFRESH_COOKIE, data.refresh_token, {
      ...sessionCookieOptions,
      maxAge: data.refresh_expires_in,
    });
    if (data.active_company_id) {
      cookieStore.set(COMPANY_COOKIE, data.active_company_id, {
        ...sessionCookieOptions,
        maxAge: data.refresh_expires_in,
      });
    }

    return NextResponse.json({
      success: true,
      data: {
        status: data.status,
        user: data.user,
        companies: data.companies,
        active_company_id: data.active_company_id,
        company_selection_required: data.company_selection_required,
      },
    });
  } catch (error) {
    return errorResponse(error);
  }
}
