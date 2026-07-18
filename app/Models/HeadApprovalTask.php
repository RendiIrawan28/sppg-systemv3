<?php

namespace App\Models;

use App\Enums\HeadApprovalTaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeadApprovalTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id', 'source_type', 'source_id', 'module_code', 'module_label',
        'document_number', 'document_title', 'document_date', 'source_status',
        'task_status', 'submitted_by', 'submitted_by_name_snapshot', 'submitted_at',
        'processed_by', 'processed_at', 'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'task_status' => HeadApprovalTaskStatus::class,
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isPending(): bool
    {
        return $this->task_status === HeadApprovalTaskStatus::Pending;
    }
}
