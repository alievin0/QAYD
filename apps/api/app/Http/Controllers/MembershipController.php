<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CompanyUser;
use Illuminate\Http\JsonResponse;

/**
 * A minimal tenant-scoped read used to exercise the tenant boundary end to end in S1-05: reading a
 * membership by id inside the active company. Because {@see CompanyUser} is scoped by RLS +
 * CompanyScope, a membership belonging to another company resolves to `findOrFail` → 404 — the
 * enumeration-safe "cross-tenant id read returns 404, not 403" behaviour SPRINT_01 §S1-06 requires.
 */
final class MembershipController extends Controller
{
    public function show(int $id): JsonResponse
    {
        $membership = CompanyUser::query()->findOrFail($id);

        return response()->json([
            'id' => $membership->id,
            'company_id' => $membership->company_id,
            'user_id' => $membership->user_id,
            'status' => $membership->status,
        ]);
    }
}
