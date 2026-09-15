<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PresidentSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'president@association.com'],
            [
                'name' => 'President',
                'password' => Hash::make('password123'),
            ]
        );

        $user->assignRole('president');
    }
}