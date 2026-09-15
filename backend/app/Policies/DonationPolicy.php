<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Helpers\ProjectLifecycle;
use App\Models\Donation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DonationPolicy
{
    // President/treasurer/vice-treasurer are the only approval authority —
    // separate from the committee leader/treasurer roles that create/submit.
    private const APPROVAL_ROLES = ['president', 'tresorier', 'vice-tresorier'];

    // Narrower than AuthorizationHelper::hasProjectAssignments() deliberately:
    // any committee seat used to be enough to list donations, which let a
    // plain committee member (not leader/treasurer) see other donors' names
    // and receipts. Only whoever actually manages a project's finances
    // (leader/treasurer) or holds a bureau donations.view permission can list
    // individual donation records — everyone else gets project aggregate
    // totals only, via ProjectResource (see ProjectController/CommitteeProjectPage).
    public function viewAny(User $user): bool
    {
        return AuthorizationHelper::hasFinancialCommitteeRole($user) || $user->can('donations.view');
    }

    public function view(User $user, Donation $donation): bool
    {
        if (! $donation->project) {
            return false;
        }

        return in_array($user->committeeRoleFor($donation->project), ['leader', 'treasurer'], true)
            || AuthorizationHelper::isFinancialOversightRole($user);
    }

    public function create(User $user, Project $project): bool|Response
    {
        if ($response = AuthorizationHelper::projectLockResponse($project)) {
            return $response;
        }

        if ($response = ProjectLifecycle::requiresActiveResponse($project)) {
            return $response;
        }

        return in_array($user->committeeRoleFor($project), ['leader', 'treasurer'], true);
    }

    public function update(User $user, Donation $donation): bool|Response
    {
        if (! $donation->project) {
            return false;
        }

        if (! in_array($donation->status, ['draft', 'pending'], true)) {
            return Response::deny(__('policies.donation.already_decided_edit'));
        }

        return $this->create($user, $donation->project);
    }

    public function delete(User $user, Donation $donation): bool|Response
    {
        if (! $donation->project) {
            return false;
        }

        if (! in_array($donation->status, ['draft', 'pending'], true)) {
            return Response::deny(__('policies.donation.already_decided_delete'));
        }

        return $this->create($user, $donation->project);
    }

    // A donation's receipt can be attached/replaced while still editable
    // (draft/pending), or on a rejected donation specifically when the
    // rejection was a receipt issue (that's the "Replace Receipt" action —
    // a details-issue rejection is permanently read-only instead).
    private function canEditReceipt(Donation $donation): bool
    {
        return in_array($donation->status, ['draft', 'pending'], true)
            || ($donation->status === 'rejected' && $donation->rejection_type === 'receipt');
    }

    public function uploadReceipt(User $user, Donation $donation): bool|Response
    {
        if (! $donation->project) {
            return false;
        }

        if (! $this->canEditReceipt($donation)) {
            return Response::deny(__('policies.donation.receipt_locked'));
        }

        return $this->create($user, $donation->project);
    }

    // Draft -> pending. Same authority as creating (committee leader/treasurer).
    // Also allows "Submit Again": a receipt-issue rejection returning to
    // pending once the leader has replaced the receipt — a details-issue
    // rejection can never be resubmitted, only replaced by a new donation.
    public function submit(User $user, Donation $donation): bool|Response
    {
        if (! $donation->project) {
            return false;
        }

        if ($response = AuthorizationHelper::projectLockResponse($donation->project)) {
            return $response;
        }

        $isResubmittableRejection = $donation->status === 'rejected' && $donation->rejection_type === 'receipt';

        if ($donation->status !== 'draft' && ! $isResubmittableRejection) {
            return Response::deny(__('policies.donation.only_draft_or_rejected_receipt_can_submit'));
        }

        return in_array($user->committeeRoleFor($donation->project), ['leader', 'treasurer'], true);
    }

    // Pending -> approved. Approval authority only, and never the donation's own recorder.
    public function approve(User $user, Donation $donation): bool|Response
    {
        if (! $donation->project) {
            return false;
        }

        if ($response = AuthorizationHelper::projectLockResponse($donation->project)) {
            return $response;
        }

        if ($donation->status !== 'pending') {
            return Response::deny(__('policies.donation.only_pending_can_approve'));
        }

        if ($donation->recorded_by === $user->id) {
            return Response::deny(__('policies.donation.cannot_approve_own'));
        }

        return $user->hasAnyRole(self::APPROVAL_ROLES);
    }

    // Pending -> rejected. Same audience/recorder restriction as approve.
    public function reject(User $user, Donation $donation): bool|Response
    {
        if (! $donation->project) {
            return false;
        }

        if ($response = AuthorizationHelper::projectLockResponse($donation->project)) {
            return $response;
        }

        if ($donation->status !== 'pending') {
            return Response::deny(__('policies.donation.only_pending_can_reject'));
        }

        if ($donation->recorded_by === $user->id) {
            return Response::deny(__('policies.donation.cannot_reject_own'));
        }

        return $user->hasAnyRole(self::APPROVAL_ROLES);
    }
}
