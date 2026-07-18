<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CleaningChemicalUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'cleaning_session_id', 'chemical_name', 'quantity', 'unit', 'purpose',
        'dilution_ratio', 'batch_number', 'expiry_date', 'used_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'expiry_date' => 'date',
            'used_at' => 'datetime',
        ];
    }

    public function cleaningSession(): BelongsTo { return $this->belongsTo(CleaningSession::class); }
}
