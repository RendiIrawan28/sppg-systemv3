<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OpeningStock extends Model
{
    protected $fillable = ['uuid', 'sppg_unit_id', 'warehouse_id', 'opening_number', 'opening_date', 'photo_path', 'notes', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['opening_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->uuid ??= (string) Str::uuid());
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }

    public function items(): HasMany
    {
        return $this->hasMany(OpeningStockItem::class);
    }
}
