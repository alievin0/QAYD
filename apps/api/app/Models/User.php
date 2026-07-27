<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * The global identity principal (docs/backend/AUTH_SERVICE.md "Database Tables Owned"). One human, one
 * row, many company memberships — NOT company-scoped. Runs on the default (owner) connection, the
 * "privileged auth lookups" path per config/tenancy.php; it is deliberately never bound to the
 * RLS-enforced tenant connection.
 *
 * The password column is `password_hash` (argon2id), not the framework's stock `password`, so
 * {@see getAuthPassword()} / {@see getAuthPasswordName()} are overridden to point Laravel's auth
 * plumbing at it. There is no `remember_token` column: "remember me" persistence is out of S1-08
 * scope, and the login flow never requests a remember cookie.
 *
 * @property int $id
 * @property string $uuid
 * @property string $email
 * @property string $name
 * @property string|null $password_hash
 * @property string $locale
 * @property bool $mfa_enrolled
 * @property string $status
 * @property bool $is_platform_admin
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password_hash',
        'locale',
        'mfa_enrolled',
        'status',
        'is_platform_admin',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization. The password hash never leaves the
     * server; nothing in this module ever serialises a User directly to a client (Resources project a
     * safe subset), but the guard is kept as defense in depth.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'mfa_enrolled' => 'boolean',
            'is_platform_admin' => 'boolean',
        ];
    }

    /**
     * The column that holds the hashed password — `password_hash`, not the framework default
     * `password`. Used by Laravel's credential plumbing.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * The hashed password for this identity (argon2id), or an empty string for SSO-only accounts.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /**
     * Whether this account has verified its email address — the gate on creating a company (S1-07).
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }
}
