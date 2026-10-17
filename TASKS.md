# Task Breakdown — ServOps Prototype

> Turunan dari [BLUEPRINT.md](BLUEPRINT.md). Urutan mengikuti §17. Kolom **Dep** = task yang harus selesai dulu.
> Estimasi dalam jam (kasar). Prioritas: **P0** wajib demo, **P1** penting, **P2** bonus.

---

## Fase 1 — Fondasi (± 6 jam)

| ID | Task | Dep | Est | Prio | Done when |
|---|---|---|---|---|---|
| F1.1 | `composer create-project laravel/laravel servops`, buat DB `vibecoding` | — | 0.5 | P0 | `php artisan --version` = 13.x |
| F1.2 | Set `.env` (MySQL, `Asia/Jakarta`, session/cache database, queue sync, `SERVOPS_NOW`) | F1.1 | 0.25 | P0 | `php artisan migrate` ke MySQL sukses |
| F1.3 | Install Livewire, Tailwind, Alpine, Chart.js; `composer run dev` jalan | F1.1 | 0.5 | P0 | halaman welcome render via Vite |
| F1.4 | `config/servops.php`: bobot priority, ambang util/bottleneck, horizon, buffer, jam kerja, threshold estimasi 30% | F1.1 | 0.5 | P0 | semua angka §6–§9 dibaca dari config |
| F1.5 | Enums: `RequestStatus` (+`allowedTransitions()`, `countsAsLoad()`), `RiskLevel`, `ImpactLevel`, `RequesterPriority`, `EstimateConfidence`, `DependencyType`, `CapacityEntryType`, `RecommendationType`, `ActorRole`, `ReworkCause`, `Seniority` | F1.1 | 1 | P0 | enum ter-cast di model |
| F1.6 | Migrations semua tabel §3.2 + index `service_requests` + soft deletes | F1.2 | 1.5 | P0 | `migrate:fresh` hijau |
| F1.7 | Models + relasi + casts (json, enum, datetime) untuk 16 model | F1.5, F1.6 | 1.5 | P0 | tinker: `ServiceRequest::with('assignee','dependencies')` jalan |
| F1.8 | DTO readonly: `PriorityResult`, `RiskResult`, `AssignmentCandidate`, `Reason` | F1.1 | 0.25 | P0 | — |
| F1.9 | Layout Blade (nav: Dashboard, Requests, Team, Risk, Recommendations, Simulation) + **"Acting as" selector** (session) + tampilan sim clock | F1.3 | 0.5 | P0 | role tersimpan di session |

---

## Fase 2 — Seed Data (± 4 jam)

| ID | Task | Dep | Est | Prio | Done when |
|---|---|---|---|---|---|
| S2.1 | `DepartmentSeeder`, `BusinessProcessSeeder` (termasuk proses operasional kritis, criticality 5) | F1.7 | 0.5 | P0 | — |
| S2.2 | `SkillSeeder` (6 skill) + `TeamSeeder` Andi–Eko + `member_skills` sesuai §14.1 | F1.7 | 0.5 | P0 | Citra satu-satunya automation 3; Dewi data_analysis 1 |
| S2.3 | `HolidaySeeder` (beberapa tanggal) | F1.7 | 0.25 | P1 | — |
| S2.4 | `ServiceRequestSeeder` SR-101..107 relatif `Clock::now()` (deadline = akhir jam kerja H+N) | S2.2, C3.1, C3.2 | 1 | P0 | data cocok tabel §14.2 |
| S2.5 | SR-090 (accepted→returned asal SR-104), dependensi external SR-105 (Tim Finance, ETA H+1 15:00) | S2.4 | 0.5 | P0 | — |
| S2.6 | WIP request per anggota (Andi 4j, Budi 2j, Citra 8j, Eko 3j) | S2.4 | 0.5 | P0 | — |
| S2.7 | `HistoricalSeeder` ± 40 request `accepted` per kategori + time_logs + beberapa outlier ekstrem | S2.2 | 0.75 | P0 | EstimateAdvisor punya n ≥ 5 per kategori |
| S2.8 | `DatabaseSeeder` memanggil semua + jalankan recompute di akhir | S2.1–S2.7, E4.9 | 0.25 | P0 | `migrate:fresh --seed` menghasilkan skor & risiko terisi |

---

## Fase 3 — Kalender & Workflow (± 6 jam)

