<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AccountType;
use Illuminate\Http\JsonResponse;

/**
 * `/api/v1/accounting/account-types` — the seven system account classifications
 * (docs/accounting/CHART_OF_ACCOUNTS.md).
 *
 * Read-only, and there is no write counterpart anywhere by design: `account_types` is a GLOBAL
 * catalogue shared by every tenant, and the S2-01 hardening migration REVOKEd INSERT/UPDATE/DELETE on
 * it from the runtime `qayd_app` role while leaving SELECT. So "read-only" here is a database grant,
 * not a convention this controller upholds — a write would be refused even if someone added a route
 * for it.
 *
 * It carries no `company_id` and therefore no RLS, which is correct for a catalogue but means the
 * tenant boundary is not what gates it. Two other things are: the route sits behind `auth → tenant`,
 * so an unauthenticated or company-less request never arrives, and behind
 * `permission:accounting.journal.read`, so a member without accounting read access gets 403. The rows
 * are identical for every tenant, so there is nothing here one company could learn about another.
 *
 * The payload is deliberately the SAME shape already embedded as `account.account_type` on every
 * account response, so the frontend has exactly one notion of what an account type is.
 */
final class AccountTypeController extends Controller
{
    /** `GET /accounting/account-types` — the catalogue, in its canonical presentation order. */
    public function index(): JsonResponse
    {
        $types = AccountType::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'account_types' => $types->map(fn (AccountType $type): array => [
                'id' => $type->id,
                'key' => $type->key,
                'name_en' => $type->name_en,
                'name_ar' => $type->name_ar,
                'normal_balance' => $type->normal_balance,
                'is_balance_sheet' => $type->is_balance_sheet,
            ])->all(),
        ], 'accounting.account_type.list');
    }
}
