<?php

namespace App\Models;

use App\Enums\NutritionRecordStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MenuCycle extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'beneficiary_period_id',
        'code',
        'name',
        'start_date',
        'end_date',
        'cycle_length_days',
        'buffer_percent',
        'base_small_portions',
        'base_large_portions',
        'buffered_small_portions',
        'buffered_large_portions',
        'beneficiary_breakdown',
        'beneficiary_snapshot_at',
        'meal_type',
        'status',
        'nutrition_warning_count',
        'revision_number',
        'notes',
        'revision_notes',
        'created_by',
        'submitted_by',
        'approved_by',
        'submitted_at',
        'approved_at',
        'activated_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'cycle_length_days' => 'integer',
            'buffer_percent' => 'decimal:2',
            'base_small_portions' => 'integer',
            'base_large_portions' => 'integer',
            'buffered_small_portions' => 'integer',
            'buffered_large_portions' => 'integer',
            'beneficiary_breakdown' => 'array',
            'beneficiary_snapshot_at' => 'datetime',
            'nutrition_warning_count' => 'integer',
            'revision_number' => 'integer',
            'status' => NutritionRecordStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $cycle): void {
            $cycle->uuid ??= (string) Str::uuid();
            $cycle->status ??= NutritionRecordStatus::Draft;
            $cycle->cycle_length_days ??= (int) config('nutrition_menu.default_cycle_length_days', 5);
            $cycle->buffer_percent ??= (float) config('nutrition_menu.default_buffer_percent', 2);
            $cycle->meal_type = 'lunch';

            if (blank($cycle->code)) {
                $year = $cycle->start_date?->format('Y') ?? now()->format('Y');
                $sequence = (int) self::query()
                    ->where('sppg_unit_id', $cycle->sppg_unit_id)
                    ->whereYear('start_date', $year)
                    ->withTrashed()
                    ->count() + 1;

                $cycle->code = sprintf('SM/%s/%04d', $year, $sequence);
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function beneficiaryPeriod(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriod::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(MenuCycleDay::class)->orderBy('day_number');
    }

    public function revisionRequests(): HasMany
    {
        return $this->hasMany(MenuDayRevisionRequest::class, 'menu_cycle_id')->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return ($this->status ?? NutritionRecordStatus::Draft)->isEditable();
    }

    public function isActive(): bool
    {
        return $this->status === NutritionRecordStatus::Active;
    }

    public function baseTotalPortions(): int
    {
        return (int) $this->base_small_portions + (int) $this->base_large_portions;
    }

    public function bufferedTotalPortions(): int
    {
        return (int) $this->buffered_small_portions + (int) $this->buffered_large_portions;
    }
}
