<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Actions\Onboarding\CreateCompanyAction;
use App\Data\Onboarding\CreateCompanyData;
use App\Domain\Onboarding\CreatedCompany;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Requests\Onboarding\CreateCompanyRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/companies` (SPRINT_01 §S1-10). An email-verified user with zero companies creates one
 * and becomes its Owner.
 *
 * The route stack ahead of this controller enforces the security contract: `auth:web,jwt` establishes
 * the caller, then the `verified.email` gate refuses an unverified caller with `403 EMAIL_NOT_VERIFIED`
 * ({@see EnsureEmailVerified}) — an unverified user never reaches this method. Thin
 * by design: validate → DTO → {@see CreateCompanyAction} (the transactional seed) → envelope. The
 * response never exposes the internal, sequential company id — only the public UUID the caller then
 * `switch-company`s into.
 */
final class CreateCompanyController extends Controller
{
    public function __construct(private readonly CreateCompanyAction $action) {}

    public function __invoke(CreateCompanyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $locale = $request->string('locale')->toString();
        $timezone = $request->string('timezone')->toString();

        $created = $this->action->execute(new CreateCompanyData(
            legalName: $request->string('legal_name')->toString(),
            nameEn: $request->string('name_en')->toString(),
            baseCurrency: $request->string('base_currency')->toString(),
            fiscalYearStartMonth: $request->integer('fiscal_year_start_month'),
            tradeName: $request->filled('trade_name') ? $request->string('trade_name')->toString() : null,
            nameAr: $request->filled('name_ar') ? $request->string('name_ar')->toString() : null,
            timezone: $timezone !== '' ? $timezone : 'Asia/Kuwait',
            locale: $locale !== '' ? $locale : 'ar',
        ), $user);

        return ApiResponse::success($this->payload($created), 'identity.company.created', status: 201);
    }

    /**
     * The client-safe projection of the created tenant: the company by its public UUID, the creator's
     * role, and the first fiscal year. The internal `id` is never serialised.
     *
     * @return array<string, mixed>
     */
    private function payload(CreatedCompany $created): array
    {
        return [
            'company' => [
                'uuid' => $created->uuid,
                'legal_name' => $created->legalName,
                'trade_name' => $created->tradeName,
                'name_en' => $created->nameEn,
                'name_ar' => $created->nameAr,
                'base_currency' => $created->baseCurrency,
                'fiscal_year_start_month' => $created->fiscalYearStartMonth,
                'timezone' => $created->timezone,
                'locale' => $created->locale,
                'status' => $created->status,
                'role' => $created->roleKey,
                'fiscal_year' => [
                    'name' => $created->fiscalYearName,
                    'start_date' => $created->fiscalYearStart,
                    'end_date' => $created->fiscalYearEnd,
                    'status' => $created->fiscalYearStatus,
                ],
            ],
        ];
    }
}
