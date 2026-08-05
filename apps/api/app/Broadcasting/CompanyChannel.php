<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Http\Middleware\ResolveTenantCompany;
use App\Models\Company;
use App\Models\User;

/**
 * Authorization for `private-company.{uuid}`, the company-scoped refresh feed (SPRINT_02 §S2-13).
 *
 * A socket subscription is a third way to reach tenant data, and neither of the guards protecting the
 * other two runs on it: RLS governs the database connection, {@see ResolveTenantCompany} governs the
 * request, and a WebSocket subscribe passes through neither. This class is that boundary, and it asks
 * the same question the middleware asks — is there a live, active membership — of the same table under
 * the same conditions, so the answers cannot drift apart.
 *
 * A class rather than a closure because a boundary deserves to be tested directly rather than through
 * whichever broadcaster happens to be configured: the `log` driver, which CI uses, authorizes
 * everything, so an endpoint-level test would prove nothing about this rule.
 */
final class CompanyChannel
{
    /**
     * Whether $user may subscribe to this company's feed.
     *
     * Returns a bool, never the "channel data" array a presence channel would. This is a private
     * channel — subscribers are not announced to one another, and returning user attributes here would
     * publish them to every other member of the company.
     */
    public function join(User $user, string $companyUuid): bool
    {
        $company = Company::query()
            ->where('uuid', $companyUuid)
            ->where('status', '!=', 'archived')
            ->whereNull('deleted_at')
            ->first();

        if (! $company instanceof Company) {
            return false;
        }

        return $company->getConnection()->table('company_users')
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
    }
}
