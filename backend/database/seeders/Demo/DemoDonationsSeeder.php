<?php

namespace Database\Seeders\Demo;

use App\Models\Donation;
use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\Support\MoroccanData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

// Hundreds of donations across every source the spec asks for, spread over
// the projects that are actually allowed to receive them (active/completed/
// cancelled — see ProjectLifecycle::requiresActiveResponse; draft/
// committee_ready/funding_ready projects are a deliberate "zero donations"
// edge case, left untouched here).
class DemoDonationsSeeder extends Seeder
{
    private const SOURCE_WEIGHTS = [
        'subscriber' => 30,
        'association_member' => 10,
        'state' => 10,
        'other_association' => 15,
        'benefactor' => 20,
        'anonymous' => 15,
    ];

    public function run(): void
    {
        $financialApprovers = User::whereIn('email', [
            'president@association.com',
            'tresorier@association.com',
            'vice.tresorier@association.com',
        ])->get();

        $subscriberMembers = Member::whereHas('user', fn ($q) => $q->role('abonne')
            ->whereDoesntHave('roles', fn ($r) => $r->where('name', '!=', 'abonne')))
            ->get();

        $bureauMembers = Member::whereHas('user', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', '!=', 'abonne')))
            ->get();

        $projects = Project::with('members.user')
            ->whereIn('status', ['active', 'completed', 'cancelled'])
            ->get();

        Auth::login($financialApprovers->first());

        $total = 0;

        foreach ($projects as $project) {
            // Scaled to the project's own budget rather than a flat range —
            // otherwise small projects end up with donation totals many
            // times their budget, which reads as an implausible balance.
            $count = min(25, max(6, (int) round(((float) $project->budget) / 2500)));
            $recorder = $this->recorderFor($project, $financialApprovers->first());

            for ($i = 0; $i < $count; $i++) {
                $this->createDonation($project, $recorder, $subscriberMembers, $bureauMembers, $financialApprovers);
                $total++;
            }
        }

        // Explicit "very large" and "very small" donation edge cases, on the
        // first eligible project.
        if ($first = $projects->first()) {
            $recorder = $this->recorderFor($first, $financialApprovers->first());

            Donation::create([
                'member_id' => null,
                'project_id' => $first->id,
                'donor_name' => MoroccanData::DONOR_BENEFACTORS[0],
                'amount' => 50000,
                'payment_method' => 'bank_transfer',
                'receipt_number' => 'DON-'.random_int(100000, 999999),
                'receipt_file' => MoroccanData::storeFakeImage('donation-receipts'),
                'donation_date' => $this->dateWithin($first),
                'notes' => 'Don exceptionnel.',
                'recorded_by' => $recorder,
                'status' => 'approved',
                'approved_by' => $financialApprovers->first()->id,
                'approved_at' => now()->subDays(random_int(1, 20)),
            ]);

            Donation::create([
                'member_id' => null,
                'project_id' => $first->id,
                'donor_name' => 'Anonyme',
                'amount' => 10,
                'payment_method' => 'cash',
                'receipt_number' => null,
                'receipt_file' => null,
                'donation_date' => $this->dateWithin($first),
                'notes' => null,
                'recorded_by' => $recorder,
                'status' => 'approved',
                'approved_by' => $financialApprovers->first()->id,
                'approved_at' => now()->subDays(random_int(1, 20)),
            ]);

            $total += 2;
        }

        Auth::logout();

        $this->command?->info("{$total} donations seeded across ".$projects->count().' projects.');
    }

    private function recorderFor(Project $project, int|User $fallback): int
    {
        $financial = $project->members->first(fn ($member) => in_array(
            $member->pivot->committee_role ?? null,
            ['leader', 'treasurer'],
            true
        ));

        if ($financial && $financial->user_id) {
            return $financial->user_id;
        }

        return is_int($fallback) ? $fallback : $fallback->id;
    }

    private function createDonation(Project $project, int $recorder, Collection $subscriberMembers, Collection $bureauMembers, Collection $financialApprovers): void
    {
        $source = $this->weightedSource();
        $status = $this->weightedStatus();
        $donationDate = $this->dateWithin($project);

        [$memberId, $donorName, $amount] = match ($source) {
            'subscriber' => [
                $subscriberMembers->isNotEmpty() ? $subscriberMembers->random()->id : null,
                null,
                random_int(50, 500),
            ],
            'association_member' => [
                $bureauMembers->isNotEmpty() ? $bureauMembers->random()->id : null,
                null,
                random_int(100, 1000),
            ],
            'state' => [null, MoroccanData::DONOR_STATE[array_rand(MoroccanData::DONOR_STATE)], random_int(1000, 8000)],
            'other_association' => [null, MoroccanData::DONOR_OTHER_ASSOCIATIONS[array_rand(MoroccanData::DONOR_OTHER_ASSOCIATIONS)], random_int(500, 4000)],
            'benefactor' => [null, MoroccanData::DONOR_BENEFACTORS[array_rand(MoroccanData::DONOR_BENEFACTORS)], random_int(500, 5000)],
            default => [null, 'Anonyme', random_int(20, 300)],
        };

        $hasReceipt = in_array($status, ['pending', 'approved'], true);

        $donation = Donation::create([
            'member_id' => $memberId,
            'project_id' => $project->id,
            'donor_name' => $donorName,
            'amount' => $amount,
            'payment_method' => MoroccanData::PAYMENT_METHODS[array_rand(MoroccanData::PAYMENT_METHODS)],
            'receipt_number' => $hasReceipt ? 'DON-'.random_int(100000, 999999) : null,
            'receipt_file' => $hasReceipt ? MoroccanData::storeFakeImage('donation-receipts') : null,
            'donation_date' => $donationDate,
            'notes' => null,
            'recorded_by' => $recorder,
            'status' => $status,
        ]);

        if ($status === 'approved') {
            $approver = $financialApprovers->random();
            $donation->update([
                'approved_by' => $approver->id,
                'approved_at' => (clone $donationDate)->addDays(random_int(0, 3)),
            ]);
        } elseif ($status === 'rejected') {
            $rejecter = $financialApprovers->random();
            $donation->update([
                'rejected_by' => $rejecter->id,
                'rejected_at' => (clone $donationDate)->addDays(random_int(0, 3)),
                'rejection_reason' => 'Justificatif de paiement manquant ou illisible.',
                'rejection_type' => random_int(0, 1) ? 'receipt' : 'details',
            ]);
        }
    }

    private function weightedSource(): string
    {
        return $this->weightedPick(self::SOURCE_WEIGHTS);
    }

    private function weightedStatus(): string
    {
        return $this->weightedPick([
            'draft' => 10,
            'pending' => 15,
            'approved' => 65,
            'rejected' => 10,
        ]);
    }

    private function weightedPick(array $weights): string
    {
        $roll = random_int(1, array_sum($weights));
        $cumulative = 0;

        foreach ($weights as $key => $weight) {
            $cumulative += $weight;

            if ($roll <= $cumulative) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    private function dateWithin(Project $project): Carbon
    {
        $start = Carbon::parse($project->start_date);
        $end = Carbon::parse($project->end_date ?? now());

        if ($end->greaterThan(now())) {
            $end = now();
        }

        if ($end->lessThanOrEqualTo($start)) {
            return $start;
        }

        return Carbon::createFromTimestamp(random_int($start->timestamp, $end->timestamp));
    }
}
