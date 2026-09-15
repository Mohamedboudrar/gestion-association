<?php

use App\Models\Project;

it('lets bureau roles see every project regardless of committee assignment', function () {
    Project::factory()->count(3)->create();

    $response = $this->actingAs(presidentActor(), 'sanctum')->getJson('/api/projects');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('scopes a plain subscriber to only the projects they are an actual committee member of', function () {
    $assigned = Project::factory()->create(['name' => 'Assigned Project']);
    Project::factory()->create(['name' => 'Unassigned Project']);

    $subscriber = committeeMemberActor($assigned);

    $response = $this->actingAs($subscriber, 'sanctum')->getJson('/api/projects');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toEqual(collect(['Assigned Project']));
});

it('returns an empty list (not a 403) for a subscriber with zero committee assignments', function () {
    // ProjectPolicy::viewAny only requires an authenticated member profile
    // (or bureau membership) — the controller itself already scopes a plain
    // subscriber to their own committee assignments and returns an empty
    // collection when there are none, so "no projects yet" must be a clean
    // 200 with an empty list, not a 403 before that logic ever runs.
    $subscriber = subscriberActor();
    Project::factory()->count(2)->create();

    $response = $this->actingAs($subscriber, 'sanctum')->getJson('/api/projects');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

it('lets a committee-assigned subscriber view their own projects detail page but 403s on an unrelated one', function () {
    $assigned = Project::factory()->create();
    $unrelated = Project::factory()->create();
    $member = committeeMemberActor($assigned);

    $this->actingAs($member, 'sanctum')->getJson("/api/projects/{$assigned->id}")->assertOk();
    $this->actingAs($member, 'sanctum')->getJson("/api/projects/{$unrelated->id}")->assertStatus(403);
});

it('lets every bureau role view any project detail', function () {
    $project = Project::factory()->create();

    foreach (['presidentActor', 'vicePresidentActor', 'treasurerActor', 'viceTreasurerActor', 'secretaireGeneralActor', 'viceSecretaireGeneralActor', 'conseillerActor'] as $factory) {
        $this->actingAs($factory(), 'sanctum')->getJson("/api/projects/{$project->id}")->assertOk();
    }
});

it('only president may delete a project directly, and only while it is not locked', function () {
    $project = Project::factory()->active()->create();

    $this->actingAs(vicePresidentActor(), 'sanctum')->deleteJson("/api/projects/{$project->id}")->assertStatus(403);
    $this->actingAs(treasurerActor(), 'sanctum')->deleteJson("/api/projects/{$project->id}")->assertStatus(403);

    $leader = committeeLeaderActor($project);
    $this->actingAs($leader, 'sanctum')->deleteJson("/api/projects/{$project->id}")->assertStatus(403);

    $this->actingAs(presidentActor(), 'sanctum')->deleteJson("/api/projects/{$project->id}")->assertOk();
    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
});

it('never allows deleting a completed or cancelled project, even for the president', function () {
    $completed = Project::factory()->completed()->create();
    $cancelled = Project::factory()->cancelled()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')->deleteJson("/api/projects/{$completed->id}")
        ->assertStatus(403)
        ->assertJson(['message' => "This project is completed and is now read-only."]);

    $this->actingAs($president, 'sanctum')->deleteJson("/api/projects/{$cancelled->id}")
        ->assertStatus(403)
        ->assertJson(['message' => "This project is cancelled and is now read-only."]);

    $this->assertDatabaseHas('projects', ['id' => $completed->id]);
    $this->assertDatabaseHas('projects', ['id' => $cancelled->id]);
});

it('lets president, vice-president, or the committee leader update a project, and denies everyone else', function () {
    $project = Project::factory()->create();
    $leader = committeeLeaderActor($project);
    $member = committeeMemberActor($project);

    $this->actingAs(presidentActor(), 'sanctum')->putJson("/api/projects/{$project->id}", ['name' => 'A'])->assertOk();
    $this->actingAs(vicePresidentActor(), 'sanctum')->putJson("/api/projects/{$project->id}", ['name' => 'B'])->assertOk();
    $this->actingAs($leader, 'sanctum')->putJson("/api/projects/{$project->id}", ['name' => 'C'])->assertOk();

    $this->actingAs($member, 'sanctum')->putJson("/api/projects/{$project->id}", ['name' => 'D'])->assertStatus(403);
    $this->actingAs(treasurerActor(), 'sanctum')->putJson("/api/projects/{$project->id}", ['name' => 'E'])->assertStatus(403);
});
