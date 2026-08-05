<?php

declare(strict_types=1);

return [

    'trial_balance' => [

        /*
         * Above this many accounts, a trial balance is generated on the `reports` queue and the API
         * answers 202 instead of blocking the request (SPRINT_02 §S2-09).
         *
         * The threshold is on ACCOUNTS rather than ledger rows because the aggregate's cost scales
         * with the group count, not the row count — a million lines across forty accounts is fast; the
         * same lines across forty thousand accounts is not. Cheap to measure before committing to a
         * synchronous run: one indexed COUNT(DISTINCT account_id).
         */
        'async_account_threshold' => (int) env('TRIAL_BALANCE_ASYNC_THRESHOLD', 500),

        /*
         * The queue long generations are handed to. Named per the sprint specification so report work
         * never competes with latency-sensitive jobs on the default queue.
         */
        'queue' => env('TRIAL_BALANCE_QUEUE', 'reports'),

        /*
         * Absolute variance a trial balance may carry and still be considered balanced — half a fils.
         * It exists for multi-currency rounding (TRIAL_BALANCE.md), not as tolerance for a real
         * imbalance, and the variance is stored either way.
         */
        'rounding_tolerance' => env('TRIAL_BALANCE_ROUNDING_TOLERANCE', '0.0050'),
    ],

    'integrity' => [

        /*
         * The queue the nightly ledger rebuild runs on (SPRINT_02 §S2-14).
         *
         * Its own queue rather than `reports`, because the two fail differently: a slow trial balance
         * keeps someone waiting at a screen, a slow integrity rebuild keeps nobody waiting at all, and
         * neither should be able to starve the other of workers.
         */
        'queue' => env('LEDGER_INTEGRITY_QUEUE', 'maintenance'),
    ],
];
