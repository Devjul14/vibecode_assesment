<?php

namespace App\Livewire\Concerns;

use App\Data\Actor;
use App\Data\TransitionResult;
use App\Exceptions\WorkflowException;
use App\Services\ActorContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Pelaku saat ini + umpan balik aksi (toast). Pelanggaran aturan workflow dan akses ditolak
 * ditampilkan sebagai pesan, bukan halaman error.
 */
trait InteractsWithServOps
{
    /** @var array{type: string, message: string, warnings?: list<string>}|null */
    public ?array $notice = null;

    protected function actor(): Actor
    {
        return app(ActorContext::class)->current();
    }

    /**
     * Jalankan aksi; tampilkan pesan sukses (+ peringatan) atau alasan penolakan.
     */
    protected function attempt(Closure $action, string $success): bool
    {
        try {
            $result = $action();
        } catch (WorkflowException $exception) {
            return $this->notify('error', $exception->getMessage());
        } catch (AuthorizationException) {
            return $this->notify('error', 'Peran Anda tidak memiliki akses untuk aksi ini.');
        }

        $warnings = $result instanceof TransitionResult ? array_column($result->warnings, 'message') : [];
        $this->notify('success', $success, $warnings);

        return true;
    }

    /**
     * @param  list<string>  $warnings
     */
    protected function notify(string $type, string $message, array $warnings = []): bool
    {
        $this->notice = ['type' => $type, 'message' => $message, 'warnings' => $warnings];
        $this->dispatch('toast', type: $type, message: $message, warnings: $warnings);

        return $type === 'success';
    }
}
