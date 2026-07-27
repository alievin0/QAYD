<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Actions\Identity\RegisterUserAction;
use App\Data\Identity\RegisterData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Identity\RegisterRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/auth/register` (SPRINT_01 §S1-07). Thin: validate → DTO → Action → Resource-shaped
 * envelope. The response never carries a credential — registration establishes identity, not a session;
 * the user verifies their email, then signs in.
 */
final class RegisterController extends Controller
{
    public function __construct(private readonly RegisterUserAction $action) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $locale = $request->string('locale')->toString();

        $user = $this->action->execute(new RegisterData(
            name: $request->string('name')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            locale: $locale !== '' ? $locale : 'ar',
        ));

        return ApiResponse::success([
            'user' => [
                'uuid' => $user->uuid,
                'email' => $user->email,
                'name' => $user->name,
                'email_verified' => $user->hasVerifiedEmail(),
            ],
        ], 'identity.registered', status: 201);
    }
}
