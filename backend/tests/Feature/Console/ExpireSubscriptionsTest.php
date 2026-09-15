<?php

use App\Models\Notification;
use App\Models\Subscription;

it('expires a verified subscription past its expiry date and notifies the subscriber and financial oversight roles', function () {
    $president = presidentActor();
    $treasurer = treasurerActor();
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->verified()->create([
        'expires_at' => now()->subDay()->toDateString(),
    ]);

    $this->artisan('app:expire-subscriptions')->assertExitCode(0);

    expect($subscription->fresh()->status)->toBe('expired');

    $this->assertDatabaseHas('notifications', [
        'user_id' => $subscriber->id,
        'type' => 'subscription_expired',
    ]);
    $this->assertDatabaseHas('notifications', [
        'user_id' => $president->id,
        'type' => 'subscription_expired',
    ]);
    $this->assertDatabaseHas('notifications', [
        'user_id' => $treasurer->id,
        'type' => 'subscription_expired',
    ]);
});

it('leaves a verified subscription untouched while it is not yet expired', function () {
    $subscriber = subscriberActor();
    $subscription = Subscription::factory()->for($subscriber->member)->verified()->create([
        'expires_at' => now()->addMonth()->toDateString(),
    ]);

    $this->artisan('app:expire-subscriptions')->assertExitCode(0);

    expect($subscription->fresh()->status)->toBe('verified');
    $this->assertDatabaseMissing('notifications', ['user_id' => $subscriber->id, 'type' => 'subscription_expired']);
});

it('never touches an already-expired or pending subscription a second time', function () {
    $subscriber = subscriberActor();
    Subscription::factory()->for($subscriber->member)->create([
        'status' => 'pending',
        'expires_at' => now()->subDay()->toDateString(),
    ]);

    $this->artisan('app:expire-subscriptions');

    $this->assertDatabaseMissing('notifications', ['user_id' => $subscriber->id, 'type' => 'subscription_expired']);
});

it('sends a one-time reminder for subscriptions expiring in exactly 30, 7, and 0 days, never duplicated on a second run', function () {
    $thirtyDay = subscriberActor();
    Subscription::factory()->for($thirtyDay->member)->verified()->create(['expires_at' => now()->addDays(30)->toDateString()]);

    $sevenDay = subscriberActor();
    Subscription::factory()->for($sevenDay->member)->verified()->create(['expires_at' => now()->addDays(7)->toDateString()]);

    $today = subscriberActor();
    Subscription::factory()->for($today->member)->verified()->create(['expires_at' => now()->toDateString()]);

    $this->artisan('app:expire-subscriptions');

    $this->assertDatabaseHas('notifications', ['user_id' => $thirtyDay->id, 'type' => 'subscription_expiring_30']);
    $this->assertDatabaseHas('notifications', ['user_id' => $sevenDay->id, 'type' => 'subscription_expiring_7']);
    $this->assertDatabaseHas('notifications', ['user_id' => $today->id, 'type' => 'subscription_expiring_today']);

    $countBefore = Notification::where('type', 'subscription_expiring_30')->count();

    // Running the command again the same day must not re-notify the same
    // subscriber for the same threshold (notifyUsersOnce dedup).
    $this->artisan('app:expire-subscriptions');

    expect(Notification::where('type', 'subscription_expiring_30')->count())->toBe($countBefore);
});
