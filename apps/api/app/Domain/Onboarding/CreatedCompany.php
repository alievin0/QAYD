<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Actions\Onboarding\CreateCompanyAction;

/**
 * The result of {@see CreateCompanyAction}: a fully-seeded tenant — the company, the creator's Owner
 * membership, and the first fiscal year — identified to a client by the company's public UUID (its
 * internal, sequential `id` is never serialised). The controller projects this to the response envelope.
 */
final readonly class CreatedCompany
{
    public function __construct(
        public int $id,            // internal company id — never serialised to a client
        public string $uuid,       // public company id
        public string $legalName,
        public ?string $tradeName,
        public string $nameEn,
        public ?string $nameAr,
        public string $baseCurrency,
        public int $fiscalYearStartMonth,
        public string $timezone,
        public string $locale,
        public string $status,
        public string $roleKey,          // the creator's role in the new company — always 'owner'
        public string $fiscalYearName,
        public string $fiscalYearStart,  // ISO date (Y-m-d)
        public string $fiscalYearEnd,    // ISO date (Y-m-d)
        public string $fiscalYearStatus,
    ) {}
}
