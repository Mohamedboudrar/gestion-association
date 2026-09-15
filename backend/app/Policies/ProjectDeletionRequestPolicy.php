<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Models\Project;
use App\Models\ProjectDeletionRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectDeletionRequestPolicy
{
    // Only the president reviews deletion requests — unlike phase requests
    // (president + vice-president), deletion is a president-only authority
    // (see ProjectPolicy::delete), so the review authority matches exactly.
    private const REVIEW_ROLES = ['president'];

    public function viewAny(User $user, Project $project): bool
    {
        return $user->hasRole('president') || $user->committeeRoleFor($project) === 'leader';
    }

    // Association-wide pending queue, for the president's review page.
    public function viewPending(User $user): bool
    {
        return $user->hasAnyRole(self::REVIEW_ROLES);
    }

    // Only the project's committee leader may request deletion — never
    // directly settable, and only for a project still eligible for deletion
    // (same lock gate as ProjectPolicy::delete).
    public function create(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        return $user->committeeRoleFor($project) === 'leader';
    }

    public function review(User $user, ProjectDeletionRequest $deletionRequest): bool|Response
    {
        if ($deletionRequest->status !== 'pending') {
            return Response::deny(__('policies.project_deletion_request.only_pending_can_review'));
        }

        if ($deletionRequest->project && ($response = AuthorizationHelper::projectLockResponse($deletionRequest->project))) {
            return $response;
        }

        return $user->hasAnyRole(self::REVIEW_ROLES);
    }
}
