<?php

declare(strict_types=1);

namespace App\Data\Onboarding;

use App\Actions\Onboarding\CreateCompanyAction;

/**
 * The validated input for {@see CreateCompanyAction}. An immutable DTO — the Action never receives a raw
 * array or Request (docs/backend/SERVICE_ARCHITECTURE.md "every entrypoint takes a DTO"). Mirrors the
 * `companies` columns onboarding is allowed to set: the legal + trade names, the bilingual display
 * names, the base currency, the fiscal-year start month, timezone, and default locale.
 */
final readonly class CreateCompanyData
{
    public function __construct(
        public string $legalName,
        public string $nameEn,
        public string $baseCurrency,
        public int $fiscalYearStartMonth,
        public ?string $tradeName = null,
        public ?string $nameAr = null,
        public string $timezone = 'Asia/Kuwait',
        public string $locale = 'ar',
    ) {}
}
