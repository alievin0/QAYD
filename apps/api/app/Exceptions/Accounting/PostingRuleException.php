<?php

declare(strict_types=1);

namespace App\Exceptions\Accounting;

use App\Exceptions\DomainException;

/**
 * A posting-engine rule violation other than an imbalance or a closed period (S2-05,
 * docs/accounting/JOURNAL_ENTRIES.md "# Posting Engine", "# Locking Rules"): the entry is in a status
 * that cannot be posted, it has no lines, or a line targets an inactive account. State conflicts render
 * as **409**; content violations as **422**. Built through named factories so each call site reads as
 * the rule it enforces — mirroring {@see JournalRuleException} for the draft lifecycle.
 */
final class PostingRuleException extends DomainException
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
     * The entry is not in a postable pre-state (only `draft` or `approved` may be posted) — e.g. it is
     * already `posted`, or is `pending_approval`/`rejected`. This is also the idempotency backstop for a
     * duplicate post: a second post of an already-`posted` entry lands here (409), projecting nothing.
     */
    public static function notPostable(string $status): self
    {
        return new self(
            'JOURNAL_NOT_POSTABLE',
            409,
            "A journal entry in '{$status}' status cannot be posted; only a draft or approved entry can be.",
            'status',
            ['status' => $status],
        );
    }

    /** The entry has no lines — an empty entry is not a postable financial fact (422). */
    public static function emptyEntry(): self
    {
        return new self(
            'CANNOT_POST_EMPTY',
            422,
            'A journal entry must have at least one line before it can be posted.',
            'lines',
        );
    }

    /** A line targets an account that is not active; an inactive account can carry no new postings (422). */
    public static function inactiveAccount(int $accountId): self
    {
        return new self(
            'ACCOUNT_INACTIVE',
            422,
            'A journal line targets an inactive account; inactive accounts cannot receive new postings.',
            'lines',
            ['account_id' => $accountId],
        );
    }

    /**
     * A line targets a HEADER account (`allow_posting = false`). Distinct from an inactive account: an
     * inactive one has been retired and could be reactivated, while a header's balance is by definition
     * the sum of its children, so an amount posted directly to it would belong to no leaf and quietly
     * break every roll-up above it (422).
     */
    public static function notPostableAccount(int $accountId): self
    {
        return new self(
            'ACCOUNT_NOT_POSTABLE',
            422,
            'A journal line targets a header account; only postable accounts can receive journal lines.',
            'lines',
            ['account_id' => $accountId],
        );
    }
}
