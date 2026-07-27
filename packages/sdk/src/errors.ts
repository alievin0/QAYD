import type { ApiError, ApiErrorCode, ApiErrorItem, Envelope } from "@qayd/types";

/**
 * The typed error the SDK throws on any non-2xx / `success:false` response (and on transport failure).
 * Implements the `ApiError` shape from `@qayd/types` and is an `Error` subclass, so callers can both
 * `catch (e) { if (e instanceof QaydApiError) … }` and read `e.code` / `e.errors` for field-level UI.
 */
export class QaydApiError extends Error implements ApiError {
  readonly status: number;
  readonly code: ApiErrorCode;
  readonly errors: ApiErrorItem[];
  readonly requestId: string | null;

  constructor(init: {
    status: number;
    code: ApiErrorCode;
    message: string;
    errors?: ApiErrorItem[];
    requestId?: string | null;
  }) {
    super(init.message);
    this.name = "QaydApiError";
    this.status = init.status;
    this.code = init.code;
    this.errors = init.errors ?? [];
    this.requestId = init.requestId ?? null;
    // Restore the prototype chain (needed when targeting ES5-ish emit / bundlers).
    Object.setPrototypeOf(this, QaydApiError.prototype);
  }

  /** True for 422 validation failures — the case a form maps `errors[]` onto its fields. */
  get isValidation(): boolean {
    return this.status === 422 || this.code === "VALIDATION_ERROR";
  }
}

/** A best-effort catalog code inferred from the HTTP status when the body carried none. */
function codeFromStatus(status: number): ApiErrorCode {
  switch (status) {
    case 401:
      return "AUTHENTICATION_FAILED";
    case 403:
      return "INSUFFICIENT_PERMISSION";
    case 404:
      return "RESOURCE_NOT_FOUND";
    case 409:
      return "CONCURRENT_MODIFICATION";
    case 422:
      return "VALIDATION_ERROR";
    case 429:
      return "RATE_LIMITED";
    default:
      return status >= 500 ? "INTERNAL_ERROR" : "VALIDATION_ERROR";
  }
}

/** Build a {@link QaydApiError} from a failure envelope + the raw response status/headers. */
export function toApiError(
  status: number,
  envelope: Partial<Envelope<unknown>> | null,
  fallbackRequestId: string | null,
): QaydApiError {
  const errors = envelope?.errors ?? [];
  const first = errors[0];
  const code = (first?.code ?? codeFromStatus(status)) as ApiErrorCode;
  const message =
    envelope?.message ??
    first?.message ??
    `Request failed with status ${status}.`;
  const requestId = envelope?.request_id ?? fallbackRequestId;
  return new QaydApiError({ status, code, message, errors, requestId });
}

/** A transport-level failure (DNS, offline, CORS, aborted) — no HTTP response was received. */
export function networkError(message: string, requestId: string | null): QaydApiError {
  return new QaydApiError({
    status: 0,
    code: "NETWORK_ERROR",
    message,
    errors: [],
    requestId,
  });
}
