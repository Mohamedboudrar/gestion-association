<?php

use App\Models\Notification;
use App\Models\Project;

/*
|--------------------------------------------------------------------------
| Project deletion — Path 2: committee leader requests, president reviews
|--------------------------------------------------------------------------
|
| POST /projects/{project}/deletion-requests        -> committee leader only
| GET  /deletion-requests/pending                    -> president only
| POST /deletion-requests/{id}/approve                -> president only, hard-deletes
| POST /deletion-requests/{id}/reject                 -> president only
*/

// --- create (POST /projects/{project}/deletion-requests) ---

it('lets the committee leader request deletion of their project', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    // notifyRoles(['president']) only inserts rows for existing president
    // users — one must exist for the notification side effect to fire.
    $president = presidentActor();

    $response = $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'This project is no longer viable.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.project_name', $project->name);

    $this->assertDatabaseHas('project_deletion_requests', [
        'project_id' => $project->id,
        'project_name' => $project->name,
        'reason' => 'This project is no longer viable.',
        'requested_by' => $leader->id,
        'status' => 'pending',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'description' => 'Project deletion requested.',
    ]);

    $this->assertDatabaseHas('notifications', [
        'type' => 'project_deletion_pending',
        'user_id' => $president->id,
    ]);
});

it('notifies every president when a deletion is requested', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'No longer needed.',
        ])->assertCreated();

    expect(
        Notification::where('user_id', $president->id)
            ->where('type', 'project_deletion_pending')
            ->exists()
    )->toBeTrue();
});

it('forbids the president from requesting deletion of a project (only the committee leader can)', function () {
    $project = Project::factory()->active()->create();
    // President must exist as a committee-less bureau actor here — they
    // are never the project's committee leader.
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'Testing.',
        ])->assertStatus(403);

    $this->assertDatabaseCount('project_deletion_requests', 0);
});

it('forbids the committee treasurer from requesting deletion', function () {
    $project = Project::factory()->active()->create();
    $treasurer = committeeTreasurerActor($project);

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'Testing.',
        ])->assertStatus(403);
});

it('forbids an ordinary committee member from requesting deletion', function () {
    $project = Project::factory()->active()->create();
    $member = committeeMemberActor($project);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'Testing.',
        ])->assertStatus(403);
});

it('forbids the vice-president from requesting deletion even though they can create/update projects', function () {
    $project = Project::factory()->active()->create();
    $vicePresident = vicePresidentActor();

    $this->actingAs($vicePresident, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'Testing.',
        ])->assertStatus(403);
});

it('forbids a subscriber with no committee role from requesting deletion', function () {
    $project = Project::factory()->active()->create();
    $subscriber = subscriberActor();

    $this->actingAs($subscriber, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'Testing.',
        ])->assertStatus(403);
});

it('requires a reason to request deletion', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('rejects a second deletion request while one is already pending', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'First request.',
        ])->assertCreated();

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'Second request.',
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'This project already has a pending deletion request.']);

    $this->assertDatabaseCount('project_deletion_requests', 1);
});

it('denies a deletion request on an already-locked (completed) project even from its former committee leader', function () {
    // Attach a live committee leader directly to a completed project (bypassing
    // the normal close() flow, which would have dissolved it) so the lock
    // check is exercised in isolation, matching how the policy is written:
    // the lock gate runs before the committee-leader role check.
    $project = Project::factory()->completed()->create();
    $leader = subscriberActor();
    assignToCommittee($project, $leader, 'leader');

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'Testing.',
        ])
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is completed and is now read-only.']);
});

it('denies a deletion request on an already-locked (cancelled) project', function () {
    $project = Project::factory()->cancelled()->create();
    $leader = subscriberActor();
    assignToCommittee($project, $leader, 'leader');

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", [
            'reason' => 'Testing.',
        ])
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is cancelled and is now read-only.']);
});

// --- pending (GET /deletion-requests/pending) ---

