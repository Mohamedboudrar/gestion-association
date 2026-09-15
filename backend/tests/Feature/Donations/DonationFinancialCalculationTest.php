<?php

use App\Models\Donation;
use App\Models\Project;

/*
|--------------------------------------------------------------------------
| Financial calculation correctness (Donation::FINANCIALLY_COUNTED_STATUSES)
|--------------------------------------------------------------------------
|
| Only 'approved' donations may ever count toward a project's `collected` /
| `remaining` figures (ProjectResource). Draft, pending, and rejected
| donations must never be summed in, no matter how many exist or how large
| their amounts are. This is one of the highest-priority business rules in
| the app — a bug here means the association's books are wrong.
*/

it('counts only the approved donation toward collected, ignoring draft/pending/rejected amounts on the same project', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create();

    Donation::factory()->for($project)->create(['status' => 'draft', 'amount' => 100]);
    Donation::factory()->for($project)->pending()->create(['amount' => 200]);
    Donation::factory()->for($project)->approved()->create(['amount' => 300]);
    Donation::factory()->for($project)->rejected()->create(['amount' => 400]);

    $response = $this->actingAs($president, 'sanctum')
        ->getJson("/api/projects/{$project->id}")
        ->assertOk();

    // Not the sum of all four (1000) — only the approved one (300).
    expect((float) $response->json('data.collected'))->toBe(300.0);
    expect((float) $response->json('data.remaining'))->toBe(300.0);
});

it('sums multiple approved donations together, and only those', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create();

    Donation::factory()->for($project)->approved()->create(['amount' => 150.25]);
    Donation::factory()->for($project)->approved()->create(['amount' => 249.75]);
    Donation::factory()->for($project)->pending()->create(['amount' => 10000]); // must not leak in

    $response = $this->actingAs($president, 'sanctum')
        ->getJson("/api/projects/{$project->id}")
        ->assertOk();

    expect((float) $response->json('data.collected'))->toBe(400.0);
});

it('reports zero collected for a project with only non-approved donations', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create();

    Donation::factory()->for($project)->create(['status' => 'draft', 'amount' => 500]);
    Donation::factory()->for($project)->pending()->create(['amount' => 600]);
    Donation::factory()->for($project)->rejected('receipt')->create(['amount' => 700]);
    Donation::factory()->for($project)->rejected('details')->create(['amount' => 800]);

    $response = $this->actingAs($president, 'sanctum')
        ->getJson("/api/projects/{$project->id}")
        ->assertOk();

    expect((float) $response->json('data.collected'))->toBe(0.0);
    expect((float) $response->json('data.remaining'))->toBe(0.0);
});

it('reduces remaining by financially-counted expenses but never by the amount of a merely-approved donation being deducted twice', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create();

    Donation::factory()->for($project)->approved()->create(['amount' => 1000]);

    // An approved expense on the same project should reduce remaining but not collected.
    \App\Models\Expense::factory()->for($project)->create([
        'status' => 'paid',
        'amount' => 300,
    ]);

    $response = $this->actingAs($president, 'sanctum')
        ->getJson("/api/projects/{$project->id}")
        ->assertOk();

    expect((float) $response->json('data.collected'))->toBe(1000.0);
    expect((float) $response->json('data.remaining'))->toBe(700.0);
});

it('lets a plain committee member see the aggregate collected figure via the project resource even though they cannot list donation records', function () {
    $project = Project::factory()->active()->create();
    $member = committeeMemberActor($project);

    Donation::factory()->for($project)->approved()->create(['amount' => 300]);
    Donation::factory()->for($project)->pending()->create(['amount' => 999]);

    // Denied the itemized list (see DonationAccessScopingTest for the full
    // regression coverage of this rule) ...
    $this->actingAs($member, 'sanctum')->getJson('/api/donations')->assertStatus(403);

    // ... but allowed the project's aggregate figure.
    $response = $this->actingAs($member, 'sanctum')
        ->getJson("/api/projects/{$project->id}")
        ->assertOk();

    expect((float) $response->json('data.collected'))->toBe(300.0);
});
