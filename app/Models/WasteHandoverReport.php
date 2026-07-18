<?php

namespace App\Models;

use App\Enums\OperationalReportStatus;
use App\Enums\WasteDivision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class WasteHandoverReport extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'division_type',
        'report_number',
        'document_year',
        'sequence_number',
        'report_date',
        'first_party_name',
        'first_party_position',
        'first_party_address',
        'second_party_name',
        'second_party_position',
        'second_party_address',
        'notes',
        'petugas_id',
        'petugas_name_snapshot',
        'status',
        'created_by',
        'updated_by',
        'submitted_by',
        'submitted_at',
        'verified_by',
        'verified_at',
        'review_notes',
        'source_system',
        'legacy_id',
        'legacy_sheet_name',
        'legacy_created_at',
        'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'division_type' => WasteDivision::class,
            'status' => OperationalReportStatus::class,
            'report_date' => 'date',
            'document_year' => 'integer',
            'sequence_number' => 'integer',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'legacy_created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->uuid ??= (string) Str::uuid();
            $report->division_type ??= WasteDivision::Preparation;
            $report->status ??= OperationalReportStatus::Draft;
            $report->source_system ??= 'laravel_v2';

            $report->assignDocumentSequence();

            if (blank($report->report_number)) {
                $report->report_number = $report->buildReportNumber();
            }
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WasteHandoverItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(WasteHandoverHistory::class)
            ->latest();
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeForDivision(Builder $query, WasteDivision|string $division): Builder
    {
        $value = $division instanceof WasteDivision ? $division->value : $division;

        return $query->where('division_type', $value);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [
            OperationalReportStatus::Draft,
            OperationalReportStatus::RevisionRequired,
        ], true);
    }

    public function canBeSubmitted(): bool
    {
        return $this->isEditable();
    }

    public function getTotalWeightKgAttribute(): float
    {
        if (array_key_exists('items_sum_weight_kg', $this->attributes)) {
            return (float) ($this->attributes['items_sum_weight_kg'] ?? 0);
        }

        return (float) $this->items()->sum('weight_kg');
    }

    private function assignDocumentSequence(): void
    {
        $date = $this->report_date
            ? Carbon::parse($this->report_date)
            : now();

        $this->document_year ??= (int) $date->format('Y');

        if ($this->sequence_number !== null) {
            return;
        }

        $lastSequence = self::query()
            ->withTrashed()
            ->where('sppg_unit_id', $this->sppg_unit_id)
            ->where('division_type', $this->division_type->value)
            ->where('document_year', $this->document_year)
            ->max('sequence_number');

        $this->sequence_number = ((int) $lastSequence) + 1;
    }

    private function buildReportNumber(): string
    {
        $unitCode = SppgUnit::query()
            ->whereKey($this->sppg_unit_id)
            ->value('code') ?: 'SPPG';

        return sprintf(
            'BA/%s/%s/%d/%04d',
            $this->division_type->documentCode(),
            strtoupper((string) $unitCode),
            $this->document_year,
            $this->sequence_number,
        );
    }

}
