<?php

declare(strict_types=1);

use App\Domain\Accounting\MonthlyFiscalPeriodGenerator;
use App\Models\User;
use Tests\Support\AuthFixtures;
use Tests\Support\RbacFixtures;
use Tests\Support\TenantHarness;

/**
 * The fiscal-period read API (S2-12 prerequisite).
 *
 * Contract tests: the shape, the ordering, the permission, and the tenant boundary. The calendar's
 * BEHAVIOUR — what closing or locking a period does, and who may do it — belongs to S2-07 and is
 * already proven there; this endpoint has no write counterpart to test.
 */
uses()->group('accounting', 'api');

beforeEach(function (): void {
    TenantHarness::boot();
});

/**
 * A member whose role carries the given permissions, in a company with FY2026 fully generated.
 *
 * @param  list<string>  $permissions
 * @return array{user: User, uuid: string, company_id: int, year_id: int}
 */
function fpaMember(array $permissions): array
{
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'FP '.uniqid(), 'fp_role');
    $companyId = (int) $m['company_id'];

    foreach ($permissions as $key) {
        RbacFixtures::attachToRole($m['role_id'], RbacFixtures::permission($key, 'accounting'));
    }

    $owner = TenantHarness::owner();
    $yearId = (int) $owner->selectOne(
        "INSERT INTO fiscal_years (company_id, name, start_date, end_date, status)
         VALUES (?, ?, '2026-01-01', '2026-12-31', 'open') RETURNING id",
        [$companyId, 'FY-'.bin2hex(random_bytes(4))],
    )->id;
    MonthlyFiscalPeriodGenerator::generate($owner, $companyId, $yearId, '2026-01-01', '2026-12-31', 'open');

    return [
        'user' => $user,
        'uuid' => $m['company_uuid'],
        'company_id' => $companyId,
        'year_id' => $yearId,
    ];
}

it('lists the calendar with exactly the fields a period selector needs', function (): void {
    $m = fpaMember(['accounting.journal.read']);

    $response = $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/fiscal-periods', ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'accounting.fiscal_period.list')
        ->assertJsonCount(12, 'data.fiscal_periods');

    $response->assertJsonStructure([
        'data' => [
            'fiscal_periods' => [
                ['id', 'fiscal_year_id', 'period_number', 'name', 'start_date', 'end_date', 'status'],
            ],
        ],
        'message', 'errors', 'meta', 'request_id', 'timestamp',
    ]);

    $first = $response->json('data.fiscal_periods.0');

    expect($first['period_number'])->toBe(1);
    expect($first['start_date'])->toBe('2026-01-01');
    expect($first['end_date'])->toBe('2026-01-31');
    expect($first['status'])->toBe('open');
    expect($first['fiscal_year_id'])->toBe($m['year_id']);

    // The close history stays unread: a screen that only picks a period has no business seeing it.
    expect($first)->not->toHaveKey('closed_at');
    expect($first)->not->toHaveKey('reopen_reason');
});

it('orders by fiscal year, then by period number within the year', function (): void {
    $m = fpaMember(['accounting.journal.read']);

    // A second year, generated after the first, so insertion order alone would not produce this order.
    $owner = TenantHarness::owner();
    $secondYear = (int) $owner->selectOne(
        "INSERT INTO fiscal_years (company_id, name, start_date, end_date, status)
         VALUES (?, ?, '2027-01-01', '2027-12-31', 'future') RETURNING id",
        [$m['company_id'], 'FY-'.bin2hex(random_bytes(4))],
    )->id;
    MonthlyFiscalPeriodGenerator::generate(
        $owner, $m['company_id'], $secondYear, '2027-01-01', '2027-12-31', 'future',
    );

    $periods = $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/fiscal-periods', ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->json('data.fiscal_periods');

    expect($periods)->toHaveCount(24);

    // Grouped by year, ascending period number inside each group.
    expect(array_column(array_slice($periods, 0, 12), 'fiscal_year_id'))
        ->toBe(array_fill(0, 12, $m['year_id']));
    expect(array_column(array_slice($periods, 0, 12), 'period_number'))
        ->toBe(range(1, 12));
    expect(array_column(array_slice($periods, 12), 'fiscal_year_id'))
        ->toBe(array_fill(0, 12, $secondYear));
    expect(array_column(array_slice($periods, 12), 'period_number'))
        ->toBe(range(1, 12));
});

it('denies the calendar without accounting.journal.read (403)', function (): void {
    $m = fpaMember([]);

    $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/fiscal-periods', ['X-Company-Id' => $m['uuid']])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_PERMISSION');
});

it('requires authentication for the calendar', function (): void {
    $this->getJson('/api/v1/accounting/fiscal-periods', ['X-Company-Id' => 'anything'])
        ->assertStatus(401);
});

it('never lists another company periods', function (): void {
    $a = fpaMember(['accounting.journal.read']);
    fpaMember(['accounting.journal.read']);

    $periods = $this->actingAs($a['user'], 'web')
        ->getJson('/api/v1/accounting/fiscal-periods', ['X-Company-Id' => $a['uuid']])
        ->assertOk()
        ->json('data.fiscal_periods');

    // Twelve, not twenty-four: the other company's identically-shaped calendar is invisible.
    expect($periods)->toHaveCount(12);
    expect(array_values(array_unique(array_column($periods, 'fiscal_year_id'))))
        ->toBe([$a['year_id']]);
});

it('has no write counterpart', function (): void {
    $m = fpaMember(['accounting.journal.read']);

    // Not merely unauthorized — the route does not exist.
    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/fiscal-periods', [], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(405);
});
