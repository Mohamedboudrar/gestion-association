<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateBureauAccounts extends Command
{
    protected $signature = 'app:create-bureau-accounts';

    protected $description = 'Create association bureau accounts';

    public function handle(): int
    {
        $password = 'password';

        $accounts = [
            [
                'name'  => 'President',
                'email' => 'president@association.test',
                'role'  => 'president',
            ],
            [
                'name'  => 'Vice President',
                'email' => 'vice-president@association.test',
                'role'  => 'vice-president',
            ],
            [
                'name'  => 'Treasurer',
                'email' => 'treasurer@association.test',
                'role'  => 'tresorier',
            ],
            [
                'name'  => 'Vice Treasurer',
                'email' => 'vice-treasurer@association.test',
                'role'  => 'vice-tresorier',
            ],
            [
                'name'  => 'Secretary General',
                'email' => 'secretary@association.test',
                'role'  => 'secretaire-general',
            ],
            [
                'name'  => 'Vice Secretary General',
                'email' => 'vice-secretary@association.test',
                'role'  => 'vice-secretaire-general',
            ],
            [
                'name'  => 'Advisor',
                'email' => 'advisor@association.test',
                'role'  => 'conseiller',
            ],
        ];

        foreach ($accounts as $account) {

            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($password),
                ]
            );

            Member::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'phone' => null,
                    'address' => null,
                ]
            );

            // Give everyone the default subscriber role if not already assigned
            if (! $user->hasRole('abonne')) {
                $user->assignRole('abonne');
            }

            // Give the bureau role
            if (! $user->hasRole($account['role'])) {
                $user->assignRole($account['role']);
            }

            $this->info("✔ {$account['name']} ({$account['role']})");
        }

        $this->newLine();

        $this->info('Bureau accounts created successfully.');
        $this->line('Default password for all accounts: password');

        return self::SUCCESS;
    }
}