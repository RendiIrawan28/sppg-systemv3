<?php

namespace App\Models;

use App\Enums\PortionSize;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortioningWeightSample extends Model
{
    use HasFactory;

    protected $fillable = [
        'portioning_session_id',
        'portion_size',
        'component_name',
        'sample_number',
        'target_weight_grams',
        'actual_weight_grams',
        'tolerance_grams',
        'deviation_grams',
        'is_within_tolerance',
        'corrective_action',
        'checked_at',
        'checked_by',
        'checked_name_snapshot',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'portion_size' => PortionSize::class,
            'sample_number' => 'integer',
            'target_weight_grams' => 'decimal:3',
            'actual_weight_grams' => 'decimal:3',
            'tolerance_grams' => 'decimal:3',
            'deviation_grams' => 'decimal:3',
            'is_within_tolerance' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $sample): void {
            $target = (float) $sample->target_weight_grams;
            $actual = (float) $sample->actual_weight_grams;
            $tolerance = max(0, (float) $sample->tolerance_grams);

            $sample->deviation_grams = $actual - $target;
            $sample->is_within_tolerance = abs($sample->deviation_grams) <= $tolerance;
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class, 'portioning_session_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
