<?php

use App\Models\Donation;
use App\Models\Subscription;

it('returns the callers own profile, subscription, and donation history — never someone elses', function () {
    $me = subscriberActor();
    $someoneElse = subscriberActor();

    Subscription::factory()->for($me->member)->verified()->create(['amount' => 250]);
    Subscription::factory()->for($someoneElse->member)->verified()->create(['amount' => 999]);

    $project = \App\Models\Project::factory()->create();
    Donation::factory()->for($project)->create(['member_id' => $me->member->id, 'donor_name' => null]);
    Donation::factory()->for($project)->create(['member_id' => $someoneElse->member->id, 'donor_name' => null]);

    $response = $this->actingAs($me, 'sanctum')->getJson('/api/member/dashboard');

    $response->assertOk()
        ->assertJsonPath('profile.email', $me->email)
        ->assertJsonPath('membership_status', 'verified')
        ->assertJsonCount(1, 'subscription_history')
        ->assertJsonCount(1, 'donation_history');

    expect((float) $response->json('subscription_history.0.amount'))->toBe(250.0);
    expect($response->json('donation_history.0.member.id'))->toBe($me->member->id);
});

it('derives outstanding_balance as zero while the current subscription is verified and unexpired', function () {
    $user = subscriberActor();
    Subscription::factory()->for($user->member)->verified()->create([
        'amount' => 300,
        'expires_at' => now()->addMonths(6),
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/member/dashboard');

    $response->assertOk()->assertJsonPath('outstanding_balance', 0);
});

it('derives outstanding_balance as the subscription amount once it is expired', function () {
    $user = subscriberActor();
    Subscription::factory()->for($user->member)->create([
        'amount' => 300,
        'status' => 'expired',
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/member/dashboard');

    $response->assertOk()->assertJsonPath('outstanding_balance', 300);
});

it('returns 404 for an authenticated user with no linked member profile', function () {
    // A bureau-created User row with no Member (e.g. a raw factory user who
    // was never turned into a subscriber) has nothing for this endpoint to
    // scope to.
    $user = \App\Models\User::factory()->create();
    $user->assignRole('abonne');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/member/dashboard')
        ->assertStatus(404)
        ->assertJson(['message' => 'No member profile is associated with this account.']);
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/member/dashboard')->assertStatus(401);
});

it('works identically for a bureau user who also has a member record', function () {
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->getJson('/api/member/dashboard')
        ->assertOk()
        ->assertJsonPath('profile.email', $president->email);
});
