<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AuthorizationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDonationRequest;
use App\Http\Requests\UpdateDonationRequest;
use App\Http\Resources\DonationResource;
use App\Models\Donation;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DonationController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Donation::class);

        $query = Donation::with(['member.user', 'project', 'recorder', 'approver', 'rejecter']);

        // Président and Trésorier/Vice-trésorier see every donation (association-wide
        // financial oversight, matching DonationPolicy::view()'s tresorier bypass).
        // Everyone else is scoped to projects where they're the committee
        // leader/treasurer — matches DonationPolicy::viewAny's narrower
        // hasFinancialCommitteeRole() check, not every committee assignment.
        if (! AuthorizationHelper::isFinancialOversightRole(auth()->user())) {
            $member = auth()->user()->member;
            $projectIds = $member
                ? $member->projects()->wherePivotIn('committee_role', ['leader', 'treasurer'])->pluck('projects.id')
                : collect();
            $query->whereIn('project_id', $projectIds);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->query('project_id'));
        }

        return DonationResource::collection(
            $query->latest('donation_date')
                ->latest()
                ->get()
        );
    }

    public function store(StoreDonationRequest $request)
    {
        $project = Project::findOrFail($request->validated('project_id'));
        $this->authorize('create', [Donation::class, $project]);

        $donation = Donation::create([
            ...$request->validated(),
            'recorded_by' => auth()->id(),
            'status' => 'draft',
        ]);

        return new DonationResource(
            $donation->load(['member.user', 'project', 'recorder', 'approver', 'rejecter'])
        );
    }

    public function show(Donation $donation)
    {
        $this->authorize('view', $donation);

        return new DonationResource(
            $donation->load(['member.user', 'project', 'recorder', 'approver', 'rejecter'])
        );
    }

    public function update(UpdateDonationRequest $request, Donation $donation)
    {
        $this->authorize('update', $donation);

        $donation->update($request->validated());

        return new DonationResource(
            $donation->load(['member.user', 'project', 'recorder', 'approver', 'rejecter'])
        );
    }

    public function destroy(Donation $donation)
    {
        $this->authorize('delete', $donation);

        if ($donation->receipt_file) {
            Storage::disk('public')->delete($donation->receipt_file);
        }

        $donation->delete();

        return response()->json([
            'message' => __('messages.donation.deleted'),
        ]);
    }

    public function uploadReceipt(Request $request, Donation $donation)
    {
        $this->authorize('uploadReceipt', $donation);

        $request->validate([
            'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($donation->receipt_file) {
            Storage::disk('public')->delete($donation->receipt_file);
        }

        $path = $request->file('receipt')->store('donation-receipts', 'public');

        $donation->update([
            'receipt_file' => $path,
        ]);

        return new DonationResource(
            $donation->fresh()->load(['member.user', 'project', 'recorder', 'approver', 'rejecter'])
        );
    }

    public function submit(Donation $donation)
    {
        $this->authorize('submit', $donation);

        // See ExpenseController::submit() for why this distinction matters:
        // same status transition, but the approval roles get a
        // differently-worded, differently-typed notification for a fresh
        // submission vs a "Submit Again" after a receipt-issue rejection.
        $isResubmission = $donation->status === 'rejected';

        $donation->update([
            'status' => 'pending',
        ]);

        $donation->load(['member.user', 'project', 'recorder', 'approver', 'rejecter']);

        $actor = $donation->recorder?->name ?? __('notifications.people.committee_member');
        $project = $donation->project?->name ?? __('notifications.people.a_project');

        Notification::notifyRoles(
            AuthorizationHelper::FINANCIAL_OVERSIGHT_ROLES,
            $isResubmission ? 'donation_resubmitted' : 'donation_pending',
            __($isResubmission ? 'notifications.donation.resubmitted_title' : 'notifications.donation.pending_title'),
            __($isResubmission ? 'notifications.donation.resubmitted_message' : 'notifications.donation.submitted_message', [
                'actor' => $actor,
                'id' => $donation->id,
                'project' => $project,
            ]),
            $donation,
        );

        return new DonationResource($donation);
    }

    public function approve(Donation $donation)
    {
        $this->authorize('approve', $donation);

        $donation->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $donation->load(['member.user', 'project', 'recorder', 'approver', 'rejecter']);

        if ($donation->recorded_by) {
            Notification::notifyUsers(
                [$donation->recorded_by],
                'donation_approved',
                __('notifications.donation.approved_title'),
                __('notifications.donation.approved_message', [
                    'id' => $donation->id,
                    'project' => $donation->project?->name ?? __('notifications.people.a_project'),
                ]),
                $donation,
            );
        }

        return new DonationResource($donation);
    }

    public function reject(Request $request, Donation $donation)
    {
        $this->authorize('reject', $donation);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'rejection_type' => 'required|in:receipt,details',
        ]);

        $donation->update([
            'status' => 'rejected',
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'rejection_reason' => $validated['reason'],
            'rejection_type' => $validated['rejection_type'],
        ]);

        $donation->load(['member.user', 'project', 'recorder', 'approver', 'rejecter']);

        if ($donation->recorded_by) {
            Notification::notifyUsers(
                [$donation->recorded_by],
                'donation_rejected',
                __('notifications.donation.rejected_title'),
                __('notifications.donation.rejected_message', [
                    'id' => $donation->id,
                    'project' => $donation->project?->name ?? __('notifications.people.a_project'),
                    'reason' => $validated['reason'],
                ]),
                $donation,
            );
        }

        return new DonationResource($donation);
    }
}
