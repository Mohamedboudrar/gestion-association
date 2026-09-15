<?php

use App\Models\Donation;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectDeletionRequest;
use App\Models\ProjectPhaseRequest;
use App\Models\Subscription;

it('rejects unauthenticated access', function () {
    $this->getJson('/api/action-center')->assertStatus(401);
});

it('is president-only, matching the existing frontend-only gating this replaces', function () {
    $treasurer = treasurerActor();

    $this->actingAs($treasurer, 'sanctum')->getJson('/api/action-center')->assertStatus(403);
});

it('returns only pending items across all five categories, never other statuses', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create();

    Subscription::factory()->create(['status' => 'pending']);
    Subscription::factory()->verified()->create();

    Expense::factory()->for($project)->pending()->create();
    Expense::factory()->for($project)->approved()->create();

    Donation::factory()->for($project)->pending()->create();
    Donation::factory()->for($project)->approved()->create();

    ProjectPhaseRequest::factory()->for($project)->create(['status' => 'pending']);
    ProjectPhaseRequest::factory()->for($project)->approved()->create();

    ProjectDeletionRequest::factory()->for($project)->create(['status' => 'pending']);
    ProjectDeletionRequest::factory()->for($project)->create(['status' => 'rejected']);

    $response = $this->actingAs($president, 'sanctum')->getJson('/api/action-center');

    $response->assertOk();
    expect($response->json('counts.subscriptions'))->toBe(1);
    expect($response->json('counts.expenses'))->toBe(1);
    expect($response->json('counts.donations'))->toBe(1);
    expect($response->json('counts.phase_requests'))->toBe(1);
    expect($response->json('counts.deletion_requests'))->toBe(1);
    expect($response->json('items.subscriptions'))->toHaveCount(1);
    expect($response->json('items.expenses'))->toHaveCount(1);
    expect($response->json('items.donations'))->toHaveCount(1);
    expect($response->json('items.phase_requests'))->toHaveCount(1);
    expect($response->json('items.deletion_requests'))->toHaveCount(1);
});
