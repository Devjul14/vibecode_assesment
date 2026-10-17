<?php

namespace App\Exceptions;

use App\Enums\RequestStatus;
use DomainException;

/**
 * Pelanggaran aturan workflow. Pesan ditujukan ke pengguna; $rule untuk logika & test.
 */
class WorkflowException extends DomainException
{
    public function __construct(public readonly string $rule, string $message)
    {
        parent::__construct($message);
    }

    public static function transitionNotAllowed(RequestStatus $from, RequestStatus $to): self
    {
        return new self('TRANSITION_NOT_ALLOWED', "Status tidak dapat berubah dari \"{$from->label()}\" ke \"{$to->label()}\".");
    }

    public static function reasonRequired(string $context): self
    {
        return new self('REASON_REQUIRED', "Alasan wajib diisi untuk {$context}.");
    }

    public static function estimateRequired(): self
    {
        return new self('ESTIMATE_REQUIRED', 'Estimasi belum diketahui. Lakukan klarifikasi scope dan isi estimasi sebelum request siap dikerjakan.');
    }

    public static function skillRequired(): self
    {
        return new self('SKILL_REQUIRED', 'Kompetensi yang dibutuhkan belum ditentukan.');
    }

    public static function assigneeRequired(): self
    {
        return new self('ASSIGNEE_REQUIRED', 'Request belum memiliki penanggung jawab.');
    }

    public static function memberInactive(string $name): self
    {
        return new self('MEMBER_INACTIVE', "{$name} tidak aktif.");
    }

    public static function memberUnavailable(string $name, string $reason): self
    {
        return new self('MEMBER_UNAVAILABLE', "{$name} sedang tidak tersedia ({$reason}).");
    }

    public static function underqualified(string $name, int $level, int $required): self
    {
        return new self(
            'UNDERQUALIFIED',
            "{$name} memiliki level {$level}, sedangkan request membutuhkan level {$required}. Tugaskan anggota lain, atau pilih mentor yang memenuhi syarat dan isi alasan override.",
        );
    }

    public static function invalidMentor(string $name): self
    {
        return new self('INVALID_MENTOR', "{$name} tidak memenuhi level kompetensi untuk menjadi mentor pairing.");
    }

    public static function openDependencies(int $count): self
    {
        return new self('OPEN_DEPENDENCIES', "Masih ada {$count} dependensi yang belum selesai.");
    }

    public static function acceptanceRole(): self
    {
        return new self('ACCEPTANCE_ROLE', 'Hanya pemohon atau manager yang dapat menerima hasil pekerjaan.');
    }

    public static function reworkEstimateRequired(): self
    {
        return new self('REWORK_ESTIMATE_REQUIRED', 'Estimasi jam rework wajib diisi agar beban revisi tercatat.');
    }

    public static function reworkCauseRequired(): self
    {
        return new self('REWORK_CAUSE_REQUIRED', 'Penyebab rework wajib dipilih.');
    }

    public static function invalidEstimate(): self
    {
        return new self('INVALID_ESTIMATE', 'Estimasi harus lebih dari 0 jam.');
    }

    public static function requestClosed(): self
    {
        return new self('REQUEST_CLOSED', 'Request sudah ditutup dan tidak dapat diubah.');
    }

    public static function notReassignable(RequestStatus $status): self
    {
        return new self('NOT_REASSIGNABLE', "Penugasan tidak dapat dipindahkan saat status \"{$status->label()}\".");
    }
}
