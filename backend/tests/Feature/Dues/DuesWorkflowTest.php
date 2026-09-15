<?php

use App\Models\Due;
use App\Models\Member;
use App\Models\Subscription;
use App\Services\DuesService;

// --- Generation ---

it('generates one due per member for a year, defaulting amount_due to the association setting', function () {
    Member::factory()->count(3)->create();

    \App\Models\AssociationSetting::query()->delete();
    \App\Models\AssociationSetting::create([
        'association_name' => 'Test Association',
        'address' => '1 Test St',
        'phone' => '000',
        'email' => 'assoc@example.com',
        'annual_subscription_amount' => 200,
        'currency' => 'MAD',
    ]);
    \App\Services\SettingsService::refresh();

    $result = app(DuesService::class)->generateForYear(2026);

    expect($result['created'])->toBe(3);
    expect($result['skipped'])->toBe(0);
    expect(Due::where('year', 2026)->count())->toBe(3);

    $due = Due::where('year', 2026)->first();
    expect((float) $due->amount_due)->toBe(200.0);
    expect((float) $due->balance)->toBe(200.0);
    expect($due->status)->toBe('pending');
    expect($due->due_date->toDateString())->toBe('2026-12-31');
});

it('generates annual dues via the artisan command, idempotently', function () {
    Member::factory()->count(4)->create();

    $this->artisan('app:generate-annual-dues', ['year' => 2026])->assertExitCode(0);
    expect(Due::where('year', 2026)->count())->toBe(4);

    $this->artisan('app:generate-annual-dues', ['year' => 2026])->assertExitCode(0);
    expect(Due::where('year', 2026)->count())->toBe(4);
});

it('defaults the generation command to the current year when none is given', function () {
    Member::factory()->create();

    $this->artisan('app:generate-annual-dues')->assertExitCode(0);

    expect(Due::where('year', now()->year)->count())->toBe(1);
});

it('is safe to run dues generation multiple times without creating duplicates', function () {
    Member::factory()->count(5)->create();

    $first = app(DuesService::class)->generateForYear(2026);
    expect($first['created'])->toBe(5);
    expect($first['skipped'])->toBe(0);

    $second = app(DuesService::class)->generateForYear(2026);
    expect($second['created'])->toBe(0);
    expect($second['skipped'])->toBe(5);

    expect(Due::where('year', 2026)->count())->toBe(5);
});

it('fires a due_created notification to the member the first time their due is generated, never again', function () {
    $subscriber = subscriberActor();

    app(DuesService::class)->getOrCreateForMemberYear($subscriber->member, 2026);
    app(DuesService::class)->getOrCreateForMemberYear($subscriber->member, 2026);

    expect(\App\Models\Notification::where('type', 'due_created')
        ->where('user_id', $subscriber->id)
        ->count())->toBe(1);
});

// --- Auto-linking payments to dues ---

it('auto-links a new subscription payment to its member/year due, creating the due if it does not exist yet', function () {
    $treasurer = treasurerActor();
    $subscriber = subscriberActor();

    expect(Due::where('member_id', $subscriber->member->id)->exists())->toBeFalse();

    $response = $this->actingAs($treasurer, 'sanctum')->postJson('/api/subscriptions', [
        'member_id' => $subscriber->member->id,
        'amount' => 100,
        'payment_method' => 'cash',
        'payment_date' => '2026-03-10',
        'expires_at' => '2027-03-10',
    ]);

    $response->assertCreated();

    $due = Due::where('member_id', $subscriber->member->id)->where('year', 2026)->first();
    expect($due)->not->toBeNull();

    $subscription = Subscription::findOrFail($response->json('data.id'));
    expect($subscription->due_id)->toBe($due->id);
});

it('reuses the same due for a second payment made toward the same member and year', function () {
    $treasurer = treasurerActor();
    $subscriber = subscriberActor();

    $payload = [
        'member_id' => $subscriber->member->id,
        'amount' => 50,
        'payment_method' => 'cash',
        'payment_date' => '2026-01-05',
        'expires_at' => '2027-01-05',
    ];

    $first = $this->actingAs($treasurer, 'sanctum')->postJson('/api/subscriptions', $payload)->assertCreated();
    $second = $this->actingAs($treasurer, 'sanctum')->postJson('/api/subscriptions', array_merge($payload, ['payment_date' => '2026-06-01', 'expires_at' => '2027-06-01']))->assertCreated();

    expect($first->json('data.due.id'))->toBe($second->json('data.due.id'));
    expect(Due::where('member_id', $subscriber->member->id)->where('year', 2026)->count())->toBe(1);
});

// --- Payment approval -> balance/status recalculation ---

it('marks a due partial when a verified payment covers less than the full amount', function () {
    $president = presidentActor();
    $subscriber = subscriberActor();
    $due = Due::factory()->for($subscriber->member)->create(['amount_due' => 200, 'balance' => 200]);
    $subscription = Subscription::factory()->for($subscriber->member)->create(['due_id' => $due->id, 'amount' => 80]);

    $this->actingAs($president, 'sanctum')->postJson("/api/subscriptions/{$subscription->id}/verify")->assertOk();

    $due->refresh();
    expect((float) $due->amount_paid)->toBe(80.0);
    expect((float) $due->balance)->toBe(120.0);
    expect($due->status)->toBe('partial');
});

