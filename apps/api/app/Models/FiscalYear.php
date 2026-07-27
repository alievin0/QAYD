<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Onboarding\CreateCompanyAction;
use App\Models\Concerns\BelongsToCompany;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's accounting calendar year (docs/accounting/GENERAL_LEDGER.md "# FISCAL CALENDAR"). The
 * first row is seeded during onboarding ({@see CreateCompanyAction}); the full
 * fiscal-calendar lifecycle is Sprint 02 accounting work.
 *
 * Strict tenant-owned (`company_id BIGINT NOT NULL`), so it uses {@see BelongsToCompany}: it is scoped
 * by RLS + {@see CompanyScope} and bound to the RLS-enforced `pgsql_app` connection. The onboarding
 * action does NOT create the first row through this model — at creation time there is no active tenant
 * context yet, so the row is inserted on the privileged owner connection with an explicit `company_id`;
 * this model is the tenant-scoped read/write surface for every later request that already has a company.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $status
 */
class FiscalYear extends Model
{
    use BelongsToCompany;

    protected $table = 'fiscal_years';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'closed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
