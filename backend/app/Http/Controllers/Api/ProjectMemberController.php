<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AuthorizationHelper;
use App\Helpers\ProjectLifecycle;
use App\Http\Controllers\Controller;
use App\Models\CommitteeAssignment;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectMemberController extends Controller
{
    // Active committee roster. Was previously gated only by a blanket
    // "projects.view" permission (held by every role including plain
    // "abonne"), with no check against *this* project at all — any
    // authenticated user could list any project's committee roster by ID.
    // Now delegates to the same centralized per-project gate as viewing the
    // project itself.
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json(
            $project->members()->with('user')->get()
        );
    }

    // Full assignment/removal history for this project's committee — never
    // hard-deleted, so this always includes every past assignment.
    public function history(Project $project)
    {
        $this->authorize('view', $project);

        return response()->json(
            $project->committeeAssignments()
                ->with(['member.user', 'assignedBy', 'removedBy'])
                ->orderByDesc('assigned_at')
                ->get()
        );
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('assignCommittee', $project);

        $validated = $request->validate([
            'member_id' => 'required|exists:members,id',
            'role' => 'nullable|string|max:100',
            'committee_role' => 'nullable|in:leader,treasurer,secretary,member',
        ]);

        $targetMember = Member::findOrFail($validated['member_id']);

        // Committees: bureau members are always eligible; regular ("abonne")
        // members need a verified subscription to be assigned.
        abort_unless(
            AuthorizationHelper::isBureauMember($targetMember->user)
                || AuthorizationHelper::hasVerifiedSubscription($targetMember),
            422,
            __('messages.project_member.needs_verified_subscription')
        );

        abort_if(
            $project->members()->whereKey($targetMember->id)->exists(),
            422,
            __('messages.project_member.already_assigned')
        );

        $committeeRole = $validated['committee_role'] ?? 'member';
        $now = now();

        DB::transaction(function () use ($project, $targetMember, $validated, $committeeRole, $now) {
            $project->members()->attach($targetMember->id, [
                'role' => $validated['role'] ?? null,
                'committee_role' => $committeeRole,
                'assigned_by' => auth()->id(),
                'assigned_at' => $now,
            ]);

            CommitteeAssignment::create([
                'project_id' => $project->id,
                'member_id' => $targetMember->id,
                'role' => $validated['role'] ?? null,
                'committee_role' => $committeeRole,
                'assigned_by' => auth()->id(),
                'assigned_at' => $now,
                'action' => 'assigned',
            ]);

            // The first committee assignment on a draft project advances it
            // to committee_ready — see ProjectLifecycle.
            if ($project->status === ProjectLifecycle::DRAFT) {
                $project->update(['status' => ProjectLifecycle::COMMITTEE_READY]);
            }
        });

        if ($targetMember->user_id) {
            Notification::notifyUsers(
                [$targetMember->user_id],
                'committee_assigned',
                __('notifications.committee.assigned_title'),
                __('notifications.committee.assigned_message', ['project' => $project->name]),
                $project,
            );
        }

        return response()->json([
            'message' => __('messages.project_member.assigned'),
        ]);
    }

    public function destroy(Request $request, Project $project, Member $member)
    {
        // Removing a committee member is president-only — see ProjectPolicy::removeCommittee.
        $this->authorize('removeCommittee', $project);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($project, $member, $validated) {
            $this->closeOpenAssignment($project, $member, 'removed', $validated['reason'] ?? null);
            $project->members()->detach($member->id);
        });

        if ($member->user_id) {
            Notification::notifyUsers(
                [$member->user_id],
                'committee_removed',
                __('notifications.committee.removed_title'),
                __('notifications.committee.removed_message', ['project' => $project->name]),
                $project,
            );
        }

        return response()->json([
            'message' => __('messages.project_member.removed'),
        ]);
    }

    // A committee member resigns their own seat.
    public function resign(Request $request, Project $project, Member $member)
    {
        $this->authorize('resignCommittee', [$project, $member]);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($project, $member, $validated) {
            $this->closeOpenAssignment($project, $member, 'resigned', $validated['reason'] ?? null);
            $project->members()->detach($member->id);
        });

        return response()->json([
            'message' => __('messages.project_member.resigned'),
        ]);
    }

    // Swap one committee member for another in the same seat, preserving the
    // outgoing member's history (closed with action=replaced) and opening a
    // fresh history row for the incoming member.
    public function replace(Request $request, Project $project, Member $member)
    {
        $this->authorize('assignCommittee', $project);

        abort_unless(
            $project->members()->whereKey($member->id)->exists(),
            422,
            __('messages.project_member.not_assigned')
        );

        $validated = $request->validate([
            'new_member_id' => 'required|exists:members,id',
            'reason' => 'nullable|string|max:500',
        ]);

        abort_if(
            (int) $validated['new_member_id'] === $member->id,
            422,
            __('messages.project_member.choose_different_member')
        );

        $newMember = Member::findOrFail($validated['new_member_id']);

        abort_if(
            $project->members()->whereKey($newMember->id)->exists(),
            422,
            __('messages.project_member.replacement_already_assigned')
        );

        abort_unless(
            AuthorizationHelper::isBureauMember($newMember->user)
                || AuthorizationHelper::hasVerifiedSubscription($newMember),
            422,
            __('messages.project_member.replacement_needs_verified_subscription')
        );

        $outgoingPivot = $project->members()->whereKey($member->id)->first()->pivot;
        $now = now();

        DB::transaction(function () use ($project, $member, $newMember, $outgoingPivot, $validated, $now) {
            $this->closeOpenAssignment($project, $member, 'replaced', $validated['reason'] ?? null);
            $project->members()->detach($member->id);

            $project->members()->attach($newMember->id, [
                'role' => $outgoingPivot->role,
                'committee_role' => $outgoingPivot->committee_role,
                'responsibility' => $outgoingPivot->responsibility,
                'assigned_by' => auth()->id(),
                'assigned_at' => $now,
            ]);

            CommitteeAssignment::create([
                'project_id' => $project->id,
                'member_id' => $newMember->id,
                'role' => $outgoingPivot->role,
                'committee_role' => $outgoingPivot->committee_role,
                'responsibility' => $outgoingPivot->responsibility,
                'assigned_by' => auth()->id(),
                'assigned_at' => $now,
                'action' => 'assigned',
            ]);
        });

        if ($member->user_id) {
            Notification::notifyUsers(
                [$member->user_id],
                'committee_removed',
                __('notifications.committee.removed_title'),
                __('notifications.committee.replaced_message', ['project' => $project->name]),
                $project,
            );
        }

        if ($newMember->user_id) {
            Notification::notifyUsers(
                [$newMember->user_id],
                'committee_assigned',
                __('notifications.committee.assigned_title'),
                __('notifications.committee.assigned_message', ['project' => $project->name]),
                $project,
            );
        }

        return response()->json([
            'message' => __('messages.project_member.replaced'),
        ]);
    }

    // Closes the currently-open history row (if any) for this project/member
    // pair — never deletes it, only stamps how/why/when/who ended it. Shared
    // by destroy/resign/replace; project completion/cancellation dissolves
    // via ProjectController instead (it closes every open row at once).
    private function closeOpenAssignment(Project $project, Member $member, string $action, ?string $reason): void
    {
        CommitteeAssignment::where('project_id', $project->id)
            ->where('member_id', $member->id)
            ->whereNull('removed_at')
            ->latest('assigned_at')
            ->first()
            ?->update([
                'removed_by' => auth()->id(),
                'removed_at' => now(),
                'reason' => $reason,
                'action' => $action,
            ]);
    }
}
