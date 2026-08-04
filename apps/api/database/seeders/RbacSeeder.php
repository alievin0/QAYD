<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S1-09 — the fixed RBAC catalogue: the platform-wide `permissions` and the system default `roles`
 * (company_id IS NULL, is_system = true) with their role→permission mappings
 * (docs/foundation/PERMISSION_SYSTEM.md "# Roles" / "# Permission Categories",
 * docs/backend/AUTH_SERVICE.md "# Database Tables Owned" / "# Permissions").
 *
 * Idempotent by design (safe to re-run):
 *  - `permissions` upsert on the unique `key`;
 *  - system `roles` are matched by `(company_id IS NULL, key)` — a plain UNIQUE cannot enforce this
 *    because Postgres treats NULLs as distinct, so re-insertion is guarded with an explicit lookup;
 *  - `role_permissions` are `insertOrIgnore`d against the `(role_id, permission_id)` primary key.
 *
 * Runs on the privileged (owner) connection: system roles carry `company_id IS NULL`, which the
 * `roles_insert` RLS policy would reject for the non-superuser app role — the owner bypasses RLS.
 *
 * A permission is SENSITIVE (maker-checker territory) when its key ends in `.approve` / `.release` /
 * `.submit` / `.transfer` (AUTH_SERVICE.md "# Database Tables Owned": `is_sensitive`).
 */
final class RbacSeeder extends Seeder
{
    /**
     * The permission catalogue as `area => [action-or-entity.action, …]`, expanded to `<area>.<key>`.
     *
     * @var array<string, list<string>>
     */
    private const CATALOGUE = [
        // `period.*` (S2-07) is split four ways on purpose: closing a month, hard-locking it after audit
        // sign-off, reopening a closed one, and overriding a hard lock are four different levels of
        // authority over the same record (GENERAL_LEDGER.md "# Permissions").
        'accounting' => [
            'read', 'create', 'update', 'delete', 'export', 'approve', 'coa.manage', 'journal.read',
            'period.close', 'period.lock', 'period.reopen', 'period.hard_lock_override',
            // `trial_balance.*` (S2-09): reading the numbers, freezing a durable snapshot, and putting
            // a human signature on one are three different levels of authority.
            'trial_balance.read', 'trial_balance.generate', 'trial_balance.approve',
        ],
        'bank' => ['read', 'create', 'update', 'delete', 'reconcile', 'transfer'],
        'payroll' => ['read', 'calculate', 'approve', 'release', 'export'],
        'inventory' => ['read', 'create', 'adjust', 'transfer', 'delete'],
        'purchasing' => ['read', 'create', 'update', 'approve', 'delete'],
        'sales' => ['read', 'create', 'update', 'approve', 'delete'],
        'tax' => ['read', 'calculate', 'submit', 'export'],
        'reports' => ['read', 'export', 'share'],
        'documents' => ['read', 'upload', 'delete'],
        'companies' => ['read', 'create', 'update', 'delete'],
        'branches' => ['read', 'create', 'update', 'delete'],
        'departments' => ['read', 'create', 'update', 'delete'],
        'integrations' => ['read', 'manage'],
        'administration' => ['audit.read', 'settings.manage'],
        'ai' => ['chat', 'generate', 'analyze', 'approve', 'automation'],
        // Identity administration, keyed exactly as AUTH_SERVICE.md "# Permissions".
        'settings' => [
            'read',
            'users.invite',
            'users.manage',
            'roles.manage',
            'roles.approve',
            'permissions.grant',
            'api_keys.manage',
            'company.lock',
        ],
    ];

