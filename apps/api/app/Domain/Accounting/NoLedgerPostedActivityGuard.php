<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\Account;
use App\Providers\AppServiceProvider;

/**
 * The S2-01 implementation of {@see PostedActivityGuard}: until the ledger exists (the posted
 * `journal_lines` / `ledger_entries` land in S2-03 / S2-05) no account can carry posted activity, so
 * this returns false for every account — the truthful answer for the current schema, not a stub. When
 * the posting engine lands, a ledger-backed implementation replaces this binding in
 * {@see AppServiceProvider}.
 */
final class NoLedgerPostedActivityGuard implements PostedActivityGuard
{
    public function hasPostedLines(Account $account): bool
    {
        return false;
    }
}
