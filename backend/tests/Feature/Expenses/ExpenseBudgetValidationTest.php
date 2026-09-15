<?php

use App\Models\Donation;
use App\Models\Expense;
use App\Models\Project;

/*
|--------------------------------------------------------------------------
| StoreExpenseRequest / UpdateExpenseRequest business rules
|--------------------------------------------------------------------------
|
| Two INDEPENDENT checks run in withValidator() once amount/project_id pass
| basic validation, and both can fire on the same request (both errors
| accumulate on the 'amount' key):
|
|   1. Remaining-funds check: amount > (donations + fund allocations - the
|      project's already financially-counted expenses). This is about money
|      that has ACTUALLY been collected.
|   2. Budget check (ExpenseBudgetValidator): compares against the
|      project's planned `budget` ceiling, counting every expense that isn't
|      rejected (draft/pending/approved/paid) as already "claiming" a slice
|      of that budget — regardless of whether the money has been collected
|      yet. Only this check logs an Activity entry on failure.
|
| These are deliberately different concerns: a project can be well within
| its budget ceiling but still short on actually-collected funds, or vice
| versa.
*/

function activeProjectWithLeaderAndBudget(float $budget): array
{
    $project = Project::factory()->active()->create(['budget' => $budget]);
    $leader = committeeLeaderActor($project);

    return [$project, $leader];
}

it('blocks a new expense that would push the project over budget, then allows one landing exactly at budget', function () {
    [$project, $leader] = activeProjectWithLeaderAndBudget(1000);

    // Plenty of actually-collected funds so the remaining-funds check never
    // interferes here — only the budget ceiling is under test.
    Donation::factory()->for($project)->approved()->create(['amount' => 5000]);

    Expense::factory()->for($project)->create([
        'created_by' => $leader->id,
        'status' => 'draft',
        'amount' => 600,
    ]);

    // 600 (existing draft) + 500 (new) = 1100 > 1000 budget.
    $overBudget = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses', [
        'project_id' => $project->id,
        'supplier_name' => 'Acme Supplies',
        'description' => 'Materials',
        'amount' => 500,
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
    ]);

    $overBudget->assertStatus(422)->assertJsonValidationErrors(['amount']);
    expect($overBudget->json('errors.amount.0'))->toContain('exceed the project budget');

    $this->assertDatabaseHas('activity_log', [
        'description' => 'Manual expense rejected because budget exceeded.',
    ]);
    $this->assertDatabaseCount('expenses', 1); // only the pre-existing draft — nothing new was created.

    // 600 (existing draft) + 400 (new) = 1000 == budget exactly.
    // within_budget is defined as exceeded_by <= 0, so landing exactly on
    // the ceiling must succeed — this is the off-by-one boundary case.
    $atBudget = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses', [
        'project_id' => $project->id,
        'supplier_name' => 'Acme Supplies',
        'description' => 'Materials',
        'amount' => 400,
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
    ]);

    $atBudget->assertCreated()->assertJsonPath('data.status', 'draft');
    $this->assertDatabaseCount('expenses', 2);
});

it('blocks an expense that exceeds actually-collected remaining funds even though it is well under the project budget', function () {
    [$project, $leader] = activeProjectWithLeaderAndBudget(10000);

    // Only 200 has actually been collected (donations + allocations); 0 spent.
    Donation::factory()->for($project)->approved()->create(['amount' => 200]);

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses', [
        'project_id' => $project->id,
        'supplier_name' => 'Acme Supplies',
        'description' => 'Materials',
        'amount' => 500, // well under the 10000 budget, but > 200 remaining funds
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['amount']);

    // Exactly one error: the remaining-funds check alone, not the budget
    // check (which passes fine at 500 <= 10000 budget).
    expect($response->json('errors.amount'))
        ->toBe(["The amount exceeds the project's remaining funds."]);

    // The budget check is what logs the activity entry — it did not fire here.
    $this->assertDatabaseMissing('activity_log', [
        'description' => 'Manual expense rejected because budget exceeded.',
    ]);
    $this->assertDatabaseCount('expenses', 0);
});

