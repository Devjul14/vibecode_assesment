<?php

namespace Database\Factories;

use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Models\Recommendation;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recommendation>
 */
class RecommendationFactory extends Factory
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
            'team_member_id' => null,
            'type' => RecommendationType::Reassign,
            'severity' => 2,
            'title' => fake()->sentence(4),
            'rationale' => fake()->sentence(),
            'payload' => null,
            'status' => RecommendationStatus::Open,
            'fingerprint' => fake()->unique()->sha256(),
        ];
    }
}
