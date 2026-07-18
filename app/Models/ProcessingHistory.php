<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_batch_id',
        'actor_id',
        'action',
        'from_state',
        'to_state',
        'from_status',
        'to_status',
        'notes',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'processing_batch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
