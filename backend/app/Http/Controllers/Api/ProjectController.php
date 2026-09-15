<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AuthorizationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectReportResource;
use App\Http\Resources\ProjectResource;
use App\Models\CommitteeAssignment;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Services\ProjectDeletionService;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Project::class);

        $user = auth()->user();
        $query = Project::with(['manager', 'members'])->latest();

        // Bureau roles (everything except plain "abonne") see every project —
        // matches AuthorizationHelper::canAccessProject(), the same gate
        // ProjectPolicy::view() uses per-project. A plain subscriber is
        // scoped to projects they're an actual committee member of, never a
        // blanket "*.view" permission.
        if (! AuthorizationHelper::isBureauMember($user)) {
            $member = $user->member;

            if (! $member) {
                return ProjectResource::collection(collect());
            }

            $query->whereHas('members', function ($membersQuery) use ($member) {
                $membersQuery->whereKey($member->id);
            });
        }

        return ProjectResource::collection(
            $query->get()
        );
    }

    public function store(StoreProjectRequest $request)
    {
        $this->authorize('create', Project::class);

        $project = Project::create([
            ...$request->validated(),
            'status' => 'draft',
            'phase' => 'planning',
        ]);

        return new ProjectResource(
            $project->load(['manager', 'members'])
        );
    }

    public function show(Project $project)
    {
        $this->authorize('view', $project);

        return new ProjectResource(
            $project->load(['manager', 'members'])
        );
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $this->authorize('update', $project);

        $wasLocked = $project->isLocked();

        $project->update(
            $request->validated()
        );

        // Status can also flip to completed/cancelled through this generic
        // update endpoint (not just close()) — e.g. cancelling a project.
        // Either transition into a locked state dissolves the committee.
        if (! $wasLocked && $project->isLocked()) {
            $this->dissolveCommittee($project);
        }

        return new ProjectResource(
            $project->load(['manager', 'members'])
        );
    }

    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        app(ProjectDeletionService::class)->delete($project);

        return response()->json([
            'message' => __('messages.project.deleted'),
        ]);
    }

    // Manual funding_ready -> active transition ("Start Project"). See
    // ProjectPolicy::start and ProjectLifecycle.
    public function start(Project $project)
    {
        $this->authorize('start', $project);

        $project->update(['status' => 'active']);

        return new ProjectResource(
            $project->fresh()->load(['manager', 'members'])
        );
    }

    public function close(Project $project)
    {
        $this->authorize('close', $project);

        $report = DB::transaction(function () use ($project) {
            // Closing is also the only way to reach phase=completed — never
            // settable through a phase change request, see ProjectPhaseWorkflow.
            $project->update([
                'status' => 'completed',
                'phase' => 'completed',
            ]);

            $donations = $project->donations()->with('member.user')->get();
            $expenses = $project->expenses()->get();
            $allocations = $project->fundAllocations()->with('recorder')->get();

            // Only approved donations represent money actually collected, and
            // only approved/paid expenses represent money actually spent — the
            // full $donations/$expenses lists (all statuses) are still passed
            // to the PDF below so rejected/pending history is preserved and visible.
            $donationsTotal = (float) $donations->whereIn('status', Donation::FINANCIALLY_COUNTED_STATUSES)->sum('amount');
            $expensesTotal = (float) $expenses->whereIn('status', Expense::FINANCIALLY_COUNTED_STATUSES)->sum('amount');
            $allocationsTotal = (float) $allocations->sum('amount');

            $summary = [
                'donations_total' => $donationsTotal,
                'expenses_total' => $expensesTotal,
                'allocations_total' => $allocationsTotal,
                'returned_to_pool' => ($donationsTotal + $allocationsTotal) - $expensesTotal,
                'budget' => (float) $project->budget,
                'donation_count' => $donations->count(),
                'expense_count' => $expenses->count(),
            ];

            $pdf = Pdf::loadView('reports.project-closure', [
                'project' => $project,
                'donations' => $donations,
                'expenses' => $expenses,
                'allocations' => $allocations,
                'summary' => $summary,
                'settings' => SettingsService::get(),
            ]);

            $path = 'project-reports/project-'.$project->id.'-'.now()->format('Ymd-His').'.pdf';
            Storage::disk('public')->put($path, $pdf->output());

            $report = ProjectReport::create([
                'project_id' => $project->id,
                'generated_by' => auth()->id(),
                'file_path' => $path,
                'summary' => $summary,
            ]);

            $committeeUserIds = $project->members()
                ->with('user')
                ->get()
                ->pluck('user.id')
                ->filter()
                ->all();

            $title = __('notifications.project.closed_title');
            $message = __('notifications.project.closed_message', ['name' => $project->name]);

            Notification::notifyUsers($committeeUserIds, 'project_closed', $title, $message, $project);

            Notification::notifyRoles([
                'president',
                'vice-president',
                'tresorier',
                'vice-tresorier',
                'secretaire-general',
                'vice-secretaire-general',
            ], 'project_closed', $title, $message, $project);

            // Member Portal — a committee member who is a plain subscriber
            // (no bureau session) only ever sees "My Projects" through the
            // portal, so these two are worded and targeted specifically for
            // that read-only audience rather than reusing project_closed.
            Notification::notifyUsers(
                $committeeUserIds,
                'project_completed',
                __('notifications.project.completed_title'),
                __('notifications.project.completed_message', ['name' => $project->name]),
                $project
            );

            Notification::notifyUsers(
                $committeeUserIds,
                'project_report_generated',
                __('notifications.project.report_generated_title'),
                __('notifications.project.report_generated_message', ['name' => $project->name]),
                $report
            );

            $this->dissolveCommittee($project);

            return $report;
        });

        return (new ProjectResource(
            $project->fresh()->load(['manager', 'members'])
        ))->additional([
            'report' => new ProjectReportResource($report),
        ]);
    }

    // Completing/cancelling a project dissolves its committee automatically:
    // every still-open assignment history row is closed with action=dissolved
    // (never deleted), and the active-members pivot is cleared so the
    // committee reads as empty going forward. No new assignments can be made
    // while the project stays locked — see ProjectPolicy::assignCommittee.
    private function dissolveCommittee(Project $project): void
    {
        CommitteeAssignment::where('project_id', $project->id)
            ->whereNull('removed_at')
            ->update([
                'removed_by' => auth()->id(),
                'removed_at' => now(),
                'reason' => "Project {$project->status}.",
                'action' => 'dissolved',
            ]);

        $project->members()->detach();
    }
}
