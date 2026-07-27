<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Middleware\ApiEnvelope;
use App\Support\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * The single formatter for the QAYD standard response envelope
 * `{ success, data, message, errors, meta, request_id, timestamp }` (docs/api/REST_STANDARDS.md
 * "# Response Envelope Schema", docs/api/API_ERROR_HANDLING.md "# Error Envelope").
 *
 * Every `/api/*` JSON response is shaped by this class: controllers call {@see success()}, the global
 * exception handler calls {@see error()}, and the {@see ApiEnvelope} middleware
 * wraps any bare payload a route returned without going through here — so no route can escape the
 * envelope. `success` always agrees with the HTTP status class (2xx ⇒ true; 4xx/5xx ⇒ false);
 * `data` is null on error; `errors` is empty on success.
 */
final class ApiResponse
{
    /**
     * A successful envelope. `data` is the payload (object, array, or null); `meta` defaults to
     * `{ "pagination": null }`.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, ?string $message = null, array $meta = [], int $status = 200): JsonResponse
    {
        return self::response(self::payload(true, $data, $message, [], $meta), $status);
    }

    /**
     * A failure envelope carrying a coded error. When no explicit `errors[]` is supplied a single
     * entry is synthesised from the top-level `code`/`message`.
     *
     * @param  list<array{code: string, field: string|null, message: string, meta: array<string, mixed>}>  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(string $code, string $message, array $errors = [], int $status = 400, array $meta = []): JsonResponse
    {
        if ($errors === []) {
            $errors = [[
                'code' => $code,
                'field' => null,
                'message' => $message,
                'meta' => [],
            ]];
        }

        return self::response(self::payload(false, null, $message, $errors, $meta), $status);
    }

    /**
     * Build the raw envelope array. Exposed so the wrapping middleware can re-shape a bare response
     * body in place without minting a second {@see JsonResponse}.
     *
     * @param  list<array{code: string, field: string|null, message: string, meta: array<string, mixed>}>  $errors
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function payload(bool $success, mixed $data, ?string $message, array $errors, array $meta): array
    {
        return [
            'success' => $success,
            'data' => $data,
            'message' => $message,
            'errors' => $errors,
            'meta' => $meta === [] ? ['pagination' => null] : $meta,
            'request_id' => RequestId::get(),
            'timestamp' => Carbon::now('UTC')->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function response(array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);

        $requestId = $payload['request_id'];
        if (is_string($requestId)) {
            $response->headers->set(RequestId::HEADER, $requestId);
        }

        return $response;
    }
}
