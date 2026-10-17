# A. Business Analysis — Intelligent Service Operations & Capacity Planning

> Deliverable A. Dokumen ini menjawab **Tantangan 1 (Business Problem Discovery)** dan memberi landasan bisnis
> untuk keputusan desain di [B — System Design](B-system-design.md). Setiap temuan dikaitkan dengan bagaimana
> prototipe ServOps menanganinya, sehingga analisis dapat diverifikasi langsung di aplikasi.

---

## 1. Ringkasan eksekutif

Manajemen tidak kekurangan data pekerjaan — yang kurang adalah **satu sumber kebenaran yang menghubungkan
pekerjaan, kapasitas, kompetensi, dan waktu**. Akibatnya prioritas ditentukan oleh siapa yang paling keras meminta,
beban kerja tidak merata, dan keterlambatan baru terlihat setelah terjadi.

Prototipe ServOps menjawab empat pertanyaan manajemen secara eksplisit dan dapat dijelaskan:

| Pertanyaan | Jawaban sistem |
|---|---|
| **Apa** yang dikerjakan dulu? | Skor prioritas berbobot dengan rincian per faktor; prioritas pemohon hanya satu sinyal berbobot 10% |
| **Siapa** yang mengerjakan? | Kandidat disaring berdasarkan kompetensi & ketersediaan, lalu diranking; anggota di bawah syarat hanya opsi *pairing* |
| **Kapan** berisiko telat? | Proyeksi selesai berbasis antrean orang yang mengerjakan — risiko terlihat **sebelum** tenggat lewat |
| **Tindakan** apa yang diambil? | Rekomendasi (tugaskan, pindahkan, eskalasi, klarifikasi, negosiasi, cross-training) — keputusan tetap di manusia |

---

## 2. Rumusan masalah

> Perusahaan tidak dapat memutuskan secara konsisten dan dapat dipertanggungjawabkan **pekerjaan mana yang
> didahulukan, siapa yang mengerjakannya, dan kapan harus bertindak sebelum terlambat**, karena informasi tentang
> dampak bisnis, kapasitas efektif, kompetensi, ketergantungan, dan perubahan scope tidak tercatat secara terstruktur
> dan tidak saling terhubung.

---

## 3. Akar penyebab

| Gejala yang dilaporkan | Akar penyebab | Jawaban di ServOps |
|---|---|---|
| Tidak ada gambaran kapasitas menyeluruh | Data tersebar (spreadsheet, chat, aplikasi lain); rapat, rutin, dan WIP tidak dicatat sebagai pengurang kapasitas | Kapasitas efektif per orang per hari = jam kerja × fokus − rutin − rapat − cuti/sakit; halaman Tim & Kapasitas |
| "Mendesak" menurut pemohon ≠ penting bagi perusahaan | Prioritas ditetapkan sepihak; tidak ada kriteria dampak | Faktor **Dampak bisnis (35%)** dari tingkat dampak, departemen terdampak, dan kritikalitas proses |
| Sebagian orang kelebihan beban, lainnya kosong | Penugasan berbasis kebiasaan; tidak ada matriks kompetensi & data beban | Matriks kompetensi, utilisasi per orang, rekomendasi *rebalance* |
| Terlambat padahal dashboard "normal" | Status diisi manual dan statis; waktu tunggu tercampur dengan waktu kerja | Risiko dihitung dari **proyeksi antrean**, bukan status; waktu tunggu dicatat terpisah |
| Dependensi tidak tercatat | Model data tidak punya relasi antar-pekerjaan | Dependensi internal/eksternal dengan ETA; guard "tidak bisa mulai bila dependensi terbuka" |
| Estimasi sering berubah | Pekerjaan dimulai sebelum scope jelas | Request tanpa estimasi **tidak bisa** masuk "Siap dikerjakan"; revisi estimasi wajib alasan & tercatat |
| Revisi tidak dihitung sebagai beban | Status "Selesai" final; tidak ada status dikembalikan | `Diserahkan` ≠ `Diterima`; status **Dikembalikan (Rework)** menambah beban & tercatat di rework rate |
| Manajemen sulit memutuskan | Dashboard hanya menghitung jumlah | Dashboard menjawab **mengapa** (penyebab risiko) dan **apa** (rekomendasi) dengan definisi indikator yang eksplisit |

