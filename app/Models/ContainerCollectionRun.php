<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ContainerCollectionRun extends Model
{
    public const ACTIVE = 'active';
    public const RETURNED = 'returned';

    protected $fillable = [
        'sppg_unit_id', 'run_number', 'run_year', 'sequence_number', 'collection_date',
        'state', 'driver_id', 'driver_name_snapshot', 'kernet_name', 'vehicle_name',
        'vehicle_plate', 'started_at', 'returned_at', 'total_collected', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'collection_date' => 'date',
            'run_year' => 'integer',
            'sequence_number' => 'integer',
            'started_at' => 'datetime',
            'returned_at' => 'datetime',
            'total_collected' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            $date = $run->collection_date ? Carbon::parse($run->collection_date) : now();
            $run->run_year ??= (int) $date->format('Y');
            $run->sequence_number ??= ((int) self::query()
                ->where('sppg_unit_id', $run->sppg_unit_id)
                ->where('run_year', $run->run_year)
                ->max('sequence_number')) + 1;
            $run->run_number ??= sprintf('OMP/%d/%d/%04d', $run->sppg_unit_id, $run->run_year, $run->sequence_number);
            $run->state ??= self::ACTIVE;
            $run->started_at ??= now();
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContainerCollectionItem::class)->orderBy('collected_at');
    }

    public function washingSession()
    {
        return $this->hasOne(WashingSession::class);
    }
}
