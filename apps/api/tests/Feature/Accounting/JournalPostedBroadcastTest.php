<?php

declare(strict_types=1);

use App\Broadcasting\CompanyChannel;
use App\Events\Accounting\JournalEntryPosted;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Tests\Support\TenantHarness;

/**
 * The posted-entry broadcast (SPRINT_02 §S2-13).
 *
 * Two things are worth proving, and they are not the same thing. The first is the tenant boundary: a
 * socket subscription reaches tenant data without passing RLS or `ResolveTenantCompany`, so
 * `CompanyChannel` is the only guard on that path, and every way of not being a member has to be
 * refused. The second is that the message stays a *hint* — it names the entry, never carries the
 * ledger, and does not leak the internal company id it was built from.
 *
 * What is NOT asserted is that a socket delivers anything. That needs a running WebSocket server, which
 * CI has no business standing up for a refresh notification; the broadcaster is Laravel's and its
 * transport is not ours to re-test.
 */
beforeEach(function (): void {
    TenantHarness::boot();
});

function jpbUser(int $id): User
{
    $user = new User;
    $user->forceFill(['id' => $id]);
    $user->exists = true;

    return $user;
}

describe('channel authorization', function (): void {
    it('lets an active member subscribe to their own company', function (): void {
        $seed = TenantHarness::seedCompany('Broadcast Member Co');

        expect((new CompanyChannel)->join(jpbUser($seed['user_id']), $seed['company_uuid']))
            ->toBeTrue();
    });

    it('refuses a member of a different company', function (): void {
        $mine = TenantHarness::seedCompany('Broadcast Mine Co');
        $theirs = TenantHarness::seedCompany('Broadcast Theirs Co');

        // The one assertion the whole channel exists for: a real, authenticated user of one tenant must
        // not be able to listen to another's feed by naming its uuid.
        expect((new CompanyChannel)->join(jpbUser($mine['user_id']), $theirs['company_uuid']))
            ->toBeFalse();
    });

    it('refuses an unknown company uuid', function (): void {
        $seed = TenantHarness::seedCompany('Broadcast Unknown Co');

        expect((new CompanyChannel)->join(
            jpbUser($seed['user_id']),
            '00000000-0000-4000-8000-000000000000',
        ))->toBeFalse();
    });

    it('refuses a membership that is no longer active', function (): void {
        $seed = TenantHarness::seedCompany('Broadcast Suspended Co');

        TenantHarness::owner()->update(
            "UPDATE company_users SET status = 'suspended' WHERE id = ?",
            [$seed['membership_id']],
        );

        expect((new CompanyChannel)->join(jpbUser($seed['user_id']), $seed['company_uuid']))
            ->toBeFalse();
    });

    it('refuses a membership that was soft-deleted', function (): void {
        $seed = TenantHarness::seedCompany('Broadcast Removed Co');

        TenantHarness::owner()->update(
            'UPDATE company_users SET deleted_at = now() WHERE id = ?',
            [$seed['membership_id']],
        );

        expect((new CompanyChannel)->join(jpbUser($seed['user_id']), $seed['company_uuid']))
            ->toBeFalse();
    });

    it('refuses a company that was archived', function (): void {
        $seed = TenantHarness::seedCompany('Broadcast Archived Co');

        TenantHarness::owner()->update(
            "UPDATE companies SET status = 'archived' WHERE id = ?",
            [$seed['company_id']],
        );

        expect((new CompanyChannel)->join(jpbUser($seed['user_id']), $seed['company_uuid']))
            ->toBeFalse();
    });
});

describe('the broadcast itself', function (): void {
    it('is broadcastable', function (): void {
        expect(new JournalEntryPosted(1, 2, 'JE-1', 'manual', '10.0000', 'KWD'))
            ->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('goes to the company private channel, named by uuid', function (): void {
        $seed = TenantHarness::seedCompany('Broadcast Channel Co');

        $channels = (new JournalEntryPosted(
            companyId: $seed['company_id'],
            journalEntryId: 7,
            journalNumber: 'JE-FY2026-000001',
            entryType: 'manual',
            baseTotal: '250.0000',
            currencyCode: 'KWD',
        ))->broadcastOn();

        expect($channels)->toHaveCount(1)
            ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
            ->and((string) $channels[0])->toBe('private-company.'.$seed['company_uuid']);
    });

    it('broadcasts nothing when the company cannot be resolved', function (): void {
        // The posting is already committed; there is simply no one to tell.
        expect((new JournalEntryPosted(2_147_483_000, 7, 'JE-1', 'manual', '1.0000', 'KWD'))
            ->broadcastOn())->toBe([]);
    });

    it('travels under the short wire name', function (): void {
        expect((new JournalEntryPosted(1, 7, 'JE-1', 'manual', '1.0000', 'KWD'))->broadcastAs())
            ->toBe('journal.posted')
            ->and(JournalEntryPosted::NAME)->toBe('accounting.journal.posted');
    });

    it('carries a compact projection and never the internal company id', function (): void {
        $payload = (new JournalEntryPosted(
            companyId: 42,
            journalEntryId: 7,
            journalNumber: 'JE-FY2026-000001',
            entryType: 'manual',
            baseTotal: '250.0000',
            currencyCode: 'KWD',
        ))->broadcastWith();

        expect($payload)->toBe([
            'journal_entry_id' => 7,
            'journal_number' => 'JE-FY2026-000001',
            'entry_type' => 'manual',
            'base_total' => '250.0000',
            'currency_code' => 'KWD',
        ]);

        // The channel already says which company this is, and internal ids are not given to clients.
        expect($payload)->not->toHaveKey('company_id');
    });

    it('keeps money a string, as everywhere else', function (): void {
        $payload = (new JournalEntryPosted(1, 7, 'JE-1', 'manual', '1234.5600', 'KWD'))
            ->broadcastWith();

        expect($payload['base_total'])->toBeString()->toBe('1234.5600');
    });
});
