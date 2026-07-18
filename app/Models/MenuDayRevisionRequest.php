<?php

namespace App\Models;

use App\Enums\MenuDayRevisionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuDayRevisionRequest extends Model
{
    protected $fillable = [
        'sppg_unit_id',
        'menu_cycle_id',
        'menu_cycle_day_id',
        'original_menu_id',
        'revision_menu_id',
        'status',
        'reason',
        'impact_notes',
        'decision_notes',
        'snapshot',
        'requested_by',
        'requested_at',
        'decided_by',
        'decided_at',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MenuDayRevisionStatus::class,
            'snapshot' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(MenuCycle::class, 'menu_cycle_id');
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(MenuCycleDay::class, 'menu_cycle_day_id');
    }

    public function originalMenu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'original_menu_id');
    }

    public function revisionMenu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'revision_menu_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
