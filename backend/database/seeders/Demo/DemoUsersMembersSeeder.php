<?php

namespace Database\Seeders\Demo;

use App\Models\Member;
use App\Models\User;
use Database\Seeders\Support\MoroccanData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

// Creates one account per bureau role (reusing the existing seeded
// president@association.com rather than duplicating it — see PresidentSeeder,
// which always runs first in DatabaseSeeder), 5 advisors, and 80 subscribers.
// Every account shares the password 'password123' so any role can be
// manually tested by logging in with its printed email. Every office member
// also gets a Member profile (subscriber record) — bureau accounts are
// subscribers too, matching the app's existing convention (see
// app:create-bureau-accounts).
class DemoUsersMembersSeeder extends Seeder
{
    private const PASSWORD = 'password123';

    public function run(): void
    {
        $credentials = [];

        $president = User::where('email', 'president@association.com')->firstOrFail();

        // Batched under a single causer for the whole run — real accounts
        // are typically bootstrapped by the president/secretary in one
        // sitting, and this avoids one session write per record.
        Auth::login($president);

        $credentials[] = $this->attachMemberToExistingUser($president, 'President');

        $bureauRoles = [
            ['role' => 'vice-president', 'email' => 'vice.president@association.com', 'label' => 'Vice President'],
            ['role' => 'tresorier', 'email' => 'tresorier@association.com', 'label' => 'Treasurer'],
            ['role' => 'vice-tresorier', 'email' => 'vice.tresorier@association.com', 'label' => 'Vice Treasurer'],
            ['role' => 'secretaire-general', 'email' => 'secretaire.general@association.com', 'label' => 'Secretary General'],
            ['role' => 'vice-secretaire-general', 'email' => 'vice.secretaire.general@association.com', 'label' => 'Vice Secretary General'],
        ];

        foreach ($bureauRoles as $account) {
            $credentials[] = $this->createBureauMember($account['role'], $account['email'], $account['label']);
        }

        for ($i = 1; $i <= 5; $i++) {
            $credentials[] = $this->createBureauMember(
                'conseiller',
                "conseiller{$i}@association.com",
                "Advisor #{$i}",
            );
        }

        for ($i = 1; $i <= 80; $i++) {
            $this->createSubscriber($i);
        }

        Auth::logout();

        $this->command?->info('Demo bureau accounts (password: '.self::PASSWORD.'):');
        $this->command?->table(['Role', 'Email', 'Name'], $credentials);
        $this->command?->info('80 subscriber accounts created (password: '.self::PASSWORD.'), e.g. subscriber1@example.ma .. subscriber80@example.ma');
    }

    private function attachMemberToExistingUser(User $user, string $label): array
    {
        Member::firstOrCreate(
            ['user_id' => $user->id],
            [
                'phone' => MoroccanData::randomPhone(),
                'address' => MoroccanData::randomAddress(),
            ]
        );

        return [$label, $user->email, $user->name];
    }

    private function createBureauMember(string $role, string $email, string $label): array
    {
        $identity = MoroccanData::randomFullName();

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $identity['name'],
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole('abonne')) {
            $user->assignRole('abonne');
        }

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        Member::firstOrCreate(
            ['user_id' => $user->id],
            [
                'phone' => MoroccanData::randomPhone(),
                'address' => MoroccanData::randomAddress(),
            ]
        );

        return [$label, $email, $user->name];
    }

    private function createSubscriber(int $index): void
    {
        $identity = MoroccanData::randomFullName();
        $email = "subscriber{$index}@example.ma";

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $identity['name'],
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole('abonne')) {
            $user->assignRole('abonne');
        }

        Member::firstOrCreate(
            ['user_id' => $user->id],
            [
                'phone' => MoroccanData::randomPhone(),
                'address' => MoroccanData::randomAddress(),
            ]
        );
    }
}
