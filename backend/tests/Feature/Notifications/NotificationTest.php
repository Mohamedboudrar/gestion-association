<?php

use App\Models\Notification;

it('only returns the authenticated users own notifications', function () {
    $me = subscriberActor();
    $someoneElse = subscriberActor();

    Notification::notifyUsers([$me->id], 'subscription_approved', 'Your subscription was approved');
    Notification::notifyUsers([$someoneElse->id], 'subscription_approved', 'Their subscription was approved');

    $response = $this->actingAs($me, 'sanctum')->getJson('/api/notifications');

    $response->assertOk();

    $titles = collect($response->json('data'))->pluck('title');
    expect($titles)->toHaveCount(1);
    expect($titles->first())->toBe('Your subscription was approved');
});

it('filters notifications by unread status', function () {
    $user = subscriberActor();

    Notification::notifyUsers([$user->id], 'subscription_approved', 'Unread one');
    Notification::notifyUsers([$user->id], 'subscription_rejected', 'Read one');
    Notification::where('title', 'Read one')->update(['read_at' => now()]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?status=unread');

    $response->assertOk();
    $titles = collect($response->json('data'))->pluck('title');
    expect($titles)->toEqual(collect(['Unread one']));
});

it('filters notifications by read status', function () {
    $user = subscriberActor();

    Notification::notifyUsers([$user->id], 'subscription_approved', 'Unread one');
    Notification::notifyUsers([$user->id], 'subscription_rejected', 'Read one');
    Notification::where('title', 'Read one')->update(['read_at' => now()]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?status=read');

    $response->assertOk();
    $titles = collect($response->json('data'))->pluck('title');
    expect($titles)->toEqual(collect(['Read one']));
});

it('filters notifications by category using the real NotificationResource category map', function () {
    $user = subscriberActor();

    Notification::notifyUsers([$user->id], 'subscription_approved', 'Membership notice');
    Notification::notifyUsers([$user->id], 'donation_approved', 'Donation notice');
    Notification::notifyUsers([$user->id], 'committee_assigned', 'Committee notice');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?category=donations');

    $response->assertOk();
    $data = collect($response->json('data'));
    expect($data)->toHaveCount(1);
    expect($data->first()['title'])->toBe('Donation notice');
    expect($data->first()['category'])->toBe('donations');
});

it('filters notifications by date range', function () {
    $user = subscriberActor();

    Notification::notifyUsers([$user->id], 'subscription_approved', 'Old notice');
    $old = Notification::where('title', 'Old notice')->firstOrFail();
    Notification::where('id', $old->id)->update(['created_at' => now()->subDays(10)]);

    Notification::notifyUsers([$user->id], 'subscription_approved', 'Recent notice');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?date_from='.now()->subDays(2)->toDateString());

    $response->assertOk();
    $titles = collect($response->json('data'))->pluck('title');
    expect($titles)->toEqual(collect(['Recent notice']));

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?date_to='.now()->subDays(5)->toDateString());

    $response->assertOk();
    $titles = collect($response->json('data'))->pluck('title');
    expect($titles)->toEqual(collect(['Old notice']));
});

it('paginates with a default page size of 20 and caps per_page at 100', function () {
    $user = subscriberActor();

    // notifyUsers() deduplicates its $userIds via ->unique() before inserting
    // (so a role-based recipient list never creates repeat rows for one
    // user) — so 25 *rows* for the same user requires 25 separate calls,
    // not one call with the same id repeated 25 times.
    for ($i = 0; $i < 25; $i++) {
        Notification::notifyUsers([$user->id], 'subscription_approved', 'Bulk notice');
    }

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

    $response->assertOk()
        ->assertJsonStructure(['current_page', 'data', 'last_page', 'per_page', 'total'])
        ->assertJsonPath('per_page', 20)
        ->assertJsonPath('total', 25)
        ->assertJsonPath('last_page', 2);
    expect($response->json('data'))->toHaveCount(20);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?per_page=500');
    $response->assertOk()->assertJsonPath('per_page', 100);
});

it('marks its own notification as read', function () {
    $user = subscriberActor();
    Notification::notifyUsers([$user->id], 'subscription_approved', 'Mark me');
    $notification = Notification::firstOrFail();

    expect($notification->read_at)->toBeNull();

    $response = $this->actingAs($user, 'sanctum')->postJson("/api/notifications/{$notification->id}/read");

    $response->assertOk();
    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('forbids marking another users notification as read', function () {
    $me = subscriberActor();
    $someoneElse = subscriberActor();
    Notification::notifyUsers([$someoneElse->id], 'subscription_approved', 'Not yours');
    $notification = Notification::firstOrFail();

    $response = $this->actingAs($me, 'sanctum')->postJson("/api/notifications/{$notification->id}/read");

    $response->assertStatus(403);
    expect($notification->fresh()->read_at)->toBeNull();
});

it('marks all of the callers unread notifications as read without touching other users notifications', function () {
    $me = subscriberActor();
    $someoneElse = subscriberActor();

    Notification::notifyUsers([$me->id], 'subscription_approved', 'Mine 1');
    Notification::notifyUsers([$me->id], 'subscription_rejected', 'Mine 2');
    Notification::notifyUsers([$someoneElse->id], 'subscription_approved', 'Theirs');

    $response = $this->actingAs($me, 'sanctum')->postJson('/api/notifications/read-all');

    $response->assertOk();

    expect(Notification::where('user_id', $me->id)->whereNull('read_at')->count())->toBe(0);
    expect(Notification::where('user_id', $someoneElse->id)->whereNull('read_at')->count())->toBe(1);
});

it('deletes its own notification', function () {
    $user = subscriberActor();
    Notification::notifyUsers([$user->id], 'subscription_approved', 'Delete me');
    $notification = Notification::firstOrFail();

    $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/notifications/{$notification->id}");

    $response->assertOk();
    expect(Notification::find($notification->id))->toBeNull();
});

it('forbids deleting another users notification', function () {
    $me = subscriberActor();
    $someoneElse = subscriberActor();
    Notification::notifyUsers([$someoneElse->id], 'subscription_approved', 'Not yours');
    $notification = Notification::firstOrFail();

    $response = $this->actingAs($me, 'sanctum')->deleteJson("/api/notifications/{$notification->id}");

    $response->assertStatus(403);
    expect(Notification::find($notification->id))->not->toBeNull();
});

it('rejects unauthenticated access to notifications', function () {
    $this->getJson('/api/notifications')->assertStatus(401);
});

it('returns an authoritative unread_count independent of the current page/filter', function () {
    $user = subscriberActor();

    for ($i = 0; $i < 3; $i++) {
        Notification::notifyUsers([$user->id], 'subscription_approved', "Unread {$i}");
    }
    Notification::notifyUsers([$user->id], 'subscription_approved', 'Already read');
    Notification::where('title', 'Already read')->update(['read_at' => now()]);

    // Asking for a single item per page must not shrink the unread count to
    // match — it has to reflect every unread row for this user, not just
    // what's on the current page.
    $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?per_page=1');

    $response->assertOk()->assertJsonPath('unread_count', 3);
    expect($response->json('data'))->toHaveCount(1);

    // Someone else's notifications must never count toward my unread total.
    $someoneElse = subscriberActor();
    Notification::notifyUsers([$someoneElse->id], 'subscription_approved', 'Not mine');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');
    $response->assertOk()->assertJsonPath('unread_count', 3);
});
