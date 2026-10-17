<?php

namespace Database\Seeders;

use App\Enums\Seniority;
use App\Models\Skill;
use App\Models\TeamMember;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * Tim simulasi. Sengaja dirancang dengan dua jebakan:
     * - Citra satu-satunya automation level 3 (bottleneck skill langka).
     * - Dewi punya kapasitas kosong, tetapi data_analysis hanya level 1.
     */
    public function run(): void
    {
        $skills = Skill::query()->pluck('id', 'code');

        $team = [
            [
                'name' => 'Andi', 'role_title' => 'Senior Data Analyst', 'seniority' => Seniority::Senior,
                'routine_hours_per_day' => 0.5, 'meeting_hours_per_day' => 1.0,
                'skills' => ['data_analysis' => 3, 'reporting' => 3],
            ],
            [
                'name' => 'Budi', 'role_title' => 'Operations Engineer', 'seniority' => Seniority::Mid,
                'routine_hours_per_day' => 0.5, 'meeting_hours_per_day' => 0.5,
                'skills' => ['troubleshooting' => 3, 'admin' => 2, 'automation' => 1],
            ],
            [
                'name' => 'Citra', 'role_title' => 'Automation Engineer', 'seniority' => Seniority::Senior,
                'routine_hours_per_day' => 0.5, 'meeting_hours_per_day' => 0.5,
                'skills' => ['automation' => 3, 'data_analysis' => 2],
            ],
            [
                'name' => 'Dewi', 'role_title' => 'Junior Analyst', 'seniority' => Seniority::Junior,
                'routine_hours_per_day' => 0.5, 'meeting_hours_per_day' => 0.5,
                'skills' => ['reporting' => 2, 'data_analysis' => 1, 'admin' => 2],
            ],
            [
                'name' => 'Eko', 'role_title' => 'Business Analyst', 'seniority' => Seniority::Mid,
                'routine_hours_per_day' => 0.5, 'meeting_hours_per_day' => 1.5,
                'skills' => ['process_analysis' => 3, 'reporting' => 2],
            ],
        ];

        foreach ($team as $data) {
            $member = TeamMember::create([
                ...collect($data)->except('skills')->all(),
                'daily_hours' => 8,
                'focus_factor' => 0.75,
                'wip_limit' => 3,
                'is_active' => true,
            ]);

            foreach ($data['skills'] as $code => $level) {
                $member->skills()->attach($skills[$code], ['level' => $level]);
            }
        }
    }
}
