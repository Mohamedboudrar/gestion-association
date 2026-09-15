<?php

use App\Models\Project;

// --- GET /projects/{project}/members ---

it('returns the committee roster as a raw JSON array, not wrapped in a data key', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();
    $member = committeeMemberActor($project);

    $response = $this->actingAs($president, 'sanctum')
        ->getJson("/api/projects/{$project->id}/members");

    $response->assertOk();
    $json = $response->json();

    expect($json)->toBeArray();
    expect(array_is_list($json))->toBeTrue();
    expect($json[0]['id'])->toBe($member->member->id);
    expect($json[0]['user']['id'])->toBe($member->id);
    expect($json[0]['pivot']['committee_role'])->toBe('member');
});

it('lets a committee member of the project view its own roster', function () {
    $project = Project::factory()->committeeReady()->create();
    $member = committeeMemberActor($project);

    $this->actingAs($member, 'sanctum')
        ->getJson("/api/projects/{$project->id}/members")
        ->assertOk();
});

it('forbids a subscriber who is not assigned to the project from viewing its roster', function () {
    $project = Project::factory()->committeeReady()->create();
    $outsider = subscriberActor();

    $this->actingAs($outsider, 'sanctum')
        ->getJson("/api/projects/{$project->id}/members")
        ->assertStatus(403);
});

it('lets any bureau role view a committee roster even without an assignment', function () {
    $project = Project::factory()->committeeReady()->create();
    $treasurer = treasurerActor();

    $this->actingAs($treasurer, 'sanctum')
        ->getJson("/api/projects/{$project->id}/members")
        ->assertOk();
});

// --- GET /projects/{project}/committee-history ---

it('returns the full committee assignment history ordered by most recent assigned_at first', function () {
    $president = presidentActor();
    $project = Project::factory()->committeeReady()->create();

    $first = committeeMemberActor($project);
    $this->travel(1)->hours();
    $second = committeeTreasurerActor($project);

    // Remove the first, closing its history row.
    $this->actingAs($president, 'sanctum')
        ->deleteJson("/api/projects/{$project->id}/members/{$first->member->id}", ['reason' => 'test'])
        ->assertOk();

    $response = $this->actingAs($president, 'sanctum')
        ->getJson("/api/projects/{$project->id}/committee-history");

    $response->assertOk();
    $json = $response->json();

    expect(array_is_list($json))->toBeTrue();
    expect(count($json))->toBe(2);

    // Most recently assigned (second) comes first.
    expect($json[0]['member_id'])->toBe($second->member->id);
    expect($json[1]['member_id'])->toBe($first->member->id);
    expect($json[1]['action'])->toBe('removed');
    expect($json[1]['removed_at'])->not->toBeNull();
});

it('forbids a subscriber who is not assigned to the project from viewing its committee history', function () {
    $project = Project::factory()->committeeReady()->create();
    $outsider = subscriberActor();

    $this->actingAs($outsider, 'sanctum')
        ->getJson("/api/projects/{$project->id}/committee-history")
        ->assertStatus(403);
});
