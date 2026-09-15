<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectDeletionRequestRequest;
use App\Http\Resources\ProjectDeletionRequestResource;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectDeletionRequest;
use App\Services\ProjectDeletionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectDeletionRequestController extends Controller
{
    // Full history for one project — never hard-deleted, so this still shows
    // even after the project itself is gone (project_id goes null, but the
    // snapshot fields stay).
    public function index(Project $project)
    {
        $this->authorize('viewAny', [ProjectDeletionRequest::class, $project]);

        return ProjectDeletionRequestResource::collection(
            $project->deletionRequests()
                ->with(['requestedBy', 'reviewedBy'])
                ->latest('requested_at')
                ->get()
        );
    }

    // Association-wide pending queue, for the president's review page.
    public function pending()
    {
        $this->authorize('viewPending', ProjectDeletionRequest::class);

        return ProjectDeletionRequestResource::collection(
            ProjectDeletionRequest::where('status', 'pending')
                ->with(['project', 'requestedBy', 'reviewedBy'])
                ->latest('requested_at')
                ->get()
        );
    }

    public function store(StoreProjectDeletionRequestRequest $request, Project $project)
    {
        $this->authorize('create', [ProjectDeletionRequest::class, $project]);

        $deletionRequest = ProjectDeletionRequest::create([
            'project_id' => $project->id,
            'project_name' => $project->name,
            'reason' => $request->validated('reason'),
            'requested_by' => auth()->id(),
            'requested_at' => now(),
            'status' => 'pending',
        ]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($project)
            ->withProperties(['reason' => $deletionRequest->reason])
            ->log('Project deletion requested.');

        $deletionRequest->load(['project', 'requestedBy', 'reviewedBy']);

        Notification::notifyRoles(
            ['president'],
            'project_deletion_pending',
            __('notifications.project_deletion.pending_title'),
            __('notifications.project_deletion.pending_message', [
                'actor' => $deletionRequest->requestedBy?->name ?? __('notifications.people.committee_leader'),
                'project' => $project->name,
            ]),
            $deletionRequest,
        );

        return new ProjectDeletionRequestResource($deletionRequest);
    }

    public function approve(ProjectDeletionRequest $deletionRequest)
    {
        $this->authorize('review', $deletionRequest);

        $project = $deletionRequest->project;

        if (! $project) {
            return response()->json([
                'message' => __('messages.project_deletion_request.project_gone'),
            ], 422);
        }

        DB::transaction(function () use ($deletionRequest, $project) {
            $deletionRequest->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            activity()
                ->causedBy(auth()->user())
                ->performedOn($project)
                ->log('Project deletion approved.');

            app(ProjectDeletionService::class)->delete($project, $deletionRequest->reason);
        });

        $deletionRequest = $deletionRequest->fresh()->load(['requestedBy', 'reviewedBy']);

        if ($deletionRequest->requested_by) {
            Notification::notifyUsers(
                [$deletionRequest->requested_by],
                'project_deletion_approved',
                __('notifications.project_deletion.approved_title'),
                __('notifications.project_deletion.approved_message', ['project' => $deletionRequest->project_name]),
                $deletionRequest,
            );
        }

        return new ProjectDeletionRequestResource($deletionRequest);
    }

    public function reject(Request $request, ProjectDeletionRequest $deletionRequest)
    {
        $this->authorize('review', $deletionRequest);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $deletionRequest->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'rejection_reason' => $validated['reason'],
        ]);

        if ($deletionRequest->project) {
            activity()
                ->causedBy(auth()->user())
                ->performedOn($deletionRequest->project)
                ->withProperties(['reason' => $validated['reason']])
                ->log('Project deletion rejected.');
        }

        $deletionRequest = $deletionRequest->fresh()->load(['project', 'requestedBy', 'reviewedBy']);

        if ($deletionRequest->requested_by) {
            Notification::notifyUsers(
                [$deletionRequest->requested_by],
                'project_deletion_rejected',
                __('notifications.project_deletion.rejected_title'),
                __('notifications.project_deletion.rejected_message', [
                    'project' => $deletionRequest->project_name,
                    'reason' => $validated['reason'],
                ]),
                $deletionRequest,
            );
        }

        return new ProjectDeletionRequestResource($deletionRequest);
    }
}