---

## 4. Analisis data awal (SR-101 – SR-107)

Data awal sengaja berisi "jebakan": label pemohon, estimasi, dan tenggat **saja** memberi urutan yang salah.

| ID | Yang terlihat | Yang sebenarnya penting | Perlakuan yang benar | Hasil di prototipe |
|---|---|---|---|---|
| SR-101 | Prioritas **Tinggi** | Dampak sedang, slack cukup | Tugaskan normal; jangan habiskan expert | Peringkat #8, direkomendasikan ke **Dewi** (level pas), Andi tetap bebas |
| SR-102 | Prioritas **Sedang** | Gangguan ke 40 pengguna, 3 departemen, proses Order-to-cash, tenggat hari ini | Prioritas tertinggi | **Peringkat #1**, rekomendasi kritis "Tugaskan ke Budi" |
| SR-103 | Prioritas **Rendah**, tenggat 5 hari | 12 jam, hanya Citra yang kompeten dan sedang sibuk | Mulai lebih awal | Ditandai **skill langka** (beban Citra 68%), rekomendasi tugaskan segera |
| SR-104 | Perbaikan | Hasil sebelumnya dikembalikan | Hitung sebagai beban tambahan; tautkan ke riwayat | Status *Rework*, 4 jam sisa effort masuk beban, tidak dihitung selesai |
| SR-105 | Prioritas **Tinggi** | Menunggu data Tim Finance; ETA mepet batas mulai | Pisahkan waktu tunggu; eskalasi sekarang | **Berisiko**, rekomendasi eskalasi ke Tim Finance |
| SR-106 | Estimasi **belum diketahui** | Kebutuhan belum jelas | Jangan dijadwalkan; klarifikasi ber-timebox | Ditolak masuk "Siap dikerjakan"; rekomendasi klarifikasi scope |
| SR-107 | Prioritas **Rendah** | Dampak **belum diidentifikasi** | Triage dulu — jangan diasumsikan rendah | Dampak dinilai setara "Sedang" + wajib review manusia + rekomendasi triage |

> Seluruh perlakuan di atas diverifikasi otomatis oleh `BlueprintAcceptanceTest` (7 kriteria) pada data seed.

---

## 5. Pemangku kepentingan & kebutuhan

| Peran | Kebutuhan utama | Yang disediakan |
|---|---|---|
| **Manajemen** | Memutuskan prioritas, realokasi, atau tambah kapasitas; melihat harga sebuah keputusan | Dashboard keputusan, override prioritas dengan **preview dampak**, simulasi skenario |
| **Team Lead** | Triage, menugaskan orang yang tepat, menjaga SLA | Antrean berperingkat, kandidat penugasan beralasan, monitor risiko, rekomendasi |
| **Anggota tim** | Tahu apa yang dikerjakan, melaporkan hambatan & perubahan scope | Mengerjakan request miliknya, mencatat blokir/dependensi, revisi estimasi, mencatat ketidakhadiran |
| **Pemohon** | Mengajukan kebutuhan, memantau, menerima/mengembalikan hasil | Form request (dampak & estimasi boleh "belum diketahui"), melihat request departemennya, acceptance |

---

## 6. Informasi yang harus dikumpulkan sebelum sistem boleh merekomendasikan

Rekomendasi prioritas dan penugasan hanya **bertanggung jawab** bila data berikut tersedia. Bila tidak, sistem
**menandai untuk review manusia** alih-alih menebak diam-diam.

