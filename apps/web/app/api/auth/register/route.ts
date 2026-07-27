import { NextResponse } from "next/server";
import { registerInputSchema } from "@qayd/types";

import { errorResponse, readJsonBody } from "../../../../lib/server/bff";
import { createServerClient } from "../../../../lib/server/sdk";

/**
 * `POST /api/auth/register` — the BFF that establishes a new identity. It proxies `POST /auth/register`
 * through the SDK and returns only the minimal registered-user projection. Registration issues **no
 * session** (the user verifies their email first, then signs in), so this handler sets no cookies — the
 * browser leaves it exactly as unauthenticated as it arrived.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const parsed = registerInputSchema.safeParse(await readJsonBody(request));
  if (!parsed.success) {
    return NextResponse.json(
      {
        success: false,
        code: "VALIDATION_ERROR",
        message: "Invalid registration payload.",
      },
      { status: 422 },
    );
  }

  try {
    const client = createServerClient();
    const { data } = await client.register(parsed.data);
    if (data === null) {
      return errorResponse(new Error("Empty register response."));
    }
    return NextResponse.json({ success: true, data: { user: data.user } });
  } catch (error) {
    return errorResponse(error);
  }
}
