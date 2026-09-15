<?php

namespace App\Helpers;

use App\Models\Project;

/**
 * Single source of truth for "does this expense (or batch of expenses) fit
 * inside the project's budget" — distinct from Expense::FINANCIALLY_COUNTED_STATUSES
 * (approved+paid only, used to compute *available funds already collected*).
 * A budget is a spending ceiling/plan: a still-pending or freshly created
 * draft expense already claims a slice of it, so it must count here even
 * though it doesn't yet count as money actually spent.
 */
class ExpenseBudgetValidator
{
    // 'paid' is included on top of the spec's literal "Draft + Pending +
    // Approved" list — paid is already-approved money that has also gone out
    // the door, so excluding it would let further spending ignore money
    // that's already spent. Rejected (and draft/pending/approved/paid's
    // absence, i.e. anything else) never counts.
    public const ALLOCATING_STATUSES = ['draft', 'pending', 'approved', 'paid'];

    public static function alreadyAllocated(Project $project, ?int $excludeExpenseId = null): float
    {
        return (float) $project->expenses()
            ->whereIn('status', self::ALLOCATING_STATUSES)
            ->when($excludeExpenseId, fn ($query) => $query->where('id', '!=', $excludeExpenseId))
            ->sum('amount');
    }

    /**
     * $newTotal is the amount being added on top of what's already allocated
     * — a single expense's amount, an edited expense's new amount (with its
     * own prior amount excluded via $excludeExpenseId), or a whole import
     * batch's summed total.
     */
    public static function breakdown(Project $project, float $newTotal, ?int $excludeExpenseId = null): array
    {
        $budget = (float) $project->budget;
        $alreadyAllocated = self::alreadyAllocated($project, $excludeExpenseId);
        $remaining = $budget - $alreadyAllocated;
        $exceededBy = round(max(0, $newTotal - $remaining), 2);

        return [
            'project_budget' => $budget,
            'already_allocated' => $alreadyAllocated,
            'remaining_budget' => $remaining,
            'new_total' => $newTotal,
            'exceeded_by' => $exceededBy,
            'within_budget' => $exceededBy <= 0.0,
        ];
    }

    public static function errorMessage(array $breakdown, string $label = 'New Total'): string
    {
        return sprintf(
            'This would exceed the project budget. Project Budget: %s | Already Allocated: %s | Remaining Budget: %s | %s: %s | Exceeded By: %s',
            number_format($breakdown['project_budget'], 2),
            number_format($breakdown['already_allocated'], 2),
            number_format($breakdown['remaining_budget'], 2),
            $label,
            number_format($breakdown['new_total'], 2),
            number_format($breakdown['exceeded_by'], 2),
        );
    }
}
