<?php

namespace App\Console\Commands;

use App\Services\Calendar\Clock;
use App\Services\Metrics\MetricsRecomputer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('servops:recompute')]
#[Description('Hitung ulang skor prioritas, risiko SLA, dan rekomendasi untuk semua request terbuka')]
class RecomputeMetricsCommand extends Command
{
    public function handle(MetricsRecomputer $recomputer, Clock $clock): int
    {
        $summary = $recomputer->run();

        $this->info("Dihitung pada {$clock->now()->translatedFormat('D d M Y H:i')}".($clock->isSimulated() ? ' (waktu simulasi)' : ''));
        $this->table(['Request terbuka', 'Berisiko', 'Perlu review', 'Rekomendasi baru', 'Diperbarui', 'Ditutup'], [[
            $summary['requests'],
            $summary['at_risk'],
            $summary['needs_review'],
            $summary['recommendations']['created'],
            $summary['recommendations']['updated'],
            $summary['recommendations']['closed'],
        ]]);

        return self::SUCCESS;
    }
}