| ID | Task | Dep | Est | Prio | Done when |
|---|---|---|---|---|---|
| C3.1 | `Clock::now()` (prioritas: cache `servops.sim_now` → `SERVOPS_NOW` → `now()`), `Clock::set()`, `Clock::advance(hours)` | F1.4 | 0.5 | P0 | — |
| C3.2 | `WorkingCalendar`: `isWorkingTime`, `workingHoursBetween`, `addWorkingHours`, `subWorkingHours`, `endOfWorkingDay(+N)` — 08–17, istirahat 12–13, skip weekend & holidays | C3.1 | 2 | P0 | lihat T3.3 |
| C3.3 | `WorkflowTransitionService::transition()` dalam `DB::transaction`, tulis `status_histories`, lempar `InvalidTransitionException` | F1.7 | 1 | P0 | transisi ilegal ditolak |
| C3.4 | `TransitionGuard` untuk setiap baris §4.3 + efek samping (remaining_hours, started_at, blocked_since, wait_hours_total, rework_count, delivered_at/accepted_at) | C3.3, C3.2 | 1.5 | P0 | lihat T3.4 |
| C3.5 | Cancel dari status mana pun kecuali `accepted` (alasan wajib) | C3.3 | 0.25 | P0 | — |
| C3.6 | Action service: `reviseEstimate()` (estimate_revisions, flag >30%), `assign()` (guard kompetensi / override pairing), `addDependency()` / `resolveDependency()`, `overridePriority()` | C3.3 | 1 | P0 | semua tercatat & dapat ditelusuri |

**Tests**
| ID | Test | Dep | Est |
|---|---|---|---|
| T3.3 | `WorkingCalendarTest` — lintas weekend, libur, istirahat, add/sub simetris | C3.2 | 0.5 |
| T3.4 | `WorkflowTransitionTest` — semua guard §4.3, history tertulis, SR-106 → ready ditolak | C3.4 | 1 |

---

## Fase 4 — Engine (± 14 jam)

| ID | Task | Dep | Est | Prio | Done when |
|---|---|---|---|---|---|
| E4.1 | `EstimateAdvisor`: filter IQR, median, P80, n, outlier_removed, fallback bila n < 5; akurasi actual/estimate per kategori & orang | F1.7 | 1.5 | P0 | T4.1 hijau |
| E4.2 | `CapacityService`: `effectiveDaily(m,d)`, `committed(m)`, `available(m,horizon)`, `utilization()`, `skillSupplyDemand()`; flag overload/idle/bottleneck | C3.2 | 2 | P0 | automation demand/supply > 1 |
| E4.3 | `PriorityEngine`: 6 faktor + breakdown + note teks; unknown impact = 50; estimasi null → P80; pinned override di atas; tie-breaker; hitung `priority_rank` global | E4.1, E4.2 | 2.5 | P0 | SR-102 rank #1 |
| E4.4 | Review triggers §6.3 (`IMPACT_UNKNOWN`, `ESTIMATE_UNKNOWN`, `CLOSE_CALL`, `OVERRIDE_ACTIVE`, `UNDERQUALIFIED_ASSIGNMENT`, `ESTIMATE_GROWTH`, `CROSS_DEPT_CONFLICT`) | E4.3 | 1 | P0 | SR-107 needs_human_review |
| E4.5 | `AssignmentRecommender`: filter keras → fit score 4 komponen → fallback PAIRING + NEGOTIATE/SPLIT/ADD_CAPACITY | E4.2 | 2 | P0 | SR-107→Dewi tidak lolos |
| E4.6 | `SlaRiskEngine`: hours_left, slack, latest_start, queue_ahead, projected_finish (blocked pakai ETA, unassigned pakai kandidat terbaik), level risiko + buffer | E4.3, E4.5 | 2.5 | P0 | SR-105 at_risk |
| E4.7 | Risk reasons §9.4 (9 kode) + tindakan disarankan | E4.6 | 1 | P0 | — |
| E4.8 | `RecommendationEngine`: 11 tipe §11, fingerprint unik, auto-close yang tak lagi relevan, accept → eksekusi aksi, dismiss → catat | E4.7 | 2 | P0 | — |
| E4.9 | `RecomputeMetrics` job (urutan Priority → Risk → Recommendation, seluruh backlog), tulis kolom denormalisasi + `metrics_computed_at` | E4.3, E4.6, E4.8 | 0.5 | P0 | — |
| E4.10 | Observers (ServiceRequest, CapacityEntry, RequestDependency, MemberSkill) → dispatch recompute; guard agar tidak rekursif | E4.9 | 0.5 | P0 | ubah data → skor update |
| E4.11 | Commands `servops:recompute`, `servops:snapshot` (kpi_snapshots) + daftar di scheduler | E4.9 | 0.5 | P1 | `schedule:list` tampil |
| E4.12 | `ImpactPreviewService`: dry-run in-memory (transaction + rollback) → diff before/after per request | E4.9 | 1.5 | P1 | daftar task yang jadi will_breach |

**Tests**
| ID | Test | Dep | Est |
|---|---|---|---|
| T4.1 | `EstimateAdvisorTest` | E4.1 | 0.5 |
| T4.2 | `CapacityServiceTest` | E4.2 | 0.5 |
| T4.3 | `PriorityEngineTest` — SR-102 > SR-101, unknown ≠ low, Σ contribution = score | E4.3 | 0.5 |
| T4.4 | `AssignmentRecommenderTest` | E4.5 | 0.5 |
| T4.5 | `SlaRiskEngineTest` | E4.6 | 0.5 |
| T4.6 | `AcceptanceTest` — 7 kriteria §14.3 (Clock dikunci) | S2.8 | 1 |

---

## Fase 5 — UI (± 14 jam)

