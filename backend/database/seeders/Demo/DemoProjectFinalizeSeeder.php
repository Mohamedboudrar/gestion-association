<?php

namespace Database\Seeders\Demo;

use App\Models\CommitteeAssignment;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\User;
use Database\Seeders\Support\MoroccanData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

// Mirrors ProjectController::close()'s summary shape + committee dissolution
// for every already-'completed' project (report generation + notifications),
// and dissolves the committee (no report) for every already-'cancelled'
// project — exactly what update() does when a project transitions into a
// locked state. Runs last, once donations/expenses/allocations exist to
// summarize.
class DemoProjectFinalizeSeeder extends Seeder
{
    public function run(): void
    {
        $president = User::where('email', 'president@association.com')->firstOrFail();

        Auth::login($president);

        $reportCount = 0;

        foreach (Project::where('status', 'completed')->get() as $project) {
            $this->generateReport($project, $president);
            $this->dissolveCommittee($project, $president, 'Project completed.');
            $reportCount++;
        }

        foreach (Project::where('status', 'cancelled')->get() as $project) {
            $this->dissolveCommittee($project, $president, 'Project cancelled.');
        }

        Auth::logout();

        $this->command?->info("{$reportCount} project closure reports generated; completed/cancelled committees dissolved.");
    }

    private function generateReport(Project $project, User $president): void
    {
        $donationsTotal = (float) Donation::where('project_id', $project->id)
            ->whereIn('status', Donation::FINANCIALLY_COUNTED_STATUSES)
            ->sum('amount');

        $expensesTotal = (float) Expense::where('project_id', $project->id)
            ->whereIn('status', Expense::FINANCIALLY_COUNTED_STATUSES)
            ->sum('amount');

        $allocationsTotal = (float) $project->fundAllocations()->sum('amount');

        $donationCount = Donation::where('project_id', $project->id)->count();
        $expenseCount = Expense::where('project_id', $project->id)->count();

        $summary = [
            'donations_total' => $donationsTotal,
            'expenses_total' => $expensesTotal,
            'allocations_total' => $allocationsTotal,
            'returned_to_pool' => ($donationsTotal + $allocationsTotal) - $expensesTotal,
            'budget' => (float) $project->budget,
            'donation_count' => $donationCount,
            'expense_count' => $expenseCount,
        ];

        $path = MoroccanData::storeFakePdf('project-reports', "Rapport de clôture - {$project->name}");

        $report = ProjectReport::create([
            'project_id' => $project->id,
            'generated_by' => $project->manager_id ?? $president->id,
            'file_path' => $path,
            'summary' => $summary,
        ]);

        $committeeUserIds = $project->members()->with('user')->get()->pluck('user.id')->filter()->all();

        Notification::notifyUsers($committeeUserIds, 'project_closed', 'Project closed', "Project {$project->name} closed — report available.", $project);
        Notification::notifyRoles([
            'president', 'vice-president', 'tresorier', 'vice-tresorier', 'secretaire-general', 'vice-secretaire-general',
        ], 'project_closed', 'Project closed', "Project {$project->name} closed — report available.", $project);
        Notification::notifyUsers($committeeUserIds, 'project_completed', 'Project completed', "Project \"{$project->name}\" has been completed.", $project);
        Notification::notifyUsers($committeeUserIds, 'project_report_generated', 'Final report available', "Final report for \"{$project->name}\" is now available.", $report);
    }

    private function dissolveCommittee(Project $project, User $president, string $reason): void
    {
        CommitteeAssignment::where('project_id', $project->id)
            ->whereNull('removed_at')
            ->update([
                'removed_by' => $president->id,
                'removed_at' => now(),
                'reason' => $reason,
                'action' => 'dissolved',
            ]);

        $project->members()->detach();
    }
}
