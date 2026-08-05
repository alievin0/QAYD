<?php

declare(strict_types=1);

use App\Exceptions\Accounting\ClosedPeriodException;
use App\Exceptions\Accounting\PostingRuleException;
use App\Exceptions\Accounting\UnbalancedEntryException;

/**
 * S2-05 — the posting engine's typed failures carry the stable catalog code + HTTP status the API
 * contract promises (docs/api/API_ERROR_HANDLING.md). Pure unit tests: no container, no database.
 */
uses()->group('accounting');

it('renders an imbalance as 422 BALANCE_MISMATCH carrying both totals and the difference', function (): void {
    $e = new UnbalancedEntryException('850.0000', '800.0000', '50.0000', 'KWD');

    expect($e->errorCode())->toBe('BALANCE_MISMATCH');
    expect($e->errorStatus())->toBe(422);
    expect($e->field)->toBe('lines');
    expect($e->meta['difference'])->toBe('50.0000');
    expect($e->meta['total_debit'])->toBe('850.0000');
    expect($e->meta['total_credit'])->toBe('800.0000');
    expect($e->getMessage())->toContain('KWD');
});

it('renders a closed/absent period as 422 CLOSED_PERIOD', function (): void {
    $noPeriod = ClosedPeriodException::noPeriodForDate('2030-01-01');
    $notOpen = ClosedPeriodException::periodNotOpen('2026-07-01', 'closed');

    expect($noPeriod->errorCode())->toBe('CLOSED_PERIOD');
    expect($noPeriod->errorStatus())->toBe(422);
    expect($noPeriod->field)->toBe('journal_date');
    expect($noPeriod->meta['journal_date'])->toBe('2030-01-01');

    expect($notOpen->errorCode())->toBe('CLOSED_PERIOD');
    expect($notOpen->meta['status'])->toBe('closed');
    expect($notOpen->getMessage())->toContain('closed');
});

it('renders a non-postable status as 409 and content violations as 422', function (): void {
    $notPostable = PostingRuleException::notPostable('posted');
    $empty = PostingRuleException::emptyEntry();
    $inactive = PostingRuleException::inactiveAccount(4242);

    // A re-post of an already-posted entry is a state conflict — the idempotency guard.
    expect($notPostable->errorCode())->toBe('JOURNAL_NOT_POSTABLE');
    expect($notPostable->errorStatus())->toBe(409);
    expect($notPostable->meta['status'])->toBe('posted');

    expect($empty->errorCode())->toBe('CANNOT_POST_EMPTY');
    expect($empty->errorStatus())->toBe(422);

    expect($inactive->errorCode())->toBe('ACCOUNT_INACTIVE');
    expect($inactive->errorStatus())->toBe(422);
    expect($inactive->meta['account_id'])->toBe(4242);
});

it('exposes each failure as a single coded errors[] entry for the envelope', function (): void {
    $list = (new UnbalancedEntryException('10.0000', '9.0000', '1.0000', 'KWD'))->errorsList();

    expect($list)->toHaveCount(1);
    expect($list[0]['code'])->toBe('BALANCE_MISMATCH');
    expect($list[0]['field'])->toBe('lines');
});
