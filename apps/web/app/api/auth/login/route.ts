import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import type { FetchLike } from "@qayd/sdk";
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
 * On a `429` (account lockout or IP throttle) the upstream `Retry-After` header is captured off the raw
 * response — the SDK's typed error drops it — and re-emitted on the BFF response so the client can render
 * the server's countdown rather than guess one (LOGIN_FLOW.md: "a lockout countdown is the server's
 * Retry-After header, not a client-side guess").
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

  // Wrap fetch so a `429`'s Retry-After survives the SDK boundary (its QaydApiError carries no headers).
  let retryAfter: string | null = null;
  const captureRetryAfter: FetchLike = async (input, init) => {
    const response = await fetch(input, init);
    const header = response.headers.get("Retry-After");
    if (header) retryAfter = header;
    return response;
  };

  try {
    const client = createServerClient({ fetch: captureRetryAfter });
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
    const response = errorResponse(error);
    if (response.status === 429 && retryAfter) {
      response.headers.set("Retry-After", retryAfter);
    }
    return response;
  }
}
