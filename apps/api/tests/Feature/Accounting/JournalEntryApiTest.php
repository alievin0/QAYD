<?php

declare(strict_types=1);

use App\Domain\Accounting\MonthlyFiscalPeriodGenerator;
use App\Models\User;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\AuthFixtures;
use Tests\Support\RbacFixtures;
use Tests\Support\TenantHarness;

/**
 * The journal-entry HTTP API (S2-11 prerequisite) — the contract, end to end through the real
 * middleware stack.
 *
 * These are CONTRACT tests, not rule tests. Whether an entry balances, whether a period is open,
 * whether a posted entry is immutable — all of that is already proven against the Actions by the S2-04,
 * S2-05 and S2-06 suites, and re-proving it here would only duplicate them. What is new, and therefore
 * tested, is the door: that each endpoint reaches the right Action, that the permission split holds,
 * that a cross-tenant id is invisible, that the envelope is standard, and that a retried POST /post
 * does not post twice.
 */
uses()->group('accounting', 'api');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

/**
 * A member whose role carries the given permission keys, in a company with an open FY2026 and two
 * postable accounts.
 *
 * @param  list<string>  $permissions
 * @return array{user: User, uuid: string, company_id: int, debit: int, credit: int}
 */
function jeMember(array $permissions): array
{
    $user = User::factory()->create();
    $m = AuthFixtures::membership($user->id, 'JE '.uniqid(), 'je_role');
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

    $typeId = (int) $owner->table('account_types')->where('key', 'asset')->value('id');
    $account = fn (string $code): int => (int) $owner->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance, status)
         VALUES (?, ?, ?, 'Acc', 'حساب', 'debit', 'active') RETURNING id",
        [$companyId, $typeId, $code.bin2hex(random_bytes(4))],
    )->id;

    return [
        'user' => $user,
        'uuid' => $m['company_uuid'],
        'company_id' => $companyId,
        'debit' => $account('D'),
        'credit' => $account('C'),
    ];
}

/**
 * @param  array<string, mixed>  $m
 * @return array<string, mixed>
 */
function jeBody(array $m, string $debit = '100.0000', string $credit = '100.0000'): array
{
    return [
        'journal_date' => '2026-02-10',
        'entry_type' => 'manual',
        'currency_code' => 'KWD',
        'memo' => 'Contract test',
        'lines' => [
            ['account_id' => $m['debit'], 'debit' => $debit, 'credit' => '0'],
            ['account_id' => $m['credit'], 'debit' => '0', 'credit' => $credit],
        ],
    ];
}

/** Everything a full draft→post journey needs. */
function jeFullMember(): array
{
    return jeMember([
        'accounting.journal.read', 'accounting.create', 'accounting.update', 'accounting.approve',
    ]);
}

// ---------------------------------------------------------------- auth + permissions

it('requires authentication on every journal route', function (): void {
    $this->getJson('/api/v1/accounting/journal-entries', ['X-Company-Id' => 'x'])->assertStatus(401);
    $this->postJson('/api/v1/accounting/journal-entries', [], ['X-Company-Id' => 'x'])->assertStatus(401);
});

it('denies journal reads without accounting.journal.read (403)', function (): void {
    $m = jeMember([]);

    $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/journal-entries', ['X-Company-Id' => $m['uuid']])
        ->assertStatus(403)
        ->assertJsonPath('errors.0.code', 'INSUFFICIENT_PERMISSION');
});

it('denies drafting for a reader (403), so looking is separate from writing', function (): void {
    $m = jeMember(['accounting.journal.read']);

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertStatus(403);
});

it('denies posting for someone who can only draft (403), so preparing is separate from approving', function (): void {
    $m = jeMember(['accounting.journal.read', 'accounting.create', 'accounting.update']);

    $id = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry.id');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$id}/post", [], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(403);
});

// ---------------------------------------------------------------- create / read

it('creates a DRAFT with its lines and the standard envelope', function (): void {
    $m = jeFullMember();

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'accounting.journal_entry.created')
        ->assertJsonPath('data.journal_entry.status', 'draft')
        ->assertJsonCount(2, 'data.journal_entry.lines')
        ->assertJsonStructure([
            'success',
            'data' => ['journal_entry' => [
                'id', 'journal_number', 'journal_date', 'entry_type', 'status', 'currency_code',
                'total_debit', 'total_credit', 'version', 'is_reversal', 'lines' => [
                    ['id', 'line_number', 'account_id', 'debit', 'credit', 'base_debit', 'base_credit'],
                ],
            ]],
            'message', 'errors', 'meta', 'request_id', 'timestamp',
        ]);
});

it('rejects a malformed body with 422 before reaching the Action', function (): void {
    $m = jeFullMember();

    $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', [
            'journal_date' => '10-02-2026', 'entry_type' => 'manual', 'currency_code' => 'KWD', 'lines' => [],
        ], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(422);
});

it('lists entries newest first with offset pagination in the envelope meta', function (): void {
    $m = jeFullMember();
    $create = fn () => $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])->assertCreated();

    $create();
    $create();

    $this->actingAs($m['user'], 'web')
        ->getJson('/api/v1/accounting/journal-entries?per_page=1', ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonCount(1, 'data.journal_entries')
        ->assertJsonPath('meta.pagination.page', 1)
        ->assertJsonPath('meta.pagination.per_page', 1)
        ->assertJsonPath('meta.pagination.total', 2);
});

