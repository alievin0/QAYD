/**
 * Resolution of the QAYD API base URL — the versioned `/api/v1` root the SDK targets.
 *
 * Resolution order (first non-empty wins):
 *   1. an explicit argument (e.g. a value the app already read from its own config),
 *   2. `NEXT_PUBLIC_API_BASE_URL` / `QAYD_API_BASE_URL` / `API_BASE_URL` from the ambient env,
 *   3. the local-dev default (`apps/api` served on :8000).
 *
 * Kept framework-free: env is read defensively off `globalThis.process` so this module compiles and
 * runs in the browser, in a Next server component, and in a plain Node/Bun context without `@types/node`.
 */

/** The local-dev origin `apps/api` (Laravel) is served on. */
export const DEFAULT_API_ORIGIN = "http://localhost:8000";

/** The versioned API path prefix. Every SDK endpoint is relative to `${origin}${API_V1_PATH}`. */
export const API_V1_PATH = "/api/v1";

/** The local-dev default API base URL (origin + `/api/v1`). */
export const DEFAULT_API_BASE_URL = `${DEFAULT_API_ORIGIN}${API_V1_PATH}`;

const ENV_KEYS = ["NEXT_PUBLIC_API_BASE_URL", "QAYD_API_BASE_URL", "API_BASE_URL"] as const;

function readEnv(key: string): string | undefined {
  const proc = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process;
  const value = proc?.env?.[key];
  return typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;
}

/** Strip a single trailing slash so callers can safely concatenate a leading-slash path. */
export function stripTrailingSlash(url: string): string {
  return url.endsWith("/") ? url.slice(0, -1) : url;
}

/**
 * Resolve the API base URL (already including `/api/v1`) with no trailing slash.
 *
 * @param explicit an override that, when non-empty, wins over every environment source.
 */
export function resolveApiBaseUrl(explicit?: string): string {
  if (typeof explicit === "string" && explicit.trim() !== "") {
    return stripTrailingSlash(explicit.trim());
  }
  for (const key of ENV_KEYS) {
    const fromEnv = readEnv(key);
    if (fromEnv) return stripTrailingSlash(fromEnv);
  }
  return DEFAULT_API_BASE_URL;
}
