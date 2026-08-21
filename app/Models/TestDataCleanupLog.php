<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestDataCleanupLog extends Model
{
    protected $fillable = [
        'sppg_unit_id', 'actor_id', 'actor_name_snapshot', 'record_type', 'record_label',
        'source_table', 'source_id', 'source_number', 'reason', 'record_snapshot',
        'deleted_counts', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'deleted_counts' => 'array',
            'deleted_at' => 'datetime',
        ];
    }
}
