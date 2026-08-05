<?php

declare(strict_types=1);

use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\JournalDraftLine;
use App\Domain\Accounting\JournalPoster;
use App\Domain\Accounting\MonthlyFiscalPeriodGenerator;
use App\Domain\Accounting\PostedJournalEntry;
use App\Exceptions\Accounting\UnbalancedEntryException;
use App\Services\Accounting\PostingEngineJournalPoster;
use Database\Seeders\AccountTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TenantHarness;

/**
 * The cross-module posting seam (SPRINT_03 Phase 0).
 *
 * Banking clears a bank transaction by posting a balanced journal, and this interface is the entire
 * surface it is allowed to use. These tests prove two different kinds of thing: that a draft handed
 * across the seam really lands in the ledger through the ordinary posting engine, and that the seam has
 * not quietly become a second, weaker way in — no AI flag to set, no unbalanced entry slipping past, no
 * transaction of its own that would break the caller's atomicity.
 */
uses()->group('accounting');

beforeEach(function (): void {
    TenantHarness::boot();
    Artisan::call('db:seed', ['--class' => AccountTypeSeeder::class, '--force' => true]);
});

function jpsAccount(int $companyId): int
{
    $typeId = (int) TenantHarness::owner()->table('account_types')->where('key', 'asset')->value('id');

    return (int) TenantHarness::owner()->selectOne(
        "INSERT INTO accounts (company_id, account_type_id, code, name_en, name_ar, normal_balance, status)
         VALUES (?, ?, ?, 'Acc', 'حساب', 'debit', 'active') RETURNING id",
        [$companyId, $typeId, 'S'.bin2hex(random_bytes(5))],
    )->id;
}

function jpsFiscalYear(int $companyId): int
{
    $yearId = (int) TenantHarness::owner()->selectOne(
        "INSERT INTO fiscal_years (company_id, name, start_date, end_date, status)
         VALUES (?, ?, '2026-01-01', '2026-12-31', 'open'::fiscal_year_status) RETURNING id",
        [$companyId, 'FY-'.bin2hex(random_bytes(4))],
    )->id;

    MonthlyFiscalPeriodGenerator::generate(
        TenantHarness::owner(), $companyId, $yearId, '2026-01-01', '2026-12-31', 'open',
    );

    return $yearId;
}

/** A balanced two-line draft — the shape Banking's clear-and-post will build. */
function jpsDraft(int $debitAccount, int $creditAccount, string $amount = '250.0000'): JournalDraft
{
    return new JournalDraft(
        journalDate: '2026-07-01',
        entryType: 'manual',
        currencyCode: 'KWD',
        lines: [
            JournalDraftLine::debit($debitAccount, $amount, 'Bank clearing'),
            JournalDraftLine::credit($creditAccount, $amount, 'Bank clearing'),
        ],
        reference: 'BANK-REF-1',
        memo: 'Cleared bank transaction',
    );
}