    /**
     * System default roles as `key => [name_en, name_ar, [permission selectors]]`. A selector is a full
     * permission key, an `area.*` wildcard, `reads` (every `.read`), or `all` (the whole catalogue).
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private const ROLES = [
        'owner' => ['Owner', 'مالك', ['all']],
        'ceo' => ['CEO', 'الرئيس التنفيذي', ['all']],
        'cfo' => ['CFO', 'المدير المالي', [
            'reads', 'accounting.*', 'bank.*', 'payroll.*', 'tax.*', 'inventory.*',
            'reports.*', 'documents.*', 'ai.*', 'purchasing.approve', 'sales.approve',
        ]],
        'finance_manager' => ['Finance Manager', 'مدير المالية', [
            'reads', 'accounting.coa.manage', 'accounting.create', 'accounting.update', 'accounting.export', 'accounting.approve',
            // Closes, locks and reopens months — but NOT accounting.period.hard_lock_override, which
            // stays with Owner/CEO/CFO (GENERAL_LEDGER.md "# Permissions" role matrix).
            'accounting.period.close', 'accounting.period.lock', 'accounting.period.reopen',
            'accounting.trial_balance.read', 'accounting.trial_balance.generate', 'accounting.trial_balance.approve',
            'bank.reconcile', 'bank.create', 'bank.update', 'tax.calculate', 'tax.export',
            'reports.export', 'reports.share', 'documents.upload', 'ai.chat', 'ai.analyze',
        ]],
        'senior_accountant' => ['Senior Accountant', 'محاسب أول', [
            'accounting.read', 'accounting.journal.read', 'accounting.coa.manage', 'accounting.create', 'accounting.update', 'accounting.export',
            // Prepares the trial balance but never signs it — approval is the manager's, which is the
            // separation the whole approve step exists to create.
            'accounting.trial_balance.read', 'accounting.trial_balance.generate',
            'bank.read', 'bank.reconcile', 'reports.read', 'reports.export', 'tax.read', 'tax.calculate',
            'documents.read', 'documents.upload', 'ai.chat', 'ai.analyze',
        ]],
        'accountant' => ['Accountant', 'محاسب', [
            'accounting.read', 'accounting.journal.read', 'accounting.trial_balance.read',
            'accounting.create', 'accounting.update', 'bank.read',
            'reports.read', 'documents.read', 'documents.upload', 'ai.chat',
        ]],
        'auditor' => ['Auditor', 'مدقق', ['reads', 'reports.export']],
        'hr_manager' => ['HR Manager', 'مدير الموارد البشرية', [
            'payroll.read', 'payroll.calculate', 'payroll.export', 'departments.read',
            'reports.read', 'documents.read', 'documents.upload',
        ]],
        'payroll_officer' => ['Payroll Officer', 'موظف الرواتب', [
            'payroll.read', 'payroll.calculate', 'payroll.export', 'documents.read',
        ]],
        'inventory_manager' => ['Inventory Manager', 'مدير المخزون', [
            'inventory.read', 'inventory.create', 'inventory.adjust', 'inventory.transfer',
            'inventory.delete', 'reports.read', 'documents.read',
        ]],
        'warehouse_employee' => ['Warehouse Employee', 'موظف المستودع', [
            'inventory.read', 'inventory.adjust', 'documents.read',
        ]],
        'sales_manager' => ['Sales Manager', 'مدير المبيعات', [
            'sales.read', 'sales.create', 'sales.update', 'sales.approve', 'sales.delete',
            'reports.read', 'reports.export', 'documents.read', 'ai.chat',
        ]],
        'sales_employee' => ['Sales Employee', 'موظف المبيعات', [
            'sales.read', 'sales.create', 'sales.update', 'documents.read',
        ]],
        'purchasing_manager' => ['Purchasing Manager', 'مدير المشتريات', [
            'purchasing.read', 'purchasing.create', 'purchasing.update', 'purchasing.approve',
            'purchasing.delete', 'reports.read', 'documents.read', 'ai.chat',
        ]],
        'purchasing_employee' => ['Purchasing Employee', 'موظف المشتريات', [
            'purchasing.read', 'purchasing.create', 'purchasing.update', 'documents.read',
        ]],
        'read_only' => ['Read Only', 'قراءة فقط', ['reads']],
        'external_auditor' => ['External Auditor', 'مدقق خارجي', [
            'accounting.read', 'accounting.journal.read', 'bank.read', 'reports.read', 'reports.export', 'tax.read', 'documents.read',
        ]],
    ];

    public function run(): void
    {
        $connection = DB::connection();

        $allKeys = $this->seedPermissions($connection);
        $permissionIds = $this->permissionIdMap($connection);

        foreach (self::ROLES as $key => [$nameEn, $nameAr, $selectors]) {
            $roleId = $this->upsertSystemRole($connection, $key, $nameEn, $nameAr);
            $this->assignPermissions($connection, $roleId, $this->expand($selectors, $allKeys), $permissionIds);
        }
    }

    /**
     * Upsert the whole catalogue on the unique `key`, returning every seeded permission key.
     *
     * @return list<string>
     */
    private function seedPermissions(ConnectionInterface $connection): array
    {
        $rows = [];
        $keys = [];

        foreach (self::CATALOGUE as $area => $actions) {
            foreach ($actions as $action) {
                $key = "{$area}.{$action}";
                $keys[] = $key;
                $rows[] = [
                    'key' => $key,
                    'area' => $area,
                    'is_sensitive' => $this->isSensitive($key),
                ];
            }
        }

        // ON CONFLICT (key) DO UPDATE — re-running refreshes area/is_sensitive without duplicating rows.
        $connection->table('permissions')->upsert($rows, ['key'], ['area', 'is_sensitive']);

        return $keys;
    }

