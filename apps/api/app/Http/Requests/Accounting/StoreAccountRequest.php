<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Actions\Accounting\CreateAccountAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /api/v1/accounting/accounts` (S2-02). Shape only: authorization is the route
 * `permission:accounting.coa.manage` gate, and the business rules (account type exists, parent is in
 * the same company, code is unique) belong to {@see CreateAccountAction}, not
 * here — this is purely the input contract the SDK's Zod schema mirrors.
 */
final class StoreAccountRequest extends FormRequest
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
            'code' => ['required', 'string', 'min:1', 'max:40'],
            'name_en' => ['required', 'string', 'min:1', 'max:255'],
            'name_ar' => ['required', 'string', 'min:1', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'is_control_account' => ['sometimes', 'boolean'],
            'control_account_of' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}
