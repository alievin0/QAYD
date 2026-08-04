<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Actions\Accounting\UpdateJournalDraftAction;
use App\Models\JournalEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `PATCH /api/v1/accounting/journal-entries/{entry}` (Journal API, S2-11 prerequisite).
 *
 * `version` is required, not optional. It is the optimistic-concurrency token
 * {@see UpdateJournalDraftAction} guards on: a caller who did not read the entry first cannot claim to
 * be editing it, and a caller whose copy is stale gets `409 VERSION_CONFLICT` from the Action rather
 * than silently overwriting someone else's edit. Making it optional would let a client opt out of that
 * protection by omission.
 */
final class UpdateJournalEntryRequest extends FormRequest
{
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
            'version' => ['required', 'integer', 'min:1'],
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
