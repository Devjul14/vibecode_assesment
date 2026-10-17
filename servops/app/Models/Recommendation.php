<?php

namespace App\Models;

use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use Database\Factories\RecommendationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_request_id', 'team_member_id', 'type', 'severity', 'title',
    'rationale', 'payload', 'status', 'decided_by', 'decided_at', 'fingerprint',
])]
class Recommendation extends Model
{
    /** @use HasFactory<RecommendationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => RecommendationType::class,
            'status' => RecommendationStatus::class,
            'severity' => 'integer',
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /** @return BelongsTo<TeamMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'team_member_id');
    }

    /** @param  Builder<Recommendation>  $query */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', RecommendationStatus::Open);
    }
}
