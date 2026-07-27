import { z } from "zod";

/**
 * The QAYD standard response envelope and coded error shape.
 *
 * Mirrors the backend exactly: `App\Http\Responses\ApiResponse` emits
 * `{ success, data, message, errors, meta, request_id, timestamp }` for every `/api/*` response
 * (docs/api/REST_STANDARDS.md "# Response Envelope Schema", docs/api/API_ERROR_HANDLING.md).
 */

/** Pagination block; present on collection endpoints, `null` on single-resource responses. */
export const paginationSchema = z.object({
  page: z.number().int().nullable(),
  per_page: z.number().int(),
  total: z.number().int().nullable(),
  cursor: z.string().nullable(),
});
export type Pagination = z.infer<typeof paginationSchema>;

/**
 * One entry in the envelope's `errors[]`. Matches the synthesised shape in `ApiResponse::error`
 * (`{ code, field, message, meta }`) and the field-level validation entries on a `422`.
 */
export const apiErrorItemSchema = z.object({
  code: z.string(),
  field: z.string().nullable(),
  message: z.string(),
  // PHP serialises an empty associative array as `[]`, so a synthesised error's `meta` arrives as an
  // empty JSON array while a populated one (e.g. `{ rule: "min" }`) arrives as an object — accept both,
  // normalising to an object.
  meta: z
    .union([z.record(z.string(), z.unknown()), z.array(z.unknown())])
    .transform((value) => (Array.isArray(value) ? {} : value))
    .default({}),
});
export type ApiErrorItem = z.infer<typeof apiErrorItemSchema>;

/** The envelope's `meta`: always a `pagination` slot plus any endpoint-specific keys. */
export const envelopeMetaSchema = z
  .object({ pagination: paginationSchema.nullable() })
  .catchall(z.unknown());
export type EnvelopeMeta = z.infer<typeof envelopeMetaSchema>;

/**
 * The generic response envelope. Hand-authored so `Envelope<Company>` reads cleanly at call sites;
 * `envelopeSchema(dataSchema)` is the runtime-validating counterpart.
 */
export interface Envelope<T> {
  success: boolean;
  data: T | null;
  message: string | null;
  errors: ApiErrorItem[];
  meta: EnvelopeMeta;
  request_id: string | null;
  timestamp: string;
}

/** Build a Zod schema for a full envelope wrapping a given `data` schema. */
export function envelopeSchema<T extends z.ZodType>(dataSchema: T) {
  return z.object({
    success: z.boolean(),
    data: dataSchema.nullable(),
    message: z.string().nullable(),
    errors: z.array(apiErrorItemSchema),
    meta: envelopeMetaSchema,
    request_id: z.string().nullable(),
    timestamp: z.string(),
  });
}

/**
 * Stable, machine-readable error codes from the catalog (docs/api/API_ERROR_HANDLING.md
 * "# Error Code Catalog"). Kept open (`string & {}`) so a newly-added backend code still type-checks.
 */
export type ApiErrorCode =
  | "VALIDATION_ERROR"
  | "MISSING_REQUIRED_FIELD"
  | "INVALID_FORMAT"
  | "DUPLICATE_ENTRY"
  | "AUTHENTICATION_FAILED"
  | "TOKEN_EXPIRED"
  | "INSUFFICIENT_PERMISSION"
  | "COMPANY_MISMATCH"
  | "APPROVAL_REQUIRED"
  | "RESOURCE_NOT_FOUND"
  | "EMAIL_NOT_VERIFIED"
  | "PERIOD_LOCKED"
  | "UNBALANCED_ENTRY"
  | "ALREADY_POSTED"
  | "CREDIT_LIMIT_EXCEEDED"
  | "INSUFFICIENT_STOCK"
  | "RECONCILED_LOCK"
  | "CURRENCY_MISMATCH"
  | "RATE_LIMITED"
  | "CONCURRENT_MODIFICATION"
  | "AI_CONFIDENCE_TOO_LOW"
  | "INTERNAL_ERROR"
  | (string & {});

/**
 * The normalized, typed error the SDK throws/returns when a request fails — the failure envelope
 * flattened for ergonomic client handling. `code`/`message` come from the first `errors[]` entry
 * (or the top-level fallback); `errors` keeps the full field-level list for form mapping.
 */
export interface ApiError {
  /** HTTP status code (e.g. 401, 403, 422). `0` for a network/transport failure. */
  status: number;
  /** The primary error code from the catalog. */
  code: ApiErrorCode;
  /** Human-readable, already-localized message safe to surface in a toast. */
  message: string;
  /** Every discrete problem (field-level on a 422); empty for a transport failure. */
  errors: ApiErrorItem[];
  /** The `X-Request-Id` correlation key, when the response carried one. */
  requestId: string | null;
}
