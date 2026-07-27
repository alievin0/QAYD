<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Custody of the RS256 signing keypair (docs/backend/AUTH_SERVICE.md "# Integrations — RS256 JWT +
 * JWKS", "# Responsibilities — Signing-key custody").
 *
 * The PRIVATE key is a secret loaded from disk (a path under storage/, gitignored) or a secret store;
 * it is NEVER committed. The PUBLIC key is safe to share and is what a verifier uses by `kid`. In
 * local/testing the keypair is auto-generated on first use so the app is runnable out of the box; in
 * every other environment a missing key is a **fail-closed** error — the service refuses to invent key
 * material in production, where `php artisan qayd:jwt-keys` (or the secret store) must provide it.
 *
 * Registered as a singleton so the PEM material is read from disk at most once per process.
 */
final class SigningKeyService
{
    private ?string $privateKey = null;

    private ?string $publicKey = null;

    public function __construct(private readonly Application $app) {}

    /** The RS256 private key PEM used to sign access tokens. */
    public function privateKey(): string
    {
        if ($this->privateKey !== null) {
            return $this->privateKey;
        }

        $this->ensureKeypair();

        $pem = @file_get_contents($this->privatePath());
        if ($pem === false || trim($pem) === '') {
            throw new RuntimeException('JWT signing private key is unreadable.');
        }

        return $this->privateKey = $pem;
    }

    /** The RS256 public key PEM any verifier uses to validate a token by `kid`. */
    public function publicKey(): string
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }

        $this->ensureKeypair();

        $pem = @file_get_contents($this->publicPath());
        if ($pem === false || trim($pem) === '') {
            throw new RuntimeException('JWT signing public key is unreadable.');
        }

        return $this->publicKey = $pem;
    }

    /** The key id embedded in every token header. */
    public function kid(): string
    {
        $kid = config('jwt.kid');

        return is_string($kid) && $kid !== '' ? $kid : 'qayd-default';
    }

    /**
     * Generate a fresh 2048-bit RSA keypair and persist it to the configured paths (private key mode
     * 0600). Ops entrypoint for `qayd:jwt-keys`; also the lazy path in local/testing.
     */
    public function generate(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new RuntimeException('Unable to generate an RSA keypair (openssl_pkey_new failed).');
        }

        $privatePem = '';
        if (! openssl_pkey_export($resource, $privatePem)) {
            throw new RuntimeException('Unable to export the RSA private key.');
        }
        if (! is_string($privatePem) || $privatePem === '') {
            throw new RuntimeException('The exported RSA private key is empty.');
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || ! isset($details['key']) || ! is_string($details['key'])) {
            throw new RuntimeException('Unable to read the RSA public key.');
        }
        $publicPem = $details['key'];

        $this->writeKey($this->privatePath(), $privatePem, 0600);
        $this->writeKey($this->publicPath(), $publicPem, 0644);

        $this->privateKey = $privatePem;
        $this->publicKey = $publicPem;
    }

    /**
     * Ensure both key files exist. In local/testing generate them on demand; elsewhere a missing key
     * is fatal (fail closed) — production key material must be provisioned out of band.
     */
    private function ensureKeypair(): void
    {
        if (is_file($this->privatePath()) && is_file($this->publicPath())) {
            return;
        }

        if (! $this->app->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'JWT signing keypair is missing. Provision it with `php artisan qayd:jwt-keys` or your secret store.'
            );
        }

        $this->generate();
    }

    private function writeKey(string $path, string $contents, int $mode): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create key directory: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write key file: {$path}");
        }

        @chmod($path, $mode);
    }

    private function privatePath(): string
    {
        $path = config('jwt.private_key_path');

        return is_string($path) && $path !== '' ? $path : storage_path('app/keys/jwt/private.pem');
    }

    private function publicPath(): string
    {
        $path = config('jwt.public_key_path');

        return is_string($path) && $path !== '' ? $path : storage_path('app/keys/jwt/public.pem');
    }
}
