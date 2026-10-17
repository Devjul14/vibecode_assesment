<?php

namespace Database\Seeders;

use App\Models\BusinessProcess;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\Skill;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    /**
     * Departemen, proses bisnis (dasar penilaian dampak), skill, dan hari libur.
     */
    public function run(): void
    {
        $departments = collect([
            'OPS' => 'Operasional',
            'FIN' => 'Finance',
            'SLS' => 'Sales',
            'HR' => 'Human Resources',
            'MGT' => 'Manajemen',
            'IT' => 'Teknologi Informasi',
        ])->map(fn (string $name, string $code) => Department::create(['code' => $code, 'name' => $name]));

        $processes = [
            ['OPS', 'Order-to-cash', 5, 4],
            ['MGT', 'Pelaporan manajemen bulanan', 4, 3],
            ['FIN', 'Rekonsiliasi data penjualan', 4, 3],
            ['OPS', 'Laporan operasional rutin', 3, 2],
            ['SLS', 'Pengelolaan retur pelanggan', 3, 2],
            ['HR', 'Administrasi kepegawaian', 2, 1],
        ];

        foreach ($processes as [$department, $name, $criticality, $usedBy]) {
            BusinessProcess::create([
                'department_id' => $departments[$department]->id,
                'name' => $name,
                'criticality' => $criticality,
                'used_by_departments_count' => $usedBy,
            ]);
        }

        $skills = [
            'data_analysis' => 'Analisis Data',
            'reporting' => 'Pelaporan',
            'troubleshooting' => 'Troubleshooting Sistem',
            'automation' => 'Otomatisasi / Scripting',
            'process_analysis' => 'Analisis Proses Bisnis',
            'admin' => 'Administrasi',
        ];

        foreach ($skills as $code => $name) {
            Skill::create(['code' => $code, 'name' => $name]);
        }

        $holidays = [
            '2026-08-17' => 'Hari Kemerdekaan RI',
            '2026-12-24' => 'Cuti Bersama Natal',
            '2026-12-25' => 'Hari Raya Natal',
            '2027-01-01' => 'Tahun Baru Masehi',
        ];

        foreach ($holidays as $date => $name) {
            Holiday::create(['date' => $date, 'name' => $name]);
        }
    }
}
