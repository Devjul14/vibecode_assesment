<?php

namespace App\Models;

use App\Enums\ActorRole;
use App\Enums\RequestStatus;
use Database\Factories\StatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_request_id', 'from_status', 'to_status',
    'actor_role', 'actor_name', 'reason', 'meta', 'created_at',
])]
class StatusHistory extends Model
{
    /** @use HasFactory<StatusHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => RequestStatus::class,
            'to_status' => RequestStatus::class,
            'actor_role' => ActorRole::class,
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }
}
