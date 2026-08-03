<?php

declare(strict_types=1);

use App\Actions\Accounting\ClosePeriodAction;
use App\Actions\Accounting\LockPeriodAction;
use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Accounting\ReopenPeriodAction;
use App\Domain\Accounting\MonthlyFiscalPeriodGenerator;
use App\Events\Accounting\FiscalPeriodClosed;
use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\PeriodRuleException;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\Support\TenantHarness;

/**
 * S2-07 — fiscal periods and the close/lock lifecycle, against real PostgreSQL.
 *
 * The story's whole value is that "you cannot post into a closed month" becomes a constraint rather than
 * a policy, so the tests are written around that: what the calendar guarantees structurally (exact
 * coverage, no overlap, containment in the parent year), what the posting engine does when a month shuts
 * under it, and who is allowed to shut or reopen one.
 *
 * Every date here is pinned. A fiscal boundary is exactly the place an unpinned wall-clock assertion
 * produces an intermittent, ledger-affecting failure (SPRINT_02 Epic C).
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

/** A fiscal year for the company over the given range, filled with its monthly periods. */
function fpYear(
    int $companyId,
    string $start = '2026-01-01',
    string $end = '2026-12-31',
    string $status = 'open',
): int {
    $yearId = (int) TenantHarness::owner()->selectOne(
        'INSERT INTO fiscal_years (company_id, name, start_date, end_date, status)
         VALUES (?, ?, ?, ?, ?::fiscal_year_status) RETURNING id',
        [$companyId, 'FY-'.bin2hex(random_bytes(4)), $start, $end, $status],
    )->id;

    MonthlyFiscalPeriodGenerator::generate(TenantHarness::owner(), $companyId, $yearId, $start, $end, $status);

    return $yearId;
}

/**
 * The period covering $date for the company, hydrated from the OWNER connection.
 *
 * It cannot be read through the model's own connection here: `FiscalPeriod` is bound to the RLS-enforced
 * `pgsql_app` connection, and outside `runInTenant` there is no tenant GUC, so every read returns zero
 * rows — correctly, and `withoutGlobalScopes()` would not change that, because the boundary is the
 * database's, not Eloquent's. So the row is read past RLS and hydrated into an existing model; the
 * Actions then re-read and lock it under a real tenant context.
 */
function fpPeriodOn(int $companyId, string $date): FiscalPeriod
{
    $row = TenantHarness::owner()->selectOne(
        'SELECT * FROM fiscal_periods WHERE company_id = ? AND ?::date BETWEEN start_date AND end_date',
        [$companyId, $date],
    );

    $period = new FiscalPeriod;
    $period->forceFill((array) $row);
    $period->exists = true;

    return $period;
}

/**
 * Grant permission keys to a role. `TenantHarness::seedCompany()` creates a bare role with no
 * permissions, which is the right default — it means every permission a test relies on is one the test
 * asked for out loud, so a 403 assertion cannot pass by accident.
 */
function fpGrant(int $roleId, string ...$keys): void
{
    $owner = TenantHarness::owner();

    foreach ($keys as $key) {
        $owner->statement(
            "INSERT INTO permissions (key, area) VALUES (?, 'accounting') ON CONFLICT (key) DO NOTHING",
            [$key],
        );
        $owner->statement(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT ?, id FROM permissions WHERE key = ? ON CONFLICT DO NOTHING',
            [$roleId, $key],
        );
    }
}

/** An active account for the company. */
function fpAccount(int $companyId): int
{
    $typeId = (int) TenantHarness::owner()->table('account_types')->where('key', 'asset')->value('id');

    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance, status)
         VALUES (?, ?, ?, 'Acc', 'حساب', 'debit', 'active') RETURNING id",
        [$companyId, $typeId, 'P'.bin2hex(random_bytes(6))],
    )->id;
}

