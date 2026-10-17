<?php

namespace App\Data;

/**
 * Alasan yang bisa dibaca manusia: kode (untuk logika), pesan, dan tindakan yang disarankan.
 */
final readonly class Reason
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $action = null,
    ) {}

    /** @var array<string, string> label singkat per kode alasan (untuk chart & badge) */
    private const LABELS = [
        'QUEUE_OVERLOAD' => 'Antrean assignee penuh',
        'BLOCKED_NEAR_LATEST_START' => 'Terblokir mendekati batas mulai',
        'EXTERNAL_ETA_LATE' => 'ETA dependensi terlambat',
        'DEPENDENCY_NO_ETA' => 'Dependensi tanpa ETA',
        'DEPENDENCY_WAIT' => 'Menunggu dependensi',
        'UNASSIGNED_LOW_SLACK' => 'Belum ditugaskan, slack rendah',
        'ASSIGNEE_UNAVAILABLE' => 'Assignee tidak tersedia',
        'SCOPE_UNCLEAR' => 'Scope belum jelas',
        'ESTIMATE_GROWTH' => 'Estimasi membengkak',
        'REWORK' => 'Pernah dikembalikan',
        'SCARCE_SKILL' => 'Skill langka',
        'NO_QUALIFIED_CAPACITY' => 'Tidak ada anggota kompeten',
        'AWAITING_ACCEPTANCE' => 'Menunggu acceptance',
        'IMPACT_UNKNOWN' => 'Dampak belum diketahui',
        'ESTIMATE_UNKNOWN' => 'Estimasi belum diketahui',
        'CLOSE_CALL' => 'Skor berdekatan',
        'OVERRIDE_ACTIVE' => 'Override manajemen',
        'UNDERQUALIFIED_ASSIGNMENT' => 'Penugasan di bawah syarat',
        'NO_QUALIFIED_MEMBER' => 'Tidak ada anggota kompeten',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }

    /** @return array{code: string, message: string, action: string|null} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message, 'action' => $this->action];
    }

    /**
     * @param  list<self>  $reasons
     * @return list<array{code: string, message: string, action: string|null}>
     */
    public static function toArrayList(array $reasons): array
    {
        return array_map(fn (self $reason): array => $reason->toArray(), $reasons);
    }
}
