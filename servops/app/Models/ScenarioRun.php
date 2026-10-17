<?php

namespace App\Models;

use App\Enums\Scenario;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['scenario', 'params', 'before', 'after', 'created_at'])]
class ScenarioRun extends Model
{
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function scenarioType(): ?Scenario
    {
        return Scenario::tryFrom($this->scenario);
    }
}
