<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Models\JournalEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `GET /api/v1/accounting/ledger/accounts/{account}/activity` (S2-08). Shape only — that the
 * account exists and is visible is settled by the tenant-scoped lookup in the controller.
 *
 * `per_page` is validated as an integer but NOT bounded here: the API standard says an oversized page
 * request is clamped rather than rejected, because the caller's intent ("give me more per page") is
 * still honoured as far as the platform allows. Rejecting it would turn a harmless request into a 422.
 *
 * `cursor` has no format rule, for the same reason a client must not parse one: it is opaque. An
 * unreadable cursor decodes to null and is treated as "start at the beginning", never as an error.
 */
final class LedgerActivityRequest extends FormRequest
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
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'entry_type' => ['sometimes', Rule::in(JournalEntry::ENTRY_TYPES)],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
