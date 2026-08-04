import { NextResponse } from "next/server";
import { generateTrialBalanceInputSchema } from "@qayd/types";

import {
  errorResponse,
  readJsonBody,
  sessionClient,
  validationResponse,
} from "../../../../lib/server/bff";

/**
 * `/api/accounting/trial-balance` — the BFF for the trial-balance screen (S2-12).
 *
 * Two verbs over one path because the API models it that way, and the distinction is not cosmetic:
 * `GET` asks what the ledger says right now and stores nothing, while `POST` freezes that answer into a
 * durable, versioned artifact somebody will sign. The screen keeps them equally distinct.
 *
 * `POST` forwards the upstream outcome rather than flattening it: `201` means the snapshot was filled
 * inline, `202` that it went to the reports queue and is still `generating`. The client needs to tell
 * those apart to know whether the totals it just received are final.
 */

export async function GET(request: Request): Promise<NextResponse> {
  const session = await sessionClient();
  if (!session.ok) return session.response;

  const periodId = Number(
    new URL(request.url).searchParams.get("fiscal_period_id"),
  );
  if (!Number.isInteger(periodId) || periodId < 1) {
    return validationResponse("A fiscal period is required.");
  }

  try {
    const { data } = await session.client.computeTrialBalance(periodId);
    return NextResponse.json({ success: true, data });
  } catch (error) {
    return errorResponse(error);
  }
}

export async function POST(request: Request): Promise<NextResponse> {
  const session = await sessionClient();
  if (!session.ok) return session.response;

  const parsed = generateTrialBalanceInputSchema.safeParse(
    await readJsonBody(request),
  );
  if (!parsed.success) {
    return validationResponse("A fiscal period is required.");
  }

  try {
    const { data } = await session.client.generateTrialBalanceSnapshot(
      parsed.data,
    );
    // `queued` is the server's own flag; the status mirrors it so neither side has to infer the other.
    return NextResponse.json(
      { success: true, data },
      { status: data?.queued === true ? 202 : 201 },
    );
  } catch (error) {
    return errorResponse(error);
  }
}
