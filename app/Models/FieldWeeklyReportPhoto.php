<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldWeeklyReportPhoto extends Model
{
    public const TYPES = ['menu_school', 'menu_3b', 'activity'];

    protected $fillable = [
        'sppg_unit_id', 'photo_date', 'photo_type', 'photo_path', 'caption', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['photo_date' => 'date'];
    }

    public function sppgUnit(): BelongsTo
    {
        return $this->belongsTo(SppgUnit::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
