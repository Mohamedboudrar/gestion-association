<?php

use App\Models\Project;
use App\Models\Subscription;

it('lets the president assign an eligible bureau member to a committee', function () {
    $president = presidentActor();
    $target = treasurerActor();
    $project = Project::factory()->committeeReady()->create();

    $response = $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $target->member->id,
            'committee_role' => 'treasurer',
        ]);

    $response->assertOk()->assertJson(['message' => 'Member assigned successfully.']);

    $this->assertDatabaseHas('member_project', [
        'project_id' => $project->id,
        'member_id' => $target->member->id,
        'committee_role' => 'treasurer',
    ]);

    $this->assertDatabaseHas('committee_assignments', [
        'project_id' => $project->id,
        'member_id' => $target->member->id,
        'committee_role' => 'treasurer',
        'action' => 'assigned',
    ]);

    $this->assertDatabaseHas('notifications', [
        'type' => 'committee_assigned',
        'user_id' => $target->id,
    ]);
});

it('defaults committee_role to member when not provided', function () {
    $president = presidentActor();
    $target = treasurerActor();
    $project = Project::factory()->committeeReady()->create();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $target->member->id,
        ])
        ->assertOk();

    $this->assertDatabaseHas('member_project', [
        'project_id' => $project->id,
        'member_id' => $target->member->id,
        'committee_role' => 'member',
    ]);
});

it('rejects assigning a subscriber with no verified subscription, then succeeds once verified', function () {
    $president = presidentActor();
    $subscriber = subscriberActor(); // no verified subscription
    $project = Project::factory()->committeeReady()->create();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $subscriber->member->id,
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'This member must have a verified subscription to be assigned to a project committee.']);

    $this->assertDatabaseMissing('member_project', [
        'project_id' => $project->id,
        'member_id' => $subscriber->member->id,
    ]);

    Subscription::factory()->for($subscriber->member)->verified()->create();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $subscriber->member->id,
        ])
        ->assertOk();

    $this->assertDatabaseHas('member_project', [
        'project_id' => $project->id,
        'member_id' => $subscriber->member->id,
    ]);
});

it('rejects assigning a member who is already on the committee', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $member = committeeMemberActor($project);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $member->member->id,
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'This member is already assigned to this project committee.']);
});

it('auto-advances a draft project to committee_ready on its first committee assignment', function () {
    $president = presidentActor();
    $target = treasurerActor();
    $project = Project::factory()->create(['status' => 'draft']);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $target->member->id,
        ])
        ->assertOk();

    expect($project->fresh()->status)->toBe('committee_ready');
});

it('does not change status when assigning a second member to an already committee_ready project', function () {
    $president = presidentActor();
    $second = treasurerActor();
    $project = Project::factory()->committeeReady()->create();
    committeeMemberActor($project);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $second->member->id,
        ])
        ->assertOk();

    expect($project->fresh()->status)->toBe('committee_ready');
});

it('validates required member_id and the committee_role enum', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['member_id']);

    $target = treasurerActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $target->member->id,
            'committee_role' => 'not-a-real-role',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['committee_role']);
});

it('allows the vice-president to assign committee members', function () {
    $vp = vicePresidentActor();
    $target = treasurerActor();
    $project = Project::factory()->committeeReady()->create();

    $this->actingAs($vp, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $target->member->id,
        ])
        ->assertOk();
});

it('forbids non-president/vice-president roles from assigning committee members', function (string $actor) {
    $user = match ($actor) {
        'tresorier' => treasurerActor(),
        'subscriber' => subscriberActor(),
        default => null,
    };
    $target = treasurerActor();
    $project = Project::factory()->committeeReady()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $target->member->id,
        ])
        ->assertStatus(403);
})->with(['tresorier', 'subscriber']);

it('forbids a committee leader from assigning other committee members', function () {
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);
    $target = treasurerActor();

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $target->member->id,
        ])
        ->assertStatus(403);
});

it('forbids assigning committee members on a locked (completed) project', function () {
    $president = presidentActor();
    $target = treasurerActor();
    $project = Project::factory()->completed()->create();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $target->member->id,
        ])
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is completed and is now read-only.']);
});

it('forbids assigning committee members on a locked (cancelled) project', function () {
    $president = presidentActor();
    $target = treasurerActor();
    $project = Project::factory()->cancelled()->create();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members", [
            'member_id' => $target->member->id,
        ])
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is cancelled and is now read-only.']);
});
