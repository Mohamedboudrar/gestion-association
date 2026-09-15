<?php

namespace Database\Factories;

use App\Models\AssociationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssociationSetting>
 */
class AssociationSettingFactory extends Factory
{
    protected $model = AssociationSetting::class;

    public function definition(): array
    {
        return [
            'association_name' => fake()->company(),
            'association_logo' => null,
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'website' => null,
            'annual_subscription_amount' => 100,
            'currency' => 'MAD',
            'description' => fake()->sentence(),
        ];
    }
}
