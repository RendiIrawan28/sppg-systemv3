<?php

namespace App\Models;

use App\Enums\OperationalReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PreparationSession extends Model
{
    protected $fillable = ['sppg_unit_id', 'warehouse_withdrawal_id', 'session_number', 'preparation_date', 'purpose_reference', 'state', 'status', 'petugas_id', 'started_at', 'completed_at', 'submitted_by', 'submitted_at', 'division_approved_by', 'division_approved_at', 'verified_by', 'verified_at', 'notes', 'review_notes', 'waste_handover_report_id'];

    protected function casts(): array
    {
        return ['preparation_date' => 'date', 'status' => OperationalReportStatus::class, 'started_at' => 'datetime', 'completed_at' => 'datetime', 'submitted_at' => 'datetime', 'division_approved_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(WarehouseWithdrawal::class, 'warehouse_withdrawal_id');
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'petugas_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PreparationSessionItem::class);
    }

    public function resultDocumentation(): HasOne
    {
        return $this->hasOne(PreparationDocumentation::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(PreparationHistory::class)->latest();
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PreparationReturn::class);
    }

    public function wasteHandoverReport(): BelongsTo
    {
        return $this->belongsTo(WasteHandoverReport::class, 'waste_handover_report_id');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(PreparationOutput::class, 'preparation_session_id');
    }

}
