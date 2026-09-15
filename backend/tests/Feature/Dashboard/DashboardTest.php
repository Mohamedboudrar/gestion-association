<?php

use App\Models\Donation;
use App\Models\Member;
use App\Models\Project;
use App\Models\Subscription;

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/dashboard')->assertStatus(401);
});

it('allows a plain subscriber, not just bureau roles, to load the dashboard', function () {
    $subscriber = subscriberActor();

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/dashboard')
        ->assertOk();
});

it('returns every expected top-level section', function () {
    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->getJson('/api/dashboard');

    $response->assertOk()->assertJsonStructure([
        'available_funds', 'overview', 'stats', 'subscriptions', 'donations',
        'projects', 'members', 'charts', 'recent', 'notifications',
    ]);
});

it('reflects seeded data in its computed figures rather than only exposing the right keys', function () {
    $president = presidentActor(); // presidentActor already creates its own Member row

    Member::factory()->count(2)->create();
    Project::factory()->create(['status' => 'draft']);
    $activeProject = Project::factory()->active()->create();

    Subscription::factory()->for($president->member)->verified()->create([
        'amount' => 300,
        'payment_date' => now(),
        'expires_at' => now()->addYear(),
    ]);
    Donation::factory()->for($activeProject)->approved()->create([
        'amount' => 500,
        'donation_date' => now(),
    ]);

    $response = $this->actingAs($president, 'sanctum')->getJson('/api/dashboard');

    $response->assertOk();

    expect($response->json('stats.members.value'))->toBe(Member::count());
    expect($response->json('stats.members.value'))->toBe(3);
    expect($response->json('overview.active_projects'))->toBe(1);
    expect($response->json('projects.total'))->toBe(2);
    expect($response->json('projects.active'))->toBe(1);
    expect($response->json('projects.in_setup'))->toBe(1);
    expect($response->json('subscriptions.verified'))->toBe(1);
    expect((float) $response->json('subscriptions.revenue'))->toBe(300.0);
    expect($response->json('donations.total'))->toBe(1);
    expect((float) $response->json('donations.revenue'))->toBe(500.0);
    expect((float) $response->json('overview.collected_this_year'))->toBe(800.0);
});
