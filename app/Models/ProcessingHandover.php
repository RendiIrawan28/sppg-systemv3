<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProcessingHandover extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_batch_id',
        'handed_over_at',
        'output_quantity',
        'unit_name',
        'received_by_user_id',
        'received_by_name',
        'notes',
        'photo_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'handed_over_at' => 'datetime',
            'output_quantity' => 'decimal:3',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'processing_batch_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? Storage::disk('public')->url($this->photo_path)
            : null;
    }
}
