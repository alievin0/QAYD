import { NextResponse } from "next/server";
import { createAccountInputSchema } from "@qayd/types";

import {
  errorResponse,
  readJsonBody,
  sessionClient,
  validationResponse,
} from "../../../../lib/server/bff";

/**
 * `POST /api/accounting/accounts` — proxies account creation to `POST /accounting/accounts` (S2-10).
 *
 * The browser never talks to Laravel directly; the bearer token lives in an httpOnly cookie the client
 * cannot read, so every mutation goes through a handler like this one. The Zod parse here is a SHAPE
 * check only — it stops a malformed request before a network hop and nothing more. Uniqueness of the
 * code, the parent belonging to the same company, and whether the account may exist at all are the
 * server's rules, and its coded `422` is passed through untouched rather than pre-empted or reworded.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const session = await sessionClient();
  if (!session.ok) return session.response;

  const parsed = createAccountInputSchema.safeParse(await readJsonBody(request));
  if (!parsed.success) {
    return validationResponse("Invalid account payload.");
  }

  try {
    const { data } = await session.client.createAccount(parsed.data);
    return NextResponse.json({ success: true, data }, { status: 201 });
  } catch (error) {
    return errorResponse(error);
  }
}
