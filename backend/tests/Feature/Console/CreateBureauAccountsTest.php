<?php

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates all 7 bureau accounts, each with a member record, both abonne and their bureau role', function () {
    $this->artisan('app:create-bureau-accounts')->assertExitCode(0);

    $expected = [
        'president@association.test' => 'president',
        'vice-president@association.test' => 'vice-president',
        'treasurer@association.test' => 'tresorier',
        'vice-treasurer@association.test' => 'vice-tresorier',
        'secretary@association.test' => 'secretaire-general',
        'vice-secretary@association.test' => 'vice-secretaire-general',
        'advisor@association.test' => 'conseiller',
    ];

    foreach ($expected as $email => $role) {
        $user = User::where('email', $email)->first();

        expect($user)->not->toBeNull();
        expect($user->hasRole($role))->toBeTrue();
        expect($user->hasRole('abonne'))->toBeTrue();
        expect(Member::where('user_id', $user->id)->exists())->toBeTrue();
        expect(Hash::check('password', $user->password))->toBeTrue();
    }
});

it('is idempotent — running it twice does not duplicate accounts or roles', function () {
    $this->artisan('app:create-bureau-accounts');
    $this->artisan('app:create-bureau-accounts');

    expect(User::where('email', 'president@association.test')->count())->toBe(1);

    $user = User::where('email', 'president@association.test')->first();
    expect($user->roles()->where('name', 'president')->count())->toBe(1);
    expect(Member::where('user_id', $user->id)->count())->toBe(1);
});

it('does not overwrite an already-existing users name or password', function () {
    User::factory()->create(['email' => 'president@association.test', 'name' => 'Existing Name']);

    $this->artisan('app:create-bureau-accounts');

    $user = User::where('email', 'president@association.test')->first();
    expect($user->name)->toBe('Existing Name');
});