/** A balanced two-line draft dated $date. */
function fpDraft(int $companyId, string $date): int
{
    $owner = TenantHarness::owner();
    $debit = fpAccount($companyId);
    $credit = fpAccount($companyId);

    $entryId = (int) $owner->selectOne(
        "INSERT INTO journal_entries (company_id, journal_number, journal_date, entry_type, currency_code, status)
         VALUES (?, ?, ?, 'manual', 'KWD', 'draft') RETURNING id",
        [$companyId, 'DRAFT-'.bin2hex(random_bytes(8)), $date],
    )->id;

    foreach ([[$debit, '40.0000', '0'], [$credit, '0', '40.0000']] as $i => [$accountId, $dr, $cr]) {
        $owner->statement(
            "INSERT INTO journal_lines (company_id, journal_entry_id, line_number, account_id, debit, credit, currency_code, base_debit, base_credit)
             VALUES (?, ?, ?, ?, ?, ?, 'KWD', ?, ?)",
            [$companyId, $entryId, $i + 1, $accountId, $dr, $cr, $dr, $cr],
        );
    }

    return $entryId;
}

// ---------------------------------------------------------------------------
// The calendar itself
// ---------------------------------------------------------------------------

it('fills a fiscal year with twelve monthly periods covering it exactly, with no gap and no overlap', function (): void {
    $c = TenantHarness::seedCompany('Calendar Co');
    fpYear($c['company_id']);

    $periods = TenantHarness::owner()->select(
        'SELECT period_number, start_date, end_date, status FROM fiscal_periods WHERE company_id = ? ORDER BY period_number',
        [$c['company_id']],
    );

    expect($periods)->toHaveCount(12);
    expect($periods[0]->start_date)->toBe('2026-01-01');
    expect((int) $periods[0]->period_number)->toBe(1);
    expect($periods[11]->end_date)->toBe('2026-12-31');
    expect((int) $periods[11]->period_number)->toBe(12);

    // Every period starts the day after the previous one ends: exact coverage, stated as an assertion
    // rather than trusted from the generator's arithmetic.
    for ($i = 1; $i < 12; $i++) {
        $expected = date('Y-m-d', (int) strtotime($periods[$i - 1]->end_date.' +1 day'));
        expect($periods[$i]->start_date)->toBe($expected);
    }
});

it('covers a fiscal year that starts and ends mid-month by clamping to the year boundaries', function (): void {
    $c = TenantHarness::seedCompany('Part Year Co');
    fpYear($c['company_id'], '2026-04-15', '2026-07-10');

    $periods = TenantHarness::owner()->select(
        'SELECT start_date, end_date FROM fiscal_periods WHERE company_id = ? ORDER BY period_number',
        [$c['company_id']],
    );

    expect($periods)->toHaveCount(4);
    expect($periods[0]->start_date)->toBe('2026-04-15');   // clamped up to the year start
    expect($periods[0]->end_date)->toBe('2026-04-30');
    expect($periods[3]->start_date)->toBe('2026-07-01');
    expect($periods[3]->end_date)->toBe('2026-07-10');     // clamped down to the year end
});

it('inherits period status from the parent year, so opening the calendar never closes a month by itself', function (): void {
    $c = TenantHarness::seedCompany('Future Co');
    fpYear($c['company_id'], '2027-01-01', '2027-12-31', 'future');

    $statuses = TenantHarness::owner()->select(
        'SELECT DISTINCT status FROM fiscal_periods WHERE company_id = ?',
        [$c['company_id']],
    );

    expect($statuses)->toHaveCount(1);
    expect($statuses[0]->status)->toBe('future');
});

it('lets the database reject two periods of one company covering the same day', function (): void {
    $c = TenantHarness::seedCompany('Overlap Co');
    $yearId = fpYear($c['company_id']);

    expect(fn () => TenantHarness::owner()->statement(
        "INSERT INTO fiscal_periods (company_id, fiscal_year_id, period_number, name, start_date, end_date)
         VALUES (?, ?, 13, 'Dup Mar 2026', '2026-03-10', '2026-03-20')",
        [$c['company_id'], $yearId],
    ))->toThrow(QueryException::class);
});

it('lets the database reject a period that falls outside its parent fiscal year', function (): void {
    $c = TenantHarness::seedCompany('Containment Co');
    $yearId = fpYear($c['company_id'], '2026-01-01', '2026-06-30');

    expect(fn () => TenantHarness::owner()->statement(
        "INSERT INTO fiscal_periods (company_id, fiscal_year_id, period_number, name, start_date, end_date)
         VALUES (?, ?, 20, 'Aug 2026', '2026-08-01', '2026-08-31')",
        [$c['company_id'], $yearId],
    ))->toThrow(QueryException::class);
});

