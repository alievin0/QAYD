<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The global exception → coded-envelope mapper (S1-16, docs/api/API_ERROR_HANDLING.md).
 *
 * Registered from bootstrap/app.php for every `/api/*` (or JSON) request, it turns any thrown
 * exception into the standard error envelope: a stable catalog `code`, the matching HTTP status, and
 * `success: false` with `data: null`. An unhandled exception becomes `INTERNAL_ERROR` (500) with a
 * fixed, non-leaking message — never a stack trace, class name, SQL, or file path, in any environment
 * (docs/api/API_ERROR_HANDLING.md "# Security"). The originating exception is still reported through
 * Laravel's normal reporting channel, correlated to this response by the `request_id` already pinned
 * into the log context.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof DomainException => self::domain($e),
            $e instanceof ValidationException => self::validation($e),
            $e instanceof AuthenticationException => ApiResponse::error(
                'AUTHENTICATION_FAILED',
                'Authentication failed.',
                [],
                401,
            ),
            $e instanceof AuthorizationException => ApiResponse::error(
                'INSUFFICIENT_PERMISSION',
                'You do not have permission to perform this action.',
                [],
                403,
            ),
            $e instanceof ModelNotFoundException => ApiResponse::error(
                'RESOURCE_NOT_FOUND',
                'The requested resource was not found.',
                [],
                404,
            ),
            $e instanceof HttpExceptionInterface => self::http($e),
            default => ApiResponse::error(
                'INTERNAL_ERROR',
                'An unexpected error occurred. Our team has been notified.',
                [],
                500,
            ),
        };
    }

    /**
     * A typed domain exception → its coded envelope, plus any headers the exception carries
     * (e.g. `Retry-After` on a `429` lockout — docs/backend/AUTH_SERVICE.md "# Error Handling").
     */
    private static function domain(DomainException $e): JsonResponse
    {
        $response = ApiResponse::error($e->errorCode(), $e->getMessage(), $e->errorsList(), $e->errorStatus());

        foreach ($e->headers() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * Laravel validation failure → `422 VALIDATION_ERROR` with one `errors[]` entry per failing field
     * (docs/api/API_ERROR_HANDLING.md "# Validation Errors").
     */
    private static function validation(ValidationException $e): JsonResponse
    {
        $errors = [];

        foreach ($e->errors() as $field => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                $errors[] = [
                    'code' => 'VALIDATION_ERROR',
                    'field' => (string) $field,
                    'message' => is_scalar($message) ? (string) $message : '',
                    'meta' => [],
                ];
            }
        }

        return ApiResponse::error('VALIDATION_ERROR', 'The given data was invalid.', $errors, 422);
    }

    /**
     * A generic Symfony/Laravel HTTP exception → the catalog code for its status. Client-set abort
     * messages are safe to surface; 401 is deliberately collapsed to a single generic message so it
     * never reveals whether a token was missing, expired, or revoked.
     */
    private static function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        [$code, $message] = self::mapStatus($status, $e->getMessage());

        return ApiResponse::error($code, $message, [], $status);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function mapStatus(int $status, string $raw): array
    {
        return match ($status) {
            400 => ['BAD_REQUEST', $raw !== '' ? $raw : 'The request was malformed.'],
            401 => ['AUTHENTICATION_FAILED', 'Authentication failed.'],
            403 => ['INSUFFICIENT_PERMISSION', 'You do not have permission to perform this action.'],
            404 => ['RESOURCE_NOT_FOUND', 'The requested resource was not found.'],
            405 => ['METHOD_NOT_ALLOWED', 'The HTTP method is not allowed for this resource.'],
            409 => ['CONFLICT', $raw !== '' ? $raw : 'The request conflicts with the current state of the resource.'],
            422 => ['VALIDATION_ERROR', $raw !== '' ? $raw : 'The given data was invalid.'],
            429 => ['RATE_LIMITED', 'Too many requests. Please slow down.'],
            503 => ['SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.'],
            default => $status >= 500
                ? ['INTERNAL_ERROR', 'An unexpected error occurred. Our team has been notified.']
                : ['HTTP_ERROR', $raw !== '' ? $raw : 'The request could not be completed.'],
        };
    }
}
