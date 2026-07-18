<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WasteHandoverHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'waste_handover_report_id',
        'actor_id',
        'action',
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

    public function report(): BelongsTo
    {
        return $this->belongsTo(
            WasteHandoverReport::class,
            'waste_handover_report_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
