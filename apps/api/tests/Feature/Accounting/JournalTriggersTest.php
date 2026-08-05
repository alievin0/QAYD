<?php

declare(strict_types=1);

use Database\Seeders\AccountTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TenantHarness;

/**
 * S2-03 — the database integrity behaviour, proven with RAW SQL (no application layer): the CHECK
 * constraints reject bad rows, the AI-autopost trigger blocks a direct AI post, the immutability trigger
 * refuses to modify a posted line even under the privileged owner role, and RLS fails closed.
 */
uses()->group('accounting', 'rls');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

/**
 * A company + one postable account (both persisted on the owner connection).
 *
 * @return array{company_id: int, account_id: int}
 */
function jctx(string $name): array
{
    $co = TenantHarness::seedCompany($name);
    $typeId = (int) TenantHarness::owner()->table('account_types')->where('key', 'asset')->value('id');
    $accountId = (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance)
         VALUES (?, ?, 'CASH', 'Cash', 'نقد', 'debit') RETURNING id",
        [$co['company_id'], $typeId],
    )->id;

    return ['company_id' => $co['company_id'], 'account_id' => $accountId];
}

function jEntry(int $companyId, string $status = 'draft', bool $ai = false, string $td = '0', string $tc = '0'): int
{
    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status, ai_generated, total_debit, total_credit)
         VALUES (?, ?, '2026-07-01', 'manual', 'KWD', ?, ?, ?, ?) RETURNING id",
        [$companyId, 'JE-'.uniqid(), $status, $ai ? 'true' : 'false', $td, $tc],
    )->id;
}

function jLine(int $companyId, int $entryId, int $accountId, int $lineNo, string $debit, string $credit): int
{
    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO journal_lines (company_id, journal_entry_id, line_number, account_id, currency_code, debit, credit)
         VALUES (?, ?, ?, ?, 'KWD', ?, ?) RETURNING id",
        [$companyId, $entryId, $lineNo, $accountId, $debit, $credit],
    )->id;
}

function jThrows(callable $fn): bool
{
    try {
        $fn();

        return false;
    } catch (Throwable) {
        return true;
    }
}

// ---------------------------------------------------------------- CHECK constraints

it('rejects a journal entry whose header totals do not balance (chk_je_balanced)', function (): void {
    $c = jctx('Balance Co');

    expect(jThrows(fn () => jEntry($c['company_id'], 'draft', false, '100', '50')))->toBeTrue();
    expect(jEntry($c['company_id'], 'draft', false, '100', '100'))->toBeGreaterThan(0);
});

it('rejects lines that are two-sided, zero, or negative (chk_jl_one_sided)', function (): void {
    $c = jctx('One Sided Co');
    $entry = jEntry($c['company_id']);

    expect(jThrows(fn () => jLine($c['company_id'], $entry, $c['account_id'], 1, '10', '10')))->toBeTrue(); // both sides
    expect(jThrows(fn () => jLine($c['company_id'], $entry, $c['account_id'], 2, '0', '0')))->toBeTrue();   // both zero
    expect(jThrows(fn () => jLine($c['company_id'], $entry, $c['account_id'], 3, '-5', '0')))->toBeTrue();  // negative
    expect(jLine($c['company_id'], $entry, $c['account_id'], 4, '10', '0'))->toBeGreaterThan(0);            // valid
});

it('rejects a duplicate line_number within an entry (uq_jl_line_number)', function (): void {
    $c = jctx('Dup Line Co');
    $entry = jEntry($c['company_id']);
    jLine($c['company_id'], $entry, $c['account_id'], 1, '10', '0');

    expect(jThrows(fn () => jLine($c['company_id'], $entry, $c['account_id'], 1, '20', '0')))->toBeTrue();
});

// ---------------------------------------------------------------- trg_no_ai_autopost

it('rejects inserting an AI-generated entry as anything but draft (trg_no_ai_autopost)', function (): void {
    $c = jctx('AI Co');

    // Otherwise valid (balanced, ai_confidence present) — only the non-draft status is the violation.
    expect(jThrows(fn () => TenantHarness::owner()->statement(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status, ai_generated, ai_confidence, total_debit, total_credit)
         VALUES (?, ?, '2026-07-01', 'ai_generated', 'KWD', 'posted', true, 0.9, 100, 100)",
        [$c['company_id'], 'JE-'.uniqid()],
    )))->toBeTrue();

    // An AI-generated DRAFT is permitted.
    $draftId = (int) TenantHarness::owner()->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status, ai_generated, ai_confidence)
         VALUES (?, ?, '2026-07-01', 'ai_generated', 'KWD', 'draft', true, 0.9) RETURNING id",
        [$c['company_id'], 'JE-'.uniqid()],
    )->id;
    expect($draftId)->toBeGreaterThan(0);

    // A non-AI entry may be inserted as posted (the trigger only guards AI-generated rows).
    expect(jEntry($c['company_id'], 'posted', false, '100', '100'))->toBeGreaterThan(0);
});

// ---------------------------------------------------------------- trg_journal_lines_no_update_when_posted

it('blocks UPDATE and DELETE of a line whose parent entry is posted — even under the privileged role', function (): void {
    $c = jctx('Immutable Co');
    $posted = jEntry($c['company_id'], 'posted', false, '100', '100');
    $lineId = jLine($c['company_id'], $posted, $c['account_id'], 1, '100', '0');

    // As the OWNER (superuser, bypasses RLS) the trigger STILL refuses the mutation.
    expect(jThrows(fn () => TenantHarness::owner()->update('UPDATE journal_lines SET description = ? WHERE id = ?', ['tamper', $lineId])))->toBeTrue();
    expect(jThrows(fn () => TenantHarness::owner()->delete('DELETE FROM journal_lines WHERE id = ?', [$lineId])))->toBeTrue();
});

it('allows UPDATE and DELETE of a line whose parent entry is still a draft', function (): void {
    $c = jctx('Draft Co');
    $draft = jEntry($c['company_id'], 'draft', false, '0', '0');
    $lineId = jLine($c['company_id'], $draft, $c['account_id'], 1, '100', '0');

    expect(TenantHarness::owner()->update('UPDATE journal_lines SET description = ? WHERE id = ?', ['ok', $lineId]))->toBe(1);
    expect(TenantHarness::owner()->delete('DELETE FROM journal_lines WHERE id = ?', [$lineId]))->toBe(1);
});

// ---------------------------------------------------------------- RLS fail-closed

it('returns zero journal rows with no tenant context (RLS fail-closed on both tables)', function (): void {
    $c = jctx('Fail Closed Co');
    $entry = jEntry($c['company_id'], 'draft', false, '0', '0');
    jLine($c['company_id'], $entry, $c['account_id'], 1, '100', '0');

    $entries = TenantHarness::app()->selectOne('SELECT count(*) AS n FROM journal_entries');
    $lines = TenantHarness::app()->selectOne('SELECT count(*) AS n FROM journal_lines');

    expect((int) $entries->n)->toBe(0);
    expect((int) $lines->n)->toBe(0);
});