    /**
     * @return array<string, int> permission key => id
     */
    private function permissionIdMap(ConnectionInterface $connection): array
    {
        $map = [];
        foreach ($connection->table('permissions')->get(['id', 'key']) as $row) {
            if (is_scalar($row->key) && is_numeric($row->id)) {
                $map[(string) $row->key] = (int) $row->id;
            }
        }

        return $map;
    }

    /**
     * Idempotently upsert one system role (company_id IS NULL). Matched on `(company_id IS NULL, key)`
     * explicitly, because a plain UNIQUE(company_id, key) does not constrain NULL company_id rows.
     */
    private function upsertSystemRole(ConnectionInterface $connection, string $key, string $nameEn, string $nameAr): int
    {
        $existing = $connection->table('roles')
            ->whereNull('company_id')
            ->where('key', $key)
            ->first(['id']);

        if ($existing !== null && is_numeric($existing->id)) {
            $connection->table('roles')->where('id', $existing->id)->update([
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'is_system' => true,
                'updated_at' => Carbon::now(),
            ]);

            return (int) $existing->id;
        }

        $inserted = $connection->selectOne(
            'INSERT INTO roles (company_id, key, name_en, name_ar, is_system) VALUES (NULL, ?, ?, ?, true) RETURNING id',
            [$key, $nameEn, $nameAr],
        );

        return $inserted !== null && is_numeric($inserted->id) ? (int) $inserted->id : 0;
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  array<string, int>  $permissionIds
     */
    private function assignPermissions(ConnectionInterface $connection, int $roleId, array $permissionKeys, array $permissionIds): void
    {
        if ($roleId === 0) {
            return;
        }

        $rows = [];
        foreach ($permissionKeys as $key) {
            if (isset($permissionIds[$key])) {
                $rows[] = ['role_id' => $roleId, 'permission_id' => $permissionIds[$key]];
            }
        }

        if ($rows !== []) {
            // ON CONFLICT (role_id, permission_id) DO NOTHING — re-running never duplicates a mapping.
            $connection->table('role_permissions')->insertOrIgnore($rows);
        }
    }

    /**
     * Expand a role's selectors against the catalogue into a concrete, de-duplicated permission list.
     *
     * @param  list<string>  $selectors
     * @param  list<string>  $allKeys
     * @return list<string>
     */
    private function expand(array $selectors, array $allKeys): array
    {
        $resolved = [];

        foreach ($selectors as $selector) {
            if ($selector === 'all') {
                $resolved = array_merge($resolved, $allKeys);

                continue;
            }

            if ($selector === 'reads') {
                $resolved = array_merge($resolved, array_filter($allKeys, static fn (string $k): bool => str_ends_with($k, '.read')));

                continue;
            }

            if (str_ends_with($selector, '.*')) {
                $prefix = substr($selector, 0, -1); // keep the trailing dot, e.g. 'accounting.'
                $resolved = array_merge($resolved, array_filter($allKeys, static fn (string $k): bool => str_starts_with($k, $prefix)));

                continue;
            }

            $resolved[] = $selector;
        }

        return array_values(array_unique($resolved));
    }

    private function isSensitive(string $key): bool
    {
        foreach (['.approve', '.release', '.submit', '.transfer'] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
