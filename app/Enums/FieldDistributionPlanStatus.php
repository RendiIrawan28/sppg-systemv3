<?php

namespace App\Enums;

enum FieldDistributionPlanStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case RevisionRequired = 'revision_required';
    case Approved = 'approved';
    case Activated = 'activated';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Status Lama: Diajukan',
            self::RevisionRequired => 'Status Lama: Perlu Revisi',
            self::Approved => 'Status Lama: Disetujui',
            self::Activated => 'Diproses Divisi',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'warning',
            self::RevisionRequired => 'danger',
            self::Approved => 'info',
            self::Activated => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
