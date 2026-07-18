<?php

namespace App\Models;

use App\Enums\NutritionRecordStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class NutritionDailyReport extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sppg_unit_id',
        'report_number',
        'report_date',
        'menu_id',
        'planned_beneficiaries',
        'actual_beneficiaries',
        'planned_portions',
        'served_portions',
        'returned_portions',
        'average_acceptance_percent',
        'average_waste_percent',
        'special_menu_count',
        'allergen_conflicts_count',
        'open_findings_count',
        'status',
        'summary',
        'evaluation_notes',
        'recommendations',
        'revision_notes',
        'generated_by',
        'submitted_by',
        'approved_by',
        'generated_at',
        'submitted_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'planned_beneficiaries' => 'integer',
            'actual_beneficiaries' => 'integer',
            'planned_portions' => 'integer',
            'served_portions' => 'integer',
            'returned_portions' => 'integer',
            'average_acceptance_percent' => 'decimal:2',
            'average_waste_percent' => 'decimal:2',
            'special_menu_count' => 'integer',
            'allergen_conflicts_count' => 'integer',
            'open_findings_count' => 'integer',
            'status' => NutritionRecordStatus::class,
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->uuid ??= (string) Str::uuid();
            $report->status ??= NutritionRecordStatus::Draft;

            if (blank($report->report_number)) {
                $date = $report->report_date ?? now();
                $sequence = (int) self::query()
                    ->where('sppg_unit_id', $report->sppg_unit_id)
                    ->whereYear('report_date', $date->format('Y'))
                    ->withTrashed()
                    ->count() + 1;

                $report->report_number = sprintf(
                    'LGZ/%s/%04d',
                    $date->format('Y'),
                    $sequence
                );
            }
        });
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(NutritionDailyReportComponent::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return ($this->status ?? NutritionRecordStatus::Draft)->isEditable();
    }
}
