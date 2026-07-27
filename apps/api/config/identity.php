<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Resolved-permission cache
    |--------------------------------------------------------------------------
    |
    | The PermissionResolver (S1-09) caches the effective permission set for a
    | (company_user_id, perms_ver) pair. In every deployed environment this is
    | Redis (docs/backend/AUTH_SERVICE.md "# Key Classes — PermissionResolver");
    | the key embeds perms_ver so a role/grant change is picked up on the next
    | request simply by keying to a version that was never cached. The test
    | suite overrides the store to `array` (phpunit.xml) for determinism.
    |
    | `store` is a cache store name from config/cache.php; `ttl` is in seconds.
    |
    */
    'perms_cache' => [
        'store' => env('PERMS_CACHE_STORE', 'redis'),
        'ttl' => (int) env('PERMS_CACHE_TTL', 1800),
    ],
];
