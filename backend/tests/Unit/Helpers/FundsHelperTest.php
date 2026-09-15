<?php

use App\Helpers\FundsHelper;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectFundAllocation;
use App\Models\Subscription;
use App\Models\User;

it('sums only verified subscriptions as the base revenue pool', function () {
    $member = Member::factory()->for(User::factory())->create();
    Subscription::factory()->for($member)->verified()->create(['amount' => 1000]);
    Subscription::factory()->for($member)->create(['status' => 'pending', 'amount' => 5000]);
    Subscription::factory()->for($member)->create(['status' => 'rejected', 'amount' => 5000]);

    expect(FundsHelper::availableFunds())->toBe(1000.0);
});

it('subtracts allocations made to still-open projects', function () {
    $member = Member::factory()->for(User::factory())->create();
    Subscription::factory()->for($member)->verified()->create(['amount' => 1000]);

    $activeProject = Project::factory()->active()->create();
    ProjectFundAllocation::factory()->for($activeProject)->create(['amount' => 300]);

    expect(FundsHelper::availableFunds())->toBe(700.0);
});

it('only commits the portion of a closed projects allocation actually needed to cover unmet expenses', function () {
    $member = Member::factory()->for(User::factory())->create();
    Subscription::factory()->for($member)->verified()->create(['amount' => 1000]);

    $closedProject = Project::factory()->completed()->create();
    ProjectFundAllocation::factory()->for($closedProject)->create(['amount' => 500]);
    Donation::factory()->for($closedProject)->approved()->create(['amount' => 200]);
    Expense::factory()->for($closedProject)->approved()->create(['amount' => 400]);

    // donationsUsed = min(200, 400) = 200; allocationUsed = min(500, max(0, 400-200)) = 200
    // available = 1000 - 0(open) - 200(committed) + 0(returned) = 800
    expect(FundsHelper::availableFunds())->toBe(800.0);
});

it('returns unspent donations from a closed project back to the pool as new funds', function () {
    $member = Member::factory()->for(User::factory())->create();
    Subscription::factory()->for($member)->verified()->create(['amount' => 1000]);

    $closedProject = Project::factory()->completed()->create();
    ProjectFundAllocation::factory()->for($closedProject)->create(['amount' => 300]);
    Donation::factory()->for($closedProject)->approved()->create(['amount' => 500]);
    Expense::factory()->for($closedProject)->approved()->create(['amount' => 200]);

    // donationsUsed = min(500, 200) = 200; allocationUsed = min(300, max(0, 200-500)) = 0
    // returnedDonations = 500 - 200 = 300
    // available = 1000 - 0 - 0 + 300 = 1300
    expect(FundsHelper::availableFunds())->toBe(1300.0);
});

it('ignores draft, pending, and rejected donations/expenses on a closed project entirely', function () {
    $member = Member::factory()->for(User::factory())->create();
    Subscription::factory()->for($member)->verified()->create(['amount' => 1000]);

    $closedProject = Project::factory()->completed()->create();
    Donation::factory()->for($closedProject)->create(['status' => 'pending', 'amount' => 5000]);
    Expense::factory()->for($closedProject)->create(['status' => 'draft', 'amount' => 5000]);

    expect(FundsHelper::availableFunds())->toBe(1000.0);
});

it('excludes a cancelled projects open allocation from the subtraction but still nets its closure figures', function () {
    $member = Member::factory()->for(User::factory())->create();
    Subscription::factory()->for($member)->verified()->create(['amount' => 1000]);

    $cancelled = Project::factory()->cancelled()->create();
    ProjectFundAllocation::factory()->for($cancelled)->create(['amount' => 400]);

    // Cancelled is terminal, same closed-project math as completed: no
    // donations/expenses at all here, so nothing is committed or returned.
    expect(FundsHelper::availableFunds())->toBe(1000.0);
});
