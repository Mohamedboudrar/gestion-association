<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'supplier_name' => fake()->company(),
            'description' => fake()->sentence(),
            'amount' => fake()->randomFloat(2, 10, 3000),
            'payment_method' => fake()->randomElement(['cash', 'bank_transfer', 'card']),
            'invoice_number' => null,
            'invoice_path' => null,
            'expense_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'notes' => null,
            'created_by' => null,
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

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => 'paid',
            'approved_at' => now(),
            'paid_at' => now(),
        ]);
    }
}
