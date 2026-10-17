<?php

namespace Database\Factories;

use App\Models\PriorityOverride;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriorityOverride>
 */
class PriorityOverrideFactory extends Factory
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
            'old_rank' => fake()->numberBetween(2, 10),
            'forced_rank' => 1,
            'pinned' => true,
            'reason' => fake()->sentence(),
            'approved_by' => fake()->name(),
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }
}
