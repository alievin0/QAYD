<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /auth/register` (SPRINT_01 §S1-07). Public route — anyone may register — so
 * `authorize()` is true; the security surface is the input contract and the email-verification gate,
 * not an RBAC check.
 */
final class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'locale' => ['sometimes', 'string', Rule::in(['ar', 'en'])],
        ];
    }
}
