<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\ApproveTrialBalanceSnapshotAction;
use App\Actions\Accounting\GenerateTrialBalanceSnapshotAction;
use App\Domain\Accounting\TrialBalanceRow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\GenerateTrialBalanceRequest;
use App\Http\Responses\ApiResponse;
use App\Models\TrialBalanceSnapshot;
use App\Models\TrialBalanceSnapshotLine;
use App\Services\Accounting\TrialBalanceService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/v1/accounting/reports/trial-balance` (SPRINT_02 §S2-09). Validates, calls exactly one service
 * or Action, and shapes the standard envelope — no business logic.
 *
 * Two distinct things live here, and conflating them would be the mistake:
 *
 *  - **`compute`** answers "what does the ledger say right now?" It is a live aggregate and stores
 *    nothing, so it is always current and never needs approval.
 *  - **`generate`** answers "freeze what the ledger says, so a human can sign it." That produces a
 *    durable, versioned snapshot which becomes immutable once approved.
 *
 * `generate` returns **202** with the snapshot already visible in `generating` when the run was handed
 * to the reports queue, and **201** when it completed inline. The caller polls `show` either way, so it
 * never has to branch on which happened.
 */
final class TrialBalanceController extends Controller
{
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly GenerateTrialBalanceSnapshotAction $generateSnapshot,
        private readonly ApproveTrialBalanceSnapshotAction $approveSnapshot,
    ) {}

    /** `GET /accounting/reports/trial-balance?fiscal_period_id=N` — the live, unstored trial balance. */
    public function compute(Request $request): JsonResponse
    {
        $periodId = $request->integer('fiscal_period_id');
        $balance = $this->trialBalance->compute($periodId);

        return ApiResponse::success([
            'fiscal_period_id' => $periodId,
            'period_start_date' => $balance->periodStartDate,
            'as_of_date' => $balance->asOfDate,
            'total_debit' => $balance->totalDebit,
            'total_credit' => $balance->totalCredit,
            'variance' => $balance->variance,
            'is_balanced' => $balance->isBalanced(),
            'lines' => array_map($this->presentRow(...), $balance->rows),
        ], 'accounting.trial_balance.computed');
    }

    /** `POST /accounting/reports/trial-balance` — freeze a snapshot (201 inline, 202 queued). */
    public function generate(GenerateTrialBalanceRequest $request): JsonResponse
    {
        $type = $request->input('type');

        $snapshot = $this->generateSnapshot->execute(
            companyId: TenantContext::companyId() ?? 0,
            fiscalPeriodId: $request->integer('fiscal_period_id'),
            actorUserId: $this->actorId($request),
            type: is_string($type) ? $type : 'unadjusted',
        );

        $queued = $snapshot->status === TrialBalanceSnapshot::STATUS_GENERATING;

        return ApiResponse::success(
            ['snapshot' => $this->presentSnapshot($snapshot), 'queued' => $queued],
            $queued ? 'accounting.trial_balance.queued' : 'accounting.trial_balance.generated',
            [],
            $queued ? 202 : 201,
        );
    }

    /** `GET /accounting/reports/trial-balance/{snapshot}` — a stored snapshot with its frozen lines. */
    public function show(int $snapshot): JsonResponse
    {
        $model = TrialBalanceSnapshot::query()->findOrFail($snapshot);

        $lines = TrialBalanceSnapshotLine::query()
            ->where('snapshot_id', $model->id)
            ->orderBy('account_code')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'snapshot' => $this->presentSnapshot($model),
            'lines' => $lines->map(fn (TrialBalanceSnapshotLine $line): array => [
                'account_id' => $line->account_id,
                'account_code' => $line->account_code,
                'account_name_en' => $line->account_name_en,
                'account_name_ar' => $line->account_name_ar,
                'normal_balance' => $line->normal_balance,
                'opening_debit' => $line->opening_debit,
                'opening_credit' => $line->opening_credit,
                'period_debit' => $line->period_debit,
                'period_credit' => $line->period_credit,
                'closing_debit' => $line->closing_debit,
                'closing_credit' => $line->closing_credit,
                'is_abnormal_balance' => $line->is_abnormal_balance,
                'source_line_count' => $line->source_line_count,
            ])->all(),
        ], 'accounting.trial_balance.show');
    }

    /** `POST /accounting/reports/trial-balance/{snapshot}/approve` — sign it; the figures freeze. */
    public function approve(Request $request, int $snapshot): JsonResponse
    {
        $model = TrialBalanceSnapshot::query()->findOrFail($snapshot);

        $approved = $this->approveSnapshot->execute($model, $this->actorId($request));

        return ApiResponse::success(
            ['snapshot' => $this->presentSnapshot($approved)],
            'accounting.trial_balance.approved',
        );
    }

    /** The authenticated user's id, or null — the Actions refuse a null actor themselves. */
    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSnapshot(TrialBalanceSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'fiscal_period_id' => $snapshot->fiscal_period_id,
            'period_start_date' => $snapshot->period_start_date,
            'as_of_date' => $snapshot->as_of_date,
            'type' => $snapshot->type,
            'status' => $snapshot->status,
            'version' => $snapshot->version,
            'is_current' => $snapshot->is_current,
            'parent_snapshot_id' => $snapshot->parent_snapshot_id,
            'currency_code' => $snapshot->currency_code,
            'total_debit' => $snapshot->total_debit,
            'total_credit' => $snapshot->total_credit,
            'variance' => $snapshot->variance,
            'account_count' => $snapshot->account_count,
            'line_count' => $snapshot->line_count,
            'has_warnings' => $snapshot->has_warnings,
            'content_hash' => $snapshot->content_hash,
            'approved_by' => $snapshot->approved_by,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRow(TrialBalanceRow $row): array
    {
        return [
            'account_id' => $row->accountId,
            'account_code' => $row->accountCode,
            'account_name_en' => $row->accountNameEn,
            'account_name_ar' => $row->accountNameAr,
            'normal_balance' => $row->normalBalance,
            'opening_debit' => $row->openingDebit,
            'opening_credit' => $row->openingCredit,
            'period_debit' => $row->periodDebit,
            'period_credit' => $row->periodCredit,
            'closing_debit' => $row->closingDebit,
            'closing_credit' => $row->closingCredit,
            'is_abnormal_balance' => $row->isAbnormalBalance,
            'source_line_count' => $row->sourceLineCount,
        ];
    }
}
