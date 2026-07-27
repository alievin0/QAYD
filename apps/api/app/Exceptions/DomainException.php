<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base for QAYD's typed domain/business exceptions (S1-16, docs/api/API_ERROR_HANDLING.md
 * "# Error Code Catalog", "# Business-Rule Errors").
 *
 * A domain exception carries a stable, machine-readable error `code` from the catalog and the HTTP
 * status that code maps to. The global exception handler renders any subclass as a coded error
 * envelope with `success: false` — never a stack trace. The exception *message* is caller-safe (it is
 * an intentional business explanation such as "debits do not equal credits"), so it is surfaced in the
 * envelope; internals never are.
 *
 * Later stories throw concrete subclasses (unbalanced entry, period locked, insufficient permission,
 * …); this skeleton ships the base plus one demonstration subclass exercised by the S1-16 tests.
 */
abstract class DomainException extends RuntimeException
{
    /** @var array<string, mixed> */
    public array $meta = [];

    public ?string $field = null;

    /**
     * The stable catalog code for this failure (e.g. `UNBALANCED_ENTRY`). Never localized.
     */
    abstract public function errorCode(): string;

    /**
     * The HTTP status this code renders as (e.g. 422 for a content violation, 409 for a state one).
     */
    abstract public function errorStatus(): int;

    /**
     * The `errors[]` list for the envelope — one entry, keyed by the catalog code.
     *
     * @return list<array{code: string, field: string|null, message: string, meta: array<string, mixed>}>
     */
    public function errorsList(): array
    {
        return [[
            'code' => $this->errorCode(),
            'field' => $this->field,
            'message' => $this->getMessage(),
            'meta' => $this->meta,
        ]];
    }
}
