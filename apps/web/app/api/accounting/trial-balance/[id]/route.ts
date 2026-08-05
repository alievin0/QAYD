import { NextResponse } from "next/server";

import { errorResponse, sessionClient } from "../../../../../lib/server/bff";

/**
 * `GET /api/accounting/trial-balance/{id}` — re-read a stored snapshot (S2-12).
 *
 * A generate that lands on the reports queue answers `202` with the snapshot already visible but still
 * `generating`, so the screen needs a way to ask again once the run finishes. That is this route, and
 * it is a read of a durable artifact — it never recomputes anything, which is the whole point of having
 * frozen the figures in the first place.
 */
export async function GET(
  _request: Request,
  context: { params: Promise<{ id: string }> },
): Promise<NextResponse> {
  const session = await sessionClient();
  if (!session.ok) return session.response;

  const { id } = await context.params;

  try {
    const { data } = await session.client.trialBalanceSnapshot(Number(id));
    return NextResponse.json({ success: true, data });
  } catch (error) {
    return errorResponse(error);
  }
}
