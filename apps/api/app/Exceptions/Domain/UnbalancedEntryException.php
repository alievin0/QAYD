<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;

/**
 * `UNBALANCED_ENTRY` (422) — a journal entry whose debit total does not equal its credit total
 * (docs/api/API_ERROR_HANDLING.md "# Business-Rule Errors").
 *
 * The one concrete domain exception the S1-16 skeleton ships, to demonstrate the typed-exception →
 * coded-envelope path end to end; the Accounting module story wires the real posting rule that throws
 * it. Included here purely as the taxonomy exemplar the exception-handler tests assert against.
 */
final class UnbalancedEntryException extends DomainException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(string $message = 'The journal entry is unbalanced: total debits do not equal total credits.', array $meta = [], ?string $field = 'journal_lines')
    {
        parent::__construct($message);

        $this->meta = $meta;
        $this->field = $field;
    }

    public function errorCode(): string
    {
        return 'UNBALANCED_ENTRY';
    }

    public function errorStatus(): int
    {
        return 422;
    }
}