it('lets the database reject a period whose company differs from its fiscal year company', function (): void {
    $a = TenantHarness::seedCompany('Cross A');
    $b = TenantHarness::seedCompany('Cross B');
    $yearOfA = fpYear($a['company_id']);

    expect(fn () => TenantHarness::owner()->statement(
        "INSERT INTO fiscal_periods (company_id, fiscal_year_id, period_number, name, start_date, end_date)
         VALUES (?, ?, 30, 'Smuggled', '2026-01-05', '2026-01-06')",
        [$b['company_id'], $yearOfA],
    ))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// The posting gate
// ---------------------------------------------------------------------------

it('posts into an open period and stamps the period on both the entry and every ledger row', function (): void {
    $c = TenantHarness::seedCompany('Posting Co');
    fpYear($c['company_id']);
    $entryId = fpDraft($c['company_id'], '2026-07-01');
    $period = fpPeriodOn($c['company_id'], '2026-07-01');

    // Asserted inside the tenant transaction: `runInTenant` rolls back, and the owner connection would
    // not see the uncommitted writes from a different connection anyway.
    TenantHarness::runInTenant($c['company_id'], function () use ($entryId, $period) {
        app(PostJournalEntryAction::class)->execute(JournalEntry::query()->findOrFail($entryId));

        $entry = TenantHarness::app()->selectOne('SELECT fiscal_period_id FROM journal_entries WHERE id = ?', [$entryId]);
        expect((int) $entry->fiscal_period_id)->toBe($period->id);

        $rows = TenantHarness::app()->select('SELECT fiscal_period_id FROM ledger_entries WHERE journal_entry_id = ?', [$entryId]);
        expect($rows)->toHaveCount(2);
        foreach ($rows as $row) {
            expect((int) $row->fiscal_period_id)->toBe($period->id);
        }
    });
});

it('refuses a post into a CLOSED period with 422 CLOSED_PERIOD and projects nothing', function (): void {
    $c = TenantHarness::seedCompany('Closed Post Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.close');
    $period = fpPeriodOn($c['company_id'], '2026-07-01');
    $entryId = fpDraft($c['company_id'], '2026-07-01');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $entryId, $c) {
        app(ClosePeriodAction::class)->execute($period, $c['user_id']);

        try {
            app(PostJournalEntryAction::class)->execute(JournalEntry::query()->findOrFail($entryId));
            expect(false)->toBeTrue('posting into a closed period should have been refused');
        } catch (ClosedPeriodException $e) {
            expect($e->errorCode())->toBe('CLOSED_PERIOD');
            expect($e->errorStatus())->toBe(422);
            expect($e->meta['status'])->toBe('closed');
        }
    });

    // A refused post leaves no trace in the ledger.
    $count = TenantHarness::owner()->scalar('SELECT COUNT(*) FROM ledger_entries WHERE journal_entry_id = ?', [$entryId]);
    expect((int) $count)->toBe(0);
});

it('refuses a post into a LOCKED period with 422 CLOSED_PERIOD', function (): void {
    $c = TenantHarness::seedCompany('Locked Post Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.close', 'accounting.period.lock');
    $period = fpPeriodOn($c['company_id'], '2026-05-04');
    $entryId = fpDraft($c['company_id'], '2026-05-04');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $entryId, $c) {
        app(ClosePeriodAction::class)->execute($period, $c['user_id']);
        app(LockPeriodAction::class)->execute($period->refresh(), $c['user_id']);

        try {
            app(PostJournalEntryAction::class)->execute(JournalEntry::query()->findOrFail($entryId));
            expect(false)->toBeTrue('posting into a locked period should have been refused');
        } catch (ClosedPeriodException $e) {
            expect($e->meta['status'])->toBe('locked');
        }
    });
});

it('closes one month without touching the next — the point of period-level granularity', function (): void {
    $c = TenantHarness::seedCompany('Granular Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.close');
    $january = fpPeriodOn($c['company_id'], '2026-01-15');
    $februaryEntry = fpDraft($c['company_id'], '2026-02-15');

    $posted = TenantHarness::runInTenant($c['company_id'], function () use ($january, $februaryEntry, $c) {
        app(ClosePeriodAction::class)->execute($january, $c['user_id']);

        return app(PostJournalEntryAction::class)->execute(JournalEntry::query()->findOrFail($februaryEntry));
    });

    expect($posted->status)->toBe(JournalEntry::STATUS_POSTED);
});

