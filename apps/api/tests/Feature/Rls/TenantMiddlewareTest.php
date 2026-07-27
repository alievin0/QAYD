<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Support\TenantHarness;

uses()->group('rls', 'isolation');

beforeEach(function (): void {
    TenantHarness::boot();

    $this->a = TenantHarness::seedCompany('Middleware A');
    $this->b = TenantHarness::seedCompany('Middleware B');

    // Load the acting user through the owner connection (users is RLS-enabled; the owner bypasses).
    $this->userA = User::on(TenantHarness::OWNER)->findOrFail($this->a['user_id']);
});

it('resolves the active company and returns an in-tenant membership', function (): void {
    $this->actingAs($this->userA)
        ->getJson('/api/v1/memberships/'.$this->a['membership_id'], [
            'X-Company-Id' => $this->a['company_uuid'],
        ])
        ->assertOk()
        ->assertJson([
            'id' => $this->a['membership_id'],
            'company_id' => $this->a['company_id'],
        ]);
});

it('returns 404 (not 403) for a cross-tenant id read within a valid company', function (): void {
    // User A is a valid member of A; they ask for company B's membership row by id. RLS +
    // CompanyScope scope the query to A, so B's row resolves to "not found" — 404, never the record.
    $response = $this->actingAs($this->userA)
        ->getJson('/api/v1/memberships/'.$this->b['membership_id'], [
            'X-Company-Id' => $this->a['company_uuid'],
        ]);

    $response->assertNotFound();
    expect($response->getStatusCode())->toBe(404);
});

it('returns 404 (not 403) when switching to a company the user does not belong to', function (): void {
    // User A is NOT a member of B. Asking to act as B must be indistinguishable from "B does not
    // exist": 404, never 403 (which would confirm B exists — a tenant-enumeration side channel).
    $response = $this->actingAs($this->userA)
        ->getJson('/api/v1/memberships/'.$this->b['membership_id'], [
            'X-Company-Id' => $this->b['company_uuid'],
        ]);

    $response->assertNotFound();
    expect($response->getStatusCode())->toBe(404);
});

it('returns 404 for an unknown company uuid', function (): void {
    $this->actingAs($this->userA)
        ->getJson('/api/v1/memberships/'.$this->a['membership_id'], [
            'X-Company-Id' => '00000000-0000-0000-0000-000000000000',
        ])
        ->assertNotFound();
});

it('rejects a request with no X-Company-Id header', function (): void {
    $this->actingAs($this->userA)
        ->getJson('/api/v1/memberships/'.$this->a['membership_id'])
        ->assertStatus(400);
});

it('rejects an unauthenticated request', function (): void {
    $this->getJson('/api/v1/memberships/'.$this->a['membership_id'], [
        'X-Company-Id' => $this->a['company_uuid'],
    ])->assertStatus(401);
});
