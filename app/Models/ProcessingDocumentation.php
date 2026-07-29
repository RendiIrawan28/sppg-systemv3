<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProcessingDocumentation extends Model
{
    use HasFactory;

    protected $fillable = [
        'processing_batch_id',
        'documentation_type',
        'output_quantity',
        'output_unit',
        'caption',
        'photo_path',
        'captured_at',
        'created_by',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'output_quantity' => 'decimal:4',
            'captured_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProcessingBatch::class, 'processing_batch_id');
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