| Informasi | Mengapa diperlukan | Bila belum ada |
|---|---|---|
| Tingkat dampak, departemen & pengguna terdampak, proses bisnis | Dasar faktor dampak (35% skor) | Dinilai "Sedang" (bukan rendah) + review `IMPACT_UNKNOWN` + rekomendasi triage |
| Estimasi effort & tingkat keyakinan | Tekanan waktu, proyeksi selesai, kapasitas | Dipakai **P80 historis kategori** + review `ESTIMATE_UNKNOWN`; tidak bisa masuk "Siap" |
| Kompetensi & level minimum yang dibutuhkan | Penyaringan kandidat | Tidak bisa masuk "Siap" (`SKILL_REQUIRED`) |
| Tenggat yang disepakati | Slack & risiko | Wajib saat pencatatan |
| Kapasitas efektif per orang (rapat, rutin, cuti) | Kapan seseorang benar-benar bisa mengerjakan | Default per profil anggota; cuti/sakit dicatat di halaman Tim |
| Dependensi & ETA | Kapan pekerjaan bisa dimulai | Dependensi tanpa ETA memicu risiko `DEPENDENCY_NO_ETA` |
| Durasi aktual historis | Kalibrasi estimasi | Median/P80 setelah outlier dibuang; fallback default bila sampel < 5 |

---

## 7. Pertanyaan klarifikasi & asumsi

Pertanyaan berikut perlu dijawab pemilik proses. Prototipe memakai asumsi di kolom tengah dan **semua asumsi dapat
diubah lewat konfigurasi** (`config/servops.php`).

| # | Pertanyaan | Asumsi prototipe | Konsekuensi bila asumsi salah |
|---|---|---|---|
| 1 | Siapa yang berwenang meng-override prioritas? Perlu persetujuan berjenjang? | Hanya **Manager**, wajib alasan, tercatat, bisa dicabut | Bila Team Lead juga boleh: ubah matriks RBAC |
| 2 | Apakah jam SLA berhenti saat menunggu pihak lain? | **Tidak berhenti** (konservatif); waktu tunggu dicatat terpisah per pihak | Breach bisa "menyalahkan" tim — dimitigasi metrik hambatan per pihak |
| 3 | Apakah ada SLA per kategori, atau tenggat selalu dari pemohon? | Tenggat dari pemohon | Perlu tabel SLA per kategori |
| 4 | Jam & hari kerja? Hari libur? | 08:00–17:00, istirahat 12–13, Senin–Jumat, tabel hari libur | Semua perhitungan jam kerja ikut berubah (terpusat di `WorkingCalendar`) |
| 5 | Berapa jam efektif seseorang per hari? | Fokus 75% dikurangi rapat & rutin per profil (± 4–5 jam) | Proyeksi terlalu optimis/pesimis |
| 6 | Bagaimana level kompetensi divalidasi? | Level 1–3 diisi Team Lead; ≥ 2 = mandiri | Penugasan bisa salah sasaran |
| 7 | Bolehkan anggota di bawah syarat mengerjakan dengan mentor? | Boleh, **hanya** dengan mentor kompeten + alasan; tidak pernah otomatis | — |
| 8 | Batas WIP per orang — keras atau peringatan? | Peringatan (soft), default 3 | — |
| 9 | Siapa yang menerima hasil? Auto-accept setelah N hari? | Pemohon (departemennya) atau Manager; **tanpa** auto-accept | Request bisa lama di "Menunggu acceptance" — terlihat di KPI |
| 10 | Rework karena kualitas vs perubahan kebutuhan perlu dibedakan? | Ya — penyebab rework wajib dipilih | — |
| 11 | Apakah revisi rework dihitung sebagai "scope membengkak"? | **Tidak** — dicatat bertipe `rework`, terpisah dari pertumbuhan scope | — |
| 12 | Seberapa banyak & konsisten data historis durasi? | 48 request simulasi dengan outlier | Fallback ke estimasi manual bila sampel kurang |

---

## 8. Definisi keberhasilan

Keberhasilan diukur dengan indikator yang **didefinisikan eksplisit**. Kolom terakhir menyebut di mana indikator
tersedia di prototipe; dua indikator butuh data berjalan (riwayat risiko harian) yang belum direkam.

