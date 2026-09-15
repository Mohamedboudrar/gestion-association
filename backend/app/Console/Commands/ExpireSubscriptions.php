<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Subscription;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    private const NOTIFY_ROLES = ['president', 'tresorier', 'vice-tresorier'];

    // Days-before-expiry thresholds that get a reminder, and the
    // notification type each one fires — see NOTIFICATION TYPES: Membership.
    private const REMINDER_THRESHOLDS = [
        30 => 'subscription_expiring_30',
        7 => 'subscription_expiring_7',
        0 => 'subscription_expiring_today',
    ];

    protected $signature = 'app:expire-subscriptions';

    protected $description = 'Mark verified subscriptions as expired once their expiry date has passed, and remind subscribers whose subscription is about to expire';

    public function handle(): int
    {
        $this->expireDueSubscriptions();
        $this->sendExpiryReminders();

        return self::SUCCESS;
    }

    private function expireDueSubscriptions(): void
    {
        $subscriptions = Subscription::where('status', 'verified')
            ->whereDate('expires_at', '<', now()->toDateString())
            ->with('member.user')
            ->get();

        foreach ($subscriptions as $subscription) {
            $subscription->update(['status' => 'expired']);

            $memberName = $subscription->member?->user?->name ?? __('notifications.people.member');
            $subscriberUserId = $subscription->member?->user_id;

            if ($subscriberUserId) {
                Notification::notifyUsers(
                    [$subscriberUserId],
                    'subscription_expired',
                    __('notifications.subscription.expired_member_title'),
                    __('notifications.subscription.expired_member_message'),
                    $subscription,
                );
            }

            Notification::notifyRoles(
                self::NOTIFY_ROLES,
                'subscription_expired',
                __('notifications.subscription.expired_roles_title'),
                __('notifications.subscription.expired_roles_message', ['name' => $memberName]),
                $subscription,
            );

            $this->info("Expired subscription #{$subscription->id} ({$memberName}).");
        }

        $this->info(count($subscriptions).' subscription(s) expired.');
    }

    // Reminders for subscriptions that haven't expired yet — 30/7/0 days
    // out. notifyUsersOnce() means running this command more than once on
    // the same day (or on a day after the exact threshold was already
    // crossed, e.g. the job didn't run) never sends a duplicate for the
    // same subscription + threshold.
    private function sendExpiryReminders(): void
    {
        $today = now()->toDateString();
        $sent = 0;

        foreach (self::REMINDER_THRESHOLDS as $daysOut => $type) {
            $targetDate = now()->addDays($daysOut)->toDateString();

            $subscriptions = Subscription::where('status', 'verified')
                ->whereDate('expires_at', $targetDate)
                ->with('member.user')
                ->get();

            foreach ($subscriptions as $subscription) {
                $subscriberUserId = $subscription->member?->user_id;

                if (! $subscriberUserId) {
                    continue;
                }

                $title = $daysOut === 0
                    ? __('notifications.subscription.expiring_today_title')
                    : __('notifications.subscription.expiring_days_title', ['days' => $daysOut]);

                Notification::notifyUsersOnce(
                    [$subscriberUserId],
                    $type,
                    $title,
                    $daysOut === 0
                        ? __('notifications.subscription.expiring_today_message')
                        // expires_at is an uncast `date` column (plain
                        // string, e.g. "2026-08-29") — no Carbon method
                        // needed, it's already the exact display format.
                        : __('notifications.subscription.expiring_days_message', [
                            'days' => $daysOut,
                            'date' => $subscription->expires_at,
                        ]),
                    $subscription,
                );

                $sent++;
            }
        }

        $this->info("{$sent} expiry reminder(s) sent (as of {$today}).");
    }
}
