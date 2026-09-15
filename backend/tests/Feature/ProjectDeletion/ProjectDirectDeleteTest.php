<?php

use App\Models\Project;

/*
|--------------------------------------------------------------------------
| Project deletion — Path 1: president direct delete (DELETE /projects/{id})
|--------------------------------------------------------------------------
|
| ProjectPolicy::delete = hasRole('president') only, gated by the same
| lock check every mutating project action uses. A real hard delete via
| ProjectDeletionService, cascading to every child table at the DB level.
*/

it('lets the president directly delete a draft project', function () {
    $project = Project::factory()->create(['status' => 'draft']);
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}")
        ->assertOk()
        ->assertJson(['message' => 'Project deleted successfully.']);

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
});

it('lets the president directly delete a committee_ready, funding_ready, or active project', function (string $state) {
    $project = Project::factory()->{$state}()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}")
        ->assertOk();

    $this->assertDatabaseMissing('projects', ['id' => $project->id]);
})->with(['committeeReady', 'fundingReady', 'active']);

it('forbids the president from directly deleting a completed project', function () {
    $project = Project::factory()->completed()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}")
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is completed and is now read-only.']);

    $this->assertDatabaseHas('projects', ['id' => $project->id]);
});

it('forbids the president from directly deleting a cancelled project', function () {
    $project = Project::factory()->cancelled()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}")
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is cancelled and is now read-only.']);

    $this->assertDatabaseHas('projects', ['id' => $project->id]);
});

it('forbids the vice-president from directly deleting a project', function () {
    $project = Project::factory()->active()->create();
    $vicePresident = vicePresidentActor();

    $this->actingAs($vicePresident, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}")
        ->assertStatus(403);

    $this->assertDatabaseHas('projects', ['id' => $project->id]);
});

it('forbids the treasurer from directly deleting a project', function () {
    $project = Project::factory()->active()->create();
    $treasurer = treasurerActor();

    $this->actingAs($treasurer, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}")
        ->assertStatus(403);

    $this->assertDatabaseHas('projects', ['id' => $project->id]);
});

it('forbids a committee leader from directly deleting their own project', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}")
        ->assertStatus(403);

    $this->assertDatabaseHas('projects', ['id' => $project->id]);
});

it('forbids a subscriber from directly deleting a project', function () {
    $project = Project::factory()->active()->create();
    $subscriber = subscriberActor();

    $this->actingAs($subscriber, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}")
        ->assertStatus(403);
});

it('forbids an unauthenticated request from deleting a project', function () {
    $project = Project::factory()->active()->create();

    $this->deleteJson("/api/projects/{$project->id}")->assertStatus(401);

    $this->assertDatabaseHas('projects', ['id' => $project->id]);
});
