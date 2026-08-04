<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Models\TrialBalanceSnapshot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/v1/accounting/reports/trial-balance` (S2-09). Shape only — that the period
 * exists and is visible is settled by the Action, which resolves it RLS-scoped and answers
 * `422 UNKNOWN_FISCAL_PERIOD` rather than leaking whether another company's period id is real.
 */
final class GenerateTrialBalanceRequest extends FormRequest
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
            'fiscal_period_id' => ['required', 'integer', 'min:1'],
            'type' => ['sometimes', Rule::in(TrialBalanceSnapshot::TYPES)],
        ];
    }
}
