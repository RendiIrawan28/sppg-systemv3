<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreparationOutputWithdrawal extends Model
{
    public const WAITING = 'waiting_verification';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'preparation_output_id', 'destination_division', 'processing_batch_id',
        'portioning_session_id', 'requested_quantity', 'verified_quantity', 'unit_snapshot',
        'status', 'taken_by', 'taken_at', 'verified_by', 'verified_at', 'notes', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:4',
            'verified_quantity' => 'decimal:4',
            'taken_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(PreparationOutput::class, 'preparation_output_id');
    }

    public function processingBatch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class);
    }

    public function portioningSession(): BelongsTo
    {
        return $this->belongsTo(PortioningSession::class);
    }

    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function getOutputNameAttribute(): ?string
    {
        return $this->output?->output_name;
    }

    public function getUsedQuantityAttribute(): float
    {
        return match ($this->status) {
            self::VERIFIED => (float) $this->verified_quantity,
            self::REJECTED => 0.0,
            default => (float) $this->requested_quantity,
        };
    }

    public function getVerificationStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::VERIFIED => 'Sesuai',
            self::REJECTED => 'Tidak sesuai',
            default => 'Menunggu pengecekan',
        };
    }
}
