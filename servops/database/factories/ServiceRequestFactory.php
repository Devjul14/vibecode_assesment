<?php

namespace Database\Factories;

use App\Enums\EstimateConfidence;
use App\Enums\ImpactLevel;
use App\Enums\RequesterPriority;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'SR-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(['analysis', 'incident', 'automation', 'reporting', 'change', 'investigation']),
            'requester_priority' => fake()->randomElement(RequesterPriority::cases()),
            'impact_level' => ImpactLevel::Medium,
            'required_skill_id' => Skill::factory(),
            'required_level' => 2,
            'estimate_hours' => $estimate = fake()->randomElement([2, 4, 6, 8, 12]),
            'estimate_confidence' => EstimateConfidence::Medium,
            'remaining_hours' => $estimate,
            'deadline_at' => now()->addDays(fake()->numberBetween(1, 5))->setTime(17, 0),
            'status' => RequestStatus::Ready,
        ];
    }

    public function status(RequestStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function assignedTo(TeamMember $member): static
    {
        return $this->state(fn (array $attributes) => [
            'assignee_id' => $member->id,
            'status' => RequestStatus::Assigned,
        ]);
    }

    public function unknownEstimate(): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_hours' => null,
            'remaining_hours' => null,
            'estimate_confidence' => EstimateConfidence::Low,
            'status' => RequestStatus::NeedsClarification,
        ]);
    }

    public function unknownImpact(): static
    {
        return $this->state(fn (array $attributes) => ['impact_level' => ImpactLevel::Unknown]);
    }
}