// ---------------------------------------------------------------------------
// Close / lock / reopen
// ---------------------------------------------------------------------------

it('emits accounting.period.closed and writes an audit row when a period is closed', function (): void {
    Event::fake([FiscalPeriodClosed::class]);

    $c = TenantHarness::seedCompany('Event Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.close');
    $period = fpPeriodOn($c['company_id'], '2026-03-03');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $c) {
        app(ClosePeriodAction::class)->execute($period, $c['user_id']);

        $audit = TenantHarness::app()->selectOne(
            "SELECT action FROM audit_logs WHERE entity_type = 'fiscal_periods' AND entity_id = ? ORDER BY id DESC LIMIT 1",
            [$period->id],
        );
        expect($audit?->action)->toBe('accounting.period.closed');
    });

    Event::assertDispatched(
        FiscalPeriodClosed::class,
        fn (FiscalPeriodClosed $e) => $e->fiscalPeriodId === $period->id && $e->companyId === $c['company_id'],
    );
});

it('refuses to close a period that is not open (409 PERIOD_NOT_OPEN)', function (): void {
    $c = TenantHarness::seedCompany('Double Close Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.close');
    $period = fpPeriodOn($c['company_id'], '2026-06-06');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $c) {
        app(ClosePeriodAction::class)->execute($period, $c['user_id']);

        try {
            app(ClosePeriodAction::class)->execute($period->refresh(), $c['user_id']);
            expect(false)->toBeTrue('a second close should have been refused');
        } catch (PeriodRuleException $e) {
            expect($e->errorCode())->toBe('PERIOD_NOT_OPEN');
            expect($e->errorStatus())->toBe(409);
        }
    });
});

it('refuses to close without accounting.period.close (403)', function (): void {
    $c = TenantHarness::seedCompany('No Perm Co');
    fpYear($c['company_id']);
    $period = fpPeriodOn($c['company_id'], '2026-08-08');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $c) {
        try {
            app(ClosePeriodAction::class)->execute($period, $c['user_id']);
            expect(false)->toBeTrue('closing without the permission should have been refused');
        } catch (PeriodRuleException $e) {
            expect($e->errorCode())->toBe('PERMISSION_DENIED');
            expect($e->errorStatus())->toBe(403);
        }
    });

    expect(fpPeriodOn($c['company_id'], '2026-08-08')->status)->toBe(FiscalPeriod::STATUS_OPEN);
});

it('refuses to lock a period that has not been closed first (409 PERIOD_NOT_CLOSED)', function (): void {
    $c = TenantHarness::seedCompany('Lock Order Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.lock');
    $period = fpPeriodOn($c['company_id'], '2026-09-09');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $c) {
        try {
            app(LockPeriodAction::class)->execute($period, $c['user_id']);
            expect(false)->toBeTrue('locking an open period should have been refused');
        } catch (PeriodRuleException $e) {
            expect($e->errorCode())->toBe('PERIOD_NOT_CLOSED');
        }
    });
});

it('reopens a closed period, records the reason in the audit trail, and makes it postable again', function (): void {
    $c = TenantHarness::seedCompany('Reopen Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.close', 'accounting.period.reopen');
    $period = fpPeriodOn($c['company_id'], '2026-04-04');
    $entryId = fpDraft($c['company_id'], '2026-04-04');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $entryId, $c) {
        app(ClosePeriodAction::class)->execute($period, $c['user_id']);
        $reopened = app(ReopenPeriodAction::class)->execute($period->refresh(), 'Late supplier invoice', $c['user_id']);

        expect($reopened->status)->toBe(FiscalPeriod::STATUS_OPEN);
        expect($reopened->reopen_reason)->toBe('Late supplier invoice');

        // The month accepts postings again — the reopen is real, not cosmetic.
        $posted = app(PostJournalEntryAction::class)->execute(JournalEntry::query()->findOrFail($entryId));
        expect($posted->status)->toBe(JournalEntry::STATUS_POSTED);

        $audit = TenantHarness::app()->selectOne(
            "SELECT action, reason FROM audit_logs WHERE entity_type = 'fiscal_periods' AND entity_id = ?
             AND action = 'accounting.period.reopened' ORDER BY id DESC LIMIT 1",
            [$period->id],
        );
        expect($audit?->action)->toBe('accounting.period.reopened');
        expect($audit?->reason)->toBe('Late supplier invoice');
    });
});

