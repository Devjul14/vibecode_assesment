<?php

namespace Database\Factories;

use App\Models\BusinessProcess;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessProcess>
 */
class BusinessProcessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->words(3, true),
            'criticality' => fake()->numberBetween(1, 5),
            'used_by_departments_count' => fake()->numberBetween(1, 5),
        ];
    }
}
