<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\Accounting\ReversalRuleException;
use App\Models\JournalEntry;
use App\Support\SqlRow;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Void an UNPOSTED journal entry (S2-06, docs/accounting/JOURNAL_ENTRIES.md "# Journal Entry Lifecycle").
 *
 * Voiding is the disposal route for an entry that never took financial effect — a draft, or a rejected
 * draft that will not be corrected. It is deliberately NOT available for a posted entry: posted history
 * is immutable and the only correction is a reversing entry, so a void attempt on a terminal record is
 * refused with `409 IMMUTABLE_RECORD` whose message names `reverse` as the remedy
 * ({@see ReverseJournalEntryAction}).
 *
 * Because a voided entry never posted, it has no `ledger_entries` rows and never consumed a permanent
 * `journal_number`; its lines are left untouched. The void is a status transition and nothing more.
 */
final class VoidJournalEntryAction
{
    /** The only statuses that may be voided: an entry that has not taken financial effect. */
    private const VOIDABLE_STATUSES = [JournalEntry::STATUS_DRAFT, JournalEntry::STATUS_REJECTED];

    public function execute(JournalEntry $entry, ?int $actorUserId = null): JournalEntry
    {
        $connection = DB::connection(TenantContext::connection());

        $connection->transaction(function () use ($entry, $actorUserId, $connection): void {
            $locked = $connection->selectOne(
                'SELECT status FROM journal_entries WHERE id = ? FOR UPDATE',
                [$entry->id],
            );

            if ($locked === null) {
                throw ReversalRuleException::immutableRecord('unknown');
            }

            $status = SqlRow::string($locked, 'status');

            if (! in_array($status, self::VOIDABLE_STATUSES, true)) {
                // Covers posted/reversed/voided/archived and the in-flight approval states alike: an
                // entry that is not an unposted draft cannot simply be discarded.
                throw ReversalRuleException::immutableRecord($status);
            }

            JournalEntry::query()->whereKey($entry->id)->update([
                'status' => JournalEntry::STATUS_VOIDED,
                'updated_by' => $actorUserId,
                'updated_at' => now(),
            ]);
        });

        return $entry->refresh();
    }
}
