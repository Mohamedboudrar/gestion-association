<?php

use App\Models\Donation;
use App\Models\Expense;
use App\Models\Project;

/*
|--------------------------------------------------------------------------
| ExpensePolicy access scoping — deliberately broader than DonationPolicy
|--------------------------------------------------------------------------
|
| Read both policies side by side (app/Policies/ExpensePolicy.php and
| app/Policies/DonationPolicy.php):
|
| - DonationPolicy::viewAny/view use AuthorizationHelper::hasFinancialCommitteeRole()
|   and an inline `in_array($user->committeeRoleFor($project), ['leader', 'treasurer'])`
|   check — only a project's leader/treasurer (or association-wide financial
|   oversight) may list/view individual donation records. A plain committee
|   member sees only aggregate totals via ProjectResource.
|
| - ExpensePolicy::viewAny/view instead use the BROADER
|   AuthorizationHelper::hasProjectAssignments() (ANY committee role) and
|   `$user->committeeRoleFor($project) !== null` (again, any role). A plain
|   committee member CAN see expense records for their project — they just
|   cannot create/edit/submit/approve/reject/mark one paid.
|
| This asymmetry is intentional (per the task brief) and is pinned down
| explicitly below so it doesn't silently drift.
*/

it('lets a plain committee member (not leader/treasurer) view expense records for their project via index and show', function () {
    $project = Project::factory()->active()->create(['budget' => 100000]);
    $leader = committeeLeaderActor($project);
    $member = committeeMemberActor($project);

    $expense = Expense::factory()->for($project)->create(['created_by' => $leader->id]);

    $index = $this->actingAs($member, 'sanctum')->getJson('/api/expenses');
    $index->assertOk();
    expect(collect($index->json('data'))->pluck('id'))->toContain($expense->id);

    $this->actingAs($member, 'sanctum')
        ->getJson("/api/expenses/{$expense->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $expense->id);
});

it('forbids a plain committee member from creating, updating, deleting, submitting, approving, rejecting, or marking an expense paid', function () {
    $project = Project::factory()->active()->create(['budget' => 100000]);
    $leader = committeeLeaderActor($project);
    $member = committeeMemberActor($project);
    // Enough collected funds that the amount below never trips the
    // remaining-funds validation check before authorization is even
    // reached (FormRequest validation runs before the controller's
    // authorize() call).
    Donation::factory()->for($project)->approved()->create(['amount' => 100000]);

    // Create.
    $this->actingAs($member, 'sanctum')->postJson('/api/expenses', [
        'project_id' => $project->id,
        'supplier_name' => 'X',
        'description' => 'Y',
        'amount' => 50,
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
    ])->assertStatus(403);

    $draft = Expense::factory()->for($project)->create(['created_by' => $leader->id, 'status' => 'draft']);

    // Update / delete / submit on a draft.
    $this->actingAs($member, 'sanctum')
        ->putJson("/api/expenses/{$draft->id}", ['amount' => 999])
        ->assertStatus(403);

    $this->actingAs($member, 'sanctum')
        ->deleteJson("/api/expenses/{$draft->id}")
        ->assertStatus(403);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/expenses/{$draft->id}/submit")
        ->assertStatus(403);

    // Approve / reject on a pending expense.
    $pending = Expense::factory()->for($project)->pending()->create(['created_by' => $leader->id]);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/expenses/{$pending->id}/approve")
        ->assertStatus(403);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/expenses/{$pending->id}/reject", ['reason' => 'x', 'rejection_type' => 'details'])
        ->assertStatus(403);

    // Mark paid on an approved expense.
    $approved = Expense::factory()->for($project)->approved()->create(['created_by' => $leader->id]);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/expenses/{$approved->id}/mark-paid")
        ->assertStatus(403);
});

it('forbids listing expenses for a subscriber with no committee assignment and no expenses.view permission', function () {
    $subscriber = subscriberActor();

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/expenses')
        ->assertStatus(403);
});

it('forbids viewing a single expense that belongs to a project the user is not assigned to', function () {
    $projectA = Project::factory()->active()->create(['budget' => 100000]);
    $projectB = Project::factory()->active()->create(['budget' => 100000]);

    $memberOnA = committeeMemberActor($projectA);
    $leaderOnB = committeeLeaderActor($projectB);

    $expenseOnB = Expense::factory()->for($projectB)->create(['created_by' => $leaderOnB->id]);

    $this->actingAs($memberOnA, 'sanctum')
        ->getJson("/api/expenses/{$expenseOnB->id}")
        ->assertStatus(403);
});

it("scopes the index to a non-financial-oversight bureau user's own assigned projects, even though their role grants the expenses.view permission", function () {
    // secretaire-general holds the 'expenses.view' Spatie permission (see
    // RolePermissionSeeder) so viewAny() passes, but ExpenseController::index
    // still filters by the acting user's own committee assignments unless
    // they hold a FINANCIAL_OVERSIGHT_ROLE (president/tresorier/vice-tresorier).
    $secretaire = secretaireGeneralActor();
    expect($secretaire->can('expenses.view'))->toBeTrue();

    $unassignedProject = Project::factory()->active()->create(['budget' => 100000]);
    $leader = committeeLeaderActor($unassignedProject);
    Expense::factory()->for($unassignedProject)->create(['created_by' => $leader->id]);

    $response = $this->actingAs($secretaire, 'sanctum')->getJson('/api/expenses');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

it('lets financial oversight roles (president, tresorier, vice-tresorier) see every expense regardless of project assignment', function () {
    $project = Project::factory()->active()->create(['budget' => 100000]);
    $leader = committeeLeaderActor($project);
    $expense = Expense::factory()->for($project)->create(['created_by' => $leader->id]);

    foreach ([presidentActor(), treasurerActor(), viceTreasurerActor()] as $overseer) {
        $response = $this->actingAs($overseer, 'sanctum')->getJson('/api/expenses');
        $response->assertOk();
        expect(collect($response->json('data'))->pluck('id'))->toContain($expense->id);
    }
});
