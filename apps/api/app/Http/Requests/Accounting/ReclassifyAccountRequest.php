<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Actions\Accounting\ReclassifyAccountAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /api/v1/accounting/accounts/{account}/reclassify` (S2-02). Shape only: the target
 * account type must be a valid integer; that it exists, and the guard that a posted account cannot be
 * reclassified, are enforced by {@see ReclassifyAccountAction}.
 */
final class ReclassifyAccountRequest extends FormRequest
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
            'account_type_id' => ['required', 'integer'],
        ];
    }
}
