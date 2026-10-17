<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['date', 'metrics'])]
class KpiSnapshot extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'metrics' => 'array',
        ];
    }
}
