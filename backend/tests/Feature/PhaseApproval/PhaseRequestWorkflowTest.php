<?php

use App\Models\Project;
use App\Models\ProjectPhaseRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function fakePhaseProofs(int $count = 1): array
{
    return collect(range(1, $count))
        ->map(fn ($i) => UploadedFile::fake()->create("proof-{$i}.pdf", 100, 'application/pdf'))
        ->all();
}

// --- viewAny: GET /projects/{project}/phase-requests ---

it('lets a committee member assigned to the project view its phase-request history', function () {
    $project = Project::factory()->committeeReady()->create();
    $member = committeeMemberActor($project);

    $this->actingAs($member, 'sanctum')
        ->getJson("/api/projects/{$project->id}/phase-requests")
        ->assertOk();
});

it('lets the president and vice-president view any projects phase-request history without an assignment', function (string $actor) {
    $project = Project::factory()->committeeReady()->create();
    $user = $actor === 'president' ? presidentActor() : vicePresidentActor();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/projects/{$project->id}/phase-requests")
        ->assertOk();
})->with(['president', 'vice-president']);

it('forbids an unassigned subscriber from viewing a projects phase-request history', function () {
    $project = Project::factory()->committeeReady()->create();
    $outsider = subscriberActor();

    $this->actingAs($outsider, 'sanctum')
        ->getJson("/api/projects/{$project->id}/phase-requests")
        ->assertStatus(403);
});

// --- viewPending: GET /phase-requests/pending ---

it('lets the president and vice-president view the association-wide pending queue', function (string $actor) {
    $user = $actor === 'president' ? presidentActor() : vicePresidentActor();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/phase-requests/pending')
        ->assertOk();
})->with(['president', 'vice-president']);

it('forbids a committee leader from viewing the association-wide pending queue', function () {
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')
        ->getJson('/api/phase-requests/pending')
        ->assertStatus(403);
});

it('forbids a tresorier from viewing the association-wide pending queue', function () {
    $this->actingAs(treasurerActor(), 'sanctum')
        ->getJson('/api/phase-requests/pending')
        ->assertStatus(403);
});

// --- store: POST /projects/{project}/phase-requests ---

