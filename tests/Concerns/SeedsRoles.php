<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Truncation empties the roles table the migration filled, so suites that
 * truncate re-create the account roles before every test (test order then
 * never matters).
 */
trait SeedsRoles
{
    protected function setUpSeedsRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
    }
}
