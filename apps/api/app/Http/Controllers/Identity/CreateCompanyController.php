<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/companies` — the S1-07 **email-verification gate** placeholder.
 *
 * The real company-creation flow (`CreateCompanyAction`, tenant + owner seeding) is story S1-10. What
 * S1-07 must land now is the guard: an email-unverified user cannot create a company. That guard is the
 * `verified.email` middleware in front of this route — an unverified caller is stopped with
 * `403 EMAIL_NOT_VERIFIED` and never reaches this method. A verified caller reaches here and gets an
 * explicit "not yet implemented" acknowledgement rather than a fabricated company.
 */
final class CreateCompanyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Reached only by an authenticated, email-verified user (the guard passed). Real creation: S1-10.
        return ApiResponse::success(null, 'identity.company.create.pending_s1_10', status: 202);
    }
}
