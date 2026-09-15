<?php

use App\Models\Donation;
use App\Models\Project;

/*
|--------------------------------------------------------------------------
| DonationPolicy authorization matrix + DonationController::index scoping
|--------------------------------------------------------------------------
|
| The single most important rule covered here (see the dedicated test below)
| is that a *plain* committee member — someone assigned to a project's
| committee but not as its leader/treasurer — must never be able to list or
| view individual donation records (donor names, amounts, receipts). They
| may only see a project's aggregate `collected` figure via GET /projects/{id}
| (covered in DonationFinancialCalculationTest). This was a real regression
| in a prior session; do not weaken this test.
*/

// Every bureau role except president/tresorier/vice-tresorier — used to
// confirm they hold `donations.view` (so viewAny passes) but are NOT
// financial-oversight and NOT automatically leader/treasurer anywhere.
function nonApprovalBureauActors(): array
{
    return [
        'vice-president' => vicePresidentActor(),
        'secretaire-general' => secretaireGeneralActor(),
        'vice-secretaire-general' => viceSecretaireGeneralActor(),
        'conseiller' => conseillerActor(),
    ];
}

// ---------------------------------------------------------------------
// viewAny / GET /api/donations — base authorization
// ---------------------------------------------------------------------

it('allows every bureau role to list donations via the donations.view permission, even with zero project assignments', function () {
    foreach ([
        presidentActor(), treasurerActor(), viceTreasurerActor(), vicePresidentActor(),
        secretaireGeneralActor(), viceSecretaireGeneralActor(), conseillerActor(),
    ] as $actor) {
        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/donations')
            ->assertOk();
    }
});

it('denies a plain subscriber with no committee role and no permission from listing donations', function () {
    $subscriber = subscriberActor();

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/donations')
        ->assertStatus(403);
});

it('allows a plain subscriber who is a committee leader/treasurer to list donations via hasFinancialCommitteeRole, without the donations.view permission', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $treasurer = committeeTreasurerActor($project);

    expect($leader->can('donations.view'))->toBeFalse();
    expect($treasurer->can('donations.view'))->toBeFalse();

    $this->actingAs($leader, 'sanctum')->getJson('/api/donations')->assertOk();
    $this->actingAs($treasurer, 'sanctum')->getJson('/api/donations')->assertOk();
});

// ---------------------------------------------------------------------
// THE critical regression test: committee member vs committee leader/treasurer
// ---------------------------------------------------------------------

it('denies a plain committee member (not leader/treasurer) all donation record access on their own project', function () {
    $project = Project::factory()->active()->create();
    $member = committeeMemberActor($project);

    $donation = Donation::factory()->for($project)->approved()->create([
        'donor_name' => 'Secret Donor',
        'amount' => 777,
    ]);

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/donations')
        ->assertStatus(403);

    $this->actingAs($member, 'sanctum')
        ->getJson("/api/donations?project_id={$project->id}")
        ->assertStatus(403);

    $this->actingAs($member, 'sanctum')
        ->getJson("/api/donations/{$donation->id}")
        ->assertStatus(403);
});

it('allows a committee leader/treasurer on the same project full donor-name-visible donation access', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $treasurer = committeeTreasurerActor($project);

    $donation = Donation::factory()->for($project)->approved()->create([
        'donor_name' => 'Visible Donor',
        'amount' => 777,
    ]);

    foreach ([$leader, $treasurer] as $actor) {
        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/donations')
            ->assertOk()
            ->assertJsonFragment(['donor_name' => 'Visible Donor']);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/donations?project_id={$project->id}")
            ->assertOk()
            ->assertJsonFragment(['donor_name' => 'Visible Donor']);

        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/donations/{$donation->id}")
            ->assertOk()
            ->assertJsonPath('data.donor_name', 'Visible Donor');
    }
});

it('denies a non-financial-oversight bureau role from viewing an individual donation on a project they hold no committee role on', function () {
    $project = Project::factory()->active()->create();
    $donation = Donation::factory()->for($project)->approved()->create();

    foreach (nonApprovalBureauActors() as $role => $actor) {
        $this->actingAs($actor, 'sanctum')
            ->getJson("/api/donations/{$donation->id}")
            ->assertStatus(403, "expected role [{$role}] to be denied view() on an unrelated project's donation");
    }
});

// ---------------------------------------------------------------------
// index() scoping: financial oversight sees everything; everyone else is
// scoped to $member->projects()->wherePivotIn(['leader','treasurer'])
// ---------------------------------------------------------------------

it('lets president/tresorier/vice-tresorier see every donation across every project regardless of committee assignment', function () {
    $projectA = Project::factory()->active()->create();
    $projectB = Project::factory()->active()->create();
    $donationA = Donation::factory()->for($projectA)->approved()->create(['donor_name' => 'Donor A']);
    $donationB = Donation::factory()->for($projectB)->pending()->create(['donor_name' => 'Donor B']);

    foreach ([presidentActor(), treasurerActor(), viceTreasurerActor()] as $actor) {
        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/donations')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        expect($ids)->toContain($donationA->id, $donationB->id);
    }
});

it('scopes a non-financial-oversight bureau role to only projects where they personally hold leader/treasurer', function () {
    $ownProject = Project::factory()->active()->create();
    $otherProject = Project::factory()->active()->create();

    $ownDonation = Donation::factory()->for($ownProject)->approved()->create();
    $otherDonation = Donation::factory()->for($otherProject)->approved()->create();

    $vicePresident = vicePresidentActor();
    assignToCommittee($ownProject, $vicePresident, 'leader');
    // Not assigned to $otherProject at all.

    $response = $this->actingAs($vicePresident, 'sanctum')->getJson('/api/donations')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($ownDonation->id);
    expect($ids)->not->toContain($otherDonation->id);
});