it('marks a due paid, sets paid_at, and notifies once the full amount is verified across multiple payments', function () {
    $president = presidentActor();
    $subscriber = subscriberActor();
    $due = Due::factory()->for($subscriber->member)->create(['amount_due' => 150, 'balance' => 150]);
    $paymentA = Subscription::factory()->for($subscriber->member)->create(['due_id' => $due->id, 'amount' => 100]);
    $paymentB = Subscription::factory()->for($subscriber->member)->create(['due_id' => $due->id, 'amount' => 50]);

    $this->actingAs($president, 'sanctum')->postJson("/api/subscriptions/{$paymentA->id}/verify")->assertOk();
    $due->refresh();
    expect($due->status)->toBe('partial');

    $this->actingAs($president, 'sanctum')->postJson("/api/subscriptions/{$paymentB->id}/verify")->assertOk();
    $due->refresh();

    expect((float) $due->amount_paid)->toBe(150.0);
    expect((float) $due->balance)->toBe(0.0);
    expect($due->status)->toBe('paid');
    expect($due->paid_at)->not->toBeNull();

    expect(\App\Models\Notification::where('type', 'due_paid')
        ->where('user_id', $subscriber->id)
        ->count())->toBe(1);
});

it('recalculates the linked due when a verified subscription payment is deleted', function () {
    $president = presidentActor();
    $subscriber = subscriberActor();
    $due = Due::factory()->for($subscriber->member)->create(['amount_due' => 100, 'balance' => 100]);
    $subscription = Subscription::factory()->for($subscriber->member)->verified()->create(['due_id' => $due->id, 'amount' => 100]);

    app(DuesService::class)->recalculate($due->fresh());
    expect($due->fresh()->status)->toBe('paid');

    $this->actingAs($president, 'sanctum')->deleteJson("/api/subscriptions/{$subscription->id}")->assertOk();

    $due->refresh();
    expect((float) $due->amount_paid)->toBe(0.0);
    expect((float) $due->balance)->toBe(100.0);
    expect($due->status)->toBe('pending');
});

// --- Overdue ---

it('marks overdue dues via the scheduled command and notifies the member and treasury roles, once', function () {
    $president = presidentActor();
    $treasurer = treasurerActor();
    $subscriber = subscriberActor();
    $due = Due::factory()->for($subscriber->member)->create([
        'amount_due' => 100,
        'balance' => 100,
        'due_date' => now()->subDay()->toDateString(),
        'status' => 'pending',
    ]);

    $this->artisan('app:mark-overdue-dues')->assertExitCode(0);

    expect($due->fresh()->status)->toBe('overdue');
    expect(\App\Models\Notification::where('type', 'due_overdue')->where('user_id', $subscriber->id)->count())->toBe(1);
    expect(\App\Models\Notification::where('type', 'due_overdue')->where('user_id', $president->id)->count())->toBe(1);
    expect(\App\Models\Notification::where('type', 'due_overdue')->where('user_id', $treasurer->id)->count())->toBe(1);

    // Idempotent: running it again must not re-notify or error, since the
    // query excludes rows already 'overdue'.
    $this->artisan('app:mark-overdue-dues')->assertExitCode(0);
    expect(\App\Models\Notification::where('type', 'due_overdue')->where('user_id', $subscriber->id)->count())->toBe(1);
});

it('never marks a paid or waived due as overdue', function () {
    $member = Member::factory()->create();
    $paid = Due::factory()->for($member)->paid()->create(['due_date' => now()->subDay()->toDateString()]);
    $waived = Due::factory()->for(Member::factory()->create())->waived()->create(['due_date' => now()->subDay()->toDateString(), 'balance' => 50]);

    app(DuesService::class)->markOverdue();

    expect($paid->fresh()->status)->toBe('paid');
    expect($waived->fresh()->status)->toBe('waived');
});

// --- Waiver ---

it('lets president, tresorier, and vice-tresorier waive a due, and blocks everyone else', function () {
    $due = Due::factory()->for(Member::factory()->create())->create(['amount_due' => 100, 'balance' => 100]);

    $forbidden = [vicePresidentActor(), secretaireGeneralActor(), conseillerActor(), subscriberActor()];

    foreach ($forbidden as $user) {
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/dues/{$due->id}/waive", ['reason' => 'Not allowed'])
            ->assertStatus(403);
    }

    $response = $this->actingAs(presidentActor(), 'sanctum')
        ->postJson("/api/dues/{$due->id}/waive", ['reason' => 'Financial hardship, approved by the board.']);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'waived');

    $due->refresh();
    expect($due->status)->toBe('waived');
    expect($due->waived_reason)->toBe('Financial hardship, approved by the board.');
});

