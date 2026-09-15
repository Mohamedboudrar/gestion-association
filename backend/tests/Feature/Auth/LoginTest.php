<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('logs in a bureau user with valid credentials and returns a usable token', function () {
    $user = presidentActor(['email' => 'president@test.local', 'password' => Hash::make('correct-password')]);

    $response = $this->postJson('/api/login', [
        'email' => 'president@test.local',
        'password' => 'correct-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user', 'token'])
        ->assertJsonPath('user.email', 'president@test.local');

    $token = $response->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('email', 'president@test.local');
});

it('rejects login with an incorrect password without revealing whether the email exists', function () {
    presidentActor(['email' => 'president@test.local', 'password' => Hash::make('correct-password')]);

    $response = $this->postJson('/api/login', [
        'email' => 'president@test.local',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials']);
});

it('rejects login for an email that does not exist with the same generic message', function () {
    $response = $this->postJson('/api/login', [
        'email' => 'nobody@test.local',
        'password' => 'whatever',
    ]);

    $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials']);
});

it('validates required login fields', function () {
    $this->postJson('/api/login', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rejects an unauthenticated request to a protected route', function () {
    $this->getJson('/api/me')->assertStatus(401);
});

it('registers a new user, always as a plain subscriber regardless of any role hint sent', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'New Person',
        'email' => 'new.person@test.local',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'president',
    ]);

    $response->assertCreated();

    $user = User::where('email', 'new.person@test.local')->firstOrFail();
    expect($user->hasRole('abonne'))->toBeTrue();
    expect($user->hasRole('president'))->toBeFalse();
});

it('rejects registration when the password confirmation does not match', function () {
    $this->postJson('/api/register', [
        'name' => 'New Person',
        'email' => 'new.person@test.local',
        'password' => 'password123',
        'password_confirmation' => 'not-the-same',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

it('rejects registration with a duplicate email', function () {
    presidentActor(['email' => 'taken@test.local']);

    $this->postJson('/api/register', [
        'name' => 'New Person',
        'email' => 'taken@test.local',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('logs out and deletes the current access token from the database', function () {
    $user = presidentActor();
    $token = $user->createToken('api');

    $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
        ->postJson('/api/logout')
        ->assertOk()
        ->assertJson(['message' => 'Logged out']);

    // Assert against the DB rather than chaining a second simulated request
    // with the same (now-deleted) token: Laravel's HTTP testing reuses one
    // application instance across calls within a test, and Sanctum's guard
    // caches its resolved user for that instance's lifetime — so a second
    // request in the same test can still appear "authenticated" even though
    // a real, separate production request with the same dead token would
    // correctly get 401. The row being gone is the actual behavior under test.
    expect(\Laravel\Sanctum\PersonalAccessToken::find($token->accessToken->id))->toBeNull();
});

it('returns the authenticated users roles from /me', function () {
    $user = treasurerActor();
    $token = $user->createToken('api')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('roles.0.name', 'tresorier');
});
