<?php

namespace Database\Seeders\Demo;

use App\Helpers\AuthorizationHelper;
use App\Models\CommitteeAssignment;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectDeletionRequest;
use App\Models\ProjectFundAllocation;
use App\Models\ProjectPhaseRequest;
use App\Models\ProjectPhaseRequestProof;
use App\Models\User;
use Database\Seeders\Support\MoroccanData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

// 20 projects across every lifecycle status, each with a committee (except
// still-draft ones, which never have one — matches ProjectLifecycle: a
// committee is what advances draft -> committee_ready in the real app).
// Donations/expenses are seeded separately (DemoDonationsSeeder/
// DemoExpensesSeeder) once these projects + committees exist; closing out
// completed/cancelled projects (report + committee dissolution) happens
// afterward in DemoProjectFinalizeSeeder, once those totals exist to report on.
class DemoProjectsSeeder extends Seeder
{
    // name => [status, phase]
    private const STATUS_PLAN = [
        ['status' => 'draft', 'phase' => 'planning'],
        ['status' => 'draft', 'phase' => 'planning'],
        ['status' => 'draft', 'phase' => 'planning'],
        ['status' => 'committee_ready', 'phase' => 'planning'],
        ['status' => 'committee_ready', 'phase' => 'planning'],
        ['status' => 'funding_ready', 'phase' => 'planning'],
        ['status' => 'funding_ready', 'phase' => 'preparation'],
        ['status' => 'active', 'phase' => 'preparation'],
        ['status' => 'active', 'phase' => 'in_progress'],
        ['status' => 'active', 'phase' => 'in_progress'],
        ['status' => 'active', 'phase' => 'in_progress'],
        ['status' => 'active', 'phase' => 'finishing'],
        ['status' => 'active', 'phase' => 'finishing'],
        ['status' => 'completed', 'phase' => 'completed'],
        ['status' => 'completed', 'phase' => 'completed'],
        ['status' => 'completed', 'phase' => 'completed'],
        ['status' => 'completed', 'phase' => 'completed'],
        ['status' => 'completed', 'phase' => 'completed'],
        ['status' => 'cancelled', 'phase' => 'in_progress'],
        ['status' => 'cancelled', 'phase' => 'planning'],
    ];

    public function run(): void
    {
        $president = User::where('email', 'president@association.com')->firstOrFail();

        $eligibleMembers = Member::with('user')->get()->filter(
            fn (Member $member) => $member->user && (
                AuthorizationHelper::isBureauMember($member->user)
                || AuthorizationHelper::hasVerifiedSubscription($member)
            )
        )->values();

        Auth::login($president);

        foreach (self::STATUS_PLAN as $index => $plan) {
            $project = $this->createProject($index, $plan);

            if ($plan['status'] !== 'draft') {
                $this->assignCommittee($project, $eligibleMembers, $president);
                $this->assignFundAllocations($project, $president);
            }
        }

        $this->seedPhaseRequests($president);
        $this->seedDeletionRequests($president);

        Auth::logout();

        $this->command?->info('20 projects seeded across draft/committee_ready/funding_ready/active/completed/cancelled.');
    }

    private function createProject(int $index, array $plan): Project
    {
        $name = MoroccanData::PROJECT_NAMES[$index];
        $city = MoroccanData::randomCity();

        // Edge cases the spec explicitly asks for: a very small and a very
        // large budget, spread among otherwise-varied amounts.
        $budget = match (true) {
            $index === 0 => 1500,       // very small budget (draft)
            $index === 16 => 250000,    // very large budget (completed)
            default => random_int(8, 60) * 1000,
        };

        [$start, $end] = $this->datesFor($plan['status'], $index);

        $project = Project::create([
            'name' => $name,
            'description' => "Projet \"{$name}\" mené par l'association à {$city}, au bénéfice des familles "
                .'et communautés locales les plus vulnérables.',
            'start_date' => $start,
            'end_date' => $end,
            'budget' => $budget,
            'status' => $plan['status'],
            'phase' => $plan['phase'],
            'latitude' => $index % 3 === 0 ? round(random_int(2760, 3590) / 100, 6) : null,
            'longitude' => $index % 3 === 0 ? round(random_int(-1300, -100) / 100, 6) : null,
            'manager_id' => null,
        ]);

        return $project;
    }

    private function datesFor(string $status, int $index): array
    {
        $now = Carbon::now();

        return match ($status) {
            // Edge case: one future-dated draft project.
            'draft' => $index === 2
                ? [$now->copy()->addMonths(2), $now->copy()->addMonths(8)]
                : [$now->copy()->subDays(random_int(1, 20)), $now->copy()->addMonths(random_int(3, 9))],
            'committee_ready', 'funding_ready' => [
                $now->copy()->subDays(random_int(5, 40)), $now->copy()->addMonths(random_int(4, 10)),
            ],
            'active' => [
                $now->copy()->subMonths(random_int(1, 8)), $now->copy()->addMonths(random_int(1, 6)),
            ],
            // Edge case: one "old" completed project (started years ago).
            'completed' => $index === 13
                ? [$now->copy()->subYears(3), $now->copy()->subYears(2)->subMonths(6)]
                : [$now->copy()->subMonths(random_int(6, 18)), $now->copy()->subMonths(random_int(1, 5))],
            'cancelled' => [$now->copy()->subMonths(random_int(2, 10)), $now->copy()->subMonths(random_int(1, 4))],
            default => [$now, $now->copy()->addMonths(6)],
        };
    }

