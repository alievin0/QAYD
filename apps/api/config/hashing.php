<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Password Hashing (S1-07, docs/backend/AUTH_SERVICE.md)
|--------------------------------------------------------------------------
|
| QAYD hashes passwords with **argon2id** — the memory-hard algorithm AUTH_SERVICE.md mandates for
| `users.password_hash` and the security-verification bar requires as "the configured hashing driver
| in every non-test environment". This file makes argon2id the default driver (Laravel ships bcrypt
| by default and ignores HASH_DRIVER unless this config is published), so `Hash::make()` produces
| argon2id everywhere. `Hash::check()` auto-detects the algorithm from the stored hash, so verification
| keeps working across any driver.
|
*/

return [

    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => null,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

    'rehash_on_login' => true,

];
