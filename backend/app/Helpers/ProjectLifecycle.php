<?php

namespace App\Helpers;

use App\Models\Project;
use Illuminate\Auth\Access\Response;

/**
 * Single source of truth for the project lifecycle state machine:
 *
 *   draft -> committee_ready -> funding_ready -> active -> completed
 *                                                       \-> cancelled (from draft/committee_ready/funding_ready/active)
 *
 * committee_ready and funding_ready are reached automatically as a side effect
 * of the first committee assignment / first fund allocation (see
 * ProjectMemberController::store and ProjectFundAllocationController::store).
 * active and completed each have a dedicated action (start()/close()). The
 * only status change allowed through the generic project update endpoint is
 * cancellation — every other transition must go through one of those actions
 * so it can never be skipped or forced out of order.
 */
class ProjectLifecycle
{
    public const DRAFT = 'draft';

    public const COMMITTEE_READY = 'committee_ready';

    public const FUNDING_READY = 'funding_ready';

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const TERMINAL = [self::COMPLETED, self::CANCELLED];

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function canManuallySetStatus(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        if ($to === self::CANCELLED) {
            return ! self::isTerminal($from);
        }

        return false;
    }

    // Donations and expenses may only be recorded once a project is active.
    public static function requiresActiveResponse(Project $project): ?Response
    {
        if ($project->status === self::ACTIVE) {
            return null;
        }

        return Response::deny(__('policies.project.must_be_active'));
    }

    // Fund allocations open up once a committee exists (committee_ready) and stay
    // available through funding_ready and active. The first allocation on a
    // committee_ready project is what advances it into funding_ready.
    public static function canReceiveAllocation(Project $project): bool
    {
        return in_array($project->status, [self::COMMITTEE_READY, self::FUNDING_READY, self::ACTIVE], true);
    }

    public static function allocationResponse(Project $project): ?Response
    {
        if (self::canReceiveAllocation($project)) {
            return null;
        }

        return Response::deny(__('policies.project.needs_committee_for_allocations'));
    }
}
