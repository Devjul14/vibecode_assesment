<?php

namespace Database\Factories;

use App\Enums\DependencyType;
use App\Models\RequestDependency;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestDependency>
 */
class RequestDependencyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_request_id' => ServiceRequest::factory(),
            'depends_on_request_id' => null,
            'type' => DependencyType::External,
            'external_party' => fake()->company(),
            'description' => fake()->sentence(),
            'expected_at' => now()->addDay()->setTime(15, 0),
            'resolved_at' => null,
        ];
    }
}
