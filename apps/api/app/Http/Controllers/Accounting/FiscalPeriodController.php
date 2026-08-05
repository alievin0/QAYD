<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FiscalPeriod;
use Illuminate\Http\JsonResponse;

/**
 * `/api/v1/accounting/fiscal-periods` — the company's accounting calendar, read-only (S2-12
 * prerequisite; the periods themselves are S2-07).
 *
 * A trial balance is computed FOR a period, and period ids are database-generated, so a client that has
 * to send `fiscal_period_id` cannot hard-code one — it has to be able to enumerate them. That is the
 * entire reason this endpoint exists, and it is why it is a read and nothing else: closing, locking and
 * reopening a period are consequential enough to have their own Actions with their own permissions, and
 * S2-12 needs none of them. There is deliberately no write counterpart here.
 *
 * `fiscal_periods` is a strict tenant table, so {@see FiscalPeriod} carries `BelongsToCompany` and the
 * read is RLS-scoped on the `pgsql_app` connection: one company can never enumerate another's calendar,
 * and with no tenant context the query returns nothing rather than everything.
 *
 * The payload is exactly the seven fields the period selector needs. Nothing about a period's close
 * history — `closed_at`, `closed_by`, `reopen_reason` and the rest — is surfaced, because a screen that
 * only picks a period has no business reading who last reopened one.
 */
final class FiscalPeriodController extends Controller
{
    /**
     * `GET /accounting/fiscal-periods` — every period, grouped by fiscal year and in period order
     * within each year, so a selector can render them without sorting anything itself.
     */
    public function index(): JsonResponse
    {
        $periods = FiscalPeriod::query()
            ->whereNull('deleted_at')
            ->orderBy('fiscal_year_id')
            ->orderBy('period_number')
            ->get();

        return ApiResponse::success([
            'fiscal_periods' => $periods->map(fn (FiscalPeriod $period): array => [
                'id' => $period->id,
                'fiscal_year_id' => $period->fiscal_year_id,
                'period_number' => $period->period_number,
                'name' => $period->name,
                'start_date' => $period->start_date,
                'end_date' => $period->end_date,
                'status' => $period->status,
            ])->all(),
        ], 'accounting.fiscal_period.list');
    }
}