it('refuses to reopen without a stated reason (422 REOPEN_REASON_REQUIRED)', function (): void {
    $c = TenantHarness::seedCompany('No Reason Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.close', 'accounting.period.reopen');
    $period = fpPeriodOn($c['company_id'], '2026-10-10');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $c) {
        app(ClosePeriodAction::class)->execute($period, $c['user_id']);

        try {
            app(ReopenPeriodAction::class)->execute($period->refresh(), '   ', $c['user_id']);
            expect(false)->toBeTrue('a reasonless reopen should have been refused');
        } catch (PeriodRuleException $e) {
            expect($e->errorCode())->toBe('REOPEN_REASON_REQUIRED');
            expect($e->errorStatus())->toBe(422);
        }
    });
});

it('refuses to reopen a LOCKED period with only accounting.period.reopen (403 names the override)', function (): void {
    $c = TenantHarness::seedCompany('Hard Lock Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.close', 'accounting.period.lock', 'accounting.period.reopen');
    $period = fpPeriodOn($c['company_id'], '2026-11-11');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $c) {
        app(ClosePeriodAction::class)->execute($period, $c['user_id']);
        app(LockPeriodAction::class)->execute($period->refresh(), $c['user_id']);

        try {
            app(ReopenPeriodAction::class)->execute($period->refresh(), 'Audit correction', $c['user_id']);
            expect(false)->toBeTrue('reopening a locked period with only accounting.period.reopen should be refused');
        } catch (PeriodRuleException $e) {
            expect($e->errorCode())->toBe('PERMISSION_DENIED');
            expect($e->meta['permission'])->toBe('accounting.period.hard_lock_override');
        }
    });
});

it('reopens a LOCKED period when the actor holds accounting.period.hard_lock_override', function (): void {
    $c = TenantHarness::seedCompany('Override Co');
    fpYear($c['company_id']);
    fpGrant(
        $c['role_id'],
        'accounting.period.close',
        'accounting.period.lock',
        'accounting.period.hard_lock_override',
    );
    $period = fpPeriodOn($c['company_id'], '2026-11-11');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $c) {
        app(ClosePeriodAction::class)->execute($period, $c['user_id']);
        app(LockPeriodAction::class)->execute($period->refresh(), $c['user_id']);

        $reopened = app(ReopenPeriodAction::class)->execute($period->refresh(), 'Audit correction', $c['user_id']);

        expect($reopened->status)->toBe(FiscalPeriod::STATUS_OPEN);
        expect($reopened->reopen_reason)->toBe('Audit correction');
    });
});

it('refuses to reopen a period that is already open (409 PERIOD_NOT_REOPENABLE)', function (): void {
    $c = TenantHarness::seedCompany('Already Open Co');
    fpYear($c['company_id']);
    fpGrant($c['role_id'], 'accounting.period.reopen');
    $period = fpPeriodOn($c['company_id'], '2026-12-12');

    TenantHarness::runInTenant($c['company_id'], function () use ($period, $c) {
        try {
            app(ReopenPeriodAction::class)->execute($period, 'Nothing to undo', $c['user_id']);
            expect(false)->toBeTrue('reopening an open period should have been refused');
        } catch (PeriodRuleException $e) {
            expect($e->errorCode())->toBe('PERIOD_NOT_REOPENABLE');
            expect($e->errorStatus())->toBe(409);
        }
    });
});

it('keeps the calendar tenant-isolated: a company never reads another company periods', function (): void {
    $a = TenantHarness::seedCompany('Calendar A');
    $b = TenantHarness::seedCompany('Calendar B');
    fpYear($a['company_id']);
    fpYear($b['company_id']);

    $visible = TenantHarness::runInTenant($a['company_id'], fn () => FiscalPeriod::query()->count());
    $foreign = TenantHarness::runInTenant($a['company_id'], fn () => FiscalPeriod::query()
        ->withoutGlobalScopes()
        ->where('company_id', $b['company_id'])
        ->count());

    expect($visible)->toBe(12);
    expect($foreign)->toBe(0);
});
