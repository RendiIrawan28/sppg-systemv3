<?php

namespace App\Models;

use App\Enums\SecuritySituation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SecurityReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'security_shift_id',
        'sppg_unit_id',
        'sequence_number',
        'due_at',
        'reported_at',
        'situation',
        'gate_secure',
        'perimeter_secure',
        'access_activity',
        'visitor_activity',
        'notes',
        'photo_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'due_at' => 'datetime',
            'reported_at' => 'datetime',
            'situation' => SecuritySituation::class,
            'gate_secure' => 'boolean',
            'perimeter_secure' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->uuid ??= (string) Str::uuid();
            $report->reported_at ??= now();
            $report->situation ??= SecuritySituation::Safe;
        });
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(SecurityShift::class, 'security_shift_id');
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }
}
