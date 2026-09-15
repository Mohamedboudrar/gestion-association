<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Helpers\ProjectLifecycle;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectFundAllocationPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->committeeRoleFor($project) !== null
            || $user->hasAnyRole(['president', 'tresorier', 'vice-tresorier']);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->committeeRoleFor($project) !== null
            || $user->hasAnyRole(['president', 'tresorier', 'vice-tresorier']);
    }

    public function create(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        if ($response = ProjectLifecycle::allocationResponse($project)) {
            return $response;
        }

        return $user->hasAnyRole(['president', 'tresorier', 'vice-tresorier']);
    }
}
