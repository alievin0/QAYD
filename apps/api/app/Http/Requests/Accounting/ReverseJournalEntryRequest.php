<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Actions\Accounting\ReverseJournalEntryAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /api/v1/accounting/journal-entries/{entry}/reverse` (Journal API, S2-11 prerequisite).
 *
 * `reason` is required because {@see ReverseJournalEntryAction} records it on the mirror entry's memo: a
 * reversal without a stated reason is a correction nobody can audit later. The rules deciding whether
 * this entry may be reversed at all — posted, not already reversed, segregation of duties — stay in the
 * Action.
 */
final class ReverseJournalEntryRequest extends FormRequest
{
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
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
            'reversal_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
