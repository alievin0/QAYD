<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\SigningKeyService;
use Illuminate\Console\Command;

/**
 * `php artisan qayd:jwt-keys` — provision the RS256 signing keypair used to sign bearer JWTs
 * (docs/backend/AUTH_SERVICE.md "# Integrations — RS256 JWT"). The private key is written to the
 * configured path (gitignored) with 0600 permissions; the public key is safe to publish.
 *
 * The ops entrypoint for non-local environments, where {@see SigningKeyService} refuses to invent key
 * material implicitly (fail closed). Use `--force` to overwrite an existing keypair — which rotates the
 * signing key and immediately invalidates every token signed by the old one.
 */
final class GenerateJwtKeys extends Command
{
    protected $signature = 'qayd:jwt-keys {--force : Overwrite an existing keypair (rotates the signing key)}';

    protected $description = 'Generate the RS256 JWT signing keypair (private key is never committed).';

    public function handle(SigningKeyService $keys): int
    {
        $privatePath = config('jwt.private_key_path');
        $privatePath = is_string($privatePath) ? $privatePath : '';

        if ($privatePath !== '' && is_file($privatePath) && ! $this->option('force')) {
            $this->warn('A signing keypair already exists. Re-run with --force to rotate it.');

            return self::FAILURE;
        }

        $keys->generate();

        $this->info('RS256 JWT signing keypair generated.');
        $this->line('  private: '.$privatePath.'  (gitignored — keep secret)');
        $publicPath = config('jwt.public_key_path');
        $this->line('  public:  '.(is_string($publicPath) ? $publicPath : ''));

        return self::SUCCESS;
    }
}
