<?php

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;

it('seeds only the platform admin from config, and can run twice', function () {
    config()->set('dormlink.admin', ['name' => 'Admin', 'email' => 'Admin@DormLink.test', 'password' => 'adminpass1']);

    $this->seed(SuperAdminSeeder::class);
    $this->seed(SuperAdminSeeder::class);

    expect(User::count())->toBe(1);

    $admin = User::first();
    expect($admin->email)->toBe('admin@dormlink.test')
        ->and($admin->hasRole('platform_admin'))->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue();
});

it('refuses to seed without admin credentials', function () {
    config()->set('dormlink.admin', ['name' => 'Admin', 'email' => null, 'password' => null]);

    $this->seed(SuperAdminSeeder::class);
})->throws(RuntimeException::class);
