<?php

namespace Database\Factories;

use App\Enums\ActorRole;
use App\Models\EstimateRevision;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EstimateRevision>
 */
class EstimateRevisionFactory extends Factory
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
            'old_hours' => 4,
            'new_hours' => 6,
            'delta_pct' => 50,
            'kind' => 'revision',
            'scope_change' => false,
            'reason' => fake()->sentence(),
            'actor_role' => ActorRole::Member,
            'actor_name' => fake()->firstName(),
        ];
    }
}
