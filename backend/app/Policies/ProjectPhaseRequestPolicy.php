<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Models\Project;
use App\Models\ProjectPhaseRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPhaseRequestPolicy
{
    // Same authority tier as assigning a committee — president or vice-president.
    private const REVIEW_ROLES = ['president', 'vice-president'];

    public function viewAny(User $user, Project $project): bool
    {
        return AuthorizationHelper::isAssignedToProject($user, $project)
            || $user->hasAnyRole(self::REVIEW_ROLES);
    }

    // Association-wide pending queue — review-authority roles only, not scoped
    // to any single project.
    public function viewPending(User $user): bool
    {
        return $user->hasAnyRole(self::REVIEW_ROLES);
    }

    // Only the project's committee leader may submit a phase change request —
    // never directly settable by anyone, not even the leader.
    public function create(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        return $user->committeeRoleFor($project) === 'leader';
    }

    public function review(User $user, ProjectPhaseRequest $phaseRequest): bool|Response
    {
        if (! $phaseRequest->project) {
            return false;
        }

        if ($response = AuthorizationHelper::projectLockResponse($phaseRequest->project)) {
            return $response;
        }

        if ($phaseRequest->status !== 'pending') {
            return Response::deny(__('policies.phase_request.only_pending_can_review'));
        }

        return $user->hasAnyRole(self::REVIEW_ROLES);
    }
}
