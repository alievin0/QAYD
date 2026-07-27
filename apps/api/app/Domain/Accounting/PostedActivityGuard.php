<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\Account;

/**
 * Answers "does this account already carry posted ledger activity?" — the fact the chart-of-accounts
 * guards depend on: a posted account may not be silently renumbered or reclassified
 * (docs/accounting/CHART_OF_ACCOUNTS.md). It is expressed as a seam because its data source — the
 * posted `journal_lines` / `ledger_entries` — is built in later Sprint-2 stories (S2-03 / S2-05). S2-01
 * binds the honest current answer ({@see NoLedgerPostedActivityGuard}); the posting stories swap in the
 * ledger-backed implementation without touching the Actions.
 */
interface PostedActivityGuard
{
    public function hasPostedLines(Account $account): bool;
}
