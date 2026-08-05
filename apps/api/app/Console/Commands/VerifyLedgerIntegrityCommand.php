<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Accounting\VerifyLedgerIntegrityJob;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Queue the nightly ledger integrity check (SPRINT_02 §S2-14).
 *
 * The command's whole job is to decide *which* companies to check and to hand each one to its own job.
 * It deliberately verifies nothing itself: the check has to run inside a per-company tenant context,
 * and a command that looped over tenants in one process would be one missed `SET LOCAL` away from
 * comparing one company's journals against another's ledger.
 *
 * Companies are enumerated on the default connection because `companies` is the tenant root and carries
 * no RLS of its own — at this point there is no company context yet, by definition.
 */
final class VerifyLedgerIntegrityCommand extends Command
{
    protected $signature = 'accounting:verify-ledger-integrity {--company= : Verify a single company by internal id}';

    protected $description = 'Rebuild each company ledger from its posted journals and report any drift';

    public function handle(): int
    {
        $query = Company::query()
            ->where('status', '!=', 'archived')
            ->whereNull('deleted_at')
            ->orderBy('id');

        $only = $this->option('company');
        if (is_string($only) && $only !== '') {
            $query->whereKey((int) $only);
        }

        /** @var list<int> $companyIds */
        $companyIds = [];
        foreach ($query->pluck('id') as $id) {
            if (is_numeric($id)) {
                $companyIds[] = (int) $id;
            }
        }

        foreach ($companyIds as $companyId) {
            VerifyLedgerIntegrityJob::dispatch($companyId);
        }

        $this->info(sprintf(
            'Queued ledger integrity checks for %d compan%s.',
            count($companyIds),
            count($companyIds) === 1 ? 'y' : 'ies',
        ));

        return self::SUCCESS;
    }
}
