<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreparationOutput extends Model
{
    public const AVAILABLE = 'available';
    public const PARTIALLY_TAKEN = 'partially_taken';
    public const DEPLETED = 'depleted';
    public const UNFIT = 'unfit';

    protected $fillable = [
        'sppg_unit_id', 'preparation_session_id', 'preparation_session_item_id', 'ingredient_id',
        'output_name', 'source_ingredient_name_snapshot', 'quantity', 'available_quantity',
        'unit_snapshot', 'target_division', 'storage_location', 'stored_at', 'expires_at',
        'state', 'photo_path', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'available_quantity' => 'decimal:4',
            'stored_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PreparationSession::class, 'preparation_session_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(PreparationSessionItem::class, 'preparation_session_item_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(PreparationOutputWithdrawal::class)->latest('taken_at');
    }

    public function isAvailableFor(string $division): bool
    {
        return in_array($this->target_division, [$division, 'both'], true)
            && in_array($this->state, [self::AVAILABLE, self::PARTIALLY_TAKEN], true)
            && (! $this->expires_at || $this->expires_at->isFuture())
            && (float) $this->available_quantity > 0;
    }
}
