<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectPhaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectPhaseRequest>
 */
class ProjectPhaseRequestFactory extends Factory
{
    protected $model = ProjectPhaseRequest::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'from_phase' => 'planning',
            'to_phase' => 'preparation',
            'summary' => fake()->sentence(),
            'notes' => null,
            'requested_by' => User::factory(),
            'requested_at' => now(),
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'reviewed_at' => now(),
            'rejection_reason' => 'Test rejection reason.',
        ]);
    }
}
