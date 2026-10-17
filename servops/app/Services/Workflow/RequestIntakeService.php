<?php

namespace App\Services\Workflow;

use App\Data\Actor;
use App\Enums\ActorRole;
use App\Enums\EstimateConfidence;
use App\Enums\ImpactLevel;
use App\Enums\RequesterPriority;
use App\Enums\RequestStatus;
use App\Models\EstimateRevision;
use App\Models\ServiceRequest;
use App\Services\Calendar\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Pencatatan request baru: kode otomatis, riwayat "Baru", estimasi awal (jika ada), lalu masuk Triage.
 */
class RequestIntakeService
{
    public function __construct(
        private Clock $clock,
        private HistoryRecorder $history,
        private WorkflowTransitionService $workflow,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, category: string, requester_department_id?: int|null, business_process_id?: int|null, requester_priority: string, impact_level?: string|null, affected_users?: int|null, affected_departments?: int|null, required_skill_id?: int|null, required_level?: int|null, estimate_hours?: float|null, estimate_confidence?: string|null, deadline_at: mixed}  $data
     */
    public function create(array $data, Actor $actor): ServiceRequest
    {
        $request = DB::transaction(function () use ($data, $actor): ServiceRequest {
            $estimate = isset($data['estimate_hours']) && $data['estimate_hours'] !== '' ? (float) $data['estimate_hours'] : null;

            $request = ServiceRequest::create([
                'code' => $this->nextCode(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'],
                'requester_department_id' => $data['requester_department_id'] ?? null,
                'business_process_id' => $data['business_process_id'] ?? null,
                'requester_priority' => RequesterPriority::from($data['requester_priority']),
                'impact_level' => ImpactLevel::from($data['impact_level'] ?? ImpactLevel::Unknown->value),
                'affected_users' => $data['affected_users'] ?? null,
                'affected_departments' => $data['affected_departments'] ?? null,
                'required_skill_id' => $data['required_skill_id'] ?? null,
                'required_level' => $data['required_level'] ?? 2,
                'estimate_hours' => $estimate,
                'estimate_confidence' => EstimateConfidence::from($data['estimate_confidence'] ?? EstimateConfidence::Medium->value),
                'remaining_hours' => $estimate,
                'deadline_at' => $data['deadline_at'],
                'status' => RequestStatus::New,
            ]);

            $request->forceFill(['created_at' => $this->clock->now()])->save();
            $this->history->record($request, null, RequestStatus::New, $actor, 'Request dicatat');

            if ($estimate !== null) {
                EstimateRevision::create([
                    'service_request_id' => $request->id,
                    'new_hours' => $estimate,
                    'kind' => 'initial',
                    'reason' => 'Estimasi awal saat pencatatan.',
                    'actor_role' => $actor->role,
                    'actor_name' => $actor->name,
                    'created_at' => $this->clock->now(),
                ]);
            }

            return $request;
        });

        return $this->workflow->transition($request, RequestStatus::Triage, new Actor(ActorRole::System, 'Sistem'), 'Masuk antrean triage')->request;
    }

    private function nextCode(): string
    {
        $max = ServiceRequest::withTrashed()
            ->where('code', 'like', 'SR-%')
            ->lockForUpdate()
            ->pluck('code')
            ->map(fn (string $code): int => (int) substr($code, 3))
            ->max() ?? 0;

        return sprintf('SR-%03d', $max + 1);
    }
}
