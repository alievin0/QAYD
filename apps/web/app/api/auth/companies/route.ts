import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { createCompanyInputSchema } from "@qayd/types";

import {
  COMPANY_COOKIE,
  SESSION_COOKIE,
  sessionCookieOptions,
} from "../../../../lib/auth/session-cookie";
import { errorResponse, readJsonBody } from "../../../../lib/server/bff";
import { createServerClient } from "../../../../lib/server/sdk";

/** A sane lifetime for the active-company hint cookie: 30 days, matching switch-company's default. */
const COMPANY_COOKIE_MAX_AGE = 60 * 60 * 24 * 30;

/**
 * `POST /api/auth/companies` — the create-company onboarding BFF (S1-11). An email-verified, signed-in
 * user with zero companies posts the wizard's validated payload here; this handler proxies
 * `POST /api/v1/companies` through the SDK with the caller's session bearer, and — on success — mirrors
 * the new company's UUID into the `qayd_company` hint cookie so the next server render is immediately
 * scoped to the tenant the user just created. The session bearer stays in its httpOnly cookie; only the
 * client-safe company projection is returned.
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

  const parsed = createCompanyInputSchema.safeParse(await readJsonBody(request));
  if (!parsed.success) {
    return NextResponse.json(
      {
        success: false,
        code: "VALIDATION_ERROR",
        message: "Invalid company payload.",
      },
      { status: 422 },
    );
  }

  try {
    const client = createServerClient({ token });
    const { data } = await client.createCompany(parsed.data);
    if (data === null) {
      return errorResponse(new Error("Empty create-company response."));
    }

    // Scope the next render to the just-created tenant; `/auth/me` re-validates this on the server.
    cookieStore.set(COMPANY_COOKIE, data.company.uuid, {
      ...sessionCookieOptions,
      maxAge: COMPANY_COOKIE_MAX_AGE,
    });

    return NextResponse.json({ success: true, data: { company: data.company } });
  } catch (error) {
    return errorResponse(error);
  }
}
