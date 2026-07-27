<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\TenantContext;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * S1-06 two-tenant isolation harness.
 *
 * RLS is a PostgreSQL feature, so these tests cannot run on the phpunit default sqlite :memory:
 * connection. This harness derives two real Postgres connections from the configured `pgsql`
 * connection — one as the schema **owner** (`qayd`, used for migrations and seeding, bypasses RLS)
 * and one as the runtime **app** role (`qayd_app`, NON-superuser + NOBYPASSRLS, so RLS actually
 * enforces) — overriding only the database name because phpunit forces DB_DATABASE to `:memory:`.
 *
 * It also remaps the application's own `pgsql` (→ owner) and `pgsql_app` (→ app role) connections to
 * this database so the middleware feature tests exercise the exact same Postgres instance.
 *
 * `seedCompany()` is the canonical company + owner-role + admin-user + active-membership builder;
 * two calls give the two-company context a leak needs somewhere to leak to.
 */
final class TenantHarness
{
    /** Owner connection (superuser/BYPASSRLS): migrations + seeding. */
    public const OWNER = 'pgsql';

    /** App connection (non-superuser, NOBYPASSRLS): all RLS-enforced tenant queries. */
    public const APP = 'pgsql_app';

    private static bool $migrated = false;

    /**
     * Register the owner + app Postgres connections against the real `qayd` database and run
     * migrate:fresh once per process. Call from a test's beforeEach.
     */
    public static function boot(): void
    {
        /** @var array<string, mixed> $base */
        $base = config('database.connections.pgsql');

        $database = $base['database'] ?? null;
        if (! is_string($database) || $database === '' || $database === ':memory:') {
            $database = 'qayd';
        }

        // Owner: the schema owner running migrations + seeds.
        $owner = $base;
        $owner['database'] = $database;

        // App role: same database, non-superuser NOBYPASSRLS credentials.
        $app = $base;
        $app['database'] = $database;
        $app['username'] = env('DB_APP_USERNAME', 'qayd_app');
        // Same source as the migration + the pgsql_app connection (reads DB_APP_PASSWORD); no literal.
        $app['password'] = config('database.connections.pgsql_app.password');

        config([
            'database.connections.'.self::OWNER => $owner,
            'database.connections.'.self::APP => $app,
            'database.default' => self::OWNER,
        ]);

        DB::purge(self::OWNER);
        DB::purge(self::APP);

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--database' => self::OWNER, '--force' => true]);
            self::$migrated = true;
        }
    }

    public static function owner(): ConnectionInterface
    {
        return DB::connection(self::OWNER);
    }

    public static function app(): ConnectionInterface
    {
        return DB::connection(self::APP);
    }

    /**
     * Seed one company with an owner role, an admin user, and an active membership — all on the
     * owner connection (which bypasses RLS). Emails are unique per call so repeated seeds across
     * tests never collide on the citext unique constraint.
     *
     * @return array{company_id:int, company_uuid:string, user_id:int, role_id:int, membership_id:int}
     */
    public static function seedCompany(string $name): array
    {
        $owner = self::owner();

        $company = $owner->selectOne(
            'INSERT INTO companies (legal_name, name_en) VALUES (?, ?) RETURNING id, uuid',
            [$name, $name],
        );

        $companyId = (int) $company->id;
        $companyUuid = (string) $company->uuid;

        $roleId = (int) $owner->selectOne(
            "INSERT INTO roles (company_id, key, name_en, name_ar) VALUES (?, 'owner', 'Owner', 'مالك') RETURNING id",
            [$companyId],
        )->id;

        $userId = (int) $owner->selectOne(
            'INSERT INTO users (email, name) VALUES (?, ?) RETURNING id',
            [uniqid('u', true).'@example.test', $name.' Admin'],
        )->id;

        $membershipId = (int) $owner->selectOne(
            "INSERT INTO company_users (company_id, user_id, role_id, status) VALUES (?, ?, ?, 'active') RETURNING id",
            [$companyId, $userId, $roleId],
        )->id;

        return [
            'company_id' => $companyId,
            'company_uuid' => $companyUuid,
            'user_id' => $userId,
            'role_id' => $roleId,
            'membership_id' => $membershipId,
        ];
    }

    /**
     * Run $fn with an established tenant context for $companyId — the container binding the Eloquent
     * CompanyScope reads AND the transaction-local RLS GUC on the app connection, mirroring
     * ResolveTenantCompany. The work runs inside a rolled-back transaction so tests stay isolated.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function runInTenant(int $companyId, Closure $fn): mixed
    {
        app()->instance(TenantContext::BINDING_COMPANY_ID, $companyId);

        $app = self::app();
        $app->beginTransaction();
        $app->select('SELECT set_config(?, ?, true)', [TenantContext::GUC_COMPANY_ID, (string) $companyId]);

        try {
            return $fn();
        } finally {
            $app->rollBack();
            app()->forgetInstance(TenantContext::BINDING_COMPANY_ID);
        }
    }
}
