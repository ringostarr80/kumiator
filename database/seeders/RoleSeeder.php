<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::findOrCreate('member');

        $activityLogView = Permission::findOrCreate('activity-log.view');

        $admin = Role::findOrCreate('admin');
        $admin->givePermissionTo($activityLogView);
    }
}
