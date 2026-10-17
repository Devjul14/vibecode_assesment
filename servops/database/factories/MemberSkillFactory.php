<?php

namespace Database\Factories;

use App\Models\MemberSkill;
use App\Models\Skill;
use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberSkill>
 */
class MemberSkillFactory extends Factory
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
            'skill_id' => Skill::factory(),
            'level' => fake()->numberBetween(1, 3),
        ];
    }
}
