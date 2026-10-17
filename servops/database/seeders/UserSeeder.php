<?php

namespace Database\Seeders;

use App\Enums\ActorRole;
use App\Models\Department;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /**
     * Akun demo per peran. Semua password: "password".
     */
    public function run(): void
    {
        $departments = Department::query()->pluck('id', 'code');

        $accounts = [
            ['Sari Wulandari', 'sari@servops.test', ActorRole::Manager, null, null],
            ['Rudi Hartono', 'rudi@servops.test', ActorRole::TeamLead, null, null],
            ['Bayu Pratama', 'ops@servops.test', ActorRole::Requester, null, 'OPS'],
            ['Nina Kartika', 'sales@servops.test', ActorRole::Requester, null, 'SLS'],
            ['Hendra Gunawan', 'finance@servops.test', ActorRole::Requester, null, 'FIN'],
            ['Ratna Dewanti', 'mgt@servops.test', ActorRole::Requester, null, 'MGT'],
        ];

        foreach (TeamMember::query()->orderBy('name')->get() as $member) {
            $accounts[] = [$member->name, strtolower($member->name).'@servops.test', ActorRole::Member, $member->id, null];
        }

        foreach ($accounts as [$name, $email, $role, $memberId, $department]) {
            User::create([
                'name' => $name,
                'email' => $email,
                'password' => self::PASSWORD,
                'role' => $role,
                'team_member_id' => $memberId,
                'department_id' => $department ? $departments[$department] : null,
                'email_verified_at' => now(),
            ]);
        }
    }
}
