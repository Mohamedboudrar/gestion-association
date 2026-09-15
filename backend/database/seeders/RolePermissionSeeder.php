<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        Role::findByName('president')->givePermissionTo([
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
        ]);

        Role::findByName('vice-president')->givePermissionTo([
            'members.view',
            'members.update',

            'subscriptions.view',

            'dues.view',

            'projects.view',
            'projects.create',
            'projects.update',

            'donations.view',
            'donations.create',
            'donations.update',

            'expenses.view',
            'expenses.create',
            'expenses.update',
        ]);

        Role::findByName('tresorier')->givePermissionTo([
            'members.view',

            'subscriptions.view',
            'subscriptions.create',
            'subscriptions.update',
            'subscriptions.verify',

            'dues.view',

            'projects.view',

            'donations.view',
            'donations.create',
            'donations.update',
            'donations.delete',

            'expenses.view',
            'expenses.create',
            'expenses.update',
            'expenses.delete',
        ]);

        Role::findByName('vice-tresorier')->givePermissionTo([
            'members.view',

            'subscriptions.view',
            'subscriptions.verify',

            'dues.view',

            'projects.view',

            'donations.view',
            'donations.create',
            'donations.update',
            'donations.delete',

            'expenses.view',
            'expenses.create',
            'expenses.update',
            'expenses.delete',
        ]);

        Role::findByName('secretaire-general')->givePermissionTo([
            'members.view',
            'members.create',
            'members.update',

            'subscriptions.view',

            'dues.view',

            'projects.view',

            'donations.view',
            'donations.create',
            'donations.update',

            'expenses.view',
            'expenses.create',
            'expenses.update',
        ]);

        Role::findByName('vice-secretaire-general')->givePermissionTo([
            'members.view',
            'members.update',

            'subscriptions.view',

            'dues.view',

            'projects.view',

            'donations.view',
            'donations.create',
            'donations.update',

            'expenses.view',
            'expenses.create',
            'expenses.update',
        ]);

        Role::findByName('conseiller')->givePermissionTo([
            'members.view',

            'subscriptions.view',

            'dues.view',

            'projects.view',

            'donations.view',
            'donations.create',
            'donations.update',

            'expenses.view',
            'expenses.create',
            'expenses.update',
        ]);

        Role::findByName('abonne')->givePermissionTo([
            'members.view',
            'subscriptions.view',
            'dues.view',
            'projects.view',
        ]);
    }
}
