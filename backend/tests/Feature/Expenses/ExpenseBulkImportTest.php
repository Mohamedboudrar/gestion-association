<?php

use App\Models\Expense;
use App\Models\Project;

/*
|--------------------------------------------------------------------------
| Bulk expense import (POST /api/expenses/import) — atomic all-or-nothing
|--------------------------------------------------------------------------
|
| StoreExpenseImportRequest validates each row structurally (same field
| rules as a single expense). The controller then sums every row's amount
| and runs ExpenseBudgetValidator::breakdown() ONCE against that total (not
| per-row). If the batch total would exceed budget: 422, ZERO rows
| persisted, and an activity log entry — nothing is saved, even rows that
| individually look fine. On success, every row is created inside one
| DB transaction with status = 'draft'.
*/

function activeProjectWithImportLeader(float $budget): array
{
    $project = Project::factory()->active()->create(['budget' => $budget]);
    $leader = committeeLeaderActor($project);

    return [$project, $leader];
}

function importRow(array $overrides = []): array
{
    return array_merge([
        'supplier_name' => 'Bulk Supplier',
        'description' => 'Bulk imported expense',
        'amount' => 100,
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
        'invoice_number' => null,
        'notes' => null,
    ], $overrides);
}

it('rejects an import batch that would exceed the project budget and persists nothing', function () {
    [$project, $leader] = activeProjectWithImportLeader(1000);

    $rows = [
        importRow(['amount' => 300]),
        importRow(['amount' => 400]),
        importRow(['amount' => 500]), // sum = 1200 > 1000 budget
    ];

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses/import', [
        'project_id' => $project->id,
        'expenses' => $rows,
    ]);

    $response->assertStatus(422)
        ->assertJson(['message' => 'Import exceeds remaining project budget. No expenses were imported.']);

    expect($response->json('budget.within_budget'))->toBeFalse();
    expect((float) $response->json('budget.new_total'))->toBe(1200.0);
    expect((float) $response->json('budget.project_budget'))->toBe(1000.0);

    $this->assertDatabaseCount('expenses', 0);
    $this->assertDatabaseHas('activity_log', [
        'description' => 'Expense import rejected because budget exceeded.',
    ]);
});

it('imports the full batch atomically when the total lands exactly at budget', function () {
    [$project, $leader] = activeProjectWithImportLeader(1000);

    $rows = [
        importRow(['supplier_name' => 'Supplier A', 'amount' => 200]),
        importRow(['supplier_name' => 'Supplier B', 'amount' => 300]),
        importRow(['supplier_name' => 'Supplier C', 'amount' => 500]), // sum = 1000 == budget exactly
    ];

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses/import', [
        'project_id' => $project->id,
        'expenses' => $rows,
    ]);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);

    $this->assertDatabaseCount('expenses', 3);

    Expense::all()->each(function (Expense $expense) use ($project, $leader) {
        expect($expense->status)->toBe('draft');
        expect($expense->project_id)->toBe($project->id);
        expect($expense->created_by)->toBe($leader->id);
    });

    expect((float) Expense::sum('amount'))->toBe(1000.0);
});

it('leaves pre-existing expenses untouched when a subsequent import batch fails the budget check', function () {
    [$project, $leader] = activeProjectWithImportLeader(1000);

    Expense::factory()->for($project)->create([
        'created_by' => $leader->id,
        'status' => 'approved',
        'amount' => 100,
    ]);

    $rows = [
        importRow(['amount' => 500]),
        importRow(['amount' => 500]), // + 100 already allocated = 1100 > 1000
    ];

    $this->actingAs($leader, 'sanctum')->postJson('/api/expenses/import', [
        'project_id' => $project->id,
        'expenses' => $rows,
    ])->assertStatus(422);

    $this->assertDatabaseCount('expenses', 1); // only the pre-existing one
});

it('validates each row of an import batch individually', function () {
    [$project, $leader] = activeProjectWithImportLeader(10000);

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses/import', [
        'project_id' => $project->id,
        'expenses' => [
            importRow(), // valid
            importRow(['supplier_name' => '', 'amount' => 0]), // invalid: blank supplier, amount below min
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['expenses.1.supplier_name', 'expenses.1.amount']);

    $this->assertDatabaseCount('expenses', 0);
});

it('rejects an import request with a missing project_id or an empty expenses array', function () {
    $leader = committeeLeaderActor(Project::factory()->active()->create());

    $this->actingAs($leader, 'sanctum')->postJson('/api/expenses/import', [
        'expenses' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['project_id', 'expenses']);
});

it('forbids a plain committee member (not leader/treasurer) from importing expenses', function () {
    [$project] = activeProjectWithImportLeader(10000);
    $member = committeeMemberActor($project);

    $this->actingAs($member, 'sanctum')->postJson('/api/expenses/import', [
        'project_id' => $project->id,
        'expenses' => [importRow()],
    ])->assertStatus(403);

    $this->assertDatabaseCount('expenses', 0);
});

it('forbids importing expenses into a project that is not active yet', function () {
    $project = Project::factory()->committeeReady()->create(['budget' => 10000]);
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')->postJson('/api/expenses/import', [
        'project_id' => $project->id,
        'expenses' => [importRow()],
    ])->assertStatus(403)->assertJson(['message' => 'This project must be active before recording this.']);

    $this->assertDatabaseCount('expenses', 0);
});

it('forbids importing expenses into a locked (completed) project', function () {
    $project = Project::factory()->completed()->create(['budget' => 10000]);
    $leader = committeeLeaderActor($project);

    $this->actingAs($leader, 'sanctum')->postJson('/api/expenses/import', [
        'project_id' => $project->id,
        'expenses' => [importRow()],
    ])->assertStatus(403)->assertJson(['message' => 'This project is completed and is now read-only.']);

    $this->assertDatabaseCount('expenses', 0);
});
