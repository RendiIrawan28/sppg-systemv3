<?php

namespace App\Models;

use App\Enums\MenuDayRevisionStatus;
use App\Services\MenuServiceCalendarService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MenuCycleDay extends Model
{
    protected $fillable = [
        'menu_cycle_id',
        'day_number',
        'service_date',
        'production_date',
        'delivery_date',
        'is_rapel',
        'label_code',
        'menu_id',
        'source_menu_id',
        'snapshot_version',
        'snapshot_created_at',
        'field_distribution_plan_id',
        'revision_status',
        'revision_notes',
        'revision_submitted_at',
        'revision_approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'day_number' => 'integer',
            'service_date' => 'date',
            'production_date' => 'date',
            'delivery_date' => 'date',
            'is_rapel' => 'boolean',
            'snapshot_version' => 'integer',
            'snapshot_created_at' => 'datetime',
            'revision_submitted_at' => 'datetime',
            'revision_approved_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(MenuCycle::class, 'menu_cycle_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function sourceMenu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'source_menu_id');
    }

    public function fieldDistributionPlan(): BelongsTo
    {
        return $this->belongsTo(FieldDistributionPlan::class);
    }

    public function revisionRequests(): HasMany
    {
        return $this->hasMany(MenuDayRevisionRequest::class, 'menu_cycle_day_id')
            ->latest('id');
    }

    public function latestRevisionRequest(): HasOne
    {
        return $this->hasOne(MenuDayRevisionRequest::class, 'menu_cycle_day_id')
            ->latestOfMany();
    }

    public function hasOpenRevisionRequest(): bool
    {
        return $this->revisionRequests()
            ->whereIn('status', array_map(
                static fn (MenuDayRevisionStatus $status): string => $status->value,
                array_filter(
                    MenuDayRevisionStatus::cases(),
                    static fn (MenuDayRevisionStatus $status): bool => $status->isOpen(),
                ),
            ))
            ->exists();
    }

    public function holidayInfo(): ?object
    {
        $this->loadMissing('cycle');

        if (! $this->cycle || ! $this->service_date) {
            return null;
        }

        return app(MenuServiceCalendarService::class)->holidayFor(
            (int) $this->cycle->sppg_unit_id,
            $this->service_date,
        );
    }

    public function isHoliday(): bool
    {
        return $this->holidayInfo() !== null;
    }

    public function holidayName(): ?string
    {
        return $this->holidayInfo()?->name;
    }
}