it('shows one entry with its lines', function (): void {
    $m = jeFullMember();
    $id = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry.id');

    $this->actingAs($m['user'], 'web')
        ->getJson("/api/v1/accounting/journal-entries/{$id}", ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.journal_entry.id', $id)
        ->assertJsonCount(2, 'data.journal_entry.lines');
});

it('returns 404, never another company data, for a cross-tenant entry id', function (): void {
    $a = jeFullMember();
    $b = jeFullMember();

    $foreignId = $this->actingAs($b['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($b), ['X-Company-Id' => $b['uuid']])
        ->assertCreated()->json('data.journal_entry.id');

    $this->actingAs($a['user'], 'web')
        ->getJson("/api/v1/accounting/journal-entries/{$foreignId}", ['X-Company-Id' => $a['uuid']])
        ->assertStatus(404);
});

// ---------------------------------------------------------------- update / submit

it('updates a draft and bumps its version', function (): void {
    $m = jeFullMember();
    $created = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry');

    $this->actingAs($m['user'], 'web')
        ->patchJson("/api/v1/accounting/journal-entries/{$created['id']}", [
            ...jeBody($m, '250.0000', '250.0000'),
            'version' => $created['version'],
        ], ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.journal_entry.version', $created['version'] + 1);
});

it('surfaces the Action version conflict as 409 rather than overwriting', function (): void {
    $m = jeFullMember();
    $created = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry');

    // A stale version — as if another editor had saved in between.
    $this->actingAs($m['user'], 'web')
        ->patchJson("/api/v1/accounting/journal-entries/{$created['id']}", [
            ...jeBody($m), 'version' => $created['version'] + 99,
        ], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(409);
});

it('submits a draft for approval', function (): void {
    $m = jeFullMember();
    $created = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$created['id']}/submit", [
            'version' => $created['version'],
        ], ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.journal_entry.status', 'pending_approval');
});

// ---------------------------------------------------------------- post / reverse / void

it('posts a balanced draft through the one authorized path', function (): void {
    $m = jeFullMember();
    $id = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry.id');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$id}/post", [], ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.journal_entry.status', 'posted')
        ->assertJsonPath('data.journal_entry.total_debit', '100.0000');
});

it('surfaces an unbalanced post as the Action 422 BALANCE_MISMATCH, never hidden', function (): void {
    $m = jeFullMember();
    $id = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m, '100.0000', '90.0000'), [
            'X-Company-Id' => $m['uuid'],
        ])->assertCreated()->json('data.journal_entry.id');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$id}/post", [], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'BALANCE_MISMATCH');
});

it('does not post twice when the same Idempotency-Key is retried', function (): void {
    $m = jeFullMember();
    $id = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry.id');

    $headers = ['X-Company-Id' => $m['uuid'], 'Idempotency-Key' => 'post-'.uniqid()];

    $first = $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$id}/post", [], $headers)->assertOk();

    $second = $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$id}/post", [], $headers)->assertOk();

    // Replayed rather than re-executed: without idempotency the second call would hit the posting
    // engine's own guard and come back 409, not 200.
    expect($second->headers->get('Idempotent-Replay'))->toBe('true');
    expect($second->json('data.journal_entry.journal_number'))
        ->toBe($first->json('data.journal_entry.journal_number'));

    $ledgerRows = TenantHarness::owner()->scalar(
        'SELECT COUNT(*) FROM ledger_entries WHERE journal_entry_id = ?',
        [$id],
    );
    expect((int) $ledgerRows)->toBe(2);
});

it('reverses a posted entry into a NEW mirror and leaves the original standing', function (): void {
    $m = jeFullMember();
    $id = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry.id');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$id}/post", [], ['X-Company-Id' => $m['uuid']])->assertOk();

    $mirror = $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", [
            'reason' => 'Duplicate capture',
        ], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(201)
        ->assertJsonPath('data.journal_entry.is_reversal', true)
        ->json('data.journal_entry');

    expect($mirror['reversed_entry_id'])->toBe($id);

    $this->actingAs($m['user'], 'web')
        ->getJson("/api/v1/accounting/journal-entries/{$id}", ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.journal_entry.status', 'reversed')
        ->assertJsonPath('data.journal_entry.reversal_entry_id', $mirror['id']);
});

it('requires a reason to reverse (422)', function (): void {
    $m = jeFullMember();
    $id = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry.id');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$id}/post", [], ['X-Company-Id' => $m['uuid']])->assertOk();

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$id}/reverse", [], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(422);
});

it('voids a draft, and refuses to void a posted entry with 409 IMMUTABLE_RECORD', function (): void {
    $m = jeFullMember();

    $draftId = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry.id');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$draftId}/void", [], ['X-Company-Id' => $m['uuid']])
        ->assertOk()
        ->assertJsonPath('data.journal_entry.status', 'voided');

    $postedId = $this->actingAs($m['user'], 'web')
        ->postJson('/api/v1/accounting/journal-entries', jeBody($m), ['X-Company-Id' => $m['uuid']])
        ->assertCreated()->json('data.journal_entry.id');

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$postedId}/post", [], ['X-Company-Id' => $m['uuid']])->assertOk();

    $this->actingAs($m['user'], 'web')
        ->postJson("/api/v1/accounting/journal-entries/{$postedId}/void", [], ['X-Company-Id' => $m['uuid']])
        ->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'IMMUTABLE_RECORD');
});
