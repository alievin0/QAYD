import { NextResponse } from "next/server";

import {
  errorResponse,
  sessionClient,
  validationResponse,
} from "../../../../../../lib/server/bff";

/**
 * `POST /api/accounting/journal-entries/{id}/post` — proxies the posting call (S2-11).
 *
 * The one route here that moves the ledger, and the only one that forwards an `Idempotency-Key`. The
 * key is minted by the editor once per posting attempt and passed straight through: if the browser
 * retries — a dropped connection, an impatient second click — the server replays the original response
 * instead of posting twice. Dropping the header here would quietly remove that protection, which is why
 * it is forwarded verbatim rather than regenerated.
 */
export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
): Promise<NextResponse> {
  const session = await sessionClient();
  if (!session.ok) return session.response;

  const { id } = await context.params;
  const entryId = Number(id);
  if (!Number.isInteger(entryId) || entryId <= 0) {
    return validationResponse("Invalid journal entry id.");
  }

  const idempotencyKey = request.headers.get("Idempotency-Key") ?? undefined;

  try {
    const { data } = await session.client.postJournalEntry(
      entryId,
      idempotencyKey,
    );
    return NextResponse.json({ success: true, data });
  } catch (error) {
    return errorResponse(error);
  }
}
