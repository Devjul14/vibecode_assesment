<?php

namespace App\Models;

use App\Enums\TimeLogKind;
use Database\Factories\TimeLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_request_id', 'team_member_id', 'started_at', 'ended_at', 'hours', 'kind'])]
class TimeLog extends Model
{
    /** @use HasFactory<TimeLogFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TimeLogKind::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'hours' => 'float',
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
}
