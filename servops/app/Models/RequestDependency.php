<?php

namespace App\Models;

use App\Enums\DependencyType;
use Database\Factories\RequestDependencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_request_id', 'depends_on_request_id', 'type',
    'external_party', 'description', 'expected_at', 'resolved_at',
])]
class RequestDependency extends Model
{
    /** @use HasFactory<RequestDependencyFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DependencyType::class,
            'expected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'depends_on_request_id');
    }

    /** @param  Builder<RequestDependency>  $query */
    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function isExternal(): bool
    {
        return $this->type === DependencyType::External;
    }
}
