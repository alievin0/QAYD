<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The fixed RBAC catalogue: permissions + system default roles (S1-09). Idempotent.
        $this->call(RbacSeeder::class);

        // A demo identity. firstOrCreate keeps `db:seed` safe to re-run (citext email is unique).
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User'],
        );
    }
}
