<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PriorityOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_request_id', 'old_rank', 'forced_rank', 'pinned',
    'reason', 'approved_by', 'expires_at', 'revoked_at',
])]
class PriorityOverride extends Model
{
    /** @use HasFactory<PriorityOverrideFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_rank' => 'integer',
            'forced_rank' => 'integer',
            'pinned' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /** @param  Builder<PriorityOverride>  $query */
    #[Scope]
    protected function activeAt(Builder $query, CarbonInterface $at): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $at));
    }
}
