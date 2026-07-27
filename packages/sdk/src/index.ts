/**
 * `@qayd/sdk` — the typed client for the QAYD Laravel `/api/v1` (auth + company, Sprint-1 scope).
 * Framework-free (no React): every method returns the typed `Envelope<T>` from `@qayd/types` and throws
 * a {@link QaydApiError} on failure. Cookie session (`credentials: "include"`) + optional bearer token,
 * with `X-Company-Id` / `X-Request-Id` handled for you.
 *
 * ```ts
 * import { createClient } from "@qayd/sdk";
 * const qayd = createClient();
 * const { data } = await qayd.login({ email, password }); // Envelope<LoginResult>
 * ```
 */
export { QaydClient, createClient, type FetchLike, type QaydClientOptions } from "./client.js";
export { QaydApiError, networkError, toApiError } from "./errors.js";

// Re-export the wire types most error/response handling touches, so consumers can import from one place.
export type { ApiError, ApiErrorItem, Envelope } from "@qayd/types";
