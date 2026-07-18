<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldDailyReportDivision extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_daily_report_id',
        'division_code',
        'division_name',
        'total_records',
        'draft_records',
        'submitted_records',
        'revision_records',
        'verified_records',
        'completion_status',
        'last_activity_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'total_records' => 'integer',
            'draft_records' => 'integer',
            'submitted_records' => 'integer',
            'revision_records' => 'integer',
            'verified_records' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(FieldDailyReport::class, 'field_daily_report_id');
    }
}
