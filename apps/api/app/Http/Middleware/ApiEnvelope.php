<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Support\RequestId;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The safety net that guarantees every `/api/*` JSON response leaves in the standard envelope
 * (docs/api/REST_STANDARDS.md "# Response Envelope Schema").
 *
 * A route that already answered through {@see ApiResponse} (or the global exception handler) is
 * enveloped — this only stamps the `X-Request-Id` header. A route that returned a bare JSON payload
 * (a plain array/object) is wrapped in place: its body becomes the envelope's `data`, preserving the
 * original status and any headers (`Location`, etc.). Non-JSON responses (file exports, `204`, the
 * `/up` probe) are passed through untouched — there is nothing to envelope.
 *
 * Note: this runs only on a normally-returned response. Exceptions unwind past `$next()` and are
 * rendered directly by the global handler (which enveloping + the header itself), so error responses
 * never depend on this middleware.
 */
final class ApiEnvelope
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $decoded = $response->getData(true);

        if (is_array($decoded) && array_key_exists('success', $decoded) && array_key_exists('request_id', $decoded)) {
            // Already an envelope: only ensure the transport header carries the correlation id.
            $requestId = $decoded['request_id'];
            $response->headers->set(RequestId::HEADER, is_string($requestId) ? $requestId : RequestId::get());

            return $response;
        }

        // Bare payload from a route that bypassed ApiResponse — wrap it, keeping status + headers.
        $success = $response->getStatusCode() < 400;
        $response->setData(ApiResponse::payload($success, $decoded, null, [], []));
        $response->headers->set(RequestId::HEADER, RequestId::get());

        return $response;
    }
}
