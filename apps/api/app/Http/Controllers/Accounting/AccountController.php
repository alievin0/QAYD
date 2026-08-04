<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\CreateAccountAction;
use App\Actions\Accounting\DeactivateAccountAction;
use App\Actions\Accounting\ReclassifyAccountAction;
use App\Actions\Accounting\UpdateAccountAction;
use App\Data\Accounting\CreateAccountData;
use App\Data\Accounting\ReclassifyAccountData;
use App\Data\Accounting\UpdateAccountData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ReclassifyAccountRequest;
use App\Http\Requests\Accounting\StoreAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\AccountType;
use Illuminate\Http\JsonResponse;

/**
 * `/api/v1/accounting/accounts` (SPRINT_02 §S2-02). The HTTP surface over the S2-01 chart-of-accounts
 * Actions: it validates input, orchestrates exactly one Action, and shapes the standard envelope — it
 * holds NO business logic (every rule — type exists, parent same-company, code unique, posted-account
 * guard — lives in the Actions). Reads require `accounting.journal.read`, writes `accounting.coa.manage`
 * (the route `permission:` gate).
 *
 * The `{account}` route param is resolved to its model INSIDE the controller — after the `tenant`
 * middleware has pinned the active company + RLS GUC — via the tenant-scoped {@see resolve()}, exactly
 * like S1's MembershipController. (Laravel's implicit route-model binding runs in `SubstituteBindings`
 * BEFORE the `tenant` middleware, so a bound model would resolve with no tenant context and fail closed;
 * resolving here guarantees the CompanyScope + RLS are active.) A cross-tenant id is therefore invisible
 * and resolves to 404 (`findOrFail`), never another tenant's row. Names are returned bilingual (en/ar).
 */
final class AccountController extends Controller
{
    public function __construct(
        private readonly CreateAccountAction $createAccount,
        private readonly UpdateAccountAction $updateAccount,
        private readonly ReclassifyAccountAction $reclassifyAccount,
        private readonly DeactivateAccountAction $deactivateAccount,
    ) {}

    /** `GET /accounting/accounts` — the flat chart, deterministically ordered by code then id. */
    public function index(): JsonResponse
    {
        $types = $this->typeMap();
        $accounts = Account::query()->orderBy('code')->orderBy('id')->get();

        $data = $accounts->map(fn (Account $account): array => $this->present($account, $types))->all();

        return ApiResponse::success(['accounts' => array_values($data)], 'accounting.account.list');
    }

    /** `GET /accounting/accounts/tree` — the same accounts nested under their parents (same ordering). */
    public function tree(): JsonResponse
    {
        $types = $this->typeMap();
        $accounts = Account::query()->orderBy('code')->orderBy('id')->get()->all();

        return ApiResponse::success(['accounts' => $this->buildTree($accounts, $types, null)], 'accounting.account.tree');
    }

    /** `GET /accounting/accounts/{account}` — one account (cross-tenant id → 404, scoped resolution). */
    public function show(int $account): JsonResponse
    {
        $model = $this->resolve($account);

        return ApiResponse::success(['account' => $this->present($model, $this->typeMap())], 'accounting.account.shown');
    }

    /** `POST /accounting/accounts` — create an account via {@see CreateAccountAction}. */
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->createAccount->execute(new CreateAccountData(
            accountTypeId: $request->integer('account_type_id'),
            code: $request->string('code')->toString(),
            nameEn: $request->string('name_en')->toString(),
            nameAr: $request->string('name_ar')->toString(),
            parentId: $request->filled('parent_id') ? $request->integer('parent_id') : null,
            isControlAccount: $request->boolean('is_control_account'),
            controlAccountOf: $request->filled('control_account_of') ? $request->string('control_account_of')->toString() : null,
        ));

