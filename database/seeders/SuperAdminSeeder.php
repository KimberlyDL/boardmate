<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Creates (or updates) the platform admin from ADMIN_EMAIL / ADMIN_PASSWORD in
 * .env. Safe to run more than once.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('boardmate.admin');

        if (blank($config['email']) || blank($config['password'])) {
            throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env before seeding the platform admin.');
        }

        $admin = User::withTrashed()->firstOrNew(['email' => strtolower($config['email'])]);
        $admin->fill([
            'name' => $config['name'],
            'password' => $config['password'],
            'active_role' => UserRole::PlatformAdmin->value,
        ]);
        $admin->email_verified_at ??= now();
        $admin->deleted_at = null;
        $admin->suspended_at = null;
        $admin->save();

        $admin->assignRole(UserRole::PlatformAdmin->value);

        $this->command?->info("Platform admin ready: {$admin->email}");
    }
}
