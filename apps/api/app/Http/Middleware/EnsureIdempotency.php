<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` handling for money-moving requests (S2-11 prerequisite; the rest is S2-13,
 * docs/api/API_IDEMPOTENCY.md).
 *
 * A retried post must not become a second posting. The client mints a key, sends it on every attempt,
 * and this middleware guarantees the operation runs at most once per key:
 *
 *  - **No key** → nothing happens. Idempotency is opt-in per request, so a caller that does not need it
 *    pays nothing and sees no behaviour change.
 *  - **A key seen before, same body** → the stored response is replayed. The handler is never entered,
 *    so nothing is written twice.
 *  - **A key seen before, DIFFERENT body** → `409 IDEMPOTENCY_KEY_CONFLICT`. This is the case that must
 *    not be lenient: replaying a stored response for a request that does not match would tell the caller
 *    an operation succeeded that never ran, which is worse than having no idempotency at all.
 *
 * The race is settled by the database, not by a check-then-act. Two concurrent retries both find no
 * stored row and both proceed to INSERT; `uq_idempotency_scope` lets exactly one win, and the loser is
 * caught here and replays the winner's response. A `SELECT` followed by an `INSERT` would leave a window
 * between them wide enough for both to post.
 *
 * Only a SUCCESSFUL response is recorded. A failed attempt should stay retryable with the same key — the
 * caller fixes the problem and sends it again — and storing the failure would freeze the error in place.
 */
final class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || trim($key) === '') {
            return $next($request);
        }

        $companyId = TenantContext::companyId();

        if ($companyId === null) {
            // No tenant context means no scope to key against; the tenant middleware will already have
            // refused anything that matters, so pass through rather than invent a boundary here.
            return $next($request);
        }

        $endpoint = $request->method().' '.$request->path();
        $hash = hash('sha256', $request->getContent());
        $connection = DB::connection(TenantContext::connection());

        $stored = $connection->selectOne(
            'SELECT request_hash, response_status, response_body::text AS response_body
             FROM idempotency_keys
             WHERE company_id = ? AND endpoint = ? AND idempotency_key = ?',
            [$companyId, $endpoint, $key],
        );

        if ($stored !== null) {
            return $this->replayOrConflict($stored, $hash);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        try {
            $connection->insert(
                'INSERT INTO idempotency_keys
                    (company_id, endpoint, idempotency_key, request_hash, response_status, response_body)
                 VALUES (?, ?, ?, ?, ?, ?::jsonb)',
                [$companyId, $endpoint, $key, $hash, $response->getStatusCode(), $response->getContent()],
            );
        } catch (QueryException $exception) {
            // Lost the race: a concurrent retry recorded this key first. Its response is the one of
            // record — ours describes work the unique constraint has just told us was duplicated.
            $winner = $connection->selectOne(
                'SELECT request_hash, response_status, response_body::text AS response_body
                 FROM idempotency_keys
                 WHERE company_id = ? AND endpoint = ? AND idempotency_key = ?',
                [$companyId, $endpoint, $key],
            );

            if ($winner === null) {
                throw $exception;
            }

            return $this->replayOrConflict($winner, $hash);
        }

        return $response;
    }

    /** Replay a stored response, or refuse when the key has been reused for a different request. */
    private function replayOrConflict(mixed $stored, string $hash): Response
    {
        if (SqlRow::string($stored, 'request_hash') !== $hash) {
            $message = 'This Idempotency-Key was already used for a different request.';

            return ApiResponse::error(
                'IDEMPOTENCY_KEY_CONFLICT',
                $message,
                [[
                    'code' => 'IDEMPOTENCY_KEY_CONFLICT',
                    'field' => self::HEADER,
                    'message' => $message,
                    'meta' => [],
                ]],
                409,
            );
        }

        return response(
            SqlRow::string($stored, 'response_body'),
            SqlRow::int($stored, 'response_status'),
        )
            ->header('Content-Type', 'application/json')
            // Says plainly that nothing ran this time, which a client retrying a payment needs to know.
            ->header('Idempotent-Replay', 'true');
    }
}