it('lets the president list the association-wide pending deletion queue', function () {
    $projectA = Project::factory()->active()->create();
    $leaderA = committeeLeaderActor($projectA);
    $this->actingAs($leaderA, 'sanctum')
        ->postJson("/api/projects/{$projectA->id}/deletion-requests", ['reason' => 'A'])
        ->assertCreated();

    $president = presidentActor();

    $response = $this->actingAs($president, 'sanctum')->getJson('/api/deletion-requests/pending');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('forbids a committee leader from listing the pending deletion queue', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')
        ->getJson('/api/deletion-requests/pending')
        ->assertStatus(403);
});

it('forbids the vice-president from listing the pending deletion queue', function () {
    $vicePresident = vicePresidentActor();

    $this->actingAs($vicePresident, 'sanctum')
        ->getJson('/api/deletion-requests/pending')
        ->assertStatus(403);
});

it('forbids the treasurer from listing the pending deletion queue', function () {
    $treasurer = treasurerActor();

    $this->actingAs($treasurer, 'sanctum')
        ->getJson('/api/deletion-requests/pending')
        ->assertStatus(403);
});

// --- approve (POST /deletion-requests/{id}/approve) ---

it('lets the president approve a deletion request, hard-deleting the project while the request survives with a null project_id', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $createResponse = $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", ['reason' => 'Obsolete.'])
        ->assertCreated();

    $deletionRequestId = $createResponse->json('data.id');

    $response = $this->actingAs($president, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/approve");

    $response->assertOk()->assertJsonPath('data.status', 'approved');

    // The project itself is really gone.
    $this->assertDatabaseMissing('projects', ['id' => $project->id]);

    // But the request row survives, snapshot intact, project_id nulled out.
    $this->assertDatabaseHas('project_deletion_requests', [
        'id' => $deletionRequestId,
        'project_id' => null,
        'project_name' => $project->name,
        'status' => 'approved',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'description' => 'Project deletion approved.',
    ]);

    expect(
        Notification::where('user_id', $leader->id)
            ->where('type', 'project_deletion_approved')
            ->where('message', 'like', "%{$project->name}%")
            ->exists()
    )->toBeTrue();
});

it('forbids the vice-president from approving a deletion request even though they can approve other project actions', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $vicePresident = vicePresidentActor();

    $deletionRequestId = $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", ['reason' => 'Obsolete.'])
        ->json('data.id');

    $this->actingAs($vicePresident, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/approve")
        ->assertStatus(403);

    $this->assertDatabaseHas('projects', ['id' => $project->id]);
});

it('forbids the treasurer from approving a deletion request', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $treasurer = treasurerActor();

    $deletionRequestId = $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", ['reason' => 'Obsolete.'])
        ->json('data.id');

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/approve")
        ->assertStatus(403);

    $this->assertDatabaseHas('projects', ['id' => $project->id]);
});

it('rejects approving the same deletion request twice', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $deletionRequestId = $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", ['reason' => 'Obsolete.'])
        ->json('data.id');

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/approve")
        ->assertOk();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/approve")
        ->assertStatus(403);
});

// --- reject (POST /deletion-requests/{id}/reject) ---

it('lets the president reject a deletion request, leaving the project intact', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $deletionRequestId = $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", ['reason' => 'Obsolete.'])
        ->json('data.id');

    $response = $this->actingAs($president, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/reject", [
            'reason' => 'The project is still needed.',
        ]);

    $response->assertOk()->assertJsonPath('data.status', 'rejected');

    $this->assertDatabaseHas('projects', ['id' => $project->id]);

    $this->assertDatabaseHas('project_deletion_requests', [
        'id' => $deletionRequestId,
        'status' => 'rejected',
        'rejection_reason' => 'The project is still needed.',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'description' => 'Project deletion rejected.',
    ]);

    expect(
        Notification::where('user_id', $leader->id)
            ->where('type', 'project_deletion_rejected')
            ->exists()
    )->toBeTrue();
});

it('requires a reason to reject a deletion request', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $deletionRequestId = $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", ['reason' => 'Obsolete.'])
        ->json('data.id');

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/reject", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->assertDatabaseHas('project_deletion_requests', [
        'id' => $deletionRequestId,
        'status' => 'pending',
    ]);
});

it('forbids the vice-president from rejecting a deletion request', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $vicePresident = vicePresidentActor();

    $deletionRequestId = $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", ['reason' => 'Obsolete.'])
        ->json('data.id');

    $this->actingAs($vicePresident, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/reject", [
            'reason' => 'Not your call.',
        ])
        ->assertStatus(403);
});

it('rejects reviewing (approve or reject) a deletion request that was already rejected', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $deletionRequestId = $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/deletion-requests", ['reason' => 'Obsolete.'])
        ->json('data.id');

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/reject", ['reason' => 'No.'])
        ->assertOk();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/deletion-requests/{$deletionRequestId}/approve")
        ->assertStatus(403);
});
