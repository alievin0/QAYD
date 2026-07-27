<?php

declare(strict_types=1);

use App\Exceptions\Domain\UnbalancedEntryException;
use Illuminate\Support\Facades\Route;

/**
 * S1-16 (b) — the global exception handler renders thrown exceptions as coded error envelopes,
 * never a stack trace (docs/api/API_ERROR_HANDLING.md "# Error Code Catalog", "# Security").
 *
 * Routes are registered on the `api` group at runtime so the request flows through the exact
 * request-id + envelope middleware and the global handler a real endpoint would.
 */
beforeEach(function (): void {
    Route::middleware('api')->prefix('api')->group(function (): void {
        Route::get('/v1/_test/unbalanced', fn () => throw new UnbalancedEntryException(
            'Total debits (1,250.0000) do not equal total credits (1,200.0000).',
            ['total_debit' => '1250.0000', 'total_credit' => '1200.0000'],
        ));

        Route::get('/v1/_test/boom', fn () => throw new RuntimeException('secret internal detail: table exploded at line 42'));
    });
});

it('renders a thrown domain exception as a coded error envelope with success=false', function (): void {
    $response = $this->getJson('/api/v1/_test/unbalanced');

    $response
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'data' => null,
            'errors' => [
                [
                    'code' => 'UNBALANCED_ENTRY',
                    'field' => 'journal_lines',
                    'message' => 'Total debits (1,250.0000) do not equal total credits (1,200.0000).',
                ],
            ],
        ]);

    $body = $response->json();

    expect($body['data'])->toBeNull();
    expect($body['success'])->toBeFalse();
    expect($body)->not->toHaveKey('trace');
    expect($body['request_id'])->toBeString()->not->toBe('');
    expect($response->headers->get('X-Request-Id'))->toBe($body['request_id']);

    // No internals leak: the exception class name never appears anywhere in the response.
    expect(json_encode($body))->not->toContain('UnbalancedEntryException');
});

it('renders an unhandled exception as a generic INTERNAL_ERROR without leaking internals', function (): void {
    $response = $this->getJson('/api/v1/_test/boom');

    $response
        ->assertStatus(500)
        ->assertJson([
            'success' => false,
            'data' => null,
            'errors' => [['code' => 'INTERNAL_ERROR']],
        ]);

    $raw = json_encode($response->json());

    expect($raw)->not->toContain('secret internal detail');
    expect($raw)->not->toContain('RuntimeException');
    expect($response->json('message'))->toBe('An unexpected error occurred. Our team has been notified.');
});
