<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class FieldDistributionPlanDestination extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_distribution_plan_id',
        'beneficiary_period_destination_id',
        'daily_beneficiary_confirmation_id',
        'destination_type',
        'destination_id',
        'destination_code_snapshot',
        'destination_name_snapshot',
        'address_snapshot',
        'contact_name_snapshot',
        'contact_phone_snapshot',
        'latitude_snapshot',
        'longitude_snapshot',
        'route_name',
        'sequence_order',
        'registered_beneficiaries',
        'confirmed_beneficiaries',
        'small_portions',
        'large_portions',
        'total_portions',
        'planned_departure_at',
        'planned_arrival_at',
        'planned_departure_time',
        'planned_arrival_time',
        'confirmation_status',
        'confirmed_at',
        'confirmed_by_name',
        'change_reason',
        'special_notes',
    ];

    protected function casts(): array
    {
        return [
            'beneficiary_period_destination_id' => 'integer',
            'daily_beneficiary_confirmation_id' => 'integer',
            'destination_id' => 'integer',
            'latitude_snapshot' => 'decimal:7',
            'longitude_snapshot' => 'decimal:7',
            'sequence_order' => 'integer',
            'registered_beneficiaries' => 'integer',
            'confirmed_beneficiaries' => 'integer',
            'small_portions' => 'integer',
            'large_portions' => 'integer',
            'total_portions' => 'integer',
            'planned_departure_at' => 'datetime',
            'planned_arrival_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $destination): void {
            $destination->total_portions =
                (int) $destination->small_portions +
                (int) $destination->large_portions;

            $destination->syncDateTimesFromPlanDate();
        });

        static::saved(fn (self $destination) => $destination->plan?->recalculateTotals());
        static::deleted(fn (self $destination) => $destination->plan?->recalculateTotals());
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(FieldDistributionPlan::class, 'field_distribution_plan_id');
    }

    public function periodDestination(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryPeriodDestination::class, 'beneficiary_period_destination_id');
    }

    public function dailyConfirmation(): BelongsTo
    {
        return $this->belongsTo(DailyBeneficiaryConfirmation::class, 'daily_beneficiary_confirmation_id');
    }

    public function recipientGroups(): HasMany
    {
        return $this->hasMany(FieldDistributionPlanRecipientGroup::class)
            ->orderBy('id');
    }

    public function recalculatePortionsFromGroups(): void
    {
        $groups = $this->recipientGroups()->get();

        if ($groups->isEmpty()) {
            $this->plan?->recalculateTotals();
            return;
        }

        $registered = (int) $groups->sum('registered_beneficiaries');
        $confirmed = (int) $groups->sum('confirmed_beneficiaries');
        $changed = $groups->contains(
            fn ($group): bool => (int) $group->registered_beneficiaries !== (int) $group->confirmed_beneficiaries
        );
        $reasons = $groups->pluck('notes')->filter()->unique()->implode('; ');

        $this->updateQuietly([
            'registered_beneficiaries' => $registered,
            'confirmed_beneficiaries' => $confirmed,
            'small_portions' => (int) $groups->sum('small_portions'),
            'large_portions' => (int) $groups->sum('large_portions'),
            'total_portions' => (int) $groups->sum('total_portions'),
            'confirmation_status' => $changed ? 'changed' : 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by_name' => auth()->user()?->name ?: $this->confirmed_by_name,
            'change_reason' => $changed
                ? ($reasons ?: $this->change_reason)
                : null,
        ]);

        $this->refresh();
        $this->plan?->recalculateTotals();
    }

    private function syncDateTimesFromPlanDate(): void
    {
        $departureTimeChanged = $this->isDirty('planned_departure_time');
        $arrivalTimeChanged = $this->isDirty('planned_arrival_time');

        $this->planned_departure_time = $this->normalizeNullableTime($this->planned_departure_time);
        $this->planned_arrival_time = $this->normalizeNullableTime($this->planned_arrival_time);

        if ($departureTimeChanged && $this->planned_departure_time === null) {
            $this->planned_departure_at = null;
        }

        if ($arrivalTimeChanged && $this->planned_arrival_time === null) {
            $this->planned_arrival_at = null;
        }

        $date = $this->plan?->distribution_date;

        if (! $date && $this->field_distribution_plan_id) {
            $date = FieldDistributionPlan::query()
                ->whereKey($this->field_distribution_plan_id)
                ->value('distribution_date');
        }

        if (! $date) {
            return;
        }

        if (! $departureTimeChanged && $this->planned_departure_time === null && $this->planned_departure_at) {
            $this->planned_departure_time = Carbon::parse($this->planned_departure_at)->format('H:i:s');
        }

        if (! $arrivalTimeChanged && $this->planned_arrival_time === null && $this->planned_arrival_at) {
            $this->planned_arrival_time = Carbon::parse($this->planned_arrival_at)->format('H:i:s');
        }

        if ($this->planned_departure_time !== null) {
            $this->planned_departure_at = $this->combineDateAndTime($date, $this->planned_departure_time);
        }

        if ($this->planned_arrival_time !== null) {
            $this->planned_arrival_at = $this->combineDateAndTime($date, $this->planned_arrival_time);
        }
    }

    private function normalizeNullableTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('H:i:s');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return Carbon::parse($value)->format('H:i:s');
    }

    private function combineDateAndTime(mixed $date, mixed $time): Carbon
    {
        $timeString = $time instanceof \DateTimeInterface
            ? Carbon::instance($time)->format('H:i:s')
            : Carbon::parse((string) $time)->format('H:i:s');

        return Carbon::parse($date)->startOfDay()->setTimeFromTimeString($timeString);
    }
}
