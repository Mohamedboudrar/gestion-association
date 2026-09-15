<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $paymentDate = fake()->dateTimeBetween('-6 months', 'now');

        return [
            'member_id' => Member::factory(),
            'amount' => fake()->randomFloat(2, 50, 500),
            'payment_method' => fake()->randomElement(['cash', 'bank_transfer', 'card']),
            'receipt_number' => null,
            'receipt_file' => null,
            'payment_date' => $paymentDate,
            'expires_at' => (clone $paymentDate)->modify('+1 year'),
            'status' => 'pending',
            'verified_by' => null,
            'verified_at' => null,
            'notes' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => [
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'expired',
            'expires_at' => now()->subDay(),
        ]);
    }
}
