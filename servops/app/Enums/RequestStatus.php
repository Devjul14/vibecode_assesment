<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum RequestStatus: string
{
    use EnumHelpers;

    case New = 'new';
    case Triage = 'triage';
    case NeedsClarification = 'needs_clarification';
    case Ready = 'ready';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Delivered = 'delivered';
    case Returned = 'returned';
    case Accepted = 'accepted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Baru',
            self::Triage => 'Triage',
            self::NeedsClarification => 'Perlu Klarifikasi',
            self::Ready => 'Siap Dikerjakan',
            self::Assigned => 'Ditugaskan',
            self::InProgress => 'Dikerjakan',
            self::Blocked => 'Terblokir',
            self::Delivered => 'Menunggu Acceptance',
            self::Returned => 'Dikembalikan (Rework)',
            self::Accepted => 'Selesai (Diterima)',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New, self::Triage => 'slate',
            self::NeedsClarification => 'amber',
            self::Ready, self::Assigned => 'sky',
            self::InProgress => 'indigo',
            self::Blocked => 'rose',
            self::Delivered => 'violet',
            self::Returned => 'orange',
            self::Accepted => 'emerald',
            self::Cancelled => 'zinc',
        };
    }

    /**
     * State machine: transisi yang diizinkan dari status ini.
     * Guard bisnis (estimasi wajib, kompetensi, dependensi) ada di WorkflowTransitionService.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        $transitions = match ($this) {
            self::New => [self::Triage],
            self::Triage => [self::NeedsClarification, self::Ready],
            self::NeedsClarification => [self::Triage],
            self::Ready => [self::Assigned],
            self::Assigned => [self::Ready, self::InProgress],
            self::InProgress => [self::Blocked, self::Delivered, self::Triage],
            self::Blocked => [self::InProgress],
            self::Delivered => [self::Accepted, self::Returned],
            self::Returned => [self::InProgress],
            self::Accepted, self::Cancelled => [],
        };

        if (! in_array($this, [self::Accepted, self::Cancelled], true)) {
            $transitions[] = self::Cancelled;
        }

        return $transitions;
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Masih berjalan (belum accepted / cancelled). */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Accepted, self::Cancelled], true);
    }

    /** Dihitung sebagai beban kerja tim (menghabiskan / akan menghabiskan kapasitas). */
    public function countsAsLoad(): bool
    {
        return in_array($this, [
            self::Ready, self::Assigned, self::InProgress,
            self::Blocked, self::Returned,
        ], true);
    }

    /** Satu-satunya status yang dihitung "selesai" di dashboard. */
    public function isDone(): bool
    {
        return $this === self::Accepted;
    }

    /** Status yang dihitung sebagai waktu tunggu (bukan pengerjaan aktif), termasuk antrean rework. */
    public function isWaiting(): bool
    {
        return in_array($this, [self::Ready, self::Assigned, self::Blocked, self::Delivered, self::Returned], true);
    }

    /** @return list<self> */
    public static function loadStatuses(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->countsAsLoad()));
    }

    /** @return list<self> */
    public static function openStatuses(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }
}
