import "server-only";

import { NextResponse } from "next/server";
import { QaydApiError } from "@qayd/sdk";

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
