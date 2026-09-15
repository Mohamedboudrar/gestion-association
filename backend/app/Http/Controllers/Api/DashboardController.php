<?php

namespace App\Http\Controllers\Api;

use App\Helpers\FundsHelper;
use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\Due;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Services\DuesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    // Every field this endpoint returns is association-wide, never scoped
    // or personalized to whichever authenticated user is asking — so a
    // short, unkeyed cache can't leak one user's data to another or serve
    // stale permissions (the permission check, if any, still runs fresh on
    // every request; only this data is cached). 45s keeps the dashboard
    // feeling live (matches the frontend's own polling cadence elsewhere)
    // while absorbing repeat loads from the sidebar badge / Action Center
    // both re-rendering around the same time.
    private const CACHE_SECONDS = 45;

    public function index(DuesService $duesService)
    {
        return response()->json(
            Cache::remember('dashboard:summary', self::CACHE_SECONDS, fn () => $this->buildSummary($duesService))
        );
    }

    private function buildSummary(DuesService $duesService): array
    {
        $membersTotal = Member::count();
        $activeSubscribersTotal = Subscription::where('status', 'verified')
            ->whereDate('expires_at', '>=', now()->toDateString())
            ->count();
        $boardMembersTotal = User::whereHas('roles', function ($query) {
            $query->where('name', '!=', 'abonne');
        })->count();
        $activeProjectsTotal = Project::where('status', 'active')->count();
        $pendingSubscriptionsTotal = Subscription::where('status', 'pending')->count();
        $verifiedRevenueTotal = Subscription::where('status', 'verified')->sum('amount');
        $availableFunds = FundsHelper::availableFunds();
        $donationsRevenueTotal = Donation::financiallyCounted()->sum('amount');
        $totalRevenue = $verifiedRevenueTotal + $donationsRevenueTotal;
        $collectedThisYear = (float) Subscription::where('status', 'verified')
            ->whereYear('payment_date', now()->year)
            ->sum('amount');
        $donationsThisYear = (float) Donation::financiallyCounted()
            ->whereYear('donation_date', now()->year)
            ->sum('amount');
        $spentThisYear = (float) Expense::whereYear('expense_date', now()->year)
            ->financiallyCounted()
            ->sum('amount');

        $duesSummary = $duesService->summary(now()->year);
        $duesOverdueMembers = Due::where('status', 'overdue')->distinct('member_id')->count('member_id');

        return [
            'available_funds' => $availableFunds,
            'overview' => [
                'active_subscribers' => $activeSubscribersTotal,
                'board_members' => $boardMembersTotal,
                'active_projects' => $activeProjectsTotal,
                'collected_this_year' => $collectedThisYear + $donationsThisYear,
                'spent_this_year' => $spentThisYear,
                'remaining_funds' => ($collectedThisYear + $donationsThisYear) - $spentThisYear,
                'unsupported_metrics' => [],
            ],
            'stats' => [
                'members' => $this->buildStat(
                    $membersTotal,
                    Member::query(),
                    'created_at'
                ),
                'projects' => $this->buildStat(
                    $activeProjectsTotal,
                    Project::where('status', 'active'),
                    'created_at'
                ),
                'subscriptions' => $this->buildStat(
                    $pendingSubscriptionsTotal,
                    Subscription::where('status', 'pending'),
                    'created_at'
                ),
                'revenue' => $this->buildRevenueStat($totalRevenue),
            ],
            'subscriptions' => [
                'verified' => Subscription::where('status', 'verified')->count(),
                'pending' => Subscription::where('status', 'pending')->count(),
                'expired' => Subscription::where('status', 'expired')->count(),
                'revenue' => $verifiedRevenueTotal,
            ],
            // Annual Dues — additive block, current-year scoped. Doesn't
            // change or replace any subscription figure above; a dues
            // payment is still counted in verified subscription revenue via
            // its linked Subscription row, this is a separate, dues-ledger
            // view of the same underlying payments.
            'dues' => [
                'year' => now()->year,
                'expected' => $duesSummary['expected'],
                'collected' => $duesSummary['collected'],
                'outstanding' => $duesSummary['outstanding'],
                'overdue_members' => $duesOverdueMembers,
                'collection_rate' => $duesSummary['collection_rate'],
            ],
            'donations' => [
                'total' => Donation::financiallyCounted()->count(),
                'revenue' => $donationsRevenueTotal,
            ],
            'projects' => [
                'total' => Project::count(),
                // Draft/committee_ready/funding_ready grouped together — none of them
                // can hold donations/expenses yet, see ProjectLifecycle.
                'in_setup' => Project::whereIn('status', ['draft', 'committee_ready', 'funding_ready'])->count(),
                'active' => $activeProjectsTotal,
                'completed' => Project::where('status', 'completed')->count(),
                'cancelled' => Project::where('status', 'cancelled')->count(),
            ],
            'members' => [
                'total' => $membersTotal,
            ],
            'charts' => [
                'donations_by_month' => $this->buildDonationsByMonthChart(),
                'revenue_vs_project_budgets' => $this->buildRevenueVsProjectBudgetsChart(),
            ],
            'recent' => [
                'member_registrations' => $this->recentMemberRegistrations(),
                'subscription_payments' => $this->recentSubscriptionPayments(),
                'donations' => $this->recentDonations(),
                'financial_operations' => [],
                'projects' => $this->recentProjects(),
            ],
            'notifications' => [
                [
                    'type' => 'pending_subscriptions',
                    'title' => 'Pending subscriptions require review',
                    'count' => $pendingSubscriptionsTotal,
                ],
                [
                    'type' => 'unfinished_projects',
                    'title' => 'Projects still in progress',
                    'count' => Project::whereNotIn('status', ['completed', 'cancelled'])->count(),
                ],
                [
                    'type' => 'missing_reports',
                    'title' => 'Missing reports',
                    'count' => null,
                    'requires_endpoint' => true,
                ],
            ],
        ];
    }

    private function buildStat(int|float $value, $query, string $dateColumn): array
    {
        $currentMonthStart = now()->startOfMonth();
        $previousMonthStart = now()->subMonth()->startOfMonth();
        $previousMonthEnd = $previousMonthStart->copy()->endOfMonth();

        $currentValue = (clone $query)
            ->whereBetween($dateColumn, [$currentMonthStart, now()])
            ->count();

        $previousValue = (clone $query)
            ->whereBetween($dateColumn, [$previousMonthStart, $previousMonthEnd])
            ->count();

        return [
            'value' => $value,
            ...$this->formatChange($currentValue, $previousValue),
        ];
    }

    private function buildRevenueStat(float|int|string $value): array
    {
        $currentMonthStart = now()->startOfMonth()->toDateString();
        $previousMonthStart = now()->subMonth()->startOfMonth()->toDateString();
        $previousMonthEnd = now()->subMonth()->endOfMonth()->toDateString();

        $currentSubscriptionValue = (float) Subscription::where('status', 'verified')
            ->whereBetween('payment_date', [$currentMonthStart, now()->toDateString()])
            ->sum('amount');

        $previousSubscriptionValue = (float) Subscription::where('status', 'verified')
            ->whereBetween('payment_date', [$previousMonthStart, $previousMonthEnd])
            ->sum('amount');

        $currentDonationValue = (float) Donation::financiallyCounted()->whereBetween('donation_date', [
            $currentMonthStart,
            now()->toDateString(),
        ])->sum('amount');

        $previousDonationValue = (float) Donation::financiallyCounted()->whereBetween('donation_date', [
            $previousMonthStart,
            $previousMonthEnd,
        ])->sum('amount');

        return [
            'value' => (float) $value,
            ...$this->formatChange(
                $currentSubscriptionValue + $currentDonationValue,
                $previousSubscriptionValue + $previousDonationValue
            ),
        ];
    }

    private function formatChange(float|int $currentValue, float|int $previousValue): array
    {
        if ($previousValue == 0) {
            if ($currentValue == 0) {
                return [
                    'change' => '0%',
                    'trend' => 'flat',
                ];
            }

            return [
                'change' => '+100%',
                'trend' => 'up',
            ];
        }

        $difference = (($currentValue - $previousValue) / $previousValue) * 100;
        $rounded = (int) round(abs($difference));

        if ($rounded === 0) {
            return [
                'change' => '0%',
                'trend' => 'flat',
            ];
        }

        return [
            'change' => ($difference > 0 ? '+' : '-').$rounded.'%',
            'trend' => $difference > 0 ? 'up' : 'down',
        ];
    }

    private function buildDonationsByMonthChart(int $months = 6): array
    {
        $monthStarts = $this->recentMonthStarts($months);
        $fromDate = $monthStarts->first()->copy()->startOfMonth()->toDateString();

        $donations = Donation::financiallyCounted()->whereDate('donation_date', '>=', $fromDate)
            ->get(['amount', 'donation_date']);

        return $monthStarts->map(function (Carbon $monthStart) use ($donations) {
            $amount = $donations
                ->filter(fn (Donation $donation) => Carbon::parse($donation->donation_date)->isSameMonth($monthStart))
                ->sum('amount');

            return [
                'month' => $monthStart->format('M'),
                'donations' => (float) $amount,
            ];
        })->values()->all();
    }

    private function buildRevenueVsProjectBudgetsChart(int $months = 7): array
    {
        $monthStarts = $this->recentMonthStarts($months);
        $fromDate = $monthStarts->first()->copy()->startOfMonth()->toDateString();

        $subscriptions = Subscription::where('status', 'verified')
            ->whereDate('payment_date', '>=', $fromDate)
            ->get(['amount', 'payment_date']);
        $donations = Donation::financiallyCounted()->whereDate('donation_date', '>=', $fromDate)
            ->get(['amount', 'donation_date']);

        $projects = Project::whereDate('start_date', '>=', $fromDate)
            ->get(['budget', 'start_date']);

        return $monthStarts->map(function (Carbon $monthStart) use ($subscriptions, $donations, $projects) {
            $subscriptionRevenue = $subscriptions
                ->filter(fn (Subscription $subscription) => Carbon::parse($subscription->payment_date)->isSameMonth($monthStart))
                ->sum('amount');
            $donationRevenue = $donations
                ->filter(fn (Donation $donation) => Carbon::parse($donation->donation_date)->isSameMonth($monthStart))
                ->sum('amount');

            $projectBudgets = $projects
                ->filter(fn (Project $project) => Carbon::parse($project->start_date)->isSameMonth($monthStart))
                ->sum('budget');

            return [
                'month' => $monthStart->format('M'),
                'revenue' => (float) ($subscriptionRevenue + $donationRevenue),
                'project_budgets' => (float) $projectBudgets,
            ];
        })->values()->all();
    }

    private function recentMonthStarts(int $months): Collection
    {
        return collect(range($months - 1, 0))
            ->map(fn (int $offset) => now()->copy()->startOfMonth()->subMonths($offset));
    }

    private function recentMemberRegistrations(): array
    {
        return Member::with('user')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (Member $member) => [
                'id' => $member->id,
                'name' => $member->user?->name ?? 'Unknown member',
                'email' => $member->user?->email,
                'phone' => $member->phone,
                'created_at' => $member->created_at,
            ])
            ->values()
            ->all();
    }

    private function recentDonations(): array
    {
        return Donation::financiallyCounted()
            ->with(['member.user', 'project'])
            ->latest('donation_date')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (Donation $donation) {
                return [
                    'id' => $donation->id,
                    'donor_name' => $donation->member?->user?->name ?? $donation->donor_name ?? 'Unknown donor',
                    'amount' => (float) $donation->amount,
                    'project' => $donation->project?->name,
                    'donation_date' => $donation->donation_date,
                    'receipt_url' => $donation->receipt_file ? asset('storage/'.$donation->receipt_file) : null,
                ];
            })
            ->all();
    }

    private function recentSubscriptionPayments(): array
    {
        return Subscription::with('member.user')
            ->latest('payment_date')
            ->take(5)
            ->get()
            ->map(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'subscriber' => $subscription->member?->user?->name ?? 'Unknown subscriber',
                'amount' => (float) $subscription->amount,
                'status' => $subscription->status,
                'payment_date' => $subscription->payment_date,
                'receipt_number' => $subscription->receipt_number,
            ])
            ->values()
            ->all();
    }

    private function recentProjects(): array
    {
        return Project::with('manager')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'budget' => (float) $project->budget,
                'manager' => $project->manager?->name,
                'created_at' => $project->created_at,
            ])
            ->values()
            ->all();
    }
}
