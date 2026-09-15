<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectFundAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectFundAllocation>
 */
class ProjectFundAllocationFactory extends Factory
{
    protected $model = ProjectFundAllocation::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'allocation_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'proof_file' => null,
            'recorded_by' => null,
        ];
    }
}
