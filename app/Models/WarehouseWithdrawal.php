<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WarehouseWithdrawal extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const WAITING = 'waiting_warehouse_verification';

    public const REVISION = 'revision_required';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    protected $fillable = ['uuid', 'sppg_unit_id', 'withdrawal_number', 'withdrawal_date', 'division_code', 'reference_type', 'reference_id', 'reference_number_snapshot', 'purpose_reference', 'shift', 'status', 'notes', 'decision_notes', 'taken_by', 'verified_by', 'submitted_at', 'verified_at', 'rejected_at'];

    protected function casts(): array
    {
        return ['withdrawal_date' => 'date', 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
            $model->withdrawal_number ??= 'PG/'.now()->format('Ymd').'/'.str_pad((string) (self::where('sppg_unit_id', $model->sppg_unit_id)->whereDate('withdrawal_date', $model->withdrawal_date)->count() + 1), 4, '0', STR_PAD_LEFT);
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

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseWithdrawalItem::class);
    }

    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::REVISION], true);
    }
}
