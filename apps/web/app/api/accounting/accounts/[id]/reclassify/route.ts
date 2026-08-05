import { NextResponse } from "next/server";
import { reclassifyAccountInputSchema } from "@qayd/types";

import {
  errorResponse,
  readJsonBody,
  sessionClient,
  validationResponse,
} from "../../../../../../lib/server/bff";

/**
 * `POST /api/accounting/accounts/{id}/reclassify` — proxies to the same upstream route (S2-10).
 *
 * The rule this endpoint is really about — an account carrying posted entries cannot be reclassified —
 * lives in `ReclassifyAccountAction` and is not restated here. When the server refuses, its coded `422`
 * travels back verbatim so the dialog can render the server's own message inline rather than a guess at
 * what went wrong.
 */
export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
): Promise<NextResponse> {
  const session = await sessionClient();
  if (!session.ok) return session.response;

  const { id } = await context.params;
  const accountId = Number(id);
  if (!Number.isInteger(accountId) || accountId <= 0) {
    return validationResponse("Invalid account id.");
  }

  const parsed = reclassifyAccountInputSchema.safeParse(
    await readJsonBody(request),
  );
  if (!parsed.success) {
    return validationResponse("Invalid reclassify payload.");
  }

  try {
    const { data } = await session.client.reclassifyAccount(
      accountId,
      parsed.data,
    );
    return NextResponse.json({ success: true, data });
  } catch (error) {
    return errorResponse(error);
  }
}
