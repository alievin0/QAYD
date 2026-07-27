import "server-only";

import { createClient, type FetchLike, type QaydClient } from "@qayd/sdk";
import { resolveApiBaseUrl } from "@qayd/shared";

/**
 * Server-only factory for the typed QAYD SDK client. The browser never talks to Laravel directly — the
 * BFF route handlers and server components do, holding the opaque session/bearer credential the client
 * never sees. This keeps the web app credential-free at the edge: it only ever proxies `/api/v1`.
 *
 * @param token the bearer access token lifted from the httpOnly session cookie (when present).
 * @param companyId the active company UUID, sent as `X-Company-Id`.
 * @param fetch an optional fetch override — the login BFF passes a wrapper that captures the upstream
 *   `Retry-After` header off a `429`, which the SDK's typed error otherwise drops.
 */
export function createServerClient(
  options: { token?: string; companyId?: string; fetch?: FetchLike } = {},
): QaydClient {
  return createClient({
    baseUrl: resolveApiBaseUrl(),
    token: options.token,
    companyId: options.companyId,
    // Server-side there is no browser cookie jar to forward; auth rides the bearer token instead.
    credentials: "omit",
    fetch: options.fetch,
  });
}
