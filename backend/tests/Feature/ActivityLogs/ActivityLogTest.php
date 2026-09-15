<?php

use App\Models\Project;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

function createAndUpdateProjectAsPresident(TestCase $test, $president): Project
{
    $createResponse = $test->actingAs($president, 'sanctum')->postJson('/api/projects', [
        'name' => 'Project '.uniqid(),
        'start_date' => now()->toDateString(),
        'budget' => 1000,
    ]);
    $createResponse->assertCreated();
    $project = Project::findOrFail($createResponse->json('data.id'));

    $test->actingAs($president, 'sanctum')
        ->putJson("/api/projects/{$project->id}", ['budget' => 2500])
        ->assertOk();

    return $project->fresh();
}

it('lets the president see activity across multiple projects', function () {
    $president = presidentActor();

    $projectA = createAndUpdateProjectAsPresident($this, $president);
    $projectB = createAndUpdateProjectAsPresident($this, $president);

    $response = $this->actingAs($president, 'sanctum')->getJson('/api/activity-logs');

    $response->assertOk();

    $projectIds = collect($response->json('data'))
        ->pluck('project.id')
        ->filter()
        ->unique();

    expect($projectIds)->toContain($projectA->id);
    expect($projectIds)->toContain($projectB->id);
});

it('scopes a bureau role without canViewAllActivities to only their assigned projects activity', function () {
    $president = presidentActor();

    $projectA = createAndUpdateProjectAsPresident($this, $president);
    $projectB = createAndUpdateProjectAsPresident($this, $president);

    $secretary = secretaireGeneralActor();
    assignToCommittee($projectA, $secretary, 'member');

    $response = $this->actingAs($secretary, 'sanctum')->getJson('/api/activity-logs');

    $response->assertOk();

    $entries = collect($response->json('data'));
    expect($entries)->not->toBeEmpty();

    $projectIds = $entries->pluck('project.id')->filter()->unique();
    expect($projectIds)->toContain($projectA->id);
    expect($projectIds)->not->toContain($projectB->id);
});

it('denies a plain unassigned subscriber all activity log access', function () {
    $subscriber = subscriberActor();

    $this->actingAs($subscriber, 'sanctum')->getJson('/api/activity-logs')->assertStatus(403);
});

it('returns the users/projects/entities filter shape', function () {
    $president = presidentActor();
    createAndUpdateProjectAsPresident($this, $president);

    $response = $this->actingAs($president, 'sanctum')->getJson('/api/activity-logs/filters');

    $response->assertOk()
        ->assertJsonStructure([
            'users',
            'projects',
            'entities' => [
                '*' => ['key', 'label', 'statuses'],
            ],
        ]);
});

it('shows full activity detail for an entry inside the callers scope and 403s for one outside it', function () {
    $president = presidentActor();

    $projectA = createAndUpdateProjectAsPresident($this, $president);
    $projectB = createAndUpdateProjectAsPresident($this, $president);

    $secretary = secretaireGeneralActor();
    assignToCommittee($projectA, $secretary, 'member');

    $activityForA = Activity::where('subject_type', Project::class)
        ->where('subject_id', $projectA->id)
        ->firstOrFail();
    $activityForB = Activity::where('subject_type', Project::class)
        ->where('subject_id', $projectB->id)
        ->firstOrFail();

    $inScope = $this->actingAs($secretary, 'sanctum')->getJson("/api/activity-logs/{$activityForA->id}");
    $inScope->assertOk()
        ->assertJsonPath('data.id', $activityForA->id)
        ->assertJsonStructure(['data' => ['changes', 'related']]);

    $outOfScope = $this->actingAs($secretary, 'sanctum')->getJson("/api/activity-logs/{$activityForB->id}");
    $outOfScope->assertStatus(403);
});

it('rejects unauthenticated access to activity logs', function () {
    $this->getJson('/api/activity-logs')->assertStatus(401);
});
