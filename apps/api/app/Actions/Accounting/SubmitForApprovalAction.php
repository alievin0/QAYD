<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Exceptions\Accounting\JournalRuleException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Submit a DRAFT (or rejected) journal entry for approval (S2-04): transition `draft`/`rejected` →
 * `pending_approval`. Optimistic concurrency guards the transition (stale version → 409). An entry with
 * no lines cannot be submitted (422). An AI agent may draft but can NEVER submit or post — a human must
 * review first (403); this is the submit-side of the same rule the S2-03 `trg_no_ai_autopost` trigger
 * enforces on creation. No balance/posting is done here — that is the posting engine (S2-05).
 */
final class SubmitForApprovalAction
{
    public function execute(JournalEntry $entry, int $expectedVersion, bool $actorIsAi = false, ?int $actorUserId = null): JournalEntry
    {
        if ($actorIsAi) {
            throw JournalRuleException::aiCannotSubmit();
        }

        if (! in_array($entry->status, JournalEntry::EDITABLE_STATUSES, true)) {
            throw JournalRuleException::notEditable($entry->status);
        }
        if ($entry->version !== $expectedVersion) {
            throw JournalRuleException::versionConflict($expectedVersion);
        }
        if (! JournalLine::query()->where('journal_entry_id', $entry->id)->exists()) {
            throw JournalRuleException::cannotSubmitEmpty();
        }

        DB::connection(TenantContext::connection())->transaction(function () use ($entry, $expectedVersion, $actorUserId): void {
            $affected = JournalEntry::query()
                ->whereKey($entry->id)
                ->where('version', $expectedVersion)
                ->whereIn('status', JournalEntry::EDITABLE_STATUSES)
                ->update([
                    'status' => JournalEntry::STATUS_PENDING_APPROVAL,
                    'version' => $expectedVersion + 1,
                    'updated_by' => $actorUserId,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                $fresh = JournalEntry::query()->find($entry->id);
                if ($fresh === null) {
                    throw JournalRuleException::notEditable('unknown');
                }
                if (! in_array($fresh->status, JournalEntry::EDITABLE_STATUSES, true)) {
                    throw JournalRuleException::notEditable($fresh->status);
                }
                throw JournalRuleException::versionConflict($expectedVersion);
            }
        });

        return $entry->refresh();
    }
}
