<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectFundAllocation;
use App\Models\Subscription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Fund allocations: viewAny/create authorization, validation (including
| the FundsHelper::availableFunds() cap), and the committee_ready ->
| funding_ready auto-advance side effect on the first allocation.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake('public');
});

// Gives the central funds pool `$amount` via a verified subscription on a
// throwaway member, so a store attempt has room to succeed.
function giveAvailableFunds(float $amount): void
{
    $member = Member::factory()->create();
    Subscription::factory()->for($member)->verified()->create(['amount' => $amount]);
}

function allocationPayload(array $overrides = []): array
{
    return array_merge([
        'amount' => 500,
        'allocation_date' => now()->toDateString(),
        'proof_file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
    ], $overrides);
}

// --- viewAny (GET /projects/{project}/allocations) ---

it('allows the president, treasurer, and vice-treasurer to view allocations regardless of committee membership', function (string $actor) {
    $project = Project::factory()->committeeReady()->create();
    $user = match ($actor) {
        'president' => presidentActor(),
        'tresorier' => treasurerActor(),
        'vice-tresorier' => viceTreasurerActor(),
    };

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/projects/{$project->id}/allocations")
        ->assertOk();
})->with(['president', 'tresorier', 'vice-tresorier']);

it('allows any committee role (leader, treasurer, member) to view their own project allocations', function (string $role) {
    $project = Project::factory()->committeeReady()->create();
    $user = match ($role) {
        'leader' => committeeLeaderActor($project),
        'treasurer' => committeeTreasurerActor($project),
        'member' => committeeMemberActor($project),
    };

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/projects/{$project->id}/allocations")
        ->assertOk();
})->with(['leader', 'treasurer', 'member']);

it('forbids non-financial bureau roles from viewing allocations of a project they are not on', function () {
    $project = Project::factory()->committeeReady()->create();

    foreach ([vicePresidentActor(), secretaireGeneralActor(), viceSecretaireGeneralActor(), conseillerActor()] as $user) {
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/projects/{$project->id}/allocations")
            ->assertStatus(403);
    }
});

it('forbids a subscriber with no committee role from viewing allocations', function () {
    $project = Project::factory()->committeeReady()->create();
    $subscriber = subscriberActor();

    $this->actingAs($subscriber, 'sanctum')
        ->getJson("/api/projects/{$project->id}/allocations")
        ->assertStatus(403);
});

// --- create authorization: only president/tresorier/vice-tresorier ---

it('lets the president, treasurer, and vice-treasurer create a fund allocation on an eligible project', function (string $actor) {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $user = match ($actor) {
        'president' => presidentActor(),
        'tresorier' => treasurerActor(),
        'vice-tresorier' => viceTreasurerActor(),
    };

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload());

    $response->assertCreated()
        ->assertJsonPath('data.amount', 500)
        ->assertJsonPath('data.recorded_by', $user->name);

    $this->assertDatabaseHas('project_fund_allocations', [
        'project_id' => $project->id,
        'recorded_by' => $user->id,
    ]);
})->with(['president', 'tresorier', 'vice-tresorier']);

it('forbids a committee leader from creating a fund allocation even on their own project', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertStatus(403);

    $this->assertDatabaseCount('project_fund_allocations', 0);
});

it('forbids a committee treasurer from creating a fund allocation (unlike donations/expenses, only bureau financial roles can)', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $committeeTreasurer = committeeTreasurerActor($project);

    $this->actingAs($committeeTreasurer, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertStatus(403);
});

it('forbids a committee member from creating a fund allocation', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $member = committeeMemberActor($project);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertStatus(403);
});

it('forbids the vice-president and secretaire-general from creating a fund allocation', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();

    foreach ([vicePresidentActor(), secretaireGeneralActor()] as $user) {
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
            ->assertStatus(403);
    }
});

it('forbids a subscriber from creating a fund allocation', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $subscriber = subscriberActor();

    $this->actingAs($subscriber, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertStatus(403);
});

// --- lifecycle gate: draft project needs a committee first ---

