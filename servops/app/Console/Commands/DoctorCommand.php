<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('servops:doctor')]
#[Description('Periksa konfigurasi server yang membuat UI tampil tetapi tombol/form tidak berfungsi')]
class DoctorCommand extends Command
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private int $failures = 0;

    public function handle(): int
    {
        $this->checkEnvironment();
        $this->checkFrontendBuild();
        $this->checkDatabase();
        $this->checkFilesystem();

        $this->table(['Status', 'Pemeriksaan', 'Detail / tindakan'], $this->rows);

        if ($this->failures > 0) {
            $this->error("{$this->failures} masalah ditemukan. Perbaiki baris FAIL, lalu jalankan: php artisan optimize:clear && php artisan optimize");

            return self::FAILURE;
        }

        $this->info('Tidak ada masalah konfigurasi yang terdeteksi. Bila UI masih tidak berfungsi, lihat banner merah di browser untuk kode status request.');

        return self::SUCCESS;
    }

    private function checkEnvironment(): void
    {
        $this->check(filled(config('app.key')), 'APP_KEY', 'Kosong — jalankan php artisan key:generate (semua sesi & CSRF gagal tanpa ini).');
        $this->note('APP_ENV / APP_DEBUG', config('app.env').' / '.(config('app.debug') ? 'true' : 'false'));

        $url = (string) config('app.url');
        $this->check(! str_contains($url, 'localhost') || app()->isLocal(), 'APP_URL', "{$url} — set ke domain prod (mis. https://servops.example.com).", $url);
        $this->note('Config cache', app()->configurationIsCached() ? 'aktif — jalankan ulang php artisan optimize setelah mengubah .env' : 'tidak aktif');
        $this->check(Route::has('default-livewire.update') || Route::has('livewire.update'), 'Route Livewire update', 'Tidak terdaftar — jalankan php artisan route:clear.');
    }

    private function checkFrontendBuild(): void
    {
        $this->check(! file_exists(public_path('hot')), 'public/hot', 'Ada — browser mengambil JS dari dev server Vite yang tidak berjalan. Hapus file public/hot.');

        $manifestPath = public_path('build/manifest.json');

        if (! $this->check(file_exists($manifestPath), 'public/build/manifest.json', 'Tidak ada — jalankan npm ci && npm run build di server.')) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
        $entry = $manifest['resources/js/app.js']['file'] ?? null;

        if (! $this->check($entry !== null && file_exists(public_path("build/{$entry}")), 'Bundel resources/js/app.js', 'Entri tidak ada di manifest — jalankan npm run build.')) {
            return;
        }

        $bundle = (string) file_get_contents(public_path("build/{$entry}"));
        $this->check(
            str_contains($bundle, '__servopsBooted'),
            'Bundel berisi Livewire',
            "build/{$entry} versi lama (tidak memulai Livewire) — jalankan npm ci && npm run build.",
            "build/{$entry}",
        );

        $sourceChangedAt = filemtime(resource_path('js/app.js'));
        $this->check(
            filemtime($manifestPath) >= $sourceChangedAt,
            'Build lebih baru dari source',
            'resources/js/app.js lebih baru dari build — jalankan npm run build.',
        );
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            $this->check(false, 'Koneksi database', $exception->getMessage());

            return;
        }

        $this->check(true, 'Koneksi database', '', DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName());

        if (config('session.driver') === 'database') {
            $this->check(Schema::hasTable(config('session.table', 'sessions')), 'Tabel sessions', 'Tidak ada — jalankan php artisan migrate --force (tanpa ini login & CSRF gagal 419).');
        }

        if (config('cache.default') === 'database') {
            $this->check(Schema::hasTable('cache'), 'Tabel cache', 'Tidak ada — jalankan php artisan migrate --force.');
        }

        if (config('servops.demo_accounts')) {
            $count = Schema::hasTable('users') ? User::query()->count() : 0;
            $this->check($count > 0, 'Akun demo', 'Tabel users kosong — jalankan php artisan db:seed --force.', "{$count} user");
        }
    }

    private function checkFilesystem(): void
    {
        foreach ([storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache')] as $path) {
            $this->check(is_writable($path), 'Writable: '.str_replace(base_path().'/', '', $path), 'Tidak bisa ditulis web server — perbaiki permission (chown/chmod).');
        }
    }

    private function check(bool $passed, string $label, string $fix, string $okDetail = ''): bool
    {
        if (! $passed) {
            $this->failures++;
        }

        $this->rows[] = [$passed ? '<info>OK</info>' : '<error>FAIL</error>', $label, $passed ? $okDetail : $fix];

        return $passed;
    }

    private function note(string $label, string $detail): void
    {
        $this->rows[] = ['<comment>INFO</comment>', $label, $detail];
    }
}
