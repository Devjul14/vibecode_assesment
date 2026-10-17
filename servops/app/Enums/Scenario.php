<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Skenario perubahan dari soal (BLUEPRINT §15) + kontrol jam simulasi.
 */
enum Scenario: string
{
    use EnumHelpers;

    case ImpactEscalation = 'impact_escalation';
    case MemberUnavailable = 'member_unavailable';
    case DeliveryReturned = 'delivery_returned';
    case DependencyDelay = 'dependency_delay';
    case UrgentInsert = 'urgent_insert';
    case EstimateGrowth = 'estimate_growth';
    case ClockAdvance = 'clock_advance';

    public function code(): string
    {
        return match ($this) {
            self::ImpactEscalation => 'S1',
            self::MemberUnavailable => 'S2',
            self::DeliveryReturned => 'S3',
            self::DependencyDelay => 'S4',
            self::UrgentInsert => 'S5',
            self::EstimateGrowth => 'S6',
            self::ClockAdvance => '⏱',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ImpactEscalation => 'Prioritas rendah ternyata berdampak luas',
            self::MemberUnavailable => 'Anggota kritis mendadak tidak tersedia',
            self::DeliveryReturned => 'Hasil dikembalikan setelah diserahkan',
            self::DependencyDelay => 'Menunggu data, tenggat makin dekat',
            self::UrgentInsert => 'Request mendadak tanpa tambah kapasitas',
            self::EstimateGrowth => 'Estimasi membengkak setelah analisis',
            self::ClockAdvance => 'Waktu berjalan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ImpactEscalation => 'Triage SR-107 menemukan selisih data memengaruhi rekonsiliasi 4 departemen (dampak Kritis).',
            self::MemberUnavailable => 'Anggota yang dipilih sakit beberapa hari kerja mulai hari ini.',
            self::DeliveryReturned => 'Request dikerjakan sampai diserahkan, lalu dikembalikan pemohon karena tidak sesuai kebutuhan.',
            self::DependencyDelay => 'Tim Finance mengabarkan data Q3 terlambat, dan waktu bergerak maju.',
            self::UrgentInsert => 'Direksi meminta audit stok mendadak, dipaksa ke peringkat 1 tanpa tambahan orang.',
            self::EstimateGrowth => 'Analisis awal SR-103 menunjukkan scope lebih kompleks: 12 → 20 jam.',
            self::ClockAdvance => 'Jam simulasi dimajukan; slack menyusut dan risiko dihitung ulang.',
        };
    }

    /** Hal yang seharusnya terlihat setelah skenario dijalankan. */
    public function expectation(): string
    {
        return match ($this) {
            self::ImpactEscalation => 'Skor & peringkat SR-107 naik, flag review dampak hilang, request lain bergeser.',
            self::MemberUnavailable => 'Pekerjaannya berisiko (assignee tidak tersedia) → rekomendasi pindahkan, atau pairing/negosiasi bila tidak ada pengganti kompeten.',
            self::DeliveryReturned => 'Jumlah selesai tidak bertambah, rework rate & beban naik, request kembali dihitung sebagai pekerjaan.',
            self::DependencyDelay => 'SR-105 memburuk (berisiko → akan terlambat) dengan alasan ETA terlambat dan rekomendasi eskalasi.',
            self::UrgentInsert => 'Preview/diff memperlihatkan pekerjaan mana yang tergeser dan menjadi berisiko — harga dari keputusan.',
            self::EstimateGrowth => 'Flag "estimasi membengkak" pada risiko & review manusia; proyeksi selesai mundur.',
            self::ClockAdvance => 'Slack berkurang; request yang belum dikerjakan bisa berubah status risiko.',
        };
    }

    /** Skenario yang hanya boleh dijalankan manager (melibatkan override prioritas). */
    public function requiresManager(): bool
    {
        return $this === self::UrgentInsert;
    }

    /** @return list<self> */
    public static function catalog(): array
    {
        return array_values(array_filter(self::cases(), fn (self $scenario) => $scenario !== self::ClockAdvance));
    }
}
