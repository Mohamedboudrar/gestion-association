<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'president',
            'vice-president',
            'tresorier',
            'vice-tresorier',
            'secretaire-general',
            'vice-secretaire-general',
            'conseiller',
            'abonne',
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        User::all()->each(function (User $user) {
            if (! $user->hasRole('abonne')) {
                $user->assignRole('abonne');
            }
        });
    }
}
