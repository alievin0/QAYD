import "server-only";

import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { QaydApiError, type QaydClient } from "@qayd/sdk";

import { COMPANY_COOKIE, SESSION_COOKIE } from "../auth/session-cookie";
import { createServerClient } from "./sdk";

/**
 * Shared helpers for the BFF auth route handlers: turn a thrown `QaydApiError` into a coded JSON response
 * (preserving the upstream status, or `502` for a transport failure), and read a JSON request body
 * defensively. Keeps the handlers themselves down to their happy path.
 */

export function errorResponse(error: unknown): NextResponse {
  if (error instanceof QaydApiError) {
    // status 0 == transport failure reaching Laravel; surface it as a Bad Gateway, not a fake 200.
    const status = error.status === 0 ? 502 : error.status;
    return NextResponse.json(
      {
        success: false,
        code: error.code,
        message: error.message,
        errors: error.errors,
      },
      { status },
    );
  }
  return NextResponse.json(
    { success: false, code: "INTERNAL_ERROR", message: "Unexpected error." },
    { status: 500 },
  );
}

export async function readJsonBody(request: Request): Promise<unknown> {
  try {
    return await request.json();
  } catch {
    return {};
  }
}

/**
 * The tenant-scoped SDK client for a BFF handler, or the 401 to return instead.
 *
 * Every accounting mutation needs the same three steps — lift the bearer token and the active-company
 * hint out of the httpOnly cookies, refuse without a session, build the client — and a handler that
 * forgot the refusal would call upstream unauthenticated. Returning a discriminated union rather than
 * throwing keeps that decision visible at the call site.
 */
export async function sessionClient(): Promise<
  { ok: true; client: QaydClient } | { ok: false; response: NextResponse }
> {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;

  if (!token) {
    return {
      ok: false,
      response: NextResponse.json(
        {
          success: false,
          code: "AUTHENTICATION_FAILED",
          message: "No active session.",
        },
        { status: 401 },
      ),
    };
  }

  return {
    ok: true,
    client: createServerClient({
      token,
      companyId: cookieStore.get(COMPANY_COOKIE)?.value,
    }),
  };
}

/** A 422 in the standard envelope shape, for a request the route rejected before calling upstream. */
export function validationResponse(message: string): NextResponse {
  return NextResponse.json(
    { success: false, code: "VALIDATION_ERROR", message },
    { status: 422 },
  );
}
