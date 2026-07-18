<?php

namespace App\Models;

use App\Enums\MenuAudience;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuAllergenSubstitution extends Model
{
    protected $fillable = [
        'sppg_unit_id',
        'menu_id',
        'allergen_id',
        'original_menu_item_id',
        'replacement_name',
        'menu_audience',
        'affected_portions_override',
        'affected_portion_profile_override',
        'notes',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'menu_audience' => MenuAudience::class,
            'affected_portions_override' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->created_by ??= auth()->id();
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    public function originalMenuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'original_menu_item_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(MenuAllergenSubstitutionIngredient::class)
            ->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
