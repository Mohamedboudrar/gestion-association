<?php

use App\Models\Subscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // verify() may call out to MailerSendService (real API key configured in
    // .env) the first time a subscriber gets portal access — never let that
    // hit the network during tests.
    Http::fake();
});

it('lets president, tresorier and vice-tresorier verify a pending subscription and notifies the subscriber', function (string $actor) {
    $verifier = match ($actor) {
        'president' => presidentActor(),
        'tresorier' => treasurerActor(),
        'vice-tresorier' => viceTreasurerActor(),
    };
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->create();

    $response = $this->actingAs($verifier, 'sanctum')->postJson("/api/subscriptions/{$subscription->id}/verify");

    $response->assertOk()->assertJsonPath('data.status', 'verified');

    $this->assertDatabaseHas('subscriptions', [
        'id' => $subscription->id,
        'status' => 'verified',
        'verified_by' => $verifier->id,
    ]);

    $this->assertDatabaseHas('notifications', [
        'type' => 'subscription_approved',
        'user_id' => $subscriber->id,
        'subject_id' => $subscription->id,
    ]);
})->with(['president', 'tresorier', 'vice-tresorier']);

it('forbids vice-president, secretaire-general, vice-secretaire-general, conseiller and the subscriber themself from verifying', function (string $actor) {
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->create();

    $verifier = match ($actor) {
        'vice-president' => vicePresidentActor(),
        'secretaire-general' => secretaireGeneralActor(),
        'vice-secretaire-general' => viceSecretaireGeneralActor(),
        'conseiller' => conseillerActor(),
        'abonne-self' => $subscriber,
    };

    $this->actingAs($verifier, 'sanctum')
        ->postJson("/api/subscriptions/{$subscription->id}/verify")
        ->assertStatus(403);
})->with(['vice-president', 'secretaire-general', 'vice-secretaire-general', 'conseiller', 'abonne-self']);

it('issues a member portal passkey the first time a subscribers subscription is verified', function () {
    $president = presidentActor();
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->create();

    expect($subscriber->passkey_hash)->toBeNull();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/subscriptions/{$subscription->id}/verify")
        ->assertOk();

    expect($subscriber->fresh()->passkey_hash)->not->toBeNull();
});

it('does not reissue a passkey when verifying a second subscription for an already portal-enabled subscriber', function () {
    $president = presidentActor();
    $subscriber = subscriberActor();
    $firstSubscription = Subscription::factory()->for($subscriber->member)->create();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/subscriptions/{$firstSubscription->id}/verify")
        ->assertOk();

    $passkeyHashAfterFirst = $subscriber->fresh()->passkey_hash;
    expect($passkeyHashAfterFirst)->not->toBeNull();

    $secondSubscription = Subscription::factory()->for($subscriber->member)->create();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/subscriptions/{$secondSubscription->id}/verify")
        ->assertOk();

    expect($subscriber->fresh()->passkey_hash)->toBe($passkeyHashAfterFirst);
});

it('allows re-verifying an already-verified subscription idempotently without error', function () {
    $president = presidentActor();
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->verified()->create();

    $response = $this->actingAs($president, 'sanctum')
        ->postJson("/api/subscriptions/{$subscription->id}/verify");

    $response->assertOk()->assertJsonPath('data.status', 'verified');

    $this->assertDatabaseHas('subscriptions', [
        'id' => $subscription->id,
        'status' => 'verified',
        'verified_by' => $president->id,
    ]);
});

it('lets a subscriber upload a receipt for their own subscription, storing the file and reverting status to pending', function () {
    Storage::fake('public');
    $president = presidentActor();
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->verified()->create();

    $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

    $response = $this->actingAs($subscriber, 'sanctum')
        ->postJson("/api/subscriptions/{$subscription->id}/receipt", ['receipt' => $file]);

    $response->assertOk()->assertJsonPath('data.status', 'pending');

    $subscription->refresh();
    expect($subscription->status)->toBe('pending');
    expect($subscription->receipt_file)->not->toBeNull();

    Storage::disk('public')->assertExists($subscription->receipt_file);

    $this->assertDatabaseHas('notifications', [
        'type' => 'subscription_pending',
        'user_id' => $president->id,
        'subject_id' => $subscription->id,
    ]);
});

it('lets a verifier role upload a receipt on behalf of a subscriber', function () {
    Storage::fake('public');
    $treasurer = treasurerActor();
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->create();

    $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/subscriptions/{$subscription->id}/receipt", ['receipt' => $file])
        ->assertOk();

    Storage::disk('public')->assertExists($subscription->fresh()->receipt_file);
});

it('forbids uploading a receipt for someone elses subscription', function () {
    Storage::fake('public');
    $self = subscriberActor();
    $other = subscriberActor();
    $othersSubscription = Subscription::factory()->for($other->member)->create();

    $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

    $this->actingAs($self, 'sanctum')
        ->postJson("/api/subscriptions/{$othersSubscription->id}/receipt", ['receipt' => $file])
        ->assertStatus(403);
});

it('rejects a receipt upload with a disallowed mime type', function () {
    Storage::fake('public');
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->create();

    $file = UploadedFile::fake()->create('receipt.exe', 100, 'application/x-msdownload');

    $this->actingAs($subscriber, 'sanctum')
        ->postJson("/api/subscriptions/{$subscription->id}/receipt", ['receipt' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['receipt']);
});

it('rejects a receipt upload exceeding the maximum file size', function () {
    Storage::fake('public');
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->create();

    // max is 5120 KB (5 MB) — this fake file is 6 MB.
    $file = UploadedFile::fake()->create('receipt.pdf', 6000, 'application/pdf');

    $this->actingAs($subscriber, 'sanctum')
        ->postJson("/api/subscriptions/{$subscription->id}/receipt", ['receipt' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['receipt']);
});
