<?php

namespace App\Helpers;

use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AuthorizationHelper
{
    /**
     * Single source of truth for the "project hard lock" behavior: completed
     * or cancelled projects become read-only. Every policy that gates a
     * project mutation (or a mutation on a project's donations, expenses,
     * fund allocations, or committee) calls this first and
     * returns its result when non-null.
     */
    public static function projectLockResponse(Project $project): ?Response
    {
        if (! $project->isLocked()) {
            return null;
        }

        return Response::deny(__('policies.project.locked', ['status' => $project->status]));
    }
    /**
     * Check if user is president
     */
    public static function isPresident(User $user): bool
    {
        return $user->hasRole('president');
    }

    /**
     * Check if user is assigned to a specific project
     */
    public static function isAssignedToProject(User $user, Project $project): bool
    {
        if (self::isPresident($user)) {
            return true;
        }

        $member = $user->member;

        if (! $member) {
            return false;
        }

        return $project->members()
            ->whereKey($member->id)
            ->exists();
    }

    /**
     * Single source of truth for "can this user see/access data belonging to
     * this specific project at all" — the one gate every project-scoped
     * endpoint (the project itself, its donations/expenses/phase
     * requests/reports/committee roster/allocations) must go through. Bureau
     * roles (every role except plain "abonne") have association-wide
     * oversight and see every project; a plain subscriber only has access to
     * a project they are an actual committee member of — never merely by
     * holding a generic "*.view" permission. Deliberately the only path to
     * project access so it can't drift out of sync across policies.
     */
    public static function canAccessProject(User $user, Project $project): bool
    {
        if (self::isBureauMember($user)) {
            return true;
        }

        return self::isAssignedToProject($user, $project);
    }

    /**
     * Get project IDs assigned to user
     */
    public static function getAssignedProjectIds(User $user): array
    {
        if (self::isPresident($user)) {
            return [];
        }

        $member = $user->member;

        if (! $member) {
            return [];
        }

        return $member->projects()->pluck('projects.id')->toArray();
    }

    /**
     * Check if user has any project assignments
     */
    public static function hasProjectAssignments(User $user): bool
    {
        if (self::isPresident($user)) {
            return true;
        }

        $member = $user->member;

        if (! $member) {
            return false;
        }

        return $member->projects()->exists();
    }

    /**
     * Committee leader/treasurer on at least one project — the narrower
     * "actually manages a project's donations" tier, as opposed to
     * hasProjectAssignments() (any committee role at all, including a plain
     * 'member'/'secretary' seat with no financial responsibility). Used by
     * DonationPolicy so a subscriber who is merely a committee member (not
     * leader/treasurer) never sees another donor's itemized records — only
     * a project's aggregate totals via ProjectResource.
     */
    public static function hasFinancialCommitteeRole(User $user): bool
    {
        if (self::isPresident($user)) {
            return true;
        }

        $member = $user->member;

        if (! $member) {
            return false;
        }

        return $member->projects()
            ->wherePivotIn('committee_role', ['leader', 'treasurer'])
            ->exists();
    }

    /**
     * Check if user can manage project (president only)
     */
    public static function canManageProject(User $user): bool
    {
        return self::isPresident($user);
    }

    /**
     * Check if user can manage project resources (assigned members)
     */
    public static function canManageProjectResources(User $user, Project $project): bool
    {
        return self::isAssignedToProject($user, $project);
    }

    /**
     * Any bureau role (everything except the plain "abonne" subscriber role).
     * Used to distinguish "sees the full directory/list" from "sees own record only".
     */
    public static function isBureauMember(User $user): bool
    {
        return $user->hasAnyRole([
            'president',
            'vice-president',
            'tresorier',
            'vice-tresorier',
            'secretaire-general',
            'vice-secretaire-general',
            'conseiller',
        ]);
    }

    /**
     * President + trésorier/vice-trésorier — the association's financial
     * approval authority. Exposed as a public constant (not just the boolean
     * check below) so notification triggers can pass it straight to
     * Notification::notifyRoles() — expense/donation "waiting for approval"
     * notifications go to exactly this list, matching who ExpensePolicy/
     * DonationPolicy::APPROVAL_ROLES actually lets approve/reject.
     */
    public const FINANCIAL_OVERSIGHT_ROLES = ['president', 'tresorier', 'vice-tresorier'];

    /**
     * President + trésorier/vice-trésorier: the association-wide financial
     * oversight tier that sees every donation/expense regardless of project
     * assignment (narrower than isBureauMember(), which also includes
     * non-financial bureau roles). Single source of truth for this check —
     * previously duplicated inline in DonationController/ExpenseController's
     * index() scoping and DonationPolicy/ExpensePolicy's view(), which had
     * drifted out of sync (view() was missing vice-tresorier).
     */
    public static function isFinancialOversightRole(User $user): bool
    {
        return $user->hasAnyRole(self::FINANCIAL_OVERSIGHT_ROLES);
    }

    /**
     * President, vice-président, trésorier: the roles the Activity Explorer
     * spec grants unrestricted visibility into every activity log entry,
     * regardless of project assignment. Every other bureau role only sees
     * activity for projects they're actually assigned to (see
     * ActivityLogHelper::scopeToAssignedProjects); "abonne" subscribers see
     * nothing at all (ActivityPolicy::viewAny denies them outright).
     */
    public static function canViewAllActivities(User $user): bool
    {
        return $user->hasAnyRole(['president', 'vice-president', 'tresorier']);
    }

    /**
     * Regular ("abonne") members are only committee-eligible once they have
     * at least one verified subscription. Bureau members are always eligible
     * regardless of subscription status — see isBureauMember().
     */
    public static function hasVerifiedSubscription(Member $member): bool
    {
        return $member->subscriptions()->where('status', 'verified')->exists();
    }
}
