<?php

namespace App\Models;

use App\Enums\EstimateConfidence;
use App\Enums\ImpactLevel;
use App\Enums\Permission;
use App\Enums\RequesterPriority;
use App\Enums\RequestStatus;
use App\Enums\ReworkCause;
use App\Enums\RiskLevel;
use Database\Factories\ServiceRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'title', 'description', 'category',
    'requester_department_id', 'business_process_id',
    'requester_priority', 'impact_level', 'affected_users', 'affected_departments',
    'required_skill_id', 'required_level',
    'estimate_hours', 'estimate_confidence', 'remaining_hours', 'actual_hours',
    'deadline_at', 'status', 'assignee_id', 'pairing_member_id',
    'parent_request_id', 'rework_count', 'rework_cause',
    'blocked_since', 'wait_hours_total', 'started_at', 'delivered_at', 'accepted_at',
    'priority_score', 'priority_breakdown', 'priority_rank',
    'needs_human_review', 'review_reasons',
    'risk_level', 'risk_reasons', 'slack_hours', 'latest_start_at', 'projected_finish_at',
    'metrics_computed_at',
])]
class ServiceRequest extends Model
{
    /** @use HasFactory<ServiceRequestFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requester_priority' => RequesterPriority::class,
            'impact_level' => ImpactLevel::class,
            'estimate_confidence' => EstimateConfidence::class,
            'status' => RequestStatus::class,
            'rework_cause' => ReworkCause::class,
            'risk_level' => RiskLevel::class,
            'affected_users' => 'integer',
            'affected_departments' => 'integer',
            'required_level' => 'integer',
            'estimate_hours' => 'float',
            'remaining_hours' => 'float',
            'actual_hours' => 'float',
            'wait_hours_total' => 'float',
            'rework_count' => 'integer',
            'priority_score' => 'float',
            'priority_rank' => 'integer',
            'slack_hours' => 'float',
            'priority_breakdown' => 'array',
            'review_reasons' => 'array',
            'risk_reasons' => 'array',
            'needs_human_review' => 'boolean',
            'deadline_at' => 'datetime',
            'blocked_since' => 'datetime',
            'started_at' => 'datetime',
            'delivered_at' => 'datetime',
            'accepted_at' => 'datetime',
            'latest_start_at' => 'datetime',
            'projected_finish_at' => 'datetime',
            'metrics_computed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return BelongsTo<Department, $this> */
    public function requesterDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requester_department_id');
    }

    /** @return BelongsTo<BusinessProcess, $this> */
    public function businessProcess(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function requiredSkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'required_skill_id');
    }

    /** @return BelongsTo<TeamMember, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'assignee_id');
    }

    /** @return BelongsTo<TeamMember, $this> */
    public function pairingMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'pairing_member_id');
    }

    /**
     * Request asal jika ini adalah rework.
     *
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_request_id');
    }

    /** @return HasMany<ServiceRequest, $this> */
    public function reworks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_request_id');
    }

    /**
     * Dependensi yang ditunggu request ini.
     *
     * @return HasMany<RequestDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(RequestDependency::class);
    }

    /** @return HasMany<RequestDependency, $this> */
    public function openDependencies(): HasMany
    {
        return $this->dependencies()->whereNull('resolved_at');
    }

    /**
     * Dependensi milik request lain yang menunggu request ini selesai.
     *
     * @return HasMany<RequestDependency, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(RequestDependency::class, 'depends_on_request_id');
    }

    /** @return HasMany<StatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<EstimateRevision, $this> */
    public function estimateRevisions(): HasMany
    {
        return $this->hasMany(EstimateRevision::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<PriorityOverride, $this> */
    public function priorityOverrides(): HasMany
    {
        return $this->hasMany(PriorityOverride::class);
    }

    /** @return HasMany<TimeLog, $this> */
    public function timeLogs(): HasMany
    {
        return $this->hasMany(TimeLog::class);
    }

    /** @return HasMany<Recommendation, $this> */
    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }

    /** @param  Builder<ServiceRequest>  $query */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', RequestStatus::openStatuses());
    }

    /** @param  Builder<ServiceRequest>  $query */
    #[Scope]
    protected function countingAsLoad(Builder $query): void
    {
        $query->whereIn('status', RequestStatus::loadStatuses());
    }

    /** @param  Builder<ServiceRequest>  $query */
    #[Scope]
    protected function done(Builder $query): void
    {
        $query->where('status', RequestStatus::Accepted);
    }

    /**
     * Request yang boleh dilihat user: semua (jika berhak) atau hanya milik departemennya.
     *
     * @param  Builder<ServiceRequest>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->hasPermission(Permission::ViewAllRequests)) {
            $query->where('requester_department_id', $user->department_id ?? 0);
        }
    }

    public function isRework(): bool
    {
        return $this->rework_count > 0 || $this->parent_request_id !== null;
    }

    public function hasUnknownEstimate(): bool
    {
        return $this->estimate_hours === null;
    }

    /**
     * Pertumbuhan estimasi karena revisi scope (persen terhadap estimasi awal).
     * Revisi bertipe "rework" diabaikan — rework adalah beban tambahan, bukan scope yang membengkak.
     */
    public function scopeGrowthPct(): ?float
    {
        $revisions = $this->estimateRevisions;
        $initial = $revisions->firstWhere('kind', 'initial')?->new_hours;

        if (! $initial) {
            return null;
        }

        $growth = $revisions->where('kind', 'revision')
            ->sum(fn (EstimateRevision $revision): float => $revision->new_hours - (float) $revision->old_hours);

        return round($growth / $initial * 100, 2);
    }

    public function hasOpenDependencies(): bool
    {
        return $this->openDependencies()->exists();
    }
}
