<?php

use App\Helpers\ExpenseBudgetValidator;
use App\Models\Expense;
use App\Models\Project;

it('reports within budget when the new total fits exactly at the remaining budget boundary', function () {
    $project = Project::factory()->create(['budget' => 1000]);
    Expense::factory()->for($project)->create(['status' => 'approved', 'amount' => 600]);

    $breakdown = ExpenseBudgetValidator::breakdown($project, 400);

    expect($breakdown)->toMatchArray([
        'project_budget' => 1000.0,
        'already_allocated' => 600.0,
        'remaining_budget' => 400.0,
        'new_total' => 400.0,
        'exceeded_by' => 0.0,
        'within_budget' => true,
    ]);
});

it('reports exceeding the budget by the exact overage amount', function () {
    $project = Project::factory()->create(['budget' => 1000]);
    Expense::factory()->for($project)->create(['status' => 'approved', 'amount' => 600]);

    $breakdown = ExpenseBudgetValidator::breakdown($project, 500);

    expect($breakdown['exceeded_by'])->toBe(100.0);
    expect($breakdown['within_budget'])->toBeFalse();
});

it('counts draft, pending, approved, and paid expenses toward already-allocated, but never rejected', function () {
    $project = Project::factory()->create(['budget' => 10000]);
    Expense::factory()->for($project)->create(['status' => 'draft', 'amount' => 100]);
    Expense::factory()->for($project)->create(['status' => 'pending', 'amount' => 100]);
    Expense::factory()->for($project)->create(['status' => 'approved', 'amount' => 100]);
    Expense::factory()->for($project)->create(['status' => 'paid', 'amount' => 100]);
    Expense::factory()->for($project)->rejected()->create(['amount' => 5000]);

    expect(ExpenseBudgetValidator::alreadyAllocated($project))->toBe(400.0);
});

it('excludes the expense being edited from its own already-allocated total', function () {
    $project = Project::factory()->create(['budget' => 1000]);
    $beingEdited = Expense::factory()->for($project)->create(['status' => 'pending', 'amount' => 300]);
    Expense::factory()->for($project)->create(['status' => 'approved', 'amount' => 200]);

    // Without exclusion, already_allocated would be 500. With exclusion, only
    // the other expense (200) counts — updating this one to 700 should fit.
    $breakdown = ExpenseBudgetValidator::breakdown($project, 700, excludeExpenseId: $beingEdited->id);

    expect($breakdown['already_allocated'])->toBe(200.0);
    expect($breakdown['within_budget'])->toBeTrue();
});

it('formats the error message with every figure to 2 decimals', function () {
    $breakdown = [
        'project_budget' => 1000.0,
        'already_allocated' => 600.0,
        'remaining_budget' => 400.0,
        'new_total' => 500.0,
        'exceeded_by' => 100.0,
    ];

    expect(ExpenseBudgetValidator::errorMessage($breakdown))
        ->toBe('This would exceed the project budget. Project Budget: 1,000.00 | Already Allocated: 600.00 | Remaining Budget: 400.00 | New Total: 500.00 | Exceeded By: 100.00');
});
