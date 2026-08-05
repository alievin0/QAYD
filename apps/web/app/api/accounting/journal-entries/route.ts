import { NextResponse } from "next/server";
import { createJournalEntryInputSchema } from "@qayd/types";

import {
  errorResponse,
  readJsonBody,
  sessionClient,
  validationResponse,
} from "../../../../lib/server/bff";

/**
 * `POST /api/accounting/journal-entries` — proxies draft creation (S2-11).
 *
 * The browser never holds the bearer token, so every mutation goes through a handler like this. The Zod
 * parse is a SHAPE check only: whether the entry balances, whether its accounts are postable, whether
 * its date falls in an open period are all decided by the Actions behind the API, and their coded
 * responses travel back untouched.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const session = await sessionClient();
  if (!session.ok) return session.response;

  const parsed = createJournalEntryInputSchema.safeParse(
    await readJsonBody(request),
  );
  if (!parsed.success) {
    return validationResponse("Invalid journal entry payload.");
  }

  try {
    const { data } = await session.client.createJournalEntry(parsed.data);
    return NextResponse.json({ success: true, data }, { status: 201 });
  } catch (error) {
    return errorResponse(error);
  }
}
