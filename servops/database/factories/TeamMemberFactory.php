<?php

namespace Database\Factories;

use App\Enums\Seniority;
use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMember>
 */
class TeamMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'role_title' => fake()->jobTitle(),
            'seniority' => fake()->randomElement(Seniority::cases()),
            'daily_hours' => 8,
            'focus_factor' => 0.75,
            'routine_hours_per_day' => 0.5,
            'meeting_hours_per_day' => 0.5,
            'wip_limit' => 3,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
