<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Actions\Accounting\Concerns\WritesJournalDraft;
use App\Data\Accounting\JournalEntryData;
use App\Models\JournalEntry;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Create a journal entry as a DRAFT (S2-04, docs/accounting/JOURNAL_ENTRIES.md). Runs inside an
 * established tenant context: the header + lines are written through their BelongsToCompany models (RLS +
 * CompanyScope + `pgsql_app`), so the entry can only ever land in the caller's own company.
 *
 * Invariants honored: the entry is ALWAYS created as `draft` — never `posted`/etc. — even when
 * AI-generated (the S2-03 `trg_no_ai_autopost` trigger is the database backstop); the cached header
 * totals stay balanced at zero (the unconditional `chk_je_balanced`), to be set from the balanced lines by
 * the posting engine (S2-05). The PERMANENT `journal_number` is likewise assigned at posting; a draft
 * carries a provisional `DRAFT-{id}`. All writes are one transaction, so a bad line rolls the header back.
 */
final class CreateJournalEntryAction
{
    use WritesJournalDraft;

    public function execute(JournalEntryData $data, ?int $actorUserId = null): JournalEntry
    {
        $this->assertValid($data);

        $entry = new JournalEntry;
        $entry->forceFill([
            // A transient, globally-unique number satisfies uq_je_number for the INSERT; it is immediately
            // rewritten to the provisional DRAFT-{id} once the identity is assigned. The permanent number
            // is assigned by the posting engine (S2-05).
            'journal_number' => 'TMP-'.bin2hex(random_bytes(12)),
            'journal_date' => $data->journalDate,
            'entry_type' => $data->entryType,
            'currency_code' => strtoupper($data->currencyCode),
            'exchange_rate' => $data->exchangeRate,
            // Cached header totals stay balanced at zero throughout the draft lifecycle (never synced to a
            // possibly-unbalanced draft line-sum); the posting engine sets them from the balanced lines.
            'total_debit' => '0',
            'total_credit' => '0',
            'base_total_debit' => '0',
            'base_total_credit' => '0',
            'status' => JournalEntry::STATUS_DRAFT,
            'ai_generated' => $data->aiGenerated,
            'ai_confidence' => $data->aiConfidence,
            'version' => 1,
            'reference' => $data->reference,
            'memo' => $data->memo,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);

        DB::connection(TenantContext::connection())->transaction(function () use ($entry, $data, $actorUserId): void {
            $entry->save();
            $entry->forceFill(['journal_number' => 'DRAFT-'.$entry->id])->save();
            $this->insertLines($entry->id, $data, $actorUserId);
        });

        return $entry->refresh();
    }
}