it('requires a reason to waive a due', function () {
    $due = Due::factory()->for(Member::factory()->create())->create();

    $this->actingAs(presidentActor(), 'sanctum')
        ->postJson("/api/dues/{$due->id}/waive", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('keeps a waived due waived even if a payment linked to it is later verified', function () {
    $president = presidentActor();
    $subscriber = subscriberActor();
    $due = Due::factory()->for($subscriber->member)->create(['amount_due' => 100, 'balance' => 100]);
    app(DuesService::class)->waive($due, 'Hardship');

    $subscription = Subscription::factory()->for($subscriber->member)->create(['due_id' => $due->id, 'amount' => 100]);
    $this->actingAs($president, 'sanctum')->postJson("/api/subscriptions/{$subscription->id}/verify")->assertOk();

    $due->refresh();
    expect($due->status)->toBe('waived');
    expect($due->waived_reason)->toBe('Hardship');
});

// --- Authorization / scoping ---

it('scopes the dues index to the callers own dues for a plain subscriber, and shows everything to bureau', function () {
    $self = subscriberActor();
    $other = subscriberActor();
    Due::factory()->for($self->member)->create();
    Due::factory()->for($other->member)->create();

    $ownResponse = $this->actingAs($self, 'sanctum')->getJson('/api/dues');
    $ownResponse->assertOk();
    expect($ownResponse->json('data'))->toHaveCount(1);

    $bureauResponse = $this->actingAs(presidentActor(), 'sanctum')->getJson('/api/dues');
    $bureauResponse->assertOk();
    expect($bureauResponse->json('data'))->toHaveCount(2);
});

it('lets bureau filter the dues index by member_id, year, and status', function () {
    $president = presidentActor();
    $memberA = Member::factory()->create();
    $memberB = Member::factory()->create();
    Due::factory()->for($memberA)->create(['year' => 2025, 'status' => 'paid']);
    Due::factory()->for($memberA)->create(['year' => 2026, 'status' => 'pending']);
    Due::factory()->for($memberB)->create(['year' => 2026, 'status' => 'overdue']);

    $byMember = $this->actingAs($president, 'sanctum')->getJson("/api/dues?member_id={$memberA->id}");
    $byMember->assertOk();
    expect($byMember->json('data'))->toHaveCount(2);

    $byYear = $this->actingAs($president, 'sanctum')->getJson('/api/dues?year=2026');
    $byYear->assertOk();
    expect($byYear->json('data'))->toHaveCount(2);

    $byStatus = $this->actingAs($president, 'sanctum')->getJson('/api/dues?status=overdue');
    $byStatus->assertOk();
    expect($byStatus->json('data'))->toHaveCount(1);
    expect($byStatus->json('data.0.member.id'))->toBe($memberB->id);
});

it('lets a subscriber view their own due but forbids viewing anothers', function () {
    $self = subscriberActor();
    $other = subscriberActor();
    $ownDue = Due::factory()->for($self->member)->create();
    $othersDue = Due::factory()->for($other->member)->create();

    $this->actingAs($self, 'sanctum')->getJson("/api/dues/{$ownDue->id}")->assertOk();
    $this->actingAs($self, 'sanctum')->getJson("/api/dues/{$othersDue->id}")->assertStatus(403);
});

it('rejects unauthenticated access to dues endpoints', function () {
    $due = Due::factory()->for(Member::factory()->create())->create();

    $this->getJson('/api/dues')->assertStatus(401);
    $this->getJson("/api/dues/{$due->id}")->assertStatus(401);
    $this->postJson("/api/dues/{$due->id}/waive", ['reason' => 'x'])->assertStatus(401);
});

// --- Dashboard ---

it('computes correct dues figures on the president dashboard', function () {
    $president = presidentActor();
    $year = (int) now()->year;

    Due::factory()->for(Member::factory()->create())->create(['year' => $year, 'amount_due' => 100, 'amount_paid' => 100, 'balance' => 0, 'status' => 'paid']);
    Due::factory()->for(Member::factory()->create())->create(['year' => $year, 'amount_due' => 100, 'amount_paid' => 40, 'balance' => 60, 'status' => 'partial']);
    Due::factory()->for(Member::factory()->create())->create(['year' => $year, 'amount_due' => 100, 'amount_paid' => 0, 'balance' => 100, 'status' => 'overdue']);
    // A waived due is excluded from "outstanding" but still counts toward "expected".
    Due::factory()->for(Member::factory()->create())->create(['year' => $year, 'amount_due' => 100, 'amount_paid' => 0, 'balance' => 100, 'status' => 'waived', 'waived_reason' => 'x']);
    // A different year must not leak into this year's totals.
    Due::factory()->for(Member::factory()->create())->create(['year' => $year - 1, 'amount_due' => 500, 'amount_paid' => 500, 'balance' => 0, 'status' => 'paid']);

    $response = $this->actingAs($president, 'sanctum')->getJson('/api/dashboard');

    $response->assertOk();
    expect((float) $response->json('dues.expected'))->toBe(400.0);
    expect((float) $response->json('dues.collected'))->toBe(140.0);
    expect((float) $response->json('dues.outstanding'))->toBe(160.0);
    expect((int) $response->json('dues.overdue_members'))->toBe(1);
    expect((float) $response->json('dues.collection_rate'))->toBe(35.0);
});
