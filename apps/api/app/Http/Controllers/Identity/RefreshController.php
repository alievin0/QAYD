<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Domain\Identity\Credential;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Identity\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/refresh` (docs/backend/AUTH_SERVICE.md "# Endpoints Backed") — rotation-on-use for
 * bearer clients. A live refresh token is exchanged for a fresh access+refresh pair in the same family;
 * replaying an already-rotated token trips family-wide reuse detection in {@see TokenService::rotate()}
 * and revokes the whole chain.
 */
final class RefreshController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{refresh_token: string} $validated */
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $credential = $this->tokens->rotate($validated['refresh_token']);

        return ApiResponse::success($this->payload($credential), 'identity.refreshed');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Credential $credential): array
    {
        return [
            'status' => 'authenticated',
            'token_type' => $credential->tokenType,
            'access_token' => $credential->accessToken,
            'expires_in' => $credential->accessExpiresIn,
            'refresh_token' => $credential->refreshToken,
            'refresh_expires_in' => $credential->refreshExpiresIn,
        ];
    }
}
