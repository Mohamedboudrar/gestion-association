<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectPhaseRequestRequest;
use App\Http\Resources\ProjectPhaseRequestResource;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectPhaseRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectPhaseRequestController extends Controller
{
    // Full history for one project — never hard-deleted, so this always
    // includes every past request regardless of outcome.
    public function index(Project $project)
    {
        $this->authorize('viewAny', [ProjectPhaseRequest::class, $project]);

        return ProjectPhaseRequestResource::collection(
            $project->phaseRequests()
                ->with(['requestedBy', 'reviewedBy', 'proofs'])
                ->latest('requested_at')
                ->get()
        );
    }

    // Association-wide pending queue, for the president's review page.
    public function pending()
    {
        $this->authorize('viewPending', ProjectPhaseRequest::class);

        return ProjectPhaseRequestResource::collection(
            ProjectPhaseRequest::where('status', 'pending')
                ->with(['project', 'requestedBy', 'reviewedBy', 'proofs'])
                ->latest('requested_at')
                ->get()
        );
    }

    public function store(StoreProjectPhaseRequestRequest $request, Project $project)
    {
        $this->authorize('create', [ProjectPhaseRequest::class, $project]);

        $phaseRequest = DB::transaction(function () use ($request, $project) {
            $phaseRequest = ProjectPhaseRequest::create([
                'project_id' => $project->id,
                'from_phase' => $project->phase,
                'to_phase' => $request->validated('to_phase'),
                'summary' => $request->validated('summary'),
                'notes' => $request->validated('notes'),
                'requested_by' => auth()->id(),
                'requested_at' => now(),
                'status' => 'pending',
            ]);

            foreach ($request->file('proofs') ?? [] as $file) {
                $path = $file->store('project-phase-proofs', 'public');

                $phaseRequest->proofs()->create([
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);
            }

            return $phaseRequest;
        });

        $phaseRequest->load(['requestedBy', 'reviewedBy', 'proofs']);

        // Same review authority as ProjectPhaseRequestPolicy::REVIEW_ROLES —
        // président/vice-président, not the wider financial-approval trio
        // (trésorier can't review phase requests, so notifying them would
        // just be noise about something they have no ability to act on).
        Notification::notifyRoles(
            ['president', 'vice-president'],
            'phase_request_pending',
            __('notifications.phase_request.pending_title'),
            __('notifications.phase_request.pending_message', [
                'actor' => $phaseRequest->requestedBy?->name ?? __('notifications.people.committee_leader'),
                'project' => $project->name,
            ]),
            $phaseRequest,
        );

        return new ProjectPhaseRequestResource($phaseRequest);
    }

    public function approve(ProjectPhaseRequest $phaseRequest)
    {
        $this->authorize('review', $phaseRequest);

        DB::transaction(function () use ($phaseRequest) {
            $phaseRequest->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            $phaseRequest->project->update(['phase' => $phaseRequest->to_phase]);
        });

        $phaseRequest = $phaseRequest->fresh()->load(['requestedBy', 'reviewedBy', 'proofs', 'project']);

        if ($phaseRequest->requested_by) {
            Notification::notifyUsers(
                [$phaseRequest->requested_by],
                'phase_request_approved',
                __('notifications.phase_request.approved_title'),
                __('notifications.phase_request.approved_message', ['project' => $phaseRequest->project->name]),
                $phaseRequest,
            );
        }

        return new ProjectPhaseRequestResource($phaseRequest);
    }

    public function reject(Request $request, ProjectPhaseRequest $phaseRequest)
    {
        $this->authorize('review', $phaseRequest);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $phaseRequest->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'rejection_reason' => $validated['reason'],
        ]);

        $phaseRequest = $phaseRequest->fresh()->load(['requestedBy', 'reviewedBy', 'proofs', 'project']);

        if ($phaseRequest->requested_by) {
            Notification::notifyUsers(
                [$phaseRequest->requested_by],
                'phase_request_rejected',
                __('notifications.phase_request.rejected_title'),
                __('notifications.phase_request.rejected_message', [
                    'project' => $phaseRequest->project->name,
                    'reason' => $validated['reason'],
                ]),
                $phaseRequest,
            );
        }

        return new ProjectPhaseRequestResource($phaseRequest);
    }
}