describe('posting through the seam', function (): void {
    it('posts a balanced draft and returns the id the caller stores', function (): void {
        $co = TenantHarness::seedCompany('Seam Post Co');
        jpsFiscalYear($co['company_id']);
        $debit = jpsAccount($co['company_id']);
        $credit = jpsAccount($co['company_id']);

        [$result, $row, $ledgerRows] = TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit, $co): array {
            $posted = app(JournalPoster::class)->post(jpsDraft($debit, $credit), $co['user_id']);

            $entry = TenantHarness::app()->selectOne(
                'SELECT status, journal_number FROM journal_entries WHERE id = ?',
                [$posted->journalEntryId],
            );
            $rows = (int) TenantHarness::app()->selectOne(
                'SELECT COUNT(*) AS n FROM ledger_entries WHERE journal_entry_id = ?',
                [$posted->journalEntryId],
            )->n;

            return [$posted, $entry, $rows];
        });

        expect($result)->toBeInstanceOf(PostedJournalEntry::class)
            ->and($result->journalEntryId)->toBeGreaterThan(0)
            // A permanent number, not the provisional DRAFT- form: it really went through posting.
            ->and($result->journalNumber)->toStartWith('JE-')
            ->and($result->journalNumber)->toBe($row->journal_number)
            ->and($row->status)->toBe('posted')
            // And it projected into the ledger, which is the only evidence that counts.
            ->and($ledgerRows)->toBe(2);
    });

    it('lets an accounting refusal travel out unchanged', function (): void {
        $co = TenantHarness::seedCompany('Seam Unbalanced Co');
        jpsFiscalYear($co['company_id']);
        $debit = jpsAccount($co['company_id']);
        $credit = jpsAccount($co['company_id']);

        $unbalanced = new JournalDraft(
            journalDate: '2026-07-01',
            entryType: 'manual',
            currencyCode: 'KWD',
            lines: [
                JournalDraftLine::debit($debit, '100.0000'),
                JournalDraftLine::credit($credit, '90.0000'),
            ],
        );

        $caught = TenantHarness::runInTenant($co['company_id'], function () use ($unbalanced, $co): bool {
            try {
                app(JournalPoster::class)->post($unbalanced, $co['user_id']);

                return false;
            } catch (UnbalancedEntryException) {
                // Accounting's own exception, not a Banking-flavoured wrapper around it.
                return true;
            }
        });

        expect($caught)->toBeTrue();
    });

    it('writes nothing to the ledger when the post is refused', function (): void {
        $co = TenantHarness::seedCompany('Seam Rollback Co');
        jpsFiscalYear($co['company_id']);
        $debit = jpsAccount($co['company_id']);
        $credit = jpsAccount($co['company_id']);

        $ledgerRows = TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit, $co): int {
            try {
                app(JournalPoster::class)->post(new JournalDraft(
                    journalDate: '2026-07-01',
                    entryType: 'manual',
                    currencyCode: 'KWD',
                    lines: [
                        JournalDraftLine::debit($debit, '100.0000'),
                        JournalDraftLine::credit($credit, '1.0000'),
                    ],
                ), $co['user_id']);
            } catch (UnbalancedEntryException) {
                // expected
            }

            return (int) TenantHarness::app()->selectOne('SELECT COUNT(*) AS n FROM ledger_entries')->n;
        });

        // The draft may exist; the LEDGER must not have moved. Cash never moves without a balanced journal.
        expect($ledgerRows)->toBe(0);
    });
});

describe('the boundary the seam is meant to be', function (): void {
    it('is resolved from the interface, so callers never name the implementation', function (): void {
        expect(app(JournalPoster::class))->toBeInstanceOf(PostingEngineJournalPoster::class);
    });

    it('exposes exactly one method, so there is no second way into the ledger', function (): void {
        $methods = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(JournalPoster::class))->getMethods(),
        );

        // Widening this is a decision someone has to take on purpose, and this test is where they notice.
        expect($methods)->toBe(['post']);
    });

    it('gives a caller no way to mark an entry as AI-generated', function (): void {
        $fields = array_map(
            static fn (ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass(JournalDraft::class))->getProperties(),
        );

        // The draft cannot carry the flag, so a module posting through the seam can neither claim nor
        // conceal machine authorship. An AI path gets its own governed route, not this one.
        expect($fields)->not->toContain('aiGenerated')
            ->and($fields)->not->toContain('aiConfidence');
    });

    it('opens no transaction of its own, so the caller keeps atomicity', function (): void {
        $co = TenantHarness::seedCompany('Seam Tx Co');
        jpsFiscalYear($co['company_id']);
        $debit = jpsAccount($co['company_id']);
        $credit = jpsAccount($co['company_id']);

        // S3-03 has to move a bank balance and post a journal in ONE transaction. That is only
        // expressible if the seam joins the caller's transaction rather than starting its own, so the
        // level must be unchanged on the way out.
        $levels = TenantHarness::runInTenant($co['company_id'], function () use ($debit, $credit, $co): array {
            $connection = TenantHarness::app();
            $before = $connection->transactionLevel();
            app(JournalPoster::class)->post(jpsDraft($debit, $credit), $co['user_id']);

            return [$before, $connection->transactionLevel()];
        });

        expect($levels[1])->toBe($levels[0]);
    });
});

describe('the draft refuses what is a caller mistake', function (): void {
    it('rejects a line that is both a debit and a credit', function (): void {
        expect(fn () => new JournalDraftLine(1, '10.0000', '10.0000'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a draft with no lines at all', function (): void {
        expect(fn () => new JournalDraft('2026-07-01', 'manual', 'KWD', []))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a non-numeric amount before it reaches the ledger', function (): void {
        expect(fn () => new JournalDraftLine(1, 'lots'))->toThrow(InvalidArgumentException::class);
    });
});
