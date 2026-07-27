<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RS256 JWT credentials (S1-08, docs/backend/AUTH_SERVICE.md)
|--------------------------------------------------------------------------
|
| Bearer clients (Flutter mobile, partner integrations, the FastAPI AI engine) authenticate with an
| RS256-signed JWT access token plus an opaque, rotating refresh token. This config names the signing
| keypair, the algorithm, the standard claims (iss/aud), and the two token lifetimes.
|
| The PRIVATE key is a secret: it lives on disk under storage/ (gitignored) or in a secret store, and
| is loaded from `private_key_path` at runtime — it is NEVER committed. The PUBLIC key is safe to
| publish (a future JWKS endpoint serves it by `kid`) and is generated alongside the private key by
| `php artisan qayd:jwt-keys`. See AUTH_SERVICE.md "# Integrations — RS256 JWT + JWKS".
|
*/

return [
    // Only asymmetric RS256 is accepted; the verifier allow-lists this single algorithm so a token
    // presenting `alg: none` or a symmetric algorithm can never be accepted (algorithm-confusion
    // defense, docs/security/AUTHENTICATION.md).
    'algorithm' => 'RS256',

    // Key id embedded in every token header, so a verifier can select the right public key by `kid`.
    'kid' => env('JWT_KID', 'qayd-default'),

    // Absolute paths to the PEM keypair. The private key is gitignored and loaded only server-side.
    'private_key_path' => env('JWT_PRIVATE_KEY_PATH', storage_path('app/keys/jwt/private.pem')),
    'public_key_path' => env('JWT_PUBLIC_KEY_PATH', storage_path('app/keys/jwt/public.pem')),

    // Standard registered claims.
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),
    'audience' => env('JWT_AUDIENCE', 'qayd-api'),

    // Clock-skew tolerance (seconds) applied to iat/nbf/exp validation.
    'leeway' => (int) env('JWT_LEEWAY', 10),

    // Access token lifetime in seconds (default 15 minutes, per AUTH_SERVICE.md).
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),

    // Opaque refresh token lifetime in seconds (default 30 days sliding, per AUTH_SERVICE.md).
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 2592000),
];
