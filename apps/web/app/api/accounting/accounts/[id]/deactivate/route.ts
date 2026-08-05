import { NextResponse } from "next/server";

import {
  errorResponse,
  sessionClient,
  validationResponse,
} from "../../../../../../lib/server/bff";

/**
 * `POST /api/accounting/accounts/{id}/deactivate` — proxies to the same upstream route (S2-10).
 *
 * No body, so no schema: the only input is the id in the path. What a deactivated account may still be
 * used for, and what it may not, is the server's rule; this handler exists purely so the browser never
 * needs the bearer token.
 */
export async function POST(
  _request: Request,
  context: { params: Promise<{ id: string }> },
): Promise<NextResponse> {
  const session = await sessionClient();
  if (!session.ok) return session.response;

  const { id } = await context.params;
  const accountId = Number(id);
  if (!Number.isInteger(accountId) || accountId <= 0) {
    return validationResponse("Invalid account id.");
  }

  try {
    const { data } = await session.client.deactivateAccount(accountId);
    return NextResponse.json({ success: true, data });
  } catch (error) {
    return errorResponse(error);
  }
}
