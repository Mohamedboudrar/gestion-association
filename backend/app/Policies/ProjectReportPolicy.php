<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Models\CommitteeAssignment;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\User;

class ProjectReportPolicy
{
    // A report only ever exists once its project is completed — which is
    // also the moment ProjectController::close() dissolves the committee
    // (member_project pivot detached in the same transaction). Checking only
    // the live pivot via committeeRoleFor() would 403 the very people the
    // report is for the instant it's generated, so this also accepts
    // CommitteeAssignment history — the permanent record of every seat this
    // member ever held on the project, dissolved or not.
    public function viewAny(User $user, Project $project): bool
    {
        return $user->committeeRoleFor($project) !== null
            || AuthorizationHelper::isBureauMember($user)
            || ($user->member && CommitteeAssignment::where('project_id', $project->id)->where('member_id', $user->member->id)->exists());
    }

    public function view(User $user, ProjectReport $projectReport): bool
    {
        if (! $projectReport->project) {
            return false;
        }

        return $this->viewAny($user, $projectReport->project);
    }
}
