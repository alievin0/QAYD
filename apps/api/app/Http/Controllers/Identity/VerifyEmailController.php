<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Actions\Identity\VerifyEmailAction;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/email/verify` (SPRINT_01 §S1-07). The route carries the `signed` middleware, which
 * validates the URL signature (a tampered or expired link is a `403` before this runs); the Action then
 * checks the `hash` binds to the account's current email and marks it verified.
 */
final class VerifyEmailController extends Controller
{
    public function __construct(private readonly VerifyEmailAction $action) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->action->execute(
            $request->integer('id'),
            $request->string('hash')->toString(),
        );

        return ApiResponse::success([
            'user' => [
                'uuid' => $user->uuid,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
            ],
        ], 'identity.email.verified');
    }
}
