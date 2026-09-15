<?php

use App\Models\Subscription;

it('creates a subscription with the DB-default pending status and notifies every verifier role', function () {
    $creator = treasurerActor();
    $president = presidentActor();
    $viceTreasurer = viceTreasurerActor();
    $subscriber = subscriberActor();

    $response = $this->actingAs($creator, 'sanctum')->postJson('/api/subscriptions', [
        'member_id' => $subscriber->member->id,
        'amount' => 250.50,
        'payment_method' => 'cash',
        'payment_date' => '2026-01-10',
        'expires_at' => '2027-01-10',
        // Attempting to set status directly — not a validated field, must be ignored.
        'status' => 'verified',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', 250.5)
        ->assertJsonPath('data.member.id', $subscriber->member->id);

    $this->assertDatabaseHas('subscriptions', [
        'member_id' => $subscriber->member->id,
        'amount' => 250.50,
        'status' => 'pending',
    ]);

    $subscriptionId = $response->json('data.id');

    foreach ([$president->id, $creator->id, $viceTreasurer->id] as $recipientId) {
        $this->assertDatabaseHas('notifications', [
            'type' => 'subscription_pending',
            'user_id' => $recipientId,
            'subject_id' => $subscriptionId,
        ]);
    }
});

it('validates required subscription fields and rejects an expiry date before the payment date', function () {
    $treasurer = treasurerActor();

    $this->actingAs($treasurer, 'sanctum')->postJson('/api/subscriptions', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['member_id', 'amount', 'payment_method', 'payment_date', 'expires_at']);

    $subscriber = subscriberActor();

    $this->actingAs($treasurer, 'sanctum')->postJson('/api/subscriptions', [
        'member_id' => $subscriber->member->id,
        'amount' => 100,
        'payment_method' => 'cash',
        'payment_date' => '2026-06-01',
        'expires_at' => '2026-05-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['expires_at']);
});

it('only lets the president and tresorier create subscriptions', function (string $actor, bool $allowed) {
    $user = match ($actor) {
        'president' => presidentActor(),
        'tresorier' => treasurerActor(),
        'vice-president' => vicePresidentActor(),
        'vice-tresorier' => viceTreasurerActor(),
        'secretaire-general' => secretaireGeneralActor(),
        'vice-secretaire-general' => viceSecretaireGeneralActor(),
        'conseiller' => conseillerActor(),
        'abonne' => subscriberActor(),
    };
    $subscriber = subscriberActor();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/subscriptions', [
        'member_id' => $subscriber->member->id,
        'amount' => 100,
        'payment_method' => 'cash',
        'payment_date' => '2026-01-01',
        'expires_at' => '2027-01-01',
    ]);

    $allowed ? $response->assertCreated() : $response->assertStatus(403);
})->with([
    ['president', true],
    ['tresorier', true],
    ['vice-president', false],
    ['vice-tresorier', false],
    ['secretaire-general', false],
    ['vice-secretaire-general', false],
    ['conseiller', false],
    ['abonne', false],
]);

it('scopes the subscription index to only the callers own subscriptions for a plain subscriber', function () {
    $self = subscriberActor();
    $other = subscriberActor();

    Subscription::factory()->for($self->member)->create();
    Subscription::factory()->for($other->member)->create();

    $response = $this->actingAs($self, 'sanctum')->getJson('/api/subscriptions');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['member']['id'])->toBe($self->member->id);
});

it('lets a bureau caller see every subscription in the index', function () {
    $president = presidentActor();
    $memberA = subscriberActor();
    $memberB = subscriberActor();

    Subscription::factory()->for($memberA->member)->create();
    Subscription::factory()->for($memberB->member)->create();

    $response = $this->actingAs($president, 'sanctum')->getJson('/api/subscriptions');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('allows a subscriber to view their own subscription but forbids viewing anothers', function () {
    $self = subscriberActor();
    $other = subscriberActor();

    $ownSubscription = Subscription::factory()->for($self->member)->create();
    $othersSubscription = Subscription::factory()->for($other->member)->create();

    $this->actingAs($self, 'sanctum')->getJson("/api/subscriptions/{$ownSubscription->id}")->assertOk();
    $this->actingAs($self, 'sanctum')->getJson("/api/subscriptions/{$othersSubscription->id}")->assertStatus(403);
});

it('rejects unauthenticated access to the subscriptions endpoints', function () {
    $this->getJson('/api/subscriptions')->assertStatus(401);
});

it('allows a partial update of just one field via UpdateSubscriptionRequest sometimes rules', function () {
    $president = presidentActor();
    $subscription = Subscription::factory()->create(['amount' => 100]);

    $this->actingAs($president, 'sanctum')
        ->putJson("/api/subscriptions/{$subscription->id}", ['amount' => 500])
        ->assertOk()
        ->assertJsonPath('data.amount', 500);

    expect((float) $subscription->fresh()->amount)->toBe(500.0);
});

it('rejects updating expires_at to before payment_date when both are sent together', function () {
    $president = presidentActor();
    $subscription = Subscription::factory()->create();

    $this->actingAs($president, 'sanctum')
        ->putJson("/api/subscriptions/{$subscription->id}", [
            'payment_date' => '2026-06-01',
            'expires_at' => '2026-01-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['expires_at']);
});

it('rejects an update referencing a nonexistent member_id', function () {
    $president = presidentActor();
    $subscription = Subscription::factory()->create();

    $this->actingAs($president, 'sanctum')
        ->putJson("/api/subscriptions/{$subscription->id}", ['member_id' => 999999])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['member_id']);
});

it('only allows update per SubscriptionPolicy::update permission, not just any bureau role', function () {
    // subscriptions.update is granted only to president and tresorier per
    // RolePermissionSeeder — vice-president/vice-tresorier/secretaire-general/
    // vice-secretaire-general/conseiller only have subscriptions.view.
    $subscription = Subscription::factory()->create();

    $this->actingAs(vicePresidentActor(), 'sanctum')
        ->putJson("/api/subscriptions/{$subscription->id}", ['amount' => 999])
        ->assertStatus(403);

    $this->actingAs(treasurerActor(), 'sanctum')
        ->putJson("/api/subscriptions/{$subscription->id}", ['amount' => 999])
        ->assertOk();
});
