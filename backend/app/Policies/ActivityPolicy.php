<?php

namespace App\Policies;

use App\Helpers\ActivityLogHelper;
use App\Helpers\AuthorizationHelper;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        if (AuthorizationHelper::canViewAllActivities($user)) {
            return true;
        }

        // Every other bureau role: allowed in (scoped to their assigned
        // projects at the query level, see ActivityLogHelper), as long as
        // they actually have at least one assignment. A plain "abonne"
        // subscriber has no access at all, regardless of committee
        // membership — matches the Activity Explorer spec's explicit
        // "Subscribers have no access".
        if (! AuthorizationHelper::isBureauMember($user)) {
            return false;
        }

        return AuthorizationHelper::hasProjectAssignments($user);
    }

    public function view(User $user, Activity $activity): bool
    {
        if (AuthorizationHelper::canViewAllActivities($user)) {
            return true;
        }

        if (! AuthorizationHelper::isBureauMember($user)) {
            return false;
        }

        $member = $user->member;

        if (! $member) {
            return false;
        }

        $entityKey = ActivityLogHelper::entityKeyFor($activity->subject_type);
        $meta = $entityKey ? ActivityLogHelper::ENTITIES[$entityKey] : null;
        $subject = $activity->subject;

        if (! $meta || ! $subject) {
            return false;
        }

        $projectId = $entityKey === 'project' ? $subject->id : ($meta['project_column'] ? $subject->{$meta['project_column']} : null);

        if (! $projectId) {
            return false;
        }

        return $member->projects()->whereKey($projectId)->exists();
    }
}
