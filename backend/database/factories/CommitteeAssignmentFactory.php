<?php

namespace Database\Factories;

use App\Models\CommitteeAssignment;
use App\Models\Member;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommitteeAssignment>
 */
class CommitteeAssignmentFactory extends Factory
{
    protected $model = CommitteeAssignment::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'member_id' => Member::factory(),
            'role' => null,
            'committee_role' => 'member',
            'responsibility' => null,
            'assigned_by' => null,
            'assigned_at' => now(),
            'removed_by' => null,
            'removed_at' => null,
            'reason' => null,
            'action' => 'assigned',
        ];
    }

    public function dissolved(): static
    {
        return $this->state(fn () => [
            'removed_at' => now(),
            'action' => 'dissolved',
            'reason' => 'Project completed.',
        ]);
    }
}
