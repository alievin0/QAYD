import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { switchCompanyInputSchema } from "@qayd/types";

import {
  COMPANY_COOKIE,
  REFRESH_COOKIE,
  SESSION_COOKIE,
  sessionCookieOptions,
} from "../../../../lib/auth/session-cookie";
import { errorResponse, readJsonBody } from "../../../../lib/server/bff";
import { createServerClient } from "../../../../lib/server/sdk";

/**
 * `POST /api/auth/switch-company` — re-scopes the active company. Proxies `POST /auth/switch-company`
 * through the SDK with the caller's session, mirrors the new active company into the `qayd_company` hint
 * cookie (so the next server render is scoped immediately), rotates the session/refresh cookies when the
 * upstream returns a fresh bearer set, and returns the new resolved permissions. The CompanySwitcher
 * calls this, then `router.refresh()` re-renders every server component under the new tenant.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  if (!token) {
    return NextResponse.json(
      {
        success: false,
        code: "AUTHENTICATION_FAILED",
        message: "No active session.",
      },
      { status: 401 },
    );
  }

  const parsed = switchCompanyInputSchema.safeParse(
    await readJsonBody(request),
  );
  if (!parsed.success) {
    return NextResponse.json(
      {
        success: false,
        code: "VALIDATION_ERROR",
        message: "Invalid company id.",
      },
      { status: 422 },
    );
  }

  try {
    const currentCompany = cookieStore.get(COMPANY_COOKIE)?.value;
    const client = createServerClient({ token, companyId: currentCompany });
    const { data } = await client.switchCompany(parsed.data);
    if (data === null) {
      return errorResponse(new Error("Empty switch-company response."));
    }

    cookieStore.set(COMPANY_COOKIE, data.active_company.uuid, {
      ...sessionCookieOptions,
      maxAge: sessionCookieMaxAge(data.refresh_expires_in),
    });

    // Bearer clients get a rotated token set on switch; keep the cookies in step when present.
    if (data.access_token && data.expires_in) {
      cookieStore.set(SESSION_COOKIE, data.access_token, {
        ...sessionCookieOptions,
        maxAge: data.expires_in,
      });
    }
    if (data.refresh_token && data.refresh_expires_in) {
      cookieStore.set(REFRESH_COOKIE, data.refresh_token, {
        ...sessionCookieOptions,
        maxAge: data.refresh_expires_in,
      });
    }

    return NextResponse.json({
      success: true,
      data: {
        active_company: data.active_company,
        perms_ver: data.perms_ver,
        permissions: data.permissions,
      },
    });
  } catch (error) {
    return errorResponse(error);
  }
}

/** A sane default lifetime for the company hint cookie when the response omits a refresh TTL. */
function sessionCookieMaxAge(refreshExpiresIn: number | undefined): number {
  return refreshExpiresIn ?? 60 * 60 * 24 * 30;
}
