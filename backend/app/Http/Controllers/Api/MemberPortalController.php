<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DonationResource;
use App\Http\Resources\DueResource;
use App\Http\Resources\SubscriptionResource;
use Illuminate\Support\Carbon;

/**
 * Read-only "my own data" endpoint for the Member Portal. Always scoped to
 * auth()->user() — there is no id parameter anywhere in this controller, so
 * there is no cross-member access to guard against by construction. Reuses
 * SubscriptionResource/DonationResource as-is rather than re-deriving their
 * shape.
 */
class MemberPortalController extends Controller
{
    public function dashboard()
    {
        $member = auth()->user()->member;

        if (! $member) {
            return response()->json([
                'message' => __('messages.member_portal.no_profile'),
            ], 404);
        }

        $subscriptions = $member->subscriptions()->with('verifier')->orderByDesc('expires_at')->get();
        $donations = $member->donations()->with(['project', 'recorder'])->orderByDesc('donation_date')->get();
        $dues = $member->dues()->orderByDesc('year')->get();

        $current = $subscriptions->first();
        $hasCurrentSubscription = $current
            && $current->status === 'verified'
            && $current->expires_at
            && Carbon::parse($current->expires_at)->isFuture();

        return response()->json([
            'profile' => [
                'name' => $member->user->name,
                'email' => $member->user->email,
                'phone' => $member->phone,
                'address' => $member->address,
            ],
            // Reaching this endpoint at all requires a passkey, which is only
            // ever issued once a subscription has been verified — so a
            // logged-in portal user is, by definition, a verified member.
            'membership_status' => 'verified',
            'subscription_status' => $current?->status,
            'current_subscription' => $current ? new SubscriptionResource($current) : null,
            'expiration_date' => $current?->expires_at,
            // Kept exactly as before for backward compatibility — a simple
            // derivation from the current subscription, not the real Annual
            // Dues ledger: 0 while the most recent subscription is verified
            // and not yet expired; otherwise that subscription's amount,
            // i.e. what's due to renew. See `dues_history` below for the
            // actual per-year ledger (amount due/paid/balance/status).
            'outstanding_balance' => $hasCurrentSubscription ? 0 : (float) ($current?->amount ?? 0),
            'subscription_history' => SubscriptionResource::collection($subscriptions),
            'donation_history' => DonationResource::collection($donations),
            'dues_history' => DueResource::collection($dues),
        ]);
    }
}
