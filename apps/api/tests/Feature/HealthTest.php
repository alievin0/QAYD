<?php

it('returns a healthy status from the health endpoint', function (): void {
    $response = $this->getJson('/api/v1/health');

    $response
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'service' => 'qayd-api',
        ]);
});
