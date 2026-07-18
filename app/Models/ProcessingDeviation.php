<?php

namespace App\Models;

use App\Enums\ProcessingDeviationSeverity;
use App\Enums\ProcessingDeviationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingDeviation extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_batch_id',
        'category',
        'severity',
        'description',
        'corrective_action',
        'status',
        'detected_at',
        'resolved_at',
        'resolved_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'severity' => ProcessingDeviationSeverity::class,
            'status' => ProcessingDeviationStatus::class,
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'processing_batch_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
