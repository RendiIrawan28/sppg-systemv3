<?php

namespace App\Models;

use App\Enums\MaterialCondition;
use App\Enums\OperationalReportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PreparationMaterialInspection extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'report_date',
        'ingredient_id',
        'material_name',
        'quantity',
        'measurement_unit_id',
        'unit_name',
        'condition',
        'remarks',
        'petugas_id',
        'petugas_name_snapshot',
        'photo_path',
        'legacy_photo_url',
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
            'report_date' => 'date',
            'quantity' => 'decimal:3',
            'condition' => MaterialCondition::class,
            'status' => OperationalReportStatus::class,
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'legacy_created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $inspection): void {
            $inspection->uuid ??= (string) Str::uuid();
        });
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
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

    public function histories(): HasMany
    {
        return $this->hasMany(PreparationMaterialInspectionHistory::class)
            ->latest();
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('report_date', $date);
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

    public function getPhotoUrlAttribute(): ?string
    {
        if ($this->photo_path) {
            return Storage::disk('public')->url($this->photo_path);
        }

        return $this->legacy_photo_url ?: null;
    }
}
