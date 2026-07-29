<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerCollectionItem extends Model
{
    protected $fillable = [
        'container_collection_run_id', 'container_collection_task_id', 'collected_quantity',
        'status', 'collected_by', 'collected_at', 'photo_path', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'collected_quantity' => 'integer',
            'collected_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContainerCollectionRun::class, 'container_collection_run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContainerCollectionTask::class, 'container_collection_task_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
