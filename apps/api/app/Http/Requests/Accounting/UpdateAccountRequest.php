<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Actions\Accounting\UpdateAccountAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `PATCH /api/v1/accounting/accounts/{account}` (S2-02). Every field is optional (a partial
 * update); a null/absent field is left unchanged. The renumber guard (a posted account cannot be
 * renumbered) and code uniqueness are enforced by {@see UpdateAccountAction}.
 */
final class UpdateAccountRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'min:1', 'max:40'],
            'name_en' => ['sometimes', 'string', 'min:1', 'max:255'],
            'name_ar' => ['sometimes', 'string', 'min:1', 'max:255'],
        ];
    }
}
