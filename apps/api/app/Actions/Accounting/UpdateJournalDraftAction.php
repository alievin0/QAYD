<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Actions\Accounting\Concerns\WritesJournalDraft;
use App\Data\Accounting\JournalEntryData;
use App\Exceptions\Accounting\JournalRuleException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Edit a DRAFT (or rejected) journal entry and replace its lines (S2-04). Only `draft`/`rejected` entries
 * are editable — the application-layer half of immutability (the S2-03 trigger independently blocks any
 * line write once the parent is terminal). Optimistic concurrency: the caller passes the `version` it read;
 * a stale version (or a status that has since left draft/rejected) yields a 409 and nothing is written.
 * Header totals are left balanced at zero (the posting engine sets them); lines are wholly replaced.
 */
final class UpdateJournalDraftAction
{
    use WritesJournalDraft;

    public function execute(JournalEntry $entry, JournalEntryData $data, int $expectedVersion, ?int $actorUserId = null): JournalEntry
    {
        $this->assertEditable($entry, $expectedVersion);
        $this->assertValid($data);

        DB::connection(TenantContext::connection())->transaction(function () use ($entry, $data, $expectedVersion, $actorUserId): void {
            // Version-guarded header update — the optimistic-concurrency gate. A concurrent edit, or a
            // status that has left draft/rejected since the caller read it, makes this affect zero rows.
            $affected = JournalEntry::query()
                ->whereKey($entry->id)
                ->where('version', $expectedVersion)
                ->whereIn('status', JournalEntry::EDITABLE_STATUSES)
                ->update([
                    'journal_date' => $data->journalDate,
                    'entry_type' => $data->entryType,
                    'currency_code' => strtoupper($data->currencyCode),
                    'exchange_rate' => $data->exchangeRate,
                    'reference' => $data->reference,
                    'memo' => $data->memo,
                    'version' => $expectedVersion + 1,
                    'updated_by' => $actorUserId,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                $this->failStale($entry->id, $expectedVersion);
            }

            // Replace the lines (the S2-03 trigger permits delete/insert while the parent is draft/rejected).
            JournalLine::query()->where('journal_entry_id', $entry->id)->delete();
            $this->insertLines($entry->id, $data, $actorUserId);
        });

        return $entry->refresh();
    }

    /** Fast, clean pre-check before the guarded write: a non-editable status or an obviously-stale version. */
    private function assertEditable(JournalEntry $entry, int $expectedVersion): void
    {
        if (! in_array($entry->status, JournalEntry::EDITABLE_STATUSES, true)) {
            throw JournalRuleException::notEditable($entry->status);
        }
        if ($entry->version !== $expectedVersion) {
            throw JournalRuleException::versionConflict($expectedVersion);
        }
    }

    /** The guarded update matched no row — reload to report precisely why (not editable vs. stale version). */
    private function failStale(int $entryId, int $expectedVersion): never
    {
        $fresh = JournalEntry::query()->find($entryId);
        if ($fresh === null) {
            throw JournalRuleException::notEditable('unknown');
        }
        if (! in_array($fresh->status, JournalEntry::EDITABLE_STATUSES, true)) {
            throw JournalRuleException::notEditable($fresh->status);
        }
        throw JournalRuleException::versionConflict($expectedVersion);
    }
}
