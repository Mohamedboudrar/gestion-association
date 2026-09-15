<?php

use App\Models\Project;
use App\Models\Subscription;

// --- removeCommittee (DELETE /projects/{project}/members/{member}) ---

it('lets the president remove a committee member, closing the history row and notifying them', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $target = committeeMemberActor($project);

    $this->actingAs($president, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}/members/{$target->member->id}", [
            'reason' => 'No longer needed',
        ])
        ->assertOk()
        ->assertJson(['message' => 'Member removed successfully.']);

    $this->assertDatabaseMissing('member_project', [
        'project_id' => $project->id,
        'member_id' => $target->member->id,
    ]);

    $this->assertDatabaseHas('committee_assignments', [
        'project_id' => $project->id,
        'member_id' => $target->member->id,
        'action' => 'removed',
        'reason' => 'No longer needed',
    ]);

    $closed = \App\Models\CommitteeAssignment::where('project_id', $project->id)
        ->where('member_id', $target->member->id)
        ->where('action', 'removed')
        ->first();
    expect($closed->removed_at)->not->toBeNull();

    $this->assertDatabaseHas('notifications', [
        'type' => 'committee_removed',
        'user_id' => $target->id,
    ]);
});

it('forbids the vice-president from removing a committee member even though they can assign', function () {
    $vp = vicePresidentActor();
    $project = Project::factory()->committeeReady()->create();
    $target = committeeMemberActor($project);

    $this->actingAs($vp, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}/members/{$target->member->id}")
        ->assertStatus(403);
});

it('forbids other roles from removing a committee member', function (string $actor) {
    $project = Project::factory()->committeeReady()->create();
    $target = committeeMemberActor($project);

    $user = match ($actor) {
        'tresorier' => treasurerActor(),
        'subscriber' => subscriberActor(),
        'committee-leader' => committeeLeaderActor($project),
        default => null,
    };

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}/members/{$target->member->id}")
        ->assertStatus(403);
})->with(['tresorier', 'subscriber', 'committee-leader']);

it('forbids removing a committee member on a locked project', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $target = committeeMemberActor($project);
    $project->update(['status' => 'completed']);

    $this->actingAs($president, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}/members/{$target->member->id}")
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is completed and is now read-only.']);
});

// --- resignCommittee (POST /projects/{project}/members/{member}/resign) ---

it('lets a committee member resign their own seat, with no notification fired', function () {
    $project = Project::factory()->committeeReady()->create();
    $member = committeeMemberActor($project);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$member->member->id}/resign", [
            'reason' => 'Moving on',
        ])
        ->assertOk()
        ->assertJson(['message' => 'Resignation recorded successfully.']);

    $this->assertDatabaseMissing('member_project', [
        'project_id' => $project->id,
        'member_id' => $member->member->id,
    ]);

    $this->assertDatabaseHas('committee_assignments', [
        'project_id' => $project->id,
        'member_id' => $member->member->id,
        'action' => 'resigned',
        'reason' => 'Moving on',
    ]);

    $this->assertDatabaseMissing('notifications', [
        'type' => 'committee_removed',
        'user_id' => $member->id,
    ]);
});

it('forbids resigning someone elses seat, even as president (no admin bypass on this policy)', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $target = committeeMemberActor($project);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$target->member->id}/resign")
        ->assertStatus(403);

    $this->assertDatabaseHas('member_project', [
        'project_id' => $project->id,
        'member_id' => $target->member->id,
    ]);
});

it('forbids resigning a seat the acting user no longer holds', function () {
    $project = Project::factory()->committeeReady()->create();
    $member = committeeMemberActor($project);

    // First resignation succeeds and detaches the pivot.
    $this->actingAs($member, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$member->member->id}/resign")
        ->assertOk();

    // Trying again (no longer on the committee) is denied.
    $this->actingAs($member, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$member->member->id}/resign")
        ->assertStatus(403);
});

it('forbids resigning on a locked project', function () {
    $project = Project::factory()->committeeReady()->create();
    $member = committeeMemberActor($project);
    $project->update(['status' => 'cancelled']);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$member->member->id}/resign")
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is cancelled and is now read-only.']);
});

// --- replace (POST /projects/{project}/members/{member}/replace) ---

