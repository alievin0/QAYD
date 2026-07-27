<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns the per-request correlation id at the very front of the `api` middleware group (S1-16,
 * docs/api/API_ERROR_HANDLING.md "# request_id & Tracing").
 *
 * It runs before routing/auth/validation so every downstream layer — the response envelope, the
 * global exception handler, log lines, and the audit write path — reads one stable id via
 * {@see RequestId}. The resolved id is echoed on the response as the `X-Request-Id` header (duplicated
 * at the transport layer for proxies/log shippers that never parse the JSON body).
 */
final class AssignRequestId
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = RequestId::resolve($request);

        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $id);

        return $response;
    }
}
