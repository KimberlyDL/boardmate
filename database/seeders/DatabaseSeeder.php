<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Only the platform admin is seeded. Every other account is created
     * through the app (sign-up, apply as owner, caretaker invitation).
     */
    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);
    }
}