        return ApiResponse::success(['account' => $this->present($account, $this->typeMap())], 'accounting.account.created', status: 201);
    }

    /** `PATCH /accounting/accounts/{account}` — rename, or renumber (guarded), via {@see UpdateAccountAction}. */
    public function update(UpdateAccountRequest $request, int $account): JsonResponse
    {
        $updated = $this->updateAccount->execute($this->resolve($account), new UpdateAccountData(
            code: $request->filled('code') ? $request->string('code')->toString() : null,
            nameEn: $request->filled('name_en') ? $request->string('name_en')->toString() : null,
            nameAr: $request->filled('name_ar') ? $request->string('name_ar')->toString() : null,
        ));

        return ApiResponse::success(['account' => $this->present($updated, $this->typeMap())], 'accounting.account.updated');
    }

    /** `POST /accounting/accounts/{account}/reclassify` — change the account type via {@see ReclassifyAccountAction}. */
    public function reclassify(ReclassifyAccountRequest $request, int $account): JsonResponse
    {
        $result = $this->reclassifyAccount->execute($this->resolve($account), new ReclassifyAccountData(
            accountTypeId: $request->integer('account_type_id'),
        ));

        return ApiResponse::success(['account' => $this->present($result, $this->typeMap())], 'accounting.account.reclassified');
    }

    /** `POST /accounting/accounts/{account}/deactivate` — deactivate via {@see DeactivateAccountAction}. */
    public function deactivate(int $account): JsonResponse
    {
        $result = $this->deactivateAccount->execute($this->resolve($account));

        return ApiResponse::success(['account' => $this->present($result, $this->typeMap())], 'accounting.account.deactivated');
    }

    /**
     * Resolve a `{account}` route id to its model in the active tenant. Runs after the `tenant`
     * middleware, so the CompanyScope + RLS are active: a cross-tenant (or unknown) id yields no row →
     * `findOrFail` → 404 RESOURCE_NOT_FOUND, the enumeration-safe behaviour SPRINT_01 §S1-06 requires.
     */
    private function resolve(int $id): Account
    {
        return Account::query()->findOrFail($id);
    }

    /**
     * The client-safe projection of an account: its own fields (bilingual names + control-account
     * designation) plus its account type (classification + normal balance).
     *
     * @param  array<int, AccountType>  $types
     * @return array<string, mixed>
     */
    private function present(Account $account, array $types): array
    {
        $type = $types[$account->account_type_id] ?? null;

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name_en' => $account->name_en,
            'name_ar' => $account->name_ar,
            'parent_id' => $account->parent_id,
            'normal_balance' => $account->normal_balance,
            'status' => $account->status,
            'is_control_account' => $account->is_control_account,
            // Whether a journal line may reference this account directly. Carried on every account
            // payload so a client never has to guess it from the tree shape — a leaf can be a header.
            'allow_posting' => $account->allow_posting,
            'control_account_of' => $account->control_account_of,
            'account_type' => $type instanceof AccountType ? [
                'id' => $type->id,
                'key' => $type->key,
                'name_en' => $type->name_en,
                'name_ar' => $type->name_ar,
                'normal_balance' => $type->normal_balance,
                'is_balance_sheet' => $type->is_balance_sheet,
            ] : null,
        ];
    }

    /**
     * Nest accounts under their parents. Deterministic: the input list is already ordered by code then
     * id, and this preserves that order at every level. O(n²) by design — a chart of accounts is small
     * and this sprint does not optimise prematurely.
     *
     * @param  array<int, Account>  $accounts
     * @param  array<int, AccountType>  $types
     * @return list<array<string, mixed>>
     */
    private function buildTree(array $accounts, array $types, ?int $parentId): array
    {
        $nodes = [];

        foreach ($accounts as $account) {
            if ($account->parent_id === $parentId) {
                $node = $this->present($account, $types);
                $node['children'] = $this->buildTree($accounts, $types, $account->id);
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * The account-type catalogue keyed by id (7 global rows; read once per request — no N+1, no cache).
     *
     * @return array<int, AccountType>
     */
    private function typeMap(): array
    {
        $map = [];

        foreach (AccountType::all() as $type) {
            $map[$type->id] = $type;
        }

        return $map;
    }
}
