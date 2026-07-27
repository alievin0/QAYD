<?php

declare(strict_types=1);

use App\Enums\AuditCategory;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\RequestId;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Context;
use Tests\Support\TenantHarness;

/**
 * S1-16 (d) — the append-only audit-log write path.
 *
 * Needs real PostgreSQL (RLS + the append-only trigger), so it boots the S1-06 two-connection harness
 * (owner = superuser/bypass; app = the NON-superuser RLS-enforced `pgsql_app` role).
 */
uses()->group('audit', 'isolation');

beforeEach(function (): void {
    TenantHarness::boot();
});

it('writes an audit row through AuditLogger::record within tenant context', function (): void {
    $co = TenantHarness::seedCompany('Audit Co');

    // Establish tenant context the way the middleware would: pin the resolved company for the
    // BelongsToCompany trait/scope, and SET the RLS GUCs on the app connection AuditLog writes through.
    app()->instance(TenantContext::BINDING_COMPANY_ID, $co['company_id']);

    $requestId = '99999999-8888-4777-8666-555544443333';
    Context::add(RequestId::CONTEXT_KEY, $requestId);

    $app = TenantHarness::app();
    $app->beginTransaction();
    $app->select('SELECT set_config(?, ?, true)', [TenantContext::GUC_COMPANY_ID, (string) $co['company_id']]);
    $app->select('SELECT set_config(?, ?, true)', [TenantContext::GUC_USER_ID, (string) $co['user_id']]);

    $log = AuditLogger::record(
        action: 'user.login_success',
        category: AuditCategory::Auth,
        actorUserId: $co['user_id'],
        newValues: ['result' => 'success', 'ip' => '203.0.113.4'],
    );

    // Read the persisted row back on the same RLS-enforced connection + transaction.
    $row = $app->selectOne(
        'SELECT company_id, category, action, actor_user_id, request_id, changed_fields
         FROM audit_logs WHERE id = ?',
        [$log->id],
    );

    // And read it back through the RLS-scoped Eloquent model, exercising the text[] + enum casts.
    $model = AuditLog::query()->findOrFail($log->id);

    $app->commit();

    expect($log->exists)->toBeTrue();
    expect($row)->not->toBeNull();
    expect((int) $row->company_id)->toBe($co['company_id']);
    expect($row->category)->toBe('auth');
    expect($row->action)->toBe('user.login_success');
    expect((int) $row->actor_user_id)->toBe($co['user_id']);
    expect($row->request_id)->toBe($requestId);
    // changed_fields is the denormalized key list of new_values, stored as a Postgres text[]
    // (Postgres normalises the literal to its canonical unquoted form for simple identifiers).
    expect($row->changed_fields)->toBe('{result,ip}');
    expect($model->changed_fields)->toBe(['result', 'ip']);
    expect($model->category)->toBe(AuditCategory::Auth);
});

it('rejects UPDATE and DELETE on audit_logs — the row is append-only', function (): void {
    $co = TenantHarness::seedCompany('Append Only Co');

    // Insert directly on the OWNER connection. The owner is a superuser (bypasses RLS), yet the
    // BEFORE UPDATE/DELETE trigger still fires for it — proving history cannot be rewritten even by a
    // privileged connection.
    $owner = TenantHarness::owner();
    $id = (int) $owner->selectOne(
        "INSERT INTO audit_logs (company_id, category, action) VALUES (?, 'system', 'system.test') RETURNING id",
        [$co['company_id']],
    )->id;

    $threwUpdate = false;
    try {
        $owner->update('UPDATE audit_logs SET action = ? WHERE id = ?', ['tampered', $id]);
    } catch (Throwable $e) {
        $threwUpdate = true;
    }

    $threwDelete = false;
    try {
        $owner->delete('DELETE FROM audit_logs WHERE id = ?', [$id]);
    } catch (Throwable $e) {
        $threwDelete = true;
    }

    expect($threwUpdate)->toBeTrue('UPDATE on audit_logs must be rejected by the append-only trigger');
    expect($threwDelete)->toBeTrue('DELETE on audit_logs must be rejected by the append-only trigger');

    // The row is untouched and still present.
    $still = $owner->selectOne('SELECT count(*) AS c, max(action) AS action FROM audit_logs WHERE id = ?', [$id]);
    expect((int) $still->c)->toBe(1);
    expect($still->action)->toBe('system.test');
});

it('refuses to update an AuditLog model instance (insert-only at the model layer)', function (): void {
    $co = TenantHarness::seedCompany('Model Guard Co');

    app()->instance(TenantContext::BINDING_COMPANY_ID, $co['company_id']);

    $app = TenantHarness::app();
    $app->beginTransaction();
    $app->select('SELECT set_config(?, ?, true)', [TenantContext::GUC_COMPANY_ID, (string) $co['company_id']]);

    $log = AuditLogger::record(action: 'system.model_guard', category: AuditCategory::System);

    $threw = false;
    try {
        $log->update(['action' => 'mutated']);
    } catch (LogicException $e) {
        $threw = true;
    }

    $app->rollBack();

    expect($threw)->toBeTrue('the AuditLog model must reject updates');
});
