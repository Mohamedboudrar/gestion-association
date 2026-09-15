<?php

namespace Database\Factories;

use App\Models\Donation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    protected $model = Donation::class;

    public function definition(): array
    {
        return [
            'member_id' => null,
            'project_id' => Project::factory(),
            'donor_name' => fake()->name(),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'payment_method' => fake()->randomElement(['cash', 'bank_transfer', 'card']),
            'receipt_number' => null,
            'receipt_file' => null,
            'donation_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'notes' => null,
            'recorded_by' => null,
            'status' => 'draft',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    public function rejected(string $type = 'details'): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => 'Test rejection reason.',
            'rejection_type' => $type,
        ]);
    }
}
