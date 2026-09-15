<?php

use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a member as a fresh abonne user, ignoring any password field sent by the caller', function () {
    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->postJson('/api/members', [
        'name' => 'Jane Doe',
        'email' => 'jane.doe@test.local',
        'phone' => '0600000000',
        'address' => '12 Rue Test',
        // Deliberately sent even though StoreMemberRequest has no password
        // rule — must never end up as the created user's password.
        'password' => 'attacker-supplied-password',
    ]);

    $response->assertCreated();

    $user = User::where('email', 'jane.doe@test.local')->firstOrFail();

    expect($user->hasRole('abonne'))->toBeTrue();
    expect(Hash::check('attacker-supplied-password', $user->password))->toBeFalse();

    $response->assertJsonPath('data.user.email', 'jane.doe@test.local')
        ->assertJsonPath('data.phone', '0600000000')
        ->assertJsonPath('data.address', '12 Rue Test')
        ->assertJsonPath('data.is_bureau_member', false)
        ->assertJsonPath('data.has_portal_access', false)
        ->assertJsonPath('data.has_verified_subscription', false);

    $this->assertDatabaseHas('members', [
        'user_id' => $user->id,
        'phone' => '0600000000',
        'address' => '12 Rue Test',
    ]);
});

it('assigns the abonne role to a newly created member regardless of who created them', function () {
    $secretaire = secretaireGeneralActor();

    $this->actingAs($secretaire, 'sanctum')->postJson('/api/members', [
        'name' => 'New Member',
        'email' => 'new.member@test.local',
    ])->assertCreated();

    $user = User::where('email', 'new.member@test.local')->firstOrFail();
    expect($user->hasRole('abonne'))->toBeTrue();
    expect($user->hasRole('secretaire-general'))->toBeFalse();
});

it('validates required fields and email format when creating a member', function () {
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')->postJson('/api/members', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email']);

    $this->actingAs($president, 'sanctum')->postJson('/api/members', [
        'name' => 'Bad Email',
        'email' => 'not-an-email',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('rejects creating a member with an email already in use', function () {
    $president = presidentActor(['email' => 'taken@test.local']);

    $this->actingAs($president, 'sanctum')->postJson('/api/members', [
        'name' => 'Duplicate',
        'email' => 'taken@test.local',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('lists every member for a bureau caller', function () {
    $president = presidentActor();
    subscriberActor();
    subscriberActor();

    $response = $this->actingAs($president, 'sanctum')->getJson('/api/members');

    $response->assertOk();
    // president's own member + the two subscribers created above.
    expect($response->json('data'))->toHaveCount(3);
});

it('scopes the member list to only the callers own record for a plain subscriber', function () {
    $self = subscriberActor();
    subscriberActor();
    subscriberActor();

    $response = $this->actingAs($self, 'sanctum')->getJson('/api/members');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($self->member->id);
});

it('updates a members phone and address', function () {
    $president = presidentActor();
    $target = subscriberActor();

    $response = $this->actingAs($president, 'sanctum')->putJson("/api/members/{$target->member->id}", [
        'phone' => '0611223344',
        'address' => 'New Address',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.phone', '0611223344')
        ->assertJsonPath('data.address', 'New Address');

    $this->assertDatabaseHas('members', [
        'id' => $target->member->id,
        'phone' => '0611223344',
        'address' => 'New Address',
    ]);
});

it('deletes a subscriber, removing both the member profile and their user account', function () {
    $president = presidentActor();
    $target = subscriberActor();
    $targetUserId = $target->id;
    $targetMemberId = $target->member->id;

    $this->actingAs($president, 'sanctum')->deleteJson("/api/members/{$targetMemberId}")
        ->assertOk()
        ->assertJson(['message' => 'Member deleted']);

    $this->assertDatabaseMissing('members', ['id' => $targetMemberId]);
    // A Member is always created together with its own dedicated User (see
    // MemberController::store()) — deleting a subscriber must remove both,
    // otherwise their email is permanently unusable and the account
    // technically still exists (this was the actual bug being fixed).
    $this->assertDatabaseMissing('users', ['id' => $targetUserId]);
});

it('deletes a bureau members profile row without deleting their user account or role', function () {
    $president = presidentActor();
    $target = treasurerActor();
    $targetUserId = $target->id;
    $targetMemberId = $target->member->id;

    $this->actingAs($president, 'sanctum')->deleteJson("/api/members/{$targetMemberId}")
        ->assertOk()
        ->assertJson(['message' => 'Member deleted']);

    $this->assertDatabaseMissing('members', ['id' => $targetMemberId]);
    // Bureau accounts are shared login identities with real system access —
    // deleting their Member profile row must never destroy their login.
    $this->assertDatabaseHas('users', ['id' => $targetUserId]);
    expect($target->fresh()->hasRole('tresorier'))->toBeTrue();
});

it('returns 404 for a member id that does not exist', function () {
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')->getJson('/api/members/999999')->assertStatus(404);
});

it('rejects unauthenticated access to the members endpoints', function () {
    $this->getJson('/api/members')->assertStatus(401);
});
