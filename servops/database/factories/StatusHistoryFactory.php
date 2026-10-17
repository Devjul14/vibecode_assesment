<?php

namespace Database\Factories;

use App\Enums\ActorRole;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\StatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusHistory>
 */
class StatusHistoryFactory extends Factory
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
            'from_status' => RequestStatus::Triage,
            'to_status' => RequestStatus::Ready,
            'actor_role' => ActorRole::TeamLead,
            'actor_name' => fake()->firstName(),
            'reason' => null,
            'meta' => null,
        ];
    }
}
