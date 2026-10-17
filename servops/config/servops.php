<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Simulation Clock
    |--------------------------------------------------------------------------
    |
    | Kunci "waktu sekarang" untuk demo skenario (ISO 8601). Null = waktu nyata.
    | Semua engine wajib membaca waktu lewat App\Services\Calendar\Clock.
    |
    */

    'now' => env('SERVOPS_NOW'),

    /*
    | Hitung ulang skor, risiko & rekomendasi otomatis setelah setiap perubahan workflow.
    */

    'auto_recompute' => env('SERVOPS_AUTO_RECOMPUTE', true),

    /*
    | Tampilkan daftar akun demo di halaman login (prototipe). Matikan di produksi.
    */

    'demo_accounts' => env('SERVOPS_DEMO_ACCOUNTS', true),

    'demo_password' => 'password',

    /*
    | Izinkan tombol "Reset data simulasi" (migrate:fresh --seed). Default hanya di environment local.
    */

    'allow_reset' => env('SERVOPS_ALLOW_RESET', env('APP_ENV') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Working Calendar
    |--------------------------------------------------------------------------
    */

    'calendar' => [
        'work_start' => '08:00',
        'work_end' => '17:00',
        'break_start' => '12:00',
        'break_end' => '13:00',
        'work_days' => [1, 2, 3, 4, 5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Priority Engine
    |--------------------------------------------------------------------------
    |
    | Bobot faktor skor prioritas (total = 1.0). Prioritas pemohon sengaja kecil.
    |
    */

    'priority' => [
        'weights' => [
            'impact' => 0.35,
            'urgency' => 0.25,
            'dependency' => 0.15,
            'requester' => 0.10,
            'scarcity' => 0.10,
            'age' => 0.05,
        ],
        'close_call_points' => 3,
        // Rasio jam efektif tim terhadap jam kerja kalender (fokus, rapat, rutin) untuk tekanan waktu.
        'effective_ratio' => 0.6,
        // Urgensi kedekatan tenggat: 0 jika tenggat ≥ N jam kerja lagi, 100 jika hari ini habis.
        'proximity_horizon_hours' => 40,
        'estimate_growth_pct' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Capacity & Assignment
    |--------------------------------------------------------------------------
    */

    'capacity' => [
        'horizon_days' => 5,
        'overload_threshold' => 1.0,
        'idle_threshold' => 0.6,
        'bottleneck_ratio' => 1.0,
        'bottleneck_warning_ratio' => 0.8,
        'qualified_min_level' => 2,
    ],

    'assignment' => [
        'weights' => [
            'capacity_fit' => 0.45,
            'finish_before_deadline' => 0.25,
            'skill_match' => 0.15,
            'context' => 0.15,
        ],
        'overqualified_score' => 70,
    ],

    'recommendation' => [
        'rebalance_overload' => 1.1,
        'rebalance_idle' => 0.6,
    ],

    /*
    |--------------------------------------------------------------------------
    | SLA & Risk
    |--------------------------------------------------------------------------
    */

    'risk' => [
        'buffer_min_hours' => 2,
        'buffer_pct' => 0.20,
        'blocked_warning_hours' => 4,
        'unassigned_low_slack_hours' => 8,
        'estimate_growth_pct' => 30,
        // Skill langka: hanya 1 orang kompeten dan bebannya ≥ rasio ini dari kapasitasnya sampai tenggat.
        'scarce_occupancy' => 0.6,
        'projection_max_days' => 60,
        'sla_clock_pauses_when_blocked' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Estimation
    |--------------------------------------------------------------------------
    */

    'estimation' => [
        'min_samples' => 5,
        'iqr_multiplier' => 1.5,
        'planning_percentile' => 80,
        // Dipakai bila estimasi kosong dan data historis kategori < min_samples.
        'fallback_hours' => 8,
    ],

];
