<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DonationResource;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\ProjectDeletionRequestResource;
use App\Http\Resources\ProjectPhaseRequestResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\ProjectDeletionRequest;
use App\Models\ProjectPhaseRequest;
use App\Models\Subscription;

/**
 * One consolidated read for the president dashboard's "what's waiting on
 * me" surfaces (the Action Center card and the sidebar's Approvals badge)
 * — both previously assembled this same picture client-side by fetching
 * every subscription/expense/donation in the whole association (unfiltered,
 * unpaginated) and filtering to status === 'pending' in the browser. Each
 * query here is filtered to 'pending' server-side instead, so the amount of
 * data ever leaving the database is the small pending subset, not the full
 * table.
 *
 * President-only: this mirrors, not changes, today's actual access — both
 * existing frontend call sites (ActionCenter, PresidentLayout's badge
 * effect) already gate themselves on isPresident before fetching, since the
 * five categories combined only make sense for the one role that can act on
 * all of them (a tresorier can't review phase/deletion requests; a
 * vice-président can't approve donations/expenses).
 */
class ActionCenterController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasRole('president'), 403);

        $subscriptions = Subscription::where('status', 'pending')
            ->with(['member.user', 'verifier', 'due'])
            ->latest('payment_date')
            ->get();

        $expenses = Expense::where('status', 'pending')
            ->with(['project', 'creator', 'approver', 'rejecter', 'payer'])
            ->latest()
            ->get();

        $donations = Donation::where('status', 'pending')
            ->with(['member.user', 'project', 'recorder', 'approver', 'rejecter'])
            ->latest()
            ->get();

        $phaseRequests = ProjectPhaseRequest::where('status', 'pending')
            ->with(['project', 'requestedBy', 'reviewedBy', 'proofs'])
            ->latest('requested_at')
            ->get();

        $deletionRequests = ProjectDeletionRequest::where('status', 'pending')
            ->with(['project', 'requestedBy', 'reviewedBy'])
            ->latest('requested_at')
            ->get();

        return response()->json([
            'items' => [
                'subscriptions' => SubscriptionResource::collection($subscriptions),
                'expenses' => ExpenseResource::collection($expenses),
                'donations' => DonationResource::collection($donations),
                'phase_requests' => ProjectPhaseRequestResource::collection($phaseRequests),
                'deletion_requests' => ProjectDeletionRequestResource::collection($deletionRequests),
            ],
            'counts' => [
                'subscriptions' => $subscriptions->count(),
                'expenses' => $expenses->count(),
                'donations' => $donations->count(),
                'phase_requests' => $phaseRequests->count(),
                'deletion_requests' => $deletionRequests->count(),
            ],
        ]);
    }
}
