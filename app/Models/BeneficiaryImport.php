<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BeneficiaryImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'sppg_unit_id',
        'institution_type',
        'institution_id',
        'uploaded_by',
        'original_filename',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'imported_rows',
        'updated_rows',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'imported_rows' => 'integer',
            'updated_rows' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by'
        );
    }

    public function institution(): MorphTo
    {
        return $this->morphTo();
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(
            Beneficiary::class,
            'last_import_id'
        );
    }
}