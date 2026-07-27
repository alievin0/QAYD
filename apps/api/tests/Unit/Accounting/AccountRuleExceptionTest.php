<?php

declare(strict_types=1);

use App\Exceptions\Accounting\AccountRuleException;

/**
 * S2-01 — the chart-of-accounts rule exception maps each business rule to its stable catalog code and
 * a 422 status. A pure unit test: no database, no container.
 */
it('maps each chart-of-accounts rule to its stable code', function (): void {
    expect(AccountRuleException::duplicateCode('1000')->errorCode())->toBe('DUPLICATE_ACCOUNT_CODE');
    expect(AccountRuleException::invalidParent()->errorCode())->toBe('INVALID_ACCOUNT_PARENT');
    expect(AccountRuleException::accountTypeNotFound()->errorCode())->toBe('ACCOUNT_TYPE_NOT_FOUND');
    expect(AccountRuleException::hasPostings('reclassified')->errorCode())->toBe('ACCOUNT_HAS_POSTINGS');
    expect(AccountRuleException::hasActiveChildren()->errorCode())->toBe('ACCOUNT_HAS_ACTIVE_CHILDREN');
});

it('renders every chart-of-accounts rule as a 422 with one errors[] entry', function (): void {
    $exception = AccountRuleException::duplicateCode('1000');

    expect($exception->errorStatus())->toBe(422);

    $errors = $exception->errorsList();
    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('DUPLICATE_ACCOUNT_CODE');
    expect($errors[0]['field'])->toBe('code');
});
