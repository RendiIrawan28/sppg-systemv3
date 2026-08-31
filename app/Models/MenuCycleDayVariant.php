<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuCycleDayVariant extends Model
{
    public const AUDIENCE_POSYANDU_3B = 'posyandu_3b';

    protected $fillable = [
        'menu_cycle_day_id',
        'audience_type',
        'menu_id',
    ];

    public function day(): BelongsTo
    {
        return $this->belongsTo(MenuCycleDay::class, 'menu_cycle_day_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }
}
