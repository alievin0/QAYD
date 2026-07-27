<?php

declare(strict_types=1);

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/v1/companies` (SPRINT_01 §S1-10). Authorization is handled upstream by the route
 * middleware (`auth:web,jwt` then the `verified.email` gate → 403 EMAIL_NOT_VERIFIED for an unverified
 * caller), so `authorize()` is true here; this class is purely the input contract (the Zod-equivalent
 * schema the onboarding wizard's client validation mirrors).
 *
 * The four required fields are the minimum a company needs to keep books: a legal name, an English
 * display name, a base currency, and a fiscal-year start month. The bilingual/trade names, timezone, and
 * locale are optional and fall back to the DTO defaults.
 */
final class CreateCompanyRequest extends FormRequest
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
            'legal_name' => ['required', 'string', 'min:2', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'min:2', 'max:255'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            // ISO 4217 alpha-3, normalised to upper-case before use.
            'base_currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'locale' => ['sometimes', 'string', Rule::in(['ar', 'en'])],
        ];
    }
}
