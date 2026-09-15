<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'members.view',
            'members.create',
            'members.update',
            'members.delete',

            'subscriptions.view',
            'subscriptions.create',
            'subscriptions.verify',
            'subscriptions.delete',
            'subscriptions.update',

            'dues.view',

            'projects.view',
            'projects.create',
            'projects.update',
            'projects.delete',

            'donations.view',
            'donations.create',
            'donations.update',
            'donations.delete',

            'expenses.view',
            'expenses.create',
            'expenses.update',
            'expenses.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }
    }
}