| ID | Task | Dep | Est | Prio | Done when |
|---|---|---|---|---|---|
| U5.1 | Komponen Blade shared: badge status, badge risiko, reason chip, stacked bar breakdown | F1.9 | 1 | P0 | — |
| U5.2 | `RequestBoard` (`/requests`): tabel urut rank, filter status/risk/skill, kolom skor/risk/projected | U5.1, E4.9 | 2 | P0 | — |
| U5.3 | Form create request (`/requests/create`), impact boleh `unknown`, estimasi boleh kosong | U5.1 | 1 | P0 | — |
| U5.4 | `RequestDetail`: breakdown skor + kalimat, risiko & alasan, kandidat assignee, dependensi, timeline history, revisi estimasi | U5.1, E4.5 | 3 | P0 | — |
| U5.5 | Aksi di detail: transition, assign (+pairing override), revisi estimasi, add/resolve dependensi, override + ImpactPreview modal; error guard tampil sebagai pesan | U5.4, C3.6, E4.12 | 2 | P0 | — |
| U5.6 | `TeamCapacity` (`/team`): heatmap hari × orang, skill matrix, form cuti/sakit/rapat | E4.2 | 2 | P0 | — |
| U5.7 | `RiskMonitor` (`/risk`): slack, latest start, projected finish, reasons | E4.7 | 1 | P0 | — |
| U5.8 | `RecommendationPanel` (`/recommendations`): list by severity, accept/dismiss | E4.8 | 1 | P0 | — |
| U5.9 | `Dashboard` (`/`): KPI row, Needs attention, util bar, skill bottleneck, late cause, tren (Chart.js) — semua indikator §12 | E4.2, E4.7, E4.11 | 3 | P0 | — |
| U5.10 | Kanban view di RequestBoard | U5.2 | 1.5 | P2 | — |

---

## Fase 6 — Simulasi (± 7 jam)

| ID | Task | Dep | Est | Prio | Done when |
|---|---|---|---|---|---|
| X6.1 | `ScenarioService` kerangka: snapshot before → aksi → recompute → snapshot after → simpan `scenario_runs` | E4.9 | 1 | P0 | — |
| X6.2 | S3 — SR-101 delivered → returned (+3j) | X6.1 | 0.5 | P0 | selesai turun, rework naik |
| X6.3 | S2 — Budi/Citra sick 2 hari | X6.1 | 0.5 | P0 | REASSIGN / PAIRING muncul |
| X6.4 | S1 — SR-107 impact critical, 4 dept | X6.1 | 0.5 | P0 | rank naik |
| X6.5 | S4 — clock +6j, ETA Finance mundur | X6.1, C3.1 | 0.5 | P0 | SR-105 → will_breach |
| X6.6 | S5 — insert urgent + pinned override, tampilkan ImpactPreview | X6.1, E4.12 | 1 | P2 | — |
| X6.7 | S6 — SR-103 12 → 20j scope change | X6.1 | 0.5 | P2 | ESTIMATE_GROWTH |
| X6.8 | `SimulationPanel` (`/simulation`): tombol skenario, diff before/after, kontrol clock (+N jam), reset (`migrate:fresh --seed`, local only) | X6.1 | 2 | P0 | — |
| T6.1 | `ScenarioTest` S1–S4 + `ReworkFlowTest` | X6.2–X6.5 | 1 | P0 | hijau |

---

## Fase 7 — Polish & Deliverable (± 4 jam)

| ID | Task | Dep | Est | Prio |
|---|---|---|---|---|
| P7.1 | README: setup, run, akun/role, alur demo skenario | X6.8 | 1 | P0 |
| P7.2 | Screenshot tiap halaman + before/after skenario | X6.8 | 0.5 | P0 |
| P7.3 | Dokumen Business Analysis (masalah, stakeholder, open questions §19) | — | 1.5 | P0 |
| P7.4 | Dokumen Design (ringkas arsitektur, keputusan & konsekuensi §18) | — | 1 | P0 |

---

## Ringkasan

| Fase | Est (jam) |
|---|---|
| 1 Fondasi | 6 |
| 2 Seed | 4 |
| 3 Kalender & Workflow | 6 |
| 4 Engine | 14 |
| 5 UI | 14 |
| 6 Simulasi | 7 |
| 7 Polish | 4 |
| **Total** | **± 55** |

**Critical path:** F1.1 → F1.6 → F1.7 → C3.1 → C3.2 → E4.2 → E4.3 → E4.5 → E4.6 → E4.8 → E4.9 → S2.8 → X6.1 → X6.8 → P7.1

**Catatan urutan:** seed SR-101..107 (S2.4) butuh `Clock` + `WorkingCalendar` (C3.1–C3.2), jadi kerjakan C3.1–C3.2 sebelum S2.4 walau beda fase. S2.8 (recompute saat seed) menunggu E4.9.

**Jika waktu mepet, potong:** U5.10, X6.6, X6.7, E4.11 (scheduler), `CROSS_DEPT_CONFLICT`, heatmap → tabel biasa.
