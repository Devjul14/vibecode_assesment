<?php

namespace App\Models;

use App\Enums\ActorRole;
use Database\Factories\EstimateRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_request_id', 'old_hours', 'new_hours', 'delta_pct', 'kind',
    'scope_change', 'reason', 'actor_role', 'actor_name', 'created_at',
])]
class EstimateRevision extends Model
{
    /** @use HasFactory<EstimateRevisionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_hours' => 'float',
            'new_hours' => 'float',
            'delta_pct' => 'float',
            'scope_change' => 'boolean',
            'actor_role' => ActorRole::class,
        ];
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }
}
