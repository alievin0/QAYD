<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\CreateJournalEntryAction;
use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Actions\Accounting\SubmitForApprovalAction;
use App\Actions\Accounting\UpdateJournalDraftAction;
use App\Actions\Accounting\VoidJournalEntryAction;
use App\Data\Accounting\JournalEntryData;
use App\Data\Accounting\JournalLineData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ReverseJournalEntryRequest;
use App\Http\Requests\Accounting\StoreJournalEntryRequest;
use App\Http\Requests\Accounting\SubmitJournalEntryRequest;
use App\Http\Requests\Accounting\UpdateJournalEntryRequest;
use App\Http\Responses\ApiResponse;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/v1/accounting/journal-entries` — the HTTP surface over the journal Actions.
 *
 * Built to exactly the shape of {@see AccountController} (S2-02): it validates input, resolves the route
 * id RLS-scoped, calls **one** Action, and shapes the standard envelope. It holds no business logic
 * whatsoever — balance, immutability, optimistic concurrency, period gating, postable accounts,
 * segregation of duties and reversal linkage all live in the S2-04/S2-05/S2-06 Actions, which existed
 * before this controller and are unchanged by it. That is the point: the Actions were always the
 * product; this is only the door.
 *
 * The `{entry}` param is resolved INSIDE the controller rather than by implicit route-model binding, for
 * the same reason as the chart of accounts: `SubstituteBindings` runs BEFORE the `tenant` middleware, so
 * a bound model would resolve with no tenant context and fail closed for everyone. Resolving here means
 * a cross-tenant id is invisible and returns 404 — never a 403 that would confirm the row exists.
 *
 * Permissions use only keys the RBAC catalogue already seeds. The split follows who does the work:
 * `accounting.journal.read` to look, `accounting.create` to draft, `accounting.update` to edit, submit
 * or discard, and `accounting.approve` to post or reverse — the two actions that move the ledger.
 */
final class JournalEntryController extends Controller
{
    /** The standard's default page size for transactional documents. */
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly CreateJournalEntryAction $createEntry,
        private readonly UpdateJournalDraftAction $updateDraft,
        private readonly SubmitForApprovalAction $submitForApproval,
        private readonly PostJournalEntryAction $postEntry,
        private readonly ReverseJournalEntryAction $reverseEntry,
        private readonly VoidJournalEntryAction $voidEntry,
    ) {}

    /** `GET /accounting/journal-entries` — the company's entries, newest first, offset-paginated. */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
        $page = max($request->integer('page', 1), 1);

        $query = JournalEntry::query();
        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();

        $entries = $query
            ->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return ApiResponse::success(
            ['journal_entries' => $entries->map(fn (JournalEntry $entry): array => $this->present($entry))->all()],
            'accounting.journal_entry.list',
            ['pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'cursor' => null]],
        );
    }

    /** `GET /accounting/journal-entries/{entry}` — one entry with its lines (cross-tenant id → 404). */
    public function show(int $entry): JsonResponse
    {
        return ApiResponse::success(
            ['journal_entry' => $this->present($this->resolve($entry), withLines: true)],
            'accounting.journal_entry.show',
        );
    }

    /** `POST /accounting/journal-entries` — create a DRAFT. Never posts, whatever the caller asks. */
    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $entry = $this->createEntry->execute($this->toData($request), $this->actorId($request));

        return ApiResponse::success(
            ['journal_entry' => $this->present($entry->refresh(), withLines: true)],
            'accounting.journal_entry.created',
            [],
            201,
        );
    }

    /** `PATCH /accounting/journal-entries/{entry}` — edit a draft, version-guarded by the Action. */
    public function update(UpdateJournalEntryRequest $request, int $entry): JsonResponse
    {
        $updated = $this->updateDraft->execute(
            $this->resolve($entry),
            $this->toData($request),
            $request->integer('version'),
            $this->actorId($request),
        );

        return ApiResponse::success(
            ['journal_entry' => $this->present($updated, withLines: true)],
            'accounting.journal_entry.updated',
        );
    }

    /** `POST /accounting/journal-entries/{entry}/submit` — hand a draft to approval. */
    public function submit(SubmitJournalEntryRequest $request, int $entry): JsonResponse
    {
        $submitted = $this->submitForApproval->execute(
            $this->resolve($entry),
            $request->integer('version'),
            // A human request is by definition not an AI actor; the AI path has no HTTP surface.
            false,
            $this->actorId($request),
        );

        return ApiResponse::success(
            ['journal_entry' => $this->present($submitted, withLines: true)],
            'accounting.journal_entry.submitted',
        );
    }

    /**
     * `POST /accounting/journal-entries/{entry}/post` — the one endpoint that moves the ledger, and the
     * only one carrying the `idempotent` middleware: a retried post must never become a second posting.
     */
    public function post(Request $request, int $entry): JsonResponse
    {
        $posted = $this->postEntry->execute($this->resolve($entry), $this->actorId($request));

        return ApiResponse::success(
            ['journal_entry' => $this->present($posted, withLines: true)],
            'accounting.journal_entry.posted',
        );
    }

    /** `POST /accounting/journal-entries/{entry}/reverse` — a NEW mirror entry; the original stands. */
    public function reverse(ReverseJournalEntryRequest $request, int $entry): JsonResponse
    {
        $reversalDate = $request->input('reversal_date');

        $mirror = $this->reverseEntry->execute(
            $this->resolve($entry),
            $this->text($request->input('reason')),
            $this->actorId($request),
            is_string($reversalDate) && $reversalDate !== '' ? $reversalDate : null,
        );

        return ApiResponse::success(
            ['journal_entry' => $this->present($mirror, withLines: true)],
            'accounting.journal_entry.reversed',
            [],
            201,
        );
    }

    /** `POST /accounting/journal-entries/{entry}/void` — discard an entry that never took effect. */
    public function void(Request $request, int $entry): JsonResponse
    {
        $voided = $this->voidEntry->execute($this->resolve($entry), $this->actorId($request));

        return ApiResponse::success(
            ['journal_entry' => $this->present($voided)],
            'accounting.journal_entry.voided',
        );
    }

    /** Tenant-scoped resolution: a cross-tenant id is simply not there (404, never 403). */
    private function resolve(int $entry): JournalEntry
    {
        /** @var JournalEntry $model */
        $model = JournalEntry::query()->findOrFail($entry);

        return $model;
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : null;
    }

    /** Build the DTO the Actions accept. A pure mapping — no rule is applied on the way through. */
    private function toData(StoreJournalEntryRequest|UpdateJournalEntryRequest $request): JournalEntryData
    {
        $rawLines = $request->input('lines');
        $rawLines = is_array($rawLines) ? $rawLines : [];

        $lines = [];
        foreach ($rawLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $description = $line['description'] ?? null;

            $lines[] = new JournalLineData(
                accountId: is_numeric($line['account_id'] ?? null) ? (int) $line['account_id'] : 0,
                debit: $this->money($line['debit'] ?? '0'),
                credit: $this->money($line['credit'] ?? '0'),
                description: is_string($description) && $description !== '' ? $description : null,
            );
        }

        $rate = $request->input('exchange_rate');
        $reference = $request->input('reference');
        $memo = $request->input('memo');

        return new JournalEntryData(
            journalDate: $this->text($request->input('journal_date')),
            entryType: $this->text($request->input('entry_type')),
            currencyCode: strtoupper($this->text($request->input('currency_code'))),
            lines: $lines,
            exchangeRate: $this->money(is_string($rate) && $rate !== '' ? $rate : '1'),
            reference: is_string($reference) && $reference !== '' ? $reference : null,
            memo: is_string($memo) && $memo !== '' ? $memo : null,
        );
    }

    /**
     * Narrow a validated money literal to a `numeric-string`. The FormRequest already rejects anything
     * else, so a non-numeric value here would mean validation was bypassed.
     *
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        $string = is_scalar($value) ? (string) $value : '0';

        return is_numeric($string) ? $string : '0';
    }

    /**
     * A validated string field. The FormRequest has already made every use of this `required`, so the
     * empty fallback is unreachable in practice — it exists so the type is honest rather than asserted.
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function present(JournalEntry $entry, bool $withLines = false): array
    {
        $payload = [
            'id' => $entry->id,
            'journal_number' => $entry->journal_number,
            'journal_date' => $entry->journal_date,
            'entry_type' => $entry->entry_type,
            'status' => $entry->status,
            'currency_code' => $entry->currency_code,
            'exchange_rate' => $entry->exchange_rate,
            'total_debit' => $entry->total_debit,
            'total_credit' => $entry->total_credit,
            'base_total_debit' => $entry->base_total_debit,
            'base_total_credit' => $entry->base_total_credit,
            'version' => $entry->version,
            'is_reversal' => $entry->is_reversal,
            'reversed_entry_id' => $entry->reversed_entry_id,
            'reversal_entry_id' => $entry->reversal_entry_id,
            'reference' => $entry->reference,
            'memo' => $entry->memo,
        ];

        if (! $withLines) {
            return $payload;
        }

        $lines = JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->orderBy('line_number')
            ->get();

        $payload['lines'] = $lines->map(fn (JournalLine $line): array => [
            'id' => $line->id,
            'line_number' => $line->line_number,
            'account_id' => $line->account_id,
            'debit' => $line->debit,
            'credit' => $line->credit,
            'base_debit' => $line->base_debit,
            'base_credit' => $line->base_credit,
            'currency_code' => $line->currency_code,
            'description' => $line->description,
        ])->all();

        return $payload;
    }
}