| Tujuan | Indikator | Definisi | Target (usulan) | Di prototipe |
|---|---|---|---|---|
| Keterlambatan terdeteksi lebih awal | Lead time peringatan | Jam kerja antara pertama kali `Berisiko` dan tenggat | ≥ 1 hari kerja untuk ≥ 80% request yang akhirnya telat | Belum — perlu snapshot risiko harian |
| Lebih banyak tepat waktu | Tepat waktu | Request diterima dalam 90 hari yang diserahkan ≤ tenggat | Naik dari baseline (data seed: **73%**) | Dashboard KPI |
| Beban merata | Utilisasi per anggota | Sisa effort ditugaskan ÷ kapasitas efektif 5 hari | Tidak ada anggota > 110% saat ada yang < 60% & kompeten | Dashboard & Tim; rekomendasi *rebalance* (variansi belum dihitung sebagai satu angka) |
| Rework terlihat & menurun | Rework rate | Request pernah dikembalikan ÷ pernah diserahkan | Tercatat 100%; turun dari baseline (**14%**) | Dashboard KPI |
| Waktu tunggu terlihat | Flow efficiency | Jam kerja aktif ÷ lead time | Naik dari baseline (**35%**) | Dashboard KPI; jam tunggu per request di Risiko SLA |
| Data cukup sebelum dijadwalkan | Kelengkapan triage | Request berstatus Siap yang punya estimasi & kompetensi | 100% | Ditegakkan guard workflow |
| Rekomendasi dapat dipercaya | Alasan tersedia | Skor & rekomendasi dengan alasan terbaca manusia | 100% | Rincian skor & alasan di setiap request/rekomendasi |
| Keputusan dapat diaudit | Jejak audit | Perubahan status, estimasi, penugasan, override dengan pelaku & alasan | 100% | Timeline riwayat di detail request |

---

## 9. Skenario perubahan dari soal & cara penanganannya

| Skenario | Penanganan | Bukti |
|---|---|---|
| Prioritas rendah ternyata berdampak ke proses penting | Dampak "belum diketahui" tidak dianggap rendah; form triage dampak; skor dihitung ulang | Simulasi **S1**, `SlaRiskEngineTest`, `PriorityEngineTest` |
| Anggota yang ditugaskan untuk pekerjaan kritis tiba-tiba tidak tersedia | Cuti/sakit langsung memicu `ASSIGNEE_UNAVAILABLE` + rekomendasi pindahkan ke anggota kompeten; bila tidak ada → pairing / negosiasi tenggat | Simulasi **S2**, `RecommendationTest` |
| Estimasi meningkat setelah analisis | Revisi wajib alasan; > 30% karena scope → review manusia + risiko | Simulasi **S6** |
| Dikembalikan tetapi dashboard menghitung selesai | `Diserahkan` bukan selesai; hanya `Diterima` yang dihitung | Simulasi **S3**, `RequestStatusTest` |
| Menunggu data departemen lain, tenggat mendekat | Dependensi dengan ETA; ETA melewati batas mulai → `EXTERNAL_ETA_LATE` + eskalasi | Simulasi **S4** |
| Manajemen meminta pekerjaan baru diprioritaskan tanpa tambah kapasitas | Override khusus Manager dengan **preview dampak** (pekerjaan apa yang tergeser) | Simulasi **S5**, `ImpactPreviewTest` |
| Kapasitas kosong tetapi kompetensi belum memenuhi | Tidak pernah direkomendasikan otomatis; hanya opsi pairing dengan mentor | `AssignmentRecommenderTest` (Dewi & SR-107) |
| Rata-rata historis menyesatkan karena durasi ekstrem | Median & P80 setelah outlier dibuang (IQR); rata-rata mentah ditampilkan sebagai pembanding | Dashboard "Akurasi estimasi", `EstimateAdvisorTest` |

---

## 10. Batasan analisis

- Data tim dan historis adalah **simulasi**; bobot skor dan ambang perlu dikalibrasi dengan data nyata.
- Model antrean mengasumsikan tim mengikuti urutan prioritas sistem (pekerjaan berprioritas rendah yang sedang
  berjalan dapat disela).
- Konflik kepentingan antar-departemen (`CROSS_DEPT_CONFLICT`) diidentifikasi sebagai pemicu review di desain,
  tetapi belum diimplementasikan.