it('denies allocating funds to a draft project with the exact "needs an assigned committee" message', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->create(['status' => 'draft']);
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertStatus(403)
        ->assertJson(['message' => 'This project needs an assigned committee before it can receive fund allocations.']);

    $this->assertDatabaseCount('project_fund_allocations', 0);
});

it('allows allocating funds to a funding_ready or active project (not just committee_ready)', function (string $state) {
    giveAvailableFunds(10000);
    $project = Project::factory()->{$state}()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertCreated();
})->with(['fundingReady', 'active']);

// --- lock: completed/cancelled projects reject allocations ---

it('denies allocating funds to a completed project with the read-only lock message', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->completed()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is completed and is now read-only.']);
});

it('denies allocating funds to a cancelled project with the read-only lock message', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->cancelled()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertStatus(403)
        ->assertJson(['message' => 'This project is cancelled and is now read-only.']);
});

// --- validation ---

it('requires amount, allocation_date, and proof_file', function () {
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount', 'allocation_date', 'proof_file']);
});

it('rejects a zero or negative amount', function (float $amount) {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['amount' => $amount]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
})->with([0, -50]);

it('rejects an amount above the max allowed value', function () {
    giveAvailableFunds(200000000);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['amount' => 100000000]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

it('rejects a non-date allocation_date', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['allocation_date' => 'not-a-date']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['allocation_date']);
});

it('rejects a proof_file with a disallowed mime type', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload([
            'proof_file' => UploadedFile::fake()->create('proof.txt', 10, 'text/plain'),
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['proof_file']);
});

it('rejects a proof_file larger than 5MB', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload([
            'proof_file' => UploadedFile::fake()->create('proof.pdf', 6000, 'application/pdf'),
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['proof_file']);
});

it('accepts a jpg or png proof_file image', function (string $extension) {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload([
            'proof_file' => UploadedFile::fake()->image("proof.{$extension}"),
        ]))
        ->assertCreated();
})->with(['jpg', 'png']);

// --- available-funds cap ---

it('rejects an allocation exceeding the available funds pool with the exact error message', function () {
    // No verified subscriptions, no prior allocations anywhere: available
    // funds is exactly 0, so any positive amount exceeds it.
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['amount' => 100]))
        ->assertStatus(422)
        ->assertJson(['message' => 'The amount exceeds the available funds.'])
        ->assertJsonValidationErrors(['amount']);

    $this->assertDatabaseCount('project_fund_allocations', 0);
});

it('allows an allocation exactly at the available funds boundary', function () {
    giveAvailableFunds(500);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['amount' => 500]))
        ->assertCreated();
});

it('rejects an allocation that exceeds available funds by even a small margin', function () {
    giveAvailableFunds(500);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['amount' => 500.01]))
        ->assertStatus(422)
        ->assertJson(['message' => 'The amount exceeds the available funds.']);
});

it('accounts for a prior allocation on another open project when computing available funds', function () {
    giveAvailableFunds(1000);
    $otherProject = Project::factory()->active()->create();
    ProjectFundAllocation::factory()->for($otherProject)->create(['amount' => 700]);

    // Only 300 left in the pool.
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['amount' => 400]))
        ->assertStatus(422)
        ->assertJson(['message' => 'The amount exceeds the available funds.']);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['amount' => 300]))
        ->assertCreated();
});

// --- side effect: first allocation on a committee_ready project advances it to funding_ready ---

it('advances a committee_ready project to funding_ready on its first fund allocation', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertCreated();

    expect($project->fresh()->status)->toBe('funding_ready');
});

it('does not change the status again on a second allocation once already funding_ready', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->committeeReady()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['amount' => 100]))
        ->assertCreated();

    expect($project->fresh()->status)->toBe('funding_ready');

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload(['amount' => 100]))
        ->assertCreated();

    // Still funding_ready — a second allocation must not push it further
    // down the lifecycle (e.g. into "active").
    expect($project->fresh()->status)->toBe('funding_ready');
});

it('does not change the status of an already-active project when it receives an allocation', function () {
    giveAvailableFunds(10000);
    $project = Project::factory()->active()->create();
    $president = presidentActor();

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/projects/{$project->id}/allocations", allocationPayload())
        ->assertCreated();

    expect($project->fresh()->status)->toBe('active');
});
