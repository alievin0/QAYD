<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * The nightly ledger integrity check (SPRINT_02 §S2-14) — the project's first scheduled task.
 *
 * Runs at 02:00 in the application timezone, when the books are quiet: the rebuild reads the posted
 * journals and the ledger in one transaction, and comparing them while entries are being posted would
 * mean chasing a moving target and reporting drift that is only concurrency.
 *
 * The command queues one job per company and returns immediately, so nothing here holds the scheduler
 * open. `withoutOverlapping` guards the case that actually happens — a night when the queue is backed
 * up and the previous run has not finished dispatching.
 */
Schedule::command('accounting:verify-ledger-integrity')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Rebuild every company ledger from its posted journals and report any drift');
