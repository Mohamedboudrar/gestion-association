<?php

use App\Models\Project;

it('always forces a new project to draft/planning regardless of what the payload sends', function () {
    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->postJson('/api/projects', [
        'name' => 'Well Construction',
        'start_date' => '2026-01-01',
        'budget' => 5000,
        'status' => 'active',
        'phase' => 'completed',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.phase', 'planning');

    $this->assertDatabaseHas('projects', ['name' => 'Well Construction', 'status' => 'draft', 'phase' => 'planning']);
});

it('only president and vice-president may create a project', function () {
    $payload = ['name' => 'X', 'start_date' => '2026-01-01', 'budget' => 1000];

    $this->actingAs(presidentActor(), 'sanctum')->postJson('/api/projects', $payload)->assertCreated();
    $this->actingAs(vicePresidentActor(), 'sanctum')->postJson('/api/projects', array_merge($payload, ['name' => 'Y']))->assertCreated();

    foreach (['treasurerActor', 'viceTreasurerActor', 'secretaireGeneralActor', 'conseillerActor'] as $factory) {
        $this->actingAs($factory(), 'sanctum')->postJson('/api/projects', $payload)->assertStatus(403);
    }

    $project = Project::factory()->create();
    $this->actingAs(committeeLeaderActor($project), 'sanctum')->postJson('/api/projects', $payload)->assertStatus(403);
    $this->actingAs(subscriberActor(), 'sanctum')->postJson('/api/projects', $payload)->assertStatus(403);
});

it('validates required project fields and rejects an end date before the start date', function () {
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')->postJson('/api/projects', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'start_date', 'budget']);

    $this->actingAs($president, 'sanctum')->postJson('/api/projects', [
        'name' => 'X', 'start_date' => '2026-06-01', 'end_date' => '2026-01-01', 'budget' => 1000,
    ])->assertStatus(422)->assertJsonValidationErrors(['end_date']);
});

it('rejects a manual status transition that is not the single allowed cancellation path', function () {
    $president = presidentActor();
    $project = Project::factory()->create(['status' => 'draft']);

    // draft -> active is never allowed directly, only via committee/funding/start.
    $this->actingAs($president, 'sanctum')
        ->putJson("/api/projects/{$project->id}", ['status' => 'active'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($project->fresh()->status)->toBe('draft');
});

it('allows cancelling a non-terminal project via the generic update endpoint', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create();

    $this->actingAs($president, 'sanctum')
        ->putJson("/api/projects/{$project->id}", ['status' => 'cancelled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('requires funding_ready status before a project can be started, and only president or committee leader may start it', function () {
    $president = presidentActor();
    $draft = Project::factory()->create(['status' => 'draft']);

    $this->actingAs($president, 'sanctum')->postJson("/api/projects/{$draft->id}/start")
        ->assertStatus(403);

    $fundingReady = Project::factory()->fundingReady()->create();
    $leader = committeeLeaderActor($fundingReady);

    $this->actingAs($leader, 'sanctum')->postJson("/api/projects/{$fundingReady->id}/start")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('forbids a plain committee member (not leader) from starting or closing a project', function () {
    $fundingReady = Project::factory()->fundingReady()->create();
    $member = committeeMemberActor($fundingReady);

    $this->actingAs($member, 'sanctum')->postJson("/api/projects/{$fundingReady->id}/start")->assertStatus(403);

    $active = Project::factory()->active()->create();
    $activeMember = committeeMemberActor($active);
    $this->actingAs($activeMember, 'sanctum')->postJson("/api/projects/{$active->id}/close")->assertStatus(403);
});

it('requires active status before a project can be closed', function () {
    $president = presidentActor();
    $fundingReady = Project::factory()->fundingReady()->create();

    $this->actingAs($president, 'sanctum')->postJson("/api/projects/{$fundingReady->id}/close")
        ->assertStatus(403);
});

it('closing a project sets status and phase to completed, only counts approved/paid money, and generates a report', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create(['budget' => 10000]);

    \App\Models\Donation::factory()->for($project)->create(['status' => 'draft', 'amount' => 1000]);
    \App\Models\Donation::factory()->for($project)->create(['status' => 'pending', 'amount' => 2000]);
    \App\Models\Donation::factory()->for($project)->approved()->create(['amount' => 3000]);
    \App\Models\Donation::factory()->for($project)->rejected()->create(['amount' => 4000]);

    \App\Models\Expense::factory()->for($project)->create(['status' => 'draft', 'amount' => 100]);
    \App\Models\Expense::factory()->for($project)->approved()->create(['amount' => 500]);
    \App\Models\Expense::factory()->for($project)->paid()->create(['amount' => 700]);
    \App\Models\Expense::factory()->for($project)->rejected()->create(['amount' => 900]);

    $response = $this->actingAs($president, 'sanctum')->postJson("/api/projects/{$project->id}/close");

    $response->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.phase', 'completed');

    $report = \App\Models\ProjectReport::where('project_id', $project->id)->firstOrFail();

    // Only the approved donation (3000) and approved+paid expenses (500+700=1200) count.
    // summary is a JSON-cast array — a whole-number float round-trips through
    // json_encode/decode as a plain int, so compare after casting to float.
    expect((float) $report->summary['donations_total'])->toBe(3000.0);
    expect((float) $report->summary['expenses_total'])->toBe(1200.0);
});

it('closing a project dissolves the committee and notifies committee members and bureau roles', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $member = committeeMemberActor($project);

    $this->actingAs($president, 'sanctum')->postJson("/api/projects/{$project->id}/close")->assertOk();

    $this->assertDatabaseHas('committee_assignments', [
        'project_id' => $project->id, 'member_id' => $leader->member->id, 'action' => 'dissolved',
    ]);
    $this->assertDatabaseHas('committee_assignments', [
        'project_id' => $project->id, 'member_id' => $member->member->id, 'action' => 'dissolved',
    ]);
    $this->assertDatabaseMissing('member_project', ['project_id' => $project->id]);

    $this->assertDatabaseHas('notifications', ['user_id' => $leader->id, 'type' => 'project_closed']);
    $this->assertDatabaseHas('notifications', ['user_id' => $leader->id, 'type' => 'project_completed']);
    $this->assertDatabaseHas('notifications', ['user_id' => $leader->id, 'type' => 'project_report_generated']);
    $this->assertDatabaseHas('notifications', ['user_id' => $president->id, 'type' => 'project_closed']);
});

it('cancelling a project via the generic update endpoint also dissolves its committee', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);

    $this->actingAs($president, 'sanctum')->putJson("/api/projects/{$project->id}", ['status' => 'cancelled'])->assertOk();

    $this->assertDatabaseHas('committee_assignments', [
        'project_id' => $project->id, 'member_id' => $leader->member->id, 'action' => 'dissolved',
    ]);
    $this->assertDatabaseMissing('member_project', ['project_id' => $project->id]);
});

it('cannot close an already-completed project a second time', function () {
    $president = presidentActor();
    $project = Project::factory()->completed()->create();

    $this->actingAs($president, 'sanctum')->postJson("/api/projects/{$project->id}/close")->assertStatus(403);
});
