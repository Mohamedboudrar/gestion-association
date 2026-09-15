<?php

namespace App\Helpers;

use App\Models\Donation;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectFundAllocation;
use App\Models\Subscription;

class FundsHelper
{
    /**
     * Single source of truth for the association's central "available
     * funds" pool. Money leaves it only when allocated to a project (not
     * when the project spends it — expenses draw down the project's own
     * collected total, which can include donations that were never part of
     * central funds). Open (planned/active) projects keep their full
     * allocation committed. Completed/cancelled projects return whatever
     * wasn't spent: expenses are covered by the project's donations first,
     * then by its allocation. The unspent allocation portion goes back to
     * central funds (undoing the earlier subtraction), and any unspent
     * donation portion is added on top as new funds — nothing stays
     * stranded on a closed project just because it happened to be
     * donation-funded.
     */
    public static function availableFunds(): float
    {
        $verifiedRevenueTotal = (float) Subscription::where('status', 'verified')->sum('amount');

        $openProjectAllocations = (float) ProjectFundAllocation::whereHas('project', function ($query) {
            $query->whereNotIn('status', ['completed', 'cancelled']);
        })->sum('amount');

        // Same per-project math as before, but batched into 4 fixed queries
        // total (project ids + 3 grouped sums) instead of 1 + 3N — the old
        // ->each() ran 3 separate sum() queries per closed project, an N+1
        // that alone accounted for the bulk of the dashboard's query count.
        $closedProjectIds = Project::whereIn('status', ['completed', 'cancelled'])->pluck('id');

        $allocatedByProject = ProjectFundAllocation::whereIn('project_id', $closedProjectIds)
            ->selectRaw('project_id, SUM(amount) as total')
            ->groupBy('project_id')
            ->pluck('total', 'project_id');

        $donationsByProject = Donation::whereIn('project_id', $closedProjectIds)
            ->financiallyCounted()
            ->selectRaw('project_id, SUM(amount) as total')
            ->groupBy('project_id')
            ->pluck('total', 'project_id');

        $expensesByProject = Expense::whereIn('project_id', $closedProjectIds)
            ->financiallyCounted()
            ->selectRaw('project_id, SUM(amount) as total')
            ->groupBy('project_id')
            ->pluck('total', 'project_id');

        $closedProjectsCommittedAllocation = 0.0;
        $closedProjectsReturnedDonations = 0.0;

        foreach ($closedProjectIds as $projectId) {
            $allocated = (float) ($allocatedByProject[$projectId] ?? 0);
            $donations = (float) ($donationsByProject[$projectId] ?? 0);
            $expenses = (float) ($expensesByProject[$projectId] ?? 0);

            $donationsUsed = min($donations, $expenses);
            $allocationUsed = min($allocated, max(0, $expenses - $donations));

            $closedProjectsCommittedAllocation += $allocationUsed;
            $closedProjectsReturnedDonations += $donations - $donationsUsed;
        }

        return $verifiedRevenueTotal
            - $openProjectAllocations
            - $closedProjectsCommittedAllocation
            + $closedProjectsReturnedDonations;
    }
}
