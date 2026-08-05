<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Actions\Accounting\CreateJournalEntryAction;
use App\Models\JournalEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/v1/accounting/journal-entries` (Journal API, S2-11 prerequisite).
 *
 * Shape only, exactly as `StoreAccountRequest` is for the chart of accounts. That an entry balances,
 * that its accounts exist, are active and postable, that its date falls in an open period — every one of
 * those belongs to {@see CreateJournalEntryAction} or the posting engine, and none is repeated here.
 * Duplicating them would create a second source of truth that drifts the first time a rule moves.
 *
 * Money arrives as STRINGS and is validated as a money literal rather than `numeric`, because `numeric`
 * would accept — and JSON decoding would then hand on — a float, which is the one representation
 * `NUMERIC(19,4)` money must never pass through.
 */
final class StoreJournalEntryRequest extends FormRequest
{
    /** A non-negative amount with at most four decimal places. */
    private const MONEY = 'regex:/^\d{1,15}(\.\d{1,4})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'journal_date' => ['required', 'date_format:Y-m-d'],
            'entry_type' => ['required', Rule::in(JournalEntry::ENTRY_TYPES)],
            'currency_code' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['sometimes', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'memo' => ['sometimes', 'nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['required', 'string', self::MONEY],
            'lines.*.credit' => ['required', 'string', self::MONEY],
            'lines.*.description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
