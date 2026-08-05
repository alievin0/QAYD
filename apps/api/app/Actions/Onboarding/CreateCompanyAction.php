<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Data\Onboarding\CreateCompanyData;
use App\Domain\Accounting\MonthlyFiscalPeriodGenerator;
use App\Domain\Onboarding\CreatedCompany;
use App\Enums\AuditCategory;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turn an authenticated, email-verified person into a scoped tenant (SPRINT_01 §S1-10, Epic D). In ONE
 * database transaction it seeds the three rows Sprint 02's accounting core assumes already exist:
 *
 *  1. the `companies` tenant-root row (legal/trade + bilingual names, base currency, fiscal-year start
 *     month, timezone, default locale);
 *  2. the creator's `company_users` **owner membership**, bound to the seeded **Owner system role**
 *     (`company_id IS NULL`, from S1-09) — so the creator holds the Owner role's full permission grant;
 *  3. the company's **first `fiscal_years` row**, derived from the fiscal-year start month, opened for
 *     posting, together with the monthly **`fiscal_periods`** filling it — the posting engine resolves a
 *     date to a period, so a year without periods accepts nothing (S2-07).
 *
 * Then it writes a `company.created` audit row. Everything runs inside a single transaction so a
 * half-created company can never exist (SPRINT_01 Epic D: "transactionally, so a half-created company can
 * never exist"): if any step throws — an FK violation, a duplicate, a failed audit — the whole unit rolls
 * back and no orphan company, membership, or fiscal year survives.
 *
 * **Connection.** A brand-new company has no active tenant context yet — no `X-Company-Id`, no RLS GUC —
 * so this runs on the **privileged owner connection** (the default connection, a superuser that bypasses
 * RLS), exactly like registration, the membership/permission reads, and the login audit. The tenant rows
 * are stamped with the new `company_id` explicitly (never from a GUC), the `companies` INSERT satisfies
 * its `WITH CHECK (true)` policy, and the RLS-enforced `pgsql_app` connection is untouched until the user
 * later `switch-company`s into the new tenant. There is deliberately no `SET app.current_company_id`
 * here: creation precedes tenant context, and the owner connection is the sanctioned pre-tenant path.
 */
final class CreateCompanyAction
{
    /**
     * @throws RuntimeException if the Owner system role is not seeded (a server misconfiguration — the
     *                          RBAC catalogue of S1-09 must be present before onboarding runs)
     */
    public function execute(CreateCompanyData $data, User $creator): CreatedCompany
    {
        $connectionName = DB::getDefaultConnection();
        $creatorId = (int) $creator->id;

        /** @var CreatedCompany $created */
        $created = DB::connection($connectionName)->transaction(
            function (ConnectionInterface $connection) use ($data, $creatorId, $connectionName): CreatedCompany {
                // The seeded Owner system role (S1-09): company_id IS NULL, shared read-only across all
                // tenants, holds every permission. Fail fast (before writing anything) if RBAC is unseeded.
                $ownerRoleId = $this->ownerSystemRoleId($connection);

                // 1) The companies tenant-root row (Eloquent so the DB-generated uuid is read back). Runs
                //    on the default owner connection, inside this transaction.
                $company = new Company;
                $company->forceFill([
                    'legal_name' => $data->legalName,
                    'trade_name' => $data->tradeName,
                    'name_en' => $data->nameEn,
                    'name_ar' => $data->nameAr,
                    'base_currency' => strtoupper($data->baseCurrency),
                    'fiscal_year_start_month' => $data->fiscalYearStartMonth,
                    'timezone' => $data->timezone,
                    'locale_default' => $data->locale,
                    'status' => 'active',
                    'created_by' => $creatorId,
                    'updated_by' => $creatorId,
                ]);
                $company->save();
                $company->refresh(); // load the gen_random_uuid() default

                // Typed via the model's @property annotations (int $id, string $uuid).
                $companyId = $company->id;
                $companyUuid = $company->uuid;

                // 2) The creator's owner membership, bound to the Owner system role. Written on the owner
                //    connection with an explicit company_id (no tenant GUC exists yet).
                $now = Carbon::now();
                $connection->table('company_users')->insert([
                    'company_id' => $companyId,
                    'user_id' => $creatorId,
                    'role_id' => $ownerRoleId,
                    'status' => 'active',
                    'joined_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // 3) The first fiscal year, derived from the fiscal-year start month and opened for
                //    posting — plus the monthly fiscal PERIODS that fill it. Since S2-07 the posting
                //    engine resolves a date to a period, not a year, so a year without periods is a year
                //    nothing can be posted into: generating them is part of creating a usable year, not
                //    an optional extra, and it belongs in this same transaction.
                $fiscalYear = $this->firstFiscalYear($data->fiscalYearStartMonth);
                $fiscalYearId = (int) $connection->table('fiscal_years')->insertGetId([
                    'company_id' => $companyId,
                    'name' => $fiscalYear['name'],
                    'start_date' => $fiscalYear['start'],
                    'end_date' => $fiscalYear['end'],
                    'status' => 'open',
                    'created_by' => $creatorId,
                    'updated_by' => $creatorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                MonthlyFiscalPeriodGenerator::generate(
                    connection: $connection,
                    companyId: $companyId,
                    fiscalYearId: $fiscalYearId,
                    startDate: $fiscalYear['start'],
                    endDate: $fiscalYear['end'],
                    yearStatus: 'open',
                    actorUserId: $creatorId,
                );

                // 4) Audit the creation. Written on this same owner connection (past RLS) with the new
                //    company_id, inside the transaction — so a failed audit rolls the company back too.
                AuditLogger::record(
                    action: 'company.created',
                    category: AuditCategory::DataMutation,
                    entityType: 'companies',
                    entityId: $companyId,
                    newValues: [
                        'legal_name' => $data->legalName,
                        'name_en' => $data->nameEn,
                        'base_currency' => strtoupper($data->baseCurrency),
                        'fiscal_year_start_month' => $data->fiscalYearStartMonth,
                        'owner_user_id' => $creatorId,
                    ],
                    companyId: $companyId,
                    actorUserId: $creatorId,
                    connection: $connectionName,
                );

                return new CreatedCompany(
                    id: $companyId,
                    uuid: $companyUuid,
                    legalName: $data->legalName,
                    tradeName: $data->tradeName,
                    nameEn: $data->nameEn,
                    nameAr: $data->nameAr,
                    baseCurrency: strtoupper($data->baseCurrency),
                    fiscalYearStartMonth: $data->fiscalYearStartMonth,
                    timezone: $data->timezone,
                    locale: $data->locale,
                    status: 'active',
                    roleKey: 'owner',
                    fiscalYearName: $fiscalYear['name'],
                    fiscalYearStart: $fiscalYear['start'],
                    fiscalYearEnd: $fiscalYear['end'],
                    fiscalYearStatus: 'open',
                );
            }
        );

        return $created;
    }

    /**
     * The internal id of the seeded Owner system role (`company_id IS NULL`, `key = 'owner'`,
     * `is_system = true`). Looked up on the owner connection (bypasses RLS) — the same self-scoped,
     * pre-tenant read path the authorization repositories use.
     */
    private function ownerSystemRoleId(ConnectionInterface $connection): int
    {
        $roleId = $connection->table('roles')
            ->whereNull('company_id')
            ->where('key', 'owner')
            ->where('is_system', true)
            ->value('id');

        if (! is_numeric($roleId)) {
            throw new RuntimeException(
                'Owner system role is not seeded; run the RBAC seeder (S1-09) before creating a company.'
            );
        }

        return (int) $roleId;
    }

    /**
     * The company's first fiscal year: the 12-month period that CONTAINS today and starts on the 1st of
     * the configured fiscal-year start month. For a January start this is the calendar year
     * (2026-01-01 → 2026-12-31); for an April start it is 2026-04-01 → 2027-03-31. Labelled by the year
     * the period opens in (e.g. `FY2026`).
     *
     * @return array{name: string, start: string, end: string}
     */
    private function firstFiscalYear(int $startMonth): array
    {
        $today = Carbon::now();
        $startYear = $today->month >= $startMonth ? $today->year : $today->year - 1;

        $start = Carbon::createFromDate($startYear, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addYear()->subDay(); // inclusive end, exactly a 12-month span

        return [
            'name' => 'FY'.$startYear,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
    }
}
