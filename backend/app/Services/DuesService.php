<?php

namespace App\Services;

use App\Models\Due;
use App\Models\Member;
use App\Models\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for the Annual Dues ledger — due generation and the
 * amount_paid/balance/status recalculation state machine. Mirrors the
 * "service owns the math" pattern already used by FundsHelper/SettingsService
 * elsewhere in this codebase: controllers/commands call in here, nothing
 * else re-derives dues totals independently.
 */
class DuesService
{
    private const NOTIFY_ROLES = ['president', 'tresorier', 'vice-tresorier'];

    /**
     * Expected/collected/outstanding totals + collection rate, optionally
     * scoped to one year. The single source of these figures — both
     * DashboardController and ReportController::dues() call this instead of
     * re-summing the dues table themselves, so the math can't drift out of
     * sync between the dashboard widget and the report.
     */
    public function summary(?int $year = null): array
    {
        $query = Due::query();

        if ($year !== null) {
            $query->where('year', $year);
        }

        $dues = $query->get();

        $expected = (float) $dues->sum('amount_due');
        $collected = (float) $dues->sum('amount_paid');
        $outstanding = (float) $dues->where('status', '!=', 'waived')->sum('balance');

        return [
            'expected' => $expected,
            'collected' => $collected,
            'outstanding' => $outstanding,
            'collection_rate' => $expected > 0 ? round(($collected / $expected) * 100, 1) : 0.0,
        ];
    }

    /**
     * Find or create this member's due for a given year. Safe to call
     * repeatedly — never creates a duplicate thanks to the (member_id, year)
     * unique index; a unique-constraint race (two requests creating the same
     * member+year due at once) is caught and resolved by re-fetching the row
     * the other request just committed.
     */
    public function getOrCreateForMemberYear(Member $member, int $year): Due
    {
        $due = Due::where('member_id', $member->id)->where('year', $year)->first();

        if ($due) {
            return $due;
        }

        try {
            return DB::transaction(function () use ($member, $year) {
                $amountDue = (float) SettingsService::get()->annual_subscription_amount;

                $due = Due::create([
                    'member_id' => $member->id,
                    'year' => $year,
                    'amount_due' => $amountDue,
                    'amount_paid' => 0,
                    'balance' => $amountDue,
                    'status' => 'pending',
                    'due_date' => "{$year}-12-31",
                ]);

                if ($userId = $member->user_id) {
                    Notification::notifyUsers(
                        [$userId],
                        'due_created',
                        __('notifications.due.created_title'),
                        __('notifications.due.created_message', [
                            'year' => $year,
                            'amount' => number_format($amountDue, 2),
                        ]),
                        $due,
                    );
                }

                return $due;
            });
        } catch (QueryException $exception) {
            $due = Due::where('member_id', $member->id)->where('year', $year)->first();

            if ($due) {
                return $due;
            }

            throw $exception;
        }
    }

    /**
     * Generate one due per member for the given year. Idempotent — a member
     * who already has a due for that year is left untouched (counted as
     * "skipped"), so this is safe to run multiple times, e.g. re-running a
     * missed scheduled job or backfilling a past year.
     */
    public function generateForYear(int $year): array
    {
        $created = 0;
        $skipped = 0;

        Member::query()->chunkById(100, function ($members) use ($year, &$created, &$skipped) {
            foreach ($members as $member) {
                $due = $this->getOrCreateForMemberYear($member, $year);

                $due->wasRecentlyCreated ? $created++ : $skipped++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Recompute amount_paid/balance/status from this due's linked verified
     * payments. The single place that implements the Annual Dues state
     * machine — every payment-status change that could affect a due
     * (verify, delete, receipt re-upload reverting to pending, amount edit)
     * routes through here instead of re-deriving status inline.
     *
     * balance == amount_due -> pending
     * 0 < balance < amount_due -> partial
     * balance == 0 -> paid
     * due date passed and balance > 0 -> overdue
     * waived_reason set -> waived (sticky; payment activity no longer moves it)
     */
    public function recalculate(Due $due): Due
    {
        if ($due->waived_reason !== null) {
            if ($due->status !== 'waived') {
                $due->status = 'waived';
                $due->save();
            }

            return $due;
        }

        $wasPaid = $due->status === 'paid';

        $amountPaid = (float) $due->payments()->where('status', 'verified')->sum('amount');
        $balance = max(0, (float) $due->amount_due - $amountPaid);

        $due->amount_paid = $amountPaid;
        $due->balance = $balance;

        if ($balance <= 0) {
            $due->status = 'paid';
            $due->paid_at ??= now();
        } elseif ($due->due_date && now()->toDateString() > $due->due_date->toDateString()) {
            $due->status = 'overdue';
        } elseif ($amountPaid > 0) {
            $due->status = 'partial';
        } else {
            $due->status = 'pending';
        }

        $due->save();

        if (! $wasPaid && $due->status === 'paid') {
            $this->notifyPaid($due);
        }

        return $due;
    }

    /**
     * Mark every past-due, still-outstanding due as overdue and notify the
     * member + treasury roles. Called by the daily scheduled command.
     * Idempotent the same way ExpireSubscriptions is: the query excludes
     * dues already 'overdue' (or 'paid'/'waived'), so a due already
     * processed is never matched — and therefore never re-notified — on a
     * later run.
     */
    public function markOverdue(): int
    {
        $dues = Due::whereDate('due_date', '<', now()->toDateString())
            ->where('balance', '>', 0)
            ->whereNotIn('status', ['paid', 'waived', 'overdue'])
            ->with('member.user')
            ->get();

        foreach ($dues as $due) {
            $due->status = 'overdue';
            $due->save();

            if ($userId = $due->member?->user_id) {
                Notification::notifyUsers(
                    [$userId],
                    'due_overdue',
                    __('notifications.due.overdue_member_title'),
                    __('notifications.due.overdue_member_message', [
                        'year' => $due->year,
                        'balance' => number_format($due->balance, 2),
                    ]),
                    $due,
                );
            }

            Notification::notifyRoles(
                self::NOTIFY_ROLES,
                'due_overdue',
                __('notifications.due.overdue_roles_title'),
                __('notifications.due.overdue_roles_message', [
                    'actor' => $due->member?->user?->name ?? __('notifications.people.member'),
                    'year' => $due->year,
                ]),
                $due,
            );
        }

        return $dues->count();
    }

    public function waive(Due $due, string $reason): Due
    {
        return DB::transaction(function () use ($due, $reason) {
            $due->waived_reason = $reason;
            $due->status = 'waived';
            $due->save();

            return $due;
        });
    }

    private function notifyPaid(Due $due): void
    {
        $due->loadMissing('member.user');

        if ($userId = $due->member?->user_id) {
            Notification::notifyUsers(
                [$userId],
                'due_paid',
                __('notifications.due.paid_title'),
                __('notifications.due.paid_member_message', ['year' => $due->year]),
                $due,
            );
        }

        Notification::notifyRoles(
            self::NOTIFY_ROLES,
            'due_paid',
            __('notifications.due.paid_title'),
            __('notifications.due.paid_roles_message', [
                'actor' => $due->member?->user?->name ?? __('notifications.people.member'),
                'year' => $due->year,
            ]),
            $due,
        );
    }
}
