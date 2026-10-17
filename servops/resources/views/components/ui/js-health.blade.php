{{--
    Diagnosa di browser: tampil hanya bila JavaScript aplikasi tidak termuat
    atau request Livewire ke server gagal (mis. 419/404/500). Sengaja inline,
    bukan di bundel Vite, agar tetap jalan saat bundelnya sendiri yang gagal.
--}}
<div id="servops-js-health" role="alert" hidden
    style="position:fixed;inset:auto 1rem 1rem 1rem;z-index:9999;max-width:42rem;margin:0 auto;padding:.875rem 1rem;border-radius:.75rem;background:#fff1f2;border:1px solid #fda4af;color:#881337;font:13px/1.5 system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.12)">
    <strong data-title></strong>
    <div data-detail style="margin-top:.25rem"></div>
</div>
<script>
    (function () {
        var box = document.getElementById('servops-js-health');
        var hints = {
            boot: 'Bundel JS (public/build) tidak termuat. Di server jalankan: npm ci && npm run build, hapus file public/hot bila ada, lalu php artisan optimize:clear. Detail: php artisan servops:doctor',
            419: 'Sesi/CSRF ditolak (419). Cek SESSION_DOMAIN, SESSION_SECURE_COOKIE, tabel sessions, dan APP_URL harus sama dengan domain yang dibuka.',
            404: 'Endpoint Livewire tidak ditemukan (404). Cek konfigurasi web server (rewrite ke public/index.php) dan php artisan route:clear.',
            500: 'Error server (500). Lihat storage/logs/laravel.log di server.',
            503: 'Server tidak bisa dihubungi atau request diblokir (jaringan/CORS/mixed content).'
        };
        window.servopsReportFailure = function (code, detail) {
            box.querySelector('[data-title]').textContent = code === 'boot'
                ? 'JavaScript aplikasi tidak termuat — tombol, form, dan pencarian tidak akan berfungsi.'
                : 'Request ke server gagal (status ' + code + ') — aksi tidak tersimpan.';
            box.querySelector('[data-detail]').textContent = (hints[code] || hints[500]) + (detail ? ' [' + detail + ']' : '');
            box.hidden = false;
        };
        window.addEventListener('load', function () {
            setTimeout(function () {
                if (! window.__servopsBooted) {
                    var bundle = document.querySelector('script[type="module"][src]');
                    window.servopsReportFailure('boot', bundle ? 'dimuat dari ' + bundle.src : 'tag script bundel tidak ada');
                }
            }, 4000);
        });
    })();
</script>
