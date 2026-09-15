<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AuthorizationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Requests\UpdateSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Due;
use App\Models\Notification;
use App\Models\Subscription;
use App\Services\DuesService;
use App\Services\MailerSendService;
use App\Services\PasskeyService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SubscriptionController extends Controller
{
    private const VERIFIER_ROLES = ['president', 'tresorier', 'vice-tresorier'];

    public function index()
    {
        $this->authorize('viewAny', Subscription::class);

        $query = Subscription::with(['member.user', 'verifier', 'due'])->latest();

        // A plain abonne only sees their own subscription history ("Subscriptions: Own subscription").
        if (! AuthorizationHelper::isBureauMember(auth()->user())) {
            $member = auth()->user()->member;
            $query->where('member_id', $member?->id ?? 0);
        }

        return SubscriptionResource::collection($query->get());
    }

    public function store(StoreSubscriptionRequest $request, DuesService $duesService)
    {
        $this->authorize('create', Subscription::class);

        $subscription = DB::transaction(function () use ($request, $duesService) {
            $subscription = Subscription::create(
                $request->validated()
            );

            // `status` isn't in StoreSubscriptionRequest's validated fields (the
            // client can't set it directly — it's DB-defaulted to 'pending'), so
            // the in-memory model from create() doesn't have it yet; without
            // this refresh the response always reported status: null even
            // though the row itself was correctly 'pending'.
            $subscription->refresh();
            $subscription->load('member.user');

            // Auto-link to the payer's due for the year this payment covers,
            // creating it if it doesn't exist yet — unless the caller already
            // specified an explicit due_id (StoreSubscriptionRequest allows
            // both). Every subscription created from here on is linked;
            // subscriptions that predate this feature stay due_id = null.
            if (! $subscription->due_id) {
                $due = $duesService->getOrCreateForMemberYear(
                    $subscription->member,
                    Carbon::parse($subscription->payment_date)->year,
                );
                $subscription->update(['due_id' => $due->id]);
            }

            return $subscription;
        });

        Notification::notifyRoles(
            self::VERIFIER_ROLES,
            'subscription_pending',
            __('notifications.subscription.pending_title'),
            __('notifications.subscription.pending_message', [
                'actor' => $subscription->member?->user?->name ?? __('notifications.people.subscriber'),
            ]),
            $subscription,
        );

        return new SubscriptionResource(
            $subscription->load(['verifier', 'due'])
        );
    }

    public function show(Subscription $subscription)
    {
        $this->authorize('view', $subscription);

        return new SubscriptionResource(
            $subscription->load(['member.user', 'verifier', 'due'])
        );
    }

    public function update(UpdateSubscriptionRequest $request, Subscription $subscription, DuesService $duesService)
    {
        $this->authorize('update', $subscription);

        DB::transaction(function () use ($request, $subscription, $duesService) {
            $subscription->update(
                $request->validated()
            );

            // Only relevant if this payment is both linked to a due and
            // already verified (e.g. its amount was corrected after the
            // fact) — an edit to a still-pending payment doesn't move any
            // due's balance, since only verified payments count toward it.
            if ($subscription->due_id && $subscription->status === 'verified') {
                $due = Due::whereKey($subscription->due_id)->lockForUpdate()->first();

                if ($due) {
                    $duesService->recalculate($due);
                }
            }
        });

        return new SubscriptionResource(
            $subscription->load(['member.user', 'verifier', 'due'])
        );
    }

    public function destroy(Subscription $subscription, DuesService $duesService)
    {
        $this->authorize('delete', $subscription);

        $dueId = $subscription->due_id;

        DB::transaction(function () use ($subscription, $dueId, $duesService) {
            $subscription->delete();

            // A deleted payment that was verified and linked to a due must
            // no longer count toward that due's amount_paid.
            if ($dueId) {
                $due = Due::whereKey($dueId)->lockForUpdate()->first();

                if ($due) {
                    $duesService->recalculate($due);
                }
            }
        });

        return response()->json([
            'message' => __('messages.subscription.deleted'),
        ]);
    }

    public function verify(Subscription $subscription, PasskeyService $passkeys, MailerSendService $mailer, DuesService $duesService)
    {
        $this->authorize('verify', $subscription);

        DB::transaction(function () use ($subscription, $duesService) {
            $subscription->update([
                'status' => 'verified',
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ]);

            if ($subscription->due_id) {
                $due = Due::whereKey($subscription->due_id)->lockForUpdate()->first();

                if ($due) {
                    $duesService->recalculate($due);
                }
            }
        });

        $subscription->load('member.user');

        if ($subscriberUserId = $subscription->member?->user_id) {
            Notification::notifyUsers(
                [$subscriberUserId],
                'subscription_approved',
                __('notifications.subscription.approved_title'),
                __('notifications.subscription.approved_message'),
                $subscription,
            );
        }

        // Member Portal access is issued exactly once — the first time a
        // subscriber is verified — never regenerated on later renewals. An
        // already-verified subscriber (has_portal_access) verifying a second
        // subscription just renews it; no new passkey, no second email.
        $user = $subscription->member?->user;

        if ($user && ! $user->hasPortalAccess()) {
            $passkey = $passkeys->issueFor($user);
            $settings = SettingsService::get();

            $mailer->send(
                $user->email,
                $user->name,
                __('emails.welcome.subject', ['association' => $settings->association_name]),
                view('emails.welcome', [
                    'name' => $user->name,
                    'passkey' => $passkey,
                    'portalUrl' => rtrim(config('app.frontend_url'), '/').'/member',
                    'associationName' => $settings->association_name,
                    'associationLogoUrl' => $settings->association_logo ? asset('storage/'.$settings->association_logo) : null,
                    'associationAddress' => $settings->address,
                    'associationPhone' => $settings->phone,
                    'associationEmail' => $settings->email,
                ])->render(),
            );
        }

        return new SubscriptionResource(
            $subscription->load(['verifier', 'due'])
        );
    }

    public function uploadReceipt(Request $request, Subscription $subscription, DuesService $duesService)
    {
        $this->authorize('uploadReceipt', $subscription);

        $request->validate([
            'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($subscription->receipt_file) {
            Storage::disk('public')->delete($subscription->receipt_file);
        }

        $path = $request->file('receipt')->store('receipts', 'public');

        DB::transaction(function () use ($subscription, $path, $duesService) {
            $wasVerified = $subscription->status === 'verified';

            $subscription->update([
                'receipt_file' => $path,
                'status' => 'pending',
            ]);

            // Replacing the receipt on a previously-verified payment reverts
            // it to pending — that payment no longer counts toward its due
            // until re-verified, so the due must be recalculated too.
            if ($wasVerified && $subscription->due_id) {
                $due = Due::whereKey($subscription->due_id)->lockForUpdate()->first();

                if ($due) {
                    $duesService->recalculate($due);
                }
            }
        });

        $subscription->load('member.user');

        Notification::notifyRoles(
            self::VERIFIER_ROLES,
            'subscription_pending',
            __('notifications.subscription.receipt_uploaded_title'),
            __('notifications.subscription.receipt_uploaded_message', [
                'actor' => $subscription->member?->user?->name ?? __('notifications.people.subscriber'),
            ]),
            $subscription,
        );

        return new SubscriptionResource(
            $subscription->fresh()->load(['member.user', 'verifier', 'due'])
        );
    }
}
