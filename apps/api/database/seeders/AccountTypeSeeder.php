<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * S2-01 — seed the seven system account types (docs/accounting/CHART_OF_ACCOUNTS.md). Idempotent:
 * upsert on the unique `key`, so re-running refreshes names/flags without duplicating. Runs on the
 * owner connection — `account_types` is a global catalogue with no RLS, like `permissions`.
 */
final class AccountTypeSeeder extends Seeder
{
    /**
     * key => [name_en, name_ar, normal_balance, is_balance_sheet, sort_order]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool, 4: int}>
     */
    private const TYPES = [
        'asset' => ['Assets', 'الأصول', 'debit', true, 1],
        'liability' => ['Liabilities', 'الالتزامات', 'credit', true, 2],
        'equity' => ['Equity', 'حقوق الملكية', 'credit', true, 3],
        'revenue' => ['Revenue', 'الإيرادات', 'credit', false, 4],
        'expense' => ['Expenses', 'المصروفات', 'debit', false, 5],
        'other_income' => ['Other Income', 'إيرادات أخرى', 'credit', false, 6],
        'other_expense' => ['Other Expenses', 'مصروفات أخرى', 'debit', false, 7],
    ];

    public function run(): void
    {
        $rows = [];

        foreach (self::TYPES as $key => [$nameEn, $nameAr, $normalBalance, $isBalanceSheet, $sortOrder]) {
            $rows[] = [
                'key' => $key,
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'normal_balance' => $normalBalance,
                'is_balance_sheet' => $isBalanceSheet,
                'sort_order' => $sortOrder,
            ];
        }

        // ON CONFLICT (key) DO UPDATE — re-running refreshes the catalogue without duplicating rows.
        DB::connection()->table('account_types')->upsert(
            $rows,
            ['key'],
            ['name_en', 'name_ar', 'normal_balance', 'is_balance_sheet', 'sort_order'],
        );
    }
}
