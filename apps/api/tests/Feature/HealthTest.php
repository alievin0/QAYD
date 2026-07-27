<?php

declare(strict_types=1);

/**
 * S1-16 (a) — the health endpoint answers inside the standard response envelope
 * `{ success, data, message, errors, meta, request_id, timestamp }`.
 */
it('returns the standard envelope with request_id and timestamp', function (): void {
    $response = $this->getJson('/api/v1/health');

    $response
        ->assertOk()
        ->assertJson([
            'success' => true,
            'data' => ['status' => 'ok', 'service' => 'qayd-api'],
            'message' => 'Service is healthy.',
            'errors' => [],
        ]);

    $body = $response->json();

    foreach (['success', 'data', 'message', 'errors', 'meta', 'request_id', 'timestamp'] as $key) {
        expect($body)->toHaveKey($key);
    }

    expect($body['request_id'])->toBeString()->not->toBe('');
    expect($body['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    expect($body['meta'])->toBe(['pagination' => null]);
});
