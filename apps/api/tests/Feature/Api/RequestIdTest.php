<?php

declare(strict_types=1);

/**
 * S1-16 (c) — the `request_id` correlation contract (docs/api/API_ERROR_HANDLING.md
 * "# request_id & Tracing").
 */
it('returns a request id header that matches the envelope body and is stable within a request', function (): void {
    $response = $this->getJson('/api/v1/health');

    $header = $response->headers->get('X-Request-Id');

    expect($header)->not->toBeNull();
    // The header and the body carry the SAME id: stable within a single request.
    expect($response->json('request_id'))->toBe($header);
});

it('mints a UUIDv4 request id when the client supplies none', function (): void {
    $requestId = $this->getJson('/api/v1/health')->json('request_id');

    expect($requestId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

it('honours a valid inbound X-Request-Id header', function (): void {
    $incoming = '11111111-2222-4333-8444-555555555555';

    $response = $this->getJson('/api/v1/health', ['X-Request-Id' => $incoming]);

    expect($response->headers->get('X-Request-Id'))->toBe($incoming);
    expect($response->json('request_id'))->toBe($incoming);
});

it('ignores a malformed inbound X-Request-Id and mints its own', function (): void {
    $response = $this->getJson('/api/v1/health', ['X-Request-Id' => 'not-a-uuid']);

    expect($response->json('request_id'))
        ->not->toBe('not-a-uuid')
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});
