<?php

namespace Database\Factories;

use App\Enums\TimeLogKind;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Models\TimeLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeLog>
 */
class TimeLogFactory extends Factory
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
            'team_member_id' => TeamMember::factory(),
            'started_at' => now()->subHours(2),
            'ended_at' => now(),
            'hours' => 2,
            'kind' => TimeLogKind::Work,
        ];
    }
}
