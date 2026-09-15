<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Helpers\ProjectLifecycle;
use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    // Every authenticated user may hit the list endpoint — bureau roles see
    // every project, a plain subscriber gets their own (possibly empty)
    // scoped list, never a 403. See ProjectController::index(), which
    // already self-scopes (and gracefully returns an empty collection for a
    // subscriber with no member profile or no committee assignments) —
    // requiring an existing assignment here would 403 before that logic
    // ever runs, for the exact "no projects yet" case it's meant to handle.
    public function viewAny(User $user): bool
    {
        return AuthorizationHelper::isBureauMember($user) || $user->member !== null;
    }

    // Single centralized gate — see AuthorizationHelper::canAccessProject().
    // Bureau roles see every project; a plain subscriber only ones they're
    // an actual committee member of. Never a blanket "*.view" permission bypass.
    public function view(User $user, Project $project): bool
    {
        return AuthorizationHelper::canAccessProject($user, $project);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['president', 'vice-president']);
    }

    public function update(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        return $user->hasAnyRole(['president', 'vice-president'])
            || $user->committeeRoleFor($project) === 'leader';
    }

    public function delete(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        return $user->hasRole('president');
    }

    public function assignCommittee(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        return $user->hasAnyRole(['president', 'vice-president']);
    }

    // Removing a committee member is president-only — vice-president can
    // add/edit committee assignments (assignCommittee) but not remove them.
    public function removeCommittee(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        return $user->hasRole('president');
    }

    // Manual funding_ready -> active transition ("Start Project"). Same
    // authority as closing a project — president or the committee leader.
    public function start(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        if ($project->status !== ProjectLifecycle::FUNDING_READY) {
            return Response::deny(__('policies.project.only_funding_ready_can_start'));
        }

        return $user->hasRole('president')
            || $user->committeeRoleFor($project) === 'leader';
    }

    public function close(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        if ($project->status !== ProjectLifecycle::ACTIVE) {
            return Response::deny(__('policies.project.only_active_can_close'));
        }

        return $user->hasRole('president')
            || $user->committeeRoleFor($project) === 'leader';
    }

    // Resigning is self-service only — a committee member may resign their
    // own seat, but cannot resign on behalf of anyone else.
    public function resignCommittee(User $user, Project $project, Member $member): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        if (! $user->member || $user->member->id !== $member->id) {
            return false;
        }

        return $project->members()->whereKey($member->id)->exists();
    }
}
