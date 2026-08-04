<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\LedgerActivityQuery;
use App\Domain\Accounting\LedgerActivityRow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\LedgerActivityRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Services\Accounting\LedgerQueryService;
use App\Support\Cursor;
use Illuminate\Http\JsonResponse;

/**
 * `/api/v1/accounting/ledger` (SPRINT_02 §S2-08). The HTTP surface over {@see LedgerQueryService}: it
 * validates the query string, calls exactly one service, and shapes the standard envelope. It holds no
 * business logic and — being the read side of an append-only projection — writes nothing at all.
 *
 * The `{account}` param is resolved INSIDE the controller, after the `tenant` middleware has pinned the
 * active company and the RLS GUC, exactly as {@see AccountController} does: Laravel's implicit
 * route-model binding runs in `SubstituteBindings`, which is BEFORE `tenant`, so a bound model would
 * resolve with no tenant context and fail closed for everyone. Resolving here means a cross-tenant
 * account id is simply invisible and returns 404 — never a 403 that would confirm the row exists.
 */
final class LedgerController extends Controller
{
    public function __construct(private readonly LedgerQueryService $ledger) {}

    /**
     * `GET /accounting/ledger/accounts/{account}/activity` — every posted line on the account in date
     * order, each with its running balance, cursor-paginated.
     */
    public function activity(LedgerActivityRequest $request, int $account): JsonResponse
    {
        $model = Account::query()->findOrFail($account);

        $pointer = Cursor::decode($this->optionalString($request->input('cursor')));

        $page = $this->ledger->accountActivity(new LedgerActivityQuery(
            accountId: $model->id,
            from: $this->optionalString($request->input('from')),
            to: $this->optionalString($request->input('to')),
            entryType: $this->optionalString($request->input('entry_type')),
            afterDate: isset($pointer['d']) ? (string) $pointer['d'] : null,
            afterId: isset($pointer['i']) ? (int) $pointer['i'] : null,
            perPage: $this->perPage($request->input('per_page')),
        ));

        return ApiResponse::success(
            [
                'account' => [
                    'id' => $model->id,
                    'code' => $model->code,
                    'name_en' => $model->name_en,
                    'name_ar' => $model->name_ar,
                ],
                'opening_balance' => $page->openingBalance,
                'closing_balance' => $page->closingBalance,
                'lines' => array_map($this->present(...), $page->rows),
            ],
            'accounting.ledger.activity',
            [
                // Cursor responses carry a null `page` and a null `total` by design: counting a table
                // this size on every request is exactly what cursor pagination exists to avoid
                // (docs/api/API_ARCHITECTURE.md "# Cursor-style pagination").
                'pagination' => [
                    'page' => null,
                    'per_page' => $page->perPage,
                    'total' => null,
                    'cursor' => $page->nextCursor,
                ],
            ],
        );
    }

    /**
     * Clamp the page size to the standard's ceiling for high-volume ledger data rather than rejecting
     * an oversized request — documented, predictable clamping, per the API standard.
     */
    private function perPage(mixed $requested): int
    {
        if (! is_numeric($requested)) {
            return LedgerActivityQuery::DEFAULT_PER_PAGE;
        }

        return min(max((int) $requested, 1), LedgerActivityQuery::MAX_PER_PAGE);
    }

    /** A query-string value that may be absent; anything non-string is treated as absent. */
    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LedgerActivityRow $row): array
    {
        return [
            'id' => $row->id,
            'journal_entry_id' => $row->journalEntryId,
            'journal_line_id' => $row->journalLineId,
            'entry_date' => $row->entryDate,
            'entry_type' => $row->entryType,
            'currency_code' => $row->currencyCode,
            'debit' => $row->debit,
            'credit' => $row->credit,
            'base_debit' => $row->baseDebit,
            'base_credit' => $row->baseCredit,
            'signed_base_amount' => $row->signedBaseAmount,
            'running_balance' => $row->runningBalance,
            'description' => $row->description,
            'reference' => $row->reference,
        ];
    }
}
