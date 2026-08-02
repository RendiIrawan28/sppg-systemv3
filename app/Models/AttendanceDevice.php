<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AttendanceDevice extends Model
{
    protected $fillable = ['uuid', 'sppg_unit_id', 'name', 'code', 'secret_hash', 'location', 'firmware_version', 'last_ip', 'last_seen_at', 'is_active'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class, 'sppg_unit_id');
    }

    public function taps(): HasMany
    {
        return $this->hasMany(AttendanceTap::class);
    }
}
