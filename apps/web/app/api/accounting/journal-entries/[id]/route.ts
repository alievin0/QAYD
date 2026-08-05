import { NextResponse } from "next/server";
import { updateJournalEntryInputSchema } from "@qayd/types";

import {
  errorResponse,
  readJsonBody,
  sessionClient,
  validationResponse,
} from "../../../../../lib/server/bff";

/**
 * `PATCH /api/accounting/journal-entries/{id}` — proxies a draft edit (S2-11).
 *
 * `version` rides along in the body: it is the optimistic-concurrency token the Action guards on, so a
 * stale editor gets `409 VERSION_CONFLICT` from the server rather than silently overwriting someone
 * else's work. This handler neither reads nor reasons about it — it only forwards.
 */
export async function PATCH(
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

  const parsed = updateJournalEntryInputSchema.safeParse(
    await readJsonBody(request),
  );
  if (!parsed.success) {
    return validationResponse("Invalid journal entry payload.");
  }

  try {
    const { data } = await session.client.updateJournalEntry(
      entryId,
      parsed.data,
    );
    return NextResponse.json({ success: true, data });
  } catch (error) {
    return errorResponse(error);
  }
}
