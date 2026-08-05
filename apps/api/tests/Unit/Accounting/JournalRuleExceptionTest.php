<?php

declare(strict_types=1);

use App\Exceptions\Accounting\JournalRuleException;

/**
 * S2-04 — the journal draft-lifecycle rule exception maps each business rule to its stable catalog code
 * and the correct HTTP status (409 state conflicts, 422 content, 403 AI-cannot-submit). Pure unit test.
 */
it('maps each journal rule to its stable code and HTTP status', function (): void {
    expect(JournalRuleException::versionConflict(2)->errorCode())->toBe('VERSION_CONFLICT');
    expect(JournalRuleException::versionConflict(2)->errorStatus())->toBe(409);

    expect(JournalRuleException::notEditable('posted')->errorCode())->toBe('JOURNAL_NOT_EDITABLE');
    expect(JournalRuleException::notEditable('posted')->errorStatus())->toBe(409);

    expect(JournalRuleException::invalidEntryType('nope')->errorCode())->toBe('INVALID_ENTRY_TYPE');
    expect(JournalRuleException::invalidEntryType('nope')->errorStatus())->toBe(422);

    expect(JournalRuleException::invalidLine(1)->errorStatus())->toBe(422);
    expect(JournalRuleException::invalidAccount(5)->errorStatus())->toBe(422);
    expect(JournalRuleException::aiConfidenceRequired()->errorCode())->toBe('AI_CONFIDENCE_REQUIRED');
    expect(JournalRuleException::cannotSubmitEmpty()->errorStatus())->toBe(422);

    expect(JournalRuleException::aiCannotSubmit()->errorCode())->toBe('AI_CANNOT_SUBMIT');
    expect(JournalRuleException::aiCannotSubmit()->errorStatus())->toBe(403);
});

it('carries the expected version in a version-conflict envelope entry', function (): void {
    $errors = JournalRuleException::versionConflict(7)->errorsList();

    expect($errors)->toHaveCount(1);
    expect($errors[0]['code'])->toBe('VERSION_CONFLICT');
    expect($errors[0]['field'])->toBe('version');
    expect($errors[0]['meta']['expected_version'])->toBe(7);
});