it('lets the committee leader submit a valid phase request, storing proofs and notifying president/vice-president', function () {
    Storage::fake('public');
    $project = Project::factory()->committeeReady()->create(); // phase = planning
    $leader = committeeLeaderActor($project);
    $president = presidentActor();
    $vp = vicePresidentActor();

    $response = $this->actingAs($leader, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [
            'to_phase' => 'preparation',
            'summary' => 'Ready to move to preparation.',
            'notes' => 'All planning docs finalized.',
            'proofs' => fakePhaseProofs(2),
        ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $response->assertJsonPath('data.status', 'pending');
    $response->assertJsonPath('data.from_phase', 'planning');
    $response->assertJsonPath('data.to_phase', 'preparation');

    $this->assertDatabaseHas('project_phase_requests', [
        'project_id' => $project->id,
        'from_phase' => 'planning',
        'to_phase' => 'preparation',
        'status' => 'pending',
        'requested_by' => $leader->id,
    ]);

    $phaseRequest = ProjectPhaseRequest::where('project_id', $project->id)->firstOrFail();
    expect($phaseRequest->proofs)->toHaveCount(2);

    foreach ($phaseRequest->proofs as $proof) {
        Storage::disk('public')->assertExists($proof->file_path);
    }

    $this->assertDatabaseHas('notifications', [
        'type' => 'phase_request_pending',
        'user_id' => $president->id,
    ]);
    $this->assertDatabaseHas('notifications', [
        'type' => 'phase_request_pending',
        'user_id' => $vp->id,
    ]);
});

it('lets the committee leader submit a phase request with no proof files at all', function () {
    Storage::fake('public');
    $project = Project::factory()->committeeReady()->create(); // phase = planning
    $leader = committeeLeaderActor($project);

    $response = $this->actingAs($leader, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [
            'to_phase' => 'preparation',
            'summary' => 'Ready to move to preparation, no proofs on hand yet.',
        ], ['Accept' => 'application/json']);

    $response->assertCreated();
    $response->assertJsonPath('data.status', 'pending');

    $phaseRequest = ProjectPhaseRequest::where('project_id', $project->id)->firstOrFail();
    expect($phaseRequest->proofs)->toHaveCount(0);
});

it('validates required fields and rejects completed as a requestable phase', function () {
    Storage::fake('public');
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to_phase', 'summary']);

    $this->actingAs($leader, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [
            'to_phase' => 'completed',
            'summary' => 'Trying to skip straight to completed.',
            'proofs' => fakePhaseProofs(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to_phase']);
});

it('rejects a second phase request while one is already pending', function () {
    Storage::fake('public');
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [
            'to_phase' => 'preparation',
            'summary' => 'First request.',
            'proofs' => fakePhaseProofs(),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $response = $this->actingAs($leader, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [
            'to_phase' => 'preparation',
            'summary' => 'Second request while first still pending.',
            'proofs' => fakePhaseProofs(),
        ], ['Accept' => 'application/json']);

    $response->assertStatus(422)->assertJsonValidationErrors(['to_phase']);
    expect($response->json('errors.to_phase.0'))->toBe('This project already has a pending phase request.');

    expect(ProjectPhaseRequest::where('project_id', $project->id)->count())->toBe(1);
});

it('rejects a request that skips ahead over an intermediate phase', function () {
    Storage::fake('public');
    $project = Project::factory()->committeeReady()->create(); // phase = planning
    $leader = committeeLeaderActor($project);

    // planning -> finishing skips preparation and in_progress.
    $this->actingAs($leader, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [
            'to_phase' => 'finishing',
            'summary' => 'Trying to skip ahead.',
            'proofs' => fakePhaseProofs(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to_phase']);

    expect(ProjectPhaseRequest::where('project_id', $project->id)->count())->toBe(0);
});

it('rejects a request that moves the phase backward', function () {
    Storage::fake('public');
    $project = Project::factory()->committeeReady()->create(['phase' => 'in_progress']);
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [
            'to_phase' => 'preparation',
            'summary' => 'Trying to move backward.',
            'proofs' => fakePhaseProofs(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to_phase']);

    expect(ProjectPhaseRequest::where('project_id', $project->id)->count())->toBe(0);
});

it('forbids non-leader committee roles, president, vice-president and outsiders from creating a phase request', function (string $actor) {
    Storage::fake('public');
    $project = Project::factory()->committeeReady()->create();
    // Ensure the project has a leader too, so non-leader actors are exercised
    // against a realistic committee, without granting them leader rights.
    committeeLeaderActor($project);

    $user = match ($actor) {
        'committee-treasurer' => committeeTreasurerActor($project),
        'committee-member' => committeeMemberActor($project),
        'president' => presidentActor(),
        'vice-president' => vicePresidentActor(),
        'subscriber' => subscriberActor(),
        default => null,
    };

    $this->actingAs($user, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [
            'to_phase' => 'preparation',
            'summary' => 'Should not be allowed.',
            'proofs' => fakePhaseProofs(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(403);
})->with(['committee-treasurer', 'committee-member', 'president', 'vice-president', 'subscriber']);

it('forbids creating a phase request on a locked project', function () {
    Storage::fake('public');
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);
    $project->update(['status' => 'completed']);

    $this->actingAs($leader, 'sanctum')
        ->post("/api/projects/{$project->id}/phase-requests", [
            'to_phase' => 'preparation',
            'summary' => 'Locked project.',
            'proofs' => fakePhaseProofs(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is completed and is now read-only.']);
});

// --- approve: POST /phase-requests/{phaseRequest}/approve ---

it('lets the president approve a pending phase request, advancing the projects actual phase and notifying the requester', function () {
    $project = Project::factory()->committeeReady()->create(); // phase = planning
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $phaseRequest = ProjectPhaseRequest::factory()->create([
        'project_id' => $project->id,
        'from_phase' => 'planning',
        'to_phase' => 'preparation',
        'requested_by' => $leader->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($president, 'sanctum')
        ->postJson("/api/phase-requests/{$phaseRequest->id}/approve");

    $response->assertOk()->assertJsonPath('data.status', 'approved');

    expect($project->fresh()->phase)->toBe('preparation');

    $this->assertDatabaseHas('project_phase_requests', [
        'id' => $phaseRequest->id,
        'status' => 'approved',
        'reviewed_by' => $president->id,
    ]);

    $this->assertDatabaseHas('notifications', [
        'type' => 'phase_request_approved',
        'user_id' => $leader->id,
    ]);
});

it('lets the vice-president approve a pending phase request', function () {
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);
    $vp = vicePresidentActor();

    $phaseRequest = ProjectPhaseRequest::factory()->create([
        'project_id' => $project->id,
        'from_phase' => 'planning',
        'to_phase' => 'preparation',
        'requested_by' => $leader->id,
        'status' => 'pending',
    ]);

    $this->actingAs($vp, 'sanctum')
        ->postJson("/api/phase-requests/{$phaseRequest->id}/approve")
        ->assertOk();

    expect($project->fresh()->phase)->toBe('preparation');
});

it('rejects approving a phase request that is not pending', function () {
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $phaseRequest = ProjectPhaseRequest::factory()->approved()->create([
        'project_id' => $project->id,
        'from_phase' => 'planning',
        'to_phase' => 'preparation',
        'requested_by' => $leader->id,
        'reviewed_by' => $president->id,
    ]);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/phase-requests/{$phaseRequest->id}/approve")
        ->assertStatus(403);

    // The phase must not have been touched by the second, denied approval.
    expect($project->fresh()->phase)->toBe('planning');
});

it('forbids non-review roles from approving a phase request', function (string $actor) {
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);

    $phaseRequest = ProjectPhaseRequest::factory()->create([
        'project_id' => $project->id,
        'from_phase' => 'planning',
        'to_phase' => 'preparation',
        'requested_by' => $leader->id,
        'status' => 'pending',
    ]);

    $user = match ($actor) {
        'tresorier' => treasurerActor(),
        'committee-leader' => $leader,
        'subscriber' => subscriberActor(),
        default => null,
    };

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/phase-requests/{$phaseRequest->id}/approve")
        ->assertStatus(403);
})->with(['tresorier', 'committee-leader', 'subscriber']);

it('forbids approving a phase request on a project that has since become locked', function () {
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $phaseRequest = ProjectPhaseRequest::factory()->create([
        'project_id' => $project->id,
        'from_phase' => 'planning',
        'to_phase' => 'preparation',
        'requested_by' => $leader->id,
        'status' => 'pending',
    ]);

    $project->update(['status' => 'cancelled']);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/phase-requests/{$phaseRequest->id}/approve")
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is cancelled and is now read-only.']);
});

// --- reject: POST /phase-requests/{phaseRequest}/reject ---

it('lets the president reject a pending phase request, leaving the projects phase unchanged, and notifies the requester', function () {
    $project = Project::factory()->committeeReady()->create(); // phase = planning
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $phaseRequest = ProjectPhaseRequest::factory()->create([
        'project_id' => $project->id,
        'from_phase' => 'planning',
        'to_phase' => 'preparation',
        'requested_by' => $leader->id,
        'status' => 'pending',
    ]);

    $response = $this->actingAs($president, 'sanctum')
        ->postJson("/api/phase-requests/{$phaseRequest->id}/reject", [
            'reason' => 'Documentation incomplete.',
        ]);

    $response->assertOk()->assertJsonPath('data.status', 'rejected');

    expect($project->fresh()->phase)->toBe('planning');

    $this->assertDatabaseHas('project_phase_requests', [
        'id' => $phaseRequest->id,
        'status' => 'rejected',
        'rejection_reason' => 'Documentation incomplete.',
        'reviewed_by' => $president->id,
    ]);

    $this->assertDatabaseHas('notifications', [
        'type' => 'phase_request_rejected',
        'user_id' => $leader->id,
    ]);
});

it('requires a reason to reject a phase request', function () {
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $phaseRequest = ProjectPhaseRequest::factory()->create([
        'project_id' => $project->id,
        'from_phase' => 'planning',
        'to_phase' => 'preparation',
        'requested_by' => $leader->id,
        'status' => 'pending',
    ]);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/phase-requests/{$phaseRequest->id}/reject", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    expect($phaseRequest->fresh()->status)->toBe('pending');
});

// --- full progression: planning -> preparation -> in_progress -> finishing ---

it('progresses a project through every phase one step at a time, keeping progress_percentage in sync', function () {
    Storage::fake('public');
    $project = Project::factory()->committeeReady()->create(); // phase = planning
    $leader = committeeLeaderActor($project);
    $president = presidentActor();

    $steps = [
        ['to' => 'preparation', 'progress' => 25],
        ['to' => 'in_progress', 'progress' => 50],
        ['to' => 'finishing', 'progress' => 75],
    ];

    foreach ($steps as $step) {
        $createResponse = $this->actingAs($leader, 'sanctum')
            ->post("/api/projects/{$project->id}/phase-requests", [
                'to_phase' => $step['to'],
                'summary' => "Moving to {$step['to']}.",
                'proofs' => fakePhaseProofs(),
            ], ['Accept' => 'application/json']);

        $createResponse->assertCreated();
        $phaseRequestId = $createResponse->json('data.id');

        $this->actingAs($president, 'sanctum')
            ->postJson("/api/phase-requests/{$phaseRequestId}/approve")
            ->assertOk();

        expect($project->fresh()->phase)->toBe($step['to']);

        $projectResponse = $this->actingAs($president, 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk();

        expect($projectResponse->json('data.phase'))->toBe($step['to']);
        expect($projectResponse->json('data.progress_percentage'))->toBe($step['progress']);
    }
});
