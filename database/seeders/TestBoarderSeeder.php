<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A boarder account for trying the app on a local machine: sign in, browse and
 * apply. It only creates the account, never a stay or a booking. Not part of
 * DatabaseSeeder; run it by hand:
 *
 *     php artisan db:seed --class=TestBoarderSeeder
 *
 * Safe to run more than once. Refuses to run in production.
 */
class TestBoarderSeeder extends Seeder
{
    public const EMAIL = 'test.boarder@dormlink.test';

    public const PASSWORD = '93ab991674c583c2';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The test boarder is for local machines only.');
        }

        $boarder = User::withTrashed()->firstOrNew(['email' => self::EMAIL]);
        $boarder->fill([
            'name' => 'Test Boarder',
            'password' => self::PASSWORD,
            'phone' => '0917 000 0000',
            'active_role' => UserRole::Boarder->value,
            'consented_at' => now(),
            'privacy_policy_version' => config('dormlink.privacy_policy_version'),
        ]);
        $boarder->email_verified_at ??= now();
        $boarder->deleted_at = null;
        $boarder->suspended_at = null;
        $boarder->save();

        $boarder->assignRole(UserRole::Boarder->value);
        $boarder->boarderProfile()->firstOrCreate();

        $this->command?->info("Test boarder ready: {$boarder->email} (password in TestBoarderSeeder)");
    }
}
