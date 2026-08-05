<?php

declare(strict_types=1);

/**
 * Broadcasting (SPRINT_02 §S2-13).
 *
 * QAYD broadcasts exactly one kind of message: a **refresh notification**. A push tells an open screen
 * that authoritative state changed and that it should re-read the API; it is never a write path and
 * never a source of truth (ADR-0006, FINAL_TECH_STACK "Realtime"). Nothing downstream may treat a
 * payload here as a fact — the database remains the sole writer, and the API the sole reader.
 *
 * The default is `log`, not `reverb`. A developer running the API without a WebSocket server, and CI
 * running the suite without one either, must not have posting fail because a socket is unreachable:
 * committing a journal entry cannot depend on the availability of a hint to refresh a screen.
 * Production sets `BROADCAST_CONNECTION=reverb`.
 */
return [

    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // A timeout belongs here rather than at the default: a slow socket server must not hold
                // a queue worker open indefinitely behind a message nobody is waiting on.
                'timeout' => 10,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
