<?php

namespace Database\Factories;

use App\Models\Due;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Due>
 */
class DueFactory extends Factory
{
    protected $model = Due::class;

    public function definition(): array
    {
        $year = (int) now()->year;
        $amountDue = fake()->randomFloat(2, 50, 500);

        return [
            'member_id' => Member::factory(),
            'year' => $year,
            'amount_due' => $amountDue,
            'amount_paid' => 0,
            'balance' => $amountDue,
            'status' => 'pending',
            'due_date' => "{$year}-12-31",
            'paid_at' => null,
            'waived_reason' => null,
        ];
    }

    public function partial(): static
    {
        return $this->state(function (array $attributes) {
            $half = round($attributes['amount_due'] / 2, 2);

            return [
                'amount_paid' => $half,
                'balance' => $attributes['amount_due'] - $half,
                'status' => 'partial',
            ];
        });
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_paid' => $attributes['amount_due'],
            'balance' => 0,
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'overdue',
            'due_date' => now()->subMonth()->toDateString(),
        ]);
    }

    public function waived(): static
    {
        return $this->state(fn () => [
            'status' => 'waived',
            'waived_reason' => 'Financial hardship',
        ]);
    }
}