    private function assignCommittee(Project $project, Collection $eligibleMembers, User $president): void
    {
        $size = random_int(3, 7);
        $committee = $eligibleMembers->shuffle()->take($size)->values();

        $roles = ['leader', 'treasurer', 'secretary'];
        $now = $project->start_date ?? now();
        $leaderUserId = null;

        foreach ($committee as $seat => $member) {
            $committeeRole = $roles[$seat] ?? 'member';

            if ($committeeRole === 'leader') {
                $leaderUserId = $member->user_id;
            }

            $project->members()->attach($member->id, [
                'role' => null,
                'committee_role' => $committeeRole,
                'responsibility' => in_array($committeeRole, ['leader', 'treasurer'], true)
                    ? ucfirst($committeeRole).' du comité de '.$project->name
                    : null,
                'assigned_by' => $president->id,
                'assigned_at' => $now,
            ]);

            CommitteeAssignment::create([
                'project_id' => $project->id,
                'member_id' => $member->id,
                'role' => null,
                'committee_role' => $committeeRole,
                'responsibility' => null,
                'assigned_by' => $president->id,
                'assigned_at' => $now,
                'action' => 'assigned',
            ]);

            if ($member->user_id) {
                Notification::notifyUsers(
                    [$member->user_id],
                    'committee_assigned',
                    'Assigned to project',
                    "You were assigned to the {$project->name} committee.",
                    $project,
                );
            }
        }

        if ($leaderUserId) {
            $project->update(['manager_id' => $leaderUserId]);
        }
    }

    private function assignFundAllocations(Project $project, User $president): void
    {
        $isClosed = in_array($project->status, ['completed', 'cancelled'], true);
        // Closed projects' allocations barely affect available_funds (see
        // FundsHelper — only the portion consumed beyond donations ever
        // leaves the pool), but open projects' allocations subtract from it
        // directly and in full, so those stay deliberately modest relative
        // to total verified subscription revenue.
        $rounds = $isClosed ? random_int(1, 2) : 1;

        for ($i = 0; $i < $rounds; $i++) {
            ProjectFundAllocation::create([
                'project_id' => $project->id,
                'amount' => $isClosed ? random_int(20, 80) * 100 : random_int(3, 10) * 100,
                'allocation_date' => Carbon::parse($project->start_date)->addDays(random_int(1, 15)),
                'proof_file' => MoroccanData::storeFakePdf('project-fund-allocations', "Ordre de virement - {$project->name}"),
                'recorded_by' => $president->id,
            ]);
        }
    }

    private function seedPhaseRequests(User $president): void
    {
        $activeProjects = Project::where('status', 'active')->get();

        if ($activeProjects->isEmpty()) {
            return;
        }

        $phaseOrder = ['planning', 'preparation', 'in_progress', 'finishing', 'completed'];

        foreach ($activeProjects->take(4) as $index => $project) {
            $leaderUserId = $project->manager_id ?? $president->id;
            $currentPhaseIndex = array_search($project->phase, $phaseOrder, true) ?: 0;
            $nextPhase = $phaseOrder[min($currentPhaseIndex + 1, count($phaseOrder) - 1)];

            if ($nextPhase === $project->phase) {
                continue;
            }

            $status = match ($index % 3) {
                0 => 'pending',
                1 => 'approved',
                default => 'rejected',
            };

            $request = ProjectPhaseRequest::create([
                'project_id' => $project->id,
                'from_phase' => $project->phase,
                'to_phase' => $nextPhase,
                'summary' => "Demande de passage de phase pour {$project->name} : {$project->phase} -> {$nextPhase}.",
                'notes' => null,
                'requested_by' => $leaderUserId,
                'requested_at' => now()->subDays(random_int(2, 10)),
                'status' => $status,
                'reviewed_by' => $status === 'pending' ? null : $president->id,
                'reviewed_at' => $status === 'pending' ? null : now()->subDays(random_int(1, 5)),
                'rejection_reason' => $status === 'rejected' ? 'Justificatifs insuffisants pour valider cette étape.' : null,
            ]);

            ProjectPhaseRequestProof::create([
                'project_phase_request_id' => $request->id,
                'file_path' => MoroccanData::storeFakePdf('project-phase-proofs', "Justificatif - {$project->name}"),
                'original_name' => 'justificatif.pdf',
                'mime_type' => 'application/pdf',
                'size' => random_int(50000, 400000),
            ]);

            if ($status === 'pending') {
                Notification::notifyRoles(
                    ['president', 'vice-president'],
                    'phase_request_pending',
                    'Phase change awaiting approval',
                    "{$project->name} requested a phase change.",
                    $request,
                );
            }
        }
    }

    private function seedDeletionRequests(User $president): void
    {
        $candidates = Project::where('status', 'draft')->take(2)->get();

        foreach ($candidates as $index => $project) {
            $status = $index === 0 ? 'pending' : 'rejected';

            ProjectDeletionRequest::create([
                'project_id' => $project->id,
                'project_name' => $project->name,
                'reason' => 'Projet redondant avec une initiative déjà en cours.',
                'status' => $status,
                'requested_by' => $president->id,
                'requested_at' => now()->subDays(random_int(1, 6)),
                'reviewed_by' => $status === 'pending' ? null : $president->id,
                'reviewed_at' => $status === 'pending' ? null : now()->subDays(random_int(1, 3)),
                'rejection_reason' => $status === 'rejected' ? 'Le projet reste pertinent, à conserver en brouillon.' : null,
            ]);
        }
    }
}
