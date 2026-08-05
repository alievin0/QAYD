import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { resolveApiBaseUrl } from "@qayd/shared";

import {
  COMPANY_COOKIE,
  SESSION_COOKIE,
} from "../../../../lib/auth/session-cookie";

/**
 * `POST /api/broadcasting/auth` — the BFF's half of a channel subscription (S2-13).
 *
 * Echo has to prove who is subscribing, and the credential that proves it is a bearer token the browser
 * is never given. So a subscription is authorized the way every other call is: the client posts its
 * `socket_id` and `channel_name` here, this handler adds the token from the httpOnly cookie, and
 * Laravel's channel authorization — the only place the membership rule lives — decides.
 *
 * Those two fields are forwarded and nothing else. A channel name is a claim about what the caller
 * wants, never about who they are; identity comes solely from the cookie, so naming another company's
 * channel meets the same refusal that protects the HTTP path.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;

  if (token === undefined) {
    return NextResponse.json(
      {
        success: false,
        code: "AUTHENTICATION_FAILED",
        message: "No active session.",
      },
      { status: 401 },
    );
  }

  const body: unknown = await request.json().catch(() => null);
  const socketId = readField(body, "socket_id");
  const channelName = readField(body, "channel_name");

  if (socketId === null || channelName === null) {
    return NextResponse.json(
      {
        success: false,
        code: "VALIDATION_ERROR",
        message: "socket_id and channel_name are required.",
      },
      { status: 422 },
    );
  }

  // `resolveApiBaseUrl()` ends in `/api/v1`; the broadcasting endpoint sits at `/api`, beside it.
  const authUrl = `${resolveApiBaseUrl().replace(/\/v1\/?$/, "")}/broadcasting/auth`;

  try {
    const upstream = await fetch(authUrl, {
      method: "POST",
      headers: {
        "content-type": "application/json",
        accept: "application/json",
        authorization: `Bearer ${token}`,
        ...companyHeader(cookieStore.get(COMPANY_COOKIE)?.value),
      },
      body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
    });

    // The signature Laravel returns is passed back verbatim: Echo hands it to the socket server, and a
    // rewritten body would be a signature for a different message.
    const payload: unknown = await upstream.json().catch(() => null);

    return NextResponse.json(payload, { status: upstream.status });
  } catch {
    return NextResponse.json(
      {
        success: false,
        code: "UPSTREAM_UNAVAILABLE",
        message: "Could not reach the realtime authorizer.",
      },
      { status: 502 },
    );
  }
}

function readField(body: unknown, field: string): string | null {
  if (body === null || typeof body !== "object") return null;
  const value = (body as Record<string, unknown>)[field];
  return typeof value === "string" && value !== "" ? value : null;
}

function companyHeader(companyId: string | undefined): Record<string, string> {
  return companyId === undefined ? {} : { "X-Company-Id": companyId };
}
