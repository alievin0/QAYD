<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one place the per-request correlation id (`request_id`) is named and resolved (S1-16,
 * docs/api/API_ERROR_HANDLING.md "# request_id & Tracing", docs/api/REST_STANDARDS.md).
 *
 * Every API request carries a UUIDv4 `request_id`, assigned at the earliest point in the middleware
 * stack so even a malformed-body 400 still has one. If the client supplies a valid `X-Request-Id`
 * header it is honoured verbatim (end-to-end correlation across the client's own retries); otherwise
 * the server mints one. The value is stored in the request-scoped {@see Context} (so it rides on every
 * log line and survives across service boundaries), returned as the `X-Request-Id` response header,
 * and echoed in the response envelope's `request_id` field — all three the same value.
 */
final class RequestId
{
    /** Transport header carrying the correlation id, inbound and outbound. */
    public const HEADER = 'X-Request-Id';

    /** Context / log key under which the correlation id lives for the request. */
    public const CONTEXT_KEY = 'request_id';

    /**
     * Resolve the correlation id for an inbound request: honour a valid client-supplied header, else
     * mint a UUIDv4. Idempotent within a request — pins the value into Context + log context so every
     * later read (envelope, exception handler, audit write) returns the same id.
     */
    public static function resolve(Request $request): string
    {
        $inbound = $request->headers->get(self::HEADER);
        $id = is_string($inbound) && Str::isUuid($inbound) ? $inbound : (string) Str::uuid();

        self::put($id);
        $request->attributes->set(self::CONTEXT_KEY, $id);

        return $id;
    }

    /**
     * The correlation id already resolved for this request, or a freshly minted one if none has been
     * assigned yet (e.g. an exception raised before the middleware ran, such as a route miss).
     */
    public static function get(): string
    {
        $existing = Context::get(self::CONTEXT_KEY);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        self::put($id);

        return $id;
    }

    private static function put(string $id): void
    {
        Context::add(self::CONTEXT_KEY, $id);
        Log::withContext([self::CONTEXT_KEY => $id]);
    }
}
