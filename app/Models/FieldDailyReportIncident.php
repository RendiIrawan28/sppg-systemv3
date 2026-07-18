<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldDailyReportIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_daily_report_id',
        'source_type',
        'source_id',
        'division_code',
        'category',
        'severity',
        'status',
        'title',
        'description',
        'action_or_resolution',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(FieldDailyReport::class, 'field_daily_report_id');
    }
}