it('lets the president replace a committee member, copying pivot data and notifying both parties', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $outgoing = committeeMemberActor($project);
    $project->members()->updateExistingPivot($outgoing->member->id, [
        'role' => 'Logistics',
        'responsibility' => 'Budget lead',
    ]);
    $incoming = treasurerActor();

    $response = $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$outgoing->member->id}/replace", [
            'new_member_id' => $incoming->member->id,
        ]);

    $response->assertOk()->assertJson(['message' => 'Member replaced successfully.']);

    $this->assertDatabaseMissing('member_project', [
        'project_id' => $project->id,
        'member_id' => $outgoing->member->id,
    ]);

    $this->assertDatabaseHas('member_project', [
        'project_id' => $project->id,
        'member_id' => $incoming->member->id,
        'role' => 'Logistics',
        'committee_role' => 'member',
        'responsibility' => 'Budget lead',
    ]);

    $this->assertDatabaseHas('committee_assignments', [
        'project_id' => $project->id,
        'member_id' => $outgoing->member->id,
        'action' => 'replaced',
    ]);

    $this->assertDatabaseHas('committee_assignments', [
        'project_id' => $project->id,
        'member_id' => $incoming->member->id,
        'action' => 'assigned',
        'role' => 'Logistics',
        'responsibility' => 'Budget lead',
    ]);

    $this->assertDatabaseHas('notifications', [
        'type' => 'committee_removed',
        'user_id' => $outgoing->id,
    ]);

    $this->assertDatabaseHas('notifications', [
        'type' => 'committee_assigned',
        'user_id' => $incoming->id,
    ]);
});

it('rejects replace when the outgoing member is not currently assigned to the committee', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $notAssigned = treasurerActor();
    $incoming = treasurerActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$notAssigned->member->id}/replace", [
            'new_member_id' => $incoming->member->id,
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'This member is not currently assigned to this project committee.']);
});

it('rejects replace when new_member_id equals the outgoing member id', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $outgoing = committeeMemberActor($project);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$outgoing->member->id}/replace", [
            'new_member_id' => $outgoing->member->id,
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'Choose a different member to replace with.']);
});

it('rejects replace when the new member is already assigned to the committee', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $outgoing = committeeMemberActor($project);
    $alreadyOnCommittee = committeeTreasurerActor($project);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$outgoing->member->id}/replace", [
            'new_member_id' => $alreadyOnCommittee->member->id,
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'The replacement member is already assigned to this project committee.']);
});

it('rejects replace when the new member lacks bureau or verified-subscription eligibility', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $outgoing = committeeMemberActor($project);
    $ineligible = subscriberActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$outgoing->member->id}/replace", [
            'new_member_id' => $ineligible->member->id,
        ])
        ->assertStatus(422)
        ->assertJson(['message' => 'The replacement member must have a verified subscription to be assigned to a project committee.']);

    Subscription::factory()->for($ineligible->member)->verified()->create();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$outgoing->member->id}/replace", [
            'new_member_id' => $ineligible->member->id,
        ])
        ->assertOk();
});

it('allows the vice-president to replace a committee member', function () {
    $vp = vicePresidentActor();
    $project = Project::factory()->committeeReady()->create();
    $outgoing = committeeMemberActor($project);
    $incoming = treasurerActor();

    $this->actingAs($vp, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$outgoing->member->id}/replace", [
            'new_member_id' => $incoming->member->id,
        ])
        ->assertOk();
});

it('forbids other roles from replacing a committee member', function (string $actor) {
    $project = Project::factory()->committeeReady()->create();
    $outgoing = committeeMemberActor($project);
    $incoming = treasurerActor();

    $user = match ($actor) {
        'tresorier' => treasurerActor(),
        'subscriber' => subscriberActor(),
        'committee-leader' => committeeLeaderActor($project),
        default => null,
    };

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$outgoing->member->id}/replace", [
            'new_member_id' => $incoming->member->id,
        ])
        ->assertStatus(403);
})->with(['tresorier', 'subscriber', 'committee-leader']);

it('forbids replacing a committee member on a locked project', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $outgoing = committeeMemberActor($project);
    $incoming = treasurerActor();
    $project->update(['status' => 'completed']);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/members/{$outgoing->member->id}/replace", [
            'new_member_id' => $incoming->member->id,
        ])
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is completed and is now read-only.']);
});
