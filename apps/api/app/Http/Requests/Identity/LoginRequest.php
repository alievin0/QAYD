<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Actions\Identity\LoginAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /auth/login` (SPRINT_01 §S1-08). Public route; the credential check and throttle live
 * in {@see LoginAction}. Validation is intentionally shallow — a malformed body is
 * a `422`, but a wrong password is a `401 INVALID_CREDENTIALS`, never a validation error (no enumeration).
 */
final class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string'],
            'remember_me' => ['sometimes', 'boolean'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