it('scopes out a project where the bureau role is only a plain committee member, not leader/treasurer', function () {
    $project = Project::factory()->active()->create();
    $donation = Donation::factory()->for($project)->approved()->create();

    $secretaireGeneral = secretaireGeneralActor();
    assignToCommittee($project, $secretaireGeneral, 'member');

    $response = $this->actingAs($secretaireGeneral, 'sanctum')->getJson('/api/donations')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->not->toContain($donation->id);
});

it('filters the scoped index by project_id within the allowed scope', function () {
    $leaderProject = Project::factory()->active()->create();
    $treasurerProject = Project::factory()->active()->create();

    $leaderDonation = Donation::factory()->for($leaderProject)->approved()->create();
    $treasurerDonation = Donation::factory()->for($treasurerProject)->approved()->create();

    $subscriber = subscriberActor();
    assignToCommittee($leaderProject, $subscriber, 'leader');
    assignToCommittee($treasurerProject, $subscriber, 'treasurer');

    $response = $this->actingAs($subscriber, 'sanctum')
        ->getJson("/api/donations?project_id={$treasurerProject->id}")
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($treasurerDonation->id);
    expect($ids)->not->toContain($leaderDonation->id);
});

// ---------------------------------------------------------------------
// create() — project lock, active-project requirement, and the
// "plain committee member cannot create, even on their own project" rule
// ---------------------------------------------------------------------

it('denies a plain committee member from creating a donation, even on the project they belong to', function () {
    $project = Project::factory()->active()->create();
    $member = committeeMemberActor($project);

    $this->actingAs($member, 'sanctum')->postJson('/api/donations', [
        'donor_name' => 'Attempted Donor',
        'project_id' => $project->id,
        'amount' => 100,
        'payment_method' => 'cash',
        'donation_date' => now()->toDateString(),
    ])->assertStatus(403);

    expect(Donation::count())->toBe(0);
});

it('denies creating a donation on a project that is not yet active', function () {
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/donations', [
        'donor_name' => 'Someone',
        'project_id' => $project->id,
        'amount' => 100,
        'payment_method' => 'cash',
        'donation_date' => now()->toDateString(),
    ]);

    $response->assertStatus(403)->assertJson(['message' => 'This project must be active before recording this.']);
});

it('denies creating a donation on a locked (completed/cancelled) project', function () {
    $project = Project::factory()->completed()->create();
    $leader = committeeLeaderActor($project);

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/donations', [
        'donor_name' => 'Someone',
        'project_id' => $project->id,
        'amount' => 100,
        'payment_method' => 'cash',
        'donation_date' => now()->toDateString(),
    ]);

    $response->assertStatus(403)->assertJson(['message' => 'This project is completed and is now read-only.']);
});

it('allows a committee leader and a committee treasurer to create donations on their active project', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $treasurer = committeeTreasurerActor($project);

    foreach ([$leader, $treasurer] as $actor) {
        $this->actingAs($actor, 'sanctum')->postJson('/api/donations', [
            'donor_name' => 'Legit Donor',
            'project_id' => $project->id,
            'amount' => 100,
            'payment_method' => 'cash',
            'donation_date' => now()->toDateString(),
        ])->assertCreated();
    }
});

it('denies a bureau role who holds donations.create permission but no committee role on the project from creating a donation there', function () {
    // Confirms DonationPolicy::create is driven purely by committeeRoleFor(),
    // not the Spatie 'donations.create' permission — a subtle but
    // intentional design point worth pinning down.
    $project = Project::factory()->active()->create();
    $vicePresident = vicePresidentActor(); // has 'donations.create' permission, no committee seat here

    expect($vicePresident->can('donations.create'))->toBeTrue();

    $this->actingAs($vicePresident, 'sanctum')->postJson('/api/donations', [
        'donor_name' => 'Someone',
        'project_id' => $project->id,
        'amount' => 100,
        'payment_method' => 'cash',
        'donation_date' => now()->toDateString(),
    ])->assertStatus(403);
});

// ---------------------------------------------------------------------
// approve() / reject() — APPROVAL_ROLES only, never a committee leader
// ---------------------------------------------------------------------

it('allows only president, tresorier, and vice-tresorier to approve a pending donation recorded by someone else', function () {
    foreach ([presidentActor(), treasurerActor(), viceTreasurerActor()] as $approver) {
        $project = Project::factory()->active()->create();
        $leader = committeeLeaderActor($project);
        $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $leader->id]);

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/donations/{$donation->id}/approve")
            ->assertOk();
    }
});

it('denies a committee leader from approving any donation, including one recorded by someone else on their own project', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $otherRecorder = committeeTreasurerActor($project);

    $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $otherRecorder->id]);

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/approve")
        ->assertStatus(403);

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/reject", [
            'reason' => 'n/a',
            'rejection_type' => 'details',
        ])
        ->assertStatus(403);

    expect($donation->fresh()->status)->toBe('pending');
});

it('denies non-approval bureau roles from approving or rejecting a pending donation', function () {
    $project = Project::factory()->active()->create();
    $recorder = committeeLeaderActor($project);

    foreach (nonApprovalBureauActors() as $role => $actor) {
        $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $recorder->id]);

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/donations/{$donation->id}/approve")
            ->assertStatus(403, "expected role [{$role}] to be denied approve()");

        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/donations/{$donation->id}/reject", [
                'reason' => 'n/a',
                'rejection_type' => 'details',
            ])
            ->assertStatus(403, "expected role [{$role}] to be denied reject()");
    }
});

it('denies approve/reject on a project that is locked (completed/cancelled), even for approval roles', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $leader->id]);

    $project->update(['status' => 'completed']);

    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/approve")
        ->assertStatus(403);
});
