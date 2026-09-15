<?php

it('lets president and secretaire-general create members, and forbids every other role', function (string $actor, bool $allowed) {
    $user = match ($actor) {
        'president' => presidentActor(),
        'secretaire-general' => secretaireGeneralActor(),
        'vice-president' => vicePresidentActor(),
        'tresorier' => treasurerActor(),
        'vice-tresorier' => viceTreasurerActor(),
        'vice-secretaire-general' => viceSecretaireGeneralActor(),
        'conseiller' => conseillerActor(),
        'abonne' => subscriberActor(),
    };

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/members', [
        'name' => 'Someone New',
        'email' => "someone.new.{$actor}@test.local",
    ]);

    $allowed ? $response->assertCreated() : $response->assertStatus(403);
})->with([
    ['president', true],
    ['secretaire-general', true],
    ['vice-president', false],
    ['tresorier', false],
    ['vice-tresorier', false],
    ['vice-secretaire-general', false],
    ['conseiller', false],
    ['abonne', false],
]);

it('lets president, vice-president, secretaire-general and vice-secretaire-general update any members record', function (string $actor) {
    $user = match ($actor) {
        'president' => presidentActor(),
        'vice-president' => vicePresidentActor(),
        'secretaire-general' => secretaireGeneralActor(),
        'vice-secretaire-general' => viceSecretaireGeneralActor(),
    };
    $target = subscriberActor();

    $this->actingAs($user, 'sanctum')->putJson("/api/members/{$target->member->id}", [
        'phone' => '0699999999',
    ])->assertOk();
})->with(['president', 'vice-president', 'secretaire-general', 'vice-secretaire-general']);

it('forbids tresorier, vice-tresorier and conseiller from updating another members record', function (string $actor) {
    $user = match ($actor) {
        'tresorier' => treasurerActor(),
        'vice-tresorier' => viceTreasurerActor(),
        'conseiller' => conseillerActor(),
    };
    $target = subscriberActor();

    $this->actingAs($user, 'sanctum')->putJson("/api/members/{$target->member->id}", [
        'phone' => '0699999999',
    ])->assertStatus(403);
})->with(['tresorier', 'vice-tresorier', 'conseiller']);

it('forbids a subscriber from updating another subscribers record but allows updating their own', function () {
    $self = subscriberActor();
    $other = subscriberActor();

    $this->actingAs($self, 'sanctum')->putJson("/api/members/{$other->member->id}", [
        'phone' => '0699999999',
    ])->assertStatus(403);

    $this->actingAs($self, 'sanctum')->putJson("/api/members/{$self->member->id}", [
        'phone' => '0688888888',
    ])->assertOk();

    $this->assertDatabaseHas('members', [
        'id' => $self->member->id,
        'phone' => '0688888888',
    ]);
});

it('only lets the president delete a member', function (string $actor, bool $allowed) {
    $user = match ($actor) {
        'president' => presidentActor(),
        'vice-president' => vicePresidentActor(),
        'secretaire-general' => secretaireGeneralActor(),
        'tresorier' => treasurerActor(),
        'abonne' => subscriberActor(),
    };
    $target = subscriberActor();

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/members/{$target->member->id}");

    $allowed ? $response->assertOk() : $response->assertStatus(403);
})->with([
    ['president', true],
    ['vice-president', false],
    ['secretaire-general', false],
    ['tresorier', false],
    ['abonne', false],
]);

it('lets any bureau role view any members record, but a subscriber only their own', function (string $actor) {
    $user = match ($actor) {
        'president' => presidentActor(),
        'tresorier' => treasurerActor(),
        'conseiller' => conseillerActor(),
    };
    $target = subscriberActor();

    $this->actingAs($user, 'sanctum')->getJson("/api/members/{$target->member->id}")->assertOk();
})->with(['president', 'tresorier', 'conseiller']);

it('forbids a subscriber from viewing another subscribers record', function () {
    $self = subscriberActor();
    $other = subscriberActor();

    $this->actingAs($self, 'sanctum')->getJson("/api/members/{$other->member->id}")->assertStatus(403);
    $this->actingAs($self, 'sanctum')->getJson("/api/members/{$self->member->id}")->assertOk();
});
