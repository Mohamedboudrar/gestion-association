<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-6 months', 'now');

        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->paragraph(),
            'start_date' => $start,
            'end_date' => fake()->dateTimeBetween($start, '+1 year'),
            'budget' => fake()->randomFloat(2, 1000, 50000),
            'status' => 'draft',
            'phase' => 'planning',
            'latitude' => null,
            'longitude' => null,
            'manager_id' => null,
        ];
    }

    public function committeeReady(): static
    {
        return $this->state(fn () => ['status' => 'committee_ready']);
    }

    public function fundingReady(): static
    {
        return $this->state(fn () => ['status' => 'funding_ready']);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed', 'phase' => 'completed']);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
