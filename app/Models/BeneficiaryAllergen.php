<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BeneficiaryAllergen extends Model
{
    use HasFactory;

    protected $table = 'beneficiary_allergen';

    protected $fillable = [
        'beneficiary_id',
        'allergen_id',
        'severity',
        'reaction_notes',
        'verified_by',
        'verified_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'beneficiary_id' => 'integer',
            'allergen_id' => 'integer',
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BeneficiaryAllergen $record): void {
            if (auth()->check()) {
                $record->verified_by ??= auth()->id();
                $record->verified_at ??= now();
            }
        });
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function allergen(): BelongsTo
    {
        return $this->belongsTo(Allergen::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
