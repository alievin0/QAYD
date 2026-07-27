<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S1-04 migration test — asserts the core identity/tenant schema really exists in PostgreSQL.
 *
 * The default test connection is sqlite :memory: (phpunit.xml), which cannot represent this
 * Postgres-specific schema (citext, GENERATED ALWAYS identity, partial indexes, CHECK constraints).
 * So this test derives a dedicated `pgsql_schema_test` connection from the real `pgsql` connection
 * config, runs `migrate:fresh` on it, and asserts the deployed schema via Schema::hasTable /
 * hasColumns and information_schema. Only the database name is overridden, because phpunit forces
 * DB_DATABASE to `:memory:`; host/port/credentials come straight from the configured pgsql params.
 */

/** @var string */
const PG = 'pgsql_schema_test';

beforeEach(function (): void {
    // Each Pest test boots a fresh application, so the connection config is (re)registered every
    // time; the external Postgres schema itself persists, so migrate:fresh runs only once.
    /** @var array<string, mixed> $base */
    $base = config('database.connections.pgsql');
    $database = $base['database'] ?? null;
    if (! is_string($database) || $database === '' || $database === ':memory:') {
        $database = 'qayd';
    }
    $base['database'] = $database;
    config(['database.connections.'.PG => $base]);

    DB::purge(PG);

    static $migrated = false;
    if (! $migrated) {
        Artisan::call('migrate:fresh', ['--database' => PG, '--force' => true]);
        $migrated = true;
    }
});

/**
 * Column-name sets (sorted) of every UNIQUE constraint on a table, via information_schema.
 *
 * @return list<list<string>>
 */
function uniqueConstraintColumnSets(string $table): array
{
    /** @var list<stdClass> $rows */
    $rows = DB::connection(PG)->select(
        <<<'SQL'
            SELECT tc.constraint_name, kcu.column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = tc.constraint_name
             AND kcu.constraint_schema = tc.constraint_schema
            WHERE tc.constraint_type = 'UNIQUE'
              AND tc.table_schema = 'public'
              AND tc.table_name = ?
            SQL,
        [$table],
    );

    /** @var array<string, list<string>> $byConstraint */
    $byConstraint = [];
    foreach ($rows as $row) {
        $name = $row->constraint_name;
        $column = $row->column_name;
        if (is_string($name) && is_string($column)) {
            $byConstraint[$name][] = $column;
        }
    }

    return array_values(array_map(
        static function (array $cols): array {
            sort($cols);

            return $cols;
        },
        $byConstraint,
    ));
}

it('creates all seven core identity/tenant tables', function (): void {
    $schema = Schema::connection(PG);

    foreach ([
        'companies',
        'users',
        'company_users',
        'roles',
        'permissions',
        'role_permissions',
        'company_user_permissions',
    ] as $table) {
        expect($schema->hasTable($table))->toBeTrue("missing table: {$table}");
    }
});

it('gives companies a uuid column plus the tenant-root shape', function (): void {
    $schema = Schema::connection(PG);

    expect($schema->hasColumn('companies', 'uuid'))->toBeTrue();
    expect($schema->hasColumns('companies', [
        'id', 'uuid', 'legal_name', 'name_en', 'base_currency',
        'fiscal_year_start_month', 'status', 'settings', 'created_at', 'deleted_at',
    ]))->toBeTrue();

    // companies is the tenant root; it must NOT itself be tenant-scoped.
    expect($schema->hasColumn('companies', 'company_id'))->toBeFalse();
});

it('enforces a unique users.email (citext)', function (): void {
    $schema = Schema::connection(PG);

    expect($schema->hasColumns('users', [
        'id', 'uuid', 'email', 'password_hash', 'name', 'status', 'deleted_at',
    ]))->toBeTrue();

    // The email column type is citext (case-insensitive uniqueness).
    $type = DB::connection(PG)->selectOne(
        "SELECT udt_name FROM information_schema.columns
         WHERE table_schema='public' AND table_name='users' AND column_name='email'",
    );
    assert($type instanceof stdClass);
    expect($type->udt_name)->toBe('citext');

    expect(uniqueConstraintColumnSets('users'))->toContain(['email']);
});

it('enforces a unique company_users (company_id, user_id) and carries company_id', function (): void {
    // Tenant-owned pivot: company_id must be present and NOT NULL.
    $companyId = DB::connection(PG)->selectOne(
        "SELECT is_nullable FROM information_schema.columns
         WHERE table_schema='public' AND table_name='company_users' AND column_name='company_id'",
    );
    assert($companyId instanceof stdClass);
    expect($companyId->is_nullable)->toBe('NO');

    expect(uniqueConstraintColumnSets('company_users'))->toContain(['company_id', 'user_id']);
});

it('models role_permissions as a composite-key junction', function (): void {
    $schema = Schema::connection(PG);

    expect($schema->hasColumns('role_permissions', ['role_id', 'permission_id']))->toBeTrue();
    // Pure junction — no surrogate id column.
    expect($schema->hasColumn('role_permissions', 'id'))->toBeFalse();
});
