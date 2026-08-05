<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use App\Exceptions\DomainException;

/**
 * A reverse/void business-rule violation (S2-06, docs/accounting/JOURNAL_ENTRIES.md "# Locking Rules",
 * "# Journal Entry Lifecycle"). Posted history is immutable: the only correction is a NEW reversing
 * entry, never an edit or a delete. These failures therefore render as **409** state conflicts — except
 * the segregation-of-duties refusal, which is an authorization decision and renders **403**.
 *
 * The `immutableRecord` message deliberately names `reverse` as the remedy, so a caller that tried to
 * void or edit a posted entry is told what to do instead rather than merely being refused.
 */
final class ReversalRuleException extends DomainException
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

    /**
     * A terminal (posted/reversed/voided/archived) entry cannot be edited or voided — it can only be
     * reversed. The remedy is named in the message because the caller's next action is unambiguous.
     */
    public static function immutableRecord(string $status): self
    {
        return new self(
            'IMMUTABLE_RECORD',
            409,
            "A journal entry in '{$status}' status is immutable and cannot be edited or voided; "
            .'correct it by posting a reverse entry instead.',
            'status',
            ['status' => $status, 'remedy' => 'reverse'],
        );
    }

    /** Only a POSTED entry can be reversed (a draft is voided or edited, not reversed). */
    public static function notPosted(string $status): self
    {
        return new self(
            'ENTRY_NOT_POSTED',
            409,
            "Only a posted journal entry can be reversed; this entry is '{$status}'.",
            'status',
            ['status' => $status],
        );
    }

    /**
     * The entry already carries a reversal. Guarded here for a clean error, and independently by the
     * `uq_je_one_reversal` partial unique index so a concurrent double-reverse cannot slip through.
     */
    public static function alreadyReversed(int $reversalEntryId): self
    {
        return new self(
            'ALREADY_REVERSED',
            409,
            'This journal entry has already been reversed.',
            null,
            ['reversal_entry_id' => $reversalEntryId],
        );
    }

    /**
     * Segregation of duties (SPRINT_02 §S2-06): the creator of an entry may not also be the person who
     * reverses it — reversal is a financial correction and needs a second pair of eyes. Relaxed only for
     * a company with a single active member, the documented small-company exception
     * (JOURNAL_ENTRIES.md "# Approval Workflow → Segregation of duties"); that carve-out is derived from
     * the membership data, never from a caller-supplied flag.
     */
    public static function segregationOfDuties(): self
    {
        return new self(
            'SEGREGATION_OF_DUTIES',
            403,
            'The user who created a journal entry cannot also reverse it; a second authorized user must '
            .'perform the reversal.',
            null,
        );
    }
}
