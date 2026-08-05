<?php

declare(strict_types=1);

namespace App\Actions\Accounting\Concerns;

use App\Actions\Accounting\CreateJournalEntryAction;
use App\Actions\Accounting\UpdateJournalDraftAction;
use App\Data\Accounting\JournalEntryData;
use App\Exceptions\Accounting\JournalRuleException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;

/**
 * Shared draft validation + line-writing for {@see CreateJournalEntryAction} and
 * {@see UpdateJournalDraftAction}. Kept in the Action layer (a trait the Actions
 * compose) so the business rules never leak into the thin models. Money is compared/derived with bcmath
 * string arithmetic at scale 4 — never float — matching the `NUMERIC(19,4)` columns.
 */
trait WritesJournalDraft
{
    /**
     * Validate the header + every line of a draft: a known entry type, an ai_confidence in [0,1] when the
     * entry is AI-generated, and each line one-sided (exactly one of debit/credit > 0, both ≥ 0) with an
     * account that exists in the active company. These mirror the S2-03 database CHECKs so the caller gets
     * a clean 422 instead of a raw constraint violation; the database remains the final backstop.
     */
    private function assertValid(JournalEntryData $data): void
    {
        if (! in_array($data->entryType, JournalEntry::ENTRY_TYPES, true)) {
            throw JournalRuleException::invalidEntryType($data->entryType);
        }

        if ($data->aiGenerated && ($data->aiConfidence === null || $data->aiConfidence < 0 || $data->aiConfidence > 1)) {
            throw JournalRuleException::aiConfidenceRequired();
        }

        $lineNumber = 0;
        foreach ($data->lines as $line) {
            $lineNumber++;

            $debitSign = bccomp($line->debit, '0', 4);   // -1 | 0 | 1
            $creditSign = bccomp($line->credit, '0', 4);

            // One-sided: both non-negative, and exactly one strictly positive.
            if ($debitSign < 0 || $creditSign < 0 || ($debitSign > 0) === ($creditSign > 0)) {
                throw JournalRuleException::invalidLine($lineNumber);
            }

            if (! Account::query()->whereKey($line->accountId)->exists()) {
                throw JournalRuleException::invalidAccount($line->accountId);
            }
        }
    }

    /**
     * Insert the draft's lines for $entryId, numbered 1..n in order, with base amounts derived at the
     * entry's exchange rate. Each line is written through {@see JournalLine} (BelongsToCompany), so it is
     * stamped with the active company and lands on the RLS-enforced connection.
     */
    private function insertLines(int $entryId, JournalEntryData $data, ?int $actorUserId): void
    {
        $currency = strtoupper($data->currencyCode);

        $lineNumber = 0;
        foreach ($data->lines as $line) {
            $lineNumber++;

            $model = new JournalLine;
            $model->forceFill([
                'journal_entry_id' => $entryId,
                'line_number' => $lineNumber,
                'account_id' => $line->accountId,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'currency_code' => $currency,
                'exchange_rate' => $data->exchangeRate,
                'base_debit' => bcmul($line->debit, $data->exchangeRate, 4),
                'base_credit' => bcmul($line->credit, $data->exchangeRate, 4),
                'created_by' => $actorUserId,
                'updated_by' => $actorUserId,
            ]);
            $model->save();
        }
    }
}
