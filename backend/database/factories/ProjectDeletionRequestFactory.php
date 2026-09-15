<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectDeletionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectDeletionRequest>
 */
class ProjectDeletionRequestFactory extends Factory
{
    protected $model = ProjectDeletionRequest::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'project_name' => fake()->words(3, true),
            'reason' => fake()->sentence(),
            'requested_by' => null,
            'requested_at' => now(),
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ];
    }
}
