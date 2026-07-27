<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * Produces rows matching the QAYD `users` schema (docs/backend/AUTH_SERVICE.md): the password lives in
 * `password_hash` (argon2id), there is no stock `password`/`remember_token` column, and every account
 * carries a `status` and `locale`. `uuid` is left to the database default (`gen_random_uuid()`).
 */
class UserFactory extends Factory
{
    /**
     * The current password hash being reused by the factory (argon2id is memory-hard, so we hash the
     * shared test password once per process rather than per row).
     */
    protected static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password_hash' => static::$passwordHash ??= Hash::make('password'),
            'locale' => 'en',
            'mfa_enrolled' => false,
            'status' => 'active',
            'is_platform_admin' => false,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A platform-admin identity (bypasses tenant membership checks).
     */
    public function platformAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_platform_admin' => true,
        ]);
    }
}
