<?php

namespace Database\Seeders\Demo;

use App\Helpers\ExpenseBudgetValidator;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\Support\MoroccanData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

// Realistic expenses across the same active/completed/cancelled projects
// DemoDonationsSeeder targets, respecting ExpenseBudgetValidator's
// draft+pending+approved+paid ceiling for every project except two
// deliberate edge cases the spec asks for (one project pushed over budget,
// one landed exactly on budget).
class DemoExpensesSeeder extends Seeder
{
    private const OVER_BUDGET_PROJECT = 'Drinking Water Network';

    private const ON_BUDGET_PROJECT = 'Quran School';

    private int $invoiceSequence = 1;

    public function run(): void
    {
        $financialApprovers = User::whereIn('email', [
            'president@association.com',
            'tresorier@association.com',
            'vice.tresorier@association.com',
        ])->get();

        $projects = Project::with('members.user')
            ->whereIn('status', ['active', 'completed', 'cancelled'])
            ->get();

        Auth::login($financialApprovers->first());

        $total = 0;

        foreach ($projects as $project) {
            $budgetCeiling = $this->targetCeilingFor($project);
            $count = random_int(8, 20);

            for ($i = 0; $i < $count; $i++) {
                if (ExpenseBudgetValidator::alreadyAllocated($project) >= $budgetCeiling) {
                    break;
                }

                $this->createExpense($project, $financialApprovers);
                $total++;
            }

            $total += $this->applyBudgetEdgeCase($project, $financialApprovers->first());
        }

        Auth::logout();

        $this->command?->info("{$total} expenses seeded across {$projects->count()} projects.");
    }

    // Most projects stay comfortably under budget. The two edge-case
    // projects are deliberately capped well short here, leaving room for
    // applyBudgetEdgeCase() to land exactly on (or over) budget afterward —
    // letting the random loop itself approach the ceiling would overshoot by
    // an arbitrary amount instead of landing exactly on budget.
    private function targetCeilingFor(Project $project): float
    {
        if (in_array($project->name, [self::OVER_BUDGET_PROJECT, self::ON_BUDGET_PROJECT], true)) {
            return (float) $project->budget * 0.5;
        }

        // Completed/cancelled projects are the only ones whose expenses feed
        // into FundsHelper::availableFunds() at all — via "expenses beyond
        // donations" eating into the central pool. Sizing their expenses off
        // the project's own budget (independent of what it actually
        // collected) routinely produced expenses well above donations,
        // which read as an implausible association-wide deficit. Capping
        // them at a fraction of what the project actually collected
        // (donations + allocations) keeps that spend realistic.
        if (in_array($project->status, ['completed', 'cancelled'], true)) {
            $collected = (float) Donation::where('project_id', $project->id)
                ->where('status', 'approved')
                ->sum('amount')
                + (float) $project->fundAllocations()->sum('amount');

            return $collected > 0
                ? $collected * (random_int(50, 85) / 100)
                : (float) $project->budget * 0.3;
        }

        return (float) $project->budget * (random_int(40, 80) / 100);
    }

    private function applyBudgetEdgeCase(Project $project, User $approver): int
    {
        $allocated = ExpenseBudgetValidator::alreadyAllocated($project);
        $budget = (float) $project->budget;

        if ($project->name === self::ON_BUDGET_PROJECT) {
            $topUp = round($budget - $allocated, 2);

            if ($topUp > 0) {
                $this->createExpense($project, collect([$approver]), amountOverride: $topUp, statusOverride: 'approved');

                return 1;
            }

            return 0;
        }

        if ($project->name === self::OVER_BUDGET_PROJECT) {
            // Covers however much of the budget the main loop left
            // unallocated, then pushes deliberately past it — a fixed
            // "overshoot" alone could still land under budget if the main
            // loop consumed less than expected.
            $remaining = max(0, $budget - $allocated);
            $overshoot = round($remaining + ($budget * 0.15) + 500, 2);
            $this->createExpense($project, collect([$approver]), amountOverride: $overshoot, statusOverride: 'approved');

            return 1;
        }

        return 0;
    }

    private function createExpense(
        Project $project,
        Collection $financialApprovers,
        ?float $amountOverride = null,
        ?string $statusOverride = null,
    ): void {
        $category = array_rand(MoroccanData::EXPENSE_CATEGORIES);
        $suppliers = MoroccanData::EXPENSE_CATEGORIES[$category];
        $supplier = $suppliers[array_rand($suppliers)];

        $expenseDate = $this->dateWithin($project);
        $status = $statusOverride ?? $this->weightedStatus();
        $creator = $this->creatorFor($project, $financialApprovers->first());

        $expense = Expense::create([
            'project_id' => $project->id,
            'supplier_name' => $supplier,
            'description' => "{$category} — {$project->name}",
            'amount' => $amountOverride ?? random_int(80, 500) * 10,
            'payment_method' => MoroccanData::PAYMENT_METHODS[array_rand(MoroccanData::PAYMENT_METHODS)],
            'invoice_number' => 'INV-'.str_pad((string) $this->invoiceSequence++, 5, '0', STR_PAD_LEFT),
            'invoice_path' => random_int(0, 1) ? MoroccanData::storeFakeImage('expense-invoices') : MoroccanData::storeFakePdf('expense-invoices', "Facture {$supplier}"),
            'expense_date' => $expenseDate,
            'notes' => null,
            'created_by' => $creator,
            'status' => $status,
        ]);

        if (in_array($status, ['approved', 'paid'], true)) {
            $approver = $financialApprovers->random();
            $expense->update([
                'approved_by' => $approver->id,
                'approved_at' => (clone $expenseDate)->addDays(random_int(0, 3)),
            ]);
        }

        if ($status === 'paid') {
            $payer = $financialApprovers->random();
            $expense->update([
                'paid_by' => $payer->id,
                'paid_at' => (clone $expenseDate)->addDays(random_int(4, 10)),
            ]);
        }

        if ($status === 'rejected') {
            $rejecter = $financialApprovers->random();
            $expense->update([
                'rejected_by' => $rejecter->id,
                'rejected_at' => (clone $expenseDate)->addDays(random_int(0, 3)),
                'rejection_reason' => 'Facture illisible ou incomplète.',
                'rejection_type' => random_int(0, 1) ? 'invoice' : 'details',
            ]);
        }
    }

    private function creatorFor(Project $project, int|User $fallback): int
    {
        $financial = $project->members->first(fn ($member) => in_array(
            $member->pivot->committee_role ?? null,
            ['leader', 'treasurer'],
            true
        ));

        if ($financial && $financial->user_id) {
            return $financial->user_id;
        }

        return is_int($fallback) ? $fallback : $fallback->id;
    }

    private function weightedStatus(): string
    {
        $weights = ['draft' => 10, 'pending' => 15, 'approved' => 50, 'rejected' => 10, 'paid' => 15];
        $roll = random_int(1, array_sum($weights));
        $cumulative = 0;

        foreach ($weights as $key => $weight) {
            $cumulative += $weight;

            if ($roll <= $cumulative) {
                return $key;
            }
        }

        return 'draft';
    }

    private function dateWithin(Project $project): Carbon
    {
        $start = Carbon::parse($project->start_date);
        $end = Carbon::parse($project->end_date ?? now());

        if ($end->greaterThan(now())) {
            $end = now();
        }

        if ($end->lessThanOrEqualTo($start)) {
            return $start;
        }

        return Carbon::createFromTimestamp(random_int($start->timestamp, $end->timestamp));
    }
}
