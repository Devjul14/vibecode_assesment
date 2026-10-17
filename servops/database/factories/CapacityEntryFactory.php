<?php

namespace Database\Factories;

use App\Enums\CapacityEntryType;
use App\Models\CapacityEntry;
use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CapacityEntry>
 */
class CapacityEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_member_id' => TeamMember::factory(),
            'type' => CapacityEntryType::Leave,
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->addDay()->endOfDay(),
            'hours' => null,
            'note' => fake()->sentence(),
        ];
    }
}
