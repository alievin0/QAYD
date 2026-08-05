<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use App\Exceptions\DomainException;

/**
 * A journal draft-lifecycle business-rule violation (S2-04, docs/accounting/JOURNAL_ENTRIES.md,
 * docs/api/API_ERROR_HANDLING.md "# Business-Rule Errors"). It carries the stable catalog code AND the
 * HTTP status for the specific rule — state conflicts (edit/submit a non-draft, a stale version) render
 * as **409**, content violations as **422**, and the AI-cannot-submit authorization rule as **403**.
 * Built through named factories so each call site reads as the rule it enforces.
 */
final class JournalRuleException extends DomainException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        private readonly string $catalogCode,
        private readonly int $status,
        string $message,
        ?string $field = null,
        array $meta = [],
    ) {
        parent::__construct($message);

        $this->field = $field;
        $this->meta = $meta;
    }

    public function errorCode(): string
    {
        return $this->catalogCode;
    }

    public function errorStatus(): int
    {
        return $this->status;
    }

    /** Optimistic-concurrency failure: the entry changed since the caller read it (409). */
    public static function versionConflict(int $expectedVersion): self
    {
        return new self(
            'VERSION_CONFLICT',
            409,
            'The journal entry was modified since you loaded it; reload the latest version and retry.',
            'version',
            ['expected_version' => $expectedVersion],
        );
    }

    /** A PATCH/submit against an entry that is not draft or rejected (409). */
    public static function notEditable(string $status): self
    {
        return new self(
            'JOURNAL_NOT_EDITABLE',
            409,
            "A journal entry in '{$status}' status cannot be edited; only draft or rejected entries are editable.",
            null,
            ['status' => $status],
        );
    }

    /** The entry type is not one of the journal_entry_type enum values (422). */
    public static function invalidEntryType(string $type): self
    {
        return new self(
            'INVALID_ENTRY_TYPE',
            422,
            "'{$type}' is not a valid journal entry type.",
            'entry_type',
            ['entry_type' => $type],
        );
    }

    /** A line is not one-sided (exactly one of debit/credit greater than zero, both non-negative) (422). */
    public static function invalidLine(int $lineNumber): self
    {
        return new self(
            'INVALID_JOURNAL_LINE',
            422,
            "Journal line {$lineNumber} must have exactly one of debit or credit greater than zero (both non-negative).",
            'lines',
            ['line' => $lineNumber],
        );
    }

    /** A line references an account that does not exist in the active company (422). */
    public static function invalidAccount(int $accountId): self
    {
        return new self(
            'INVALID_JOURNAL_ACCOUNT',
            422,
            'A journal line references an account that does not exist in this company.',
            'lines',
            ['account_id' => $accountId],
        );
    }

    /** An AI-generated entry was created without an ai_confidence in [0,1] (422). */
    public static function aiConfidenceRequired(): self
    {
        return new self(
            'AI_CONFIDENCE_REQUIRED',
            422,
            'An AI-generated entry must carry an ai_confidence between 0 and 1.',
            'ai_confidence',
        );
    }

    /** Submitting an entry that has no lines (422). */
    public static function cannotSubmitEmpty(): self
    {
        return new self(
            'CANNOT_SUBMIT_EMPTY',
            422,
            'A journal entry must have at least one line before it can be submitted for approval.',
        );
    }

    /** An AI agent tried to submit (or post) an entry — never permitted; a human must review (403). */
    public static function aiCannotSubmit(): self
    {
        return new self(
            'AI_CANNOT_SUBMIT',
            403,
            'An AI agent may draft a journal entry but can never submit or post it; a human must review it first.',
        );
    }
}