it('fires both the remaining-funds check and the budget check on the same request when both are violated', function () {
    [$project, $leader] = activeProjectWithLeaderAndBudget(300);

    Donation::factory()->for($project)->approved()->create(['amount' => 200]);

    Expense::factory()->for($project)->create([
        'created_by' => $leader->id,
        'status' => 'draft',
        'amount' => 250, // already claims 250 of the 300 budget
    ]);

    // Remaining funds = 200 (collected) - 0 (financially counted expenses) = 200.
    // Remaining budget = 300 - 250 (already allocated) = 50.
    // A new expense of 280 exceeds BOTH.
    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses', [
        'project_id' => $project->id,
        'supplier_name' => 'Acme Supplies',
        'description' => 'Materials',
        'amount' => 280,
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['amount']);

    $errors = $response->json('errors.amount');
    expect($errors)->toHaveCount(2);
    expect(collect($errors)->contains(fn ($m) => str_contains($m, 'remaining funds')))->toBeTrue();
    expect(collect($errors)->contains(fn ($m) => str_contains($m, 'exceed the project budget')))->toBeTrue();

    $this->assertDatabaseHas('activity_log', [
        'description' => 'Manual expense rejected because budget exceeded.',
    ]);
});

it('skips the business-rule checks entirely when basic amount validation already fails', function () {
    [$project, $leader] = activeProjectWithLeaderAndBudget(1000);

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses', [
        'project_id' => $project->id,
        'supplier_name' => 'Acme Supplies',
        'description' => 'Materials',
        'amount' => -50,
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['amount']);

    // Only the built-in "min:0.01" message, never a business-rule message,
    // and no activity log entry — withValidator() bails out early when
    // amount already has a basic validation error.
    expect($response->json('errors.amount'))->toHaveCount(1);
    expect($response->json('errors.amount.0'))->not->toContain('remaining funds');
    expect($response->json('errors.amount.0'))->not->toContain('project budget');

    $this->assertDatabaseMissing('activity_log', [
        'description' => 'Manual expense rejected because budget exceeded.',
    ]);
});

it('rejects a store request missing all required fields', function () {
    $leader = committeeLeaderActor(Project::factory()->active()->create());

    $this->actingAs($leader, 'sanctum')->postJson('/api/expenses', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['project_id', 'supplier_name', 'description', 'amount', 'payment_method', 'expense_date']);
});

it('excludes an expense being edited from its own budget contribution, so raising its amount is not double-counted', function () {
    [$project, $leader] = activeProjectWithLeaderAndBudget(1000);

    Donation::factory()->for($project)->approved()->create(['amount' => 5000]);

    $expense = Expense::factory()->for($project)->create([
        'created_by' => $leader->id,
        'status' => 'draft',
        'amount' => 600,
    ]);

    // If the endpoint naively summed "all allocating expenses" (including
    // this one's OLD amount) plus the NEW amount, 600 (old) + 900 (new) =
    // 1500 would wrongly exceed the 1000 budget. Correctly excluding this
    // expense's own prior contribution leaves already_allocated = 0, so
    // 900 <= 1000 must pass.
    $response = $this->actingAs($leader, 'sanctum')
        ->putJson("/api/expenses/{$expense->id}", ['amount' => 900]);

    $response->assertOk()->assertJsonPath('data.amount', '900.00');
    expect((float) $expense->fresh()->amount)->toBe(900.0);
});

it('still blocks an update whose new amount genuinely exceeds the budget on its own', function () {
    [$project, $leader] = activeProjectWithLeaderAndBudget(1000);

    Donation::factory()->for($project)->approved()->create(['amount' => 5000]);

    $expense = Expense::factory()->for($project)->create([
        'created_by' => $leader->id,
        'status' => 'draft',
        'amount' => 600,
    ]);

    $response = $this->actingAs($leader, 'sanctum')
        ->putJson("/api/expenses/{$expense->id}", ['amount' => 1200]);

    $response->assertStatus(422)->assertJsonValidationErrors(['amount']);
    expect($response->json('errors.amount.0'))->toContain('exceed the project budget');

    $this->assertDatabaseHas('activity_log', [
        'description' => 'Manual expense rejected because budget exceeded.',
    ]);
    expect((float) $expense->fresh()->amount)->toBe(600.0); // unchanged
});
