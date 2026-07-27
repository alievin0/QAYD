import { NextResponse } from "next/server";
import { verifyEmailInputSchema } from "@qayd/types";

import { errorResponse, readJsonBody } from "../../../../lib/server/bff";
import { createServerClient } from "../../../../lib/server/sdk";

/**
 * `POST /api/auth/verify-email` — the BFF that confirms an email from its signed link. The `/verify-email`
 * landing page reads the `id`/`hash`/`expires`/`signature` params off the mailed URL and posts them here;
 * this handler proxies `POST /auth/email/verify` (which carries the signature params as the query string
 * Laravel's signed middleware checks). Verification issues no session — on success the user continues to
 * `/login` — so no cookies are set.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const parsed = verifyEmailInputSchema.safeParse(await readJsonBody(request));
  if (!parsed.success) {
    return NextResponse.json(
      {
        success: false,
        code: "VALIDATION_ERROR",
        message: "Invalid verification parameters.",
      },
      { status: 422 },
    );
  }

  try {
    const client = createServerClient();
    const { data } = await client.verifyEmail(parsed.data);
    return NextResponse.json({
      success: true,
      data: data ? { user: data.user } : null,
    });
  } catch (error) {
    return errorResponse(error);
  }
}
