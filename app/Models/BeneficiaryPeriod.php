<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BeneficiaryPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id',
        'source_period_id',
        'code',
        'document_number',
        'revision_number',
        'name',
        'start_date',
        'end_date',
        'status',
        'destination_count',
        'total_members',
        'active_members',
        'notes',
        'created_by',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'locked_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'revision_number' => 'integer',
            'destination_count' => 'integer',
            'total_members' => 'integer',
            'active_members' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function sourcePeriod(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_period_id');
    }

    public function derivedPeriods(): HasMany
    {
        return $this->hasMany(self::class, 'source_period_id');
    }

    /** @deprecated Gunakan destinations() dan members(). */
    public function items(): HasMany
    {
        return $this->hasMany(BeneficiaryPeriodItem::class);
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(BeneficiaryPeriodDestination::class)
            ->orderBy('sort_order')
            ->orderBy('destination_name_snapshot');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BeneficiaryPeriodMember::class);
    }

    public function activeMemberRecords(): HasMany
    {
        return $this->members()->where('is_active', true);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(BeneficiaryPeriodHistory::class)->latest();
    }

    /** @deprecated Konfirmasi aktual kini dilakukan langsung pada Rencana H-3. */
    public function confirmations(): HasMany
    {
        return $this->hasMany(DailyBeneficiaryConfirmation::class);
    }

    public function distributionPlans(): HasMany
    {
        return $this->hasMany(FieldDistributionPlan::class);
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

    public function scopeForUnit(Builder $query, int $unitId): Builder
    {
        return $query->where('sppg_unit_id', $unitId);
    }

    public function scopeContainingDate(Builder $query, mixed $date): Builder
    {
        return $query
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'revision_required'], true)
            && $this->locked_at === null;
    }

    public function isLocked(): bool
    {
        return ! $this->isEditable();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'submitted' => 'Menunggu Persetujuan',
            'revision_required' => 'Perlu Revisi',
            'approved' => 'Disetujui / Dikunci',
            'active' => 'Aktif',
            'closed' => 'Ditutup',
            default => str($this->status)->replace('_', ' ')->title()->toString(),
        };
    }
}
