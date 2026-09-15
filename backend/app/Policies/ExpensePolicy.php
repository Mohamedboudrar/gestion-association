<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Helpers\ProjectLifecycle;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    // President/treasurer/vice-treasurer are the only approval authority —
    // separate from the committee leader/treasurer roles that create/submit.
    private const APPROVAL_ROLES = ['president', 'tresorier', 'vice-tresorier'];

    public function viewAny(User $user): bool
    {
        return AuthorizationHelper::hasProjectAssignments($user) || $user->can('expenses.view');
    }

    public function view(User $user, Expense $expense): bool
    {
        if (! $expense->project) {
            return false;
        }

        return $user->committeeRoleFor($expense->project) !== null
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

    public function update(User $user, Expense $expense): bool|Response
    {
        if (! $expense->project) {
            return false;
        }

        if (! in_array($expense->status, ['draft', 'pending'], true)) {
            return Response::deny(__('policies.expense.already_decided_edit'));
        }

        return $this->create($user, $expense->project);
    }

    public function delete(User $user, Expense $expense): bool|Response
    {
        if (! $expense->project) {
            return false;
        }

        if (! in_array($expense->status, ['draft', 'pending'], true)) {
            return Response::deny(__('policies.expense.already_decided_delete'));
        }

        return $this->create($user, $expense->project);
    }

    // An expense's invoice can be attached/replaced while still editable
    // (draft/pending), or on a rejected expense specifically when the
    // rejection was an invoice issue (that's the "Replace Invoice" action —
    // a details-issue rejection is permanently read-only instead).
    private function canEditInvoice(Expense $expense): bool
    {
        return in_array($expense->status, ['draft', 'pending'], true)
            || ($expense->status === 'rejected' && $expense->rejection_type === 'invoice');
    }

    public function uploadInvoice(User $user, Expense $expense): bool|Response
    {
        if (! $expense->project) {
            return false;
        }

        if (! $this->canEditInvoice($expense)) {
            return Response::deny(__('policies.expense.invoice_locked'));
        }

        return $this->create($user, $expense->project);
    }

    // Draft -> pending. Same authority as creating (committee leader/treasurer).
    // Also allows "Submit Again": an invoice-issue rejection returning to
    // pending once the leader has replaced the invoice — a details-issue
    // rejection can never be resubmitted, only replaced by a new expense.
    public function submit(User $user, Expense $expense): bool|Response
    {
        if (! $expense->project) {
            return false;
        }

        if ($response = AuthorizationHelper::projectLockResponse($expense->project)) {
            return $response;
        }

        $isResubmittableRejection = $expense->status === 'rejected' && $expense->rejection_type === 'invoice';

        if ($expense->status !== 'draft' && ! $isResubmittableRejection) {
            return Response::deny(__('policies.expense.only_draft_or_rejected_invoice_can_submit'));
        }

        return in_array($user->committeeRoleFor($expense->project), ['leader', 'treasurer'], true);
    }

    // Pending -> approved. Approval authority only, and never the expense's own creator.
    public function approve(User $user, Expense $expense): bool|Response
    {
        if (! $expense->project) {
            return false;
        }

        if ($response = AuthorizationHelper::projectLockResponse($expense->project)) {
            return $response;
        }

        if ($expense->status !== 'pending') {
            return Response::deny(__('policies.expense.only_pending_can_approve'));
        }

        if ($expense->created_by === $user->id) {
            return Response::deny(__('policies.expense.cannot_approve_own'));
        }

        return $user->hasAnyRole(self::APPROVAL_ROLES);
    }

    // Pending -> rejected. Same audience/creator restriction as approve.
    public function reject(User $user, Expense $expense): bool|Response
    {
        if (! $expense->project) {
            return false;
        }

        if ($response = AuthorizationHelper::projectLockResponse($expense->project)) {
            return $response;
        }

        if ($expense->status !== 'pending') {
            return Response::deny(__('policies.expense.only_pending_can_reject'));
        }

        if ($expense->created_by === $user->id) {
            return Response::deny(__('policies.expense.cannot_reject_own'));
        }

        return $user->hasAnyRole(self::APPROVAL_ROLES);
    }

    // Approved -> paid. Approval authority only; nothing may follow this.
    public function markPaid(User $user, Expense $expense): bool|Response
    {
        if (! $expense->project) {
            return false;
        }

        if ($response = AuthorizationHelper::projectLockResponse($expense->project)) {
            return $response;
        }

        if ($expense->status !== 'approved') {
            return Response::deny(__('policies.expense.only_approved_can_mark_paid'));
        }

        return $user->hasAnyRole(self::APPROVAL_ROLES);
    }
}
