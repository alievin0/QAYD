import { NextResponse } from "next/server";
import { submitJournalEntryInputSchema } from "@qayd/types";

import {
  errorResponse,
  readJsonBody,
  sessionClient,
  validationResponse,
} from "../../../../../../lib/server/bff";

/**
 * `POST /api/accounting/journal-entries/{id}/submit` — proxies the submit transition (S2-11).
 *
 * Whether the entry is in a submittable state, and whether it may be submitted at all, is the Action's
 * decision; this handler exists so the browser never needs the bearer token.
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

  const parsed = submitJournalEntryInputSchema.safeParse(
    await readJsonBody(request),
  );
  if (!parsed.success) {
    return validationResponse("Invalid submit payload.");
  }

  try {
    const { data } = await session.client.submitJournalEntry(
      entryId,
      parsed.data,
    );
    return NextResponse.json({ success: true, data });
  } catch (error) {
    return errorResponse(error);
  }
}
