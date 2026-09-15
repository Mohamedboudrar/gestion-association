<?php

namespace App\Http\Resources;

use App\Models\Donation;
use App\Models\Due;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectDeletionRequest;
use App\Models\ProjectFundAllocation;
use App\Models\ProjectPhaseRequest;
use App\Models\ProjectReport;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    // Which of the 7 notification-center categories a type belongs to —
    // drives the Notifications page's "Filter by Type" and the dropdown's
    // icon. "Approvals" isn't its own category here: expense_pending/
    // donation_pending/phase_request_pending are the "waiting for approval"
    // notifications the task's Approvals section describes, but they're
    // still each entity's own natural category (an approval notification
    // about an expense is still an expense notification) — a separate
    // "approvals" bucket would just be a filtered view of the same 3 types
    // spread across 3 other categories, not a 4th category of its own.
    private const TYPE_CATEGORIES = [
        'subscription_pending' => 'membership',
        'subscription_approved' => 'membership',
        'subscription_rejected' => 'membership',
        'subscription_expiring_30' => 'membership',
        'subscription_expiring_7' => 'membership',
        'subscription_expiring_today' => 'membership',
        'subscription_expired' => 'membership',
        'due_created' => 'membership',
        'due_paid' => 'membership',
        'due_overdue' => 'membership',
        'phase_request_pending' => 'projects',
        'phase_request_approved' => 'projects',
        'phase_request_rejected' => 'projects',
        'project_closed' => 'projects',
        'project_completed' => 'projects',
        'project_report_generated' => 'projects',
        'project_overdue' => 'projects',
        'project_deletion_pending' => 'projects',
        'project_deletion_approved' => 'projects',
        'project_deletion_rejected' => 'projects',
        'expense_pending' => 'expenses',
        'expense_resubmitted' => 'expenses',
        'expense_approved' => 'expenses',
        'expense_rejected' => 'expenses',
        'donation_pending' => 'donations',
        'donation_resubmitted' => 'donations',
        'donation_approved' => 'donations',
        'donation_rejected' => 'donations',
        'committee_assigned' => 'committee',
        'committee_removed' => 'committee',
    ];

    public static function typesForCategory(string $category): array
    {
        return array_keys(array_filter(self::TYPE_CATEGORIES, fn ($value) => $value === $category));
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => self::TYPE_CATEGORIES[$this->type] ?? 'other',
            'title' => $this->title,
            'message' => $this->message,
            'read_at' => $this->read_at,
            'is_read' => $this->read_at !== null,
            'link' => $this->resolveLink(),
            'created_at' => $this->created_at,
        ];
    }

    // Every notification's subject already carries its own project_id (or,
    // for a Project subject, is the project) — no extra query needed beyond
    // the subject itself being eager-loaded by the controller.
    private function resolveLink(): ?string
    {
        $subject = $this->subject;

        if (! $subject) {
            return null;
        }

        // The president's review queue is the more useful destination for
        // "waiting for approval" than the project page.
        if ($this->type === 'phase_request_pending' && $subject instanceof ProjectPhaseRequest) {
            return '/phase-requests/pending';
        }

        if ($this->type === 'project_deletion_pending' && $subject instanceof ProjectDeletionRequest) {
            return '/deletion-requests/pending';
        }

        // An approved deletion request's project is gone by the time this is
        // read — project_id is null (see ProjectDeletionRequest's nullOnDelete)
        // and there's nowhere left to deep-link to. A rejected request's
        // project is still alive, so send the requester back to it.
        if ($subject instanceof ProjectDeletionRequest) {
            return $subject->project_id ? "/projects/{$subject->project_id}" : null;
        }

        return match (true) {
            $subject instanceof Project => "/projects/{$subject->id}",
            $subject instanceof Donation,
            $subject instanceof Expense,
            $subject instanceof ProjectFundAllocation,
            $subject instanceof ProjectPhaseRequest,
            $subject instanceof ProjectReport => $subject->project_id ? "/projects/{$subject->project_id}" : null,
            $subject instanceof Subscription,
            $subject instanceof Due => '/subscriptions',
            default => null,
        };
    }
}
