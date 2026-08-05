<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /api/v1/accounting/journal-entries/{entry}/submit` (Journal API, S2-11 prerequisite).
 *
 * Only the concurrency token: submitting is a state transition rather than a content change, so the body
 * carries nothing else. Whether the entry is in a submittable state is the Action's decision.
 */
final class SubmitJournalEntryRequest extends FormRequest
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
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
