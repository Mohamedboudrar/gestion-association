<?php

namespace Database\Seeders\Demo;

use App\Helpers\AuthorizationHelper;
use App\Models\Due;
use App\Models\Member;
use App\Models\Subscription;
use App\Models\User;
use App\Services\DuesService;
use Database\Seeders\Support\MoroccanData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

// Annual dues + subscription payments for the last 3 years, per member.
// Deliberately routes every dues-affecting change through DuesService (the
// same service SubscriptionController/GenerateAnnualDues/MarkOverdueDues
// use) instead of computing amount_paid/balance/status here — see
// DuesService::recalculate for the state machine this seeder relies on.
class DemoDuesSubscriptionsSeeder extends Seeder
{
    // Indices (subscriber1..subscriber80) forced into deterministic edge
    // cases the spec explicitly asks for, rather than leaving it to chance.
    private const ALWAYS_PAID_INDICES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

    private const NEVER_PAID_INDICES = [71, 72, 73, 74, 75, 76, 77, 78, 79, 80];

    private int $receiptSequence = 1;

    private array $verifiers = [];

    public function run(DuesService $duesService): void
    {
        $this->verifiers = User::whereIn('email', [
            'president@association.com',
            'tresorier@association.com',
            'vice.tresorier@association.com',
        ])->get()->all();

        $currentYear = (int) now()->year;
        $years = [$currentYear - 2, $currentYear - 1, $currentYear];

        $members = Member::with('user')->get();

        Auth::login($this->verifiers[0]);

        foreach ($members as $member) {
            $subscriberIndex = $this->subscriberIndex($member->user);

            foreach ($years as $year) {
                $due = $duesService->getOrCreateForMemberYear($member, $year);
                $scenario = $this->scenarioFor($subscriberIndex);

                $this->applyScenario($member, $due, $year, $scenario);

                $duesService->recalculate($due->fresh());
            }
        }

        Auth::logout();

        // A handful of waived dues — exercises DuePolicy/waive() and the
        // "waived" status branch, which the payment-scenario mix above never
        // produces on its own.
        $this->seedWaivedDues($duesService, $members, $currentYear);

        // Reuse the exact same scheduled commands production runs daily —
        // converts stale unpaid/partial past-year dues to 'overdue' and
        // stale verified-but-past-expiry subscriptions to 'expired', with
        // their normal notifications. No duplicated status logic here.
        Artisan::call('app:mark-overdue-dues');
        Artisan::call('app:expire-subscriptions');

        $this->command?->info('Dues + subscriptions seeded for years: '.implode(', ', $years));
    }

    private function subscriberIndex(?User $user): ?int
    {
        if (! $user || ! preg_match('/^subscriber(\d+)@example\.ma$/', $user->email, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function scenarioFor(?int $subscriberIndex): string
    {
        if ($subscriberIndex !== null && in_array($subscriberIndex, self::ALWAYS_PAID_INDICES, true)) {
            return 'full';
        }

        if ($subscriberIndex !== null && in_array($subscriberIndex, self::NEVER_PAID_INDICES, true)) {
            return 'unpaid';
        }

        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 45 => 'full',
            $roll <= 60 => 'partial',
            $roll <= 75 => 'pending',
            $roll <= 85 => 'rejected',
            default => 'unpaid',
        };
    }

    private function applyScenario(Member $member, Due $due, int $year, string $scenario): void
    {
        if ($scenario === 'unpaid') {
            return;
        }

        $amountDue = (float) $due->amount_due;
        $paymentDate = $this->randomDateInYear($year);
        $receiptNumber = sprintf('RC-%d-%04d', $year, $this->receiptSequence++);
        $receiptFile = MoroccanData::storeFakeImage('receipts');

        $base = [
            'member_id' => $member->id,
            'due_id' => $due->id,
            'payment_method' => MoroccanData::PAYMENT_METHODS[array_rand(MoroccanData::PAYMENT_METHODS)],
            'receipt_number' => $receiptNumber,
            'receipt_file' => $receiptFile,
            'payment_date' => $paymentDate,
            'expires_at' => (clone $paymentDate)->addYear(),
        ];

        match ($scenario) {
            'full' => Subscription::create([
                ...$base,
                'amount' => $amountDue,
                'status' => 'verified',
                'verified_by' => $this->randomVerifier()->id,
                'verified_at' => (clone $paymentDate)->addDays(random_int(1, 5)),
                'notes' => null,
            ]),
            'partial' => Subscription::create([
                ...$base,
                'amount' => round($amountDue * (random_int(40, 70) / 100), 2),
                'status' => 'verified',
                'verified_by' => $this->randomVerifier()->id,
                'verified_at' => (clone $paymentDate)->addDays(random_int(1, 5)),
                'notes' => null,
            ]),
            'pending' => Subscription::create([
                ...$base,
                'amount' => $amountDue,
                'status' => 'pending',
                'verified_by' => null,
                'verified_at' => null,
                'notes' => null,
            ]),
            // No endpoint currently transitions a subscription to 'rejected'
            // (see SubscriptionsPage.jsx's own comment on this), but the
            // status/display support already exist — no verifier is
            // attached since nothing in the app records who rejected one.
            'rejected' => Subscription::create([
                ...$base,
                'amount' => $amountDue,
                'status' => 'rejected',
                'verified_by' => null,
                'verified_at' => null,
                'notes' => 'Reçu illisible — merci de le re-soumettre.',
            ]),
            default => null,
        };
    }

    private function seedWaivedDues(DuesService $duesService, Collection $members, int $currentYear): void
    {
        $candidates = $members->filter(fn (Member $member) => AuthorizationHelper::isBureauMember($member->user) === false)
            ->values()
            ->take(3);

        Auth::login($this->verifiers[0]);

        foreach ($candidates as $member) {
            $due = $duesService->getOrCreateForMemberYear($member, $currentYear);
            $duesService->waive($due, 'Difficultés financières — dispense accordée par le bureau.');
        }

        Auth::logout();
    }

    private function randomVerifier(): User
    {
        return $this->verifiers[array_rand($this->verifiers)];
    }

    private function randomDateInYear(int $year): Carbon
    {
        $start = Carbon::create($year, 1, 1);
        $end = $year === (int) now()->year ? now() : Carbon::create($year, 12, 31);

        return Carbon::createFromTimestamp(random_int($start->timestamp, max($start->timestamp, $end->timestamp)));
    }
}
