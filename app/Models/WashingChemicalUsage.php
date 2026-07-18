<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WashingChemicalUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'washing_session_id', 'chemical_name', 'quantity', 'unit', 'purpose',
        'batch_number', 'expiry_date', 'used_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'expiry_date' => 'date',
            'used_at' => 'datetime',
        ];
    }

    public function washingSession(): BelongsTo { return $this->belongsTo(WashingSession::class); }
}
