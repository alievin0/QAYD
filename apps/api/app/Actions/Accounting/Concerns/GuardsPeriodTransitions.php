<?php

declare(strict_types=1);

namespace App\Actions\Accounting\Concerns;

use App\Exceptions\Accounting\PeriodRuleException;
use App\Models\FiscalPeriod;
use App\Services\Identity\PermissionResolver;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The two guards every fiscal-period transition shares (S2-07): *is this actor allowed to do it* and
 * *is the period actually in the state this transition starts from*, the second re-read under a row
 * lock so a concurrent close/reopen cannot race it.
 *
 * Both live here rather than in each Action because a period transition that checks only one of them is
 * a hole, and three copies of a guard is three chances to update two of them.
 */
trait GuardsPeriodTransitions
{
    protected function tenantConnection(): ConnectionInterface
    {
        return DB::connection(TenantContext::connection());
    }

    /**
     * Refuse unless the actor holds `$permission` in the period's own company.
     *
     * Resolved through {@see PermissionResolver}, which is deny-by-default: no live membership resolves
     * to the empty set, so an actor outside the company fails here rather than falling through to a
     * check that happens to pass. A null actor is treated as unauthenticated and refused — a period
     * transition is never a system action.
     */
    protected function assertPermitted(
        PermissionResolver $permissions,
        ?int $actorUserId,
        int $companyId,
        string $permission,
        string $verb,
    ): void {
        if ($actorUserId === null) {
            throw PeriodRuleException::forbidden($permission, $verb);
        }

        if (! $permissions->resolve($actorUserId, $companyId)->has($permission)) {
            throw PeriodRuleException::forbidden($permission, $verb);
        }
    }

    /**
     * Row-lock the period and return its authoritative status. Reading the status back under the lock
     * (rather than trusting the model the caller handed in) is what makes a transition safe against a
     * concurrent one: two simultaneous closes serialize here, and the second sees `closed`.
     */
    protected function lockPeriodStatus(ConnectionInterface $connection, FiscalPeriod $period): string
    {
        $locked = $connection->selectOne(
            'SELECT status FROM fiscal_periods WHERE id = ? FOR UPDATE',
            [$period->id],
        );

        if ($locked === null) {
            throw PeriodRuleException::notReopenable('unknown');
        }

        return SqlRow::string($locked, 'status');
    }
}
