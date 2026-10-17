# ServOps — Intelligent Service Operations & Capacity Planning

Prototipe sistem pendukung keputusan untuk tim layanan internal: memprioritaskan service request secara
transparan, menghitung kapasitas tim berbasis kompetensi, mendeteksi risiko SLA **sebelum** tenggat, dan
memberi rekomendasi tindakan yang dapat dijelaskan.

> Spesifikasi teknis lengkap (skema, formula, aturan bisnis, skenario) ada di
> [`../BLUEPRINT.md`](../BLUEPRINT.md). README ini adalah ringkasan + panduan menjalankan.

---

## Daftar Isi

1. [Status Proyek](#1-status-proyek)
2. [Stack](#2-stack)
3. [Prasyarat](#3-prasyarat)
4. [Instalasi & Menjalankan](#4-instalasi--menjalankan)
5. [Konfigurasi](#5-konfigurasi)
6. [Arsitektur](#6-arsitektur)
7. [Konsep Domain](#7-konsep-domain)
8. [Halaman & Route](#8-halaman--route)
9. [Data Seed & Skenario Demo](#9-data-seed--skenario-demo)
10. [Testing](#10-testing)
11. [Perintah Berguna](#11-perintah-berguna)
12. [Keputusan Desain](#12-keputusan-desain)
13. [Catatan & Masalah Diketahui](#13-catatan--masalah-diketahui)

---

## 1. Status Proyek

Proyek mengikuti rencana 7 fase di BLUEPRINT §17. Posisi saat ini: **Fase 6 (Simulasi) selesai** — tersisa Fase 7 (polish & dokumen).

| Fase | Isi | Status |
|---|---|---|
| 1. Fondasi | Laravel 13, `.env` MySQL, Livewire, Tailwind, layout, enums, migrations, models + relasi | ✅ |
| 2. Seed | Tim, SR-101..107, WIP, 48 data historis (dengan outlier) | ✅ |
| 3. Kalender & Workflow | `Clock`, `WorkingCalendar`, `WorkflowTransitionService`, `RequestChangeService`, riwayat | ✅ |
| 4. Engine | Priority, Capacity, Assignment, Risk, Estimate, Recommendation, `MetricsRecomputer`, `servops:recompute` | ✅ |
| 5. UI | Dashboard, Requests (daftar, buat, detail + aksi + override/preview), Tim & Kapasitas, Risiko SLA, Rekomendasi, "bertindak sebagai" | ✅ |
| 6. Simulasi | Skenario S1–S6 (preview dry-run + jalankan), kontrol jam, riwayat run + diff, reset | ✅ |
| 7. Polish | README final, screenshot, dokumen BA & Design | ⬜ belum |

Bagian 6–9 di bawah mendeskripsikan **target desain**; perbarui tabel ini seiring implementasi.

---

## 2. Stack

| Komponen | Versi / Pilihan |
|---|---|
| PHP | ≥ 8.3 (lokal: 8.4.12) |
| Framework | Laravel 13 (`laravel/framework ^13.17`) |
| Database | MySQL 9.4, DB `vibecoding` |
| UI | Blade + Livewire 4 + Tailwind CSS 4 + Alpine.js |
| Chart | Chart.js *(belum di-install — `npm install chart.js`)* |
| Build | Vite 8 |
| Queue | `sync` (tanpa worker) |
| Test | PHPUnit 12, SQLite in-memory |

---

## 3. Prasyarat

- PHP 8.3+ dengan ekstensi `pdo_mysql`
- Composer 2
- Node.js + npm
- MySQL berjalan di `127.0.0.1:3306`

---

## 4. Instalasi & Menjalankan

```bash
cd servops

# 1. Dependensi
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
# isi DB_PASSWORD di .env

# 3. Database
mysql -uroot -p -e "CREATE DATABASE IF NOT EXISTS vibecoding CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan migrate:fresh --seed

# 4. Jalankan (server + Vite)
composer run dev
```

Buka <http://localhost:8000>.

---

## 5. Konfigurasi

Variabel `.env` penting:

| Variabel | Nilai | Keterangan |
|---|---|---|
| `DB_CONNECTION` | `mysql` | Default Laravel 13 adalah SQLite — wajib diganti |
| `DB_DATABASE` | `vibecoding` | |
| `SESSION_DRIVER` / `CACHE_STORE` | `database` | |
| `QUEUE_CONNECTION` | `sync` | Recompute berjalan langsung |
| `APP_TIMEZONE` | `Asia/Jakarta` | Lihat [§13](#13-catatan--masalah-diketahui) |
| `SERVOPS_NOW` | kosong / ISO 8601 | Mengunci "waktu sekarang" untuk demo & test. Kosong = waktu nyata |

Bobot, ambang, dan jam kerja direncanakan di `config/servops.php` *(belum dibuat)*. Semua angka di
BLUEPRINT adalah default yang dapat dikonfigurasi.

---

## 6. Arsitektur

```
UI (Blade + Livewire)
  └─ Controllers / Livewire components   ← tipis, hanya validasi
       └─ Domain Services (app/Services)  ← semua logika bisnis, mudah di-unit-test
            └─ Eloquent Models + MySQL
                 └─ Observers → RecomputeMetrics (sync) + Scheduler
```

**Domain services** (rencana):

| Service | Tanggung jawab |
|---|---|
| `Calendar/Clock` | Sumber "waktu sekarang" (nyata atau simulasi). Semua engine wajib memakainya, bukan `now()` |
| `Calendar/WorkingCalendar` | Jam kerja 08:00–17:00 Sen–Jum, istirahat 12–13, dikurangi libur |
| `Workflow/WorkflowTransitionService` | State machine + guard + `status_histories` |
| `Priority/PriorityEngine` | Skor 0–100 berbobot + breakdown + flag review manusia |
| `Capacity/CapacityService` | Kapasitas efektif, utilisasi, supply vs demand per skill |
| `Assignment/AssignmentRecommender` | Filter kompetensi + ranking kandidat |
| `Risk/SlaRiskEngine` | Slack, latest start, projected finish berbasis antrean, alasan risiko |
| `Estimation/EstimateAdvisor` | Median & P80 historis, buang outlier (IQR) |
| `Recommendation/RecommendationEngine` | Tindakan yang disarankan (accept / dismiss) |
| `Recommendation/ImpactPreviewService` | Dry-run "what-if" sebelum override |
| `Simulation/ScenarioService` | Skenario before/after untuk demo |

**Alur recompute:** setiap perubahan request / assignment / kapasitas / dependensi →
`RecomputeMetrics` → `PriorityEngine` → `SlaRiskEngine` → `RecommendationEngine`. Hasil (skor, risiko,
alasan) disimpan ter-denormalisasi di `service_requests` agar dashboard cepat dan alasan dapat diaudit.
Scheduler: `servops:recompute` tiap 15 menit, `servops:snapshot` harian.

Struktur folder target dan ERD lengkap: BLUEPRINT §2–§3.

---

## 7. Konsep Domain

### Workflow status

```
new → triage → ready → assigned → in_progress → delivered → accepted
        ↕                             ↕              ↓
 needs_clarification               blocked        returned → in_progress
```

- **Hanya `accepted` dihitung selesai.** `delivered` masih menunggu acceptance pemohon, sehingga pekerjaan
  yang dikembalikan tidak pernah tercatat selesai.
- `cancelled` dapat dicapai dari status mana pun kecuali `accepted` (alasan wajib).
- Guard contoh: `triage → ready` ditolak jika estimasi atau skill kosong; `ready → assigned` ditolak jika
  level skill assignee < syarat (kecuali override pairing). Daftar lengkap: BLUEPRINT §4.3.

### Skor prioritas

| Faktor | Bobot |
|---|---|
| Impact (dampak, jumlah departemen, kritikalitas proses) | 0.35 |
| Urgency (sisa effort ÷ jam kerja sampai tenggat) | 0.25 |
| Dependency (berapa request menunggu request ini) | 0.15 |
| Requester priority | 0.10 |
| Scarce skill (sedikit orang qualified) | 0.10 |
| Age | 0.05 |

Prioritas dari pemohon hanya sinyal berbobot kecil. Impact `unknown` dinilai sebagai medium (bukan rendah)
dan memicu review manusia.

### Level risiko

| Level | Kondisi |
|---|---|
| 🟢 `on_track` | projected finish ≤ deadline − buffer |
| 🟡 `at_risk` | projected finish dalam buffer, atau ada trigger tambahan |
| 🔴 `will_breach` | projected finish > deadline |
| ⚫ `breached` | sudah lewat deadline dan belum `accepted` |

Projected finish dihitung dari **antrean per orang**, bukan hanya slack, sehingga risiko terlihat sebelum
status berubah.

### Kapasitas

```
effective_daily = daily_hours × focus_factor − rutin − rapat − cuti/sakit
utilization     = committed ÷ Σ effective_daily (horizon 5 hari kerja)
```

Overload > 1.0, idle < 0.6. Skill dengan demand ÷ supply > 1.0 ditandai bottleneck.

---

## 8. Halaman & Route

Saat ini hanya `GET /` (halaman welcome bawaan Laravel). Target:

| Route | Fungsi |
|---|---|
| `GET /` | Dashboard: KPI, needs attention, utilisasi tim, bottleneck skill, penyebab keterlambatan |
| `GET /requests` | Daftar request urut prioritas, filter status/risk/skill |
| `GET /requests/create` | Form request baru |
| `GET /requests/{code}` | Detail: breakdown skor, risiko, kandidat assignee, dependensi, histori |
| `POST /requests/{code}/{transition,assign,estimate,override,dependencies}` | Aksi request |
| `GET /team` | Heatmap kapasitas & skill matrix |
| `POST /team/{member}/capacity` | Input cuti/sakit/rapat |
| `GET /risk` | Monitor SLA |
| `GET /recommendations` | Accept / dismiss rekomendasi |
| `GET /simulation` | Jalankan skenario, geser clock, reset data |

**Login & RBAC.** Semua halaman butuh login (`/login`, rate limit 5 percobaan). Akun demo (password `password`):

| Peran | Akun | Akses |
|---|---|---|
| Manager | `sari@servops.test` | Semua, termasuk override prioritas, acceptance, simulasi |
| Team Lead | `rudi@servops.test` | Triage, assign/reassign, kerjakan request, estimasi, dependensi, putuskan rekomendasi, kelola kapasitas — **tanpa** override & acceptance |
| Anggota | `andi@` `budi@` `citra@` `dewi@` `eko@servops.test` | Lihat semua; kerjakan **request miliknya**; catat ketidakhadiran diri sendiri; buat request |
| Pemohon | `ops@` `sales@` `finance@` `mgt@servops.test` | Buat request; lihat request **departemennya**; terima/kembalikan hasil departemennya |

Matriks: `ActorRole::permissions()` (`app/Enums`), otorisasi per request: `ServiceRequestPolicy` (permission + kepemilikan). Setiap aksi dicek di server; kontrol yang tidak diizinkan disembunyikan di UI. Audit log mencatat user yang login.

---

## 9. Data Seed & Skenario Demo

**Tim:** Andi (Senior Data Analyst), Budi (Ops Engineer), Citra (Automation Engineer — satu-satunya
automation level 3), Dewi (Junior Analyst — kapasitas kosong, data_analysis level 1), Eko (Business Analyst).

**Request awal SR-101..107** dirancang untuk memicu kasus tertentu. Kriteria penerimaan:

1. SR-102 (incident, dampak tinggi) rank #1 meski prioritas pemohon "medium".
2. SR-104 tampil sebagai rework, bebannya masuk kapasitas, tidak terhitung selesai.
3. SR-105 (blocked oleh Tim Finance) `at_risk` + rekomendasi eskalasi.
4. SR-106 ditolak pindah ke `ready` (estimasi kosong) + rekomendasi klarifikasi scope.
5. SR-107 (impact unknown) butuh review manusia, tidak diperlakukan sebagai rendah.
6. SR-103 memicu `SCARCE_SKILL`; automation muncul sebagai bottleneck.
7. Assign SR-101 ke Dewi berhasil; assign SR-107 ke Dewi ditolak tanpa pairing.

**Skenario simulasi** (halaman `/simulation`):

| # | Skenario | Wajib? |
|---|---|---|
| S1 | Request prioritas rendah ternyata berdampak luas | ✅ |
| S2 | Anggota kritis tiba-tiba sakit | ✅ |
| S3 | Pekerjaan dikembalikan setelah delivered | ✅ |
| S4 | Menunggu data eksternal, tenggat mendekat | ✅ |
| S5 | Insert request urgent tanpa tambah kapasitas | bonus |
| S6 | Estimasi membengkak | bonus |

Detail data dan hasil yang diharapkan: BLUEPRINT §14–§15.

---

## 10. Testing

```bash
composer test          # atau: php artisan test
```

- DB test: SQLite in-memory (lihat `phpunit.xml`).
- Gunakan `SERVOPS_NOW` agar hasil deterministik.
- Rencana: unit test tiap engine di `tests/Unit/Services`, feature test workflow/rework/skenario di
  `tests/Feature`. Data SR-101..107 dipakai sebagai acceptance test.

---

## 11. Perintah Berguna

| Perintah | Fungsi |
|---|---|
| `composer run dev` | Server + Vite |
| `php artisan migrate:fresh --seed` | Reset database ke data demo |
| `php artisan test` | Jalankan test |
| `vendor/bin/pint` | Format kode |
| `php artisan servops:recompute` | Hitung ulang metrik *(rencana)* |
| `php artisan servops:snapshot` | Simpan snapshot KPI harian *(rencana)* |

---

## 12. Keputusan Desain

| Keputusan | Alasan | Konsekuensi |
|---|---|---|
| Skor berbobot transparan, bukan ML | Dapat dijelaskan & diaudit | Bobot perlu dikalibrasi manual |
| `delivered` terpisah dari `accepted` | Rework tidak hilang dari metrik | Pemohon harus melakukan acceptance |
| SLA clock tetap jalan saat blocked | Risiko terlihat lebih awal | Diimbangi metrik *blocked hours by party* |
| Under-skilled tidak pernah di-assign otomatis | Kualitas terjaga | Kapasitas idle ditangani via rekomendasi pairing |
| Median/P80, bukan rata-rata | Tahan outlier | Butuh data historis cukup (fallback bila n < 5) |
| Recompute seluruh backlog | Sederhana & konsisten | Tidak skalabel untuk ribuan request |
| Login + RBAC berbasis permission + policy kepemilikan | Kontrol akses nyata; audit mencatat user | Akun demo ditampilkan di halaman login (matikan via `SERVOPS_DEMO_ACCOUNTS=false`) |

Open questions untuk Business Analysis: BLUEPRINT §19.

---

## 13. Catatan & Masalah Diketahui

- **Timezone belum aktif.** `config/app.php` masih `'timezone' => 'UTC'` (hardcode), sehingga
  `APP_TIMEZONE=Asia/Jakarta` di `.env` diabaikan. Ganti menjadi `env('APP_TIMEZONE', 'UTC')` — penting
  karena perhitungan jam kerja dan SLA bergantung pada zona waktu.
- **Chart.js belum terpasang** di `package.json`.
- `config/servops.php` belum dibuat.
